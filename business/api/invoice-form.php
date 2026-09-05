<?php
/* FieldPlx Invoice Form API - Version 2.1.0 - 2026-09-06
 * Supports Job-based invoices, Direct invoices, recurring job billing slots,
 * Service/Product/Manual items, custom label/value fields, invoice Images/Attachments,
 * and automatic customer email after successful invoice creation.
 * PHP 7.2 compatible.
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

function aifRes($code, $ok, $message, $extra)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$code);
    echo json_encode(array_merge(array(
        'success' => (bool)$ok,
        'message' => (string)$message,
        'api_version' => '2.1.0'
    ), is_array($extra) ? $extra : array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function aifP($key, $default)
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function aifTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function aifColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $stmt->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function aifIndex(PDO $pdo, $table, $index)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND INDEX_NAME=:i");
    $stmt->execute(array(':t' => $table, ':i' => $index));
    return (int)$stmt->fetchColumn() > 0;
}

function aifCurrency(PDO $pdo, $tenant)
{
    $stmt = $pdo->prepare("SELECT c.id,c.currency_code,c.currency_name,c.symbol,c.symbol_position,c.decimal_places,c.decimal_separator,c.thousand_separator FROM tenants t INNER JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1");
    $stmt->execute(array(':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    return array('id' => 1, 'currency_code' => 'INR', 'currency_name' => 'Indian Rupee', 'symbol' => '₹', 'symbol_position' => 'before', 'decimal_places' => 2, 'decimal_separator' => '.', 'thousand_separator' => ',');
}

function aifClient(PDO $pdo, $tenant, $id)
{
    if ($id <= 0) return null;
    $stmt = $pdo->prepare("SELECT c.id,c.tenant_id,c.branch_id,c.display_name,c.company_name,c.email,c.phone,c.tax_number,c.allow_email,c.status,b.name branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.id=:id AND c.tenant_id=:t AND c.deleted_at IS NULL AND c.client_type<>'archived' AND c.status<>'archived' LIMIT 1");
    $stmt->execute(array(':id' => $id, ':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function aifLocation(PDO $pdo, $tenant, $clientId, $id)
{
    if ($id <= 0) return null;
    $stmt = $pdo->prepare("SELECT id,client_id,name,address_line1,address_line2,city,state,postal_code,is_primary FROM client_locations WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL AND status='active' LIMIT 1");
    $stmt->execute(array(':id' => $id, ':t' => $tenant, ':c' => $clientId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function aifBranch(PDO $pdo, $tenant, $id)
{
    if ($id <= 0) return null;
    $stmt = $pdo->prepare("SELECT id,name,branch_code,currency_id FROM branches WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
    $stmt->execute(array(':id' => $id, ':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function aifJob(PDO $pdo, $tenant, $id)
{
    if ($id <= 0) return null;
    $billingJoin = aifTable($pdo, 'job_billing_settings') ? " LEFT JOIN job_billing_settings jbs ON jbs.job_id=j.id AND jbs.tenant_id=j.tenant_id " : "";
    $billingSelect = aifTable($pdo, 'job_billing_settings') ? ",jbs.billing_type,jbs.automatic_payments_enabled,jbs.total_invoices,jbs.first_invoice_date,jbs.last_invoice_date,jbs.fixed_invoice_amount" : ",'visit_based' billing_type,0 automatic_payments_enabled,1 total_invoices,NULL first_invoice_date,NULL last_invoice_date,NULL fixed_invoice_amount";
    $sql = "SELECT j.*,c.display_name client_name,c.company_name client_company,c.email client_email,c.phone client_phone,cl.name location_name,cl.address_line1,cl.city location_city,cl.state location_state,b.name branch_name,q.quote_no,ps.name service_name,ps.description service_description,ps.unit_cost service_unit_cost,ps.unit_price service_unit_price,ps.tax_percent service_tax_percent" . $billingSelect . " FROM jobs j INNER JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id LEFT JOIN client_locations cl ON cl.id=j.location_id AND cl.tenant_id=j.tenant_id LEFT JOIN branches b ON b.id=j.branch_id AND b.tenant_id=j.tenant_id LEFT JOIN quotes q ON q.id=j.quote_id AND q.tenant_id=j.tenant_id LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id " . $billingJoin . " WHERE j.id=:id AND j.tenant_id=:t AND j.deleted_at IS NULL LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':id' => $id, ':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function aifPaymentHints(PDO $pdo, $tenant, $clientId)
{
    $out = array('card' => array(), 'online' => array());
    if ($clientId <= 0 || !aifTable($pdo, 'payments')) return $out;
    $stmt = $pdo->prepare("SELECT id,payment_method,payment_channel,provider,provider_payment_id,notes,received_at,created_at FROM payments WHERE tenant_id=:t AND client_id=:c AND status='succeeded' AND (payment_method='card' OR payment_channel='online') ORDER BY COALESCE(received_at,created_at) DESC,id DESC LIMIT 20");
    $stmt->execute(array(':t' => $tenant, ':c' => $clientId));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $row['payment_channel'] === 'online' ? 'online' : 'card';
        if (count($out[$type]) >= 5) continue;
        $provider = trim((string)$row['provider']);
        $reference = trim((string)$row['provider_payment_id']);
        if ($provider === '' && $reference === '') continue;
        $out[$type][] = array(
            'id' => (int)$row['id'],
            'provider' => $provider,
            'reference' => $reference,
            'received_at' => $row['received_at'] ? $row['received_at'] : $row['created_at']
        );
    }
    return $out;
}

function aifFallbackNo(PDO $pdo, $tenant, $table, $column, $prefix)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0)+1 FROM `" . $table . "` WHERE tenant_id=:t");
    $stmt->execute(array(':t' => $tenant));
    $n = max(1, (int)$stmt->fetchColumn());
    for ($i = 0; $i < 1000; $i++) {
        $no = $prefix . str_pad((string)($n + $i), 6, '0', STR_PAD_LEFT);
        $q = $pdo->prepare("SELECT id FROM `" . $table . "` WHERE tenant_id=:t AND `" . $column . "`=:n LIMIT 1");
        $q->execute(array(':t' => $tenant, ':n' => $no));
        if (!$q->fetchColumn()) return $no;
    }
    throw new RuntimeException('Unable to generate a unique document number.');
}

function aifNextNo(PDO $pdo, $tenant, $branchId, $type, $table, $column, $fallbackPrefix)
{
    if (!aifTable($pdo, 'document_sequences')) {
        return aifFallbackNo($pdo, $tenant, $table, $column, $fallbackPrefix);
    }
    if ($branchId > 0) {
        $stmt = $pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type=:dt AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1 FOR UPDATE");
        $stmt->execute(array(':t' => $tenant, ':dt' => $type, ':b' => $branchId, ':b2' => $branchId));
    } else {
        $stmt = $pdo->prepare("SELECT ds.*,NULL branch_code FROM document_sequences ds WHERE ds.tenant_id=:t AND ds.document_type=:dt AND ds.is_active=1 AND ds.branch_id IS NULL ORDER BY ds.id LIMIT 1 FOR UPDATE");
        $stmt->execute(array(':t' => $tenant, ':dt' => $type));
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return aifFallbackNo($pdo, $tenant, $table, $column, $fallbackPrefix);

    $now = new DateTime('now');
    $year = $now->format('Y');
    $month = $now->format('m');
    $fyStart = max(1, min(12, (int)$row['financial_year_start_month']));
    $yearNum = (int)$now->format('Y');
    $fyYear = (int)$now->format('n') >= $fyStart ? $yearNum : $yearNum - 1;
    $fy = $fyYear . '-' . substr((string)($fyYear + 1), -2);
    $resetKey = 'never';
    if ($row['reset_period'] === 'monthly') $resetKey = $year . $month;
    elseif ($row['reset_period'] === 'yearly') $resetKey = $year;
    elseif ($row['reset_period'] === 'financial_year') $resetKey = $fy;

    $current = (int)$row['current_number'];
    if ($row['reset_period'] !== 'never' && (string)$row['last_reset_key'] !== (string)$resetKey) $current = 0;
    $next = $current + 1;
    $middle = '';
    if ($row['middle_format'] === 'year') $middle = $year;
    elseif ($row['middle_format'] === 'year_month') $middle = $year . $month;
    elseif ($row['middle_format'] === 'financial_year') $middle = $fy;
    elseif ($row['middle_format'] === 'branch_year') $middle = (!empty($row['branch_code']) ? $row['branch_code'] : 'BR') . $year;

    $parts = array();
    if (!empty($row['prefix'])) $parts[] = $row['prefix'];
    if ($middle !== '') $parts[] = $middle;
    $parts[] = str_pad((string)$next, max(1, (int)$row['number_length']), '0', STR_PAD_LEFT);
    if (!empty($row['suffix'])) $parts[] = $row['suffix'];
    $separator = isset($row['number_separator']) ? (string)$row['number_separator'] : '-';
    $number = implode($separator, $parts);

    $update = $pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");
    $update->execute(array(':n' => $next, ':k' => $resetKey, ':id' => $row['id']));
    return $number;
}

function aifJobSlots(PDO $pdo, $tenant, $job)
{
    $slots = array();
    $jobId = (int)$job['id'];
    $totalExpected = max(1, (int)$job['total_invoices']);
    if (aifTable($pdo, 'visits')) {
        $stmt = $pdo->prepare("SELECT v.id,v.visit_no,v.visit_number,v.scheduled_start,v.scheduled_end,v.status,CASE WHEN EXISTS(SELECT 1 FROM invoices i WHERE i.tenant_id=v.tenant_id AND i.visit_id=v.id AND i.status NOT IN('cancelled','archived')) THEN 1 ELSE 0 END invoiced FROM visits v WHERE v.tenant_id=:t AND v.job_id=:j AND v.status<>'cancelled' ORDER BY COALESCE(v.scheduled_start,'9999-12-31'),v.visit_number,v.id");
        $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slots[] = array(
                'slot_key' => 'visit:' . (int)$row['id'],
                'visit_id' => (int)$row['id'],
                'visit_no' => $row['visit_no'],
                'visit_number' => (int)$row['visit_number'],
                'scheduled_start' => $row['scheduled_start'],
                'scheduled_end' => $row['scheduled_end'],
                'status' => $row['status'],
                'invoiced' => (int)$row['invoiced']
            );
        }
    }
    if (!$slots) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE tenant_id=:t AND job_id=:j AND status NOT IN('cancelled','archived')");
        $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
        $used = (int)$stmt->fetchColumn();
        for ($i = 1; $i <= $totalExpected; $i++) {
            $slots[] = array(
                'slot_key' => 'sequence:' . $i,
                'visit_id' => null,
                'visit_no' => 'Invoice ' . $i,
                'visit_number' => $i,
                'scheduled_start' => null,
                'scheduled_end' => null,
                'status' => 'scheduled',
                'invoiced' => $i <= $used ? 1 : 0
            );
        }
    }
    return $slots;
}

function aifJobItems(PDO $pdo, $tenant, $job, $visitId)
{
    $items = array();
    $jobId = (int)$job['id'];

    if (aifTable($pdo, 'job_line_items')) {
        $productIdSelect = aifColumn($pdo, 'job_line_items', 'product_id') ? 'product_id' : 'NULL AS product_id';
        $sourceSelect = aifColumn($pdo, 'job_line_items', 'item_source') ? "CASE WHEN item_source='product_service' THEN 'service' ELSE item_source END AS item_source" : "CASE WHEN product_service_id IS NOT NULL THEN 'service' ELSE 'manual' END AS item_source";
        $discountSelect = aifColumn($pdo, 'job_line_items', 'discount_amount') ? 'discount_amount' : '0 AS discount_amount';
        $taxPercentSelect = aifColumn($pdo, 'job_line_items', 'tax_percent') ? 'tax_percent' : '0 AS tax_percent';
        $sql = "SELECT product_service_id,{$productIdSelect},{$sourceSelect},item_name,description,quantity,unit_cost,unit_price,{$discountSelect},{$taxPercentSelect},tax_amount,line_total,sort_order FROM job_line_items WHERE tenant_id=:t AND job_id=:j ORDER BY sort_order,id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':t' => $tenant, ':j' => $jobId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) return $rows;
    }

    $totalInvoices = max(1, (int)$job['total_invoices']);
    $billingType = !empty($job['billing_type']) ? (string)$job['billing_type'] : 'visit_based';
    $fixed = isset($job['fixed_invoice_amount']) ? (float)$job['fixed_invoice_amount'] : 0.0;

    if ($billingType === 'fixed_price' && $fixed > 0.001) {
        $name = trim((string)$job['service_name']);
        if ($name === '') $name = trim((string)$job['title']);
        if ($name === '') $name = 'Job service';
        return array(array('product_service_id' => !empty($job['product_service_id']) ? (int)$job['product_service_id'] : null, 'product_id' => null, 'item_source' => 'service', 'item_name' => $name . ' - Fixed invoice', 'description' => 'Scheduled fixed-price billing for ' . $job['job_no'], 'quantity' => 1, 'unit_cost' => 0, 'unit_price' => $fixed, 'discount_amount' => 0, 'tax_percent' => 0, 'tax_amount' => 0, 'line_total' => $fixed, 'sort_order' => 0));
    }

    if (!empty($job['quote_id']) && $totalInvoices <= 1 && aifTable($pdo, 'quote_line_items')) {
        $stmt = $pdo->prepare("SELECT product_service_id,NULL AS product_id,'service' AS item_source,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,sort_order FROM quote_line_items WHERE quote_id=:q ORDER BY sort_order,id");
        $stmt->execute(array(':q' => (int)$job['quote_id']));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) return $rows;
    }

    $name = trim((string)$job['service_name']);
    if ($name === '') $name = trim((string)$job['title']);
    if ($name === '') $name = 'Job service';
    $unitPrice = isset($job['service_unit_price']) ? (float)$job['service_unit_price'] : 0.0;
    $unitCost = isset($job['service_unit_cost']) ? (float)$job['service_unit_cost'] : 0.0;
    $taxPercent = isset($job['service_tax_percent']) ? (float)$job['service_tax_percent'] : 0.0;
    if ($totalInvoices > 1 && (float)$job['total'] > 0.001) {
        $grossShare = round((float)$job['total'] / $totalInvoices, 2);
        $unitPrice = $taxPercent > 0 ? round($grossShare / (1 + ($taxPercent / 100)), 2) : $grossShare;
    } elseif ($unitPrice <= 0.001 && (float)$job['total'] > 0.001) {
        $unitPrice = $taxPercent > 0 ? round((float)$job['total'] / (1 + ($taxPercent / 100)), 2) : (float)$job['total'];
    }
    $base = $unitPrice;
    $tax = round($base * $taxPercent / 100, 2);
    return array(array('product_service_id' => !empty($job['product_service_id']) ? (int)$job['product_service_id'] : null, 'product_id' => null, 'item_source' => 'service', 'item_name' => $name . ($totalInvoices > 1 ? ' - Billing visit' : ''), 'description' => $visitId > 0 ? ('Visit billing for ' . $job['job_no']) : ('Job billing for ' . $job['job_no']), 'quantity' => 1, 'unit_cost' => $unitCost, 'unit_price' => $unitPrice, 'discount_amount' => 0, 'tax_percent' => $taxPercent, 'tax_amount' => $tax, 'line_total' => round($base + $tax, 2), 'sort_order' => 0));
}

function aifLog(PDO $pdo, $tenant, $branch, $user, $client, $type, $relatedId, $title, $details)
{
    if (!aifTable($pdo, 'activity_events')) return;
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user',:e,:rt,:rid,:c,:title,:d,0)");
        $stmt->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : null, ':u' => $user, ':e' => $type, ':rt' => strpos($type, 'payment') !== false ? 'payment' : 'invoice', ':rid' => $relatedId, ':c' => $client > 0 ? $client : null, ':title' => substr($title, 0, 255), ':d' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    } catch (Throwable $e) {
        error_log('invoice form activity ' . $e->getMessage());
    }
}


function aifTenantInfo(PDO $pdo, $tenant)
{
    $stmt = $pdo->prepare("SELECT id,display_name,legal_name,email,phone,website_url FROM tenants WHERE id=:t AND deleted_at IS NULL LIMIT 1");
    $stmt->execute(array(':t' => $tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : array('display_name' => 'FieldPlx', 'legal_name' => 'FieldPlx', 'email' => '', 'phone' => '', 'website_url' => '');
}

function aifSmtpSecretKey()
{
    if (!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $secretFile = __DIR__ . '/../includes/smtp-secret.php';
        if (is_file($secretFile)) require_once $secretFile;
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
    if ($key === '' || strlen($key) < 32) throw new RuntimeException('SMTP encryption key is not configured.');
    return hash('sha256', $key, true);
}

function aifDecryptSmtpPassword($stored)
{
    $stored = trim((string)$stored);
    if ($stored === '') return '';
    if (strpos($stored, 'v1:') !== 0) throw new RuntimeException('SMTP password uses an unsupported encryption format. Re-save the SMTP password in Master Controls.');
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= 16) throw new RuntimeException('Stored SMTP password is invalid.');
    $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', aifSmtpSecretKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    if ($plain === false) throw new RuntimeException('Unable to decrypt the SMTP password. Confirm the same permanent SMTP encryption key is configured on this server.');
    return $plain;
}

function aifSmtpConfig(PDO $pdo, $tenant, $branch)
{
    if (!aifTable($pdo, 'smtp_configurations')) return null;
    $sql = "SELECT * FROM smtp_configurations WHERE is_active=1 AND ("
         . "(scope_type='branch' AND tenant_id=:t1 AND branch_id=:b1) OR "
         . "(scope_type='tenant' AND tenant_id=:t2) OR "
         . "(scope_type='platform' AND tenant_id IS NULL)) "
         . "ORDER BY CASE "
         . "WHEN scope_type='branch' AND tenant_id=:t3 AND branch_id=:b2 THEN 0 "
         . "WHEN scope_type='tenant' AND tenant_id=:t4 THEN 1 ELSE 2 END, is_default DESC, id DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $b = $branch > 0 ? $branch : -1;
    $stmt->execute(array(':t1'=>$tenant, ':b1'=>$b, ':t2'=>$tenant, ':t3'=>$tenant, ':b2'=>$b, ':t4'=>$tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function aifSmtpRead($socket)
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) break;
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return trim($response);
}

function aifSmtpCmd($socket, $cmd, $ok, $label)
{
    if ($cmd !== null && @fwrite($socket, $cmd . "\r\n") === false) throw new RuntimeException('SMTP connection closed during ' . $label . '.');
    $response = aifSmtpRead($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, (array)$ok, true)) throw new RuntimeException($label . ' failed (SMTP ' . $code . '): ' . substr(preg_replace('/[\r\n]+/', ' ', $response), 0, 220));
    return $response;
}

function aifSendMail($cfg, $password, $to, $subject, $html)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Customer email address is invalid.');
    $host = trim((string)$cfg['host']);
    $port = (int)$cfg['port'];
    $enc = strtolower(trim((string)$cfg['encryption']));
    $user = trim((string)$cfg['username']);
    $from = trim((string)$cfg['from_email']);
    if ($host === '' || $port <= 0) throw new RuntimeException('SMTP host or port is not configured.');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP From Email is invalid.');
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $context = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host)));
    $errno = 0; $err = '';
    $socket = @stream_socket_client($remote, $errno, $err, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException('Unable to connect to SMTP server: ' . ($err !== '' ? $err : 'connection failed'));
    stream_set_timeout($socket, 20);
    try {
        aifSmtpCmd($socket, null, array(220), 'SMTP greeting');
        $ehlo = isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== '' ? preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME']) : 'fieldplx.local';
        if ($ehlo === '') $ehlo = 'fieldplx.local';
        aifSmtpCmd($socket, 'EHLO ' . $ehlo, array(250), 'EHLO');
        if ($enc === 'tls' || $enc === 'starttls') {
            aifSmtpCmd($socket, 'STARTTLS', array(220), 'STARTTLS');
            $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (@stream_socket_enable_crypto($socket, true, $method) !== true) throw new RuntimeException('Unable to establish TLS encryption with the SMTP server.');
            aifSmtpCmd($socket, 'EHLO ' . $ehlo, array(250), 'EHLO after TLS');
        }
        if ($user !== '') {
            if ($password === '') throw new RuntimeException('SMTP password is empty.');
            aifSmtpCmd($socket, 'AUTH LOGIN', array(334), 'SMTP authentication');
            aifSmtpCmd($socket, base64_encode($user), array(334), 'SMTP username');
            aifSmtpCmd($socket, base64_encode($password), array(235), 'SMTP password');
        }
        aifSmtpCmd($socket, 'MAIL FROM:<' . $from . '>', array(250), 'MAIL FROM');
        aifSmtpCmd($socket, 'RCPT TO:<' . $to . '>', array(250,251), 'RCPT TO');
        aifSmtpCmd($socket, 'DATA', array(354), 'DATA');
        $fromName = trim((string)$cfg['from_name']);
        if ($fromName === '') $fromName = 'FieldPlx';
        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . str_replace(array("\r","\n"), ' ', $fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . str_replace(array("\r","\n"), ' ', $subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        );
        if (!empty($cfg['reply_to_email']) && filter_var($cfg['reply_to_email'], FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: <' . $cfg['reply_to_email'] . '>';
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $html;
        $payload = preg_replace('/(?m)^\./', '..', $payload);
        @fwrite($socket, $payload . "\r\n.\r\n");
        aifSmtpCmd($socket, null, array(250), 'Message delivery');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }
    return true;
}

function aifNotificationEventId(PDO $pdo)
{
    if (!aifTable($pdo, 'notification_events')) return 0;
    $stmt = $pdo->prepare("SELECT id FROM notification_events WHERE event_key='invoice.sent' AND is_active=1 ORDER BY id LIMIT 1");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function aifQueueLog(PDO $pdo, $tenant, $branch, $clientId, $email, $invoiceId, $subject, $body, $smtpId, $status, $error)
{
    if (!aifTable($pdo, 'notification_queue')) return;
    $eventId = aifNotificationEventId($pdo);
    if ($eventId <= 0) return;
    try {
        $stmt = $pdo->prepare("INSERT INTO notification_queue(tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address,related_type,related_id,subject,body,smtp_config_id,status,attempts,scheduled_at,sent_at,error_message,created_at) VALUES(:t,:b,:e,'email','client',:c,:addr,'invoice',:rid,:sub,:body,:smtp,:st,1,NOW(),:sent,:err,NOW())");
        $stmt->execute(array(':t'=>$tenant, ':b'=>$branch>0?$branch:null, ':e'=>$eventId, ':c'=>$clientId, ':addr'=>$email!==''?$email:null, ':rid'=>$invoiceId, ':sub'=>$subject, ':body'=>$body, ':smtp'=>$smtpId>0?$smtpId:null, ':st'=>$status, ':sent'=>$status==='sent'?date('Y-m-d H:i:s'):null, ':err'=>$error!==''?$error:null));
    } catch (Throwable $e) {
        error_log('invoice notification queue log ' . $e->getMessage());
    }
}

function aifEmailInvoice(PDO $pdo, $tenant, $branch, $client, $invoiceId, $invoiceNo, $issueDate, $dueDate, $subject, $clientMessage, $items, $total, $currency)
{
    $out = array('sent'=>0, 'status'=>'skipped', 'message'=>'', 'smtp_id'=>0);
    $email = trim((string)(isset($client['email']) ? $client['email'] : ''));
    if (isset($client['allow_email']) && (int)$client['allow_email'] !== 1) {
        $out['message'] = 'Customer email was not sent because email communication is disabled for this customer.';
        return $out;
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $out['message'] = 'Customer email was not sent because the customer does not have a valid email address.';
        return $out;
    }
    $cfg = aifSmtpConfig($pdo, $tenant, $branch);
    if (!$cfg) {
        $out['message'] = 'Customer email was not sent because no active SMTP configuration was found.';
        return $out;
    }
    $out['smtp_id'] = (int)$cfg['id'];
    $tenantInfo = aifTenantInfo($pdo, $tenant);
    $businessName = trim((string)$tenantInfo['display_name']);
    if ($businessName === '') $businessName = 'FieldPlx';
    $customerName = trim((string)(isset($client['display_name']) ? $client['display_name'] : 'Customer'));
    if ($customerName === '') $customerName = 'Customer';
    $symbol = isset($currency['symbol']) ? (string)$currency['symbol'] : '';
    $decimals = isset($currency['decimal_places']) ? (int)$currency['decimal_places'] : 2;
    $amount = number_format((float)$total, $decimals, '.', ',');
    $amountText = isset($currency['symbol_position']) && $currency['symbol_position'] === 'after' ? $amount . ' ' . $symbol : $symbol . $amount;
    $rows = '';
    foreach ($items as $item) {
        $line = number_format((float)$item['line_total'], $decimals, '.', ',');
        $lineText = isset($currency['symbol_position']) && $currency['symbol_position'] === 'after' ? $line . ' ' . $symbol : $symbol . $line;
        $rows .= '<tr><td style="padding:9px 8px;border-bottom:1px solid #e7ebee">' . htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') . '</td><td style="padding:9px 8px;border-bottom:1px solid #e7ebee;text-align:center">' . htmlspecialchars((string)$item['quantity'], ENT_QUOTES, 'UTF-8') . '</td><td style="padding:9px 8px;border-bottom:1px solid #e7ebee;text-align:right">' . htmlspecialchars($lineText, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    $mailSubject = 'Invoice ' . $invoiceNo . ' from ' . $businessName;
    $safeMessage = trim((string)$clientMessage) !== '' ? '<p style="margin:0 0 16px">' . nl2br(htmlspecialchars($clientMessage, ENT_QUOTES, 'UTF-8')) . '</p>' : '';
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:680px;margin:auto;color:#17334f;background:#fff">'
          . '<div style="padding:20px 22px;background:#001131;color:#fff"><div style="font-size:12px;opacity:.82">' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '</div><h2 style="margin:4px 0 0;font-size:22px">Invoice ' . htmlspecialchars($invoiceNo, ENT_QUOTES, 'UTF-8') . '</h2></div>'
          . '<div style="padding:22px;border:1px solid #e5eaf1;border-top:0">'
          . '<p>Hello ' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ',</p>'
          . $safeMessage
          . '<p>Your invoice has been created successfully. Please find the billing summary below.</p>'
          . '<div style="margin:16px 0;padding:14px;background:#f7f9fb;border-radius:8px"><strong>Invoice:</strong> ' . htmlspecialchars($invoiceNo, ENT_QUOTES, 'UTF-8') . '<br><strong>Issue date:</strong> ' . htmlspecialchars($issueDate, ENT_QUOTES, 'UTF-8') . '<br><strong>Due date:</strong> ' . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . '<br><strong>Total:</strong> ' . htmlspecialchars($amountText, ENT_QUOTES, 'UTF-8') . '</div>'
          . ($subject !== '' ? '<p><strong>Subject:</strong> ' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</p>' : '')
          . '<table style="width:100%;border-collapse:collapse;margin:18px 0"><thead><tr><th style="padding:9px 8px;text-align:left;border-bottom:2px solid #dfe6ea">Product / Service</th><th style="padding:9px 8px;text-align:center;border-bottom:2px solid #dfe6ea">Qty</th><th style="padding:9px 8px;text-align:right;border-bottom:2px solid #dfe6ea">Total</th></tr></thead><tbody>' . $rows . '</tbody></table>'
          . '<p style="margin-top:20px">Thank you,<br>' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '</p>'
          . '</div></div>';
    try {
        $password = aifDecryptSmtpPassword(isset($cfg['password_encrypted']) ? $cfg['password_encrypted'] : '');
        aifSendMail($cfg, $password, $email, $mailSubject, $html);
        $pdo->prepare("UPDATE invoices SET status=CASE WHEN status='draft' THEN 'sent' ELSE status END,sent_at=COALESCE(sent_at,NOW()) WHERE id=:i AND tenant_id=:t")->execute(array(':i'=>$invoiceId, ':t'=>$tenant));
        $out['sent'] = 1;
        $out['status'] = 'sent';
        $out['message'] = 'Invoice email sent to ' . $email . '.';
        aifQueueLog($pdo, $tenant, $branch, (int)$client['id'], $email, $invoiceId, $mailSubject, $html, (int)$cfg['id'], 'sent', '');
    } catch (Throwable $e) {
        $out['status'] = 'failed';
        $out['message'] = 'Invoice was created, but customer email failed: ' . $e->getMessage();
        aifQueueLog($pdo, $tenant, $branch, (int)$client['id'], $email, $invoiceId, $mailSubject, $html, (int)$cfg['id'], 'failed', $e->getMessage());
        error_log('invoice customer email ' . $e->getMessage());
    }
    return $out;
}

function aifUploadArray($key)
{
    $out = array();
    if (empty($_FILES[$key]) || !isset($_FILES[$key]['name'])) return $out;
    $f = $_FILES[$key];
    if (is_array($f['name'])) {
        $count = count($f['name']);
        for ($i=0; $i<$count; $i++) {
            $out[] = array(
                'name'=>isset($f['name'][$i])?$f['name'][$i]:'',
                'type'=>isset($f['type'][$i])?$f['type'][$i]:'',
                'tmp_name'=>isset($f['tmp_name'][$i])?$f['tmp_name'][$i]:'',
                'error'=>isset($f['error'][$i])?(int)$f['error'][$i]:UPLOAD_ERR_NO_FILE,
                'size'=>isset($f['size'][$i])?(int)$f['size'][$i]:0
            );
        }
    } else {
        $out[] = array('name'=>$f['name'], 'type'=>$f['type'], 'tmp_name'=>$f['tmp_name'], 'error'=>(int)$f['error'], 'size'=>(int)$f['size']);
    }
    return $out;
}

function aifStoreInvoiceUploads(PDO $pdo, $tenant, $user, $invoiceId, $jobId, $visitId)
{
    $result = array('saved'=>0, 'skipped'=>0, 'messages'=>array());
    $imageFiles = aifUploadArray('invoice_images');
    $attachmentFiles = aifUploadArray('invoice_attachments');
    if (!$imageFiles && !$attachmentFiles) return $result;
    if (!aifTable($pdo, 'attachments')) {
        $result['skipped'] = count($imageFiles) + count($attachmentFiles);
        $result['messages'][] = 'Images and attachments were not stored because the attachments table is unavailable.';
        return $result;
    }
    $baseDir = dirname(__DIR__) . '/uploads/invoices/' . (int)$tenant . '/' . (int)$invoiceId;
    if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        $result['skipped'] = count($imageFiles) + count($attachmentFiles);
        $result['messages'][] = 'Images and attachments could not be stored because the invoice upload folder is not writable.';
        return $result;
    }
    $groups = array(
        array('files'=>$imageFiles, 'max'=>25*1024*1024, 'allowed'=>array('avif','jpg','jpeg','png','webp','heic'), 'kind'=>'image'),
        array('files'=>$attachmentFiles, 'max'=>50*1024*1024, 'allowed'=>array('avif','jpg','jpeg','png','webp','heic','pdf','doc','docx'), 'kind'=>'attachment')
    );
    foreach ($groups as $group) {
        $count = 0;
        foreach ($group['files'] as $f) {
            if ($count >= 10) { $result['skipped']++; continue; }
            $count++;
            if ((int)$f['error'] === UPLOAD_ERR_NO_FILE) continue;
            if ((int)$f['error'] !== UPLOAD_ERR_OK) { $result['skipped']++; $result['messages'][] = basename((string)$f['name']) . ' could not be uploaded.'; continue; }
            if ((int)$f['size'] <= 0 || (int)$f['size'] > (int)$group['max']) { $result['skipped']++; $result['messages'][] = basename((string)$f['name']) . ' exceeded the upload size limit.'; continue; }
            $original = basename((string)$f['name']);
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if (!in_array($ext, $group['allowed'], true)) { $result['skipped']++; $result['messages'][] = $original . ' has an unsupported file type.'; continue; }
            $savedName = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
            $target = $baseDir . '/' . $savedName;
            if (!@move_uploaded_file((string)$f['tmp_name'], $target)) { $result['skipped']++; $result['messages'][] = $original . ' could not be stored.'; continue; }
            $mime = '';
            if (function_exists('finfo_open')) {
                $fi = @finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) { $mime = (string)@finfo_file($fi, $target); @finfo_close($fi); }
            }
            if ($group['kind'] === 'image' && $mime !== '' && strpos($mime, 'image/') !== 0 && $ext !== 'heic') {
                @unlink($target); $result['skipped']++; $result['messages'][] = $original . ' is not a valid image.'; continue;
            }
            $relative = 'uploads/invoices/' . (int)$tenant . '/' . (int)$invoiceId . '/' . $savedName;
            $attachmentType = $group['kind'] === 'attachment' ? 'document' : 'file';
            try {
                $stmt = $pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,job_id,visit_id,workflow_step_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type,description) VALUES(:t,'invoice',:rid,:j,:v,NULL,:u,:fn,:fp,:fm,:fs,:at,NULL)");
                $stmt->execute(array(':t'=>$tenant, ':rid'=>$invoiceId, ':j'=>$jobId>0?$jobId:null, ':v'=>$visitId>0?$visitId:null, ':u'=>$user, ':fn'=>$original, ':fp'=>$relative, ':fm'=>$mime!==''?$mime:null, ':fs'=>(int)$f['size'], ':at'=>$attachmentType));
                $result['saved']++;
            } catch (Throwable $e) {
                @unlink($target);
                $result['skipped']++;
                $result['messages'][] = $original . ' could not be linked to the invoice.';
                error_log('invoice attachment insert ' . $e->getMessage());
            }
        }
    }
    return $result;
}

$tenant = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$user = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$sessionBranch = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
if ($tenant <= 0 || $user <= 0) aifRes(401, false, 'Authentication required.', array());

$csrf = (string)aifP('csrf_token', '');
$token = isset($_SESSION['invoice_form_csrf_token']) ? (string)$_SESSION['invoice_form_csrf_token'] : '';
$legacy = isset($_SESSION['invoices_csrf_token']) ? (string)$_SESSION['invoices_csrf_token'] : '';
if ($csrf === '' || !(($token !== '' && hash_equals($token, $csrf)) || ($legacy !== '' && hash_equals($legacy, $csrf)))) {
    aifRes(419, false, 'Your form session expired. Refresh and try again.', array());
}

$action = trim((string)aifP('action', ''));

try {
    if ($action === 'form_meta') {
        $clientsStmt = $pdo->prepare("SELECT c.id,c.branch_id,c.display_name name,c.company_name,c.email,c.phone,c.tax_number,c.allow_email,b.name branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.tenant_id=:t AND c.deleted_at IS NULL AND c.client_type<>'archived' AND c.status<>'archived' ORDER BY c.display_name,c.id");
        $clientsStmt->execute(array(':t' => $tenant));

        $locationsStmt = $pdo->prepare("SELECT id,client_id,name,address_line1,address_line2,city,state,postal_code,is_primary FROM client_locations WHERE tenant_id=:t AND deleted_at IS NULL AND status='active' ORDER BY is_primary DESC,name,id");
        $locationsStmt->execute(array(':t' => $tenant));

        $branchesStmt = $pdo->prepare("SELECT id,branch_code,name,currency_id FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name,id");
        $branchesStmt->execute(array(':t' => $tenant));

        $servicesSql = "SELECT id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent FROM product_services WHERE tenant_id=:t AND deleted_at IS NULL AND status='active'";
        if (aifColumn($pdo, 'product_services', 'item_type')) $servicesSql .= " AND item_type='service'";
        $servicesSql .= " ORDER BY name,id";
        $servicesStmt = $pdo->prepare($servicesSql);
        $servicesStmt->execute(array(':t' => $tenant));

        $productRows = array();
        if (aifTable($pdo, 'products')) {
            $productSql = "SELECT id,sku,name,description,unit_name,base_unit_price,selling_price,tax_percent FROM products WHERE tenant_id=:t AND status='active'";
            if (aifColumn($pdo, 'products', 'deleted_at')) $productSql .= " AND deleted_at IS NULL";
            $productSql .= " ORDER BY name,id";
            $productsStmt = $pdo->prepare($productSql);
            $productsStmt->execute(array(':t' => $tenant));
            $productRows = $productsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $currentUser = array('id' => $user, 'name' => 'Current user');
        if (aifTable($pdo, 'users')) {
            $userStmt = $pdo->prepare("SELECT id,first_name,last_name FROM users WHERE id=:u AND tenant_id=:t LIMIT 1");
            $userStmt->execute(array(':u' => $user, ':t' => $tenant));
            $ur = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($ur) $currentUser = array('id' => (int)$ur['id'], 'name' => trim($ur['first_name'] . ' ' . $ur['last_name']));
        }

        $billingJoin = aifTable($pdo, 'job_billing_settings') ? " LEFT JOIN job_billing_settings jbs ON jbs.job_id=j.id AND jbs.tenant_id=j.tenant_id " : "";
        $billingSelect = aifTable($pdo, 'job_billing_settings') ? ",COALESCE(jbs.billing_type,'visit_based') billing_type,COALESCE(jbs.total_invoices,1) total_invoices,jbs.fixed_invoice_amount" : ",'visit_based' billing_type,1 total_invoices,NULL fixed_invoice_amount";
        $jobsSql = "SELECT j.id,j.job_no,j.title,j.status,j.job_type,j.client_id,j.location_id,j.branch_id,j.quote_id,j.product_service_id,j.total,c.display_name client_name,cl.name location_name,b.name branch_name,q.quote_no,ps.name service_name" . $billingSelect . " FROM jobs j INNER JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id LEFT JOIN client_locations cl ON cl.id=j.location_id AND cl.tenant_id=j.tenant_id LEFT JOIN branches b ON b.id=j.branch_id AND b.tenant_id=j.tenant_id LEFT JOIN quotes q ON q.id=j.quote_id AND q.tenant_id=j.tenant_id LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id " . $billingJoin . " WHERE j.tenant_id=:t AND j.deleted_at IS NULL AND j.status NOT IN('cancelled','archived','closed') ORDER BY j.id DESC";
        $jobsStmt = $pdo->prepare($jobsSql);
        $jobsStmt->execute(array(':t' => $tenant));

        aifRes(200, true, 'Invoice form data loaded.', array('meta' => array(
            'clients' => $clientsStmt->fetchAll(PDO::FETCH_ASSOC),
            'locations' => $locationsStmt->fetchAll(PDO::FETCH_ASSOC),
            'branches' => $branchesStmt->fetchAll(PDO::FETCH_ASSOC),
            'services' => $servicesStmt->fetchAll(PDO::FETCH_ASSOC),
            'products' => $productRows,
            'current_user' => $currentUser,
            'jobs' => $jobsStmt->fetchAll(PDO::FETCH_ASSOC),
            'currency' => aifCurrency($pdo, $tenant),
            'session_branch_id' => $sessionBranch,
            'has_recurring_job_unique_index' => aifIndex($pdo, 'invoices', 'uq_invoice_tenant_job') ? 1 : 0
        )));
    }

    if ($action === 'payment_hints') {
        $clientId = (int)aifP('client_id', 0);
        $client = aifClient($pdo, $tenant, $clientId);
        if (!$client) aifRes(404, false, 'Customer not found.', array());
        aifRes(200, true, 'Customer payment details loaded.', array('hints' => aifPaymentHints($pdo, $tenant, $clientId)));
    }

    if ($action === 'job_context') {
        $jobId = (int)aifP('job_id', 0);
        $job = aifJob($pdo, $tenant, $jobId);
        if (!$job) aifRes(404, false, 'Job not found.', array());
        if (in_array($job['status'], array('cancelled','archived','closed'), true)) aifRes(409, false, 'This job cannot be invoiced in its current status.', array());
        $slots = aifJobSlots($pdo, $tenant, $job);
        $requestedVisitId = (int)aifP('visit_id', 0);
        $selectedVisitId = 0;
        foreach ($slots as $slot) {
            if ($requestedVisitId > 0 && (int)$slot['visit_id'] === $requestedVisitId && (int)$slot['invoiced'] === 0) $selectedVisitId = $requestedVisitId;
        }
        if ($selectedVisitId <= 0) {
            foreach ($slots as $slot) {
                if ((int)$slot['invoiced'] === 0 && !empty($slot['visit_id'])) { $selectedVisitId = (int)$slot['visit_id']; break; }
            }
        }
        $items = aifJobItems($pdo, $tenant, $job, $selectedVisitId);
        aifRes(200, true, 'Job billing data loaded.', array(
            'job' => $job,
            'billing_slots' => $slots,
            'items' => $items,
            'payment_hints' => aifPaymentHints($pdo, $tenant, (int)$job['client_id'])
        ));
    }

    if ($action === 'save') {
        if (!aifTable($pdo, 'invoices') || !aifTable($pdo, 'invoice_line_items')) {
            aifRes(500, false, 'Invoice tables are not installed.', array());
        }

        $sourceMode = trim((string)aifP('source_mode', 'direct'));
        if (!in_array($sourceMode, array('job','direct'), true)) aifRes(422, false, 'Select a valid invoice source.', array());

        $issueDate = trim((string)aifP('issue_date', ''));
        $dueDate = trim((string)aifP('due_date', ''));
        $requestedStatus = 'draft';
        $paymentTerms = trim((string)aifP('payment_terms', ''));
        $subject = trim((string)aifP('subject', ''));
        $clientMessage = trim((string)aifP('client_message', ''));
        $contractDisclaimer = trim((string)aifP('contract_disclaimer', ''));
        $notes = trim((string)aifP('notes', ''));
        $customFieldsRaw = json_decode((string)aifP('custom_fields_json', '[]'), true);
        if (!is_array($customFieldsRaw)) $customFieldsRaw = array();
        $customFields = array();
        foreach ($customFieldsRaw as $customIndex => $custom) {
            if (!is_array($custom)) continue;
            $label = trim((string)(isset($custom['label']) ? $custom['label'] : ''));
            $value = trim((string)(isset($custom['value']) ? $custom['value'] : ''));
            if ($label === '' && $value === '') continue;
            if ($label === '') aifRes(422, false, 'Custom field ' . ((int)$customIndex + 1) . ' needs a label.', array());
            $customFields[] = array('label'=>substr($label,0,190), 'value'=>substr($value,0,5000));
            if (count($customFields) >= 20) break;
        }
        if ($customFields && !aifTable($pdo, 'invoice_custom_fields')) aifRes(500, false, 'Invoice custom fields are not installed. Run migration_invoice_custom_fields_v1.sql once.', array());
        if ($issueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) aifRes(422, false, 'Select a valid invoice issue date.', array());

        $job = null;
        $jobId = 0;
        $visitId = 0;
        $quoteId = 0;
        $clientId = 0;
        $locationId = 0;
        $branchId = 0;

        if ($sourceMode === 'job') {
            $jobId = (int)aifP('job_id', 0);
            $visitId = (int)aifP('visit_id', 0);
            $job = aifJob($pdo, $tenant, $jobId);
            if (!$job) aifRes(404, false, 'Selected job was not found.', array());
            if (in_array($job['status'], array('cancelled','archived','closed'), true)) aifRes(409, false, 'This job cannot be invoiced in its current status.', array());
            $clientId = (int)$job['client_id'];
            $locationId = !empty($job['location_id']) ? (int)$job['location_id'] : 0;
            $branchId = !empty($job['branch_id']) ? (int)$job['branch_id'] : $sessionBranch;
            $quoteId = !empty($job['quote_id']) ? (int)$job['quote_id'] : 0;
            $slots = aifJobSlots($pdo, $tenant, $job);
            $availableVisitIds = array();
            $availableSequence = 0;
            foreach ($slots as $slot) {
                if ((int)$slot['invoiced'] === 0) {
                    if (!empty($slot['visit_id'])) $availableVisitIds[] = (int)$slot['visit_id'];
                    else $availableSequence++;
                }
            }
            $invoiceCountStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE tenant_id=:t AND job_id=:j AND status NOT IN('cancelled','archived')");
            $invoiceCountStmt->execute(array(':t' => $tenant, ':j' => $jobId));
            $existingInvoiceCount = (int)$invoiceCountStmt->fetchColumn();
            $expectedInvoiceCount = max(1, (int)$job['total_invoices']);
            if ($existingInvoiceCount >= $expectedInvoiceCount) aifRes(409, false, 'All configured invoice slots for this Job Card are already invoiced.', array());
            if ($visitId > 0 && !in_array($visitId, $availableVisitIds, true)) aifRes(409, false, 'The selected job visit is already invoiced or is not available.', array());
            if ($expectedInvoiceCount > 1 && $visitId <= 0 && count($availableVisitIds) > 0) aifRes(422, false, 'Select the job billing visit for this invoice.', array());
            if ($expectedInvoiceCount <= 1) {
                $dup = $pdo->prepare("SELECT id,invoice_no FROM invoices WHERE tenant_id=:t AND job_id=:j AND status NOT IN('cancelled','archived') LIMIT 1");
                $dup->execute(array(':t' => $tenant, ':j' => $jobId));
                $existing = $dup->fetch(PDO::FETCH_ASSOC);
                if ($existing) aifRes(409, false, 'This one-off job already has invoice ' . $existing['invoice_no'] . '.', array('invoice_id' => (int)$existing['id']));
            }
        } else {
            $clientId = (int)aifP('client_id', 0);
            $locationId = (int)aifP('location_id', 0);
            $branchId = (int)aifP('branch_id', 0);
            $client = aifClient($pdo, $tenant, $clientId);
            if (!$client) aifRes(422, false, 'Select a valid customer.', array());
            if ($locationId > 0 && !aifLocation($pdo, $tenant, $clientId, $locationId)) aifRes(422, false, 'Selected customer location is invalid.', array());
            if ($branchId <= 0) $branchId = !empty($client['branch_id']) ? (int)$client['branch_id'] : $sessionBranch;
            if ($branchId > 0 && !aifBranch($pdo, $tenant, $branchId)) aifRes(422, false, 'Select a valid branch.', array());
        }

        $client = aifClient($pdo, $tenant, $clientId);
        if (!$client) aifRes(422, false, 'Invoice customer could not be loaded.', array());

        $items = json_decode((string)aifP('items_json', '[]'), true);
        if (!is_array($items) || !count($items)) aifRes(422, false, 'Add at least one invoice item.', array());
        $normalized = array();
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $grandTotal = 0.0;
        foreach ($items as $index => $item) {
            $psid = isset($item['product_service_id']) ? (int)$item['product_service_id'] : 0;
            $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            $itemSource = strtolower(trim((string)(isset($item['item_source']) ? $item['item_source'] : 'manual')));
            if ($itemSource === 'product_service') $itemSource = 'service';
            if (!in_array($itemSource, array('service','product','manual'), true)) $itemSource = 'manual';
            $newProductName = trim((string)(isset($item['new_product_name']) ? $item['new_product_name'] : ''));
            if ($psid > 0) {
                $check = $pdo->prepare("SELECT id FROM product_services WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL AND status='active' LIMIT 1");
                $check->execute(array(':id' => $psid, ':t' => $tenant));
                if (!$check->fetchColumn()) aifRes(422, false, 'One selected service is no longer available.', array());
                $itemSource = 'service';
            } else $psid = null;
            if ($productId > 0) {
                if (!aifTable($pdo, 'products')) aifRes(422, false, 'Product catalog is unavailable.', array());
                $productSql = "SELECT id FROM products WHERE id=:id AND tenant_id=:t AND status='active'" . (aifColumn($pdo, 'products', 'deleted_at') ? " AND deleted_at IS NULL" : "") . " LIMIT 1";
                $check = $pdo->prepare($productSql);
                $check->execute(array(':id' => $productId, ':t' => $tenant));
                if (!$check->fetchColumn()) aifRes(422, false, 'One selected product is no longer available.', array());
                $itemSource = 'product';
            } else $productId = null;
            $name = trim((string)(isset($item['item_name']) ? $item['item_name'] : ''));
            if ($name === '') aifRes(422, false, 'Invoice item name is required.', array());
            $qty = max(0.001, (float)(isset($item['quantity']) ? $item['quantity'] : 1));
            $unitCost = max(0, (float)(isset($item['unit_cost']) ? $item['unit_cost'] : 0));
            $unitPrice = max(0, (float)(isset($item['unit_price']) ? $item['unit_price'] : 0));
            $base = round($qty * $unitPrice, 2);
            $discount = max(0, min($base, (float)(isset($item['discount_amount']) ? $item['discount_amount'] : 0)));
            $taxPercent = max(0, (float)(isset($item['tax_percent']) ? $item['tax_percent'] : 0));
            $taxable = max(0, $base - $discount);
            $taxAmount = round($taxable * $taxPercent / 100, 2);
            $lineTotal = round($taxable + $taxAmount, 2);
            $subtotal += $base;
            $discountTotal += $discount;
            $taxTotal += $taxAmount;
            $grandTotal += $lineTotal;
            $normalized[] = array(
                'product_service_id' => $psid,
                'product_id' => $productId,
                'item_source' => $itemSource,
                'new_product_name' => $newProductName,
                'item_name' => $name,
                'description' => trim((string)(isset($item['description']) ? $item['description'] : '')),
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
                'sort_order' => $index
            );
        }
        $subtotal = round($subtotal, 2);
        $discountTotal = round($discountTotal, 2);
        $taxTotal = round($taxTotal, 2);
        $grandTotal = round($grandTotal, 2);
        if ($grandTotal <= 0) aifRes(422, false, 'Invoice total must be greater than zero.', array());

        if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) $dueDate = $issueDate;
        if ($dueDate < $issueDate) aifRes(422, false, 'Due date cannot be before the invoice issue date.', array());
        if ($paymentTerms === '') $paymentTerms = 'Due upon receipt';

        $receivedTotal = 0.0;
        $creditTotal = $grandTotal;
        $balanceDue = $grandTotal;
        $invoiceStatus = 'draft';

        $currency = aifCurrency($pdo, $tenant);
        $currencyId = (int)$currency['id'];
        $pdo->beginTransaction();
        try {
            if ($sourceMode === 'job') {
                $lock = $pdo->prepare("SELECT id,status FROM jobs WHERE id=:j AND tenant_id=:t AND deleted_at IS NULL FOR UPDATE");
                $lock->execute(array(':j' => $jobId, ':t' => $tenant));
                if (!$lock->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('Selected job is no longer available.');
                if ($visitId > 0) {
                    $dupVisit = $pdo->prepare("SELECT id,invoice_no FROM invoices WHERE tenant_id=:t AND visit_id=:v AND status NOT IN('cancelled','archived') LIMIT 1 FOR UPDATE");
                    $dupVisit->execute(array(':t' => $tenant, ':v' => $visitId));
                    $existingVisitInvoice = $dupVisit->fetch(PDO::FETCH_ASSOC);
                    if ($existingVisitInvoice) throw new RuntimeException('This job visit already has invoice ' . $existingVisitInvoice['invoice_no'] . '.');
                }
                if ((int)$job['total_invoices'] <= 1) {
                    $dupJob = $pdo->prepare("SELECT id,invoice_no FROM invoices WHERE tenant_id=:t AND job_id=:j AND status NOT IN('cancelled','archived') LIMIT 1 FOR UPDATE");
                    $dupJob->execute(array(':t' => $tenant, ':j' => $jobId));
                    $existingJobInvoice = $dupJob->fetch(PDO::FETCH_ASSOC);
                    if ($existingJobInvoice) throw new RuntimeException('This one-off job already has invoice ' . $existingJobInvoice['invoice_no'] . '.');
                }
            }

            $invoiceNo = aifNextNo($pdo, $tenant, $branchId, 'invoice', 'invoices', 'invoice_no', 'INV-');
            $invoiceColumns = array('tenant_id','branch_id','invoice_no','client_id','location_id','job_id','visit_id','quote_id','status','issue_date','due_date','subtotal','discount_total','tax_total','total','amount_paid','balance_due','payment_terms','notes','sent_at','paid_at','created_by');
            $invoiceValues = array(':t',':b',':no',':c',':l',':j',':v',':q',':st',':issue',':due',':sub',':disc',':tax',':tot',':paid',':bal',':terms',':notes','NULL','NULL',':u');
            if (aifColumn($pdo, 'invoices', 'subject')) { $invoiceColumns[]='subject'; $invoiceValues[]=':subject'; }
            if (aifColumn($pdo, 'invoices', 'client_message')) { $invoiceColumns[]='client_message'; $invoiceValues[]=':client_message'; }
            if (aifColumn($pdo, 'invoices', 'contract_disclaimer')) { $invoiceColumns[]='contract_disclaimer'; $invoiceValues[]=':contract_disclaimer'; }
            $invoiceStmt = $pdo->prepare("INSERT INTO invoices(" . implode(',', $invoiceColumns) . ") VALUES(" . implode(',', $invoiceValues) . ")");
            $invoiceParams = array(':t'=>$tenant, ':b'=>$branchId > 0 ? $branchId : null, ':no'=>$invoiceNo, ':c'=>$clientId, ':l'=>$locationId > 0 ? $locationId : null, ':j'=>$jobId > 0 ? $jobId : null, ':v'=>$visitId > 0 ? $visitId : null, ':q'=>$quoteId > 0 ? $quoteId : null, ':st'=>'draft', ':issue'=>$issueDate, ':due'=>$dueDate !== '' ? $dueDate : null, ':sub'=>$subtotal, ':disc'=>$discountTotal, ':tax'=>$taxTotal, ':tot'=>$grandTotal, ':paid'=>0, ':bal'=>$grandTotal, ':terms'=>$paymentTerms !== '' ? $paymentTerms : null, ':notes'=>$notes !== '' ? $notes : null, ':u'=>$user);
            if (aifColumn($pdo, 'invoices', 'subject')) $invoiceParams[':subject'] = $subject !== '' ? $subject : null;
            if (aifColumn($pdo, 'invoices', 'client_message')) $invoiceParams[':client_message'] = $clientMessage !== '' ? $clientMessage : null;
            if (aifColumn($pdo, 'invoices', 'contract_disclaimer')) $invoiceParams[':contract_disclaimer'] = $contractDisclaimer !== '' ? $contractDisclaimer : null;
            $invoiceStmt->execute($invoiceParams);
            $invoiceId = (int)$pdo->lastInsertId();

            foreach ($normalized as $k => $item) {
                if ($item['item_source'] === 'product' && empty($item['product_id']) && trim((string)$item['new_product_name']) !== '') {
                    if (!aifTable($pdo, 'products')) throw new RuntimeException('Products table is not available.');
                    $newName = substr(trim((string)$item['new_product_name']), 0, 190);
                    $findSql = "SELECT id FROM products WHERE tenant_id=:t AND LOWER(name)=LOWER(:n)" . (aifColumn($pdo, 'products', 'deleted_at') ? " AND deleted_at IS NULL" : "") . " LIMIT 1";
                    $find = $pdo->prepare($findSql);
                    $find->execute(array(':t' => $tenant, ':n' => $newName));
                    $existingProductId = (int)$find->fetchColumn();
                    if ($existingProductId > 0) {
                        $normalized[$k]['product_id'] = $existingProductId;
                    } else {
                        $insertProduct = $pdo->prepare("INSERT INTO products(tenant_id,sku,name,description,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_percent,status,created_by,created_at,updated_at,deleted_at) VALUES(:t,NULL,:n,:d,'Unit',:cost,'fixed',0,:price,:tax,'active',:u,NOW(),NOW(),NULL)");
                        $insertProduct->execute(array(':t'=>$tenant, ':n'=>$newName, ':d'=>$item['description'] !== '' ? $item['description'] : null, ':cost'=>$item['unit_cost'], ':price'=>$item['unit_price'], ':tax'=>$item['tax_percent'], ':u'=>$user));
                        $normalized[$k]['product_id'] = (int)$pdo->lastInsertId();
                    }
                }
            }

            $lineColumns = array('invoice_id','product_service_id');
            $lineValues = array(':i',':ps');
            if (aifColumn($pdo, 'invoice_line_items', 'product_id')) { $lineColumns[]='product_id'; $lineValues[]=':pid'; }
            if (aifColumn($pdo, 'invoice_line_items', 'item_source')) { $lineColumns[]='item_source'; $lineValues[]=':src'; }
            $lineColumns = array_merge($lineColumns, array('item_name','description','quantity','unit_cost','unit_price','discount_amount','tax_percent','tax_amount','line_total','sort_order'));
            $lineValues = array_merge($lineValues, array(':n',':d',':qty',':cost',':price',':disc',':tp',':ta',':lt',':sort'));
            $lineStmt = $pdo->prepare("INSERT INTO invoice_line_items(" . implode(',', $lineColumns) . ") VALUES(" . implode(',', $lineValues) . ")");
            foreach ($normalized as $item) {
                $params = array(':i'=>$invoiceId, ':ps'=>$item['product_service_id'], ':n'=>$item['item_name'], ':d'=>$item['description'] !== '' ? $item['description'] : null, ':qty'=>$item['quantity'], ':cost'=>$item['unit_cost'], ':price'=>$item['unit_price'], ':disc'=>$item['discount_amount'], ':tp'=>$item['tax_percent'], ':ta'=>$item['tax_amount'], ':lt'=>$item['line_total'], ':sort'=>$item['sort_order']);
                if (aifColumn($pdo, 'invoice_line_items', 'product_id')) $params[':pid'] = !empty($item['product_id']) ? (int)$item['product_id'] : null;
                if (aifColumn($pdo, 'invoice_line_items', 'item_source')) $params[':src'] = $item['item_source'];
                $lineStmt->execute($params);
            }

            if ($customFields) {
                $customStmt = $pdo->prepare("INSERT INTO invoice_custom_fields(tenant_id,invoice_id,field_label,field_value,sort_order,created_by,created_at) VALUES(:t,:i,:l,:v,:s,:u,NOW())");
                foreach ($customFields as $customIndex => $custom) {
                    $customStmt->execute(array(':t'=>$tenant, ':i'=>$invoiceId, ':l'=>$custom['label'], ':v'=>$custom['value']!==''?$custom['value']:null, ':s'=>$customIndex, ':u'=>$user));
                }
            }

            $paymentIds = array();

            if ($jobId > 0 && in_array($job['status'], array('completed','ready_to_invoice','needs_review'), true)) {
                $pdo->prepare("UPDATE jobs SET status='invoiced' WHERE id=:j AND tenant_id=:t")->execute(array(':j' => $jobId, ':t' => $tenant));
            }

            $pdo->commit();

            $uploadSummary = aifStoreInvoiceUploads($pdo, $tenant, $user, $invoiceId, $jobId, $visitId);
            $emailSummary = aifEmailInvoice($pdo, $tenant, $branchId, $client, $invoiceId, $invoiceNo, $issueDate, $dueDate, $subject, $clientMessage, $normalized, $grandTotal, $currency);
            if ((int)$emailSummary['sent'] === 1) $invoiceStatus = 'sent';

            aifLog($pdo, $tenant, $branchId, $user, $clientId, 'invoice_created', $invoiceId, 'Invoice created: ' . $invoiceNo, array('invoice_no' => $invoiceNo, 'source' => $sourceMode, 'job_id' => $jobId > 0 ? $jobId : null, 'visit_id' => $visitId > 0 ? $visitId : null, 'total' => $grandTotal, 'amount_paid' => $receivedTotal, 'balance_due' => $balanceDue, 'email_sent' => (int)$emailSummary['sent'], 'files_saved' => (int)$uploadSummary['saved']));
            if (function_exists('tenantAuditLog')) {
                try {
                    tenantAuditLog($pdo, 'INVOICE_CREATED', $tenant, $branchId, $user, 'invoice', $invoiceId, null, array('invoice_no' => $invoiceNo, 'job_id' => $jobId > 0 ? $jobId : null, 'client_id' => $clientId, 'total' => $grandTotal, 'amount_paid' => $receivedTotal, 'balance_due' => $balanceDue, 'email_sent' => (int)$emailSummary['sent'], 'files_saved' => (int)$uploadSummary['saved']));
                } catch (Throwable $auditError) {
                    error_log('invoice form audit ' . $auditError->getMessage());
                }
            }

            $uploadMessage = '';
            if ($uploadSummary['messages']) $uploadMessage = implode(' ', array_unique($uploadSummary['messages']));
            elseif ((int)$uploadSummary['saved'] > 0) $uploadMessage = (int)$uploadSummary['saved'] . ' file(s) uploaded.';
            $message = 'Invoice ' . $invoiceNo . ' created successfully.';
            if ((int)$emailSummary['sent'] === 1) $message .= ' Customer email sent.';

            aifRes(200, true, $message, array(
                'invoice_id' => $invoiceId,
                'invoice_no' => $invoiceNo,
                'status' => $invoiceStatus,
                'total' => $grandTotal,
                'amount_paid' => $receivedTotal,
                'balance_due' => $grandTotal,
                'payment_count' => 0,
                'credit_total' => $grandTotal,
                'custom_field_count' => count($customFields),
                'uploaded_file_count' => (int)$uploadSummary['saved'],
                'upload_skipped_count' => (int)$uploadSummary['skipped'],
                'upload_message' => $uploadMessage,
                'email_sent' => (int)$emailSummary['sent'],
                'email_status' => $emailSummary['status'],
                'email_message' => $emailSummary['message']
            ));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    aifRes(400, false, 'Unsupported invoice form action.', array());
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx invoice form PDO ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        if (strpos($e->getMessage(), 'uq_invoice_tenant_job') !== false) {
            aifRes(409, false, 'This database still has the old one-invoice-per-job unique index. Run migration_invoice_job_recurring_support_v1.sql once to allow recurring job invoices.', array());
        }
        aifRes(409, false, 'A duplicate invoice or payment number already exists. Refresh and try again.', array());
    }
    aifRes(500, false, 'Unable to create the invoice.', array());
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx invoice form ' . $e->getMessage());
    aifRes(500, false, $e->getMessage() !== '' ? $e->getMessage() : 'Unable to create the invoice.', array());
}
