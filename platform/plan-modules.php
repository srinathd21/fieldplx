<?php
/**
 * FieldPlx Platform - Plan Modules & Features
 *
 * File:
 * platform/plan-modules.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 *
 * Required tables:
 * - plans
 * - modules
 * - module_features
 *
 * Automatically creates:
 * - plan_modules
 * - plan_features
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin'
));

$pageTitle = 'Plan Modules & Features - FieldPlx';
$activePage = 'plan-modules';
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

if (!function_exists('planModulesEscape')) {
    function planModulesEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('planModulesTableExists')) {
    function planModulesTableExists(
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

if (!function_exists('planModulesColumns')) {
    function planModulesColumns(
        mysqli $conn,
        $tableName,
        $refresh = false
    ) {
        static $cache = array();

        if (
            !$refresh &&
            isset($cache[$tableName])
        ) {
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

if (!function_exists('planModulesFirstColumn')) {
    function planModulesFirstColumn(
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

if (!function_exists('planModulesLabel')) {
    function planModulesLabel($value)
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

if (!function_exists('planModulesCurrentUserId')) {
    function planModulesCurrentUserId()
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

/*
|--------------------------------------------------------------------------
| Verify required master tables
|--------------------------------------------------------------------------
*/

foreach (
    array(
        'plans',
        'modules',
        'module_features'
    ) as $requiredTable
) {
    if (
        !planModulesTableExists(
            $conn,
            $requiredTable
        )
    ) {
        http_response_code(500);

        exit(
            'Missing required table: ' .
            planModulesEscape($requiredTable) .
            '.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Create plan access tables
|--------------------------------------------------------------------------
*/

$conn->query("
    CREATE TABLE IF NOT EXISTS `plan_modules` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `plan_id` BIGINT(20) UNSIGNED NOT NULL,
        `module_id` BIGINT(20) UNSIGNED NOT NULL,
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_plan_module`
            (`plan_id`, `module_id`),
        KEY `idx_plan_modules_plan`
            (`plan_id`),
        KEY `idx_plan_modules_module`
            (`module_id`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS `plan_features` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `plan_id` BIGINT(20) UNSIGNED NOT NULL,
        `feature_id` BIGINT(20) UNSIGNED NOT NULL,
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_plan_feature`
            (`plan_id`, `feature_id`),
        KEY `idx_plan_features_plan`
            (`plan_id`),
        KEY `idx_plan_features_feature`
            (`feature_id`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");


/*
|--------------------------------------------------------------------------
| Upgrade existing plan access tables safely
|--------------------------------------------------------------------------
*/

$planModuleColumns = planModulesColumns(
    $conn,
    'plan_modules',
    true
);

if (!isset($planModuleColumns['updated_by'])) {
    $conn->query("
        ALTER TABLE `plan_modules`
        ADD COLUMN `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL
        AFTER `is_enabled`
    ");
}

$planModuleColumns = planModulesColumns(
    $conn,
    'plan_modules',
    true
);

if (!isset($planModuleColumns['created_at'])) {
    $conn->query("
        ALTER TABLE `plan_modules`
        ADD COLUMN `created_at`
            DATETIME NOT NULL
            DEFAULT CURRENT_TIMESTAMP
        AFTER `updated_by`
    ");
}

$planModuleColumns = planModulesColumns(
    $conn,
    'plan_modules',
    true
);

if (!isset($planModuleColumns['updated_at'])) {
    $conn->query("
        ALTER TABLE `plan_modules`
        ADD COLUMN `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP
        AFTER `created_at`
    ");
}

$planFeatureColumns = planModulesColumns(
    $conn,
    'plan_features',
    true
);

if (!isset($planFeatureColumns['updated_by'])) {
    $conn->query("
        ALTER TABLE `plan_features`
        ADD COLUMN `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL
        AFTER `is_enabled`
    ");
}

$planFeatureColumns = planModulesColumns(
    $conn,
    'plan_features',
    true
);

if (!isset($planFeatureColumns['created_at'])) {
    $conn->query("
        ALTER TABLE `plan_features`
        ADD COLUMN `created_at`
            DATETIME NOT NULL
            DEFAULT CURRENT_TIMESTAMP
        AFTER `updated_by`
    ");
}

$planFeatureColumns = planModulesColumns(
    $conn,
    'plan_features',
    true
);

if (!isset($planFeatureColumns['updated_at'])) {
    $conn->query("
        ALTER TABLE `plan_features`
        ADD COLUMN `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP
        AFTER `created_at`
    ");
}

/*
|--------------------------------------------------------------------------
| Plan structure and list
|--------------------------------------------------------------------------
*/

$planColumns = planModulesColumns(
    $conn,
    'plans'
);

$planIdColumn = planModulesFirstColumn(
    $planColumns,
    array('id', 'plan_id')
);

$planNameColumn = planModulesFirstColumn(
    $planColumns,
    array('name', 'plan_name', 'title')
);

$planCodeColumn = planModulesFirstColumn(
    $planColumns,
    array('code', 'plan_code', 'slug')
);

$planStatusColumn = planModulesFirstColumn(
    $planColumns,
    array('status', 'is_active')
);

$planDeletedColumn = planModulesFirstColumn(
    $planColumns,
    array('deleted_at')
);

if (
    $planIdColumn === '' ||
    $planNameColumn === ''
) {
    http_response_code(500);
    exit('Required plan columns are missing.');
}

$planSelectParts = array(
    "`{$planIdColumn}` AS plan_id",
    "`{$planNameColumn}` AS plan_name"
);

$planSelectParts[] =
    $planCodeColumn !== ''
        ? "`{$planCodeColumn}` AS plan_code"
        : "'' AS plan_code";

$planSelectParts[] =
    $planStatusColumn !== ''
        ? "`{$planStatusColumn}` AS plan_status"
        : "'active' AS plan_status";

$plansSql = "
    SELECT " .
    implode(', ', $planSelectParts) . "
    FROM plans
    WHERE 1 = 1
";

if ($planDeletedColumn !== '') {
    $plansSql .= "
        AND `{$planDeletedColumn}` IS NULL
    ";
}

$plansSql .= "
    ORDER BY `{$planNameColumn}` ASC
";

$plansResult = $conn->query($plansSql);
$plans = array();

while ($planRow = $plansResult->fetch_assoc()) {
    $plans[] = $planRow;
}

$plansResult->free();

$planId = isset($_GET['plan_id'])
    ? (int) $_GET['plan_id']
    : (
        isset($_POST['plan_id'])
            ? (int) $_POST['plan_id']
            : 0
    );

if (
    $planId <= 0 &&
    !empty($plans)
) {
    $planId = (int) $plans[0]['plan_id'];
}

$currentPlan = null;

foreach ($plans as $plan) {
    if ((int) $plan['plan_id'] === $planId) {
        $currentPlan = $plan;
        break;
    }
}

if (
    $planId > 0 &&
    !$currentPlan
) {
    $_SESSION['platform_error_message'] =
        'Selected subscription plan was not found.';

    header('Location: plans.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Save plan modules and features
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    if ($planId <= 0) {
        $errorMessage =
            'Select a subscription plan.';
    } else {
        $action = isset($_POST['action']) &&
            !is_array($_POST['action'])
                ? trim((string) $_POST['action'])
                : 'save';

        try {
            $conn->begin_transaction();

            if ($action === 'clear_all') {
                $deleteFeatureStmt =
                    $conn->prepare("
                        DELETE FROM plan_features
                        WHERE `plan_id` = ?
                    ");

                $deleteFeatureStmt->bind_param(
                    'i',
                    $planId
                );

                $deleteFeatureStmt->execute();
                $deleteFeatureStmt->close();

                $deleteModuleStmt =
                    $conn->prepare("
                        DELETE FROM plan_modules
                        WHERE `plan_id` = ?
                    ");

                $deleteModuleStmt->bind_param(
                    'i',
                    $planId
                );

                $deleteModuleStmt->execute();
                $deleteModuleStmt->close();

                $conn->commit();

                regenerateCsrfToken();

                $_SESSION['platform_success_message'] =
                    'All modules and features were removed from this plan.';

                header(
                    'Location: plan-modules.php?plan_id=' .
                    $planId,
                    true,
                    303
                );

                exit;
            }

            $selectedModules =
                isset($_POST['modules']) &&
                is_array($_POST['modules'])
                    ? array_map(
                        'intval',
                        array_keys($_POST['modules'])
                    )
                    : array();

            $selectedFeatures =
                isset($_POST['features']) &&
                is_array($_POST['features'])
                    ? array_map(
                        'intval',
                        array_keys($_POST['features'])
                    )
                    : array();

            $updatedBy =
                planModulesCurrentUserId();

            $deleteExistingFeatures =
                $conn->prepare("
                    DELETE FROM plan_features
                    WHERE `plan_id` = ?
                ");

            $deleteExistingFeatures->bind_param(
                'i',
                $planId
            );

            $deleteExistingFeatures->execute();
            $deleteExistingFeatures->close();

            $deleteExistingModules =
                $conn->prepare("
                    DELETE FROM plan_modules
                    WHERE `plan_id` = ?
                ");

            $deleteExistingModules->bind_param(
                'i',
                $planId
            );

            $deleteExistingModules->execute();
            $deleteExistingModules->close();

            if (!empty($selectedModules)) {
                $insertModuleStmt =
                    $conn->prepare("
                        INSERT INTO plan_modules (
                            `plan_id`,
                            `module_id`,
                            `is_enabled`,
                            `updated_by`,
                            `created_at`,
                            `updated_at`
                        ) VALUES (
                            ?,
                            ?,
                            1,
                            ?,
                            NOW(),
                            NOW()
                        )
                    ");

                foreach (
                    $selectedModules as $moduleId
                ) {
                    if ($moduleId <= 0) {
                        continue;
                    }

                    $insertModuleStmt->bind_param(
                        'iii',
                        $planId,
                        $moduleId,
                        $updatedBy
                    );

                    $insertModuleStmt->execute();
                }

                $insertModuleStmt->close();
            }

            if (!empty($selectedFeatures)) {
                $insertFeatureStmt =
                    $conn->prepare("
                        INSERT INTO plan_features (
                            `plan_id`,
                            `feature_id`,
                            `is_enabled`,
                            `updated_by`,
                            `created_at`,
                            `updated_at`
                        ) VALUES (
                            ?,
                            ?,
                            1,
                            ?,
                            NOW(),
                            NOW()
                        )
                    ");

                foreach (
                    $selectedFeatures as $featureId
                ) {
                    if ($featureId <= 0) {
                        continue;
                    }

                    $insertFeatureStmt->bind_param(
                        'iii',
                        $planId,
                        $featureId,
                        $updatedBy
                    );

                    $insertFeatureStmt->execute();
                }

                $insertFeatureStmt->close();
            }

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Plan module and feature access updated successfully.';

            header(
                'Location: plan-modules.php?plan_id=' .
                $planId,
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            $conn->rollback();

            error_log(
                'Plan modules update failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to save plan access settings: ' .
                $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load modules and features
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
        CASE
            WHEN pm.`id` IS NULL THEN 0
            ELSE 1
        END AS is_selected
    FROM modules m
    LEFT JOIN plan_modules pm
        ON pm.`module_id` = m.`id`
       AND pm.`plan_id` = " .
       (int) $planId . "
       AND pm.`is_enabled` = 1
    WHERE m.`is_active` = 1
    ORDER BY
        m.`menu_order` ASC,
        m.`module_name` ASC
");

while ($moduleRow = $moduleResult->fetch_assoc()) {
    $moduleRow['features'] = array();

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
        CASE
            WHEN pf.`id` IS NULL THEN 0
            ELSE 1
        END AS is_selected
    FROM module_features mf
    LEFT JOIN plan_features pf
        ON pf.`feature_id` = mf.`id`
       AND pf.`plan_id` = " .
       (int) $planId . "
       AND pf.`is_enabled` = 1
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

    $modules[$moduleId]['features'][] =
        $featureRow;
}

$featureResult->free();

$modules = array_values($modules);

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$totalModules = count($modules);
$selectedModulesCount = 0;
$totalFeatures = 0;
$selectedFeaturesCount = 0;

foreach ($modules as $module) {
    if ((int) $module['is_selected'] === 1) {
        $selectedModulesCount++;
    }

    foreach ($module['features'] as $feature) {
        $totalFeatures++;

        if ((int) $feature['is_selected'] === 1) {
            $selectedFeaturesCount++;
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
    .plan-modules-page {
        display: grid;
        gap: 15px;
    }

    .plan-modules-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .plan-modules-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .plan-modules-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .plan-modules-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .plan-modules-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .plan-modules-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .plan-modules-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .plan-modules-button {
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
        cursor: pointer;
    }

    .plan-modules-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .plan-modules-button.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .plan-selector-card {
        padding: 15px;
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(240px, 340px);
        gap: 15px;
        align-items: center;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .plan-selector-name {
        color: #111827;
        font-size: 13px;
        font-weight: 800;
    }

    .plan-selector-meta {
        margin-top: 4px;
        color: #6b7280;
        font-size: 9px;
    }

    .plan-selector-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        color: #374151;
        font-size: 9px;
    }

    .plan-modules-summary {
        display: grid;
        grid-template-columns:
            repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .plan-modules-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
    }

    .plan-modules-summary-icon {
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

    .plan-modules-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .plan-modules-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .plan-modules-toolbar {
        padding: 11px 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border: 1px solid #ddd6fe;
        border-radius: 10px;
        background: #faf8ff;
    }

    .plan-modules-toolbar-text {
        color: #5b21b6;
        font-size: 9px;
        line-height: 1.5;
    }

    .plan-modules-toolbar-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .plan-module-list {
        display: grid;
        gap: 12px;
    }

    .plan-module-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .plan-module-header {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        background: #fafafa;
        border-bottom: 1px solid #eef0f3;
    }

    .plan-module-icon {
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

    .plan-module-title-wrap {
        min-width: 0;
        flex: 1;
    }

    .plan-module-title {
        color: #111827;
        font-size: 11px;
        font-weight: 800;
    }

    .plan-module-description {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .plan-module-toggle {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
        cursor: pointer;
    }

    .plan-module-toggle input {
        width: 17px;
        height: 17px;
        accent-color: #7c3aed;
    }

    .plan-module-body {
        padding: 14px;
    }

    .plan-feature-list {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .plan-feature-row {
        padding: 10px 11px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
        cursor: pointer;
    }

    .plan-feature-row input {
        width: 16px;
        height: 16px;
        margin-top: 1px;
        flex: 0 0 16px;
        accent-color: #7c3aed;
    }

    .plan-feature-name {
        display: block;
        color: #111827;
        font-size: 9px;
        font-weight: 700;
    }

    .plan-feature-description {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .plan-module-empty {
        padding: 10px;
        border: 1px dashed #e5e7eb;
        border-radius: 8px;
        color: #9ca3af;
        font-size: 8px;
        text-align: center;
    }

    .plan-modules-savebar {
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

    .plan-modules-savebar-text {
        color: #6b7280;
        font-size: 9px;
    }

    .plan-modules-save {
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
        .plan-selector-card {
            grid-template-columns: 1fr;
        }

        .plan-modules-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

        .plan-feature-list {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 650px) {
        .plan-modules-header,
        .plan-modules-toolbar,
        .plan-modules-savebar {
            align-items: stretch;
            flex-direction: column;
        }

        .plan-modules-actions,
        .plan-modules-toolbar-actions {
            width: 100%;
        }

        .plan-modules-button {
            flex: 1;
        }

        .plan-modules-summary {
            grid-template-columns: 1fr;
        }

        .plan-modules-save {
            width: 100%;
        }
    }
</style>

<div class="plan-modules-page">

    <?php if ($successMessage !== ''): ?>
        <div class="plan-modules-alert success">
            <i class="bi bi-check-circle"></i>

            <span>
                <?= planModulesEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="plan-modules-alert danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= planModulesEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="plan-modules-header">
        <div>
            <h2 class="plan-modules-title">
                Plan Modules & Features
            </h2>

            <div class="plan-modules-description">
                Choose the default modules and features included in each subscription plan.
            </div>
        </div>

        <div class="plan-modules-actions">
            <a
                href="plans.php"
                class="plan-modules-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Plans
            </a>

            <?php if ($planId > 0): ?>
                <a
                    href="plan-view.php?id=<?= (int) $planId; ?>"
                    class="plan-modules-button"
                >
                    <i class="bi bi-eye"></i>
                    View Plan
                </a>
            <?php endif; ?>
        </div>
    </div>

    <section class="plan-selector-card">
        <div>
            <div class="plan-selector-name">
                <?= $currentPlan
                    ? planModulesEscape(
                        $currentPlan['plan_name']
                    )
                    : 'Select Subscription Plan'; ?>
            </div>

            <div class="plan-selector-meta">
                <?php if ($currentPlan): ?>
                    <?= planModulesEscape(
                        !empty($currentPlan['plan_code'])
                            ? $currentPlan['plan_code']
                            : 'Plan #' . $planId
                    ); ?>
                    ·
                    <?= planModulesEscape(
                        planModulesLabel(
                            $currentPlan['plan_status']
                        )
                    ); ?>
                <?php else: ?>
                    No subscription plans are available.
                <?php endif; ?>
            </div>
        </div>

        <select
            class="form-select plan-selector-control"
            id="planSelector"
        >
            <?php foreach ($plans as $plan): ?>
                <option
                    value="<?= (int) $plan['plan_id']; ?>"
                    <?= (int) $plan['plan_id'] ===
                        $planId
                            ? 'selected'
                            : ''; ?>
                >
                    <?= planModulesEscape(
                        $plan['plan_name']
                    ); ?>
                    <?php if (
                        !empty($plan['plan_code'])
                    ): ?>
                        (<?= planModulesEscape(
                            $plan['plan_code']
                        ); ?>)
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </section>

    <?php if ($currentPlan): ?>

        <section class="plan-modules-summary">
            <article class="plan-modules-summary-card">
                <span class="plan-modules-summary-icon">
                    <i class="bi bi-grid-3x3-gap"></i>
                </span>

                <span>
                    <span class="plan-modules-summary-label">
                        Included Modules
                    </span>

                    <span class="plan-modules-summary-value">
                        <?= number_format(
                            $selectedModulesCount
                        ); ?>
                        /
                        <?= number_format(
                            $totalModules
                        ); ?>
                    </span>
                </span>
            </article>

            <article class="plan-modules-summary-card">
                <span class="plan-modules-summary-icon">
                    <i class="bi bi-ui-checks"></i>
                </span>

                <span>
                    <span class="plan-modules-summary-label">
                        Included Features
                    </span>

                    <span class="plan-modules-summary-value">
                        <?= number_format(
                            $selectedFeaturesCount
                        ); ?>
                        /
                        <?= number_format(
                            $totalFeatures
                        ); ?>
                    </span>
                </span>
            </article>

            <article class="plan-modules-summary-card">
                <span class="plan-modules-summary-icon">
                    <i class="bi bi-building-check"></i>
                </span>

                <span>
                    <span class="plan-modules-summary-label">
                        Tenant Default
                    </span>

                    <span class="plan-modules-summary-value">
                        Plan
                    </span>
                </span>
            </article>

            <article class="plan-modules-summary-card">
                <span class="plan-modules-summary-icon">
                    <i class="bi bi-arrow-left-right"></i>
                </span>

                <span>
                    <span class="plan-modules-summary-label">
                        Overrides
                    </span>

                    <span class="plan-modules-summary-value">
                        Allowed
                    </span>
                </span>
            </article>
        </section>

        <div class="plan-modules-toolbar">
            <div class="plan-modules-toolbar-text">
                Disabling a module removes its default access from tenants using this plan. Tenant-specific overrides can still enable or disable it separately.
            </div>

            <div class="plan-modules-toolbar-actions">
                <button
                    type="button"
                    class="plan-modules-button"
                    id="enableAllModules"
                >
                    Enable All
                </button>

                <button
                    type="button"
                    class="plan-modules-button"
                    id="disableAllModules"
                >
                    Disable All
                </button>
            </div>
        </div>

        <form
            method="post"
            action="plan-modules.php?plan_id=<?= (int) $planId; ?>"
            id="planModulesForm"
        >
            <?php csrfField(); ?>

            <input
                type="hidden"
                name="plan_id"
                value="<?= (int) $planId; ?>"
            >

            <input
                type="hidden"
                name="action"
                value="save"
            >

            <div class="plan-module-list">
                <?php foreach ($modules as $module): ?>
                    <section class="plan-module-card">
                        <div class="plan-module-header">
                            <span class="plan-module-icon">
                                <i class="<?= planModulesEscape(
                                    !empty($module['icon_class'])
                                        ? $module['icon_class']
                                        : 'bi bi-grid'
                                ); ?>"></i>
                            </span>

                            <div class="plan-module-title-wrap">
                                <div class="plan-module-title">
                                    <?= planModulesEscape(
                                        $module['module_name']
                                    ); ?>
                                </div>

                                <div class="plan-module-description">
                                    <?= planModulesEscape(
                                        !empty($module['description'])
                                            ? $module['description']
                                            : planModulesLabel(
                                                $module['module_code']
                                            )
                                    ); ?>
                                </div>
                            </div>

                            <label class="plan-module-toggle">
                                <input
                                    type="checkbox"
                                    name="modules[<?= (int) $module['module_id']; ?>]"
                                    value="1"
                                    class="plan-module-checkbox"
                                    data-module-id="<?= (int) $module['module_id']; ?>"
                                    <?= (int) $module['is_selected'] === 1
                                        ? 'checked'
                                        : ''; ?>
                                >

                                Include Module
                            </label>
                        </div>

                        <div class="plan-module-body">
                            <?php if (!empty($module['features'])): ?>
                                <div
                                    class="plan-feature-list"
                                    data-feature-group="<?= (int) $module['module_id']; ?>"
                                >
                                    <?php foreach (
                                        $module['features'] as
                                        $feature
                                    ): ?>
                                        <label class="plan-feature-row">
                                            <input
                                                type="checkbox"
                                                name="features[<?= (int) $feature['feature_id']; ?>]"
                                                value="1"
                                                class="plan-feature-checkbox"
                                                data-parent-module="<?= (int) $module['module_id']; ?>"
                                                <?= (int) $feature['is_selected'] === 1
                                                    ? 'checked'
                                                    : ''; ?>
                                            >

                                            <span>
                                                <span class="plan-feature-name">
                                                    <?= planModulesEscape(
                                                        $feature['feature_name']
                                                    ); ?>
                                                </span>

                                                <span class="plan-feature-description">
                                                    <?= planModulesEscape(
                                                        !empty(
                                                            $feature['description']
                                                        )
                                                            ? $feature['description']
                                                            : planModulesLabel(
                                                                $feature['feature_code']
                                                            )
                                                    ); ?>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="plan-module-empty">
                                    No individual features are configured for this module.
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="plan-modules-savebar">
                <div class="plan-modules-savebar-text">
                    Save the default access provided by this subscription plan.
                </div>

                <button
                    type="submit"
                    class="plan-modules-save"
                    id="planModulesSave"
                >
                    <i class="bi bi-check2-circle"></i>
                    Save Plan Access
                </button>
            </div>
        </form>

        <form
            method="post"
            action="plan-modules.php?plan_id=<?= (int) $planId; ?>"
            onsubmit="return confirm('Remove all modules and features from this plan?');"
        >
            <?php csrfField(); ?>

            <input
                type="hidden"
                name="plan_id"
                value="<?= (int) $planId; ?>"
            >

            <input
                type="hidden"
                name="action"
                value="clear_all"
            >

            <button
                type="submit"
                class="plan-modules-button danger"
            >
                <i class="bi bi-trash3"></i>
                Remove All Plan Access
            </button>
        </form>

    <?php else: ?>
        <div class="plan-modules-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span>
                Create a subscription plan before assigning modules and features.
            </span>
        </div>
    <?php endif; ?>

</div>

<script>
(function () {
    'use strict';

    const planSelector =
        document.getElementById(
            'planSelector'
        );

    if (planSelector) {
        planSelector.addEventListener(
            'change',
            function () {
                const planId =
                    parseInt(
                        planSelector.value,
                        10
                    );

                if (planId > 0) {
                    window.location.href =
                        'plan-modules.php?plan_id=' +
                        planId;
                }
            }
        );
    }

    const moduleCheckboxes =
        document.querySelectorAll(
            '.plan-module-checkbox'
        );

    moduleCheckboxes.forEach(
        function (moduleCheckbox) {
            const moduleId =
                moduleCheckbox.getAttribute(
                    'data-module-id'
                );

            const featureCheckboxes =
                document.querySelectorAll(
                    '.plan-feature-checkbox[data-parent-module="' +
                    moduleId +
                    '"]'
                );

            moduleCheckbox.addEventListener(
                'change',
                function () {
                    featureCheckboxes.forEach(
                        function (featureCheckbox) {
                            if (!moduleCheckbox.checked) {
                                featureCheckbox.checked = false;
                            }

                            featureCheckbox.disabled =
                                !moduleCheckbox.checked;
                        }
                    );
                }
            );

            moduleCheckbox.dispatchEvent(
                new Event('change')
            );
        }
    );

    document
        .querySelectorAll(
            '.plan-feature-checkbox'
        )
        .forEach(
            function (featureCheckbox) {
                featureCheckbox.addEventListener(
                    'change',
                    function () {
                        if (!featureCheckbox.checked) {
                            return;
                        }

                        const moduleId =
                            featureCheckbox.getAttribute(
                                'data-parent-module'
                            );

                        const moduleCheckbox =
                            document.querySelector(
                                '.plan-module-checkbox[data-module-id="' +
                                moduleId +
                                '"]'
                            );

                        if (
                            moduleCheckbox &&
                            !moduleCheckbox.checked
                        ) {
                            moduleCheckbox.checked = true;
                            moduleCheckbox.dispatchEvent(
                                new Event('change')
                            );

                            featureCheckbox.checked = true;
                        }
                    }
                );
            }
        );

    const enableAll =
        document.getElementById(
            'enableAllModules'
        );

    const disableAll =
        document.getElementById(
            'disableAllModules'
        );

    if (enableAll) {
        enableAll.addEventListener(
            'click',
            function () {
                moduleCheckboxes.forEach(
                    function (checkbox) {
                        checkbox.checked = true;
                        checkbox.dispatchEvent(
                            new Event('change')
                        );
                    }
                );

                document
                    .querySelectorAll(
                        '.plan-feature-checkbox'
                    )
                    .forEach(
                        function (checkbox) {
                            checkbox.checked = true;
                        }
                    );
            }
        );
    }

    if (disableAll) {
        disableAll.addEventListener(
            'click',
            function () {
                moduleCheckboxes.forEach(
                    function (checkbox) {
                        checkbox.checked = false;
                        checkbox.dispatchEvent(
                            new Event('change')
                        );
                    }
                );
            }
        );
    }

    const form =
        document.getElementById(
            'planModulesForm'
        );

    const saveButton =
        document.getElementById(
            'planModulesSave'
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
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
