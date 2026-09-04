<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Master Controls - Number Formatting
 *
 * GET  number-formatting.php
 * GET  number-formatting.php?document_type=invoice
 * GET  number-formatting.php?document_type=invoice&branch_id=0
 * GET  number-formatting.php?document_type=invoice&branch_id=2
 * POST number-formatting.php
 * PUT  number-formatting.php
 * PATCH number-formatting.php
 *
 * POST/PUT/PATCH perform UPSERT using:
 * tenant + branch scope + document type.
 *
 * Current web Master Controls does not delete number sequences,
 * so no DELETE operation is exposed here.
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

function mcnumber_preview($row)
{
    if (!is_array($row)) {
        return '';
    }

    $prefix = trim((string)($row['prefix'] ?? ''));
    $separator = (string)($row['number_separator'] ?? '-');
    $suffix = trim((string)($row['suffix'] ?? ''));
    $middle = (string)($row['middle_format'] ?? 'none');
    $length = max(1, (int)($row['number_length'] ?? 6));
    $current = max(0, (int)($row['current_number'] ?? 0)) + 1;
    $fyMonth = (int)($row['financial_year_start_month'] ?? 4);

    $year = date('Y');
    $month = date('m');
    $mid = '';

    if ($middle === 'year') {
        $mid = $year;
    } elseif ($middle === 'year_month') {
        $mid = $year . $month;
    } elseif ($middle === 'financial_year') {
        $nowMonth = (int)date('n');
        $nowYear = (int)date('Y');
        $fyStart = $nowMonth >= $fyMonth ? $nowYear : ($nowYear - 1);

        $mid =
            substr((string)$fyStart, -2) .
            substr((string)($fyStart + 1), -2);
    } elseif ($middle === 'branch_year') {
        $mid = 'BR' . $year;
    }

    $parts = array();

    if ($prefix !== '') {
        $parts[] = $prefix;
    }

    if ($mid !== '') {
        $parts[] = $mid;
    }

    $parts[] = str_pad(
        (string)$current,
        $length,
        '0',
        STR_PAD_LEFT
    );

    $value = implode($separator, $parts);

    if ($suffix !== '') {
        $value .= $separator . $suffix;
    }

    return $value;
}

function mcnumber_row($row)
{
    if (!is_array($row)) {
        return $row;
    }

    $row['next_number_preview'] = mcnumber_preview($row);

    return $row;
}

try {
    if ($method === 'GET') {
        $documentType = mcapi_string('document_type', '');
        $hasBranchParameter = array_key_exists('branch_id', $_GET);
        $branchId = mcapi_int('branch_id', 0);

        if (
            $documentType !== '' &&
            !in_array($documentType, array('invoice', 'quote', 'request'), true)
        ) {
            mcapi_response(
                422,
                false,
                'Invalid document type.',
                null,
                'validation_error'
            );
        }

        if (
            $hasBranchParameter &&
            $branchId > 0 &&
            !mcapi_branch_exists($pdo, $tenantId, $branchId)
        ) {
            mcapi_response(
                422,
                false,
                'Selected branch is invalid.',
                null,
                'invalid_branch'
            );
        }

        if ($documentType !== '') {
            if ($hasBranchParameter && $branchId > 0) {
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM document_sequences
                    WHERE tenant_id = :tenant_id
                      AND branch_id = :branch_id
                      AND document_type = :document_type
                    LIMIT 1
                ");
                $stmt->execute(array(
                    ':tenant_id' => $tenantId,
                    ':branch_id' => $branchId,
                    ':document_type' => $documentType
                ));

                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                mcapi_response(
                    200,
                    true,
                    'Number format loaded successfully.',
                    array(
                        'document_type' => $documentType,
                        'branch_id' => $branchId,
                        'sequence' => $row ? mcnumber_row($row) : null
                    )
                );
            }

            if ($hasBranchParameter) {
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM document_sequences
                    WHERE tenant_id = :tenant_id
                      AND branch_id IS NULL
                      AND document_type = :document_type
                    LIMIT 1
                ");
                $stmt->execute(array(
                    ':tenant_id' => $tenantId,
                    ':document_type' => $documentType
                ));

                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                mcapi_response(
                    200,
                    true,
                    'Number format loaded successfully.',
                    array(
                        'document_type' => $documentType,
                        'branch_id' => null,
                        'sequence' => $row ? mcnumber_row($row) : null
                    )
                );
            }

            $stmt = $pdo->prepare("
                SELECT *
                FROM document_sequences
                WHERE tenant_id = :tenant_id
                  AND document_type = :document_type
                ORDER BY branch_id IS NULL DESC, id DESC
            ");
            $stmt->execute(array(
                ':tenant_id' => $tenantId,
                ':document_type' => $documentType
            ));

            $rows = array();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = mcnumber_row($row);
            }

            mcapi_response(
                200,
                true,
                'Number formats loaded successfully.',
                array(
                    'document_type' => $documentType,
                    'sequences' => $rows
                )
            );
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM document_sequences
            WHERE tenant_id = :tenant_id
              AND document_type IN ('invoice','quote','request')
            ORDER BY
                CASE document_type
                    WHEN 'invoice' THEN 1
                    WHEN 'quote' THEN 2
                    WHEN 'request' THEN 3
                    ELSE 4
                END,
                branch_id IS NULL DESC,
                id DESC
        ");
        $stmt->execute(array(':tenant_id' => $tenantId));

        $all = array(
            'invoice' => array(),
            'quote' => array(),
            'request' => array()
        );
        $preferred = array();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = (string)$row['document_type'];
            $formatted = mcnumber_row($row);

            if (!isset($all[$type])) {
                $all[$type] = array();
            }

            $all[$type][] = $formatted;

            if (!isset($preferred[$type])) {
                $preferred[$type] = $formatted;
            }

            if ($row['branch_id'] === null) {
                $preferred[$type] = $formatted;
            }
        }

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
            'Number formats loaded successfully.',
            array(
                'sequences' => $all,
                'preferred' => $preferred,
                'meta' => array(
                    'branches' => $branchStmt->fetchAll(PDO::FETCH_ASSOC),
                    'document_types' => array(
                        array('value' => 'invoice', 'label' => 'Invoice'),
                        array('value' => 'quote', 'label' => 'Quote'),
                        array('value' => 'request', 'label' => 'Enquiry')
                    ),
                    'middle_format_options' => array(
                        'none',
                        'year',
                        'year_month',
                        'financial_year',
                        'branch_year'
                    ),
                    'reset_period_options' => array(
                        'never',
                        'monthly',
                        'yearly',
                        'financial_year'
                    )
                )
            )
        );
    }

    if (in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
        $documentType = mcapi_string('document_type', '');

        if (
            !in_array($documentType, array('invoice', 'quote', 'request'), true)
        ) {
            mcapi_response(
                422,
                false,
                'Invalid document type.',
                null,
                'validation_error'
            );
        }

        $branchId = mcapi_int('branch_id', 0);
        $middle = mcapi_string('middle_format', 'none');
        $reset = mcapi_string('reset_period', 'never');
        $length = mcapi_int('number_length', 6);
        $current = mcapi_int('current_number', 0);
        $fyMonth = mcapi_int('financial_year_start_month', 4);
        $isActive = mcapi_bool('is_active', true) ? 1 : 0;
        $prefix = mcapi_string('prefix', '');
        $separator = (string)mcapi_value('number_separator', '-');
        $suffix = mcapi_string('suffix', '');

        if (
            !in_array(
                $middle,
                array('none', 'year', 'year_month', 'financial_year', 'branch_year'),
                true
            )
        ) {
            mcapi_response(
                422,
                false,
                'Invalid middle format.',
                null,
                'validation_error'
            );
        }

        if (
            !in_array(
                $reset,
                array('never', 'monthly', 'yearly', 'financial_year'),
                true
            )
        ) {
            mcapi_response(
                422,
                false,
                'Invalid reset period.',
                null,
                'validation_error'
            );
        }

        if ($length < 1 || $length > 12) {
            mcapi_response(
                422,
                false,
                'Number length must be between 1 and 12.',
                null,
                'validation_error'
            );
        }

        if ($fyMonth < 1 || $fyMonth > 12) {
            mcapi_response(
                422,
                false,
                'Financial year start month must be between 1 and 12.',
                null,
                'validation_error'
            );
        }

        if (strlen($prefix) > 50) {
            mcapi_response(
                422,
                false,
                'Prefix cannot exceed 50 characters.',
                null,
                'validation_error'
            );
        }

        if (strlen($suffix) > 50) {
            mcapi_response(
                422,
                false,
                'Suffix cannot exceed 50 characters.',
                null,
                'validation_error'
            );
        }

        if (strlen($separator) > 10) {
            mcapi_response(
                422,
                false,
                'Separator cannot exceed 10 characters.',
                null,
                'validation_error'
            );
        }

        if (
            $branchId > 0 &&
            !mcapi_branch_exists($pdo, $tenantId, $branchId)
        ) {
            mcapi_response(
                422,
                false,
                'Selected branch is invalid.',
                null,
                'invalid_branch'
            );
        }

        if ($branchId > 0) {
            $find = $pdo->prepare("
                SELECT id
                FROM document_sequences
                WHERE tenant_id = :tenant_id
                  AND branch_id = :branch_id
                  AND document_type = :document_type
                LIMIT 1
            ");
            $find->execute(array(
                ':tenant_id' => $tenantId,
                ':branch_id' => $branchId,
                ':document_type' => $documentType
            ));
        } else {
            $find = $pdo->prepare("
                SELECT id
                FROM document_sequences
                WHERE tenant_id = :tenant_id
                  AND branch_id IS NULL
                  AND document_type = :document_type
                LIMIT 1
            ");
            $find->execute(array(
                ':tenant_id' => $tenantId,
                ':document_type' => $documentType
            ));
        }

        $id = (int)$find->fetchColumn();

        $old = $id > 0
            ? mcapi_fetch_row($pdo, 'document_sequences', $tenantId, $id)
            : null;

        $params = array(
            ':prefix' => $prefix !== '' ? $prefix : null,
            ':number_separator' => $separator,
            ':middle_format' => $middle,
            ':suffix' => $suffix !== '' ? $suffix : null,
            ':number_length' => $length,
            ':current_number' => max(0, $current),
            ':reset_period' => $reset,
            ':financial_year_start_month' => $fyMonth,
            ':is_active' => $isActive
        );

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE document_sequences
                SET
                    prefix = :prefix,
                    number_separator = :number_separator,
                    middle_format = :middle_format,
                    suffix = :suffix,
                    number_length = :number_length,
                    current_number = :current_number,
                    reset_period = :reset_period,
                    financial_year_start_month = :financial_year_start_month,
                    is_active = :is_active
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");

            $params[':id'] = $id;
            $params[':tenant_id'] = $tenantId;
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO document_sequences (
                    tenant_id,
                    branch_id,
                    document_type,
                    prefix,
                    number_separator,
                    middle_format,
                    suffix,
                    number_length,
                    current_number,
                    reset_period,
                    financial_year_start_month,
                    is_active
                ) VALUES (
                    :tenant_id,
                    :branch_id,
                    :document_type,
                    :prefix,
                    :number_separator,
                    :middle_format,
                    :suffix,
                    :number_length,
                    :current_number,
                    :reset_period,
                    :financial_year_start_month,
                    :is_active
                )
            ");

            $params[':tenant_id'] = $tenantId;
            $params[':branch_id'] = $branchId > 0 ? $branchId : null;
            $params[':document_type'] = $documentType;
            $stmt->execute($params);

            $id = (int)$pdo->lastInsertId();
        }

        $new = mcapi_fetch_row(
            $pdo,
            'document_sequences',
            $tenantId,
            $id
        );

        $label = $documentType === 'request'
            ? 'Enquiry'
            : ucfirst($documentType);

        mcapi_activity(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'document_sequence_saved',
            'document_sequence',
            $id,
            $label . ' number format updated',
            $new
        );

        mcapi_audit(
            $pdo,
            $tenantId,
            $sessionBranchId,
            $userId,
            'DOCUMENT_SEQUENCE_SAVED',
            'document_sequence',
            $id,
            $old,
            $new
        );

        mcapi_response(
            200,
            true,
            $label . ' number format saved successfully.',
            array('sequence' => mcnumber_row($new))
        );
    }

    mcapi_response(405, false, 'Method not allowed.', null, 'method_not_allowed');
} catch (PDOException $e) {
    mcapi_pdo_error($e, 'number_formatting');
} catch (Throwable $e) {
    error_log(
        'FieldPlx mobile number formatting API error: ' .
        $e->getMessage()
    );
    mcapi_response(
        500,
        false,
        'Unable to process the number formatting request.',
        null,
        'server_error'
    );
}
