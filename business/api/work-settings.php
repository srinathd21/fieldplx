<?php
/**
 * FieldPlx - Work Settings API
 * File: business/api/work-settings.php
 * PHP 7.2+ / MariaDB 11.x
 */
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) require_once __DIR__ . '/../includes/audit.php';

function wsOut($code,$ok,$message,$extra=array()){
    while(ob_get_level()>0){@ob_end_clean();}
    http_response_code((int)$code);
    echo json_encode(array_merge(array('success'=>(bool)$ok,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function wsPost($key,$default=''){
    if(!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}
function wsTable(PDO $pdo,$table){
    static $cache=array();
    if(isset($cache[$table])) return $cache[$table];
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t'=>$table));
    return $cache[$table]=((int)$q->fetchColumn()>0);
}
function wsCol(PDO $pdo,$table,$column){
    static $cache=array();
    $k=$table.'.'.$column;
    if(isset($cache[$k])) return $cache[$k];
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t'=>$table,':c'=>$column));
    return $cache[$k]=((int)$q->fetchColumn()>0);
}
function wsCsrf(){
    $saved=isset($_SESSION['work_settings_csrf'])?(string)$_SESSION['work_settings_csrf']:'';
    $posted=wsPost('csrf_token');
    if($saved==='' || $posted==='' || !hash_equals($saved,$posted)) wsOut(419,false,'Your form session expired. Refresh the page and try again.');
}
function wsActor(PDO $pdo,$tenant,$user){
    $sql="SELECT id,tenant_id,role_id,is_tenant_admin,status FROM users WHERE id=:id AND tenant_id=:t";
    if(wsCol($pdo,'users','deleted_at')) $sql.=" AND deleted_at IS NULL";
    $sql.=" LIMIT 1";
    $q=$pdo->prepare($sql);
    $q->execute(array(':id'=>$user,':t'=>$tenant));
    $r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r || (isset($r['status']) && !in_array((string)$r['status'],array('active','invited'),true))) wsOut(401,false,'Your login session is no longer active.');
    return $r;
}
function wsPermission(PDO $pdo,$tenant,$actor,$code){
    if((int)$actor['is_tenant_admin']===1) return true;
    if(!wsTable($pdo,'permissions')) return true;
    $q=$pdo->prepare("SELECT id FROM permissions WHERE permission_code=:c LIMIT 1");$q->execute(array(':c'=>$code));$pid=(int)$q->fetchColumn();
    if($pid<=0) return true;
    if(wsTable($pdo,'user_permissions')){
        $q=$pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenant,':u'=>(int)$actor['id'],':p'=>$pid));$v=$q->fetchColumn();
        if($v!==false) return (string)$v==='allow';
    }
    if((int)$actor['role_id']>0 && wsTable($pdo,'role_permissions')){
        $q=$pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $q->execute(array(':t'=>$tenant,':r'=>(int)$actor['role_id'],':p'=>$pid));$v=$q->fetchColumn();
        if($v!==false) return (string)$v==='allow';
    }
    return false;
}
function wsRequire(PDO $pdo,$tenant,$actor,$code){if(!wsPermission($pdo,$tenant,$actor,$code))wsOut(403,false,'You do not have permission to update work settings.');}
function wsAudit(PDO $pdo,$tenant,$branch,$user,$action,$id,$values){
    if(function_exists('tenantAuditLog')){try{tenantAuditLog($pdo,$action,$tenant,$branch>0?$branch:null,$user,'work_settings',$id,null,$values);}catch(Throwable $e){error_log('Work settings audit: '.$e->getMessage());}}
}
function wsRequireSchema(PDO $pdo){
    foreach(array('work_payment_terms','tenant_work_settings','work_setting_invoice_reminder_users') as $t){
        if(!wsTable($pdo,$t)) wsOut(503,false,'Work Settings database setup is incomplete. Missing table: '.$t.'.');
    }
}
function wsSeedTerms(PDO $pdo,$tenant,$actor){
    $q=$pdo->prepare("SELECT COUNT(*) FROM work_payment_terms WHERE tenant_id=:t".(wsCol($pdo,'work_payment_terms','deleted_at')?" AND deleted_at IS NULL":""));
    $q->execute(array(':t'=>$tenant)); if((int)$q->fetchColumn()>0) return;
    $rows=array(array('Due upon receipt','days',0,1,10),array('Net 7','days',7,0,20),array('Net 15','days',15,0,30),array('Net 30','days',30,0,40),array('Net 45','days',45,0,50),array('Net 60','days',60,0,60),array('End of the month','end_month',null,1,70),array('End of next month','end_next_month',null,1,80));
    foreach($rows as $r){
        $fields=array('tenant_id','term_name','term_type','number_days','is_system','sort_order');
        $marks=array(':t',':n',':type',':days',':sys',':sort');
        $params=array(':t'=>$tenant,':n'=>$r[0],':type'=>$r[1],':days'=>$r[2],':sys'=>$r[3],':sort'=>$r[4]);
        if(wsCol($pdo,'work_payment_terms','status')){$fields[]='status';$marks[]="'active'";}
        if(wsCol($pdo,'work_payment_terms','created_by')){$fields[]='created_by';$marks[]=':u';$params[':u']=$actor>0?$actor:null;}
        if(wsCol($pdo,'work_payment_terms','created_at')){$fields[]='created_at';$marks[]='NOW()';}
        $sql='INSERT INTO work_payment_terms(`'.implode('`,`',$fields).'`) VALUES('.implode(',',$marks).')';
        $pdo->prepare($sql)->execute($params);
    }
}
function wsEnsureSettings(PDO $pdo,$tenant,$actor){
    $q=$pdo->prepare("SELECT id FROM tenant_work_settings WHERE tenant_id=:t LIMIT 1");$q->execute(array(':t'=>$tenant));$id=(int)$q->fetchColumn(); if($id>0)return $id;
    $where="tenant_id=:t".(wsCol($pdo,'work_payment_terms','deleted_at')?" AND deleted_at IS NULL":"");
    $q=$pdo->prepare("SELECT id FROM work_payment_terms WHERE $where AND term_name='Due upon receipt' LIMIT 1");$q->execute(array(':t'=>$tenant));$res=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT id FROM work_payment_terms WHERE $where AND term_name='Net 30' LIMIT 1");$q->execute(array(':t'=>$tenant));$com=(int)$q->fetchColumn();
    $data=array(
        'tenant_id'=>$tenant,'quote_reminder_enabled'=>1,'quote_reminder_days'=>3,'arrival_window_minutes'=>0,'arrival_window_style'=>'after_start',
        'visit_title_template'=>'{{CLIENT_NAME}} - {{JOB_TITLE}}','invoice_subject'=>'For Services Rendered','invoice_use_work_title'=>1,
        'default_residential_term_id'=>$res>0?$res:null,'default_commercial_term_id'=>$com>0?$com:null,'reassign_incomplete_invoice_reminders'=>0,
        'statement_sort_order'=>'newest','statement_disclaimer'=>null,'chemical_tracking_enabled'=>0
    );
    $fields=array();$marks=array();$params=array();
    foreach($data as $k=>$v){if(wsCol($pdo,'tenant_work_settings',$k)){$fields[]='`'.$k.'`';$marks[]=':'.$k;$params[':'.$k]=$v;}}
    if(wsCol($pdo,'tenant_work_settings','created_by')){$fields[]='`created_by`';$marks[]=':created_by';$params[':created_by']=$actor>0?$actor:null;}
    if(wsCol($pdo,'tenant_work_settings','updated_by')){$fields[]='`updated_by`';$marks[]=':updated_by';$params[':updated_by']=$actor>0?$actor:null;}
    if(wsCol($pdo,'tenant_work_settings','created_at')){$fields[]='`created_at`';$marks[]='NOW()';}
    if(wsCol($pdo,'tenant_work_settings','updated_at')){$fields[]='`updated_at`';$marks[]='NOW()';}
    $pdo->prepare('INSERT INTO tenant_work_settings('.implode(',',$fields).') VALUES('.implode(',',$marks).')')->execute($params);
    return (int)$pdo->lastInsertId();
}
function wsSettings(PDO $pdo,$tenant){
    $q=$pdo->prepare("SELECT * FROM tenant_work_settings WHERE tenant_id=:t LIMIT 1");$q->execute(array(':t'=>$tenant));$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return array();
    $row['invoice_reminder_user_ids']=array();
    if(wsTable($pdo,'work_setting_invoice_reminder_users')){$q=$pdo->prepare("SELECT user_id FROM work_setting_invoice_reminder_users WHERE tenant_id=:t AND work_setting_id=:w ORDER BY user_id");$q->execute(array(':t'=>$tenant,':w'=>(int)$row['id']));$row['invoice_reminder_user_ids']=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
    return $row;
}
function wsTerms(PDO $pdo,$tenant){
    $sql="SELECT id,term_name,term_type,number_days,is_system,sort_order".(wsCol($pdo,'work_payment_terms','status')?",status":"") . " FROM work_payment_terms WHERE tenant_id=:t";
    if(wsCol($pdo,'work_payment_terms','deleted_at'))$sql.=" AND deleted_at IS NULL";
    if(wsCol($pdo,'work_payment_terms','status'))$sql.=" AND status='active'";
    $sql.=" ORDER BY sort_order,id";$q=$pdo->prepare($sql);$q->execute(array(':t'=>$tenant));return $q->fetchAll(PDO::FETCH_ASSOC);
}
function wsUsers(PDO $pdo,$tenant){
    $sql="SELECT id,first_name,last_name,email FROM users WHERE tenant_id=:t";
    if(wsCol($pdo,'users','deleted_at'))$sql.=" AND deleted_at IS NULL";
    if(wsCol($pdo,'users','status'))$sql.=" AND status='active'";
    $sql.=" ORDER BY is_tenant_admin DESC,first_name,last_name,id";
    $q=$pdo->prepare($sql);$q->execute(array(':t'=>$tenant));return $q->fetchAll(PDO::FETCH_ASSOC);
}

$tenant=isset($currentTenantId)?(int)$currentTenantId:(isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0);
$user=isset($currentTenantUserId)?(int)$currentTenantUserId:(isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:(isset($_SESSION['id'])?(int)$_SESSION['id']:0));
$branch=isset($currentBranchId)?(int)$currentBranchId:(isset($_SESSION['branch_id'])?(int)$_SESSION['branch_id']:0);
if($tenant<=0 || $user<=0)wsOut(401,false,'Tenant session is not available.');
$actor=wsActor($pdo,$tenant,$user);
$action=wsPost('action','load');

try{
    wsRequireSchema($pdo);
    wsSeedTerms($pdo,$tenant,$user);
    $settingId=wsEnsureSettings($pdo,$tenant,$user);

    if($action==='load'){
        if(!wsPermission($pdo,$tenant,$actor,'settings.view') && !wsPermission($pdo,$tenant,$actor,'settings.update'))wsOut(403,false,'You do not have permission to view work settings.');
        wsOut(200,true,'Work settings loaded.',array('settings'=>wsSettings($pdo,$tenant),'terms'=>wsTerms($pdo,$tenant),'users'=>wsUsers($pdo,$tenant),'can_update'=>wsPermission($pdo,$tenant,$actor,'settings.update')));
    }

    if($action==='save_settings'){
        wsCsrf();wsRequire($pdo,$tenant,$actor,'settings.update');
        $data=array(
            'quote_reminder_enabled'=>wsPost('quote_reminder_enabled','0')==='1'?1:0,
            'quote_reminder_days'=>max(1,min(365,(int)wsPost('quote_reminder_days','3'))),
            'arrival_window_minutes'=>(int)wsPost('arrival_window_minutes','0'),
            'arrival_window_style'=>wsPost('arrival_window_style','after_start'),
            'visit_title_template'=>wsPost('visit_title_template','{{CLIENT_NAME}} - {{JOB_TITLE}}'),
            'invoice_subject'=>wsPost('invoice_subject','For Services Rendered'),
            'invoice_use_work_title'=>wsPost('invoice_use_work_title','0')==='1'?1:0,
            'default_residential_term_id'=>(int)wsPost('default_residential_term_id','0'),
            'default_commercial_term_id'=>(int)wsPost('default_commercial_term_id','0'),
            'reassign_incomplete_invoice_reminders'=>wsPost('reassign_incomplete_invoice_reminders','0')==='1'?1:0,
            'statement_sort_order'=>wsPost('statement_sort_order','newest'),
            'statement_disclaimer'=>wsPost('statement_disclaimer',''),
            'chemical_tracking_enabled'=>wsPost('chemical_tracking_enabled','0')==='1'?1:0
        );
        if(!in_array($data['arrival_window_minutes'],array(0,15,30,60,120,180,240),true))$data['arrival_window_minutes']=0;
        if(!in_array($data['arrival_window_style'],array('after_start','centered'),true))$data['arrival_window_style']='after_start';
        if(!in_array($data['statement_sort_order'],array('newest','oldest'),true))$data['statement_sort_order']='newest';
        if($data['visit_title_template']==='')$data['visit_title_template']='{{CLIENT_NAME}} - {{JOB_TITLE}}';
        if($data['invoice_subject']==='')$data['invoice_subject']='For Services Rendered';
        foreach(array('default_residential_term_id','default_commercial_term_id') as $k){if($data[$k]<=0)$data[$k]=null;}

        $sets=array();$params=array(':id'=>$settingId,':t'=>$tenant);
        foreach($data as $k=>$v){if(wsCol($pdo,'tenant_work_settings',$k)){$sets[]='`'.$k.'`=:'.$k;$params[':'.$k]=$v;}}
        if(wsCol($pdo,'tenant_work_settings','updated_by')){$sets[]='`updated_by`=:updated_by';$params[':updated_by']=$user;}
        if(wsCol($pdo,'tenant_work_settings','updated_at'))$sets[]='`updated_at`=NOW()';
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE tenant_work_settings SET '.implode(',',$sets).' WHERE id=:id AND tenant_id=:t')->execute($params);

        $uids=array();if(isset($_POST['invoice_reminder_user_ids'])&&is_array($_POST['invoice_reminder_user_ids']))foreach($_POST['invoice_reminder_user_ids'] as $v){$x=(int)$v;if($x>0)$uids[$x]=$x;}
        $pdo->prepare("DELETE FROM work_setting_invoice_reminder_users WHERE tenant_id=:t AND work_setting_id=:w")->execute(array(':t'=>$tenant,':w'=>$settingId));
        if($uids){$ins=$pdo->prepare("INSERT INTO work_setting_invoice_reminder_users(tenant_id,work_setting_id,user_id".(wsCol($pdo,'work_setting_invoice_reminder_users','created_at')?",created_at":"").") VALUES(:t,:w,:u".(wsCol($pdo,'work_setting_invoice_reminder_users','created_at')?",NOW()":"").")");foreach($uids as $uid)$ins->execute(array(':t'=>$tenant,':w'=>$settingId,':u'=>$uid));}
        $pdo->commit();wsAudit($pdo,$tenant,$branch,$user,'WORK_SETTINGS_UPDATED',$settingId,$data);wsOut(200,true,'Work settings updated successfully.');
    }

    if($action==='save_term'){
        wsCsrf();wsRequire($pdo,$tenant,$actor,'settings.update');
        $id=(int)wsPost('term_id','0');$name=wsPost('term_name');$days=(int)wsPost('number_days','0');if($name==='')wsOut(422,false,'Payment term name is required.');if($days<0||$days>3650)wsOut(422,false,'Number of days must be between 0 and 3650.');
        if($id>0){$q=$pdo->prepare("SELECT is_system FROM work_payment_terms WHERE id=:id AND tenant_id=:t LIMIT 1");$q->execute(array(':id'=>$id,':t'=>$tenant));$sys=$q->fetchColumn();if($sys===false)wsOut(404,false,'Payment term not found.');if((int)$sys===1)wsOut(409,false,'Built-in payment terms cannot be edited.');$sets=array('term_name=:n','term_type=\'days\'','number_days=:d');$p=array(':n'=>$name,':d'=>$days,':id'=>$id,':t'=>$tenant);if(wsCol($pdo,'work_payment_terms','updated_by')){$sets[]='updated_by=:u';$p[':u']=$user;}if(wsCol($pdo,'work_payment_terms','updated_at'))$sets[]='updated_at=NOW()';$pdo->prepare('UPDATE work_payment_terms SET '.implode(',',$sets).' WHERE id=:id AND tenant_id=:t')->execute($p);$msg='Payment term updated successfully.';}
        else{$q=$pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+10 FROM work_payment_terms WHERE tenant_id=:t");$q->execute(array(':t'=>$tenant));$sort=(int)$q->fetchColumn();$fields=array('tenant_id','term_name','term_type','number_days','is_system','sort_order');$marks=array(':t',':n',"'days'",':d','0',':s');$p=array(':t'=>$tenant,':n'=>$name,':d'=>$days,':s'=>$sort);if(wsCol($pdo,'work_payment_terms','status')){$fields[]='status';$marks[]="'active'";}if(wsCol($pdo,'work_payment_terms','created_by')){$fields[]='created_by';$marks[]=':u';$p[':u']=$user;}if(wsCol($pdo,'work_payment_terms','created_at')){$fields[]='created_at';$marks[]='NOW()';}$pdo->prepare('INSERT INTO work_payment_terms(`'.implode('`,`',$fields).'`) VALUES('.implode(',',$marks).')')->execute($p);$id=(int)$pdo->lastInsertId();$msg='Payment term added successfully.';}
        wsOut(200,true,$msg,array('terms'=>wsTerms($pdo,$tenant)));
    }

    if($action==='delete_term'){
        wsCsrf();wsRequire($pdo,$tenant,$actor,'settings.update');$id=(int)wsPost('term_id','0');if($id<=0)wsOut(422,false,'Payment term is required.');
        $q=$pdo->prepare("SELECT is_system FROM work_payment_terms WHERE id=:id AND tenant_id=:t LIMIT 1");$q->execute(array(':id'=>$id,':t'=>$tenant));$sys=$q->fetchColumn();if($sys===false)wsOut(404,false,'Payment term not found.');if((int)$sys===1)wsOut(409,false,'Built-in payment terms cannot be deleted.');
        if(wsCol($pdo,'work_payment_terms','deleted_at')){$sets=array('deleted_at=NOW()');if(wsCol($pdo,'work_payment_terms','status'))$sets[]="status='inactive'";if(wsCol($pdo,'work_payment_terms','updated_by'))$sets[]='updated_by='.(int)$user;if(wsCol($pdo,'work_payment_terms','updated_at'))$sets[]='updated_at=NOW()';$pdo->exec('UPDATE work_payment_terms SET '.implode(',',$sets).' WHERE id='.(int)$id.' AND tenant_id='.(int)$tenant);}else{$pdo->prepare("DELETE FROM work_payment_terms WHERE id=:id AND tenant_id=:t")->execute(array(':id'=>$id,':t'=>$tenant));}
        wsOut(200,true,'Payment term deleted successfully.');
    }

    wsOut(400,false,'Invalid work settings action.');
}catch(PDOException $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('FieldPlx work settings API database error: '.$e->getMessage());
    wsOut(500,false,'Unable to complete the work settings request.',array('error_code'=>isset($e->errorInfo[1])?(int)$e->errorInfo[1]:0));
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('FieldPlx work settings API error: '.$e->getMessage());
    wsOut(500,false,'Unable to complete the work settings request.');
}
