<?php
/**
 * FieldPlx Platform - Module Features
 *
 * File:
 * platform/module-features.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin'
));

$pageTitle = 'Module Features - FieldPlx';
$activePage = 'module-features';
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

if (!function_exists('moduleFeaturesEscape')) {
    function moduleFeaturesEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('moduleFeaturesPost')) {
    function moduleFeaturesPost(
        $key,
        $default = ''
    ) {
        if (
            !isset($_POST[$key]) ||
            is_array($_POST[$key])
        ) {
            return $default;
        }

        return trim((string) $_POST[$key]);
    }
}

if (!function_exists('moduleFeaturesGet')) {
    function moduleFeaturesGet(
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

if (!function_exists('moduleFeaturesCurrentUserId')) {
    function moduleFeaturesCurrentUserId()
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

if (!function_exists('moduleFeaturesNormaliseCode')) {
    function moduleFeaturesNormaliseCode($value)
    {
        $value = strtolower(
            trim((string) $value)
        );

        $value = preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $value
        );

        return trim(
            (string) $value,
            '_'
        );
    }
}

if (!function_exists('moduleFeaturesTableExists')) {
    function moduleFeaturesTableExists(
        mysqli $conn,
        $tableName
    ) {
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

        return !empty($row['total']);
    }
}

/*
|--------------------------------------------------------------------------
| Create and upgrade tables
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
            (`module_code`)
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
            (`module_id`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");

$featureColumns = array();

$featureColumnsResult = $conn->query("
    SHOW COLUMNS FROM `module_features`
");

while ($featureColumn = $featureColumnsResult->fetch_assoc()) {
    $featureColumns[
        (string) $featureColumn['Field']
    ] = true;
}

$featureColumnsResult->free();

$featureColumnUpgrades = array(
    'description' => "
        ALTER TABLE `module_features`
        ADD COLUMN `description`
            VARCHAR(500) DEFAULT NULL
        AFTER `feature_name`
    ",
    'is_active' => "
        ALTER TABLE `module_features`
        ADD COLUMN `is_active`
            TINYINT(1) NOT NULL DEFAULT 1
        AFTER `description`
    ",
    'updated_by' => "
        ALTER TABLE `module_features`
        ADD COLUMN `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL
        AFTER `is_active`
    ",
    'created_at' => "
        ALTER TABLE `module_features`
        ADD COLUMN `created_at`
            DATETIME NOT NULL
            DEFAULT CURRENT_TIMESTAMP
        AFTER `updated_by`
    ",
    'updated_at' => "
        ALTER TABLE `module_features`
        ADD COLUMN `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP
        AFTER `created_at`
    "
);

foreach (
    $featureColumnUpgrades as
    $columnName =>
    $alterSql
) {
    if (!isset($featureColumns[$columnName])) {
        $conn->query($alterSql);
    }
}

/*
|--------------------------------------------------------------------------
| Module selection
|--------------------------------------------------------------------------
*/

$moduleId = isset($_GET['module_id'])
    ? (int) $_GET['module_id']
    : (
        isset($_POST['module_id'])
            ? (int) $_POST['module_id']
            : 0
    );

$modules = array();

$modulesResult = $conn->query("
    SELECT
        `id`,
        `module_code`,
        `module_name`,
        `description`,
        `icon_class`,
        `is_active`
    FROM modules
    ORDER BY
        `menu_order` ASC,
        `module_name` ASC
");

while ($moduleRow = $modulesResult->fetch_assoc()) {
    $modules[] = $moduleRow;
}

$modulesResult->free();

if (
    $moduleId <= 0 &&
    !empty($modules)
) {
    $moduleId =
        (int) $modules[0]['id'];
}

$currentModule = null;

foreach ($modules as $moduleItem) {
    if ((int) $moduleItem['id'] === $moduleId) {
        $currentModule = $moduleItem;
        break;
    }
}

if (
    $moduleId > 0 &&
    !$currentModule
) {
    $_SESSION['platform_error_message'] =
        'Selected module was not found.';

    header('Location: modules.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Form defaults
|--------------------------------------------------------------------------
*/

$editingFeature = null;
$editFeatureId = isset($_GET['edit_id'])
    ? (int) $_GET['edit_id']
    : 0;

if (
    $editFeatureId > 0 &&
    $moduleId > 0
) {
    $editStmt = $conn->prepare("
        SELECT *
        FROM module_features
        WHERE `id` = ?
          AND `module_id` = ?
        LIMIT 1
    ");

    $editStmt->bind_param(
        'ii',
        $editFeatureId,
        $moduleId
    );

    $editStmt->execute();

    $editingFeature =
        $editStmt
        ->get_result()
        ->fetch_assoc();

    $editStmt->close();
}

$featureName = $editingFeature
    ? (string) $editingFeature['feature_name']
    : '';

$featureCode = $editingFeature
    ? (string) $editingFeature['feature_code']
    : '';

$description = $editingFeature
    ? (string) $editingFeature['description']
    : '';

$isActive = $editingFeature
    ? (int) $editingFeature['is_active']
    : 1;

$errorMessage = '';
$successMessage = '';

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $action = moduleFeaturesPost(
        'action',
        'save'
    );

    try {
        if (
            $action === 'save' &&
            $moduleId > 0
        ) {
            $featureId = (int)
                moduleFeaturesPost(
                    'feature_id',
                    '0'
                );

            $featureName =
                moduleFeaturesPost(
                    'feature_name'
                );

            $featureCode =
                moduleFeaturesNormaliseCode(
                    moduleFeaturesPost(
                        'feature_code',
                        $featureName
                    )
                );

            $description =
                moduleFeaturesPost(
                    'description'
                );

            $isActive =
                !empty($_POST['is_active'])
                    ? 1
                    : 0;

            if ($featureName === '') {
                throw new RuntimeException(
                    'Enter the feature name.'
                );
            }

            if (strlen($featureName) > 150) {
                throw new RuntimeException(
                    'Feature name must not exceed 150 characters.'
                );
            }

            if ($featureCode === '') {
                throw new RuntimeException(
                    'Enter a valid feature code.'
                );
            }

            if (strlen($featureCode) > 120) {
                throw new RuntimeException(
                    'Feature code must not exceed 120 characters.'
                );
            }

            if (
                !preg_match(
                    '/^[a-z0-9_]+$/',
                    $featureCode
                )
            ) {
                throw new RuntimeException(
                    'Feature code can contain lowercase letters, numbers, and underscores only.'
                );
            }

            if (strlen($description) > 500) {
                throw new RuntimeException(
                    'Description must not exceed 500 characters.'
                );
            }

            $duplicateSql = "
                SELECT `id`
                FROM module_features
                WHERE `module_id` = ?
                  AND LOWER(`feature_code`) =
                      LOWER(?)
            ";

            if ($featureId > 0) {
                $duplicateSql .= "
                    AND `id` <> ?
                ";
            }

            $duplicateSql .= "
                LIMIT 1
            ";

            $duplicateStmt =
                $conn->prepare(
                    $duplicateSql
                );

            if ($featureId > 0) {
                $duplicateStmt->bind_param(
                    'isi',
                    $moduleId,
                    $featureCode,
                    $featureId
                );
            } else {
                $duplicateStmt->bind_param(
                    'is',
                    $moduleId,
                    $featureCode
                );
            }

            $duplicateStmt->execute();

            $duplicateFeature =
                $duplicateStmt
                ->get_result()
                ->fetch_assoc();

            $duplicateStmt->close();

            if ($duplicateFeature) {
                throw new RuntimeException(
                    'This feature code already exists for the selected module.'
                );
            }

            $updatedBy =
                moduleFeaturesCurrentUserId();

            if ($featureId > 0) {
                $updateStmt = $conn->prepare("
                    UPDATE module_features
                    SET
                        `feature_code` = ?,
                        `feature_name` = ?,
                        `description` = ?,
                        `is_active` = ?,
                        `updated_by` = ?,
                        `updated_at` = NOW()
                    WHERE `id` = ?
                      AND `module_id` = ?
                    LIMIT 1
                ");

                $updateStmt->bind_param(
                    'sssiiii',
                    $featureCode,
                    $featureName,
                    $description,
                    $isActive,
                    $updatedBy,
                    $featureId,
                    $moduleId
                );

                $updateStmt->execute();
                $updateStmt->close();

                $message =
                    'Feature updated successfully.';
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO module_features (
                        `module_id`,
                        `feature_code`,
                        `feature_name`,
                        `description`,
                        `is_active`,
                        `updated_by`,
                        `created_at`,
                        `updated_at`
                    ) VALUES (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        NOW(),
                        NOW()
                    )
                ");

                $insertStmt->bind_param(
                    'isssii',
                    $moduleId,
                    $featureCode,
                    $featureName,
                    $description,
                    $isActive,
                    $updatedBy
                );

                $insertStmt->execute();
                $insertStmt->close();

                $message =
                    'Feature added successfully.';
            }

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                $message;

            header(
                'Location: module-features.php?module_id=' .
                $moduleId,
                true,
                303
            );

            exit;
        }

        if (
            in_array(
                $action,
                array(
                    'activate',
                    'deactivate'
                ),
                true
            )
        ) {
            $featureId = (int)
                moduleFeaturesPost(
                    'feature_id',
                    '0'
                );

            if ($featureId <= 0) {
                throw new RuntimeException(
                    'Invalid feature record.'
                );
            }

            $newStatus =
                $action === 'activate'
                    ? 1
                    : 0;

            $updatedBy =
                moduleFeaturesCurrentUserId();

            $statusStmt = $conn->prepare("
                UPDATE module_features
                SET
                    `is_active` = ?,
                    `updated_by` = ?,
                    `updated_at` = NOW()
                WHERE `id` = ?
                  AND `module_id` = ?
                LIMIT 1
            ");

            $statusStmt->bind_param(
                'iiii',
                $newStatus,
                $updatedBy,
                $featureId,
                $moduleId
            );

            $statusStmt->execute();
            $statusStmt->close();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                $newStatus === 1
                    ? 'Feature activated successfully.'
                    : 'Feature deactivated successfully.';

            header(
                'Location: module-features.php?module_id=' .
                $moduleId,
                true,
                303
            );

            exit;
        }

        if ($action === 'delete') {
            $featureId = (int)
                moduleFeaturesPost(
                    'feature_id',
                    '0'
                );

            if ($featureId <= 0) {
                throw new RuntimeException(
                    'Invalid feature record.'
                );
            }

            $conn->begin_transaction();

            foreach (
                array(
                    'tenant_features',
                    'plan_features'
                ) as $relationTable
            ) {
                if (
                    moduleFeaturesTableExists(
                        $conn,
                        $relationTable
                    )
                ) {
                    $deleteRelationStmt =
                        $conn->prepare("
                            DELETE FROM `{$relationTable}`
                            WHERE `feature_id` = ?
                        ");

                    $deleteRelationStmt->bind_param(
                        'i',
                        $featureId
                    );

                    $deleteRelationStmt->execute();
                    $deleteRelationStmt->close();
                }
            }

            $deleteStmt = $conn->prepare("
                DELETE FROM module_features
                WHERE `id` = ?
                  AND `module_id` = ?
                LIMIT 1
            ");

            $deleteStmt->bind_param(
                'ii',
                $featureId,
                $moduleId
            );

            $deleteStmt->execute();
            $deleteStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Feature deleted successfully.';

            header(
                'Location: module-features.php?module_id=' .
                $moduleId,
                true,
                303
            );

            exit;
        }
    } catch (Exception $exception) {
        try {
            $conn->rollback();
        } catch (Exception $ignored) {
            // No active transaction.
        }

        error_log(
            'Module feature action failed: ' .
            $exception->getMessage()
        );

        $errorMessage =
            'Unable to complete the feature action: ' .
            $exception->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Load features
|--------------------------------------------------------------------------
*/

$features = array();

if ($moduleId > 0) {
    $featuresStmt = $conn->prepare("
        SELECT *
        FROM module_features
        WHERE `module_id` = ?
        ORDER BY
            `feature_name` ASC
    ");

    $featuresStmt->bind_param(
        'i',
        $moduleId
    );

    $featuresStmt->execute();

    $featuresResult =
        $featuresStmt->get_result();

    while (
        $featureRow =
        $featuresResult->fetch_assoc()
    ) {
        $features[] = $featureRow;
    }

    $featuresStmt->close();
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
    .module-features-page {
        display: grid;
        gap: 15px;
    }

    .module-features-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .module-features-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .module-features-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .module-features-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .module-features-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .module-features-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .module-features-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .module-features-button {
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

    .module-features-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .module-selector-card {
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

    .module-selector-info {
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .module-selector-icon {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 15px;
    }

    .module-selector-name {
        color: #111827;
        font-size: 13px;
        font-weight: 800;
    }

    .module-selector-meta {
        margin-top: 3px;
        color: #6b7280;
        font-size: 8px;
    }

    .module-selector-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        color: #374151;
        font-size: 9px;
    }

    .module-features-summary {
        display: grid;
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
        gap: 10px;
    }

    .module-features-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
    }

    .module-features-summary-icon {
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

    .module-features-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .module-features-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .module-features-layout {
        display: grid;
        grid-template-columns:
            minmax(300px, 0.42fr)
            minmax(0, 0.58fr);
        gap: 15px;
        align-items: start;
    }

    .module-feature-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .module-feature-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .module-feature-card-icon {
        width: 31px;
        height: 31px;
        flex: 0 0 31px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 12px;
    }

    .module-feature-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .module-feature-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-feature-card-body {
        padding: 15px;
    }

    .module-feature-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .module-feature-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        color: #374151;
        font-size: 10px;
    }

    textarea.module-feature-control {
        min-height: 95px;
        resize: vertical;
    }

    .module-feature-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124,58,237,.08);
    }

    .module-feature-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-feature-toggle {
        padding: 10px 11px;
        display: flex;
        align-items: center;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #fafafa;
        color: #4b5563;
        font-size: 9px;
    }

    .module-feature-submit {
        width: 100%;
        min-height: 40px;
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

    .module-feature-cancel {
        margin-top: 8px;
        width: 100%;
    }

    .module-feature-list {
        display: grid;
        gap: 8px;
    }

    .module-feature-row {
        padding: 11px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .module-feature-row-icon {
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

    .module-feature-row-content {
        min-width: 0;
        flex: 1;
    }

    .module-feature-row-name {
        display: block;
        color: #111827;
        font-size: 9px;
        font-weight: 800;
    }

    .module-feature-row-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-feature-row-description {
        margin-top: 5px;
        display: block;
        color: #6b7280;
        font-size: 8px;
        line-height: 1.5;
    }

    .module-feature-row-side {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .module-feature-status {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 7px;
        font-weight: 700;
    }

    .module-feature-status.active {
        background: #ecfdf5;
        color: #047857;
    }

    .module-feature-status.inactive {
        background: #fef2f2;
        color: #b91c1c;
    }

    .module-feature-action {
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

    .module-feature-action:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .module-feature-action.danger:hover {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .module-feature-empty {
        padding: 36px 15px;
        color: #9ca3af;
        font-size: 9px;
        text-align: center;
    }

    @media (max-width: 950px) {
        .module-features-layout {
            grid-template-columns: 1fr;
        }

        .module-selector-card {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 700px) {
        .module-features-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .module-features-actions {
            width: 100%;
        }

        .module-features-button {
            flex: 1;
        }

        .module-features-summary {
            grid-template-columns: 1fr;
        }

        .module-feature-row {
            align-items: stretch;
            flex-direction: column;
        }

        .module-feature-row-side {
            justify-content: flex-end;
        }
    }
</style>

<div class="module-features-page">

    <?php if ($successMessage !== ''): ?>
        <div class="module-features-alert success">
            <i class="bi bi-check-circle"></i>

            <span>
                <?= moduleFeaturesEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="module-features-alert danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= moduleFeaturesEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="module-features-header">
        <div>
            <h2 class="module-features-title">
                Module Features
            </h2>

            <div class="module-features-description">
                Manage the individual capabilities available inside each module.
            </div>
        </div>

        <div class="module-features-actions">
            <?php if ($currentModule): ?>
                <a
                    href="module-view.php?id=<?= (int) $moduleId; ?>"
                    class="module-features-button"
                >
                    <i class="bi bi-eye"></i>
                    View Module
                </a>
            <?php endif; ?>

            <a
                href="modules.php"
                class="module-features-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Modules
            </a>
        </div>
    </div>

    <section class="module-selector-card">
        <div class="module-selector-info">
            <span class="module-selector-icon">
                <i class="<?= moduleFeaturesEscape(
                    $currentModule &&
                    !empty($currentModule['icon_class'])
                        ? $currentModule['icon_class']
                        : 'bi bi-grid'
                ); ?>"></i>
            </span>

            <span>
                <span class="module-selector-name">
                    <?= $currentModule
                        ? moduleFeaturesEscape(
                            $currentModule['module_name']
                        )
                        : 'Select Module'; ?>
                </span>

                <span class="module-selector-meta">
                    <?= $currentModule
                        ? moduleFeaturesEscape(
                            $currentModule['module_code']
                        )
                        : 'No modules available'; ?>
                </span>
            </span>
        </div>

        <select
            class="form-select module-selector-control"
            id="moduleSelector"
        >
            <?php foreach ($modules as $moduleItem): ?>
                <option
                    value="<?= (int) $moduleItem['id']; ?>"
                    <?= (int) $moduleItem['id'] ===
                        $moduleId
                            ? 'selected'
                            : ''; ?>
                >
                    <?= moduleFeaturesEscape(
                        $moduleItem['module_name']
                    ); ?>
                    (<?= moduleFeaturesEscape(
                        $moduleItem['module_code']
                    ); ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </section>

    <?php if ($currentModule): ?>

        <section class="module-features-summary">
            <article class="module-features-summary-card">
                <span class="module-features-summary-icon">
                    <i class="bi bi-ui-checks"></i>
                </span>

                <span>
                    <span class="module-features-summary-label">
                        Total Features
                    </span>

                    <span class="module-features-summary-value">
                        <?= number_format(
                            $totalFeatures
                        ); ?>
                    </span>
                </span>
            </article>

            <article class="module-features-summary-card">
                <span class="module-features-summary-icon">
                    <i class="bi bi-check-circle"></i>
                </span>

                <span>
                    <span class="module-features-summary-label">
                        Active Features
                    </span>

                    <span class="module-features-summary-value">
                        <?= number_format(
                            $activeFeatures
                        ); ?>
                    </span>
                </span>
            </article>

            <article class="module-features-summary-card">
                <span class="module-features-summary-icon">
                    <i class="bi bi-pause-circle"></i>
                </span>

                <span>
                    <span class="module-features-summary-label">
                        Inactive Features
                    </span>

                    <span class="module-features-summary-value">
                        <?= number_format(
                            $inactiveFeatures
                        ); ?>
                    </span>
                </span>
            </article>
        </section>

        <section class="module-features-layout">

            <article class="module-feature-card">
                <div class="module-feature-card-header">
                    <span class="module-feature-card-icon">
                        <i class="bi bi-plus-circle"></i>
                    </span>

                    <div>
                        <h3 class="module-feature-card-title">
                            <?= $editingFeature
                                ? 'Edit Feature'
                                : 'Add Feature'; ?>
                        </h3>

                        <div class="module-feature-card-subtitle">
                            <?= moduleFeaturesEscape(
                                $currentModule[
                                    'module_name'
                                ]
                            ); ?>
                        </div>
                    </div>
                </div>

                <div class="module-feature-card-body">
                    <form
                        method="post"
                        action="module-features.php?module_id=<?= (int) $moduleId; ?><?= $editingFeature
                            ? '&edit_id=' .
                              (int) $editingFeature['id']
                            : ''; ?>"
                        id="moduleFeatureForm"
                    >
                        <?php csrfField(); ?>

                        <input
                            type="hidden"
                            name="module_id"
                            value="<?= (int) $moduleId; ?>"
                        >

                        <input
                            type="hidden"
                            name="feature_id"
                            value="<?= $editingFeature
                                ? (int) $editingFeature['id']
                                : 0; ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="save"
                        >

                        <div class="mb-3">
                            <label class="module-feature-label">
                                Feature Name
                            </label>

                            <input
                                type="text"
                                name="feature_name"
                                id="featureName"
                                class="form-control module-feature-control"
                                value="<?= moduleFeaturesEscape(
                                    $featureName
                                ); ?>"
                                maxlength="150"
                                placeholder="Example: Create Invoice"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label class="module-feature-label">
                                Feature Code
                            </label>

                            <input
                                type="text"
                                name="feature_code"
                                id="featureCode"
                                class="form-control module-feature-control"
                                value="<?= moduleFeaturesEscape(
                                    $featureCode
                                ); ?>"
                                maxlength="120"
                                placeholder="create_invoice"
                                required
                            >

                            <div class="module-feature-help">
                                Lowercase letters, numbers, and underscores only.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="module-feature-label">
                                Description
                            </label>

                            <textarea
                                name="description"
                                class="form-control module-feature-control"
                                maxlength="500"
                                placeholder="Describe what this feature allows."
                            ><?= moduleFeaturesEscape(
                                $description
                            ); ?></textarea>
                        </div>

                        <label class="module-feature-toggle mb-3">
                            <input
                                type="checkbox"
                                name="is_active"
                                value="1"
                                <?= $isActive === 1
                                    ? 'checked'
                                    : ''; ?>
                            >

                            <span>
                                Active feature
                            </span>
                        </label>

                        <button
                            type="submit"
                            class="module-feature-submit"
                            id="moduleFeatureSubmit"
                        >
                            <i class="bi bi-check2-circle me-1"></i>
                            <?= $editingFeature
                                ? 'Save Feature Changes'
                                : 'Add Feature'; ?>
                        </button>

                        <?php if ($editingFeature): ?>
                            <a
                                href="module-features.php?module_id=<?= (int) $moduleId; ?>"
                                class="module-features-button module-feature-cancel"
                            >
                                Cancel Editing
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </article>

            <article class="module-feature-card">
                <div class="module-feature-card-header">
                    <span class="module-feature-card-icon">
                        <i class="bi bi-list-check"></i>
                    </span>

                    <div>
                        <h3 class="module-feature-card-title">
                            Feature List
                        </h3>

                        <div class="module-feature-card-subtitle">
                            Available capabilities for this module
                        </div>
                    </div>
                </div>

                <div class="module-feature-card-body">
                    <?php if (empty($features)): ?>
                        <div class="module-feature-empty">
                            No features have been added to this module.
                        </div>
                    <?php else: ?>
                        <div class="module-feature-list">
                            <?php foreach ($features as $feature): ?>
                                <div class="module-feature-row">
                                    <span class="module-feature-row-icon">
                                        <i class="bi bi-check2-square"></i>
                                    </span>

                                    <span class="module-feature-row-content">
                                        <span class="module-feature-row-name">
                                            <?= moduleFeaturesEscape(
                                                $feature[
                                                    'feature_name'
                                                ]
                                            ); ?>
                                        </span>

                                        <span class="module-feature-row-code">
                                            <?= moduleFeaturesEscape(
                                                $feature[
                                                    'feature_code'
                                                ]
                                            ); ?>
                                        </span>

                                        <?php if (
                                            trim(
                                                (string)
                                                $feature[
                                                    'description'
                                                ]
                                            ) !== ''
                                        ): ?>
                                            <span class="module-feature-row-description">
                                                <?= moduleFeaturesEscape(
                                                    $feature[
                                                        'description'
                                                    ]
                                                ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>

                                    <span class="module-feature-row-side">
                                        <span class="module-feature-status <?= (int) $feature['is_active'] === 1
                                            ? 'active'
                                            : 'inactive'; ?>">
                                            <?= (int) $feature['is_active'] === 1
                                                ? 'Active'
                                                : 'Inactive'; ?>
                                        </span>

                                        <a
                                            href="module-features.php?module_id=<?= (int) $moduleId; ?>&edit_id=<?= (int) $feature['id']; ?>"
                                            class="module-feature-action"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <form
                                            method="post"
                                            action="module-features.php?module_id=<?= (int) $moduleId; ?>"
                                            style="display:inline;"
                                        >
                                            <?php csrfField(); ?>

                                            <input
                                                type="hidden"
                                                name="module_id"
                                                value="<?= (int) $moduleId; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="feature_id"
                                                value="<?= (int) $feature['id']; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="<?= (int) $feature['is_active'] === 1
                                                    ? 'deactivate'
                                                    : 'activate'; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="module-feature-action"
                                                title="<?= (int) $feature['is_active'] === 1
                                                    ? 'Deactivate'
                                                    : 'Activate'; ?>"
                                            >
                                                <i class="bi <?= (int) $feature['is_active'] === 1
                                                    ? 'bi-pause-circle'
                                                    : 'bi-play-circle'; ?>"></i>
                                            </button>
                                        </form>

                                        <form
                                            method="post"
                                            action="module-features.php?module_id=<?= (int) $moduleId; ?>"
                                            style="display:inline;"
                                            onsubmit="return confirm('Delete this feature and remove it from all plans and tenants?');"
                                        >
                                            <?php csrfField(); ?>

                                            <input
                                                type="hidden"
                                                name="module_id"
                                                value="<?= (int) $moduleId; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="feature_id"
                                                value="<?= (int) $feature['id']; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <button
                                                type="submit"
                                                class="module-feature-action danger"
                                                title="Delete"
                                            >
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

        </section>

    <?php else: ?>
        <div class="module-features-alert danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                Create a module before adding module features.
            </span>
        </div>
    <?php endif; ?>

</div>

<script>
(function () {
    'use strict';

    const moduleSelector =
        document.getElementById(
            'moduleSelector'
        );

    if (moduleSelector) {
        moduleSelector.addEventListener(
            'change',
            function () {
                const moduleId =
                    parseInt(
                        moduleSelector.value,
                        10
                    );

                if (moduleId > 0) {
                    window.location.href =
                        'module-features.php?module_id=' +
                        moduleId;
                }
            }
        );
    }

    const featureName =
        document.getElementById(
            'featureName'
        );

    const featureCode =
        document.getElementById(
            'featureCode'
        );

    let codeEdited =
        featureCode &&
        featureCode.value.trim() !== '';

    function normaliseCode(value) {
        return String(value)
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    if (featureCode) {
        featureCode.addEventListener(
            'input',
            function () {
                codeEdited =
                    featureCode.value.trim() !== '';

                featureCode.value =
                    normaliseCode(
                        featureCode.value
                    );
            }
        );
    }

    if (
        featureName &&
        featureCode
    ) {
        featureName.addEventListener(
            'input',
            function () {
                if (!codeEdited) {
                    featureCode.value =
                        normaliseCode(
                            featureName.value
                        );
                }
            }
        );
    }

    const form =
        document.getElementById(
            'moduleFeatureForm'
        );

    const submitButton =
        document.getElementById(
            'moduleFeatureSubmit'
        );

    if (
        form &&
        submitButton
    ) {
        form.addEventListener(
            'submit',
            function () {
                submitButton.disabled = true;
                submitButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
