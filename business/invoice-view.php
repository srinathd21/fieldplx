<?php
/**
 * FieldPlx Invoice View - Direct Backend Version 2.1.0
 * PHP 7.2 compatible.
 *
 * All invoice loading and payment collection are handled directly in this page.
 * No api/invoices.php request is used.
 */
require_once __DIR__ . '/includes/auth.php';
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

$invoiceId = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;
$jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
$openCollect = isset($_GET['collect']) && $_GET['collect'] === '1';
$flashSuccess = isset($_SESSION['invoice_view_success']) ? (string) $_SESSION['invoice_view_success'] : '';
$flashError = isset($_SESSION['invoice_view_error']) ? (string) $_SESSION['invoice_view_error'] : '';
unset($_SESSION['invoice_view_success'], $_SESSION['invoice_view_error']);

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

        $_SESSION['invoice_view_success'] = 'Payment ' . $paymentNo . ' collected successfully.';
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
   Load invoice directly from database
   ---------------------------------------------------------- */
if ($invoiceId <= 0 && $jobId > 0) {
    $find = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id=:t AND job_id=:j AND status NOT IN('cancelled','archived') ORDER BY id DESC LIMIT 1");
    $find->execute(array(':t' => $tenantId, ':j' => $jobId));
    $invoiceId = (int) $find->fetchColumn();
}

$invoice = null;
$items = array();
$payments = array();
$pageError = '';
$currency = array('id' => 0, 'symbol' => '', 'symbol_position' => 'before', 'decimal_places' => 2);

if ($invoiceId > 0) {
    try {
        $sql = "SELECT i.*,
                       c.display_name AS client_name,c.company_name AS client_company,c.email AS client_email,c.phone AS client_phone,
                       cl.name AS location_name,cl.address_line1,cl.address_line2,cl.city,cl.state,cl.postal_code,
                       j.job_no,q.quote_no,
                       b.name AS branch_name,b.email AS branch_email,b.phone AS branch_phone,b.address_line1 AS branch_address_line1,b.address_line2 AS branch_address_line2,b.city AS branch_city,b.state AS branch_state,b.postal_code AS branch_postal_code,b.logo_path AS branch_logo_path,b.invoice_logo_path AS branch_invoice_logo_path,b.currency_id AS branch_currency_id,
                       t.legal_name AS tenant_legal_name,t.display_name AS tenant_name,t.email AS tenant_email,t.phone AS tenant_phone,t.tax_number AS tenant_tax_number,t.address_line1 AS tenant_address_line1,t.address_line2 AS tenant_address_line2,t.city AS tenant_city,t.state AS tenant_state,t.postal_code AS tenant_postal_code,t.logo_path AS tenant_logo_path,t.invoice_logo_path AS tenant_invoice_logo_path,t.currency_id AS tenant_currency_id
                FROM invoices i
                INNER JOIN clients c ON c.id=i.client_id AND c.tenant_id=i.tenant_id
                LEFT JOIN client_locations cl ON cl.id=i.location_id AND cl.tenant_id=i.tenant_id
                LEFT JOIN jobs j ON j.id=i.job_id AND j.tenant_id=i.tenant_id
                LEFT JOIN quotes q ON q.id=i.quote_id AND q.tenant_id=i.tenant_id
                LEFT JOIN branches b ON b.id=i.branch_id AND b.tenant_id=i.tenant_id
                INNER JOIN tenants t ON t.id=i.tenant_id
                WHERE i.id=:id AND i.tenant_id=:t
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':id' => $invoiceId, ':t' => $tenantId));
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) throw new RuntimeException('Invoice not found.');

        $branchCurrencyId = !empty($invoice['branch_currency_id']) ? (int) $invoice['branch_currency_id'] : 0;
        $tenantCurrencyId = !empty($invoice['tenant_currency_id']) ? (int) $invoice['tenant_currency_id'] : 0;
        $currencyId = $branchCurrencyId > 0 ? $branchCurrencyId : $tenantCurrencyId;
        if ($currencyId > 0) {
            $cs = $pdo->prepare("SELECT id,currency_code,currency_name,symbol,symbol_position,decimal_places,decimal_separator,thousand_separator FROM currencies WHERE id=:id LIMIT 1");
            $cs->execute(array(':id' => $currencyId));
            $cr = $cs->fetch(PDO::FETCH_ASSOC);
            if ($cr) $currency = $cr;
        }

        if (ivTable($pdo, 'invoice_line_items')) {
            $li = $pdo->prepare("SELECT * FROM invoice_line_items WHERE invoice_id=:i ORDER BY sort_order,id");
            $li->execute(array(':i' => $invoiceId));
            $items = $li->fetchAll(PDO::FETCH_ASSOC);
        }

        if (ivTable($pdo, 'payments')) {
            $ps = $pdo->prepare("SELECT id,payment_no,payment_method,payment_channel,status,amount,provider,provider_payment_id,received_at,notes,created_at FROM payments WHERE tenant_id=:t AND invoice_id=:i ORDER BY COALESCE(received_at,created_at) DESC,id DESC");
            $ps->execute(array(':t' => $tenantId, ':i' => $invoiceId));
            $payments = $ps->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('FieldPlx invoice direct load: ' . $e->getMessage());
        $pageError = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to load invoice.';
    }
} else {
    $pageError = 'Invoice or completed job is required.';
}

$invoice = is_array($invoice) ? $invoice : array();
$status = isset($invoice['status']) ? (string) $invoice['status'] : 'draft';
$invoiceLogo = '';
if (!empty($invoice['branch_invoice_logo_path'])) $invoiceLogo = (string) $invoice['branch_invoice_logo_path'];
elseif (!empty($invoice['tenant_invoice_logo_path'])) $invoiceLogo = (string) $invoice['tenant_invoice_logo_path'];
elseif (!empty($invoice['branch_logo_path'])) $invoiceLogo = (string) $invoice['branch_logo_path'];
elseif (!empty($invoice['tenant_logo_path'])) $invoiceLogo = (string) $invoice['tenant_logo_path'];
$companyName = !empty($invoice['branch_name']) ? (string) $invoice['branch_name'] : (!empty($invoice['tenant_name']) ? (string) $invoice['tenant_name'] : 'FieldPlx');
$companyAddress = !empty($invoice['branch_name'])
    ? ivAddress(array($invoice['branch_address_line1'], $invoice['branch_address_line2'], $invoice['branch_city'], $invoice['branch_state'], $invoice['branch_postal_code']))
    : ivAddress(array(isset($invoice['tenant_address_line1']) ? $invoice['tenant_address_line1'] : '', isset($invoice['tenant_address_line2']) ? $invoice['tenant_address_line2'] : '', isset($invoice['tenant_city']) ? $invoice['tenant_city'] : '', isset($invoice['tenant_state']) ? $invoice['tenant_state'] : '', isset($invoice['tenant_postal_code']) ? $invoice['tenant_postal_code'] : ''));
$companyContactParts = array();
$companyEmail = !empty($invoice['branch_email']) ? $invoice['branch_email'] : (isset($invoice['tenant_email']) ? $invoice['tenant_email'] : '');
$companyPhone = !empty($invoice['branch_phone']) ? $invoice['branch_phone'] : (isset($invoice['tenant_phone']) ? $invoice['tenant_phone'] : '');
if ($companyEmail) $companyContactParts[] = $companyEmail;
if ($companyPhone) $companyContactParts[] = $companyPhone;
$companyContact = $companyContactParts ? implode(' • ', $companyContactParts) : '-';
$clientContactParts = array();
if (!empty($invoice['client_email'])) $clientContactParts[] = $invoice['client_email'];
if (!empty($invoice['client_phone'])) $clientContactParts[] = $invoice['client_phone'];
$clientContact = $clientContactParts ? implode(' • ', $clientContactParts) : '-';
$clientAddress = ivAddress(array(isset($invoice['location_name']) ? $invoice['location_name'] : '', isset($invoice['address_line1']) ? $invoice['address_line1'] : '', isset($invoice['address_line2']) ? $invoice['address_line2'] : '', isset($invoice['city']) ? $invoice['city'] : '', isset($invoice['state']) ? $invoice['state'] : '', isset($invoice['postal_code']) ? $invoice['postal_code'] : ''));
$balanceDue = isset($invoice['balance_due']) ? (float) $invoice['balance_due'] : 0.0;
$canCollect = $invoiceId > 0 && $balanceDue > 0.005 && !in_array($status, array('cancelled','archived','written_off'), true);
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
        @media print{.fieldplx-topbar,.fieldplx-sidebar,.fieldplx-footer,.iv-actions,.iv-summary,.iv-modal-backdrop,.iv-toast{display:none!important}.fieldplx-main-content{margin-left:0!important}.iv-page{max-width:none;padding:0}.iv-grid{display:block}.iv-card{box-shadow:none;break-inside:avoid}}
    
        .iv-alert{margin-bottom:14px;padding:11px 13px;border:1px solid #cfe7b3;border-radius:9px;background:#f4faec;color:#4d7c18;font-size:10px;font-weight:700}.iv-alert.error{border-color:#f0c5c9;background:#fff3f4;color:#b9444d}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="iv-page">
                <?php if ($flashSuccess !== ''): ?><div class="iv-alert"><?php echo ivh($flashSuccess); ?></div><?php endif; ?>
                <?php if ($flashError !== ''): ?><div class="iv-alert error"><?php echo ivh($flashError); ?></div><?php endif; ?>
                <?php if ($pageError !== ''): ?><div class="iv-alert error"><?php echo ivh($pageError); ?></div><?php endif; ?>

                <section class="iv-head">
                    <div>
                        <div class="iv-title-row">
                            <h1 class="iv-title">Invoice <?php echo ivh(isset($invoice['invoice_no']) ? $invoice['invoice_no'] : ''); ?></h1>
                            <span class="iv-badge <?php echo ivh($status); ?>"><?php echo ivh(ivTitle($status)); ?></span>
                        </div>
                        <p class="iv-sub"><?php echo !empty($invoice['job_no']) ? 'Generated from Job Card ' . ivh($invoice['job_no']) . '. ' : ''; ?>Collect and track customer payments against this invoice.</p>
                    </div>
                    <div class="iv-actions">
                        <a class="iv-btn" href="invoices"><i class="bi bi-arrow-left"></i> Invoices</a>
                        <?php if ($invoiceId > 0): ?>
                        <a class="iv-btn" target="_blank" href="invoice-print?invoice_id=<?php echo (int) $invoiceId; ?>"><i class="bi bi-printer"></i> Print</a>
                        <?php endif; ?>
                        <button type="button" class="iv-btn primary" id="collectButton" <?php echo $canCollect ? '' : 'disabled'; ?>><i class="bi bi-cash-stack"></i> Collect Payment</button>
                    </div>
                </section>

                <section class="row g-3 iv-summary">
                    <div class="col-xl-3 col-6"><article class="iv-stat"><div class="iv-stat-row"><span class="iv-stat-icon"><i class="bi bi-receipt"></i></span><div><span class="iv-stat-label">Invoice Total</span><strong class="iv-stat-value"><?php echo ivh(ivMoney(isset($invoice['total']) ? $invoice['total'] : 0, $currency)); ?></strong></div></div></article></div>
                    <div class="col-xl-3 col-6"><article class="iv-stat"><div class="iv-stat-row"><span class="iv-stat-icon green"><i class="bi bi-check2-circle"></i></span><div><span class="iv-stat-label">Collected</span><strong class="iv-stat-value"><?php echo ivh(ivMoney(isset($invoice['amount_paid']) ? $invoice['amount_paid'] : 0, $currency)); ?></strong></div></div></article></div>
                    <div class="col-xl-3 col-6"><article class="iv-stat"><div class="iv-stat-row"><span class="iv-stat-icon orange"><i class="bi bi-hourglass-split"></i></span><div><span class="iv-stat-label">Balance Due</span><strong class="iv-stat-value"><?php echo ivh(ivMoney($balanceDue, $currency)); ?></strong></div></div></article></div>
                    <div class="col-xl-3 col-6"><article class="iv-stat"><div class="iv-stat-row"><span class="iv-stat-icon"><i class="bi bi-briefcase"></i></span><div><span class="iv-stat-label">Job Card</span><strong class="iv-stat-value" style="font-size:18px"><?php echo ivh(!empty($invoice['job_no']) ? $invoice['job_no'] : '-'); ?></strong></div></div></article></div>
                </section>

                <div class="iv-grid">
                    <div>
                        <section class="iv-card">
                            <div class="iv-card-body">
                                <div class="iv-company">
                                    <div class="iv-company-left">
                                        <div class="iv-logo"><?php if ($invoiceLogo !== ''): ?><img src="<?php echo ivh($invoiceLogo); ?>" alt="Logo"><?php else: ?><?php echo ivh(strtoupper(substr($companyName, 0, 1))); ?><?php endif; ?></div>
                                        <div>
                                            <h3><?php echo ivh($companyName); ?></h3>
                                            <p><?php echo ivh($companyAddress); ?></p>
                                            <p><?php echo ivh($companyContact); ?></p>
                                            <?php if (!empty($invoice['tenant_tax_number'])): ?><p>Tax No: <?php echo ivh($invoice['tenant_tax_number']); ?></p><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="iv-number"><small>Invoice Number</small><strong><?php echo ivh(isset($invoice['invoice_no']) ? $invoice['invoice_no'] : '-'); ?></strong></div>
                                </div>

                                <div class="iv-info-grid">
                                    <div class="iv-info"><span>Issue Date</span><strong><?php echo ivh(ivDate(isset($invoice['issue_date']) ? $invoice['issue_date'] : null)); ?></strong></div>
                                    <div class="iv-info"><span>Due Date</span><strong><?php echo ivh(ivDate(isset($invoice['due_date']) ? $invoice['due_date'] : null)); ?></strong></div>
                                    <div class="iv-info"><span>Payment Terms</span><strong><?php echo ivh(!empty($invoice['payment_terms']) ? $invoice['payment_terms'] : '-'); ?></strong></div>
                                    <div class="iv-info"><span>Job Card</span><strong><?php echo ivh(!empty($invoice['job_no']) ? $invoice['job_no'] : '-'); ?></strong></div>
                                    <div class="iv-info"><span>Quotation</span><strong><?php echo ivh(!empty($invoice['quote_no']) ? $invoice['quote_no'] : '-'); ?></strong></div>
                                    <div class="iv-info"><span>Branch</span><strong><?php echo ivh(!empty($invoice['branch_name']) ? $invoice['branch_name'] : 'Head Office'); ?></strong></div>
                                </div>
                            </div>
                        </section>

                        <section class="iv-card">
                            <div class="iv-card-head"><div><h2>Invoice Items</h2></div></div>
                            <div class="iv-table-wrap">
                                <table class="iv-table">
                                    <thead><tr><th>S.No</th><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Discount</th><th class="num">Tax %</th><th class="num">Tax</th><th class="num">Total</th></tr></thead>
                                    <tbody>
                                    <?php if (!$items): ?>
                                        <tr><td colspan="8" class="iv-empty">No invoice items found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($items as $index => $item): ?>
                                            <tr>
                                                <td><?php echo (int) $index + 1; ?></td>
                                                <td><div class="iv-item"><strong><?php echo ivh(isset($item['item_name']) ? $item['item_name'] : '-'); ?></strong><?php if (!empty($item['description'])): ?><small><?php echo ivh($item['description']); ?></small><?php endif; ?></div></td>
                                                <td class="num"><?php echo ivh(rtrim(rtrim(number_format((float) $item['quantity'], 3, '.', ''), '0'), '.')); ?></td>
                                                <td class="num"><?php echo ivh(ivMoney($item['unit_price'], $currency)); ?></td>
                                                <td class="num"><?php echo ivh(ivMoney($item['discount_amount'], $currency)); ?></td>
                                                <td class="num"><?php echo ivh(rtrim(rtrim(number_format((float) $item['tax_percent'], 2, '.', ''), '0'), '.')); ?>%</td>
                                                <td class="num"><?php echo ivh(ivMoney($item['tax_amount'], $currency)); ?></td>
                                                <td class="num"><strong><?php echo ivh(ivMoney($item['line_total'], $currency)); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="iv-totals">
                                <div class="iv-total-row"><span>Subtotal</span><strong><?php echo ivh(ivMoney(isset($invoice['subtotal']) ? $invoice['subtotal'] : 0, $currency)); ?></strong></div>
                                <div class="iv-total-row"><span>Discount</span><strong><?php echo ivh(ivMoney(isset($invoice['discount_total']) ? $invoice['discount_total'] : 0, $currency)); ?></strong></div>
                                <div class="iv-total-row"><span>Tax</span><strong><?php echo ivh(ivMoney(isset($invoice['tax_total']) ? $invoice['tax_total'] : 0, $currency)); ?></strong></div>
                                <div class="iv-total-row grand"><span>Invoice Total</span><strong><?php echo ivh(ivMoney(isset($invoice['total']) ? $invoice['total'] : 0, $currency)); ?></strong></div>
                                <div class="iv-total-row"><span>Amount Paid</span><strong><?php echo ivh(ivMoney(isset($invoice['amount_paid']) ? $invoice['amount_paid'] : 0, $currency)); ?></strong></div>
                                <div class="iv-total-row balance"><span>Balance Due</span><strong><?php echo ivh(ivMoney($balanceDue, $currency)); ?></strong></div>
                            </div>
                        </section>
                    </div>

                    <aside>
                        <section class="iv-card">
                            <div class="iv-card-head"><div><h2>Bill To</h2></div></div>
                            <div class="iv-card-body"><div class="iv-customer"><strong><?php echo ivh(!empty($invoice['client_name']) ? $invoice['client_name'] : '-'); ?></strong><span><?php echo ivh(!empty($invoice['client_company']) ? $invoice['client_company'] : ''); ?></span><span><?php echo ivh($clientContact); ?></span><span><?php echo ivh($clientAddress); ?></span></div></div>
                        </section>

                        <section class="iv-card">
                            <div class="iv-card-head"><div><h2>Payment History</h2></div><span class="iv-badge"><?php echo count($payments); ?></span></div>
                            <div class="iv-card-body"><div class="iv-pay-list">
                                <?php if (!$payments): ?>
                                    <div class="iv-empty">No payments collected yet.</div>
                                <?php else: ?>
                                    <?php foreach ($payments as $payment): ?>
                                        <div class="iv-payment">
                                            <div class="iv-payment-top"><strong><?php echo ivh($payment['payment_no']); ?></strong><strong class="iv-payment-amount"><?php echo ivh(ivMoney($payment['amount'], $currency)); ?></strong></div>
                                            <small><?php echo ivh(ivTitle($payment['payment_method'])); ?> • <?php echo ivh(ivTitle($payment['status'])); ?> • <?php echo ivh(ivDateTime(!empty($payment['received_at']) ? $payment['received_at'] : $payment['created_at'])); ?></small>
                                            <?php if (!empty($payment['provider_payment_id'])): ?><small>Reference: <?php echo ivh($payment['provider_payment_id']); ?></small><?php endif; ?>
                                            <?php if (!empty($payment['notes'])): ?><small><?php echo ivh($payment['notes']); ?></small><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div></div>
                        </section>
                    </aside>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="iv-modal-backdrop" id="paymentModal">
    <section class="iv-modal">
        <div class="iv-modal-head"><div><h3>Collect Payment</h3><div class="iv-help">Invoice <?php echo ivh(isset($invoice['invoice_no']) ? $invoice['invoice_no'] : ''); ?> • Balance <?php echo ivh(ivMoney($balanceDue, $currency)); ?></div></div><button type="button" class="iv-close" id="closePayment"><i class="bi bi-x-lg"></i></button></div>
        <form method="post" id="paymentForm" autocomplete="off">
            <input type="hidden" name="action" value="collect_payment">
            <input type="hidden" name="csrf_token" value="<?php echo ivh($invoiceCsrfToken); ?>">
            <input type="hidden" name="invoice_id" value="<?php echo (int) $invoiceId; ?>">
            <div class="iv-modal-body">
                <div class="iv-form-grid">
                    <div class="iv-field"><label>Amount *</label><input type="number" name="amount" id="paymentAmount" min="0.01" max="<?php echo ivh(number_format($balanceDue, 2, '.', '')); ?>" step="0.01" required></div>
                    <div class="iv-field"><label>Payment Method *</label><select name="payment_method" id="paymentMethod" required><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="bank">Bank Transfer</option><option value="cheque">Cheque</option><option value="wallet">Wallet</option><option value="other">Other</option></select></div>
                    <div class="iv-field full"><label>Transaction / Reference No.</label><input type="text" name="reference" id="paymentReference" maxlength="190" placeholder="UPI ref, bank ref, cheque no., etc."></div>
                    <div class="iv-field full"><label>Received Date & Time *</label><input type="datetime-local" name="received_at" id="paymentReceivedAt" required></div>
                    <div class="iv-field full"><label>Notes</label><textarea name="payment_notes" id="paymentNotes" maxlength="3000" placeholder="Optional payment remarks"></textarea></div>
                </div>
            </div>
            <div class="iv-modal-foot"><button type="button" class="iv-btn" id="cancelPayment">Cancel</button><button type="submit" class="iv-btn primary" id="savePayment"><i class="bi bi-check2-circle"></i> Save Payment</button></div>
        </form>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    'use strict';
    var canCollect=<?php echo $canCollect ? 'true' : 'false'; ?>;
    var openCollect=<?php echo $openCollect ? 'true' : 'false'; ?>;
    var modal=document.getElementById('paymentModal');
    var collect=document.getElementById('collectButton');
    var amount=document.getElementById('paymentAmount');
    var received=document.getElementById('paymentReceivedAt');
    function localDateTime(){var d=new Date(),off=d.getTimezoneOffset();d=new Date(d.getTime()-off*60000);return d.toISOString().slice(0,16)}
    function openPayment(){if(!canCollect)return;if(amount)amount.value=<?php echo json_encode(number_format($balanceDue, 2, '.', '')); ?>;if(received)received.value=localDateTime();if(modal)modal.classList.add('show')}
    function closePayment(){if(modal)modal.classList.remove('show')}
    if(collect)collect.addEventListener('click',openPayment);
    var close=document.getElementById('closePayment'),cancel=document.getElementById('cancelPayment');
    if(close)close.addEventListener('click',closePayment);if(cancel)cancel.addEventListener('click',closePayment);
    if(modal)modal.addEventListener('click',function(e){if(e.target===modal)closePayment()});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closePayment()});
    if(openCollect&&canCollect)setTimeout(openPayment,80);
})();
</script>
</body>
</html>
