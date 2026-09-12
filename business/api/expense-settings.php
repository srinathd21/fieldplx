<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function es_out($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(array('success'=>(bool)$success,'message'=>(string)$message), $extra),
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

function es_table_exists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name");
    $stmt->execute(array(':table_name'=>$table));
    return (int)$stmt->fetchColumn() > 0;
}

function es_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $entityId, $values)
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
            'expense_accounting_code',
            $entityId,
            null,
            $values
        );
    } catch (Throwable $e) {
        error_log('FieldPlx expense settings audit error: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    es_out(405, false, 'Method not allowed.');
}

$token = es_post('csrf_token');
if (
    empty($_SESSION['expense_settings_csrf']) ||
    !is_string($_SESSION['expense_settings_csrf']) ||
    $token === '' ||
    !hash_equals($_SESSION['expense_settings_csrf'], $token)
) {
    es_out(419, false, 'Your form session expired. Refresh the page and try again.');
}

$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$userId = isset($currentTenantUserId) ? (int)$currentTenantUserId : (isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : 0);
$branchId = isset($currentBranchId) ? (int)$currentBranchId : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);

if ($tenantId <= 0) {
    es_out(401, false, 'Tenant session is not available.');
}

if (!es_table_exists($pdo, 'expense_accounting_codes')) {
    es_out(500, false, 'Expense accounting codes are not installed. Run the expense tracking settings migration.');
}

$action = es_post('action');

try {
    if ($action === 'list') {
        $stmt = $pdo->prepare("SELECT id, code, sort_order, created_at, updated_at FROM expense_accounting_codes WHERE tenant_id=:tenant_id AND is_active=1 AND deleted_at IS NULL ORDER BY sort_order ASC, code ASC, id ASC");
        $stmt->execute(array(':tenant_id'=>$tenantId));
        es_out(200, true, 'Accounting codes loaded.', array('codes'=>$stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($action === 'save') {
        $id = (int)es_post('id', '0');
        $code = preg_replace('/\s+/u', ' ', es_post('code'));
        $code = trim((string)$code);

        if ($code === '') {
            es_out(422, false, 'Accounting code is required.');
        }
        if (mb_strlen($code, 'UTF-8') > 120) {
            es_out(422, false, 'Accounting code cannot be longer than 120 characters.');
        }

        $dup = $pdo->prepare("SELECT id FROM expense_accounting_codes WHERE tenant_id=:tenant_id AND LOWER(code)=LOWER(:code) AND is_active=1 AND deleted_at IS NULL AND id<>:id LIMIT 1");
        $dup->execute(array(':tenant_id'=>$tenantId, ':code'=>$code, ':id'=>$id));
        if ($dup->fetchColumn()) {
            es_out(409, false, 'This accounting code already exists.');
        }

        if ($id > 0) {
            $find = $pdo->prepare("SELECT id, code FROM expense_accounting_codes WHERE id=:id AND tenant_id=:tenant_id AND deleted_at IS NULL LIMIT 1");
            $find->execute(array(':id'=>$id, ':tenant_id'=>$tenantId));
            $old = $find->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                es_out(404, false, 'Accounting code not found.');
            }

            $stmt = $pdo->prepare("UPDATE expense_accounting_codes SET code=:code, is_active=1, updated_by=:updated_by, updated_at=NOW() WHERE id=:id AND tenant_id=:tenant_id");
            $stmt->execute(array(':code'=>$code, ':updated_by'=>$userId > 0 ? $userId : null, ':id'=>$id, ':tenant_id'=>$tenantId));

            /* Keep existing unlinked text-based expense values consistent when they exactly used the old code. */
            if (es_table_exists($pdo, 'expenses')) {
                $sync = $pdo->prepare("UPDATE expenses SET accounting_code=:new_code WHERE tenant_id=:tenant_id AND accounting_code=:old_code AND deleted_at IS NULL");
                $sync->execute(array(':new_code'=>$code, ':tenant_id'=>$tenantId, ':old_code'=>$old['code']));
            }

            es_audit($pdo, $tenantId, $branchId, $userId, 'EXPENSE_ACCOUNTING_CODE_UPDATED', $id, array('old_code'=>$old['code'], 'code'=>$code));
            es_out(200, true, 'Accounting code updated successfully.', array('id'=>$id, 'code'=>$code));
        }

        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+100 FROM expense_accounting_codes WHERE tenant_id=:tenant_id AND deleted_at IS NULL");
        $sortStmt->execute(array(':tenant_id'=>$tenantId));
        $sortOrder = (int)$sortStmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO expense_accounting_codes (tenant_id, code, sort_order, is_active, created_by, created_at, updated_at) VALUES (:tenant_id,:code,:sort_order,1,:created_by,NOW(),NOW())");
        $stmt->execute(array(':tenant_id'=>$tenantId, ':code'=>$code, ':sort_order'=>$sortOrder, ':created_by'=>$userId > 0 ? $userId : null));
        $id = (int)$pdo->lastInsertId();

        es_audit($pdo, $tenantId, $branchId, $userId, 'EXPENSE_ACCOUNTING_CODE_CREATED', $id, array('code'=>$code));
        es_out(201, true, 'Accounting code created successfully.', array('id'=>$id, 'code'=>$code));
    }

    if ($action === 'delete') {
        $id = (int)es_post('id', '0');
        if ($id <= 0) {
            es_out(422, false, 'Invalid accounting code.');
        }
        $stmt = $pdo->prepare("UPDATE expense_accounting_codes SET is_active=0, deleted_at=NOW(), updated_by=:updated_by, updated_at=NOW() WHERE id=:id AND tenant_id=:tenant_id AND deleted_at IS NULL");
        $stmt->execute(array(':updated_by'=>$userId > 0 ? $userId : null, ':id'=>$id, ':tenant_id'=>$tenantId));
        if ($stmt->rowCount() < 1) {
            es_out(404, false, 'Accounting code not found.');
        }
        es_audit($pdo, $tenantId, $branchId, $userId, 'EXPENSE_ACCOUNTING_CODE_DELETED', $id, array());
        es_out(200, true, 'Accounting code removed successfully.');
    }

    es_out(400, false, 'Invalid action.');

} catch (PDOException $e) {
    error_log('FieldPlx expense settings database error: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        es_out(409, false, 'This accounting code already exists.');
    }
    es_out(500, false, 'Unable to complete the expense tracking settings action.');
} catch (Throwable $e) {
    error_log('FieldPlx expense settings error: ' . $e->getMessage());
    es_out(500, false, 'Unable to complete the expense tracking settings action.');
}
