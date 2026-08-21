<?php
/**
 * FieldPlx - Clients List
 *
 * Upload as:
 * /public_html/clients.php
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
        rawurlencode('clients.php')
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

$pageTitle = 'Clients - FieldPlx';
$activePage = 'clients';
$searchPlaceholder = 'Search clients...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$errors = array();

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

$canCreate = function_exists('hasPermission')
    ? hasPermission('clients.create')
    : true;

$canUpdate = function_exists('hasPermission')
    ? hasPermission('clients.update')
    : true;

$canDelete = function_exists('hasPermission')
    ? hasPermission('clients.delete')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('clientsFetchAssoc')) {
    function clientsFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('clientsFetchAll')) {
    function clientsFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('clientsBindParams')) {
    function clientsBindParams(
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

if (!function_exists('clientsCsrfToken')) {
    function clientsCsrfToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));
            } catch (Throwable $error) {
                $_SESSION['csrf_token'] =
                    sha1(
                        uniqid(
                            (string) mt_rand(),
                            true
                        )
                    );
            }
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('clientsVerifyCsrf')) {
    function clientsVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('clientsStatusClass')) {
    function clientsStatusClass($status)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $status))
        );
    }
}

if (!function_exists('clientsDate')) {
    function clientsDate($value)
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

if (!function_exists('clientsBuildQueryString')) {
    function clientsBuildQueryString(
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

if (!function_exists('clientsLogActivity')) {
    function clientsLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $clientId,
        $clientName
    ) {
        $stmt = $conn->prepare("
            INSERT INTO activity_events (
                tenant_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                client_id,
                title,
                details_json,
                visible_to_client,
                created_at
            ) VALUES (
                ?,
                ?,
                'user',
                'client_archived',
                'client',
                ?,
                ?,
                ?,
                ?,
                0,
                NOW()
            )
        ");

        if (!$stmt) {
            return;
        }

        $title = 'Client archived: ' . $clientName;

        $details = json_encode(
            array(
                'client_id' => (int) $clientId,
                'client_name' => (string) $clientName
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $clientId,
            $clientId,
            $title,
            $details
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Archive action
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'archive'
) {
    if (!$canDelete) {
        $errors[] =
            'You do not have permission to archive clients.';
    }

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!clientsVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $archiveClientId = isset($_POST['client_id'])
        ? (int) $_POST['client_id']
        : 0;

    if ($archiveClientId <= 0) {
        $errors[] = 'Invalid client selected.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT display_name
            FROM clients
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");

        $clientName = '';

        if ($stmt) {
            $stmt->bind_param(
                'ii',
                $archiveClientId,
                $tenantId
            );

            $stmt->execute();
            $row = clientsFetchAssoc($stmt);
            $stmt->close();

            if ($row) {
                $clientName =
                    (string) $row['display_name'];
            }
        }

        if ($clientName === '') {
            $errors[] = 'Client not found.';
        } else {
            $stmt = $conn->prepare("
                UPDATE clients
                SET
                    status = 'archived',
                    client_type = 'archived',
                    deleted_at = NOW(),
                    updated_by = ?,
                    updated_at = NOW(),
                    last_activity_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                  AND deleted_at IS NULL
            ");

            if (!$stmt) {
                $errors[] =
                    'Unable to prepare archive operation.';
            } else {
                $stmt->bind_param(
                    'iii',
                    $currentUserId,
                    $archiveClientId,
                    $tenantId
                );

                if ($stmt->execute()) {
                    $stmt->close();

                    clientsLogActivity(
                        $conn,
                        $tenantId,
                        $currentUserId,
                        $archiveClientId,
                        $clientName
                    );

                    $_SESSION['flash_success'] =
                        'Client archived successfully.';

                    header('Location: clients.php');
                    exit;
                }

                $errors[] =
                    'Client could not be archived: ' .
                    $stmt->error;

                $stmt->close();
            }
        }
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

$typeFilter = isset($_GET['type'])
    ? trim((string) $_GET['type'])
    : '';

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'latest';

$allowedTypes = array(
    '',
    'lead',
    'client',
    'archived'
);

$allowedStatuses = array(
    '',
    'new',
    'active',
    'inactive',
    'archived'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'name_asc',
    'name_desc'
);

if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = '';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 20;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'leads' => 0,
    'active' => 0,
    'inactive' => 0
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(client_type = 'lead') AS leads,
        SUM(status = 'active') AS active,
        SUM(status = 'inactive') AS inactive
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = clientsFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['total'] = (int) $row['total'];
        $stats['leads'] = (int) $row['leads'];
        $stats['active'] = (int) $row['active'];
        $stats['inactive'] = (int) $row['inactive'];
    }
}

/*
|--------------------------------------------------------------------------
| Build client query
|--------------------------------------------------------------------------
*/

$where = array(
    'c.tenant_id = ?',
    'c.deleted_at IS NULL'
);

$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        c.display_name LIKE ?
        OR c.company_name LIKE ?
        OR c.first_name LIKE ?
        OR c.last_name LIKE ?
        OR c.email LIKE ?
        OR c.phone LIKE ?
        OR c.alternate_phone LIKE ?
    )";

    $searchLike = '%' . $search . '%';

    for ($i = 0; $i < 7; $i++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($typeFilter !== '') {
    $where[] = 'c.client_type = ?';
    $params[] = $typeFilter;
    $types .= 's';
}

if ($statusFilter !== '') {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$orderSql = 'c.created_at DESC';

if ($sort === 'oldest') {
    $orderSql = 'c.created_at ASC';
} elseif ($sort === 'name_asc') {
    $orderSql = 'c.display_name ASC';
} elseif ($sort === 'name_desc') {
    $orderSql = 'c.display_name DESC';
}

/*
|--------------------------------------------------------------------------
| Count filtered results
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM clients c
    WHERE {$whereSql}
");

if ($stmt) {
    clientsBindParams(
        $stmt,
        $types,
        $params
    );

    $stmt->execute();
    $row = clientsFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $totalFiltered = (int) $row['total'];
    }
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
| Load clients
|--------------------------------------------------------------------------
*/

$clients = array();

$sql = "
    SELECT
        c.id,
        c.client_type,
        c.display_name,
        c.company_name,
        c.first_name,
        c.last_name,
        c.email,
        c.phone,
        c.source,
        c.status,
        c.preferred_contact_method,
        c.created_at,
        c.last_activity_at,
        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN COALESCE(u.last_name, '') <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS account_manager_name,
        (
            SELECT COUNT(*)
            FROM properties p
            WHERE p.tenant_id = c.tenant_id
              AND p.client_id = c.id
              AND p.deleted_at IS NULL
        ) AS property_count,
        (
            SELECT COUNT(*)
            FROM jobs j
            WHERE j.tenant_id = c.tenant_id
              AND j.client_id = c.id
              AND j.deleted_at IS NULL
        ) AS job_count,
        (
            SELECT COALESCE(SUM(i.balance_due), 0)
            FROM invoices i
            WHERE i.tenant_id = c.tenant_id
              AND i.client_id = c.id
              AND i.archived_at IS NULL
              AND i.balance_due > 0
        ) AS outstanding_amount
    FROM clients c
    LEFT JOIN users u
        ON u.id = c.account_manager_id
       AND u.tenant_id = c.tenant_id
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    clientsBindParams(
        $stmt,
        $listTypes,
        $listParams
    );

    $stmt->execute();
    $clients = clientsFetchAll($stmt);
    $stmt->close();
}

$currencyCode = !empty($_SESSION['currency_code'])
    ? (string) $_SESSION['currency_code']
    : 'INR';

$csrfToken = clientsCsrfToken();

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
    --cl-navy: #001131;
    --cl-navy-light: #071f49;
    --cl-blue: #123d70;
    --cl-primary: #74b824;
    --cl-primary-dark: #5d971b;
    --cl-primary-soft: #f0f8e5;
    --cl-red: #e45b66;
    --cl-bg: #f6f8fb;
    --cl-text: #0b1933;
    --cl-muted: #6f7b90;
    --cl-border: #e5eaf1;
}

body {
    background: var(--cl-bg) !important;
    color: var(--cl-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

/* Shared FieldPlx shell - same visual system as the new dashboard */
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--cl-border) !important;
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
    color: var(--cl-navy) !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--cl-navy) !important;
    background: var(--cl-primary-soft) !important;
}
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--cl-text) !important;
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
.fieldplx-profile-button:hover { background: var(--cl-primary-soft) !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--cl-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: var(--cl-muted) !important; font-size: 12px !important; }
.fieldplx-notification-count { background: var(--cl-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--cl-border) !important;
    box-shadow: 0 18px 45px rgba(29,38,74,.14) !important;
}
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--cl-primary-dark) !important; }

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,var(--cl-navy-light),var(--cl-navy)) !important;
    border-top: 4px solid var(--cl-primary) !important;
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
    color: var(--cl-navy) !important;
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

/* Clients page - exact component language used by the new dashboard */
.clients-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.cl-header {
    margin-bottom: 23px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}
.cl-eyebrow { display: none; }
.cl-header h1 {
    margin: 0 0 8px;
    color: var(--cl-text);
    font-size: 28px;
    font-weight: 700;
}
.cl-header p {
    margin: 0;
    color: var(--cl-muted);
    font-size: 14px;
}
.cl-add-btn {
    height: 46px;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--cl-primary);
    border-radius: 9px;
    background: var(--cl-primary);
    color: #fff;
    box-shadow: 0 5px 15px rgba(31,43,88,.05);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}
.cl-add-btn:hover {
    border-color: var(--cl-primary-dark);
    color: #fff;
    background: var(--cl-primary-dark);
}

.cl-alert {
    margin-bottom: 16px;
    padding: 12px 14px;
    border-radius: 9px;
    font-size: 13px;
    line-height: 1.6;
}
.cl-alert.error { border: 1px solid #f4c8cc; background: #fff5f6; color: #b5434d; }
.cl-alert.success { border: 1px solid #d3e9b8; background: #f7fbf1; color: var(--cl-primary-dark); }

/* Same stat cards as new dashboard */
.cl-stats {
    margin-bottom: 18px;
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 14px;
}
.cl-stat {
    position: relative;
    min-height: 170px;
    padding: 25px 20px 8px;
    overflow: hidden;
    border: 1px solid var(--cl-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.cl-stat-more {
    position: absolute;
    top: 13px;
    right: 11px;
    color: #8995a8;
    font-size: 18px;
}
.cl-stat-row {
    display: flex;
    align-items: flex-start;
    gap: 18px;
}
.cl-stat-icon {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    font-size: 27px;
}
.cl-stat-icon.navy { background: var(--cl-blue); }
.cl-stat-icon.green { background: var(--cl-primary); }
.cl-stat-icon.dark-green { background: var(--cl-primary-dark); }
.cl-stat-icon.soft-green { background: #96c945; }
.cl-stat-copy { min-width: 0; }
.cl-stat-label {
    display: block;
    margin-bottom: 10px;
    color: #66748b;
    font-size: 13px;
    font-weight: 500;
}
.cl-stat-value {
    display: block;
    color: var(--cl-text);
    font-size: 34px;
    line-height: 1;
    font-weight: 700;
}
.cl-stat-note {
    display: block;
    margin-top: 14px;
    color: #8a95a8;
    font-size: 11px;
    line-height: 1.5;
}
.cl-stat-note strong {
    color: var(--cl-primary-dark);
    font-size: 11px;
}
.cl-stat-wave {
    position: absolute;
    right: 18px;
    bottom: 7px;
    left: 18px;
    height: 38px;
    opacity: .72;
    pointer-events: none;
}
.cl-stat-wave svg { width: 100%; height: 100%; display: block; }
.cl-stat-wave path { fill: none; stroke: #d5e9ba; stroke-width: 2; vector-effect: non-scaling-stroke; }
.cl-stat-wave path.accent { stroke: var(--cl-primary); }

/* Same white panel language as dashboard */
.cl-panel {
    overflow: hidden;
    border: 1px solid var(--cl-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.cl-panel-head {
    padding: 18px 18px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.cl-panel-title {
    margin: 0;
    color: var(--cl-text);
    font-size: 18px;
    font-weight: 700;
}
.cl-panel-count {
    padding: 4px 8px;
    border-radius: 999px;
    color: var(--cl-primary-dark);
    background: var(--cl-primary-soft);
    font-size: 11px;
    font-weight: 700;
}

.cl-filter-bar {
    padding: 0 18px 18px;
    display: grid;
    grid-template-columns: minmax(235px,1.45fr) minmax(140px,.62fr) minmax(140px,.62fr) minmax(150px,.68fr) auto;
    gap: 9px;
    border-bottom: 1px solid var(--cl-border);
}
.cl-input-wrap { position: relative; }
.cl-input-wrap > i {
    position: absolute;
    top: 50%;
    left: 13px;
    z-index: 1;
    transform: translateY(-50%);
    color: #8b97a9;
    font-size: 15px;
    pointer-events: none;
}
.cl-input,
.cl-select {
    width: 100%;
    height: 46px;
    padding: 0 14px;
    border: 1px solid var(--cl-border);
    border-radius: 8px;
    background: #fff;
    color: var(--cl-text);
    font-family: inherit;
    font-size: 13px;
    outline: none;
}
.cl-input { padding-left: 37px; }
.cl-input:focus,
.cl-select:focus {
    border-color: #cfe3ae;
    box-shadow: 0 0 0 3px rgba(116,184,36,.12);
}
.cl-filter-actions { display: flex; gap: 7px; }
.cl-filter-btn,
.cl-reset-btn {
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
.cl-filter-btn {
    border: 1px solid var(--cl-primary);
    background: var(--cl-primary);
    color: #fff;
    cursor: pointer;
}
.cl-filter-btn:hover { border-color: var(--cl-primary-dark); background: var(--cl-primary-dark); }
.cl-reset-btn {
    border: 1px solid var(--cl-border);
    background: #fff;
    color: #53627a;
    text-decoration: none;
}
.cl-reset-btn:hover { border-color: #cfe3ae; color: var(--cl-primary-dark); background: #f9fcf4; }

/* Table uses the same proportions as Recent Jobs on the new dashboard */
.cl-table-wrap {
    overflow-x: auto;
    padding: 0 18px;
}
.cl-table {
    width: 100%;
    min-width: 1120px;
    margin: 4px 0 0;
    border-collapse: collapse;
    white-space: nowrap;
}
.cl-table th,
.cl-table td { text-align: left; }
.cl-table th {
    padding: 14px 8px;
    border-bottom: 1px solid var(--cl-border);
    color: #65738a;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.cl-table td {
    padding: 16px 8px;
    border-bottom: 1px solid #f1f3f7;
    color: #33445f;
    font-size: 13px;
    vertical-align: middle;
}
.cl-table tbody tr:hover { background: #fbfdf8; }
.cl-table tbody tr:last-child td { border-bottom: 0; }
.cl-client { display: flex; align-items: center; gap: 12px; }
.cl-avatar {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    display: grid;
    place-items: center;
    border-radius: 9px;
    color: var(--cl-navy);
    background: var(--cl-primary-soft);
    font-size: 13px;
    font-weight: 800;
}
.cl-main { color: var(--cl-text); font-size: 13px; font-weight: 700; }
.cl-sub { margin-top: 3px; display: block; color: #8792a4; font-size: 11px; }
.cl-badge {
    display: inline-flex;
    padding: 5px 7px;
    border-radius: 5px;
    background: #f1f4f7;
    color: #5e6b80;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}
.cl-badge.active,
.cl-badge.client { color: #5d971b; background: #f0f8e5; }
.cl-badge.new,
.cl-badge.lead { color: #123d70; background: #edf2f7; }
.cl-badge.inactive { color: #9a731a; background: #fff8e7; }
.cl-badge.archived { color: #66748b; background: #eef2f6; }
.cl-actions { display: flex; align-items: center; justify-content: flex-end; gap: 2px; }
.cl-action {
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
.cl-action i { font-size: 14px; }
.cl-action:hover { color: var(--cl-primary-dark); background: var(--cl-primary-soft); }
.cl-action.danger:hover { color: #b9444d; background: #fff0f1; }

.cl-footer {
    padding: 14px 18px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-top: 1px solid var(--cl-border);
    background: #fff;
}
.cl-result-count { color: var(--cl-muted); font-size: 11px; }
.cl-pagination { display: flex; align-items: center; gap: 4px; }
.cl-page-link {
    min-width: 34px;
    height: 34px;
    padding: 0 7px;
    display: grid;
    place-items: center;
    border: 1px solid var(--cl-border);
    border-radius: 6px;
    background: #fff;
    color: #66748b;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
}
.cl-page-link:hover { border-color: #cfe3ae; color: var(--cl-primary-dark); background: var(--cl-primary-soft); }
.cl-page-link.active { border-color: var(--cl-primary); color: #fff; background: var(--cl-primary); }
.cl-empty { padding: 54px 18px; color: #8b97a9; font-size: 13px; text-align: center; }
.cl-empty i { display: block; margin-bottom: 10px; color: #b8c2cf; font-size: 28px; }

@media (max-width: 1200px) {
    .cl-stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .cl-filter-bar { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .cl-filter-actions { grid-column: span 2; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .clients-page { padding: 20px 18px 30px; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 14px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .clients-page { padding: 18px 13px 28px; }
    .cl-header { align-items: flex-start; flex-direction: column; margin-bottom: 18px; }
    .cl-add-btn { width: 100%; }
    .cl-stats,
    .cl-filter-bar { grid-template-columns: 1fr; }
    .cl-filter-actions { grid-column: auto; }
    .cl-filter-btn,
    .cl-reset-btn { flex: 1; }
    .cl-footer { flex-direction: column; align-items: flex-start; }
    .cl-stat { min-height: 160px; }
}
</style>

<div class="clients-page">

    <div class="cl-header">
        <div>
            <h1>Clients</h1>
            <p>
                Manage leads, customers, contact details and client activity for the current business.
            </p>
        </div>

        <?php if ($canCreate): ?>
            <a
                href="client-add.php"
                class="cl-add-btn"
            >
                <i class="bi bi-plus-lg"></i>
                Add Client
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="cl-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="cl-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="cl-stats">
        <article class="cl-stat">
            <span class="cl-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="cl-stat-row">
                <span class="cl-stat-icon navy"><i class="bi bi-people"></i></span>
                <div class="cl-stat-copy">
                    <span class="cl-stat-label">Total Clients</span>
                    <strong class="cl-stat-value"><?= e(number_format($stats['total'])); ?></strong>
                    <span class="cl-stat-note"><strong>All records</strong> in the current tenant</span>
                </div>
            </div>
            <span class="cl-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="cl-stat">
            <span class="cl-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="cl-stat-row">
                <span class="cl-stat-icon soft-green"><i class="bi bi-person-plus"></i></span>
                <div class="cl-stat-copy">
                    <span class="cl-stat-label">Leads</span>
                    <strong class="cl-stat-value"><?= e(number_format($stats['leads'])); ?></strong>
                    <span class="cl-stat-note"><strong>Pipeline</strong> potential clients</span>
                </div>
            </div>
            <span class="cl-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="cl-stat">
            <span class="cl-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="cl-stat-row">
                <span class="cl-stat-icon green"><i class="bi bi-person-check"></i></span>
                <div class="cl-stat-copy">
                    <span class="cl-stat-label">Active</span>
                    <strong class="cl-stat-value"><?= e(number_format($stats['active'])); ?></strong>
                    <span class="cl-stat-note"><strong>Active</strong> customer accounts</span>
                </div>
            </div>
            <span class="cl-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="cl-stat">
            <span class="cl-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="cl-stat-row">
                <span class="cl-stat-icon dark-green"><i class="bi bi-person-dash"></i></span>
                <div class="cl-stat-copy">
                    <span class="cl-stat-label">Inactive</span>
                    <strong class="cl-stat-value"><?= e(number_format($stats['inactive'])); ?></strong>
                    <span class="cl-stat-note"><strong>Review</strong> inactive accounts</span>
                </div>
            </div>
            <span class="cl-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>
    </section>

    <section class="cl-panel">
        <div class="cl-panel-head">
            <h2 class="cl-panel-title">Client Directory</h2>
            <span class="cl-panel-count"><?= e($totalFiltered); ?> result<?= $totalFiltered === 1 ? '' : 's'; ?></span>
        </div>

        <form
            method="get"
            action=""
            class="cl-filter-bar"
        >
            <div class="cl-input-wrap">
                <i class="bi bi-search"></i>
                <input
                    type="search"
                    name="search"
                    class="cl-input"
                    value="<?= e($search); ?>"
                    placeholder="Search name, company, email or phone"
                >
            </div>

            <select
                name="type"
                class="cl-select"
            >
                <option value="">All Types</option>
                <option
                    value="lead"
                    <?= $typeFilter === 'lead' ? 'selected' : ''; ?>
                >
                    Lead
                </option>
                <option
                    value="client"
                    <?= $typeFilter === 'client' ? 'selected' : ''; ?>
                >
                    Client
                </option>
                <option
                    value="archived"
                    <?= $typeFilter === 'archived' ? 'selected' : ''; ?>
                >
                    Archived
                </option>
            </select>

            <select
                name="status"
                class="cl-select"
            >
                <option value="">All Statuses</option>
                <option
                    value="new"
                    <?= $statusFilter === 'new' ? 'selected' : ''; ?>
                >
                    New
                </option>
                <option
                    value="active"
                    <?= $statusFilter === 'active' ? 'selected' : ''; ?>
                >
                    Active
                </option>
                <option
                    value="inactive"
                    <?= $statusFilter === 'inactive' ? 'selected' : ''; ?>
                >
                    Inactive
                </option>
                <option
                    value="archived"
                    <?= $statusFilter === 'archived' ? 'selected' : ''; ?>
                >
                    Archived
                </option>
            </select>

            <select
                name="sort"
                class="cl-select"
            >
                <option
                    value="latest"
                    <?= $sort === 'latest' ? 'selected' : ''; ?>
                >
                    Latest First
                </option>
                <option
                    value="oldest"
                    <?= $sort === 'oldest' ? 'selected' : ''; ?>
                >
                    Oldest First
                </option>
                <option
                    value="name_asc"
                    <?= $sort === 'name_asc' ? 'selected' : ''; ?>
                >
                    Name A-Z
                </option>
                <option
                    value="name_desc"
                    <?= $sort === 'name_desc' ? 'selected' : ''; ?>
                >
                    Name Z-A
                </option>
            </select>

            <div class="cl-filter-actions">
                <button
                    type="submit"
                    class="cl-filter-btn"
                >
                    <i class="bi bi-funnel"></i>
                    Apply
                </button>

                <a
                    href="clients.php"
                    class="cl-reset-btn"
                >
                    <i class="bi bi-arrow-counterclockwise"></i>
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($clients)): ?>
            <div class="cl-table-wrap">
                <table class="cl-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Contact</th>
                            <th>Properties</th>
                            <th>Jobs</th>
                            <th>Outstanding</th>
                            <th>Manager</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($clients as $client): ?>
                        <tr>
                            <td>
                                <div class="cl-client">
                                    <span class="cl-avatar">
                                        <?= e(
                                            strtoupper(
                                                substr(
                                                    (string) $client['display_name'],
                                                    0,
                                                    1
                                                )
                                            )
                                        ); ?>
                                    </span>

                                    <span>
                                        <span class="cl-main">
                                            <?= e($client['display_name']); ?>
                                        </span>

                                        <span class="cl-sub">
                                            <?= e(
                                                !empty($client['company_name'])
                                                    ? $client['company_name']
                                                    : (
                                                        !empty($client['email'])
                                                            ? $client['email']
                                                            : 'No company'
                                                    )
                                            ); ?>
                                        </span>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <span class="cl-badge <?= e(
                                    clientsStatusClass(
                                        $client['client_type']
                                    )
                                ); ?>">
                                    <?= e($client['client_type']); ?>
                                </span>
                            </td>

                            <td>
                                <span class="cl-badge <?= e(
                                    clientsStatusClass(
                                        $client['status']
                                    )
                                ); ?>">
                                    <?= e($client['status']); ?>
                                </span>
                            </td>

                            <td>
                                <span class="cl-main">
                                    <?= e(
                                        !empty($client['phone'])
                                            ? $client['phone']
                                            : '—'
                                    ); ?>
                                </span>

                                <span class="cl-sub">
                                    <?= e(
                                        !empty($client['email'])
                                            ? $client['email']
                                            : 'No email'
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <?= e((int) $client['property_count']); ?>
                            </td>

                            <td>
                                <?= e((int) $client['job_count']); ?>
                            </td>

                            <td>
                                <?= e(
                                    $currencyCode .
                                    ' ' .
                                    number_format(
                                        (float) $client['outstanding_amount'],
                                        2
                                    )
                                ); ?>
                            </td>

                            <td>
                                <?= e(
                                    trim(
                                        (string) $client['account_manager_name']
                                    ) !== ''
                                        ? $client['account_manager_name']
                                        : '—'
                                ); ?>
                            </td>

                            <td>
                                <?= e(
                                    clientsDate(
                                        $client['created_at']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <div class="cl-actions">
                                    <a
                                        href="client-view.php?id=<?= (int) $client['id']; ?>"
                                        class="cl-action"
                                        title="View"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($canUpdate): ?>
                                        <a
                                            href="client-edit.php?id=<?= (int) $client['id']; ?>"
                                            class="cl-action"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canDelete): ?>
                                        <form
                                            method="post"
                                            action=""
                                            class="archive-client-form"
                                            style="display:inline;"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrfToken); ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="archive"
                                            >

                                            <input
                                                type="hidden"
                                                name="client_id"
                                                value="<?= (int) $client['id']; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="cl-action danger"
                                                title="Archive"
                                                aria-label="Archive client"
                                            >
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="cl-footer">
                <div class="cl-result-count">
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
                            $offset + count($clients)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    clients
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="cl-pagination">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    clientsBuildQueryString(
                                        array(
                                            'page' => $page - 1
                                        )
                                    )
                                ); ?>"
                                class="cl-page-link"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min(
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
                                    clientsBuildQueryString(
                                        array(
                                            'page' => $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="cl-page-link <?= $pageNumber === $page ? 'active' : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    clientsBuildQueryString(
                                        array(
                                            'page' => $page + 1
                                        )
                                    )
                                ); ?>"
                                class="cl-page-link"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="cl-empty">
                <i class="bi bi-people"></i>
                No clients found for the selected filters.
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document
        .querySelectorAll('.archive-client-form')
        .forEach(function (form) {
            form.addEventListener(
                'submit',
                function (event) {
                    var confirmed = window.confirm(
                        'Archive this client? The client will be removed from the active list.'
                    );

                    if (!confirmed) {
                        event.preventDefault();
                    }
                }
            );
        });
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
