<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Master Controls - Services
 *
 * GET    services.php
 * GET    services.php?id=1
 * POST   services.php
 * PUT    services.php?id=1
 * PATCH  services.php?id=1
 * DELETE services.php?id=1
 *
 * DELETE archives the service instead of physically deleting it.
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

function mcservice_currency($pdo, $tenantId)
{
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.currency_code,
            c.currency_name,
            c.symbol,
            c.symbol_position,
            c.decimal_places
        FROM tenants t
        INNER JOIN currencies c ON c.id = t.currency_id
        WHERE t.id = :tenant_id
          AND t.deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(array(':tenant_id' => (int)$tenantId));
    $currency = $stmt->fetch(PDO::FETCH_ASSOC);

    return $currency ?: array(
        'id' => null,
        'currency_code' => '',
        'currency_name' => '',
        'symbol' => '',
        'symbol_position' => 'before',
        'decimal_places' => 2
    );
}

try {
    if ($method === 'GET') {
        $id = mcapi_int('id', 0);

        $select = "
            SELECT
                ps.id,
                ps.tenant_id,
                ps.item_type,
                ps.name,
                ps.sku,
                ps.description,
                ps.unit_name,
                ps.unit_cost,
                ps.unit_price,
                ps.tax_percent,
                ps.is_bookable,
                ps.estimated_duration_minutes,
                ps.status,
                ps.created_at,
                ps.updated_at
            FROM product_services ps
            WHERE ps.tenant_id = :tenant_id
              AND ps.item_type = 'service'
              AND ps.deleted_at IS NULL
        ";

        if ($id > 0) {
            $stmt = $pdo->prepare($select . " AND ps.id = :id LIMIT 1");
            $stmt->execute(array(
                ':tenant_id' => $tenantId,
                ':id' => $id
            ));

            $service = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$service) {
                mcapi_response(
                    404,
                    false,
                    'Service not found.',
                    null,
                    'service_not_found'
                );
            }

            mcapi_response(
                200,
                true,
                'Service loaded successfully.',
                array(
                    'service' => $service,
                    'meta' => array(
                        'tenant_currency' => mcservice_currency($pdo, $tenantId)
                    )
                )
            );
        }

        $stmt = $pdo->prepare(
            $select . "
            ORDER BY
                FIELD(ps.status,'active','inactive','archived'),
                ps.name ASC
        ");
        $stmt->execute(array(':tenant_id' => $tenantId));

        mcapi_response(
            200,
            true,
            'Services loaded successfully.',
            array(
                'services' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'meta' => array(
                    'tenant_currency' => mcservice_currency($pdo, $tenantId),
                    'status_options' => array('active', 'inactive', 'archived')
                )
            )
        );
    }

    if (in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
        $id = mcapi_int('id', 0);

        if ($method !== 'POST' && $id <= 0) {
            mcapi_response(
                422,
                false,
                'Service id is required for update.',
                null,
                'service_id_required'
            );
        }

        $old = $id > 0
            ? mcapi_fetch_row($pdo, 'product_services', $tenantId, $id)
            : null;

        if ($id > 0 && !$old) {
            mcapi_response(
                404,
                false,
                'Service not found.',
                null,
                'service_not_found'
            );
        }

        $name = mcapi_string('name', '');
        $sku = mcapi_string('sku', '');
        $description = mcapi_string('description', '');
        $unitName = mcapi_string('unit_name', '');
        $unitCostRaw = mcapi_string('unit_cost', '0');
        $unitPriceRaw = mcapi_string('unit_price', '0');
        $taxPercentRaw = mcapi_string('tax_percent', '0');
        $durationRaw = mcapi_string('estimated_duration_minutes', '');
        $status = mcapi_string('status', 'active');
        $isBookable = mcapi_bool('is_bookable', false) ? 1 : 0;

        if ($name === '') {
            mcapi_response(
                422,
                false,
                'Service name is required.',
                null,
                'validation_error'
            );
        }

        if (!is_numeric($unitCostRaw) || (float)$unitCostRaw < 0) {
            mcapi_response(
                422,
                false,
                'Internal service cost must be a valid non-negative amount.',
                null,
                'validation_error'
            );
        }

        if (!is_numeric($unitPriceRaw) || (float)$unitPriceRaw < 0) {
            mcapi_response(
                422,
                false,
                'Customer service price must be a valid non-negative amount.',
                null,
                'validation_error'
            );
        }

        if (!in_array($status, array('active', 'inactive', 'archived'), true)) {
            mcapi_response(
                422,
                false,
                'Invalid service status.',
                null,
                'validation_error'
            );
        }

        $duration = null;

        if ($durationRaw !== '') {
            if (!ctype_digit($durationRaw)) {
                mcapi_response(
                    422,
                    false,
                    'Estimated duration must be a whole number of minutes.',
                    null,
                    'validation_error'
                );
            }

            $duration = (int)$durationRaw;
        }

        if (!is_numeric($taxPercentRaw)) {
            mcapi_response(
                422,
                false,
                'Tax percent must be a valid number.',
                null,
                'validation_error'
            );
        }

        $taxPercent = (float)$taxPercentRaw;

        if ($taxPercent < 0 || $taxPercent > 100) {
            mcapi_response(
                422,
                false,
                'Tax percent must be between 0 and 100.',
                null,
                'validation_error'
            );
        }

        $nameSql = "
            SELECT id
            FROM product_services
            WHERE tenant_id = :tenant_id
              AND item_type = 'service'
              AND name = :name
              AND deleted_at IS NULL
        ";
        $nameParams = array(
            ':tenant_id' => $tenantId,
            ':name' => $name
        );

        if ($id > 0) {
            $nameSql .= " AND id <> :id";
            $nameParams[':id'] = $id;
        }

        $nameCheck = $pdo->prepare($nameSql);
        $nameCheck->execute($nameParams);

        if ($nameCheck->fetchColumn()) {
            mcapi_response(
                409,
                false,
                'A service with this name already exists.',
                null,
                'duplicate_service_name'
            );
        }

        if ($sku !== '') {
            $skuSql = "
                SELECT id
                FROM product_services
                WHERE tenant_id = :tenant_id
                  AND sku = :sku
                  AND deleted_at IS NULL
            ";
            $skuParams = array(
                ':tenant_id' => $tenantId,
                ':sku' => $sku
            );

            if ($id > 0) {
                $skuSql .= " AND id <> :id";
                $skuParams[':id'] = $id;
            }

            $skuCheck = $pdo->prepare($skuSql);
            $skuCheck->execute($skuParams);

            if ($skuCheck->fetchColumn()) {
                mcapi_response(
                    409,
                    false,
                    'This SKU / service code is already in use.',
                    null,
                    'duplicate_service_sku'
                );
            }
        }

        $params = array(
            ':name' => $name,
            ':sku' => $sku !== '' ? $sku : null,
            ':description' => $description !== '' ? $description : null,
            ':unit_name' => $unitName !== '' ? $unitName : null,
            ':unit_cost' => number_format((float)$unitCostRaw, 2, '.', ''),
            ':unit_price' => number_format((float)$unitPriceRaw, 2, '.', ''),
            ':tax_percent' => number_format($taxPercent, 4, '.', ''),
            ':is_bookable' => $isBookable,
            ':duration' => $duration,
            ':status' => $status
        );

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE product_services
                SET
                    name = :name,
                    sku = :sku,
                    description = :description,
                    unit_name = :unit_name,
                    unit_cost = :unit_cost,
                    unit_price = :unit_price,
                    tax_percent = :tax_percent,
                    is_bookable = :is_bookable,
                    estimated_duration_minutes = :duration,
                    status = :status
                WHERE id = :id
                  AND tenant_id = :tenant_id
                  AND item_type = 'service'
                  AND deleted_at IS NULL
            ");

            $params[':id'] = $id;
            $params[':tenant_id'] = $tenantId;
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO product_services (
                    tenant_id,
                    item_type,
                    name,
                    sku,
                    description,
                    unit_name,
                    unit_cost,
                    unit_price,
                    tax_percent,
                    is_bookable,
                    estimated_duration_minutes,
                    status
                ) VALUES (
                    :tenant_id,
                    'service',
                    :name,
                    :sku,
                    :description,
                    :unit_name,
                    :unit_cost,
                    :unit_price,
                    :tax_percent,
                    :is_bookable,
                    :duration,
                    :status
                )
            ");

            $params[':tenant_id'] = $tenantId;
            $stmt->execute($params);
            $id = (int)$pdo->lastInsertId();
        }

        $new = mcapi_fetch_row($pdo, 'product_services', $tenantId, $id);

        mcapi_activity(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            $old ? 'service_updated' : 'service_created',
            'service',
            $id,
            $old ? 'Service updated: ' . $name : 'Service created: ' . $name,
            $new
        );

        mcapi_audit(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            $old ? 'SERVICE_UPDATED' : 'SERVICE_CREATED',
            'service',
            $id,
            $old,
            $new
        );

        mcapi_response(
            200,
            true,
            $old ? 'Service updated successfully.' : 'Service created successfully.',
            array('service' => $new)
        );
    }

    if ($method === 'DELETE') {
        $id = mcapi_int('id', 0);

        if ($id <= 0) {
            mcapi_response(
                422,
                false,
                'Service id is required.',
                null,
                'service_id_required'
            );
        }

        $old = mcapi_fetch_row($pdo, 'product_services', $tenantId, $id);

        if (!$old) {
            mcapi_response(
                404,
                false,
                'Service not found.',
                null,
                'service_not_found'
            );
        }

        $stmt = $pdo->prepare("
            UPDATE product_services
            SET
                status = 'archived',
                deleted_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND item_type = 'service'
              AND deleted_at IS NULL
        ");
        $stmt->execute(array(
            ':id' => $id,
            ':tenant_id' => $tenantId
        ));

        mcapi_activity(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'service_archived',
            'service',
            $id,
            'Service archived: ' . $old['name'],
            $old
        );

        mcapi_audit(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'SERVICE_ARCHIVED',
            'service',
            $id,
            $old,
            array(
                'status' => 'archived',
                'deleted_at' => date('Y-m-d H:i:s')
            )
        );

        mcapi_response(
            200,
            true,
            'Service archived successfully.',
            array('archived_id' => $id)
        );
    }

    mcapi_response(405, false, 'Method not allowed.', null, 'method_not_allowed');
} catch (PDOException $e) {
    mcapi_pdo_error($e, 'services');
} catch (Throwable $e) {
    error_log('FieldPlx mobile services API error: ' . $e->getMessage());
    mcapi_response(
        500,
        false,
        'Unable to process the service request.',
        null,
        'server_error'
    );
}
