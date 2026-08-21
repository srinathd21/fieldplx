<?php
/**
 * FieldPlx Mobile REST API - Login
 *
 * Recommended location:
 * /public_html/api/mobile-login.php
 *
 * Uses:
 * /public_html/includes/db.php
 *
 * PHP 7.2+ / MySQLi
 *
 * POST JSON:
 * {
 *   "identifier": "user@example.com",
 *   "password": "password",
 *   "workspace": "company-slug",
 *   "platform": "android",
 *   "device_token": "optional-push-token",
 *   "device_name": "Pixel 9"
 * }
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

/*
|--------------------------------------------------------------------------
| API configuration
|--------------------------------------------------------------------------
|
| The requested secret is used only to sign access tokens.
| The mobile client should NOT send this secret with each request.
|
*/
const FIELDPLX_API_SECRET = 'coreplx';
const FIELDPLX_TOKEN_TTL  = 2592000; // 30 days

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| For a native mobile app CORS is usually irrelevant, but keeping these
| headers is useful for testing with browser-based tools.
|
*/
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function apiResponse(int $status, array $payload): void
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function apiBase64UrlEncode(string $value): string
{
    return rtrim(
        strtr(base64_encode($value), '+/', '-_'),
        '='
    );
}

function apiCreateToken(array $claims): string
{
    $header = array(
        'alg' => 'HS256',
        'typ' => 'JWT'
    );

    $encodedHeader = apiBase64UrlEncode(
        json_encode($header, JSON_UNESCAPED_SLASHES)
    );

    $encodedPayload = apiBase64UrlEncode(
        json_encode($claims, JSON_UNESCAPED_SLASHES)
    );

    $signature = hash_hmac(
        'sha256',
        $encodedHeader . '.' . $encodedPayload,
        FIELDPLX_API_SECRET,
        true
    );

    return $encodedHeader . '.' .
        $encodedPayload . '.' .
        apiBase64UrlEncode($signature);
}

function apiReadJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        apiResponse(
            400,
            array(
                'success' => false,
                'code' => 'invalid_json',
                'message' => 'Request body must contain valid JSON.'
            )
        );
    }

    return $data;
}

function apiString(array $data, string $key): string
{
    if (!isset($data[$key]) || is_array($data[$key])) {
        return '';
    }

    return trim((string) $data[$key]);
}

function apiClientIp(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }

    return isset($_SERVER['REMOTE_ADDR'])
        ? (string) $_SERVER['REMOTE_ADDR']
        : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(
        405,
        array(
            'success' => false,
            'code' => 'method_not_allowed',
            'message' => 'Only POST requests are allowed.'
        )
    );
}

$body = apiReadJsonBody();

$identifier  = apiString($body, 'identifier');
$password    = apiString($body, 'password');
$workspace   = apiString($body, 'workspace');
$platform    = strtolower(apiString($body, 'platform'));
$deviceToken = apiString($body, 'device_token');
$deviceName  = apiString($body, 'device_name');

if ($identifier === '' || $password === '') {
    apiResponse(
        422,
        array(
            'success' => false,
            'code' => 'validation_error',
            'message' => 'Identifier and password are required.'
        )
    );
}

if (
    $platform !== '' &&
    !in_array($platform, array('ios', 'android', 'web'), true)
) {
    apiResponse(
        422,
        array(
            'success' => false,
            'code' => 'invalid_platform',
            'message' => 'Platform must be ios, android, or web.'
        )
    );
}

try {
    /*
    |--------------------------------------------------------------------------
    | Resolve the login account
    |--------------------------------------------------------------------------
    |
    | Final FieldPlx schema stores tenant users in `users` and workspace
    | details in `tenants`. The role is read from `roles`.
    |
    */
    $sql = "
        SELECT
            u.id AS user_id,
            u.tenant_id,
            u.role_id,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            u.password_hash,
            u.avatar_path,
            u.job_title,
            u.employee_code,
            u.is_bookable,
            u.is_field_worker,
            u.status AS user_status,
            u.deleted_at AS user_deleted_at,

            t.company_name,
            t.slug AS company_slug,
            t.business_type,
            t.email AS company_email,
            t.phone AS company_phone,
            t.website AS company_website,
            t.logo_path AS company_logo,
            t.timezone,
            t.currency_code,
            t.date_format,
            t.status AS tenant_status,
            t.trial_ends_at,
            t.subscription_plan,
            t.deleted_at AS tenant_deleted_at,

            r.name AS role_name,
            r.code AS role_code,
            r.is_active AS role_is_active

        FROM users u

        INNER JOIN tenants t
            ON t.id = u.tenant_id

        LEFT JOIN roles r
            ON r.id = u.role_id
           AND (
                r.tenant_id = u.tenant_id
                OR r.tenant_id IS NULL
           )

        WHERE LOWER(u.email) = LOWER(?)
    ";

    $types  = 's';
    $params = array($identifier);

    if ($workspace !== '') {
        $sql .= " AND LOWER(t.slug) = LOWER(?) ";
        $types .= 's';
        $params[] = $workspace;
    }

    $sql .= " ORDER BY u.id ASC LIMIT 2 ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    if ($workspace !== '') {
        $stmt->bind_param(
            $types,
            $params[0],
            $params[1]
        );
    } else {
        $stmt->bind_param(
            $types,
            $params[0]
        );
    }

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $result = $stmt->get_result();

    $rows = array();

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    if (count($rows) === 0) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'invalid_credentials',
                'message' => 'Invalid email or password.'
            )
        );
    }

    /*
     * If one email exists in multiple workspaces and no workspace code was
     * supplied, do not silently choose the wrong tenant.
     */
    if ($workspace === '' && count($rows) > 1) {
        apiResponse(
            409,
            array(
                'success' => false,
                'code' => 'workspace_required',
                'message' => 'This email belongs to multiple workspaces. Enter the workspace code.'
            )
        );
    }

    $user = $rows[0];

    if (
        !password_verify(
            $password,
            (string) $user['password_hash']
        )
    ) {
        apiResponse(
            401,
            array(
                'success' => false,
                'code' => 'invalid_credentials',
                'message' => 'Invalid email or password.'
            )
        );
    }

    if (!empty($user['user_deleted_at'])) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'user_unavailable',
                'message' => 'This user account is unavailable.'
            )
        );
    }

    if (
        in_array(
            strtolower((string) $user['user_status']),
            array('inactive', 'invited', 'suspended'),
            true
        )
    ) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'user_inactive',
                'message' => 'This user account is not active.'
            )
        );
    }

    if (!empty($user['tenant_deleted_at'])) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'workspace_unavailable',
                'message' => 'This workspace is unavailable.'
            )
        );
    }

    $tenantStatus = strtolower(
        (string) $user['tenant_status']
    );

    if (
        in_array(
            $tenantStatus,
            array('inactive', 'suspended'),
            true
        )
    ) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'workspace_inactive',
                'message' => 'This workspace is inactive.'
            )
        );
    }

    if (
        $tenantStatus === 'trial' &&
        !empty($user['trial_ends_at']) &&
        strtotime((string) $user['trial_ends_at']) < time()
    ) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'trial_expired',
                'message' => 'This workspace trial has expired.'
            )
        );
    }

    if (
        !empty($user['role_id']) &&
        isset($user['role_is_active']) &&
        (int) $user['role_is_active'] !== 1
    ) {
        apiResponse(
            403,
            array(
                'success' => false,
                'code' => 'role_inactive',
                'message' => 'The assigned role is inactive.'
            )
        );
    }

    $now = time();
    $expiresAt = $now + FIELDPLX_TOKEN_TTL;

    $claims = array(
        'iss' => 'FieldPlx',
        'aud' => 'FieldPlx-Mobile',
        'iat' => $now,
        'nbf' => $now,
        'exp' => $expiresAt,
        'sub' => (string) $user['user_id'],
        'tenant_id' => (int) $user['tenant_id'],
        'role_id' => (int) $user['role_id'],
        'role_code' => (string) $user['role_code'],
        'email' => (string) $user['email']
    );

    $accessToken = apiCreateToken($claims);

    /*
    |--------------------------------------------------------------------------
    | Update last login time
    |--------------------------------------------------------------------------
    */
    $update = $conn->prepare(
        "UPDATE users
         SET last_login_at = NOW()
         WHERE id = ?
           AND tenant_id = ?
         LIMIT 1"
    );

    if ($update) {
        $userId = (int) $user['user_id'];
        $tenantId = (int) $user['tenant_id'];

        $update->bind_param(
            'ii',
            $userId,
            $tenantId
        );

        $update->execute();
        $update->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Optional device registration
    |--------------------------------------------------------------------------
    |
    | `user_devices.device_token` is treated as the mobile push/device token.
    | It is NOT used as the API access token.
    |
    */
    if (
        $deviceToken !== '' &&
        $platform !== ''
    ) {
        $deviceSql = "
            INSERT INTO user_devices
            (
                tenant_id,
                user_id,
                platform,
                device_token,
                device_name,
                is_active,
                last_seen_at
            )
            VALUES (?, ?, ?, ?, ?, 1, NOW())

            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                platform = VALUES(platform),
                device_name = VALUES(device_name),
                is_active = 1,
                last_seen_at = NOW()
        ";

        $deviceStmt = $conn->prepare($deviceSql);

        if ($deviceStmt) {
            $tenantId = (int) $user['tenant_id'];
            $userId = (int) $user['user_id'];

            $deviceStmt->bind_param(
                'iisss',
                $tenantId,
                $userId,
                $platform,
                $deviceToken,
                $deviceName
            );

            $deviceStmt->execute();
            $deviceStmt->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Activity event
    |--------------------------------------------------------------------------
    */
    $activityStmt = $conn->prepare(
        "INSERT INTO activity_events
        (
            tenant_id,
            actor_user_id,
            actor_type,
            event_type,
            related_type,
            related_id,
            title,
            details_json,
            visible_to_client,
            created_at
        )
        VALUES (?, ?, 'user', 'mobile_login', 'user', ?, ?, ?, 0, NOW())"
    );

    if ($activityStmt) {
        $tenantId = (int) $user['tenant_id'];
        $userId = (int) $user['user_id'];

        $activityTitle = 'Mobile login: ' .
            trim(
                (string) $user['first_name'] . ' ' .
                (string) $user['last_name']
            );

        $details = json_encode(
            array(
                'platform' => $platform,
                'device_name' => $deviceName,
                'ip_address' => apiClientIp()
            ),
            JSON_UNESCAPED_SLASHES
        );

        $activityStmt->bind_param(
            'iiiss',
            $tenantId,
            $userId,
            $userId,
            $activityTitle,
            $details
        );

        $activityStmt->execute();
        $activityStmt->close();
    }

    $fullName = trim(
        (string) $user['first_name'] . ' ' .
        (string) $user['last_name']
    );

    apiResponse(
        200,
        array(
            'success' => true,
            'message' => 'Login successful.',
            'data' => array(
                'token_type' => 'Bearer',
                'access_token' => $accessToken,
                'expires_in' => FIELDPLX_TOKEN_TTL,
                'expires_at' => gmdate(
                    'Y-m-d\TH:i:s\Z',
                    $expiresAt
                ),

                'user' => array(
                    'id' => (int) $user['user_id'],
                    'tenant_id' => (int) $user['tenant_id'],
                    'role_id' => (int) $user['role_id'],
                    'name' => $fullName,
                    'first_name' => (string) $user['first_name'],
                    'last_name' => (string) $user['last_name'],
                    'email' => (string) $user['email'],
                    'phone' => (string) $user['phone'],
                    'avatar_path' => (string) $user['avatar_path'],
                    'job_title' => (string) $user['job_title'],
                    'employee_code' => (string) $user['employee_code'],
                    'is_bookable' => (bool) $user['is_bookable'],
                    'is_field_worker' => (bool) $user['is_field_worker'],
                    'role' => array(
                        'id' => (int) $user['role_id'],
                        'name' => (string) $user['role_name'],
                        'code' => (string) $user['role_code']
                    )
                ),

                'workspace' => array(
                    'id' => (int) $user['tenant_id'],
                    'company_name' => (string) $user['company_name'],
                    'slug' => (string) $user['company_slug'],
                    'business_type' => (string) $user['business_type'],
                    'email' => (string) $user['company_email'],
                    'phone' => (string) $user['company_phone'],
                    'website' => (string) $user['company_website'],
                    'logo_path' => (string) $user['company_logo'],
                    'timezone' => (string) $user['timezone'],
                    'currency_code' => (string) $user['currency_code'],
                    'date_format' => (string) $user['date_format'],
                    'status' => (string) $user['tenant_status'],
                    'subscription_plan' => (string) $user['subscription_plan']
                )
            )
        )
    );
} catch (Throwable $exception) {
    error_log(
        'FieldPlx mobile login API error: ' .
        $exception->getMessage()
    );

    apiResponse(
        500,
        array(
            'success' => false,
            'code' => 'server_error',
            'message' => 'Unable to complete login.'
        )
    );
}
