<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Invoices Jobber-style List API - Version 3.1.0 - 2026-09-07
|--------------------------------------------------------------------------
| List/dashboard metrics + invoice email + safe delete/cancel action.
| PHP 7.2 compatible.
*/
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function ijlResponse($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message,
        'api_version' => '3.1.0'
    ), is_array($extra) ? $extra : array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ijlPost($key, $default = '')
{
    return isset($_POST[$key]) && !is_array($_POST[$key]) ? $_POST[$key] : $default;
}

function ijlTable(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function ijlColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $stmt->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function ijlInvoicePermission(PDO $pdo, $tenantId, $userId, $action)
{
    $action = strtolower(trim((string)$action));
    if (!in_array($action, array('view','create','update','delete','approve','export'), true)) return false;
    if ((function_exists('isPlatformSuperAdmin') && isPlatformSuperAdmin()) || !empty($_SESSION['is_super_admin'])) return true;

    $roleId = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
    $planId = isset($_SESSION['plan_id']) ? (int)$_SESSION['plan_id'] : 0;
    if ($tenantId <= 0 || $userId <= 0 || $roleId <= 0) return false;
    if (!ijlTable($pdo,'modules') || !ijlTable($pdo,'permissions') || !ijlTable($pdo,'role_permissions')) return false;

    try {
        $m = $pdo->prepare("SELECT id FROM modules WHERE module_code='invoices' AND is_active=1 LIMIT 1");
        $m->execute();
        $moduleId = (int)$m->fetchColumn();
        if ($moduleId <= 0) return false;

        if (ijlTable($pdo,'plan_modules')) {
            if ($planId <= 0) return false;
            $pm = $pdo->prepare("SELECT COUNT(*) FROM plan_modules WHERE plan_id=:p AND module_id=:m AND is_enabled=1");
            $pm->execute(array(':p'=>$planId, ':m'=>$moduleId));
            if ((int)$pm->fetchColumn() <= 0) return false;
        }

        if (ijlTable($pdo,'tenant_modules')) {
            $tm = $pdo->prepare("SELECT access_type FROM tenant_modules WHERE tenant_id=:t AND module_id=:m LIMIT 1");
            $tm->execute(array(':t'=>$tenantId, ':m'=>$moduleId));
            if (strtolower(trim((string)$tm->fetchColumn())) === 'disabled') return false;
        }

        $ps = $pdo->prepare("SELECT id FROM permissions WHERE module_id=:m AND (action_code=:a OR permission_code=:c) ORDER BY CASE WHEN permission_code=:c2 THEN 0 ELSE 1 END,id LIMIT 1");
        $ps->execute(array(':m'=>$moduleId, ':a'=>$action, ':c'=>'invoices.'.$action, ':c2'=>'invoices.'.$action));
        $permissionId = (int)$ps->fetchColumn();
        if ($permissionId <= 0) return false;

        $rp = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $rp->execute(array(':t'=>$tenantId, ':r'=>$roleId, ':p'=>$permissionId));
        $effective = strtolower(trim((string)$rp->fetchColumn()));

        if (ijlTable($pdo,'user_permissions')) {
            $up = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
            $up->execute(array(':t'=>$tenantId, ':u'=>$userId, ':p'=>$permissionId));
            $userAccess = strtolower(trim((string)$up->fetchColumn()));
            if ($userAccess !== '') $effective = $userAccess;
        }
        return $effective === 'allow';
    } catch (Throwable $e) {
        error_log('Invoice list permission check: ' . $e->getMessage());
        return false;
    }
}

function ijlCanSeeAllBranches(PDO $pdo, $tenantId)
{
    if ((function_exists('isPlatformSuperAdmin') && isPlatformSuperAdmin()) || !empty($_SESSION['is_super_admin'])) return true;
    if (!empty($_SESSION['tenant_user_is_admin']) || !empty($_SESSION['role_is_admin'])) return true;
    $roleId = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 0;
    if ($roleId > 0 && ijlTable($pdo,'roles') && ijlColumn($pdo,'roles','scope_type')) {
        try {
            $q = $pdo->prepare("SELECT scope_type FROM roles WHERE id=:r AND tenant_id=:t LIMIT 1");
            $q->execute(array(':r'=>$roleId, ':t'=>$tenantId));
            if (strtolower(trim((string)$q->fetchColumn())) === 'business') return true;
        } catch (Throwable $e) {
            error_log('Invoice branch scope check: ' . $e->getMessage());
        }
    }
    return false;
}

function ijlValidDate($value)
{
    $value = trim((string)$value);
    if ($value === '') return true;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

function ijlCurrency(PDO $pdo, $tenantId, $branchId)
{
    try {
        if ($branchId > 0) {
            $stmt = $pdo->prepare("SELECT c.id,c.currency_code,c.currency_name,c.symbol,c.symbol_position,c.decimal_places,c.decimal_separator,c.thousand_separator FROM tenants t LEFT JOIN branches b ON b.id=:b AND b.tenant_id=t.id INNER JOIN currencies c ON c.id=COALESCE(b.currency_id,t.currency_id) WHERE t.id=:t LIMIT 1");
            $stmt->execute(array(':b'=>$branchId, ':t'=>$tenantId));
        } else {
            $stmt = $pdo->prepare("SELECT c.id,c.currency_code,c.currency_name,c.symbol,c.symbol_position,c.decimal_places,c.decimal_separator,c.thousand_separator FROM tenants t INNER JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1");
            $stmt->execute(array(':t'=>$tenantId));
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Throwable $e) {
        error_log('Invoice list currency: ' . $e->getMessage());
    }
    return array(
        'id'=>null,
        'currency_code'=>'',
        'currency_name'=>'',
        'symbol'=>'',
        'symbol_position'=>'before',
        'decimal_places'=>2,
        'decimal_separator'=>'.',
        'thousand_separator'=>','
    );
}

function ijlMoney($value, $currency)
{
    $places = isset($currency['decimal_places']) ? (int)$currency['decimal_places'] : 2;
    $places = max(0, min(4, $places));
    $number = number_format((float)$value, $places, '.', ',');
    $symbol = isset($currency['symbol']) ? (string)$currency['symbol'] : '';
    $position = isset($currency['symbol_position']) ? (string)$currency['symbol_position'] : 'before';
    if ($symbol === '') return $number;
    return $position === 'after' ? $number . ' ' . $symbol : $symbol . $number;
}

function ijlPaymentState($row)
{
    $status = strtolower((string)(isset($row['status']) ? $row['status'] : ''));
    $balance = (float)(isset($row['balance_due']) ? $row['balance_due'] : 0);
    $paid = (float)(isset($row['amount_paid']) ? $row['amount_paid'] : 0);
    $dueDate = !empty($row['due_date']) ? substr((string)$row['due_date'], 0, 10) : '';
    $today = date('Y-m-d');

    if (in_array($status, array('cancelled','archived','written_off'), true)) return $status;
    if ($status === 'draft') return 'draft';
    if ($balance <= 0.005 || $status === 'paid') return 'paid';
    if ($dueDate !== '' && $dueDate < $today) return 'overdue';
    if ($paid > 0.005) return 'partially_paid';
    return 'awaiting_payment';
}

function ijlLoadVendor()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && class_exists('Dompdf\\Dompdf')) return true;
    $paths = array(
        __DIR__ . '/../vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
        __DIR__ . '/../mailer/vendor/autoload.php',
        dirname(__DIR__, 2) . '/mailer/vendor/autoload.php'
    );
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
        }
    }
    return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
}

function ijlSmtpSecretKey()
{
    if (!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $files = array(
            __DIR__ . '/../includes/smtp-secret.php',
            dirname(__DIR__, 2) . '/includes/smtp-secret.php'
        );
        foreach ($files as $file) {
            if (is_file($file)) {
                require_once $file;
                if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) break;
            }
        }
    }

    $key = defined('FIELDPLX_SMTP_ENCRYPTION_KEY') ? trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY) : '';
    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) $key = trim((string)$env);
    }
    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) $key = trim((string)$env);
    }
    if ($key === '' || strlen($key) < 32) {
        throw new RuntimeException('SMTP encryption key is not configured.');
    }
    return hash('sha256', $key, true);
}

function ijlLegacySmtpKeys()
{
    $sources = array(
        dirname(__DIR__) . '/api|fieldplx|smtp|credential-protection',
        dirname(__DIR__) . '|fieldplx|smtp|credential-protection'
    );
    $keys = array();
    foreach ($sources as $source) {
        $legacyTextKey = hash('sha256', $source);
        $keys[] = hash('sha256', $legacyTextKey, true);
    }
    return $keys;
}

function ijlDecryptSmtpPassword($stored)
{
    $stored = trim((string)$stored);
    if ($stored === '') return '';
    if (strpos($stored, 'v1:') !== 0) {
        throw new RuntimeException('SMTP password uses an unsupported encryption format. Re-save it in Master Controls.');
    }
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required to decrypt the SMTP password.');
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException('Stored SMTP password is invalid.');
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);

    $primaryError = null;
    try {
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', ijlSmtpSecretKey(), OPENSSL_RAW_DATA, $iv);
        if ($plain !== false && $plain !== '') return $plain;
    } catch (Throwable $e) {
        $primaryError = $e;
    }
    foreach (ijlLegacySmtpKeys() as $key) {
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain !== false && $plain !== '') return $plain;
    }
    if ($primaryError) throw $primaryError;
    throw new RuntimeException('Unable to decrypt the SMTP password. Re-save the SMTP password in Master Controls.');
}

function ijlSmtpConfiguration(PDO $pdo, $tenantId, $branchId)
{
    if (!ijlTable($pdo, 'smtp_configurations')) return null;
    if ($branchId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM smtp_configurations WHERE is_active=1 AND ((scope_type='branch' AND tenant_id=:t AND branch_id=:b) OR (scope_type='tenant' AND tenant_id=:t2) OR (scope_type='platform' AND tenant_id IS NULL)) ORDER BY CASE WHEN scope_type='branch' THEN 0 WHEN scope_type='tenant' THEN 1 ELSE 2 END,is_default DESC,id DESC LIMIT 1");
        $stmt->execute(array(':t'=>$tenantId, ':b'=>$branchId, ':t2'=>$tenantId));
    } else {
        $stmt = $pdo->prepare("SELECT * FROM smtp_configurations WHERE is_active=1 AND ((scope_type='tenant' AND tenant_id=:t) OR (scope_type='platform' AND tenant_id IS NULL)) ORDER BY CASE WHEN scope_type='tenant' THEN 0 ELSE 1 END,is_default DESC,id DESC LIMIT 1");
        $stmt->execute(array(':t'=>$tenantId));
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function ijlCreateMailer(PDO $pdo, $tenantId, $branchId)
{
    if (!ijlLoadVendor() || !class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('PHPMailer is not installed.');
    }
    $config = ijlSmtpConfiguration($pdo, $tenantId, $branchId);
    if (!$config) throw new RuntimeException('No active SMTP configuration is available for this company.');

    $password = ijlDecryptSmtpPassword(isset($config['password_encrypted']) ? $config['password_encrypted'] : '');
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string)$config['host']);
    $mail->Port = (int)$config['port'];
    $mail->Timeout = 20;
    if (property_exists($mail, 'Timelimit')) $mail->Timelimit = 20;
    $mail->SMTPDebug = 0;

    $username = trim((string)$config['username']);
    $mail->SMTPAuth = $username !== '';
    if ($mail->SMTPAuth) {
        if ($password === '') throw new RuntimeException('SMTP password is empty. Re-save the SMTP password in Master Controls.');
        $mail->Username = $username;
        $mail->Password = $password;
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
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $fromEmail = $username;
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The configured SMTP From Email is invalid.');
    $fromName = trim((string)$config['from_name']);
    if ($fromName === '') $fromName = 'FieldPlx';
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);

    $replyTo = trim((string)$config['reply_to_email']);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
    return $mail;
}

function ijlParseEmails($raw)
{
    $parts = preg_split('/[;,\\s]+/', trim((string)$raw));
    $out = array();
    foreach ($parts as $email) {
        $email = strtolower(trim((string)$email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $out[$email] = $email;
    }
    return array_values($out);
}

function ijlEmailAttachments()
{
    $out = array();
    if (empty($_FILES['email_attachments']) || !is_array($_FILES['email_attachments'])) return $out;
    $f = $_FILES['email_attachments'];
    $names = isset($f['name']) && is_array($f['name']) ? $f['name'] : array(isset($f['name']) ? $f['name'] : '');
    $total = 0;
    foreach ($names as $idx => $name) {
        $error = is_array($f['error']) ? (int)$f['error'][$idx] : (int)$f['error'];
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('One email attachment could not be uploaded.');
        $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$idx] : $f['tmp_name'];
        $size = is_array($f['size']) ? (int)$f['size'][$idx] : (int)$f['size'];
        if (!is_uploaded_file($tmp)) throw new RuntimeException('One email attachment is invalid.');
        $total += $size;
        if ($total > 10 * 1024 * 1024) throw new RuntimeException('Email attachments cannot exceed 10 MB in total.');
        $safe = preg_replace('/[^A-Za-z0-9._() -]/', '_', basename((string)$name));
        if ($safe === '') $safe = 'attachment';
        $out[] = array('tmp'=>$tmp, 'name'=>$safe, 'size'=>$size);
    }
    return $out;
}

function ijlInvoiceEmailHtml($invoice, $items, $currency, $message)
{
    $e = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $company = !empty($invoice['branch_name']) ? $invoice['branch_name'] : (!empty($invoice['tenant_name']) ? $invoice['tenant_name'] : 'FieldPlx');
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:auto;color:#173946;background:#fff">';
    $html .= '<div style="padding:20px 22px;background:#001131;color:#fff"><div style="font-size:12px;opacity:.8">'.$e($company).'</div><h2 style="margin:5px 0 0;font-size:22px">Invoice '.$e($invoice['invoice_no']).'</h2></div>';
    $html .= '<div style="padding:22px;border:1px solid #e2e8ec;border-top:0">';
    if (trim((string)$message) !== '') $html .= '<div style="white-space:pre-line;line-height:1.65;margin-bottom:18px">'.nl2br($e($message)).'</div>';
    $html .= '<div style="padding:13px 15px;background:#f7f9fb;border-radius:8px;line-height:1.75"><strong>Invoice:</strong> '.$e($invoice['invoice_no']).'<br><strong>Issued:</strong> '.$e($invoice['issue_date']).'<br><strong>Due:</strong> '.$e($invoice['due_date']).'<br><strong>Total:</strong> '.$e(ijlMoney($invoice['total'],$currency)).'<br><strong>Balance:</strong> '.$e(ijlMoney($invoice['balance_due'],$currency)).'</div>';
    if ($items) {
        $html .= '<table style="width:100%;border-collapse:collapse;margin-top:18px"><thead><tr><th style="padding:9px;text-align:left;border-bottom:2px solid #dfe6ea">Product / Service</th><th style="padding:9px;text-align:center;border-bottom:2px solid #dfe6ea">Qty</th><th style="padding:9px;text-align:right;border-bottom:2px solid #dfe6ea">Total</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $qty = rtrim(rtrim(number_format((float)$item['quantity'], 3, '.', ''), '0'), '.');
            $html .= '<tr><td style="padding:9px;border-bottom:1px solid #e7ebee">'.$e($item['item_name']).'</td><td style="padding:9px;text-align:center;border-bottom:1px solid #e7ebee">'.$e($qty).'</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e7ebee">'.$e(ijlMoney($item['line_total'],$currency)).'</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $html .= '<p style="margin:20px 0 0">Thank you,<br>'.$e($company).'</p></div></div>';
    return $html;
}

function ijlAttachInvoicePdf($mail, $invoice, $items, $currency)
{
    ijlLoadVendor();
    if (!class_exists('Dompdf\\Dompdf')) return false;
    try {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml(ijlInvoiceEmailHtml($invoice, $items, $currency, ''), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();
        if ($pdf !== '') {
            $name = 'invoice_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$invoice['invoice_no']) . '.pdf';
            $mail->addStringAttachment($pdf, $name, 'base64', 'application/pdf');
            return true;
        }
    } catch (Throwable $e) {
        error_log('Invoice list PDF attachment: ' . $e->getMessage());
    }
    return false;
}

$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$sessionBranchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
if ($tenantId <= 0 || $userId <= 0) {
    ijlResponse(401, false, 'Authentication required.');
}

$permissions = array(
    'view' => ijlInvoicePermission($pdo,$tenantId,$userId,'view'),
    'create' => ijlInvoicePermission($pdo,$tenantId,$userId,'create'),
    'update' => ijlInvoicePermission($pdo,$tenantId,$userId,'update'),
    'delete' => ijlInvoicePermission($pdo,$tenantId,$userId,'delete')
);
if (!$permissions['view']) {
    ijlResponse(403, false, 'Access denied. Your role does not have permission to view invoices.');
}
$canSeeAllBranches = ijlCanSeeAllBranches($pdo,$tenantId);

$csrf = trim((string)ijlPost('csrf_token', ''));
$sessionCsrf = isset($_SESSION['invoices_csrf_token']) ? (string)$_SESSION['invoices_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    ijlResponse(419, false, 'Your invoice page session expired. Refresh and try again.');
}

$action = trim((string)ijlPost('action', 'list'));

try {
    if ($action === 'list') {
        if (!ijlTable($pdo, 'invoices') || !ijlTable($pdo, 'clients')) {
            ijlResponse(500, false, 'Invoice or client tables are not available.');
        }

        $page = max(1, (int)ijlPost('page', 1));
        $perPage = (int)ijlPost('per_page', 10);
        if (!in_array($perPage, array(10,25,50), true)) $perPage = 10;

        $search = trim((string)ijlPost('search', ''));
        $status = strtolower(trim((string)ijlPost('status', '')));
        $paymentStatus = strtolower(trim((string)ijlPost('payment_status', '')));
        $requestedBranchId = max(0, (int)ijlPost('branch_id', 0));
        $branchId = (!$canSeeAllBranches && $sessionBranchId > 0) ? $sessionBranchId : $requestedBranchId;
        $branchScopeHasNoAssignment = (!$canSeeAllBranches && $sessionBranchId <= 0);
        $dateType = trim((string)ijlPost('date_type', 'issue_date'));
        $fromDate = trim((string)ijlPost('from_date', ''));
        $toDate = trim((string)ijlPost('to_date', ''));
        $sortKey = trim((string)ijlPost('sort_key', 'due_date'));
        $sortDir = strtoupper(trim((string)ijlPost('sort_dir', 'ASC'))) === 'DESC' ? 'DESC' : 'ASC';

        if (!in_array($dateType, array('issue_date','due_date','created_at'), true)) $dateType = 'issue_date';
        if (!ijlValidDate($fromDate) || !ijlValidDate($toDate)) ijlResponse(422, false, 'Select a valid date range.');
        if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) ijlResponse(422, false, 'From date cannot be later than To date.');

        $allowedStatuses = array('', 'draft','sent','viewed','partially_paid','paid','overdue','awaiting_payment','written_off','cancelled','archived');
        if (!in_array($status, $allowedStatuses, true)) ijlResponse(422, false, 'Select a valid invoice status.');
        $allowedPayments = array('', 'outstanding','unpaid','partial','partially_paid','paid','overdue');
        if (!in_array($paymentStatus, $allowedPayments, true)) ijlResponse(422, false, 'Select a valid payment status.');

        $where = array('i.tenant_id=:tenant_id');
        $params = array(':tenant_id'=>$tenantId);

        /* Jobber-like normal list hides deleted/cancelled and archived invoices. */
        if ($status === '') {
            $where[] = "i.status NOT IN ('cancelled','archived')";
        } elseif ($status === 'overdue') {
            $where[] = "i.status NOT IN ('cancelled','archived','written_off') AND i.balance_due>0.005 AND i.due_date IS NOT NULL AND DATE(i.due_date)<CURDATE()";
        } elseif ($status === 'awaiting_payment') {
            $where[] = "i.status NOT IN ('draft','paid','cancelled','archived','written_off') AND i.balance_due>0.005 AND (i.due_date IS NULL OR DATE(i.due_date)>=CURDATE())";
        } elseif ($status === 'paid') {
            $where[] = "(i.status='paid' OR i.balance_due<=0.005) AND i.status NOT IN ('cancelled','archived','written_off')";
        } else {
            $where[] = 'i.status=:status';
            $params[':status'] = $status;
        }

        if ($paymentStatus === 'outstanding') {
            $where[] = "i.balance_due>0.005 AND i.status NOT IN ('cancelled','archived','written_off')";
        } elseif ($paymentStatus === 'unpaid') {
            $where[] = "i.amount_paid<=0.005 AND i.balance_due>0.005 AND i.status NOT IN ('cancelled','archived','written_off')";
        } elseif ($paymentStatus === 'partial' || $paymentStatus === 'partially_paid') {
            $where[] = "i.amount_paid>0.005 AND i.balance_due>0.005 AND i.status NOT IN ('cancelled','archived','written_off')";
        } elseif ($paymentStatus === 'paid') {
            $where[] = "i.balance_due<=0.005 AND i.status NOT IN ('cancelled','archived','written_off')";
        } elseif ($paymentStatus === 'overdue') {
            $where[] = "i.balance_due>0.005 AND i.due_date IS NOT NULL AND DATE(i.due_date)<CURDATE() AND i.status NOT IN ('cancelled','archived','written_off')";
        }

        if ($branchScopeHasNoAssignment) {
            $where[] = '1=0';
        } elseif ($branchId > 0) {
            $where[] = 'i.branch_id=:branch_id';
            $params[':branch_id'] = $branchId;
        }
        if ($fromDate !== '') {
            $where[] = 'DATE(i.' . $dateType . ')>=:from_date';
            $params[':from_date'] = $fromDate;
        }
        if ($toDate !== '') {
            $where[] = 'DATE(i.' . $dateType . ')<=:to_date';
            $params[':to_date'] = $toDate;
        }
        if ($search !== '') {
            $sv = '%' . $search . '%';
            $where[] = "(i.invoice_no LIKE :s1 OR COALESCE(c.display_name,'') LIKE :s2 OR COALESCE(c.company_name,'') LIKE :s3 OR COALESCE(c.email,'') LIKE :s4 OR COALESCE(c.phone,'') LIKE :s5 OR " . (ijlColumn($pdo,'invoices','subject') ? "COALESCE(i.subject,'') LIKE :s6" : "'' LIKE :s6") . " OR COALESCE(j.job_no,'') LIKE :s7 OR COALESCE(q.quote_no,'') LIKE :s8)";
            $params[':s1']=$sv; $params[':s2']=$sv; $params[':s3']=$sv; $params[':s4']=$sv;
            $params[':s5']=$sv; $params[':s6']=$sv; $params[':s7']=$sv; $params[':s8']=$sv;
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM invoices i INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id LEFT JOIN jobs j ON j.id=i.job_id AND j.tenant_id=i.tenant_id LEFT JOIN quotes q ON q.id=i.quote_id AND q.tenant_id=i.tenant_id WHERE " . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $subjectSelect = ijlColumn($pdo,'invoices','subject') ? 'i.subject' : "'For Services Rendered' AS subject";
        $sortMap = array(
            'client'=>'c.display_name',
            'invoice_no'=>'i.invoice_no',
            'due_date'=>'i.due_date',
            'subject'=>ijlColumn($pdo,'invoices','subject') ? 'i.subject' : 'i.id',
            'status'=>'i.status',
            'total'=>'i.total',
            'balance'=>'i.balance_due'
        );
        if (!isset($sortMap[$sortKey])) $sortKey = 'due_date';
        $orderBy = $sortMap[$sortKey] . ' ' . $sortDir . ', i.id DESC';

        $sql = "SELECT i.id,i.invoice_no,i.branch_id,i.client_id,i.job_id,i.quote_id,i.status,i.issue_date,i.due_date,i.total,i.amount_paid,i.balance_due,i.created_at,i.sent_at,i.viewed_at,i.paid_at," . $subjectSelect . ",c.display_name AS client_name,c.company_name AS client_company,c.email AS client_email,c.phone AS client_phone,b.name AS branch_name,b.branch_code,j.job_no,j.title AS job_title,q.quote_no FROM invoices i INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id LEFT JOIN branches b ON b.id=i.branch_id AND b.tenant_id=i.tenant_id LEFT JOIN jobs j ON j.id=i.job_id AND j.tenant_id=i.tenant_id LEFT JOIN quotes q ON q.id=i.quote_id AND q.tenant_id=i.tenant_id WHERE " . $whereSql . " ORDER BY " . $orderBy . " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['payment_state'] = ijlPaymentState($row);
        }
        unset($row);

        $summaryWhere = array("i.tenant_id=:summary_t", "i.status NOT IN ('cancelled','archived','written_off')");
        $summaryParams = array(':summary_t'=>$tenantId);
        if ($branchScopeHasNoAssignment) {
            $summaryWhere[] = '1=0';
        } elseif ($branchId > 0) {
            $summaryWhere[] = 'i.branch_id=:summary_b';
            $summaryParams[':summary_b'] = $branchId;
        }
        $summarySql = "SELECT
            SUM(CASE WHEN i.balance_due>0.005 AND i.due_date IS NOT NULL AND DATE(i.due_date)<CURDATE() THEN 1 ELSE 0 END) AS past_due_count,
            COALESCE(SUM(CASE WHEN i.balance_due>0.005 AND i.due_date IS NOT NULL AND DATE(i.due_date)<CURDATE() THEN i.balance_due ELSE 0 END),0) AS past_due_amount,
            SUM(CASE WHEN i.status NOT IN ('draft','paid') AND i.balance_due>0.005 AND (i.due_date IS NULL OR DATE(i.due_date)>=CURDATE()) THEN 1 ELSE 0 END) AS sent_not_due_count,
            COALESCE(SUM(CASE WHEN i.status NOT IN ('draft','paid') AND i.balance_due>0.005 AND (i.due_date IS NULL OR DATE(i.due_date)>=CURDATE()) THEN i.balance_due ELSE 0 END),0) AS sent_not_due_amount,
            SUM(CASE WHEN i.status='draft' THEN 1 ELSE 0 END) AS draft_count,
            COALESCE(SUM(CASE WHEN i.status='draft' THEN i.total ELSE 0 END),0) AS draft_amount,
            SUM(CASE WHEN i.status<>'draft' AND DATE(COALESCE(i.issue_date,i.created_at))>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) THEN 1 ELSE 0 END) AS issued_30_count,
            COALESCE(SUM(CASE WHEN i.status<>'draft' AND DATE(COALESCE(i.issue_date,i.created_at))>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) THEN i.total ELSE 0 END),0) AS issued_30_total,
            COALESCE(AVG(CASE WHEN i.status<>'draft' AND DATE(COALESCE(i.issue_date,i.created_at))>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) THEN i.total ELSE NULL END),0) AS average_30,
            SUM(CASE WHEN i.status<>'draft' AND DATE(COALESCE(i.issue_date,i.created_at)) BETWEEN DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND DATE_SUB(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS issued_prev_count,
            COALESCE(SUM(CASE WHEN i.status<>'draft' AND DATE(COALESCE(i.issue_date,i.created_at)) BETWEEN DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND DATE_SUB(CURDATE(),INTERVAL 30 DAY) THEN i.total ELSE 0 END),0) AS issued_prev_total,
            COALESCE(AVG(CASE WHEN i.status<>'draft' AND DATE(COALESCE(i.issue_date,i.created_at)) BETWEEN DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND DATE_SUB(CURDATE(),INTERVAL 30 DAY) THEN i.total ELSE NULL END),0) AS average_prev,
            SUM(CASE WHEN i.paid_at IS NOT NULL AND DATE(i.paid_at)>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) THEN 1 ELSE 0 END) AS payment_30_count,
            COALESCE(AVG(CASE WHEN i.paid_at IS NOT NULL AND DATE(i.paid_at)>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) THEN GREATEST(DATEDIFF(DATE(i.paid_at),DATE(COALESCE(i.issue_date,i.created_at))),0) ELSE NULL END),0) AS payment_days_30,
            SUM(CASE WHEN i.paid_at IS NOT NULL AND DATE(i.paid_at) BETWEEN DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND DATE_SUB(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS payment_prev_count,
            COALESCE(AVG(CASE WHEN i.paid_at IS NOT NULL AND DATE(i.paid_at) BETWEEN DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND DATE_SUB(CURDATE(),INTERVAL 30 DAY) THEN GREATEST(DATEDIFF(DATE(i.paid_at),DATE(COALESCE(i.issue_date,i.created_at))),0) ELSE NULL END),0) AS payment_days_prev
            FROM invoices i WHERE " . implode(' AND ', $summaryWhere);
        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute($summaryParams);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        if (!$summary) $summary = array();

        $branches = array();
        if (ijlTable($pdo,'branches')) {
            $branchWhere = array('tenant_id=:t');
            $branchParams = array(':t'=>$tenantId);
            if (ijlColumn($pdo,'branches','status')) $branchWhere[] = "status='active'";
            if (!$canSeeAllBranches && $sessionBranchId <= 0) {
                $branchWhere[] = '1=0';
            } elseif (!$canSeeAllBranches && $sessionBranchId > 0) {
                $branchWhere[] = 'id=:scope_branch';
                $branchParams[':scope_branch'] = $sessionBranchId;
            }
            $bs = $pdo->prepare("SELECT id,name,branch_code FROM branches WHERE ".implode(' AND ',$branchWhere)." ORDER BY name,id");
            $bs->execute($branchParams);
            $branches = $bs->fetchAll(PDO::FETCH_ASSOC);
        }

        $companyName = 'FieldPlx';
        if (ijlTable($pdo,'tenants')) {
            $nameCol = ijlColumn($pdo,'tenants','display_name') ? 'display_name' : (ijlColumn($pdo,'tenants','legal_name') ? 'legal_name' : 'id');
            $tn = $pdo->prepare("SELECT " . $nameCol . " FROM tenants WHERE id=:t LIMIT 1");
            $tn->execute(array(':t'=>$tenantId));
            $value = trim((string)$tn->fetchColumn());
            if ($value !== '' && !ctype_digit($value)) $companyName = $value;
        }

        $currency = ijlCurrency($pdo, $tenantId, $branchId > 0 ? $branchId : $sessionBranchId);
        $dbToday = date('Y-m-d');
        try {
            $dbDateValue = trim((string)$pdo->query("SELECT CURDATE()")->fetchColumn());
            if (ijlValidDate($dbDateValue) && $dbDateValue !== '') $dbToday = $dbDateValue;
        } catch (Throwable $e) {
            error_log('Invoice list period date: ' . $e->getMessage());
        }
        $currentStart = date('Y-m-d', strtotime($dbToday . ' -29 days'));
        $previousEnd = date('Y-m-d', strtotime($dbToday . ' -30 days'));
        $previousStart = date('Y-m-d', strtotime($dbToday . ' -59 days'));

        ijlResponse(200, true, 'Invoices loaded.', array(
            'rows'=>$rows,
            'summary'=>$summary,
            'branches'=>$branches,
            'currency'=>$currency,
            'company_name'=>$companyName,
            'permissions'=>$permissions,
            'periods'=>array(
                'previous'=>array('start'=>$previousStart,'end'=>$previousEnd),
                'current'=>array('start'=>$currentStart,'end'=>$dbToday)
            ),
            'pagination'=>array(
                'page'=>$page,
                'pages'=>$pages,
                'per_page'=>$perPage,
                'total'=>$total,
                'from'=>$total > 0 ? $offset + 1 : 0,
                'to'=>$total > 0 ? min($offset + $perPage, $total) : 0
            )
        ));
    }

    if ($action === 'send_email') {
        if (!$permissions['update']) ijlResponse(403,false,'Your role does not have permission to send or update invoices.');
        $invoiceId = max(0, (int)ijlPost('invoice_id', 0));
        if ($invoiceId <= 0) ijlResponse(422, false, 'Select a valid invoice.');
        $to = ijlParseEmails(ijlPost('to_emails', ''));
        if (!$to) ijlResponse(422, false, 'Add at least one valid recipient email address.');
        $emailSubject = trim((string)ijlPost('email_subject', ''));
        $emailMessage = trim((string)ijlPost('email_message', ''));
        if ($emailSubject === '') ijlResponse(422, false, 'Email subject is required.');

        $subjectSelect = ijlColumn($pdo,'invoices','subject') ? 'i.subject' : "'For Services Rendered' AS subject";
        $inv = $pdo->prepare("SELECT i.*," . $subjectSelect . ",c.display_name AS client_name,c.email AS client_email,b.name AS branch_name,t.display_name AS tenant_name,t.legal_name AS tenant_legal_name FROM invoices i INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id LEFT JOIN branches b ON b.id=i.branch_id AND b.tenant_id=i.tenant_id INNER JOIN tenants t ON t.id=i.tenant_id WHERE i.id=:i AND i.tenant_id=:t LIMIT 1");
        $inv->execute(array(':i'=>$invoiceId, ':t'=>$tenantId));
        $invoice = $inv->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) ijlResponse(404, false, 'Invoice not found.');
        if (!$canSeeAllBranches && $sessionBranchId <= 0) ijlResponse(403,false,'No branch is assigned to your role/user scope.');
        if (!$canSeeAllBranches && $sessionBranchId > 0 && (int)$invoice['branch_id'] !== $sessionBranchId) ijlResponse(403,false,'This invoice belongs to a branch outside your access scope.');
        if (in_array((string)$invoice['status'], array('cancelled','archived'), true)) ijlResponse(409, false, 'This invoice cannot be emailed in its current status.');

        $items = array();
        if (ijlTable($pdo,'invoice_line_items')) {
            $it = $pdo->prepare("SELECT item_name,description,quantity,unit_price,line_total FROM invoice_line_items WHERE invoice_id=:i ORDER BY sort_order,id");
            $it->execute(array(':i'=>$invoiceId));
            $items = $it->fetchAll(PDO::FETCH_ASSOC);
        }
        $currency = ijlCurrency($pdo,$tenantId,!empty($invoice['branch_id'])?(int)$invoice['branch_id']:0);
        $mail = ijlCreateMailer($pdo,$tenantId,!empty($invoice['branch_id'])?(int)$invoice['branch_id']:0);
        foreach ($to as $address) $mail->addAddress($address);

        if (ijlPost('send_me_copy','0') === '1' && ijlTable($pdo,'users')) {
            $me = $pdo->prepare("SELECT email FROM users WHERE id=:u AND tenant_id=:t LIMIT 1");
            $me->execute(array(':u'=>$userId, ':t'=>$tenantId));
            $meEmail = strtolower(trim((string)$me->fetchColumn()));
            if ($meEmail !== '' && filter_var($meEmail,FILTER_VALIDATE_EMAIL) && !in_array($meEmail,$to,true)) $mail->addCC($meEmail);
        }

        $mail->isHTML(true);
        $mail->Subject = substr($emailSubject,0,255);
        $mail->Body = ijlInvoiceEmailHtml($invoice,$items,$currency,$emailMessage);
        $mail->AltBody = ($emailMessage !== '' ? $emailMessage . "\n\n" : '') . 'Invoice ' . $invoice['invoice_no'] . ' - Total ' . ijlMoney($invoice['total'],$currency) . ' - Balance ' . ijlMoney($invoice['balance_due'],$currency);
        ijlAttachInvoicePdf($mail,$invoice,$items,$currency);
        foreach (ijlEmailAttachments() as $file) $mail->addAttachment($file['tmp'],$file['name']);
        $mail->send();

        $newStatus = (string)$invoice['status'] === 'draft' ? 'sent' : (string)$invoice['status'];
        $up = $pdo->prepare("UPDATE invoices SET status=:s,sent_at=COALESCE(sent_at,NOW()),updated_at=NOW() WHERE id=:i AND tenant_id=:t");
        $up->execute(array(':s'=>$newStatus, ':i'=>$invoiceId, ':t'=>$tenantId));

        if (function_exists('tenantAuditLog')) {
            try {
                tenantAuditLog($pdo,'INVOICE_EMAIL_SENT',$tenantId,!empty($invoice['branch_id'])?(int)$invoice['branch_id']:null,$userId,'invoice',$invoiceId,null,array('invoice_no'=>$invoice['invoice_no'],'recipients'=>$to,'subject'=>$emailSubject));
            } catch (Throwable $auditError) {
                error_log('Invoice list email audit: ' . $auditError->getMessage());
            }
        }
        ijlResponse(200,true,'Invoice email sent successfully.');
    }

    if ($action === 'delete_invoice') {
        if (!$permissions['delete']) ijlResponse(403,false,'Your role does not have permission to delete invoices.');
        $invoiceId = max(0,(int)ijlPost('invoice_id',0));
        if ($invoiceId <= 0) ijlResponse(422,false,'Select a valid invoice.');

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id,branch_id,invoice_no,status,total,amount_paid,balance_due FROM invoices WHERE id=:i AND tenant_id=:t LIMIT 1 FOR UPDATE");
            $st->execute(array(':i'=>$invoiceId, ':t'=>$tenantId));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('Invoice not found.');
            if (!$canSeeAllBranches && $sessionBranchId <= 0) throw new RuntimeException('No branch is assigned to your role/user scope.');
            if (!$canSeeAllBranches && $sessionBranchId > 0 && (int)$row['branch_id'] !== $sessionBranchId) throw new RuntimeException('This invoice belongs to a branch outside your access scope.');
            if (in_array((string)$row['status'],array('cancelled','archived'),true)) throw new RuntimeException('This invoice is already removed from the active invoice list.');

            $paymentCount = 0;
            if (ijlTable($pdo,'payments')) {
                $pc = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE tenant_id=:t AND invoice_id=:i AND status='succeeded'");
                $pc->execute(array(':t'=>$tenantId, ':i'=>$invoiceId));
                $paymentCount = (int)$pc->fetchColumn();
            }
            if ((float)$row['amount_paid'] > 0.005 || $paymentCount > 0) {
                throw new RuntimeException('This invoice has payment history and cannot be deleted. Open the invoice and close/archive it instead.');
            }

            /* Soft-delete semantics preserve audit/accounting history. Normal list and summaries exclude cancelled invoices. */
            $up = $pdo->prepare("UPDATE invoices SET status='cancelled',updated_at=NOW() WHERE id=:i AND tenant_id=:t");
            $up->execute(array(':i'=>$invoiceId, ':t'=>$tenantId));
            $pdo->commit();

            if (function_exists('tenantAuditLog')) {
                try {
                    tenantAuditLog($pdo,'INVOICE_CANCELLED',$tenantId,!empty($row['branch_id'])?(int)$row['branch_id']:null,$userId,'invoice',$invoiceId,array('status'=>$row['status'],'balance_due'=>$row['balance_due']),array('status'=>'cancelled'));
                } catch (Throwable $auditError) {
                    error_log('Invoice list delete audit: ' . $auditError->getMessage());
                }
            }
            ijlResponse(200,true,'Invoice deleted from the active invoice list.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    ijlResponse(400,false,'Unsupported invoice action.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx invoice Jobber API: ' . $e->getMessage());
    ijlResponse(500,false,$e->getMessage() !== '' ? $e->getMessage() : 'Unable to complete the invoice request.');
}
