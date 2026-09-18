<?php
/**
 * FieldPlx Manage Team - seat/member API
 * PHP 7.2+ / MariaDB 11.x
 *
 * Handles only the Users tab of Manage Team:
 * - assigned / unassigned lists
 * - assign seat
 * - unassign seat
 * - delete member
 *
 * Crew actions remain in api/team.php.
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
if (file_exists(__DIR__ . '/../includes/platform-smtp.php')) {
    require_once __DIR__ . '/../includes/platform-smtp.php';
}

/* Load SMTP secret from the same locations used by the invitation API. */
$mtSecretCandidates = array(
    __DIR__ . '/../includes/smtp-secret.php',
    dirname(__DIR__, 2) . '/includes/smtp-secret.php',
    dirname(__DIR__, 2) . '/platform/includes/smtp-secret.php',
    dirname(__DIR__, 2) . '/platform.v1/includes/smtp-secret.php',
    dirname(__DIR__, 2) . '/admin/includes/smtp-secret.php'
);
$mtRootSecrets = glob(dirname(__DIR__, 2) . '/*/includes/smtp-secret.php');
if (is_array($mtRootSecrets)) {
    foreach ($mtRootSecrets as $f) $mtSecretCandidates[] = $f;
}
foreach (array_unique($mtSecretCandidates) as $f) {
    if (!is_file($f)) continue;
    require_once $f;
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) break;
}

function mt_out($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mt_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function mt_table(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t'=>$table));
    $cache[$table] = ((int)$q->fetchColumn() > 0);
    return $cache[$table];
}

function mt_csrf()
{
    $posted = mt_post('csrf_token');
    $saved = isset($_SESSION['team_settings_csrf']) ? (string)$_SESSION['team_settings_csrf'] : '';
    if ($saved === '' || $posted === '' || !hash_equals($saved, $posted)) {
        mt_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function mt_actor(PDO $pdo, $tenantId, $userId)
{
    $q = $pdo->prepare("SELECT id,tenant_id,role_id,is_tenant_admin,status FROM users WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id'=>$userId, ':t'=>$tenantId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string)$row['status'] !== 'active') {
        mt_out(401, false, 'Your login session is no longer active.');
    }
    return $row;
}

function mt_permission(PDO $pdo, $tenantId, $userId, $roleId, $isAdmin, $code)
{
    if ((int)$isAdmin === 1) return true;
    if (!mt_table($pdo, 'permissions')) return true;

    $q = $pdo->prepare("SELECT id FROM permissions WHERE permission_code=:c LIMIT 1");
    $q->execute(array(':c'=>$code));
    $permissionId = (int)$q->fetchColumn();
    if ($permissionId <= 0) return true;

    if (mt_table($pdo, 'user_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenantId, ':u'=>$userId, ':p'=>$permissionId));
        $v = $q->fetchColumn();
        if ($v !== false) return ((string)$v === 'allow');
    }

    if ($roleId > 0 && mt_table($pdo, 'role_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenantId, ':r'=>$roleId, ':p'=>$permissionId));
        $v = $q->fetchColumn();
        if ($v !== false) return ((string)$v === 'allow');
    }
    return false;
}

function mt_can_any(PDO $pdo, $tenantId, $actor, $codes)
{
    foreach ($codes as $code) {
        if (mt_permission($pdo, $tenantId, (int)$actor['id'], (int)$actor['role_id'], (int)$actor['is_tenant_admin'], $code)) return true;
    }
    return false;
}

function mt_require_any(PDO $pdo, $tenantId, $actor, $codes)
{
    if (!mt_can_any($pdo, $tenantId, $actor, $codes)) {
        mt_out(403, false, 'You do not have permission to manage team members.');
    }
}

function mt_audit(PDO $pdo, $tenantId, $branchId, $actorId, $action, $objectId, $values)
{
    if (!function_exists('tenantAuditLog')) return;
    try {
        tenantAuditLog(
            $pdo,
            $action,
            $tenantId,
            $branchId > 0 ? $branchId : null,
            $actorId,
            'user',
            $objectId,
            null,
            $values
        );
    } catch (Throwable $e) {
        error_log('Manage Team audit error: ' . $e->getMessage());
    }
}

function mt_owner_id(PDO $pdo, $tenantId)
{
    /* Prefer an explicit Owner role when installed. */
    if (mt_table($pdo, 'roles')) {
        $q = $pdo->prepare("SELECT u.id
                            FROM users u
                            LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id
                            WHERE u.tenant_id=:t AND u.deleted_at IS NULL
                              AND (LOWER(COALESCE(r.code,''))='owner' OR LOWER(COALESCE(r.name,''))='owner')
                            ORDER BY u.id ASC LIMIT 1");
        $q->execute(array(':t'=>$tenantId));
        $id = (int)$q->fetchColumn();
        if ($id > 0) return $id;
    }

    $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:t AND deleted_at IS NULL AND is_tenant_admin=1 ORDER BY id ASC LIMIT 1");
    $q->execute(array(':t'=>$tenantId));
    return (int)$q->fetchColumn();
}

function mt_member(PDO $pdo, $tenantId, $userId)
{
    $q = $pdo->prepare("SELECT u.*,r.name AS role_name,r.code AS role_code
                        FROM users u
                        LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id
                        WHERE u.id=:id AND u.tenant_id=:t AND u.deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id'=>$userId, ':t'=>$tenantId));
    return $q->fetch(PDO::FETCH_ASSOC);
}

function mt_has_seat(PDO $pdo, $tenantId, $member, $ownerId)
{
    if (!$member) return false;
    if ((int)$member['id'] === (int)$ownerId) return true;
    if ((string)$member['status'] === 'active') return true;
    if ((string)$member['status'] !== 'invited') return false;
    if (!mt_table($pdo, 'tenant_activation_tokens')) return false;

    $q = $pdo->prepare("SELECT COUNT(*) FROM tenant_activation_tokens WHERE tenant_id=:t AND user_id=:u AND used_at IS NULL");
    $q->execute(array(':t'=>$tenantId, ':u'=>(int)$member['id']));
    return ((int)$q->fetchColumn() > 0);
}

/* ---------------- Invitation SMTP helpers ---------------- */
function mt_smtp_key()
{
    $key = '';
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) $key = trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY);
    if ($key === '') {
        $v = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($v !== false) $key = trim((string)$v);
    }
    if ($key === '') {
        $v = getenv('APP_KEY');
        if ($v !== false) $key = trim((string)$v);
    }
    if ($key === '' || $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY' || strlen($key) < 32) {
        throw new RuntimeException('SMTP encryption key is not configured correctly.');
    }
    return hash('sha256', $key, true);
}

function mt_decrypt_smtp($stored, $tenantId)
{
    $stored = trim((string)$stored);
    if ($stored === '') return '';

    if (strpos($stored, 'v1:') === 0) {
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw !== false && strlen($raw) > 16 && function_exists('openssl_decrypt')) {
            $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', mt_smtp_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
            if ($plain !== false && trim((string)$plain) !== '') return (string)$plain;
        }
    }

    if (function_exists('fieldplxDecryptSmtpPassword')) {
        try {
            $plain = fieldplxDecryptSmtpPassword($stored);
            if (trim((string)$plain) !== '') return (string)$plain;
        } catch (Throwable $ignore) {}
    }

    $legacy = strpos($stored, 'v1:') === 0 ? substr($stored, 3) : $stored;
    $raw = base64_decode($legacy, true);
    if ($raw !== false && strlen($raw) > 16 && function_exists('openssl_decrypt')) {
        $env = getenv('FIELDPLX_APP_KEY');
        if ($env === false || trim((string)$env) === '') {
            $seed = (defined('DB_NAME') ? DB_NAME : '') . '|' . (defined('DB_USER') ? DB_USER : '') . '|' . (defined('DB_PASS') ? DB_PASS : '') . '|' . (int)$tenantId;
        } else {
            $seed = trim((string)$env) . '|' . (int)$tenantId;
        }
        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', hash('sha256', $seed, true), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        if ($plain !== false && trim((string)$plain) !== '') return (string)$plain;
    }
    throw new RuntimeException('SMTP password could not be decrypted. Re-save and test SMTP settings.');
}

function mt_smtp_config(PDO $pdo, $tenantId, $branchId)
{
    if (!mt_table($pdo, 'smtp_configurations')) throw new RuntimeException('SMTP configuration is not installed.');
    $rows = array();

    $q = $pdo->query("SELECT * FROM smtp_configurations
                      WHERE scope_type='platform' AND tenant_id IS NULL AND branch_id IS NULL AND is_active=1
                      ORDER BY CASE WHEN last_test_status='success' THEN 0 ELSE 1 END,is_default DESC,COALESCE(last_tested_at,updated_at,created_at) DESC,id DESC");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;

    $q = $pdo->prepare("SELECT * FROM smtp_configurations
                        WHERE tenant_id=:t AND is_active=1 AND scope_type IN('tenant','branch')
                          AND (scope_type='tenant' OR (scope_type='branch' AND branch_id=:b))
                        ORDER BY CASE WHEN last_test_status='success' THEN 0 ELSE 1 END,
                                 CASE WHEN scope_type='branch' AND branch_id=:b2 THEN 0 ELSE 1 END,
                                 is_default DESC,COALESCE(last_tested_at,updated_at,created_at) DESC,id DESC");
    $bk = $branchId > 0 ? $branchId : -1;
    $q->execute(array(':t'=>$tenantId, ':b'=>$bk, ':b2'=>$bk));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = $r;

    foreach ($rows as $r) {
        try {
            $password = mt_decrypt_smtp(isset($r['password_encrypted']) ? $r['password_encrypted'] : '', $tenantId);
            return array($r, $password);
        } catch (Throwable $e) {
            error_log('Manage Team SMTP candidate skipped: ' . $e->getMessage());
        }
    }
    throw new RuntimeException('No usable SMTP configuration was found. Configure and test SMTP first.');
}

function mt_load_phpmailer()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) return;
    $root = dirname(__DIR__, 2);
    $paths = array(
        $root . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php'
    );
    foreach ($paths as $path) {
        if (!is_file($path)) continue;
        require_once $path;
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return;
    }
    throw new RuntimeException('PHPMailer could not be loaded. Install PHPMailer 6.9.x.');
}

function mt_base_url()
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    if ($host === '') return '';
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
    $base = $script !== '' ? dirname(dirname($script)) : '';
    $base = ($base === '/' || $base === '.') ? '' : rtrim($base, '/');
    return $scheme . '://' . $host . $base;
}

function mt_identity(PDO $pdo, $tenantId)
{
    $out = array('name'=>'FieldPlx Business', 'logo_path'=>'');
    if (mt_table($pdo, 'tenants')) {
        $q = $pdo->prepare("SELECT display_name,legal_name,logo_path FROM tenants WHERE id=:t LIMIT 1");
        $q->execute(array(':t'=>$tenantId));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $name = trim((string)$r['display_name']);
            if ($name === '') $name = trim((string)$r['legal_name']);
            if ($name !== '') $out['name'] = $name;
            if (!empty($r['logo_path'])) $out['logo_path'] = (string)$r['logo_path'];
        }
    }
    if (mt_table($pdo, 'tenant_business_profiles')) {
        try {
            $q = $pdo->prepare("SELECT logo_path FROM tenant_business_profiles WHERE tenant_id=:t LIMIT 1");
            $q->execute(array(':t'=>$tenantId));
            $logo = trim((string)$q->fetchColumn());
            if ($logo !== '') $out['logo_path'] = $logo;
        } catch (Throwable $ignore) {}
    }
    return $out;
}

function mt_send_invitation(PDO $pdo, $tenantId, $branchId, $member, $rawToken)
{
    $email = trim((string)$member['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The team member email address is invalid.');
    list($config, $password) = mt_smtp_config($pdo, $tenantId, $branchId);
    mt_load_phpmailer();

    $identity = mt_identity($pdo, $tenantId);
    $base = mt_base_url();
    if ($base === '') throw new RuntimeException('Unable to determine the invitation URL.');
    $acceptUrl = rtrim($base, '/') . '/accept-invitation.php?token=' . rawurlencode($rawToken);
    $name = trim((string)$member['first_name'] . ' ' . (string)$member['last_name']);
    $safeBusiness = htmlspecialchars($identity['name'], ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($acceptUrl, ENT_QUOTES, 'UTF-8');
    $logoUrl = '';
    if ($identity['logo_path'] !== '') {
        $logoUrl = preg_match('#^https?://#i', $identity['logo_path']) ? $identity['logo_path'] : rtrim($base, '/') . '/' . ltrim($identity['logo_path'], '/');
    }
    $brand = $logoUrl !== ''
        ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $safeBusiness . '" style="max-width:54px;max-height:54px;display:block;margin-bottom:22px">'
        : '<div style="font-size:22px;font-weight:800;color:#fff;margin-bottom:22px">FieldPlx</div>';
    $html = '<!doctype html><html><body style="margin:0;background:#eef3f5;font-family:Arial,Helvetica,sans-serif">'
          . '<div style="padding:32px 14px"><div style="max-width:520px;margin:0 auto;background:#10171b;border-radius:10px;padding:30px;color:#d8e2e6">'
          . $brand
          . '<div style="font-size:25px;line-height:1.25;font-weight:800;color:#fff">You have been added to<br>' . $safeBusiness . ' on FieldPlx</div>'
          . '<p style="margin:20px 0 0;line-height:1.6;color:#b9c8ce">Hello ' . $safeName . ',</p>'
          . '<p style="line-height:1.6;color:#b9c8ce">A seat has been assigned to you. Finish setting up your account to access your team, schedule and work.</p>'
          . '<p style="margin:24px 0 0"><a href="' . $safeUrl . '" style="display:inline-block;padding:13px 18px;background:#2f8c25;color:#fff;text-decoration:none;border-radius:5px;font-weight:700">Accept the Invitation</a></p>'
          . '<div style="height:1px;background:#2a3439;margin:28px 0 18px"></div>'
          . '<div style="font-size:12px;color:#8799a1">This invitation expires in 48 hours.</div>'
          . '</div></div></body></html>';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string)$config['host']);
    $mail->Port = (int)$config['port'];
    $mail->Timeout = 30;
    $mail->SMTPDebug = 0;
    $username = trim((string)$config['username']);
    $mail->SMTPAuth = $username !== '';
    if ($mail->SMTPAuth) {
        $mail->Username = $username;
        $mail->Password = (string)$password;
    }
    $enc = strtolower(trim((string)$config['encryption']));
    if ($enc === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
    } elseif ($enc === 'tls' || $enc === 'starttls') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    $fromEmail = trim((string)$config['from_email']);
    if ($fromEmail === '') $fromEmail = $username;
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The configured SMTP From Email is invalid.');
    $fromName = trim((string)$config['from_name']);
    if ($fromName === '') $fromName = 'FieldPlx';
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);
    $reply = trim((string)$config['reply_to_email']);
    if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($reply);
    $mail->addAddress($email, $name);
    $mail->isHTML(true);
    $mail->Subject = 'You have been added to ' . $identity['name'] . ' on FieldPlx';
    $mail->Body = $html;
    $mail->AltBody = "Hello " . $name . ",\n\nA seat has been assigned to you on FieldPlx.\nAccept the invitation: " . $acceptUrl . "\n\nThis invitation expires in 48 hours.";
    $mail->send();
    return (int)$config['id'];
}

$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$actorId = isset($currentTenantUserId) ? (int)$currentTenantUserId : (isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0));
$branchId = isset($currentBranchId) ? (int)$currentBranchId : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);
if ($tenantId <= 0 || $actorId <= 0) mt_out(401, false, 'Tenant session is not available.');
$actor = mt_actor($pdo, $tenantId, $actorId);
$ownerId = mt_owner_id($pdo, $tenantId);
$action = mt_post('action', 'list');

try {
    if ($action === 'list') {
        mt_require_any($pdo, $tenantId, $actor, array('employees.view','teams.view','administration.view'));

        $search = mt_post('search');
        $assignedPage = max(1, (int)mt_post('assigned_page', '1'));
        $unassignedPage = max(1, (int)mt_post('unassigned_page', '1'));
        $assignedPer = (int)mt_post('assigned_per_page', '25');
        $unassignedPer = (int)mt_post('unassigned_per_page', '25');
        if (!in_array($assignedPer, array(10,25,50), true)) $assignedPer = 25;
        if (!in_array($unassignedPer, array(10,25,50), true)) $unassignedPer = 25;

        $where = "u.tenant_id=:tenant AND u.deleted_at IS NULL";
        $params = array(':tenant'=>$tenantId);
        if ($search !== '') {
            $where .= " AND (u.first_name LIKE :s1 OR COALESCE(u.last_name,'') LIKE :s2 OR u.email LIKE :s3 OR COALESCE(u.phone,'') LIKE :s4 OR COALESCE(r.name,'') LIKE :s5)";
            $like = '%' . $search . '%';
            $params[':s1'] = $like;
            $params[':s2'] = $like;
            $params[':s3'] = $like;
            $params[':s4'] = $like;
            $params[':s5'] = $like;
        }

        $hasToken = mt_table($pdo, 'tenant_activation_tokens')
            ? "EXISTS(SELECT 1 FROM tenant_activation_tokens tat WHERE tat.tenant_id=u.tenant_id AND tat.user_id=u.id AND tat.used_at IS NULL)"
            : "0";
        $seatExpr = "(u.id=" . (int)$ownerId . " OR u.status='active' OR (u.status='invited' AND " . $hasToken . "))";

        $countSql = "SELECT
                        SUM(CASE WHEN $seatExpr THEN 1 ELSE 0 END) AS assigned_count,
                        SUM(CASE WHEN NOT $seatExpr THEN 1 ELSE 0 END) AS unassigned_count
                     FROM users u
                     LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id
                     WHERE $where";
        $q = $pdo->prepare($countSql);
        $q->execute($params);
        $counts = $q->fetch(PDO::FETCH_ASSOC);
        $assignedCount = (int)(isset($counts['assigned_count']) ? $counts['assigned_count'] : 0);
        $unassignedCount = (int)(isset($counts['unassigned_count']) ? $counts['unassigned_count'] : 0);

        $assignedPages = max(1, (int)ceil($assignedCount / $assignedPer));
        $unassignedPages = max(1, (int)ceil($unassignedCount / $unassignedPer));
        if ($assignedPage > $assignedPages) $assignedPage = $assignedPages;
        if ($unassignedPage > $unassignedPages) $unassignedPage = $unassignedPages;
        $assignedOffset = ($assignedPage - 1) * $assignedPer;
        $unassignedOffset = ($unassignedPage - 1) * $unassignedPer;

        $deviceSelect = mt_table($pdo, 'user_devices')
            ? ", (SELECT COUNT(*) FROM user_devices d WHERE d.tenant_id=u.tenant_id AND d.user_id=u.id AND d.status='active') AS active_session_count"
            : ", 0 AS active_session_count";
        $select = "SELECT u.id,u.first_name,u.last_name,u.email,u.phone,u.avatar_path,u.role_id,u.is_tenant_admin,u.status,u.last_login_at,u.created_at,
                          r.name AS role_name,r.code AS role_code,
                          CASE WHEN u.id=" . (int)$ownerId . " THEN 1 ELSE 0 END AS is_owner
                          $deviceSelect
                   FROM users u
                   LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id
                   WHERE $where AND ";

        $qa = $pdo->prepare($select . $seatExpr . " ORDER BY is_owner DESC,u.is_tenant_admin DESC,u.first_name,u.last_name,u.id LIMIT :lim OFFSET :off");
        foreach ($params as $k=>$v) $qa->bindValue($k, $v, PDO::PARAM_STR);
        $qa->bindValue(':lim', $assignedPer, PDO::PARAM_INT);
        $qa->bindValue(':off', $assignedOffset, PDO::PARAM_INT);
        $qa->execute();
        $assigned = $qa->fetchAll(PDO::FETCH_ASSOC);

        $qu = $pdo->prepare($select . "NOT " . $seatExpr . " ORDER BY u.first_name,u.last_name,u.id LIMIT :lim OFFSET :off");
        foreach ($params as $k=>$v) $qu->bindValue($k, $v, PDO::PARAM_STR);
        $qu->bindValue(':lim', $unassignedPer, PDO::PARAM_INT);
        $qu->bindValue(':off', $unassignedOffset, PDO::PARAM_INT);
        $qu->execute();
        $unassigned = $qu->fetchAll(PDO::FETCH_ASSOC);

        $totalSeats = max(1, $assignedCount);
        $included = min(1, $totalSeats);
        $paid = max(0, $totalSeats - $included);

        mt_out(200, true, 'Team members loaded.', array(
            'assigned_members'=>$assigned,
            'unassigned_members'=>$unassigned,
            'assigned_pagination'=>array(
                'page'=>$assignedPage,'per_page'=>$assignedPer,'total'=>$assignedCount,'pages'=>$assignedPages,
                'from'=>$assignedCount ? $assignedOffset + 1 : 0,'to'=>min($assignedOffset + $assignedPer, $assignedCount)
            ),
            'unassigned_pagination'=>array(
                'page'=>$unassignedPage,'per_page'=>$unassignedPer,'total'=>$unassignedCount,'pages'=>$unassignedPages,
                'from'=>$unassignedCount ? $unassignedOffset + 1 : 0,'to'=>min($unassignedOffset + $unassignedPer, $unassignedCount)
            ),
            'seats'=>array('assigned'=>$assignedCount,'included'=>$included,'paid'=>$paid,'total'=>$totalSeats,'changes_available'=>false),
            'can_invite'=>mt_can_any($pdo,$tenantId,$actor,array('employees.create','administration.create','teams.create'))
        ));
    }

    if ($action === 'assign_seat') {
        mt_csrf();
        mt_require_any($pdo, $tenantId, $actor, array('employees.update','administration.update','teams.update'));
        $userId = (int)mt_post('user_id', '0');
        if ($userId <= 0) mt_out(422, false, 'Team member is required.');
        $member = mt_member($pdo, $tenantId, $userId);
        if (!$member) mt_out(404, false, 'Team member not found.');
        if ($userId === $ownerId) mt_out(409, false, 'The account owner already has a seat.');
        if ((string)$member['status'] === 'suspended') mt_out(409, false, 'Suspended team members cannot be assigned a seat until they are unsuspended.');
        if (mt_has_seat($pdo, $tenantId, $member, $ownerId)) mt_out(200, true, 'This team member already has a seat.');

        $needsInvitation = empty($member['last_login_at']);
        $smtpId = null;
        $pdo->beginTransaction();
        try {
            if ($needsInvitation) {
                if (!mt_table($pdo, 'tenant_activation_tokens')) throw new RuntimeException('The tenant activation token table is required to invite this member.');
                $q = $pdo->prepare("DELETE FROM tenant_activation_tokens WHERE tenant_id=:t AND user_id=:u AND used_at IS NULL");
                $q->execute(array(':t'=>$tenantId, ':u'=>$userId));
                $rawToken = bin2hex(random_bytes(32));
                $q = $pdo->prepare("INSERT INTO tenant_activation_tokens(tenant_id,user_id,token_hash,expires_at,created_at) VALUES(:t,:u,:h,DATE_ADD(NOW(),INTERVAL 48 HOUR),NOW())");
                $q->execute(array(':t'=>$tenantId, ':u'=>$userId, ':h'=>hash('sha256',$rawToken)));
                $q = $pdo->prepare("UPDATE users SET status='invited',updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
                $q->execute(array(':id'=>$userId, ':t'=>$tenantId));
                $smtpId = mt_send_invitation($pdo, $tenantId, $branchId, $member, $rawToken);
            } else {
                $q = $pdo->prepare("UPDATE users SET status='active',updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
                $q->execute(array(':id'=>$userId, ':t'=>$tenantId));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('FieldPlx assign seat error: ' . $e->getMessage());
            mt_out(502, false, $needsInvitation ? 'The seat was not assigned because the invitation email could not be sent. ' . $e->getMessage() : 'Unable to assign the seat.');
        }

        mt_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_SEAT_ASSIGNED',$userId,array(
            'invitation_sent'=>$needsInvitation ? 1 : 0,
            'smtp_config_id'=>$smtpId
        ));
        mt_out(200, true, $needsInvitation ? 'Seat assigned and invitation email sent successfully.' : 'Seat assigned successfully.', array('invitation_sent'=>$needsInvitation));
    }

    if ($action === 'unassign_seat') {
        mt_csrf();
        mt_require_any($pdo, $tenantId, $actor, array('employees.update','administration.update','teams.update'));
        $userId = (int)mt_post('user_id', '0');
        if ($userId <= 0) mt_out(422, false, 'Team member is required.');
        $member = mt_member($pdo, $tenantId, $userId);
        if (!$member) mt_out(404, false, 'Team member not found.');
        if ($userId === $ownerId) mt_out(409, false, 'The account owner cannot be unassigned.');
        if ($userId === $actorId) mt_out(409, false, 'You cannot unassign your own seat.');

        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("UPDATE users SET status='inactive',updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
            $q->execute(array(':id'=>$userId, ':t'=>$tenantId));
            if (mt_table($pdo, 'tenant_activation_tokens')) {
                $q = $pdo->prepare("DELETE FROM tenant_activation_tokens WHERE tenant_id=:t AND user_id=:u AND used_at IS NULL");
                $q->execute(array(':t'=>$tenantId, ':u'=>$userId));
            }
            if (mt_table($pdo, 'user_devices')) {
                $q = $pdo->prepare("UPDATE user_devices SET status='revoked',updated_at=NOW() WHERE tenant_id=:t AND user_id=:u AND status='active'");
                $q->execute(array(':t'=>$tenantId, ':u'=>$userId));
            }
            if (mt_table($pdo, 'team_members')) {
                $q = $pdo->prepare("DELETE tm FROM team_members tm INNER JOIN teams t ON t.id=tm.team_id WHERE t.tenant_id=:t AND tm.user_id=:u");
                $q->execute(array(':t'=>$tenantId, ':u'=>$userId));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        mt_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_SEAT_UNASSIGNED',$userId,array('email'=>$member['email']));
        mt_out(200, true, 'Seat unassigned successfully. The team member has been signed out.');
    }

    if ($action === 'delete_member') {
        mt_csrf();
        mt_require_any($pdo, $tenantId, $actor, array('employees.delete','administration.delete','teams.delete','employees.update'));
        $userId = (int)mt_post('user_id', '0');
        if ($userId <= 0) mt_out(422, false, 'Team member is required.');
        $member = mt_member($pdo, $tenantId, $userId);
        if (!$member) mt_out(404, false, 'Team member not found.');
        if ($userId === $ownerId) mt_out(409, false, 'The account owner cannot be deleted.');
        if ($userId === $actorId) mt_out(409, false, 'You cannot delete your own account from Manage Team.');

        $oldEmail = (string)$member['email'];
        $deletedEmail = 'deleted-' . $userId . '-' . time() . '@deleted.fieldplx.local';
        $avatar = !empty($member['avatar_path']) ? (string)$member['avatar_path'] : '';

        $pdo->beginTransaction();
        try {
            if (mt_table($pdo, 'user_devices')) {
                $q = $pdo->prepare("UPDATE user_devices SET status='revoked',updated_at=NOW() WHERE tenant_id=:t AND user_id=:u AND status='active'");
                $q->execute(array(':t'=>$tenantId, ':u'=>$userId));
            }
            if (mt_table($pdo, 'tenant_activation_tokens')) {
                $q = $pdo->prepare("DELETE FROM tenant_activation_tokens WHERE tenant_id=:t AND user_id=:u");
                $q->execute(array(':t'=>$tenantId, ':u'=>$userId));
            }
            if (mt_table($pdo, 'team_members')) {
                $q = $pdo->prepare("DELETE tm FROM team_members tm INNER JOIN teams t ON t.id=tm.team_id WHERE t.tenant_id=:t AND tm.user_id=:u");
                $q->execute(array(':t'=>$tenantId, ':u'=>$userId));
            }
            $q = $pdo->prepare("UPDATE users SET status='inactive',email=:deleted_email,avatar_path=NULL,deleted_at=NOW(),updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
            $q->execute(array(':deleted_email'=>$deletedEmail, ':id'=>$userId, ':t'=>$tenantId));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        if ($avatar !== '' && strpos($avatar, 'uploads/profile/tenant-' . $tenantId . '/') === 0) {
            $file = dirname(__DIR__) . '/' . ltrim($avatar, '/');
            if (is_file($file)) @unlink($file);
        }
        mt_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_DELETED',$userId,array('email'=>$oldEmail));
        mt_out(200, true, 'Team member deleted successfully.');
    }

    mt_out(400, false, 'Invalid Manage Team action.');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx Manage Team database error: ' . $e->getMessage());
    mt_out(500, false, 'Unable to complete the Manage Team request.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx Manage Team error: ' . $e->getMessage());
    mt_out(500, false, 'Unable to complete the Manage Team request.');
}
