<?php
/* FieldPlx Quote Templates API - 2026-09-15 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function qtOut($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qtPost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function qtDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('PDO database connection is not available.');
}

function qtTable(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) return $cache[$table];
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:n");
    $st->execute(array(':n' => $table));
    $cache[$table] = ((int)$st->fetchColumn() > 0);
    return $cache[$table];
}

function qtUserCan(PDO $pdo, $tenantId, $userId, $actionCode)
{
    $tenantId = (int)$tenantId;
    $userId = (int)$userId;
    $actionCode = strtolower(trim((string)$actionCode));
    if ($tenantId <= 0 || $userId <= 0 || $actionCode === '') return false;

    $st = $pdo->prepare("SELECT role_id FROM users WHERE id=:u AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
    $st->execute(array(':u' => $userId, ':t' => $tenantId));
    $roleId = (int)$st->fetchColumn();

    $p = $pdo->prepare("SELECT p.id FROM permissions p INNER JOIN modules m ON m.id=p.module_id WHERE m.module_code IN ('quotations','quotation','quotes') AND m.is_active=1 AND LOWER(p.action_code)=:a");
    $p->execute(array(':a' => $actionCode));
    $ids = array_values(array_unique(array_map('intval', $p->fetchAll(PDO::FETCH_COLUMN))));
    if (!$ids) return false;
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $up = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=? AND user_id=? AND permission_id IN ($ph) ORDER BY CASE access_type WHEN 'deny' THEN 0 ELSE 1 END, permission_id ASC");
    $up->execute(array_merge(array($tenantId, $userId), $ids));
    $direct = $up->fetchAll(PDO::FETCH_COLUMN);
    if ($direct) {
        if (in_array('deny', $direct, true)) return false;
        if (in_array('allow', $direct, true)) return true;
    }

    if ($roleId <= 0) return false;
    $rp = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=? AND role_id=? AND permission_id IN ($ph) ORDER BY CASE access_type WHEN 'deny' THEN 0 ELSE 1 END, permission_id ASC");
    $rp->execute(array_merge(array($tenantId, $roleId), $ids));
    $role = $rp->fetchAll(PDO::FETCH_COLUMN);
    if (!$role) return false;
    if (in_array('deny', $role, true)) return false;
    return in_array('allow', $role, true);
}

function qtAudit(PDO $pdo, $action, $tenantId, $branchId, $userId, $templateId, $old, $new)
{
    if (!function_exists('tenantAuditLog')) return;
    try {
        tenantAuditLog($pdo, $action, $tenantId, $branchId, $userId, 'quote_template', $templateId, $old, $new, array(
            'module' => 'Quotes',
            'audit_category' => 'GENERAL_ACTIVITY'
        ));
    } catch (Throwable $e) {
        error_log('Quote template audit: ' . $e->getMessage());
    }
}

$pdo = qtDb();
$tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : (!empty($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0);
$userId = !empty($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$branchId = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($tenantId <= 0 || $userId <= 0) qtOut(401, false, 'Authentication required.');

$csrf = trim((string)qtPost('csrf_token', ''));
$sessionCsrf = isset($_SESSION['quotations_csrf_token']) ? (string)$_SESSION['quotations_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    qtOut(419, false, 'Your quotation session expired. Refresh the page and try again.');
}

if (!qtTable($pdo, 'quote_templates')) {
    qtOut(503, false, 'Quote templates are not installed yet. Run sql/quote_templates.sql once, then refresh this page.');
}

$action = strtolower(trim((string)qtPost('action', 'list')));
$maxTemplates = 30;

try {
    if ($action === 'list') {
        if (!qtUserCan($pdo, $tenantId, $userId, 'view')) qtOut(403, false, 'You do not have permission to view quote templates.');
        $st = $pdo->prepare(
            "SELECT qt.id,qt.name,qt.description,qt.source_quote_id,qt.branch_id,qt.is_active,qt.created_at,qt.updated_at,
                    q.quote_no AS source_quote_no,q.title AS source_quote_title,c.display_name AS source_client_name
             FROM quote_templates qt
             INNER JOIN quotes q ON q.id=qt.source_quote_id AND q.tenant_id=qt.tenant_id
             LEFT JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id
             WHERE qt.tenant_id=:t AND qt.is_active=1
             ORDER BY qt.updated_at DESC,qt.id DESC"
        );
        $st->execute(array(':t' => $tenantId));
        qtOut(200, true, 'Quote templates loaded successfully.', array(
            'templates' => $st->fetchAll(PDO::FETCH_ASSOC),
            'max_templates' => $maxTemplates
        ));
    }

    if ($action === 'source_quotes') {
        if (!qtUserCan($pdo, $tenantId, $userId, 'create')) qtOut(403, false, 'You do not have permission to create quote templates.');
        $st = $pdo->prepare(
            "SELECT q.id,q.quote_no,q.title,q.total,q.status,q.updated_at,c.display_name AS client_name
             FROM quotes q
             INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id
             WHERE q.tenant_id=:t AND q.status<>'archived'
             ORDER BY q.updated_at DESC,q.id DESC
             LIMIT 150"
        );
        $st->execute(array(':t' => $tenantId));
        qtOut(200, true, 'Source quotes loaded successfully.', array('quotes' => $st->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($action === 'create') {
        if (!qtUserCan($pdo, $tenantId, $userId, 'create')) qtOut(403, false, 'You do not have permission to create quote templates.');
        $name = trim((string)qtPost('name', ''));
        $description = trim((string)qtPost('description', ''));
        $sourceQuoteId = (int)qtPost('source_quote_id', 0);
        if ($name === '' || $sourceQuoteId <= 0) qtOut(422, false, 'Enter a template name and select a source quote.');
        if (strlen($name) > 150) qtOut(422, false, 'Template name must be 150 characters or less.');
        if (strlen($description) > 500) qtOut(422, false, 'Template description must be 500 characters or less.');

        $count = $pdo->prepare("SELECT COUNT(*) FROM quote_templates WHERE tenant_id=:t AND is_active=1");
        $count->execute(array(':t' => $tenantId));
        if ((int)$count->fetchColumn() >= $maxTemplates) qtOut(422, false, 'A maximum of 30 active quote templates is allowed.');

        $q = $pdo->prepare("SELECT id,branch_id,quote_no,title FROM quotes WHERE id=:id AND tenant_id=:t LIMIT 1");
        $q->execute(array(':id' => $sourceQuoteId, ':t' => $tenantId));
        $source = $q->fetch(PDO::FETCH_ASSOC);
        if (!$source) qtOut(404, false, 'The selected source quote was not found.');

        $branch = !empty($source['branch_id']) ? (int)$source['branch_id'] : ($branchId > 0 ? $branchId : null);
        $st = $pdo->prepare("INSERT INTO quote_templates(tenant_id,branch_id,source_quote_id,name,description,is_active,created_by,created_at,updated_at) VALUES(:t,:b,:q,:n,:d,1,:u,NOW(),NOW())");
        $st->execute(array(
            ':t' => $tenantId,
            ':b' => $branch,
            ':q' => $sourceQuoteId,
            ':n' => $name,
            ':d' => $description !== '' ? $description : null,
            ':u' => $userId
        ));
        $id = (int)$pdo->lastInsertId();
        qtAudit($pdo, 'QUOTE_TEMPLATE_CREATED', $tenantId, $branchId, $userId, $id, null, array(
            'name' => $name,
            'source_quote_id' => $sourceQuoteId,
            'source_quote_no' => $source['quote_no']
        ));
        qtOut(200, true, 'Quote template created successfully.', array('template_id' => $id));
    }

    if ($action === 'delete') {
        if (!qtUserCan($pdo, $tenantId, $userId, 'update') && !qtUserCan($pdo, $tenantId, $userId, 'delete')) {
            qtOut(403, false, 'You do not have permission to delete quote templates.');
        }
        $id = (int)qtPost('template_id', 0);
        if ($id <= 0) qtOut(422, false, 'Invalid quote template.');
        $st = $pdo->prepare("SELECT * FROM quote_templates WHERE id=:id AND tenant_id=:t LIMIT 1");
        $st->execute(array(':id' => $id, ':t' => $tenantId));
        $old = $st->fetch(PDO::FETCH_ASSOC);
        if (!$old) qtOut(404, false, 'Quote template not found.');
        $up = $pdo->prepare("UPDATE quote_templates SET is_active=0,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        $up->execute(array(':id' => $id, ':t' => $tenantId));
        qtAudit($pdo, 'QUOTE_TEMPLATE_DELETED', $tenantId, $branchId, $userId, $id, $old, array('is_active' => 0));
        qtOut(200, true, 'Quote template deleted successfully.');
    }

    qtOut(400, false, 'Unsupported quote template action.');
} catch (PDOException $e) {
    error_log('Quote templates PDO: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        qtOut(409, false, 'A quote template with that name already exists.');
    }
    qtOut(500, false, 'Unable to process the quote template request.');
} catch (Throwable $e) {
    error_log('Quote templates: ' . $e->getMessage());
    qtOut(500, false, $e->getMessage());
}
