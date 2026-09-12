<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Employees API
 *
 * Upload to:
 *   /business/api/mobile/employees.php
 *
 * Production URL:
 *   https://fieldplx.com/business/api/mobile/employees.php
 *
 * Authentication:
 *   Authorization: Bearer <access_token>
 *
 * REST endpoints:
 *   GET    /employees.php
 *   GET    /employees.php?id=5
 *   GET    /employees.php?meta=1
 *   POST   /employees.php
 *   PUT    /employees.php?id=5
 *   PATCH  /employees.php?id=5
 *   DELETE /employees.php?id=5
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

if (!defined('FIELDPLX_API_SECRET') || trim((string) FIELDPLX_API_SECRET) === '') {
    employeeApiResponse(500, false, 'FieldPlx mobile API configuration is incomplete.', null, 'api_secret_not_configured');
}

if (!defined('FIELDPLX_TOKEN_ISSUER')) {
    define('FIELDPLX_TOKEN_ISSUER', 'FieldPlx');
}

if (!defined('FIELDPLX_TOKEN_AUDIENCE')) {
    define('FIELDPLX_TOKEN_AUDIENCE', 'FieldPlx-Mobile');
}

function employeeApiResponse($status, $success, $message, $data = null, $errorCode = null)
{
    http_response_code((int) $status);

    if ($errorCode !== null) {
        $payload = array(
            'success' => false,
            'error' => array(
                'code' => (string) $errorCode,
                'message' => (string) $message,
            ),
        );
        if ($data !== null) {
            $payload['data'] = $data;
        }
    } else {
        $payload = array(
            'success' => (bool) $success,
            'message' => (string) $message,
        );
        if ($data !== null) {
            $payload['data'] = $data;
        }
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function employeeApiBase64UrlDecode($data)
{
    $data = (string) $data;
    $rem = strlen($data) % 4;
    if ($rem) {
        $data .= str_repeat('=', 4 - $rem);
    }
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function employeeApiAuthorizationHeader()
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'authorization') {
                    return trim((string) $value);
                }
            }
        }
    }
    return '';
}

function employeeApiBearerToken()
{
    $header = employeeApiAuthorizationHeader();
    if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return '';
    }
    return trim((string) $m[1]);
}

function employeeApiVerifyToken($token)
{
    $parts = explode('.', (string) $token);
    if (count($parts) !== 3) {
        employeeApiResponse(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    [$h64, $p64, $s64] = $parts;
    $headerJson = employeeApiBase64UrlDecode($h64);
    $payloadJson = employeeApiBase64UrlDecode($p64);
    $signature = employeeApiBase64UrlDecode($s64);

    if ($headerJson === false || $payloadJson === false || $signature === false) {
        employeeApiResponse(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        employeeApiResponse(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    if (($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT') {
        employeeApiResponse(401, false, 'Unsupported access token.', null, 'invalid_token');
    }

    $expected = hash_hmac('sha256', $h64 . '.' . $p64, FIELDPLX_API_SECRET, true);
    if (!hash_equals($expected, $signature)) {
        employeeApiResponse(401, false, 'Invalid access token.', null, 'invalid_token_signature');
    }

    $now = time();
    if (isset($payload['nbf']) && (int) $payload['nbf'] > $now) {
        employeeApiResponse(401, false, 'Access token is not active yet.', null, 'token_not_active');
    }
    if (!isset($payload['exp']) || (int) $payload['exp'] <= $now) {
        employeeApiResponse(401, false, 'Access token has expired. Please sign in again.', null, 'token_expired');
    }
    if (isset($payload['iss']) && (string) $payload['iss'] !== (string) FIELDPLX_TOKEN_ISSUER) {
        employeeApiResponse(401, false, 'Invalid access token.', null, 'invalid_token_issuer');
    }
    if (isset($payload['aud']) && (string) $payload['aud'] !== (string) FIELDPLX_TOKEN_AUDIENCE) {
        employeeApiResponse(401, false, 'Invalid access token.', null, 'invalid_token_audience');
    }
    if (empty($payload['user_id']) || empty($payload['tenant_id'])) {
        employeeApiResponse(401, false, 'Invalid access token.', null, 'invalid_token_context');
    }

    return $payload;
}

function employeeApiTableExists(PDO $pdo, $table)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t' => (string) $table));
    return (int) $q->fetchColumn() > 0;
}

function employeeApiColumnExists(PDO $pdo, $table, $column)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t' => (string) $table, ':c' => (string) $column));
    return (int) $q->fetchColumn() > 0;
}

function employeeApiRequireAuth(PDO $pdo)
{
    $token = employeeApiBearerToken();
    if ($token === '') {
        employeeApiResponse(401, false, 'Authorization Bearer token is required.', null, 'authorization_required');
    }

    $claims = employeeApiVerifyToken($token);
    $uid = (int) $claims['user_id'];
    $tid = (int) $claims['tenant_id'];

    $q = $pdo->prepare(
        "SELECT
            u.id AS user_id,u.tenant_id,u.branch_id,u.department_id,u.role_id,
            u.first_name,u.last_name,u.email,u.is_tenant_admin,u.status AS user_status,
            t.status AS tenant_status,
            b.status AS branch_status,d.status AS department_status,r.status AS role_status
         FROM users u
         INNER JOIN tenants t ON t.id=u.tenant_id AND t.deleted_at IS NULL
         LEFT JOIN branches b ON b.id=u.branch_id AND b.tenant_id=u.tenant_id
         LEFT JOIN departments d ON d.id=u.department_id AND d.tenant_id=u.tenant_id
         LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id
         WHERE u.id=:uid AND u.tenant_id=:tid AND u.deleted_at IS NULL
         LIMIT 1"
    );
    $q->execute(array(':uid' => $uid, ':tid' => $tid));
    $u = $q->fetch(PDO::FETCH_ASSOC);

    if (!$u) employeeApiResponse(401, false, 'Authenticated user account was not found.', null, 'user_not_found');
    if ((string) $u['user_status'] !== 'active') employeeApiResponse(403, false, 'Your user account is not active.', null, 'user_not_active');
    if (!in_array((string) $u['tenant_status'], array('trial', 'active'), true)) employeeApiResponse(403, false, 'This business account is not active.', null, 'tenant_not_active');
    if (!empty($u['branch_id']) && !empty($u['branch_status']) && (string) $u['branch_status'] !== 'active') employeeApiResponse(403, false, 'Your assigned branch is not active.', null, 'branch_not_active');
    if (!empty($u['department_id']) && !empty($u['department_status']) && (string) $u['department_status'] !== 'active') employeeApiResponse(403, false, 'Your assigned department is not active.', null, 'department_not_active');
    if (!empty($u['role_id']) && !empty($u['role_status']) && (string) $u['role_status'] !== 'active') employeeApiResponse(403, false, 'Your assigned role is not active.', null, 'role_not_active');

    return array(
        'user_id' => $uid,
        'tenant_id' => $tid,
        'branch_id' => !empty($u['branch_id']) ? (int) $u['branch_id'] : 0,
        'user' => $u,
    );
}

function employeeApiInput()
{
    static $input = null;
    if (is_array($input)) return $input;
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
        employeeApiResponse(400, false, 'Invalid request body.', null, 'invalid_request_body');
    }
    $input = array();
    return $input;
}

function employeeApiValue($key, $default = null)
{
    $input = employeeApiInput();
    if (array_key_exists($key, $input)) return $input[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    return $default;
}

function employeeApiString($key, $default = '')
{
    return trim((string) employeeApiValue($key, $default));
}

function employeeApiInt($key, $default = 0)
{
    return (int) employeeApiValue($key, $default);
}

function employeeApiBool($value)
{
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return ((int) $value) === 1;
    return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true);
}

function employeeApiJson($value)
{
    $j = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $j === false ? null : $j;
}

function employeeApiGet(PDO $pdo, $tid, $id)
{
    $q = $pdo->prepare(
        "SELECT
            u.id,u.tenant_id,u.branch_id,u.department_id,u.role_id,
            u.employee_code,u.first_name,u.last_name,u.email,u.phone,u.alternate_phone,
            u.avatar_path,u.job_title,u.labor_rate,u.is_bookable,u.is_field_worker,
            u.is_tenant_admin,u.status,u.last_login_at,u.created_at,u.updated_at,
            b.name AS branch_name,b.branch_code,
            d.name AS department_name,d.code AS department_code,
            r.name AS role_name,r.code AS role_code
         FROM users u
         LEFT JOIN branches b ON b.id=u.branch_id AND b.tenant_id=u.tenant_id
         LEFT JOIN departments d ON d.id=u.department_id AND d.tenant_id=u.tenant_id
         LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id
         WHERE u.id=:id AND u.tenant_id=:tid AND u.deleted_at IS NULL
         LIMIT 1"
    );
    $q->execute(array(':id' => (int) $id, ':tid' => (int) $tid));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) employeeApiResponse(404, false, 'Employee not found.', null, 'employee_not_found');
    return $r;
}

function employeeApiMeta(PDO $pdo, $tid)
{
    $b = $pdo->prepare("SELECT id,name,branch_code FROM branches WHERE tenant_id=:tid AND status='active' ORDER BY is_head_office DESC,name");
    $b->execute(array(':tid' => $tid));
    $d = $pdo->prepare("SELECT id,branch_id,name,code FROM departments WHERE tenant_id=:tid AND status='active' ORDER BY name");
    $d->execute(array(':tid' => $tid));
    $r = $pdo->prepare("SELECT id,name,code,is_admin FROM roles WHERE tenant_id=:tid AND status='active' ORDER BY is_admin DESC,name");
    $r->execute(array(':tid' => $tid));
    return array(
        'branches' => $b->fetchAll(PDO::FETCH_ASSOC),
        'departments' => $d->fetchAll(PDO::FETCH_ASSOC),
        'roles' => $r->fetchAll(PDO::FETCH_ASSOC),
    );
}

function employeeApiFk(PDO $pdo, $table, $tid, $id)
{
    if ($id <= 0) return null;
    if (!in_array($table, array('branches', 'departments', 'roles'), true)) return null;
    $q = $pdo->prepare("SELECT id FROM $table WHERE id=:id AND tenant_id=:tid LIMIT 1");
    $q->execute(array(':id' => (int) $id, ':tid' => (int) $tid));
    return $q->fetchColumn() ? (int) $id : null;
}

function employeeApiPlanMaxUsers(PDO $pdo, $tid)
{
    try {
        if (!employeeApiTableExists($pdo, 'subscriptions')) return 0;

        $planId = 0;
        $q = $pdo->prepare("SELECT plan_id FROM subscriptions WHERE tenant_id=:tid AND status IN ('active','trial') ORDER BY id DESC LIMIT 1");
        $q->execute(array(':tid' => $tid));
        $planId = (int) $q->fetchColumn();
        if ($planId <= 0 || !employeeApiTableExists($pdo, 'plans')) return 0;

        foreach (array('max_users', 'user_limit', 'max_employees') as $column) {
            if (employeeApiColumnExists($pdo, 'plans', $column)) {
                $stmt = $pdo->prepare("SELECT `$column` FROM plans WHERE id=:id LIMIT 1");
                $stmt->execute(array(':id' => $planId));
                return max(0, (int) $stmt->fetchColumn());
            }
        }
    } catch (Throwable $e) {
        error_log('Employee plan limit lookup: ' . $e->getMessage());
    }
    return 0;
}

function employeeApiLog(PDO $pdo, $tid, $bid, $uid, $event, $id, $title, $details, $audit, $old, $new)
{
    try {
        if (employeeApiTableExists($pdo, 'activity_events')) {
            $q = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,title,details_json,visible_to_client) VALUES(:tid,:bid,:uid,'user',:event,'employee',:rid,:title,:details,0)");
            $q->execute(array(
                ':tid' => $tid,
                ':bid' => $bid > 0 ? $bid : null,
                ':uid' => $uid,
                ':event' => substr((string) $event, 0, 120),
                ':rid' => $id,
                ':title' => substr((string) $title, 0, 255),
                ':details' => employeeApiJson($details),
            ));
        }
    } catch (Throwable $e) {
        error_log('Employee activity log: ' . $e->getMessage());
    }

    try {
        if (function_exists('tenantAuditLog')) {
            tenantAuditLog($pdo, $audit, $tid, $bid > 0 ? $bid : null, $uid, 'employee', $id, $old, $new);
        } elseif (employeeApiTableExists($pdo, 'audit_logs')) {
            $q = $pdo->prepare("INSERT INTO audit_logs(tenant_id,branch_id,user_id,platform_user_id,action,object_type,object_id,old_values,new_values,ip_address,device_type,user_agent) VALUES(:tid,:bid,:uid,NULL,:action,'employee',:oid,:old,:new,:ip,'mobile',:ua)");
            $q->execute(array(
                ':tid' => $tid,
                ':bid' => $bid > 0 ? $bid : null,
                ':uid' => $uid,
                ':action' => substr((string) $audit, 0, 120),
                ':oid' => $id,
                ':old' => employeeApiJson($old),
                ':new' => employeeApiJson($new),
                ':ip' => isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 80) : null,
                ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            ));
        }
    } catch (Throwable $e) {
        error_log('Employee audit log: ' . $e->getMessage());
    }
}

/* SMTP helpers - same encrypted SMTP model used by current web API. */
function employeeMailLoadSmtpSecretFile()
{
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) return true;
    $candidates = array(
        __DIR__ . '/../../includes/smtp-secret.php',
        __DIR__ . '/../../../includes/smtp-secret.php',
    );
    foreach ($candidates as $file) {
        if (is_file($file)) {
            try {
                require_once $file;
            } catch (Throwable $e) {
                error_log('Employee SMTP secret loader: ' . $e->getMessage());
            }
            if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) return true;
        }
    }
    return false;
}

function employeeMailSmtpSecretKey()
{
    $key = '';
    employeeMailLoadSmtpSecretFile();
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) $key = trim((string) FIELDPLX_SMTP_ENCRYPTION_KEY);
    if ($key === '') {
        $v = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($v !== false) $key = trim((string) $v);
    }
    if ($key === '') {
        $v = getenv('APP_KEY');
        if ($v !== false) $key = trim((string) $v);
    }
    if ($key === '' || $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY') throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.');
    if (strlen($key) < 32) throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.');
    return hash('sha256', $key, true);
}

function employeeMailDecryptPassword($encrypted, $tenantId)
{
    $encrypted = trim((string) $encrypted);
    if ($encrypted === '') return '';
    if (!function_exists('openssl_decrypt')) throw new RuntimeException('OpenSSL extension is required for SMTP password decryption.');
    if (strpos($encrypted, 'v1:') !== 0) throw new RuntimeException('The saved SMTP password uses an old encryption format. Re-enter and save the SMTP password in Master Controls.');
    $raw = base64_decode(substr($encrypted, 3), true);
    if ($raw === false || strlen($raw) <= 16) throw new RuntimeException('The stored SMTP password is invalid.');
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', employeeMailSmtpSecretKey(), OPENSSL_RAW_DATA, $iv);
    if ($plain === false) throw new RuntimeException('Unable to decrypt the SMTP password. Confirm the server uses the same SMTP encryption key used when the configuration was saved.');
    return $plain;
}

function employeeMailRead($socket)
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) break;
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return trim($response);
}

function employeeMailCommand($socket, $command, $expected, $label)
{
    if ($command !== null && @fwrite($socket, $command . "\r\n") === false) throw new RuntimeException('SMTP connection closed while sending ' . $label . '.');
    $response = employeeMailRead($socket);
    $code = (int) substr((string) $response, 0, 3);
    if (!in_array($code, (array) $expected, true)) {
        $safe = preg_replace('/[\r\n]+/', ' ', (string) $response);
        throw new RuntimeException($label . ' failed (SMTP ' . $code . '): ' . substr($safe, 0, 350));
    }
    return $response;
}

function employeeMailHeaderValue($value)
{
    return trim(str_replace(array("\r", "\n"), ' ', (string) $value));
}

function employeeMailEncodedHeader($value)
{
    $value = employeeMailHeaderValue($value);
    return $value === '' ? '' : '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function employeeMailEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function employeeMailFindConfig(PDO $pdo, $tenantId, $branchId)
{
    if (!employeeApiTableExists($pdo, 'smtp_configurations')) return null;

    if ((int) $branchId > 0) {
        $stmt = $pdo->prepare("SELECT id,scope_type,tenant_id,branch_id,config_name,host,port,encryption,username,password_encrypted,from_name,from_email,reply_to_email,is_default,is_active FROM smtp_configurations WHERE tenant_id=:tenant_id AND is_active=1 AND ((scope_type='branch' AND branch_id=:branch_filter) OR scope_type='tenant') ORDER BY CASE WHEN scope_type='branch' AND branch_id=:branch_order THEN 0 WHEN scope_type='tenant' THEN 1 ELSE 2 END,is_default DESC,id DESC LIMIT 1");
        $stmt->execute(array(':tenant_id' => $tenantId, ':branch_filter' => $branchId, ':branch_order' => $branchId));
    } else {
        $stmt = $pdo->prepare("SELECT id,scope_type,tenant_id,branch_id,config_name,host,port,encryption,username,password_encrypted,from_name,from_email,reply_to_email,is_default,is_active FROM smtp_configurations WHERE tenant_id=:tenant_id AND is_active=1 AND scope_type='tenant' ORDER BY is_default DESC,id DESC LIMIT 1");
        $stmt->execute(array(':tenant_id' => $tenantId));
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function employeeMailContext(PDO $pdo, $tenantId, $employeeId)
{
    $stmt = $pdo->prepare("SELECT u.id,u.branch_id,u.employee_code,u.first_name,u.last_name,u.email,u.phone,u.job_title,u.status,b.name AS branch_name,d.name AS department_name,r.name AS role_name,t.display_name AS tenant_display_name,t.legal_name AS tenant_legal_name FROM users u INNER JOIN tenants t ON t.id=u.tenant_id AND t.deleted_at IS NULL LEFT JOIN branches b ON b.id=u.branch_id AND b.tenant_id=u.tenant_id LEFT JOIN departments d ON d.id=u.department_id AND d.tenant_id=u.tenant_id LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE u.id=:employee_id AND u.tenant_id=:tenant_id AND u.deleted_at IS NULL LIMIT 1");
    $stmt->execute(array(':employee_id' => $employeeId, ':tenant_id' => $tenantId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Employee email details are not available.');
    return $row;
}

function employeeMailSendWelcome(array $config, $password, array $employee)
{
    $host = trim((string) $config['host']);
    $port = (int) $config['port'];
    $encryption = strtolower(trim((string) $config['encryption']));
    $username = trim((string) $config['username']);
    $fromEmail = trim((string) $config['from_email']);
    $fromName = trim((string) $config['from_name']);
    $replyTo = trim((string) $config['reply_to_email']);
    $recipient = trim((string) $employee['email']);

    if ($host === '') throw new RuntimeException('SMTP host is empty.');
    if ($port < 1 || $port > 65535) throw new RuntimeException('SMTP port is invalid.');
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Employee email address is invalid.');
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP From Email must be a valid email address.');

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $context = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host)));
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException('Unable to connect to SMTP server: ' . ($errstr !== '' ? $errstr : 'connection failed') . ' (' . $errno . ').');

    stream_set_timeout($socket, 20);

    try {
        employeeMailCommand($socket, null, array(220), 'SMTP greeting');
        $ehloHost = !empty($_SERVER['SERVER_NAME']) ? preg_replace('/[^A-Za-z0-9.\-]/', '', (string) $_SERVER['SERVER_NAME']) : 'fieldplx.local';
        if ($ehloHost === '') $ehloHost = 'fieldplx.local';
        employeeMailCommand($socket, 'EHLO ' . $ehloHost, array(250), 'EHLO');

        if ($encryption === 'tls' || $encryption === 'starttls') {
            employeeMailCommand($socket, 'STARTTLS', array(220), 'STARTTLS');
            $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (@stream_socket_enable_crypto($socket, true, $cryptoMethod) !== true) throw new RuntimeException('Unable to establish TLS encryption with the SMTP server.');
            employeeMailCommand($socket, 'EHLO ' . $ehloHost, array(250), 'EHLO after TLS');
        }

        if ($username !== '') {
            if ($password === '') throw new RuntimeException('SMTP password is empty.');
            employeeMailCommand($socket, 'AUTH LOGIN', array(334), 'SMTP authentication');
            employeeMailCommand($socket, base64_encode($username), array(334), 'SMTP username');
            employeeMailCommand($socket, base64_encode($password), array(235), 'SMTP password');
        }

        employeeMailCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', array(250), 'MAIL FROM');
        employeeMailCommand($socket, 'RCPT TO:<' . $recipient . '>', array(250, 251), 'RCPT TO');
        employeeMailCommand($socket, 'DATA', array(354), 'DATA');

        $tenantName = trim((string) $employee['tenant_display_name']);
        if ($tenantName === '') $tenantName = trim((string) $employee['tenant_legal_name']);
        if ($tenantName === '') $tenantName = 'FieldPlx';
        $employeeName = trim((string) $employee['first_name'] . ' ' . (string) $employee['last_name']);
        if ($employeeName === '') $employeeName = 'Employee';

        $subject = $tenantName . ' - Employee Account Created';
        $displayName = $fromName !== '' ? $fromName : $tenantName;
        $messageIdHost = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'fieldplx.local';
        $rows = array(
            array('Employee Code', trim((string) $employee['employee_code']) !== '' ? $employee['employee_code'] : '-'),
            array('Registered Email', $employee['email']),
            array('Branch', trim((string) $employee['branch_name']) !== '' ? $employee['branch_name'] : '-'),
            array('Department', trim((string) $employee['department_name']) !== '' ? $employee['department_name'] : '-'),
            array('Role', trim((string) $employee['role_name']) !== '' ? $employee['role_name'] : '-'),
            array('Job Title', trim((string) $employee['job_title']) !== '' ? $employee['job_title'] : '-'),
            array('Account Status', ucfirst((string) $employee['status'])),
        );
        $detailRows = '';
        foreach ($rows as $row) {
            $detailRows .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #e8edf3;color:#6f7b90;font-size:12px;width:38%">' . employeeMailEscape($row[0]) . '</td><td style="padding:8px 10px;border-bottom:1px solid #e8edf3;color:#0b1933;font-size:12px">' . employeeMailEscape($row[1]) . '</td></tr>';
        }
        $body = '<!doctype html><html><body style="margin:0;padding:0;background:#f6f8fb;font-family:Arial,Helvetica,sans-serif;color:#0b1933"><div style="padding:28px 14px"><div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e5eaf1;border-radius:12px;overflow:hidden"><div style="padding:22px 24px;background:#001131;color:#ffffff"><div style="font-size:20px;font-weight:700">Welcome to ' . employeeMailEscape($tenantName) . '</div><div style="margin-top:5px;font-size:12px;color:#cbd5e1">Your FieldPlx employee account has been created.</div></div><div style="padding:24px"><p style="margin:0 0 12px;font-size:14px">Hello ' . employeeMailEscape($employeeName) . ',</p><p style="margin:0 0 18px;color:#506784;font-size:13px;line-height:1.65">Your employee account has been created successfully. Your registered account details are shown below.</p><table style="width:100%;border-collapse:collapse;border:1px solid #e5eaf1">' . $detailRows . '</table><div style="margin-top:18px;padding:12px 14px;border-radius:8px;background:#f0f8e5;color:#385d12;font-size:12px;line-height:1.6">For security, your password is not included in this email. Use the password provided by your administrator to sign in.</div></div></div></div></body></html>';

        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . employeeMailEncodedHeader($displayName) . ' <' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . employeeMailEncodedHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $messageIdHost . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        );
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: <' . $replyTo . '>';
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $payload = preg_replace('/(?m)^\./', '..', $payload);
        if (@fwrite($socket, $payload . "\r\n.\r\n") === false) throw new RuntimeException('Unable to send SMTP message data.');
        employeeMailCommand($socket, null, array(250), 'Message delivery');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }

    return true;
}

$auth = employeeApiRequireAuth($pdo);
$tid = (int) $auth['tenant_id'];
$uid = (int) $auth['user_id'];
$bid = (int) $auth['branch_id'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        if (isset($_GET['meta']) && (int) $_GET['meta'] === 1) {
            employeeApiResponse(200, true, 'Employee metadata loaded successfully.', array('meta' => employeeApiMeta($pdo, $tid)));
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id > 0) {
            employeeApiResponse(200, true, 'Employee loaded successfully.', array(
                'employee' => employeeApiGet($pdo, $tid, $id),
                'meta' => employeeApiMeta($pdo, $tid),
            ));
        }

        $page = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);
        $pp = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
        if (!in_array($pp, array(10, 25, 50), true)) $pp = 10;
        $search = trim((string) ($_GET['search'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $bf = (int) ($_GET['branch_id'] ?? 0);
        $rf = (int) ($_GET['role_id'] ?? 0);

        $where = array('u.tenant_id=:tid', 'u.deleted_at IS NULL');
        $params = array(':tid' => $tid);
        if ($search !== '') {
            $v = '%' . $search . '%';
            $where[] = '(u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3 OR u.phone LIKE :s4 OR u.employee_code LIKE :s5 OR u.job_title LIKE :s6)';
            for ($i = 1; $i <= 6; $i++) $params[':s' . $i] = $v;
        }
        if (in_array($status, array('active', 'inactive', 'invited', 'suspended'), true)) {
            $where[] = 'u.status=:status';
            $params[':status'] = $status;
        }
        if ($bf > 0) {
            $where[] = 'u.branch_id=:bf';
            $params[':bf'] = $bf;
        }
        if ($rf > 0) {
            $where[] = 'u.role_id=:rf';
            $params[':rf'] = $rf;
        }

        $ws = implode(' AND ', $where);
        $c = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $ws");
        $c->execute($params);
        $total = (int) $c->fetchColumn();
        $pages = max(1, (int) ceil($total / $pp));
        if ($page > $pages) $page = $pages;
        $off = ($page - 1) * $pp;

        $sql = "SELECT u.id,u.employee_code,u.first_name,u.last_name,u.email,u.phone,u.alternate_phone,u.avatar_path,u.job_title,u.labor_rate,u.is_bookable,u.is_field_worker,u.is_tenant_admin,u.status,u.last_login_at,u.created_at,u.updated_at,u.branch_id,u.department_id,u.role_id,b.name branch_name,d.name department_name,r.name role_name,CASE WHEN u.id=:current THEN 1 ELSE 0 END is_current_user FROM users u LEFT JOIN branches b ON b.id=u.branch_id AND b.tenant_id=u.tenant_id LEFT JOIN departments d ON d.id=u.department_id AND d.tenant_id=u.tenant_id LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE $ws ORDER BY u.is_tenant_admin DESC,u.first_name,u.last_name LIMIT $pp OFFSET $off";
        $lp = $params;
        $lp[':current'] = $uid;
        $q = $pdo->prepare($sql);
        $q->execute($lp);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $s = $pdo->prepare("SELECT COUNT(*) total,SUM(status='active') active,SUM(is_field_worker=1) field_workers,COUNT(DISTINCT branch_id) branches FROM users WHERE tenant_id=:tid AND deleted_at IS NULL");
        $s->execute(array(':tid' => $tid));
        $sum = $s->fetch(PDO::FETCH_ASSOC);

        employeeApiResponse(200, true, 'Employees loaded successfully.', array(
            'employees' => $rows,
            'meta' => employeeApiMeta($pdo, $tid),
            'summary' => array(
                'total' => (int) ($sum['total'] ?? 0),
                'active' => (int) ($sum['active'] ?? 0),
                'field_workers' => (int) ($sum['field_workers'] ?? 0),
                'branches' => (int) ($sum['branches'] ?? 0),
            ),
            'pagination' => array(
                'page' => $page,
                'per_page' => $pp,
                'total' => $total,
                'pages' => $pages,
                'from' => $total ? $off + 1 : 0,
                'to' => $total ? min($off + count($rows), $total) : 0,
            ),
        ));
    }

    if ($method === 'POST') {
        $id = 0;
        $code = employeeApiString('employee_code', '');
        $fn = employeeApiString('first_name', '');
        $ln = employeeApiString('last_name', '');
        $email = strtolower(employeeApiString('email', ''));
        $phone = employeeApiString('phone', '');
        $alt = employeeApiString('alternate_phone', '');
        $job = employeeApiString('job_title', '');
        $rate = employeeApiString('labor_rate', '');
        $pass = (string) employeeApiValue('password', '');
        $status = employeeApiString('status', 'active');
        $branch = employeeApiFk($pdo, 'branches', $tid, employeeApiInt('branch_id', 0));
        $dept = employeeApiFk($pdo, 'departments', $tid, employeeApiInt('department_id', 0));
        $role = employeeApiFk($pdo, 'roles', $tid, employeeApiInt('role_id', 0));
        $book = employeeApiBool(employeeApiValue('is_bookable', false)) ? 1 : 0;
        $field = employeeApiBool(employeeApiValue('is_field_worker', false)) ? 1 : 0;
        $admin = employeeApiBool(employeeApiValue('is_tenant_admin', false)) ? 1 : 0;

        if ($fn === '') employeeApiResponse(422, false, 'First name is required.', null, 'first_name_required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) employeeApiResponse(422, false, 'Enter a valid email address.', null, 'invalid_email');
        if (!in_array($status, array('active', 'inactive', 'invited', 'suspended'), true)) employeeApiResponse(422, false, 'Invalid employee status.', null, 'invalid_status');
        if (strlen($pass) < 8) employeeApiResponse(422, false, 'Password must be at least 8 characters.', null, 'password_too_short');
        if ($rate !== '' && !is_numeric($rate)) employeeApiResponse(422, false, 'Labor rate must be a valid number.', null, 'invalid_labor_rate');

        $max = employeeApiPlanMaxUsers($pdo, $tid);
        if ($max > 0) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=:tid AND deleted_at IS NULL");
            $q->execute(array(':tid' => $tid));
            if ((int) $q->fetchColumn() >= $max) employeeApiResponse(409, false, 'Your current plan user limit has been reached.', null, 'plan_user_limit_reached');
        }

        $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:tid AND email=:email AND deleted_at IS NULL LIMIT 1");
        $q->execute(array(':tid' => $tid, ':email' => $email));
        if ($q->fetchColumn()) employeeApiResponse(409, false, 'This email address is already used by another employee.', null, 'duplicate_email');

        if ($code !== '') {
            $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:tid AND employee_code=:code AND deleted_at IS NULL LIMIT 1");
            $q->execute(array(':tid' => $tid, ':code' => $code));
            if ($q->fetchColumn()) employeeApiResponse(409, false, 'This employee code is already in use.', null, 'duplicate_employee_code');
        }

        $lr = $rate !== '' ? number_format(max(0, (float) $rate), 2, '.', '') : null;
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("INSERT INTO users(tenant_id,branch_id,department_id,role_id,employee_code,first_name,last_name,email,phone,alternate_phone,password_hash,job_title,labor_rate,is_bookable,is_field_worker,is_tenant_admin,status) VALUES(:tid,:branch,:dept,:role,:code,:fn,:ln,:email,:phone,:alt,:ph,:job,:rate,:book,:field,:admin,:status)");
            $q->execute(array(
                ':tid' => $tid, ':branch' => $branch, ':dept' => $dept, ':role' => $role,
                ':code' => $code !== '' ? $code : null, ':fn' => $fn, ':ln' => $ln !== '' ? $ln : null,
                ':email' => $email, ':phone' => $phone !== '' ? $phone : null, ':alt' => $alt !== '' ? $alt : null,
                ':ph' => password_hash($pass, PASSWORD_DEFAULT), ':job' => $job !== '' ? $job : null,
                ':rate' => $lr, ':book' => $book, ':field' => $field, ':admin' => $admin, ':status' => $status,
            ));
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $new = employeeApiGet($pdo, $tid, $id);
        employeeApiLog($pdo, $tid, $bid, $uid, 'employee_created', $id, 'Employee created: ' . $new['first_name'], array('employee' => $new), 'EMPLOYEE_CREATED', null, $new);

        $emailSent = false;
        $emailMessage = '';
        $smtpConfigName = '';
        try {
            $mailEmployee = employeeMailContext($pdo, $tid, $id);
            $smtpConfig = employeeMailFindConfig($pdo, $tid, !empty($mailEmployee['branch_id']) ? (int) $mailEmployee['branch_id'] : 0);
            if (!$smtpConfig) throw new RuntimeException('No active branch or tenant SMTP configuration is available. Configure SMTP in Master Controls.');
            $smtpConfigName = (string) ($smtpConfig['config_name'] ?? '');
            $smtpPassword = employeeMailDecryptPassword($smtpConfig['password_encrypted'], $tid);
            employeeMailSendWelcome($smtpConfig, $smtpPassword, $mailEmployee);
            $emailSent = true;
            $emailMessage = 'Welcome email sent successfully to ' . $email . '.';
        } catch (Throwable $mailError) {
            $emailMessage = substr($mailError->getMessage(), 0, 1000);
            error_log('FieldPlx employee welcome email failed for employee ' . $id . ': ' . $mailError->getMessage());
        }

        employeeApiResponse(201, true, $emailSent ? 'Employee created successfully. Welcome email sent to ' . $email . '.' : 'Employee created successfully, but the welcome email could not be sent.', array(
            'employee' => $new,
            'email_sent' => $emailSent,
            'email_to' => $email,
            'email_message' => $emailMessage,
            'smtp_configuration' => $smtpConfigName,
        ));
    }

    if (in_array($method, array('PUT', 'PATCH'), true)) {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : employeeApiInt('id', 0);
        if ($id <= 0) employeeApiResponse(422, false, 'Employee id is required.', null, 'employee_id_required');

        $old = employeeApiGet($pdo, $tid, $id);
        $input = employeeApiInput();

        $code = array_key_exists('employee_code', $input) ? trim((string) $input['employee_code']) : (string) ($old['employee_code'] ?? '');
        $fn = array_key_exists('first_name', $input) ? trim((string) $input['first_name']) : (string) $old['first_name'];
        $ln = array_key_exists('last_name', $input) ? trim((string) $input['last_name']) : (string) ($old['last_name'] ?? '');
        $email = array_key_exists('email', $input) ? strtolower(trim((string) $input['email'])) : (string) $old['email'];
        $phone = array_key_exists('phone', $input) ? trim((string) $input['phone']) : (string) ($old['phone'] ?? '');
        $alt = array_key_exists('alternate_phone', $input) ? trim((string) $input['alternate_phone']) : (string) ($old['alternate_phone'] ?? '');
        $job = array_key_exists('job_title', $input) ? trim((string) $input['job_title']) : (string) ($old['job_title'] ?? '');
        $rate = array_key_exists('labor_rate', $input) ? trim((string) $input['labor_rate']) : (string) ($old['labor_rate'] ?? '');
        $pass = array_key_exists('password', $input) ? (string) $input['password'] : '';
        $status = array_key_exists('status', $input) ? trim((string) $input['status']) : (string) $old['status'];
        $branch = array_key_exists('branch_id', $input) ? employeeApiFk($pdo, 'branches', $tid, (int) $input['branch_id']) : (!empty($old['branch_id']) ? (int) $old['branch_id'] : null);
        $dept = array_key_exists('department_id', $input) ? employeeApiFk($pdo, 'departments', $tid, (int) $input['department_id']) : (!empty($old['department_id']) ? (int) $old['department_id'] : null);
        $role = array_key_exists('role_id', $input) ? employeeApiFk($pdo, 'roles', $tid, (int) $input['role_id']) : (!empty($old['role_id']) ? (int) $old['role_id'] : null);
        $book = array_key_exists('is_bookable', $input) ? (employeeApiBool($input['is_bookable']) ? 1 : 0) : (int) $old['is_bookable'];
        $field = array_key_exists('is_field_worker', $input) ? (employeeApiBool($input['is_field_worker']) ? 1 : 0) : (int) $old['is_field_worker'];
        $admin = array_key_exists('is_tenant_admin', $input) ? (employeeApiBool($input['is_tenant_admin']) ? 1 : 0) : (int) $old['is_tenant_admin'];

        if ($fn === '') employeeApiResponse(422, false, 'First name is required.', null, 'first_name_required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) employeeApiResponse(422, false, 'Enter a valid email address.', null, 'invalid_email');
        if (!in_array($status, array('active', 'inactive', 'invited', 'suspended'), true)) employeeApiResponse(422, false, 'Invalid employee status.', null, 'invalid_status');
        if ($pass !== '' && strlen($pass) < 8) employeeApiResponse(422, false, 'New password must be at least 8 characters.', null, 'password_too_short');
        if ($rate !== '' && !is_numeric($rate)) employeeApiResponse(422, false, 'Labor rate must be a valid number.', null, 'invalid_labor_rate');
        if ($id === $uid && $status !== 'active') employeeApiResponse(409, false, 'You cannot disable your own logged-in account.', null, 'cannot_disable_self');

        $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:tid AND email=:email AND deleted_at IS NULL AND id<>:id LIMIT 1");
        $q->execute(array(':tid' => $tid, ':email' => $email, ':id' => $id));
        if ($q->fetchColumn()) employeeApiResponse(409, false, 'This email address is already used by another employee.', null, 'duplicate_email');
        if ($code !== '') {
            $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:tid AND employee_code=:code AND deleted_at IS NULL AND id<>:id LIMIT 1");
            $q->execute(array(':tid' => $tid, ':code' => $code, ':id' => $id));
            if ($q->fetchColumn()) employeeApiResponse(409, false, 'This employee code is already in use.', null, 'duplicate_employee_code');
        }

        $lr = $rate !== '' ? number_format(max(0, (float) $rate), 2, '.', '') : null;
        $sql = "UPDATE users SET branch_id=:branch,department_id=:dept,role_id=:role,employee_code=:code,first_name=:fn,last_name=:ln,email=:email,phone=:phone,alternate_phone=:alt,job_title=:job,labor_rate=:rate,is_bookable=:book,is_field_worker=:field,is_tenant_admin=:admin,status=:status";
        $par = array(
            ':branch' => $branch, ':dept' => $dept, ':role' => $role, ':code' => $code !== '' ? $code : null,
            ':fn' => $fn, ':ln' => $ln !== '' ? $ln : null, ':email' => $email,
            ':phone' => $phone !== '' ? $phone : null, ':alt' => $alt !== '' ? $alt : null,
            ':job' => $job !== '' ? $job : null, ':rate' => $lr, ':book' => $book,
            ':field' => $field, ':admin' => $admin, ':status' => $status, ':id' => $id, ':tid' => $tid,
        );
        if ($pass !== '') {
            $sql .= ',password_hash=:ph';
            $par[':ph'] = password_hash($pass, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id=:id AND tenant_id=:tid AND deleted_at IS NULL';
        $q = $pdo->prepare($sql);
        $q->execute($par);

        $new = employeeApiGet($pdo, $tid, $id);
        employeeApiLog($pdo, $tid, $bid, $uid, 'employee_updated', $id, 'Employee updated: ' . $new['first_name'], array('employee' => $new), 'EMPLOYEE_UPDATED', $old, $new);
        employeeApiResponse(200, true, 'Employee updated successfully.', array('employee' => $new));
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : employeeApiInt('id', 0);
        if ($id <= 0) employeeApiResponse(422, false, 'Employee id is required.', null, 'employee_id_required');
        if ($id === $uid) employeeApiResponse(409, false, 'You cannot delete your own logged-in account.', null, 'cannot_delete_self');

        $old = employeeApiGet($pdo, $tid, $id);
        $q = $pdo->prepare("UPDATE users SET status='inactive',deleted_at=NOW() WHERE id=:id AND tenant_id=:tid AND deleted_at IS NULL");
        $q->execute(array(':id' => $id, ':tid' => $tid));
        employeeApiLog($pdo, $tid, $bid, $uid, 'employee_deleted', $id, 'Employee deleted: ' . $old['first_name'], array('employee' => $old), 'EMPLOYEE_DELETED', $old, array('status' => 'inactive', 'deleted_at' => date('Y-m-d H:i:s')));
        employeeApiResponse(200, true, 'Employee deleted successfully.', array('deleted_employee_id' => $id));
    }

    employeeApiResponse(405, false, 'Method not allowed.', null, 'method_not_allowed');

} catch (PDOException $e) {
    error_log('FieldPlx mobile employees PDO error: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
        employeeApiResponse(409, false, 'Employee email or employee code already exists.', null, 'duplicate_record');
    }
    employeeApiResponse(500, false, 'Unable to process the employees request.', null, 'database_error');
} catch (Throwable $e) {
    error_log('FieldPlx mobile employees API error: ' . $e->getMessage());
    employeeApiResponse(500, false, 'Unable to process the employees request.', null, 'server_error');
}
