<?php
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function uwRes($status,$success,$message,$extra=array()){
    while(ob_get_level()>0){@ob_end_clean();}
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success'=>(bool)$success,
        'message'=>(string)$message
    ),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function uwPost($key,$default=''){
    return isset($_POST[$key])?$_POST[$key]:$default;
}

function uwTable(PDO $pdo,$table){
    static $cache=array();
    if(array_key_exists($table,$cache))return $cache[$table];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(array(':t'=>$table));
    $cache[$table]=(int)$s->fetchColumn()>0;
    return $cache[$table];
}

function uwAssignedJob(PDO $pdo,$tenantId,$userId,$jobId){
    $s=$pdo->prepare("
        SELECT
            j.*,
            c.display_name AS client_name,
            cl.name AS location_name,
            ps.name AS service_name,
            w.name AS workflow_name,
            w.version_no AS workflow_version
        FROM jobs j
        INNER JOIN clients c
            ON c.id=j.client_id
           AND c.tenant_id=j.tenant_id
        LEFT JOIN client_locations cl
            ON cl.id=j.location_id
           AND cl.tenant_id=j.tenant_id
        LEFT JOIN product_services ps
            ON ps.id=j.product_service_id
           AND ps.tenant_id=j.tenant_id
        LEFT JOIN workflows w
            ON w.id=j.workflow_id
           AND w.tenant_id=j.tenant_id
        WHERE j.id=:job_id
          AND j.tenant_id=:tenant_id
          AND j.deleted_at IS NULL
          AND EXISTS(
              SELECT 1
              FROM job_assignments ja
              LEFT JOIN team_members tm
                ON tm.team_id=ja.team_id
               AND tm.user_id=:viewer1
              WHERE ja.tenant_id=j.tenant_id
                AND ja.job_id=j.id
                AND ja.status<>'removed'
                AND (
                    ja.user_id=:viewer2
                    OR tm.user_id IS NOT NULL
                )
          )
        LIMIT 1
    ");
    $s->execute(array(
        ':job_id'=>$jobId,
        ':tenant_id'=>$tenantId,
        ':viewer1'=>$userId,
        ':viewer2'=>$userId
    ));
    $row=$s->fetch();
    return $row?$row:null;
}

function uwProgressExists(PDO $pdo,$tenantId,$jobId,$workflowId,$userId){
    $count=$pdo->prepare("
        SELECT COUNT(*)
        FROM job_workflow_progress p
        INNER JOIN workflow_steps ws
            ON ws.id=p.workflow_step_id
           AND ws.workflow_id=:workflow_id
        WHERE p.tenant_id=:tenant_id
          AND p.job_id=:job_id
          AND p.visit_id IS NULL
    ");
    $count->execute(array(
        ':workflow_id'=>$workflowId,
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId
    ));

    if((int)$count->fetchColumn()>0)return;

    $assignment=$pdo->prepare("
        SELECT ja.user_id,ja.team_id
        FROM job_assignments ja
        LEFT JOIN team_members tm
          ON tm.team_id=ja.team_id
         AND tm.user_id=:viewer
        WHERE ja.tenant_id=:tenant_id
          AND ja.job_id=:job_id
          AND ja.status<>'removed'
          AND (ja.user_id=:viewer2 OR tm.user_id IS NOT NULL)
        ORDER BY ja.is_primary_responsible DESC,ja.id
        LIMIT 1
    ");
    $assignment->execute(array(
        ':viewer'=>$userId,
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId,
        ':viewer2'=>$userId
    ));
    $a=$assignment->fetch();

    $steps=$pdo->prepare("
        SELECT id
        FROM workflow_steps
        WHERE workflow_id=:workflow_id
        ORDER BY sort_order,id
    ");
    $steps->execute(array(':workflow_id'=>$workflowId));
    $rows=$steps->fetchAll();

    $ins=$pdo->prepare("
        INSERT INTO job_workflow_progress(
            tenant_id,job_id,visit_id,workflow_step_id,
            assigned_user_id,assigned_team_id,status
        ) VALUES(
            :tenant_id,:job_id,NULL,:step_id,:user_id,:team_id,:status
        )
    ");

    foreach($rows as $i=>$step){
        $ins->execute(array(
            ':tenant_id'=>$tenantId,
            ':job_id'=>$jobId,
            ':step_id'=>$step['id'],
            ':user_id'=>!empty($a['user_id'])?(int)$a['user_id']:null,
            ':team_id'=>!empty($a['team_id'])?(int)$a['team_id']:null,
            ':status'=>$i===0?'available':'pending'
        ));
    }
}

function uwOptions(PDO $pdo,$fieldId){
    $s=$pdo->prepare("
        SELECT option_label,option_value,is_default
        FROM workflow_field_options
        WHERE workflow_field_id=:field_id
          AND status='active'
        ORDER BY sort_order,id
    ");
    $s->execute(array(':field_id'=>$fieldId));
    return $s->fetchAll();
}

function uwSaved(PDO $pdo,$tenantId,$jobId,$fieldId){
    if(!uwTable($pdo,'job_workflow_field_values'))return null;

    $s=$pdo->prepare("
        SELECT value_text,value_number,value_json,file_path
        FROM job_workflow_field_values
        WHERE tenant_id=:tenant_id
          AND job_id=:job_id
          AND workflow_field_id=:field_id
        LIMIT 1
    ");
    $s->execute(array(
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId,
        ':field_id'=>$fieldId
    ));
    $r=$s->fetch();
    if(!$r)return null;

    if($r['file_path']!==null && $r['file_path']!==''){
        return array(
            'value'=>$r['file_path'],
            'file_url'=>$r['file_path']
        );
    }

    if($r['value_json']!==null && $r['value_json']!==''){
        $decoded=json_decode($r['value_json'],true);
        return array('value'=>is_array($decoded)?$decoded:$r['value_json'],'file_url'=>null);
    }

    if($r['value_number']!==null){
        return array('value'=>$r['value_number'],'file_url'=>null);
    }

    return array('value'=>$r['value_text'],'file_url'=>null);
}

function uwLoadSteps(PDO $pdo,$tenantId,$jobId,$workflowId){
    $s=$pdo->prepare("
        SELECT
            ws.id,
            ws.step_code,
            ws.step_name,
            ws.description,
            ws.sort_order,
            ws.required,
            p.status,
            p.notes,
            p.started_at,
            p.completed_at,
            p.completed_by
        FROM workflow_steps ws
        LEFT JOIN job_workflow_progress p
          ON p.workflow_step_id=ws.id
         AND p.tenant_id=:tenant_id
         AND p.job_id=:job_id
         AND p.visit_id IS NULL
        WHERE ws.workflow_id=:workflow_id
        ORDER BY ws.sort_order,ws.id
    ");
    $s->execute(array(
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId,
        ':workflow_id'=>$workflowId
    ));
    $steps=$s->fetchAll();

    $fieldStmt=$pdo->prepare("
        SELECT *
        FROM workflow_step_fields
        WHERE tenant_id=:tenant_id
          AND workflow_step_id=:step_id
          AND status='active'
        ORDER BY sort_order,id
    ");

    foreach($steps as &$step){
        if(empty($step['status']))$step['status']='pending';

        $fieldStmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':step_id'=>$step['id']
        ));

        $fields=$fieldStmt->fetchAll();

        foreach($fields as &$field){
            $field['options']=in_array($field['field_type'],array('checklist','select','radio','checkbox'),true)
                ?uwOptions($pdo,$field['id'])
                :array();

            $saved=uwSaved($pdo,$tenantId,$jobId,$field['id']);
            $field['saved_value']=$saved?$saved['value']:$field['default_value'];
            $field['saved_file_url']=$saved?$saved['file_url']:null;
        }
        unset($field);

        $step['fields']=$fields;
    }
    unset($step);

    return $steps;
}

function uwUpload($file,$tenantId,$jobId,$fieldType,$multiple=false){
    if(!isset($file['error']))return null;

    $files=array();

    if(is_array($file['error'])){
        foreach($file['error'] as $i=>$error){
            if($error===UPLOAD_ERR_NO_FILE)continue;
            $files[]=array(
                'name'=>$file['name'][$i],
                'type'=>$file['type'][$i],
                'tmp_name'=>$file['tmp_name'][$i],
                'error'=>$error,
                'size'=>$file['size'][$i]
            );
        }
    }else{
        if($file['error']===UPLOAD_ERR_NO_FILE)return null;
        $files[]=$file;
    }

    if(!$files)return null;

    $base=__DIR__.'/../uploads/job-workflow/'.$tenantId.'/'.$jobId;
    if(!is_dir($base) && !@mkdir($base,0755,true)){
        throw new RuntimeException('Unable to create workflow upload directory.');
    }

    $urls=array();

    foreach($files as $f){
        if((int)$f['error']!==UPLOAD_ERR_OK){
            throw new RuntimeException('Unable to upload workflow file.');
        }
        if((int)$f['size']>10*1024*1024){
            throw new RuntimeException('Workflow upload must be 10 MB or smaller.');
        }

        $ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION));
        $allowed=array('jpg','jpeg','png','webp','pdf','doc','docx','xls','xlsx','txt');

        if(in_array($fieldType,array('photo_single','photo_multiple','signature'),true)){
            $allowed=array('jpg','jpeg','png','webp');
        }

        if(!in_array($ext,$allowed,true)){
            throw new RuntimeException('Unsupported workflow file type.');
        }

        $name=date('YmdHis').'_'.bin2hex(random_bytes(6)).'.'.$ext;
        $target=$base.'/'.$name;

        if(!move_uploaded_file($f['tmp_name'],$target)){
            throw new RuntimeException('Unable to save workflow file.');
        }

        $urls[]='uploads/job-workflow/'.$tenantId.'/'.$jobId.'/'.$name;
    }

    return $multiple?$urls:$urls[0];
}

function uwSaveValue(PDO $pdo,$tenantId,$jobId,$stepId,$field,$value,$filePath,$userId){
    $text=null;$number=null;$json=null;$path=null;

    if($filePath!==null){
        if(is_array($filePath)){
            $json=json_encode($filePath,JSON_UNESCAPED_SLASHES);
            $path=count($filePath)===1?$filePath[0]:null;
        }else{
            $path=$filePath;
        }
    }elseif(is_array($value)){
        $json=json_encode(array_values($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }elseif(in_array($field['field_type'],array('number','decimal'),true) && $value!==''){
        $number=(float)$value;
    }else{
        $text=$value!==''?(string)$value:null;
    }

    $s=$pdo->prepare("
        INSERT INTO job_workflow_field_values(
            tenant_id,job_id,workflow_step_id,workflow_field_id,
            value_text,value_number,value_json,file_path,
            updated_by,created_at,updated_at
        ) VALUES(
            :tenant_id,:job_id,:step_id,:field_id,
            :value_text,:value_number,:value_json,:file_path,
            :updated_by,NOW(),NOW()
        )
        ON DUPLICATE KEY UPDATE
            value_text=VALUES(value_text),
            value_number=VALUES(value_number),
            value_json=VALUES(value_json),
            file_path=VALUES(file_path),
            updated_by=VALUES(updated_by),
            updated_at=NOW()
    ");
    $s->execute(array(
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId,
        ':step_id'=>$stepId,
        ':field_id'=>$field['id'],
        ':value_text'=>$text,
        ':value_number'=>$number,
        ':value_json'=>$json,
        ':file_path'=>$path,
        ':updated_by'=>$userId
    ));
}

function uwRequiredSatisfied($field,$value,$hasExistingFile,$newFile){
    if((int)$field['is_required']!==1)return true;

    if(in_array($field['field_type'],array('photo_single','photo_multiple','file','signature'),true)){
        return $hasExistingFile || $newFile;
    }

    if(is_array($value))return count(array_filter($value,function($v){return trim((string)$v)!=='';}))>0;

    return trim((string)$value)!=='';
}

function uwLog(PDO $pdo,$tenantId,$branchId,$userId,$jobId,$title,$details){
    try{
        $s=$pdo->prepare("
            INSERT INTO activity_events(
                tenant_id,branch_id,actor_user_id,actor_type,event_type,
                related_type,related_id,title,details_json,visible_to_client
            ) VALUES(
                :tenant_id,:branch_id,:user_id,'user','job_workflow_updated',
                'job',:job_id,:title,:details,0
            )
        ");
        $s->execute(array(
            ':tenant_id'=>$tenantId,
            ':branch_id'=>$branchId>0?$branchId:null,
            ':user_id'=>$userId,
            ':job_id'=>$jobId,
            ':title'=>substr($title,0,255),
            ':details'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ));
    }catch(Throwable $e){
        error_log('Workflow activity log: '.$e->getMessage());
    }
}

$tenantId=isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;
$userId=isset($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:0;

if($tenantId<=0||$userId<=0)uwRes(401,false,'Authentication required.');

$csrf=(string)uwPost('csrf_token','');
$sessionCsrf=isset($_SESSION['my_jobs_csrf_token'])?(string)$_SESSION['my_jobs_csrf_token']:'';

if($csrf===''||$sessionCsrf===''||!hash_equals($sessionCsrf,$csrf)){
    uwRes(419,false,'Your session expired. Refresh the page and try again.');
}

if(!uwTable($pdo,'job_workflow_field_values')){
    uwRes(500,false,'Workflow response storage is missing. Run migration_job_workflow_values.sql once.');
}

$action=trim((string)uwPost('action',''));

try{
    $jobId=(int)uwPost('job_id',0);
    $job=uwAssignedJob($pdo,$tenantId,$userId,$jobId);

    if(!$job)uwRes(404,false,'This job is not assigned to you.');
    if(empty($job['workflow_id']))uwRes(409,false,'No workflow is assigned to this job.');
    if(in_array($job['status'],array('cancelled','archived'),true))uwRes(409,false,'This job workflow cannot be updated.');

    uwProgressExists($pdo,$tenantId,$jobId,(int)$job['workflow_id'],$userId);

    if($action==='load'){
        $steps=uwLoadSteps($pdo,$tenantId,$jobId,(int)$job['workflow_id']);

        uwRes(200,true,'Workflow loaded.',array(
            'job'=>$job,
            'workflow'=>array(
                'id'=>(int)$job['workflow_id'],
                'name'=>$job['workflow_name'],
                'version_no'=>$job['workflow_version']
            ),
            'steps'=>$steps
        ));
    }

    if($action==='save_step'){
        $stepId=(int)uwPost('workflow_step_id',0);
        $complete=uwPost('complete_step','')==='1';
        $notes=trim((string)uwPost('step_notes',''));

        $stepStmt=$pdo->prepare("
            SELECT
                ws.*,
                p.id AS progress_id,
                p.status AS progress_status
            FROM workflow_steps ws
            INNER JOIN job_workflow_progress p
              ON p.workflow_step_id=ws.id
             AND p.tenant_id=:tenant_id
             AND p.job_id=:job_id
             AND p.visit_id IS NULL
            WHERE ws.id=:step_id
              AND ws.workflow_id=:workflow_id
            LIMIT 1
        ");
        $stepStmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':job_id'=>$jobId,
            ':step_id'=>$stepId,
            ':workflow_id'=>$job['workflow_id']
        ));
        $step=$stepStmt->fetch();

        if(!$step)uwRes(404,false,'Workflow step not found.');
        if(!in_array($step['progress_status'],array('available','in_progress'),true)){
            uwRes(409,false,$step['progress_status']==='completed'
                ?'This workflow step is already completed.'
                :'Complete the previous workflow step first.');
        }

        $fieldStmt=$pdo->prepare("
            SELECT *
            FROM workflow_step_fields
            WHERE tenant_id=:tenant_id
              AND workflow_step_id=:step_id
              AND status='active'
            ORDER BY sort_order,id
        ");
        $fieldStmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':step_id'=>$stepId
        ));
        $fields=$fieldStmt->fetchAll();

        $pdo->beginTransaction();

        try{
            foreach($fields as $field){
                if($field['field_type']==='heading')continue;

                $name='field_'.$field['id'];
                $value=isset($_POST[$name])?$_POST[$name]:'';
                $existing=uwSaved($pdo,$tenantId,$jobId,$field['id']);
                $existingFile=$existing && !empty($existing['file_url']);
                $newFile=null;

                if(in_array($field['field_type'],array('photo_single','photo_multiple','file','signature'),true) && isset($_FILES[$name])){
                    $newFile=uwUpload(
                        $_FILES[$name],
                        $tenantId,
                        $jobId,
                        $field['field_type'],
                        $field['field_type']==='photo_multiple'
                    );
                }

                if($complete && !uwRequiredSatisfied($field,$value,$existingFile,$newFile)){
                    throw new RuntimeException(($field['label']?:$field['field_key']).' is required.');
                }

                if($newFile!==null || $value!=='' || is_array($value)){
                    uwSaveValue($pdo,$tenantId,$jobId,$stepId,$field,$value,$newFile,$userId);
                }
            }

            $progressStatus=$complete?'completed':'in_progress';

            $progress=$pdo->prepare("
                UPDATE job_workflow_progress
                SET
                    status=:status,
                    notes=:notes,
                    started_at=COALESCE(started_at,NOW()),
                    completed_at=CASE WHEN :complete_flag=1 THEN NOW() ELSE NULL END,
                    completed_by=CASE WHEN :complete_flag2=1 THEN :completed_by ELSE NULL END
                WHERE id=:id
                  AND tenant_id=:tenant_id
            ");
            $progress->execute(array(
                ':status'=>$progressStatus,
                ':notes'=>$notes!==''?$notes:null,
                ':complete_flag'=>$complete?1:0,
                ':complete_flag2'=>$complete?1:0,
                ':completed_by'=>$complete?$userId:null,
                ':id'=>$step['progress_id'],
                ':tenant_id'=>$tenantId
            ));

            if(!in_array($job['status'],array('in_progress','completed','ready_to_invoice','invoiced','closed'),true)){
                $u=$pdo->prepare("UPDATE jobs SET status='in_progress' WHERE id=:id AND tenant_id=:tenant_id");
                $u->execute(array(':id'=>$jobId,':tenant_id'=>$tenantId));
            }

            $jobCompleted=false;

            if($complete){
                $next=$pdo->prepare("
                    SELECT p.id
                    FROM job_workflow_progress p
                    INNER JOIN workflow_steps ws
                      ON ws.id=p.workflow_step_id
                     AND ws.workflow_id=:workflow_id
                    WHERE p.tenant_id=:tenant_id
                      AND p.job_id=:job_id
                      AND p.visit_id IS NULL
                      AND p.status='pending'
                    ORDER BY ws.sort_order,ws.id
                    LIMIT 1
                ");
                $next->execute(array(
                    ':workflow_id'=>$job['workflow_id'],
                    ':tenant_id'=>$tenantId,
                    ':job_id'=>$jobId
                ));
                $nextId=(int)$next->fetchColumn();

                if($nextId>0){
                    $unlock=$pdo->prepare("
                        UPDATE job_workflow_progress
                        SET status='available'
                        WHERE id=:id
                          AND tenant_id=:tenant_id
                    ");
                    $unlock->execute(array(
                        ':id'=>$nextId,
                        ':tenant_id'=>$tenantId
                    ));
                }else{
                    $remaining=$pdo->prepare("
                        SELECT COUNT(*)
                        FROM job_workflow_progress p
                        INNER JOIN workflow_steps ws
                          ON ws.id=p.workflow_step_id
                         AND ws.workflow_id=:workflow_id
                        WHERE p.tenant_id=:tenant_id
                          AND p.job_id=:job_id
                          AND p.visit_id IS NULL
                          AND p.status NOT IN('completed','skipped')
                    ");
                    $remaining->execute(array(
                        ':workflow_id'=>$job['workflow_id'],
                        ':tenant_id'=>$tenantId,
                        ':job_id'=>$jobId
                    ));

                    if((int)$remaining->fetchColumn()===0){
                        $finish=$pdo->prepare("
                            UPDATE jobs
                            SET status='completed',
                                completed_at=COALESCE(completed_at,NOW())
                            WHERE id=:id
                              AND tenant_id=:tenant_id
                        ");
                        $finish->execute(array(
                            ':id'=>$jobId,
                            ':tenant_id'=>$tenantId
                        ));
                        $jobCompleted=true;
                    }
                }
            }

            $pdo->commit();

            uwLog(
                $pdo,
                $tenantId,
                (int)$job['branch_id'],
                $userId,
                $jobId,
                $complete?'Workflow step completed':'Workflow progress saved',
                array(
                    'workflow_id'=>(int)$job['workflow_id'],
                    'workflow_step_id'=>$stepId,
                    'step_name'=>$step['step_name'],
                    'completed'=>$complete,
                    'job_completed'=>$jobCompleted
                )
            );

            if(function_exists('tenantAuditLog')){
                tenantAuditLog(
                    $pdo,
                    $complete?'WORKFLOW_STEP_COMPLETED':'WORKFLOW_PROGRESS_UPDATED',
                    $tenantId,
                    (int)$job['branch_id'],
                    $userId,
                    'job',
                    $jobId,
                    null,
                    array(
                        'workflow_id'=>(int)$job['workflow_id'],
                        'workflow_step_id'=>$stepId,
                        'completed'=>$complete,
                        'job_completed'=>$jobCompleted
                    )
                );
            }

            uwRes(200,true,
                $jobCompleted
                    ?'Final workflow step completed. Job marked Completed.'
                    :($complete?'Workflow step completed successfully.':'Work progress saved successfully.'),
                array('job_completed'=>$jobCompleted)
            );

        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    uwRes(400,false,'Unsupported workflow action.');

}catch(PDOException $e){
    error_log('FieldPlx update workflow PDO: '.$e->getMessage());
    uwRes(500,false,'Unable to update the workflow.');
}catch(Throwable $e){
    error_log('FieldPlx update workflow: '.$e->getMessage());
    uwRes(422,false,$e->getMessage());
}
