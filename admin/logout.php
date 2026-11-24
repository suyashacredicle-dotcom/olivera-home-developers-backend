
<?php

session_start();

require_once "../config/database.php";
require_once "../includes/activity_logger.php";

// Allow logout only through the submitted form
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit;
}

// Verify CSRF token
if (
    !isset($_SESSION["csrf_token"]) ||
    !hash_equals(
        $_SESSION["csrf_token"],
        $_POST["csrf_token"] ?? ""
    )
) {
    die("Invalid logout request.");
}

// Record logout before destroying the session
if (isset($_SESSION["admin_id"])) {

    logActivity(
        $pdo,
        "ADMIN_LOGOUT",
        "Admin " . ($_SESSION["admin_name"] ?? "Unknown") . " logged out successfully."
    );

}

// Clear session information
$_SESSION = [];

// Delete session cookie
if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );

}

// Destroy session
session_destroy();

// Return to login page
header("Location: login.php");

exit;

?>
