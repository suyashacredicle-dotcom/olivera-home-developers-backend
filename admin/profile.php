
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
// GET CURRENT ADMIN INFORMATION
// --------------------------------------------------

$stmt = $pdo->prepare("
    SELECT id, name, email, role
    FROM admins
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$_SESSION["admin_id"]]);

$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    $_SESSION = [];
    session_destroy();

    header("Location: login.php");
    exit;
}

$formName = $admin["name"];
$formEmail = $admin["email"];

// --------------------------------------------------
// UPDATE PROFILE
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

    $nameInput = $_POST["name"] ?? "";
    $emailInput = $_POST["email"] ?? "";

    if (
        !is_string($nameInput) ||
        !is_string($emailInput)
    ) {
        $message = "Invalid profile information.";
        $messageType = "error";

    } else {

        $name = trim($nameInput);
        $email = strtolower(trim($emailInput));

        $formName = $name;
        $formEmail = $email;

        if ($name === "") {

            $message = "Name is required.";
            $messageType = "error";

        } elseif (strlen($name) > 100) {

            $message = "Name cannot exceed 100 characters.";
            $messageType = "error";

        } elseif (strlen($email) > 254) {

            $message = "Email address is too long.";
            $messageType = "error";

        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $message = "Please enter a valid email address.";
            $messageType = "error";

        } else {

            try {

                $stmt = $pdo->prepare("
                    UPDATE admins
                    SET name = ?, email = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $_SESSION["admin_id"]
                ]);

                $_SESSION["admin_name"] = $name;

                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                $message = "Your profile has been updated successfully.";
                $messageType = "success";

                $stmt = $pdo->prepare("
                    SELECT id, name, email, role
                    FROM admins
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->execute([$_SESSION["admin_id"]]);

                $admin = $stmt->fetch(PDO::FETCH_ASSOC);

                $formName = $admin["name"];
                $formEmail = $admin["email"];

            } catch (PDOException $e) {

                if ($e->getCode() === "23000") {
                    $message = "This email address is already registered.";
                } else {
                    error_log($e->getMessage());
                    $message = "Something went wrong. Please try again.";
                }

                $messageType = "error";
            }
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

$avatarInitial = strtoupper(substr(trim($formName), 0, 1));

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile | Admin Panel</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f5f7fb;
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
            border-radius: 8px;
            font-size: 14px;
            transition: background 0.2s, color 0.2s;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 27px;
        }

        .page-title-group {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #eaf0ff;
            color: #315fbd;
            font-size: 23px;
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

        .breadcrumb {
            font-size: 12px;
            color: #8995a7;
            margin-top: 6px;
        }

        /* PROFILE CARD */

        .profile-card {
            background: #ffffff;
            border-radius: 13px;
            padding: 30px;
            max-width: 900px;
            border: 1px solid #e7ecf3;
            box-shadow: 0 4px 18px rgba(20, 35, 60, 0.045);
        }

        .card-heading {
            border-bottom: 1px solid #edf0f5;
            padding-bottom: 19px;
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
            line-height: 1.6;
        }

        /* PROFILE IDENTITY */

        .profile-identity {
            display: flex;
            align-items: center;
            gap: 17px;
            padding: 19px;
            margin-bottom: 27px;
            border: 1px solid #e9eef6;
            border-radius: 11px;
            background: #f9fbfe;
        }

        .profile-avatar {
            width: 66px;
            height: 66px;
            min-width: 66px;
            border-radius: 50%;
            background: #e8efff;
            color: #315fbd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: bold;
            border: 3px solid #ffffff;
            box-shadow: 0 2px 8px rgba(49,95,189,0.12);
        }

        .profile-identity h3 {
            margin: 0 0 6px;
            color: #25344b;
            font-size: 17px;
        }

        .profile-identity p {
            margin: 0;
            color: #8290a3;
            font-size: 13px;
            word-break: break-word;
        }

        /* FORM */

        .form-section-title {
            color: #263247;
            font-size: 15px;
            font-weight: 700;
            margin: 0 0 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 21px;
            min-width: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 9px;
            color: #35445a;
            font-size: 13px;
            font-weight: 600;
        }

        .form-control {
            display: block;
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #dce3ed;
            border-radius: 8px;
            font-size: 14px;
            color: #263247;
            background: #ffffff;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus {
            border-color: #4776d0;
            box-shadow: 0 0 0 3px rgba(71,118,208,0.11);
        }

        .role-label {
            display: inline-flex;
            align-items: center;
            padding: 8px 14px;
            background: #eaf0ff;
            color: #315fbd;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .form-help {
            display: block;
            margin-top: 9px;
            color: #8a96a8;
            font-size: 12px;
            line-height: 1.5;
        }

        /* MESSAGES */

        .message {
            padding: 14px 17px;
            border-radius: 8px;
            margin-bottom: 22px;
            font-size: 14px;
            line-height: 1.5;
        }

        .message.success {
            background: #eaf7ef;
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
            margin-top: 12px;
            padding-top: 23px;
            border-top: 1px solid #edf0f5;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.2s;
        }

        .btn:hover {
            transform: translateY(-1px);
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

        /* ACCOUNT LINKS */

        .account-links {
            margin-top: 23px;
            padding-top: 20px;
            border-top: 1px solid #edf0f5;
        }

        .account-links a {
            color: #315fbd;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .account-links a:hover {
            text-decoration: underline;
        }

        /* RESPONSIVE */

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

        @media (max-width: 600px) {

            .form-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .main-content {
                padding: 17px;
            }

            .profile-card {
                padding: 20px;
            }

            .page-header h1 {
                font-size: 23px;
            }

            .page-title-group {
                align-items: flex-start;
            }

            .page-icon {
                width: 42px;
                height: 42px;
                min-width: 42px;
            }
        }

        @media (max-width: 500px) {

            .sidebar-menu {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .form-actions .btn {
                width: 100%;
                text-align: center;
            }

            .profile-identity {
                padding: 14px;
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

            <a href="profile.php" class="active">
                ◉ My Profile
            </a>

            <a href="change_password.php">
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

            <div class="page-title-group">

                <div class="page-icon">◉</div>

                <div>
                    <h1>My Profile</h1>

                    <p>
                        Manage your administrator account information.
                    </p>

                    <div class="breadcrumb">
                        Admin Panel / My Account / My Profile
                    </div>
                </div>

            </div>

        </div>

        <?php if ($message !== ""): ?>

            <div class="message <?php echo e($messageType); ?>">
                <?php echo e($message); ?>
            </div>

        <?php endif; ?>

        <section class="profile-card">

            <div class="card-heading">

                <h2>Profile Information</h2>

                <p>
                    Keep your administrator details up to date.
                    You can change your name and email address here.
                </p>

            </div>

            <div class="profile-identity">

                <div class="profile-avatar">
                    <?php echo e($avatarInitial); ?>
                </div>

                <div>
                    <h3><?php echo e($formName); ?></h3>
                    <p><?php echo e($formEmail); ?></p>
                </div>

            </div>

            <h3 class="form-section-title">
                Personal Information
            </h3>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($_SESSION["csrf_token"]); ?>"
                >

                <div class="form-grid">

                    <div class="form-group">

                        <label for="name">
                            Full Name
                        </label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="form-control"
                            value="<?php echo e($formName); ?>"
                            maxlength="100"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="email">
                            Email Address
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            value="<?php echo e($formEmail); ?>"
                            maxlength="254"
                            required
                        >

                    </div>

                </div>

                <div class="form-group">

                    <label>
                        Administrator Role
                    </label>

                    <div>
                        <span class="role-label">
                            <?php echo e($admin["role"]); ?>
                        </span>
                    </div>

                    <small class="form-help">
                        Your administrator role can only be changed by an authorized administrator.
                    </small>

                </div>

                <div class="form-actions">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Save Changes
                    </button>

                    <a
                        href="dashboard.php"
                        class="btn btn-secondary"
                    >
                        Back to Dashboard
                    </a>

                </div>

            </form>

            <div class="account-links">

                <a href="change_password.php">
                    🔒 Change Your Password →
                </a>

            </div>

        </section>

    </main>

</div>

</body>
</html>
