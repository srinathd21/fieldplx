<?php
/**
 * FieldPlx Platform - System Settings
 *
 * File:
 * platform/system-settings.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 *
 * This page manages technical system controls separately
 * from branding and general Platform Settings.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin'
));

$pageTitle = 'System Settings - FieldPlx';
$activePage = 'system-settings';
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

if (!function_exists('systemSettingsEscape')) {
    function systemSettingsEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('systemSettingsPost')) {
    function systemSettingsPost(
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

if (!function_exists('systemSettingsUserId')) {
    function systemSettingsUserId()
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

if (!function_exists('systemSettingsGenerateSecret')) {
    function systemSettingsGenerateSecret()
    {
        try {
            return bin2hex(random_bytes(24));
        } catch (Exception $exception) {
            return hash(
                'sha256',
                uniqid(
                    (string) mt_rand(),
                    true
                )
            );
        }
    }
}

if (!function_exists('systemSettingsNormaliseExtensions')) {
    function systemSettingsNormaliseExtensions($value)
    {
        $parts = preg_split(
            '/[\s,;]+/',
            strtolower((string) $value)
        );

        $extensions = array();

        foreach ($parts as $part) {
            $part = ltrim(
                trim((string) $part),
                '.'
            );

            if (
                $part !== '' &&
                preg_match('/^[a-z0-9]+$/', $part)
            ) {
                $extensions[$part] = true;
            }
        }

        return implode(
            ',',
            array_keys($extensions)
        );
    }
}


if (!function_exists('systemSettingsUnitCategoryLabel')) {
    function systemSettingsUnitCategoryLabel($value)
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

/*
|--------------------------------------------------------------------------
| Create Units of Measurement table
|--------------------------------------------------------------------------
*/

$conn->query("
    CREATE TABLE IF NOT EXISTS `unit_measurements` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `unit_name` VARCHAR(120) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_unit_measurements_name`
            (`unit_name`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");

/*
 * Upgrade an older unit_measurements table safely.
 */
$unitColumnsResult = $conn->query("
    SHOW COLUMNS FROM `unit_measurements`
");

$unitColumns = array();

while ($unitColumn = $unitColumnsResult->fetch_assoc()) {
    $unitColumns[
        (string) $unitColumn['Field']
    ] = true;
}

$unitColumnsResult->free();

if (!isset($unitColumns['unit_name'])) {
    $conn->query("
        ALTER TABLE `unit_measurements`
        ADD COLUMN `unit_name`
            VARCHAR(120) NOT NULL
        AFTER `id`
    ");
}

if (!isset($unitColumns['is_active'])) {
    $conn->query("
        ALTER TABLE `unit_measurements`
        ADD COLUMN `is_active`
            TINYINT(1) NOT NULL DEFAULT 1
        AFTER `unit_name`
    ");
}

if (!isset($unitColumns['updated_by'])) {
    $conn->query("
        ALTER TABLE `unit_measurements`
        ADD COLUMN `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL
        AFTER `is_active`
    ");
}

if (!isset($unitColumns['created_at'])) {
    $conn->query("
        ALTER TABLE `unit_measurements`
        ADD COLUMN `created_at`
            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        AFTER `updated_by`
    ");
}

if (!isset($unitColumns['updated_at'])) {
    $conn->query("
        ALTER TABLE `unit_measurements`
        ADD COLUMN `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP
        AFTER `created_at`
    ");
}

/*
 * Default UOM list.
 */
$defaultUnits = array(
    'Each',
    'Piece',
    'Pair',
    'Dozen',
    'Box',
    'Pack',
    'Kilogram',
    'Gram',
    'Tonne',
    'Meter',
    'Centimeter',
    'Millimeter',
    'Kilometer',
    'Foot',
    'Inch',
    'Square Meter',
    'Square Foot',
    'Liter',
    'Milliliter',
    'Hour',
    'Minute',
    'Day',
    'Visit',
    'Job',
    'Service'
);

$unitSeedStmt = $conn->prepare("
    INSERT INTO `unit_measurements` (
        `unit_name`,
        `is_active`,
        `created_at`
    )
    SELECT
        ?,
        1,
        NOW()
    WHERE NOT EXISTS (
        SELECT 1
        FROM `unit_measurements`
        WHERE LOWER(`unit_name`) = LOWER(?)
    )
");

foreach ($defaultUnits as $defaultUnit) {
    $unitSeedStmt->bind_param(
        'ss',
        $defaultUnit,
        $defaultUnit
    );

    $unitSeedStmt->execute();
}

$unitSeedStmt->close();

$unitErrorMessage = '';
$unitSuccessMessage = '';

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST' &&
    systemSettingsPost('form_type') === 'unit_measurement'
) {
    verifyCsrfToken();

    $unitAction =
        systemSettingsPost('unit_action');

    $unitId =
        (int) systemSettingsPost(
            'unit_id',
            '0'
        );

    try {
        if ($unitAction === 'save') {
            $unitName =
                systemSettingsPost(
                    'unit_name'
                );

            if ($unitName === '') {
                throw new RuntimeException(
                    'Enter the unit of measurement.'
                );
            }

            if (strlen($unitName) > 120) {
                throw new RuntimeException(
                    'Unit of measurement cannot exceed 120 characters.'
                );
            }

            $duplicateStmt =
                $conn->prepare("
                    SELECT `id`
                    FROM `unit_measurements`
                    WHERE LOWER(`unit_name`) = LOWER(?)
                      AND `id` <> ?
                    LIMIT 1
                ");

            $duplicateStmt->bind_param(
                'si',
                $unitName,
                $unitId
            );

            $duplicateStmt->execute();
            $duplicateResult =
                $duplicateStmt->get_result();

            $duplicateUnit =
                $duplicateResult->fetch_assoc();

            $duplicateStmt->close();

            if ($duplicateUnit) {
                throw new RuntimeException(
                    'This unit of measurement already exists.'
                );
            }

            $updatedBy =
                systemSettingsUserId();

            if ($unitId > 0) {
                $unitStmt =
                    $conn->prepare("
                        UPDATE `unit_measurements`
                        SET
                            `unit_name` = ?,
                            `updated_by` = ?,
                            `updated_at` = NOW()
                        WHERE `id` = ?
                        LIMIT 1
                    ");

                $unitStmt->bind_param(
                    'sii',
                    $unitName,
                    $updatedBy,
                    $unitId
                );
            } else {
                $unitStmt =
                    $conn->prepare("
                        INSERT INTO `unit_measurements` (
                            `unit_name`,
                            `is_active`,
                            `updated_by`,
                            `created_at`,
                            `updated_at`
                        ) VALUES (
                            ?,
                            1,
                            ?,
                            NOW(),
                            NOW()
                        )
                    ");

                $unitStmt->bind_param(
                    'si',
                    $unitName,
                    $updatedBy
                );
            }

            $unitStmt->execute();
            $unitStmt->close();

            regenerateCsrfToken();

            $_SESSION[
                'system_unit_success_message'
            ] =
                $unitId > 0
                    ? 'Unit of measurement updated successfully.'
                    : 'Unit of measurement added successfully.';

            header(
                'Location: system-settings.php#unit-measurements',
                true,
                303
            );

            exit;
        }

        if (
            in_array(
                $unitAction,
                array(
                    'activate',
                    'deactivate'
                ),
                true
            ) &&
            $unitId > 0
        ) {
            $newStatus =
                $unitAction === 'activate'
                    ? 1
                    : 0;

            $updatedBy =
                systemSettingsUserId();

            $unitStmt =
                $conn->prepare("
                    UPDATE `unit_measurements`
                    SET
                        `is_active` = ?,
                        `updated_by` = ?,
                        `updated_at` = NOW()
                    WHERE `id` = ?
                    LIMIT 1
                ");

            $unitStmt->bind_param(
                'iii',
                $newStatus,
                $updatedBy,
                $unitId
            );

            $unitStmt->execute();
            $unitStmt->close();

            regenerateCsrfToken();

            $_SESSION[
                'system_unit_success_message'
            ] =
                $newStatus === 1
                    ? 'Unit activated successfully.'
                    : 'Unit deactivated successfully.';

            header(
                'Location: system-settings.php#unit-measurements',
                true,
                303
            );

            exit;
        }
    } catch (Exception $exception) {
        $unitErrorMessage =
            $exception->getMessage();
    }
}

if (
    !empty(
        $_SESSION[
            'system_unit_success_message'
        ]
    )
) {
    $unitSuccessMessage =
        (string) $_SESSION[
            'system_unit_success_message'
        ];

    unset(
        $_SESSION[
            'system_unit_success_message'
        ]
    );
}

$unitEditId =
    isset($_GET['edit_unit'])
        ? max(
            0,
            (int) $_GET['edit_unit']
        )
        : 0;

$unitEditRecord = null;

if ($unitEditId > 0) {
    $unitEditStmt =
        $conn->prepare("
            SELECT
                `id`,
                `unit_name`,
                `is_active`
            FROM `unit_measurements`
            WHERE `id` = ?
            LIMIT 1
        ");

    $unitEditStmt->bind_param(
        'i',
        $unitEditId
    );

    $unitEditStmt->execute();

    $unitEditRecord =
        $unitEditStmt
            ->get_result()
            ->fetch_assoc();

    $unitEditStmt->close();
}

$unitMeasurements = array();

$unitListResult = $conn->query("
    SELECT
        `id`,
        `unit_name`,
        `is_active`,
        `created_at`,
        `updated_at`
    FROM `unit_measurements`
    ORDER BY
        `is_active` DESC,
        `unit_name` ASC
");

while (
    $unitMeasurement =
        $unitListResult->fetch_assoc()
) {
    $unitMeasurements[] =
        $unitMeasurement;
}

$unitListResult->free();

/*
|--------------------------------------------------------------------------
| Create Tax Rates master table
|--------------------------------------------------------------------------
*/

$conn->query("\n    CREATE TABLE IF NOT EXISTS `system_tax_rates` (\n        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,\n        `tax_name` VARCHAR(120) NOT NULL,\n        `tax_rate` DECIMAL(8,3) NOT NULL DEFAULT 0.000,\n        `is_active` TINYINT(1) NOT NULL DEFAULT 1,\n        `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,\n        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n        `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n        PRIMARY KEY (`id`),\n        UNIQUE KEY `uq_system_tax_rates_name_rate` (`tax_name`, `tax_rate`)\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n");

$defaultTaxRates = array(
    array('No Tax', 0.000),
    array('GST 5%', 5.000),
    array('GST 12%', 12.000),
    array('GST 18%', 18.000),
    array('GST 28%', 28.000)
);

$taxSeedStmt = $conn->prepare("\n    INSERT INTO `system_tax_rates` (`tax_name`,`tax_rate`,`is_active`,`created_at`)\n    SELECT ?, ?, 1, NOW()\n    WHERE NOT EXISTS (\n        SELECT 1 FROM `system_tax_rates`\n        WHERE LOWER(`tax_name`) = LOWER(?) AND `tax_rate` = ?\n    )\n");

foreach ($defaultTaxRates as $defaultTaxRate) {
    $taxNameSeed = $defaultTaxRate[0];
    $taxRateSeed = (float) $defaultTaxRate[1];
    $taxSeedStmt->bind_param('sdsd', $taxNameSeed, $taxRateSeed, $taxNameSeed, $taxRateSeed);
    $taxSeedStmt->execute();
}
$taxSeedStmt->close();

$taxErrorMessage = '';
$taxSuccessMessage = '';

if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST' && systemSettingsPost('form_type') === 'tax_rate') {
    verifyCsrfToken();
    $taxAction = systemSettingsPost('tax_action');
    $taxId = (int) systemSettingsPost('tax_id', '0');

    try {
        if ($taxAction === 'save') {
            $taxName = systemSettingsPost('tax_name');
            $taxRateRaw = systemSettingsPost('tax_rate');

            if ($taxName === '') {
                throw new RuntimeException('Enter the tax name.');
            }
            if (strlen($taxName) > 120) {
                throw new RuntimeException('Tax name cannot exceed 120 characters.');
            }
            if ($taxRateRaw === '' || !is_numeric($taxRateRaw)) {
                throw new RuntimeException('Enter a valid tax percentage.');
            }

            $taxRate = round((float) $taxRateRaw, 3);
            if ($taxRate < 0 || $taxRate > 100) {
                throw new RuntimeException('Tax percentage must be between 0 and 100.');
            }

            $duplicateStmt = $conn->prepare("\n                SELECT `id` FROM `system_tax_rates`\n                WHERE LOWER(`tax_name`) = LOWER(?) AND `tax_rate` = ? AND `id` <> ?\n                LIMIT 1\n            ");
            $duplicateStmt->bind_param('sdi', $taxName, $taxRate, $taxId);
            $duplicateStmt->execute();
            $duplicateTax = $duplicateStmt->get_result()->fetch_assoc();
            $duplicateStmt->close();

            if ($duplicateTax) {
                throw new RuntimeException('This tax rate already exists.');
            }

            $updatedBy = systemSettingsUserId();
            if ($taxId > 0) {
                $taxStmt = $conn->prepare("\n                    UPDATE `system_tax_rates`\n                    SET `tax_name` = ?, `tax_rate` = ?, `updated_by` = ?, `updated_at` = NOW()\n                    WHERE `id` = ? LIMIT 1\n                ");
                $taxStmt->bind_param('sdii', $taxName, $taxRate, $updatedBy, $taxId);
            } else {
                $taxStmt = $conn->prepare("\n                    INSERT INTO `system_tax_rates` (`tax_name`,`tax_rate`,`is_active`,`updated_by`,`created_at`,`updated_at`)\n                    VALUES (?, ?, 1, ?, NOW(), NOW())\n                ");
                $taxStmt->bind_param('sdi', $taxName, $taxRate, $updatedBy);
            }
            $taxStmt->execute();
            $taxStmt->close();

            regenerateCsrfToken();
            $_SESSION['system_tax_success_message'] = $taxId > 0 ? 'Tax rate updated successfully.' : 'Tax rate added successfully.';
            header('Location: system-settings.php#tax-rates', true, 303);
            exit;
        }

        if (in_array($taxAction, array('activate','deactivate'), true) && $taxId > 0) {
            $newStatus = $taxAction === 'activate' ? 1 : 0;
            $updatedBy = systemSettingsUserId();
            $taxStmt = $conn->prepare("\n                UPDATE `system_tax_rates`\n                SET `is_active` = ?, `updated_by` = ?, `updated_at` = NOW()\n                WHERE `id` = ? LIMIT 1\n            ");
            $taxStmt->bind_param('iii', $newStatus, $updatedBy, $taxId);
            $taxStmt->execute();
            $taxStmt->close();

            regenerateCsrfToken();
            $_SESSION['system_tax_success_message'] = $newStatus === 1 ? 'Tax rate activated successfully.' : 'Tax rate deactivated successfully.';
            header('Location: system-settings.php#tax-rates', true, 303);
            exit;
        }
    } catch (Exception $exception) {
        $taxErrorMessage = $exception->getMessage();
    }
}

if (!empty($_SESSION['system_tax_success_message'])) {
    $taxSuccessMessage = (string) $_SESSION['system_tax_success_message'];
    unset($_SESSION['system_tax_success_message']);
}

$taxEditId = isset($_GET['edit_tax']) ? max(0, (int) $_GET['edit_tax']) : 0;
$taxEditRecord = null;
if ($taxEditId > 0) {
    $taxEditStmt = $conn->prepare("SELECT `id`,`tax_name`,`tax_rate`,`is_active` FROM `system_tax_rates` WHERE `id` = ? LIMIT 1");
    $taxEditStmt->bind_param('i', $taxEditId);
    $taxEditStmt->execute();
    $taxEditRecord = $taxEditStmt->get_result()->fetch_assoc();
    $taxEditStmt->close();
}

$systemTaxRates = array();
$taxListResult = $conn->query("SELECT `id`,`tax_name`,`tax_rate`,`is_active`,`created_at`,`updated_at` FROM `system_tax_rates` ORDER BY `is_active` DESC, `tax_rate` ASC, `tax_name` ASC");
while ($systemTaxRate = $taxListResult->fetch_assoc()) {
    $systemTaxRates[] = $systemTaxRate;
}
$taxListResult->free();

/*
|--------------------------------------------------------------------------
| Create system settings table
|--------------------------------------------------------------------------
*/

$conn->query("
    CREATE TABLE IF NOT EXISTS `system_settings` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

        `app_environment`
            ENUM('production','staging','development')
            NOT NULL DEFAULT 'production',

        `app_url`
            VARCHAR(255) DEFAULT NULL,

        `debug_mode`
            TINYINT(1) NOT NULL DEFAULT 0,

        `error_logging`
            TINYINT(1) NOT NULL DEFAULT 1,

        `log_level`
            ENUM('error','warning','info','debug')
            NOT NULL DEFAULT 'error',

        `log_retention_days`
            INT(10) UNSIGNED NOT NULL DEFAULT 30,

        `upload_max_mb`
            INT(10) UNSIGNED NOT NULL DEFAULT 20,

        `allowed_upload_extensions`
            VARCHAR(1000) NOT NULL
            DEFAULT 'jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv',

        `backup_enabled`
            TINYINT(1) NOT NULL DEFAULT 0,

        `backup_time`
            TIME NOT NULL DEFAULT '02:00:00',

        `backup_retention_days`
            INT(10) UNSIGNED NOT NULL DEFAULT 14,

        `cron_enabled`
            TINYINT(1) NOT NULL DEFAULT 1,

        `cron_secret`
            VARCHAR(255) DEFAULT NULL,

        `queue_batch_size`
            INT(10) UNSIGNED NOT NULL DEFAULT 50,

        `api_rate_limit_per_minute`
            INT(10) UNSIGNED NOT NULL DEFAULT 120,

        `cache_enabled`
            TINYINT(1) NOT NULL DEFAULT 1,

        `cache_ttl_seconds`
            INT(10) UNSIGNED NOT NULL DEFAULT 300,

        `allow_file_execution`
            TINYINT(1) NOT NULL DEFAULT 0,

        `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL,

        `created_at`
            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

        `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");

/*
|--------------------------------------------------------------------------
| Upgrade existing installations safely
|--------------------------------------------------------------------------
*/

$requiredColumns = array(
    'app_environment' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `app_environment`
            ENUM('production','staging','development')
            NOT NULL DEFAULT 'production'
        AFTER `id`
    ",
    'app_url' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `app_url`
            VARCHAR(255) DEFAULT NULL
        AFTER `app_environment`
    ",
    'debug_mode' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `debug_mode`
            TINYINT(1) NOT NULL DEFAULT 0
        AFTER `app_url`
    ",
    'error_logging' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `error_logging`
            TINYINT(1) NOT NULL DEFAULT 1
        AFTER `debug_mode`
    ",
    'log_level' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `log_level`
            ENUM('error','warning','info','debug')
            NOT NULL DEFAULT 'error'
        AFTER `error_logging`
    ",
    'log_retention_days' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `log_retention_days`
            INT(10) UNSIGNED NOT NULL DEFAULT 30
        AFTER `log_level`
    ",
    'upload_max_mb' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `upload_max_mb`
            INT(10) UNSIGNED NOT NULL DEFAULT 20
        AFTER `log_retention_days`
    ",
    'allowed_upload_extensions' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `allowed_upload_extensions`
            VARCHAR(1000) NOT NULL
            DEFAULT 'jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv'
        AFTER `upload_max_mb`
    ",
    'backup_enabled' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `backup_enabled`
            TINYINT(1) NOT NULL DEFAULT 0
        AFTER `allowed_upload_extensions`
    ",
    'backup_time' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `backup_time`
            TIME NOT NULL DEFAULT '02:00:00'
        AFTER `backup_enabled`
    ",
    'backup_retention_days' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `backup_retention_days`
            INT(10) UNSIGNED NOT NULL DEFAULT 14
        AFTER `backup_time`
    ",
    'cron_enabled' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `cron_enabled`
            TINYINT(1) NOT NULL DEFAULT 1
        AFTER `backup_retention_days`
    ",
    'cron_secret' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `cron_secret`
            VARCHAR(255) DEFAULT NULL
        AFTER `cron_enabled`
    ",
    'queue_batch_size' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `queue_batch_size`
            INT(10) UNSIGNED NOT NULL DEFAULT 50
        AFTER `cron_secret`
    ",
    'api_rate_limit_per_minute' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `api_rate_limit_per_minute`
            INT(10) UNSIGNED NOT NULL DEFAULT 120
        AFTER `queue_batch_size`
    ",
    'cache_enabled' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `cache_enabled`
            TINYINT(1) NOT NULL DEFAULT 1
        AFTER `api_rate_limit_per_minute`
    ",
    'cache_ttl_seconds' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `cache_ttl_seconds`
            INT(10) UNSIGNED NOT NULL DEFAULT 300
        AFTER `cache_enabled`
    ",
    'allow_file_execution' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `allow_file_execution`
            TINYINT(1) NOT NULL DEFAULT 0
        AFTER `cache_ttl_seconds`
    ",
    'updated_by' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL
        AFTER `allow_file_execution`
    ",
    'created_at' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `created_at`
            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        AFTER `updated_by`
    ",
    'updated_at' => "
        ALTER TABLE `system_settings`
        ADD COLUMN `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP
        AFTER `created_at`
    "
);

$columnsResult = $conn->query("
    SHOW COLUMNS FROM `system_settings`
");

$existingColumns = array();

while ($column = $columnsResult->fetch_assoc()) {
    $existingColumns[
        (string) $column['Field']
    ] = true;
}

$columnsResult->free();

foreach (
    $requiredColumns as
    $columnName => $alterSql
) {
    if (!isset($existingColumns[$columnName])) {
        $conn->query($alterSql);
    }
}

/*
|--------------------------------------------------------------------------
| Seed row
|--------------------------------------------------------------------------
*/

$defaultCronSecret =
    systemSettingsGenerateSecret();

$seedStmt = $conn->prepare("
    INSERT INTO `system_settings` (
        `id`,
        `app_environment`,
        `app_url`,
        `debug_mode`,
        `error_logging`,
        `log_level`,
        `log_retention_days`,
        `upload_max_mb`,
        `allowed_upload_extensions`,
        `backup_enabled`,
        `backup_time`,
        `backup_retention_days`,
        `cron_enabled`,
        `cron_secret`,
        `queue_batch_size`,
        `api_rate_limit_per_minute`,
        `cache_enabled`,
        `cache_ttl_seconds`,
        `allow_file_execution`,
        `created_at`
    )
    SELECT
        1,
        'production',
        NULL,
        0,
        1,
        'error',
        30,
        20,
        'jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv',
        0,
        '02:00:00',
        14,
        1,
        ?,
        50,
        120,
        1,
        300,
        0,
        NOW()
    WHERE NOT EXISTS (
        SELECT 1
        FROM `system_settings`
        WHERE `id` = 1
    )
");

$seedStmt->bind_param(
    's',
    $defaultCronSecret
);

$seedStmt->execute();
$seedStmt->close();

/*
|--------------------------------------------------------------------------
| Load current settings
|--------------------------------------------------------------------------
*/

$result = $conn->query("
    SELECT *
    FROM `system_settings`
    WHERE `id` = 1
    LIMIT 1
");

$settings = $result->fetch_assoc();
$result->free();

if (!$settings) {
    http_response_code(500);
    exit('Unable to initialise system settings.');
}

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';

$appEnvironment =
    isset($_POST['app_environment'])
        ? systemSettingsPost(
            'app_environment'
        )
        : (string) $settings['app_environment'];

$appUrl =
    isset($_POST['app_url'])
        ? systemSettingsPost('app_url')
        : (string) $settings['app_url'];

$logLevel =
    isset($_POST['log_level'])
        ? systemSettingsPost('log_level')
        : (string) $settings['log_level'];

$logRetentionDays =
    isset($_POST['log_retention_days'])
        ? max(
            1,
            (int) $_POST['log_retention_days']
        )
        : (int) $settings['log_retention_days'];

$uploadMaxMb =
    isset($_POST['upload_max_mb'])
        ? max(
            1,
            (int) $_POST['upload_max_mb']
        )
        : (int) $settings['upload_max_mb'];

$allowedUploadExtensions =
    isset($_POST['allowed_upload_extensions'])
        ? systemSettingsNormaliseExtensions(
            systemSettingsPost(
                'allowed_upload_extensions'
            )
        )
        : (string) $settings[
            'allowed_upload_extensions'
        ];

$backupTime =
    isset($_POST['backup_time'])
        ? systemSettingsPost('backup_time')
        : substr(
            (string) $settings['backup_time'],
            0,
            5
        );

$backupRetentionDays =
    isset($_POST['backup_retention_days'])
        ? max(
            1,
            (int) $_POST[
                'backup_retention_days'
            ]
        )
        : (int) $settings[
            'backup_retention_days'
        ];

$cronSecret =
    (string) $settings['cron_secret'];

$queueBatchSize =
    (int) $settings['queue_batch_size'];

$apiRateLimit =
    (int) $settings[
        'api_rate_limit_per_minute'
    ];

$cacheTtlSeconds =
    isset($_POST['cache_ttl_seconds'])
        ? max(
            1,
            (int) $_POST['cache_ttl_seconds']
        )
        : (int) $settings[
            'cache_ttl_seconds'
        ];

$isPost =
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper(
        $_SERVER['REQUEST_METHOD']
    ) === 'POST';

$debugMode = $isPost
    ? (!empty($_POST['debug_mode']) ? 1 : 0)
    : (int) $settings['debug_mode'];

$errorLogging = $isPost
    ? (!empty($_POST['error_logging']) ? 1 : 0)
    : (int) $settings['error_logging'];

$backupEnabled = $isPost
    ? (!empty($_POST['backup_enabled']) ? 1 : 0)
    : (int) $settings['backup_enabled'];

$cronEnabled =
    (int) $settings['cron_enabled'];

$cacheEnabled = $isPost
    ? (!empty($_POST['cache_enabled']) ? 1 : 0)
    : (int) $settings['cache_enabled'];

$allowFileExecution = $isPost
    ? (
        !empty($_POST['allow_file_execution'])
            ? 1
            : 0
    )
    : (int) $settings['allow_file_execution'];

$allowedEnvironments = array(
    'production',
    'staging',
    'development'
);

$allowedLogLevels = array(
    'error',
    'warning',
    'info',
    'debug'
);

/*
|--------------------------------------------------------------------------
| Save settings
|--------------------------------------------------------------------------
*/

if (
    $isPost &&
    !in_array(
        systemSettingsPost('form_type'),
        array('unit_measurement', 'tax_rate'),
        true
    )
) {
    verifyCsrfToken();

    if (
        !in_array(
            $appEnvironment,
            $allowedEnvironments,
            true
        )
    ) {
        $errorMessage =
            'Select a valid application environment.';
    } elseif (
        $appUrl !== '' &&
        !filter_var(
            $appUrl,
            FILTER_VALIDATE_URL
        )
    ) {
        $errorMessage =
            'Enter a valid application URL including https://.';
    } elseif (
        !in_array(
            $logLevel,
            $allowedLogLevels,
            true
        )
    ) {
        $errorMessage =
            'Select a valid log level.';
    } elseif (
        $logRetentionDays > 3650
    ) {
        $errorMessage =
            'Log retention cannot exceed 3650 days.';
    } elseif (
        $uploadMaxMb > 2048
    ) {
        $errorMessage =
            'Upload limit cannot exceed 2048 MB.';
    } elseif (
        $allowedUploadExtensions === ''
    ) {
        $errorMessage =
            'Enter at least one permitted upload extension.';
    } elseif (
        !preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            $backupTime
        )
    ) {
        $errorMessage =
            'Select a valid backup time.';
    } elseif (
        $backupRetentionDays > 3650
    ) {
        $errorMessage =
            'Backup retention cannot exceed 3650 days.';
    } elseif (
        $cacheTtlSeconds > 86400
    ) {
        $errorMessage =
            'Cache lifetime cannot exceed 86400 seconds.';
    } elseif (
        $appEnvironment === 'production' &&
        $debugMode === 1
    ) {
        $errorMessage =
            'Debug mode should not be enabled in the production environment.';
    }

    if ($errorMessage === '') {
        try {
            $updatedBy =
                systemSettingsUserId();

            $stmt = $conn->prepare("
                UPDATE `system_settings`
                SET
                    `app_environment` = ?,
                    `app_url` = ?,
                    `debug_mode` = ?,
                    `error_logging` = ?,
                    `log_level` = ?,
                    `log_retention_days` = ?,
                    `upload_max_mb` = ?,
                    `allowed_upload_extensions` = ?,
                    `backup_enabled` = ?,
                    `backup_time` = ?,
                    `backup_retention_days` = ?,
                    `cron_enabled` = ?,
                    `cron_secret` = ?,
                    `queue_batch_size` = ?,
                    `api_rate_limit_per_minute` = ?,
                    `cache_enabled` = ?,
                    `cache_ttl_seconds` = ?,
                    `allow_file_execution` = ?,
                    `updated_by` = ?,
                    `updated_at` = NOW()
                WHERE `id` = 1
                LIMIT 1
            ");

            /*
             * 19 variables / 19 type characters.
             */
            $stmt->bind_param(
                'ssiisiisisisisiiiii',
                $appEnvironment,
                $appUrl,
                $debugMode,
                $errorLogging,
                $logLevel,
                $logRetentionDays,
                $uploadMaxMb,
                $allowedUploadExtensions,
                $backupEnabled,
                $backupTime,
                $backupRetentionDays,
                $cronEnabled,
                $cronSecret,
                $queueBatchSize,
                $apiRateLimit,
                $cacheEnabled,
                $cacheTtlSeconds,
                $allowFileExecution,
                $updatedBy
            );

            $stmt->execute();
            $stmt->close();

            regenerateCsrfToken();

            $_SESSION[
                'platform_success_message'
            ] =
                'System settings updated successfully.';

            header(
                'Location: system-settings.php',
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            error_log(
                'System settings update failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to save system settings: ' .
                $exception->getMessage();
        }
    }
}

if (
    !empty(
        $_SESSION[
            'platform_success_message'
        ]
    )
) {
    $successMessage =
        (string) $_SESSION[
            'platform_success_message'
        ];

    unset(
        $_SESSION[
            'platform_success_message'
        ]
    );
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .system-settings-page {
        max-width: 1160px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .system-settings-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .system-settings-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .system-settings-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .system-settings-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .system-settings-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .system-settings-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .system-settings-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(300px, 350px);
        gap: 15px;
        align-items: start;
    }

    .system-settings-main,
    .system-settings-side {
        display: grid;
        gap: 15px;
    }

    .system-settings-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px
            rgba(31, 41, 55, 0.035);
    }

    .system-settings-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .system-settings-card-icon {
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

    .system-settings-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .system-settings-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .system-settings-card-body {
        padding: 15px;
    }

    .system-settings-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .system-settings-required {
        color: #dc2626;
    }

    .system-settings-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    textarea.system-settings-control {
        min-height: 90px;
        resize: vertical;
    }

    .system-settings-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .system-settings-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .system-settings-toggle {
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

    .system-settings-toggle + .system-settings-toggle {
        margin-top: 8px;
    }

    .system-settings-toggle strong {
        color: #111827;
    }

    .system-settings-danger {
        border-color: #fecaca;
        background: #fef2f2;
    }

    .system-settings-secret-wrap {
        position: relative;
    }

    .system-settings-secret-actions {
        margin-top: 7px;
        display: flex;
        gap: 7px;
    }

    .system-settings-small-button {
        min-height: 32px;
        padding: 6px 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        background: #ffffff;
        color: #4b5563;
        font-size: 8px;
        font-weight: 700;
        cursor: pointer;
    }

    .system-settings-summary {
        display: grid;
        gap: 8px;
    }

    .system-settings-summary-row {
        padding: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border: 1px solid #eef0f3;
        border-radius: 8px;
        background: #fafafa;
        color: #6b7280;
        font-size: 9px;
    }

    .system-settings-summary-row strong {
        color: #111827;
    }

    .system-settings-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .system-settings-submit {
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

    .system-settings-submit:disabled {
        opacity: 0.65;
    }


    .system-units-toolbar {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(310px, 390px);
        gap: 15px;
        align-items: start;
    }

    .system-units-table-wrap {
        overflow-x: auto;
    }

    .system-units-table {
        width: 100%;
        border-collapse: collapse;
    }

    .system-units-table th,
    .system-units-table td {
        padding: 10px 11px;
        border-bottom: 1px solid #eef0f3;
        text-align: left;
        vertical-align: middle;
        white-space: nowrap;
    }

    .system-units-table th {
        background: #fafafa;
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .system-units-table td {
        color: #374151;
        font-size: 9px;
    }

    .system-unit-name {
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .system-unit-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .system-unit-badge {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #f3f4f6;
        color: #4b5563;
        font-size: 7px;
        font-weight: 700;
    }

    .system-unit-badge.active {
        background: #ecfdf5;
        color: #047857;
    }

    .system-unit-badge.inactive {
        background: #fef2f2;
        color: #b91c1c;
    }

    .system-unit-badge.default {
        background: #f3e8ff;
        color: #6d28d9;
    }

    .system-unit-actions {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .system-unit-action {
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
        cursor: pointer;
    }

    .system-unit-action:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .system-unit-form-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
    }

    @media (max-width: 980px) {
        .system-units-toolbar {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 900px) {
        .system-settings-layout {
            grid-template-columns: 1fr;
        }

        .system-settings-side {
            order: -1;
        }
    }
</style>

<div class="system-settings-page">

    <div class="system-settings-header">
        <div>
            <h2 class="system-settings-title">
                System Settings
            </h2>

            <div class="system-settings-description">
                Configure application runtime, logging, uploads, backups, cache, security, and units of measurement.
            </div>
        </div>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="system-settings-alert success">
            <i class="bi bi-check-circle"></i>

            <span>
                <?= systemSettingsEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="system-settings-alert danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= systemSettingsEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="system-settings.php"
        id="systemSettingsForm"
    >
        <?php csrfField(); ?>

        <div class="system-settings-layout">

            <div class="system-settings-main">

                <section class="system-settings-card">
                    <div class="system-settings-card-header">
                        <span class="system-settings-card-icon">
                            <i class="bi bi-hdd-stack"></i>
                        </span>

                        <div>
                            <h3 class="system-settings-card-title">
                                Application Runtime
                            </h3>

                            <div class="system-settings-card-subtitle">
                                Environment, application URL, and runtime behaviour
                            </div>
                        </div>
                    </div>

                    <div class="system-settings-card-body">
                        <div class="row g-3">

                            <div class="col-md-4">
                                <label class="system-settings-label">
                                    Environment
                                </label>

                                <select
                                    name="app_environment"
                                    id="appEnvironment"
                                    class="form-select system-settings-control"
                                >
                                    <?php foreach (
                                        $allowedEnvironments as
                                        $environment
                                    ): ?>
                                        <option
                                            value="<?= systemSettingsEscape(
                                                $environment
                                            ); ?>"
                                            <?= $appEnvironment ===
                                                $environment
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= systemSettingsEscape(
                                                ucfirst(
                                                    $environment
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="system-settings-label">
                                    Application URL
                                </label>

                                <input
                                    type="url"
                                    name="app_url"
                                    class="form-control system-settings-control"
                                    value="<?= systemSettingsEscape(
                                        $appUrl
                                    ); ?>"
                                    placeholder="https://fieldplx.com"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="system-settings-toggle">
                                    <input
                                        type="checkbox"
                                        name="debug_mode"
                                        id="debugMode"
                                        value="1"
                                        <?= $debugMode === 1
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span>
                                        <strong>Debug Mode</strong><br>
                                        Show detailed application errors. Keep disabled in production.
                                    </span>
                                </label>
                            </div>

                            <div class="col-md-6">
                                <label class="system-settings-toggle">
                                    <input
                                        type="checkbox"
                                        name="error_logging"
                                        value="1"
                                        <?= $errorLogging === 1
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span>
                                        <strong>Error Logging</strong><br>
                                        Store application errors for technical review.
                                    </span>
                                </label>
                            </div>

                        </div>
                    </div>
                </section>

                <section class="system-settings-card">
                    <div class="system-settings-card-header">
                        <span class="system-settings-card-icon">
                            <i class="bi bi-journal-text"></i>
                        </span>

                        <div>
                            <h3 class="system-settings-card-title">
                                Logging
                            </h3>

                            <div class="system-settings-card-subtitle">
                                Log severity and automatic retention
                            </div>
                        </div>
                    </div>

                    <div class="system-settings-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="system-settings-label">
                                    Log Level
                                </label>

                                <select
                                    name="log_level"
                                    class="form-select system-settings-control"
                                >
                                    <?php foreach (
                                        $allowedLogLevels as
                                        $level
                                    ): ?>
                                        <option
                                            value="<?= systemSettingsEscape(
                                                $level
                                            ); ?>"
                                            <?= $logLevel === $level
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= systemSettingsEscape(
                                                ucfirst($level)
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="system-settings-label">
                                    Log Retention Days
                                </label>

                                <input
                                    type="number"
                                    name="log_retention_days"
                                    class="form-control system-settings-control"
                                    value="<?= (int) $logRetentionDays; ?>"
                                    min="1"
                                    max="3650"
                                >
                            </div>

                        </div>
                    </div>
                </section>

                <section class="system-settings-card">
                    <div class="system-settings-card-header">
                        <span class="system-settings-card-icon">
                            <i class="bi bi-cloud-arrow-up"></i>
                        </span>

                        <div>
                            <h3 class="system-settings-card-title">
                                Upload Controls
                            </h3>

                            <div class="system-settings-card-subtitle">
                                File-size limits and permitted extensions
                            </div>
                        </div>
                    </div>

                    <div class="system-settings-card-body">
                        <div class="row g-3">

                            <div class="col-md-4">
                                <label class="system-settings-label">
                                    Maximum Upload Size
                                </label>

                                <div class="input-group">
                                    <input
                                        type="number"
                                        name="upload_max_mb"
                                        class="form-control system-settings-control"
                                        value="<?= (int) $uploadMaxMb; ?>"
                                        min="1"
                                        max="2048"
                                    >

                                    <span class="input-group-text">
                                        MB
                                    </span>
                                </div>
                            </div>

                            <div class="col-md-8">
                                <label class="system-settings-label">
                                    Allowed Extensions
                                </label>

                                <input
                                    type="text"
                                    name="allowed_upload_extensions"
                                    class="form-control system-settings-control"
                                    value="<?= systemSettingsEscape(
                                        $allowedUploadExtensions
                                    ); ?>"
                                    maxlength="1000"
                                >

                                <div class="system-settings-help">
                                    Enter extensions separated by commas. Do not include dots.
                                </div>
                            </div>

                        </div>
                    </div>
                </section>

                <section class="system-settings-card">
                    <div class="system-settings-card-header">
                        <span class="system-settings-card-icon">
                            <i class="bi bi-database-check"></i>
                        </span>

                        <div>
                            <h3 class="system-settings-card-title">
                                Backup Configuration
                            </h3>

                            <div class="system-settings-card-subtitle">
                                Schedule and retention for automated backups
                            </div>
                        </div>
                    </div>

                    <div class="system-settings-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label class="system-settings-toggle">
                                    <input
                                        type="checkbox"
                                        name="backup_enabled"
                                        id="backupEnabled"
                                        value="1"
                                        <?= $backupEnabled === 1
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span>
                                        <strong>Enable Automatic Backups</strong><br>
                                        Permit scheduled database and application backup tasks.
                                    </span>
                                </label>
                            </div>

                            <div
                                class="col-md-6 backup-field"
                            >
                                <label class="system-settings-label">
                                    Backup Time
                                </label>

                                <input
                                    type="time"
                                    name="backup_time"
                                    class="form-control system-settings-control"
                                    value="<?= systemSettingsEscape(
                                        $backupTime
                                    ); ?>"
                                >
                            </div>

                            <div
                                class="col-md-6 backup-field"
                            >
                                <label class="system-settings-label">
                                    Retention Days
                                </label>

                                <input
                                    type="number"
                                    name="backup_retention_days"
                                    class="form-control system-settings-control"
                                    value="<?= (int) $backupRetentionDays; ?>"
                                    min="1"
                                    max="3650"
                                >
                            </div>

                        </div>
                    </div>
                </section>

                

            </div>

            <aside class="system-settings-side">

                <section class="system-settings-card">
                    <div class="system-settings-card-header">
                        <span class="system-settings-card-icon">
                            <i class="bi bi-speedometer2"></i>
                        </span>

                        <div>
                            <h3 class="system-settings-card-title">
                                Cache
                            </h3>

                            <div class="system-settings-card-subtitle">
                                Application caching controls
                            </div>
                        </div>
                    </div>

                    <div class="system-settings-card-body">

                        <label class="system-settings-toggle">
                            <input
                                type="checkbox"
                                name="cache_enabled"
                                id="cacheEnabled"
                                value="1"
                                <?= $cacheEnabled === 1
                                    ? 'checked'
                                    : ''; ?>
                            >

                            <span>
                                <strong>Enable Cache</strong><br>
                                Cache reusable configuration and application data.
                            </span>
                        </label>

                        <div
                            id="cacheTtlWrap"
                            style="margin-top:12px;"
                        >
                            <label class="system-settings-label">
                                Cache Lifetime
                            </label>

                            <div class="input-group">
                                <input
                                    type="number"
                                    name="cache_ttl_seconds"
                                    class="form-control system-settings-control"
                                    value="<?= (int) $cacheTtlSeconds; ?>"
                                    min="1"
                                    max="86400"
                                >

                                <span class="input-group-text">
                                    seconds
                                </span>
                            </div>
                        </div>

                    </div>
                </section>

                <section class="system-settings-card">
                    <div class="system-settings-card-header">
                        <span class="system-settings-card-icon">
                            <i class="bi bi-shield-exclamation"></i>
                        </span>

                        <div>
                            <h3 class="system-settings-card-title">
                                Advanced Security
                            </h3>

                            <div class="system-settings-card-subtitle">
                                High-risk technical controls
                            </div>
                        </div>
                    </div>

                    <div class="system-settings-card-body">

                        <label class="system-settings-toggle system-settings-danger">
                            <input
                                type="checkbox"
                                name="allow_file_execution"
                                value="1"
                                <?= $allowFileExecution === 1
                                    ? 'checked'
                                    : ''; ?>
                            >

                            <span>
                                <strong>Allow Uploaded File Execution</strong><br>
                                Keep this disabled. Enabling it can create a serious security risk.
                            </span>
                        </label>

                    </div>
                </section>

                <section class="system-settings-card">
                    <div class="system-settings-card-header">
                        <span class="system-settings-card-icon">
                            <i class="bi bi-info-circle"></i>
                        </span>

                        <div>
                            <h3 class="system-settings-card-title">
                                Current Configuration
                            </h3>

                            <div class="system-settings-card-subtitle">
                                Quick system overview
                            </div>
                        </div>
                    </div>

                    <div class="system-settings-card-body">
                        <div class="system-settings-summary">

                            <div class="system-settings-summary-row">
                                <span>Environment</span>
                                <strong>
                                    <?= systemSettingsEscape(
                                        ucfirst(
                                            $appEnvironment
                                        )
                                    ); ?>
                                </strong>
                            </div>

                            <div class="system-settings-summary-row">
                                <span>Debug Mode</span>
                                <strong>
                                    <?= $debugMode === 1
                                        ? 'Enabled'
                                        : 'Disabled'; ?>
                                </strong>
                            </div>

                            <div class="system-settings-summary-row">
                                <span>Backups</span>
                                <strong>
                                    <?= $backupEnabled === 1
                                        ? 'Enabled'
                                        : 'Disabled'; ?>
                                </strong>
                            </div>

                            

                            <div class="system-settings-summary-row">
                                <span>Cache</span>
                                <strong>
                                    <?= $cacheEnabled === 1
                                        ? 'Enabled'
                                        : 'Disabled'; ?>
                                </strong>
                            </div>

                            <div class="system-settings-summary-row">
                                <span>Last Updated</span>
                                <strong>
                                    <?= !empty(
                                        $settings['updated_at']
                                    )
                                        ? systemSettingsEscape(
                                            date(
                                                'd M Y H:i',
                                                strtotime(
                                                    $settings[
                                                        'updated_at'
                                                    ]
                                                )
                                            )
                                        )
                                        : 'Not updated'; ?>
                                </strong>
                            </div>

                        </div>
                    </div>
                </section>

                <div class="system-settings-submit-card">
                    <button
                        type="submit"
                        class="system-settings-submit"
                        id="systemSettingsSubmit"
                    >
                        <i class="bi bi-check2-circle me-1"></i>
                        Save System Settings
                    </button>
                </div>

            </aside>

        </div>
    </form>

    <section
        class="system-settings-card"
        id="unit-measurements"
    >
        <div class="system-settings-card-header">
            <span class="system-settings-card-icon">
                <i class="bi bi-rulers"></i>
            </span>

            <div>
                <h3 class="system-settings-card-title">
                    Units of Measurement
                </h3>

                <div class="system-settings-card-subtitle">
                    Add and manage the UOM list used throughout the application
                </div>
            </div>
        </div>

        <div class="system-settings-card-body">

            <?php if ($unitSuccessMessage !== ''): ?>
                <div class="system-settings-alert success" style="margin-bottom:12px;">
                    <i class="bi bi-check-circle"></i>
                    <span>
                        <?= systemSettingsEscape(
                            $unitSuccessMessage
                        ); ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($unitErrorMessage !== ''): ?>
                <div class="system-settings-alert danger" style="margin-bottom:12px;">
                    <i class="bi bi-exclamation-circle"></i>
                    <span>
                        <?= systemSettingsEscape(
                            $unitErrorMessage
                        ); ?>
                    </span>
                </div>
            <?php endif; ?>

            <form
                method="post"
                action="system-settings.php#unit-measurements"
                style="margin-bottom:15px;"
            >
                <?php csrfField(); ?>

                <input
                    type="hidden"
                    name="form_type"
                    value="unit_measurement"
                >

                <input
                    type="hidden"
                    name="unit_action"
                    value="save"
                >

                <input
                    type="hidden"
                    name="unit_id"
                    value="<?= $unitEditRecord
                        ? (int) $unitEditRecord['id']
                        : 0; ?>"
                >

                <div class="row g-2 align-items-end">
                    <div class="col-md-9">
                        <label class="system-settings-label">
                            Unit of Measurement
                            <span class="system-settings-required">*</span>
                        </label>

                        <input
                            type="text"
                            name="unit_name"
                            class="form-control system-settings-control"
                            value="<?= systemSettingsEscape(
                                $unitEditRecord
                                    ? $unitEditRecord[
                                        'unit_name'
                                    ]
                                    : ''
                            ); ?>"
                            maxlength="120"
                            placeholder="Example: Square Foot, Kilogram, Hour"
                            required
                        >
                    </div>

                    <div class="col-md-3">
                        <div style="display:flex;gap:7px;">
                            <?php if ($unitEditRecord): ?>
                                <a
                                    href="system-settings.php#unit-measurements"
                                    class="system-settings-small-button"
                                    style="min-height:39px;"
                                >
                                    Cancel
                                </a>
                            <?php endif; ?>

                            <button
                                type="submit"
                                class="system-settings-submit"
                                style="width:100%;padding:0 14px;"
                            >
                                <i class="bi bi-plus-circle me-1"></i>
                                <?= $unitEditRecord
                                    ? 'Update'
                                    : 'Add UOM'; ?>
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <div class="system-units-table-wrap">
                <table class="system-units-table">
                    <thead>
                        <tr>
                            <th>UOM</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($unitMeasurements)): ?>
                            <tr>
                                <td colspan="3">
                                    No units of measurement added.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (
                                $unitMeasurements as
                                $unitMeasurement
                            ): ?>
                                <tr>
                                    <td>
                                        <span class="system-unit-name">
                                            <?= systemSettingsEscape(
                                                $unitMeasurement[
                                                    'unit_name'
                                                ]
                                            ); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="system-unit-badge <?= (int) $unitMeasurement[
                                            'is_active'
                                        ] === 1
                                            ? 'active'
                                            : 'inactive'; ?>">
                                            <?= (int) $unitMeasurement[
                                                'is_active'
                                            ] === 1
                                                ? 'Active'
                                                : 'Inactive'; ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="system-unit-actions">
                                            <a
                                                href="system-settings.php?edit_unit=<?= (int) $unitMeasurement[
                                                    'id'
                                                ]; ?>#unit-measurements"
                                                class="system-unit-action"
                                                title="Edit"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <form
                                                method="post"
                                                action="system-settings.php#unit-measurements"
                                                style="display:inline;"
                                            >
                                                <?php csrfField(); ?>

                                                <input
                                                    type="hidden"
                                                    name="form_type"
                                                    value="unit_measurement"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="unit_action"
                                                    value="<?= (int) $unitMeasurement[
                                                        'is_active'
                                                    ] === 1
                                                        ? 'deactivate'
                                                        : 'activate'; ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="unit_id"
                                                    value="<?= (int) $unitMeasurement[
                                                        'id'
                                                    ]; ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="system-unit-action"
                                                    title="<?= (int) $unitMeasurement[
                                                        'is_active'
                                                    ] === 1
                                                        ? 'Deactivate'
                                                        : 'Activate'; ?>"
                                                >
                                                    <i class="bi <?= (int) $unitMeasurement[
                                                        'is_active'
                                                    ] === 1
                                                        ? 'bi-pause-circle'
                                                        : 'bi-play-circle'; ?>"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </section>

    <section class="system-settings-card" id="tax-rates">
        <div class="system-settings-card-header">
            <span class="system-settings-card-icon"><i class="bi bi-percent"></i></span>
            <div>
                <h3 class="system-settings-card-title">Tax Rates</h3>
                <div class="system-settings-card-subtitle">Add and manage tax percentages used throughout the application</div>
            </div>
        </div>

        <div class="system-settings-card-body">
            <?php if ($taxSuccessMessage !== ''): ?>
                <div class="system-settings-alert success" style="margin-bottom:12px;">
                    <i class="bi bi-check-circle"></i>
                    <span><?= systemSettingsEscape($taxSuccessMessage); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($taxErrorMessage !== ''): ?>
                <div class="system-settings-alert danger" style="margin-bottom:12px;">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?= systemSettingsEscape($taxErrorMessage); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="system-settings.php#tax-rates" style="margin-bottom:15px;">
                <?php csrfField(); ?>
                <input type="hidden" name="form_type" value="tax_rate">
                <input type="hidden" name="tax_action" value="save">
                <input type="hidden" name="tax_id" value="<?= $taxEditRecord ? (int) $taxEditRecord['id'] : 0; ?>">

                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="system-settings-label">Tax Name <span class="system-settings-required">*</span></label>
                        <input type="text" name="tax_name" class="form-control system-settings-control"
                               value="<?= systemSettingsEscape($taxEditRecord ? $taxEditRecord['tax_name'] : ''); ?>"
                               maxlength="120" placeholder="Example: GST 18%" required>
                    </div>
                    <div class="col-md-3">
                        <label class="system-settings-label">Tax Percentage <span class="system-settings-required">*</span></label>
                        <div class="input-group">
                            <input type="number" name="tax_rate" class="form-control system-settings-control"
                                   value="<?= systemSettingsEscape($taxEditRecord ? $taxEditRecord['tax_rate'] : ''); ?>"
                                   min="0" max="100" step="0.001" placeholder="18" required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div style="display:flex;gap:7px;">
                            <?php if ($taxEditRecord): ?>
                                <a href="system-settings.php#tax-rates" class="system-settings-small-button" style="min-height:39px;">Cancel</a>
                            <?php endif; ?>
                            <button type="submit" class="system-settings-submit" style="width:100%;padding:0 14px;">
                                <i class="bi bi-plus-circle me-1"></i><?= $taxEditRecord ? 'Update' : 'Add Tax'; ?>
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <div class="system-units-table-wrap">
                <table class="system-units-table">
                    <thead><tr><th>Tax Name</th><th>Rate</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
                    <tbody>
                    <?php if (empty($systemTaxRates)): ?>
                        <tr><td colspan="4">No tax rates added.</td></tr>
                    <?php else: ?>
                        <?php foreach ($systemTaxRates as $systemTaxRate): ?>
                            <tr>
                                <td><span class="system-unit-name"><?= systemSettingsEscape($systemTaxRate['tax_name']); ?></span></td>
                                <td><strong><?= systemSettingsEscape(rtrim(rtrim(number_format((float) $systemTaxRate['tax_rate'], 3, '.', ''), '0'), '.')); ?>%</strong></td>
                                <td>
                                    <span class="system-unit-badge <?= (int) $systemTaxRate['is_active'] === 1 ? 'active' : 'inactive'; ?>">
                                        <?= (int) $systemTaxRate['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="system-unit-actions">
                                        <a href="system-settings.php?edit_tax=<?= (int) $systemTaxRate['id']; ?>#tax-rates" class="system-unit-action" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <form method="post" action="system-settings.php#tax-rates" style="display:inline;">
                                            <?php csrfField(); ?>
                                            <input type="hidden" name="form_type" value="tax_rate">
                                            <input type="hidden" name="tax_action" value="<?= (int) $systemTaxRate['is_active'] === 1 ? 'deactivate' : 'activate'; ?>">
                                            <input type="hidden" name="tax_id" value="<?= (int) $systemTaxRate['id']; ?>">
                                            <button type="submit" class="system-unit-action" title="<?= (int) $systemTaxRate['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="bi <?= (int) $systemTaxRate['is_active'] === 1 ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    'use strict';

    const environment =
        document.getElementById(
            'appEnvironment'
        );

    const debugMode =
        document.getElementById(
            'debugMode'
        );

    if (environment && debugMode) {
        environment.addEventListener(
            'change',
            function () {
                if (
                    environment.value ===
                    'production' &&
                    debugMode.checked
                ) {
                    debugMode.checked = false;

                    window.alert(
                        'Debug mode was disabled because the environment is Production.'
                    );
                }
            }
        );
    }

    const backupEnabled =
        document.getElementById(
            'backupEnabled'
        );

    const backupFields =
        document.querySelectorAll(
            '.backup-field'
        );

    function updateBackupFields() {
        backupFields.forEach(
            function (field) {
                field.style.display =
                    backupEnabled &&
                    backupEnabled.checked
                        ? ''
                        : 'none';
            }
        );
    }

    if (backupEnabled) {
        backupEnabled.addEventListener(
            'change',
            updateBackupFields
        );
    }

    updateBackupFields();

    const cacheEnabled =
        document.getElementById(
            'cacheEnabled'
        );

    const cacheTtlWrap =
        document.getElementById(
            'cacheTtlWrap'
        );

    function updateCacheField() {
        if (!cacheTtlWrap) {
            return;
        }

        cacheTtlWrap.style.display =
            cacheEnabled &&
            cacheEnabled.checked
                ? ''
                : 'none';
    }

    if (cacheEnabled) {
        cacheEnabled.addEventListener(
            'change',
            updateCacheField
        );
    }

    updateCacheField();

    const form =
        document.getElementById(
            'systemSettingsForm'
        );

    const submitButton =
        document.getElementById(
            'systemSettingsSubmit'
        );

    if (form && submitButton) {
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
