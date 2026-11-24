
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";
require_once "../includes/lead_activity_logger.php";

requirePermission("manage_site_visits");

$adminId = (int) $_SESSION["admin_id"];
$canViewAll = hasPermission("view_all_leads");

$message = "";
$messageType = "success";

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Create a CSRF token if one does not exist.
if (
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"]) ||
    $_SESSION["csrf_token"] === ""
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Display saved message.
if (isset($_SESSION["user_message"])) {
    $message = (string) $_SESSION["user_message"];
    unset($_SESSION["user_message"]);
}

// Handle rescheduling.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Strict CSRF validation.
    $sessionToken = $_SESSION["csrf_token"] ?? null;
    $postedToken = $_POST["csrf_token"] ?? null;

    if (
        !is_string($sessionToken) ||
        $sessionToken === "" ||
        !is_string($postedToken) ||
        $postedToken === "" ||
        !hash_equals($sessionToken, $postedToken)
    ) {
        http_response_code(403);
        exit("Invalid security token. Please refresh the page and try again.");
    }

    // Validate visit ID.
    $visitIdInput = $_POST["visit_id"] ?? null;

    $visitId = (
        is_string($visitIdInput) &&
        ctype_digit($visitIdInput)
    ) ? (int) $visitIdInput : 0;

    // Validate date/time input.
    $newScheduledAt = $_POST["new_scheduled_at"] ?? "";

    if (!is_string($newScheduledAt)) {
        $newScheduledAt = "";
    }

    $newScheduledAt = trim($newScheduledAt);

    if ($visitId <= 0 || $newScheduledAt === "") {

        $message = "Please select a valid visit and enter the new date and time.";
        $messageType = "error";

    } else {

        // Strictly validate datetime-local format.
        $newDate = DateTime::createFromFormat(
            "!Y-m-d\TH:i",
            $newScheduledAt
        );

        $dateErrors = DateTime::getLastErrors();

        $validDate = (
            $newDate !== false &&
            (
                $dateErrors === false ||
                (
                    $dateErrors["warning_count"] === 0 &&
                    $dateErrors["error_count"] === 0
                )
            ) &&
            $newDate->format("Y-m-d\TH:i") === $newScheduledAt
        );

        if (!$validDate) {

            $message = "Please enter a valid new visit date and time.";
            $messageType = "error";

        } else {

            $newScheduledAtForDatabase = $newDate->format("Y-m-d H:i:s");

            try {

                $pdo->beginTransaction();

                // Fetch and lock the visit.
                if ($canViewAll) {

                    $visitCheck = $pdo->prepare("
                        SELECT
                            sv.id,
                            sv.lead_id,
                            sv.scheduled_at,
                            sv.visit_status,
                            sv.assigned_to,
                            l.customer_name
                        FROM site_visits sv
                        INNER JOIN leads l ON sv.lead_id = l.id
                        WHERE sv.id = ?
                        LIMIT 1
                        FOR UPDATE
                    ");

                    $visitCheck->execute([$visitId]);

                } else {

                    $visitCheck = $pdo->prepare("
                        SELECT
                            sv.id,
                            sv.lead_id,
                            sv.scheduled_at,
                            sv.visit_status,
                            sv.assigned_to,
                            l.customer_name
                        FROM site_visits sv
                        INNER JOIN leads l ON sv.lead_id = l.id
                        WHERE sv.id = ?
                        AND sv.assigned_to = ?
                        AND l.assigned_to = ?
                        LIMIT 1
                        FOR UPDATE
                    ");

                    $visitCheck->execute([
                        $visitId,
                        $adminId,
                        $adminId
                    ]);
                }

                $visit = $visitCheck->fetch(PDO::FETCH_ASSOC);

                if (!$visit) {

                    throw new RuntimeException(
                        "VISIT_NOT_FOUND"
                    );
                }

                if (
                    !in_array(
                        $visit["visit_status"],
                        ["scheduled", "rescheduled"],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        "VISIT_STATUS_INVALID"
                    );
                }

                $oldScheduledAt = $visit["scheduled_at"];

                if ($oldScheduledAt === $newScheduledAtForDatabase) {
                    throw new RuntimeException(
                        "SAME_SCHEDULE"
                    );
                }

                // Update visit date/time and status.
                if ($canViewAll) {

                    $update = $pdo->prepare("
                        UPDATE site_visits
                        SET
                            scheduled_at = ?,
                            visit_status = 'rescheduled'
                        WHERE id = ?
                        AND visit_status IN ('scheduled', 'rescheduled')
                    ");

                    $update->execute([
                        $newScheduledAtForDatabase,
                        $visitId
                    ]);

                } else {

                    $update = $pdo->prepare("
                        UPDATE site_visits sv
                        INNER JOIN leads l ON sv.lead_id = l.id
                        SET
                            sv.scheduled_at = ?,
                            sv.visit_status = 'rescheduled'
                        WHERE sv.id = ?
                        AND sv.assigned_to = ?
                        AND l.assigned_to = ?
                        AND sv.visit_status IN ('scheduled', 'rescheduled')
                    ");

                    $update->execute([
                        $newScheduledAtForDatabase,
                        $visitId,
                        $adminId,
                        $adminId
                    ]);
                }

                if ($update->rowCount() !== 1) {
                    throw new RuntimeException(
                        "VISIT_UPDATE_FAILED"
                    );
                }

                $oldDisplay = date(
                    "d-m-Y h:i A",
                    strtotime($oldScheduledAt)
                );

                $newDisplay = $newDate->format("d-m-Y h:i A");

                $activityDescription =
                    "Site visit rescheduled for " .
                    $visit["customer_name"] .
                    ". Previous schedule: " .
                    $oldDisplay .
                    ". New schedule: " .
                    $newDisplay;

                // Record the change in lead activity history.
                logLeadActivity(
                    $pdo,
                    (int) $visit["lead_id"],
                    $adminId,
                    "site_visit_rescheduled",
                    $activityDescription,
                    $oldScheduledAt,
                    $newScheduledAtForDatabase
                );

                $pdo->commit();

                // Rotate CSRF token after successful operation.
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                $_SESSION["user_message"] =
                    "Site visit successfully rescheduled to " .
                    $newDate->format("d-m-Y h:i A") .
                    ". The activity history has been updated.";

                header(
                    "Location: site_visits.php?month=" .
                    $newDate->format("Y-m") .
                    "&day=" .
                    $newDate->format("Y-m-d") .
                    "#daily-schedule"
                );
                exit;

            } catch (RuntimeException $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($exception->getMessage() === "VISIT_NOT_FOUND") {

                    $message = "Visit not found or you are not allowed to reschedule it.";

                } elseif ($exception->getMessage() === "VISIT_STATUS_INVALID") {

                    $message = "Only scheduled or previously rescheduled visits can be rescheduled. Completed and cancelled visits cannot be rescheduled.";

                } elseif ($exception->getMessage() === "SAME_SCHEDULE") {

                    $message = "The new date and time are the same as the current schedule.";

                } else {

                    error_log(
                        "Site visit rescheduling error: " .
                        $exception->getMessage()
                    );

                    $message = "Unable to reschedule the visit. Please try again.";
                }

                $messageType = "error";

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    "Site visit rescheduling error: " .
                    $exception->getMessage()
                );

                $message = "Unable to reschedule the visit due to a system error. Please try again.";
                $messageType = "error";
            }
        }
    }
}

// Get visits available for rescheduling.
if ($canViewAll) {

    $visitQuery = $pdo->query("
        SELECT
            sv.id,
            sv.lead_id,
            sv.scheduled_at,
            sv.visit_status,
            sv.notes,
            sv.assigned_to,
            l.customer_name,
            l.phone,
            l.property_type,
            l.interested_location,
            a.name AS assigned_employee
        FROM site_visits sv
        INNER JOIN leads l ON sv.lead_id = l.id
        LEFT JOIN admins a ON sv.assigned_to = a.id
        WHERE sv.visit_status IN ('scheduled', 'rescheduled')
        ORDER BY sv.scheduled_at ASC
    ");

} else {

    $visitQuery = $pdo->prepare("
        SELECT
            sv.id,
            sv.lead_id,
            sv.scheduled_at,
            sv.visit_status,
            sv.notes,
            sv.assigned_to,
            l.customer_name,
            l.phone,
            l.property_type,
            l.interested_location,
            a.name AS assigned_employee
        FROM site_visits sv
        INNER JOIN leads l ON sv.lead_id = l.id
        LEFT JOIN admins a ON sv.assigned_to = a.id
        WHERE sv.assigned_to = ?
        AND l.assigned_to = ?
        AND sv.visit_status IN ('scheduled', 'rescheduled')
        ORDER BY sv.scheduled_at ASC
    ");

    $visitQuery->execute([
        $adminId,
        $adminId
    ]);
}

$visits = $visitQuery->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reschedule Site Visits</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #333;
        }

        .container {
            max-width: 1250px;
            margin: auto;
        }

        h1, h2 {
            color: #222;
        }

        .card {
            background: white;
            padding: 22px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        }

        .nav-links {
            margin-bottom: 20px;
        }

        .nav-links a {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 8px;
            color: #1769aa;
            text-decoration: none;
            font-weight: bold;
        }

        .nav-links a:hover {
            text-decoration: underline;
        }

        .message {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 18px;
            background: #e6f5e9;
            color: #216b32;
        }

        .message.error {
            background: #fde8e8;
            color: #a32222;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f0f2f5;
        }

        .property-details {
            line-height: 1.6;
        }

        .property-details strong {
            display: block;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            background: #e5f1ff;
            color: #174b7a;
        }

        .status.rescheduled {
            background: #fff3dc;
            color: #80540b;
        }

        .reschedule-form {
            min-width: 220px;
        }

        .reschedule-form input {
            width: 100%;
            padding: 9px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
            margin-bottom: 8px;
        }

        button {
            padding: 9px 13px;
            background: #1769aa;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        button:hover {
            background: #10548b;
        }

        .empty {
            color: #777;
        }

        .information {
            background: #eef6ff;
            padding: 14px;
            border-radius: 6px;
            margin-bottom: 18px;
            line-height: 1.6;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .card {
                padding: 14px;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <h1>Reschedule Site Visits</h1>

    <p>Change the date and time of an upcoming property visit.</p>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="site_visits.php">Monthly Site Visit Calendar</a>
        <a href="weekly_site_visits.php">Weekly Site Visit Schedule</a>
        <a href="manage_leads.php">Manage Leads</a>
    </div>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo $messageType === "error" ? "error" : ""; ?>">
            <?php echo e($message); ?>
        </div>

    <?php endif; ?>

    <div class="card">

        <h2>Upcoming Visits Available for Rescheduling</h2>

        <div class="information">
            You can reschedule visits that are currently Scheduled or Rescheduled.
            Completed and Cancelled visits cannot be rescheduled.
            The previous and new date/time will be recorded in the lead activity history.
        </div>

        <?php if (count($visits) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Visit ID</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Property Details</th>
                            <th>Current Date and Time</th>
                            <th>Assigned Employee</th>
                            <th>Status</th>
                            <th>Reschedule Visit</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($visits as $visit): ?>

                            <tr>

                                <td>
                                    <?php echo (int) $visit["id"]; ?>
                                </td>

                                <td>
                                    <strong>
                                        <?php echo e($visit["customer_name"]); ?>
                                    </strong>
                                </td>

                                <td>
                                    <?php echo e($visit["phone"]); ?>
                                </td>

                                <td class="property-details">

                                    <strong>
                                        <?php
                                        echo e(
                                            $visit["property_type"] ??
                                            "Not specified"
                                        );
                                        ?>
                                    </strong>

                                    <?php
                                    echo e(
                                        $visit["interested_location"] ??
                                        "Location not specified"
                                    );
                                    ?>

                                </td>

                                <td>
                                    <?php
                                    echo e(
                                        date(
                                            "d-m-Y h:i A",
                                            strtotime($visit["scheduled_at"])
                                        )
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo e(
                                        $visit["assigned_employee"] ??
                                        "Not Assigned"
                                    );
                                    ?>
                                </td>

                                <td>

                                    <span class="status <?php echo e($visit["visit_status"]); ?>">
                                        <?php
                                        echo e(
                                            ucwords(
                                                str_replace(
                                                    "_",
                                                    " ",
                                                    $visit["visit_status"]
                                                )
                                            )
                                        );
                                        ?>
                                    </span>

                                </td>

                                <td>

                                    <form
                                        method="POST"
                                        action="reschedule_site_visits.php"
                                        class="reschedule-form"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php echo e($_SESSION["csrf_token"]); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="visit_id"
                                            value="<?php echo (int) $visit["id"]; ?>"
                                        >

                                        <label>
                                            New Date and Time
                                        </label>

                                        <input
                                            type="datetime-local"
                                            name="new_scheduled_at"
                                            value="<?php
                                                echo e(
                                                    date(
                                                        "Y-m-d\TH:i",
                                                        strtotime($visit["scheduled_at"])
                                                    )
                                                );
                                            ?>"
                                            required
                                        >

                                        <button type="submit">
                                            Reschedule Visit
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">
                There are no scheduled or rescheduled visits available.
            </p>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
