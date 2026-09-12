<?php
ob_start();
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) require_once __DIR__ . '/../includes/audit.php';

function cl_out($status,$success,$message,$extra=array()){while(ob_get_level()>0)@ob_end_clean();http_response_code($status);echo json_encode(array_merge(array('success'=>$success,'message'=>$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function cl_post($k,$d=''){return isset($_POST[$k])&&!is_array($_POST[$k])?trim((string)$_POST[$k]):$d;}
function cl_col(PDO $pdo,$table,$col){$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");$s->execute(array(':t'=>$table,':c'=>$col));return (int)$s->fetchColumn()>0;}
function cl_csrf(){if(empty($_SESSION['checklists_csrf'])||!hash_equals((string)$_SESSION['checklists_csrf'],cl_post('csrf_token')))cl_out(419,false,'Your form session expired. Refresh and try again.');}

$tenantId=isset($currentTenantId)?(int)$currentTenantId:(isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0);
$userId=isset($currentTenantUserId)?(int)$currentTenantUserId:(isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:(isset($_SESSION['id'])?(int)$_SESSION['id']:0));
if($tenantId<=0||$userId<=0)cl_out(401,false,'Tenant session is not available.');

$action=cl_post('action');

try{
 if($action==='list'){
   $search=cl_post('search');
   $where="tenant_id=:t AND status='active'";
   $p=array(':t'=>$tenantId);
   if($search!==''){$where.=" AND name LIKE :s";$p[':s']='%'.$search.'%';}
   $cols="id,name,status,created_at,updated_at";
   $cols.=cl_col($pdo,'checklist_templates','auto_attach_jobs')?",auto_attach_jobs":",0 AS auto_attach_jobs";
   $cols.=cl_col($pdo,'checklist_templates','auto_attach_assessments')?",auto_attach_assessments":",0 AS auto_attach_assessments";
   $s=$pdo->prepare("SELECT $cols FROM checklist_templates WHERE $where ORDER BY name ASC,id DESC");$s->execute($p);
   cl_out(200,true,'Checklists loaded.',array('items'=>$s->fetchAll(PDO::FETCH_ASSOC)));
 }

 if($action==='get'){
   $id=(int)cl_post('id','0');if($id<=0)cl_out(422,false,'Invalid checklist.');
   $cols="id,name,description,status";
   $cols.=cl_col($pdo,'checklist_templates','auto_attach_jobs')?",auto_attach_jobs":",0 AS auto_attach_jobs";
   $cols.=cl_col($pdo,'checklist_templates','auto_attach_assessments')?",auto_attach_assessments":",0 AS auto_attach_assessments";
   $s=$pdo->prepare("SELECT $cols FROM checklist_templates WHERE id=:id AND tenant_id=:t LIMIT 1");$s->execute(array(':id'=>$id,':t'=>$tenantId));$c=$s->fetch(PDO::FETCH_ASSOC);if(!$c)cl_out(404,false,'Checklist not found.');
   $s=$pdo->prepare("SELECT id,section_title,title,description,question_type,options_json,is_required,sort_order FROM checklist_template_items WHERE checklist_template_id=:id ORDER BY sort_order,id");$s->execute(array(':id'=>$id));$items=$s->fetchAll(PDO::FETCH_ASSOC);
   foreach($items as &$x){$x['options']=array();if(!empty($x['options_json'])){$o=json_decode($x['options_json'],true);if(is_array($o))$x['options']=$o;}if((empty($x['section_title'])||$x['question_type']==='checkbox')&&!empty($x['description'])&&substr(trim($x['description']),0,1)==='{'){$m=json_decode($x['description'],true);if(is_array($m)){if(empty($x['section_title'])&&!empty($m['section_title']))$x['section_title']=$m['section_title'];if(!empty($m['question_type']))$x['question_type']=$m['question_type'];if(empty($x['options'])&&!empty($m['options'])&&is_array($m['options']))$x['options']=$m['options'];}}}
   unset($x);$c['items']=$items;cl_out(200,true,'Checklist loaded.',array('checklist'=>$c));
 }

 if($action==='save'){
   cl_csrf();$id=(int)cl_post('id','0');$name=substr(cl_post('name'),0,190);if($name==='')cl_out(422,false,'Checklist title is required.');
   $items=json_decode(cl_post('items_json','[]'),true);if(!is_array($items)||!count($items))cl_out(422,false,'Add at least one checklist question.');
   $autoJobs=cl_post('auto_attach_jobs')==='1'?1:0;$autoAssess=cl_post('auto_attach_assessments')==='1'?1:0;
   $hasJobs=cl_col($pdo,'checklist_templates','auto_attach_jobs');$hasAssess=cl_col($pdo,'checklist_templates','auto_attach_assessments');
   $pdo->beginTransaction();
   if($id>0){
     $set="name=:n,updated_at=NOW()";$pa=array(':n'=>$name,':id'=>$id,':t'=>$tenantId);
     if($hasJobs){$set.=",auto_attach_jobs=:aj";$pa[':aj']=$autoJobs;}if($hasAssess){$set.=",auto_attach_assessments=:aa";$pa[':aa']=$autoAssess;}
     $s=$pdo->prepare("UPDATE checklist_templates SET $set WHERE id=:id AND tenant_id=:t");$s->execute($pa);if(!$s->rowCount()){ $c=$pdo->prepare("SELECT id FROM checklist_templates WHERE id=:id AND tenant_id=:t");$c->execute(array(':id'=>$id,':t'=>$tenantId));if(!$c->fetchColumn())throw new RuntimeException('Checklist not found.');}
     $pdo->prepare("DELETE FROM checklist_template_items WHERE checklist_template_id=:id")->execute(array(':id'=>$id));
   }else{
     $cols="tenant_id,name,description,status,created_by";$vals=":t,:n,NULL,'active',:u";$pa=array(':t'=>$tenantId,':n'=>$name,':u'=>$userId);
     if($hasJobs){$cols.=",auto_attach_jobs";$vals.=", :aj";$pa[':aj']=$autoJobs;}if($hasAssess){$cols.=",auto_attach_assessments";$vals.=", :aa";$pa[':aa']=$autoAssess;}
     $s=$pdo->prepare("INSERT INTO checklist_templates($cols) VALUES($vals)");$s->execute($pa);$id=(int)$pdo->lastInsertId();
   }
   $ins=$pdo->prepare("INSERT INTO checklist_template_items(checklist_template_id,section_title,title,description,question_type,options_json,is_required,sort_order) VALUES(:ct,:section,:title,NULL,:type,:opts,:req,:ord)");
   $ord=0;foreach($items as $x){$title=substr(trim((string)(isset($x['title'])?$x['title']:'')),0,255);if($title==='')continue;$type=isset($x['question_type'])?(string)$x['question_type']:'short_answer';if(!in_array($type,array('short_answer','long_answer','dropdown','checkbox','number','image','date','signature'),true))$type='short_answer';$opts=isset($x['options'])&&is_array($x['options'])?array_values(array_filter(array_map('strval',$x['options']),function($v){return trim($v)!=='';})):array();$ord++;$ins->execute(array(':ct'=>$id,':section'=>substr(trim((string)(isset($x['section_title'])?$x['section_title']:'Section 1')),0,190),':title'=>$title,':type'=>$type,':opts'=>count($opts)?json_encode($opts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,':req'=>!empty($x['is_required'])?1:0,':ord'=>$ord));}
   if($ord===0)throw new RuntimeException('Add at least one valid checklist question.');
   $pdo->commit();cl_out(200,true,'Checklist saved successfully.',array('id'=>$id));
 }

 if($action==='duplicate'){
   $id=(int)cl_post('id','0');if($id<=0)cl_out(422,false,'Invalid checklist.');
   $s=$pdo->prepare("SELECT * FROM checklist_templates WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");$s->execute(array(':id'=>$id,':t'=>$tenantId));$c=$s->fetch(PDO::FETCH_ASSOC);if(!$c)cl_out(404,false,'Checklist not found.');
   $pdo->beginTransaction();
   $cols=array('tenant_id','name','description','status','created_by');$vals=array(':t',':n',':d',"'active'",':u');$pa=array(':t'=>$tenantId,':n'=>substr($c['name'].' copy',0,190),':d'=>$c['description'],':u'=>$userId);
   foreach(array('auto_attach_jobs','auto_attach_assessments') as $col)if(cl_col($pdo,'checklist_templates',$col)){$cols[]=$col;$vals[]=':'.$col;$pa[':'.$col]=0;}
   $q=$pdo->prepare("INSERT INTO checklist_templates(".implode(',',$cols).") VALUES(".implode(',',$vals).")");$q->execute($pa);$newId=(int)$pdo->lastInsertId();
   $q=$pdo->prepare("SELECT section_title,title,description,question_type,options_json,is_required,sort_order FROM checklist_template_items WHERE checklist_template_id=:id ORDER BY sort_order,id");$q->execute(array(':id'=>$id));
   $ins=$pdo->prepare("INSERT INTO checklist_template_items(checklist_template_id,section_title,title,description,question_type,options_json,is_required,sort_order) VALUES(:ct,:s,:t,:d,:q,:o,:r,:so)");
   foreach($q->fetchAll(PDO::FETCH_ASSOC) as $x)$ins->execute(array(':ct'=>$newId,':s'=>$x['section_title'],':t'=>$x['title'],':d'=>$x['description'],':q'=>$x['question_type'],':o'=>$x['options_json'],':r'=>$x['is_required'],':so'=>$x['sort_order']));
   $pdo->commit();cl_out(200,true,'Checklist duplicated successfully.');
 }

 if($action==='delete'){
   $id=(int)cl_post('id','0');if($id<=0)cl_out(422,false,'Invalid checklist.');
   $s=$pdo->prepare("UPDATE checklist_templates SET status='inactive',updated_at=NOW() WHERE id=:id AND tenant_id=:t");$s->execute(array(':id'=>$id,':t'=>$tenantId));cl_out(200,true,'Checklist deleted successfully.');
 }

 cl_out(400,false,'Invalid checklist action.');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Checklist API: '.$e->getMessage());cl_out(500,false,$e instanceof RuntimeException?$e->getMessage():'Unable to process checklist request.');}
