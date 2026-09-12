<?php
/**
 * FieldPlx Invoice View - Direct Backend Version 2.6.1
 * PHP 7.2 compatible.
 *
 * All invoice loading and payment collection are handled directly in this page.
 * No api/invoices.php request is used.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/platform-smtp.php';
if (file_exists(__DIR__ . '/includes/audit.php')) {
    require_once __DIR__ . '/includes/audit.php';
}

$pageTitle = 'Invoice';
$activePage = 'invoices';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['invoices_csrf_token'])) {
    $_SESSION['invoices_csrf_token'] = bin2hex(random_bytes(32));
}

$invoiceCsrfToken = (string) $_SESSION['invoices_csrf_token'];
$tenantId = !empty($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : 0;
$userId = !empty($_SESSION['tenant_user_id'])
    ? (int) $_SESSION['tenant_user_id']
    : (!empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0);

if ($tenantId <= 0 || $userId <= 0) {
    http_response_code(401);
    exit('Authentication required.');
}

function ivh($value)
{
    return htmlspecialchars((string) ($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

function ivTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t' => $table));
    $cache[$table] = ((int) $stmt->fetchColumn() > 0);
    return $cache[$table];
}

function ivColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $stmt->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int) $stmt->fetchColumn() > 0);
    return $cache[$key];
}

function ivMoney($value, $currency)
{
    $places = isset($currency['decimal_places']) ? (int) $currency['decimal_places'] : 2;
    $places = max(0, min(4, $places));
    $number = number_format((float) $value, $places, '.', ',');
    $symbol = isset($currency['symbol']) ? (string) $currency['symbol'] : '';
    $position = isset($currency['symbol_position']) ? (string) $currency['symbol_position'] : 'before';
    if ($symbol === '') return $number;
    return $position === 'after' ? $number . ' ' . $symbol : $symbol . $number;
}

function ivDate($value)
{
    if (!$value) return '-';
    $time = strtotime((string) $value);
    return $time ? date('d M Y', $time) : (string) $value;
}

function ivDateTime($value)
{
    if (!$value) return '-';
    $time = strtotime((string) $value);
    return $time ? date('d M Y, h:i A', $time) : (string) $value;
}

function ivTitle($value)
{
    $text = str_replace('_', ' ', trim((string) $value));
    return $text !== '' ? ucwords($text) : '-';
}

function ivAddress($parts)
{
    $out = array();
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '') $out[] = $part;
    }
    return $out ? implode(', ', $out) : '-';
}

function ivFallbackPaymentNo(PDO $pdo, $tenantId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0)+1 FROM payments WHERE tenant_id=:t");
    $stmt->execute(array(':t' => $tenantId));
    $next = max(1, (int) $stmt->fetchColumn());
    for ($i = 0; $i < 1000; $i++) {
        $no = 'PAY-' . str_pad((string) ($next + $i), 6, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT id FROM payments WHERE tenant_id=:t AND payment_no=:n LIMIT 1");
        $check->execute(array(':t' => $tenantId, ':n' => $no));
        if (!$check->fetchColumn()) return $no;
    }
    throw new RuntimeException('Unable to generate payment number.');
}

function ivNextPaymentNo(PDO $pdo, $tenantId, $branchId)
{
    if (!ivTable($pdo, 'document_sequences')) {
        return ivFallbackPaymentNo($pdo, $tenantId);
    }

    if ($branchId > 0) {
        $stmt = $pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type='payment' AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1 FOR UPDATE");
        $stmt->execute(array(':t' => $tenantId, ':b' => $branchId, ':b2' => $branchId));
    } else {
        $stmt = $pdo->prepare("SELECT ds.*,NULL AS branch_code FROM document_sequences ds WHERE ds.tenant_id=:t AND ds.document_type='payment' AND ds.is_active=1 AND ds.branch_id IS NULL ORDER BY ds.id LIMIT 1 FOR UPDATE");
        $stmt->execute(array(':t' => $tenantId));
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ivFallbackPaymentNo($pdo, $tenantId);

    $now = new DateTime('now');
    $year = $now->format('Y');
    $month = $now->format('m');
    $fyStart = max(1, min(12, (int) $row['financial_year_start_month']));
    $yearNum = (int) $now->format('Y');
    $fyYear = (int) $now->format('n') >= $fyStart ? $yearNum : $yearNum - 1;
    $fy = $fyYear . '-' . substr((string) ($fyYear + 1), -2);

    $resetKey = 'never';
    if ($row['reset_period'] === 'monthly') $resetKey = $year . $month;
    elseif ($row['reset_period'] === 'yearly') $resetKey = $year;
    elseif ($row['reset_period'] === 'financial_year') $resetKey = $fy;

    $current = (int) $row['current_number'];
    if ($row['reset_period'] !== 'never' && (string) $row['last_reset_key'] !== (string) $resetKey) {
        $current = 0;
    }
    $next = $current + 1;

    $middle = '';
    if ($row['middle_format'] === 'year') $middle = $year;
    elseif ($row['middle_format'] === 'year_month') $middle = $year . $month;
    elseif ($row['middle_format'] === 'financial_year') $middle = $fy;
    elseif ($row['middle_format'] === 'branch_year') $middle = (!empty($row['branch_code']) ? $row['branch_code'] : 'BR') . $year;

    $parts = array();
    if (!empty($row['prefix'])) $parts[] = $row['prefix'];
    if ($middle !== '') $parts[] = $middle;
    $parts[] = str_pad((string) $next, max(1, (int) $row['number_length']), '0', STR_PAD_LEFT);
    if (!empty($row['suffix'])) $parts[] = $row['suffix'];
    $separator = isset($row['number_separator']) ? (string) $row['number_separator'] : '-';
    $number = implode($separator, $parts);

    $update = $pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");
    $update->execute(array(':n' => $next, ':k' => $resetKey, ':id' => $row['id']));
    return $number;
}

function ivCurrency(PDO $pdo, $tenantId, $branchId)
{
    $stmt = $pdo->prepare("SELECT c.id,c.currency_code,c.currency_name,c.symbol,c.symbol_position,c.decimal_places,c.decimal_separator,c.thousand_separator FROM tenants t LEFT JOIN branches b ON b.id=:b AND b.tenant_id=t.id INNER JOIN currencies c ON c.id=COALESCE(b.currency_id,t.currency_id) WHERE t.id=:t LIMIT 1");
    $stmt->execute(array(':b' => $branchId > 0 ? $branchId : 0, ':t' => $tenantId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    return array('id' => 1, 'currency_code' => '', 'currency_name' => '', 'symbol' => '', 'symbol_position' => 'before', 'decimal_places' => 2, 'decimal_separator' => '.', 'thousand_separator' => ',');
}


function ivLoadPhpMailer()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;
    $paths = array(
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/mailer/vendor/autoload.php',
        dirname(__DIR__) . '/mailer/vendor/autoload.php'
    );
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;
        }
    }
    return false;
}

function ivSmtpConfiguration(PDO $pdo, $tenantId = 0, $branchId = 0)
{
    /*
     * IMPORTANT:
     * Every FieldPlx tenant, branch and user uses only the one active DEFAULT
     * PLATFORM SMTP configuration. Tenant/branch SMTP records are intentionally
     * ignored. The common include owns this policy.
     */
    return fieldplxPlatformSmtpConfig($pdo);
}

function ivCreateMailer(PDO $pdo, $tenantId = 0, $branchId = 0)
{
    if (!ivLoadPhpMailer()) {
        throw new RuntimeException('PHPMailer is not installed. Install PHPMailer with Composer before sending invoice email.');
    }

    $config = fieldplxPlatformSmtpConfig($pdo);
    if (!$config) {
        throw new RuntimeException('No active default platform SMTP configuration is available. Configure one Platform SMTP as Active + Default in Master Controls.');
    }

    $password = fieldplxDecryptSmtpPassword(
        isset($config['password_encrypted']) ? $config['password_encrypted'] : ''
    );

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string) $config['host']);
    $mail->Port = (int) $config['port'];
    $mail->Timeout = 20;
    if (property_exists($mail, 'Timelimit')) $mail->Timelimit = 20;
    $mail->SMTPDebug = 0;

    $username = trim((string) $config['username']);
    $mail->SMTPAuth = $username !== '';
    if ($mail->SMTPAuth) {
        if ($password === '') {
            throw new RuntimeException('Platform SMTP password is empty or could not be decrypted. Re-save the password in Master Controls using the permanent platform SMTP encryption key.');
        }
        $mail->Username = $username;
        $mail->Password = $password;
    }

    $encryption = strtolower(trim((string) $config['encryption']));
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

    $fromEmail = trim((string) $config['from_email']);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $fromEmail = $username;
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The configured Platform SMTP From Email is invalid.');
    }

    $fromName = trim((string) $config['from_name']);
    if ($fromName === '') $fromName = 'FieldPlx';

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);

    $replyTo = trim((string) $config['reply_to_email']);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($replyTo);
    }

    return $mail;
}

function ivParseEmails($raw)
{
    $parts = preg_split('/[;,\\s]+/', trim((string) $raw));
    $out = array();
    foreach ($parts as $email) {
        $email = strtolower(trim((string) $email));
        if ($email === '') continue;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid recipient email address: ' . $email);
        }
        $out[$email] = $email;
    }
    return array_values($out);
}

function ivEmailAttachmentFiles()
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
        $safeName = preg_replace('/[^A-Za-z0-9._() -]/', '_', basename((string)$name));
        if ($safeName === '') $safeName = 'attachment';

        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detected = @finfo_file($fi, $tmp);
                if (is_string($detected)) $mime = trim($detected);
                @finfo_close($fi);
            }
        }
        if ($mime === '' && isset($f['type'])) {
            $mime = is_array($f['type']) ? trim((string)$f['type'][$idx]) : trim((string)$f['type']);
        }

        $out[] = array('tmp'=>$tmp,'name'=>$safeName,'size'=>$size,'mime'=>$mime);
    }
    return $out;
}

function ivInvoiceEmailHtml($invoice, $items, $currency, $message)
{
    $e = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $company = !empty($invoice['branch_name']) ? $invoice['branch_name'] : (!empty($invoice['tenant_name']) ? $invoice['tenant_name'] : 'FieldPlx');
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:auto;color:#173946;background:#fff">';
    $html .= '<div style="padding:20px 22px;background:#001131;color:#fff"><div style="font-size:12px;opacity:.8">'.$e($company).'</div><h2 style="margin:5px 0 0;font-size:22px">Invoice '.$e($invoice['invoice_no']).'</h2></div>';
    $html .= '<div style="padding:22px;border:1px solid #e2e8ec;border-top:0">';
    if (trim((string)$message) !== '') $html .= '<div style="white-space:pre-line;line-height:1.65;margin-bottom:18px">'.nl2br($e($message)).'</div>';
    $html .= '<div style="padding:13px 15px;background:#f7f9fb;border-radius:8px;line-height:1.75"><strong>Invoice:</strong> '.$e($invoice['invoice_no']).'<br><strong>Issued:</strong> '.$e(ivDate($invoice['issue_date'])).'<br><strong>Due:</strong> '.$e(ivDate($invoice['due_date'])).'<br><strong>Total:</strong> '.$e(ivMoney($invoice['total'],$currency)).'<br><strong>Balance:</strong> '.$e(ivMoney($invoice['balance_due'],$currency)).'</div>';
    if ($items) {
        $html .= '<table style="width:100%;border-collapse:collapse;margin-top:18px"><thead><tr><th style="padding:9px;text-align:left;border-bottom:2px solid #dfe6ea">Product / Service</th><th style="padding:9px;text-align:center;border-bottom:2px solid #dfe6ea">Qty</th><th style="padding:9px;text-align:right;border-bottom:2px solid #dfe6ea">Total</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $html .= '<tr><td style="padding:9px;border-bottom:1px solid #e7ebee">'.$e($item['item_name']).'</td><td style="padding:9px;text-align:center;border-bottom:1px solid #e7ebee">'.$e(rtrim(rtrim(number_format((float)$item['quantity'],3,'.',''),'0'),'.')).'</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e7ebee">'.$e(ivMoney($item['line_total'],$currency)).'</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $html .= '<p style="margin:20px 0 0">Thank you,<br>'.$e($company).'</p></div></div>';
    return $html;
}

function ivGenerateExactInvoicePdf($invoiceId)
{
    $invoiceId = (int)$invoiceId;
    if ($invoiceId <= 0) throw new RuntimeException('Invalid invoice for PDF attachment.');

    $printFile = __DIR__ . '/invoice-print.php';
    if (!is_file($printFile)) {
        throw new RuntimeException('invoice-print.php was not found, so the invoice PDF could not be attached.');
    }

    if (!defined('FIELDPLX_INVOICE_PDF_CAPTURE')) {
        define('FIELDPLX_INVOICE_PDF_CAPTURE', true);
    }

    $oldInvoiceIdExists = array_key_exists('invoice_id', $_GET);
    $oldInvoiceId = $oldInvoiceIdExists ? $_GET['invoice_id'] : null;
    $oldIdExists = array_key_exists('id', $_GET);
    $oldId = $oldIdExists ? $_GET['id'] : null;

    unset($GLOBALS['fieldplx_invoice_pdf_bytes'], $GLOBALS['fieldplx_invoice_pdf_name']);
    $_GET['invoice_id'] = $invoiceId;
    unset($_GET['id']);

    $bufferLevel = ob_get_level();
    ob_start();
    try {
        include $printFile;
    } catch (Throwable $e) {
        while (ob_get_level() > $bufferLevel) @ob_end_clean();
        if ($oldInvoiceIdExists) $_GET['invoice_id'] = $oldInvoiceId; else unset($_GET['invoice_id']);
        if ($oldIdExists) $_GET['id'] = $oldId; else unset($_GET['id']);
        throw new RuntimeException('Unable to generate the invoice PDF attachment: ' . $e->getMessage(), 0, $e);
    }
    while (ob_get_level() > $bufferLevel) @ob_end_clean();

    if ($oldInvoiceIdExists) $_GET['invoice_id'] = $oldInvoiceId; else unset($_GET['invoice_id']);
    if ($oldIdExists) $_GET['id'] = $oldId; else unset($_GET['id']);

    $bytes = isset($GLOBALS['fieldplx_invoice_pdf_bytes']) ? $GLOBALS['fieldplx_invoice_pdf_bytes'] : '';
    $name = isset($GLOBALS['fieldplx_invoice_pdf_name']) ? $GLOBALS['fieldplx_invoice_pdf_name'] : ('Invoice-' . $invoiceId . '.pdf');
    unset($GLOBALS['fieldplx_invoice_pdf_bytes'], $GLOBALS['fieldplx_invoice_pdf_name']);

    if (!is_string($bytes) || strlen($bytes) < 100 || substr($bytes, 0, 4) !== '%PDF') {
        throw new RuntimeException('The invoice print page did not return a valid PDF document.');
    }

    return array(
        'bytes' => $bytes,
        'name' => $name,
        'size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes)
    );
}

function ivAttachExactInvoicePdf($mail, $invoiceId)
{
    $pdf = ivGenerateExactInvoicePdf($invoiceId);
    $mail->addStringAttachment($pdf['bytes'], $pdf['name'], 'base64', 'application/pdf');
    return $pdf;
}

function ivPaymentReceiptEmailHtml($row, $currency, $paymentNo, $amount, $method, $receivedAt, $balance, $reference)
{
    $e = function ($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    $clientName = trim((string)(isset($row['client_name']) ? $row['client_name'] : 'Customer'));
    if ($clientName === '') $clientName = 'Customer';

    $company = trim((string)(isset($row['branch_name']) ? $row['branch_name'] : ''));
    if ($company === '') $company = trim((string)(isset($row['tenant_name']) ? $row['tenant_name'] : ''));
    if ($company === '') $company = 'FieldPlx';

    $statusText = $balance <= 0.005 ? 'Paid in full' : 'Partially paid';

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:680px;margin:0 auto;color:#173946;background:#ffffff">';
    $html .= '<div style="padding:20px 22px;background:#001131;color:#ffffff">';
    $html .= '<div style="font-size:12px;opacity:.82">' . $e($company) . '</div>';
    $html .= '<h2 style="margin:5px 0 0;font-size:22px">Payment received</h2>';
    $html .= '</div>';
    $html .= '<div style="padding:22px;border:1px solid #e2e8ec;border-top:0">';
    $html .= '<p style="margin:0 0 16px;line-height:1.6">Hi ' . $e($clientName) . ',</p>';
    $html .= '<p style="margin:0 0 18px;line-height:1.6">We received your payment for invoice <strong>' . $e($row['invoice_no']) . '</strong>. Thank you.</p>';
    $html .= '<div style="padding:14px 16px;background:#f7f9fb;border-radius:8px;line-height:1.85">';
    $html .= '<strong>Payment receipt:</strong> ' . $e($paymentNo) . '<br>';
    $html .= '<strong>Invoice:</strong> ' . $e($row['invoice_no']) . '<br>';
    $html .= '<strong>Amount received:</strong> ' . $e(ivMoney($amount, $currency)) . '<br>';
    $html .= '<strong>Payment method:</strong> ' . $e(ivTitle($method)) . '<br>';
    $html .= '<strong>Received:</strong> ' . $e(ivDateTime($receivedAt)) . '<br>';
    if (trim((string)$reference) !== '') {
        $html .= '<strong>Reference:</strong> ' . $e($reference) . '<br>';
    }
    $html .= '<strong>Remaining balance:</strong> ' . $e(ivMoney($balance, $currency)) . '<br>';
    $html .= '<strong>Status:</strong> ' . $e($statusText);
    $html .= '</div>';
    $html .= '<p style="margin:20px 0 0;line-height:1.6">Thank you,<br>' . $e($company) . '</p>';
    $html .= '</div></div>';

    return $html;
}

function ivSendPaymentReceiptEmail(PDO $pdo, $tenantId, $invoiceId, $paymentNo, $amount, $method, $receivedAt, $balance, $reference, $currency)
{
    $stmt = $pdo->prepare(
        "SELECT i.invoice_no,i.branch_id," .
        "c.display_name AS client_name,c.email AS client_email," .
        "b.name AS branch_name,t.display_name AS tenant_name " .
        "FROM invoices i " .
        "INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id " .
        "LEFT JOIN branches b ON b.id=i.branch_id AND b.tenant_id=i.tenant_id " .
        "INNER JOIN tenants t ON t.id=i.tenant_id " .
        "WHERE i.id=:i AND i.tenant_id=:t LIMIT 1"
    );
    $stmt->execute(array(':i' => $invoiceId, ':t' => $tenantId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Payment was collected, but the invoice could not be loaded for the receipt email.');
    }

    $email = strtolower(trim((string)$row['client_email']));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Payment was collected, but the selected customer does not have a valid email address.');
    }

    $subject = 'Payment received for invoice ' . (string)$row['invoice_no'];
    $html = ivPaymentReceiptEmailHtml(
        $row,
        $currency,
        $paymentNo,
        $amount,
        $method,
        $receivedAt,
        $balance,
        $reference
    );

    /*
     * IMPORTANT:
     * Use the exact same PHPMailer + Platform SMTP path as the working
     * invoice Email action. The older payment code called
     * fieldplxSendPlatformMail(), which uses the low-level socket sender.
     * On some servers invoice email worked through PHPMailer while payment
     * receipt delivery failed through that different transport path.
     */
    $branchId = !empty($row['branch_id']) ? (int)$row['branch_id'] : 0;
    $smtpConfig = fieldplxPlatformSmtpConfig($pdo);
    if (!$smtpConfig) {
        throw new RuntimeException('No active default platform SMTP configuration is available. Configure one Platform SMTP as Active + Default in Master Controls.');
    }

    $mail = ivCreateMailer($pdo, $tenantId, $branchId);
    $mail->addAddress($email, trim((string)$row['client_name']) !== '' ? (string)$row['client_name'] : 'Client');
    $mail->isHTML(true);
    $mail->Subject = substr($subject, 0, 255);
    $mail->Body = $html;
    $mail->AltBody =
        'Payment received for invoice ' . (string)$row['invoice_no'] . "\n" .
        'Payment receipt: ' . (string)$paymentNo . "\n" .
        'Amount received: ' . ivMoney($amount, $currency) . "\n" .
        'Payment method: ' . ivTitle($method) . "\n" .
        'Received: ' . ivDateTime($receivedAt) . "\n" .
        'Remaining balance: ' . ivMoney($balance, $currency);

    $mail->send();

    return array(
        'email' => $email,
        'smtp_id' => isset($smtpConfig['id']) ? (int)$smtpConfig['id'] : 0
    );
}

$invoiceId = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;
$jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
$openCollect = isset($_GET['collect']) && $_GET['collect'] === '1';
$flashSuccess = isset($_SESSION['invoice_view_success']) ? (string) $_SESSION['invoice_view_success'] : '';
$flashWarning = isset($_SESSION['invoice_view_warning']) ? (string) $_SESSION['invoice_view_warning'] : '';
$flashError = isset($_SESSION['invoice_view_error']) ? (string) $_SESSION['invoice_view_error'] : '';
unset($_SESSION['invoice_view_success'], $_SESSION['invoice_view_warning'], $_SESSION['invoice_view_error']);

/* ----------------------------------------------------------
   Collect payment - handled directly on this page
   ---------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_payment') {
    $postedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if ($postedToken === '' || !hash_equals($invoiceCsrfToken, $postedToken)) {
        $_SESSION['invoice_view_error'] = 'Your form session expired. Refresh and try again.';
        header('Location: ' . basename($_SERVER['PHP_SELF']) . '?invoice_id=' . (int) $_POST['invoice_id']);
        exit;
    }

    $postInvoiceId = isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0;
    $amount = isset($_POST['amount']) ? round((float) $_POST['amount'], 2) : 0.0;
    $method = isset($_POST['payment_method']) ? strtolower(trim((string) $_POST['payment_method'])) : 'cash';
    $reference = isset($_POST['reference']) ? trim((string) $_POST['reference']) : '';
    $receivedAtRaw = isset($_POST['received_at']) ? trim((string) $_POST['received_at']) : '';
    $paymentNotes = isset($_POST['payment_notes']) ? trim((string) $_POST['payment_notes']) : '';
    $allowedMethods = array('cash','card','bank','upi','cheque','wallet','other');

    try {
        if ($postInvoiceId <= 0) throw new RuntimeException('Invalid invoice selected.');
        if ($amount <= 0) throw new RuntimeException('Enter a valid payment amount.');
        if (!in_array($method, $allowedMethods, true)) throw new RuntimeException('Select a valid payment method.');

        $receivedAt = date('Y-m-d H:i:s');
        if ($receivedAtRaw !== '') {
            $ts = strtotime(str_replace('T', ' ', $receivedAtRaw));
            if ($ts === false) throw new RuntimeException('Select a valid received date and time.');
            $receivedAt = date('Y-m-d H:i:s', $ts);
        }

        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT id,tenant_id,branch_id,client_id,quote_id,invoice_no,status,total,amount_paid,balance_due FROM invoices WHERE id=:id AND tenant_id=:t LIMIT 1 FOR UPDATE");
        $lock->execute(array(':id' => $postInvoiceId, ':t' => $tenantId));
        $invoiceLock = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$invoiceLock) throw new RuntimeException('Invoice not found.');
        if (in_array((string) $invoiceLock['status'], array('cancelled','archived','written_off'), true)) {
            throw new RuntimeException('Payment cannot be collected for this invoice status.');
        }
        $balanceBefore = round((float) $invoiceLock['balance_due'], 2);
        if ($balanceBefore <= 0.005) throw new RuntimeException('This invoice has no balance due.');
        if ($amount > $balanceBefore + 0.005) throw new RuntimeException('Payment cannot exceed the balance due.');

        if (!ivTable($pdo, 'payments')) throw new RuntimeException('Payments table is not available.');
        $branchIdForPayment = !empty($invoiceLock['branch_id']) ? (int) $invoiceLock['branch_id'] : 0;
        $currency = ivCurrency($pdo, $tenantId, $branchIdForPayment);
        $currencyId = (int) $currency['id'];
        $paymentNo = ivNextPaymentNo($pdo, $tenantId, $branchIdForPayment);

        $insert = $pdo->prepare("INSERT INTO payments(tenant_id,branch_id,payment_no,client_id,invoice_id,quote_id,payment_method,payment_channel,status,amount,currency_id,provider,provider_payment_id,transaction_fee,received_at,notes,created_by,created_at) VALUES(:t,:b,:no,:c,:i,:q,:m,'manual','succeeded',:amt,:cur,'manual_collection',:ref,0,:received,:notes,:u,NOW())");
        $insert->execute(array(
            ':t' => $tenantId,
            ':b' => $branchIdForPayment > 0 ? $branchIdForPayment : null,
            ':no' => $paymentNo,
            ':c' => (int) $invoiceLock['client_id'],
            ':i' => $postInvoiceId,
            ':q' => !empty($invoiceLock['quote_id']) ? (int) $invoiceLock['quote_id'] : null,
            ':m' => $method,
            ':amt' => $amount,
            ':cur' => $currencyId,
            ':ref' => $reference !== '' ? $reference : null,
            ':received' => $receivedAt,
            ':notes' => $paymentNotes !== '' ? $paymentNotes : null,
            ':u' => $userId
        ));
        $paymentId = (int) $pdo->lastInsertId();

        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE tenant_id=:t AND invoice_id=:i AND status='succeeded'");
        $sumStmt->execute(array(':t' => $tenantId, ':i' => $postInvoiceId));
        $paid = round((float) $sumStmt->fetchColumn(), 2);
        $invoiceTotal = round((float) $invoiceLock['total'], 2);
        $balance = max(0, round($invoiceTotal - $paid, 2));
        $newStatus = $balance <= 0.005 ? 'paid' : ($paid > 0.005 ? 'partially_paid' : (string) $invoiceLock['status']);

        $update = $pdo->prepare("UPDATE invoices SET amount_paid=:paid,balance_due=:bal,status=:st,paid_at=CASE WHEN :st2='paid' THEN COALESCE(paid_at,NOW()) ELSE NULL END,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
        $update->execute(array(':paid' => $paid, ':bal' => $balance, ':st' => $newStatus, ':st2' => $newStatus, ':id' => $postInvoiceId, ':t' => $tenantId));

        $pdo->commit();

        if (function_exists('tenantAuditLog')) {
            try {
                tenantAuditLog($pdo, 'PAYMENT_RECEIVED', $tenantId, $branchIdForPayment, $userId, 'payment', $paymentId, null, array(
                    'invoice_id' => $postInvoiceId,
                    'invoice_no' => $invoiceLock['invoice_no'],
                    'payment_no' => $paymentNo,
                    'amount' => $amount,
                    'balance_due' => $balance
                ));
            } catch (Throwable $auditError) {
                error_log('Invoice view payment audit: ' . $auditError->getMessage());
            }
        }

        $successMessage = 'Payment ' . $paymentNo . ' collected successfully.';

        /*
         * Transactional receipt email happens AFTER the payment transaction is
         * committed. SMTP failure must never reverse a successfully collected
         * payment. Every tenant/user uses the shared DEFAULT PLATFORM SMTP.
         */
        try {
            $receiptEmail = ivSendPaymentReceiptEmail(
                $pdo,
                $tenantId,
                $postInvoiceId,
                $paymentNo,
                $amount,
                $method,
                $receivedAt,
                $balance,
                $reference,
                $currency
            );

            $successMessage .= ' Payment receipt emailed to ' . $receiptEmail['email'] . '.';

            if (function_exists('tenantAuditLog')) {
                try {
                    tenantAuditLog(
                        $pdo,
                        'PAYMENT_RECEIPT_EMAIL_SENT',
                        $tenantId,
                        $branchIdForPayment,
                        $userId,
                        'payment',
                        $paymentId,
                        null,
                        array(
                            'invoice_id' => $postInvoiceId,
                            'invoice_no' => $invoiceLock['invoice_no'],
                            'payment_no' => $paymentNo,
                            'recipient' => $receiptEmail['email'],
                            'smtp_config_id' => $receiptEmail['smtp_id']
                        )
                    );
                } catch (Throwable $auditError) {
                    error_log('Payment receipt email audit: ' . $auditError->getMessage());
                }
            }
        } catch (Throwable $mailError) {
            error_log('FieldPlx payment receipt email: ' . $mailError->getMessage());
            $_SESSION['invoice_view_warning'] = 'Payment was collected, but the receipt email was not sent: ' . $mailError->getMessage();

            if (function_exists('tenantAuditLog')) {
                try {
                    tenantAuditLog(
                        $pdo,
                        'PAYMENT_RECEIPT_EMAIL_FAILED',
                        $tenantId,
                        $branchIdForPayment,
                        $userId,
                        'payment',
                        $paymentId,
                        null,
                        array(
                            'invoice_id' => $postInvoiceId,
                            'invoice_no' => $invoiceLock['invoice_no'],
                            'payment_no' => $paymentNo,
                            'error' => substr($mailError->getMessage(), 0, 500)
                        )
                    );
                } catch (Throwable $auditError) {
                    error_log('Payment receipt email failure audit: ' . $auditError->getMessage());
                }
            }
        }

        $_SESSION['invoice_view_success'] = $successMessage;
        header('Location: ' . basename($_SERVER['PHP_SELF']) . '?invoice_id=' . $postInvoiceId);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('FieldPlx invoice direct payment: ' . $e->getMessage());
        $_SESSION['invoice_view_error'] = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to collect payment.';
        header('Location: ' . basename($_SERVER['PHP_SELF']) . '?invoice_id=' . $postInvoiceId);
        exit;
    }
}



/* ----------------------------------------------------------
   Email invoice / signature / client-view actions
   ---------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], array('send_invoice_email','save_signature','save_client_view'), true)) {
    $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $postInvoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
    if ($postedToken === '' || !hash_equals($invoiceCsrfToken, $postedToken)) {
        $_SESSION['invoice_view_error'] = 'Your form session expired. Refresh and try again.';
        header('Location: ' . basename($_SERVER['PHP_SELF']) . '?invoice_id=' . $postInvoiceId);
        exit;
    }
    try {
        if ($postInvoiceId <= 0) throw new RuntimeException('Invalid invoice selected.');

        if ($_POST['action'] === 'save_client_view') {
            if (!ivColumn($pdo, 'invoices', 'client_view_options')) throw new RuntimeException('Client view storage is not installed. Run migration_invoice_client_view_options.sql once.');
            $keys = array('quantities','unit_prices','line_item_totals','account_balance','late_stamp');
            $options = array();
            foreach ($keys as $key) $options[$key] = isset($_POST['cv_'.$key]) && $_POST['cv_'.$key] === '1';
            $oldStmt = $pdo->prepare("SELECT branch_id,invoice_no,client_view_options FROM invoices WHERE id=:i AND tenant_id=:t LIMIT 1");
            $oldStmt->execute(array(':i'=>$postInvoiceId, ':t'=>$tenantId));
            $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new RuntimeException('Invoice not found.');
            $json = json_encode($options, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $up = $pdo->prepare("UPDATE invoices SET client_view_options=:o,updated_at=NOW() WHERE id=:i AND tenant_id=:t");
            $up->execute(array(':o'=>$json, ':i'=>$postInvoiceId, ':t'=>$tenantId));
            if (function_exists('tenantAuditLog')) {
                try { tenantAuditLog($pdo, 'INVOICE_CLIENT_VIEW_UPDATED', $tenantId, !empty($old['branch_id'])?(int)$old['branch_id']:null, $userId, 'invoice', $postInvoiceId, array('client_view_options'=>isset($old['client_view_options'])?$old['client_view_options']:null), array('client_view_options'=>$options)); }
                catch (Throwable $auditError) { error_log('Client view audit: '.$auditError->getMessage()); }
            }
            $_SESSION['invoice_view_success'] = 'Client view settings updated.';
        }

        if ($_POST['action'] === 'save_signature') {
            if (!ivTable($pdo, 'attachments')) throw new RuntimeException('Attachments table is not available.');
            $sig = isset($_POST['signature_data']) ? trim((string)$_POST['signature_data']) : '';
            if (!preg_match('#^data:image/png;base64,#', $sig)) throw new RuntimeException('Please write a signature before submitting.');
            $raw = base64_decode(substr($sig, strpos($sig, ',') + 1), true);
            if ($raw === false || strlen($raw) < 100) throw new RuntimeException('The signature image is invalid.');
            if (strlen($raw) > 3 * 1024 * 1024) throw new RuntimeException('The signature image is too large.');
            $invStmt = $pdo->prepare("SELECT i.id,i.branch_id,i.invoice_no,i.client_id,c.display_name AS client_name,c.email AS client_email FROM invoices i INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id WHERE i.id=:i AND i.tenant_id=:t LIMIT 1");
            $invStmt->execute(array(':i'=>$postInvoiceId, ':t'=>$tenantId));
            $sigInvoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sigInvoice) throw new RuntimeException('Invoice not found.');
            $dir = __DIR__ . '/uploads/invoices/' . $tenantId . '/' . $postInvoiceId;
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Invoice upload folder is not writable.');
            $fileName = 'signature-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)),0,8) . '.png';
            $abs = $dir . '/' . $fileName;
            if (@file_put_contents($abs, $raw) === false) throw new RuntimeException('Unable to save the signature image.');
            $relative = 'uploads/invoices/' . $tenantId . '/' . $postInvoiceId . '/' . $fileName;
            try {
                $ins = $pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,job_id,visit_id,workflow_step_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type,description) SELECT :t,'invoice',i.id,i.job_id,i.visit_id,NULL,:u,:fn,:fp,'image/png',:fs,'signature',:d FROM invoices i WHERE i.id=:i AND i.tenant_id=:t2");
                $ins->execute(array(':t'=>$tenantId, ':u'=>$userId, ':fn'=>$fileName, ':fp'=>$relative, ':fs'=>strlen($raw), ':d'=>'Invoice signature', ':i'=>$postInvoiceId, ':t2'=>$tenantId));
            } catch (Throwable $insertError) {
                @unlink($abs);
                throw $insertError;
            }
            if (function_exists('tenantAuditLog')) {
                try { tenantAuditLog($pdo, 'INVOICE_SIGNATURE_COLLECTED', $tenantId, !empty($sigInvoice['branch_id'])?(int)$sigInvoice['branch_id']:null, $userId, 'invoice', $postInvoiceId, null, array('invoice_no'=>$sigInvoice['invoice_no'],'signature_file'=>$relative)); }
                catch (Throwable $auditError) { error_log('Invoice signature audit: '.$auditError->getMessage()); }
            }
            $message = 'Signature saved successfully.';
            if (!empty($_POST['send_signature_copy']) && $_POST['send_signature_copy']==='1' && !empty($sigInvoice['client_email']) && filter_var($sigInvoice['client_email'], FILTER_VALIDATE_EMAIL)) {
                try {
                    $mail = ivCreateMailer($pdo, $tenantId, !empty($sigInvoice['branch_id'])?(int)$sigInvoice['branch_id']:0);
                    $mail->addAddress($sigInvoice['client_email'], !empty($sigInvoice['client_name'])?$sigInvoice['client_name']:'Client');
                    $mail->isHTML(true);
                    $mail->Subject = 'Signature received for invoice ' . $sigInvoice['invoice_no'];
                    $mail->Body = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#173946"><p>Hello '.ivh($sigInvoice['client_name']).',</p><p>Your signature for invoice <strong>'.ivh($sigInvoice['invoice_no']).'</strong> has been recorded.</p><p>Thank you.</p></div>';
                    $mail->AltBody = 'Your signature for invoice '.$sigInvoice['invoice_no'].' has been recorded.';
                    $mail->addAttachment($abs, 'signature.png');
                    $mail->send();
                    $message .= ' A copy was emailed to the client.';
                } catch (Throwable $mailError) {
                    $message .= ' Client copy was not sent: '.$mailError->getMessage();
                }
            }
            $_SESSION['invoice_view_success'] = $message;
        }

        if ($_POST['action'] === 'send_invoice_email') {
            $to = ivParseEmails(isset($_POST['to_emails']) ? $_POST['to_emails'] : '');
            if (!$to) throw new RuntimeException('Add at least one valid recipient email address.');

            $emailSubject = trim((string)(isset($_POST['email_subject']) ? $_POST['email_subject'] : ''));
            if ($emailSubject === '') throw new RuntimeException('Email subject is required.');
            $emailMessage = trim((string)(isset($_POST['email_message']) ? $_POST['email_message'] : ''));

            $mailInvoiceStmt = $pdo->prepare("SELECT i.*,c.display_name AS client_name,c.email AS client_email,b.name AS branch_name,t.display_name AS tenant_name,t.legal_name AS tenant_legal_name FROM invoices i INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id LEFT JOIN branches b ON b.id=i.branch_id AND b.tenant_id=i.tenant_id INNER JOIN tenants t ON t.id=i.tenant_id WHERE i.id=:i AND i.tenant_id=:t LIMIT 1");
            $mailInvoiceStmt->execute(array(':i'=>$postInvoiceId, ':t'=>$tenantId));
            $mailInvoice = $mailInvoiceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$mailInvoice) throw new RuntimeException('Invoice not found.');

            $mailItems = array();
            if (ivTable($pdo, 'invoice_line_items')) {
                $mi = $pdo->prepare("SELECT item_name,description,quantity,unit_price,line_total FROM invoice_line_items WHERE invoice_id=:i ORDER BY sort_order,id");
                $mi->execute(array(':i'=>$postInvoiceId));
                $mailItems = $mi->fetchAll(PDO::FETCH_ASSOC);
            }

            $branchIdForMail = !empty($mailInvoice['branch_id']) ? (int)$mailInvoice['branch_id'] : 0;
            $mailCurrency = ivCurrency($pdo, $tenantId, $branchIdForMail);
            $extraFiles = ivEmailAttachmentFiles();
            $attachmentAudit = array();
            foreach ($extraFiles as $file) {
                $attachmentAudit[] = array(
                    'name' => $file['name'],
                    'size' => (int)$file['size'],
                    'mime' => isset($file['mime']) ? $file['mime'] : ''
                );
            }

            $ccEmail = '';
            $smtpConfig = fieldplxPlatformSmtpConfig($pdo);
            $smtpId = $smtpConfig && isset($smtpConfig['id']) ? (int)$smtpConfig['id'] : 0;

            try {
                $mail = ivCreateMailer($pdo, $tenantId, $branchIdForMail);
                foreach ($to as $email) $mail->addAddress($email);

                if (!empty($_POST['send_me_copy']) && $_POST['send_me_copy']==='1' && ivTable($pdo,'users')) {
                    $me = $pdo->prepare("SELECT email FROM users WHERE id=:u AND tenant_id=:t LIMIT 1");
                    $me->execute(array(':u'=>$userId, ':t'=>$tenantId));
                    $meEmail = strtolower(trim((string)$me->fetchColumn()));
                    if ($meEmail !== '' && filter_var($meEmail,FILTER_VALIDATE_EMAIL) && !in_array($meEmail,$to,true)) {
                        $mail->addCC($meEmail);
                        $ccEmail = $meEmail;
                    }
                }

                $mail->isHTML(true);
                $mail->Subject = substr($emailSubject,0,255);
                $mail->Body = ivInvoiceEmailHtml($mailInvoice, $mailItems, $mailCurrency, $emailMessage);
                $mail->AltBody = ($emailMessage !== '' ? $emailMessage."\n\n" : '') . 'Invoice '.$mailInvoice['invoice_no'].' - Total '.ivMoney($mailInvoice['total'],$mailCurrency).' - Balance '.ivMoney($mailInvoice['balance_due'],$mailCurrency);

                /* Mandatory: use the exact document produced by invoice-print.php. */
                $invoicePdf = ivAttachExactInvoicePdf($mail, $postInvoiceId);

                foreach ($extraFiles as $file) {
                    $mail->addAttachment($file['tmp'], $file['name']);
                }

                $mail->send();

                $oldStatus = (string)$mailInvoice['status'];
                $newStatus = in_array($oldStatus,array('draft'),true) ? 'sent' : $oldStatus;
                $up = $pdo->prepare("UPDATE invoices SET status=:s,sent_at=COALESCE(sent_at,NOW()),updated_at=NOW() WHERE id=:i AND tenant_id=:t");
                $up->execute(array(':s'=>$newStatus, ':i'=>$postInvoiceId, ':t'=>$tenantId));

                if (function_exists('tenantAuditLog')) {
                    try {
                        tenantAuditLog(
                            $pdo,
                            'INVOICE_EMAIL_SENT',
                            $tenantId,
                            $branchIdForMail > 0 ? $branchIdForMail : null,
                            $userId,
                            'invoice',
                            $postInvoiceId,
                            array('status'=>$oldStatus),
                            array(
                                'status'=>$newStatus,
                                'invoice_no'=>$mailInvoice['invoice_no'],
                                'recipients'=>$to,
                                'cc'=>$ccEmail !== '' ? array($ccEmail) : array(),
                                'subject'=>$emailSubject,
                                'message'=>$emailMessage,
                                'smtp_config_id'=>$smtpId,
                                'invoice_pdf'=>array(
                                    'name'=>$invoicePdf['name'],
                                    'size'=>(int)$invoicePdf['size'],
                                    'sha256'=>$invoicePdf['sha256']
                                ),
                                'additional_attachments'=>$attachmentAudit,
                                'additional_attachment_count'=>count($attachmentAudit)
                            )
                        );
                    } catch (Throwable $auditError) {
                        error_log('Invoice email audit: '.$auditError->getMessage());
                    }
                }

                $_SESSION['invoice_view_success'] = 'Invoice email sent successfully to ' . implode(', ', $to) . '.';
            } catch (Throwable $mailError) {
                if (function_exists('tenantAuditLog')) {
                    try {
                        tenantAuditLog(
                            $pdo,
                            'INVOICE_EMAIL_FAILED',
                            $tenantId,
                            $branchIdForMail > 0 ? $branchIdForMail : null,
                            $userId,
                            'invoice',
                            $postInvoiceId,
                            null,
                            array(
                                'invoice_no'=>$mailInvoice['invoice_no'],
                                'recipients'=>$to,
                                'cc'=>$ccEmail !== '' ? array($ccEmail) : array(),
                                'subject'=>$emailSubject,
                                'message'=>$emailMessage,
                                'smtp_config_id'=>$smtpId,
                                'additional_attachments'=>$attachmentAudit,
                                'additional_attachment_count'=>count($attachmentAudit),
                                'error'=>substr($mailError->getMessage(),0,1000)
                            )
                        );
                    } catch (Throwable $auditError) {
                        error_log('Invoice email failure audit: '.$auditError->getMessage());
                    }
                }
                throw $mailError;
            }
        }
    } catch (Throwable $e) {
        error_log('Invoice view action: '.$e->getMessage());
        $_SESSION['invoice_view_error'] = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to complete the invoice action.';
    }
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?invoice_id=' . $postInvoiceId);
    exit;
}

/* ----------------------------------------------------------
   Additional invoice actions
   ---------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], array('close_invoice','delete_invoice'), true)) {
    $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($postedToken === '' || !hash_equals($invoiceCsrfToken, $postedToken)) {
        $_SESSION['invoice_view_error'] = 'Your form session expired. Refresh and try again.';
        header('Location: ' . basename($_SERVER['PHP_SELF']) . '?invoice_id=' . (int)(isset($_POST['invoice_id']) ? $_POST['invoice_id'] : 0));
        exit;
    }
    $postInvoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT id,branch_id,invoice_no,status,total,amount_paid,balance_due FROM invoices WHERE id=:i AND tenant_id=:t LIMIT 1 FOR UPDATE");
        $st->execute(array(':i'=>$postInvoiceId, ':t'=>$tenantId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Invoice not found.');
        $oldStatus = (string)$row['status'];
        if ($_POST['action'] === 'delete_invoice') {
            $paymentCount = 0;
            if (ivTable($pdo, 'payments')) {
                $pc = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE tenant_id=:t AND invoice_id=:i AND status='succeeded'");
                $pc->execute(array(':t'=>$tenantId, ':i'=>$postInvoiceId));
                $paymentCount = (int)$pc->fetchColumn();
            }
            if ((float)$row['amount_paid'] > 0.005 || $paymentCount > 0) {
                throw new RuntimeException('This invoice has payment history and cannot be deleted. Close it instead.');
            }
            $newStatus = 'cancelled';
            $message = 'Invoice cancelled successfully. Audit history has been retained.';
            $auditAction = 'INVOICE_CANCELLED';
        } else {
            $newStatus = 'archived';
            $message = 'Invoice closed successfully.';
            $auditAction = 'INVOICE_CLOSED';
        }
        $up = $pdo->prepare("UPDATE invoices SET status=:s,updated_at=NOW() WHERE id=:i AND tenant_id=:t");
        $up->execute(array(':s'=>$newStatus, ':i'=>$postInvoiceId, ':t'=>$tenantId));
        $pdo->commit();
        if (function_exists('tenantAuditLog')) {
            try { tenantAuditLog($pdo, $auditAction, $tenantId, !empty($row['branch_id'])?(int)$row['branch_id']:null, $userId, 'invoice', $postInvoiceId, array('status'=>$oldStatus), array('status'=>$newStatus,'invoice_no'=>$row['invoice_no'])); }
            catch (Throwable $auditError) { error_log('Invoice status audit: '.$auditError->getMessage()); }
        }
        $_SESSION['invoice_view_success'] = $message;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['invoice_view_error'] = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update invoice.';
    }
    header('Location: ' . basename($_SERVER['PHP_SELF']) . '?invoice_id=' . $postInvoiceId);
    exit;
}

/* ----------------------------------------------------------
   Load invoice directly from database
   ---------------------------------------------------------- */
if ($invoiceId <= 0 && $jobId > 0) {
    $find = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id=:t AND job_id=:j AND status NOT IN('cancelled','archived') ORDER BY id DESC LIMIT 1");
    $find->execute(array(':t'=>$tenantId, ':j'=>$jobId));
    $invoiceId = (int)$find->fetchColumn();
}

$invoice = array();
$items = array();
$payments = array();
$customFields = array();
$invoiceImages = array();
$invoiceAttachments = array();
$invoiceSignatures = array();
$history = array();
$billingLocation = array();
$pageError = '';
$currency = array('id'=>0,'symbol'=>'','symbol_position'=>'before','decimal_places'=>2);
$clientPreview = isset($_GET['client_preview']) && $_GET['client_preview'] === '1';

function ivJsonObject($value) {
    if (!is_string($value) || trim($value)==='') return array();
    $d = json_decode($value, true);
    return is_array($d) ? $d : array();
}
function ivScalarDisplay($value) {
    if ($value === null || $value === '') return 'empty';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return (string)$value;
}
function ivHistoryDiffs($oldValues, $newValues) {
    $old = ivJsonObject($oldValues); $new = ivJsonObject($newValues); $out = array();
    foreach (array_unique(array_merge(array_keys($old),array_keys($new))) as $key) {
        if (in_array($key,array('password','password_hash','token','csrf_token'),true)) continue;
        $a = array_key_exists($key,$old) ? $old[$key] : null; $b = array_key_exists($key,$new) ? $new[$key] : null;
        if (json_encode($a) === json_encode($b)) continue;
        $out[] = array('field'=>ucwords(str_replace('_',' ',$key)), 'old'=>ivScalarDisplay($a), 'new'=>ivScalarDisplay($b));
        if (count($out)>=6) break;
    }
    return $out;
}

if ($invoiceId > 0) {
    try {
        $creatorSelect = ivTable($pdo,'users') ? ",TRIM(CONCAT(COALESCE(cu.first_name,''),CASE WHEN cu.last_name IS NOT NULL AND cu.last_name<>'' THEN CONCAT(' ',cu.last_name) ELSE '' END)) AS salesperson_name,cu.email AS salesperson_email" : ",'Current user' AS salesperson_name,NULL AS salesperson_email";
        $creatorJoin = ivTable($pdo,'users') ? " LEFT JOIN users cu ON cu.id=i.created_by AND cu.tenant_id=i.tenant_id " : '';
        $sql = "SELECT i.*,
                       c.display_name AS client_name,c.company_name AS client_company,c.email AS client_email,c.phone AS client_phone,
                       cl.name AS location_name,cl.address_line1,cl.address_line2,cl.city,cl.state,cl.postal_code,
                       j.job_no,q.quote_no,
                       b.name AS branch_name,b.email AS branch_email,b.phone AS branch_phone,b.address_line1 AS branch_address_line1,b.address_line2 AS branch_address_line2,b.city AS branch_city,b.state AS branch_state,b.postal_code AS branch_postal_code,b.logo_path AS branch_logo_path,b.invoice_logo_path AS branch_invoice_logo_path,b.currency_id AS branch_currency_id,
                       t.legal_name AS tenant_legal_name,t.display_name AS tenant_name,t.email AS tenant_email,t.phone AS tenant_phone,t.tax_number AS tenant_tax_number,t.address_line1 AS tenant_address_line1,t.address_line2 AS tenant_address_line2,t.city AS tenant_city,t.state AS tenant_state,t.postal_code AS tenant_postal_code,t.logo_path AS tenant_logo_path,t.invoice_logo_path AS tenant_invoice_logo_path,t.currency_id AS tenant_currency_id".$creatorSelect."
                FROM invoices i
                INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id
                LEFT JOIN client_locations cl ON cl.id=i.location_id AND cl.tenant_id=i.tenant_id
                LEFT JOIN jobs j ON j.id=i.job_id AND j.tenant_id=i.tenant_id
                LEFT JOIN quotes q ON q.id=i.quote_id AND q.tenant_id=i.tenant_id
                LEFT JOIN branches b ON b.id=i.branch_id AND b.tenant_id=i.tenant_id
                INNER JOIN tenants t ON t.id=i.tenant_id".$creatorJoin."
                WHERE i.id=:id AND i.tenant_id=:t LIMIT 1";
        $stmt=$pdo->prepare($sql);$stmt->execute(array(':id'=>$invoiceId,':t'=>$tenantId));$invoice=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) throw new RuntimeException('Invoice not found.');

        $currencyId=!empty($invoice['branch_currency_id'])?(int)$invoice['branch_currency_id']:(!empty($invoice['tenant_currency_id'])?(int)$invoice['tenant_currency_id']:0);
        if($currencyId>0){$cs=$pdo->prepare("SELECT id,currency_code,currency_name,symbol,symbol_position,decimal_places,decimal_separator,thousand_separator FROM currencies WHERE id=:id LIMIT 1");$cs->execute(array(':id'=>$currencyId));$cr=$cs->fetch(PDO::FETCH_ASSOC);if($cr)$currency=$cr;}

        if(ivTable($pdo,'invoice_line_items')){
            $hasProductId=ivColumn($pdo,'invoice_line_items','product_id');
            $hasItemSource=ivColumn($pdo,'invoice_line_items','item_source');
            if($hasProductId && ivTable($pdo,'products') && ivTable($pdo,'product_services')){
                $psImg=ivColumn($pdo,'product_services','image_path')?'ps.image_path':'NULL';
                $pImg=ivColumn($pdo,'products','image_path')?'p.image_path':'NULL';
                $li=$pdo->prepare("SELECT ili.*,COALESCE(".$psImg.",".$pImg.") AS catalog_image FROM invoice_line_items ili LEFT JOIN product_services ps ON ps.id=ili.product_service_id LEFT JOIN products p ON p.id=ili.product_id WHERE ili.invoice_id=:i ORDER BY ili.sort_order,ili.id");
            } else {
                $li=$pdo->prepare("SELECT ili.*,NULL AS catalog_image FROM invoice_line_items ili WHERE ili.invoice_id=:i ORDER BY ili.sort_order,ili.id");
            }
            $li->execute(array(':i'=>$invoiceId));$items=$li->fetchAll(PDO::FETCH_ASSOC);
        }
        if(ivTable($pdo,'payments')){$ps=$pdo->prepare("SELECT id,payment_no,payment_method,payment_channel,status,amount,provider,provider_payment_id,received_at,notes,created_at FROM payments WHERE tenant_id=:t AND invoice_id=:i ORDER BY COALESCE(received_at,created_at) DESC,id DESC");$ps->execute(array(':t'=>$tenantId,':i'=>$invoiceId));$payments=$ps->fetchAll(PDO::FETCH_ASSOC);}
        if(ivTable($pdo,'invoice_custom_fields')){$cf=$pdo->prepare("SELECT field_label,field_value,sort_order FROM invoice_custom_fields WHERE tenant_id=:t AND invoice_id=:i ORDER BY sort_order,id");$cf->execute(array(':t'=>$tenantId,':i'=>$invoiceId));$customFields=$cf->fetchAll(PDO::FETCH_ASSOC);}
        if(ivTable($pdo,'attachments')){
            $at=$pdo->prepare("SELECT id,file_name,file_path,file_mime,file_size,attachment_type,description,created_at FROM attachments WHERE tenant_id=:t AND related_type='invoice' AND related_id=:i ORDER BY id");$at->execute(array(':t'=>$tenantId,':i'=>$invoiceId));
            foreach($at->fetchAll(PDO::FETCH_ASSOC) as $a){$mime=strtolower((string)$a['file_mime']);$ext=strtolower(pathinfo((string)$a['file_name'],PATHINFO_EXTENSION));$isImage=strpos($mime,'image/')===0||in_array($ext,array('jpg','jpeg','png','webp','gif','avif','heic'),true);if((string)$a['attachment_type']==='signature')$invoiceSignatures[]=$a;elseif($isImage && (string)$a['attachment_type']!=='document')$invoiceImages[]=$a;else $invoiceAttachments[]=$a;}
        }
        if(ivTable($pdo,'client_locations')){
            $bl=$pdo->prepare("SELECT * FROM client_locations WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL AND status='active' ORDER BY (location_type='billing') DESC,is_primary DESC,id ASC LIMIT 1");
            $bl->execute(array(':t'=>$tenantId,':c'=>(int)$invoice['client_id']));$billingLocation=$bl->fetch(PDO::FETCH_ASSOC);if(!$billingLocation)$billingLocation=array();
        }
        if(ivTable($pdo,'audit_logs')){
            $hasUserName=ivColumn($pdo,'audit_logs','user_name');
            $userExpr=$hasUserName?"COALESCE(NULLIF(al.user_name,''),TRIM(CONCAT(COALESCE(hu.first_name,''),CASE WHEN hu.last_name IS NOT NULL AND hu.last_name<>'' THEN CONCAT(' ',hu.last_name) ELSE '' END)),'System')":"COALESCE(TRIM(CONCAT(COALESCE(hu.first_name,''),CASE WHEN hu.last_name IS NOT NULL AND hu.last_name<>'' THEN CONCAT(' ',hu.last_name) ELSE '' END)),'System')";
            $hq=$pdo->prepare("SELECT al.id,al.action,al.old_values,al.new_values,al.created_at,".$userExpr." actor_name FROM audit_logs al LEFT JOIN users hu ON hu.id=al.user_id AND hu.tenant_id=al.tenant_id WHERE al.tenant_id=:t AND al.object_type='invoice' AND al.object_id=:i ORDER BY al.created_at DESC,al.id DESC LIMIT 100");
            $hq->execute(array(':t'=>$tenantId,':i'=>$invoiceId));
            foreach($hq->fetchAll(PDO::FETCH_ASSOC) as $h){$history[]=array('kind'=>'audit','type'=>'invoice','actor'=>$h['actor_name']?:'System','action'=>(string)$h['action'],'created_at'=>$h['created_at'],'changes'=>ivHistoryDiffs($h['old_values'],$h['new_values']));}
        }
        foreach($payments as $p){$history[]=array('kind'=>'payment','type'=>'payment','actor'=>'Team member','action'=>'PAYMENT_RECORDED','created_at'=>!empty($p['received_at'])?$p['received_at']:$p['created_at'],'changes'=>array(array('field'=>'Amount','old'=>'empty','new'=>ivMoney($p['amount'],$currency)),array('field'=>'Payment method','old'=>'empty','new'=>ivTitle($p['payment_method']))));}
        usort($history,function($a,$b){return strcmp((string)$b['created_at'],(string)$a['created_at']);});
    }catch(Throwable $e){error_log('FieldPlx invoice direct load: '.$e->getMessage());$pageError=$e->getMessage()!==''?$e->getMessage():'Unable to load invoice.';}
}else{$pageError='Invoice or completed job is required.';}

$invoice=is_array($invoice)?$invoice:array();
$status=isset($invoice['status'])?(string)$invoice['status']:'draft';
$balanceDue=isset($invoice['balance_due'])?(float)$invoice['balance_due']:0.0;
$canCollect=$invoiceId>0&&$balanceDue>0.005&&!in_array($status,array('cancelled','archived','written_off'),true);
$isOverdue=$canCollect&&!empty($invoice['due_date'])&&$invoice['due_date']<date('Y-m-d');
$displayStatus=$status==='paid'?'Paid':($isOverdue?'Overdue':($canCollect?'Awaiting payment':ivTitle($status)));
$statusClass=$status==='paid'?'paid':($isOverdue?'overdue':($canCollect?'awaiting':''));
$clientViewDefaults=array('quantities'=>true,'unit_prices'=>true,'line_item_totals'=>true,'account_balance'=>true,'late_stamp'=>true);
$clientViewOptions=$clientViewDefaults;
if(array_key_exists('client_view_options',$invoice)&&!empty($invoice['client_view_options'])){$decoded=json_decode((string)$invoice['client_view_options'],true);if(is_array($decoded)){foreach($clientViewDefaults as $k=>$v){if(array_key_exists($k,$decoded))$clientViewOptions[$k]=(bool)$decoded[$k];}}}
$billingAddress=$billingLocation?ivAddress(array(isset($billingLocation['address_line1'])?$billingLocation['address_line1']:'',isset($billingLocation['address_line2'])?$billingLocation['address_line2']:'',isset($billingLocation['city'])?$billingLocation['city']:'',isset($billingLocation['state'])?$billingLocation['state']:'',isset($billingLocation['postal_code'])?$billingLocation['postal_code']:'')):'-';
$propertyAddress=ivAddress(array(isset($invoice['address_line1'])?$invoice['address_line1']:'',isset($invoice['address_line2'])?$invoice['address_line2']:'',isset($invoice['city'])?$invoice['city']:'',isset($invoice['state'])?$invoice['state']:'',isset($invoice['postal_code'])?$invoice['postal_code']:''));
$sameAddress=$billingAddress!=='-'&&$propertyAddress!=='-'&&strtolower($billingAddress)===strtolower($propertyAddress);
$subject=!empty($invoice['subject'])?(string)$invoice['subject']:(!empty($invoice['invoice_no'])?'Invoice '.$invoice['invoice_no']:'Invoice');
$sourceLabel=!empty($invoice['job_id'])?'From Job Card':'Direct Invoice';
$salesperson=trim(isset($invoice['salesperson_name'])?(string)$invoice['salesperson_name']:'');if($salesperson==='')$salesperson='Current user';
if($clientPreview && empty($clientViewOptions['late_stamp']) && $isOverdue){$displayStatus='Awaiting payment';$statusClass='awaiting';}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Invoice - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <style>
/* ==========================================================
           FieldPlx canonical tenant shell
           Same shell used by Customers / Jobs / Quotations pages.
           ========================================================== */
        :root{
            --fieldplx-primary:#74b824;
            --fieldplx-primary-dark:#5d971b;
            --fieldplx-text:#0b1933;
            --fieldplx-muted:#6f7b90;
            --fieldplx-border:#e5eaf1;
            --fieldplx-surface:#ffffff;
            --fieldplx-background:#f6f8fb;
            --fieldplx-topbar-height:70px;
            --fieldplx-sidebar-width:250px;
            --fieldplx-sidebar-collapsed-width:78px;

            --fd-navy:#001131;
            --fd-navy-light:#071f49;
            --fd-blue:#123d70;
            --fd-green:#74b824;
            --fd-green-dark:#5d971b;
            --fd-green-soft:#f0f8e5;
            --fd-red:#e45b66;
            --fd-bg:#f6f8fb;
            --fd-text:#0b1933;
            --fd-muted:#6f7b90;
            --fd-border:#e5eaf1;
        }

        *{
            box-sizing:border-box;
        }

        html,
        body{
            margin:0;
            min-height:100%;
            overflow-x:hidden;
        }

        body{
            min-height:100vh;
            background:var(--fd-bg)!important;
            color:var(--fd-text);
            font-family:Arial,Helvetica,sans-serif!important;
            font-size:14px;
        }

        a,
        a:link,
        a:visited,
        a:hover,
        a:focus,
        a:active{
            text-decoration:none!important;
        }

        /* ---------- Topbar ---------- */
        .fieldplx-topbar{
            min-height:70px!important;
            position:sticky!important;
            top:0!important;
            z-index:1030!important;
            margin-left:var(--fieldplx-sidebar-width);
            width:calc(100% - var(--fieldplx-sidebar-width));
            background:#fff!important;
            border-bottom:1px solid var(--fd-border)!important;
            box-shadow:0 3px 14px rgba(0,17,49,.035)!important;
            backdrop-filter:none!important;
            transition:margin-left .25s ease,width .25s ease;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-topbar{
            margin-left:var(--fieldplx-sidebar-collapsed-width);
            width:calc(100% - var(--fieldplx-sidebar-collapsed-width));
        }

        .fieldplx-topbar-inner{
            min-height:70px!important;
            padding:0 27px!important;
            display:flex!important;
            align-items:center!important;
            gap:13px!important;
        }

        .fieldplx-brand-mobile{
            display:none!important;
            align-items:center!important;
            gap:9px!important;
            min-width:0!important;
            color:var(--fd-text)!important;
        }

        .fieldplx-brand-logo{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            border-radius:10px!important;
            object-fit:contain!important;
        }

        .fieldplx-brand-placeholder{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:10px!important;
            color:#fff!important;
            background:linear-gradient(135deg,#8fd236,#68aa1d)!important;
            font-weight:700!important;
        }

        .fieldplx-brand-name{
            max-width:170px!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:var(--fd-text)!important;
            font-size:14px!important;
            font-weight:700!important;
        }

        .fieldplx-page-heading{
            display:none!important;
        }

        .fieldplx-menu-toggle,
        .fieldplx-topbar-action{
            width:41px!important;
            height:41px!important;
            min-width:41px!important;
            padding:0!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            position:relative!important;
            border:0!important;
            border-radius:9px!important;
            color:var(--fd-navy)!important;
            background:transparent!important;
            font-size:18px!important;
            box-shadow:none!important;
        }

        .fieldplx-menu-toggle:hover,
        .fieldplx-topbar-action:hover{
            color:var(--fd-navy)!important;
            background:var(--fd-green-soft)!important;
        }

        .fieldplx-search-wrap{
            width:280px!important;
            margin-left:auto!important;
            position:relative!important;
        }

        .fieldplx-search-icon{
            position:absolute!important;
            top:50%!important;
            left:13px!important;
            z-index:2!important;
            transform:translateY(-50%)!important;
            color:#98a3b2!important;
            font-size:14px!important;
            pointer-events:none!important;
        }

        .fieldplx-search-input{
            width:100%!important;
            height:41px!important;
            padding:8px 13px 8px 38px!important;
            border:0!important;
            border-radius:8px!important;
            outline:0!important;
            background:#f5f8fb!important;
            color:var(--fd-text)!important;
            font-size:12px!important;
            box-shadow:none!important;
        }

        .fieldplx-search-input:focus{
            background:#f5f8fb!important;
            box-shadow:0 0 0 3px rgba(116,184,36,.14)!important;
        }

        .fieldplx-notification-count{
            position:absolute!important;
            top:-5px!important;
            right:-5px!important;
            min-width:18px!important;
            height:18px!important;
            padding:0 5px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border:2px solid #fff!important;
            border-radius:999px!important;
            color:#fff!important;
            background:var(--fd-red)!important;
            font-size:9px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-button{
            min-width:0!important;
            padding:2px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border:0!important;
            border-radius:9px!important;
            background:transparent!important;
            color:var(--fd-text)!important;
            text-align:left!important;
            box-shadow:none!important;
        }

        .fieldplx-profile-button:hover{
            background:var(--fd-green-soft)!important;
        }

        .fieldplx-avatar{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            overflow:hidden!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border:0!important;
            border-radius:50%!important;
            color:var(--fd-navy)!important;
            background:linear-gradient(135deg,#fff,#e8f3d9)!important;
            font-size:12px!important;
            font-weight:800!important;
        }

        .fieldplx-avatar img{
            width:100%!important;
            height:100%!important;
            object-fit:cover!important;
        }

        .fieldplx-profile-details{
            max-width:145px!important;
            min-width:0!important;
        }

        .fieldplx-profile-name,
        .fieldplx-profile-role{
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-profile-name{
            color:#111827!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-role{
            margin-top:1px!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
        }

        /* ---------- Dropdowns ---------- */
        .fieldplx-dropdown{
            width:340px!important;
            max-width:calc(100vw - 24px)!important;
            padding:0!important;
            margin-top:10px!important;
            overflow:hidden!important;
            border:1px solid var(--fd-border)!important;
            border-radius:14px!important;
            background:#fff!important;
            box-shadow:0 18px 45px rgba(29,38,74,.14)!important;
        }

        .fieldplx-dropdown-header{
            min-height:48px!important;
            padding:11px 16px!important;
            display:flex!important;
            align-items:center!important;
            justify-content:space-between!important;
            border-bottom:1px solid var(--fd-border)!important;
            background:#fff!important;
        }

        .fieldplx-dropdown-title{
            margin:0!important;
            color:#111827!important;
            font-size:14px!important;
            font-weight:700!important;
        }

        #topbarNotificationList{
            max-height:300px!important;
            overflow-y:auto!important;
            background:#fff!important;
        }

        .fieldplx-notification-item{
            padding:11px 14px!important;
            display:flex!important;
            gap:10px!important;
            border-bottom:1px solid #f1f2f4!important;
            color:inherit!important;
            text-decoration:none!important;
        }

        .fieldplx-notification-item:hover,
        .fieldplx-notification-item.is-unread{
            background:#f8fbf3!important;
        }

        .fieldplx-notification-icon{
            width:32px!important;
            height:32px!important;
            flex:0 0 32px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:9px!important;
            color:var(--fd-green-dark)!important;
            background:var(--fd-green-soft)!important;
            font-size:14px!important;
        }

        .fieldplx-notification-content{
            min-width:0!important;
        }

        .fieldplx-notification-title{
            margin:0!important;
            color:#111827!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-notification-message{
            margin-top:3px!important;
            overflow:hidden!important;
            display:-webkit-box!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
            line-height:1.45!important;
            -webkit-line-clamp:2!important;
            -webkit-box-orient:vertical!important;
        }

        .fieldplx-notification-time{
            margin-top:4px!important;
            color:#9ca3af!important;
            font-size:9px!important;
        }

        .fieldplx-empty-notifications{
            min-height:155px!important;
            padding:28px 18px 24px!important;
            display:flex!important;
            flex-direction:column!important;
            align-items:center!important;
            justify-content:center!important;
            color:#718096!important;
            background:#fff!important;
            text-align:center!important;
            font-size:13px!important;
        }

        .fieldplx-empty-notifications i{
            margin-bottom:10px!important;
            color:#a9cf75!important;
            font-size:30px!important;
        }

        .fieldplx-dropdown-footer{
            min-height:44px!important;
            padding:10px 14px!important;
            display:flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-top:1px solid var(--fd-border)!important;
            background:#fff!important;
        }

        .fieldplx-dropdown-footer a{
            color:var(--fd-green-dark)!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-menu{
            width:230px!important;
            padding:7px!important;
            border:1px solid var(--fd-border)!important;
            border-radius:12px!important;
            background:#fff!important;
            box-shadow:0 18px 45px rgba(29,38,74,.14)!important;
        }

        .fieldplx-profile-menu-header{
            padding:9px 10px 11px!important;
            border-bottom:1px solid #f0f1f3!important;
        }

        .fieldplx-profile-menu-name{
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:#111827!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-menu-email{
            margin-top:2px!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
        }

        .fieldplx-profile-menu .dropdown-item{
            padding:9px 10px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border-radius:8px!important;
            color:#374151!important;
            background:transparent!important;
            font-size:11px!important;
        }

        .fieldplx-profile-menu .dropdown-item:hover{
            color:var(--fd-green-dark)!important;
            background:var(--fd-green-soft)!important;
        }

        /* ---------- Sidebar ---------- */
        .fieldplx-sidebar{
            width:var(--fieldplx-sidebar-width)!important;
            min-width:var(--fieldplx-sidebar-width)!important;
            height:100vh!important;
            position:fixed!important;
            top:0!important;
            left:0!important;
            z-index:1045!important;
            display:flex!important;
            flex-direction:column!important;
            color:#fff!important;
            background:linear-gradient(180deg,var(--fd-navy-light),var(--fd-navy))!important;
            border-right:0!important;
            transition:width .25s ease,min-width .25s ease,transform .25s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
            width:var(--fieldplx-sidebar-collapsed-width)!important;
            min-width:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-sidebar-header{
            min-height:68px!important;
            padding:9px 14px 10px!important;
            display:flex!important;
            align-items:center!important;
            border-bottom:1px solid rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-brand{
            min-width:0!important;
            display:flex!important;
            align-items:center!important;
            gap:10px!important;
            color:#fff!important;
        }

        .fieldplx-sidebar-logo,
        .fieldplx-sidebar-logo-placeholder{
            width:40px!important;
            height:40px!important;
            flex:0 0 40px!important;
            border-radius:10px!important;
        }

        .fieldplx-sidebar-logo{
            object-fit:contain!important;
        }

        .fieldplx-sidebar-logo-placeholder{
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            color:#fff!important;
            background:linear-gradient(135deg,#8fd236,#68aa1d)!important;
            font-size:18px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-brand-text{
            min-width:0!important;
            display:block!important;
        }

        .fieldplx-sidebar-company-name{
            max-width:155px!important;
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:#fff!important;
            font-size:16px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-product-name{
            margin-top:1px!important;
            display:block!important;
            color:#9fda55!important;
            font-size:9px!important;
            font-weight:600!important;
            letter-spacing:.4px!important;
            text-transform:uppercase!important;
        }

        .fieldplx-sidebar-close{
            width:32px!important;
            height:32px!important;
            margin-left:auto!important;
            padding:0!important;
            display:none!important;
            align-items:center!important;
            justify-content:center!important;
            border:0!important;
            border-radius:8px!important;
            color:rgba(255,255,255,.82)!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-body{
            min-height:0!important;
            flex:1 1 auto!important;
            overflow-y:auto!important;
            overflow-x:hidden!important;
            padding:12px 14px!important;
            scrollbar-width:none!important;
        }

        .fieldplx-sidebar-body::-webkit-scrollbar{
            display:none!important;
        }

        .fieldplx-sidebar-section-label{
            margin:7px 12px!important;
            color:rgba(255,255,255,.5)!important;
            font-size:9px!important;
            font-weight:700!important;
            letter-spacing:.65px!important;
            text-transform:uppercase!important;
        }

        .fieldplx-sidebar-nav{
            display:flex!important;
            flex-direction:column!important;
            gap:3px!important;
        }

        .fieldplx-sidebar-link{
            width:100%!important;
            min-height:46px!important;
            margin-bottom:3px!important;
            padding:0 14px!important;
            display:flex!important;
            align-items:center!important;
            gap:15px!important;
            border:0!important;
            border-radius:9px!important;
            color:rgba(255,255,255,.94)!important;
            background:transparent!important;
            text-align:left!important;
            font-family:inherit!important;
            font-size:14px!important;
            font-weight:600!important;
        }

        .fieldplx-sidebar-link:hover{
            color:#fff!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-link.active,
        .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link{
            color:#fff!important;
            background:linear-gradient(90deg,#7fc92d,#68aa1d)!important;
            box-shadow:0 6px 18px rgba(0,17,49,.28)!important;
        }

        .fieldplx-sidebar-link-icon{
            width:21px!important;
            height:21px!important;
            flex:0 0 21px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            font-size:19px!important;
        }

        .fieldplx-sidebar-link-text{
            min-width:0!important;
            flex:1!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-sidebar-arrow{
            margin-left:auto!important;
            color:rgba(255,255,255,.65)!important;
            font-size:10px!important;
            transition:transform .2s ease!important;
        }

        .fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow{
            transform:rotate(180deg)!important;
        }

        .fieldplx-sidebar-submenu{
            max-height:0!important;
            overflow:hidden!important;
            padding-left:36px!important;
            transition:max-height .25s ease,padding-top .25s ease,padding-bottom .25s ease!important;
        }

        .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu{
            max-height:680px!important;
            padding-top:4px!important;
            padding-bottom:5px!important;
        }

        .fieldplx-sidebar-sublink{
            min-height:34px!important;
            padding:7px 9px!important;
            display:flex!important;
            align-items:center!important;
            border-radius:7px!important;
            color:rgba(255,255,255,.72)!important;
            background:transparent!important;
            font-size:11px!important;
            font-weight:500!important;
        }

        .fieldplx-sidebar-sublink::before{
            width:5px!important;
            height:5px!important;
            margin-right:9px!important;
            flex:0 0 5px!important;
            content:""!important;
            border-radius:50%!important;
            background:rgba(255,255,255,.35)!important;
        }

        .fieldplx-sidebar-sublink:hover,
        .fieldplx-sidebar-sublink.active{
            color:#fff!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-sublink.active::before{
            background:#9fda55!important;
        }

        .fieldplx-sidebar-footer{
            flex:0 0 auto!important;
            padding:10px 14px 14px!important;
            border-top:1px solid rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-user{
            min-height:62px!important;
            padding:8px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border-radius:10px!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-user-avatar{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:50%!important;
            color:var(--fd-navy)!important;
            background:linear-gradient(135deg,#fff,#e8f3d9)!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-user-details{
            min-width:0!important;
            flex:1!important;
        }

        .fieldplx-sidebar-user-name,
        .fieldplx-sidebar-user-role{
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-sidebar-user-name{
            color:#fff!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-user-role{
            margin-top:1px!important;
            color:rgba(255,255,255,.6)!important;
            font-size:9px!important;
        }

        .fieldplx-sidebar-logout{
            width:29px!important;
            height:29px!important;
            flex:0 0 29px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:8px!important;
            color:rgba(255,255,255,.7)!important;
            font-size:14px!important;
        }

        .fieldplx-sidebar-logout:hover{
            color:#fff!important;
            background:rgba(228,91,102,.3)!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout{
            display:none!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user{
            justify-content:center!important;
        }

        /* ---------- Main content ---------- */
        .fieldplx-main-layout{
            display:block!important;
            min-height:calc(100vh - 70px)!important;
        }

        .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-width)!important;
            min-width:0!important;
            transition:margin-left .25s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-content-wrapper{
            padding:0!important;
        }

        /* ---------- Footer ---------- */
        .fieldplx-footer{
            min-height:52px!important;
            margin-left:var(--fieldplx-sidebar-width)!important;
            display:block!important;
            border-top:1px solid var(--fd-border)!important;
            background:#fff!important;
            transition:margin-left .22s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-footer{
            margin-left:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-footer-inner{
            min-height:52px!important;
            padding:10px 18px!important;
            display:flex!important;
            align-items:center!important;
            gap:18px!important;
            color:#6b7280!important;
            font-size:10px!important;
        }

        .fieldplx-footer-links{
            display:flex!important;
            align-items:center!important;
            gap:8px!important;
        }

        .fieldplx-footer-links a{
            color:#6b7280!important;
        }

        .fieldplx-footer-links a:hover,
        .fieldplx-footer-product strong{
            color:var(--fd-green-dark)!important;
        }

        .fieldplx-footer-separator{
            color:#d1d5db!important;
            font-size:8px!important;
        }

        .fieldplx-footer-product{
            margin-left:auto!important;
            white-space:nowrap!important;
            color:#9ca3af!important;
        }

        /* ---------- Mobile sidebar ---------- */
        .fieldplx-sidebar-overlay{
            display:none;
        }

        @media(max-width:991.98px){
            html,
            body{
                overflow-x:hidden!important;
            }

            body.fieldplx-sidebar-mobile-open{
                overflow:hidden!important;
            }

            .fieldplx-topbar,
            body.fieldplx-sidebar-collapsed .fieldplx-topbar{
                margin-left:0!important;
                width:100%!important;
            }

            .fieldplx-brand-mobile{
                display:flex!important;
            }

            .fieldplx-main-content,
            body.fieldplx-sidebar-collapsed .fieldplx-main-content{
                width:100%!important;
                margin-left:0!important;
            }

            .fieldplx-footer,
            body.fieldplx-sidebar-collapsed .fieldplx-footer{
                margin-left:0!important;
            }

            .fieldplx-sidebar,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                width:min(300px,calc(100vw - 52px))!important;
                min-width:0!important;
                max-width:300px!important;
                height:100vh!important;
                height:100dvh!important;
                position:fixed!important;
                top:0!important;
                bottom:0!important;
                left:0!important;
                z-index:1060!important;
                display:flex!important;
                flex-direction:column!important;
                overflow:hidden!important;
                visibility:hidden!important;
                transform:translate3d(-100%,0,0)!important;
                box-shadow:none!important;
                transition:transform .25s ease,visibility .25s ease!important;
            }

            body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
            body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                visibility:visible!important;
                transform:translate3d(0,0,0)!important;
            }

            .fieldplx-sidebar-close{
                display:inline-flex!important;
            }

            .fieldplx-sidebar-brand-text,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
            .fieldplx-sidebar-section-label,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
            .fieldplx-sidebar-link-text,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
            .fieldplx-sidebar-user-details,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details{
                display:block!important;
            }

            .fieldplx-sidebar-arrow,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
            .fieldplx-sidebar-logout,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout{
                display:inline-flex!important;
            }

            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user{
                justify-content:flex-start!important;
            }

            .fieldplx-sidebar-submenu,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu{
                display:block!important;
                max-height:0!important;
                overflow:hidden!important;
                padding-top:0!important;
                padding-bottom:0!important;
            }

            .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu{
                max-height:680px!important;
                padding-top:4px!important;
                padding-bottom:5px!important;
            }

            .fieldplx-sidebar-overlay{
                position:fixed!important;
                inset:0!important;
                z-index:1055!important;
                display:block!important;
                visibility:hidden!important;
                opacity:0!important;
                pointer-events:none!important;
                background:rgba(0,17,49,.48)!important;
                transition:opacity .25s ease,visibility .25s ease!important;
            }

            body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay{
                visibility:visible!important;
                opacity:1!important;
                pointer-events:auto!important;
            }
        }

        @media(max-width:767.98px){
            :root{
                --fieldplx-topbar-height:64px;
            }

            .fieldplx-topbar,
            .fieldplx-topbar-inner{
                min-height:64px!important;
            }

            .fieldplx-topbar-inner{
                padding:0 13px!important;
            }

            .fieldplx-search-wrap{
                display:none!important;
            }

            .fieldplx-profile-details{
                display:none!important;
            }

            .fieldplx-footer-inner{
                padding:12px!important;
                flex-wrap:wrap!important;
                justify-content:center!important;
                gap:7px 14px!important;
                text-align:center!important;
            }

            .fieldplx-footer-product{
                width:100%!important;
                margin-left:0!important;
            }
        }

        @media(max-width:575.98px){
            .fieldplx-sidebar,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                width:min(288px,calc(100vw - 44px))!important;
            }

            .fieldplx-sidebar-body{
                padding-left:10px!important;
                padding-right:10px!important;
            }

            .fieldplx-sidebar-link{
                min-height:43px!important;
                padding-left:12px!important;
                padding-right:12px!important;
                gap:12px!important;
                font-size:13px!important;
            }

            .fieldplx-sidebar-submenu{
                padding-left:31px!important;
            }
        }

        :root{
            --fieldplx-sidebar-width:250px;
            --fieldplx-sidebar-collapsed-width:78px;
            --fd-navy:#001131;
            --fd-navy-light:#071f49;
            --fd-blue:#123d70;
            --fd-green:#74b824;
            --fd-green-dark:#5d971b;
            --fd-green-soft:#f0f8e5;
            --fd-red:#e45b66;
            --fd-orange:#a97814;
            --fd-bg:#f6f8fb;
            --fd-text:#0b1933;
            --fd-muted:#6f7b90;
            --fd-border:#e5eaf1;
        }
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;overflow-x:hidden;background:var(--fd-bg)!important;color:var(--fd-text);font-family:Arial,Helvetica,sans-serif!important;font-size:14px}
        a,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}

        :root{
            --fieldplx-sidebar-width:250px;
            --fieldplx-sidebar-collapsed-width:78px;
            --fd-navy:#001131;
            --fd-navy-light:#071f49;
            --fd-blue:#123d70;
            --fd-green:#74b824;
            --fd-green-dark:#5d971b;
            --fd-green-soft:#f0f8e5;
            --fd-red:#e45b66;
            --fd-bg:#f6f8fb;
            --fd-text:#0b1933;
            --fd-muted:#6f7b90;
            --fd-border:#e5eaf1;
        }
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;overflow-x:hidden;background:var(--fd-bg)!important;color:var(--fd-text);font-family:Arial,Helvetica,sans-serif!important;font-size:14px}
        a,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}
.iv-page{width:100%;max-width:1600px;margin:auto;padding:25px 27px 36px}
        .iv-head{margin-bottom:17px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
        .iv-title-row{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
        .iv-title{margin:0;color:var(--fd-text);font-size:21px;line-height:1.2;font-weight:700}
        .iv-sub{margin:7px 0 0;color:var(--fd-muted);font-size:10.5px;line-height:1.55}
        .iv-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .iv-btn{min-height:40px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;color:#43546c;background:#fff;font-size:10px;font-weight:700;cursor:pointer}
        .iv-btn:hover{border-color:#cbd5e1;color:var(--fd-navy)}
        .iv-btn.primary{border-color:var(--fd-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);box-shadow:0 7px 16px rgba(104,170,29,.16)}
        .iv-btn:disabled{opacity:.55;cursor:not-allowed}
        .iv-badge{padding:5px 9px;border-radius:999px;color:#41536c;background:#edf2f7;font-size:9px;font-weight:700;text-transform:capitalize}
        .iv-badge.paid{color:#4f8618;background:#eaf6da}.iv-badge.partially_paid{color:#9b6c10;background:#fff4d8}.iv-badge.overdue{color:#b9444d;background:#fff0f1}.iv-badge.draft{color:#355a85;background:#edf4fb}
        .iv-summary{margin-bottom:16px}
        .iv-stat{min-height:112px;padding:18px 20px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}
        .iv-stat-row{min-height:72px;display:flex;align-items:center;gap:18px}.iv-stat-icon{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;border-radius:16px;color:#fff;background:#123f73;font-size:24px}
        .iv-stat-icon.green{background:#6aa91f}.iv-stat-icon.orange{background:#a97814}.iv-stat-icon.red{background:#b94c54}
        .iv-stat-label{display:block;margin-bottom:8px;color:#506784;font-size:12px}.iv-stat-value{display:block;color:#020b16;font-size:25px;line-height:1;font-weight:700}
        .iv-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(300px,.75fr);gap:16px;align-items:start}
        .iv-card{margin-bottom:16px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035);overflow:hidden}
        .iv-card-head{min-height:62px;padding:15px 17px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--fd-border);background:#fff}
        .iv-card-head h2{margin:0;font-size:13px;font-weight:700}.iv-card-head small{display:block;margin-top:4px;color:var(--fd-muted);font-size:9px}
        .iv-card-body{padding:16px 17px}
        .iv-company{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding-bottom:17px;margin-bottom:16px;border-bottom:1px solid var(--fd-border)}
        .iv-company-left{display:flex;align-items:flex-start;gap:13px;min-width:0}.iv-logo{width:62px;height:62px;display:grid;place-items:center;overflow:hidden;border:1px solid #e1e7ee;border-radius:11px;background:#f8fafc;color:var(--fd-green-dark);font-size:24px;font-weight:700}.iv-logo img{width:100%;height:100%;object-fit:contain}.iv-company h3{margin:1px 0 5px;font-size:16px}.iv-company p{margin:2px 0;color:var(--fd-muted);font-size:10px;line-height:1.45}
        .iv-number{text-align:right}.iv-number small{display:block;margin-bottom:5px;color:#8793a5;font-size:9px;text-transform:uppercase}.iv-number strong{display:block;font-size:20px;color:var(--fd-navy)}
        .iv-info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.iv-info{min-height:72px;padding:11px 12px;border:1px solid #e8edf2;border-radius:9px;background:#fbfcfd}.iv-info span,.iv-info strong{display:block}.iv-info span{margin-bottom:6px;color:#8793a5;font-size:8.5px;font-weight:700;text-transform:uppercase}.iv-info strong{font-size:10.5px;line-height:1.45;overflow-wrap:anywhere}
        .iv-table-wrap{overflow:auto}.iv-table{width:100%;min-width:850px;border-collapse:collapse}.iv-table th{padding:10px 11px;text-align:left;color:#6f7b90;background:#f8fafc;border-bottom:1px solid var(--fd-border);font-size:8.5px;text-transform:uppercase;white-space:nowrap}.iv-table td{padding:11px;border-bottom:1px solid #edf1f4;color:#33445f;font-size:9.5px;vertical-align:top}.iv-table td.num,.iv-table th.num{text-align:right}.iv-item strong{display:block;color:var(--fd-text);font-size:10px}.iv-item small{display:block;margin-top:3px;color:var(--fd-muted);font-size:8.5px}
        .iv-totals{margin-left:auto;width:min(380px,100%);padding:13px 17px 17px}.iv-total-row{padding:8px 0;display:flex;justify-content:space-between;gap:20px;color:#53637a;font-size:10px}.iv-total-row.grand{margin-top:4px;padding-top:12px;border-top:1px solid var(--fd-border);color:var(--fd-text);font-size:13px;font-weight:700}.iv-total-row.balance strong{color:var(--fd-green-dark);font-size:15px}
        .iv-customer{padding:12px;border:1px solid #e8edf2;border-radius:9px;background:#fbfcfd}.iv-customer strong{display:block;font-size:12px}.iv-customer span{display:block;margin-top:5px;color:var(--fd-muted);font-size:9.5px;line-height:1.5;overflow-wrap:anywhere}
        .iv-pay-list{display:flex;flex-direction:column;gap:8px}.iv-payment{padding:11px 12px;border:1px solid #e8edf2;border-radius:9px;background:#fbfcfd}.iv-payment-top{display:flex;justify-content:space-between;gap:12px}.iv-payment strong{font-size:10.5px}.iv-payment-amount{color:var(--fd-green-dark)!important}.iv-payment small{display:block;margin-top:5px;color:var(--fd-muted);font-size:8.5px;line-height:1.45}.iv-empty{padding:26px 15px;text-align:center;color:var(--fd-muted);font-size:10px}
        .iv-modal-backdrop{position:fixed;inset:0;z-index:12000;padding:18px;display:none;align-items:center;justify-content:center;background:rgba(0,17,49,.56)}.iv-modal-backdrop.show{display:flex}.iv-modal{width:min(520px,100%);max-height:calc(100vh - 36px);overflow:auto;border-radius:13px;background:#fff;box-shadow:0 24px 70px rgba(0,17,49,.25)}
        .iv-modal-head,.iv-modal-foot{padding:15px 17px;display:flex;align-items:center;justify-content:space-between;gap:12px}.iv-modal-head{border-bottom:1px solid var(--fd-border)}.iv-modal-foot{border-top:1px solid var(--fd-border);justify-content:flex-end}.iv-modal-head h3{margin:0;font-size:14px}.iv-close{width:32px;height:32px;border:0;border-radius:8px;background:#f5f7fa;color:#50617a;cursor:pointer}.iv-modal-body{padding:17px}.iv-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.iv-field.full{grid-column:1/-1}.iv-field label{display:block;margin-bottom:6px;color:#42546c;font-size:9px;font-weight:700}.iv-field input,.iv-field select,.iv-field textarea{width:100%;min-height:40px;padding:9px 10px;border:1px solid #dfe5ec;border-radius:8px;outline:0;color:#263750;background:#fff;font:inherit;font-size:10px}.iv-field textarea{min-height:85px;resize:vertical}.iv-field input:focus,.iv-field select:focus,.iv-field textarea:focus{border-color:#b8d88d;box-shadow:0 0 0 3px rgba(116,184,36,.11)}.iv-help{margin-top:6px;color:var(--fd-muted);font-size:8.5px}
        .iv-toast{position:fixed;top:82px;right:18px;z-index:14000;width:min(380px,calc(100vw - 36px));padding:12px 14px;border-radius:9px;color:#fff;background:#123d70;box-shadow:0 12px 30px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s;font-size:10px;font-weight:700}.iv-toast.show{opacity:1;transform:translateY(0)}.iv-toast.success{background:#5d971b}.iv-toast.error{background:#e45b66}
        @media(max-width:1199.98px){.iv-grid{grid-template-columns:1fr}}
        
        @media(max-width:767.98px){.iv-page{padding:17px 13px 28px}.iv-head{flex-direction:column}.iv-actions{width:100%}.iv-actions .iv-btn{flex:1}.iv-info-grid{grid-template-columns:1fr 1fr}.iv-company{flex-direction:column}.iv-number{text-align:left}.iv-form-grid{grid-template-columns:1fr}.iv-field.full{grid-column:auto}}
        @media(max-width:520px){.iv-info-grid{grid-template-columns:1fr}}
        @media print{.fieldplx-topbar,.fieldplx-sidebar,.fieldplx-footer,.iv-actions,.iv-summary,.iv-modal-backdrop,.iv-toast,.fieldplx-toast{display:none!important}.fieldplx-main-content{margin-left:0!important}.iv-page{max-width:none;padding:0}.iv-grid{display:block}.iv-card{box-shadow:none;break-inside:avoid}}
    
        .iv-alert{margin-bottom:14px;padding:11px 13px;border:1px solid #cfe7b3;border-radius:9px;background:#f4faec;color:#4d7c18;font-size:10px;font-weight:700}.iv-alert.error{border-color:#f0c5c9;background:#fff3f4;color:#b9444d}
    

        /* ---------- Jobber-style invoice detail ---------- */
        :root{--jv-green:#2f8d25;--jv-green-dark:#24751d;--jv-text:#0b2b37;--jv-muted:#5d737f;--jv-border:#dbe3e7;--jv-soft:#f8faf9;--jv-yellow:#f5efc8;--jv-red:#c44343}
        .jv-page{width:100%;max-width:1600px;margin:0 auto;padding:24px 26px 44px;background:#fff;color:var(--jv-text)}
        .jv-shell{display:grid;grid-template-columns:minmax(0,1fr) 292px;gap:28px;align-items:start}
        .jv-main{min-width:0}.jv-side{min-width:0;position:sticky;top:88px}
        .jv-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
        .jv-toolbar-left,.jv-toolbar-actions{display:flex;align-items:center;gap:10px}.jv-toolbar-actions{position:relative}
        .jv-doc-icon{font-size:19px;color:#2d6d94}.jv-status{display:inline-flex;align-items:center;gap:7px;min-height:24px;padding:3px 10px;border-radius:999px;background:#eef3ed;color:#45604b;font-size:12px;font-weight:600}
        .jv-status:before{content:"";width:7px;height:7px;border-radius:50%;background:#7c9b78}.jv-status.awaiting,.jv-status.overdue{background:var(--jv-yellow);color:#6b5d18}.jv-status.awaiting:before{background:#ddc623}.jv-status.overdue{background:#fff0ed;color:#a43f36}.jv-status.overdue:before{background:#d95b50}.jv-status.paid{background:#e7f4e2;color:#2d7424}.jv-status.paid:before{background:var(--jv-green)}
        .jv-icon-btn,.jv-btn{height:38px;border:1px solid var(--jv-border);border-radius:7px;background:#fff;color:#314e5b;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px;text-decoration:none!important}.jv-icon-btn{width:38px;padding:0}.jv-btn{padding:0 13px}.jv-btn.primary{background:var(--jv-green);border-color:var(--jv-green);color:#fff}.jv-btn:hover,.jv-icon-btn:hover{background:#f6faf4;border-color:#bfd6b7}.jv-btn.primary:hover{background:var(--jv-green-dark)}
        .jv-title{margin:0 0 18px;color:#052a3a;font-size:31px;line-height:1.14;font-weight:750;letter-spacing:-.4px}
        .jv-top-grid{display:grid;grid-template-columns:minmax(300px,1.03fr) minmax(340px,.97fr);gap:24px;align-items:start;padding-bottom:28px;border-bottom:1px solid var(--jv-border)}
        .jv-client-card{min-height:220px;padding:20px 22px;border:1px solid var(--jv-border);border-radius:8px;background:#fff;position:relative}.jv-client-name{font-size:16px;font-weight:700;color:#123644;margin-bottom:13px}.jv-client-company{margin:-7px 0 12px;color:#607681;font-size:13px}.jv-address-label{margin-top:10px;color:#637985;font-size:12px}.jv-address{margin-top:3px;color:#2f4e5b;font-size:13px;line-height:1.38}.jv-contact-links{margin-top:13px;display:grid;gap:3px}.jv-contact-links a{width:max-content;max-width:100%;overflow-wrap:anywhere;color:#4b8b28!important;text-decoration:underline!important;font-size:13px}
        .jv-meta{border-top:1px solid transparent}.jv-meta-row{min-height:43px;display:grid;grid-template-columns:160px minmax(0,1fr);gap:12px;align-items:center;border-bottom:1px solid var(--jv-border);font-size:13px}.jv-meta-row span:first-child{color:#5a7280}.jv-meta-row strong,.jv-meta-row .jv-value{color:#264754;font-weight:500;min-width:0;overflow-wrap:anywhere}.jv-avatar{display:inline-flex;width:25px;height:25px;border-radius:50%;align-items:center;justify-content:center;background:#244e5e;color:#fff;font-size:10px;font-weight:700;margin-right:8px}
        .jv-card{margin-top:28px;border:1px solid var(--jv-border);border-radius:8px;background:#fff;overflow:hidden}.jv-card-head{min-height:70px;padding:18px 21px;display:flex;align-items:center;justify-content:space-between;gap:14px}.jv-card-head h2{margin:0;font-size:18px;color:#123644}.jv-card-body{padding:0 21px 20px}.jv-items{width:100%;border-collapse:collapse}.jv-items th{padding:12px 0;text-align:left;border-bottom:1px solid var(--jv-border);color:#173b49;font-size:12px}.jv-items th.num,.jv-items td.num{text-align:right}.jv-items td{padding:17px 0;border-bottom:1px solid #edf0f2;color:#33535f;font-size:13px;vertical-align:top}.jv-item-name{display:flex;align-items:flex-start;gap:10px}.jv-item-thumb{width:46px;height:46px;flex:0 0 46px;border-radius:7px;border:1px solid #e1e7ea;object-fit:cover;background:#f8fafb}.jv-item-name strong{display:block;color:#163846}.jv-item-name small{display:block;margin-top:5px;color:#5f7580;line-height:1.4}.jv-item-meta{margin-top:5px;display:flex;gap:8px;flex-wrap:wrap}.jv-item-badge{padding:2px 6px;border-radius:999px;background:#eef5eb;color:#427635;font-size:10px;font-weight:700}.jv-service-date{font-size:11px;color:#617781}
        .jv-totals{width:min(480px,100%);margin-left:auto;padding:12px 0 5px}.jv-total-row{min-height:39px;display:flex;align-items:center;justify-content:space-between;gap:20px;border-bottom:1px solid var(--jv-border);font-size:13px}.jv-total-row strong{color:#173946}.jv-total-row.grand{font-size:15px;font-weight:700;border-bottom:3px solid #e5e9eb}.jv-total-row.balance{margin-top:12px;padding:0 12px;border:0;border-radius:6px;background:#faf9f7}
        .jv-section-card{margin-top:18px;border:1px solid var(--jv-border);border-radius:8px;padding:18px 20px;background:#fff}.jv-section-card h3{margin:0 0 10px;font-size:16px}.jv-section-card p{margin:0;color:#405e6b;font-size:13px;line-height:1.55;white-space:pre-wrap}.jv-custom-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 18px;margin-top:8px}.jv-custom-item{padding:9px 0;border-bottom:1px solid #edf1f2}.jv-custom-item span{display:block;color:#71838c;font-size:11px}.jv-custom-item strong{display:block;margin-top:4px;color:#254653;font-size:13px;font-weight:500;overflow-wrap:anywhere}
        .jv-gallery{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.jv-gallery a{display:block;border:1px solid var(--jv-border);border-radius:8px;overflow:hidden;background:#f8fafb;aspect-ratio:4/3}.jv-gallery img{width:100%;height:100%;object-fit:cover;display:block}.jv-file-list{display:grid;gap:8px}.jv-file{min-height:42px;padding:8px 10px;border:1px solid #e2e8eb;border-radius:7px;display:flex;align-items:center;gap:9px;color:#385762!important;background:#fbfcfc}.jv-file span{min-width:0;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.jv-file small{color:#84959d}
        .jv-side-card{margin-bottom:15px;padding:17px;border:1px solid var(--jv-border);border-radius:8px;background:#fff}.jv-side-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}.jv-side-head h3{margin:0;font-size:15px;color:#103440}.jv-client-option{min-height:30px;display:flex;align-items:center;justify-content:space-between;gap:12px;color:#3b5966;font-size:12px}.jv-onoff{min-width:47px;height:22px;padding:0 9px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:#ebefef;color:#75878d;font-size:10px;font-weight:700}.jv-onoff.on{background:#e3f0df;color:#2f7928}.jv-note-empty{min-height:205px;border:1px dashed #cbd6db;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#4a6672;font-size:12px;padding:20px}.jv-note-empty i{width:48px;height:48px;margin-bottom:13px;border-radius:50%;display:grid;place-items:center;background:#f7f6f3;font-size:20px}.jv-note-box{padding:10px 0;border-bottom:1px solid #edf1f2}.jv-note-box:last-child{border-bottom:0}.jv-note-author{font-size:11px;color:#74858d}.jv-note-text{margin-top:4px;white-space:pre-wrap;color:#36545f;font-size:12px;line-height:1.45}.jv-note-mentions{margin-top:5px;display:flex;gap:5px;flex-wrap:wrap}.jv-note-mention{padding:2px 6px;border-radius:999px;background:#edf4fb;color:#315d7b;font-size:10px}.jv-note-files{margin-top:6px;display:grid;gap:5px}.jv-note-files a{font-size:11px;color:#4b8b28!important;text-decoration:underline!important}
        .jv-more-menu{position:absolute;top:44px;right:0;z-index:1500;width:215px;padding:7px 0;border:1px solid var(--jv-border);border-radius:8px;background:#fff;box-shadow:0 12px 28px rgba(13,48,62,.18);display:none}.jv-more-menu.show{display:block}.jv-menu-label{padding:8px 13px 5px;color:#67808b;font-size:11px;font-weight:700}.jv-menu-item{width:100%;min-height:36px;padding:7px 13px;border:0;background:#fff;display:flex;align-items:center;gap:10px;color:#294957!important;font:600 12px Arial,Helvetica,sans-serif;text-align:left;cursor:pointer;text-decoration:none!important}.jv-menu-item:hover{background:#f5faf2;color:var(--jv-green-dark)!important}.jv-menu-item.danger{color:#c33f3f!important}.jv-menu-item.disabled{opacity:.45;cursor:not-allowed}.jv-menu-divider{height:1px;margin:6px 0;background:#e6ebed}
        .jv-history-drawer{position:fixed;top:0;right:0;z-index:16000;width:min(410px,94vw);height:100vh;background:#fff;box-shadow:-12px 0 34px rgba(0,24,35,.18);transform:translateX(105%);transition:transform .2s ease;display:flex;flex-direction:column}.jv-history-drawer.show{transform:translateX(0)}.jv-history-head{padding:20px 18px 12px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--jv-border)}.jv-history-head h2{margin:0;font-size:21px}.jv-history-close{width:34px;height:34px;border:0;background:transparent;font-size:20px;cursor:pointer}.jv-history-filters{padding:12px 15px;display:flex;gap:7px;border-bottom:1px solid var(--jv-border);flex-wrap:wrap}.jv-history-filters select{height:32px;padding:4px 24px 4px 9px;border:1px solid #dadfdf;border-radius:999px;background:#f2f1ef;color:#334f5a;font-size:11px}.jv-history-list{padding:8px 15px 20px;overflow:auto;flex:1}.jv-history-event{padding:13px 0;border-bottom:1px solid #e4e8ea}.jv-history-event-head{display:flex;gap:9px}.jv-history-avatar{width:24px;height:24px;flex:0 0 24px;border-radius:50%;display:grid;place-items:center;background:#365866;color:#fff;font-size:8px;font-weight:700}.jv-history-event strong{font-size:12px;color:#274854}.jv-history-event small{display:block;margin-top:3px;color:#7c8e96;font-size:10px}.jv-history-changes{margin:7px 0 0 33px;display:grid;gap:5px}.jv-history-change{font-size:11px;color:#506a75}.jv-history-change b{color:#2f4f5c;font-weight:600}.jv-history-overlay{position:fixed;inset:0;z-index:15990;background:rgba(0,27,38,.12);opacity:0;pointer-events:none;transition:opacity .2s}.jv-history-overlay.show{opacity:1;pointer-events:auto}
        .jv-preview-banner{padding:10px 18px;background:#eef6e9;color:#356d2c;border-bottom:1px solid #d5e8cd;display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:13px}.jv-preview-banner a{color:#2f7826!important;text-decoration:underline!important}
        .jv-modal-backdrop{position:fixed;inset:0;z-index:17000;padding:18px;display:none;align-items:center;justify-content:center;background:rgba(0,17,49,.56)}.jv-modal-backdrop.show{display:flex}.jv-modal{width:min(520px,100%);max-height:calc(100vh - 36px);overflow:auto;border-radius:12px;background:#fff;box-shadow:0 24px 70px rgba(0,17,49,.25)}.jv-modal-head,.jv-modal-foot{padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}.jv-modal-head{border-bottom:1px solid var(--jv-border)}.jv-modal-foot{border-top:1px solid var(--jv-border);justify-content:flex-end}.jv-modal-head h3{margin:0;font-size:17px}.jv-modal-body{padding:18px}.jv-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.jv-field.full{grid-column:1/-1}.jv-field label{display:block;margin-bottom:6px;color:#425e69;font-size:12px;font-weight:700}.jv-field input,.jv-field select,.jv-field textarea{width:100%;min-height:40px;padding:9px 10px;border:1px solid #dfe5e8;border-radius:7px;outline:0;font:inherit;color:#274854;background:#fff}.jv-field textarea{min-height:86px;resize:vertical}.jv-close{width:32px;height:32px;border:0;border-radius:7px;background:#f4f6f6;cursor:pointer}.jv-alert{margin-bottom:14px;padding:11px 13px;border:1px solid #cfe7b3;border-radius:8px;background:#f4faec;color:#4d7c18;font-size:12px;font-weight:700}.jv-alert.error{border-color:#f0c5c9;background:#fff3f4;color:#b9444d}

        .jv-client-edit{width:30px;height:30px;padding:0;border:0;border-radius:6px;background:transparent;color:#315461;cursor:pointer}.jv-client-edit:hover{background:#f4f8f1;color:var(--jv-green-dark)}
        .jv-switch{display:inline-flex;align-items:center;cursor:pointer}.jv-switch input{position:absolute;opacity:0;pointer-events:none}.jv-switch-track{position:relative;width:42px;height:23px;border-radius:999px;background:#cfd9d6;display:block;transition:.16s}.jv-switch-knob{position:absolute;top:3px;left:3px;width:17px;height:17px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:.16s}.jv-switch-check{position:absolute;left:7px;top:2px;color:#fff;font-size:13px;opacity:0}.jv-switch input:checked+.jv-switch-track{background:#2f8d25}.jv-switch input:checked+.jv-switch-track .jv-switch-knob{left:22px}.jv-switch input:checked+.jv-switch-track .jv-switch-check{opacity:1}.jv-client-help{margin:8px 0 12px;padding-top:5px;color:#46616e;font-size:12px;line-height:1.4}.jv-client-actions{margin:8px -17px -17px;padding:13px 17px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--jv-border)}
        .jv-signature-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.jv-signature-saved{border:1px solid var(--jv-border);border-radius:8px;padding:10px;background:#fbfcfc}.jv-signature-saved a{display:block;height:120px;background:#fff;border-radius:6px;overflow:hidden}.jv-signature-saved img{width:100%;height:100%;object-fit:contain}.jv-signature-saved small{display:block;margin-top:7px;color:#71838c;font-size:11px}
        .jv-email-modal{width:min(840px,100%)}.jv-email-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(245px,.7fr);gap:20px}.jv-email-to{min-height:76px;padding:9px 10px;border:1px solid #dfe5e8;border-radius:8px;display:flex;align-content:flex-start;align-items:center;gap:6px;flex-wrap:wrap;background:#fff}.jv-email-to:focus-within{border-color:#90b97d;box-shadow:0 0 0 2px rgba(47,141,37,.08)}.jv-email-to-label{width:28px;color:#516b77;font-size:12px}.jv-email-chips{display:flex;gap:5px;flex-wrap:wrap}.jv-email-chip{height:34px;padding:0 9px 0 12px;border:1px solid #dce3e6;border-radius:999px;display:inline-flex;align-items:center;gap:8px;color:#375661;background:#fff;font-size:12px}.jv-email-chip button{width:18px;height:18px;padding:0;border:0;background:transparent;color:#526d79;cursor:pointer}.jv-email-to input[type=text]{min-width:140px;flex:1;height:34px;border:0!important;padding:4px!important;outline:0;box-shadow:none!important}.jv-email-field{margin-top:12px;position:relative}.jv-email-field label{position:absolute;top:6px;left:12px;color:#71838c;font-size:10px}.jv-email-field input,.jv-email-field textarea{width:100%;border:1px solid #dfe5e8;border-radius:7px;color:#274854;font:inherit;outline:0}.jv-email-field input{height:45px;padding:18px 12px 6px}.jv-email-field textarea{min-height:150px;padding:22px 12px 10px;resize:vertical;line-height:1.5}.jv-email-attachments h4{margin:2px 0 14px;font-size:14px}.jv-email-drop{min-height:112px;padding:14px;border:1px dashed #cbd6db;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;color:#667c86;text-align:center;font-size:11px}.jv-email-file-list{margin-top:10px;display:grid;gap:7px}.jv-email-file{min-height:42px;padding:8px 9px;border:1px solid #e2e8eb;border-radius:7px;display:flex;align-items:center;gap:8px;background:#fbfcfc;font-size:11px}.jv-email-file span{min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.jv-email-file button{border:0;background:transparent;cursor:pointer}.jv-email-limit{margin-top:10px;color:#71838c;font-size:10px}.jv-email-progress{height:6px;margin-top:5px;border-radius:999px;background:#e6e9e8;overflow:hidden}.jv-email-progress span{display:block;height:100%;width:0;background:#8db68a}.jv-email-invoice-file{margin-top:12px;min-height:52px;padding:8px;border:1px solid #dfe5e8;border-radius:7px;display:flex;align-items:center;gap:10px}.jv-email-pdf-icon{width:42px;height:42px;border-radius:5px;background:#f2f0e9;color:#db3b31;display:grid;place-items:center;font-size:20px}.jv-email-invoice-file small{display:block;color:#7d8d94;margin-top:2px}.jv-checkline{display:flex;align-items:center;gap:8px;color:#405f6b;font-size:12px}.jv-checkline input{width:18px;height:18px;accent-color:var(--jv-green)}
        .jv-signature-modal{width:min(650px,100%)}.jv-signature-wrap{position:relative;border:1px solid #dfe5e8;border-radius:8px;background:#fff;overflow:hidden}.jv-signature-canvas{display:block;width:100%;height:180px;touch-action:none;cursor:crosshair}.jv-signature-clear{position:absolute;top:8px;right:8px;height:30px;padding:0 11px;border:1px solid #dfe5e8;border-radius:6px;background:#fff;color:#355460;cursor:pointer}.jv-signature-line{position:absolute;left:10px;right:10px;bottom:23px;border-top:1px dashed #73858d}.jv-signature-label{position:absolute;bottom:5px;left:0;right:0;text-align:center;color:#687d86;font-size:10px}
        @media(max-width:760px){.jv-email-grid{grid-template-columns:1fr}.jv-email-modal{max-height:calc(100vh - 20px)}.jv-signature-canvas{height:160px}.jv-signature-grid{grid-template-columns:1fr}}
        .jv-client-preview .fieldplx-topbar,.jv-client-preview .fieldplx-sidebar,.jv-client-preview .fieldplx-footer{display:none!important}.jv-client-preview .fieldplx-main-content{margin-left:0!important}.jv-client-preview .jv-toolbar-actions,.jv-client-preview .jv-side{display:none!important}.jv-client-preview .jv-shell{grid-template-columns:1fr}.jv-client-preview .jv-page{max-width:1050px}.jv-client-preview .jv-staff-only{display:none!important}
        @media(max-width:1100px){.jv-shell{grid-template-columns:1fr}.jv-side{position:static;display:grid;grid-template-columns:1fr 1fr;gap:14px}.jv-side-card{margin:0}.jv-top-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:760px){.jv-page{padding:16px 13px 32px}.jv-toolbar{align-items:flex-start;flex-direction:column}.jv-toolbar-actions{width:100%;flex-wrap:wrap}.jv-title{font-size:25px}.jv-top-grid{grid-template-columns:1fr}.jv-meta-row{grid-template-columns:125px 1fr}.jv-side{grid-template-columns:1fr}.jv-gallery{grid-template-columns:repeat(2,minmax(0,1fr))}.jv-custom-grid{grid-template-columns:1fr}.jv-items{min-width:650px}.jv-card{overflow:auto}.jv-form-grid{grid-template-columns:1fr}.jv-field.full{grid-column:auto}}

    </style>
</head>
<body class="<?php echo $clientPreview?'jv-client-preview':''; ?>">
<?php require_once __DIR__ . '/includes/toast.php'; ?>
<?php if($clientPreview): ?>
<div class="jv-preview-banner"><span><i class="bi bi-eye"></i> Client preview — client-view settings are applied.</span><a href="invoice-view.php?invoice_id=<?php echo (int)$invoiceId; ?>">Back to staff view</a></div>
<?php else: ?>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<?php endif; ?>
<main class="fieldplx-main-content">
<div class="fieldplx-content-wrapper">
<div class="jv-page">
<?php if($pageError!==''): ?><div class="jv-alert error"><?php echo ivh($pageError); ?></div><?php endif; ?>

<div class="jv-shell">
<section class="jv-main">
    <div class="jv-toolbar">
        <div class="jv-toolbar-left"><i class="bi bi-file-earmark-text jv-doc-icon"></i><span class="jv-status <?php echo ivh($statusClass); ?>"><?php echo ivh($displayStatus); ?></span></div>
        <?php if(!$clientPreview && $invoiceId>0): ?>
        <div class="jv-toolbar-actions">
            <button type="button" class="jv-icon-btn" id="historyButton" title="Invoice history"><i class="bi bi-clock-history"></i></button>
            <button type="button" class="jv-btn" id="moreButton"><i class="bi bi-three-dots"></i> More</button>
            <div class="jv-more-menu" id="moreMenu">
                <div class="jv-menu-label">Send as...</div>
                <?php if(!empty($invoice['client_phone'])): ?><a class="jv-menu-item" href="sms:<?php echo ivh($invoice['client_phone']); ?>"><i class="bi bi-chat-square-text"></i> Text Message</a><?php else: ?><span class="jv-menu-item disabled"><i class="bi bi-chat-square-text"></i> Text Message</span><?php endif; ?>
                <button type="button" class="jv-menu-item" id="emailInvoiceButton"><i class="bi bi-envelope"></i> Email</button>
                <div class="jv-menu-divider"></div>
                <a class="jv-menu-item" href="add-invoice.php?similar_invoice_id=<?php echo (int)$invoiceId; ?>"><i class="bi bi-files"></i> Create Similar Invoice</a>
                <a class="jv-menu-item" target="_blank" href="invoice-view.php?invoice_id=<?php echo (int)$invoiceId; ?>&client_preview=1"><i class="bi bi-eye"></i> Preview as Client</a>
                <button type="button" class="jv-menu-item" id="signatureButton"><i class="bi bi-pen"></i> Collect Signature</button>
                <a class="jv-menu-item" target="_blank" href="invoice-print?invoice_id=<?php echo (int)$invoiceId; ?>"><i class="bi bi-file-earmark-pdf"></i> Print or Save PDF</a>
                <div class="jv-menu-divider"></div>
                <form method="post" onsubmit="return confirm('Close this invoice?');"><input type="hidden" name="csrf_token" value="<?php echo ivh($invoiceCsrfToken); ?>"><input type="hidden" name="invoice_id" value="<?php echo (int)$invoiceId; ?>"><input type="hidden" name="action" value="close_invoice"><button class="jv-menu-item" type="submit"><i class="bi bi-file-earmark-check"></i> Close Invoice</button></form>
                <form method="post" onsubmit="return confirm('Cancel this invoice? The record and audit history will be retained.');"><input type="hidden" name="csrf_token" value="<?php echo ivh($invoiceCsrfToken); ?>"><input type="hidden" name="invoice_id" value="<?php echo (int)$invoiceId; ?>"><input type="hidden" name="action" value="delete_invoice"><button class="jv-menu-item danger" type="submit"><i class="bi bi-trash"></i> Delete</button></form>
            </div>
            <?php if($canCollect): ?><button type="button" class="jv-btn primary" id="collectButton"><i class="bi bi-credit-card"></i> Collect Payment</button><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <h1 class="jv-title"><?php echo ivh($subject); ?></h1>

    <div class="jv-top-grid">
        <div class="jv-client-card">
            <div class="jv-client-name"><?php echo ivh(!empty($invoice['client_name'])?$invoice['client_name']:'-'); ?></div>
            <?php if(!empty($invoice['client_company'])): ?><div class="jv-client-company"><?php echo ivh($invoice['client_company']); ?></div><?php endif; ?>
            <div class="jv-address-label">Billing Address</div><div class="jv-address"><?php echo ivh($billingAddress); ?></div>
            <div class="jv-address-label">Property Address</div><div class="jv-address"><?php echo $sameAddress?'(Same as billing address)':ivh($propertyAddress); ?></div>
            <div class="jv-contact-links">
                <?php if(!empty($invoice['client_phone'])): ?><a href="tel:<?php echo ivh($invoice['client_phone']); ?>"><?php echo ivh($invoice['client_phone']); ?></a><?php endif; ?>
                <?php if(!empty($invoice['client_email'])): ?><a href="mailto:<?php echo ivh($invoice['client_email']); ?>"><?php echo ivh($invoice['client_email']); ?></a><?php endif; ?>
            </div>
        </div>
        <div class="jv-meta">
            <div class="jv-meta-row"><span>Invoice #</span><strong><?php echo ivh(isset($invoice['invoice_no'])?$invoice['invoice_no']:'-'); ?></strong></div>
            <div class="jv-meta-row"><span>Issued</span><span class="jv-value"><?php echo ivh(ivDate(isset($invoice['issue_date'])?$invoice['issue_date']:null)); ?></span></div>
            <div class="jv-meta-row"><span>Viewed in Client Hub</span><span class="jv-value"><?php echo !empty($invoice['viewed_at'])?ivh(ivDate($invoice['viewed_at'])):'Not viewed'; ?></span></div>
            <div class="jv-meta-row"><span>Payment terms</span><span class="jv-value"><?php echo ivh(!empty($invoice['payment_terms'])?$invoice['payment_terms']:'-'); ?></span></div>
            <div class="jv-meta-row"><span>Due date</span><span class="jv-value"><?php echo ivh(ivDate(isset($invoice['due_date'])?$invoice['due_date']:null)); ?></span></div>
            <div class="jv-meta-row"><span>Salesperson</span><span class="jv-value"><span class="jv-avatar"><?php echo ivh(strtoupper(substr($salesperson,0,1))); ?></span><?php echo ivh($salesperson); ?></span></div>
            <div class="jv-meta-row"><span>Invoice source</span><span class="jv-value"><?php echo ivh($sourceLabel); ?></span></div>
            <?php if(!empty($invoice['job_no'])): ?><div class="jv-meta-row"><span>Job Card</span><span class="jv-value"><?php echo ivh($invoice['job_no']); ?></span></div><?php endif; ?>
            <?php if(!empty($invoice['quote_no'])): ?><div class="jv-meta-row"><span>Quotation</span><span class="jv-value"><?php echo ivh($invoice['quote_no']); ?></span></div><?php endif; ?>
            <?php if(!empty($invoice['branch_name'])): ?><div class="jv-meta-row"><span>Branch</span><span class="jv-value"><?php echo ivh($invoice['branch_name']); ?></span></div><?php endif; ?>
        </div>
    </div>

    <?php if($customFields): ?><div class="jv-section-card"><h3>Custom details</h3><div class="jv-custom-grid"><?php foreach($customFields as $cf): ?><div class="jv-custom-item"><span><?php echo ivh($cf['field_label']); ?></span><strong><?php echo ivh($cf['field_value']!==null?$cf['field_value']:'-'); ?></strong></div><?php endforeach; ?></div></div><?php endif; ?>

    <section class="jv-card">
        <div class="jv-card-head"><h2>Product / Service</h2></div>
        <div class="jv-card-body">
            <div style="overflow:auto"><table class="jv-items"><thead><tr><th>Line Item</th><?php if(!$clientPreview||$clientViewOptions['quantities']): ?><th class="num">Quantity</th><?php endif; ?><?php if(!$clientPreview||$clientViewOptions['unit_prices']): ?><th class="num">Unit Price</th><?php endif; ?><?php if(!$clientPreview||$clientViewOptions['line_item_totals']): ?><th class="num">Total</th><?php endif; ?></tr></thead><tbody>
            <?php if(!$items): ?><tr><td colspan="4">No invoice items found.</td></tr><?php else: foreach($items as $item): ?>
            <tr>
                <td><div class="jv-item-name"><?php if(!empty($item['catalog_image'])): ?><img class="jv-item-thumb" src="<?php echo ivh($item['catalog_image']); ?>" alt=""><?php endif; ?><div><strong><?php echo ivh(isset($item['item_name'])?$item['item_name']:'-'); ?></strong><?php if(!empty($item['description'])): ?><small><?php echo ivh($item['description']); ?></small><?php endif; ?><div class="jv-item-meta"><?php if(!empty($item['item_source'])): ?><span class="jv-item-badge"><?php echo ivh(ivTitle($item['item_source'])); ?></span><?php endif; ?><?php if(!empty($item['service_date'])): ?><span class="jv-service-date"><i class="bi bi-calendar3"></i> Service date: <?php echo ivh(ivDate($item['service_date'])); ?></span><?php endif; ?></div></div></div></td>
                <?php if(!$clientPreview||$clientViewOptions['quantities']): ?><td class="num"><?php echo ivh(rtrim(rtrim(number_format((float)$item['quantity'],3,'.',''),'0'),'.')); ?></td><?php endif; ?>
                <?php if(!$clientPreview||$clientViewOptions['unit_prices']): ?><td class="num"><?php echo ivh(ivMoney($item['unit_price'],$currency)); ?></td><?php endif; ?>
                <?php if(!$clientPreview||$clientViewOptions['line_item_totals']): ?><td class="num"><strong><?php echo ivh(ivMoney($item['line_total'],$currency)); ?></strong></td><?php endif; ?>
            </tr>
            <?php endforeach; endif; ?>
            </tbody></table></div>
            <div class="jv-totals">
                <div class="jv-total-row"><span>Subtotal</span><strong><?php echo ivh(ivMoney(isset($invoice['subtotal'])?$invoice['subtotal']:0,$currency)); ?></strong></div>
                <?php if((float)(isset($invoice['discount_total'])?$invoice['discount_total']:0)>0.005): ?><div class="jv-total-row"><span>Discount</span><strong>-<?php echo ivh(ivMoney($invoice['discount_total'],$currency)); ?></strong></div><?php endif; ?>
                <?php if((float)(isset($invoice['tax_total'])?$invoice['tax_total']:0)>0.005): ?><div class="jv-total-row"><span>Tax</span><strong><?php echo ivh(ivMoney($invoice['tax_total'],$currency)); ?></strong></div><?php endif; ?>
                <div class="jv-total-row grand"><span>Total</span><strong><?php echo ivh(ivMoney(isset($invoice['total'])?$invoice['total']:0,$currency)); ?></strong></div>
                <?php if((float)(isset($invoice['amount_paid'])?$invoice['amount_paid']:0)>0.005): ?><div class="jv-total-row"><span>Amount paid</span><strong><?php echo ivh(ivMoney($invoice['amount_paid'],$currency)); ?></strong></div><?php endif; ?>
                <?php if(!$clientPreview||$clientViewOptions['account_balance']): ?><div class="jv-total-row balance"><span>Invoice balance</span><strong><?php echo ivh(ivMoney($balanceDue,$currency)); ?></strong></div><?php endif; ?>
            </div>
        </div>
    </section>

    <?php if(!empty($invoice['client_message'])): ?><section class="jv-section-card"><h3>Client Message</h3><p><?php echo ivh($invoice['client_message']); ?></p></section><?php endif; ?>
    <?php if(!empty($invoice['contract_disclaimer'])): ?><section class="jv-section-card"><h3>Contract / Disclaimer</h3><p><?php echo ivh($invoice['contract_disclaimer']); ?></p></section><?php endif; ?>
    <?php if($invoiceImages): ?><section class="jv-section-card"><h3>Images</h3><div class="jv-gallery"><?php foreach($invoiceImages as $img): ?><a href="<?php echo ivh($img['file_path']); ?>" target="_blank" rel="noopener"><img src="<?php echo ivh($img['file_path']); ?>" alt="<?php echo ivh($img['file_name']); ?>"></a><?php endforeach; ?></div></section><?php endif; ?>
    <?php if($invoiceSignatures): ?><section class="jv-section-card"><h3>Signatures</h3><div class="jv-signature-grid"><?php foreach($invoiceSignatures as $sig): ?><div class="jv-signature-saved"><a href="<?php echo ivh($sig['file_path']); ?>" target="_blank" rel="noopener"><img src="<?php echo ivh($sig['file_path']); ?>" alt="Invoice signature"></a><small>Collected <?php echo ivh(ivDateTime($sig['created_at'])); ?></small></div><?php endforeach; ?></div></section><?php endif; ?>
    <?php if($invoiceAttachments): ?><section class="jv-section-card"><h3>Attachments</h3><div class="jv-file-list"><?php foreach($invoiceAttachments as $file): ?><a class="jv-file" href="<?php echo ivh($file['file_path']); ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i><span><?php echo ivh($file['file_name']); ?></span><small><?php echo number_format(((int)$file['file_size'])/1024,0); ?> KB</small></a><?php endforeach; ?></div></section><?php endif; ?>
    <?php if(!empty($invoice['notes'])): ?><section class="jv-section-card jv-staff-only"><h3>Invoice Notes</h3><p><?php echo ivh($invoice['notes']); ?></p></section><?php endif; ?>
</section>

<aside class="jv-side">
    <section class="jv-side-card" id="clientViewCard">
        <div class="jv-side-head"><h3>Client view</h3><?php if(!$clientPreview): ?><button type="button" class="jv-client-edit" id="clientViewEdit" title="Edit client view"><i class="bi bi-pencil"></i></button><?php endif; ?></div>
        <form method="post" id="clientViewForm">
            <input type="hidden" name="action" value="save_client_view"><input type="hidden" name="csrf_token" value="<?php echo ivh($invoiceCsrfToken); ?>"><input type="hidden" name="invoice_id" value="<?php echo (int)$invoiceId; ?>">
            <?php foreach(array('quantities'=>'Quantities','unit_prices'=>'Unit prices','line_item_totals'=>'Line item totals','account_balance'=>'Account balance','late_stamp'=>'Late stamp (if overdue)') as $key=>$label): ?>
                <div class="jv-client-option jv-client-view-display"><span><?php echo ivh($label); ?></span><span class="jv-onoff <?php echo !empty($clientViewOptions[$key])?'on':''; ?>"><?php echo !empty($clientViewOptions[$key])?'ON':'OFF'; ?></span></div>
                <div class="jv-client-option jv-client-view-editrow" style="display:none"><span><?php echo ivh($label); ?></span><label class="jv-switch"><input type="checkbox" name="cv_<?php echo ivh($key); ?>" value="1" data-initial="<?php echo !empty($clientViewOptions[$key])?'1':'0'; ?>" <?php echo !empty($clientViewOptions[$key])?'checked':''; ?>><span class="jv-switch-track"><span class="jv-switch-check">✓</span><span class="jv-switch-knob"></span></span></label></div>
            <?php endforeach; ?>
            <p class="jv-client-help" id="clientViewHelp" style="display:none">Adjust what your client will see on this invoice. These settings apply to this invoice only.</p>
            <div class="jv-client-actions" id="clientViewActions" style="display:none"><button type="button" class="jv-btn" id="clientViewCancel">Cancel</button><button type="submit" class="jv-btn primary">Save</button></div>
        </form>
    </section>
    <section class="jv-side-card jv-staff-only">
        <div class="jv-side-head"><h3>Notes</h3></div>
        <div id="internalNotesList"></div>
        <div class="jv-note-empty" id="notesEmpty"><i class="bi bi-journal-plus"></i><span>Leave an internal note for yourself or a team member</span></div>
    </section>
    <?php if($payments): ?><section class="jv-side-card jv-staff-only"><div class="jv-side-head"><h3>Payment History</h3><span><?php echo count($payments); ?></span></div><?php foreach($payments as $p): ?><div class="jv-note-box"><div><strong><?php echo ivh($p['payment_no']); ?></strong> — <?php echo ivh(ivMoney($p['amount'],$currency)); ?></div><div class="jv-note-author"><?php echo ivh(ivTitle($p['payment_method'])); ?> · <?php echo ivh(ivDateTime(!empty($p['received_at'])?$p['received_at']:$p['created_at'])); ?></div></div><?php endforeach; ?></section><?php endif; ?>
</aside>
</div>
</div>
</div>
</main>
<?php if(!$clientPreview): ?></div><?php require_once __DIR__ . '/includes/footer.php'; ?><?php endif; ?>

<?php if(!$clientPreview && $invoiceId>0): ?>
<div class="jv-history-overlay" id="historyOverlay"></div>
<aside class="jv-history-drawer" id="historyDrawer">
    <div class="jv-history-head"><h2>Invoice History</h2><button type="button" class="jv-history-close" id="historyClose"><i class="bi bi-x-lg"></i></button></div>
    <div class="jv-history-filters"><select id="historyTeam"><option value="all">Team | All</option><?php $actors=array();foreach($history as $h){$actors[(string)$h['actor']]=1;}foreach(array_keys($actors) as $actor): ?><option value="<?php echo ivh(strtolower($actor)); ?>"><?php echo ivh($actor); ?></option><?php endforeach; ?></select><select id="historyType"><option value="all">Type | All</option><option value="invoice">Invoice</option><option value="payment">Payment</option></select><select id="historyDate"><option value="all">Date | All</option><option value="today">Today</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option></select></div>
    <div class="jv-history-list" id="historyList">
    <?php if(!$history): ?><div class="jv-note-empty" style="min-height:160px">No invoice history recorded yet.</div><?php else: foreach($history as $h): $initials='';foreach(preg_split('/\s+/',trim((string)$h['actor'])) as $part){if($part!=='')$initials.=strtoupper(substr($part,0,1));if(strlen($initials)>=2)break;} ?>
        <article class="jv-history-event" data-actor="<?php echo ivh(strtolower((string)$h['actor'])); ?>" data-type="<?php echo ivh($h['type']); ?>" data-date="<?php echo ivh(substr((string)$h['created_at'],0,10)); ?>"><div class="jv-history-event-head"><span class="jv-history-avatar"><?php echo ivh($initials?:'S'); ?></span><div><strong><?php echo ivh($h['actor'].' '.strtolower(ivTitle($h['action']))); ?></strong><small><?php echo ivh(ivDateTime($h['created_at'])); ?><?php echo count($h['changes'])?' · '.count($h['changes']).' change'.(count($h['changes'])===1?'':'s'):''; ?></small></div></div><?php if($h['changes']): ?><div class="jv-history-changes"><?php foreach($h['changes'] as $chg): ?><div class="jv-history-change"><b><?php echo ivh($chg['field']); ?></b> &nbsp; <?php echo ivh($chg['old']); ?> &nbsp;→&nbsp; <?php echo ivh($chg['new']); ?></div><?php endforeach; ?></div><?php endif; ?></article>
    <?php endforeach; endif; ?>
    </div>
</aside>
<?php endif; ?>

<?php if(!$clientPreview && $invoiceId>0): ?>
<div class="jv-modal-backdrop" id="emailModal">
    <section class="jv-modal jv-email-modal">
        <div class="jv-modal-head"><h3>Email invoice #<?php echo ivh(isset($invoice['invoice_no'])?$invoice['invoice_no']:''); ?> to <?php echo ivh(isset($invoice['client_name'])?$invoice['client_name']:'Client'); ?></h3><button type="button" class="jv-close" id="emailClose"><i class="bi bi-x-lg"></i></button></div>
        <form method="post" enctype="multipart/form-data" id="emailForm">
            <input type="hidden" name="action" value="send_invoice_email"><input type="hidden" name="csrf_token" value="<?php echo ivh($invoiceCsrfToken); ?>"><input type="hidden" name="invoice_id" value="<?php echo (int)$invoiceId; ?>"><input type="hidden" name="to_emails" id="emailToHidden" value="<?php echo ivh(!empty($invoice['client_email'])?$invoice['client_email']:''); ?>">
            <div class="jv-modal-body"><div class="jv-email-grid"><div>
                <div class="jv-email-to" id="emailToBox"><span class="jv-email-to-label">To</span><div class="jv-email-chips" id="emailRecipientChips"></div><input type="text" id="emailRecipientInput" placeholder="Add email"></div>
                <div class="jv-email-field"><label>Subject</label><input type="text" name="email_subject" maxlength="255" required value="Invoice from <?php echo ivh(!empty($invoice['branch_name'])?$invoice['branch_name']:(!empty($invoice['tenant_name'])?$invoice['tenant_name']:'FieldPlx')); ?> - <?php echo ivh($subject); ?>"></div>
                <div class="jv-email-field"><label>Message</label><textarea name="email_message" required>Hi <?php echo ivh(isset($invoice['client_name'])?$invoice['client_name']:''); ?>,

Thank you for choosing to work with us.

Just a quick note to let you know your invoice is now available.

Please let us know if you have any questions.</textarea></div>
                <div class="jv-email-invoice-file"><div class="jv-email-pdf-icon"><i class="bi bi-file-earmark-pdf"></i></div><div><strong>Invoice <?php echo ivh(isset($invoice['invoice_no'])?$invoice['invoice_no']:''); ?></strong><small>The exact Invoice PDF from Print / Save PDF is attached automatically.</small></div></div>
            </div><div class="jv-email-attachments"><h4>Attachments</h4><div class="jv-email-drop"><button type="button" class="jv-btn" id="emailAttachmentPick">Select</button><span>Select or drag files here to upload</span><input type="file" name="email_attachments[]" id="emailAttachmentInput" multiple hidden></div><div class="jv-email-file-list" id="emailAttachmentList"></div><div class="jv-email-limit">You've attached <span id="emailAttachmentMb">0.00</span> MB of the 10.00 MB limit.<div class="jv-email-progress"><span id="emailAttachmentProgress"></span></div></div></div></div></div>
            <div class="jv-modal-foot" style="justify-content:space-between"><label class="jv-checkline"><input type="checkbox" name="send_me_copy" value="1"> Send me a copy</label><div style="display:flex;gap:8px"><button type="button" class="jv-btn" id="emailCancel">Cancel</button><button type="submit" class="jv-btn primary">Send Email</button></div></div>
        </form>
    </section>
</div>
<div class="jv-modal-backdrop" id="signatureModal">
    <section class="jv-modal jv-signature-modal">
        <div class="jv-modal-head"><h3>Signature Pad</h3><button type="button" class="jv-close" id="signatureClose"><i class="bi bi-x-lg"></i></button></div>
        <form method="post" id="signatureForm"><input type="hidden" name="action" value="save_signature"><input type="hidden" name="csrf_token" value="<?php echo ivh($invoiceCsrfToken); ?>"><input type="hidden" name="invoice_id" value="<?php echo (int)$invoiceId; ?>"><input type="hidden" name="signature_data" id="signatureData">
            <div class="jv-modal-body"><div class="jv-signature-wrap"><canvas id="signatureCanvas" class="jv-signature-canvas"></canvas><button type="button" class="jv-signature-clear" id="signatureClear">Clear</button><div class="jv-signature-line"></div><div class="jv-signature-label">Write signature</div></div><label class="jv-checkline" style="margin-top:9px"><input type="checkbox" name="send_signature_copy" value="1"> Send your client a copy</label></div>
            <div class="jv-modal-foot"><button type="button" class="jv-btn" id="signatureCancel">Cancel</button><button type="submit" class="jv-btn primary">Submit</button></div>
        </form>
    </section>
</div>
<?php endif; ?>

<?php if(!$clientPreview && $canCollect): ?>
<div class="jv-modal-backdrop" id="paymentModal"><section class="jv-modal"><div class="jv-modal-head"><div><h3>Collect Payment</h3><div style="font-size:12px;color:#6a7f89;margin-top:4px">Invoice <?php echo ivh(isset($invoice['invoice_no'])?$invoice['invoice_no']:''); ?> · Balance <?php echo ivh(ivMoney($balanceDue,$currency)); ?></div></div><button type="button" class="jv-close" id="closePayment"><i class="bi bi-x-lg"></i></button></div><form method="post" id="paymentForm" autocomplete="off"><input type="hidden" name="action" value="collect_payment"><input type="hidden" name="csrf_token" value="<?php echo ivh($invoiceCsrfToken); ?>"><input type="hidden" name="invoice_id" value="<?php echo (int)$invoiceId; ?>"><div class="jv-modal-body"><div class="jv-form-grid"><div class="jv-field"><label>Amount *</label><input type="number" name="amount" id="paymentAmount" min="0.01" max="<?php echo ivh(number_format($balanceDue,2,'.','')); ?>" step="0.01" required></div><div class="jv-field"><label>Payment Method *</label><select name="payment_method" id="paymentMethod" required><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="bank">Bank Transfer</option><option value="cheque">Cheque</option><option value="wallet">Wallet</option><option value="other">Other</option></select></div><div class="jv-field full"><label>Transaction / Reference No.</label><input type="text" name="reference" id="paymentReference" maxlength="190"></div><div class="jv-field full"><label>Received Date & Time *</label><input type="datetime-local" name="received_at" id="paymentReceivedAt" required></div><div class="jv-field full"><label>Notes</label><textarea name="payment_notes" id="paymentNotes" maxlength="3000"></textarea></div></div></div><div class="jv-modal-foot"><button type="button" class="jv-btn" id="cancelPayment">Cancel</button><button type="submit" class="jv-btn primary">Save Payment</button></div></form></section></div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){'use strict';
function byId(id){return document.getElementById(id)}
<?php if($flashSuccess!==''): ?>setTimeout(function(){fieldplxToast('success',<?php echo json_encode($flashSuccess, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,4200)},80);<?php endif; ?>
<?php if($flashWarning!==''): ?>setTimeout(function(){fieldplxToast('warning',<?php echo json_encode($flashWarning, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,6500)},220);<?php endif; ?>
<?php if($flashError!==''): ?>setTimeout(function(){fieldplxToast('error',<?php echo json_encode($flashError, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>,6500)},80);<?php endif; ?>
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
var more=byId('moreButton'),menu=byId('moreMenu');if(more&&menu){more.addEventListener('click',function(e){e.stopPropagation();menu.classList.toggle('show')});document.addEventListener('click',function(e){if(!e.target.closest('#moreMenu')&&!e.target.closest('#moreButton'))menu.classList.remove('show')})}
function toggleModal(node,show){if(node)node.classList.toggle('show',!!show)}
var emailModal=byId('emailModal'),emailBtn=byId('emailInvoiceButton');if(emailBtn)emailBtn.addEventListener('click',function(){if(menu)menu.classList.remove('show');toggleModal(emailModal,true);setTimeout(function(){var x=byId('emailRecipientInput');if(x)x.focus()},80)});['emailClose','emailCancel'].forEach(function(id){var b=byId(id);if(b)b.addEventListener('click',function(){toggleModal(emailModal,false)})});if(emailModal)emailModal.addEventListener('click',function(e){if(e.target===emailModal)toggleModal(emailModal,false)});
var sigModal=byId('signatureModal'),sigBtn=byId('signatureButton');if(sigBtn)sigBtn.addEventListener('click',function(){if(menu)menu.classList.remove('show');toggleModal(sigModal,true);setTimeout(initSignatureCanvas,50)});['signatureClose','signatureCancel'].forEach(function(id){var b=byId(id);if(b)b.addEventListener('click',function(){toggleModal(sigModal,false)})});if(sigModal)sigModal.addEventListener('click',function(e){if(e.target===sigModal)toggleModal(sigModal,false)});
var cvEdit=byId('clientViewEdit'),cvCancel=byId('clientViewCancel'),cvActions=byId('clientViewActions'),cvHelp=byId('clientViewHelp');function setClientViewEdit(on){document.querySelectorAll('.jv-client-view-display').forEach(function(x){x.style.display=on?'none':'flex'});document.querySelectorAll('.jv-client-view-editrow').forEach(function(x){x.style.display=on?'flex':'none'});if(cvActions)cvActions.style.display=on?'flex':'none';if(cvHelp)cvHelp.style.display=on?'block':'none';if(cvEdit)cvEdit.style.display=on?'none':'inline-flex'}if(cvEdit)cvEdit.addEventListener('click',function(){setClientViewEdit(true)});if(cvCancel)cvCancel.addEventListener('click',function(){document.querySelectorAll('#clientViewForm input[type=checkbox][data-initial]').forEach(function(x){x.checked=x.getAttribute('data-initial')==='1'});setClientViewEdit(false)});

var recipients=[];var toHidden=byId('emailToHidden'),recipientInput=byId('emailRecipientInput'),recipientChips=byId('emailRecipientChips');function validEmail(v){return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)}function syncRecipients(){if(toHidden)toHidden.value=recipients.join(',');if(recipientChips)recipientChips.innerHTML=recipients.map(function(v,i){return '<span class="jv-email-chip">'+esc(v)+'<button type="button" data-remove-recipient="'+i+'"><i class="bi bi-x"></i></button></span>'}).join('')}function addRecipient(v,showError){var invalid=[];String(v||'').split(/[;,\s]+/).forEach(function(x){x=x.trim().toLowerCase();if(!x)return;if(validEmail(x)){if(recipients.indexOf(x)<0)recipients.push(x)}else invalid.push(x)});syncRecipients();if(invalid.length&&showError!==false){fieldplxToast('warning','Enter a valid email address: '+invalid.join(', '),5200);return false}return invalid.length===0}if(toHidden&&toHidden.value)addRecipient(toHidden.value,false);if(recipientInput){recipientInput.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===','||e.key===';'){e.preventDefault();if(addRecipient(this.value,true))this.value=''}});recipientInput.addEventListener('blur',function(){if(this.value.trim()&&addRecipient(this.value,true))this.value=''})}if(recipientChips)recipientChips.addEventListener('click',function(e){var b=e.target.closest('[data-remove-recipient]');if(!b)return;recipients.splice(Number(b.getAttribute('data-remove-recipient')),1);syncRecipients()});var emailForm=byId('emailForm');if(emailForm)emailForm.addEventListener('submit',function(e){if(recipientInput&&recipientInput.value.trim()){if(!addRecipient(recipientInput.value,true)){e.preventDefault();recipientInput.focus();return}recipientInput.value=''}if(!recipients.length){e.preventDefault();fieldplxToast('warning','Add at least one valid recipient email address.');if(recipientInput)recipientInput.focus();return}var submitBtn=emailForm.querySelector('button[type=submit]');if(submitBtn){submitBtn.disabled=true;submitBtn.textContent='Sending...'}});
var emailFiles=[],fileInput=byId('emailAttachmentInput'),fileList=byId('emailAttachmentList'),fileMb=byId('emailAttachmentMb'),fileProgress=byId('emailAttachmentProgress'),filePick=byId('emailAttachmentPick');function rebuildEmailFiles(){if(fileInput&&typeof DataTransfer!=='undefined'){var dt=new DataTransfer();emailFiles.forEach(function(f){dt.items.add(f)});fileInput.files=dt.files}var total=emailFiles.reduce(function(n,f){return n+Number(f.size||0)},0);if(fileMb)fileMb.textContent=(total/1048576).toFixed(2);if(fileProgress)fileProgress.style.width=Math.min(100,total/(10*1048576)*100)+'%';if(fileList)fileList.innerHTML=emailFiles.map(function(f,i){return '<div class="jv-email-file"><i class="bi bi-paperclip"></i><span>'+esc(f.name)+'</span><small>'+Math.max(1,Math.round(f.size/1024))+' KB</small><button type="button" data-remove-email-file="'+i+'"><i class="bi bi-x-lg"></i></button></div>'}).join('')}function addEmailFiles(list){Array.prototype.slice.call(list||[]).forEach(function(f){var current=emailFiles.reduce(function(n,x){return n+Number(x.size||0)},0);if(current+Number(f.size||0)>10*1048576){fieldplxToast('warning','Email attachments cannot exceed 10 MB in total.');return}emailFiles.push(f)});rebuildEmailFiles()}if(filePick)filePick.addEventListener('click',function(){if(fileInput)fileInput.click()});if(fileInput)fileInput.addEventListener('change',function(){addEmailFiles(this.files)});if(fileList)fileList.addEventListener('click',function(e){var b=e.target.closest('[data-remove-email-file]');if(!b)return;emailFiles.splice(Number(b.getAttribute('data-remove-email-file')),1);rebuildEmailFiles()});var drop=emailModal?emailModal.querySelector('.jv-email-drop'):null;if(drop){['dragenter','dragover'].forEach(function(evt){drop.addEventListener(evt,function(e){e.preventDefault()})});drop.addEventListener('drop',function(e){e.preventDefault();addEmailFiles(e.dataTransfer.files)})}

var sigCanvas=byId('signatureCanvas'),sigDirty=false,sigCtx=null;function initSignatureCanvas(){if(!sigCanvas)return;var rect=sigCanvas.getBoundingClientRect(),dpr=Math.max(1,window.devicePixelRatio||1),w=Math.max(300,Math.round(rect.width)),h=Math.max(150,Math.round(rect.height));if(sigCanvas.width!==Math.round(w*dpr)||sigCanvas.height!==Math.round(h*dpr)){sigCanvas.width=Math.round(w*dpr);sigCanvas.height=Math.round(h*dpr);sigCtx=sigCanvas.getContext('2d');sigCtx.scale(dpr,dpr);sigCtx.lineWidth=2.5;sigCtx.lineCap='round';sigCtx.lineJoin='round';sigCtx.strokeStyle='#2780c2';sigDirty=false}else if(!sigCtx)sigCtx=sigCanvas.getContext('2d')}function sigPoint(e){var r=sigCanvas.getBoundingClientRect();return{x:e.clientX-r.left,y:e.clientY-r.top}}var drawing=false,last=null;if(sigCanvas){sigCanvas.addEventListener('pointerdown',function(e){initSignatureCanvas();drawing=true;last=sigPoint(e);sigCanvas.setPointerCapture(e.pointerId)});sigCanvas.addEventListener('pointermove',function(e){if(!drawing||!sigCtx)return;var p=sigPoint(e);sigCtx.beginPath();sigCtx.moveTo(last.x,last.y);sigCtx.lineTo(p.x,p.y);sigCtx.stroke();last=p;sigDirty=true});function endSig(e){drawing=false;last=null}sigCanvas.addEventListener('pointerup',endSig);sigCanvas.addEventListener('pointercancel',endSig)}var sigClear=byId('signatureClear');if(sigClear)sigClear.addEventListener('click',function(){initSignatureCanvas();if(sigCtx){sigCtx.clearRect(0,0,sigCanvas.width,sigCanvas.height);sigDirty=false}});var sigForm=byId('signatureForm');if(sigForm)sigForm.addEventListener('submit',function(e){if(!sigDirty){e.preventDefault();fieldplxToast('warning','Please write a signature before submitting.');return}byId('signatureData').value=sigCanvas.toDataURL('image/png')});
var hb=byId('historyButton'),drawer=byId('historyDrawer'),overlay=byId('historyOverlay'),hc=byId('historyClose');function hist(show){if(drawer)drawer.classList.toggle('show',show);if(overlay)overlay.classList.toggle('show',show)}if(hb)hb.addEventListener('click',function(){hist(true)});if(hc)hc.addEventListener('click',function(){hist(false)});if(overlay)overlay.addEventListener('click',function(){hist(false)});
function filterHistory(){var team=(byId('historyTeam')||{}).value||'all',type=(byId('historyType')||{}).value||'all',date=(byId('historyDate')||{}).value||'all',now=new Date();document.querySelectorAll('.jv-history-event').forEach(function(row){var ok=(team==='all'||row.dataset.actor===team)&&(type==='all'||row.dataset.type===type);if(ok&&date!=='all'){var d=new Date(row.dataset.date+'T00:00:00');var diff=Math.floor((now-d)/86400000);ok=date==='today'?diff===0:diff<=Number(date)}row.style.display=ok?'block':'none'})}['historyTeam','historyType','historyDate'].forEach(function(id){var e=byId(id);if(e)e.addEventListener('change',filterHistory)});
var modal=byId('paymentModal'),collect=byId('collectButton'),amount=byId('paymentAmount'),received=byId('paymentReceivedAt');function localDT(){var d=new Date(),off=d.getTimezoneOffset();d=new Date(d.getTime()-off*60000);return d.toISOString().slice(0,16)}function openPay(){if(!modal)return;if(amount)amount.value=<?php echo json_encode(number_format($balanceDue,2,'.','')); ?>;if(received)received.value=localDT();modal.classList.add('show')}function closePay(){if(modal)modal.classList.remove('show')}if(collect)collect.addEventListener('click',openPay);var cp=byId('closePayment'),cancel=byId('cancelPayment');if(cp)cp.addEventListener('click',closePay);if(cancel)cancel.addEventListener('click',closePay);if(modal)modal.addEventListener('click',function(e){if(e.target===modal)closePay()});
<?php if(!$clientPreview && $invoiceId>0): ?>
var notesList=byId('internalNotesList'),notesEmpty=byId('notesEmpty');if(notesList){var fd=new FormData();fd.append('action','load');fd.append('invoice_id',<?php echo (int)$invoiceId; ?>);fd.append('csrf_token',<?php echo json_encode($invoiceCsrfToken); ?>);fetch('api/invoice-internal-notes.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()}).then(function(d){if(!d.success||!Array.isArray(d.notes)||!d.notes.length)return;notesEmpty.style.display='none';notesList.innerHTML=d.notes.map(function(n){var mentions=Array.isArray(n.mentions)?n.mentions:[],files=Array.isArray(n.attachments)?n.attachments:[];return '<div class="jv-note-box"><div class="jv-note-author">'+esc(n.created_by_name||'Team member')+' · '+esc(n.created_at||'')+'</div><div class="jv-note-text">'+esc(n.note_text||'')+'</div>'+(mentions.length?'<div class="jv-note-mentions">'+mentions.map(function(m){return '<span class="jv-note-mention">@'+esc(m.name||'Team member')+'</span>'}).join('')+'</div>':'')+(files.length?'<div class="jv-note-files">'+files.map(function(f){return '<a target="_blank" rel="noopener" href="'+esc(f.download_url)+'"><i class="bi bi-paperclip"></i> '+esc(f.file_name||'Attachment')+'</a>'}).join('')+'</div>':'')+'</div>'}).join('')}).catch(function(){/* optional notes module */})}
<?php endif; ?>
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closePay();hist(false);toggleModal(emailModal,false);toggleModal(sigModal,false);if(menu)menu.classList.remove('show')}});
<?php if($openCollect && $canCollect && !$clientPreview): ?>setTimeout(openPay,80);<?php endif; ?>
})();
</script>
</body>
</html>
