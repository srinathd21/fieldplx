<?php
/**
 * FieldPlx Platform - Edit Module
 *
 * File:
 * platform/module-edit.php
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

$pageTitle = 'Edit Module - FieldPlx';
$activePage = 'module-edit';
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

if (!function_exists('moduleEditEscape')) {
    function moduleEditEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('moduleEditPost')) {
    function moduleEditPost(
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

if (!function_exists('moduleEditCurrentUserId')) {
    function moduleEditCurrentUserId()
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

if (!function_exists('moduleEditNormaliseCode')) {
    function moduleEditNormaliseCode($value)
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

/*
|--------------------------------------------------------------------------
| Create and upgrade modules table
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

$moduleColumns = array();

$moduleColumnsResult = $conn->query("
    SHOW COLUMNS FROM `modules`
");

while ($moduleColumn = $moduleColumnsResult->fetch_assoc()) {
    $moduleColumns[
        (string) $moduleColumn['Field']
    ] = true;
}

$moduleColumnsResult->free();

$moduleColumnUpgrades = array(
    'description' => "
        ALTER TABLE `modules`
        ADD COLUMN `description`
            VARCHAR(500) DEFAULT NULL
        AFTER `module_name`
    ",
    'icon_class' => "
        ALTER TABLE `modules`
        ADD COLUMN `icon_class`
            VARCHAR(100) DEFAULT NULL
        AFTER `description`
    ",
    'menu_url' => "
        ALTER TABLE `modules`
        ADD COLUMN `menu_url`
            VARCHAR(255) DEFAULT NULL
        AFTER `icon_class`
    ",
    'menu_order' => "
        ALTER TABLE `modules`
        ADD COLUMN `menu_order`
            INT(11) NOT NULL DEFAULT 0
        AFTER `menu_url`
    ",
    'is_core' => "
        ALTER TABLE `modules`
        ADD COLUMN `is_core`
            TINYINT(1) NOT NULL DEFAULT 0
        AFTER `menu_order`
    ",
    'is_active' => "
        ALTER TABLE `modules`
        ADD COLUMN `is_active`
            TINYINT(1) NOT NULL DEFAULT 1
        AFTER `is_core`
    ",
    'updated_by' => "
        ALTER TABLE `modules`
        ADD COLUMN `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL
        AFTER `is_active`
    ",
    'created_at' => "
        ALTER TABLE `modules`
        ADD COLUMN `created_at`
            DATETIME NOT NULL
            DEFAULT CURRENT_TIMESTAMP
        AFTER `updated_by`
    ",
    'updated_at' => "
        ALTER TABLE `modules`
        ADD COLUMN `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP
        AFTER `created_at`
    "
);

foreach (
    $moduleColumnUpgrades as
    $columnName =>
    $alterSql
) {
    if (!isset($moduleColumns[$columnName])) {
        $conn->query($alterSql);
    }
}

/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$moduleId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (
        isset($_POST['id'])
            ? (int) $_POST['id']
            : 0
    );

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

$moduleStmt = $conn->prepare("
    SELECT *
    FROM modules
    WHERE `id` = ?
    LIMIT 1
");

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
| Form values
|--------------------------------------------------------------------------
*/

$moduleName = isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? moduleEditPost('module_name')
        : (string) $module['module_name'];

$moduleCode = isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? moduleEditPost('module_code')
        : (string) $module['module_code'];

$description = isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? moduleEditPost('description')
        : (string) $module['description'];

$iconClass = isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? moduleEditPost(
            'icon_class',
            'bi bi-grid'
        )
        : (
            !empty($module['icon_class'])
                ? (string) $module['icon_class']
                : 'bi bi-grid'
        );

$menuUrl = isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? moduleEditPost('menu_url')
        : (string) $module['menu_url'];

$menuOrder = isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? moduleEditPost(
            'menu_order',
            '0'
        )
        : (string) $module['menu_order'];

$isCore = isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? (
            !empty($_POST['is_core'])
                ? 1
                : 0
        )
        : (int) $module['is_core'];

$isActive = isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? (
            !empty($_POST['is_active'])
                ? 1
                : 0
        )
        : (int) $module['is_active'];

$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Save module
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $moduleCode = moduleEditNormaliseCode(
        $moduleCode !== ''
            ? $moduleCode
            : $moduleName
    );

    $menuOrderValue = (int) $menuOrder;

    if ($moduleName === '') {
        $errorMessage =
            'Enter the module name.';
    } elseif (strlen($moduleName) > 150) {
        $errorMessage =
            'Module name must not exceed 150 characters.';
    } elseif ($moduleCode === '') {
        $errorMessage =
            'Enter a valid module code.';
    } elseif (strlen($moduleCode) > 100) {
        $errorMessage =
            'Module code must not exceed 100 characters.';
    } elseif (
        !preg_match(
            '/^[a-z0-9_]+$/',
            $moduleCode
        )
    ) {
        $errorMessage =
            'Module code can contain lowercase letters, numbers, and underscores only.';
    } elseif (strlen($description) > 500) {
        $errorMessage =
            'Description must not exceed 500 characters.';
    } elseif (strlen($iconClass) > 100) {
        $errorMessage =
            'Icon class must not exceed 100 characters.';
    } elseif (strlen($menuUrl) > 255) {
        $errorMessage =
            'Menu URL must not exceed 255 characters.';
    } elseif (
        $menuOrderValue < 0 ||
        $menuOrderValue > 100000
    ) {
        $errorMessage =
            'Menu order must be between 0 and 100000.';
    }

    if ($errorMessage === '') {
        $duplicateStmt = $conn->prepare("
            SELECT `id`
            FROM modules
            WHERE LOWER(`module_code`) =
                LOWER(?)
              AND `id` <> ?
            LIMIT 1
        ");

        $duplicateStmt->bind_param(
            'si',
            $moduleCode,
            $moduleId
        );

        $duplicateStmt->execute();

        $duplicateModule =
            $duplicateStmt
            ->get_result()
            ->fetch_assoc();

        $duplicateStmt->close();

        if ($duplicateModule) {
            $errorMessage =
                'This module code already exists.';
        }
    }

    if (
        $errorMessage === '' &&
        (int) $module['is_core'] === 1 &&
        $isCore !== 1
    ) {
        $isCore = 1;
    }

    if ($errorMessage === '') {
        try {
            $updatedBy =
                moduleEditCurrentUserId();

            $stmt = $conn->prepare("
                UPDATE modules
                SET
                    `module_code` = ?,
                    `module_name` = ?,
                    `description` = ?,
                    `icon_class` = ?,
                    `menu_url` = ?,
                    `menu_order` = ?,
                    `is_core` = ?,
                    `is_active` = ?,
                    `updated_by` = ?,
                    `updated_at` = NOW()
                WHERE `id` = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'sssssiiiii',
                $moduleCode,
                $moduleName,
                $description,
                $iconClass,
                $menuUrl,
                $menuOrderValue,
                $isCore,
                $isActive,
                $updatedBy,
                $moduleId
            );

            $stmt->execute();
            $stmt->close();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Module updated successfully.';

            header(
                'Location: module-view.php?id=' .
                $moduleId,
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            error_log(
                'Module update failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to update the module: ' .
                $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .module-edit-page {
        max-width: 980px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .module-edit-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .module-edit-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .module-edit-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .module-edit-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .module-edit-button {
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

    .module-edit-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .module-edit-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid #fecaca;
        border-radius: 10px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 10px;
        line-height: 1.55;
    }

    .module-edit-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(260px, 320px);
        gap: 15px;
        align-items: start;
    }

    .module-edit-column {
        display: grid;
        gap: 15px;
    }

    .module-edit-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31,41,55,.03);
    }

    .module-edit-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .module-edit-card-icon {
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

    .module-edit-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .module-edit-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-edit-card-body {
        padding: 15px;
    }

    .module-edit-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .module-edit-required {
        color: #dc2626;
    }

    .module-edit-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        color: #374151;
        font-size: 10px;
    }

    textarea.module-edit-control {
        min-height: 105px;
        resize: vertical;
    }

    .module-edit-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124,58,237,.08);
    }

    .module-edit-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .module-edit-preview {
        padding: 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fafafa;
    }

    .module-edit-preview-icon {
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 15px;
    }

    .module-edit-preview-name {
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 800;
    }

    .module-edit-preview-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-edit-toggle {
        padding: 11px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #fafafa;
        color: #4b5563;
        font-size: 9px;
        line-height: 1.45;
    }

    .module-edit-toggle + .module-edit-toggle {
        margin-top: 8px;
    }

    .module-edit-toggle strong {
        color: #111827;
    }

    .module-edit-toggle.locked {
        opacity: .72;
    }

    .module-edit-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .module-edit-submit {
        min-height: 41px;
        border: 0;
        border-radius: 9px;
        background:
            linear-gradient(
                135deg,
                #7c3aed,
                #6d28d9
            );
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
    }

    .module-edit-meta {
        display: grid;
        gap: 8px;
    }

    .module-edit-meta-row {
        padding: 9px 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border: 1px solid #eef0f3;
        border-radius: 8px;
        background: #fafafa;
    }

    .module-edit-meta-label {
        color: #6b7280;
        font-size: 8px;
    }

    .module-edit-meta-value {
        color: #111827;
        font-size: 8px;
        font-weight: 700;
    }

    @media (max-width: 850px) {
        .module-edit-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 650px) {
        .module-edit-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .module-edit-actions {
            width: 100%;
        }

        .module-edit-button {
            flex: 1;
        }
    }
</style>

<div class="module-edit-page">

    <div class="module-edit-header">
        <div>
            <h2 class="module-edit-title">
                Edit Module
            </h2>

            <div class="module-edit-description">
                Update the module information and platform behaviour.
            </div>
        </div>

        <div class="module-edit-actions">
            <a
                href="module-view.php?id=<?= (int) $moduleId; ?>"
                class="module-edit-button"
            >
                <i class="bi bi-eye"></i>
                View Module
            </a>

            <a
                href="modules.php"
                class="module-edit-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Modules
            </a>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="module-edit-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= moduleEditEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="module-edit.php?id=<?= (int) $moduleId; ?>"
        id="moduleEditForm"
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="id"
            value="<?= (int) $moduleId; ?>"
        >

        <div class="module-edit-layout">

            <div class="module-edit-column">

                <section class="module-edit-card">
                    <div class="module-edit-card-header">
                        <span class="module-edit-card-icon">
                            <i class="bi bi-grid-3x3-gap"></i>
                        </span>

                        <div>
                            <h3 class="module-edit-card-title">
                                Module Information
                            </h3>

                            <div class="module-edit-card-subtitle">
                                Name, unique code, and menu details
                            </div>
                        </div>
                    </div>

                    <div class="module-edit-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="module-edit-label">
                                    Module Name
                                    <span class="module-edit-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="module_name"
                                    id="moduleName"
                                    class="form-control module-edit-control"
                                    value="<?= moduleEditEscape(
                                        $moduleName
                                    ); ?>"
                                    maxlength="150"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="module-edit-label">
                                    Module Code
                                    <span class="module-edit-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="module_code"
                                    id="moduleCode"
                                    class="form-control module-edit-control"
                                    value="<?= moduleEditEscape(
                                        $moduleCode
                                    ); ?>"
                                    maxlength="100"
                                    required
                                >

                                <div class="module-edit-help">
                                    Lowercase letters, numbers, and underscores only.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="module-edit-label">
                                    Icon Class
                                </label>

                                <input
                                    type="text"
                                    name="icon_class"
                                    id="iconClass"
                                    class="form-control module-edit-control"
                                    value="<?= moduleEditEscape(
                                        $iconClass
                                    ); ?>"
                                    maxlength="100"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="module-edit-label">
                                    Menu URL
                                </label>

                                <input
                                    type="text"
                                    name="menu_url"
                                    class="form-control module-edit-control"
                                    value="<?= moduleEditEscape(
                                        $menuUrl
                                    ); ?>"
                                    maxlength="255"
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="module-edit-label">
                                    Menu Order
                                </label>

                                <input
                                    type="number"
                                    name="menu_order"
                                    class="form-control module-edit-control"
                                    value="<?= moduleEditEscape(
                                        $menuOrder
                                    ); ?>"
                                    min="0"
                                    max="100000"
                                >
                            </div>

                            <div class="col-md-8">
                                <label class="module-edit-label">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    class="form-control module-edit-control"
                                    maxlength="500"
                                ><?= moduleEditEscape(
                                    $description
                                ); ?></textarea>
                            </div>

                        </div>
                    </div>
                </section>

            </div>

            <aside class="module-edit-column">

                <section class="module-edit-card">
                    <div class="module-edit-card-header">
                        <span class="module-edit-card-icon">
                            <i class="bi bi-eye"></i>
                        </span>

                        <div>
                            <h3 class="module-edit-card-title">
                                Preview
                            </h3>

                            <div class="module-edit-card-subtitle">
                                Sidebar module appearance
                            </div>
                        </div>
                    </div>

                    <div class="module-edit-card-body">
                        <div class="module-edit-preview">
                            <span
                                class="module-edit-preview-icon"
                                id="previewIcon"
                            >
                                <i class="<?= moduleEditEscape(
                                    $iconClass !== ''
                                        ? $iconClass
                                        : 'bi bi-grid'
                                ); ?>"></i>
                            </span>

                            <span>
                                <span
                                    class="module-edit-preview-name"
                                    id="previewName"
                                >
                                    <?= moduleEditEscape(
                                        $moduleName
                                    ); ?>
                                </span>

                                <span
                                    class="module-edit-preview-code"
                                    id="previewCode"
                                >
                                    <?= moduleEditEscape(
                                        $moduleCode
                                    ); ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </section>

                <section class="module-edit-card">
                    <div class="module-edit-card-header">
                        <span class="module-edit-card-icon">
                            <i class="bi bi-toggles"></i>
                        </span>

                        <div>
                            <h3 class="module-edit-card-title">
                                Module Controls
                            </h3>

                            <div class="module-edit-card-subtitle">
                                Global module behaviour
                            </div>
                        </div>
                    </div>

                    <div class="module-edit-card-body">
                        <label class="module-edit-toggle <?= (int) $module['is_core'] === 1
                            ? 'locked'
                            : ''; ?>">
                            <input
                                type="checkbox"
                                name="is_core"
                                value="1"
                                <?= $isCore === 1
                                    ? 'checked'
                                    : ''; ?>
                                <?= (int) $module['is_core'] === 1
                                    ? 'disabled'
                                    : ''; ?>
                            >

                            <?php if ((int) $module['is_core'] === 1): ?>
                                <input
                                    type="hidden"
                                    name="is_core"
                                    value="1"
                                >
                            <?php endif; ?>

                            <span>
                                <strong>Core Module</strong><br>
                                Core modules cannot be changed back to optional or deleted.
                            </span>
                        </label>

                        <label class="module-edit-toggle">
                            <input
                                type="checkbox"
                                name="is_active"
                                value="1"
                                <?= $isActive === 1
                                    ? 'checked'
                                    : ''; ?>
                            >

                            <span>
                                <strong>Active Module</strong><br>
                                Allow this module to be assigned to plans and tenants.
                            </span>
                        </label>
                    </div>
                </section>

                <section class="module-edit-card">
                    <div class="module-edit-card-header">
                        <span class="module-edit-card-icon">
                            <i class="bi bi-info-circle"></i>
                        </span>

                        <div>
                            <h3 class="module-edit-card-title">
                                Record Information
                            </h3>

                            <div class="module-edit-card-subtitle">
                                Module identifiers and timestamps
                            </div>
                        </div>
                    </div>

                    <div class="module-edit-card-body">
                        <div class="module-edit-meta">
                            <div class="module-edit-meta-row">
                                <span class="module-edit-meta-label">
                                    Module ID
                                </span>

                                <span class="module-edit-meta-value">
                                    <?= (int) $moduleId; ?>
                                </span>
                            </div>

                            <div class="module-edit-meta-row">
                                <span class="module-edit-meta-label">
                                    Created
                                </span>

                                <span class="module-edit-meta-value">
                                    <?= moduleEditEscape(
                                        !empty($module['created_at'])
                                            ? date(
                                                'd M Y, h:i A',
                                                strtotime(
                                                    $module['created_at']
                                                )
                                            )
                                            : '—'
                                    ); ?>
                                </span>
                            </div>

                            <div class="module-edit-meta-row">
                                <span class="module-edit-meta-label">
                                    Updated
                                </span>

                                <span class="module-edit-meta-value">
                                    <?= moduleEditEscape(
                                        !empty($module['updated_at'])
                                            ? date(
                                                'd M Y, h:i A',
                                                strtotime(
                                                    $module['updated_at']
                                                )
                                            )
                                            : '—'
                                    ); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="module-edit-submit-card">
                    <button
                        type="submit"
                        class="module-edit-submit"
                        id="moduleEditSubmit"
                    >
                        <i class="bi bi-check2-circle me-1"></i>
                        Save Module Changes
                    </button>
                </div>

            </aside>

        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const moduleName =
        document.getElementById(
            'moduleName'
        );

    const moduleCode =
        document.getElementById(
            'moduleCode'
        );

    const iconClass =
        document.getElementById(
            'iconClass'
        );

    const previewName =
        document.getElementById(
            'previewName'
        );

    const previewCode =
        document.getElementById(
            'previewCode'
        );

    const previewIcon =
        document.getElementById(
            'previewIcon'
        );

    function normaliseCode(value) {
        return String(value)
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    if (moduleCode) {
        moduleCode.addEventListener(
            'input',
            function () {
                moduleCode.value =
                    normaliseCode(
                        moduleCode.value
                    );

                if (previewCode) {
                    previewCode.textContent =
                        moduleCode.value ||
                        'module_code';
                }
            }
        );
    }

    if (moduleName && previewName) {
        moduleName.addEventListener(
            'input',
            function () {
                previewName.textContent =
                    moduleName.value.trim() ||
                    'Module Name';
            }
        );
    }

    if (iconClass && previewIcon) {
        iconClass.addEventListener(
            'input',
            function () {
                const className =
                    iconClass.value.trim() ||
                    'bi bi-grid';

                previewIcon.innerHTML =
                    '<i class="' +
                    className
                        .replace(/"/g, '') +
                    '"></i>';
            }
        );
    }

    const form =
        document.getElementById(
            'moduleEditForm'
        );

    const submitButton =
        document.getElementById(
            'moduleEditSubmit'
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
