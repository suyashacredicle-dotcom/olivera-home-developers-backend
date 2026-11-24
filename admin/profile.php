
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

// Values displayed in the form
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
            background: #f4f6fa;
            font-family: Arial, Helvetica, sans-serif;
            color: #263247;
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

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

        .profile-card {
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

        .profile-avatar {
            width: 66px;
            height: 66px;
            border-radius: 50%;
            background: #e8efff;
            color: #315fbd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 22px;
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

        .form-control {
            display: block;
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d8e0eb;
            border-radius: 7px;
            font-size: 14px;
            color: #263247;
            background: #ffffff;
            outline: none;
            transition: 0.2s;
        }

        .form-control:focus {
            border-color: #4776d0;
            box-shadow: 0 0 0 3px rgba(71,118,208,0.12);
        }

        .form-control:disabled {
            background: #f1f4f8;
            color: #78869a;
            cursor: not-allowed;
        }

        .form-help {
            display: block;
            margin-top: 7px;
            color: #8a96a8;
            font-size: 12px;
        }

        .role-label {
            display: inline-block;
            padding: 7px 12px;
            background: #eaf0ff;
            color: #315fbd;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

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

        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 28px;
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

            .profile-card {
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

            <div>
                <h1>My Profile</h1>

                <p>
                    Manage your administrator account information.
                </p>
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
                    Update your name and email address below.
                </p>

            </div>

            <div class="profile-avatar">
                <?php
                    echo e(
                        strtoupper(
                            substr(trim($formName), 0, 1)
                        )
                    );
                ?>
            </div>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($_SESSION["csrf_token"]); ?>"
                >

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

                <div class="form-group">

                    <label>
                        Admin Role
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
                        Update Profile
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
