<?php
/* FieldPlx Client Form - Add Client - 2026-09-15 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Add Client';
$pageDescription = 'Create a client, property, contact and communication preferences';
$activePage = 'clients';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['clients_csrf_token'])) {
    $_SESSION['clients_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['clients_csrf_token'];

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/includes/db.php';
}
if ((!isset($pdo) || !($pdo instanceof PDO)) && isset($db) && $db instanceof PDO) {
    $pdo = $db;
}

function cfH($value)
{
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

function cfTable(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) return $cache[$table];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t'=>$table));
    $cache[$table] = (int)$q->fetchColumn() > 0;
    return $cache[$table];
}

function cfColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t'=>$table, ':c'=>$column));
    $cache[$key] = (int)$q->fetchColumn() > 0;
    return $cache[$key];
}

function cfColumns(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $q = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t'=>$table));
    $out = array();
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) $out[(string)$c] = true;
    $cache[$table] = $out;
    return $out;
}

function cfInsert(PDO $pdo, $table, array $data)
{
    $cols = cfColumns($pdo, $table);
    $filtered = array();
    foreach ($data as $k=>$v) {
        if (isset($cols[$k])) $filtered[$k] = $v;
    }
    if (!$filtered) throw new RuntimeException('No compatible columns found for ' . $table . '.');
    $names = array_keys($filtered);
    $marks = array();
    $params = array();
    foreach ($names as $name) {
        $marks[] = ':' . $name;
        $params[':' . $name] = $filtered[$name];
    }
    $sql = "INSERT INTO `" . str_replace('`','',$table) . "` (`" . implode('`,`',$names) . "`) VALUES (" . implode(',',$marks) . ")";
    $q = $pdo->prepare($sql);
    $q->execute($params);
    return (int)$pdo->lastInsertId();
}

function cfUserContext(PDO $pdo, $tenantId, $userId)
{
    $out = array('role_id'=>0,'is_tenant_admin'=>0,'is_role_admin'=>0,'branch_id'=>0);
    if ($userId <= 0 || !cfTable($pdo,'users')) return $out;
    $roleJoin = cfTable($pdo,'roles') && cfColumn($pdo,'users','role_id') ? "LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id" : '';
    $roleAdmin = cfTable($pdo,'roles') && cfColumn($pdo,'roles','is_admin') ? 'COALESCE(r.is_admin,0)' : '0';
    $deleted = cfColumn($pdo,'users','deleted_at') ? ' AND u.deleted_at IS NULL' : '';
    $sql = "SELECT " . (cfColumn($pdo,'users','role_id')?'u.role_id':'0 AS role_id') . ", "
        . (cfColumn($pdo,'users','is_tenant_admin')?'u.is_tenant_admin':'0 AS is_tenant_admin') . ", "
        . (cfColumn($pdo,'users','branch_id')?'u.branch_id':'0 AS branch_id') . ", $roleAdmin AS is_role_admin
        FROM users u $roleJoin WHERE u.id=:u AND u.tenant_id=:t$deleted LIMIT 1";
    $q=$pdo->prepare($sql);$q->execute(array(':u'=>$userId,':t'=>$tenantId));
    $r=$q->fetch(PDO::FETCH_ASSOC);
    if($r){foreach($out as $k=>$v)$out[$k]=isset($r[$k])?(int)$r[$k]:$v;}
    return $out;
}

function cfHasPermission(PDO $pdo, $tenantId, $userId, $ctx)
{
    if (!empty($ctx['is_tenant_admin']) || !empty($ctx['is_role_admin'])) return true;
    if (!cfTable($pdo,'permissions')) return true;

    if (cfColumn($pdo,'permissions','permission_code')) {
        $q=$pdo->prepare("SELECT id FROM permissions WHERE permission_code='clients.create' LIMIT 1");
        $q->execute();$pid=(int)$q->fetchColumn();
        if($pid>0){
            if(cfTable($pdo,'user_permissions')){
                $q=$pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
                $q->execute(array(':t'=>$tenantId,':u'=>$userId,':p'=>$pid));$v=$q->fetchColumn();if($v!==false)return $v==='allow';
            }
            if(!empty($ctx['role_id'])&&cfTable($pdo,'role_permissions')){
                $q=$pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
                $q->execute(array(':t'=>$tenantId,':r'=>(int)$ctx['role_id'],':p'=>$pid));$v=$q->fetchColumn();if($v!==false)return $v==='allow';
            }
        }
    }

    if (cfTable($pdo,'modules') && cfColumn($pdo,'permissions','action_code') && cfColumn($pdo,'permissions','module_id')) {
        $q=$pdo->prepare("SELECT p.id FROM permissions p INNER JOIN modules m ON m.id=p.module_id WHERE m.module_code IN('clients','client') AND LOWER(p.action_code)='create'");
        $q->execute();$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if($ids){
            $ph=implode(',',array_fill(0,count($ids),'?'));
            if(cfTable($pdo,'user_permissions')){
                $q=$pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=? AND user_id=? AND permission_id IN($ph) ORDER BY CASE access_type WHEN 'deny' THEN 0 ELSE 1 END");
                $q->execute(array_merge(array($tenantId,$userId),$ids));$v=$q->fetchAll(PDO::FETCH_COLUMN);if($v){if(in_array('deny',$v,true))return false;if(in_array('allow',$v,true))return true;}
            }
            if(!empty($ctx['role_id'])&&cfTable($pdo,'role_permissions')){
                $q=$pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=? AND role_id=? AND permission_id IN($ph) ORDER BY CASE access_type WHEN 'deny' THEN 0 ELSE 1 END");
                $q->execute(array_merge(array($tenantId,(int)$ctx['role_id']),$ids));$v=$q->fetchAll(PDO::FETCH_COLUMN);if($v){if(in_array('deny',$v,true))return false;if(in_array('allow',$v,true))return true;}
            }
        }
    }
    return false;
}

$tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = !empty($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (!empty($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$ctx = cfUserContext($pdo,$tenantId,$userId);
$canCreate = cfHasPermission($pdo,$tenantId,$userId,$ctx);
if (!$canCreate) { http_response_code(403); exit('You do not have permission to create clients.'); }

$branches=array();$users=array();$countries=array();$tags=array();$errors=array();
if(cfTable($pdo,'branches')){
    $where=cfColumn($pdo,'branches','status')?" AND status='active'":'';
    $q=$pdo->prepare("SELECT id,name FROM branches WHERE tenant_id=:t$where ORDER BY name,id");$q->execute(array(':t'=>$tenantId));$branches=$q->fetchAll(PDO::FETCH_ASSOC);
}
if(cfTable($pdo,'users')){
    $deleted=cfColumn($pdo,'users','deleted_at')?' AND deleted_at IS NULL':'';$status=cfColumn($pdo,'users','status')?" AND status='active'":'';
    $q=$pdo->prepare("SELECT id,TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) name,email FROM users WHERE tenant_id=:t$status$deleted ORDER BY first_name,last_name,id");$q->execute(array(':t'=>$tenantId));$users=$q->fetchAll(PDO::FETCH_ASSOC);
}
if(cfTable($pdo,'countries')){
    $nameCol=cfColumn($pdo,'countries','name')?'name':(cfColumn($pdo,'countries','country_name')?'country_name':'id');
    $q=$pdo->query("SELECT id,$nameCol AS name FROM countries ORDER BY name");$countries=$q->fetchAll(PDO::FETCH_ASSOC);
}
if(cfTable($pdo,'client_tags')){
    $active=cfColumn($pdo,'client_tags','is_active')?' AND is_active=1':'';
    $q=$pdo->prepare("SELECT id,name FROM client_tags WHERE tenant_id=:t$active ORDER BY name,id");$q->execute(array(':t'=>$tenantId));$tags=$q->fetchAll(PDO::FETCH_ASSOC);
}

$form = array(
    'client_type'=>'client','display_name'=>'','company_name'=>'','first_name'=>'','last_name'=>'','email'=>'','phone'=>'','alternate_phone'=>'','source'=>'','status'=>'active','preferred_contact_method'=>'phone','tax_number'=>'','account_manager_id'=>'','branch_id'=>'','notes'=>'',
    'allow_email'=>1,'allow_sms'=>1,'quote_followups'=>1,'invoice_followups'=>1,'visit_reminders'=>1,'job_close_followups'=>1,
    'property_name'=>'Primary property','address_line1'=>'','address_line2'=>'','city'=>'','state'=>'','postal_code'=>'','country'=>'India','country_id'=>'','contact_first_name'=>'','contact_last_name'=>'','contact_title'=>'','contact_email'=>'','contact_phone'=>''
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach($form as $k=>$v){ if(isset($_POST[$k])&&!is_array($_POST[$k]))$form[$k]=trim((string)$_POST[$k]); }
    foreach(array('allow_email','allow_sms','quote_followups','invoice_followups','visit_reminders','job_close_followups') as $k)$form[$k]=isset($_POST[$k])?1:0;

    $additionalAddresses = array();
    $additionalContacts = array();
    $customFields = array();
    foreach (array('additional_addresses_json'=>'additionalAddresses','additional_contacts_json'=>'additionalContacts','custom_fields_json'=>'customFields') as $postKey=>$varName) {
        $decoded = json_decode(isset($_POST[$postKey]) ? (string)$_POST[$postKey] : '[]', true);
        if (is_array($decoded)) {
            if ($varName === 'additionalAddresses') $additionalAddresses = $decoded;
            elseif ($varName === 'additionalContacts') $additionalContacts = $decoded;
            else $customFields = $decoded;
        }
    }

    if (!hash_equals($csrfToken, isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '')) $errors[]='Your session token expired. Refresh the page and try again.';
    if ($tenantId<=0 || $userId<=0) $errors[]='Invalid tenant session.';
    if ($form['display_name']==='') {
        $derivedName = trim($form['first_name'] . ' ' . $form['last_name']);
        if ($derivedName === '') $derivedName = trim($form['company_name']);
        $form['display_name'] = $derivedName;
    }
    if ($form['display_name']==='') $errors[]='First name, last name, or company name is required.';
    if ($form['email']!=='' && !filter_var($form['email'],FILTER_VALIDATE_EMAIL)) $errors[]='Enter a valid client email address.';
    if ($form['contact_email']!=='' && !filter_var($form['contact_email'],FILTER_VALIDATE_EMAIL)) $errors[]='Enter a valid contact email address.';
    if (!in_array($form['client_type'],array('lead','client'),true)) $form['client_type']='client';
    if (!in_array($form['preferred_contact_method'],array('phone','email','sms'),true)) $form['preferred_contact_method']='phone';
    if (!in_array($form['status'],array('new','active','inactive'),true)) $form['status']=$form['client_type']==='lead'?'new':'active';

    if(!$errors && cfTable($pdo,'clients')){
        $parts=array("LOWER(display_name)=LOWER(:n)");$params=array(':t'=>$tenantId,':n'=>$form['display_name']);
        if($form['email']!=='' && cfColumn($pdo,'clients','email')){$parts[]="LOWER(email)=LOWER(:e)";$params[':e']=$form['email'];}
        if($form['phone']!=='' && cfColumn($pdo,'clients','phone')){$parts[]="phone=:p";$params[':p']=$form['phone'];}
        $deleted=cfColumn($pdo,'clients','deleted_at')?' AND deleted_at IS NULL':'';
        $q=$pdo->prepare("SELECT id FROM clients WHERE tenant_id=:t$deleted AND (".implode(' OR ',$parts).") LIMIT 1");$q->execute($params);
        if($q->fetchColumn())$errors[]='Another client with the same name, email, or phone already exists.';
    }

    if(!$errors){
        try{
            $pdo->beginTransaction();
            $clientData=array(
                'tenant_id'=>$tenantId,
                'branch_id'=>$form['branch_id']!==''?(int)$form['branch_id']:(!empty($ctx['branch_id'])?(int)$ctx['branch_id']:null),
                'client_type'=>$form['client_type'],
                'display_name'=>$form['display_name'],
                'company_name'=>$form['company_name']!==''?$form['company_name']:null,
                'first_name'=>$form['first_name']!==''?$form['first_name']:null,
                'last_name'=>$form['last_name']!==''?$form['last_name']:null,
                'email'=>$form['email']!==''?$form['email']:null,
                'phone'=>$form['phone']!==''?$form['phone']:null,
                'alternate_phone'=>$form['alternate_phone']!==''?$form['alternate_phone']:null,
                'source'=>$form['source']!==''?$form['source']:null,
                'status'=>$form['status'],
                'notes'=>$form['notes']!==''?$form['notes']:null,
                'preferred_contact_method'=>$form['preferred_contact_method'],
                'allow_email'=>(int)$form['allow_email'],
                'allow_sms'=>(int)$form['allow_sms'],
                'billing_address_line1'=>$form['address_line1']!==''?$form['address_line1']:null,
                'billing_address_line2'=>$form['address_line2']!==''?$form['address_line2']:null,
                'billing_city'=>$form['city']!==''?$form['city']:null,
                'billing_state'=>$form['state']!==''?$form['state']:null,
                'billing_postal_code'=>$form['postal_code']!==''?$form['postal_code']:null,
                'billing_country'=>$form['country']!==''?$form['country']:null,
                'tax_number'=>$form['tax_number']!==''?$form['tax_number']:null,
                'account_manager_id'=>$form['account_manager_id']!==''?(int)$form['account_manager_id']:null,
                'created_by'=>$userId,
                'updated_by'=>$userId,
                'last_activity_at'=>date('Y-m-d H:i:s'),
                'created_at'=>date('Y-m-d H:i:s'),
                'updated_at'=>date('Y-m-d H:i:s'),
                'deleted_at'=>null
            );
            $clientId=cfInsert($pdo,'clients',$clientData);
            if($clientId<=0)throw new RuntimeException('Client could not be created.');

            if(cfTable($pdo,'client_phone_numbers')){
                if($form['phone']!=='')cfInsert($pdo,'client_phone_numbers',array('tenant_id'=>$tenantId,'client_id'=>$clientId,'phone_number'=>$form['phone'],'phone_type'=>'main','receives_messages'=>(int)$form['allow_sms'],'is_primary'=>1,'sort_order'=>1,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')));
                if($form['alternate_phone']!=='' && $form['alternate_phone']!==$form['phone'])cfInsert($pdo,'client_phone_numbers',array('tenant_id'=>$tenantId,'client_id'=>$clientId,'phone_number'=>$form['alternate_phone'],'phone_type'=>'other','receives_messages'=>(int)$form['allow_sms'],'is_primary'=>0,'sort_order'=>2,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')));
            }

            $hasProperty = implode('',array($form['address_line1'],$form['address_line2'],$form['city'],$form['state'],$form['postal_code']))!=='';
            if($hasProperty && cfTable($pdo,'client_locations')){
                cfInsert($pdo,'client_locations',array(
                    'tenant_id'=>$tenantId,'client_id'=>$clientId,'branch_id'=>$clientData['branch_id'],'name'=>$form['property_name']!==''?$form['property_name']:'Primary property','location_type'=>'property','address_line1'=>$form['address_line1']!==''?$form['address_line1']:null,'address_line2'=>$form['address_line2']!==''?$form['address_line2']:null,'city'=>$form['city']!==''?$form['city']:null,'state'=>$form['state']!==''?$form['state']:null,'postal_code'=>$form['postal_code']!==''?$form['postal_code']:null,'country'=>$form['country']!==''?$form['country']:null,'country_id'=>$form['country_id']!==''?(int)$form['country_id']:null,'is_primary'=>1,'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),'deleted_at'=>null
                ));
            }

            if($form['contact_first_name']!=='' && cfTable($pdo,'client_contacts')){
                cfInsert($pdo,'client_contacts',array('tenant_id'=>$tenantId,'client_id'=>$clientId,'first_name'=>$form['contact_first_name'],'last_name'=>$form['contact_last_name']!==''?$form['contact_last_name']:null,'title'=>$form['contact_title']!==''?$form['contact_title']:null,'email'=>$form['contact_email']!==''?$form['contact_email']:null,'phone'=>$form['contact_phone']!==''?$form['contact_phone']:null,'is_primary'=>0,'is_billing_contact'=>0,'allow_email'=>1,'allow_sms'=>1,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')));
            }

            if (cfTable($pdo,'client_locations') && $additionalAddresses) {
                foreach ($additionalAddresses as $index=>$addr) {
                    if (!is_array($addr)) continue;
                    $hasAny = trim((string)($addr['address_line1'] ?? '')) !== '' || trim((string)($addr['city'] ?? '')) !== '';
                    if (!$hasAny) continue;
                    cfInsert($pdo,'client_locations',array(
                        'tenant_id'=>$tenantId,'client_id'=>$clientId,'branch_id'=>$clientData['branch_id'],
                        'name'=>trim((string)($addr['name'] ?? '')) !== '' ? trim((string)$addr['name']) : 'Property '.($index+2),
                        'location_type'=>'property','address_line1'=>trim((string)($addr['address_line1'] ?? '')) ?: null,
                        'address_line2'=>trim((string)($addr['address_line2'] ?? '')) ?: null,'city'=>trim((string)($addr['city'] ?? '')) ?: null,
                        'state'=>trim((string)($addr['state'] ?? '')) ?: null,'postal_code'=>trim((string)($addr['postal_code'] ?? '')) ?: null,
                        'country'=>trim((string)($addr['country'] ?? '')) ?: null,'is_primary'=>0,'status'=>'active',
                        'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),'deleted_at'=>null
                    ));
                }
            }

            if (cfTable($pdo,'client_contacts') && $additionalContacts) {
                foreach ($additionalContacts as $contact) {
                    if (!is_array($contact) || trim((string)($contact['first_name'] ?? '')) === '') continue;
                    cfInsert($pdo,'client_contacts',array(
                        'tenant_id'=>$tenantId,'client_id'=>$clientId,'first_name'=>trim((string)$contact['first_name']),
                        'last_name'=>trim((string)($contact['last_name'] ?? '')) ?: null,'title'=>trim((string)($contact['role'] ?? '')) ?: null,
                        'email'=>trim((string)($contact['email'] ?? '')) ?: null,'phone'=>trim((string)($contact['phone'] ?? '')) ?: null,
                        'is_primary'=>0,'is_billing_contact'=>!empty($contact['billing'])?1:0,'allow_email'=>1,'allow_sms'=>1,
                        'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')
                    ));
                }
            }

            if ($customFields && cfTable($pdo,'client_custom_field_definitions')) {
                foreach ($customFields as $idx=>$field) {
                    if (!is_array($field) || trim((string)($field['name'] ?? '')) === '') continue;
                    try {
                        $fieldId = cfInsert($pdo,'client_custom_field_definitions',array(
                            'tenant_id'=>$tenantId,'field_name'=>trim((string)$field['name']),'field_type'=>trim((string)($field['type'] ?? 'text')),
                            'applies_to'=>'client','default_value'=>trim((string)($field['default'] ?? '')) ?: null,'status'=>'active','sort_order'=>$idx+1,
                            'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')
                        ));
                        if ($fieldId > 0 && cfTable($pdo,'client_custom_field_values')) {
                            cfInsert($pdo,'client_custom_field_values',array(
                                'tenant_id'=>$tenantId,'client_id'=>$clientId,'field_id'=>$fieldId,'field_value'=>trim((string)($field['default'] ?? '')),
                                'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')
                            ));
                        }
                    } catch (Throwable $ignore) {
                        error_log('FieldPlx client custom field create: '.$ignore->getMessage());
                    }
                }
            }

            if(cfTable($pdo,'client_communication_preferences')){
                cfInsert($pdo,'client_communication_preferences',array('tenant_id'=>$tenantId,'client_id'=>$clientId,'quote_followups'=>(int)$form['quote_followups'],'invoice_followups'=>(int)$form['invoice_followups'],'visit_reminders'=>(int)$form['visit_reminders'],'job_close_followups'=>(int)$form['job_close_followups'],'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')));
            }

            $tagIds=isset($_POST['tag_ids'])&&is_array($_POST['tag_ids'])?array_values(array_unique(array_filter(array_map('intval',$_POST['tag_ids'])))):array();
            if($tagIds && cfTable($pdo,'client_tag_assignments')){
                foreach($tagIds as $tagId){
                    if($tagId<=0)continue;
                    cfInsert($pdo,'client_tag_assignments',array('tenant_id'=>$tenantId,'client_id'=>$clientId,'tag_id'=>$tagId,'created_at'=>date('Y-m-d H:i:s')));
                }
            }

            if(cfTable($pdo,'activity_events')){
                try{cfInsert($pdo,'activity_events',array('tenant_id'=>$tenantId,'branch_id'=>$clientData['branch_id'],'actor_user_id'=>$userId,'event_type'=>'client_created','related_type'=>'client','related_id'=>$clientId,'client_id'=>$clientId,'title'=>'Client created','details_json'=>json_encode(array('display_name'=>$form['display_name']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>date('Y-m-d H:i:s')));}catch(Throwable $ignore){}
            }

            $pdo->commit();
            if (isset($_POST['save_mode']) && $_POST['save_mode'] === 'another') {
                header('Location: client-form.php?created=' . $clientId);
            } else {
                header('Location: client-view.php?client_id=' . $clientId . '&created=1');
            }
            exit;
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            error_log('FieldPlx client form create: '.$e->getMessage());
            $errors[]='Unable to create the client. '.$e->getMessage();
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{--jf-primary:var(--primary,#2f8d25);--jf-text:var(--text,#0f3343);--jf-muted:var(--muted,#5f7885);--jf-card:var(--card-bg,#fff);--jf-bg:var(--body-bg,#f5f6f4);--jf-border:var(--card-border,#d9e0e3);--jf-input:var(--input-bg,#fff);--jf-input-border:var(--input-border,#d5dde1);--jf-hover:var(--table-hover-bg,#f4f6f4);--jf-danger:#d84d4d;--jf-blue:#dff1ff}
.jf-page{max-width:1180px;margin:0 auto;padding:22px 28px 110px;color:var(--jf-text)}
.jf-title{margin:0 0 30px;font-size:34px;line-height:1.05;font-weight:800;letter-spacing:-.7px}.jf-form{display:grid;gap:34px}.jf-section{display:grid;grid-template-columns:300px minmax(0,1fr);gap:56px;align-items:start}.jf-section+.jf-section{padding-top:34px;border-top:1px solid var(--jf-border)}.jf-label-col h2{margin:0 0 10px;font-size:20px;line-height:1.2;font-weight:800}.jf-label-col p{max-width:240px;margin:0;color:var(--jf-muted);font-size:12px;line-height:1.35}.jf-label-col .jf-outline{margin-top:16px}.jf-content{min-width:0}.jf-fieldset{border:1px solid var(--jf-input-border);border-radius:9px;background:var(--jf-input);overflow:hidden}.jf-row{display:grid;border-top:1px solid var(--jf-input-border)}.jf-row:first-child{border-top:0}.jf-row.cols3{grid-template-columns:120px 1fr 1fr}.jf-row.cols2{grid-template-columns:1fr 1fr}.jf-cell{min-width:0;position:relative;border-left:1px solid var(--jf-input-border)}.jf-cell:first-child{border-left:0}.jf-input,.jf-select{width:100%;height:50px;border:0;background:transparent;color:var(--jf-text);padding:0 16px;font:inherit;font-size:13px;outline:0}.jf-cell.float label{position:absolute;top:7px;left:16px;color:var(--jf-muted);font-size:9px;pointer-events:none}.jf-cell.float .jf-select{padding-top:14px}.jf-input:focus,.jf-select:focus{box-shadow:inset 0 0 0 2px color-mix(in srgb,var(--jf-primary) 46%,transparent)}.jf-block{margin-top:18px}.jf-block-title{margin:0 0 9px;font-size:13px;font-weight:800}.jf-link{border:0;background:transparent;padding:0;color:var(--jf-primary);font:inherit;font-size:12px;font-weight:700;text-decoration:underline;cursor:pointer}.jf-accordion{margin-top:14px;border-radius:9px;background:color-mix(in srgb,var(--jf-card) 96%,var(--jf-bg));overflow:hidden}.jf-accordion-btn{width:100%;min-height:58px;padding:0 16px;border:1px solid var(--jf-border);border-radius:9px;background:transparent;color:var(--jf-text);display:flex;align-items:center;justify-content:space-between;font:inherit;font-size:13px;font-weight:800;cursor:pointer;text-align:left}.jf-accordion-btn i{color:var(--jf-primary);transition:.18s}.jf-accordion.open .jf-accordion-btn i{transform:rotate(180deg)}.jf-accordion-body{display:none;padding:16px;border:1px solid var(--jf-border);border-top:0;border-radius:0 0 9px 9px}.jf-accordion.open .jf-accordion-body{display:block}.jf-inline-action{display:flex;align-items:center;justify-content:space-between;gap:18px}.jf-inline-action p{margin:0;color:var(--jf-muted);font-size:11px;line-height:1.4}.jf-btn{height:40px;padding:0 16px;border:1px solid var(--jf-border);border-radius:8px;background:var(--jf-card);color:var(--jf-text);display:inline-flex;align-items:center;justify-content:center;gap:7px;font:inherit;font-size:12px;font-weight:700;text-decoration:none!important;cursor:pointer;white-space:nowrap}.jf-btn:hover{border-color:var(--jf-primary);color:var(--jf-primary);background:var(--jf-hover)}.jf-btn.primary{border-color:var(--jf-primary);background:var(--jf-primary);color:var(--primary-text,#fff)!important}.jf-btn.primary:hover{filter:brightness(.95)}.jf-outline{background:var(--jf-card);color:var(--jf-primary)}
.jf-stack{display:grid;gap:10px}.jf-subcard{padding:12px 14px;border:1px solid var(--jf-border);border-radius:8px;background:var(--jf-card);display:flex;align-items:center;justify-content:space-between;gap:12px}.jf-subcard strong{display:block;font-size:12px}.jf-subcard small{display:block;margin-top:3px;color:var(--jf-muted);font-size:10px}.jf-subcard button{border:0;background:transparent;color:var(--jf-danger);cursor:pointer}.jf-error{margin-bottom:18px;padding:12px 14px;border:1px solid color-mix(in srgb,var(--jf-danger) 35%,var(--jf-border));border-radius:8px;background:color-mix(in srgb,var(--jf-danger) 7%,var(--jf-card));color:var(--jf-danger);font-size:11px}.jf-error ul{margin:0;padding-left:18px}
.jf-bottom-bar{position:fixed;left:var(--fieldplx-sidebar-width,0px);right:0;bottom:0;z-index:80;min-height:72px;padding:12px max(24px,calc((100vw - var(--fieldplx-sidebar-width,0px) - 1180px)/2 + 28px));border-top:1px solid var(--jf-border);background:color-mix(in srgb,var(--jf-card) 96%,transparent);backdrop-filter:blur(12px);display:flex;align-items:center;justify-content:space-between;gap:12px}.jf-bottom-right{display:flex;gap:8px;margin-left:auto}
.jf-modal-bg{display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;padding:18px;background:rgba(5,18,25,.45);backdrop-filter:blur(2px)}.jf-modal-bg.show{display:flex}.jf-modal{width:min(620px,calc(100vw - 28px));max-height:calc(100vh - 36px);overflow:auto;border:1px solid var(--jf-border);border-radius:9px;background:var(--jf-card);box-shadow:0 24px 70px rgba(4,20,29,.25)}.jf-modal-head{padding:22px 24px 12px;display:flex;align-items:center;gap:12px}.jf-modal-head h3{margin:0;font-size:24px;font-weight:800}.jf-close{margin-left:auto;width:34px;height:34px;border:0;border-radius:7px;background:transparent;color:var(--jf-text);font-size:20px;cursor:pointer}.jf-close:hover{background:var(--jf-hover)}.jf-modal-body{padding:10px 24px 18px}.jf-modal-footer{padding:0 24px 22px;display:flex;justify-content:flex-end;gap:8px}.jf-modal-copy{margin:0 0 14px;color:var(--jf-text);font-size:12px;line-height:1.45}.jf-settings-box{border:1px solid var(--jf-border);border-radius:8px;padding:10px 16px}.jf-setting-title{padding:6px 0;font-size:12px;font-weight:800}.jf-setting-row{min-height:42px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:12px}.jf-switch{position:relative;width:48px;height:25px;flex:0 0 48px}.jf-switch input{position:absolute;opacity:0}.jf-switch span{position:absolute;inset:0;border-radius:999px;background:color-mix(in srgb,var(--jf-muted) 30%,var(--jf-card));cursor:pointer}.jf-switch span:before{content:'×';position:absolute;left:7px;top:2px;color:var(--jf-text);font-size:17px;line-height:20px}.jf-switch span:after{content:"";position:absolute;width:19px;height:19px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.24);transition:.18s}.jf-switch input:checked+span{background:var(--jf-primary)}.jf-switch input:checked+span:before{content:'✓';left:8px;color:#fff;font-size:14px}.jf-switch input:checked+span:after{transform:translateX(23px)}.jf-info{margin:14px 0;padding:12px 14px;border-radius:7px;background:var(--jf-blue);font-size:11px}.jf-modal-field{margin-bottom:11px}.jf-modal-field label{display:block;margin-bottom:5px;font-size:11px;font-weight:700}.jf-modal-input,.jf-modal-select{width:100%;height:46px;padding:0 12px;border:1px solid var(--jf-input-border);border-radius:8px;background:var(--jf-input);color:var(--jf-text);font:inherit;font-size:12px;outline:0}.jf-modal-input:focus,.jf-modal-select:focus{border-color:var(--jf-primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--jf-primary) 12%,transparent)}.jf-modal-grid{display:grid;grid-template-columns:120px 1fr 1fr;border:1px solid var(--jf-input-border);border-radius:8px;overflow:hidden}.jf-modal-grid>*{border:0;border-left:1px solid var(--jf-input-border);border-radius:0}.jf-modal-grid>*:first-child{border-left:0}.jf-check{display:flex;align-items:center;gap:8px;margin:10px 0;font-size:11px}.jf-check input{width:17px;height:17px;accent-color:var(--jf-primary)}
html.app-dark-mode .jf-info{background:color-mix(in srgb,#1f6b96 35%,var(--jf-card))}.jf-hidden{display:none!important}
@media(max-width:900px){.jf-page{padding:18px 16px 110px}.jf-section{grid-template-columns:1fr;gap:16px}.jf-label-col p{max-width:none}.jf-bottom-bar{left:0;padding:10px 16px}.jf-title{font-size:29px}}
@media(max-width:620px){.jf-row.cols3,.jf-row.cols2,.jf-modal-grid{grid-template-columns:1fr}.jf-cell{border-left:0;border-top:1px solid var(--jf-input-border)}.jf-cell:first-child{border-top:0}.jf-bottom-bar{flex-wrap:wrap}.jf-bottom-right{width:100%}.jf-bottom-right .jf-btn{flex:1}.jf-title{font-size:27px}.jf-modal-head h3{font-size:20px}}
</style>
<div class="jf-page">
  <h1 class="jf-title">New Client</h1>
  <?php if($errors): ?><div class="jf-error"><ul><?php foreach($errors as $error): ?><li><?= cfH($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" id="clientForm" class="jf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= cfH($csrfToken); ?>">
    <input type="hidden" name="display_name" id="displayName" value="<?= cfH($form['display_name']); ?>">
    <input type="hidden" name="additional_addresses_json" id="additionalAddressesJson" value="[]">
    <input type="hidden" name="additional_contacts_json" id="additionalContactsJson" value="[]">
    <input type="hidden" name="custom_fields_json" id="customFieldsJson" value="[]">
    <input type="hidden" name="contact_first_name" id="legacyContactFirst" value="">
    <input type="hidden" name="contact_last_name" id="legacyContactLast" value="">
    <input type="hidden" name="contact_title" id="legacyContactTitle" value="">
    <input type="hidden" name="contact_phone" id="legacyContactPhone" value="">
    <input type="hidden" name="contact_email" id="legacyContactEmail" value="">

    <section class="jf-section">
      <div class="jf-label-col"><h2>Primary contact details</h2><p>Provide the main point of contact to ensure smooth communication and reliable client records.</p></div>
      <div class="jf-content">
        <div class="jf-fieldset">
          <div class="jf-row cols3">
            <div class="jf-cell float"><label>Title</label><select class="jf-select" name="title_prefix"><option value="">No title</option><option>Mr.</option><option>Mrs.</option><option>Ms.</option><option>Dr.</option></select></div>
            <div class="jf-cell"><input class="jf-input" id="firstName" name="first_name" maxlength="120" placeholder="First name" value="<?= cfH($form['first_name']); ?>"></div>
            <div class="jf-cell"><input class="jf-input" id="lastName" name="last_name" maxlength="120" placeholder="Last name" value="<?= cfH($form['last_name']); ?>"></div>
          </div>
          <div class="jf-row"><div class="jf-cell"><input class="jf-input" id="companyName" name="company_name" maxlength="190" placeholder="Company name" value="<?= cfH($form['company_name']); ?>"></div></div>
        </div>

        <div class="jf-block"><h3 class="jf-block-title">Communication</h3>
          <div class="jf-fieldset">
            <div class="jf-row"><div class="jf-cell"><input class="jf-input" name="phone" maxlength="50" placeholder="Phone number" value="<?= cfH($form['phone']); ?>"></div></div>
            <div class="jf-row"><div class="jf-cell"><input class="jf-input" type="email" name="email" maxlength="190" placeholder="Email" value="<?= cfH($form['email']); ?>"></div></div>
          </div>
        </div>
        <div class="jf-block"><button type="button" class="jf-link" id="communicationSettingsLink">Communication settings</button></div>

        <div class="jf-block"><h3 class="jf-block-title">Lead information</h3><div class="jf-fieldset"><div class="jf-row"><div class="jf-cell"><input class="jf-input" name="source" maxlength="120" placeholder="Lead source" value="<?= cfH($form['source']); ?>"></div></div></div></div>

        <div class="jf-accordion open" id="additionalDetailsAccordion">
          <button class="jf-accordion-btn" type="button" id="additionalDetailsToggle"><span>Additional client details</span><i class="bi bi-chevron-up"></i></button>
          <div class="jf-accordion-body">
            <div class="jf-inline-action"><p>Create custom fields to track additional details</p><button type="button" class="jf-btn jf-outline" id="addCustomFieldButton">Add Custom Field</button></div>
            <div class="jf-stack" id="customFieldList" style="margin-top:12px"></div>
            <div style="margin-top:14px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px">
              <select class="jf-modal-select" name="client_type" id="clientType"><option value="client" <?= $form['client_type']==='client'?'selected':''; ?>>Client</option><option value="lead" <?= $form['client_type']==='lead'?'selected':''; ?>>Lead</option></select>
              <select class="jf-modal-select" name="status" id="status"><option value="active" <?= $form['status']==='active'?'selected':''; ?>>Active</option><option value="new" <?= $form['status']==='new'?'selected':''; ?>>New</option><option value="inactive" <?= $form['status']==='inactive'?'selected':''; ?>>Inactive</option></select>
              <select class="jf-modal-select" name="preferred_contact_method"><option value="phone" <?= $form['preferred_contact_method']==='phone'?'selected':''; ?>>Preferred contact: Phone</option><option value="email" <?= $form['preferred_contact_method']==='email'?'selected':''; ?>>Preferred contact: Email</option><option value="sms" <?= $form['preferred_contact_method']==='sms'?'selected':''; ?>>Preferred contact: SMS</option></select>
              <input class="jf-modal-input" name="tax_number" maxlength="100" placeholder="Tax number" value="<?= cfH($form['tax_number']); ?>">
              <?php if($users): ?><select class="jf-modal-select" name="account_manager_id"><option value="">Account manager</option><?php foreach($users as $u): $un=trim((string)$u['name'])!==''?$u['name']:$u['email']; ?><option value="<?= (int)$u['id']; ?>" <?= (string)$form['account_manager_id']===(string)$u['id']?'selected':''; ?>><?= cfH($un); ?></option><?php endforeach; ?></select><?php endif; ?>
              <?php if($branches): ?><select class="jf-modal-select" name="branch_id"><option value="">Use my branch</option><?php foreach($branches as $b): ?><option value="<?= (int)$b['id']; ?>" <?= (string)$form['branch_id']===(string)$b['id']?'selected':''; ?>><?= cfH($b['name']); ?></option><?php endforeach; ?></select><?php endif; ?>
              <textarea class="jf-modal-input" name="notes" style="height:86px;padding-top:12px;grid-column:1/-1" placeholder="Internal notes"><?= cfH($form['notes']); ?></textarea>
            </div>
          </div>
        </div>

        <div class="jf-accordion" id="additionalContactsAccordion">
          <button class="jf-accordion-btn" type="button" id="additionalContactsToggle"><span>Additional contacts</span><i class="bi bi-chevron-down"></i></button>
          <div class="jf-accordion-body"><div class="jf-inline-action"><p>For contacts with access to all properties, e.g., spouse or family for residential, or property or regional managers for commercial.</p><button type="button" class="jf-btn jf-outline" id="addContactButton">Add Contact</button></div><div class="jf-stack" id="contactList" style="margin-top:12px"></div></div>
        </div>
      </div>
    </section>

    <section class="jf-section">
      <div class="jf-label-col"><h2>Property address</h2><p>Enter the primary service address, billing address, or any additional locations where services may take place.</p><button type="button" class="jf-btn jf-outline" id="addAddressButton">Add Another Address</button></div>
      <div class="jf-content">
        <div class="jf-fieldset">
          <div class="jf-row"><div class="jf-cell"><input class="jf-input" name="address_line1" maxlength="190" placeholder="Street 1" value="<?= cfH($form['address_line1']); ?>"></div></div>
          <div class="jf-row"><div class="jf-cell"><input class="jf-input" name="address_line2" maxlength="190" placeholder="Street 2" value="<?= cfH($form['address_line2']); ?>"></div></div>
          <div class="jf-row cols2"><div class="jf-cell"><input class="jf-input" name="city" maxlength="120" placeholder="City" value="<?= cfH($form['city']); ?>"></div><div class="jf-cell"><input class="jf-input" name="state" maxlength="120" placeholder="Province" value="<?= cfH($form['state']); ?>"></div></div>
          <div class="jf-row cols2"><div class="jf-cell"><input class="jf-input" name="postal_code" maxlength="40" placeholder="Postal code" value="<?= cfH($form['postal_code']); ?>"></div><div class="jf-cell float"><label>Country</label><?php if($countries): ?><select class="jf-select" name="country_id" id="countrySelect"><option value="">Select a country</option><?php foreach($countries as $c): ?><option value="<?= (int)$c['id']; ?>" data-name="<?= cfH($c['name']); ?>" <?= (string)$form['country_id']===(string)$c['id']?'selected':''; ?>><?= cfH($c['name']); ?></option><?php endforeach; ?></select><input type="hidden" name="country" id="countryName" value="<?= cfH($form['country']); ?>"><?php else: ?><input class="jf-input" name="country" placeholder="Country" value="<?= cfH($form['country']); ?>"><?php endif; ?></div></div>
        </div>
        <input type="hidden" name="property_name" value="Primary property">
        <div class="jf-stack" id="addressList" style="margin-top:12px"></div>
      </div>
    </section>

    <div class="jf-bottom-bar">
      <a class="jf-btn" href="clients.php">Cancel</a>
      <div class="jf-bottom-right"><button class="jf-btn" type="submit" name="save_mode" value="another">Save and Create Another</button><button class="jf-btn primary" type="submit" name="save_mode" value="normal">Save Client</button></div>
    </div>
  </form>
</div>

<div class="jf-modal-bg" id="communicationModal" aria-hidden="true"><section class="jf-modal"><div class="jf-modal-head"><h3>Communication Settings</h3><button type="button" class="jf-close" data-close="communicationModal"><i class="bi bi-x-lg"></i></button></div><div class="jf-modal-body"><p class="jf-modal-copy">Automated communications send emails and SMS to the client for key updates. They can be toggled on or off per client.</p><div class="jf-settings-box"><div class="jf-setting-title">Quotes &amp; Invoices <span class="jf-link">Configure</span></div><div class="jf-setting-row"><span>Outstanding quote follow-ups</span><label class="jf-switch"><input type="checkbox" name="quote_followups" form="clientForm" value="1" <?= !empty($form['quote_followups'])?'checked':''; ?>><span></span></label></div><div class="jf-setting-row"><span>Overdue invoice follow-ups</span><label class="jf-switch"><input type="checkbox" name="invoice_followups" form="clientForm" value="1" <?= !empty($form['invoice_followups'])?'checked':''; ?>><span></span></label></div><div class="jf-setting-title">Jobs &amp; Visits <span class="jf-link">Configure</span></div><div class="jf-setting-row"><span>Upcoming assessment or visit reminders</span><label class="jf-switch"><input type="checkbox" name="visit_reminders" form="clientForm" value="1" <?= !empty($form['visit_reminders'])?'checked':''; ?>><span></span></label></div><div class="jf-setting-row"><span>Job closure follow-ups</span><label class="jf-switch"><input type="checkbox" name="job_close_followups" form="clientForm" value="1" <?= !empty($form['job_close_followups'])?'checked':''; ?>><span></span></label></div></div><input type="hidden" name="allow_email" form="clientForm" value="1"><input type="hidden" name="allow_sms" form="clientForm" value="1"></div><div class="jf-modal-footer"><button type="button" class="jf-btn" data-close="communicationModal">Cancel</button><button type="button" class="jf-btn primary" data-close="communicationModal">Save</button></div></section></div>

<div class="jf-modal-bg" id="customFieldModal" aria-hidden="true"><section class="jf-modal"><div class="jf-modal-head"><h3>New custom field</h3><button type="button" class="jf-close" data-close="customFieldModal"><i class="bi bi-x-lg"></i></button></div><div class="jf-modal-body"><div class="jf-block-title" style="color:var(--jf-muted);font-size:10px">APPLIES TO</div><div style="font-size:20px;font-weight:800;margin-bottom:10px">All properties</div><label class="jf-check"><input type="checkbox" id="customTransferable"> Transferable field</label><div class="jf-modal-field"><input class="jf-modal-input" id="customFieldName" placeholder="Custom field name"></div><div class="jf-modal-field"><select class="jf-modal-select" id="customFieldType"><option value="text">Text</option><option value="numeric">Numeric</option><option value="boolean">True/False</option><option value="area">Area (length × width)</option><option value="dropdown">Dropdown</option></select></div><div class="jf-modal-field"><input class="jf-modal-input" id="customFieldDefault" placeholder="Default value"></div><div style="font-size:11px;color:var(--jf-muted)">All custom fields can be edited and reordered in <span class="jf-link">Settings &gt; Custom Fields</span></div></div><div class="jf-modal-footer"><button type="button" class="jf-btn" data-close="customFieldModal">Cancel</button><button type="button" class="jf-btn primary" id="saveCustomField">Add Custom Field</button></div></section></div>

<div class="jf-modal-bg" id="contactModal" aria-hidden="true"><section class="jf-modal"><div class="jf-modal-head"><h3>Add contact</h3><button type="button" class="jf-close" data-close="contactModal"><i class="bi bi-x-lg"></i></button></div><div class="jf-modal-body"><div class="jf-block-title">Details</div><div class="jf-modal-grid"><select class="jf-modal-select" id="contactTitle"><option value="">No title</option><option>Mr.</option><option>Mrs.</option><option>Ms.</option><option>Dr.</option></select><input class="jf-modal-input" id="contactFirst" placeholder="First name"><input class="jf-modal-input" id="contactLast" placeholder="Last name"></div><div class="jf-modal-field"><input class="jf-modal-input" id="contactRole" placeholder="Role"></div><label class="jf-check"><input type="checkbox" id="contactBilling"> Set as billing contact</label><div class="jf-block-title" style="margin-top:18px">Communication</div><div class="jf-modal-field"><input class="jf-modal-input" id="contactPhone" placeholder="Phone number"></div><div class="jf-modal-field"><input class="jf-modal-input" type="email" id="contactEmail" placeholder="Email"></div><div class="jf-block-title" style="margin-top:18px">Communication settings</div><div class="jf-info"><i class="bi bi-info-circle"></i> Contacts can access the client hub</div><div class="jf-settings-box"><div class="jf-setting-title">Quotes &amp; Invoices <span class="jf-link">Configure</span></div><div class="jf-setting-row"><span>Outstanding quote follow-ups</span><label class="jf-switch"><input type="checkbox" id="contactQuote"><span></span></label></div><div class="jf-setting-row"><span>Overdue invoice follow-ups</span><label class="jf-switch"><input type="checkbox" id="contactInvoice"><span></span></label></div><div class="jf-setting-title">Jobs &amp; Visits <span class="jf-link">Configure</span></div><div class="jf-setting-row"><span>Upcoming assessment or visit reminders</span><label class="jf-switch"><input type="checkbox" id="contactVisit" checked><span></span></label></div><div class="jf-setting-row"><span>Job closure follow-ups</span><label class="jf-switch"><input type="checkbox" id="contactJob"><span></span></label></div></div></div><div class="jf-modal-footer"><button type="button" class="jf-btn" data-close="contactModal">Cancel</button><button type="button" class="jf-btn primary" id="saveContactButton">Add Contact</button></div></section></div>

<div class="jf-modal-bg" id="addressModal" aria-hidden="true"><section class="jf-modal"><div class="jf-modal-head"><h3>Add property address</h3><button type="button" class="jf-close" data-close="addressModal"><i class="bi bi-x-lg"></i></button></div><div class="jf-modal-body"><div class="jf-modal-field"><input class="jf-modal-input" id="addrName" placeholder="Property name"></div><div class="jf-modal-field"><input class="jf-modal-input" id="addr1" placeholder="Street 1"></div><div class="jf-modal-field"><input class="jf-modal-input" id="addr2" placeholder="Street 2"></div><div class="jf-modal-field"><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><input class="jf-modal-input" id="addrCity" placeholder="City"><input class="jf-modal-input" id="addrState" placeholder="Province"></div></div><div class="jf-modal-field"><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px"><input class="jf-modal-input" id="addrPostal" placeholder="Postal code"><input class="jf-modal-input" id="addrCountry" placeholder="Country" value="India"></div></div></div><div class="jf-modal-footer"><button type="button" class="jf-btn" data-close="addressModal">Cancel</button><button type="button" class="jf-btn primary" id="saveAddressButton">Add Address</button></div></section></div>

<script>
(function(){'use strict';
var form=document.getElementById('clientForm'),contacts=[],addresses=[],customFields=[];
function E(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]})}
function modal(id,on){var el=E(id);if(!el)return;el.classList.toggle('show',!!on);el.setAttribute('aria-hidden',on?'false':'true');document.body.style.overflow=on?'hidden':''}
document.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',function(){modal(b.getAttribute('data-close'),false)})});document.querySelectorAll('.jf-modal-bg').forEach(function(bg){bg.addEventListener('click',function(e){if(e.target===bg)modal(bg.id,false)})});
function accordion(id){var box=E(id);box.classList.toggle('open');var icon=box.querySelector('.jf-accordion-btn i');if(icon)icon.className='bi '+(box.classList.contains('open')?'bi-chevron-up':'bi-chevron-down')}
E('additionalDetailsToggle').addEventListener('click',function(){accordion('additionalDetailsAccordion')});E('additionalContactsToggle').addEventListener('click',function(){accordion('additionalContactsAccordion')});
E('communicationSettingsLink').addEventListener('click',function(){modal('communicationModal',true)});E('addCustomFieldButton').addEventListener('click',function(){E('customFieldName').value='';E('customFieldDefault').value='';E('customFieldType').value='text';modal('customFieldModal',true)});E('addContactButton').addEventListener('click',function(){['contactFirst','contactLast','contactRole','contactPhone','contactEmail'].forEach(function(id){E(id).value=''});E('contactBilling').checked=false;modal('contactModal',true)});E('addAddressButton').addEventListener('click',function(){['addrName','addr1','addr2','addrCity','addrState','addrPostal'].forEach(function(id){E(id).value=''});E('addrCountry').value='India';modal('addressModal',true)});
function syncHidden(){E('additionalContactsJson').value=JSON.stringify(contacts);E('additionalAddressesJson').value=JSON.stringify(addresses);E('customFieldsJson').value=JSON.stringify(customFields)}
function renderContacts(){E('contactList').innerHTML=contacts.map(function(c,i){return '<div class="jf-subcard"><div><strong>'+esc((c.first_name+' '+c.last_name).trim()||'Contact')+'</strong><small>'+esc(c.role||c.email||c.phone||'Additional contact')+'</small></div><button type="button" data-remove-contact="'+i+'"><i class="bi bi-trash"></i></button></div>'}).join('');syncHidden()}
function renderAddresses(){E('addressList').innerHTML=addresses.map(function(a,i){return '<div class="jf-subcard"><div><strong>'+esc(a.name||('Property '+(i+2)))+'</strong><small>'+esc([a.address_line1,a.city,a.state].filter(Boolean).join(', '))+'</small></div><button type="button" data-remove-address="'+i+'"><i class="bi bi-trash"></i></button></div>'}).join('');syncHidden()}
function renderCustomFields(){E('customFieldList').innerHTML=customFields.map(function(f,i){return '<div class="jf-subcard"><div><strong>'+esc(f.name)+'</strong><small>'+esc(f.type)+(f.default?' · '+esc(f.default):'')+'</small></div><button type="button" data-remove-custom="'+i+'"><i class="bi bi-trash"></i></button></div>'}).join('');syncHidden()}
E('saveContactButton').addEventListener('click',function(){var first=E('contactFirst').value.trim(),email=E('contactEmail').value.trim();if(!first){E('contactFirst').focus();return}if(email&&!E('contactEmail').checkValidity()){E('contactEmail').focus();return}contacts.push({title:E('contactTitle').value,first_name:first,last_name:E('contactLast').value.trim(),role:E('contactRole').value.trim(),phone:E('contactPhone').value.trim(),email:email,billing:E('contactBilling').checked?1:0,quote_followups:E('contactQuote').checked?1:0,invoice_followups:E('contactInvoice').checked?1:0,visit_reminders:E('contactVisit').checked?1:0,job_close_followups:E('contactJob').checked?1:0});renderContacts();accordion('additionalContactsAccordion');modal('contactModal',false)});
E('saveAddressButton').addEventListener('click',function(){var a={name:E('addrName').value.trim(),address_line1:E('addr1').value.trim(),address_line2:E('addr2').value.trim(),city:E('addrCity').value.trim(),state:E('addrState').value.trim(),postal_code:E('addrPostal').value.trim(),country:E('addrCountry').value.trim()};if(!a.address_line1&&!a.city){E('addr1').focus();return}addresses.push(a);renderAddresses();modal('addressModal',false)});
E('saveCustomField').addEventListener('click',function(){var name=E('customFieldName').value.trim();if(!name){E('customFieldName').focus();return}customFields.push({name:name,type:E('customFieldType').value,default:E('customFieldDefault').value.trim(),transferable:E('customTransferable').checked?1:0});renderCustomFields();modal('customFieldModal',false)});
document.addEventListener('click',function(e){var c=e.target.closest('[data-remove-contact]');if(c){contacts.splice(Number(c.dataset.removeContact),1);renderContacts()}var a=e.target.closest('[data-remove-address]');if(a){addresses.splice(Number(a.dataset.removeAddress),1);renderAddresses()}var f=e.target.closest('[data-remove-custom]');if(f){customFields.splice(Number(f.dataset.removeCustom),1);renderCustomFields()}});
function deriveName(){var n=(E('firstName').value+' '+E('lastName').value).trim().replace(/\s+/g,' ');if(!n)n=E('companyName').value.trim();E('displayName').value=n}
['firstName','lastName','companyName'].forEach(function(id){E(id).addEventListener('input',deriveName)});deriveName();
var country=E('countrySelect');if(country){function syncCountry(){var o=country.options[country.selectedIndex];E('countryName').value=o&&o.dataset.name?o.dataset.name:''}country.addEventListener('change',syncCountry);if(!E('countryName').value)syncCountry()}
form.addEventListener('submit',function(e){deriveName();syncHidden();if(!E('displayName').value.trim()){e.preventDefault();E('firstName').focus();return}var submitter=e.submitter;if(submitter){var hidden=form.querySelector('input[name="save_mode"][type="hidden"]');if(hidden)hidden.remove();var h=document.createElement('input');h.type='hidden';h.name='save_mode';h.value=submitter.value||'normal';form.appendChild(h)}document.querySelectorAll('.jf-bottom-bar button').forEach(function(b){b.disabled=true})});
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
