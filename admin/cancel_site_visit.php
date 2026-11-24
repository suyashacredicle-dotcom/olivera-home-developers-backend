
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

// Create CSRF token if missing or invalid.
if (
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"]) ||
    $_SESSION["csrf_token"] === ""
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Display flash message.
if (isset($_SESSION["user_message"])) {
    $message = (string) $_SESSION["user_message"];
    unset($_SESSION["user_message"]);
}

// Handle cancellation.
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

    // Validate cancellation reason.
    $reasonInput = $_POST["cancellation_reason"] ?? "";

    if (!is_string($reasonInput)) {
        $reasonInput = "";
    }

    $reason = trim($reasonInput);

    $reasonLength = function_exists("mb_strlen")
        ? mb_strlen($reason, "UTF-8")
        : strlen($reason);

    if ($visitId <= 0) {

        $message = "Invalid site visit selected.";
        $messageType = "error";

    } elseif ($reason === "") {

        $message = "Please enter a cancellation reason.";
        $messageType = "error";

    } elseif ($reasonLength > 1000) {

        $message = "Cancellation reason cannot exceed 1000 characters.";
        $messageType = "error";

    } else {

        try {

            $pdo->beginTransaction();

            // Fetch and lock visit while checking access permissions.
            if ($canViewAll) {

                $check = $pdo->prepare("
                    SELECT
                        sv.id,
                        sv.lead_id,
                        sv.visit_status,
                        sv.scheduled_at,
                        l.customer_name
                    FROM site_visits sv
                    INNER JOIN leads l ON sv.lead_id = l.id
                    WHERE sv.id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $check->execute([$visitId]);

            } else {

                $check = $pdo->prepare("
                    SELECT
                        sv.id,
                        sv.lead_id,
                        sv.visit_status,
                        sv.scheduled_at,
                        l.customer_name
                    FROM site_visits sv
                    INNER JOIN leads l ON sv.lead_id = l.id
                    WHERE sv.id = ?
                    AND sv.assigned_to = ?
                    AND l.assigned_to = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $check->execute([
                    $visitId,
                    $adminId,
                    $adminId
                ]);
            }

            $visit = $check->fetch(PDO::FETCH_ASSOC);

            if (!$visit) {
                throw new RuntimeException("VISIT_NOT_FOUND");
            }

            if (
                !in_array(
                    $visit["visit_status"],
                    ["scheduled", "rescheduled"],
                    true
                )
            ) {
                throw new RuntimeException("VISIT_STATUS_INVALID");
            }

            $oldStatus = $visit["visit_status"];

            // Save cancellation details.
            $update = $pdo->prepare("
                UPDATE site_visits
                SET
                    visit_status = 'cancelled',
                    cancellation_reason = ?,
                    cancelled_by = ?,
                    cancelled_at = NOW()
                WHERE id = ?
                AND visit_status IN ('scheduled', 'rescheduled')
            ");

            $update->execute([
                $reason,
                $adminId,
                $visitId
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException("VISIT_UPDATE_FAILED");
            }

            // Record cancellation in lead activity history.
            $activityDescription =
                "Site visit cancelled. Previous status: " .
                ucwords(str_replace("_", " ", $oldStatus)) .
                ". Scheduled date: " .
                date("d-m-Y h:i A", strtotime($visit["scheduled_at"])) .
                ". Cancellation reason: " .
                $reason;

            logLeadActivity(
                $pdo,
                (int) $visit["lead_id"],
                $adminId,
                "site_visit_cancelled",
                $activityDescription,
                $oldStatus,
                "cancelled"
            );

            // Commit cancellation and activity history together.
            $pdo->commit();

            // Rotate CSRF token after successful cancellation.
            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

            $_SESSION["user_message"] =
                "Site visit for " .
                $visit["customer_name"] .
                " was cancelled successfully. The reason has been recorded.";

            header("Location: cancel_site_visit.php");
            exit;

        } catch (RuntimeException $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($exception->getMessage() === "VISIT_NOT_FOUND") {

                $message = "Visit not found or you are not allowed to cancel it.";

            } elseif ($exception->getMessage() === "VISIT_STATUS_INVALID") {

                $message = "Only scheduled or rescheduled visits can be cancelled. Completed or already cancelled visits cannot be cancelled again.";

            } else {

                error_log(
                    "Site visit cancellation error: " .
                    $exception->getMessage()
                );

                $message = "Unable to cancel this visit. Please try again.";
            }

            $messageType = "error";

        } catch (Throwable $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                "Site visit cancellation error: " .
                $exception->getMessage()
            );

            $message = "Unable to cancel this visit due to a system error. Please try again.";
            $messageType = "error";
        }
    }
}

// Fetch site visits for display.
if ($canViewAll) {

    $visitQuery = $pdo->query("
        SELECT
            sv.id,
            sv.lead_id,
            sv.scheduled_at,
            sv.visit_status,
            sv.notes,
            sv.cancellation_reason,
            sv.cancelled_at,
            sv.cancelled_by,
            l.customer_name,
            l.phone,
            l.property_type,
            l.interested_location,
            assigned.name AS assigned_employee,
            canceller.name AS cancelled_by_name
        FROM site_visits sv
        INNER JOIN leads l ON sv.lead_id = l.id
        LEFT JOIN admins assigned
            ON sv.assigned_to = assigned.id
        LEFT JOIN admins canceller
            ON sv.cancelled_by = canceller.id
        ORDER BY sv.scheduled_at DESC
    ");

} else {

    $visitQuery = $pdo->prepare("
        SELECT
            sv.id,
            sv.lead_id,
            sv.scheduled_at,
            sv.visit_status,
            sv.notes,
            sv.cancellation_reason,
            sv.cancelled_at,
            sv.cancelled_by,
            l.customer_name,
            l.phone,
            l.property_type,
            l.interested_location,
            assigned.name AS assigned_employee,
            canceller.name AS cancelled_by_name
        FROM site_visits sv
        INNER JOIN leads l ON sv.lead_id = l.id
        LEFT JOIN admins assigned
            ON sv.assigned_to = assigned.id
        LEFT JOIN admins canceller
            ON sv.cancelled_by = canceller.id
        WHERE sv.assigned_to = ?
        AND l.assigned_to = ?
        ORDER BY sv.scheduled_at DESC
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

    <title>Cancel Site Visits</title>

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

        .card {
            background: white;
            padding: 22px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        }

        h1, h2 {
            color: #222;
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

        .error {
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

        label {
            display: block;
            margin: 12px 0 7px;
            font-weight: bold;
        }

        textarea {
            width: 100%;
            min-height: 75px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
            resize: vertical;
        }

        button {
            padding: 9px 14px;
            background: #c62828;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        button:hover {
            background: #a51f1f;
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

        .status.completed {
            background: #e5f5e9;
            color: #216b32;
        }

        .status.cancelled {
            background: #fde8e8;
            color: #a32222;
        }

        .status.rescheduled {
            background: #fff3dc;
            color: #80540b;
        }

        .reason {
            color: #a32222;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .empty {
            color: #777;
        }

        .property {
            line-height: 1.6;
        }

        .cancel-form {
            min-width: 230px;
        }

        .cancelled-details {
            min-width: 200px;
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

    <h1>Site Visit Cancellation Management</h1>

    <p>
        Cancel scheduled site visits and maintain a record of the cancellation reason.
    </p>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="site_visits.php">Monthly Calendar</a>
        <a href="weekly_site_visits.php">Weekly Schedule</a>
        <a href="reschedule_site_visits.php">Reschedule Visits</a>
        <a href="site_visit_reschedule_history.php">Rescheduling History</a>
        <a href="manage_leads.php">Manage Leads</a>
    </div>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo $messageType === "error" ? "error" : ""; ?>">
            <?php echo e($message); ?>
        </div>

    <?php endif; ?>

    <div class="card">

        <h2>Site Visit Cancellation Records</h2>

        <?php if (count($visits) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Visit ID</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Property Details</th>
                            <th>Visit Date and Time</th>
                            <th>Assigned Employee</th>
                            <th>Status</th>
                            <th>Cancellation Reason</th>
                            <th>Cancelled By</th>
                            <th>Cancelled At</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($visits as $visit): ?>

                            <tr>

                                <td>
                                    <?php echo (int) $visit["id"]; ?>
                                </td>

                                <td>
                                    <?php echo e($visit["customer_name"]); ?>
                                </td>

                                <td>
                                    <?php echo e($visit["phone"]); ?>
                                </td>

                                <td class="property">

                                    <strong>
                                        <?php echo e($visit["property_type"] ?? "Not specified"); ?>
                                    </strong>

                                    <?php echo e($visit["interested_location"] ?? "Location not specified"); ?>

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
                                    <?php echo e($visit["assigned_employee"] ?? "Not Assigned"); ?>
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

                                <td class="cancelled-details">

                                    <?php if ($visit["visit_status"] === "cancelled"): ?>

                                        <div class="reason">
                                            <?php
                                            echo nl2br(
                                                e(
                                                    $visit["cancellation_reason"] ??
                                                    "Reason not recorded"
                                                )
                                            );
                                            ?>
                                        </div>

                                    <?php else: ?>

                                        Not cancelled

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?php
                                    echo $visit["visit_status"] === "cancelled"
                                        ? e($visit["cancelled_by_name"] ?? "Deleted User")
                                        : "-";
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo $visit["cancelled_at"]
                                        ? e(
                                            date(
                                                "d-m-Y h:i A",
                                                strtotime($visit["cancelled_at"])
                                            )
                                        )
                                        : "-";
                                    ?>
                                </td>

                                <td class="cancel-form">

                                    <?php if (
                                        in_array(
                                            $visit["visit_status"],
                                            ["scheduled", "rescheduled"],
                                            true
                                        )
                                    ): ?>

                                        <form
                                            method="POST"
                                            action="cancel_site_visit.php"
                                            onsubmit="return confirm('Are you sure you want to cancel this site visit?');"
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
                                                Cancellation Reason *
                                            </label>

                                            <textarea
                                                name="cancellation_reason"
                                                maxlength="1000"
                                                required
                                                placeholder="Explain why this site visit is being cancelled..."
                                            ></textarea>

                                            <button type="submit">
                                                Cancel Visit
                                            </button>

                                        </form>

                                    <?php elseif ($visit["visit_status"] === "cancelled"): ?>

                                        <strong>Already Cancelled</strong>

                                    <?php else: ?>

                                        <span class="empty">
                                            Completed visits cannot be cancelled.
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">
                No site visits found.
            </p>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
