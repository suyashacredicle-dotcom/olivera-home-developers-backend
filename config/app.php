
<?php

// --------------------------------------------------
// WEBSITE URL CONFIGURATION
// --------------------------------------------------

// LOCAL XAMPP WEBSITE
define("APP_BASE_URL", "http://localhost/mywebsite");

// --------------------------------------------------
// LIVE HOSTING CONFIGURATION
// --------------------------------------------------

// When your website is hosted, replace the URL above
// with your actual domain, for example:
//
// define("APP_BASE_URL", "https://www.yourdomain.com");
//
// Do not include a trailing slash.

// --------------------------------------------------
// CONFIGURATION VALIDATION
// --------------------------------------------------

if (
    !filter_var(APP_BASE_URL, FILTER_VALIDATE_URL) ||
    !in_array(
        parse_url(APP_BASE_URL, PHP_URL_SCHEME),
        ["http", "https"],
        true
    )
) {
    throw new RuntimeException(
        "Invalid website URL configuration."
    );
}
