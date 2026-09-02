<?php
/* FieldPlx Invoice Form API - Version 1.0.0 - 2026-09-02
 * Supports Job-based invoices, Direct invoices, recurring job billing slots,
 * Select2 form metadata and split payments (Cash / Card / Online / Credit).
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
        'api_version' => '1.0.0'
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
    $stmt = $pdo->prepare("SELECT c.id,c.tenant_id,c.branch_id,c.display_name,c.company_name,c.email,c.phone,c.tax_number,c.status,b.name branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.id=:id AND c.tenant_id=:t AND c.deleted_at IS NULL AND c.client_type<>'archived' AND c.status<>'archived' LIMIT 1");
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
    $totalInvoices = max(1, (int)$job['total_invoices']);
    $billingType = !empty($job['billing_type']) ? (string)$job['billing_type'] : 'visit_based';
    $fixed = isset($job['fixed_invoice_amount']) ? (float)$job['fixed_invoice_amount'] : 0.0;

    if ($billingType === 'fixed_price' && $fixed > 0.001) {
        $name = trim((string)$job['service_name']);
        if ($name === '') $name = trim((string)$job['title']);
        if ($name === '') $name = 'Job service';
        $items[] = array('product_service_id' => !empty($job['product_service_id']) ? (int)$job['product_service_id'] : null, 'item_name' => $name . ' - Fixed invoice', 'description' => 'Scheduled fixed-price billing for ' . $job['job_no'], 'quantity' => 1, 'unit_cost' => 0, 'unit_price' => $fixed, 'discount_amount' => 0, 'tax_percent' => 0, 'tax_amount' => 0, 'line_total' => $fixed, 'sort_order' => 0);
        return $items;
    }

    if (!empty($job['quote_id']) && $totalInvoices <= 1) {
        $stmt = $pdo->prepare("SELECT product_service_id,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,sort_order FROM quote_line_items WHERE quote_id=:q ORDER BY sort_order,id");
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
        if ($taxPercent > 0) $unitPrice = round($grossShare / (1 + ($taxPercent / 100)), 2);
        else $unitPrice = $grossShare;
    } elseif ($unitPrice <= 0.001 && (float)$job['total'] > 0.001) {
        if ($taxPercent > 0) $unitPrice = round((float)$job['total'] / (1 + ($taxPercent / 100)), 2);
        else $unitPrice = (float)$job['total'];
    }
    $base = $unitPrice;
    $tax = round($base * $taxPercent / 100, 2);
    $items[] = array(
        'product_service_id' => !empty($job['product_service_id']) ? (int)$job['product_service_id'] : null,
        'item_name' => $name . ($totalInvoices > 1 ? ' - Billing visit' : ''),
        'description' => $visitId > 0 ? ('Visit billing for ' . $job['job_no']) : ('Job billing for ' . $job['job_no']),
        'quantity' => 1,
        'unit_cost' => $unitCost,
        'unit_price' => $unitPrice,
        'discount_amount' => 0,
        'tax_percent' => $taxPercent,
        'tax_amount' => $tax,
        'line_total' => round($base + $tax, 2),
        'sort_order' => 0
    );
    return $items;
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
        $clientsStmt = $pdo->prepare("SELECT c.id,c.branch_id,c.display_name name,c.company_name,c.email,c.phone,c.tax_number,b.name branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.tenant_id=:t AND c.deleted_at IS NULL AND c.client_type<>'archived' AND c.status<>'archived' ORDER BY c.display_name,c.id");
        $clientsStmt->execute(array(':t' => $tenant));

        $locationsStmt = $pdo->prepare("SELECT id,client_id,name,address_line1,address_line2,city,state,postal_code,is_primary FROM client_locations WHERE tenant_id=:t AND deleted_at IS NULL AND status='active' ORDER BY is_primary DESC,name,id");
        $locationsStmt->execute(array(':t' => $tenant));

        $branchesStmt = $pdo->prepare("SELECT id,branch_code,name,currency_id FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name,id");
        $branchesStmt->execute(array(':t' => $tenant));

        $catalogStmt = $pdo->prepare("SELECT id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent FROM product_services WHERE tenant_id=:t AND deleted_at IS NULL AND status='active' ORDER BY FIELD(item_type,'service','product','material','fee','discount'),name,id");
        $catalogStmt->execute(array(':t' => $tenant));

        $billingJoin = aifTable($pdo, 'job_billing_settings') ? " LEFT JOIN job_billing_settings jbs ON jbs.job_id=j.id AND jbs.tenant_id=j.tenant_id " : "";
        $billingSelect = aifTable($pdo, 'job_billing_settings') ? ",COALESCE(jbs.billing_type,'visit_based') billing_type,COALESCE(jbs.total_invoices,1) total_invoices,jbs.fixed_invoice_amount" : ",'visit_based' billing_type,1 total_invoices,NULL fixed_invoice_amount";
        $jobsSql = "SELECT j.id,j.job_no,j.title,j.status,j.job_type,j.client_id,j.location_id,j.branch_id,j.quote_id,j.product_service_id,j.total,c.display_name client_name,cl.name location_name,b.name branch_name,q.quote_no,ps.name service_name" . $billingSelect . " FROM jobs j INNER JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id LEFT JOIN client_locations cl ON cl.id=j.location_id AND cl.tenant_id=j.tenant_id LEFT JOIN branches b ON b.id=j.branch_id AND b.tenant_id=j.tenant_id LEFT JOIN quotes q ON q.id=j.quote_id AND q.tenant_id=j.tenant_id LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id " . $billingJoin . " WHERE j.tenant_id=:t AND j.deleted_at IS NULL AND j.status NOT IN('cancelled','archived','closed') ORDER BY j.id DESC";
        $jobsStmt = $pdo->prepare($jobsSql);
        $jobsStmt->execute(array(':t' => $tenant));

        aifRes(200, true, 'Invoice form data loaded.', array('meta' => array(
            'clients' => $clientsStmt->fetchAll(PDO::FETCH_ASSOC),
            'locations' => $locationsStmt->fetchAll(PDO::FETCH_ASSOC),
            'branches' => $branchesStmt->fetchAll(PDO::FETCH_ASSOC),
            'catalog' => $catalogStmt->fetchAll(PDO::FETCH_ASSOC),
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
        if (!aifTable($pdo, 'invoices') || !aifTable($pdo, 'invoice_line_items') || !aifTable($pdo, 'payments')) {
            aifRes(500, false, 'Invoice or payment tables are not installed.', array());
        }

        $sourceMode = trim((string)aifP('source_mode', 'direct'));
        if (!in_array($sourceMode, array('job','direct'), true)) aifRes(422, false, 'Select a valid invoice source.', array());

        $issueDate = trim((string)aifP('issue_date', ''));
        $dueDate = trim((string)aifP('due_date', ''));
        $requestedStatus = trim((string)aifP('invoice_status', 'draft'));
        if (!in_array($requestedStatus, array('draft','sent'), true)) $requestedStatus = 'draft';
        $paymentTerms = trim((string)aifP('payment_terms', ''));
        $notes = trim((string)aifP('notes', ''));
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

        $items = json_decode((string)aifP('items_json', '[]'), true);
        if (!is_array($items) || !count($items)) aifRes(422, false, 'Add at least one invoice item.', array());
        $normalized = array();
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $grandTotal = 0.0;
        foreach ($items as $index => $item) {
            $psid = isset($item['product_service_id']) ? (int)$item['product_service_id'] : 0;
            if ($psid > 0) {
                $check = $pdo->prepare("SELECT id FROM product_services WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL AND status='active' LIMIT 1");
                $check->execute(array(':id' => $psid, ':t' => $tenant));
                if (!$check->fetchColumn()) aifRes(422, false, 'One selected invoice item is no longer available.', array());
            } else $psid = null;
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

        $paymentRows = json_decode((string)aifP('payments_json', '[]'), true);
        if (!is_array($paymentRows) || !count($paymentRows)) aifRes(422, false, 'Allocate the invoice total using Cash, Card, Online or Credit.', array());
        $payments = array();
        $receivedTotal = 0.0;
        $creditTotal = 0.0;
        $allocatedTotal = 0.0;
        foreach ($paymentRows as $idx => $payment) {
            $method = strtolower(trim((string)(isset($payment['method']) ? $payment['method'] : '')));
            if (!in_array($method, array('cash','card','online','credit'), true)) aifRes(422, false, 'Invalid split payment method.', array());
            $amount = round(max(0, (float)(isset($payment['amount']) ? $payment['amount'] : 0)), 2);
            if ($amount <= 0) continue;
            $allocatedTotal += $amount;
            if ($method === 'credit') {
                $creditTotal += $amount;
                continue;
            }
            $receivedTotal += $amount;
            $payments[] = array(
                'method' => $method,
                'amount' => $amount,
                'provider' => trim((string)(isset($payment['provider']) ? $payment['provider'] : '')),
                'reference' => trim((string)(isset($payment['reference']) ? $payment['reference'] : '')),
                'notes' => trim((string)(isset($payment['notes']) ? $payment['notes'] : ''))
            );
        }
        $allocatedTotal = round($allocatedTotal, 2);
        $receivedTotal = round($receivedTotal, 2);
        $creditTotal = round($creditTotal, 2);
        if (abs($allocatedTotal - $grandTotal) > 0.01) aifRes(422, false, 'Split payments must equal the invoice total. Current allocation: ' . number_format($allocatedTotal, 2) . '.', array('invoice_total' => $grandTotal, 'allocated_total' => $allocatedTotal));
        if ($receivedTotal > $grandTotal + 0.01) aifRes(422, false, 'Received payment cannot exceed the invoice total.', array());
        if ($creditTotal > 0.001) {
            if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) aifRes(422, false, 'Due date is required when Credit is used.', array());
            if ($dueDate < $issueDate) aifRes(422, false, 'Credit due date cannot be before the invoice issue date.', array());
            if ($paymentTerms === '') $paymentTerms = 'Credit due ' . $dueDate;
        } elseif ($dueDate === '') {
            $dueDate = $issueDate;
            if ($paymentTerms === '') $paymentTerms = 'Due on receipt';
        }

        $balanceDue = round(max(0, $grandTotal - $receivedTotal), 2);
        if ($receivedTotal >= $grandTotal - 0.01) {
            $invoiceStatus = 'paid';
            $balanceDue = 0.0;
        } elseif ($receivedTotal > 0.001) {
            $invoiceStatus = 'partially_paid';
        } else {
            $invoiceStatus = $requestedStatus;
        }

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
            $invoiceStmt = $pdo->prepare("INSERT INTO invoices(tenant_id,branch_id,invoice_no,client_id,location_id,job_id,visit_id,quote_id,status,issue_date,due_date,subtotal,discount_total,tax_total,total,amount_paid,balance_due,payment_terms,notes,sent_at,paid_at,created_by) VALUES(:t,:b,:no,:c,:l,:j,:v,:q,:st,:issue,:due,:sub,:disc,:tax,:tot,:paid,:bal,:terms,:notes,:sent,:paidat,:u)");
            $invoiceStmt->execute(array(
                ':t' => $tenant,
                ':b' => $branchId > 0 ? $branchId : null,
                ':no' => $invoiceNo,
                ':c' => $clientId,
                ':l' => $locationId > 0 ? $locationId : null,
                ':j' => $jobId > 0 ? $jobId : null,
                ':v' => $visitId > 0 ? $visitId : null,
                ':q' => $quoteId > 0 ? $quoteId : null,
                ':st' => $invoiceStatus,
                ':issue' => $issueDate,
                ':due' => $dueDate !== '' ? $dueDate : null,
                ':sub' => $subtotal,
                ':disc' => $discountTotal,
                ':tax' => $taxTotal,
                ':tot' => $grandTotal,
                ':paid' => $receivedTotal,
                ':bal' => $balanceDue,
                ':terms' => $paymentTerms !== '' ? $paymentTerms : null,
                ':notes' => $notes !== '' ? $notes : null,
                ':sent' => $requestedStatus === 'sent' ? date('Y-m-d H:i:s') : null,
                ':paidat' => $invoiceStatus === 'paid' ? date('Y-m-d H:i:s') : null,
                ':u' => $user
            ));
            $invoiceId = (int)$pdo->lastInsertId();

            $lineStmt = $pdo->prepare("INSERT INTO invoice_line_items(invoice_id,product_service_id,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,sort_order) VALUES(:i,:ps,:n,:d,:qty,:cost,:price,:disc,:tp,:ta,:lt,:sort)");
            foreach ($normalized as $item) {
                $lineStmt->execute(array(':i' => $invoiceId, ':ps' => $item['product_service_id'], ':n' => $item['item_name'], ':d' => $item['description'] !== '' ? $item['description'] : null, ':qty' => $item['quantity'], ':cost' => $item['unit_cost'], ':price' => $item['unit_price'], ':disc' => $item['discount_amount'], ':tp' => $item['tax_percent'], ':ta' => $item['tax_amount'], ':lt' => $item['line_total'], ':sort' => $item['sort_order']));
            }

            $paymentIds = array();
            foreach ($payments as $payment) {
                $paymentNo = aifNextNo($pdo, $tenant, $branchId, 'payment', 'payments', 'payment_no', 'PAY-');
                $dbMethod = $payment['method'] === 'online' ? 'other' : $payment['method'];
                $channel = $payment['method'] === 'online' ? 'online' : 'office';
                $provider = $payment['provider'];
                if ($provider === '') {
                    if ($payment['method'] === 'cash') $provider = 'manual_collection';
                    elseif ($payment['method'] === 'card') $provider = 'card_terminal';
                    else $provider = 'online_payment';
                }
                $paymentStmt = $pdo->prepare("INSERT INTO payments(tenant_id,branch_id,payment_no,client_id,invoice_id,quote_id,payment_method,payment_channel,status,amount,currency_id,provider,provider_payment_id,transaction_fee,received_at,notes,created_by) VALUES(:t,:b,:no,:c,:i,:q,:m,:ch,'succeeded',:amt,:cur,:provider,:ref,0,NOW(),:notes,:u)");
                $paymentStmt->execute(array(':t' => $tenant, ':b' => $branchId > 0 ? $branchId : null, ':no' => $paymentNo, ':c' => $clientId, ':i' => $invoiceId, ':q' => $quoteId > 0 ? $quoteId : null, ':m' => $dbMethod, ':ch' => $channel, ':amt' => $payment['amount'], ':cur' => $currencyId, ':provider' => $provider, ':ref' => $payment['reference'] !== '' ? $payment['reference'] : null, ':notes' => $payment['notes'] !== '' ? $payment['notes'] : ('Initial invoice payment - ' . ucfirst($payment['method'])), ':u' => $user));
                $paymentId = (int)$pdo->lastInsertId();
                $paymentIds[] = $paymentId;
            }

            if ($jobId > 0 && in_array($job['status'], array('completed','ready_to_invoice','needs_review'), true)) {
                $pdo->prepare("UPDATE jobs SET status='invoiced' WHERE id=:j AND tenant_id=:t")->execute(array(':j' => $jobId, ':t' => $tenant));
            }

            $pdo->commit();

            aifLog($pdo, $tenant, $branchId, $user, $clientId, 'invoice_created', $invoiceId, 'Invoice created: ' . $invoiceNo, array('invoice_no' => $invoiceNo, 'source' => $sourceMode, 'job_id' => $jobId > 0 ? $jobId : null, 'visit_id' => $visitId > 0 ? $visitId : null, 'total' => $grandTotal, 'amount_paid' => $receivedTotal, 'balance_due' => $balanceDue));
            foreach ($paymentIds as $paymentId) {
                aifLog($pdo, $tenant, $branchId, $user, $clientId, 'payment_received', $paymentId, 'Initial payment received for ' . $invoiceNo, array('invoice_id' => $invoiceId, 'invoice_no' => $invoiceNo));
            }
            if (function_exists('tenantAuditLog')) {
                try {
                    tenantAuditLog($pdo, 'INVOICE_CREATED', $tenant, $branchId, $user, 'invoice', $invoiceId, null, array('invoice_no' => $invoiceNo, 'job_id' => $jobId > 0 ? $jobId : null, 'client_id' => $clientId, 'total' => $grandTotal, 'amount_paid' => $receivedTotal, 'balance_due' => $balanceDue));
                } catch (Throwable $auditError) {
                    error_log('invoice form audit ' . $auditError->getMessage());
                }
            }

            aifRes(200, true, 'Invoice ' . $invoiceNo . ' created successfully.', array(
                'invoice_id' => $invoiceId,
                'invoice_no' => $invoiceNo,
                'status' => $invoiceStatus,
                'total' => $grandTotal,
                'amount_paid' => $receivedTotal,
                'balance_due' => $balanceDue,
                'payment_count' => count($paymentIds)
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
