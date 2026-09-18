<?php
/* FieldPlx Request View - Client View referenced UI only */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Request View';
$pageDescription = 'Service request details, assessment, pricing, notes and activity';
$activePage = 'requests';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['request_view_csrf_token'])) {
    $_SESSION['request_view_csrf_token'] = bin2hex(random_bytes(32));
}
$requestViewCsrf = (string)$_SESSION['request_view_csrf_token'];
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

/* ==========================================================
   Inline booking confirmation email sender
   Uses configured FieldPlx SMTP. No separate booking-email API file.
   ========================================================== */
function rvInlineJson($code, $success, $message, array $extra = array())
{
    http_response_code((int)$code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message,
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rvInlinePost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function rvInlineFetchOne(PDO $pdo, $sql, array $params = array())
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function rvInlineSmtpSecretKey()
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
        throw new RuntimeException('SMTP encryption key is not configured. Use the same FIELDPLX_SMTP_ENCRYPTION_KEY used by Email & SMTP settings.');
    }
    if (strlen($key) < 32) {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.');
    }
    return hash('sha256', $key, true);
}

function rvInlineDecryptSmtpPassword($stored)
{
    $stored = trim((string)$stored);
    if ($stored === '') return '';
    if (strpos($stored, 'v1:') !== 0) return $stored;
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required to decrypt the configured SMTP password.');
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException('Stored SMTP password is invalid. Re-enter it in Email & SMTP settings.');
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', rvInlineSmtpSecretKey(), OPENSSL_RAW_DATA, $iv);
    if ($plain === false || $plain === '') {
        throw new RuntimeException('Unable to decrypt the SMTP password. Re-enter it in Email & SMTP settings and save again.');
    }
    return $plain;
}

function rvInlineLoadPhpMailer()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) return;
    $root = __DIR__;
    $paths = array(
        $root . '/vendor/autoload.php',
        $root . '/mailer/vendor/autoload.php',
        dirname($root) . '/vendor/autoload.php',
    );
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            break;
        }
    }
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('PHPMailer is not installed. Install PHPMailer with Composer or place vendor/autoload.php in the FieldPlx project.');
    }
}

function rvInlineLoadSmtp(PDO $pdo, $tenantId, $branchId)
{
    /*
     * Booking confirmation must use the LAST configured active SMTP row.
     * Example from current setup: the latest row is the platform "Alerts"
     * SMTP (smtp.hostinger.com:465 / SSL).
     *
     * We intentionally do not prefer tenant/branch/default rows here; the
     * newest active smtp_configurations.id wins.
     */
    $sql = "SELECT *
            FROM smtp_configurations
            WHERE is_active = 1
            ORDER BY id DESC
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute();
    $smtp = $st->fetch(PDO::FETCH_ASSOC);
    if (!$smtp) {
        throw new RuntimeException('No active SMTP configuration is available. Configure Email & SMTP first.');
    }
    $smtp['password'] = rvInlineDecryptSmtpPassword(isset($smtp['password_encrypted']) ? $smtp['password_encrypted'] : '');
    return $smtp;
}

function rvInlineSendSmtp(array $smtp, $toEmail, $toName, $subject, $plainMessage)
{
    rvInlineLoadPhpMailer();
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string)$smtp['host']);
    $mail->Port = (int)$smtp['port'];
    $mail->Timeout = 30;
    if (property_exists($mail, 'Timelimit')) $mail->Timelimit = 30;
    $mail->SMTPDebug = 0;
    $mail->SMTPKeepAlive = false;

    $username = trim((string)$smtp['username']);
    $mail->SMTPAuth = $username !== '';
    if ($mail->SMTPAuth) {
        if ((string)$smtp['password'] === '') throw new RuntimeException('Configured SMTP password is empty.');
        $mail->Username = $username;
        $mail->Password = (string)$smtp['password'];
    }

    $encryption = strtolower(trim((string)$smtp['encryption']));
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

    $fromEmail = trim((string)$smtp['from_email']);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Configured SMTP From Email is invalid.');
    }
    $fromName = trim((string)$smtp['from_name']);
    if ($fromName === '') $fromName = 'FieldPlx';

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);
    $replyTo = trim((string)$smtp['reply_to_email']);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($replyTo, $fromName);
    }

    $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:680px;margin:0 auto;color:#183548;line-height:1.6">' . nl2br(htmlspecialchars($plainMessage, ENT_QUOTES, 'UTF-8')) . '</div>';
    $mail->AltBody = $plainMessage;
    $mail->send();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string)rvInlinePost('action', '')) === 'send_booking_confirmation') {
    $tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
    $userId = !empty($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
    $postRequestId = (int)rvInlinePost('request_id', 0);
    $csrf = (string)rvInlinePost('csrf_token', '');
    $expectedCsrf = isset($_SESSION['request_view_csrf_token']) ? (string)$_SESSION['request_view_csrf_token'] : '';

    if ($tenantId <= 0 || $userId <= 0) rvInlineJson(401, false, 'Your login session has expired.');
    if ($postRequestId <= 0) rvInlineJson(422, false, 'Invalid service request.');
    if ($csrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) rvInlineJson(419, false, 'Your form session expired. Refresh and try again.');

    $to = trim((string)rvInlinePost('to', ''));
    $subject = trim((string)rvInlinePost('subject', ''));
    $message = trim((string)rvInlinePost('message', ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) rvInlineJson(422, false, 'Enter a valid recipient email address.');
    if ($subject === '') rvInlineJson(422, false, 'Email subject is required.');
    if ($message === '') rvInlineJson(422, false, 'Email message is required.');
    if (strlen($subject) > 255) rvInlineJson(422, false, 'Email subject is too long.');
    if (strlen($message) > 50000) rvInlineJson(422, false, 'Email message is too long.');

    try {
        $request = rvInlineFetchOne($pdo,
            "SELECT r.*, c.display_name AS client_name, c.email AS client_email
             FROM service_requests r
             INNER JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id
             WHERE r.id=:id AND r.tenant_id=:tenant_id
             LIMIT 1",
            array(':id'=>$postRequestId, ':tenant_id'=>$tenantId)
        );
        if (!$request) rvInlineJson(404, false, 'Service request not found.');

        $assessment = rvInlineFetchOne($pdo,
            "SELECT * FROM assessments
             WHERE tenant_id=:tenant_id AND request_id=:request_id
             ORDER BY id DESC LIMIT 1",
            array(':tenant_id'=>$tenantId, ':request_id'=>$postRequestId)
        );
        if (!$assessment) rvInlineJson(422, false, 'Create the on-site assessment before sending the booking confirmation.');
        if (empty($assessment['scheduled_start'])) rvInlineJson(422, false, 'Schedule the assessment before sending the booking confirmation.');

        $event = rvInlineFetchOne($pdo,
            "SELECT id FROM notification_events
             WHERE event_key='assessment.booking_confirmation' AND is_active=1 LIMIT 1"
        );
        if (!$event) throw new RuntimeException('Assessment Booking Confirmation email event is not active.');

        $branchId = !empty($request['branch_id']) ? (int)$request['branch_id'] : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);
        $smtp = rvInlineLoadSmtp($pdo, $tenantId, $branchId);

        $queue = $pdo->prepare("INSERT INTO notification_queue
            (tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address,related_type,related_id,subject,body,smtp_config_id,status,attempts,scheduled_at,created_at)
            VALUES (:tenant_id,:branch_id,:event_id,'email','client',:client_id,:recipient,'assessment',:assessment_id,:subject,:body,:smtp_id,'processing',1,NOW(),NOW())");
        $queue->execute(array(
            ':tenant_id'=>$tenantId,
            ':branch_id'=>$branchId>0?$branchId:null,
            ':event_id'=>(int)$event['id'],
            ':client_id'=>(int)$request['client_id'],
            ':recipient'=>$to,
            ':assessment_id'=>(int)$assessment['id'],
            ':subject'=>$subject,
            ':body'=>$message,
            ':smtp_id'=>(int)$smtp['id'],
        ));
        $queueId = (int)$pdo->lastInsertId();

        try {
            rvInlineSendSmtp($smtp, $to, trim((string)$request['client_name']), $subject, $message);
            $st = $pdo->prepare("UPDATE notification_queue SET status='sent',sent_at=NOW(),error_message=NULL WHERE id=:id AND tenant_id=:tenant_id");
            $st->execute(array(':id'=>$queueId, ':tenant_id'=>$tenantId));
        } catch (Throwable $mailError) {
            $st = $pdo->prepare("UPDATE notification_queue SET status='failed',error_message=:error WHERE id=:id AND tenant_id=:tenant_id");
            $st->execute(array(':error'=>substr($mailError->getMessage(),0,5000), ':id'=>$queueId, ':tenant_id'=>$tenantId));
            throw $mailError;
        }

        rvInlineJson(200, true, 'Booking confirmation email sent successfully.', array(
            'booking_email' => array(
                'available' => 1,
                'enabled' => 1,
                'sent' => 1,
                'sent_at' => date('Y-m-d H:i:s'),
                'to' => $to,
                'subject' => $subject,
                'message' => $message,
                'template_name' => 'Assessment Booking Confirmation',
                'smtp_config_id' => (int)$smtp['id'],
                'smtp_config_name' => (string)$smtp['config_name'],
                'mail_log_id' => $queueId,
            )
        ));
    } catch (Throwable $e) {
        error_log('FieldPlx inline booking confirmation SMTP: ' . $e->getMessage());
        rvInlineJson(500, false, 'Booking confirmation email failed: ' . $e->getMessage());
    }
}

require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
:root{
    --cv-primary:var(--primary,#2f8d25);
    --cv-primary-text:var(--primary-text,#fff);
    --cv-text:var(--text,#102f3b);
    --cv-muted:var(--muted,#667c87);
    --cv-bg:var(--body-bg,#f6f8fb);
    --cv-card:var(--card-bg,#fff);
    --cv-border:var(--card-border,#dce4e8);
    --cv-input:var(--input-bg,var(--card-bg,#fff));
    --cv-input-border:var(--input-border,var(--card-border,#dce4e8));
    --cv-row:var(--table-row-bg,var(--card-bg,#fff));
    --cv-hover:var(--table-hover-bg,color-mix(in srgb,var(--primary,#2f8d25) 6%,var(--card-bg,#fff)));
    --cv-shadow:var(--card-shadow,0 1px 2px rgba(10,32,45,.03));
    --cv-danger:#d84d4d;
    --cv-warning:#b58b14;
    --cv-info:#3d7188;
}
.cv-page{width:100%;max-width:1600px;margin:0 auto;padding:18px 22px 34px;color:var(--cv-text);font-family:inherit}.cv-layout{display:grid;grid-template-columns:minmax(0,1fr) 292px;gap:26px;align-items:start}.cv-main{min-width:0}.cv-aside{position:sticky;top:8px;z-index:25;min-width:0;height:calc(100dvh - var(--fieldplx-topbar-height,70px) - 16px);max-height:calc(100dvh - var(--fieldplx-topbar-height,70px) - 16px);align-self:start;overflow-y:auto;overflow-x:hidden;padding:0 4px 14px 0;scrollbar-width:thin;scrollbar-gutter:stable;scrollbar-color:color-mix(in srgb,var(--cv-muted) 42%,transparent) transparent;overscroll-behavior:contain;touch-action:pan-y}.cv-aside::-webkit-scrollbar{width:5px}.cv-aside::-webkit-scrollbar-track{background:transparent}.cv-aside::-webkit-scrollbar-thumb{background:color-mix(in srgb,var(--cv-muted) 42%,transparent);border-radius:999px}.cv-aside::-webkit-scrollbar-thumb:hover{background:color-mix(in srgb,var(--cv-muted) 62%,transparent)}.cv-aside-toolbar{position:sticky;top:0;z-index:40;height:38px;min-height:38px;display:flex;align-items:center;justify-content:flex-end;margin:0 0 8px;padding:0;}.cv-aside-toggle{width:36px;height:36px;border:1px solid var(--cv-border);background:var(--cv-card);color:var(--cv-text);border-radius:7px;display:grid;place-items:center;cursor:pointer;box-shadow:var(--cv-shadow)}.cv-aside-toggle:hover{border-color:color-mix(in srgb,var(--cv-primary) 45%,var(--cv-border));background:var(--cv-hover);color:var(--cv-primary)}.cv-aside-body{display:grid;align-content:start;grid-auto-rows:max-content;gap:13px;overflow:visible;padding:0;min-height:max-content}.cv-layout.aside-collapsed{grid-template-columns:minmax(0,1fr) 42px}.cv-layout.aside-collapsed .cv-aside-body{display:none}.cv-layout.aside-collapsed .cv-aside{width:42px;height:46px;max-height:46px;overflow:visible;padding-right:0}.cv-layout.aside-collapsed .cv-aside-toggle i{transform:rotate(180deg)}
.cv-profile{padding:2px 4px 24px;border-bottom:1px solid var(--cv-border)}.cv-topline{display:flex;align-items:center;gap:8px;min-height:38px}.cv-person-icon{font-size:17px;color:var(--cv-text)}.cv-status{display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:999px;background:color-mix(in srgb,var(--cv-primary) 10%,var(--cv-card));color:var(--cv-primary);font-size:11px;line-height:1}.cv-status:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.cv-status.archived,.cv-status.inactive{color:var(--cv-muted);background:color-mix(in srgb,var(--cv-muted) 10%,var(--cv-card))}.cv-actions{margin-left:auto;display:flex;gap:7px;align-items:center;position:relative}.cv-btn{height:36px;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--cv-border);border-radius:8px;background:var(--cv-card);color:var(--cv-text);font-family:inherit;font-size:12px;font-weight:600;line-height:1;cursor:pointer;text-decoration:none!important;white-space:nowrap}.cv-btn:hover{border-color:color-mix(in srgb,var(--cv-primary) 55%,var(--cv-border));color:var(--cv-primary);background:var(--cv-hover)}.cv-btn.icon{width:36px;padding:0}.cv-btn.primary{border-color:var(--cv-primary);background:var(--cv-primary);color:var(--cv-primary-text)!important}.cv-btn.primary:hover{filter:brightness(.95);color:var(--cv-primary-text)!important}.cv-btn.danger{color:#fff!important;background:var(--cv-danger);border-color:var(--cv-danger)}.cv-btn:disabled{opacity:.55;cursor:not-allowed}.cv-title-line{display:flex;align-items:center;gap:12px;margin-top:11px}.cv-title{margin:0;font-size:30px;line-height:1.12;color:var(--cv-text);font-weight:700;letter-spacing:-.55px}.cv-title-edit{margin-left:auto;width:34px;height:34px;display:grid;place-items:center;border-radius:7px;color:var(--cv-text);text-decoration:none!important}.cv-title-edit:hover{background:var(--cv-hover);color:var(--cv-primary)}.cv-company{margin-top:5px;color:var(--cv-muted);font-size:12px}.cv-summary{display:grid;grid-template-columns:1fr 1fr;gap:0 38px;margin-top:13px}.cv-summary-col{min-width:0}.cv-summary-row{min-height:43px;padding:8px 0;display:grid;grid-template-columns:165px minmax(0,1fr);gap:12px;align-items:center;border-bottom:1px solid var(--cv-border);font-size:12px}.cv-summary-row .label{color:var(--cv-muted)}.cv-summary-row .value{min-width:0;color:var(--cv-text);overflow-wrap:anywhere}.cv-summary-row a{color:var(--cv-primary)!important;text-decoration:underline!important}
.cv-menu{position:relative;z-index:60}.cv-dropdown{display:none;position:absolute;right:0;top:42px;z-index:50000;width:205px;padding:7px;border:1px solid var(--cv-border);border-radius:9px;background:var(--cv-card);box-shadow:0 16px 42px rgba(7,30,42,.15)}.cv-dropdown.show{display:block}.cv-dropdown button,.cv-dropdown a{width:100%;min-height:38px;padding:0 10px;border:0;border-radius:6px;background:transparent;color:var(--cv-text);display:flex;align-items:center;gap:10px;font-family:inherit;font-size:12px;font-weight:500;text-decoration:none!important;text-align:left;cursor:pointer}.cv-dropdown button:hover,.cv-dropdown a:hover{background:var(--cv-hover);color:var(--cv-primary)}.cv-dropdown .danger{color:var(--cv-danger)}.cv-dropdown hr{border:0;border-top:1px solid var(--cv-border);margin:6px 0}
.cv-tabs{display:flex;gap:24px;margin-top:20px;padding:0 2px;border-bottom:1px solid var(--cv-border)}.cv-tab{height:43px;padding:0 7px;border:0;border-bottom:3px solid transparent;background:transparent;color:var(--cv-muted);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer}.cv-tab.active{color:var(--cv-text);border-bottom-color:var(--cv-primary)}.cv-pane{display:none}.cv-pane.active{display:block}.cv-stack{display:grid;gap:18px;margin-top:12px}.cv-card,.cv-side-card{border:1px solid var(--cv-border);border-radius:8px;background:var(--cv-card);box-shadow:var(--cv-shadow);overflow:hidden}.cv-side-card{position:relative;z-index:1}.cv-side-card[data-view-section="notes"]{overflow:visible;z-index:12}.cv-card-head{min-height:60px;padding:0 14px;display:flex;align-items:center;gap:10px}.cv-card-head.with-border{border-bottom:1px solid var(--cv-border)}.cv-card-head h3,.cv-side-card h3{margin:0;color:var(--cv-text);font-size:17px;font-weight:700}.cv-card-head .sub{margin-left:5px;color:var(--cv-muted);font-size:11px}.cv-plus{margin-left:auto;width:34px;height:34px;border:1px solid var(--cv-border);border-radius:7px;background:var(--cv-card);color:var(--cv-primary);font-size:20px;display:grid;place-items:center;cursor:pointer}.cv-plus:hover{background:var(--cv-hover)}.cv-empty{padding:18px;color:var(--cv-muted);font-size:12px}.cv-link{border:0;background:none;padding:0;color:var(--cv-primary);font:inherit;cursor:pointer;text-decoration:underline}
.cv-property{min-height:50px;padding:10px 14px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;border-top:1px solid var(--cv-border);background:var(--cv-card)}.cv-property:first-of-type{border-top:0}.cv-property:hover{background:var(--cv-hover)}.cv-property-main{min-width:0}.cv-property-address{display:inline;color:var(--cv-text);font-size:12px;font-weight:700;text-decoration:underline;text-decoration-color:color-mix(in srgb,var(--cv-text) 45%,transparent);text-underline-offset:2px}.cv-property-name{margin-top:3px;color:var(--cv-muted);font-size:10.5px}.cv-mini-actions{display:flex;align-items:center;gap:6px}.cv-mini{width:34px;height:34px;border:1px solid transparent;border-radius:7px;background:var(--cv-card);display:grid;place-items:center;color:var(--cv-text);cursor:pointer;text-decoration:none!important}.cv-mini:hover{border-color:var(--cv-border);background:var(--cv-hover);color:var(--cv-primary)}
.cv-contact-strip{min-height:50px;padding:9px 14px;display:flex;align-items:center;gap:12px}.cv-contact-strip .icon{width:28px;height:28px;display:grid;place-items:center;border-right:1px solid var(--cv-border);padding-right:12px;box-sizing:content-box}.cv-contact-copy{min-width:0;flex:1;font-size:12px;color:var(--cv-muted)}.cv-contact-copy strong{color:var(--cv-text)}.cv-contact-names{margin-left:5px;color:var(--cv-muted)}.cv-contact-action{color:var(--cv-primary)!important;text-decoration:underline!important;font-size:12px;font-weight:600}
.cv-filter-row{padding:0 14px 12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.cv-chip{height:33px;padding:0 11px;border:1px solid var(--cv-border);border-radius:999px;background:var(--cv-card);color:var(--cv-text);display:inline-flex;align-items:center;gap:6px;font-family:inherit;font-size:11px;font-weight:500;cursor:pointer;text-decoration:none!important}.cv-chip:hover{background:var(--cv-hover)}.cv-chip.active{background:color-mix(in srgb,var(--cv-muted) 18%,var(--cv-card));border-color:transparent}.cv-chip .count{color:var(--cv-muted)}.cv-table-wrap{overflow:auto}.cv-table{width:100%;border-collapse:collapse;min-width:660px}.cv-table th{height:42px;padding:0 14px;border-bottom:1px solid var(--cv-border);text-align:left;color:var(--cv-text);font-size:11px;font-weight:700}.cv-table td{padding:10px 14px;border-bottom:1px solid var(--cv-border);vertical-align:middle;color:var(--cv-text);font-size:11.5px}.cv-table tbody tr:last-child td{border-bottom:0}.cv-table tbody tr:hover{background:var(--cv-hover)}.cv-table th:last-child,.cv-table td:last-child{text-align:right}.cv-item{display:flex;align-items:flex-start;gap:9px;min-width:0}.cv-item-icon{width:18px;flex:0 0 18px;margin-top:1px;color:var(--cv-info);font-size:15px}.cv-item-icon.request{color:#cc7500}.cv-item-icon.job{color:#4f9b25}.cv-item-icon.quote{color:#a14c61}.cv-item-icon.invoice{color:#3975a6}.cv-item-title{min-width:0}.cv-item-title a{color:var(--cv-text)!important;font-weight:700;text-decoration:none!important}.cv-item-title a:hover{color:var(--cv-primary)!important}.cv-item-title small{display:block;margin-top:2px;color:var(--cv-muted);font-size:10.5px;white-space:normal}.cv-date-caption{display:block;color:var(--cv-muted);font-size:10px}.cv-status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;font-size:10px;white-space:nowrap}.cv-status-pill:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}.cv-status-pill.success{color:#3b8a33;background:#e7f3e3}.cv-status-pill.danger{color:#d74d43;background:#fbeae8}.cv-status-pill.warning{color:#a68419;background:#f7f0cc}.cv-status-pill.info{color:#467589;background:#eaf2f5}.cv-status-pill.neutral{color:#617782;background:#edf2f4}.cv-money-main{font-weight:600}.cv-money-sub{display:block;color:var(--cv-muted);font-size:10px;margin-top:2px}
.cv-balance-row{min-height:42px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--cv-border);font-size:11.5px;font-weight:700}.cv-schedule-filters{padding:0 14px 12px;display:flex;gap:10px;flex-wrap:wrap}.cv-filter-select{height:34px;padding:0 30px 0 11px;border:0;border-radius:999px;background:color-mix(in srgb,var(--cv-muted) 18%,var(--cv-card));color:var(--cv-text);font-family:inherit;font-size:11px;font-weight:500;outline:0}.cv-assignee{display:inline-flex;align-items:center;gap:7px}.cv-assignee-avatar{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;background:var(--cv-text);color:var(--cv-card);font-size:8px;text-transform:uppercase}.cv-schedule-type{display:inline-flex;align-items:center;gap:7px;font-weight:700}.cv-schedule-type i.request{color:#cc7500}.cv-schedule-type i.visit{color:#67aa1d}.cv-schedule-type i.task{color:#417b97}.cv-row-muted{background:color-mix(in srgb,var(--cv-muted) 6%,var(--cv-card))}
.cv-pricing-name{font-weight:700}.cv-price-cell strong{display:block}.cv-price-cell span{display:block;color:var(--cv-muted);font-size:10px;margin-top:2px}
.cv-details{padding:4px 14px 14px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:28px}.cv-detail{min-height:39px;padding:8px 0;border-bottom:1px solid var(--cv-border);display:grid;grid-template-columns:145px minmax(0,1fr);gap:8px;font-size:11.5px}.cv-detail .k{color:var(--cv-muted)}.cv-detail .v{overflow-wrap:anywhere}.cv-toggle-list{padding:8px 0}.cv-toggle{display:flex;justify-content:space-between;gap:12px;padding:7px 0}.cv-toggle-state{padding:3px 7px;border-radius:999px;font-size:9px;background:#e7f3e3;color:#3b8a33}.cv-toggle-state.off{background:#edf2f4;color:var(--cv-muted)}
.cv-communication-list{border-top:1px solid var(--cv-border)}.cv-comm{padding:13px 14px;border-bottom:1px solid var(--cv-border);cursor:pointer}.cv-comm:hover{background:var(--cv-hover)}.cv-comm:last-child{border-bottom:0}.cv-comm-top{display:flex;align-items:center;gap:7px;font-size:11.5px}.cv-comm-top strong{font-weight:600}.cv-comm-date{margin-left:auto;color:var(--cv-muted);font-size:10px}.cv-comm-line{margin-top:6px;color:var(--cv-muted);font-size:11.5px;line-height:1.4}.cv-comm-line strong{color:var(--cv-text)}.cv-sent{display:inline-block;margin-top:7px;padding:2px 7px;border-radius:999px;background:#edf2f4;color:var(--cv-muted);font-size:9px}
.cv-side-card{padding:15px 14px;height:auto!important;min-height:max-content!important;max-height:none!important;overflow:visible!important;flex:none}.cv-side-card[data-view-section="overview"]{min-height:154px!important}.cv-side-card[data-view-section="tags"]{min-height:92px!important}.cv-side-card[data-view-section="lastCommunication"]{min-height:126px!important}.cv-side-head{display:flex;align-items:center;gap:8px}.cv-side-head h3{font-size:16px}.cv-side-head .cv-plus{margin-left:auto}.cv-side-stat{margin-top:10px}.cv-side-stat strong{display:block;color:var(--cv-text);font-size:21px;line-height:1.05}.cv-side-stat span{display:block;margin-top:3px;color:var(--cv-muted);font-size:10px}.cv-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.cv-tag{display:inline-flex;align-items:center;padding:5px 8px;border-radius:999px;background:color-mix(in srgb,var(--cv-muted) 10%,var(--cv-card));color:var(--cv-text);font-size:10px}.cv-tag-dot{width:6px;height:6px;border-radius:50%;background:var(--cv-muted);margin-right:5px}.cv-last{margin-top:8px;color:var(--cv-text);font-size:11px;line-height:1.42}.cv-last small{display:block;color:var(--cv-muted);font-size:9.5px;margin-bottom:5px}.cv-note{width:100%;min-height:126px;margin-top:10px;padding:10px;border:1px solid var(--cv-input-border);border-radius:7px;background:var(--cv-input);color:var(--cv-text);font-family:inherit;font-size:11px;line-height:1.45;resize:vertical;outline:0}.cv-note:focus{border-color:var(--cv-primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--cv-primary) 12%,transparent)}.cv-note-upload{margin-top:9px;padding:12px 8px;border:1px dashed var(--cv-border);border-radius:7px;text-align:center}.cv-note-upload button{height:30px}.cv-note-upload small{display:block;margin-top:5px;color:var(--cv-muted);font-size:9px}.cv-related{margin-top:9px}.cv-related summary{cursor:pointer;color:var(--cv-text);font-size:11px;font-weight:600;list-style:none;display:flex;justify-content:space-between}.cv-related summary:after{content:"⌄";color:var(--cv-primary)}.cv-related select{width:100%;height:35px;margin-top:8px;border:1px solid var(--cv-input-border);border-radius:7px;background:var(--cv-input);color:var(--cv-text);font-family:inherit;font-size:10.5px}.cv-note-actions{margin-top:10px;padding-top:10px;border-top:1px solid var(--cv-border);display:flex;justify-content:flex-end;gap:7px}.cv-note-files{margin-top:8px;display:grid;gap:4px}.cv-note-file{color:var(--cv-muted);font-size:9px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.cv-side-meta{margin-top:7px}.cv-side-meta-row{min-height:33px;display:grid;grid-template-columns:86px minmax(0,1fr);align-items:center;gap:8px;border-bottom:1px solid var(--cv-border);font-size:10px}.cv-side-meta-row:last-child{border-bottom:0}.cv-side-meta-row .k{color:var(--cv-muted)}
.cv-modal-backdrop{display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;padding:18px;background:rgba(5,18,25,.52);backdrop-filter:blur(2px)}.cv-modal-backdrop.show{display:flex}.cv-modal{width:min(560px,calc(100vw - 28px));max-height:calc(100vh - 36px);overflow:auto;border:1px solid var(--cv-border);border-radius:9px;background:var(--cv-card);color:var(--cv-text);box-shadow:0 24px 70px rgba(4,20,29,.28)}.cv-modal.wide{width:min(870px,calc(100vw - 28px))}.cv-modal.task{width:min(780px,calc(100vw - 28px))}.cv-modal-head{padding:19px 20px 12px;display:flex;align-items:center;gap:12px}.cv-modal-head h3{margin:0;font-size:21px}.cv-modal-close{margin-left:auto;width:34px;height:34px;border:0;border-radius:7px;background:transparent;color:var(--cv-text);font-size:20px;cursor:pointer}.cv-modal-close:hover{background:var(--cv-hover)}.cv-modal-body{padding:8px 20px 18px}.cv-modal-footer{padding:13px 20px 18px;display:flex;justify-content:flex-end;gap:8px}.cv-field{margin-bottom:12px}.cv-field label{display:block;margin-bottom:5px;color:var(--cv-text);font-size:11px;font-weight:600}.cv-input,.cv-textarea,.cv-select{width:100%;border:1px solid var(--cv-input-border);border-radius:7px;background:var(--cv-input);color:var(--cv-text);font-family:inherit;font-size:12px;outline:0}.cv-input,.cv-select{height:42px;padding:0 11px}.cv-textarea{min-height:180px;padding:11px;resize:vertical}.cv-input:focus,.cv-textarea:focus,.cv-select:focus{border-color:var(--cv-primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--cv-primary) 12%,transparent)}.cv-email-grid{display:grid;grid-template-columns:minmax(0,1fr) 265px;gap:20px}.cv-attachment-box{min-height:78px;padding:16px;border:1px dashed var(--cv-border);border-radius:7px;display:grid;place-items:center;text-align:center}.cv-attachment-box small{display:block;color:var(--cv-muted);font-size:9px;margin-top:6px}.cv-attachment-meta{margin-top:9px;color:var(--cv-muted);font-size:9px}.cv-progress{height:6px;margin-top:5px;border-radius:999px;background:color-mix(in srgb,var(--cv-muted) 15%,var(--cv-card));overflow:hidden}.cv-progress span{height:100%;width:0;display:block;background:var(--cv-primary)}.cv-check{display:flex;align-items:center;gap:7px;color:var(--cv-muted);font-size:11px}.cv-check input{width:16px;height:16px;accent-color:var(--cv-primary)}
.cv-task-client{padding:13px 14px;margin-bottom:12px;border:1px solid var(--cv-border);border-radius:8px}.cv-task-client-top{display:flex;align-items:center;justify-content:space-between;font-size:12px;font-weight:700}.cv-client-dot{width:6px;height:6px;display:inline-block;margin-left:4px;border-radius:50%;background:#338ed0}.cv-task-contact{margin-top:10px;color:var(--cv-primary);font-size:11px}.cv-task-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.cv-dates{display:grid;grid-template-columns:1fr 1fr}.cv-dates .cv-input{border-radius:0}.cv-dates .cv-input:first-child{border-radius:7px 0 0 7px}.cv-dates .cv-input:last-child{border-radius:0 7px 7px 0;border-left:0}.cv-task-flags{display:flex;gap:18px;margin-top:9px}.cv-task-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}
.cv-tag-selected{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:11px}.cv-tag-options{border:1px solid var(--cv-border);border-radius:7px;max-height:230px;overflow:auto}.cv-tag-option{min-height:40px;padding:0 11px;display:flex;align-items:center;gap:9px;border-bottom:1px solid var(--cv-border);font-size:11px;cursor:pointer}.cv-tag-option:last-child{border-bottom:0}.cv-tag-option input{accent-color:var(--cv-primary)}.cv-create-tag{display:flex;gap:7px;margin-top:10px}.cv-create-tag .cv-input{flex:1}.cv-comm-detail-meta{display:grid;grid-template-columns:1fr 1fr;gap:0 16px;margin-bottom:16px}.cv-comm-detail-meta div{padding:8px 0;border-bottom:1px solid var(--cv-border);font-size:11px}.cv-comm-detail-meta strong{margin-right:5px}.cv-comm-body{white-space:pre-wrap;color:var(--cv-text);font-size:11.5px;line-height:1.55}.cv-customize-help{margin:0 0 10px;color:var(--cv-muted);font-size:11.5px}.cv-customize-list{display:grid;gap:8px}.cv-customize-row{min-height:58px;padding:0 12px;border:1px solid var(--cv-border);border-radius:8px;display:flex;align-items:center;gap:10px;background:var(--cv-card);font-size:12px}.cv-customize-row .cv-customize-icon{width:24px;height:24px;display:grid;place-items:center;color:var(--cv-text);font-size:16px}.cv-customize-row .cv-customize-name{flex:1}.cv-customize-arrows{display:flex;gap:5px}.cv-order-btn{width:37px;height:37px;border:1px solid var(--cv-border);border-radius:7px;background:var(--cv-card);color:var(--cv-primary);display:grid;place-items:center;cursor:pointer}.cv-order-btn:hover{background:var(--cv-hover)}.cv-order-btn:disabled{background:color-mix(in srgb,var(--cv-muted) 8%,var(--cv-card));color:color-mix(in srgb,var(--cv-muted) 55%,transparent);cursor:not-allowed}.cv-customize-footer{padding-top:12px;display:flex;justify-content:flex-end;gap:8px}.cv-confirm-copy{color:var(--cv-muted);font-size:12px;line-height:1.55}.cv-confirm-warning{margin-top:12px;padding:10px;border-radius:7px;background:color-mix(in srgb,#d5a51e 12%,var(--cv-card));font-size:11px}

.cv-related-options{margin-top:10px;display:flex;flex-wrap:wrap;gap:9px 12px}.cv-related-check{display:inline-flex;align-items:center;gap:6px;color:var(--cv-text);font-size:11px;cursor:pointer}.cv-related-check input{width:16px;height:16px;accent-color:var(--cv-primary)}.cv-schedule-action{width:34px;height:34px;border:1px solid transparent;border-radius:7px;background:transparent;color:var(--cv-text);display:inline-grid;place-items:center;font-size:17px;cursor:pointer}.cv-schedule-action:hover{border-color:var(--cv-border);background:var(--cv-card);color:var(--cv-primary)}.cv-schedule-popover{display:none;position:fixed;z-index:100600;min-width:180px;padding:7px;border:1px solid var(--cv-border);border-radius:8px;background:var(--cv-card);box-shadow:0 12px 30px rgba(6,28,39,.18)}.cv-schedule-popover.show{display:block}.cv-schedule-popover button{width:100%;height:40px;padding:0 11px;border:0;border-radius:6px;background:transparent;color:var(--cv-text);display:flex;align-items:center;gap:9px;font:inherit;font-size:11.5px;font-weight:600;cursor:pointer}.cv-schedule-popover button:hover{background:var(--cv-hover);color:var(--cv-primary)}.cv-note-empty{width:100%;min-height:190px;margin-top:12px;border:1px dashed var(--cv-border);border-radius:8px;background:color-mix(in srgb,var(--cv-muted) 5%,var(--cv-card));color:var(--cv-text);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:13px;cursor:pointer;text-align:center;padding:18px}.cv-note-empty:hover{border-color:color-mix(in srgb,var(--cv-primary) 60%,var(--cv-border));background:var(--cv-hover)}.cv-note-empty .plus{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:var(--cv-primary);color:var(--cv-primary-text);font-size:26px}.cv-note-empty span:last-child{max-width:190px;font-size:12px;line-height:1.5}.cv-note-preview{margin-top:10px;padding:10px;border:1px solid var(--cv-border);border-radius:7px;color:var(--cv-text);font-size:11px;line-height:1.5;white-space:pre-wrap}.cv-note-preview-actions{display:flex;justify-content:flex-end;margin-top:8px}.cv-note-editor[hidden]{display:none!important}.cv-note-wrap{position:relative}.cv-mention-box{display:none;position:fixed;z-index:100500;width:300px;max-width:calc(100vw - 24px);max-height:190px;overflow:auto;border:1px solid var(--cv-border);border-radius:8px;background:var(--cv-card);box-shadow:0 12px 28px rgba(7,30,42,.15)}.cv-mention-box.show{display:block}.cv-mention-item{width:100%;min-height:38px;padding:7px 10px;border:0;border-bottom:1px solid var(--cv-border);background:transparent;color:var(--cv-text);display:flex;flex-direction:column;align-items:flex-start;text-align:left;cursor:pointer}.cv-mention-item:last-child{border-bottom:0}.cv-mention-item:hover{background:var(--cv-hover)}.cv-mention-item small{color:var(--cv-muted);font-size:9px}.cv-switch-row{min-height:44px;display:flex;align-items:center;justify-content:space-between;gap:14px;border-bottom:1px solid var(--cv-border);font-size:11.5px}.cv-switch-row:last-child{border-bottom:0}.cv-switch{position:relative;width:44px;height:24px;flex:0 0 44px}.cv-switch input{position:absolute;opacity:0;pointer-events:none}.cv-switch span{position:absolute;inset:0;border-radius:999px;background:color-mix(in srgb,var(--cv-muted) 30%,var(--cv-card));transition:.18s}.cv-switch span:after{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:.18s}.cv-switch input:checked+span{background:var(--cv-primary)}.cv-switch input:checked+span:after{transform:translateX(20px)}.cv-login-copy{color:var(--cv-muted);font-size:13px;line-height:1.55}.cv-login-copy strong{color:var(--cv-text)}

html.app-dark-mode .cv-status-pill.success{background:color-mix(in srgb,#3b8a33 22%,var(--cv-card))}html.app-dark-mode .cv-status-pill.danger{background:color-mix(in srgb,#d74d43 20%,var(--cv-card))}html.app-dark-mode .cv-status-pill.warning{background:color-mix(in srgb,#a68419 22%,var(--cv-card))}html.app-dark-mode .cv-status-pill.info{background:color-mix(in srgb,#467589 24%,var(--cv-card))}
@media(max-width:1180px){.cv-layout{grid-template-columns:minmax(0,1fr) 250px;gap:18px}.cv-summary{gap:0 22px}.cv-summary-row{grid-template-columns:135px minmax(0,1fr)}}
@media(max-width:980px){.cv-layout,.cv-layout.aside-collapsed{grid-template-columns:1fr}.cv-aside,.cv-layout.aside-collapsed .cv-aside{position:static!important;top:auto!important;width:auto!important;height:auto!important;max-height:none!important;overflow:visible!important;padding-right:0!important;scrollbar-gutter:auto}.cv-aside-toolbar{display:none}.cv-aside-body,.cv-layout.aside-collapsed .cv-aside-body{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));overflow:visible;padding-right:0}.cv-summary{grid-template-columns:1fr}.cv-summary-row{grid-template-columns:150px minmax(0,1fr)}}
@media(max-width:760px){.cv-page{padding:14px 12px 28px}.cv-topline{flex-wrap:wrap}.cv-actions{width:100%;margin-left:0;justify-content:flex-end}.cv-title{font-size:26px}.cv-summary-row{grid-template-columns:115px minmax(0,1fr)}.cv-aside-body,.cv-layout.aside-collapsed .cv-aside-body{grid-template-columns:1fr}.cv-details{grid-template-columns:1fr}.cv-detail{grid-template-columns:120px minmax(0,1fr)}.cv-email-grid,.cv-task-grid{grid-template-columns:1fr}.cv-attachment-box{min-height:95px}.cv-modal-head{padding:16px 15px 10px}.cv-modal-body{padding:8px 15px 14px}.cv-modal-footer{padding:12px 15px 16px}.cv-contact-names{display:none}}
@media(max-width:520px){.cv-btn .hide-xs{display:none}.cv-btn.primary{padding:0 11px}.cv-summary-row{grid-template-columns:100px minmax(0,1fr)}.cv-title-edit{margin-left:0}.cv-tabs{gap:10px}.cv-tab{font-size:12px}.cv-task-flags{flex-direction:column;gap:8px}.cv-dates{grid-template-columns:1fr}.cv-dates .cv-input,.cv-dates .cv-input:first-child,.cv-dates .cv-input:last-child{border-radius:7px;border-left:1px solid var(--cv-input-border)}.cv-dates .cv-input+ .cv-input{margin-top:7px}}

/* ==========================================================
   Request View adaptations
   VISUAL SOURCE: client-view.php only
   ========================================================== */
:root{
  --rv-primary:var(--cv-primary);--rv-text:var(--cv-text);--rv-muted:var(--cv-muted);
  --rv-bg:var(--cv-bg);--rv-card:var(--cv-card);--rv-border:var(--cv-border);
  --rv-line:var(--cv-border);--rv-line-soft:var(--cv-border);--rv-danger:var(--cv-danger);
  --rv-input:var(--cv-input);--rv-hover:var(--cv-hover);--rv-shadow:var(--cv-shadow);
}
.rv-page{width:100%;color:var(--cv-text);font-family:inherit}.rv-layout{display:contents}.rv-main{min-width:0}.rv-main-inner{min-width:0}.rv-side{min-width:0}.rv-side-inner{display:grid;gap:13px}.rv-side-title{display:none}
.rv-loading{min-height:320px;display:grid;place-items:center;color:var(--cv-muted);font-size:12px}.rv-spinner{width:30px;height:30px;border:3px solid var(--cv-border);border-top-color:var(--cv-primary);border-radius:50%;animation:rvSpin .75s linear infinite}@keyframes rvSpin{to{transform:rotate(360deg)}}

/* Request profile header: same proportions and spacing as Client View */
.rv-top{display:flex;align-items:center;gap:8px;min-height:38px;padding:2px 4px 0}.rv-request-icon{font-size:17px;color:var(--cv-text)}.rv-status{display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:999px;background:color-mix(in srgb,var(--cv-primary) 10%,var(--cv-card));color:var(--cv-primary);font-size:11px;line-height:1}.rv-status:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.rv-request-no{color:var(--cv-muted);font-size:11px}.rv-top-spacer{margin-left:auto}.rv-title-row{display:flex;align-items:center;gap:12px;margin-top:11px;padding:0 4px}.rv-title{margin:0;font-size:30px;line-height:1.12;color:var(--cv-text);font-weight:700;letter-spacing:-.55px}.rv-title-edit,.rv-edit,.rv-icon-btn{width:34px;height:34px;display:grid;place-items:center;border:1px solid transparent;border-radius:7px;background:transparent;color:var(--cv-text);cursor:pointer}.rv-title-edit{margin-left:auto}.rv-title-edit:hover,.rv-edit:hover,.rv-icon-btn:hover{border-color:var(--cv-border);background:var(--cv-hover);color:var(--cv-primary)}
.rv-action-btn,.rv-small-btn,.rv-link-btn,.rv-checklist-menu-btn,.rv-new-checklist-btn,.rv-add-note-btn{min-height:36px;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--cv-border);border-radius:8px;background:var(--cv-card);color:var(--cv-text);font:inherit;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none!important}.rv-action-btn:hover,.rv-small-btn:hover,.rv-link-btn:hover,.rv-checklist-menu-btn:hover,.rv-new-checklist-btn:hover,.rv-add-note-btn:hover{border-color:color-mix(in srgb,var(--cv-primary) 55%,var(--cv-border));background:var(--cv-hover);color:var(--cv-primary)}.rv-action-btn.primary,.rv-small-btn.primary{border-color:var(--cv-primary);background:var(--cv-primary);color:var(--cv-primary-text)!important}.rv-action-btn.primary:hover,.rv-small-btn.primary:hover{filter:brightness(.95)}.rv-small-btn:disabled,.rv-action-btn:disabled{opacity:.55;cursor:not-allowed}
.rv-more-wrap{position:relative;z-index:60}.rv-more-menu{display:none;position:absolute;right:0;top:42px;z-index:50000;width:220px;padding:7px;border:1px solid var(--cv-border);border-radius:9px;background:var(--cv-card);box-shadow:0 16px 42px rgba(7,30,42,.15)}.rv-more-menu.show{display:block}.rv-more-item{width:100%;min-height:38px;padding:0 10px;border:0;border-radius:6px;background:transparent;color:var(--cv-text);display:flex;align-items:center;gap:10px;font:inherit;font-size:12px;font-weight:500;text-decoration:none!important;text-align:left;cursor:pointer}.rv-more-item:hover{background:var(--cv-hover);color:var(--cv-primary)}.rv-more-item.danger{color:var(--cv-danger)}.rv-more-sep{border-top:1px solid var(--cv-border);margin:6px 0}
.rv-header-info{display:grid;grid-template-columns:minmax(0,1fr) minmax(230px,.55fr);gap:0 38px;margin:13px 4px 24px;padding-bottom:24px;border-bottom:1px solid var(--cv-border)}.rv-customer-card,.rv-requested{min-height:43px;padding:8px 0;border-bottom:1px solid var(--cv-border)}.rv-customer-card{position:relative}.rv-customer-more{position:absolute;right:0;top:5px;width:34px;height:34px;border:0;border-radius:7px;background:transparent;color:var(--cv-text);cursor:pointer}.rv-customer-more:hover{background:var(--cv-hover);color:var(--cv-primary)}.rv-customer-name{padding-right:42px;color:var(--cv-text);font-size:12px;font-weight:700}.rv-customer-dot{width:6px;height:6px;margin-left:5px;display:inline-block;border-radius:50%;background:var(--cv-primary)}.rv-address{margin-top:4px;color:var(--cv-muted);font-size:11px;white-space:pre-line}.rv-customer-links{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px}.rv-customer-links a{color:var(--cv-primary)!important;text-decoration:underline!important;font-size:11px}.rv-requested{display:grid;grid-template-columns:120px 1fr;align-items:center;gap:12px;font-size:12px}.rv-requested span{color:var(--cv-muted)}.rv-requested strong{color:var(--cv-text)}

/* Cards: exact Client View language */
.rv-section{margin-top:18px}.rv-card,.rv-assessment-card,.rv-lines-card,.rv-notes-card{border:1px solid var(--cv-border);border-radius:8px;background:var(--cv-card);box-shadow:var(--cv-shadow);overflow:visible}.rv-card-inner,.rv-assessment-editor,.rv-assessment-display{padding:0 14px 16px}.rv-section-head,.rv-lines-head{min-height:60px;display:flex;align-items:center;gap:10px}.rv-section-head h2,.rv-lines-head h2,.rv-assessment-title{margin:0;color:var(--cv-text);font-size:17px;font-weight:700}.rv-edit{margin-left:auto}.rv-info-block{padding:12px 0;border-top:1px solid var(--cv-border)}.rv-info-block:first-of-type{border-top:0}.rv-subtitle{color:var(--cv-text);font-size:11px;font-weight:700}.rv-helper{margin-top:3px;color:var(--cv-muted);font-size:10px}.rv-copy{margin-top:8px;color:var(--cv-text);font-size:11.5px;line-height:1.55;white-space:pre-wrap}.rv-placeholder{margin-top:8px;color:var(--cv-muted)}
.rv-work-images{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}.rv-work-image{width:78px;height:62px;border:1px solid var(--cv-border);border-radius:7px;overflow:hidden;background:var(--cv-bg)}.rv-work-image img{width:100%;height:100%;object-fit:cover}

/* Inputs/editors copied to Client View proportions */
.rv-form-field{margin-top:12px}.rv-form-field>label{display:block;margin-bottom:6px;color:var(--cv-muted);font-size:10px;font-weight:700}.rv-input,.rv-select,.rv-textarea{width:100%;border:1px solid var(--cv-input-border);border-radius:7px;background:var(--cv-input);color:var(--cv-text);font:inherit;font-size:11.5px;outline:none}.rv-input,.rv-select{height:40px;padding:0 10px}.rv-textarea{min-height:92px;padding:10px 11px;resize:vertical;line-height:1.5}.rv-input:focus,.rv-select:focus,.rv-textarea:focus{border-color:color-mix(in srgb,var(--cv-primary) 62%,var(--cv-input-border));box-shadow:0 0 0 3px color-mix(in srgb,var(--cv-primary) 10%,transparent)}.rv-editor-actions,.rv-line-editor-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.rv-date-pair,.rv-time-pair{display:grid;grid-template-columns:1fr 1fr;gap:7px}.rv-check-row,.rv-check-required{display:flex;align-items:center;gap:7px;margin-top:9px;color:var(--cv-muted);font-size:11px}.rv-check-row input,.rv-check-required input{accent-color:var(--cv-primary)}

/* Assessment */
.rv-assessment-title{padding:0 2px 9px}.rv-assessment-empty{min-height:145px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;border:1px dashed var(--cv-border);border-radius:8px;background:color-mix(in srgb,var(--cv-muted) 4%,var(--cv-card));color:var(--cv-text);font-size:12px;cursor:pointer}.rv-assessment-empty:hover{border-color:color-mix(in srgb,var(--cv-primary) 60%,var(--cv-border));background:var(--cv-hover)}.rv-plus{width:50px;height:50px;display:grid;place-items:center;border-radius:50%;background:var(--cv-primary);color:var(--cv-primary-text);font-size:24px}.rv-assessment-grid{display:grid;grid-template-columns:1.05fr 1fr 1fr;gap:18px;padding-top:3px}.rv-assessment-col{min-width:0}.rv-assessment-col h3{margin:8px 0 10px;color:var(--cv-text);font-size:12px}.rv-assessment-display{padding-top:0}.rv-checklist-toolbar{position:relative;display:flex;gap:7px;flex-wrap:wrap}.rv-checklist-picker{display:none;position:absolute;z-index:80;top:42px;left:0;width:310px;max-width:calc(100vw - 36px);border:1px solid var(--cv-border);border-radius:8px;background:var(--cv-card);box-shadow:0 14px 35px rgba(7,30,42,.15);overflow:hidden}.rv-checklist-picker.show{display:block}.rv-checklist-search{padding:9px;border-bottom:1px solid var(--cv-border)}.rv-checklist-search input{width:100%;height:37px;padding:0 9px;border:1px solid var(--cv-input-border);border-radius:7px;background:var(--cv-input);color:var(--cv-text)}.rv-checklist-picker-head{padding:8px 10px;display:flex;justify-content:space-between;color:var(--cv-muted);font-size:10px}.rv-checklist-select-all{border:0;background:none;color:var(--cv-primary);font:inherit;cursor:pointer}.rv-checklist-option-list{max-height:220px;overflow:auto}.rv-checklist-option{min-height:44px;padding:8px 10px;display:flex;align-items:center;gap:9px;border-top:1px solid var(--cv-border);cursor:pointer}.rv-checklist-option input{accent-color:var(--cv-primary)}.rv-checklist-option-copy strong,.rv-checklist-option-copy small{display:block}.rv-checklist-option-copy strong{font-size:11px}.rv-checklist-option-copy small{margin-top:2px;color:var(--cv-muted);font-size:9px}.rv-checklist-items{display:flex;gap:6px;flex-wrap:wrap}.rv-checklist-chip{padding:5px 8px;border-radius:999px;background:color-mix(in srgb,var(--cv-primary) 10%,var(--cv-card));color:var(--cv-primary);font-size:10px}.rv-checklist-box{display:flex;gap:10px;padding:12px;border:1px dashed var(--cv-border);border-radius:8px}.rv-checklist-icon{width:36px;height:36px;display:grid;place-items:center;border-radius:8px;background:var(--cv-hover);color:var(--cv-primary)}.rv-checklist-copy{min-width:0;flex:1}.rv-checklist-text strong{font-size:10px}.rv-checklist-text p{margin:4px 0 8px;color:var(--cv-muted);font-size:9.5px;line-height:1.4}

/* Line items table/card */
.rv-lines-card{overflow:visible}.rv-lines-head{padding:0 14px;border-bottom:1px solid var(--cv-border)}.rv-line-list{padding:0}.rv-line{position:relative;display:grid;grid-template-columns:minmax(220px,1fr) 90px 130px 130px 34px;gap:8px;align-items:start;padding:10px 14px;border-bottom:1px solid var(--cv-border)}.rv-line:last-child{border-bottom:0}.rv-line-item{min-width:0}.rv-line-desc{grid-column:1/2;min-height:36px}.rv-line-image{font-size:10px;color:var(--cv-muted)}.rv-remove-line{width:34px;height:34px;border:0;border-radius:7px;background:transparent;color:var(--cv-danger);cursor:pointer}.rv-remove-line:hover{background:color-mix(in srgb,var(--cv-danger) 8%,var(--cv-card))}.rv-price-cell{position:relative}.rv-markup-pop{display:none;position:absolute;z-index:90;right:0;top:44px;width:250px;padding:11px;border:1px solid var(--cv-border);border-radius:8px;background:var(--cv-card);box-shadow:0 14px 35px rgba(7,30,42,.15)}.rv-markup-pop.show{display:block}.rv-markup-field{margin-bottom:8px}.rv-markup-field label{display:block;margin-bottom:5px;color:var(--cv-muted);font-size:9px}.rv-markup-input{width:100%;height:36px;border:1px solid var(--cv-input-border);border-radius:7px;background:var(--cv-input);color:var(--cv-text);padding:0 9px}.rv-markup-note{color:var(--cv-muted);font-size:9px;line-height:1.4}.rv-totals{padding:10px 14px;display:flex;justify-content:flex-end}.rv-total-box{width:min(310px,100%)}.rv-total-row{display:flex;justify-content:space-between;padding:6px 0;color:var(--cv-muted);font-size:11px}.rv-total-row.grand{padding-top:9px;border-top:1px solid var(--cv-border);color:var(--cv-text);font-size:13px;font-weight:700}.rv-lines-display{overflow:auto}.rv-display-line{min-width:650px;display:grid;grid-template-columns:minmax(250px,1fr) 90px 120px 130px;gap:8px;padding:11px 14px;border-bottom:1px solid var(--cv-border);font-size:11.5px}.rv-display-line:last-child{border-bottom:0}.rv-display-line-name{font-weight:700}.rv-display-line-name small{display:block;margin-top:3px;color:var(--cv-muted);font-size:9.5px;font-weight:400}.rv-display-right{text-align:right}.rv-money{font-weight:700}.rv-badge{display:inline-flex;padding:3px 7px;border-radius:999px;background:var(--cv-hover);color:var(--cv-primary);font-size:9px}

/* Right panel notes: same Client View side card */
.rv-notes-card{padding:14px}.rv-note-empty{width:100%;min-height:190px;border:1px dashed var(--cv-border);border-radius:8px;background:color-mix(in srgb,var(--cv-muted) 5%,var(--cv-card));color:var(--cv-text);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:13px;cursor:pointer;text-align:center;padding:18px}.rv-note-empty:hover{border-color:color-mix(in srgb,var(--cv-primary) 60%,var(--cv-border));background:var(--cv-hover)}.rv-note-empty-icon{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:var(--cv-primary);color:var(--cv-primary-text);font-size:24px}.rv-note-list{display:grid;gap:8px}.rv-note-item{padding:10px;border:1px solid var(--cv-border);border-radius:7px;background:var(--cv-card)}.rv-note-item-top{display:flex;gap:8px;align-items:center}.rv-note-item-top strong{font-size:10.5px}.rv-note-item-top time{margin-left:auto;color:var(--cv-muted);font-size:8.5px}.rv-note-item p{margin:7px 0 0;color:var(--cv-text);font-size:10.5px;line-height:1.5;white-space:pre-wrap}.rv-add-note-btn{width:100%;margin-top:9px}.rv-note-editor{position:relative}.rv-mention-menu{display:none;position:absolute;z-index:120;left:0;right:0;top:96px;border:1px solid var(--cv-border);border-radius:7px;background:var(--cv-card);box-shadow:0 12px 28px rgba(7,30,42,.15);overflow:hidden}.rv-mention-menu.show{display:block}.rv-mention-option{width:100%;min-height:40px;padding:7px 9px;display:flex;align-items:center;gap:8px;border:0;border-bottom:1px solid var(--cv-border);background:transparent;color:var(--cv-text);text-align:left;cursor:pointer}.rv-mention-option:hover{background:var(--cv-hover)}.rv-avatar{width:28px;height:28px;display:grid;place-items:center;border-radius:50%;background:var(--cv-hover);color:var(--cv-primary);font-size:9px;font-weight:700}.rv-mention-option small{display:block;color:var(--cv-muted);font-size:8px}.rv-note-drop{min-height:86px;margin-top:9px;padding:12px;border:1px dashed var(--cv-border);border-radius:7px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;color:var(--cv-muted);font-size:9.5px}.rv-note-drop button{border:0;background:none;color:var(--cv-primary);font:inherit;font-weight:700;cursor:pointer}.rv-note-file-list{display:grid;gap:5px;margin-top:8px}.rv-note-file{padding:7px 8px;border:1px solid var(--cv-border);border-radius:6px;font-size:9px}

/* History drawer */
.rv-drawer-backdrop{position:fixed;inset:0;z-index:100400;background:rgba(7,30,42,.32);opacity:0;visibility:hidden;transition:.18s}.rv-drawer-backdrop.show{opacity:1;visibility:visible}.rv-history{position:fixed;z-index:100500;top:0;right:0;width:min(470px,100vw);height:100dvh;background:var(--cv-card);border-left:1px solid var(--cv-border);box-shadow:-18px 0 45px rgba(7,30,42,.13);transform:translateX(100%);transition:.22s;overflow:auto}.rv-history.show{transform:translateX(0)}.rv-history-head{height:64px;padding:0 16px;display:flex;align-items:center;border-bottom:1px solid var(--cv-border)}.rv-history-head h2{margin:0;font-size:17px}.rv-history-close{margin-left:auto;width:34px;height:34px;border:0;border-radius:7px;background:transparent;color:var(--cv-text);cursor:pointer}.rv-history-close:hover{background:var(--cv-hover)}.rv-history-filters{padding:10px 12px;display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--cv-border)}.rv-history-filter{height:34px;padding:0 8px;border:1px solid var(--cv-input-border);border-radius:7px;background:var(--cv-input);color:var(--cv-text);font-size:10px}.rv-history-list{padding:0 14px}.rv-history-item{display:grid;grid-template-columns:34px minmax(0,1fr);gap:9px;padding:12px 0;border-bottom:1px solid var(--cv-border)}.rv-history-avatar{width:32px;height:32px;display:grid;place-items:center;border-radius:50%;background:var(--cv-hover);color:var(--cv-primary);font-size:9px;font-weight:700}.rv-history-item strong,.rv-history-item small{display:block}.rv-history-item strong{font-size:10.5px}.rv-history-item small{margin-top:2px;color:var(--cv-muted);font-size:8.5px}.rv-history-detail{margin-top:6px;color:var(--cv-muted);font-size:10px;line-height:1.5}.rv-history-empty{padding:22px 8px;color:var(--cv-muted);font-size:11px;text-align:center}

/* Modals */
.rv-modal-bg{position:fixed;inset:0;z-index:100700;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(7,30,42,.42);backdrop-filter:blur(2px)}.rv-modal-bg.show{display:flex}.rv-modal{width:min(640px,100%);max-height:calc(100dvh - 36px);overflow:auto;border:1px solid var(--cv-border);border-radius:10px;background:var(--cv-card);box-shadow:0 24px 70px rgba(7,30,42,.22)}.rv-modal.wide{width:min(980px,100%)}.rv-modal.small{width:min(450px,100%)}.rv-modal-head{min-height:60px;padding:0 16px;display:flex;align-items:center;border-bottom:1px solid var(--cv-border)}.rv-modal-head h2{margin:0;font-size:17px}.rv-modal-close{margin-left:auto;width:34px;height:34px;border:0;border-radius:7px;background:transparent;color:var(--cv-text);cursor:pointer}.rv-modal-close:hover{background:var(--cv-hover)}.rv-modal-body{padding:16px}.rv-modal-foot{padding:12px 16px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--cv-border)}.rv-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.rv-modal-grid .full{grid-column:1/-1}.rv-cost-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.rv-email-note,.rv-email-sent-note,.rv-email-template-source{padding:9px 10px;border-radius:7px;background:var(--cv-hover);color:var(--cv-muted);font-size:10px;line-height:1.45}.rv-email-sent-note{margin-bottom:10px}.rv-booking-message{min-height:180px}

/* Checklist builder modal */
.rv-check-builder{display:grid;grid-template-columns:minmax(0,1fr) 250px}.rv-check-canvas{padding:14px}.rv-check-manage{padding:14px;border-left:1px solid var(--cv-border)}.rv-check-manage h3{margin:0 0 10px;font-size:12px}.rv-check-palette{display:grid;gap:6px}.rv-check-palette button{min-height:36px;padding:0 9px;border:1px solid var(--cv-border);border-radius:7px;background:var(--cv-card);color:var(--cv-text);font:inherit;font-size:10px;text-align:left;cursor:pointer}.rv-check-palette button:hover{background:var(--cv-hover);color:var(--cv-primary)}.rv-check-section{margin-bottom:10px;border:1px solid var(--cv-border);border-radius:8px;overflow:hidden}.rv-check-section-head{padding:9px;display:grid;grid-template-columns:1fr 34px;gap:7px;background:var(--cv-hover)}.rv-check-section-head input{height:36px;padding:0 9px;border:1px solid var(--cv-input-border);border-radius:7px;background:var(--cv-input);color:var(--cv-text)}.rv-check-question{padding:10px;border-top:1px solid var(--cv-border)}.rv-check-q-grid{display:grid;grid-template-columns:minmax(0,1fr) 160px 34px;gap:7px}.rv-check-options{display:grid;gap:6px;margin-top:8px;padding-left:16px}.rv-check-option-row{display:grid;grid-template-columns:1fr 34px;gap:7px}

/* Catalog Select2 follows Client View theme */
.select2-container{font-size:11.5px}.select2-container--default .select2-selection--single,.select2-container--default .select2-selection--multiple{min-height:40px;border:1px solid var(--cv-input-border)!important;border-radius:7px!important;background:var(--cv-input)!important}.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:38px;color:var(--cv-text)}.select2-container--default .select2-selection--single .select2-selection__arrow{height:38px}.select2-dropdown{border:1px solid var(--cv-border)!important;background:var(--cv-card)!important;color:var(--cv-text);box-shadow:0 12px 28px rgba(7,30,42,.12)}.select2-results__option--highlighted.select2-results__option--selectable{background:var(--cv-hover)!important;color:var(--cv-primary)!important}.rv-catalog-result{display:flex;gap:8px;align-items:center}.rv-catalog-main{min-width:0;flex:1}.rv-catalog-name{display:flex;align-items:center;gap:6px;font-weight:700}.rv-catalog-desc{margin-top:2px;color:var(--cv-muted);font-size:9px}.rv-catalog-price{font-weight:700}.rv-create-option{display:flex;gap:7px;align-items:center;color:var(--cv-primary)}
.rv-error{max-width:650px;margin:50px auto;padding:20px;border:1px solid color-mix(in srgb,var(--cv-danger) 35%,var(--cv-border));border-radius:8px;background:color-mix(in srgb,var(--cv-danger) 5%,var(--cv-card));text-align:center}.rv-error h2{margin-top:0;color:var(--cv-danger)}.rv-error p{color:var(--cv-muted)}

/* Request aside collapse behaves exactly like Client View */
#requestLayout.aside-collapsed{grid-template-columns:minmax(0,1fr) 42px}#requestLayout.aside-collapsed .cv-aside-body{display:none}#requestLayout.aside-collapsed .cv-aside{width:42px;height:46px;max-height:46px;overflow:visible;padding-right:0}#requestLayout.aside-collapsed .cv-aside-toggle i{transform:rotate(180deg)}

@media(max-width:1180px){.rv-header-info{gap:0 22px}.rv-assessment-grid{grid-template-columns:1fr 1fr}.rv-assessment-checklists{grid-column:1/-1}.rv-line{grid-template-columns:minmax(190px,1fr) 80px 120px 120px 34px}}
@media(max-width:980px){#requestLayout,#requestLayout.aside-collapsed{grid-template-columns:1fr}.rv-header-info{grid-template-columns:1fr}.rv-assessment-grid{grid-template-columns:1fr 1fr}.rv-assessment-checklists{grid-column:1/-1}}
@media(max-width:760px){.rv-top{flex-wrap:wrap}.rv-top-spacer{display:none}.rv-title{font-size:26px}.rv-header-info{grid-template-columns:1fr;margin-bottom:18px}.rv-assessment-grid{grid-template-columns:1fr}.rv-assessment-checklists{grid-column:auto}.rv-line{grid-template-columns:1fr 1fr;padding-right:50px}.rv-line-item,.rv-line-desc,.rv-line-image{grid-column:1/-1}.rv-remove-line{position:absolute;right:10px;top:10px}.rv-modal-grid,.rv-cost-grid{grid-template-columns:1fr}.rv-modal-grid .full{grid-column:auto}.rv-check-builder{grid-template-columns:1fr}.rv-check-manage{border-left:0;border-top:1px solid var(--cv-border)}.rv-check-q-grid{grid-template-columns:1fr}.rv-requested{grid-template-columns:105px 1fr}}
@media(max-width:520px){.rv-action-btn span{display:none}.rv-action-btn{width:36px;padding:0}.rv-title{font-size:24px}.rv-requested{grid-template-columns:1fr;gap:5px}.rv-date-pair,.rv-time-pair{grid-template-columns:1fr}}
@media print{.fieldplx-topbar,.fieldplx-sidebar,.fieldplx-footer,.cv-aside,.rv-top .rv-action-btn,.rv-top .rv-icon-btn,.rv-title-edit,.rv-edit,.rv-more-wrap{display:none!important}.fieldplx-main-content{margin-left:0!important}.cv-layout{display:block!important}.cv-page{max-width:none;padding:10px}.rv-section{break-inside:avoid}}

</style>

<div class="cv-page">
  <div class="cv-layout" id="requestLayout">
    <main class="cv-main">
      <div class="rv-main-inner" id="rvMainContent">
        <div class="rv-loading"><div><div class="rv-spinner" style="margin:auto"></div><div style="margin-top:12px">Loading request...</div></div></div>
      </div>
    </main>

    <aside class="cv-aside">
      <div class="cv-aside-toolbar">
        <button class="cv-aside-toggle" type="button" id="requestAsideToggle" title="Collapse side panel"><i class="bi bi-layout-sidebar-inset-reverse"></i></button>
      </div>
      <div class="cv-aside-body">
        <section class="cv-side-card" data-view-section="notes">
          <div class="cv-side-card-head"><h3>Notes</h3></div>
          <div class="rv-notes-card" id="rvNotesCard"><div class="rv-loading" style="min-height:190px"><div class="rv-spinner"></div></div></div>
        </section>
        <section class="cv-side-card">
          <div class="cv-side-card-head"><h3>Request</h3></div>
          <div class="cv-side-body">
            <div class="cv-overview-row"><span>Request ID</span><strong>#<?= (int)$requestId ?></strong></div>
            <div class="cv-overview-row"><span>Module</span><strong>Requests</strong></div>
            <div class="cv-overview-row"><span>Activity</span><button class="cv-link" type="button" id="requestHistorySideButton">View history</button></div>
          </div>
        </section>
      </div>
    </aside>
  </div>
</div>

<!-- Request History -->
<div class="rv-drawer-backdrop" id="historyBackdrop"></div>
<aside class="rv-history" id="historyDrawer" aria-hidden="true">
  <div class="rv-history-head"><h2>Request History</h2><button class="rv-history-close" type="button" id="historyClose"><i class="bi bi-x-lg"></i></button></div>
  <div class="rv-history-filters">
    <select class="rv-history-filter" id="historyTeam"><option value="">Team | All</option></select>
    <select class="rv-history-filter" id="historyType"><option value="">Type | All</option></select>
    <select class="rv-history-filter" id="historyDate"><option value="">Date | All</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option></select>
  </div>
  <div class="rv-history-list" id="historyList"></div>
</aside>

<!-- Product / Service quick create -->
<div class="rv-modal-bg" id="catalogModal">
  <div class="rv-modal" role="dialog" aria-modal="true">
    <div class="rv-modal-head"><h2>Add Product / Service</h2><button class="rv-modal-close" type="button" data-close-modal="catalogModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="rv-modal-body">
      <div class="rv-modal-grid">
        <div class="rv-form-field full"><label>Item type</label><select class="rv-select" id="newItemType"><option value="service">Service</option><option value="product">Product</option></select></div>
        <div class="rv-form-field full"><label>Name</label><input class="rv-input" id="newItemName" maxlength="190"></div>
        <div class="rv-form-field full"><label>Description</label><textarea class="rv-textarea" id="newItemDescription"></textarea></div>
      </div>
      <div class="rv-cost-grid">
        <div class="rv-form-field"><label>Unit cost</label><input class="rv-input" type="number" min="0" step="0.01" id="newItemCost" value="0.00"></div>
        <div class="rv-form-field"><label>Markup %</label><input class="rv-input" type="number" min="0" step="0.01" id="newItemMarkup" value="0.00"></div>
        <div class="rv-form-field"><label>Unit price</label><input class="rv-input" type="number" min="0" step="0.01" id="newItemPrice" value="0.00"></div>
      </div>
      <div class="rv-form-field" style="max-width:190px"><label>Tax %</label><input class="rv-input" type="number" min="0" max="100" step="0.01" id="newItemTax" value="0.00"></div>
    </div>
    <div class="rv-modal-foot"><button class="rv-small-btn" type="button" data-close-modal="catalogModal">Cancel</button><button class="rv-small-btn primary" type="button" id="createCatalogBtn">Create</button></div>
  </div>
</div>

<!-- Checklist builder -->
<div class="rv-modal-bg" id="checklistModal">
  <div class="rv-modal wide" role="dialog" aria-modal="true">
    <div class="rv-modal-head"><h2>Edit New Checklist</h2><button class="rv-modal-close" type="button" data-close-modal="checklistModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="rv-modal-body" style="padding:0">
      <div style="padding:15px 20px;border-top:1px solid var(--rv-line-soft)"><div class="rv-form-field" style="margin:0"><label>Form title</label><input class="rv-input" id="checklistName" value="New checklist"></div></div>
      <div class="rv-check-builder">
        <div class="rv-check-canvas" id="checklistCanvas"></div>
        <aside class="rv-check-manage"><h3>Manage checklist</h3><div class="rv-check-palette">
          <button type="button" data-check-add="section"><i class="bi bi-plus-square"></i> Add section</button>
          <button type="button" data-check-add="short_answer"><i class="bi bi-text-left"></i> Short answer</button>
          <button type="button" data-check-add="long_answer"><i class="bi bi-text-paragraph"></i> Long answer</button>
          <button type="button" data-check-add="dropdown"><i class="bi bi-menu-button-wide"></i> Dropdown - single choice</button>
          <button type="button" data-check-add="checkbox"><i class="bi bi-check2-square"></i> Checkbox</button>
          <button type="button" data-check-add="number"><i class="bi bi-123"></i> Numerical answer</button>
          <button type="button" data-check-add="image"><i class="bi bi-image"></i> Upload images</button>
          <button type="button" data-check-add="date"><i class="bi bi-calendar3"></i> Date picker</button>
          <button type="button" data-check-add="signature"><i class="bi bi-pen"></i> Signature</button>
        </div></aside>
      </div>
    </div>
    <div class="rv-modal-foot"><button class="rv-small-btn" type="button" data-close-modal="checklistModal">Cancel</button><button class="rv-small-btn primary" type="button" id="saveChecklistBtn">Save Checklist</button></div>
  </div>
</div>

<!-- Assessment booking confirmation email -->
<div class="rv-modal-bg" id="bookingEmailModal">
  <div class="rv-modal" role="dialog" aria-modal="true" aria-labelledby="bookingEmailTitle">
    <div class="rv-modal-head"><h2 id="bookingEmailTitle">Email booking confirmation</h2><button class="rv-modal-close" type="button" data-close-modal="bookingEmailModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="rv-modal-body">
      <p class="rv-email-note">This email is pre-filled from your Assessment Booking Confirmation email settings. Review the customer, subject, and message before sending.</p>
      <div class="rv-form-field"><label>To</label><input class="rv-input" type="email" id="bookingEmailTo" placeholder="Customer email"></div>
      <div class="rv-form-field"><label>Subject</label><input class="rv-input" id="bookingEmailSubject" maxlength="255" placeholder="Email subject"></div>
      <div class="rv-form-field"><label>Message</label><textarea class="rv-textarea rv-booking-message" id="bookingEmailMessage" maxlength="50000" placeholder="Email message"></textarea><div class="rv-email-template-source" id="bookingEmailTemplateSource"></div></div>
      <div class="rv-email-sent-note" id="bookingEmailSentNote" style="display:none"><i class="bi bi-check-circle-fill"></i><span></span></div>
    </div>
    <div class="rv-modal-foot"><button class="rv-small-btn" type="button" data-close-modal="bookingEmailModal">Cancel</button><button class="rv-small-btn primary" type="button" id="sendBookingEmailBtn">Send Email</button></div>
  </div>
</div>

<!-- Confirm modal -->
<div class="rv-modal-bg" id="confirmModal">
  <div class="rv-modal small" role="dialog" aria-modal="true">
    <div class="rv-modal-head"><h2 id="confirmTitle">Confirm</h2><button class="rv-modal-close" type="button" data-close-modal="confirmModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="rv-modal-body"><p id="confirmText" style="margin:0;color:#536e7a;line-height:1.55"></p></div>
    <div class="rv-modal-foot"><button class="rv-small-btn" type="button" data-close-modal="confirmModal">Cancel</button><button class="rv-small-btn primary" type="button" id="confirmActionBtn">Continue</button></div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
'use strict';
var requestId = <?= (int)$requestId ?>;
var csrfToken = <?= json_encode($requestViewCsrf, JSON_UNESCAPED_SLASHES) ?>;
var apiUrl = 'api/request-view.php';
var state = {data:null,catalog:[],users:[],currency:{},lines:[],assessment:null,checklistDraft:null,checklistTemplates:[],selectedChecklistIds:[],bookingEmail:null,lineEdit:false,assessmentEdit:false,noteEdit:false,catalogTarget:null,history:[]};

function esc(v){return String(v==null?'':v).replace(/[&<>'"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]})}
function readable(v){return String(v||'').replace(/_/g,' ').replace(/\b\w/g,function(m){return m.toUpperCase()})}
function toast(type,msg,duration){if(typeof window.fieldplxToast==='function'){window.fieldplxToast(type,msg,duration||4200)}else{console.log(type,msg)}}
function api(action,fields,files){var fd=new FormData();fd.append('action',action);fd.append('csrf_token',csrfToken);if(action!=='create_catalog_item')fd.append('request_id',requestId);Object.keys(fields||{}).forEach(function(k){fd.append(k,fields[k])});if(files){Object.keys(files).forEach(function(k){(files[k]||[]).forEach(function(file){fd.append(k+'[]',file)})})}var targetUrl=action==='send_booking_confirmation'?window.location.href:apiUrl;return fetch(targetUrl,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.text().then(function(text){var j=null;try{j=JSON.parse(text)}catch(err){var preview=String(text||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();if(preview.length>220)preview=preview.slice(0,220)+'...';throw new Error(preview?('Server error: '+preview):('The request API returned an empty response (HTTP '+r.status+'). Check the PHP error log.'))}if(!r.ok||!j||!j.success)throw new Error((j&&j.message)?j.message:('Request failed (HTTP '+r.status+').'));return j})})}
function money(n){n=Number(n||0);var c=state.currency||{},d=Number(c.decimal_places==null?2:c.decimal_places);var formatted=n.toLocaleString(undefined,{minimumFractionDigits:d,maximumFractionDigits:d});var symbol=c.symbol||'';return c.symbol_position==='after'?formatted+(symbol?' '+symbol:''):(symbol||'')+formatted}
function formatDate(v,withTime){if(!v)return '-';var d=new Date(String(v).replace(' ','T'));if(isNaN(d.getTime()))return v;var opts={day:'2-digit',month:'short',year:'numeric'};if(withTime){opts.hour='2-digit';opts.minute='2-digit'}return d.toLocaleString('en-IN',opts)}
function initials(name){var p=String(name||'').trim().split(/\s+/).filter(Boolean);return ((p[0]?p[0][0]:'')+(p.length>1?p[p.length-1][0]:'')).toUpperCase()||'U'}
function address(r){return [r.location_address1,r.location_address2,[r.location_city,r.location_state,r.location_postal_code].filter(Boolean).join(', ')].filter(Boolean).join('\n')}
function openModal(id){document.getElementById(id).classList.add('show');document.body.style.overflow='hidden'}
function closeModal(id){document.getElementById(id).classList.remove('show');if(!document.querySelector('.rv-modal-bg.show')&&!document.getElementById('historyDrawer').classList.contains('show'))document.body.style.overflow=''}
document.addEventListener('click',function(e){var b=e.target.closest('[data-close-modal]');if(b)closeModal(b.getAttribute('data-close-modal'))});

function load(){if(requestId<=0){renderError('Invalid request.');return}api('load',{}).then(function(j){try{state.data=j.data||{};state.catalog=state.data.catalog||[];state.users=state.data.users||[];state.currency=state.data.currency||{};state.lines=(state.data.line_items||[]).map(normalizeLineMarkup);state.assessment=state.data.assessment||null;state.history=state.data.history||[];state.checklistTemplates=state.data.checklist_templates||[];state.selectedChecklistIds=(state.data.checklists||[]).map(function(c){return Number(c.id)});state.bookingEmail=state.data.booking_email||null;renderAll()}catch(err){renderError('Request page render error: '+(err&&err.message?err.message:String(err)))}}).catch(function(e){renderError(e.message)})}
function renderError(msg){var main=document.getElementById('rvMainContent'),notes=document.getElementById('rvNotesCard');if(main)main.innerHTML='<div class="rv-error"><h2>Request unavailable</h2><p>'+esc(msg||'Unknown request-view error.')+'</p><a class="rv-small-btn" style="display:inline-flex;align-items:center" href="requests.php">Back to Requests</a></div>';if(notes)notes.innerHTML='<div class="rv-history-empty">Request unavailable.</div>';if(!main){var box=document.createElement('div');box.style.cssText='margin:30px;padding:20px;border:1px solid #efc7ca;background:#fff7f7;color:#8d2f38;font:14px Arial';box.textContent='Request View error: '+String(msg||'Unknown error');document.body.appendChild(box)}}

function renderAll(){renderMain();renderNotes();renderHistoryFilters();renderHistory()}
function renderMain(){var d=state.data,r=d.request||{},hasAssessment=!!state.assessment,booking=state.bookingEmail||{},bookingSent=!!Number(booking.sent||0),bookingAvailable=hasAssessment&&Number(booking.available||0)===1&&Number(booking.enabled==null?1:booking.enabled)===1;var html='';
var bookingPrimary=bookingAvailable&&!bookingSent?'<button class="rv-action-btn primary" type="button" id="bookingEmailBtn"><i class="bi bi-envelope"></i> <span>Email booking confirmation</span></button>':'';
var resendItem=bookingAvailable&&bookingSent?'<button class="rv-more-item" type="button" id="resendBookingEmail"><i class="bi bi-envelope-arrow-up"></i> Resend booking confirmation</button>':'';
html+='<div class="rv-top"><span class="rv-request-icon"><i class="bi bi-inbox"></i></span><span class="rv-status">'+esc(readable(r.status||'new'))+'</span><span class="rv-request-no">'+esc(r.request_no||'')+'</span><span class="rv-top-spacer"></span><button class="rv-icon-btn" id="historyOpen" type="button" title="Request history"><i class="bi bi-clock-history"></i></button><div class="rv-more-wrap"><button class="rv-action-btn" id="moreBtn" type="button"><i class="bi bi-three-dots"></i> <span>More</span></button><div class="rv-more-menu" id="moreMenu"><a class="rv-more-item" id="convertQuoteLink" href="#"><i class="bi bi-cash-coin"></i> Convert to Quote</a><a class="rv-more-item" id="convertJobLink" href="#"><i class="bi bi-hammer"></i> Convert to Job</a>'+resendItem+'<div class="rv-more-sep"></div><button class="rv-more-item" type="button" id="archiveBtn"><i class="bi bi-archive"></i> Archive</button><button class="rv-more-item" type="button" id="printBtn"><i class="bi bi-printer"></i> Print</button><button class="rv-more-item danger" type="button" id="deleteBtn"><i class="bi bi-trash"></i> Delete</button></div></div>'+bookingPrimary+(hasAssessment?'':'<button class="rv-action-btn primary" type="button" id="assessmentTopBtn"><i class="bi bi-calendar3"></i> <span>Schedule Assessment</span></button>')+'</div>';
html+='<div class="rv-title-row"><h1 class="rv-title">'+esc(r.title||'Service Request')+'</h1><button class="rv-title-edit" type="button" id="titleEditBtn"><i class="bi bi-pencil"></i></button></div>';
html+='<div class="rv-header-info"><div class="rv-customer-card"><button class="rv-customer-more" type="button" id="customerMore"><i class="bi bi-three-dots"></i></button><div class="rv-customer-name">'+esc(r.client_name||'Customer')+' <span class="rv-customer-dot"></span></div><div class="rv-address">'+esc(address(r)||r.location_name||'')+'</div><div class="rv-customer-links">'+(r.client_phone?'<a href="tel:'+esc(r.client_phone)+'">'+esc(r.client_phone)+'</a>':'')+(r.client_email?'<a href="mailto:'+esc(r.client_email)+'">'+esc(r.client_email)+'</a>':'')+'</div></div><div class="rv-requested"><span>Requested</span><strong>'+esc(formatDate(r.created_at,false))+'</strong></div></div>';
html+=renderOverview();html+=renderAssessment();html+=renderLines();document.getElementById('rvMainContent').innerHTML=html;bindMain();}
function renderOverview(){var r=state.data.request||{},imgs=(state.data.attachments||[]).filter(function(a){return String(a.attachment_type||'').indexOf('before_photo')>=0||String(a.file_mime||'').indexOf('image/')===0});if(state.overviewEdit){return '<section class="rv-section"><div class="rv-card"><div class="rv-card-inner"><div class="rv-section-head"><h2>Overview</h2></div><div class="rv-form-field"><label>Title</label><input class="rv-input" id="overviewTitle" value="'+esc(r.title||'')+'"></div><div class="rv-form-field"><label>Service details</label><textarea class="rv-textarea" id="overviewDescription">'+esc(r.description||'')+'</textarea></div><div class="rv-form-field"><label>How did you hear about us?</label><select class="rv-select" id="overviewSource">'+['office','website','portal','phone','sms','email','ai','other'].map(function(v){return '<option value="'+v+'" '+(r.source===v?'selected':'')+'>'+readable(v)+'</option>'}).join('')+'</select></div><div class="rv-editor-actions"><button class="rv-small-btn" id="overviewCancel" type="button">Cancel</button><button class="rv-small-btn primary" id="overviewSave" type="button">Save</button></div></div></div></section>'}
var imageHtml=imgs.length?'<div class="rv-work-images">'+imgs.slice(0,10).map(function(a){return '<a class="rv-work-image" href="'+esc(a.file_path||'#')+'" target="_blank"><img src="'+esc(a.file_path||'')+'" alt=""></a>'}).join('')+'</div>':'<div class="rv-placeholder">—</div>';
return '<section class="rv-section"><div class="rv-card"><div class="rv-card-inner"><div class="rv-section-head"><h2>Overview</h2><button class="rv-edit" id="overviewEdit" type="button"><i class="bi bi-pencil"></i></button></div><div class="rv-info-block"><div class="rv-subtitle">Service details</div><div class="rv-helper">Please provide as much information as you can</div><div class="rv-copy">'+esc(r.description||'—')+'</div></div><div class="rv-info-block"><div class="rv-helper">Share images of the work to be done</div>'+imageHtml+'</div><div class="rv-info-block"><div class="rv-helper">How did you hear about us?</div><div class="rv-copy">'+esc(r.source?readable(r.source):'—')+'</div></div></div></div></section>'}

function assignedNames(){var ids=(state.assessment&&state.assessment.assigned_user_ids)||[];return ids.map(function(id){var u=state.users.find(function(x){return Number(x.id)===Number(id)});return u?u.name:null}).filter(Boolean)}
function assessmentSchedule(a){if(!a)return '';if(!a.scheduled_start)return 'Schedule later';return formatDate(a.scheduled_start,true)+(a.scheduled_end?' - '+formatDate(a.scheduled_end,true):'')}
function checklistTemplateById(id){return (state.checklistTemplates||[]).find(function(x){return Number(x.id)===Number(id)})}
function resetChecklistSelection(){state.selectedChecklistIds=(state.data.checklists||[]).map(function(c){return Number(c.id)})}
function checklistSelectionChips(){var chips=(state.selectedChecklistIds||[]).map(function(id){var c=checklistTemplateById(id);if(!c)c=(state.data.checklists||[]).find(function(x){return Number(x.id)===Number(id)});return c?'<span class="rv-checklist-chip"><i class="bi bi-clipboard-check"></i> '+esc(c.name)+' ('+Number(c.item_count||0)+')</span>':''}).join('');if(state.checklistDraft)chips+='<span class="rv-checklist-chip"><i class="bi bi-check-circle"></i> '+esc(state.checklistDraft.name)+' (new)</span>';return chips}
function checklistPickerHtml(){var templates=state.checklistTemplates||[];if(!templates.length){return '<div class="rv-checklist-box"><div class="rv-checklist-icon"><i class="bi bi-clipboard2-check"></i></div><div class="rv-checklist-copy"><div class="rv-checklist-text"><strong>CAPTURE ON-SITE DETAILS</strong><p>Attach custom-built checklists so that nothing gets missed</p></div><button class="rv-link-btn" id="createChecklistBtn" type="button">Create a Checklist</button><div class="rv-checklist-items" id="checklistDraftSummary">'+checklistSelectionChips()+'</div></div></div>'}
var options=templates.map(function(c){var checked=(state.selectedChecklistIds||[]).indexOf(Number(c.id))>=0;return '<label class="rv-checklist-option" data-checklist-option data-name="'+esc(String(c.name||'').toLowerCase())+'"><input type="checkbox" class="rv-existing-checklist" value="'+Number(c.id)+'" '+(checked?'checked':'')+'><span class="rv-checklist-option-copy"><strong>'+esc(c.name)+'</strong><small>'+Number(c.item_count||0)+' question'+(Number(c.item_count||0)===1?'':'s')+'</small></span></label>'}).join('');
return '<div><div class="rv-checklist-toolbar"><button class="rv-checklist-menu-btn" type="button" id="checklistPickerBtn">Checklists <i class="bi bi-chevron-down"></i></button><button class="rv-new-checklist-btn" type="button" id="createChecklistBtn">New checklist</button><div class="rv-checklist-picker" id="checklistPicker"><div class="rv-checklist-search"><input type="search" id="checklistSearch" placeholder="Search Checklists"></div><div class="rv-checklist-picker-head"><span>Select Checklists</span><button class="rv-checklist-select-all" type="button" id="checklistSelectAll">Select all</button></div><div class="rv-checklist-option-list">'+options+'</div></div></div><div class="rv-checklist-items" id="checklistDraftSummary" style="margin-top:10px">'+checklistSelectionChips()+'</div></div>'}
function renderAssessment(){
var a=state.assessment;
if(!a&&!state.assessmentEdit){return '<section class="rv-section"><h2 class="rv-assessment-title">On-site assessment</h2><div class="rv-assessment-empty" id="assessmentEmpty"><div class="rv-plus">+</div><div>Visit the property to assess the job before you do the work</div></div></section>'}
var startDate='',endDate='',startTime='',endTime='',scheduleLater=true,anytime=false;
if(a&&a.scheduled_start){var sd=String(a.scheduled_start).replace(' ','T');var ed=String(a.scheduled_end||a.scheduled_start).replace(' ','T');startDate=sd.slice(0,10);startTime=sd.slice(11,16);endDate=ed.slice(0,10);endTime=ed.slice(11,16);scheduleLater=(a.schedule_later!=null?Number(a.schedule_later)===1:false);anytime=(a.anytime!=null?Number(a.anytime)===1:(startTime==='00:00'&&endTime==='23:59'))}
else if(a){scheduleLater=(a.schedule_later!=null?Number(a.schedule_later)===1:true);anytime=(a.anytime!=null?Number(a.anytime)===1:false)}
var closeBtn=!a?'<button class="rv-edit" id="assessmentClose" type="button" aria-label="Close assessment"><i class="bi bi-x-lg"></i></button>':'';
var reminder=(a&&a.team_reminder)?String(a.team_reminder):'none';
var reminderList=[['none','No reminder set'],['at_start','At start of task'],['30_minutes','30 minutes before'],['1_hour','1 hour before'],['2_hours','2 hours before'],['5_hours','5 hours before'],['24_hours','24 hours before']];if(reminderList.every(function(x){return x[0]!==reminder})&&reminder!=='')reminderList.push([reminder,readable(reminder)]);var reminderOptions=reminderList.map(function(x){return '<option value="'+x[0]+'" '+(reminder===x[0]?'selected':'')+'>'+x[1]+'</option>'}).join('');
return '<section class="rv-section"><div class="rv-assessment-card"><div class="rv-assessment-editor"><div class="rv-section-head"><h2>On-site assessment</h2>'+closeBtn+'</div><textarea class="rv-textarea" id="assessmentInstructions" placeholder="Instructions">'+esc(a&&a.notes?a.notes:'')+'</textarea><div class="rv-assessment-grid"><div class="rv-assessment-col"><h3>Schedule</h3><div class="rv-date-pair"><input class="rv-input" type="date" id="assessmentStartDate" value="'+esc(startDate)+'"><input class="rv-input" type="date" id="assessmentEndDate" value="'+esc(endDate)+'"></div><label class="rv-check-row"><input type="checkbox" id="scheduleLater" '+(scheduleLater?'checked':'')+'> Schedule later</label><div class="rv-time-pair"><input class="rv-input" type="time" id="assessmentStartTime" value="'+esc(startTime)+'"><input class="rv-input" type="time" id="assessmentEndTime" value="'+esc(endTime)+'"></div><label class="rv-check-row"><input type="checkbox" id="assessmentAnytime" '+(anytime?'checked':'')+'> Anytime</label></div><div class="rv-assessment-col rv-assessment-team"><h3>Team</h3><select id="assessmentTeam" multiple></select><label class="rv-check-row"><input type="checkbox" id="emailTeam"> Email team when assigned</label><div class="rv-form-field"><label>Team reminder</label><select class="rv-select" id="teamReminder">'+reminderOptions+'</select></div></div><div class="rv-assessment-col rv-assessment-checklists"><h3>Checklists</h3>'+checklistPickerHtml()+'</div></div><div class="rv-editor-actions" style="margin-top:18px"><button class="rv-small-btn" id="assessmentCancel" type="button">Cancel</button><button class="rv-small-btn primary" id="assessmentSave" type="button">Save</button></div></div></div></section>'}
function bindAssessmentChecklistPicker(){var btn=document.getElementById('checklistPickerBtn'),menu=document.getElementById('checklistPicker'),search=document.getElementById('checklistSearch'),selectAll=document.getElementById('checklistSelectAll');if(btn&&menu){btn.onclick=function(e){e.stopPropagation();menu.classList.toggle('show')};menu.onclick=function(e){e.stopPropagation()};if(search)search.oninput=function(){var q=String(this.value||'').toLowerCase().trim();menu.querySelectorAll('[data-checklist-option]').forEach(function(row){row.style.display=!q||String(row.getAttribute('data-name')||'').indexOf(q)>=0?'flex':'none'})};menu.querySelectorAll('.rv-existing-checklist').forEach(function(cb){cb.onchange=function(){var id=Number(cb.value),idx=state.selectedChecklistIds.indexOf(id);if(cb.checked&&idx<0)state.selectedChecklistIds.push(id);if(!cb.checked&&idx>=0)state.selectedChecklistIds.splice(idx,1);var sum=document.getElementById('checklistDraftSummary');if(sum)sum.innerHTML=checklistSelectionChips()}});if(selectAll)selectAll.onclick=function(){menu.querySelectorAll('[data-checklist-option]').forEach(function(row){if(row.style.display==='none')return;var cb=row.querySelector('.rv-existing-checklist'),id=Number(cb.value);cb.checked=true;if(state.selectedChecklistIds.indexOf(id)<0)state.selectedChecklistIds.push(id)});var sum=document.getElementById('checklistDraftSummary');if(sum)sum.innerHTML=checklistSelectionChips()}}var create=document.getElementById('createChecklistBtn');if(create)create.onclick=openChecklist}

function renderLines(){var lines=state.lines||[];if(state.lineEdit){return '<section class="rv-section"><div class="rv-lines-card"><div class="rv-lines-head"><h2>Product / Service</h2><p>Keep everything on track by adding products and services.</p><button class="rv-small-btn primary" id="addLineBtn" type="button">Add Line Item</button></div><div class="rv-line-list" id="lineEditorList">'+lines.map(function(l,i){return lineEditorRow(l,i)}).join('')+'</div>'+totalsHtml(lines)+'<div class="rv-line-editor-actions"><button class="rv-small-btn" id="lineCancel" type="button">Cancel</button><button class="rv-small-btn primary" id="lineSave" type="button">Save</button></div></div></section>'}
if(!lines.length){return '<section class="rv-section"><div class="rv-lines-card"><div class="rv-lines-head"><h2>Product / Service</h2><p>Keep everything on track by adding products and services.</p><button class="rv-small-btn primary" id="addLineFromEmpty" type="button">Add Line Item</button></div>'+totalsHtml([])+'</div></section>'}
return '<section class="rv-section"><div class="rv-lines-card"><div class="rv-lines-head" style="display:flex;align-items:flex-start;gap:12px"><div><h2>Product / Service</h2><p style="margin-bottom:0">Keep everything on track by adding products and services.</p></div><button class="rv-edit" id="lineEdit" style="margin-left:auto" type="button"><i class="bi bi-pencil"></i></button></div><div class="rv-lines-display">'+lines.map(function(l){return '<div class="rv-display-line"><div class="rv-display-line-name"><strong>'+esc(l.item_name)+'</strong><small>'+esc(l.description||readable(l.item_type))+'</small></div><div class="rv-display-right">'+Number(l.quantity||0).toFixed(2)+'</div><div class="rv-display-right">'+money(l.unit_price)+'</div><div class="rv-display-right"><strong>'+money(l.line_total)+'</strong></div></div>'}).join('')+'</div>'+totalsHtml(lines)+'</div></section>'}
function normalizeLineMarkup(l){l=l||{};l.unit_cost=Number(l.unit_cost||0);l.unit_price=Number(l.unit_price||0);if(l.markup_percent==null)l.markup_percent=l.unit_cost>0?((l.unit_price-l.unit_cost)/l.unit_cost*100):0;else l.markup_percent=Number(l.markup_percent||0);return l}
function lineEditorRow(l,i){l=normalizeLineMarkup(l);return '<div class="rv-line" data-line-index="'+i+'"><div class="rv-line-item"><select class="rv-catalog-select" data-i="'+i+'"></select></div><div class="rv-money"><small>Quantity</small><input class="rv-line-qty" data-i="'+i+'" type="number" min="0.001" step="0.001" value="'+esc(Number(l.quantity||1))+'"></div><div class="rv-money rv-price-cell" data-price-cell="'+i+'"><small>Unit price</small><input class="rv-line-price" data-i="'+i+'" type="number" min="0" step="0.01" value="'+esc(Number(l.unit_price||0).toFixed(2))+'"><div class="rv-markup-pop" data-markup-pop="'+i+'"><div class="rv-markup-field"><label>Unit cost</label><input class="rv-markup-input rv-line-cost" data-i="'+i+'" type="number" min="0" step="0.01" value="'+esc(Number(l.unit_cost||0).toFixed(2))+'"></div><div class="rv-markup-field"><label>Markup (%)</label><input class="rv-markup-input rv-line-markup" data-i="'+i+'" type="number" step="0.01" value="'+esc(Number(l.markup_percent||0).toFixed(2))+'"></div><p class="rv-markup-note">These calculations won\'t be visible to your clients</p></div></div><div class="rv-money"><small>Total</small><strong class="rv-line-total" data-i="'+i+'">'+money(l.line_total||0)+'</strong></div><button class="rv-remove-line" type="button" data-remove-line="'+i+'"><i class="bi bi-three-dots"></i></button><textarea class="rv-textarea rv-line-desc" data-i="'+i+'" placeholder="Description">'+esc(l.description||'')+'</textarea><label class="rv-line-image" title="Attach image"><i class="bi bi-image"></i><input class="rv-line-image-input" data-i="'+i+'" type="file" accept="image/*" hidden></label></div>'}
function showMarkupPopup(i){document.querySelectorAll('.rv-markup-pop.show').forEach(function(p){if(Number(p.dataset.markupPop)!==Number(i)){p.classList.remove('show');var old=p.closest('.rv-price-cell');if(old)old.classList.remove('pop-open')}});var pop=document.querySelector('.rv-markup-pop[data-markup-pop="'+i+'"]');if(pop){pop.classList.add('show');var cell=pop.closest('.rv-price-cell');if(cell)cell.classList.add('pop-open')}}
function recalcMarkupFromPrice(i){var l=state.lines[i];l.markup_percent=Number(l.unit_cost||0)>0?((Number(l.unit_price||0)-Number(l.unit_cost||0))/Number(l.unit_cost||0)*100):0;var m=document.querySelector('.rv-line-markup[data-i="'+i+'"]');if(m)m.value=Number(l.markup_percent||0).toFixed(2)}
function recalcPriceFromMarkup(i){var l=state.lines[i];l.unit_price=Math.max(0,Number(l.unit_cost||0)*(1+Number(l.markup_percent||0)/100));var p=document.querySelector('.rv-line-price[data-i="'+i+'"]');if(p)p.value=Number(l.unit_price||0).toFixed(2);recalcLine(i);updateLineTotal(i)}

function totalsHtml(lines){var sub=0,total=0;(lines||[]).forEach(function(l){sub+=Number(l.quantity||0)*Number(l.unit_price||0);total+=Number(l.line_total!=null?l.line_total:(Number(l.quantity||0)*Number(l.unit_price||0)))});return '<div class="rv-totals"><div class="rv-total-box"><div class="rv-total-row"><span>Subtotal</span><span>'+money(sub)+'</span></div><div class="rv-total-row grand"><span>Total</span><span>'+money(total)+'</span></div></div></div>'}

function bindMain(){var r=state.data.request||{};var q={request_id:requestId,client_id:r.client_id};if(r.location_id)q.location_id=r.location_id;if(r.product_service_id)q.product_service_id=r.product_service_id;var qs=new URLSearchParams(q).toString();document.getElementById('convertQuoteLink').href='add-quotation.php?'+qs;document.getElementById('convertJobLink').href='job-form.php?'+qs;
document.getElementById('historyOpen').onclick=openHistory;document.getElementById('moreBtn').onclick=function(e){e.stopPropagation();document.getElementById('moreMenu').classList.toggle('show')};document.getElementById('printBtn').onclick=function(){window.print()};document.getElementById('archiveBtn').onclick=function(){confirmAction('Archive Request','Archive this service request? It will remain in your records.',function(){api('archive',{}).then(function(j){toast('success',j.message);load()}).catch(function(e){toast('error',e.message)})})};document.getElementById('deleteBtn').onclick=function(){confirmAction('Delete Request','Delete this service request? This action uses the available soft-delete/cancel behavior.',function(){api('delete',{}).then(function(j){toast('success',j.message);setTimeout(function(){location.href='requests.php'},500)}).catch(function(e){toast('error',e.message)})})};document.getElementById('customerMore').onclick=function(){location.href='client-view.php?client_id='+encodeURIComponent(r.client_id)};document.getElementById('titleEditBtn').onclick=function(){state.overviewEdit=true;renderMain()};var assessmentTopBtn=document.getElementById('assessmentTopBtn');if(assessmentTopBtn)assessmentTopBtn.onclick=function(){state.assessmentEdit=true;resetChecklistSelection();renderMain()};
if(document.getElementById('overviewEdit'))document.getElementById('overviewEdit').onclick=function(){state.overviewEdit=true;renderMain()};if(document.getElementById('overviewCancel'))document.getElementById('overviewCancel').onclick=function(){state.overviewEdit=false;renderMain()};if(document.getElementById('overviewSave'))document.getElementById('overviewSave').onclick=saveOverview;
if(document.getElementById('assessmentEmpty'))document.getElementById('assessmentEmpty').onclick=function(){state.assessmentEdit=true;resetChecklistSelection();renderMain()};if(document.getElementById('assessmentClose'))document.getElementById('assessmentClose').onclick=function(){state.assessmentEdit=false;state.checklistDraft=null;resetChecklistSelection();renderMain()};if(document.getElementById('assessmentCancel'))document.getElementById('assessmentCancel').onclick=function(){state.assessmentEdit=false;state.checklistDraft=null;resetChecklistSelection();renderMain()};if(document.getElementById('assessmentSave')){initAssessmentSelect();bindScheduleSwitches();bindAssessmentChecklistPicker();document.getElementById('assessmentSave').onclick=saveAssessment}var bookingEmailBtn=document.getElementById('bookingEmailBtn');if(bookingEmailBtn)bookingEmailBtn.onclick=openBookingEmail;var resendBookingEmail=document.getElementById('resendBookingEmail');if(resendBookingEmail)resendBookingEmail.onclick=openBookingEmail;
if(document.getElementById('addLineFromEmpty'))document.getElementById('addLineFromEmpty').onclick=function(){state.lineEdit=true;state.lines=[blankLine()];renderMain()};if(document.getElementById('lineEdit'))document.getElementById('lineEdit').onclick=function(){state.lineEdit=true;renderMain()};if(document.getElementById('addLineBtn')){initLineEditors();document.getElementById('addLineBtn').onclick=function(){state.lines.push(blankLine());renderMain();setTimeout(function(){var selects=document.querySelectorAll('.rv-catalog-select');if(selects.length)$(selects[selects.length-1]).select2('open')},20)};document.getElementById('lineCancel').onclick=function(){state.lineEdit=false;load()};document.getElementById('lineSave').onclick=saveLines;document.querySelectorAll('[data-remove-line]').forEach(function(b){b.onclick=function(){state.lines.splice(Number(b.getAttribute('data-remove-line')),1);renderMain()}})} }
document.addEventListener('click',function(e){var m=document.getElementById('moreMenu');if(m)m.classList.remove('show');var picker=document.getElementById('checklistPicker');if(picker&&!e.target.closest('.rv-checklist-toolbar'))picker.classList.remove('show');if(!e.target.closest('.rv-price-cell')){document.querySelectorAll('.rv-markup-pop.show').forEach(function(p){p.classList.remove('show');var c=p.closest('.rv-price-cell');if(c)c.classList.remove('pop-open')})}});

function saveOverview(){var btn=document.getElementById('overviewSave');btn.disabled=true;api('save_overview',{title:document.getElementById('overviewTitle').value,description:document.getElementById('overviewDescription').value,source:document.getElementById('overviewSource').value}).then(function(j){state.data.request=j.request;state.overviewEdit=false;toast('success',j.message);renderMain();state.history.unshift({event_type:'service_request_overview_updated',title:'Request overview updated',created_at:new Date().toISOString(),actor_name:'You',details:{}});renderHistory()}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})}
function initAssessmentSelect(){var el=$('#assessmentTeam');el.empty();state.users.forEach(function(u){el.append(new Option(u.name,u.id,false,(state.assessment&&state.assessment.assigned_user_ids||[]).map(Number).indexOf(Number(u.id))>=0))});el.select2({placeholder:'Assign team members',closeOnSelect:false,width:'100%'}).trigger('change')}
function bindScheduleSwitches(){function sync(){var later=document.getElementById('scheduleLater').checked,any=document.getElementById('assessmentAnytime').checked;['assessmentStartDate','assessmentEndDate'].forEach(function(id){document.getElementById(id).disabled=later});['assessmentStartTime','assessmentEndTime'].forEach(function(id){document.getElementById(id).disabled=later||any})}document.getElementById('scheduleLater').onchange=sync;document.getElementById('assessmentAnytime').onchange=sync;sync()}
function saveAssessment(){var btn=document.getElementById('assessmentSave');btn.disabled=true;var ids=$('#assessmentTeam').val()||[];api('save_assessment',{assessment_id:state.assessment?state.assessment.id:0,instructions:document.getElementById('assessmentInstructions').value,schedule_later:document.getElementById('scheduleLater').checked?1:0,start_date:document.getElementById('assessmentStartDate').value,end_date:document.getElementById('assessmentEndDate').value,anytime:document.getElementById('assessmentAnytime').checked?1:0,start_time:document.getElementById('assessmentStartTime').value,end_time:document.getElementById('assessmentEndTime').value,assigned_user_ids:JSON.stringify(ids),email_team_when_assigned:document.getElementById('emailTeam').checked?1:0,team_reminder:document.getElementById('teamReminder').value,checklist_template_ids:JSON.stringify(state.selectedChecklistIds||[]),new_checklist_json:state.checklistDraft?JSON.stringify(state.checklistDraft):''}).then(function(j){state.assessment=j.assessment;state.data.checklists=j.checklists||state.data.checklists;state.checklistTemplates=j.checklist_templates||state.checklistTemplates;state.selectedChecklistIds=(state.data.checklists||[]).map(function(c){return Number(c.id)});state.bookingEmail=j.booking_email||state.bookingEmail;state.assessmentEdit=false;state.checklistDraft=null;toast('success',j.message);renderMain();if(j.team_email&&j.team_email.email_failed)toast('warning',j.team_email.email_failed+' team email(s) failed.')}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})}

function blankLine(){return {catalog_key:'',item_name:'',item_type:'service',description:'',quantity:1,unit_cost:0,markup_percent:0,unit_price:0,tax_percent:0,tax_amount:0,line_total:0}}
function catalogById(id){return state.catalog.find(function(x){return String(x.id)===String(id)})}
function initLineEditors(){document.querySelectorAll('.rv-catalog-select').forEach(function(sel){var i=Number(sel.getAttribute('data-i')),line=normalizeLineMarkup(state.lines[i]||blankLine());state.lines[i]=line;var $s=$(sel);$s.empty();$s.append(new Option('', '', false, false));state.catalog.forEach(function(c){$s.append(new Option(c.name,c.id,false,String(line.catalog_key||'')===String(c.id)))});$s.select2({placeholder:'Name',width:'100%',tags:true,createTag:function(params){var term=$.trim(params.term);if(!term)return null;return {id:'__create__:'+term,text:'+ Create new item',term:term,isNew:true}},templateResult:catalogResult,templateSelection:function(item){var c=catalogById(item.id);return c?c.name:(item.text||'')}}).on('select2:select',function(e){var d=e.params.data;if(String(d.id).indexOf('__create__:')===0){state.catalogTarget=i;document.getElementById('newItemName').value=d.term||String(d.id).replace('__create__:','');$s.val(null).trigger('change');openModal('catalogModal');return}var c=catalogById(d.id);if(c){state.lines[i].catalog_key=c.id;state.lines[i].item_name=c.name;state.lines[i].item_type=c.item_type;state.lines[i].description=c.description||'';state.lines[i].unit_cost=Number(c.unit_cost||0);state.lines[i].unit_price=Number(c.unit_price||0);state.lines[i].markup_percent=c.markup_percent!=null?Number(c.markup_percent||0):(state.lines[i].unit_cost>0?((state.lines[i].unit_price-state.lines[i].unit_cost)/state.lines[i].unit_cost*100):0);state.lines[i].tax_percent=Number(c.tax_percent||0);recalcLine(i);renderMain()}})});
document.querySelectorAll('.rv-line-qty').forEach(function(el){el.oninput=function(){var i=Number(el.dataset.i);state.lines[i].quantity=Number(el.value||0);recalcLine(i);updateLineTotal(i)}});
document.querySelectorAll('.rv-line-price').forEach(function(el){el.onclick=el.onfocus=function(e){e.stopPropagation();showMarkupPopup(Number(el.dataset.i))};el.oninput=function(){var i=Number(el.dataset.i);state.lines[i].unit_price=Math.max(0,Number(el.value||0));recalcMarkupFromPrice(i);recalcLine(i);updateLineTotal(i)}});
document.querySelectorAll('.rv-line-cost').forEach(function(el){el.onclick=function(e){e.stopPropagation()};el.oninput=function(){var i=Number(el.dataset.i);state.lines[i].unit_cost=Math.max(0,Number(el.value||0));recalcPriceFromMarkup(i)}});
document.querySelectorAll('.rv-line-markup').forEach(function(el){el.onclick=function(e){e.stopPropagation()};el.oninput=function(){var i=Number(el.dataset.i);state.lines[i].markup_percent=Number(el.value||0);recalcPriceFromMarkup(i)}});
document.querySelectorAll('.rv-markup-pop').forEach(function(pop){pop.onclick=function(e){e.stopPropagation()}});
document.querySelectorAll('.rv-line-desc').forEach(function(el){el.oninput=function(){state.lines[Number(el.dataset.i)].description=el.value}})}
function catalogResult(item){if(!item.id)return item.text;if(item.isNew||String(item.id).indexOf('__create__:')===0)return $('<div class="rv-create-option"><i class="bi bi-plus-circle"></i><span>'+esc(item.text)+'</span></div>');var c=catalogById(item.id);if(!c)return item.text;return $('<div class="rv-catalog-result"><div class="rv-catalog-main"><div class="rv-catalog-name"><span>'+esc(c.name)+'</span><span class="rv-badge '+esc(c.item_type)+'">'+esc(readable(c.item_type))+'</span></div><div class="rv-catalog-desc">'+esc(c.description||'')+'</div></div><div class="rv-catalog-price">'+esc(money(c.unit_price))+'</div></div>')}
function recalcLine(i){var l=state.lines[i];var base=Number(l.quantity||0)*Number(l.unit_price||0);l.tax_amount=base*Number(l.tax_percent||0)/100;l.line_total=base+l.tax_amount}
function updateLineTotal(i){var el=document.querySelector('.rv-line-total[data-i="'+i+'"]');if(el)el.textContent=money(state.lines[i].line_total);var card=el&&el.closest('.rv-lines-card');if(card){var t=card.querySelector('.rv-totals');if(t)t.outerHTML=totalsHtml(state.lines)}}
function saveLines(){document.querySelectorAll('.rv-line-desc').forEach(function(el){state.lines[Number(el.dataset.i)].description=el.value});var images=[];document.querySelectorAll('.rv-line-image-input').forEach(function(el){Array.from(el.files||[]).forEach(function(f){images.push(f)})});var btn=document.getElementById('lineSave');btn.disabled=true;api('save_lines',{line_items_json:JSON.stringify(state.lines)},{line_item_images:images}).then(function(j){state.lines=j.line_items||[];state.lineEdit=false;toast('success',j.message);if(j.line_images&&j.line_images.failed)toast('warning',j.line_images.failed+' line image(s) could not be saved.');renderMain()}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})}

/* Quick create catalog */
var priceManuallyEdited=false;function recalcNewPrice(){if(priceManuallyEdited)return;var c=Number(document.getElementById('newItemCost').value||0),m=Number(document.getElementById('newItemMarkup').value||0);document.getElementById('newItemPrice').value=(c*(1+m/100)).toFixed(2)}
document.getElementById('newItemCost').addEventListener('input',recalcNewPrice);document.getElementById('newItemMarkup').addEventListener('input',recalcNewPrice);document.getElementById('newItemPrice').addEventListener('input',function(){priceManuallyEdited=true});
document.getElementById('createCatalogBtn').onclick=function(){var btn=this;btn.disabled=true;api('create_catalog_item',{item_type:document.getElementById('newItemType').value,name:document.getElementById('newItemName').value,description:document.getElementById('newItemDescription').value,unit_cost:document.getElementById('newItemCost').value,markup_percent:document.getElementById('newItemMarkup').value,unit_price:document.getElementById('newItemPrice').value,tax_percent:document.getElementById('newItemTax').value}).then(function(j){var item=j.item;state.catalog.push(item);state.catalog.sort(function(a,b){return String(a.name).localeCompare(String(b.name))});if(state.catalogTarget!=null&&state.lines[state.catalogTarget]){state.lines[state.catalogTarget].catalog_key=item.id;state.lines[state.catalogTarget].item_name=item.name;state.lines[state.catalogTarget].item_type=item.item_type;state.lines[state.catalogTarget].description=item.description||'';state.lines[state.catalogTarget].unit_cost=Number(item.unit_cost||0);state.lines[state.catalogTarget].unit_price=Number(item.unit_price||0);state.lines[state.catalogTarget].tax_percent=Number(item.tax_percent||0);recalcLine(state.catalogTarget)}closeModal('catalogModal');toast('success',j.message);state.catalogTarget=null;priceManuallyEdited=false;renderMain()}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})};

/* Notes */
function renderNotes(){var notes=state.data.notes||[];var html='';if(!state.noteEdit){if(notes.length){html+='<div class="rv-note-list">'+notes.slice(0,4).map(function(n){return '<div class="rv-note-item"><div class="rv-note-item-top"><strong>'+esc(n.actor_name||'Team member')+'</strong><time>'+esc(formatDate(n.created_at,true))+'</time></div><p>'+esc(n.note||'')+'</p></div>'}).join('')+'</div><button class="rv-add-note-btn" id="openNoteEditor" type="button">Add internal note</button>'}else{html='<div class="rv-note-empty" id="openNoteEditor"><div class="rv-note-empty-icon"><i class="bi bi-journal-plus"></i></div><div>Leave an internal note for<br>yourself or a team member</div></div>'}}else{html='<div class="rv-note-editor"><textarea class="rv-textarea" id="noteText" placeholder="Use @ in notes to mention your team"></textarea><div class="rv-mention-menu" id="mentionMenu"></div></div><label class="rv-note-drop"><button type="button" onclick="document.getElementById(\'noteFiles\').click();return false">Attach files & photos</button><span>Select or drag files here to upload</span><input type="file" multiple hidden id="noteFiles"></label><div class="rv-note-file-list" id="noteFileList"></div><div class="rv-editor-actions"><button class="rv-small-btn" id="noteCancel" type="button">Cancel</button><button class="rv-small-btn primary" id="noteSave" type="button">Save Note</button></div>'}document.getElementById('rvNotesCard').innerHTML=html;var open=document.getElementById('openNoteEditor');if(open)open.onclick=function(){state.noteEdit=true;state.noteMentions=[];renderNotes();setTimeout(bindNoteEditor,0)};if(state.noteEdit)bindNoteEditor()}
function bindNoteEditor(){var tx=document.getElementById('noteText');if(!tx)return;state.noteMentions=state.noteMentions||[];tx.oninput=function(){showMentionMenu(tx)};document.getElementById('noteCancel').onclick=function(){state.noteEdit=false;renderNotes()};var input=document.getElementById('noteFiles');input.onchange=renderNoteFiles;var drop=document.querySelector('.rv-note-drop');if(drop){drop.ondragover=function(e){e.preventDefault();drop.style.background='#f8fcf6'};drop.ondragleave=function(){drop.style.background=''};drop.ondrop=function(e){e.preventDefault();drop.style.background='';if(e.dataTransfer&&e.dataTransfer.files){try{input.files=e.dataTransfer.files}catch(err){state.noteDroppedFiles=Array.from(e.dataTransfer.files)}renderNoteFiles()}}}document.getElementById('noteSave').onclick=saveNote}
function showMentionMenu(tx){var val=tx.value,pos=tx.selectionStart||0,before=val.slice(0,pos),m=before.match(/@([A-Za-z0-9._ -]*)$/),menu=document.getElementById('mentionMenu');if(!m){menu.classList.remove('show');return}var q=String(m[1]||'').toLowerCase();var users=state.users.filter(function(u){return String(u.name||'').toLowerCase().indexOf(q)>=0}).slice(0,8);menu.innerHTML=users.map(function(u){return '<button class="rv-mention-option" type="button" data-mention="'+u.id+'"><span class="rv-avatar">'+esc(initials(u.name))+'</span><span>'+esc(u.name)+'<small>'+esc(u.job_title||'Team member')+'</small></span></button>'}).join('');menu.classList.toggle('show',users.length>0);menu.querySelectorAll('[data-mention]').forEach(function(b){b.onclick=function(){var uid=Number(b.dataset.mention),u=state.users.find(function(x){return Number(x.id)===uid});var start=before.lastIndexOf('@');tx.value=val.slice(0,start)+'@'+String(u.name).replace(/\s+/g,'_')+' '+val.slice(pos);tx.focus();tx.selectionStart=tx.selectionEnd=start+String(u.name).replace(/\s+/g,'_').length+2;if(state.noteMentions.indexOf(uid)<0)state.noteMentions.push(uid);menu.classList.remove('show')}})}
function renderNoteFiles(){var files=(state.noteDroppedFiles&&state.noteDroppedFiles.length)?state.noteDroppedFiles:Array.from(document.getElementById('noteFiles').files||[]);document.getElementById('noteFileList').innerHTML=files.map(function(f){return '<div class="rv-note-file"><i class="bi bi-paperclip"></i> '+esc(f.name)+'</div>'}).join('')}
function saveNote(){var btn=document.getElementById('noteSave'),files=(state.noteDroppedFiles&&state.noteDroppedFiles.length)?state.noteDroppedFiles:Array.from(document.getElementById('noteFiles').files||[]);btn.disabled=true;api('add_note',{note:document.getElementById('noteText').value,mention_user_ids:JSON.stringify(state.noteMentions||[])},{note_files:files}).then(function(j){state.data.notes=j.notes||[];state.noteEdit=false;state.noteDroppedFiles=[];toast('success',j.message);renderNotes();loadHistoryOnly()}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})}

/* History */
function openHistory(){document.getElementById('historyDrawer').classList.add('show');document.getElementById('historyBackdrop').classList.add('show');document.body.style.overflow='hidden';renderHistory()}
function closeHistory(){document.getElementById('historyDrawer').classList.remove('show');document.getElementById('historyBackdrop').classList.remove('show');document.body.style.overflow=''}
document.getElementById('historyClose').onclick=closeHistory;document.getElementById('historyBackdrop').onclick=closeHistory;
function renderHistoryFilters(){var team=document.getElementById('historyTeam'),type=document.getElementById('historyType');var actors={},types={};state.history.forEach(function(h){if(h.actor_user_id)actors[h.actor_user_id]=h.actor_name||'Team member';if(h.event_type)types[h.event_type]=readable(h.event_type)});team.innerHTML='<option value="">Team | All</option>'+Object.keys(actors).map(function(k){return '<option value="'+esc(k)+'">'+esc(actors[k])+'</option>'}).join('');type.innerHTML='<option value="">Type | All</option>'+Object.keys(types).map(function(k){return '<option value="'+esc(k)+'">'+esc(types[k])+'</option>'}).join('');team.onchange=renderHistory;type.onchange=renderHistory;document.getElementById('historyDate').onchange=renderHistory}
function historyDetail(h){var d=h.details||{};if(h.event_type==='request_status_changed')return '<em>'+esc(readable(d.old_status||'empty'))+'</em> → <strong>'+esc(readable(d.new_status||''))+'</strong>'+(d.notes?'<div>'+esc(d.notes)+'</div>':'');if(d.note)return esc(d.note);if(d.old&&d.new&&d.old.description!==d.new.description)return '<em>Service details updated</em>';return ''}
function renderHistory(){var team=document.getElementById('historyTeam').value,type=document.getElementById('historyType').value,days=Number(document.getElementById('historyDate').value||0),cut=days?Date.now()-days*86400000:0;var rows=state.history.filter(function(h){if(team&&String(h.actor_user_id)!==String(team))return false;if(type&&h.event_type!==type)return false;if(cut&&new Date(String(h.created_at).replace(' ','T')).getTime()<cut)return false;return true});document.getElementById('historyList').innerHTML=rows.length?rows.map(function(h){return '<div class="rv-history-item"><div class="rv-history-avatar">'+esc(initials(h.actor_name||'System'))+'</div><div><strong>'+esc(h.title||readable(h.event_type))+'</strong><small>'+esc((h.actor_name||'System')+' · '+formatDate(h.created_at,true))+'</small><div class="rv-history-detail">'+historyDetail(h)+'</div></div></div>'}).join(''):'<div class="rv-history-empty">No matching request activity.</div>'}
function loadHistoryOnly(){api('load',{}).then(function(j){state.history=j.data.history||[];state.data.notes=j.data.notes||state.data.notes;state.bookingEmail=j.data.booking_email||state.bookingEmail;renderHistoryFilters();renderHistory()}).catch(function(){})}

/* Checklist */
function newChecklistModel(){return {name:'New checklist',description:'',sections:[{title:'Section 1',questions:[{title:'Question',question_type:'short_answer',options:[],is_required:0}]}]}}
function openChecklist(){if(!state.checklistBuilder)state.checklistBuilder=newChecklistModel();document.getElementById('checklistName').value=state.checklistBuilder.name||'New checklist';renderChecklist();openModal('checklistModal')}
function renderChecklist(){var m=state.checklistBuilder||newChecklistModel();document.getElementById('checklistCanvas').innerHTML=m.sections.map(function(s,si){return '<div class="rv-check-section" data-section="'+si+'"><div class="rv-check-section-head"><input value="'+esc(s.title)+'" data-section-title="'+si+'"><button class="rv-remove-line" type="button" data-section-delete="'+si+'"><i class="bi bi-trash"></i></button></div><div>'+s.questions.map(function(q,qi){return checklistQuestion(q,si,qi)}).join('')+'</div><div style="padding:10px"><button class="rv-link-btn" type="button" data-add-question="'+si+'">+ Add Question</button></div></div>'}).join('');bindChecklist()}
function checklistQuestion(q,si,qi){var opts='';if(q.question_type==='dropdown'||q.question_type==='checkbox'){opts='<div class="rv-check-options">'+(q.options||[]).map(function(o,oi){return '<div class="rv-check-option-row"><input class="rv-input" value="'+esc(o)+'" data-option="'+si+','+qi+','+oi+'"><button class="rv-remove-line" style="height:37px" type="button" data-remove-option="'+si+','+qi+','+oi+'"><i class="bi bi-x"></i></button></div>'}).join('')+'<button class="rv-link-btn" type="button" data-add-option="'+si+','+qi+'">+ Add option</button></div>'}return '<div class="rv-check-question"><div class="rv-check-q-grid"><input class="rv-input" value="'+esc(q.title)+'" data-q-title="'+si+','+qi+'"><select class="rv-select" data-q-type="'+si+','+qi+'">'+[['short_answer','Short answer'],['long_answer','Long answer'],['dropdown','Dropdown'],['checkbox','Checkbox'],['number','Numerical answer'],['image','Upload images'],['date','Date picker'],['signature','Signature']].map(function(x){return '<option value="'+x[0]+'" '+(q.question_type===x[0]?'selected':'')+'>'+x[1]+'</option>'}).join('')+'</select><button class="rv-remove-line" style="height:43px" type="button" data-q-delete="'+si+','+qi+'"><i class="bi bi-trash"></i></button></div>'+opts+'<label class="rv-check-required"><input type="checkbox" data-q-required="'+si+','+qi+'" '+(q.is_required?'checked':'')+'> Required</label></div>'}
function parsePair(v){return String(v).split(',').map(Number)}
function bindChecklist(){document.querySelectorAll('[data-section-title]').forEach(function(e){e.oninput=function(){state.checklistBuilder.sections[Number(e.dataset.sectionTitle)].title=e.value}});document.querySelectorAll('[data-section-delete]').forEach(function(e){e.onclick=function(){state.checklistBuilder.sections.splice(Number(e.dataset.sectionDelete),1);if(!state.checklistBuilder.sections.length)state.checklistBuilder.sections.push({title:'Section 1',questions:[]});renderChecklist()}});document.querySelectorAll('[data-add-question]').forEach(function(e){e.onclick=function(){state.checklistBuilder.sections[Number(e.dataset.addQuestion)].questions.push({title:'Question',question_type:'short_answer',options:[],is_required:0});renderChecklist()}});document.querySelectorAll('[data-q-title]').forEach(function(e){e.oninput=function(){var p=parsePair(e.dataset.qTitle);state.checklistBuilder.sections[p[0]].questions[p[1]].title=e.value}});document.querySelectorAll('[data-q-type]').forEach(function(e){e.onchange=function(){var p=parsePair(e.dataset.qType),q=state.checklistBuilder.sections[p[0]].questions[p[1]];q.question_type=e.value;if((e.value==='dropdown'||e.value==='checkbox')&&!q.options.length)q.options=['Option 1'];renderChecklist()}});document.querySelectorAll('[data-q-delete]').forEach(function(e){e.onclick=function(){var p=parsePair(e.dataset.qDelete);state.checklistBuilder.sections[p[0]].questions.splice(p[1],1);renderChecklist()}});document.querySelectorAll('[data-q-required]').forEach(function(e){e.onchange=function(){var p=parsePair(e.dataset.qRequired);state.checklistBuilder.sections[p[0]].questions[p[1]].is_required=e.checked?1:0}});document.querySelectorAll('[data-option]').forEach(function(e){e.oninput=function(){var p=String(e.dataset.option).split(',').map(Number);state.checklistBuilder.sections[p[0]].questions[p[1]].options[p[2]]=e.value}});document.querySelectorAll('[data-add-option]').forEach(function(e){e.onclick=function(){var p=parsePair(e.dataset.addOption);state.checklistBuilder.sections[p[0]].questions[p[1]].options.push('Option '+(state.checklistBuilder.sections[p[0]].questions[p[1]].options.length+1));renderChecklist()}});document.querySelectorAll('[data-remove-option]').forEach(function(e){e.onclick=function(){var p=String(e.dataset.removeOption).split(',').map(Number);state.checklistBuilder.sections[p[0]].questions[p[1]].options.splice(p[2],1);renderChecklist()}})}
document.querySelectorAll('[data-check-add]').forEach(function(b){b.onclick=function(){var type=b.dataset.checkAdd;if(!state.checklistBuilder)state.checklistBuilder=newChecklistModel();if(type==='section'){state.checklistBuilder.sections.push({title:'Section '+(state.checklistBuilder.sections.length+1),questions:[]})}else{var s=state.checklistBuilder.sections[state.checklistBuilder.sections.length-1];s.questions.push({title:'Question',question_type:type,options:(type==='dropdown'||type==='checkbox')?['Option 1']:[],is_required:0})}renderChecklist()}});
document.getElementById('saveChecklistBtn').onclick=function(){var name=document.getElementById('checklistName').value.trim();if(!name){toast('warning','Enter a checklist title.');return}state.checklistBuilder.name=name;var items=[];state.checklistBuilder.sections.forEach(function(s){s.questions.forEach(function(q){if(String(q.title||'').trim())items.push({section_title:s.title,title:q.title,question_type:q.question_type,options:q.options||[],is_required:q.is_required?1:0})})});if(!items.length){toast('warning','Add at least one checklist question.');return}state.checklistDraft={name:name,description:'',items:items};closeModal('checklistModal');var sum=document.getElementById('checklistDraftSummary');if(sum)sum.innerHTML=checklistSelectionChips();toast('success','Checklist added.')};

/* Booking confirmation email */
function openBookingEmail(){var b=state.bookingEmail||{};if(!state.assessment){toast('warning','Create the on-site assessment first.');return}if(Number(b.enabled==null?1:b.enabled)!==1){toast('warning','Assessment booking confirmation email is disabled in Email Settings.');return}if(Number(b.available||0)!==1){toast('warning',b.reason||'Schedule the assessment before sending the booking confirmation.');return}document.getElementById('bookingEmailTo').value=b.to||((state.data.request||{}).client_email||'');document.getElementById('bookingEmailSubject').value=b.subject||'';document.getElementById('bookingEmailMessage').value=b.message||'';document.getElementById('bookingEmailTemplateSource').textContent='Template: '+(b.template_name||'Default booking confirmation');var sent=document.getElementById('bookingEmailSentNote');if(Number(b.sent||0)===1){sent.style.display='flex';sent.querySelector('span').textContent='Previously sent'+(b.sent_at?' on '+formatDate(b.sent_at,true):'')+'. Sending again will create a resend record.'}else sent.style.display='none';document.getElementById('bookingEmailTitle').textContent=Number(b.sent||0)===1?'Resend booking confirmation':'Email booking confirmation';document.getElementById('sendBookingEmailBtn').textContent=Number(b.sent||0)===1?'Resend Email':'Send Email';openModal('bookingEmailModal')}
document.getElementById('sendBookingEmailBtn').onclick=function(){var btn=this,to=document.getElementById('bookingEmailTo').value.trim(),subject=document.getElementById('bookingEmailSubject').value.trim(),message=document.getElementById('bookingEmailMessage').value.trim();if(!to||!subject||!message){toast('warning','Enter recipient, subject and message.');return}btn.disabled=true;var original=btn.textContent;btn.textContent='Sending...';api('send_booking_confirmation',{to:to,subject:subject,message:message}).then(function(j){state.bookingEmail=j.booking_email||state.bookingEmail;closeModal('bookingEmailModal');toast('success',j.message||'Booking confirmation email sent.');renderMain();loadHistoryOnly()}).catch(function(e){toast('error',e.message,6000)}).finally(function(){btn.disabled=false;btn.textContent=original})};

/* Confirm */
var pendingConfirm=null;function confirmAction(title,text,fn){pendingConfirm=fn;document.getElementById('confirmTitle').textContent=title;document.getElementById('confirmText').textContent=text;openModal('confirmModal')}document.getElementById('confirmActionBtn').onclick=function(){var fn=pendingConfirm;pendingConfirm=null;closeModal('confirmModal');if(fn)fn()};

window.addEventListener('error',function(ev){try{if(ev&&ev.message)renderError('Request page JavaScript error: '+ev.message)}catch(ignore){}});
window.addEventListener('unhandledrejection',function(ev){try{var reason=ev&&ev.reason;renderError('Request page error: '+(reason&&reason.message?reason.message:String(reason||'Unknown error')))}catch(ignore){}});
load();
})();
</script>


<script>
(function(){
  var layout=document.getElementById('requestLayout');
  var toggle=document.getElementById('requestAsideToggle');
  var aside=document.querySelector('.cv-aside');
  var key='fieldplx.requestView.asideCollapsed';
  var scrollKey='fieldplx.requestView.asideScroll.'+<?= (int)$requestId ?>;
  try{if(localStorage.getItem(key)==='1')layout.classList.add('aside-collapsed');}catch(e){}
  if(toggle)toggle.addEventListener('click',function(){layout.classList.toggle('aside-collapsed');try{localStorage.setItem(key,layout.classList.contains('aside-collapsed')?'1':'0');}catch(e){}});
  if(aside){try{var y=parseInt(sessionStorage.getItem(scrollKey)||'0',10);if(y>0)requestAnimationFrame(function(){aside.scrollTop=y;});}catch(e){}aside.addEventListener('scroll',function(){try{sessionStorage.setItem(scrollKey,String(aside.scrollTop));}catch(e){}},{passive:true});}
  var historySide=document.getElementById('requestHistorySideButton');
  if(historySide)historySide.addEventListener('click',function(){var b=document.getElementById('historyOpen');if(b)b.click();});
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
