
<?php

// --------------------------------------------------
// SUPER ADMIN GALLERY MANAGEMENT
// --------------------------------------------------

require_once "../includes/permissions.php";
require_once "../config/database.php";

requirePermission("manage_admins");

// --------------------------------------------------
// ESCAPE OUTPUT
// --------------------------------------------------

function galleryEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
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

$csrfToken = $_SESSION["csrf_token"];

// --------------------------------------------------
// FLASH MESSAGES
// --------------------------------------------------

$message = "";
$messageType = "";

if (isset($_SESSION["gallery_message"])) {

    $message = $_SESSION["gallery_message"];
    $messageType = $_SESSION["gallery_message_type"] ?? "success";

    unset($_SESSION["gallery_message"]);
    unset($_SESSION["gallery_message_type"]);
}

// --------------------------------------------------
// SEARCH GALLERY PHOTOS
// --------------------------------------------------

$search = $_GET["search"] ?? "";

if (!is_string($search)) {
    $search = "";
}

$search = trim($search);

try {

    if ($search !== "") {

        $stmt = $pdo->prepare("
            SELECT *
            FROM gallery_photos
            WHERE title LIKE ?
               OR original_filename LIKE ?
               OR image_filename LIKE ?
            ORDER BY id DESC
        ");

        $searchTerm = "%" . $search . "%";

        $stmt->execute([
            $searchTerm,
            $searchTerm,
            $searchTerm
        ]);

    } else {

        $stmt = $pdo->query("
            SELECT *
            FROM gallery_photos
            ORDER BY id DESC
        ");
    }

    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPhotos = count($photos);
    $publicPhotos = 0;
    $employeePhotos = 0;

    foreach ($photos as $photo) {

        if ($photo["visibility"] === "public") {
            $publicPhotos++;
        }

        if ($photo["visibility"] === "employees") {
            $employeePhotos++;
        }
    }

} catch (PDOException $e) {

    error_log("Gallery loading failed: " . $e->getMessage());

    http_response_code(500);
    exit("Unable to load gallery records.");
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Gallery | Admin Panel</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>

        .gallery-page {
            max-width: 1450px;
            margin: 0 auto;
            padding: 10px 5px 35px;
        }

        .gallery-header {
            margin-bottom: 25px;
        }

        .gallery-header h1 {
            color: #172554;
            font-size: 28px;
            margin: 0 0 8px;
        }

        .gallery-header p {
            color: #64748b;
            font-size: 14px;
            margin: 0;
        }

        .gallery-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .gallery-summary-card {
            background: #ffffff;
            padding: 22px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
        }

        .gallery-summary-label {
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .gallery-summary-number {
            color: #172554;
            font-size: 30px;
            font-weight: 700;
        }

        .gallery-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .gallery-panel-header {
            padding: 21px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .gallery-panel-header h2 {
            color: #172554;
            font-size: 19px;
            margin: 0 0 6px;
        }

        .gallery-panel-header p {
            color: #64748b;
            font-size: 13px;
            margin: 0;
        }

        .gallery-panel-body {
            padding: 24px;
        }

        .gallery-alert {
            padding: 14px 18px;
            margin-bottom: 22px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
        }

        .gallery-alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .gallery-alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .gallery-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .gallery-form-group {
            margin-bottom: 19px;
        }

        .gallery-form-group.full-width {
            grid-column: 1 / -1;
        }

        .gallery-form-group label {
            display: block;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .gallery-form-group input,
        .gallery-form-group select,
        .gallery-form-group textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            color: #334155;
            background: #ffffff;
            outline: none;
        }

        .gallery-form-group textarea {
            min-height: 110px;
            resize: vertical;
            line-height: 1.6;
        }

        .gallery-form-group input:focus,
        .gallery-form-group select:focus,
        .gallery-form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }

        .gallery-form-help {
            display: block;
            color: #64748b;
            font-size: 12px;
            margin-top: 7px;
            line-height: 1.5;
        }

        .gallery-primary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 21px;
            background: #1e40af;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .gallery-primary-btn:hover {
            background: #1e3a8a;
        }

        .gallery-search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .gallery-search-form input {
            flex: 1;
            min-width: 200px;
            padding: 11px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }

        .gallery-search-btn {
            padding: 11px 18px;
            background: #1e40af;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .gallery-clear-btn {
            display: inline-flex;
            align-items: center;
            padding: 11px 16px;
            background: #f1f5f9;
            color: #475569;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        /* TABLE */

        .gallery-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .gallery-table {
            width: 100%;
            min-width: 750px;
            border-collapse: collapse;
        }

        .gallery-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            text-align: left;
            padding: 16px 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .gallery-table td {
            padding: 17px 18px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 13px;
            vertical-align: middle;
        }

        .gallery-table tbody tr:hover {
            background: #f8fafc;
        }

        .gallery-serial {
            color: #64748b;
            font-weight: 700;
            width: 85px;
        }

        .gallery-photo-name {
            color: #172554;
            font-weight: 700;
        }

        .gallery-visibility {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .gallery-visibility.public {
            background: #dcfce7;
            color: #166534;
        }

        .gallery-visibility.employees {
            background: #dbeafe;
            color: #1e40af;
        }

        .gallery-date {
            color: #64748b;
            font-size: 12px;
            white-space: nowrap;
        }

        .gallery-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .gallery-view-btn {
            width: 35px;
            height: 35px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 7px;
            text-decoration: none;
            transition: 0.2s;
        }

        .gallery-view-btn:hover {
            background: #bae6fd;
        }

        .gallery-view-btn svg {
            width: 19px;
            height: 19px;
        }

        .gallery-edit-btn,
        .gallery-delete-btn {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 7px;
            text-decoration: none;
            border: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .gallery-edit-btn {
            background: #dbeafe;
            color: #1e40af;
        }

        .gallery-edit-btn:hover {
            background: #bfdbfe;
        }

        .gallery-delete-btn {
            background: #fee2e2;
            color: #991b1b;
        }

        .gallery-delete-btn:hover {
            background: #fecaca;
        }

        .gallery-delete-form {
            display: inline;
            margin: 0;
        }

        .gallery-empty {
            text-align: center;
            padding: 45px 20px !important;
            color: #64748b !important;
        }

        .gallery-empty strong {
            display: block;
            color: #334155;
            font-size: 16px;
            margin-bottom: 7px;
        }

        .gallery-notice {
            padding: 13px 16px;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        @media (max-width: 650px) {

            .gallery-header h1 {
                font-size: 23px;
            }

            .gallery-form-grid {
                grid-template-columns: 1fr;
            }

            .gallery-form-group.full-width {
                grid-column: auto;
            }

            .gallery-panel-body {
                padding: 18px;
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
        <a href="manage_admins.php">Manage Admins</a>
        <a href="manage_users.php">Manage Users</a>
        <a href="manage_customers.php">Manage Customers</a>
        <a href="manage_content.php">Manage Content</a>

        <?php
        require __DIR__ . "/includes/gallery_sidebar_link.php";
        ?>

        <a href="manage_settings.php">Website Settings</a>
        <a href="activity_logs.php">Activity Logs</a>
        <a href="profile.php">My Profile</a>
        <a href="change_password.php">Change Password</a>

        <form action="logout.php" method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo galleryEscape($csrfToken); ?>"
            >

            <button type="submit">Logout</button>

        </form>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="main-content">

        <div class="gallery-page">

            <div class="gallery-header">

                <h1>Gallery Management</h1>

                <p>
                    Upload photos and control who can view them.
                </p>

            </div>

            <!-- FLASH MESSAGE -->

            <?php if ($message !== ""): ?>

                <div class="gallery-alert <?php
                    echo $messageType === "error" ? "error" : "success";
                ?>">

                    <?php echo galleryEscape($message); ?>

                </div>

            <?php endif; ?>

            <!-- SUMMARY -->

            <div class="gallery-summary">

                <div class="gallery-summary-card">
                    <div class="gallery-summary-label">Matching Photos</div>
                    <div class="gallery-summary-number">
                        <?php echo $totalPhotos; ?>
                    </div>
                </div>

                <div class="gallery-summary-card">
                    <div class="gallery-summary-label">Public Website Photos</div>
                    <div class="gallery-summary-number">
                        <?php echo $publicPhotos; ?>
                    </div>
                </div>

                <div class="gallery-summary-card">
                    <div class="gallery-summary-label">Employees Only Photos</div>
                    <div class="gallery-summary-number">
                        <?php echo $employeePhotos; ?>
                    </div>
                </div>

            </div>

            <!-- UPLOAD PHOTO -->

            <section class="gallery-panel">

                <div class="gallery-panel-header">

                    <h2>Upload New Photo</h2>

                    <p>
                        Select a photo and choose who should be able to view it.
                    </p>

                </div>

                <div class="gallery-panel-body">

                    <form
                        action="actions/upload_gallery.php"
                        method="POST"
                        enctype="multipart/form-data"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo galleryEscape($csrfToken); ?>"
                        >

                        <div class="gallery-form-grid">

                            <div class="gallery-form-group full-width">

                                <label for="photo">Select Photo</label>

                                <input
                                    type="file"
                                    id="photo"
                                    name="photo"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    required
                                >

                                <span class="gallery-form-help">
                                    Allowed formats: JPG, JPEG, PNG and WEBP.
                                    Maximum file size: 5 MB.
                                </span>

                            </div>

                            <div class="gallery-form-group">

                                <label for="title">Photo Name (Optional)</label>

                                <input
                                    type="text"
                                    id="title"
                                    name="title"
                                    maxlength="150"
                                    placeholder="Enter photo name"
                                >

                                <span class="gallery-form-help">
                                    If left blank, the original desktop filename will be used automatically.
                                </span>

                            </div>

                            <div class="gallery-form-group">

                                <label for="visibility">
                                    Who Can View This Photo?
                                </label>

                                <select id="visibility" name="visibility" required>

                                    <option value="employees" selected>
                                        Employees Only
                                    </option>

                                    <option value="public">
                                        Public Website
                                    </option>

                                </select>

                                <span class="gallery-form-help">
                                    Employees Only is selected by default.
                                </span>

                            </div>

                            <div class="gallery-form-group full-width">

                                <label for="description">
                                    Photo Description (Optional)
                                </label>

                                <textarea
                                    id="description"
                                    name="description"
                                    maxlength="5000"
                                    placeholder="Enter a short description..."
                                ></textarea>

                            </div>

                        </div>

                        <button type="submit" class="gallery-primary-btn">
                            Upload Photo
                        </button>

                    </form>

                </div>

            </section>

            <!-- UPLOADED PHOTOS TABLE -->

            <section class="gallery-panel">

                <div class="gallery-panel-header">

                    <h2>Uploaded Photos</h2>

                    <p>
                        View photo names and manage your uploaded photos.
                    </p>

                </div>

                <div class="gallery-panel-body">

                    <div class="gallery-notice">
                        Photos are not displayed directly in this table.
                        Use the eye icon to open a photo separately.
                        Only Super Admin can use these previews.
                    </div>

                    <!-- SEARCH -->

                    <form method="GET" class="gallery-search-form">

                        <input
                            type="text"
                            name="search"
                            placeholder="Search by photo name..."
                            value="<?php echo galleryEscape($search); ?>"
                        >

                        <button type="submit" class="gallery-search-btn">
                            Search
                        </button>

                        <a href="manage_gallery.php" class="gallery-clear-btn">
                            Clear Search
                        </a>

                    </form>

                </div>

                <!-- TABLE -->

                <div class="gallery-table-wrapper">

                    <table class="gallery-table">

                        <thead>

                            <tr>
                                <th>S. No.</th>
                                <th>Photo Name</th>
                                <th>Visibility</th>
                                <th>Uploaded At</th>
                                <th>View</th>
                                <th>Actions</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if ($totalPhotos > 0): ?>

                                <?php
                                $serialNumber = 1;
                                ?>

                                <?php foreach ($photos as $photo): ?>

                                    <?php

                                    $displayPhotoName = trim(
                                        (string) ($photo["title"] ?? "")
                                    );

                                    if ($displayPhotoName === "") {
                                        $displayPhotoName = trim(
                                            (string) ($photo["original_filename"] ?? "")
                                        );
                                    }

                                    if ($displayPhotoName === "") {
                                        $displayPhotoName = "Untitled Photo";
                                    }

                                    ?>

                                    <tr>

                                        <!-- SERIAL NUMBER -->

                                        <td class="gallery-serial">
                                            <?php echo $serialNumber++; ?>
                                        </td>

                                        <!-- PHOTO NAME -->

                                        <td class="gallery-photo-name">
                                            <?php echo galleryEscape($displayPhotoName); ?>
                                        </td>

                                        <!-- VISIBILITY -->

                                        <td>

                                            <span class="gallery-visibility <?php
                                                echo $photo["visibility"] === "public"
                                                    ? "public"
                                                    : "employees";
                                            ?>">

                                                <?php
                                                echo $photo["visibility"] === "public"
                                                    ? "Public Website"
                                                    : "Employees Only";
                                                ?>

                                            </span>

                                        </td>

                                        <!-- UPLOAD DATE -->

                                        <td class="gallery-date">
                                            <?php
                                            echo galleryEscape($photo["created_at"]);
                                            ?>
                                        </td>

                                        <!-- VIEW PHOTO -->

                                        <td>

                                            <a
                                                href="gallery_preview.php?id=<?php echo (int) $photo["id"]; ?>"
                                                class="gallery-view-btn"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="View Photo"
                                                aria-label="View photo"
                                            >

                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    aria-hidden="true"
                                                >

                                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/>

                                                    <circle cx="12" cy="12" r="3"/>

                                                </svg>

                                            </a>

                                        </td>

                                        <!-- ACTIONS -->

                                        <td>

                                            <div class="gallery-actions">

                                                <a
                                                    href="actions/edit_gallery.php?id=<?php echo (int) $photo["id"]; ?>"
                                                    class="gallery-edit-btn"
                                                >
                                                    Edit
                                                </a>

                                                <form
                                                    action="actions/delete_gallery.php"
                                                    method="POST"
                                                    class="gallery-delete-form"
                                                    onsubmit="return confirm('Are you sure you want to permanently delete this photo? This action cannot be undone.');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?php echo galleryEscape($csrfToken); ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?php echo (int) $photo["id"]; ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="gallery-delete-btn"
                                                    >
                                                        Delete
                                                    </button>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="6" class="gallery-empty">

                                        <strong>No Photos Found</strong>

                                        <?php if ($search !== ""): ?>

                                            No gallery photos match your search.

                                        <?php else: ?>

                                            You have not uploaded any gallery photos yet.

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        </div>

    </main>

</div>

</body>
</html>
