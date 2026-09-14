<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email-settings-config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function es_out($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(
        array_merge(array('success' => (bool)$success, 'message' => (string)$message), $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function es_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }
    return trim((string)$_POST[$key]);
}

function es_table(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function es_csrf()
{
    $token = es_post('csrf_token');
    if (
        empty($_SESSION['email_settings_csrf']) ||
        !is_string($_SESSION['email_settings_csrf']) ||
        $token === '' ||
        !hash_equals($_SESSION['email_settings_csrf'], $token)
    ) {
        es_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function es_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $entityType, $entityId, $values)
{
    if (!function_exists('tenantAuditLog')) {
        return;
    }
    try {
        tenantAuditLog(
            $pdo,
            $action,
            $tenantId,
            $branchId > 0 ? $branchId : null,
            $userId,
            $entityType,
            $entityId,
            null,
            $values
        );
    } catch (Throwable $e) {
        error_log('FieldPlx Email Settings audit error: ' . $e->getMessage());
    }
}

function es_schema(PDO $pdo)
{
    return array(
        'notification_events' => es_table($pdo, 'notification_events'),
        'notification_templates' => es_table($pdo, 'notification_templates'),
        'email_reply_routing' => es_table($pdo, 'email_reply_routing'),
        'email_feature_settings' => es_table($pdo, 'email_feature_settings'),
        'email_reminder_schedules' => es_table($pdo, 'email_reminder_schedules')
    );
}

function es_schema_ready($schema)
{
    foreach ($schema as $ready) {
        if (!$ready) return false;
    }
    return true;
}

function es_event_id(PDO $pdo, $eventKey)
{
    $stmt = $pdo->prepare("SELECT id FROM notification_events WHERE event_key=:k AND is_active=1 LIMIT 1");
    $stmt->execute(array(':k' => $eventKey));
    return (int)$stmt->fetchColumn();
}

function es_variables_for_item($item)
{
    $tokens = array();
    $groups = es_variable_groups($item['context']);
    foreach ($groups as $items) {
        foreach ($items as $variable) {
            $tokens[] = $variable['token'];
        }
    }
    return $tokens;
}

function es_save_template(PDO $pdo, $tenantId, $userId, $item, $subject, $body)
{
    $eventId = es_event_id($pdo, $item['event_key']);
    if ($eventId <= 0) {
        throw new RuntimeException('Email event is not configured. Run the Email Settings migration.');
    }

    $varsJson = json_encode(es_variables_for_item($item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare("SELECT id FROM notification_templates WHERE tenant_id=:tenant AND branch_id IS NULL AND event_id=:event AND channel='email' ORDER BY id DESC LIMIT 1");
    $stmt->execute(array(':tenant' => $tenantId, ':event' => $eventId));
    $templateId = (int)$stmt->fetchColumn();

    if ($templateId > 0) {
        $stmt = $pdo->prepare("UPDATE notification_templates SET template_name=:name,subject=:subject,body=:body,variables_json=:vars,is_active=1,updated_at=NOW() WHERE id=:id AND tenant_id=:tenant");
        $stmt->execute(array(
            ':name' => $item['title'] . ' Email',
            ':subject' => $subject,
            ':body' => $body,
            ':vars' => $varsJson,
            ':id' => $templateId,
            ':tenant' => $tenantId
        ));
    } else {
        $stmt = $pdo->prepare("INSERT INTO notification_templates (tenant_id,branch_id,event_id,channel,template_name,subject,body,variables_json,is_default,is_active,created_by,created_at,updated_at) VALUES (:tenant,NULL,:event,'email',:name,:subject,:body,:vars,0,1,:user,NOW(),NOW())");
        $stmt->execute(array(
            ':tenant' => $tenantId,
            ':event' => $eventId,
            ':name' => $item['title'] . ' Email',
            ':subject' => $subject,
            ':body' => $body,
            ':vars' => $varsJson,
            ':user' => $userId > 0 ? $userId : null
        ));
        $templateId = (int)$pdo->lastInsertId();
    }

    return $templateId;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    es_out(405, false, 'Method not allowed.');
}

es_csrf();

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$userId = isset($currentTenantUserId)
    ? (int)$currentTenantUserId
    : (isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0));
$branchId = isset($currentBranchId)
    ? (int)$currentBranchId
    : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);

if ($tenantId <= 0) {
    es_out(401, false, 'Tenant session is not available.');
}

$catalog = es_catalog();
$schema = es_schema($pdo);
$schemaReady = es_schema_ready($schema);
$action = es_post('action');

try {
    if ($action === 'list') {
        $tenant = array('display_name' => 'Your Company', 'email' => '', 'phone' => '');
        if (es_table($pdo, 'tenants')) {
            $stmt = $pdo->prepare("SELECT display_name,email,phone FROM tenants WHERE id=:id LIMIT 1");
            $stmt->execute(array(':id' => $tenantId));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $tenant = array_merge($tenant, $row);
        }

        $teamUsers = array();
        if (es_table($pdo, 'users')) {
            $stmt = $pdo->prepare("SELECT id,first_name,last_name,email FROM users WHERE tenant_id=:tenant AND status='active' ORDER BY first_name,last_name,id");
            $stmt->execute(array(':tenant' => $tenantId));
            $teamUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $overrides = array();
        if ($schema['notification_events'] && $schema['notification_templates']) {
            $stmt = $pdo->prepare("SELECT nt.id,nt.subject,nt.body,nt.is_active,ne.event_key FROM notification_templates nt INNER JOIN notification_events ne ON ne.id=nt.event_id WHERE nt.tenant_id=:tenant AND nt.branch_id IS NULL AND nt.channel='email' ORDER BY nt.id DESC");
            $stmt->execute(array(':tenant' => $tenantId));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!isset($overrides[$row['event_key']])) {
                    $overrides[$row['event_key']] = $row;
                }
            }
        }

        $features = array();
        if ($schema['email_feature_settings']) {
            $stmt = $pdo->prepare("SELECT setting_key,is_enabled FROM email_feature_settings WHERE tenant_id=:tenant");
            $stmt->execute(array(':tenant' => $tenantId));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $features[$row['setting_key']] = (int)$row['is_enabled'];
            }
        }

        $routes = array(
            'requests' => array('route_type' => 'sender', 'user_id' => null),
            'quotes' => array('route_type' => 'sender', 'user_id' => null),
            'jobs' => array('route_type' => 'sender', 'user_id' => null),
            'invoices' => array('route_type' => 'sender', 'user_id' => null)
        );
        if ($schema['email_reply_routing']) {
            $stmt = $pdo->prepare("SELECT workflow_key,route_type,user_id FROM email_reply_routing WHERE tenant_id=:tenant");
            $stmt->execute(array(':tenant' => $tenantId));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($routes[$row['workflow_key']])) {
                    $routes[$row['workflow_key']] = array(
                        'route_type' => $row['route_type'],
                        'user_id' => $row['user_id'] === null ? null : (int)$row['user_id']
                    );
                }
            }
        }

        $schedules = array();
        if ($schema['email_reminder_schedules']) {
            $stmt = $pdo->prepare("SELECT reminder_key,amount,offset_type,time_of_day,sort_order FROM email_reminder_schedules WHERE tenant_id=:tenant AND is_active=1 ORDER BY reminder_key,sort_order,id");
            $stmt->execute(array(':tenant' => $tenantId));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!isset($schedules[$row['reminder_key']])) {
                    $schedules[$row['reminder_key']] = array();
                }
                $schedules[$row['reminder_key']][] = array(
                    'amount' => (int)$row['amount'],
                    'offset_type' => (string)$row['offset_type'],
                    'time_of_day' => $row['time_of_day']
                );
            }
        }

        $clientCatalog = array();
        foreach ($catalog as $key => $item) {
            $item['default_subject'] = $item['subject'];
            $item['default_body'] = $item['body'];
            if (isset($overrides[$item['event_key']])) {
                $item['subject'] = (string)$overrides[$item['event_key']]['subject'];
                $item['body'] = (string)$overrides[$item['event_key']]['body'];
            }
            if (!empty($item['toggle'])) {
                $item['enabled'] = isset($features[$key]) ? (int)$features[$key] : 1;
            }
            if ($item['type'] === 'reminder') {
                $item['schedules'] = isset($schedules[$key]) && count($schedules[$key])
                    ? $schedules[$key]
                    : $item['schedules'];
            }
            $item['variables'] = es_variable_groups($item['context']);
            $clientCatalog[$key] = $item;
        }

        es_out(200, true, 'Email Settings loaded.', array(
            'catalog' => $clientCatalog,
            'tenant' => $tenant,
            'team_users' => $teamUsers,
            'reply_routing' => $routes,
            'schema' => array_merge($schema, array('ready' => $schemaReady))
        ));
    }

    if (!$schemaReady) {
        es_out(503, false, 'Run database/email-settings-migration.sql before saving Email Settings.');
    }

    if ($action === 'save_template') {
        $key = es_post('template_key');
        if (!isset($catalog[$key])) {
            es_out(422, false, 'Invalid email template.');
        }

        $subject = es_post('subject');
        $body = isset($_POST['body']) && !is_array($_POST['body']) ? trim((string)$_POST['body']) : '';
        if ($subject === '') es_out(422, false, 'Email subject is required.');
        if ($body === '') es_out(422, false, 'Email message is required.');
        if (strlen($subject) > 255) es_out(422, false, 'Email subject is too long.');

        $templateId = es_save_template($pdo, $tenantId, $userId, $catalog[$key], $subject, $body);
        es_audit($pdo, $tenantId, $branchId, $userId, 'EMAIL_TEMPLATE_UPDATED', 'notification_template', $templateId, array('template_key' => $key));
        es_out(200, true, 'Email template saved successfully.');
    }

    if ($action === 'save_reply_routing') {
        $allowed = array('requests', 'quotes', 'jobs', 'invoices');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO email_reply_routing (tenant_id,workflow_key,route_type,user_id,updated_by,created_at,updated_at) VALUES (:tenant,:workflow,:type,:uid,:by,NOW(),NOW()) ON DUPLICATE KEY UPDATE route_type=VALUES(route_type),user_id=VALUES(user_id),updated_by=VALUES(updated_by),updated_at=NOW()");

        foreach ($allowed as $workflow) {
            $raw = es_post('route_' . $workflow, 'sender');
            $routeType = 'sender';
            $targetUserId = null;

            if (strpos($raw, 'user:') === 0) {
                $candidate = (int)substr($raw, 5);
                if ($candidate > 0) {
                    $check = $pdo->prepare("SELECT id FROM users WHERE id=:id AND tenant_id=:tenant AND status='active' LIMIT 1");
                    $check->execute(array(':id' => $candidate, ':tenant' => $tenantId));
                    if ((int)$check->fetchColumn() > 0) {
                        $routeType = 'specific_user';
                        $targetUserId = $candidate;
                    }
                }
            }

            $stmt->execute(array(
                ':tenant' => $tenantId,
                ':workflow' => $workflow,
                ':type' => $routeType,
                ':uid' => $targetUserId,
                ':by' => $userId > 0 ? $userId : null
            ));
        }

        $pdo->commit();
        es_audit($pdo, $tenantId, $branchId, $userId, 'EMAIL_REPLY_ROUTING_UPDATED', 'email_settings', null, array('workflows' => $allowed));
        es_out(200, true, 'Email reply routing saved successfully.');
    }

    if ($action === 'toggle_feature') {
        $key = es_post('setting_key');
        if (!isset($catalog[$key]) || empty($catalog[$key]['toggle'])) {
            es_out(422, false, 'Invalid email setting.');
        }
        $enabled = es_post('enabled') === '1' ? 1 : 0;
        $stmt = $pdo->prepare("INSERT INTO email_feature_settings (tenant_id,setting_key,is_enabled,updated_by,created_at,updated_at) VALUES (:tenant,:key,:enabled,:by,NOW(),NOW()) ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),updated_by=VALUES(updated_by),updated_at=NOW()");
        $stmt->execute(array(':tenant' => $tenantId, ':key' => $key, ':enabled' => $enabled, ':by' => $userId > 0 ? $userId : null));
        es_audit($pdo, $tenantId, $branchId, $userId, 'EMAIL_FEATURE_TOGGLED', 'email_settings', null, array('setting_key' => $key, 'enabled' => $enabled));
        es_out(200, true, 'Email setting updated successfully.', array('enabled' => $enabled));
    }

    if ($action === 'save_reminder') {
        $key = es_post('template_key');
        if (!isset($catalog[$key]) || $catalog[$key]['type'] !== 'reminder') {
            es_out(422, false, 'Invalid reminder template.');
        }

        $subject = es_post('subject');
        $body = isset($_POST['body']) && !is_array($_POST['body']) ? trim((string)$_POST['body']) : '';
        $schedules = json_decode(es_post('schedules_json', '[]'), true);
        if (!is_array($schedules) || count($schedules) === 0) es_out(422, false, 'Add at least one reminder schedule.');
        if ($subject === '' || $body === '') es_out(422, false, 'Email subject and message are required.');

        $pdo->beginTransaction();
        $templateId = es_save_template($pdo, $tenantId, $userId, $catalog[$key], $subject, $body);

        $stmt = $pdo->prepare("DELETE FROM email_reminder_schedules WHERE tenant_id=:tenant AND reminder_key=:key");
        $stmt->execute(array(':tenant' => $tenantId, ':key' => $key));

        $insert = $pdo->prepare("INSERT INTO email_reminder_schedules (tenant_id,reminder_key,amount,offset_type,time_of_day,sort_order,is_active,created_at,updated_at) VALUES (:tenant,:key,:amount,:type,:time,:sort,1,NOW(),NOW())");
        $sort = 0;
        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) continue;
            $amount = isset($schedule['amount']) ? max(0, min(365, (int)$schedule['amount'])) : 1;
            $type = isset($schedule['offset_type']) ? (string)$schedule['offset_type'] : 'hour_before';
            if (!in_array($type, array('hour_before', 'day_before', 'same_day_as'), true)) {
                $type = 'hour_before';
            }

            $time = null;
            if (($type === 'day_before' || $type === 'same_day_as') && !empty($schedule['time_of_day'])) {
                $rawTime = (string)$schedule['time_of_day'];
                if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $rawTime)) {
                    $time = strlen($rawTime) === 5 ? $rawTime . ':00' : $rawTime;
                }
            }

            $sort++;
            $insert->execute(array(
                ':tenant' => $tenantId,
                ':key' => $key,
                ':amount' => $amount,
                ':type' => $type,
                ':time' => $time,
                ':sort' => $sort
            ));
        }

        if ($sort === 0) {
            throw new RuntimeException('Add at least one valid reminder schedule.');
        }

        $enabled = es_post('enabled') === '0' ? 0 : 1;
        $stmt = $pdo->prepare("INSERT INTO email_feature_settings (tenant_id,setting_key,is_enabled,updated_by,created_at,updated_at) VALUES (:tenant,:key,:enabled,:by,NOW(),NOW()) ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),updated_by=VALUES(updated_by),updated_at=NOW()");
        $stmt->execute(array(':tenant' => $tenantId, ':key' => $key, ':enabled' => $enabled, ':by' => $userId > 0 ? $userId : null));

        $pdo->commit();
        es_audit($pdo, $tenantId, $branchId, $userId, 'EMAIL_REMINDER_UPDATED', 'notification_template', $templateId, array('template_key' => $key, 'schedule_count' => $sort, 'enabled' => $enabled));
        es_out(200, true, 'Reminder settings saved successfully.');
    }

    es_out(400, false, 'Invalid Email Settings action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('FieldPlx Email Settings API error: ' . $e->getMessage());
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to complete the Email Settings action.';
    es_out(500, false, $message);
}
