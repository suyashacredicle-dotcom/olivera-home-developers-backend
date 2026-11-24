
<?php

// --------------------------------------------------
// SECURITY HEADERS
// --------------------------------------------------

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Referrer-Policy: no-referrer");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

// --------------------------------------------------
// FIXED ACCOUNT TYPE
// --------------------------------------------------

if (!defined("PASSWORD_RESET_ACCOUNT_TYPE")) {
    define("PASSWORD_RESET_ACCOUNT_TYPE", "customer");
}

$accountType = PASSWORD_RESET_ACCOUNT_TYPE;

if (
    !is_string($accountType) ||
    !in_array($accountType, ["admin", "customer"], true)
) {
    http_response_code(403);
    exit("Invalid password recovery page.");
}

$isAdmin = ($accountType === "admin");

// --------------------------------------------------
// LOAD APPLICATION CONFIGURATION
// --------------------------------------------------

require_once __DIR__ . "/config/app.php";

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

if (session_status() !== PHP_SESSION_ACTIVE) {

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "domain" => "",
        "secure" => $isHttps,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    session_start();
}

// --------------------------------------------------
// LOAD DATABASE AND EMAIL
// --------------------------------------------------

require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/config/mail.php";

// --------------------------------------------------
// HELPER FUNCTION
// --------------------------------------------------

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        "UTF-8"
    );
}

// --------------------------------------------------
// CSRF TOKEN
// --------------------------------------------------

if (
    empty($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"])
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// --------------------------------------------------
// RATE LIMIT SETTINGS
// --------------------------------------------------

$maximumIpRequests = 5;
$maximumEmailRequests = 3;

// --------------------------------------------------
// PAGE VARIABLES
// --------------------------------------------------

$message = "";
$messageType = "info";
$email = "";

$pageTitle = $isAdmin
    ? "Admin Forgot Password?"
    : "Customer Forgot Password?";

$emailLabel = $isAdmin
    ? "Registered Admin Email Address"
    : "Registered Customer Email Address";

$description = $isAdmin
    ? "Enter your registered admin email address. If an active admin account exists, we will send you a secure password reset link."
    : "Enter your registered customer email address. If an active customer account exists, we will send you a secure password reset link.";

$loginLink = "login.php";

$loginText = $isAdmin
    ? "Back to Admin Login"
    : "Back to Customer Login";

// --------------------------------------------------
// PROCESS FORM
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $emailInput = $_POST["email"] ?? "";
    $csrfToken = $_POST["csrf_token"] ?? "";

    $email = is_string($emailInput)
        ? strtolower(trim($emailInput))
        : "";

    // --------------------------------------------------
    // CSRF VALIDATION
    // --------------------------------------------------

    if (
        !is_string($csrfToken) ||
        !is_string($_SESSION["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $csrfToken)
    ) {

        http_response_code(403);

        $message = "Invalid request. Please refresh the page.";
        $messageType = "error";

    } elseif (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 254
    ) {

        $message = "Please enter a valid email address.";
        $messageType = "error";

    } else {

        $rateLimitLocks = [];

        try {

            // --------------------------------------------------
            // CREATE RATE LIMIT IDENTIFIERS
            // --------------------------------------------------

            $ipAddress = $_SERVER["REMOTE_ADDR"] ?? "";

            if (
                !is_string($ipAddress) ||
                $ipAddress === ""
            ) {
                $ipAddress = "unknown";
            }

            $ipHash = hash("sha256", $ipAddress);
            $emailHash = hash("sha256", $email);

            // --------------------------------------------------
            // ACQUIRE DATABASE LOCKS
            // --------------------------------------------------
            // These locks serialize requests for the same IP
            // and email address.
            // Sorting prevents lock-order deadlocks.

            $lockKeys = [
                "pwreset:" . substr(
                    hash("sha256", $accountType . "|ip|" . $ipHash),
                    0,
                    56
                ),

                "pwreset:" . substr(
                    hash("sha256", $accountType . "|email|" . $emailHash),
                    0,
                    56
                )
            ];

            sort($lockKeys, SORT_STRING);

            foreach ($lockKeys as $lockKey) {

                $lockStmt = $pdo->prepare("
                    SELECT GET_LOCK(?, 5)
                ");

                $lockStmt->execute([$lockKey]);

                $lockResult = $lockStmt->fetchColumn();

                if ((int) $lockResult !== 1) {

                    throw new RuntimeException(
                        "Unable to acquire password reset rate limit lock."
                    );
                }

                $rateLimitLocks[] = $lockKey;
            }

            // --------------------------------------------------
            // RECORD REQUEST
            // --------------------------------------------------

            $requestStmt = $pdo->prepare("
                INSERT INTO password_reset_requests (
                    account_type,
                    ip_hash,
                    email_hash
                )
                VALUES (?, ?, ?)
            ");

            $requestStmt->execute([
                $accountType,
                $ipHash,
                $emailHash
            ]);

            // --------------------------------------------------
            // COUNT IP REQUESTS
            // --------------------------------------------------

            $ipCountStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM password_reset_requests
                WHERE account_type = ?
                  AND ip_hash = ?
                  AND requested_at >= DATE_SUB(
                      NOW(),
                      INTERVAL 15 MINUTE
                  )
            ");

            $ipCountStmt->execute([
                $accountType,
                $ipHash
            ]);

            $ipRequestCount = (int) $ipCountStmt->fetchColumn();

            // --------------------------------------------------
            // COUNT EMAIL REQUESTS
            // --------------------------------------------------

            $emailCountStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM password_reset_requests
                WHERE account_type = ?
                  AND email_hash = ?
                  AND requested_at >= DATE_SUB(
                      NOW(),
                      INTERVAL 15 MINUTE
                  )
            ");

            $emailCountStmt->execute([
                $accountType,
                $emailHash
            ]);

            $emailRequestCount = (int) $emailCountStmt->fetchColumn();

            // --------------------------------------------------
            // ENFORCE RATE LIMITS
            // --------------------------------------------------

            if (
                $ipRequestCount > $maximumIpRequests ||
                $emailRequestCount > $maximumEmailRequests
            ) {

                $message = "Too many password reset requests. Please try again after 15 minutes.";

                $messageType = "error";

            } else {

                // --------------------------------------------------
                // FIND ACCOUNT OF FIXED TYPE ONLY
                // --------------------------------------------------

                if ($isAdmin) {

                    $stmt = $pdo->prepare("
                        SELECT id, name, email
                        FROM admins
                        WHERE email = ?
                          AND status = 'active'
                        LIMIT 1
                    ");

                } else {

                    $stmt = $pdo->prepare("
                        SELECT id, name, email
                        FROM customers
                        WHERE email = ?
                          AND status = 'active'
                        LIMIT 1
                    ");
                }

                $stmt->execute([$email]);

                $account = $stmt->fetch(PDO::FETCH_ASSOC);

                // --------------------------------------------------
                // GENERATE RESET TOKEN
                // --------------------------------------------------

                if ($account) {

                    $token = bin2hex(random_bytes(32));

                    $tokenHash = hash("sha256", $token);

                    $expiresAt = date(
                        "Y-m-d H:i:s",
                        time() + 3600
                    );

                    // --------------------------------------------------
                    // TRANSACTION: REPLACE OLD RESET TOKENS
                    // --------------------------------------------------

                    $pdo->beginTransaction();

                    try {

                        // Lock the account row before replacing tokens.

                        if ($isAdmin) {

                            $accountLockStmt = $pdo->prepare("
                                SELECT id
                                FROM admins
                                WHERE id = ?
                                  AND email = ?
                                  AND status = 'active'
                                LIMIT 1
                                FOR UPDATE
                            ");

                        } else {

                            $accountLockStmt = $pdo->prepare("
                                SELECT id
                                FROM customers
                                WHERE id = ?
                                  AND email = ?
                                  AND status = 'active'
                                LIMIT 1
                                FOR UPDATE
                            ");
                        }

                        $accountLockStmt->execute([
                            $account["id"],
                            $account["email"]
                        ]);

                        $lockedAccount = $accountLockStmt->fetch(
                            PDO::FETCH_ASSOC
                        );

                        if (!$lockedAccount) {

                            throw new RuntimeException(
                                "Account is no longer available."
                            );
                        }

                        // Remove previous unused tokens.

                        $deleteStmt = $pdo->prepare("
                            DELETE FROM password_resets
                            WHERE account_type = ?
                              AND account_id = ?
                              AND used_at IS NULL
                        ");

                        $deleteStmt->execute([
                            $accountType,
                            $account["id"]
                        ]);

                        // Store hashed token.

                        $insertStmt = $pdo->prepare("
                            INSERT INTO password_resets (
                                account_type,
                                account_id,
                                email,
                                token_hash,
                                expires_at
                            )
                            VALUES (?, ?, ?, ?, ?)
                        ");

                        $insertStmt->execute([
                            $accountType,
                            $account["id"],
                            $account["email"],
                            $tokenHash,
                            $expiresAt
                        ]);

                        $pdo->commit();

                    } catch (Throwable $e) {

                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        throw $e;
                    }

                    // --------------------------------------------------
                    // CREATE RESET LINK
                    // --------------------------------------------------

                    $resetLink =
                        rtrim(APP_BASE_URL, "/") .
                        "/reset-password.php" .
                        "?type=" . urlencode($accountType) .
                        "&token=" . urlencode($token);

                    // --------------------------------------------------
                    // SEND EMAIL
                    // --------------------------------------------------

                    $emailSent = sendPasswordResetEmail(
                        $account["email"],
                        $account["name"],
                        $resetLink
                    );

                    // --------------------------------------------------
                    // HANDLE EMAIL FAILURE
                    // --------------------------------------------------

                    if (!$emailSent) {

                        $cleanupStmt = $pdo->prepare("
                            DELETE FROM password_resets
                            WHERE token_hash = ?
                              AND used_at IS NULL
                        ");

                        $cleanupStmt->execute([
                            $tokenHash
                        ]);

                        error_log(
                            ucfirst($accountType) .
                            " password reset email could not be sent."
                        );
                    }
                }

                // --------------------------------------------------
                // GENERIC RESPONSE
                // --------------------------------------------------

                $message = "If an active account exists with that email address, a password reset link will be sent. Please check your inbox.";

                $messageType = "success";
            }

            // --------------------------------------------------
            // CLEAN OLD RATE LIMIT RECORDS
            // --------------------------------------------------

            if (random_int(1, 100) === 1) {

                $cleanupRequestsStmt = $pdo->prepare("
                    DELETE FROM password_reset_requests
                    WHERE requested_at < DATE_SUB(
                        NOW(),
                        INTERVAL 24 HOUR
                    )
                ");

                $cleanupRequestsStmt->execute();
            }

        } catch (Throwable $e) {

            error_log(
                ucfirst($accountType) .
                " password reset request error: " .
                $e->getMessage()
            );

            $message = "We could not process your request right now. Please try again later.";

            $messageType = "error";

        } finally {

            // --------------------------------------------------
            // RELEASE DATABASE LOCKS
            // --------------------------------------------------

            foreach ($rateLimitLocks as $lockKey) {

                try {

                    $releaseStmt = $pdo->prepare("
                        SELECT RELEASE_LOCK(?)
                    ");

                    $releaseStmt->execute([$lockKey]);

                } catch (Throwable $e) {

                    error_log(
                        "Password reset lock release error: " .
                        $e->getMessage()
                    );
                }
            }
        }
    }
}

// --------------------------------------------------
// ROTATE CSRF TOKEN
// --------------------------------------------------

$_SESSION["csrf_token"] = bin2hex(random_bytes(32));

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> - My Website</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(
                135deg,
                #0f172a,
                #1e3a8a
            );
            font-family: Arial, sans-serif;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 430px;
            background: white;
            padding: 35px;
            border-radius: 14px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
        }

        h2 {
            text-align: center;
            color: #172554;
            margin-top: 0;
        }

        .description {
            color: #64748b;
            text-align: center;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #334155;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #2563eb;
        }

        button {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 7px;
            background: #1d4ed8;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #1e40af;
        }

        button:focus-visible {
            outline: 3px solid #93c5fd;
            outline-offset: 3px;
        }

        .message {
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 20px;
            line-height: 1.5;
            font-size: 14px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .links {
            text-align: center;
            margin-top: 22px;
        }

        .links a {
            display: block;
            margin: 10px 0;
            color: #1d4ed8;
            text-decoration: none;
            font-size: 14px;
        }

        .links a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {

            .container {
                padding: 28px 22px;
            }

        }

    </style>

</head>

<body>

    <div class="container">

        <h2><?= e($pageTitle) ?></h2>

        <p class="description">
            <?= e($description) ?>
        </p>

        <?php if ($message !== ""): ?>

            <div
                class="message <?= e($messageType) ?>"
                role="alert"
            >
                <?= e($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION["csrf_token"]) ?>"
            >

            <label for="email">
                <?= e($emailLabel) ?>
            </label>

            <input
                type="email"
                id="email"
                name="email"
                value="<?= e($email) ?>"
                maxlength="254"
                placeholder="Enter your email address"
                autocomplete="email"
                required
            >

            <button type="submit">
                Send Reset Link
            </button>

        </form>

        <div class="links">

            <a href="<?= e($loginLink) ?>">
                <?= e($loginText) ?>
            </a>

        </div>

    </div>

</body>

</html>
