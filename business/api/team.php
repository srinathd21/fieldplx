<?php
/**
 * FieldPlx Manage Team API
 * Compatible with PHP 7.2+ / MariaDB 11.x
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

/* --------------------------------------------------------------------------
 | Team invitation SMTP
 * -------------------------------------------------------------------------- */
$platformSmtpFile = __DIR__ . '/../includes/platform-smtp.php';
if (is_file($platformSmtpFile)) {
    require_once $platformSmtpFile;
}

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

function tm_out($status, $success, $message, $extra = array())
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

function tm_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function tm_table(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t' => $table));
    $cache[$table] = ((int)$q->fetchColumn() > 0);
    return $cache[$table];
}

function tm_column(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$q->fetchColumn() > 0);
    return $cache[$key];
}

function tm_csrf()
{
    $posted = tm_post('csrf_token');
    $saved = isset($_SESSION['team_settings_csrf']) ? (string)$_SESSION['team_settings_csrf'] : '';
    if ($saved === '' || $posted === '' || !hash_equals($saved, $posted)) {
        tm_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function tm_actor(PDO $pdo, $tenantId, $userId)
{
    $q = $pdo->prepare("SELECT id,tenant_id,role_id,is_tenant_admin,status FROM users WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id'=>$userId, ':t'=>$tenantId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string)$row['status'] !== 'active') {
        tm_out(401, false, 'Your login session is no longer active.');
    }
    return $row;
}

function tm_permission(PDO $pdo, $tenantId, $userId, $roleId, $isAdmin, $code)
{
    if ((int)$isAdmin === 1) return true;
    if (!tm_table($pdo, 'permissions')) return true;

    $q = $pdo->prepare("SELECT id FROM permissions WHERE permission_code=:c LIMIT 1");
    $q->execute(array(':c'=>$code));
    $permissionId = (int)$q->fetchColumn();
    if ($permissionId <= 0) return true; // backward-compatible when a permission is not installed yet

    if (tm_table($pdo, 'user_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenantId, ':u'=>$userId, ':p'=>$permissionId));
        $override = $q->fetchColumn();
        if ($override !== false) return ((string)$override === 'allow');
    }

    if ($roleId > 0 && tm_table($pdo, 'role_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenantId, ':r'=>$roleId, ':p'=>$permissionId));
        $access = $q->fetchColumn();
        if ($access !== false) return ((string)$access === 'allow');
    }
    return false;
}

function tm_can_any(PDO $pdo, $tenantId, $actor, $codes)
{
    foreach ($codes as $code) {
        if (tm_permission($pdo, $tenantId, (int)$actor['id'], (int)$actor['role_id'], (int)$actor['is_tenant_admin'], $code)) return true;
    }
    return false;
}

function tm_require_any(PDO $pdo, $tenantId, $actor, $codes)
{
    if (!tm_can_any($pdo, $tenantId, $actor, $codes)) {
        tm_out(403, false, 'You do not have permission to manage team members.');
    }
}

function tm_member(PDO $pdo, $tenantId, $userId)
{
    $q = $pdo->prepare("SELECT u.*,r.name AS role_name,r.code AS role_code,r.is_admin AS role_is_admin FROM users u LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE u.id=:id AND u.tenant_id=:t AND u.deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id'=>$userId, ':t'=>$tenantId));
    return $q->fetch(PDO::FETCH_ASSOC);
}

function tm_member_is_owner($member)
{
    if (!is_array($member)) return false;
    $code = strtolower(trim((string)(isset($member['role_code']) ? $member['role_code'] : '')));
    $name = strtolower(trim((string)(isset($member['role_name']) ? $member['role_name'] : '')));
    return in_array($code, array('owner','account_owner','tenant_owner'), true)
        || in_array($name, array('owner','account owner','tenant owner'), true);
}

function tm_normalize_ids($values)
{
    $out = array();
    if (!is_array($values)) return $out;
    foreach ($values as $v) {
        $id = (int)$v;
        if ($id > 0) $out[$id] = $id;
    }
    return array_values($out);
}

function tm_audit(PDO $pdo, $tenantId, $branchId, $actorId, $action, $objectId, $newValues)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog($pdo, $action, $tenantId, $branchId > 0 ? $branchId : null, $actorId, 'user', $objectId, null, $newValues);
        } catch (Throwable $ignore) {
            // Do not fail the main team action if the optional audit helper has a different installation signature.
        }
    }
}


function tm_flag($key, $default = 0)
{
    if (!isset($_POST[$key])) return (int)$default;
    if (is_array($_POST[$key])) return (int)$default;
    return ((string)$_POST[$key] === '1' || (string)$_POST[$key] === 'on') ? 1 : 0;
}

function tm_enum_post($key, $allowed, $default)
{
    $value = tm_post($key, $default);
    return in_array($value, $allowed, true) ? $value : $default;
}

function tm_smtp_secret_key()
{
    $key = '';
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) $key = trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY);
    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) $key = trim((string)$env);
    }
    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) $key = trim((string)$env);
    }
    if ($key === '' || $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY') {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.');
    }
    if (strlen($key) < 32) throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.');
    return hash('sha256', $key, true);
}

function tm_decrypt_smtp_password($stored, $tenantId)
{
    $stored = trim((string)$stored);
    if ($stored === '') return '';

    if (strpos($stored, 'v1:') === 0) {
        if (!function_exists('openssl_decrypt')) throw new RuntimeException('OpenSSL extension is unavailable.');
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw !== false && strlen($raw) > 16) {
            $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', tm_smtp_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
            if ($plain !== false && trim((string)$plain) !== '') return (string)$plain;
        }
    }

    if (function_exists('fieldplxDecryptSmtpPassword')) {
        try {
            $plain = fieldplxDecryptSmtpPassword($stored);
            if (trim((string)$plain) !== '') return (string)$plain;
        } catch (Throwable $ignore) {}
    }

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
        if ($plain !== false && trim((string)$plain) !== '') return (string)$plain;
    }

    throw new RuntimeException('SMTP password could not be decrypted. Re-save and test the SMTP configuration.');
}

function tm_get_smtp(PDO $pdo, $tenantId, $branchId)
{
    if (!tm_table($pdo, 'smtp_configurations')) throw new RuntimeException('SMTP configuration table is not installed.');
    $candidates = array();

    $q = $pdo->query("SELECT * FROM smtp_configurations
                      WHERE scope_type='platform' AND tenant_id IS NULL AND branch_id IS NULL AND is_active=1
                      ORDER BY CASE WHEN last_test_status='success' THEN 0 ELSE 1 END,
                               is_default DESC,
                               COALESCE(last_tested_at,updated_at,created_at) DESC,
                               id DESC");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $candidates[] = $row;

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
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $candidates[] = $row;

    if (!$candidates) throw new RuntimeException('No active SMTP configuration was found. Configure and test SMTP first.');

    foreach ($candidates as $config) {
        try {
            $password = tm_decrypt_smtp_password(isset($config['password_encrypted']) ? $config['password_encrypted'] : '', $tenantId);
            $username = trim((string)(isset($config['username']) ? $config['username'] : ''));
            if ($username !== '' && trim((string)$password) === '') throw new RuntimeException('SMTP password is empty.');
            return array($config, $password);
        } catch (Throwable $e) {
            error_log('Team invitation SMTP candidate skipped: ' . $e->getMessage());
        }
    }

    throw new RuntimeException('No active SMTP configuration could be decrypted. Re-save and test SMTP settings.');
}

function tm_composer_autoload_path()
{
    $projectRoot = dirname(__DIR__, 2);
    $paths = array(
        $projectRoot . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php'
    );
    foreach ($paths as $path) if (is_file($path)) return $path;
    return '';
}

function tm_load_phpmailer()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) return;
    $autoloadPath = tm_composer_autoload_path();
    if ($autoloadPath === '') throw new RuntimeException('Composer vendor/autoload.php was not found. Install PHPMailer 6.9.x.');
    require_once $autoloadPath;
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) throw new RuntimeException('PHPMailer could not be loaded.');
}

function tm_business_identity(PDO $pdo, $tenantId)
{
    $out = array('name'=>'FieldPlx Business', 'logo_path'=>'');
    if (tm_table($pdo, 'tenants')) {
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
    if (tm_table($pdo, 'tenant_business_profiles') && tm_column($pdo, 'tenant_business_profiles', 'logo_path')) {
        $q = $pdo->prepare("SELECT logo_path FROM tenant_business_profiles WHERE tenant_id=:t LIMIT 1");
        $q->execute(array(':t'=>$tenantId));
        $logo = trim((string)$q->fetchColumn());
        if ($logo !== '') $out['logo_path'] = $logo;
    }
    return $out;
}

function tm_business_base_url()
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    if ($host === '') return '';
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
    $basePath = $script !== '' ? dirname(dirname($script)) : '';
    $basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
    return $scheme . '://' . $host . $basePath;
}

function tm_absolute_asset_url($baseUrl, $path)
{
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function tm_invitation_html($businessName, $inviteeName, $acceptUrl, $logoUrl, $isAdmin)
{
    $business = htmlspecialchars((string)$businessName, ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars((string)$inviteeName, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars((string)$acceptUrl, ENT_QUOTES, 'UTF-8');
    $logo = htmlspecialchars((string)$logoUrl, ENT_QUOTES, 'UTF-8');
    $roleLine = $isAdmin
        ? 'You have been invited as an administrator with full account access.'
        : 'You have been invited to join the team and start working in FieldPlx.';
    $roleLine = htmlspecialchars($roleLine, ENT_QUOTES, 'UTF-8');
    $brand = $logo !== ''
        ? '<img src="' . $logo . '" alt="' . $business . '" style="max-width:54px;max-height:54px;display:block;margin-bottom:22px">'
        : '<div style="font-size:22px;font-weight:800;letter-spacing:.2px;color:#fff;margin-bottom:22px">FieldPlx</div>';

    return '<!doctype html><html><body style="margin:0;background:#eef3f5;font-family:Arial,Helvetica,sans-serif;color:#d8e2e6">'
        . '<div style="padding:32px 14px"><div style="max-width:520px;margin:0 auto;background:#10171b;border-radius:10px;padding:30px 30px 24px;box-shadow:0 18px 45px rgba(8,24,31,.18)">'
        . $brand
        . '<div style="font-size:26px;line-height:1.22;font-weight:800;color:#fff">You have been added to<br>' . $business . ' on FieldPlx</div>'
        . '<p style="margin:20px 0 0;line-height:1.6;color:#b9c8ce">Hello ' . $name . ',</p>'
        . '<p style="margin:8px 0 0;line-height:1.6;color:#b9c8ce">' . $roleLine . '</p>'
        . '<div style="margin:24px 0 0;font-size:16px;font-weight:700;color:#fff">Do your best work:</div>'
        . '<ul style="padding-left:20px;margin:12px 0 0;color:#b9c8ce;line-height:1.75">'
        . '<li>View your schedule and get notified of changes</li><li>Access job details and checklists</li><li>Add notes and photos from the field</li><li>Track your work hours</li></ul>'
        . '<div style="margin-top:24px;font-size:16px;font-weight:700;color:#fff">Click below to finish your profile setup and join your team!</div>'
        . '<p style="margin:22px 0 0"><a href="' . $url . '" style="display:inline-block;padding:13px 18px;background:#2f8c25;color:#fff;text-decoration:none;border-radius:5px;font-weight:700">Accept the Invitation</a></p>'
        . '<div style="height:1px;background:#2a3439;margin:30px 0 20px"></div>'
        . '<div style="font-size:13px;color:#93a5ad">This invitation expires in 48 hours. If you were not expecting this invitation, you can ignore this email.</div>'
        . '<div style="height:1px;background:#2a3439;margin:22px 0 18px"></div>'
        . '<div style="font-size:11px;color:#74858d">Delivered by FieldPlx · <a href="' . htmlspecialchars(rtrim(tm_business_base_url(), '/') . '/privacy-policy', ENT_QUOTES, 'UTF-8') . '" style="color:#8fc8df">Privacy Policy</a></div>'
        . '</div></div></body></html>';
}

function tm_send_invitation(PDO $pdo, $tenantId, $branchId, $to, $inviteeName, $rawToken, $isAdmin)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The invited team member email address is invalid.');
    list($config, $password) = tm_get_smtp($pdo, $tenantId, $branchId);
    tm_load_phpmailer();

    $identity = tm_business_identity($pdo, $tenantId);
    $baseUrl = tm_business_base_url();
    if ($baseUrl === '') throw new RuntimeException('Unable to determine the FieldPlx invitation URL.');
    $acceptUrl = rtrim($baseUrl, '/') . '/accept-invitation.php?token=' . rawurlencode($rawToken);
    $logoUrl = tm_absolute_asset_url($baseUrl, $identity['logo_path']);
    $subject = 'You have been added to ' . $identity['name'] . ' on FieldPlx';
    $html = tm_invitation_html($identity['name'], $inviteeName, $acceptUrl, $logoUrl, $isAdmin);

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string)$config['host']);
    $mail->Port = (int)$config['port'];
    $mail->Timeout = 30;
    if (property_exists($mail, 'Timelimit')) $mail->Timelimit = 30;
    $mail->SMTPDebug = 0;
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
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The configured SMTP From Email is invalid.');
    $fromName = trim((string)$config['from_name']);
    if ($fromName === '') $fromName = 'FieldPlx';
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);
    $replyTo = trim((string)$config['reply_to_email']);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
    $mail->addAddress($to, $inviteeName);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $html;
    $mail->AltBody = "Hello " . $inviteeName . ",\n\nYou have been added to " . $identity['name'] . " on FieldPlx.\nAccept the invitation: " . $acceptUrl . "\n\nThis invitation expires in 48 hours.";
    $mail->send();

    return array('smtp_config_id'=>(int)$config['id'], 'accept_url'=>$acceptUrl, 'subject'=>$subject);
}

function tm_password_reset_html($businessName, $memberName, $resetUrl, $logoUrl)
{
    $business = htmlspecialchars((string)$businessName, ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars((string)$memberName, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars((string)$resetUrl, ENT_QUOTES, 'UTF-8');
    $logo = htmlspecialchars((string)$logoUrl, ENT_QUOTES, 'UTF-8');
    $brand = $logo !== ''
        ? '<img src="' . $logo . '" alt="' . $business . '" style="max-width:54px;max-height:54px;display:block;margin-bottom:22px">'
        : '<div style="font-size:22px;font-weight:800;color:#fff;margin-bottom:22px">FieldPlx</div>';

    return '<!doctype html><html><body style="margin:0;background:#eef3f5;font-family:Arial,Helvetica,sans-serif;color:#d8e2e6">'
        . '<div style="padding:32px 14px"><div style="max-width:520px;margin:0 auto;background:#10171b;border-radius:10px;padding:30px;box-shadow:0 18px 45px rgba(8,24,31,.18)">'
        . $brand
        . '<div style="font-size:25px;line-height:1.22;font-weight:800;color:#fff">Reset your FieldPlx password</div>'
        . '<p style="margin:20px 0 0;line-height:1.6;color:#b9c8ce">Hello ' . $name . ',</p>'
        . '<p style="margin:8px 0 0;line-height:1.6;color:#b9c8ce">A password reset was requested for your account at ' . $business . '.</p>'
        . '<p style="margin:22px 0 0"><a href="' . $url . '" style="display:inline-block;padding:13px 18px;background:#2f8c25;color:#fff;text-decoration:none;border-radius:5px;font-weight:700">Reset Password</a></p>'
        . '<p style="margin:20px 0 0;line-height:1.55;color:#93a5ad;font-size:13px">This link expires in 60 minutes and can only be used once. If you were not expecting this email, you can ignore it.</p>'
        . '<div style="height:1px;background:#2a3439;margin:24px 0 18px"></div>'
        . '<div style="font-size:11px;color:#74858d">Delivered by FieldPlx</div>'
        . '</div></div></body></html>';
}

function tm_send_password_reset(PDO $pdo, $tenantId, $branchId, $to, $memberName, $rawToken)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The team member email address is invalid.');
    list($config, $password) = tm_get_smtp($pdo, $tenantId, $branchId);
    tm_load_phpmailer();

    $identity = tm_business_identity($pdo, $tenantId);
    $baseUrl = tm_business_base_url();
    if ($baseUrl === '') throw new RuntimeException('Unable to determine the FieldPlx password reset URL.');
    $resetUrl = rtrim($baseUrl, '/') . '/reset-password.php?token=' . rawurlencode($rawToken);
    $logoUrl = tm_absolute_asset_url($baseUrl, $identity['logo_path']);
    $subject = 'Reset your FieldPlx password';
    $html = tm_password_reset_html($identity['name'], $memberName, $resetUrl, $logoUrl);

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string)$config['host']);
    $mail->Port = (int)$config['port'];
    $mail->Timeout = 30;
    if (property_exists($mail, 'Timelimit')) $mail->Timelimit = 30;
    $mail->SMTPDebug = 0;
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
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The configured SMTP From Email is invalid.');
    $fromName = trim((string)$config['from_name']);
    if ($fromName === '') $fromName = 'FieldPlx';
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);
    $replyTo = trim((string)$config['reply_to_email']);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
    $mail->addAddress($to, $memberName);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $html;
    $mail->AltBody = "Hello " . $memberName . ",\n\nReset your FieldPlx password using this link:\n" . $resetUrl . "\n\nThis link expires in 60 minutes and can only be used once.";
    $mail->send();

    return array('smtp_config_id'=>(int)$config['id'], 'reset_url'=>$resetUrl, 'subject'=>$subject);
}

function tm_team_defaults()
{
    return array(
        'preset_key'=>'field_crew',
        'schedule_enabled'=>1,'schedule_level'=>'complete_own',
        'time_tracking_enabled'=>1,'time_tracking_level'=>'timer_own',
        'notes_enabled'=>1,'notes_level'=>'job_visit',
        'files_media_enabled'=>0,
        'expenses_enabled'=>0,'expenses_level'=>'own',
        'show_pricing'=>0,'job_costing'=>0,
        'clients_enabled'=>0,'clients_level'=>'basic','show_clients_menu'=>0,
        'requests_enabled'=>0,'requests_level'=>'view','show_requests_menu'=>0,
        'quotes_enabled'=>0,'quotes_level'=>'view','show_quotes_menu'=>0,
        'jobs_enabled'=>1,'jobs_level'=>'view','show_jobs_menu'=>1,
        'invoices_enabled'=>0,'invoices_level'=>'view','show_invoices_menu'=>0,
        'payments_enabled'=>0,'payments_level'=>'both',
        'client_communications_enabled'=>0,'client_communications_level'=>'view',
        'reports_enabled'=>0,
        'surveys_enabled'=>1,'error_messages_enabled'=>1,'marketing_reminder_emails'=>1,'invitation_language'=>'en'
    );
}

function tm_team_settings(PDO $pdo, $tenantId, $userId)
{
    $defaults = tm_team_defaults();
    if (!tm_table($pdo, 'user_team_settings')) return $defaults;
    $q = $pdo->prepare("SELECT * FROM user_team_settings WHERE tenant_id=:t AND user_id=:u LIMIT 1");
    $q->execute(array(':t'=>$tenantId, ':u'=>$userId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ? array_merge($defaults, $row) : $defaults;
}

function tm_client_level_rank($value)
{
    $map = array('basic'=>1,'full'=>2,'edit'=>3,'delete'=>4);
    return isset($map[$value]) ? $map[$value] : 0;
}

function tm_module_level_rank($value)
{
    $map = array('view'=>1,'edit'=>2,'delete'=>3);
    return isset($map[$value]) ? $map[$value] : 0;
}

function tm_schedule_level_rank($value)
{
    $map = array('view_own'=>1,'complete_own'=>2,'edit_own'=>3,'edit_all'=>4,'delete_all'=>5);
    return isset($map[$value]) ? $map[$value] : 0;
}

function tm_settings_from_post($existing = null, $lockLanguage = false)
{
    $s = tm_team_defaults();
    $s['preset_key'] = tm_enum_post('preset_key', array('field_crew','senior_field_crew','crew_lead','manager','custom'), 'custom');
    $s['schedule_enabled'] = tm_flag('schedule_enabled');
    $s['schedule_level'] = tm_enum_post('schedule_level', array('view_own','complete_own','edit_own','edit_all','delete_all'), 'view_own');
    $s['time_tracking_enabled'] = tm_flag('time_tracking_enabled');
    $s['time_tracking_level'] = tm_enum_post('time_tracking_level', array('timer_own','manage_own','manage_all'), 'timer_own');
    $s['notes_enabled'] = tm_flag('notes_enabled');
    $s['notes_level'] = tm_enum_post('notes_level', array('job_visit','view_all','edit_all','delete_all'), 'job_visit');
    $s['files_media_enabled'] = tm_flag('files_media_enabled');
    $s['expenses_enabled'] = tm_flag('expenses_enabled');
    $s['expenses_level'] = tm_enum_post('expenses_level', array('own','all'), 'own');
    $s['show_pricing'] = tm_flag('show_pricing');
    $s['job_costing'] = tm_flag('job_costing');
    $s['clients_enabled'] = tm_flag('clients_enabled');
    $s['clients_level'] = tm_enum_post('clients_level', array('basic','full','edit','delete'), 'basic');
    $s['show_clients_menu'] = tm_flag('show_clients_menu');
    $s['requests_enabled'] = tm_flag('requests_enabled');
    $s['requests_level'] = tm_enum_post('requests_level', array('view','edit','delete'), 'view');
    $s['show_requests_menu'] = tm_flag('show_requests_menu');
    $s['quotes_enabled'] = tm_flag('quotes_enabled');
    $s['quotes_level'] = tm_enum_post('quotes_level', array('view','edit','delete'), 'view');
    $s['show_quotes_menu'] = tm_flag('show_quotes_menu');
    $s['jobs_enabled'] = tm_flag('jobs_enabled');
    $s['jobs_level'] = tm_enum_post('jobs_level', array('view','edit','delete'), 'view');
    $s['show_jobs_menu'] = tm_flag('show_jobs_menu');
    $s['invoices_enabled'] = tm_flag('invoices_enabled');
    $s['invoices_level'] = tm_enum_post('invoices_level', array('view','edit','delete'), 'view');
    $s['show_invoices_menu'] = tm_flag('show_invoices_menu');
    $s['payments_enabled'] = tm_flag('payments_enabled');
    $s['payments_level'] = tm_enum_post('payments_level', array('quotes','invoices','both'), 'both');
    $s['client_communications_enabled'] = tm_flag('client_communications_enabled');
    $s['client_communications_level'] = tm_enum_post('client_communications_level', array('view','send'), 'view');
    $s['reports_enabled'] = tm_flag('reports_enabled');
    $s['surveys_enabled'] = tm_flag('surveys_enabled');
    $s['error_messages_enabled'] = tm_flag('error_messages_enabled', 1);
    $s['marketing_reminder_emails'] = tm_flag('marketing_reminder_emails');
    $s['invitation_language'] = tm_enum_post('invitation_language', array('en','es'), 'en');

    if ($lockLanguage && is_array($existing) && !empty($existing['invitation_language'])) {
        $s['invitation_language'] = (string)$existing['invitation_language'];
    }

    // Server-side dependency enforcement. The browser mirrors these rules,
    // but the API must never trust client-side JavaScript alone.
    if ($s['payments_enabled']) {
        $s['show_pricing'] = 1;
        $s['clients_enabled'] = 1;
        if (tm_client_level_rank($s['clients_level']) < 3) $s['clients_level'] = 'edit';
        if ($s['payments_level'] === 'quotes' || $s['payments_level'] === 'both') {
            $s['quotes_enabled'] = 1;
            if (tm_module_level_rank($s['quotes_level']) < 2) $s['quotes_level'] = 'edit';
        }
        if ($s['payments_level'] === 'invoices' || $s['payments_level'] === 'both') {
            $s['invoices_enabled'] = 1;
            if (tm_module_level_rank($s['invoices_level']) < 2) $s['invoices_level'] = 'edit';
        }
    }

    if ($s['client_communications_enabled']) {
        $s['clients_enabled'] = 1;
        if (tm_client_level_rank($s['clients_level']) < 1) $s['clients_level'] = 'full';
    }

    // Creating/editing jobs requires permission to edit the user's own schedule.
    if ($s['jobs_enabled'] && tm_module_level_rank($s['jobs_level']) >= 2) {
        $s['schedule_enabled'] = 1;
        if (tm_schedule_level_rank($s['schedule_level']) < 3) $s['schedule_level'] = 'edit_own';
    }

    if (!($s['show_pricing'] && $s['time_tracking_enabled'] && $s['expenses_enabled'] && $s['jobs_enabled'])) {
        $s['job_costing'] = 0;
    }

    if (!$s['clients_enabled']) $s['show_clients_menu'] = 0;
    if (!$s['requests_enabled']) $s['show_requests_menu'] = 0;
    if (!$s['quotes_enabled']) $s['show_quotes_menu'] = 0;
    if (!$s['jobs_enabled']) $s['show_jobs_menu'] = 0;
    if (!$s['invoices_enabled']) $s['show_invoices_menu'] = 0;

    return $s;
}

function tm_permission_codes_for_settings($s)
{
    $codes = array();
    $add = function($code) use (&$codes) { $codes[$code] = $code; };

    if (!empty($s['schedule_enabled'])) {
        $add('schedule.view'); $add('scheduling.view');
        $add('schedule.' . $s['schedule_level']);
        if ($s['schedule_level'] === 'complete_own') { $add('jobs.update'); $add('visits.update'); }
        if ($s['schedule_level'] === 'edit_own') $add('schedule.update');
        if ($s['schedule_level'] === 'edit_all') { $add('schedule.update'); $add('scheduling.update'); }
        if ($s['schedule_level'] === 'delete_all') { $add('schedule.update'); $add('scheduling.update'); $add('schedule.delete'); $add('scheduling.delete'); }
    }

    if (!empty($s['time_tracking_enabled'])) {
        $add('workforce.view'); $add('time_tracking.' . $s['time_tracking_level']);
        if ($s['time_tracking_level'] !== 'timer_own') $add('workforce.update');
    }

    if (!empty($s['notes_enabled'])) {
        $add('notes.' . $s['notes_level']);
        if ($s['notes_level'] === 'job_visit') { $add('jobs.view'); $add('visits.view'); }
        if ($s['notes_level'] === 'view_all') { $add('jobs.view'); $add('visits.view'); $add('clients.view'); }
        if ($s['notes_level'] === 'edit_all') { $add('jobs.view'); $add('jobs.update'); $add('visits.view'); $add('visits.update'); $add('clients.view'); }
        if ($s['notes_level'] === 'delete_all') { $add('jobs.view'); $add('jobs.update'); $add('jobs.delete'); $add('visits.view'); $add('visits.update'); $add('visits.delete'); $add('clients.view'); }
    }

    if (!empty($s['files_media_enabled'])) { $add('files_media.view'); $add('clients.view'); }

    if (!empty($s['expenses_enabled'])) {
        $add('expenses.view'); $add('expenses.create'); $add('expenses.update');
        $add($s['expenses_level'] === 'all' ? 'expenses.manage_all' : 'expenses.manage_own');
    }

    if (!empty($s['show_pricing'])) $add('pricing.view');
    if (!empty($s['job_costing'])) { $add('job_costing.view'); $add('insights.view'); }

    if (!empty($s['clients_enabled'])) {
        $add('clients.view');
        $add($s['clients_level'] === 'basic' ? 'clients.basic_view' : 'clients.full_view');
        if (tm_client_level_rank($s['clients_level']) >= 3) { $add('clients.create'); $add('clients.update'); }
        if ($s['clients_level'] === 'delete') $add('clients.delete');
        if (!empty($s['show_clients_menu'])) $add('menu.clients');
    }

    $moduleMap = array(
        'requests'=>array('enabled'=>'requests_enabled','level'=>'requests_level','prefix'=>'requests','menu'=>'show_requests_menu','menu_code'=>'menu.requests'),
        'quotes'=>array('enabled'=>'quotes_enabled','level'=>'quotes_level','prefix'=>'quotations','menu'=>'show_quotes_menu','menu_code'=>'menu.quotes'),
        'jobs'=>array('enabled'=>'jobs_enabled','level'=>'jobs_level','prefix'=>'jobs','menu'=>'show_jobs_menu','menu_code'=>'menu.jobs'),
        'invoices'=>array('enabled'=>'invoices_enabled','level'=>'invoices_level','prefix'=>'invoices','menu'=>'show_invoices_menu','menu_code'=>'menu.invoices')
    );
    foreach ($moduleMap as $m) {
        if (empty($s[$m['enabled']])) continue;
        $add($m['prefix'] . '.view');
        if (tm_module_level_rank($s[$m['level']]) >= 2) { $add($m['prefix'] . '.create'); $add($m['prefix'] . '.update'); }
        if ($s[$m['level']] === 'delete') $add($m['prefix'] . '.delete');
        if (!empty($s[$m['menu']])) $add($m['menu_code']);
    }

    if (!empty($s['payments_enabled'])) {
        $add('payments.view'); $add('payments.create'); $add('payments.update');
        if ($s['payments_level'] === 'quotes' || $s['payments_level'] === 'both') $add('payments.collect_quotes');
        if ($s['payments_level'] === 'invoices' || $s['payments_level'] === 'both') $add('payments.collect_invoices');
    }

    if (!empty($s['client_communications_enabled'])) {
        $add('communication.view'); $add('client_communications.view');
        if ($s['client_communications_level'] === 'send') { $add('communication.create'); $add('communication.update'); $add('client_communications.send'); }
    }

    if (!empty($s['reports_enabled'])) $add('reports.view');

    return array_values($codes);
}

function tm_managed_permission_codes()
{
    return array(
        'schedule.view','schedule.update','schedule.delete','scheduling.view','scheduling.update','scheduling.delete',
        'schedule.view_own','schedule.complete_own','schedule.edit_own','schedule.edit_all','schedule.delete_all',
        'workforce.view','workforce.update','time_tracking.timer_own','time_tracking.manage_own','time_tracking.manage_all',
        'notes.job_visit','notes.view_all','notes.edit_all','notes.delete_all','files_media.view',
        'expenses.view','expenses.create','expenses.update','expenses.delete','expenses.manage_own','expenses.manage_all',
        'pricing.view','job_costing.view','insights.view',
        'clients.view','clients.create','clients.update','clients.delete','clients.basic_view','clients.full_view','menu.clients',
        'requests.view','requests.create','requests.update','requests.delete','menu.requests',
        'quotations.view','quotations.create','quotations.update','quotations.delete','menu.quotes',
        'jobs.view','jobs.create','jobs.update','jobs.delete','menu.jobs',
        'visits.view','visits.update','visits.delete',
        'invoices.view','invoices.create','invoices.update','invoices.delete','menu.invoices',
        'payments.view','payments.create','payments.update','payments.collect_quotes','payments.collect_invoices',
        'communication.view','communication.create','communication.update','client_communications.view','client_communications.send',
        'reports.view',
        'employees.view','employees.create','employees.update','employees.delete',
        'teams.view','teams.create','teams.update','teams.delete',
        'settings.view','settings.create','settings.update','settings.delete',
        'administration.view','administration.create','administration.update','administration.delete',
        'roles.view','roles.create','roles.update','roles.delete'
    );
}

function tm_sync_user_permissions(PDO $pdo, $tenantId, $userId, $isAdmin, $settings)
{
    if (!tm_table($pdo, 'user_permissions') || !tm_table($pdo, 'permissions')) return;
    $managed = tm_managed_permission_codes();
    $desired = array_flip(tm_permission_codes_for_settings($settings));
    if (!$managed) return;

    $place = implode(',', array_fill(0, count($managed), '?'));
    $q = $pdo->prepare("SELECT id,permission_code FROM permissions WHERE permission_code IN ($place)");
    $q->execute($managed);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;

    $ids = array();
    foreach ($rows as $r) $ids[] = (int)$r['id'];
    $idPlace = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge(array($tenantId, $userId), $ids);
    $q = $pdo->prepare("DELETE FROM user_permissions WHERE tenant_id=? AND user_id=? AND permission_id IN ($idPlace)");
    $q->execute($params);

    // Administrators are granted globally by is_tenant_admin, so no overrides are needed.
    if ($isAdmin) return;

    $ins = $pdo->prepare("INSERT INTO user_permissions(tenant_id,user_id,permission_id,access_type,created_at) VALUES(:t,:u,:p,:a,NOW())");
    foreach ($rows as $r) {
        $code = (string)$r['permission_code'];
        $ins->execute(array(':t'=>$tenantId, ':u'=>$userId, ':p'=>(int)$r['id'], ':a'=>isset($desired[$code]) ? 'allow' : 'deny'));
    }
}


function tm_default_availability()
{
    $rows = array();
    for ($d = 0; $d <= 6; $d++) {
        $rows[] = array(
            'weekday' => $d,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_available' => ($d >= 1 && $d <= 5) ? 1 : 0
        );
    }
    return $rows;
}

function tm_user_availability(PDO $pdo, $tenantId, $userId)
{
    $defaults = tm_default_availability();
    if (!tm_table($pdo, 'user_availability')) return $defaults;

    $q = $pdo->prepare("SELECT weekday,start_time,end_time,is_available FROM user_availability WHERE tenant_id=:t AND user_id=:u ORDER BY weekday ASC,id ASC");
    $q->execute(array(':t'=>$tenantId, ':u'=>$userId));
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return $defaults;

    $byDay = array();
    foreach ($rows as $r) {
        $d = (int)$r['weekday'];
        if ($d < 0 || $d > 6) continue;
        $byDay[$d] = array(
            'weekday'=>$d,
            'start_time'=>substr((string)$r['start_time'],0,5),
            'end_time'=>substr((string)$r['end_time'],0,5),
            'is_available'=>(int)$r['is_available'] ? 1 : 0
        );
    }
    foreach ($defaults as &$r) {
        if (isset($byDay[$r['weekday']])) $r = $byDay[$r['weekday']];
    }
    unset($r);
    return $defaults;
}

function tm_valid_time($value)
{
    return is_string($value) && preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $value);
}

function tm_actor_can_admin_password(PDO $pdo, $tenantId, $actor)
{
    if ((int)$actor['is_tenant_admin'] === 1) return true;
    $roleId = isset($actor['role_id']) ? (int)$actor['role_id'] : 0;
    if ($roleId > 0 && tm_table($pdo, 'roles') && tm_column($pdo, 'roles', 'is_admin')) {
        $q = $pdo->prepare("SELECT is_admin FROM roles WHERE id=:r AND tenant_id=:t LIMIT 1");
        $q->execute(array(':r'=>$roleId, ':t'=>$tenantId));
        return ((int)$q->fetchColumn() === 1);
    }
    return false;
}

function tm_save_team_settings(PDO $pdo, $tenantId, $userId, $s)
{
    $columns = array_keys(tm_team_defaults());
    $insertCols = array('tenant_id','user_id');
    $values = array(':tenant_id',':user_id');
    $updates = array();
    $params = array(':tenant_id'=>$tenantId, ':user_id'=>$userId);
    foreach ($columns as $c) {
        $insertCols[] = $c;
        $values[] = ':' . $c;
        $updates[] = $c . '=VALUES(' . $c . ')';
        $params[':' . $c] = $s[$c];
    }
    $sql = "INSERT INTO user_team_settings (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $values) . ") ON DUPLICATE KEY UPDATE " . implode(',', $updates) . ",updated_at=NOW()";
    $q = $pdo->prepare($sql);
    $q->execute($params);
}

$tenantId = isset($currentTenantId) ? (int)$currentTenantId : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);
$actorId = isset($currentTenantUserId) ? (int)$currentTenantUserId : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$branchId = isset($currentBranchId) ? (int)$currentBranchId : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);
if ($tenantId <= 0 || $actorId <= 0) tm_out(401, false, 'Tenant session is not available.');
$actor = tm_actor($pdo, $tenantId, $actorId);

$action = tm_post('action', isset($_GET['action']) ? (string)$_GET['action'] : 'list');

try {
    if ($action === 'list') {
        tm_require_any($pdo, $tenantId, $actor, array('employees.view','teams.view','administration.view'));
        $search = tm_post('search');
        $page = max(1, (int)tm_post('page', '1'));
        $perPage = (int)tm_post('per_page', '25');
        if (!in_array($perPage, array(10,25,50), true)) $perPage = 25;
        $where = "u.tenant_id=:t AND u.deleted_at IS NULL";
        $params = array(':t'=>$tenantId);
        if ($search !== '') {
            $where .= " AND (u.first_name LIKE :s OR COALESCE(u.last_name,'') LIKE :s OR u.email LIKE :s OR COALESCE(u.phone,'') LIKE :s OR COALESCE(u.employee_code,'') LIKE :s OR COALESCE(r.name,'') LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }
        $q = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE $where");
        $q->execute($params);
        $total = (int)$q->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $deviceSelect = tm_table($pdo, 'user_devices')
            ? ", (SELECT COUNT(*) FROM user_devices d WHERE d.tenant_id=u.tenant_id AND d.user_id=u.id AND d.status='active') AS active_session_count"
            : ", 0 AS active_session_count";
        $sql = "SELECT u.id,u.first_name,u.last_name,u.email,u.phone,u.avatar_path,u.role_id,u.is_tenant_admin,u.status,u.last_login_at,u.created_at,r.name AS role_name,r.code AS role_code $deviceSelect FROM users u LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE $where ORDER BY u.is_tenant_admin DESC, u.first_name ASC, u.last_name ASC, u.id ASC LIMIT :lim OFFSET :off";
        $q = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $q->bindValue($k, $v, PDO::PARAM_STR);
        $q->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $q->bindValue(':off', $offset, PDO::PARAM_INT);
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=:t AND deleted_at IS NULL");
        $q->execute(array(':t'=>$tenantId));
        $assigned = (int)$q->fetchColumn();

        tm_out(200, true, 'Team members loaded.', array(
            'members'=>$rows,
            'pagination'=>array('page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$total ? $offset+1 : 0,'to'=>min($offset+$perPage,$total)),
            'seats'=>array('assigned'=>$assigned,'included'=>1,'paid'=>0,'total'=>max(1,$assigned),'changes_available'=>false),
            'can_invite'=>tm_can_any($pdo,$tenantId,$actor,array('employees.create','administration.create','teams.create'))
        ));
    }

    if ($action === 'meta') {
        tm_require_any($pdo, $tenantId, $actor, array('employees.view','teams.view','administration.view'));

        $roles = array();
        if (tm_table($pdo, 'roles')) {
            $q = $pdo->prepare("SELECT id,name,code,is_admin FROM roles WHERE tenant_id=:t AND status='active' ORDER BY is_admin DESC,name ASC");
            $q->execute(array(':t'=>$tenantId));
            $roles = $q->fetchAll(PDO::FETCH_ASSOC);
        }

        $permissions = array();
        if (tm_table($pdo, 'permissions')) {
            $q = $pdo->query("SELECT id,permission_code,action_code,description FROM permissions ORDER BY permission_code ASC");
            $permissions = $q->fetchAll(PDO::FETCH_ASSOC);
        }

        $countries = array();
        if (tm_table($pdo, 'countries')) {
            $q = $pdo->query("SELECT id,name,iso2 FROM countries ORDER BY name ASC");
            $countries = $q->fetchAll(PDO::FETCH_ASSOC);
        }

        $requiredUserColumns = array('address_line1','city','state','postal_code','country_id');
        $migrationRequired = !tm_table($pdo, 'user_team_settings');
        if (!$migrationRequired && !tm_column($pdo, 'user_team_settings', 'error_messages_enabled')) $migrationRequired = true;
        foreach ($requiredUserColumns as $column) {
            if (!tm_column($pdo, 'users', $column)) $migrationRequired = true;
        }

        tm_out(200, true, 'Team metadata loaded.', array(
            'roles'=>$roles,
            'permissions'=>$permissions,
            'countries'=>$countries,
            'migration_required'=>$migrationRequired
        ));
    }

    if ($action === 'get_member') {
        tm_require_any($pdo, $tenantId, $actor, array('employees.view','teams.view','administration.view'));
        $id = (int)tm_post('user_id','0');
        if ($id <= 0) tm_out(422, false, 'Team member is required.');
        $member = tm_member($pdo, $tenantId, $id);
        if (!$member) tm_out(404, false, 'Team member not found.');

        $allow = array();
        $deny = array();
        if (tm_table($pdo, 'user_permissions')) {
            $q = $pdo->prepare("SELECT permission_id,access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u");
            $q->execute(array(':t'=>$tenantId, ':u'=>$id));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ((string)$r['access_type'] === 'allow') $allow[] = (int)$r['permission_id']; else $deny[] = (int)$r['permission_id'];
            }
        }

        $settings = tm_team_settings($pdo, $tenantId, $id);
        $settings['invitation_language_locked'] = 1;

        $isSelf = ($id === $actorId);
        $canManageMember = tm_actor_can_admin_password($pdo, $tenantId, $actor) || tm_can_any($pdo, $tenantId, $actor, array('employees.update','administration.update','teams.update'));
        $isOwnerTarget = tm_member_is_owner($member);

        // Passwords are never directly changed by an administrator for another user.
        // The signed-in user can change only their own password. Other users receive
        // a one-time password-reset link by email.
        $canChangePassword = $isSelf && (string)$member['status'] === 'active';
        $canSendPasswordReset = !$isSelf && $canManageMember && (string)$member['status'] === 'active';

        // Owner cannot be deactivated. Administrators and normal users can be
        // deactivated by an authorized team manager as long as they are not self.
        $canDeactivate = !$isSelf
            && !$isOwnerTarget
            && in_array((string)$member['status'], array('active','invited'), true)
            && $canManageMember;

        tm_out(200, true, 'Team member loaded.', array(
            'member'=>$member,
            'team_settings'=>$settings,
            'permission_allow_ids'=>$allow,
            'permission_deny_ids'=>$deny,
            'availability'=>tm_user_availability($pdo,$tenantId,$id),
            'capabilities'=>array(
                'can_change_password'=>$canChangePassword ? 1 : 0,
                'can_send_password_reset'=>$canSendPasswordReset ? 1 : 0,
                'require_current_password'=>$isSelf ? 1 : 0,
                'can_deactivate'=>$canDeactivate ? 1 : 0,
                'is_owner_target'=>$isOwnerTarget ? 1 : 0,
                'is_self'=>$isSelf ? 1 : 0
            )
        ));
    }


    if ($action === 'save_availability') {
        tm_csrf();
        $id = (int)tm_post('user_id','0');
        if ($id <= 0) tm_out(422,false,'Team member is required.');
        $member = tm_member($pdo,$tenantId,$id);
        if (!$member) tm_out(404,false,'Team member not found.');
        if ($id !== $actorId) tm_require_any($pdo,$tenantId,$actor,array('employees.update','administration.update','teams.update'));
        if (!tm_table($pdo,'user_availability')) tm_out(409,false,'The user availability table is not installed.');

        $raw = tm_post('availability_json','');
        $rows = json_decode($raw,true);
        if (!is_array($rows)) tm_out(422,false,'Working hours are invalid.');
        $normalized = array();
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $day = isset($r['weekday']) ? (int)$r['weekday'] : -1;
            if ($day < 0 || $day > 6) continue;
            $on = !empty($r['is_available']) ? 1 : 0;
            $start = isset($r['start_time']) ? substr(trim((string)$r['start_time']),0,5) : '09:00';
            $end = isset($r['end_time']) ? substr(trim((string)$r['end_time']),0,5) : '17:00';
            if (!tm_valid_time($start) || !tm_valid_time($end)) tm_out(422,false,'Enter valid working hours.');
            if ($on && strcmp($start,$end) >= 0) tm_out(422,false,'End time must be later than start time.');
            $normalized[$day] = array('weekday'=>$day,'start_time'=>$start,'end_time'=>$end,'is_available'=>$on);
        }
        $defaults = tm_default_availability();
        foreach ($defaults as $r) if (!isset($normalized[$r['weekday']])) $normalized[$r['weekday']] = $r;
        ksort($normalized);

        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare("DELETE FROM user_availability WHERE tenant_id=:t AND user_id=:u");
            $q->execute(array(':t'=>$tenantId,':u'=>$id));
            $ins=$pdo->prepare("INSERT INTO user_availability(tenant_id,user_id,weekday,start_time,end_time,is_available) VALUES(:t,:u,:d,:s,:e,:a)");
            foreach ($normalized as $r) {
                $ins->execute(array(':t'=>$tenantId,':u'=>$id,':d'=>$r['weekday'],':s'=>$r['start_time'].':00',':e'=>$r['end_time'].':00',':a'=>$r['is_available']));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        tm_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_AVAILABILITY_UPDATED',$id,array('availability'=>array_values($normalized)));
        tm_out(200,true,'Working hours updated successfully.',array('availability'=>array_values($normalized)));
    }

    if ($action === 'send_password_reset') {
        tm_csrf();
        $id = (int)tm_post('user_id','0');
        if ($id <= 0) tm_out(422,false,'Team member is required.');
        $member = tm_member($pdo,$tenantId,$id);
        if (!$member) tm_out(404,false,'Team member not found.');
        if ($id === $actorId) tm_out(409,false,'Use Change password for your own account.');
        if (!tm_actor_can_admin_password($pdo,$tenantId,$actor) && !tm_can_any($pdo,$tenantId,$actor,array('employees.update','administration.update','teams.update'))) {
            tm_out(403,false,'You do not have permission to send password reset emails.');
        }
        if ((string)$member['status'] !== 'active') tm_out(409,false,'Password reset email is available only for active team members.');
        if (!tm_table($pdo,'tenant_password_reset_tokens')) tm_out(409,false,'Run the supplied password reset SQL migration first.');
        if (!filter_var((string)$member['email'], FILTER_VALIDATE_EMAIL)) tm_out(422,false,'This team member does not have a valid email address.');

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256',$rawToken);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);
        $requestIp = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'],0,45) : null;

        // Invalidate older unused password reset links for this user.
        $q=$pdo->prepare("UPDATE tenant_password_reset_tokens SET used_at=NOW() WHERE tenant_id=:t AND user_id=:u AND used_at IS NULL");
        $q->execute(array(':t'=>$tenantId,':u'=>$id));

        $q=$pdo->prepare("INSERT INTO tenant_password_reset_tokens(tenant_id,user_id,token_hash,request_ip,expires_at,used_at,created_by,created_at) VALUES(:t,:u,:h,:ip,:e,NULL,:by,NOW())");
        $q->execute(array(':t'=>$tenantId,':u'=>$id,':h'=>$tokenHash,':ip'=>$requestIp,':e'=>$expiresAt,':by'=>$actorId));
        $resetId=(int)$pdo->lastInsertId();

        $memberName=trim((string)$member['first_name'].' '.(string)$member['last_name']);
        if ($memberName==='') $memberName=(string)$member['email'];
        try {
            $mailInfo=tm_send_password_reset($pdo,$tenantId,$branchId,(string)$member['email'],$memberName,$rawToken);
        } catch (Throwable $e) {
            try {
                $q=$pdo->prepare("DELETE FROM tenant_password_reset_tokens WHERE id=:id AND tenant_id=:t");
                $q->execute(array(':id'=>$resetId,':t'=>$tenantId));
            } catch (Throwable $ignore) {}
            throw $e;
        }

        tm_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_PASSWORD_RESET_SENT',$id,array(
            'email'=>$member['email'],
            'expires_at'=>$expiresAt,
            'smtp_config_id'=>isset($mailInfo['smtp_config_id']) ? (int)$mailInfo['smtp_config_id'] : null
        ));
        tm_out(200,true,'Password reset email sent to '.$member['email'].'.');
    }

    if ($action === 'change_password') {
        tm_csrf();
        $id = (int)tm_post('user_id','0');
        if ($id <= 0) tm_out(422,false,'Team member is required.');
        $member = tm_member($pdo,$tenantId,$id);
        if (!$member) tm_out(404,false,'Team member not found.');

        $isSelf = ($id === $actorId);
        if (!$isSelf) tm_out(403,false,'You cannot directly change another team member password. Send them a password reset email instead.');

        $newPassword = tm_post('new_password');
        $confirm = tm_post('confirm_password');
        if (strlen($newPassword) < 8 || !preg_match('/[A-Za-z]/',$newPassword) || !preg_match('/\\d/',$newPassword)) {
            tm_out(422,false,'Password must be at least 8 characters and include a letter and a number.');
        }
        if (!hash_equals($newPassword,$confirm)) tm_out(422,false,'New password and confirmation do not match.');

        if ($isSelf) {
            $current = tm_post('current_password');
            if ($current === '' || !password_verify($current,(string)$member['password_hash'])) tm_out(422,false,'Current password is incorrect.');
        }

        $hash = password_hash($newPassword,PASSWORD_DEFAULT);
        $q=$pdo->prepare("UPDATE users SET password_hash=:p,updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
        $q->execute(array(':p'=>$hash,':id'=>$id,':t'=>$tenantId));

        if (tm_table($pdo,'user_devices')) {
            $currentDeviceId = !empty($_SESSION['tenant_device_session_id']) ? (int)$_SESSION['tenant_device_session_id'] : 0;
            if ($isSelf && $currentDeviceId > 0) {
                $q=$pdo->prepare("UPDATE user_devices SET status='revoked',updated_at=NOW() WHERE tenant_id=:t AND user_id=:u AND status='active' AND id<>:current");
                $q->execute(array(':t'=>$tenantId,':u'=>$id,':current'=>$currentDeviceId));
            } else {
                $q=$pdo->prepare("UPDATE user_devices SET status='revoked',updated_at=NOW() WHERE tenant_id=:t AND user_id=:u AND status='active'");
                $q->execute(array(':t'=>$tenantId,':u'=>$id));
            }
        }
        tm_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_PASSWORD_CHANGED',$id,array('self_service'=>1));
        tm_out(200,true,'Your password was changed successfully.');
    }

    if ($action === 'deactivate_member') {
        tm_csrf();
        $id = (int)tm_post('user_id','0');
        if ($id <= 0) tm_out(422,false,'Team member is required.');
        $member = tm_member($pdo,$tenantId,$id);
        if (!$member) tm_out(404,false,'Team member not found.');
        if (!tm_actor_can_admin_password($pdo,$tenantId,$actor) && !tm_can_any($pdo,$tenantId,$actor,array('employees.update','administration.update','teams.update'))) {
            tm_out(403,false,'You do not have permission to deactivate team members.');
        }
        if ($id === $actorId) tm_out(409,false,'You cannot deactivate your own account.');
        if (tm_member_is_owner($member)) tm_out(409,false,'The account owner cannot be deactivated from this page.');

        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare("UPDATE users SET status='inactive',updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
            $q->execute(array(':id'=>$id,':t'=>$tenantId));
            if (tm_table($pdo,'user_devices')) {
                $q=$pdo->prepare("UPDATE user_devices SET status='revoked',updated_at=NOW() WHERE tenant_id=:t AND user_id=:u AND status='active'");
                $q->execute(array(':t'=>$tenantId,':u'=>$id));
            }
            if (tm_table($pdo,'tenant_activation_tokens')) {
                $q=$pdo->prepare("DELETE FROM tenant_activation_tokens WHERE tenant_id=:t AND user_id=:u AND used_at IS NULL");
                $q->execute(array(':t'=>$tenantId,':u'=>$id));
            }
            if (tm_table($pdo,'team_members') && tm_table($pdo,'teams')) {
                $q=$pdo->prepare("DELETE tm FROM team_members tm INNER JOIN teams t ON t.id=tm.team_id WHERE t.tenant_id=:t AND tm.user_id=:u");
                $q->execute(array(':t'=>$tenantId,':u'=>$id));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        tm_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_DEACTIVATED',$id,array('email'=>$member['email']));
        tm_out(200,true,'Team member deactivated successfully.');
    }

    if ($action === 'save_member') {
        tm_csrf();
        $id = (int)tm_post('user_id','0');
        $isNewMember = $id <= 0;
        tm_require_any($pdo, $tenantId, $actor, $id > 0 ? array('employees.update','administration.update','teams.update') : array('employees.create','administration.create','teams.create'));

        if (!tm_table($pdo, 'user_team_settings') || !tm_column($pdo,'user_team_settings','error_messages_enabled') || !tm_column($pdo,'users','address_line1') || !tm_column($pdo,'users','city') || !tm_column($pdo,'users','state') || !tm_column($pdo,'users','postal_code') || !tm_column($pdo,'users','country_id')) {
            tm_out(409, false, 'Run the supplied Team Member SQL migration before saving this form.');
        }
        $fullName = trim(tm_post('full_name'));
        $bits = preg_split('/\s+/', $fullName, 2);
        $firstName = isset($bits[0]) ? trim($bits[0]) : '';
        $lastName = isset($bits[1]) ? trim($bits[1]) : '';
        $email = strtolower(tm_post('email'));
        $phone = tm_post('phone');
        $addressLine1 = tm_post('address_line1');
        $city = tm_post('city');
        $state = tm_post('state');
        $postalCode = tm_post('postal_code');
        $countryId = (int)tm_post('country_id','0');
        $laborRateRaw = tm_post('labor_rate');
        $isAdmin = tm_flag('is_tenant_admin');
        $saveAndAssign = tm_flag('save_and_assign');

        if ($firstName === '') tm_out(422, false, 'Full name is required.');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) tm_out(422, false, 'Enter a valid email address.');
        if ($laborRateRaw !== '' && (!is_numeric($laborRateRaw) || (float)$laborRateRaw < 0)) tm_out(422, false, 'Labour cost must be zero or higher.');
        $laborRate = $laborRateRaw === '' ? '0.00' : number_format((float)$laborRateRaw, 2, '.', '');

        if ($countryId > 0 && tm_table($pdo,'countries')) {
            $q=$pdo->prepare("SELECT COUNT(*) FROM countries WHERE id=:id");
            $q->execute(array(':id'=>$countryId));
            if (!(int)$q->fetchColumn()) tm_out(422,false,'Select a valid country.');
        } else {
            $countryId = null;
        }

        $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:t AND email=:e AND deleted_at IS NULL" . ($id > 0 ? " AND id<>:id" : '') . " LIMIT 1");
        $p = array(':t'=>$tenantId, ':e'=>$email);
        if ($id > 0) $p[':id']=$id;
        $q->execute($p);
        if ($q->fetchColumn()) tm_out(409, false, 'This email address is already used by another team member.');

        $old = $id > 0 ? tm_member($pdo, $tenantId, $id) : null;
        if ($id > 0 && !$old) tm_out(404, false, 'Team member not found.');
        if ($id === $actorId && $isAdmin === 0 && !empty($old['is_tenant_admin'])) {
            tm_out(409, false, 'You cannot remove your own administrator access.');
        }

        /*
         * Save Member only stores the teammate. It does not send an invitation.
         * Save and Assign sends an invitation only when the account has not yet
         * been activated (new user or an existing user still in invited status).
         */
        $shouldSendInvitation = $saveAndAssign && (
            $isNewMember ||
            ($old && isset($old['status']) && (string)$old['status'] === 'invited')
        );

        if ($shouldSendInvitation && !tm_table($pdo, 'tenant_activation_tokens')) {
            tm_out(409, false, 'The tenant activation token table is required before team invitations can be sent.');
        }

        $existingSettings = $id > 0 ? tm_team_settings($pdo,$tenantId,$id) : null;
        $settings = tm_settings_from_post($existingSettings, $id > 0);

        $removeAvatar = tm_flag('remove_avatar');
        $oldAvatarPath = $old && !empty($old['avatar_path']) ? (string)$old['avatar_path'] : null;
        $avatarPath = $removeAvatar ? null : $oldAvatarPath;
        $newAvatarAbsolute = '';
        if (isset($_FILES['avatar']) && is_array($_FILES['avatar']) && (int)$_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int)$_FILES['avatar']['error'] !== UPLOAD_ERR_OK) tm_out(422, false, 'Unable to upload the profile image.');
            if ((int)$_FILES['avatar']['size'] > 3 * 1024 * 1024) tm_out(422, false, 'Profile image must be 3 MB or smaller.');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($_FILES['avatar']['tmp_name']);
            $allowed = array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp');
            if (!isset($allowed[$mime])) tm_out(422, false, 'Only JPG, PNG or WEBP profile images are allowed.');
            $root = dirname(__DIR__) . '/uploads/profile/tenant-' . $tenantId;
            if (!is_dir($root) && !@mkdir($root, 0775, true)) tm_out(500, false, 'Unable to create the profile upload folder.');
            $filename = 'user-' . ($id > 0 ? $id : 'new') . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            $newAvatarAbsolute = $root . '/' . $filename;
            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $newAvatarAbsolute)) tm_out(500, false, 'Unable to save the profile image.');
            $avatarPath = 'uploads/profile/tenant-' . $tenantId . '/' . $filename;
            $removeAvatar = 0;
        }

        $preset = (string)$settings['preset_key'];
        $isFieldWorker = in_array($preset,array('field_crew','senior_field_crew','crew_lead'),true) ? 1 : ($old ? (int)$old['is_field_worker'] : 0);
        $isBookable = $old ? (int)$old['is_bookable'] : 1;
        if ($saveAndAssign) { $isFieldWorker = 1; $isBookable = 1; }
        $roleId = $old && !empty($old['role_id']) ? (int)$old['role_id'] : null;
        $inviteInfo = null;
        $sendingInvitation = false;

        try {
            $pdo->beginTransaction();

            if ($id > 0) {
                $q = $pdo->prepare("UPDATE users SET first_name=:fn,last_name=:ln,email=:email,phone=:phone,address_line1=:a1,city=:city,state=:state,postal_code=:postal,country_id=:country,labor_rate=:rate,is_tenant_admin=:admin,is_field_worker=:field,is_bookable=:book,avatar_path=:avatar,updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
                $q->execute(array(':fn'=>$firstName,':ln'=>$lastName!==''?$lastName:null,':email'=>$email,':phone'=>$phone!==''?$phone:null,':a1'=>$addressLine1!==''?$addressLine1:null,':city'=>$city!==''?$city:null,':state'=>$state!==''?$state:null,':postal'=>$postalCode!==''?$postalCode:null,':country'=>$countryId,':rate'=>$laborRate,':admin'=>$isAdmin,':field'=>$isFieldWorker,':book'=>$isBookable,':avatar'=>$avatarPath,':id'=>$id,':t'=>$tenantId));
            } else {
                /* The random placeholder password is never emailed. The invited user creates a password on accept-invitation.php. */
                $temporaryPassword = bin2hex(random_bytes(24));
                $q = $pdo->prepare("INSERT INTO users(tenant_id,branch_id,role_id,first_name,last_name,email,phone,address_line1,city,state,postal_code,country_id,password_hash,avatar_path,labor_rate,is_bookable,is_field_worker,is_tenant_admin,status,created_at) VALUES(:t,:branch,:role,:fn,:ln,:email,:phone,:a1,:city,:state,:postal,:country,:password,:avatar,:rate,:book,:field,:admin,'invited',NOW())");
                $q->execute(array(':t'=>$tenantId,':branch'=>$branchId>0?$branchId:null,':role'=>$roleId,':fn'=>$firstName,':ln'=>$lastName!==''?$lastName:null,':email'=>$email,':phone'=>$phone!==''?$phone:null,':a1'=>$addressLine1!==''?$addressLine1:null,':city'=>$city!==''?$city:null,':state'=>$state!==''?$state:null,':postal'=>$postalCode!==''?$postalCode:null,':country'=>$countryId,':password'=>password_hash($temporaryPassword,PASSWORD_DEFAULT),':avatar'=>$avatarPath,':rate'=>$laborRate,':book'=>$isBookable,':field'=>$isFieldWorker,':admin'=>$isAdmin));
                $id = (int)$pdo->lastInsertId();
            }

            tm_save_team_settings($pdo,$tenantId,$id,$settings);
            tm_sync_user_permissions($pdo,$tenantId,$id,$isAdmin,$settings);

            /*
             * Invitation is sent only for Save and Assign. Save Member leaves
             * the teammate unassigned and does not send any email.
             */
            if ($shouldSendInvitation) {
                /* Invalidate any older unused invitation links before issuing a new one. */
                $q = $pdo->prepare("DELETE FROM tenant_activation_tokens WHERE tenant_id=:t AND user_id=:u AND used_at IS NULL");
                $q->execute(array(':t'=>$tenantId, ':u'=>$id));

                $rawToken = bin2hex(random_bytes(32));
                $q = $pdo->prepare("INSERT INTO tenant_activation_tokens(tenant_id,user_id,token_hash,expires_at,created_at) VALUES(:t,:u,:h,DATE_ADD(NOW(),INTERVAL 48 HOUR),NOW())");
                $q->execute(array(':t'=>$tenantId,':u'=>$id,':h'=>hash('sha256',$rawToken)));

                $sendingInvitation = true;
                $inviteInfo = tm_send_invitation($pdo, $tenantId, $branchId, $email, trim($firstName . ' ' . $lastName), $rawToken, $isAdmin);
                $sendingInvitation = false;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newAvatarAbsolute !== '' && is_file($newAvatarAbsolute)) @unlink($newAvatarAbsolute);
            if ($sendingInvitation) {
                error_log('FieldPlx team invitation failed: ' . $e->getMessage());
                tm_out(502, false, $isNewMember
                    ? 'The team member was not created because the invitation email could not be sent. ' . $e->getMessage()
                    : 'The team member was not changed because the invitation email could not be sent. ' . $e->getMessage());
            }
            throw $e;
        }

        // Remove the previous profile image only after the database update succeeds.
        if ($oldAvatarPath && $oldAvatarPath !== $avatarPath) {
            $allowedPrefix = 'uploads/profile/tenant-' . $tenantId . '/';
            if (strpos($oldAvatarPath, $allowedPrefix) === 0) {
                $oldAvatarFile = dirname(__DIR__) . '/' . ltrim($oldAvatarPath, '/');
                if (is_file($oldAvatarFile)) @unlink($oldAvatarFile);
            }
        }

        $member = tm_member($pdo, $tenantId, $id);
        $invitationSent = $shouldSendInvitation && is_array($inviteInfo);
        $audit = array(
            'email'=>$email,
            'preset_key'=>$settings['preset_key'],
            'is_tenant_admin'=>$isAdmin,
            'save_and_assign'=>$saveAndAssign,
            'invitation_sent'=>$invitationSent ? 1 : 0
        );
        if (is_array($inviteInfo) && isset($inviteInfo['smtp_config_id'])) $audit['smtp_config_id'] = (int)$inviteInfo['smtp_config_id'];

        $auditAction = $old ? 'TEAM_MEMBER_UPDATED' : ($invitationSent ? 'TEAM_MEMBER_INVITED' : 'TEAM_MEMBER_CREATED');
        tm_audit($pdo,$tenantId,$branchId,$actorId,$auditAction,$id,$audit);

        if ($old) {
            $message = $invitationSent
                ? 'Team member updated successfully. Invitation email sent to ' . $email . '.'
                : 'Team member updated successfully.';
        } else {
            $message = $invitationSent
                ? 'Team member saved and invitation email sent to ' . $email . '.'
                : 'Team member saved successfully. No invitation email was sent.';
        }

        tm_out($old ? 200 : 201, true, $message, array(
            'member'=>$member,
            'team_settings'=>$settings,
            'user_id'=>$id,
            'invitation_sent'=>$invitationSent,
            'assign_url'=>'team.php?tab=crews&assign_user=' . $id
        ));
    }
    if ($action === 'sessions') {
        tm_require_any($pdo, $tenantId, $actor, array('employees.view','teams.view','administration.view'));
        $userId = (int)tm_post('user_id','0');
        if ($userId <= 0) tm_out(422, false, 'Team member is required.');
        $member = tm_member($pdo,$tenantId,$userId);
        if (!$member) tm_out(404,false,'Team member not found.');
        $rows = array();
        if (tm_table($pdo,'user_devices')) {
            $q = $pdo->prepare("SELECT id,platform,device_name,last_ip_address,last_seen_at,created_at,status FROM user_devices WHERE tenant_id=:t AND user_id=:u AND status='active' ORDER BY COALESCE(last_seen_at,created_at) DESC,id DESC");
            $q->execute(array(':t'=>$tenantId,':u'=>$userId));
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        tm_out(200,true,'Active sessions loaded.',array('member'=>$member,'sessions'=>$rows));
    }

    if ($action === 'signout_session' || $action === 'signout_all') {
        tm_csrf();
        tm_require_any($pdo, $tenantId, $actor, array('employees.update','administration.update','teams.update'));
        if (!tm_table($pdo,'user_devices')) tm_out(200,true,'No active device sessions are stored.');
        $userId = (int)tm_post('user_id','0');
        if ($userId <= 0 || !tm_member($pdo,$tenantId,$userId)) tm_out(404,false,'Team member not found.');
        if ($action === 'signout_all') {
            $q = $pdo->prepare("UPDATE user_devices SET status='revoked',updated_at=NOW() WHERE tenant_id=:t AND user_id=:u AND status='active'");
            $q->execute(array(':t'=>$tenantId,':u'=>$userId));
            tm_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_SESSIONS_REVOKED',$userId,array('count'=>$q->rowCount()));
            tm_out(200,true,'All active sessions were signed out.');
        }
        $deviceId = (int)tm_post('device_id','0');
        if ($deviceId <= 0) tm_out(422,false,'Session is required.');
        $q = $pdo->prepare("UPDATE user_devices SET status='revoked',updated_at=NOW() WHERE id=:id AND tenant_id=:t AND user_id=:u AND status='active'");
        $q->execute(array(':id'=>$deviceId,':t'=>$tenantId,':u'=>$userId));
        tm_audit($pdo,$tenantId,$branchId,$actorId,'TEAM_MEMBER_SESSION_REVOKED',$userId,array('device_id'=>$deviceId));
        tm_out(200,true,'Session signed out successfully.');
    }


    if ($action === 'list_crews') {
        tm_require_any($pdo, $tenantId, $actor, array('teams.view','employees.view','administration.view'));
        if (!tm_table($pdo,'teams') || !tm_table($pdo,'team_members')) {
            tm_out(200,true,'Crews loaded.',array('crews'=>array(),'sort_supported'=>false));
        }
        $sortSupported = tm_column($pdo,'team_members','sort_order');
        $q=$pdo->prepare("SELECT id,name,code,leader_user_id,status,created_at,updated_at FROM teams WHERE tenant_id=:t AND status='active' ORDER BY name,id");
        $q->execute(array(':t'=>$tenantId));
        $crews=$q->fetchAll(PDO::FETCH_ASSOC);
        $order=$sortSupported ? 'tm.sort_order ASC, tm.is_primary DESC, tm.joined_at ASC, tm.user_id ASC' : 'tm.is_primary DESC, tm.joined_at ASC, tm.user_id ASC';
        $mq=$pdo->prepare("SELECT tm.team_id,u.id,u.first_name,u.last_name,u.email,u.avatar_path,u.status,tm.is_primary".($sortSupported?',tm.sort_order':'')." FROM team_members tm INNER JOIN teams t ON t.id=tm.team_id AND t.tenant_id=:tenant INNER JOIN users u ON u.id=tm.user_id AND u.tenant_id=t.tenant_id AND u.deleted_at IS NULL WHERE tm.team_id=:team ORDER BY $order");
        foreach($crews as &$crew){
            $mq->execute(array(':tenant'=>$tenantId,':team'=>$crew['id']));
            $crew['members']=$mq->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($crew);
        tm_out(200,true,'Crews loaded.',array('crews'=>$crews,'sort_supported'=>$sortSupported));
    }

    if ($action === 'create_crew') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.create','administration.create'));
        if (!tm_table($pdo,'teams')) tm_out(500,false,'Teams table is not available.');
        $name=substr(trim(tm_post('name')),0,190);
        if ($name==='') tm_out(422,false,'Crew name is required.');
        $q=$pdo->prepare("SELECT id FROM teams WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) AND status='active' LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':n'=>$name));
        if ($q->fetchColumn()) tm_out(409,false,'A crew with this name already exists.');
        $q=$pdo->prepare("INSERT INTO teams(tenant_id,branch_id,department_id,name,code,leader_user_id,description,status,created_at) VALUES(:t,:b,NULL,:n,NULL,NULL,NULL,'active',NOW())");
        $q->execute(array(':t'=>$tenantId,':b'=>$branchId>0?$branchId:null,':n'=>$name));
        $id=(int)$pdo->lastInsertId();
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_CREATED',$id,array('name'=>$name));
        tm_out(201,true,'Crew created successfully.',array('crew_id'=>$id));
    }

    if ($action === 'rename_crew') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.update','administration.update'));
        $crewId=(int)tm_post('crew_id','0');
        $name=substr(trim(tm_post('name')),0,190);
        if ($crewId<=0 || $name==='') tm_out(422,false,'Crew and crew name are required.');
        $q=$pdo->prepare("SELECT id,name FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
        $q->execute(array(':id'=>$crewId,':t'=>$tenantId));
        $old=$q->fetch(PDO::FETCH_ASSOC);
        if(!$old) tm_out(404,false,'Crew not found.');
        $q=$pdo->prepare("SELECT id FROM teams WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) AND id<>:id AND status='active' LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':n'=>$name,':id'=>$crewId));
        if($q->fetchColumn()) tm_out(409,false,'A crew with this name already exists.');
        $q=$pdo->prepare("UPDATE teams SET name=:n,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        $q->execute(array(':n'=>$name,':id'=>$crewId,':t'=>$tenantId));
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_RENAMED',$crewId,array('old_name'=>$old['name'],'name'=>$name));
        tm_out(200,true,'Crew renamed successfully.');
    }

    if ($action === 'delete_crew') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.delete','administration.delete','teams.update'));
        $crewId=(int)tm_post('crew_id','0');
        if($crewId<=0) tm_out(422,false,'Crew is required.');
        $q=$pdo->prepare("SELECT id,name FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
        $q->execute(array(':id'=>$crewId,':t'=>$tenantId));
        $crew=$q->fetch(PDO::FETCH_ASSOC);
        if(!$crew) tm_out(404,false,'Crew not found.');
        $pdo->beginTransaction();
        $q=$pdo->prepare("DELETE FROM team_members WHERE team_id=:id");$q->execute(array(':id'=>$crewId));
        $q=$pdo->prepare("UPDATE teams SET status='inactive',updated_at=NOW() WHERE id=:id AND tenant_id=:t");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));
        $pdo->commit();
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_DELETED',$crewId,array('name'=>$crew['name']));
        tm_out(200,true,'Crew deleted successfully.');
    }

    if ($action === 'crew_candidates') {
        tm_require_any($pdo,$tenantId,$actor,array('teams.view','teams.update','employees.view','administration.view'));
        $crewId=(int)tm_post('crew_id','0');
        $search=tm_post('search');
        $q=$pdo->prepare("SELECT id FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));
        if(!$q->fetchColumn()) tm_out(404,false,'Crew not found.');
        $where="u.tenant_id=:t AND u.deleted_at IS NULL AND u.status IN ('active','invited') AND NOT EXISTS(SELECT 1 FROM team_members tm WHERE tm.team_id=:team AND tm.user_id=u.id)";
        $params=array(':t'=>$tenantId,':team'=>$crewId);
        if($search!==''){$where.=" AND (u.first_name LIKE :s OR COALESCE(u.last_name,'') LIKE :s OR u.email LIKE :s)";$params[':s']='%'.$search.'%';}
        $q=$pdo->prepare("SELECT u.id,u.first_name,u.last_name,u.email,u.avatar_path,u.status FROM users u WHERE $where ORDER BY u.first_name,u.last_name,u.id LIMIT 100");
        $q->execute($params);
        tm_out(200,true,'Available teammates loaded.',array('members'=>$q->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($action === 'add_crew_members') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.update','administration.update'));
        $crewId=(int)tm_post('crew_id','0');
        $ids=isset($_POST['user_ids'])?tm_normalize_ids($_POST['user_ids']):array();
        if($crewId<=0 || !$ids) tm_out(422,false,'Select at least one teammate.');
        $q=$pdo->prepare("SELECT id FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));if(!$q->fetchColumn())tm_out(404,false,'Crew not found.');
        $sortSupported=tm_column($pdo,'team_members','sort_order');
        $maxSort=0;if($sortSupported){$q=$pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM team_members WHERE team_id=:id");$q->execute(array(':id'=>$crewId));$maxSort=(int)$q->fetchColumn();}
        $valid=$pdo->prepare("SELECT id FROM users WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL AND status IN ('active','invited') LIMIT 1");
        $added=0;
        if($sortSupported)$ins=$pdo->prepare("INSERT IGNORE INTO team_members(team_id,user_id,member_role,is_primary,sort_order,joined_at) VALUES(:team,:user,NULL,0,:sort,NOW())");
        else $ins=$pdo->prepare("INSERT IGNORE INTO team_members(team_id,user_id,member_role,is_primary,joined_at) VALUES(:team,:user,NULL,0,NOW())");
        foreach($ids as $uid){$valid->execute(array(':id'=>$uid,':t'=>$tenantId));if(!$valid->fetchColumn())continue;$maxSort+=10;$params=array(':team'=>$crewId,':user'=>$uid);if($sortSupported)$params[':sort']=$maxSort;$ins->execute($params);$added+=$ins->rowCount();}
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_MEMBERS_ADDED',$crewId,array('user_ids'=>$ids,'added'=>$added));
        tm_out(200,true,$added.' teammate'.($added===1?'':'s').' added to crew.');
    }

    if ($action === 'remove_crew_member') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.update','administration.update'));
        $crewId=(int)tm_post('crew_id','0');$userId=(int)tm_post('user_id','0');
        $q=$pdo->prepare("SELECT id FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));if(!$q->fetchColumn())tm_out(404,false,'Crew not found.');
        $q=$pdo->prepare("DELETE FROM team_members WHERE team_id=:team AND user_id=:user");$q->execute(array(':team'=>$crewId,':user'=>$userId));
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_MEMBER_REMOVED',$crewId,array('user_id'=>$userId));
        tm_out(200,true,'Teammate removed from crew.');
    }

    if ($action === 'reorder_crew_members') {
        tm_csrf();
        tm_require_any($pdo,$tenantId,$actor,array('teams.update','administration.update'));
        if(!tm_column($pdo,'team_members','sort_order')) tm_out(409,false,'Run the crew member sort-order migration before using drag and drop.');
        $crewId=(int)tm_post('crew_id','0');$ids=isset($_POST['user_ids'])?tm_normalize_ids($_POST['user_ids']):array();
        $q=$pdo->prepare("SELECT id FROM teams WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$q->execute(array(':id'=>$crewId,':t'=>$tenantId));if(!$q->fetchColumn())tm_out(404,false,'Crew not found.');
        $q=$pdo->prepare("SELECT user_id FROM team_members WHERE team_id=:team");$q->execute(array(':team'=>$crewId));$existing=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));sort($existing);$check=$ids;sort($check);if($existing!==$check)tm_out(409,false,'Crew members changed while reordering. Refresh and try again.');
        $pdo->beginTransaction();$up=$pdo->prepare("UPDATE team_members SET sort_order=:sort WHERE team_id=:team AND user_id=:user");$sort=10;foreach($ids as $uid){$up->execute(array(':sort'=>$sort,':team'=>$crewId,':user'=>$uid));$sort+=10;}$pdo->commit();
        tm_audit($pdo,$tenantId,$branchId,$actorId,'CREW_MEMBERS_REORDERED',$crewId,array('user_ids'=>$ids));
        tm_out(200,true,'Crew order saved.');
    }

    tm_out(400,false,'Invalid team action.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx team API database error: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        tm_out(409,false,'This team member conflicts with an existing email or employee code.');
    }
    tm_out(500,false,'Unable to complete the team request.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx team API error: ' . $e->getMessage());
    tm_out(500,false,'Unable to complete the team request.');
}
