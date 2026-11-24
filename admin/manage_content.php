
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";
require_once "../includes/activity_logger.php";

requirePermission("manage_content");

// Escape output safely
function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

// Create CSRF token if it does not exist
if (
    empty($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"])
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "";

// Display messages from previous actions
if (isset($_SESSION["content_message"])) {

    $message = $_SESSION["content_message"];

    $messageType =
        $_SESSION["content_message_type"] ?? "success";

    unset($_SESSION["content_message"]);
    unset($_SESSION["content_message_type"]);
}

// Create new content
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["create_content"])
) {

    if (
        !isset($_POST["csrf_token"]) ||
        !is_string($_POST["csrf_token"]) ||
        !hash_equals(
            $_SESSION["csrf_token"],
            $_POST["csrf_token"]
        )
    ) {
        http_response_code(403);
        die("Invalid CSRF token.");
    }

    $title = trim($_POST["title"] ?? "");
    $body = trim($_POST["body"] ?? "");
    $status = $_POST["status"] ?? "draft";

    if ($title === "" || $body === "") {

        $_SESSION["content_message"] =
            "Please fill in all fields.";

        $_SESSION["content_message_type"] = "error";

    } elseif (
        !in_array($status, ["draft", "published"], true)
    ) {

        $_SESSION["content_message"] =
            "Invalid content status.";

        $_SESSION["content_message_type"] = "error";

    } else {

        try {

            $stmt = $pdo->prepare(
                "INSERT INTO content
                    (title, body, status, created_by)
                 VALUES (?, ?, ?, ?)"
            );

            $stmt->execute([
                $title,
                $body,
                $status,
                $_SESSION["admin_id"]
            ]);

            logActivity(
                $pdo,
                "CONTENT_CREATED",
                "Created content: " . $title
            );

            $_SESSION["content_message"] =
                "Content created successfully!";

            $_SESSION["content_message_type"] = "success";

        } catch (PDOException $e) {

            error_log(
                "Content creation failed: " . $e->getMessage()
            );

            $_SESSION["content_message"] =
                "Unable to create content. Please try again.";

            $_SESSION["content_message_type"] = "error";
        }
    }

    header("Location: manage_content.php");
    exit;
}

// Search content
$search = trim($_GET["search"] ?? "");

if ($search !== "") {

    $stmt = $pdo->prepare(
        "SELECT * FROM content
         WHERE title LIKE ?
         ORDER BY id DESC"
    );

    $stmt->execute(["%" . $search . "%"]);

} else {

    $stmt = $pdo->query(
        "SELECT * FROM content ORDER BY id DESC"
    );
}

$contents = $stmt->fetchAll();

$totalContents = count($contents);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Content | Admin Panel</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>

        .content-page {
            max-width: 1450px;
            margin: 0 auto;
            padding: 10px 5px 35px;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 25px;
        }

        .content-header h1 {
            color: #172554;
            font-size: 28px;
            margin: 0 0 8px;
        }

        .content-header p {
            color: #64748b;
            font-size: 14px;
            margin: 0;
        }

        .content-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .content-summary-card {
            background: #ffffff;
            padding: 22px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
        }

        .content-summary-label {
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .content-summary-number {
            color: #172554;
            font-size: 30px;
            font-weight: 700;
        }

        .content-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .content-panel-header {
            padding: 21px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .content-panel-header h2 {
            color: #172554;
            font-size: 19px;
            margin: 0 0 6px;
        }

        .content-panel-header p {
            color: #64748b;
            font-size: 13px;
            margin: 0;
        }

        .content-panel-body {
            padding: 24px;
        }

        .content-alert {
            padding: 14px 18px;
            margin-bottom: 22px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
        }

        .content-alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .content-alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .content-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .content-form-group {
            margin-bottom: 19px;
        }

        .content-form-group.full-width {
            grid-column: 1 / -1;
        }

        .content-form-group label {
            display: block;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .content-form-group input,
        .content-form-group select,
        .content-form-group textarea {
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

        .content-form-group textarea {
            min-height: 150px;
            resize: vertical;
            line-height: 1.6;
        }

        .content-form-group input:focus,
        .content-form-group select:focus,
        .content-form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }

        .content-primary-btn {
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
            transition: 0.2s;
        }

        .content-primary-btn:hover {
            background: #1e3a8a;
        }

        .content-search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .content-search-form input {
            flex: 1;
            min-width: 200px;
            padding: 11px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }

        .content-search-form input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }

        .content-search-btn {
            padding: 11px 18px;
            background: #1e40af;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .content-clear-btn {
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

        .content-clear-btn:hover {
            background: #e2e8f0;
        }

        .content-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .content-table {
            width: 100%;
            min-width: 750px;
            border-collapse: collapse;
        }

        .content-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            text-align: left;
            padding: 15px 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .content-table td {
            padding: 16px 18px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 13px;
            vertical-align: middle;
        }

        .content-table tbody tr:hover {
            background: #f8fafc;
        }

        .content-table tbody tr:last-child td {
            border-bottom: none;
        }

        .content-id {
            color: #64748b;
            font-weight: 700;
        }

        .content-title {
            color: #172554;
            font-weight: 600;
        }

        .content-status {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .content-status.published {
            background: #dcfce7;
            color: #166534;
        }

        .content-status.draft {
            background: #fef3c7;
            color: #92400e;
        }

        .content-date {
            color: #64748b;
            font-size: 12px;
            white-space: nowrap;
        }

        .content-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .content-edit-btn,
        .content-delete-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .content-edit-btn {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .content-edit-btn:hover {
            background: #dbeafe;
        }

        .content-delete-btn {
            background: #fee2e2;
            color: #b91c1c;
            border: none;
        }

        .content-delete-btn:hover {
            background: #fecaca;
        }

        .content-empty {
            text-align: center;
            padding: 45px 20px !important;
            color: #64748b !important;
        }

        .content-empty strong {
            display: block;
            color: #334155;
            font-size: 16px;
            margin-bottom: 7px;
        }

        @media (max-width: 650px) {

            .content-header h1 {
                font-size: 23px;
            }

            .content-form-grid {
                grid-template-columns: 1fr;
            }

            .content-form-group.full-width {
                grid-column: auto;
            }

            .content-panel-body {
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

        <div class="content-page">

            <div class="content-header">

                <div>

                    <h1>Manage Content</h1>

                    <p>
                        Create, manage, edit and publish your website content.
                    </p>

                </div>

            </div>

            <?php if ($message !== ""): ?>

                <div class="content-alert <?php
                    echo $messageType === "success"
                        ? "success"
                        : "error";
                ?>">

                    <?php echo e($message); ?>

                </div>

            <?php endif; ?>

            <div class="content-summary">

                <div class="content-summary-card">

                    <div class="content-summary-label">
                        Matching Content Records
                    </div>

                    <div class="content-summary-number">
                        <?php echo $totalContents; ?>
                    </div>

                </div>

            </div>

            <section class="content-panel">

                <div class="content-panel-header">

                    <h2>Create New Content</h2>

                    <p>
                        Add a new content entry to your website.
                    </p>

                </div>

                <div class="content-panel-body">

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo e($_SESSION["csrf_token"]); ?>"
                        >

                        <div class="content-form-grid">

                            <div class="content-form-group full-width">

                                <label for="title">
                                    Content Title
                                </label>

                                <input
                                    type="text"
                                    id="title"
                                    name="title"
                                    placeholder="Enter content title"
                                    maxlength="255"
                                    required
                                >

                            </div>

                            <div class="content-form-group full-width">

                                <label for="body">
                                    Content Body
                                </label>

                                <textarea
                                    id="body"
                                    name="body"
                                    placeholder="Write your website content here..."
                                    required
                                ></textarea>

                            </div>

                            <div class="content-form-group">

                                <label for="status">
                                    Publication Status
                                </label>

                                <select id="status" name="status">

                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>

                                </select>

                            </div>

                        </div>

                        <button
                            type="submit"
                            name="create_content"
                            class="content-primary-btn"
                        >
                            + Add Content
                        </button>

                    </form>

                </div>

            </section>

            <section class="content-panel">

                <div class="content-panel-header">

                    <h2>Existing Content</h2>

                    <p>
                        View and manage your existing website content.
                    </p>

                </div>

                <div class="content-panel-body">

                    <form method="GET" class="content-search-form">

                        <input
                            type="text"
                            name="search"
                            placeholder="Search content by title..."
                            value="<?php echo e($search); ?>"
                        >

                        <button
                            type="submit"
                            class="content-search-btn"
                        >
                            Search
                        </button>

                        <a
                            href="manage_content.php"
                            class="content-clear-btn"
                        >
                            Clear Search
                        </a>

                    </form>

                </div>

                <div class="content-table-wrapper">

                    <table class="content-table">

                        <thead>

                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if ($totalContents > 0): ?>

                                <?php foreach ($contents as $content): ?>

                                    <tr>

                                        <td class="content-id">
                                            #<?php echo (int) $content["id"]; ?>
                                        </td>

                                        <td class="content-title">
                                            <?php echo e($content["title"]); ?>
                                        </td>

                                        <td>

                                            <span class="content-status <?php
                                                echo $content["status"] === "published"
                                                    ? "published"
                                                    : "draft";
                                            ?>">

                                                <?php echo e($content["status"]); ?>

                                            </span>

                                        </td>

                                        <td class="content-date">
                                            <?php echo e($content["created_at"]); ?>
                                        </td>

                                        <td>

                                            <div class="content-actions">

                                                <a
                                                    href="actions/edit_content.php?id=<?php
                                                        echo (int) $content["id"];
                                                    ?>"
                                                    class="content-edit-btn"
                                                >
                                                    Edit
                                                </a>

                                                <form
                                                    action="actions/delete_content.php"
                                                    method="POST"
                                                    onsubmit="return confirm('Are you sure you want to permanently delete this content? This action cannot be undone.');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?php
                                                            echo e($_SESSION["csrf_token"]);
                                                        ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?php
                                                            echo (int) $content["id"];
                                                        ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="content-delete-btn"
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

                                    <td colspan="5" class="content-empty">

                                        <strong>No Content Found</strong>

                                        <?php if ($search !== ""): ?>

                                            No content matches your search.

                                        <?php else: ?>

                                            You have not created any content yet.

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
