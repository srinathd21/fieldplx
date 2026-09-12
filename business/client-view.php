<?php
/* FieldPlx Client View UI Fix - v3.0.1 - canonical invoices.php shell */
/* FieldPlx Client View - Version 3.0.0 - 2026-09-08 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Client View';
$activePage = 'clients';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['clients_csrf_token'])) {
    $_SESSION['clients_csrf_token'] = bin2hex(random_bytes(32));
}
$clientsCsrfToken = (string)$_SESSION['clients_csrf_token'];

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/includes/db.php';
}

function cvH($value)
{
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}
function cvTable(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) return $cache[$table];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t' => $table));
    $cache[$table] = (int)$q->fetchColumn() > 0;
    return $cache[$table];
}
function cvColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = (int)$q->fetchColumn() > 0;
    return $cache[$key];
}
function cvLabel($value)
{
    $value = trim((string)$value);
    if ($value === '') return '—';
    return ucwords(str_replace(array('_', '-'), ' ', $value));
}
function cvDate($value, $time = false)
{
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    if ($ts === false) return '—';
    return $time ? date('M d, Y, h:i A', $ts) : date('M d, Y', $ts);
}
function cvMoney($amount, $currency)
{
    $decimals = isset($currency['decimal_places']) ? (int)$currency['decimal_places'] : 2;
    $symbol = isset($currency['symbol']) ? (string)$currency['symbol'] : '';
    $position = isset($currency['symbol_position']) ? (string)$currency['symbol_position'] : 'before';
    $number = number_format((float)$amount, $decimals, '.', ',');
    if ($symbol === '') return $number;
    return $position === 'after' ? $number . ' ' . $symbol : $symbol . $number;
}
function cvAddress($row, $billing = false)
{
    $prefix = $billing ? 'billing_' : '';
    $parts = array();
    foreach (array('address_line1','address_line2','city','state','postal_code') as $field) {
        $key = $prefix . $field;
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') $parts[] = trim((string)$row[$key]);
    }
    if (!$billing && !empty($row['country_name'])) $parts[] = trim((string)$row['country_name']);
    if ($billing && !empty($row['billing_country_name'])) $parts[] = trim((string)$row['billing_country_name']);
    return implode(', ', $parts);
}
function cvSafeTagColor($value)
{
    $value = trim((string)$value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '';
}

$clientId = isset($_GET['client_id']) && !is_array($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (!empty($_SESSION['id']) ? (int)$_SESSION['id'] : 0);

$loadError = '';
$client = null;
$phones = array();
$locations = array();
$additionalContacts = array();
$clientCustomFields = array();
$locationCustomFields = array();
$communicationPrefs = array('quote_followups'=>1,'invoice_followups'=>1,'visit_reminders'=>1,'job_close_followups'=>1);
$portal = null;
$tags = array();
$allTags = array();
$workItems = array();
$communications = array();
$summaryCounts = array('requests'=>0,'quotes'=>0,'jobs'=>0,'invoices'=>0);
$lifetimeValue = 0.0;
$currentBalance = 0.0;
$latestPaymentTerms = '';
$currency = array('symbol'=>'','symbol_position'=>'before','decimal_places'=>2);
$branchName = '';
$managerName = '';

try {
    if ($clientId <= 0 || $tenantId <= 0) {
        throw new RuntimeException('Invalid client or tenant session.');
    }

    $q = $pdo->prepare("SELECT c.* FROM clients c WHERE c.id=:id AND c.tenant_id=:t AND c.deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id'=>$clientId, ':t'=>$tenantId));
    $client = $q->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new RuntimeException('Client not found.');

    if (cvTable($pdo, 'currencies') && cvTable($pdo, 'tenants')) {
        $q = $pdo->prepare("SELECT COALESCE(c.symbol,'') symbol,COALESCE(c.symbol_position,'before') symbol_position,COALESCE(c.decimal_places,2) decimal_places FROM tenants t LEFT JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1");
        $q->execute(array(':t'=>$tenantId));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) $currency = $row;
    }

    if (!empty($client['branch_id']) && cvTable($pdo, 'branches')) {
        $q = $pdo->prepare("SELECT name FROM branches WHERE id=:id AND tenant_id=:t LIMIT 1");
        $q->execute(array(':id'=>(int)$client['branch_id'], ':t'=>$tenantId));
        $branchName = (string)$q->fetchColumn();
    }
    if (!empty($client['account_manager_id']) && cvTable($pdo, 'users')) {
        $q = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) FROM users WHERE id=:id AND tenant_id=:t LIMIT 1");
        $q->execute(array(':id'=>(int)$client['account_manager_id'], ':t'=>$tenantId));
        $managerName = trim((string)$q->fetchColumn());
    }

    if (cvTable($pdo, 'client_phone_numbers')) {
        $q = $pdo->prepare("SELECT id,phone_number,phone_type,receives_messages,is_primary,sort_order FROM client_phone_numbers WHERE tenant_id=:t AND client_id=:c ORDER BY is_primary DESC,sort_order,id");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        $phones = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$phones) {
        if (!empty($client['phone'])) $phones[] = array('id'=>0,'phone_number'=>$client['phone'],'phone_type'=>'main','receives_messages'=>!empty($client['allow_sms'])?1:0,'is_primary'=>1,'sort_order'=>1);
        if (!empty($client['alternate_phone']) && (string)$client['alternate_phone'] !== (string)$client['phone']) $phones[] = array('id'=>0,'phone_number'=>$client['alternate_phone'],'phone_type'=>'other','receives_messages'=>!empty($client['allow_sms'])?1:0,'is_primary'=>0,'sort_order'=>2);
    }

    if (cvTable($pdo, 'client_contacts')) {
        $hasLocation = cvColumn($pdo, 'client_contacts', 'location_id');
        $where = $hasLocation ? 'AND location_id IS NULL' : '';
        $q = $pdo->prepare("SELECT * FROM client_contacts WHERE tenant_id=:t AND client_id=:c $where ORDER BY is_billing_contact DESC,is_primary DESC,id");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        $additionalContacts = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    if (cvTable($pdo, 'client_locations')) {
        $countryJoin = cvTable($pdo, 'countries') ? "LEFT JOIN countries co ON co.id=cl.country_id LEFT JOIN countries bco ON bco.id=cl.billing_country_id" : '';
        $countrySelect = cvTable($pdo, 'countries') ? ",co.name country_name,bco.name billing_country_name" : ",'' country_name,'' billing_country_name";
        $taxJoin = cvTable($pdo, 'product_tax_rates') && cvColumn($pdo, 'client_locations', 'tax_rate_id') ? "LEFT JOIN product_tax_rates ptr ON ptr.id=cl.tax_rate_id AND ptr.tenant_id=cl.tenant_id" : '';
        $taxSelect = $taxJoin ? ",ptr.tax_name,ptr.rate_percent" : ",NULL tax_name,NULL rate_percent";
        $q = $pdo->prepare("SELECT cl.* $countrySelect $taxSelect FROM client_locations cl $countryJoin $taxJoin WHERE cl.tenant_id=:t AND cl.client_id=:c AND cl.deleted_at IS NULL ORDER BY cl.is_primary DESC,cl.id");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        $locations = $q->fetchAll(PDO::FETCH_ASSOC);

        foreach ($locations as &$location) {
            $location['contacts'] = array();
            $location['custom_values'] = array();
            if (cvTable($pdo, 'client_contacts') && cvColumn($pdo, 'client_contacts', 'location_id')) {
                $cq = $pdo->prepare("SELECT * FROM client_contacts WHERE tenant_id=:t AND client_id=:c AND location_id=:l ORDER BY is_billing_contact DESC,is_primary DESC,id");
                $cq->execute(array(':t'=>$tenantId, ':c'=>$clientId, ':l'=>(int)$location['id']));
                $location['contacts'] = $cq->fetchAll(PDO::FETCH_ASSOC);
            }
            if (cvTable($pdo, 'client_location_custom_field_values')) {
                $vq = $pdo->prepare("SELECT field_id,field_value FROM client_location_custom_field_values WHERE tenant_id=:t AND location_id=:l");
                $vq->execute(array(':t'=>$tenantId, ':l'=>(int)$location['id']));
                foreach ($vq->fetchAll(PDO::FETCH_ASSOC) as $v) $location['custom_values'][(int)$v['field_id']] = (string)$v['field_value'];
            }
        }
        unset($location);
    }

    if (cvTable($pdo, 'client_custom_field_definitions')) {
        $q = $pdo->prepare("SELECT id,applies_to,field_name,field_type,default_value,options_json,sort_order FROM client_custom_field_definitions WHERE tenant_id=:t AND status='active' ORDER BY sort_order,field_name,id");
        $q->execute(array(':t'=>$tenantId));
        $defs = $q->fetchAll(PDO::FETCH_ASSOC);
        $clientValues = array();
        if (cvTable($pdo, 'client_custom_field_values')) {
            $vq = $pdo->prepare("SELECT field_id,field_value FROM client_custom_field_values WHERE tenant_id=:t AND client_id=:c");
            $vq->execute(array(':t'=>$tenantId, ':c'=>$clientId));
            foreach ($vq->fetchAll(PDO::FETCH_ASSOC) as $v) $clientValues[(int)$v['field_id']] = (string)$v['field_value'];
        }
        foreach ($defs as $def) {
            $def['value'] = isset($clientValues[(int)$def['id']]) ? $clientValues[(int)$def['id']] : (string)$def['default_value'];
            if ($def['applies_to'] === 'client') $clientCustomFields[] = $def;
            if ($def['applies_to'] === 'location') $locationCustomFields[] = $def;
        }
    }

    if (cvTable($pdo, 'client_communication_preferences')) {
        $q = $pdo->prepare("SELECT quote_followups,invoice_followups,visit_reminders,job_close_followups FROM client_communication_preferences WHERE tenant_id=:t AND client_id=:c LIMIT 1");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) $communicationPrefs = $row;
    }

    if (cvTable($pdo, 'client_portal_users')) {
        $contactClause = cvColumn($pdo, 'client_portal_users', 'contact_id') ? 'AND contact_id IS NULL' : '';
        $phoneSelect = cvColumn($pdo, 'client_portal_users', 'phone') ? ',phone' : ",NULL phone";
        $q = $pdo->prepare("SELECT id,email,status $phoneSelect FROM client_portal_users WHERE tenant_id=:t AND client_id=:c $contactClause ORDER BY id LIMIT 1");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        $portal = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (cvTable($pdo, 'client_tags')) {
        $q = $pdo->prepare("SELECT id,name,COALESCE(color,'') color FROM client_tags WHERE tenant_id=:t AND is_active=1 ORDER BY name");
        $q->execute(array(':t'=>$tenantId));
        $allTags = $q->fetchAll(PDO::FETCH_ASSOC);
        if (cvTable($pdo, 'client_tag_assignments')) {
            $q = $pdo->prepare("SELECT ct.id,ct.name,COALESCE(ct.color,'') color FROM client_tag_assignments a INNER JOIN client_tags ct ON ct.id=a.tag_id AND ct.tenant_id=a.tenant_id WHERE a.tenant_id=:t AND a.client_id=:c AND ct.is_active=1 ORDER BY ct.name");
            $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
            $tags = $q->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $countMap = array('requests'=>'service_requests','quotes'=>'quotes','jobs'=>'jobs','invoices'=>'invoices');
    foreach ($countMap as $key=>$table) {
        if (!cvTable($pdo, $table)) continue;
        $deleted = cvColumn($pdo, $table, 'deleted_at') ? ' AND deleted_at IS NULL' : '';
        $q = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id=:t AND client_id=:c$deleted");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        $summaryCounts[$key] = (int)$q->fetchColumn();
    }

    if (cvTable($pdo, 'invoices')) {
        $statusWhere = cvColumn($pdo,'invoices','status') ? " AND status NOT IN ('cancelled','archived','written_off')" : '';
        $totalCol = cvColumn($pdo,'invoices','total') ? 'total' : '0';
        $balanceCol = cvColumn($pdo,'invoices','balance_due') ? 'balance_due' : '0';
        $q = $pdo->prepare("SELECT COALESCE(SUM($totalCol),0) lifetime_value,COALESCE(SUM($balanceCol),0) current_balance FROM invoices WHERE tenant_id=:t AND client_id=:c$statusWhere");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $lifetimeValue = (float)$row['lifetime_value'];
        $currentBalance = (float)$row['current_balance'];
        if (cvColumn($pdo,'invoices','payment_terms')) {
            $q = $pdo->prepare("SELECT payment_terms FROM invoices WHERE tenant_id=:t AND client_id=:c AND payment_terms IS NOT NULL AND payment_terms<>'' ORDER BY id DESC LIMIT 1");
            $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
            $latestPaymentTerms = (string)$q->fetchColumn();
        }
    }

    $work = array();
    if (cvTable($pdo,'service_requests')) {
        $no = cvColumn($pdo,'service_requests','request_no') ? 'request_no' : 'id';
        $title = cvColumn($pdo,'service_requests','title') ? 'title' : "'Service Request'";
        $date = cvColumn($pdo,'service_requests','preferred_date') ? 'COALESCE(preferred_date,DATE(created_at))' : 'DATE(created_at)';
        $q = $pdo->prepare("SELECT id item_id,$no item_no,$title title,status,$date activity_date,0 amount,created_at sort_at FROM service_requests WHERE tenant_id=:t AND client_id=:c ORDER BY created_at DESC LIMIT 20");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['item_type']='request'; $work[]=$r; }
    }
    if (cvTable($pdo,'quotes')) {
        $title = cvColumn($pdo,'quotes','title') ? 'COALESCE(NULLIF(title,\'\'),quote_no)' : 'quote_no';
        $total = cvColumn($pdo,'quotes','total') ? 'total' : '0';
        $q = $pdo->prepare("SELECT id item_id,quote_no item_no,$title title,status,DATE(created_at) activity_date,$total amount,created_at sort_at FROM quotes WHERE tenant_id=:t AND client_id=:c ORDER BY created_at DESC LIMIT 20");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['item_type']='quote'; $work[]=$r; }
    }
    if (cvTable($pdo,'jobs')) {
        $date = cvColumn($pdo,'jobs','start_date') ? 'COALESCE(start_date,DATE(created_at))' : 'DATE(created_at)';
        $title = cvColumn($pdo,'jobs','title') ? 'title' : "'Job'";
        $total = cvColumn($pdo,'jobs','total') ? 'total' : '0';
        $deleted = cvColumn($pdo,'jobs','deleted_at') ? ' AND deleted_at IS NULL' : '';
        $q = $pdo->prepare("SELECT id item_id,job_no item_no,$title title,status,$date activity_date,$total amount,created_at sort_at FROM jobs WHERE tenant_id=:t AND client_id=:c$deleted ORDER BY created_at DESC LIMIT 20");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['item_type']='job'; $work[]=$r; }
    }
    if (cvTable($pdo,'invoices')) {
        $date = cvColumn($pdo,'invoices','issue_date') ? 'COALESCE(issue_date,DATE(created_at))' : 'DATE(created_at)';
        $title = cvColumn($pdo,'invoices','subject') ? 'COALESCE(NULLIF(subject,\'\'),invoice_no)' : 'invoice_no';
        $total = cvColumn($pdo,'invoices','total') ? 'total' : '0';
        $q = $pdo->prepare("SELECT id item_id,invoice_no item_no,$title title,status,$date activity_date,$total amount,created_at sort_at FROM invoices WHERE tenant_id=:t AND client_id=:c ORDER BY created_at DESC LIMIT 20");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['item_type']='invoice'; $work[]=$r; }
    }
    usort($work, function($a,$b){ return strcmp((string)$b['sort_at'], (string)$a['sort_at']); });
    $workItems = array_slice($work,0,30);

    if (cvTable($pdo,'message_threads') && cvTable($pdo,'message_thread_messages')) {
        $q = $pdo->prepare("SELECT mt.channel,m.direction,m.sender_label,m.body,m.status,m.created_at FROM message_threads mt INNER JOIN message_thread_messages m ON m.thread_id=mt.id AND m.tenant_id=mt.tenant_id WHERE mt.tenant_id=:t AND mt.client_id=:c ORDER BY m.created_at DESC LIMIT 30");
        $q->execute(array(':t'=>$tenantId, ':c'=>$clientId));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['kind']='message'; $r['sort_at']=$r['created_at']; $communications[]=$r; }
    }
    if (cvTable($pdo,'notification_queue')) {
        $recipientClause = cvColumn($pdo,'notification_queue','recipient_id') ? "AND recipient_id=:c" : "";
        $relatedClause = cvColumn($pdo,'notification_queue','related_type') ? "AND related_type IN ('client','invoice','quote','payment')" : "";
        $q = $pdo->prepare("SELECT 'email' channel,'outbound' direction,'' sender_label,COALESCE(subject,'Email') body,status,COALESCE(sent_at,created_at) created_at FROM notification_queue WHERE tenant_id=:t AND channel='email' $recipientClause $relatedClause ORDER BY COALESCE(sent_at,created_at) DESC LIMIT 30");
        $params = array(':t'=>$tenantId); if ($recipientClause !== '') $params[':c']=$clientId;
        $q->execute($params);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['kind']='email'; $r['sort_at']=$r['created_at']; $communications[]=$r; }
    }
    usort($communications, function($a,$b){ return strcmp((string)$b['sort_at'], (string)$a['sort_at']); });
    $communications = array_slice($communications,0,40);

} catch (Throwable $e) {
    error_log('FieldPlx client view load: ' . $e->getMessage());
    $loadError = $e->getMessage();
}

$displayName = $client && !empty($client['display_name']) ? (string)$client['display_name'] : 'Client';
$status = $client && !empty($client['status']) ? (string)$client['status'] : 'active';
$primaryPhone = '';
$primaryPhoneType = 'Phone';
foreach ($phones as $p) {
    if (!empty($p['is_primary'])) { $primaryPhone=(string)$p['phone_number']; $primaryPhoneType=cvLabel($p['phone_type']) . ' phone'; break; }
}
if ($primaryPhone==='' && $phones) { $primaryPhone=(string)$phones[0]['phone_number']; $primaryPhoneType=cvLabel($phones[0]['phone_type']).' phone'; }
$lastCommunication = $communications ? $communications[0] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= cvH($displayName); ?> - FieldPlx</title>
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
        /* ---------- Client View page tokens ---------- */
        :root{
            --cv-green:#2f8d25;
            --cv-green-soft:#edf6e8;
            --cv-red:#d9534f;
            --cv-navy:#001131;
            --cv-blue:#123d70;
            --cv-border:#dce4e8;
            --cv-muted:#687b88;
            --cv-row:#f8fafb;
        }

        /* ==========================================================
           Client View - Jobber-style content
           ========================================================== */
.cv-page{width:100%;min-height:calc(100vh - 70px);padding:24px 26px 36px;background:#fff}.cv-layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:24px;align-items:start}.cv-main{min-width:0}.cv-aside{display:grid;gap:14px;position:sticky;top:88px}
.cv-header{padding:4px 0 22px;border-bottom:1px solid var(--cv-border)}.cv-header-row{display:flex;align-items:center;gap:10px}.cv-status{display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:999px;background:#edf6e8;color:#2c6b24;font-size:11px}.cv-status::before{content:"";width:7px;height:7px;border-radius:50%;background:#2f8d25}.cv-status.inactive,.cv-status.archived{background:#eef1f3;color:#65737d}.cv-status.inactive::before,.cv-status.archived::before{background:#7b8790}.cv-header-actions{margin-left:auto;display:flex;align-items:center;gap:8px;position:relative}.cv-btn{height:38px;white-space:nowrap;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--cv-border);border-radius:7px;background:#fff;color:#17334f;text-decoration:none;font:600 13px Arial,Helvetica,sans-serif;cursor:pointer}.cv-btn:hover{border-color:#bdd7a1;color:#2f7d22}.cv-btn.icon{width:38px;padding:0}.cv-btn.primary{border-color:#2f8d25;background:#2f8d25;color:#fff}.cv-btn.primary:hover{background:#28791f}.cv-title-row{display:flex;align-items:center;gap:12px;margin-top:12px}.cv-title{margin:0;color:#072b3b;font-size:31px;line-height:1.1;font-weight:700;letter-spacing:-.5px}.cv-edit{margin-left:auto;color:#173f51;text-decoration:none;font-size:18px}.cv-summary{margin-top:18px;display:grid;grid-template-columns:1fr 1fr;column-gap:34px}.cv-summary-item{min-height:46px;padding:9px 0;display:grid;grid-template-columns:165px minmax(0,1fr);align-items:center;border-bottom:1px solid #e3e8eb}.cv-summary-item label{color:#617784;font-size:13px}.cv-summary-item strong,.cv-summary-item a{min-width:0;color:#163644;font-size:13px;font-weight:500;text-decoration:none;overflow-wrap:anywhere}.cv-summary-item a{color:#2f7d22!important;text-decoration:underline!important}
.cv-tabs{display:flex;gap:24px;margin-top:20px;border-bottom:1px solid var(--cv-border)}.cv-tab{padding:12px 8px 11px;border:0;border-bottom:3px solid transparent;background:none;color:#3f5867;font-size:13px;font-weight:600;cursor:pointer}.cv-tab.active{border-bottom-color:#2f8d25;color:#0b3140}.cv-pane{display:none}.cv-pane.active{display:block}.cv-stack{display:grid;gap:16px;margin-top:12px}.cv-card,.cv-side-card{border:1px solid var(--cv-border);border-radius:8px;background:#fff}.cv-card-head,.cv-side-head{min-height:58px;padding:0 14px;display:flex;align-items:center;gap:10px}.cv-card-head{border-bottom:1px solid var(--cv-border)}.cv-card h3,.cv-side-card h3{margin:0;color:#0b3140;font-size:17px;font-weight:700}.cv-card-action{margin-left:auto}.cv-plus{width:34px;height:34px;border:1px solid var(--cv-border);border-radius:7px;background:#fff;color:#2f8d25;font-size:22px;line-height:1;cursor:pointer}.cv-empty{padding:18px;color:#70818c;font-size:13px}
.cv-property{padding:14px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;border-top:1px solid var(--cv-border)}.cv-property:first-of-type{border-top:0}.cv-property-title{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.cv-property strong{font-size:13px}.cv-property small{display:block;margin-top:4px;color:#617784;font-size:12px;line-height:1.4}.cv-mini-actions{display:flex;align-items:center;gap:6px}.cv-mini{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border:0;background:transparent;color:#315566;text-decoration:none;font-size:16px}.cv-property-extra{grid-column:1/-1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 22px;padding-top:10px;border-top:1px dashed #e2e8eb}.cv-kv{display:grid;grid-template-columns:145px minmax(0,1fr);gap:10px;font-size:12px}.cv-kv span:first-child{color:#70818c}.cv-kv span:last-child{color:#173744;overflow-wrap:anywhere}.cv-contact-list{display:grid}.cv-contact{padding:13px 14px;display:grid;grid-template-columns:42px minmax(0,1fr) auto;gap:10px;align-items:center;border-top:1px solid var(--cv-border)}.cv-contact:first-child{border-top:0}.cv-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#f0f8e5;color:#2f8d25;font-weight:700;font-size:12px}.cv-contact-name{font-weight:700;font-size:13px}.cv-contact-meta{margin-top:3px;display:flex;gap:12px;flex-wrap:wrap;color:#647b87;font-size:12px}.cv-badge{display:inline-flex;padding:3px 7px;border-radius:999px;background:#eef3f5;color:#506776;font-size:10px}.cv-badge.green{background:#edf6e8;color:#2f7d22}
.cv-details-grid{padding:13px 14px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 28px}.cv-detail{padding:10px 0;display:grid;grid-template-columns:150px minmax(0,1fr);gap:10px;border-bottom:1px solid #edf0f2}.cv-detail label{color:#6d7f89;font-size:12px}.cv-detail span{font-size:12.5px;overflow-wrap:anywhere}.cv-toggle-list{padding:8px 14px 14px}.cv-toggle-row{padding:8px 0;display:flex;justify-content:space-between;gap:15px;font-size:12.5px}.cv-toggle-state{min-width:42px;padding:3px 8px;border-radius:999px;text-align:center;background:#edf6e8;color:#2f7d22;font-size:10px;font-weight:700}.cv-toggle-state.off{background:#edf1f3;color:#6f7e87}
.cv-chip-row{padding:13px 14px;display:flex;gap:8px;flex-wrap:wrap}.cv-chip{min-height:32px;padding:0 11px;display:inline-flex;align-items:center;gap:6px;border:1px solid var(--cv-border);border-radius:999px;background:#fff;color:#234b5d;text-decoration:none;font-size:11px}.cv-chip.active{background:#e3e1dc;border-color:#e3e1dc}.cv-work-table{width:100%;border-collapse:collapse;table-layout:fixed}.cv-work-table th{height:38px;padding:0 14px;border-bottom:1px solid var(--cv-border);color:#193c4a;text-align:left;font-size:11px;font-weight:700}.cv-work-table td{height:54px;padding:8px 14px;border-bottom:1px solid #edf0f2;font-size:12px;vertical-align:middle}.cv-work-table th:last-child,.cv-work-table td:last-child{text-align:right}.cv-item-link{color:#153a48;font-weight:700;text-decoration:none}.cv-item-link:hover{color:#2f8d25}.cv-item-sub{display:block;margin-top:2px;color:#71818b;font-size:11px}.cv-work-status{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;background:#edf6e8;color:#2f7d22;font-size:10px}.cv-work-status::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.cv-comm{padding:14px;display:grid;grid-template-columns:38px minmax(0,1fr) auto;gap:11px;border-top:1px solid var(--cv-border)}.cv-comm:first-of-type{border-top:0}.cv-comm-icon{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#eef5f8;color:#315b6c}.cv-comm strong{font-size:12.5px}.cv-comm p{margin:4px 0 0;color:#4d6674;font-size:12px;line-height:1.45}.cv-comm-date{color:#7a8992;font-size:11px;white-space:nowrap}
.cv-side-card{padding:14px}.cv-side-head{min-height:0;padding:0}.cv-side-title{font-size:16px!important}.cv-side-stat{margin-top:12px}.cv-side-stat strong{display:block;color:#0b3140;font-size:24px;line-height:1.05}.cv-side-stat span{display:block;margin-top:3px;color:#6b7f89;font-size:11px}.cv-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:11px}.cv-tag{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:#f1f4f5;color:#284956;font-size:11px}.cv-tag-dot{width:7px;height:7px;margin-right:6px;border-radius:50%;background:#6f8792}.cv-last{margin-top:9px;color:#344f5c;font-size:12px;line-height:1.45}.cv-last small{display:block;color:#7b8991;margin-bottom:5px}.cv-link{color:#2f7d22;text-decoration:underline;cursor:pointer;border:0;background:none;padding:0;font:inherit}.cv-notes{width:100%;min-height:145px;margin-top:10px;padding:12px;border:1px dashed #cdd8dd;border-radius:8px;resize:vertical;color:#173744;font:12px Arial,Helvetica,sans-serif}.cv-notes-actions{margin-top:9px;display:flex;justify-content:flex-end}.cv-side-meta{margin-top:8px;display:grid}.cv-side-meta-row{padding:8px 0;border-bottom:1px solid #edf0f2;display:grid;grid-template-columns:95px minmax(0,1fr);gap:8px;font-size:11.5px}.cv-side-meta-row span:first-child{color:#71838c}.cv-side-meta-row span:last-child{overflow-wrap:anywhere}
.cv-menu{position:relative}.cv-dropdown{position:absolute;top:45px;right:0;z-index:40;width:210px;padding:7px;border:1px solid var(--cv-border);border-radius:8px;background:#fff;box-shadow:0 15px 35px rgba(0,17,49,.14);display:none}.cv-dropdown.show{display:block}.cv-dropdown a{min-height:38px;padding:0 10px;display:flex;align-items:center;gap:9px;border-radius:6px;color:#173744;text-decoration:none;font-size:12px}.cv-dropdown a:hover{background:#f3f5f6}.cv-dropdown hr{border:0;border-top:1px solid var(--cv-border);margin:6px 0}
.cv-modal-backdrop{position:fixed;inset:0;z-index:15000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,17,49,.36)}.cv-modal-backdrop.show{display:flex}.cv-modal{width:min(560px,100%);max-height:calc(100vh - 40px);overflow:auto;border-radius:10px;background:#fff;box-shadow:0 24px 70px rgba(0,17,49,.24)}.cv-modal-head{padding:19px 20px 12px;display:flex;align-items:center;gap:12px}.cv-modal-head h3{margin:0;font-size:22px;color:#0b3140}.cv-modal-close{margin-left:auto;width:34px;height:34px;border:0;border-radius:7px;background:#f5f6f7;color:#173744;font-size:20px}.cv-modal-body{padding:8px 20px 18px}.cv-tag-selected{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}.cv-tag-search{width:100%;height:44px;padding:0 12px;border:1px solid var(--cv-border);border-radius:7px;font:13px Arial,Helvetica,sans-serif}.cv-tag-options{margin-top:8px;border:1px solid var(--cv-border);border-radius:8px;max-height:230px;overflow:auto}.cv-tag-option{min-height:43px;padding:0 12px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #edf0f2;cursor:pointer;font-size:12.5px}.cv-tag-option:last-child{border-bottom:0}.cv-tag-option input{accent-color:#2f8d25}.cv-create-tag{margin-top:14px;display:flex;gap:8px}.cv-create-tag input{flex:1;height:40px;padding:0 11px;border:1px solid var(--cv-border);border-radius:7px;font:12px Arial,Helvetica,sans-serif}.cv-modal-footer{padding:13px 20px 18px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #edf0f2}
@media(max-width:1100px){.cv-layout{grid-template-columns:1fr}.cv-aside{position:static;grid-template-columns:repeat(2,minmax(0,1fr))}.cv-summary{grid-template-columns:1fr}.cv-summary-item{grid-template-columns:150px 1fr}}
@media(max-width:760px){.cv-page{padding:16px 13px 26px}.cv-header-actions{width:100%;margin-left:0;margin-top:12px;flex-wrap:wrap}.cv-header-row{flex-wrap:wrap}.cv-title{font-size:26px}.cv-title-row{align-items:flex-start}.cv-summary-item{grid-template-columns:120px 1fr}.cv-aside{grid-template-columns:1fr}.cv-details-grid,.cv-property-extra{grid-template-columns:1fr}.cv-detail,.cv-kv{grid-template-columns:125px 1fr}.cv-work-table thead{display:none}.cv-work-table,.cv-work-table tbody,.cv-work-table tr,.cv-work-table td{display:block;width:100%}.cv-work-table tr{padding:10px 13px;border-bottom:1px solid var(--cv-border)}.cv-work-table td{height:auto;padding:4px 0;border:0;text-align:left!important}.cv-work-table td::before{content:attr(data-label);display:inline-block;width:90px;color:#71818b;font-size:10px}.cv-comm{grid-template-columns:34px minmax(0,1fr)}.cv-comm-date{grid-column:2}.cv-modal-backdrop{padding:10px}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fieldplx-main-content">
<div class="fieldplx-content-wrapper">
<div class="cv-page">
<?php if ($loadError !== ''): ?>
  <div style="padding:18px;border:1px solid #f1c1c1;border-radius:8px;background:#fff6f6;color:#a33"><?= cvH($loadError); ?></div>
<?php else: ?>
<div class="cv-layout">
<section class="cv-main">
  <header class="cv-header">
    <div class="cv-header-row">
      <i class="bi bi-person-circle" style="font-size:18px;color:#355867"></i>
      <span class="cv-status <?= cvH($status); ?>"><?= cvH(cvLabel($status)); ?></span>
      <div class="cv-header-actions">
        <!--<?php if (!empty($client['email'])): ?><a class="cv-btn icon" href="mailto:<?= cvH($client['email']); ?>" title="Email client"><i class="bi bi-envelope"></i></a><?php endif; ?>-->
        <div class="cv-menu">
          <button class="cv-btn" type="button" id="moreButton"><i class="bi bi-three-dots"></i> More</button>
          <div class="cv-dropdown" id="moreMenu">
            <a href="client-form.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-pencil"></i> Edit Client</a>
            <a href="client-locations.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-geo-alt"></i> Manage Properties</a>
            <hr>
            <a href="audit-trail.php?record_type=client&record_id=<?= (int)$clientId; ?>"><i class="bi bi-clock-history"></i> View Audit Trail</a>
          </div>
        </div>
        <div class="cv-menu">
          <button class="cv-btn primary" type="button" id="createButton"><i class="bi bi-plus-lg"></i> Create</button>
          <div class="cv-dropdown" id="createMenu">
            <a href="add-request.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-inbox"></i> Service Request</a>
            <a href="add-quotation.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-file-earmark-text"></i> Quote</a>
            <a href="job-form.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-hammer"></i> Job</a>
            <a href="add-invoice.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-receipt"></i> Invoice</a>
          </div>
        </div>
      </div>
    </div>
    <div class="cv-title-row"><h1 class="cv-title"><?= cvH($displayName); ?></h1><a class="cv-edit" href="client-form.php?client_id=<?= (int)$clientId; ?>" title="Edit client"><i class="bi bi-pencil"></i></a></div>
    <?php if (!empty($client['company_name']) && trim((string)$client['company_name']) !== trim($displayName)): ?><div style="margin-top:6px;color:#6d7f89;font-size:12px"><?= cvH($client['company_name']); ?></div><?php endif; ?>
    <div class="cv-summary">
      <div>
        <div class="cv-summary-item"><label><?= cvH($primaryPhoneType); ?></label><?php if ($primaryPhone!==''): ?><a href="tel:<?= cvH($primaryPhone); ?>"><?= cvH($primaryPhone); ?></a><?php else: ?><strong>—</strong><?php endif; ?></div>
        <div class="cv-summary-item"><label>Work email</label><?php if (!empty($client['email'])): ?><a href="mailto:<?= cvH($client['email']); ?>"><?= cvH($client['email']); ?></a><?php else: ?><strong>—</strong><?php endif; ?></div>
      </div>
      <div>
        <div class="cv-summary-item"><label>Payment terms</label><strong><?= cvH($latestPaymentTerms !== '' ? cvLabel($latestPaymentTerms) : 'Due upon receipt'); ?></strong></div>
        <div class="cv-summary-item"><label>Lead source</label><strong><?= cvH(!empty($client['source']) ? cvLabel($client['source']) : '—'); ?></strong></div>
      </div>
    </div>
    <div class="cv-tabs"><button type="button" class="cv-tab active" data-tab="information">Client information</button><button type="button" class="cv-tab" data-tab="communication">Communication</button></div>
  </header>

  <div class="cv-pane active" id="paneInformation">
    <div class="cv-stack">
      <section class="cv-card">
        <div class="cv-card-head"><h3>Contact information</h3><a class="cv-card-action cv-btn" href="client-form.php?client_id=<?= (int)$clientId; ?>">Edit</a></div>
        <div class="cv-details-grid">
          <div class="cv-detail"><label>Title</label><span><?= cvH(!empty($client['title_prefix']) ? $client['title_prefix'] : '—'); ?></span></div>
          <div class="cv-detail"><label>First name</label><span><?= cvH(!empty($client['first_name']) ? $client['first_name'] : '—'); ?></span></div>
          <div class="cv-detail"><label>Last name</label><span><?= cvH(!empty($client['last_name']) ? $client['last_name'] : '—'); ?></span></div>
          <div class="cv-detail"><label>Company</label><span><?= cvH(!empty($client['company_name']) ? $client['company_name'] : '—'); ?></span></div>
          <div class="cv-detail"><label>Preferred contact</label><span><?= cvH(!empty($client['preferred_contact_method']) ? cvLabel($client['preferred_contact_method']) : '—'); ?></span></div>
          <div class="cv-detail"><label>Tax number</label><span><?= cvH(!empty($client['tax_number']) ? $client['tax_number'] : '—'); ?></span></div>
          <div class="cv-detail"><label>Branch</label><span><?= cvH($branchName !== '' ? $branchName : '—'); ?></span></div>
          <div class="cv-detail"><label>Account manager</label><span><?= cvH($managerName !== '' ? $managerName : '—'); ?></span></div>
          <div class="cv-detail"><label>Client type</label><span><?= cvH(!empty($client['client_type']) ? cvLabel($client['client_type']) : '—'); ?></span></div>
          <div class="cv-detail"><label>Status</label><span><?= cvH(cvLabel($status)); ?></span></div>
          <div class="cv-detail"><label>Created</label><span><?= cvH(cvDate(isset($client['created_at'])?$client['created_at']:null,true)); ?></span></div>
          <div class="cv-detail"><label>Updated</label><span><?= cvH(cvDate(isset($client['updated_at'])?$client['updated_at']:null,true)); ?></span></div>
        </div>
      </section>

      <section class="cv-card">
        <div class="cv-card-head"><h3>Phone numbers</h3><a class="cv-card-action cv-btn" href="client-form.php?client_id=<?= (int)$clientId; ?>">Manage</a></div>
        <?php if (!$phones): ?><div class="cv-empty">No phone numbers saved.</div><?php else: ?><div class="cv-contact-list">
        <?php foreach ($phones as $phone): ?>
          <div class="cv-contact"><div class="cv-avatar"><i class="bi bi-telephone"></i></div><div><div class="cv-contact-name"><a href="tel:<?= cvH($phone['phone_number']); ?>" style="color:inherit;text-decoration:none"><?= cvH($phone['phone_number']); ?></a></div><div class="cv-contact-meta"><span><?= cvH(cvLabel($phone['phone_type'])); ?></span><span><?= !empty($phone['receives_messages']) ? 'Receives messages' : 'Messages off'; ?></span></div></div><div><?php if (!empty($phone['is_primary'])): ?><span class="cv-badge green">Primary</span><?php endif; ?></div></div>
        <?php endforeach; ?></div><?php endif; ?>
      </section>

      <?php if ($clientCustomFields): ?>
      <section class="cv-card"><div class="cv-card-head"><h3>Additional client details</h3></div><div class="cv-details-grid">
        <?php foreach ($clientCustomFields as $field): ?><div class="cv-detail"><label><?= cvH($field['field_name']); ?></label><span><?= cvH(trim((string)$field['value']) !== '' ? $field['value'] : '—'); ?></span></div><?php endforeach; ?>
      </div></section>
      <?php endif; ?>

      <section class="cv-card">
        <div class="cv-card-head"><h3>Additional contacts</h3><a class="cv-card-action cv-btn" href="client-form.php?client_id=<?= (int)$clientId; ?>">Add / Manage</a></div>
        <?php if (!$additionalContacts): ?><div class="cv-empty">No additional contacts saved.</div><?php else: ?><div class="cv-contact-list">
        <?php foreach ($additionalContacts as $contact): $contactName=trim((string)($contact['title_prefix']??'').' '.(string)($contact['first_name']??'').' '.(string)($contact['last_name']??'')); ?>
          <div class="cv-contact"><div class="cv-avatar"><?= cvH(strtoupper(substr(trim((string)($contact['first_name']??'C')),0,1))); ?></div><div><div class="cv-contact-name"><?= cvH($contactName !== '' ? $contactName : 'Contact'); ?></div><div class="cv-contact-meta"><?php if (!empty($contact['role_name'])): ?><span><?= cvH($contact['role_name']); ?></span><?php endif; ?><?php if (!empty($contact['phone'])): ?><a href="tel:<?= cvH($contact['phone']); ?>" style="color:inherit"><?= cvH($contact['phone']); ?></a><?php endif; ?><?php if (!empty($contact['email'])): ?><a href="mailto:<?= cvH($contact['email']); ?>" style="color:inherit"><?= cvH($contact['email']); ?></a><?php endif; ?></div></div><div><?php if (!empty($contact['is_billing_contact'])): ?><span class="cv-badge green">Billing</span><?php endif; ?> <?php if (!empty($contact['portal_access'])): ?><span class="cv-badge">Portal</span><?php endif; ?></div></div>
        <?php endforeach; ?></div><?php endif; ?>
      </section>

      <section class="cv-card">
        <div class="cv-card-head"><h3>Properties</h3><a class="cv-card-action cv-btn" href="client-form.php?client_id=<?= (int)$clientId; ?>#propertySections"><i class="bi bi-plus-lg"></i> Add Address</a></div>
        <?php if (!$locations): ?><div class="cv-empty">No property addresses saved.</div><?php else: ?>
        <?php foreach ($locations as $location): $address=cvAddress($location,false); $billingAddress=!empty($location['billing_same_as_property'])?$address:cvAddress($location,true); ?>
          <div class="cv-property">
            <div><div class="cv-property-title"><strong><?= cvH(!empty($location['name'])?$location['name']:(!empty($location['is_primary'])?'Primary Property':'Property')); ?></strong><?php if (!empty($location['is_primary'])): ?><span class="cv-badge green">Primary</span><?php endif; ?></div><small><?= cvH($address !== '' ? $address : 'No address available'); ?></small></div>
            <div class="cv-mini-actions"><?php if ($address!==''): ?><a class="cv-mini" href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($address); ?>" target="_blank" rel="noopener"><i class="bi bi-geo-alt"></i></a><?php endif; ?><a class="cv-mini" href="client-form.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-pencil"></i></a></div>
            <div class="cv-property-extra">
              <div class="cv-kv"><span>Billing address</span><span><?= cvH($billingAddress !== '' ? $billingAddress : '—'); ?></span></div>
              <div class="cv-kv"><span>Tax rate</span><span><?= cvH(!empty($location['tax_name']) ? $location['tax_name'] . ' (' . rtrim(rtrim(number_format((float)$location['rate_percent'],2,'.',''),'0'),'.') . '%)' : '—'); ?></span></div>
              <?php foreach ($locationCustomFields as $field): $fv=isset($location['custom_values'][(int)$field['id']])?$location['custom_values'][(int)$field['id']]:$field['default_value']; ?><div class="cv-kv"><span><?= cvH($field['field_name']); ?></span><span><?= cvH(trim((string)$fv)!==''?$fv:'—'); ?></span></div><?php endforeach; ?>
              <div class="cv-kv"><span>Property contacts</span><span><?= count($location['contacts']); ?></span></div>
            </div>
            <?php if (!empty($location['contacts'])): ?><div style="grid-column:1/-1;padding-top:4px"><div class="cv-contact-list" style="border:1px solid #edf0f2;border-radius:7px;overflow:hidden"><?php foreach ($location['contacts'] as $contact): $nm=trim((string)($contact['title_prefix']??'').' '.(string)($contact['first_name']??'').' '.(string)($contact['last_name']??'')); ?><div class="cv-contact"><div class="cv-avatar"><?= cvH(strtoupper(substr(trim((string)($contact['first_name']??'C')),0,1))); ?></div><div><div class="cv-contact-name"><?= cvH($nm!==''?$nm:'Contact'); ?></div><div class="cv-contact-meta"><?php if (!empty($contact['role_name'])): ?><span><?= cvH($contact['role_name']); ?></span><?php endif; ?><?php if (!empty($contact['phone'])): ?><span><?= cvH($contact['phone']); ?></span><?php endif; ?><?php if (!empty($contact['email'])): ?><span><?= cvH($contact['email']); ?></span><?php endif; ?></div></div><div><?php if (!empty($contact['is_billing_contact'])): ?><span class="cv-badge green">Billing</span><?php endif; ?></div></div><?php endforeach; ?></div></div><?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </section>

      <section class="cv-card"><div class="cv-card-head"><h3>Communication settings</h3></div><div class="cv-toggle-list">
        <?php $prefLabels=array('quote_followups'=>'Outstanding quote follow-ups','invoice_followups'=>'Overdue invoice follow-ups','visit_reminders'=>'Upcoming assessment or visit reminders','job_close_followups'=>'Job closure follow-ups'); foreach($prefLabels as $key=>$label): $on=!empty($communicationPrefs[$key]); ?><div class="cv-toggle-row"><span><?= cvH($label); ?></span><span class="cv-toggle-state <?= $on?'':'off'; ?>"><?= $on?'ON':'OFF'; ?></span></div><?php endforeach; ?>
        <div class="cv-toggle-row"><span>Client portal</span><span class="cv-toggle-state <?= ($portal && in_array($portal['status'],array('active','invited'),true))?'':'off'; ?>"><?= ($portal && in_array($portal['status'],array('active','invited'),true)) ? cvH(strtoupper($portal['status'])) : 'OFF'; ?></span></div>
      </div></section>

      <section class="cv-card">
        <div class="cv-card-head"><h3>Work overview</h3><button type="button" class="cv-plus" id="workCreateButton">+</button></div>
        <div class="cv-chip-row"><span class="cv-chip active">Status | <?= cvH(cvLabel($status)); ?></span><a class="cv-chip" href="requests.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-inbox"></i> Requests <?= (int)$summaryCounts['requests']; ?></a><a class="cv-chip" href="quotations.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-file-earmark-text"></i> Quotes <?= (int)$summaryCounts['quotes']; ?></a><a class="cv-chip" href="jobs.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-hammer"></i> Jobs <?= (int)$summaryCounts['jobs']; ?></a><a class="cv-chip" href="invoices.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-receipt"></i> Invoices <?= (int)$summaryCounts['invoices']; ?></a></div>
        <table class="cv-work-table"><thead><tr><th style="width:40%">Item</th><th style="width:22%">Date</th><th style="width:20%">Status</th><th style="width:18%">Amount</th></tr></thead><tbody>
        <?php if (!$workItems): ?><tr><td colspan="4" class="cv-empty">No work activity found for this client.</td></tr><?php else: foreach($workItems as $item): $type=$item['item_type']; $url=$type==='request'?'request-view.php?request_id='.(int)$item['item_id']:($type==='quote'?'quote-view.php?quote_id='.(int)$item['item_id']:($type==='job'?'job-view.php?job_id='.(int)$item['item_id']:'invoice-view.php?invoice_id='.(int)$item['item_id'])); ?><tr><td data-label="Item"><a class="cv-item-link" href="<?= cvH($url); ?>"><?= cvH(cvLabel($type).' '.(string)$item['item_no']); ?></a><span class="cv-item-sub"><?= cvH(!empty($item['title'])?$item['title']:'—'); ?></span></td><td data-label="Date"><?= cvH(cvDate($item['activity_date'])); ?></td><td data-label="Status"><span class="cv-work-status"><?= cvH(cvLabel($item['status'])); ?></span></td><td data-label="Amount"><?= cvH(cvMoney($item['amount'],$currency)); ?></td></tr><?php endforeach; endif; ?>
        </tbody></table>
      </section>
    </div>
  </div>

  <div class="cv-pane" id="paneCommunication"><div class="cv-stack"><section class="cv-card"><div class="cv-card-head"><h3>Communication history</h3></div><?php if(!$communications): ?><div class="cv-empty">No communication history found.</div><?php else: foreach($communications as $comm): ?><div class="cv-comm"><div class="cv-comm-icon"><i class="bi <?= $comm['channel']==='sms'?'bi-chat-left-text':'bi-envelope'; ?>"></i></div><div><strong><?= cvH(cvLabel($comm['direction']).' '.cvLabel($comm['channel']).' · '.cvLabel($comm['status'])); ?></strong><p><?= cvH(mb_strimwidth((string)$comm['body'],0,500,'...','UTF-8')); ?></p></div><div class="cv-comm-date"><?= cvH(cvDate($comm['created_at'],true)); ?></div></div><?php endforeach; endif; ?></section></div></div>
</section>

<aside class="cv-aside">
  <section class="cv-side-card"><h3 class="cv-side-title">Overview</h3><div class="cv-side-stat"><strong><?= cvH(cvMoney($lifetimeValue,$currency)); ?></strong><span>Lifetime value</span></div><div class="cv-side-stat"><strong><?= cvH(cvMoney($currentBalance,$currency)); ?></strong><span>Current balance</span></div></section>
  <section class="cv-side-card"><div class="cv-side-head"><h3 class="cv-side-title">Tags</h3><button type="button" class="cv-plus" id="editTagsButton" style="margin-left:auto">+</button></div><div class="cv-tags" id="tagsDisplay"><?php if(!$tags): ?><span style="color:#71818b;font-size:12px">This client has no tags</span><?php else: foreach($tags as $tag): $color=cvSafeTagColor($tag['color']); ?><span class="cv-tag"><span class="cv-tag-dot"<?= $color!==''?' style="background:'.cvH($color).'"':''; ?>></span><?= cvH($tag['name']); ?></span><?php endforeach; endif; ?></div></section>
  <section class="cv-side-card"><h3 class="cv-side-title">Last communication</h3><?php if($lastCommunication): ?><div class="cv-last"><small><?= cvH(cvDate($lastCommunication['created_at'],true)); ?></small><?= cvH(mb_strimwidth((string)$lastCommunication['body'],0,120,'...','UTF-8')); ?></div><button class="cv-link" type="button" id="openCommunication">Read more...</button><?php else: ?><div class="cv-last">No communication history.</div><?php endif; ?></section>
  <section class="cv-side-card"><h3 class="cv-side-title">Notes</h3><textarea class="cv-notes" id="notesInput" maxlength="5000" placeholder="Leave an internal note for yourself or a team member..."><?= cvH(isset($client['notes'])?$client['notes']:''); ?></textarea><div class="cv-notes-actions"><button class="cv-btn primary" type="button" id="saveNotesButton">Save Note</button></div></section>
  <section class="cv-side-card"><h3 class="cv-side-title">Account details</h3><div class="cv-side-meta"><div class="cv-side-meta-row"><span>Client ID</span><span>#<?= (int)$clientId; ?></span></div><div class="cv-side-meta-row"><span>Branch</span><span><?= cvH($branchName!==''?$branchName:'—'); ?></span></div><div class="cv-side-meta-row"><span>Manager</span><span><?= cvH($managerName!==''?$managerName:'—'); ?></span></div><div class="cv-side-meta-row"><span>Portal</span><span><?= cvH($portal?$portal['status']:'Not enabled'); ?></span></div></div></section>
</aside>
</div>
<?php endif; ?>
</div>
</div>
</main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<div class="cv-modal-backdrop" id="tagsModal" aria-hidden="true"><div class="cv-modal" role="dialog" aria-modal="true"><div class="cv-modal-head"><h3>Edit tags for <?= cvH($displayName); ?></h3><button type="button" class="cv-modal-close" id="closeTagsModal">&times;</button></div><div class="cv-modal-body"><div class="cv-tag-selected" id="selectedTagsPreview"></div><input class="cv-tag-search" id="tagSearch" placeholder="Search tags"><div class="cv-tag-options" id="tagOptions"></div><div class="cv-create-tag"><input id="newTagName" maxlength="80" placeholder="Create new tag"><button type="button" class="cv-btn" id="createTagButton"><i class="bi bi-plus-lg"></i> Create</button></div></div><div class="cv-modal-footer"><button type="button" class="cv-btn" id="cancelTagsButton">Cancel</button><button type="button" class="cv-btn primary" id="saveTagsButton">Save</button></div></div></div>

<?php require_once __DIR__ . '/includes/toast.php'; ?>
<script>
(function(){
'use strict';
var clientId=<?= (int)$clientId; ?>;
var csrf=<?= json_encode($clientsCsrfToken); ?>;
var allTags=<?= json_encode($allTags,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?> || [];
var selectedTagIds=<?= json_encode(array_map('intval',array_column($tags,'id'))); ?> || [];
var initialTagIds=selectedTagIds.slice();
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function request(data){var fd=new FormData();Object.keys(data).forEach(function(k){fd.append(k,data[k]);});fd.append('csrf_token',csrf);return fetch('api/client-view.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json().catch(function(){throw new Error('Invalid server response.');}).then(function(j){if(!r.ok||!j.success)throw new Error(j.message||'Request failed.');return j;});});}
function toggleMenu(btnId,menuId){var btn=document.getElementById(btnId),menu=document.getElementById(menuId);if(!btn||!menu)return;btn.addEventListener('click',function(e){e.stopPropagation();document.querySelectorAll('.cv-dropdown.show').forEach(function(x){if(x!==menu)x.classList.remove('show');});menu.classList.toggle('show');});}
toggleMenu('moreButton','moreMenu');toggleMenu('createButton','createMenu');document.addEventListener('click',function(e){if(!e.target.closest('.cv-menu'))document.querySelectorAll('.cv-dropdown.show').forEach(function(x){x.classList.remove('show');});});
document.querySelectorAll('[data-tab]').forEach(function(btn){btn.addEventListener('click',function(){var name=btn.getAttribute('data-tab');document.querySelectorAll('[data-tab]').forEach(function(b){b.classList.toggle('active',b===btn);});document.getElementById('paneInformation').classList.toggle('active',name==='information');document.getElementById('paneCommunication').classList.toggle('active',name==='communication');});});
var openComm=document.getElementById('openCommunication');if(openComm)openComm.addEventListener('click',function(){var b=document.querySelector('[data-tab="communication"]');if(b)b.click();window.scrollTo({top:0,behavior:'smooth'});});
var workCreate=document.getElementById('workCreateButton');if(workCreate)workCreate.addEventListener('click',function(){document.getElementById('createButton').click();});
var notesBtn=document.getElementById('saveNotesButton');if(notesBtn)notesBtn.addEventListener('click',function(){notesBtn.disabled=true;request({action:'save_notes',client_id:clientId,notes:document.getElementById('notesInput').value}).then(function(d){fieldplxToast('success',d.message||'Client note saved.');}).catch(function(e){fieldplxToast('error',e.message);}).finally(function(){notesBtn.disabled=false;});});
var modal=document.getElementById('tagsModal');
function renderTagOptions(){var q=String(document.getElementById('tagSearch').value||'').toLowerCase().trim();var html='';allTags.forEach(function(t){if(q&&String(t.name).toLowerCase().indexOf(q)===-1)return;var checked=selectedTagIds.indexOf(Number(t.id))!==-1;html+='<label class="cv-tag-option"><input type="checkbox" data-tag-id="'+Number(t.id)+'" '+(checked?'checked':'')+'><span>'+esc(t.name)+'</span></label>';});document.getElementById('tagOptions').innerHTML=html||'<div class="cv-empty">No matching tags</div>';var selected=allTags.filter(function(t){return selectedTagIds.indexOf(Number(t.id))!==-1;});document.getElementById('selectedTagsPreview').innerHTML=selected.length?selected.map(function(t){return '<span class="cv-tag">'+esc(t.name)+'</span>';}).join(''):'<span style="color:#71818b;font-size:12px">No tags selected</span>';}
function openTags(){selectedTagIds=initialTagIds.slice();document.getElementById('tagSearch').value='';renderTagOptions();modal.classList.add('show');modal.setAttribute('aria-hidden','false');}
function closeTags(){modal.classList.remove('show');modal.setAttribute('aria-hidden','true');}
var editTags=document.getElementById('editTagsButton');if(editTags)editTags.addEventListener('click',openTags);document.getElementById('closeTagsModal').addEventListener('click',closeTags);document.getElementById('cancelTagsButton').addEventListener('click',closeTags);modal.addEventListener('click',function(e){if(e.target===modal)closeTags();});document.getElementById('tagSearch').addEventListener('input',renderTagOptions);document.getElementById('tagOptions').addEventListener('change',function(e){if(!e.target.matches('[data-tag-id]'))return;var id=Number(e.target.getAttribute('data-tag-id'));if(e.target.checked){if(selectedTagIds.indexOf(id)===-1)selectedTagIds.push(id);}else{selectedTagIds=selectedTagIds.filter(function(x){return x!==id;});}renderTagOptions();});
document.getElementById('createTagButton').addEventListener('click',function(){var input=document.getElementById('newTagName'),name=input.value.trim();if(!name){fieldplxToast('warning','Enter a tag name.');return;}var btn=this;btn.disabled=true;request({action:'create_tag',client_id:clientId,name:name}).then(function(d){if(d.tag){allTags.push(d.tag);selectedTagIds.push(Number(d.tag.id));input.value='';renderTagOptions();}fieldplxToast('success',d.message||'Tag created.');}).catch(function(e){fieldplxToast('error',e.message);}).finally(function(){btn.disabled=false;});});
document.getElementById('saveTagsButton').addEventListener('click',function(){var btn=this;btn.disabled=true;request({action:'save_tags',client_id:clientId,tag_ids:JSON.stringify(selectedTagIds)}).then(function(d){fieldplxToast('success',d.message||'Tags updated.');setTimeout(function(){window.location.reload();},350);}).catch(function(e){fieldplxToast('error',e.message);}).finally(function(){btn.disabled=false;});});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeTags();document.querySelectorAll('.cv-dropdown.show').forEach(function(x){x.classList.remove('show');});}});
<?php if ($loadError !== ''): ?>fieldplxToast('error',<?= json_encode($loadError); ?>,5000);<?php endif; ?>
})();
</script>
</body>
</html>
