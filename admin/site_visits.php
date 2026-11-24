
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";
require_once "../includes/lead_activity_logger.php";

requirePermission("manage_site_visits");

$message = "";
$messageType = "success";

$adminId = (int)($_SESSION["admin_id"] ?? 0);
$canViewAll = hasPermission("view_all_leads");

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function validDateValue($value) {
    if (!is_string($value) || $value === "") {
        return false;
    }

    $date = DateTime::createFromFormat("!Y-m-d", $value);
    return $date && $date->format("Y-m-d") === $value;
}

function redirectToSchedule($month, $day, $anchor = "") {
    $url = "site_visits.php?" . http_build_query([
        "month" => $month,
        "day" => $day
    ]);

    if ($anchor !== "") {
        $url .= "#" . $anchor;
    }

    header("Location: " . $url);
    exit;
}

// --------------------------------------------------
// CSRF AND FLASH MESSAGE
// --------------------------------------------------

if (
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"]) ||
    $_SESSION["csrf_token"] === ""
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if (isset($_SESSION["user_message"])) {
    $message = is_string($_SESSION["user_message"])
        ? $_SESSION["user_message"]
        : "";

    unset($_SESSION["user_message"]);
}

$allowedStatuses = ["scheduled", "completed", "rescheduled"];

// --------------------------------------------------
// CALENDAR MONTH
// --------------------------------------------------

$requestedMonth = $_GET["month"] ?? date("Y-m");

if (!is_string($requestedMonth)) {
    $requestedMonth = "";
}

$calendarDate = DateTime::createFromFormat("!Y-m", $requestedMonth);

if (!$calendarDate || $calendarDate->format("Y-m") !== $requestedMonth) {
    $calendarDate = new DateTime("first day of this month");
}

$calendarMonth = $calendarDate->format("Y-m");
$calendarTitle = $calendarDate->format("F Y");

$previousMonth = (clone $calendarDate)->modify("-1 month")->format("Y-m");
$nextMonth = (clone $calendarDate)->modify("+1 month")->format("Y-m");

// --------------------------------------------------
// SELECTED DATE
// --------------------------------------------------

$requestedDay = $_GET["day"] ?? date("Y-m-d");

if (!is_string($requestedDay)) {
    $requestedDay = "";
}

$selectedDateObject = DateTime::createFromFormat("!Y-m-d", $requestedDay);

if (
    !$selectedDateObject ||
    $selectedDateObject->format("Y-m-d") !== $requestedDay
) {
    $selectedDateObject = new DateTime("today");
}

$selectedDate = $selectedDateObject->format("Y-m-d");
$selectedDateDisplay = $selectedDateObject->format("l, d F Y");

// --------------------------------------------------
// FILTERS
// --------------------------------------------------

$search = $_GET["search"] ?? "";
$filterStatus = $_GET["status"] ?? "";
$filterEmployee = $_GET["employee"] ?? "";
$filterProperty = $_GET["property_type"] ?? "";
$filterStartDate = $_GET["start_date"] ?? "";
$filterEndDate = $_GET["end_date"] ?? "";

$filterErrors = [];

$filterValues = [
    "search" => &$search,
    "status" => &$filterStatus,
    "employee" => &$filterEmployee,
    "property_type" => &$filterProperty,
    "start_date" => &$filterStartDate,
    "end_date" => &$filterEndDate
];

foreach ($filterValues as &$value) {
    if (!is_string($value)) {
        $value = "";
        $filterErrors[] = "Invalid filter value was ignored.";
    }
}
unset($value);

$search = trim($search);
$filterStatus = trim($filterStatus);
$filterEmployee = trim($filterEmployee);
$filterProperty = trim($filterProperty);
$filterStartDate = trim($filterStartDate);
$filterEndDate = trim($filterEndDate);

if (strlen($search) > 200) {
    $search = substr($search, 0, 200);
    $filterErrors[] = "Search text was shortened to the allowed length.";
}

if (strlen($filterProperty) > 100) {
    $filterProperty = "";
    $filterErrors[] = "Invalid property type filter was ignored.";
}

$validFilterStatuses = [
    "scheduled",
    "completed",
    "cancelled",
    "rescheduled"
];

if (
    $filterStatus !== "" &&
    !in_array($filterStatus, $validFilterStatuses, true)
) {
    $filterStatus = "";
    $filterErrors[] = "Invalid status filter was ignored.";
}

if (
    $filterEmployee !== "" &&
    (!ctype_digit($filterEmployee) || (int)$filterEmployee < 0)
) {
    $filterEmployee = "";
    $filterErrors[] = "Invalid employee filter was ignored.";
}

if (!$canViewAll) {
    $filterEmployee = "";
}

if ($filterStartDate !== "" && !validDateValue($filterStartDate)) {
    $filterStartDate = "";
    $filterErrors[] = "Invalid start date was ignored.";
}

if ($filterEndDate !== "" && !validDateValue($filterEndDate)) {
    $filterEndDate = "";
    $filterErrors[] = "Invalid end date was ignored.";
}

if (
    $filterStartDate !== "" &&
    $filterEndDate !== "" &&
    $filterStartDate > $filterEndDate
) {
    $filterStartDate = "";
    $filterEndDate = "";
    $filterErrors[] = "Start date cannot be later than end date. Date filters were cleared.";
}

if ($filterErrors) {
    $message = implode(" ", $filterErrors);
    $messageType = "error";
}

// --------------------------------------------------
// HANDLE POST REQUESTS
// --------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken = $_POST["csrf_token"] ?? null;
    $sessionToken = $_SESSION["csrf_token"] ?? null;

    if (
        !is_string($sessionToken) ||
        $sessionToken === "" ||
        !is_string($submittedToken) ||
        $submittedToken === "" ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);
        exit("Invalid security token. Please refresh the page.");
    }

    $action = $_POST["action"] ?? "";

    if (!is_string($action)) {
        $action = "";
    }

    // Schedule a new visit.
    if ($action === "add_visit") {

        $leadIdValue = $_POST["lead_id"] ?? "";
        $scheduledAt = $_POST["scheduled_at"] ?? "";
        $notes = $_POST["notes"] ?? "";

        if (
            !is_string($leadIdValue) ||
            !ctype_digit($leadIdValue) ||
            (int)$leadIdValue < 1 ||
            !is_string($scheduledAt) ||
            !is_string($notes)
        ) {
            $message = "Please provide valid customer and visit details.";
            $messageType = "error";

        } elseif (trim($scheduledAt) === "") {
            $message = "Please select a visit date and time.";
            $messageType = "error";

        } elseif (strlen($notes) > 10000) {
            $message = "Visit notes cannot exceed 10,000 characters.";
            $messageType = "error";

        } else {

            $leadId = (int)$leadIdValue;
            $notes = trim($notes);

            try {

                if ($canViewAll) {
                    $leadCheck = $pdo->prepare("
                        SELECT id, assigned_to, customer_name
                        FROM leads
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $leadCheck->execute([$leadId]);

                } else {
                    $leadCheck = $pdo->prepare("
                        SELECT id, assigned_to, customer_name
                        FROM leads
                        WHERE id = ?
                        AND assigned_to = ?
                        LIMIT 1
                    ");
                    $leadCheck->execute([$leadId, $adminId]);
                }

                $lead = $leadCheck->fetch(PDO::FETCH_ASSOC);

                if (!$lead) {
                    $message = "You are not allowed to schedule a visit for this customer.";
                    $messageType = "error";

                } else {

                    $date = DateTime::createFromFormat("!Y-m-d\TH:i", $scheduledAt);

                    if (!$date || $date->format("Y-m-d\TH:i") !== $scheduledAt) {
                        $message = "Please enter a valid visit date and time.";
                        $messageType = "error";

                    } else {

                        $pdo->beginTransaction();

                        $insert = $pdo->prepare("
                            INSERT INTO site_visits
                            (
                                lead_id,
                                assigned_to,
                                created_by,
                                scheduled_at,
                                visit_status,
                                notes
                            )
                            VALUES (?, ?, ?, ?, 'scheduled', ?)
                        ");

                        $insert->execute([
                            $leadId,
                            $lead["assigned_to"],
                            $adminId,
                            $date->format("Y-m-d H:i:s"),
                            $notes
                        ]);

                        $activityDescription =
                            "Site visit scheduled for " .
                            $date->format("d-m-Y h:i A");

                        if ($notes !== "") {
                            $activityDescription .= ". Notes: " . $notes;
                        }

                        logLeadActivity(
                            $pdo,
                            $leadId,
                            $adminId,
                            "site_visit_scheduled",
                            $activityDescription,
                            null,
                            "scheduled"
                        );

                        $pdo->commit();

                        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
                        $_SESSION["user_message"] = "Site visit scheduled successfully!";

                        redirectToSchedule(
                            $calendarMonth,
                            $date->format("Y-m-d")
                        );
                    }
                }

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Site visit scheduling error: " . $exception->getMessage());

                $message = "Unable to schedule the site visit. Please try again.";
                $messageType = "error";
            }
        }

    // Update an existing visit status.
    } elseif ($action === "update_status") {

        $visitIdValue = $_POST["visit_id"] ?? "";
        $newStatus = $_POST["visit_status"] ?? "";

        if (
            !is_string($visitIdValue) ||
            !ctype_digit($visitIdValue) ||
            (int)$visitIdValue < 1 ||
            !is_string($newStatus) ||
            !in_array($newStatus, $allowedStatuses, true)
        ) {
            $message = "Invalid visit or status selected. Cancellation must be completed through the cancellation page.";
            $messageType = "error";

        } else {

            $visitId = (int)$visitIdValue;

            try {

                $pdo->beginTransaction();

                if ($canViewAll) {
                    $visitCheck = $pdo->prepare("
                        SELECT
                            sv.id,
                            sv.lead_id,
                            sv.visit_status,
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
                            sv.visit_status,
                            l.customer_name
                        FROM site_visits sv
                        INNER JOIN leads l ON sv.lead_id = l.id
                        WHERE sv.id = ?
                        AND sv.assigned_to = ?
                        AND l.assigned_to = ?
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $visitCheck->execute([$visitId, $adminId, $adminId]);
                }

                $visit = $visitCheck->fetch(PDO::FETCH_ASSOC);

                if (!$visit) {
                    $pdo->rollBack();
                    $message = "Visit not found or you are not allowed to update it.";
                    $messageType = "error";

                } elseif ($visit["visit_status"] === "cancelled") {
                    $pdo->rollBack();
                    $message = "A cancelled visit cannot be changed through this page.";
                    $messageType = "error";

                } else {

                    $oldStatus = $visit["visit_status"];

                    if ($oldStatus === $newStatus) {
                        $pdo->rollBack();

                        $_SESSION["user_message"] =
                            "The site visit already has this status.";

                        redirectToSchedule(
                            $calendarMonth,
                            $selectedDate,
                            "daily-schedule"
                        );

                    } else {

                        if ($canViewAll) {
                            $update = $pdo->prepare("
                                UPDATE site_visits
                                SET visit_status = ?
                                WHERE id = ?
                            ");
                            $update->execute([$newStatus, $visitId]);

                        } else {
                            $update = $pdo->prepare("
                                UPDATE site_visits sv
                                INNER JOIN leads l ON sv.lead_id = l.id
                                SET sv.visit_status = ?
                                WHERE sv.id = ?
                                AND sv.assigned_to = ?
                                AND l.assigned_to = ?
                            ");
                            $update->execute([
                                $newStatus,
                                $visitId,
                                $adminId,
                                $adminId
                            ]);
                        }

                        if ($update->rowCount() > 0) {

                            $oldStatusText = ucwords(str_replace("_", " ", $oldStatus));
                            $newStatusText = ucwords(str_replace("_", " ", $newStatus));

                            $activityDescription =
                                "Site visit status changed from " .
                                $oldStatusText .
                                " to " .
                                $newStatusText;

                            logLeadActivity(
                                $pdo,
                                (int)$visit["lead_id"],
                                $adminId,
                                "site_visit_status_changed",
                                $activityDescription,
                                $oldStatus,
                                $newStatus
                            );

                            $pdo->commit();

                            $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
                            $_SESSION["user_message"] =
                                "Site visit status updated successfully!";

                            redirectToSchedule(
                                $calendarMonth,
                                $selectedDate,
                                "daily-schedule"
                            );

                        } else {
                            $pdo->rollBack();
                            $message = "Unable to update the visit status. Please try again.";
                            $messageType = "error";
                        }
                    }
                }

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Site visit status update error: " . $exception->getMessage());

                $message = "Unable to update the visit status. Please try again.";
                $messageType = "error";
            }
        }

    } else {
        $message = "Invalid request.";
        $messageType = "error";
    }
}

// --------------------------------------------------
// LEADS AVAILABLE FOR SCHEDULING
// --------------------------------------------------

if ($canViewAll) {
    $leadQuery = $pdo->query("
        SELECT
            l.id,
            l.customer_name,
            l.phone,
            l.assigned_to,
            a.name AS assigned_employee
        FROM leads l
        LEFT JOIN admins a ON l.assigned_to = a.id
        ORDER BY l.customer_name ASC
    ");
} else {
    $leadQuery = $pdo->prepare("
        SELECT
            l.id,
            l.customer_name,
            l.phone,
            l.assigned_to,
            a.name AS assigned_employee
        FROM leads l
        LEFT JOIN admins a ON l.assigned_to = a.id
        WHERE l.assigned_to = ?
        ORDER BY l.customer_name ASC
    ");
    $leadQuery->execute([$adminId]);
}

$leads = $leadQuery->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// EMPLOYEE FILTER OPTIONS
// --------------------------------------------------

$employees = [];

if ($canViewAll) {
    $employeeQuery = $pdo->query("
        SELECT id, name
        FROM admins
        WHERE role = 'sales_employee'
        ORDER BY name ASC
    ");
    $employees = $employeeQuery->fetchAll(PDO::FETCH_ASSOC);
}

// --------------------------------------------------
// PROPERTY TYPE FILTER OPTIONS
// --------------------------------------------------

if ($canViewAll) {
    $propertyQuery = $pdo->query("
        SELECT DISTINCT property_type
        FROM leads
        WHERE property_type IS NOT NULL
        AND property_type <> ''
        ORDER BY property_type ASC
    ");
} else {
    $propertyQuery = $pdo->prepare("
        SELECT DISTINCT property_type
        FROM leads
        WHERE assigned_to = ?
        AND property_type IS NOT NULL
        AND property_type <> ''
        ORDER BY property_type ASC
    ");
    $propertyQuery->execute([$adminId]);
}

$propertyTypes = $propertyQuery->fetchAll(PDO::FETCH_COLUMN);

// --------------------------------------------------
// BUILD ADVANCED FILTER QUERY
// --------------------------------------------------

$where = [];
$queryParams = [];

if ($canViewAll) {
    $where[] = "1 = 1";
} else {
    $where[] = "sv.assigned_to = ?";
    $where[] = "l.assigned_to = ?";
    $queryParams[] = $adminId;
    $queryParams[] = $adminId;
}

if ($search !== "") {
    $where[] = "(
        l.customer_name LIKE ?
        OR l.phone LIKE ?
        OR l.email LIKE ?
        OR l.interested_location LIKE ?
    )";

    $searchValue = "%" . $search . "%";

    $queryParams[] = $searchValue;
    $queryParams[] = $searchValue;
    $queryParams[] = $searchValue;
    $queryParams[] = $searchValue;
}

if ($filterStatus !== "") {
    $where[] = "sv.visit_status = ?";
    $queryParams[] = $filterStatus;
}

if ($canViewAll && $filterEmployee !== "") {
    if ($filterEmployee === "0") {
        $where[] = "sv.assigned_to IS NULL";
    } else {
        $where[] = "sv.assigned_to = ?";
        $queryParams[] = (int)$filterEmployee;
    }
}

if ($filterProperty !== "") {
    $where[] = "l.property_type = ?";
    $queryParams[] = $filterProperty;
}

if ($filterStartDate !== "") {
    $where[] = "sv.scheduled_at >= ?";
    $queryParams[] = $filterStartDate . " 00:00:00";
}

if ($filterEndDate !== "") {
    $endDateObject = new DateTime($filterEndDate);
    $endDateObject->modify("+1 day");

    $where[] = "sv.scheduled_at < ?";
    $queryParams[] = $endDateObject->format("Y-m-d") . " 00:00:00";
}

$whereSql = implode(" AND ", $where);

// --------------------------------------------------
// GET FILTERED SITE VISITS
// --------------------------------------------------

$visitSql = "
    SELECT
        sv.id,
        sv.lead_id,
        sv.assigned_to,
        sv.scheduled_at,
        sv.visit_status,
        sv.notes,
        sv.created_at,
        sv.cancellation_reason,
        sv.cancelled_at,
        sv.cancelled_by,

        l.customer_name,
        l.phone,
        l.email,
        l.property_type,
        l.interested_location,

        assigned.name AS assigned_employee,
        creator.name AS created_by_name,
        canceller.name AS cancelled_by_name

    FROM site_visits sv

    INNER JOIN leads l ON sv.lead_id = l.id
    LEFT JOIN admins assigned ON sv.assigned_to = assigned.id
    LEFT JOIN admins creator ON sv.created_by = creator.id
    LEFT JOIN admins canceller ON sv.cancelled_by = canceller.id

    WHERE $whereSql
    ORDER BY sv.scheduled_at DESC
";

$visitQuery = $pdo->prepare($visitSql);
$visitQuery->execute($queryParams);

$visits = $visitQuery->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// GROUP VISITS BY DATE
// --------------------------------------------------

$visitsByDate = [];
$dailyVisits = [];

foreach ($visits as $visit) {
    $visitDate = date("Y-m-d", strtotime($visit["scheduled_at"]));

    if (!isset($visitsByDate[$visitDate])) {
        $visitsByDate[$visitDate] = [];
    }

    $visitsByDate[$visitDate][] = $visit;

    if ($visitDate === $selectedDate) {
        $dailyVisits[] = $visit;
    }
}

usort($dailyVisits, function ($a, $b) {
    return strtotime($a["scheduled_at"]) <=> strtotime($b["scheduled_at"]);
});

$dailyStatusCounts = [
    "scheduled" => 0,
    "completed" => 0,
    "cancelled" => 0,
    "rescheduled" => 0
];

foreach ($dailyVisits as $dailyVisit) {
    $status = $dailyVisit["visit_status"];

    if (isset($dailyStatusCounts[$status])) {
        $dailyStatusCounts[$status]++;
    }
}

// --------------------------------------------------
// CALENDAR GRID
// --------------------------------------------------

$calendarStart = (clone $calendarDate)->modify("first day of this month");
$calendarStart->modify("-" . ((int)$calendarStart->format("N") - 1) . " days");

$calendarEnd = (clone $calendarDate)->modify("last day of this month");
$calendarEnd->modify("+" . (7 - (int)$calendarEnd->format("N")) . " days");

$today = date("Y-m-d");

$activeFilters = [];

if ($search !== "") $activeFilters["search"] = $search;
if ($filterStatus !== "") $activeFilters["status"] = $filterStatus;
if ($filterEmployee !== "") $activeFilters["employee"] = $filterEmployee;
if ($filterProperty !== "") $activeFilters["property_type"] = $filterProperty;
if ($filterStartDate !== "") $activeFilters["start_date"] = $filterStartDate;
if ($filterEndDate !== "") $activeFilters["end_date"] = $filterEndDate;

$previousUrl = "site_visits.php?" . http_build_query(
    array_merge(["month" => $previousMonth, "day" => $selectedDate], $activeFilters)
);

$nextUrl = "site_visits.php?" . http_build_query(
    array_merge(["month" => $nextMonth, "day" => $selectedDate], $activeFilters)
);

$currentMonthUrl = "site_visits.php?" . http_build_query(
    array_merge(["month" => date("Y-m"), "day" => $selectedDate], $activeFilters)
);

$weekDays = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];

function visitStatusLabel($status) {
    return ucwords(str_replace("_", " ", (string)$status));
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Site Visit Management</title>

<style>
:root {
    --sv-primary: #315fbd;
    --sv-primary-dark: #244b99;
    --sv-navy: #17243a;
    --sv-text: #263247;
    --sv-muted: #718096;
    --sv-border: #e5eaf1;
    --sv-bg: #f4f7fb;
    --sv-white: #ffffff;
    --sv-green: #16804a;
    --sv-red: #c43d46;
    --sv-orange: #b7791f;
    --sv-shadow: 0 5px 20px rgba(24, 39, 75, .055);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 28px;
    background: var(--sv-bg);
    color: var(--sv-text);
    font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    font-size: 14px;
    line-height: 1.55;
}

.container {
    max-width: 1500px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}

.page-heading h1 {
    margin: 0 0 6px;
    color: var(--sv-navy);
    font-size: 27px;
    font-weight: 750;
    letter-spacing: -.5px;
}

.page-heading p {
    margin: 0;
    color: var(--sv-muted);
}

.header-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    border-radius: 15px;
    background: #e8efff;
    color: var(--sv-primary);
    font-size: 25px;
}

.header-title {
    display: flex;
    align-items: center;
    gap: 15px;
}

.nav-links {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 22px;
}

.nav-links a {
    display: inline-flex;
    align-items: center;
    padding: 9px 13px;
    border: 1px solid var(--sv-border);
    border-radius: 8px;
    background: white;
    color: #42536d;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: .18s ease;
}

.nav-links a:hover {
    border-color: #b8c9ec;
    background: #f0f5ff;
    color: var(--sv-primary);
}

.card {
    margin-bottom: 22px;
    padding: 23px;
    border: 1px solid var(--sv-border);
    border-radius: 13px;
    background: var(--sv-white);
    box-shadow: var(--sv-shadow);
}

.card-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.card h2 {
    margin: 0 0 7px;
    color: var(--sv-navy);
    font-size: 18px;
    font-weight: 700;
}

.card h3 {
    margin: 20px 0 12px;
    color: var(--sv-navy);
    font-size: 15px;
}

.card-description {
    margin: 0;
    color: var(--sv-muted);
    font-size: 13px;
}

.message {
    margin-bottom: 18px;
    padding: 13px 16px;
    border: 1px solid #c7ead4;
    border-radius: 9px;
    background: #effaf3;
    color: #17663b;
    font-weight: 600;
}

.message.error {
    border-color: #f1c9cc;
    background: #fff2f2;
    color: #a52e38;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(205px, 1fr));
    gap: 16px;
}

label {
    display: block;
    margin: 0 0 7px;
    color: #42516a;
    font-size: 13px;
    font-weight: 650;
}

input, select, textarea {
    display: block;
    width: 100%;
    min-height: 42px;
    padding: 10px 12px;
    border: 1px solid #d9e0eb;
    border-radius: 8px;
    outline: none;
    background: #fff;
    color: var(--sv-text);
    font: inherit;
    transition: border-color .18s ease, box-shadow .18s ease;
}

input:focus, select:focus, textarea:focus {
    border-color: #83a3e5;
    box-shadow: 0 0 0 3px rgba(49, 95, 189, .10);
}

textarea {
    min-height: 105px;
    resize: vertical;
}

.form-field {
    margin-bottom: 16px;
}

.form-actions, .filter-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 18px;
}

button, .button-link, .reset-button {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 7px;
    min-height: 40px;
    padding: 10px 16px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--sv-primary);
    color: white;
    cursor: pointer;
    text-decoration: none;
    font-family: inherit;
    font-size: 13px;
    font-weight: 650;
    transition: background .18s ease, transform .18s ease;
}

button:hover, .button-link:hover {
    background: var(--sv-primary-dark);
}

button:active, .button-link:active {
    transform: translateY(1px);
}

.reset-button {
    border-color: var(--sv-border);
    background: #f7f9fc;
    color: #53627a;
}

.reset-button:hover {
    background: #edf1f7;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--sv-border);
    border-radius: 9px;
}

table {
    width: 100%;
    min-width: 950px;
    border-collapse: separate;
    border-spacing: 0;
    background: white;
}

th, td {
    padding: 13px 14px;
    border-bottom: 1px solid #edf0f5;
    text-align: left;
    vertical-align: top;
}

th {
    background: #f6f8fc;
    color: #52617a;
    font-size: 11px;
    font-weight: 750;
    letter-spacing: .45px;
    text-transform: uppercase;
    white-space: nowrap;
}

td {
    color: #3e4c62;
    font-size: 13px;
}

tbody tr:last-child td {
    border-bottom: 0;
}

tbody tr:hover td {
    background: #fafcff;
}

.empty {
    padding: 18px 0;
    color: var(--sv-muted);
    text-align: center;
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 18px;
}

.calendar-header h2 {
    margin: 0;
}

.calendar-navigation {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
}

.calendar-navigation a {
    display: inline-block;
    padding: 8px 12px;
    border: 1px solid #dce5f5;
    border-radius: 7px;
    background: #f4f7ff;
    color: var(--sv-primary);
    text-decoration: none;
    font-size: 12px;
    font-weight: 650;
}

.calendar-navigation a:hover {
    background: #e9efff;
}

.calendar-navigation .today-link {
    border-color: #dfe5ed;
    background: #f4f6f9;
    color: #53627a;
}

.filter-result {
    margin-bottom: 15px;
    padding: 11px 14px;
    border: 1px solid #dce7fb;
    border-radius: 8px;
    background: #f3f7ff;
    color: #52698f;
    font-size: 13px;
}

.calendar-wrapper {
    overflow-x: auto;
}

.calendar {
    display: grid;
    grid-template-columns: repeat(7, minmax(105px, 1fr));
    min-width: 790px;
    overflow: hidden;
    border-top: 1px solid var(--sv-border);
    border-left: 1px solid var(--sv-border);
    border-radius: 8px;
}

.calendar-day-heading {
    padding: 11px 5px;
    border-right: 1px solid var(--sv-border);
    border-bottom: 1px solid var(--sv-border);
    background: #f6f8fc;
    color: #5d6b81;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
}

.calendar-day {
    min-height: 125px;
    padding: 8px;
    border-right: 1px solid var(--sv-border);
    border-bottom: 1px solid var(--sv-border);
    background: white;
}

.calendar-day.other-month {
    background: #fafbfd;
    color: #aab2c0;
}

.calendar-day.today {
    background: #f0f5ff;
}

.calendar-day.selected-day {
    outline: 2px solid var(--sv-primary);
    outline-offset: -2px;
}

.day-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 27px;
    height: 27px;
    margin-bottom: 7px;
    border-radius: 50%;
    color: #4d5b72;
    font-size: 12px;
    font-weight: 700;
}

.calendar-day.today .day-number {
    background: var(--sv-primary);
    color: white;
}

.calendar-visit {
    display: block;
    margin-bottom: 5px;
    padding: 6px 7px;
    overflow-wrap: anywhere;
    border-left: 3px solid var(--sv-primary);
    border-radius: 5px;
    background: #edf3ff;
    color: #294e91;
    text-decoration: none;
    font-size: 11px;
    font-weight: 600;
}

.calendar-visit.completed {
    border-left-color: var(--sv-green);
    background: #edf8f1;
    color: #17663b;
}

.calendar-visit.cancelled {
    border-left-color: var(--sv-red);
    background: #fff0f0;
    color: #a52e38;
}

.calendar-visit.rescheduled {
    border-left-color: var(--sv-orange);
    background: #fff7e8;
    color: #8b5b13;
}

.calendar-visit-time {
    display: block;
    margin-top: 2px;
    opacity: .8;
}

.calendar-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 16px;
    color: #65738a;
    font-size: 12px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 7px;
}

.legend-color {
    width: 10px;
    height: 10px;
    border-radius: 3px;
}

.daily-date-form {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 18px;
}

.daily-date-form .date-field {
    flex: 1;
    min-width: 220px;
}

.daily-date-form button {
    margin: 0;
}

.view-visit-link {
    color: var(--sv-primary);
    text-decoration: none;
    font-weight: 650;
}

.view-visit-link:hover {
    text-decoration: underline;
}

.daily-summary {
    margin-bottom: 18px;
    padding: 16px;
    border: 1px solid #dce7fb;
    border-radius: 9px;
    background: #f4f7ff;
    color: #52698f;
}

.daily-summary > strong {
    color: var(--sv-primary);
    font-size: 25px;
}

.summary-counts {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.summary-count {
    padding: 6px 10px;
    border: 1px solid #e1e7f0;
    border-radius: 6px;
    background: white;
    color: #52617a;
    font-size: 12px;
    font-weight: 600;
}

.daily-status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #edf3ff;
    color: #294e91;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.daily-status.completed {
    background: #edf8f1;
    color: #17663b;
}

.daily-status.cancelled {
    background: #fff0f0;
    color: #a52e38;
}

.daily-status.rescheduled {
    background: #fff7e8;
    color: #8b5b13;
}

.status-form, .daily-action-form {
    min-width: 145px;
}

.status-form button, .daily-action-form button {
    width: 100%;
    margin-top: 6px;
    min-height: 35px;
    padding: 7px 10px;
}

.status-form select, .daily-action-form select {
    min-height: 36px;
    padding: 7px;
    font-size: 12px;
}

.cancel-visit-link {
    display: inline-flex;
    margin-top: 8px;
    padding: 7px 10px;
    border-radius: 6px;
    background: #fff0f0;
    color: #ae343e;
    text-decoration: none;
    font-size: 11px;
    font-weight: 700;
}

.cancel-visit-link:hover {
    background: #ffe2e3;
}

.property-details {
    min-width: 140px;
    line-height: 1.6;
}

.property-details strong {
    display: block;
    color: #34435a;
}

.cancellation-details {
    min-width: 160px;
    margin-top: 8px;
    padding: 9px;
    border: 1px solid #f3d5d7;
    border-radius: 7px;
    background: #fff5f5;
    color: #9e3b43;
    font-size: 11px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.section-count {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border: 1px solid #e0e7f1;
    border-radius: 20px;
    background: #f7f9fc;
    color: #64738b;
    font-size: 12px;
    font-weight: 650;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 18px;
}

.form-grid .full-width {
    grid-column: 1 / -1;
}

@media (max-width: 760px) {
    body {
        padding: 16px;
    }

    .page-heading h1 {
        font-size: 23px;
    }

    .card {
        padding: 17px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-grid .full-width {
        grid-column: auto;
    }
}

@media (max-width: 480px) {
    body {
        padding: 11px;
    }

    .card {
        padding: 14px;
        border-radius: 10px;
    }

    .header-icon {
        width: 43px;
        height: 43px;
        font-size: 21px;
    }

    .page-heading h1 {
        font-size: 21px;
    }

    .nav-links a {
        padding: 8px 10px;
        font-size: 12px;
    }

    .calendar-navigation {
        width: 100%;
    }

    .calendar-navigation a {
        flex: 1;
        text-align: center;
    }

    .daily-date-form .date-field {
        min-width: 100%;
    }

    .daily-date-form button {
        width: 100%;
    }

    .filter-actions > * {
        flex: 1;
        text-align: center;
    }
}
</style>
</head>

<body>
<div class="container">

    <header class="page-header">
        <div class="header-title">
            <div class="header-icon">◷</div>
            <div class="page-heading">
                <h1>Site Visit Management</h1>
                <p>Schedule, manage and track customer property visits.</p>
            </div>
        </div>
    </header>

    <nav class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_leads.php">Manage Leads</a>
        <a href="lead_followups.php">Lead Follow-ups</a>
        <a href="weekly_site_visits.php">Weekly Schedule</a>
        <a href="reschedule_site_visits.php">Reschedule Visits</a>
        <a href="cancel_site_visit.php">Cancel Visits</a>
        <a href="site_visit_reschedule_history.php">Rescheduling History</a>
        <a href="site_visit_cancellation_history.php">Cancellation History</a>
        <a href="site_visit_analytics.php">Performance Analytics</a>
        <a href="employee_site_visit_reports.php">Employee Reports</a>
    </nav>

    <?php if ($message !== ""): ?>
        <div class="message <?php echo $messageType === "error" ? "error" : ""; ?>">
            <?php echo e($message); ?>
        </div>
    <?php endif; ?>

    <!-- ADVANCED FILTERS -->
    <section class="card">
        <div class="card-heading">
            <div>
                <h2>Search and Advanced Filters</h2>
                <p class="card-description">Find visits by customer, status, employee, property type or date.</p>
            </div>
        </div>

        <form method="GET" action="site_visits.php">
            <input type="hidden" name="month" value="<?php echo e($calendarMonth); ?>">
            <input type="hidden" name="day" value="<?php echo e($selectedDate); ?>">

            <div class="filter-grid">
                <div>
                    <label for="search">Search Customer</label>
                    <input type="text" name="search" id="search"
                           value="<?php echo e($search); ?>" maxlength="200"
                           placeholder="Name, phone, email or location">
                </div>

                <div>
                    <label for="status">Visit Status</label>
                    <select name="status" id="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($validFilterStatuses as $status): ?>
                            <option value="<?php echo e($status); ?>"
                                <?php echo $filterStatus === $status ? "selected" : ""; ?>>
                                <?php echo e(visitStatusLabel($status)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($canViewAll): ?>
                    <div>
                        <label for="employee">Assigned Sales Employee</label>
                        <select name="employee" id="employee">
                            <option value="">All Employees</option>
                            <option value="0" <?php echo $filterEmployee === "0" ? "selected" : ""; ?>>
                                Unassigned
                            </option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo (int)$employee["id"]; ?>"
                                    <?php echo $filterEmployee === (string)$employee["id"] ? "selected" : ""; ?>>
                                    <?php echo e($employee["name"]); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div>
                    <label for="property_type">Property Type</label>
                    <select name="property_type" id="property_type">
                        <option value="">All Property Types</option>
                        <?php foreach ($propertyTypes as $propertyType): ?>
                            <option value="<?php echo e($propertyType); ?>"
                                <?php echo $filterProperty === $propertyType ? "selected" : ""; ?>>
                                <?php echo e($propertyType); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="start_date">From Date</label>
                    <input type="date" name="start_date" id="start_date"
                           value="<?php echo e($filterStartDate); ?>">
                </div>

                <div>
                    <label for="end_date">To Date</label>
                    <input type="date" name="end_date" id="end_date"
                           value="<?php echo e($filterEndDate); ?>">
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit">Search / Apply Filters</button>
                <a class="reset-button"
                   href="site_visits.php?month=<?php echo e($calendarMonth); ?>&day=<?php echo e($selectedDate); ?>">
                    Reset Filters
                </a>
            </div>
        </form>
    </section>

    <!-- MONTHLY CALENDAR -->
    <section class="card">
        <div class="calendar-header">
            <div>
                <h2><?php echo e($calendarTitle); ?> Calendar</h2>
                <p class="card-description">Select a visit to view the schedule for that date.</p>
            </div>

            <div class="calendar-navigation">
                <a href="<?php echo e($previousUrl); ?>">&laquo; Previous</a>
                <a href="<?php echo e($currentMonthUrl); ?>" class="today-link">Current Month</a>
                <a href="<?php echo e($nextUrl); ?>">Next &raquo;</a>
            </div>
        </div>

        <div class="filter-result">
            Showing <strong><?php echo count($visits); ?></strong>
            site visit(s) matching the selected filters.
        </div>

        <div class="calendar-wrapper">
            <div class="calendar">
                <?php foreach ($weekDays as $weekDay): ?>
                    <div class="calendar-day-heading"><?php echo e($weekDay); ?></div>
                <?php endforeach; ?>

                <?php
                $currentDay = clone $calendarStart;

                while ($currentDay <= $calendarEnd):
                    $dateKey = $currentDay->format("Y-m-d");
                    $isCurrentMonth = $currentDay->format("Y-m") === $calendarMonth;
                    $isToday = $dateKey === $today;
                    $isSelected = $dateKey === $selectedDate;

                    $dayClasses = "calendar-day";
                    if (!$isCurrentMonth) $dayClasses .= " other-month";
                    if ($isToday) $dayClasses .= " today";
                    if ($isSelected) $dayClasses .= " selected-day";
                ?>
                    <div class="<?php echo e($dayClasses); ?>">
                        <div class="day-number"><?php echo e($currentDay->format("j")); ?></div>

                        <?php if (isset($visitsByDate[$dateKey])): ?>
                            <?php foreach ($visitsByDate[$dateKey] as $calendarVisit): ?>
                                <?php
                                $statusClass = $calendarVisit["visit_status"];
                                $visitTime = date("h:i A", strtotime($calendarVisit["scheduled_at"]));

                                $dailyUrl = "site_visits.php?" . http_build_query(
                                    array_merge(
                                        ["month" => $calendarMonth, "day" => $dateKey],
                                        $activeFilters
                                    )
                                ) . "#daily-schedule";
                                ?>
                                <a href="<?php echo e($dailyUrl); ?>"
                                   class="calendar-visit <?php echo e($statusClass); ?>"
                                   title="<?php echo e($calendarVisit["customer_name"]); ?>">
                                    <?php echo e($calendarVisit["customer_name"]); ?>
                                    <span class="calendar-visit-time"><?php echo e($visitTime); ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php
                    $currentDay->modify("+1 day");
                endwhile;
                ?>
            </div>
        </div>

        <div class="calendar-legend">
            <div class="legend-item"><span class="legend-color" style="background:#315fbd;"></span>Scheduled</div>
            <div class="legend-item"><span class="legend-color" style="background:#16804a;"></span>Completed</div>
            <div class="legend-item"><span class="legend-color" style="background:#c43d46;"></span>Cancelled</div>
            <div class="legend-item"><span class="legend-color" style="background:#b7791f;"></span>Rescheduled</div>
        </div>
    </section>

    <!-- DAILY SCHEDULE -->
    <section class="card" id="daily-schedule">
        <div class="card-heading">
            <div>
                <h2>Daily Site Visit Schedule</h2>
                <p class="card-description">View visit timings and update visit status.</p>
            </div>
            <span class="section-count"><?php echo count($dailyVisits); ?> visits</span>
        </div>

        <form method="GET" action="site_visits.php" class="daily-date-form">
            <input type="hidden" name="month" value="<?php echo e($calendarMonth); ?>">

            <?php foreach ($activeFilters as $key => $value): ?>
                <input type="hidden" name="<?php echo e($key); ?>" value="<?php echo e($value); ?>">
            <?php endforeach; ?>

            <div class="date-field">
                <label for="daily_date">Select Date</label>
                <input type="date" id="daily_date" name="day"
                       value="<?php echo e($selectedDate); ?>" required>
            </div>

            <button type="submit">View Daily Schedule</button>

            <a class="view-visit-link"
               href="<?php
               echo e(
                   "site_visits.php?" .
                   http_build_query(
                       array_merge(
                           ["month" => $calendarMonth, "day" => $today],
                           $activeFilters
                       )
                   ) .
                   "#daily-schedule"
               );
               ?>">
                Today's Schedule
            </a>
        </form>

        <h3><?php echo e($selectedDateDisplay); ?></h3>

        <div class="daily-summary">
            <strong><?php echo count($dailyVisits); ?></strong>
            site visit(s) matching the filters for this date.

            <div class="summary-counts">
                <span class="summary-count">Scheduled: <?php echo $dailyStatusCounts["scheduled"]; ?></span>
                <span class="summary-count">Completed: <?php echo $dailyStatusCounts["completed"]; ?></span>
                <span class="summary-count">Cancelled: <?php echo $dailyStatusCounts["cancelled"]; ?></span>
                <span class="summary-count">Rescheduled: <?php echo $dailyStatusCounts["rescheduled"]; ?></span>
            </div>
        </div>

        <?php if ($dailyVisits): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Visit ID</th>
                            <th>Visit Time</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Property Details</th>
                            <th>Assigned Employee</th>
                            <th>Status</th>
                            <th>Visit Notes</th>
                            <th>Quick Status Update</th>
                            <th>History</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dailyVisits as $dailyVisit): ?>
                        <tr id="daily-visit-<?php echo (int)$dailyVisit["id"]; ?>">
                            <td><?php echo (int)$dailyVisit["id"]; ?></td>
                            <td><strong><?php echo e(date("h:i A", strtotime($dailyVisit["scheduled_at"]))); ?></strong></td>
                            <td><?php echo e($dailyVisit["customer_name"]); ?></td>
                            <td><?php echo e($dailyVisit["phone"]); ?></td>
                            <td class="property-details">
                                <strong><?php echo e($dailyVisit["property_type"] ?? "Not specified"); ?></strong>
                                <?php echo e($dailyVisit["interested_location"] ?? "Location not specified"); ?>
                            </td>
                            <td><?php echo e($dailyVisit["assigned_employee"] ?? "Not Assigned"); ?></td>
                            <td>
                                <span class="daily-status <?php echo e($dailyVisit["visit_status"]); ?>">
                                    <?php echo e(visitStatusLabel($dailyVisit["visit_status"])); ?>
                                </span>

                                <?php if ($dailyVisit["visit_status"] === "cancelled"): ?>
                                    <div class="cancellation-details">
                                        <strong>Reason:</strong><br>
                                        <?php echo nl2br(e($dailyVisit["cancellation_reason"] ?? "Not recorded")); ?>
                                        <br><br>
                                        <strong>Cancelled By:</strong>
                                        <?php echo e($dailyVisit["cancelled_by_name"] ?? "Deleted User"); ?>
                                        <br>
                                        <strong>Cancelled At:</strong>
                                        <?php
                                        echo $dailyVisit["cancelled_at"]
                                            ? e(date("d-m-Y h:i A", strtotime($dailyVisit["cancelled_at"])))
                                            : "Not recorded";
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                echo $dailyVisit["notes"] !== null && $dailyVisit["notes"] !== ""
                                    ? nl2br(e($dailyVisit["notes"]))
                                    : "No notes";
                                ?>
                            </td>
                            <td>
                                <?php if ($dailyVisit["visit_status"] !== "cancelled"): ?>
                                    <form method="POST"
                                          action="site_visits.php?month=<?php echo e($calendarMonth); ?>&day=<?php echo e($selectedDate); ?>#daily-schedule"
                                          class="daily-action-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION["csrf_token"]); ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="visit_id" value="<?php echo (int)$dailyVisit["id"]; ?>">

                                        <select name="visit_status" required>
                                            <?php foreach ($allowedStatuses as $status): ?>
                                                <option value="<?php echo e($status); ?>"
                                                    <?php echo $dailyVisit["visit_status"] === $status ? "selected" : ""; ?>>
                                                    <?php echo e(visitStatusLabel($status)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit">Update Status</button>
                                    </form>

                                    <?php if (in_array($dailyVisit["visit_status"], ["scheduled", "rescheduled"], true)): ?>
                                        <a href="cancel_site_visit.php" class="cancel-visit-link">Cancel Visit</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <strong>Cancellation Recorded</strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="#visit-<?php echo (int)$dailyVisit["id"]; ?>" class="view-visit-link">
                                    View History
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">No site visits match your selected date and filters.</p>
        <?php endif; ?>
    </section>

    <!-- SCHEDULE NEW VISIT -->
    <section class="card">
        <div class="card-heading">
            <div>
                <h2>Schedule New Site Visit</h2>
                <p class="card-description">Create a new property visit for an available customer lead.</p>
            </div>
        </div>

        <?php if ($leads): ?>
            <form method="POST"
                  action="site_visits.php?month=<?php echo e($calendarMonth); ?>&day=<?php echo e($selectedDate); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION["csrf_token"]); ?>">
                <input type="hidden" name="action" value="add_visit">

                <div class="form-grid">
                    <div class="form-field full-width">
                        <label for="lead_id">Select Customer *</label>
                        <select name="lead_id" id="lead_id" required>
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($leads as $lead): ?>
                                <option value="<?php echo (int)$lead["id"]; ?>">
                                    <?php
                                    echo e($lead["customer_name"]);
                                    echo " - ";
                                    echo e($lead["phone"]);
                                    echo " | Employee: ";
                                    echo e($lead["assigned_employee"] ?? "Not Assigned");
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-field full-width">
                        <label for="scheduled_at">Visit Date and Time *</label>
                        <input type="datetime-local" name="scheduled_at" id="scheduled_at" required>
                    </div>

                    <div class="form-field full-width">
                        <label for="notes">Visit Notes</label>
                        <textarea name="notes" id="notes" maxlength="10000"
                                  placeholder="Enter property details, meeting point or other instructions..."></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit">Schedule Site Visit</button>
                </div>
            </form>
        <?php else: ?>
            <p class="empty">No assigned customer leads are available for scheduling.</p>
        <?php endif; ?>
    </section>

    <!-- FILTERED VISIT HISTORY -->
    <section class="card">
        <div class="card-heading">
            <div>
                <h2>Site Visit History</h2>
                <p class="card-description">Review all visits matching the selected filters.</p>
            </div>
            <span class="section-count"><?php echo count($visits); ?> visits</span>
        </div>

        <?php if ($visits): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Property Details</th>
                            <th>Visit Date and Time</th>
                            <th>Assigned Employee</th>
                            <th>Visit Notes</th>
                            <th>Status and Cancellation Details</th>
                            <th>Update Status</th>
                            <th>Created By</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visits as $visit): ?>
                        <tr id="visit-<?php echo (int)$visit["id"]; ?>">
                            <td><?php echo (int)$visit["id"]; ?></td>
                            <td><?php echo e($visit["customer_name"]); ?></td>
                            <td><?php echo e($visit["phone"]); ?></td>
                            <td class="property-details">
                                <strong><?php echo e($visit["property_type"] ?? "Not specified"); ?></strong>
                                <?php echo e($visit["interested_location"] ?? "Location not specified"); ?>
                            </td>
                            <td><?php echo e(date("d-m-Y h:i A", strtotime($visit["scheduled_at"]))); ?></td>
                            <td><?php echo e($visit["assigned_employee"] ?? "Not Assigned"); ?></td>
                            <td>
                                <?php
                                echo $visit["notes"] !== null && $visit["notes"] !== ""
                                    ? nl2br(e($visit["notes"]))
                                    : "No notes";
                                ?>
                            </td>
                            <td>
                                <span class="daily-status <?php echo e($visit["visit_status"]); ?>">
                                    <?php echo e(visitStatusLabel($visit["visit_status"])); ?>
                                </span>

                                <?php if ($visit["visit_status"] === "cancelled"): ?>
                                    <div class="cancellation-details">
                                        <strong>Cancellation Reason:</strong><br>
                                        <?php echo nl2br(e($visit["cancellation_reason"] ?? "Not recorded")); ?>
                                        <br><br>
                                        <strong>Cancelled By:</strong><br>
                                        <?php echo e($visit["cancelled_by_name"] ?? "Deleted User"); ?>
                                        <br><br>
                                        <strong>Cancelled At:</strong><br>
                                        <?php
                                        echo $visit["cancelled_at"]
                                            ? e(date("d-m-Y h:i A", strtotime($visit["cancelled_at"])))
                                            : "Not recorded";
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($visit["visit_status"] !== "cancelled"): ?>
                                    <form method="POST"
                                          action="site_visits.php?month=<?php echo e($calendarMonth); ?>&day=<?php echo e($selectedDate); ?>"
                                          class="status-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION["csrf_token"]); ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="visit_id" value="<?php echo (int)$visit["id"]; ?>">

                                        <select name="visit_status" required>
                                            <?php foreach ($allowedStatuses as $status): ?>
                                                <option value="<?php echo e($status); ?>"
                                                    <?php echo $visit["visit_status"] === $status ? "selected" : ""; ?>>
                                                    <?php echo e(visitStatusLabel($status)); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit">Save</button>
                                    </form>

                                    <?php if (in_array($visit["visit_status"], ["scheduled", "rescheduled"], true)): ?>
                                        <a href="cancel_site_visit.php" class="cancel-visit-link">Cancel Visit</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <strong>Cancelled</strong>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($visit["created_by_name"] ?? "Deleted User"); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">No site visits match your selected filters.</p>
        <?php endif; ?>
    </section>

</div>
</body>
</html>
