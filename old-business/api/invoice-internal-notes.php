<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Private Invoice Notes API
|--------------------------------------------------------------------------
| Returns internal invoice notes only when the current tenant user is:
| - a tenant/role administrator, or
| - the note author, or
| - explicitly mentioned on that note.
|
| PHP 7.2 compatible.
*/

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function iinRes($code, $ok, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    echo json_encode(array_merge(array(
        'success' => (bool)$ok,
        'message' => (string)$message
    ), is_array($extra) ? $extra : array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function iinTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t'=>$table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function iinIsAdmin(PDO $pdo, $tenantId, $userId)
{
    if ($tenantId <= 0 || $userId <= 0 || !iinTable($pdo, 'users')) return false;

    $join = iinTable($pdo, 'roles')
        ? " LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id "
        : "";
    $roleAdmin = iinTable($pdo, 'roles') ? "COALESCE(r.is_admin,0)" : "0";

    $stmt = $pdo->prepare(
        "SELECT CASE WHEN COALESCE(u.is_tenant_admin,0)=1 OR " . $roleAdmin . "=1 THEN 1 ELSE 0 END admin_flag
         FROM users u" . $join . "
         WHERE u.id=:u AND u.tenant_id=:t LIMIT 1"
    );
    $stmt->execute(array(':u'=>$userId, ':t'=>$tenantId));
    return ((int)$stmt->fetchColumn() === 1);
}

$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);

if ($tenantId <= 0 || $userId <= 0) {
    iinRes(401, false, 'Authentication required.');
}

$csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
$sessionCsrf = isset($_SESSION['invoices_csrf_token']) ? (string)$_SESSION['invoices_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    iinRes(419, false, 'Your form session expired. Refresh and try again.');
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'load';
if ($action !== 'load') {
    iinRes(400, false, 'Unknown action.');
}

$invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
if ($invoiceId <= 0) {
    iinRes(422, false, 'Invoice is required.');
}

try {
    if (!iinTable($pdo, 'invoices')) {
        iinRes(500, false, 'Invoices table is unavailable.');
    }

    $invoiceStmt = $pdo->prepare("SELECT id,invoice_no FROM invoices WHERE id=:i AND tenant_id=:t LIMIT 1");
    $invoiceStmt->execute(array(':i'=>$invoiceId, ':t'=>$tenantId));
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        iinRes(404, false, 'Invoice not found.');
    }

    if (!iinTable($pdo, 'invoice_internal_notes') || !iinTable($pdo, 'invoice_internal_note_mentions')) {
        iinRes(500, false, 'Internal invoice notes are not installed. Run migration_invoice_internal_notes.sql once.');
    }

    $isAdmin = iinIsAdmin($pdo, $tenantId, $userId);

    $sql = "SELECT
                n.id,
                n.invoice_id,
                n.note_text,
                n.created_by,
                n.created_at,
                TRIM(CONCAT(COALESCE(u.first_name,''),
                    CASE WHEN u.last_name IS NOT NULL AND u.last_name<>'' THEN CONCAT(' ',u.last_name) ELSE '' END
                )) created_by_name
            FROM invoice_internal_notes n
            LEFT JOIN users u ON u.id=n.created_by AND u.tenant_id=n.tenant_id
            WHERE n.tenant_id=:t
              AND n.invoice_id=:i";

    $params = array(':t'=>$tenantId, ':i'=>$invoiceId);

    if (!$isAdmin) {
        $sql .= " AND (
                    n.created_by=:author_user
                    OR EXISTS (
                        SELECT 1
                        FROM invoice_internal_note_mentions m
                        WHERE m.tenant_id=n.tenant_id
                          AND m.note_id=n.id
                          AND m.user_id=:mentioned_user
                    )
                  )";
        $params[':author_user'] = $userId;
        $params[':mentioned_user'] = $userId;
    }

    $sql .= " ORDER BY n.created_at DESC,n.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$notes) {
        iinRes(200, true, 'No internal notes are visible to this user.', array(
            'notes'=>array(),
            'is_admin'=>$isAdmin ? 1 : 0,
            'invoice_id'=>$invoiceId
        ));
    }

    $noteIds = array();
    foreach ($notes as $note) $noteIds[] = (int)$note['id'];

    $placeholders = array();
    $commonParams = array(':tenant_mentions'=>$tenantId);
    foreach ($noteIds as $idx => $noteId) {
        $key = ':n' . $idx;
        $placeholders[] = $key;
        $commonParams[$key] = $noteId;
    }

    $mentionsByNote = array();
    $mentionSql = "SELECT
                        m.note_id,
                        u.id user_id,
                        TRIM(CONCAT(COALESCE(u.first_name,''),
                            CASE WHEN u.last_name IS NOT NULL AND u.last_name<>'' THEN CONCAT(' ',u.last_name) ELSE '' END
                        )) name,
                        u.email,
                        u.job_title
                   FROM invoice_internal_note_mentions m
                   INNER JOIN users u ON u.id=m.user_id AND u.tenant_id=m.tenant_id
                   WHERE m.tenant_id=:tenant_mentions
                     AND m.note_id IN (" . implode(',', $placeholders) . ")
                   ORDER BY m.id";
    $mStmt = $pdo->prepare($mentionSql);
    $mStmt->execute($commonParams);
    foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $nid = (int)$row['note_id'];
        if (!isset($mentionsByNote[$nid])) $mentionsByNote[$nid] = array();
        $mentionsByNote[$nid][] = array(
            'user_id'=>(int)$row['user_id'],
            'name'=>trim((string)$row['name']) !== '' ? (string)$row['name'] : 'Team member',
            'email'=>(string)$row['email'],
            'job_title'=>(string)$row['job_title']
        );
    }

    $attachmentsByNote = array();
    if (iinTable($pdo, 'attachments')) {
        $aParams = array(':tenant_attachments'=>$tenantId);
        $aMarks = array();
        foreach ($noteIds as $idx => $noteId) {
            $key = ':a' . $idx;
            $aMarks[] = $key;
            $aParams[$key] = $noteId;
        }
        $aSql = "SELECT id,related_id,file_name,file_mime,file_size,attachment_type,created_at
                 FROM attachments
                 WHERE tenant_id=:tenant_attachments
                   AND related_type='invoice_internal_note'
                   AND related_id IN (" . implode(',', $aMarks) . ")
                 ORDER BY id";
        $aStmt = $pdo->prepare($aSql);
        $aStmt->execute($aParams);
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nid = (int)$row['related_id'];
            if (!isset($attachmentsByNote[$nid])) $attachmentsByNote[$nid] = array();
            $attachmentsByNote[$nid][] = array(
                'id'=>(int)$row['id'],
                'file_name'=>(string)$row['file_name'],
                'file_mime'=>(string)$row['file_mime'],
                'file_size'=>(int)$row['file_size'],
                'attachment_type'=>(string)$row['attachment_type'],
                'created_at'=>$row['created_at'],
                'download_url'=>'api/invoice-note-file.php?attachment_id=' . (int)$row['id']
            );
        }
    }

    $result = array();
    foreach ($notes as $note) {
        $nid = (int)$note['id'];
        $creator = trim((string)$note['created_by_name']);
        $result[] = array(
            'id'=>$nid,
            'invoice_id'=>(int)$note['invoice_id'],
            'note_text'=>$note['note_text'] !== null ? (string)$note['note_text'] : '',
            'created_by'=>$note['created_by'] !== null ? (int)$note['created_by'] : null,
            'created_by_name'=>$creator !== '' ? $creator : 'Team member',
            'created_at'=>$note['created_at'],
            'mentions'=>isset($mentionsByNote[$nid]) ? $mentionsByNote[$nid] : array(),
            'attachments'=>isset($attachmentsByNote[$nid]) ? $attachmentsByNote[$nid] : array()
        );
    }

    iinRes(200, true, 'Internal invoice notes loaded.', array(
        'notes'=>$result,
        'is_admin'=>$isAdmin ? 1 : 0,
        'invoice_id'=>$invoiceId,
        'invoice_no'=>(string)$invoice['invoice_no']
    ));

} catch (Throwable $e) {
    error_log('invoice internal notes load ' . $e->getMessage());
    iinRes(500, false, 'Unable to load internal invoice notes.');
}
