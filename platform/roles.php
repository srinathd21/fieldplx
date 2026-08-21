<?php
/**
 * FieldPlx Platform - Tenant Roles
 *
 * File:
 * platform/roles.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - platform_users authentication
 */

require_once __DIR__ . '/includes/auth.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'platform_read_only'
));

$pageTitle = 'Tenant Roles - FieldPlx';
$activePage = 'roles';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('platformRolesEscape')) {
    function platformRolesEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('platformRolesTableExists')) {
    function platformRolesTableExists(mysqli $conn, $tableName)
    {
        static $cache = array();

        $tableName = trim((string) $tableName);

        if ($tableName === '') {
            return false;
        }

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        if (!$stmt) {
            $cache[$tableName] = false;
            return false;
        }

        $stmt->bind_param('s', $tableName);

        if (!$stmt->execute()) {
            $stmt->close();
            $cache[$tableName] = false;
            return false;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('platformRolesColumns')) {
    function platformRolesColumns(mysqli $conn, $tableName)
    {
        static $cache = array();

        $tableName = trim((string) $tableName);

        if ($tableName === '') {
            return array();
        }

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTableName = str_replace('`', '``', $tableName);

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTableName}`"
        );

        if (!$result) {
            return $cache[$tableName];
        }

        while ($row = $result->fetch_assoc()) {
            if (!empty($row['Field'])) {
                $cache[$tableName][
                    (string) $row['Field']
                ] = $row;
            }
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('platformRolesFirstColumn')) {
    function platformRolesFirstColumn(
        array $columns,
        array $candidates
    ) {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('platformRolesBind')) {
    function platformRolesBind(
        mysqli_stmt $stmt,
        $types,
        array &$values
    ) {
        if ($types === '') {
            return true;
        }

        $arguments = array($types);

        foreach ($values as $key => $value) {
            $arguments[] = &$values[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('platformRolesLabel')) {
    function platformRolesLabel($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '—';
        }

        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                $value
            )
        );
    }
}

if (!function_exists('platformRolesStatusClass')) {
    function platformRolesStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
                return 'success';

            case 'inactive':
            case 'disabled':
            case 'suspended':
                return 'danger';

            case 'draft':
            case 'pending':
                return 'warning';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('platformRolesDate')) {
    function platformRolesDate($value)
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

if (!function_exists('platformRolesBuildQuery')) {
    function platformRolesBuildQuery(array $changes = array())
    {
        $query = $_GET;

        foreach ($changes as $key => $value) {
            if ($value === '' || $value === null) {
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
| Verify tables
|--------------------------------------------------------------------------
*/

if (!platformRolesTableExists($conn, 'roles')) {
    http_response_code(500);
    exit('The roles table does not exist.');
}

if (!platformRolesTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$roleColumns = platformRolesColumns($conn, 'roles');
$tenantColumns = platformRolesColumns($conn, 'tenants');

/*
|--------------------------------------------------------------------------
| Detect role columns
|--------------------------------------------------------------------------
*/

$roleIdColumn = platformRolesFirstColumn(
    $roleColumns,
    array('id', 'role_id')
);

$roleTenantColumn = platformRolesFirstColumn(
    $roleColumns,
    array('tenant_id')
);

$roleNameColumn = platformRolesFirstColumn(
    $roleColumns,
    array('name', 'role_name')
);

$roleCodeColumn = platformRolesFirstColumn(
    $roleColumns,
    array('code', 'role_code')
);

$roleDescriptionColumn = platformRolesFirstColumn(
    $roleColumns,
    array('description', 'notes', 'remarks')
);

$roleStatusColumn = platformRolesFirstColumn(
    $roleColumns,
    array('status')
);

$roleSystemColumn = platformRolesFirstColumn(
    $roleColumns,
    array(
        'is_system',
        'system_role',
        'is_default'
    )
);

$roleCreatedColumn = platformRolesFirstColumn(
    $roleColumns,
    array('created_at', 'created_on')
);

$roleUpdatedColumn = platformRolesFirstColumn(
    $roleColumns,
    array('updated_at', 'updated_on')
);

$roleDeletedColumn = platformRolesFirstColumn(
    $roleColumns,
    array('deleted_at')
);

if (
    $roleIdColumn === '' ||
    $roleNameColumn === ''
) {
    http_response_code(500);
    exit('The roles table requires id and name columns.');
}

/*
|--------------------------------------------------------------------------
| Detect tenant columns
|--------------------------------------------------------------------------
*/

$tenantIdColumn = platformRolesFirstColumn(
    $tenantColumns,
    array('id', 'tenant_id')
);

$tenantNameColumn = platformRolesFirstColumn(
    $tenantColumns,
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$tenantCodeColumn = platformRolesFirstColumn(
    $tenantColumns,
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$tenantDeletedColumn = platformRolesFirstColumn(
    $tenantColumns,
    array('deleted_at')
);

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);
    exit('The tenants table requires id and name columns.');
}

/*
|--------------------------------------------------------------------------
| Detect users table for assigned user counts
|--------------------------------------------------------------------------
*/

$hasUsersTable = platformRolesTableExists($conn, 'users');

$userRoleColumn = '';
$userTenantColumn = '';
$userDeletedColumn = '';

if ($hasUsersTable) {
    $userColumns = platformRolesColumns($conn, 'users');

    $userRoleColumn = platformRolesFirstColumn(
        $userColumns,
        array('role_id')
    );

    $userTenantColumn = platformRolesFirstColumn(
        $userColumns,
        array('tenant_id')
    );

    $userDeletedColumn = platformRolesFirstColumn(
        $userColumns,
        array('deleted_at')
    );
}

/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$tenantId = isset($_GET['tenant_id'])
    ? max(0, (int) $_GET['tenant_id'])
    : 0;

$search = isset($_GET['search']) &&
    !is_array($_GET['search'])
        ? trim((string) $_GET['search'])
        : '';

$status = isset($_GET['status']) &&
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
| Load tenant list
|--------------------------------------------------------------------------
*/

$tenantList = array();

$tenantListSql = "
    SELECT
        `{$tenantIdColumn}` AS tenant_id,
        `{$tenantNameColumn}` AS tenant_name
";

if ($tenantCodeColumn !== '') {
    $tenantListSql .= ",
        `{$tenantCodeColumn}` AS tenant_code
    ";
} else {
    $tenantListSql .= ",
        '' AS tenant_code
    ";
}

$tenantListSql .= "
    FROM tenants
";

if ($tenantDeletedColumn !== '') {
    $tenantListSql .= "
        WHERE `{$tenantDeletedColumn}` IS NULL
    ";
}

$tenantListSql .= "
    ORDER BY `{$tenantNameColumn}` ASC
";

$tenantListResult = $conn->query($tenantListSql);

if ($tenantListResult) {
    while ($tenantRow = $tenantListResult->fetch_assoc()) {
        $tenantList[] = $tenantRow;
    }

    $tenantListResult->free();
}

$selectedTenant = null;

if ($tenantId > 0) {
    foreach ($tenantList as $tenantRow) {
        if ((int) $tenantRow['tenant_id'] === $tenantId) {
            $selectedTenant = $tenantRow;
            break;
        }
    }

    if (!$selectedTenant) {
        $_SESSION['platform_error_message'] =
            'The selected tenant could not be found.';

        header('Location: roles.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summaryWhere = array();

if ($roleDeletedColumn !== '') {
    $summaryWhere[] =
        "`{$roleDeletedColumn}` IS NULL";
}

if ($tenantId > 0 && $roleTenantColumn !== '') {
    $summaryWhere[] =
        "`{$roleTenantColumn}` = " . (int) $tenantId;
}

$summarySql = "
    SELECT
        COUNT(*) AS total
";

if ($roleStatusColumn !== '') {
    $summarySql .= ",
        SUM(
            CASE
                WHEN `{$roleStatusColumn}` = 'active'
                THEN 1 ELSE 0
            END
        ) AS active_count,
        SUM(
            CASE
                WHEN `{$roleStatusColumn}` IN (
                    'inactive',
                    'disabled',
                    'suspended'
                )
                THEN 1 ELSE 0
            END
        ) AS inactive_count
    ";
}

if ($roleSystemColumn !== '') {
    $summarySql .= ",
        SUM(
            CASE
                WHEN `{$roleSystemColumn}` = 1
                THEN 1 ELSE 0
            END
        ) AS system_count
    ";
}

$summarySql .= "
    FROM roles
";

if (!empty($summaryWhere)) {
    $summarySql .= "
        WHERE " . implode(' AND ', $summaryWhere);
}

$summary = array(
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'system' => 0
);

$summaryResult = $conn->query($summarySql);

if ($summaryResult) {
    $summaryRow = $summaryResult->fetch_assoc();

    $summary['total'] = isset($summaryRow['total'])
        ? (int) $summaryRow['total']
        : 0;

    $summary['active'] = isset(
        $summaryRow['active_count']
    )
        ? (int) $summaryRow['active_count']
        : 0;

    $summary['inactive'] = isset(
        $summaryRow['inactive_count']
    )
        ? (int) $summaryRow['inactive_count']
        : 0;

    $summary['system'] = isset(
        $summaryRow['system_count']
    )
        ? (int) $summaryRow['system_count']
        : 0;

    $summaryResult->free();
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$where = array();
$params = array();
$types = '';

if ($roleDeletedColumn !== '') {
    $where[] =
        "r.`{$roleDeletedColumn}` IS NULL";
}

if ($tenantDeletedColumn !== '') {
    $where[] =
        "t.`{$tenantDeletedColumn}` IS NULL";
}

if ($tenantId > 0 && $roleTenantColumn !== '') {
    $where[] =
        "r.`{$roleTenantColumn}` = ?";

    $types .= 'i';
    $params[] = $tenantId;
}

if ($status !== '' && $roleStatusColumn !== '') {
    if ($status === 'inactive') {
        $where[] =
            "r.`{$roleStatusColumn}` IN (
                'inactive',
                'disabled',
                'suspended'
            )";
    } else {
        $where[] =
            "r.`{$roleStatusColumn}` = ?";

        $types .= 's';
        $params[] = $status;
    }
}

if ($search !== '') {
    $searchConditions = array();

    $searchConditions[] =
        "r.`{$roleNameColumn}` LIKE ?";

    $types .= 's';
    $params[] = '%' . $search . '%';

    if ($roleCodeColumn !== '') {
        $searchConditions[] =
            "r.`{$roleCodeColumn}` LIKE ?";

        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    if ($roleDescriptionColumn !== '') {
        $searchConditions[] =
            "r.`{$roleDescriptionColumn}` LIKE ?";

        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    $searchConditions[] =
        "t.`{$tenantNameColumn}` LIKE ?";

    $types .= 's';
    $params[] = '%' . $search . '%';

    if ($tenantCodeColumn !== '') {
        $searchConditions[] =
            "t.`{$tenantCodeColumn}` LIKE ?";

        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    $where[] =
        '(' . implode(' OR ', $searchConditions) . ')';
}

$whereSql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM roles r
";

if ($roleTenantColumn !== '') {
    $countSql .= "
        INNER JOIN tenants t
            ON t.`{$tenantIdColumn}` =
               r.`{$roleTenantColumn}`
    ";
} else {
    $countSql .= "
        CROSS JOIN tenants t
    ";
}

$countSql .= "
    {$whereSql}
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    http_response_code(500);

    exit(
        'Unable to prepare role count: ' .
        platformRolesEscape($conn->error)
    );
}

platformRolesBind(
    $countStmt,
    $types,
    $params
);

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
        $sortColumn = $roleCreatedColumn !== ''
            ? "r.`{$roleCreatedColumn}`"
            : "r.`{$roleIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} ASC";
        break;

    case 'name_asc':
        $orderSql =
            "ORDER BY r.`{$roleNameColumn}` ASC";
        break;

    case 'name_desc':
        $orderSql =
            "ORDER BY r.`{$roleNameColumn}` DESC";
        break;

    case 'latest':
    default:
        $sortColumn = $roleCreatedColumn !== ''
            ? "r.`{$roleCreatedColumn}`"
            : "r.`{$roleIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} DESC";
        break;
}

/*
|--------------------------------------------------------------------------
| Select roles
|--------------------------------------------------------------------------
*/

$select = array(
    "r.`{$roleIdColumn}` AS role_id",
    "r.`{$roleNameColumn}` AS role_name"
);

$select[] = $roleCodeColumn !== ''
    ? "r.`{$roleCodeColumn}` AS role_code"
    : "'' AS role_code";

$select[] = $roleDescriptionColumn !== ''
    ? "r.`{$roleDescriptionColumn}` AS role_description"
    : "'' AS role_description";

$select[] = $roleStatusColumn !== ''
    ? "r.`{$roleStatusColumn}` AS role_status"
    : "'active' AS role_status";

$select[] = $roleSystemColumn !== ''
    ? "r.`{$roleSystemColumn}` AS is_system"
    : "0 AS is_system";

$select[] = $roleCreatedColumn !== ''
    ? "r.`{$roleCreatedColumn}` AS created_at"
    : "NULL AS created_at";

$select[] = $roleUpdatedColumn !== ''
    ? "r.`{$roleUpdatedColumn}` AS updated_at"
    : "NULL AS updated_at";

if ($roleTenantColumn !== '') {
    $select[] =
        "r.`{$roleTenantColumn}` AS tenant_id";

    $select[] =
        "t.`{$tenantNameColumn}` AS tenant_name";

    $select[] = $tenantCodeColumn !== ''
        ? "t.`{$tenantCodeColumn}` AS tenant_code"
        : "'' AS tenant_code";
} else {
    $select[] = "0 AS tenant_id";
    $select[] = "'Global Role' AS tenant_name";
    $select[] = "'' AS tenant_code";
}

$userJoinSql = '';

if (
    $hasUsersTable &&
    $userRoleColumn !== ''
) {
    $userJoinSql = "
        LEFT JOIN users u
            ON u.`{$userRoleColumn}` =
               r.`{$roleIdColumn}`
    ";

    if (
        $userTenantColumn !== '' &&
        $roleTenantColumn !== ''
    ) {
        $userJoinSql .= "
            AND u.`{$userTenantColumn}` =
                r.`{$roleTenantColumn}`
        ";
    }

    if ($userDeletedColumn !== '') {
        $userJoinSql .= "
            AND u.`{$userDeletedColumn}` IS NULL
        ";
    }

    $select[] =
        "COUNT(u.`{$userRoleColumn}`) AS assigned_users";
} else {
    $select[] = "0 AS assigned_users";
}

$listSql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM roles r
";

if ($roleTenantColumn !== '') {
    $listSql .= "
        INNER JOIN tenants t
            ON t.`{$tenantIdColumn}` =
               r.`{$roleTenantColumn}`
    ";
} else {
    $listSql .= "
        CROSS JOIN tenants t
    ";
}

$listSql .= "
    {$userJoinSql}
    {$whereSql}
";

if (
    $hasUsersTable &&
    $userRoleColumn !== ''
) {
    $listSql .= "
        GROUP BY
            r.`{$roleIdColumn}`
    ";
}

$listSql .= "
    {$orderSql}
    LIMIT ? OFFSET ?
";

$listStmt = $conn->prepare($listSql);

if (!$listStmt) {
    http_response_code(500);

    exit(
        'Unable to prepare roles: ' .
        platformRolesEscape($conn->error)
    );
}

$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$listTypes = $types . 'ii';

platformRolesBind(
    $listStmt,
    $listTypes,
    $listParams
);

$listStmt->execute();

$listResult = $listStmt->get_result();
$roles = array();

while ($roleRow = $listResult->fetch_assoc()) {
    $roles[] = $roleRow;
}

$listStmt->close();

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$successMessage = '';

if (!empty($_SESSION['platform_success_message'])) {
    $successMessage =
        (string) $_SESSION['platform_success_message'];

    unset($_SESSION['platform_success_message']);
}

$errorMessage = '';

if (!empty($_SESSION['platform_error_message'])) {
    $errorMessage =
        (string) $_SESSION['platform_error_message'];

    unset($_SESSION['platform_error_message']);
}

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

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .platform-roles-page {
        display: grid;
        gap: 15px;
    }

    .platform-roles-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .platform-roles-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .platform-roles-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .platform-roles-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .platform-roles-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .platform-roles-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .platform-roles-actions {
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .platform-roles-button {
        min-height: 37px;
        padding: 7px 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #4b5563;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none;
    }

    .platform-roles-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .platform-roles-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .platform-roles-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .platform-roles-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .platform-roles-summary-card {
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

    .platform-roles-summary-card:hover {
        border-color: #ddd6fe;
        background: #fcfbff;
    }

    .platform-roles-summary-card.selected {
        border-color: #c4b5fd;
        background: #faf8ff;
    }

    .platform-roles-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 14px;
    }

    .platform-roles-summary-icon.total {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .platform-roles-summary-icon.active {
        background: #ecfdf5;
        color: #059669;
    }

    .platform-roles-summary-icon.inactive {
        background: #fef2f2;
        color: #dc2626;
    }

    .platform-roles-summary-icon.system {
        background: #eff6ff;
        color: #2563eb;
    }

    .platform-roles-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .platform-roles-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .platform-roles-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .platform-roles-context {
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
        background: #faf8ff;
    }

    .platform-roles-context-name {
        color: #5b21b6;
        font-size: 10px;
        font-weight: 700;
    }

    .platform-roles-context-link {
        color: #7c3aed;
        font-size: 9px;
        font-weight: 600;
        text-decoration: none;
    }

    .platform-roles-toolbar {
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-bottom: 1px solid #eef0f3;
    }

    .platform-roles-search {
        min-width: 220px;
        position: relative;
        flex: 1;
    }

    .platform-roles-search i {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 12px;
    }

    .platform-roles-control {
        height: 36px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .platform-roles-search .platform-roles-control {
        padding-left: 33px;
    }

    .platform-roles-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .platform-roles-filter-button {
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

    .platform-roles-clear-button {
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #6b7280;
        text-decoration: none;
    }

    .platform-roles-table-wrap {
        overflow-x: auto;
    }

    .platform-roles-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .platform-roles-table th {
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

    .platform-roles-table td {
        padding: 11px 13px;
        border-bottom: 1px solid #f0f1f3;
        color: #374151;
        font-size: 9px;
        vertical-align: middle;
    }

    .platform-roles-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .platform-roles-table tbody tr:hover {
        background: #fcfbff;
    }

    .platform-role-main {
        min-width: 200px;
    }

    .platform-role-name {
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .platform-role-code {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .platform-role-description {
        max-width: 260px;
        color: #6b7280;
        font-size: 8px;
        line-height: 1.45;
    }

    .platform-role-tenant-name {
        display: block;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .platform-role-tenant-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .platform-role-status {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .platform-role-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .platform-role-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .platform-role-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .platform-role-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .platform-role-system {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 8px;
        font-weight: 700;
    }

    .platform-role-user-count {
        color: #111827;
        font-size: 10px;
        font-weight: 800;
    }

    .platform-role-actions {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .platform-role-action {
        width: 29px;
        height: 29px;
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

    .platform-role-action:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
        color: #7c3aed;
    }

    .platform-roles-empty {
        padding: 48px 20px;
        color: #9ca3af;
        text-align: center;
        font-size: 10px;
    }

    .platform-roles-empty i {
        margin-bottom: 10px;
        display: block;
        color: #c4b5fd;
        font-size: 30px;
    }

    .platform-roles-pagination-bar {
        min-height: 54px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #eef0f3;
    }

    .platform-roles-pagination-info {
        color: #6b7280;
        font-size: 9px;
    }

    .platform-roles-pagination {
        margin: 0;
        display: flex;
        gap: 4px;
        list-style: none;
    }

    .platform-roles-pagination a,
    .platform-roles-pagination span {
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

    .platform-roles-pagination a:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .platform-roles-pagination .active {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .platform-roles-pagination .disabled {
        opacity: 0.45;
        pointer-events: none;
    }

    @media (max-width: 1000px) {
        .platform-roles-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 800px) {
        .platform-roles-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .platform-roles-actions {
            width: 100%;
        }

        .platform-roles-button {
            flex: 1;
        }

        .platform-roles-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .platform-roles-search {
            min-width: 0;
        }

        .platform-roles-toolbar .platform-roles-control,
        .platform-roles-filter-button,
        .platform-roles-clear-button {
            width: 100% !important;
        }

        .platform-roles-pagination-bar {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    @media (max-width: 480px) {
        .platform-roles-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="platform-roles-page">

    <?php if ($successMessage !== ''): ?>
        <div class="platform-roles-alert success">
            <i class="bi bi-check-circle"></i>
            <span><?= platformRolesEscape($successMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="platform-roles-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= platformRolesEscape($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <div class="platform-roles-header">
        <div>
            <h2 class="platform-roles-title">
                Tenant Roles
            </h2>

            <div class="platform-roles-description">
                Manage tenant-level roles used by workspace users.
            </div>
        </div>

        <div class="platform-roles-actions">
            <?php if ($tenantId > 0): ?>
                <a
                    href="tenant-view.php?id=<?= (int) $tenantId; ?>"
                    class="platform-roles-button"
                >
                    <i class="bi bi-building"></i>
                    View Tenant
                </a>
            <?php endif; ?>

            <?php if (canManagePlatformTenants()): ?>
                <a
                    href="role-add.php<?= $tenantId > 0
                        ? '?tenant_id=' . (int) $tenantId
                        : ''; ?>"
                    class="platform-roles-button primary"
                >
                    <i class="bi bi-shield-plus"></i>
                    Add Role
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="platform-roles-summary">

        <a
            href="?<?= platformRolesEscape(
                platformRolesBuildQuery(
                    array(
                        'status' => '',
                        'page' => 1
                    )
                )
            ); ?>"
            class="platform-roles-summary-card <?= $status === ''
                ? 'selected'
                : ''; ?>"
        >
            <span class="platform-roles-summary-icon total">
                <i class="bi bi-shield-check"></i>
            </span>

            <span>
                <span class="platform-roles-summary-label">
                    Total Roles
                </span>

                <span class="platform-roles-summary-value">
                    <?= number_format($summary['total']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= platformRolesEscape(
                platformRolesBuildQuery(
                    array(
                        'status' => 'active',
                        'page' => 1
                    )
                )
            ); ?>"
            class="platform-roles-summary-card <?= $status === 'active'
                ? 'selected'
                : ''; ?>"
        >
            <span class="platform-roles-summary-icon active">
                <i class="bi bi-check-circle"></i>
            </span>

            <span>
                <span class="platform-roles-summary-label">
                    Active
                </span>

                <span class="platform-roles-summary-value">
                    <?= number_format($summary['active']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= platformRolesEscape(
                platformRolesBuildQuery(
                    array(
                        'status' => 'inactive',
                        'page' => 1
                    )
                )
            ); ?>"
            class="platform-roles-summary-card <?= $status === 'inactive'
                ? 'selected'
                : ''; ?>"
        >
            <span class="platform-roles-summary-icon inactive">
                <i class="bi bi-slash-circle"></i>
            </span>

            <span>
                <span class="platform-roles-summary-label">
                    Inactive
                </span>

                <span class="platform-roles-summary-value">
                    <?= number_format($summary['inactive']); ?>
                </span>
            </span>
        </a>

        <div class="platform-roles-summary-card">
            <span class="platform-roles-summary-icon system">
                <i class="bi bi-lock"></i>
            </span>

            <span>
                <span class="platform-roles-summary-label">
                    System Roles
                </span>

                <span class="platform-roles-summary-value">
                    <?= number_format($summary['system']); ?>
                </span>
            </span>
        </div>

    </div>

    <div class="platform-roles-card">

        <?php if ($selectedTenant): ?>
            <div class="platform-roles-context">
                <span class="platform-roles-context-name">
                    <i class="bi bi-building me-1"></i>

                    <?= platformRolesEscape(
                        $selectedTenant['tenant_name']
                    ); ?>

                    <?php if (
                        !empty($selectedTenant['tenant_code'])
                    ): ?>
                        ·
                        <?= platformRolesEscape(
                            $selectedTenant['tenant_code']
                        ); ?>
                    <?php endif; ?>
                </span>

                <a
                    href="roles.php"
                    class="platform-roles-context-link"
                >
                    Show all tenants
                </a>
            </div>
        <?php endif; ?>

        <form
            method="get"
            class="platform-roles-toolbar"
            id="platformRolesFilterForm"
        >
            <div class="platform-roles-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="search"
                    class="form-control platform-roles-control"
                    value="<?= platformRolesEscape($search); ?>"
                    placeholder="Search role, code, description or tenant..."
                    autocomplete="off"
                >
            </div>

            <?php if ($roleTenantColumn !== ''): ?>
                <select
                    name="tenant_id"
                    class="form-select platform-roles-control"
                    style="width:190px;"
                >
                    <option value="0">
                        All tenants
                    </option>

                    <?php foreach ($tenantList as $tenantRow): ?>
                        <option
                            value="<?= (int) $tenantRow['tenant_id']; ?>"
                            <?= $tenantId ===
                                (int) $tenantRow['tenant_id']
                                    ? 'selected'
                                    : ''; ?>
                        >
                            <?= platformRolesEscape(
                                $tenantRow['tenant_name']
                            ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php if ($roleStatusColumn !== ''): ?>
                <select
                    name="status"
                    class="form-select platform-roles-control"
                    style="width:140px;"
                >
                    <option value="">
                        All statuses
                    </option>

                    <option
                        value="active"
                        <?= $status === 'active'
                            ? 'selected'
                            : ''; ?>
                    >
                        Active
                    </option>

                    <option
                        value="inactive"
                        <?= $status === 'inactive'
                            ? 'selected'
                            : ''; ?>
                    >
                        Inactive
                    </option>
                </select>
            <?php endif; ?>

            <select
                name="sort"
                class="form-select platform-roles-control"
                style="width:135px;"
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
                class="form-select platform-roles-control"
                style="width:90px;"
            >
                <?php foreach ($allowedPerPage as $pageSize): ?>
                    <option
                        value="<?= (int) $pageSize; ?>"
                        <?= $perPage === $pageSize
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= (int) $pageSize; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button
                type="submit"
                class="platform-roles-filter-button"
            >
                <i class="bi bi-funnel"></i>
                Apply
            </button>

            <?php if (
                $search !== '' ||
                $tenantId > 0 ||
                $status !== '' ||
                $sort !== 'latest' ||
                $perPage !== 15
            ): ?>
                <a
                    href="roles.php"
                    class="platform-roles-clear-button"
                    title="Clear filters"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($roles)): ?>
            <div class="platform-roles-empty">
                <i class="bi bi-shield-check"></i>
                No roles matched your filters.
            </div>
        <?php else: ?>

            <div class="platform-roles-table-wrap">
                <table class="platform-roles-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Tenant</th>
                            <th>Description</th>
                            <th>Users</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($roles as $role): ?>
                            <?php
                            $roleStatus = strtolower(
                                trim((string) $role['role_status'])
                            );

                            if ($roleStatus === '') {
                                $roleStatus = 'active';
                            }
                            ?>

                            <tr>
                                <td>
                                    <div class="platform-role-main">
                                        <span class="platform-role-name">
                                            <?= platformRolesEscape(
                                                $role['role_name']
                                            ); ?>
                                        </span>

                                        <span class="platform-role-code">
                                            <?= platformRolesEscape(
                                                !empty($role['role_code'])
                                                    ? $role['role_code']
                                                    : 'Role ID ' .
                                                        (int) $role['role_id']
                                            ); ?>
                                        </span>

                                        <?php if (
                                            !empty($role['is_system'])
                                        ): ?>
                                            <span class="platform-role-system mt-1">
                                                System Role
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="platform-role-tenant-name">
                                        <?= platformRolesEscape(
                                            $role['tenant_name']
                                        ); ?>
                                    </span>

                                    <span class="platform-role-tenant-code">
                                        <?= platformRolesEscape(
                                            !empty($role['tenant_code'])
                                                ? $role['tenant_code']
                                                : 'Tenant ID ' .
                                                    (int) $role['tenant_id']
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="platform-role-description">
                                        <?= platformRolesEscape(
                                            !empty(
                                                $role['role_description']
                                            )
                                                ? $role['role_description']
                                                : 'No description'
                                        ); ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="platform-role-user-count">
                                        <?= number_format(
                                            (int) $role['assigned_users']
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span
                                        class="platform-role-status <?= platformRolesEscape(
                                            platformRolesStatusClass(
                                                $roleStatus
                                            )
                                        ); ?>"
                                    >
                                        <?= platformRolesEscape(
                                            platformRolesLabel(
                                                $roleStatus
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <?= platformRolesEscape(
                                        platformRolesDate(
                                            $role['created_at']
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <div class="platform-role-actions">
                                        <a
                                            href="role-view.php?id=<?= (int)
                                                $role['role_id']; ?>"
                                            class="platform-role-action"
                                            title="View role"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <?php if (
                                            canManagePlatformTenants()
                                        ): ?>
                                            <a
                                                href="role-edit.php?id=<?= (int)
                                                    $role['role_id']; ?>"
                                                class="platform-role-action"
                                                title="Edit role"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if (
                                            !empty($role['tenant_id'])
                                        ): ?>
                                            <a
                                                href="tenant-view.php?id=<?= (int)
                                                    $role['tenant_id']; ?>"
                                                class="platform-role-action"
                                                title="View tenant"
                                            >
                                                <i class="bi bi-building"></i>
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

        <div class="platform-roles-pagination-bar">
            <div class="platform-roles-pagination-info">
                Showing
                <?= number_format($startRecord); ?>
                to
                <?= number_format($endRecord); ?>
                of
                <?= number_format($totalRecords); ?>
                roles
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Role pagination">
                    <ul class="platform-roles-pagination">
                        <li>
                            <?php if ($page > 1): ?>
                                <a
                                    href="?<?= platformRolesEscape(
                                        platformRolesBuildQuery(
                                            array(
                                                'page' => $page - 1
                                            )
                                        )
                                    ); ?>"
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
                                    href="?<?= platformRolesEscape(
                                        platformRolesBuildQuery(
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
                                        href="?<?= platformRolesEscape(
                                            platformRolesBuildQuery(
                                                array(
                                                    'page' => $pageNumber
                                                )
                                            )
                                        ); ?>"
                                    >
                                        <?= (int) $pageNumber; ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endfor; ?>

                        <?php if ($paginationEnd < $totalPages): ?>
                            <?php if (
                                $paginationEnd < $totalPages - 1
                            ): ?>
                                <li>
                                    <span>…</span>
                                </li>
                            <?php endif; ?>

                            <li>
                                <a
                                    href="?<?= platformRolesEscape(
                                        platformRolesBuildQuery(
                                            array(
                                                'page' => $totalPages
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
                                    href="?<?= platformRolesEscape(
                                        platformRolesBuildQuery(
                                            array(
                                                'page' => $page + 1
                                            )
                                        )
                                    ); ?>"
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
        'platformRolesFilterForm'
    );

    if (!form) {
        return;
    }

    const selects = form.querySelectorAll('select');

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
