<?php
/**
 * FieldPlx Platform - View Module
 *
 * File:
 * platform/module-view.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 */

require_once __DIR__ . '/includes/auth.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin',
    'platform_read_only'
));

$pageTitle = 'Module Details - FieldPlx';
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

if (!function_exists('moduleViewEscape')) {
    function moduleViewEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('moduleViewTableExists')) {
    function moduleViewTableExists(
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

        $stmt->bind_param(
            's',
            $tableName
        );

        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        $cache[$tableName] =
            !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('moduleViewColumns')) {
    function moduleViewColumns(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTableName = str_replace(
            '`',
            '``',
            $tableName
        );

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTableName}`"
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

if (!function_exists('moduleViewFirstColumn')) {
    function moduleViewFirstColumn(
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

if (!function_exists('moduleViewLabel')) {
    function moduleViewLabel($value)
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

if (!function_exists('moduleViewDate')) {
    function moduleViewDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime(
            (string) $value
        );

        if ($timestamp === false) {
            return '—';
        }

        return date(
            'd M Y, h:i A',
            $timestamp
        );
    }
}

/*
|--------------------------------------------------------------------------
| Verify tables
|--------------------------------------------------------------------------
*/

if (!moduleViewTableExists($conn, 'modules')) {
    http_response_code(500);
    exit('The modules table does not exist.');
}

$moduleColumns =
    moduleViewColumns(
        $conn,
        'modules'
    );

$moduleIdColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('id', 'module_id')
    );

$moduleCodeColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('module_code', 'code')
    );

$moduleNameColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('module_name', 'name')
    );

$moduleDescriptionColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array(
            'description',
            'notes',
            'remarks'
        )
    );

$moduleIconColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('icon_class', 'icon')
    );

$moduleMenuUrlColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('menu_url', 'url')
    );

$moduleMenuOrderColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('menu_order', 'sort_order')
    );

$moduleCoreColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('is_core', 'core')
    );

$moduleActiveColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('is_active', 'active')
    );

$moduleCreatedColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('created_at', 'created_on')
    );

$moduleUpdatedColumn =
    moduleViewFirstColumn(
        $moduleColumns,
        array('updated_at', 'updated_on')
    );

if (
    $moduleIdColumn === '' ||
    $moduleNameColumn === ''
) {
    http_response_code(500);
    exit('Required module columns are missing.');
}

/*
|--------------------------------------------------------------------------
| Module ID
|--------------------------------------------------------------------------
*/

$moduleId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($moduleId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid module record.';

    header('Location: modules.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load module
|--------------------------------------------------------------------------
*/

$select = array(
    "`{$moduleIdColumn}` AS module_id",
    "`{$moduleNameColumn}` AS module_name"
);

$select[] = $moduleCodeColumn !== ''
    ? "`{$moduleCodeColumn}` AS module_code"
    : "'' AS module_code";

$select[] = $moduleDescriptionColumn !== ''
    ? "`{$moduleDescriptionColumn}` AS module_description"
    : "'' AS module_description";

$select[] = $moduleIconColumn !== ''
    ? "`{$moduleIconColumn}` AS icon_class"
    : "'bi bi-grid' AS icon_class";

$select[] = $moduleMenuUrlColumn !== ''
    ? "`{$moduleMenuUrlColumn}` AS menu_url"
    : "'' AS menu_url";

$select[] = $moduleMenuOrderColumn !== ''
    ? "`{$moduleMenuOrderColumn}` AS menu_order"
    : "0 AS menu_order";

$select[] = $moduleCoreColumn !== ''
    ? "`{$moduleCoreColumn}` AS is_core"
    : "0 AS is_core";

$select[] = $moduleActiveColumn !== ''
    ? "`{$moduleActiveColumn}` AS is_active"
    : "1 AS is_active";

$select[] = $moduleCreatedColumn !== ''
    ? "`{$moduleCreatedColumn}` AS created_at"
    : "NULL AS created_at";

$select[] = $moduleUpdatedColumn !== ''
    ? "`{$moduleUpdatedColumn}` AS updated_at"
    : "NULL AS updated_at";

$moduleSql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM modules
    WHERE `{$moduleIdColumn}` = ?
    LIMIT 1
";

$moduleStmt = $conn->prepare($moduleSql);

$moduleStmt->bind_param(
    'i',
    $moduleId
);

$moduleStmt->execute();

$module = $moduleStmt
    ->get_result()
    ->fetch_assoc();

$moduleStmt->close();

if (!$module) {
    $_SESSION['platform_error_message'] =
        'Module not found.';

    header('Location: modules.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load features
|--------------------------------------------------------------------------
*/

$features = array();

$hasFeatureTable =
    moduleViewTableExists(
        $conn,
        'module_features'
    );

if ($hasFeatureTable) {
    $featureColumns =
        moduleViewColumns(
            $conn,
            'module_features'
        );

    $featureIdColumn =
        moduleViewFirstColumn(
            $featureColumns,
            array('id', 'feature_id')
        );

    $featureModuleColumn =
        moduleViewFirstColumn(
            $featureColumns,
            array('module_id')
        );

    $featureCodeColumn =
        moduleViewFirstColumn(
            $featureColumns,
            array('feature_code', 'code')
        );

    $featureNameColumn =
        moduleViewFirstColumn(
            $featureColumns,
            array('feature_name', 'name')
        );

    $featureDescriptionColumn =
        moduleViewFirstColumn(
            $featureColumns,
            array(
                'description',
                'notes',
                'remarks'
            )
        );

    $featureActiveColumn =
        moduleViewFirstColumn(
            $featureColumns,
            array('is_active', 'active')
        );

    $featureCreatedColumn =
        moduleViewFirstColumn(
            $featureColumns,
            array('created_at', 'created_on')
        );

    if (
        $featureIdColumn !== '' &&
        $featureModuleColumn !== '' &&
        $featureNameColumn !== ''
    ) {
        $featureSelect = array(
            "`{$featureIdColumn}` AS feature_id",
            "`{$featureModuleColumn}` AS module_id",
            "`{$featureNameColumn}` AS feature_name"
        );

        $featureSelect[] =
            $featureCodeColumn !== ''
                ? "`{$featureCodeColumn}` AS feature_code"
                : "'' AS feature_code";

        $featureSelect[] =
            $featureDescriptionColumn !== ''
                ? "`{$featureDescriptionColumn}` AS feature_description"
                : "'' AS feature_description";

        $featureSelect[] =
            $featureActiveColumn !== ''
                ? "`{$featureActiveColumn}` AS is_active"
                : "1 AS is_active";

        $featureSelect[] =
            $featureCreatedColumn !== ''
                ? "`{$featureCreatedColumn}` AS created_at"
                : "NULL AS created_at";

        $featureSql = "
            SELECT
                " . implode(",\n                ", $featureSelect) . "
            FROM module_features
            WHERE `{$featureModuleColumn}` = ?
            ORDER BY
                `{$featureNameColumn}` ASC
        ";

        $featureStmt =
            $conn->prepare(
                $featureSql
            );

        $featureStmt->bind_param(
            'i',
            $moduleId
        );

        $featureStmt->execute();

        $featureResult =
            $featureStmt->get_result();

        while (
            $featureRow =
            $featureResult->fetch_assoc()
        ) {
            $features[] = $featureRow;
        }

        $featureStmt->close();
    }
}

$totalFeatures = count($features);
$activeFeatures = 0;
$inactiveFeatures = 0;

foreach ($features as $feature) {
    if ((int) $feature['is_active'] === 1) {
        $activeFeatures++;
    } else {
        $inactiveFeatures++;
    }
}

/*
|--------------------------------------------------------------------------
| Plan and tenant usage
|--------------------------------------------------------------------------
*/

$planUsageCount = 0;
$tenantUsageCount = 0;

if (
    moduleViewTableExists($conn, 'plan_modules')
) {
    $planModuleColumns =
        moduleViewColumns(
            $conn,
            'plan_modules'
        );

    $planModuleModuleColumn =
        moduleViewFirstColumn(
            $planModuleColumns,
            array('module_id')
        );

    $planModuleEnabledColumn =
        moduleViewFirstColumn(
            $planModuleColumns,
            array('is_enabled', 'enabled')
        );

    if ($planModuleModuleColumn !== '') {
        $usageSql = "
            SELECT COUNT(DISTINCT `plan_id`) AS total
            FROM plan_modules
            WHERE `{$planModuleModuleColumn}` = ?
        ";

        if ($planModuleEnabledColumn !== '') {
            $usageSql .= "
                AND `{$planModuleEnabledColumn}` = 1
            ";
        }

        $usageStmt =
            $conn->prepare($usageSql);

        $usageStmt->bind_param(
            'i',
            $moduleId
        );

        $usageStmt->execute();

        $usageRow = $usageStmt
            ->get_result()
            ->fetch_assoc();

        $usageStmt->close();

        $planUsageCount =
            isset($usageRow['total'])
                ? (int) $usageRow['total']
                : 0;
    }
}

if (
    moduleViewTableExists($conn, 'tenant_modules')
) {
    $tenantModuleColumns =
        moduleViewColumns(
            $conn,
            'tenant_modules'
        );

    $tenantModuleModuleColumn =
        moduleViewFirstColumn(
            $tenantModuleColumns,
            array('module_id')
        );

    $tenantModuleAccessColumn =
        moduleViewFirstColumn(
            $tenantModuleColumns,
            array(
                'access_type',
                'is_enabled',
                'enabled'
            )
        );

    if ($tenantModuleModuleColumn !== '') {
        $usageSql = "
            SELECT COUNT(DISTINCT `tenant_id`) AS total
            FROM tenant_modules
            WHERE `{$tenantModuleModuleColumn}` = ?
        ";

        if ($tenantModuleAccessColumn === 'access_type') {
            $usageSql .= "
                AND `access_type` = 'enabled'
            ";
        } elseif ($tenantModuleAccessColumn !== '') {
            $usageSql .= "
                AND `{$tenantModuleAccessColumn}` = 1
            ";
        }

        $usageStmt =
            $conn->prepare($usageSql);

        $usageStmt->bind_param(
            'i',
            $moduleId
        );

        $usageStmt->execute();

        $usageRow = $usageStmt
            ->get_result()
            ->fetch_assoc();

        $usageStmt->close();

        $tenantUsageCount =
            isset($usageRow['total'])
                ? (int) $usageRow['total']
                : 0;
    }
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .module-view-page {
        max-width: 1120px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .module-view-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .module-view-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .module-view-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .module-view-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .module-view-button {
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

    .module-view-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .module-view-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .module-view-hero {
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background:
            linear-gradient(
                135deg,
                #ffffff,
                #faf8ff
            );
        box-shadow:
            0 6px 24px rgba(31, 41, 55, 0.04);
    }

    .module-view-icon {
        width: 72px;
        height: 72px;
        flex: 0 0 72px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 27px;
        box-shadow:
            0 8px 22px rgba(91, 33, 182, 0.18);
    }

    .module-view-hero-content {
        min-width: 0;
        flex: 1;
    }

    .module-view-name {
        margin: 0;
        color: #111827;
        font-size: 21px;
        font-weight: 800;
    }

    .module-view-code {
        margin-top: 4px;
        color: #7c3aed;
        font-size: 9px;
        font-weight: 700;
    }

    .module-view-badges {
        margin-top: 8px;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .module-view-badge {
        padding: 5px 8px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .module-view-badge.active {
        background: #ecfdf5;
        color: #047857;
    }

    .module-view-badge.inactive {
        background: #fef2f2;
        color: #b91c1c;
    }

    .module-view-badge.core {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .module-view-badge.optional {
        background: #f3f4f6;
        color: #4b5563;
    }

    .module-view-summary {
        display: grid;
        grid-template-columns:
            repeat(5, minmax(0, 1fr));
        gap: 10px;
    }

    .module-view-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
    }

    .module-view-summary-icon {
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

    .module-view-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .module-view-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .module-view-grid {
        display: grid;
        grid-template-columns:
            minmax(0, 1.35fr)
            minmax(290px, 0.8fr);
        gap: 15px;
        align-items: start;
    }

    .module-view-main,
    .module-view-side {
        display: grid;
        gap: 15px;
    }

    .module-view-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .module-view-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .module-view-card-icon {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 13px;
    }

    .module-view-card-content {
        min-width: 0;
        flex: 1;
    }

    .module-view-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .module-view-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-view-card-action {
        color: #7c3aed;
        font-size: 8px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }

    .module-view-card-body {
        padding: 15px;
    }

    .module-view-description-box {
        padding: 13px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
        color: #4b5563;
        font-size: 9px;
        line-height: 1.65;
    }

    .module-view-details {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .module-view-detail {
        padding: 11px 12px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .module-view-detail-label {
        display: block;
        color: #9ca3af;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .module-view-detail-value {
        margin-top: 4px;
        display: block;
        color: #374151;
        font-size: 10px;
        font-weight: 700;
        word-break: break-word;
    }

    .module-view-feature-list {
        display: grid;
        gap: 8px;
    }

    .module-view-feature {
        padding: 11px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .module-view-feature-icon {
        width: 31px;
        height: 31px;
        flex: 0 0 31px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #ede9fe;
        color: #6d28d9;
        font-size: 12px;
    }

    .module-view-feature-content {
        min-width: 0;
        flex: 1;
    }

    .module-view-feature-name {
        display: block;
        color: #111827;
        font-size: 9px;
        font-weight: 800;
    }

    .module-view-feature-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-view-feature-description {
        margin-top: 5px;
        display: block;
        color: #6b7280;
        font-size: 8px;
        line-height: 1.5;
    }

    .module-view-feature-status {
        padding: 4px 7px;
        border-radius: 999px;
        font-size: 7px;
        font-weight: 700;
        white-space: nowrap;
    }

    .module-view-feature-status.active {
        background: #ecfdf5;
        color: #047857;
    }

    .module-view-feature-status.inactive {
        background: #fef2f2;
        color: #b91c1c;
    }

    .module-view-empty {
        padding: 30px 15px;
        border: 1px dashed #e5e7eb;
        border-radius: 9px;
        color: #9ca3af;
        font-size: 9px;
        text-align: center;
    }

    .module-view-quick-links {
        display: grid;
        gap: 8px;
    }

    .module-view-quick-link {
        min-height: 40px;
        padding: 9px 11px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #ffffff;
        color: #4b5563;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none;
    }

    .module-view-quick-link:hover {
        border-color: #c4b5fd;
        background: #faf8ff;
        color: #7c3aed;
    }

    @media (max-width: 1050px) {
        .module-view-summary {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .module-view-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 650px) {
        .module-view-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .module-view-actions {
            width: 100%;
        }

        .module-view-button {
            flex: 1;
        }

        .module-view-hero {
            align-items: flex-start;
            flex-direction: column;
        }

        .module-view-summary,
        .module-view-details {
            grid-template-columns: 1fr;
        }

        .module-view-feature {
            flex-wrap: wrap;
        }
    }
</style>

<div class="module-view-page">

    <div class="module-view-header">
        <div>
            <h2 class="module-view-title">
                Module Details
            </h2>

            <div class="module-view-description">
                Review module settings, features, and usage across plans and tenants.
            </div>
        </div>

        <div class="module-view-actions">
            <a
                href="modules.php"
                class="module-view-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Modules
            </a>

            <?php if (
                hasPlatformRole(array(
                    'super_admin',
                    'platform_admin'
                ))
            ): ?>
                <a
                    href="module-features.php?module_id=<?= (int) $moduleId; ?>"
                    class="module-view-button"
                >
                    <i class="bi bi-ui-checks"></i>
                    Manage Features
                </a>

                <a
                    href="module-edit.php?id=<?= (int) $moduleId; ?>"
                    class="module-view-button primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit Module
                </a>
            <?php endif; ?>
        </div>
    </div>

    <section class="module-view-hero">
        <span class="module-view-icon">
            <i class="<?= moduleViewEscape(
                !empty($module['icon_class'])
                    ? $module['icon_class']
                    : 'bi bi-grid'
            ); ?>"></i>
        </span>

        <div class="module-view-hero-content">
            <h1 class="module-view-name">
                <?= moduleViewEscape(
                    $module['module_name']
                ); ?>
            </h1>

            <div class="module-view-code">
                <?= moduleViewEscape(
                    !empty($module['module_code'])
                        ? $module['module_code']
                        : 'MODULE-' . $moduleId
                ); ?>
            </div>

            <div class="module-view-badges">
                <span class="module-view-badge <?= (int) $module['is_active'] === 1
                    ? 'active'
                    : 'inactive'; ?>">
                    <i class="bi <?= (int) $module['is_active'] === 1
                        ? 'bi-check-circle'
                        : 'bi-slash-circle'; ?>"></i>

                    <?= (int) $module['is_active'] === 1
                        ? 'Active'
                        : 'Inactive'; ?>
                </span>

                <span class="module-view-badge <?= (int) $module['is_core'] === 1
                    ? 'core'
                    : 'optional'; ?>">
                    <i class="bi <?= (int) $module['is_core'] === 1
                        ? 'bi-shield-check'
                        : 'bi-grid'; ?>"></i>

                    <?= (int) $module['is_core'] === 1
                        ? 'Core Module'
                        : 'Optional Module'; ?>
                </span>
            </div>
        </div>
    </section>

    <section class="module-view-summary">
        <article class="module-view-summary-card">
            <span class="module-view-summary-icon">
                <i class="bi bi-ui-checks"></i>
            </span>

            <span>
                <span class="module-view-summary-label">
                    Total Features
                </span>

                <span class="module-view-summary-value">
                    <?= number_format(
                        $totalFeatures
                    ); ?>
                </span>
            </span>
        </article>

        <article class="module-view-summary-card">
            <span class="module-view-summary-icon">
                <i class="bi bi-check-circle"></i>
            </span>

            <span>
                <span class="module-view-summary-label">
                    Active Features
                </span>

                <span class="module-view-summary-value">
                    <?= number_format(
                        $activeFeatures
                    ); ?>
                </span>
            </span>
        </article>

        <article class="module-view-summary-card">
            <span class="module-view-summary-icon">
                <i class="bi bi-pause-circle"></i>
            </span>

            <span>
                <span class="module-view-summary-label">
                    Inactive Features
                </span>

                <span class="module-view-summary-value">
                    <?= number_format(
                        $inactiveFeatures
                    ); ?>
                </span>
            </span>
        </article>

        <article class="module-view-summary-card">
            <span class="module-view-summary-icon">
                <i class="bi bi-card-list"></i>
            </span>

            <span>
                <span class="module-view-summary-label">
                    Included Plans
                </span>

                <span class="module-view-summary-value">
                    <?= number_format(
                        $planUsageCount
                    ); ?>
                </span>
            </span>
        </article>

        <article class="module-view-summary-card">
            <span class="module-view-summary-icon">
                <i class="bi bi-building"></i>
            </span>

            <span>
                <span class="module-view-summary-label">
                    Tenant Overrides
                </span>

                <span class="module-view-summary-value">
                    <?= number_format(
                        $tenantUsageCount
                    ); ?>
                </span>
            </span>
        </article>
    </section>

    <div class="module-view-grid">

        <main class="module-view-main">

            <section class="module-view-card">
                <div class="module-view-card-header">
                    <span class="module-view-card-icon">
                        <i class="bi bi-info-circle"></i>
                    </span>

                    <div class="module-view-card-content">
                        <h3 class="module-view-card-title">
                            Module Description
                        </h3>

                        <div class="module-view-card-subtitle">
                            Purpose and business capability
                        </div>
                    </div>
                </div>

                <div class="module-view-card-body">
                    <div class="module-view-description-box">
                        <?= nl2br(
                            moduleViewEscape(
                                !empty(
                                    $module['module_description']
                                )
                                    ? $module[
                                        'module_description'
                                    ]
                                    : 'No description has been added for this module.'
                            )
                        ); ?>
                    </div>
                </div>
            </section>

            <section class="module-view-card">
                <div class="module-view-card-header">
                    <span class="module-view-card-icon">
                        <i class="bi bi-ui-checks"></i>
                    </span>

                    <div class="module-view-card-content">
                        <h3 class="module-view-card-title">
                            Module Features
                        </h3>

                        <div class="module-view-card-subtitle">
                            Individual capabilities inside this module
                        </div>
                    </div>

                    <?php if (
                        hasPlatformRole(array(
                            'super_admin',
                            'platform_admin'
                        ))
                    ): ?>
                        <a
                            href="module-features.php?module_id=<?= (int) $moduleId; ?>"
                            class="module-view-card-action"
                        >
                            Manage Features
                        </a>
                    <?php endif; ?>
                </div>

                <div class="module-view-card-body">
                    <?php if (empty($features)): ?>
                        <div class="module-view-empty">
                            No features have been configured for this module.
                        </div>
                    <?php else: ?>
                        <div class="module-view-feature-list">
                            <?php foreach ($features as $feature): ?>
                                <article class="module-view-feature">
                                    <span class="module-view-feature-icon">
                                        <i class="bi bi-check2-square"></i>
                                    </span>

                                    <span class="module-view-feature-content">
                                        <span class="module-view-feature-name">
                                            <?= moduleViewEscape(
                                                $feature[
                                                    'feature_name'
                                                ]
                                            ); ?>
                                        </span>

                                        <span class="module-view-feature-code">
                                            <?= moduleViewEscape(
                                                !empty(
                                                    $feature[
                                                        'feature_code'
                                                    ]
                                                )
                                                    ? $feature[
                                                        'feature_code'
                                                    ]
                                                    : 'FEATURE-' .
                                                      (int)
                                                      $feature[
                                                        'feature_id'
                                                      ]
                                            ); ?>
                                        </span>

                                        <?php if (
                                            !empty(
                                                $feature[
                                                    'feature_description'
                                                ]
                                            )
                                        ): ?>
                                            <span class="module-view-feature-description">
                                                <?= moduleViewEscape(
                                                    $feature[
                                                        'feature_description'
                                                    ]
                                                ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>

                                    <span class="module-view-feature-status <?= (int) $feature['is_active'] === 1
                                        ? 'active'
                                        : 'inactive'; ?>">
                                        <?= (int) $feature['is_active'] === 1
                                            ? 'Active'
                                            : 'Inactive'; ?>
                                    </span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        </main>

        <aside class="module-view-side">

            <section class="module-view-card">
                <div class="module-view-card-header">
                    <span class="module-view-card-icon">
                        <i class="bi bi-sliders"></i>
                    </span>

                    <div class="module-view-card-content">
                        <h3 class="module-view-card-title">
                            Module Settings
                        </h3>

                        <div class="module-view-card-subtitle">
                            Menu and access configuration
                        </div>
                    </div>
                </div>

                <div class="module-view-card-body">
                    <div class="module-view-details">
                        <div class="module-view-detail">
                            <span class="module-view-detail-label">
                                Module ID
                            </span>

                            <span class="module-view-detail-value">
                                #<?= (int) $moduleId; ?>
                            </span>
                        </div>

                        <div class="module-view-detail">
                            <span class="module-view-detail-label">
                                Menu Order
                            </span>

                            <span class="module-view-detail-value">
                                <?= number_format(
                                    (int)
                                    $module['menu_order']
                                ); ?>
                            </span>
                        </div>

                        <div class="module-view-detail">
                            <span class="module-view-detail-label">
                                Menu URL
                            </span>

                            <span class="module-view-detail-value">
                                <?= moduleViewEscape(
                                    !empty($module['menu_url'])
                                        ? $module['menu_url']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="module-view-detail">
                            <span class="module-view-detail-label">
                                Icon Class
                            </span>

                            <span class="module-view-detail-value">
                                <?= moduleViewEscape(
                                    !empty($module['icon_class'])
                                        ? $module['icon_class']
                                        : 'bi bi-grid'
                                ); ?>
                            </span>
                        </div>

                        <div class="module-view-detail">
                            <span class="module-view-detail-label">
                                Module Type
                            </span>

                            <span class="module-view-detail-value">
                                <?= (int) $module['is_core'] === 1
                                    ? 'Core'
                                    : 'Optional'; ?>
                            </span>
                        </div>

                        <div class="module-view-detail">
                            <span class="module-view-detail-label">
                                Status
                            </span>

                            <span class="module-view-detail-value">
                                <?= (int) $module['is_active'] === 1
                                    ? 'Active'
                                    : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="module-view-card">
                <div class="module-view-card-header">
                    <span class="module-view-card-icon">
                        <i class="bi bi-clock-history"></i>
                    </span>

                    <div class="module-view-card-content">
                        <h3 class="module-view-card-title">
                            Record Information
                        </h3>

                        <div class="module-view-card-subtitle">
                            Creation and modification details
                        </div>
                    </div>
                </div>

                <div class="module-view-card-body">
                    <div class="module-view-details">
                        <div class="module-view-detail">
                            <span class="module-view-detail-label">
                                Created
                            </span>

                            <span class="module-view-detail-value">
                                <?= moduleViewEscape(
                                    moduleViewDate(
                                        $module['created_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="module-view-detail">
                            <span class="module-view-detail-label">
                                Last Updated
                            </span>

                            <span class="module-view-detail-value">
                                <?= moduleViewEscape(
                                    moduleViewDate(
                                        $module['updated_at']
                                    )
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="module-view-card">
                <div class="module-view-card-header">
                    <span class="module-view-card-icon">
                        <i class="bi bi-lightning-charge"></i>
                    </span>

                    <div class="module-view-card-content">
                        <h3 class="module-view-card-title">
                            Quick Actions
                        </h3>

                        <div class="module-view-card-subtitle">
                            Related module management pages
                        </div>
                    </div>
                </div>

                <div class="module-view-card-body">
                    <div class="module-view-quick-links">

                        <?php if (
                            hasPlatformRole(array(
                                'super_admin',
                                'platform_admin'
                            ))
                        ): ?>
                            <a
                                href="module-edit.php?id=<?= (int) $moduleId; ?>"
                                class="module-view-quick-link"
                            >
                                <i class="bi bi-pencil-square"></i>
                                Edit Module
                            </a>

                            <a
                                href="module-features.php?module_id=<?= (int) $moduleId; ?>"
                                class="module-view-quick-link"
                            >
                                <i class="bi bi-ui-checks"></i>
                                Manage Module Features
                            </a>
                        <?php endif; ?>

                        <a
                            href="plan-modules.php"
                            class="module-view-quick-link"
                        >
                            <i class="bi bi-card-list"></i>
                            Configure Plan Access
                        </a>

                        <a
                            href="modules.php"
                            class="module-view-quick-link"
                        >
                            <i class="bi bi-grid-3x3-gap"></i>
                            View All Modules
                        </a>

                    </div>
                </div>
            </section>

        </aside>

    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
