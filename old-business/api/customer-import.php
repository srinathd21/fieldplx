<?php
/* FieldPlx Customer Import API - Version 1.1.0 - 2026-09-02 */
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) require_once __DIR__ . '/../includes/audit.php';

function ciOut($status,$success,$message,$extra=array()){
    while(ob_get_level()>0) @ob_end_clean();
    http_response_code((int)$status);
    echo json_encode(array_merge(array('success'=>(bool)$success,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function ciPost($key,$default=''){ return isset($_POST[$key]) ? $_POST[$key] : $default; }
function ciTableExists(PDO $pdo,$table){ $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:n");$q->execute(array(':n'=>$table));return (int)$q->fetchColumn()>0; }
function ciClean($v){ return trim((string)$v); }
function ciNullable($v){ $v=trim((string)$v); return $v===''?null:$v; }
function ciYes($v){ $v=strtolower(trim((string)$v)); return in_array($v,array('1','yes','y','true','on'),true)?1:0; }
function ciInputSummary($row){ return array('display_name'=>ciClean(isset($row['display_name'])?$row['display_name']:''),'email'=>ciClean(isset($row['email'])?$row['email']:''),'phone'=>ciClean(isset($row['phone'])?$row['phone']:''),'branch'=>ciClean(isset($row['branch'])?$row['branch']:'')); }
function ciBranchId(PDO $pdo,$tenantId,$value){
    $value=trim((string)$value); if($value==='') return null;
    $q=$pdo->prepare("SELECT id FROM branches WHERE tenant_id=:t AND status='active' AND (LOWER(branch_code)=LOWER(:v1) OR LOWER(name)=LOWER(:v2)) ORDER BY is_head_office DESC,id LIMIT 1");
    $q->execute(array(':t'=>$tenantId,':v1'=>$value,':v2'=>$value));
    $id=$q->fetchColumn(); return $id!==false?(int)$id:null;
}
function ciCountryId(PDO $pdo,$value){
    $value=trim((string)$value); if($value==='' || !ciTableExists($pdo,'countries')) return null;
    $q=$pdo->prepare("SELECT id FROM countries WHERE is_active=1 AND (LOWER(iso2)=LOWER(:v1) OR LOWER(name)=LOWER(:v2)) ORDER BY id LIMIT 1");
    $q->execute(array(':v1'=>$value,':v2'=>$value));
    $id=$q->fetchColumn(); return $id!==false?(int)$id:null;
}
function ciDuplicate(PDO $pdo,$tenantId,$email,$phone){
    $base="SELECT c.id,c.display_name,c.company_name,c.email,c.phone,c.client_type,c.status,c.branch_id,b.name AS branch_name FROM clients c LEFT JOIN branches b ON b.id=c.branch_id AND b.tenant_id=c.tenant_id WHERE c.tenant_id=:t AND c.deleted_at IS NULL AND ";
    if($email!==''){
        $q=$pdo->prepare($base."LOWER(c.email)=LOWER(:v) LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':v'=>$email));$r=$q->fetch(PDO::FETCH_ASSOC);if($r)return array('field'=>'email','value'=>$email,'client'=>$r);
    }
    if($phone!==''){
        $q=$pdo->prepare($base."c.phone=:v LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':v'=>$phone));$r=$q->fetch(PDO::FETCH_ASSOC);if($r)return array('field'=>'phone','value'=>$phone,'client'=>$r);
    }
    return null;
}
function ciActivity(PDO $pdo,$tenantId,$branchId,$userId,$clientId,$name){
    if(!ciTableExists($pdo,'activity_events')) return;
    try{
        $q=$pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user','client_imported','client',:id,:id2,:title,:details,0)");
        $q->execute(array(':t'=>$tenantId,':b'=>$branchId,':u'=>$userId,':id'=>$clientId,':id2'=>$clientId,':title'=>'Customer imported: '.substr($name,0,190),':details'=>json_encode(array('source'=>'customer-import.php'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    }catch(Throwable $e){ error_log('customer import activity: '.$e->getMessage()); }
}

$tenantId=isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;
$userId=isset($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:0;
$sessionBranch=isset($_SESSION['branch_id'])?(int)$_SESSION['branch_id']:0;
if($tenantId<=0 || $userId<=0) ciOut(401,false,'Authentication required.');
$csrf=(string)ciPost('csrf_token','');
$sessionCsrf=isset($_SESSION['customer_import_csrf_token'])?(string)$_SESSION['customer_import_csrf_token']:'';
if($csrf==='' || $sessionCsrf==='' || !hash_equals($sessionCsrf,$csrf)) ciOut(419,false,'Your form session expired. Refresh the page and try again.');
$action=trim((string)ciPost('action',''));

try{
    if($action==='meta'){
        $b=$pdo->prepare("SELECT id,branch_code,name FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name");
        $b->execute(array(':t'=>$tenantId));
        $countries=array();
        if(ciTableExists($pdo,'countries')){$q=$pdo->query("SELECT id,name,iso2 FROM countries WHERE is_active=1 ORDER BY name");$countries=$q->fetchAll(PDO::FETCH_ASSOC);}
        ciOut(200,true,'Import metadata loaded.',array('meta'=>array('branches'=>$b->fetchAll(PDO::FETCH_ASSOC),'countries'=>$countries)));
    }

    if($action==='import'){
        $decoded=json_decode((string)ciPost('rows_json','[]'),true);
        if(!is_array($decoded)) ciOut(422,false,'Invalid bulk customer data.');
        if(count($decoded)===0) ciOut(422,false,'No customer rows were provided.');
        if(count($decoded)>1000) ciOut(422,false,'Import a maximum of 1000 rows at one time.');

        $validTypes=array('lead','client');
        $validPreferred=array('email','sms','phone','whatsapp','none');
        $validStatuses=array('new','active','inactive');
        $validLocationTypes=array('home','office','warehouse','factory','farm','shop','site','other');
        $results=array();$imported=0;$skipped=0;$existing=0;$failed=0;$batchKeys=array();$importedIds=array();

        foreach($decoded as $index=>$row){
            $rowNo=$index+1;
            if(!is_array($row)){ $failed++;$results[]=array('row'=>$rowNo,'status'=>'Failed','code'=>'INVALID_ROW','message'=>'Invalid row format. Expected customer column values.','input'=>array());continue; }
            $display=ciClean(isset($row['display_name'])?$row['display_name']:'');
            if($display===''){ $failed++;$results[]=array('row'=>$rowNo,'status'=>'Failed','code'=>'DISPLAY_NAME_REQUIRED','field'=>'display_name','message'=>'Display Name is required. Enter a customer name in this row.','input'=>ciInputSummary($row));continue; }
            $type=strtolower(ciClean(isset($row['client_type'])?$row['client_type']:'lead')); if(!in_array($type,$validTypes,true))$type='lead';
            $preferred=strtolower(ciClean(isset($row['preferred_contact_method'])?$row['preferred_contact_method']:'email')); if(!in_array($preferred,$validPreferred,true))$preferred='email';
            $status=strtolower(ciClean(isset($row['status'])?$row['status']:'new')); if(!in_array($status,$validStatuses,true))$status='new';
            $email=strtolower(ciClean(isset($row['email'])?$row['email']:''));
            $phone=ciClean(isset($row['phone'])?$row['phone']:'');
            if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)){ $failed++;$results[]=array('row'=>$rowNo,'status'=>'Failed','code'=>'INVALID_EMAIL','field'=>'email','message'=>'Invalid email address: '.$email.'. Enter a valid email or leave it blank.','input'=>ciInputSummary($row));continue; }
            $batchDup=null;
            if($email!=='' && isset($batchKeys['e:'.$email])) $batchDup=$batchKeys['e:'.$email];
            if($batchDup===null && $phone!=='' && isset($batchKeys['p:'.$phone])) $batchDup=$batchKeys['p:'.$phone];
            if($batchDup!==null){ $skipped++;$results[]=array('row'=>$rowNo,'status'=>'Skipped','code'=>'DUPLICATE_IN_FILE','message'=>'Duplicate in this import. Same email/phone was already entered on row '.$batchDup['row'].' ('.$batchDup['name'].').','input'=>ciInputSummary($row),'duplicate_row'=>$batchDup['row']);continue; }
            $dup=ciDuplicate($pdo,$tenantId,$email,$phone);
            if($dup){ $skipped++;$existing++;$results[]=array('row'=>$rowNo,'status'=>'Existing','code'=>'CUSTOMER_EXISTS','field'=>$dup['field'],'message'=>'Customer already exists. The '.$dup['field'].' "'.$dup['value'].'" is already assigned to Customer #'.$dup['client']['id'].' '.$dup['client']['display_name'].'.','input'=>ciInputSummary($row),'existing'=>$dup['client']);continue; }

            $branchText=ciClean(isset($row['branch'])?$row['branch']:'');
            $branchId=ciBranchId($pdo,$tenantId,$branchText);
            if($branchText!=='' && $branchId===null){ $failed++;$results[]=array('row'=>$rowNo,'status'=>'Failed','code'=>'BRANCH_NOT_FOUND','field'=>'branch','message'=>'Branch "'.$branchText.'" was not found. Use an active branch code or exact branch name.','input'=>ciInputSummary($row));continue; }
            if($branchId===null && $sessionBranch>0) $branchId=$sessionBranch;

            $locationName=ciClean(isset($row['location_name'])?$row['location_name']:'');
            $address1=ciClean(isset($row['address_line1'])?$row['address_line1']:'');
            $hasLocation=($locationName!=='' || $address1!=='');
            if($hasLocation && ($locationName==='' || $address1==='')){ $failed++;$results[]=array('row'=>$rowNo,'status'=>'Failed','code'=>'LOCATION_REQUIRED_FIELDS','field'=>$locationName===''?'location_name':'address_line1','message'=>'Location is incomplete. Location Name and Address Line 1 are both required when any location data is entered.','input'=>ciInputSummary($row));continue; }
            $locType=strtolower(ciClean(isset($row['location_type'])?$row['location_type']:'other'));if(!in_array($locType,$validLocationTypes,true))$locType='other';
            $countryText=ciClean(isset($row['country'])?$row['country']:'');$countryId=ciCountryId($pdo,$countryText);
            if($hasLocation && $countryText!=='' && $countryId===null){$failed++;$results[]=array('row'=>$rowNo,'status'=>'Failed','code'=>'COUNTRY_NOT_FOUND','field'=>'country','message'=>'Country "'.$countryText.'" was not found. Use an active country name or ISO2 code such as US or IN.','input'=>ciInputSummary($row));continue;}

            $pdo->beginTransaction();
            try{
                $q=$pdo->prepare("INSERT INTO clients(tenant_id,branch_id,client_type,display_name,company_name,first_name,last_name,email,phone,alternate_phone,source,preferred_contact_method,allow_email,allow_sms,status,tax_number,notes,account_manager_id,created_by) VALUES(:t,:b,:type,:display,:company,:first,:last,:email,:phone,:alt,:source,:pref,1,1,:status,:tax,:notes,NULL,:created)");
                $q->execute(array(
                    ':t'=>$tenantId,':b'=>$branchId,':type'=>$type,':display'=>$display,
                    ':company'=>ciNullable(isset($row['company_name'])?$row['company_name']:''),':first'=>ciNullable(isset($row['first_name'])?$row['first_name']:''),':last'=>ciNullable(isset($row['last_name'])?$row['last_name']:''),
                    ':email'=>$email!==''?$email:null,':phone'=>$phone!==''?$phone:null,':alt'=>ciNullable(isset($row['alternate_phone'])?$row['alternate_phone']:''),':source'=>ciNullable(isset($row['source'])?$row['source']:''),
                    ':pref'=>$preferred,':status'=>$status,':tax'=>ciNullable(isset($row['tax_number'])?$row['tax_number']:''),':notes'=>ciNullable(isset($row['notes'])?$row['notes']:''),':created'=>$userId
                ));
                $clientId=(int)$pdo->lastInsertId();
                if($hasLocation){
                    $q=$pdo->prepare("INSERT INTO client_locations(tenant_id,client_id,location_type,name,address_line1,address_line2,city,state,postal_code,country_id,contact_name,contact_phone,is_primary,status) VALUES(:t,:c,:type,:name,:a1,:a2,:city,:state,:postal,:country,:contact,:phone,:primary,'active')");
                    $q->execute(array(':t'=>$tenantId,':c'=>$clientId,':type'=>$locType,':name'=>$locationName,':a1'=>$address1,':a2'=>ciNullable(isset($row['address_line2'])?$row['address_line2']:''),':city'=>ciNullable(isset($row['city'])?$row['city']:''),':state'=>ciNullable(isset($row['state'])?$row['state']:''),':postal'=>ciNullable(isset($row['postal_code'])?$row['postal_code']:''),':country'=>$countryId,':contact'=>ciNullable(isset($row['location_contact_name'])?$row['location_contact_name']:''),':phone'=>ciNullable(isset($row['location_contact_phone'])?$row['location_contact_phone']:''),':primary'=>ciYes(isset($row['is_primary'])?$row['is_primary']:'Yes')));
                }
                if(ciTableExists($pdo,'client_portal_users')){
                    $q=$pdo->prepare("INSERT INTO client_portal_users(tenant_id,client_id,contact_id,email,phone,password_hash,status) VALUES(:t,:c,NULL,:email,:phone,NULL,'inactive')");
                    $q->execute(array(':t'=>$tenantId,':c'=>$clientId,':email'=>$email!==''?$email:null,':phone'=>$phone!==''?$phone:null));
                }
                $pdo->commit();
                ciActivity($pdo,$tenantId,$branchId,$userId,$clientId,$display);
                $imported++;$importedIds[]=$clientId;if($email!=='')$batchKeys['e:'.$email]=array('row'=>$rowNo,'name'=>$display);if($phone!=='')$batchKeys['p:'.$phone]=array('row'=>$rowNo,'name'=>$display);
                $results[]=array('row'=>$rowNo,'status'=>'Imported','code'=>'IMPORTED','client_id'=>$clientId,'message'=>'Customer #'.$clientId.' '.$display.' imported successfully.'.($hasLocation?' Primary location created.':''),'input'=>ciInputSummary($row));
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                $failed++;error_log('Customer import row '.$rowNo.': '.$e->getMessage());
                $safeMessage='Unable to import this row due to a database validation error.';if($e instanceof PDOException && (string)$e->getCode()==='23000')$safeMessage='Database rejected this row because a duplicate or related record already exists.';$results[]=array('row'=>$rowNo,'status'=>'Failed','code'=>'DATABASE_ERROR','message'=>$safeMessage,'input'=>ciInputSummary($row));
            }
        }

        if(function_exists('tenantAuditLog')){
            try{tenantAuditLog($pdo,'CUSTOMERS_BULK_IMPORTED',$tenantId,$sessionBranch,$userId,'client',null,null,array('imported'=>$imported,'skipped'=>$skipped,'failed'=>$failed,'client_ids'=>$importedIds));}catch(Throwable $e){error_log('Customer import audit: '.$e->getMessage());}
        }
        ciOut(200,true,$imported.' customer(s) imported. '.$skipped.' skipped, '.$failed.' failed.',array('imported'=>$imported,'skipped'=>$skipped,'existing'=>$existing,'failed'=>$failed,'results'=>$results));
    }

    ciOut(400,false,'Unsupported customer import action.');
}catch(PDOException $e){
    error_log('FieldPlx customer import PDO error: '.$e->getMessage());
    ciOut(500,false,'Unable to process customer import.');
}catch(Throwable $e){
    error_log('FieldPlx customer import error: '.$e->getMessage());
    ciOut(500,false,'Unable to process customer import.');
}
