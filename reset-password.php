
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
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/config/database.php";

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
// CREATE CSRF TOKEN
// --------------------------------------------------

if (
    empty($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"])
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// --------------------------------------------------
// PAGE VARIABLES
// --------------------------------------------------

$message = "";
$messageType = "error";
$success = false;

$accountType = $_GET["type"] ?? $_POST["type"] ?? "";
$token = $_GET["token"] ?? $_POST["token"] ?? "";

$validRequest = true;
$resetRecord = false;

// --------------------------------------------------
// VALIDATE ACCOUNT TYPE AND TOKEN
// --------------------------------------------------

if (
    !is_string($accountType) ||
    !in_array($accountType, ["admin", "customer"], true) ||
    !is_string($token) ||
    !preg_match('/\A[a-f0-9]{64}\z/', $token)
) {
    $validRequest = false;
}

// --------------------------------------------------
// VALIDATE RESET TOKEN
// --------------------------------------------------

if ($validRequest) {

    try {

        $tokenHash = hash("sha256", $token);

        $stmt = $pdo->prepare("
            SELECT id, account_type, account_id, email
            FROM password_resets
            WHERE token_hash = ?
              AND account_type = ?
              AND used_at IS NULL
              AND expires_at > NOW()
            LIMIT 1
        ");

        $stmt->execute([
            $tokenHash,
            $accountType
        ]);

        $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resetRecord) {
            $validRequest = false;
        }

    } catch (Throwable $e) {

        error_log(
            "Password reset validation error: " .
            $e->getMessage()
        );

        $validRequest = false;
    }
}

// --------------------------------------------------
// PROCESS NEW PASSWORD
// --------------------------------------------------

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    $validRequest
) {

    $csrfToken = $_POST["csrf_token"] ?? "";

    $newPassword = $_POST["new_password"] ?? "";

    $confirmPassword = $_POST["confirm_password"] ?? "";

    // --------------------------------------------------
    // CSRF VALIDATION
    // --------------------------------------------------

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION["csrf_token"], $csrfToken)
    ) {

        http_response_code(403);

        $message = "Invalid request. Please refresh the page.";

    } elseif (
        !is_string($newPassword) ||
        !is_string($confirmPassword)
    ) {

        $message = "Invalid password information.";

    } elseif (
        strlen($newPassword) < 12 ||
        strlen($newPassword) > 72
    ) {

        $message = "Password must be between 12 and 72 characters.";

    } elseif (
        !preg_match('/[A-Z]/', $newPassword) ||
        !preg_match('/[a-z]/', $newPassword) ||
        !preg_match('/[0-9]/', $newPassword) ||
        !preg_match('/[^a-zA-Z0-9]/', $newPassword)
    ) {

        $message = "Password must contain uppercase, lowercase, number and special character.";

    } elseif ($newPassword !== $confirmPassword) {

        $message = "Passwords do not match.";

    } else {

        try {

            // --------------------------------------------------
            // START DATABASE TRANSACTION
            // --------------------------------------------------

            $pdo->beginTransaction();

            // --------------------------------------------------
            // LOCK ACCOUNT FIRST
            // This serializes password resets for the same account.
            // --------------------------------------------------

            if ($accountType === "admin") {

                $accountStmt = $pdo->prepare("
                    SELECT id
                    FROM admins
                    WHERE id = ?
                      AND email = ?
                      AND status = 'active'
                    LIMIT 1
                    FOR UPDATE
                ");

            } else {

                $accountStmt = $pdo->prepare("
                    SELECT id
                    FROM customers
                    WHERE id = ?
                      AND email = ?
                      AND status = 'active'
                    LIMIT 1
                    FOR UPDATE
                ");
            }

            $accountStmt->execute([
                $resetRecord["account_id"],
                $resetRecord["email"]
            ]);

            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {

                throw new RuntimeException(
                    "The account is no longer available."
                );
            }

            // --------------------------------------------------
            // LOCK AND REVALIDATE RESET TOKEN
            // --------------------------------------------------

            $stmt = $pdo->prepare("
                SELECT id, account_type, account_id, email
                FROM password_resets
                WHERE id = ?
                  AND token_hash = ?
                  AND account_type = ?
                  AND used_at IS NULL
                  AND expires_at > NOW()
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                $resetRecord["id"],
                hash("sha256", $token),
                $accountType
            ]);

            $lockedRecord = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$lockedRecord ||
                (int) $lockedRecord["account_id"] !==
                    (int) $resetRecord["account_id"] ||
                $lockedRecord["email"] !== $resetRecord["email"]
            ) {

                throw new RuntimeException(
                    "The reset link is invalid or expired."
                );
            }

            // --------------------------------------------------
            // HASH NEW PASSWORD
            // --------------------------------------------------

            $passwordHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            if (!$passwordHash) {

                throw new RuntimeException(
                    "Unable to secure the new password."
                );
            }

            // --------------------------------------------------
            // UPDATE ACCOUNT PASSWORD
            // --------------------------------------------------

            if ($accountType === "admin") {

                $updateStmt = $pdo->prepare("
                    UPDATE admins
                    SET password = ?
                    WHERE id = ?
                ");

            } else {

                $updateStmt = $pdo->prepare("
                    UPDATE customers
                    SET password = ?
                    WHERE id = ?
                ");
            }

            $updateStmt->execute([
                $passwordHash,
                $lockedRecord["account_id"]
            ]);

            // --------------------------------------------------
            // MARK ALL OUTSTANDING RESET LINKS AS USED
            // --------------------------------------------------

            $usedStmt = $pdo->prepare("
                UPDATE password_resets
                SET used_at = NOW()
                WHERE account_type = ?
                  AND account_id = ?
                  AND used_at IS NULL
            ");

            $usedStmt->execute([
                $accountType,
                $lockedRecord["account_id"]
            ]);

            if ($usedStmt->rowCount() < 1) {

                throw new RuntimeException(
                    "Unable to invalidate reset links."
                );
            }

            // --------------------------------------------------
            // COMMIT TRANSACTION
            // --------------------------------------------------

            $pdo->commit();

            $success = true;

            $message =
                "Your password has been changed successfully. " .
                "You can now log in using your new password.";

            $messageType = "success";

            // Remove token from page variables.

            $token = "";

            // Rotate CSRF token.

            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                "Password reset update error: " .
                $e->getMessage()
            );

            $message =
                "Unable to reset your password. " .
                "The reset link may have expired or already been used.";

            $messageType = "error";
        }
    }
}

// --------------------------------------------------
// ROTATE CSRF TOKEN AFTER POST REQUEST
// --------------------------------------------------

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    !$success
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Reset Password - My Website</title>

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
            padding: 20px;
            background: linear-gradient(
                135deg,
                #0f172a,
                #1e3a8a
            );
            font-family: Arial, sans-serif;
        }

        .container {
            width: 100%;
            max-width: 440px;
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
            text-align: center;
            color: #64748b;
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

        .password-rules {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 7px;
            color: #475569;
            font-size: 13px;
            line-height: 1.7;
            margin-bottom: 20px;
        }

        .show-password {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
            font-size: 13px;
            color: #475569;
        }

        .show-password input {
            width: auto;
            margin: 0;
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
            padding: 13px;
            border-radius: 7px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.6;
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

        <h2>Reset Your Password</h2>

        <p class="description">
            Create a new password for your account.
        </p>

        <?php if ($message !== ""): ?>

            <div
                class="message <?= e($messageType) ?>"
                role="alert"
            >
                <?= e($message) ?>
            </div>

        <?php endif; ?>

        <?php if ($success): ?>

            <div class="links">

                <?php if ($accountType === "admin"): ?>

                    <a href="admin/login.php">
                        Go to Admin Login
                    </a>

                <?php else: ?>

                    <a href="login.php">
                        Go to Customer Login
                    </a>

                <?php endif; ?>

            </div>

        <?php elseif ($validRequest): ?>

            <form method="POST" action="">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION["csrf_token"]) ?>"
                >

                <input
                    type="hidden"
                    name="type"
                    value="<?= e($accountType) ?>"
                >

                <input
                    type="hidden"
                    name="token"
                    value="<?= e($token) ?>"
                >

                <label for="new_password">
                    New Password
                </label>

                <input
                    type="password"
                    id="new_password"
                    name="new_password"
                    minlength="12"
                    maxlength="72"
                    autocomplete="new-password"
                    required
                >

                <label for="confirm_password">
                    Confirm New Password
                </label>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="12"
                    maxlength="72"
                    autocomplete="new-password"
                    required
                >

                <label class="show-password">

                    <input
                        type="checkbox"
                        id="showPasswords"
                    >

                    Show passwords

                </label>

                <div class="password-rules">

                    Your password must contain:

                    <br>• At least 12 characters

                    <br>• One uppercase letter

                    <br>• One lowercase letter

                    <br>• One number

                    <br>• One special character

                </div>

                <button type="submit">
                    Reset Password
                </button>

            </form>

        <?php else: ?>

            <div class="links">

                <?php if ($accountType === "admin"): ?>

                    <a href="admin/forgot-password.php">
                        Request a New Admin Reset Link
                    </a>

                <?php else: ?>

                    <a href="forgot-password.php">
                        Request a New Customer Reset Link
                    </a>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

    <script>

        // Remove the secret reset token from the address bar
        // after PHP has already validated and stored it for the form.

        if (window.history && window.history.replaceState) {

            const cleanUrl =
                window.location.pathname +
                "?type=" +
                encodeURIComponent(
                    <?= json_encode($accountType) ?>
                );

            window.history.replaceState(
                null,
                document.title,
                cleanUrl
            );
        }

        // Show / Hide Password

        const showPasswords =
            document.getElementById("showPasswords");

        if (showPasswords) {

            showPasswords.addEventListener("change", function () {

                const fields = [
                    document.getElementById("new_password"),
                    document.getElementById("confirm_password")
                ];

                fields.forEach(function (field) {

                    if (field) {
                        field.type = this.checked
                            ? "text"
                            : "password";
                    }

                }, this);

            });

        }

    </script>

</body>

</html>
