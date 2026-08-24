<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function settingsResponse(int $status, bool $success, string $message, array $extra = []): void
{
    http_response_code($status);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function postValue(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    return trim((string) $_POST[$key]);
}

function nullablePositiveInt(string $value)
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $id = (int) $value;

    return $id > 0 ? $id : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    settingsResponse(405, false, 'Method not allowed.');
}

$csrf = postValue('csrf_token');

if (
    empty($_SESSION['platform_settings_csrf']) ||
    !is_string($_SESSION['platform_settings_csrf']) ||
    $csrf === '' ||
    !hash_equals($_SESSION['platform_settings_csrf'], $csrf)
) {
    settingsResponse(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$platformName = postValue('platform_name', 'FieldPlx');
$platformTagline = postValue('platform_tagline');
$defaultCountryId = nullablePositiveInt(postValue('default_country_id'));
$defaultCurrencyId = nullablePositiveInt(postValue('default_currency_id'));
$defaultTimezone = postValue('default_timezone', 'UTC');
$defaultDateFormat = postValue('default_date_format', 'd-m-Y');
$defaultTrialDays = (int) postValue('default_trial_days', '14');

$allowPublicSignup = postValue('allow_public_signup') === '1' ? 1 : 0;
$requireEmailVerification = postValue('require_email_verification') === '1' ? 1 : 0;
$allowSupportAccess = postValue('allow_support_access') === '1' ? 1 : 0;
$maintenanceMode = postValue('maintenance_mode') === '1' ? 1 : 0;

$maintenanceMessage = postValue('maintenance_message');
$sessionTimeoutMinutes = (int) postValue('session_timeout_minutes', '120');
$passwordMinLength = (int) postValue('password_min_length', '8');

$allowedDateFormats = [
    'd-m-Y',
    'd/m/Y',
    'm-d-Y',
    'm/d/Y',
    'Y-m-d'
];

if ($platformName === '' || strlen($platformName) > 190) {
    settingsResponse(
        422,
        false,
        'Platform name is required and must be 190 characters or less.'
    );
}

if (strlen($platformTagline) > 255) {
    settingsResponse(
        422,
        false,
        'Platform tagline must be 255 characters or less.'
    );
}

if ($defaultTimezone === '' || strlen($defaultTimezone) > 100) {
    settingsResponse(
        422,
        false,
        'Default timezone is required.'
    );
}

if (!in_array($defaultDateFormat, $allowedDateFormats, true)) {
    settingsResponse(
        422,
        false,
        'Invalid default date format.'
    );
}

if ($defaultTrialDays < 0 || $defaultTrialDays > 3650) {
    settingsResponse(
        422,
        false,
        'Default trial days must be between 0 and 3650.'
    );
}

if ($sessionTimeoutMinutes < 5 || $sessionTimeoutMinutes > 10080) {
    settingsResponse(
        422,
        false,
        'Session timeout must be between 5 and 10080 minutes.'
    );
}

if ($passwordMinLength < 6 || $passwordMinLength > 128) {
    settingsResponse(
        422,
        false,
        'Minimum password length must be between 6 and 128.'
    );
}

if ($defaultCountryId !== null) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM countries
        WHERE id = :id
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([':id' => $defaultCountryId]);

    if (!$stmt->fetchColumn()) {
        settingsResponse(
            422,
            false,
            'Selected default country is not available.'
        );
    }
}

if ($defaultCurrencyId !== null) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM currencies
        WHERE id = :id
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([':id' => $defaultCurrencyId]);

    if (!$stmt->fetchColumn()) {
        settingsResponse(
            422,
            false,
            'Selected default currency is not available.'
        );
    }
}

try {
    $pdo->beginTransaction();

    $currentStmt = $pdo->query("
        SELECT id
        FROM platform_settings
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
    ");

    $settingsId = $currentStmt->fetchColumn();

    $params = [
        ':platform_name' => $platformName,
        ':platform_tagline' => $platformTagline === '' ? null : $platformTagline,
        ':default_country_id' => $defaultCountryId,
        ':default_currency_id' => $defaultCurrencyId,
        ':default_timezone' => $defaultTimezone,
        ':default_date_format' => $defaultDateFormat,
        ':default_trial_days' => $defaultTrialDays,
        ':allow_public_signup' => $allowPublicSignup,
        ':require_email_verification' => $requireEmailVerification,
        ':allow_support_access' => $allowSupportAccess,
        ':maintenance_mode' => $maintenanceMode,
        ':maintenance_message' => $maintenanceMessage === '' ? null : $maintenanceMessage,
        ':session_timeout_minutes' => $sessionTimeoutMinutes,
        ':password_min_length' => $passwordMinLength
    ];

    if ($settingsId) {
        $params[':id'] = (int) $settingsId;

        $stmt = $pdo->prepare("
            UPDATE platform_settings
            SET
                platform_name = :platform_name,
                platform_tagline = :platform_tagline,
                default_country_id = :default_country_id,
                default_currency_id = :default_currency_id,
                default_timezone = :default_timezone,
                default_date_format = :default_date_format,
                default_trial_days = :default_trial_days,
                allow_public_signup = :allow_public_signup,
                require_email_verification = :require_email_verification,
                allow_support_access = :allow_support_access,
                maintenance_mode = :maintenance_mode,
                maintenance_message = :maintenance_message,
                session_timeout_minutes = :session_timeout_minutes,
                password_min_length = :password_min_length
            WHERE id = :id
        ");

        $stmt->execute($params);

        $savedId = (int) $settingsId;
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO platform_settings (
                platform_name,
                platform_tagline,
                default_country_id,
                default_currency_id,
                default_timezone,
                default_date_format,
                default_trial_days,
                allow_public_signup,
                require_email_verification,
                allow_support_access,
                maintenance_mode,
                maintenance_message,
                session_timeout_minutes,
                password_min_length
            ) VALUES (
                :platform_name,
                :platform_tagline,
                :default_country_id,
                :default_currency_id,
                :default_timezone,
                :default_date_format,
                :default_trial_days,
                :allow_public_signup,
                :require_email_verification,
                :allow_support_access,
                :maintenance_mode,
                :maintenance_message,
                :session_timeout_minutes,
                :password_min_length
            )
        ");

        $stmt->execute($params);

        $savedId = (int) $pdo->lastInsertId();
    }

    $pdo->commit();
settingsResponse(
        200,
        true,
        'Platform settings saved successfully.',
        [
            'settings_id' => $savedId
        ]
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'FieldPlx platform settings save error: ' .
        $e->getMessage()
    );

    settingsResponse(
        500,
        false,
        'Unable to save platform settings. Please try again.'
    );
}
