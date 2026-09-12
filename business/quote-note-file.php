<?php
require_once __DIR__ . '/includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$tenant = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$user = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
if ($tenant <= 0 || $user <= 0 || $fileId <= 0) { http_response_code(403); exit('Forbidden'); }

$stmt = $pdo->prepare("SELECT qf.original_name,qf.file_path,qf.mime_type,qf.file_size FROM quote_files qf INNER JOIN quotes q ON q.id=qf.quote_id AND q.tenant_id=qf.tenant_id WHERE qf.id=:id AND qf.tenant_id=:t AND qf.file_category='note_attachment' LIMIT 1");
$stmt->execute(array(':id'=>$fileId, ':t'=>$tenant));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('File not found'); }
$root = realpath(__DIR__ . '/private_uploads');
$full = realpath(__DIR__ . '/' . ltrim((string)$row['file_path'], '/'));
if (!$root || !$full || strpos($full, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) { http_response_code(404); exit('File not found'); }
$name = preg_replace('/[\r\n"]+/', '', basename((string)$row['original_name']));
$mime = trim((string)$row['mime_type']); if ($mime === '') $mime = 'application/octet-stream';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: inline; filename="' . $name . '"');
readfile($full);
exit;
