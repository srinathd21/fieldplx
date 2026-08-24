<?php
/**
 * FieldPlx - Requests List
 *
 * Upload as:
 * /public_html/requests.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('requests.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'requests.view',
        'You do not have permission to view requests.'
    );
}

$pageTitle = 'Requests - FieldPlx';
$activePage = 'requests';
$searchPlaceholder = 'Search requests...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$errors = array();

$canManage = function_exists('hasPermission')
    ? hasPermission('requests.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('requestsFetchAssoc')) {
    function requestsFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('requestsFetchAll')) {
    function requestsFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('requestsBindParams')) {
    function requestsBindParams(
        mysqli_stmt $stmt,
        $types,
        array &$params
    ) {
        if ($types === '' || empty($params)) {
            return true;
        }

        $arguments = array($types);

        foreach ($params as $key => $value) {
            $arguments[] = &$params[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('requestsDate')) {
    function requestsDate($value)
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

if (!function_exists('requestsStatusClass')) {
    function requestsStatusClass($status)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $status))
        );
    }
}

if (!function_exists('requestsLabel')) {
    function requestsLabel($value)
    {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $value
            )
        );
    }
}

if (!function_exists('requestsBuildQueryString')) {
    function requestsBuildQueryString(
        array $overrides = array()
    ) {
        $query = $_GET;

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return http_build_query($query);
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

$clientFilter = isset($_GET['client_id'])
    ? (int) $_GET['client_id']
    : 0;

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$priorityFilter = isset($_GET['priority'])
    ? trim((string) $_GET['priority'])
    : '';

$sourceFilter = isset($_GET['source'])
    ? trim((string) $_GET['source'])
    : '';

$assignedFilter = isset($_GET['assigned_user_id'])
    ? (int) $_GET['assigned_user_id']
    : 0;

$dateFrom = isset($_GET['date_from'])
    ? trim((string) $_GET['date_from'])
    : '';

$dateTo = isset($_GET['date_to'])
    ? trim((string) $_GET['date_to'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'latest';

$allowedStatuses = array(
    '',
    'new',
    'needs_review',
    'assessment_required',
    'unscheduled',
    'overdue',
    'assessment_completed',
    'quote_required',
    'converted',
    'closed',
    'rejected',
    'archived'
);

$allowedPriorities = array(
    '',
    'low',
    'normal',
    'high',
    'urgent'
);

$allowedSources = array(
    '',
    'manual',
    'public_form',
    'client_portal',
    'online_booking',
    'phone',
    'sms',
    'ai_receptionist',
    'import'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'requested_asc',
    'requested_desc',
    'priority',
    'client_asc'
);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if (!in_array($priorityFilter, $allowedPriorities, true)) {
    $priorityFilter = '';
}

if (!in_array($sourceFilter, $allowedSources, true)) {
    $sourceFilter = '';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

if (
    $dateFrom !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
) {
    $dateFrom = '';
}

if (
    $dateTo !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
) {
    $dateTo = '';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 20;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Filter options
|--------------------------------------------------------------------------
*/

$clientOptions = array();
$userOptions = array();

$stmt = $conn->prepare("
    SELECT
        id,
        display_name
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $clientOptions = requestsFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name
    FROM users
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status = 'active'
    ORDER BY first_name ASC, last_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $userOptions = requestsFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'new_count' => 0,
    'needs_review' => 0,
    'overdue' => 0,
    'converted' => 0
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(r.status = 'new') AS new_count,
        SUM(r.status = 'needs_review') AS needs_review,
        SUM(
            r.status = 'overdue'
            OR (
                r.requested_date IS NOT NULL
                AND r.requested_date < CURDATE()
                AND r.status NOT IN (
                    'converted',
                    'closed',
                    'rejected',
                    'archived'
                )
            )
        ) AS overdue_count,
        SUM(r.status = 'converted') AS converted_count
    FROM requests r
    WHERE r.tenant_id = ?
      AND r.archived_at IS NULL
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = requestsFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['total'] = (int) $row['total'];
        $stats['new_count'] = (int) $row['new_count'];
        $stats['needs_review'] = (int) $row['needs_review'];
        $stats['overdue'] = (int) $row['overdue_count'];
        $stats['converted'] = (int) $row['converted_count'];
    }
}

/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
*/

$where = array(
    'r.tenant_id = ?'
);

$params = array($tenantId);
$types = 'i';

if ($statusFilter !== 'archived') {
    $where[] = 'r.archived_at IS NULL';
}

if ($search !== '') {
    $where[] = "(
        r.request_no LIKE ?
        OR r.title LIKE ?
        OR r.description LIKE ?
        OR c.display_name LIKE ?
        OR c.company_name LIKE ?
        OR c.phone LIKE ?
        OR c.email LIKE ?
        OR p.name LIKE ?
        OR p.address_line1 LIKE ?
        OR q.quote_no LIKE ?
        OR j.job_no LIKE ?
    )";

    $searchLike = '%' . $search . '%';

    for ($i = 0; $i < 11; $i++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($clientFilter > 0) {
    $where[] = 'r.client_id = ?';
    $params[] = $clientFilter;
    $types .= 'i';
}

if ($statusFilter !== '') {
    $where[] = 'r.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($priorityFilter !== '') {
    $where[] = 'r.priority = ?';
    $params[] = $priorityFilter;
    $types .= 's';
}

if ($sourceFilter !== '') {
    $where[] = 'r.source = ?';
    $params[] = $sourceFilter;
    $types .= 's';
}

if ($assignedFilter > 0) {
    $where[] = 'r.assigned_user_id = ?';
    $params[] = $assignedFilter;
    $types .= 'i';
}

if ($dateFrom !== '') {
    $where[] = 'DATE(r.created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = 'DATE(r.created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$orderSql = 'r.created_at DESC, r.id DESC';

if ($sort === 'oldest') {
    $orderSql = 'r.created_at ASC, r.id ASC';
} elseif ($sort === 'requested_asc') {
    $orderSql = 'r.requested_date ASC, r.created_at DESC';
} elseif ($sort === 'requested_desc') {
    $orderSql = 'r.requested_date DESC, r.created_at DESC';
} elseif ($sort === 'priority') {
    $orderSql = "
        FIELD(
            r.priority,
            'urgent',
            'high',
            'normal',
            'low'
        ) ASC,
        r.created_at DESC
    ";
} elseif ($sort === 'client_asc') {
    $orderSql = 'c.display_name ASC, r.created_at DESC';
}

/*
|--------------------------------------------------------------------------
| Count filtered
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM requests r

    LEFT JOIN clients c
        ON c.id = r.client_id
       AND c.tenant_id = r.tenant_id

    LEFT JOIN properties p
        ON p.id = r.property_id
       AND p.tenant_id = r.tenant_id

    LEFT JOIN quotes q
        ON q.id = r.converted_quote_id
       AND q.tenant_id = r.tenant_id

    LEFT JOIN jobs j
        ON j.id = r.converted_job_id
       AND j.tenant_id = r.tenant_id
       AND j.deleted_at IS NULL

    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare request count query: ' .
        $conn->error;
} else {
    requestsBindParams(
        $stmt,
        $types,
        $params
    );

    if (!$stmt->execute()) {
        $errors[] =
            'Unable to count requests: ' .
            $stmt->error;
    } else {
        $row = requestsFetchAssoc($stmt);

        if ($row) {
            $totalFiltered = (int) $row['total'];
        }
    }

    $stmt->close();
}

$totalPages = max(
    1,
    (int) ceil(
        $totalFiltered / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Load requests
|--------------------------------------------------------------------------
*/

$requests = array();

$listSql = "
    SELECT
        r.id,
        r.request_no,
        r.client_id,
        r.property_id,
        r.title,
        r.description,
        r.source,
        r.status,
        r.requested_date,
        r.assigned_user_id,
        r.priority,
        r.converted_quote_id,
        r.converted_job_id,
        r.created_at,
        r.updated_at,
        r.archived_at,

        c.display_name AS client_name,
        c.phone AS client_phone,

        p.name AS property_name,
        p.address_line1 AS property_address,

        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS assigned_user_name,

        q.quote_no AS converted_quote_no,
        j.job_no AS converted_job_no

    FROM requests r

    LEFT JOIN clients c
        ON c.id = r.client_id
       AND c.tenant_id = r.tenant_id

    LEFT JOIN properties p
        ON p.id = r.property_id
       AND p.tenant_id = r.tenant_id

    LEFT JOIN users u
        ON u.id = r.assigned_user_id
       AND u.tenant_id = r.tenant_id
       AND u.deleted_at IS NULL

    LEFT JOIN quotes q
        ON q.id = r.converted_quote_id
       AND q.tenant_id = r.tenant_id

    LEFT JOIN jobs j
        ON j.id = r.converted_job_id
       AND j.tenant_id = r.tenant_id
       AND j.deleted_at IS NULL

    WHERE {$whereSql}

    ORDER BY {$orderSql}

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($listSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare request list query: ' .
        $conn->error;
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    if (
        !requestsBindParams(
            $stmt,
            $listTypes,
            $listParams
        )
    ) {
        $errors[] =
            'Unable to bind request filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to load requests: ' .
            $stmt->error;
    } else {
        $requests = requestsFetchAll($stmt);
    }

    $stmt->close();
}

require_once __DIR__ . '/includes/topbar.php';
?>

<style>

/* Exact new FieldPlx dashboard shell */
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
}
body {
    background: var(--fieldplx-background) !important;
    color: var(--fieldplx-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--fieldplx-border) !important;
    box-shadow: 0 3px 14px rgba(0,17,49,.035);
    backdrop-filter: none !important;
    transition: margin-left .25s ease, width .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: var(--fieldplx-sidebar-collapsed-width);
    width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
}
.fieldplx-topbar-inner { min-height: 70px !important; padding: 0 27px !important; gap: 13px !important; }
.fieldplx-page-heading { display: none !important; }
.fieldplx-menu-toggle,
.fieldplx-topbar-action {
    width: 41px !important;
    height: 41px !important;
    border: 0 !important;
    border-radius: 9px !important;
    color: #001131 !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover { background: #f0f8e5 !important; }
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: #0b1933 !important;
    font-size: 14px !important;
}
.fieldplx-search-input:focus { box-shadow: 0 0 0 3px rgba(116,184,36,.14) !important; }
.fieldplx-profile-button { padding: 2px !important; border: 0 !important; border-radius: 9px !important; background: transparent !important; }
.fieldplx-profile-button:hover { background: #f0f8e5 !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: #001131 !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: #6f7b90 !important; font-size: 12px !important; }
.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,#071f49,#001131) !important;
    border-top: 4px solid #74b824 !important;
    border-right: 0 !important;
    transition: width .25s ease, min-width .25s ease, transform .25s ease !important;
}
body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: var(--fieldplx-sidebar-collapsed-width) !important;
    min-width: var(--fieldplx-sidebar-collapsed-width) !important;
}
.fieldplx-sidebar-header { min-height: 68px !important; padding: 9px 14px 10px !important; border-bottom: 1px solid rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-brand { color: #fff !important; }
.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder { width: 40px !important; height: 40px !important; flex: 0 0 40px !important; border-radius: 10px !important; }
.fieldplx-sidebar-logo-placeholder { color: #fff !important; background: linear-gradient(135deg,#8fd236,#68aa1d) !important; font-size: 18px !important; }
.fieldplx-sidebar-company-name { max-width: 155px !important; color: #fff !important; font-size: 16px !important; font-weight: 700 !important; }
.fieldplx-sidebar-product-name { color: #9fda55 !important; font-size: 11px !important; }
.fieldplx-sidebar-body { padding: 12px 14px !important; scrollbar-width: none !important; }
.fieldplx-sidebar-body::-webkit-scrollbar { display: none; }
.fieldplx-sidebar-section-label { margin: 7px 12px !important; color: rgba(255,255,255,.50) !important; font-size: 11px !important; }
.fieldplx-sidebar-nav { gap: 3px !important; }
.fieldplx-sidebar-link {
    min-height: 46px !important;
    margin-bottom: 3px !important;
    padding: 0 14px !important;
    gap: 15px !important;
    border-radius: 9px !important;
    color: rgba(255,255,255,.94) !important;
    font-size: 15px !important;
}
.fieldplx-sidebar-link:hover { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link { color: #fff !important; background: rgba(116,184,36,.22) !important; }
.fieldplx-sidebar-link-icon { color: #9fda55 !important; font-size: 18px !important; }
.fieldplx-sidebar-arrow { color: rgba(255,255,255,.65) !important; }
.fieldplx-sidebar-submenu { padding-left: 36px !important; }
.fieldplx-sidebar-sublink { min-height: 38px !important; color: rgba(255,255,255,.72) !important; font-size: 13px !important; }
.fieldplx-sidebar-sublink::before { background: rgba(255,255,255,.35) !important; }
.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-sublink.active::before { background: #9fda55 !important; }
.fieldplx-sidebar-footer { padding: 10px 14px 14px !important; border-top: 1px solid rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-user { min-height: 62px; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-user-name { color: #fff !important; font-size: 14px !important; }
.fieldplx-sidebar-user-role { color: rgba(255,255,255,.60) !important; font-size: 11px !important; }
.fieldplx-sidebar-user-avatar { width: 38px !important; height: 38px !important; flex: 0 0 38px !important; border-radius: 50% !important; color: #001131 !important; background: linear-gradient(135deg,#fff,#e8f3d9) !important; }
.fieldplx-sidebar-logout { color: rgba(255,255,255,.70) !important; }
.fieldplx-sidebar-logout:hover { color: #fff !important; background: rgba(228,91,102,.30) !important; }
.fieldplx-main-layout { display: block !important; min-height: calc(100vh - 70px) !important; }
.fieldplx-main-content { margin-left: var(--fieldplx-sidebar-width); min-width: 0; transition: margin-left .25s ease; }
body.fieldplx-sidebar-collapsed .fieldplx-main-content { margin-left: var(--fieldplx-sidebar-collapsed-width); }
.fieldplx-content-wrapper { padding: 0 !important; }
.fieldplx-footer { display: none !important; }

.requests-page {
    --rq-primary: #74b824;
    --rq-primary-dark: #5d971b;
    --rq-primary-soft: #f1f8e8;
    --rq-navy: #071f49;
    --rq-navy-deep: #001131;
    --rq-blue: #123d70;
    --rq-text: #0b1933;
    --rq-muted: #6f7b90;
    --rq-border: #e5eaf1;
    --rq-bg: #f6f8fb;
    max-width: 1600px;
    margin: 0 auto;
    padding: 25px 27px 35px;
    font-family: Arial, Helvetica, sans-serif;
    color: var(--rq-text);
}

.rq-header {
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
}

.rq-header h1 {
    margin: 0;
    color: var(--rq-text);
    font-size: 28px;
    line-height: 1.15;
    font-weight: 700;
    letter-spacing: -.35px;
}

.rq-header p {
    margin: 7px 0 0;
    color: var(--rq-muted);
    font-size: 14px;
    line-height: 1.55;
}

.rq-add-btn {
    min-height: 46px;
    padding: 0 17px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border: 1px solid var(--rq-primary);
    border-radius: 9px;
    background: var(--rq-primary);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    box-shadow: 0 7px 16px rgba(116,184,36,.18);
    transition: .18s ease;
}

.rq-add-btn:hover {
    border-color: var(--rq-primary-dark);
    background: var(--rq-primary-dark);
    color: #fff;
    transform: translateY(-1px);
}

.rq-alert {
    margin-bottom: 16px;
    padding: 13px 15px;
    border-radius: 9px;
    font-size: 13px;
    line-height: 1.55;
}

.rq-alert.error {
    border: 1px solid #fecaca;
    background: #fff7f7;
    color: #b91c1c;
}

.rq-alert.success {
    border: 1px solid #cce8ab;
    background: #f5faef;
    color: #477b12;
}

.rq-stats {
    margin-bottom: 18px;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 14px;
}

.rq-stat {
    position: relative;
    min-height: 170px;
    overflow: hidden;
    padding: 24px 20px 16px;
    border: 1px solid var(--rq-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 7px 22px rgba(15,35,65,.055);
}

.rq-stat::after {
    content: '';
    position: absolute;
    left: -10%;
    right: -10%;
    bottom: -42px;
    height: 88px;
    border-radius: 50%;
    border-top: 2px solid rgba(116,184,36,.22);
    transform: rotate(-3deg);
    pointer-events: none;
}

.rq-stat:nth-child(2)::after,
.rq-stat:nth-child(4)::after {
    border-top-color: rgba(18,61,112,.15);
    transform: rotate(4deg);
}

.rq-stat-top {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.rq-stat-icon {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 15px;
    background: var(--rq-primary-soft);
    color: var(--rq-primary-dark);
    font-size: 25px;
}

.rq-stat:nth-child(2) .rq-stat-icon,
.rq-stat:nth-child(4) .rq-stat-icon {
    background: #eef4fb;
    color: var(--rq-blue);
}

.rq-stat:nth-child(3) .rq-stat-icon {
    background: #fff7e8;
    color: #b7791f;
}

.rq-stat:nth-child(4) .rq-stat-icon {
    background: #fff1f1;
    color: #bf3b3b;
}

.rq-stat-label {
    margin-top: 22px;
    color: var(--rq-muted);
    font-size: 13px;
    font-weight: 500;
}

.rq-stat-value {
    margin-top: 5px;
    color: var(--rq-text);
    font-size: 34px;
    line-height: 1;
    font-weight: 700;
    letter-spacing: -.7px;
}

.rq-stat-note {
    position: relative;
    z-index: 1;
    margin-top: 8px;
    color: #8994a6;
    font-size: 11px;
    line-height: 1.35;
}

.rq-panel {
    overflow: hidden;
    border: 1px solid var(--rq-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 7px 22px rgba(15,35,65,.055);
}

.rq-panel-head {
    padding: 18px 18px 15px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    border-bottom: 1px solid var(--rq-border);
}

.rq-panel-head h2 {
    margin: 0;
    color: var(--rq-text);
    font-size: 18px;
    font-weight: 700;
}

.rq-panel-head p {
    margin: 5px 0 0;
    color: var(--rq-muted);
    font-size: 12px;
    line-height: 1.45;
}

.rq-panel-count {
    padding: 7px 10px;
    border-radius: 7px;
    background: var(--rq-primary-soft);
    color: var(--rq-primary-dark);
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.rq-filter-bar {
    padding: 16px 18px;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    border-bottom: 1px solid var(--rq-border);
    background: #fbfcfe;
}

.rq-filter-bar > :first-child {
    grid-column: span 2;
}

.rq-filter-bar > div:last-child {
    display: flex !important;
    gap: 8px !important;
}

.rq-input,
.rq-select {
    width: 100%;
    min-height: 46px;
    padding: 0 13px;
    border: 1px solid #dce3ec;
    border-radius: 8px;
    background: #fff;
    color: var(--rq-text);
    font-family: inherit;
    font-size: 13px;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease;
}

.rq-input:focus,
.rq-select:focus {
    border-color: var(--rq-primary);
    box-shadow: 0 0 0 3px rgba(116,184,36,.12);
}

.rq-filter-btn,
.rq-reset-btn {
    min-height: 46px;
    padding: 0 15px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}

.rq-filter-btn {
    border: 1px solid var(--rq-primary);
    background: var(--rq-primary);
    color: #fff;
    cursor: pointer;
}

.rq-filter-btn:hover {
    border-color: var(--rq-primary-dark);
    background: var(--rq-primary-dark);
}

.rq-reset-btn {
    border: 1px solid var(--rq-border);
    background: #fff;
    color: #59677b;
}

.rq-reset-btn:hover {
    border-color: #cad3df;
    background: #f8fafc;
    color: var(--rq-text);
}

.rq-table-wrap {
    overflow-x: auto;
}

.rq-table {
    width: 100%;
    min-width: 1340px;
    border-collapse: collapse;
}

.rq-table th,
.rq-table td {
    padding: 16px 9px;
    border-bottom: 1px solid #edf1f5;
    text-align: left;
    white-space: nowrap;
    vertical-align: middle;
}

.rq-table th {
    padding-top: 14px;
    padding-bottom: 14px;
    background: #fafbfd;
    color: #758196;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .25px;
    text-transform: uppercase;
}

.rq-table td {
    color: #435168;
    font-size: 13px;
}

.rq-table tbody tr {
    transition: background .15s ease;
}

.rq-table tbody tr:hover {
    background: #fbfdf8;
}

.rq-main {
    color: var(--rq-text);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.rq-main:hover {
    color: var(--rq-primary-dark);
}

.rq-sub {
    margin-top: 4px;
    display: block;
    max-width: 260px;
    overflow: hidden;
    color: #8a95a7;
    font-size: 11px;
    line-height: 1.35;
    text-overflow: ellipsis;
}

.rq-badge {
    min-height: 25px;
    padding: 5px 8px;
    display: inline-flex;
    align-items: center;
    border-radius: 5px;
    background: #f2f4f7;
    color: #59667a;
    font-size: 11px;
    line-height: 1;
    font-weight: 700;
    text-transform: capitalize;
}

.rq-badge.new,
.rq-badge.assessment_completed,
.rq-badge.converted,
.rq-badge.closed {
    background: #eff8e6;
    color: #4f8616;
}

.rq-badge.needs_review,
.rq-badge.assessment_required,
.rq-badge.quote_required,
.rq-badge.unscheduled {
    background: #edf4fb;
    color: var(--rq-blue);
}

.rq-badge.overdue,
.rq-badge.rejected,
.rq-badge.urgent {
    background: #fff0f0;
    color: #bd3535;
}

.rq-badge.high {
    background: #fff5e8;
    color: #a95f12;
}

.rq-badge.normal {
    background: #f2f4f7;
    color: #59667a;
}

.rq-badge.low {
    background: #f2f8ee;
    color: #5b8c2b;
}

.rq-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
}

.rq-action {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--rq-border);
    border-radius: 6px;
    background: #fff;
    color: #5b687a;
    font-size: 14px;
    text-decoration: none;
    transition: .16s ease;
}

.rq-action:hover {
    border-color: #c8d3df;
    background: #f7fafc;
    color: var(--rq-primary-dark);
    transform: translateY(-1px);
}

.rq-footer {
    padding: 14px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-top: 1px solid var(--rq-border);
    background: #fbfcfe;
}

.rq-result-count {
    color: var(--rq-muted);
    font-size: 11px;
}

.rq-pagination {
    display: flex;
    align-items: center;
    gap: 6px;
}

.rq-page-link {
    min-width: 34px;
    height: 34px;
    padding: 0 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--rq-border);
    border-radius: 6px;
    background: #fff;
    color: #5a687b;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
}

.rq-page-link:hover {
    border-color: #cad4df;
    color: var(--rq-primary-dark);
}

.rq-page-link.active {
    border-color: var(--rq-primary);
    background: var(--rq-primary);
    color: #fff;
}

.rq-empty {
    padding: 55px 18px;
    color: #8894a7;
    font-size: 13px;
    text-align: center;
}

@media (max-width: 1320px) {
    .rq-stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .rq-filter-bar {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .rq-filter-bar > :first-child {
        grid-column: span 2;
    }
}

@media (max-width: 900px) {
    .requests-page {
        padding: 20px 18px 30px;
    }

    .rq-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .rq-filter-bar {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .rq-filter-bar > :first-child {
        grid-column: 1 / -1;
    }
}

@media (max-width: 680px) {
    .requests-page {
        padding: 16px 13px 26px;
    }

    .rq-header {
        align-items: stretch;
        flex-direction: column;
    }

    .rq-header h1 {
        font-size: 24px;
    }

    .rq-add-btn {
        width: 100%;
    }

    .rq-stats,
    .rq-filter-bar {
        grid-template-columns: 1fr;
    }

    .rq-stat {
        min-height: 154px;
    }

    .rq-filter-bar > :first-child {
        grid-column: auto;
    }

    .rq-filter-bar > div:last-child {
        width: 100%;
    }

    .rq-filter-btn,
    .rq-reset-btn {
        flex: 1 1 0;
    }

    .rq-panel-head,
    .rq-footer {
        align-items: flex-start;
        flex-direction: column;
    }
}


/* Requests - final approved new-design refinements */
.rq-header {
    min-height: 108px;
    margin-bottom: 18px;
    padding: 20px 22px;
    align-items: center;
    border: 1px solid var(--rq-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.rq-header-main { min-width: 0; display: flex; align-items: center; gap: 16px; }
.rq-header-icon {
    width: 58px;
    height: 58px;
    flex: 0 0 58px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg,var(--rq-blue),var(--rq-navy-deep));
    box-shadow: 0 8px 22px rgba(0,17,49,.16);
    font-size: 23px;
}
.rq-header-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 9px; }
.rq-header h1 { font-size: 28px; }
.rq-stat { padding-bottom: 18px; }
.rq-stat-value { position: relative; z-index: 1; }
.rq-panel-head { min-height: 72px; }
.rq-filter-bar { grid-template-columns: minmax(260px,2fr) repeat(4,minmax(145px,1fr)); }
.rq-filter-bar > :first-child { grid-column: span 2; }
.rq-filter-bar > div:last-child { min-width: 155px; }
.rq-table-wrap { scrollbar-width: thin; scrollbar-color: #cbd5e1 #f8fafc; }
.rq-table-wrap::-webkit-scrollbar { height: 9px; }
.rq-table-wrap::-webkit-scrollbar-track { background: #f8fafc; }
.rq-table-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }
.rq-table th:first-child,
.rq-table td:first-child { padding-left: 18px; }
.rq-table th:last-child,
.rq-table td:last-child { padding-right: 18px; }
.rq-action[title="Create Quote"] { color: var(--rq-blue); }
.rq-action[title="View Job"] { color: var(--rq-primary-dark); }
.rq-action[title="View Quote"] { color: #8a6419; }
@media (max-width: 991px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .fieldplx-search-wrap { width: min(260px,42vw) !important; }
    .requests-page { padding: 20px 18px 30px; }
    .rq-header { min-height: 0; }
    .rq-filter-bar { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .rq-filter-bar > :first-child { grid-column: 1 / -1; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 13px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .rq-header { padding: 18px; align-items: stretch; }
    .rq-header-main { align-items: flex-start; }
    .rq-header-icon { width: 50px; height: 50px; flex-basis: 50px; border-radius: 13px; font-size: 20px; }
    .rq-header h1 { font-size: 24px; }
    .rq-header-actions { width: 100%; }
    .rq-add-btn { width: 100%; }
    .rq-panel-head { min-height: 0; }
    .rq-filter-bar { grid-template-columns: 1fr; }
    .rq-filter-bar > :first-child { grid-column: auto; }
}

</style>

<div class="requests-page">
    <div class="rq-header">
        <div class="rq-header-main">
            <div class="rq-header-icon" aria-hidden="true">
                <i class="bi bi-inbox"></i>
            </div>
            <div>
                <h1>Requests</h1>
                <p>
                    Manage incoming service requests, assessments, quotes, and conversions.
                </p>
            </div>
        </div>

        <div class="rq-header-actions">
            <?php if ($canManage): ?>
                <a href="request-add.php" class="rq-add-btn">
                    <i class="bi bi-plus-lg"></i>
                    Add Request
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="rq-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="rq-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="rq-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="rq-stats">
        <article class="rq-stat">
            <div class="rq-stat-top">
                <span class="rq-stat-icon"><i class="bi bi-inbox"></i></span>
            </div>
            <div class="rq-stat-label">Total Requests</div>
            <div class="rq-stat-value"><?= e($stats['total']); ?></div>
            <div class="rq-stat-note">All current service requests</div>
        </article>

        <article class="rq-stat">
            <div class="rq-stat-top">
                <span class="rq-stat-icon"><i class="bi bi-stars"></i></span>
            </div>
            <div class="rq-stat-label">New</div>
            <div class="rq-stat-value"><?= e($stats['new_count']); ?></div>
            <div class="rq-stat-note">Newly received requests</div>
        </article>

        <article class="rq-stat">
            <div class="rq-stat-top">
                <span class="rq-stat-icon"><i class="bi bi-search"></i></span>
            </div>
            <div class="rq-stat-label">Needs Review</div>
            <div class="rq-stat-value"><?= e($stats['needs_review']); ?></div>
            <div class="rq-stat-note">Waiting for review or assessment</div>
        </article>

        <article class="rq-stat">
            <div class="rq-stat-top">
                <span class="rq-stat-icon"><i class="bi bi-exclamation-triangle"></i></span>
            </div>
            <div class="rq-stat-label">Overdue</div>
            <div class="rq-stat-value"><?= e($stats['overdue']); ?></div>
            <div class="rq-stat-note">Past requested date and still open</div>
        </article>

        <article class="rq-stat">
            <div class="rq-stat-top">
                <span class="rq-stat-icon"><i class="bi bi-arrow-left-right"></i></span>
            </div>
            <div class="rq-stat-label">Converted</div>
            <div class="rq-stat-value"><?= e($stats['converted']); ?></div>
            <div class="rq-stat-note">Converted into quote or job flow</div>
        </article>
    </section>

    <section class="rq-panel">
        <div class="rq-panel-head">
            <div>
                <h2>Request Directory</h2>
                <p>Search, filter, review, and continue request workflows.</p>
            </div>
            <span class="rq-panel-count"><?= e($totalFiltered); ?> results</span>
        </div>

        <form method="get" action="" class="rq-filter-bar">
            <input
                type="search"
                name="search"
                class="rq-input"
                value="<?= e($search); ?>"
                placeholder="Search request, client, property, quote or job"
            >

            <select name="client_id" class="rq-select">
                <option value="">All Clients</option>
                <?php foreach ($clientOptions as $client): ?>
                    <option
                        value="<?= (int) $client['id']; ?>"
                        <?= $clientFilter === (int) $client['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($client['display_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="rq-select">
                <option value="">All Statuses</option>
                <?php
                $statusOptions = array(
                    'new' => 'New',
                    'needs_review' => 'Needs Review',
                    'assessment_required' => 'Assessment Required',
                    'unscheduled' => 'Unscheduled',
                    'overdue' => 'Overdue',
                    'assessment_completed' => 'Assessment Completed',
                    'quote_required' => 'Quote Required',
                    'converted' => 'Converted',
                    'closed' => 'Closed',
                    'rejected' => 'Rejected',
                    'archived' => 'Archived'
                );

                foreach ($statusOptions as $value => $label):
                ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $statusFilter === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="priority" class="rq-select">
                <option value="">All Priorities</option>
                <?php foreach (
                    array(
                        'urgent' => 'Urgent',
                        'high' => 'High',
                        'normal' => 'Normal',
                        'low' => 'Low'
                    ) as $value => $label
                ): ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $priorityFilter === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="source" class="rq-select">
                <option value="">All Sources</option>
                <?php foreach (
                    array(
                        'manual' => 'Manual',
                        'public_form' => 'Public Form',
                        'client_portal' => 'Client Portal',
                        'online_booking' => 'Online Booking',
                        'phone' => 'Phone',
                        'sms' => 'SMS',
                        'ai_receptionist' => 'AI Receptionist',
                        'import' => 'Import'
                    ) as $value => $label
                ): ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $sourceFilter === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="assigned_user_id"
                class="rq-select"
            >
                <option value="">All Assigned Users</option>

                <?php foreach ($userOptions as $user): ?>
                    <?php
                    $userName = trim(
                        (string) $user['first_name'] .
                        ' ' .
                        (string) $user['last_name']
                    );
                    ?>
                    <option
                        value="<?= (int) $user['id']; ?>"
                        <?= $assignedFilter === (int) $user['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($userName); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input
                type="date"
                name="date_from"
                class="rq-input"
                value="<?= e($dateFrom); ?>"
                title="Created from"
            >

            <input
                type="date"
                name="date_to"
                class="rq-input"
                value="<?= e($dateTo); ?>"
                title="Created to"
            >

            <select name="sort" class="rq-select">
                <option value="latest" <?= $sort === 'latest' ? 'selected' : ''; ?>>
                    Latest First
                </option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : ''; ?>>
                    Oldest First
                </option>
                <option value="requested_asc" <?= $sort === 'requested_asc' ? 'selected' : ''; ?>>
                    Requested Date Asc
                </option>
                <option value="requested_desc" <?= $sort === 'requested_desc' ? 'selected' : ''; ?>>
                    Requested Date Desc
                </option>
                <option value="priority" <?= $sort === 'priority' ? 'selected' : ''; ?>>
                    Highest Priority
                </option>
                <option value="client_asc" <?= $sort === 'client_asc' ? 'selected' : ''; ?>>
                    Client A-Z
                </option>
            </select>

            <div style="display:flex;gap:6px;">
                <button type="submit" class="rq-filter-btn">
                    Apply
                </button>

                <a href="requests.php" class="rq-reset-btn">
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($requests)): ?>
            <div class="rq-table-wrap">
                <table class="rq-table">
                    <thead>
                        <tr>
                            <th>Request</th>
                            <th>Client</th>
                            <th>Property</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Source</th>
                            <th>Requested Date</th>
                            <th>Assigned To</th>
                            <th>Converted</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $propertyTitle =
                            trim((string) $request['property_name']) !== ''
                                ? (string) $request['property_name']
                                : (
                                    trim((string) $request['property_address']) !== ''
                                        ? (string) $request['property_address']
                                        : '—'
                                );

                        $assignedName =
                            trim((string) $request['assigned_user_name']) !== ''
                                ? (string) $request['assigned_user_name']
                                : 'Unassigned';

                        $convertedLinks = array();

                        if (!empty($request['converted_quote_id'])) {
                            $convertedLinks[] =
                                '<a class="rq-main" href="quote-view.php?id=' .
                                (int) $request['converted_quote_id'] .
                                '">' .
                                e($request['converted_quote_no']) .
                                '</a>';
                        }

                        if (!empty($request['converted_job_id'])) {
                            $convertedLinks[] =
                                '<a class="rq-main" href="job-view.php?id=' .
                                (int) $request['converted_job_id'] .
                                '">' .
                                e($request['converted_job_no']) .
                                '</a>';
                        }
                        ?>
                        <tr>
                            <td>
                                <a
                                    href="request-view.php?id=<?= (int) $request['id']; ?>"
                                    class="rq-main"
                                >
                                    <?= e($request['request_no']); ?>
                                </a>

                                <span class="rq-sub" title="<?= e($request['title']); ?>">
                                    <?= e($request['title']); ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!empty($request['client_id'])): ?>
                                    <a
                                        href="client-view.php?id=<?= (int) $request['client_id']; ?>"
                                        class="rq-main"
                                    >
                                        <?= e(
                                            trim((string) $request['client_name']) !== ''
                                                ? $request['client_name']
                                                : 'Unnamed Client'
                                        ); ?>
                                    </a>

                                    <?php if (
                                        trim((string) $request['client_phone']) !== ''
                                    ): ?>
                                        <span class="rq-sub">
                                            <?= e($request['client_phone']); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($request['property_id'])): ?>
                                    <a
                                        href="property-view.php?id=<?= (int) $request['property_id']; ?>"
                                        class="rq-main"
                                    >
                                        <?= e($propertyTitle); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="rq-badge <?= e(
                                    requestsStatusClass(
                                        $request['status']
                                    )
                                ); ?>">
                                    <?= e(
                                        requestsLabel(
                                            $request['status']
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="rq-badge <?= e(
                                    requestsStatusClass(
                                        $request['priority']
                                    )
                                ); ?>">
                                    <?= e(
                                        requestsLabel(
                                            $request['priority']
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <?= e(
                                    requestsLabel(
                                        $request['source']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <?= e(
                                    requestsDate(
                                        $request['requested_date']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <?= e($assignedName); ?>
                            </td>

                            <td>
                                <?= !empty($convertedLinks)
                                    ? implode(' · ', $convertedLinks)
                                    : '—'; ?>
                            </td>

                            <td>
                                <?= e(
                                    requestsDate(
                                        $request['created_at']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <div class="rq-actions">
                                    <a
                                        href="request-view.php?id=<?= (int) $request['id']; ?>"
                                        class="rq-action"
                                        title="View"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($canManage): ?>
                                        <a
                                            href="request-edit.php?id=<?= (int) $request['id']; ?>"
                                            class="rq-action"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <?php if (
                                            empty($request['converted_quote_id']) &&
                                            !in_array(
                                                $request['status'],
                                                array(
                                                    'converted',
                                                    'closed',
                                                    'rejected',
                                                    'archived'
                                                ),
                                                true
                                            )
                                        ): ?>
                                            <a
                                                href="quote-add.php?request_id=<?= (int) $request['id']; ?>&client_id=<?= (int) $request['client_id']; ?>&property_id=<?= (int) $request['property_id']; ?>"
                                                class="rq-action"
                                                title="Create Quote"
                                            >
                                                <i class="bi bi-file-earmark-text"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (!empty($request['converted_quote_id'])): ?>
                                        <a
                                            href="quote-view.php?id=<?= (int) $request['converted_quote_id']; ?>"
                                            class="rq-action"
                                            title="View Quote"
                                        >
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($request['converted_job_id'])): ?>
                                        <a
                                            href="job-view.php?id=<?= (int) $request['converted_job_id']; ?>"
                                            class="rq-action"
                                            title="View Job"
                                        >
                                            <i class="bi bi-briefcase"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="rq-footer">
                <div class="rq-result-count">
                    Showing
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + 1
                        )
                    ); ?>
                    -
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + count($requests)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    requests
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="rq-pagination">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    requestsBuildQueryString(
                                        array(
                                            'page' => $page - 1
                                        )
                                    )
                                ); ?>"
                                class="rq-page-link"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);

                        for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(
                                    requestsBuildQueryString(
                                        array(
                                            'page' => $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="rq-page-link <?= $pageNumber === $page ? 'active' : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    requestsBuildQueryString(
                                        array(
                                            'page' => $page + 1
                                        )
                                    )
                                ); ?>"
                                class="rq-page-link"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="rq-empty">
                <?php if (
                    $search !== '' ||
                    $clientFilter > 0 ||
                    $statusFilter !== '' ||
                    $priorityFilter !== '' ||
                    $sourceFilter !== '' ||
                    $assignedFilter > 0 ||
                    $dateFrom !== '' ||
                    $dateTo !== ''
                ): ?>
                    No requests found for the selected filters.
                <?php else: ?>
                    No requests are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
