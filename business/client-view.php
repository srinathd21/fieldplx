<?php
/* FieldPlx Client View - Version 4.4.0 - Jobber-inspired client workspace */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Client View';
$pageDescription = 'Customer profile, work, billing, schedule and communication';
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

function cvShortDate($value)
{
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    return $ts === false ? '—' : date('M d, Y', $ts);
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

function cvAddress($row)
{
    $parts = array();
    foreach (array('address_line1','address_line2','city','state','postal_code') as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== '') $parts[] = trim((string)$row[$field]);
    }
    return implode(', ', $parts);
}

function cvSafeColor($value)
{
    $value = trim((string)$value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '';
}

function cvTone($status)
{
    $s = strtolower(trim((string)$status));
    if (in_array($s, array('overdue','late','past_due','failed','cancelled','blocked','rejected'), true)) return 'danger';
    if (in_array($s, array('unscheduled','pending','waiting','draft','new','assessment_required'), true)) return 'warning';
    if (in_array($s, array('completed','paid','succeeded','approved','active','sent','delivered','assessment_complete'), true)) return 'success';
    if (in_array($s, array('in_progress','scheduled','accepted','travelling','arrived','viewed','partially_paid'), true)) return 'info';
    return 'neutral';
}

function cvPlainBody($value)
{
    $value = (string)$value;
    $value = preg_replace('/<br\s*\/?>/i', "\n", $value);
    $value = preg_replace('/<\/p>/i', "\n\n", $value);
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8');
    $value = preg_replace("/[\t ]+\n/", "\n", $value);
    $value = preg_replace("/\n{3,}/", "\n\n", $value);
    return trim((string)$value);
}

function cvParseLoggedMail($body)
{
    $body = (string)$body;
    $subject = '';
    $message = $body;
    if (preg_match('/^Subject:\s*(.*?)\R\R(.*)$/s', $body, $m)) {
        $subject = trim($m[1]);
        $message = trim($m[2]);
    }
    return array($subject, $message);
}

function cvUserContext(PDO $pdo, $tenantId, $userId)
{
    $out = array('role_id'=>0,'is_tenant_admin'=>0,'is_role_admin'=>0,'branch_id'=>0,'email'=>'','name'=>'FieldPlx User');
    if ($userId <= 0 || !cvTable($pdo, 'users')) return $out;
    $roleJoin = cvTable($pdo, 'roles') && cvColumn($pdo, 'users', 'role_id') ? "LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id" : '';
    $roleAdmin = cvTable($pdo, 'roles') && cvColumn($pdo, 'roles', 'is_admin') ? 'COALESCE(r.is_admin,0)' : '0';
    $deleted = cvColumn($pdo, 'users', 'deleted_at') ? ' AND u.deleted_at IS NULL' : '';
    $q = $pdo->prepare("SELECT " . (cvColumn($pdo,'users','role_id')?'u.role_id':'0 AS role_id') . ", "
        . (cvColumn($pdo,'users','is_tenant_admin')?'u.is_tenant_admin':'0 AS is_tenant_admin') . ", "
        . (cvColumn($pdo,'users','branch_id')?'u.branch_id':'0 AS branch_id') . ", u.email,
           TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) user_name, $roleAdmin is_role_admin
           FROM users u $roleJoin WHERE u.id=:u AND u.tenant_id=:t$deleted LIMIT 1");
    $q->execute(array(':u'=>$userId, ':t'=>$tenantId));
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $out['role_id']=(int)$r['role_id'];
        $out['is_tenant_admin']=(int)$r['is_tenant_admin'];
        $out['is_role_admin']=(int)$r['is_role_admin'];
        $out['branch_id']=(int)$r['branch_id'];
        $out['email']=trim((string)$r['email']);
        $out['name']=trim((string)$r['user_name'])!==''?trim((string)$r['user_name']):'FieldPlx User';
    }
    return $out;
}

function cvHasPermission(PDO $pdo, $tenantId, $userId, $permissionCode, $ctx)
{
    if (!empty($ctx['is_tenant_admin']) || !empty($ctx['is_role_admin'])) return true;
    if (!cvTable($pdo, 'permissions')) return false;
    $q=$pdo->prepare("SELECT id FROM permissions WHERE permission_code=:p LIMIT 1");
    $q->execute(array(':p'=>$permissionCode));
    $permissionId=(int)$q->fetchColumn();
    if ($permissionId<=0) return false;
    if (cvTable($pdo,'user_permissions')) {
        $q=$pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':u'=>$userId,':p'=>$permissionId));
        $v=$q->fetchColumn();
        if ($v!==false) return $v==='allow';
    }
    if (!empty($ctx['role_id']) && cvTable($pdo,'role_permissions')) {
        $q=$pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':r'=>(int)$ctx['role_id'],':p'=>$permissionId));
        return $q->fetchColumn()==='allow';
    }
    return false;
}

$clientId = isset($_GET['client_id']) && !is_array($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = !empty($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (!empty($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$sessionBranchId = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

$loadError = '';
$client = null;
$ctx = array();
$canUpdate = false;
$canDelete = false;
$phones = array();
$locations = array();
$additionalContacts = array();
$tags = array();
$allTags = array();
$portal = null;
$currency = array('symbol'=>'','symbol_position'=>'before','decimal_places'=>2);
$branchName = '';
$managerName = '';
$lifetimeValue = 0.0;
$currentBalance = 0.0;
$latestPaymentTerms = '';
$summaryCounts = array('requests'=>0,'quotes'=>0,'jobs'=>0,'invoices'=>0);
$workItems = array();
$billingItems = array();
$scheduleItems = array();
$recentPricing = array();
$communications = array();
$users = array();
$mentionUsers = array();
$teams = array();
$clientCustomFields = array();
$communicationPrefs = array('quote_followups'=>1,'invoice_followups'=>1,'visit_reminders'=>1,'job_close_followups'=>1);
$noteAttachments = array();

try {
    if ($clientId <= 0 || $tenantId <= 0 || $userId <= 0) {
        throw new RuntimeException('Invalid client or tenant session.');
    }

    $ctx = cvUserContext($pdo, $tenantId, $userId);
    if (!cvHasPermission($pdo, $tenantId, $userId, 'clients.view', $ctx)) {
        http_response_code(403);
        throw new RuntimeException('You do not have permission to view this client.');
    }
    $canUpdate = cvHasPermission($pdo, $tenantId, $userId, 'clients.update', $ctx);
    $canDelete = cvHasPermission($pdo, $tenantId, $userId, 'clients.delete', $ctx);

    $q = $pdo->prepare("SELECT * FROM clients WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id'=>$clientId, ':t'=>$tenantId));
    $client = $q->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new RuntimeException('Client not found.');

    if (empty($ctx['is_tenant_admin']) && empty($ctx['is_role_admin']) && !empty($ctx['branch_id']) && !empty($client['branch_id']) && (int)$ctx['branch_id'] !== (int)$client['branch_id']) {
        http_response_code(403);
        throw new RuntimeException('This client is outside your branch access.');
    }

    if (cvTable($pdo,'currencies') && cvTable($pdo,'tenants')) {
        $q=$pdo->prepare("SELECT COALESCE(c.symbol,'') symbol,COALESCE(c.symbol_position,'before') symbol_position,COALESCE(c.decimal_places,2) decimal_places
                          FROM tenants t LEFT JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1");
        $q->execute(array(':t'=>$tenantId));
        $r=$q->fetch(PDO::FETCH_ASSOC); if($r)$currency=$r;
    }

    if (!empty($client['branch_id']) && cvTable($pdo,'branches')) {
        $q=$pdo->prepare("SELECT name FROM branches WHERE id=:id AND tenant_id=:t LIMIT 1");
        $q->execute(array(':id'=>(int)$client['branch_id'],':t'=>$tenantId));
        $branchName=(string)$q->fetchColumn();
    }
    if (!empty($client['account_manager_id']) && cvTable($pdo,'users')) {
        $q=$pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) FROM users WHERE id=:id AND tenant_id=:t LIMIT 1");
        $q->execute(array(':id'=>(int)$client['account_manager_id'],':t'=>$tenantId));
        $managerName=trim((string)$q->fetchColumn());
    }

    if (cvTable($pdo,'client_phone_numbers')) {
        $q=$pdo->prepare("SELECT id,phone_number,phone_type,receives_messages,is_primary,sort_order FROM client_phone_numbers WHERE tenant_id=:t AND client_id=:c ORDER BY is_primary DESC,sort_order,id");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $phones=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$phones) {
        if (!empty($client['phone'])) $phones[]=array('id'=>0,'phone_number'=>$client['phone'],'phone_type'=>'main','receives_messages'=>!empty($client['allow_sms'])?1:0,'is_primary'=>1,'sort_order'=>1);
        if (!empty($client['alternate_phone']) && (string)$client['alternate_phone']!==(string)$client['phone']) $phones[]=array('id'=>0,'phone_number'=>$client['alternate_phone'],'phone_type'=>'other','receives_messages'=>!empty($client['allow_sms'])?1:0,'is_primary'=>0,'sort_order'=>2);
    }

    if (cvTable($pdo,'client_locations')) {
        $countryJoin = cvTable($pdo,'countries') ? "LEFT JOIN countries co ON co.id=cl.country_id" : '';
        $countrySelect = cvTable($pdo,'countries') ? ",COALESCE(co.name,'') country_name" : ",'' country_name";
        $taxJoin = cvTable($pdo,'product_tax_rates') && cvColumn($pdo,'client_locations','tax_rate_id') ? "LEFT JOIN product_tax_rates tr ON tr.id=cl.tax_rate_id AND tr.tenant_id=cl.tenant_id" : '';
        $taxSelect = $taxJoin ? ",tr.tax_name,tr.rate_percent" : ",NULL tax_name,NULL rate_percent";
        $q=$pdo->prepare("SELECT cl.* $countrySelect $taxSelect FROM client_locations cl $countryJoin $taxJoin WHERE cl.tenant_id=:t AND cl.client_id=:c AND cl.deleted_at IS NULL ORDER BY cl.is_primary DESC,cl.id");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $locations=$q->fetchAll(PDO::FETCH_ASSOC);
    }

    if (cvTable($pdo,'client_contacts')) {
        $where = cvColumn($pdo,'client_contacts','location_id') ? ' AND location_id IS NULL' : '';
        $q=$pdo->prepare("SELECT * FROM client_contacts WHERE tenant_id=:t AND client_id=:c$where ORDER BY is_primary DESC,is_billing_contact DESC,id");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $additionalContacts=$q->fetchAll(PDO::FETCH_ASSOC);
    }

    if (cvTable($pdo,'client_tags')) {
        $q=$pdo->prepare("SELECT id,name,COALESCE(color,'') color FROM client_tags WHERE tenant_id=:t AND is_active=1 ORDER BY name,id");
        $q->execute(array(':t'=>$tenantId));
        $allTags=$q->fetchAll(PDO::FETCH_ASSOC);
        if (cvTable($pdo,'client_tag_assignments')) {
            $q=$pdo->prepare("SELECT ct.id,ct.name,COALESCE(ct.color,'') color FROM client_tag_assignments a INNER JOIN client_tags ct ON ct.id=a.tag_id AND ct.tenant_id=a.tenant_id WHERE a.tenant_id=:t AND a.client_id=:c AND ct.is_active=1 ORDER BY ct.name,ct.id");
            $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
            $tags=$q->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (cvTable($pdo,'client_portal_users')) {
        $contactWhere = cvColumn($pdo,'client_portal_users','contact_id') ? ' AND contact_id IS NULL' : '';
        $q=$pdo->prepare("SELECT * FROM client_portal_users WHERE tenant_id=:t AND client_id=:c$contactWhere ORDER BY id LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $portal=$q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (cvTable($pdo,'client_communication_preferences')) {
        $q=$pdo->prepare("SELECT quote_followups,invoice_followups,visit_reminders,job_close_followups FROM client_communication_preferences WHERE tenant_id=:t AND client_id=:c LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $r=$q->fetch(PDO::FETCH_ASSOC); if($r)$communicationPrefs=$r;
    }

    if (cvTable($pdo,'client_custom_field_definitions')) {
        $q=$pdo->prepare("SELECT id,field_name,default_value FROM client_custom_field_definitions WHERE tenant_id=:t AND applies_to='client' AND status='active' ORDER BY sort_order,field_name,id");
        $q->execute(array(':t'=>$tenantId));
        $defs=$q->fetchAll(PDO::FETCH_ASSOC);
        $vals=array();
        if (cvTable($pdo,'client_custom_field_values')) {
            $v=$pdo->prepare("SELECT field_id,field_value FROM client_custom_field_values WHERE tenant_id=:t AND client_id=:c");
            $v->execute(array(':t'=>$tenantId,':c'=>$clientId));
            foreach($v->fetchAll(PDO::FETCH_ASSOC) as $row)$vals[(int)$row['field_id']]=(string)$row['field_value'];
        }
        foreach($defs as $def){$def['value']=isset($vals[(int)$def['id']])?$vals[(int)$def['id']]:(string)$def['default_value'];$clientCustomFields[]=$def;}
    }

    $countMap=array('requests'=>'service_requests','quotes'=>'quotes','jobs'=>'jobs','invoices'=>'invoices');
    foreach($countMap as $key=>$table){
        if(!cvTable($pdo,$table))continue;
        $deleted=cvColumn($pdo,$table,'deleted_at')?' AND deleted_at IS NULL':'';
        $q=$pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id=:t AND client_id=:c$deleted");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $summaryCounts[$key]=(int)$q->fetchColumn();
    }

    if (cvTable($pdo,'payments')) {
        $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE tenant_id=:t AND client_id=:c AND status='succeeded'");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $lifetimeValue=(float)$q->fetchColumn();
    } elseif (cvTable($pdo,'invoices') && cvColumn($pdo,'invoices','amount_paid')) {
        $q=$pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM invoices WHERE tenant_id=:t AND client_id=:c AND status NOT IN ('cancelled','archived','written_off')");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $lifetimeValue=(float)$q->fetchColumn();
    }

    if (cvTable($pdo,'invoices')) {
        $q=$pdo->prepare("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE tenant_id=:t AND client_id=:c AND status NOT IN ('cancelled','archived','written_off')");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $currentBalance=(float)$q->fetchColumn();
        if (cvColumn($pdo,'invoices','payment_terms')) {
            $q=$pdo->prepare("SELECT payment_terms FROM invoices WHERE tenant_id=:t AND client_id=:c AND payment_terms IS NOT NULL AND payment_terms<>'' ORDER BY id DESC LIMIT 1");
            $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
            $latestPaymentTerms=trim((string)$q->fetchColumn());
        }
        $q=$pdo->prepare("SELECT id,invoice_no,subject,status,issue_date,due_date,total,amount_paid,balance_due,created_at FROM invoices WHERE tenant_id=:t AND client_id=:c AND status NOT IN ('cancelled','archived','written_off') ORDER BY COALESCE(issue_date,DATE(created_at)) DESC,id DESC LIMIT 20");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $billingItems=$q->fetchAll(PDO::FETCH_ASSOC);
    }

    /* Work overview */
    $work=array();
    if(cvTable($pdo,'service_requests')){
        $q=$pdo->prepare("SELECT id item_id,request_no item_no,title,status,COALESCE(preferred_date,DATE(created_at)) activity_date,0 amount,created_at sort_at FROM service_requests WHERE tenant_id=:t AND client_id=:c ORDER BY created_at DESC LIMIT 30");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['item_type']='request';$r['date_caption']=!empty($r['activity_date'])?'Created / requested':'Created';$work[]=$r;}
    }
    if(cvTable($pdo,'quotes')){
        $q=$pdo->prepare("SELECT id item_id,quote_no item_no,COALESCE(NULLIF(title,''),quote_no) title,status,DATE(created_at) activity_date,total amount,created_at sort_at FROM quotes WHERE tenant_id=:t AND client_id=:c ORDER BY created_at DESC LIMIT 30");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['item_type']='quote';$r['date_caption']='Created at';$work[]=$r;}
    }
    if(cvTable($pdo,'jobs')){
        $deleted=cvColumn($pdo,'jobs','deleted_at')?' AND deleted_at IS NULL':'';
        $q=$pdo->prepare("SELECT id item_id,job_no item_no,title,status,COALESCE(start_date,DATE(created_at)) activity_date,total amount,created_at sort_at FROM jobs WHERE tenant_id=:t AND client_id=:c$deleted ORDER BY created_at DESC LIMIT 30");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['item_type']='job';$r['date_caption']='Scheduled for';$work[]=$r;}
    }
    if(cvTable($pdo,'invoices')){
        $q=$pdo->prepare("SELECT id item_id,invoice_no item_no,COALESCE(NULLIF(subject,''),invoice_no) title,status,COALESCE(due_date,issue_date,DATE(created_at)) activity_date,total amount,created_at sort_at,balance_due FROM invoices WHERE tenant_id=:t AND client_id=:c ORDER BY created_at DESC LIMIT 30");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['item_type']='invoice';$r['date_caption']=!empty($r['activity_date'])?'Due / issued':'Created';$work[]=$r;}
    }
    usort($work,function($a,$b){return strcmp((string)$b['sort_at'],(string)$a['sort_at']);});
    $workItems=array_slice($work,0,40);

    /* Client schedule: unscheduled requests + visits + tasks. */
    if(cvTable($pdo,'service_requests')){
        $timeFrom=cvColumn($pdo,'service_requests','preferred_time_from')?'preferred_time_from':'NULL AS preferred_time_from';
        $timeTo=cvColumn($pdo,'service_requests','preferred_time_to')?'preferred_time_to':'NULL AS preferred_time_to';
        $assigned=cvColumn($pdo,'service_requests','assigned_user_id')?'assigned_user_id':'NULL AS assigned_user_id';
        $q=$pdo->prepare("SELECT id,request_no ref_no,title,status,preferred_date,$timeFrom,$timeTo,$assigned,created_at FROM service_requests WHERE tenant_id=:t AND client_id=:c ORDER BY COALESCE(preferred_date,'9999-12-31'),id DESC LIMIT 40");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $when=null;
            if(!empty($r['preferred_date']))$when=$r['preferred_date'].' '.(!empty($r['preferred_time_from'])?$r['preferred_time_from']:'00:00:00');
            $scheduleItems[]=array('id'=>(int)$r['id'],'type'=>'request','ref_no'=>$r['ref_no'],'title'=>$r['title'],'status'=>$r['status'],'scheduled_start'=>$when,'scheduled_end'=>null,'assigned_user_id'=>(int)$r['assigned_user_id'],'assigned_team_id'=>0,'sort_key'=>$when?:'0000-00-00 00:00:00');
        }
    }
    if(cvTable($pdo,'visits') && cvTable($pdo,'jobs')){
        $deleted=cvColumn($pdo,'jobs','deleted_at')?' AND j.deleted_at IS NULL':'';
        $q=$pdo->prepare("SELECT v.id,v.visit_no ref_no,COALESCE(NULLIF(v.title,''),CONCAT('Visit for ',j.job_no,' - ',j.title)) title,v.status,v.scheduled_start,v.scheduled_end,v.assigned_user_id,v.assigned_team_id
                          FROM visits v INNER JOIN jobs j ON j.id=v.job_id AND j.tenant_id=v.tenant_id
                          WHERE v.tenant_id=:t AND j.client_id=:c$deleted ORDER BY COALESCE(v.scheduled_start,'9999-12-31') ASC,v.id DESC LIMIT 60");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['type']='visit';$r['sort_key']=$r['scheduled_start']?:'0000-00-00 00:00:00';$scheduleItems[]=$r;}
    }
    if(cvTable($pdo,'tasks')){
        $q=$pdo->prepare("SELECT id,CONCAT('TASK-',LPAD(id,6,'0')) ref_no,title,status,scheduled_start,scheduled_end,assigned_user_id,assigned_team_id,schedule_later,created_at FROM tasks WHERE tenant_id=:t AND client_id=:c ORDER BY COALESCE(scheduled_start,'9999-12-31') ASC,id DESC LIMIT 50");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['type']='task';$r['sort_key']=$r['scheduled_start']?:'0000-00-00 00:00:00';$scheduleItems[]=$r;}
    }

    $userNames=array();$teamNames=array();
    if(cvTable($pdo,'users')){
        $deleted=cvColumn($pdo,'users','deleted_at')?' AND deleted_at IS NULL':'';
        $branchFilter=!empty($client['branch_id']) && cvColumn($pdo,'users','branch_id')?' AND (branch_id IS NULL OR branch_id=:b)':'';
        $q=$pdo->prepare("SELECT id,TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) name,email FROM users WHERE tenant_id=:t AND status='active'$deleted$branchFilter ORDER BY first_name,last_name,id");
        $params=array(':t'=>$tenantId);if($branchFilter!=='')$params[':b']=(int)$client['branch_id'];
        $q->execute($params);$users=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($users as $u)$userNames[(int)$u['id']]=trim((string)$u['name'])!==''?(string)$u['name']:(string)$u['email'];
    }
    if(cvTable($pdo,'users')){
        $deleted=cvColumn($pdo,'users','deleted_at')?' AND deleted_at IS NULL':'';
        $mq=$pdo->prepare("SELECT id,TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) name,email FROM users WHERE tenant_id=:t AND status='active'$deleted ORDER BY first_name,last_name,id");
        $mq->execute(array(':t'=>$tenantId));
        $mentionUsers=$mq->fetchAll(PDO::FETCH_ASSOC);
    }
    if(cvTable($pdo,'teams')){
        $branchFilter=!empty($client['branch_id']) && cvColumn($pdo,'teams','branch_id')?' AND (branch_id IS NULL OR branch_id=:b)':'';
        $q=$pdo->prepare("SELECT id,name FROM teams WHERE tenant_id=:t AND status='active'$branchFilter ORDER BY name,id");
        $params=array(':t'=>$tenantId);if($branchFilter!=='')$params[':b']=(int)$client['branch_id'];
        $q->execute($params);$teams=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($teams as $t)$teamNames[(int)$t['id']]=(string)$t['name'];
    }
    foreach($scheduleItems as &$si){
        $si['assigned_name']='—';
        if(!empty($si['assigned_user_id']) && isset($userNames[(int)$si['assigned_user_id']]))$si['assigned_name']=$userNames[(int)$si['assigned_user_id']];
        elseif(!empty($si['assigned_team_id']) && isset($teamNames[(int)$si['assigned_team_id']]))$si['assigned_name']=$teamNames[(int)$si['assigned_team_id']];
    }
    unset($si);
    usort($scheduleItems,function($a,$b){
        $au=empty($a['scheduled_start']);$bu=empty($b['scheduled_start']);
        if($au!==$bu)return $au?-1:1;
        return strcmp((string)$a['sort_key'],(string)$b['sort_key']);
    });
    $scheduleItems=array_slice($scheduleItems,0,50);

    /* Recent pricing from the latest quote/job line items. */
    $pricingMap=array();
    if(cvTable($pdo,'quote_line_items') && cvTable($pdo,'quotes')){
        $q=$pdo->prepare("SELECT qli.item_name,qli.unit_price,q.created_at,q.id quote_id,q.quote_no FROM quote_line_items qli INNER JOIN quotes q ON q.id=qli.quote_id WHERE q.tenant_id=:t AND q.client_id=:c ORDER BY q.created_at DESC,qli.id DESC LIMIT 100");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$key=mb_strtolower(trim((string)$r['item_name']),'UTF-8');if($key===''||isset($pricingMap[$key]['quoted']))continue;$pricingMap[$key]['name']=$r['item_name'];$pricingMap[$key]['quoted']=array('price'=>(float)$r['unit_price'],'date'=>$r['created_at'],'id'=>(int)$r['quote_id'],'no'=>$r['quote_no']);$pricingMap[$key]['latest']=$r['created_at'];}
    }
    if(cvTable($pdo,'job_line_items') && cvTable($pdo,'jobs')){
        $deleted=cvColumn($pdo,'jobs','deleted_at')?' AND j.deleted_at IS NULL':'';
        $q=$pdo->prepare("SELECT jli.item_name,jli.unit_price,j.created_at,j.id job_id,j.job_no FROM job_line_items jli INNER JOIN jobs j ON j.id=jli.job_id AND j.tenant_id=jli.tenant_id WHERE j.tenant_id=:t AND j.client_id=:c$deleted ORDER BY j.created_at DESC,jli.id DESC LIMIT 100");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$key=mb_strtolower(trim((string)$r['item_name']),'UTF-8');if($key==='')continue;if(!isset($pricingMap[$key]))$pricingMap[$key]=array('name'=>$r['item_name'],'latest'=>$r['created_at']);if(!isset($pricingMap[$key]['job']))$pricingMap[$key]['job']=array('price'=>(float)$r['unit_price'],'date'=>$r['created_at'],'id'=>(int)$r['job_id'],'no'=>$r['job_no']);if(empty($pricingMap[$key]['latest'])||strcmp($r['created_at'],$pricingMap[$key]['latest'])>0)$pricingMap[$key]['latest']=$r['created_at'];}
    }
    $recentPricing=array_values($pricingMap);
    usort($recentPricing,function($a,$b){return strcmp((string)$b['latest'],(string)$a['latest']);});
    $recentPricing=array_slice($recentPricing,0,12);

    /* Communications: manual messages and queued system emails. */
    $loadedMessageIds=array();
    if(cvTable($pdo,'message_threads') && cvTable($pdo,'message_thread_messages')){
        $q=$pdo->prepare("SELECT m.id,mt.channel,m.direction,m.sender_label,m.body,m.status,m.created_at FROM message_threads mt INNER JOIN message_thread_messages m ON m.thread_id=mt.id AND m.tenant_id=mt.tenant_id WHERE mt.tenant_id=:t AND mt.client_id=:c ORDER BY m.created_at DESC,m.id DESC LIMIT 80");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$mid=(int)$r['id'];$loadedMessageIds[$mid]=true;list($sub,$body)=cvParseLoggedMail($r['body']);$communications[]=array('id'=>'m'.$mid,'message_id'=>$mid,'channel'=>$r['channel'],'direction'=>$r['direction'],'status'=>$r['status'],'created_at'=>$r['created_at'],'to_email'=>!empty($client['email'])?$client['email']:'','subject'=>$sub!==''?$sub:cvLabel($r['channel']).' communication','body'=>cvPlainBody($body),'type'=>cvLabel($r['channel']),'source'=>'message');}
    }
    if(cvTable($pdo,'notification_queue')){
        $where="tenant_id=:t AND channel='email' AND recipient_type='client' AND recipient_id=:c";
        $q=$pdo->prepare("SELECT id,recipient_address,related_type,subject,body,status,COALESCE(sent_at,created_at) created_at FROM notification_queue WHERE $where ORDER BY COALESCE(sent_at,created_at) DESC LIMIT 60");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$communications[]=array('id'=>'q'.(int)$r['id'],'channel'=>'email','direction'=>'outbound','status'=>$r['status'],'created_at'=>$r['created_at'],'to_email'=>$r['recipient_address'],'subject'=>trim((string)$r['subject'])!==''?$r['subject']:'Email','body'=>cvPlainBody($r['body']),'type'=>!empty($r['related_type'])?cvLabel($r['related_type']):'Email','source'=>'queue');}
    }
    /* Fallback history for SMTP-success events when a message-thread insert was unavailable. */
    if(cvTable($pdo,'activity_events')){
        $q=$pdo->prepare("SELECT id,event_type,details_json,created_at FROM activity_events WHERE tenant_id=:t AND client_id=:c AND event_type IN('client_email_sent','client_portal_login_email_sent') ORDER BY created_at DESC,id DESC LIMIT 40");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
            $d=json_decode((string)$r['details_json'],true);if(!is_array($d))$d=array();$mid=!empty($d['message_id'])?(int)$d['message_id']:0;if($mid>0&&isset($loadedMessageIds[$mid]))continue;
            $body=isset($d['message'])?trim((string)$d['message']):'';$subject=isset($d['subject'])?trim((string)$d['subject']):'';if($body===''&&$subject==='')continue;
            $communications[]=array('id'=>'a'.(int)$r['id'],'message_id'=>$mid,'channel'=>'email','direction'=>'outbound','status'=>'sent','created_at'=>$r['created_at'],'to_email'=>isset($d['recipient'])?(string)$d['recipient']:(!empty($client['email'])?$client['email']:''),'subject'=>$subject!==''?$subject:'Email','body'=>$body,'type'=>(string)$r['event_type']==='client_portal_login_email_sent'?'Client Hub login email':'Email','source'=>'activity');
        }
    }
    usort($communications,function($a,$b){$cmp=strcmp((string)$b['created_at'],(string)$a['created_at']);return $cmp!==0?$cmp:strcmp((string)$b['id'],(string)$a['id']);});
    $communications=array_slice($communications,0,80);

    if(cvTable($pdo,'attachments')){
        $q=$pdo->prepare("SELECT id,file_name,file_path,file_mime,file_size,created_at FROM attachments WHERE tenant_id=:t AND related_type='client_note' AND related_id=:c ORDER BY id DESC LIMIT 20");
        $q->execute(array(':t'=>$tenantId,':c'=>$clientId));
        $noteAttachments=$q->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Throwable $e) {
    error_log('FieldPlx client view load: ' . $e->getMessage());
    $loadError = $e->getMessage();
}

$displayName = $client && !empty($client['display_name']) ? (string)$client['display_name'] : 'Client';
$status = $client && !empty($client['status']) ? (string)$client['status'] : 'active';
$primaryPhone = '';
$primaryPhoneType = 'Main phone';
foreach($phones as $p){if(!empty($p['is_primary'])){$primaryPhone=(string)$p['phone_number'];break;}}
if($primaryPhone==='' && $phones){$primaryPhone=(string)$phones[0]['phone_number'];}
$lastCommunication = $communications ? $communications[0] : null;
$currentUserEmail = isset($ctx['email']) ? (string)$ctx['email'] : '';

require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
</style>

<div class="cv-page">
<?php if ($loadError !== ''): ?>
    <div class="cv-card" style="padding:18px;color:var(--cv-danger)"><?= cvH($loadError); ?></div>
<?php else: ?>
<div class="cv-layout" id="clientLayout">
    <main class="cv-main">
        <section class="cv-profile">
            <div class="cv-topline">
                <i class="bi bi-person-circle cv-person-icon"></i>
                <span class="cv-status <?= cvH($status); ?>"><?= cvH(cvLabel($status)); ?></span>
                <div class="cv-actions">
                    <?php if ($canUpdate && !empty($client['email'])): ?>
                    <button type="button" class="cv-btn icon" id="emailButton" title="Email client"><i class="bi bi-envelope"></i></button>
                    <?php endif; ?>
                    <div class="cv-menu">
                        <button type="button" class="cv-btn icon" id="moreButton" title="More actions"><i class="bi bi-three-dots"></i></button>
                        <div class="cv-dropdown" id="moreMenu">
                            <?php if ($canUpdate): ?>
                            <button type="button" data-action="send-login"><i class="bi bi-envelope"></i> Send Login Email</button>
                            <button type="button" data-action="login-client"><i class="bi bi-person-circle"></i> Log in as Client</button>
                            <hr>
                            <button type="button" data-action="archive"><i class="bi bi-archive"></i> Archive</button>
                            <?php if ($canDelete): ?><a href="client-merge.php?client_ids=<?= (int)$clientId; ?>&primary_id=<?= (int)$clientId; ?>"><i class="bi bi-shuffle"></i> Merge Client</a><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canDelete): ?><button type="button" class="danger" data-action="delete"><i class="bi bi-trash"></i> Delete Client</button><?php endif; ?>
                            <hr>
                            <button type="button" data-action="customize"><i class="bi bi-gear"></i> Customize view</button>
                        </div>
                    </div>
                    <?php if ($canUpdate): ?>
                    <div class="cv-menu">
                        <button type="button" class="cv-btn primary" id="createButton"><i class="bi bi-plus-lg"></i> <span class="hide-xs">Create</span></button>
                        <div class="cv-dropdown" id="createMenu">
                            <button type="button" data-create="task"><i class="bi bi-check2-square"></i> Task</button>
                            <a href="add-request.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-inbox"></i> Service Request</a>
                            <a href="add-quotation.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-file-earmark-text"></i> Quote</a>
                            <a href="job-form.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-hammer"></i> Job</a>
                            <a href="add-invoice.php?client_id=<?= (int)$clientId; ?>"><i class="bi bi-receipt"></i> Invoice</a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="cv-title-line">
                <h1 class="cv-title"><?= cvH($displayName); ?></h1>
                <?php if ($canUpdate): ?><a class="cv-title-edit" href="client-form.php?client_id=<?= (int)$clientId; ?>" title="Edit client"><i class="bi bi-pencil"></i></a><?php endif; ?>
            </div>
            <?php if (!empty($client['company_name']) && trim((string)$client['company_name']) !== trim($displayName)): ?><div class="cv-company"><?= cvH($client['company_name']); ?></div><?php endif; ?>
            <div class="cv-summary">
                <div class="cv-summary-col">
                    <div class="cv-summary-row"><span class="label"><?= cvH($primaryPhoneType !== '' ? $primaryPhoneType : 'Main phone'); ?></span><span class="value"><?php if($primaryPhone!==''): ?><a href="tel:<?= cvH($primaryPhone); ?>"><?= cvH($primaryPhone); ?></a><?php else: ?>—<?php endif; ?></span></div>
                    <div class="cv-summary-row"><span class="label">Main email</span><span class="value"><?php if(!empty($client['email'])): ?><a href="mailto:<?= cvH($client['email']); ?>"><?= cvH($client['email']); ?></a><?php else: ?>—<?php endif; ?></span></div>
                </div>
                <div class="cv-summary-col">
                    <div class="cv-summary-row"><span class="label">Payment terms</span><span class="value"><?= cvH($latestPaymentTerms!==''?cvLabel($latestPaymentTerms):'Due upon receipt'); ?></span></div>
                    <div class="cv-summary-row"><span class="label">Lead source</span><span class="value"><?= cvH(!empty($client['source'])?cvLabel($client['source']):'—'); ?></span></div>
                </div>
            </div>
            <div class="cv-tabs">
                <button type="button" class="cv-tab active" data-tab="information">Client information</button>
                <button type="button" class="cv-tab" data-tab="communication">Communication</button>
            </div>
        </section>

        <section class="cv-pane active" id="paneInformation">
            <div class="cv-stack">
                <section class="cv-card" data-view-section="properties">
                    <div class="cv-card-head with-border"><h3>Properties</h3><?php if($canUpdate): ?><a class="cv-plus" href="client-form.php?client_id=<?= (int)$clientId; ?>#propertySections" title="Add property"><i class="bi bi-plus-lg"></i></a><?php endif; ?></div>
                    <?php if(!$locations): ?><div class="cv-empty">No properties saved for this client.</div><?php else: foreach($locations as $location): $address=cvAddress($location); ?>
                    <div class="cv-property">
                        <div class="cv-property-main"><a class="cv-property-address" href="<?= $address!==''?'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address):'#'; ?>" <?= $address!==''?'target="_blank" rel="noopener"':''; ?>><?= cvH($address!==''?$address:(!empty($location['name'])?$location['name']:'Property')); ?></a><div class="cv-property-name"><?= cvH(!empty($location['name'])?$location['name']:(!empty($location['is_primary'])?'Primary property':'Property')); ?><?= !empty($location['is_primary'])?' · Primary':''; ?></div></div>
                        <div class="cv-mini-actions"><?php if($address!==''): ?><a class="cv-mini" href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($address); ?>" target="_blank" rel="noopener" title="Open map"><i class="bi bi-geo-alt"></i></a><?php endif; ?><?php if($canUpdate): ?><a class="cv-mini" href="client-form.php?client_id=<?= (int)$clientId; ?>#propertySections" title="Edit property"><i class="bi bi-pencil"></i></a><?php endif; ?></div>
                    </div>
                    <?php endforeach; endif; ?>
                </section>

                <section class="cv-card" data-view-section="contacts">
                    <div class="cv-contact-strip">
                        <div class="icon"><i class="bi bi-person-vcard"></i></div>
                        <div class="cv-contact-copy"><strong>Contacts</strong><span class="cv-contact-names"> - <?php if(!$additionalContacts): ?>Add contacts to keep track of everyone you communicate with<?php else: ?><?= count($additionalContacts); ?> additional contact<?= count($additionalContacts)===1?'':'s'; ?><?php endif; ?></span></div>
                        <?php if($canUpdate): ?><a class="cv-contact-action" href="client-form.php?client_id=<?= (int)$clientId; ?>#additionalContacts">Add Contact</a><?php endif; ?>
                    </div>
                    <?php if($additionalContacts): ?><div style="border-top:1px solid var(--cv-border)"><?php foreach($additionalContacts as $contact): $cn=trim((string)(isset($contact['first_name'])?$contact['first_name']:'').' '.(string)(isset($contact['last_name'])?$contact['last_name']:'')); ?><div class="cv-property"><div class="cv-property-main"><div style="font-size:11.5px;font-weight:600"><?= cvH($cn!==''?$cn:'Contact'); ?></div><div class="cv-property-name"><?= cvH(!empty($contact['email'])?$contact['email']:(!empty($contact['phone'])?$contact['phone']:'No contact information')); ?></div></div><div><?php if(!empty($contact['is_billing_contact'])): ?><span class="cv-status-pill success">Billing</span><?php endif; ?></div></div><?php endforeach; ?></div><?php endif; ?>
                </section>

                <section class="cv-card" data-view-section="work">
                    <div class="cv-card-head"><h3>Work overview</h3><?php if($canUpdate): ?><button type="button" class="cv-plus" id="workCreateButton" title="Create"><i class="bi bi-plus-lg"></i></button><?php endif; ?></div>
                    <div class="cv-filter-row" id="workFilters">
                        <button class="cv-chip active" type="button" data-work-filter="all">Status <span>|</span> All</button>
                        <button class="cv-chip" type="button" data-work-filter="request"><i class="bi bi-inbox"></i> Requests <span class="count"><?= (int)$summaryCounts['requests']; ?></span></button>
                        <button class="cv-chip" type="button" data-work-filter="quote"><i class="bi bi-file-earmark-text"></i> Quotes <span class="count"><?= (int)$summaryCounts['quotes']; ?></span></button>
                        <button class="cv-chip" type="button" data-work-filter="job"><i class="bi bi-hammer"></i> Jobs <span class="count"><?= (int)$summaryCounts['jobs']; ?></span></button>
                        <button class="cv-chip" type="button" data-work-filter="invoice"><i class="bi bi-receipt"></i> Invoices <span class="count"><?= (int)$summaryCounts['invoices']; ?></span></button>
                    </div>
                    <div class="cv-table-wrap"><table class="cv-table" id="workTable"><thead><tr><th style="width:42%">Item</th><th style="width:24%">Date</th><th style="width:18%">Status</th><th style="width:16%">Amount</th></tr></thead><tbody>
                    <?php if(!$workItems): ?><tr class="cv-empty-row"><td colspan="4" class="cv-empty">No work activity found.</td></tr><?php else: foreach($workItems as $item): $type=$item['item_type'];$url=$type==='request'?'request-view.php?request_id='.(int)$item['item_id']:($type==='quote'?'quote-view.php?quote_id='.(int)$item['item_id']:($type==='job'?'job-view.php?job_id='.(int)$item['item_id']:'invoice-view.php?invoice_id='.(int)$item['item_id']));$icon=$type==='request'?'bi-inbox':($type==='quote'?'bi-file-earmark-text':($type==='job'?'bi-hammer':'bi-receipt')); ?>
                    <tr data-work-type="<?= cvH($type); ?>"><td><div class="cv-item"><i class="bi <?= cvH($icon); ?> cv-item-icon <?= cvH($type); ?>"></i><div class="cv-item-title"><a href="<?= cvH($url); ?>"><?= cvH(cvLabel($type).' '.(string)$item['item_no']); ?></a><small><?= cvH(!empty($item['title'])?$item['title']:'—'); ?></small></div></div></td><td><span class="cv-date-caption"><?= cvH($item['date_caption']); ?></span><?= cvH(cvShortDate($item['activity_date'])); ?></td><td><span class="cv-status-pill <?= cvH(cvTone($item['status'])); ?>"><?= cvH(cvLabel($item['status'])); ?></span></td><td><span class="cv-money-main"><?= cvH(cvMoney($item['amount'],$currency)); ?></span><?php if($type==='invoice' && isset($item['balance_due'])): ?><span class="cv-money-sub">Balance <?= cvH(cvMoney($item['balance_due'],$currency)); ?></span><?php endif; ?></td></tr>
                    <?php endforeach; endif; ?></tbody></table></div>
                </section>

                <section class="cv-card" data-view-section="billing">
                    <div class="cv-card-head"><h3>Billing</h3><?php if($canUpdate): ?><a class="cv-plus" href="add-invoice.php?client_id=<?= (int)$clientId; ?>" title="Create invoice"><i class="bi bi-plus-lg"></i></a><?php endif; ?></div>
                    <div class="cv-table-wrap"><table class="cv-table"><thead><tr><th style="width:42%">Item</th><th style="width:25%">Applied to</th><th style="width:17%">Date</th><th style="width:16%">Amount</th></tr></thead><tbody>
                    <?php if(!$billingItems): ?><tr><td colspan="4" class="cv-empty">No billing records found.</td></tr><?php else: foreach($billingItems as $inv): ?><tr><td><div class="cv-item"><i class="bi bi-receipt cv-item-icon invoice"></i><div class="cv-item-title"><a href="invoice-view.php?invoice_id=<?= (int)$inv['id']; ?>"><?= cvH($inv['invoice_no']); ?></a><small><?= cvH(!empty($inv['subject'])?$inv['subject']:cvLabel($inv['status'])); ?></small></div></div></td><td>—</td><td><?= cvH(cvShortDate(!empty($inv['issue_date'])?$inv['issue_date']:$inv['created_at'])); ?></td><td><span class="cv-money-main"><?= cvH(cvMoney($inv['total'],$currency)); ?></span><?php if((float)$inv['balance_due']>0): ?><span class="cv-money-sub">Balance <?= cvH(cvMoney($inv['balance_due'],$currency)); ?></span><?php endif; ?></td></tr><?php endforeach; endif; ?>
                    </tbody></table></div><div class="cv-balance-row"><span>Current balance</span><span><?= cvH(cvMoney($currentBalance,$currency)); ?></span></div>
                </section>

                <section class="cv-card" data-view-section="schedule">
                    <div class="cv-card-head"><h3>Client schedule</h3><?php if($canUpdate): ?><button type="button" class="cv-plus" id="scheduleCreateButton" title="New task"><i class="bi bi-plus-lg"></i></button><?php endif; ?></div>
                    <div class="cv-schedule-filters"><select class="cv-filter-select" id="scheduleType"><option value="all">Type | All</option><option value="request">Requests</option><option value="visit">Visits</option><option value="task">Tasks</option></select><select class="cv-filter-select" id="scheduleStatus"><option value="all">Status | All</option><?php $statuses=array();foreach($scheduleItems as $s){$statuses[(string)$s['status']]=true;}foreach(array_keys($statuses) as $ss): ?><option value="<?= cvH($ss); ?>"><?= cvH(cvLabel($ss)); ?></option><?php endforeach; ?></select></div>
                    <div class="cv-table-wrap"><table class="cv-table" id="scheduleTable"><thead><tr><th style="width:25%">Schedule</th><th style="width:43%">Title</th><th style="width:24%">Assigned</th><th style="width:8%"></th></tr></thead><tbody>
                    <?php if(!$scheduleItems): ?><tr class="cv-empty-row"><td colspan="4" class="cv-empty">No scheduled activity found.</td></tr><?php else: foreach($scheduleItems as $s): $isUnscheduled=empty($s['scheduled_start']);$stype=$s['type'];$sicon=$stype==='request'?'bi-inbox':($stype==='visit'?'bi-truck':'bi-check2-square');$isCompleted=((string)$s['status']==='completed');$canToggleSchedule=$canUpdate && in_array($stype,array('visit','task'),true) && (string)$s['status']!=='cancelled'; ?><tr data-schedule-type="<?= cvH($stype); ?>" data-schedule-status="<?= cvH($s['status']); ?>" class="<?= in_array($s['status'],array('completed','cancelled'),true)?'cv-row-muted':''; ?>"><td><span class="cv-schedule-type"><i class="bi <?= cvH($sicon); ?> <?= cvH($stype); ?>"></i><?= $isUnscheduled?'Unscheduled':cvH(cvDate($s['scheduled_start'],false).(!empty($s['scheduled_start']) && date('H:i',strtotime($s['scheduled_start']))!=='00:00'?', '.date('H:i',strtotime($s['scheduled_start'])):'')); ?></span><?php if($isCompleted || (string)$s['status']==='cancelled'): ?><span class="cv-date-caption"><?= cvH(cvLabel($s['status'])); ?> <i class="bi bi-info-circle"></i></span><?php endif; ?></td><td><?= cvH(!empty($s['title'])?$s['title']:$s['ref_no']); ?></td><td><span class="cv-assignee"><span class="cv-assignee-avatar"><?= cvH(strtoupper(substr((string)$s['assigned_name'],0,1))); ?></span><?= cvH($s['assigned_name']); ?></span></td><td><?php if($canToggleSchedule): ?><?php if($isCompleted): ?><button type="button" class="cv-schedule-action" data-schedule-menu="1" data-schedule-type="<?= cvH($stype); ?>" data-schedule-id="<?= (int)$s['id']; ?>" title="More actions"><i class="bi bi-three-dots"></i></button><?php else: ?><button type="button" class="cv-schedule-action" data-schedule-complete="1" data-schedule-type="<?= cvH($stype); ?>" data-schedule-id="<?= (int)$s['id']; ?>" title="Mark as complete"><i class="bi bi-check2-circle"></i></button><?php endif; ?><?php else: ?><span style="color:var(--cv-muted)">—</span><?php endif; ?></td></tr><?php endforeach; endif; ?>
                    </tbody></table></div>
                </section>

                <section class="cv-card" data-view-section="pricing">
                    <div class="cv-card-head with-border"><h3>Recent pricing</h3></div>
                    <div class="cv-table-wrap"><table class="cv-table"><thead><tr><th style="width:48%">Line item</th><th style="width:26%">Quoted</th><th style="width:26%">Job</th></tr></thead><tbody>
                    <?php if(!$recentPricing): ?><tr><td colspan="3" class="cv-empty">No recent pricing found.</td></tr><?php else: foreach($recentPricing as $pr): ?><tr><td class="cv-pricing-name"><?= cvH($pr['name']); ?></td><td class="cv-price-cell"><?php if(!empty($pr['quoted'])): ?><strong><?= cvH(cvMoney($pr['quoted']['price'],$currency)); ?></strong><span><?= cvH(cvShortDate($pr['quoted']['date'])); ?></span><?php else: ?>—<?php endif; ?></td><td class="cv-price-cell"><?php if(!empty($pr['job'])): ?><strong><?= cvH(cvMoney($pr['job']['price'],$currency)); ?></strong><span><?= cvH(cvShortDate($pr['job']['date'])); ?></span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; endif; ?>
                    </tbody></table></div>
                </section>

                <section class="cv-card" data-view-section="details">
                    <div class="cv-card-head with-border"><h3>Client details</h3><?php if($canUpdate): ?><button type="button" class="cv-btn" id="communicationSettingsButton" style="margin-left:auto">Communication settings</button><a class="cv-btn" href="client-form.php?client_id=<?= (int)$clientId; ?>">Edit</a><?php endif; ?></div>
                    <div class="cv-details">
                        <div class="cv-detail"><span class="k">Company</span><span class="v"><?= cvH(!empty($client['company_name'])?$client['company_name']:'—'); ?></span></div>
                        <div class="cv-detail"><span class="k">Preferred contact</span><span class="v"><?= cvH(!empty($client['preferred_contact_method'])?cvLabel($client['preferred_contact_method']):'—'); ?></span></div>
                        <div class="cv-detail"><span class="k">Tax number</span><span class="v"><?= cvH(!empty($client['tax_number'])?$client['tax_number']:'—'); ?></span></div>
                        <div class="cv-detail"><span class="k">Branch</span><span class="v"><?= cvH($branchName!==''?$branchName:'—'); ?></span></div>
                        <div class="cv-detail"><span class="k">Account manager</span><span class="v"><?= cvH($managerName!==''?$managerName:'—'); ?></span></div>
                        <div class="cv-detail"><span class="k">Client type</span><span class="v"><?= cvH(!empty($client['client_type'])?cvLabel($client['client_type']):'—'); ?></span></div>
                        <?php foreach($phones as $phone): ?><div class="cv-detail"><span class="k"><?= cvH(cvLabel($phone['phone_type'])); ?> phone<?= !empty($phone['is_primary'])?' · Primary':''; ?></span><span class="v"><?= cvH($phone['phone_number']); ?><?= !empty($phone['receives_messages'])?' · Messages on':''; ?></span></div><?php endforeach; ?>
                        <?php foreach($clientCustomFields as $field): ?><div class="cv-detail"><span class="k"><?= cvH($field['field_name']); ?></span><span class="v"><?= cvH(trim((string)$field['value'])!==''?$field['value']:'—'); ?></span></div><?php endforeach; ?>
                        <div class="cv-detail" style="grid-column:1/-1"><span class="k">Communication</span><span class="v"><div class="cv-toggle-list"><?php $pl=array('quote_followups'=>'Quote follow-ups','invoice_followups'=>'Invoice follow-ups','visit_reminders'=>'Visit reminders','job_close_followups'=>'Job close follow-ups');foreach($pl as $pk=>$pv):$on=!empty($communicationPrefs[$pk]);?><div class="cv-toggle"><span><?= cvH($pv); ?></span><span class="cv-toggle-state <?= $on?'':'off'; ?>"><?= $on?'ON':'OFF'; ?></span></div><?php endforeach; ?></div></span></div>
                    </div>
                </section>
            </div>
        </section>

        <section class="cv-pane" id="paneCommunication">
            <div class="cv-stack"><section class="cv-card" id="communicationCard"><div class="cv-card-head with-border"><h3>Communication</h3><?php if($canUpdate && !empty($client['email'])): ?><button type="button" class="cv-btn primary" id="emailButtonCommunication" style="margin-left:auto"><i class="bi bi-envelope"></i> Send email</button><?php endif; ?></div>
                <div id="communicationHistoryWrap"><?php if(!$communications): ?><div class="cv-empty">No communication history found.</div><?php else: ?><div class="cv-communication-list" id="communicationList"><?php foreach($communications as $i=>$comm): ?><div class="cv-comm" data-comm-index="<?= (int)$i; ?>"><div class="cv-comm-top"><i class="bi bi-envelope-arrow-up"></i><strong>To: <?= cvH($displayName); ?><?= !empty($comm['to_email'])?', '.cvH($comm['to_email']):''; ?></strong><span class="cv-comm-date"><?= cvH(date('M d',strtotime($comm['created_at']))); ?></span></div><div class="cv-comm-line"><strong><?= cvH($comm['subject']); ?></strong> - <?= cvH(mb_strimwidth($comm['body'],0,280,'...','UTF-8')); ?></div><span class="cv-sent"><?= cvH(cvLabel($comm['status'])); ?></span></div><?php endforeach; ?></div><?php endif; ?></div>
            </section></div>
        </section>
    </main>

    <aside class="cv-aside">
        <div class="cv-aside-toolbar"><button class="cv-aside-toggle" type="button" id="asideToggle" title="Collapse side panel"><i class="bi bi-layout-sidebar-inset-reverse"></i></button></div>
        <div class="cv-aside-body">
            <section class="cv-side-card" data-view-section="overview"><h3>Overview</h3><div class="cv-side-stat"><strong><?= cvH(cvMoney($lifetimeValue,$currency)); ?></strong><span>Lifetime value</span></div><div class="cv-side-stat"><strong><?= cvH(cvMoney($currentBalance,$currency)); ?></strong><span>Current balance</span></div></section>
            <section class="cv-side-card" data-view-section="tags"><div class="cv-side-head"><h3>Tags</h3><?php if($canUpdate): ?><button type="button" class="cv-plus" id="editTagsButton"><i class="bi bi-plus-lg"></i></button><?php endif; ?></div><div class="cv-tags" id="tagsDisplay"><?php if(!$tags): ?><span style="color:var(--cv-muted);font-size:11px">This client has no tags</span><?php else: foreach($tags as $tag): $color=cvSafeColor($tag['color']); ?><span class="cv-tag"><span class="cv-tag-dot"<?= $color!==''?' style="background:'.cvH($color).'"':''; ?>></span><?= cvH($tag['name']); ?></span><?php endforeach; endif; ?></div></section>
            <section class="cv-side-card" data-view-section="lastCommunication" id="lastCommunicationCard"><h3>Last communication</h3><?php if($lastCommunication): ?><div class="cv-last"><small><?= cvH(cvDate($lastCommunication['created_at'],true)); ?></small><?= cvH(mb_strimwidth($lastCommunication['subject'].' - '.$lastCommunication['body'],0,120,'...','UTF-8')); ?></div><button class="cv-link" type="button" id="openCommunication">Read more...</button><?php else: ?><div class="cv-last">No communication history.</div><?php endif; ?></section>
            <section class="cv-side-card" data-view-section="notes"><h3>Notes</h3><?php if($canUpdate): ?><div id="noteCollapsed"><?php if(!empty($client['notes'])): ?><div class="cv-note-preview" id="notePreview"><?= cvH($client['notes']); ?></div><div class="cv-note-preview-actions"><button type="button" class="cv-btn" id="openNoteEditor"><i class="bi bi-pencil"></i> Edit note</button></div><?php else: ?><button type="button" class="cv-note-empty" id="openNoteEditor"><span class="plus"><i class="bi bi-plus-lg"></i></span><span>Leave an internal note for yourself or a team member</span></button><?php endif; ?></div><div class="cv-note-editor" id="noteEditor" hidden><div class="cv-note-wrap"><textarea class="cv-note" id="notesInput" maxlength="5000" placeholder="Use @ in notes to mention your team"><?= cvH(isset($client['notes'])?$client['notes']:''); ?></textarea><div class="cv-mention-box" id="mentionBox"></div></div><div class="cv-note-upload" id="noteDrop"><input type="file" id="noteFiles" multiple hidden><button type="button" class="cv-btn" id="selectNoteFiles">Attach files &amp; photos</button><small>Select or drag files here to upload</small><div class="cv-note-files" id="noteFileList"></div></div><details class="cv-related"><summary>Link to related</summary><div class="cv-related-options"><label class="cv-related-check"><input type="checkbox" class="noteRelatedType" value="request" checked> Requests</label><label class="cv-related-check"><input type="checkbox" class="noteRelatedType" value="quote" checked> Quotes</label><label class="cv-related-check"><input type="checkbox" class="noteRelatedType" value="job" checked> Jobs</label><label class="cv-related-check"><input type="checkbox" class="noteRelatedType" value="invoice" checked> Invoices</label></div></details><div class="cv-note-actions"><button type="button" class="cv-btn" id="cancelNote">Cancel</button><button type="button" class="cv-btn primary" id="saveNotesButton">Save</button></div></div><?php else: ?><div class="cv-last"><?= cvH(!empty($client['notes'])?$client['notes']:'No internal notes.'); ?></div><?php endif; ?><?php if($noteAttachments): ?><div class="cv-note-files" style="margin-top:10px"><?php foreach(array_slice($noteAttachments,0,5) as $na): ?><div class="cv-note-file"><i class="bi bi-paperclip"></i> <?= cvH($na['file_name']); ?></div><?php endforeach; ?></div><?php endif; ?></section>
            <section class="cv-side-card" data-view-section="account"><h3>Account details</h3><div class="cv-side-meta"><div class="cv-side-meta-row"><span class="k">Client ID</span><span>#<?= (int)$clientId; ?></span></div><div class="cv-side-meta-row"><span class="k">Branch</span><span><?= cvH($branchName!==''?$branchName:'—'); ?></span></div><div class="cv-side-meta-row"><span class="k">Manager</span><span><?= cvH($managerName!==''?$managerName:'—'); ?></span></div><div class="cv-side-meta-row"><span class="k">Portal</span><span><?= cvH($portal?cvLabel($portal['status']):'Not enabled'); ?></span></div></div></section>
        </div>
    </aside>
</div>
<?php endif; ?>
</div>

<?php if ($loadError === ''): ?>
<div class="cv-schedule-popover" id="scheduleActionPopover"><button type="button" id="markScheduleIncomplete"><i class="bi bi-x-lg"></i> Mark as incomplete</button></div>
<!-- Email composer -->
<div class="cv-modal-backdrop" id="emailModal" aria-hidden="true"><section class="cv-modal wide" role="dialog" aria-modal="true"><div class="cv-modal-head"><h3>Send email to <?= cvH($displayName); ?></h3><button class="cv-modal-close" type="button" data-close="emailModal"><i class="bi bi-x-lg"></i></button></div><div class="cv-modal-body"><div class="cv-email-grid"><div><div class="cv-field"><label>To</label><input class="cv-input" id="emailTo" type="email" value="<?= cvH(!empty($client['email'])?$client['email']:''); ?>" placeholder="Recipient email"></div><div class="cv-field"><label>Subject</label><input class="cv-input" id="emailSubject" maxlength="255" placeholder="Subject"></div><div class="cv-field"><label>Message</label><textarea class="cv-textarea" id="emailMessage" maxlength="30000" placeholder="Message"></textarea></div><label class="cv-check"><input type="checkbox" id="emailCopy"> Send me a copy<?= $currentUserEmail!==''?' ('.cvH($currentUserEmail).')':''; ?></label></div><div><label style="display:block;margin-bottom:7px;font-size:11px;font-weight:700">Attachments</label><div class="cv-attachment-box" id="emailDrop"><div><input type="file" id="emailFiles" multiple hidden><button class="cv-btn" type="button" id="selectEmailFiles">Select</button><small>Select or drag files here to upload</small></div></div><div class="cv-attachment-meta" id="emailAttachmentMeta">You've attached 0.00 MB of the 10.00 MB limit.</div><div class="cv-progress"><span id="emailAttachmentProgress"></span></div><div class="cv-note-files" id="emailFileList"></div></div></div></div><div class="cv-modal-footer"><button class="cv-btn" type="button" data-close="emailModal">Cancel</button><button class="cv-btn primary" type="button" id="sendEmailButton">Send Email</button></div></section></div>

<!-- Send login link -->
<div class="cv-modal-backdrop" id="loginEmailModal" aria-hidden="true"><section class="cv-modal" role="dialog" aria-modal="true"><div class="cv-modal-head"><h3>Send login link</h3><button class="cv-modal-close" type="button" data-close="loginEmailModal"><i class="bi bi-x-lg"></i></button></div><div class="cv-modal-body"><div class="cv-login-copy">Send a Client Hub login email to<br><strong><?= cvH(!empty($client['email'])?$client['email']:'No client email'); ?></strong></div></div><div class="cv-modal-footer"><button class="cv-btn" type="button" data-close="loginEmailModal">Close</button><button class="cv-btn primary" type="button" id="sendLoginEmailButton">Send Email</button></div></section></div>

<!-- Communication detail -->
<div class="cv-modal-backdrop" id="communicationModal" aria-hidden="true"><section class="cv-modal" role="dialog" aria-modal="true"><div class="cv-modal-head"><h3>Email Communication</h3><button class="cv-modal-close" type="button" data-close="communicationModal"><i class="bi bi-x-lg"></i></button></div><div class="cv-modal-body"><div class="cv-comm-detail-meta"><div><strong>Sent on</strong><span id="commSent"></span></div><div><strong>Type</strong><span id="commType"></span></div><div style="grid-column:1/-1"><strong>To:</strong><span id="commTo"></span></div><div style="grid-column:1/-1"><strong>Cc:</strong> - None -</div><div style="grid-column:1/-1"><strong>Bcc:</strong> - None -</div><div style="grid-column:1/-1"><strong>Subject:</strong><span id="commSubject"></span></div></div><div class="cv-comm-body" id="commBody"></div></div></section></div>

<!-- New task -->
<div class="cv-modal-backdrop" id="taskModal" aria-hidden="true"><section class="cv-modal task" role="dialog" aria-modal="true"><div class="cv-modal-head"><h3>New Task</h3><button class="cv-modal-close" type="button" data-close="taskModal"><i class="bi bi-x-lg"></i></button></div><div class="cv-modal-body"><div class="cv-task-client"><div class="cv-task-client-top"><span><?= cvH($displayName); ?><span class="cv-client-dot"></span></span><i class="bi bi-three-dots"></i></div><div class="cv-field" style="margin-top:10px;margin-bottom:0"><select class="cv-select" id="taskProperty"><option value="">Select a property</option><?php foreach($locations as $loc): ?><option value="<?= (int)$loc['id']; ?>"><?= cvH(cvAddress($loc)!==''?cvAddress($loc):(!empty($loc['name'])?$loc['name']:'Property')); ?></option><?php endforeach; ?></select></div><div class="cv-task-contact"><?php if($primaryPhone!==''): ?><div><?= cvH($primaryPhone); ?></div><?php endif; ?><?php if(!empty($client['email'])): ?><div><?= cvH($client['email']); ?></div><?php endif; ?><?php if($canUpdate): ?><div style="margin-top:6px"><a href="client-form.php?client_id=<?= (int)$clientId; ?>#propertySections" style="color:var(--cv-primary);text-decoration:none"><i class="bi bi-plus-lg"></i> Create new property</a></div><?php endif; ?></div></div><div class="cv-field"><input class="cv-input" id="taskTitle" maxlength="190" placeholder="Title"></div><div class="cv-field"><textarea class="cv-textarea" id="taskDescription" style="min-height:76px" placeholder="Instructions"></textarea></div><div class="cv-task-grid"><div><label style="font-size:12px;font-weight:700">Schedule</label><div class="cv-dates" style="margin-top:7px"><input class="cv-input" type="date" id="taskStartDate" title="Start date"><input class="cv-input" type="date" id="taskEndDate" title="End date"></div><div class="cv-dates" style="margin-top:7px"><input class="cv-input" type="time" id="taskStartTime" title="Start time"><input class="cv-input" type="time" id="taskEndTime" title="End time"></div><div class="cv-task-flags"><label class="cv-check"><input type="checkbox" id="taskScheduleLater" checked> Schedule later</label><label class="cv-check"><input type="checkbox" id="taskAnytime" checked> Anytime</label></div></div><div><div class="cv-field"><label>Team</label><select class="cv-select" id="taskAssignee"><option value="">Assign</option><?php if($users): ?><optgroup label="Team members"><?php foreach($users as $u): ?><option value="user:<?= (int)$u['id']; ?>"><?= cvH(trim((string)$u['name'])!==''?$u['name']:$u['email']); ?></option><?php endforeach; ?></optgroup><?php endif; ?><?php if($teams): ?><optgroup label="Teams"><?php foreach($teams as $tm): ?><option value="team:<?= (int)$tm['id']; ?>"><?= cvH($tm['name']); ?></option><?php endforeach; ?></optgroup><?php endif; ?></select></div><div class="cv-field"><label>Repeats</label><select class="cv-select" id="taskRepeat"><option value="never">Never</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></div></div></div><div class="cv-task-actions"><button class="cv-btn" type="button" data-close="taskModal">Cancel</button><button class="cv-btn primary" type="button" id="saveTaskButton">Save</button></div></div></section></div>

<!-- Tags -->
<div class="cv-modal-backdrop" id="tagsModal" aria-hidden="true"><section class="cv-modal" role="dialog" aria-modal="true"><div class="cv-modal-head"><h3>Edit tags for <?= cvH($displayName); ?></h3><button class="cv-modal-close" type="button" data-close="tagsModal"><i class="bi bi-x-lg"></i></button></div><div class="cv-modal-body"><div class="cv-tag-selected" id="selectedTagsPreview"></div><div class="cv-field"><input class="cv-input" id="tagSearch" placeholder="Search tags"></div><div class="cv-tag-options" id="tagOptions"></div><div class="cv-create-tag"><input class="cv-input" id="newTagName" maxlength="80" placeholder="Create new tag"><button class="cv-btn" type="button" id="createTagButton"><i class="bi bi-plus-lg"></i> Create</button></div></div><div class="cv-modal-footer"><button class="cv-btn" type="button" data-close="tagsModal">Cancel</button><button class="cv-btn primary" type="button" id="saveTagsButton">Save</button></div></section></div>

<!-- Communication settings -->
<div class="cv-modal-backdrop" id="communicationSettingsModal" aria-hidden="true"><section class="cv-modal" role="dialog" aria-modal="true"><div class="cv-modal-head"><h3>Communication Settings</h3><button class="cv-modal-close" type="button" data-close="communicationSettingsModal"><i class="bi bi-x-lg"></i></button></div><div class="cv-modal-body"><div class="cv-login-copy" style="margin-bottom:12px">Automated communications can be toggled on or off for this client.</div><div class="cv-card" style="padding:0 14px"><div style="padding:12px 0 4px;font-size:11px;font-weight:700">Quotes &amp; Invoices</div><div class="cv-switch-row"><span>Outstanding quote follow-ups</span><label class="cv-switch"><input type="checkbox" id="prefQuote" <?= !empty($communicationPrefs['quote_followups'])?'checked':''; ?>><span></span></label></div><div class="cv-switch-row"><span>Overdue invoice follow-ups</span><label class="cv-switch"><input type="checkbox" id="prefInvoice" <?= !empty($communicationPrefs['invoice_followups'])?'checked':''; ?>><span></span></label></div><div style="padding:14px 0 4px;font-size:11px;font-weight:700">Jobs &amp; Visits</div><div class="cv-switch-row"><span>Upcoming assessment or visit reminders</span><label class="cv-switch"><input type="checkbox" id="prefVisit" <?= !empty($communicationPrefs['visit_reminders'])?'checked':''; ?>><span></span></label></div><div class="cv-switch-row"><span>Job closure follow-ups</span><label class="cv-switch"><input type="checkbox" id="prefJob" <?= !empty($communicationPrefs['job_close_followups'])?'checked':''; ?>><span></span></label></div></div></div><div class="cv-modal-footer"><button class="cv-btn" type="button" data-close="communicationSettingsModal">Cancel</button><button class="cv-btn primary" type="button" id="saveCommunicationSettings">Save</button></div></section></div>

<!-- Customize -->
<div class="cv-modal-backdrop" id="customizeModal" aria-hidden="true"><section class="cv-modal" role="dialog" aria-modal="true"><div class="cv-modal-head"><h3>Customize view</h3><button class="cv-modal-close" type="button" data-close="customizeModal"><i class="bi bi-x-lg"></i></button></div><div class="cv-modal-body"><p class="cv-customize-help">Use the arrow buttons to rearrange sections. Changes are saved automatically.</p><div class="cv-customize-list" id="customizeList"></div><div class="cv-customize-footer"><button class="cv-btn" type="button" id="resetCustomize">Default order</button><button class="cv-btn primary" type="button" data-close="customizeModal">Done</button></div></div></section></div>

<!-- Generic confirm -->
<div class="cv-modal-backdrop" id="confirmModal" aria-hidden="true"><section class="cv-modal" role="dialog" aria-modal="true"><div class="cv-modal-head"><h3 id="confirmTitle">Confirm action</h3><button class="cv-modal-close" type="button" data-close="confirmModal"><i class="bi bi-x-lg"></i></button></div><div class="cv-modal-body"><div class="cv-confirm-copy" id="confirmCopy"></div><div class="cv-confirm-warning" id="confirmWarning" style="display:none"></div></div><div class="cv-modal-footer"><button class="cv-btn" type="button" data-close="confirmModal">Cancel</button><button class="cv-btn primary" type="button" id="confirmActionButton">Continue</button></div></section></div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/toast.php'; ?>
<?php if ($loadError === ''): ?>
<script>
(function(){
'use strict';
var clientId=<?= (int)$clientId; ?>;
var csrf=<?= json_encode($clientsCsrfToken); ?>;
var communications=<?= json_encode($communications,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
var allTags=<?= json_encode($allTags,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
var mentionUsers=<?= json_encode($mentionUsers,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
var selectedTagIds=<?= json_encode(array_map('intval',array_column($tags,'id'))); ?> || [];
var initialTagIds=selectedTagIds.slice();
var originalNotes=<?= json_encode(isset($client['notes'])?(string)$client['notes']:'',JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var confirmCallback=null;
var maxFiles=10,maxBytes=10*1024*1024;
var emailFiles=[],noteFiles=[];
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
function toast(type,msg){if(typeof fieldplxToast==='function')fieldplxToast(type,msg);else alert(msg);}
function modal(id,show){var el=document.getElementById(id);if(!el)return;el.classList.toggle('show',!!show);el.setAttribute('aria-hidden',show?'false':'true');document.body.style.overflow=show?'hidden':'';}
document.querySelectorAll('[data-close]').forEach(function(btn){btn.addEventListener('click',function(){modal(btn.getAttribute('data-close'),false);});});
document.querySelectorAll('.cv-modal-backdrop').forEach(function(bg){bg.addEventListener('click',function(e){if(e.target===bg)modal(bg.id,false);});});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.querySelectorAll('.cv-modal-backdrop.show').forEach(function(x){modal(x.id,false);});document.querySelectorAll('.cv-dropdown.show').forEach(function(x){x.classList.remove('show');});}});
function api(data,files){var fd=new FormData();Object.keys(data||{}).forEach(function(k){fd.append(k,data[k]);});fd.append('csrf_token',csrf);(files||[]).forEach(function(f){fd.append('attachments[]',f,f.name);});return fetch('api/client-view.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.text().then(function(raw){var j;try{j=raw?JSON.parse(raw):{};}catch(e){throw new Error(raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.');}if(!r.ok||!j.success)throw new Error(j.message||'Request failed.');return j;});});}
function setBusy(btn,on,label){if(!btn)return;if(on){btn.dataset.oldText=btn.innerHTML;btn.disabled=true;if(label)btn.textContent=label;}else{btn.disabled=false;if(btn.dataset.oldText)btn.innerHTML=btn.dataset.oldText;}}
function toggleMenu(btnId,menuId){var btn=document.getElementById(btnId),menu=document.getElementById(menuId);if(!btn||!menu)return;btn.addEventListener('click',function(e){e.stopPropagation();document.querySelectorAll('.cv-dropdown.show').forEach(function(x){if(x!==menu)x.classList.remove('show');});menu.classList.toggle('show');});}
toggleMenu('moreButton','moreMenu');toggleMenu('createButton','createMenu');document.addEventListener('click',function(e){if(!e.target.closest('.cv-menu'))document.querySelectorAll('.cv-dropdown.show').forEach(function(x){x.classList.remove('show');});});

function setClientTab(name){name=name==='communication'?'communication':'information';document.querySelectorAll('[data-tab]').forEach(function(b){b.classList.toggle('active',b.getAttribute('data-tab')===name);});document.getElementById('paneInformation').classList.toggle('active',name==='information');document.getElementById('paneCommunication').classList.toggle('active',name==='communication');try{sessionStorage.setItem('fieldplx.clientView.tab.'+clientId,name);}catch(e){}}
document.querySelectorAll('[data-tab]').forEach(function(btn){btn.addEventListener('click',function(){setClientTab(btn.getAttribute('data-tab'));});});try{var rememberedTab=sessionStorage.getItem('fieldplx.clientView.tab.'+clientId);if(rememberedTab)setClientTab(rememberedTab);}catch(e){}
function commDate(v){var d=new Date(String(v||'').replace(' ','T'));if(isNaN(d.getTime()))return '';return d.toLocaleDateString(undefined,{month:'short',day:'2-digit'});}
function commPreview(v,n){v=String(v==null?'':v).replace(/\s+/g,' ').trim();return v.length>(n||280)?v.slice(0,(n||280)-3)+'...':v;}
function renderCommunicationHistory(){var wrap=document.getElementById('communicationHistoryWrap');if(!wrap)return;if(!communications.length){wrap.innerHTML='<div class="cv-empty">No communication history found.</div>';return;}wrap.innerHTML='<div class="cv-communication-list" id="communicationList">'+communications.map(function(c,i){return '<div class="cv-comm" data-comm-index="'+i+'"><div class="cv-comm-top"><i class="bi bi-envelope-arrow-up"></i><strong>To: '+esc(<?= json_encode($displayName); ?>)+(c.to_email?', '+esc(c.to_email):'')+'</strong><span class="cv-comm-date">'+esc(commDate(c.created_at))+'</span></div><div class="cv-comm-line"><strong>'+esc(c.subject||'Email')+'</strong> - '+esc(commPreview(c.body,280))+'</div><span class="cv-sent">'+esc(String(c.status||'sent').replace(/[_-]/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase();}))+'</span></div>';}).join('')+'</div>';}
function renderLastCommunication(){var card=document.getElementById('lastCommunicationCard');if(!card)return;var c=communications.length?communications[0]:null;if(!c){card.innerHTML='<h3>Last communication</h3><div class="cv-last">No communication history.</div>';return;}card.innerHTML='<h3>Last communication</h3><div class="cv-last"><small>'+esc(formatDate(c.created_at))+'</small>'+esc(commPreview((c.subject||'Email')+' - '+(c.body||''),120))+'</div><button class="cv-link" type="button" id="openCommunication">Read more...</button>';var b=document.getElementById('openCommunication');if(b)b.addEventListener('click',function(){openCommunicationDetail(communications[0]);});}
function addRecentCommunication(c){if(!c)return;communications=communications.filter(function(x){return String(x.id||'')!==String(c.id||'');});communications.unshift(c);communications.sort(function(a,b){return String(b.created_at||'').localeCompare(String(a.created_at||''));});if(communications.length>80)communications=communications.slice(0,80);renderCommunicationHistory();renderLastCommunication();}
function openCommunicationDetail(c){if(!c)return;document.getElementById('commSent').textContent=formatDate(c.created_at);document.getElementById('commType').textContent=c.type||'Email';document.getElementById('commTo').textContent=' '+(c.to_email||<?= json_encode(!empty($client['email'])?$client['email']:''); ?>||'—');document.getElementById('commSubject').textContent=' '+(c.subject||'Email');document.getElementById('commBody').textContent=c.body||'';modal('communicationModal',true);}var openComm=document.getElementById('openCommunication');if(openComm)openComm.addEventListener('click',function(){openCommunicationDetail(communications.length?communications[0]:null);});

function openEmail(){emailFiles=[];renderFiles('email');document.getElementById('emailSubject').value='';document.getElementById('emailMessage').value='';document.getElementById('emailCopy').checked=false;modal('emailModal',true);}
['emailButton','emailButtonCommunication'].forEach(function(id){var b=document.getElementById(id);if(b)b.addEventListener('click',openEmail);});
function renderFiles(kind){var files=kind==='email'?emailFiles:noteFiles;var list=document.getElementById(kind==='email'?'emailFileList':'noteFileList');if(list)list.innerHTML=files.map(function(f,i){return '<div class="cv-note-file"><i class="bi bi-paperclip"></i> '+esc(f.name)+' <button type="button" data-remove-file="'+kind+':'+i+'" style="border:0;background:none;color:var(--cv-danger);cursor:pointer">×</button></div>';}).join('');if(kind==='email'){var total=files.reduce(function(a,f){return a+f.size;},0),mb=total/(1024*1024);document.getElementById('emailAttachmentMeta').textContent="You've attached "+mb.toFixed(2)+' MB of the 10.00 MB limit.';document.getElementById('emailAttachmentProgress').style.width=Math.min(100,total/maxBytes*100)+'%';}}
function addFiles(kind,fileList){var target=kind==='email'?emailFiles:noteFiles;Array.prototype.forEach.call(fileList||[],function(f){if(target.length>=maxFiles){toast('warning','You can attach up to 10 files.');return;}var current=target.reduce(function(a,x){return a+x.size;},0);if(current+f.size>maxBytes){toast('warning','Attachments cannot exceed 10 MB in total.');return;}target.push(f);});renderFiles(kind);}
document.addEventListener('click',function(e){var b=e.target.closest('[data-remove-file]');if(!b)return;var p=b.getAttribute('data-remove-file').split(':'),kind=p[0],idx=Number(p[1]);if(kind==='email')emailFiles.splice(idx,1);else noteFiles.splice(idx,1);renderFiles(kind);});
var emailInput=document.getElementById('emailFiles');document.getElementById('selectEmailFiles').addEventListener('click',function(){emailInput.click();});emailInput.addEventListener('change',function(){addFiles('email',this.files);this.value='';});var drop=document.getElementById('emailDrop');['dragenter','dragover'].forEach(function(ev){drop.addEventListener(ev,function(e){e.preventDefault();});});drop.addEventListener('drop',function(e){e.preventDefault();addFiles('email',e.dataTransfer.files);});
var noteInput=document.getElementById('noteFiles');if(noteInput){document.getElementById('selectNoteFiles').addEventListener('click',function(){noteInput.click();});noteInput.addEventListener('change',function(){addFiles('note',this.files);this.value='';});}var noteDrop=document.getElementById('noteDrop');if(noteDrop){['dragenter','dragover'].forEach(function(ev){noteDrop.addEventListener(ev,function(e){e.preventDefault();});});noteDrop.addEventListener('drop',function(e){e.preventDefault();addFiles('note',e.dataTransfer.files);});}
var sendEmail=document.getElementById('sendEmailButton');sendEmail.addEventListener('click',function(){var to=document.getElementById('emailTo').value.trim(),sub=document.getElementById('emailSubject').value.trim(),msg=document.getElementById('emailMessage').value.trim();if(!to||!sub||!msg){toast('warning','Enter recipient, subject and message.');return;}setBusy(sendEmail,true,'Sending...');api({action:'send_email',client_id:clientId,to:to,subject:sub,message:msg,send_copy:document.getElementById('emailCopy').checked?'1':'0'},emailFiles).then(function(d){toast('success',d.message||'Email sent.');modal('emailModal',false);emailFiles=[];renderFiles('email');if(d.communication)addRecentCommunication(d.communication);setClientTab('communication');}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(sendEmail,false);});});

/* Communication detail */
var communicationHistoryWrap=document.getElementById('communicationHistoryWrap');if(communicationHistoryWrap)communicationHistoryWrap.addEventListener('click',function(e){var row=e.target.closest('[data-comm-index]');if(!row)return;openCommunicationDetail(communications[Number(row.getAttribute('data-comm-index'))]);});
function formatDate(v){var d=new Date(String(v).replace(' ','T'));if(isNaN(d.getTime()))return v||'—';return d.toLocaleString();}

/* Work and schedule filters */
document.getElementById('workFilters').addEventListener('click',function(e){var b=e.target.closest('[data-work-filter]');if(!b)return;var f=b.getAttribute('data-work-filter');document.querySelectorAll('[data-work-filter]').forEach(function(x){x.classList.toggle('active',x===b);});document.querySelectorAll('#workTable tbody tr[data-work-type]').forEach(function(r){r.style.display=f==='all'||r.getAttribute('data-work-type')===f?'':'none';});});
function filterSchedule(){var t=document.getElementById('scheduleType').value,s=document.getElementById('scheduleStatus').value;document.querySelectorAll('#scheduleTable tbody tr[data-schedule-type]').forEach(function(r){var ok=(t==='all'||r.getAttribute('data-schedule-type')===t)&&(s==='all'||r.getAttribute('data-schedule-status')===s);r.style.display=ok?'':'none';});}document.getElementById('scheduleType').addEventListener('change',filterSchedule);document.getElementById('scheduleStatus').addEventListener('change',filterSchedule);
var schedulePopover=document.getElementById('scheduleActionPopover'),scheduleIncomplete=document.getElementById('markScheduleIncomplete'),schedulePending=null;function hideSchedulePopover(){if(schedulePopover)schedulePopover.classList.remove('show');schedulePending=null;}function runScheduleStatus(type,id,completed,btn){setBusy(btn,true,completed?'Completing...':'Updating...');api({action:'set_schedule_completion',client_id:clientId,schedule_type:type,schedule_id:String(id),completed:completed?'1':'0'}).then(function(d){toast('success',d.message||'Schedule updated.');setTimeout(function(){location.reload();},300);}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(btn,false);});}var scheduleTable=document.getElementById('scheduleTable');if(scheduleTable)scheduleTable.addEventListener('click',function(e){var complete=e.target.closest('[data-schedule-complete]');if(complete){runScheduleStatus(complete.getAttribute('data-schedule-type'),complete.getAttribute('data-schedule-id'),true,complete);return;}var menu=e.target.closest('[data-schedule-menu]');if(menu&&schedulePopover){schedulePending={type:menu.getAttribute('data-schedule-type'),id:menu.getAttribute('data-schedule-id'),button:menu};var r=menu.getBoundingClientRect(),w=190,left=Math.max(8,Math.min(r.right-w,window.innerWidth-w-8)),top=r.bottom+6;if(top+55>window.innerHeight)top=Math.max(8,r.top-61);schedulePopover.style.left=left+'px';schedulePopover.style.top=top+'px';schedulePopover.classList.add('show');e.stopPropagation();}});if(scheduleIncomplete)scheduleIncomplete.addEventListener('click',function(){if(!schedulePending)return;var item=schedulePending;hideSchedulePopover();runScheduleStatus(item.type,item.id,false,item.button);});document.addEventListener('click',function(e){if(schedulePopover&&schedulePopover.classList.contains('show')&&!e.target.closest('#scheduleActionPopover')&&!e.target.closest('[data-schedule-menu]'))hideSchedulePopover();});window.addEventListener('scroll',hideSchedulePopover,true);

/* Task */
function openTask(){modal('taskModal',true);}['scheduleCreateButton'].forEach(function(id){var b=document.getElementById(id);if(b)b.addEventListener('click',openTask);});var workCreate=document.getElementById('workCreateButton');if(workCreate)workCreate.addEventListener('click',function(){var cb=document.getElementById('createButton');if(cb)cb.click();});document.querySelectorAll('[data-create="task"]').forEach(function(b){b.addEventListener('click',function(){document.querySelectorAll('.cv-dropdown.show').forEach(function(x){x.classList.remove('show');});openTask();});});
function taskState(){var later=document.getElementById('taskScheduleLater').checked,any=document.getElementById('taskAnytime').checked;['taskStartDate','taskEndDate'].forEach(function(id){document.getElementById(id).disabled=later;});['taskStartTime','taskEndTime'].forEach(function(id){document.getElementById(id).disabled=later||any;});}document.getElementById('taskScheduleLater').addEventListener('change',taskState);document.getElementById('taskAnytime').addEventListener('change',taskState);taskState();
var saveTask=document.getElementById('saveTaskButton');saveTask.addEventListener('click',function(){var title=document.getElementById('taskTitle').value.trim();if(!title){toast('warning','Enter a task title.');return;}var ass=document.getElementById('taskAssignee').value,atype='',aid='0';if(ass){var ap=ass.split(':');atype=ap[0];aid=ap[1]||'0';}setBusy(saveTask,true,'Saving...');api({action:'create_task',client_id:clientId,title:title,description:document.getElementById('taskDescription').value,property_id:document.getElementById('taskProperty').value,schedule_later:document.getElementById('taskScheduleLater').checked?'1':'0',anytime:document.getElementById('taskAnytime').checked?'1':'0',start_date:document.getElementById('taskStartDate').value,end_date:document.getElementById('taskEndDate').value,start_time:document.getElementById('taskStartTime').value,end_time:document.getElementById('taskEndTime').value,assignee_type:atype,assignee_id:aid,repeat:document.getElementById('taskRepeat').value}).then(function(d){toast('success',d.message||'Task created.');modal('taskModal',false);setTimeout(function(){location.reload();},400);}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(saveTask,false);});});

/* Notes */
var noteCollapsed=document.getElementById('noteCollapsed'),noteEditor=document.getElementById('noteEditor'),openNoteEditor=document.getElementById('openNoteEditor'),notesInput=document.getElementById('notesInput'),mentionBox=document.getElementById('mentionBox');
function showNoteEditor(show){if(!noteEditor)return;noteEditor.hidden=!show;if(noteCollapsed)noteCollapsed.style.display=show?'none':'';if(show&&notesInput){setTimeout(function(){notesInput.focus();},40);}if(!show&&mentionBox)mentionBox.classList.remove('show');}
if(openNoteEditor)openNoteEditor.addEventListener('click',function(){showNoteEditor(true);});
function positionMentionBox(){if(!notesInput||!mentionBox||!mentionBox.classList.contains('show'))return;var r=notesInput.getBoundingClientRect(),w=Math.min(340,Math.max(250,r.width)),left=Math.max(8,Math.min(r.left,window.innerWidth-w-8)),top=r.bottom+5;if(top+195>window.innerHeight)top=Math.max(8,r.top-195);mentionBox.style.width=w+'px';mentionBox.style.left=left+'px';mentionBox.style.top=top+'px';}function renderMentions(){if(!notesInput||!mentionBox)return;var pos=notesInput.selectionStart||0,before=notesInput.value.slice(0,pos),m=before.match(/(?:^|\s)@([^\s@]{0,40})$/);if(!m){mentionBox.classList.remove('show');return;}var q=(m[1]||'').toLowerCase(),matches=mentionUsers.filter(function(u){var name=String(u.name||'').trim(),email=String(u.email||'').trim();return !q||name.toLowerCase().indexOf(q)!==-1||email.toLowerCase().indexOf(q)!==-1;}).slice(0,10);if(!matches.length){mentionBox.innerHTML='<div class="cv-empty">No matching team members</div>';mentionBox.classList.add('show');positionMentionBox();return;}mentionBox.innerHTML=matches.map(function(u){return '<button type="button" class="cv-mention-item" data-mention="'+esc(String(u.name||u.email||''))+'"><span>'+esc(String(u.name||u.email||'Team member'))+'</span><small>'+esc(String(u.email||''))+'</small></button>';}).join('');mentionBox.classList.add('show');positionMentionBox();}
if(notesInput){notesInput.addEventListener('input',renderMentions);notesInput.addEventListener('click',renderMentions);}
if(mentionBox)mentionBox.addEventListener('click',function(e){var b=e.target.closest('[data-mention]');if(!b||!notesInput)return;var pos=notesInput.selectionStart||0,before=notesInput.value.slice(0,pos),after=notesInput.value.slice(pos),replacement='@'+b.getAttribute('data-mention')+' ',newBefore=before.replace(/@[^\s@]*$/,replacement);notesInput.value=newBefore+after;notesInput.focus();notesInput.selectionStart=notesInput.selectionEnd=newBefore.length;mentionBox.classList.remove('show');});
var notesBtn=document.getElementById('saveNotesButton');if(notesBtn)notesBtn.addEventListener('click',function(){var relatedTypes=Array.prototype.slice.call(document.querySelectorAll('.noteRelatedType:checked')).map(function(x){return x.value;});setBusy(notesBtn,true,'Saving...');api({action:'save_notes',client_id:clientId,notes:notesInput.value,link_related_types:JSON.stringify(relatedTypes)},noteFiles).then(function(d){originalNotes=notesInput.value;noteFiles=[];renderFiles('note');toast('success',d.message||'Note saved.');setTimeout(function(){location.reload();},300);}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(notesBtn,false);});});var cancelNote=document.getElementById('cancelNote');if(cancelNote)cancelNote.addEventListener('click',function(){if(notesInput)notesInput.value=originalNotes;document.querySelectorAll('.noteRelatedType').forEach(function(x){x.checked=true;});noteFiles=[];renderFiles('note');showNoteEditor(false);});

/* Tags */
var tagsModal=document.getElementById('tagsModal');function renderTagOptions(){var q=document.getElementById('tagSearch').value.toLowerCase().trim(),html='';allTags.forEach(function(t){if(q&&String(t.name).toLowerCase().indexOf(q)===-1)return;var checked=selectedTagIds.indexOf(Number(t.id))!==-1;html+='<label class="cv-tag-option"><input type="checkbox" data-tag-id="'+Number(t.id)+'" '+(checked?'checked':'')+'><span>'+esc(t.name)+'</span></label>';});document.getElementById('tagOptions').innerHTML=html||'<div class="cv-empty">No matching tags</div>';var selected=allTags.filter(function(t){return selectedTagIds.indexOf(Number(t.id))!==-1;});document.getElementById('selectedTagsPreview').innerHTML=selected.length?selected.map(function(t){return '<span class="cv-tag">'+esc(t.name)+'</span>';}).join(''):'<span style="color:var(--cv-muted);font-size:11px">No tags selected</span>';}
var editTags=document.getElementById('editTagsButton');if(editTags)editTags.addEventListener('click',function(){selectedTagIds=initialTagIds.slice();document.getElementById('tagSearch').value='';renderTagOptions();modal('tagsModal',true);});document.getElementById('tagSearch').addEventListener('input',renderTagOptions);document.getElementById('tagOptions').addEventListener('change',function(e){if(!e.target.matches('[data-tag-id]'))return;var id=Number(e.target.getAttribute('data-tag-id'));if(e.target.checked){if(selectedTagIds.indexOf(id)===-1)selectedTagIds.push(id);}else selectedTagIds=selectedTagIds.filter(function(x){return x!==id;});renderTagOptions();});document.getElementById('createTagButton').addEventListener('click',function(){var input=document.getElementById('newTagName'),name=input.value.trim();if(!name){toast('warning','Enter a tag name.');return;}var b=this;setBusy(b,true,'Creating...');api({action:'create_tag',client_id:clientId,name:name}).then(function(d){if(d.tag){allTags.push(d.tag);if(selectedTagIds.indexOf(Number(d.tag.id))===-1)selectedTagIds.push(Number(d.tag.id));input.value='';renderTagOptions();}toast('success',d.message||'Tag created.');}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(b,false);});});document.getElementById('saveTagsButton').addEventListener('click',function(){var b=this;setBusy(b,true,'Saving...');api({action:'save_tags',client_id:clientId,tag_ids:JSON.stringify(selectedTagIds)}).then(function(d){initialTagIds=selectedTagIds.slice();toast('success',d.message||'Tags updated.');modal('tagsModal',false);setTimeout(function(){location.reload();},350);}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(b,false);});});

/* Communication settings */
var communicationSettingsButton=document.getElementById('communicationSettingsButton');if(communicationSettingsButton)communicationSettingsButton.addEventListener('click',function(){modal('communicationSettingsModal',true);});
var saveCommunicationSettings=document.getElementById('saveCommunicationSettings');if(saveCommunicationSettings)saveCommunicationSettings.addEventListener('click',function(){var b=this;setBusy(b,true,'Saving...');api({action:'save_communication_settings',client_id:clientId,quote_followups:document.getElementById('prefQuote').checked?'1':'0',invoice_followups:document.getElementById('prefInvoice').checked?'1':'0',visit_reminders:document.getElementById('prefVisit').checked?'1':'0',job_close_followups:document.getElementById('prefJob').checked?'1':'0'}).then(function(d){toast('success',d.message||'Communication settings saved.');modal('communicationSettingsModal',false);setTimeout(function(){location.reload();},300);}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(b,false);});});

/* Right panel collapse */
var layout=document.getElementById('clientLayout'),asideToggle=document.getElementById('asideToggle'),aside=document.querySelector('.cv-aside');var asideKey='fieldplx.clientView.asideCollapsed',asideScrollKey='fieldplx.clientView.asideScroll.'+clientId;if(localStorage.getItem(asideKey)==='1')layout.classList.add('aside-collapsed');if(aside){try{var savedAsideScroll=parseInt(sessionStorage.getItem(asideScrollKey)||'0',10);if(savedAsideScroll>0)requestAnimationFrame(function(){aside.scrollTop=savedAsideScroll;});}catch(e){}aside.addEventListener('scroll',function(){try{sessionStorage.setItem(asideScrollKey,String(aside.scrollTop));}catch(e){}},{passive:true});}asideToggle.addEventListener('click',function(){layout.classList.toggle('aside-collapsed');localStorage.setItem(asideKey,layout.classList.contains('aside-collapsed')?'1':'0');});

/* Customize view */
var customizeSections={properties:{label:'Properties',icon:'bi-house-door'},contacts:{label:'Contacts',icon:'bi-person-vcard'},work:{label:'Work overview',icon:'bi-briefcase'},billing:{label:'Billing',icon:'bi-receipt'},schedule:{label:'Client schedule',icon:'bi-calendar3'},pricing:{label:'Recent pricing',icon:'bi-tags'},details:{label:'Client details',icon:'bi-person-lines-fill'}};
var defaultViewOrder=['properties','contacts','work','billing','schedule','pricing','details'];
var viewOrderKey='fieldplx.clientView.order.v2';
function getViewOrder(){var v=[];try{v=JSON.parse(localStorage.getItem(viewOrderKey)||'[]')||[];}catch(e){v=[];}v=v.filter(function(k,i){return customizeSections[k]&&v.indexOf(k)===i;});defaultViewOrder.forEach(function(k){if(v.indexOf(k)===-1)v.push(k);});return v;}
function applyViewOrder(){var stack=document.querySelector('#paneInformation .cv-stack');if(!stack)return;getViewOrder().forEach(function(k){var el=stack.querySelector('[data-view-section="'+k+'"]');if(el)stack.appendChild(el);});}
function saveViewOrder(order){localStorage.setItem(viewOrderKey,JSON.stringify(order));applyViewOrder();renderCustomize();}
function renderCustomize(){var order=getViewOrder(),html='';order.forEach(function(k,i){var item=customizeSections[k];html+='<div class="cv-customize-row" data-order-key="'+esc(k)+'"><span class="cv-customize-icon"><i class="bi '+esc(item.icon)+'"></i></span><span class="cv-customize-name">'+esc(item.label)+'</span><span class="cv-customize-arrows"><button type="button" class="cv-order-btn" data-move="up" '+(i===0?'disabled':'')+' title="Move up"><i class="bi bi-chevron-up"></i></button><button type="button" class="cv-order-btn" data-move="down" '+(i===order.length-1?'disabled':'')+' title="Move down"><i class="bi bi-chevron-down"></i></button></span></div>';});document.getElementById('customizeList').innerHTML=html;}
applyViewOrder();
var customizeAction=document.querySelector('[data-action="customize"]');if(customizeAction)customizeAction.addEventListener('click',function(){renderCustomize();modal('customizeModal',true);});
var customizeList=document.getElementById('customizeList');if(customizeList)customizeList.addEventListener('click',function(e){var btn=e.target.closest('[data-move]');if(!btn||btn.disabled)return;var row=btn.closest('[data-order-key]'),key=row?row.getAttribute('data-order-key'):'';var order=getViewOrder(),idx=order.indexOf(key);if(idx<0)return;var next=btn.getAttribute('data-move')==='up'?idx-1:idx+1;if(next<0||next>=order.length)return;var tmp=order[idx];order[idx]=order[next];order[next]=tmp;saveViewOrder(order);});
var resetCustomize=document.getElementById('resetCustomize');if(resetCustomize)resetCustomize.addEventListener('click',function(){localStorage.removeItem(viewOrderKey);applyViewOrder();renderCustomize();});

/* Confirmation/actions */
function confirmAction(title,copy,warning,buttonLabel,cb,danger){document.getElementById('confirmTitle').textContent=title;document.getElementById('confirmCopy').textContent=copy;var w=document.getElementById('confirmWarning');w.textContent=warning||'';w.style.display=warning?'block':'none';var b=document.getElementById('confirmActionButton');b.textContent=buttonLabel||'Continue';b.classList.toggle('danger',!!danger);confirmCallback=cb;modal('confirmModal',true);}document.getElementById('confirmActionButton').addEventListener('click',function(){if(typeof confirmCallback==='function')confirmCallback(this);});
function actionApi(action,btn,successRedirect){setBusy(btn,true,'Working...');api({action:action,client_id:clientId}).then(function(d){toast('success',d.message||'Done.');modal('confirmModal',false);if(d.redirect||successRedirect)setTimeout(function(){location.href=d.redirect||successRedirect;},400);else setTimeout(function(){location.reload();},350);}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(btn,false);});}
document.getElementById('moreMenu').addEventListener('click',function(e){var b=e.target.closest('[data-action]');if(!b)return;var a=b.getAttribute('data-action');document.getElementById('moreMenu').classList.remove('show');if(a==='send-login')modal('loginEmailModal',true);else if(a==='login-client')actionApi('login_as_client',b);else if(a==='archive')confirmAction('Archive this client?','The client will be hidden from active client lists. Linked work and history will remain.','','Archive',function(btn){actionApi('archive_client',btn,'clients.php');});else if(a==='delete')confirmAction('Delete this client?','The client profile will be soft-deleted and removed from normal client lists.','Linked historical records are not permanently erased by this action.','Delete Client',function(btn){actionApi('delete_client',btn,'clients.php');},true);});
var sendLoginEmailButton=document.getElementById('sendLoginEmailButton');if(sendLoginEmailButton)sendLoginEmailButton.addEventListener('click',function(){var b=this;setBusy(b,true,'Sending...');api({action:'send_login_email',client_id:clientId}).then(function(d){toast('success',d.message||'Login email sent.');modal('loginEmailModal',false);if(d.communication)addRecentCommunication(d.communication);setClientTab('communication');}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(b,false);});});

/* Login-as-client redirect needs a direct response, not generic reload. */
var originalActionApi=actionApi;actionApi=function(action,btn,successRedirect){if(action!=='login_as_client')return originalActionApi(action,btn,successRedirect);setBusy(btn,true,'Opening...');api({action:action,client_id:clientId}).then(function(d){if(d.redirect)window.location.href=d.redirect;else toast('success',d.message||'Client portal opened.');}).catch(function(e){toast('error',e.message);}).finally(function(){setBusy(btn,false);});};
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
