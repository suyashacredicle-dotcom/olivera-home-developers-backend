
<?php

// --------------------------------------------------
// SEND WEBSITE VISITORS TO THE CUSTOMER ENQUIRY FORM
// --------------------------------------------------

$allowedTrackingParameters = [
    "utm_source",
    "source",
    "utm_medium",
    "utm_campaign",
    "utm_content",
    "utm_term",
    "fbclid",
    "gclid",
    "gbraid",
    "wbraid",
    "ttclid"
];

$trackingParameters = [];

foreach ($allowedTrackingParameters as $parameter) {

    if (
        isset($_GET[$parameter]) &&
        is_string($_GET[$parameter])
    ) {

        $value = trim($_GET[$parameter]);

        $value = preg_replace(
            '/[\x00-\x1F\x7F]/u',
            '',
            $value
        );

        if ($value !== "") {
            $trackingParameters[$parameter] = substr($value, 0, 255);
        }
    }
}

$destination = "property-enquiry-start.php";

if (!empty($trackingParameters)) {

    $destination .= "?" . http_build_query(
        $trackingParameters,
        "",
        "&",
        PHP_QUERY_RFC3986
    );
}

header("Location: " . $destination, true, 302);
exit;
