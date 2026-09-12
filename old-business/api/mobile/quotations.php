<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Quotations API
 *
 * Upload:
 *   /business/api/mobile/quotations.php
 *
 * Production URL:
 *   https://fieldplx.com/business/api/mobile/quotations.php
 *
 * Authentication:
 *   Authorization: Bearer <access_token>
 *
 * REST endpoints:
 *   GET    /quotations.php
 *   GET    /quotations.php?id=15
 *   GET    /quotations.php?meta=1
 *   POST   /quotations.php
 *   PUT    /quotations.php?id=15
 *   PATCH  /quotations.php?id=15
 *   DELETE /quotations.php?id=15
 *
 * List query parameters:
 *   page, per_page, search, status, from_date, to_date, sort, direction
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

if (!defined('FIELDPLX_API_SECRET') || trim((string)FIELDPLX_API_SECRET) === '') {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => array(
            'code' => 'api_secret_not_configured',
            'message' => 'FieldPlx mobile API configuration is incomplete.'
        )
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!defined('FIELDPLX_TOKEN_ISSUER')) {
    define('FIELDPLX_TOKEN_ISSUER', 'FieldPlx');
}
if (!defined('FIELDPLX_TOKEN_AUDIENCE')) {
    define('FIELDPLX_TOKEN_AUDIENCE', 'FieldPlx-Mobile');
}

function qaResponse($status, $success, $message, $data = null, $errorCode = null)
{
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

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qaTable(PDO $pdo, $table)
{
    static $cache = array();
    $table = (string)$table;
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
    );
    $stmt->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function qaColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );
    $stmt->execute(array(
        ':table_name' => (string)$table,
        ':column_name' => (string)$column
    ));
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function qaBase64UrlDecode($data)
{
    $data = (string)$data;
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function qaAuthorizationHeader()
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

function qaVerifyToken($token)
{
    $parts = explode('.', (string)$token);
    if (count($parts) !== 3) {
        qaResponse(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
    $headerJson = qaBase64UrlDecode($headerEncoded);
    $payloadJson = qaBase64UrlDecode($payloadEncoded);
    $signature = qaBase64UrlDecode($signatureEncoded);

    if ($headerJson === false || $payloadJson === false || $signature === false) {
        qaResponse(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        qaResponse(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    if (($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT') {
        qaResponse(401, false, 'Unsupported access token.', null, 'invalid_token');
    }

    $expected = hash_hmac(
        'sha256',
        $headerEncoded . '.' . $payloadEncoded,
        FIELDPLX_API_SECRET,
        true
    );

    if (!hash_equals($expected, $signature)) {
        qaResponse(401, false, 'Invalid access token.', null, 'invalid_token_signature');
    }

    $now = time();
    if (isset($payload['nbf']) && (int)$payload['nbf'] > $now) {
        qaResponse(401, false, 'Access token is not active yet.', null, 'token_not_active');
    }
    if (!isset($payload['exp']) || (int)$payload['exp'] <= $now) {
        qaResponse(401, false, 'Access token has expired. Please sign in again.', null, 'token_expired');
    }
    if (isset($payload['iss']) && (string)$payload['iss'] !== (string)FIELDPLX_TOKEN_ISSUER) {
        qaResponse(401, false, 'Invalid access token.', null, 'invalid_token_issuer');
    }
    if (isset($payload['aud']) && (string)$payload['aud'] !== (string)FIELDPLX_TOKEN_AUDIENCE) {
        qaResponse(401, false, 'Invalid access token.', null, 'invalid_token_audience');
    }
    if (empty($payload['user_id']) || empty($payload['tenant_id'])) {
        qaResponse(401, false, 'Invalid access token.', null, 'invalid_token_context');
    }

    return $payload;
}

function qaRequireAuth(PDO $pdo)
{
    $header = qaAuthorizationHeader();
    if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        qaResponse(401, false, 'Authorization Bearer token is required.', null, 'authorization_required');
    }

    $claims = qaVerifyToken(trim((string)$matches[1]));
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
            r.status AS role_status
         FROM users u
         INNER JOIN tenants t
            ON t.id = u.tenant_id AND t.deleted_at IS NULL
         LEFT JOIN branches b
            ON b.id = u.branch_id AND b.tenant_id = u.tenant_id
         LEFT JOIN departments d
            ON d.id = u.department_id AND d.tenant_id = u.tenant_id
         LEFT JOIN roles r
            ON r.id = u.role_id AND r.tenant_id = u.tenant_id
         WHERE u.id = :user_id
           AND u.tenant_id = :tenant_id
           AND u.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute(array(':user_id' => $userId, ':tenant_id' => $tenantId));
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        qaResponse(401, false, 'Authenticated user account was not found.', null, 'user_not_found');
    }
    if ((string)$user['user_status'] !== 'active') {
        qaResponse(403, false, 'Your user account is not active.', null, 'user_not_active');
    }
    if (!in_array((string)$user['tenant_status'], array('trial', 'active'), true)) {
        qaResponse(403, false, 'This business account is not active.', null, 'tenant_not_active');
    }
    if (!empty($user['branch_id']) && !empty($user['branch_status']) && (string)$user['branch_status'] !== 'active') {
        qaResponse(403, false, 'Your assigned branch is not active.', null, 'branch_not_active');
    }
    if (!empty($user['department_id']) && !empty($user['department_status']) && (string)$user['department_status'] !== 'active') {
        qaResponse(403, false, 'Your assigned department is not active.', null, 'department_not_active');
    }
    if (!empty($user['role_id']) && !empty($user['role_status']) && (string)$user['role_status'] !== 'active') {
        qaResponse(403, false, 'Your assigned role is not active.', null, 'role_not_active');
    }

    return array(
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'branch_id' => !empty($user['branch_id']) ? (int)$user['branch_id'] : 0,
        'role_id' => !empty($user['role_id']) ? (int)$user['role_id'] : 0,
        'user' => $user
    );
}

function qaUserCan(PDO $pdo, $tenantId, $userId, $actionCode)
{
    $actionCode = strtolower(trim((string)$actionCode));
    if ((int)$tenantId <= 0 || (int)$userId <= 0 || $actionCode === '') {
        return false;
    }

    $userStmt = $pdo->prepare(
        "SELECT role_id FROM users
         WHERE id = :user_id AND tenant_id = :tenant_id AND deleted_at IS NULL
         LIMIT 1"
    );
    $userStmt->execute(array(':user_id' => (int)$userId, ':tenant_id' => (int)$tenantId));
    $roleId = (int)$userStmt->fetchColumn();

    $permissionStmt = $pdo->prepare(
        "SELECT p.id
         FROM permissions p
         INNER JOIN modules m ON m.id = p.module_id
         WHERE m.module_code IN ('quotations', 'quotation', 'quotes')
           AND m.is_active = 1
           AND LOWER(p.action_code) = :action_code"
    );
    $permissionStmt->execute(array(':action_code' => $actionCode));
    $permissionIds = array_values(array_unique(array_map('intval', $permissionStmt->fetchAll(PDO::FETCH_COLUMN))));

    if (!$permissionIds) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));

    if (qaTable($pdo, 'user_permissions')) {
        $stmt = $pdo->prepare(
            "SELECT access_type FROM user_permissions
             WHERE tenant_id = ? AND user_id = ? AND permission_id IN ($placeholders)
             ORDER BY CASE access_type WHEN 'deny' THEN 0 ELSE 1 END, permission_id"
        );
        $stmt->execute(array_merge(array((int)$tenantId, (int)$userId), $permissionIds));
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($rows) {
            if (in_array('deny', $rows, true)) {
                return false;
            }
            if (in_array('allow', $rows, true)) {
                return true;
            }
        }
    }

    if ($roleId <= 0 || !qaTable($pdo, 'role_permissions')) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT access_type FROM role_permissions
         WHERE tenant_id = ? AND role_id = ? AND permission_id IN ($placeholders)
         ORDER BY CASE access_type WHEN 'deny' THEN 0 ELSE 1 END, permission_id"
    );
    $stmt->execute(array_merge(array((int)$tenantId, $roleId), $permissionIds));
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$rows || in_array('deny', $rows, true)) {
        return false;
    }

    return in_array('allow', $rows, true);
}

function qaInput()
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
        qaResponse(400, false, 'Invalid request body.', null, 'invalid_request_body');
    }
    $input = array();
    return $input;
}

function qaValue($key, $default = null)
{
    $input = qaInput();
    if (array_key_exists($key, $input)) {
        return $input[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

function qaString($key, $default = '')
{
    return trim((string)qaValue($key, $default));
}

function qaInt($key, $default = 0)
{
    return (int)qaValue($key, $default);
}

function qaValidTenantId(PDO $pdo, $table, $tenantId, $id, $extra = '')
{
    $allowed = array('branches', 'clients', 'client_locations', 'service_requests', 'product_services');
    $id = (int)$id;
    if ($id <= 0 || !in_array($table, $allowed, true) || !qaTable($pdo, $table)) {
        return null;
    }

    $sql = "SELECT id FROM $table WHERE id = :id AND tenant_id = :tenant_id";
    if (qaColumn($pdo, $table, 'deleted_at')) {
        $sql .= " AND deleted_at IS NULL";
    }
    if ($extra !== '') {
        $sql .= ' ' . $extra;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':id' => $id, ':tenant_id' => (int)$tenantId));
    return $stmt->fetchColumn() ? $id : null;
}

function qaCurrency(PDO $pdo, $tenantId, $branchId)
{
    $stmt = $pdo->prepare(
        "SELECT
            c.currency_code,
            c.currency_name,
            c.symbol,
            c.symbol_position,
            c.decimal_places,
            c.decimal_separator,
            c.thousand_separator
         FROM tenants t
         LEFT JOIN branches b
            ON b.id = :branch_id AND b.tenant_id = t.id
         LEFT JOIN currencies c
            ON c.id = COALESCE(b.currency_id, t.currency_id)
         WHERE t.id = :tenant_id
         LIMIT 1"
    );
    $stmt->execute(array(
        ':branch_id' => (int)$branchId > 0 ? (int)$branchId : -1,
        ':tenant_id' => (int)$tenantId
    ));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['currency_code'])) {
        $row['decimal_places'] = (int)$row['decimal_places'];
        return $row;
    }
    return array(
        'currency_code' => 'INR',
        'currency_name' => 'Indian Rupee',
        'symbol' => '₹',
        'symbol_position' => 'before',
        'decimal_places' => 2,
        'decimal_separator' => '.',
        'thousand_separator' => ','
    );
}

function qaPercentChange($current, $previous)
{
    $current = (float)$current;
    $previous = (float)$previous;
    if (abs($previous) < 0.0000001) {
        return $current > 0 ? 100.0 : 0.0;
    }
    return (($current - $previous) / abs($previous)) * 100.0;
}

function qaStats(PDO $pdo, $tenantId)
{
    $stats = array(
        'draft' => 0,
        'awaiting_response' => 0,
        'changes_requested' => 0,
        'approved' => 0,
        'new_quotes_30' => 0,
        'previous_new_quotes_30' => 0,
        'converted_quote_cohort_30' => 0,
        'conversion_rate_30' => 0.0,
        'previous_conversion_rate_30' => 0.0,
        'conversion_rate_change_percent' => 0.0,
        'sent_30' => 0,
        'previous_sent_30' => 0,
        'sent_amount_30' => 0.0,
        'previous_sent_amount_30' => 0.0,
        'sent_change_percent' => 0.0,
        'converted_30' => 0,
        'previous_converted_30' => 0,
        'converted_amount_30' => 0.0,
        'previous_converted_amount_30' => 0.0,
        'converted_change_percent' => 0.0,
        'total_quotes' => 0
    );

    $currentStart = date('Y-m-d', strtotime('-29 days'));
    $currentEnd = date('Y-m-d');
    $previousStart = date('Y-m-d', strtotime('-59 days'));
    $previousEnd = date('Y-m-d', strtotime('-30 days'));

    $overview = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_quotes,
            SUM(status = 'draft') AS draft,
            SUM(status IN ('sent','viewed')) AS awaiting_response,
            SUM(status = 'changes_requested') AS changes_requested,
            SUM(status = 'approved') AS approved
         FROM quotes
         WHERE tenant_id = :tenant_id"
    );
    $overview->execute(array(':tenant_id' => (int)$tenantId));
    $o = $overview->fetch(PDO::FETCH_ASSOC) ?: array();

    foreach (array('total_quotes','draft','awaiting_response','changes_requested','approved') as $key) {
        $stats[$key] = (int)($o[$key] ?? 0);
    }

    $period = $pdo->prepare(
        "SELECT
            SUM(DATE(created_at) BETWEEN :current_start AND :current_end) AS new_quotes_30,
            SUM(DATE(created_at) BETWEEN :previous_start AND :previous_end) AS previous_new_quotes_30,
            SUM(sent_at IS NOT NULL AND DATE(sent_at) BETWEEN :current_start2 AND :current_end2) AS sent_30,
            SUM(CASE WHEN sent_at IS NOT NULL AND DATE(sent_at) BETWEEN :current_start3 AND :current_end3 THEN total ELSE 0 END) AS sent_amount_30,
            SUM(sent_at IS NOT NULL AND DATE(sent_at) BETWEEN :previous_start2 AND :previous_end2) AS previous_sent_30,
            SUM(CASE WHEN sent_at IS NOT NULL AND DATE(sent_at) BETWEEN :previous_start3 AND :previous_end3 THEN total ELSE 0 END) AS previous_sent_amount_30
         FROM quotes
         WHERE tenant_id = :tenant_id"
    );
    $period->execute(array(
        ':current_start' => $currentStart,
        ':current_end' => $currentEnd,
        ':previous_start' => $previousStart,
        ':previous_end' => $previousEnd,
        ':current_start2' => $currentStart,
        ':current_end2' => $currentEnd,
        ':current_start3' => $currentStart,
        ':current_end3' => $currentEnd,
        ':previous_start2' => $previousStart,
        ':previous_end2' => $previousEnd,
        ':previous_start3' => $previousStart,
        ':previous_end3' => $previousEnd,
        ':tenant_id' => (int)$tenantId
    ));
    $p = $period->fetch(PDO::FETCH_ASSOC) ?: array();

    $stats['new_quotes_30'] = (int)($p['new_quotes_30'] ?? 0);
    $stats['previous_new_quotes_30'] = (int)($p['previous_new_quotes_30'] ?? 0);
    $stats['sent_30'] = (int)($p['sent_30'] ?? 0);
    $stats['previous_sent_30'] = (int)($p['previous_sent_30'] ?? 0);
    $stats['sent_amount_30'] = (float)($p['sent_amount_30'] ?? 0);
    $stats['previous_sent_amount_30'] = (float)($p['previous_sent_amount_30'] ?? 0);
    $stats['sent_change_percent'] = round(qaPercentChange($stats['sent_30'], $stats['previous_sent_30']), 2);

    if (qaTable($pdo, 'jobs')) {
        $converted = $pdo->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN DATE(j.created_at) BETWEEN :current_start AND :current_end THEN q.id END) AS converted_30,
                COALESCE(SUM(CASE WHEN DATE(j.created_at) BETWEEN :current_start2 AND :current_end2 THEN q.total ELSE 0 END),0) AS converted_amount_30,
                COUNT(DISTINCT CASE WHEN DATE(j.created_at) BETWEEN :previous_start AND :previous_end THEN q.id END) AS previous_converted_30,
                COALESCE(SUM(CASE WHEN DATE(j.created_at) BETWEEN :previous_start2 AND :previous_end2 THEN q.total ELSE 0 END),0) AS previous_converted_amount_30
             FROM quotes q
             INNER JOIN jobs j
                ON j.quote_id = q.id
               AND j.tenant_id = q.tenant_id
               AND j.deleted_at IS NULL
               AND j.status NOT IN ('cancelled','archived')
             WHERE q.tenant_id = :tenant_id"
        );
        $converted->execute(array(
            ':current_start' => $currentStart,
            ':current_end' => $currentEnd,
            ':current_start2' => $currentStart,
            ':current_end2' => $currentEnd,
            ':previous_start' => $previousStart,
            ':previous_end' => $previousEnd,
            ':previous_start2' => $previousStart,
            ':previous_end2' => $previousEnd,
            ':tenant_id' => (int)$tenantId
        ));
        $c = $converted->fetch(PDO::FETCH_ASSOC) ?: array();
        $stats['converted_30'] = (int)($c['converted_30'] ?? 0);
        $stats['previous_converted_30'] = (int)($c['previous_converted_30'] ?? 0);
        $stats['converted_amount_30'] = (float)($c['converted_amount_30'] ?? 0);
        $stats['previous_converted_amount_30'] = (float)($c['previous_converted_amount_30'] ?? 0);
        $stats['converted_change_percent'] = round(qaPercentChange($stats['converted_30'], $stats['previous_converted_30']), 2);

        $cohort = $pdo->prepare(
            "SELECT COUNT(*)
             FROM quotes q
             WHERE q.tenant_id = :tenant_id
               AND DATE(q.created_at) BETWEEN :start_date AND :end_date
               AND EXISTS (
                    SELECT 1 FROM jobs j
                    WHERE j.tenant_id = q.tenant_id
                      AND j.quote_id = q.id
                      AND j.deleted_at IS NULL
                      AND j.status NOT IN ('cancelled','archived')
               )"
        );
        $cohort->execute(array(':tenant_id'=>(int)$tenantId, ':start_date'=>$currentStart, ':end_date'=>$currentEnd));
        $stats['converted_quote_cohort_30'] = (int)$cohort->fetchColumn();
        $cohort->execute(array(':tenant_id'=>(int)$tenantId, ':start_date'=>$previousStart, ':end_date'=>$previousEnd));
        $previousCohort = (int)$cohort->fetchColumn();

        $stats['conversion_rate_30'] = $stats['new_quotes_30'] > 0
            ? round(($stats['converted_quote_cohort_30'] / $stats['new_quotes_30']) * 100, 2)
            : 0.0;
        $stats['previous_conversion_rate_30'] = $stats['previous_new_quotes_30'] > 0
            ? round(($previousCohort / $stats['previous_new_quotes_30']) * 100, 2)
            : 0.0;
        $stats['conversion_rate_change_percent'] = round(
            qaPercentChange($stats['conversion_rate_30'], $stats['previous_conversion_rate_30']),
            2
        );
    }

    return array(
        'stats' => $stats,
        'periods' => array(
            'previous_label' => date('M j', strtotime($previousStart)) . ' - ' . date('M j', strtotime($previousEnd)),
            'current_label' => date('M j', strtotime($currentStart)) . ' - ' . date('M j', strtotime($currentEnd))
        )
    );
}

function qaNextQuoteNumber(PDO $pdo, $tenantId, $branchId)
{
    if (qaTable($pdo, 'document_sequences')) {
        $separatorColumn = qaColumn($pdo, 'document_sequences', 'number_separator')
            ? 'number_separator'
            : (qaColumn($pdo, 'document_sequences', 'separator') ? 'separator' : null);

        $stmt = $pdo->prepare(
            "SELECT ds.*, b.branch_code
             FROM document_sequences ds
             LEFT JOIN branches b
                ON b.id = ds.branch_id AND b.tenant_id = ds.tenant_id
             WHERE ds.tenant_id = :tenant_id
               AND ds.document_type IN ('quote','quotation')
               AND ds.is_active = 1
               AND (ds.branch_id = :branch_id OR ds.branch_id IS NULL)
             ORDER BY CASE WHEN ds.branch_id = :branch_id2 THEN 0 ELSE 1 END, ds.id
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(array(
            ':tenant_id' => (int)$tenantId,
            ':branch_id' => (int)$branchId > 0 ? (int)$branchId : 0,
            ':branch_id2' => (int)$branchId > 0 ? (int)$branchId : 0
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $now = new DateTime('now');
            $year = $now->format('Y');
            $month = $now->format('m');
            $fyStart = max(1, min(12, (int)($row['financial_year_start_month'] ?? 4)));
            $fyYear = (int)$now->format('n') >= $fyStart ? (int)$year : (int)$year - 1;
            $financialYear = $fyYear . '-' . substr((string)($fyYear + 1), -2);

            $resetPeriod = (string)($row['reset_period'] ?? 'never');
            $key = 'never';
            if ($resetPeriod === 'monthly') $key = $year . $month;
            elseif ($resetPeriod === 'yearly') $key = $year;
            elseif ($resetPeriod === 'financial_year') $key = $financialYear;

            $current = (int)($row['current_number'] ?? 0);
            if ($resetPeriod !== 'never' && (string)($row['last_reset_key'] ?? '') !== $key) {
                $current = 0;
            }
            $next = $current + 1;

            $middle = '';
            $middleFormat = (string)($row['middle_format'] ?? 'none');
            if ($middleFormat === 'year') $middle = $year;
            elseif ($middleFormat === 'year_month') $middle = $year . $month;
            elseif ($middleFormat === 'financial_year') $middle = $financialYear;
            elseif ($middleFormat === 'branch_year') $middle = (!empty($row['branch_code']) ? $row['branch_code'] : 'BR') . $year;

            $parts = array();
            if (!empty($row['prefix'])) $parts[] = $row['prefix'];
            if ($middle !== '') $parts[] = $middle;
            $parts[] = str_pad((string)$next, max(1, (int)($row['number_length'] ?? 6)), '0', STR_PAD_LEFT);
            if (!empty($row['suffix'])) $parts[] = $row['suffix'];

            $separator = '-';
            if ($separatorColumn !== null && isset($row[$separatorColumn])) {
                $separator = (string)$row[$separatorColumn];
            }

            $number = implode($separator, $parts);
            $update = $pdo->prepare(
                "UPDATE document_sequences
                 SET current_number = :current_number, last_reset_key = :last_reset_key
                 WHERE id = :id"
            );
            $update->execute(array(
                ':current_number' => $next,
                ':last_reset_key' => $key,
                ':id' => (int)$row['id']
            ));
            return $number;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(quote_no, '-', -1) AS UNSIGNED))
         FROM quotes
         WHERE tenant_id = :tenant_id AND quote_no LIKE 'QUO-%'"
    );
    $stmt->execute(array(':tenant_id' => (int)$tenantId));
    return 'QUO-' . str_pad((string)((int)$stmt->fetchColumn() + 1), 6, '0', STR_PAD_LEFT);
}

function qaQuote(PDO $pdo, $tenantId, $quoteId)
{
    $linkedJobSelect = qaTable($pdo, 'jobs')
        ? "(SELECT j.id FROM jobs j WHERE j.tenant_id = q.tenant_id AND j.quote_id = q.id AND j.deleted_at IS NULL AND j.status NOT IN ('cancelled','archived') ORDER BY j.id DESC LIMIT 1) AS linked_job_id,
           (SELECT j.job_no FROM jobs j WHERE j.tenant_id = q.tenant_id AND j.quote_id = q.id AND j.deleted_at IS NULL AND j.status NOT IN ('cancelled','archived') ORDER BY j.id DESC LIMIT 1) AS linked_job_no,"
        : "NULL AS linked_job_id, NULL AS linked_job_no,";

    $stmt = $pdo->prepare(
        "SELECT
            q.*,
            c.display_name AS client_name,
            c.company_name AS client_company_name,
            c.email AS client_email,
            c.phone AS client_phone,
            cl.name AS location_name,
            cl.address_line1 AS location_address1,
            cl.address_line2 AS location_address2,
            cl.city AS location_city,
            cl.state AS location_state,
            cl.postal_code AS location_postal_code,
            b.name AS branch_name,
            t.display_name AS tenant_name,
            $linkedJobSelect
            CASE
                WHEN q.status = 'converted' THEN 'converted'
                WHEN q.status IN ('sent','viewed') THEN 'awaiting_response'
                ELSE q.status
            END AS display_status
         FROM quotes q
         INNER JOIN clients c
            ON c.id = q.client_id AND c.tenant_id = q.tenant_id
         LEFT JOIN client_locations cl
            ON cl.id = q.location_id AND cl.tenant_id = q.tenant_id
         LEFT JOIN branches b
            ON b.id = q.branch_id AND b.tenant_id = q.tenant_id
         INNER JOIN tenants t
            ON t.id = q.tenant_id
         WHERE q.id = :quote_id AND q.tenant_id = :tenant_id
         LIMIT 1"
    );
    $stmt->execute(array(':quote_id' => (int)$quoteId, ':tenant_id' => (int)$tenantId));
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        qaResponse(404, false, 'Quotation not found.', null, 'quotation_not_found');
    }

    if (!empty($quote['linked_job_id'])) {
        $quote['display_status'] = 'converted';
    }

    $items = array();
    if (qaTable($pdo, 'quote_line_items')) {
        $itemsStmt = $pdo->prepare(
            "SELECT * FROM quote_line_items
             WHERE quote_id = :quote_id
             ORDER BY sort_order, id"
        );
        $itemsStmt->execute(array(':quote_id' => (int)$quoteId));
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $quote['line_items'] = $items;
    return $quote;
}

function qaNormalizeItems(PDO $pdo, $tenantId, $raw)
{
    $items = is_array($raw) ? $raw : array();
    $out = array();
    $subtotal = 0.0;
    $discountTotal = 0.0;
    $taxTotal = 0.0;
    $total = 0.0;

    foreach ($items as $index => $item) {
        if (!is_array($item)) continue;

        $productServiceId = isset($item['product_service_id']) ? (int)$item['product_service_id'] : 0;
        if ($productServiceId > 0) {
            $productServiceId = qaValidTenantId(
                $pdo,
                'product_services',
                $tenantId,
                $productServiceId,
                "AND status = 'active'"
            );
        } else {
            $productServiceId = null;
        }

        $name = trim((string)($item['item_name'] ?? ''));
        if ($name === '' && $productServiceId) {
            $stmt = $pdo->prepare(
                "SELECT name FROM product_services WHERE id = :id AND tenant_id = :tenant_id LIMIT 1"
            );
            $stmt->execute(array(':id'=>$productServiceId, ':tenant_id'=>(int)$tenantId));
            $name = trim((string)$stmt->fetchColumn());
        }
        if ($name === '') {
            qaResponse(422, false, 'Each quotation item requires an item_name.', null, 'item_name_required');
        }

        $quantity = max(0.001, (float)($item['quantity'] ?? 1));
        $unitCost = max(0, (float)($item['unit_cost'] ?? 0));
        $unitPrice = max(0, (float)($item['unit_price'] ?? 0));
        $base = $quantity * $unitPrice;
        $discount = max(0, min($base, (float)($item['discount_amount'] ?? 0)));
        $taxPercent = max(0, min(100, (float)($item['tax_percent'] ?? 0)));
        $taxable = max(0, $base - $discount);
        $taxAmount = $taxable * $taxPercent / 100;
        $lineTotal = $taxable + $taxAmount;

        $subtotal += $base;
        $discountTotal += $discount;
        $taxTotal += $taxAmount;
        $total += $lineTotal;

        $out[] = array(
            'product_service_id' => $productServiceId,
            'item_name' => substr($name, 0, 255),
            'description' => trim((string)($item['description'] ?? '')),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'unit_price' => $unitPrice,
            'discount_amount' => $discount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
            'is_optional' => !empty($item['is_optional']) ? 1 : 0,
            'sort_order' => $index
        );
    }

    if (!$out) {
        qaResponse(422, false, 'Add at least one quotation item.', null, 'quotation_items_required');
    }

    return array(
        'items' => $out,
        'subtotal' => round($subtotal, 2),
        'discount_total' => round($discountTotal, 2),
        'tax_total' => round($taxTotal, 2),
        'total' => round($total, 2)
    );
}

function qaSaveItems(PDO $pdo, $quoteId, $items)
{
    if (!qaTable($pdo, 'quote_line_items')) {
        qaResponse(500, false, 'quote_line_items table is missing.', null, 'quote_line_items_table_missing');
    }

    $delete = $pdo->prepare("DELETE FROM quote_line_items WHERE quote_id = :quote_id");
    $delete->execute(array(':quote_id' => (int)$quoteId));

    $insert = $pdo->prepare(
        "INSERT INTO quote_line_items (
            quote_id,
            product_service_id,
            item_name,
            description,
            quantity,
            unit_cost,
            unit_price,
            discount_amount,
            tax_percent,
            tax_amount,
            line_total,
            is_optional,
            sort_order
         ) VALUES (
            :quote_id,
            :product_service_id,
            :item_name,
            :description,
            :quantity,
            :unit_cost,
            :unit_price,
            :discount_amount,
            :tax_percent,
            :tax_amount,
            :line_total,
            :is_optional,
            :sort_order
         )"
    );

    foreach ($items as $item) {
        $insert->execute(array(
            ':quote_id' => (int)$quoteId,
            ':product_service_id' => $item['product_service_id'],
            ':item_name' => $item['item_name'],
            ':description' => $item['description'] !== '' ? $item['description'] : null,
            ':quantity' => $item['quantity'],
            ':unit_cost' => $item['unit_cost'],
            ':unit_price' => $item['unit_price'],
            ':discount_amount' => $item['discount_amount'],
            ':tax_percent' => $item['tax_percent'],
            ':tax_amount' => $item['tax_amount'],
            ':line_total' => $item['line_total'],
            ':is_optional' => $item['is_optional'],
            ':sort_order' => $item['sort_order']
        ));
    }
}

function qaLog(PDO $pdo, $tenantId, $branchId, $userId, $eventType, $quoteId, $clientId, $title, $details)
{
    try {
        if (qaTable($pdo, 'activity_events')) {
            $stmt = $pdo->prepare(
                "INSERT INTO activity_events (
                    tenant_id, branch_id, actor_user_id, actor_type,
                    event_type, related_type, related_id, client_id,
                    title, details_json, visible_to_client
                 ) VALUES (
                    :tenant_id, :branch_id, :user_id, 'user',
                    :event_type, 'quote', :related_id, :client_id,
                    :title, :details_json, 0
                 )"
            );
            $stmt->execute(array(
                ':tenant_id'=>(int)$tenantId,
                ':branch_id'=>(int)$branchId>0?(int)$branchId:null,
                ':user_id'=>(int)$userId,
                ':event_type'=>substr((string)$eventType,0,120),
                ':related_id'=>(int)$quoteId,
                ':client_id'=>(int)$clientId>0?(int)$clientId:null,
                ':title'=>substr((string)$title,0,255),
                ':details_json'=>json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ));
        }
    } catch (Throwable $e) {
        error_log('FieldPlx mobile quotation activity error: ' . $e->getMessage());
    }
}

function qaAudit(PDO $pdo, $tenantId, $branchId, $userId, $action, $quoteId, $oldValues, $newValues)
{
    try {
        if (function_exists('tenantAuditLog')) {
            tenantAuditLog(
                $pdo,
                $action,
                (int)$tenantId,
                (int)$branchId > 0 ? (int)$branchId : null,
                (int)$userId,
                'quote',
                (int)$quoteId,
                $oldValues,
                $newValues
            );
        }
    } catch (Throwable $e) {
        error_log('FieldPlx mobile quotation audit error: ' . $e->getMessage());
    }
}

$auth = qaRequireAuth($pdo);
$tenantId = (int)$auth['tenant_id'];
$userId = (int)$auth['user_id'];
$sessionBranchId = (int)$auth['branch_id'];

if (!qaTable($pdo, 'quotes')) {
    qaResponse(500, false, 'quotes table is missing.', null, 'quotes_table_missing');
}

$canView = qaUserCan($pdo, $tenantId, $userId, 'view');
$canCreate = qaUserCan($pdo, $tenantId, $userId, 'create');
$canUpdate = qaUserCan($pdo, $tenantId, $userId, 'update');
$canDelete = qaUserCan($pdo, $tenantId, $userId, 'delete');
$canExport = qaUserCan($pdo, $tenantId, $userId, 'export');

if (!$canView) {
    qaResponse(403, false, 'You do not have permission to view quotations.', null, 'quotation_view_forbidden');
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        if (isset($_GET['meta']) && (int)$_GET['meta'] === 1) {
            $branches = array();
            if (qaTable($pdo, 'branches')) {
                $stmt = $pdo->prepare(
                    "SELECT id, branch_code, name
                     FROM branches
                     WHERE tenant_id = :tenant_id AND status = 'active'
                     ORDER BY is_head_office DESC, name"
                );
                $stmt->execute(array(':tenant_id' => $tenantId));
                $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $clients = array();
            $stmt = $pdo->prepare(
                "SELECT id, display_name, company_name, email, phone, branch_id
                 FROM clients
                 WHERE tenant_id = :tenant_id
                   AND deleted_at IS NULL
                   AND status <> 'archived'
                 ORDER BY display_name"
            );
            $stmt->execute(array(':tenant_id' => $tenantId));
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $services = array();
            if (qaTable($pdo, 'product_services')) {
                $sql = "SELECT id, name, sku, description, unit_cost, unit_price, tax_percent
                        FROM product_services
                        WHERE tenant_id = :tenant_id
                          AND status = 'active'";
                if (qaColumn($pdo, 'product_services', 'deleted_at')) {
                    $sql .= " AND deleted_at IS NULL";
                }
                $sql .= " ORDER BY name";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array(':tenant_id' => $tenantId));
                $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            qaResponse(200, true, 'Quotation metadata loaded successfully.', array(
                'permissions' => array(
                    'view' => $canView,
                    'create' => $canCreate,
                    'update' => $canUpdate,
                    'delete' => $canDelete,
                    'export' => $canExport
                ),
                'statuses' => array(
                    'draft', 'awaiting_response', 'changes_requested', 'approved',
                    'converted', 'internal_approval', 'rejected', 'expired', 'archived'
                ),
                'stored_statuses' => array(
                    'draft', 'sent', 'viewed', 'changes_requested', 'approved',
                    'internal_approval', 'rejected', 'expired', 'archived', 'converted'
                ),
                'date_presets' => array('all','last_week','last_30','last_month','this_month','this_year','last_12','custom'),
                'branches' => $branches,
                'clients' => $clients,
                'services' => $services,
                'currency' => qaCurrency($pdo, $tenantId, $sessionBranchId)
            ));
        }

        $quoteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($quoteId > 0) {
            $quote = qaQuote($pdo, $tenantId, $quoteId);
            $company = !empty($quote['branch_name']) ? $quote['branch_name'] : (!empty($quote['tenant_name']) ? $quote['tenant_name'] : 'FieldPlx');
            $clientName = trim((string)($quote['client_name'] ?? ''));
            $subject = 'Quote from ' . $company . ' - ' . date('M d, Y', strtotime((string)$quote['created_at']));
            $message = "Hi " . $clientName . ",\n\nThank you for asking us to quote on your project.\n\nThe quote total is " . $quote['total'] . " as of " . date('M d, Y', strtotime((string)$quote['created_at'])) . ".\n\nPlease review the quotation and use the secure approval option to Approve or Reject the quote.\n\nIf you have any questions or concerns regarding this quote, please don't hesitate to get in touch with us.\n\nSincerely,\n\n" . $company;

            qaResponse(200, true, 'Quotation loaded successfully.', array(
                'quotation' => $quote,
                'currency' => qaCurrency($pdo, $tenantId, !empty($quote['branch_id']) ? (int)$quote['branch_id'] : $sessionBranchId),
                'permissions' => array(
                    'view'=>$canView, 'create'=>$canCreate, 'update'=>$canUpdate,
                    'delete'=>$canDelete, 'export'=>$canExport
                ),
                'email_context' => array(
                    'available' => !empty($quote['client_email']),
                    'to_email' => $quote['client_email'] ?? '',
                    'subject' => $subject,
                    'message' => $message,
                    'attachment_limit_files' => 10,
                    'attachment_limit_bytes' => 10 * 1024 * 1024
                )
            ));
        }

        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
        if (!in_array($perPage, array(10,25,50), true)) {
            $perPage = 25;
        }
        $search = trim((string)($_GET['search'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $fromDate = trim((string)($_GET['from_date'] ?? ''));
        $toDate = trim((string)($_GET['to_date'] ?? ''));
        $sort = trim((string)($_GET['sort'] ?? 'created'));
        $direction = strtolower(trim((string)($_GET['direction'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';

        $sortMap = array(
            'customer' => 'c.display_name',
            'quote' => 'q.quote_no',
            'property' => 'cl.address_line1',
            'created' => 'q.created_at',
            'status' => 'q.status',
            'total' => 'q.total'
        );
        $sortSql = isset($sortMap[$sort]) ? $sortMap[$sort] : $sortMap['created'];

        $where = array('q.tenant_id = :tenant_id');
        $params = array(':tenant_id' => $tenantId);

        if ($search !== '') {
            $where[] = "(q.quote_no LIKE :search1 OR q.title LIKE :search2 OR c.display_name LIKE :search3 OR c.company_name LIKE :search4 OR cl.address_line1 LIKE :search5 OR cl.city LIKE :search6)";
            $sv = '%' . $search . '%';
            foreach (array(':search1',':search2',':search3',':search4',':search5',':search6') as $key) {
                $params[$key] = $sv;
            }
        }

        $validFilters = array('draft','awaiting_response','changes_requested','approved','converted','internal_approval','rejected','expired','archived');
        if ($status !== '' && in_array($status, $validFilters, true)) {
            if ($status === 'awaiting_response') {
                $where[] = "q.status IN ('sent','viewed')";
            } elseif ($status === 'converted') {
                if (qaTable($pdo, 'jobs')) {
                    $where[] = "(q.status = 'converted' OR EXISTS (SELECT 1 FROM jobs jf WHERE jf.tenant_id = q.tenant_id AND jf.quote_id = q.id AND jf.deleted_at IS NULL AND jf.status NOT IN ('cancelled','archived')))";
                } else {
                    $where[] = "q.status = 'converted'";
                }
            } else {
                $where[] = 'q.status = :status';
                $params[':status'] = $status;
            }
        }

        if ($fromDate !== '') {
            $where[] = 'DATE(q.created_at) >= :from_date';
            $params[':from_date'] = $fromDate;
        }
        if ($toDate !== '') {
            $where[] = 'DATE(q.created_at) <= :to_date';
            $params[':to_date'] = $toDate;
        }

        $whereSql = implode(' AND ', $where);

        $count = $pdo->prepare(
            "SELECT COUNT(*)
             FROM quotes q
             INNER JOIN clients c ON c.id = q.client_id AND c.tenant_id = q.tenant_id
             LEFT JOIN client_locations cl ON cl.id = q.location_id AND cl.tenant_id = q.tenant_id
             WHERE $whereSql"
        );
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $linkedJobSelect = qaTable($pdo, 'jobs')
            ? "(SELECT j.id FROM jobs j WHERE j.tenant_id=q.tenant_id AND j.quote_id=q.id AND j.deleted_at IS NULL AND j.status NOT IN ('cancelled','archived') ORDER BY j.id DESC LIMIT 1) AS linked_job_id,
               (SELECT j.job_no FROM jobs j WHERE j.tenant_id=q.tenant_id AND j.quote_id=q.id AND j.deleted_at IS NULL AND j.status NOT IN ('cancelled','archived') ORDER BY j.id DESC LIMIT 1) AS linked_job_no,"
            : "NULL AS linked_job_id, NULL AS linked_job_no,";

        $sql = "SELECT
                    q.id, q.quote_no, q.title, q.status, q.total, q.created_at, q.sent_at,
                    q.branch_id, q.client_id, q.location_id,
                    c.display_name AS client_name,
                    c.company_name AS client_company_name,
                    c.email AS client_email,
                    cl.name AS location_name,
                    cl.address_line1 AS location_address1,
                    cl.address_line2 AS location_address2,
                    cl.city AS location_city,
                    cl.state AS location_state,
                    cl.postal_code AS location_postal_code,
                    b.name AS branch_name,
                    $linkedJobSelect
                    CASE
                        WHEN q.status IN ('sent','viewed') THEN 'awaiting_response'
                        WHEN q.status = 'converted' THEN 'converted'
                        ELSE q.status
                    END AS display_status
                FROM quotes q
                INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id
                LEFT JOIN client_locations cl ON cl.id=q.location_id AND cl.tenant_id=q.tenant_id
                LEFT JOIN branches b ON b.id=q.branch_id AND b.tenant_id=q.tenant_id
                WHERE $whereSql
                ORDER BY $sortSql $direction, q.id $direction
                LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (!empty($row['linked_job_id'])) {
                $row['display_status'] = 'converted';
            }
        }
        unset($row);

        $statusCounts = array('total' => 0);
        $statusStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(q.status='draft') AS draft,
                SUM(q.status IN ('sent','viewed')) AS awaiting_response,
                SUM(q.status='changes_requested') AS changes_requested,
                SUM(q.status='approved') AS approved,
                SUM(q.status='internal_approval') AS internal_approval,
                SUM(q.status='rejected') AS rejected,
                SUM(q.status='expired') AS expired,
                SUM(q.status='archived') AS archived,
                SUM(q.status='converted') AS converted
             FROM quotes q
             WHERE q.tenant_id = :tenant_id"
        );
        $statusStmt->execute(array(':tenant_id' => $tenantId));
        $statusCounts = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: $statusCounts;
        foreach ($statusCounts as $key => $value) {
            $statusCounts[$key] = (int)$value;
        }

        if (qaTable($pdo, 'jobs')) {
            $convertedStmt = $pdo->prepare(
                "SELECT COUNT(DISTINCT q.id)
                 FROM quotes q
                 INNER JOIN jobs j
                    ON j.quote_id=q.id AND j.tenant_id=q.tenant_id
                   AND j.deleted_at IS NULL AND j.status NOT IN ('cancelled','archived')
                 WHERE q.tenant_id=:tenant_id"
            );
            $convertedStmt->execute(array(':tenant_id'=>$tenantId));
            $statusCounts['converted'] = max($statusCounts['converted'] ?? 0, (int)$convertedStmt->fetchColumn());
        }

        $statData = qaStats($pdo, $tenantId);

        qaResponse(200, true, 'Quotations loaded successfully.', array(
            'quotations' => $rows,
            'status_counts' => $statusCounts,
            'statistics' => $statData['stats'],
            'periods' => $statData['periods'],
            'currency' => qaCurrency($pdo, $tenantId, $sessionBranchId),
            'permissions' => array(
                'view'=>$canView, 'create'=>$canCreate, 'update'=>$canUpdate,
                'delete'=>$canDelete, 'export'=>$canExport
            ),
            'pagination' => array(
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => $total > 0 ? min($offset + count($rows), $total) : 0
            )
        ));
    }

    if ($method === 'POST') {
        if (!$canCreate) {
            qaResponse(403, false, 'You do not have permission to create quotations.', null, 'quotation_create_forbidden');
        }

        $clientId = qaInt('client_id', 0);
        $branchId = qaInt('branch_id', $sessionBranchId);
        $locationId = qaInt('location_id', 0);
        $requestId = qaInt('request_id', 0);
        $title = qaString('title', '');
        $introduction = qaString('introduction', '');
        $status = qaString('status', 'draft');
        $revisionNo = max(0, qaInt('revision_no', 0));

        if ($title === '') {
            qaResponse(422, false, 'Quotation title is required.', null, 'quotation_title_required');
        }
        $clientId = qaValidTenantId($pdo, 'clients', $tenantId, $clientId);
        if ($clientId === null) {
            qaResponse(422, false, 'Select a valid customer.', null, 'invalid_client');
        }
        if ($branchId > 0) {
            $branchId = qaValidTenantId($pdo, 'branches', $tenantId, $branchId, "AND status='active'");
            if ($branchId === null) qaResponse(422, false, 'Select a valid active branch.', null, 'invalid_branch');
        } else {
            $branchId = null;
        }
        if ($locationId > 0) {
            $locationId = qaValidTenantId($pdo, 'client_locations', $tenantId, $locationId);
            if ($locationId === null) qaResponse(422, false, 'Selected property is invalid.', null, 'invalid_location');
            $check = $pdo->prepare("SELECT id FROM client_locations WHERE id=:id AND tenant_id=:tenant_id AND client_id=:client_id AND deleted_at IS NULL LIMIT 1");
            $check->execute(array(':id'=>$locationId, ':tenant_id'=>$tenantId, ':client_id'=>$clientId));
            if (!$check->fetchColumn()) qaResponse(422, false, 'Selected property does not belong to this customer.', null, 'location_client_mismatch');
        } else {
            $locationId = null;
        }
        if ($requestId > 0) {
            $requestId = qaValidTenantId($pdo, 'service_requests', $tenantId, $requestId);
            if ($requestId === null) qaResponse(422, false, 'Selected request is invalid.', null, 'invalid_request');
        } else {
            $requestId = null;
        }

        $allowedStatuses = array('draft','sent','viewed','changes_requested','approved','internal_approval','rejected','expired','archived');
        if (!in_array($status, $allowedStatuses, true)) {
            qaResponse(422, false, 'Invalid quotation status.', null, 'invalid_quotation_status');
        }

        $normalized = qaNormalizeItems($pdo, $tenantId, qaValue('line_items', array()));

        $pdo->beginTransaction();
        try {
            $quoteNo = qaNextQuoteNumber($pdo, $tenantId, $branchId !== null ? $branchId : $sessionBranchId);
            $stmt = $pdo->prepare(
                "INSERT INTO quotes (
                    tenant_id, branch_id, quote_no, revision_no,
                    client_id, location_id, request_id,
                    title, introduction, status,
                    subtotal, discount_total, tax_total, total, created_by
                 ) VALUES (
                    :tenant_id, :branch_id, :quote_no, :revision_no,
                    :client_id, :location_id, :request_id,
                    :title, :introduction, :status,
                    :subtotal, :discount_total, :tax_total, :total, :created_by
                 )"
            );
            $stmt->execute(array(
                ':tenant_id'=>$tenantId,
                ':branch_id'=>$branchId,
                ':quote_no'=>$quoteNo,
                ':revision_no'=>$revisionNo,
                ':client_id'=>$clientId,
                ':location_id'=>$locationId,
                ':request_id'=>$requestId,
                ':title'=>$title,
                ':introduction'=>$introduction !== '' ? $introduction : null,
                ':status'=>$status,
                ':subtotal'=>$normalized['subtotal'],
                ':discount_total'=>$normalized['discount_total'],
                ':tax_total'=>$normalized['tax_total'],
                ':total'=>$normalized['total'],
                ':created_by'=>$userId
            ));
            $quoteId = (int)$pdo->lastInsertId();
            qaSaveItems($pdo, $quoteId, $normalized['items']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $quote = qaQuote($pdo, $tenantId, $quoteId);
        qaLog($pdo, $tenantId, $branchId ?: $sessionBranchId, $userId, 'quotation_created', $quoteId, $clientId, 'Quotation created: ' . $quote['quote_no'], array('total'=>$quote['total']));
        qaAudit($pdo, $tenantId, $branchId ?: $sessionBranchId, $userId, 'QUOTATION_CREATED', $quoteId, null, $quote);

        qaResponse(201, true, 'Quotation created successfully.', array('quotation' => $quote));
    }

    if (in_array($method, array('PUT','PATCH'), true)) {
        if (!$canUpdate) {
            qaResponse(403, false, 'You do not have permission to update quotations.', null, 'quotation_update_forbidden');
        }

        $quoteId = qaInt('id', isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if ($quoteId <= 0) qaResponse(422, false, 'Quotation id is required.', null, 'quotation_id_required');

        $old = qaQuote($pdo, $tenantId, $quoteId);
        if (!empty($old['linked_job_id'])) {
            qaResponse(409, false, 'This quotation is already linked to a job. Update the job/quote workflow instead.', null, 'quotation_linked_to_job');
        }

        $input = qaInput();
        $clientId = array_key_exists('client_id', $input) ? (int)$input['client_id'] : (int)$old['client_id'];
        $branchId = array_key_exists('branch_id', $input) ? (int)$input['branch_id'] : (int)($old['branch_id'] ?? 0);
        $locationId = array_key_exists('location_id', $input) ? (int)$input['location_id'] : (int)($old['location_id'] ?? 0);
        $requestId = array_key_exists('request_id', $input) ? (int)$input['request_id'] : (int)($old['request_id'] ?? 0);
        $title = array_key_exists('title', $input) ? trim((string)$input['title']) : (string)$old['title'];
        $introduction = array_key_exists('introduction', $input) ? trim((string)$input['introduction']) : (string)($old['introduction'] ?? '');
        $status = array_key_exists('status', $input) ? trim((string)$input['status']) : (string)$old['status'];

        if ($title === '') qaResponse(422, false, 'Quotation title is required.', null, 'quotation_title_required');
        $clientId = qaValidTenantId($pdo, 'clients', $tenantId, $clientId);
        if ($clientId === null) qaResponse(422, false, 'Select a valid customer.', null, 'invalid_client');

        if ($branchId > 0) {
            $branchId = qaValidTenantId($pdo, 'branches', $tenantId, $branchId, "AND status='active'");
            if ($branchId === null) qaResponse(422, false, 'Select a valid active branch.', null, 'invalid_branch');
        } else $branchId = null;

        if ($locationId > 0) {
            $locationId = qaValidTenantId($pdo, 'client_locations', $tenantId, $locationId);
            if ($locationId === null) qaResponse(422, false, 'Selected property is invalid.', null, 'invalid_location');
            $check = $pdo->prepare("SELECT id FROM client_locations WHERE id=:id AND tenant_id=:tenant_id AND client_id=:client_id AND deleted_at IS NULL LIMIT 1");
            $check->execute(array(':id'=>$locationId, ':tenant_id'=>$tenantId, ':client_id'=>$clientId));
            if (!$check->fetchColumn()) qaResponse(422, false, 'Selected property does not belong to this customer.', null, 'location_client_mismatch');
        } else $locationId = null;

        if ($requestId > 0) {
            $requestId = qaValidTenantId($pdo, 'service_requests', $tenantId, $requestId);
            if ($requestId === null) qaResponse(422, false, 'Selected request is invalid.', null, 'invalid_request');
        } else $requestId = null;

        $allowedStatuses = array('draft','sent','viewed','changes_requested','approved','internal_approval','rejected','expired','archived','converted');
        if (!in_array($status, $allowedStatuses, true)) qaResponse(422, false, 'Invalid quotation status.', null, 'invalid_quotation_status');

        $normalized = null;
        if (array_key_exists('line_items', $input)) {
            $normalized = qaNormalizeItems($pdo, $tenantId, $input['line_items']);
        }

        $subtotal = $normalized ? $normalized['subtotal'] : (float)$old['subtotal'];
        $discountTotal = $normalized ? $normalized['discount_total'] : (float)$old['discount_total'];
        $taxTotal = $normalized ? $normalized['tax_total'] : (float)$old['tax_total'];
        $total = $normalized ? $normalized['total'] : (float)$old['total'];

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE quotes SET
                    branch_id=:branch_id,
                    client_id=:client_id,
                    location_id=:location_id,
                    request_id=:request_id,
                    title=:title,
                    introduction=:introduction,
                    status=:status,
                    subtotal=:subtotal,
                    discount_total=:discount_total,
                    tax_total=:tax_total,
                    total=:total
                 WHERE id=:quote_id AND tenant_id=:tenant_id"
            );
            $stmt->execute(array(
                ':branch_id'=>$branchId,
                ':client_id'=>$clientId,
                ':location_id'=>$locationId,
                ':request_id'=>$requestId,
                ':title'=>$title,
                ':introduction'=>$introduction !== '' ? $introduction : null,
                ':status'=>$status,
                ':subtotal'=>$subtotal,
                ':discount_total'=>$discountTotal,
                ':tax_total'=>$taxTotal,
                ':total'=>$total,
                ':quote_id'=>$quoteId,
                ':tenant_id'=>$tenantId
            ));
            if ($normalized) qaSaveItems($pdo, $quoteId, $normalized['items']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $quote = qaQuote($pdo, $tenantId, $quoteId);
        qaLog($pdo, $tenantId, $branchId ?: $sessionBranchId, $userId, 'quotation_updated', $quoteId, $clientId, 'Quotation updated: ' . $quote['quote_no'], array('status'=>$quote['status'],'total'=>$quote['total']));
        qaAudit($pdo, $tenantId, $branchId ?: $sessionBranchId, $userId, 'QUOTATION_UPDATED', $quoteId, $old, $quote);

        qaResponse(200, true, 'Quotation updated successfully.', array('quotation' => $quote));
    }

    if ($method === 'DELETE') {
        if (!$canDelete) {
            qaResponse(403, false, 'You do not have permission to delete quotations.', null, 'quotation_delete_forbidden');
        }

        $quoteId = qaInt('id', isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if ($quoteId <= 0) qaResponse(422, false, 'Quotation id is required.', null, 'quotation_id_required');
        $quote = qaQuote($pdo, $tenantId, $quoteId);

        if (!empty($quote['linked_job_id'])) {
            qaResponse(409, false, 'This quotation is linked to a job and cannot be deleted.', array(
                'linked_job_id' => (int)$quote['linked_job_id'],
                'linked_job_no' => $quote['linked_job_no'] ?? null
            ), 'quotation_linked_to_job');
        }

        $pdo->beginTransaction();
        try {
            if (qaTable($pdo, 'quote_line_items')) {
                $stmt = $pdo->prepare("DELETE FROM quote_line_items WHERE quote_id=:quote_id");
                $stmt->execute(array(':quote_id'=>$quoteId));
            }
            $stmt = $pdo->prepare("DELETE FROM quotes WHERE id=:quote_id AND tenant_id=:tenant_id");
            $stmt->execute(array(':quote_id'=>$quoteId, ':tenant_id'=>$tenantId));
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Quotation could not be deleted.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        qaLog($pdo, $tenantId, !empty($quote['branch_id']) ? (int)$quote['branch_id'] : $sessionBranchId, $userId, 'quotation_deleted', $quoteId, (int)$quote['client_id'], 'Quotation deleted: ' . $quote['quote_no'], array('quotation'=>$quote));
        qaAudit($pdo, $tenantId, !empty($quote['branch_id']) ? (int)$quote['branch_id'] : $sessionBranchId, $userId, 'QUOTATION_DELETED', $quoteId, $quote, null);

        qaResponse(200, true, 'Quotation deleted successfully.', array('deleted_quote_id'=>$quoteId));
    }

    qaResponse(405, false, 'Method not allowed.', null, 'method_not_allowed');

} catch (PDOException $e) {
    error_log('FieldPlx mobile quotations PDO error: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        qaResponse(409, false, 'A generated quotation number already exists. Check Number Formatting and retry.', null, 'duplicate_quote_number');
    }
    qaResponse(500, false, 'Unable to process the quotation request.', null, 'database_error');
} catch (Throwable $e) {
    error_log('FieldPlx mobile quotations API error: ' . $e->getMessage());
    qaResponse(500, false, 'Unable to process the quotation request.', null, 'server_error');
}
