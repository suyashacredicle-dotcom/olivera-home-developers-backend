
<?php

// --------------------------------------------------
// PROPERTY ENQUIRIES MANAGEMENT
// --------------------------------------------------

require_once "../includes/permissions.php";
require_once "../config/database.php";

requirePermission("manage_users");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// --------------------------------------------------
// ESCAPE OUTPUT
// --------------------------------------------------

function propertyEnquiryAdminEscape($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
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

$csrfToken = $_SESSION["csrf_token"];

// --------------------------------------------------
// SEARCH
// --------------------------------------------------

$search = trim((string)($_GET["search"] ?? ""));
$search = substr($search, 0, 200);

// --------------------------------------------------
// STATUS OPTIONS
// --------------------------------------------------

$statusOptions = [
    "new",
    "contacted",
    "interested",
    "site_visit_scheduled",
    "negotiation",
    "converted",
    "lost"
];

// --------------------------------------------------
// SUMMARY STATISTICS
// --------------------------------------------------

$totalEnquiries = (int)$pdo->query("
    SELECT COUNT(*) FROM property_enquiries
")->fetchColumn();

$newEnquiries = (int)$pdo->query("
    SELECT COUNT(*) FROM property_enquiries WHERE status = 'new'
")->fetchColumn();

$convertedEnquiries = (int)$pdo->query("
    SELECT COUNT(*) FROM property_enquiries WHERE status = 'converted'
")->fetchColumn();

$lostEnquiries = (int)$pdo->query("
    SELECT COUNT(*) FROM property_enquiries WHERE status = 'lost'
")->fetchColumn();

// --------------------------------------------------
// FETCH PROPERTY ENQUIRIES
// --------------------------------------------------

$sql = "
    SELECT
        p.*,
        c.name AS customer_name,
        c.email AS customer_email,
        c.phone AS customer_phone,
        l.id AS crm_lead_id
    FROM property_enquiries p
    LEFT JOIN customers c ON c.id = p.customer_id
    LEFT JOIN leads l ON l.property_enquiry_id = p.id
";

$params = [];

if ($search !== "") {

    $sql .= "
        WHERE
            c.name LIKE :search_name
            OR c.email LIKE :search_email
            OR c.phone LIKE :search_phone
            OR p.preferred_location LIKE :search_location
            OR p.source_platform LIKE :search_source
            OR p.source_campaign LIKE :search_campaign
            OR p.budget LIKE :search_budget
            OR p.plot_size LIKE :search_plot
            OR p.customer_address LIKE :search_address
            OR p.utm_medium LIKE :search_medium
            OR p.utm_content LIKE :search_content
            OR p.utm_term LIKE :search_term
            OR p.fbclid LIKE :search_fbclid
            OR p.gclid LIKE :search_gclid
            OR p.gbraid LIKE :search_gbraid
            OR p.wbraid LIKE :search_wbraid
            OR p.ttclid LIKE :search_ttclid
    ";

    $searchValue = "%" . $search . "%";

    $params = [
        ":search_name" => $searchValue,
        ":search_email" => $searchValue,
        ":search_phone" => $searchValue,
        ":search_location" => $searchValue,
        ":search_source" => $searchValue,
        ":search_campaign" => $searchValue,
        ":search_budget" => $searchValue,
        ":search_plot" => $searchValue,
        ":search_address" => $searchValue,
        ":search_medium" => $searchValue,
        ":search_content" => $searchValue,
        ":search_term" => $searchValue,
        ":search_fbclid" => $searchValue,
        ":search_gclid" => $searchValue,
        ":search_gbraid" => $searchValue,
        ":search_wbraid" => $searchValue,
        ":search_ttclid" => $searchValue
    ];
}

$sql .= " ORDER BY p.created_at DESC, p.id DESC";

$enquiryQuery = $pdo->prepare($sql);
$enquiryQuery->execute($params);
$enquiries = $enquiryQuery->fetchAll(PDO::FETCH_ASSOC);

// --------------------------------------------------
// SOURCE LABELS
// --------------------------------------------------

$sourceLabels = [
    "website" => "Website",
    "facebook" => "Facebook",
    "instagram" => "Instagram",
    "google" => "Google Ads",
    "youtube" => "YouTube",
    "other" => "Other"
];

// --------------------------------------------------
// TRACKING LABELS
// --------------------------------------------------

$trackingLabels = [
    "utm_medium" => "Medium",
    "utm_content" => "Ad Content",
    "utm_term" => "Keyword",
    "fbclid" => "Facebook Click ID",
    "gclid" => "Google Click ID",
    "gbraid" => "Google GBRAID",
    "wbraid" => "Google WBRAID",
    "ttclid" => "TikTok Click ID"
];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Property Enquiries Management</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f3f5f9;
            color: #1e293b;
        }

        .page-container {
            max-width: 1600px;
            margin: auto;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin: 0 0 8px;
            color: #172554;
        }

        .page-heading p {
            margin: 0;
            color: #64748b;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .stat-card {
            padding: 22px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .stat-card span {
            display: block;
            margin-bottom: 10px;
            color: #64748b;
            font-size: 14px;
        }

        .stat-card strong {
            font-size: 27px;
            color: #172554;
        }

        .content-card {
            padding: 22px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-form input {
            flex: 1;
            min-width: 0;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-size: 14px;
        }

        .search-form button,
        .search-form a {
            display: inline-block;
            padding: 12px 18px;
            border: none;
            border-radius: 7px;
            background: #1d4ed8;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .search-form a {
            background: #64748b;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1500px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 13px 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            vertical-align: top;
            font-size: 13px;
            line-height: 1.6;
        }

        th {
            background: #f8fafc;
            color: #334155;
            font-weight: bold;
            white-space: nowrap;
        }

        td {
            color: #475569;
        }

        .customer-name {
            color: #172554;
            font-weight: bold;
        }

        .tracking-details {
            min-width: 220px;
            max-width: 300px;
            overflow-wrap: anywhere;
        }

        .tracking-item {
            margin-bottom: 9px;
        }

        .tracking-item:last-child {
            margin-bottom: 0;
        }

        .tracking-label {
            display: block;
            color: #64748b;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .tracking-value {
            display: block;
            color: #1e293b;
            font-size: 13px;
            margin-top: 2px;
        }

        .tracking-empty {
            color: #94a3b8;
            font-style: italic;
        }

        .status-form {
            min-width: 175px;
        }

        .status-form select {
            width: 100%;
            padding: 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            margin-bottom: 7px;
        }

        .status-form button {
            width: 100%;
            padding: 8px;
            border: none;
            border-radius: 6px;
            background: #1d4ed8;
            color: #fff;
            cursor: pointer;
        }

        .crm-link {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 6px;
            background: #eff6ff;
            color: #1d4ed8;
            text-decoration: none;
            font-weight: bold;
            white-space: nowrap;
        }

        .no-lead {
            color: #94a3b8;
        }

        .empty-state {
            padding: 35px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 800px) {
            body {
                padding: 12px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .content-card {
                padding: 14px;
            }

            .search-form {
                flex-wrap: wrap;
            }

            .search-form input {
                flex-basis: 100%;
            }
        }

        @media (max-width: 450px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="page-container">

    <div class="page-heading">
        <h1>Property Enquiries</h1>
        <p>Manage customer property enquiries and advertisement sources.</p>
    </div>

    <div class="stats-grid">

        <div class="stat-card">
            <span>Total Enquiries</span>
            <strong><?php echo $totalEnquiries; ?></strong>
        </div>

        <div class="stat-card">
            <span>New Enquiries</span>
            <strong><?php echo $newEnquiries; ?></strong>
        </div>

        <div class="stat-card">
            <span>Converted Enquiries</span>
            <strong><?php echo $convertedEnquiries; ?></strong>
        </div>

        <div class="stat-card">
            <span>Lost Enquiries</span>
            <strong><?php echo $lostEnquiries; ?></strong>
        </div>

    </div>

    <div class="content-card">

        <form method="GET" class="search-form">

            <input
                type="text"
                name="search"
                placeholder="Search customer, location, source, campaign or tracking ID..."
                value="<?php echo propertyEnquiryAdminEscape($search); ?>"
            >

            <button type="submit">Search</button>

            <a href="property_enquiries.php">Reset</a>

        </form>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>S. No.</th>
                        <th>Customer</th>
                        <th>Budget</th>
                        <th>Preferred Location</th>
                        <th>Plot Size</th>
                        <th>Full Address</th>
                        <th>Advertisement Source</th>
                        <th>Advertisement Details</th>
                        <th>Status Management</th>
                        <th>CRM Lead</th>
                        <th>Submitted At</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!empty($enquiries)): ?>

                    <?php $serialNumber = 1; ?>

                    <?php foreach ($enquiries as $enquiry): ?>

                        <tr>

                            <td><?php echo $serialNumber++; ?></td>

                            <td>
                                <div class="customer-name">
                                    <?php echo propertyEnquiryAdminEscape(
                                        $enquiry["customer_name"] ?? "Customer unavailable"
                                    ); ?>
                                </div>

                                <div><?php echo propertyEnquiryAdminEscape($enquiry["customer_email"] ?? ""); ?></div>
                                <div><?php echo propertyEnquiryAdminEscape($enquiry["customer_phone"] ?? ""); ?></div>
                            </td>

                            <td><?php echo propertyEnquiryAdminEscape($enquiry["budget"] ?? ""); ?></td>

                            <td><?php echo propertyEnquiryAdminEscape($enquiry["preferred_location"] ?? ""); ?></td>

                            <td><?php echo propertyEnquiryAdminEscape($enquiry["plot_size"] ?? ""); ?></td>

                            <td>
                                <?php echo nl2br(propertyEnquiryAdminEscape(
                                    $enquiry["customer_address"] ?? ""
                                )); ?>
                            </td>

                            <td>
                                <?php
                                $platform = strtolower((string)($enquiry["source_platform"] ?? "website"));
                                ?>

                                <strong>
                                    <?php echo propertyEnquiryAdminEscape(
                                        $sourceLabels[$platform] ?? "Other"
                                    ); ?>
                                </strong>

                                <?php if (!empty($enquiry["source_campaign"])): ?>
                                    <div>
                                        <span class="tracking-label">Campaign</span>
                                        <?php echo propertyEnquiryAdminEscape($enquiry["source_campaign"]); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- CLEAN ADVERTISEMENT DETAILS -->

                            <td>
                                <div class="tracking-details">

                                    <?php
                                    $hasTrackingDetails = false;

                                    foreach ($trackingLabels as $field => $label):

                                        $trackingValue = trim(
                                            (string)($enquiry[$field] ?? "")
                                        );

                                        if ($trackingValue === "") {
                                            continue;
                                        }

                                        $hasTrackingDetails = true;
                                    ?>

                                        <div class="tracking-item">
                                            <span class="tracking-label">
                                                <?php echo propertyEnquiryAdminEscape($label); ?>
                                            </span>

                                            <span class="tracking-value">
                                                <?php echo propertyEnquiryAdminEscape($trackingValue); ?>
                                            </span>
                                        </div>

                                    <?php endforeach; ?>

                                    <?php if (!$hasTrackingDetails): ?>
                                        <span class="tracking-empty">
                                            No additional tracking details
                                        </span>
                                    <?php endif; ?>

                                </div>
                            </td>

                            <td>
                                <form
                                    method="POST"
                                    action="actions/update_property_enquiry_status.php"
                                    class="status-form"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?php echo propertyEnquiryAdminEscape($csrfToken); ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="enquiry_id"
                                        value="<?php echo (int)$enquiry["id"]; ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="search"
                                        value="<?php echo propertyEnquiryAdminEscape($search); ?>"
                                    >

                                    <select name="status" required>

                                        <?php foreach ($statusOptions as $status): ?>

                                            <option
                                                value="<?php echo propertyEnquiryAdminEscape($status); ?>"
                                                <?php echo (
                                                    ($enquiry["status"] ?? "new") === $status
                                                ) ? "selected" : ""; ?>
                                            >
                                                <?php echo propertyEnquiryAdminEscape(
                                                    ucwords(str_replace("_", " ", $status))
                                                ); ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <button type="submit">Update Status</button>

                                </form>
                            </td>

                            <td>
                                <?php if (!empty($enquiry["crm_lead_id"])): ?>

                                    <a
                                        class="crm-link"
                                        href="view_lead.php?id=<?php echo (int)$enquiry["crm_lead_id"]; ?>"
                                    >
                                        View CRM Lead
                                    </a>

                                <?php else: ?>

                                    <span class="no-lead">No linked lead</span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <?php echo propertyEnquiryAdminEscape(
                                    $enquiry["created_at"] ?? ""
                                ); ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="11" class="empty-state">
                            No property enquiries found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>
</html>
