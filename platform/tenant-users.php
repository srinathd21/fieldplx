<?php
/**
 * FieldPlx Platform - Tenant Users
 *
 * File:
 * platform/tenant-users.php
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

$pageTitle = 'Tenant Users - FieldPlx';
$activePage = 'tenant-users';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('tenantUsersEscape')) {
    function tenantUsersEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantUsersTableExists')) {
    function tenantUsersTableExists(mysqli $conn, $tableName)
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

if (!function_exists('tenantUsersColumns')) {
    function tenantUsersColumns(mysqli $conn, $tableName)
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

if (!function_exists('tenantUsersFirstColumn')) {
    function tenantUsersFirstColumn(
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

if (!function_exists('tenantUsersBind')) {
    function tenantUsersBind(
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

if (!function_exists('tenantUsersStatusLabel')) {
    function tenantUsersStatusLabel($status)
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

if (!function_exists('tenantUsersStatusClass')) {
    function tenantUsersStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
            case 'approved':
                return 'success';

            case 'pending':
            case 'invited':
                return 'warning';

            case 'inactive':
            case 'suspended':
            case 'blocked':
            case 'deleted':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('tenantUsersRoleLabel')) {
    function tenantUsersRoleLabel($role)
    {
        $role = trim((string) $role);

        if ($role === '') {
            return 'User';
        }

        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                $role
            )
        );
    }
}

if (!function_exists('tenantUsersDate')) {
    function tenantUsersDate($value, $withTime = false)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return '—';
        }

        return $withTime
            ? date('d M Y, h:i A', $timestamp)
            : date('d M Y', $timestamp);
    }
}

if (!function_exists('tenantUsersInitials')) {
    function tenantUsersInitials($firstName, $lastName, $email)
    {
        $initials = '';

        if (trim((string) $firstName) !== '') {
            $initials .= strtoupper(
                substr(trim((string) $firstName), 0, 1)
            );
        }

        if (trim((string) $lastName) !== '') {
            $initials .= strtoupper(
                substr(trim((string) $lastName), 0, 1)
            );
        }

        if ($initials === '' && trim((string) $email) !== '') {
            $initials = strtoupper(
                substr(trim((string) $email), 0, 1)
            );
        }

        return $initials !== ''
            ? $initials
            : 'U';
    }
}

if (!function_exists('tenantUsersFullName')) {
    function tenantUsersFullName(
        $firstName,
        $lastName,
        $fallback
    ) {
        $name = trim(
            trim((string) $firstName) .
            ' ' .
            trim((string) $lastName)
        );

        if ($name !== '') {
            return $name;
        }

        $fallback = trim((string) $fallback);

        return $fallback !== ''
            ? $fallback
            : 'Unnamed User';
    }
}

if (!function_exists('tenantUsersBuildQuery')) {
    function tenantUsersBuildQuery(array $changes = array())
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
| Verify required tables
|--------------------------------------------------------------------------
*/

if (!tenantUsersTableExists($conn, 'users')) {
    http_response_code(500);
    exit('The users table does not exist.');
}

if (!tenantUsersTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$userColumns = tenantUsersColumns(
    $conn,
    'users'
);

$tenantColumns = tenantUsersColumns(
    $conn,
    'tenants'
);

/*
|--------------------------------------------------------------------------
| Detect users columns
|--------------------------------------------------------------------------
*/

$userIdColumn = tenantUsersFirstColumn(
    $userColumns,
    array('id', 'user_id')
);

$userTenantColumn = tenantUsersFirstColumn(
    $userColumns,
    array('tenant_id')
);

$userFirstNameColumn = tenantUsersFirstColumn(
    $userColumns,
    array('first_name', 'firstname', 'given_name')
);

$userLastNameColumn = tenantUsersFirstColumn(
    $userColumns,
    array('last_name', 'lastname', 'surname')
);

$userNameColumn = tenantUsersFirstColumn(
    $userColumns,
    array('name', 'full_name', 'display_name')
);

$userEmailColumn = tenantUsersFirstColumn(
    $userColumns,
    array('email', 'email_address')
);

$userPhoneColumn = tenantUsersFirstColumn(
    $userColumns,
    array('phone', 'mobile', 'phone_number')
);

$userUsernameColumn = tenantUsersFirstColumn(
    $userColumns,
    array('username', 'user_name')
);

$userStatusColumn = tenantUsersFirstColumn(
    $userColumns,
    array('status', 'account_status')
);

$userRoleIdColumn = tenantUsersFirstColumn(
    $userColumns,
    array('role_id')
);

$userRoleCodeColumn = tenantUsersFirstColumn(
    $userColumns,
    array('role_code', 'user_role', 'role')
);

$userAvatarColumn = tenantUsersFirstColumn(
    $userColumns,
    array(
        'avatar_path',
        'profile_photo',
        'photo_path',
        'image'
    )
);

$userCreatedColumn = tenantUsersFirstColumn(
    $userColumns,
    array('created_at', 'created_on')
);

$userLastLoginColumn = tenantUsersFirstColumn(
    $userColumns,
    array('last_login_at', 'last_login')
);

$userDeletedColumn = tenantUsersFirstColumn(
    $userColumns,
    array('deleted_at')
);

if (
    $userIdColumn === '' ||
    $userTenantColumn === ''
) {
    http_response_code(500);

    exit(
        'The users table requires id and tenant_id columns.'
    );
}

/*
|--------------------------------------------------------------------------
| Detect tenant columns
|--------------------------------------------------------------------------
*/

$tenantIdColumn = tenantUsersFirstColumn(
    $tenantColumns,
    array('id', 'tenant_id')
);

$tenantNameColumn = tenantUsersFirstColumn(
    $tenantColumns,
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$tenantCodeColumn = tenantUsersFirstColumn(
    $tenantColumns,
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$tenantStatusColumn = tenantUsersFirstColumn(
    $tenantColumns,
    array('status')
);

$tenantDeletedColumn = tenantUsersFirstColumn(
    $tenantColumns,
    array('deleted_at')
);

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);

    exit(
        'The tenants table requires id and name columns.'
    );
}

/*
|--------------------------------------------------------------------------
| Role table detection
|--------------------------------------------------------------------------
*/

$hasRolesTable = tenantUsersTableExists(
    $conn,
    'roles'
);

$roleIdColumn = '';
$roleNameColumn = '';
$roleCodeTableColumn = '';
$roleTenantColumn = '';
$roleDeletedColumn = '';

if ($hasRolesTable) {
    $roleColumns = tenantUsersColumns(
        $conn,
        'roles'
    );

    $roleIdColumn = tenantUsersFirstColumn(
        $roleColumns,
        array('id', 'role_id')
    );

    $roleNameColumn = tenantUsersFirstColumn(
        $roleColumns,
        array('name', 'role_name')
    );

    $roleCodeTableColumn = tenantUsersFirstColumn(
        $roleColumns,
        array('code', 'role_code')
    );

    $roleTenantColumn = tenantUsersFirstColumn(
        $roleColumns,
        array('tenant_id')
    );

    $roleDeletedColumn = tenantUsersFirstColumn(
        $roleColumns,
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

$allowedStatuses = array(
    '',
    'active',
    'inactive',
    'pending',
    'invited',
    'suspended',
    'blocked'
);

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

/*
|--------------------------------------------------------------------------
| Load tenants for filter
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

$tenantListWhere = array();

if ($tenantDeletedColumn !== '') {
    $tenantListWhere[] =
        "`{$tenantDeletedColumn}` IS NULL";
}

if (!empty($tenantListWhere)) {
    $tenantListSql .= "
        WHERE " .
        implode(' AND ', $tenantListWhere);
}

$tenantListSql .= "
    ORDER BY `{$tenantNameColumn}` ASC
";

$tenantListResult = $conn->query(
    $tenantListSql
);

if ($tenantListResult) {
    while ($tenantRow = $tenantListResult->fetch_assoc()) {
        $tenantList[] = $tenantRow;
    }

    $tenantListResult->free();
}

/*
|--------------------------------------------------------------------------
| Validate selected tenant
|--------------------------------------------------------------------------
*/

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

        header('Location: tenant-users.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Summary counts
|--------------------------------------------------------------------------
*/

$summary = array(
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'pending' => 0
);

$summaryWhere = array();

if ($userDeletedColumn !== '') {
    $summaryWhere[] =
        "`{$userDeletedColumn}` IS NULL";
}

if ($tenantId > 0) {
    $summaryWhere[] =
        "`{$userTenantColumn}` = " .
        (int) $tenantId;
}

$summarySql = "
    SELECT
        COUNT(*) AS total
";

if ($userStatusColumn !== '') {
    $summarySql .= ",
        SUM(
            CASE
                WHEN `{$userStatusColumn}` = 'active'
                THEN 1 ELSE 0
            END
        ) AS active_count,
        SUM(
            CASE
                WHEN `{$userStatusColumn}` IN (
                    'inactive',
                    'suspended',
                    'blocked'
                )
                THEN 1 ELSE 0
            END
        ) AS inactive_count,
        SUM(
            CASE
                WHEN `{$userStatusColumn}` IN (
                    'pending',
                    'invited'
                )
                THEN 1 ELSE 0
            END
        ) AS pending_count
    ";
}

$summarySql .= "
    FROM users
";

if (!empty($summaryWhere)) {
    $summarySql .= "
        WHERE " .
        implode(' AND ', $summaryWhere);
}

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

    $summary['pending'] = isset(
        $summaryRow['pending_count']
    )
        ? (int) $summaryRow['pending_count']
        : 0;

    $summaryResult->free();
}

/*
|--------------------------------------------------------------------------
| Build list filters
|--------------------------------------------------------------------------
*/

$where = array();
$params = array();
$types = '';

if ($userDeletedColumn !== '') {
    $where[] =
        "u.`{$userDeletedColumn}` IS NULL";
}

if ($tenantDeletedColumn !== '') {
    $where[] =
        "t.`{$tenantDeletedColumn}` IS NULL";
}

if ($tenantId > 0) {
    $where[] =
        "u.`{$userTenantColumn}` = ?";

    $types .= 'i';
    $params[] = $tenantId;
}

if ($status !== '' && $userStatusColumn !== '') {
    if ($status === 'inactive') {
        $where[] =
            "u.`{$userStatusColumn}` IN (
                'inactive',
                'suspended',
                'blocked'
            )";
    } elseif ($status === 'pending') {
        $where[] =
            "u.`{$userStatusColumn}` IN (
                'pending',
                'invited'
            )";
    } else {
        $where[] =
            "u.`{$userStatusColumn}` = ?";

        $types .= 's';
        $params[] = $status;
    }
}

if ($search !== '') {
    $searchConditions = array();

    $searchColumns = array_filter(array(
        $userFirstNameColumn,
        $userLastNameColumn,
        $userNameColumn,
        $userEmailColumn,
        $userPhoneColumn,
        $userUsernameColumn
    ));

    foreach ($searchColumns as $column) {
        $searchConditions[] =
            "u.`{$column}` LIKE ?";

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
| Count filtered users
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM users u
    INNER JOIN tenants t
        ON t.`{$tenantIdColumn}` =
           u.`{$userTenantColumn}`
    {$whereSql}
";

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    http_response_code(500);

    exit(
        'Unable to prepare tenant user count: ' .
        tenantUsersEscape($conn->error)
    );
}

tenantUsersBind(
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

$nameSortColumn = $userFirstNameColumn !== ''
    ? "u.`{$userFirstNameColumn}`"
    : (
        $userNameColumn !== ''
            ? "u.`{$userNameColumn}`"
            : "u.`{$userIdColumn}`"
    );

switch ($sort) {
    case 'oldest':
        $sortColumn = $userCreatedColumn !== ''
            ? "u.`{$userCreatedColumn}`"
            : "u.`{$userIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} ASC";
        break;

    case 'name_asc':
        $orderSql =
            "ORDER BY {$nameSortColumn} ASC";
        break;

    case 'name_desc':
        $orderSql =
            "ORDER BY {$nameSortColumn} DESC";
        break;

    case 'latest':
    default:
        $sortColumn = $userCreatedColumn !== ''
            ? "u.`{$userCreatedColumn}`"
            : "u.`{$userIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} DESC";
        break;
}

/*
|--------------------------------------------------------------------------
| Build select
|--------------------------------------------------------------------------
*/

$select = array(
    "u.`{$userIdColumn}` AS user_id",
    "u.`{$userTenantColumn}` AS tenant_id",
    "t.`{$tenantNameColumn}` AS tenant_name"
);

$select[] = $tenantCodeColumn !== ''
    ? "t.`{$tenantCodeColumn}` AS tenant_code"
    : "'' AS tenant_code";

$select[] = $userFirstNameColumn !== ''
    ? "u.`{$userFirstNameColumn}` AS first_name"
    : "'' AS first_name";

$select[] = $userLastNameColumn !== ''
    ? "u.`{$userLastNameColumn}` AS last_name"
    : "'' AS last_name";

$select[] = $userNameColumn !== ''
    ? "u.`{$userNameColumn}` AS full_name"
    : "'' AS full_name";

$select[] = $userEmailColumn !== ''
    ? "u.`{$userEmailColumn}` AS email"
    : "'' AS email";

$select[] = $userPhoneColumn !== ''
    ? "u.`{$userPhoneColumn}` AS phone"
    : "'' AS phone";

$select[] = $userUsernameColumn !== ''
    ? "u.`{$userUsernameColumn}` AS username"
    : "'' AS username";

$select[] = $userStatusColumn !== ''
    ? "u.`{$userStatusColumn}` AS user_status"
    : "'active' AS user_status";

$select[] = $userAvatarColumn !== ''
    ? "u.`{$userAvatarColumn}` AS avatar_path"
    : "'' AS avatar_path";

$select[] = $userCreatedColumn !== ''
    ? "u.`{$userCreatedColumn}` AS created_at"
    : "NULL AS created_at";

$select[] = $userLastLoginColumn !== ''
    ? "u.`{$userLastLoginColumn}` AS last_login_at"
    : "NULL AS last_login_at";

$roleJoinSql = '';

if (
    $userRoleIdColumn !== '' &&
    $hasRolesTable &&
    $roleIdColumn !== ''
) {
    $roleJoinSql = "
        LEFT JOIN roles r
            ON r.`{$roleIdColumn}` =
               u.`{$userRoleIdColumn}`
    ";

    if (
        $roleTenantColumn !== '' &&
        $roleTenantColumn !== $roleIdColumn
    ) {
        $roleJoinSql .= "
            AND (
                r.`{$roleTenantColumn}` =
                    u.`{$userTenantColumn}`
                OR r.`{$roleTenantColumn}` IS NULL
            )
        ";
    }

    if ($roleDeletedColumn !== '') {
        $roleJoinSql .= "
            AND r.`{$roleDeletedColumn}` IS NULL
        ";
    }

    if ($roleNameColumn !== '') {
        $select[] =
            "r.`{$roleNameColumn}` AS role_name";
    } elseif ($roleCodeTableColumn !== '') {
        $select[] =
            "r.`{$roleCodeTableColumn}` AS role_name";
    } else {
        $select[] = "'User' AS role_name";
    }

    $select[] = $roleCodeTableColumn !== ''
        ? "r.`{$roleCodeTableColumn}` AS role_code"
        : "'' AS role_code";
} else {
    $select[] = $userRoleCodeColumn !== ''
        ? "u.`{$userRoleCodeColumn}` AS role_name"
        : "'User' AS role_name";

    $select[] = $userRoleCodeColumn !== ''
        ? "u.`{$userRoleCodeColumn}` AS role_code"
        : "'' AS role_code";
}

/*
|--------------------------------------------------------------------------
| Load tenant users
|--------------------------------------------------------------------------
*/

$listSql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM users u
    INNER JOIN tenants t
        ON t.`{$tenantIdColumn}` =
           u.`{$userTenantColumn}`
    {$roleJoinSql}
    {$whereSql}
    {$orderSql}
    LIMIT ? OFFSET ?
";

$listStmt = $conn->prepare($listSql);

if (!$listStmt) {
    http_response_code(500);

    exit(
        'Unable to prepare tenant users: ' .
        tenantUsersEscape($conn->error)
    );
}

$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$listTypes = $types . 'ii';

tenantUsersBind(
    $listStmt,
    $listTypes,
    $listParams
);

$listStmt->execute();

$listResult = $listStmt->get_result();
$users = array();

while ($row = $listResult->fetch_assoc()) {
    $users[] = $row;
}

$listStmt->close();

/*
|--------------------------------------------------------------------------
| Messages and pagination
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
    .tenant-users-page {
        display: grid;
        gap: 15px;
    }

    .tenant-users-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .tenant-users-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .tenant-users-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-users-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .tenant-users-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-users-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .tenant-users-header-actions {
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .tenant-users-button {
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

    .tenant-users-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-users-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .tenant-users-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .tenant-users-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .tenant-users-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
        text-decoration: none;
        box-shadow:
            0 4px 15px rgba(31, 41, 55, 0.03);
    }

    .tenant-users-summary-card:hover {
        border-color: #ddd6fe;
        background: #fcfbff;
    }

    .tenant-users-summary-card.selected {
        border-color: #c4b5fd;
        background: #faf8ff;
    }

    .tenant-users-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 14px;
    }

    .tenant-users-summary-icon.total {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .tenant-users-summary-icon.active {
        background: #ecfdf5;
        color: #059669;
    }

    .tenant-users-summary-icon.inactive {
        background: #fef2f2;
        color: #dc2626;
    }

    .tenant-users-summary-icon.pending {
        background: #fff7ed;
        color: #d97706;
    }

    .tenant-users-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        letter-spacing: 0.4px;
        text-transform: uppercase;
    }

    .tenant-users-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .tenant-users-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-users-toolbar {
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-bottom: 1px solid #eef0f3;
    }

    .tenant-users-search {
        min-width: 220px;
        position: relative;
        flex: 1;
    }

    .tenant-users-search i {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 12px;
    }

    .tenant-users-control {
        height: 36px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .tenant-users-search .tenant-users-control {
        padding-left: 33px;
    }

    .tenant-users-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .tenant-users-filter-button {
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

    .tenant-users-clear-button {
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

    .tenant-users-context {
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
        background: #faf8ff;
    }

    .tenant-users-context-name {
        color: #5b21b6;
        font-size: 10px;
        font-weight: 700;
    }

    .tenant-users-context-link {
        color: #7c3aed;
        font-size: 9px;
        font-weight: 600;
        text-decoration: none;
    }

    .tenant-users-table-wrap {
        overflow-x: auto;
    }

    .tenant-users-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .tenant-users-table th {
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

    .tenant-users-table td {
        padding: 11px 13px;
        border-bottom: 1px solid #f0f1f3;
        color: #374151;
        font-size: 9px;
        vertical-align: middle;
    }

    .tenant-users-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .tenant-users-table tbody tr:hover {
        background: #fcfbff;
    }

    .tenant-user-main {
        min-width: 210px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .tenant-user-avatar {
        width: 37px;
        height: 37px;
        flex: 0 0 37px;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 9px;
        font-weight: 800;
    }

    .tenant-user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .tenant-user-name {
        overflow: hidden;
        display: block;
        max-width: 220px;
        color: #111827;
        font-size: 10px;
        font-weight: 700;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .tenant-user-subtext {
        margin-top: 2px;
        display: block;
        overflow: hidden;
        max-width: 220px;
        color: #9ca3af;
        font-size: 8px;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .tenant-user-tenant {
        min-width: 150px;
    }

    .tenant-user-tenant-name {
        display: block;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-user-tenant-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-user-role {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #f3e8ff;
        color: #6d28d9;
        font-size: 8px;
        font-weight: 700;
    }

    .tenant-user-status {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .tenant-user-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .tenant-user-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .tenant-user-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-user-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .tenant-user-actions {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .tenant-user-action {
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

    .tenant-user-action:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
        color: #7c3aed;
    }

    .tenant-users-empty {
        padding: 48px 20px;
        color: #9ca3af;
        text-align: center;
        font-size: 10px;
    }

    .tenant-users-empty i {
        margin-bottom: 10px;
        display: block;
        color: #c4b5fd;
        font-size: 30px;
    }

    .tenant-users-pagination-bar {
        min-height: 54px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #eef0f3;
    }

    .tenant-users-pagination-info {
        color: #6b7280;
        font-size: 9px;
    }

    .tenant-users-pagination {
        margin: 0;
        display: flex;
        gap: 4px;
        list-style: none;
    }

    .tenant-users-pagination a,
    .tenant-users-pagination span {
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

    .tenant-users-pagination a:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-users-pagination .active {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .tenant-users-pagination .disabled {
        opacity: 0.45;
        pointer-events: none;
    }

    @media (max-width: 1000px) {
        .tenant-users-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 800px) {
        .tenant-users-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .tenant-users-header-actions {
            width: 100%;
        }

        .tenant-users-button {
            flex: 1;
        }

        .tenant-users-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .tenant-users-search {
            min-width: 0;
        }

        .tenant-users-toolbar .tenant-users-control,
        .tenant-users-filter-button,
        .tenant-users-clear-button {
            width: 100% !important;
        }

        .tenant-users-pagination-bar {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    @media (max-width: 480px) {
        .tenant-users-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="tenant-users-page">

    <?php if ($successMessage !== ''): ?>
        <div class="tenant-users-alert success">
            <i class="bi bi-check-circle"></i>

            <span>
                <?= tenantUsersEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="tenant-users-alert danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= tenantUsersEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="tenant-users-header">
        <div>
            <h2 class="tenant-users-title">
                Tenant Users
            </h2>

            <div class="tenant-users-description">
                Manage user accounts across tenant workspaces.
            </div>
        </div>

        <div class="tenant-users-header-actions">
            <?php if ($tenantId > 0): ?>
                <a
                    href="tenant-view.php?id=<?= (int) $tenantId; ?>"
                    class="tenant-users-button"
                >
                    <i class="bi bi-building"></i>
                    View Tenant
                </a>
            <?php endif; ?>

            <?php if (canManagePlatformTenants()): ?>
                <a
                    href="tenant-user-add.php<?= $tenantId > 0
                        ? '?tenant_id=' . (int) $tenantId
                        : ''; ?>"
                    class="tenant-users-button primary"
                >
                    <i class="bi bi-person-plus"></i>
                    Add Tenant User
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="tenant-users-summary">

        <a
            href="?<?= tenantUsersEscape(
                tenantUsersBuildQuery(
                    array(
                        'status' => '',
                        'page' => 1
                    )
                )
            ); ?>"
            class="tenant-users-summary-card <?= $status === ''
                ? 'selected'
                : ''; ?>"
        >
            <span class="tenant-users-summary-icon total">
                <i class="bi bi-people"></i>
            </span>

            <span>
                <span class="tenant-users-summary-label">
                    Total Users
                </span>

                <span class="tenant-users-summary-value">
                    <?= number_format(
                        $summary['total']
                    ); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= tenantUsersEscape(
                tenantUsersBuildQuery(
                    array(
                        'status' => 'active',
                        'page' => 1
                    )
                )
            ); ?>"
            class="tenant-users-summary-card <?= $status === 'active'
                ? 'selected'
                : ''; ?>"
        >
            <span class="tenant-users-summary-icon active">
                <i class="bi bi-person-check"></i>
            </span>

            <span>
                <span class="tenant-users-summary-label">
                    Active
                </span>

                <span class="tenant-users-summary-value">
                    <?= number_format(
                        $summary['active']
                    ); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= tenantUsersEscape(
                tenantUsersBuildQuery(
                    array(
                        'status' => 'inactive',
                        'page' => 1
                    )
                )
            ); ?>"
            class="tenant-users-summary-card <?= $status === 'inactive'
                ? 'selected'
                : ''; ?>"
        >
            <span class="tenant-users-summary-icon inactive">
                <i class="bi bi-person-x"></i>
            </span>

            <span>
                <span class="tenant-users-summary-label">
                    Inactive
                </span>

                <span class="tenant-users-summary-value">
                    <?= number_format(
                        $summary['inactive']
                    ); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= tenantUsersEscape(
                tenantUsersBuildQuery(
                    array(
                        'status' => 'pending',
                        'page' => 1
                    )
                )
            ); ?>"
            class="tenant-users-summary-card <?= $status === 'pending'
                ? 'selected'
                : ''; ?>"
        >
            <span class="tenant-users-summary-icon pending">
                <i class="bi bi-person-clock"></i>
            </span>

            <span>
                <span class="tenant-users-summary-label">
                    Pending
                </span>

                <span class="tenant-users-summary-value">
                    <?= number_format(
                        $summary['pending']
                    ); ?>
                </span>
            </span>
        </a>

    </div>

    <div class="tenant-users-card">

        <?php if ($selectedTenant): ?>
            <div class="tenant-users-context">
                <span class="tenant-users-context-name">
                    <i class="bi bi-building me-1"></i>

                    <?= tenantUsersEscape(
                        $selectedTenant['tenant_name']
                    ); ?>

                    <?php if (
                        !empty(
                            $selectedTenant['tenant_code']
                        )
                    ): ?>
                        ·
                        <?= tenantUsersEscape(
                            $selectedTenant['tenant_code']
                        ); ?>
                    <?php endif; ?>
                </span>

                <a
                    href="tenant-users.php"
                    class="tenant-users-context-link"
                >
                    Show all tenants
                </a>
            </div>
        <?php endif; ?>

        <form
            method="get"
            class="tenant-users-toolbar"
            id="tenantUsersFilterForm"
        >
            <div class="tenant-users-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    class="form-control tenant-users-control"
                    name="search"
                    value="<?= tenantUsersEscape(
                        $search
                    ); ?>"
                    placeholder="Search name, email, phone or username..."
                    autocomplete="off"
                >
            </div>

            <select
                name="tenant_id"
                class="form-select tenant-users-control"
                style="width:190px;"
            >
                <option value="0">
                    All tenants
                </option>

                <?php foreach ($tenantList as $tenantRow): ?>
                    <option
                        value="<?= (int)
                            $tenantRow['tenant_id']; ?>"
                        <?= $tenantId ===
                            (int) $tenantRow['tenant_id']
                                ? 'selected'
                                : ''; ?>
                    >
                        <?= tenantUsersEscape(
                            $tenantRow['tenant_name']
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($userStatusColumn !== ''): ?>
                <select
                    name="status"
                    class="form-select tenant-users-control"
                    style="width:145px;"
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

                    <option
                        value="pending"
                        <?= $status === 'pending'
                            ? 'selected'
                            : ''; ?>
                    >
                        Pending
                    </option>

                    <option
                        value="suspended"
                        <?= $status === 'suspended'
                            ? 'selected'
                            : ''; ?>
                    >
                        Suspended
                    </option>
                </select>
            <?php endif; ?>

            <select
                name="sort"
                class="form-select tenant-users-control"
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
                class="form-select tenant-users-control"
                style="width:90px;"
            >
                <?php foreach (
                    $allowedPerPage as $pageSize
                ): ?>
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
                class="tenant-users-filter-button"
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
                    href="tenant-users.php"
                    class="tenant-users-clear-button"
                    title="Clear filters"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($users)): ?>
            <div class="tenant-users-empty">
                <i class="bi bi-people"></i>

                No tenant users matched your filters.
            </div>
        <?php else: ?>

            <div class="tenant-users-table-wrap">
                <table class="tenant-users-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Tenant</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Created</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $displayName =
                                trim(
                                    (string) $user['full_name']
                                );

                            if ($displayName === '') {
                                $displayName =
                                    tenantUsersFullName(
                                        $user['first_name'],
                                        $user['last_name'],
                                        $user['email']
                                    );
                            }

                            $avatarPath = trim(
                                (string) $user['avatar_path']
                            );

                            $roleName = !empty(
                                $user['role_name']
                            )
                                ? $user['role_name']
                                : $user['role_code'];

                            $userStatus = strtolower(
                                trim(
                                    (string)
                                    $user['user_status']
                                )
                            );

                            if ($userStatus === '') {
                                $userStatus = 'active';
                            }
                            ?>

                            <tr>
                                <td>
                                    <div class="tenant-user-main">
                                        <span class="tenant-user-avatar">
                                            <?php if (
                                                $avatarPath !== ''
                                            ): ?>
                                                <img
                                                    src="../<?= tenantUsersEscape(
                                                        ltrim(
                                                            $avatarPath,
                                                            '/'
                                                        )
                                                    ); ?>"
                                                    alt=""
                                                >
                                            <?php else: ?>
                                                <?= tenantUsersEscape(
                                                    tenantUsersInitials(
                                                        $user[
                                                            'first_name'
                                                        ],
                                                        $user[
                                                            'last_name'
                                                        ],
                                                        $user['email']
                                                    )
                                                ); ?>
                                            <?php endif; ?>
                                        </span>

                                        <span style="min-width:0;">
                                            <span class="tenant-user-name">
                                                <?= tenantUsersEscape(
                                                    $displayName
                                                ); ?>
                                            </span>

                                            <span class="tenant-user-subtext">
                                                <?= tenantUsersEscape(
                                                    !empty($user['email'])
                                                        ? $user['email']
                                                        : (
                                                            !empty(
                                                                $user[
                                                                    'username'
                                                                ]
                                                            )
                                                                ? $user[
                                                                    'username'
                                                                ]
                                                                : 'User ID ' .
                                                                    (int)
                                                                    $user[
                                                                        'user_id'
                                                                    ]
                                                        )
                                                ); ?>
                                            </span>

                                            <?php if (
                                                !empty($user['phone'])
                                            ): ?>
                                                <span class="tenant-user-subtext">
                                                    <?= tenantUsersEscape(
                                                        $user['phone']
                                                    ); ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="tenant-user-tenant">
                                        <span class="tenant-user-tenant-name">
                                            <?= tenantUsersEscape(
                                                $user['tenant_name']
                                            ); ?>
                                        </span>

                                        <span class="tenant-user-tenant-code">
                                            <?= tenantUsersEscape(
                                                !empty(
                                                    $user['tenant_code']
                                                )
                                                    ? $user['tenant_code']
                                                    : 'Tenant ID ' .
                                                        (int)
                                                        $user['tenant_id']
                                            ); ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <span class="tenant-user-role">
                                        <?= tenantUsersEscape(
                                            tenantUsersRoleLabel(
                                                $roleName
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span
                                        class="tenant-user-status <?= tenantUsersEscape(
                                            tenantUsersStatusClass(
                                                $userStatus
                                            )
                                        ); ?>"
                                    >
                                        <?= tenantUsersEscape(
                                            tenantUsersStatusLabel(
                                                $userStatus
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <?= tenantUsersEscape(
                                        tenantUsersDate(
                                            $user['last_login_at'],
                                            true
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <?= tenantUsersEscape(
                                        tenantUsersDate(
                                            $user['created_at']
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <div class="tenant-user-actions">
                                        <a
                                            href="tenant-user-view.php?id=<?= (int)
                                                $user['user_id']; ?>"
                                            class="tenant-user-action"
                                            title="View user"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <?php if (
                                            canManagePlatformTenants()
                                        ): ?>
                                            <a
                                                href="tenant-user-edit.php?id=<?= (int)
                                                    $user['user_id']; ?>"
                                                class="tenant-user-action"
                                                title="Edit user"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>

                                        <a
                                            href="tenant-view.php?id=<?= (int)
                                                $user['tenant_id']; ?>"
                                            class="tenant-user-action"
                                            title="View tenant"
                                        >
                                            <i class="bi bi-building"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <div class="tenant-users-pagination-bar">
            <div class="tenant-users-pagination-info">
                Showing
                <?= number_format($startRecord); ?>
                to
                <?= number_format($endRecord); ?>
                of
                <?= number_format($totalRecords); ?>
                users
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Tenant user pagination">
                    <ul class="tenant-users-pagination">

                        <li>
                            <?php if ($page > 1): ?>
                                <a
                                    href="?<?= tenantUsersEscape(
                                        tenantUsersBuildQuery(
                                            array(
                                                'page' =>
                                                    $page - 1
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
                                    href="?<?= tenantUsersEscape(
                                        tenantUsersBuildQuery(
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
                                <?php if (
                                    $pageNumber === $page
                                ): ?>
                                    <span class="active">
                                        <?= (int) $pageNumber; ?>
                                    </span>
                                <?php else: ?>
                                    <a
                                        href="?<?= tenantUsersEscape(
                                            tenantUsersBuildQuery(
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
                                    href="?<?= tenantUsersEscape(
                                        tenantUsersBuildQuery(
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
                                    href="?<?= tenantUsersEscape(
                                        tenantUsersBuildQuery(
                                            array(
                                                'page' =>
                                                    $page + 1
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
        'tenantUsersFilterForm'
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
