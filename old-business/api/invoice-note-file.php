<?php
/* Secure download/view endpoint for private invoice note attachments. */

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function infFail($code, $message)
{
    http_response_code((int)$code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    echo (string)$message;
    exit;
}

function infTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t'=>$table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function infIsAdmin(PDO $pdo, $tenantId, $userId)
{
    if (!infTable($pdo, 'users')) return false;
    $join = infTable($pdo, 'roles')
        ? " LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id "
        : "";
    $roleAdmin = infTable($pdo, 'roles') ? "COALESCE(r.is_admin,0)" : "0";
    $stmt = $pdo->prepare(
        "SELECT CASE WHEN COALESCE(u.is_tenant_admin,0)=1 OR " . $roleAdmin . "=1 THEN 1 ELSE 0 END
         FROM users u" . $join . "
         WHERE u.id=:u AND u.tenant_id=:t LIMIT 1"
    );
    $stmt->execute(array(':u'=>$userId, ':t'=>$tenantId));
    return ((int)$stmt->fetchColumn() === 1);
}

$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
if ($tenantId <= 0 || $userId <= 0) infFail(401, 'Authentication required.');

$attachmentId = isset($_GET['attachment_id']) ? (int)$_GET['attachment_id'] : 0;
if ($attachmentId <= 0) infFail(422, 'Attachment is required.');

try {
    if (!infTable($pdo, 'attachments') || !infTable($pdo, 'invoice_internal_notes') || !infTable($pdo, 'invoice_internal_note_mentions')) {
        infFail(404, 'Private invoice note attachments are not installed.');
    }

    $stmt = $pdo->prepare(
        "SELECT
             a.id,a.file_name,a.file_path,a.file_mime,a.file_size,a.related_id note_id,
             n.invoice_id,n.created_by
         FROM attachments a
         INNER JOIN invoice_internal_notes n
             ON n.id=a.related_id
            AND n.tenant_id=a.tenant_id
         WHERE a.id=:a
           AND a.tenant_id=:t
           AND a.related_type='invoice_internal_note'
         LIMIT 1"
    );
    $stmt->execute(array(':a'=>$attachmentId, ':t'=>$tenantId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) infFail(404, 'Attachment not found.');

    $allowed = infIsAdmin($pdo, $tenantId, $userId) || ((int)$row['created_by'] === $userId);
    if (!$allowed) {
        $m = $pdo->prepare("SELECT 1 FROM invoice_internal_note_mentions WHERE tenant_id=:t AND note_id=:n AND user_id=:u LIMIT 1");
        $m->execute(array(':t'=>$tenantId, ':n'=>(int)$row['note_id'], ':u'=>$userId));
        $allowed = (bool)$m->fetchColumn();
    }
    if (!$allowed) infFail(403, 'You do not have access to this private attachment.');

    $businessRoot = realpath(dirname(__DIR__));
    $privateRoot = realpath(dirname(__DIR__) . '/private_uploads/invoice-notes');
    if ($businessRoot === false || $privateRoot === false) infFail(404, 'Attachment file is unavailable.');

    $relative = ltrim(str_replace('\\', '/', (string)$row['file_path']), '/');
    if (strpos($relative, 'private_uploads/invoice-notes/') !== 0) {
        infFail(403, 'Invalid private attachment path.');
    }

    $absolute = realpath($businessRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if ($absolute === false || !is_file($absolute)) infFail(404, 'Attachment file is unavailable.');

    $rootPrefix = rtrim($privateRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($absolute, $rootPrefix) !== 0) infFail(403, 'Invalid private attachment path.');

    $mime = trim((string)$row['file_mime']);
    if ($mime === '' && function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)@finfo_file($fi, $absolute);
            @finfo_close($fi);
        }
    }
    if ($mime === '' || !preg_match('/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+$/', $mime)) {
        $mime = 'application/octet-stream';
    }

    $fileName = basename((string)$row['file_name']);
    $safeAsciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $fileName);
    if ($safeAsciiName === '') $safeAsciiName = 'attachment';
    $disposition = (strpos($mime, 'image/') === 0 || $mime === 'application/pdf') ? 'inline' : 'attachment';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($absolute));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header("Content-Disposition: " . $disposition . "; filename=\"" . addcslashes($safeAsciiName, "\\\"") . "\"; filename*=UTF-8''" . rawurlencode($fileName));
    readfile($absolute);
    exit;

} catch (Throwable $e) {
    error_log('invoice note file ' . $e->getMessage());
    infFail(500, 'Unable to open this private attachment.');
}
