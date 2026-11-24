
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";

// --------------------------------------------------
// WEBSITE SETTINGS
// --------------------------------------------------

$stmt = $pdo->query("
    SELECT setting_key, setting_value
    FROM settings
");

$settingsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$settings = [];

foreach ($settingsRows as $row) {
    $settings[$row["setting_key"]] = $row["setting_value"];
}

$websiteName = $settings["website_name"] ?? "My Website";
$websiteLogo = $settings["website_logo"] ?? "";

// --------------------------------------------------
// HELPER FUNCTIONS
// --------------------------------------------------

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function createPieGradient($data, $colors) {

    $total = array_sum($data);

    if ($total <= 0) {
        return "conic-gradient(#e2e8f0 0deg 360deg)";
    }

    $gradientParts = [];
    $currentAngle = 0;
    $index = 0;

    foreach ($data as $value) {

        $value = (int) $value;

        if ($value <= 0) {
            $index++;
            continue;
        }

        $angle = ($value / $total) * 360;
        $endAngle = $currentAngle + $angle;

        $color = $colors[$index % count($colors)];

        $gradientParts[] =
            $color . " " .
            round($currentAngle, 2) . "deg " .
            round($endAngle, 2) . "deg";

        $currentAngle = $endAngle;
        $index++;
    }

    return "conic-gradient(" .
        implode(", ", $gradientParts) .
        ")";
}

// --------------------------------------------------
// CURRENT ADMIN DETAILS
// --------------------------------------------------

$adminName = $_SESSION["admin_name"] ?? "Admin";
$adminRole = $_SESSION["admin_role"] ?? "";
$adminId = (int) ($_SESSION["admin_id"] ?? 0);

if ($adminId <= 0) {
    http_response_code(403);
    exit("Invalid admin session.");
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
// PERMISSIONS
// --------------------------------------------------

$isManager = hasPermission("view_all_leads");
$isSalesEmployee = hasPermission("view_assigned_leads");
$canManageAdmins = hasPermission("manage_admins");
$canManageUsers = hasPermission("manage_users");
$canManageContent = hasPermission("manage_content");
$canManageFollowups = hasPermission("manage_followups");
$canManageVisits = hasPermission("manage_site_visits");

// --------------------------------------------------
// DASHBOARD STATISTICS
// --------------------------------------------------

$totalAdmins = 0;
$totalUsers = 0;
$totalCustomers = 0;
$totalContent = 0;

$totalLeads = 0;
$assignedLeads = 0;
$totalFollowups = 0;
$totalSiteVisits = 0;

$unreadNotifications = 0;

// Today's work
$todaysNewLeads = 0;
$todaysFollowups = 0;
$todaysSiteVisits = 0;
$todaysActivities = 0;

// Chart data
$leadChart = [
    "New" => 0,
    "Contacted" => 0,
    "Converted" => 0,
    "Lost" => 0
];

$siteVisitChart = [];

// --------------------------------------------------
// WEBSITE STATISTICS
// --------------------------------------------------

if ($canManageAdmins) {

    $totalAdmins = (int) $pdo->query("
        SELECT COUNT(*)
        FROM admins
    ")->fetchColumn();
}

if ($canManageUsers) {

    $totalUsers = (int) $pdo->query("
        SELECT COUNT(*)
        FROM users
    ")->fetchColumn();

    $totalCustomers = (int) $pdo->query("
        SELECT COUNT(*)
        FROM customers
    ")->fetchColumn();
}

if ($canManageContent) {

    $totalContent = (int) $pdo->query("
        SELECT COUNT(*)
        FROM content
    ")->fetchColumn();
}

// --------------------------------------------------
// CRM STATISTICS
// --------------------------------------------------

if ($isManager) {

    $totalLeads = (int) $pdo->query("
        SELECT COUNT(*)
        FROM leads
    ")->fetchColumn();

    $assignedLeads = (int) $pdo->query("
        SELECT COUNT(*)
        FROM leads
        WHERE assigned_to IS NOT NULL
    ")->fetchColumn();

    $totalFollowups = (int) $pdo->query("
        SELECT COUNT(*)
        FROM lead_followups
    ")->fetchColumn();

    $totalSiteVisits = (int) $pdo->query("
        SELECT COUNT(*)
        FROM site_visits
    ")->fetchColumn();

} elseif ($isSalesEmployee) {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM leads
        WHERE assigned_to = ?
    ");

    $stmt->execute([$adminId]);
    $assignedLeads = (int) $stmt->fetchColumn();

    $totalLeads = $assignedLeads;

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM lead_followups lf
        INNER JOIN leads l ON lf.lead_id = l.id
        WHERE l.assigned_to = ?
    ");

    $stmt->execute([$adminId]);
    $totalFollowups = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM site_visits
        WHERE assigned_to = ?
    ");

    $stmt->execute([$adminId]);
    $totalSiteVisits = (int) $stmt->fetchColumn();
}

// --------------------------------------------------
// LEAD STATUS PIE CHART
// --------------------------------------------------

if ($isManager || $isSalesEmployee) {

    $leadSql = "
        SELECT lead_status, COUNT(*) AS total
        FROM leads
    ";

    if ($isSalesEmployee && !$isManager) {
        $leadSql .= " WHERE assigned_to = ? ";
    }

    $leadSql .= " GROUP BY lead_status";

    if ($isSalesEmployee && !$isManager) {

        $stmt = $pdo->prepare($leadSql);
        $stmt->execute([$adminId]);

    } else {

        $stmt = $pdo->query($leadSql);
    }

    $leadRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($leadRows as $row) {

        $status = strtolower(trim($row["lead_status"]));

        if ($status === "new") {
            $leadChart["New"] = (int) $row["total"];
        } elseif ($status === "contacted") {
            $leadChart["Contacted"] = (int) $row["total"];
        } elseif ($status === "converted") {
            $leadChart["Converted"] = (int) $row["total"];
        } elseif ($status === "lost") {
            $leadChart["Lost"] = (int) $row["total"];
        }
    }
}

// --------------------------------------------------
// SITE VISIT STATUS PIE CHART
// --------------------------------------------------

if ($isManager || $isSalesEmployee) {

    $visitSql = "
        SELECT visit_status, COUNT(*) AS total
        FROM site_visits
    ";

    if ($isSalesEmployee && !$isManager) {
        $visitSql .= " WHERE assigned_to = ? ";
    }

    $visitSql .= " GROUP BY visit_status";

    if ($isSalesEmployee && !$isManager) {

        $stmt = $pdo->prepare($visitSql);
        $stmt->execute([$adminId]);

    } else {

        $stmt = $pdo->query($visitSql);
    }

    $visitRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($visitRows as $row) {

        $status = ucwords(
            str_replace("_", " ", (string) $row["visit_status"])
        );

        $siteVisitChart[$status] = (int) $row["total"];
    }
}

// --------------------------------------------------
// TODAY'S WORK STATISTICS
// --------------------------------------------------

if ($isManager || $isSalesEmployee) {

    $leadTodaySql = "
        SELECT COUNT(*)
        FROM leads
        WHERE DATE(created_at) = CURDATE()
    ";

    if ($isSalesEmployee && !$isManager) {

        $leadTodaySql .= " AND assigned_to = ? ";

        $stmt = $pdo->prepare($leadTodaySql);
        $stmt->execute([$adminId]);

    } else {

        $stmt = $pdo->query($leadTodaySql);
    }

    $todaysNewLeads = (int) $stmt->fetchColumn();

    $followupTodaySql = "
        SELECT COUNT(*)
        FROM lead_followups lf
        INNER JOIN leads l ON lf.lead_id = l.id
        WHERE lf.next_followup_at IS NOT NULL
        AND DATE(lf.next_followup_at) = CURDATE()
    ";

    if ($isSalesEmployee && !$isManager) {

        $followupTodaySql .= " AND l.assigned_to = ? ";

        $stmt = $pdo->prepare($followupTodaySql);
        $stmt->execute([$adminId]);

    } else {

        $stmt = $pdo->query($followupTodaySql);
    }

    $todaysFollowups = (int) $stmt->fetchColumn();

    $visitTodaySql = "
        SELECT COUNT(*)
        FROM site_visits
        WHERE DATE(scheduled_at) = CURDATE()
    ";

    if ($isSalesEmployee && !$isManager) {

        $visitTodaySql .= " AND assigned_to = ? ";

        $stmt = $pdo->prepare($visitTodaySql);
        $stmt->execute([$adminId]);

    } else {

        $stmt = $pdo->query($visitTodaySql);
    }

    $todaysSiteVisits = (int) $stmt->fetchColumn();
}

// --------------------------------------------------
// TODAY'S RECORDED ADMIN ACTIVITIES
// --------------------------------------------------

if ($canManageAdmins) {

    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM activity_logs
        WHERE DATE(created_at) = CURDATE()
    ");

    $todaysActivities = (int) $stmt->fetchColumn();

} else {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM activity_logs
        WHERE admin_id = ?
        AND DATE(created_at) = CURDATE()
    ");

    $stmt->execute([$adminId]);

    $todaysActivities = (int) $stmt->fetchColumn();
}

// --------------------------------------------------
// EMPLOYEE NOTIFICATIONS
// --------------------------------------------------

if ($isSalesEmployee) {

    $notificationStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE admin_id = ?
        AND is_read = 0
    ");

    $notificationStmt->execute([$adminId]);

    $unreadNotifications = (int) $notificationStmt->fetchColumn();
}

// --------------------------------------------------
// LOGO VALIDATION
// --------------------------------------------------

$logoPath = "";

if (
    is_string($websiteLogo) &&
    preg_match(
        '/^uploads\/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$/i',
        $websiteLogo
    )
) {

    $physicalLogoPath = dirname(__DIR__) . "/" . $websiteLogo;

    if (is_file($physicalLogoPath)) {
        $logoPath = "../" . $websiteLogo;
    }
}

// --------------------------------------------------
// CHART CONFIGURATION
// --------------------------------------------------

$leadColors = [
    "#3569a8",
    "#d99036",
    "#299764",
    "#d85b63"
];

$visitColors = [
    "#3569a8",
    "#299764",
    "#d99036",
    "#d85b63",
    "#8265bd",
    "#0891b2"
];

$leadGradient = createPieGradient(
    array_values($leadChart),
    $leadColors
);

$visitGradient = createPieGradient(
    array_values($siteVisitChart),
    $visitColors
);

$leadTotal = array_sum($leadChart);
$visitTotal = array_sum($siteVisitChart);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | <?php echo e($websiteName); ?></title>

    <style>

        /* --------------------------------------------------
           GLOBAL DESIGN
        -------------------------------------------------- */

        * {
            box-sizing: border-box;
        }

        :root {
            --navy: #17365d;
            --navy-dark: #102944;
            --blue: #3569a8;
            --background: #f5f7fb;
            --surface: #ffffff;
            --border: #e5eaf1;
            --text: #1d2939;
            --muted: #667085;
            --radius: 14px;
            --shadow: 0 5px 20px rgba(16, 41, 68, 0.055);
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            background: var(--background);
            color: var(--text);
        }

        /* SIDEBAR */

        .sidebar {
            width: 255px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: linear-gradient(
                180deg,
                var(--navy-dark),
                var(--navy)
            );
            color: white;
            padding: 25px 16px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            z-index: 100;
            box-shadow: 5px 0 22px rgba(16, 41, 68, 0.08);
        }

        .sidebar-header {
            text-align: center;
            padding: 10px 10px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.13);
            margin-bottom: 23px;
        }

        .sidebar-header img {
            max-width: 155px;
            max-height: 68px;
            object-fit: contain;
            margin-bottom: 12px;
        }

        .sidebar-header h2 {
            margin: 0 0 7px;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.5;
            color: white;
            overflow-wrap: anywhere;
        }

        .sidebar-header p {
            margin: 0;
            color: #c3d5eb;
            font-size: 12px;
            letter-spacing: 0.4px;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            min-height: 43px;
            border-radius: 9px;
            color: #d7e3f1;
            text-decoration: none;
            font-size: 13px;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .sidebar-menu a:hover {
            background: rgba(255,255,255,0.11);
            color: white;
        }

        .sidebar-menu a.active {
            background: white;
            color: var(--navy);
            font-weight: 700;
            box-shadow: 0 3px 12px rgba(0,0,0,0.12);
        }

        .menu-icon {
            width: 21px;
            text-align: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .notification-badge {
            display: inline-block;
            background: #e5484d;
            color: white;
            font-size: 11px;
            font-weight: bold;
            padding: 3px 7px;
            border-radius: 20px;
            margin-left: auto;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,0.13);
        }

        .sidebar-footer form {
            margin: 0;
        }

        .sidebar-footer button {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255,255,255,0.13);
            border-radius: 9px;
            background: rgba(220, 38, 38, 0.16);
            color: white;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .sidebar-footer button:hover {
            background: #b42318;
        }

        /* MAIN CONTENT */

        .main-content {
            margin-left: 255px;
            padding: 34px;
            min-height: 100vh;
        }

        .welcome-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 32px;
        }

        .welcome-header h1 {
            margin: 0 0 8px;
            font-size: 28px;
            color: var(--navy-dark);
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .welcome-header p {
            margin: 0;
            font-size: 14px;
            color: var(--muted);
            line-height: 1.6;
        }

        .admin-profile-label {
            background: white;
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 13px 17px;
            font-size: 13px;
            color: #334155;
            box-shadow: 0 2px 8px rgba(16,41,68,0.035);
        }

        .admin-profile-label strong {
            color: var(--blue);
        }

        /* SECTION HEADINGS */

        .section-heading {
            margin: 32px 0 18px;
        }

        .section-heading h2 {
            margin: 0 0 7px;
            font-size: 19px;
            color: var(--navy-dark);
            font-weight: 700;
            letter-spacing: -0.2px;
        }

        .section-heading p {
            margin: 0;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.6;
        }

        /* STATISTIC CARDS */

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(205px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .dashboard-card {
            min-width: 0;
            background: var(--surface);
            padding: 23px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 9px 25px rgba(16,41,68,0.09);
        }

        .dashboard-card h3 {
            margin: 0 0 12px;
            font-size: 13px;
            color: var(--muted);
            font-weight: 600;
        }

        .dashboard-card h1 {
            margin: 0 0 8px;
            font-size: 32px;
            color: var(--navy-dark);
            font-weight: 700;
            letter-spacing: -0.7px;
        }

        .dashboard-card p {
            margin: 0;
            font-size: 12px;
            line-height: 1.7;
            color: var(--muted);
        }

        .dashboard-card a {
            display: inline-block;
            margin-top: 13px;
            color: var(--blue);
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        .dashboard-card a:hover {
            color: var(--navy-dark);
            text-decoration: underline;
        }

        .stat-card {
            border-top: 3px solid #3569a8;
        }

        .stat-card.green {
            border-top-color: #299764;
        }

        .stat-card.orange {
            border-top-color: #d99036;
        }

        .stat-card.purple {
            border-top-color: #8265bd;
        }

        .stat-card.red {
            border-top-color: #d85b63;
        }

        /* TODAY'S WORK */

        .today-card {
            border-left: 3px solid #3569a8;
        }

        .today-card.green {
            border-left-color: #299764;
        }

        .today-card.orange {
            border-left-color: #d99036;
        }

        .today-card.purple {
            border-left-color: #8265bd;
        }

        .today-date {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #eaf1f9;
            color: var(--navy);
            padding: 9px 13px;
            border: 1px solid #dce7f3;
            border-radius: 9px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        /* CHART LAYOUT */

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .chart-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 25px;
            box-shadow: var(--shadow);
            min-width: 0;
        }

        .chart-card h3 {
            margin: 0 0 7px;
            color: var(--navy-dark);
            font-size: 16px;
            font-weight: 700;
        }

        .chart-description {
            margin: 0 0 22px;
            font-size: 12px;
            color: var(--muted);
            line-height: 1.6;
        }

        .chart-content {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 28px;
            min-height: 220px;
        }

        .pie-chart {
            width: 185px;
            height: 185px;
            min-width: 185px;
            border-radius: 50%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 12px rgba(16,41,68,0.07);
        }

        .pie-chart::after {
            content: "";
            position: absolute;
            width: 105px;
            height: 105px;
            background: white;
            border-radius: 50%;
        }

        .pie-chart-center {
            position: absolute;
            z-index: 2;
            text-align: center;
        }

        .pie-chart-center strong {
            display: block;
            color: var(--navy-dark);
            font-size: 25px;
        }

        .pie-chart-center span {
            display: block;
            color: var(--muted);
            font-size: 11px;
            margin-top: 4px;
        }

        .chart-legend {
            display: flex;
            flex-direction: column;
            gap: 15px;
            min-width: 125px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 9px;
            color: #475467;
            font-size: 12px;
        }

        .legend-color {
            width: 10px;
            height: 10px;
            border-radius: 3px;
            flex-shrink: 0;
        }

        .legend-value {
            margin-left: auto;
            padding-left: 12px;
            font-weight: bold;
            color: var(--navy-dark);
        }

        .empty-chart {
            width: 100%;
            padding: 45px 15px;
            text-align: center;
            background: #f7f9fc;
            border: 1px dashed #d6dfeb;
            border-radius: 10px;
            color: var(--muted);
            font-size: 13px;
        }

        /* FOOTER */

        .page-footer {
            text-align: center;
            padding: 25px 10px;
            color: #8793a5;
            font-size: 12px;
            border-top: 1px solid var(--border);
            margin-top: 25px;
        }

        /* KEYBOARD ACCESSIBILITY */

        .sidebar-menu a:focus-visible,
        .dashboard-card a:focus-visible,
        .sidebar-footer button:focus-visible {
            outline: 3px solid #9cc5f1;
            outline-offset: 3px;
        }

        /* RESPONSIVE */

        @media (max-width: 1100px) {

            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1000px) {

            .sidebar {
                width: 220px;
            }

            .main-content {
                margin-left: 220px;
                padding: 25px;
            }
        }

        @media (max-width: 700px) {

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding: 18px;
            }

            .sidebar-header {
                padding-bottom: 15px;
                margin-bottom: 15px;
            }

            .sidebar-menu {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 5px;
            }

            .sidebar-menu a {
                padding: 10px;
                font-size: 12px;
            }

            .sidebar-footer {
                margin-top: 15px;
                padding-top: 10px;
            }

            .main-content {
                margin-left: 0;
                padding: 19px;
            }

            .welcome-header h1 {
                font-size: 23px;
            }

            .admin-profile-label {
                width: 100%;
            }

            .dashboard-cards {
                grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
                gap: 12px;
            }

            .dashboard-card {
                padding: 17px;
            }

            .dashboard-card h1 {
                font-size: 26px;
            }

            .chart-card {
                padding: 18px;
            }

            .pie-chart {
                width: 165px;
                height: 165px;
                min-width: 165px;
            }
        }

    </style>

</head>

<body>

<!-- SIDEBAR -->

<aside class="sidebar">

    <div class="sidebar-header">

        <?php if ($logoPath !== ""): ?>

            <img
                src="<?php echo e($logoPath); ?>"
                alt="Website Logo"
            >

        <?php endif; ?>

        <h2><?php echo e($websiteName); ?></h2>

        <p>Admin Control Panel</p>

    </div>

    <nav class="sidebar-menu">

        <a href="dashboard.php" class="active">
            <span class="menu-icon">▦</span>
            Dashboard
        </a>

        <?php
        require __DIR__ . "/includes/gallery_sidebar_link.php";
        ?>

        <?php if ($canManageAdmins): ?>

            <a href="manage_admins.php">
                <span class="menu-icon">♙</span>
                Manage Admins
            </a>

            <a href="all_accounts.php">
                <span class="menu-icon">☷</span>
                All Accounts
            </a>

            <a href="activity_logs.php">
                <span class="menu-icon">◷</span>
                Activity Logs
            </a>

        <?php endif; ?>

        <?php if ($canManageContent): ?>

            <a href="manage_content.php">
                <span class="menu-icon">▤</span>
                Manage Content
            </a>

        <?php endif; ?>

        <?php if ($canManageUsers): ?>

            <a href="manage_users.php">
                <span class="menu-icon">♧</span>
                Manage Users
            </a>

            <a href="manage_customers.php">
                <span class="menu-icon">♧</span>
                Manage Customers
            </a>

        <?php endif; ?>

        <?php if (hasPermission("manage_settings")): ?>

            <a href="manage_settings.php">
                <span class="menu-icon">⚙</span>
                Website Settings
            </a>

        <?php endif; ?>

        <?php if (hasPermission("manage_leads")): ?>

            <a href="manage_leads.php">
                <span class="menu-icon">♧</span>
                Manage Leads
            </a>

        <?php endif; ?>

        <?php if ($canManageFollowups): ?>

            <a href="lead_followups.php">
                <span class="menu-icon">◷</span>
                Lead Follow-ups
            </a>

            <a href="followup_reminders.php">
                <span class="menu-icon">♢</span>
                Follow-up Reminders
            </a>

        <?php endif; ?>

        <?php if ($canManageVisits): ?>

            <a href="site_visits.php">
                <span class="menu-icon">⌂</span>
                Site Visits
            </a>

        <?php endif; ?>

        <?php if (hasPermission("view_crm_reports")): ?>

            <a href="crm_reports.php">
                <span class="menu-icon">▥</span>
                CRM Reports
            </a>

        <?php endif; ?>

        <?php if ($isSalesEmployee): ?>

            <a href="manage_leads.php">
                <span class="menu-icon">♙</span>
                My Assigned Leads
            </a>

            <a href="notifications.php">
                <span class="menu-icon">♧</span>
                Notifications

                <?php if ($unreadNotifications > 0): ?>

                    <span class="notification-badge">
                        <?php echo (int) $unreadNotifications; ?>
                    </span>

                <?php endif; ?>

            </a>

        <?php endif; ?>

        <a href="profile.php">
            <span class="menu-icon">♙</span>
            My Profile
        </a>

        <a href="change_password.php">
            <span class="menu-icon">🔒</span>
            Change Password
        </a>

    </nav>

    <div class="sidebar-footer">

        <form action="logout.php" method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo e($_SESSION["csrf_token"]); ?>"
            >

            <button type="submit">
                ↪ &nbsp; Logout
            </button>

        </form>

    </div>

</aside>

<!-- MAIN CONTENT -->

<main class="main-content">

    <!-- WELCOME HEADER -->

    <header class="welcome-header">

        <div>

            <h1>Welcome to Your Dashboard</h1>

            <p>
                Hello, <strong><?php echo e($adminName); ?></strong>.
                Here's your website overview.
            </p>

        </div>

        <div class="admin-profile-label">

            Role:

            <strong>
                <?php
                echo e(
                    ucwords(str_replace("_", " ", $adminRole))
                );
                ?>
            </strong>

        </div>

    </header>

    <!-- MAIN STATISTIC CARDS -->

    <div class="section-heading">

        <h2>Dashboard Overview</h2>

        <p>Your website and CRM statistics at a glance.</p>

    </div>

    <div class="dashboard-cards">

        <?php if ($canManageAdmins): ?>

            <div class="dashboard-card stat-card">

                <h3>Total Admins</h3>

                <h1><?php echo (int) $totalAdmins; ?></h1>

                <p>Total administrator accounts</p>

                <a href="manage_admins.php">Manage Admins →</a>

            </div>

        <?php endif; ?>

        <?php if ($canManageUsers): ?>

            <div class="dashboard-card stat-card green">

                <h3>Total Website Users</h3>

                <h1><?php echo (int) $totalUsers; ?></h1>

                <p>Total registered website users</p>

                <a href="manage_users.php">Manage Users →</a>

            </div>

            <div class="dashboard-card stat-card purple">

                <h3>Total Customers</h3>

                <h1><?php echo (int) $totalCustomers; ?></h1>

                <p>Total registered website customers</p>

                <a href="manage_customers.php">Manage Customers →</a>

            </div>

        <?php endif; ?>

        <?php if ($canManageContent): ?>

            <div class="dashboard-card stat-card orange">

                <h3>Total Content</h3>

                <h1><?php echo (int) $totalContent; ?></h1>

                <p>Total website content entries</p>

                <a href="manage_content.php">Manage Content →</a>

            </div>

        <?php endif; ?>

        <?php if ($isManager): ?>

            <div class="dashboard-card stat-card">

                <h3>Total Leads</h3>

                <h1><?php echo (int) $totalLeads; ?></h1>

                <p>Total customer enquiries</p>

                <a href="manage_leads.php">View Leads →</a>

            </div>

            <div class="dashboard-card stat-card green">

                <h3>Assigned Leads</h3>

                <h1><?php echo (int) $assignedLeads; ?></h1>

                <p>Leads assigned to employees</p>

                <a href="manage_leads.php">View Assignments →</a>

            </div>

            <div class="dashboard-card stat-card orange">

                <h3>Total Follow-ups</h3>

                <h1><?php echo (int) $totalFollowups; ?></h1>

                <p>Recorded customer follow-ups</p>

                <a href="lead_followups.php">View Follow-ups →</a>

            </div>

            <div class="dashboard-card stat-card purple">

                <h3>Site Visits</h3>

                <h1><?php echo (int) $totalSiteVisits; ?></h1>

                <p>Recorded property visits</p>

                <a href="site_visits.php">View Site Visits →</a>

            </div>

        <?php endif; ?>

        <?php if ($isSalesEmployee): ?>

            <div class="dashboard-card stat-card">

                <h3>My Assigned Leads</h3>

                <h1><?php echo (int) $assignedLeads; ?></h1>

                <p>Customers assigned to you</p>

                <a href="manage_leads.php">View My Leads →</a>

            </div>

            <div class="dashboard-card stat-card orange">

                <h3>My Follow-ups</h3>

                <h1><?php echo (int) $totalFollowups; ?></h1>

                <p>Follow-ups for your assigned leads</p>

                <a href="lead_followups.php">View My Follow-ups →</a>

            </div>

            <div class="dashboard-card stat-card purple">

                <h3>My Site Visits</h3>

                <h1><?php echo (int) $totalSiteVisits; ?></h1>

                <p>Your assigned property visits</p>

                <a href="site_visits.php">View My Site Visits →</a>

            </div>

        <?php endif; ?>

        <!-- PROPERTY ENQUIRIES DASHBOARD CARD -->

        <?php
        require __DIR__ . "/includes/property_enquiry_dashboard_stats.php";
        ?>

    </div>

    <!-- TODAY'S WORK -->

    <?php if ($isManager || $isSalesEmployee): ?>

        <div class="section-heading">

            <h2>Today's Work</h2>

            <p>
                A summary of the work recorded in your CRM today.
            </p>

        </div>

        <div class="today-date">
            <span>📅</span>
            <?php echo date("d F Y"); ?>
        </div>

        <div class="dashboard-cards">

            <div class="dashboard-card today-card">

                <h3>New Leads Today</h3>

                <h1><?php echo (int) $todaysNewLeads; ?></h1>

                <p>New customer enquiries created today.</p>

            </div>

            <div class="dashboard-card today-card green">

                <h3>Today's Follow-ups</h3>

                <h1><?php echo (int) $todaysFollowups; ?></h1>

                <p>Customer follow-ups scheduled for today.</p>

            </div>

            <div class="dashboard-card today-card orange">

                <h3>Today's Site Visits</h3>

                <h1><?php echo (int) $todaysSiteVisits; ?></h1>

                <p>Property visits scheduled for today.</p>

            </div>

            <div class="dashboard-card today-card purple">

                <h3>Activities Recorded Today</h3>

                <h1><?php echo (int) $todaysActivities; ?></h1>

                <p>Admin activities recorded in the activity log.</p>

            </div>

        </div>

    <?php endif; ?>

    <!-- ANALYTICS CHARTS -->

    <?php if ($isManager || $isSalesEmployee): ?>

        <div class="section-heading">

            <h2>CRM Analytics</h2>

            <p>
                Visual overview of your lead pipeline and site visits.
            </p>

        </div>

        <div class="charts-grid">

            <!-- LEAD STATUS PIE CHART -->

            <div class="chart-card">

                <h3>Lead Status Distribution</h3>

                <p class="chart-description">
                    Distribution of leads by their current status.
                </p>

                <div class="chart-content">

                    <?php if ($leadTotal > 0): ?>

                        <div
                            class="pie-chart"
                            style="background: <?php echo e($leadGradient); ?>;"
                            role="img"
                            aria-label="Lead status pie chart"
                        >

                            <div class="pie-chart-center">

                                <strong>
                                    <?php echo (int) $leadTotal; ?>
                                </strong>

                                <span>Total Leads</span>

                            </div>

                        </div>

                        <div class="chart-legend">

                            <?php
                            $index = 0;

                            foreach ($leadChart as $label => $value):
                            ?>

                                <div class="legend-item">

                                    <span
                                        class="legend-color"
                                        style="background: <?php echo e($leadColors[$index]); ?>;"
                                    ></span>

                                    <span><?php echo e($label); ?></span>

                                    <span class="legend-value">
                                        <?php echo (int) $value; ?>
                                    </span>

                                </div>

                            <?php
                                $index++;
                            endforeach;
                            ?>

                        </div>

                    <?php else: ?>

                        <div class="empty-chart">
                            No lead data is available yet.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

            <!-- SITE VISIT PIE CHART -->

            <div class="chart-card">

                <h3>Site Visit Distribution</h3>

                <p class="chart-description">
                    Distribution of site visits by their current status.
                </p>

                <div class="chart-content">

                    <?php if ($visitTotal > 0): ?>

                        <div
                            class="pie-chart"
                            style="background: <?php echo e($visitGradient); ?>;"
                            role="img"
                            aria-label="Site visit status pie chart"
                        >

                            <div class="pie-chart-center">

                                <strong>
                                    <?php echo (int) $visitTotal; ?>
                                </strong>

                                <span>Total Visits</span>

                            </div>

                        </div>

                        <div class="chart-legend">

                            <?php
                            $index = 0;

                            foreach ($siteVisitChart as $label => $value):
                            ?>

                                <div class="legend-item">

                                    <span
                                        class="legend-color"
                                        style="background: <?php echo e($visitColors[$index % count($visitColors)]); ?>;"
                                    ></span>

                                    <span><?php echo e($label); ?></span>

                                    <span class="legend-value">
                                        <?php echo (int) $value; ?>
                                    </span>

                                </div>

                            <?php
                                $index++;
                            endforeach;
                            ?>

                        </div>

                    <?php else: ?>

                        <div class="empty-chart">
                            No site visit data is available yet.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    <?php endif; ?>

    <footer class="page-footer">

        <?php echo e($websiteName); ?> — Admin Control Panel

    </footer>

</main>

</body>
</html>
