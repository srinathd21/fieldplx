<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Master Controls - Tenant SMTP
 *
 * GET    smtp.php
 * GET    smtp.php?id=1
 * POST   smtp.php
 * PUT    smtp.php?id=1
 * PATCH  smtp.php?id=1
 * POST   smtp.php?action=test
 * DELETE smtp.php?id=1
 *
 * SMTP passwords are encrypted at rest and are never returned.
 */

/*
 * Standalone endpoint: this file intentionally contains its own
 * authentication, token validation, tenant scoping, request helpers,
 * activity/audit helpers and module logic.
 * It does NOT require _common.php or any other mobile API helper file.
 */

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api-config.php';

if (file_exists(__DIR__ . '/../../includes/audit.php')) {
    require_once __DIR__ . '/../../includes/audit.php';
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (
    !defined('FIELDPLX_API_SECRET') ||
    trim((string)FIELDPLX_API_SECRET) === ''
) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => array(
            'code' => 'api_secret_not_configured',
            'message' => 'FieldPlx mobile API configuration is incomplete.'
        )
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!defined('FIELDPLX_TOKEN_ISSUER')) {
    define('FIELDPLX_TOKEN_ISSUER', 'FieldPlx');
}

if (!defined('FIELDPLX_TOKEN_AUDIENCE')) {
    define('FIELDPLX_TOKEN_AUDIENCE', 'FieldPlx-Mobile');
}

function mcapi_response($statusCode, $success, $message, $data = null, $errorCode = null)
{
    http_response_code((int)$statusCode);

    if ($errorCode !== null) {
        $payload = array(
            'success' => false,
            'error' => array(
                'code' => (string)$errorCode,
                'message' => (string)$message
            )
        );

        if ($data !== null) {
            $payload['data'] = $data;
        }
    } else {
        $payload = array(
            'success' => (bool)$success,
            'message' => (string)$message
        );

        if ($data !== null) {
            $payload['data'] = $data;
        }
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function mcapi_base64url_decode($data)
{
    $data = (string)$data;
    $remainder = strlen($data) % 4;

    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(strtr($data, '-_', '+/'), true);
}

function mcapi_authorization_header()
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strtolower((string)$name) === 'authorization') {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function mcapi_bearer_token()
{
    $header = mcapi_authorization_header();

    if (
        $header === '' ||
        !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)
    ) {
        return '';
    }

    return trim((string)$matches[1]);
}

function mcapi_verify_token($token)
{
    $parts = explode('.', (string)$token);

    if (count($parts) !== 3) {
        mcapi_response(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    $headerEncoded = $parts[0];
    $payloadEncoded = $parts[1];
    $signatureEncoded = $parts[2];

    $headerJson = mcapi_base64url_decode($headerEncoded);
    $payloadJson = mcapi_base64url_decode($payloadEncoded);
    $signature = mcapi_base64url_decode($signatureEncoded);

    if (
        $headerJson === false ||
        $payloadJson === false ||
        $signature === false
    ) {
        mcapi_response(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        mcapi_response(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    if (
        ($header['alg'] ?? '') !== 'HS256' ||
        ($header['typ'] ?? '') !== 'JWT'
    ) {
        mcapi_response(401, false, 'Unsupported access token.', null, 'invalid_token');
    }

    $expectedSignature = hash_hmac(
        'sha256',
        $headerEncoded . '.' . $payloadEncoded,
        FIELDPLX_API_SECRET,
        true
    );

    if (!hash_equals($expectedSignature, $signature)) {
        mcapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_signature'
        );
    }

    $now = time();

    if (isset($payload['nbf']) && (int)$payload['nbf'] > $now) {
        mcapi_response(
            401,
            false,
            'Access token is not active yet.',
            null,
            'token_not_active'
        );
    }

    if (!isset($payload['exp']) || (int)$payload['exp'] <= $now) {
        mcapi_response(
            401,
            false,
            'Access token has expired. Please sign in again.',
            null,
            'token_expired'
        );
    }

    if (
        isset($payload['iss']) &&
        (string)$payload['iss'] !== (string)FIELDPLX_TOKEN_ISSUER
    ) {
        mcapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_issuer'
        );
    }

    if (
        isset($payload['aud']) &&
        (string)$payload['aud'] !== (string)FIELDPLX_TOKEN_AUDIENCE
    ) {
        mcapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_audience'
        );
    }

    if (empty($payload['user_id']) || empty($payload['tenant_id'])) {
        mcapi_response(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_context'
        );
    }

    return $payload;
}

function mcapi_table_exists($pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");

    $stmt->execute(array(
        ':table_name' => (string)$table
    ));

    $cache[$table] = ((int)$stmt->fetchColumn() > 0);

    return $cache[$table];
}

function mcapi_require_auth($pdo)
{
    $token = mcapi_bearer_token();

    if ($token === '') {
        mcapi_response(
            401,
            false,
            'Authorization Bearer token is required.',
            null,
            'authorization_required'
        );
    }

    $claims = mcapi_verify_token($token);

    $userId = (int)$claims['user_id'];
    $tenantId = (int)$claims['tenant_id'];

    $stmt = $pdo->prepare("
        SELECT
            u.id AS user_id,
            u.tenant_id,
            u.branch_id,
            u.department_id,
            u.role_id,
            u.first_name,
            u.last_name,
            u.email,
            u.is_tenant_admin,
            u.status AS user_status,

            t.tenant_code,
            t.display_name AS tenant_name,
            t.status AS tenant_status,

            b.status AS branch_status,
            d.status AS department_status,
            r.status AS role_status,
            r.is_admin AS role_is_admin,
            r.code AS role_code

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

        WHERE u.id = :user_id
          AND u.tenant_id = :tenant_id
          AND u.deleted_at IS NULL

        LIMIT 1
    ");

    $stmt->execute(array(
        ':user_id' => $userId,
        ':tenant_id' => $tenantId
    ));

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        mcapi_response(
            401,
            false,
            'Authenticated user account was not found.',
            null,
            'user_not_found'
        );
    }

    if ((string)$user['user_status'] !== 'active') {
        mcapi_response(
            403,
            false,
            'Your user account is not active.',
            null,
            'user_not_active'
        );
    }

    if (
        !in_array(
            (string)$user['tenant_status'],
            array('trial', 'active'),
            true
        )
    ) {
        mcapi_response(
            403,
            false,
            'This business account is not active.',
            null,
            'tenant_not_active'
        );
    }

    if (
        !empty($user['branch_id']) &&
        !empty($user['branch_status']) &&
        (string)$user['branch_status'] !== 'active'
    ) {
        mcapi_response(
            403,
            false,
            'Your assigned branch is not active.',
            null,
            'branch_not_active'
        );
    }

    if (
        !empty($user['department_id']) &&
        !empty($user['department_status']) &&
        (string)$user['department_status'] !== 'active'
    ) {
        mcapi_response(
            403,
            false,
            'Your assigned department is not active.',
            null,
            'department_not_active'
        );
    }

    if (
        !empty($user['role_id']) &&
        !empty($user['role_status']) &&
        (string)$user['role_status'] !== 'active'
    ) {
        mcapi_response(
            403,
            false,
            'Your assigned role is not active.',
            null,
            'role_not_active'
        );
    }

    if (mcapi_table_exists($pdo, 'subscriptions')) {
        $sub = $pdo->prepare("
            SELECT id, plan_id, status, expiry_date
            FROM subscriptions
            WHERE tenant_id = :tenant_id
            ORDER BY
                CASE status
                    WHEN 'active' THEN 1
                    WHEN 'trial' THEN 2
                    WHEN 'suspended' THEN 3
                    WHEN 'expired' THEN 4
                    ELSE 5
                END,
                id DESC
            LIMIT 1
        ");

        $sub->execute(array(
            ':tenant_id' => $tenantId
        ));

        $subscription = $sub->fetch(PDO::FETCH_ASSOC);

        if ($subscription) {
            if (
                !in_array(
                    (string)$subscription['status'],
                    array('trial', 'active'),
                    true
                )
            ) {
                mcapi_response(
                    403,
                    false,
                    'Your subscription is not active.',
                    null,
                    'subscription_not_active'
                );
            }

            if (
                !empty($subscription['expiry_date']) &&
                (string)$subscription['expiry_date'] < date('Y-m-d')
            ) {
                mcapi_response(
                    403,
                    false,
                    'Your subscription has expired.',
                    null,
                    'subscription_expired'
                );
            }
        }
    }

    return array(
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'branch_id' => !empty($user['branch_id']) ? (int)$user['branch_id'] : 0,
        'department_id' => !empty($user['department_id']) ? (int)$user['department_id'] : 0,
        'role_id' => !empty($user['role_id']) ? (int)$user['role_id'] : 0,
        'is_tenant_admin' => !empty($user['is_tenant_admin']),
        'role_is_admin' => !empty($user['role_is_admin']),
        'role_code' => (string)($user['role_code'] ?? ''),
        'user' => $user,
        'claims' => $claims
    );
}

function mcapi_input()
{
    static $input = null;

    if (is_array($input)) {
        return $input;
    }

    if (!empty($_POST) && is_array($_POST)) {
        $input = $_POST;
        return $input;
    }

    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $input = $decoded;
            return $input;
        }

        parse_str($raw, $parsed);

        if (is_array($parsed) && !empty($parsed)) {
            $input = $parsed;
            return $input;
        }

        mcapi_response(
            400,
            false,
            'Invalid request body.',
            null,
            'invalid_request_body'
        );
    }

    $input = array();
    return $input;
}

function mcapi_value($key, $default = null)
{
    $input = mcapi_input();

    if (array_key_exists($key, $input)) {
        return $input[$key];
    }

    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    return $default;
}

function mcapi_string($key, $default = '')
{
    return trim((string)mcapi_value($key, $default));
}

function mcapi_int($key, $default = 0)
{
    return (int)mcapi_value($key, $default);
}

function mcapi_bool_value($value, $default = false)
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return (bool)$default;
    }

    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 1;
    }

    $normalized = strtolower(trim((string)$value));

    if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
        return true;
    }

    if (in_array($normalized, array('0', 'false', 'no', 'off'), true)) {
        return false;
    }

    return (bool)$default;
}

function mcapi_bool($key, $default = false)
{
    $input = mcapi_input();

    if (!array_key_exists($key, $input) && !isset($_GET[$key])) {
        return (bool)$default;
    }

    return mcapi_bool_value(mcapi_value($key, null), $default);
}

function mcapi_fetch_row($pdo, $table, $tenantId, $id)
{
    $allowed = array(
        'branches',
        'departments',
        'smtp_configurations',
        'document_sequences',
        'product_services'
    );

    if (!in_array($table, $allowed, true)) {
        return null;
    }

    $sql = "SELECT * FROM " . $table . " WHERE id = :id";

    if ($table === 'smtp_configurations') {
        $sql .= " AND tenant_id = :tenant_id
                  AND scope_type IN ('tenant','branch')";
    } elseif ($table === 'product_services') {
        $sql .= " AND tenant_id = :tenant_id
                  AND item_type = 'service'
                  AND deleted_at IS NULL";
    } else {
        $sql .= " AND tenant_id = :tenant_id";
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':id' => (int)$id,
        ':tenant_id' => (int)$tenantId
    ));

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mcapi_branch_exists($pdo, $tenantId, $branchId)
{
    if ((int)$branchId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM branches
        WHERE id = :id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");

    $stmt->execute(array(
        ':id' => (int)$branchId,
        ':tenant_id' => (int)$tenantId
    ));

    return (bool)$stmt->fetchColumn();
}

function mcapi_json($value)
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return $json === false ? null : $json;
}

function mcapi_activity(
    $pdo,
    $tenantId,
    $branchId,
    $userId,
    $eventType,
    $relatedType,
    $relatedId,
    $title,
    $details
) {
    try {
        if (!mcapi_table_exists($pdo, 'activity_events')) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO activity_events (
                tenant_id,
                branch_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                title,
                details_json,
                visible_to_client
            ) VALUES (
                :tenant_id,
                :branch_id,
                :actor_user_id,
                'user',
                :event_type,
                :related_type,
                :related_id,
                :title,
                :details_json,
                0
            )
        ");

        $stmt->execute(array(
            ':tenant_id' => (int)$tenantId,
            ':branch_id' => (int)$branchId > 0 ? (int)$branchId : null,
            ':actor_user_id' => (int)$userId,
            ':event_type' => substr((string)$eventType, 0, 120),
            ':related_type' => substr((string)$relatedType, 0, 80),
            ':related_id' => (int)$relatedId > 0 ? (int)$relatedId : null,
            ':title' => substr((string)$title, 0, 255),
            ':details_json' => mcapi_json($details)
        ));
    } catch (Throwable $e) {
        error_log('Mobile master controls activity log: ' . $e->getMessage());
    }
}

function mcapi_audit(
    $pdo,
    $tenantId,
    $branchId,
    $userId,
    $action,
    $objectType,
    $objectId,
    $oldValues,
    $newValues
) {
    try {
        if (function_exists('tenantAuditLog')) {
            tenantAuditLog(
                $pdo,
                $action,
                (int)$tenantId,
                (int)$branchId > 0 ? (int)$branchId : null,
                (int)$userId,
                $objectType,
                (int)$objectId > 0 ? (int)$objectId : null,
                $oldValues,
                $newValues
            );
        }
    } catch (Throwable $e) {
        error_log('Mobile master controls audit log: ' . $e->getMessage());
    }
}

function mcapi_pdo_error($e, $context = '')
{
    error_log(
        'FieldPlx mobile master controls PDO error' .
        ($context !== '' ? ' [' . $context . ']' : '') .
        ': ' .
        $e->getMessage()
    );

    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        mcapi_response(
            409,
            false,
            'A record with the same unique code or configuration already exists.',
            null,
            'duplicate_record'
        );
    }

    mcapi_response(
        500,
        false,
        'Unable to process the master control request.',
        null,
        'database_error'
    );
}

$auth = mcapi_require_auth($pdo);
$tenantId = (int)$auth['tenant_id'];
$userId = (int)$auth['user_id'];
$sessionBranchId = (int)$auth['branch_id'];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(mcapi_string('action', ''));

function mcsmtp_load_secret()
{
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        return true;
    }

    $files = array(
        __DIR__ . '/../../includes/smtp-secret.php',
        __DIR__ . '/../../../includes/smtp-secret.php'
    );

    foreach ($files as $file) {
        if (is_file($file)) {
            try {
                require_once $file;
            } catch (Throwable $e) {
                error_log('SMTP secret loader: ' . $e->getMessage());
            }

            if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
                return true;
            }
        }
    }

    return false;
}

function mcsmtp_key()
{
    $key = '';

    mcsmtp_load_secret();

    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $key = trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY);
    }

    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) {
            $key = trim((string)$env);
        }
    }

    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) {
            $key = trim((string)$env);
        }
    }

    if (
        $key === '' ||
        $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY'
    ) {
        throw new RuntimeException(
            'FIELDPLX_SMTP_ENCRYPTION_KEY is not configured. Configure the same permanent SMTP encryption key used by the web Master Controls.'
        );
    }

    if (strlen($key) < 32) {
        throw new RuntimeException(
            'FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.'
        );
    }

    return hash('sha256', $key, true);
}

function mcsmtp_encrypt($plain)
{
    $plain = (string)$plain;

    if ($plain === '') {
        return null;
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException(
            'OpenSSL extension is required for SMTP password encryption.'
        );
    }

    $iv = random_bytes(16);
    $cipher = openssl_encrypt(
        $plain,
        'AES-256-CBC',
        mcsmtp_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($cipher === false) {
        throw new RuntimeException('Unable to encrypt SMTP password.');
    }

    return 'v1:' . base64_encode($iv . $cipher);
}

function mcsmtp_decrypt($encrypted)
{
    $encrypted = trim((string)$encrypted);

    if ($encrypted === '') {
        return '';
    }

    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException(
            'OpenSSL extension is required for SMTP password decryption.'
        );
    }

    if (strpos($encrypted, 'v1:') !== 0) {
        throw new RuntimeException(
            'This SMTP password was saved with the old encryption format. Re-enter the SMTP password and save it once.'
        );
    }

    $raw = base64_decode(substr($encrypted, 3), true);

    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException(
            'Stored SMTP password is invalid. Re-enter and save the SMTP password.'
        );
    }

    $plain = openssl_decrypt(
        substr($raw, 16),
        'AES-256-CBC',
        mcsmtp_key(),
        OPENSSL_RAW_DATA,
        substr($raw, 0, 16)
    );

    if ($plain === false) {
        throw new RuntimeException(
            'Unable to decrypt SMTP password. Confirm the web and mobile API use the same FIELDPLX_SMTP_ENCRYPTION_KEY.'
        );
    }

    return $plain;
}

function mcsmtp_read($socket)
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return trim($response);
}

function mcsmtp_command($socket, $command, $expected, $label)
{
    if ($command !== null && @fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException(
            'SMTP connection closed while sending ' . $label . '.'
        );
    }

    $response = mcsmtp_read($socket);
    $code = (int)substr($response, 0, 3);

    if (!in_array($code, (array)$expected, true)) {
        $safe = preg_replace('/[\r\n]+/', ' ', $response);
        throw new RuntimeException(
            $label . ' failed (SMTP ' . $code . '): ' . substr($safe, 0, 350)
        );
    }

    return $response;
}

function mcsmtp_header_value($value)
{
    return trim(str_replace(array("\r", "\n"), ' ', (string)$value));
}

function mcsmtp_send_test($config, $password, $recipient)
{
    $host = trim((string)$config['host']);
    $port = (int)$config['port'];
    $encryption = strtolower(trim((string)$config['encryption']));
    $username = trim((string)$config['username']);
    $fromEmail = trim((string)$config['from_email']);
    $fromName = trim((string)$config['from_name']);
    $replyTo = trim((string)$config['reply_to_email']);

    if ($host === '') {
        throw new RuntimeException('SMTP host is empty.');
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('SMTP port is invalid.');
    }

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid test recipient email.');
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            'SMTP From Email must be a valid email address.'
        );
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') .
        $host . ':' . $port;

    $context = stream_context_create(array(
        'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host
        )
    ));

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException(
            'Unable to connect to SMTP server: ' .
            ($errstr !== '' ? $errstr : 'connection failed') .
            ' (' . $errno . ').'
        );
    }

    stream_set_timeout($socket, 20);

    try {
        mcsmtp_command($socket, null, array(220), 'SMTP greeting');

        $ehloHost = !empty($_SERVER['SERVER_NAME'])
            ? preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME'])
            : 'fieldplx.local';

        if ($ehloHost === '') {
            $ehloHost = 'fieldplx.local';
        }

        mcsmtp_command($socket, 'EHLO ' . $ehloHost, array(250), 'EHLO');

        if ($encryption === 'tls' || $encryption === 'starttls') {
            mcsmtp_command($socket, 'STARTTLS', array(220), 'STARTTLS');

            $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ? STREAM_CRYPTO_METHOD_TLS_CLIENT
                : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;

            if (
                @stream_socket_enable_crypto(
                    $socket,
                    true,
                    $cryptoMethod
                ) !== true
            ) {
                throw new RuntimeException(
                    'Unable to establish TLS encryption with the SMTP server.'
                );
            }

            mcsmtp_command(
                $socket,
                'EHLO ' . $ehloHost,
                array(250),
                'EHLO after TLS'
            );
        }

        if ($username !== '') {
            if ($password === '') {
                throw new RuntimeException('SMTP password is empty.');
            }

            mcsmtp_command($socket, 'AUTH LOGIN', array(334), 'SMTP authentication');
            mcsmtp_command($socket, base64_encode($username), array(334), 'SMTP username');
            mcsmtp_command($socket, base64_encode($password), array(235), 'SMTP password');
        }

        mcsmtp_command(
            $socket,
            'MAIL FROM:<' . $fromEmail . '>',
            array(250),
            'MAIL FROM'
        );
        mcsmtp_command(
            $socket,
            'RCPT TO:<' . $recipient . '>',
            array(250, 251),
            'RCPT TO'
        );
        mcsmtp_command($socket, 'DATA', array(354), 'DATA');

        $displayName = $fromName !== ''
            ? mcsmtp_header_value($fromName)
            : 'FieldPlx';

        $messageIdHost = preg_replace('/[^A-Za-z0-9.\-]/', '', $host);
        if ($messageIdHost === '') {
            $messageIdHost = 'fieldplx.local';
        }

        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $displayName . ' <' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: FieldPlx SMTP Test Email',
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $messageIdHost . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        );

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }

        $body =
            "FieldPlx SMTP Test\r\n\r\n" .
            "This test email confirms that your SMTP configuration is working correctly.\r\n" .
            "Configuration: " . mcsmtp_header_value($config['config_name']) . "\r\n" .
            "SMTP Host: " . mcsmtp_header_value($host) . ":" . $port . "\r\n" .
            "Encryption: " . mcsmtp_header_value($encryption) . "\r\n" .
            "Sent At: " . date('Y-m-d H:i:s') . "\r\n\r\n" .
            "FieldPlx";

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $payload = preg_replace('/(?m)^\./', '..', $payload);

        if (@fwrite($socket, $payload . "\r\n.\r\n") === false) {
            throw new RuntimeException('Unable to send SMTP message data.');
        }

        mcsmtp_command($socket, null, array(250), 'Message delivery');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }

    return true;
}

function mcsmtp_safe_row($row)
{
    if (!is_array($row)) {
        return $row;
    }

    $row['password_configured'] = !empty($row['password_encrypted']);
    unset($row['password_encrypted']);

    return $row;
}

try {
    if ($method === 'GET') {
        $id = mcapi_int('id', 0);

        $sql = "
            SELECT
                s.id,
                s.scope_type,
                s.tenant_id,
                s.branch_id,
                s.config_name,
                s.host,
                s.port,
                s.encryption,
                s.username,
                s.from_name,
                s.from_email,
                s.reply_to_email,
                s.is_default,
                s.is_active,
                s.last_test_status,
                s.last_test_message,
                s.last_tested_at,
                s.created_at,
                s.updated_at,
                CASE
                    WHEN s.password_encrypted IS NOT NULL
                     AND s.password_encrypted <> ''
                    THEN 1 ELSE 0
                END AS password_configured,
                b.name AS branch_name
            FROM smtp_configurations s
            LEFT JOIN branches b
                ON b.id = s.branch_id
               AND b.tenant_id = s.tenant_id
            WHERE s.tenant_id = :tenant_id
              AND s.scope_type IN ('tenant','branch')
        ";

        if ($id > 0) {
            $stmt = $pdo->prepare($sql . " AND s.id = :id LIMIT 1");
            $stmt->execute(array(
                ':tenant_id' => $tenantId,
                ':id' => $id
            ));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                mcapi_response(
                    404,
                    false,
                    'SMTP configuration not found.',
                    null,
                    'smtp_not_found'
                );
            }

            mcapi_response(
                200,
                true,
                'SMTP configuration loaded successfully.',
                array('smtp' => $row)
            );
        }

        $stmt = $pdo->prepare(
            $sql . " ORDER BY s.is_default DESC, s.config_name ASC"
        );
        $stmt->execute(array(':tenant_id' => $tenantId));

        $branchStmt = $pdo->prepare("
            SELECT id, branch_code, name, status, is_head_office
            FROM branches
            WHERE tenant_id = :tenant_id
            ORDER BY is_head_office DESC, name ASC
        ");
        $branchStmt->execute(array(':tenant_id' => $tenantId));

        mcapi_response(
            200,
            true,
            'SMTP configurations loaded successfully.',
            array(
                'smtp' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'meta' => array(
                    'branches' => $branchStmt->fetchAll(PDO::FETCH_ASSOC),
                    'scope_options' => array('tenant', 'branch'),
                    'encryption_options' => array('none', 'ssl', 'tls', 'starttls')
                )
            )
        );
    }

    if ($method === 'POST' && $action === 'test') {
        $id = mcapi_int('id', 0);
        $recipient = mcapi_string('test_email', '');

        if ($id <= 0) {
            mcapi_response(
                422,
                false,
                'SMTP configuration id is required.',
                null,
                'smtp_id_required'
            );
        }

        $smtp = mcapi_fetch_row($pdo, 'smtp_configurations', $tenantId, $id);

        if (!$smtp) {
            mcapi_response(
                404,
                false,
                'SMTP configuration not found.',
                null,
                'smtp_not_found'
            );
        }

        if ((int)$smtp['is_active'] !== 1) {
            mcapi_response(
                409,
                false,
                'Activate this SMTP configuration before testing it.',
                null,
                'smtp_not_active'
            );
        }

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            mcapi_response(
                422,
                false,
                'Enter a valid test recipient email address.',
                null,
                'validation_error'
            );
        }

        $testStatus = 'failed';
        $testMessage = '';

        try {
            $password = mcsmtp_decrypt($smtp['password_encrypted']);
            mcsmtp_send_test($smtp, $password, $recipient);
            $testStatus = 'success';
            $testMessage = 'Test email sent successfully to ' . $recipient . '.';
        } catch (Throwable $testError) {
            $testMessage = substr($testError->getMessage(), 0, 2000);
        }

        $update = $pdo->prepare("
            UPDATE smtp_configurations
            SET
                last_test_status = :status,
                last_test_message = :message,
                last_tested_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND scope_type IN ('tenant','branch')
        ");
        $update->execute(array(
            ':status' => $testStatus,
            ':message' => $testMessage,
            ':id' => $id,
            ':tenant_id' => $tenantId
        ));

        mcapi_activity(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            $testStatus === 'success' ? 'smtp_test_success' : 'smtp_test_failed',
            'smtp_configuration',
            $id,
            ($testStatus === 'success'
                ? 'SMTP test succeeded: '
                : 'SMTP test failed: ') . $smtp['config_name'],
            array(
                'recipient' => $recipient,
                'status' => $testStatus,
                'message' => $testMessage
            )
        );

        mcapi_audit(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            $testStatus === 'success' ? 'SMTP_TEST_SUCCESS' : 'SMTP_TEST_FAILED',
            'smtp_configuration',
            $id,
            null,
            array(
                'recipient' => $recipient,
                'status' => $testStatus,
                'message' => $testMessage
            )
        );

        if ($testStatus !== 'success') {
            mcapi_response(
                422,
                false,
                'SMTP test failed: ' . $testMessage,
                array(
                    'test_status' => $testStatus,
                    'test_message' => $testMessage
                )
            );
        }

        mcapi_response(
            200,
            true,
            $testMessage,
            array(
                'test_status' => $testStatus,
                'test_message' => $testMessage
            )
        );
    }

    if (in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
        $id = mcapi_int('id', 0);

        if ($method !== 'POST' && $id <= 0) {
            mcapi_response(
                422,
                false,
                'SMTP configuration id is required for update.',
                null,
                'smtp_id_required'
            );
        }

        $old = $id > 0
            ? mcapi_fetch_row($pdo, 'smtp_configurations', $tenantId, $id)
            : null;

        if ($id > 0 && !$old) {
            mcapi_response(
                404,
                false,
                'SMTP configuration not found.',
                null,
                'smtp_not_found'
            );
        }

        $scope = mcapi_string('scope_type', 'tenant');
        $branchId = mcapi_int('branch_id', 0);
        $name = mcapi_string('config_name', '');
        $host = mcapi_string('host', '');
        $port = mcapi_int('port', 587);
        $encryption = mcapi_string('encryption', 'tls');
        $password = (string)mcapi_value('password', '');
        $changePassword = mcapi_bool('change_password', false);
        $isDefault = mcapi_bool('is_default', false) ? 1 : 0;
        $isActive = mcapi_bool('is_active', true) ? 1 : 0;

        if (!in_array($scope, array('tenant', 'branch'), true)) {
            mcapi_response(
                422,
                false,
                'Invalid SMTP scope.',
                null,
                'validation_error'
            );
        }

        if ($name === '' || $host === '') {
            mcapi_response(
                422,
                false,
                'SMTP configuration name and host are required.',
                null,
                'validation_error'
            );
        }

        if ($port < 1 || $port > 65535) {
            mcapi_response(
                422,
                false,
                'SMTP port is invalid.',
                null,
                'validation_error'
            );
        }

        if (!in_array($encryption, array('none', 'ssl', 'tls', 'starttls'), true)) {
            mcapi_response(
                422,
                false,
                'Invalid SMTP encryption.',
                null,
                'validation_error'
            );
        }

        if ($scope === 'branch') {
            if (
                $branchId <= 0 ||
                !mcapi_branch_exists($pdo, $tenantId, $branchId)
            ) {
                mcapi_response(
                    422,
                    false,
                    $branchId <= 0
                        ? 'Select a branch for branch SMTP.'
                        : 'Selected branch is invalid.',
                    null,
                    'invalid_branch'
                );
            }
        } else {
            $branchId = 0;
        }

        $passwordEncrypted = null;

        if ($id > 0) {
            if ($changePassword) {
                if (trim($password) === '') {
                    mcapi_response(
                        422,
                        false,
                        'Enter the new SMTP password.',
                        null,
                        'validation_error'
                    );
                }

                $passwordEncrypted = mcsmtp_encrypt($password);
            }
        } else {
            if (trim($password) === '') {
                mcapi_response(
                    422,
                    false,
                    'SMTP password is required for a new configuration.',
                    null,
                    'validation_error'
                );
            }

            $passwordEncrypted = mcsmtp_encrypt($password);
        }

        $pdo->beginTransaction();

        try {
            if ($isDefault === 1) {
                $clear = $pdo->prepare("
                    UPDATE smtp_configurations
                    SET is_default = 0
                    WHERE tenant_id = :tenant_id
                      AND scope_type IN ('tenant','branch')
                ");
                $clear->execute(array(':tenant_id' => $tenantId));
            }

            if ($id > 0) {
                $sql = "
                    UPDATE smtp_configurations
                    SET
                        scope_type = :scope_type,
                        branch_id = :branch_id,
                        config_name = :config_name,
                        host = :host,
                        port = :port,
                        encryption = :encryption,
                        username = :username,
                        from_name = :from_name,
                        from_email = :from_email,
                        reply_to_email = :reply_to_email,
                        is_default = :is_default,
                        is_active = :is_active
                ";

                $params = array(
                    ':scope_type' => $scope,
                    ':branch_id' => $branchId > 0 ? $branchId : null,
                    ':config_name' => $name,
                    ':host' => $host,
                    ':port' => $port,
                    ':encryption' => $encryption,
                    ':username' => mcapi_string('username', '') ?: null,
                    ':from_name' => mcapi_string('from_name', '') ?: null,
                    ':from_email' => mcapi_string('from_email', '') ?: null,
                    ':reply_to_email' => mcapi_string('reply_to_email', '') ?: null,
                    ':is_default' => $isDefault,
                    ':is_active' => $isActive,
                    ':id' => $id,
                    ':tenant_id' => $tenantId
                );

                if ($passwordEncrypted !== null) {
                    $sql .= ", password_encrypted = :password_encrypted";
                    $params[':password_encrypted'] = $passwordEncrypted;
                }

                $sql .= "
                    WHERE id = :id
                      AND tenant_id = :tenant_id
                      AND scope_type IN ('tenant','branch')
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO smtp_configurations (
                        scope_type,
                        tenant_id,
                        branch_id,
                        config_name,
                        host,
                        port,
                        encryption,
                        username,
                        password_encrypted,
                        from_name,
                        from_email,
                        reply_to_email,
                        is_default,
                        is_active,
                        created_by_tenant_user_id
                    ) VALUES (
                        :scope_type,
                        :tenant_id,
                        :branch_id,
                        :config_name,
                        :host,
                        :port,
                        :encryption,
                        :username,
                        :password_encrypted,
                        :from_name,
                        :from_email,
                        :reply_to_email,
                        :is_default,
                        :is_active,
                        :created_by
                    )
                ");

                $stmt->execute(array(
                    ':scope_type' => $scope,
                    ':tenant_id' => $tenantId,
                    ':branch_id' => $branchId > 0 ? $branchId : null,
                    ':config_name' => $name,
                    ':host' => $host,
                    ':port' => $port,
                    ':encryption' => $encryption,
                    ':username' => mcapi_string('username', '') ?: null,
                    ':password_encrypted' => $passwordEncrypted,
                    ':from_name' => mcapi_string('from_name', '') ?: null,
                    ':from_email' => mcapi_string('from_email', '') ?: null,
                    ':reply_to_email' => mcapi_string('reply_to_email', '') ?: null,
                    ':is_default' => $isDefault,
                    ':is_active' => $isActive,
                    ':created_by' => $userId
                ));

                $id = (int)$pdo->lastInsertId();
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $new = mcapi_fetch_row($pdo, 'smtp_configurations', $tenantId, $id);
        $oldSafe = $old ? mcsmtp_safe_row($old) : null;
        $newSafe = mcsmtp_safe_row($new);

        mcapi_activity(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            $old ? 'smtp_updated' : 'smtp_created',
            'smtp_configuration',
            $id,
            $old ? 'SMTP updated: ' . $name : 'SMTP created: ' . $name,
            $newSafe
        );

        mcapi_audit(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            $old ? 'SMTP_UPDATED' : 'SMTP_CREATED',
            'smtp_configuration',
            $id,
            $oldSafe,
            $newSafe
        );

        mcapi_response(
            200,
            true,
            $old
                ? 'SMTP configuration updated successfully.'
                : 'SMTP configuration created successfully.',
            array('smtp' => $newSafe)
        );
    }

    if ($method === 'DELETE') {
        $id = mcapi_int('id', 0);

        if ($id <= 0) {
            mcapi_response(
                422,
                false,
                'SMTP configuration id is required.',
                null,
                'smtp_id_required'
            );
        }

        $old = mcapi_fetch_row($pdo, 'smtp_configurations', $tenantId, $id);

        if (!$old) {
            mcapi_response(
                404,
                false,
                'SMTP configuration not found.',
                null,
                'smtp_not_found'
            );
        }

        $stmt = $pdo->prepare("
            DELETE FROM smtp_configurations
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND scope_type IN ('tenant','branch')
        ");
        $stmt->execute(array(
            ':id' => $id,
            ':tenant_id' => $tenantId
        ));

        $oldSafe = mcsmtp_safe_row($old);

        mcapi_activity(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'smtp_deleted',
            'smtp_configuration',
            $id,
            'SMTP deleted: ' . $old['config_name'],
            $oldSafe
        );

        mcapi_audit(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'SMTP_DELETED',
            'smtp_configuration',
            $id,
            $oldSafe,
            null
        );

        mcapi_response(
            200,
            true,
            'SMTP configuration deleted successfully.',
            array('deleted_id' => $id)
        );
    }

    mcapi_response(405, false, 'Method not allowed.', null, 'method_not_allowed');
} catch (PDOException $e) {
    error_log('FieldPlx mobile SMTP PDO error: ' . $e->getMessage());

    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        mcapi_response(
            409,
            false,
            'A record with the same unique code or configuration already exists.',
            null,
            'duplicate_record'
        );
    }

    mcapi_response(
        500,
        false,
        'SMTP configuration database update failed.',
        null,
        'database_error'
    );
} catch (Throwable $e) {
    error_log('FieldPlx mobile SMTP API error: ' . $e->getMessage());
    mcapi_response(500, false, $e->getMessage(), null, 'server_error');
}
