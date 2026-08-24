<?php
/**
 * FieldPlx - Properties List
 *
 * Upload as:
 * /public_html/properties.php
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
        rawurlencode('properties.php')
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

$pageTitle = 'Properties - FieldPlx';
$activePage = 'properties';
$searchPlaceholder = 'Search properties...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$errors = array();

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

$canManage = function_exists('hasPermission')
    ? hasPermission('properties.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('propertiesFetchAssoc')) {
    function propertiesFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('propertiesFetchAll')) {
    function propertiesFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('propertiesBindParams')) {
    function propertiesBindParams(
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

if (!function_exists('propertiesCsrfToken')) {
    function propertiesCsrfToken()
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

if (!function_exists('propertiesVerifyCsrf')) {
    function propertiesVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('propertiesStatusClass')) {
    function propertiesStatusClass($status)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $status))
        );
    }
}

if (!function_exists('propertiesDate')) {
    function propertiesDate($value)
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

if (!function_exists('propertiesBuildQueryString')) {
    function propertiesBuildQueryString(
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

if (!function_exists('propertiesLogActivity')) {
    function propertiesLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $propertyId,
        $clientId,
        $propertyName
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
                'property_archived',
                'property',
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

        $title = 'Property archived: ' . $propertyName;

        $details = json_encode(
            array(
                'property_id' => (int) $propertyId,
                'client_id' => (int) $clientId,
                'property_name' => (string) $propertyName
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $propertyId,
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
| Archive property
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'archive'
) {
    if (!$canManage) {
        $errors[] =
            'You do not have permission to archive properties.';
    }

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!propertiesVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $archivePropertyId = isset($_POST['property_id'])
        ? (int) $_POST['property_id']
        : 0;

    if ($archivePropertyId <= 0) {
        $errors[] = 'Invalid property selected.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT
                id,
                client_id,
                name,
                address_line1
            FROM properties
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");

        $propertyRow = null;

        if ($stmt) {
            $stmt->bind_param(
                'ii',
                $archivePropertyId,
                $tenantId
            );

            $stmt->execute();
            $propertyRow = propertiesFetchAssoc($stmt);
            $stmt->close();
        }

        if (!$propertyRow) {
            $errors[] = 'Property not found.';
        } else {
            $propertyName =
                trim((string) $propertyRow['name']) !== ''
                    ? (string) $propertyRow['name']
                    : (string) $propertyRow['address_line1'];

            $stmt = $conn->prepare("
                UPDATE properties
                SET
                    status = 'archived',
                    deleted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                  AND deleted_at IS NULL
            ");

            if (!$stmt) {
                $errors[] =
                    'Unable to prepare archive operation.';
            } else {
                $stmt->bind_param(
                    'ii',
                    $archivePropertyId,
                    $tenantId
                );

                if ($stmt->execute()) {
                    $stmt->close();

                    propertiesLogActivity(
                        $conn,
                        $tenantId,
                        $currentUserId,
                        $archivePropertyId,
                        (int) $propertyRow['client_id'],
                        $propertyName
                    );

                    $_SESSION['flash_success'] =
                        'Property archived successfully.';

                    header('Location: properties.php');
                    exit;
                }

                $errors[] =
                    'Property could not be archived: ' .
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

$clientFilter = isset($_GET['client_id'])
    ? (int) $_GET['client_id']
    : 0;

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$primaryFilter = isset($_GET['primary'])
    ? trim((string) $_GET['primary'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'latest';

$allowedStatuses = array(
    '',
    'active',
    'inactive',
    'archived'
);

$allowedPrimary = array(
    '',
    'yes',
    'no'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'name_asc',
    'name_desc',
    'client_asc'
);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if (!in_array($primaryFilter, $allowedPrimary, true)) {
    $primaryFilter = '';
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
| Client filter options
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
    $clientOptions = propertiesFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'active' => 0,
    'primary' => 0,
    'with_jobs' => 0
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(p.status = 'active') AS active,
        SUM(p.is_primary = 1) AS primary_count,
        SUM(
            EXISTS(
                SELECT 1
                FROM jobs j
                WHERE j.tenant_id = p.tenant_id
                  AND j.property_id = p.id
                  AND j.deleted_at IS NULL
            )
        ) AS with_jobs
    FROM properties p
    WHERE p.tenant_id = ?
      AND p.deleted_at IS NULL
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = propertiesFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['total'] = (int) $row['total'];
        $stats['active'] = (int) $row['active'];
        $stats['primary'] = (int) $row['primary_count'];
        $stats['with_jobs'] = (int) $row['with_jobs'];
    }
}

/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
*/

$where = array(
    'p.tenant_id = ?',
    'p.deleted_at IS NULL'
);

$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        p.name LIKE ?
        OR p.address_line1 LIKE ?
        OR p.address_line2 LIKE ?
        OR p.city LIKE ?
        OR p.state LIKE ?
        OR p.postal_code LIKE ?
        OR p.country LIKE ?
        OR c.display_name LIKE ?
    )";

    $searchLike = '%' . $search . '%';

    for ($i = 0; $i < 8; $i++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($clientFilter > 0) {
    $where[] = 'p.client_id = ?';
    $params[] = $clientFilter;
    $types .= 'i';
}

if ($statusFilter !== '') {
    $where[] = 'p.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($primaryFilter === 'yes') {
    $where[] = 'p.is_primary = 1';
} elseif ($primaryFilter === 'no') {
    $where[] = 'p.is_primary = 0';
}

$whereSql = implode(' AND ', $where);

$orderSql = 'p.created_at DESC';

if ($sort === 'oldest') {
    $orderSql = 'p.created_at ASC';
} elseif ($sort === 'name_asc') {
    $orderSql = 'COALESCE(NULLIF(p.name, ""), p.address_line1) ASC';
} elseif ($sort === 'name_desc') {
    $orderSql = 'COALESCE(NULLIF(p.name, ""), p.address_line1) DESC';
} elseif ($sort === 'client_asc') {
    $orderSql = 'c.display_name ASC, p.created_at DESC';
}

/*
|--------------------------------------------------------------------------
| Count filtered
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM properties p
    INNER JOIN clients c
        ON c.id = p.client_id
       AND c.tenant_id = p.tenant_id
    WHERE {$whereSql}
");

if ($stmt) {
    propertiesBindParams(
        $stmt,
        $types,
        $params
    );

    $stmt->execute();
    $row = propertiesFetchAssoc($stmt);
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
| Load properties
|--------------------------------------------------------------------------
*/

$properties = array();

$sql = "
    SELECT
        p.id,
        p.client_id,
        p.name,
        p.address_line1,
        p.address_line2,
        p.city,
        p.state,
        p.postal_code,
        p.country,
        p.service_area,
        p.tax_area,
        p.is_primary,
        p.status,
        p.created_at,
        c.display_name AS client_name,
        (
            SELECT COUNT(*)
            FROM jobs j
            WHERE j.tenant_id = p.tenant_id
              AND j.property_id = p.id
              AND j.deleted_at IS NULL
        ) AS job_count,
        (
            SELECT COUNT(*)
            FROM requests r
            WHERE r.tenant_id = p.tenant_id
              AND r.property_id = p.id
              AND r.archived_at IS NULL
        ) AS request_count,
        (
            SELECT COUNT(*)
            FROM visits v
            INNER JOIN jobs vj
                ON vj.id = v.job_id
               AND vj.tenant_id = v.tenant_id
               AND vj.deleted_at IS NULL
            WHERE v.tenant_id = p.tenant_id
              AND vj.property_id = p.id
        ) AS visit_count
    FROM properties p
    INNER JOIN clients c
        ON c.id = p.client_id
       AND c.tenant_id = p.tenant_id
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare the property list query: ' .
        $conn->error;
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    if (
        !propertiesBindParams(
            $stmt,
            $listTypes,
            $listParams
        )
    ) {
        $errors[] =
            'Unable to bind the property list filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to load properties: ' .
            $stmt->error;
    } else {
        $properties =
            propertiesFetchAll($stmt);
    }

    $stmt->close();
}

$csrfToken = propertiesCsrfToken();

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
    --pp-navy: #001131;
    --pp-navy-light: #071f49;
    --pp-blue: #123d70;
    --pp-primary: #74b824;
    --pp-primary-dark: #5d971b;
    --pp-primary-soft: #f0f8e5;
    --pp-red: #e45b66;
    --pp-bg: #f6f8fb;
    --pp-text: #0b1933;
    --pp-muted: #6f7b90;
    --pp-border: #e5eaf1;
}

body {
    background: var(--pp-bg) !important;
    color: var(--pp-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

/* Shared FieldPlx shell - same visual system as the new dashboard */
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--pp-border) !important;
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
    color: var(--pp-navy) !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--pp-navy) !important;
    background: var(--pp-primary-soft) !important;
}
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--pp-text) !important;
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
.fieldplx-profile-button:hover { background: var(--pp-primary-soft) !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--pp-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: var(--pp-muted) !important; font-size: 12px !important; }
.fieldplx-notification-count { background: var(--pp-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--pp-border) !important;
    box-shadow: 0 18px 45px rgba(29,38,74,.14) !important;
}
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--pp-primary-dark) !important; }

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,var(--pp-navy-light),var(--pp-navy)) !important;
    border-top: 4px solid var(--pp-primary) !important;
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
    color: var(--pp-navy) !important;
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

/* Properties page - exact component language used by the new dashboard */
.properties-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.pp-header {
    margin-bottom: 23px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}
.pp-eyebrow { display: none; }
.pp-header h1 {
    margin: 0 0 8px;
    color: var(--pp-text);
    font-size: 28px;
    font-weight: 700;
}
.pp-header p {
    margin: 0;
    color: var(--pp-muted);
    font-size: 14px;
}
.pp-add-btn {
    height: 46px;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--pp-primary);
    border-radius: 9px;
    background: var(--pp-primary);
    color: #fff;
    box-shadow: 0 5px 15px rgba(31,43,88,.05);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}
.pp-add-btn:hover {
    border-color: var(--pp-primary-dark);
    color: #fff;
    background: var(--pp-primary-dark);
}

.pp-alert {
    margin-bottom: 16px;
    padding: 12px 14px;
    border-radius: 9px;
    font-size: 13px;
    line-height: 1.6;
}
.pp-alert.error { border: 1px solid #f4c8cc; background: #fff5f6; color: #b5434d; }
.pp-alert.success { border: 1px solid #d3e9b8; background: #f7fbf1; color: var(--pp-primary-dark); }

/* Same stat cards as new dashboard */
.pp-stats {
    margin-bottom: 18px;
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 14px;
}
.pp-stat {
    position: relative;
    min-height: 170px;
    padding: 25px 20px 8px;
    overflow: hidden;
    border: 1px solid var(--pp-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.pp-stat-more {
    position: absolute;
    top: 13px;
    right: 11px;
    color: #8995a8;
    font-size: 18px;
}
.pp-stat-row {
    display: flex;
    align-items: flex-start;
    gap: 18px;
}
.pp-stat-icon {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    font-size: 27px;
}
.pp-stat-icon.navy { background: var(--pp-blue); }
.pp-stat-icon.green { background: var(--pp-primary); }
.pp-stat-icon.dark-green { background: var(--pp-primary-dark); }
.pp-stat-icon.soft-green { background: #96c945; }
.pp-stat-copy { min-width: 0; }
.pp-stat-label {
    display: block;
    margin-bottom: 10px;
    color: #66748b;
    font-size: 13px;
    font-weight: 500;
}
.pp-stat-value {
    display: block;
    color: var(--pp-text);
    font-size: 34px;
    line-height: 1;
    font-weight: 700;
}
.pp-stat-note {
    display: block;
    margin-top: 14px;
    color: #8a95a8;
    font-size: 11px;
    line-height: 1.5;
}
.pp-stat-note strong {
    color: var(--pp-primary-dark);
    font-size: 11px;
}
.pp-stat-wave {
    position: absolute;
    right: 18px;
    bottom: 7px;
    left: 18px;
    height: 38px;
    opacity: .72;
    pointer-events: none;
}
.pp-stat-wave svg { width: 100%; height: 100%; display: block; }
.pp-stat-wave path { fill: none; stroke: #d5e9ba; stroke-width: 2; vector-effect: non-scaling-stroke; }
.pp-stat-wave path.accent { stroke: var(--pp-primary); }

/* Same white panel language as dashboard */
.pp-panel {
    overflow: hidden;
    border: 1px solid var(--pp-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.pp-panel-head {
    padding: 18px 18px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.pp-panel-title {
    margin: 0;
    color: var(--pp-text);
    font-size: 18px;
    font-weight: 700;
}
.pp-panel-count {
    padding: 4px 8px;
    border-radius: 999px;
    color: var(--pp-primary-dark);
    background: var(--pp-primary-soft);
    font-size: 11px;
    font-weight: 700;
}

.pp-filter-bar {
    padding: 0 18px 18px;
    display: grid;
    grid-template-columns: minmax(235px,1.35fr) minmax(155px,.72fr) minmax(125px,.55fr) minmax(135px,.58fr) minmax(150px,.65fr) auto;
    gap: 9px;
    border-bottom: 1px solid var(--pp-border);
}
.pp-input-wrap { position: relative; }
.pp-input-wrap > i {
    position: absolute;
    top: 50%;
    left: 13px;
    z-index: 1;
    transform: translateY(-50%);
    color: #8b97a9;
    font-size: 15px;
    pointer-events: none;
}
.pp-input,
.pp-select {
    width: 100%;
    height: 46px;
    padding: 0 14px;
    border: 1px solid var(--pp-border);
    border-radius: 8px;
    background: #fff;
    color: var(--pp-text);
    font-family: inherit;
    font-size: 13px;
    outline: none;
}
.pp-input { padding-left: 37px; }
.pp-input:focus,
.pp-select:focus {
    border-color: #cfe3ae;
    box-shadow: 0 0 0 3px rgba(116,184,36,.12);
}
.pp-filter-actions { display: flex; gap: 7px; }
.pp-filter-btn,
.pp-reset-btn {
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
.pp-filter-btn {
    border: 1px solid var(--pp-primary);
    background: var(--pp-primary);
    color: #fff;
    cursor: pointer;
}
.pp-filter-btn:hover { border-color: var(--pp-primary-dark); background: var(--pp-primary-dark); }
.pp-reset-btn {
    border: 1px solid var(--pp-border);
    background: #fff;
    color: #53627a;
    text-decoration: none;
}
.pp-reset-btn:hover { border-color: #cfe3ae; color: var(--pp-primary-dark); background: #f9fcf4; }

/* Table uses the same proportions as Recent Jobs on the new dashboard */
.pp-table-wrap {
    overflow-x: auto;
    padding: 0 18px;
}
.pp-table {
    width: 100%;
    min-width: 1380px;
    margin: 4px 0 0;
    border-collapse: collapse;
    white-space: nowrap;
}
.pp-table th,
.pp-table td { text-align: left; }
.pp-table th {
    padding: 14px 8px;
    border-bottom: 1px solid var(--pp-border);
    color: #65738a;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.pp-table td {
    padding: 16px 8px;
    border-bottom: 1px solid #f1f3f7;
    color: #33445f;
    font-size: 13px;
    vertical-align: middle;
}
.pp-table tbody tr:hover { background: #fbfdf8; }
.pp-table tbody tr:last-child td { border-bottom: 0; }
.pp-property { display: flex; align-items: center; gap: 12px; }
.pp-icon {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    display: grid;
    place-items: center;
    border-radius: 9px;
    color: var(--pp-navy);
    background: var(--pp-primary-soft);
    font-size: 17px;
    font-weight: 800;
}
.pp-main { color: var(--pp-text); font-size: 13px; font-weight: 700; }
.pp-sub { margin-top: 3px; display: block; color: #8792a4; font-size: 11px; }
.pp-badge {
    display: inline-flex;
    padding: 5px 7px;
    border-radius: 5px;
    background: #f1f4f7;
    color: #5e6b80;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}
.pp-badge.active,
.pp-badge.primary { color: #5d971b; background: #f0f8e5; }
.pp-badge.new { color: #123d70; background: #edf2f7; }
.pp-badge.inactive { color: #9a731a; background: #fff8e7; }
.pp-badge.archived { color: #66748b; background: #eef2f6; }
.pp-actions { display: flex; align-items: center; justify-content: flex-end; gap: 2px; }
.pp-action {
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
.pp-action i { font-size: 14px; }
.pp-action:hover { color: var(--pp-primary-dark); background: var(--pp-primary-soft); }
.pp-action.danger:hover { color: #b9444d; background: #fff0f1; }

.pp-footer {
    padding: 14px 18px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-top: 1px solid var(--pp-border);
    background: #fff;
}
.pp-result-count { color: var(--pp-muted); font-size: 11px; }
.pp-pagination { display: flex; align-items: center; gap: 4px; }
.pp-page-link {
    min-width: 34px;
    height: 34px;
    padding: 0 7px;
    display: grid;
    place-items: center;
    border: 1px solid var(--pp-border);
    border-radius: 6px;
    background: #fff;
    color: #66748b;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
}
.pp-page-link:hover { border-color: #cfe3ae; color: var(--pp-primary-dark); background: var(--pp-primary-soft); }
.pp-page-link.active { border-color: var(--pp-primary); color: #fff; background: var(--pp-primary); }
.pp-empty { padding: 54px 18px; color: #8b97a9; font-size: 13px; text-align: center; }
.pp-empty i { display: block; margin-bottom: 10px; color: #b8c2cf; font-size: 28px; }

@media (max-width: 1200px) {
    .pp-stats { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .pp-filter-bar { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .pp-filter-actions { grid-column: span 2; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .properties-page { padding: 20px 18px 30px; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 14px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .properties-page { padding: 18px 13px 28px; }
    .pp-header { align-items: flex-start; flex-direction: column; margin-bottom: 18px; }
    .pp-add-btn { width: 100%; }
    .pp-stats,
    .pp-filter-bar { grid-template-columns: 1fr; }
    .pp-filter-actions { grid-column: auto; }
    .pp-filter-btn,
    .pp-reset-btn { flex: 1; }
    .pp-footer { flex-direction: column; align-items: flex-start; }
    .pp-stat { min-height: 160px; }
}
.pp-count {
    display: inline-flex;
    min-width: 28px;
    height: 28px;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    color: var(--pp-navy);
    background: #f4f7fa;
    font-size: 12px;
    font-weight: 700;
}
.pp-client-link { color: var(--pp-text); font-size: 13px; font-weight: 700; text-decoration: none; }
.pp-client-link:hover { color: var(--pp-primary-dark); }
.pp-location { color: #53627a; font-size: 12px; }

</style>

<div class="properties-page">
    <div class="pp-header">
        <div>
            <h1>Properties</h1>
            <p>
                Manage client service locations, property activity and field-service coverage for the current business.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a
                href="property-add.php"
                class="pp-add-btn"
            >
                <i class="bi bi-plus-lg"></i>
                Add Property
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="pp-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="pp-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="pp-stats">
        <article class="pp-stat">
            <span class="pp-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="pp-stat-row">
                <span class="pp-stat-icon navy"><i class="bi bi-buildings"></i></span>
                <div class="pp-stat-copy">
                    <span class="pp-stat-label">Total Properties</span>
                    <strong class="pp-stat-value"><?= e(number_format($stats['total'])); ?></strong>
                    <span class="pp-stat-note"><strong>All locations</strong> in the current tenant</span>
                </div>
            </div>
            <span class="pp-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="pp-stat">
            <span class="pp-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="pp-stat-row">
                <span class="pp-stat-icon green"><i class="bi bi-geo-alt-fill"></i></span>
                <div class="pp-stat-copy">
                    <span class="pp-stat-label">Active</span>
                    <strong class="pp-stat-value"><?= e(number_format($stats['active'])); ?></strong>
                    <span class="pp-stat-note"><strong>Ready</strong> service locations</span>
                </div>
            </div>
            <span class="pp-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="pp-stat">
            <span class="pp-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="pp-stat-row">
                <span class="pp-stat-icon soft-green"><i class="bi bi-star-fill"></i></span>
                <div class="pp-stat-copy">
                    <span class="pp-stat-label">Primary Locations</span>
                    <strong class="pp-stat-value"><?= e(number_format($stats['primary'])); ?></strong>
                    <span class="pp-stat-note"><strong>Preferred</strong> client locations</span>
                </div>
            </div>
            <span class="pp-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>

        <article class="pp-stat">
            <span class="pp-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
            <div class="pp-stat-row">
                <span class="pp-stat-icon dark-green"><i class="bi bi-briefcase-fill"></i></span>
                <div class="pp-stat-copy">
                    <span class="pp-stat-label">With Jobs</span>
                    <strong class="pp-stat-value"><?= e(number_format($stats['with_jobs'])); ?></strong>
                    <span class="pp-stat-note"><strong>Active history</strong> linked to jobs</span>
                </div>
            </div>
            <span class="pp-stat-wave" aria-hidden="true"><svg viewBox="0 0 220 38" preserveAspectRatio="none"><path d="M0 29 C28 21, 46 31, 70 23 S112 13, 138 22 S178 27, 220 12"/><path class="accent" d="M0 30 C34 24, 48 27, 76 21 S116 17, 142 22 S180 18, 220 9"/></svg></span>
        </article>
    </section>

    <section class="pp-panel">
        <div class="pp-panel-head">
            <h2 class="pp-panel-title">Property Directory</h2>
            <span class="pp-panel-count"><?= e($totalFiltered); ?> result<?= $totalFiltered === 1 ? '' : 's'; ?></span>
        </div>
        <form
            method="get"
            action=""
            class="pp-filter-bar"
        >
            <div class="pp-input-wrap">
                <i class="bi bi-search"></i>
                <input
                    type="search"
                    name="search"
                    class="pp-input"
                    value="<?= e($search); ?>"
                    placeholder="Search property, client, city or postcode"
                >
            </div>

            <select
                name="client_id"
                class="pp-select"
            >
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

            <select
                name="status"
                class="pp-select"
            >
                <option value="">All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>
                    Active
                </option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : ''; ?>>
                    Inactive
                </option>
                <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : ''; ?>>
                    Archived
                </option>
            </select>

            <select
                name="primary"
                class="pp-select"
            >
                <option value="">All Locations</option>
                <option value="yes" <?= $primaryFilter === 'yes' ? 'selected' : ''; ?>>
                    Primary Only
                </option>
                <option value="no" <?= $primaryFilter === 'no' ? 'selected' : ''; ?>>
                    Non-primary
                </option>
            </select>

            <select
                name="sort"
                class="pp-select"
            >
                <option value="latest" <?= $sort === 'latest' ? 'selected' : ''; ?>>
                    Latest First
                </option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : ''; ?>>
                    Oldest First
                </option>
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : ''; ?>>
                    Property A-Z
                </option>
                <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : ''; ?>>
                    Property Z-A
                </option>
                <option value="client_asc" <?= $sort === 'client_asc' ? 'selected' : ''; ?>>
                    Client A-Z
                </option>
            </select>

            <div class="pp-filter-actions">
                <button
                    type="submit"
                    class="pp-filter-btn"
                >
                    Apply
                </button>

                <a
                    href="properties.php"
                    class="pp-reset-btn"
                >
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($properties)): ?>
            <div class="pp-table-wrap">
                <table class="pp-table">
                    <thead>
                        <tr>
                            <th>Property</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th>Primary</th>
                            <th>City / State</th>
                            <th>Jobs</th>
                            <th>Requests</th>
                            <th>Visits</th>
                            <th>Service Area</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($properties as $property): ?>
                        <?php
                        $propertyTitle =
                            trim((string) $property['name']) !== ''
                                ? (string) $property['name']
                                : (string) $property['address_line1'];

                        $locationText = trim(
                            implode(
                                ', ',
                                array_filter(
                                    array(
                                        $property['city'],
                                        $property['state'],
                                        $property['postal_code']
                                    ),
                                    function ($value) {
                                        return trim((string) $value) !== '';
                                    }
                                )
                            )
                        );
                        ?>
                        <tr>
                            <td>
                                <div class="pp-property">
                                    <span class="pp-icon">
                                        <i class="bi bi-geo-alt"></i>
                                    </span>

                                    <span>
                                        <span class="pp-main">
                                            <?= e($propertyTitle); ?>
                                        </span>

                                        <span class="pp-sub">
                                            <?= e($property['address_line1']); ?>
                                        </span>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <a
                                    href="client-view.php?id=<?= (int) $property['client_id']; ?>"
                                    class="pp-client-link"
                                >
                                    <?= e($property['client_name']); ?>
                                </a>
                            </td>

                            <td>
                                <span class="pp-badge <?= e(
                                    propertiesStatusClass(
                                        $property['status']
                                    )
                                ); ?>">
                                    <?= e($property['status']); ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!empty($property['is_primary'])): ?>
                                    <span class="pp-badge primary">
                                        Primary
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e($locationText !== '' ? $locationText : '—'); ?>
                            </td>

                            <td>
                                <span class="pp-count"><?= e((int) $property['job_count']); ?></span>
                            </td>

                            <td>
                                <span class="pp-count"><?= e((int) $property['request_count']); ?></span>
                            </td>

                            <td>
                                <span class="pp-count"><?= e((int) $property['visit_count']); ?></span>
                            </td>

                            <td>
                                <?= e(
                                    trim((string) $property['service_area']) !== ''
                                        ? $property['service_area']
                                        : '—'
                                ); ?>
                            </td>

                            <td>
                                <?= e(
                                    propertiesDate(
                                        $property['created_at']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <div class="pp-actions">
                                    <a
                                        href="property-view.php?id=<?= (int) $property['id']; ?>"
                                        class="pp-action"
                                        title="View"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($canManage): ?>
                                        <a
                                            href="property-edit.php?id=<?= (int) $property['id']; ?>"
                                            class="pp-action"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <form
                                            method="post"
                                            action=""
                                            class="archive-property-form"
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
                                                name="property_id"
                                                value="<?= (int) $property['id']; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="pp-action danger"
                                                title="Archive"
                                                aria-label="Archive property"
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

            <div class="pp-footer">
                <div class="pp-result-count">
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
                            $offset + count($properties)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    properties
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pp-pagination">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    propertiesBuildQueryString(
                                        array(
                                            'page' => $page - 1
                                        )
                                    )
                                ); ?>"
                                class="pp-page-link"
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
                                    propertiesBuildQueryString(
                                        array(
                                            'page' => $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="pp-page-link <?= $pageNumber === $page ? 'active' : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    propertiesBuildQueryString(
                                        array(
                                            'page' => $page + 1
                                        )
                                    )
                                ); ?>"
                                class="pp-page-link"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="pp-empty">
                <?php if (
                    $search !== '' ||
                    $clientFilter > 0 ||
                    $statusFilter !== '' ||
                    $primaryFilter !== ''
                ): ?>
                    No properties found for the selected filters.
                <?php else: ?>
                    No properties are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document
        .querySelectorAll('.archive-property-form')
        .forEach(function (form) {
            form.addEventListener(
                'submit',
                function (event) {
                    var confirmed = window.confirm(
                        'Archive this property? It will be removed from the active property list.'
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
