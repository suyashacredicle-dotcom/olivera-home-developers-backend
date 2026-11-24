
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";
require_once "../includes/lead_activity_logger.php";

requirePermission("manage_followups");

$message = "";
$messageType = "success";

$adminId = (int)($_SESSION["admin_id"] ?? 0);
$canViewAllLeads = hasPermission("view_all_leads");

// Escape output safely.
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Create a valid CSRF token.
if (
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"]) ||
    $_SESSION["csrf_token"] === ""
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["csrf_token"];

// Display saved message.
if (isset($_SESSION["user_message"])) {
    $message = $_SESSION["user_message"];
    $messageType = $_SESSION["user_message_type"] ?? "success";

    unset($_SESSION["user_message"], $_SESSION["user_message_type"]);
}

// --------------------------------------------------
// SAVE FOLLOW-UP
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Strict CSRF validation.
    if (
        !isset($_SESSION["csrf_token"]) ||
        !is_string($_SESSION["csrf_token"]) ||
        !is_string($_POST["csrf_token"] ?? null) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {

        http_response_code(403);
        exit("Invalid security token. Please refresh the page and try again.");

    }

    $leadIdInput = $_POST["lead_id"] ?? null;
    $followupType = $_POST["followup_type"] ?? null;
    $notesInput = $_POST["notes"] ?? null;
    $nextFollowupInput = $_POST["next_followup_at"] ?? "";

    $allowedTypes = [
        "call",
        "email",
        "whatsapp",
        "meeting",
        "note"
    ];

    // Validate input types.
    if (
        !is_scalar($leadIdInput) ||
        !is_string($followupType) ||
        !is_string($notesInput) ||
        !is_string($nextFollowupInput)
    ) {

        $message = "Invalid form information.";
        $messageType = "error";

    } else {

        $leadId = filter_var(
            $leadIdInput,
            FILTER_VALIDATE_INT,
            ["options" => ["min_range" => 1]]
        );

        $notes = trim($notesInput);
        $nextFollowup = trim($nextFollowupInput);

        if (
            $leadId === false ||
            $leadId === null ||
            !in_array($followupType, $allowedTypes, true)
        ) {

            $message = "Please select a valid customer and follow-up type.";
            $messageType = "error";

        } elseif ($notes === "") {

            $message = "Please enter follow-up notes.";
            $messageType = "error";

        } elseif (strlen($notes) > 10000) {

            $message = "Follow-up notes cannot exceed 10,000 characters.";
            $messageType = "error";

        } elseif (strlen($nextFollowup) > 16) {

            $message = "Invalid next follow-up date and time.";
            $messageType = "error";

        } else {

            try {

                // Check lead ownership and access.
                if ($canViewAllLeads) {

                    $checkLead = $pdo->prepare("
                        SELECT id, customer_name
                        FROM leads
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $checkLead->execute([$leadId]);

                } else {

                    $checkLead = $pdo->prepare("
                        SELECT id, customer_name
                        FROM leads
                        WHERE id = ?
                        AND assigned_to = ?
                        LIMIT 1
                    ");

                    $checkLead->execute([$leadId, $adminId]);
                }

                $lead = $checkLead->fetch(PDO::FETCH_ASSOC);

                if (!$lead) {

                    $message = "You are not allowed to add a follow-up for this lead.";
                    $messageType = "error";

                } else {

                    // Validate optional date and time.
                    $nextFollowupValue = null;

                    if ($nextFollowup !== "") {

                        $date = DateTime::createFromFormat(
                            "!Y-m-d\TH:i",
                            $nextFollowup
                        );

                        $dateErrors = DateTime::getLastErrors();

                        if (
                            !$date ||
                            (
                                $dateErrors !== false &&
                                (
                                    $dateErrors["warning_count"] > 0 ||
                                    $dateErrors["error_count"] > 0
                                )
                            ) ||
                            $date->format("Y-m-d\TH:i") !== $nextFollowup
                        ) {

                            $message = "Please enter a valid next follow-up date and time.";
                            $messageType = "error";

                        } else {

                            $nextFollowupValue = $date->format("Y-m-d H:i:s");
                        }
                    }

                    if ($message === "") {

                        // Start transaction.
                        $pdo->beginTransaction();

                        // Insert follow-up record.
                        $insert = $pdo->prepare("
                            INSERT INTO lead_followups
                            (
                                lead_id,
                                admin_id,
                                followup_type,
                                notes,
                                next_followup_at
                            )
                            VALUES (?, ?, ?, ?, ?)
                        ");

                        $insert->execute([
                            $leadId,
                            $adminId,
                            $followupType,
                            $notes,
                            $nextFollowupValue
                        ]);

                        // Record activity in lead timeline.
                        $followupDescription =
                            "Follow-up added (" .
                            ucwords($followupType) .
                            "): " .
                            $notes;

                        if ($nextFollowupValue !== null) {

                            $followupDescription .=
                                " Next follow-up: " . $nextFollowupValue;
                        }

                        logLeadActivity(
                            $pdo,
                            $leadId,
                            $adminId,
                            "followup_added",
                            $followupDescription,
                            null,
                            $followupType
                        );

                        $pdo->commit();

                        // Rotate CSRF token after successful action.
                        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                        $_SESSION["user_message"] = "Follow-up saved successfully!";
                        $_SESSION["user_message_type"] = "success";

                        header("Location: lead_followups.php");
                        exit;
                    }
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    "Lead follow-up save error: " . $e->getMessage()
                );

                $message = "Unable to save the follow-up. Please try again.";
                $messageType = "error";
            }
        }
    }
}

// --------------------------------------------------
// FETCH AVAILABLE LEADS
// --------------------------------------------------

try {

    if ($canViewAllLeads) {

        $leadQuery = $pdo->query("
            SELECT id, customer_name, phone
            FROM leads
            ORDER BY customer_name ASC
        ");

    } else {

        $leadQuery = $pdo->prepare("
            SELECT id, customer_name, phone
            FROM leads
            WHERE assigned_to = ?
            ORDER BY customer_name ASC
        ");

        $leadQuery->execute([$adminId]);
    }

    $leads = $leadQuery->fetchAll(PDO::FETCH_ASSOC);

    // --------------------------------------------------
    // FETCH FOLLOW-UP HISTORY
    // --------------------------------------------------

    if ($canViewAllLeads) {

        $historyQuery = $pdo->query("
            SELECT
                lf.id,
                lf.lead_id,
                lf.followup_type,
                lf.notes,
                lf.next_followup_at,
                lf.created_at,
                l.customer_name,
                a.name AS employee_name
            FROM lead_followups lf
            INNER JOIN leads l ON lf.lead_id = l.id
            LEFT JOIN admins a ON lf.admin_id = a.id
            ORDER BY lf.created_at DESC
        ");

    } else {

        $historyQuery = $pdo->prepare("
            SELECT
                lf.id,
                lf.lead_id,
                lf.followup_type,
                lf.notes,
                lf.next_followup_at,
                lf.created_at,
                l.customer_name,
                a.name AS employee_name
            FROM lead_followups lf
            INNER JOIN leads l ON lf.lead_id = l.id
            LEFT JOIN admins a ON lf.admin_id = a.id
            WHERE l.assigned_to = ?
            ORDER BY lf.created_at DESC
        ");

        $historyQuery->execute([$adminId]);
    }

    $followups = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log(
        "Lead follow-up page loading error: " . $e->getMessage()
    );

    $leads = [];
    $followups = [];

    $message = "Unable to load follow-up information. Please try again.";
    $messageType = "error";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Lead Follow-ups</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        h1, h2 {
            color: #222;
        }

        .card {
            background: #fff;
            padding: 22px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        }

        label {
            display: block;
            margin: 14px 0 7px;
            font-weight: bold;
        }

        input, select, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
            font-size: 14px;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        button {
            margin-top: 18px;
            padding: 11px 22px;
            background: #1769aa;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 15px;
        }

        button:hover {
            background: #10548b;
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
            min-width: 750px;
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

        .nav-links {
            margin-bottom: 20px;
        }

        .nav-links a {
            display: inline-block;
            margin-right: 15px;
            color: #1769aa;
            text-decoration: none;
            font-weight: bold;
        }

        .empty {
            color: #777;
            padding: 15px 0;
        }

    </style>

</head>

<body>

<div class="container">

    <h1>Lead Follow-ups</h1>

    <p>Record and track customer interactions.</p>

    <div class="nav-links">

        <a href="dashboard.php">Dashboard</a>
        <a href="manage_leads.php">Manage Leads</a>

    </div>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo $messageType === 'error' ? 'error' : ''; ?>">

            <?php echo e($message); ?>

        </div>

    <?php endif; ?>

    <div class="card">

        <h2>Add New Follow-up</h2>

        <?php if (count($leads) > 0): ?>

            <form method="POST" action="lead_followups.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($_SESSION["csrf_token"]); ?>"
                >

                <label for="lead_id">Select Customer *</label>

                <select name="lead_id" id="lead_id" required>

                    <option value="">-- Select Customer --</option>

                    <?php foreach ($leads as $lead): ?>

                        <option value="<?php echo (int)$lead["id"]; ?>">

                            <?php
                            echo e($lead["customer_name"]);
                            echo " - ";
                            echo e($lead["phone"]);
                            ?>

                        </option>

                    <?php endforeach; ?>

                </select>

                <label for="followup_type">Follow-up Type *</label>

                <select name="followup_type" id="followup_type" required>

                    <option value="">-- Select Type --</option>
                    <option value="call">Phone Call</option>
                    <option value="email">Email</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="meeting">Meeting</option>
                    <option value="note">Note</option>

                </select>

                <label for="notes">Discussion / Follow-up Notes *</label>

                <textarea
                    name="notes"
                    id="notes"
                    maxlength="10000"
                    placeholder="Write what happened during the customer interaction..."
                    required
                ></textarea>

                <label for="next_followup_at">
                    Next Follow-up Date and Time (Optional)
                </label>

                <input
                    type="datetime-local"
                    name="next_followup_at"
                    id="next_followup_at"
                >

                <button type="submit">Save Follow-up</button>

            </form>

        <?php else: ?>

            <p class="empty">
                No leads are currently assigned or available to you.
            </p>

        <?php endif; ?>

    </div>

    <div class="card">

        <h2>Customer Follow-up History</h2>

        <?php if (count($followups) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>ID</th>
                            <th>Customer</th>
                            <th>Follow-up Type</th>
                            <th>Notes</th>
                            <th>Next Follow-up</th>
                            <th>Added By</th>
                            <th>Created Date</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($followups as $followup): ?>

                            <tr>

                                <td>
                                    <?php echo (int)$followup["id"]; ?>
                                </td>

                                <td>
                                    <?php echo e($followup["customer_name"]); ?>
                                </td>

                                <td>

                                    <?php
                                    echo e(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $followup["followup_type"]
                                            )
                                        )
                                    );
                                    ?>

                                </td>

                                <td>
                                    <?php echo nl2br(e($followup["notes"])); ?>
                                </td>

                                <td>

                                    <?php
                                    echo $followup["next_followup_at"]
                                        ? e($followup["next_followup_at"])
                                        : "Not scheduled";
                                    ?>

                                </td>

                                <td>
                                    <?php echo e($followup["employee_name"] ?? "Deleted User"); ?>
                                </td>

                                <td>
                                    <?php echo e($followup["created_at"]); ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">
                No follow-up records found yet.
            </p>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
