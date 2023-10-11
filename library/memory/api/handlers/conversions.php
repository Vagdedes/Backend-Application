<?php

function memory_ignore_key_serialization(): void
{
    global $memory_serialize_key;
    $memory_serialize_key = false;
}

function manipulate_memory_key($key): bool|string
{
    global $memory_serialize_key;

    if ($memory_serialize_key) {
        return $key === null ? false : serialize(is_object($key) ? get_object_vars($key) : $key);
    } else {
        $memory_serialize_key = true;
        return $key;
    }
}

function manipulate_memory_date($cooldown, $maxTime = 86400)
{
    if ($cooldown === null) {
        return false;
    }
    if (is_array($cooldown)) {
        $cooldown = strtotime("+" . implode(" ", $cooldown));

        if ($cooldown === false) {
            return null;
        }
    } else if (is_numeric($cooldown)) {
        $cooldown = time() + min($cooldown, $maxTime);
    } else {
        $cooldown = strtotime("+" . $cooldown);

        if ($cooldown === false) {
            return null;
        }
    }
    return min($cooldown, time() + $maxTime);
}

function map_to_string($array): string
{
    $string = "";

    foreach ($array as $key => $value) {
        $dataToStore = @gzdeflate($value, 9);

        if ($dataToStore !== false) {
            $string .= $key . "\r" . $dataToStore . "\r";
        } else {
            throw new Exception("Failed to deflate string: " . $value);
        }
    }
    return $string;
}

function string_to_map($string): array
{
    $explode = preg_split("/(\r)/", $string);
    $array = array();
    $previousValue = null;

    foreach ($explode as $position => $value) {
        if (($position + 1) % 2 == 0) {
            $storedData = @gzinflate($value);

            if ($storedData !== false) {
                $array[$previousValue] = $storedData;
            } else {
                throw new Exception("Failed to inflate string: " . $value);
            }
        } else {
            $previousValue = $value;
        }
    }
    return $array;
}
