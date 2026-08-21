<?php
/**
 * FieldPlx Platform - Activity Logs
 *
 * File:
 * platform/activity-logs.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 *
 * Supports flexible activity log schemas and common column names.
 */

require_once __DIR__ . '/includes/auth.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
));

$pageTitle = 'Activity Logs - FieldPlx';
$activePage = 'activity-logs';
$basePath = '';

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('activityLogsEscape')) {
    function activityLogsEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('activityLogsTableExists')) {
    function activityLogsTableExists(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        $stmt->bind_param('s', $tableName);
        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('activityLogsColumns')) {
    function activityLogsColumns(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTable = str_replace(
            '`',
            '``',
            $tableName
        );

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTable}`"
        );

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

if (!function_exists('activityLogsFirstColumn')) {
    function activityLogsFirstColumn(
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

if (!function_exists('activityLogsGet')) {
    function activityLogsGet(
        $key,
        $default = ''
    ) {
        if (
            !isset($_GET[$key]) ||
            is_array($_GET[$key])
        ) {
            return $default;
        }

        return trim((string) $_GET[$key]);
    }
}

if (!function_exists('activityLogsBind')) {
    function activityLogsBind(
        mysqli_stmt $stmt,
        $types,
        array &$values
    ) {
        if ($types === '') {
            return;
        }

        $arguments = array($types);

        foreach ($values as $key => $value) {
            $arguments[] = &$values[$key];
        }

        call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('activityLogsLabel')) {
    function activityLogsLabel($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 'Unknown';
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

if (!function_exists('activityLogsDate')) {
    function activityLogsDate(
        $value,
        $withSeconds = false
    ) {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime(
            (string) $value
        );

        if ($timestamp === false) {
            return '—';
        }

        return $withSeconds
            ? date('d M Y, h:i:s A', $timestamp)
            : date('d M Y, h:i A', $timestamp);
    }
}

if (!function_exists('activityLogsActionClass')) {
    function activityLogsActionClass($action)
    {
        $action = strtolower(
            trim((string) $action)
        );

        if (
            strpos($action, 'delete') !== false ||
            strpos($action, 'remove') !== false ||
            strpos($action, 'failed') !== false ||
            strpos($action, 'reject') !== false
        ) {
            return 'danger';
        }

        if (
            strpos($action, 'create') !== false ||
            strpos($action, 'add') !== false ||
            strpos($action, 'login') !== false ||
            strpos($action, 'approve') !== false ||
            strpos($action, 'enable') !== false
        ) {
            return 'success';
        }

        if (
            strpos($action, 'update') !== false ||
            strpos($action, 'edit') !== false ||
            strpos($action, 'change') !== false ||
            strpos($action, 'assign') !== false
        ) {
            return 'warning';
        }

        if (
            strpos($action, 'view') !== false ||
            strpos($action, 'download') !== false ||
            strpos($action, 'export') !== false
        ) {
            return 'info';
        }

        return 'secondary';
    }
}

if (!function_exists('activityLogsActionIcon')) {
    function activityLogsActionIcon($action)
    {
        $action = strtolower(
            trim((string) $action)
        );

        if (
            strpos($action, 'delete') !== false ||
            strpos($action, 'remove') !== false
        ) {
            return 'bi bi-trash3';
        }

        if (
            strpos($action, 'create') !== false ||
            strpos($action, 'add') !== false
        ) {
            return 'bi bi-plus-circle';
        }

        if (
            strpos($action, 'update') !== false ||
            strpos($action, 'edit') !== false ||
            strpos($action, 'change') !== false
        ) {
            return 'bi bi-pencil-square';
        }

        if (strpos($action, 'login') !== false) {
            return 'bi bi-box-arrow-in-right';
        }

        if (strpos($action, 'logout') !== false) {
            return 'bi bi-box-arrow-right';
        }

        if (
            strpos($action, 'view') !== false ||
            strpos($action, 'read') !== false
        ) {
            return 'bi bi-eye';
        }

        if (
            strpos($action, 'download') !== false ||
            strpos($action, 'export') !== false
        ) {
            return 'bi bi-download';
        }

        if (
            strpos($action, 'approve') !== false ||
            strpos($action, 'enable') !== false
        ) {
            return 'bi bi-check-circle';
        }

        if (
            strpos($action, 'reject') !== false ||
            strpos($action, 'disable') !== false
        ) {
            return 'bi bi-slash-circle';
        }

        return 'bi bi-activity';
    }
}

if (!function_exists('activityLogsBuildQuery')) {
    function activityLogsBuildQuery(
        array $changes = array()
    ) {
        $query = $_GET;

        foreach ($changes as $key => $value) {
            if (
                $value === '' ||
                $value === null
            ) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return http_build_query($query);
    }
}

if (!function_exists('activityLogsJsonPretty')) {
    function activityLogsJsonPretty($value)
    {
        if (
            $value === null ||
            $value === ''
        ) {
            return '';
        }

        $decoded = json_decode(
            (string) $value,
            true
        );

        if (
            json_last_error() === JSON_ERROR_NONE
        ) {
            return json_encode(
                $decoded,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE
            );
        }

        return (string) $value;
    }
}

/*
|--------------------------------------------------------------------------
| Detect activity log table
|--------------------------------------------------------------------------
*/

$activityTableCandidates = array(
    'activity_logs',
    'platform_activity_logs',
    'audit_logs',
    'system_logs'
);

$activityTable = '';

foreach ($activityTableCandidates as $candidate) {
    if (activityLogsTableExists($conn, $candidate)) {
        $activityTable = $candidate;
        break;
    }
}

if ($activityTable === '') {
    $conn->query("
        CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `platform_user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
            `tenant_id` BIGINT(20) UNSIGNED DEFAULT NULL,
            `action` VARCHAR(120) NOT NULL,
            `entity_type` VARCHAR(120) DEFAULT NULL,
            `entity_id` BIGINT(20) UNSIGNED DEFAULT NULL,
            `description` VARCHAR(1000) DEFAULT NULL,
            `old_values` LONGTEXT DEFAULT NULL,
            `new_values` LONGTEXT DEFAULT NULL,
            `ip_address` VARCHAR(64) DEFAULT NULL,
            `user_agent` VARCHAR(500) DEFAULT NULL,
            `request_method` VARCHAR(20) DEFAULT NULL,
            `request_url` VARCHAR(1000) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_activity_logs_created`
                (`created_at`),
            KEY `idx_activity_logs_user`
                (`platform_user_id`),
            KEY `idx_activity_logs_tenant`
                (`tenant_id`),
            KEY `idx_activity_logs_action`
                (`action`)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $activityTable = 'activity_logs';
}

$logColumns = activityLogsColumns(
    $conn,
    $activityTable
);

$logIdColumn = activityLogsFirstColumn(
    $logColumns,
    array('id', 'log_id', 'activity_id')
);

$logUserColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'platform_user_id',
        'user_id',
        'created_by',
        'actor_id'
    )
);

$logTenantColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'tenant_id',
        'business_id',
        'workspace_id'
    )
);

$logActionColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'action',
        'action_type',
        'event',
        'activity'
    )
);

$logEntityTypeColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'entity_type',
        'module',
        'resource_type',
        'subject_type'
    )
);

$logEntityIdColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'entity_id',
        'record_id',
        'resource_id',
        'subject_id'
    )
);

$logDescriptionColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'description',
        'details',
        'message',
        'activity_description'
    )
);

$logOldValuesColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'old_values',
        'old_data',
        'before_data'
    )
);

$logNewValuesColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'new_values',
        'new_data',
        'after_data'
    )
);

$logIpColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'ip_address',
        'ip',
        'client_ip'
    )
);

$logUserAgentColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'user_agent',
        'browser',
        'device'
    )
);

$logMethodColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'request_method',
        'method',
        'http_method'
    )
);

$logUrlColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'request_url',
        'url',
        'route',
        'page_url'
    )
);

$logCreatedColumn = activityLogsFirstColumn(
    $logColumns,
    array(
        'created_at',
        'logged_at',
        'activity_at',
        'created_on',
        'timestamp'
    )
);

if (
    $logIdColumn === '' ||
    $logActionColumn === '' ||
    $logCreatedColumn === ''
) {
    http_response_code(500);
    exit(
        'The activity log table requires ID, action, and created date columns.'
    );
}

/*
|--------------------------------------------------------------------------
| Detect related tables
|--------------------------------------------------------------------------
*/

$hasPlatformUsersTable =
    activityLogsTableExists(
        $conn,
        'platform_users'
    );

$platformUserColumns = $hasPlatformUsersTable
    ? activityLogsColumns(
        $conn,
        'platform_users'
    )
    : array();

$platformUserIdColumn =
    activityLogsFirstColumn(
        $platformUserColumns,
        array('id', 'user_id')
    );

$platformUserNameColumn =
    activityLogsFirstColumn(
        $platformUserColumns,
        array(
            'name',
            'full_name',
            'display_name',
            'username'
        )
    );

$platformUserFirstNameColumn =
    activityLogsFirstColumn(
        $platformUserColumns,
        array('first_name')
    );

$platformUserLastNameColumn =
    activityLogsFirstColumn(
        $platformUserColumns,
        array('last_name')
    );

$platformUserEmailColumn =
    activityLogsFirstColumn(
        $platformUserColumns,
        array('email', 'email_address')
    );

$platformUserRoleColumn =
    activityLogsFirstColumn(
        $platformUserColumns,
        array('role_code', 'role', 'user_role')
    );

$hasTenantsTable =
    activityLogsTableExists(
        $conn,
        'tenants'
    );

$tenantColumns = $hasTenantsTable
    ? activityLogsColumns(
        $conn,
        'tenants'
    )
    : array();

$tenantIdColumn =
    activityLogsFirstColumn(
        $tenantColumns,
        array('id', 'tenant_id')
    );

$tenantNameColumn =
    activityLogsFirstColumn(
        $tenantColumns,
        array(
            'business_name',
            'company_name',
            'tenant_name',
            'name'
        )
    );

$tenantCodeColumn =
    activityLogsFirstColumn(
        $tenantColumns,
        array(
            'tenant_code',
            'business_code',
            'code',
            'slug'
        )
    );

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = activityLogsGet('search');
$actionFilter = activityLogsGet('action');
$entityFilter = activityLogsGet('entity');
$tenantFilter = (int)
    activityLogsGet('tenant_id', '0');
$userFilter = (int)
    activityLogsGet('user_id', '0');
$dateFrom = activityLogsGet('date_from');
$dateTo = activityLogsGet('date_to');
$sort = activityLogsGet(
    'sort',
    'latest'
);

$page = max(
    1,
    (int)
    activityLogsGet('page', '1')
);

$perPage = (int)
    activityLogsGet(
        'per_page',
        '25'
    );

$allowedPerPage = array(
    10,
    25,
    50,
    100
);

if (
    !in_array(
        $perPage,
        $allowedPerPage,
        true
    )
) {
    $perPage = 25;
}

if (
    !in_array(
        $sort,
        array('latest', 'oldest'),
        true
    )
) {
    $sort = 'latest';
}

/*
|--------------------------------------------------------------------------
| Dropdown data
|--------------------------------------------------------------------------
*/

$actionOptions = array();
$entityOptions = array();

$actionResult = $conn->query("
    SELECT DISTINCT
        `{$logActionColumn}` AS action_value
    FROM `{$activityTable}`
    WHERE `{$logActionColumn}` IS NOT NULL
      AND `{$logActionColumn}` <> ''
    ORDER BY `{$logActionColumn}` ASC
");

while ($row = $actionResult->fetch_assoc()) {
    $actionOptions[] =
        (string) $row['action_value'];
}

$actionResult->free();

if ($logEntityTypeColumn !== '') {
    $entityResult = $conn->query("
        SELECT DISTINCT
            `{$logEntityTypeColumn}` AS entity_value
        FROM `{$activityTable}`
        WHERE `{$logEntityTypeColumn}` IS NOT NULL
          AND `{$logEntityTypeColumn}` <> ''
        ORDER BY `{$logEntityTypeColumn}` ASC
    ");

    while ($row = $entityResult->fetch_assoc()) {
        $entityOptions[] =
            (string) $row['entity_value'];
    }

    $entityResult->free();
}

$tenantOptions = array();

if (
    $hasTenantsTable &&
    $tenantIdColumn !== '' &&
    $tenantNameColumn !== ''
) {
    $tenantSelect = array(
        "`{$tenantIdColumn}` AS tenant_id",
        "`{$tenantNameColumn}` AS tenant_name"
    );

    $tenantSelect[] =
        $tenantCodeColumn !== ''
            ? "`{$tenantCodeColumn}` AS tenant_code"
            : "'' AS tenant_code";

    $tenantResult = $conn->query("
        SELECT
            " . implode(', ', $tenantSelect) . "
        FROM tenants
        ORDER BY `{$tenantNameColumn}` ASC
    ");

    while ($row = $tenantResult->fetch_assoc()) {
        $tenantOptions[] = $row;
    }

    $tenantResult->free();
}

$userOptions = array();

if (
    $hasPlatformUsersTable &&
    $platformUserIdColumn !== ''
) {
    $userNameExpression = "''";

    if ($platformUserNameColumn !== '') {
        $userNameExpression =
            "`{$platformUserNameColumn}`";
    } elseif (
        $platformUserFirstNameColumn !== '' ||
        $platformUserLastNameColumn !== ''
    ) {
        $firstPart =
            $platformUserFirstNameColumn !== ''
                ? "COALESCE(`{$platformUserFirstNameColumn}`, '')"
                : "''";

        $lastPart =
            $platformUserLastNameColumn !== ''
                ? "COALESCE(`{$platformUserLastNameColumn}`, '')"
                : "''";

        $userNameExpression =
            "TRIM(CONCAT({$firstPart}, ' ', {$lastPart}))";
    }

    $userSelect = array(
        "`{$platformUserIdColumn}` AS user_id",
        "{$userNameExpression} AS user_name"
    );

    $userSelect[] =
        $platformUserEmailColumn !== ''
            ? "`{$platformUserEmailColumn}` AS user_email"
            : "'' AS user_email";

    $userResult = $conn->query("
        SELECT
            " . implode(', ', $userSelect) . "
        FROM platform_users
        ORDER BY user_name ASC
    ");

    while ($row = $userResult->fetch_assoc()) {
        $userOptions[] = $row;
    }

    $userResult->free();
}

/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
*/

$where = array('1 = 1');
$params = array();
$types = '';

if ($search !== '') {
    $searchConditions = array();

    $searchConditions[] =
        "l.`{$logActionColumn}` LIKE ?";

    $params[] = '%' . $search . '%';
    $types .= 's';

    if ($logDescriptionColumn !== '') {
        $searchConditions[] =
            "l.`{$logDescriptionColumn}` LIKE ?";

        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    if ($logEntityTypeColumn !== '') {
        $searchConditions[] =
            "l.`{$logEntityTypeColumn}` LIKE ?";

        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    if ($logIpColumn !== '') {
        $searchConditions[] =
            "l.`{$logIpColumn}` LIKE ?";

        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    if (
        $hasPlatformUsersTable &&
        $logUserColumn !== '' &&
        $platformUserIdColumn !== ''
    ) {
        if ($platformUserNameColumn !== '') {
            $searchConditions[] =
                "u.`{$platformUserNameColumn}` LIKE ?";

            $params[] = '%' . $search . '%';
            $types .= 's';
        }

        if ($platformUserEmailColumn !== '') {
            $searchConditions[] =
                "u.`{$platformUserEmailColumn}` LIKE ?";

            $params[] = '%' . $search . '%';
            $types .= 's';
        }
    }

    if (
        $hasTenantsTable &&
        $logTenantColumn !== '' &&
        $tenantIdColumn !== '' &&
        $tenantNameColumn !== ''
    ) {
        $searchConditions[] =
            "t.`{$tenantNameColumn}` LIKE ?";

        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $where[] =
        '(' .
        implode(
            ' OR ',
            $searchConditions
        ) .
        ')';
}

if ($actionFilter !== '') {
    $where[] =
        "l.`{$logActionColumn}` = ?";

    $params[] = $actionFilter;
    $types .= 's';
}

if (
    $entityFilter !== '' &&
    $logEntityTypeColumn !== ''
) {
    $where[] =
        "l.`{$logEntityTypeColumn}` = ?";

    $params[] = $entityFilter;
    $types .= 's';
}

if (
    $tenantFilter > 0 &&
    $logTenantColumn !== ''
) {
    $where[] =
        "l.`{$logTenantColumn}` = ?";

    $params[] = $tenantFilter;
    $types .= 'i';
}

if (
    $userFilter > 0 &&
    $logUserColumn !== ''
) {
    $where[] =
        "l.`{$logUserColumn}` = ?";

    $params[] = $userFilter;
    $types .= 'i';
}

if ($dateFrom !== '') {
    $where[] =
        "DATE(l.`{$logCreatedColumn}`) >= ?";

    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] =
        "DATE(l.`{$logCreatedColumn}`) <= ?";

    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(
    ' AND ',
    $where
);

/*
|--------------------------------------------------------------------------
| Joins
|--------------------------------------------------------------------------
*/

$userJoinSql = '';
$tenantJoinSql = '';

if (
    $hasPlatformUsersTable &&
    $logUserColumn !== '' &&
    $platformUserIdColumn !== ''
) {
    $userJoinSql = "
        LEFT JOIN platform_users u
            ON u.`{$platformUserIdColumn}` =
               l.`{$logUserColumn}`
    ";
}

if (
    $hasTenantsTable &&
    $logTenantColumn !== '' &&
    $tenantIdColumn !== ''
) {
    $tenantJoinSql = "
        LEFT JOIN tenants t
            ON t.`{$tenantIdColumn}` =
               l.`{$logTenantColumn}`
    ";
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summary = array(
    'total' => 0,
    'today' => 0,
    'users' => 0,
    'tenants' => 0
);

$summarySelect = array(
    "COUNT(*) AS total_count",
    "SUM(
        CASE
            WHEN DATE(l.`{$logCreatedColumn}`) = CURDATE()
            THEN 1 ELSE 0
        END
    ) AS today_count"
);

$summarySelect[] =
    $logUserColumn !== ''
        ? "COUNT(DISTINCT l.`{$logUserColumn}`) AS user_count"
        : "0 AS user_count";

$summarySelect[] =
    $logTenantColumn !== ''
        ? "COUNT(DISTINCT l.`{$logTenantColumn}`) AS tenant_count"
        : "0 AS tenant_count";

$summarySql = "
    SELECT
        " . implode(",\n        ", $summarySelect) . "
    FROM `{$activityTable}` l
";

$summaryResult = $conn->query($summarySql);
$summaryRow = $summaryResult->fetch_assoc();
$summaryResult->free();

$summary['total'] =
    isset($summaryRow['total_count'])
        ? (int) $summaryRow['total_count']
        : 0;

$summary['today'] =
    isset($summaryRow['today_count'])
        ? (int) $summaryRow['today_count']
        : 0;

$summary['users'] =
    isset($summaryRow['user_count'])
        ? (int) $summaryRow['user_count']
        : 0;

$summary['tenants'] =
    isset($summaryRow['tenant_count'])
        ? (int) $summaryRow['tenant_count']
        : 0;

/*
|--------------------------------------------------------------------------
| Count filtered records
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM `{$activityTable}` l
    {$userJoinSql}
    {$tenantJoinSql}
    WHERE {$whereSql}
";

$countStmt = $conn->prepare($countSql);

activityLogsBind(
    $countStmt,
    $types,
    $params
);

$countStmt->execute();

$countRow = $countStmt
    ->get_result()
    ->fetch_assoc();

$totalRecords =
    isset($countRow['total'])
        ? (int) $countRow['total']
        : 0;

$countStmt->close();

$totalPages = max(
    1,
    (int) ceil(
        $totalRecords / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Select logs
|--------------------------------------------------------------------------
*/

$select = array(
    "l.`{$logIdColumn}` AS log_id",
    "l.`{$logActionColumn}` AS log_action",
    "l.`{$logCreatedColumn}` AS created_at"
);

$select[] =
    $logUserColumn !== ''
        ? "l.`{$logUserColumn}` AS actor_id"
        : "NULL AS actor_id";

$select[] =
    $logTenantColumn !== ''
        ? "l.`{$logTenantColumn}` AS tenant_id"
        : "NULL AS tenant_id";

$select[] =
    $logEntityTypeColumn !== ''
        ? "l.`{$logEntityTypeColumn}` AS entity_type"
        : "'' AS entity_type";

$select[] =
    $logEntityIdColumn !== ''
        ? "l.`{$logEntityIdColumn}` AS entity_id"
        : "NULL AS entity_id";

$select[] =
    $logDescriptionColumn !== ''
        ? "l.`{$logDescriptionColumn}` AS log_description"
        : "'' AS log_description";

$select[] =
    $logOldValuesColumn !== ''
        ? "l.`{$logOldValuesColumn}` AS old_values"
        : "'' AS old_values";

$select[] =
    $logNewValuesColumn !== ''
        ? "l.`{$logNewValuesColumn}` AS new_values"
        : "'' AS new_values";

$select[] =
    $logIpColumn !== ''
        ? "l.`{$logIpColumn}` AS ip_address"
        : "'' AS ip_address";

$select[] =
    $logUserAgentColumn !== ''
        ? "l.`{$logUserAgentColumn}` AS user_agent"
        : "'' AS user_agent";

$select[] =
    $logMethodColumn !== ''
        ? "l.`{$logMethodColumn}` AS request_method"
        : "'' AS request_method";

$select[] =
    $logUrlColumn !== ''
        ? "l.`{$logUrlColumn}` AS request_url"
        : "'' AS request_url";

if (
    $hasPlatformUsersTable &&
    $logUserColumn !== '' &&
    $platformUserIdColumn !== ''
) {
    if ($platformUserNameColumn !== '') {
        $select[] =
            "u.`{$platformUserNameColumn}` AS actor_name";
    } elseif (
        $platformUserFirstNameColumn !== '' ||
        $platformUserLastNameColumn !== ''
    ) {
        $firstPart =
            $platformUserFirstNameColumn !== ''
                ? "COALESCE(u.`{$platformUserFirstNameColumn}`, '')"
                : "''";

        $lastPart =
            $platformUserLastNameColumn !== ''
                ? "COALESCE(u.`{$platformUserLastNameColumn}`, '')"
                : "''";

        $select[] =
            "TRIM(CONCAT({$firstPart}, ' ', {$lastPart})) AS actor_name";
    } else {
        $select[] =
            "'' AS actor_name";
    }

    $select[] =
        $platformUserEmailColumn !== ''
            ? "u.`{$platformUserEmailColumn}` AS actor_email"
            : "'' AS actor_email";

    $select[] =
        $platformUserRoleColumn !== ''
            ? "u.`{$platformUserRoleColumn}` AS actor_role"
            : "'' AS actor_role";
} else {
    $select[] = "'' AS actor_name";
    $select[] = "'' AS actor_email";
    $select[] = "'' AS actor_role";
}

if (
    $hasTenantsTable &&
    $logTenantColumn !== '' &&
    $tenantIdColumn !== ''
) {
    $select[] =
        $tenantNameColumn !== ''
            ? "t.`{$tenantNameColumn}` AS tenant_name"
            : "'' AS tenant_name";

    $select[] =
        $tenantCodeColumn !== ''
            ? "t.`{$tenantCodeColumn}` AS tenant_code"
            : "'' AS tenant_code";
} else {
    $select[] = "'' AS tenant_name";
    $select[] = "'' AS tenant_code";
}

$orderDirection =
    $sort === 'oldest'
        ? 'ASC'
        : 'DESC';

$listSql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM `{$activityTable}` l
    {$userJoinSql}
    {$tenantJoinSql}
    WHERE {$whereSql}
    ORDER BY
        l.`{$logCreatedColumn}` {$orderDirection},
        l.`{$logIdColumn}` {$orderDirection}
    LIMIT ? OFFSET ?
";

$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$listTypes =
    $types . 'ii';

$listStmt = $conn->prepare($listSql);

activityLogsBind(
    $listStmt,
    $listTypes,
    $listParams
);

$listStmt->execute();

$listResult = $listStmt->get_result();
$logs = array();

while ($row = $listResult->fetch_assoc()) {
    $logs[] = $row;
}

$listStmt->close();

$startRecord =
    $totalRecords > 0
        ? $offset + 1
        : 0;

$endRecord = min(
    $offset + $perPage,
    $totalRecords
);

$paginationStart = max(
    1,
    $page - 2
);

$paginationEnd = min(
    $totalPages,
    $page + 2
);

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .activity-logs-page {
        display: grid;
        gap: 15px;
    }

    .activity-logs-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .activity-logs-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .activity-logs-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .activity-logs-button {
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

    .activity-logs-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .activity-logs-summary {
        display: grid;
        grid-template-columns:
            repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .activity-logs-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
    }

    .activity-logs-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 14px;
    }

    .activity-logs-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .activity-logs-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .activity-logs-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .activity-logs-toolbar {
        padding: 12px 14px;
        display: grid;
        grid-template-columns:
            minmax(210px, 1fr)
            repeat(6, minmax(120px, auto))
            auto;
        gap: 8px;
        align-items: center;
        border-bottom: 1px solid #eef0f3;
    }

    .activity-logs-search {
        position: relative;
    }

    .activity-logs-search i {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 12px;
    }

    .activity-logs-control {
        min-height: 36px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        color: #374151;
        font-size: 9px;
    }

    .activity-logs-search .activity-logs-control {
        padding-left: 33px;
    }

    .activity-logs-clear {
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

    .activity-logs-table-wrap {
        overflow-x: auto;
    }

    .activity-logs-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .activity-logs-table th {
        padding: 10px 12px;
        border-bottom: 1px solid #e9ebef;
        background: #fafafa;
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        text-align: left;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .activity-logs-table td {
        padding: 11px 12px;
        border-bottom: 1px solid #f0f1f3;
        color: #374151;
        font-size: 9px;
        vertical-align: middle;
    }

    .activity-logs-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .activity-logs-table tbody tr:hover {
        background: #fcfbff;
    }

    .activity-log-action {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 170px;
    }

    .activity-log-action-icon {
        width: 31px;
        height: 31px;
        flex: 0 0 31px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        font-size: 12px;
    }

    .activity-log-action-icon.success {
        background: #ecfdf5;
        color: #047857;
    }

    .activity-log-action-icon.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .activity-log-action-icon.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .activity-log-action-icon.info {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .activity-log-action-icon.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .activity-log-action-name {
        display: block;
        color: #111827;
        font-size: 9px;
        font-weight: 800;
    }

    .activity-log-action-entity {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .activity-log-user {
        display: block;
        color: #111827;
        font-size: 9px;
        font-weight: 700;
    }

    .activity-log-meta {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .activity-log-description {
        max-width: 310px;
        color: #6b7280;
        font-size: 8px;
        line-height: 1.45;
    }

    .activity-log-method {
        padding: 4px 6px;
        display: inline-flex;
        align-items: center;
        border-radius: 6px;
        background: #f3f4f6;
        color: #4b5563;
        font-size: 7px;
        font-weight: 800;
    }

    .activity-log-view {
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
        cursor: pointer;
    }

    .activity-log-view:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .activity-logs-empty {
        padding: 48px 20px;
        color: #9ca3af;
        font-size: 10px;
        text-align: center;
    }

    .activity-logs-empty i {
        margin-bottom: 10px;
        display: block;
        color: #c4b5fd;
        font-size: 30px;
    }

    .activity-logs-pagination-bar {
        min-height: 54px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #eef0f3;
    }

    .activity-logs-pagination-info {
        color: #6b7280;
        font-size: 9px;
    }

    .activity-logs-pagination {
        margin: 0;
        display: flex;
        gap: 4px;
        list-style: none;
    }

    .activity-logs-pagination a,
    .activity-logs-pagination span {
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

    .activity-logs-pagination .active {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .activity-logs-pagination .disabled {
        opacity: .45;
        pointer-events: none;
    }

    .activity-log-modal-grid {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .activity-log-modal-item {
        padding: 10px;
        border: 1px solid #eef0f3;
        border-radius: 8px;
        background: #fafafa;
    }

    .activity-log-modal-label {
        display: block;
        color: #9ca3af;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .activity-log-modal-value {
        margin-top: 4px;
        display: block;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
        word-break: break-word;
    }

    .activity-log-json {
        margin: 0;
        padding: 12px;
        max-height: 240px;
        overflow: auto;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #111827;
        color: #e5e7eb;
        font-size: 8px;
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
    }

    @media (max-width: 1250px) {
        .activity-logs-toolbar {
            grid-template-columns:
                repeat(4, minmax(0, 1fr));
        }

        .activity-logs-search {
            grid-column: span 2;
        }
    }

    @media (max-width: 900px) {
        .activity-logs-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

        .activity-logs-toolbar {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

        .activity-logs-search {
            grid-column: span 2;
        }
    }

    @media (max-width: 650px) {
        .activity-logs-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .activity-logs-summary,
        .activity-logs-toolbar,
        .activity-log-modal-grid {
            grid-template-columns: 1fr;
        }

        .activity-logs-search {
            grid-column: span 1;
        }

        .activity-logs-pagination-bar {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<div class="activity-logs-page">

    <div class="activity-logs-header">
        <div>
            <h2 class="activity-logs-title">
                Activity Logs
            </h2>

            <div class="activity-logs-description">
                Review platform activity, user actions, tenant changes, and request details.
            </div>
        </div>

        <a
            href="dashboard.php"
            class="activity-logs-button"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Dashboard
        </a>
    </div>

    <section class="activity-logs-summary">
        <article class="activity-logs-summary-card">
            <span class="activity-logs-summary-icon">
                <i class="bi bi-activity"></i>
            </span>

            <span>
                <span class="activity-logs-summary-label">
                    Total Logs
                </span>

                <span class="activity-logs-summary-value">
                    <?= number_format(
                        $summary['total']
                    ); ?>
                </span>
            </span>
        </article>

        <article class="activity-logs-summary-card">
            <span class="activity-logs-summary-icon">
                <i class="bi bi-calendar-day"></i>
            </span>

            <span>
                <span class="activity-logs-summary-label">
                    Today
                </span>

                <span class="activity-logs-summary-value">
                    <?= number_format(
                        $summary['today']
                    ); ?>
                </span>
            </span>
        </article>

        <article class="activity-logs-summary-card">
            <span class="activity-logs-summary-icon">
                <i class="bi bi-people"></i>
            </span>

            <span>
                <span class="activity-logs-summary-label">
                    Active Actors
                </span>

                <span class="activity-logs-summary-value">
                    <?= number_format(
                        $summary['users']
                    ); ?>
                </span>
            </span>
        </article>

        <article class="activity-logs-summary-card">
            <span class="activity-logs-summary-icon">
                <i class="bi bi-building"></i>
            </span>

            <span>
                <span class="activity-logs-summary-label">
                    Affected Tenants
                </span>

                <span class="activity-logs-summary-value">
                    <?= number_format(
                        $summary['tenants']
                    ); ?>
                </span>
            </span>
        </article>
    </section>

    <section class="activity-logs-card">

        <form
            method="get"
            action="activity-logs.php"
            class="activity-logs-toolbar"
            id="activityLogsFilterForm"
        >
            <div class="activity-logs-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="search"
                    class="form-control activity-logs-control"
                    value="<?= activityLogsEscape(
                        $search
                    ); ?>"
                    placeholder="Search action, description, actor, tenant or IP..."
                    autocomplete="off"
                >
            </div>

            <select
                name="action"
                class="form-select activity-logs-control auto-submit"
            >
                <option value="">
                    All actions
                </option>

                <?php foreach ($actionOptions as $actionOption): ?>
                    <option
                        value="<?= activityLogsEscape(
                            $actionOption
                        ); ?>"
                        <?= $actionFilter === $actionOption
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= activityLogsEscape(
                            activityLogsLabel(
                                $actionOption
                            )
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($logEntityTypeColumn !== ''): ?>
                <select
                    name="entity"
                    class="form-select activity-logs-control auto-submit"
                >
                    <option value="">
                        All entities
                    </option>

                    <?php foreach ($entityOptions as $entityOption): ?>
                        <option
                            value="<?= activityLogsEscape(
                                $entityOption
                            ); ?>"
                            <?= $entityFilter === $entityOption
                                ? 'selected'
                                : ''; ?>
                        >
                            <?= activityLogsEscape(
                                activityLogsLabel(
                                    $entityOption
                                )
                            ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php if (!empty($tenantOptions)): ?>
                <select
                    name="tenant_id"
                    class="form-select activity-logs-control auto-submit"
                >
                    <option value="0">
                        All tenants
                    </option>

                    <?php foreach ($tenantOptions as $tenantOption): ?>
                        <option
                            value="<?= (int)
                                $tenantOption['tenant_id']; ?>"
                            <?= $tenantFilter ===
                                (int)
                                $tenantOption['tenant_id']
                                    ? 'selected'
                                    : ''; ?>
                        >
                            <?= activityLogsEscape(
                                $tenantOption['tenant_name']
                            ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php if (!empty($userOptions)): ?>
                <select
                    name="user_id"
                    class="form-select activity-logs-control auto-submit"
                >
                    <option value="0">
                        All users
                    </option>

                    <?php foreach ($userOptions as $userOption): ?>
                        <option
                            value="<?= (int)
                                $userOption['user_id']; ?>"
                            <?= $userFilter ===
                                (int)
                                $userOption['user_id']
                                    ? 'selected'
                                    : ''; ?>
                        >
                            <?= activityLogsEscape(
                                !empty($userOption['user_name'])
                                    ? $userOption['user_name']
                                    : $userOption['user_email']
                            ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <input
                type="date"
                name="date_from"
                class="form-control activity-logs-control"
                value="<?= activityLogsEscape(
                    $dateFrom
                ); ?>"
                title="Date from"
            >

            <input
                type="date"
                name="date_to"
                class="form-control activity-logs-control"
                value="<?= activityLogsEscape(
                    $dateTo
                ); ?>"
                title="Date to"
            >

            <select
                name="sort"
                class="form-select activity-logs-control auto-submit"
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
            </select>

            <select
                name="per_page"
                class="form-select activity-logs-control auto-submit"
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

            <?php if (
                $search !== '' ||
                $actionFilter !== '' ||
                $entityFilter !== '' ||
                $tenantFilter > 0 ||
                $userFilter > 0 ||
                $dateFrom !== '' ||
                $dateTo !== '' ||
                $sort !== 'latest' ||
                $perPage !== 25
            ): ?>
                <a
                    href="activity-logs.php"
                    class="activity-logs-clear"
                    title="Clear filters"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($logs)): ?>
            <div class="activity-logs-empty">
                <i class="bi bi-activity"></i>
                No activity logs matched the selected filters.
            </div>
        <?php else: ?>

            <div class="activity-logs-table-wrap">
                <table class="activity-logs-table">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Actor</th>
                            <th>Tenant</th>
                            <th>Description</th>
                            <th>Request</th>
                            <th>IP Address</th>
                            <th>Date & Time</th>
                            <th style="text-align:right;">Details</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $actionClass =
                                activityLogsActionClass(
                                    $log['log_action']
                                );

                            $actorName = trim(
                                (string)
                                $log['actor_name']
                            );

                            if ($actorName === '') {
                                $actorName =
                                    !empty($log['actor_email'])
                                        ? $log['actor_email']
                                        : (
                                            !empty($log['actor_id'])
                                                ? 'User #' .
                                                  (int)
                                                  $log['actor_id']
                                                : 'System'
                                        );
                            }

                            $tenantName = trim(
                                (string)
                                $log['tenant_name']
                            );

                            if ($tenantName === '') {
                                $tenantName =
                                    !empty($log['tenant_id'])
                                        ? 'Tenant #' .
                                          (int)
                                          $log['tenant_id']
                                        : 'Platform';

                            }

                            $oldPretty =
                                activityLogsJsonPretty(
                                    $log['old_values']
                                );

                            $newPretty =
                                activityLogsJsonPretty(
                                    $log['new_values']
                                );
                            ?>

                            <tr>
                                <td>
                                    <div class="activity-log-action">
                                        <span class="activity-log-action-icon <?= activityLogsEscape(
                                            $actionClass
                                        ); ?>">
                                            <i class="<?= activityLogsEscape(
                                                activityLogsActionIcon(
                                                    $log['log_action']
                                                )
                                            ); ?>"></i>
                                        </span>

                                        <span>
                                            <span class="activity-log-action-name">
                                                <?= activityLogsEscape(
                                                    activityLogsLabel(
                                                        $log['log_action']
                                                    )
                                                ); ?>
                                            </span>

                                            <span class="activity-log-action-entity">
                                                <?= activityLogsEscape(
                                                    !empty(
                                                        $log['entity_type']
                                                    )
                                                        ? activityLogsLabel(
                                                            $log['entity_type']
                                                        ) .
                                                          (
                                                            !empty(
                                                                $log['entity_id']
                                                            )
                                                                ? ' #' .
                                                                  (int)
                                                                  $log['entity_id']
                                                                : ''
                                                          )
                                                        : 'General Activity'
                                                ); ?>
                                            </span>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <span class="activity-log-user">
                                        <?= activityLogsEscape(
                                            $actorName
                                        ); ?>
                                    </span>

                                    <span class="activity-log-meta">
                                        <?= activityLogsEscape(
                                            !empty(
                                                $log['actor_role']
                                            )
                                                ? activityLogsLabel(
                                                    $log['actor_role']
                                                )
                                                : (
                                                    !empty(
                                                        $log['actor_email']
                                                    )
                                                        ? $log['actor_email']
                                                        : 'Platform activity'
                                                )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="activity-log-user">
                                        <?= activityLogsEscape(
                                            $tenantName
                                        ); ?>
                                    </span>

                                    <?php if (
                                        !empty(
                                            $log['tenant_code']
                                        )
                                    ): ?>
                                        <span class="activity-log-meta">
                                            <?= activityLogsEscape(
                                                $log['tenant_code']
                                            ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="activity-log-description">
                                        <?= activityLogsEscape(
                                            !empty(
                                                $log['log_description']
                                            )
                                                ? $log['log_description']
                                                : 'No description'
                                        ); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if (
                                        !empty(
                                            $log['request_method']
                                        )
                                    ): ?>
                                        <span class="activity-log-method">
                                            <?= activityLogsEscape(
                                                strtoupper(
                                                    $log['request_method']
                                                )
                                            ); ?>
                                        </span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>

                                    <?php if (
                                        !empty(
                                            $log['request_url']
                                        )
                                    ): ?>
                                        <span class="activity-log-meta">
                                            <?= activityLogsEscape(
                                                mb_strimwidth(
                                                    $log['request_url'],
                                                    0,
                                                    45,
                                                    '…'
                                                )
                                            ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= activityLogsEscape(
                                        !empty(
                                            $log['ip_address']
                                        )
                                            ? $log['ip_address']
                                            : '—'
                                    ); ?>
                                </td>

                                <td>
                                    <span class="activity-log-user">
                                        <?= activityLogsEscape(
                                            activityLogsDate(
                                                $log['created_at']
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td style="text-align:right;">
                                    <button
                                        type="button"
                                        class="activity-log-view"
                                        data-bs-toggle="modal"
                                        data-bs-target="#activityLogModal"
                                        data-log-id="<?= (int)
                                            $log['log_id']; ?>"
                                        data-action="<?= activityLogsEscape(
                                            activityLogsLabel(
                                                $log['log_action']
                                            )
                                        ); ?>"
                                        data-actor="<?= activityLogsEscape(
                                            $actorName
                                        ); ?>"
                                        data-role="<?= activityLogsEscape(
                                            activityLogsLabel(
                                                $log['actor_role']
                                            )
                                        ); ?>"
                                        data-tenant="<?= activityLogsEscape(
                                            $tenantName
                                        ); ?>"
                                        data-entity="<?= activityLogsEscape(
                                            !empty($log['entity_type'])
                                                ? activityLogsLabel(
                                                    $log['entity_type']
                                                ) .
                                                  (
                                                    !empty($log['entity_id'])
                                                        ? ' #' .
                                                          (int)
                                                          $log['entity_id']
                                                        : ''
                                                  )
                                                : '—'
                                        ); ?>"
                                        data-description="<?= activityLogsEscape(
                                            $log['log_description']
                                        ); ?>"
                                        data-ip="<?= activityLogsEscape(
                                            $log['ip_address']
                                        ); ?>"
                                        data-method="<?= activityLogsEscape(
                                            strtoupper(
                                                $log['request_method']
                                            )
                                        ); ?>"
                                        data-url="<?= activityLogsEscape(
                                            $log['request_url']
                                        ); ?>"
                                        data-agent="<?= activityLogsEscape(
                                            $log['user_agent']
                                        ); ?>"
                                        data-date="<?= activityLogsEscape(
                                            activityLogsDate(
                                                $log['created_at'],
                                                true
                                            )
                                        ); ?>"
                                        data-old="<?= activityLogsEscape(
                                            $oldPretty
                                        ); ?>"
                                        data-new="<?= activityLogsEscape(
                                            $newPretty
                                        ); ?>"
                                        title="View details"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <div class="activity-logs-pagination-bar">
            <div class="activity-logs-pagination-info">
                Showing
                <?= number_format($startRecord); ?>
                to
                <?= number_format($endRecord); ?>
                of
                <?= number_format($totalRecords); ?>
                logs
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Activity log pagination">
                    <ul class="activity-logs-pagination">
                        <li>
                            <?php if ($page > 1): ?>
                                <a
                                    href="?<?= activityLogsEscape(
                                        activityLogsBuildQuery(
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
                                    href="?<?= activityLogsEscape(
                                        activityLogsBuildQuery(
                                            array('page' => 1)
                                        )
                                    ); ?>"
                                >
                                    1
                                </a>
                            </li>

                            <?php if ($paginationStart > 2): ?>
                                <li><span>…</span></li>
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
                                        href="?<?= activityLogsEscape(
                                            activityLogsBuildQuery(
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

                        <?php if ($paginationEnd < $totalPages): ?>
                            <?php if (
                                $paginationEnd <
                                $totalPages - 1
                            ): ?>
                                <li><span>…</span></li>
                            <?php endif; ?>

                            <li>
                                <a
                                    href="?<?= activityLogsEscape(
                                        activityLogsBuildQuery(
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
                                    href="?<?= activityLogsEscape(
                                        activityLogsBuildQuery(
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

    </section>
</div>

<div
    class="modal fade"
    id="activityLogModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5
                        class="modal-title"
                        style="font-size:14px;font-weight:800;"
                    >
                        Activity Log Details
                    </h5>

                    <div
                        id="modalLogId"
                        style="font-size:8px;color:#9ca3af;margin-top:2px;"
                    ></div>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>
            </div>

            <div class="modal-body">
                <div class="activity-log-modal-grid mb-3">
                    <div class="activity-log-modal-item">
                        <span class="activity-log-modal-label">
                            Action
                        </span>
                        <span
                            class="activity-log-modal-value"
                            id="modalAction"
                        ></span>
                    </div>

                    <div class="activity-log-modal-item">
                        <span class="activity-log-modal-label">
                            Date & Time
                        </span>
                        <span
                            class="activity-log-modal-value"
                            id="modalDate"
                        ></span>
                    </div>

                    <div class="activity-log-modal-item">
                        <span class="activity-log-modal-label">
                            Actor
                        </span>
                        <span
                            class="activity-log-modal-value"
                            id="modalActor"
                        ></span>
                    </div>

                    <div class="activity-log-modal-item">
                        <span class="activity-log-modal-label">
                            Role
                        </span>
                        <span
                            class="activity-log-modal-value"
                            id="modalRole"
                        ></span>
                    </div>

                    <div class="activity-log-modal-item">
                        <span class="activity-log-modal-label">
                            Tenant
                        </span>
                        <span
                            class="activity-log-modal-value"
                            id="modalTenant"
                        ></span>
                    </div>

                    <div class="activity-log-modal-item">
                        <span class="activity-log-modal-label">
                            Entity
                        </span>
                        <span
                            class="activity-log-modal-value"
                            id="modalEntity"
                        ></span>
                    </div>

                    <div class="activity-log-modal-item">
                        <span class="activity-log-modal-label">
                            IP Address
                        </span>
                        <span
                            class="activity-log-modal-value"
                            id="modalIp"
                        ></span>
                    </div>

                    <div class="activity-log-modal-item">
                        <span class="activity-log-modal-label">
                            Request Method
                        </span>
                        <span
                            class="activity-log-modal-value"
                            id="modalMethod"
                        ></span>
                    </div>
                </div>

                <div class="activity-log-modal-item mb-3">
                    <span class="activity-log-modal-label">
                        Description
                    </span>
                    <span
                        class="activity-log-modal-value"
                        id="modalDescription"
                    ></span>
                </div>

                <div class="activity-log-modal-item mb-3">
                    <span class="activity-log-modal-label">
                        Request URL
                    </span>
                    <span
                        class="activity-log-modal-value"
                        id="modalUrl"
                    ></span>
                </div>

                <div class="activity-log-modal-item mb-3">
                    <span class="activity-log-modal-label">
                        User Agent / Device
                    </span>
                    <span
                        class="activity-log-modal-value"
                        id="modalAgent"
                    ></span>
                </div>

                <div class="mb-3">
                    <div class="activity-log-modal-label mb-2">
                        Previous Values
                    </div>

                    <pre
                        class="activity-log-json"
                        id="modalOld"
                    >No previous values recorded.</pre>
                </div>

                <div>
                    <div class="activity-log-modal-label mb-2">
                        New Values
                    </div>

                    <pre
                        class="activity-log-json"
                        id="modalNew"
                    >No new values recorded.</pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const form =
        document.getElementById(
            'activityLogsFilterForm'
        );

    if (form) {
        form
            .querySelectorAll(
                '.auto-submit'
            )
            .forEach(
                function (control) {
                    control.addEventListener(
                        'change',
                        function () {
                            form.submit();
                        }
                    );
                }
            );

        const dateInputs =
            form.querySelectorAll(
                'input[type="date"]'
            );

        dateInputs.forEach(
            function (input) {
                input.addEventListener(
                    'change',
                    function () {
                        form.submit();
                    }
                );
            }
        );

        const searchInput =
            form.querySelector(
                'input[name="search"]'
            );

        let timer = null;

        if (searchInput) {
            searchInput.addEventListener(
                'input',
                function () {
                    window.clearTimeout(timer);

                    timer = window.setTimeout(
                        function () {
                            form.submit();
                        },
                        600
                    );
                }
            );
        }
    }

    const modal =
        document.getElementById(
            'activityLogModal'
        );

    if (modal) {
        modal.addEventListener(
            'show.bs.modal',
            function (event) {
                const button =
                    event.relatedTarget;

                if (!button) {
                    return;
                }

                function value(name, fallback) {
                    const result =
                        button.getAttribute(
                            'data-' + name
                        );

                    return result &&
                        result.trim() !== ''
                            ? result
                            : fallback;
                }

                document.getElementById(
                    'modalLogId'
                ).textContent =
                    'Log #' +
                    value('log-id', '—');

                document.getElementById(
                    'modalAction'
                ).textContent =
                    value('action', '—');

                document.getElementById(
                    'modalDate'
                ).textContent =
                    value('date', '—');

                document.getElementById(
                    'modalActor'
                ).textContent =
                    value('actor', 'System');

                document.getElementById(
                    'modalRole'
                ).textContent =
                    value('role', '—');

                document.getElementById(
                    'modalTenant'
                ).textContent =
                    value('tenant', 'Platform');

                document.getElementById(
                    'modalEntity'
                ).textContent =
                    value('entity', '—');

                document.getElementById(
                    'modalIp'
                ).textContent =
                    value('ip', '—');

                document.getElementById(
                    'modalMethod'
                ).textContent =
                    value('method', '—');

                document.getElementById(
                    'modalDescription'
                ).textContent =
                    value(
                        'description',
                        'No description'
                    );

                document.getElementById(
                    'modalUrl'
                ).textContent =
                    value('url', '—');

                document.getElementById(
                    'modalAgent'
                ).textContent =
                    value('agent', '—');

                document.getElementById(
                    'modalOld'
                ).textContent =
                    value(
                        'old',
                        'No previous values recorded.'
                    );

                document.getElementById(
                    'modalNew'
                ).textContent =
                    value(
                        'new',
                        'No new values recorded.'
                    );
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
