<?php
/**
 * FieldPlx Platform - Tenant Modules & Features
 *
 * File:
 * platform/tenant-modules.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 *
 * Required tables:
 * - tenants
 * - subscriptions
 * - plans
 * - modules
 * - module_features
 * - plan_modules
 * - plan_features
 * - tenant_modules
 * - tenant_features
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin'
));

$pageTitle = 'Tenant Modules & Features - FieldPlx';
$activePage = 'tenant-modules';
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

if (!function_exists('tenantModulesEscape')) {
    function tenantModulesEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantModulesTableExists')) {
    function tenantModulesTableExists(
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

if (!function_exists('tenantModulesColumns')) {
    function tenantModulesColumns(
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
            $cache[$tableName][
                (string) $row['Field']
            ] = $row;
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('tenantModulesFirstColumn')) {
    function tenantModulesFirstColumn(
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

if (!function_exists('tenantModulesLabel')) {
    function tenantModulesLabel($value)
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

if (!function_exists('tenantModulesCurrentUserId')) {
    function tenantModulesCurrentUserId()
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

if (!function_exists('tenantModulesResolveAccess')) {
    function tenantModulesResolveAccess(
        $override,
        $planEnabled
    ) {
        $override = strtolower(
            trim((string) $override)
        );

        if ($override === 'enabled') {
            return true;
        }

        if ($override === 'disabled') {
            return false;
        }

        return (int) $planEnabled === 1;
    }
}

/*
|--------------------------------------------------------------------------
| Required tables
|--------------------------------------------------------------------------
*/

$requiredTables = array(
    'tenants',
    'subscriptions',
    'plans',
    'modules',
    'module_features',
    'plan_modules',
    'plan_features',
    'tenant_modules',
    'tenant_features'
);

$missingTables = array();

foreach ($requiredTables as $requiredTable) {
    if (
        !tenantModulesTableExists(
            $conn,
            $requiredTable
        )
    ) {
        $missingTables[] = $requiredTable;
    }
}

if (!empty($missingTables)) {
    http_response_code(500);

    exit(
        'Missing required tables: ' .
        tenantModulesEscape(
            implode(', ', $missingTables)
        ) .
        '.'
    );
}

/*
|--------------------------------------------------------------------------
| Tenant lookup
|--------------------------------------------------------------------------
*/

$tenantColumns =
    tenantModulesColumns(
        $conn,
        'tenants'
    );

$tenantIdColumn =
    tenantModulesFirstColumn(
        $tenantColumns,
        array('id', 'tenant_id')
    );

$tenantNameColumn =
    tenantModulesFirstColumn(
        $tenantColumns,
        array(
            'business_name',
            'company_name',
            'tenant_name',
            'name'
        )
    );

$tenantCodeColumn =
    tenantModulesFirstColumn(
        $tenantColumns,
        array(
            'tenant_code',
            'business_code',
            'code',
            'slug'
        )
    );

$tenantDeletedColumn =
    tenantModulesFirstColumn(
        $tenantColumns,
        array('deleted_at')
    );

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);
    exit('Required tenant columns are missing.');
}

$tenantId = isset($_GET['tenant_id'])
    ? (int) $_GET['tenant_id']
    : (
        isset($_POST['tenant_id'])
            ? (int) $_POST['tenant_id']
            : 0
    );

if ($tenantId <= 0) {
    $_SESSION['platform_error_message'] =
        'Select a tenant to configure.';

    header('Location: tenants.php');
    exit;
}

$tenantSelect = array(
    "`{$tenantIdColumn}` AS tenant_id",
    "`{$tenantNameColumn}` AS tenant_name"
);

$tenantSelect[] =
    $tenantCodeColumn !== ''
        ? "`{$tenantCodeColumn}` AS tenant_code"
        : "'' AS tenant_code";

$tenantSql = "
    SELECT " .
    implode(', ', $tenantSelect) . "
    FROM tenants
    WHERE `{$tenantIdColumn}` = ?
";

if ($tenantDeletedColumn !== '') {
    $tenantSql .= "
        AND `{$tenantDeletedColumn}` IS NULL
    ";
}

$tenantSql .= " LIMIT 1";

$tenantStmt = $conn->prepare($tenantSql);
$tenantStmt->bind_param('i', $tenantId);
$tenantStmt->execute();

$tenant = $tenantStmt
    ->get_result()
    ->fetch_assoc();

$tenantStmt->close();

if (!$tenant) {
    $_SESSION['platform_error_message'] =
        'Tenant not found.';

    header('Location: tenants.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Current subscription and plan
|--------------------------------------------------------------------------
*/

$currentSubscription = null;

$subscriptionStmt = $conn->prepare("
    SELECT
        s.`id` AS subscription_id,
        s.`plan_id`,
        s.`status`,
        s.`starts_at`,
        s.`ends_at`,
        p.`name` AS plan_name
    FROM subscriptions s
    INNER JOIN plans p
        ON p.`id` = s.`plan_id`
    WHERE s.`tenant_id` = ?
      AND s.`deleted_at` IS NULL
      AND s.`status` IN (
          'trial',
          'active',
          'past_due',
          'suspended'
      )
    ORDER BY
        CASE
            WHEN s.`status` = 'active' THEN 1
            WHEN s.`status` = 'trial' THEN 2
            WHEN s.`status` = 'past_due' THEN 3
            ELSE 4
        END,
        s.`created_at` DESC
    LIMIT 1
");

$subscriptionStmt->bind_param(
    'i',
    $tenantId
);

$subscriptionStmt->execute();

$currentSubscription =
    $subscriptionStmt
    ->get_result()
    ->fetch_assoc();

$subscriptionStmt->close();

$currentPlanId = $currentSubscription
    ? (int) $currentSubscription['plan_id']
    : 0;

/*
|--------------------------------------------------------------------------
| Save configuration
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $action = isset($_POST['action']) &&
        !is_array($_POST['action'])
            ? trim((string) $_POST['action'])
            : 'save';

    try {
        $conn->begin_transaction();

        if ($action === 'reset_all') {
            $deleteModulesStmt =
                $conn->prepare("
                    DELETE FROM tenant_modules
                    WHERE `tenant_id` = ?
                ");

            $deleteModulesStmt->bind_param(
                'i',
                $tenantId
            );

            $deleteModulesStmt->execute();
            $deleteModulesStmt->close();

            $deleteFeaturesStmt =
                $conn->prepare("
                    DELETE FROM tenant_features
                    WHERE `tenant_id` = ?
                ");

            $deleteFeaturesStmt->bind_param(
                'i',
                $tenantId
            );

            $deleteFeaturesStmt->execute();
            $deleteFeaturesStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'All tenant module and feature overrides were reset to the subscription plan defaults.';

            header(
                'Location: tenant-modules.php?tenant_id=' .
                $tenantId,
                true,
                303
            );

            exit;
        }

        $moduleAccess = isset($_POST['module_access']) &&
            is_array($_POST['module_access'])
                ? $_POST['module_access']
                : array();

        $featureAccess = isset($_POST['feature_access']) &&
            is_array($_POST['feature_access'])
                ? $_POST['feature_access']
                : array();

        $allowedAccess = array(
            'inherit',
            'enabled',
            'disabled'
        );

        $updatedBy =
            tenantModulesCurrentUserId();

        $deleteModuleStmt =
            $conn->prepare("
                DELETE FROM tenant_modules
                WHERE `tenant_id` = ?
                  AND `module_id` = ?
            ");

        $upsertModuleStmt =
            $conn->prepare("
                INSERT INTO tenant_modules (
                    `tenant_id`,
                    `module_id`,
                    `access_type`,
                    `updated_by`,
                    `created_at`,
                    `updated_at`
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    `access_type` =
                        VALUES(`access_type`),
                    `updated_by` =
                        VALUES(`updated_by`),
                    `updated_at` = NOW()
            ");

        foreach ($moduleAccess as $moduleId => $accessType) {
            $moduleId = (int) $moduleId;
            $accessType = trim(
                (string) $accessType
            );

            if (
                $moduleId <= 0 ||
                !in_array(
                    $accessType,
                    $allowedAccess,
                    true
                )
            ) {
                continue;
            }

            if ($accessType === 'inherit') {
                $deleteModuleStmt->bind_param(
                    'ii',
                    $tenantId,
                    $moduleId
                );

                $deleteModuleStmt->execute();
            } else {
                $upsertModuleStmt->bind_param(
                    'iisi',
                    $tenantId,
                    $moduleId,
                    $accessType,
                    $updatedBy
                );

                $upsertModuleStmt->execute();
            }
        }

        $deleteModuleStmt->close();
        $upsertModuleStmt->close();

        $deleteFeatureStmt =
            $conn->prepare("
                DELETE FROM tenant_features
                WHERE `tenant_id` = ?
                  AND `feature_id` = ?
            ");

        $upsertFeatureStmt =
            $conn->prepare("
                INSERT INTO tenant_features (
                    `tenant_id`,
                    `feature_id`,
                    `access_type`,
                    `config_value`,
                    `updated_by`,
                    `created_at`,
                    `updated_at`
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    NULL,
                    ?,
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    `access_type` =
                        VALUES(`access_type`),
                    `updated_by` =
                        VALUES(`updated_by`),
                    `updated_at` = NOW()
            ");

        foreach ($featureAccess as $featureId => $accessType) {
            $featureId = (int) $featureId;
            $accessType = trim(
                (string) $accessType
            );

            if (
                $featureId <= 0 ||
                !in_array(
                    $accessType,
                    $allowedAccess,
                    true
                )
            ) {
                continue;
            }

            if ($accessType === 'inherit') {
                $deleteFeatureStmt->bind_param(
                    'ii',
                    $tenantId,
                    $featureId
                );

                $deleteFeatureStmt->execute();
            } else {
                $upsertFeatureStmt->bind_param(
                    'iisi',
                    $tenantId,
                    $featureId,
                    $accessType,
                    $updatedBy
                );

                $upsertFeatureStmt->execute();
            }
        }

        $deleteFeatureStmt->close();
        $upsertFeatureStmt->close();

        $conn->commit();

        regenerateCsrfToken();

        $_SESSION['platform_success_message'] =
            'Tenant module and feature access updated successfully.';

        header(
            'Location: tenant-modules.php?tenant_id=' .
            $tenantId,
            true,
            303
        );

        exit;
    } catch (Exception $exception) {
        $conn->rollback();

        error_log(
            'Tenant module update failed: ' .
            $exception->getMessage()
        );

        $errorMessage =
            'Unable to save tenant access settings: ' .
            $exception->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Load modules, plan defaults, and tenant overrides
|--------------------------------------------------------------------------
*/

$modules = array();

$moduleResult = $conn->query("
    SELECT
        m.`id` AS module_id,
        m.`module_code`,
        m.`module_name`,
        m.`description`,
        m.`icon_class`,
        m.`menu_url`,
        m.`menu_order`,
        m.`is_core`,
        m.`is_active`,
        COALESCE(
            pm.`is_enabled`,
            0
        ) AS plan_enabled,
        COALESCE(
            tm.`access_type`,
            'inherit'
        ) AS tenant_access
    FROM modules m
    LEFT JOIN plan_modules pm
        ON pm.`module_id` = m.`id`
       AND pm.`plan_id` = " .
       (int) $currentPlanId . "
    LEFT JOIN tenant_modules tm
        ON tm.`module_id` = m.`id`
       AND tm.`tenant_id` = " .
       (int) $tenantId . "
    WHERE m.`is_active` = 1
    ORDER BY
        m.`menu_order` ASC,
        m.`module_name` ASC
");

while ($moduleRow = $moduleResult->fetch_assoc()) {
    $moduleRow['features'] = array();

    $moduleRow['effective_enabled'] =
        tenantModulesResolveAccess(
            $moduleRow['tenant_access'],
            $moduleRow['plan_enabled']
        );

    $modules[
        (int) $moduleRow['module_id']
    ] = $moduleRow;
}

$moduleResult->free();

$featureResult = $conn->query("
    SELECT
        mf.`id` AS feature_id,
        mf.`module_id`,
        mf.`feature_code`,
        mf.`feature_name`,
        mf.`description`,
        mf.`is_active`,
        COALESCE(
            pf.`is_enabled`,
            0
        ) AS plan_enabled,
        COALESCE(
            tf.`access_type`,
            'inherit'
        ) AS tenant_access
    FROM module_features mf
    LEFT JOIN plan_features pf
        ON pf.`feature_id` = mf.`id`
       AND pf.`plan_id` = " .
       (int) $currentPlanId . "
    LEFT JOIN tenant_features tf
        ON tf.`feature_id` = mf.`id`
       AND tf.`tenant_id` = " .
       (int) $tenantId . "
    WHERE mf.`is_active` = 1
    ORDER BY
        mf.`module_id` ASC,
        mf.`feature_name` ASC
");

while ($featureRow = $featureResult->fetch_assoc()) {
    $moduleId =
        (int) $featureRow['module_id'];

    if (!isset($modules[$moduleId])) {
        continue;
    }

    $featureRow['effective_enabled'] =
        tenantModulesResolveAccess(
            $featureRow['tenant_access'],
            $featureRow['plan_enabled']
        );

    $modules[$moduleId]['features'][] =
        $featureRow;
}

$featureResult->free();

$modules = array_values($modules);

/*
|--------------------------------------------------------------------------
| Summary totals
|--------------------------------------------------------------------------
*/

$totalModules = count($modules);
$effectiveEnabledModules = 0;
$moduleOverrides = 0;
$totalFeatures = 0;
$effectiveEnabledFeatures = 0;
$featureOverrides = 0;

foreach ($modules as $module) {
    if (!empty($module['effective_enabled'])) {
        $effectiveEnabledModules++;
    }

    if (
        $module['tenant_access'] === 'enabled' ||
        $module['tenant_access'] === 'disabled'
    ) {
        $moduleOverrides++;
    }

    foreach ($module['features'] as $feature) {
        $totalFeatures++;

        if (!empty($feature['effective_enabled'])) {
            $effectiveEnabledFeatures++;
        }

        if (
            $feature['tenant_access'] === 'enabled' ||
            $feature['tenant_access'] === 'disabled'
        ) {
            $featureOverrides++;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Messages
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
    .tenant-modules-page {
        display: grid;
        gap: 15px;
    }

    .tenant-modules-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .tenant-modules-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .tenant-modules-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-modules-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .tenant-modules-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-modules-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .tenant-modules-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .tenant-modules-button {
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

    .tenant-modules-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-modules-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .tenant-modules-button.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-modules-tenant {
        padding: 16px;
        display: flex;
        align-items: center;
        gap: 13px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-modules-tenant-icon {
        width: 48px;
        height: 48px;
        flex: 0 0 48px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 17px;
    }

    .tenant-modules-tenant-name {
        color: #111827;
        font-size: 14px;
        font-weight: 800;
    }

    .tenant-modules-tenant-meta {
        margin-top: 4px;
        color: #6b7280;
        font-size: 9px;
    }

    .tenant-modules-summary {
        display: grid;
        grid-template-columns:
            repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .tenant-modules-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
    }

    .tenant-modules-summary-icon {
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

    .tenant-modules-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .tenant-modules-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .tenant-modules-help {
        padding: 12px 14px;
        border: 1px solid #ddd6fe;
        border-radius: 10px;
        background: #faf8ff;
        color: #5b21b6;
        font-size: 9px;
        line-height: 1.6;
    }

    .tenant-modules-list {
        display: grid;
        gap: 12px;
    }

    .tenant-module-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .tenant-module-header {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        background: #fafafa;
        border-bottom: 1px solid #eef0f3;
    }

    .tenant-module-icon {
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

    .tenant-module-title-wrap {
        min-width: 0;
        flex: 1;
    }

    .tenant-module-title {
        color: #111827;
        font-size: 11px;
        font-weight: 800;
    }

    .tenant-module-description {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .tenant-module-badges {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .tenant-module-badge {
        padding: 4px 7px;
        border-radius: 999px;
        font-size: 7px;
        font-weight: 700;
    }

    .tenant-module-badge.plan {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .tenant-module-badge.enabled {
        background: #ecfdf5;
        color: #047857;
    }

    .tenant-module-badge.disabled {
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-module-body {
        padding: 14px;
    }

    .tenant-module-control-row {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            200px;
        gap: 15px;
        align-items: center;
    }

    .tenant-module-control-label {
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-module-control-help {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-module-select {
        min-height: 37px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        color: #374151;
        font-size: 9px;
    }

    .tenant-module-features {
        margin-top: 13px;
        display: grid;
        gap: 8px;
    }

    .tenant-feature-row {
        padding: 10px 11px;
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            140px
            200px;
        gap: 12px;
        align-items: center;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .tenant-feature-name {
        color: #111827;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-feature-description {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-feature-plan {
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
    }

    .tenant-modules-savebar {
        position: sticky;
        bottom: 0;
        z-index: 20;
        padding: 12px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border: 1px solid #ddd6fe;
        border-radius: 12px;
        background: rgba(255,255,255,.96);
        box-shadow:
            0 -8px 28px rgba(91,33,182,.08);
        backdrop-filter: blur(8px);
    }

    .tenant-modules-savebar-text {
        color: #6b7280;
        font-size: 9px;
    }

    .tenant-modules-save {
        min-height: 39px;
        padding: 8px 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 0;
        border-radius: 9px;
        background:
            linear-gradient(
                135deg,
                #7c3aed,
                #6d28d9
            );
        color: #ffffff;
        font-size: 9px;
        font-weight: 700;
    }

    @media (max-width: 900px) {
        .tenant-modules-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

        .tenant-feature-row {
            grid-template-columns: 1fr;
        }

        .tenant-module-control-row {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 650px) {
        .tenant-modules-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .tenant-modules-actions {
            width: 100%;
        }

        .tenant-modules-button {
            flex: 1;
        }

        .tenant-modules-summary {
            grid-template-columns: 1fr;
        }

        .tenant-modules-savebar {
            align-items: stretch;
            flex-direction: column;
        }

        .tenant-modules-save {
            width: 100%;
        }
    }
</style>

<div class="tenant-modules-page">

    <?php if ($successMessage !== ''): ?>
        <div class="tenant-modules-alert success">
            <i class="bi bi-check-circle"></i>
            <span>
                <?= tenantModulesEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="tenant-modules-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span>
                <?= tenantModulesEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="tenant-modules-header">
        <div>
            <h2 class="tenant-modules-title">
                Tenant Modules & Features
            </h2>

            <div class="tenant-modules-description">
                Override the subscription plan for this individual business.
            </div>
        </div>

        <div class="tenant-modules-actions">
            <a
                href="tenant-view.php?id=<?= (int) $tenantId; ?>"
                class="tenant-modules-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Tenant
            </a>

            <a
                href="tenant-limits.php?tenant_id=<?= (int) $tenantId; ?>"
                class="tenant-modules-button"
            >
                <i class="bi bi-sliders"></i>
                Usage Limits
            </a>
        </div>
    </div>

    <section class="tenant-modules-tenant">
        <span class="tenant-modules-tenant-icon">
            <i class="bi bi-building"></i>
        </span>

        <span>
            <span class="tenant-modules-tenant-name">
                <?= tenantModulesEscape(
                    $tenant['tenant_name']
                ); ?>
            </span>

            <span class="tenant-modules-tenant-meta">
                <?= tenantModulesEscape(
                    !empty($tenant['tenant_code'])
                        ? $tenant['tenant_code']
                        : 'Tenant #' . $tenantId
                ); ?>

                <?php if ($currentSubscription): ?>
                    · Plan:
                    <?= tenantModulesEscape(
                        $currentSubscription['plan_name']
                    ); ?>
                    ·
                    <?= tenantModulesEscape(
                        tenantModulesLabel(
                            $currentSubscription['status']
                        )
                    ); ?>
                <?php else: ?>
                    · No active subscription plan
                <?php endif; ?>
            </span>
        </span>
    </section>

    <section class="tenant-modules-summary">
        <article class="tenant-modules-summary-card">
            <span class="tenant-modules-summary-icon">
                <i class="bi bi-grid-3x3-gap"></i>
            </span>

            <span>
                <span class="tenant-modules-summary-label">
                    Enabled Modules
                </span>

                <span class="tenant-modules-summary-value">
                    <?= number_format(
                        $effectiveEnabledModules
                    ); ?>
                    /
                    <?= number_format($totalModules); ?>
                </span>
            </span>
        </article>

        <article class="tenant-modules-summary-card">
            <span class="tenant-modules-summary-icon">
                <i class="bi bi-toggles"></i>
            </span>

            <span>
                <span class="tenant-modules-summary-label">
                    Module Overrides
                </span>

                <span class="tenant-modules-summary-value">
                    <?= number_format(
                        $moduleOverrides
                    ); ?>
                </span>
            </span>
        </article>

        <article class="tenant-modules-summary-card">
            <span class="tenant-modules-summary-icon">
                <i class="bi bi-ui-checks"></i>
            </span>

            <span>
                <span class="tenant-modules-summary-label">
                    Enabled Features
                </span>

                <span class="tenant-modules-summary-value">
                    <?= number_format(
                        $effectiveEnabledFeatures
                    ); ?>
                    /
                    <?= number_format($totalFeatures); ?>
                </span>
            </span>
        </article>

        <article class="tenant-modules-summary-card">
            <span class="tenant-modules-summary-icon">
                <i class="bi bi-sliders2"></i>
            </span>

            <span>
                <span class="tenant-modules-summary-label">
                    Feature Overrides
                </span>

                <span class="tenant-modules-summary-value">
                    <?= number_format(
                        $featureOverrides
                    ); ?>
                </span>
            </span>
        </article>
    </section>

    <div class="tenant-modules-help">
        <strong>Use Plan Default</strong> follows the current subscription plan.
        <strong>Enabled</strong> force-enables access for this tenant.
        <strong>Disabled</strong> blocks access even when the plan includes it.
    </div>

    <form
        method="post"
        action="tenant-modules.php?tenant_id=<?= (int) $tenantId; ?>"
        id="tenantModulesForm"
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="tenant_id"
            value="<?= (int) $tenantId; ?>"
        >

        <input
            type="hidden"
            name="action"
            value="save"
        >

        <div class="tenant-modules-list">

            <?php foreach ($modules as $module): ?>
                <section class="tenant-module-card">
                    <div class="tenant-module-header">
                        <span class="tenant-module-icon">
                            <i class="<?= tenantModulesEscape(
                                !empty($module['icon_class'])
                                    ? $module['icon_class']
                                    : 'bi bi-grid'
                            ); ?>"></i>
                        </span>

                        <div class="tenant-module-title-wrap">
                            <div class="tenant-module-title">
                                <?= tenantModulesEscape(
                                    $module['module_name']
                                ); ?>
                            </div>

                            <div class="tenant-module-description">
                                <?= tenantModulesEscape(
                                    !empty($module['description'])
                                        ? $module['description']
                                        : tenantModulesLabel(
                                            $module['module_code']
                                        )
                                ); ?>
                            </div>
                        </div>

                        <div class="tenant-module-badges">
                            <span class="tenant-module-badge plan">
                                Plan:
                                <?= (int) $module['plan_enabled'] === 1
                                    ? 'Included'
                                    : 'Not Included'; ?>
                            </span>

                            <span class="tenant-module-badge <?= !empty(
                                $module['effective_enabled']
                            )
                                ? 'enabled'
                                : 'disabled'; ?>">
                                Effective:
                                <?= !empty(
                                    $module['effective_enabled']
                                )
                                    ? 'Enabled'
                                    : 'Disabled'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="tenant-module-body">
                        <div class="tenant-module-control-row">
                            <span>
                                <span class="tenant-module-control-label">
                                    Module Access
                                </span>

                                <span class="tenant-module-control-help">
                                    Controls the entire
                                    <?= tenantModulesEscape(
                                        $module['module_name']
                                    ); ?>
                                    module.
                                </span>
                            </span>

                            <select
                                name="module_access[<?= (int) $module['module_id']; ?>]"
                                class="form-select tenant-module-select module-access-select"
                                data-module-id="<?= (int) $module['module_id']; ?>"
                            >
                                <option
                                    value="inherit"
                                    <?= $module['tenant_access'] === 'inherit'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Use Plan Default
                                </option>

                                <option
                                    value="enabled"
                                    <?= $module['tenant_access'] === 'enabled'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Enabled
                                </option>

                                <option
                                    value="disabled"
                                    <?= $module['tenant_access'] === 'disabled'
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    Disabled
                                </option>
                            </select>
                        </div>

                        <?php if (!empty($module['features'])): ?>
                            <div
                                class="tenant-module-features"
                                data-feature-group="<?= (int) $module['module_id']; ?>"
                            >
                                <?php foreach (
                                    $module['features'] as
                                    $feature
                                ): ?>
                                    <div class="tenant-feature-row">
                                        <span>
                                            <span class="tenant-feature-name">
                                                <?= tenantModulesEscape(
                                                    $feature['feature_name']
                                                ); ?>
                                            </span>

                                            <span class="tenant-feature-description">
                                                <?= tenantModulesEscape(
                                                    !empty(
                                                        $feature['description']
                                                    )
                                                        ? $feature['description']
                                                        : tenantModulesLabel(
                                                            $feature['feature_code']
                                                        )
                                                ); ?>
                                            </span>
                                        </span>

                                        <span class="tenant-feature-plan">
                                            Plan:
                                            <?= (int) $feature['plan_enabled'] === 1
                                                ? 'Included'
                                                : 'Not Included'; ?>
                                            <br>
                                            Effective:
                                            <?= !empty(
                                                $feature['effective_enabled']
                                            )
                                                ? 'Enabled'
                                                : 'Disabled'; ?>
                                        </span>

                                        <select
                                            name="feature_access[<?= (int) $feature['feature_id']; ?>]"
                                            class="form-select tenant-module-select feature-access-select"
                                        >
                                            <option
                                                value="inherit"
                                                <?= $feature['tenant_access'] === 'inherit'
                                                    ? 'selected'
                                                    : ''; ?>
                                            >
                                                Use Plan Default
                                            </option>

                                            <option
                                                value="enabled"
                                                <?= $feature['tenant_access'] === 'enabled'
                                                    ? 'selected'
                                                    : ''; ?>
                                            >
                                                Enabled
                                            </option>

                                            <option
                                                value="disabled"
                                                <?= $feature['tenant_access'] === 'disabled'
                                                    ? 'selected'
                                                    : ''; ?>
                                            >
                                                Disabled
                                            </option>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>

        </div>

        <div class="tenant-modules-savebar">
            <div class="tenant-modules-savebar-text">
                Save tenant-specific module and feature overrides.
            </div>

            <button
                type="submit"
                class="tenant-modules-save"
                id="tenantModulesSave"
            >
                <i class="bi bi-check2-circle"></i>
                Save Access Settings
            </button>
        </div>
    </form>

    <form
        method="post"
        action="tenant-modules.php?tenant_id=<?= (int) $tenantId; ?>"
        onsubmit="return confirm('Reset all tenant module and feature overrides to the plan defaults?');"
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="tenant_id"
            value="<?= (int) $tenantId; ?>"
        >

        <input
            type="hidden"
            name="action"
            value="reset_all"
        >

        <button
            type="submit"
            class="tenant-modules-button danger"
        >
            <i class="bi bi-arrow-counterclockwise"></i>
            Reset All Overrides to Plan Default
        </button>
    </form>

</div>

<script>
(function () {
    'use strict';

    const form =
        document.getElementById(
            'tenantModulesForm'
        );

    const saveButton =
        document.getElementById(
            'tenantModulesSave'
        );

    if (
        form &&
        saveButton
    ) {
        form.addEventListener(
            'submit',
            function () {
                saveButton.disabled = true;
                saveButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm"></span> Saving...';
            }
        );
    }

    const moduleSelects =
        document.querySelectorAll(
            '.module-access-select'
        );

    moduleSelects.forEach(
        function (moduleSelect) {
            moduleSelect.addEventListener(
                'change',
                function () {
                    const moduleId =
                        moduleSelect.getAttribute(
                            'data-module-id'
                        );

                    const group =
                        document.querySelector(
                            '[data-feature-group="' +
                            moduleId +
                            '"]'
                        );

                    if (!group) {
                        return;
                    }

                    group.style.opacity =
                        moduleSelect.value ===
                        'disabled'
                            ? '0.58'
                            : '1';
                }
            );

            moduleSelect.dispatchEvent(
                new Event('change')
            );
        }
    );
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
