<?php


function loadViewProduct(Account $account, $isLoggedIn): void
{
    $productArguments = explode(".", get_form_get("id"));
    $argumentSize = sizeof($productArguments);
    $productID = $productArguments[$argumentSize - 1];

    if (is_numeric($productID) && $productID > 0) {
        global $website_url;
        $productFound = $account->getProduct()->find($productID);

        if ($productFound->isPositiveOutcome()) {
            $productFound = $productFound->getObject()[0];
            $name = $productFound->name;
            $nameURL = prepare_redirect_url($name);

            if ($argumentSize == 1 || $productArguments[0] !== $nameURL) {
                redirect_to_url($website_url . "/viewProduct/?id=$nameURL.$productID", array("id"));
                return;
            }
            $description = $productFound->description;
            $image = $productFound->image;
            $legal = $productFound->legal_information;
            $isFree = $productFound->is_free;
            $developmentDays = get_date_days_difference($productFound->creation_date);
            $hasPurchased = $isFree
                || $isLoggedIn && $account->getPurchases()->owns($productID)->isPositiveOutcome();

            // Separator

            if ($isFree) {
                $activeCustomers = "";
                $price = "";
            } else {
                if ($productFound->registered_buyers > 0) {
                    $word = $productFound->registered_buyers === 1 ? "Customer" : "Customers";
                    $activeCustomers = "<li style='width: auto;'>$productFound->registered_buyers $word</li>";
                } else {
                    $activeCustomers = "";
                }
                $hasTiers = sizeof($productFound->tiers->paid) > 1;
                $tier = $productFound->tiers->paid[0];
                $price = "<li style='width: auto;'>" . ($hasTiers ? "Starting from " : "") . $tier->price . " " . $tier->currency . "</li>";
            }

            echo "<div class='area'>";

            if ($image !== null) {
                $alt = strtolower($name);
                echo "<div class='area_logo'><img src='$image' alt='$alt'></div>";
            }
            $release = $productFound->latest_version !== null ? "<li style='width: auto;'>Release {$productFound->latest_version}</li>" : "";
            echo "<div class='area_title'>$name</div>
                    <div class='area_text'>$description</div>";

            echo "<div class='area_list' id='text'>
                    <ul>
                        <li style='width: auto;'>$developmentDays Days Of Development</li>
                        $release
                        $price
                        $activeCustomers
                    </ul>
                 </div><private_verification_key>
                </div>";

            // Separator
            $css = "";
            $overviewContents = "";
            $productDivisions = $isFree ? array_merge($productFound->divisions->post_purchase, $productFound->divisions->pre_purchase)
                : ($hasPurchased
                    ? $productFound->divisions->post_purchase
                    : $productFound->divisions->pre_purchase);

            if (!empty($productDivisions)) {
                foreach ($productDivisions as $family => $divisions) {
                    if (!empty($family)) {
                        $overviewContents .= "<div class='area_title'>$family</div>";
                    }
                    $overviewContents .= "<div class='area_list'><ul>";

                    foreach ($divisions as $division) {
                        $contents = $division->description;

                        if ($division->no_html != null) {
                            $contents = htmlspecialchars($contents);
                        }
                        $contents = str_replace("\n", "<br>", $contents);
                        $overviewContents .= "<li>
                                <div class='area_list_title'>{$division->name}</div>
                                <div class='area_list_contents'>$contents</div>
                                </li>";
                    }
                    $overviewContents .= "</ul></div><br><br>";
                }
            }

            // Separator
            $offer = $productFound->show_offer;

            if ($offer === null) {
                $productCompatibilities = $productFound->compatibilities;

                if (!empty($productCompatibilities)) {
                    $validProducts = $account->getProduct()->find();
                    $validProducts = $validProducts->getObject();

                    if (sizeof($validProducts) > 1) { // One because we already are quering one
                        $overviewContents .= "<div class='area_title'>Works With</div><div class='product_list'><ul>";

                        foreach ($productCompatibilities as $compatibility) {
                            $productObject = find_object_from_key_match($validProducts, "id", $compatibility);

                            if (is_object($productObject)) {
                                $compatibleProductImage = $productObject->image;

                                if ($compatibleProductImage != null) {
                                    $compatibleProductName = $productObject->name;
                                    $span = "<span>" . account_product_prompt($account, $isLoggedIn, $productObject) . "</span>";
                                    $productURL = $productObject->url;
                                    $overviewContents .= "<li><a href='$productURL'>
                                                        <div class='product_list_contents' style='background-image: url(\"$compatibleProductImage\");'>
                                                            <div class='product_list_title'>$compatibleProductName</div>
                                                            $span
                                                        </div>
                                                    </a>
                                                </li>";
                                }
                            }
                        }
                    }
                    $overviewContents .= "</ul></div>";
                }
            } else {
                $offer = $account->getOffer()->find($offer == -1 ? null : $offer);

                if ($offer->isPositiveOutcome()) {
                    $offer = $offer->getObject();

                    foreach ($offer->divisions as $divisions) {
                        foreach ($divisions as $division) {
                            $overviewContents .= $division->description;
                        }
                    }
                }
            }

            if (isset($overviewContents[0])) {
                echo "<div class='area' id='darker'>$overviewContents</div>";
            } else {
                $css = "darker";
            }

            // Separator

            $showLegal = true;
            $buttonInformation = "";
            $productButton = $hasPurchased ? null : $productFound->buttons->pre_purchase;

            if (!empty($productButton)) {
                $firstButton = false;
                $buttonInformation .= "<div class='area_text'>Your purchase will appear within a minute of completion.</div><private_verification_key>";

                foreach ($productButton as $button) {
                    if ($isLoggedIn
                        || $button->requires_account === null) {
                        if (!$firstButton) {
                            $firstButton = true;
                            $buttonInformation .= "<div class='area_form' id='marginless'>";
                        }
                        $color = $button->color;
                        $buttonName = $button->name;
                        $url = $button->url;
                        $buttonInformation .= "<private_verification_key><a href='$url' class='button' id='$color'>$buttonName</a>";
                    }
                }
                if ($firstButton) {
                    $buttonInformation .= "</div>";
                }
            } else if ($hasPurchased) {
                if (!empty($productFound->downloads)) {
                    if ($isLoggedIn) {
                        $productButton = $productFound->buttons->post_purchase;
                        $downloadNote = $productFound->download_note !== null ? "<div class='area_text'><b>IMPORTANT NOTE</b><br>" . $productFound->download_note . "</div>" : "";
                        $buttonInformation .= "$downloadNote<div class='area_form'><a href='$website_url/downloadFile/?id=$productID' class='button' id='blue'>Download $name</a>";

                        if (!empty($productButton)) {
                            foreach ($productButton as $button) {
                                $color = $button->color;
                                $buttonName = $button->name;
                                $url = $button->url;
                                $buttonInformation .= "<private_verification_key><a href='$url' class='button' id='$color'>$buttonName</a>";
                            }
                        }
                        $buttonInformation .= "</div>";
                    } else {
                        $buttonInformation .= "<div class='area_form' id='marginless'>
                                        <a href='$website_url/profile' class='button' id='blue'>Log In To Download</a>
                                    </div>";
                    }
                } else {
                    $productButton = $productFound->buttons->post_purchase;

                    if (!empty($productButton)) {
                        $firstButton = false;

                        foreach ($productButton as $button) {
                            if ($isLoggedIn
                                || $button->requires_account === null) {
                                if (!$firstButton) {
                                    $firstButton = true;
                                    $buttonInformation .= "<div class='area_form' id='marginless'>";
                                }
                                $color = $button->color;
                                $buttonName = $button->name;
                                $url = $button->url;
                                $buttonInformation .= "<private_verification_key><a href='$url' class='button' id='$color'>$buttonName</a>";
                            }
                        }
                        if ($firstButton) {
                            $buttonInformation .= "</div>";
                        }
                    } else {
                        $showLegal = false;
                    }
                }
            } else if (!$isFree) {
                if ($isLoggedIn) {
                    $buttonInformation .= "<div class='area_form' id='marginless'>
                                        <a href='#' class='button' id='red'>Not For Sale</a>
                                    </div>";
                } else {
                    $showLegal = false;
                    $buttonInformation .= "<div class='area_form' id='marginless'>
                                        <a href='$website_url/profile' class='button' id='blue'>Log In To Learn More</a>
                                    </div>";
                }
            }
            if (isset($buttonInformation[0])) {
                echo "<div class='area' id='$css'>";
                echo $buttonInformation;

                if ($showLegal && $legal !== null) {
                    echo "<private_verification_key><div class='area_text'>By purchasing/downloading, you acknowledge and accept this product/service's <a href='$legal'>legal information</a>.</div>";
                }
                echo "</div>";
            }
        } else {
            $name = "Error";

            if ($argumentSize == 1 || $productArguments[0] !== $name) {
                redirect_to_url($website_url . "/viewProduct/?id=$name.$productID", array("id"));
                return;
            }
            load_account_page_message(
                "Website Error",
                "This product does not exist or is not currently available."
            );
        }
    } else {
        load_account_page_message(
            "Website Error",
            "This product does not exist or is not currently available."
        );
    }
}
