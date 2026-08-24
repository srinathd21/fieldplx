<?php
/**
 * FieldPlx Platform - Modules
 *
 * File:
 * platform/modules.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 *
 * Automatically creates:
 * - modules
 * - module_features
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin'
));

$pageTitle = 'Modules - FieldPlx';
$activePage = 'modules';
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

if (!function_exists('modulesEscape')) {
    function modulesEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('modulesGet')) {
    function modulesGet($key, $default = '')
    {
        if (
            !isset($_GET[$key]) ||
            is_array($_GET[$key])
        ) {
            return $default;
        }

        return trim((string) $_GET[$key]);
    }
}

if (!function_exists('modulesPost')) {
    function modulesPost($key, $default = '')
    {
        if (
            !isset($_POST[$key]) ||
            is_array($_POST[$key])
        ) {
            return $default;
        }

        return trim((string) $_POST[$key]);
    }
}

if (!function_exists('modulesLabel')) {
    function modulesLabel($value)
    {
        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                trim((string) $value)
            )
        );
    }
}

if (!function_exists('modulesCurrentUserId')) {
    function modulesCurrentUserId()
    {
        if (!empty($_SESSION['platform_user_id'])) {
            return (int) $_SESSION['platform_user_id'];
        }

        if (!empty($_SESSION['platform_admin_id'])) {
            return (int) $_SESSION['platform_admin_id'];
        }

        return 0;
    }
}

if (!function_exists('modulesBuildUrl')) {
    function modulesBuildUrl(array $changes = array())
    {
        $query = $_GET;

        foreach ($changes as $key => $value) {
            if (
                $value === null ||
                $value === ''
            ) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return 'modules.php' .
            (!empty($query)
                ? '?' . http_build_query($query)
                : '');
    }
}

/*
|--------------------------------------------------------------------------
| Create master tables
|--------------------------------------------------------------------------
*/

$conn->query("
    CREATE TABLE IF NOT EXISTS `modules` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `module_code` VARCHAR(100) NOT NULL,
        `module_name` VARCHAR(150) NOT NULL,
        `description` VARCHAR(500) DEFAULT NULL,
        `icon_class` VARCHAR(100) DEFAULT NULL,
        `menu_url` VARCHAR(255) DEFAULT NULL,
        `menu_order` INT(11) NOT NULL DEFAULT 0,
        `is_core` TINYINT(1) NOT NULL DEFAULT 0,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_modules_code`
            (`module_code`),
        KEY `idx_modules_status_order`
            (`is_active`, `menu_order`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS `module_features` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `module_id` BIGINT(20) UNSIGNED NOT NULL,
        `feature_code` VARCHAR(120) NOT NULL,
        `feature_name` VARCHAR(150) NOT NULL,
        `description` VARCHAR(500) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_module_feature`
            (`module_id`, `feature_code`),
        KEY `idx_module_features_module`
            (`module_id`),
        KEY `idx_module_features_status`
            (`is_active`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");


/*
|--------------------------------------------------------------------------
| Upgrade existing module tables safely
|--------------------------------------------------------------------------
*/

$moduleTableColumns = array();

$moduleColumnsResult = $conn->query("
    SHOW COLUMNS FROM `modules`
");

while ($moduleColumn = $moduleColumnsResult->fetch_assoc()) {
    $moduleTableColumns[
        (string) $moduleColumn['Field']
    ] = true;
}

$moduleColumnsResult->free();

if (!isset($moduleTableColumns['updated_by'])) {
    $conn->query("
        ALTER TABLE `modules`
        ADD COLUMN `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL
        AFTER `is_active`
    ");
}

if (!isset($moduleTableColumns['created_at'])) {
    $conn->query("
        ALTER TABLE `modules`
        ADD COLUMN `created_at`
            DATETIME NOT NULL
            DEFAULT CURRENT_TIMESTAMP
        AFTER `updated_by`
    ");
}

if (!isset($moduleTableColumns['updated_at'])) {
    $conn->query("
        ALTER TABLE `modules`
        ADD COLUMN `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP
        AFTER `created_at`
    ");
}

$featureTableColumns = array();

$featureColumnsResult = $conn->query("
    SHOW COLUMNS FROM `module_features`
");

while ($featureColumn = $featureColumnsResult->fetch_assoc()) {
    $featureTableColumns[
        (string) $featureColumn['Field']
    ] = true;
}

$featureColumnsResult->free();

if (!isset($featureTableColumns['updated_by'])) {
    $conn->query("
        ALTER TABLE `module_features`
        ADD COLUMN `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL
        AFTER `is_active`
    ");
}

if (!isset($featureTableColumns['created_at'])) {
    $conn->query("
        ALTER TABLE `module_features`
        ADD COLUMN `created_at`
            DATETIME NOT NULL
            DEFAULT CURRENT_TIMESTAMP
        AFTER `updated_by`
    ");
}

if (!isset($featureTableColumns['updated_at'])) {
    $conn->query("
        ALTER TABLE `module_features`
        ADD COLUMN `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP
        AFTER `created_at`
    ");
}

/*
|--------------------------------------------------------------------------
| Seed default modules when table is empty
|--------------------------------------------------------------------------
*/

$countResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM modules
");

$countRow = $countResult->fetch_assoc();
$countResult->free();

if (empty($countRow['total'])) {
    $defaultModules = array(
        array('dashboard', 'Dashboard', 'bi bi-grid', 'dashboard.php', 1, 1),
        array('clients', 'Clients', 'bi bi-people', 'clients.php', 10, 1),
        array('requests', 'Requests', 'bi bi-inbox', 'requests.php', 20, 0),
        array('jobs', 'Jobs', 'bi bi-briefcase', 'jobs.php', 30, 1),
        array('work_orders', 'Work Orders', 'bi bi-clipboard-check', 'work-orders.php', 40, 0),
        array('visits', 'Visits', 'bi bi-geo-alt', 'visits.php', 50, 0),
        array('scheduling', 'Scheduling', 'bi bi-calendar3', 'scheduling.php', 60, 0),
        array('routes', 'Routes', 'bi bi-signpost-split', 'routes.php', 70, 0),
        array('tasks', 'Tasks', 'bi bi-check2-square', 'tasks.php', 80, 0),
        array('quotes', 'Quotes', 'bi bi-file-earmark-text', 'quotes.php', 90, 0),
        array('invoices', 'Invoices', 'bi bi-receipt', 'invoices.php', 100, 0),
        array('payments', 'Payments', 'bi bi-cash-stack', 'payments.php', 110, 0),
        array('workers', 'Workers', 'bi bi-person-badge', 'workers.php', 120, 0),
        array('users', 'Users', 'bi bi-person-gear', 'users.php', 130, 1),
        array('roles', 'Roles & Permissions', 'bi bi-shield-lock', 'roles.php', 140, 1),
        array('reports', 'Reports', 'bi bi-bar-chart', 'reports.php', 150, 0),
        array('messages', 'Messages', 'bi bi-chat-dots', 'messages.php', 160, 0),
        array('notifications', 'Notifications', 'bi bi-bell', 'notifications.php', 170, 0),
        array('settings', 'Settings', 'bi bi-gear', 'settings.php', 180, 1)
    );

    $seedStmt = $conn->prepare("
        INSERT INTO modules (
            `module_code`,
            `module_name`,
            `icon_class`,
            `menu_url`,
            `menu_order`,
            `is_core`,
            `is_active`,
            `created_at`
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            1,
            NOW()
        )
    ");

    foreach ($defaultModules as $module) {
        $seedStmt->bind_param(
            'ssssii',
            $module[0],
            $module[1],
            $module[2],
            $module[3],
            $module[4],
            $module[5]
        );

        $seedStmt->execute();
    }

    $seedStmt->close();
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $action = modulesPost('action');
    $moduleId = (int) modulesPost('module_id', '0');

    try {
        if (
            in_array(
                $action,
                array('activate', 'deactivate'),
                true
            ) &&
            $moduleId > 0
        ) {
            $newStatus =
                $action === 'activate'
                    ? 1
                    : 0;

            $updatedBy =
                modulesCurrentUserId();

            $stmt = $conn->prepare("
                UPDATE modules
                SET
                    `is_active` = ?,
                    `updated_by` = ?,
                    `updated_at` = NOW()
                WHERE `id` = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'iii',
                $newStatus,
                $updatedBy,
                $moduleId
            );

            $stmt->execute();
            $stmt->close();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                $newStatus === 1
                    ? 'Module activated successfully.'
                    : 'Module deactivated successfully.';

            header(
                'Location: ' .
                modulesBuildUrl(),
                true,
                303
            );

            exit;
        }

        if (
            $action === 'delete' &&
            $moduleId > 0
        ) {
            $moduleStmt = $conn->prepare("
                SELECT
                    `is_core`,
                    `module_name`
                FROM modules
                WHERE `id` = ?
                LIMIT 1
            ");

            $moduleStmt->bind_param(
                'i',
                $moduleId
            );

            $moduleStmt->execute();

            $moduleRecord = $moduleStmt
                ->get_result()
                ->fetch_assoc();

            $moduleStmt->close();

            if (!$moduleRecord) {
                throw new RuntimeException(
                    'Module not found.'
                );
            }

            if ((int) $moduleRecord['is_core'] === 1) {
                throw new RuntimeException(
                    'Core modules cannot be deleted.'
                );
            }

            $conn->begin_transaction();

            foreach (
                array(
                    'tenant_features' => 'feature_id',
                    'plan_features' => 'feature_id'
                ) as $tableName => $columnName
            ) {
                $tableCheck = $conn->query("
                    SELECT COUNT(*) AS total
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                      AND table_name = '" .
                    $conn->real_escape_string($tableName) .
                    "'
                ");

                $tableRow = $tableCheck->fetch_assoc();
                $tableCheck->free();

                if (!empty($tableRow['total'])) {
                    $conn->query("
                        DELETE target
                        FROM `{$tableName}` target
                        INNER JOIN module_features mf
                            ON mf.`id` =
                               target.`{$columnName}`
                        WHERE mf.`module_id` = " .
                        (int) $moduleId
                    );
                }
            }

            foreach (
                array(
                    'tenant_modules',
                    'plan_modules'
                ) as $tableName
            ) {
                $tableCheck = $conn->query("
                    SELECT COUNT(*) AS total
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                      AND table_name = '" .
                    $conn->real_escape_string($tableName) .
                    "'
                ");

                $tableRow = $tableCheck->fetch_assoc();
                $tableCheck->free();

                if (!empty($tableRow['total'])) {
                    $deleteRelationStmt =
                        $conn->prepare("
                            DELETE FROM `{$tableName}`
                            WHERE `module_id` = ?
                        ");

                    $deleteRelationStmt->bind_param(
                        'i',
                        $moduleId
                    );

                    $deleteRelationStmt->execute();
                    $deleteRelationStmt->close();
                }
            }

            $deleteFeaturesStmt =
                $conn->prepare("
                    DELETE FROM module_features
                    WHERE `module_id` = ?
                ");

            $deleteFeaturesStmt->bind_param(
                'i',
                $moduleId
            );

            $deleteFeaturesStmt->execute();
            $deleteFeaturesStmt->close();

            $deleteModuleStmt =
                $conn->prepare("
                    DELETE FROM modules
                    WHERE `id` = ?
                      AND `is_core` = 0
                    LIMIT 1
                ");

            $deleteModuleStmt->bind_param(
                'i',
                $moduleId
            );

            $deleteModuleStmt->execute();
            $deleteModuleStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Module deleted successfully.';

            header(
                'Location: modules.php',
                true,
                303
            );

            exit;
        }
    } catch (Exception $exception) {
        if ($conn->errno === 0) {
            try {
                $conn->rollback();
            } catch (Exception $ignored) {
                // No open transaction.
            }
        }

        error_log(
            'Module action failed: ' .
            $exception->getMessage()
        );

        $errorMessage =
            'Unable to complete the module action: ' .
            $exception->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = modulesGet('search');
$status = modulesGet('status', 'all');
$type = modulesGet('type', 'all');
$sort = modulesGet('sort', 'order');

$allowedStatuses = array(
    'all',
    'active',
    'inactive'
);

$allowedTypes = array(
    'all',
    'core',
    'optional'
);

$allowedSorts = array(
    'order',
    'name',
    'newest',
    'features'
);

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

if (!in_array($type, $allowedTypes, true)) {
    $type = 'all';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'order';
}

$page = max(
    1,
    (int) modulesGet('page', '1')
);

$perPage = (int) modulesGet(
    'per_page',
    '20'
);

if (
    !in_array(
        $perPage,
        array(10, 20, 50, 100),
        true
    )
) {
    $perPage = 20;
}

/*
|--------------------------------------------------------------------------
| Build list query
|--------------------------------------------------------------------------
*/

$where = array('1 = 1');
$params = array();
$types = '';

if ($search !== '') {
    $where[] = "(
        m.`module_name` LIKE ?
        OR m.`module_code` LIKE ?
        OR m.`description` LIKE ?
        OR m.`menu_url` LIKE ?
    )";

    $searchValue = '%' . $search . '%';

    for ($i = 0; $i < 4; $i++) {
        $params[] = $searchValue;
        $types .= 's';
    }
}

if ($status === 'active') {
    $where[] = "m.`is_active` = 1";
} elseif ($status === 'inactive') {
    $where[] = "m.`is_active` = 0";
}

if ($type === 'core') {
    $where[] = "m.`is_core` = 1";
} elseif ($type === 'optional') {
    $where[] = "m.`is_core` = 0";
}

$orderBy = "m.`menu_order` ASC, m.`module_name` ASC";

if ($sort === 'name') {
    $orderBy = "m.`module_name` ASC";
} elseif ($sort === 'newest') {
    $orderBy = "m.`created_at` DESC";
} elseif ($sort === 'features') {
    $orderBy = "feature_count DESC, m.`module_name` ASC";
}

$whereSql = implode(
    ' AND ',
    $where
);

$countSql = "
    SELECT COUNT(*) AS total
    FROM modules m
    WHERE {$whereSql}
";

$countStmt = $conn->prepare($countSql);

if (!empty($params)) {
    $bind = array($types);

    foreach ($params as $key => $value) {
        $bind[] = &$params[$key];
    }

    call_user_func_array(
        array($countStmt, 'bind_param'),
        $bind
    );
}

$countStmt->execute();

$totalRows = (int) $countStmt
    ->get_result()
    ->fetch_assoc()['total'];

$countStmt->close();

$totalPages = max(
    1,
    (int) ceil(
        $totalRows / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$listSql = "
    SELECT
        m.*,
        COUNT(mf.`id`) AS feature_count,
        SUM(
            CASE
                WHEN mf.`is_active` = 1
                THEN 1 ELSE 0
            END
        ) AS active_feature_count
    FROM modules m
    LEFT JOIN module_features mf
        ON mf.`module_id` = m.`id`
    WHERE {$whereSql}
    GROUP BY m.`id`
    ORDER BY {$orderBy}
    LIMIT ?
    OFFSET ?
";

$listParams = $params;
$listTypes = $types . 'ii';
$listParams[] = $perPage;
$listParams[] = $offset;

$listStmt = $conn->prepare($listSql);

$bind = array($listTypes);

foreach ($listParams as $key => $value) {
    $bind[] = &$listParams[$key];
}

call_user_func_array(
    array($listStmt, 'bind_param'),
    $bind
);

$listStmt->execute();

$modulesResult = $listStmt->get_result();
$modules = array();

while ($row = $modulesResult->fetch_assoc()) {
    $modules[] = $row;
}

$listStmt->close();

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summaryResult = $conn->query("
    SELECT
        COUNT(*) AS total_modules,
        SUM(
            CASE
                WHEN `is_active` = 1
                THEN 1 ELSE 0
            END
        ) AS active_modules,
        SUM(
            CASE
                WHEN `is_core` = 1
                THEN 1 ELSE 0
            END
        ) AS core_modules
    FROM modules
");

$summary = $summaryResult->fetch_assoc();
$summaryResult->free();

$featureSummaryResult = $conn->query("
    SELECT
        COUNT(*) AS total_features,
        SUM(
            CASE
                WHEN `is_active` = 1
                THEN 1 ELSE 0
            END
        ) AS active_features
    FROM module_features
");

$featureSummary =
    $featureSummaryResult->fetch_assoc();

$featureSummaryResult->free();

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['platform_success_message'])) {
    $successMessage =
        (string) $_SESSION['platform_success_message'];

    unset($_SESSION['platform_success_message']);
}

if (!empty($_SESSION['platform_error_message'])) {
    $errorMessage =
        (string) $_SESSION['platform_error_message'];

    unset($_SESSION['platform_error_message']);
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .modules-page {
        display: grid;
        gap: 15px;
    }

    .modules-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .modules-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .modules-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .modules-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .modules-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .modules-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .modules-button {
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

    .modules-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .modules-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .modules-summary {
        display: grid;
        grid-template-columns:
            repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .modules-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
    }

    .modules-summary-icon {
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

    .modules-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .modules-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .modules-filter-card {
        padding: 12px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
    }

    .modules-filter-grid {
        display: grid;
        grid-template-columns:
            minmax(220px, 1fr)
            150px
            150px
            170px
            100px;
        gap: 8px;
    }

    .modules-control {
        min-height: 38px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        color: #374151;
        font-size: 9px;
    }

    .modules-table-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .modules-table-wrap {
        overflow-x: auto;
    }

    .modules-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .modules-table th {
        padding: 11px 12px;
        border-bottom: 1px solid #e5e7eb;
        background: #fafafa;
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        text-align: left;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .modules-table td {
        padding: 11px 12px;
        border-bottom: 1px solid #f1f3f5;
        color: #374151;
        font-size: 9px;
        vertical-align: middle;
    }

    .modules-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .module-identity {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 220px;
    }

    .module-icon {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 13px;
    }

    .module-name {
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 800;
    }

    .module-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-badge {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 7px;
        font-weight: 700;
        white-space: nowrap;
    }

    .module-badge.active {
        background: #ecfdf5;
        color: #047857;
    }

    .module-badge.inactive {
        background: #fef2f2;
        color: #b91c1c;
    }

    .module-badge.core {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .module-badge.optional {
        background: #f3f4f6;
        color: #4b5563;
    }

    .module-actions {
        display: flex;
        align-items: center;
        gap: 5px;
        justify-content: flex-end;
    }

    .module-action {
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
        text-decoration: none;
        font-size: 11px;
        cursor: pointer;
    }

    .module-action:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .module-action.danger:hover {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .modules-empty {
        padding: 42px 20px;
        color: #9ca3af;
        font-size: 10px;
        text-align: center;
    }

    .modules-pagination {
        padding: 11px 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #eef0f3;
    }

    .modules-pagination-info {
        color: #6b7280;
        font-size: 8px;
    }

    .modules-pagination-links {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .modules-page-link {
        min-width: 29px;
        height: 29px;
        padding: 0 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        background: #ffffff;
        color: #4b5563;
        font-size: 8px;
        font-weight: 700;
        text-decoration: none;
    }

    .modules-page-link.active {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    @media (max-width: 1050px) {
        .modules-filter-grid {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

        .modules-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 650px) {
        .modules-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .modules-button {
            width: 100%;
        }

        .modules-filter-grid,
        .modules-summary {
            grid-template-columns: 1fr;
        }

        .modules-pagination {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<div class="modules-page">

    <?php if ($successMessage !== ''): ?>
        <div class="modules-alert success">
            <i class="bi bi-check-circle"></i>

            <span>
                <?= modulesEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="modules-alert danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= modulesEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="modules-header">
        <div>
            <h2 class="modules-title">
                Modules
            </h2>

            <div class="modules-description">
                Manage the master modules and features available across FieldPlx plans and tenants.
            </div>
        </div>

        <a
            href="module-add.php"
            class="modules-button primary"
        >
            <i class="bi bi-plus-circle"></i>
            Add Module
        </a>
    </div>

    <section class="modules-summary">
        <article class="modules-summary-card">
            <span class="modules-summary-icon">
                <i class="bi bi-grid-3x3-gap"></i>
            </span>

            <span>
                <span class="modules-summary-label">
                    Total Modules
                </span>

                <span class="modules-summary-value">
                    <?= number_format(
                        (int) $summary['total_modules']
                    ); ?>
                </span>
            </span>
        </article>

        <article class="modules-summary-card">
            <span class="modules-summary-icon">
                <i class="bi bi-check-circle"></i>
            </span>

            <span>
                <span class="modules-summary-label">
                    Active Modules
                </span>

                <span class="modules-summary-value">
                    <?= number_format(
                        (int) $summary['active_modules']
                    ); ?>
                </span>
            </span>
        </article>

        <article class="modules-summary-card">
            <span class="modules-summary-icon">
                <i class="bi bi-shield-check"></i>
            </span>

            <span>
                <span class="modules-summary-label">
                    Core Modules
                </span>

                <span class="modules-summary-value">
                    <?= number_format(
                        (int) $summary['core_modules']
                    ); ?>
                </span>
            </span>
        </article>

        <article class="modules-summary-card">
            <span class="modules-summary-icon">
                <i class="bi bi-ui-checks"></i>
            </span>

            <span>
                <span class="modules-summary-label">
                    Active Features
                </span>

                <span class="modules-summary-value">
                    <?= number_format(
                        (int) $featureSummary[
                            'active_features'
                        ]
                    ); ?>
                </span>
            </span>
        </article>
    </section>

    <form
        method="get"
        action="modules.php"
        class="modules-filter-card"
        id="modulesFilterForm"
    >
        <div class="modules-filter-grid">
            <input
                type="search"
                name="search"
                class="form-control modules-control"
                value="<?= modulesEscape($search); ?>"
                placeholder="Search module name, code, URL..."
            >

            <select
                name="status"
                class="form-select modules-control auto-submit"
            >
                <option value="all" <?= $status === 'all' ? 'selected' : ''; ?>>
                    All Statuses
                </option>
                <option value="active" <?= $status === 'active' ? 'selected' : ''; ?>>
                    Active
                </option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : ''; ?>>
                    Inactive
                </option>
            </select>

            <select
                name="type"
                class="form-select modules-control auto-submit"
            >
                <option value="all" <?= $type === 'all' ? 'selected' : ''; ?>>
                    All Types
                </option>
                <option value="core" <?= $type === 'core' ? 'selected' : ''; ?>>
                    Core
                </option>
                <option value="optional" <?= $type === 'optional' ? 'selected' : ''; ?>>
                    Optional
                </option>
            </select>

            <select
                name="sort"
                class="form-select modules-control auto-submit"
            >
                <option value="order" <?= $sort === 'order' ? 'selected' : ''; ?>>
                    Menu Order
                </option>
                <option value="name" <?= $sort === 'name' ? 'selected' : ''; ?>>
                    Name
                </option>
                <option value="newest" <?= $sort === 'newest' ? 'selected' : ''; ?>>
                    Newest
                </option>
                <option value="features" <?= $sort === 'features' ? 'selected' : ''; ?>>
                    Feature Count
                </option>
            </select>

            <select
                name="per_page"
                class="form-select modules-control auto-submit"
            >
                <?php foreach (
                    array(10, 20, 50, 100) as
                    $pageSize
                ): ?>
                    <option
                        value="<?= (int) $pageSize; ?>"
                        <?= $perPage === $pageSize
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= (int) $pageSize; ?> rows
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <section class="modules-table-card">
        <div class="modules-table-wrap">
            <table class="modules-table">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>Menu URL</th>
                        <th>Order</th>
                        <th>Features</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($modules)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="modules-empty">
                                    No modules matched the selected filters.
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($modules as $module): ?>
                            <tr>
                                <td>
                                    <div class="module-identity">
                                        <span class="module-icon">
                                            <i class="<?= modulesEscape(
                                                !empty($module['icon_class'])
                                                    ? $module['icon_class']
                                                    : 'bi bi-grid'
                                            ); ?>"></i>
                                        </span>

                                        <span>
                                            <span class="module-name">
                                                <?= modulesEscape(
                                                    $module['module_name']
                                                ); ?>
                                            </span>

                                            <span class="module-code">
                                                <?= modulesEscape(
                                                    $module['module_code']
                                                ); ?>
                                            </span>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <?= modulesEscape(
                                        !empty($module['menu_url'])
                                            ? $module['menu_url']
                                            : '—'
                                    ); ?>
                                </td>

                                <td>
                                    <?= (int) $module['menu_order']; ?>
                                </td>

                                <td>
                                    <a
                                        href="module-features.php?module_id=<?= (int) $module['id']; ?>"
                                        class="modules-button"
                                        style="min-height:29px;padding:4px 8px;"
                                    >
                                        <?= number_format(
                                            (int) $module['active_feature_count']
                                        ); ?>
                                        /
                                        <?= number_format(
                                            (int) $module['feature_count']
                                        ); ?>
                                    </a>
                                </td>

                                <td>
                                    <span class="module-badge <?= (int) $module['is_core'] === 1
                                        ? 'core'
                                        : 'optional'; ?>">
                                        <?= (int) $module['is_core'] === 1
                                            ? 'Core'
                                            : 'Optional'; ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="module-badge <?= (int) $module['is_active'] === 1
                                        ? 'active'
                                        : 'inactive'; ?>">
                                        <?= (int) $module['is_active'] === 1
                                            ? 'Active'
                                            : 'Inactive'; ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="module-actions">
                                        <a
                                            href="module-view.php?id=<?= (int) $module['id']; ?>"
                                            class="module-action"
                                            title="View"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <a
                                            href="module-edit.php?id=<?= (int) $module['id']; ?>"
                                            class="module-action"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <a
                                            href="module-features.php?module_id=<?= (int) $module['id']; ?>"
                                            class="module-action"
                                            title="Features"
                                        >
                                            <i class="bi bi-ui-checks"></i>
                                        </a>

                                        <form
                                            method="post"
                                            action="<?= modulesEscape(
                                                modulesBuildUrl()
                                            ); ?>"
                                            style="display:inline;"
                                        >
                                            <?php csrfField(); ?>

                                            <input
                                                type="hidden"
                                                name="module_id"
                                                value="<?= (int) $module['id']; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="<?= (int) $module['is_active'] === 1
                                                    ? 'deactivate'
                                                    : 'activate'; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="module-action"
                                                title="<?= (int) $module['is_active'] === 1
                                                    ? 'Deactivate'
                                                    : 'Activate'; ?>"
                                            >
                                                <i class="bi <?= (int) $module['is_active'] === 1
                                                    ? 'bi-pause-circle'
                                                    : 'bi-play-circle'; ?>"></i>
                                            </button>
                                        </form>

                                        <?php if ((int) $module['is_core'] !== 1): ?>
                                            <form
                                                method="post"
                                                action="modules.php"
                                                style="display:inline;"
                                                onsubmit="return confirm('Delete this module and all its feature assignments?');"
                                            >
                                                <?php csrfField(); ?>

                                                <input
                                                    type="hidden"
                                                    name="module_id"
                                                    value="<?= (int) $module['id']; ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <button
                                                    type="submit"
                                                    class="module-action danger"
                                                    title="Delete"
                                                >
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalRows > 0): ?>
            <div class="modules-pagination">
                <div class="modules-pagination-info">
                    Showing
                    <?= number_format(
                        $offset + 1
                    ); ?>
                    to
                    <?= number_format(
                        min(
                            $offset + $perPage,
                            $totalRows
                        )
                    ); ?>
                    of
                    <?= number_format($totalRows); ?>
                    modules
                </div>

                <div class="modules-pagination-links">
                    <?php if ($page > 1): ?>
                        <a
                            href="<?= modulesEscape(
                                modulesBuildUrl(
                                    array(
                                        'page' => $page - 1
                                    )
                                )
                            ); ?>"
                            class="modules-page-link"
                        >
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(
                        1,
                        $page - 2
                    );

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
                            href="<?= modulesEscape(
                                modulesBuildUrl(
                                    array(
                                        'page' =>
                                            $pageNumber
                                    )
                                )
                            ); ?>"
                            class="modules-page-link <?= $pageNumber === $page
                                ? 'active'
                                : ''; ?>"
                        >
                            <?= (int) $pageNumber; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a
                            href="<?= modulesEscape(
                                modulesBuildUrl(
                                    array(
                                        'page' => $page + 1
                                    )
                                )
                            ); ?>"
                            class="modules-page-link"
                        >
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

</div>

<script>
(function () {
    'use strict';

    const form =
        document.getElementById(
            'modulesFilterForm'
        );

    if (!form) {
        return;
    }

    form
        .querySelectorAll('.auto-submit')
        .forEach(function (control) {
            control.addEventListener(
                'change',
                function () {
                    form.submit();
                }
            );
        });

    const searchInput =
        form.querySelector(
            'input[name="search"]'
        );

    let searchTimer = null;

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            function () {
                clearTimeout(searchTimer);

                searchTimer = setTimeout(
                    function () {
                        form.submit();
                    },
                    500
                );
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
