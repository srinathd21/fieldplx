<?php
/* FieldPlx Schedule API - Jobber-style dispatcher backend */
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/../includes/db.php';
}
header('Content-Type: application/json; charset=utf-8');

function out($ok, $data = array(), $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(array('success' => (bool)$ok), $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail($msg, $code = 400) { out(false, array('message' => $msg), $code); }
function tbl(PDO $pdo, $name) {
    static $cache = array();
    if (isset($cache[$name])) return $cache[$name];
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute(array($name));
    return $cache[$name] = ((int)$s->fetchColumn() > 0);
}
function col(PDO $pdo, $table, $column) {
    static $cache = array(); $k=$table.'.'.$column;
    if (isset($cache[$k])) return $cache[$k];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute(array($table,$column));
    return $cache[$k]=((int)$s->fetchColumn()>0);
}
function p($key, $default='') { return isset($_POST[$key]) ? $_POST[$key] : $default; }
function intv($key) { return max(0,(int)p($key,0)); }
function jsonv($key, $default=array()) { $x=json_decode((string)p($key,''),true); return is_array($x)?$x:$default; }
function validDate($v) { $d=DateTime::createFromFormat('Y-m-d',$v); return $d && $d->format('Y-m-d')===$v; }
function validTime($v) { return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)$v)===1; }
function dt($date,$time='00:00') { return trim($date.' '.($time?:'00:00').':00'); }
function userName($r){ return trim((isset($r['first_name'])?$r['first_name']:'').' '.(isset($r['last_name'])?$r['last_name']:'')); }
function authCsrf($mutating) {
    if (!$mutating) return;
    $sent=(string)p('csrf_token','');
    $session=(string)(isset($_SESSION['schedule_csrf_token'])?$_SESSION['schedule_csrf_token']:'');
    if ($session==='' || $sent==='' || !hash_equals($session,$sent)) fail('Invalid or expired CSRF token.',419);
}
function currentTenant(){ $t=(int)(isset($_SESSION['tenant_id'])?$_SESSION['tenant_id']:0); if($t<=0) fail('Tenant session is missing.',401); return $t; }
function currentUser(){ return (int)(isset($_SESSION['user_id'])?$_SESSION['user_id']:0); }
function userExists(PDO $pdo,$tenant,$id){ if($id<=0)return true;$s=$pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND tenant_id=? AND status='active'");$s->execute(array($id,$tenant));return (int)$s->fetchColumn()>0; }
function insertActivity(PDO $pdo,$tenant,$user,$type,$relatedType,$relatedId,$title,$details=array()){
    if(!tbl($pdo,'activity_events'))return;
    try{$s=$pdo->prepare("INSERT INTO activity_events (tenant_id,actor_user_id,actor_type,event_type,related_type,related_id,title,details_json,visible_to_client,created_at) VALUES (?,?, 'user',?,?,?,?,?,0,NOW())");$s->execute(array($tenant,$user,$type,$relatedType,$relatedId,$title,json_encode($details)));}catch(Throwable $e){}
}
function userIdsForVisit(PDO $pdo,$tenant,$visitId,$fallback=0){
    $ids=array();
    if(tbl($pdo,'visit_assignments')){ $s=$pdo->prepare("SELECT user_id FROM visit_assignments WHERE tenant_id=? AND visit_id=?");$s->execute(array($tenant,$visitId));foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){if((int)$id>0)$ids[]=(int)$id;} }
    if(!$ids && $fallback>0)$ids[]=(int)$fallback;
    return array_values(array_unique($ids));
}
function replaceVisitAssignments(PDO $pdo,$tenant,$visitId,$ids){
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
    if(tbl($pdo,'visit_assignments')){
        $d=$pdo->prepare("DELETE FROM visit_assignments WHERE tenant_id=? AND visit_id=?");$d->execute(array($tenant,$visitId));
        if($ids){$i=$pdo->prepare("INSERT INTO visit_assignments (tenant_id,visit_id,user_id) VALUES (?,?,?)");foreach($ids as $id)$i->execute(array($tenant,$visitId,$id));}
    }
    if(col($pdo,'visits','assigned_user_id')){$u=$pdo->prepare("UPDATE visits SET assigned_user_id=? WHERE id=? AND tenant_id=?");$u->execute(array($ids?$ids[0]:null,$visitId,$tenant));}
}
function visitStartCol(PDO $pdo){ return col($pdo,'visits','scheduled_start')?'scheduled_start':(col($pdo,'visits','start_at')?'start_at':''); }
function visitEndCol(PDO $pdo){ return col($pdo,'visits','scheduled_end')?'scheduled_end':(col($pdo,'visits','end_at')?'end_at':''); }
function clientDisplayCol(PDO $pdo){ return col($pdo,'clients','display_name')?'display_name':(col($pdo,'clients','name')?'name':'first_name'); }
function propertyTable(PDO $pdo){
    foreach(array('properties','locations','client_locations') as $t){ if(tbl($pdo,$t)) return $t; }
    return '';
}
function jobLocationCol(PDO $pdo){
    foreach(array('property_id','location_id','service_location_id') as $c){ if(col($pdo,'jobs',$c)) return $c; }
    return '';
}

if (!isset($pdo) || !($pdo instanceof PDO)) fail('Database connection is unavailable.',500);
$tenant=currentTenant(); $actor=currentUser(); $action=(string)p('action','meta');
$readActions=array('meta','range','unscheduled','availability','crew_list');
authCsrf(!in_array($action,$readActions,true));

try {
if($action==='meta'){
    $display=clientDisplayCol($pdo);
    $users=array(); if(tbl($pdo,'users')){$userCols=array('id','first_name','last_name');foreach(array('email','job_title','avatar_path','color_code') as $uc){$userCols[]=col($pdo,'users',$uc)?$uc:'NULL AS '.$uc;}$statusWhere=col($pdo,'users','status')?" AND status='active'":'';$s=$pdo->prepare("SELECT ".implode(',', $userCols)." FROM users WHERE tenant_id=?".$statusWhere." ORDER BY first_name,last_name");$s->execute(array($tenant));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$r['name']=userName($r);$users[]=$r;}}
    $clients=array(); if(tbl($pdo,'clients')){$sql="SELECT id,$display AS name,company_name,phone,email FROM clients WHERE tenant_id=?".(col($pdo,'clients','deleted_at')?" AND deleted_at IS NULL":"")." ORDER BY $display";$s=$pdo->prepare($sql);$s->execute(array($tenant));$clients=$s->fetchAll(PDO::FETCH_ASSOC);}
    $props=array();$pt=propertyTable($pdo);if($pt){$sql="SELECT id,client_id,name,address_line1,address_line2,city,state,postal_code,latitude,longitude FROM `$pt` WHERE tenant_id=?".(col($pdo,$pt,'deleted_at')?" AND deleted_at IS NULL":"")." ORDER BY client_id,id";$s=$pdo->prepare($sql);$s->execute(array($tenant));$props=$s->fetchAll(PDO::FETCH_ASSOC);}
    $catalog=array(); if(tbl($pdo,'product_services')){$s=$pdo->prepare("SELECT id,name,description,unit_price,item_type FROM product_services WHERE tenant_id=?".(col($pdo,'product_services','status')?" AND status='active'":"")." ORDER BY name");$s->execute(array($tenant));$catalog=$s->fetchAll(PDO::FETCH_ASSOC);}
    $crews=array(); if(tbl($pdo,'schedule_crews')){$s=$pdo->prepare("SELECT id,name,leader_user_id,is_active FROM schedule_crews WHERE tenant_id=? AND is_active=1 ORDER BY name");$s->execute(array($tenant));$crews=$s->fetchAll(PDO::FETCH_ASSOC);if(tbl($pdo,'schedule_crew_members')){$m=$pdo->prepare("SELECT user_id FROM schedule_crew_members WHERE tenant_id=? AND crew_id=?");foreach($crews as &$c){$m->execute(array($tenant,$c['id']));$c['member_ids']=array_map('intval',$m->fetchAll(PDO::FETCH_COLUMN));}unset($c);}}
    $settings=array('default_view'=>'month','appointment_layout'=>'stacked','show_weekends'=>1,'week_starts_on'=>0,'workday_start'=>'08:00:00','workday_end'=>'18:00:00','slot_minutes'=>30,'default_duration_minutes'=>60,'timezone'=>'Asia/Kolkata');
    if(tbl($pdo,'schedule_settings')){$s=$pdo->prepare("SELECT default_view,appointment_layout,show_weekends,week_starts_on,workday_start,workday_end,slot_minutes,default_duration_minutes,timezone FROM schedule_settings WHERE tenant_id=? AND (user_id=? OR user_id IS NULL) ORDER BY user_id IS NULL ASC LIMIT 1");$s->execute(array($tenant,$actor));$r=$s->fetch(PDO::FETCH_ASSOC);if($r)$settings=array_merge($settings,$r);}
    out(true,array('users'=>$users,'clients'=>$clients,'properties'=>$props,'catalog'=>$catalog,'crews'=>$crews,'settings'=>$settings,'types'=>array('visit','request','task','event','reminder'),'statuses'=>array('completed','overdue','upcoming','confirmed')));
}

if($action==='range'){
    $from=(string)p('from','');$to=(string)p('to','');if(!validDate($from)||!validDate($to)||$from>$to)fail('Invalid schedule date range.');
    $items=array();$vs=visitStartCol($pdo);$ve=visitEndCol($pdo);$display=clientDisplayCol($pdo);$pt=propertyTable($pdo);$jobLocCol=jobLocationCol($pdo);
    if(tbl($pdo,'visits')&&$vs){
        $joins=" LEFT JOIN jobs j ON j.id=v.job_id AND j.tenant_id=v.tenant_id LEFT JOIN clients c ON c.id=j.client_id AND c.tenant_id=v.tenant_id";
        $selectProp="NULL AS property_name,'' AS address";
        $selectJobLoc=$jobLocCol ? "j.$jobLocCol AS property_id" : "NULL AS property_id";
        if($pt && $jobLocCol){
            $joins.=" LEFT JOIN `$pt` p ON p.id=j.$jobLocCol AND p.tenant_id=v.tenant_id";
            $nameExpr=col($pdo,$pt,'name')?'p.name':(col($pdo,$pt,'property_name')?'p.property_name':"''");
            $addr=array();
            foreach(array('address_line1','city','state','postal_code') as $ac){ if(col($pdo,$pt,$ac)) $addr[]='p.'.$ac; }
            $addressExpr=$addr ? "TRIM(CONCAT_WS(', ',".implode(',', $addr)."))" : "''";
            $selectProp="$nameExpr AS property_name,$addressExpr AS address";
        }
        $sql="SELECT v.id,v.job_id,v.visit_no,v.$vs AS start_at,".($ve?"v.$ve":"v.$vs")." AS end_at,v.status,v.instructions,".(col($pdo,'visits','assigned_user_id')?'v.assigned_user_id':'NULL AS assigned_user_id').",j.job_no,j.title,j.client_id,$selectJobLoc,c.$display AS client_name,$selectProp FROM visits v $joins WHERE v.tenant_id=? AND DATE(v.$vs) BETWEEN ? AND ? AND v.$vs IS NOT NULL AND v.status<>'cancelled' ORDER BY v.$vs";
        $s=$pdo->prepare($sql);$s->execute(array($tenant,$from,$to));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$status=$r['status']==='completed'?'completed':((substr((string)$r['end_at'],0,10)<date('Y-m-d')&&$r['status']!=='completed')?'overdue':'upcoming');$items[]=array('key'=>'visit:'.$r['id'],'id'=>(int)$r['id'],'type'=>'visit','title'=>$r['title']?:('Visit '.($r['visit_no']?:'#'.$r['id'])),'client_name'=>$r['client_name'],'property_name'=>$r['property_name'],'address'=>$r['address'],'job_id'=>(int)$r['job_id'],'job_no'=>$r['job_no'],'start'=>$r['start_at'],'end'=>$r['end_at'],'anytime'=>0,'status'=>$status,'confirmed'=>0,'instructions'=>$r['instructions'],'assignee_ids'=>userIdsForVisit($pdo,$tenant,$r['id'],(int)$r['assigned_user_id']));}
    }
    if(tbl($pdo,'tasks')&&col($pdo,'tasks','scheduled_start')){$s=$pdo->prepare("SELECT id,title,description,client_id,property_id,assigned_user_id,scheduled_start,scheduled_end,anytime,status,confirmed_by_client FROM tasks WHERE tenant_id=? AND schedule_later=0 AND scheduled_start IS NOT NULL AND DATE(scheduled_start) BETWEEN ? AND ? AND status<>'cancelled'");$s->execute(array($tenant,$from,$to));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$st=$r['status']==='completed'?'completed':((($r['scheduled_end']?:$r['scheduled_start'])<date('Y-m-d H:i:s'))?'overdue':'upcoming');$items[]=array('key'=>'task:'.$r['id'],'id'=>(int)$r['id'],'type'=>'task','title'=>$r['title'],'client_name'=>'','property_name'=>'','address'=>'','start'=>$r['scheduled_start'],'end'=>$r['scheduled_end']?:$r['scheduled_start'],'anytime'=>(int)$r['anytime'],'status'=>$st,'confirmed'=>(int)$r['confirmed_by_client'],'instructions'=>$r['description'],'assignee_ids'=>$r['assigned_user_id']?array((int)$r['assigned_user_id']):array());}}
    if(tbl($pdo,'schedule_items')){$s=$pdo->prepare("SELECT * FROM schedule_items WHERE tenant_id=? AND schedule_later=0 AND start_at IS NOT NULL AND DATE(start_at) BETWEEN ? AND ? AND status<>'cancelled'");$s->execute(array($tenant,$from,$to));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$st=$r['status']==='completed'?'completed':((($r['end_at']?:$r['start_at'])<date('Y-m-d H:i:s'))?'overdue':'upcoming');$ids=$r['assigned_user_id']?array((int)$r['assigned_user_id']):array();if(tbl($pdo,'schedule_item_assignments')){$a=$pdo->prepare("SELECT user_id FROM schedule_item_assignments WHERE tenant_id=? AND schedule_item_id=?");$a->execute(array($tenant,$r['id']));$x=array_map('intval',$a->fetchAll(PDO::FETCH_COLUMN));if($x)$ids=$x;}$items[]=array('key'=>$r['item_type'].':'.$r['id'],'id'=>(int)$r['id'],'type'=>$r['item_type'],'title'=>$r['title'],'client_name'=>'','property_name'=>'','address'=>'','start'=>$r['start_at'],'end'=>$r['end_at']?:$r['start_at'],'anytime'=>(int)$r['anytime'],'status'=>$st,'confirmed'=>(int)$r['confirmed_by_client'],'instructions'=>$r['description'],'assignee_ids'=>$ids);}}
    // Requests without an assessment are represented by requested_date when present.
    $rt=tbl($pdo,'service_requests')?'service_requests':(tbl($pdo,'requests')?'requests':'');
    if($rt && col($pdo,$rt,'requested_date')){$titleCol=col($pdo,$rt,'request_title')?'request_title':'title';$s=$pdo->prepare("SELECT id,$titleCol AS title,client_id,requested_date,status,".(col($pdo,$rt,'assigned_user_id')?'assigned_user_id':'NULL AS assigned_user_id')." FROM `$rt` WHERE tenant_id=? AND requested_date BETWEEN ? AND ? AND status NOT IN ('closed','rejected','archived')");$s->execute(array($tenant,$from,$to));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$items[]=array('key'=>'request:'.$r['id'],'id'=>(int)$r['id'],'type'=>'request','title'=>$r['title']?:('Request #'.$r['id']),'client_name'=>'','property_name'=>'','address'=>'','start'=>$r['requested_date'].' 00:00:00','end'=>$r['requested_date'].' 23:59:59','anytime'=>1,'status'=>$r['status']==='overdue'?'overdue':'upcoming','confirmed'=>0,'instructions'=>'','assignee_ids'=>$r['assigned_user_id']?array((int)$r['assigned_user_id']):array());}}
    out(true,array('items'=>$items));
}

if($action==='unscheduled'){
    $items=array();$vs=visitStartCol($pdo);
    if(tbl($pdo,'visits')&&$vs){$s=$pdo->prepare("SELECT v.id,v.job_id,v.visit_no,v.instructions,v.created_at,j.job_no,j.title,".(col($pdo,'visits','assigned_user_id')?'v.assigned_user_id':'NULL AS assigned_user_id')." FROM visits v LEFT JOIN jobs j ON j.id=v.job_id AND j.tenant_id=v.tenant_id WHERE v.tenant_id=? AND v.$vs IS NULL AND v.status<>'cancelled' ORDER BY v.created_at");$s->execute(array($tenant));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$items[]=array('key'=>'visit:'.$r['id'],'id'=>(int)$r['id'],'type'=>'visit','title'=>$r['title']?:'Unscheduled visit','client_name'=>'','created_at'=>$r['created_at'],'assignee_ids'=>userIdsForVisit($pdo,$tenant,$r['id'],(int)$r['assigned_user_id']));}
    if(tbl($pdo,'tasks')&&col($pdo,'tasks','schedule_later')){$s=$pdo->prepare("SELECT id,title,created_at,assigned_user_id FROM tasks WHERE tenant_id=? AND (schedule_later=1 OR scheduled_start IS NULL) AND status NOT IN ('completed','cancelled') ORDER BY created_at");$s->execute(array($tenant));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$items[]=array('key'=>'task:'.$r['id'],'id'=>(int)$r['id'],'type'=>'task','title'=>$r['title'],'client_name'=>'','created_at'=>$r['created_at'],'assignee_ids'=>$r['assigned_user_id']?array((int)$r['assigned_user_id']):array());}
    out(true,array('items'=>$items,'count'=>count($items)));
}

if($action==='schedule_visit'){
    $id=intv('id');$date=(string)p('date','');$time=(string)p('time','09:00');$duration=max(15,(int)p('duration_minutes',60));$any=(int)p('anytime',0)===1;
    if($id<=0||!validDate($date)||(!$any&&!validTime($time)))fail('Invalid visit schedule.');$vs=visitStartCol($pdo);$ve=visitEndCol($pdo);if(!$vs)fail('Visit scheduling columns were not found.',500);
    $q=$pdo->prepare("SELECT * FROM visits WHERE id=? AND tenant_id=? LIMIT 1");$q->execute(array($id,$tenant));$old=$q->fetch(PDO::FETCH_ASSOC);if(!$old)fail('Visit not found.',404);
    $start=dt($date,$any?'00:00':$time);$end=(new DateTime($start))->modify('+'.$duration.' minutes')->format('Y-m-d H:i:s');
    $set="$vs=?".($ve?",$ve=?":"").",status=IF(status='draft','scheduled',status)";$params=$ve?array($start,$end,$id,$tenant):array($start,$id,$tenant);$u=$pdo->prepare("UPDATE visits SET $set WHERE id=? AND tenant_id=?");$u->execute($params);
    if(tbl($pdo,'visit_reschedule_history')){try{
        $oldStartCol=col($pdo,'visit_reschedule_history','old_start')?'old_start':'old_start_at';
        $oldEndCol=col($pdo,'visit_reschedule_history','old_end')?'old_end':'old_end_at';
        $newStartCol=col($pdo,'visit_reschedule_history','new_start')?'new_start':'new_start_at';
        $newEndCol=col($pdo,'visit_reschedule_history','new_end')?'new_end':'new_end_at';
        $actorCol=col($pdo,'visit_reschedule_history','rescheduled_by')?'rescheduled_by':'changed_by';
        $h=$pdo->prepare("INSERT INTO visit_reschedule_history (tenant_id,visit_id,`$oldStartCol`,`$oldEndCol`,`$newStartCol`,`$newEndCol`,`$actorCol`,reason,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
        $h->execute(array($tenant,$id,$old[$vs],$ve?$old[$ve]:null,$start,$end,$actor,'Schedule calendar move'));
    }catch(Throwable $e){}}
    insertActivity($pdo,$tenant,$actor,'visit_rescheduled','visit',$id,'Visit rescheduled',array('start'=>$start,'end'=>$end));out(true,array('message'=>'Visit scheduled.'));
}

if($action==='unschedule_visit'){
    $id=intv('id');$vs=visitStartCol($pdo);$ve=visitEndCol($pdo);if($id<=0||!$vs)fail('Invalid visit.');$set="$vs=NULL".($ve?",$ve=NULL":"");$u=$pdo->prepare("UPDATE visits SET $set,status=IF(status='completed',status,'draft') WHERE id=? AND tenant_id=?");$u->execute(array($id,$tenant));out(true,array('message'=>'Visit moved to Unscheduled.'));
}
if($action==='complete_visit'){$id=intv('id');$complete=(int)p('completed',1)===1;$set="status=?";if(col($pdo,'visits','actual_end'))$set.=',actual_end=?';$vals=array($complete?'completed':'scheduled');if(col($pdo,'visits','actual_end'))$vals[]=($complete?date('Y-m-d H:i:s'):null);$vals[]=$id;$vals[]=$tenant;$u=$pdo->prepare("UPDATE visits SET $set WHERE id=? AND tenant_id=?");$u->execute($vals);out(true,array('message'=>$complete?'Visit completed.':'Visit reopened.'));}


if($action==='quick_job'){
    if(!tbl($pdo,'jobs')||!tbl($pdo,'visits')) fail('Jobs/Visits tables are unavailable.',500);
    $title=trim((string)p('title',''));$client=intv('client_id');$property=intv('property_id')?:null;
    if($title===''||$client<=0)fail('Client and job title are required.');
    $c=$pdo->prepare("SELECT COUNT(*) FROM clients WHERE id=? AND tenant_id=?");$c->execute(array($client,$tenant));if(!(int)$c->fetchColumn())fail('Client not found.',404);
    $later=(int)p('schedule_later',0)===1;$any=(int)p('anytime',0)===1;$date=(string)p('date','');$time=(string)p('time','09:00');$duration=max(15,(int)p('duration_minutes',60));
    if(!$later&&(!validDate($date)||(!$any&&!validTime($time))))fail('Select a valid schedule or choose Schedule later.');
    $jobNo='JOB-S-'.date('Ymd-His').'-'.random_int(100,999);
    $pdo->beginTransaction();
    $cols=array('tenant_id','job_no','client_id','title','status','created_by','created_at');$vals=array($tenant,$jobNo,$client,$title,$later?'unscheduled':'scheduled',$actor,date('Y-m-d H:i:s'));
    $jobLocCol=jobLocationCol($pdo);if($jobLocCol){$cols[]=$jobLocCol;$vals[]=$property;}
    if(col($pdo,'jobs','description')){$cols[]='description';$vals[]=p('instructions','');}
    if(col($pdo,'jobs','job_type')){$cols[]='job_type';$vals[]='one_off';}
    if(col($pdo,'jobs','start_date')){$cols[]='start_date';$vals[]=$later?null:$date;}
    if(col($pdo,'jobs','end_date')){$cols[]='end_date';$vals[]=$later?null:$date;}
    $sql="INSERT INTO jobs (`".implode('`,`',$cols)."`) VALUES (".implode(',',array_fill(0,count($cols),'?')).")";$pdo->prepare($sql)->execute($vals);$jobId=(int)$pdo->lastInsertId();
    $vs=visitStartCol($pdo);$ve=visitEndCol($pdo);$start=$later?null:dt($date,$any?'00:00':$time);$end=$later?null:(new DateTime($start))->modify('+'.$duration.' minutes')->format('Y-m-d H:i:s');
    $vcols=array('tenant_id','job_id','status','instructions','created_at');$vvals=array($tenant,$jobId,$later?'draft':'scheduled',p('instructions',''),date('Y-m-d H:i:s'));
    if($vs){$vcols[]=$vs;$vvals[]=$start;}if($ve){$vcols[]=$ve;$vvals[]=$end;}if(col($pdo,'visits','visit_no')){$vcols[]='visit_no';$vvals[]='VIS-'.$jobId.'-1';}
    $ids=jsonv('assignee_ids',array());if(col($pdo,'visits','assigned_user_id')){$vcols[]='assigned_user_id';$vvals[]=$ids?(int)$ids[0]:null;}
    $sql="INSERT INTO visits (`".implode('`,`',$vcols)."`) VALUES (".implode(',',array_fill(0,count($vcols),'?')).")";$pdo->prepare($sql)->execute($vvals);$visitId=(int)$pdo->lastInsertId();replaceVisitAssignments($pdo,$tenant,$visitId,$ids);
    $pdo->commit();insertActivity($pdo,$tenant,$actor,'job_created','job',$jobId,'Job created from Schedule',array('job_no'=>$jobNo));out(true,array('job_id'=>$jobId,'visit_id'=>$visitId,'message'=>'Job created and added to Schedule.'));
}

if($action==='quick_request'){
    $rt=tbl($pdo,'service_requests')?'service_requests':(tbl($pdo,'requests')?'requests':'');if(!$rt)fail('Request table is unavailable.',500);
    $title=trim((string)p('title',''));$client=intv('client_id');if($title===''||$client<=0)fail('Client and request title are required.');
    $requestNo='REQ-S-'.date('Ymd-His').'-'.random_int(100,999);$cols=array('tenant_id');$vals=array($tenant);
    if(col($pdo,$rt,'request_no')){$cols[]='request_no';$vals[]=$requestNo;}
    if(col($pdo,$rt,'client_id')){$cols[]='client_id';$vals[]=$client;}
    if(col($pdo,$rt,'property_id')){$cols[]='property_id';$vals[]=intv('property_id')?:null;}elseif(col($pdo,$rt,'location_id')){$cols[]='location_id';$vals[]=intv('property_id')?:null;}
    $tc=col($pdo,$rt,'request_title')?'request_title':'title';$cols[]=$tc;$vals[]=$title;
    if(col($pdo,$rt,'description')){$cols[]='description';$vals[]=p('instructions','');}
    if(col($pdo,$rt,'status')){$cols[]='status';$vals[]='new';}
    if(col($pdo,$rt,'requested_date')){$cols[]='requested_date';$vals[]=((int)p('schedule_later',0)===1?null:p('date',date('Y-m-d')));}
    if(col($pdo,$rt,'assigned_user_id')){$ids=jsonv('assignee_ids',array());$cols[]='assigned_user_id';$vals[]=$ids?(int)$ids[0]:null;}
    if(col($pdo,$rt,'created_by')){$cols[]='created_by';$vals[]=$actor;}if(col($pdo,$rt,'created_at')){$cols[]='created_at';$vals[]=date('Y-m-d H:i:s');}
    $sql="INSERT INTO `$rt` (`".implode('`,`',$cols)."`) VALUES (".implode(',',array_fill(0,count($cols),'?')).")";$pdo->prepare($sql)->execute($vals);$id=(int)$pdo->lastInsertId();insertActivity($pdo,$tenant,$actor,'request_created','request',$id,'Request created from Schedule',array('request_no'=>$requestNo));out(true,array('request_id'=>$id,'message'=>'Request created.'));
}

if($action==='save_task'){
    $id=intv('id');$title=trim((string)p('title',''));if($title==='')fail('Task title is required.');$client=intv('client_id')?:null;$prop=intv('property_id')?:null;$assigned=intv('assigned_user_id')?:null;if($assigned&&!userExists($pdo,$tenant,$assigned))fail('Invalid assignee.');$later=(int)p('schedule_later',0)===1;$any=(int)p('anytime',0)===1;$date=(string)p('date','');$time=(string)p('time','09:00');$dur=max(15,(int)p('duration_minutes',60));$start=null;$end=null;if(!$later){if(!validDate($date)||(!$any&&!validTime($time)))fail('Select a valid task schedule.');$start=dt($date,$any?'00:00':$time);$end=(new DateTime($start))->modify('+'.$dur.' minutes')->format('Y-m-d H:i:s');}
    if($id){$s=$pdo->prepare("UPDATE tasks SET title=?,description=?,client_id=?,property_id=?,assigned_user_id=?,scheduled_start=?,scheduled_end=?,anytime=?,schedule_later=?,updated_at=NOW() WHERE id=? AND tenant_id=?");$s->execute(array($title,p('description',''),$client,$prop,$assigned,$start,$end,$any?1:0,$later?1:0,$id,$tenant));}
    else{$s=$pdo->prepare("INSERT INTO tasks (tenant_id,title,description,assigned_user_id,client_id,property_id,due_at,scheduled_start,scheduled_end,anytime,schedule_later,status,priority,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'open','normal',?,NOW())");$s->execute(array($tenant,$title,p('description',''),$assigned,$client,$prop,$end,$start,$end,$any?1:0,$later?1:0,$actor));$id=(int)$pdo->lastInsertId();}
    out(true,array('id'=>$id,'message'=>'Task saved.'));
}

if($action==='save_event'){
    $id=intv('id');$type=(string)p('item_type','event');if(!in_array($type,array('event','reminder'),true))$type='event';$title=trim((string)p('title',''));if($title==='')fail(ucfirst($type).' title is required.');$later=(int)p('schedule_later',0)===1;$any=(int)p('anytime',0)===1;$date=(string)p('date','');$endDate=(string)p('end_date',$date);$time=(string)p('time','09:00');$endTime=(string)p('end_time','10:00');$start=null;$end=null;if(!$later){if(!validDate($date)||!validDate($endDate)||(!$any&&(!validTime($time)||!validTime($endTime))))fail('Invalid event schedule.');$start=dt($date,$any?'00:00':$time);$end=dt($endDate,$any?'23:59':$endTime);if($end<$start)fail('End must be after start.');}$ids=jsonv('assignee_ids',array());$first=$ids?(int)$ids[0]:(int)p('assigned_user_id',0);$first=$first?:null;
    if($id){$s=$pdo->prepare("UPDATE schedule_items SET item_type=?,title=?,description=?,client_id=?,property_id=?,assigned_user_id=?,start_at=?,end_at=?,anytime=?,schedule_later=?,recurrence_json=?,updated_by=?,updated_at=NOW() WHERE id=? AND tenant_id=?");$s->execute(array($type,$title,p('description',''),intv('client_id')?:null,intv('property_id')?:null,$first,$start,$end,$any?1:0,$later?1:0,p('recurrence_json',''),$actor,$id,$tenant));}
    else{$s=$pdo->prepare("INSERT INTO schedule_items (tenant_id,item_type,title,description,client_id,property_id,assigned_user_id,start_at,end_at,anytime,schedule_later,status,confirmed_by_client,recurrence_json,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'upcoming',0,?,?,NOW())");$s->execute(array($tenant,$type,$title,p('description',''),intv('client_id')?:null,intv('property_id')?:null,$first,$start,$end,$any?1:0,$later?1:0,p('recurrence_json',''),$actor));$id=(int)$pdo->lastInsertId();}
    if(tbl($pdo,'schedule_item_assignments')){$d=$pdo->prepare("DELETE FROM schedule_item_assignments WHERE tenant_id=? AND schedule_item_id=?");$d->execute(array($tenant,$id));if($ids){$i=$pdo->prepare("INSERT INTO schedule_item_assignments (tenant_id,schedule_item_id,user_id) VALUES (?,?,?)");foreach(array_unique(array_map('intval',$ids)) as $uid){if($uid>0&&userExists($pdo,$tenant,$uid))$i->execute(array($tenant,$id,$uid));}}}
    out(true,array('id'=>$id,'message'=>ucfirst($type).' saved.'));
}
if($action==='delete_event'){$id=intv('id');$s=$pdo->prepare("UPDATE schedule_items SET status='cancelled',updated_by=?,updated_at=NOW() WHERE id=? AND tenant_id=?");$s->execute(array($actor,$id,$tenant));out(true,array('message'=>'Schedule item deleted.'));}

if($action==='bulk_update'){
    $items=jsonv('items',array());if(!$items)fail('Select at least one appointment.');$specific=(string)p('specific_date','');$shift=(int)p('shift_days',0);$reassign=jsonv('assignee_ids',array());$doReassign=(int)p('do_reassign',0)===1;if($specific!==''&&!validDate($specific))fail('Invalid specific date.');$pdo->beginTransaction();
    foreach($items as $it){$type=isset($it['type'])?$it['type']:'';$id=(int)(isset($it['id'])?$it['id']:0);if($id<=0)continue;if($type==='visit'){$vs=visitStartCol($pdo);$ve=visitEndCol($pdo);$q=$pdo->prepare("SELECT $vs AS s,".($ve?$ve:"$vs")." AS e FROM visits WHERE id=? AND tenant_id=? FOR UPDATE");$q->execute(array($id,$tenant));$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)continue;$ns=$r['s'];$ne=$r['e'];if($ns){$sd=new DateTime($ns);$ed=new DateTime($ne);if($specific){$delta=$ed->getTimestamp()-$sd->getTimestamp();$new=new DateTime($specific.' '.$sd->format('H:i:s'));$ns=$new->format('Y-m-d H:i:s');$ne=date('Y-m-d H:i:s',$new->getTimestamp()+$delta);}elseif($shift){$sd->modify(($shift>0?'+':'').$shift.' days');$ed->modify(($shift>0?'+':'').$shift.' days');$ns=$sd->format('Y-m-d H:i:s');$ne=$ed->format('Y-m-d H:i:s');}$set="$vs=?".($ve?",$ve=?":"");$u=$pdo->prepare("UPDATE visits SET $set WHERE id=? AND tenant_id=?");$u->execute($ve?array($ns,$ne,$id,$tenant):array($ns,$id,$tenant));}if($doReassign)replaceVisitAssignments($pdo,$tenant,$id,$reassign);}
      elseif($type==='task'){$q=$pdo->prepare("SELECT scheduled_start s,scheduled_end e FROM tasks WHERE id=? AND tenant_id=? FOR UPDATE");$q->execute(array($id,$tenant));$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)continue;$ns=$r['s'];$ne=$r['e'];if($ns&&($specific||$shift)){$sd=new DateTime($ns);$ed=new DateTime($ne?:$ns);if($specific){$delta=$ed->getTimestamp()-$sd->getTimestamp();$new=new DateTime($specific.' '.$sd->format('H:i:s'));$ns=$new->format('Y-m-d H:i:s');$ne=date('Y-m-d H:i:s',$new->getTimestamp()+$delta);}else{$sd->modify(($shift>0?'+':'').$shift.' days');$ed->modify(($shift>0?'+':'').$shift.' days');$ns=$sd->format('Y-m-d H:i:s');$ne=$ed->format('Y-m-d H:i:s');}}$assigned=$doReassign?($reassign?(int)$reassign[0]:null):null;$sql="UPDATE tasks SET scheduled_start=?,scheduled_end=?".($doReassign?',assigned_user_id=?':'')." WHERE id=? AND tenant_id=?";$vals=array($ns,$ne);if($doReassign)$vals[]=$assigned;$vals[]=$id;$vals[]=$tenant;$pdo->prepare($sql)->execute($vals);}
      elseif(in_array($type,array('event','reminder'),true)){$q=$pdo->prepare("SELECT start_at s,end_at e FROM schedule_items WHERE id=? AND tenant_id=? FOR UPDATE");$q->execute(array($id,$tenant));$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)continue;$ns=$r['s'];$ne=$r['e'];if($ns&&($specific||$shift)){$sd=new DateTime($ns);$ed=new DateTime($ne?:$ns);if($specific){$delta=$ed->getTimestamp()-$sd->getTimestamp();$new=new DateTime($specific.' '.$sd->format('H:i:s'));$ns=$new->format('Y-m-d H:i:s');$ne=date('Y-m-d H:i:s',$new->getTimestamp()+$delta);}else{$sd->modify(($shift>0?'+':'').$shift.' days');$ed->modify(($shift>0?'+':'').$shift.' days');$ns=$sd->format('Y-m-d H:i:s');$ne=$ed->format('Y-m-d H:i:s');}}$assigned=$doReassign?($reassign?(int)$reassign[0]:null):null;$sql="UPDATE schedule_items SET start_at=?,end_at=?".($doReassign?',assigned_user_id=?':'')." WHERE id=? AND tenant_id=?";$vals=array($ns,$ne);if($doReassign)$vals[]=$assigned;$vals[]=$id;$vals[]=$tenant;$pdo->prepare($sql)->execute($vals);}
    }
    $pdo->commit();out(true,array('message'=>'Selected appointments updated.'));
}

if($action==='save_crew'){
    $id=intv('id');$name=trim((string)p('name',''));if($name==='')fail('Crew name is required.');$leader=intv('leader_user_id')?:null;$members=jsonv('member_ids',array());if($leader&&array_search($leader,array_map('intval',$members),true)===false)$members[]=$leader;$pdo->beginTransaction();if($id){$s=$pdo->prepare("UPDATE schedule_crews SET name=?,leader_user_id=?,is_active=?,updated_at=NOW() WHERE id=? AND tenant_id=?");$s->execute(array($name,$leader,(int)p('is_active',1),$id,$tenant));}else{$s=$pdo->prepare("INSERT INTO schedule_crews (tenant_id,name,leader_user_id,is_active,created_by,created_at) VALUES (?,?,?,1,?,NOW())");$s->execute(array($tenant,$name,$leader,$actor));$id=(int)$pdo->lastInsertId();}$pdo->prepare("DELETE FROM schedule_crew_members WHERE tenant_id=? AND crew_id=?")->execute(array($tenant,$id));$i=$pdo->prepare("INSERT INTO schedule_crew_members (tenant_id,crew_id,user_id) VALUES (?,?,?)");foreach(array_unique(array_map('intval',$members)) as $uid){if($uid>0&&userExists($pdo,$tenant,$uid))$i->execute(array($tenant,$id,$uid));}$pdo->commit();out(true,array('id'=>$id,'message'=>'Crew saved.'));
}
if($action==='crew_list'){
    $s=$pdo->prepare("SELECT id,name,leader_user_id,is_active FROM schedule_crews WHERE tenant_id=? ORDER BY is_active DESC,name");$s->execute(array($tenant));$rows=$s->fetchAll(PDO::FETCH_ASSOC);$m=$pdo->prepare("SELECT user_id FROM schedule_crew_members WHERE tenant_id=? AND crew_id=?");foreach($rows as &$r){$m->execute(array($tenant,$r['id']));$r['member_ids']=array_map('intval',$m->fetchAll(PDO::FETCH_COLUMN));}unset($r);out(true,array('crews'=>$rows));
}

if($action==='save_settings'){
    $view=(string)p('default_view','month');if(!in_array($view,array('month','week','day'),true))$view='month';$layout=(string)p('appointment_layout','stacked');if(!in_array($layout,array('stacked','nested'),true))$layout='stacked';$start=(string)p('workday_start','08:00');$end=(string)p('workday_end','18:00');if(!validTime(substr($start,0,5))||!validTime(substr($end,0,5)))fail('Invalid workday times.');$slot=max(15,min(120,(int)p('slot_minutes',30)));$dur=max(15,min(1440,(int)p('default_duration_minutes',60)));$tz=(string)p('timezone','Asia/Kolkata');$s=$pdo->prepare("SELECT id FROM schedule_settings WHERE tenant_id=? AND user_id=? LIMIT 1");$s->execute(array($tenant,$actor));$id=(int)$s->fetchColumn();$vals=array($view,$layout,(int)p('show_weekends',1),(int)p('week_starts_on',0),$start,$end,$slot,$dur,$tz);if($id){$u=$pdo->prepare("UPDATE schedule_settings SET default_view=?,appointment_layout=?,show_weekends=?,week_starts_on=?,workday_start=?,workday_end=?,slot_minutes=?,default_duration_minutes=?,timezone=?,updated_at=NOW() WHERE id=? AND tenant_id=?");$u->execute(array_merge($vals,array($id,$tenant)));}else{$u=$pdo->prepare("INSERT INTO schedule_settings (tenant_id,user_id,default_view,appointment_layout,show_weekends,week_starts_on,workday_start,workday_end,slot_minutes,default_duration_minutes,timezone,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");$u->execute(array_merge(array($tenant,$actor),$vals));}out(true,array('message'=>'Schedule settings saved.'));
}

if($action==='availability'){
    $userId=intv('user_id');$crewId=intv('crew_id');$date=(string)p('date',date('Y-m-d'));$days=max(1,min(31,(int)p('days',7)));$duration=max(15,min(480,(int)p('duration_minutes',60)));if(!validDate($date))fail('Invalid date.');$users=array();if($crewId&&tbl($pdo,'schedule_crew_members')){$s=$pdo->prepare("SELECT user_id FROM schedule_crew_members WHERE tenant_id=? AND crew_id=?");$s->execute(array($tenant,$crewId));$users=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));}elseif($userId)$users=array($userId);if(!$users)fail('Select a team member or crew.');foreach($users as $uid)if(!userExists($pdo,$tenant,$uid))fail('Invalid team member.');
    $settings=array('workday_start'=>'08:00:00','workday_end'=>'18:00:00','slot_minutes'=>30);if(tbl($pdo,'schedule_settings')){$s=$pdo->prepare("SELECT workday_start,workday_end,slot_minutes FROM schedule_settings WHERE tenant_id=? AND (user_id=? OR user_id IS NULL) ORDER BY user_id IS NULL ASC LIMIT 1");$s->execute(array($tenant,$actor));$x=$s->fetch(PDO::FETCH_ASSOC);if($x)$settings=array_merge($settings,$x);}
    $busy=array();$vs=visitStartCol($pdo);$ve=visitEndCol($pdo);$endDate=(new DateTime($date))->modify('+'.($days-1).' days')->format('Y-m-d');foreach($users as $uid){if($vs){$sql="SELECT v.$vs s,".($ve?"v.$ve":"v.$vs")." e FROM visits v LEFT JOIN visit_assignments va ON va.visit_id=v.id AND va.tenant_id=v.tenant_id WHERE v.tenant_id=? AND DATE(v.$vs) BETWEEN ? AND ? AND (".(col($pdo,'visits','assigned_user_id')?'v.assigned_user_id=? OR ':'')."va.user_id=?) AND v.status NOT IN ('completed','cancelled')";$vals=array($tenant,$date,$endDate);if(col($pdo,'visits','assigned_user_id'))$vals[]=$uid;$vals[]=$uid;$s=$pdo->prepare($sql);$s->execute($vals);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $b)$busy[]=array($b['s'],$b['e']?:$b['s']);}}
    $slots=array();$cursor=new DateTime($date);for($d=0;$d<$days;$d++){$day=$cursor->format('Y-m-d');$begin=new DateTime($day.' '.substr($settings['workday_start'],0,5));$finish=new DateTime($day.' '.substr($settings['workday_end'],0,5));for($t=clone $begin;$t<$finish;$t->modify('+'.(int)$settings['slot_minutes'].' minutes')){$e=clone $t;$e->modify('+'.$duration.' minutes');if($e>$finish)continue;$ok=true;foreach($busy as $b){$bs=new DateTime($b[0]);$be=new DateTime($b[1]);if($t<$be&&$e>$bs){$ok=false;break;}}if($ok)$slots[]=array('start'=>$t->format('Y-m-d H:i:s'),'end'=>$e->format('Y-m-d H:i:s'));if(count($slots)>=40)break;}if(count($slots)>=40)break;$cursor->modify('+1 day');}
    out(true,array('slots'=>$slots));
}

fail('Unknown schedule action.',404);
} catch (Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('FieldPlx schedule API: '.$e->getMessage());
    fail('Schedule operation failed: '.$e->getMessage(),500);
}
