<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Workflow Builder API
 *
 * Upload to:
 *   /business/api/mobile/workflows.php
 *
 * Production URL:
 *   https://fieldplx.com/business/api/mobile/workflows.php
 *
 * Authentication:
 *   Authorization: Bearer <access_token>
 *
 * REST endpoints:
 *   GET    /workflows.php
 *   GET    /workflows.php?id=10
 *   GET    /workflows.php?meta=1
 *   POST   /workflows.php
 *   POST   /workflows.php?duplicate_id=10
 *   PUT    /workflows.php?id=10
 *   PATCH  /workflows.php?id=10
 *   DELETE /workflows.php?id=10
 *
 * DELETE archives the workflow instead of permanently deleting it.
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
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!defined('FIELDPLX_TOKEN_ISSUER')) {
    define('FIELDPLX_TOKEN_ISSUER', 'FieldPlx');
}

if (!defined('FIELDPLX_TOKEN_AUDIENCE')) {
    define('FIELDPLX_TOKEN_AUDIENCE', 'FieldPlx-Mobile');
}

function wfResponse($status, $success, $message, $data = null, $errorCode = null)
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

function wfB64Decode($data)
{
    $data = (string)$data;
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function wfAuthorizationHeader()
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

function wfBearerToken()
{
    $header = wfAuthorizationHeader();
    if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return '';
    }
    return trim((string)$m[1]);
}

function wfVerifyToken($token)
{
    $parts = explode('.', (string)$token);
    if (count($parts) !== 3) {
        wfResponse(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    $headerEncoded = $parts[0];
    $payloadEncoded = $parts[1];
    $signatureEncoded = $parts[2];

    $headerJson = wfB64Decode($headerEncoded);
    $payloadJson = wfB64Decode($payloadEncoded);
    $signature = wfB64Decode($signatureEncoded);

    if ($headerJson === false || $payloadJson === false || $signature === false) {
        wfResponse(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        wfResponse(401, false, 'Invalid access token.', null, 'invalid_token');
    }

    if (($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT') {
        wfResponse(401, false, 'Unsupported access token.', null, 'invalid_token');
    }

    $expected = hash_hmac(
        'sha256',
        $headerEncoded . '.' . $payloadEncoded,
        FIELDPLX_API_SECRET,
        true
    );

    if (!hash_equals($expected, $signature)) {
        wfResponse(401, false, 'Invalid access token.', null, 'invalid_token_signature');
    }

    $now = time();
    if (isset($payload['nbf']) && (int)$payload['nbf'] > $now) {
        wfResponse(401, false, 'Access token is not active yet.', null, 'token_not_active');
    }
    if (!isset($payload['exp']) || (int)$payload['exp'] <= $now) {
        wfResponse(401, false, 'Access token has expired. Please sign in again.', null, 'token_expired');
    }
    if (isset($payload['iss']) && (string)$payload['iss'] !== (string)FIELDPLX_TOKEN_ISSUER) {
        wfResponse(401, false, 'Invalid access token.', null, 'invalid_token_issuer');
    }
    if (isset($payload['aud']) && (string)$payload['aud'] !== (string)FIELDPLX_TOKEN_AUDIENCE) {
        wfResponse(401, false, 'Invalid access token.', null, 'invalid_token_audience');
    }
    if (empty($payload['user_id']) || empty($payload['tenant_id'])) {
        wfResponse(401, false, 'Invalid access token.', null, 'invalid_token_context');
    }

    return $payload;
}

function wfTableExists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name"
    );
    $stmt->execute(array(':table_name' => (string)$table));
    return (int)$stmt->fetchColumn() > 0;
}

function wfBuilderSchemaReady(PDO $pdo)
{
    return wfTableExists($pdo, 'workflow_step_fields') &&
           wfTableExists($pdo, 'workflow_field_options');
}

function wfRequireBuilderSchema(PDO $pdo)
{
    if (!wfBuilderSchemaReady($pdo)) {
        wfResponse(
            409,
            false,
            'Workflow Builder database update is missing. Run migration_workflow_builder.sql once.',
            null,
            'workflow_builder_schema_missing'
        );
    }
}

function wfRequireAuth(PDO $pdo)
{
    $token = wfBearerToken();
    if ($token === '') {
        wfResponse(401, false, 'Authorization Bearer token is required.', null, 'authorization_required');
    }

    $claims = wfVerifyToken($token);
    $userId = (int)$claims['user_id'];
    $tenantId = (int)$claims['tenant_id'];

    $stmt = $pdo->prepare(
        "SELECT
            u.id AS user_id,
            u.tenant_id,
            u.branch_id,
            u.department_id,
            u.role_id,
            u.status AS user_status,
            t.status AS tenant_status,
            b.status AS branch_status,
            d.status AS department_status,
            r.status AS role_status
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

    $stmt->execute(array(
        ':user_id' => $userId,
        ':tenant_id' => $tenantId
    ));

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        wfResponse(401, false, 'Authenticated user account was not found.', null, 'user_not_found');
    }
    if ((string)$user['user_status'] !== 'active') {
        wfResponse(403, false, 'Your user account is not active.', null, 'user_not_active');
    }
    if (!in_array((string)$user['tenant_status'], array('trial', 'active'), true)) {
        wfResponse(403, false, 'This business account is not active.', null, 'tenant_not_active');
    }
    if (!empty($user['branch_id']) && !empty($user['branch_status']) && (string)$user['branch_status'] !== 'active') {
        wfResponse(403, false, 'Your assigned branch is not active.', null, 'branch_not_active');
    }
    if (!empty($user['department_id']) && !empty($user['department_status']) && (string)$user['department_status'] !== 'active') {
        wfResponse(403, false, 'Your assigned department is not active.', null, 'department_not_active');
    }
    if (!empty($user['role_id']) && !empty($user['role_status']) && (string)$user['role_status'] !== 'active') {
        wfResponse(403, false, 'Your assigned role is not active.', null, 'role_not_active');
    }

    return array(
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'branch_id' => !empty($user['branch_id']) ? (int)$user['branch_id'] : 0
    );
}

function wfInput()
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
        wfResponse(400, false, 'Invalid request body.', null, 'invalid_request_body');
    }

    $input = array();
    return $input;
}

function wfValue($key, $default = null)
{
    $input = wfInput();
    if (array_key_exists($key, $input)) {
        return $input[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

function wfString($key, $default = '')
{
    return trim((string)wfValue($key, $default));
}

function wfInt($key, $default = 0)
{
    return (int)wfValue($key, $default);
}

function wfBoolValue($value)
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 1;
    }
    return in_array(strtolower(trim((string)$value)), array('1', 'true', 'yes', 'on'), true);
}

function wfSlug($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim((string)$value, '_');
}

function wfJson($value)
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function wfTenantWorkflow(PDO $pdo, $tenantId, $id)
{
    $stmt = $pdo->prepare(
        "SELECT
            w.*,
            sw.product_service_id AS service_id,
            ps.name AS service_name,
            ps.sku AS service_sku
         FROM workflows w
         LEFT JOIN service_workflows sw
            ON sw.workflow_id = w.id
         LEFT JOIN product_services ps
            ON ps.id = sw.product_service_id
           AND ps.tenant_id = w.tenant_id
         WHERE w.id = :id
           AND w.tenant_id = :tenant_id
         ORDER BY sw.is_default DESC
         LIMIT 1"
    );

    $stmt->execute(array(
        ':id' => (int)$id,
        ':tenant_id' => (int)$tenantId
    ));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        wfResponse(404, false, 'Workflow not found.', null, 'workflow_not_found');
    }
    return $row;
}

function wfServices(PDO $pdo, $tenantId)
{
    $stmt = $pdo->prepare(
        "SELECT id, name, sku
         FROM product_services
         WHERE tenant_id = :tenant_id
           AND item_type = 'service'
           AND status = 'active'
           AND deleted_at IS NULL
         ORDER BY name"
    );
    $stmt->execute(array(':tenant_id' => (int)$tenantId));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function wfFullSteps(PDO $pdo, $tenantId, $workflowId)
{
    wfRequireBuilderSchema($pdo);

    $stmt = $pdo->prepare(
        "SELECT ws.*
         FROM workflow_steps ws
         INNER JOIN workflows w
            ON w.id = ws.workflow_id
           AND w.tenant_id = :tenant_id
         WHERE ws.workflow_id = :workflow_id
         ORDER BY ws.sort_order, ws.id"
    );
    $stmt->execute(array(
        ':tenant_id' => (int)$tenantId,
        ':workflow_id' => (int)$workflowId
    ));
    $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $fieldStmt = $pdo->prepare(
        "SELECT *
         FROM workflow_step_fields
         WHERE tenant_id = :tenant_id
           AND workflow_step_id = :step_id
           AND status = 'active'
         ORDER BY sort_order, id"
    );

    $optionStmt = $pdo->prepare(
        "SELECT *
         FROM workflow_field_options
         WHERE workflow_field_id = :field_id
           AND status = 'active'
         ORDER BY sort_order, id"
    );

    foreach ($steps as &$step) {
        $fieldStmt->execute(array(
            ':tenant_id' => (int)$tenantId,
            ':step_id' => (int)$step['id']
        ));
        $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fields as &$field) {
            $field['config'] = array();
            if (!empty($field['config_json'])) {
                $cfg = json_decode((string)$field['config_json'], true);
                if (is_array($cfg)) {
                    $field['config'] = $cfg;
                }
            }

            $optionStmt->execute(array(':field_id' => (int)$field['id']));
            $field['options'] = $optionStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($field);

        $step['fields'] = $fields;
    }
    unset($step);

    return $steps;
}

function wfActivity(PDO $pdo, $tenantId, $branchId, $userId, $event, $id, $title, $details)
{
    try {
        if (!wfTableExists($pdo, 'activity_events')) {
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO activity_events (
                tenant_id, branch_id, actor_user_id, actor_type,
                event_type, related_type, related_id, title,
                details_json, visible_to_client
             ) VALUES (
                :tenant_id, :branch_id, :user_id, 'user',
                :event, 'workflow', :id, :title,
                :details, 0
             )"
        );
        $stmt->execute(array(
            ':tenant_id' => (int)$tenantId,
            ':branch_id' => (int)$branchId > 0 ? (int)$branchId : null,
            ':user_id' => (int)$userId,
            ':event' => substr((string)$event, 0, 120),
            ':id' => (int)$id,
            ':title' => substr((string)$title, 0, 255),
            ':details' => wfJson($details)
        ));
    } catch (Throwable $e) {
        error_log('FieldPlx mobile workflow activity: ' . $e->getMessage());
    }
}

function wfAudit(PDO $pdo, $tenantId, $branchId, $userId, $action, $id, $old, $new)
{
    try {
        if (function_exists('tenantAuditLog')) {
            tenantAuditLog(
                $pdo,
                $action,
                (int)$tenantId,
                (int)$branchId > 0 ? (int)$branchId : null,
                (int)$userId,
                'workflow',
                (int)$id,
                $old,
                $new
            );
        }
    } catch (Throwable $e) {
        error_log('FieldPlx mobile workflow audit: ' . $e->getMessage());
    }
}

function wfNormalizeConfig($field)
{
    if (isset($field['config']) && is_array($field['config'])) {
        return $field['config'];
    }

    if (!empty($field['config_json'])) {
        if (is_array($field['config_json'])) {
            return $field['config_json'];
        }
        $decoded = json_decode((string)$field['config_json'], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return array();
}

function wfValidateAndNormalizePayload(PDO $pdo, $tenantId, $input, $existingId = 0)
{
    wfRequireBuilderSchema($pdo);

    $serviceId = isset($input['service_id']) ? (int)$input['service_id'] : 0;
    $name = trim((string)($input['name'] ?? ''));
    $code = trim((string)($input['code'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $mode = trim((string)($input['assignment_completion_mode'] ?? 'primary_only'));
    $versionNo = max(1, (int)($input['version_no'] ?? 1));
    $status = trim((string)($input['status'] ?? 'draft'));
    $steps = isset($input['steps']) && is_array($input['steps']) ? $input['steps'] : array();

    if ($serviceId <= 0) {
        wfResponse(422, false, 'Select the service for this workflow.', null, 'service_required');
    }

    $serviceStmt = $pdo->prepare(
        "SELECT id, name, sku
         FROM product_services
         WHERE id = :id
           AND tenant_id = :tenant_id
           AND item_type = 'service'
           AND status = 'active'
           AND deleted_at IS NULL
         LIMIT 1"
    );
    $serviceStmt->execute(array(
        ':id' => $serviceId,
        ':tenant_id' => (int)$tenantId
    ));
    $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) {
        wfResponse(422, false, 'Selected service is invalid.', null, 'invalid_service');
    }

    if ($name === '') {
        wfResponse(422, false, 'Workflow name is required.', null, 'workflow_name_required');
    }
    if (strlen($name) > 190) {
        wfResponse(422, false, 'Workflow name is too long.', null, 'workflow_name_too_long');
    }
    if (strlen($code) > 100) {
        wfResponse(422, false, 'Workflow code is too long.', null, 'workflow_code_too_long');
    }
    if (empty($steps)) {
        wfResponse(422, false, 'Add at least one work-process step.', null, 'steps_required');
    }
    if (!in_array($mode, array('primary_only', 'task_owner', 'all_assignees'), true)) {
        wfResponse(422, false, 'Invalid completion mode.', null, 'invalid_completion_mode');
    }
    if (!in_array($status, array('draft', 'active', 'inactive', 'archived'), true)) {
        wfResponse(422, false, 'Invalid workflow status.', null, 'invalid_workflow_status');
    }

    $dupSql = "SELECT id FROM workflows WHERE tenant_id = :tenant_id AND name = :name";
    $dupParams = array(':tenant_id' => (int)$tenantId, ':name' => $name);
    if ((int)$existingId > 0) {
        $dupSql .= " AND id <> :id";
        $dupParams[':id'] = (int)$existingId;
    }
    $dupSql .= " LIMIT 1";
    $dup = $pdo->prepare($dupSql);
    $dup->execute($dupParams);
    if ($dup->fetchColumn()) {
        wfResponse(409, false, 'A workflow with this name already exists.', null, 'duplicate_workflow_name');
    }

    $allowedTypes = array(
        'checklist', 'text', 'textarea', 'number', 'decimal', 'yes_no',
        'select', 'radio', 'checkbox', 'photo_single', 'photo_multiple',
        'signature', 'date', 'time', 'datetime', 'location', 'file',
        'customer_confirmation', 'heading'
    );

    $normalizedSteps = array();

    foreach ($steps as $si => $step) {
        if (!is_array($step)) {
            continue;
        }

        $stepName = trim((string)($step['step_name'] ?? ''));
        if ($stepName === '') {
            wfResponse(422, false, 'Every process step needs a name.', null, 'step_name_required');
        }

        $normalizedFields = array();
        $fields = isset($step['fields']) && is_array($step['fields']) ? $step['fields'] : array();

        foreach ($fields as $fi => $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = trim((string)($field['field_type'] ?? ''));
            if (!in_array($type, $allowedTypes, true)) {
                wfResponse(422, false, 'Invalid builder field type: ' . $type, null, 'invalid_field_type');
            }

            $label = trim((string)($field['label'] ?? ''));
            if ($type !== 'heading' && $label === '') {
                wfResponse(422, false, 'Every workflow field needs a label.', null, 'field_label_required');
            }

            $fieldKey = trim((string)($field['field_key'] ?? ''));
            if ($fieldKey === '') {
                $fieldKey = wfSlug($label !== '' ? $label : 'instruction_' . ($fi + 1));
            }

            $config = wfNormalizeConfig($field);

            $minFiles = ($field['min_files'] ?? '') !== '' ? (int)$field['min_files'] : null;
            $maxFiles = ($field['max_files'] ?? '') !== '' ? (int)$field['max_files'] : null;

            if ($type === 'photo_single') {
                $minFiles = !empty($field['is_required']) ? 1 : 0;
                $maxFiles = 1;
            }
            if ($type === 'photo_multiple' && $maxFiles === null) {
                $maxFiles = 10;
            }

            $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
            $normalizedOptions = array();
            foreach ($options as $oi => $opt) {
                if (!is_array($opt)) {
                    continue;
                }
                $optionLabel = trim((string)($opt['option_label'] ?? ''));
                if ($optionLabel === '') {
                    continue;
                }
                $optionValue = trim((string)($opt['option_value'] ?? ''));
                if ($optionValue === '') {
                    $optionValue = wfSlug($optionLabel);
                }
                $normalizedOptions[] = array(
                    'option_label' => $optionLabel,
                    'option_value' => $optionValue,
                    'sort_order' => $oi + 1
                );
            }

            $normalizedFields[] = array(
                'field_key' => $fieldKey,
                'label' => $label !== '' ? $label : null,
                'field_type' => $type,
                'help_text' => trim((string)($field['help_text'] ?? '')) ?: null,
                'placeholder' => trim((string)($field['placeholder'] ?? '')) ?: null,
                'is_required' => wfBoolValue($field['is_required'] ?? false) ? 1 : 0,
                'sort_order' => $fi + 1,
                'min_value' => ($field['min_value'] ?? '') !== '' ? $field['min_value'] : null,
                'max_value' => ($field['max_value'] ?? '') !== '' ? $field['max_value'] : null,
                'min_length' => ($field['min_length'] ?? '') !== '' ? (int)$field['min_length'] : null,
                'max_length' => ($field['max_length'] ?? '') !== '' ? (int)$field['max_length'] : null,
                'min_files' => $minFiles,
                'max_files' => $maxFiles,
                'accept_types' => trim((string)($field['accept_types'] ?? '')) ?: null,
                'config_json' => empty($config) ? null : wfJson($config),
                'options' => $normalizedOptions
            );
        }

        $normalizedSteps[] = array(
            'step_name' => $stepName,
            'step_code' => wfSlug($stepName) ?: null,
            'description' => trim((string)($step['description'] ?? '')) ?: null,
            'sort_order' => $si + 1,
            'required' => wfBoolValue($step['required'] ?? false) ? 1 : 0,
            'fields' => $normalizedFields
        );
    }

    if (empty($normalizedSteps)) {
        wfResponse(422, false, 'Add at least one valid work-process step.', null, 'steps_required');
    }

    return array(
        'service' => $service,
        'service_id' => $serviceId,
        'name' => $name,
        'code' => $code,
        'description' => $description,
        'assignment_completion_mode' => $mode,
        'version_no' => $versionNo,
        'status' => $status,
        'steps' => $normalizedSteps
    );
}

function wfSaveDefinition(PDO $pdo, $tenantId, $userId, $workflowId, $payload)
{
    $isUpdate = (int)$workflowId > 0;
    $old = null;

    if ($isUpdate) {
        $old = array(
            'workflow' => wfTenantWorkflow($pdo, $tenantId, $workflowId),
            'steps' => wfFullSteps($pdo, $tenantId, $workflowId)
        );
    }

    $pdo->beginTransaction();

    try {
        if ($isUpdate) {
            $stmt = $pdo->prepare(
                "UPDATE workflows
                 SET name = :name,
                     code = :code,
                     description = :description,
                     assignment_completion_mode = :mode,
                     version_no = :version,
                     status = :status
                 WHERE id = :id
                   AND tenant_id = :tenant_id"
            );
            $stmt->execute(array(
                ':name' => $payload['name'],
                ':code' => $payload['code'] !== '' ? $payload['code'] : null,
                ':description' => $payload['description'] !== '' ? $payload['description'] : null,
                ':mode' => $payload['assignment_completion_mode'],
                ':version' => $payload['version_no'],
                ':status' => $payload['status'],
                ':id' => (int)$workflowId,
                ':tenant_id' => (int)$tenantId
            ));
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO workflows (
                    tenant_id, name, code, description,
                    assignment_completion_mode, version_no,
                    status, created_by
                 ) VALUES (
                    :tenant_id, :name, :code, :description,
                    :mode, :version, :status, :created_by
                 )"
            );
            $stmt->execute(array(
                ':tenant_id' => (int)$tenantId,
                ':name' => $payload['name'],
                ':code' => $payload['code'] !== '' ? $payload['code'] : null,
                ':description' => $payload['description'] !== '' ? $payload['description'] : null,
                ':mode' => $payload['assignment_completion_mode'],
                ':version' => $payload['version_no'],
                ':status' => $payload['status'],
                ':created_by' => (int)$userId
            ));
            $workflowId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare(
            "DELETE FROM service_workflows WHERE workflow_id = :id"
        )->execute(array(':id' => (int)$workflowId));

        $pdo->prepare(
            "INSERT INTO service_workflows (
                product_service_id, workflow_id, is_default
             ) VALUES (
                :service_id, :workflow_id, 1
             )"
        )->execute(array(
            ':service_id' => (int)$payload['service_id'],
            ':workflow_id' => (int)$workflowId
        ));

        $progress = $pdo->prepare(
            "SELECT COUNT(*)
             FROM job_workflow_progress jwp
             INNER JOIN workflow_steps ws
                ON ws.id = jwp.workflow_step_id
             WHERE ws.workflow_id = :id"
        );
        $progress->execute(array(':id' => (int)$workflowId));

        if ((int)$progress->fetchColumn() > 0 && $old) {
            throw new RuntimeException(
                'This workflow already has technician job history. Duplicate it to create a new version instead of changing its builder structure.'
            );
        }

        $pdo->prepare(
            "DELETE FROM workflow_steps WHERE workflow_id = :id"
        )->execute(array(':id' => (int)$workflowId));

        $insertStep = $pdo->prepare(
            "INSERT INTO workflow_steps (
                workflow_id, step_code, step_name, description,
                sort_order, required, require_notes, require_form,
                require_checklist, require_photo, min_photos,
                require_signature, require_location, allow_reschedule,
                allow_quote_revision, allowed_roles_json
             ) VALUES (
                :workflow_id, :step_code, :step_name, :description,
                :sort_order, :required, 0, 1, 0, 0, 0,
                0, 0, 0, 0, NULL
             )"
        );

        $insertField = $pdo->prepare(
            "INSERT INTO workflow_step_fields (
                tenant_id, workflow_step_id, field_key, label,
                field_type, help_text, placeholder, default_value,
                is_required, sort_order, min_value, max_value,
                min_length, max_length, min_files, max_files,
                accept_types, config_json, status
             ) VALUES (
                :tenant_id, :step_id, :field_key, :label,
                :field_type, :help_text, :placeholder, NULL,
                :is_required, :sort_order, :min_value, :max_value,
                :min_length, :max_length, :min_files, :max_files,
                :accept_types, :config_json, 'active'
             )"
        );

        $insertOption = $pdo->prepare(
            "INSERT INTO workflow_field_options (
                workflow_field_id, option_label, option_value,
                sort_order, is_default, status
             ) VALUES (
                :field_id, :label, :value,
                :sort_order, 0, 'active'
             )"
        );

        foreach ($payload['steps'] as $step) {
            $insertStep->execute(array(
                ':workflow_id' => (int)$workflowId,
                ':step_code' => $step['step_code'],
                ':step_name' => $step['step_name'],
                ':description' => $step['description'],
                ':sort_order' => (int)$step['sort_order'],
                ':required' => (int)$step['required']
            ));
            $stepId = (int)$pdo->lastInsertId();

            foreach ($step['fields'] as $field) {
                $insertField->execute(array(
                    ':tenant_id' => (int)$tenantId,
                    ':step_id' => $stepId,
                    ':field_key' => $field['field_key'],
                    ':label' => $field['label'],
                    ':field_type' => $field['field_type'],
                    ':help_text' => $field['help_text'],
                    ':placeholder' => $field['placeholder'],
                    ':is_required' => (int)$field['is_required'],
                    ':sort_order' => (int)$field['sort_order'],
                    ':min_value' => $field['min_value'],
                    ':max_value' => $field['max_value'],
                    ':min_length' => $field['min_length'],
                    ':max_length' => $field['max_length'],
                    ':min_files' => $field['min_files'],
                    ':max_files' => $field['max_files'],
                    ':accept_types' => $field['accept_types'],
                    ':config_json' => $field['config_json']
                ));
                $fieldId = (int)$pdo->lastInsertId();

                foreach ($field['options'] as $option) {
                    $insertOption->execute(array(
                        ':field_id' => $fieldId,
                        ':label' => $option['option_label'],
                        ':value' => $option['option_value'],
                        ':sort_order' => (int)$option['sort_order']
                    ));
                }
            }
        }

        $pdo->commit();

        return array(
            'workflow_id' => (int)$workflowId,
            'old' => $old,
            'new' => array(
                'workflow' => wfTenantWorkflow($pdo, $tenantId, $workflowId),
                'steps' => wfFullSteps($pdo, $tenantId, $workflowId)
            )
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function wfDuplicate(PDO $pdo, $tenantId, $userId, $sourceId)
{
    wfRequireBuilderSchema($pdo);

    $source = wfTenantWorkflow($pdo, $tenantId, $sourceId);
    $steps = wfFullSteps($pdo, $tenantId, $sourceId);

    $newName = $source['name'] . ' Copy';
    $n = 1;
    while (true) {
        $stmt = $pdo->prepare(
            "SELECT id FROM workflows
             WHERE tenant_id = :tenant_id
               AND name = :name
             LIMIT 1"
        );
        $stmt->execute(array(
            ':tenant_id' => (int)$tenantId,
            ':name' => $newName
        ));
        if (!$stmt->fetchColumn()) {
            break;
        }
        $n++;
        $newName = $source['name'] . ' Copy ' . $n;
    }

    $pdo->beginTransaction();

    try {
        $insert = $pdo->prepare(
            "INSERT INTO workflows (
                tenant_id, name, code, description,
                assignment_completion_mode, version_no,
                status, created_by
             ) VALUES (
                :tenant_id, :name, NULL, :description,
                :mode, :version, 'draft', :created_by
             )"
        );
        $insert->execute(array(
            ':tenant_id' => (int)$tenantId,
            ':name' => $newName,
            ':description' => $source['description'],
            ':mode' => $source['assignment_completion_mode'],
            ':version' => (int)$source['version_no'] + 1,
            ':created_by' => (int)$userId
        ));

        $newId = (int)$pdo->lastInsertId();

        if (!empty($source['service_id'])) {
            $pdo->prepare(
                "INSERT INTO service_workflows (
                    product_service_id, workflow_id, is_default
                 ) VALUES (
                    :service_id, :workflow_id, 1
                 )"
            )->execute(array(
                ':service_id' => (int)$source['service_id'],
                ':workflow_id' => $newId
            ));
        }

        $stepIns = $pdo->prepare(
            "INSERT INTO workflow_steps (
                workflow_id, step_code, step_name, description,
                sort_order, required, require_notes, require_form,
                require_checklist, require_photo, min_photos,
                require_signature, require_location, allow_reschedule,
                allow_quote_revision, allowed_roles_json
             ) VALUES (
                :workflow_id, :step_code, :step_name, :description,
                :sort_order, :required, 0, 1, 0, 0, 0,
                0, 0, 0, 0, NULL
             )"
        );

        $fieldIns = $pdo->prepare(
            "INSERT INTO workflow_step_fields (
                tenant_id, workflow_step_id, field_key, label,
                field_type, help_text, placeholder, default_value,
                is_required, sort_order, min_value, max_value,
                min_length, max_length, min_files, max_files,
                accept_types, config_json, status
             ) VALUES (
                :tenant_id, :step_id, :field_key, :label,
                :field_type, :help_text, :placeholder, :default_value,
                :is_required, :sort_order, :min_value, :max_value,
                :min_length, :max_length, :min_files, :max_files,
                :accept_types, :config_json, :status
             )"
        );

        $optionIns = $pdo->prepare(
            "INSERT INTO workflow_field_options (
                workflow_field_id, option_label, option_value,
                sort_order, is_default, status
             ) VALUES (
                :field_id, :label, :value,
                :sort_order, :is_default, :status
             )"
        );

        foreach ($steps as $step) {
            $stepIns->execute(array(
                ':workflow_id' => $newId,
                ':step_code' => $step['step_code'],
                ':step_name' => $step['step_name'],
                ':description' => $step['description'],
                ':sort_order' => $step['sort_order'],
                ':required' => $step['required']
            ));
            $newStepId = (int)$pdo->lastInsertId();

            foreach ($step['fields'] as $field) {
                $fieldIns->execute(array(
                    ':tenant_id' => (int)$tenantId,
                    ':step_id' => $newStepId,
                    ':field_key' => $field['field_key'],
                    ':label' => $field['label'],
                    ':field_type' => $field['field_type'],
                    ':help_text' => $field['help_text'],
                    ':placeholder' => $field['placeholder'],
                    ':default_value' => $field['default_value'],
                    ':is_required' => $field['is_required'],
                    ':sort_order' => $field['sort_order'],
                    ':min_value' => $field['min_value'],
                    ':max_value' => $field['max_value'],
                    ':min_length' => $field['min_length'],
                    ':max_length' => $field['max_length'],
                    ':min_files' => $field['min_files'],
                    ':max_files' => $field['max_files'],
                    ':accept_types' => $field['accept_types'],
                    ':config_json' => $field['config_json'],
                    ':status' => $field['status']
                ));
                $newFieldId = (int)$pdo->lastInsertId();

                foreach ($field['options'] as $option) {
                    $optionIns->execute(array(
                        ':field_id' => $newFieldId,
                        ':label' => $option['option_label'],
                        ':value' => $option['option_value'],
                        ':sort_order' => $option['sort_order'],
                        ':is_default' => $option['is_default'],
                        ':status' => $option['status']
                    ));
                }
            }
        }

        $pdo->commit();
        return $newId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$auth = wfRequireAuth($pdo);
$tenantId = (int)$auth['tenant_id'];
$userId = (int)$auth['user_id'];
$branchId = (int)$auth['branch_id'];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        if (isset($_GET['meta']) && (int)$_GET['meta'] === 1) {
            wfResponse(200, true, 'Workflow builder metadata loaded successfully.', array(
                'services' => wfServices($pdo, $tenantId),
                'builder_schema_ready' => wfBuilderSchemaReady($pdo),
                'status_options' => array('draft', 'active', 'inactive', 'archived'),
                'completion_mode_options' => array('primary_only', 'task_owner', 'all_assignees'),
                'field_type_options' => array(
                    'checklist', 'text', 'textarea', 'number', 'decimal', 'yes_no',
                    'select', 'radio', 'checkbox', 'photo_single', 'photo_multiple',
                    'signature', 'date', 'time', 'datetime', 'location', 'file',
                    'customer_confirmation', 'heading'
                )
            ));
        }

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $workflow = wfTenantWorkflow($pdo, $tenantId, $id);
            $steps = wfFullSteps($pdo, $tenantId, $id);
            wfResponse(200, true, 'Workflow loaded successfully.', array(
                'workflow' => $workflow,
                'steps' => $steps,
                'services' => wfServices($pdo, $tenantId),
                'builder_schema_ready' => true
            ));
        }

        $schemaReady = wfBuilderSchemaReady($pdo);
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
        if (!in_array($perPage, array(10, 25, 50), true)) {
            $perPage = 10;
        }
        $search = trim((string)($_GET['search'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $serviceId = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

        $where = array('w.tenant_id = :tenant_id');
        $params = array(':tenant_id' => $tenantId);

        if ($search !== '') {
            $where[] = '(w.name LIKE :s1 OR w.code LIKE :s2 OR ps.name LIKE :s3 OR ps.sku LIKE :s4)';
            $sv = '%' . $search . '%';
            $params[':s1'] = $sv;
            $params[':s2'] = $sv;
            $params[':s3'] = $sv;
            $params[':s4'] = $sv;
        }

        if (in_array($status, array('draft', 'active', 'inactive', 'archived'), true)) {
            $where[] = 'w.status = :status';
            $params[':status'] = $status;
        }

        if ($serviceId > 0) {
            $where[] = 'sw.product_service_id = :service_id';
            $params[':service_id'] = $serviceId;
        }

        $whereSql = implode(' AND ', $where);

        $count = $pdo->prepare(
            "SELECT COUNT(DISTINCT w.id)
             FROM workflows w
             LEFT JOIN service_workflows sw ON sw.workflow_id = w.id
             LEFT JOIN product_services ps
                ON ps.id = sw.product_service_id
               AND ps.tenant_id = w.tenant_id
             WHERE $whereSql"
        );
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $fieldCountSql = $schemaReady
            ? "(SELECT COUNT(*) FROM workflow_step_fields f INNER JOIN workflow_steps x2 ON x2.id = f.workflow_step_id WHERE x2.workflow_id = w.id AND f.status = 'active')"
            : '0';

        $stmt = $pdo->prepare(
            "SELECT
                w.id, w.name, w.code, w.description,
                w.assignment_completion_mode, w.version_no,
                w.status, w.created_at, w.updated_at,
                ps.id AS service_id, ps.name AS service_name, ps.sku AS service_sku,
                (SELECT COUNT(*) FROM workflow_steps x WHERE x.workflow_id = w.id) AS step_count,
                $fieldCountSql AS field_count
             FROM workflows w
             LEFT JOIN service_workflows sw ON sw.workflow_id = w.id
             LEFT JOIN product_services ps
                ON ps.id = sw.product_service_id
               AND ps.tenant_id = w.tenant_id
             WHERE $whereSql
             GROUP BY w.id
             ORDER BY FIELD(w.status, 'active', 'draft', 'inactive', 'archived'), w.name
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($schemaReady) {
            $sum = $pdo->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM((SELECT COUNT(*) FROM workflow_steps ws WHERE ws.workflow_id = w.id)) AS steps,
                    SUM((SELECT COUNT(*) FROM workflow_step_fields f INNER JOIN workflow_steps ws2 ON ws2.id = f.workflow_step_id WHERE ws2.workflow_id = w.id AND f.status = 'active')) AS fields
                 FROM workflows w
                 WHERE w.tenant_id = :tenant_id"
            );
        } else {
            $sum = $pdo->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM((SELECT COUNT(*) FROM workflow_steps ws WHERE ws.workflow_id = w.id)) AS steps,
                    0 AS fields
                 FROM workflows w
                 WHERE w.tenant_id = :tenant_id"
            );
        }
        $sum->execute(array(':tenant_id' => $tenantId));
        $summary = $sum->fetch(PDO::FETCH_ASSOC);

        $serviceCount = $pdo->prepare(
            "SELECT COUNT(DISTINCT sw.product_service_id)
             FROM service_workflows sw
             INNER JOIN workflows w
                ON w.id = sw.workflow_id
               AND w.tenant_id = :tenant_id"
        );
        $serviceCount->execute(array(':tenant_id' => $tenantId));
        $summary['services'] = (int)$serviceCount->fetchColumn();

        wfResponse(200, true, 'Workflows loaded successfully.', array(
            'workflows' => $rows,
            'services' => wfServices($pdo, $tenantId),
            'summary' => $summary,
            'builder_schema_ready' => $schemaReady,
            'builder_schema_message' => $schemaReady
                ? ''
                : 'Workflow Builder database update is missing. Run migration_workflow_builder.sql once to enable dynamic fields.',
            'pagination' => array(
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
                'total' => $total,
                'from' => $total ? $offset + 1 : 0,
                'to' => $total ? min($offset + count($rows), $total) : 0
            )
        ));
    }

    if ($method === 'POST') {
        $duplicateId = isset($_GET['duplicate_id']) ? (int)$_GET['duplicate_id'] : 0;
        if ($duplicateId > 0) {
            $newId = wfDuplicate($pdo, $tenantId, $userId, $duplicateId);
            $new = array(
                'workflow' => wfTenantWorkflow($pdo, $tenantId, $newId),
                'steps' => wfFullSteps($pdo, $tenantId, $newId)
            );
            wfActivity($pdo, $tenantId, $branchId, $userId, 'workflow_duplicated', $newId, 'Workflow duplicated: ' . $new['workflow']['name'], $new);
            wfAudit($pdo, $tenantId, $branchId, $userId, 'WORKFLOW_DUPLICATED', $newId, null, $new);
            wfResponse(201, true, 'Workflow duplicated as a new draft version.', array(
                'workflow_id' => $newId,
                'workflow' => $new['workflow'],
                'steps' => $new['steps']
            ));
        }

        $input = wfInput();
        $payload = wfValidateAndNormalizePayload($pdo, $tenantId, $input, 0);
        $saved = wfSaveDefinition($pdo, $tenantId, $userId, 0, $payload);

        wfActivity(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'workflow_builder_created',
            $saved['workflow_id'],
            'Workflow builder created: ' . $payload['name'],
            $saved['new']
        );
        wfAudit(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'WORKFLOW_BUILDER_CREATED',
            $saved['workflow_id'],
            null,
            $saved['new']
        );

        wfResponse(201, true, 'Workflow created successfully.', array(
            'workflow_id' => $saved['workflow_id'],
            'workflow' => $saved['new']['workflow'],
            'steps' => $saved['new']['steps']
        ));
    }

    if (in_array($method, array('PUT', 'PATCH'), true)) {
        $id = wfInt('id', 0);
        if ($id <= 0) {
            wfResponse(422, false, 'Workflow id is required.', null, 'workflow_id_required');
        }

        wfTenantWorkflow($pdo, $tenantId, $id);
        $input = wfInput();
        $payload = wfValidateAndNormalizePayload($pdo, $tenantId, $input, $id);
        $saved = wfSaveDefinition($pdo, $tenantId, $userId, $id, $payload);

        wfActivity(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'workflow_builder_updated',
            $saved['workflow_id'],
            'Workflow builder updated: ' . $payload['name'],
            $saved['new']
        );
        wfAudit(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'WORKFLOW_BUILDER_UPDATED',
            $saved['workflow_id'],
            $saved['old'],
            $saved['new']
        );

        wfResponse(200, true, 'Workflow updated successfully.', array(
            'workflow_id' => $saved['workflow_id'],
            'workflow' => $saved['new']['workflow'],
            'steps' => $saved['new']['steps']
        ));
    }

    if ($method === 'DELETE') {
        $id = wfInt('id', 0);
        if ($id <= 0) {
            wfResponse(422, false, 'Workflow id is required.', null, 'workflow_id_required');
        }

        $workflow = wfTenantWorkflow($pdo, $tenantId, $id);

        $stmt = $pdo->prepare(
            "UPDATE workflows
             SET status = 'archived'
             WHERE id = :id
               AND tenant_id = :tenant_id"
        );
        $stmt->execute(array(
            ':id' => $id,
            ':tenant_id' => $tenantId
        ));

        wfActivity(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'workflow_archived',
            $id,
            'Workflow archived: ' . $workflow['name'],
            array('status' => 'archived')
        );
        wfAudit(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'WORKFLOW_ARCHIVED',
            $id,
            array('status' => $workflow['status']),
            array('status' => 'archived')
        );

        wfResponse(200, true, 'Workflow archived successfully.', array(
            'workflow_id' => $id,
            'status' => 'archived'
        ));
    }

    wfResponse(405, false, 'Method not allowed.', null, 'method_not_allowed');

} catch (PDOException $e) {
    error_log('FieldPlx mobile workflow PDO: ' . $e->getMessage());

    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1146) {
        wfResponse(
            409,
            false,
            'Workflow Builder database update is missing. Run migration_workflow_builder.sql once.',
            null,
            'workflow_builder_schema_missing'
        );
    }

    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        wfResponse(
            409,
            false,
            'A duplicate workflow, field key or option already exists.',
            null,
            'duplicate_record'
        );
    }

    wfResponse(500, false, 'Unable to process the workflow request.', null, 'database_error');

} catch (RuntimeException $e) {
    wfResponse(409, false, $e->getMessage(), null, 'workflow_conflict');

} catch (Throwable $e) {
    error_log('FieldPlx mobile workflow API: ' . $e->getMessage());
    wfResponse(500, false, 'Unable to process the workflow request.', null, 'server_error');
}
