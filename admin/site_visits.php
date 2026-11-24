
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";
require_once "../includes/lead_activity_logger.php";

requirePermission("manage_site_visits");

$message = "";
$messageType = "success";

$adminId = (int) $_SESSION["admin_id"];
$canViewAll = hasPermission("view_all_leads");

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
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
// CSRF TOKEN
// --------------------------------------------------

if (
    !isset($_SESSION["csrf_token"]) ||
    !is_string($_SESSION["csrf_token"]) ||
    $_SESSION["csrf_token"] === ""
) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Flash message.
if (isset($_SESSION["user_message"])) {
    $message = is_string($_SESSION["user_message"])
        ? $_SESSION["user_message"]
        : "";

    unset($_SESSION["user_message"]);
}

$allowedStatuses = [
    "scheduled",
    "completed",
    "rescheduled"
];

// --------------------------------------------------
// CALENDAR MONTH
// --------------------------------------------------

$requestedMonth = $_GET["month"] ?? date("Y-m");

if (!is_string($requestedMonth)) {
    $requestedMonth = "";
}

$calendarDate = DateTime::createFromFormat("!Y-m", $requestedMonth);

if (
    !$calendarDate ||
    $calendarDate->format("Y-m") !== $requestedMonth
) {
    $calendarDate = new DateTime("first day of this month");
}

$calendarMonth = $calendarDate->format("Y-m");
$calendarTitle = $calendarDate->format("F Y");

$previousMonth = (clone $calendarDate)
    ->modify("-1 month")
    ->format("Y-m");

$nextMonth = (clone $calendarDate)
    ->modify("+1 month")
    ->format("Y-m");

// --------------------------------------------------
// SELECTED DAILY DATE
// --------------------------------------------------

$requestedDay = $_GET["day"] ?? date("Y-m-d");

if (!is_string($requestedDay)) {
    $requestedDay = "";
}

$selectedDateObject = DateTime::createFromFormat(
    "!Y-m-d",
    $requestedDay
);

if (
    !$selectedDateObject ||
    $selectedDateObject->format("Y-m-d") !== $requestedDay
) {
    $selectedDateObject = new DateTime("today");
}

$selectedDate = $selectedDateObject->format("Y-m-d");
$selectedDateDisplay = $selectedDateObject->format("l, d F Y");

// --------------------------------------------------
// ADVANCED FILTERS
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

foreach ($filterValues as $key => &$value) {
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

// Validate employee ID. Zero means unassigned.
if ($filterEmployee !== "") {
    if (
        !ctype_digit($filterEmployee) ||
        (int) $filterEmployee < 0
    ) {
        $filterEmployee = "";
        $filterErrors[] = "Invalid employee filter was ignored.";
    }
}

// Sales employees cannot filter other employees.
if (!$canViewAll) {
    $filterEmployee = "";
}

// Validate dates.
if (
    $filterStartDate !== "" &&
    !validDateValue($filterStartDate)
) {
    $filterStartDate = "";
    $filterErrors[] = "Invalid start date was ignored.";
}

if (
    $filterEndDate !== "" &&
    !validDateValue($filterEndDate)
) {
    $filterEndDate = "";
    $filterErrors[] = "Invalid end date was ignored.";
}

// Ensure date range is correct.
if (
    $filterStartDate !== "" &&
    $filterEndDate !== "" &&
    $filterStartDate > $filterEndDate
) {
    $filterStartDate = "";
    $filterEndDate = "";

    $filterErrors[] =
        "Start date cannot be later than end date. Date filters were cleared.";
}

if (!empty($filterErrors)) {
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

    // ----------------------------------------------
    // SCHEDULE NEW VISIT
    // ----------------------------------------------

    if ($action === "add_visit") {

        $leadIdValue = $_POST["lead_id"] ?? "";
        $scheduledAt = $_POST["scheduled_at"] ?? "";
        $notes = $_POST["notes"] ?? "";

        if (
            !is_string($leadIdValue) ||
            !ctype_digit($leadIdValue) ||
            (int) $leadIdValue < 1 ||
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

            $leadId = (int) $leadIdValue;
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

                    $date = DateTime::createFromFormat(
                        "!Y-m-d\TH:i",
                        $scheduledAt
                    );

                    if (
                        !$date ||
                        $date->format("Y-m-d\TH:i") !== $scheduledAt
                    ) {

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

                        $_SESSION["csrf_token"] =
                            bin2hex(random_bytes(32));

                        $_SESSION["user_message"] =
                            "Site visit scheduled successfully!";

                        redirectToSchedule(
                            $calendarMonth,
                            $date->format("Y-m-d")
                        );
                    }
                }

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Site visit scheduling error: " . $e->getMessage());

                $message = "Unable to schedule the site visit. Please try again.";
                $messageType = "error";
            }
        }

    }

    // ----------------------------------------------
    // UPDATE VISIT STATUS
    // ----------------------------------------------

    elseif ($action === "update_status") {

        $visitIdValue = $_POST["visit_id"] ?? "";
        $newStatus = $_POST["visit_status"] ?? "";

        if (
            !is_string($visitIdValue) ||
            !ctype_digit($visitIdValue) ||
            (int) $visitIdValue < 1 ||
            !is_string($newStatus) ||
            !in_array($newStatus, $allowedStatuses, true)
        ) {

            $message = "Invalid visit or status selected. Cancellation must be completed through the cancellation page.";
            $messageType = "error";

        } else {

            $visitId = (int) $visitIdValue;

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

                    $visitCheck->execute([
                        $visitId,
                        $adminId,
                        $adminId
                    ]);
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

                            $update->execute([
                                $newStatus,
                                $visitId
                            ]);

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

                            $oldStatusText = ucwords(
                                str_replace("_", " ", $oldStatus)
                            );

                            $newStatusText = ucwords(
                                str_replace("_", " ", $newStatus)
                            );

                            $activityDescription =
                                "Site visit status changed from " .
                                $oldStatusText .
                                " to " .
                                $newStatusText;

                            logLeadActivity(
                                $pdo,
                                (int) $visit["lead_id"],
                                $adminId,
                                "site_visit_status_changed",
                                $activityDescription,
                                $oldStatus,
                                $newStatus
                            );

                            $pdo->commit();

                            $_SESSION["csrf_token"] =
                                bin2hex(random_bytes(32));

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

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Site visit status update error: " . $e->getMessage());

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

// Role-based access.
if ($canViewAll) {

    $where[] = "1 = 1";

} else {

    $where[] = "sv.assigned_to = ?";
    $where[] = "l.assigned_to = ?";

    $queryParams[] = $adminId;
    $queryParams[] = $adminId;
}

// Customer name, phone, email and location search.
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

// Status filter.
if ($filterStatus !== "") {

    $where[] = "sv.visit_status = ?";
    $queryParams[] = $filterStatus;
}

// Employee filter, including unassigned visits.
if ($canViewAll && $filterEmployee !== "") {

    if ($filterEmployee === "0") {

        $where[] = "sv.assigned_to IS NULL";

    } else {

        $where[] = "sv.assigned_to = ?";
        $queryParams[] = (int) $filterEmployee;
    }
}

// Property type filter.
if ($filterProperty !== "") {

    $where[] = "l.property_type = ?";
    $queryParams[] = $filterProperty;
}

// Start date filter.
if ($filterStartDate !== "") {

    $where[] = "sv.scheduled_at >= ?";
    $queryParams[] = $filterStartDate . " 00:00:00";
}

// End date filter.
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

    INNER JOIN leads l
        ON sv.lead_id = l.id

    LEFT JOIN admins assigned
        ON sv.assigned_to = assigned.id

    LEFT JOIN admins creator
        ON sv.created_by = creator.id

    LEFT JOIN admins canceller
        ON sv.cancelled_by = canceller.id

    WHERE $whereSql

    ORDER BY sv.scheduled_at DESC
";

$visitQuery = $pdo->prepare($visitSql);
$visitQuery->execute($queryParams);

$visits = $visitQuery->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// GROUP FILTERED VISITS BY DATE
// --------------------------------------------------

$visitsByDate = [];

foreach ($visits as $visit) {

    $visitDate = date(
        "Y-m-d",
        strtotime($visit["scheduled_at"])
    );

    if (!isset($visitsByDate[$visitDate])) {
        $visitsByDate[$visitDate] = [];
    }

    $visitsByDate[$visitDate][] = $visit;
}

// Daily schedule.
$dailyVisits = [];

foreach ($visits as $visit) {

    $visitDate = date(
        "Y-m-d",
        strtotime($visit["scheduled_at"])
    );

    if ($visitDate === $selectedDate) {
        $dailyVisits[] = $visit;
    }
}

usort($dailyVisits, function ($a, $b) {
    return strtotime($a["scheduled_at"]) <=> strtotime($b["scheduled_at"]);
});

// Daily status counts.
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

$calendarStart = (clone $calendarDate)
    ->modify("first day of this month");

$calendarStart->modify(
    "-" . ((int) $calendarStart->format("N") - 1) . " days"
);

$calendarEnd = (clone $calendarDate)
    ->modify("last day of this month");

$calendarEnd->modify(
    "+" . (7 - (int) $calendarEnd->format("N")) . " days"
);

$today = date("Y-m-d");

// Filter query string for calendar navigation.
$activeFilters = [];

if ($search !== "") {
    $activeFilters["search"] = $search;
}

if ($filterStatus !== "") {
    $activeFilters["status"] = $filterStatus;
}

if ($filterEmployee !== "") {
    $activeFilters["employee"] = $filterEmployee;
}

if ($filterProperty !== "") {
    $activeFilters["property_type"] = $filterProperty;
}

if ($filterStartDate !== "") {
    $activeFilters["start_date"] = $filterStartDate;
}

if ($filterEndDate !== "") {
    $activeFilters["end_date"] = $filterEndDate;
}

$previousUrl = "site_visits.php?" . http_build_query(
    array_merge(
        ["month" => $previousMonth, "day" => $selectedDate],
        $activeFilters
    )
);

$nextUrl = "site_visits.php?" . http_build_query(
    array_merge(
        ["month" => $nextMonth, "day" => $selectedDate],
        $activeFilters
    )
);

$currentMonthUrl = "site_visits.php?" . http_build_query(
    array_merge(
        ["month" => date("Y-m"), "day" => $selectedDate],
        $activeFilters
    )
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Site Visit Management</title>

<style>

body {
    font-family: Arial, sans-serif;
    background: #f4f6f9;
    margin: 0;
    padding: 25px;
    color: #333;
}

.container {
    max-width: 1400px;
    margin: auto;
}

h1, h2, h3 {
    color: #222;
}

.card {
    background: white;
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
    min-height: 85px;
    resize: vertical;
}

button, .button-link {
    display: inline-block;
    margin-top: 15px;
    padding: 10px 20px;
    background: #1769aa;
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    text-decoration: none;
    font-size: 14px;
}

button:hover, .button-link:hover {
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

.status-form {
    min-width: 150px;
}

.status-form select {
    margin-bottom: 5px;
}

.status-form button {
    margin-top: 5px;
}

.empty {
    color: #777;
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.calendar-header h2 {
    margin: 0;
}

.calendar-navigation {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.calendar-navigation a {
    display: inline-block;
    padding: 9px 13px;
    background: #1769aa;
    color: white;
    text-decoration: none;
    border-radius: 5px;
    font-size: 14px;
}

.calendar-navigation .today-link {
    background: #555;
}

.calendar-wrapper {
    overflow-x: auto;
}

.calendar {
    display: grid;
    grid-template-columns: repeat(7, minmax(100px, 1fr));
    min-width: 750px;
    border-top: 1px solid #ddd;
    border-left: 1px solid #ddd;
}

.calendar-day-heading {
    background: #f0f2f5;
    font-weight: bold;
    text-align: center;
    padding: 12px 5px;
    border-right: 1px solid #ddd;
    border-bottom: 1px solid #ddd;
}

.calendar-day {
    min-height: 125px;
    padding: 8px;
    border-right: 1px solid #ddd;
    border-bottom: 1px solid #ddd;
    background: white;
}

.calendar-day.other-month {
    background: #f8f8f8;
    color: #aaa;
}

.calendar-day.today {
    background: #eef6ff;
}

.calendar-day.selected-day {
    outline: 2px solid #1769aa;
    outline-offset: -2px;
}

.day-number {
    font-weight: bold;
    margin-bottom: 8px;
}

.today .day-number {
    color: #1769aa;
}

.calendar-visit {
    display: block;
    padding: 6px;
    margin-bottom: 5px;
    border-radius: 4px;
    background: #e5f1ff;
    border-left: 3px solid #1769aa;
    color: #174b7a;
    text-decoration: none;
    font-size: 12px;
    overflow-wrap: anywhere;
}

.calendar-visit.completed {
    background: #e5f5e9;
    border-left-color: #258443;
    color: #216b32;
}

.calendar-visit.cancelled {
    background: #fde8e8;
    border-left-color: #c33;
    color: #a32222;
}

.calendar-visit.rescheduled {
    background: #fff3dc;
    border-left-color: #d18b16;
    color: #80540b;
}

.calendar-visit-time {
    display: block;
    margin-top: 3px;
}

.calendar-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 15px;
    font-size: 13px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

.daily-date-form {
    display: flex;
    align-items: end;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.daily-date-form .date-field {
    flex: 1;
    min-width: 220px;
}

.daily-date-form label {
    margin-top: 0;
}

.daily-date-form button {
    margin-top: 0;
    min-height: 40px;
}

.daily-summary {
    padding: 15px;
    background: #eef6ff;
    border-radius: 6px;
    margin-bottom: 18px;
}

.daily-summary strong {
    color: #1769aa;
    font-size: 22px;
}

.summary-counts {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}

.summary-count {
    padding: 7px 11px;
    border-radius: 5px;
    background: white;
    font-size: 13px;
    border: 1px solid #e0e0e0;
}

.daily-status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    background: #e5f1ff;
    color: #174b7a;
}

.daily-status.completed {
    background: #e5f5e9;
    color: #216b32;
}

.daily-status.cancelled {
    background: #fde8e8;
    color: #a32222;
}

.daily-status.rescheduled {
    background: #fff3dc;
    color: #80540b;
}

.view-visit-link {
    color: #1769aa;
    font-weight: bold;
    text-decoration: none;
}

.cancel-visit-link {
    display: inline-block;
    margin-top: 8px;
    padding: 7px 10px;
    background: #c62828;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.daily-action-form {
    min-width: 145px;
}

.daily-action-form select {
    margin-bottom: 5px;
}

.daily-action-form button {
    width: 100%;
    margin-top: 4px;
    padding: 8px 10px;
}

.property-details {
    line-height: 1.6;
}

.property-details strong {
    display: block;
    color: #444;
}

.cancellation-details {
    margin-top: 7px;
    padding: 8px;
    background: #fde8e8;
    color: #a32222;
    border-radius: 4px;
    font-size: 12px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 15px;
}

.filter-grid label {
    margin-top: 0;
}

.filter-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-actions button {
    margin-top: 15px;
}

.reset-button {
    display: inline-block;
    margin-top: 15px;
    padding: 10px 20px;
    background: #666;
    color: white;
    text-decoration: none;
    border-radius: 5px;
    font-size: 14px;
}

.filter-result {
    background: #eef6ff;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
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

<h1>Site Visit Management</h1>

<p>Schedule and track property visits for customers.</p>

<div class="nav-links">

<a href="dashboard.php">Dashboard</a>
<a href="manage_leads.php">Manage Leads</a>
<a href="lead_followups.php">Lead Follow-ups</a>
<a href="weekly_site_visits.php">Weekly Site Visit Schedule</a>
<a href="reschedule_site_visits.php">Reschedule Site Visits</a>
<a href="cancel_site_visit.php">Cancel Site Visits</a>
<a href="site_visit_reschedule_history.php">Rescheduling History</a>
<a href="site_visit_cancellation_history.php">Cancellation History</a>
<a href="site_visit_analytics.php">Performance Analytics</a>
<a href="employee_site_visit_reports.php">Employee Reports</a>

</div>

<?php if ($message !== ""): ?>

<div class="message <?php echo $messageType === 'error' ? 'error' : ''; ?>">
    <?php echo e($message); ?>
</div>

<?php endif; ?>

<!-- ADVANCED SEARCH AND FILTERS -->

<div class="card">

<h2>Site Visit Search and Advanced Filters</h2>

<form method="GET" action="site_visits.php">

<input type="hidden" name="month" value="<?php echo e($calendarMonth); ?>">
<input type="hidden" name="day" value="<?php echo e($selectedDate); ?>">

<div class="filter-grid">

<div>

<label for="search">Search Customer</label>

<input
    type="text"
    name="search"
    id="search"
    value="<?php echo e($search); ?>"
    maxlength="200"
    placeholder="Customer name, phone, email or location"
>

</div>

<div>

<label for="status">Visit Status</label>

<select name="status" id="status">

<option value="">All Statuses</option>

<?php foreach ($validFilterStatuses as $status): ?>

<option value="<?php echo e($status); ?>" <?php echo $filterStatus === $status ? "selected" : ""; ?>>
    <?php echo e(ucwords(str_replace("_", " ", $status))); ?>
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

<option
    value="<?php echo (int) $employee["id"]; ?>"
    <?php echo $filterEmployee === (string) $employee["id"] ? "selected" : ""; ?>
>
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

<option
    value="<?php echo e($propertyType); ?>"
    <?php echo $filterProperty === $propertyType ? "selected" : ""; ?>
>
    <?php echo e($propertyType); ?>
</option>

<?php endforeach; ?>

</select>

</div>

<div>

<label for="start_date">From Date</label>

<input
    type="date"
    name="start_date"
    id="start_date"
    value="<?php echo e($filterStartDate); ?>"
>

</div>

<div>

<label for="end_date">To Date</label>

<input
    type="date"
    name="end_date"
    id="end_date"
    value="<?php echo e($filterEndDate); ?>"
>

</div>

</div>

<div class="filter-actions">

<button type="submit">Search / Apply Filters</button>

<a
    class="reset-button"
    href="site_visits.php?month=<?php echo e($calendarMonth); ?>&day=<?php echo e($selectedDate); ?>"
>
    Reset Filters
</a>

</div>

</form>

</div>

<!-- MONTHLY CALENDAR -->

<div class="card">

<div class="calendar-header">

<h2><?php echo e($calendarTitle); ?> Calendar</h2>

<div class="calendar-navigation">

<a href="<?php echo e($previousUrl); ?>">&laquo; Previous</a>

<a href="<?php echo e($currentMonthUrl); ?>" class="today-link">
Current Month
</a>

<a href="<?php echo e($nextUrl); ?>">Next &raquo;</a>

</div>

</div>

<div class="filter-result">

Showing <?php echo count($visits); ?> site visit(s)
matching the selected filters.

</div>

<div class="calendar-wrapper">

<div class="calendar">

<?php

$weekDays = [
    "Monday",
    "Tuesday",
    "Wednesday",
    "Thursday",
    "Friday",
    "Saturday",
    "Sunday"
];

foreach ($weekDays as $weekDay):

?>

<div class="calendar-day-heading">
<?php echo e($weekDay); ?>
</div>

<?php endforeach; ?>

<?php

$currentDay = clone $calendarStart;

while ($currentDay <= $calendarEnd):

    $dateKey = $currentDay->format("Y-m-d");

    $isCurrentMonth = $currentDay->format("Y-m") === $calendarMonth;
    $isToday = $dateKey === $today;
    $isSelected = $dateKey === $selectedDate;

    $dayClasses = "calendar-day";

    if (!$isCurrentMonth) {
        $dayClasses .= " other-month";
    }

    if ($isToday) {
        $dayClasses .= " today";
    }

    if ($isSelected) {
        $dayClasses .= " selected-day";
    }

?>

<div class="<?php echo e($dayClasses); ?>">

<div class="day-number">
<?php echo e($currentDay->format("j")); ?>
</div>

<?php if (isset($visitsByDate[$dateKey])): ?>

<?php foreach ($visitsByDate[$dateKey] as $calendarVisit): ?>

<?php

$statusClass = $calendarVisit["visit_status"];

$visitTime = date(
    "h:i A",
    strtotime($calendarVisit["scheduled_at"])
);

$dailyUrl = "site_visits.php?" . http_build_query(
    array_merge(
        [
            "month" => $calendarMonth,
            "day" => $dateKey
        ],
        $activeFilters
    )
) . "#daily-schedule";

?>

<a
    href="<?php echo e($dailyUrl); ?>"
    class="calendar-visit <?php echo e($statusClass); ?>"
    title="<?php echo e($calendarVisit["customer_name"]); ?>"
>

<?php echo e($calendarVisit["customer_name"]); ?>

<span class="calendar-visit-time">
<?php echo e($visitTime); ?>
</span>

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

<div class="legend-item">
<span class="legend-color" style="background:#1769aa;"></span>
Scheduled
</div>

<div class="legend-item">
<span class="legend-color" style="background:#258443;"></span>
Completed
</div>

<div class="legend-item">
<span class="legend-color" style="background:#c33;"></span>
Cancelled
</div>

<div class="legend-item">
<span class="legend-color" style="background:#d18b16;"></span>
Rescheduled
</div>

</div>

<p class="empty">
Click a visit in the calendar to see visits scheduled for that date.
</p>

</div>

<!-- DAILY SCHEDULE -->

<div class="card" id="daily-schedule">

<h2>Daily Site Visit Schedule</h2>

<form method="GET" action="site_visits.php" class="daily-date-form">

<input type="hidden" name="month" value="<?php echo e($calendarMonth); ?>">

<?php foreach ($activeFilters as $key => $value): ?>

<input
    type="hidden"
    name="<?php echo e($key); ?>"
    value="<?php echo e($value); ?>"
>

<?php endforeach; ?>

<div class="date-field">

<label for="daily_date">Select Date</label>

<input
    type="date"
    id="daily_date"
    name="day"
    value="<?php echo e($selectedDate); ?>"
    required
>

</div>

<button type="submit">View Daily Schedule</button>

<a
    href="<?php
    echo e(
        "site_visits.php?" .
        http_build_query(
            array_merge(
                [
                    "month" => $calendarMonth,
                    "day" => $today
                ],
                $activeFilters
            )
        ) .
        "#daily-schedule"
    );
    ?>"
    class="view-visit-link"
>
Today's Schedule
</a>

</form>

<h3><?php echo e($selectedDateDisplay); ?></h3>

<div class="daily-summary">

<strong><?php echo count($dailyVisits); ?></strong>

site visit(s) matching the filters for this date.

<div class="summary-counts">

<span class="summary-count">
Scheduled: <?php echo $dailyStatusCounts["scheduled"]; ?>
</span>

<span class="summary-count">
Completed: <?php echo $dailyStatusCounts["completed"]; ?>
</span>

<span class="summary-count">
Cancelled: <?php echo $dailyStatusCounts["cancelled"]; ?>
</span>

<span class="summary-count">
Rescheduled: <?php echo $dailyStatusCounts["rescheduled"]; ?>
</span>

</div>

</div>

<?php if (count($dailyVisits) > 0): ?>

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

<tr id="daily-visit-<?php echo (int) $dailyVisit["id"]; ?>">

<td><?php echo (int) $dailyVisit["id"]; ?></td>

<td>
<strong>
<?php
echo e(
    date(
        "h:i A",
        strtotime($dailyVisit["scheduled_at"])
    )
);
?>
</strong>
</td>

<td><?php echo e($dailyVisit["customer_name"]); ?></td>

<td><?php echo e($dailyVisit["phone"]); ?></td>

<td class="property-details">

<strong>
<?php echo e($dailyVisit["property_type"] ?? "Not specified"); ?>
</strong>

<?php echo e($dailyVisit["interested_location"] ?? "Location not specified"); ?>

</td>

<td>
<?php echo e($dailyVisit["assigned_employee"] ?? "Not Assigned"); ?>
</td>

<td>

<span class="daily-status <?php echo e($dailyVisit["visit_status"]); ?>">

<?php
echo e(
    ucwords(
        str_replace("_", " ", $dailyVisit["visit_status"])
    )
);
?>

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

<form
    method="POST"
    action="site_visits.php?month=<?php echo e($calendarMonth); ?>&day=<?php echo e($selectedDate); ?>#daily-schedule"
    class="daily-action-form"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?php echo e($_SESSION["csrf_token"]); ?>"
>

<input type="hidden" name="action" value="update_status">

<input
    type="hidden"
    name="visit_id"
    value="<?php echo (int) $dailyVisit["id"]; ?>"
>

<select name="visit_status" required>

<?php foreach ($allowedStatuses as $status): ?>

<option
    value="<?php echo e($status); ?>"
    <?php echo $dailyVisit["visit_status"] === $status ? "selected" : ""; ?>
>
<?php echo e(ucwords(str_replace("_", " ", $status))); ?>
</option>

<?php endforeach; ?>

</select>

<button type="submit">Update Status</button>

</form>

<?php if (in_array($dailyVisit["visit_status"], ["scheduled", "rescheduled"], true)): ?>

<a href="cancel_site_visit.php" class="cancel-visit-link">
Cancel Visit
</a>

<?php endif; ?>

<?php else: ?>

<strong>Cancellation Recorded</strong>

<?php endif; ?>

</td>

<td>

<a
    href="#visit-<?php echo (int) $dailyVisit["id"]; ?>"
    class="view-visit-link"
>
View History
</a>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php else: ?>

<p class="empty">
No site visits match your selected date and filters.
</p>

<?php endif; ?>

</div>

<!-- SCHEDULE NEW VISIT -->

<div class="card">

<h2>Schedule New Site Visit</h2>

<?php if (count($leads) > 0): ?>

<form method="POST" action="site_visits.php?month=<?php echo e($calendarMonth); ?>&day=<?php echo e($selectedDate); ?>">

<input
    type="hidden"
    name="csrf_token"
    value="<?php echo e($_SESSION["csrf_token"]); ?>"
>

<input type="hidden" name="action" value="add_visit">

<label for="lead_id">Select Customer *</label>

<select name="lead_id" id="lead_id" required>

<option value="">-- Select Customer --</option>

<?php foreach ($leads as $lead): ?>

<option value="<?php echo (int) $lead["id"]; ?>">

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

<label for="scheduled_at">Visit Date and Time *</label>

<input
    type="datetime-local"
    name="scheduled_at"
    id="scheduled_at"
    required
>

<label for="notes">Visit Notes</label>

<textarea
    name="notes"
    id="notes"
    maxlength="10000"
    placeholder="Enter property details, meeting point or other instructions..."
></textarea>

<button type="submit">Schedule Site Visit</button>

</form>

<?php else: ?>

<p class="empty">
No assigned customer leads are available for scheduling.
</p>

<?php endif; ?>

</div>

<!-- FILTERED SITE VISIT HISTORY -->

<div class="card">

<h2>Site Visit History</h2>

<p>
Showing <?php echo count($visits); ?> visit(s) based on the selected filters.
</p>

<?php if (count($visits) > 0): ?>

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

<tr id="visit-<?php echo (int) $visit["id"]; ?>">

<td><?php echo (int) $visit["id"]; ?></td>

<td><?php echo e($visit["customer_name"]); ?></td>

<td><?php echo e($visit["phone"]); ?></td>

<td class="property-details">

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
<?php
echo $visit["notes"] !== null && $visit["notes"] !== ""
    ? nl2br(e($visit["notes"]))
    : "No notes";
?>
</td>

<td>

<span class="daily-status <?php echo e($visit["visit_status"]); ?>">

<?php
echo e(
    ucwords(
        str_replace("_", " ", $visit["visit_status"])
    )
);
?>

</span>

<?php if ($visit["visit_status"] === "cancelled"): ?>

<div class="cancellation-details">

<strong>Cancellation Reason:</strong><br>

<?php
echo nl2br(
    e($visit["cancellation_reason"] ?? "Not recorded")
);
?>

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

<form
    method="POST"
    action="site_visits.php?month=<?php echo e($calendarMonth); ?>&day=<?php echo e($selectedDate); ?>"
    class="status-form"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?php echo e($_SESSION["csrf_token"]); ?>"
>

<input type="hidden" name="action" value="update_status">

<input
    type="hidden"
    name="visit_id"
    value="<?php echo (int) $visit["id"]; ?>"
>

<select name="visit_status" required>

<?php foreach ($allowedStatuses as $status): ?>

<option
    value="<?php echo e($status); ?>"
    <?php echo $visit["visit_status"] === $status ? "selected" : ""; ?>
>
<?php echo e(ucwords(str_replace("_", " ", $status))); ?>
</option>

<?php endforeach; ?>

</select>

<button type="submit">Save</button>

</form>

<?php if (in_array($visit["visit_status"], ["scheduled", "rescheduled"], true)): ?>

<a href="cancel_site_visit.php" class="cancel-visit-link">
Cancel Visit
</a>

<?php endif; ?>

<?php else: ?>

<strong>Cancelled</strong>

<?php endif; ?>

</td>

<td>
<?php echo e($visit["created_by_name"] ?? "Deleted User"); ?>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php else: ?>

<p class="empty">
No site visits match your selected filters.
</p>

<?php endif; ?>

</div>

</div>

</body>
</html>
