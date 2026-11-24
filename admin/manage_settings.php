
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";

requirePermission("manage_settings");

// --------------------------------------------------
// HELPER FUNCTION
// --------------------------------------------------

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
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

$message = "";
$messageType = "";

// --------------------------------------------------
// SAVE SETTINGS
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Verify CSRF token
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

    // Get submitted settings
    $websiteNameInput = $_POST["website_name"] ?? "";
    $websiteEmailInput = $_POST["website_email"] ?? "";
    $websitePhoneInput = $_POST["website_phone"] ?? "";
    $websiteAddressInput = $_POST["website_address"] ?? "";

    if (
        !is_string($websiteNameInput) ||
        !is_string($websiteEmailInput) ||
        !is_string($websitePhoneInput) ||
        !is_string($websiteAddressInput)
    ) {

        $message = "Invalid settings input.";
        $messageType = "error";

    } else {

        $settings = [
            "website_name" => trim($websiteNameInput),
            "website_email" => trim($websiteEmailInput),
            "website_phone" => trim($websitePhoneInput),
            "website_address" => trim($websiteAddressInput)
        ];

        $newLogoPath = null;

        // --------------------------------------------------
        // VALIDATE TEXT SETTINGS
        // --------------------------------------------------

        if ($settings["website_name"] === "") {

            $message = "Website name is required.";

        } elseif (strlen($settings["website_name"]) > 150) {

            $message = "Website name cannot exceed 150 characters.";

        } elseif (strlen($settings["website_email"]) > 254) {

            $message = "Email address is too long.";

        } elseif (
            $settings["website_email"] !== "" &&
            !filter_var(
                $settings["website_email"],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $message = "Please enter a valid email address.";

        } elseif (strlen($settings["website_phone"]) > 30) {

            $message = "Phone number cannot exceed 30 characters.";

        } elseif (strlen($settings["website_address"]) > 2000) {

            $message = "Website address is too long.";
        }

        // --------------------------------------------------
        // LOGO UPLOAD VALIDATION
        // --------------------------------------------------

        if ($message === "") {

            if (
                isset($_FILES["website_logo"]) &&
                $_FILES["website_logo"]["error"] !== UPLOAD_ERR_NO_FILE
            ) {

                $file = $_FILES["website_logo"];

                if (
                    !isset($file["error"]) ||
                    !isset($file["tmp_name"]) ||
                    !isset($file["size"]) ||
                    !isset($file["name"]) ||
                    !is_string($file["tmp_name"])
                ) {

                    $message = "Invalid logo upload.";

                } elseif ($file["error"] !== UPLOAD_ERR_OK) {

                    $message = "Logo upload failed. Please try again.";

                } elseif (!is_uploaded_file($file["tmp_name"])) {

                    $message = "Invalid uploaded file.";

                } elseif ($file["size"] > 2 * 1024 * 1024) {

                    $message = "Logo must not be larger than 2 MB.";

                } else {

                    // Check actual image type
                    $imageInfo = @getimagesize($file["tmp_name"]);

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($file["tmp_name"]);

                    $allowedTypes = [
                        "image/jpeg" => "jpg",
                        "image/png" => "png",
                        "image/webp" => "webp"
                    ];

                    if (
                        !$imageInfo ||
                        !isset($allowedTypes[$mimeType]) ||
                        $imageInfo["mime"] !== $mimeType
                    ) {

                        $message =
                            "Only JPG, PNG and WebP images are allowed.";

                    } else {

                        // --------------------------------------------------
                        // SAVE LOGO FILE
                        // --------------------------------------------------

                        $uploadDirectory = __DIR__ . "/../uploads/";

                        if (!is_dir($uploadDirectory)) {

                            $message =
                                "Upload directory does not exist.";

                        } elseif (!is_writable($uploadDirectory)) {

                            $message =
                                "Upload directory is not writable.";

                        } else {

                            $fileName = bin2hex(random_bytes(16))
                                . "." . $allowedTypes[$mimeType];

                            $destination = $uploadDirectory . $fileName;

                            if (
                                move_uploaded_file(
                                    $file["tmp_name"],
                                    $destination
                                )
                            ) {

                                $newLogoPath = "uploads/" . $fileName;

                            } else {

                                $message =
                                    "Unable to save the logo. Please try again.";
                            }
                        }
                    }
                }
            }
        }

        // --------------------------------------------------
        // SAVE SETTINGS TO DATABASE
        // --------------------------------------------------

        if ($message === "") {

            try {

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO settings (
                        setting_key,
                        setting_value
                    )
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value)
                ");

                foreach ($settings as $key => $value) {

                    $stmt->execute([
                        $key,
                        $value
                    ]);
                }

                // Save logo path if a new logo was uploaded
                if ($newLogoPath !== null) {

                    $stmt->execute([
                        "website_logo",
                        $newLogoPath
                    ]);
                }

                $pdo->commit();

                // Rotate CSRF token after successful save
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                $message = "Website settings saved successfully!";
                $messageType = "success";

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());

                // Remove newly uploaded file if database save fails
                if ($newLogoPath !== null) {

                    $newFile = __DIR__ . "/../" . $newLogoPath;

                    if (is_file($newFile)) {
                        unlink($newFile);
                    }
                }

                $message = "Something went wrong. Please try again.";
                $messageType = "error";
            }
        }

        if ($message !== "" && $messageType === "") {
            $messageType = "error";
        }
    }
}

// --------------------------------------------------
// LOAD SAVED SETTINGS
// --------------------------------------------------

$stmt = $pdo->query("
    SELECT setting_key, setting_value
    FROM settings
");

$savedSettings = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

    $savedSettings[$row["setting_key"]] = $row["setting_value"];
}

$websiteName = $savedSettings["website_name"] ?? "";
$websiteEmail = $savedSettings["website_email"] ?? "";
$websitePhone = $savedSettings["website_phone"] ?? "";
$websiteAddress = $savedSettings["website_address"] ?? "";
$websiteLogo = $savedSettings["website_logo"] ?? "";

// Validate stored logo path before displaying it
$validLogo = "";

if (
    is_string($websiteLogo) &&
    preg_match(
        '/^uploads\/[a-f0-9]{32}\.(jpg|png|webp)$/i',
        $websiteLogo
    ) &&
    is_file(__DIR__ . "/../" . $websiteLogo)
) {
    $validLogo = $websiteLogo;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Website Settings | Admin Panel</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>

        .settings-page {
            max-width: 1250px;
            margin: 0 auto;
            padding: 10px 5px 35px;
        }

        .settings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 25px;
        }

        .settings-header h1 {
            color: #172554;
            font-size: 28px;
            margin: 0 0 8px;
        }

        .settings-header p {
            color: #64748b;
            font-size: 14px;
            margin: 0;
        }

        .settings-back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 17px;
            background: #1e40af;
            color: #ffffff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        .settings-back-btn:hover {
            background: #1e3a8a;
            color: #ffffff;
        }

        .settings-alert {
            padding: 14px 18px;
            margin-bottom: 22px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
        }

        .settings-alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .settings-alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .settings-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .settings-panel-header {
            padding: 22px 25px;
            border-bottom: 1px solid #e2e8f0;
        }

        .settings-panel-header h2 {
            color: #172554;
            font-size: 19px;
            margin: 0 0 6px;
        }

        .settings-panel-header p {
            color: #64748b;
            font-size: 13px;
            margin: 0;
        }

        .settings-panel-body {
            padding: 25px;
        }

        .settings-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .settings-form-group {
            margin-bottom: 20px;
        }

        .settings-form-group.full-width {
            grid-column: 1 / -1;
        }

        .settings-form-group label {
            display: block;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .settings-form-group input,
        .settings-form-group textarea {
            display: block;
            width: 100%;
            box-sizing: border-box;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            color: #334155;
            background: #ffffff;
            outline: none;
            transition: 0.2s;
        }

        .settings-form-group textarea {
            min-height: 115px;
            resize: vertical;
            line-height: 1.6;
        }

        .settings-form-group input:focus,
        .settings-form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }

        .settings-help {
            display: block;
            color: #94a3b8;
            font-size: 12px;
            margin-top: 7px;
        }

        .settings-logo-box {
            display: flex;
            align-items: center;
            gap: 25px;
            flex-wrap: wrap;
            padding: 20px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            margin-bottom: 22px;
        }

        .settings-logo-preview {
            width: 200px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            box-sizing: border-box;
        }

        .settings-logo-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .settings-logo-empty {
            color: #94a3b8;
            font-size: 13px;
            text-align: center;
        }

        .settings-logo-info h3 {
            color: #172554;
            font-size: 15px;
            margin: 0 0 7px;
        }

        .settings-logo-info p {
            color: #64748b;
            font-size: 13px;
            margin: 0;
            line-height: 1.6;
        }

        .settings-file-input {
            padding: 12px !important;
            background: #f8fafc !important;
        }

        .settings-save-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 13px 23px;
            background: #1e40af;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }

        .settings-save-btn:hover {
            background: #1e3a8a;
        }

        @media (max-width: 650px) {

            .settings-header h1 {
                font-size: 23px;
            }

            .settings-form-grid {
                grid-template-columns: 1fr;
            }

            .settings-form-group.full-width {
                grid-column: auto;
            }

            .settings-panel-body {
                padding: 18px;
            }

            .settings-logo-box {
                gap: 15px;
            }

        }

    </style>

</head>

<body>

<div class="dashboard-container">

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <h2>Admin Panel</h2>

        <a href="dashboard.php">Dashboard</a>

        <?php
        require __DIR__ . "/includes/gallery_sidebar_link.php";
        ?>

        <a href="manage_admins.php">Manage Admins</a>

        <a href="manage_users.php">Manage Users</a>

        <a href="manage_customers.php">Manage Customers</a>

        <a href="manage_content.php">Manage Content</a>

        <a href="manage_settings.php">Website Settings</a>

        <a href="activity_logs.php">Activity Logs</a>

        <a href="profile.php">My Profile</a>

        <a href="change_password.php">Change Password</a>

        <form action="logout.php" method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo e($_SESSION["csrf_token"]); ?>"
            >

            <button type="submit">Logout</button>

        </form>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="main-content">

        <div class="settings-page">

            <!-- PAGE HEADER -->

            <div class="settings-header">

                <div>

                    <h1>Website Settings</h1>

                    <p>
                        Welcome,
                        <?php echo e($_SESSION["admin_name"] ?? "Admin"); ?>.
                        Manage your website's general information and logo.
                    </p>

                </div>

                <a href="dashboard.php" class="settings-back-btn">
                    Back to Dashboard
                </a>

            </div>

            <!-- SUCCESS / ERROR MESSAGE -->

            <?php if ($message !== ""): ?>

                <div class="settings-alert <?php
                    echo $messageType === "success"
                        ? "success"
                        : "error";
                ?>">

                    <?php echo e($message); ?>

                </div>

            <?php endif; ?>

            <!-- SETTINGS FORM -->

            <form
                method="POST"
                enctype="multipart/form-data"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($_SESSION["csrf_token"]); ?>"
                >

                <!-- GENERAL INFORMATION -->

                <section class="settings-panel">

                    <div class="settings-panel-header">

                        <h2>General Website Information</h2>

                        <p>
                            Update your website's basic contact information.
                        </p>

                    </div>

                    <div class="settings-panel-body">

                        <div class="settings-form-grid">

                            <div class="settings-form-group">

                                <label for="website_name">
                                    Website Name
                                </label>

                                <input
                                    type="text"
                                    id="website_name"
                                    name="website_name"
                                    value="<?php echo e($websiteName); ?>"
                                    maxlength="150"
                                    placeholder="Enter website name"
                                    required
                                >

                            </div>

                            <div class="settings-form-group">

                                <label for="website_email">
                                    Website Email
                                </label>

                                <input
                                    type="email"
                                    id="website_email"
                                    name="website_email"
                                    value="<?php echo e($websiteEmail); ?>"
                                    maxlength="254"
                                    placeholder="Enter website email"
                                >

                            </div>

                            <div class="settings-form-group">

                                <label for="website_phone">
                                    Contact Number
                                </label>

                                <input
                                    type="text"
                                    id="website_phone"
                                    name="website_phone"
                                    value="<?php echo e($websitePhone); ?>"
                                    maxlength="30"
                                    placeholder="Enter contact number"
                                >

                            </div>

                            <div class="settings-form-group full-width">

                                <label for="website_address">
                                    Website Address
                                </label>

                                <textarea
                                    id="website_address"
                                    name="website_address"
                                    rows="4"
                                    maxlength="2000"
                                    placeholder="Enter your website address"
                                ><?php echo e($websiteAddress); ?></textarea>

                            </div>

                        </div>

                    </div>

                </section>

                <!-- WEBSITE LOGO -->

                <section class="settings-panel">

                    <div class="settings-panel-header">

                        <h2>Website Logo</h2>

                        <p>
                            Manage the logo displayed on your website.
                        </p>

                    </div>

                    <div class="settings-panel-body">

                        <div class="settings-logo-box">

                            <div class="settings-logo-preview">

                                <?php if ($validLogo !== ""): ?>

                                    <img
                                        src="../<?php echo e($validLogo); ?>"
                                        alt="Current Website Logo"
                                    >

                                <?php else: ?>

                                    <div class="settings-logo-empty">
                                        No logo uploaded yet
                                    </div>

                                <?php endif; ?>

                            </div>

                            <div class="settings-logo-info">

                                <h3>Current Website Logo</h3>

                                <p>
                                    <?php if ($validLogo !== ""): ?>

                                        Your website logo is currently saved.

                                    <?php else: ?>

                                        Upload a logo to display your website branding.

                                    <?php endif; ?>

                                    <br>
                                    Supported formats: JPG, PNG and WebP.
                                    <br>
                                    Maximum file size: 2 MB.
                                </p>

                            </div>

                        </div>

                        <div class="settings-form-group">

                            <label for="website_logo">
                                Upload New Website Logo
                            </label>

                            <input
                                type="file"
                                id="website_logo"
                                name="website_logo"
                                class="settings-file-input"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            >

                            <span class="settings-help">
                                Leave this field empty to keep your current logo.
                            </span>

                        </div>

                    </div>

                </section>

                <!-- SAVE BUTTON -->

                <button
                    type="submit"
                    class="settings-save-btn"
                >
                    Save Website Settings
                </button>

            </form>

        </div>

    </main>

</div>

</body>

</html>
