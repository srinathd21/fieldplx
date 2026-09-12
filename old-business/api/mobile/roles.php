<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Roles & Permissions API
 *
 * Upload:
 *   /business/api/mobile/roles.php
 *
 * Production:
 *   https://fieldplx.com/business/api/mobile/roles.php
 *
 * Authentication:
 *   Authorization: Bearer <access_token>
 *
 * REST usage:
 *
 * GET    /roles.php
 * GET    /roles.php?id=5
 * GET    /roles.php?permissions=1
 * POST   /roles.php
 * PUT    /roles.php?id=5
 * PATCH  /roles.php?id=5
 * DELETE /roles.php?id=5
 *
 * POST/PUT/PATCH request body:
 *   Content-Type: application/json
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

    echo json_encode(
        array(
            'success' => false,
            'error' => array(
                'code' => 'api_secret_not_configured',
                'message' => 'FieldPlx mobile API configuration is incomplete.'
            )
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if (!defined('FIELDPLX_TOKEN_ISSUER')) {
    define('FIELDPLX_TOKEN_ISSUER', 'FieldPlx');
}

if (!defined('FIELDPLX_TOKEN_AUDIENCE')) {
    define('FIELDPLX_TOKEN_AUDIENCE', 'FieldPlx-Mobile');
}

/* -------------------------------------------------------------------------
 * Response helpers
 * ---------------------------------------------------------------------- */

function roleMobileResponse(
    $status,
    $success,
    $message,
    $data = null,
    $errorCode = null
) {
    http_response_code((int)$status);

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
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function roleMobileBase64UrlDecode($data)
{
    $data = (string)$data;
    $remainder = strlen($data) % 4;

    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(
        strtr($data, '-_', '+/'),
        true
    );
}

function roleMobileAuthorizationHeader()
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

function roleMobileBearerToken()
{
    $header = roleMobileAuthorizationHeader();

    if (
        $header === '' ||
        !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)
    ) {
        return '';
    }

    return trim((string)$matches[1]);
}

function roleMobileVerifyToken($token)
{
    $parts = explode('.', (string)$token);

    if (count($parts) !== 3) {
        roleMobileResponse(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token'
        );
    }

    $headerEncoded = $parts[0];
    $payloadEncoded = $parts[1];
    $signatureEncoded = $parts[2];

    $headerJson = roleMobileBase64UrlDecode($headerEncoded);
    $payloadJson = roleMobileBase64UrlDecode($payloadEncoded);
    $signature = roleMobileBase64UrlDecode($signatureEncoded);

    if (
        $headerJson === false ||
        $payloadJson === false ||
        $signature === false
    ) {
        roleMobileResponse(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token'
        );
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        roleMobileResponse(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token'
        );
    }

    if (
        ($header['alg'] ?? '') !== 'HS256' ||
        ($header['typ'] ?? '') !== 'JWT'
    ) {
        roleMobileResponse(
            401,
            false,
            'Unsupported access token.',
            null,
            'invalid_token'
        );
    }

    $expected = hash_hmac(
        'sha256',
        $headerEncoded . '.' . $payloadEncoded,
        FIELDPLX_API_SECRET,
        true
    );

    if (!hash_equals($expected, $signature)) {
        roleMobileResponse(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_signature'
        );
    }

    $now = time();

    if (isset($payload['nbf']) && (int)$payload['nbf'] > $now) {
        roleMobileResponse(
            401,
            false,
            'Access token is not active yet.',
            null,
            'token_not_active'
        );
    }

    if (!isset($payload['exp']) || (int)$payload['exp'] <= $now) {
        roleMobileResponse(
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
        roleMobileResponse(
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
        roleMobileResponse(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_audience'
        );
    }

    if (
        empty($payload['user_id']) ||
        empty($payload['tenant_id'])
    ) {
        roleMobileResponse(
            401,
            false,
            'Invalid access token.',
            null,
            'invalid_token_context'
        );
    }

    return $payload;
}

function roleMobileTableExists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name"
    );

    $stmt->execute(
        array(
            ':table_name' => (string)$table
        )
    );

    return ((int)$stmt->fetchColumn() > 0);
}

function roleMobileRequireAuth(PDO $pdo)
{
    $token = roleMobileBearerToken();

    if ($token === '') {
        roleMobileResponse(
            401,
            false,
            'Authorization Bearer token is required.',
            null,
            'authorization_required'
        );
    }

    $claims = roleMobileVerifyToken($token);

    $userId = (int)$claims['user_id'];
    $tenantId = (int)$claims['tenant_id'];

    $stmt = $pdo->prepare(
        "SELECT
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
            t.status AS tenant_status,
            b.status AS branch_status,
            d.status AS department_status,
            r.status AS role_status,
            r.is_admin AS role_is_admin
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
         LIMIT 1"
    );

    $stmt->execute(
        array(
            ':user_id' => $userId,
            ':tenant_id' => $tenantId
        )
    );

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        roleMobileResponse(
            401,
            false,
            'Authenticated user account was not found.',
            null,
            'user_not_found'
        );
    }

    if ((string)$user['user_status'] !== 'active') {
        roleMobileResponse(
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
        roleMobileResponse(
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
        roleMobileResponse(
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
        roleMobileResponse(
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
        roleMobileResponse(
            403,
            false,
            'Your assigned role is not active.',
            null,
            'role_not_active'
        );
    }

    return array(
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'branch_id' => !empty($user['branch_id'])
            ? (int)$user['branch_id']
            : 0,
        'role_id' => !empty($user['role_id'])
            ? (int)$user['role_id']
            : 0,
        'is_tenant_admin' => !empty($user['is_tenant_admin']),
        'role_is_admin' => !empty($user['role_is_admin']),
        'user' => $user
    );
}

/* -------------------------------------------------------------------------
 * Request helpers
 * ---------------------------------------------------------------------- */

function roleMobileInput()
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

        roleMobileResponse(
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

function roleMobileValue($key, $default = null)
{
    $input = roleMobileInput();

    if (array_key_exists($key, $input)) {
        return $input[$key];
    }

    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    return $default;
}

function roleMobileString($key, $default = '')
{
    return trim((string)roleMobileValue($key, $default));
}

function roleMobileInt($key, $default = 0)
{
    return (int)roleMobileValue($key, $default);
}

function roleMobileBool($value)
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 1;
    }

    return in_array(
        strtolower(trim((string)$value)),
        array('1', 'true', 'yes', 'on'),
        true
    );
}

function roleMobileSlug($value)
{
    $value = strtolower(trim((string)$value));

    $value = preg_replace(
        '/[^a-z0-9]+/',
        '_',
        $value
    );

    return trim((string)$value, '_');
}

function roleMobileJson($value)
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    return $json === false
        ? null
        : $json;
}

function roleMobileIp()
{
    return isset($_SERVER['REMOTE_ADDR'])
        ? substr(trim((string)$_SERVER['REMOTE_ADDR']), 0, 80)
        : null;
}

function roleMobileUserAgent()
{
    return isset($_SERVER['HTTP_USER_AGENT'])
        ? substr(trim((string)$_SERVER['HTTP_USER_AGENT']), 0, 500)
        : null;
}

function roleMobileDeviceType()
{
    $ua = strtolower(
        (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    if ($ua === '') {
        return 'unknown';
    }

    if (
        strpos($ua, 'ipad') !== false ||
        strpos($ua, 'tablet') !== false ||
        strpos($ua, 'kindle') !== false
    ) {
        return 'tablet';
    }

    if (
        strpos($ua, 'mobile') !== false ||
        strpos($ua, 'iphone') !== false ||
        strpos($ua, 'android') !== false
    ) {
        return 'mobile';
    }

    return 'desktop';
}

/* -------------------------------------------------------------------------
 * Role/permission helpers
 * ---------------------------------------------------------------------- */

function roleMobileGetRole(PDO $pdo, $tenantId, $roleId)
{
    $stmt = $pdo->prepare(
        "SELECT
            id,
            tenant_id,
            name,
            code,
            description,
            is_admin,
            is_system_role,
            status,
            created_at,
            updated_at
         FROM roles
         WHERE id = :id
           AND tenant_id = :tenant_id
         LIMIT 1"
    );

    $stmt->execute(
        array(
            ':id' => (int)$roleId,
            ':tenant_id' => (int)$tenantId
        )
    );

    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        roleMobileResponse(
            404,
            false,
            'Role not found.',
            null,
            'role_not_found'
        );
    }

    return $role;
}

function roleMobilePlanId(PDO $pdo, $tenantId)
{
    $stmt = $pdo->prepare(
        "SELECT plan_id
         FROM subscriptions
         WHERE tenant_id = :tenant_id
           AND status IN ('active','trial')
         ORDER BY id DESC
         LIMIT 1"
    );

    $stmt->execute(
        array(
            ':tenant_id' => (int)$tenantId
        )
    );

    return (int)$stmt->fetchColumn();
}

function roleMobileSyncPermissions(
    PDO $pdo,
    $tenantId,
    $planId
) {
    if ((int)$planId <= 0 || (int)$tenantId <= 0) {
        return;
    }

    $definitions = array(
        array('view', '.view', 'View '),
        array('create', '.create', 'Create in '),
        array('update', '.update', 'Update '),
        array('delete', '.delete', 'Delete/archive in '),
        array('approve', '.approve', 'Approve in '),
        array('export', '.export', 'Export from ')
    );

    $sql = "
        INSERT IGNORE INTO permissions (
            module_id,
            action_code,
            permission_code,
            description
        )
        SELECT
            m.id,
            :action_code,
            CONCAT(m.module_code, :permission_suffix),
            CONCAT(:description_prefix, m.module_name)
        FROM plan_modules pm
        INNER JOIN modules m
            ON m.id = pm.module_id
        LEFT JOIN tenant_modules tm
            ON tm.tenant_id = :tenant_id
           AND tm.module_id = m.id
        WHERE pm.plan_id = :plan_id
          AND pm.is_enabled = 1
          AND m.is_active = 1
          AND m.is_sidebar_item = 1
          AND COALESCE(tm.access_type, 'inherit') <> 'disabled'
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($definitions as $definition) {
        $stmt->execute(
            array(
                ':action_code' => $definition[0],
                ':permission_suffix' => $definition[1],
                ':description_prefix' => $definition[2],
                ':tenant_id' => (int)$tenantId,
                ':plan_id' => (int)$planId
            )
        );
    }
}

function roleMobilePermissions(
    PDO $pdo,
    $tenantId,
    $planId
) {
    roleMobileSyncPermissions(
        $pdo,
        $tenantId,
        $planId
    );

    $stmt = $pdo->prepare(
        "SELECT
            p.id,
            p.module_id,
            p.action_code,
            p.permission_code,
            p.description,
            m.parent_id,
            m.module_code,
            m.module_name,
            m.menu_url,
            m.icon_name,
            m.menu_order,
            parent.module_code AS parent_module_code,
            parent.module_name AS parent_module_name,
            parent.menu_order AS parent_menu_order
         FROM permissions p
         INNER JOIN modules m
            ON m.id = p.module_id
           AND m.is_active = 1
           AND m.is_sidebar_item = 1
         INNER JOIN plan_modules pm
            ON pm.module_id = m.id
           AND pm.plan_id = :plan_id
           AND pm.is_enabled = 1
         LEFT JOIN tenant_modules tm
            ON tm.tenant_id = :tenant_id
           AND tm.module_id = m.id
         LEFT JOIN modules parent
            ON parent.id = m.parent_id
         WHERE COALESCE(
            tm.access_type,
            'inherit'
         ) <> 'disabled'
         ORDER BY
            COALESCE(parent.menu_order, m.menu_order),
            COALESCE(m.parent_id, m.id),
            CASE WHEN m.parent_id IS NULL THEN 0 ELSE 1 END,
            m.menu_order,
            m.module_name,
            CASE p.action_code
                WHEN 'view' THEN 1
                WHEN 'create' THEN 2
                WHEN 'update' THEN 3
                WHEN 'delete' THEN 4
                WHEN 'approve' THEN 5
                WHEN 'export' THEN 6
                ELSE 99
            END,
            p.action_code"
    );

    $stmt->execute(
        array(
            ':plan_id' => (int)$planId,
            ':tenant_id' => (int)$tenantId
        )
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function roleMobileSelectedPermissionIds(
    PDO $pdo,
    $tenantId,
    $roleId
) {
    $stmt = $pdo->prepare(
        "SELECT permission_id
         FROM role_permissions
         WHERE tenant_id = :tenant_id
           AND role_id = :role_id
           AND access_type = 'allow'"
    );

    $stmt->execute(
        array(
            ':tenant_id' => (int)$tenantId,
            ':role_id' => (int)$roleId
        )
    );

    return array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
}

function roleMobileGroupPermissions($permissions)
{
    $modules = array();

    foreach ($permissions as $permission) {
        $moduleId = (int)$permission['module_id'];

        if (!isset($modules[$moduleId])) {
            $modules[$moduleId] = array(
                'module_id' => $moduleId,
                'module_code' => $permission['module_code'],
                'module_name' => $permission['module_name'],
                'parent_module_code' => $permission['parent_module_code'],
                'parent_module_name' => $permission['parent_module_name'],
                'icon_name' => $permission['icon_name'],
                'permissions' => array()
            );
        }

        $modules[$moduleId]['permissions'][] = array(
            'id' => (int)$permission['id'],
            'action_code' => $permission['action_code'],
            'permission_code' => $permission['permission_code'],
            'description' => $permission['description']
        );
    }

    return array_values($modules);
}

/* -------------------------------------------------------------------------
 * Activity/Audit
 * ---------------------------------------------------------------------- */

function roleMobileActivity(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $eventType,
    $roleId,
    $title,
    $details
) {
    try {
        if (!roleMobileTableExists($pdo, 'activity_events')) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO activity_events (
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
                'role',
                :related_id,
                :title,
                :details_json,
                0
             )"
        );

        $stmt->execute(
            array(
                ':tenant_id' => (int)$tenantId,
                ':branch_id' => (int)$branchId > 0
                    ? (int)$branchId
                    : null,
                ':actor_user_id' => (int)$userId,
                ':event_type' => substr((string)$eventType, 0, 120),
                ':related_id' => (int)$roleId > 0
                    ? (int)$roleId
                    : null,
                ':title' => substr((string)$title, 0, 255),
                ':details_json' => roleMobileJson($details)
            )
        );
    } catch (Throwable $e) {
        error_log(
            'FieldPlx mobile roles activity error: ' .
            $e->getMessage()
        );
    }
}

function roleMobileAudit(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $action,
    $roleId,
    $oldValues,
    $newValues
) {
    try {
        if (function_exists('tenantAuditLog')) {
            tenantAuditLog(
                $pdo,
                $action,
                (int)$tenantId,
                (int)$branchId > 0
                    ? (int)$branchId
                    : null,
                (int)$userId,
                'role',
                (int)$roleId,
                $oldValues,
                $newValues
            );

            return;
        }

        if (!roleMobileTableExists($pdo, 'audit_logs')) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (
                tenant_id,
                branch_id,
                user_id,
                platform_user_id,
                action,
                object_type,
                object_id,
                old_values,
                new_values,
                ip_address,
                device_type,
                user_agent
             ) VALUES (
                :tenant_id,
                :branch_id,
                :user_id,
                NULL,
                :action,
                'role',
                :object_id,
                :old_values,
                :new_values,
                :ip_address,
                :device_type,
                :user_agent
             )"
        );

        $stmt->execute(
            array(
                ':tenant_id' => (int)$tenantId,
                ':branch_id' => (int)$branchId > 0
                    ? (int)$branchId
                    : null,
                ':user_id' => (int)$userId,
                ':action' => substr((string)$action, 0, 120),
                ':object_id' => (int)$roleId,
                ':old_values' => roleMobileJson($oldValues),
                ':new_values' => roleMobileJson($newValues),
                ':ip_address' => roleMobileIp(),
                ':device_type' => roleMobileDeviceType(),
                ':user_agent' => roleMobileUserAgent()
            )
        );
    } catch (Throwable $e) {
        error_log(
            'FieldPlx mobile roles audit error: ' .
            $e->getMessage()
        );
    }
}

/* -------------------------------------------------------------------------
 * Start
 * ---------------------------------------------------------------------- */

$auth = roleMobileRequireAuth($pdo);

$tenantId = (int)$auth['tenant_id'];
$userId = (int)$auth['user_id'];
$branchId = (int)$auth['branch_id'];

$planId = roleMobilePlanId(
    $pdo,
    $tenantId
);

if ($planId <= 0) {
    roleMobileResponse(
        403,
        false,
        'No active subscription plan was found for this tenant.',
        null,
        'plan_not_found'
    );
}

$method = strtoupper(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

try {

    /* ---------------------------------------------------------------------
     * GET
     * ------------------------------------------------------------------ */

    if ($method === 'GET') {

        if (
            isset($_GET['permissions']) &&
            (int)$_GET['permissions'] === 1
        ) {
            $permissions = roleMobilePermissions(
                $pdo,
                $tenantId,
                $planId
            );

            roleMobileResponse(
                200,
                true,
                'Permissions loaded successfully.',
                array(
                    'permissions' => $permissions,
                    'modules' => roleMobileGroupPermissions(
                        $permissions
                    ),
                    'selected_permission_ids' => array()
                )
            );
        }

        $roleId = isset($_GET['id'])
            ? (int)$_GET['id']
            : 0;

        if ($roleId > 0) {
            $role = roleMobileGetRole(
                $pdo,
                $tenantId,
                $roleId
            );

            $permissions = roleMobilePermissions(
                $pdo,
                $tenantId,
                $planId
            );

            $selected = roleMobileSelectedPermissionIds(
                $pdo,
                $tenantId,
                $roleId
            );

            roleMobileResponse(
                200,
                true,
                'Role loaded successfully.',
                array(
                    'role' => $role,
                    'permissions' => $permissions,
                    'modules' => roleMobileGroupPermissions(
                        $permissions
                    ),
                    'selected_permission_ids' => $selected
                )
            );
        }

        $page = max(
            1,
            isset($_GET['page'])
                ? (int)$_GET['page']
                : 1
        );

        $perPage = isset($_GET['per_page'])
            ? (int)$_GET['per_page']
            : 10;

        if (!in_array($perPage, array(10, 25, 50), true)) {
            $perPage = 10;
        }

        $search = trim(
            (string)($_GET['search'] ?? '')
        );

        $status = trim(
            (string)($_GET['status'] ?? '')
        );

        $type = trim(
            (string)($_GET['type'] ?? '')
        );

        $where = array(
            'r.tenant_id = :tenant_id'
        );

        $params = array(
            ':tenant_id' => $tenantId
        );

        if ($search !== '') {
            $where[] = '(
                r.name LIKE :search1
                OR r.code LIKE :search2
                OR r.description LIKE :search3
            )';

            $searchValue = '%' . $search . '%';

            $params[':search1'] = $searchValue;
            $params[':search2'] = $searchValue;
            $params[':search3'] = $searchValue;
        }

        if (
            in_array(
                $status,
                array('active', 'inactive'),
                true
            )
        ) {
            $where[] = 'r.status = :status';
            $params[':status'] = $status;
        }

        if ($type === 'admin') {
            $where[] = 'r.is_admin = 1';
        } elseif ($type === 'standard') {
            $where[] = 'r.is_admin = 0';
        } elseif ($type === 'system') {
            $where[] = 'r.is_system_role = 1';
        }

        $whereSql = implode(
            ' AND ',
            $where
        );

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM roles r
             WHERE $whereSql"
        );

        $countStmt->execute($params);

        $total = (int)$countStmt->fetchColumn();

        $pages = max(
            1,
            (int)ceil($total / $perPage)
        );

        if ($page > $pages) {
            $page = $pages;
        }

        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
                r.id,
                r.name,
                r.code,
                r.description,
                r.is_admin,
                r.is_system_role,
                r.status,
                r.created_at,
                r.updated_at,
                (
                    SELECT COUNT(*)
                    FROM role_permissions rp
                    WHERE rp.tenant_id = r.tenant_id
                      AND rp.role_id = r.id
                      AND rp.access_type = 'allow'
                ) AS permission_count,
                (
                    SELECT COUNT(*)
                    FROM users u
                    WHERE u.tenant_id = r.tenant_id
                      AND u.role_id = r.id
                      AND u.deleted_at IS NULL
                ) AS user_count
            FROM roles r
            WHERE $whereSql
            ORDER BY
                r.is_system_role DESC,
                r.is_admin DESC,
                r.name ASC
            LIMIT " . (int)$perPage . "
            OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summaryStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(
                    CASE
                        WHEN status = 'active' THEN 1
                        ELSE 0
                    END
                ) AS active,
                SUM(
                    CASE
                        WHEN is_admin = 1 THEN 1
                        ELSE 0
                    END
                ) AS admin
             FROM roles
             WHERE tenant_id = :tenant_id"
        );

        $summaryStmt->execute(
            array(
                ':tenant_id' => $tenantId
            )
        );

        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

        $assignedStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM users
             WHERE tenant_id = :tenant_id
               AND role_id IS NOT NULL
               AND deleted_at IS NULL"
        );

        $assignedStmt->execute(
            array(
                ':tenant_id' => $tenantId
            )
        );

        $from = $total > 0
            ? $offset + 1
            : 0;

        $to = $total > 0
            ? min($offset + count($roles), $total)
            : 0;

        roleMobileResponse(
            200,
            true,
            'Roles loaded successfully.',
            array(
                'roles' => $roles,
                'summary' => array(
                    'total' => (int)($summary['total'] ?? 0),
                    'active' => (int)($summary['active'] ?? 0),
                    'admin' => (int)($summary['admin'] ?? 0),
                    'assigned_users' => (int)$assignedStmt->fetchColumn()
                ),
                'pagination' => array(
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'pages' => $pages,
                    'from' => $from,
                    'to' => $to
                )
            )
        );
    }

    /* ---------------------------------------------------------------------
     * POST = CREATE
     * ------------------------------------------------------------------ */

    if ($method === 'POST') {

        $name = roleMobileString('name', '');

        $code = roleMobileSlug(
            roleMobileValue(
                'code',
                $name
            )
        );

        $description = roleMobileString(
            'description',
            ''
        );

        $status = roleMobileString(
            'status',
            'active'
        );

        $isAdmin = roleMobileBool(
            roleMobileValue(
                'is_admin',
                false
            )
        )
            ? 1
            : 0;

        if ($name === '') {
            roleMobileResponse(
                422,
                false,
                'Role name is required.',
                null,
                'role_name_required'
            );
        }

        if ($code === '') {
            roleMobileResponse(
                422,
                false,
                'Role code is required.',
                null,
                'role_code_required'
            );
        }

        if (
            !in_array(
                $status,
                array('active', 'inactive'),
                true
            )
        ) {
            roleMobileResponse(
                422,
                false,
                'Invalid role status.',
                null,
                'invalid_role_status'
            );
        }

        $permissions = roleMobilePermissions(
            $pdo,
            $tenantId,
            $planId
        );

        $allowed = array();

        foreach ($permissions as $permission) {
            $allowed[(int)$permission['id']] = true;
        }

        $postedIds = roleMobileValue(
            'permission_ids',
            array()
        );

        if (!is_array($postedIds)) {
            $postedIds = array($postedIds);
        }

        $permissionIds = array();

        foreach ($postedIds as $permissionId) {
            $permissionId = (int)$permissionId;

            if (
                $permissionId > 0 &&
                isset($allowed[$permissionId])
            ) {
                $permissionIds[] = $permissionId;
            }
        }

        $permissionIds = array_values(
            array_unique($permissionIds)
        );

        $dup = $pdo->prepare(
            "SELECT id
             FROM roles
             WHERE tenant_id = :tenant_id
               AND code = :code
             LIMIT 1"
        );

        $dup->execute(
            array(
                ':tenant_id' => $tenantId,
                ':code' => $code
            )
        );

        if ($dup->fetchColumn()) {
            roleMobileResponse(
                409,
                false,
                'Role code already exists.',
                null,
                'duplicate_role_code'
            );
        }

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO roles (
                    tenant_id,
                    name,
                    code,
                    description,
                    is_admin,
                    is_system_role,
                    status
                 ) VALUES (
                    :tenant_id,
                    :name,
                    :code,
                    :description,
                    :is_admin,
                    0,
                    :status
                 )"
            );

            $stmt->execute(
                array(
                    ':tenant_id' => $tenantId,
                    ':name' => $name,
                    ':code' => $code,
                    ':description' => $description !== ''
                        ? $description
                        : null,
                    ':is_admin' => $isAdmin,
                    ':status' => $status
                )
            );

            $roleId = (int)$pdo->lastInsertId();

            if (!empty($permissionIds)) {
                $insertPermission = $pdo->prepare(
                    "INSERT INTO role_permissions (
                        tenant_id,
                        role_id,
                        permission_id,
                        access_type
                     ) VALUES (
                        :tenant_id,
                        :role_id,
                        :permission_id,
                        'allow'
                     )"
                );

                foreach ($permissionIds as $permissionId) {
                    $insertPermission->execute(
                        array(
                            ':tenant_id' => $tenantId,
                            ':role_id' => $roleId,
                            ':permission_id' => $permissionId
                        )
                    );
                }
            }

            $pdo->commit();

            $role = roleMobileGetRole(
                $pdo,
                $tenantId,
                $roleId
            );

            roleMobileActivity(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                'role_created',
                $roleId,
                'Role created: ' . $role['name'],
                array(
                    'role' => $role,
                    'permission_ids' => $permissionIds
                )
            );

            roleMobileAudit(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                'ROLE_CREATED',
                $roleId,
                null,
                array(
                    'role' => $role,
                    'permission_ids' => $permissionIds
                )
            );

            roleMobileResponse(
                201,
                true,
                'Role created successfully.',
                array(
                    'role' => $role,
                    'selected_permission_ids' => $permissionIds
                )
            );

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /* ---------------------------------------------------------------------
     * PUT/PATCH = UPDATE
     * ------------------------------------------------------------------ */

    if (
        in_array(
            $method,
            array('PUT', 'PATCH'),
            true
        )
    ) {
        $roleId = roleMobileInt(
            'id',
            0
        );

        if ($roleId <= 0) {
            roleMobileResponse(
                422,
                false,
                'Role id is required.',
                null,
                'role_id_required'
            );
        }

        $oldRole = roleMobileGetRole(
            $pdo,
            $tenantId,
            $roleId
        );

        $oldPermissionIds =
            roleMobileSelectedPermissionIds(
                $pdo,
                $tenantId,
                $roleId
            );

        $name = roleMobileString(
            'name',
            (string)$oldRole['name']
        );

        $code = roleMobileSlug(
            roleMobileValue(
                'code',
                (string)$oldRole['code']
            )
        );

        $description = roleMobileString(
            'description',
            (string)($oldRole['description'] ?? '')
        );

        $status = roleMobileString(
            'status',
            (string)$oldRole['status']
        );

        $isAdmin = array_key_exists(
            'is_admin',
            roleMobileInput()
        )
            ? (
                roleMobileBool(
                    roleMobileValue(
                        'is_admin',
                        false
                    )
                )
                    ? 1
                    : 0
            )
            : (int)$oldRole['is_admin'];

        if (
            (int)$oldRole['is_system_role'] === 1
        ) {
            $name = (string)$oldRole['name'];
            $code = (string)$oldRole['code'];
            $isAdmin = (int)$oldRole['is_admin'];
        }

        if ($name === '' || $code === '') {
            roleMobileResponse(
                422,
                false,
                'Role name and code are required.',
                null,
                'validation_error'
            );
        }

        if (
            !in_array(
                $status,
                array('active', 'inactive'),
                true
            )
        ) {
            roleMobileResponse(
                422,
                false,
                'Invalid role status.',
                null,
                'invalid_role_status'
            );
        }

        $dup = $pdo->prepare(
            "SELECT id
             FROM roles
             WHERE tenant_id = :tenant_id
               AND code = :code
               AND id <> :id
             LIMIT 1"
        );

        $dup->execute(
            array(
                ':tenant_id' => $tenantId,
                ':code' => $code,
                ':id' => $roleId
            )
        );

        if ($dup->fetchColumn()) {
            roleMobileResponse(
                409,
                false,
                'Role code already exists.',
                null,
                'duplicate_role_code'
            );
        }

        $input = roleMobileInput();

        $permissionIds = $oldPermissionIds;

        if (array_key_exists('permission_ids', $input)) {
            $permissions = roleMobilePermissions(
                $pdo,
                $tenantId,
                $planId
            );

            $allowed = array();

            foreach ($permissions as $permission) {
                $allowed[(int)$permission['id']] = true;
            }

            $postedIds = $input['permission_ids'];

            if (!is_array($postedIds)) {
                $postedIds = array($postedIds);
            }

            $permissionIds = array();

            foreach ($postedIds as $permissionId) {
                $permissionId = (int)$permissionId;

                if (
                    $permissionId > 0 &&
                    isset($allowed[$permissionId])
                ) {
                    $permissionIds[] = $permissionId;
                }
            }

            $permissionIds = array_values(
                array_unique($permissionIds)
            );
        }

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "UPDATE roles
                 SET
                    name = :name,
                    code = :code,
                    description = :description,
                    is_admin = :is_admin,
                    status = :status
                 WHERE id = :id
                   AND tenant_id = :tenant_id"
            );

            $stmt->execute(
                array(
                    ':name' => $name,
                    ':code' => $code,
                    ':description' => $description !== ''
                        ? $description
                        : null,
                    ':is_admin' => $isAdmin,
                    ':status' => $status,
                    ':id' => $roleId,
                    ':tenant_id' => $tenantId
                )
            );

            if (array_key_exists('permission_ids', $input)) {
                $deletePermissions = $pdo->prepare(
                    "DELETE FROM role_permissions
                     WHERE tenant_id = :tenant_id
                       AND role_id = :role_id"
                );

                $deletePermissions->execute(
                    array(
                        ':tenant_id' => $tenantId,
                        ':role_id' => $roleId
                    )
                );

                if (!empty($permissionIds)) {
                    $insertPermission = $pdo->prepare(
                        "INSERT INTO role_permissions (
                            tenant_id,
                            role_id,
                            permission_id,
                            access_type
                         ) VALUES (
                            :tenant_id,
                            :role_id,
                            :permission_id,
                            'allow'
                         )"
                    );

                    foreach ($permissionIds as $permissionId) {
                        $insertPermission->execute(
                            array(
                                ':tenant_id' => $tenantId,
                                ':role_id' => $roleId,
                                ':permission_id' => $permissionId
                            )
                        );
                    }
                }
            }

            $pdo->commit();

            $newRole = roleMobileGetRole(
                $pdo,
                $tenantId,
                $roleId
            );

            roleMobileActivity(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                'role_updated',
                $roleId,
                'Role updated: ' . $newRole['name'],
                array(
                    'role' => $newRole,
                    'permission_ids' => $permissionIds
                )
            );

            roleMobileAudit(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                'ROLE_UPDATED',
                $roleId,
                array(
                    'role' => $oldRole,
                    'permission_ids' => $oldPermissionIds
                ),
                array(
                    'role' => $newRole,
                    'permission_ids' => $permissionIds
                )
            );

            roleMobileResponse(
                200,
                true,
                'Role updated successfully.',
                array(
                    'role' => $newRole,
                    'selected_permission_ids' => $permissionIds
                )
            );

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /* ---------------------------------------------------------------------
     * DELETE
     * ------------------------------------------------------------------ */

    if ($method === 'DELETE') {
        $roleId = roleMobileInt(
            'id',
            0
        );

        if ($roleId <= 0) {
            roleMobileResponse(
                422,
                false,
                'Role id is required.',
                null,
                'role_id_required'
            );
        }

        $role = roleMobileGetRole(
            $pdo,
            $tenantId,
            $roleId
        );

        if ((int)$role['is_system_role'] === 1) {
            roleMobileResponse(
                409,
                false,
                'System roles cannot be deleted.',
                null,
                'system_role_delete_blocked'
            );
        }

        $userCountStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM users
             WHERE tenant_id = :tenant_id
               AND role_id = :role_id
               AND deleted_at IS NULL"
        );

        $userCountStmt->execute(
            array(
                ':tenant_id' => $tenantId,
                ':role_id' => $roleId
            )
        );

        if ((int)$userCountStmt->fetchColumn() > 0) {
            roleMobileResponse(
                409,
                false,
                'This role is assigned to users. Reassign those users before deleting the role.',
                null,
                'role_in_use'
            );
        }

        $permissionIds =
            roleMobileSelectedPermissionIds(
                $pdo,
                $tenantId,
                $roleId
            );

        $pdo->beginTransaction();

        try {
            $deletePermissions = $pdo->prepare(
                "DELETE FROM role_permissions
                 WHERE tenant_id = :tenant_id
                   AND role_id = :role_id"
            );

            $deletePermissions->execute(
                array(
                    ':tenant_id' => $tenantId,
                    ':role_id' => $roleId
                )
            );

            $deleteRole = $pdo->prepare(
                "DELETE FROM roles
                 WHERE id = :id
                   AND tenant_id = :tenant_id"
            );

            $deleteRole->execute(
                array(
                    ':id' => $roleId,
                    ':tenant_id' => $tenantId
                )
            );

            $pdo->commit();

            roleMobileActivity(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                'role_deleted',
                $roleId,
                'Role deleted: ' . $role['name'],
                array(
                    'role' => $role,
                    'permission_ids' => $permissionIds
                )
            );

            roleMobileAudit(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                'ROLE_DELETED',
                $roleId,
                array(
                    'role' => $role,
                    'permission_ids' => $permissionIds
                ),
                null
            );

            roleMobileResponse(
                200,
                true,
                'Role deleted successfully.',
                array(
                    'deleted_role_id' => $roleId
                )
            );

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    roleMobileResponse(
        405,
        false,
        'Method not allowed.',
        null,
        'method_not_allowed'
    );

} catch (PDOException $e) {
    error_log(
        'FieldPlx mobile roles PDO error: ' .
        $e->getMessage()
    );

    if (
        isset($e->errorInfo[1]) &&
        (int)$e->errorInfo[1] === 1062
    ) {
        roleMobileResponse(
            409,
            false,
            'A duplicate role or permission record already exists.',
            null,
            'duplicate_record'
        );
    }

    roleMobileResponse(
        500,
        false,
        'Unable to process the roles request.',
        null,
        'database_error'
    );

} catch (Throwable $e) {
    error_log(
        'FieldPlx mobile roles API error: ' .
        $e->getMessage()
    );

    roleMobileResponse(
        500,
        false,
        'Unable to process the roles request.',
        null,
        'server_error'
    );
}
