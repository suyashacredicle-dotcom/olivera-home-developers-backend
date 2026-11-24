
<?php

// --------------------------------------------------
// DATABASE CONFIGURATION
// --------------------------------------------------

// LOCAL XAMPP DATABASE SETTINGS

$host = "localhost";
$dbname = "mywebsite_db";
$username = "root";
$password = "";

// --------------------------------------------------
// LIVE HOSTING DATABASE SETTINGS
// --------------------------------------------------

// When hosting your website, replace the values above
// with the database credentials provided by your host.
//
// Never use the XAMPP root account on live hosting.
// Use a dedicated database user with only the permissions
// your website requires.

// --------------------------------------------------
// CREATE SECURE PDO CONNECTION
// --------------------------------------------------

try {

    $pdo = new PDO(
        "mysql:host=" . $host .
        ";dbname=" . $dbname .
        ";charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false,

            PDO::ATTR_PERSISTENT =>
                false,

            PDO::ATTR_TIMEOUT =>
                5
        ]
    );

} catch (PDOException $e) {

    // Record technical details privately.
    error_log(
        "Database connection error: " .
        $e->getMessage()
    );

    // Display a generic message to visitors.
    http_response_code(500);

    exit(
        "A database connection error occurred. Please try again later."
    );

}

?>
