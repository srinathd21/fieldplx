<?php
/**
 * FieldPlx Platform - Platform Settings
 *
 * File:
 * platform/settings.php
 *
 * The page automatically creates the platform_settings table
 * when it does not already exist.
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

$pageTitle = 'Platform Settings - FieldPlx';
$activePage = 'settings';
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

if (!function_exists('platformSettingsEscape')) {
    function platformSettingsEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('platformSettingsPost')) {
    function platformSettingsPost(
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

if (!function_exists('platformSettingsUploadLogo')) {
    function platformSettingsUploadLogo(
        $file,
        &$errorMessage
    ) {
        if (
            !is_array($file) ||
            empty($file['name']) ||
            !isset($file['error'])
        ) {
            return null;
        }

        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = 'Logo upload failed.';
            return false;
        }

        if (
            empty($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            $errorMessage = 'Invalid logo upload.';
            return false;
        }

        if (
            !empty($file['size']) &&
            (int) $file['size'] > 3 * 1024 * 1024
        ) {
            $errorMessage =
                'Logo size must not exceed 3 MB.';
            return false;
        }

        $mimeType = '';

        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $mimeType = mime_content_type(
                $file['tmp_name']
            );
        }

        $allowedTypes = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg'
        );

        if (!isset($allowedTypes[$mimeType])) {
            $errorMessage =
                'Logo must be JPG, PNG, WEBP, or SVG.';
            return false;
        }

        $relativeDirectory =
            'uploads/platform/settings';

        $absoluteDirectory =
            dirname(__DIR__) .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relativeDirectory
            );

        if (
            !is_dir($absoluteDirectory) &&
            !mkdir($absoluteDirectory, 0775, true) &&
            !is_dir($absoluteDirectory)
        ) {
            $errorMessage =
                'Unable to create the settings upload directory.';
            return false;
        }

        $fileName =
            'platform-logo-' .
            date('YmdHis') .
            '-' .
            bin2hex(random_bytes(4)) .
            '.' .
            $allowedTypes[$mimeType];

        $absolutePath =
            $absoluteDirectory .
            DIRECTORY_SEPARATOR .
            $fileName;

        if (
            !move_uploaded_file(
                $file['tmp_name'],
                $absolutePath
            )
        ) {
            $errorMessage =
                'Unable to save the platform logo.';
            return false;
        }

        return $relativeDirectory . '/' . $fileName;
    }
}

if (!function_exists('platformSettingsDeleteLogo')) {
    function platformSettingsDeleteLogo($relativePath)
    {
        $relativePath = trim((string) $relativePath);

        if ($relativePath === '') {
            return;
        }

        $normalised = str_replace(
            '\\',
            '/',
            $relativePath
        );

        if (
            strpos(
                $normalised,
                'uploads/platform/settings/'
            ) !== 0
        ) {
            return;
        }

        $absolutePath =
            dirname(__DIR__) .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $normalised
            );

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Create settings table safely
|--------------------------------------------------------------------------
*/

$conn->query("
    CREATE TABLE IF NOT EXISTS `platform_settings` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `platform_name` VARCHAR(190) NOT NULL DEFAULT 'FieldPlx',
        `platform_tagline` VARCHAR(255) DEFAULT NULL,
        `logo_path` VARCHAR(500) DEFAULT NULL,
        `support_email` VARCHAR(190) DEFAULT NULL,
        `support_phone` VARCHAR(50) DEFAULT NULL,
        `website_url` VARCHAR(255) DEFAULT NULL,
        `default_timezone` VARCHAR(100) NOT NULL DEFAULT 'America/New_York',
        `default_currency` CHAR(3) NOT NULL DEFAULT 'USD',
        `default_date_format` VARCHAR(30) NOT NULL DEFAULT 'm-d-Y',
        `default_trial_days` INT(10) UNSIGNED NOT NULL DEFAULT 14,
        `default_max_users` INT(10) UNSIGNED DEFAULT NULL,
        `default_max_branches` INT(10) UNSIGNED DEFAULT NULL,
        `allow_public_signup` TINYINT(1) NOT NULL DEFAULT 0,
        `require_email_verification` TINYINT(1) NOT NULL DEFAULT 1,
        `allow_support_access` TINYINT(1) NOT NULL DEFAULT 1,
        `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0,
        `maintenance_message` TEXT DEFAULT NULL,
        `session_timeout_minutes` INT(10) UNSIGNED NOT NULL DEFAULT 120,
        `password_min_length` INT(10) UNSIGNED NOT NULL DEFAULT 8,
        `smtp_host` VARCHAR(190) DEFAULT NULL,
        `smtp_port` INT(10) UNSIGNED NOT NULL DEFAULT 465,
        `smtp_encryption` VARCHAR(20) NOT NULL DEFAULT 'ssl',
        `smtp_username` VARCHAR(190) DEFAULT NULL,
        `smtp_password` VARCHAR(500) DEFAULT NULL,
        `smtp_from_name` VARCHAR(190) DEFAULT NULL,
        `smtp_from_email` VARCHAR(190) DEFAULT NULL,
        `smtp_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");


/*
|--------------------------------------------------------------------------
| Add SMTP columns safely for existing installations
|--------------------------------------------------------------------------
*/

$smtpColumns = array(
    'smtp_host' => "
        ALTER TABLE `platform_settings`
        ADD COLUMN `smtp_host` VARCHAR(190) DEFAULT NULL
        AFTER `password_min_length`
    ",
    'smtp_port' => "
        ALTER TABLE `platform_settings`
        ADD COLUMN `smtp_port` INT(10) UNSIGNED NOT NULL DEFAULT 465
        AFTER `smtp_host`
    ",
    'smtp_encryption' => "
        ALTER TABLE `platform_settings`
        ADD COLUMN `smtp_encryption` VARCHAR(20) NOT NULL DEFAULT 'ssl'
        AFTER `smtp_port`
    ",
    'smtp_username' => "
        ALTER TABLE `platform_settings`
        ADD COLUMN `smtp_username` VARCHAR(190) DEFAULT NULL
        AFTER `smtp_encryption`
    ",
    'smtp_password' => "
        ALTER TABLE `platform_settings`
        ADD COLUMN `smtp_password` VARCHAR(500) DEFAULT NULL
        AFTER `smtp_username`
    ",
    'smtp_enabled' => "
        ALTER TABLE `platform_settings`
        ADD COLUMN `smtp_enabled` TINYINT(1) NOT NULL DEFAULT 1
        AFTER `smtp_from_email`
    "
);

$existingColumnsResult = $conn->query("
    SHOW COLUMNS FROM `platform_settings`
");

$existingColumns = array();

while ($existingColumn = $existingColumnsResult->fetch_assoc()) {
    $existingColumns[$existingColumn['Field']] = true;
}

$existingColumnsResult->free();

foreach ($smtpColumns as $smtpColumnName => $smtpAlterSql) {
    if (!isset($existingColumns[$smtpColumnName])) {
        $conn->query($smtpAlterSql);
    }
}

$conn->query("
    INSERT INTO `platform_settings` (
        `id`,
        `platform_name`,
        `platform_tagline`,
        `support_email`,
        `default_timezone`,
        `default_currency`,
        `default_date_format`,
        `default_trial_days`,
        `allow_public_signup`,
        `require_email_verification`,
        `allow_support_access`,
        `maintenance_mode`,
        `session_timeout_minutes`,
        `password_min_length`,
        `smtp_host`,
        `smtp_port`,
        `smtp_encryption`,
        `smtp_username`,
        `smtp_password`,
        `smtp_from_name`,
        `smtp_from_email`,
        `smtp_enabled`
    )
    SELECT
        1,
        'FieldPlx',
        'Field Service Management Platform',
        NULL,
        'America/New_York',
        'USD',
        'm-d-Y',
        14,
        0,
        1,
        1,
        0,
        120,
        8,
        'smtp.hostinger.com',
        465,
        'ssl',
        'alerts@fieldplx.com',
        '/b26IsrN',
        'FieldPlx',
        'alerts@fieldplx.com',
        1
    WHERE NOT EXISTS (
        SELECT 1
        FROM `platform_settings`
        WHERE `id` = 1
    )
");

/*
|--------------------------------------------------------------------------
| Load settings
|--------------------------------------------------------------------------
*/

$result = $conn->query("
    SELECT *
    FROM platform_settings
    WHERE `id` = 1
    LIMIT 1
");

$settings = $result->fetch_assoc();
$result->free();

if (!$settings) {
    http_response_code(500);
    exit('Unable to initialise platform settings.');
}

/*
|--------------------------------------------------------------------------
| Current platform user
|--------------------------------------------------------------------------
*/

$currentPlatformUserId = 0;

if (!empty($_SESSION['platform_user_id'])) {
    $currentPlatformUserId =
        (int) $_SESSION['platform_user_id'];
} elseif (!empty($_SESSION['platform_admin_id'])) {
    $currentPlatformUserId =
        (int) $_SESSION['platform_admin_id'];
}

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';

$platformName = isset($_POST['platform_name'])
    ? platformSettingsPost('platform_name')
    : (string) $settings['platform_name'];

$platformTagline = isset($_POST['platform_tagline'])
    ? platformSettingsPost('platform_tagline')
    : (string) $settings['platform_tagline'];

$supportEmail = isset($_POST['support_email'])
    ? strtolower(platformSettingsPost('support_email'))
    : (string) $settings['support_email'];

$supportPhone = isset($_POST['support_phone'])
    ? platformSettingsPost('support_phone')
    : (string) $settings['support_phone'];

$websiteUrl = isset($_POST['website_url'])
    ? platformSettingsPost('website_url')
    : (string) $settings['website_url'];

$defaultTimezone = isset($_POST['default_timezone'])
    ? platformSettingsPost('default_timezone')
    : (string) $settings['default_timezone'];

$defaultCurrency = isset($_POST['default_currency'])
    ? strtoupper(
        platformSettingsPost('default_currency')
    )
    : strtoupper(
        (string) $settings['default_currency']
    );

$defaultDateFormat = isset($_POST['default_date_format'])
    ? platformSettingsPost('default_date_format')
    : (string) $settings['default_date_format'];

$defaultTrialDays = isset($_POST['default_trial_days'])
    ? max(0, (int) $_POST['default_trial_days'])
    : (int) $settings['default_trial_days'];

$defaultMaxUsers = isset($_POST['default_max_users'])
    ? platformSettingsPost('default_max_users')
    : (
        $settings['default_max_users'] === null
            ? ''
            : (string) $settings['default_max_users']
    );

$defaultMaxBranches = isset($_POST['default_max_branches'])
    ? platformSettingsPost('default_max_branches')
    : (
        $settings['default_max_branches'] === null
            ? ''
            : (string) $settings['default_max_branches']
    );

$sessionTimeoutMinutes =
    isset($_POST['session_timeout_minutes'])
        ? max(
            15,
            (int) $_POST['session_timeout_minutes']
        )
        : (int) $settings['session_timeout_minutes'];

$passwordMinLength =
    isset($_POST['password_min_length'])
        ? max(
            8,
            (int) $_POST['password_min_length']
        )
        : (int) $settings['password_min_length'];

$smtpHost = isset($_POST['smtp_host'])
    ? platformSettingsPost('smtp_host')
    : (string) $settings['smtp_host'];

$smtpPort = isset($_POST['smtp_port'])
    ? max(1, (int) $_POST['smtp_port'])
    : (int) $settings['smtp_port'];

$smtpEncryption = isset($_POST['smtp_encryption'])
    ? platformSettingsPost('smtp_encryption')
    : (string) $settings['smtp_encryption'];

$smtpUsername = isset($_POST['smtp_username'])
    ? platformSettingsPost('smtp_username')
    : (string) $settings['smtp_username'];

$smtpPassword = isset($_POST['smtp_password'])
    ? (string) $_POST['smtp_password']
    : (string) $settings['smtp_password'];

$smtpEnabled =
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? (!empty($_POST['smtp_enabled']) ? 1 : 0)
        : (int) $settings['smtp_enabled'];

$smtpFromName = isset($_POST['smtp_from_name'])
    ? platformSettingsPost('smtp_from_name')
    : (string) $settings['smtp_from_name'];

$smtpFromEmail = isset($_POST['smtp_from_email'])
    ? strtolower(
        platformSettingsPost('smtp_from_email')
    )
    : (string) $settings['smtp_from_email'];

$maintenanceMessage =
    isset($_POST['maintenance_message'])
        ? platformSettingsPost('maintenance_message')
        : (string) $settings['maintenance_message'];

$allowPublicSignup =
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? (!empty($_POST['allow_public_signup']) ? 1 : 0)
        : (int) $settings['allow_public_signup'];

$requireEmailVerification =
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? (
            !empty($_POST['require_email_verification'])
                ? 1
                : 0
        )
        : (int) $settings['require_email_verification'];

$allowSupportAccess =
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? (!empty($_POST['allow_support_access']) ? 1 : 0)
        : (int) $settings['allow_support_access'];

$maintenanceMode =
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? (!empty($_POST['maintenance_mode']) ? 1 : 0)
        : (int) $settings['maintenance_mode'];

$removeLogo = !empty($_POST['remove_logo'])
    ? 1
    : 0;

$allowedCurrencies = array(
    'USD',
    'GBP',
    'EUR',
    'CAD',
    'AUD',
    'INR'
);

$allowedDateFormats = array(
    'm-d-Y',
    'd-m-Y',
    'Y-m-d',
    'm/d/Y',
    'd/m/Y'
);

$smtpEncryptionOptions = array(
    'ssl' => 'SSL',
    'tls' => 'TLS',
    'none' => 'None'
);

$timezoneOptions = array(
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Phoenix',
    'America/Toronto',
    'Europe/London',
    'Europe/Paris',
    'Asia/Kolkata',
    'Asia/Dubai',
    'Asia/Singapore',
    'Australia/Sydney',
    'UTC'
);

/*
|--------------------------------------------------------------------------
| Save settings
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    if ($platformName === '') {
        $errorMessage = 'Enter the platform name.';
    } elseif (strlen($platformName) > 190) {
        $errorMessage =
            'Platform name must not exceed 190 characters.';
    } elseif (strlen($platformTagline) > 255) {
        $errorMessage =
            'Platform tagline must not exceed 255 characters.';
    } elseif (
        $supportEmail !== '' &&
        !filter_var(
            $supportEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errorMessage =
            'Enter a valid support email address.';
    } elseif (
        $smtpFromEmail !== '' &&
        !filter_var(
            $smtpFromEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errorMessage =
            'Enter a valid sender email address.';
    } elseif ($smtpHost === '') {
        $errorMessage =
            'Enter the SMTP host.';
    } elseif (
        $smtpPort < 1 ||
        $smtpPort > 65535
    ) {
        $errorMessage =
            'Enter a valid SMTP port.';
    } elseif (
        !isset(
            $smtpEncryptionOptions[
                $smtpEncryption
            ]
        )
    ) {
        $errorMessage =
            'Select a valid SMTP encryption type.';
    } elseif (
        $smtpUsername !== '' &&
        !filter_var(
            $smtpUsername,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errorMessage =
            'Enter a valid SMTP username.';
    } elseif (
        $websiteUrl !== '' &&
        !filter_var(
            $websiteUrl,
            FILTER_VALIDATE_URL
        )
    ) {
        $errorMessage =
            'Enter a valid website URL including https://.';
    } elseif (
        !in_array(
            $defaultCurrency,
            $allowedCurrencies,
            true
        )
    ) {
        $errorMessage =
            'Select a valid default currency.';
    } elseif (
        !in_array(
            $defaultDateFormat,
            $allowedDateFormats,
            true
        )
    ) {
        $errorMessage =
            'Select a valid date format.';
    } elseif ($defaultTrialDays > 365) {
        $errorMessage =
            'Default trial days must not exceed 365.';
    } elseif ($sessionTimeoutMinutes > 1440) {
        $errorMessage =
            'Session timeout must not exceed 1440 minutes.';
    } elseif ($passwordMinLength > 128) {
        $errorMessage =
            'Password minimum length must not exceed 128.';
    }

    $newLogoPath = null;

    if ($errorMessage === '') {
        $newLogoPath =
            platformSettingsUploadLogo(
                isset($_FILES['platform_logo'])
                    ? $_FILES['platform_logo']
                    : null,
                $errorMessage
            );
    }

    if ($errorMessage === '') {
        try {
            $oldLogoPath =
                (string) $settings['logo_path'];

            $logoPath = $oldLogoPath;

            if ($removeLogo === 1) {
                $logoPath = null;
            }

            if (
                $newLogoPath !== null &&
                $newLogoPath !== false
            ) {
                $logoPath = $newLogoPath;
            }

            $maxUsersValue =
                $defaultMaxUsers === ''
                    ? null
                    : max(
                        1,
                        (int) $defaultMaxUsers
                    );

            $maxBranchesValue =
                $defaultMaxBranches === ''
                    ? null
                    : max(
                        1,
                        (int) $defaultMaxBranches
                    );

            $stmt = $conn->prepare("
                UPDATE platform_settings
                SET
                    `platform_name` = ?,
                    `platform_tagline` = ?,
                    `logo_path` = ?,
                    `support_email` = ?,
                    `support_phone` = ?,
                    `website_url` = ?,
                    `default_timezone` = ?,
                    `default_currency` = ?,
                    `default_date_format` = ?,
                    `default_trial_days` = ?,
                    `default_max_users` = ?,
                    `default_max_branches` = ?,
                    `allow_public_signup` = ?,
                    `require_email_verification` = ?,
                    `allow_support_access` = ?,
                    `maintenance_mode` = ?,
                    `maintenance_message` = ?,
                    `session_timeout_minutes` = ?,
                    `password_min_length` = ?,
                    `smtp_host` = ?,
                    `smtp_port` = ?,
                    `smtp_encryption` = ?,
                    `smtp_username` = ?,
                    `smtp_password` = ?,
                    `smtp_from_name` = ?,
                    `smtp_from_email` = ?,
                    `smtp_enabled` = ?,
                    `updated_by` = ?,
                    `updated_at` = NOW()
                WHERE `id` = 1
                LIMIT 1
            ");

            $stmt->bind_param(
                'sssssssssiiiiiiisiisisssssii',
                $platformName,
                $platformTagline,
                $logoPath,
                $supportEmail,
                $supportPhone,
                $websiteUrl,
                $defaultTimezone,
                $defaultCurrency,
                $defaultDateFormat,
                $defaultTrialDays,
                $maxUsersValue,
                $maxBranchesValue,
                $allowPublicSignup,
                $requireEmailVerification,
                $allowSupportAccess,
                $maintenanceMode,
                $maintenanceMessage,
                $sessionTimeoutMinutes,
                $passwordMinLength,
                $smtpHost,
                $smtpPort,
                $smtpEncryption,
                $smtpUsername,
                $smtpPassword,
                $smtpFromName,
                $smtpFromEmail,
                $smtpEnabled,
                $currentPlatformUserId
            );

            $stmt->execute();
            $stmt->close();

            if (
                (
                    $removeLogo === 1 ||
                    (
                        $newLogoPath !== null &&
                        $newLogoPath !== false
                    )
                ) &&
                $oldLogoPath !== ''
            ) {
                platformSettingsDeleteLogo($oldLogoPath);
            }

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Platform settings updated successfully.';

            header(
                'Location: settings.php',
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            if (
                $newLogoPath !== null &&
                $newLogoPath !== false
            ) {
                platformSettingsDeleteLogo(
                    $newLogoPath
                );
            }

            error_log(
                'Platform settings update failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to save platform settings: ' .
                $exception->getMessage();
        }
    }
}

if (!empty($_SESSION['platform_success_message'])) {
    $successMessage =
        (string) $_SESSION['platform_success_message'];

    unset($_SESSION['platform_success_message']);
}

$logoDisplayPath = '';

if (
    $removeLogo !== 1 &&
    !empty($settings['logo_path'])
) {
    $logoDisplayPath =
        (string) $settings['logo_path'];
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .platform-settings-page {
        max-width: 1120px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .platform-settings-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
    }

    .platform-settings-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .platform-settings-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .platform-settings-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .platform-settings-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .platform-settings-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .platform-settings-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(290px, 340px);
        gap: 15px;
        align-items: start;
    }

    .platform-settings-main,
    .platform-settings-side {
        display: grid;
        gap: 15px;
    }

    .platform-settings-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .platform-settings-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .platform-settings-card-icon {
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

    .platform-settings-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .platform-settings-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .platform-settings-card-body {
        padding: 15px;
    }

    .platform-settings-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .platform-settings-required {
        color: #dc2626;
    }

    .platform-settings-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    textarea.platform-settings-control {
        min-height: 92px;
        resize: vertical;
    }

    .platform-settings-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .platform-settings-password-wrap {
        position: relative;
    }

    .platform-settings-password-toggle {
        position: absolute;
        top: 50%;
        right: 8px;
        width: 30px;
        height: 30px;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 0;
        background: transparent;
        color: #9ca3af;
    }

    .platform-settings-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .platform-settings-toggle {
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

    .platform-settings-toggle + .platform-settings-toggle {
        margin-top: 8px;
    }

    .platform-settings-toggle strong {
        color: #111827;
    }

    .platform-settings-logo {
        width: 140px;
        height: 100px;
        margin: 0 auto 12px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px dashed #c4b5fd;
        border-radius: 16px;
        background: #faf8ff;
        color: #7c3aed;
        font-size: 30px;
    }

    .platform-settings-logo img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }

    .platform-settings-logo-input {
        display: none;
    }

    .platform-settings-logo-button {
        min-height: 37px;
        width: 100%;
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
        cursor: pointer;
    }

    .platform-settings-remove {
        margin-top: 8px;
        padding: 9px 10px;
        display: flex;
        align-items: center;
        gap: 8px;
        border: 1px solid #fee2e2;
        border-radius: 8px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 8px;
        font-weight: 600;
    }

    .platform-settings-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .platform-settings-submit {
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

    .platform-settings-submit:disabled {
        opacity: 0.65;
    }

    @media (max-width: 900px) {
        .platform-settings-layout {
            grid-template-columns: 1fr;
        }

        .platform-settings-side {
            order: -1;
        }
    }
</style>

<div class="platform-settings-page">

    <div class="platform-settings-header">
        <div>
            <h2 class="platform-settings-title">
                Platform Settings
            </h2>

            <div class="platform-settings-description">
                Configure branding, regional defaults, security, registration, and maintenance.
            </div>
        </div>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="platform-settings-alert success">
            <i class="bi bi-check-circle"></i>
            <span>
                <?= platformSettingsEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="platform-settings-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span>
                <?= platformSettingsEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="settings.php"
        enctype="multipart/form-data"
        id="platformSettingsForm"
    >
        <?php csrfField(); ?>

        <div class="platform-settings-layout">

            <div class="platform-settings-main">

                <section class="platform-settings-card">
                    <div class="platform-settings-card-header">
                        <span class="platform-settings-card-icon">
                            <i class="bi bi-buildings"></i>
                        </span>

                        <div>
                            <h3 class="platform-settings-card-title">
                                Platform Identity
                            </h3>

                            <div class="platform-settings-card-subtitle">
                                Branding and public contact details
                            </div>
                        </div>
                    </div>

                    <div class="platform-settings-card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="platform-settings-label">
                                    Platform Name
                                    <span class="platform-settings-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="platform_name"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $platformName
                                    ); ?>"
                                    maxlength="190"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="platform-settings-label">
                                    Platform Tagline
                                </label>

                                <input
                                    type="text"
                                    name="platform_tagline"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $platformTagline
                                    ); ?>"
                                    maxlength="255"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="platform-settings-label">
                                    Support Email
                                </label>

                                <input
                                    type="email"
                                    name="support_email"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $supportEmail
                                    ); ?>"
                                    maxlength="190"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="platform-settings-label">
                                    Support Phone
                                </label>

                                <input
                                    type="text"
                                    name="support_phone"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $supportPhone
                                    ); ?>"
                                    maxlength="50"
                                >
                            </div>

                            <div class="col-12">
                                <label class="platform-settings-label">
                                    Website URL
                                </label>

                                <input
                                    type="url"
                                    name="website_url"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $websiteUrl
                                    ); ?>"
                                    placeholder="https://fieldplx.com"
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="platform-settings-card">
                    <div class="platform-settings-card-header">
                        <span class="platform-settings-card-icon">
                            <i class="bi bi-globe-americas"></i>
                        </span>

                        <div>
                            <h3 class="platform-settings-card-title">
                                Regional Defaults
                            </h3>

                            <div class="platform-settings-card-subtitle">
                                New tenant timezone, currency, and date format
                            </div>
                        </div>
                    </div>

                    <div class="platform-settings-card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="platform-settings-label">
                                    Default Timezone
                                </label>

                                <select
                                    name="default_timezone"
                                    class="form-select platform-settings-control"
                                >
                                    <?php foreach (
                                        $timezoneOptions as $timezone
                                    ): ?>
                                        <option
                                            value="<?= platformSettingsEscape(
                                                $timezone
                                            ); ?>"
                                            <?= $defaultTimezone === $timezone
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= platformSettingsEscape(
                                                $timezone
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="platform-settings-label">
                                    Default Currency
                                </label>

                                <select
                                    name="default_currency"
                                    class="form-select platform-settings-control"
                                >
                                    <?php foreach (
                                        $allowedCurrencies as $currency
                                    ): ?>
                                        <option
                                            value="<?= platformSettingsEscape(
                                                $currency
                                            ); ?>"
                                            <?= $defaultCurrency === $currency
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= platformSettingsEscape(
                                                $currency
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="platform-settings-label">
                                    Date Format
                                </label>

                                <select
                                    name="default_date_format"
                                    class="form-select platform-settings-control"
                                >
                                    <?php foreach (
                                        $allowedDateFormats as $format
                                    ): ?>
                                        <option
                                            value="<?= platformSettingsEscape(
                                                $format
                                            ); ?>"
                                            <?= $defaultDateFormat === $format
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= platformSettingsEscape(
                                                $format
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="platform-settings-card">
                    <div class="platform-settings-card-header">
                        <span class="platform-settings-card-icon">
                            <i class="bi bi-building-add"></i>
                        </span>

                        <div>
                            <h3 class="platform-settings-card-title">
                                New Tenant Defaults
                            </h3>

                            <div class="platform-settings-card-subtitle">
                                Initial trial and usage limits
                            </div>
                        </div>
                    </div>

                    <div class="platform-settings-card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="platform-settings-label">
                                    Trial Days
                                </label>

                                <input
                                    type="number"
                                    name="default_trial_days"
                                    class="form-control platform-settings-control"
                                    value="<?= (int) $defaultTrialDays; ?>"
                                    min="0"
                                    max="365"
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="platform-settings-label">
                                    Default Max Users
                                </label>

                                <input
                                    type="number"
                                    name="default_max_users"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $defaultMaxUsers
                                    ); ?>"
                                    min="1"
                                    placeholder="Unlimited"
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="platform-settings-label">
                                    Default Max Branches
                                </label>

                                <input
                                    type="number"
                                    name="default_max_branches"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $defaultMaxBranches
                                    ); ?>"
                                    min="1"
                                    placeholder="Unlimited"
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="platform-settings-card">
                    <div class="platform-settings-card-header">
                        <span class="platform-settings-card-icon">
                            <i class="bi bi-envelope-at"></i>
                        </span>

                        <div>
                            <h3 class="platform-settings-card-title">
                                SMTP Configuration
                            </h3>

                            <div class="platform-settings-card-subtitle">
                                Outgoing mail server used by FieldPlx notifications
                            </div>
                        </div>
                    </div>

                    <div class="platform-settings-card-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="platform-settings-label">
                                    SMTP Host
                                    <span class="platform-settings-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="smtp_host"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $smtpHost
                                    ); ?>"
                                    maxlength="190"
                                    placeholder="smtp.hostinger.com"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="platform-settings-label">
                                    SMTP Port
                                </label>

                                <input
                                    type="number"
                                    name="smtp_port"
                                    class="form-control platform-settings-control"
                                    value="<?= (int) $smtpPort; ?>"
                                    min="1"
                                    max="65535"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <label class="platform-settings-label">
                                    Encryption
                                </label>

                                <select
                                    name="smtp_encryption"
                                    class="form-select platform-settings-control"
                                >
                                    <?php foreach (
                                        $smtpEncryptionOptions as
                                        $encryptionValue =>
                                        $encryptionLabel
                                    ): ?>
                                        <option
                                            value="<?= platformSettingsEscape(
                                                $encryptionValue
                                            ); ?>"
                                            <?= $smtpEncryption ===
                                                $encryptionValue
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= platformSettingsEscape(
                                                $encryptionLabel
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="platform-settings-label">
                                    SMTP Username
                                </label>

                                <input
                                    type="email"
                                    name="smtp_username"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $smtpUsername
                                    ); ?>"
                                    maxlength="190"
                                    autocomplete="off"
                                >
                            </div>

                            <div class="col-12">
                                <label class="platform-settings-label">
                                    SMTP Password
                                </label>

                                <div class="platform-settings-password-wrap">
                                    <input
                                        type="password"
                                        name="smtp_password"
                                        id="smtpPassword"
                                        class="form-control platform-settings-control pe-5"
                                        value="<?= platformSettingsEscape(
                                            $smtpPassword
                                        ); ?>"
                                        maxlength="500"
                                        autocomplete="new-password"
                                    >

                                    <button
                                        type="button"
                                        class="platform-settings-password-toggle"
                                        data-password-target="smtpPassword"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="platform-settings-label">
                                    Sender Name
                                </label>

                                <input
                                    type="text"
                                    name="smtp_from_name"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $smtpFromName
                                    ); ?>"
                                    maxlength="190"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="platform-settings-label">
                                    Sender Email
                                </label>

                                <input
                                    type="email"
                                    name="smtp_from_email"
                                    class="form-control platform-settings-control"
                                    value="<?= platformSettingsEscape(
                                        $smtpFromEmail
                                    ); ?>"
                                    maxlength="190"
                                >
                            </div>

                            <div class="col-12">
                                <label class="platform-settings-toggle">
                                    <input
                                        type="checkbox"
                                        name="smtp_enabled"
                                        value="1"
                                        <?= $smtpEnabled === 1
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span>
                                        <strong>Enable SMTP</strong><br>
                                        Use these SMTP credentials for platform emails and password-reset links.
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

            </div>

            <aside class="platform-settings-side">

                <section class="platform-settings-card">
                    <div class="platform-settings-card-header">
                        <span class="platform-settings-card-icon">
                            <i class="bi bi-image"></i>
                        </span>

                        <div>
                            <h3 class="platform-settings-card-title">
                                Platform Logo
                            </h3>

                            <div class="platform-settings-card-subtitle">
                                Main administration branding
                            </div>
                        </div>
                    </div>

                    <div class="platform-settings-card-body">
                        <div
                            class="platform-settings-logo"
                            id="logoPreview"
                        >
                            <?php if ($logoDisplayPath !== ''): ?>
                                <img
                                    src="../<?= platformSettingsEscape(
                                        ltrim(
                                            $logoDisplayPath,
                                            '/'
                                        )
                                    ); ?>"
                                    alt=""
                                >
                            <?php else: ?>
                                <strong>FP</strong>
                            <?php endif; ?>
                        </div>

                        <input
                            type="file"
                            name="platform_logo"
                            id="logoInput"
                            class="platform-settings-logo-input"
                            accept=".jpg,.jpeg,.png,.webp,.svg,image/jpeg,image/png,image/webp,image/svg+xml"
                        >

                        <label
                            for="logoInput"
                            class="platform-settings-logo-button"
                        >
                            <i class="bi bi-upload"></i>
                            Choose Logo
                        </label>

                        <?php if (!empty($settings['logo_path'])): ?>
                            <label class="platform-settings-remove">
                                <input
                                    type="checkbox"
                                    name="remove_logo"
                                    id="removeLogo"
                                    value="1"
                                >
                                Remove current logo
                            </label>
                        <?php endif; ?>

                        <div class="platform-settings-help text-center">
                            JPG, PNG, WEBP, or SVG. Maximum 3 MB.
                        </div>
                    </div>
                </section>

                <section class="platform-settings-card">
                    <div class="platform-settings-card-header">
                        <span class="platform-settings-card-icon">
                            <i class="bi bi-shield-lock"></i>
                        </span>

                        <div>
                            <h3 class="platform-settings-card-title">
                                Security Defaults
                            </h3>

                            <div class="platform-settings-card-subtitle">
                                Login and password controls
                            </div>
                        </div>
                    </div>

                    <div class="platform-settings-card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="platform-settings-label">
                                    Session Timeout (Minutes)
                                </label>

                                <input
                                    type="number"
                                    name="session_timeout_minutes"
                                    class="form-control platform-settings-control"
                                    value="<?= (int) $sessionTimeoutMinutes; ?>"
                                    min="15"
                                    max="1440"
                                >
                            </div>

                            <div class="col-12">
                                <label class="platform-settings-label">
                                    Minimum Password Length
                                </label>

                                <input
                                    type="number"
                                    name="password_min_length"
                                    class="form-control platform-settings-control"
                                    value="<?= (int) $passwordMinLength; ?>"
                                    min="8"
                                    max="128"
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="platform-settings-card">
                    <div class="platform-settings-card-header">
                        <span class="platform-settings-card-icon">
                            <i class="bi bi-toggles"></i>
                        </span>

                        <div>
                            <h3 class="platform-settings-card-title">
                                Platform Controls
                            </h3>

                            <div class="platform-settings-card-subtitle">
                                Signup, verification, and support access
                            </div>
                        </div>
                    </div>

                    <div class="platform-settings-card-body">
                        <label class="platform-settings-toggle">
                            <input
                                type="checkbox"
                                name="allow_public_signup"
                                value="1"
                                <?= $allowPublicSignup === 1
                                    ? 'checked'
                                    : ''; ?>
                            >
                            <span>
                                <strong>Public Signup</strong><br>
                                Allow businesses to register themselves.
                            </span>
                        </label>

                        <label class="platform-settings-toggle">
                            <input
                                type="checkbox"
                                name="require_email_verification"
                                value="1"
                                <?= $requireEmailVerification === 1
                                    ? 'checked'
                                    : ''; ?>
                            >
                            <span>
                                <strong>Email Verification</strong><br>
                                Require email verification for new accounts.
                            </span>
                        </label>

                        <label class="platform-settings-toggle">
                            <input
                                type="checkbox"
                                name="allow_support_access"
                                value="1"
                                <?= $allowSupportAccess === 1
                                    ? 'checked'
                                    : ''; ?>
                            >
                            <span>
                                <strong>Support Access</strong><br>
                                Permit authorised platform support sessions.
                            </span>
                        </label>

                        <label class="platform-settings-toggle">
                            <input
                                type="checkbox"
                                name="maintenance_mode"
                                id="maintenanceMode"
                                value="1"
                                <?= $maintenanceMode === 1
                                    ? 'checked'
                                    : ''; ?>
                            >
                            <span>
                                <strong>Maintenance Mode</strong><br>
                                Temporarily restrict tenant access.
                            </span>
                        </label>

                        <div
                            id="maintenanceMessageWrap"
                            style="margin-top:10px;"
                        >
                            <label class="platform-settings-label">
                                Maintenance Message
                            </label>

                            <textarea
                                name="maintenance_message"
                                class="form-control platform-settings-control"
                                placeholder="FieldPlx is temporarily unavailable while maintenance is completed."
                            ><?= platformSettingsEscape(
                                $maintenanceMessage
                            ); ?></textarea>
                        </div>
                    </div>
                </section>

                <div class="platform-settings-submit-card">
                    <button
                        type="submit"
                        class="platform-settings-submit"
                        id="settingsSubmit"
                    >
                        <i class="bi bi-check2-circle me-1"></i>
                        Save Platform Settings
                    </button>
                </div>

            </aside>

        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const logoInput =
        document.getElementById('logoInput');

    const logoPreview =
        document.getElementById('logoPreview');

    const removeLogo =
        document.getElementById('removeLogo');

    if (logoInput && logoPreview) {
        logoInput.addEventListener(
            'change',
            function () {
                const file = logoInput.files[0];

                if (!file) {
                    return;
                }

                if (removeLogo) {
                    removeLogo.checked = false;
                }

                const reader = new FileReader();

                reader.onload = function (event) {
                    logoPreview.innerHTML = '';

                    const image =
                        document.createElement('img');

                    image.src = event.target.result;
                    image.alt = '';

                    logoPreview.appendChild(image);
                };

                reader.readAsDataURL(file);
            }
        );
    }

    if (removeLogo && logoPreview) {
        removeLogo.addEventListener(
            'change',
            function () {
                if (removeLogo.checked) {
                    if (logoInput) {
                        logoInput.value = '';
                    }

                    logoPreview.innerHTML =
                        '<strong>FP</strong>';
                }
            }
        );
    }

    const passwordButtons =
        document.querySelectorAll(
            '[data-password-target]'
        );

    passwordButtons.forEach(
        function (button) {
            button.addEventListener(
                'click',
                function () {
                    const input =
                        document.getElementById(
                            button.getAttribute(
                                'data-password-target'
                            )
                        );

                    if (!input) {
                        return;
                    }

                    input.type =
                        input.type === 'password'
                            ? 'text'
                            : 'password';

                    const icon =
                        button.querySelector('i');

                    if (icon) {
                        icon.className =
                            input.type === 'password'
                                ? 'bi bi-eye'
                                : 'bi bi-eye-slash';
                    }
                }
            );
        }
    );

    const maintenanceMode =
        document.getElementById(
            'maintenanceMode'
        );

    const maintenanceMessageWrap =
        document.getElementById(
            'maintenanceMessageWrap'
        );

    function updateMaintenanceMessage() {
        if (!maintenanceMessageWrap) {
            return;
        }

        maintenanceMessageWrap.style.display =
            maintenanceMode &&
            maintenanceMode.checked
                ? 'block'
                : 'none';
    }

    if (maintenanceMode) {
        maintenanceMode.addEventListener(
            'change',
            updateMaintenanceMessage
        );
    }

    updateMaintenanceMessage();

    const form =
        document.getElementById(
            'platformSettingsForm'
        );

    const submitButton =
        document.getElementById(
            'settingsSubmit'
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
