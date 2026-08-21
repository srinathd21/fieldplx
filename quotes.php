<?php
/**
 * FieldPlx - Quotes List
 *
 * Upload as:
 * /public_html/quotes.php
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
        rawurlencode('quotes.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'quotes.view',
        'You do not have permission to view quotes.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Quotes - FieldPlx';
$activePage = 'quotes';
$searchPlaceholder = 'Search quotes...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$errors = array();

$canManage = function_exists('hasPermission')
    ? hasPermission('quotes.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('quotesFetchAssoc')) {
    function quotesFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('quotesFetchAll')) {
    function quotesFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('quotesBindParams')) {
    function quotesBindParams(
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

if (!function_exists('quotesMoney')) {
    function quotesMoney($amount)
    {
        return number_format(
            (float) $amount,
            2,
            '.',
            ','
        );
    }
}

if (!function_exists('quotesDate')) {
    function quotesDate($value)
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

if (!function_exists('quotesStatusClass')) {
    function quotesStatusClass($status)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $status))
        );
    }
}

if (!function_exists('quotesStatusLabel')) {
    function quotesStatusLabel($status)
    {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $status
            )
        );
    }
}

if (!function_exists('quotesBuildQueryString')) {
    function quotesBuildQueryString(
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
    'draft',
    'sent',
    'viewed',
    'awaiting_response',
    'changes_requested',
    'approved',
    'deposit_paid',
    'converted',
    'rejected',
    'expired',
    'archived'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'quote_asc',
    'quote_desc',
    'client_asc',
    'total_high',
    'total_low'
);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
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
| Client options
|--------------------------------------------------------------------------
*/

$clientOptions = array();

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
    $clientOptions = quotesFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/

$stats = array(
    'total_count' => 0,
    'draft_count' => 0,
    'approved_count' => 0,
    'converted_count' => 0,
    'total_value' => 0.00
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(q.status = 'draft') AS draft_count,
        SUM(
            q.status IN (
                'approved',
                'deposit_paid'
            )
        ) AS approved_count,
        SUM(q.status = 'converted') AS converted_count,
        COALESCE(SUM(q.total), 0) AS total_value
    FROM quotes q
    WHERE q.tenant_id = ?
      AND q.archived_at IS NULL
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = quotesFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['total_count'] =
            (int) $row['total_count'];

        $stats['draft_count'] =
            (int) $row['draft_count'];

        $stats['approved_count'] =
            (int) $row['approved_count'];

        $stats['converted_count'] =
            (int) $row['converted_count'];

        $stats['total_value'] =
            (float) $row['total_value'];
    }
}

/*
|--------------------------------------------------------------------------
| Build filtered query
|--------------------------------------------------------------------------
*/

$where = array(
    'q.tenant_id = ?'
);

$params = array($tenantId);
$types = 'i';

if ($statusFilter !== 'archived') {
    $where[] = 'q.archived_at IS NULL';
}

if ($search !== '') {
    $where[] = "(
        q.quote_no LIKE ?
        OR q.title LIKE ?
        OR q.introduction LIKE ?
        OR c.display_name LIKE ?
        OR c.company_name LIKE ?
        OR c.phone LIKE ?
        OR c.email LIKE ?
        OR p.name LIKE ?
        OR p.address_line1 LIKE ?
        OR r.request_no LIKE ?
        OR r.title LIKE ?
    )";

    $searchLike = '%' . $search . '%';

    for ($i = 0; $i < 11; $i++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($clientFilter > 0) {
    $where[] = 'q.client_id = ?';
    $params[] = $clientFilter;
    $types .= 'i';
}

if ($statusFilter !== '') {
    $where[] = 'q.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($dateFrom !== '') {
    $where[] = 'DATE(q.created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = 'DATE(q.created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$orderSql = 'q.created_at DESC, q.id DESC';

if ($sort === 'oldest') {
    $orderSql = 'q.created_at ASC, q.id ASC';
} elseif ($sort === 'quote_asc') {
    $orderSql = 'q.quote_no ASC';
} elseif ($sort === 'quote_desc') {
    $orderSql = 'q.quote_no DESC';
} elseif ($sort === 'client_asc') {
    $orderSql = 'c.display_name ASC, q.created_at DESC';
} elseif ($sort === 'total_high') {
    $orderSql = 'q.total DESC, q.created_at DESC';
} elseif ($sort === 'total_low') {
    $orderSql = 'q.total ASC, q.created_at DESC';
}

/*
|--------------------------------------------------------------------------
| Count filtered rows
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM quotes q

    INNER JOIN clients c
        ON c.id = q.client_id
       AND c.tenant_id = q.tenant_id

    LEFT JOIN properties p
        ON p.id = q.property_id
       AND p.tenant_id = q.tenant_id

    LEFT JOIN requests r
        ON r.id = q.request_id
       AND r.tenant_id = q.tenant_id

    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare quote count query: ' .
        $conn->error;
} else {
    quotesBindParams(
        $stmt,
        $types,
        $params
    );

    if (!$stmt->execute()) {
        $errors[] =
            'Unable to count quotes: ' .
            $stmt->error;
    } else {
        $row = quotesFetchAssoc($stmt);

        if ($row) {
            $totalFiltered =
                (int) $row['total'];
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
| Load quotes
|--------------------------------------------------------------------------
*/

$quotes = array();

$listSql = "
    SELECT
        q.id,
        q.quote_no,
        q.client_id,
        q.property_id,
        q.request_id,
        q.title,
        q.status,
        q.subtotal,
        q.discount_total,
        q.tax_total,
        q.total,
        q.deposit_required,
        q.deposit_amount,
        q.valid_until,
        q.sent_at,
        q.viewed_at,
        q.approved_at,
        q.converted_job_id,
        q.created_at,
        q.updated_at,
        q.archived_at,

        c.display_name AS client_name,
        c.phone AS client_phone,

        p.name AS property_name,
        p.address_line1 AS property_address,

        r.request_no,
        r.title AS request_title,

        j.job_no AS converted_job_no,

        (
            SELECT COUNT(*)
            FROM quote_line_items qli
            WHERE qli.tenant_id = q.tenant_id
              AND qli.quote_id = q.id
        ) AS item_count

    FROM quotes q

    INNER JOIN clients c
        ON c.id = q.client_id
       AND c.tenant_id = q.tenant_id

    LEFT JOIN properties p
        ON p.id = q.property_id
       AND p.tenant_id = q.tenant_id

    LEFT JOIN requests r
        ON r.id = q.request_id
       AND r.tenant_id = q.tenant_id

    LEFT JOIN jobs j
        ON j.id = q.converted_job_id
       AND j.tenant_id = q.tenant_id
       AND j.deleted_at IS NULL

    WHERE {$whereSql}

    ORDER BY {$orderSql}

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($listSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare quote list query: ' .
        $conn->error;
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    if (
        !quotesBindParams(
            $stmt,
            $listTypes,
            $listParams
        )
    ) {
        $errors[] =
            'Unable to bind quote filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to load quotes: ' .
            $stmt->error;
    } else {
        $quotes =
            quotesFetchAll($stmt);
    }

    $stmt->close();
}

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
    --ql-navy: #001131;
    --ql-navy-light: #071f49;
    --ql-blue: #123d70;
    --ql-primary: #74b824;
    --ql-primary-dark: #5d971b;
    --ql-primary-soft: #f0f8e5;
    --ql-red: #e45b66;
    --ql-bg: #f6f8fb;
    --ql-text: #0b1933;
    --ql-muted: #6f7b90;
    --ql-border: #e5eaf1;
}

body {
    background: var(--ql-bg) !important;
    color: var(--ql-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

/* Shared FieldPlx shell - same visual system as the new dashboard */
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--ql-border) !important;
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
    color: var(--ql-navy) !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--ql-navy) !important;
    background: var(--ql-primary-soft) !important;
}
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--ql-text) !important;
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
.fieldplx-profile-button:hover { background: var(--ql-primary-soft) !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--ql-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: var(--ql-muted) !important; font-size: 12px !important; }
.fieldplx-notification-count { background: var(--ql-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--ql-border) !important;
    box-shadow: 0 18px 45px rgba(29,38,74,.14) !important;
}
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--ql-primary-dark) !important; }

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,var(--ql-navy-light),var(--ql-navy)) !important;
    border-top: 4px solid var(--ql-primary) !important;
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
    color: var(--ql-navy) !important;
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

/* Quotes page - exact component language used by the new dashboard */
.quotes-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.ql-header {
    margin-bottom: 23px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}
.ql-eyebrow { display: none; }
.ql-header h1 {
    margin: 0 0 8px;
    color: var(--ql-text);
    font-size: 28px;
    font-weight: 700;
}
.ql-header p {
    margin: 0;
    color: var(--ql-muted);
    font-size: 14px;
}
.ql-add-btn {
    height: 46px;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--ql-primary);
    border-radius: 9px;
    background: var(--ql-primary);
    color: #fff;
    box-shadow: 0 5px 15px rgba(31,43,88,.05);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}
.ql-add-btn:hover {
    border-color: var(--ql-primary-dark);
    color: #fff;
    background: var(--ql-primary-dark);
}

.ql-alert {
    margin-bottom: 16px;
    padding: 12px 14px;
    border-radius: 9px;
    font-size: 13px;
    line-height: 1.6;
}
.ql-alert.error { border: 1px solid #f4c8cc; background: #fff5f6; color: #b5434d; }
.ql-alert.success { border: 1px solid #d3e9b8; background: #f7fbf1; color: var(--ql-primary-dark); }

/* Same stat cards as new dashboard */
.ql-stats {
    margin-bottom: 18px;
    display: grid;
    grid-template-columns: repeat(5,minmax(0,1fr));
    gap: 14px;
}
.ql-stat {
    position: relative;
    min-height: 170px;
    padding: 25px 20px 8px;
    overflow: hidden;
    border: 1px solid var(--ql-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.ql-stat-more {
    position: absolute;
    top: 13px;
    right: 11px;
    color: #8995a8;
    font-size: 18px;
}
.ql-stat-row {
    display: flex;
    align-items: flex-start;
    gap: 18px;
}
.ql-stat-icon {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    font-size: 27px;
}
.ql-stat-icon.navy { background: var(--ql-blue); }
.ql-stat-icon.green { background: var(--ql-primary); }
.ql-stat-icon.dark-green { background: var(--ql-primary-dark); }
.ql-stat-icon.soft-green { background: #96c945; }
.ql-stat-copy { min-width: 0; }
.ql-stat-label {
    display: block;
    margin-bottom: 10px;
    color: #66748b;
    font-size: 13px;
    font-weight: 500;
}
.ql-stat-value {
    display: block;
    color: var(--ql-text);
    font-size: 34px;
    line-height: 1;
    font-weight: 700;
}
.ql-stat-note {
    display: block;
    margin-top: 14px;
    color: #8a95a8;
    font-size: 11px;
    line-height: 1.5;
}
.ql-stat-note strong {
    color: var(--ql-primary-dark);
    font-size: 11px;
}
.ql-stat-wave {
    position: absolute;
    right: 18px;
    bottom: 7px;
    left: 18px;
    height: 38px;
    opacity: .72;
    pointer-events: none;
}
.ql-stat-wave svg { width: 100%; height: 100%; display: block; }
.ql-stat-wave path { fill: none; stroke: #d5e9ba; stroke-width: 2; vector-effect: non-scaling-stroke; }
.ql-stat-wave path.accent { stroke: var(--ql-primary); }

/* Same white panel language as dashboard */
.ql-panel {
    overflow: hidden;
    border: 1px solid var(--ql-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.ql-panel-head {
    padding: 18px 18px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.ql-panel-title {
    margin: 0;
    color: var(--ql-text);
    font-size: 18px;
    font-weight: 700;
}
.ql-panel-count {
    padding: 4px 8px;
    border-radius: 999px;
    color: var(--ql-primary-dark);
    background: var(--ql-primary-soft);
    font-size: 11px;
    font-weight: 700;
}

.ql-filter-bar {
    padding: 0 18px 18px;
    display: grid;
    grid-template-columns: minmax(235px,1.35fr) minmax(155px,.72fr) minmax(150px,.68fr) minmax(135px,.58fr) minmax(135px,.58fr) minmax(150px,.65fr) auto;
    gap: 9px;
    border-bottom: 1px solid var(--ql-border);
}
.ql-input-wrap { position: relative; }
.ql-input-wrap > i {
    position: absolute;
    top: 50%;
    left: 13px;
    z-index: 1;
    transform: translateY(-50%);
    color: #8b97a9;
    font-size: 15px;
    pointer-events: none;
}
.ql-input,
.ql-select {
    width: 100%;
    height: 46px;
    padding: 0 14px;
    border: 1px solid var(--ql-border);
    border-radius: 8px;
    background: #fff;
    color: var(--ql-text);
    font-family: inherit;
    font-size: 13px;
    outline: none;
}
.ql-input { padding-left: 37px; }
.ql-input:focus,
.ql-select:focus {
    border-color: #cfe3ae;
    box-shadow: 0 0 0 3px rgba(116,184,36,.12);
}
.ql-filter-actions { display: flex; gap: 7px; }
.ql-filter-btn,
.ql-reset-btn {
    height: 46px;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 8px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}
.ql-filter-btn {
    border: 1px solid var(--ql-primary);
    background: var(--ql-primary);
    color: #fff;
    cursor: pointer;
}
.ql-filter-btn:hover { border-color: var(--ql-primary-dark); background: var(--ql-primary-dark); }
.ql-reset-btn {
    border: 1px solid var(--ql-border);
    background: #fff;
    color: #53627a;
    text-decoration: none;
}
.ql-reset-btn:hover { border-color: #cfe3ae; color: var(--ql-primary-dark); background: #f9fcf4; }

/* Table uses the same proportions as Recent Jobs on the new dashboard */
.ql-table-wrap {
    overflow-x: auto;
    padding: 0 18px;
}
.ql-table {
    width: 100%;
    min-width: 1480px;
    margin: 4px 0 0;
    border-collapse: collapse;
    white-space: nowrap;
}
.ql-table th,
.ql-table td { text-align: left; }
.ql-table th {
    padding: 14px 8px;
    border-bottom: 1px solid var(--ql-border);
    color: #65738a;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.ql-table td {
    padding: 16px 8px;
    border-bottom: 1px solid #f1f3f7;
    color: #33445f;
    font-size: 13px;
    vertical-align: middle;
}
.ql-table tbody tr:hover { background: #fbfdf8; }
.ql-table tbody tr:last-child td { border-bottom: 0; }
.ql-quote { display: flex; align-items: center; gap: 12px; }
.ql-icon {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    display: grid;
    place-items: center;
    border-radius: 9px;
    color: var(--ql-navy);
    background: var(--ql-primary-soft);
    font-size: 17px;
    font-weight: 800;
}
.ql-main { color: var(--ql-text); font-size: 13px; font-weight: 700; }
.ql-sub { margin-top: 3px; display: block; color: #8792a4; font-size: 11px; }
.ql-badge {
    display: inline-flex;
    padding: 5px 7px;
    border-radius: 5px;
    background: #f1f4f7;
    color: #5e6b80;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}
.ql-badge.active,
.ql-badge.primary { color: #5d971b; background: #f0f8e5; }
.ql-badge.new { color: #123d70; background: #edf2f7; }
.ql-badge.inactive { color: #9a731a; background: #fff8e7; }
.ql-badge.archived { color: #66748b; background: #eef2f6; }
.ql-actions { display: flex; align-items: center; justify-content: flex-end; gap: 2px; }
.ql-action {
    width: 34px;
    height: 34px;
    padding: 0;
    display: grid;
    place-items: center;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: #66748b;
    text-decoration: none;
    cursor: pointer;
}
.ql-action i { font-size: 14px; }
.ql-action:hover { color: var(--ql-primary-dark); background: var(--ql-primary-soft); }
.ql-action.danger:hover { color: #b9444d; background: #fff0f1; }

.ql-footer {
    padding: 14px 18px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-top: 1px solid var(--ql-border);
    background: #fff;
}
.ql-result-count { color: var(--ql-muted); font-size: 11px; }
.ql-pagination { display: flex; align-items: center; gap: 4px; }
.ql-page-link {
    min-width: 34px;
    height: 34px;
    padding: 0 7px;
    display: grid;
    place-items: center;
    border: 1px solid var(--ql-border);
    border-radius: 6px;
    background: #fff;
    color: #66748b;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
}
.ql-page-link:hover { border-color: #cfe3ae; color: var(--ql-primary-dark); background: var(--ql-primary-soft); }
.ql-page-link.active { border-color: var(--ql-primary); color: #fff; background: var(--ql-primary); }
.ql-empty { padding: 54px 18px; color: #8b97a9; font-size: 13px; text-align: center; }
.ql-empty i { display: block; margin-bottom: 10px; color: #b8c2cf; font-size: 28px; }

@media (max-width: 1350px) {
    .ql-stats { grid-template-columns: repeat(3,minmax(0,1fr)); }
    .ql-filter-bar { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .ql-filter-actions { grid-column: span 2; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .quotes-page { padding: 20px 18px 30px; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 14px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .quotes-page { padding: 18px 13px 28px; }
    .ql-header { align-items: flex-start; flex-direction: column; margin-bottom: 18px; }
    .ql-add-btn { width: 100%; }
    .ql-stats,
    .ql-filter-bar { grid-template-columns: 1fr; }
    .ql-filter-actions { grid-column: auto; }
    .ql-filter-btn,
    .ql-reset-btn { flex: 1; }
    .ql-footer { flex-direction: column; align-items: flex-start; }
    .ql-stat { min-height: 160px; }
}
.ql-count {
    display: inline-flex;
    min-width: 28px;
    height: 28px;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    color: var(--ql-navy);
    background: #f4f7fa;
    font-size: 12px;
    font-weight: 700;
}
.ql-client-link { color: var(--ql-text); font-size: 13px; font-weight: 700; text-decoration: none; }
.ql-client-link:hover { color: var(--ql-primary-dark); }
.ql-location { color: #53627a; font-size: 12px; }


/* Quotes-specific refinements */
.ql-stat-value.value-money { font-size: 28px; letter-spacing: -.4px; }
.ql-stat-note { position: relative; z-index: 2; }
.ql-panel-head { border-bottom: 0; }
.ql-input[type="date"] { padding-left: 14px; }
.ql-date-field { position: relative; }
.ql-date-field .ql-input { padding-right: 10px; }
.ql-quote .ql-icon { color: #fff; background: var(--ql-blue); }
.ql-quote .ql-icon.green { background: var(--ql-primary); }
.ql-main {
    color: var(--ql-text);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}
.ql-main:hover { color: var(--ql-primary-dark); }
.ql-sub { margin-top: 3px; display: block; color: #8792a4; font-size: 11px; }
.ql-status {
    display: inline-flex;
    padding: 5px 7px;
    border-radius: 5px;
    background: #f1f4f7;
    color: #5e6b80;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}
.ql-status.draft { color: #66748b; background: #eef2f6; }
.ql-status.sent,
.ql-status.viewed,
.ql-status.awaiting_response { color: #123d70; background: #edf2f7; }
.ql-status.approved,
.ql-status.deposit_paid,
.ql-status.converted { color: #5d971b; background: #f0f8e5; }
.ql-status.rejected,
.ql-status.expired { color: #b9444d; background: #fff0f1; }
.ql-status.changes_requested { color: #9a731a; background: #fff8e7; }
.ql-status.archived { color: #66748b; background: #eef2f6; }
.ql-count {
    display: inline-flex;
    min-width: 28px;
    height: 28px;
    padding: 0 8px;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    color: var(--ql-navy);
    background: #f4f7fa;
    font-size: 12px;
    font-weight: 700;
}
.ql-money { color: #33445f; font-size: 13px; font-weight: 600; }
.ql-money.total { color: var(--ql-text); font-weight: 800; }
.ql-property-link { color: #53627a; font-size: 12px; font-weight: 600; text-decoration: none; }
.ql-property-link:hover { color: var(--ql-primary-dark); }
.ql-filter-actions { align-items: stretch; }
@media (max-width: 1350px) {
    .ql-filter-bar { grid-template-columns: repeat(3,minmax(0,1fr)); }
    .ql-filter-actions { grid-column: span 3; }
}
@media (max-width: 900px) {
    .ql-stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 680px) {
    .ql-stats { grid-template-columns: 1fr; }
    .ql-filter-actions { grid-column: auto; }
}

</style>

<div class="quotes-page">
    <div class="ql-header">
        <div>
            <h1>Quotes</h1>
            <p>
                Manage estimates, approvals, quote value and converted work for the current business.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a
                href="quote-add.php"
                class="ql-add-btn"
            >
                <i class="bi bi-plus-lg"></i>
                Add Quote
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="ql-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="ql-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="ql-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="ql-stats">
        <article class="ql-stat">
            <span class="ql-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="ql-stat-row">
                <span class="ql-stat-icon navy"><i class="bi bi-file-earmark-text"></i></span>
                <div class="ql-stat-copy">
                    <span class="ql-stat-label">Total Quotes</span>
                    <strong class="ql-stat-value"><?= e(number_format($stats['total_count'])); ?></strong>
                    <span class="ql-stat-note"><strong>All quotes</strong> in the current tenant</span>
                </div>
            </div>
            <span class="ql-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="ql-stat">
            <span class="ql-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="ql-stat-row">
                <span class="ql-stat-icon soft-green"><i class="bi bi-pencil-square"></i></span>
                <div class="ql-stat-copy">
                    <span class="ql-stat-label">Draft</span>
                    <strong class="ql-stat-value"><?= e(number_format($stats['draft_count'])); ?></strong>
                    <span class="ql-stat-note"><strong>In preparation</strong> and not yet sent</span>
                </div>
            </div>
            <span class="ql-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="ql-stat">
            <span class="ql-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="ql-stat-row">
                <span class="ql-stat-icon green"><i class="bi bi-check2-circle"></i></span>
                <div class="ql-stat-copy">
                    <span class="ql-stat-label">Approved</span>
                    <strong class="ql-stat-value"><?= e(number_format($stats['approved_count'])); ?></strong>
                    <span class="ql-stat-note"><strong>Approved/deposit paid</strong> quotes</span>
                </div>
            </div>
            <span class="ql-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="ql-stat">
            <span class="ql-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="ql-stat-row">
                <span class="ql-stat-icon dark-green"><i class="bi bi-briefcase-fill"></i></span>
                <div class="ql-stat-copy">
                    <span class="ql-stat-label">Converted</span>
                    <strong class="ql-stat-value"><?= e(number_format($stats['converted_count'])); ?></strong>
                    <span class="ql-stat-note"><strong>Converted</strong> into jobs</span>
                </div>
            </div>
            <span class="ql-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="ql-stat">
            <span class="ql-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="ql-stat-row">
                <span class="ql-stat-icon navy"><i class="bi bi-currency-dollar"></i></span>
                <div class="ql-stat-copy">
                    <span class="ql-stat-label">Total Quote Value</span>
                    <strong class="ql-stat-value value-money"><?= e(quotesMoney($stats['total_value'])); ?></strong>
                    <span class="ql-stat-note"><strong>Combined value</strong> of active quotes</span>
                </div>
            </div>
            <span class="ql-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>
    </section>

    <section class="ql-panel">
        <div class="ql-panel-head">
            <h2 class="ql-panel-title">Quote Directory</h2>
            <span class="ql-panel-count"><?= e($totalFiltered); ?> result<?= $totalFiltered === 1 ? '' : 's'; ?></span>
        </div>
        <form
            method="get"
            action=""
            class="ql-filter-bar"
        >
            <div class="ql-input-wrap">
                <i class="bi bi-search"></i>
                <input
                    type="search"
                    name="search"
                    class="ql-input"
                    value="<?= e($search); ?>"
                    placeholder="Search quote, client, property or request"
                >
            </div>

            <select
                name="client_id"
                class="ql-select"
            >
                <option value="">
                    All Clients
                </option>

                <?php foreach ($clientOptions as $client): ?>
                    <option
                        value="<?= (int) $client['id']; ?>"
                        <?= $clientFilter ===
                            (int) $client['id']
                                ? 'selected'
                                : ''; ?>
                    >
                        <?= e($client['display_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="status"
                class="ql-select"
            >
                <option value="">
                    All Statuses
                </option>

                <?php
                $statusOptions = array(
                    'draft' => 'Draft',
                    'sent' => 'Sent',
                    'viewed' => 'Viewed',
                    'awaiting_response' => 'Awaiting Response',
                    'changes_requested' => 'Changes Requested',
                    'approved' => 'Approved',
                    'deposit_paid' => 'Deposit Paid',
                    'converted' => 'Converted',
                    'rejected' => 'Rejected',
                    'expired' => 'Expired',
                    'archived' => 'Archived'
                );

                foreach (
                    $statusOptions as
                    $value => $label
                ):
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

            <input
                type="date"
                name="date_from"
                class="ql-input ql-date-field"
                value="<?= e($dateFrom); ?>"
                title="From date"
            >

            <input
                type="date"
                name="date_to"
                class="ql-input ql-date-field"
                value="<?= e($dateTo); ?>"
                title="To date"
            >

            <select
                name="sort"
                class="ql-select"
            >
                <option value="latest" <?= $sort === 'latest' ? 'selected' : ''; ?>>
                    Latest First
                </option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : ''; ?>>
                    Oldest First
                </option>
                <option value="quote_asc" <?= $sort === 'quote_asc' ? 'selected' : ''; ?>>
                    Quote Number A-Z
                </option>
                <option value="quote_desc" <?= $sort === 'quote_desc' ? 'selected' : ''; ?>>
                    Quote Number Z-A
                </option>
                <option value="client_asc" <?= $sort === 'client_asc' ? 'selected' : ''; ?>>
                    Client A-Z
                </option>
                <option value="total_high" <?= $sort === 'total_high' ? 'selected' : ''; ?>>
                    Highest Value
                </option>
                <option value="total_low" <?= $sort === 'total_low' ? 'selected' : ''; ?>>
                    Lowest Value
                </option>
            </select>

            <div class="ql-filter-actions">
                <button
                    type="submit"
                    class="ql-filter-btn"
                >
                    Apply
                </button>

                <a
                    href="quotes.php"
                    class="ql-reset-btn"
                >
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($quotes)): ?>
            <div class="ql-table-wrap">
                <table class="ql-table">
                    <thead>
                        <tr>
                            <th>Quote</th>
                            <th>Client</th>
                            <th>Property</th>
                            <th>Status</th>
                            <th>Items</th>
                            <th>Subtotal</th>
                            <th>Tax</th>
                            <th>Total</th>
                            <th>Valid Until</th>
                            <th>Created</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($quotes as $quote): ?>
                        <?php
                        $propertyTitle =
                            trim(
                                (string) $quote['property_name']
                            ) !== ''
                                ? (string) $quote['property_name']
                                : (
                                    trim(
                                        (string) $quote['property_address']
                                    ) !== ''
                                        ? (string) $quote['property_address']
                                        : '—'
                                );
                        ?>
                        <tr>
                            <td>
                                <div class="ql-quote">
                                    <span class="ql-icon"><i class="bi bi-file-earmark-text"></i></span>
                                    <span>
                                        <a
                                            href="quote-view.php?id=<?= (int) $quote['id']; ?>"
                                            class="ql-main"
                                        >
                                            <?= e($quote['quote_no']); ?>
                                        </a>
                                        <span class="ql-sub"><?= e($quote['title']); ?></span>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <a
                                    href="client-view.php?id=<?= (int) $quote['client_id']; ?>"
                                    class="ql-main"
                                >
                                    <?= e($quote['client_name']); ?>
                                </a>

                                <?php if (
                                    trim(
                                        (string) $quote['client_phone']
                                    ) !== ''
                                ): ?>
                                    <span class="ql-sub">
                                        <?= e($quote['client_phone']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($quote['property_id'])): ?>
                                    <a
                                        href="property-view.php?id=<?= (int) $quote['property_id']; ?>"
                                        class="ql-main"
                                    >
                                        <?= e($propertyTitle); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="ql-status <?= e(
                                    quotesStatusClass(
                                        $quote['status']
                                    )
                                ); ?>">
                                    <?= e(
                                        quotesStatusLabel(
                                            $quote['status']
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="ql-count"><?= e((int) $quote['item_count']); ?></span>
                            </td>

                            <td>
                                <span class="ql-money"><?= e(quotesMoney($quote['subtotal'])); ?></span>
                            </td>

                            <td>
                                <span class="ql-money"><?= e(quotesMoney($quote['tax_total'])); ?></span>
                            </td>

                            <td>
                                <span class="ql-money total"><?= e(quotesMoney($quote['total'])); ?></span>
                            </td>

                            <td>
                                <?= e(
                                    quotesDate(
                                        $quote['valid_until']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <?= e(
                                    quotesDate(
                                        $quote['created_at']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <div class="ql-actions">
                                    <a
                                        href="quote-view.php?id=<?= (int) $quote['id']; ?>"
                                        class="ql-action"
                                        title="View"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($canManage): ?>
                                        <a
                                            href="quote-edit.php?id=<?= (int) $quote['id']; ?>"
                                            class="ql-action"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (
                                        !empty(
                                            $quote['converted_job_id']
                                        )
                                    ): ?>
                                        <a
                                            href="job-view.php?id=<?= (int) $quote['converted_job_id']; ?>"
                                            class="ql-action"
                                            title="Converted Job <?= e($quote['converted_job_no']); ?>"
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

            <div class="ql-footer">
                <div class="ql-result-count">
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
                            $offset + count($quotes)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    quotes
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="ql-pagination">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    quotesBuildQueryString(
                                        array(
                                            'page' => $page - 1
                                        )
                                    )
                                ); ?>"
                                class="ql-page-link"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage =
                            max(1, $page - 2);

                        $endPage =
                            min(
                                $totalPages,
                                $page + 2
                            );

                        for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(
                                    quotesBuildQueryString(
                                        array(
                                            'page' => $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="ql-page-link <?= $pageNumber === $page ? 'active' : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    quotesBuildQueryString(
                                        array(
                                            'page' => $page + 1
                                        )
                                    )
                                ); ?>"
                                class="ql-page-link"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="ql-empty">
                <i class="bi bi-file-earmark-text"></i>
                <?php if (
                    $search !== '' ||
                    $clientFilter > 0 ||
                    $statusFilter !== '' ||
                    $dateFrom !== '' ||
                    $dateTo !== ''
                ): ?>
                    No quotes found for the selected filters.
                <?php else: ?>
                    No quotes are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
