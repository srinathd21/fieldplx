<?php
/**
 * FieldPlx - Schedule Calendar Color API
 * Dedicated endpoint for Jobber-style calendar color rules.
 * PHP 7.2+ / MariaDB 11.x
 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function scc_out($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function scc_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function scc_table(PDO $pdo, $table)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t' => $table));
    return (int)$q->fetchColumn() > 0;
}

function scc_col(PDO $pdo, $table, $column)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t' => $table, ':c' => $column));
    return (int)$q->fetchColumn() > 0;
}

function scc_csrf()
{
    $posted = scc_post('csrf_token');
    $saved = isset($_SESSION['schedule_settings_csrf']) ? (string)$_SESSION['schedule_settings_csrf'] : '';
    if ($posted === '' || $saved === '' || !hash_equals($saved, $posted)) {
        scc_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function scc_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $objectId, $values)
{
    if (!function_exists('tenantAuditLog')) return;
    try {
        tenantAuditLog(
            $pdo,
            $action,
            $tenantId,
            $branchId > 0 ? $branchId : null,
            $userId,
            'calendar_color_assignment',
            $objectId,
            null,
            $values
        );
    } catch (Throwable $ignore) {
    }
}

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$userId = isset($currentTenantUserId)
    ? (int)$currentTenantUserId
    : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$branchId = isset($currentBranchId)
    ? (int)$currentBranchId
    : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);

if ($tenantId <= 0 || $userId <= 0) scc_out(401, false, 'Tenant session is not available.');
if (!scc_table($pdo, 'calendar_color_assignments')) scc_out(503, false, 'Calendar color settings table is not available.');

scc_csrf();
$action = scc_post('action');
$hasSortOrder = scc_col($pdo, 'calendar_color_assignments', 'sort_order');

try {
    if ($action === 'save_color') {
        $assignmentId = (int)scc_post('assignment_id', '0');
        $ruleType = strtolower(scc_post('rule_type', 'assigned_to'));
        $ruleValue = scc_post('rule_value');
        $targetUserId = (int)scc_post('user_id', '0');
        $colorHex = strtoupper(scc_post('color_hex'));

        if (!in_array($ruleType, array('assigned_to', 'title_contains'), true)) {
            scc_out(422, false, 'Select a valid color rule.');
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $colorHex)) {
            scc_out(422, false, 'Select a valid calendar color.');
        }

        if ($ruleType === 'assigned_to') {
            if ($targetUserId <= 0) scc_out(422, false, 'Select a team member.');
            $q = $pdo->prepare("SELECT id FROM users WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
            $q->execute(array(':id' => $targetUserId, ':t' => $tenantId));
            if (!(int)$q->fetchColumn()) scc_out(404, false, 'Team member not found.');
            $ruleValue = '';

            $dup = $pdo->prepare("SELECT id FROM calendar_color_assignments WHERE tenant_id=:t AND user_id=:u AND id<>:id LIMIT 1");
            $dup->execute(array(':t' => $tenantId, ':u' => $targetUserId, ':id' => $assignmentId));
            $dupId = (int)$dup->fetchColumn();
            if ($dupId > 0) scc_out(409, false, 'This team member already has a calendar color rule.');
        } else {
            $targetUserId = null;
            if ($ruleValue === '') scc_out(422, false, 'Enter the item title text to match.');
            if (strlen($ruleValue) > 190) scc_out(422, false, 'Item title text must be 190 characters or fewer.');
        }

        if ($assignmentId > 0) {
            $exists = $pdo->prepare("SELECT id FROM calendar_color_assignments WHERE id=:id AND tenant_id=:t LIMIT 1");
            $exists->execute(array(':id' => $assignmentId, ':t' => $tenantId));
            if (!(int)$exists->fetchColumn()) scc_out(404, false, 'Calendar color rule not found.');

            $q = $pdo->prepare("UPDATE calendar_color_assignments SET user_id=:u, rule_type=:rt, rule_value=:rv, color_hex=:color, created_by=:by, updated_at=NOW() WHERE id=:id AND tenant_id=:t LIMIT 1");
            $q->execute(array(
                ':u' => $targetUserId,
                ':rt' => $ruleType,
                ':rv' => $ruleValue !== '' ? $ruleValue : null,
                ':color' => $colorHex,
                ':by' => $userId,
                ':id' => $assignmentId,
                ':t' => $tenantId
            ));
        } else {
            $nextSort = 0;
            if ($hasSortOrder) {
                $q = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+10 FROM calendar_color_assignments WHERE tenant_id=:t");
                $q->execute(array(':t' => $tenantId));
                $nextSort = (int)$q->fetchColumn();
            }

            $cols = 'tenant_id,user_id,rule_type,rule_value,color_hex,created_by,created_at,updated_at';
            $vals = ':t,:u,:rt,:rv,:color,:by,NOW(),NOW()';
            if ($hasSortOrder) {
                $cols .= ',sort_order';
                $vals .= ',:sort_order';
            }
            $q = $pdo->prepare("INSERT INTO calendar_color_assignments (" . $cols . ") VALUES (" . $vals . ")");
            $params = array(
                ':t' => $tenantId,
                ':u' => $targetUserId,
                ':rt' => $ruleType,
                ':rv' => $ruleValue !== '' ? $ruleValue : null,
                ':color' => $colorHex,
                ':by' => $userId
            );
            if ($hasSortOrder) $params[':sort_order'] = $nextSort;
            $q->execute($params);
            $assignmentId = (int)$pdo->lastInsertId();
        }

        scc_audit($pdo, $tenantId, $branchId, $userId, 'CALENDAR_COLOR_RULE_SAVED', $assignmentId, array(
            'rule_type' => $ruleType,
            'rule_value' => $ruleValue,
            'user_id' => $targetUserId,
            'color_hex' => $colorHex
        ));
        scc_out(200, true, $assignmentId > 0 ? 'Calendar color saved successfully.' : 'Calendar color assigned successfully.', array('assignment_id' => $assignmentId));
    }

    if ($action === 'delete_color') {
        $assignmentId = (int)scc_post('assignment_id', '0');
        if ($assignmentId <= 0) scc_out(422, false, 'Invalid calendar color rule.');
        $q = $pdo->prepare("DELETE FROM calendar_color_assignments WHERE id=:id AND tenant_id=:t LIMIT 1");
        $q->execute(array(':id' => $assignmentId, ':t' => $tenantId));
        if ($q->rowCount() < 1) scc_out(404, false, 'Calendar color rule not found.');
        scc_audit($pdo, $tenantId, $branchId, $userId, 'CALENDAR_COLOR_RULE_DELETED', $assignmentId, array('deleted' => 1));
        scc_out(200, true, 'Calendar color deleted successfully.');
    }

    if ($action === 'save_color_order') {
        if (!$hasSortOrder) {
            scc_out(409, false, 'Run database/calendar-color-sort-order.sql once to enable persistent drag-and-drop ordering.');
        }
        $order = json_decode(scc_post('order_json', '[]'), true);
        if (!is_array($order)) scc_out(422, false, 'Invalid calendar color order.');
        $ids = array();
        foreach ($order as $raw) {
            $id = (int)$raw;
            if ($id > 0) $ids[$id] = $id;
        }
        $ids = array_values($ids);
        if (!$ids) scc_out(200, true, 'Calendar color order updated.');

        $placeholders = array();
        $params = array(':t' => $tenantId);
        foreach ($ids as $i => $id) {
            $key = ':id' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $check = $pdo->prepare("SELECT COUNT(*) FROM calendar_color_assignments WHERE tenant_id=:t AND id IN (" . implode(',', $placeholders) . ")");
        $check->execute($params);
        if ((int)$check->fetchColumn() !== count($ids)) scc_out(422, false, 'One or more calendar color rules are invalid.');

        $pdo->beginTransaction();
        $update = $pdo->prepare("UPDATE calendar_color_assignments SET sort_order=:sort, updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        foreach ($ids as $index => $id) {
            $update->execute(array(':sort' => ($index + 1) * 10, ':id' => $id, ':t' => $tenantId));
        }
        $pdo->commit();
        scc_audit($pdo, $tenantId, $branchId, $userId, 'CALENDAR_COLOR_ORDER_UPDATED', $userId, array('assignment_ids' => $ids));
        scc_out(200, true, 'Calendar color order updated.');
    }

    scc_out(400, false, 'Invalid calendar color action.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx schedule color API database error: ' . $e->getMessage());
    scc_out(500, false, 'Unable to update calendar colors.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx schedule color API error: ' . $e->getMessage());
    scc_out(500, false, 'Unable to update calendar colors.');
}
