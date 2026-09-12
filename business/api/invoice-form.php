<?php
/* FieldPlx Invoice Form API - Version 2.5.0 - 2026-09-08
 * Supports Job-based invoices, Direct invoices, recurring job billing slots,
 * Service/Product/Manual items, custom label/value fields, invoice Images/Attachments,
 * and automatic client email after successful invoice creation.
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
require_once __DIR__ . '/../includes/platform-smtp.php';

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
        'api_version' => '2.5.0'
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

function aifTaxRates(PDO $pdo, $tenant)
{
    if (!aifTable($pdo, 'product_tax_rates')) return array();
    $defaultSelect = aifColumn($pdo, 'product_tax_rates', 'is_default') ? ',is_default' : ',0 AS is_default';
    $stmt = $pdo->prepare("SELECT id,tax_name,rate_percent,jurisdiction_name,status" . $defaultSelect . " FROM product_tax_rates WHERE tenant_id=:t AND status='active' ORDER BY " . (aifColumn($pdo, 'product_tax_rates', 'is_default') ? 'is_default DESC,' : '') . " tax_name,id");
    $stmt->execute(array(':t' => $tenant));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function aifTaxRate(PDO $pdo, $tenant, $id)
{
    if ($id <= 0 || !aifTable($pdo, 'product_tax_rates')) return null;
    $defaultSelect = aifColumn($pdo, 'product_tax_rates', 'is_default') ? ',is_default' : ',0 AS is_default';
    $stmt = $pdo->prepare("SELECT id,tax_name,rate_percent,jurisdiction_name,status" . $defaultSelect . " FROM product_tax_rates WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
    $stmt->execute(array(':id'=>$id, ':t'=>$tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
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

function aifUserAccess(PDO $pdo, $tenant, $id)
{
    if ($id <= 0 || !aifTable($pdo, 'users')) return null;
    $deletedClause = aifColumn($pdo, 'users', 'deleted_at') ? " AND u.deleted_at IS NULL" : "";
    $roleJoin = aifTable($pdo, 'roles') ? " LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id " : "";
    $roleSelect = aifTable($pdo, 'roles') ? ",COALESCE(r.name,'') role_name,COALESCE(r.is_admin,0) role_is_admin" : ",' ' role_name,0 role_is_admin";
    $stmt = $pdo->prepare("SELECT u.id,u.first_name,u.last_name,u.email,u.job_title,u.is_tenant_admin" . $roleSelect . " FROM users u" . $roleJoin . " WHERE u.id=:id AND u.tenant_id=:t AND u.status='active'" . $deletedClause . " LIMIT 1");
    $stmt->execute(array(':id'=>$id, ':t'=>$tenant));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['name'] = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    $row['can_edit_salesperson'] = (!empty($row['is_tenant_admin']) || !empty($row['role_is_admin'])) ? 1 : 0;
    return $row;
}

function aifFormatAddress($row)
{
    if (!is_array($row)) return '';
    $parts = array();
    foreach (array('address_line1','address_line2','city','state','postal_code') as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') $parts[] = trim((string)$row[$key]);
    }
    return implode(', ', $parts);
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

function aifPreviewNo(PDO $pdo, $tenant, $branchId, $type, $table, $column, $fallbackPrefix)
{
    if (!aifTable($pdo, 'document_sequences')) {
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0)+1 FROM `" . $table . "` WHERE tenant_id=:t");
        $stmt->execute(array(':t' => $tenant));
        $n = max(1, (int)$stmt->fetchColumn());
        for ($i = 0; $i < 1000; $i++) {
            $no = $fallbackPrefix . str_pad((string)($n + $i), 6, '0', STR_PAD_LEFT);
            $q = $pdo->prepare("SELECT id FROM `" . $table . "` WHERE tenant_id=:t AND `" . $column . "`=:n LIMIT 1");
            $q->execute(array(':t' => $tenant, ':n' => $no));
            if (!$q->fetchColumn()) return $no;
        }
        return $fallbackPrefix . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
    }

    if ($branchId > 0) {
        $stmt = $pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type=:dt AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1");
        $stmt->execute(array(':t' => $tenant, ':dt' => $type, ':b' => $branchId, ':b2' => $branchId));
    } else {
        $stmt = $pdo->prepare("SELECT ds.*,NULL branch_code FROM document_sequences ds WHERE ds.tenant_id=:t AND ds.document_type=:dt AND ds.is_active=1 AND ds.branch_id IS NULL ORDER BY ds.id LIMIT 1");
        $stmt->execute(array(':t' => $tenant, ':dt' => $type));
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0)+1 FROM `" . $table . "` WHERE tenant_id=:t");
        $stmt->execute(array(':t' => $tenant));
        return $fallbackPrefix . str_pad((string)max(1, (int)$stmt->fetchColumn()), 6, '0', STR_PAD_LEFT);
    }

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
    return implode($separator, $parts);
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

function aifSmtpConfig(PDO $pdo)
{
    /*
     * Global-only SMTP policy:
     * - never read tenant SMTP
     * - never read branch SMTP
     * - always use the active DEFAULT platform SMTP
     */
    return fieldplxPlatformSmtpConfig($pdo);
}

function aifNotificationEventId(PDO $pdo)
{
    if (!aifTable($pdo, 'notification_events')) return 0;
    $stmt = $pdo->prepare("SELECT id FROM notification_events WHERE event_key='invoice.sent' AND is_active=1 ORDER BY id LIMIT 1");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function aifQueueLogCreate(PDO $pdo, $tenant, $branch, $clientId, $email, $invoiceId, $subject, $body, $smtpId, $status, $error)
{
    if (!aifTable($pdo, 'notification_queue')) return 0;

    $eventId = aifNotificationEventId($pdo);
    if ($eventId <= 0) {
        error_log('invoice notification queue log skipped: invoice.sent event is not available.');
        return 0;
    }

    $allowed = array('queued','processing','sent','delivered','read','failed','suppressed');
    if (!in_array($status, $allowed, true)) $status = 'processing';
    $attempts = in_array($status, array('processing','sent','failed'), true) ? 1 : 0;

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO notification_queue(" .
            "tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address," .
            "related_type,related_id,subject,body,smtp_config_id,status,attempts,scheduled_at,sent_at,error_message,created_at" .
            ") VALUES(" .
            ":t,:b,:e,'email','client',:c,:addr,'invoice',:rid,:sub,:body,:smtp,:st,:attempts,NOW(),:sent,:err,NOW()" .
            ")"
        );
        $stmt->execute(array(
            ':t' => $tenant,
            ':b' => $branch > 0 ? $branch : null,
            ':e' => $eventId,
            ':c' => $clientId > 0 ? $clientId : null,
            ':addr' => $email !== '' ? $email : null,
            ':rid' => $invoiceId,
            ':sub' => $subject !== '' ? substr($subject, 0, 255) : null,
            ':body' => (string)$body,
            ':smtp' => $smtpId > 0 ? $smtpId : null,
            ':st' => $status,
            ':attempts' => $attempts,
            ':sent' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ':err' => $error !== '' ? $error : null
        ));
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('invoice notification queue insert ' . $e->getMessage());
        return 0;
    }
}

function aifQueueLogFinish(PDO $pdo, $logId, $status, $error)
{
    if ($logId <= 0 || !aifTable($pdo, 'notification_queue')) return false;

    if (!in_array($status, array('sent','failed','suppressed'), true)) {
        $status = 'failed';
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE notification_queue SET " .
            "status=:st," .
            "sent_at=CASE WHEN :sent_status='sent' THEN NOW() ELSE NULL END," .
            "error_message=:err " .
            "WHERE id=:id LIMIT 1"
        );
        $stmt->execute(array(
            ':st' => $status,
            ':sent_status' => $status,
            ':err' => $error !== '' ? $error : null,
            ':id' => $logId
        ));
        return true;
    } catch (Throwable $e) {
        error_log('invoice notification queue update ' . $e->getMessage());
        return false;
    }
}

function aifGenerateInvoicePdfForEmail($invoiceId)
{
    $invoiceId = (int)$invoiceId;
    if ($invoiceId <= 0) {
        throw new RuntimeException('Invalid invoice selected for PDF attachment.');
    }

    $printFile = dirname(__DIR__) . '/invoice-print.php';
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
        while (ob_get_level() > $bufferLevel) {
            @ob_end_clean();
        }
        if ($oldInvoiceIdExists) $_GET['invoice_id'] = $oldInvoiceId; else unset($_GET['invoice_id']);
        if ($oldIdExists) $_GET['id'] = $oldId; else unset($_GET['id']);
        throw new RuntimeException('Unable to generate the invoice PDF attachment: ' . $e->getMessage(), 0, $e);
    }

    while (ob_get_level() > $bufferLevel) {
        @ob_end_clean();
    }

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

function aifEmailInvoice(PDO $pdo, $tenant, $branch, $client, $invoiceId, $invoiceNo, $issueDate, $dueDate, $subject, $clientMessage, $items, $total, $currency, $clientViewOptions)
{
    $out = array(
        'sent' => 0,
        'status' => 'suppressed',
        'message' => '',
        'smtp_id' => 0,
        'smtp_scope' => 'platform',
        'smtp_is_default' => 0,
        'recipient' => '',
        'mail_log_id' => 0,
        'pdf_attached' => 0,
        'pdf_name' => '',
        'pdf_size' => 0,
        'pdf_sha256' => ''
    );

    $clientId = isset($client['id']) ? (int)$client['id'] : 0;
    $email = trim((string)(isset($client['email']) ? $client['email'] : ''));
    $out['recipient'] = $email;

    $tenantInfo = aifTenantInfo($pdo, $tenant);
    $businessName = trim((string)$tenantInfo['display_name']);
    if ($businessName === '') $businessName = 'FieldPlx';
    $mailSubject = 'Invoice ' . $invoiceNo . ' from ' . $businessName;

    /*
     * Invoice delivery is transactional. If the selected customer has a valid
     * email address, send the invoice even when an older customer row has
     * allow_email=0/NULL. That preference is retained for optional/marketing
     * communication; it must not suppress the invoice the user just created.
     */

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reason = 'Client email was not sent because the client does not have a valid email address.';
        $out['message'] = $reason;
        $out['mail_log_id'] = aifQueueLogCreate(
            $pdo, $tenant, $branch, $clientId, $email, $invoiceId,
            $mailSubject, '', 0, 'suppressed', $reason
        );
        return $out;
    }

    $cfg = aifSmtpConfig($pdo);
    if (!$cfg) {
        $reason = 'Client email was not sent because no active default platform SMTP configuration was found. Configure one platform SMTP as Active + Default in Master Controls.';
        $out['message'] = $reason;
        $out['mail_log_id'] = aifQueueLogCreate(
            $pdo, $tenant, $branch, $clientId, $email, $invoiceId,
            $mailSubject, '', 0, 'suppressed', $reason
        );
        return $out;
    }

    $out['smtp_id'] = (int)$cfg['id'];
    $out['smtp_is_default'] = !empty($cfg['is_default']) ? 1 : 0;

    if (
        (isset($cfg['scope_type']) && (string)$cfg['scope_type'] !== 'platform') ||
        !empty($cfg['tenant_id']) ||
        !empty($cfg['branch_id']) ||
        empty($cfg['is_default'])
    ) {
        $reason = 'Client email was not sent because the selected SMTP configuration is not the global default platform SMTP.';
        $out['message'] = $reason;
        $out['mail_log_id'] = aifQueueLogCreate(
            $pdo, $tenant, $branch, $clientId, $email, $invoiceId,
            $mailSubject, '', 0, 'suppressed', $reason
        );
        return $out;
    }

    $viewDefaults = array(
        'quantities' => true,
        'unit_prices' => true,
        'line_item_totals' => true,
        'account_balance' => true,
        'late_stamp' => true
    );
    if (!is_array($clientViewOptions)) $clientViewOptions = array();
    $view = array();
    foreach ($viewDefaults as $key => $defaultValue) {
        $view[$key] = array_key_exists($key, $clientViewOptions) ? (bool)$clientViewOptions[$key] : $defaultValue;
    }

    $customerName = trim((string)(isset($client['display_name']) ? $client['display_name'] : 'Client'));
    if ($customerName === '') $customerName = 'Client';
    $symbol = isset($currency['symbol']) ? (string)$currency['symbol'] : '';
    $decimals = isset($currency['decimal_places']) ? (int)$currency['decimal_places'] : 2;

    $formatMoney = function ($value) use ($symbol, $decimals, $currency) {
        $amount = number_format((float)$value, $decimals, '.', ',');
        return isset($currency['symbol_position']) && $currency['symbol_position'] === 'after'
            ? $amount . ($symbol !== '' ? ' ' . $symbol : '')
            : $symbol . $amount;
    };

    $amountText = $formatMoney($total);
    $headerCells = '<th style="padding:9px 8px;text-align:left;border-bottom:2px solid #dfe6ea">Product / Service</th>';
    if ($view['quantities']) $headerCells .= '<th style="padding:9px 8px;text-align:center;border-bottom:2px solid #dfe6ea">Qty</th>';
    if ($view['unit_prices']) $headerCells .= '<th style="padding:9px 8px;text-align:right;border-bottom:2px solid #dfe6ea">Unit price</th>';
    if ($view['line_item_totals']) $headerCells .= '<th style="padding:9px 8px;text-align:right;border-bottom:2px solid #dfe6ea">Total</th>';

    $rows = '';
    foreach ($items as $item) {
        $rows .= '<tr>';
        $rows .= '<td style="padding:9px 8px;border-bottom:1px solid #e7ebee">' . htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') . '</td>';
        if ($view['quantities']) {
            $rows .= '<td style="padding:9px 8px;border-bottom:1px solid #e7ebee;text-align:center">' . htmlspecialchars((string)$item['quantity'], ENT_QUOTES, 'UTF-8') . '</td>';
        }
        if ($view['unit_prices']) {
            $rows .= '<td style="padding:9px 8px;border-bottom:1px solid #e7ebee;text-align:right">' . htmlspecialchars($formatMoney(isset($item['unit_price']) ? $item['unit_price'] : 0), ENT_QUOTES, 'UTF-8') . '</td>';
        }
        if ($view['line_item_totals']) {
            $rows .= '<td style="padding:9px 8px;border-bottom:1px solid #e7ebee;text-align:right">' . htmlspecialchars($formatMoney(isset($item['line_total']) ? $item['line_total'] : 0), ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $rows .= '</tr>';
    }

    $balanceLine = $view['account_balance']
        ? '<br><strong>Invoice balance:</strong> ' . htmlspecialchars($amountText, ENT_QUOTES, 'UTF-8')
        : '';

    $lateStamp = '';
    if ($view['late_stamp'] && $dueDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) && $dueDate < date('Y-m-d')) {
        $lateStamp = '<div style="margin:0 0 14px;padding:9px 12px;border:1px solid #efb3b3;border-radius:6px;background:#fff4f4;color:#a13232;font-weight:700;text-align:center">OVERDUE</div>';
    }

    $safeMessage = trim((string)$clientMessage) !== ''
        ? '<p style="margin:0 0 16px">' . nl2br(htmlspecialchars($clientMessage, ENT_QUOTES, 'UTF-8')) . '</p>'
        : '';

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:680px;margin:auto;color:#17334f;background:#fff">'
          . '<div style="padding:20px 22px;background:#001131;color:#fff"><div style="font-size:12px;opacity:.82">' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '</div><h2 style="margin:4px 0 0;font-size:22px">Invoice ' . htmlspecialchars($invoiceNo, ENT_QUOTES, 'UTF-8') . '</h2></div>'
          . '<div style="padding:22px;border:1px solid #e5eaf1;border-top:0">'
          . $lateStamp
          . '<p>Hello ' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ',</p>'
          . $safeMessage
          . '<p>Your invoice has been created successfully. Please find the billing summary below.</p>'
          . '<div style="margin:16px 0;padding:14px;background:#f7f9fb;border-radius:8px"><strong>Invoice:</strong> ' . htmlspecialchars($invoiceNo, ENT_QUOTES, 'UTF-8') . '<br><strong>Issue date:</strong> ' . htmlspecialchars($issueDate, ENT_QUOTES, 'UTF-8') . '<br><strong>Due date:</strong> ' . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . '<br><strong>Total:</strong> ' . htmlspecialchars($amountText, ENT_QUOTES, 'UTF-8') . $balanceLine . '</div>'
          . ($subject !== '' ? '<p><strong>Subject:</strong> ' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</p>' : '')
          . '<table style="width:100%;border-collapse:collapse;margin:18px 0"><thead><tr>' . $headerCells . '</tr></thead><tbody>' . $rows . '</tbody></table>'
          . '<p style="margin-top:20px">Thank you,<br>' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '</p>'
          . '</div></div>';

    /* Create the log BEFORE the SMTP attempt so every attempt is traceable. */
    $logId = aifQueueLogCreate(
        $pdo, $tenant, $branch, $clientId, $email, $invoiceId,
        $mailSubject, $html, (int)$cfg['id'], 'processing', ''
    );
    $out['mail_log_id'] = $logId;

    try {
        $password = fieldplxDecryptSmtpPassword(
            isset($cfg['password_encrypted']) ? $cfg['password_encrypted'] : ''
        );

        if (trim((string)$cfg['username']) !== '' && trim((string)$password) === '') {
            throw new RuntimeException(
                'SMTP password is empty or could not be decrypted. The invoice module now uses the same Platform SMTP encryption method and secret key source as Master Controls. If this remains, edit the default Platform SMTP, enter the password again, save, and test it once.'
            );
        }

        /* Generate the exact invoice PDF only after the invoice transaction has committed. */
        $invoicePdf = aifGenerateInvoicePdfForEmail($invoiceId);

        fieldplxSmtpSendWithConfig(
            $cfg,
            $password,
            $email,
            $mailSubject,
            $html,
            array(
                array(
                    'name' => $invoicePdf['name'],
                    'mime' => 'application/pdf',
                    'content' => $invoicePdf['bytes']
                )
            )
        );

        $out['pdf_attached'] = 1;
        $out['pdf_name'] = $invoicePdf['name'];
        $out['pdf_size'] = (int)$invoicePdf['size'];
        $out['pdf_sha256'] = $invoicePdf['sha256'];

        $pdo->prepare(
            "UPDATE invoices SET " .
            "status=CASE WHEN status='draft' THEN 'sent' ELSE status END," .
            "sent_at=COALESCE(sent_at,NOW()) " .
            "WHERE id=:i AND tenant_id=:t"
        )->execute(array(':i' => $invoiceId, ':t' => $tenant));

        $out['sent'] = 1;
        $out['status'] = 'sent';
        $out['message'] = 'Invoice email with PDF attachment sent to ' . $email . '.';
        aifQueueLogFinish($pdo, $logId, 'sent', '');
    } catch (Throwable $e) {
        $detail = $e->getMessage();
        $smtpContext = 'SMTP config #' . (int)$cfg['id']
            . ' [' . (string)$cfg['host'] . ':' . (int)$cfg['port']
            . ' / ' . (string)$cfg['encryption'] . ']';
        $logError = $smtpContext . ' - ' . $detail;

        $out['status'] = 'failed';
        $out['message'] = 'Invoice was created, but client email failed: ' . $detail;
        aifQueueLogFinish($pdo, $logId, 'failed', $logError);
        error_log('invoice client email ' . $logError);
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

function aifStoreCatalogImage($field, $tenant, $kind)
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return array('relative'=>null, 'absolute'=>null);
    }

    $file = $_FILES[$field];
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) return array('relative'=>null, 'absolute'=>null);
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Unable to upload the product / service image.');

    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > 4 * 1024 * 1024) throw new RuntimeException('Product / service image must be 4 MB or smaller.');

    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Uploaded product / service image is invalid.');

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)@finfo_file($fi, $tmp);
            @finfo_close($fi);
        }
    }

    $allowed = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    );

    if (!isset($allowed[$mime])) throw new RuntimeException('Product / service image must be JPG, PNG or WEBP.');

    $folderName = $kind === 'service' ? 'services' : 'products';
    $relativeDir = 'uploads/product-masters/tenant-' . (int)$tenant . '/' . $folderName;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;

    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Unable to create the product / service image upload folder.');
    }

    $prefix = $kind === 'service' ? 'service' : 'product';
    $fileName = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $absolutePath = $absoluteDir . '/' . $fileName;

    if (!@move_uploaded_file($tmp, $absolutePath)) {
        throw new RuntimeException('Unable to save the product / service image.');
    }

    return array(
        'relative' => $relativeDir . '/' . $fileName,
        'absolute' => $absolutePath
    );
}


function aifNoteUploadPresent()
{
    if (empty($_FILES['note_attachments']) || !isset($_FILES['note_attachments']['name'])) return false;
    $names = $_FILES['note_attachments']['name'];
    if (is_array($names)) {
        foreach ($names as $name) if (trim((string)$name) !== '') return true;
        return false;
    }
    return trim((string)$names) !== '';
}

function aifValidMentionUsers(PDO $pdo, $tenant, $ids)
{
    $clean = array();
    foreach ((array)$ids as $id) {
        $id = (int)$id;
        if ($id > 0) $clean[$id] = $id;
    }
    if (!$clean || !aifTable($pdo, 'users')) return array();
    $params = array(':t'=>$tenant);
    $marks = array();
    $i = 0;
    foreach ($clean as $id) {
        $key = ':u' . $i++;
        $marks[] = $key;
        $params[$key] = $id;
    }
    $sql = "SELECT id,first_name,last_name,email,job_title,is_tenant_admin FROM users WHERE tenant_id=:t AND status='active' AND id IN (" . implode(',', $marks) . ")";
    if (aifColumn($pdo, 'users', 'deleted_at')) $sql .= " AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['id']] = $row;
    return $out;
}

function aifStoreInternalNoteUploads(PDO $pdo, $tenant, $user, $invoiceId, $noteId)
{
    $result = array('saved'=>0, 'skipped'=>0, 'messages'=>array());
    if ($noteId <= 0) return $result;
    $files = aifUploadArray('note_attachments');
    if (!$files) return $result;
    if (!aifTable($pdo, 'attachments')) {
        $result['skipped'] = count($files);
        $result['messages'][] = 'Internal note files were not stored because the attachments table is unavailable.';
        return $result;
    }

    $relativeDir = 'private_uploads/invoice-notes/tenant-' . (int)$tenant . '/invoice-' . (int)$invoiceId . '/note-' . (int)$noteId;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
        $result['skipped'] = count($files);
        $result['messages'][] = 'Internal note upload folder is not writable.';
        return $result;
    }
    $privateRoot = dirname(__DIR__) . '/private_uploads';
    if (is_dir($privateRoot)) {
        $denyFile = $privateRoot . '/.htaccess';
        if (!is_file($denyFile)) @file_put_contents($denyFile, "Require all denied\nDeny from all\n");
        $indexFile = $privateRoot . '/index.php';
        if (!is_file($indexFile)) @file_put_contents($indexFile, "<?php http_response_code(403); exit;\n");
    }

    $allowed = array('avif','jpg','jpeg','png','webp','heic','pdf','doc','docx');
    $count = 0;
    foreach ($files as $f) {
        if ($count >= 10) { $result['skipped']++; continue; }
        $count++;
        if ((int)$f['error'] === UPLOAD_ERR_NO_FILE) continue;
        if ((int)$f['error'] !== UPLOAD_ERR_OK) { $result['skipped']++; $result['messages'][] = basename((string)$f['name']) . ' could not be uploaded.'; continue; }
        if ((int)$f['size'] <= 0 || (int)$f['size'] > 25*1024*1024) { $result['skipped']++; $result['messages'][] = basename((string)$f['name']) . ' exceeded the 25MB note attachment limit.'; continue; }
        $original = basename((string)$f['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) { $result['skipped']++; $result['messages'][] = $original . ' has an unsupported file type.'; continue; }
        $savedName = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $target = $absoluteDir . '/' . $savedName;
        if (!@move_uploaded_file((string)$f['tmp_name'], $target)) { $result['skipped']++; $result['messages'][] = $original . ' could not be stored.'; continue; }
        @chmod($target, 0640);
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mime = (string)@finfo_file($fi, $target); @finfo_close($fi); }
        }
        $relative = $relativeDir . '/' . $savedName;
        $isImage = ($mime !== '' && strpos($mime, 'image/') === 0) || in_array($ext, array('avif','jpg','jpeg','png','webp','heic'), true);
        $attachmentType = $isImage ? 'file' : 'document';
        try {
            $stmt = $pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,job_id,visit_id,workflow_step_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type,description) VALUES(:t,'invoice_internal_note',:rid,NULL,NULL,NULL,:u,:fn,:fp,:fm,:fs,:at,'Private invoice note attachment')");
            $stmt->execute(array(':t'=>$tenant, ':rid'=>$noteId, ':u'=>$user, ':fn'=>$original, ':fp'=>$relative, ':fm'=>$mime!==''?$mime:null, ':fs'=>(int)$f['size'], ':at'=>$attachmentType));
            $result['saved']++;
        } catch (Throwable $e) {
            @unlink($target);
            $result['skipped']++;
            $result['messages'][] = $original . ' could not be linked to the internal note.';
            error_log('invoice internal note attachment insert ' . $e->getMessage());
        }
    }
    return $result;
}

function aifNotifyInvoiceMentions(PDO $pdo, $tenant, $branch, $actorUser, $invoiceId, $invoiceNo, $mentionedUsers)
{
    if (!$mentionedUsers || !aifTable($pdo, 'notification_queue') || !aifTable($pdo, 'notification_events')) return;
    try {
        $eventStmt = $pdo->prepare("SELECT id FROM notification_events WHERE event_key='invoice.note_mentioned' AND is_active=1 LIMIT 1");
        $eventStmt->execute();
        $eventId = (int)$eventStmt->fetchColumn();
        if ($eventId <= 0) return;
        $actorName = 'A team member';
        if (aifTable($pdo, 'users')) {
            $s = $pdo->prepare("SELECT first_name,last_name FROM users WHERE id=:u AND tenant_id=:t LIMIT 1");
            $s->execute(array(':u'=>$actorUser, ':t'=>$tenant));
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $n = trim((string)$r['first_name'] . ' ' . (string)$r['last_name']);
                if ($n !== '') $actorName = $n;
            }
        }
        $ins = $pdo->prepare("INSERT INTO notification_queue(tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address,related_type,related_id,subject,body,smtp_config_id,status,attempts,scheduled_at,sent_at,error_message,created_at) VALUES(:t,:b,:e,'in_app','user',:u,NULL,'invoice',:rid,:sub,:body,NULL,'queued',0,NOW(),NULL,NULL,NOW())");
        foreach ($mentionedUsers as $uid => $row) {
            $uid = (int)$uid;
            if ($uid <= 0 || $uid === (int)$actorUser) continue;
            $ins->execute(array(':t'=>$tenant, ':b'=>$branch>0?$branch:null, ':e'=>$eventId, ':u'=>$uid, ':rid'=>$invoiceId, ':sub'=>'Mentioned in invoice note', ':body'=>$actorName . ' mentioned you in an internal note on invoice ' . $invoiceNo . '.'));
        }
    } catch (Throwable $e) {
        error_log('invoice note mention notification ' . $e->getMessage());
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
        $clientsStmt = $pdo->prepare("SELECT c.id,c.branch_id,c.display_name name,c.company_name,c.email,c.phone,c.tax_number,c.allow_email,b.name branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.tenant_id=:t AND c.deleted_at IS NULL AND c.client_type<>'archived' AND c.status<>'archived' ORDER BY c.display_name,c.id");
        $clientsStmt->execute(array(':t' => $tenant));

        $locationsStmt = $pdo->prepare("SELECT id,client_id,name,address_line1,address_line2,city,state,postal_code,is_primary FROM client_locations WHERE tenant_id=:t AND deleted_at IS NULL AND status='active' ORDER BY is_primary DESC,name,id");
        $locationsStmt->execute(array(':t' => $tenant));

        $branchesStmt = $pdo->prepare("SELECT id,branch_code,name,currency_id FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name,id");
        $branchesStmt->execute(array(':t' => $tenant));

        $serviceImageSelect = aifColumn($pdo, 'product_services', 'image_path') ? ',image_path' : ',NULL AS image_path';
        $servicesSql = "SELECT id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent" . $serviceImageSelect . " FROM product_services WHERE tenant_id=:t AND deleted_at IS NULL AND status='active'";
        if (aifColumn($pdo, 'product_services', 'item_type')) $servicesSql .= " AND item_type='service'";
        $servicesSql .= " ORDER BY name,id";
        $servicesStmt = $pdo->prepare($servicesSql);
        $servicesStmt->execute(array(':t' => $tenant));

        $productRows = array();
        if (aifTable($pdo, 'products')) {
            $productHasTaxId = aifColumn($pdo, 'products', 'tax_id') && aifTable($pdo, 'product_tax_rates');
            $productImageSelect = aifColumn($pdo, 'products', 'image_path') ? ',p.image_path' : ',NULL AS image_path';
            $productTaxSelect = $productHasTaxId ? ',p.tax_id,COALESCE(NULLIF(p.tax_percent,0),ptr.rate_percent,0) AS tax_percent' : ',NULL AS tax_id,p.tax_percent';
            $productTaxJoin = $productHasTaxId ? " LEFT JOIN product_tax_rates ptr ON ptr.id=p.tax_id AND ptr.tenant_id=p.tenant_id AND ptr.status='active' " : '';
            $productSql = "SELECT p.id,p.sku,p.name,p.description,p.unit_name,p.base_unit_price,p.selling_price" . $productTaxSelect . $productImageSelect . " FROM products p" . $productTaxJoin . " WHERE p.tenant_id=:t AND p.status='active'";
            if (aifColumn($pdo, 'products', 'deleted_at')) $productSql .= " AND p.deleted_at IS NULL";
            $productSql .= " ORDER BY p.name,p.id";
            $productsStmt = $pdo->prepare($productSql);
            $productsStmt->execute(array(':t' => $tenant));
            $productRows = $productsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $taxRates = aifTaxRates($pdo, $tenant);
        $defaultTaxRateId = 0;
        foreach ($taxRates as $taxRateRow) { if (!empty($taxRateRow['is_default'])) { $defaultTaxRateId = (int)$taxRateRow['id']; break; } }

        $currentUser = aifUserAccess($pdo, $tenant, $user);
        if (!$currentUser) $currentUser = array('id'=>$user, 'name'=>'Current user', 'can_edit_salesperson'=>0, 'is_tenant_admin'=>0, 'role_is_admin'=>0);

        $teamMembers = array();
        if (aifTable($pdo, 'users')) {
            $deletedClause = aifColumn($pdo, 'users', 'deleted_at') ? " AND u.deleted_at IS NULL" : "";
            $teamStmt = $pdo->prepare("SELECT u.id,u.first_name,u.last_name,u.email,u.job_title,u.is_tenant_admin,COALESCE(r.name,'') role_name,COALESCE(r.is_admin,0) role_is_admin FROM users u LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE u.tenant_id=:t AND u.status='active'" . $deletedClause . " ORDER BY u.first_name,u.last_name,u.id");
            $teamStmt->execute(array(':t'=>$tenant));
            foreach ($teamStmt->fetchAll(PDO::FETCH_ASSOC) as $tm) {
                $tm['name'] = trim((string)$tm['first_name'] . ' ' . (string)$tm['last_name']);
                $teamMembers[] = $tm;
            }
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
            'tax_rates' => $taxRates,
            'default_tax_rate_id' => $defaultTaxRateId,
            'current_user' => $currentUser,
            'team_members' => $teamMembers,
            'jobs' => $jobsStmt->fetchAll(PDO::FETCH_ASSOC),
            'currency' => aifCurrency($pdo, $tenant),
            'session_branch_id' => $sessionBranch,
            'invoice_no_preview' => aifPreviewNo($pdo, $tenant, $sessionBranch, 'invoice', 'invoices', 'invoice_no', 'INV-'),
            'has_recurring_job_unique_index' => aifIndex($pdo, 'invoices', 'uq_invoice_tenant_job') ? 1 : 0
        )));
    }

    if ($action === 'create_customer') {
        if (!aifTable($pdo, 'clients')) aifRes(500, false, 'Customers table is not available.', array());
        $name = substr(trim((string)aifP('display_name', '')), 0, 190);
        $company = substr(trim((string)aifP('company_name', '')), 0, 190);
        $email = strtolower(substr(trim((string)aifP('email', '')), 0, 190));
        $phone = substr(trim((string)aifP('phone', '')), 0, 50);
        $branchId = (int)aifP('branch_id', $sessionBranch);
        if ($name === '') aifRes(422, false, 'Customer name is required.', array());
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) aifRes(422, false, 'Enter a valid customer email address.', array());
        if ($branchId > 0 && !aifBranch($pdo, $tenant, $branchId)) $branchId = $sessionBranch;
        if ($email !== '') {
            $dup = $pdo->prepare("SELECT id FROM clients WHERE tenant_id=:t AND email=:e AND deleted_at IS NULL LIMIT 1");
            $dup->execute(array(':t'=>$tenant, ':e'=>$email));
            if ($dup->fetchColumn()) aifRes(409, false, 'This email is already used by another customer.', array());
        }
        $stmt = $pdo->prepare("INSERT INTO clients(tenant_id,branch_id,client_type,display_name,company_name,first_name,last_name,email,phone,alternate_phone,source,preferred_contact_method,allow_email,allow_sms,status,tax_number,notes,account_manager_id,created_by,last_activity_at,created_at,updated_at,deleted_at) VALUES(:t,:b,'client',:n,:company,NULL,NULL,:email,:phone,NULL,'invoice','email',:allow_email,0,'active',NULL,NULL,NULL,:u,NOW(),NOW(),NOW(),NULL)");
        $stmt->execute(array(':t'=>$tenant, ':b'=>$branchId>0?$branchId:null, ':n'=>$name, ':company'=>$company!==''?$company:null, ':email'=>$email!==''?$email:null, ':phone'=>$phone!==''?$phone:null, ':allow_email'=>$email!==''?1:0, ':u'=>$user));
        $id = (int)$pdo->lastInsertId();
        $client = aifClient($pdo, $tenant, $id);
        $client['name'] = $client['display_name'];
        if (function_exists('tenantAuditLog')) { try { tenantAuditLog($pdo, 'CUSTOMER_CREATED_FROM_INVOICE', $tenant, $branchId, $user, 'client', $id, null, $client); } catch (Throwable $e) { error_log('invoice customer audit ' . $e->getMessage()); } }
        aifRes(200, true, 'Customer created successfully.', array('client'=>$client));
    }

    if ($action === 'create_location') {
        if (!aifTable($pdo, 'client_locations')) aifRes(500, false, 'Customer locations table is not available.', array());
        $clientId = (int)aifP('client_id', 0);
        $client = aifClient($pdo, $tenant, $clientId);
        if (!$client) aifRes(422, false, 'Select a valid customer before creating a location.', array());
        $name = substr(trim((string)aifP('name', '')), 0, 190);
        $type = strtolower(trim((string)aifP('location_type', 'site')));
        $a1 = substr(trim((string)aifP('address_line1', '')), 0, 255);
        $a2 = substr(trim((string)aifP('address_line2', '')), 0, 255);
        $city = substr(trim((string)aifP('city', '')), 0, 120);
        $state = substr(trim((string)aifP('state', '')), 0, 120);
        $postal = substr(trim((string)aifP('postal_code', '')), 0, 40);
        $isPrimary = (int)aifP('is_primary', 0) === 1 ? 1 : 0;
        if ($name === '') aifRes(422, false, 'Location name is required.', array());
        if ($a1 === '') aifRes(422, false, 'Address line 1 is required.', array());
        if (!in_array($type, array('home','office','warehouse','factory','farm','shop','site','other'), true)) $type = 'site';
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM client_locations WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL");
        $countStmt->execute(array(':t'=>$tenant, ':c'=>$clientId));
        if ((int)$countStmt->fetchColumn() === 0) $isPrimary = 1;
        $pdo->beginTransaction();
        try {
            if ($isPrimary) $pdo->prepare("UPDATE client_locations SET is_primary=0 WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL")->execute(array(':t'=>$tenant, ':c'=>$clientId));
            $stmt = $pdo->prepare("INSERT INTO client_locations(tenant_id,client_id,location_type,name,address_line1,address_line2,city,state,postal_code,country_id,latitude,longitude,contact_name,contact_phone,gate_code,access_notes,service_instructions,is_primary,status,created_at,updated_at,deleted_at) VALUES(:t,:c,:type,:n,:a1,:a2,:city,:state,:postal,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,:primary,'active',NOW(),NOW(),NULL)");
            $stmt->execute(array(':t'=>$tenant, ':c'=>$clientId, ':type'=>$type, ':n'=>$name, ':a1'=>$a1, ':a2'=>$a2!==''?$a2:null, ':city'=>$city!==''?$city:null, ':state'=>$state!==''?$state:null, ':postal'=>$postal!==''?$postal:null, ':primary'=>$isPrimary));
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $location = aifLocation($pdo, $tenant, $clientId, $id);
        if (function_exists('tenantAuditLog')) { try { tenantAuditLog($pdo, 'CUSTOMER_LOCATION_CREATED_FROM_INVOICE', $tenant, !empty($client['branch_id'])?(int)$client['branch_id']:$sessionBranch, $user, 'client_location', $id, null, $location); } catch (Throwable $e) { error_log('invoice location audit ' . $e->getMessage()); } }
        aifRes(200, true, 'Location created successfully.', array('location'=>$location));
    }

    if ($action === 'customer_jobs') {
        $clientId = (int)aifP('client_id', 0);
        $client = aifClient($pdo, $tenant, $clientId);
        if (!$client) aifRes(404, false, 'Customer not found.', array());
        $billingJoin = aifTable($pdo, 'job_billing_settings') ? " LEFT JOIN job_billing_settings jbs ON jbs.job_id=j.id AND jbs.tenant_id=j.tenant_id " : "";
        $billingSelect = aifTable($pdo, 'job_billing_settings') ? ",COALESCE(jbs.billing_type,'visit_based') billing_type,COALESCE(jbs.total_invoices,1) total_invoices,jbs.fixed_invoice_amount" : ",'visit_based' billing_type,1 total_invoices,NULL fixed_invoice_amount";
        $stmt = $pdo->prepare("SELECT j.id,j.job_no,j.title,j.status,j.job_type,j.client_id,j.location_id,j.branch_id,j.quote_id,j.product_service_id,j.total,j.created_at,cl.name location_name,cl.address_line1,cl.address_line2,cl.city,cl.state,cl.postal_code,ps.name service_name" . $billingSelect . " FROM jobs j LEFT JOIN client_locations cl ON cl.id=j.location_id AND cl.tenant_id=j.tenant_id LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id " . $billingJoin . " WHERE j.tenant_id=:t AND j.client_id=:c AND j.deleted_at IS NULL AND j.status NOT IN('cancelled','archived','closed') ORDER BY j.id DESC");
        $stmt->execute(array(':t'=>$tenant, ':c'=>$clientId));
        $invoiceTotals = array();
        $invStmt = $pdo->prepare("SELECT job_id,COUNT(*) invoice_count,COALESCE(SUM(total),0) invoiced_total FROM invoices WHERE tenant_id=:t AND client_id=:c AND job_id IS NOT NULL AND status NOT IN('cancelled','archived') GROUP BY job_id");
        $invStmt->execute(array(':t'=>$tenant, ':c'=>$clientId));
        foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $ir) $invoiceTotals[(int)$ir['job_id']] = $ir;
        $jobs = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $slots = aifJobSlots($pdo, $tenant, $job);
            $available = array();
            foreach ($slots as $slot) if ((int)$slot['invoiced'] === 0) $available[] = $slot;
            $expected = max(1, (int)$job['total_invoices']);
            $used = isset($invoiceTotals[(int)$job['id']]) ? (int)$invoiceTotals[(int)$job['id']]['invoice_count'] : 0;
            if (!$available || $used >= $expected) continue;
            $invoicedTotal = isset($invoiceTotals[(int)$job['id']]) ? (float)$invoiceTotals[(int)$job['id']]['invoiced_total'] : 0.0;
            $subtotal = max(0, (float)$job['total']);
            $uninvoiced = max(0, round($subtotal - $invoicedTotal, 2));
            $preferredVisitId = 0;
            $nextScheduled = null;
            foreach ($available as $slot) {
                if ($preferredVisitId <= 0 && !empty($slot['visit_id'])) $preferredVisitId = (int)$slot['visit_id'];
                if ($nextScheduled === null && !empty($slot['scheduled_start'])) $nextScheduled = $slot['scheduled_start'];
            }
            $statusLabel = ucwords(str_replace('_',' ',(string)$job['status']));
            $jobs[] = array(
                'id'=>(int)$job['id'],
                'job_no'=>(string)$job['job_no'],
                'title'=>trim((string)$job['title'])!==''?(string)$job['title']:(trim((string)$job['service_name'])!==''?(string)$job['service_name']:(string)$job['job_no']),
                'status'=>(string)$job['status'],
                'status_label'=>$statusLabel,
                'address'=>aifFormatAddress($job),
                'location_name'=>isset($job['location_name'])?(string)$job['location_name']:'',
                'uninvoiced'=>$uninvoiced,
                'subtotal'=>$subtotal,
                'available_slots'=>count($available),
                'preferred_visit_id'=>$preferredVisitId,
                'next_scheduled_start'=>$nextScheduled
            );
        }
        aifRes(200, true, 'Customer jobs loaded.', array('client'=>array('id'=>(int)$client['id'],'name'=>(string)$client['display_name']), 'jobs'=>$jobs));
    }

    if ($action === 'invoice_number_preview') {
        $previewBranch = (int)aifP('branch_id', $sessionBranch);
        if ($previewBranch > 0 && !aifBranch($pdo, $tenant, $previewBranch)) $previewBranch = $sessionBranch;
        $previewNo = aifPreviewNo($pdo, $tenant, $previewBranch, 'invoice', 'invoices', 'invoice_no', 'INV-');
        aifRes(200, true, 'Current invoice number loaded.', array('invoice_no' => $previewNo));
    }

    if ($action === 'create_tax_rate') {
        if (!aifTable($pdo, 'product_tax_rates')) aifRes(500, false, 'Tax rate table is not available.', array());
        $name = substr(trim((string)aifP('name', '')), 0, 120);
        $rate = max(0, min(100, (float)aifP('rate_percent', 0)));
        $description = substr(trim((string)aifP('description', '')), 0, 190);
        $isDefault = (int)aifP('is_default', 0) === 1 ? 1 : 0;
        if ($name === '') aifRes(422, false, 'Tax rate name is required.', array());

        $dup = $pdo->prepare("SELECT id FROM product_tax_rates WHERE tenant_id=:t AND LOWER(tax_name)=LOWER(:n) AND status='active' LIMIT 1");
        $dup->execute(array(':t'=>$tenant, ':n'=>$name));
        if ($dup->fetchColumn()) aifRes(409, false, 'A tax rate with this name already exists.', array());

        $countryCode = 'US';
        if (aifTable($pdo, 'countries')) {
            $countryStmt = $pdo->prepare("SELECT c.iso2 FROM tenants t LEFT JOIN countries c ON c.id=t.country_id WHERE t.id=:t LIMIT 1");
            $countryStmt->execute(array(':t'=>$tenant));
            $cc = strtoupper(trim((string)$countryStmt->fetchColumn()));
            if (preg_match('/^[A-Z]{2}$/', $cc)) $countryCode = $cc;
        }

        $pdo->beginTransaction();
        try {
            $hasDefault = aifColumn($pdo, 'product_tax_rates', 'is_default');
            if ($isDefault && $hasDefault) {
                $pdo->prepare("UPDATE product_tax_rates SET is_default=0 WHERE tenant_id=:t")->execute(array(':t'=>$tenant));
            }
            if ($hasDefault) {
                $stmt = $pdo->prepare("INSERT INTO product_tax_rates(tenant_id,tax_name,rate_percent,country_code,state_code,state_name,tax_type,jurisdiction_name,status,is_default,created_by,created_at,updated_at) VALUES(:t,:n,:r,:cc,'',NULL,'other',:d,'active',:def,:u,NOW(),NOW())");
                $stmt->execute(array(':t'=>$tenant, ':n'=>$name, ':r'=>$rate, ':cc'=>$countryCode, ':d'=>$description!==''?$description:null, ':def'=>$isDefault, ':u'=>$user));
            } else {
                $stmt = $pdo->prepare("INSERT INTO product_tax_rates(tenant_id,tax_name,rate_percent,country_code,state_code,state_name,tax_type,jurisdiction_name,status,created_by,created_at,updated_at) VALUES(:t,:n,:r,:cc,'',NULL,'other',:d,'active',:u,NOW(),NOW())");
                $stmt->execute(array(':t'=>$tenant, ':n'=>$name, ':r'=>$rate, ':cc'=>$countryCode, ':d'=>$description!==''?$description:null, ':u'=>$user));
            }
            $taxId = (int)$pdo->lastInsertId();
            $pdo->commit();
            $taxRate = aifTaxRate($pdo, $tenant, $taxId);
            if (function_exists('tenantAuditLog')) {
                try { tenantAuditLog($pdo, 'TAX_RATE_CREATED', $tenant, $sessionBranch, $user, 'tax_rate', $taxId, null, $taxRate); } catch (Throwable $auditError) { error_log('tax rate audit ' . $auditError->getMessage()); }
            }
            aifRes(200, true, 'Tax rate created successfully.', array('tax_rate'=>$taxRate));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'create_catalog_item') {
        $itemType = strtolower(trim((string)aifP('item_type', 'service')));
        if (!in_array($itemType, array('service','product'), true)) aifRes(422, false, 'Select Service or Product.', array());

        $name = substr(trim((string)aifP('name', '')), 0, 190);
        $description = trim((string)aifP('description', ''));
        $unitCost = max(0, (float)aifP('unit_cost', 0));
        $markupPercent = max(0, (float)aifP('markup_percent', 0));
        $unitPriceRaw = trim((string)aifP('unit_price', ''));
        $unitPrice = $unitPriceRaw === '' ? round($unitCost * (1 + ($markupPercent / 100)), 2) : max(0, (float)$unitPriceRaw);
        $exemptTax = (int)aifP('exempt_tax', 0) === 1;
        $taxRateId = (int)aifP('tax_rate_id', 0);
        $catalogTaxRate = (!$exemptTax && $taxRateId > 0) ? aifTaxRate($pdo, $tenant, $taxRateId) : null;
        if ($taxRateId > 0 && !$exemptTax && !$catalogTaxRate) aifRes(422, false, 'Selected tax rate is no longer available.', array());
        $taxPercent = $exemptTax ? 0.0 : ($catalogTaxRate ? max(0, (float)$catalogTaxRate['rate_percent']) : max(0, (float)aifP('tax_percent', 0)));
        $hasUploadedImage = !empty($_FILES['item_image']) && is_array($_FILES['item_image']) && isset($_FILES['item_image']['error']) && (int)$_FILES['item_image']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($name === '') aifRes(422, false, 'Product / Service name is required.', array());

        if ($itemType === 'service') {
            $serviceHasImage = aifColumn($pdo, 'product_services', 'image_path');
            $serviceImageSelect = $serviceHasImage ? ',image_path' : ',NULL AS image_path';
            $find = $pdo->prepare("SELECT id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent" . $serviceImageSelect . " FROM product_services WHERE tenant_id=:t AND item_type='service' AND LOWER(name)=LOWER(:n) AND deleted_at IS NULL AND status='active' LIMIT 1");
            $find->execute(array(':t'=>$tenant, ':n'=>$name));
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if ($existing) aifRes(200, true, 'Existing service selected.', array('created'=>0, 'item_kind'=>'service', 'item'=>$existing));

            if ($hasUploadedImage && !$serviceHasImage) {
                aifRes(500, false, 'Service image storage is not installed. Run migration_product_service_image.sql once.', array());
            }

            $image = array('relative'=>null, 'absolute'=>null);
            try {
                if ($hasUploadedImage) $image = aifStoreCatalogImage('item_image', $tenant, 'service');

                if ($serviceHasImage) {
                    $stmt = $pdo->prepare("INSERT INTO product_services(tenant_id,category_id,item_type,name,sku,image_path,description,unit_name,unit_cost,unit_price,tax_percent,is_bookable,estimated_duration_minutes,status,created_at,updated_at,deleted_at) VALUES(:t,NULL,'service',:n,NULL,:img,:d,'Service',:cost,:price,:tax,0,NULL,'active',NOW(),NOW(),NULL)");
                    $stmt->execute(array(':t'=>$tenant, ':n'=>$name, ':img'=>$image['relative'], ':d'=>$description!==''?$description:null, ':cost'=>$unitCost, ':price'=>$unitPrice, ':tax'=>$taxPercent));
                } else {
                    $stmt = $pdo->prepare("INSERT INTO product_services(tenant_id,category_id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent,is_bookable,estimated_duration_minutes,status,created_at,updated_at,deleted_at) VALUES(:t,NULL,'service',:n,NULL,:d,'Service',:cost,:price,:tax,0,NULL,'active',NOW(),NOW(),NULL)");
                    $stmt->execute(array(':t'=>$tenant, ':n'=>$name, ':d'=>$description!==''?$description:null, ':cost'=>$unitCost, ':price'=>$unitPrice, ':tax'=>$taxPercent));
                }
            } catch (Throwable $e) {
                if (!empty($image['absolute']) && is_file($image['absolute'])) @unlink($image['absolute']);
                throw $e;
            }

            $newId = (int)$pdo->lastInsertId();
            $item = array('id'=>$newId, 'item_type'=>'service', 'name'=>$name, 'sku'=>null, 'image_path'=>$image['relative'], 'description'=>$description, 'unit_name'=>'Service', 'unit_cost'=>$unitCost, 'unit_price'=>$unitPrice, 'tax_percent'=>$taxPercent);

            if (function_exists('tenantAuditLog')) {
                try { tenantAuditLog($pdo, 'SERVICE_CREATED', $tenant, $sessionBranch, $user, 'service', $newId, null, $item); }
                catch (Throwable $auditError) { error_log('catalog service audit ' . $auditError->getMessage()); }
            }
            aifRes(200, true, 'Service created successfully.', array('created'=>1, 'item_kind'=>'service', 'item'=>$item));
        }

        if (!aifTable($pdo, 'products')) aifRes(500, false, 'Products table is not available.', array());
        $productHasImage = aifColumn($pdo, 'products', 'image_path');
        $productHasTaxId = aifColumn($pdo, 'products', 'tax_id') && aifTable($pdo, 'product_tax_rates');
        $productImageSelect = $productHasImage ? ',p.image_path' : ',NULL AS image_path';
        $productTaxSelect = $productHasTaxId ? ',p.tax_id,COALESCE(NULLIF(p.tax_percent,0),ptr.rate_percent,0) AS tax_percent' : ',NULL AS tax_id,p.tax_percent';
        $productTaxJoin = $productHasTaxId ? " LEFT JOIN product_tax_rates ptr ON ptr.id=p.tax_id AND ptr.tenant_id=p.tenant_id AND ptr.status='active' " : '';
        $findSql = "SELECT p.id,p.sku,p.name,p.description,p.unit_name,p.base_unit_price,p.selling_price" . $productTaxSelect . $productImageSelect . " FROM products p" . $productTaxJoin . " WHERE p.tenant_id=:t AND LOWER(p.name)=LOWER(:n) AND p.status='active'" . (aifColumn($pdo, 'products', 'deleted_at') ? " AND p.deleted_at IS NULL" : "") . " LIMIT 1";
        $find = $pdo->prepare($findSql);
        $find->execute(array(':t'=>$tenant, ':n'=>$name));
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) aifRes(200, true, 'Existing product selected.', array('created'=>0, 'item_kind'=>'product', 'item'=>$existing));

        if ($hasUploadedImage && !$productHasImage) {
            aifRes(500, false, 'Product image storage is not available in the products table.', array());
        }

        $image = array('relative'=>null, 'absolute'=>null);
        try {
            if ($hasUploadedImage) $image = aifStoreCatalogImage('item_image', $tenant, 'product');

            if ($productHasImage) {
                $stmt = $pdo->prepare("INSERT INTO products(tenant_id,category_id,sku,name,image_path,description,unit_of_measure_id,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_id,tax_percent,track_inventory,status,created_by,created_at,updated_at,deleted_at) VALUES(:t,NULL,NULL,:n,:img,:d,NULL,'Unit',:cost,'percentage',:markup,:price,:tax_id,:tax,0,'active',:u,NOW(),NOW(),NULL)");
                $stmt->execute(array(':t'=>$tenant, ':n'=>$name, ':img'=>$image['relative'], ':d'=>$description!==''?$description:null, ':cost'=>$unitCost, ':markup'=>$markupPercent, ':price'=>$unitPrice, ':tax_id'=>(!$exemptTax && $catalogTaxRate ? (int)$catalogTaxRate['id'] : null), ':tax'=>$taxPercent, ':u'=>$user));
            } else {
                $stmt = $pdo->prepare("INSERT INTO products(tenant_id,category_id,sku,name,description,unit_of_measure_id,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_id,tax_percent,track_inventory,status,created_by,created_at,updated_at,deleted_at) VALUES(:t,NULL,NULL,:n,:d,NULL,'Unit',:cost,'percentage',:markup,:price,:tax_id,:tax,0,'active',:u,NOW(),NOW(),NULL)");
                $stmt->execute(array(':t'=>$tenant, ':n'=>$name, ':d'=>$description!==''?$description:null, ':cost'=>$unitCost, ':markup'=>$markupPercent, ':price'=>$unitPrice, ':tax_id'=>(!$exemptTax && $catalogTaxRate ? (int)$catalogTaxRate['id'] : null), ':tax'=>$taxPercent, ':u'=>$user));
            }
        } catch (Throwable $e) {
            if (!empty($image['absolute']) && is_file($image['absolute'])) @unlink($image['absolute']);
            throw $e;
        }

        $newId = (int)$pdo->lastInsertId();
        $item = array('id'=>$newId, 'sku'=>null, 'name'=>$name, 'image_path'=>$image['relative'], 'description'=>$description, 'unit_name'=>'Unit', 'base_unit_price'=>$unitCost, 'selling_price'=>$unitPrice, 'tax_id'=>(!$exemptTax && $catalogTaxRate ? (int)$catalogTaxRate['id'] : null), 'tax_percent'=>$taxPercent);

        if (function_exists('tenantAuditLog')) {
            try { tenantAuditLog($pdo, 'PRODUCT_CREATED', $tenant, $sessionBranch, $user, 'product', $newId, null, $item); }
            catch (Throwable $auditError) { error_log('catalog product audit ' . $auditError->getMessage()); }
        }
        aifRes(200, true, 'Product created successfully.', array('created'=>1, 'item_kind'=>'product', 'item'=>$item));
    }

    if ($action === 'payment_hints') {
        $clientId = (int)aifP('client_id', 0);
        $client = aifClient($pdo, $tenant, $clientId);
        if (!$client) aifRes(404, false, 'Client not found.', array());
        aifRes(200, true, 'Client payment details loaded.', array('hints' => aifPaymentHints($pdo, $tenant, $clientId)));
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
            'selected_visit_id' => $selectedVisitId,
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
        $clientViewOptionsRaw = json_decode((string)aifP('client_view_options_json', '{}'), true);
        if (!is_array($clientViewOptionsRaw)) $clientViewOptionsRaw = array();
        $clientViewDefaults = array('quantities'=>true,'unit_prices'=>true,'line_item_totals'=>true,'account_balance'=>true,'late_stamp'=>true);
        $clientViewOptions = array();
        foreach ($clientViewDefaults as $cvKey => $cvDefault) {
            $clientViewOptions[$cvKey] = array_key_exists($cvKey, $clientViewOptionsRaw) ? (bool)$clientViewOptionsRaw[$cvKey] : $cvDefault;
        }
        $clientViewOptionsJson = json_encode($clientViewOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $notes = trim((string)aifP('notes', ''));
        $noteMentionIdsRaw = json_decode((string)aifP('note_mentions_json', '[]'), true);
        if (!is_array($noteMentionIdsRaw)) $noteMentionIdsRaw = array();
        $mentionedUsers = aifValidMentionUsers($pdo, $tenant, $noteMentionIdsRaw);
        $hasInternalNoteFiles = aifNoteUploadPresent();
        if (($notes !== '' || $mentionedUsers || $hasInternalNoteFiles) && (!aifTable($pdo, 'invoice_internal_notes') || !aifTable($pdo, 'invoice_internal_note_mentions'))) {
            aifRes(500, false, 'Internal invoice notes are not installed. Run migration_invoice_internal_notes.sql once.', array());
        }
        $requestedInvoiceNo = substr(trim((string)aifP('invoice_no', '')), 0, 80);
        $invoiceNoMode = strtolower(trim((string)aifP('invoice_no_mode', 'auto')));
        if (!in_array($invoiceNoMode, array('auto','manual'), true)) $invoiceNoMode = 'auto';
        if ($invoiceNoMode === 'manual' && $requestedInvoiceNo === '') aifRes(422, false, 'Enter a valid invoice number.', array());
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

        $actorAccess = aifUserAccess($pdo, $tenant, $user);
        $canEditSalesperson = $actorAccess && !empty($actorAccess['can_edit_salesperson']);
        $requestedSalespersonId = (int)aifP('salesperson_id', $user);
        $salespersonId = $user;
        if ($canEditSalesperson && $requestedSalespersonId > 0) {
            $salesperson = aifUserAccess($pdo, $tenant, $requestedSalespersonId);
            if (!$salesperson) aifRes(422, false, 'Select a valid active salesperson.', array());
            $salespersonId = (int)$salesperson['id'];
        }
        if ($salespersonId !== $user && !aifColumn($pdo, 'invoices', 'salesperson_id')) {
            aifRes(500, false, 'Salesperson storage is not installed. Run migration_invoice_salesperson_v1.sql once.', array());
        }

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
            if (!$client) aifRes(422, false, 'Select a valid client.', array());
            if ($locationId > 0 && !aifLocation($pdo, $tenant, $clientId, $locationId)) aifRes(422, false, 'Selected client location is invalid.', array());
            if ($branchId <= 0) $branchId = !empty($client['branch_id']) ? (int)$client['branch_id'] : $sessionBranch;
            if ($branchId > 0 && !aifBranch($pdo, $tenant, $branchId)) aifRes(422, false, 'Select a valid branch.', array());
        }

        $client = aifClient($pdo, $tenant, $clientId);
        if (!$client) aifRes(422, false, 'Invoice client could not be loaded.', array());

        $items = json_decode((string)aifP('items_json', '[]'), true);
        if (!is_array($items) || !count($items)) aifRes(422, false, 'Add at least one invoice item.', array());

        $invoiceDiscountType = strtolower(trim((string)aifP('invoice_discount_type', 'fixed')));
        if (!in_array($invoiceDiscountType, array('fixed','percentage'), true)) $invoiceDiscountType = 'fixed';
        $invoiceDiscountValue = max(0, (float)aifP('invoice_discount_value', 0));
        if ($invoiceDiscountType === 'percentage') $invoiceDiscountValue = min(100, $invoiceDiscountValue);
        $selectedTaxRateId = (int)aifP('invoice_tax_rate_id', 0);
        $selectedTaxRate = $selectedTaxRateId > 0 ? aifTaxRate($pdo, $tenant, $selectedTaxRateId) : null;
        if ($selectedTaxRateId > 0 && !$selectedTaxRate) aifRes(422, false, 'Selected tax rate is no longer available.', array());
        $selectedTaxPercent = $selectedTaxRate ? max(0, (float)$selectedTaxRate['rate_percent']) : 0.0;

        $normalized = array();
        $subtotal = 0.0;
        $existingDiscountTotal = 0.0;
        $discountBase = 0.0;
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
            $serviceDate = trim((string)(isset($item['service_date']) ? $item['service_date'] : ''));
            if ($serviceDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceDate)) aifRes(422, false, 'Line item ' . ((int)$index + 1) . ' has an invalid service date.', array());
            if ($serviceDate === '') $serviceDate = null;
            $catalogTaxPercent = null;
            if ($psid > 0) {
                $check = $pdo->prepare("SELECT id,tax_percent FROM product_services WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL AND status='active' LIMIT 1");
                $check->execute(array(':id' => $psid, ':t' => $tenant));
                $serviceRow = $check->fetch(PDO::FETCH_ASSOC);
                if (!$serviceRow) aifRes(422, false, 'One selected service is no longer available.', array());
                $catalogTaxPercent = max(0, (float)$serviceRow['tax_percent']);
                $itemSource = 'service';
            } else $psid = null;
            if ($productId > 0) {
                if (!aifTable($pdo, 'products')) aifRes(422, false, 'Product catalog is unavailable.', array());
                $hasProductTaxMaster = aifColumn($pdo, 'products', 'tax_id') && aifTable($pdo, 'product_tax_rates');
                $productSql = $hasProductTaxMaster
                    ? "SELECT p.id,COALESCE(NULLIF(p.tax_percent,0),ptr.rate_percent,0) AS tax_percent FROM products p LEFT JOIN product_tax_rates ptr ON ptr.id=p.tax_id AND ptr.tenant_id=p.tenant_id AND ptr.status='active' WHERE p.id=:id AND p.tenant_id=:t AND p.status='active'" . (aifColumn($pdo, 'products', 'deleted_at') ? " AND p.deleted_at IS NULL" : "") . " LIMIT 1"
                    : "SELECT id,tax_percent FROM products WHERE id=:id AND tenant_id=:t AND status='active'" . (aifColumn($pdo, 'products', 'deleted_at') ? " AND deleted_at IS NULL" : "") . " LIMIT 1";
                $check = $pdo->prepare($productSql);
                $check->execute(array(':id' => $productId, ':t' => $tenant));
                $productRow = $check->fetch(PDO::FETCH_ASSOC);
                if (!$productRow) aifRes(422, false, 'One selected product is no longer available.', array());
                $catalogTaxPercent = max(0, (float)$productRow['tax_percent']);
                $itemSource = 'product';
            } else $productId = null;
            $name = trim((string)(isset($item['item_name']) ? $item['item_name'] : ''));
            if ($name === '') aifRes(422, false, 'Invoice item name is required.', array());
            $qty = max(0.001, (float)(isset($item['quantity']) ? $item['quantity'] : 1));
            $unitCost = max(0, (float)(isset($item['unit_cost']) ? $item['unit_cost'] : 0));
            $unitPrice = max(0, (float)(isset($item['unit_price']) ? $item['unit_price'] : 0));
            $base = round($qty * $unitPrice, 2);
            $discount = max(0, min($base, (float)(isset($item['discount_amount']) ? $item['discount_amount'] : 0)));
            $submittedTaxPercent = max(0, (float)(isset($item['tax_percent']) ? $item['tax_percent'] : 0));
            $taxPercent = $catalogTaxPercent !== null ? $catalogTaxPercent : $submittedTaxPercent;
            if ($taxPercent <= 0 && $selectedTaxPercent > 0) $taxPercent = $selectedTaxPercent;
            $subtotal += $base;
            $existingDiscountTotal += $discount;
            $discountBase += max(0, $base - $discount);
            $normalized[] = array(
                'product_service_id' => $psid,
                'product_id' => $productId,
                'item_source' => $itemSource,
                'new_product_name' => $newProductName,
                'service_date' => $serviceDate,
                'item_name' => $name,
                'description' => trim((string)(isset($item['description']) ? $item['description'] : '')),
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'unit_price' => $unitPrice,
                'base_amount' => $base,
                'original_discount_amount' => $discount,
                'discount_amount' => $discount,
                'tax_percent' => $taxPercent,
                'tax_amount' => 0,
                'line_total' => 0,
                'sort_order' => $index
            );
        }
        $subtotal = round($subtotal, 2);
        $existingDiscountTotal = round($existingDiscountTotal, 2);
        $discountBase = round($discountBase, 2);
        $invoiceDiscountAmount = 0.0;
        if ($invoiceDiscountValue > 0 && $discountBase > 0) {
            $invoiceDiscountAmount = $invoiceDiscountType === 'percentage'
                ? round($discountBase * $invoiceDiscountValue / 100, 2)
                : min($discountBase, round($invoiceDiscountValue, 2));
        }
        $remainingDiscount = $invoiceDiscountAmount;
        $remainingBase = $discountBase;
        $eligibleIndexes = array();
        foreach ($normalized as $idx => $item) if (max(0, $item['base_amount'] - $item['original_discount_amount']) > 0) $eligibleIndexes[] = $idx;
        $lastEligible = count($eligibleIndexes) ? end($eligibleIndexes) : -1;
        foreach ($normalized as $idx => &$item) {
            $netBeforeInvoiceDiscount = max(0, round($item['base_amount'] - $item['original_discount_amount'], 2));
            $share = 0.0;
            if ($invoiceDiscountAmount > 0 && $netBeforeInvoiceDiscount > 0 && $remainingBase > 0) {
                if ($idx === $lastEligible) $share = $remainingDiscount;
                else $share = round($invoiceDiscountAmount * ($netBeforeInvoiceDiscount / $discountBase), 2);
                $share = max(0, min($netBeforeInvoiceDiscount, $share));
                $remainingDiscount = round($remainingDiscount - $share, 2);
                $remainingBase = round($remainingBase - $netBeforeInvoiceDiscount, 2);
            }
            $item['discount_amount'] = round($item['original_discount_amount'] + $share, 2);
            $taxable = max(0, round($item['base_amount'] - $item['discount_amount'], 2));
            $item['tax_amount'] = round($taxable * $item['tax_percent'] / 100, 2);
            $item['line_total'] = round($taxable + $item['tax_amount'], 2);
            $taxTotal += $item['tax_amount'];
            $grandTotal += $item['line_total'];
            unset($item['base_amount'], $item['original_discount_amount']);
        }
        unset($item);
        $discountTotal = round($existingDiscountTotal + $invoiceDiscountAmount, 2);
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

            if ($invoiceNoMode === 'manual') {
                $invoiceNo = $requestedInvoiceNo;
                if ($invoiceNo === '') throw new RuntimeException('Invoice number is required.');
                $dupNo = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id=:t AND invoice_no=:n LIMIT 1 FOR UPDATE");
                $dupNo->execute(array(':t' => $tenant, ':n' => $invoiceNo));
                if ($dupNo->fetchColumn()) throw new RuntimeException('Invoice number ' . $invoiceNo . ' already exists. Enter a different invoice number.');
            } else {
                $invoiceNo = aifNextNo($pdo, $tenant, $branchId, 'invoice', 'invoices', 'invoice_no', 'INV-');
            }
            $invoiceColumns = array('tenant_id','branch_id','invoice_no','client_id','location_id','job_id','visit_id','quote_id','status','issue_date','due_date','subtotal','discount_total','tax_total','total','amount_paid','balance_due','payment_terms','notes','sent_at','paid_at','created_by');
            $invoiceValues = array(':t',':b',':no',':c',':l',':j',':v',':q',':st',':issue',':due',':sub',':disc',':tax',':tot',':paid',':bal',':terms',':notes','NULL','NULL',':u');
            if (aifColumn($pdo, 'invoices', 'subject')) { $invoiceColumns[]='subject'; $invoiceValues[]=':subject'; }
            if (aifColumn($pdo, 'invoices', 'client_message')) { $invoiceColumns[]='client_message'; $invoiceValues[]=':client_message'; }
            if (aifColumn($pdo, 'invoices', 'contract_disclaimer')) { $invoiceColumns[]='contract_disclaimer'; $invoiceValues[]=':contract_disclaimer'; }
            if (aifColumn($pdo, 'invoices', 'client_view_options')) { $invoiceColumns[]='client_view_options'; $invoiceValues[]=':client_view_options'; }
            if (aifColumn($pdo, 'invoices', 'salesperson_id')) { $invoiceColumns[]='salesperson_id'; $invoiceValues[]=':salesperson_id'; }
            $invoiceStmt = $pdo->prepare("INSERT INTO invoices(" . implode(',', $invoiceColumns) . ") VALUES(" . implode(',', $invoiceValues) . ")");
            $invoiceParams = array(':t'=>$tenant, ':b'=>$branchId > 0 ? $branchId : null, ':no'=>$invoiceNo, ':c'=>$clientId, ':l'=>$locationId > 0 ? $locationId : null, ':j'=>$jobId > 0 ? $jobId : null, ':v'=>$visitId > 0 ? $visitId : null, ':q'=>$quoteId > 0 ? $quoteId : null, ':st'=>'draft', ':issue'=>$issueDate, ':due'=>$dueDate !== '' ? $dueDate : null, ':sub'=>$subtotal, ':disc'=>$discountTotal, ':tax'=>$taxTotal, ':tot'=>$grandTotal, ':paid'=>0, ':bal'=>$grandTotal, ':terms'=>$paymentTerms !== '' ? $paymentTerms : null, ':notes'=>null, ':u'=>$user);
            if (aifColumn($pdo, 'invoices', 'subject')) $invoiceParams[':subject'] = $subject !== '' ? $subject : null;
            if (aifColumn($pdo, 'invoices', 'client_message')) $invoiceParams[':client_message'] = $clientMessage !== '' ? $clientMessage : null;
            if (aifColumn($pdo, 'invoices', 'contract_disclaimer')) $invoiceParams[':contract_disclaimer'] = $contractDisclaimer !== '' ? $contractDisclaimer : null;
            if (aifColumn($pdo, 'invoices', 'client_view_options')) $invoiceParams[':client_view_options'] = $clientViewOptionsJson;
            if (aifColumn($pdo, 'invoices', 'salesperson_id')) $invoiceParams[':salesperson_id'] = $salespersonId;
            $invoiceStmt->execute($invoiceParams);
            $invoiceId = (int)$pdo->lastInsertId();

            $internalNoteId = 0;
            if ($notes !== '' || $mentionedUsers || $hasInternalNoteFiles) {
                $noteStmt = $pdo->prepare("INSERT INTO invoice_internal_notes(tenant_id,branch_id,invoice_id,note_text,created_by,created_at) VALUES(:t,:b,:i,:n,:u,NOW())");
                $noteStmt->execute(array(':t'=>$tenant, ':b'=>$branchId>0?$branchId:null, ':i'=>$invoiceId, ':n'=>$notes!==''?$notes:null, ':u'=>$user));
                $internalNoteId = (int)$pdo->lastInsertId();
                if ($mentionedUsers) {
                    $mentionStmt = $pdo->prepare("INSERT INTO invoice_internal_note_mentions(tenant_id,note_id,user_id,created_at) VALUES(:t,:n,:u,NOW())");
                    foreach ($mentionedUsers as $mentionedUserId => $mentionedUserRow) {
                        $mentionStmt->execute(array(':t'=>$tenant, ':n'=>$internalNoteId, ':u'=>(int)$mentionedUserId));
                    }
                }
            }

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
            if (aifColumn($pdo, 'invoice_line_items', 'service_date')) { $lineColumns[]='service_date'; $lineValues[]=':sd'; }
            $lineColumns = array_merge($lineColumns, array('item_name','description','quantity','unit_cost','unit_price','discount_amount','tax_percent','tax_amount','line_total','sort_order'));
            $lineValues = array_merge($lineValues, array(':n',':d',':qty',':cost',':price',':disc',':tp',':ta',':lt',':sort'));
            $lineStmt = $pdo->prepare("INSERT INTO invoice_line_items(" . implode(',', $lineColumns) . ") VALUES(" . implode(',', $lineValues) . ")");
            foreach ($normalized as $item) {
                $params = array(':i'=>$invoiceId, ':ps'=>$item['product_service_id'], ':n'=>$item['item_name'], ':d'=>$item['description'] !== '' ? $item['description'] : null, ':qty'=>$item['quantity'], ':cost'=>$item['unit_cost'], ':price'=>$item['unit_price'], ':disc'=>$item['discount_amount'], ':tp'=>$item['tax_percent'], ':ta'=>$item['tax_amount'], ':lt'=>$item['line_total'], ':sort'=>$item['sort_order']);
                if (aifColumn($pdo, 'invoice_line_items', 'product_id')) $params[':pid'] = !empty($item['product_id']) ? (int)$item['product_id'] : null;
                if (aifColumn($pdo, 'invoice_line_items', 'item_source')) $params[':src'] = $item['item_source'];
                if (aifColumn($pdo, 'invoice_line_items', 'service_date')) $params[':sd'] = !empty($item['service_date']) ? $item['service_date'] : null;
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
            $noteUploadSummary = aifStoreInternalNoteUploads($pdo, $tenant, $user, $invoiceId, $internalNoteId);
            if ($internalNoteId > 0 && $mentionedUsers) aifNotifyInvoiceMentions($pdo, $tenant, $branchId, $user, $invoiceId, $invoiceNo, $mentionedUsers);
            $emailSummary = aifEmailInvoice($pdo, $tenant, $branchId, $client, $invoiceId, $invoiceNo, $issueDate, $dueDate, $subject, $clientMessage, $normalized, $grandTotal, $currency, $clientViewOptions);
            if ((int)$emailSummary['sent'] === 1) $invoiceStatus = 'sent';

            aifLog($pdo, $tenant, $branchId, $user, $clientId, 'invoice_created', $invoiceId, 'Invoice created: ' . $invoiceNo, array('invoice_no' => $invoiceNo, 'source' => $sourceMode, 'salesperson_id' => $salespersonId, 'job_id' => $jobId > 0 ? $jobId : null, 'visit_id' => $visitId > 0 ? $visitId : null, 'total' => $grandTotal, 'amount_paid' => $receivedTotal, 'balance_due' => $balanceDue, 'email_sent' => (int)$emailSummary['sent'], 'email_status' => $emailSummary['status'], 'email_log_id' => (int)$emailSummary['mail_log_id'], 'smtp_config_id' => (int)$emailSummary['smtp_id'], 'email_pdf_attached' => (int)$emailSummary['pdf_attached'], 'email_pdf_name' => $emailSummary['pdf_name'], 'email_pdf_size' => (int)$emailSummary['pdf_size'], 'email_pdf_sha256' => $emailSummary['pdf_sha256'], 'files_saved' => (int)$uploadSummary['saved'], 'internal_note_id' => $internalNoteId > 0 ? $internalNoteId : null, 'mentioned_user_ids' => array_map('intval', array_keys($mentionedUsers)), 'internal_note_files_saved' => (int)$noteUploadSummary['saved']));
            if (function_exists('tenantAuditLog')) {
                try {
                    tenantAuditLog($pdo, 'INVOICE_CREATED', $tenant, $branchId, $user, 'invoice', $invoiceId, null, array('invoice_no' => $invoiceNo, 'job_id' => $jobId > 0 ? $jobId : null, 'client_id' => $clientId, 'total' => $grandTotal, 'amount_paid' => $receivedTotal, 'balance_due' => $balanceDue, 'email_sent' => (int)$emailSummary['sent'], 'email_status' => $emailSummary['status'], 'email_log_id' => (int)$emailSummary['mail_log_id'], 'smtp_config_id' => (int)$emailSummary['smtp_id'], 'email_pdf_attached' => (int)$emailSummary['pdf_attached'], 'email_pdf_name' => $emailSummary['pdf_name'], 'email_pdf_size' => (int)$emailSummary['pdf_size'], 'email_pdf_sha256' => $emailSummary['pdf_sha256'], 'files_saved' => (int)$uploadSummary['saved'], 'internal_note_id' => $internalNoteId > 0 ? $internalNoteId : null, 'mentioned_user_ids' => array_map('intval', array_keys($mentionedUsers)), 'internal_note_files_saved' => (int)$noteUploadSummary['saved']));
                } catch (Throwable $auditError) {
                    error_log('invoice form audit ' . $auditError->getMessage());
                }
            }

            $uploadMessageParts = array();
            if ($uploadSummary['messages']) $uploadMessageParts[] = implode(' ', array_unique($uploadSummary['messages']));
            elseif ((int)$uploadSummary['saved'] > 0) $uploadMessageParts[] = (int)$uploadSummary['saved'] . ' invoice file(s) uploaded.';
            if ($noteUploadSummary['messages']) $uploadMessageParts[] = implode(' ', array_unique($noteUploadSummary['messages']));
            elseif ((int)$noteUploadSummary['saved'] > 0) $uploadMessageParts[] = (int)$noteUploadSummary['saved'] . ' private note file(s) uploaded.';
            $uploadMessage = implode(' ', $uploadMessageParts);
            $message = 'Invoice ' . $invoiceNo . ' created successfully.';
            if ((int)$emailSummary['sent'] === 1) $message .= ' Client email sent.';

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
                'internal_note_id' => $internalNoteId,
                'internal_note_mention_count' => count($mentionedUsers),
                'internal_note_file_count' => (int)$noteUploadSummary['saved'],
                'upload_message' => $uploadMessage,
                'email_sent' => (int)$emailSummary['sent'],
                'email_status' => $emailSummary['status'],
                'email_log_id' => (int)$emailSummary['mail_log_id'],
                'email_smtp_id' => (int)$emailSummary['smtp_id'],
                'email_smtp_scope' => isset($emailSummary['smtp_scope']) ? $emailSummary['smtp_scope'] : 'platform',
                'email_smtp_is_default' => isset($emailSummary['smtp_is_default']) ? (int)$emailSummary['smtp_is_default'] : 0,
                'email_recipient' => isset($emailSummary['recipient']) ? $emailSummary['recipient'] : '',
                'email_pdf_attached' => isset($emailSummary['pdf_attached']) ? (int)$emailSummary['pdf_attached'] : 0,
                'email_pdf_name' => isset($emailSummary['pdf_name']) ? $emailSummary['pdf_name'] : '',
                'email_pdf_size' => isset($emailSummary['pdf_size']) ? (int)$emailSummary['pdf_size'] : 0,
                'email_pdf_sha256' => isset($emailSummary['pdf_sha256']) ? $emailSummary['pdf_sha256'] : '',
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
