<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Login API
 *
 * Recommended location:
 *   /api/mobile/login.php
 *
 * Expected project structure:
 *   /includes/db.php
 *   /includes/audit.php
 *   /api/mobile/login.php
 *
 * Request:
 *   POST application/json
 *
 * {
 *   "identifier": "TNT-0001 or user@example.com",
 *   "password": "your-password",
 *   "platform": "android",
 *   "device_name": "Samsung Galaxy S24",
 *   "device_identifier": "unique-device-id",
 *   "push_token": "firebase-push-token"
 * }
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/api-config.php';

/*
|--------------------------------------------------------------------------
| MOBILE API CONFIG VALIDATION
|--------------------------------------------------------------------------
*/
if (
    !defined('FIELDPLX_API_SECRET') ||
    trim((string)FIELDPLX_API_SECRET) === ''
) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'api_secret_not_configured',
            'message' => 'FIELDPLX mobile API configuration is incomplete.'
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit;
}

if (!defined('FIELDPLX_ACCESS_TOKEN_TTL')) {
    define('FIELDPLX_ACCESS_TOKEN_TTL', 60 * 60 * 24 * 30);
}

if (!defined('FIELDPLX_TOKEN_ISSUER')) {
    define('FIELDPLX_TOKEN_ISSUER', 'FieldPlx');
}

if (!defined('FIELDPLX_TOKEN_AUDIENCE')) {
    define('FIELDPLX_TOKEN_AUDIENCE', 'FieldPlx-Mobile');
}

/*
|--------------------------------------------------------------------------
| RESPONSE HEADERS
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function ml_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function ml_base64url_encode(string $data): string
{
    return rtrim(
        strtr(base64_encode($data), '+/', '-_'),
        '='
    );
}

function ml_create_token(array $payload): string
{
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT'
    ];

    $headerEncoded = ml_base64url_encode(
        json_encode($header, JSON_UNESCAPED_SLASHES)
    );

    $payloadEncoded = ml_base64url_encode(
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );

    $signature = hash_hmac(
        'sha256',
        $headerEncoded . '.' . $payloadEncoded,
        FIELDPLX_API_SECRET,
        true
    );

    return $headerEncoded . '.' . $payloadEncoded . '.' .
        ml_base64url_encode($signature);
}

function ml_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        ml_response(400, [
            'success' => false,
            'error' => [
                'code' => 'invalid_json',
                'message' => 'Invalid JSON request body.'
            ]
        ]);
    }

    return $decoded;
}

function ml_string($value): string
{
    return trim((string)($value ?? ''));
}

function ml_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($forwarded[0]);
    }

    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function ml_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");

    $stmt->execute([
        ':table_name' => $table
    ]);

    $cache[$table] = ((int)$stmt->fetchColumn() > 0);

    return $cache[$table];
}

function ml_audit_failed_login(
    PDO $pdo,
    string $tenantCode,
    string $email,
    string $reason,
    ?array $resolvedUser = null
): void {
    try {
        $tenantId = null;
        $branchId = null;
        $userId = null;

        if (is_array($resolvedUser)) {
            $tenantId = !empty($resolvedUser['tenant_id'])
                ? (int)$resolvedUser['tenant_id']
                : null;

            $branchId = !empty($resolvedUser['branch_id'])
                ? (int)$resolvedUser['branch_id']
                : null;

            $userId = !empty($resolvedUser['user_id'])
                ? (int)$resolvedUser['user_id']
                : null;
        } elseif ($tenantCode !== '') {
            try {
                $tenantStmt = $pdo->prepare("
                    SELECT id
                    FROM tenants
                    WHERE tenant_code = :tenant_code
                      AND deleted_at IS NULL
                    LIMIT 1
                ");

                $tenantStmt->execute([
                    ':tenant_code' => $tenantCode
                ]);

                $resolvedTenantId = $tenantStmt->fetchColumn();

                if ($resolvedTenantId) {
                    $tenantId = (int)$resolvedTenantId;
                }
            } catch (Throwable $ignored) {
            }
        }

        tenantAuditLog(
            $pdo,
            'MOBILE_LOGIN_FAILED',
            $tenantId,
            $branchId,
            $userId,
            'mobile_login',
            $userId,
            null,
            [
                'tenant_code' => $tenantCode,
                'email' => $email,
                'result' => 'failed',
                'reason' => $reason,
                'ip_address' => ml_client_ip(),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
            ]
        );
    } catch (Throwable $ignored) {
        // Authentication must never fail because audit logging failed.
    }
}

function ml_log_activity(PDO $pdo, array $user, string $fullName): void
{
    if (!ml_table_exists($pdo, 'activity_events')) {
        return;
    }

    try {
        $columnStmt = $pdo->query("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'activity_events'
        ");

        $available = [];

        foreach ($columnStmt->fetchAll(PDO::FETCH_COLUMN) as $columnName) {
            $available[(string)$columnName] = true;
        }

        if (
            !isset($available['tenant_id']) ||
            !isset($available['event_type']) ||
            !isset($available['title'])
        ) {
            return;
        }

        $columns = ['tenant_id', 'event_type', 'title'];
        $values = [':tenant_id', ':event_type', ':title'];

        $params = [
            ':tenant_id' => (int)$user['tenant_id'],
            ':event_type' => 'mobile_login',
            ':title' => $fullName . ' signed in from mobile'
        ];

        if (isset($available['actor_user_id'])) {
            $columns[] = 'actor_user_id';
            $values[] = ':actor_user_id';
            $params[':actor_user_id'] = (int)$user['user_id'];
        }

        if (isset($available['branch_id'])) {
            $columns[] = 'branch_id';
            $values[] = ':branch_id';
            $params[':branch_id'] = !empty($user['branch_id'])
                ? (int)$user['branch_id']
                : null;
        }

        $sql = "INSERT INTO activity_events (" .
            implode(', ', $columns) .
            ") VALUES (" .
            implode(', ', $values) .
            ")";

        $activityStmt = $pdo->prepare($sql);
        $activityStmt->execute($params);
    } catch (Throwable $ignored) {
        // Activity logging is optional.
    }
}

function ml_register_device(
    PDO $pdo,
    array $user,
    string $platform,
    string $deviceName,
    string $deviceIdentifier,
    string $pushToken
): void {
    if (!ml_table_exists($pdo, 'user_devices')) {
        return;
    }

    try {
        $deviceIdentifierHash = $deviceIdentifier !== ''
            ? hash('sha256', $deviceIdentifier)
            : null;

        $existingDeviceId = null;

        if ($deviceIdentifierHash !== null) {
            $deviceCheck = $pdo->prepare("
                SELECT id
                FROM user_devices
                WHERE tenant_id = :tenant_id
                  AND user_id = :user_id
                  AND device_identifier_hash = :device_identifier_hash
                ORDER BY id DESC
                LIMIT 1
            ");

            $deviceCheck->execute([
                ':tenant_id' => (int)$user['tenant_id'],
                ':user_id' => (int)$user['user_id'],
                ':device_identifier_hash' => $deviceIdentifierHash
            ]);

            $existingDeviceId = $deviceCheck->fetchColumn();
        }

        if (!$existingDeviceId && $pushToken !== '') {
            $pushCheck = $pdo->prepare("
                SELECT id
                FROM user_devices
                WHERE tenant_id = :tenant_id
                  AND user_id = :user_id
                  AND push_token = :push_token
                ORDER BY id DESC
                LIMIT 1
            ");

            $pushCheck->execute([
                ':tenant_id' => (int)$user['tenant_id'],
                ':user_id' => (int)$user['user_id'],
                ':push_token' => $pushToken
            ]);

            $existingDeviceId = $pushCheck->fetchColumn();
        }

        if ($existingDeviceId) {
            $deviceUpdate = $pdo->prepare("
                UPDATE user_devices
                SET
                    platform = :platform,
                    device_name = :device_name,
                    device_identifier_hash = :device_identifier_hash,
                    push_token = :push_token,
                    last_ip_address = :last_ip_address,
                    last_seen_at = NOW(),
                    status = 'active',
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :tenant_id
                  AND user_id = :user_id
            ");

            $deviceUpdate->execute([
                ':platform' => $platform,
                ':device_name' => $deviceName !== '' ? $deviceName : null,
                ':device_identifier_hash' => $deviceIdentifierHash,
                ':push_token' => $pushToken !== '' ? $pushToken : null,
                ':last_ip_address' => ml_client_ip(),
                ':id' => (int)$existingDeviceId,
                ':tenant_id' => (int)$user['tenant_id'],
                ':user_id' => (int)$user['user_id']
            ]);

            return;
        }

        $deviceInsert = $pdo->prepare("
            INSERT INTO user_devices
            (
                tenant_id,
                user_id,
                platform,
                device_name,
                device_identifier_hash,
                push_token,
                last_ip_address,
                last_seen_at,
                status,
                created_at,
                updated_at
            )
            VALUES
            (
                :tenant_id,
                :user_id,
                :platform,
                :device_name,
                :device_identifier_hash,
                :push_token,
                :last_ip_address,
                NOW(),
                'active',
                NOW(),
                NOW()
            )
        ");

        $deviceInsert->execute([
            ':tenant_id' => (int)$user['tenant_id'],
            ':user_id' => (int)$user['user_id'],
            ':platform' => $platform,
            ':device_name' => $deviceName !== '' ? $deviceName : null,
            ':device_identifier_hash' => $deviceIdentifierHash,
            ':push_token' => $pushToken !== '' ? $pushToken : null,
            ':last_ip_address' => ml_client_ip()
        ]);
    } catch (Throwable $deviceError) {
        error_log(
            'FieldPlx mobile device registration error: ' .
            $deviceError->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| POST ONLY
|--------------------------------------------------------------------------
*/
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ml_response(405, [
        'success' => false,
        'error' => [
            'code' => 'method_not_allowed',
            'message' => 'Only POST requests are allowed.'
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| REQUEST INPUT
|--------------------------------------------------------------------------
*/
$input = ml_json_body();

$identifier = ml_string(
    $input['identifier'] ??
    $input['login_identifier'] ??
    ''
);

$password = (string)($input['password'] ?? '');

$platform = strtolower(
    ml_string($input['platform'] ?? 'other')
);

$deviceName = ml_string($input['device_name'] ?? '');
$deviceIdentifier = ml_string($input['device_identifier'] ?? '');
$pushToken = ml_string($input['push_token'] ?? '');

/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/
if ($identifier === '') {
    ml_response(422, [
        'success' => false,
        'error' => [
            'code' => 'identifier_required',
            'message' => 'Enter your Tenant Code or Email Address.'
        ]
    ]);
}

if ($password === '') {
    ml_response(422, [
        'success' => false,
        'error' => [
            'code' => 'password_required',
            'message' => 'Enter your password.'
        ]
    ]);
}

$allowedPlatforms = ['android', 'ios', 'web', 'other'];

if (!in_array($platform, $allowedPlatforms, true)) {
    ml_response(422, [
        'success' => false,
        'error' => [
            'code' => 'invalid_platform',
            'message' => 'Platform must be android, ios, web or other.'
        ]
    ]);
}

try {
    /*
    |--------------------------------------------------------------------------
    | SAME USER/TENANT RESOLUTION AS WEB LOGIN
    |--------------------------------------------------------------------------
    */
    $isEmail = filter_var(
        $identifier,
        FILTER_VALIDATE_EMAIL
    ) !== false;

    $lookupValue = $isEmail
        ? strtolower($identifier)
        : strtoupper($identifier);

    $baseSelect = "
        SELECT
            u.id AS user_id,
            u.tenant_id,
            u.branch_id,
            u.department_id,
            u.role_id,
            u.employee_code,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            u.alternate_phone,
            u.password_hash,
            u.avatar_path,
            u.job_title,
            u.labor_rate,
            u.is_bookable,
            u.is_field_worker,
            u.is_tenant_admin,
            u.status AS user_status,
            u.last_login_at,

            t.tenant_code,
            t.legal_name,
            t.display_name AS tenant_name,
            t.business_type,
            t.registration_number,
            t.tax_number,
            t.email AS tenant_email,
            t.phone AS tenant_phone,
            t.website_url,
            t.country_id AS tenant_country_id,
            t.currency_id AS tenant_currency_id,
            t.timezone AS tenant_timezone,
            t.date_format AS tenant_date_format,
            t.logo_path AS tenant_logo_path,
            t.invoice_logo_path AS tenant_invoice_logo_path,
            t.status AS tenant_status,

            b.branch_code,
            b.name AS branch_name,
            b.country_id AS branch_country_id,
            b.currency_id AS branch_currency_id,
            b.timezone AS branch_timezone,
            b.logo_path AS branch_logo_path,
            b.invoice_logo_path AS branch_invoice_logo_path,
            b.is_head_office,
            b.status AS branch_status,

            d.name AS department_name,
            d.code AS department_code,
            d.status AS department_status,

            r.name AS role_name,
            r.code AS role_code,
            r.is_admin AS role_is_admin,
            r.is_system_role,
            r.status AS role_status,

            tc.name AS tenant_country_name,
            tc.iso2 AS tenant_country_iso2,

            tcur.currency_code AS tenant_currency_code,
            tcur.currency_name AS tenant_currency_name,
            tcur.symbol AS tenant_currency_symbol,
            tcur.symbol_position AS tenant_currency_symbol_position,
            tcur.decimal_places AS tenant_currency_decimal_places

        FROM users u

        INNER JOIN tenants t
            ON t.id = u.tenant_id
           AND t.deleted_at IS NULL

        LEFT JOIN branches b
            ON b.id = u.branch_id
           AND b.tenant_id = u.tenant_id

        LEFT JOIN departments d
            ON d.id = u.department_id
           AND d.tenant_id = u.tenant_id

        LEFT JOIN roles r
            ON r.id = u.role_id
           AND r.tenant_id = u.tenant_id

        LEFT JOIN countries tc
            ON tc.id = t.country_id

        LEFT JOIN currencies tcur
            ON tcur.id = t.currency_id
    ";

    if ($isEmail) {
        $stmt = $pdo->prepare(
            $baseSelect . "
                WHERE LOWER(u.email) = :identifier
                  AND u.deleted_at IS NULL
                ORDER BY
                    u.is_tenant_admin DESC,
                    u.id ASC
            "
        );
    } else {
        $stmt = $pdo->prepare(
            $baseSelect . "
                WHERE UPPER(t.tenant_code) = :identifier
                  AND u.is_tenant_admin = 1
                  AND u.deleted_at IS NULL
                ORDER BY u.id ASC
                LIMIT 1
            "
        );
    }

    $stmt->execute([
        ':identifier' => $lookupValue
    ]);

    $user = false;

    if ($isEmail) {
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $matchedUsers = [];

        foreach ($candidates as $candidate) {
            if (
                password_verify(
                    $password,
                    (string)$candidate['password_hash']
                )
            ) {
                $matchedUsers[] = $candidate;
            }
        }

        if (count($matchedUsers) > 1) {
            ml_audit_failed_login(
                $pdo,
                '',
                strtolower($identifier),
                'ambiguous_email_login'
            );

            ml_response(409, [
                'success' => false,
                'error' => [
                    'code' => 'tenant_code_required',
                    'message' =>
                        'This email is linked to multiple businesses. Please use your Tenant Code.'
                ]
            ]);
        }

        if (count($matchedUsers) === 1) {
            $user = $matchedUsers[0];
        }
    } else {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $user &&
            !password_verify(
                $password,
                (string)$user['password_hash']
            )
        ) {
            $user = false;
        }
    }

    if (!$user) {
        ml_audit_failed_login(
            $pdo,
            $isEmail ? '' : strtoupper($identifier),
            $isEmail ? strtolower($identifier) : '',
            'invalid_credentials'
        );

        ml_response(401, [
            'success' => false,
            'error' => [
                'code' => 'invalid_credentials',
                'message' => 'Invalid Tenant Code / Email or password.'
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | USER / TENANT / BRANCH / DEPARTMENT / ROLE STATUS
    |--------------------------------------------------------------------------
    */
    if ((string)$user['user_status'] !== 'active') {
        ml_audit_failed_login(
            $pdo,
            (string)$user['tenant_code'],
            (string)$user['email'],
            'user_not_active',
            $user
        );

        ml_response(403, [
            'success' => false,
            'error' => [
                'code' => 'user_not_active',
                'message' =>
                    'Your user account is not active. Contact your administrator.'
            ]
        ]);
    }

    if (
        !in_array(
            (string)$user['tenant_status'],
            ['trial', 'active'],
            true
        )
    ) {
        ml_audit_failed_login(
            $pdo,
            (string)$user['tenant_code'],
            (string)$user['email'],
            'tenant_not_active',
            $user
        );

        ml_response(403, [
            'success' => false,
            'error' => [
                'code' => 'tenant_not_active',
                'message' =>
                    'This business account is not currently active. Contact FieldPlx support.'
            ]
        ]);
    }

    if (
        !empty($user['branch_id']) &&
        !empty($user['branch_status']) &&
        (string)$user['branch_status'] !== 'active'
    ) {
        ml_audit_failed_login(
            $pdo,
            (string)$user['tenant_code'],
            (string)$user['email'],
            'branch_not_active',
            $user
        );

        ml_response(403, [
            'success' => false,
            'error' => [
                'code' => 'branch_not_active',
                'message' =>
                    'Your assigned branch is not active. Contact your administrator.'
            ]
        ]);
    }

    if (
        !empty($user['department_id']) &&
        !empty($user['department_status']) &&
        (string)$user['department_status'] !== 'active'
    ) {
        ml_audit_failed_login(
            $pdo,
            (string)$user['tenant_code'],
            (string)$user['email'],
            'department_not_active',
            $user
        );

        ml_response(403, [
            'success' => false,
            'error' => [
                'code' => 'department_not_active',
                'message' =>
                    'Your assigned department is not active. Contact your administrator.'
            ]
        ]);
    }

    if (
        !empty($user['role_id']) &&
        !empty($user['role_status']) &&
        (string)$user['role_status'] !== 'active'
    ) {
        ml_audit_failed_login(
            $pdo,
            (string)$user['tenant_code'],
            (string)$user['email'],
            'role_not_active',
            $user
        );

        ml_response(403, [
            'success' => false,
            'error' => [
                'code' => 'role_not_active',
                'message' =>
                    'Your assigned role is not active. Contact your administrator.'
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENT SUBSCRIPTION
    |--------------------------------------------------------------------------
    */
    $subscription = null;

    if (ml_table_exists($pdo, 'subscriptions')) {
        $subStmt = $pdo->prepare("
            SELECT
                s.id AS subscription_id,
                s.plan_id,
                s.currency_id AS subscription_currency_id,
                s.amount AS subscription_amount,
                s.start_date AS subscription_start_date,
                s.expiry_date AS subscription_expiry_date,
                s.trial_end_date,
                s.auto_renew,
                s.max_users_override,
                s.max_branches_override,
                s.max_customers_override,
                s.storage_limit_mb_override,
                s.status AS subscription_status,

                p.name AS plan_name,
                p.code AS plan_code,
                p.billing_cycle,
                p.duration_days,
                p.trial_days,
                p.max_users AS plan_max_users,
                p.max_branches AS plan_max_branches,
                p.max_customers AS plan_max_customers,
                p.storage_limit_mb AS plan_storage_limit_mb,
                p.api_calls_per_month,
                p.sms_per_month,
                p.email_per_month,
                p.ai_minutes_per_month,

                scur.currency_code AS subscription_currency_code,
                scur.symbol AS subscription_currency_symbol

            FROM subscriptions s

            LEFT JOIN plans p
                ON p.id = s.plan_id
               AND p.deleted_at IS NULL

            LEFT JOIN currencies scur
                ON scur.id = s.currency_id

            WHERE s.tenant_id = :tenant_id

            ORDER BY
                CASE s.status
                    WHEN 'active' THEN 1
                    WHEN 'trial' THEN 2
                    WHEN 'suspended' THEN 3
                    WHEN 'expired' THEN 4
                    ELSE 5
                END,
                s.id DESC

            LIMIT 1
        ");

        $subStmt->execute([
            ':tenant_id' => (int)$user['tenant_id']
        ]);

        $subscription = $subStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (
        $subscription &&
        !in_array(
            (string)$subscription['subscription_status'],
            ['trial', 'active'],
            true
        )
    ) {
        ml_audit_failed_login(
            $pdo,
            (string)$user['tenant_code'],
            (string)$user['email'],
            'subscription_not_active',
            $user
        );

        ml_response(403, [
            'success' => false,
            'error' => [
                'code' => 'subscription_not_active',
                'message' =>
                    'Your subscription is not active. Contact your administrator or renew the subscription.'
            ]
        ]);
    }

    if (
        $subscription &&
        !empty($subscription['subscription_expiry_date']) &&
        $subscription['subscription_expiry_date'] < date('Y-m-d')
    ) {
        ml_audit_failed_login(
            $pdo,
            (string)$user['tenant_code'],
            (string)$user['email'],
            'subscription_expired',
            $user
        );

        ml_response(403, [
            'success' => false,
            'error' => [
                'code' => 'subscription_expired',
                'message' =>
                    'Your subscription has expired. Please renew your subscription.'
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EFFECTIVE SETTINGS
    |--------------------------------------------------------------------------
    */
    $effectiveTimezone = !empty($user['branch_timezone'])
        ? (string)$user['branch_timezone']
        : (string)$user['tenant_timezone'];

    if ($effectiveTimezone === '') {
        $effectiveTimezone = 'UTC';
    }

    $effectiveCurrencyId = !empty($user['branch_currency_id'])
        ? (int)$user['branch_currency_id']
        : (int)$user['tenant_currency_id'];

    $effectiveCountryId = !empty($user['branch_country_id'])
        ? (int)$user['branch_country_id']
        : (int)$user['tenant_country_id'];

    $effectiveLogo = !empty($user['branch_logo_path'])
        ? (string)$user['branch_logo_path']
        : (string)$user['tenant_logo_path'];

    $effectiveInvoiceLogo = !empty($user['branch_invoice_logo_path'])
        ? (string)$user['branch_invoice_logo_path']
        : (string)$user['tenant_invoice_logo_path'];

    $fullName = trim(
        (string)$user['first_name'] . ' ' .
        (string)($user['last_name'] ?? '')
    );

    if ($fullName === '') {
        $fullName = (string)$user['email'];
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE ACCESS TOKEN
    |--------------------------------------------------------------------------
    */
    $issuedAt = time();
    $expiresAt = $issuedAt + FIELDPLX_ACCESS_TOKEN_TTL;

    $claims = [
        'iss' => FIELDPLX_TOKEN_ISSUER,
        'aud' => FIELDPLX_TOKEN_AUDIENCE,
        'iat' => $issuedAt,
        'nbf' => $issuedAt,
        'exp' => $expiresAt,
        'sub' => (string)$user['user_id'],

        'user_id' => (int)$user['user_id'],
        'tenant_id' => (int)$user['tenant_id'],
        'branch_id' => !empty($user['branch_id'])
            ? (int)$user['branch_id']
            : null,
        'department_id' => !empty($user['department_id'])
            ? (int)$user['department_id']
            : null,
        'role_id' => !empty($user['role_id'])
            ? (int)$user['role_id']
            : null,
        'role_code' => (string)($user['role_code'] ?? ''),
        'email' => (string)$user['email']
    ];

    $accessToken = ml_create_token($claims);

    /*
    |--------------------------------------------------------------------------
    | UPDATE LAST LOGIN
    |--------------------------------------------------------------------------
    */
    $updateLogin = $pdo->prepare("
        UPDATE users
        SET last_login_at = NOW()
        WHERE id = :id
          AND tenant_id = :tenant_id
          AND deleted_at IS NULL
    ");

    $updateLogin->execute([
        ':id' => (int)$user['user_id'],
        ':tenant_id' => (int)$user['tenant_id']
    ]);

    /*
    |--------------------------------------------------------------------------
    | REGISTER / UPDATE DEVICE
    |--------------------------------------------------------------------------
    */
    ml_register_device(
        $pdo,
        $user,
        $platform,
        $deviceName,
        $deviceIdentifier,
        $pushToken
    );

    /*
    |--------------------------------------------------------------------------
    | OPTIONAL ACTIVITY EVENT
    |--------------------------------------------------------------------------
    */
    ml_log_activity(
        $pdo,
        $user,
        $fullName
    );

    /*
    |--------------------------------------------------------------------------
    | SUCCESS AUDIT
    |--------------------------------------------------------------------------
    */
    try {
        tenantAuditLog(
            $pdo,
            'MOBILE_LOGIN_SUCCESS',
            (int)$user['tenant_id'],
            !empty($user['branch_id'])
                ? (int)$user['branch_id']
                : null,
            (int)$user['user_id'],
            'mobile_login',
            (int)$user['user_id'],
            null,
            [
                'result' => 'success',
                'tenant_code' => (string)$user['tenant_code'],
                'user_email' => (string)$user['email'],
                'user_name' => $fullName,
                'role_id' => !empty($user['role_id'])
                    ? (int)$user['role_id']
                    : null,
                'role_code' => (string)($user['role_code'] ?? ''),
                'branch_id' => !empty($user['branch_id'])
                    ? (int)$user['branch_id']
                    : null,
                'department_id' => !empty($user['department_id'])
                    ? (int)$user['department_id']
                    : null,
                'subscription_id' => $subscription
                    ? (int)$subscription['subscription_id']
                    : null,
                'plan_id' => $subscription
                    ? (int)$subscription['plan_id']
                    : null,
                'platform' => $platform,
                'device_name' => $deviceName,
                'ip_address' => ml_client_ip(),
                'login_at' => date('Y-m-d H:i:s')
            ]
        );
    } catch (Throwable $ignored) {
    }

    /*
    |--------------------------------------------------------------------------
    | EFFECTIVE SUBSCRIPTION LIMITS
    |--------------------------------------------------------------------------
    */
    $maxUsers = null;
    $maxBranches = null;
    $maxCustomers = null;
    $storageLimitMb = null;

    if ($subscription) {
        $maxUsers =
            $subscription['max_users_override'] !== null
                ? (int)$subscription['max_users_override']
                : (
                    $subscription['plan_max_users'] !== null
                        ? (int)$subscription['plan_max_users']
                        : null
                );

        $maxBranches =
            $subscription['max_branches_override'] !== null
                ? (int)$subscription['max_branches_override']
                : (
                    $subscription['plan_max_branches'] !== null
                        ? (int)$subscription['plan_max_branches']
                        : null
                );

        $maxCustomers =
            $subscription['max_customers_override'] !== null
                ? (int)$subscription['max_customers_override']
                : (
                    $subscription['plan_max_customers'] !== null
                        ? (int)$subscription['plan_max_customers']
                        : null
                );

        $storageLimitMb =
            $subscription['storage_limit_mb_override'] !== null
                ? (int)$subscription['storage_limit_mb_override']
                : (
                    $subscription['plan_storage_limit_mb'] !== null
                        ? (int)$subscription['plan_storage_limit_mb']
                        : null
                );
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */
    ml_response(200, [
        'success' => true,
        'message' => 'Login successful.',

        'data' => [
            'authentication' => [
                'token_type' => 'Bearer',
                'access_token' => $accessToken,
                'expires_in' => FIELDPLX_ACCESS_TOKEN_TTL,
                'expires_at' => gmdate('c', $expiresAt)
            ],

            'user' => [
                'id' => (int)$user['user_id'],
                'employee_code' => (string)($user['employee_code'] ?? ''),
                'first_name' => (string)$user['first_name'],
                'last_name' => (string)($user['last_name'] ?? ''),
                'name' => $fullName,
                'email' => (string)$user['email'],
                'phone' => (string)($user['phone'] ?? ''),
                'alternate_phone' =>
                    (string)($user['alternate_phone'] ?? ''),
                'avatar_path' => (string)($user['avatar_path'] ?? ''),
                'job_title' => (string)($user['job_title'] ?? ''),
                'labor_rate' => isset($user['labor_rate'])
                    ? (float)$user['labor_rate']
                    : null,
                'is_tenant_admin' => (bool)$user['is_tenant_admin'],
                'is_field_worker' => (bool)$user['is_field_worker'],
                'is_bookable' => (bool)$user['is_bookable'],
                'status' => (string)$user['user_status'],
                'last_login_at' =>
                    (string)($user['last_login_at'] ?? '')
            ],

            'tenant' => [
                'id' => (int)$user['tenant_id'],
                'tenant_code' => (string)$user['tenant_code'],
                'name' => (string)$user['tenant_name'],
                'legal_name' => (string)$user['legal_name'],
                'business_type' =>
                    (string)($user['business_type'] ?? ''),
                'registration_number' =>
                    (string)($user['registration_number'] ?? ''),
                'tax_number' =>
                    (string)($user['tax_number'] ?? ''),
                'email' =>
                    (string)($user['tenant_email'] ?? ''),
                'phone' =>
                    (string)($user['tenant_phone'] ?? ''),
                'website_url' =>
                    (string)($user['website_url'] ?? ''),
                'status' => (string)$user['tenant_status'],
                'timezone' =>
                    (string)$user['tenant_timezone'],
                'date_format' =>
                    (string)$user['tenant_date_format'],
                'country_id' =>
                    (int)$user['tenant_country_id'],
                'country_name' =>
                    (string)($user['tenant_country_name'] ?? ''),
                'country_iso2' =>
                    (string)($user['tenant_country_iso2'] ?? ''),
                'currency_id' =>
                    (int)$user['tenant_currency_id'],
                'currency_code' =>
                    (string)($user['tenant_currency_code'] ?? ''),
                'currency_name' =>
                    (string)($user['tenant_currency_name'] ?? ''),
                'currency_symbol' =>
                    (string)($user['tenant_currency_symbol'] ?? ''),
                'currency_symbol_position' =>
                    (string)($user['tenant_currency_symbol_position'] ?? 'before'),
                'currency_decimal_places' =>
                    isset($user['tenant_currency_decimal_places'])
                        ? (int)$user['tenant_currency_decimal_places']
                        : 2,
                'logo_path' =>
                    (string)($user['tenant_logo_path'] ?? ''),
                'invoice_logo_path' =>
                    (string)($user['tenant_invoice_logo_path'] ?? '')
            ],

            'branch' => [
                'id' => !empty($user['branch_id'])
                    ? (int)$user['branch_id']
                    : null,
                'code' => (string)($user['branch_code'] ?? ''),
                'name' => (string)($user['branch_name'] ?? ''),
                'is_head_office' =>
                    isset($user['is_head_office'])
                        ? (bool)$user['is_head_office']
                        : false,
                'status' => (string)($user['branch_status'] ?? '')
            ],

            'department' => [
                'id' => !empty($user['department_id'])
                    ? (int)$user['department_id']
                    : null,
                'name' =>
                    (string)($user['department_name'] ?? ''),
                'code' =>
                    (string)($user['department_code'] ?? ''),
                'status' =>
                    (string)($user['department_status'] ?? '')
            ],

            'role' => [
                'id' => !empty($user['role_id'])
                    ? (int)$user['role_id']
                    : null,
                'name' => (string)($user['role_name'] ?? ''),
                'code' => (string)($user['role_code'] ?? ''),
                'is_admin' =>
                    isset($user['role_is_admin'])
                        ? (bool)$user['role_is_admin']
                        : false,
                'is_system_role' =>
                    isset($user['is_system_role'])
                        ? (bool)$user['is_system_role']
                        : false,
                'status' => (string)($user['role_status'] ?? '')
            ],

            'settings' => [
                'timezone' => $effectiveTimezone,
                'country_id' => $effectiveCountryId,
                'currency_id' => $effectiveCurrencyId,
                'currency_code' =>
                    (string)($user['tenant_currency_code'] ?? ''),
                'currency_symbol' =>
                    (string)($user['tenant_currency_symbol'] ?? ''),
                'currency_symbol_position' =>
                    (string)($user['tenant_currency_symbol_position'] ?? 'before'),
                'decimal_places' =>
                    isset($user['tenant_currency_decimal_places'])
                        ? (int)$user['tenant_currency_decimal_places']
                        : 2,
                'logo_path' => $effectiveLogo,
                'invoice_logo_path' => $effectiveInvoiceLogo
            ],

            'subscription' => $subscription
                ? [
                    'id' => (int)$subscription['subscription_id'],
                    'status' =>
                        (string)$subscription['subscription_status'],
                    'start_date' =>
                        (string)$subscription['subscription_start_date'],
                    'expiry_date' =>
                        (string)($subscription['subscription_expiry_date'] ?? ''),
                    'trial_end_date' =>
                        (string)($subscription['trial_end_date'] ?? ''),
                    'auto_renew' =>
                        (bool)$subscription['auto_renew'],

                    'plan' => [
                        'id' => (int)$subscription['plan_id'],
                        'name' =>
                            (string)($subscription['plan_name'] ?? ''),
                        'code' =>
                            (string)($subscription['plan_code'] ?? ''),
                        'billing_cycle' =>
                            (string)($subscription['billing_cycle'] ?? '')
                    ],

                    'limits' => [
                        'max_users' => $maxUsers,
                        'max_branches' => $maxBranches,
                        'max_customers' => $maxCustomers,
                        'storage_limit_mb' => $storageLimitMb,
                        'api_calls_per_month' =>
                            $subscription['api_calls_per_month'] !== null
                                ? (int)$subscription['api_calls_per_month']
                                : null,
                        'sms_per_month' =>
                            $subscription['sms_per_month'] !== null
                                ? (int)$subscription['sms_per_month']
                                : null,
                        'email_per_month' =>
                            $subscription['email_per_month'] !== null
                                ? (int)$subscription['email_per_month']
                                : null,
                        'ai_minutes_per_month' =>
                            $subscription['ai_minutes_per_month'] !== null
                                ? (int)$subscription['ai_minutes_per_month']
                                : null
                    ]
                ]
                : null
        ]
    ]);
} catch (Throwable $e) {
    error_log(
        'FieldPlx mobile login API error: ' .
        $e->getMessage()
    );

    ml_response(500, [
        'success' => false,
        'error' => [
            'code' => 'server_error',
            'message' => 'Unable to sign in right now. Please try again.'
        ]
    ]);
}
