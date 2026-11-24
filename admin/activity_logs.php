
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";

// Only Super Admin can view activity logs
requirePermission("manage_admins");

// Escape output safely
function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

// Get the latest 100 activity records
$stmt = $pdo->prepare(
    "SELECT
        activity_logs.id,
        activity_logs.admin_id,
        activity_logs.action,
        activity_logs.description,
        activity_logs.created_at,
        admins.name AS admin_name
     FROM activity_logs
     LEFT JOIN admins
        ON activity_logs.admin_id = admins.id
     ORDER BY activity_logs.id DESC
     LIMIT 100"
);

$stmt->execute();

$logs = $stmt->fetchAll();

$totalLogs = count($logs);
$adminName = $_SESSION["admin_name"] ?? "Administrator";

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Activity Logs | Admin Panel</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>

        .activity-page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 10px 5px 35px;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .activity-header h1 {
            font-size: 28px;
            color: #172554;
            margin: 0 0 8px;
        }

        .activity-header p {
            color: #64748b;
            margin: 0;
            font-size: 14px;
        }

        .activity-back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 18px;
            background: #1e40af;
            color: #ffffff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: 0.2s;
        }

        .activity-back-btn:hover {
            background: #1e3a8a;
            color: #ffffff;
        }

        .activity-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .activity-summary-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
        }

        .activity-summary-label {
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .activity-summary-number {
            color: #172554;
            font-size: 30px;
            font-weight: 700;
        }

        .activity-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .activity-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            padding: 22px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .activity-card-header h2 {
            color: #172554;
            font-size: 19px;
            margin: 0 0 5px;
        }

        .activity-card-header p {
            color: #64748b;
            font-size: 13px;
            margin: 0;
        }

        .activity-count {
            display: inline-block;
            padding: 7px 12px;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .activity-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .activity-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        .activity-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            padding: 15px 18px;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .activity-table td {
            padding: 16px 18px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 13px;
            vertical-align: top;
            line-height: 1.6;
        }

        .activity-table tbody tr:hover {
            background: #f8fafc;
        }

        .activity-table tbody tr:last-child td {
            border-bottom: none;
        }

        .activity-id {
            color: #64748b;
            font-weight: 700;
        }

        .activity-admin {
            color: #172554;
            font-weight: 600;
        }

        .activity-action {
            display: inline-block;
            padding: 5px 10px;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .activity-description {
            max-width: 400px;
            overflow-wrap: anywhere;
            color: #475569;
        }

        .activity-date {
            white-space: nowrap;
            color: #64748b;
            font-size: 12px;
        }

        .activity-empty {
            text-align: center;
            padding: 55px 20px !important;
            color: #64748b !important;
        }

        .activity-empty strong {
            display: block;
            color: #334155;
            font-size: 16px;
            margin-bottom: 7px;
        }

        @media (max-width: 600px) {

            .activity-header h1 {
                font-size: 23px;
            }

            .activity-card-header {
                padding: 18px;
            }

            .activity-summary-card {
                padding: 18px;
            }

        }

    </style>

</head>

<body>

    <div class="main-content">

        <div class="activity-page">

            <!-- PAGE HEADER -->

            <div class="activity-header">

                <div>

                    <h1>Admin Activity Logs</h1>

                    <p>
                        Welcome back, <?php echo e($adminName); ?>.
                        Monitor administrative activities across your website.
                    </p>

                </div>

                <a href="dashboard.php" class="activity-back-btn">
                    Back to Dashboard
                </a>

            </div>

            <!-- SUMMARY -->

            <div class="activity-summary">

                <div class="activity-summary-card">

                    <div class="activity-summary-label">
                        Activity Records Displayed
                    </div>

                    <div class="activity-summary-number">
                        <?php echo $totalLogs; ?>
                    </div>

                </div>

            </div>

            <!-- ACTIVITY HISTORY -->

            <div class="activity-card">

                <div class="activity-card-header">

                    <div>

                        <h2>Activity History</h2>

                        <p>
                            Review the latest administrative actions.
                        </p>

                    </div>

                    <span class="activity-count">
                        Latest 100 Records
                    </span>

                </div>

                <div class="activity-table-wrapper">

                    <table class="activity-table">

                        <thead>

                            <tr>
                                <th>ID</th>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>Date and Time</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if ($totalLogs > 0): ?>

                                <?php foreach ($logs as $log): ?>

                                    <tr>

                                        <td class="activity-id">
                                            #<?php echo (int) $log["id"]; ?>
                                        </td>

                                        <td class="activity-admin">

                                            <?php
                                            if ($log["admin_name"] !== null) {
                                                echo e($log["admin_name"]);
                                            } else {
                                                echo "Deleted / Unknown Admin";
                                            }
                                            ?>

                                        </td>

                                        <td>
                                            <span class="activity-action">
                                                <?php echo e($log["action"]); ?>
                                            </span>
                                        </td>

                                        <td class="activity-description">
                                            <?php echo e($log["description"] ?? ""); ?>
                                        </td>

                                        <td class="activity-date">
                                            <?php echo e($log["created_at"]); ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="5" class="activity-empty">

                                        <strong>No Activity Records Found</strong>

                                        Administrative activities will appear here.

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</body>

</html>
