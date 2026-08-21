<?php
/**
 * FieldPlx Platform - Tenant Management
 *
 * File:
 * platform/tenants.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MySQLi
 * - platform_users authentication
 */

require_once __DIR__ . '/includes/auth.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
));

$pageTitle = 'Tenants - FieldPlx';
$activePage = 'tenants';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('tenantPageEscape')) {
    function tenantPageEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantPageColumnExists')) {
    function tenantPageColumnExists(mysqli $conn, $table, $column)
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $table, $column);

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return !empty($row['total']);
    }
}

if (!function_exists('tenantPageFirstColumn')) {
    function tenantPageFirstColumn(
        mysqli $conn,
        $table,
        array $candidates
    ) {
        foreach ($candidates as $candidate) {
            if (
                tenantPageColumnExists(
                    $conn,
                    $table,
                    $candidate
                )
            ) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('tenantPageStatusLabel')) {
    function tenantPageStatusLabel($status)
    {
        $status = trim((string) $status);

        if ($status === '') {
            return 'Unknown';
        }

        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                $status
            )
        );
    }
}

if (!function_exists('tenantPageStatusClass')) {
    function tenantPageStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
                return 'success';

            case 'trial':
                return 'info';

            case 'pending':
            case 'pending_approval':
                return 'warning';

            case 'suspended':
            case 'inactive':
            case 'expired':
            case 'cancelled':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('tenantPageDate')) {
    function tenantPageDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return '—';
        }

        return date('d M Y', $timestamp);
    }
}

if (!function_exists('tenantPageInitials')) {
    function tenantPageInitials($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'TN';
        }

        $parts = preg_split('/\s+/', $name);
        $initials = '';

        if (!empty($parts[0])) {
            $initials .= strtoupper(
                substr($parts[0], 0, 1)
            );
        }

        if (count($parts) > 1) {
            $lastPart = end($parts);

            if ($lastPart !== '') {
                $initials .= strtoupper(
                    substr($lastPart, 0, 1)
                );
            }
        }

        return $initials !== ''
            ? $initials
            : 'TN';
    }
}

if (!function_exists('tenantPageBuildQuery')) {
    function tenantPageBuildQuery(array $changes = array())
    {
        $parameters = $_GET;

        unset($parameters['page']);

        foreach ($changes as $key => $value) {
            if (
                $value === '' ||
                $value === null
            ) {
                unset($parameters[$key]);
            } else {
                $parameters[$key] = $value;
            }
        }

        return http_build_query($parameters);
    }
}

/*
|--------------------------------------------------------------------------
| Check tenant table
|--------------------------------------------------------------------------
*/

$tableCheck = $conn->query("
    SHOW TABLES LIKE 'tenants'
");

$tenantTableExists =
    $tableCheck &&
    $tableCheck->num_rows > 0;

if ($tableCheck) {
    $tableCheck->free();
}

if (!$tenantTableExists) {
    http_response_code(500);

    exit(
        'The tenants table does not exist.'
    );
}

/*
|--------------------------------------------------------------------------
| Detect tenant columns
|--------------------------------------------------------------------------
*/

$idColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array('id', 'tenant_id')
);

$nameColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$emailColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array(
        'email',
        'contact_email',
        'billing_email'
    )
);

$phoneColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array(
        'phone',
        'mobile',
        'contact_phone',
        'contact_mobile'
    )
);

$codeColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$statusColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array('status')
);

$createdColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array(
        'created_at',
        'created_on'
    )
);

$trialEndColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array(
        'trial_ends_at',
        'trial_end_date',
        'trial_end'
    )
);

$deletedColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array('deleted_at')
);

$logoColumn = tenantPageFirstColumn(
    $conn,
    'tenants',
    array(
        'logo_path',
        'logo',
        'company_logo'
    )
);

if ($idColumn === '' || $nameColumn === '') {
    http_response_code(500);

    exit(
        'The tenants table requires an ID and tenant name column.'
    );
}

/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search']) &&
    !is_array($_GET['search'])
        ? trim((string) $_GET['search'])
        : '';

$statusFilter = isset($_GET['status']) &&
    !is_array($_GET['status'])
        ? strtolower(trim((string) $_GET['status']))
        : '';

$sort = isset($_GET['sort']) &&
    !is_array($_GET['sort'])
        ? trim((string) $_GET['sort'])
        : 'latest';

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = isset($_GET['per_page'])
    ? (int) $_GET['per_page']
    : 15;

$allowedPerPage = array(10, 15, 25, 50);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 15;
}

$allowedStatuses = array(
    '',
    'active',
    'trial',
    'pending',
    'pending_approval',
    'suspended',
    'inactive',
    'expired',
    'cancelled'
);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$allowedSorts = array(
    'latest',
    'oldest',
    'name_asc',
    'name_desc'
);

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

/*
|--------------------------------------------------------------------------
| Summary counts
|--------------------------------------------------------------------------
*/

$summary = array(
    'total' => 0,
    'active' => 0,
    'trial' => 0,
    'pending' => 0,
    'suspended' => 0
);

$summaryWhere = $deletedColumn !== ''
    ? "WHERE `{$deletedColumn}` IS NULL"
    : '';

$summarySelect = array(
    'COUNT(*) AS total'
);

if ($statusColumn !== '') {
    $summarySelect[] = "
        SUM(
            CASE
                WHEN `{$statusColumn}` = 'active'
                THEN 1 ELSE 0
            END
        ) AS active_count
    ";

    $summarySelect[] = "
        SUM(
            CASE
                WHEN `{$statusColumn}` = 'trial'
                THEN 1 ELSE 0
            END
        ) AS trial_count
    ";

    $summarySelect[] = "
        SUM(
            CASE
                WHEN `{$statusColumn}` IN (
                    'pending',
                    'pending_approval'
                )
                THEN 1 ELSE 0
            END
        ) AS pending_count
    ";

    $summarySelect[] = "
        SUM(
            CASE
                WHEN `{$statusColumn}` = 'suspended'
                THEN 1 ELSE 0
            END
        ) AS suspended_count
    ";
}

$summarySql = "
    SELECT " . implode(',', $summarySelect) . "
    FROM tenants
    {$summaryWhere}
";

$summaryResult = $conn->query($summarySql);

if ($summaryResult) {
    $summaryRow = $summaryResult->fetch_assoc();

    $summary['total'] = isset($summaryRow['total'])
        ? (int) $summaryRow['total']
        : 0;

    $summary['active'] = isset($summaryRow['active_count'])
        ? (int) $summaryRow['active_count']
        : 0;

    $summary['trial'] = isset($summaryRow['trial_count'])
        ? (int) $summaryRow['trial_count']
        : 0;

    $summary['pending'] = isset($summaryRow['pending_count'])
        ? (int) $summaryRow['pending_count']
        : 0;

    $summary['suspended'] = isset($summaryRow['suspended_count'])
        ? (int) $summaryRow['suspended_count']
        : 0;

    $summaryResult->free();
}

/*
|--------------------------------------------------------------------------
| Build filters
|--------------------------------------------------------------------------
*/

$where = array();
$params = array();
$types = '';

if ($deletedColumn !== '') {
    $where[] = "`{$deletedColumn}` IS NULL";
}

if ($statusFilter !== '' && $statusColumn !== '') {
    if ($statusFilter === 'pending') {
        $where[] = "`{$statusColumn}` IN (
            'pending',
            'pending_approval'
        )";
    } else {
        $where[] = "`{$statusColumn}` = ?";
        $types .= 's';
        $params[] = $statusFilter;
    }
}

if ($search !== '') {
    $searchConditions = array();

    $searchableColumns = array_filter(array(
        $nameColumn,
        $emailColumn,
        $phoneColumn,
        $codeColumn
    ));

    foreach ($searchableColumns as $searchableColumn) {
        $searchConditions[] =
            "`{$searchableColumn}` LIKE ?";
        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    if (!empty($searchConditions)) {
        $where[] =
            '(' .
            implode(' OR ', $searchConditions) .
            ')';
    }
}

$whereSql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| Count filtered records
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM tenants
    {$whereSql}
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    exit(
        'Unable to prepare tenant count: ' .
        tenantPageEscape($conn->error)
    );
}

if ($types !== '') {
    $bindValues = array($types);

    foreach ($params as $key => $value) {
        $bindValues[] = &$params[$key];
    }

    call_user_func_array(
        array($countStmt, 'bind_param'),
        $bindValues
    );
}

$countStmt->execute();

$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();

$totalRecords = isset($countRow['total'])
    ? (int) $countRow['total']
    : 0;

$countStmt->close();

$totalPages = max(
    1,
    (int) ceil($totalRecords / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Sorting
|--------------------------------------------------------------------------
*/

switch ($sort) {
    case 'oldest':
        $orderColumn = $createdColumn !== ''
            ? $createdColumn
            : $idColumn;

        $orderSql =
            "ORDER BY `{$orderColumn}` ASC";
        break;

    case 'name_asc':
        $orderSql =
            "ORDER BY `{$nameColumn}` ASC";
        break;

    case 'name_desc':
        $orderSql =
            "ORDER BY `{$nameColumn}` DESC";
        break;

    case 'latest':
    default:
        $orderColumn = $createdColumn !== ''
            ? $createdColumn
            : $idColumn;

        $orderSql =
            "ORDER BY `{$orderColumn}` DESC";
        break;
}

/*
|--------------------------------------------------------------------------
| Select tenant data
|--------------------------------------------------------------------------
*/

$select = array(
    "`{$idColumn}` AS tenant_id",
    "`{$nameColumn}` AS tenant_name"
);

$select[] = $emailColumn !== ''
    ? "`{$emailColumn}` AS tenant_email"
    : "'' AS tenant_email";

$select[] = $phoneColumn !== ''
    ? "`{$phoneColumn}` AS tenant_phone"
    : "'' AS tenant_phone";

$select[] = $codeColumn !== ''
    ? "`{$codeColumn}` AS tenant_code"
    : "'' AS tenant_code";

$select[] = $statusColumn !== ''
    ? "`{$statusColumn}` AS tenant_status"
    : "'active' AS tenant_status";

$select[] = $createdColumn !== ''
    ? "`{$createdColumn}` AS tenant_created_at"
    : "NULL AS tenant_created_at";

$select[] = $trialEndColumn !== ''
    ? "`{$trialEndColumn}` AS trial_ends_at"
    : "NULL AS trial_ends_at";

$select[] = $logoColumn !== ''
    ? "`{$logoColumn}` AS tenant_logo"
    : "'' AS tenant_logo";

$listSql = "
    SELECT
        " . implode(',', $select) . "
    FROM tenants
    {$whereSql}
    {$orderSql}
    LIMIT ? OFFSET ?
";

$listStmt = $conn->prepare($listSql);

if (!$listStmt) {
    exit(
        'Unable to prepare tenant list: ' .
        tenantPageEscape($conn->error)
    );
}

$listTypes = $types . 'ii';
$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$bindValues = array($listTypes);

foreach ($listParams as $key => $value) {
    $bindValues[] = &$listParams[$key];
}

call_user_func_array(
    array($listStmt, 'bind_param'),
    $bindValues
);

$listStmt->execute();

$listResult = $listStmt->get_result();
$tenants = array();

while ($row = $listResult->fetch_assoc()) {
    $tenants[] = $row;
}

$listStmt->close();

/*
|--------------------------------------------------------------------------
| Pagination range
|--------------------------------------------------------------------------
*/

$startRecord = $totalRecords > 0
    ? $offset + 1
    : 0;

$endRecord = min(
    $offset + $perPage,
    $totalRecords
);

$paginationStart = max(1, $page - 2);
$paginationEnd = min(
    $totalPages,
    $page + 2
);

/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
*/

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .tenant-page {
        display: grid;
        gap: 15px;
    }

    .tenant-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .tenant-page-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-page-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .tenant-add-button {
        min-height: 38px;
        padding: 8px 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border-radius: 9px;
        background: #7c3aed;
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
        text-decoration: none;
    }

    .tenant-add-button:hover {
        background: #6d28d9;
        color: #ffffff;
    }

    .tenant-summary-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
    }

    .tenant-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
        text-decoration: none;
        box-shadow: 0 4px 15px rgba(31, 41, 55, 0.03);
    }

    .tenant-summary-card:hover {
        border-color: #ddd6fe;
        background: #fcfbff;
    }

    .tenant-summary-card.selected {
        border-color: #c4b5fd;
        background: #faf8ff;
    }

    .tenant-summary-icon {
        width: 35px;
        height: 35px;
        flex: 0 0 35px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 14px;
    }

    .tenant-summary-icon.total {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .tenant-summary-icon.active {
        background: #ecfdf5;
        color: #059669;
    }

    .tenant-summary-icon.trial {
        background: #eff6ff;
        color: #2563eb;
    }

    .tenant-summary-icon.pending {
        background: #fff7ed;
        color: #d97706;
    }

    .tenant-summary-icon.suspended {
        background: #fef2f2;
        color: #dc2626;
    }

    .tenant-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .tenant-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .tenant-list-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-toolbar {
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-bottom: 1px solid #eef0f3;
    }

    .tenant-search {
        min-width: 220px;
        position: relative;
        flex: 1;
    }

    .tenant-search i {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 12px;
    }

    .tenant-control {
        height: 36px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .tenant-search .tenant-control {
        padding-left: 33px;
    }

    .tenant-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .tenant-filter-button {
        height: 36px;
        padding: 7px 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 0;
        border-radius: 8px;
        background: #111827;
        color: #ffffff;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-clear-button {
        height: 36px;
        padding: 7px 11px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #6b7280;
        font-size: 9px;
        text-decoration: none;
    }

    .tenant-table-wrap {
        overflow-x: auto;
    }

    .tenant-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .tenant-table th {
        padding: 10px 13px;
        border-bottom: 1px solid #e9ebef;
        background: #fafafa;
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .tenant-table td {
        padding: 11px 13px;
        border-bottom: 1px solid #f0f1f3;
        color: #374151;
        font-size: 9px;
        vertical-align: middle;
    }

    .tenant-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .tenant-table tbody tr:hover {
        background: #fcfbff;
    }

    .tenant-business {
        min-width: 210px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .tenant-avatar {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: linear-gradient(135deg, #111827, #7c3aed);
        color: #ffffff;
        font-size: 9px;
        font-weight: 800;
    }

    .tenant-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .tenant-name {
        overflow: hidden;
        display: block;
        max-width: 230px;
        color: #111827;
        font-size: 10px;
        font-weight: 700;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .tenant-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-contact {
        min-width: 175px;
    }

    .tenant-contact-line {
        display: block;
        overflow: hidden;
        max-width: 210px;
        color: #4b5563;
        font-size: 9px;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .tenant-contact-line + .tenant-contact-line {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-status {
        padding: 4px 8px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .tenant-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .tenant-status.info {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .tenant-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .tenant-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .tenant-actions {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .tenant-action-button {
        width: 29px;
        height: 29px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        background: #ffffff;
        color: #6b7280;
        font-size: 11px;
        text-decoration: none;
    }

    .tenant-action-button:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
        color: #7c3aed;
    }

    .tenant-empty {
        padding: 48px 20px;
        color: #9ca3af;
        text-align: center;
        font-size: 10px;
    }

    .tenant-empty i {
        margin-bottom: 10px;
        display: block;
        color: #c4b5fd;
        font-size: 30px;
    }

    .tenant-pagination-bar {
        min-height: 54px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #eef0f3;
    }

    .tenant-pagination-info {
        color: #6b7280;
        font-size: 9px;
    }

    .tenant-pagination {
        margin: 0;
        display: flex;
        gap: 4px;
        list-style: none;
    }

    .tenant-pagination a,
    .tenant-pagination span {
        min-width: 29px;
        height: 29px;
        padding: 0 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        background: #ffffff;
        color: #6b7280;
        font-size: 8px;
        text-decoration: none;
    }

    .tenant-pagination a:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-pagination .active {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .tenant-pagination .disabled {
        opacity: 0.45;
        pointer-events: none;
    }

    @media (max-width: 1199.98px) {
        .tenant-summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 767.98px) {
        .tenant-page-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .tenant-add-button {
            width: 100%;
        }

        .tenant-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .tenant-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .tenant-search {
            min-width: 0;
        }

        .tenant-filter-button,
        .tenant-clear-button {
            width: 100%;
        }

        .tenant-pagination-bar {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    @media (max-width: 420px) {
        .tenant-summary-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="tenant-page">

    <div class="tenant-page-header">
        <div>
            <h2 class="tenant-page-title">
                Tenant Management
            </h2>

            <div class="tenant-page-description">
                Manage FieldPlx tenant workspaces and account access.
            </div>
        </div>

        <?php if (canManagePlatformTenants()): ?>
            <a
                href="tenant-add.php"
                class="tenant-add-button"
            >
                <i class="bi bi-building-add"></i>
                Add Tenant
            </a>
        <?php endif; ?>
    </div>

    <div class="tenant-summary-grid">

        <a
            href="tenants.php"
            class="tenant-summary-card <?= $statusFilter === ''
                ? 'selected'
                : ''; ?>"
        >
            <span class="tenant-summary-icon total">
                <i class="bi bi-buildings"></i>
            </span>

            <span>
                <span class="tenant-summary-label">
                    Total
                </span>

                <span class="tenant-summary-value">
                    <?= number_format($summary['total']); ?>
                </span>
            </span>
        </a>

        <a
            href="tenants.php?status=active"
            class="tenant-summary-card <?= $statusFilter === 'active'
                ? 'selected'
                : ''; ?>"
        >
            <span class="tenant-summary-icon active">
                <i class="bi bi-check-circle"></i>
            </span>

            <span>
                <span class="tenant-summary-label">
                    Active
                </span>

                <span class="tenant-summary-value">
                    <?= number_format($summary['active']); ?>
                </span>
            </span>
        </a>

        <a
            href="tenants.php?status=trial"
            class="tenant-summary-card <?= $statusFilter === 'trial'
                ? 'selected'
                : ''; ?>"
        >
            <span class="tenant-summary-icon trial">
                <i class="bi bi-clock-history"></i>
            </span>

            <span>
                <span class="tenant-summary-label">
                    Trial
                </span>

                <span class="tenant-summary-value">
                    <?= number_format($summary['trial']); ?>
                </span>
            </span>
        </a>

        <a
            href="tenants.php?status=pending"
            class="tenant-summary-card <?= in_array(
                $statusFilter,
                array('pending', 'pending_approval'),
                true
            )
                ? 'selected'
                : ''; ?>"
        >
            <span class="tenant-summary-icon pending">
                <i class="bi bi-hourglass-split"></i>
            </span>

            <span>
                <span class="tenant-summary-label">
                    Pending
                </span>

                <span class="tenant-summary-value">
                    <?= number_format($summary['pending']); ?>
                </span>
            </span>
        </a>

        <a
            href="tenants.php?status=suspended"
            class="tenant-summary-card <?= $statusFilter === 'suspended'
                ? 'selected'
                : ''; ?>"
        >
            <span class="tenant-summary-icon suspended">
                <i class="bi bi-slash-circle"></i>
            </span>

            <span>
                <span class="tenant-summary-label">
                    Suspended
                </span>

                <span class="tenant-summary-value">
                    <?= number_format($summary['suspended']); ?>
                </span>
            </span>
        </a>

    </div>

    <div class="tenant-list-card">

        <form
            method="get"
            class="tenant-toolbar"
            id="tenantFilterForm"
        >
            <div class="tenant-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="search"
                    class="form-control tenant-control"
                    value="<?= tenantPageEscape($search); ?>"
                    placeholder="Search tenant, email, phone or code..."
                    autocomplete="off"
                >
            </div>

            <?php if ($statusColumn !== ''): ?>
                <select
                    name="status"
                    class="form-select tenant-control"
                    style="width:150px;"
                >
                    <option value="">All statuses</option>

                    <option
                        value="active"
                        <?= $statusFilter === 'active'
                            ? 'selected'
                            : ''; ?>
                    >
                        Active
                    </option>

                    <option
                        value="trial"
                        <?= $statusFilter === 'trial'
                            ? 'selected'
                            : ''; ?>
                    >
                        Trial
                    </option>

                    <option
                        value="pending"
                        <?= in_array(
                            $statusFilter,
                            array(
                                'pending',
                                'pending_approval'
                            ),
                            true
                        )
                            ? 'selected'
                            : ''; ?>
                    >
                        Pending
                    </option>

                    <option
                        value="suspended"
                        <?= $statusFilter === 'suspended'
                            ? 'selected'
                            : ''; ?>
                    >
                        Suspended
                    </option>

                    <option
                        value="inactive"
                        <?= $statusFilter === 'inactive'
                            ? 'selected'
                            : ''; ?>
                    >
                        Inactive
                    </option>
                </select>
            <?php endif; ?>

            <select
                name="sort"
                class="form-select tenant-control"
                style="width:145px;"
            >
                <option
                    value="latest"
                    <?= $sort === 'latest'
                        ? 'selected'
                        : ''; ?>
                >
                    Latest first
                </option>

                <option
                    value="oldest"
                    <?= $sort === 'oldest'
                        ? 'selected'
                        : ''; ?>
                >
                    Oldest first
                </option>

                <option
                    value="name_asc"
                    <?= $sort === 'name_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Name A-Z
                </option>

                <option
                    value="name_desc"
                    <?= $sort === 'name_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Name Z-A
                </option>
            </select>

            <select
                name="per_page"
                class="form-select tenant-control"
                style="width:95px;"
            >
                <?php foreach ($allowedPerPage as $size): ?>
                    <option
                        value="<?= (int) $size; ?>"
                        <?= $perPage === $size
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= (int) $size; ?> rows
                    </option>
                <?php endforeach; ?>
            </select>

            <button
                type="submit"
                class="tenant-filter-button"
            >
                <i class="bi bi-funnel"></i>
                Apply
            </button>

            <?php if (
                $search !== '' ||
                $statusFilter !== '' ||
                $sort !== 'latest' ||
                $perPage !== 15
            ): ?>
                <a
                    href="tenants.php"
                    class="tenant-clear-button"
                    title="Clear filters"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($tenants)): ?>
            <div class="tenant-empty">
                <i class="bi bi-buildings"></i>

                No tenant records matched your filters.
            </div>
        <?php else: ?>

            <div class="tenant-table-wrap">
                <table class="tenant-table">
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Contact</th>
                            <th>Status</th>

                            <?php if ($trialEndColumn !== ''): ?>
                                <th>Trial Ends</th>
                            <?php endif; ?>

                            <th>Created</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($tenants as $tenant): ?>
                            <?php
                            $tenantName = trim(
                                (string) $tenant['tenant_name']
                            );

                            $tenantStatus = strtolower(
                                trim(
                                    (string)
                                    $tenant['tenant_status']
                                )
                            );

                            $tenantLogo = trim(
                                (string) $tenant['tenant_logo']
                            );
                            ?>

                            <tr>
                                <td>
                                    <div class="tenant-business">
                                        <span class="tenant-avatar">
                                            <?php if ($tenantLogo !== ''): ?>
                                                <img
                                                    src="../<?= tenantPageEscape(
                                                        ltrim(
                                                            $tenantLogo,
                                                            '/'
                                                        )
                                                    ); ?>"
                                                    alt=""
                                                >
                                            <?php else: ?>
                                                <?= tenantPageEscape(
                                                    tenantPageInitials(
                                                        $tenantName
                                                    )
                                                ); ?>
                                            <?php endif; ?>
                                        </span>

                                        <span style="min-width:0;">
                                            <span class="tenant-name">
                                                <?= tenantPageEscape(
                                                    $tenantName !== ''
                                                        ? $tenantName
                                                        : 'Unnamed Tenant'
                                                ); ?>
                                            </span>

                                            <span class="tenant-code">
                                                <?php if (
                                                    !empty(
                                                        $tenant[
                                                            'tenant_code'
                                                        ]
                                                    )
                                                ): ?>
                                                    <?= tenantPageEscape(
                                                        $tenant[
                                                            'tenant_code'
                                                        ]
                                                    ); ?>
                                                <?php else: ?>
                                                    Tenant ID:
                                                    <?= (int)
                                                        $tenant[
                                                            'tenant_id'
                                                        ]; ?>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="tenant-contact">
                                        <span class="tenant-contact-line">
                                            <?= !empty(
                                                $tenant['tenant_email']
                                            )
                                                ? tenantPageEscape(
                                                    $tenant[
                                                        'tenant_email'
                                                    ]
                                                )
                                                : '—'; ?>
                                        </span>

                                        <span class="tenant-contact-line">
                                            <?= !empty(
                                                $tenant['tenant_phone']
                                            )
                                                ? tenantPageEscape(
                                                    $tenant[
                                                        'tenant_phone'
                                                    ]
                                                )
                                                : 'No phone number'; ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <span
                                        class="tenant-status <?= tenantPageEscape(
                                            tenantPageStatusClass(
                                                $tenantStatus
                                            )
                                        ); ?>"
                                    >
                                        <?= tenantPageEscape(
                                            tenantPageStatusLabel(
                                                $tenantStatus
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <?php if ($trialEndColumn !== ''): ?>
                                    <td>
                                        <?= tenantPageEscape(
                                            tenantPageDate(
                                                $tenant[
                                                    'trial_ends_at'
                                                ]
                                            )
                                        ); ?>
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <?= tenantPageEscape(
                                        tenantPageDate(
                                            $tenant[
                                                'tenant_created_at'
                                            ]
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <div class="tenant-actions">
                                        <a
                                            href="tenant-view.php?id=<?= (int)
                                                $tenant[
                                                    'tenant_id'
                                                ]; ?>"
                                            class="tenant-action-button"
                                            title="View tenant"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <?php if (
                                            canManagePlatformTenants()
                                        ): ?>
                                            <a
                                                href="tenant-edit.php?id=<?= (int)
                                                    $tenant[
                                                        'tenant_id'
                                                    ]; ?>"
                                                class="tenant-action-button"
                                                title="Edit tenant"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if (
                                            canProvidePlatformSupport()
                                        ): ?>
                                            <a
                                                href="support-access.php?tenant_id=<?= (int)
                                                    $tenant[
                                                        'tenant_id'
                                                    ]; ?>"
                                                class="tenant-action-button"
                                                title="Support access"
                                            >
                                                <i class="bi bi-headset"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <div class="tenant-pagination-bar">
            <div class="tenant-pagination-info">
                Showing
                <?= number_format($startRecord); ?>
                to
                <?= number_format($endRecord); ?>
                of
                <?= number_format($totalRecords); ?>
                tenants
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Tenant pagination">
                    <ul class="tenant-pagination">
                        <li>
                            <?php if ($page > 1): ?>
                                <a
                                    href="?<?= tenantPageEscape(
                                        tenantPageBuildQuery(
                                            array(
                                                'page' =>
                                                    $page - 1
                                            )
                                        )
                                    ); ?>"
                                    aria-label="Previous"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    <i class="bi bi-chevron-left"></i>
                                </span>
                            <?php endif; ?>
                        </li>

                        <?php if ($paginationStart > 1): ?>
                            <li>
                                <a
                                    href="?<?= tenantPageEscape(
                                        tenantPageBuildQuery(
                                            array('page' => 1)
                                        )
                                    ); ?>"
                                >
                                    1
                                </a>
                            </li>

                            <?php if ($paginationStart > 2): ?>
                                <li>
                                    <span>…</span>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for (
                            $pageNumber = $paginationStart;
                            $pageNumber <= $paginationEnd;
                            $pageNumber++
                        ): ?>
                            <li>
                                <?php if ($pageNumber === $page): ?>
                                    <span class="active">
                                        <?= (int) $pageNumber; ?>
                                    </span>
                                <?php else: ?>
                                    <a
                                        href="?<?= tenantPageEscape(
                                            tenantPageBuildQuery(
                                                array(
                                                    'page' =>
                                                        $pageNumber
                                                )
                                            )
                                        ); ?>"
                                    >
                                        <?= (int) $pageNumber; ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endfor; ?>

                        <?php if (
                            $paginationEnd < $totalPages
                        ): ?>
                            <?php if (
                                $paginationEnd <
                                $totalPages - 1
                            ): ?>
                                <li>
                                    <span>…</span>
                                </li>
                            <?php endif; ?>

                            <li>
                                <a
                                    href="?<?= tenantPageEscape(
                                        tenantPageBuildQuery(
                                            array(
                                                'page' =>
                                                    $totalPages
                                            )
                                        )
                                    ); ?>"
                                >
                                    <?= (int) $totalPages; ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li>
                            <?php if ($page < $totalPages): ?>
                                <a
                                    href="?<?= tenantPageEscape(
                                        tenantPageBuildQuery(
                                            array(
                                                'page' =>
                                                    $page + 1
                                            )
                                        )
                                    ); ?>"
                                    aria-label="Next"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    <i class="bi bi-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
(function () {
    'use strict';

    const form = document.getElementById(
        'tenantFilterForm'
    );

    if (!form) {
        return;
    }

    const selects = form.querySelectorAll(
        'select'
    );

    selects.forEach(function (select) {
        select.addEventListener(
            'change',
            function () {
                form.submit();
            }
        );
    });

    const searchInput = form.querySelector(
        'input[name="search"]'
    );

    let searchTimer = null;

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            function () {
                window.clearTimeout(searchTimer);

                searchTimer = window.setTimeout(
                    function () {
                        form.submit();
                    },
                    600
                );
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>