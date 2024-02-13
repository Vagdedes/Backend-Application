<?php

function get_reserved_memory_names(): array
{
    global $memory_reserved_keys;
    $array = array();

    foreach (array_keys($memory_reserved_keys) as $key) {
        if (is_string($key)) {
            $array[] = $key;
        }
    }
    return $array;
}

/**
 * @throws Exception
 */
function reserve_memory_key(string $name, int $key): void
{
    global $memory_reserved_keys;

    if (array_key_exists($name, $memory_reserved_keys)) {
        $existingKey = $memory_reserved_keys[$name];

        if ($existingKey !== $key) {
            throw new Exception("Tried to change memory key of name '" . $name . "' from '$existingKey' to '$key'");
        }
        return;
    }
    $memory_reserved_keys[$name] = $key;
}

/**
 * @throws Exception
 */
function get_reserved_memory_key(string $name): int
{
    global $memory_reserved_keys;

    if (!array_key_exists($name, $memory_reserved_keys)) {
        throw new Exception("Tried to use name that is not reserved: " . $name);
    } else {
        return $memory_reserved_keys[$name];
    }
}

function is_reserved_memory_key(int $key): bool
{
    global $memory_reserved_keys;
    return in_array($key, $memory_reserved_keys);
}

// Separator

function get_memory_segment_limit(): int
{
    global $memory_reserved_keys;
    $niddle = "max number of segments = ";
    $integer = substr(shell_exec("ipcs -l | grep '$niddle'"), strlen($niddle), -1) - sizeof($memory_reserved_keys);
    return $integer;
}


function get_memory_segment_ids(): array
{
    global $memory_reserved_keys;
    $memoryBlock = new IndividualMemoryBlock($memory_reserved_keys[0]);
    $array = $memoryBlock->get();

    if (is_array($array)) {
        return $array;
    } else {
        global $memory_permissions_string;
        //$stringToFix = "echo 32768 >/proc/sys/kernel/shmmni";
        $oldCommand = "ipcs -m | grep 'www-data.*$memory_permissions_string'";
        $timeToCalculate = microtime(true);
        $array = explode(chr(32), shell_exec("ipcs -m"));

        if (!empty($array)) {
            foreach ($array as $key => $value) {
                if (empty($value) || is_numeric($value) || $value[0] === "w") {
                    unset($array[$key]);
                } else {
                    $array[$key] = @hexdec($value);
                }
            }
        }
        $memoryBlock->set($array);

        if (microtime(true) - $timeToCalculate >= 0.1) {
            clear_memory_expired_segments();
        }
        return $array;
    }
}

function clear_memory_segment_ids_cache(): void
{
    global $memory_reserved_keys;
    $segmentIDCacheBlock = new IndividualMemoryBlock($memory_reserved_keys[0]);
    $segmentIDCacheBlock->clear(false, true);
}

function clear_memory_expired_segments(int $makeSpace = 0): void
{
    $segments = get_memory_segment_ids();

    if (!empty($segments)) {
        $sortedByCreation = array();
        $countSpace = $makeSpace > 0;

        foreach ($segments as $segment) {
            $memoryBlock = new IndividualMemoryBlock($segment);

            if ($memoryBlock->clear(true)) {
                if ($countSpace) {
                    $makeSpace--;

                    if ($makeSpace == 0) {
                        return;
                    }
                }
            } else {
                $time = $memoryBlock->get("creation");

                if ($time !== null) {
                    $sortedByCreation[$time] = $memoryBlock;
                }
            }
        }

        if ($countSpace && !empty($sortedByCreation)) {
            ksort($sortedByCreation); // Sort in ascending order, so we start from the least recent

            foreach ($sortedByCreation as $memoryBlock) {
                if ($memoryBlock->clear()) {
                    return;
                }
            }
        }
    }
}

// Separator

class IndividualMemoryBlock
{
    private mixed $originalKey;
    private int $key;

    /**
     * @throws Exception
     */
    public function __construct(mixed $key)
    {
        if (is_integer($key)) { // Used for reserved or existing keys
            $keyToInteger = $key;
            $this->originalKey = $key;
        } else {
            $keyToInteger = string_to_integer($key);

            if (is_reserved_memory_key($keyToInteger)) {
                $this->throwException("Tried using reserved key '" . $keyToInteger . "' as individual-memory-block.");
            }
            $this->originalKey = $key;
        }
        $this->key = $keyToInteger;
    }

    public function getKey(): int
    {
        return $this->key;
    }

    public function getOriginalKey(): mixed
    {
        return $this->originalKey;
    }

    /**
     * @throws Exception
     */
    public function getSize(): int
    {
        global $memory_starting_bytes;
        $exceptionID = 4;
        $block = $this->internalGetBlock($exceptionID, $memory_starting_bytes, false);
        return $this->internalGetBlockSize($exceptionID, $block);
    }

    /**
     * @throws Exception
     */
    private function getRaw()
    {
        global $memory_starting_bytes;
        $exceptionID = 3;
        $block = $this->internalGetBlock($exceptionID, $memory_starting_bytes, false);

        if (!$block) {
            return null;
        }
        $readMapBytes = $this->readBlock(
            $block,
            max($this->internalGetBlockSize($exceptionID, $block), $memory_starting_bytes)
        );

        if (!$readMapBytes) {
            return false;
        }
        global $memory_filler_character;
        $rawData = trim($readMapBytes, $memory_filler_character);

        if (isset($rawData[20])) { // Minimum length (21) of serialized and deflated object.
            $value = @gzinflate($rawData);

            if ($value !== false) {
                $object = @unserialize($value);

                if (isset($object->expiration)) {
                    if ($object->expiration === false || $object->expiration >= time()) {
                        return $object;
                    }
                } else {
                    $this->throwException("Unable to use individual-memory-block object of key '" . $this->originalKey . "': " . $value);
                }
            } else {
                //$this->throwException("Failed to inflate string '" . $rawData . "' of individual-memory-block: " . $originalKey);
            }
        }
        return null;
    }

    /**
     * @throws Exception
     */
    public function get(string $objectKey = "value")
    {
        return $this->getRaw()?->{$objectKey};
    }

    /**
     * @throws Exception
     */
    public function exists(): bool
    {
        return $this->getRaw() !== null;
    }

    /**
     * @throws Exception
     */
    public function clear(bool $ifExpired = false, bool $ignoreReserves = false): bool
    {
        if (($ignoreReserves || !is_reserved_memory_key($this->key))
            && (!$ifExpired || $this->getRaw() === null)) {
            if (!$this->internalClose(null, true)) {
                $this->throwException("Unable to manually close current individual-memory-block: " . $this->originalKey, false);
            }
            return true;
        } else {
            return false;
        }
    }

    // Separator

    /**
     * @throws Exception
     */
    public function set(mixed $value, int|string|null|bool $expiration = false, bool $ifEmpty = false): bool
    {
        if ($ifEmpty && $this->getRaw() !== null) {
            return false;
        }
        global $memory_starting_bytes;

        $object = new stdClass();
        $object->key = $this->originalKey;
        $object->value = $value;
        $object->creation = time();
        $object->expiration = is_numeric($expiration) ? $expiration : false;

        $exceptionID = 1;
        $objectToTextRaw = serialize($object);
        $objectToText = @gzdeflate($objectToTextRaw, 9);

        if ($objectToText === false) {
            $this->throwException("Failed to deflate string '" . $objectToTextRaw . "' of individual-memory-block: " . $this->originalKey);
        }
        $objectToTextLength = strlen($objectToText);
        $block = $this->internalGetBlock($exceptionID, $memory_starting_bytes, false, true);
        $bytesSize = max($this->internalGetBlockSize($exceptionID, $block), $memory_starting_bytes); // check default

        if ($objectToTextLength > $bytesSize) {
            if (!$this->internalClose($block, true)) {
                $this->throwException("Unable to close old individual-memory-block: " . $this->originalKey);
            }
            $oldBytesSize = $bytesSize;
            $bytesSize = max($bytesSize + $memory_starting_bytes, $objectToTextLength);
            $block = $this->internalGetBlock(2, $bytesSize, true, true); // open bigger

            if (!$block) {
                return false;
            } else if (is_array($block)) { // Revert to old bytes if php did not close the previous block
                $bytesSize = $oldBytesSize;
                $block = $block[0];
            }
            $readMapBytes = $this->readBlock($block, $bytesSize);

            if (!$readMapBytes) {
                return false;
            }
        } else if (!$block) {
            $block = $this->internalGetBlock($exceptionID, $bytesSize, true, true); // open default

            if (!$block) {
                clear_memory_expired_segments(1);
                return false;
            } else {
                global $memory_reserved_keys;

                if ($this->key !== $memory_reserved_keys[0]) {
                    clear_memory_segment_ids_cache();
                }
            }
        }
        $remainingBytes = $bytesSize - $objectToTextLength;

        if ($remainingBytes > 0) {
            global $memory_filler_character;
            $objectToText .= str_repeat($memory_filler_character, $remainingBytes);
        }
        $bytesWritten = shmop_write($block, $objectToText, 0);

        if ($bytesWritten !== $bytesSize) {
            $this->throwException("Unable to write to individual-memory-block: " . $this->originalKey);
        }
        return true;
    }

    /**
     * @throws Exception
     */
    private function internalClose(mixed $block = null, bool $ignoreCreation = false): bool
    {
        if ($block === null) {
            global $memory_starting_bytes;
            $block = $this->internalGetBlock(5, $memory_starting_bytes, false);
        }
        if ($block) {
            if (shmop_delete($block)) {
                global $memory_reserved_keys;

                if ($this->key !== $memory_reserved_keys[0]) {
                    clear_memory_segment_ids_cache();
                }
                return true;
            } else {
                return false;
            }
        } else {
            return $ignoreCreation;
        }
        //shmop_close($block);
    }

    /**
     * @throws Exception
     */
    private function internalGetBlock(int  $exceptionID, int $bytes = -1,
                                      bool $create = true, bool $write = false): mixed
    {
        global $memory_permissions, $memory_starting_bytes;
        $bytes = max($memory_starting_bytes, $bytes);
        $block = @shmop_open($this->key, $write ? "w" : "a", $memory_permissions, $bytes);

        if (!$block && $create) {
            $block = @shmop_open($this->key, "c", $memory_permissions, $bytes);

            if (!$block) {
                $errors = error_get_last();
                $hasErrorKey = array_key_exists("message", $errors);
                $throwException = true;

                if ($hasErrorKey) {
                    global $memory_segment_ignore_errors;

                    if (!empty($memory_segment_ignore_errors)) {
                        $errorMessage = $errors["message"];

                        foreach ($memory_segment_ignore_errors as $ignoreError) {
                            if (str_contains($errorMessage, $ignoreError)) {
                                $throwException = false;
                                break;
                            }
                        }
                    }
                }

                if ($throwException) {
                    $this->throwException(
                        "Unable to open/read individual-memory-block (" . $exceptionID . "): " . $this->originalKey,
                        true,
                        $errors
                    );
                }
            }
        }
        return $block;
    }

    /**
     * @throws Exception
     */
    private function internalGetBlockSize(int $exceptionID, mixed $block): int
    {
        if (!$block) {
            return -1;
        }
        $size = shmop_size($block);

        if (!$size) {
            $this->throwException("Failed to read size of individual-memory-block (" . $exceptionID . "): " . $this->originalKey);
        }
        return $size;
    }

    /**
     * @throws Exception
     */
    private function throwException(string $details, bool $close = true, ?array $errors = null)
    {
        if ($close) {
            $this->internalClose();
        }
        if ($errors === null) {
            $errors = error_get_last();
        }
        throw new Exception($details . (!empty($errors) ? " [" . $errors["message"] . "]" : ""));
    }

    private function readBlock(mixed $block, int $bytesSize): bool|string
    {
        try {
            global $max_32bit_Integer;

            if ($bytesSize >= 0 && $bytesSize <= $max_32bit_Integer) {
                return @shmop_read($block, 0, $bytesSize);
            } else {
                return false;
            }
        } catch (Error|Exception $ignored) {
            return false;
        }
    }
}