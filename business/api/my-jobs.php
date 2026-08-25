<?php
ob_start();
ini_set('display_errors','0');ini_set('html_errors','0');ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

function mjRes($status,$success,$message,$extra=array()){while(ob_get_level()>0){@ob_end_clean();}http_response_code((int)$status);echo json_encode(array_merge(array('success'=>(bool)$success,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function mjPost($k,$d=''){return isset($_POST[$k])?$_POST[$k]:$d;}

function mjTableExists(PDO $pdo,$table){
  try{$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t");$s->execute(array(':t'=>$table));return (int)$s->fetchColumn()>0;}catch(Throwable $e){return false;}
}
function mjAssessmentAssigned(PDO $pdo,$tenantId,$requestId,$userId){
  $s=$pdo->prepare("SELECT 1 FROM service_requests r WHERE r.id=:r AND r.tenant_id=:t AND r.status='assessment_required' AND EXISTS(SELECT 1 FROM request_assignments ra LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=:u1 WHERE ra.tenant_id=r.tenant_id AND ra.request_id=r.id AND (ra.user_id=:u2 OR tm.user_id IS NOT NULL)) LIMIT 1");
  $s->execute(array(':r'=>$requestId,':t'=>$tenantId,':u1'=>$userId,':u2'=>$userId));
  return (bool)$s->fetchColumn();
}


$tenantId=isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;
$userId=isset($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:0;
if($tenantId<=0||$userId<=0)mjRes(401,false,'Authentication required.');
$csrf=(string)mjPost('csrf_token','');$sessionCsrf=isset($_SESSION['my_jobs_csrf_token'])?(string)$_SESSION['my_jobs_csrf_token']:'';
if($csrf===''||$sessionCsrf===''||!hash_equals($sessionCsrf,$csrf))mjRes(419,false,'Your session expired. Refresh and try again.');
$action=trim((string)mjPost('action',''));

/* A service employee can see a job when they are assigned directly OR they belong to an assigned team. */
$visibility="EXISTS (SELECT 1 FROM job_assignments ja LEFT JOIN team_members tm ON tm.team_id=ja.team_id AND tm.user_id=:viewer_user_id WHERE ja.tenant_id=j.tenant_id AND ja.job_id=j.id AND ja.status<>'removed' AND (ja.user_id=:viewer_user_id2 OR tm.user_id IS NOT NULL))";

try{
if($action==='list'){
  $page=max(1,(int)mjPost('page',1));$per=(int)mjPost('per_page',10);if(!in_array($per,array(10,25,50),true))$per=10;
  $search=trim((string)mjPost('search',''));$status=trim((string)mjPost('status',''));$priority=trim((string)mjPost('priority',''));$dateFrom=trim((string)mjPost('date_from',''));$dateTo=trim((string)mjPost('date_to',''));
  if($dateFrom!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom))mjRes(422,false,'Invalid From Date.');
  if($dateTo!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo))mjRes(422,false,'Invalid To Date.');
  if($dateFrom!==''&&$dateTo!==''&&$dateFrom>$dateTo)mjRes(422,false,'From Date cannot be after To Date.');

  /* Jobs assigned directly or through a team. */
  $jobWhere=array('j.tenant_id=:jt','j.deleted_at IS NULL',"j.status<>'archived'",$visibility);
  $jobParams=array(':jt'=>$tenantId,':viewer_user_id'=>$userId,':viewer_user_id2'=>$userId);
  if($search!==''){
    $v='%'.$search.'%';
    $jobWhere[]="(j.job_no LIKE :js1 OR j.title LIKE :js2 OR c.display_name LIKE :js3 OR ps.name LIKE :js4 OR cl.name LIKE :js5)";
    $jobParams[':js1']=$v;$jobParams[':js2']=$v;$jobParams[':js3']=$v;$jobParams[':js4']=$v;$jobParams[':js5']=$v;
  }
  if(in_array($priority,array('low','normal','high','urgent'),true)){$jobWhere[]='j.priority=:jpriority';$jobParams[':jpriority']=$priority;}
  if($dateFrom!==''){$jobWhere[]='j.start_date>=:jdate_from';$jobParams[':jdate_from']=$dateFrom;}
  if($dateTo!==''){$jobWhere[]='j.start_date<=:jdate_to';$jobParams[':jdate_to']=$dateTo;}
  if($status!==''){
    $allowedStatus=array('draft','active','scheduled','upcoming','today','in_progress','waiting_customer','waiting_material','rescheduled','completed','needs_review','ready_to_invoice','invoiced','closed','cancelled');
    if(in_array($status,$allowedStatus,true)){$jobWhere[]='j.status=:jstatus';$jobParams[':jstatus']=$status;}
    elseif($status==='assessment_required'){$jobWhere[]='1=0';}
  }
  $jobWs=implode(' AND ',$jobWhere);

  $jobSql="SELECT
      'job' item_type,
      j.id,
      j.job_no item_no,
      j.title,
      j.priority,
      j.status,
      j.start_date,
      j.end_date,
      j.updated_at,
      j.created_at,
      c.display_name client_name,
      cl.name location_name,
      ps.name service_name,
      w.name workflow_name,
      CASE WHEN EXISTS(
        SELECT 1 FROM job_assignments x
        WHERE x.tenant_id=j.tenant_id AND x.job_id=j.id
          AND x.user_id=:source_user_id AND x.status<>'removed'
      ) THEN 'direct' ELSE 'team' END assignment_source,
      (SELECT GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ')
       FROM job_assignments a
       INNER JOIN teams t ON t.id=a.team_id AND t.tenant_id=a.tenant_id
       INNER JOIN team_members tm2 ON tm2.team_id=t.id AND tm2.user_id=:source_user_id2
       WHERE a.tenant_id=j.tenant_id AND a.job_id=j.id AND a.status<>'removed') assigned_team_name
    FROM jobs j
    INNER JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id
    LEFT JOIN client_locations cl ON cl.id=j.location_id AND cl.tenant_id=j.tenant_id
    LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id
    LEFT JOIN workflows w ON w.id=j.workflow_id AND w.tenant_id=j.tenant_id
    WHERE $jobWs";

  $jobParams[':source_user_id']=$userId;
  $jobParams[':source_user_id2']=$userId;
  $jq=$pdo->prepare($jobSql);$jq->execute($jobParams);$jobRows=$jq->fetchAll();

  /* Assessment-required service requests assigned directly/team/multiple.
     These are work items for the technician even though a Job row does not exist yet. */
  $rqWhere=array("r.tenant_id=:rt","r.status='assessment_required'");
  $rqParams=array(':rt'=>$tenantId);
  $rqWhere[]="EXISTS (
      SELECT 1
      FROM request_assignments ra
      LEFT JOIN team_members rtm
        ON rtm.team_id=ra.team_id
       AND rtm.user_id=:ru1
      WHERE ra.tenant_id=r.tenant_id
        AND ra.request_id=r.id
        AND (ra.user_id=:ru2 OR rtm.user_id IS NOT NULL)
  )";
  $rqParams[':ru1']=$userId;$rqParams[':ru2']=$userId;

  if($search!==''){
    $v='%'.$search.'%';
    $rqWhere[]="(r.request_no LIKE :rs1 OR r.title LIKE :rs2 OR c.display_name LIKE :rs3 OR ps.name LIKE :rs4 OR cl.name LIKE :rs5)";
    $rqParams[':rs1']=$v;$rqParams[':rs2']=$v;$rqParams[':rs3']=$v;$rqParams[':rs4']=$v;$rqParams[':rs5']=$v;
  }
  if(in_array($priority,array('low','normal','high','urgent'),true)){$rqWhere[]='r.priority=:rpriority';$rqParams[':rpriority']=$priority;}
  if($dateFrom!==''){$rqWhere[]='r.preferred_date>=:rdate_from';$rqParams[':rdate_from']=$dateFrom;}
  if($dateTo!==''){$rqWhere[]='r.preferred_date<=:rdate_to';$rqParams[':rdate_to']=$dateTo;}

  $hasRevisitHistory=mjTableExists($pdo,'assessment_reschedule_history');
  $revisitCountSql=$hasRevisitHistory
    ? "(SELECT COUNT(*) FROM assessment_reschedule_history arh WHERE arh.tenant_id=r.tenant_id AND arh.request_id=r.id)"
    : "0";

  if($status!=='' && $status!=='assessment_required' && $status!=='rescheduled'){$rqWhere[]='1=0';}
  if($status==='rescheduled'){
    if($hasRevisitHistory){
      $rqWhere[]="EXISTS (SELECT 1 FROM assessment_reschedule_history arh_filter WHERE arh_filter.tenant_id=r.tenant_id AND arh_filter.request_id=r.id)";
    }else{
      $rqWhere[]='1=0';
    }
  }

  $rqWs=implode(' AND ',$rqWhere);
  $rqSql="SELECT
      'assessment' item_type,
      r.id,
      r.request_no item_no,
      r.title,
      r.priority,
      'assessment_required' status,
      r.preferred_date start_date,
      r.preferred_date end_date,
      r.updated_at,
      r.created_at,
      c.display_name client_name,
      cl.name location_name,
      ps.name service_name,
      NULL workflow_name,
      {$revisitCountSql} revisit_count,
      CASE WHEN EXISTS(
        SELECT 1 FROM request_assignments x
        WHERE x.tenant_id=r.tenant_id AND x.request_id=r.id AND x.user_id=:rus
      ) THEN 'direct' ELSE 'team' END assignment_source,
      (SELECT GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ')
       FROM request_assignments a
       INNER JOIN teams t ON t.id=a.team_id AND t.tenant_id=a.tenant_id
       INNER JOIN team_members tm2 ON tm2.team_id=t.id AND tm2.user_id=:rus2
       WHERE a.tenant_id=r.tenant_id AND a.request_id=r.id) assigned_team_name
    FROM service_requests r
    INNER JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id
    LEFT JOIN client_locations cl ON cl.id=r.location_id AND cl.tenant_id=r.tenant_id
    LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=r.tenant_id
    WHERE $rqWs";

  $rqParams[':rus']=$userId;$rqParams[':rus2']=$userId;
  $rq=$pdo->prepare($rqSql);$rq->execute($rqParams);$assessmentRows=$rq->fetchAll();

  $rows=array_merge($jobRows,$assessmentRows);
  usort($rows,function($a,$b){
    $ad=!empty($a['start_date'])?$a['start_date']:'9999-12-31';
    $bd=!empty($b['start_date'])?$b['start_date']:'9999-12-31';
    if($ad===$bd)return (int)$b['id'] <=> (int)$a['id'];
    return strcmp($ad,$bd);
  });

  $total=count($rows);$pages=max(1,(int)ceil($total/$per));if($page>$pages)$page=$pages;
  $offset=($page-1)*$per;$pageRows=array_slice($rows,$offset,$per);

  $today=0;$progress=0;$completed=0;$assessmentCount=0;
  foreach($rows as $x){
    if($x['item_type']==='assessment')$assessmentCount++;
    if(!empty($x['start_date']) && $x['start_date']===date('Y-m-d'))$today++;
    if($x['item_type']==='job' && $x['status']==='in_progress')$progress++;
    if($x['item_type']==='job' && in_array($x['status'],array('completed','ready_to_invoice','invoiced','closed'),true))$completed++;
  }

  mjRes(200,true,'Assigned work loaded.',array(
    'jobs'=>$pageRows,
    'summary'=>array(
      'total'=>$total,
      'today'=>$today,
      'in_progress'=>$progress,
      'completed'=>$completed,
      'assessments'=>$assessmentCount
    ),
    'pagination'=>array(
      'page'=>$page,'pages'=>$pages,'total'=>$total,
      'from'=>$total?$offset+1:0,
      'to'=>$total?min($offset+count($pageRows),$total):0
    )
  ));
}
if($action==='get'){
  $jobId=(int)mjPost('job_id',0);if($jobId<=0)mjRes(422,false,'Invalid job.');
  $sql="SELECT j.*,c.display_name client_name,c.phone client_phone,cl.name location_name,cl.address_line1,cl.address_line2,cl.city,cl.state,cl.postal_code,cl.latitude,cl.longitude,ps.name service_name,w.name workflow_name,b.name branch_name,r.request_no,q.quote_no FROM jobs j INNER JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id LEFT JOIN client_locations cl ON cl.id=j.location_id AND cl.tenant_id=j.tenant_id LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id LEFT JOIN workflows w ON w.id=j.workflow_id AND w.tenant_id=j.tenant_id LEFT JOIN branches b ON b.id=j.branch_id AND b.tenant_id=j.tenant_id LEFT JOIN service_requests r ON r.id=j.request_id AND r.tenant_id=j.tenant_id LEFT JOIN quotes q ON q.id=j.quote_id AND q.tenant_id=j.tenant_id WHERE j.id=:job_id AND j.tenant_id=:tenant_id AND j.deleted_at IS NULL AND $visibility LIMIT 1";
  $s=$pdo->prepare($sql);$s->execute(array(':job_id'=>$jobId,':tenant_id'=>$tenantId,':viewer_user_id'=>$userId,':viewer_user_id2'=>$userId));$job=$s->fetch();if(!$job)mjRes(404,false,'This job is not assigned to you.');
  $a=$pdo->prepare("SELECT ja.assignment_role,ja.status assignment_status,ja.is_primary_responsible,t.name team_name FROM job_assignments ja LEFT JOIN team_members tm ON tm.team_id=ja.team_id AND tm.user_id=:uid LEFT JOIN teams t ON t.id=ja.team_id AND t.tenant_id=ja.tenant_id WHERE ja.tenant_id=:tenant_id AND ja.job_id=:job_id AND ja.status<>'removed' AND (ja.user_id=:uid2 OR tm.user_id IS NOT NULL) ORDER BY CASE WHEN ja.user_id=:uid3 THEN 0 ELSE 1 END,ja.is_primary_responsible DESC,ja.id LIMIT 1");$a->execute(array(':uid'=>$userId,':tenant_id'=>$tenantId,':job_id'=>$jobId,':uid2'=>$userId,':uid3'=>$userId));$assignment=$a->fetch();
  $label=!empty($assignment['team_name'])?'Team: '.$assignment['team_name']:'Direct Employee Assignment';
  mjRes(200,true,'Job loaded.',array('job'=>$job,'assignment'=>array('label'=>$label,'role'=>$assignment['assignment_role']??'technician','assignment_status'=>$assignment['assignment_status']??'assigned','is_primary'=>(int)($assignment['is_primary_responsible']??0))));
}

if($action==='get_assessment'){
  $requestId=(int)mjPost('request_id',0);if($requestId<=0)mjRes(422,false,'Invalid assessment request.');
  $sql="SELECT
      r.id,r.request_no job_no,r.title,r.description,r.priority,
      'assessment_required' status,
      r.preferred_date start_date,r.preferred_date end_date,
      r.preferred_time_from,r.preferred_time_to,
      'assessment' job_type,
      'primary_only' assignment_completion_mode,
      'manual' invoicing_preference,
      r.created_at,r.updated_at,
      c.display_name client_name,c.phone client_phone,
      cl.name location_name,cl.address_line1,cl.address_line2,cl.city,cl.state,cl.postal_code,cl.latitude,cl.longitude,
      ps.name service_name,
      NULL workflow_name,
      b.name branch_name,
      r.request_no,
      NULL quote_no
    FROM service_requests r
    INNER JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id
    LEFT JOIN client_locations cl ON cl.id=r.location_id AND cl.tenant_id=r.tenant_id
    LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=r.tenant_id
    LEFT JOIN branches b ON b.id=r.branch_id AND b.tenant_id=r.tenant_id
    WHERE r.id=:rid AND r.tenant_id=:t AND r.status='assessment_required'
      AND EXISTS(
        SELECT 1 FROM request_assignments ra
        LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=:u1
        WHERE ra.tenant_id=r.tenant_id AND ra.request_id=r.id
          AND (ra.user_id=:u2 OR tm.user_id IS NOT NULL)
      )
    LIMIT 1";
  $s=$pdo->prepare($sql);
  $s->execute(array(':rid'=>$requestId,':t'=>$tenantId,':u1'=>$userId,':u2'=>$userId));
  $r=$s->fetch();
  if(!$r)mjRes(404,false,'This assessment request is not assigned to you.');

  $a=$pdo->prepare("SELECT ra.assignment_mode,ra.is_primary,t.name team_name
    FROM request_assignments ra
    LEFT JOIN team_members tm ON tm.team_id=ra.team_id AND tm.user_id=:u1
    LEFT JOIN teams t ON t.id=ra.team_id AND t.tenant_id=ra.tenant_id
    WHERE ra.tenant_id=:t AND ra.request_id=:r
      AND (ra.user_id=:u2 OR tm.user_id IS NOT NULL)
    ORDER BY ra.is_primary DESC,ra.id LIMIT 1");
  $a->execute(array(':u1'=>$userId,':t'=>$tenantId,':r'=>$requestId,':u2'=>$userId));
  $ar=$a->fetch();
  $label=!empty($ar['team_name'])?'Team: '.$ar['team_name']:'Direct Employee Assignment';

  $history=array();
  if(mjTableExists($pdo,'assessment_reschedule_history')){
    $hs=$pdo->prepare("SELECT h.*,COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.email,CONCAT('User #',h.rescheduled_by)) rescheduled_by_name FROM assessment_reschedule_history h LEFT JOIN users u ON u.id=h.rescheduled_by AND u.tenant_id=h.tenant_id WHERE h.tenant_id=:t AND h.request_id=:r ORDER BY h.id DESC");
    $hs->execute(array(':t'=>$tenantId,':r'=>$requestId));
    $history=$hs->fetchAll();
  }

  mjRes(200,true,'Assessment request loaded.',array(
    'request'=>$r,
    'assignment'=>array(
      'label'=>$label,
      'role'=>'inspector',
      'assignment_status'=>'assigned',
      'is_primary'=>(int)($ar['is_primary']??0)
    ),
    'reschedule_history'=>$history
  ));
}

if($action==='reschedule_assessment'){
  $requestId=(int)mjPost('request_id',0);
  $newDate=trim((string)mjPost('new_date',''));
  $newFrom=trim((string)mjPost('new_time_from',''));
  $newTo=trim((string)mjPost('new_time_to',''));
  $remarks=trim((string)mjPost('remarks',''));
  if($requestId<=0)mjRes(422,false,'Invalid assessment request.');
  if(!mjAssessmentAssigned($pdo,$tenantId,$requestId,$userId))mjRes(403,false,'This assessment request is not assigned to you.');
  if(!mjTableExists($pdo,'assessment_reschedule_history'))mjRes(500,false,'Assessment reschedule history table is missing. Run migration_assessment_reschedule_history.sql once.');
  if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$newDate))mjRes(422,false,'Select a valid new visit date.');
  if($newDate<date('Y-m-d'))mjRes(422,false,'The rescheduled visit date cannot be in the past.');
  if($remarks==='' || mb_strlen($remarks)>2000)mjRes(422,false,'Enter the assessment / reschedule remarks (maximum 2000 characters).');
  if($newFrom!=='' && !preg_match('/^\\d{2}:\\d{2}(:\\d{2})?$/',$newFrom))mjRes(422,false,'Invalid From Time.');
  if($newTo!=='' && !preg_match('/^\\d{2}:\\d{2}(:\\d{2})?$/',$newTo))mjRes(422,false,'Invalid To Time.');
  if($newFrom!=='' && $newTo!=='' && substr($newTo,0,5)<=substr($newFrom,0,5))mjRes(422,false,'To Time must be after From Time.');

  $pdo->beginTransaction();
  try{
    $ls=$pdo->prepare("SELECT preferred_date,preferred_time_from,preferred_time_to,branch_id,title,request_no FROM service_requests WHERE id=:r AND tenant_id=:t AND status='assessment_required' FOR UPDATE");
    $ls->execute(array(':r'=>$requestId,':t'=>$tenantId));
    $old=$ls->fetch();
    if(!$old)throw new RuntimeException('Assessment request is no longer available for rescheduling.');
    $ins=$pdo->prepare("INSERT INTO assessment_reschedule_history (tenant_id,request_id,old_preferred_date,old_time_from,old_time_to,new_preferred_date,new_time_from,new_time_to,remarks,rescheduled_by,created_at) VALUES (:t,:r,:od,:of,:ot,:nd,:nf,:nt,:rm,:u,NOW())");
    $ins->execute(array(':t'=>$tenantId,':r'=>$requestId,':od'=>$old['preferred_date'],':of'=>$old['preferred_time_from'],':ot'=>$old['preferred_time_to'],':nd'=>$newDate,':nf'=>$newFrom!==''?$newFrom:null,':nt'=>$newTo!==''?$newTo:null,':rm'=>$remarks,':u'=>$userId));
    $up=$pdo->prepare("UPDATE service_requests SET preferred_date=:d,preferred_time_from=:f,preferred_time_to=:to,updated_at=NOW() WHERE id=:r AND tenant_id=:t");
    $up->execute(array(':d'=>$newDate,':f'=>$newFrom!==''?$newFrom:null,':to'=>$newTo!==''?$newTo:null,':r'=>$requestId,':t'=>$tenantId));
    if(mjTableExists($pdo,'assessments')){
      $start=$newDate.' '.($newFrom!==''?$newFrom.':00':'00:00:00');
      $end=$newDate.' '.($newTo!==''?$newTo.':00':($newFrom!==''?$newFrom.':00':'23:59:59'));
      $au=$pdo->prepare("UPDATE assessments SET scheduled_start=:s,scheduled_end=:e,status=CASE WHEN status='draft' THEN 'scheduled' ELSE status END,updated_at=NOW() WHERE tenant_id=:t AND request_id=:r AND status NOT IN ('completed','cancelled','converted')");
      $au->execute(array(':s'=>$start,':e'=>$end,':t'=>$tenantId,':r'=>$requestId));
    }
    if(mjTableExists($pdo,'activity_events')){
      try{$ev=$pdo->prepare("INSERT INTO activity_events (tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,title,details_json,visible_to_client,created_at) VALUES (:t,:b,:u,'user','assessment.rescheduled','service_request',:r,:title,:details,0,NOW())");$ev->execute(array(':t'=>$tenantId,':b'=>$old['branch_id'],':u'=>$userId,':r'=>$requestId,':title'=>'Assessment visit rescheduled',':details'=>json_encode(array('request_no'=>$old['request_no'],'old_date'=>$old['preferred_date'],'new_date'=>$newDate,'old_time_from'=>$old['preferred_time_from'],'old_time_to'=>$old['preferred_time_to'],'new_time_from'=>$newFrom,'new_time_to'=>$newTo,'remarks'=>$remarks))));}catch(Throwable $ignore){}
    }
    $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  mjRes(200,true,'Assessment visit rescheduled successfully. Remarks saved in history.');
}

mjRes(400,false,'Unsupported My Jobs action.');
}catch(PDOException $e){error_log('FieldPlx my-jobs PDO: '.$e->getMessage());mjRes(500,false,'Unable to load assigned jobs.');}catch(Throwable $e){error_log('FieldPlx my-jobs: '.$e->getMessage());mjRes(500,false,'Unable to load assigned jobs.');}