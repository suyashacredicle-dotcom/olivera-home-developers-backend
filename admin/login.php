
<?php

// --------------------------------------------------
// SECURE SESSION CONFIGURATION
// --------------------------------------------------

$isHttps = (
    isset($_SERVER["HTTPS"]) &&
    $_SERVER["HTTPS"] !== "off" &&
    $_SERVER["HTTPS"] !== ""
);

ini_set("session.use_strict_mode", "1");
ini_set("session.use_only_cookies", "1");
ini_set("session.use_trans_sid", "0");

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "domain" => "",
    "secure" => $isHttps,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/activity_logger.php";

// --------------------------------------------------
// REDIRECT ALREADY LOGGED-IN ADMIN
// --------------------------------------------------

if (isset($_SESSION["admin_id"])) {
    header("Location: dashboard.php");
    exit;
}

// --------------------------------------------------
// LOAD WEBSITE LOGO FROM SETTINGS
// --------------------------------------------------

$websiteLogo = "";

$logoStmt = $pdo->prepare("
    SELECT setting_value
    FROM settings
    WHERE setting_key = ?
    LIMIT 1
");

$logoStmt->execute(["website_logo"]);

$savedLogo = $logoStmt->fetchColumn();

if (
    is_string($savedLogo) &&
    preg_match(
        '/^uploads\/[a-f0-9]{32}\.(jpg|png|webp)$/i',
        $savedLogo
    ) &&
    is_file(__DIR__ . "/../" . $savedLogo)
) {
    $websiteLogo = "../" . $savedLogo;
}

$error = "";

// --------------------------------------------------
// CREATE CSRF TOKEN
// --------------------------------------------------

if (
    empty($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"])
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// --------------------------------------------------
// LOGIN PROCESS
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION["csrf_token"], $submittedToken)
    ) {
        http_response_code(403);
        exit("Invalid request. Please reload the page.");
    }

    $emailInput = $_POST["email"] ?? "";
    $password = $_POST["password"] ?? "";

    $email = is_string($emailInput)
        ? strtolower(trim($emailInput))
        : "";

    $password = is_string($password)
        ? $password
        : "";

    $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "unknown";

    $email = substr($email, 0, 254);
    $ipAddress = substr($ipAddress, 0, 45);

    $validInput = (
        filter_var($email, FILTER_VALIDATE_EMAIL) &&
        strlen($email) <= 254 &&
        $password !== "" &&
        strlen($password) <= 4096
    );

    // --------------------------------------------------
    // CHECK FAILED LOGIN ATTEMPTS
    // --------------------------------------------------

    $emailAttempts = 0;
    $ipAttempts = 0;

    if ($validInput) {

        $attemptStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM admin_login_attempts
            WHERE email = ?
              AND ip_address = ?
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");

        $attemptStmt->execute([
            $email,
            $ipAddress
        ]);

        $emailAttempts = (int) $attemptStmt->fetchColumn();
    }

    $ipStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM admin_login_attempts
        WHERE ip_address = ?
          AND attempted_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");

    $ipStmt->execute([$ipAddress]);

    $ipAttempts = (int) $ipStmt->fetchColumn();

    // --------------------------------------------------
    // BLOCK EXCESSIVE LOGIN ATTEMPTS
    // --------------------------------------------------

    if ($emailAttempts >= 5 || $ipAttempts >= 20) {

        $error = "Too many login attempts. Please wait 1 minute before trying again.";

    } else {

        $admin = false;

        // --------------------------------------------------
        // VERIFY ADMIN ACCOUNT
        // --------------------------------------------------

        if ($validInput) {

            $stmt = $pdo->prepare("
                SELECT id, name, email, password, role, status
                FROM admins
                WHERE email = ?
                LIMIT 1
            ");

            $stmt->execute([$email]);

            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // --------------------------------------------------
        // CHECK PASSWORD AND ACCOUNT STATUS
        // --------------------------------------------------

        if (
            $admin &&
            $admin["status"] === "active" &&
            password_verify($password, $admin["password"])
        ) {

            session_regenerate_id(true);

            $_SESSION["admin_id"] = (int) $admin["id"];
            $_SESSION["admin_name"] = $admin["name"];
            $_SESSION["admin_role"] = $admin["role"];

            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

            // Clear failed attempts.

            $clearStmt = $pdo->prepare("
                DELETE FROM admin_login_attempts
                WHERE email = ?
                  AND ip_address = ?
            ");

            $clearStmt->execute([
                $email,
                $ipAddress
            ]);

            logActivity(
                $pdo,
                "ADMIN_LOGIN",
                "Admin " . $admin["name"] . " logged in successfully."
            );

            header("Location: dashboard.php");
            exit;

        } else {

            // Record failed attempt.

            $insertStmt = $pdo->prepare("
                INSERT INTO admin_login_attempts
                    (email, ip_address)
                VALUES (?, ?)
            ");

            $insertStmt->execute([
                $email,
                $ipAddress
            ]);

            $error = "Invalid email or password, or account is inactive.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Portal Login</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 25px 15px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(
                135deg,
                #071426,
                #102c50,
                #173c65
            );
        }

        .login-container {
            width: 100%;
            max-width: 430px;
            padding: 42px 38px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.30);
        }

        .logo {
            width: 100%;
            height: 85px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo img {
            max-width: 180px;
            max-height: 85px;
            width: auto;
            height: auto;
            object-fit: contain;
        }

        h1 {
            margin: 0;
            text-align: center;
            color: #14243a;
            font-size: 27px;
        }

        .subtitle {
            margin: 10px 0 30px;
            text-align: center;
            color: #718096;
            font-size: 14px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 22px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            color: #26364a;
            font-size: 14px;
            font-weight: bold;
        }

        input {
            width: 100%;
            height: 48px;
            padding: 0 14px;
            border: 1px solid #d8e0eb;
            border-radius: 8px;
            background: #f9fbfd;
            color: #1e293b;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
            background: #ffffff;
        }

        input::placeholder {
            color: #a0aec0;
        }

        /* PASSWORD SHOW / HIDE */

        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            padding-right: 75px;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #2563eb;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            padding: 6px;
        }

        .toggle-password:hover {
            color: #1d4ed8;
        }

        /* FORGOT PASSWORD */

        .forgot-password {
            margin-top: -9px;
            margin-bottom: 20px;
            text-align: right;
        }

        .forgot-password a {
            color: #2563eb;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
        }

        .forgot-password a:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .login-button {
            width: 100%;
            height: 49px;
            margin-top: 8px;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: #ffffff;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s, transform 0.2s;
        }

        .login-button:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        .login-button:focus-visible,
        .toggle-password:focus-visible {
            outline: 3px solid #93c5fd;
            outline-offset: 3px;
        }

        .error-message {
            padding: 13px;
            margin-bottom: 22px;
            border: 1px solid #fecaca;
            border-radius: 8px;
            background: #fef2f2;
            color: #b91c1c;
            font-size: 13px;
            line-height: 1.5;
            text-align: center;
        }

        .footer {
            margin-top: 28px;
            padding-top: 18px;
            border-top: 1px solid #edf0f5;
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
        }

        @media (max-width: 480px) {

            .login-container {
                padding: 32px 24px;
            }

            h1 {
                font-size: 24px;
            }

        }

    </style>

</head>

<body>

    <div class="login-container">

        <?php if ($websiteLogo !== ""): ?>

            <div class="logo">
                <img
                    src="<?php
                    echo htmlspecialchars(
                        $websiteLogo,
                        ENT_QUOTES,
                        "UTF-8"
                    );
                    ?>"
                    alt="Website Logo"
                >
            </div>

        <?php endif; ?>

        <h1>Admin Portal</h1>

        <p class="subtitle">
            Sign in to access your website administration panel
        </p>

        <?php if ($error !== ""): ?>

            <div class="error-message" role="alert">
                <?php
                echo htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                );
                ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php
                echo htmlspecialchars(
                    $_SESSION["csrf_token"],
                    ENT_QUOTES,
                    "UTF-8"
                );
                ?>"
            >

            <div class="form-group">

                <label for="admin_email">
                    Email Address
                </label>

                <input
                    type="email"
                    id="admin_email"
                    name="email"
                    placeholder="Enter your email address"
                    autocomplete="username"
                    maxlength="254"
                    required
                >

            </div>

            <div class="form-group">

                <label for="admin_password">
                    Password
                </label>

                <div class="password-wrapper">

                    <input
                        type="password"
                        id="admin_password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        maxlength="4096"
                        required
                    >

                    <button
                        type="button"
                        class="toggle-password"
                        aria-label="Show password"
                        aria-pressed="false"
                    >
                        Show
                    </button>

                </div>

            </div>

            <div class="forgot-password">
                <a href="forgot-password.php">
                    Forgot Password?
                </a>
            </div>

            <button type="submit" class="login-button">
                Login to Admin Panel
            </button>

        </form>

        <div class="footer">
            Secure Administration Portal
        </div>

    </div>

    <script>

        const toggleButton = document.querySelector(".toggle-password");

        const passwordInput = document.getElementById("admin_password");

        toggleButton.addEventListener("click", function() {

            const isHidden = passwordInput.type === "password";

            passwordInput.type = isHidden ? "text" : "password";

            this.textContent = isHidden ? "Hide" : "Show";

            this.setAttribute(
                "aria-label",
                isHidden ? "Hide password" : "Show password"
            );

            this.setAttribute(
                "aria-pressed",
                isHidden ? "true" : "false"
            );

        });

    </script>

</body>

</html>
