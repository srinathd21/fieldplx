<?php
/**
 * FieldPlx - Client View
 *
 * Upload as:
 * /public_html/client-view.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and permission
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode(
            'client-view.php?id=' .
            (isset($_GET['id']) ? (int) $_GET['id'] : 0)
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'clients.view',
        'You do not have permission to view clients.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Client Details - FieldPlx';
$activePage = 'client-view';
$searchPlaceholder = 'Search clients...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$clientId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($clientId <= 0) {
    header('Location: clients.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('clientViewFetchAssoc')) {
    function clientViewFetchAssoc(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                return $result->fetch_assoc();
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return null;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        if (!$stmt->fetch()) {
            return null;
        }

        $copy = array();

        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }

        return $copy;
    }
}

if (!function_exists('clientViewFetchAll')) {
    function clientViewFetchAll(mysqli_stmt $stmt)
    {
        $rows = array();

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }

                return $rows;
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return $rows;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        while ($stmt->fetch()) {
            $copy = array();

            foreach ($row as $key => $value) {
                $copy[$key] = $value;
            }

            $rows[] = $copy;
        }

        return $rows;
    }
}

if (!function_exists('clientViewDate')) {
    function clientViewDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y', $timestamp)
            : '—';
    }
}

if (!function_exists('clientViewDateTime')) {
    function clientViewDateTime($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('clientViewMoney')) {
    function clientViewMoney($amount, $currency)
    {
        return trim((string) $currency) .
            ' ' .
            number_format((float) $amount, 2);
    }
}

if (!function_exists('clientViewStatusClass')) {
    function clientViewStatusClass($status)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $status))
        );
    }
}

if (!function_exists('clientViewNullable')) {
    function clientViewNullable($value)
    {
        return trim((string) $value) !== ''
            ? (string) $value
            : '—';
    }
}

/*
|--------------------------------------------------------------------------
| Permissions for actions
|--------------------------------------------------------------------------
*/

$canUpdateClient = function_exists('hasPermission')
    ? hasPermission('clients.update')
    : true;

$canCreateProperty = function_exists('hasPermission')
    ? hasPermission('properties.manage')
    : true;

$canCreateRequest = function_exists('hasPermission')
    ? hasPermission('requests.manage')
    : true;

$canCreateQuote = function_exists('hasPermission')
    ? hasPermission('quotes.manage')
    : true;

$canCreateJob = function_exists('hasPermission')
    ? hasPermission('jobs.manage')
    : true;

$canCreateInvoice = function_exists('hasPermission')
    ? hasPermission('invoices.manage')
    : true;

$canViewPayments = function_exists('hasPermission')
    ? hasPermission('payments.view')
    : true;

/*
|--------------------------------------------------------------------------
| Client record
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        c.*,
        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN COALESCE(u.last_name, '') <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS account_manager_name
    FROM clients c
    LEFT JOIN users u
        ON u.id = c.account_manager_id
       AND u.tenant_id = c.tenant_id
    WHERE c.id = ?
      AND c.tenant_id = ?
      AND c.deleted_at IS NULL
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load client.');
}

$stmt->bind_param(
    'ii',
    $clientId,
    $tenantId
);

$stmt->execute();
$client = clientViewFetchAssoc($stmt);
$stmt->close();

if (!$client) {
    http_response_code(404);
    $pageTitle = 'Client Not Found - FieldPlx';

    require_once __DIR__ . '/includes/topbar.php';
    ?>
    <div style="padding:30px;text-align:center;">
        <h2>Client not found</h2>
        <p>
            This client does not exist or is not available for your business.
        </p>
        <a href="clients.php">Back to Clients</a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$currencyCode = !empty($_SESSION['currency_code'])
    ? (string) $_SESSION['currency_code']
    : 'INR';

/*
|--------------------------------------------------------------------------
| Related records
|--------------------------------------------------------------------------
*/

$contacts = array();
$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name,
        title,
        email,
        phone,
        is_primary,
        allow_email,
        allow_sms
    FROM client_contacts
    WHERE tenant_id = ?
      AND client_id = ?
    ORDER BY is_primary DESC, first_name ASC
");

if ($stmt) {
    $stmt->bind_param('ii', $tenantId, $clientId);
    $stmt->execute();
    $contacts = clientViewFetchAll($stmt);
    $stmt->close();
}

$properties = array();
$stmt = $conn->prepare("
    SELECT
        id,
        name,
        address_line1,
        address_line2,
        city,
        state,
        postal_code,
        country,
        is_primary,
        status
    FROM properties
    WHERE tenant_id = ?
      AND client_id = ?
      AND deleted_at IS NULL
    ORDER BY is_primary DESC, created_at DESC
    LIMIT 10
");

if ($stmt) {
    $stmt->bind_param('ii', $tenantId, $clientId);
    $stmt->execute();
    $properties = clientViewFetchAll($stmt);
    $stmt->close();
}

$requests = array();
$stmt = $conn->prepare("
    SELECT
        id,
        request_no,
        title,
        status,
        priority,
        requested_date,
        created_at
    FROM requests
    WHERE tenant_id = ?
      AND client_id = ?
      AND archived_at IS NULL
    ORDER BY created_at DESC
    LIMIT 8
");

if ($stmt) {
    $stmt->bind_param('ii', $tenantId, $clientId);
    $stmt->execute();
    $requests = clientViewFetchAll($stmt);
    $stmt->close();
}

$jobs = array();
$stmt = $conn->prepare("
    SELECT
        id,
        job_no,
        title,
        status,
        start_date,
        end_date,
        total,
        created_at
    FROM jobs
    WHERE tenant_id = ?
      AND client_id = ?
      AND deleted_at IS NULL
    ORDER BY created_at DESC
    LIMIT 8
");

if ($stmt) {
    $stmt->bind_param('ii', $tenantId, $clientId);
    $stmt->execute();
    $jobs = clientViewFetchAll($stmt);
    $stmt->close();
}

$quotes = array();
$stmt = $conn->prepare("
    SELECT
        id,
        quote_no,
        title,
        status,
        total,
        valid_until,
        created_at
    FROM quotes
    WHERE tenant_id = ?
      AND client_id = ?
      AND archived_at IS NULL
    ORDER BY created_at DESC
    LIMIT 8
");

if ($stmt) {
    $stmt->bind_param('ii', $tenantId, $clientId);
    $stmt->execute();
    $quotes = clientViewFetchAll($stmt);
    $stmt->close();
}

$invoices = array();
$stmt = $conn->prepare("
    SELECT
        id,
        invoice_no,
        status,
        issue_date,
        due_date,
        total,
        amount_paid,
        balance_due,
        created_at
    FROM invoices
    WHERE tenant_id = ?
      AND client_id = ?
      AND archived_at IS NULL
    ORDER BY created_at DESC
    LIMIT 8
");

if ($stmt) {
    $stmt->bind_param('ii', $tenantId, $clientId);
    $stmt->execute();
    $invoices = clientViewFetchAll($stmt);
    $stmt->close();
}

$payments = array();

if ($canViewPayments) {
    $stmt = $conn->prepare("
        SELECT
            id,
            payment_no,
            payment_method,
            payment_channel,
            status,
            amount,
            currency_code,
            received_at,
            created_at
        FROM payments
        WHERE tenant_id = ?
          AND client_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ");

    if ($stmt) {
        $stmt->bind_param('ii', $tenantId, $clientId);
        $stmt->execute();
        $payments = clientViewFetchAll($stmt);
        $stmt->close();
    }
}

$activity = array();
$stmt = $conn->prepare("
    SELECT
        id,
        event_type,
        title,
        created_at
    FROM activity_events
    WHERE tenant_id = ?
      AND client_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");

if ($stmt) {
    $stmt->bind_param('ii', $tenantId, $clientId);
    $stmt->execute();
    $activity = clientViewFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Summary totals
|--------------------------------------------------------------------------
*/

$totalJobs = count($jobs);
$totalQuotes = count($quotes);
$totalInvoices = count($invoices);

$totalOutstanding = 0.00;
$totalInvoiced = 0.00;
$totalPaid = 0.00;

foreach ($invoices as $invoice) {
    $totalInvoiced += (float) $invoice['total'];
    $totalOutstanding += (float) $invoice['balance_due'];
    $totalPaid += (float) $invoice['amount_paid'];
}

$pageTitle =
    (string) $client['display_name'] .
    ' - Client Details - FieldPlx';

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
:root {
    --fieldplx-primary: #74b824;
    --fieldplx-primary-dark: #5d971b;
    --fieldplx-text: #0b1933;
    --fieldplx-muted: #6f7b90;
    --fieldplx-border: #e5eaf1;
    --fieldplx-surface: #ffffff;
    --fieldplx-background: #f6f8fb;
    --fieldplx-topbar-height: 70px;
    --fieldplx-sidebar-width: 250px;
    --fieldplx-sidebar-collapsed-width: 78px;
    --cv-navy: #001131;
    --cv-navy-light: #071f49;
    --cv-blue: #123d70;
    --cv-primary: #74b824;
    --cv-primary-dark: #5d971b;
    --cv-primary-soft: #f0f8e5;
    --cv-red: #e45b66;
    --cv-bg: #f6f8fb;
    --cv-text: #0b1933;
    --cv-muted: #6f7b90;
    --cv-border: #e5eaf1;
}

body {
    background: var(--cv-bg) !important;
    color: var(--cv-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

/* Shared FieldPlx shell - same visual system as the new dashboard */
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--cv-border) !important;
    box-shadow: 0 3px 14px rgba(0,17,49,.035);
    backdrop-filter: none !important;
    transition: margin-left .25s ease, width .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: var(--fieldplx-sidebar-collapsed-width);
    width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
}
.fieldplx-topbar-inner {
    min-height: 70px !important;
    padding: 0 27px !important;
    gap: 13px !important;
}
.fieldplx-page-heading { display: none !important; }
.fieldplx-menu-toggle,
.fieldplx-topbar-action {
    width: 41px !important;
    height: 41px !important;
    border: 0 !important;
    border-radius: 9px !important;
    color: var(--cv-navy) !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--cv-navy) !important;
    background: var(--cv-primary-soft) !important;
}
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--cv-text) !important;
    font-size: 14px !important;
}
.fieldplx-search-input:focus {
    background: #f5f8fb !important;
    box-shadow: 0 0 0 3px rgba(116,184,36,.14) !important;
}
.fieldplx-profile-button {
    padding: 2px !important;
    border: 0 !important;
    border-radius: 9px !important;
    background: transparent !important;
}
.fieldplx-profile-button:hover { background: var(--cv-primary-soft) !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--cv-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: var(--cv-muted) !important; font-size: 12px !important; }
.fieldplx-notification-count { background: var(--cv-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--cv-border) !important;
    box-shadow: 0 18px 45px rgba(29,38,74,.14) !important;
}
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--cv-primary-dark) !important; }

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,var(--cv-navy-light),var(--cv-navy)) !important;
    border-top: 4px solid var(--cv-primary) !important;
    border-right: 0 !important;
    transition: width .25s ease, min-width .25s ease, transform .25s ease !important;
}
body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: var(--fieldplx-sidebar-collapsed-width) !important;
    min-width: var(--fieldplx-sidebar-collapsed-width) !important;
}
.fieldplx-sidebar-header {
    min-height: 68px !important;
    padding: 9px 14px 10px !important;
    border-bottom: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-brand { color: #fff !important; }
.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
    width: 40px !important;
    height: 40px !important;
    flex: 0 0 40px !important;
    border-radius: 10px !important;
}
.fieldplx-sidebar-logo-placeholder {
    color: #fff !important;
    background: linear-gradient(135deg,#8fd236,#68aa1d) !important;
    font-size: 18px !important;
}
.fieldplx-sidebar-company-name {
    max-width: 155px !important;
    color: #fff !important;
    font-size: 16px !important;
    font-weight: 700 !important;
}
.fieldplx-sidebar-product-name { color: #9fda55 !important; font-size: 11px !important; }
.fieldplx-sidebar-body { padding: 12px 14px !important; scrollbar-width: none !important; }
.fieldplx-sidebar-body::-webkit-scrollbar { display: none; }
.fieldplx-sidebar-section-label {
    margin: 7px 12px 7px !important;
    color: rgba(255,255,255,.50) !important;
    font-size: 11px !important;
}
.fieldplx-sidebar-nav { gap: 3px !important; }
.fieldplx-sidebar-link {
    min-height: 46px !important;
    margin-bottom: 3px !important;
    padding: 0 14px !important;
    gap: 15px !important;
    border-radius: 9px !important;
    color: rgba(255,255,255,.94) !important;
    font-size: 15px !important;
    font-weight: 600 !important;
}
.fieldplx-sidebar-link:hover { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link {
    color: #fff !important;
    background: linear-gradient(90deg,#7fc92d,#68aa1d) !important;
    box-shadow: 0 6px 18px rgba(0,17,49,.28) !important;
}
.fieldplx-sidebar-link-icon {
    width: 21px !important;
    height: 21px !important;
    flex: 0 0 21px !important;
    font-size: 19px !important;
}
.fieldplx-sidebar-arrow { color: rgba(255,255,255,.65) !important; }
.fieldplx-sidebar-submenu { padding-left: 36px !important; }
.fieldplx-sidebar-sublink {
    min-height: 34px !important;
    color: rgba(255,255,255,.72) !important;
    font-size: 13px !important;
}
.fieldplx-sidebar-sublink::before { background: rgba(255,255,255,.35) !important; }
.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-sublink.active::before { background: #9fda55 !important; }
.fieldplx-sidebar-footer {
    padding: 10px 14px 14px !important;
    border-top: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-user { min-height: 62px; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-user-name { color: #fff !important; font-size: 14px !important; }
.fieldplx-sidebar-user-role { color: rgba(255,255,255,.60) !important; font-size: 11px !important; }
.fieldplx-sidebar-user-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    color: var(--cv-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
}
.fieldplx-sidebar-logout { color: rgba(255,255,255,.70) !important; }
.fieldplx-sidebar-logout:hover { color: #fff !important; background: rgba(228,91,102,.30) !important; }
.fieldplx-main-layout { display: block !important; min-height: calc(100vh - 70px) !important; }
.fieldplx-main-content {
    margin-left: var(--fieldplx-sidebar-width);
    min-width: 0;
    transition: margin-left .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-main-content { margin-left: var(--fieldplx-sidebar-collapsed-width); }
.fieldplx-content-wrapper { padding: 0 !important; }
.fieldplx-footer { display: none !important; }

/* Client View page */
.client-view-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.cv-header {
    margin-bottom: 23px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}
.cv-header-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 15px;
}
.cv-avatar {
    width: 58px;
    height: 58px;
    flex: 0 0 58px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg,var(--cv-blue),var(--cv-navy));
    box-shadow: 0 8px 22px rgba(0,17,49,.16);
    font-size: 22px;
    font-weight: 800;
}
.cv-header h1 {
    margin: 0 0 7px;
    color: var(--cv-text);
    font-size: 28px;
    line-height: 1.1;
    font-weight: 700;
}
.cv-header p {
    margin: 0;
    color: var(--cv-muted);
    font-size: 14px;
    line-height: 1.5;
}
.cv-header p .cv-status { margin: 0 3px; vertical-align: middle; }
.cv-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.cv-btn {
    height: 46px;
    padding: 0 15px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--cv-border);
    border-radius: 9px;
    background: #fff;
    color: #53627a;
    box-shadow: 0 4px 14px rgba(31,43,88,.04);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}
.cv-btn:hover {
    border-color: #cfe3ae;
    color: var(--cv-primary-dark);
    background: #f9fcf4;
}
.cv-btn.primary {
    border-color: var(--cv-primary);
    color: #fff;
    background: var(--cv-primary);
}
.cv-btn.primary:hover { border-color: var(--cv-primary-dark); color: #fff; background: var(--cv-primary-dark); }

/* Same dashboard stat cards */
.cv-grid-stats {
    margin-bottom: 18px;
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 14px;
}
.cv-stat {
    position: relative;
    min-height: 170px;
    padding: 25px 20px 8px;
    overflow: hidden;
    border: 1px solid var(--cv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.cv-stat-more {
    position: absolute;
    top: 13px;
    right: 11px;
    color: #8995a8;
    font-size: 18px;
}
.cv-stat-row { display: flex; align-items: flex-start; gap: 18px; }
.cv-stat-icon {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    font-size: 25px;
}
.cv-stat-icon.navy { background: var(--cv-blue); }
.cv-stat-icon.green { background: var(--cv-primary); }
.cv-stat-icon.dark-green { background: var(--cv-primary-dark); }
.cv-stat-icon.soft-green { background: #96c945; }
.cv-stat-copy { min-width: 0; }
.cv-stat-label {
    display: block;
    margin-bottom: 10px;
    color: #66748b;
    font-size: 13px;
    font-weight: 500;
}
.cv-stat-value {
    display: block;
    max-width: 100%;
    overflow: hidden;
    color: var(--cv-text);
    font-size: clamp(24px,2.1vw,34px);
    line-height: 1.05;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.cv-stat-note {
    display: block;
    margin-top: 14px;
    color: #8a95a8;
    font-size: 11px;
    line-height: 1.5;
}
.cv-stat-note strong { color: var(--cv-primary-dark); font-size: 11px; }
.cv-stat-wave {
    position: absolute;
    right: 18px;
    bottom: 7px;
    left: 18px;
    height: 38px;
    opacity: .72;
    pointer-events: none;
}
.cv-stat-wave svg { width: 100%; height: 100%; display: block; }
.cv-stat-wave path { fill: none; stroke: #d5e9ba; stroke-width: 2; vector-effect: non-scaling-stroke; }
.cv-stat-wave path.accent { stroke: var(--cv-primary); }

.cv-layout {
    display: grid;
    grid-template-columns: minmax(0,1.52fr) minmax(330px,.72fr);
    gap: 18px;
    align-items: start;
}
.cv-card {
    overflow: hidden;
    border: 1px solid var(--cv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.cv-card + .cv-card { margin-top: 18px; }
.cv-card-head {
    min-height: 58px;
    padding: 15px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid var(--cv-border);
}
.cv-card-head h2 {
    margin: 0;
    color: var(--cv-text);
    font-size: 18px;
    font-weight: 700;
}
.cv-card-link {
    color: var(--cv-primary-dark);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}
.cv-card-link:hover { color: var(--cv-navy); }
.cv-card-body { padding: 20px 18px; }
.cv-detail-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 22px 28px;
}
.cv-detail.full { grid-column: 1 / -1; }
.cv-detail-label {
    color: #7f8ba0;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .02em;
    text-transform: uppercase;
}
.cv-detail-value {
    margin-top: 6px;
    color: #33445f;
    font-size: 14px;
    line-height: 1.65;
    font-weight: 500;
    word-break: break-word;
}
.cv-detail-value a { color: var(--cv-blue); text-decoration: none; }
.cv-detail-value a:hover { color: var(--cv-primary-dark); }
.cv-status {
    display: inline-flex;
    padding: 5px 7px;
    border-radius: 5px;
    background: #f1f4f7;
    color: #5e6b80;
    font-size: 11px;
    line-height: 1.2;
    font-weight: 700;
    text-transform: capitalize;
}
.cv-status.active,
.cv-status.paid,
.cv-status.completed,
.cv-status.approved,
.cv-status.succeeded { color: #5d971b; background: #f0f8e5; }
.cv-status.new,
.cv-status.draft,
.cv-status.pending,
.cv-status.sent,
.cv-status.scheduled { color: #123d70; background: #edf2f7; }
.cv-status.overdue,
.cv-status.cancelled,
.cv-status.rejected,
.cv-status.failed { color: #b9444d; background: #fff0f1; }
.cv-status.in_progress,
.cv-status.partially_paid,
.cv-status.viewed { color: #8b6d16; background: #fff8e7; }

.cv-table-wrap { overflow-x: auto; padding: 0 18px; }
.cv-table {
    width: 100%;
    min-width: 650px;
    margin: 4px 0 0;
    border-collapse: collapse;
    white-space: nowrap;
}
.cv-table th,
.cv-table td { text-align: left; }
.cv-table th {
    padding: 14px 8px;
    border-bottom: 1px solid var(--cv-border);
    color: #65738a;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.cv-table td {
    padding: 16px 8px;
    border-bottom: 1px solid #f1f3f7;
    color: #33445f;
    font-size: 13px;
    vertical-align: middle;
}
.cv-table tbody tr:hover { background: #fbfdf8; }
.cv-table tbody tr:last-child td { border-bottom: 0; }
.cv-main-text { color: var(--cv-text); font-size: 13px; font-weight: 700; }
.cv-sub-text { margin-top: 3px; display: block; color: #8792a4; font-size: 11px; }

.cv-list { padding: 4px 18px 10px; }
.cv-list-item {
    padding: 14px 0;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border-bottom: 1px solid #f1f3f7;
}
.cv-list-item:last-child { border-bottom: 0; }
.cv-list-icon {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: var(--cv-primary-soft);
    color: var(--cv-primary-dark);
    font-size: 16px;
}
.cv-list-content { min-width: 0; flex: 1; }
.cv-list-title {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    color: var(--cv-text);
    font-size: 13px;
    line-height: 1.45;
    font-weight: 700;
}
.cv-list-meta {
    margin-top: 4px;
    color: #8792a4;
    font-size: 11px;
    line-height: 1.6;
}
.cv-list-item > .cv-card-link { flex: 0 0 auto; margin-top: 4px; }
.cv-empty { padding: 42px 18px; color: #8b97a9; font-size: 13px; text-align: center; }

@media (max-width: 1200px) {
    .cv-grid-stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .cv-layout { grid-template-columns: 1fr; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .client-view-page { padding: 20px 18px 30px; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 14px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .client-view-page { padding: 18px 13px 28px; }
    .cv-header { align-items: flex-start; flex-direction: column; margin-bottom: 18px; }
    .cv-header-main { align-items: flex-start; }
    .cv-header h1 { font-size: 24px; }
    .cv-actions { width: 100%; }
    .cv-btn { flex: 1; }
    .cv-grid-stats { grid-template-columns: 1fr; }
    .cv-stat { min-height: 160px; }
    .cv-detail-grid { grid-template-columns: 1fr; gap: 18px; }
    .cv-detail.full { grid-column: auto; }
    .cv-card-head { padding: 14px 15px; }
    .cv-card-body { padding: 17px 15px; }
    .cv-table-wrap { padding: 0 15px; }
    .cv-list { padding-left: 15px; padding-right: 15px; }
}
</style>

<div class="client-view-page">

    <div class="cv-header">
        <div class="cv-header-main">
            <div class="cv-avatar">
                <?= e(
                    strtoupper(
                        substr(
                            (string) $client['display_name'],
                            0,
                            1
                        )
                    )
                ); ?>
            </div>

            <div>
                <h1><?= e($client['display_name']); ?></h1>

                <p>
                    <?= e(ucfirst($client['client_type'])); ?>
                    ·
                    <span class="cv-status <?= e(
                        clientViewStatusClass($client['status'])
                    ); ?>">
                        <?= e($client['status']); ?>
                    </span>
                    · Created
                    <?= e(clientViewDate($client['created_at'])); ?>
                </p>
            </div>
        </div>

        <div class="cv-actions">
            <a
                href="clients.php"
                class="cv-btn"
            >
                <i class="bi bi-arrow-left"></i>
                Clients
            </a>

            <?php if ($canUpdateClient): ?>
                <a
                    href="client-edit.php?id=<?= $clientId; ?>"
                    class="cv-btn"
                >
                    <i class="bi bi-pencil"></i>
                    Edit
                </a>
            <?php endif; ?>

            <?php if ($canCreateRequest): ?>
                <a
                    href="request-add.php?client_id=<?= $clientId; ?>"
                    class="cv-btn primary"
                >
                    <i class="bi bi-plus-lg"></i>
                    New Request
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="cv-grid-stats">
        <article class="cv-stat">
            <span class="cv-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="cv-stat-row">
                <span class="cv-stat-icon navy"><i class="bi bi-briefcase"></i></span>
                <div class="cv-stat-copy">
                    <span class="cv-stat-label">Jobs</span>
                    <strong class="cv-stat-value"><?= e(number_format($totalJobs)); ?></strong>
                    <span class="cv-stat-note"><strong>Recent</strong> client job records</span>
                </div>
            </div>
            <span class="cv-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="cv-stat">
            <span class="cv-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="cv-stat-row">
                <span class="cv-stat-icon soft-green"><i class="bi bi-receipt"></i></span>
                <div class="cv-stat-copy">
                    <span class="cv-stat-label">Total Invoiced</span>
                    <strong class="cv-stat-value"><?= e(clientViewMoney($totalInvoiced, $currencyCode)); ?></strong>
                    <span class="cv-stat-note"><strong>Billing</strong> from recent invoices</span>
                </div>
            </div>
            <span class="cv-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="cv-stat">
            <span class="cv-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="cv-stat-row">
                <span class="cv-stat-icon green"><i class="bi bi-check-circle"></i></span>
                <div class="cv-stat-copy">
                    <span class="cv-stat-label">Paid</span>
                    <strong class="cv-stat-value"><?= e(clientViewMoney($totalPaid, $currencyCode)); ?></strong>
                    <span class="cv-stat-note"><strong>Collected</strong> against invoices</span>
                </div>
            </div>
            <span class="cv-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="cv-stat">
            <span class="cv-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="cv-stat-row">
                <span class="cv-stat-icon dark-green"><i class="bi bi-wallet2"></i></span>
                <div class="cv-stat-copy">
                    <span class="cv-stat-label">Outstanding</span>
                    <strong class="cv-stat-value"><?= e(clientViewMoney($totalOutstanding, $currencyCode)); ?></strong>
                    <span class="cv-stat-note"><strong>Due</strong> from open invoice balances</span>
                </div>
            </div>
            <span class="cv-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>
    </div>

    <div class="cv-layout">
        <main>
            <section class="cv-card">
                <div class="cv-card-head">
                    <h2>Client Information</h2>
                </div>

                <div class="cv-card-body">
                    <div class="cv-detail-grid">
                        <div class="cv-detail">
                            <div class="cv-detail-label">Company</div>
                            <div class="cv-detail-value">
                                <?= e(clientViewNullable($client['company_name'])); ?>
                            </div>
                        </div>

                        <div class="cv-detail">
                            <div class="cv-detail-label">Contact Person</div>
                            <div class="cv-detail-value">
                                <?= e(
                                    clientViewNullable(
                                        trim(
                                            (string) $client['first_name'] .
                                            ' ' .
                                            (string) $client['last_name']
                                        )
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="cv-detail">
                            <div class="cv-detail-label">Email</div>
                            <div class="cv-detail-value">
                                <?php if (!empty($client['email'])): ?>
                                    <a href="mailto:<?= e($client['email']); ?>">
                                        <?= e($client['email']); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="cv-detail">
                            <div class="cv-detail-label">Phone</div>
                            <div class="cv-detail-value">
                                <?= e(clientViewNullable($client['phone'])); ?>
                            </div>
                        </div>

                        <div class="cv-detail">
                            <div class="cv-detail-label">Alternate Phone</div>
                            <div class="cv-detail-value">
                                <?= e(clientViewNullable($client['alternate_phone'])); ?>
                            </div>
                        </div>

                        <div class="cv-detail">
                            <div class="cv-detail-label">Source</div>
                            <div class="cv-detail-value">
                                <?= e(clientViewNullable($client['source'])); ?>
                            </div>
                        </div>

                        <div class="cv-detail">
                            <div class="cv-detail-label">Preferred Contact</div>
                            <div class="cv-detail-value">
                                <?= e(ucfirst($client['preferred_contact_method'])); ?>
                            </div>
                        </div>

                        <div class="cv-detail">
                            <div class="cv-detail-label">Account Manager</div>
                            <div class="cv-detail-value">
                                <?= e(
                                    clientViewNullable(
                                        $client['account_manager_name']
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="cv-detail full">
                            <div class="cv-detail-label">Billing Address</div>
                            <div class="cv-detail-value">
                                <?php
                                $billingParts = array_filter(
                                    array(
                                        $client['billing_address_line1'],
                                        $client['billing_address_line2'],
                                        $client['billing_city'],
                                        $client['billing_state'],
                                        $client['billing_postal_code'],
                                        $client['billing_country']
                                    ),
                                    function ($value) {
                                        return trim((string) $value) !== '';
                                    }
                                );
                                ?>
                                <?= !empty($billingParts)
                                    ? e(implode(', ', $billingParts))
                                    : '—'; ?>
                            </div>
                        </div>

                        <div class="cv-detail">
                            <div class="cv-detail-label">Tax Number</div>
                            <div class="cv-detail-value">
                                <?= e(clientViewNullable($client['tax_number'])); ?>
                            </div>
                        </div>

                        <div class="cv-detail">
                            <div class="cv-detail-label">Communication</div>
                            <div class="cv-detail-value">
                                Email:
                                <?= !empty($client['allow_email']) ? 'Allowed' : 'Not allowed'; ?>
                                · SMS:
                                <?= !empty($client['allow_sms']) ? 'Allowed' : 'Not allowed'; ?>
                            </div>
                        </div>

                        <div class="cv-detail full">
                            <div class="cv-detail-label">Notes</div>
                            <div class="cv-detail-value">
                                <?= nl2br(
                                    e(
                                        clientViewNullable(
                                            $client['notes']
                                        )
                                    )
                                ); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="cv-card">
                <div class="cv-card-head">
                    <h2>Recent Jobs</h2>

                    <?php if ($canCreateJob): ?>
                        <a
                            href="job-add.php?client_id=<?= $clientId; ?>"
                            class="cv-card-link"
                        >
                            Create Job
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($jobs)): ?>
                    <div class="cv-table-wrap">
                        <table class="cv-table">
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    <th>Status</th>
                                    <th>Start</th>
                                    <th>Total</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td>
                                        <span class="cv-main-text">
                                            <?= e($job['title']); ?>
                                        </span>
                                        <span class="cv-sub-text">
                                            <?= e($job['job_no']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="cv-status <?= e(
                                            clientViewStatusClass($job['status'])
                                        ); ?>">
                                            <?= e($job['status']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= e(clientViewDate($job['start_date'])); ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            clientViewMoney(
                                                $job['total'],
                                                $currencyCode
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <a
                                            href="job-view.php?id=<?= (int) $job['id']; ?>"
                                            class="cv-card-link"
                                        >
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="cv-empty">No jobs found.</div>
                <?php endif; ?>
            </section>

            <section class="cv-card">
                <div class="cv-card-head">
                    <h2>Quotes</h2>

                    <?php if ($canCreateQuote): ?>
                        <a
                            href="quote-add.php?client_id=<?= $clientId; ?>"
                            class="cv-card-link"
                        >
                            Create Quote
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($quotes)): ?>
                    <div class="cv-table-wrap">
                        <table class="cv-table">
                            <thead>
                                <tr>
                                    <th>Quote</th>
                                    <th>Status</th>
                                    <th>Valid Until</th>
                                    <th>Total</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($quotes as $quote): ?>
                                <tr>
                                    <td>
                                        <span class="cv-main-text">
                                            <?= e(
                                                !empty($quote['title'])
                                                    ? $quote['title']
                                                    : $quote['quote_no']
                                            ); ?>
                                        </span>
                                        <span class="cv-sub-text">
                                            <?= e($quote['quote_no']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="cv-status <?= e(
                                            clientViewStatusClass($quote['status'])
                                        ); ?>">
                                            <?= e($quote['status']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= e(clientViewDate($quote['valid_until'])); ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            clientViewMoney(
                                                $quote['total'],
                                                $currencyCode
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <a
                                            href="quote-view.php?id=<?= (int) $quote['id']; ?>"
                                            class="cv-card-link"
                                        >
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="cv-empty">No quotes found.</div>
                <?php endif; ?>
            </section>

            <section class="cv-card">
                <div class="cv-card-head">
                    <h2>Invoices</h2>

                    <?php if ($canCreateInvoice): ?>
                        <a
                            href="invoice-add.php?client_id=<?= $clientId; ?>"
                            class="cv-card-link"
                        >
                            Create Invoice
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($invoices)): ?>
                    <div class="cv-table-wrap">
                        <table class="cv-table">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Status</th>
                                    <th>Due Date</th>
                                    <th>Total</th>
                                    <th>Balance</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td>
                                        <span class="cv-main-text">
                                            <?= e($invoice['invoice_no']); ?>
                                        </span>
                                        <span class="cv-sub-text">
                                            <?= e(clientViewDate($invoice['issue_date'])); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="cv-status <?= e(
                                            clientViewStatusClass($invoice['status'])
                                        ); ?>">
                                            <?= e($invoice['status']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= e(clientViewDate($invoice['due_date'])); ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            clientViewMoney(
                                                $invoice['total'],
                                                $currencyCode
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            clientViewMoney(
                                                $invoice['balance_due'],
                                                $currencyCode
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <a
                                            href="invoice-view.php?id=<?= (int) $invoice['id']; ?>"
                                            class="cv-card-link"
                                        >
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="cv-empty">No invoices found.</div>
                <?php endif; ?>
            </section>
        </main>

        <aside>
            <section class="cv-card">
                <div class="cv-card-head">
                    <h2>Contacts</h2>
                </div>

                <?php if (!empty($contacts)): ?>
                    <div class="cv-list">
                        <?php foreach ($contacts as $contact): ?>
                            <div class="cv-list-item">
                                <span class="cv-list-icon">
                                    <i class="bi bi-person"></i>
                                </span>

                                <div class="cv-list-content">
                                    <div class="cv-list-title">
                                        <?= e(
                                            trim(
                                                $contact['first_name'] .
                                                ' ' .
                                                $contact['last_name']
                                            )
                                        ); ?>
                                        <?= !empty($contact['is_primary'])
                                            ? ' · Primary'
                                            : ''; ?>
                                    </div>

                                    <div class="cv-list-meta">
                                        <?= e(clientViewNullable($contact['title'])); ?>
                                        <br>
                                        <?= e(clientViewNullable($contact['email'])); ?>
                                        ·
                                        <?= e(clientViewNullable($contact['phone'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="cv-empty">No contacts added.</div>
                <?php endif; ?>
            </section>

            <section class="cv-card">
                <div class="cv-card-head">
                    <h2>Properties</h2>

                    <?php if ($canCreateProperty): ?>
                        <a
                            href="property-add.php?client_id=<?= $clientId; ?>"
                            class="cv-card-link"
                        >
                            Add Property
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($properties)): ?>
                    <div class="cv-list">
                        <?php foreach ($properties as $property): ?>
                            <div class="cv-list-item">
                                <span class="cv-list-icon">
                                    <i class="bi bi-geo-alt"></i>
                                </span>

                                <div class="cv-list-content">
                                    <div class="cv-list-title">
                                        <?= e(
                                            !empty($property['name'])
                                                ? $property['name']
                                                : $property['address_line1']
                                        ); ?>
                                        <?= !empty($property['is_primary'])
                                            ? ' · Primary'
                                            : ''; ?>
                                    </div>

                                    <div class="cv-list-meta">
                                        <?= e(
                                            implode(
                                                ', ',
                                                array_filter(
                                                    array(
                                                        $property['address_line1'],
                                                        $property['city'],
                                                        $property['state'],
                                                        $property['postal_code']
                                                    )
                                                )
                                            )
                                        ); ?>
                                    </div>
                                </div>

                                <a
                                    href="property-view.php?id=<?= (int) $property['id']; ?>"
                                    class="cv-card-link"
                                >
                                    View
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="cv-empty">No properties found.</div>
                <?php endif; ?>
            </section>

            <section class="cv-card">
                <div class="cv-card-head">
                    <h2>Recent Requests</h2>
                </div>

                <?php if (!empty($requests)): ?>
                    <div class="cv-list">
                        <?php foreach ($requests as $request): ?>
                            <div class="cv-list-item">
                                <span class="cv-list-icon">
                                    <i class="bi bi-inbox"></i>
                                </span>

                                <div class="cv-list-content">
                                    <div class="cv-list-title">
                                        <?= e($request['title']); ?>
                                    </div>

                                    <div class="cv-list-meta">
                                        <?= e($request['request_no']); ?>
                                        ·
                                        <?= e($request['status']); ?>
                                        ·
                                        <?= e(clientViewDate($request['created_at'])); ?>
                                    </div>
                                </div>

                                <a
                                    href="request-view.php?id=<?= (int) $request['id']; ?>"
                                    class="cv-card-link"
                                >
                                    View
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="cv-empty">No requests found.</div>
                <?php endif; ?>
            </section>

            <?php if ($canViewPayments): ?>
                <section class="cv-card">
                    <div class="cv-card-head">
                        <h2>Recent Payments</h2>
                    </div>

                    <?php if (!empty($payments)): ?>
                        <div class="cv-list">
                            <?php foreach ($payments as $payment): ?>
                                <div class="cv-list-item">
                                    <span class="cv-list-icon">
                                        <i class="bi bi-cash-stack"></i>
                                    </span>

                                    <div class="cv-list-content">
                                        <div class="cv-list-title">
                                            <?= e(
                                                clientViewMoney(
                                                    $payment['amount'],
                                                    $payment['currency_code']
                                                )
                                            ); ?>
                                        </div>

                                        <div class="cv-list-meta">
                                            <?= e($payment['payment_no']); ?>
                                            ·
                                            <?= e($payment['payment_method']); ?>
                                            ·
                                            <?= e($payment['status']); ?>
                                            <br>
                                            <?= e(
                                                clientViewDateTime(
                                                    !empty($payment['received_at'])
                                                        ? $payment['received_at']
                                                        : $payment['created_at']
                                                )
                                            ); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="cv-empty">No payments found.</div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="cv-card">
                <div class="cv-card-head">
                    <h2>Recent Activity</h2>
                </div>

                <?php if (!empty($activity)): ?>
                    <div class="cv-list">
                        <?php foreach ($activity as $event): ?>
                            <div class="cv-list-item">
                                <span class="cv-list-icon">
                                    <i class="bi bi-activity"></i>
                                </span>

                                <div class="cv-list-content">
                                    <div class="cv-list-title">
                                        <?= e($event['title']); ?>
                                    </div>

                                    <div class="cv-list-meta">
                                        <?= e($event['event_type']); ?>
                                        ·
                                        <?= e(
                                            clientViewDateTime(
                                                $event['created_at']
                                            )
                                        ); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="cv-empty">No activity found.</div>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
