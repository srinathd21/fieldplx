<?php
/* FieldPlx Client View API - Version 4.4.0 - Jobber-inspired client workspace */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}
$platformSmtpFile = __DIR__ . '/../includes/platform-smtp.php';
if (is_file($platformSmtpFile)) {
    require_once $platformSmtpFile;
}
/* Load the exact SMTP secret definition used by the Platform SMTP API.
   business.v1 and platform may live in different sibling folders, so discover it safely. */
$smtpSecretCandidates = array(
    __DIR__ . '/../includes/smtp-secret.php',
    dirname(__DIR__, 2) . '/includes/smtp-secret.php',
    dirname(__DIR__, 2) . '/platform/includes/smtp-secret.php',
    dirname(__DIR__, 2) . '/platform.v1/includes/smtp-secret.php',
    dirname(__DIR__, 2) . '/admin/includes/smtp-secret.php'
);
$rootSecretMatches = glob(dirname(__DIR__, 2) . '/*/includes/smtp-secret.php');
if (is_array($rootSecretMatches)) {
    foreach ($rootSecretMatches as $smtpSecretMatch) $smtpSecretCandidates[] = $smtpSecretMatch;
}
foreach (array_unique($smtpSecretCandidates) as $smtpSecretFile) {
    if (!is_file($smtpSecretFile)) continue;
    require_once $smtpSecretFile;
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) break;
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function cvr($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cvp($key, $default = '')
{
    return isset($_POST[$key]) && !is_array($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function cvt(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t' => $table));
    $cache[$table] = (int)$q->fetchColumn() > 0;
    return $cache[$table];
}

function cvc(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = (int)$q->fetchColumn() > 0;
    return $cache[$key];
}

function cvClient(PDO $pdo, $tenantId, $clientId)
{
    $q = $pdo->prepare("SELECT * FROM clients WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id' => $clientId, ':t' => $tenantId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        cvr(404, false, 'Client not found.');
    }
    return $row;
}

function cvUserContext(PDO $pdo, $tenantId, $userId)
{
    $out = array('role_id' => 0, 'is_tenant_admin' => 0, 'is_role_admin' => 0, 'branch_id' => 0, 'email' => '', 'name' => 'FieldPlx User');
    if (!cvt($pdo, 'users') || $userId <= 0) {
        return $out;
    }
    $roleJoin = cvt($pdo, 'roles') && cvc($pdo, 'users', 'role_id') ? "LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id" : '';
    $roleAdmin = cvt($pdo, 'roles') && cvc($pdo, 'roles', 'is_admin') ? 'COALESCE(r.is_admin,0)' : '0';
    $deleted = cvc($pdo, 'users', 'deleted_at') ? ' AND u.deleted_at IS NULL' : '';
    $q = $pdo->prepare("SELECT " . (cvc($pdo, 'users', 'role_id') ? 'u.role_id' : '0 AS role_id') . ", "
        . (cvc($pdo, 'users', 'is_tenant_admin') ? 'u.is_tenant_admin' : '0 AS is_tenant_admin') . ", "
        . (cvc($pdo, 'users', 'branch_id') ? 'u.branch_id' : '0 AS branch_id') . ", "
        . "u.email, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS user_name, $roleAdmin AS is_role_admin "
        . "FROM users u $roleJoin WHERE u.id=:u AND u.tenant_id=:t$deleted LIMIT 1");
    $q->execute(array(':u' => $userId, ':t' => $tenantId));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $out['role_id'] = (int)$r['role_id'];
        $out['is_tenant_admin'] = (int)$r['is_tenant_admin'];
        $out['is_role_admin'] = (int)$r['is_role_admin'];
        $out['branch_id'] = (int)$r['branch_id'];
        $out['email'] = trim((string)$r['email']);
        $out['name'] = trim((string)$r['user_name']) !== '' ? trim((string)$r['user_name']) : 'FieldPlx User';
    }
    return $out;
}

function cvHasPermission(PDO $pdo, $tenantId, $userId, $permissionCode, $ctx)
{
    if (!empty($ctx['is_tenant_admin']) || !empty($ctx['is_role_admin'])) {
        return true;
    }
    if (!cvt($pdo, 'permissions')) {
        return false;
    }
    $q = $pdo->prepare("SELECT id FROM permissions WHERE permission_code=:p LIMIT 1");
    $q->execute(array(':p' => $permissionCode));
    $permissionId = (int)$q->fetchColumn();
    if ($permissionId <= 0) {
        return false;
    }
    if (cvt($pdo, 'user_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':u' => $userId, ':p' => $permissionId));
        $v = $q->fetchColumn();
        if ($v !== false) {
            return $v === 'allow';
        }
    }
    if (!empty($ctx['role_id']) && cvt($pdo, 'role_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':r' => (int)$ctx['role_id'], ':p' => $permissionId));
        return $q->fetchColumn() === 'allow';
    }
    return false;
}

function cvRequirePermission(PDO $pdo, $tenantId, $userId, $permissionCode, $ctx)
{
    if (!cvHasPermission($pdo, $tenantId, $userId, $permissionCode, $ctx)) {
        cvr(403, false, 'You do not have permission to perform this client action.');
    }
}

function cvAudit(PDO $pdo, $tenantId, $branchId, $userId, $action, $clientId, $oldData, $newData)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog($pdo, $action, $tenantId, $branchId, $userId, 'client', $clientId, $oldData, $newData);
        } catch (Throwable $e) {
            error_log('client view audit: ' . $e->getMessage());
        }
    }
}

function cvActivity(PDO $pdo, $tenantId, $branchId, $userId, $eventType, $clientId, $title, $details)
{
    if (!cvt($pdo, 'activity_events')) {
        return;
    }
    try {
        $q = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client,created_at)
                            VALUES(:t,:b,:u,'user',:e,'client',:r,:c,:title,:d,0,NOW())");
        $q->execute(array(
            ':t' => $tenantId,
            ':b' => $branchId > 0 ? $branchId : null,
            ':u' => $userId > 0 ? $userId : null,
            ':e' => substr((string)$eventType, 0, 120),
            ':r' => $clientId,
            ':c' => $clientId,
            ':title' => substr((string)$title, 0, 255),
            ':d' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ));
    } catch (Throwable $e) {
        error_log('client view activity: ' . $e->getMessage());
    }
}

function cvNotifyMentions(PDO $pdo, $tenantId, $clientId, $authorUserId, $authorName, $notes)
{
    if (trim((string)$notes) === '' || !cvt($pdo, 'users') || !cvt($pdo, 'in_app_notifications')) {
        return array();
    }
    $deleted = cvc($pdo, 'users', 'deleted_at') ? ' AND deleted_at IS NULL' : '';
    $q = $pdo->prepare("SELECT id,first_name,last_name,email FROM users WHERE tenant_id=:t AND status='active'$deleted ORDER BY id");
    $q->execute(array(':t' => $tenantId));
    $notified = array();
    $insert = $pdo->prepare("INSERT INTO in_app_notifications(tenant_id,user_id,title,message,related_type,related_id,action_url,icon_name,is_read,created_at)
                             VALUES(:t,:u,:title,:message,'client',:c,:url,'person-lines-fill',0,NOW())");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $uid = (int)$user['id'];
        if ($uid <= 0 || $uid === (int)$authorUserId) continue;
        $first = trim((string)$user['first_name']);
        $full = trim($first . ' ' . (string)$user['last_name']);
        $email = trim((string)$user['email']);
        $emailLocal = $email !== '' ? strstr($email, '@', true) : '';
        $tokens = array();
        if ($full !== '') $tokens[] = '@' . $full;
        if ($first !== '') $tokens[] = '@' . $first;
        if ($emailLocal !== '') $tokens[] = '@' . $emailLocal;
        $matched = false;
        foreach (array_unique($tokens) as $token) {
            if ($token !== '@' && stripos((string)$notes, $token) !== false) {
                $matched = true;
                break;
            }
        }
        if (!$matched) continue;
        $insert->execute(array(
            ':t' => $tenantId,
            ':u' => $uid,
            ':title' => 'Mentioned in a client note',
            ':message' => ($authorName !== '' ? $authorName : 'A team member') . ' mentioned you in a note for client #' . $clientId . '.',
            ':c' => $clientId,
            ':url' => 'client-view.php?client_id=' . $clientId
        ));
        $notified[] = $uid;
    }
    return $notified;
}

function cvHtmlEmail($title, $clientName, $message)
{
    $safeTitle = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars((string)$clientName, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8'));
    return '<!doctype html><html><body style="margin:0;background:#f5f7f9;font-family:Arial,Helvetica,sans-serif;color:#173744">'
        . '<div style="max-width:680px;margin:0 auto;padding:28px 14px">'
        . '<div style="padding:20px 24px;background:#0b3140;color:#fff;border-radius:10px 10px 0 0">'
        . '<div style="font-size:12px;opacity:.8">FieldPlx</div><div style="margin-top:4px;font-size:21px;font-weight:700">' . $safeTitle . '</div></div>'
        . '<div style="padding:24px;background:#fff;border:1px solid #dde5e9;border-top:0;border-radius:0 0 10px 10px">'
        . '<div style="line-height:1.6">' . $safeMessage . '</div>'
        . '<p style="margin:24px 0 0;color:#6b7f89;font-size:12px">Sent from FieldPlx</p></div></div></body></html>';
}

function cvSmtpSecretKey()
{
    $key = '';
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $key = trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY);
    }
    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) $key = trim((string)$env);
    }
    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) $key = trim((string)$env);
    }
    if ($key === '' || $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY') {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured. Use the same permanent SMTP encryption key used by Platform SMTP.');
    }
    if (strlen($key) < 32) {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.');
    }
    return hash('sha256', $key, true);
}

function cvDecryptStoredSmtpPassword($stored, $tenantId = 0)
{
    $stored = trim((string)$stored);
    if ($stored === '') return '';
    $errors = array();

    /* Current Platform SMTP format: v1: + base64(16-byte IV + AES-256-CBC ciphertext). */
    if (strpos($stored, 'v1:') === 0) {
        try {
            if (!function_exists('openssl_decrypt')) throw new RuntimeException('OpenSSL extension is unavailable.');
            $raw = base64_decode(substr($stored, 3), true);
            if ($raw === false || strlen($raw) <= 16) throw new RuntimeException('Stored payload is invalid.');
            $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', cvSmtpSecretKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
            if ($plain !== false && trim((string)$plain) !== '') return $plain;
            $errors[] = 'Platform key did not decrypt the password.';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    /* Shared helper compatibility. Try it even for v1 records because it may know the deployment's exact secret location. */
    if (function_exists('fieldplxDecryptSmtpPassword')) {
        try {
            $plain = fieldplxDecryptSmtpPassword($stored);
            if (trim((string)$plain) !== '') return $plain;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    /* Older tenant/branch SMTP records used base64(IV+cipher) with a tenant-derived FIELDPLX_APP_KEY seed. */
    try {
        $legacyPayload = strpos($stored, 'v1:') === 0 ? substr($stored, 3) : $stored;
        $raw = base64_decode($legacyPayload, true);
        if ($raw !== false && strlen($raw) > 16 && function_exists('openssl_decrypt')) {
            $envKey = getenv('FIELDPLX_APP_KEY');
            if ($envKey === false || trim((string)$envKey) === '') {
                $seed = (defined('DB_NAME') ? DB_NAME : '') . '|' . (defined('DB_USER') ? DB_USER : '') . '|' . (defined('DB_PASS') ? DB_PASS : '') . '|' . (int)$tenantId;
            } else {
                $seed = trim((string)$envKey) . '|' . (int)$tenantId;
            }
            $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', hash('sha256', $seed, true), OPENSSL_RAW_DATA, substr($raw, 0, 16));
            if ($plain !== false && trim((string)$plain) !== '') return $plain;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    throw new RuntimeException('SMTP password could not be decrypted with the configured SMTP secret. Re-save and test the SMTP configuration.');
}

function cvGetSmtp(PDO $pdo, $tenantId, $branchId)
{
    if (!cvt($pdo, 'smtp_configurations')) {
        throw new RuntimeException('SMTP configuration table is not installed.');
    }

    $candidates = array();
    /* Prefer an SMTP configuration that has actually passed its send test. A broken default must not block another tested Platform SMTP. */
    $q = $pdo->query("SELECT * FROM smtp_configurations
                      WHERE scope_type='platform' AND tenant_id IS NULL AND branch_id IS NULL AND is_active=1
                      ORDER BY CASE WHEN last_test_status='success' THEN 0 ELSE 1 END,
                               is_default DESC,
                               COALESCE(last_tested_at,updated_at,created_at) DESC,
                               id DESC");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['_smtp_source'] = 'platform';
        $candidates[] = $row;
    }

    /* Fallback for tenants that intentionally use their own SMTP. */
    $q = $pdo->prepare("SELECT * FROM smtp_configurations
                        WHERE tenant_id=:t AND is_active=1
                          AND scope_type IN('tenant','branch')
                          AND (scope_type='tenant' OR (scope_type='branch' AND branch_id=:b))
                        ORDER BY CASE WHEN last_test_status='success' THEN 0 ELSE 1 END,
                                 CASE WHEN scope_type='branch' AND branch_id=:b2 THEN 0 ELSE 1 END,
                                 is_default DESC,
                                 COALESCE(last_tested_at,updated_at,created_at) DESC,
                                 id DESC");
    $branchKey = $branchId > 0 ? $branchId : -1;
    $q->execute(array(':t'=>$tenantId, ':b'=>$branchKey, ':b2'=>$branchKey));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['_smtp_source'] = (string)$row['scope_type'];
        $candidates[] = $row;
    }

    if (!$candidates) {
        throw new RuntimeException('No active SMTP configuration was found. Configure and test SMTP first.');
    }

    $decryptErrors = array();
    foreach ($candidates as $config) {
        try {
            $password = cvDecryptStoredSmtpPassword(isset($config['password_encrypted']) ? $config['password_encrypted'] : '', $tenantId);
            if (trim((string)(isset($config['username']) ? $config['username'] : '')) !== '' && trim((string)$password) === '') {
                throw new RuntimeException('Password is empty.');
            }
            return array($config, $password, isset($config['_smtp_source']) ? $config['_smtp_source'] : 'smtp');
        } catch (Throwable $e) {
            $decryptErrors[] = '#' . (int)$config['id'] . ' ' . (isset($config['config_name']) ? (string)$config['config_name'] : 'SMTP') . ': ' . $e->getMessage();
            error_log('Client View SMTP candidate skipped: ' . end($decryptErrors));
        }
    }

    throw new RuntimeException('No active SMTP configuration could be decrypted. Re-save and test the SMTP password in SMTP settings.');
}

function cvComposerAutoloadPath()
{
    $projectRoot = dirname(__DIR__, 2);
    $paths = array(
        $projectRoot . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php'
    );
    foreach ($paths as $path) {
        if (is_file($path)) return $path;
    }
    return '';
}

function cvInstalledPhpMailerVersion($autoloadPath)
{
    if ($autoloadPath === '') return '';
    $installedJson = dirname($autoloadPath) . '/composer/installed.json';
    if (!is_file($installedJson)) return '';
    $raw = @file_get_contents($installedJson);
    if ($raw === false) return '';
    $data = json_decode($raw, true);
    if (!is_array($data)) return '';
    $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;
    foreach ($packages as $package) {
        if (is_array($package) && isset($package['name']) && $package['name'] === 'phpmailer/phpmailer') {
            return isset($package['version']) ? (string)$package['version'] : '';
        }
    }
    return '';
}

function cvLoadPhpMailer()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) return;
    $autoloadPath = cvComposerAutoloadPath();
    if ($autoloadPath === '') {
        throw new RuntimeException('Composer vendor/autoload.php was not found. Install PHPMailer 6.9.x in the FieldPlx project root.');
    }
    $version = cvInstalledPhpMailerVersion($autoloadPath);
    if (PHP_VERSION_ID < 80100 && $version !== '' && preg_match('/^v?7\\./i', $version)) {
        throw new RuntimeException('PHPMailer ' . $version . ' is not compatible with PHP ' . PHP_VERSION . '. Install PHPMailer 6.9.x.');
    }
    require_once $autoloadPath;
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('PHPMailer could not be loaded from Composer autoload.php.');
    }
}

function cvSendSmtp(PDO $pdo, $tenantId, $branchId, $to, $subject, $html, $attachments, $copyEmail)
{
    list($config, $password, $smtpSource) = cvGetSmtp($pdo, $tenantId, $branchId);
    $to = trim((string)$to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The client does not have a valid email address.');
    }

    cvLoadPhpMailer();
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string)$config['host']);
    $mail->Port = (int)$config['port'];
    $mail->Timeout = 30;
    if (property_exists($mail, 'Timelimit')) $mail->Timelimit = 30;
    $mail->SMTPDebug = 0;
    $mail->SMTPKeepAlive = false;

    $username = trim((string)$config['username']);
    $mail->SMTPAuth = $username !== '';
    if ($mail->SMTPAuth) {
        $mail->Username = $username;
        $mail->Password = (string)$password;
    }

    $encryption = strtolower(trim((string)$config['encryption']));
    if ($encryption === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
    } elseif ($encryption === 'tls' || $encryption === 'starttls') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $fromEmail = trim((string)$config['from_email']);
    if ($fromEmail === '') $fromEmail = $username;
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The configured SMTP From Email is invalid.');
    }
    $fromName = trim((string)$config['from_name']);
    if ($fromName === '') $fromName = 'FieldPlx';
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);

    $replyTo = trim((string)$config['reply_to_email']);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($replyTo);
    }
    $mail->addAddress($to);
    if ($copyEmail !== '' && filter_var($copyEmail, FILTER_VALIDATE_EMAIL) && strcasecmp($copyEmail, $to) !== 0) {
        $mail->addCC($copyEmail);
    }

    foreach ((array)$attachments as $attachment) {
        $path = isset($attachment['absolute_path']) ? (string)$attachment['absolute_path'] : '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('One selected attachment is no longer available to send.');
        }
        $name = isset($attachment['name']) ? basename((string)$attachment['name']) : basename($path);
        $mail->addAttachment($path, $name);
    }

    $mail->isHTML(true);
    $mail->Subject = (string)$subject;
    $mail->Body = (string)$html;
    $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(array('<br>','<br/>','<br />','</p>'), array("\n","\n","\n","\n\n"), $html)), ENT_QUOTES, 'UTF-8'));
    $mail->send();
    return (int)$config['id'];
}

function cvBusinessName(PDO $pdo, $tenantId)
{
    if (!cvt($pdo, 'tenants')) return 'FieldPlx';
    $q = $pdo->prepare("SELECT COALESCE(NULLIF(display_name,''),NULLIF(legal_name,''),'FieldPlx') FROM tenants WHERE id=:t LIMIT 1");
    $q->execute(array(':t'=>$tenantId));
    $name = trim((string)$q->fetchColumn());
    return $name !== '' ? $name : 'FieldPlx';
}

function cvLoginInviteHtml($businessName, $clientName, $loginUrl, $email, $temporaryPassword)
{
    $business = htmlspecialchars((string)$businessName, ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars((string)$clientName, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars((string)$loginUrl, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars((string)$email, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars((string)$temporaryPassword, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html><body style="margin:0;background:#fff;font-family:Arial,Helvetica,sans-serif;color:#46535d">'
        . '<div style="max-width:760px;margin:0 auto;padding:26px 18px">'
        . '<div style="max-width:520px;margin:0 auto">'
        . '<div style="padding:14px 18px;text-align:center;background:#dff2fb;color:#24434f;font-size:13px">Check it out! This is what your clients will see.</div>'
        . '<h2 style="margin:24px 0 20px;color:#49515a;font-size:20px">' . $business . '</h2><hr style="border:0;border-top:1px solid #e2e5e7">'
        . '<p style="margin-top:28px">Hello ' . $name . ',</p>'
        . '<p>We\'re inviting you to log in to our client hub.</p>'
        . '<p style="line-height:1.6">Client hub is a self-serve online experience where you can access your account details and view available quotes, invoices, and service information.</p>'
        . '<p style="margin:24px 0"><a href="' . $url . '" style="display:inline-block;padding:12px 18px;border-radius:4px;background:#2f8d25;color:#fff;text-decoration:none;font-weight:700">Log in to Client Hub</a></p>'
        . '<div style="margin-top:24px;padding:14px;background:#f6f7f8;border-radius:6px;font-size:13px;line-height:1.65"><strong>Login email:</strong> ' . $safeEmail . '<br><strong>Temporary password:</strong> ' . $safePassword . '<br><span style="color:#6e7d86">For security, change your password after signing in.</span></div>'
        . '<div style="margin-top:32px;padding:18px;background:#f5f5f5;color:#77838a;font-size:12px">' . $business . '</div>'
        . '</div></div></body></html>';
}

function cvNormalizeUploads($field)
{
    $out = array();
    if (!isset($_FILES[$field])) {
        return $out;
    }
    $f = $_FILES[$field];
    if (!is_array($f['name'])) {
        $f = array(
            'name' => array($f['name']),
            'type' => array($f['type']),
            'tmp_name' => array($f['tmp_name']),
            'error' => array($f['error']),
            'size' => array($f['size'])
        );
    }
    foreach ($f['name'] as $i => $name) {
        $error = isset($f['error'][$i]) ? (int)$f['error'][$i] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One attachment could not be uploaded.');
        }
        $out[] = array(
            'name' => basename((string)$name),
            'type' => isset($f['type'][$i]) ? (string)$f['type'][$i] : '',
            'tmp_name' => (string)$f['tmp_name'][$i],
            'size' => isset($f['size'][$i]) ? (int)$f['size'][$i] : 0
        );
    }
    return $out;
}

function cvStoreUploads(PDO $pdo, $tenantId, $clientId, $userId, $field, $folderKind, $relatedType, $relatedId)
{
    $files = cvNormalizeUploads($field);
    if (!$files) {
        return array();
    }
    if (count($files) > 10) {
        throw new RuntimeException('You can attach up to 10 files at one time.');
    }
    $total = 0;
    foreach ($files as $file) {
        $total += $file['size'];
    }
    if ($total > 10 * 1024 * 1024) {
        throw new RuntimeException('Attachments cannot exceed 10 MB in total.');
    }

    $privateKinds = array('client-emails', 'client-notes');
    $storageRoot = in_array($folderKind, $privateKinds, true) ? 'private_uploads' : 'uploads';
    $relativeDir = $storageRoot . '/' . $folderKind . '/' . $tenantId . '/' . $clientId;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to create the attachment folder.');
    }

    $saved = array();
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, array('php','phtml','phar','cgi','pl','py','sh','exe','bat','cmd','com','js','html','htm'), true)) {
            throw new RuntimeException('This attachment type is not allowed: ' . $file['name']);
        }
        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME));
        $safeBase = trim((string)$safeBase, '-_.');
        if ($safeBase === '') {
            $safeBase = 'file';
        }
        $stored = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $safeBase . ($ext !== '' ? '.' . $ext : '');
        $absolute = $absoluteDir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $absolute)) {
            throw new RuntimeException('Unable to save attachment: ' . $file['name']);
        }
        $relative = $relativeDir . '/' . $stored;
        $mime = $file['type'];
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($absolute);
            if ($detected) {
                $mime = $detected;
            }
        }
        $item = array('name' => $file['name'], 'relative_path' => $relative, 'absolute_path' => $absolute, 'mime' => $mime, 'size' => $file['size']);
        $saved[] = $item;
        if (cvt($pdo, 'attachments')) {
            $q = $pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type,description,created_at)
                                VALUES(:t,:rt,:rid,:u,:n,:p,:m,:s,'file',:d,NOW())");
            $q->execute(array(
                ':t' => $tenantId,
                ':rt' => $relatedType,
                ':rid' => $relatedId,
                ':u' => $userId > 0 ? $userId : null,
                ':n' => $file['name'],
                ':p' => $relative,
                ':m' => $mime !== '' ? $mime : null,
                ':s' => $file['size'],
                ':d' => $relatedType === 'client_email' ? 'Client email attachment' : 'Client internal note attachment'
            ));
            $saved[count($saved) - 1]['attachment_id'] = (int)$pdo->lastInsertId();
        }
    }
    return $saved;
}

function cvCleanupUploads(PDO $pdo, $tenantId, $saved)
{
    foreach ((array)$saved as $item) {
        if (!empty($item['attachment_id']) && cvt($pdo, 'attachments')) {
            try {
                $q = $pdo->prepare("DELETE FROM attachments WHERE id=:id AND tenant_id=:t");
                $q->execute(array(':id' => (int)$item['attachment_id'], ':t' => $tenantId));
            } catch (Throwable $e) {
                error_log('client attachment cleanup db: ' . $e->getMessage());
            }
        }
        if (!empty($item['absolute_path']) && is_file($item['absolute_path'])) {
            @unlink($item['absolute_path']);
        }
    }
}

function cvLogEmail(PDO $pdo, $tenantId, $clientId, $userId, $userName, $subject, $message, $status)
{
    if (!cvt($pdo, 'message_threads') || !cvt($pdo, 'message_thread_messages')) return 0;
    $safeUserId = null;
    if ($userId > 0 && cvt($pdo, 'users')) {
        $uq=$pdo->prepare("SELECT id FROM users WHERE id=:u AND tenant_id=:t LIMIT 1");
        $uq->execute(array(':u'=>$userId,':t'=>$tenantId));
        if ($uq->fetchColumn()) $safeUserId=$userId;
    }
    $startedHere = !$pdo->inTransaction();
    if ($startedHere) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT id FROM message_threads WHERE tenant_id=:t AND client_id=:c AND channel='email' AND status<>'archived' ORDER BY COALESCE(last_message_at,created_at) DESC,id DESC LIMIT 1 FOR UPDATE");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $threadId=(int)$q->fetchColumn();
        if ($threadId<=0) {
            $q=$pdo->prepare("INSERT INTO message_threads(tenant_id,client_id,related_type,related_id,assigned_user_id,channel,status,last_message_at,created_at,updated_at) VALUES(:t,:c,'client',:c,:u,'email','open',NOW(),NOW(),NOW())");
            $q->execute(array(':t'=>$tenantId,':c'=>$clientId,':u'=>$safeUserId));
            $threadId=(int)$pdo->lastInsertId();
        }
        $body='Subject: '.$subject."\n\n".$message;
        $q=$pdo->prepare("INSERT INTO message_thread_messages(tenant_id,thread_id,direction,sender_user_id,sender_label,body,status,created_at) VALUES(:t,:th,'outbound',:u,:label,:body,:status,NOW())");
        $q->execute(array(':t'=>$tenantId,':th'=>$threadId,':u'=>$safeUserId,':label'=>$userName!==''?$userName:'FieldPlx User',':body'=>$body,':status'=>$status));
        $messageId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE message_threads SET status='open',last_message_at=NOW(),updated_at=NOW() WHERE id=:id AND tenant_id=:t")->execute(array(':id'=>$threadId,':t'=>$tenantId));
        if ($startedHere) $pdo->commit();
        return $messageId;
    } catch (Throwable $e) {
        if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function cvBaseUrl()
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    if ($host === '') {
        return '';
    }
    return $scheme . '://' . $host;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cvr(405, false, 'Method not allowed.');
}

$tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = !empty($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (!empty($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$branchId = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
if ($tenantId <= 0 || $userId <= 0) {
    cvr(401, false, 'Authentication required.');
}

$sessionToken = isset($_SESSION['clients_csrf_token']) ? (string)$_SESSION['clients_csrf_token'] : '';
$postedToken = cvp('csrf_token');
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    cvr(419, false, 'Your session expired. Refresh the page and try again.');
}

$action = cvp('action');
$clientId = (int)cvp('client_id', '0');
if ($clientId <= 0) {
    cvr(422, false, 'Invalid client.');
}
$client = cvClient($pdo, $tenantId, $clientId);
$ctx = cvUserContext($pdo, $tenantId, $userId);
cvRequirePermission($pdo, $tenantId, $userId, 'clients.view', $ctx);
if (empty($ctx['is_tenant_admin']) && empty($ctx['is_role_admin']) && !empty($ctx['branch_id']) && !empty($client['branch_id']) && (int)$ctx['branch_id'] !== (int)$client['branch_id']) {
    cvr(403, false, 'This client is outside your branch access.');
}

try {
    if ($action === 'save_notes') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        $notes = cvp('notes');
        if (mb_strlen($notes, 'UTF-8') > 5000) {
            cvr(422, false, 'Notes cannot exceed 5000 characters.');
        }
        $allowedLinks = array('request' => 'service_requests', 'quote' => 'quotes', 'job' => 'jobs', 'invoice' => 'invoices');
        $linkTypesRaw = json_decode(cvp('link_related_types', '[]'), true);
        if (!is_array($linkTypesRaw)) $linkTypesRaw = array();
        $linkTypes = array();
        foreach ($linkTypesRaw as $linkTypeValue) {
            $linkTypeValue = trim((string)$linkTypeValue);
            if (isset($allowedLinks[$linkTypeValue]) && !in_array($linkTypeValue, $linkTypes, true)) $linkTypes[] = $linkTypeValue;
        }
        /* Backward compatibility for older clients that posted one concrete related record. */
        $linkType = cvp('link_related_type');
        $linkId = (int)cvp('link_related_id', '0');
        if ($linkType !== '' && $linkId > 0) {
            if (!isset($allowedLinks[$linkType]) || !cvt($pdo, $allowedLinks[$linkType])) cvr(422, false, 'Select a valid related record.');
            $linkTable = $allowedLinks[$linkType];
            $q = $pdo->prepare("SELECT id FROM `$linkTable` WHERE id=:id AND tenant_id=:t AND client_id=:c LIMIT 1");
            $q->execute(array(':id' => $linkId, ':t' => $tenantId, ':c' => $clientId));
            if (!$q->fetchColumn()) cvr(422, false, 'The selected related record does not belong to this client.');
            if (!in_array($linkType, $linkTypes, true)) $linkTypes[] = $linkType;
        }
        $old = array('notes' => isset($client['notes']) ? $client['notes'] : null);
        $q = $pdo->prepare("UPDATE clients SET notes=:n,updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
        $q->execute(array(':n' => $notes !== '' ? $notes : null, ':id' => $clientId, ':t' => $tenantId));
        $savedAttachments = cvStoreUploads($pdo, $tenantId, $clientId, $userId, 'attachments', 'client-notes', 'client_note', $clientId);
        $mentionedUsers = cvNotifyMentions($pdo, $tenantId, $clientId, $userId, isset($ctx['name']) ? (string)$ctx['name'] : '', $notes);
        $noteDetails = array('notes' => $notes, 'attachments' => count($savedAttachments), 'linked_types' => $linkTypes, 'linked_type' => $linkType !== '' ? $linkType : null, 'linked_id' => $linkId > 0 ? $linkId : null, 'mentioned_user_ids' => $mentionedUsers);
        cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_NOTES_UPDATED', $clientId, $old, $noteDetails);
        cvActivity($pdo, $tenantId, $branchId, $userId, 'client_note_updated', $clientId, 'Client note updated', $noteDetails);
        cvr(200, true, 'Client note saved successfully.', array('attachments_saved' => count($savedAttachments), 'mentions_notified' => count($mentionedUsers)));
    }

    if ($action === 'save_tags') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        if (!cvt($pdo, 'client_tags') || !cvt($pdo, 'client_tag_assignments')) {
            cvr(500, false, 'Client tag tables are not installed.');
        }
        $ids = json_decode(cvp('tag_ids', '[]'), true);
        if (!is_array($ids)) {
            cvr(422, false, 'Invalid tag selection.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; })));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare("SELECT id FROM client_tags WHERE tenant_id=? AND is_active=1 AND id IN ($ph)");
            $q->execute(array_merge(array($tenantId), $ids));
            $valid = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            sort($valid);
            $check = $ids;
            sort($check);
            if ($valid !== $check) {
                cvr(422, false, 'One or more selected tags are invalid.');
            }
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM client_tag_assignments WHERE tenant_id=:t AND client_id=:c")->execute(array(':t' => $tenantId, ':c' => $clientId));
            if ($ids) {
                $ins = $pdo->prepare("INSERT INTO client_tag_assignments(tenant_id,client_id,tag_id,created_by) VALUES(:t,:c,:tag,:u)");
                foreach ($ids as $tagId) {
                    $ins->execute(array(':t' => $tenantId, ':c' => $clientId, ':tag' => $tagId, ':u' => $userId));
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_TAGS_UPDATED', $clientId, null, array('tag_ids' => $ids));
        cvr(200, true, 'Client tags updated successfully.');
    }

    if ($action === 'create_tag') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        if (!cvt($pdo, 'client_tags') || !cvt($pdo, 'client_tag_assignments')) {
            cvr(500, false, 'Client tag tables are not installed.');
        }
        $name = cvp('name');
        if ($name === '') cvr(422, false, 'Enter a tag name.');
        if (mb_strlen($name, 'UTF-8') > 80) cvr(422, false, 'Tag name must be 80 characters or less.');
        $q = $pdo->prepare("SELECT id,name,COALESCE(color,'') color FROM client_tags WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':n' => $name));
        $tag = $q->fetch(PDO::FETCH_ASSOC);
        if ($tag) {
            $pdo->prepare("UPDATE client_tags SET is_active=1,updated_at=NOW() WHERE id=:id AND tenant_id=:t")->execute(array(':id' => (int)$tag['id'], ':t' => $tenantId));
        } else {
            $q = $pdo->prepare("INSERT INTO client_tags(tenant_id,name,color,is_active,created_by,created_at,updated_at) VALUES(:t,:n,NULL,1,:u,NOW(),NOW())");
            $q->execute(array(':t' => $tenantId, ':n' => $name, ':u' => $userId));
            $tag = array('id' => (int)$pdo->lastInsertId(), 'name' => $name, 'color' => '');
        }
        $pdo->prepare("INSERT IGNORE INTO client_tag_assignments(tenant_id,client_id,tag_id,created_by) VALUES(:t,:c,:tag,:u)")
            ->execute(array(':t' => $tenantId, ':c' => $clientId, ':tag' => (int)$tag['id'], ':u' => $userId));
        cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_TAG_CREATED_AND_ASSIGNED', $clientId, null, $tag);
        cvr(200, true, 'Tag created and added to client.', array('tag' => $tag));
    }

    if ($action === 'save_communication_settings') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        if (!cvt($pdo, 'client_communication_preferences')) {
            cvr(500, false, 'Client communication preferences table is not installed.');
        }
        $prefs = array(
            'quote_followups' => cvp('quote_followups', '0') === '1' ? 1 : 0,
            'invoice_followups' => cvp('invoice_followups', '0') === '1' ? 1 : 0,
            'visit_reminders' => cvp('visit_reminders', '0') === '1' ? 1 : 0,
            'job_close_followups' => cvp('job_close_followups', '0') === '1' ? 1 : 0
        );
        $oldQ = $pdo->prepare("SELECT quote_followups,invoice_followups,visit_reminders,job_close_followups FROM client_communication_preferences WHERE tenant_id=:t AND client_id=:c LIMIT 1");
        $oldQ->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        $oldPrefs = $oldQ->fetch(PDO::FETCH_ASSOC);
        if ($oldPrefs) {
            $q = $pdo->prepare("UPDATE client_communication_preferences SET quote_followups=:q,invoice_followups=:i,visit_reminders=:v,job_close_followups=:j,updated_at=NOW() WHERE tenant_id=:t AND client_id=:c");
        } else {
            $q = $pdo->prepare("INSERT INTO client_communication_preferences(tenant_id,client_id,quote_followups,invoice_followups,visit_reminders,job_close_followups,created_at,updated_at) VALUES(:t,:c,:q,:i,:v,:j,NOW(),NOW())");
        }
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId, ':q'=>$prefs['quote_followups'], ':i'=>$prefs['invoice_followups'], ':v'=>$prefs['visit_reminders'], ':j'=>$prefs['job_close_followups']));
        cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_COMMUNICATION_SETTINGS_UPDATED', $clientId, $oldPrefs ?: null, $prefs);
        cvActivity($pdo, $tenantId, $branchId, $userId, 'client_communication_settings_updated', $clientId, 'Client communication settings updated', $prefs);
        cvr(200, true, 'Communication settings saved successfully.');
    }

    if ($action === 'send_email') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        $to = cvp('to');
        if ($to === '') $to = trim((string)(isset($client['email']) ? $client['email'] : ''));
        $subject = cvp('subject');
        $message = cvp('message');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) cvr(422, false, 'Enter a valid recipient email address.');
        if ($subject === '') cvr(422, false, 'Enter an email subject.');
        if ($message === '') cvr(422, false, 'Enter an email message.');
        if (mb_strlen($subject, 'UTF-8') > 255) cvr(422, false, 'Email subject is too long.');
        if (mb_strlen($message, 'UTF-8') > 30000) cvr(422, false, 'Email message is too long.');

        $tempRelatedId = $clientId;
        $savedAttachments = cvStoreUploads($pdo, $tenantId, $clientId, $userId, 'attachments', 'client-emails', 'client_email', $tempRelatedId);
        $copyEmail = cvp('send_copy', '0') === '1' ? (string)$ctx['email'] : '';
        $html = cvHtmlEmail($subject, !empty($client['display_name']) ? $client['display_name'] : 'Client', $message);
        try {
            $mailBranchId = !empty($client['branch_id']) ? (int)$client['branch_id'] : $branchId;
            $smtpId = cvSendSmtp($pdo, $tenantId, $mailBranchId, $to, $subject, $html, $savedAttachments, $copyEmail);
        } catch (Throwable $e) {
            cvCleanupUploads($pdo, $tenantId, $savedAttachments);
            try {
                cvLogEmail($pdo, $tenantId, $clientId, $userId, $ctx['name'], $subject, $message, 'failed');
            } catch (Throwable $logError) {
                error_log('client email failed-log error: ' . $logError->getMessage());
            }
            $failedMailAudit = array('result'=>'failed','recipient'=>$to,'subject'=>$subject,'message'=>$message,'attachments'=>count($savedAttachments),'error'=>$e->getMessage());
            cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_EMAIL_SEND_FAILED', $clientId, null, $failedMailAudit);
            cvActivity($pdo, $tenantId, $branchId, $userId, 'client_email_failed', $clientId, 'Email failed to send', $failedMailAudit);
            throw $e;
        }

        $messageId = 0;
        try {
            $messageId = cvLogEmail($pdo, $tenantId, $clientId, $userId, $ctx['name'], $subject, $message, 'sent');
            if ($messageId > 0 && cvt($pdo, 'attachments')) {
                $moveAttachment = $pdo->prepare("UPDATE attachments SET related_id=:m WHERE id=:id AND tenant_id=:t AND related_type='client_email'");
                foreach ($savedAttachments as $savedAttachment) {
                    if (!empty($savedAttachment['attachment_id'])) {
                        $moveAttachment->execute(array(':m' => $messageId, ':id' => (int)$savedAttachment['attachment_id'], ':t' => $tenantId));
                    }
                }
            }
        } catch (Throwable $logError) {
            /* SMTP already succeeded; communication logging must not turn a sent email into a visible failure. */
            error_log('client email communication log error: ' . $logError->getMessage());
        }
        $sentAt=date('Y-m-d H:i:s');
        $sentDetails=array('result'=>'sent','recipient'=>$to,'subject'=>$subject,'message'=>$message,'message_id'=>$messageId,'smtp_config_id'=>$smtpId,'attachments'=>count($savedAttachments),'copy_to'=>$copyEmail!==''?$copyEmail:null);
        cvActivity($pdo, $tenantId, $branchId, $userId, 'client_email_sent', $clientId, 'Email sent to client', $sentDetails);
        cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_EMAIL_SENT', $clientId, null, $sentDetails);
        $communication=array('id'=>$messageId>0?'m'.$messageId:'sent'.time(),'message_id'=>$messageId,'channel'=>'email','direction'=>'outbound','status'=>'sent','created_at'=>$sentAt,'to_email'=>$to,'subject'=>$subject,'body'=>$message,'type'=>'Email','source'=>$messageId>0?'message':'smtp');
        cvr(200, true, 'Email sent successfully to ' . $to . '.', array('smtp_config_id'=>$smtpId,'message_id'=>$messageId,'communication'=>$communication));
    }

    if ($action === 'send_login_email') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        if (!cvt($pdo, 'client_portal_users')) {
            cvr(500, false, 'Client portal users table is not installed.');
        }
        $email = trim((string)(isset($client['email']) ? $client['email'] : ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            cvr(422, false, 'The client needs a valid email address before a portal login email can be sent.');
        }
        $q = $pdo->prepare("SELECT id FROM client_portal_users WHERE tenant_id=:t AND email=:e AND client_id<>:c LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':e' => $email, ':c' => $clientId));
        if ($q->fetchColumn()) {
            cvr(409, false, 'This email is already used by another client portal login.');
        }
        $q = $pdo->prepare("SELECT * FROM client_portal_users WHERE tenant_id=:t AND client_id=:c AND contact_id IS NULL ORDER BY id LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':c' => $clientId));
        $portal = $q->fetch(PDO::FETCH_ASSOC);
        $temporaryPassword = 'Fp!' . strtoupper(bin2hex(random_bytes(4)));
        $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        $phone = trim((string)(isset($client['phone']) ? $client['phone'] : ''));

        $loginUrl = getenv('FIELDPLX_CLIENT_PORTAL_LOGIN_URL');
        if (!$loginUrl && defined('FIELDPLX_CLIENT_PORTAL_LOGIN_URL')) {
            $loginUrl = FIELDPLX_CLIENT_PORTAL_LOGIN_URL;
        }
        if (!$loginUrl) {
            $loginUrl = rtrim(cvBaseUrl(), '/') . '/client-portal-login.php';
        }
        $clientGreeting = !empty($client['display_name']) ? (string)$client['display_name'] : 'Client';
        $businessName = cvBusinessName($pdo, $tenantId);
        $message = "Hello " . $clientGreeting . ",\n\nWe're inviting you to log in to our client hub.\n\nLogin URL: " . $loginUrl . "\nEmail: " . $email . "\nTemporary password: " . $temporaryPassword . "\n\nFor security, change your password after signing in.";
        $subject = "You've been invited to log in to our client hub";
        $html = cvLoginInviteHtml($businessName, $clientGreeting, $loginUrl, $email, $temporaryPassword);

        /* Keep the credential change transactional: an SMTP failure rolls the new password back. */
        $pdo->beginTransaction();
        try {
            if ($portal) {
                $q = $pdo->prepare("UPDATE client_portal_users SET email=:e,phone=:p,password_hash=:h,status='active',updated_at=NOW() WHERE id=:id AND tenant_id=:t");
                $q->execute(array(':e' => $email, ':p' => $phone !== '' ? $phone : null, ':h' => $hash, ':id' => (int)$portal['id'], ':t' => $tenantId));
                $portalId = (int)$portal['id'];
            } else {
                $q = $pdo->prepare("INSERT INTO client_portal_users(tenant_id,client_id,contact_id,email,phone,password_hash,status,created_at) VALUES(:t,:c,NULL,:e,:p,:h,'active',NOW())");
                $q->execute(array(':t' => $tenantId, ':c' => $clientId, ':e' => $email, ':p' => $phone !== '' ? $phone : null, ':h' => $hash));
                $portalId = (int)$pdo->lastInsertId();
            }
            $mailBranchId = !empty($client['branch_id']) ? (int)$client['branch_id'] : $branchId;
            $smtpId = cvSendSmtp($pdo, $tenantId, $mailBranchId, $email, $subject, $html, array(), '');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $failedLoginAudit = array('result'=>'failed','recipient'=>$email,'subject'=>$subject,'error'=>$e->getMessage());
            cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_PORTAL_LOGIN_EMAIL_SEND_FAILED', $clientId, null, $failedLoginAudit);
            cvActivity($pdo, $tenantId, $branchId, $userId, 'client_portal_login_email_failed', $clientId, 'Client portal login email failed', $failedLoginAudit);
            throw $e;
        }
        $loginMessageId=0;
        try {$loginMessageId=cvLogEmail($pdo,$tenantId,$clientId,$userId,$ctx['name'],$subject,$message,'sent');}catch(Throwable $logError){error_log('client portal login email communication log retry error: '.$logError->getMessage());}
        $loginSentAt=date('Y-m-d H:i:s');
        $loginDetails=array('result'=>'sent','portal_user_id'=>$portalId,'recipient'=>$email,'subject'=>$subject,'message'=>$message,'message_id'=>$loginMessageId,'smtp_config_id'=>$smtpId);
        cvActivity($pdo, $tenantId, $branchId, $userId, 'client_portal_login_email_sent', $clientId, 'Client portal login email sent', $loginDetails);
        cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_PORTAL_LOGIN_EMAIL_SENT', $clientId, null, $loginDetails);
        cvr(200, true, 'Login email sent successfully to ' . $email . '.', array('communication'=>array('id'=>$loginMessageId>0?'m'.$loginMessageId:'login'.time(),'message_id'=>$loginMessageId,'channel'=>'email','direction'=>'outbound','status'=>'sent','created_at'=>$loginSentAt,'to_email'=>$email,'subject'=>$subject,'body'=>$message,'type'=>'Client Hub login email','source'=>$loginMessageId>0?'message':'smtp')));
    }

    if ($action === 'login_as_client') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        if (!cvt($pdo, 'client_portal_users')) {
            cvr(500, false, 'Client portal is not installed.');
        }
        $q = $pdo->prepare("SELECT id,status FROM client_portal_users WHERE tenant_id=:t AND client_id=:c AND contact_id IS NULL ORDER BY id LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':c' => $clientId));
        $portal = $q->fetch(PDO::FETCH_ASSOC);
        if (!$portal || !in_array((string)$portal['status'], array('active','invited'), true)) {
            cvr(422, false, 'This client does not have an active portal account. Use Send Login Email first.');
        }
        $_SESSION['portal_user_id'] = (int)$portal['id'];
        $_SESSION['portal_client_id'] = $clientId;
        $_SESSION['portal_tenant_id'] = $tenantId;
        $_SESSION['client_portal_user_id'] = (int)$portal['id'];
        $_SESSION['client_portal_client_id'] = $clientId;
        $_SESSION['client_portal_impersonated_by_user_id'] = $userId;
        $_SESSION['client_portal_impersonation'] = 1;

        $redirect = getenv('FIELDPLX_CLIENT_PORTAL_HOME_URL');
        if (!$redirect && defined('FIELDPLX_CLIENT_PORTAL_HOME_URL')) {
            $redirect = FIELDPLX_CLIENT_PORTAL_HOME_URL;
        }
        if (!$redirect) {
            $candidates = array(
                array(__DIR__ . '/../client-portal.php', 'client-portal.php'),
                array(__DIR__ . '/../client-hub.php', 'client-hub.php'),
                array(__DIR__ . '/../../client-portal/index.php', '../client-portal/index.php')
            );
            foreach ($candidates as $candidate) {
                if (is_file($candidate[0])) {
                    $redirect = $candidate[1];
                    break;
                }
            }
        }
        if (!$redirect) {
            $redirect = 'client-portal.php';
        }
        cvActivity($pdo, $tenantId, $branchId, $userId, 'client_portal_impersonation_started', $clientId, 'Logged in as client', array('portal_user_id' => (int)$portal['id']));
        cvr(200, true, 'Opening client portal.', array('redirect' => $redirect));
    }

    if ($action === 'archive_client') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        $q = $pdo->prepare("UPDATE clients SET status='archived',client_type='archived',updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
        $q->execute(array(':id' => $clientId, ':t' => $tenantId));
        cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_ARCHIVED', $clientId, $client, array('status' => 'archived', 'client_type' => 'archived'));
        cvActivity($pdo, $tenantId, $branchId, $userId, 'client_archived', $clientId, 'Client archived', array());
        cvr(200, true, 'Client archived successfully.', array('redirect' => 'clients.php'));
    }

    if ($action === 'delete_client') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.delete', $ctx);
        $q = $pdo->prepare("UPDATE clients SET status='archived',client_type='archived',deleted_at=NOW(),updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
        $q->execute(array(':id' => $clientId, ':t' => $tenantId));
        cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_DELETED', $clientId, $client, array('deleted_at' => date('Y-m-d H:i:s')));
        cvActivity($pdo, $tenantId, $branchId, $userId, 'client_deleted', $clientId, 'Client deleted', array());
        cvr(200, true, 'Client deleted successfully.', array('redirect' => 'clients.php'));
    }

    if ($action === 'set_schedule_completion') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        $scheduleType = cvp('schedule_type');
        $scheduleId = (int)cvp('schedule_id', '0');
        $completed = cvp('completed', '1') === '1';
        if ($scheduleId <= 0 || !in_array($scheduleType, array('visit','task'), true)) {
            cvr(422, false, 'Invalid schedule item.');
        }

        if ($scheduleType === 'visit') {
            if (!cvt($pdo, 'visits') || !cvt($pdo, 'jobs')) cvr(500, false, 'Visit tables are unavailable.');
            $q = $pdo->prepare("SELECT v.id,v.status,v.completed_at,v.work_ended_at,v.branch_id FROM visits v INNER JOIN jobs j ON j.id=v.job_id AND j.tenant_id=v.tenant_id WHERE v.id=:id AND v.tenant_id=:t AND j.client_id=:c LIMIT 1");
            $q->execute(array(':id'=>$scheduleId, ':t'=>$tenantId, ':c'=>$clientId));
            $item = $q->fetch(PDO::FETCH_ASSOC);
            if (!$item) cvr(404, false, 'Visit not found for this client.');
            if ((string)$item['status'] === 'cancelled') cvr(422, false, 'A cancelled visit cannot be marked complete.');
            $old = array('status'=>$item['status'],'completed_at'=>$item['completed_at'],'work_ended_at'=>$item['work_ended_at']);
            if ($completed) {
                $q = $pdo->prepare("UPDATE visits SET status='completed',completed_at=COALESCE(completed_at,NOW()),work_ended_at=COALESCE(work_ended_at,NOW()),updated_at=NOW() WHERE id=:id AND tenant_id=:t");
                $q->execute(array(':id'=>$scheduleId, ':t'=>$tenantId));
                if (cvt($pdo, 'visit_assignments')) {
                    $pdo->prepare("UPDATE visit_assignments SET status='completed',updated_at=NOW() WHERE tenant_id=:t AND visit_id=:v AND status NOT IN('removed','declined')")
                        ->execute(array(':t'=>$tenantId, ':v'=>$scheduleId));
                }
                $new = array('status'=>'completed','completed_at'=>date('Y-m-d H:i:s'));
                $message = 'Visit marked as complete.';
                $auditAction = 'CLIENT_VISIT_MARKED_COMPLETE';
                $eventType = 'client_visit_completed';
            } else {
                $q = $pdo->prepare("UPDATE visits SET status='scheduled',completed_at=NULL,work_ended_at=NULL,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
                $q->execute(array(':id'=>$scheduleId, ':t'=>$tenantId));
                if (cvt($pdo, 'visit_assignments')) {
                    $pdo->prepare("UPDATE visit_assignments SET status='assigned',updated_at=NOW() WHERE tenant_id=:t AND visit_id=:v AND status='completed'")
                        ->execute(array(':t'=>$tenantId, ':v'=>$scheduleId));
                }
                $new = array('status'=>'scheduled','completed_at'=>null,'work_ended_at'=>null);
                $message = 'Visit marked as incomplete.';
                $auditAction = 'CLIENT_VISIT_MARKED_INCOMPLETE';
                $eventType = 'client_visit_reopened';
            }
            cvAudit($pdo, $tenantId, !empty($item['branch_id'])?(int)$item['branch_id']:$branchId, $userId, $auditAction, $clientId, $old, array_merge($new,array('visit_id'=>$scheduleId)));
            cvActivity($pdo, $tenantId, !empty($item['branch_id'])?(int)$item['branch_id']:$branchId, $userId, $eventType, $clientId, $message, array('visit_id'=>$scheduleId,'old_status'=>$old['status'],'new_status'=>$new['status']));
            cvr(200, true, $message, array('schedule_type'=>'visit','schedule_id'=>$scheduleId,'status'=>$new['status']));
        }

        if (!cvt($pdo, 'tasks')) cvr(500, false, 'Tasks table is unavailable.');
        $q = $pdo->prepare("SELECT id,status,completed_at,branch_id FROM tasks WHERE id=:id AND tenant_id=:t AND client_id=:c LIMIT 1");
        $q->execute(array(':id'=>$scheduleId, ':t'=>$tenantId, ':c'=>$clientId));
        $item = $q->fetch(PDO::FETCH_ASSOC);
        if (!$item) cvr(404, false, 'Task not found for this client.');
        if ((string)$item['status'] === 'cancelled') cvr(422, false, 'A cancelled task cannot be marked complete.');
        $old = array('status'=>$item['status'],'completed_at'=>$item['completed_at']);
        $newStatus = $completed ? 'completed' : 'open';
        $q = $pdo->prepare("UPDATE tasks SET status=:s,completed_at=" . ($completed ? "COALESCE(completed_at,NOW())" : "NULL") . ",updated_at=NOW() WHERE id=:id AND tenant_id=:t AND client_id=:c");
        $q->execute(array(':s'=>$newStatus, ':id'=>$scheduleId, ':t'=>$tenantId, ':c'=>$clientId));
        $message = $completed ? 'Task marked as complete.' : 'Task marked as incomplete.';
        $auditAction = $completed ? 'CLIENT_TASK_MARKED_COMPLETE' : 'CLIENT_TASK_MARKED_INCOMPLETE';
        cvAudit($pdo, $tenantId, !empty($item['branch_id'])?(int)$item['branch_id']:$branchId, $userId, $auditAction, $clientId, $old, array('task_id'=>$scheduleId,'status'=>$newStatus));
        cvActivity($pdo, $tenantId, !empty($item['branch_id'])?(int)$item['branch_id']:$branchId, $userId, $completed?'client_task_completed':'client_task_reopened', $clientId, $message, array('task_id'=>$scheduleId,'old_status'=>$old['status'],'new_status'=>$newStatus));
        cvr(200, true, $message, array('schedule_type'=>'task','schedule_id'=>$scheduleId,'status'=>$newStatus));
    }

    if ($action === 'create_task') {
        cvRequirePermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
        if (!cvt($pdo, 'tasks')) {
            cvr(500, false, 'Tasks table is not installed.');
        }
        $title = cvp('title');
        $description = cvp('description');
        $propertyId = (int)cvp('property_id', '0');
        $scheduleLater = cvp('schedule_later', '0') === '1' ? 1 : 0;
        $anytime = cvp('anytime', '0') === '1' ? 1 : 0;
        $startDate = cvp('start_date');
        $endDate = cvp('end_date');
        $startTime = cvp('start_time');
        $endTime = cvp('end_time');
        $assigneeType = cvp('assignee_type');
        $assigneeId = (int)cvp('assignee_id', '0');
        $repeat = cvp('repeat', 'never');
        if ($title === '') cvr(422, false, 'Enter a task title.');
        if (mb_strlen($title, 'UTF-8') > 190) cvr(422, false, 'Task title is too long.');
        if ($propertyId > 0) {
            if (!cvt($pdo, 'client_locations')) cvr(422, false, 'Property is unavailable.');
            $q = $pdo->prepare("SELECT id FROM client_locations WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL LIMIT 1");
            $q->execute(array(':id' => $propertyId, ':t' => $tenantId, ':c' => $clientId));
            if (!$q->fetchColumn()) cvr(422, false, 'Select a valid client property.');
        }
        $scheduledStart = null;
        $scheduledEnd = null;
        if (!$scheduleLater) {
            if ($startDate === '') cvr(422, false, 'Select a task start date or choose Schedule later.');
            $scheduledStart = $startDate . ' ' . ($anytime || $startTime === '' ? '00:00:00' : $startTime . ':00');
            $effectiveEndDate = $endDate !== '' ? $endDate : $startDate;
            $scheduledEnd = $effectiveEndDate . ' ' . ($anytime || $endTime === '' ? '23:59:59' : $endTime . ':00');
            if (strtotime($scheduledEnd) < strtotime($scheduledStart)) cvr(422, false, 'Task end time cannot be before the start time.');
        }
        $assignedUser = null;
        $assignedTeam = null;
        if ($assigneeId > 0 && $assigneeType === 'user') {
            $branchSql = !empty($client['branch_id']) && cvc($pdo, 'users', 'branch_id') ? ' AND (branch_id IS NULL OR branch_id=:b)' : '';
            $q = $pdo->prepare("SELECT id FROM users WHERE id=:id AND tenant_id=:t AND status='active'" . (cvc($pdo, 'users', 'deleted_at') ? ' AND deleted_at IS NULL' : '') . $branchSql . " LIMIT 1");
            $params = array(':id' => $assigneeId, ':t' => $tenantId); if ($branchSql !== '') $params[':b'] = (int)$client['branch_id'];
            $q->execute($params);
            if (!$q->fetchColumn()) cvr(422, false, 'Select a valid team member for this client branch.');
            $assignedUser = $assigneeId;
        } elseif ($assigneeId > 0 && $assigneeType === 'team') {
            $branchSql = !empty($client['branch_id']) && cvc($pdo, 'teams', 'branch_id') ? ' AND (branch_id IS NULL OR branch_id=:b)' : '';
            $q = $pdo->prepare("SELECT id FROM teams WHERE id=:id AND tenant_id=:t AND status='active'" . $branchSql . " LIMIT 1");
            $params = array(':id' => $assigneeId, ':t' => $tenantId); if ($branchSql !== '') $params[':b'] = (int)$client['branch_id'];
            $q->execute($params);
            if (!$q->fetchColumn()) cvr(422, false, 'Select a valid team for this client branch.');
            $assignedTeam = $assigneeId;
        }
        $allowedRepeats = array('never','daily','weekly','monthly','yearly');
        if (!in_array($repeat, $allowedRepeats, true)) $repeat = 'never';
        $recurrenceJson = $repeat === 'never' ? null : json_encode(array('frequency' => $repeat, 'interval' => 1), JSON_UNESCAPED_SLASHES);
        $taskBranch = !empty($client['branch_id']) ? (int)$client['branch_id'] : ($branchId > 0 ? $branchId : null);
        $dueAt = $scheduledEnd !== null ? $scheduledEnd : $scheduledStart;
        $q = $pdo->prepare("INSERT INTO tasks(tenant_id,branch_id,related_type,related_id,title,description,assigned_user_id,assigned_team_id,priority,status,due_at,created_by,created_at,client_id,property_id,scheduled_start,scheduled_end,anytime,schedule_later,confirmed_by_client,recurrence_json)
                            VALUES(:t,:b,'client',:c,:title,:d,:au,:at,'normal','open',:due,:u,NOW(),:c,:p,:ss,:se,:any,:later,0,:rec)");
        $q->execute(array(':t' => $tenantId, ':b' => $taskBranch, ':c' => $clientId, ':title' => $title, ':d' => $description !== '' ? $description : null, ':au' => $assignedUser, ':at' => $assignedTeam, ':due' => $dueAt, ':u' => $userId, ':p' => $propertyId > 0 ? $propertyId : null, ':ss' => $scheduledStart, ':se' => $scheduledEnd, ':any' => $anytime, ':later' => $scheduleLater, ':rec' => $recurrenceJson));
        $taskId = (int)$pdo->lastInsertId();
        cvActivity($pdo, $tenantId, $branchId, $userId, 'client_task_created', $clientId, 'Task created: ' . $title, array('task_id' => $taskId));
        cvAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_TASK_CREATED', $clientId, null, array('task_id' => $taskId, 'title' => $title));
        cvr(200, true, 'Task created successfully.', array('task_id' => $taskId));
    }

    cvr(400, false, 'Invalid action.');
} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('FieldPlx client view PDO error: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        cvr(409, false, 'This action would create a duplicate record. Review the client data and try again.');
    }
    cvr(500, false, 'Unable to complete the client request. Check the PHP error log for database details.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('FieldPlx client view API: ' . $e->getMessage());
    cvr(500, false, $e->getMessage() !== '' ? $e->getMessage() : 'Unable to complete the request.');
}
