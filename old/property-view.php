<?php
/**
 * FieldPlx - Property View
 *
 * Upload as:
 * /public_html/property-view.php
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
            'property-view.php?id=' .
            (isset($_GET['id']) ? (int) $_GET['id'] : 0)
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'properties.view',
        'You do not have permission to view properties.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Property Details - FieldPlx';
$activePage = 'property-view';
$searchPlaceholder = 'Search properties...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];

$propertyId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($propertyId <= 0) {
    header('Location: properties.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('propertyViewFetchAssoc')) {
    function propertyViewFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('propertyViewFetchAll')) {
    function propertyViewFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('propertyViewDate')) {
    function propertyViewDate($value)
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

if (!function_exists('propertyViewDateTime')) {
    function propertyViewDateTime($value)
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

if (!function_exists('propertyViewStatusClass')) {
    function propertyViewStatusClass($status)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $status))
        );
    }
}

if (!function_exists('propertyViewText')) {
    function propertyViewText($value)
    {
        return trim((string) $value) !== ''
            ? (string) $value
            : '—';
    }
}

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

$canManageProperty = function_exists('hasPermission')
    ? hasPermission('properties.manage')
    : true;

$canCreateRequest = function_exists('hasPermission')
    ? hasPermission('requests.manage')
    : true;

$canCreateJob = function_exists('hasPermission')
    ? hasPermission('jobs.manage')
    : true;

$canViewJobs = function_exists('hasPermission')
    ? hasPermission('jobs.view')
    : true;

$canViewRequests = function_exists('hasPermission')
    ? hasPermission('requests.view')
    : true;

$canViewVisits = function_exists('hasPermission')
    ? hasPermission('visits.view')
    : true;

/*
|--------------------------------------------------------------------------
| Property record
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        p.*,
        c.display_name AS client_name,
        c.company_name AS client_company,
        c.email AS client_email,
        c.phone AS client_phone,
        c.status AS client_status
    FROM properties p
    INNER JOIN clients c
        ON c.id = p.client_id
       AND c.tenant_id = p.tenant_id
    WHERE p.id = ?
      AND p.tenant_id = ?
      AND p.deleted_at IS NULL
      AND c.deleted_at IS NULL
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load property.');
}

$stmt->bind_param(
    'ii',
    $propertyId,
    $tenantId
);

$stmt->execute();
$property = propertyViewFetchAssoc($stmt);
$stmt->close();

if (!$property) {
    http_response_code(404);

    require_once __DIR__ . '/includes/topbar.php';
    ?>
    <div style="padding:30px;text-align:center;">
        <h2>Property not found</h2>
        <p>
            This property does not exist or is not available for your business.
        </p>
        <a href="properties.php">Back to Properties</a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| Related records
|--------------------------------------------------------------------------
*/

$jobs = array();
$requests = array();
$visits = array();
$activity = array();

if ($canViewJobs) {
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
          AND property_id = ?
          AND deleted_at IS NULL
        ORDER BY created_at DESC
        LIMIT 10
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $tenantId,
            $propertyId
        );

        $stmt->execute();
        $jobs = propertyViewFetchAll($stmt);
        $stmt->close();
    }
}

if ($canViewRequests) {
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
          AND property_id = ?
          AND archived_at IS NULL
        ORDER BY created_at DESC
        LIMIT 10
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $tenantId,
            $propertyId
        );

        $stmt->execute();
        $requests = propertyViewFetchAll($stmt);
        $stmt->close();
    }
}

if ($canViewVisits) {
    $stmt = $conn->prepare("
        SELECT
            v.id,
            v.job_id,
            v.title,
            v.status,
            v.scheduled_start,
            v.scheduled_end,
            v.actual_start,
            v.actual_end,
            j.job_no,
            j.title AS job_title
        FROM visits v
        LEFT JOIN jobs j
            ON j.id = v.job_id
           AND j.tenant_id = v.tenant_id
        WHERE v.tenant_id = ?
          AND v.property_id = ?
        ORDER BY
            COALESCE(
                v.scheduled_start,
                v.created_at
            ) DESC
        LIMIT 10
    ");

    if ($stmt) {
        $stmt->bind_param(
            'ii',
            $tenantId,
            $propertyId
        );

        $stmt->execute();
        $visits = propertyViewFetchAll($stmt);
        $stmt->close();
    }
}

$stmt = $conn->prepare("
    SELECT
        id,
        event_type,
        title,
        created_at
    FROM activity_events
    WHERE tenant_id = ?
      AND related_type = 'property'
      AND related_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $propertyId
    );

    $stmt->execute();
    $activity = propertyViewFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$activeJobs = 0;
$openRequests = 0;
$upcomingVisits = 0;

foreach ($jobs as $job) {
    if (
        !in_array(
            $job['status'],
            array(
                'completed',
                'closed',
                'cancelled',
                'archived',
                'invoiced'
            ),
            true
        )
    ) {
        $activeJobs++;
    }
}

foreach ($requests as $request) {
    if (
        !in_array(
            $request['status'],
            array(
                'closed',
                'rejected',
                'archived',
                'converted'
            ),
            true
        )
    ) {
        $openRequests++;
    }
}

foreach ($visits as $visit) {
    if (
        !empty($visit['scheduled_start']) &&
        strtotime($visit['scheduled_start']) >= time() &&
        !in_array(
            $visit['status'],
            array(
                'completed',
                'cancelled',
                'missed'
            ),
            true
        )
    ) {
        $upcomingVisits++;
    }
}

$propertyTitle =
    trim((string) $property['name']) !== ''
        ? (string) $property['name']
        : (string) $property['address_line1'];

$pageTitle =
    $propertyTitle .
    ' - Property Details - FieldPlx';

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
    --pv-navy: #001131;
    --pv-navy-light: #071f49;
    --pv-blue: #123d70;
    --pv-primary: #74b824;
    --pv-primary-dark: #5d971b;
    --pv-primary-soft: #f0f8e5;
    --pv-red: #e45b66;
    --pv-bg: #f6f8fb;
    --pv-text: #0b1933;
    --pv-muted: #6f7b90;
    --pv-border: #e5eaf1;
}

body {
    background: var(--pv-bg) !important;
    color: var(--pv-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

/* Exact new FieldPlx dashboard shell */
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--pv-border) !important;
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
    color: var(--pv-navy) !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--pv-navy) !important;
    background: var(--pv-primary-soft) !important;
}
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--pv-text) !important;
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
.fieldplx-profile-button:hover { background: var(--pv-primary-soft) !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--pv-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: var(--pv-muted) !important; font-size: 12px !important; }
.fieldplx-notification-count { background: var(--pv-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--pv-border) !important;
    box-shadow: 0 18px 45px rgba(29,38,74,.14) !important;
}
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--pv-primary-dark) !important; }

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,var(--pv-navy-light),var(--pv-navy)) !important;
    border-top: 4px solid var(--pv-primary) !important;
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
    color: var(--pv-navy) !important;
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

/* Property View - approved new FieldPlx component system */
.property-view-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.pv-header {
    min-height: 108px;
    margin-bottom: 18px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border: 1px solid var(--pv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.pv-header-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 16px;
}
.pv-icon {
    width: 58px;
    height: 58px;
    flex: 0 0 58px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg,var(--pv-blue),var(--pv-navy));
    box-shadow: 0 8px 22px rgba(0,17,49,.16);
    font-size: 24px;
}
.pv-header h1 {
    margin: 0 0 7px;
    color: var(--pv-text);
    font-size: 28px;
    line-height: 1.1;
    font-weight: 700;
}
.pv-header p {
    margin: 0;
    color: var(--pv-muted);
    font-size: 14px;
    line-height: 1.5;
}
.pv-header p .pv-status { margin: 0 3px; vertical-align: middle; }
.pv-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.pv-btn {
    height: 46px;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--pv-border);
    border-radius: 9px;
    background: #fff;
    color: #53627a;
    box-shadow: 0 4px 14px rgba(31,43,88,.04);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}
.pv-btn i { font-size: 14px; }
.pv-btn:hover { border-color: #cfe3ae; color: var(--pv-primary-dark); background: #f9fcf4; }
.pv-btn.primary { border-color: var(--pv-primary); color: #fff; background: var(--pv-primary); }
.pv-btn.primary:hover { border-color: var(--pv-primary-dark); color: #fff; background: var(--pv-primary-dark); }

.pv-stats {
    margin-bottom: 18px;
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 14px;
}
.pv-stat {
    position: relative;
    min-height: 170px;
    padding: 25px 20px 8px;
    overflow: hidden;
    border: 1px solid var(--pv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.pv-stat-more {
    position: absolute;
    top: 13px;
    right: 11px;
    color: #8995a8;
    font-size: 18px;
}
.pv-stat-row { display: flex; align-items: flex-start; gap: 18px; }
.pv-stat-icon {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    font-size: 25px;
}
.pv-stat-icon.navy { background: var(--pv-blue); }
.pv-stat-icon.green { background: var(--pv-primary); }
.pv-stat-icon.dark-green { background: var(--pv-primary-dark); }
.pv-stat-icon.soft-green { background: #96c945; }
.pv-stat-copy { min-width: 0; }
.pv-stat-label {
    display: block;
    margin-bottom: 10px;
    color: #66748b;
    font-size: 13px;
    font-weight: 500;
}
.pv-stat-value {
    display: block;
    max-width: 245px;
    overflow: hidden;
    color: var(--pv-text);
    font-size: clamp(27px,2vw,34px);
    line-height: 1;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.pv-stat-note {
    display: block;
    margin-top: 14px;
    color: #8a95a8;
    font-size: 11px;
    line-height: 1.5;
}
.pv-stat-note strong { color: var(--pv-primary-dark); font-size: 11px; }
.pv-stat-wave {
    position: absolute;
    right: 18px;
    bottom: 7px;
    left: 18px;
    height: 38px;
    opacity: .72;
    pointer-events: none;
}
.pv-stat-wave svg { width: 100%; height: 100%; display: block; }
.pv-stat-wave path { fill: none; stroke: #d5e9ba; stroke-width: 2; vector-effect: non-scaling-stroke; }
.pv-stat-wave path.accent { stroke: var(--pv-primary); }

.pv-layout {
    display: grid;
    grid-template-columns: minmax(0,1.68fr) minmax(340px,.72fr);
    gap: 18px;
    align-items: start;
}
.pv-card {
    overflow: hidden;
    border: 1px solid var(--pv-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.pv-card + .pv-card { margin-top: 18px; }
.pv-card-head {
    min-height: 62px;
    padding: 17px 18px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid var(--pv-border);
    background: #fff;
}
.pv-card-head h2 {
    margin: 0;
    color: var(--pv-text);
    font-size: 18px;
    line-height: 1.2;
    font-weight: 700;
}
.pv-card-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 32px;
    padding: 0 9px;
    border-radius: 6px;
    color: var(--pv-primary-dark);
    background: var(--pv-primary-soft);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}
.pv-card-link:hover { color: #fff; background: var(--pv-primary); }
.pv-card-body { padding: 20px 18px; }

.pv-address-box {
    position: relative;
    padding: 18px 18px 17px 58px;
    overflow: hidden;
    border: 1px solid #dce8cc;
    border-radius: 9px;
    background: linear-gradient(135deg,#fbfdf8,#f3f9ea);
}
.pv-address-box::before {
    content: "\F47F";
    font-family: "bootstrap-icons";
    position: absolute;
    top: 18px;
    left: 18px;
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    border-radius: 8px;
    color: #fff;
    background: var(--pv-primary);
    font-size: 15px;
}
.pv-address-title {
    color: var(--pv-text);
    font-size: 16px;
    line-height: 1.35;
    font-weight: 700;
}
.pv-address-text {
    margin-top: 6px;
    color: #617086;
    font-size: 13px;
    line-height: 1.7;
}
.pv-map-link {
    margin-top: 10px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--pv-primary-dark);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}
.pv-map-link:hover { color: var(--pv-navy); }

.pv-detail-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 0;
    border: 1px solid #edf0f4;
    border-radius: 8px;
    overflow: hidden;
}
.pv-detail {
    min-height: 92px;
    padding: 17px 18px;
    border-right: 1px solid #edf0f4;
    border-bottom: 1px solid #edf0f4;
    background: #fff;
}
.pv-detail:nth-child(2n) { border-right: 0; }
.pv-detail.full { grid-column: 1 / -1; border-right: 0; }
.pv-detail-label {
    margin-bottom: 8px;
    color: #78859a;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .02em;
    text-transform: uppercase;
}
.pv-detail-value {
    color: #263956;
    font-size: 14px;
    line-height: 1.65;
    font-weight: 500;
    word-break: break-word;
}
.pv-detail-value a { color: var(--pv-blue); font-weight: 600; text-decoration: none; }
.pv-detail-value a:hover { color: var(--pv-primary-dark); }

.pv-status {
    display: inline-flex;
    align-items: center;
    padding: 5px 8px;
    border-radius: 5px;
    background: #f1f4f7;
    color: #5e6b80;
    font-size: 11px;
    line-height: 1.2;
    font-weight: 700;
    text-transform: capitalize;
}
.pv-status.active,
.pv-status.completed,
.pv-status.closed,
.pv-status.invoiced { color: #5d971b; background: #f0f8e5; }
.pv-status.new,
.pv-status.scheduled,
.pv-status.upcoming,
.pv-status.today { color: #123d70; background: #edf2f7; }
.pv-status.in_progress,
.pv-status.unscheduled,
.pv-status.needs_review,
.pv-status.action_required { color: #8b6d16; background: #fff8e7; }
.pv-status.inactive,
.pv-status.cancelled,
.pv-status.rejected,
.pv-status.late,
.pv-status.missed { color: #b9444d; background: #fff0f1; }

.pv-table-wrap { overflow-x: auto; padding: 0 18px; }
.pv-table {
    width: 100%;
    min-width: 680px;
    margin: 4px 0 0;
    border-collapse: collapse;
    white-space: nowrap;
}
.pv-table th,
.pv-table td { text-align: left; }
.pv-table th {
    padding: 14px 8px;
    border-bottom: 1px solid var(--pv-border);
    color: #65738a;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.pv-table td {
    padding: 16px 8px;
    border-bottom: 1px solid #f1f3f7;
    color: #33445f;
    font-size: 13px;
    vertical-align: middle;
}
.pv-table tbody tr:hover { background: #fbfdf8; }
.pv-table tbody tr:last-child td { border-bottom: 0; }
.pv-main { display: block; color: var(--pv-text); font-size: 13px; font-weight: 700; }
.pv-sub { margin-top: 3px; display: block; color: #8792a4; font-size: 11px; }

.pv-list { padding: 5px 18px 9px; }
.pv-list-item {
    min-height: 74px;
    padding: 14px 0;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border-bottom: 1px solid #f1f3f7;
}
.pv-list-item:last-child { border-bottom: 0; }
.pv-list-icon {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    color: var(--pv-navy);
    background: var(--pv-primary-soft);
    font-size: 17px;
}
.pv-list-content { min-width: 0; flex: 1; }
.pv-list-title {
    overflow: hidden;
    color: var(--pv-text);
    font-size: 13px;
    line-height: 1.45;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.pv-list-meta {
    margin-top: 4px;
    color: #7e8a9d;
    font-size: 11px;
    line-height: 1.65;
}
.pv-list-item > .pv-card-link { flex: 0 0 auto; margin-top: 3px; }
.pv-empty {
    padding: 46px 18px;
    color: #8b97a9;
    font-size: 13px;
    text-align: center;
}

@media (max-width: 1220px) {
    .pv-stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .pv-layout { grid-template-columns: 1fr; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .property-view-page { padding: 20px 18px 30px; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 14px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .property-view-page { padding: 18px 13px 28px; }
    .pv-header { align-items: flex-start; flex-direction: column; padding: 17px 15px; }
    .pv-header-main { align-items: flex-start; }
    .pv-header h1 { font-size: 24px; }
    .pv-actions { width: 100%; }
    .pv-btn { flex: 1; }
    .pv-stats { grid-template-columns: 1fr; }
    .pv-stat { min-height: 160px; }
    .pv-detail-grid { grid-template-columns: 1fr; }
    .pv-detail,
    .pv-detail:nth-child(2n),
    .pv-detail.full { grid-column: auto; border-right: 0; border-bottom: 1px solid #edf0f4; }
    .pv-card-head { padding: 15px; }
    .pv-card-body { padding: 15px; }
    .pv-table-wrap { padding: 0 15px; }
    .pv-list { padding-left: 15px; padding-right: 15px; }
    .pv-address-box { padding: 16px 15px 16px 54px; }
}

</style>

<div class="property-view-page">
    <div class="pv-header">
        <div class="pv-header-main">
            <div class="pv-icon">
                <i class="bi bi-geo-alt"></i>
            </div>

            <div>
                <h1><?= e($propertyTitle); ?></h1>

                <p>
                    <?= e($property['client_name']); ?>
                    ·
                    <span class="pv-status <?= e(
                        propertyViewStatusClass(
                            $property['status']
                        )
                    ); ?>">
                        <?= e($property['status']); ?>
                    </span>

                    <?php if (!empty($property['is_primary'])): ?>
                        · Primary Property
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="pv-actions">
            <a
                href="properties.php"
                class="pv-btn"
            >
                <i class="bi bi-arrow-left"></i>
                Properties
            </a>

            <?php if ($canManageProperty): ?>
                <a
                    href="property-edit.php?id=<?= $propertyId; ?>"
                    class="pv-btn"
                >
                    <i class="bi bi-pencil"></i>
                    Edit
                </a>
            <?php endif; ?>

            <?php if ($canCreateJob): ?>
                <a
                    href="job-add.php?client_id=<?= (int) $property['client_id']; ?>&property_id=<?= $propertyId; ?>"
                    class="pv-btn primary"
                >
                    <i class="bi bi-plus-lg"></i>
                    New Job
                </a>
            <?php endif; ?>
        </div>
    </div>

    <section class="pv-stats">
        <article class="pv-stat">
            <span class="pv-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="pv-stat-row">
                <span class="pv-stat-icon navy"><i class="bi bi-briefcase"></i></span>
                <div class="pv-stat-copy">
                    <span class="pv-stat-label">Total Jobs</span>
                    <strong class="pv-stat-value"><?= e(number_format(count($jobs))); ?></strong>
                    <span class="pv-stat-note"><strong>Jobs</strong> linked to this property</span>
                </div>
            </div>
            <span class="pv-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="pv-stat">
            <span class="pv-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="pv-stat-row">
                <span class="pv-stat-icon green"><i class="bi bi-tools"></i></span>
                <div class="pv-stat-copy">
                    <span class="pv-stat-label">Active Jobs</span>
                    <strong class="pv-stat-value"><?= e(number_format($activeJobs)); ?></strong>
                    <span class="pv-stat-note"><strong>Active</strong> work currently open</span>
                </div>
            </div>
            <span class="pv-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="pv-stat">
            <span class="pv-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="pv-stat-row">
                <span class="pv-stat-icon soft-green"><i class="bi bi-inbox"></i></span>
                <div class="pv-stat-copy">
                    <span class="pv-stat-label">Open Requests</span>
                    <strong class="pv-stat-value"><?= e(number_format($openRequests)); ?></strong>
                    <span class="pv-stat-note"><strong>Requests</strong> awaiting completion</span>
                </div>
            </div>
            <span class="pv-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="pv-stat">
            <span class="pv-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="pv-stat-row">
                <span class="pv-stat-icon dark-green"><i class="bi bi-calendar-check"></i></span>
                <div class="pv-stat-copy">
                    <span class="pv-stat-label">Upcoming Visits</span>
                    <strong class="pv-stat-value"><?= e(number_format($upcomingVisits)); ?></strong>
                    <span class="pv-stat-note"><strong>Scheduled</strong> upcoming site visits</span>
                </div>
            </div>
            <span class="pv-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>
    </section>

    <div class="pv-layout">
        <main>
            <section class="pv-card">
                <div class="pv-card-head">
                    <h2>Property Information</h2>
                    <span class="pv-status <?= e(propertyViewStatusClass($property['status'])); ?>"><?= e($property['status']); ?></span>
                </div>

                <div class="pv-card-body">
                    <div class="pv-address-box">
                        <div class="pv-address-title">
                            <?= e($propertyTitle); ?>
                        </div>

                        <div class="pv-address-text">
                            <?php
                            $addressParts = array_filter(
                                array(
                                    $property['address_line1'],
                                    $property['address_line2'],
                                    $property['city'],
                                    $property['state'],
                                    $property['postal_code'],
                                    $property['country']
                                ),
                                function ($value) {
                                    return trim((string) $value) !== '';
                                }
                            );
                            ?>
                            <?= e(
                                !empty($addressParts)
                                    ? implode(', ', $addressParts)
                                    : 'No address available'
                            ); ?>
                        </div>

                        <?php if (
                            $property['latitude'] !== null &&
                            $property['longitude'] !== null
                        ): ?>
                            <a
                                href="https://www.google.com/maps?q=<?= rawurlencode(
                                    $property['latitude'] .
                                    ',' .
                                    $property['longitude']
                                ); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="pv-map-link"
                            >
                                <i class="bi bi-map"></i>
                                Open in Maps
                            </a>
                        <?php endif; ?>
                    </div>

                    <div
                        class="pv-detail-grid"
                        style="margin-top:14px;"
                    >
                        <div class="pv-detail">
                            <div class="pv-detail-label">Service Area</div>
                            <div class="pv-detail-value">
                                <?= e(
                                    propertyViewText(
                                        $property['service_area']
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="pv-detail">
                            <div class="pv-detail-label">Tax Area</div>
                            <div class="pv-detail-value">
                                <?= e(
                                    propertyViewText(
                                        $property['tax_area']
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="pv-detail">
                            <div class="pv-detail-label">Latitude</div>
                            <div class="pv-detail-value">
                                <?= e(
                                    propertyViewText(
                                        $property['latitude']
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="pv-detail">
                            <div class="pv-detail-label">Longitude</div>
                            <div class="pv-detail-value">
                                <?= e(
                                    propertyViewText(
                                        $property['longitude']
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="pv-detail">
                            <div class="pv-detail-label">Gate Code</div>
                            <div class="pv-detail-value">
                                <?= e(
                                    propertyViewText(
                                        $property['gate_code']
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="pv-detail">
                            <div class="pv-detail-label">Created</div>
                            <div class="pv-detail-value">
                                <?= e(
                                    propertyViewDate(
                                        $property['created_at']
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="pv-detail full">
                            <div class="pv-detail-label">Access Notes</div>
                            <div class="pv-detail-value">
                                <?= nl2br(
                                    e(
                                        propertyViewText(
                                            $property['access_notes']
                                        )
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="pv-detail full">
                            <div class="pv-detail-label">
                                Service Instructions
                            </div>
                            <div class="pv-detail-value">
                                <?= nl2br(
                                    e(
                                        propertyViewText(
                                            $property['service_instructions']
                                        )
                                    )
                                ); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($canViewJobs): ?>
                <section class="pv-card">
                    <div class="pv-card-head">
                        <h2>Recent Jobs</h2>

                        <?php if ($canCreateJob): ?>
                            <a
                                href="job-add.php?client_id=<?= (int) $property['client_id']; ?>&property_id=<?= $propertyId; ?>"
                                class="pv-card-link"
                            >
                                Create Job
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($jobs)): ?>
                        <div class="pv-table-wrap">
                            <table class="pv-table">
                                <thead>
                                    <tr>
                                        <th>Job</th>
                                        <th>Status</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th></th>
                                    </tr>
                                </thead>

                                <tbody>
                                <?php foreach ($jobs as $job): ?>
                                    <tr>
                                        <td>
                                            <span class="pv-main">
                                                <?= e($job['title']); ?>
                                            </span>

                                            <span class="pv-sub">
                                                <?= e($job['job_no']); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="pv-status <?= e(
                                                propertyViewStatusClass(
                                                    $job['status']
                                                )
                                            ); ?>">
                                                <?= e(
                                                    str_replace(
                                                        '_',
                                                        ' ',
                                                        $job['status']
                                                    )
                                                ); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= e(
                                                propertyViewDate(
                                                    $job['start_date']
                                                )
                                            ); ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                propertyViewDate(
                                                    $job['end_date']
                                                )
                                            ); ?>
                                        </td>

                                        <td>
                                            <a
                                                href="job-view.php?id=<?= (int) $job['id']; ?>"
                                                class="pv-card-link"
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
                        <div class="pv-empty">
                            No jobs found for this property.
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($canViewRequests): ?>
                <section class="pv-card">
                    <div class="pv-card-head">
                        <h2>Recent Requests</h2>

                        <?php if ($canCreateRequest): ?>
                            <a
                                href="request-add.php?client_id=<?= (int) $property['client_id']; ?>&property_id=<?= $propertyId; ?>"
                                class="pv-card-link"
                            >
                                Create Request
                            </a>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($requests)): ?>
                        <div class="pv-table-wrap">
                            <table class="pv-table">
                                <thead>
                                    <tr>
                                        <th>Request</th>
                                        <th>Status</th>
                                        <th>Priority</th>
                                        <th>Date</th>
                                        <th></th>
                                    </tr>
                                </thead>

                                <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td>
                                            <span class="pv-main">
                                                <?= e($request['title']); ?>
                                            </span>

                                            <span class="pv-sub">
                                                <?= e($request['request_no']); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="pv-status <?= e(
                                                propertyViewStatusClass(
                                                    $request['status']
                                                )
                                            ); ?>">
                                                <?= e(
                                                    str_replace(
                                                        '_',
                                                        ' ',
                                                        $request['status']
                                                    )
                                                ); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= e(
                                                ucfirst(
                                                    $request['priority']
                                                )
                                            ); ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                propertyViewDate(
                                                    !empty(
                                                        $request['requested_date']
                                                    )
                                                        ? $request['requested_date']
                                                        : $request['created_at']
                                                )
                                            ); ?>
                                        </td>

                                        <td>
                                            <a
                                                href="request-view.php?id=<?= (int) $request['id']; ?>"
                                                class="pv-card-link"
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
                        <div class="pv-empty">
                            No requests found for this property.
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>

        <aside>
            <section class="pv-card">
                <div class="pv-card-head">
                    <h2>Client</h2>

                    <a
                        href="client-view.php?id=<?= (int) $property['client_id']; ?>"
                        class="pv-card-link"
                    >
                        View Client
                    </a>
                </div>

                <div class="pv-card-body">
                    <div class="pv-detail-grid">
                        <div class="pv-detail full">
                            <div class="pv-detail-label">Client Name</div>
                            <div class="pv-detail-value">
                                <?= e($property['client_name']); ?>
                            </div>
                        </div>

                        <div class="pv-detail full">
                            <div class="pv-detail-label">Company</div>
                            <div class="pv-detail-value">
                                <?= e(
                                    propertyViewText(
                                        $property['client_company']
                                    )
                                ); ?>
                            </div>
                        </div>

                        <div class="pv-detail full">
                            <div class="pv-detail-label">Email</div>
                            <div class="pv-detail-value">
                                <?php if (!empty($property['client_email'])): ?>
                                    <a href="mailto:<?= e(
                                        $property['client_email']
                                    ); ?>">
                                        <?= e($property['client_email']); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="pv-detail full">
                            <div class="pv-detail-label">Phone</div>
                            <div class="pv-detail-value">
                                <?= e(
                                    propertyViewText(
                                        $property['client_phone']
                                    )
                                ); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($canViewVisits): ?>
                <section class="pv-card">
                    <div class="pv-card-head">
                        <h2>Recent Visits</h2>
                    </div>

                    <?php if (!empty($visits)): ?>
                        <div class="pv-list">
                            <?php foreach ($visits as $visit): ?>
                                <div class="pv-list-item">
                                    <span class="pv-list-icon">
                                        <i class="bi bi-calendar-check"></i>
                                    </span>

                                    <div class="pv-list-content">
                                        <div class="pv-list-title">
                                            <?= e(
                                                !empty($visit['title'])
                                                    ? $visit['title']
                                                    : (
                                                        !empty($visit['job_title'])
                                                            ? $visit['job_title']
                                                            : 'Visit'
                                                    )
                                            ); ?>
                                        </div>

                                        <div class="pv-list-meta">
                                            <?= e(
                                                !empty($visit['job_no'])
                                                    ? $visit['job_no'] . ' · '
                                                    : ''
                                            ); ?>
                                            <?= e(
                                                propertyViewDateTime(
                                                    !empty($visit['scheduled_start'])
                                                        ? $visit['scheduled_start']
                                                        : $visit['actual_start']
                                                )
                                            ); ?>
                                            <br>
                                            <?= e(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $visit['status']
                                                )
                                            ); ?>
                                        </div>
                                    </div>

                                    <a
                                        href="visit-view.php?id=<?= (int) $visit['id']; ?>"
                                        class="pv-card-link"
                                    >
                                        View
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="pv-empty">
                            No visits found.
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="pv-card">
                <div class="pv-card-head">
                    <h2>Recent Activity</h2>
                </div>

                <?php if (!empty($activity)): ?>
                    <div class="pv-list">
                        <?php foreach ($activity as $event): ?>
                            <div class="pv-list-item">
                                <span class="pv-list-icon">
                                    <i class="bi bi-activity"></i>
                                </span>

                                <div class="pv-list-content">
                                    <div class="pv-list-title">
                                        <?= e($event['title']); ?>
                                    </div>

                                    <div class="pv-list-meta">
                                        <?= e(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $event['event_type']
                                            )
                                        ); ?>
                                        ·
                                        <?= e(
                                            propertyViewDateTime(
                                                $event['created_at']
                                            )
                                        ); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="pv-empty">
                        No property activity found.
                    </div>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
