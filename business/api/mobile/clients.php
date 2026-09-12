<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Clients API - Updated Customer/Client Form v2
 *
 * Upload:
 *   /business/api/mobile/clients.php
 *
 * Production URL:
 *   https://fieldplx.com/business/api/mobile/clients.php
 *
 * Authentication:
 *   Authorization: Bearer <access_token>
 *
 * Core REST endpoints:
 *   GET    /clients.php
 *   GET    /clients.php?id=10
 *   GET    /clients.php?meta=1
 *   POST   /clients.php
 *   PUT    /clients.php?id=10
 *   PATCH  /clients.php?id=10
 *   DELETE /clients.php?id=10
 *
 * Extra master actions:
 *   POST /clients.php?action=create_lead_source
 *   POST /clients.php?action=create_tax_rate
 *   POST /clients.php?action=create_custom_field
 *
 * Updated v2 support:
 *   - Multiple client phone numbers
 *   - Additional client-level contacts
 *   - Property/location contacts
 *   - Property billing/tax/GPS/access details
 *   - Client + location custom fields
 *   - Communication preferences
 *   - Contact portal access
 *   - Global default Platform SMTP welcome email on client creation
 */

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api-config.php';

if (file_exists(__DIR__ . '/../../includes/audit.php')) {
    require_once __DIR__ . '/../../includes/audit.php';
}

require_once __DIR__ . '/../../includes/platform-smtp.php';

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

function cfRes($status, $ok, $message, $extra = array(), $errorCode = null)
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
        if (!empty($extra)) {
            $payload['data'] = $extra;
        }
    } else {
        $payload = array_merge(
            array('success' => (bool)$ok, 'message' => (string)$message),
            $extra
        );
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cfBase64UrlDecode($data)
{
    $data = (string)$data;
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function cfAuthorizationHeader()
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

function cfVerifyToken($token)
{
    $parts = explode('.', (string)$token);
    if (count($parts) !== 3) {
        cfRes(401, false, 'Invalid access token.', array(), 'invalid_token');
    }
    list($h, $p, $s) = $parts;
    $headerJson = cfBase64UrlDecode($h);
    $payloadJson = cfBase64UrlDecode($p);
    $signature = cfBase64UrlDecode($s);
    if ($headerJson === false || $payloadJson === false || $signature === false) {
        cfRes(401, false, 'Invalid access token.', array(), 'invalid_token');
    }
    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);
    if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'HS256') {
        cfRes(401, false, 'Invalid access token.', array(), 'invalid_token');
    }
    $expected = hash_hmac('sha256', $h . '.' . $p, FIELDPLX_API_SECRET, true);
    if (!hash_equals($expected, $signature)) {
        cfRes(401, false, 'Invalid access token.', array(), 'invalid_token_signature');
    }
    $now = time();
    if (isset($payload['nbf']) && (int)$payload['nbf'] > $now) {
        cfRes(401, false, 'Access token is not active yet.', array(), 'token_not_active');
    }
    if (!isset($payload['exp']) || (int)$payload['exp'] <= $now) {
        cfRes(401, false, 'Access token has expired. Please sign in again.', array(), 'token_expired');
    }
    if (isset($payload['iss']) && (string)$payload['iss'] !== (string)FIELDPLX_TOKEN_ISSUER) {
        cfRes(401, false, 'Invalid access token.', array(), 'invalid_token_issuer');
    }
    if (isset($payload['aud']) && (string)$payload['aud'] !== (string)FIELDPLX_TOKEN_AUDIENCE) {
        cfRes(401, false, 'Invalid access token.', array(), 'invalid_token_audience');
    }
    if (empty($payload['user_id']) || empty($payload['tenant_id'])) {
        cfRes(401, false, 'Invalid access token.', array(), 'invalid_token_context');
    }
    return $payload;
}

function cfRequireAuth(PDO $pdo)
{
    $header = cfAuthorizationHeader();
    if ($header === '' || !preg_match('/^Bearer\\s+(.+)$/i', $header, $m)) {
        cfRes(401, false, 'Authorization Bearer token is required.', array(), 'authorization_required');
    }
    $claims = cfVerifyToken(trim((string)$m[1]));
    $uid = (int)$claims['user_id'];
    $tid = (int)$claims['tenant_id'];
    $stmt = $pdo->prepare("SELECT u.id,u.tenant_id,u.branch_id,u.department_id,u.role_id,u.status user_status,t.status tenant_status,b.status branch_status,d.status department_status,r.status role_status FROM users u INNER JOIN tenants t ON t.id=u.tenant_id AND t.deleted_at IS NULL LEFT JOIN branches b ON b.id=u.branch_id AND b.tenant_id=u.tenant_id LEFT JOIN departments d ON d.id=u.department_id AND d.tenant_id=u.tenant_id LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE u.id=:u AND u.tenant_id=:t AND u.deleted_at IS NULL LIMIT 1");
    $stmt->execute(array(':u'=>$uid, ':t'=>$tid));
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) cfRes(401,false,'Authenticated user account was not found.',array(),'user_not_found');
    if ((string)$user['user_status'] !== 'active') cfRes(403,false,'Your user account is not active.',array(),'user_not_active');
    if (!in_array((string)$user['tenant_status'],array('trial','active'),true)) cfRes(403,false,'This business account is not active.',array(),'tenant_not_active');
    if (!empty($user['branch_id']) && !empty($user['branch_status']) && (string)$user['branch_status'] !== 'active') cfRes(403,false,'Your assigned branch is not active.',array(),'branch_not_active');
    if (!empty($user['department_id']) && !empty($user['department_status']) && (string)$user['department_status'] !== 'active') cfRes(403,false,'Your assigned department is not active.',array(),'department_not_active');
    if (!empty($user['role_id']) && !empty($user['role_status']) && (string)$user['role_status'] !== 'active') cfRes(403,false,'Your assigned role is not active.',array(),'role_not_active');

    if (cfTable($pdo, 'subscriptions')) {
        $sub = $pdo->prepare("SELECT id,plan_id,status,expiry_date FROM subscriptions WHERE tenant_id=:t ORDER BY CASE status WHEN 'active' THEN 1 WHEN 'trial' THEN 2 ELSE 3 END,id DESC LIMIT 1");
        $sub->execute(array(':t'=>$tid));
        $subscription = $sub->fetch(PDO::FETCH_ASSOC);
        if ($subscription) {
            if (!in_array((string)$subscription['status'], array('active','trial'), true)) {
                cfRes(403,false,'Your subscription is not active.',array(),'subscription_not_active');
            }
            if (!empty($subscription['expiry_date']) && (string)$subscription['expiry_date'] < date('Y-m-d')) {
                cfRes(403,false,'Your subscription has expired.',array(),'subscription_expired');
            }
        }
    }

    return array('tenant_id'=>$tid,'user_id'=>$uid,'branch_id'=>!empty($user['branch_id'])?(int)$user['branch_id']:0,'user'=>$user);
}

function cfInput()
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
        cfRes(400,false,'Invalid request body.',array(),'invalid_request_body');
    }
    $input = array();
    return $input;
}

function cfPost($key, $default = '')
{
    $input = cfInput();
    if (array_key_exists($key, $input)) return $input[$key];
    $aliases = array(
        'locations_json' => 'locations',
        'client_contacts_json' => 'client_contacts',
        'phone_numbers_json' => 'phone_numbers',
        'communication_json' => 'communication',
        'customer_custom_values_json' => 'customer_custom_values',
        'options_json' => 'options'
    );
    if (isset($aliases[$key]) && array_key_exists($aliases[$key], $input)) {
        return $input[$aliases[$key]];
    }
    if (isset($_GET[$key])) return $_GET[$key];
    return $default;
}

function cfJson($value)
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function cfTable(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:n");
    $stmt->execute(array(':n' => $table));
    $cache[$table] = (int)$stmt->fetchColumn() > 0;
    return $cache[$table];
}

function cfColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $stmt->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = (int)$stmt->fetchColumn() > 0;
    return $cache[$key];
}

function cfSchemaStatus(PDO $pdo)
{
    $requiredTables = array(
        'client_locations',
        'client_contacts',
        'client_portal_users',
        'client_lead_sources',
        'client_communication_preferences',
        'client_custom_field_definitions',
        'client_custom_field_values',
        'client_location_custom_field_values',
        'client_phone_numbers'
    );
    $requiredColumns = array(
        array('clients', 'title_prefix'),
        array('client_locations', 'tax_rate_id'),
        array('client_locations', 'billing_same_as_property'),
        array('client_locations', 'billing_address_line1'),
        array('client_locations', 'billing_country_id'),
        array('client_contacts', 'location_id'),
        array('client_contacts', 'title_prefix'),
        array('client_contacts', 'role_name'),
        array('client_contacts', 'is_billing_contact'),
        array('client_contacts', 'portal_access'),
        array('client_contacts', 'quote_followups'),
        array('client_contacts', 'invoice_followups'),
        array('client_contacts', 'visit_reminders'),
        array('client_contacts', 'job_close_followups'),
        array('client_phone_numbers', 'phone_number'),
        array('client_phone_numbers', 'phone_type'),
        array('client_phone_numbers', 'receives_messages'),
        array('client_phone_numbers', 'is_primary'),
        array('client_phone_numbers', 'sort_order')
    );

    $missing = array();
    foreach ($requiredTables as $table) {
        if (!cfTable($pdo, $table)) {
            $missing[] = $table;
        }
    }
    foreach ($requiredColumns as $pair) {
        if (!cfTable($pdo, $pair[0]) || !cfColumn($pdo, $pair[0], $pair[1])) {
            $missing[] = $pair[0] . '.' . $pair[1];
        }
    }

    return array(
        'ready' => count($missing) === 0,
        'missing' => $missing,
        'migration' => 'migration_customer_jobber_form_v1.sql and migration_customer_phone_numbers_v2.sql'
    );
}

function cfRequireSchema(PDO $pdo)
{
    $status = cfSchemaStatus($pdo);
    if (!$status['ready']) {
        throw new RuntimeException(
            'Client form database update is required. Run ' . $status['migration'] . ' once. Missing: ' . implode(', ', $status['missing'])
        );
    }
}

function cfClient(PDO $pdo, $tenantId, $clientId)
{
    $stmt = $pdo->prepare("SELECT c.* FROM clients c WHERE c.id=:id AND c.tenant_id=:t AND c.deleted_at IS NULL LIMIT 1");
    $stmt->execute(array(':id' => $clientId, ':t' => $tenantId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        cfRes(404, false, 'Client not found.');
    }
    return $row;
}

function cfPortal(PDO $pdo, $tenantId, $clientId)
{
    if (!cfTable($pdo, 'client_portal_users')) {
        return null;
    }
    $hasPhone = cfColumn($pdo, 'client_portal_users', 'phone');
    $sql = "SELECT id,email," . ($hasPhone ? 'phone' : 'NULL AS phone') . ",status,password_hash
            FROM client_portal_users
            WHERE tenant_id=:t AND client_id=:c AND contact_id IS NULL
            ORDER BY id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':t' => $tenantId, ':c' => $clientId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function cfValidId(PDO $pdo, $table, $tenantId, $id)
{
    $id = (int)$id;
    if ($id <= 0 || !in_array($table, array('branches', 'users'), true)) {
        return null;
    }
    $sql = "SELECT id FROM " . $table . " WHERE id=:id AND tenant_id=:t";
    if ($table === 'users') {
        $sql .= " AND status='active' AND deleted_at IS NULL";
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':id' => $id, ':t' => $tenantId));
    return $stmt->fetchColumn() ? $id : null;
}

function cfCountries(PDO $pdo)
{
    if (!cfTable($pdo, 'countries')) {
        return array();
    }
    return $pdo->query("SELECT id,name,iso2 FROM countries WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

function cfTaxRates(PDO $pdo, $tenantId)
{
    if (!cfTable($pdo, 'product_tax_rates')) {
        return array();
    }
    $hasDefault = cfColumn($pdo, 'product_tax_rates', 'is_default');
    $sql = "SELECT id,tax_name,rate_percent,jurisdiction_name,status," . ($hasDefault ? 'is_default' : '0 AS is_default') . "
            FROM product_tax_rates
            WHERE tenant_id=:t AND status='active'
            ORDER BY " . ($hasDefault ? 'is_default DESC,' : '') . " tax_name,id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':t' => $tenantId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cfLeadSources(PDO $pdo, $tenantId)
{
    $defaults = array('Existing Client', 'Facebook', 'Flyer', 'Google', 'Instagram', 'Other', 'Referral');
    $out = array();
    $seen = array();
    foreach ($defaults as $index => $name) {
        $key = strtolower($name);
        $seen[$key] = true;
        $out[] = array('id' => 0, 'source_name' => $name, 'is_default_option' => 1, 'sort_order' => $index + 1);
    }
    if (cfTable($pdo, 'client_lead_sources')) {
        $stmt = $pdo->prepare("SELECT id,source_name,sort_order FROM client_lead_sources WHERE tenant_id=:t AND status='active' ORDER BY sort_order,source_name,id");
        $stmt->execute(array(':t' => $tenantId));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(trim((string)$row['source_name']));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row['is_default_option'] = 0;
            $out[] = $row;
        }
    }
    return $out;
}

function cfCustomFields(PDO $pdo, $tenantId, $appliesTo)
{
    if (!cfTable($pdo, 'client_custom_field_definitions')) {
        return array();
    }
    $stmt = $pdo->prepare("SELECT id,applies_to,field_name,field_type,is_transferable,default_value,options_json,sort_order
                           FROM client_custom_field_definitions
                           WHERE tenant_id=:t AND applies_to=:a AND status='active'
                           ORDER BY sort_order,field_name,id");
    $stmt->execute(array(':t' => $tenantId, ':a' => $appliesTo));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $options = json_decode((string)$row['options_json'], true);
        $row['options'] = is_array($options) ? array_values($options) : array();
    }
    unset($row);
    return $rows;
}

function cfMeta(PDO $pdo, $tenantId)
{
    $branches = array();
    if (cfTable($pdo, 'branches')) {
        $stmt = $pdo->prepare("SELECT id,name,branch_code FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name");
        $stmt->execute(array(':t' => $tenantId));
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $users = array();
    if (cfTable($pdo, 'users')) {
        $stmt = $pdo->prepare("SELECT id,CONCAT(first_name,CASE WHEN last_name IS NOT NULL AND last_name<>'' THEN CONCAT(' ',last_name) ELSE '' END) name,employee_code,job_title
                               FROM users
                               WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL
                               ORDER BY first_name,last_name");
        $stmt->execute(array(':t' => $tenantId));
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return array(
        'branches' => $branches,
        'users' => $users,
        'countries' => cfCountries($pdo),
        'lead_sources' => cfLeadSources($pdo, $tenantId),
        'tax_rates' => cfTaxRates($pdo, $tenantId),
        'client_custom_fields' => cfCustomFields($pdo, $tenantId, 'client'),
        'location_custom_fields' => cfCustomFields($pdo, $tenantId, 'location'),
        'schema' => cfSchemaStatus($pdo)
    );
}

function cfPreparePhoneNumbers($rows)
{
    if (!is_array($rows)) {
        $rows = array();
    }

    $types = array('main', 'work', 'mobile', 'home', 'fax', 'other');
    $prepared = array();
    $seen = array();
    $primaryIndex = -1;

    foreach ($rows as $row) {
        if (!is_array($row) || !empty($row['_delete'])) {
            continue;
        }

        $number = trim((string)(isset($row['phone_number']) ? $row['phone_number'] : (isset($row['phone']) ? $row['phone'] : '')));
        if ($number === '') {
            continue;
        }
        if (mb_strlen($number, 'UTF-8') > 50) {
            throw new RuntimeException('Phone number cannot exceed 50 characters.');
        }

        $dedupe = strtolower(preg_replace('/\s+/', '', $number));
        if (isset($seen[$dedupe])) {
            throw new RuntimeException('The same phone number cannot be added more than once.');
        }
        $seen[$dedupe] = true;

        $type = strtolower(trim((string)(isset($row['phone_type']) ? $row['phone_type'] : 'main')));
        if (!in_array($type, $types, true)) {
            $type = 'other';
        }

        $prepared[] = array(
            'id' => isset($row['id']) ? (int)$row['id'] : 0,
            'phone_number' => $number,
            'phone_type' => $type,
            'receives_messages' => !empty($row['receives_messages']) ? 1 : 0,
            'is_primary' => !empty($row['is_primary']) ? 1 : 0,
            'sort_order' => count($prepared) + 1
        );

        if (!empty($row['is_primary'])) {
            if ($primaryIndex >= 0) {
                throw new RuntimeException('Only one phone number can be marked as primary.');
            }
            $primaryIndex = count($prepared) - 1;
        }
    }

    if (count($prepared) > 0 && $primaryIndex < 0) {
        $prepared[0]['is_primary'] = 1;
    }

    return $prepared;
}

function cfPhoneSummary($rows)
{
    $primary = '';
    $alternate = '';
    $allowSms = 0;

    foreach ($rows as $row) {
        if (!empty($row['receives_messages'])) {
            $allowSms = 1;
        }
        if (!empty($row['is_primary']) && $primary === '') {
            $primary = (string)$row['phone_number'];
        }
    }

    if ($primary === '' && count($rows) > 0) {
        $primary = (string)$rows[0]['phone_number'];
    }

    foreach ($rows as $row) {
        if ((string)$row['phone_number'] !== $primary) {
            $alternate = (string)$row['phone_number'];
            break;
        }
    }

    return array('phone' => $primary, 'alternate_phone' => $alternate, 'allow_sms' => $allowSms);
}

function cfPhoneNumbers(PDO $pdo, $tenantId, $clientId)
{
    $rows = array();
    if (cfTable($pdo, 'client_phone_numbers')) {
        $stmt = $pdo->prepare("SELECT id,phone_number,phone_type,receives_messages,is_primary,sort_order
                               FROM client_phone_numbers
                               WHERE tenant_id=:t AND client_id=:c
                               ORDER BY is_primary DESC,sort_order,id");
        $stmt->execute(array(':t' => $tenantId, ':c' => $clientId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (count($rows) > 0) {
        return $rows;
    }

    /* Backward compatibility for clients created before multi-phone support. */
    $client = cfClient($pdo, $tenantId, $clientId);
    $sort = 1;
    if (!empty($client['phone'])) {
        $rows[] = array(
            'id' => 0,
            'phone_number' => (string)$client['phone'],
            'phone_type' => 'main',
            'receives_messages' => isset($client['allow_sms']) && (int)$client['allow_sms'] === 0 ? 0 : 1,
            'is_primary' => 1,
            'sort_order' => $sort++
        );
    }
    if (!empty($client['alternate_phone']) && (string)$client['alternate_phone'] !== (string)$client['phone']) {
        $rows[] = array(
            'id' => 0,
            'phone_number' => (string)$client['alternate_phone'],
            'phone_type' => 'other',
            'receives_messages' => isset($client['allow_sms']) && (int)$client['allow_sms'] === 0 ? 0 : 1,
            'is_primary' => count($rows) === 0 ? 1 : 0,
            'sort_order' => $sort++
        );
    }
    return $rows;
}

function cfSyncPhoneNumbers(PDO $pdo, $tenantId, $clientId, $prepared)
{
    if (!cfTable($pdo, 'client_phone_numbers')) {
        throw new RuntimeException('Client phone number storage is not available. Run migration_customer_phone_numbers_v2.sql.');
    }

    $prepared = cfPreparePhoneNumbers($prepared);
    $pdo->prepare("DELETE FROM client_phone_numbers WHERE tenant_id=:t AND client_id=:c")
        ->execute(array(':t' => $tenantId, ':c' => $clientId));

    $insert = $pdo->prepare("INSERT INTO client_phone_numbers
        (tenant_id,client_id,phone_number,phone_type,receives_messages,is_primary,sort_order,created_at,updated_at)
        VALUES(:t,:c,:phone,:type,:messages,:primary,:sort,NOW(),NOW())");

    foreach ($prepared as $row) {
        $insert->execute(array(
            ':t' => $tenantId,
            ':c' => $clientId,
            ':phone' => $row['phone_number'],
            ':type' => $row['phone_type'],
            ':messages' => $row['receives_messages'],
            ':primary' => $row['is_primary'],
            ':sort' => $row['sort_order']
        ));
    }

    return $prepared;
}

function cfCommunication(PDO $pdo, $tenantId, $clientId)
{
    $defaults = array(
        'quote_followups' => 1,
        'invoice_followups' => 1,
        'visit_reminders' => 1,
        'job_close_followups' => 1
    );
    if (!cfTable($pdo, 'client_communication_preferences')) {
        return $defaults;
    }
    $stmt = $pdo->prepare("SELECT quote_followups,invoice_followups,visit_reminders,job_close_followups
                           FROM client_communication_preferences
                           WHERE tenant_id=:t AND client_id=:c LIMIT 1");
    $stmt->execute(array(':t' => $tenantId, ':c' => $clientId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : $defaults;
}

function cfClientCustomValues(PDO $pdo, $tenantId, $clientId)
{
    $out = array();
    if (!cfTable($pdo, 'client_custom_field_values')) {
        return $out;
    }
    $stmt = $pdo->prepare("SELECT field_id,field_value FROM client_custom_field_values WHERE tenant_id=:t AND client_id=:c");
    $stmt->execute(array(':t' => $tenantId, ':c' => $clientId));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(string)$row['field_id']] = (string)$row['field_value'];
    }
    return $out;
}

function cfLocationCustomValues(PDO $pdo, $tenantId, $locationId)
{
    $out = array();
    if (!cfTable($pdo, 'client_location_custom_field_values')) {
        return $out;
    }
    $stmt = $pdo->prepare("SELECT field_id,field_value FROM client_location_custom_field_values WHERE tenant_id=:t AND location_id=:l");
    $stmt->execute(array(':t' => $tenantId, ':l' => $locationId));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(string)$row['field_id']] = (string)$row['field_value'];
    }
    return $out;
}

function cfLocationContacts(PDO $pdo, $tenantId, $clientId, $locationId)
{
    if (!cfTable($pdo, 'client_contacts') || !cfColumn($pdo, 'client_contacts', 'location_id')) {
        return array();
    }
    $stmt = $pdo->prepare("SELECT id,location_id,title_prefix,first_name,last_name,title,role_name,email,phone,is_primary,is_billing_contact,portal_access,quote_followups,invoice_followups,visit_reminders,job_close_followups
                           FROM client_contacts
                           WHERE tenant_id=:t AND client_id=:c AND location_id=:l
                           ORDER BY is_billing_contact DESC,is_primary DESC,id");
    $stmt->execute(array(':t' => $tenantId, ':c' => $clientId, ':l' => $locationId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cfClientContacts(PDO $pdo, $tenantId, $clientId)
{
    if (!cfTable($pdo, 'client_contacts') || !cfColumn($pdo, 'client_contacts', 'location_id')) {
        return array();
    }
    $stmt = $pdo->prepare("SELECT id,location_id,title_prefix,first_name,last_name,title,role_name,email,phone,is_primary,is_billing_contact,portal_access,quote_followups,invoice_followups,visit_reminders,job_close_followups
                           FROM client_contacts
                           WHERE tenant_id=:t AND client_id=:c AND location_id IS NULL
                           ORDER BY is_billing_contact DESC,is_primary DESC,id");
    $stmt->execute(array(':t' => $tenantId, ':c' => $clientId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cfLocations(PDO $pdo, $tenantId, $clientId)
{
    if (!cfTable($pdo, 'client_locations')) {
        return array();
    }
    $stmt = $pdo->prepare("SELECT * FROM client_locations
                           WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL
                           ORDER BY is_primary DESC,FIELD(status,'active','inactive','archived'),id");
    $stmt->execute(array(':t' => $tenantId, ':c' => $clientId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $locationId = (int)$row['id'];
        $row['custom_values'] = cfLocationCustomValues($pdo, $tenantId, $locationId);
        $row['contacts'] = cfLocationContacts($pdo, $tenantId, $clientId, $locationId);
    }
    unset($row);
    return $rows;
}

function cfParseJson($raw, $label, $default)
{
    if (is_array($raw)) {
        return $raw;
    }

    $raw = trim((string)$raw);
    if ($raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid ' . $label . ' data.');
    }

    return $decoded;
}

function cfValidateCountry(PDO $pdo, $countryId)
{
    $countryId = (int)$countryId;
    if ($countryId <= 0) {
        return null;
    }
    if (!cfTable($pdo, 'countries')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM countries WHERE id=:id AND is_active=1 LIMIT 1");
    $stmt->execute(array(':id' => $countryId));
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Invalid country selected.');
    }
    return $countryId;
}

function cfValidateTaxRate(PDO $pdo, $tenantId, $taxRateId)
{
    $taxRateId = (int)$taxRateId;
    if ($taxRateId <= 0) {
        return null;
    }
    if (!cfTable($pdo, 'product_tax_rates')) {
        throw new RuntimeException('Tax rate master is not available.');
    }
    $stmt = $pdo->prepare("SELECT id FROM product_tax_rates WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
    $stmt->execute(array(':id' => $taxRateId, ':t' => $tenantId));
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Invalid tax rate selected.');
    }
    return $taxRateId;
}

function cfSaveCommunication(PDO $pdo, $tenantId, $clientId, $prefs)
{
    $values = array(
        ':t' => $tenantId,
        ':c' => $clientId,
        ':q' => !empty($prefs['quote_followups']) ? 1 : 0,
        ':i' => !empty($prefs['invoice_followups']) ? 1 : 0,
        ':v' => !empty($prefs['visit_reminders']) ? 1 : 0,
        ':j' => !empty($prefs['job_close_followups']) ? 1 : 0
    );
    $stmt = $pdo->prepare("INSERT INTO client_communication_preferences
        (tenant_id,client_id,quote_followups,invoice_followups,visit_reminders,job_close_followups,created_at,updated_at)
        VALUES(:t,:c,:q,:i,:v,:j,NOW(),NOW())
        ON DUPLICATE KEY UPDATE
        quote_followups=VALUES(quote_followups),invoice_followups=VALUES(invoice_followups),visit_reminders=VALUES(visit_reminders),job_close_followups=VALUES(job_close_followups),updated_at=NOW()");
    $stmt->execute($values);
}

function cfSaveClientCustomValues(PDO $pdo, $tenantId, $clientId, $values)
{
    if (!is_array($values)) {
        $values = array();
    }
    $valid = array();
    $stmt = $pdo->prepare("SELECT id FROM client_custom_field_definitions WHERE tenant_id=:t AND applies_to='client' AND status='active'");
    $stmt->execute(array(':t' => $tenantId));
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $valid[(int)$id] = true;
    }

    $pdo->prepare("DELETE FROM client_custom_field_values WHERE tenant_id=:t AND client_id=:c")
        ->execute(array(':t' => $tenantId, ':c' => $clientId));

    $insert = $pdo->prepare("INSERT INTO client_custom_field_values(tenant_id,client_id,field_id,field_value,created_at,updated_at)
                             VALUES(:t,:c,:f,:v,NOW(),NOW())");
    foreach ($values as $fieldId => $value) {
        $fieldId = (int)$fieldId;
        if ($fieldId <= 0 || !isset($valid[$fieldId])) {
            continue;
        }
        $insert->execute(array(
            ':t' => $tenantId,
            ':c' => $clientId,
            ':f' => $fieldId,
            ':v' => (string)$value
        ));
    }
}

function cfSaveLocationCustomValues(PDO $pdo, $tenantId, $locationId, $values)
{
    if (!is_array($values)) {
        $values = array();
    }
    $valid = array();
    $stmt = $pdo->prepare("SELECT id FROM client_custom_field_definitions WHERE tenant_id=:t AND applies_to='location' AND status='active'");
    $stmt->execute(array(':t' => $tenantId));
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $valid[(int)$id] = true;
    }

    $pdo->prepare("DELETE FROM client_location_custom_field_values WHERE tenant_id=:t AND location_id=:l")
        ->execute(array(':t' => $tenantId, ':l' => $locationId));

    $insert = $pdo->prepare("INSERT INTO client_location_custom_field_values(tenant_id,location_id,field_id,field_value,created_at,updated_at)
                             VALUES(:t,:l,:f,:v,NOW(),NOW())");
    foreach ($values as $fieldId => $value) {
        $fieldId = (int)$fieldId;
        if ($fieldId <= 0 || !isset($valid[$fieldId])) {
            continue;
        }
        $insert->execute(array(':t' => $tenantId, ':l' => $locationId, ':f' => $fieldId, ':v' => (string)$value));
    }
}

function cfContactPortal(PDO $pdo, $tenantId, $clientId, $contactId, $email, $phone, $enabled)
{
    if (!cfTable($pdo, 'client_portal_users')) {
        return;
    }
    $stmt = $pdo->prepare("SELECT id,status FROM client_portal_users WHERE tenant_id=:t AND client_id=:c AND contact_id=:contact LIMIT 1");
    $stmt->execute(array(':t' => $tenantId, ':c' => $clientId, ':contact' => $contactId));
    $portal = $stmt->fetch(PDO::FETCH_ASSOC);
    $portalId = $portal ? (int)$portal['id'] : 0;

    if (!$enabled || ($email === '' && $phone === '')) {
        if ($portalId > 0) {
            $pdo->prepare("UPDATE client_portal_users SET status='inactive' WHERE id=:id AND tenant_id=:t")
                ->execute(array(':id' => $portalId, ':t' => $tenantId));
        }
        return;
    }

    if ($email !== '') {
        $check = $pdo->prepare("SELECT id FROM client_portal_users WHERE tenant_id=:t AND email=:e AND id<>:id LIMIT 1");
        $check->execute(array(':t' => $tenantId, ':e' => $email, ':id' => $portalId));
        if ($check->fetchColumn()) {
            throw new RuntimeException('A property contact email is already used for another client portal login.');
        }
    }
    if ($phone !== '') {
        $check = $pdo->prepare("SELECT id FROM client_portal_users WHERE tenant_id=:t AND phone=:p AND id<>:id LIMIT 1");
        $check->execute(array(':t' => $tenantId, ':p' => $phone, ':id' => $portalId));
        if ($check->fetchColumn()) {
            throw new RuntimeException('A property contact phone number is already used for another client portal login.');
        }
    }

    if ($portalId > 0) {
        $status = $portal['status'] === 'active' ? 'active' : 'invited';
        $upd = $pdo->prepare("UPDATE client_portal_users SET email=:e,phone=:p,status=:s WHERE id=:id AND tenant_id=:t");
        $upd->execute(array(
            ':e' => $email !== '' ? $email : null,
            ':p' => $phone !== '' ? $phone : null,
            ':s' => $status,
            ':id' => $portalId,
            ':t' => $tenantId
        ));
    } else {
        $ins = $pdo->prepare("INSERT INTO client_portal_users(tenant_id,client_id,contact_id,email,phone,password_hash,status)
                              VALUES(:t,:c,:contact,:e,:p,NULL,'invited')");
        $ins->execute(array(
            ':t' => $tenantId,
            ':c' => $clientId,
            ':contact' => $contactId,
            ':e' => $email !== '' ? $email : null,
            ':p' => $phone !== '' ? $phone : null
        ));
    }
}

function cfSyncContacts(PDO $pdo, $tenantId, $clientId, $locationId, $rows)
{
    if (!is_array($rows)) {
        $rows = array();
    }

    $locationId = (int)$locationId;
    $isClientLevel = $locationId <= 0;
    $locationWhere = $isClientLevel ? 'location_id IS NULL' : 'location_id=:location_match';
    $allowedTitles = array('', 'Mr.', 'Ms.', 'Mrs.', 'Miss.', 'Dr.');

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = isset($row['id']) ? (int)$row['id'] : 0;
        $delete = !empty($row['_delete']);

        if ($delete) {
            if ($id > 0) {
                $deleteSql = "DELETE FROM client_contacts WHERE id=:id AND tenant_id=:t AND client_id=:c AND " . $locationWhere;
                $deleteParams = array(':id' => $id, ':t' => $tenantId, ':c' => $clientId);
                if (!$isClientLevel) {
                    $deleteParams[':location_match'] = $locationId;
                }
                $pdo->prepare($deleteSql)->execute($deleteParams);
                $pdo->prepare("UPDATE client_portal_users SET status='inactive' WHERE tenant_id=:t AND client_id=:c AND contact_id=:contact")
                    ->execute(array(':t' => $tenantId, ':c' => $clientId, ':contact' => $id));
            }
            continue;
        }

        $titlePrefix = trim((string)(isset($row['title_prefix']) ? $row['title_prefix'] : ''));
        if (!in_array($titlePrefix, $allowedTitles, true)) {
            $titlePrefix = '';
        }
        $first = trim((string)(isset($row['first_name']) ? $row['first_name'] : ''));
        $last = trim((string)(isset($row['last_name']) ? $row['last_name'] : ''));
        $role = trim((string)(isset($row['role_name']) ? $row['role_name'] : ''));
        $email = strtolower(trim((string)(isset($row['email']) ? $row['email'] : '')));
        $phone = trim((string)(isset($row['phone']) ? $row['phone'] : ''));

        if ($first === '' && $last === '' && $email === '' && $phone === '') {
            continue;
        }
        if ($first === '') {
            throw new RuntimeException('Every contact requires a first name.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address for contact ' . $first . '.');
        }

        $values = array(
            ':location' => $isClientLevel ? null : $locationId,
            ':prefix' => $titlePrefix !== '' ? $titlePrefix : null,
            ':first' => $first,
            ':last' => $last !== '' ? $last : null,
            ':title' => $role !== '' ? $role : null,
            ':role' => $role !== '' ? $role : null,
            ':email' => $email !== '' ? $email : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':primary' => !empty($row['is_primary']) ? 1 : 0,
            ':billing' => !empty($row['is_billing_contact']) ? 1 : 0,
            ':portal' => !empty($row['portal_access']) ? 1 : 0,
            ':qf' => !empty($row['quote_followups']) ? 1 : 0,
            ':if' => !empty($row['invoice_followups']) ? 1 : 0,
            ':vr' => !empty($row['visit_reminders']) ? 1 : 0,
            ':jf' => !empty($row['job_close_followups']) ? 1 : 0,
            ':t' => $tenantId,
            ':c' => $clientId
        );

        if ($id > 0) {
            $values[':id'] = $id;
            $updateSql = "UPDATE client_contacts SET
                location_id=:location,title_prefix=:prefix,first_name=:first,last_name=:last,title=:title,role_name=:role,email=:email,phone=:phone,
                is_primary=:primary,is_billing_contact=:billing,portal_access=:portal,quote_followups=:qf,invoice_followups=:if,visit_reminders=:vr,job_close_followups=:jf
                WHERE id=:id AND tenant_id=:t AND client_id=:c AND " . $locationWhere;
            if (!$isClientLevel) {
                $values[':location_match'] = $locationId;
            }
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute($values);
            $contactId = $id;
        } else {
            $stmt = $pdo->prepare("INSERT INTO client_contacts
                (tenant_id,client_id,location_id,title_prefix,first_name,last_name,title,role_name,email,phone,is_primary,is_billing_contact,portal_access,quote_followups,invoice_followups,visit_reminders,job_close_followups,created_at,updated_at)
                VALUES(:t,:c,:location,:prefix,:first,:last,:title,:role,:email,:phone,:primary,:billing,:portal,:qf,:if,:vr,:jf,NOW(),NOW())");
            $stmt->execute($values);
            $contactId = (int)$pdo->lastInsertId();
        }

        cfContactPortal($pdo, $tenantId, $clientId, $contactId, $email, $phone, !empty($row['portal_access']));
    }
}

function cfSyncLocations(PDO $pdo, $tenantId, $clientId, $rows)
{
    if (!is_array($rows)) {
        $rows = array();
    }

    $types = array('home', 'office', 'warehouse', 'factory', 'farm', 'shop', 'site', 'other');
    $statuses = array('active', 'inactive', 'archived');
    $prepared = array();
    $primaryCount = 0;

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['id']) ? (int)$row['id'] : 0;
        if (!empty($row['_delete'])) {
            if ($id > 0) {
                $pdo->prepare("UPDATE client_locations SET status='archived',deleted_at=NOW() WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL")
                    ->execute(array(':id' => $id, ':t' => $tenantId, ':c' => $clientId));
            }
            continue;
        }

        $a1 = trim((string)(isset($row['address_line1']) ? $row['address_line1'] : ''));
        $a2 = trim((string)(isset($row['address_line2']) ? $row['address_line2'] : ''));
        $city = trim((string)(isset($row['city']) ? $row['city'] : ''));
        $state = trim((string)(isset($row['state']) ? $row['state'] : ''));
        $postal = trim((string)(isset($row['postal_code']) ? $row['postal_code'] : ''));
        $hasContacts = !empty($row['contacts']) && is_array($row['contacts']);
        $hasCustom = !empty($row['custom_values']) && is_array($row['custom_values']);
        $hasAnything = $a1 !== '' || $a2 !== '' || $city !== '' || $state !== '' || $postal !== '' || $hasContacts || $hasCustom || !empty($row['tax_rate_id']);
        if (!$hasAnything && $id <= 0) {
            continue;
        }
        if ($a1 === '') {
            throw new RuntimeException('Street 1 is required for every property address that is added.');
        }

        $type = trim((string)(isset($row['location_type']) ? $row['location_type'] : 'other'));
        if (!in_array($type, $types, true)) {
            $type = 'other';
        }
        $status = trim((string)(isset($row['status']) ? $row['status'] : 'active'));
        if (!in_array($status, $statuses, true)) {
            $status = 'active';
        }
        $isPrimary = !empty($row['is_primary']) ? 1 : 0;
        $primaryCount += $isPrimary;

        $name = trim((string)(isset($row['name']) ? $row['name'] : ''));
        if ($name === '') {
            $name = $isPrimary ? 'Primary Property' : 'Property ' . ($index + 1);
        }

        $billingSame = !isset($row['billing_same_as_property']) || !empty($row['billing_same_as_property']) ? 1 : 0;
        $countryId = cfValidateCountry($pdo, isset($row['country_id']) ? $row['country_id'] : 0);
        $billingCountryId = $billingSame ? $countryId : cfValidateCountry($pdo, isset($row['billing_country_id']) ? $row['billing_country_id'] : 0);
        $taxRateId = cfValidateTaxRate($pdo, $tenantId, isset($row['tax_rate_id']) ? $row['tax_rate_id'] : 0);

        $lat = trim((string)(isset($row['latitude']) ? $row['latitude'] : ''));
        $lng = trim((string)(isset($row['longitude']) ? $row['longitude'] : ''));
        if ($lat !== '' && (!is_numeric($lat) || (float)$lat < -90 || (float)$lat > 90)) {
            throw new RuntimeException('Latitude must be between -90 and 90.');
        }
        if ($lng !== '' && (!is_numeric($lng) || (float)$lng < -180 || (float)$lng > 180)) {
            throw new RuntimeException('Longitude must be between -180 and 180.');
        }

        $prepared[] = array(
            'id' => $id,
            'location_type' => $type,
            'name' => $name,
            'address_line1' => $a1,
            'address_line2' => $a2,
            'city' => $city,
            'state' => $state,
            'postal_code' => $postal,
            'country_id' => $countryId,
            'tax_rate_id' => $taxRateId,
            'billing_same_as_property' => $billingSame,
            'billing_address_line1' => $billingSame ? $a1 : trim((string)(isset($row['billing_address_line1']) ? $row['billing_address_line1'] : '')),
            'billing_address_line2' => $billingSame ? $a2 : trim((string)(isset($row['billing_address_line2']) ? $row['billing_address_line2'] : '')),
            'billing_city' => $billingSame ? $city : trim((string)(isset($row['billing_city']) ? $row['billing_city'] : '')),
            'billing_state' => $billingSame ? $state : trim((string)(isset($row['billing_state']) ? $row['billing_state'] : '')),
            'billing_postal_code' => $billingSame ? $postal : trim((string)(isset($row['billing_postal_code']) ? $row['billing_postal_code'] : '')),
            'billing_country_id' => $billingCountryId,
            'latitude' => $lat,
            'longitude' => $lng,
            'contact_name' => trim((string)(isset($row['contact_name']) ? $row['contact_name'] : '')),
            'contact_phone' => trim((string)(isset($row['contact_phone']) ? $row['contact_phone'] : '')),
            'gate_code' => trim((string)(isset($row['gate_code']) ? $row['gate_code'] : '')),
            'access_notes' => trim((string)(isset($row['access_notes']) ? $row['access_notes'] : '')),
            'service_instructions' => trim((string)(isset($row['service_instructions']) ? $row['service_instructions'] : '')),
            'is_primary' => $isPrimary,
            'status' => $status,
            'custom_values' => isset($row['custom_values']) && is_array($row['custom_values']) ? $row['custom_values'] : array(),
            'contacts' => isset($row['contacts']) && is_array($row['contacts']) ? $row['contacts'] : array()
        );
    }

    if (count($prepared) > 0) {
        if ($primaryCount === 0) {
            $prepared[0]['is_primary'] = 1;
        }
        if ($primaryCount > 1) {
            throw new RuntimeException('Only one property address can be primary.');
        }
        $pdo->prepare("UPDATE client_locations SET is_primary=0 WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL")
            ->execute(array(':t' => $tenantId, ':c' => $clientId));
    }

    foreach ($prepared as $row) {
        $params = array(
            ':type' => $row['location_type'],
            ':name' => $row['name'],
            ':a1' => $row['address_line1'],
            ':a2' => $row['address_line2'] !== '' ? $row['address_line2'] : null,
            ':city' => $row['city'] !== '' ? $row['city'] : null,
            ':state' => $row['state'] !== '' ? $row['state'] : null,
            ':postal' => $row['postal_code'] !== '' ? $row['postal_code'] : null,
            ':country' => $row['country_id'],
            ':tax' => $row['tax_rate_id'],
            ':bSame' => $row['billing_same_as_property'],
            ':ba1' => $row['billing_address_line1'] !== '' ? $row['billing_address_line1'] : null,
            ':ba2' => $row['billing_address_line2'] !== '' ? $row['billing_address_line2'] : null,
            ':bcity' => $row['billing_city'] !== '' ? $row['billing_city'] : null,
            ':bstate' => $row['billing_state'] !== '' ? $row['billing_state'] : null,
            ':bpostal' => $row['billing_postal_code'] !== '' ? $row['billing_postal_code'] : null,
            ':bcountry' => $row['billing_country_id'],
            ':lat' => $row['latitude'] !== '' ? $row['latitude'] : null,
            ':lng' => $row['longitude'] !== '' ? $row['longitude'] : null,
            ':contact' => $row['contact_name'] !== '' ? $row['contact_name'] : null,
            ':phone' => $row['contact_phone'] !== '' ? $row['contact_phone'] : null,
            ':gate' => $row['gate_code'] !== '' ? $row['gate_code'] : null,
            ':access' => $row['access_notes'] !== '' ? $row['access_notes'] : null,
            ':instructions' => $row['service_instructions'] !== '' ? $row['service_instructions'] : null,
            ':primary' => $row['is_primary'],
            ':status' => $row['status'],
            ':t' => $tenantId,
            ':c' => $clientId
        );

        if ($row['id'] > 0) {
            $params[':id'] = $row['id'];
            $stmt = $pdo->prepare("UPDATE client_locations SET
                location_type=:type,name=:name,address_line1=:a1,address_line2=:a2,city=:city,state=:state,postal_code=:postal,country_id=:country,
                tax_rate_id=:tax,billing_same_as_property=:bSame,billing_address_line1=:ba1,billing_address_line2=:ba2,billing_city=:bcity,billing_state=:bstate,billing_postal_code=:bpostal,billing_country_id=:bcountry,
                latitude=:lat,longitude=:lng,contact_name=:contact,contact_phone=:phone,gate_code=:gate,access_notes=:access,service_instructions=:instructions,is_primary=:primary,status=:status
                WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL");
            $stmt->execute($params);
            $locationId = $row['id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO client_locations
                (tenant_id,client_id,location_type,name,address_line1,address_line2,city,state,postal_code,country_id,tax_rate_id,billing_same_as_property,billing_address_line1,billing_address_line2,billing_city,billing_state,billing_postal_code,billing_country_id,latitude,longitude,contact_name,contact_phone,gate_code,access_notes,service_instructions,is_primary,status,created_at,updated_at)
                VALUES(:t,:c,:type,:name,:a1,:a2,:city,:state,:postal,:country,:tax,:bSame,:ba1,:ba2,:bcity,:bstate,:bpostal,:bcountry,:lat,:lng,:contact,:phone,:gate,:access,:instructions,:primary,:status,NOW(),NOW())");
            $stmt->execute($params);
            $locationId = (int)$pdo->lastInsertId();
        }

        cfSaveLocationCustomValues($pdo, $tenantId, $locationId, $row['custom_values']);
        cfSyncContacts($pdo, $tenantId, $clientId, $locationId, $row['contacts']);
    }
}

function cfActivity(PDO $pdo, $tenantId, $branchId, $userId, $event, $clientId, $title, $details)
{
    try {
        if (!cfTable($pdo, 'activity_events')) {
            return;
        }
        $stmt = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client)
                               VALUES(:t,:b,:u,'user',:e,'client',:r,:c,:title,:d,0)");
        $stmt->execute(array(
            ':t' => $tenantId,
            ':b' => $branchId > 0 ? $branchId : null,
            ':u' => $userId,
            ':e' => substr($event, 0, 120),
            ':r' => $clientId,
            ':c' => $clientId,
            ':title' => substr($title, 0, 255),
            ':d' => cfJson($details)
        ));
    } catch (Throwable $e) {
        error_log('client activity: ' . $e->getMessage());
    }
}

function cfAudit(PDO $pdo, $tenantId, $branchId, $userId, $action, $clientId, $old, $new)
{
    if (function_exists('tenantAuditLog')) {
        tenantAuditLog($pdo, $action, $tenantId, $branchId, $userId, 'client', $clientId, $old, $new);
    }
}

function cfSmtpConfig(PDO $pdo, $tenantId = 0, $branchId = 0)
{
    /*
     * Keep Client email identical to the Invoice module:
     * - global platform SMTP only
     * - active default configuration only
     * - decryption and transport are handled by includes/platform-smtp.php
     */
    return fieldplxPlatformSmtpConfig($pdo);
}

function cfSendClientMail(PDO $pdo, $tenantId, $branchId, $client, $plainPassword, $isEdit, $portalEnabled, $locations)
{
    $email = trim((string)(isset($client['email']) ? $client['email'] : ''));
    $result = array(
        'status' => 'skipped',
        'notice' => '',
        'recipient' => $email,
        'smtp_id' => 0,
        'smtp_scope' => 'platform',
        'smtp_is_default' => 0
    );

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $result['notice'] = 'Email not sent because the client has no valid email address.';
        return $result;
    }

    $config = cfSmtpConfig($pdo, $tenantId, $branchId);
    if (!$config) {
        $result['notice'] = 'Email not sent because no active default Platform SMTP configuration was found. Configure one Platform SMTP as Active + Default in Master Controls.';
        return $result;
    }

    $result['smtp_id'] = isset($config['id']) ? (int)$config['id'] : 0;
    $result['smtp_is_default'] = !empty($config['is_default']) ? 1 : 0;

    if (
        (isset($config['scope_type']) && (string)$config['scope_type'] !== 'platform') ||
        !empty($config['tenant_id']) ||
        !empty($config['branch_id']) ||
        empty($config['is_default'])
    ) {
        $result['notice'] = 'Email not sent because the selected SMTP configuration is not the global default Platform SMTP.';
        return $result;
    }

    try {
        /* Use the exact same decryptor used by Invoice email / Master Controls. */
        $password = fieldplxDecryptSmtpPassword(
            isset($config['password_encrypted']) ? $config['password_encrypted'] : ''
        );

        if (trim((string)(isset($config['username']) ? $config['username'] : '')) !== '' && trim((string)$password) === '') {
            throw new RuntimeException(
                'SMTP password is empty or could not be decrypted. Edit the default Platform SMTP, enter the password again, save it, and test it once in Master Controls.'
            );
        }

        $subject = $isEdit
            ? 'Your FieldPlx client account was updated'
            : 'Your FieldPlx client account was created';

        $credentials = '';
        if ($portalEnabled) {
            $credentials .= '<p><strong>Client Portal Login</strong></p><ul>';
            if (!empty($client['email'])) {
                $credentials .= '<li>Email: ' . htmlspecialchars((string)$client['email'], ENT_QUOTES, 'UTF-8') . '</li>';
            }
            if (!empty($client['phone'])) {
                $credentials .= '<li>Contact: ' . htmlspecialchars((string)$client['phone'], ENT_QUOTES, 'UTF-8') . '</li>';
            }
            if ($plainPassword !== '') {
                $credentials .= '<li>Password: ' . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $credentials .= '</ul>';
            if ($isEdit && $plainPassword === '') {
                $credentials .= '<p>Your existing password has not been changed.</p>';
            }
        }

        $locationHtml = '';
        if (!empty($locations)) {
            $locationHtml = '<p><strong>Registered Service Locations</strong></p><ul>';
            foreach ($locations as $location) {
                $parts = array_filter(array(
                    isset($location['address_line1']) ? $location['address_line1'] : '',
                    isset($location['address_line2']) ? $location['address_line2'] : '',
                    isset($location['city']) ? $location['city'] : '',
                    isset($location['state']) ? $location['state'] : '',
                    isset($location['postal_code']) ? $location['postal_code'] : ''
                ));
                $locationHtml .= '<li><strong>'
                    . htmlspecialchars((string)(isset($location['name']) ? $location['name'] : 'Property'), ENT_QUOTES, 'UTF-8')
                    . '</strong>'
                    . (!empty($location['is_primary']) ? ' (Primary)' : '')
                    . '<br>'
                    . htmlspecialchars(implode(', ', $parts), ENT_QUOTES, 'UTF-8')
                    . '</li>';
            }
            $locationHtml .= '</ul>';
        }

        $clientName = trim((string)(isset($client['display_name']) ? $client['display_name'] : ''));
        if ($clientName === '') {
            $clientName = 'Client';
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;margin:auto;color:#17334f;background:#fff">'
            . '<div style="padding:20px 22px;background:#001131;color:#fff">'
            . '<div style="font-size:12px;opacity:.82">FieldPlx</div>'
            . '<h2 style="margin:4px 0 0;font-size:22px">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '</div>'
            . '<div style="padding:22px;border:1px solid #e5eaf1;border-top:0">'
            . '<p>Hello ' . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your client profile ' . ($isEdit ? 'has been updated successfully.' : 'has been created successfully.') . '</p>'
            . $locationHtml
            . $credentials
            . '<p style="margin-top:24px">Thank you,<br>FieldPlx</p>'
            . '</div></div>';

        /* Same SMTP sender as Invoice; no attachment is required for the client welcome email. */
        fieldplxSmtpSendWithConfig(
            $config,
            $password,
            $email,
            $subject,
            $html,
            array()
        );

        $result['status'] = 'sent';
        $result['notice'] = 'Account email sent successfully to ' . $email . '.';
        return $result;
    } catch (Throwable $e) {
        $detail = $e->getMessage();
        $smtpContext = 'SMTP config #' . (isset($config['id']) ? (int)$config['id'] : 0)
            . ' [' . (isset($config['host']) ? (string)$config['host'] : '')
            . ':' . (isset($config['port']) ? (int)$config['port'] : 0)
            . ' / ' . (isset($config['encryption']) ? (string)$config['encryption'] : '') . ']';

        error_log('Client account email failed: ' . $smtpContext . ' - ' . $detail);
        $result['status'] = 'failed';
        $result['notice'] = 'Client saved, but email failed: ' . $detail;
        return $result;
    }
}


/* -------------------------------------------------------------------------
 * Mobile REST routing
 * ---------------------------------------------------------------------- */

$auth = cfRequireAuth($pdo);
$tenantId = (int)$auth['tenant_id'];
$userId = (int)$auth['user_id'];
$branchId = (int)$auth['branch_id'];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? cfPost('action', ''))));

try {
    /* ---------------------------------------------------------------------
     * GET metadata / one client / client list
     * ------------------------------------------------------------------ */
    if ($method === 'GET') {
        if (isset($_GET['meta']) && (int)$_GET['meta'] === 1) {
            cfRes(
                200,
                true,
                'Client form data loaded.',
                array('data' => array('meta' => cfMeta($pdo, $tenantId)))
            );
        }

        $clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($clientId > 0) {
            $portal = cfPortal($pdo, $tenantId, $clientId);

            cfRes(
                200,
                true,
                'Client loaded successfully.',
                array(
                    'data' => array(
                        'client' => cfClient($pdo, $tenantId, $clientId),
                        'phone_numbers' => cfPhoneNumbers($pdo, $tenantId, $clientId),
                        'client_contacts' => cfClientContacts($pdo, $tenantId, $clientId),
                        'locations' => cfLocations($pdo, $tenantId, $clientId),
                        'communication' => cfCommunication($pdo, $tenantId, $clientId),
                        'customer_custom_values' => cfClientCustomValues($pdo, $tenantId, $clientId),
                        'portal' => array(
                            'exists' => $portal ? true : false,
                            'email' => $portal ? $portal['email'] : null,
                            'phone' => $portal ? $portal['phone'] : null,
                            'status' => $portal ? $portal['status'] : null
                        ),
                        'meta' => cfMeta($pdo, $tenantId)
                    )
                )
            );
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 10);
        if (!in_array($perPage, array(10, 25, 50), true)) {
            $perPage = 10;
        }

        $search = trim((string)($_GET['search'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $branchFilter = (int)($_GET['branch_id'] ?? 0);
        $sourceFilter = trim((string)($_GET['source'] ?? ''));

        $where = array(
            'c.tenant_id = :tenant_id',
            'c.deleted_at IS NULL'
        );

        $params = array(
            ':tenant_id' => $tenantId
        );

        if ($search !== '') {
            $value = '%' . $search . '%';
            $searchParts = array(
                'c.display_name LIKE :search1',
                'c.company_name LIKE :search2',
                'c.first_name LIKE :search3',
                'c.last_name LIKE :search4',
                'c.email LIKE :search5',
                'c.phone LIKE :search6'
            );

            if (cfTable($pdo, 'client_phone_numbers')) {
                $searchParts[] = "EXISTS (SELECT 1 FROM client_phone_numbers cpn WHERE cpn.tenant_id=c.tenant_id AND cpn.client_id=c.id AND cpn.phone_number LIKE :search7)";
            }

            $where[] = '(' . implode(' OR ', $searchParts) . ')';
            for ($i = 1; $i <= 6; $i++) {
                $params[':search' . $i] = $value;
            }
            if (cfTable($pdo, 'client_phone_numbers')) {
                $params[':search7'] = $value;
            }
        }

        if (in_array($status, array('new', 'active', 'inactive', 'archived'), true)) {
            $where[] = 'c.status = :status';
            $params[':status'] = $status;
        }

        if ($branchFilter > 0) {
            $where[] = 'c.branch_id = :branch_filter';
            $params[':branch_filter'] = $branchFilter;
        }

        if ($sourceFilter !== '') {
            $where[] = 'c.source = :source_filter';
            $params[':source_filter'] = $sourceFilter;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM clients c WHERE $whereSql"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $phoneCountSql = cfTable($pdo, 'client_phone_numbers')
            ? "(SELECT COUNT(*) FROM client_phone_numbers pn WHERE pn.tenant_id=c.tenant_id AND pn.client_id=c.id)"
            : "CASE WHEN c.phone IS NOT NULL AND c.phone<>'' THEN 1 ELSE 0 END";

        $contactCountSql = cfTable($pdo, 'client_contacts')
            ? "(SELECT COUNT(*) FROM client_contacts cc WHERE cc.tenant_id=c.tenant_id AND cc.client_id=c.id)"
            : "0";

        $stmt = $pdo->prepare(
            "SELECT
                c.id,
                c.branch_id,
                c.client_type,
                c.title_prefix,
                c.display_name,
                c.company_name,
                c.first_name,
                c.last_name,
                c.email,
                c.phone,
                c.alternate_phone,
                c.source,
                c.status,
                c.created_at,
                c.updated_at,
                b.name AS branch_name,
                $phoneCountSql AS phone_count,
                $contactCountSql AS contact_count,
                (
                    SELECT COUNT(*)
                    FROM client_locations l
                    WHERE l.tenant_id=c.tenant_id
                      AND l.client_id=c.id
                      AND l.deleted_at IS NULL
                ) AS location_count
             FROM clients c
             LEFT JOIN branches b
                ON b.id=c.branch_id
               AND b.tenant_id=c.tenant_id
             WHERE $whereSql
             ORDER BY c.display_name ASC, c.id DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        cfRes(
            200,
            true,
            'Clients loaded successfully.',
            array(
                'data' => array(
                    'clients' => $rows,
                    'meta' => cfMeta($pdo, $tenantId),
                    'pagination' => array(
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'pages' => $pages,
                        'from' => $total ? $offset + 1 : 0,
                        'to' => $total ? min($offset + count($rows), $total) : 0
                    )
                )
            )
        );
    }

    /* ---------------------------------------------------------------------
     * POST extra master: lead source
     * ------------------------------------------------------------------ */
    if ($method === 'POST' && $action === 'create_lead_source') {
        cfRequireSchema($pdo);

        $name = substr(trim((string)cfPost('source_name', '')), 0, 120);
        if ($name === '') {
            cfRes(422, false, 'Lead source name is required.', array(), 'lead_source_name_required');
        }

        $stmt = $pdo->prepare(
            "SELECT id,source_name
             FROM client_lead_sources
             WHERE tenant_id=:tenant_id
               AND LOWER(source_name)=LOWER(:source_name)
             LIMIT 1"
        );
        $stmt->execute(array(':tenant_id'=>$tenantId, ':source_name'=>$name));
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            cfRes(200, true, 'Lead source already exists.', array('data'=>array('lead_source'=>$existing)));
        }

        $stmt = $pdo->prepare(
            "INSERT INTO client_lead_sources
                (tenant_id,source_name,status,sort_order,created_by,created_at,updated_at)
             VALUES
                (:tenant_id,:source_name,'active',100,:created_by,NOW(),NOW())"
        );
        $stmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':source_name'=>$name,
            ':created_by'=>$userId
        ));

        $id = (int)$pdo->lastInsertId();

        if (function_exists('tenantAuditLog')) {
            tenantAuditLog(
                $pdo,
                'LEAD_SOURCE_CREATED',
                $tenantId,
                $branchId,
                $userId,
                'lead_source',
                $id,
                null,
                array('source_name'=>$name)
            );
        }

        cfRes(
            201,
            true,
            'Lead source created successfully.',
            array('data'=>array('lead_source'=>array('id'=>$id,'source_name'=>$name)))
        );
    }

    /* ---------------------------------------------------------------------
     * POST extra master: tax rate
     * ------------------------------------------------------------------ */
    if ($method === 'POST' && $action === 'create_tax_rate') {
        if (!cfTable($pdo, 'product_tax_rates')) {
            cfRes(500, false, 'Tax rate master is not available.', array(), 'tax_rate_master_missing');
        }

        $name = substr(trim((string)cfPost('name', '')), 0, 120);
        $rate = (float)cfPost('rate_percent', 0);
        $description = substr(trim((string)cfPost('description', '')), 0, 190);
        $isDefault = cfPost('is_default', 0);
        $isDefault = in_array(strtolower(trim((string)$isDefault)), array('1','true','yes','on'), true) ? 1 : 0;

        if ($name === '') {
            cfRes(422, false, 'Tax rate name is required.', array(), 'tax_rate_name_required');
        }
        if ($rate < 0 || $rate > 100) {
            cfRes(422, false, 'Tax rate must be between 0 and 100.', array(), 'invalid_tax_rate');
        }

        $dup = $pdo->prepare(
            "SELECT id FROM product_tax_rates
             WHERE tenant_id=:tenant_id
               AND LOWER(tax_name)=LOWER(:tax_name)
               AND status='active'
             LIMIT 1"
        );
        $dup->execute(array(':tenant_id'=>$tenantId, ':tax_name'=>$name));
        if ($dup->fetchColumn()) {
            cfRes(409, false, 'A tax rate with this name already exists.', array(), 'duplicate_tax_rate');
        }

        $countryCode = 'US';
        if (cfTable($pdo, 'countries') && cfTable($pdo, 'tenants')) {
            $countryStmt = $pdo->prepare(
                "SELECT c.iso2
                 FROM tenants t
                 LEFT JOIN countries c ON c.id=t.country_id
                 WHERE t.id=:tenant_id
                 LIMIT 1"
            );
            $countryStmt->execute(array(':tenant_id'=>$tenantId));
            $candidate = strtoupper(trim((string)$countryStmt->fetchColumn()));
            if (preg_match('/^[A-Z]{2}$/', $candidate)) {
                $countryCode = $candidate;
            }
        }

        $pdo->beginTransaction();
        try {
            $hasDefault = cfColumn($pdo, 'product_tax_rates', 'is_default');
            if ($isDefault && $hasDefault) {
                $pdo->prepare("UPDATE product_tax_rates SET is_default=0 WHERE tenant_id=:tenant_id")
                    ->execute(array(':tenant_id'=>$tenantId));
            }

            if ($hasDefault) {
                $stmt = $pdo->prepare(
                    "INSERT INTO product_tax_rates
                        (tenant_id,tax_name,rate_percent,country_code,state_code,state_name,tax_type,jurisdiction_name,status,is_default,created_by,created_at,updated_at)
                     VALUES
                        (:tenant_id,:tax_name,:rate,:country_code,'',NULL,'other',:description,'active',:is_default,:created_by,NOW(),NOW())"
                );
                $stmt->execute(array(
                    ':tenant_id'=>$tenantId,
                    ':tax_name'=>$name,
                    ':rate'=>$rate,
                    ':country_code'=>$countryCode,
                    ':description'=>$description !== '' ? $description : null,
                    ':is_default'=>$isDefault,
                    ':created_by'=>$userId
                ));
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO product_tax_rates
                        (tenant_id,tax_name,rate_percent,country_code,state_code,state_name,tax_type,jurisdiction_name,status,created_by,created_at,updated_at)
                     VALUES
                        (:tenant_id,:tax_name,:rate,:country_code,'',NULL,'other',:description,'active',:created_by,NOW(),NOW())"
                );
                $stmt->execute(array(
                    ':tenant_id'=>$tenantId,
                    ':tax_name'=>$name,
                    ':rate'=>$rate,
                    ':country_code'=>$countryCode,
                    ':description'=>$description !== '' ? $description : null,
                    ':created_by'=>$userId
                ));
            }

            $taxId = (int)$pdo->lastInsertId();
            $pdo->commit();

            $stmt = $pdo->prepare(
                "SELECT id,tax_name,rate_percent,jurisdiction_name,status," .
                (cfColumn($pdo, 'product_tax_rates', 'is_default') ? 'is_default' : '0 AS is_default') .
                " FROM product_tax_rates WHERE id=:id AND tenant_id=:tenant_id LIMIT 1"
            );
            $stmt->execute(array(':id'=>$taxId, ':tenant_id'=>$tenantId));

            cfRes(201, true, 'Tax rate created successfully.', array('data'=>array('tax_rate'=>$stmt->fetch(PDO::FETCH_ASSOC))));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /* ---------------------------------------------------------------------
     * POST extra master: custom field
     * ------------------------------------------------------------------ */
    if ($method === 'POST' && $action === 'create_custom_field') {
        cfRequireSchema($pdo);

        $appliesTo = trim((string)cfPost('applies_to', 'client'));
        $fieldName = substr(trim((string)cfPost('field_name', '')), 0, 190);
        $fieldType = trim((string)cfPost('field_type', 'text'));
        $transferValue = cfPost('is_transferable', 0);
        $transferable = in_array(strtolower(trim((string)$transferValue)), array('1','true','yes','on'), true) ? 1 : 0;
        $defaultValue = trim((string)cfPost('default_value', ''));
        $options = cfParseJson(cfPost('options_json', array()), 'custom field options', array());

        if (!in_array($appliesTo, array('client','location'), true)) {
            cfRes(422, false, 'Invalid custom field scope.', array(), 'invalid_custom_field_scope');
        }
        if (!in_array($fieldType, array('text','numeric','boolean','area','dropdown'), true)) {
            cfRes(422, false, 'Invalid custom field type.', array(), 'invalid_custom_field_type');
        }
        if ($fieldName === '') {
            cfRes(422, false, 'Custom field name is required.', array(), 'custom_field_name_required');
        }

        $cleanOptions = array();
        if ($fieldType === 'dropdown') {
            foreach ($options as $option) {
                $option = substr(trim((string)$option), 0, 120);
                if ($option !== '' && !in_array($option, $cleanOptions, true)) {
                    $cleanOptions[] = $option;
                }
            }
            if (count($cleanOptions) === 0) {
                cfRes(422, false, 'Add at least one dropdown option.', array(), 'dropdown_options_required');
            }
        }

        $dup = $pdo->prepare(
            "SELECT id
             FROM client_custom_field_definitions
             WHERE tenant_id=:tenant_id
               AND applies_to=:applies_to
               AND LOWER(field_name)=LOWER(:field_name)
               AND status='active'
             LIMIT 1"
        );
        $dup->execute(array(
            ':tenant_id'=>$tenantId,
            ':applies_to'=>$appliesTo,
            ':field_name'=>$fieldName
        ));
        if ($dup->fetchColumn()) {
            cfRes(409, false, 'A custom field with this name already exists.', array(), 'duplicate_custom_field');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO client_custom_field_definitions
                (tenant_id,applies_to,field_name,field_type,is_transferable,default_value,options_json,status,sort_order,created_by,created_at,updated_at)
             VALUES
                (:tenant_id,:applies_to,:field_name,:field_type,:is_transferable,:default_value,:options_json,'active',100,:created_by,NOW(),NOW())"
        );
        $stmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':applies_to'=>$appliesTo,
            ':field_name'=>$fieldName,
            ':field_type'=>$fieldType,
            ':is_transferable'=>$transferable,
            ':default_value'=>$defaultValue !== '' ? $defaultValue : null,
            ':options_json'=>count($cleanOptions) ? cfJson($cleanOptions) : null,
            ':created_by'=>$userId
        ));

        $fieldId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare(
            "SELECT id,applies_to,field_name,field_type,is_transferable,default_value,options_json,sort_order
             FROM client_custom_field_definitions
             WHERE id=:id AND tenant_id=:tenant_id
             LIMIT 1"
        );
        $stmt->execute(array(':id'=>$fieldId, ':tenant_id'=>$tenantId));
        $field = $stmt->fetch(PDO::FETCH_ASSOC);
        $field['options'] = $cleanOptions;

        cfRes(201, true, 'Custom field created successfully.', array('data'=>array('custom_field'=>$field)));
    }

    /* ---------------------------------------------------------------------
     * POST create / PUT-PATCH update
     * ------------------------------------------------------------------ */
    if (in_array($method, array('POST','PUT','PATCH'), true)) {
        cfRequireSchema($pdo);

        $clientId = $method === 'POST'
            ? 0
            : (int)($_GET['id'] ?? cfPost('client_id', 0));

        if ($method !== 'POST' && $clientId <= 0) {
            cfRes(422, false, 'Client id is required.', array(), 'client_id_required');
        }

        $input = cfInput();
        $old = $clientId > 0 ? cfClient($pdo, $tenantId, $clientId) : null;

        $oldSnapshot = null;
        if ($old) {
            $oldSnapshot = array(
                'client'=>$old,
                'phone_numbers'=>cfPhoneNumbers($pdo, $tenantId, $clientId),
                'additional_contacts'=>cfClientContacts($pdo, $tenantId, $clientId),
                'locations'=>cfLocations($pdo, $tenantId, $clientId),
                'communication'=>cfCommunication($pdo, $tenantId, $clientId),
                'custom_values'=>cfClientCustomValues($pdo, $tenantId, $clientId)
            );
        }

        /* Preserve current web-form non-editable legacy fields on edit. */
        $type = $old && isset($old['client_type']) ? trim((string)$old['client_type']) : 'client';
        $status = $old && isset($old['status']) ? trim((string)$old['status']) : 'active';
        $preferred = $old && isset($old['preferred_contact_method']) ? trim((string)$old['preferred_contact_method']) : 'email';
        $taxNumber = $old && isset($old['tax_number']) ? trim((string)$old['tax_number']) : '';
        $manager = $old && !empty($old['account_manager_id']) ? (int)$old['account_manager_id'] : null;
        $allowEmail = $old && array_key_exists('allow_email', $old) ? ((int)$old['allow_email'] === 1 ? 1 : 0) : 1;
        $allowSms = $old && array_key_exists('allow_sms', $old) ? ((int)$old['allow_sms'] === 1 ? 1 : 0) : 1;

        $prefix = trim((string)cfPost('title_prefix', $old['title_prefix'] ?? ''));
        $company = trim((string)cfPost('company_name', $old['company_name'] ?? ''));
        $first = trim((string)cfPost('first_name', $old['first_name'] ?? ''));
        $last = trim((string)cfPost('last_name', $old['last_name'] ?? ''));
        $display = trim((string)cfPost('display_name', $old['display_name'] ?? ''));
        $email = strtolower(trim((string)cfPost('email', $old['email'] ?? '')));
        $legacyPhone = trim((string)cfPost('phone', $old['phone'] ?? ''));
        $legacyAlt = trim((string)cfPost('alternate_phone', $old['alternate_phone'] ?? ''));
        $source = trim((string)cfPost('source', $old['source'] ?? ''));
        $notes = trim((string)cfPost('notes', $old['notes'] ?? ''));

        $branch = $old && !empty($old['branch_id'])
            ? (int)$old['branch_id']
            : cfValidId($pdo, 'branches', $tenantId, $branchId);

        $allowedPrefixes = array('', 'Mr.', 'Ms.', 'Mrs.', 'Miss.', 'Dr.');
        if (!in_array($prefix, $allowedPrefixes, true)) {
            $prefix = '';
        }
        if (!in_array($type, array('lead','client','archived'), true)) {
            $type = 'client';
        }
        if (!in_array($status, array('new','active','inactive','archived'), true)) {
            $status = 'active';
        }
        if (!in_array($preferred, array('email','sms','phone','whatsapp','none'), true)) {
            $preferred = 'email';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            cfRes(422, false, 'Enter a valid email address.', array(), 'invalid_email');
        }

        if ($display === '') {
            $display = trim(implode(' ', array_filter(array($prefix, $first, $last))));
            if ($display === '') {
                $display = $company;
            }
        }
        if ($display === '') {
            cfRes(422, false, 'Enter a first name, last name, or company name.', array(), 'client_name_required');
        }

        if ($email !== '') {
            $sql = "SELECT id FROM clients WHERE tenant_id=:tenant_id AND email=:email AND deleted_at IS NULL";
            $params = array(':tenant_id'=>$tenantId, ':email'=>$email);
            if ($clientId > 0) {
                $sql .= ' AND id<>:id';
                $params[':id'] = $clientId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn()) {
                cfRes(409, false, 'This email is already used by another client.', array(), 'duplicate_client_email');
            }
        }

        $hasPhonePayload = array_key_exists('phone_numbers', $input) || array_key_exists('phone_numbers_json', $input) || (!$old && ($legacyPhone !== '' || $legacyAlt !== ''));
        $hasClientContactsPayload = array_key_exists('client_contacts', $input) || array_key_exists('client_contacts_json', $input);
        $hasLocationsPayload = array_key_exists('locations', $input) || array_key_exists('locations_json', $input);
        $hasCommunicationPayload = array_key_exists('communication', $input) || array_key_exists('communication_json', $input);
        $hasCustomValuesPayload = array_key_exists('customer_custom_values', $input) || array_key_exists('customer_custom_values_json', $input);

        if ($hasPhonePayload) {
            if (array_key_exists('phone_numbers', $input) || array_key_exists('phone_numbers_json', $input)) {
                $phoneNumbers = cfPreparePhoneNumbers(
                    cfParseJson(cfPost('phone_numbers_json', array()), 'phone numbers', array())
                );
            } else {
                $phoneNumbers = array();
                if ($legacyPhone !== '') {
                    $phoneNumbers[] = array(
                        'phone_number'=>$legacyPhone,
                        'phone_type'=>'main',
                        'receives_messages'=>1,
                        'is_primary'=>1
                    );
                }
                if ($legacyAlt !== '' && $legacyAlt !== $legacyPhone) {
                    $phoneNumbers[] = array(
                        'phone_number'=>$legacyAlt,
                        'phone_type'=>'other',
                        'receives_messages'=>1,
                        'is_primary'=>0
                    );
                }
                $phoneNumbers = cfPreparePhoneNumbers($phoneNumbers);
            }
        } else {
            $phoneNumbers = $old
                ? cfPhoneNumbers($pdo, $tenantId, $clientId)
                : array();
        }

        $phoneSummary = cfPhoneSummary($phoneNumbers);
        $phone = $phoneSummary['phone'];
        $alt = $phoneSummary['alternate_phone'];
        if (count($phoneNumbers) > 0) {
            $allowSms = (int)$phoneSummary['allow_sms'];
        } elseif ($hasPhonePayload) {
            $phone = '';
            $alt = '';
            $allowSms = 0;
        }

        $clientContacts = $hasClientContactsPayload
            ? cfParseJson(cfPost('client_contacts_json', array()), 'additional contacts', array())
            : array();

        $locations = $hasLocationsPayload
            ? cfParseJson(cfPost('locations_json', array()), 'property address', array())
            : array();

        $communication = $hasCommunicationPayload
            ? cfParseJson(cfPost('communication_json', array()), 'communication settings', array())
            : ($old ? cfCommunication($pdo, $tenantId, $clientId) : array(
                'quote_followups'=>1,
                'invoice_followups'=>1,
                'visit_reminders'=>1,
                'job_close_followups'=>1
            ));

        $customValues = $hasCustomValuesPayload
            ? cfParseJson(cfPost('customer_custom_values_json', array()), 'client custom fields', array())
            : ($old ? cfClientCustomValues($pdo, $tenantId, $clientId) : array());

        $values = array(
            ':branch'=>$branch,
            ':type'=>$type,
            ':prefix'=>$prefix !== '' ? $prefix : null,
            ':display'=>$display,
            ':company'=>$company !== '' ? $company : null,
            ':first'=>$first !== '' ? $first : null,
            ':last'=>$last !== '' ? $last : null,
            ':email'=>$email !== '' ? $email : null,
            ':phone'=>$phone !== '' ? $phone : null,
            ':alt'=>$alt !== '' ? $alt : null,
            ':source'=>$source !== '' ? $source : null,
            ':pref'=>$preferred,
            ':ae'=>$allowEmail,
            ':as'=>$allowSms,
            ':status'=>$status,
            ':tax'=>$taxNumber !== '' ? $taxNumber : null,
            ':notes'=>$notes !== '' ? $notes : null,
            ':manager'=>$manager
        );

        $pdo->beginTransaction();
        try {
            if ($clientId > 0) {
                $values[':id'] = $clientId;
                $values[':tenant_id'] = $tenantId;

                $stmt = $pdo->prepare(
                    "UPDATE clients SET
                        branch_id=:branch,
                        client_type=:type,
                        title_prefix=:prefix,
                        display_name=:display,
                        company_name=:company,
                        first_name=:first,
                        last_name=:last,
                        email=:email,
                        phone=:phone,
                        alternate_phone=:alt,
                        source=:source,
                        preferred_contact_method=:pref,
                        allow_email=:ae,
                        allow_sms=:as,
                        status=:status,
                        tax_number=:tax,
                        notes=:notes,
                        account_manager_id=:manager
                     WHERE id=:id
                       AND tenant_id=:tenant_id
                       AND deleted_at IS NULL"
                );
                $stmt->execute($values);
            } else {
                $values[':tenant_id'] = $tenantId;
                $values[':created_by'] = $userId;

                $stmt = $pdo->prepare(
                    "INSERT INTO clients
                        (tenant_id,branch_id,client_type,title_prefix,display_name,company_name,first_name,last_name,email,phone,alternate_phone,source,preferred_contact_method,allow_email,allow_sms,status,tax_number,notes,account_manager_id,created_by)
                     VALUES
                        (:tenant_id,:branch,:type,:prefix,:display,:company,:first,:last,:email,:phone,:alt,:source,:pref,:ae,:as,:status,:tax,:notes,:manager,:created_by)"
                );
                $stmt->execute($values);
                $clientId = (int)$pdo->lastInsertId();
            }

            if ($hasCommunicationPayload || !$old) {
                cfSaveCommunication($pdo, $tenantId, $clientId, $communication);
            }

            if ($hasCustomValuesPayload || !$old) {
                cfSaveClientCustomValues($pdo, $tenantId, $clientId, $customValues);
            }

            if ($hasPhonePayload || !$old) {
                cfSyncPhoneNumbers($pdo, $tenantId, $clientId, $phoneNumbers);
            }

            if ($hasClientContactsPayload) {
                cfSyncContacts($pdo, $tenantId, $clientId, null, $clientContacts);
            }

            if ($hasLocationsPayload) {
                cfSyncLocations($pdo, $tenantId, $clientId, $locations);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $new = cfClient($pdo, $tenantId, $clientId);
        $savedPhones = cfPhoneNumbers($pdo, $tenantId, $clientId);
        $savedContacts = cfClientContacts($pdo, $tenantId, $clientId);
        $savedLocations = cfLocations($pdo, $tenantId, $clientId);
        $savedCommunication = cfCommunication($pdo, $tenantId, $clientId);
        $savedCustomValues = cfClientCustomValues($pdo, $tenantId, $clientId);

        $newSnapshot = array(
            'client'=>$new,
            'phone_numbers'=>$savedPhones,
            'additional_contacts'=>$savedContacts,
            'locations'=>$savedLocations,
            'communication'=>$savedCommunication,
            'custom_values'=>$savedCustomValues
        );

        cfActivity(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            $old ? 'client_updated' : 'client_created',
            $clientId,
            ($old ? 'Client updated: ' : 'Client created: ') . $display,
            array(
                'client'=>$new,
                'phone_count'=>count($savedPhones),
                'additional_contact_count'=>count($savedContacts),
                'location_count'=>count($savedLocations)
            )
        );

        cfAudit(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            $old ? 'CLIENT_UPDATED' : 'CLIENT_CREATED',
            $clientId,
            $oldSnapshot,
            $newSnapshot
        );

        /* Current web behavior: welcome email only when a NEW client is created. */
        $mail = array('status'=>'not_applicable', 'notice'=>'', 'recipient'=>$email);
        if (!$old) {
            $mail = cfSendClientMail(
                $pdo,
                $tenantId,
                $branch ? $branch : $branchId,
                $new,
                '',
                false,
                false,
                $savedLocations
            );

            $mailAction = $mail['status'] === 'sent'
                ? 'CLIENT_WELCOME_EMAIL_SENT'
                : ($mail['status'] === 'failed'
                    ? 'CLIENT_WELCOME_EMAIL_FAILED'
                    : 'CLIENT_WELCOME_EMAIL_SKIPPED');

            $mailEvent = $mail['status'] === 'sent'
                ? 'client_welcome_email_sent'
                : ($mail['status'] === 'failed'
                    ? 'client_welcome_email_failed'
                    : 'client_welcome_email_skipped');

            $mailDetails = array(
                'recipient'=>isset($mail['recipient']) && $mail['recipient'] !== '' ? $mail['recipient'] : ($email !== '' ? $email : null),
                'smtp_config_id'=>isset($mail['smtp_id']) ? (int)$mail['smtp_id'] : null,
                'smtp_scope'=>'platform',
                'smtp_is_default'=>isset($mail['smtp_is_default']) ? (int)$mail['smtp_is_default'] : 0,
                'status'=>$mail['status'],
                'notice'=>$mail['notice']
            );

            cfActivity(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                $mailEvent,
                $clientId,
                'Client welcome email: ' . $mail['status'],
                $mailDetails
            );

            cfAudit(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                $mailAction,
                $clientId,
                null,
                $mailDetails
            );
        }

        cfRes(
            $old ? 200 : 201,
            true,
            $old ? 'Client updated successfully.' : 'Client created successfully.',
            array(
                'data'=>array(
                    'client_id'=>$clientId,
                    'client'=>$new,
                    'phone_numbers'=>$savedPhones,
                    'client_contacts'=>$savedContacts,
                    'locations'=>$savedLocations,
                    'communication'=>$savedCommunication,
                    'customer_custom_values'=>$savedCustomValues,
                    'email_status'=>$mail['status'],
                    'email_notice'=>$mail['notice']
                )
            )
        );
    }

    /* ---------------------------------------------------------------------
     * DELETE = soft archive client
     * ------------------------------------------------------------------ */
    if ($method === 'DELETE') {
        $clientId = (int)($_GET['id'] ?? 0);
        if ($clientId <= 0) {
            cfRes(422, false, 'Client id is required.', array(), 'client_id_required');
        }

        $old = cfClient($pdo, $tenantId, $clientId);
        $oldSnapshot = array(
            'client'=>$old,
            'phone_numbers'=>cfPhoneNumbers($pdo, $tenantId, $clientId),
            'additional_contacts'=>cfClientContacts($pdo, $tenantId, $clientId),
            'locations'=>cfLocations($pdo, $tenantId, $clientId),
            'communication'=>cfCommunication($pdo, $tenantId, $clientId)
        );

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE clients
                 SET status='archived', deleted_at=NOW()
                 WHERE id=:id AND tenant_id=:tenant_id AND deleted_at IS NULL"
            )->execute(array(':id'=>$clientId, ':tenant_id'=>$tenantId));

            if (cfTable($pdo, 'client_locations')) {
                $pdo->prepare(
                    "UPDATE client_locations
                     SET status='archived', deleted_at=NOW()
                     WHERE tenant_id=:tenant_id AND client_id=:client_id AND deleted_at IS NULL"
                )->execute(array(':tenant_id'=>$tenantId, ':client_id'=>$clientId));
            }

            if (cfTable($pdo, 'client_portal_users')) {
                $pdo->prepare(
                    "UPDATE client_portal_users
                     SET status='inactive'
                     WHERE tenant_id=:tenant_id AND client_id=:client_id"
                )->execute(array(':tenant_id'=>$tenantId, ':client_id'=>$clientId));
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $deletedState = array(
            'status'=>'archived',
            'deleted_at'=>date('Y-m-d H:i:s')
        );

        cfActivity(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'client_deleted',
            $clientId,
            'Client deleted: ' . $old['display_name'],
            array('client'=>$oldSnapshot)
        );

        cfAudit(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'CLIENT_DELETED',
            $clientId,
            $oldSnapshot,
            $deletedState
        );

        cfRes(
            200,
            true,
            'Client deleted successfully.',
            array('data'=>array('deleted_client_id'=>$clientId))
        );
    }

    cfRes(405, false, 'Method not allowed.', array(), 'method_not_allowed');

} catch (PDOException $e) {
    error_log('FieldPlx mobile clients PDO error: ' . $e->getMessage());

    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        cfRes(
            409,
            false,
            'A client, phone, contact, lead source, or portal login already uses this unique value.',
            array(),
            'duplicate_record'
        );
    }

    cfRes(500, false, 'Unable to process the client request.', array(), 'database_error');
} catch (Throwable $e) {
    error_log('FieldPlx mobile clients API error: ' . $e->getMessage());
    cfRes(500, false, $e->getMessage(), array(), 'server_error');
}
