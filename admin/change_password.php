
<?php

require_once "../includes/auth.php";
require_once "../config/database.php";

$message = "";
$messageType = "";

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
// CHANGE PASSWORD
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken = $_POST["csrf_token"] ?? "";
    $sessionToken = $_SESSION["csrf_token"] ?? "";

    if (
        !is_string($submittedToken) ||
        !is_string($sessionToken) ||
        $sessionToken === "" ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);
        exit("Invalid request. Please reload the page.");
    }

    $currentPassword = $_POST["current_password"] ?? "";
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if (
        !is_string($currentPassword) ||
        !is_string($newPassword) ||
        !is_string($confirmPassword)
    ) {

        $message = "Invalid password input.";
        $messageType = "error";

    } elseif (
        strlen($currentPassword) > 4096 ||
        strlen($newPassword) > 4096 ||
        strlen($confirmPassword) > 4096
    ) {

        $message = "Password is too long.";
        $messageType = "error";

    } else {

        try {

            // GET CURRENT PASSWORD HASH

            $stmt = $pdo->prepare("
                SELECT password
                FROM admins
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $_SESSION["admin_id"]
            ]);

            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            // VERIFY CURRENT PASSWORD

            if (!$admin) {

                $message = "Admin account not found.";
                $messageType = "error";

            } elseif (
                !password_verify(
                    $currentPassword,
                    $admin["password"]
                )
            ) {

                $message = "Current password is incorrect.";
                $messageType = "error";

            } elseif (strlen($newPassword) < 12) {

                $message = "New password must contain at least 12 characters.";
                $messageType = "error";

            } elseif ($newPassword !== $confirmPassword) {

                $message = "New password and confirmation do not match.";
                $messageType = "error";

            } elseif ($currentPassword === $newPassword) {

                $message = "New password must be different from current password.";
                $messageType = "error";

            } elseif (!preg_match('/[A-Z]/', $newPassword)) {

                $message = "Password must contain at least one uppercase letter.";
                $messageType = "error";

            } elseif (!preg_match('/[a-z]/', $newPassword)) {

                $message = "Password must contain at least one lowercase letter.";
                $messageType = "error";

            } elseif (!preg_match('/[0-9]/', $newPassword)) {

                $message = "Password must contain at least one number.";
                $messageType = "error";

            } elseif (!preg_match('/[^a-zA-Z0-9]/', $newPassword)) {

                $message = "Password must contain at least one special character.";
                $messageType = "error";

            } else {

                // HASH NEW PASSWORD

                $hashedPassword = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                if ($hashedPassword === false) {

                    $message = "Password could not be secured. Please try again.";
                    $messageType = "error";

                } else {

                    // UPDATE PASSWORD

                    $stmt = $pdo->prepare("
                        UPDATE admins
                        SET password = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $hashedPassword,
                        $_SESSION["admin_id"]
                    ]);

                    if ($stmt->rowCount() === 1) {

                        // REGENERATE SESSION ID

                        session_regenerate_id(true);

                        // GENERATE FRESH CSRF TOKEN

                        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                        $message = "Password changed successfully!";
                        $messageType = "success";

                    } else {

                        $message = "Password could not be updated. Please try again.";
                        $messageType = "error";
                    }
                }
            }

        } catch (PDOException $e) {

            error_log($e->getMessage());

            $message = "Something went wrong. Please try again.";
            $messageType = "error";
        }
    }
}

// --------------------------------------------------
// OUTPUT ESCAPING
// --------------------------------------------------

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Change Password | Admin Panel</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6fa;
            font-family: Arial, Helvetica, sans-serif;
            color: #263247;
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */

        .sidebar {
            width: 250px;
            min-width: 250px;
            background: #17243a;
            color: #ffffff;
            padding: 25px 16px;
        }

        .sidebar-brand {
            font-size: 21px;
            font-weight: bold;
            padding: 0 12px 28px;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            margin-bottom: 22px;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .sidebar-menu a {
            display: block;
            padding: 12px 14px;
            color: #cbd5e1;
            text-decoration: none;
            border-radius: 7px;
            font-size: 14px;
            transition: 0.2s;
        }

        .sidebar-menu a:hover {
            background: #263954;
            color: #ffffff;
        }

        .sidebar-menu a.active {
            background: #315fbd;
            color: #ffffff;
            font-weight: bold;
        }

        .sidebar-section {
            color: #8292aa;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1px;
            padding: 18px 14px 7px;
            text-transform: uppercase;
        }

        /* MAIN CONTENT */

        .main-content {
            flex: 1;
            min-width: 0;
            padding: 35px;
        }

        .page-header {
            margin-bottom: 28px;
        }

        .page-header h1 {
            margin: 0 0 7px;
            font-size: 27px;
            color: #1d2b43;
        }

        .page-header p {
            margin: 0;
            color: #718096;
            font-size: 14px;
        }

        /* PASSWORD CARD */

        .password-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 30px;
            max-width: 850px;
            box-shadow: 0 3px 15px rgba(20, 35, 60, 0.05);
            border: 1px solid #e8edf4;
        }

        .card-heading {
            border-bottom: 1px solid #edf0f5;
            padding-bottom: 18px;
            margin-bottom: 25px;
        }

        .card-heading h2 {
            margin: 0 0 7px;
            font-size: 19px;
            color: #263247;
        }

        .card-heading p {
            margin: 0;
            color: #8290a3;
            font-size: 13px;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            margin-bottom: 9px;
            color: #35445a;
            font-size: 14px;
            font-weight: 600;
        }

        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            display: block;
            width: 100%;
            padding: 12px 75px 12px 14px;
            border: 1px solid #d8e0eb;
            border-radius: 7px;
            font-size: 14px;
            color: #263247;
            background: #ffffff;
            outline: none;
            transition: 0.2s;
        }

        .password-wrapper input:focus {
            border-color: #4776d0;
            box-shadow: 0 0 0 3px rgba(71,118,208,0.12);
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #315fbd;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            padding: 6px;
        }

        .toggle-password:hover {
            color: #244b99;
        }

        /* PASSWORD REQUIREMENTS */

        .requirements-box {
            background: #f7f9fc;
            border: 1px solid #e5eaf2;
            border-radius: 9px;
            padding: 18px 20px;
            margin: 25px 0;
        }

        .requirements-box h3 {
            margin: 0 0 13px;
            font-size: 14px;
            color: #35445a;
        }

        .requirements-list {
            margin: 0;
            padding-left: 20px;
            color: #66758b;
            font-size: 13px;
            line-height: 2;
        }

        /* MESSAGES */

        .message {
            padding: 13px 16px;
            border-radius: 7px;
            margin-bottom: 22px;
            font-size: 14px;
        }

        .message.success {
            background: #e8f7ee;
            color: #187444;
            border: 1px solid #c7ead5;
        }

        .message.error {
            background: #fff0f0;
            color: #b42323;
            border: 1px solid #f3cccc;
        }

        /* BUTTONS */

        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .btn {
            display: inline-block;
            padding: 12px 20px;
            border: none;
            border-radius: 7px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-primary {
            background: #315fbd;
            color: #ffffff;
        }

        .btn-primary:hover {
            background: #244b99;
        }

        .btn-secondary {
            background: #edf1f7;
            color: #35445a;
        }

        .btn-secondary:hover {
            background: #dfe6f0;
        }

        /* FORGOT PASSWORD */

        .forgot-password {
            margin-top: 15px;
            text-align: right;
        }

        .forgot-password a {
            color: #315fbd;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .forgot-password a:hover {
            color: #244b99;
            text-decoration: underline;
        }

        .account-links {
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid #edf0f5;
        }

        .account-links a {
            color: #315fbd;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .account-links a:hover {
            text-decoration: underline;
        }

        /* RESPONSIVE DESIGN */

        @media (max-width: 850px) {

            .admin-layout {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                min-width: 0;
                padding: 18px;
            }

            .sidebar-brand {
                padding-bottom: 15px;
                margin-bottom: 12px;
            }

            .sidebar-menu {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .sidebar-section {
                grid-column: 1 / -1;
            }

            .main-content {
                padding: 22px;
            }
        }

        @media (max-width: 500px) {

            .main-content {
                padding: 15px;
            }

            .password-card {
                padding: 20px;
            }

            .sidebar-menu {
                grid-template-columns: 1fr;
            }

            .page-header h1 {
                font-size: 23px;
            }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .form-actions .btn {
                text-align: center;
            }
        }

    </style>

</head>

<body>

<div class="admin-layout">

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <div class="sidebar-brand">
            Admin Panel
        </div>

        <nav class="sidebar-menu">

            <a href="dashboard.php">
                ◈ Dashboard
            </a>

            <?php
            require __DIR__ . "/includes/gallery_sidebar_link.php";
            ?>

            <div class="sidebar-section">
                Administration
            </div>

            <a href="manage_admins.php">
                ◉ Manage Admins
            </a>

            <a href="manage_users.php">
                ◉ Manage Users
            </a>

            <a href="manage_customers.php">
                ◉ Manage Customers
            </a>

            <a href="manage_content.php">
                ◉ Manage Content
            </a>

            <a href="manage_settings.php">
                ⚙ Website Settings
            </a>

            <a href="activity_logs.php">
                ◷ Activity Logs
            </a>

            <div class="sidebar-section">
                My Account
            </div>

            <a href="profile.php">
                ◉ My Profile
            </a>

            <a href="change_password.php" class="active">
                🔒 Change Password
            </a>

            <a href="logout.php">
                ↪ Logout
            </a>

        </nav>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="main-content">

        <div class="page-header">

            <h1>Change Password</h1>

            <p>
                Keep your administrator account secure by updating your password.
            </p>

        </div>

        <?php if ($message !== ""): ?>

            <div class="message <?php echo e($messageType); ?>">
                <?php echo e($message); ?>
            </div>

        <?php endif; ?>

        <section class="password-card">

            <div class="card-heading">

                <h2>Update Your Password</h2>

                <p>
                    Enter your current password and choose a strong new password.
                </p>

            </div>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($_SESSION["csrf_token"]); ?>"
                >

                <!-- CURRENT PASSWORD -->

                <div class="form-group">

                    <label for="current_password">
                        Current Password
                    </label>

                    <div class="password-wrapper">

                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            autocomplete="current-password"
                            maxlength="4096"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            data-target="current_password"
                            aria-label="Show current password"
                            aria-pressed="false"
                        >
                            Show
                        </button>

                    </div>

                </div>

                <!-- NEW PASSWORD -->

                <div class="form-group">

                    <label for="new_password">
                        New Password
                    </label>

                    <div class="password-wrapper">

                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            autocomplete="new-password"
                            minlength="12"
                            maxlength="4096"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            data-target="new_password"
                            aria-label="Show new password"
                            aria-pressed="false"
                        >
                            Show
                        </button>

                    </div>

                </div>

                <!-- CONFIRM PASSWORD -->

                <div class="form-group">

                    <label for="confirm_password">
                        Confirm New Password
                    </label>

                    <div class="password-wrapper">

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            autocomplete="new-password"
                            minlength="12"
                            maxlength="4096"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            data-target="confirm_password"
                            aria-label="Show confirm password"
                            aria-pressed="false"
                        >
                            Show
                        </button>

                    </div>

                </div>

                <!-- PASSWORD REQUIREMENTS -->

                <div class="requirements-box">

                    <h3>Your new password must contain:</h3>

                    <ul class="requirements-list">
                        <li>At least 12 characters</li>
                        <li>One uppercase letter (A-Z)</li>
                        <li>One lowercase letter (a-z)</li>
                        <li>One number (0-9)</li>
                        <li>One special character</li>
                    </ul>

                </div>

                <!-- BUTTONS -->

                <div class="form-actions">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Change Password
                    </button>

                    <a
                        href="profile.php"
                        class="btn btn-secondary"
                    >
                        Back to My Profile
                    </a>

                </div>

                <!-- FORGOT PASSWORD LINK -->

                <div class="forgot-password">

                    <a href="../forgot-password.php">
                        Forgot your current password?
                    </a>

                </div>

            </form>

            <div class="account-links">

                <a href="dashboard.php">
                    ← Back to Dashboard
                </a>

            </div>

        </section>

    </main>

</div>

<!-- SHOW / HIDE PASSWORD SCRIPT -->

<script>

    document.querySelectorAll(".toggle-password").forEach(function(button) {

        button.addEventListener("click", function() {

            const targetId = this.getAttribute("data-target");
            const passwordInput = document.getElementById(targetId);

            if (!passwordInput) {
                return;
            }

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

    });

</script>

</body>
</html>
