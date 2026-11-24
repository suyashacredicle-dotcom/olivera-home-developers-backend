
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";

requirePermission("manage_users");

// --------------------------------------------------
// CSRF TOKEN
// --------------------------------------------------

if (
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"]) ||
    $_SESSION["csrf_token"] === ""
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

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
// FLASH MESSAGE
// --------------------------------------------------

$message = "";
$messageType = "success";

if (isset($_SESSION["customer_admin_message"])) {

    $message = $_SESSION["customer_admin_message"];
    $messageType = $_SESSION["customer_admin_message_type"] ?? "success";

    unset(
        $_SESSION["customer_admin_message"],
        $_SESSION["customer_admin_message_type"]
    );
}

// --------------------------------------------------
// ACTIVATE / DEACTIVATE CUSTOMER
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_SESSION["csrf_token"]) ||
        !is_string($_SESSION["csrf_token"]) ||
        !isset($_POST["csrf_token"]) ||
        !is_string($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {

        $_SESSION["customer_admin_message"] =
            "Invalid request. Please refresh the page and try again.";

        $_SESSION["customer_admin_message_type"] = "error";

    } else {

        $customerId = $_POST["customer_id"] ?? null;
        $action = $_POST["action"] ?? null;

        if (
            !is_string($customerId) ||
            !ctype_digit($customerId) ||
            (int) $customerId <= 0
        ) {

            $_SESSION["customer_admin_message"] =
                "Invalid customer ID.";

            $_SESSION["customer_admin_message_type"] = "error";

        } elseif (
            !is_string($action) ||
            !in_array($action, ["activate", "deactivate"], true)
        ) {

            $_SESSION["customer_admin_message"] =
                "Invalid customer action.";

            $_SESSION["customer_admin_message_type"] = "error";

        } else {

            try {

                $pdo->beginTransaction();

                $findCustomer = $pdo->prepare("
                    SELECT id, name, status
                    FROM customers
                    WHERE id = :id
                    LIMIT 1
                    FOR UPDATE
                ");

                $findCustomer->execute([
                    ":id" => (int) $customerId
                ]);

                $customer = $findCustomer->fetch();

                if (!$customer) {
                    throw new RuntimeException("Customer not found.");
                }

                $newStatus = ($action === "activate")
                    ? "active"
                    : "inactive";

                $updateCustomer = $pdo->prepare("
                    UPDATE customers
                    SET status = :status
                    WHERE id = :id
                ");

                $updateCustomer->execute([
                    ":status" => $newStatus,
                    ":id" => (int) $customerId
                ]);

                $pdo->commit();

                $_SESSION["customer_admin_message"] =
                    "Customer account " . $newStatus . " successfully.";

                $_SESSION["customer_admin_message_type"] = "success";

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($exception->getMessage());

                $_SESSION["customer_admin_message"] =
                    "Unable to update customer account. Please try again.";

                $_SESSION["customer_admin_message_type"] = "error";
            }
        }

        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    header("Location: manage_customers.php");
    exit;
}

// --------------------------------------------------
// SEARCH CUSTOMERS
// --------------------------------------------------

$search = $_GET["q"] ?? "";

if (!is_string($search)) {
    $search = "";
}

$search = trim($search);

if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}

try {

    if ($search !== "") {

        $stmt = $pdo->prepare("
            SELECT
                id,
                name,
                email,
                phone,
                status,
                created_at
            FROM customers
            WHERE
                name LIKE :name_search
                OR email LIKE :email_search
                OR phone LIKE :phone_search
            ORDER BY id DESC
            LIMIT 200
        ");

        $searchValue = "%" . $search . "%";

        $stmt->execute([
            ":name_search" => $searchValue,
            ":email_search" => $searchValue,
            ":phone_search" => $searchValue
        ]);

    } else {

        $stmt = $pdo->prepare("
            SELECT
                id,
                name,
                email,
                phone,
                status,
                created_at
            FROM customers
            ORDER BY id DESC
            LIMIT 200
        ");

        $stmt->execute();
    }

    $customers = $stmt->fetchAll();

} catch (Throwable $exception) {

    error_log($exception->getMessage());

    $customers = [];

    $message = "Unable to load customer records.";
    $messageType = "error";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Customers | Admin Panel</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        /* SIDEBAR */

        .sidebar {
            width: 250px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: #172554;
            color: white;
            padding: 25px 15px;
            overflow-y: auto;
        }

        .sidebar-brand {
            padding: 0 12px 25px;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            margin-bottom: 25px;
        }

        .sidebar-brand h2 {
            font-size: 21px;
            margin: 0 0 7px;
            color: white;
        }

        .sidebar-brand p {
            margin: 0;
            font-size: 12px;
            color: #bfdbfe;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin-bottom: 7px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 14px;
            border-radius: 7px;
            color: #dbeafe;
            text-decoration: none;
            font-size: 14px;
            transition: 0.2s;
        }

        .sidebar-menu a:hover {
            background: #1e40af;
            color: white;
        }

        .sidebar-menu a.active {
            background: #2563eb;
            color: white;
            font-weight: bold;
        }

        .menu-icon {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        /* MAIN CONTENT */

        .main-content {
            margin-left: 250px;
            padding: 30px;
            min-height: 100vh;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            gap: 15px;
            flex-wrap: wrap;
        }

        .topbar h1 {
            margin: 0 0 7px;
            font-size: 26px;
            color: #0f172a;
        }

        .topbar p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .admin-label {
            background: white;
            padding: 11px 16px;
            border-radius: 8px;
            color: #334155;
            font-size: 13px;
            border: 1px solid #e2e8f0;
        }

        /* CARDS */

        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .card-header h2 {
            margin: 0;
            font-size: 18px;
            color: #0f172a;
        }

        .card-description {
            color: #64748b;
            font-size: 13px;
            margin: 7px 0 0;
        }

        /* MESSAGES */

        .message {
            padding: 14px 17px;
            border-radius: 8px;
            margin-bottom: 22px;
            font-size: 14px;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* SEARCH */

        .search-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .search-form input {
            flex: 1;
            min-width: 220px;
            padding: 13px 15px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 14px;
            outline: none;
        }

        .search-form input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }

        /* BUTTONS */

        button,
        .button {
            border: none;
            border-radius: 7px;
            padding: 11px 16px;
            cursor: pointer;
            color: white;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            transition: 0.2s;
        }

        button:hover,
        .button:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .search-button {
            background: #2563eb;
        }

        .clear-button {
            background: #64748b;
        }

        .activate-button {
            background: #16a34a;
        }

        .deactivate-button {
            background: #dc2626;
        }

        /* CUSTOMER COUNT */

        .count {
            color: #64748b;
            font-size: 13px;
            margin: 0 0 18px;
        }

        .count strong {
            color: #0f172a;
        }

        /* TABLE */

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-weight: bold;
            padding: 15px 13px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        td {
            padding: 15px 13px;
            font-size: 13px;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .customer-name {
            font-weight: bold;
            color: #0f172a;
        }

        /* STATUS */

        .status {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .status.active {
            background: #dcfce7;
            color: #166534;
        }

        .status.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .action-form {
            display: inline-block;
            margin: 0;
        }

        .empty {
            text-align: center;
            padding: 35px;
            color: #64748b;
        }

        /* FOOTER */

        .page-footer {
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
            padding: 15px;
        }

        /* RESPONSIVE */

        @media (max-width: 900px) {

            .sidebar {
                width: 210px;
            }

            .main-content {
                margin-left: 210px;
                padding: 22px;
            }
        }

        @media (max-width: 650px) {

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding: 18px;
            }

            .sidebar-brand {
                margin-bottom: 15px;
                padding-bottom: 15px;
            }

            .sidebar-menu {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 5px;
            }

            .sidebar-menu li {
                margin: 0;
            }

            .sidebar-menu a {
                padding: 11px 9px;
                font-size: 12px;
            }

            .main-content {
                margin-left: 0;
                padding: 18px;
            }

            .topbar h1 {
                font-size: 22px;
            }

            .card {
                padding: 18px;
            }

            .search-form {
                flex-direction: column;
            }

            .search-form input,
            .search-form button,
            .search-form a {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<!-- SIDEBAR -->

<aside class="sidebar">

    <div class="sidebar-brand">

        <h2>My Website</h2>

        <p>Admin Control Panel</p>

    </div>

    <ul class="sidebar-menu">

        <li>
            <a href="dashboard.php">
                <span class="menu-icon">▦</span>
                Dashboard
            </a>
        </li>

        <!-- ROLE-BASED GALLERY LINK -->

        <li>
            <?php
            require __DIR__ . "/includes/gallery_sidebar_link.php";
            ?>
        </li>

        <li>
            <a href="manage_admins.php">
                <span class="menu-icon">♙</span>
                Manage Admins
            </a>
        </li>

        <li>
            <a href="manage_users.php">
                <span class="menu-icon">♧</span>
                Manage Users
            </a>
        </li>

        <li>
            <a href="manage_customers.php" class="active">
                <span class="menu-icon">♧</span>
                Manage Customers
            </a>
        </li>

        <li>
            <a href="manage_content.php">
                <span class="menu-icon">▤</span>
                Manage Content
            </a>
        </li>

        <li>
            <a href="manage_settings.php">
                <span class="menu-icon">⚙</span>
                Website Settings
            </a>
        </li>

        <li>
            <a href="activity_logs.php">
                <span class="menu-icon">◷</span>
                Activity Logs
            </a>
        </li>

        <li>
            <a href="profile.php">
                <span class="menu-icon">♙</span>
                My Profile
            </a>
        </li>

        <li>
            <a href="change_password.php">
                <span class="menu-icon">🔒</span>
                Change Password
            </a>
        </li>

        <li>
            <a href="logout.php">
                <span class="menu-icon">↪</span>
                Logout
            </a>
        </li>

    </ul>

</aside>

<!-- MAIN CONTENT -->

<main class="main-content">

    <div class="topbar">

        <div>

            <h1>Manage Customers</h1>

            <p>
                View customer registrations and manage customer account status.
            </p>

        </div>

        <div class="admin-label">
            Admin Panel
        </div>

    </div>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo e($messageType === "error" ? "error" : "success"); ?>">
            <?php echo e($message); ?>
        </div>

    <?php endif; ?>

    <!-- SEARCH CUSTOMERS -->

    <section class="card">

        <div class="card-header">

            <div>

                <h2>Search Customers</h2>

                <p class="card-description">
                    Search customers using their name, email address or phone number.
                </p>

            </div>

        </div>

        <form
            method="GET"
            action="manage_customers.php"
            class="search-form"
        >

            <input
                type="text"
                name="q"
                maxlength="100"
                placeholder="Search by name, email or phone..."
                value="<?php echo e($search); ?>"
            >

            <button type="submit" class="search-button">
                Search
            </button>

            <a
                href="manage_customers.php"
                class="button clear-button"
            >
                Clear
            </a>

        </form>

    </section>

    <!-- CUSTOMER LIST -->

    <section class="card">

        <div class="card-header">

            <div>

                <h2>Customer List</h2>

                <p class="card-description">
                    View registered customers and manage their account status.
                </p>

            </div>

        </div>

        <p class="count">

            Showing <strong><?php echo count($customers); ?></strong>
            customer record(s).

            <?php if (count($customers) === 200): ?>

                The list is limited to the latest 200 records.

            <?php endif; ?>

        </p>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>Customer Name</th>
                        <th>Email Address</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Registration Date</th>
                        <th>Action</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (empty($customers)): ?>

                        <tr>

                            <td colspan="7" class="empty">
                                No customers found.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($customers as $customer): ?>

                            <tr>

                                <td>
                                    <?php echo (int) $customer["id"]; ?>
                                </td>

                                <td class="customer-name">
                                    <?php echo e($customer["name"]); ?>
                                </td>

                                <td>
                                    <?php echo e($customer["email"]); ?>
                                </td>

                                <td>
                                    <?php echo e($customer["phone"]); ?>
                                </td>

                                <td>

                                    <span class="status <?php echo e($customer["status"] === "active" ? "active" : "inactive"); ?>">

                                        <?php echo e(ucfirst($customer["status"])); ?>

                                    </span>

                                </td>

                                <td>

                                    <?php

                                    $registrationDate = strtotime(
                                        $customer["created_at"]
                                    );

                                    echo $registrationDate
                                        ? e(date("d M Y", $registrationDate))
                                        : "N/A";

                                    ?>

                                </td>

                                <td>

                                    <form
                                        method="POST"
                                        action="manage_customers.php"
                                        class="action-form"
                                        onsubmit="return confirm('Are you sure you want to change this customer account status?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php echo e($csrfToken); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="customer_id"
                                            value="<?php echo (int) $customer["id"]; ?>"
                                        >

                                        <?php if ($customer["status"] === "active"): ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="deactivate"
                                            >

                                            <button
                                                type="submit"
                                                class="deactivate-button"
                                            >
                                                Deactivate
                                            </button>

                                        <?php else: ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="activate"
                                            >

                                            <button
                                                type="submit"
                                                class="activate-button"
                                            >
                                                Activate
                                            </button>

                                        <?php endif; ?>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <div class="page-footer">
        Admin Control Panel
    </div>

</main>

</body>
</html>
