<?php
/**
 * FieldPlx Platform - Add Module
 *
 * File:
 * platform/module-add.php
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

$pageTitle = 'Add Module - FieldPlx';
$activePage = 'module-add';
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

if (!function_exists('moduleAddEscape')) {
    function moduleAddEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('moduleAddPost')) {
    function moduleAddPost(
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

if (!function_exists('moduleAddCurrentUserId')) {
    function moduleAddCurrentUserId()
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

if (!function_exists('moduleAddNormaliseCode')) {
    function moduleAddNormaliseCode($value)
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
| Form values
|--------------------------------------------------------------------------
*/

$moduleName = moduleAddPost('module_name');
$moduleCode = moduleAddPost('module_code');
$description = moduleAddPost('description');
$iconClass = moduleAddPost(
    'icon_class',
    'bi bi-grid'
);
$menuUrl = moduleAddPost('menu_url');
$menuOrder = moduleAddPost(
    'menu_order',
    '0'
);
$isCore = !empty($_POST['is_core'])
    ? 1
    : 0;
$isActive = isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? (
            !empty($_POST['is_active'])
                ? 1
                : 0
        )
        : 1;

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

    $moduleCode = moduleAddNormaliseCode(
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
            LIMIT 1
        ");

        $duplicateStmt->bind_param(
            's',
            $moduleCode
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

    if ($errorMessage === '') {
        try {
            $updatedBy =
                moduleAddCurrentUserId();

            $stmt = $conn->prepare("
                INSERT INTO modules (
                    `module_code`,
                    `module_name`,
                    `description`,
                    `icon_class`,
                    `menu_url`,
                    `menu_order`,
                    `is_core`,
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
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW()
                )
            ");

            $stmt->bind_param(
                'sssssiiii',
                $moduleCode,
                $moduleName,
                $description,
                $iconClass,
                $menuUrl,
                $menuOrderValue,
                $isCore,
                $isActive,
                $updatedBy
            );

            $stmt->execute();

            $newModuleId =
                (int) $stmt->insert_id;

            $stmt->close();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Module created successfully.';

            header(
                'Location: module-view.php?id=' .
                $newModuleId,
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            error_log(
                'Module creation failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to create the module: ' .
                $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .module-add-page {
        max-width: 980px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .module-add-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .module-add-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .module-add-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .module-add-button {
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

    .module-add-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .module-add-alert {
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

    .module-add-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(260px, 320px);
        gap: 15px;
        align-items: start;
    }

    .module-add-column {
        display: grid;
        gap: 15px;
    }

    .module-add-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31,41,55,.03);
    }

    .module-add-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .module-add-card-icon {
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

    .module-add-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .module-add-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-add-card-body {
        padding: 15px;
    }

    .module-add-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .module-add-required {
        color: #dc2626;
    }

    .module-add-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        color: #374151;
        font-size: 10px;
    }

    textarea.module-add-control {
        min-height: 105px;
        resize: vertical;
    }

    .module-add-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124,58,237,.08);
    }

    .module-add-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .module-add-preview {
        padding: 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fafafa;
    }

    .module-add-preview-icon {
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

    .module-add-preview-name {
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 800;
    }

    .module-add-preview-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .module-add-toggle {
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

    .module-add-toggle + .module-add-toggle {
        margin-top: 8px;
    }

    .module-add-toggle strong {
        color: #111827;
    }

    .module-add-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .module-add-submit {
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

    .module-add-submit:disabled {
        opacity: .65;
    }

    @media (max-width: 850px) {
        .module-add-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 650px) {
        .module-add-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .module-add-button {
            width: 100%;
        }
    }
</style>

<div class="module-add-page">

    <div class="module-add-header">
        <div>
            <h2 class="module-add-title">
                Add Module
            </h2>

            <div class="module-add-description">
                Create a new FieldPlx module for plans and tenant access control.
            </div>
        </div>

        <a
            href="modules.php"
            class="module-add-button"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Modules
        </a>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="module-add-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= moduleAddEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="module-add.php"
        id="moduleAddForm"
    >
        <?php csrfField(); ?>

        <div class="module-add-layout">

            <div class="module-add-column">

                <section class="module-add-card">
                    <div class="module-add-card-header">
                        <span class="module-add-card-icon">
                            <i class="bi bi-grid-3x3-gap"></i>
                        </span>

                        <div>
                            <h3 class="module-add-card-title">
                                Module Information
                            </h3>

                            <div class="module-add-card-subtitle">
                                Name, unique code, and menu details
                            </div>
                        </div>
                    </div>

                    <div class="module-add-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="module-add-label">
                                    Module Name
                                    <span class="module-add-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="module_name"
                                    id="moduleName"
                                    class="form-control module-add-control"
                                    value="<?= moduleAddEscape(
                                        $moduleName
                                    ); ?>"
                                    maxlength="150"
                                    placeholder="Example: Inventory"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="module-add-label">
                                    Module Code
                                    <span class="module-add-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="module_code"
                                    id="moduleCode"
                                    class="form-control module-add-control"
                                    value="<?= moduleAddEscape(
                                        $moduleCode
                                    ); ?>"
                                    maxlength="100"
                                    placeholder="inventory"
                                    required
                                >

                                <div class="module-add-help">
                                    Lowercase letters, numbers, and underscores only.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="module-add-label">
                                    Icon Class
                                </label>

                                <input
                                    type="text"
                                    name="icon_class"
                                    id="iconClass"
                                    class="form-control module-add-control"
                                    value="<?= moduleAddEscape(
                                        $iconClass
                                    ); ?>"
                                    maxlength="100"
                                    placeholder="bi bi-box-seam"
                                >

                                <div class="module-add-help">
                                    Bootstrap Icons class, such as bi bi-briefcase.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="module-add-label">
                                    Menu URL
                                </label>

                                <input
                                    type="text"
                                    name="menu_url"
                                    class="form-control module-add-control"
                                    value="<?= moduleAddEscape(
                                        $menuUrl
                                    ); ?>"
                                    maxlength="255"
                                    placeholder="inventory.php"
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="module-add-label">
                                    Menu Order
                                </label>

                                <input
                                    type="number"
                                    name="menu_order"
                                    class="form-control module-add-control"
                                    value="<?= moduleAddEscape(
                                        $menuOrder
                                    ); ?>"
                                    min="0"
                                    max="100000"
                                >
                            </div>

                            <div class="col-md-8">
                                <label class="module-add-label">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    class="form-control module-add-control"
                                    maxlength="500"
                                    placeholder="Describe what this module provides."
                                ><?= moduleAddEscape(
                                    $description
                                ); ?></textarea>
                            </div>

                        </div>
                    </div>
                </section>

            </div>

            <aside class="module-add-column">

                <section class="module-add-card">
                    <div class="module-add-card-header">
                        <span class="module-add-card-icon">
                            <i class="bi bi-eye"></i>
                        </span>

                        <div>
                            <h3 class="module-add-card-title">
                                Preview
                            </h3>

                            <div class="module-add-card-subtitle">
                                Sidebar module appearance
                            </div>
                        </div>
                    </div>

                    <div class="module-add-card-body">
                        <div class="module-add-preview">
                            <span
                                class="module-add-preview-icon"
                                id="previewIcon"
                            >
                                <i class="<?= moduleAddEscape(
                                    $iconClass !== ''
                                        ? $iconClass
                                        : 'bi bi-grid'
                                ); ?>"></i>
                            </span>

                            <span>
                                <span
                                    class="module-add-preview-name"
                                    id="previewName"
                                >
                                    <?= moduleAddEscape(
                                        $moduleName !== ''
                                            ? $moduleName
                                            : 'New Module'
                                    ); ?>
                                </span>

                                <span
                                    class="module-add-preview-code"
                                    id="previewCode"
                                >
                                    <?= moduleAddEscape(
                                        $moduleCode !== ''
                                            ? $moduleCode
                                            : 'module_code'
                                    ); ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </section>

                <section class="module-add-card">
                    <div class="module-add-card-header">
                        <span class="module-add-card-icon">
                            <i class="bi bi-toggles"></i>
                        </span>

                        <div>
                            <h3 class="module-add-card-title">
                                Module Controls
                            </h3>

                            <div class="module-add-card-subtitle">
                                Global module behaviour
                            </div>
                        </div>
                    </div>

                    <div class="module-add-card-body">
                        <label class="module-add-toggle">
                            <input
                                type="checkbox"
                                name="is_core"
                                value="1"
                                <?= $isCore === 1
                                    ? 'checked'
                                    : ''; ?>
                            >

                            <span>
                                <strong>Core Module</strong><br>
                                Core modules cannot be deleted from the platform.
                            </span>
                        </label>

                        <label class="module-add-toggle">
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

                <div class="module-add-submit-card">
                    <button
                        type="submit"
                        class="module-add-submit"
                        id="moduleAddSubmit"
                    >
                        <i class="bi bi-check2-circle me-1"></i>
                        Create Module
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

    let codeEdited = false;

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
                codeEdited =
                    moduleCode.value.trim() !== '';

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

    if (moduleName) {
        moduleName.addEventListener(
            'input',
            function () {
                if (previewName) {
                    previewName.textContent =
                        moduleName.value.trim() ||
                        'New Module';
                }

                if (
                    moduleCode &&
                    !codeEdited
                ) {
                    moduleCode.value =
                        normaliseCode(
                            moduleName.value
                        );

                    if (previewCode) {
                        previewCode.textContent =
                            moduleCode.value ||
                            'module_code';
                    }
                }
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
            'moduleAddForm'
        );

    const submitButton =
        document.getElementById(
            'moduleAddSubmit'
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
                    '<span class="spinner-border spinner-border-sm me-1"></span> Creating...';
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
