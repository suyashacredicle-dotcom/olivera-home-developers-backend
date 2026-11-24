
<?php

require_once "../includes/permissions.php";
require_once "../config/database.php";

requirePermission("view_crm_reports");

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// --------------------------------------------------
// 1. LEAD SUMMARY
// --------------------------------------------------

$totalLeads = (int)$pdo->query("
    SELECT COUNT(*) FROM leads
")->fetchColumn();

$leadStatuses = $pdo->query("
    SELECT lead_status, COUNT(*) AS total
    FROM leads
    GROUP BY lead_status
")->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 2. LEAD CONVERSION REPORT
// --------------------------------------------------

$conversionData = $pdo->query("
    SELECT
        COUNT(*) AS total_leads,
        SUM(CASE WHEN lead_status = 'converted' THEN 1 ELSE 0 END) AS converted_leads,
        SUM(CASE WHEN lead_status = 'lost' THEN 1 ELSE 0 END) AS lost_leads
    FROM leads
")->fetch(PDO::FETCH_ASSOC);

$conversionTotal = (int)($conversionData["total_leads"] ?? 0);
$convertedLeads = (int)($conversionData["converted_leads"] ?? 0);
$lostLeads = (int)($conversionData["lost_leads"] ?? 0);
$activeLeads = $conversionTotal - $convertedLeads - $lostLeads;

$conversionRate = $conversionTotal > 0
    ? ($convertedLeads / $conversionTotal) * 100
    : 0;

// --------------------------------------------------
// 3. SITE VISIT SUMMARY
// --------------------------------------------------

$totalVisits = (int)$pdo->query("
    SELECT COUNT(*) FROM site_visits
")->fetchColumn();

$visitStatuses = $pdo->query("
    SELECT visit_status, COUNT(*) AS total
    FROM site_visits
    GROUP BY visit_status
")->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 4. FOLLOW-UP SUMMARY
// --------------------------------------------------

$totalFollowups = (int)$pdo->query("
    SELECT COUNT(*) FROM lead_followups
")->fetchColumn();

// --------------------------------------------------
// 5. EMPLOYEE PERFORMANCE
// --------------------------------------------------

$employeeQuery = $pdo->query("
    SELECT
        a.id,
        a.name,
        a.email,

        (
            SELECT COUNT(*)
            FROM leads l
            WHERE l.assigned_to = a.id
        ) AS total_leads,

        (
            SELECT COUNT(*)
            FROM leads l
            WHERE l.assigned_to = a.id
            AND l.lead_status = 'converted'
        ) AS converted_leads,

        (
            SELECT COUNT(*)
            FROM leads l
            WHERE l.assigned_to = a.id
            AND l.lead_status = 'lost'
        ) AS lost_leads,

        (
            SELECT COUNT(*)
            FROM leads l
            WHERE l.assigned_to = a.id
            AND l.lead_status NOT IN ('converted', 'lost')
        ) AS active_leads,

        (
            SELECT COUNT(*)
            FROM site_visits sv
            WHERE sv.assigned_to = a.id
        ) AS total_visits,

        (
            SELECT COUNT(*)
            FROM site_visits sv
            WHERE sv.assigned_to = a.id
            AND sv.visit_status = 'completed'
        ) AS completed_visits,

        (
            SELECT COUNT(*)
            FROM lead_followups lf
            WHERE lf.admin_id = a.id
        ) AS total_followups

    FROM admins a
    WHERE a.role = 'sales_employee'
    ORDER BY total_leads DESC, a.name ASC
");

$employees = $employeeQuery->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 6. MONTHLY LEAD GENERATION
// --------------------------------------------------

$monthlyLeadQuery = $pdo->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month_key,
        DATE_FORMAT(created_at, '%M %Y') AS month_name,
        COUNT(*) AS total_leads,

        SUM(CASE WHEN lead_status = 'converted' THEN 1 ELSE 0 END)
            AS converted_leads,

        SUM(CASE WHEN lead_status = 'lost' THEN 1 ELSE 0 END)
            AS lost_leads,

        SUM(
            CASE
                WHEN lead_status NOT IN ('converted', 'lost')
                THEN 1 ELSE 0
            END
        ) AS active_leads

    FROM leads

    GROUP BY
        DATE_FORMAT(created_at, '%Y-%m'),
        DATE_FORMAT(created_at, '%M %Y')

    ORDER BY month_key DESC
");

$monthlyLeadReports = $monthlyLeadQuery->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 7. LEAD SOURCE CONVERSION ANALYSIS
// --------------------------------------------------

$sourceQuery = $pdo->query("
    SELECT
        COALESCE(NULLIF(TRIM(lead_source), ''), 'Unknown') AS source_name,
        COUNT(*) AS total_leads,

        SUM(CASE WHEN lead_status = 'converted' THEN 1 ELSE 0 END)
            AS converted_leads,

        SUM(CASE WHEN lead_status = 'lost' THEN 1 ELSE 0 END)
            AS lost_leads,

        SUM(
            CASE
                WHEN lead_status NOT IN ('converted', 'lost')
                THEN 1 ELSE 0
            END
        ) AS active_leads

    FROM leads

    GROUP BY COALESCE(NULLIF(TRIM(lead_source), ''), 'Unknown')

    ORDER BY total_leads DESC, source_name ASC
");

$leadSources = $sourceQuery->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 8. FOLLOW-UP PERFORMANCE
// --------------------------------------------------

$followupTypeQuery = $pdo->query("
    SELECT
        followup_type,
        COUNT(*) AS total_followups
    FROM lead_followups
    GROUP BY followup_type
    ORDER BY total_followups DESC, followup_type ASC
");

$followupTypes = $followupTypeQuery->fetchAll(PDO::FETCH_ASSOC);

$followupEmployeeQuery = $pdo->query("
    SELECT
        a.id,
        a.name,
        a.email,

        COUNT(lf.id) AS total_followups,

        SUM(CASE WHEN lf.followup_type = 'call' THEN 1 ELSE 0 END)
            AS call_followups,

        SUM(CASE WHEN lf.followup_type = 'email' THEN 1 ELSE 0 END)
            AS email_followups,

        SUM(CASE WHEN lf.followup_type = 'whatsapp' THEN 1 ELSE 0 END)
            AS whatsapp_followups,

        SUM(CASE WHEN lf.followup_type = 'meeting' THEN 1 ELSE 0 END)
            AS meeting_followups,

        SUM(CASE WHEN lf.followup_type = 'note' THEN 1 ELSE 0 END)
            AS note_followups

    FROM admins a

    LEFT JOIN lead_followups lf
        ON lf.admin_id = a.id

    WHERE a.role = 'sales_employee'

    GROUP BY a.id, a.name, a.email

    ORDER BY total_followups DESC, a.name ASC
");

$followupEmployeeReports = $followupEmployeeQuery->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 9. DATE-WISE AND CUSTOM-RANGE ANALYTICS
// --------------------------------------------------

$today = date("Y-m-d");
$defaultStartDate = date("Y-m-01");

$startDate = isset($_GET["start_date"])
    ? trim((string)$_GET["start_date"])
    : $defaultStartDate;

$endDate = isset($_GET["end_date"])
    ? trim((string)$_GET["end_date"])
    : $today;

$dateRangeError = "";

function isValidReportDate($date) {

    if (!is_string($date) || $date === "") {
        return false;
    }

    $dateObject = DateTime::createFromFormat("!Y-m-d", $date);

    return $dateObject &&
        $dateObject->format("Y-m-d") === $date;
}

if (
    !isValidReportDate($startDate) ||
    !isValidReportDate($endDate)
) {
    $dateRangeError = "Please select valid start and end dates.";
    $startDate = $defaultStartDate;
    $endDate = $today;

} elseif ($startDate > $endDate) {

    $dateRangeError = "Start Date cannot be later than End Date.";
    $startDate = $defaultStartDate;
    $endDate = $today;
}

$rangeStartDateTime = $startDate . " 00:00:00";

$endDateObject = new DateTime($endDate);
$endDateObject->modify("+1 day");

$rangeEndDateTime = $endDateObject->format("Y-m-d") . " 00:00:00";

// --------------------------------------------------
// 9A. DATE RANGE LEAD SUMMARY
// --------------------------------------------------

$dateRangeSummaryQuery = $pdo->prepare("
    SELECT
        COUNT(*) AS total_leads,

        SUM(CASE WHEN lead_status = 'converted' THEN 1 ELSE 0 END)
            AS converted_leads,

        SUM(CASE WHEN lead_status = 'lost' THEN 1 ELSE 0 END)
            AS lost_leads,

        SUM(
            CASE
                WHEN lead_status NOT IN ('converted', 'lost')
                THEN 1 ELSE 0
            END
        ) AS active_leads

    FROM leads

    WHERE created_at >= :start_datetime
    AND created_at < :end_datetime
");

$dateRangeSummaryQuery->execute([
    ":start_datetime" => $rangeStartDateTime,
    ":end_datetime" => $rangeEndDateTime
]);

$dateRangeSummary = $dateRangeSummaryQuery->fetch(PDO::FETCH_ASSOC);

$dateRangeTotal = (int)($dateRangeSummary["total_leads"] ?? 0);
$dateRangeConverted = (int)($dateRangeSummary["converted_leads"] ?? 0);
$dateRangeLost = (int)($dateRangeSummary["lost_leads"] ?? 0);
$dateRangeActive = (int)($dateRangeSummary["active_leads"] ?? 0);

// --------------------------------------------------
// 9B. DAILY LEAD GENERATION
// --------------------------------------------------

$dateWiseLeadQuery = $pdo->prepare("
    SELECT
        DATE(created_at) AS report_date,
        COUNT(*) AS total_leads,

        SUM(CASE WHEN lead_status = 'converted' THEN 1 ELSE 0 END)
            AS converted_leads,

        SUM(CASE WHEN lead_status = 'lost' THEN 1 ELSE 0 END)
            AS lost_leads,

        SUM(
            CASE
                WHEN lead_status NOT IN ('converted', 'lost')
                THEN 1 ELSE 0
            END
        ) AS active_leads

    FROM leads

    WHERE created_at >= :start_datetime
    AND created_at < :end_datetime

    GROUP BY DATE(created_at)

    ORDER BY report_date DESC
");

$dateWiseLeadQuery->execute([
    ":start_datetime" => $rangeStartDateTime,
    ":end_datetime" => $rangeEndDateTime
]);

$dateWiseLeadReports = $dateWiseLeadQuery->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// 9C. ADVERTISEMENT CAMPAIGN AND UTM PERFORMANCE
// --------------------------------------------------

$campaignQuery = $pdo->prepare("
    SELECT
        COALESCE(
            NULLIF(TRIM(pe.source_platform), ''),
            'website'
        ) AS source_platform,

        COALESCE(
            NULLIF(TRIM(pe.source_campaign), ''),
            'Unspecified Campaign'
        ) AS campaign_name,

        COALESCE(
            NULLIF(TRIM(pe.utm_medium), ''),
            'Not specified'
        ) AS utm_medium,

        COALESCE(
            NULLIF(TRIM(pe.utm_content), ''),
            'Not specified'
        ) AS utm_content,

        COALESCE(
            NULLIF(TRIM(pe.utm_term), ''),
            'Not specified'
        ) AS utm_term,

        COUNT(pe.id) AS total_enquiries,

        SUM(
            CASE WHEN l.lead_status = 'new'
            THEN 1 ELSE 0 END
        ) AS new_leads,

        SUM(
            CASE WHEN l.lead_status = 'converted'
            THEN 1 ELSE 0 END
        ) AS converted_leads,

        SUM(
            CASE WHEN l.lead_status = 'lost'
            THEN 1 ELSE 0 END
        ) AS lost_leads,

        SUM(
            CASE
                WHEN l.id IS NOT NULL
                AND l.lead_status NOT IN ('converted', 'lost', 'new')
                THEN 1 ELSE 0
            END
        ) AS other_active_leads,

        SUM(
            CASE WHEN pe.fbclid IS NOT NULL AND pe.fbclid <> ''
            THEN 1 ELSE 0 END
        ) AS facebook_clicks_tracked,

        SUM(
            CASE WHEN pe.gclid IS NOT NULL AND pe.gclid <> ''
            THEN 1 ELSE 0 END
        ) AS google_clicks_tracked,

        SUM(
            CASE WHEN pe.ttclid IS NOT NULL AND pe.ttclid <> ''
            THEN 1 ELSE 0 END
        ) AS tiktok_clicks_tracked

    FROM property_enquiries pe

    LEFT JOIN leads l
        ON l.property_enquiry_id = pe.id

    WHERE pe.created_at >= :start_datetime
    AND pe.created_at < :end_datetime

    GROUP BY
        COALESCE(NULLIF(TRIM(pe.source_platform), ''), 'website'),
        COALESCE(NULLIF(TRIM(pe.source_campaign), ''), 'Unspecified Campaign'),
        COALESCE(NULLIF(TRIM(pe.utm_medium), ''), 'Not specified'),
        COALESCE(NULLIF(TRIM(pe.utm_content), ''), 'Not specified'),
        COALESCE(NULLIF(TRIM(pe.utm_term), ''), 'Not specified')

    ORDER BY
        total_enquiries DESC,
        source_platform ASC,
        campaign_name ASC
");

$campaignQuery->execute([
    ":start_datetime" => $rangeStartDateTime,
    ":end_datetime" => $rangeEndDateTime
]);

$campaignReports = $campaignQuery->fetchAll(PDO::FETCH_ASSOC);

$totalCampaignEnquiries = 0;
$totalCampaignConverted = 0;
$totalCampaignLost = 0;
$totalFacebookClicksTracked = 0;
$totalGoogleClicksTracked = 0;
$totalTikTokClicksTracked = 0;

foreach ($campaignReports as $campaign) {

    $totalCampaignEnquiries += (int)$campaign["total_enquiries"];
    $totalCampaignConverted += (int)$campaign["converted_leads"];
    $totalCampaignLost += (int)$campaign["lost_leads"];

    $totalFacebookClicksTracked +=
        (int)$campaign["facebook_clicks_tracked"];

    $totalGoogleClicksTracked +=
        (int)$campaign["google_clicks_tracked"];

    $totalTikTokClicksTracked +=
        (int)$campaign["tiktok_clicks_tracked"];
}

$campaignConversionRate = $totalCampaignEnquiries > 0
    ? ($totalCampaignConverted / $totalCampaignEnquiries) * 100
    : 0;

// --------------------------------------------------
// 10. RECENT LEADS
// --------------------------------------------------

$recentLeadsQuery = $pdo->query("
    SELECT
        l.id,
        l.customer_name,
        l.phone,
        l.lead_status,
        l.lead_source,
        l.created_at,
        a.name AS assigned_employee

    FROM leads l

    LEFT JOIN admins a
        ON l.assigned_to = a.id

    ORDER BY l.created_at DESC

    LIMIT 10
");

$recentLeads = $recentLeadsQuery->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>CRM Reports</title>

    <style>

        * {
            box-sizing: border-box;
        }

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

        h1, h2 {
            color: #222;
        }

        .nav-links {
            margin-bottom: 25px;
        }

        .nav-links a {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 8px;
            color: #1769aa;
            text-decoration: none;
            font-weight: bold;
        }

        .summary-grid,
        .conversion-grid,
        .date-range-summary,
        .advertisement-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .summary-card,
        .card {
            background: white;
            padding: 22px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .summary-card h3 {
            margin: 0 0 12px;
            color: #666;
            font-size: 15px;
        }

        .summary-card .number {
            font-size: 30px;
            font-weight: bold;
            color: #1769aa;
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

        .status-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .status-item {
            background: #f4f6f9;
            padding: 15px;
            border-radius: 6px;
        }

        .status-item strong {
            display: block;
            font-size: 22px;
            color: #1769aa;
            margin-top: 7px;
        }

        .conversion-item {
            padding: 20px;
            border-radius: 8px;
            background: #f4f6f9;
        }

        .conversion-item h3 {
            margin: 0 0 12px;
            font-size: 14px;
            color: #666;
        }

        .conversion-item .number {
            font-size: 28px;
            font-weight: bold;
        }

        .conversion-total .number {
            color: #1769aa;
        }

        .conversion-success .number {
            color: #16803c;
        }

        .conversion-lost .number {
            color: #d93025;
        }

        .conversion-active .number {
            color: #d97706;
        }

        .conversion-rate-section {
            margin-top: 25px;
        }

        .conversion-rate-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .conversion-rate-heading strong {
            font-size: 24px;
            color: #16803c;
        }

        .progress-track {
            width: 100%;
            height: 22px;
            background: #e5e7eb;
            border-radius: 20px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #16803c;
            border-radius: 20px;
        }

        .conversion-note,
        .employee-performance-note,
        .monthly-report-note,
        .source-report-note,
        .followup-report-note,
        .date-range-note,
        .advertisement-report-note {
            color: #777;
            font-size: 13px;
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .employee-rate,
        .monthly-rate,
        .source-rate,
        .advertisement-campaign-rate {
            font-weight: bold;
            color: #16803c;
        }

        .followup-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin: 18px 0 25px;
        }

        .followup-type-item {
            background: #f4f6f9;
            padding: 16px;
            border-radius: 6px;
        }

        .followup-type-item span {
            display: block;
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .followup-type-item strong {
            display: block;
            color: #1769aa;
            font-size: 24px;
        }

        .followup-total {
            font-weight: bold;
            color: #1769aa;
        }

        .date-range-form {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 14px;
            margin: 18px 0 22px;
            padding: 18px;
            background: #f4f6f9;
            border-radius: 8px;
        }

        .date-range-field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .date-range-field label {
            font-size: 14px;
            font-weight: bold;
            color: #555;
        }

        .date-range-field input {
            min-width: 180px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
        }

        .date-range-form button,
        .date-range-reset {
            display: inline-block;
            padding: 11px 17px;
            border: 0;
            border-radius: 5px;
            background: #1769aa;
            color: white;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
        }

        .date-range-reset {
            background: #666;
        }

        .date-range-error {
            padding: 12px 15px;
            margin: 12px 0;
            border-radius: 5px;
            background: #fff0f0;
            color: #b42318;
        }

        .date-range-summary-item,
        .advertisement-summary-item {
            padding: 17px;
            border-radius: 7px;
            background: #f4f6f9;
        }

        .date-range-summary-item span,
        .advertisement-summary-item span {
            display: block;
            color: #666;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .date-range-summary-item strong,
        .advertisement-summary-item strong {
            display: block;
            color: #1769aa;
            font-size: 25px;
        }

        .date-range-summary-item.converted strong,
        .advertisement-summary-item.converted strong {
            color: #16803c;
        }

        .date-range-summary-item.lost strong,
        .advertisement-summary-item.lost strong {
            color: #d93025;
        }

        .date-range-summary-item.active strong {
            color: #d97706;
        }

        .empty {
            color: #777;
        }

        .tracking-cell {
            min-width: 180px;
            max-width: 260px;
            overflow-wrap: anywhere;
            font-size: 13px;
        }

        .tracking-cell div {
            margin-bottom: 5px;
        }

        .tracking-cell strong {
            color: #444;
        }

        @media (max-width: 600px) {

            body {
                padding: 12px;
            }

            .card,
            .summary-card {
                padding: 15px;
            }

            .conversion-rate-heading strong {
                font-size: 21px;
            }

            .date-range-field,
            .date-range-field input {
                width: 100%;
            }

            .date-range-form button,
            .date-range-reset {
                text-align: center;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <h1>CRM Reports</h1>

    <p>
        Monitor customer leads, sales employee performance,
        property visits and advertisement campaigns.
    </p>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="manage_leads.php">Manage Leads</a>
        <a href="lead_followups.php">Lead Follow-ups</a>
        <a href="site_visits.php">Site Visits</a>
    </div>

    <!-- SUMMARY CARDS -->

    <div class="summary-grid">

        <div class="summary-card">
            <h3>Total Leads</h3>
            <div class="number"><?php echo $totalLeads; ?></div>
        </div>

        <div class="summary-card">
            <h3>Total Site Visits</h3>
            <div class="number"><?php echo $totalVisits; ?></div>
        </div>

        <div class="summary-card">
            <h3>Total Follow-ups</h3>
            <div class="number"><?php echo $totalFollowups; ?></div>
        </div>

        <div class="summary-card">
            <h3>Sales Employees</h3>
            <div class="number"><?php echo count($employees); ?></div>
        </div>

    </div>

    <!-- LEAD CONVERSION REPORT -->

    <div class="card">

        <h2>Lead Conversion Rate Report</h2>

        <p>
            Understand how many customer leads are successfully
            converted into sales.
        </p>

        <div class="conversion-grid">

            <div class="conversion-item conversion-total">
                <h3>Total Leads</h3>
                <div class="number"><?php echo $conversionTotal; ?></div>
            </div>

            <div class="conversion-item conversion-success">
                <h3>Converted Leads</h3>
                <div class="number"><?php echo $convertedLeads; ?></div>
            </div>

            <div class="conversion-item conversion-lost">
                <h3>Lost Leads</h3>
                <div class="number"><?php echo $lostLeads; ?></div>
            </div>

            <div class="conversion-item conversion-active">
                <h3>Active Leads</h3>
                <div class="number"><?php echo $activeLeads; ?></div>
            </div>

        </div>

        <div class="conversion-rate-section">

            <div class="conversion-rate-heading">
                <span>Overall Lead Conversion Rate</span>

                <strong>
                    <?php echo number_format($conversionRate, 2); ?>%
                </strong>
            </div>

            <div
                class="progress-track"
                role="progressbar"
                aria-label="Lead conversion rate"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="<?php echo number_format($conversionRate, 2, ".", ""); ?>"
            >
                <div
                    class="progress-fill"
                    style="width: <?php echo number_format($conversionRate, 2, ".", ""); ?>%;"
                ></div>
            </div>

            <p class="conversion-note">
                Conversion Rate = (Converted Leads / Total Leads) × 100.

                <?php if ($conversionTotal === 0): ?>
                    No leads are available to calculate the conversion rate.
                <?php else: ?>
                    <?php echo $convertedLeads; ?> out of
                    <?php echo $conversionTotal; ?> leads have been converted.
                <?php endif; ?>
            </p>

        </div>

    </div>

    <!-- LEAD STATUS REPORT -->

    <div class="card">

        <h2>Lead Status Report</h2>

        <div class="status-list">

            <?php if (count($leadStatuses) > 0): ?>

                <?php foreach ($leadStatuses as $status): ?>

                    <div class="status-item">

                        <?php
                        echo e(
                            ucwords(
                                str_replace("_", " ", $status["lead_status"])
                            )
                        );
                        ?>

                        <strong><?php echo (int)$status["total"]; ?></strong>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="empty">No leads found.</p>

            <?php endif; ?>

        </div>

    </div>

    <!-- SITE VISIT REPORT -->

    <div class="card">

        <h2>Site Visit Status Report</h2>

        <div class="status-list">

            <?php if (count($visitStatuses) > 0): ?>

                <?php foreach ($visitStatuses as $status): ?>

                    <div class="status-item">

                        <?php
                        echo e(
                            ucwords(
                                str_replace("_", " ", $status["visit_status"])
                            )
                        );
                        ?>

                        <strong><?php echo (int)$status["total"]; ?></strong>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="empty">No site visits found.</p>

            <?php endif; ?>

        </div>

    </div>

    <!-- EMPLOYEE-WISE PERFORMANCE -->

    <div class="card">

        <h2>Employee-wise Performance Report</h2>

        <p class="employee-performance-note">
            Compare each Sales Employee's assigned leads, conversions,
            lost leads, active leads, site visits and follow-up activity.
        </p>

        <?php if (count($employees) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Employee Name</th>
                            <th>Email</th>
                            <th>Total Leads</th>
                            <th>Converted Leads</th>
                            <th>Lost Leads</th>
                            <th>Active Leads</th>
                            <th>Conversion Rate</th>
                            <th>Total Visits</th>
                            <th>Completed Visits</th>
                            <th>Total Follow-ups</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($employees as $employee): ?>

                            <?php

                            $employeeTotalLeads = (int)$employee["total_leads"];
                            $employeeConvertedLeads = (int)$employee["converted_leads"];

                            $employeeConversionRate = $employeeTotalLeads > 0
                                ? ($employeeConvertedLeads / $employeeTotalLeads) * 100
                                : 0;

                            ?>

                            <tr>
                                <td><?php echo e($employee["name"]); ?></td>
                                <td><?php echo e($employee["email"]); ?></td>
                                <td><?php echo $employeeTotalLeads; ?></td>
                                <td><?php echo $employeeConvertedLeads; ?></td>
                                <td><?php echo (int)$employee["lost_leads"]; ?></td>
                                <td><?php echo (int)$employee["active_leads"]; ?></td>

                                <td class="employee-rate">
                                    <?php echo number_format($employeeConversionRate, 2); ?>%
                                </td>

                                <td><?php echo (int)$employee["total_visits"]; ?></td>
                                <td><?php echo (int)$employee["completed_visits"]; ?></td>
                                <td><?php echo (int)$employee["total_followups"]; ?></td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">No Sales Employees found.</p>

        <?php endif; ?>

    </div>

    <!-- MONTHLY LEAD GENERATION -->

    <div class="card">

        <h2>Monthly Lead Generation Report</h2>

        <p class="monthly-report-note">
            View how many leads were generated each month and how many
            are converted, lost or still active.
        </p>

        <?php if (count($monthlyLeadReports) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Total Leads</th>
                            <th>Converted Leads</th>
                            <th>Lost Leads</th>
                            <th>Active Leads</th>
                            <th>Conversion Rate</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($monthlyLeadReports as $monthlyReport): ?>

                            <?php

                            $monthTotal = (int)$monthlyReport["total_leads"];
                            $monthConverted = (int)$monthlyReport["converted_leads"];

                            $monthConversionRate = $monthTotal > 0
                                ? ($monthConverted / $monthTotal) * 100
                                : 0;

                            ?>

                            <tr>
                                <td><strong><?php echo e($monthlyReport["month_name"]); ?></strong></td>
                                <td><?php echo $monthTotal; ?></td>
                                <td><?php echo $monthConverted; ?></td>
                                <td><?php echo (int)$monthlyReport["lost_leads"]; ?></td>
                                <td><?php echo (int)$monthlyReport["active_leads"]; ?></td>

                                <td class="monthly-rate">
                                    <?php echo number_format($monthConversionRate, 2); ?>%
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">No monthly lead generation records found.</p>

        <?php endif; ?>

    </div>

    <!-- LEAD SOURCE CONVERSION ANALYSIS -->

    <div class="card">

        <h2>Lead Source Conversion Analysis</h2>

        <p class="source-report-note">
            Compare marketing sources to understand which sources
            generate the most leads and successful conversions.
        </p>

        <?php if (count($leadSources) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Lead Source</th>
                            <th>Total Leads</th>
                            <th>Converted Leads</th>
                            <th>Lost Leads</th>
                            <th>Active Leads</th>
                            <th>Conversion Rate</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($leadSources as $source): ?>

                            <?php

                            $sourceTotal = (int)$source["total_leads"];
                            $sourceConverted = (int)$source["converted_leads"];

                            $sourceConversionRate = $sourceTotal > 0
                                ? ($sourceConverted / $sourceTotal) * 100
                                : 0;

                            ?>

                            <tr>
                                <td><strong><?php echo e($source["source_name"]); ?></strong></td>
                                <td><?php echo $sourceTotal; ?></td>
                                <td><?php echo $sourceConverted; ?></td>
                                <td><?php echo (int)$source["lost_leads"]; ?></td>
                                <td><?php echo (int)$source["active_leads"]; ?></td>

                                <td class="source-rate">
                                    <?php echo number_format($sourceConversionRate, 2); ?>%
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">No lead source records found.</p>

        <?php endif; ?>

    </div>

    <!-- ADVERTISEMENT CAMPAIGN PERFORMANCE -->

    <div class="card">

        <h2>Advertisement Campaign Performance</h2>

        <p class="advertisement-report-note">
            Compare campaigns, UTM medium, advertisement content and
            keywords. The report uses the selected date range below.
        </p>

        <div class="advertisement-summary-grid">

            <div class="advertisement-summary-item">
                <span>Total Advertisement Enquiries</span>
                <strong><?php echo $totalCampaignEnquiries; ?></strong>
            </div>

            <div class="advertisement-summary-item converted">
                <span>Converted Leads</span>
                <strong><?php echo $totalCampaignConverted; ?></strong>
            </div>

            <div class="advertisement-summary-item lost">
                <span>Lost Leads</span>
                <strong><?php echo $totalCampaignLost; ?></strong>
            </div>

            <div class="advertisement-summary-item">
                <span>Advertisement Conversion Rate</span>
                <strong><?php echo number_format($campaignConversionRate, 2); ?>%</strong>
            </div>

        </div>

        <div class="advertisement-summary-grid">

            <div class="advertisement-summary-item">
                <span>Enquiries with Facebook Click ID</span>
                <strong><?php echo $totalFacebookClicksTracked; ?></strong>
            </div>

            <div class="advertisement-summary-item">
                <span>Enquiries with Google Click ID</span>
                <strong><?php echo $totalGoogleClicksTracked; ?></strong>
            </div>

            <div class="advertisement-summary-item">
                <span>Enquiries with TikTok Click ID</span>
                <strong><?php echo $totalTikTokClicksTracked; ?></strong>
            </div>

        </div>

        <?php if (count($campaignReports) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Platform</th>
                            <th>Campaign Name</th>
                            <th>UTM Medium</th>
                            <th>Advertisement Content</th>
                            <th>Keyword / UTM Term</th>
                            <th>Total Enquiries</th>
                            <th>New Leads</th>
                            <th>Converted</th>
                            <th>Lost</th>
                            <th>Other Active Leads</th>
                            <th>Conversion Rate</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($campaignReports as $campaign): ?>

                            <?php

                            $campaignTotal = (int)$campaign["total_enquiries"];
                            $campaignConverted = (int)$campaign["converted_leads"];

                            $campaignRate = $campaignTotal > 0
                                ? ($campaignConverted / $campaignTotal) * 100
                                : 0;

                            ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?php
                                        echo e(
                                            ucwords(
                                                str_replace("_", " ", $campaign["source_platform"])
                                            )
                                        );
                                        ?>
                                    </strong>
                                </td>

                                <td><?php echo e($campaign["campaign_name"]); ?></td>
                                <td><?php echo e($campaign["utm_medium"]); ?></td>
                                <td><?php echo e($campaign["utm_content"]); ?></td>
                                <td><?php echo e($campaign["utm_term"]); ?></td>

                                <td><?php echo $campaignTotal; ?></td>
                                <td><?php echo (int)$campaign["new_leads"]; ?></td>
                                <td><?php echo $campaignConverted; ?></td>
                                <td><?php echo (int)$campaign["lost_leads"]; ?></td>
                                <td><?php echo (int)$campaign["other_active_leads"]; ?></td>

                                <td class="advertisement-campaign-rate">
                                    <?php echo number_format($campaignRate, 2); ?>%
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">
                No advertisement property enquiries were found
                during the selected date range.
            </p>

        <?php endif; ?>

        <p class="advertisement-report-note">
            Conversion Rate = Converted CRM Leads divided by Total
            Advertisement Enquiries, multiplied by 100. Lead statuses
            reflect their current CRM status.
        </p>

    </div>

    <!-- FOLLOW-UP PERFORMANCE REPORT -->

    <div class="card">

        <h2>Follow-up Performance Report</h2>

        <p class="followup-report-note">
            Review follow-up activity by type and compare the number
            of follow-ups recorded by each Sales Employee.
        </p>

        <h3>Follow-ups by Type</h3>

        <?php if (count($followupTypes) > 0): ?>

            <div class="followup-type-grid">

                <?php foreach ($followupTypes as $followupType): ?>

                    <div class="followup-type-item">

                        <span>
                            <?php
                            echo e(
                                ucwords(
                                    str_replace("_", " ", $followupType["followup_type"])
                                )
                            );
                            ?>
                        </span>

                        <strong><?php echo (int)$followupType["total_followups"]; ?></strong>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <p class="empty">No follow-up records found.</p>

        <?php endif; ?>

        <h3>Employee-wise Follow-up Activity</h3>

        <p class="followup-report-note">
            Counts below are based on the Sales Employee recorded
            as the creator of each follow-up.
        </p>

        <?php if (count($followupEmployeeReports) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Employee Name</th>
                            <th>Email</th>
                            <th>Total Follow-ups</th>
                            <th>Calls</th>
                            <th>Emails</th>
                            <th>WhatsApp</th>
                            <th>Meetings</th>
                            <th>Notes</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($followupEmployeeReports as $followupEmployee): ?>

                            <tr>
                                <td><strong><?php echo e($followupEmployee["name"]); ?></strong></td>
                                <td><?php echo e($followupEmployee["email"]); ?></td>

                                <td class="followup-total">
                                    <?php echo (int)$followupEmployee["total_followups"]; ?>
                                </td>

                                <td><?php echo (int)$followupEmployee["call_followups"]; ?></td>
                                <td><?php echo (int)$followupEmployee["email_followups"]; ?></td>
                                <td><?php echo (int)$followupEmployee["whatsapp_followups"]; ?></td>
                                <td><?php echo (int)$followupEmployee["meeting_followups"]; ?></td>
                                <td><?php echo (int)$followupEmployee["note_followups"]; ?></td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">No Sales Employees found.</p>

        <?php endif; ?>

        <p class="followup-report-note">
            Note: The current lead_followups table does not contain
            a completion-status field. This report measures recorded
            follow-up activity.
        </p>

    </div>

    <!-- DATE-WISE AND CUSTOM-RANGE ANALYTICS -->

    <div class="card">

        <h2>Date-wise and Custom-Range Analytics</h2>

        <p class="date-range-note">
            Select a start date and end date to view leads created
            during that period, including their current status.
        </p>

        <?php if ($dateRangeError !== ""): ?>

            <div class="date-range-error">
                <?php echo e($dateRangeError); ?>
            </div>

        <?php endif; ?>

        <form method="GET" action="crm_reports.php" class="date-range-form">

            <div class="date-range-field">
                <label for="start_date">Start Date</label>

                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?php echo e($startDate); ?>"
                    max="<?php echo e($today); ?>"
                    required
                >
            </div>

            <div class="date-range-field">
                <label for="end_date">End Date</label>

                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?php echo e($endDate); ?>"
                    max="<?php echo e($today); ?>"
                    required
                >
            </div>

            <button type="submit">Apply Date Filter</button>

            <a href="crm_reports.php" class="date-range-reset">Reset</a>

        </form>

        <p class="date-range-note">
            Showing leads created from
            <strong><?php echo e($startDate); ?></strong>
            to
            <strong><?php echo e($endDate); ?></strong>,
            inclusive.
        </p>

        <div class="date-range-summary">

            <div class="date-range-summary-item">
                <span>Total Leads</span>
                <strong><?php echo $dateRangeTotal; ?></strong>
            </div>

            <div class="date-range-summary-item converted">
                <span>Converted Leads</span>
                <strong><?php echo $dateRangeConverted; ?></strong>
            </div>

            <div class="date-range-summary-item lost">
                <span>Lost Leads</span>
                <strong><?php echo $dateRangeLost; ?></strong>
            </div>

            <div class="date-range-summary-item active">
                <span>Active Leads</span>
                <strong><?php echo $dateRangeActive; ?></strong>
            </div>

        </div>

        <h3>Daily Lead Generation</h3>

        <?php if (count($dateWiseLeadReports) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Leads</th>
                            <th>Converted Leads</th>
                            <th>Lost Leads</th>
                            <th>Active Leads</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($dateWiseLeadReports as $dailyReport): ?>

                            <tr>
                                <td>
                                    <strong>
                                        <?php
                                        echo e(
                                            date(
                                                "d M Y",
                                                strtotime($dailyReport["report_date"])
                                            )
                                        );
                                        ?>
                                    </strong>
                                </td>

                                <td><?php echo (int)$dailyReport["total_leads"]; ?></td>
                                <td><?php echo (int)$dailyReport["converted_leads"]; ?></td>
                                <td><?php echo (int)$dailyReport["lost_leads"]; ?></td>
                                <td><?php echo (int)$dailyReport["active_leads"]; ?></td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">
                No leads were created during the selected date range.
            </p>

        <?php endif; ?>

        <p class="date-range-note">
            Note: This report groups leads by their creation date.
            Status counts reflect each lead's current status.
        </p>

    </div>

    <!-- RECENT LEADS -->

    <div class="card">

        <h2>Recent Customer Leads</h2>

        <?php if (count($recentLeads) > 0): ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>Assigned Employee</th>
                            <th>Created Date</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($recentLeads as $lead): ?>

                            <tr>
                                <td><?php echo (int)$lead["id"]; ?></td>
                                <td><?php echo e($lead["customer_name"]); ?></td>
                                <td><?php echo e($lead["phone"]); ?></td>

                                <td>
                                    <?php
                                    echo e(
                                        ucwords(
                                            str_replace("_", " ", $lead["lead_status"])
                                        )
                                    );
                                    ?>
                                </td>

                                <td><?php echo e($lead["lead_source"]); ?></td>

                                <td>
                                    <?php
                                    echo e(
                                        $lead["assigned_employee"] ?? "Not Assigned"
                                    );
                                    ?>
                                </td>

                                <td><?php echo e($lead["created_at"]); ?></td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty">No customer leads found.</p>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
