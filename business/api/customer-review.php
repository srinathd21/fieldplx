<?php
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

function crRes($status,$success,$message,$extra=array()){
    while(ob_get_level()>0){@ob_end_clean();}
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success'=>(bool)$success,
        'message'=>(string)$message
    ),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function crPost($key,$default=''){
    return isset($_POST[$key])?$_POST[$key]:$default;
}

function crTable(PDO $pdo,$table){
    static $cache=array();
    if(array_key_exists($table,$cache))return $cache[$table];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(array(':t'=>$table));
    $cache[$table]=(int)$s->fetchColumn()>0;
    return $cache[$table];
}

function crCol(PDO $pdo,$table,$column){
    static $cache=array();
    $key=$table.'.'.$column;
    if(array_key_exists($key,$cache))return $cache[$key];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(array(':t'=>$table,':c'=>$column));
    $cache[$key]=(int)$s->fetchColumn()>0;
    return $cache[$key];
}

function crCurrency(PDO $pdo,$tenantId){
    $s=$pdo->prepare("
        SELECT c.currency_code,c.symbol,c.symbol_position,c.decimal_places
        FROM tenants t
        LEFT JOIN currencies c ON c.id=t.currency_id
        WHERE t.id=:tenant_id
        LIMIT 1
    ");
    $s->execute(array(':tenant_id'=>$tenantId));
    $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r?$r:array(
        'currency_code'=>'',
        'symbol'=>'',
        'symbol_position'=>'before',
        'decimal_places'=>2
    );
}

function crRequest(PDO $pdo,$plainToken,$markOpened=true){
    $plainToken=trim((string)$plainToken);

    if($plainToken===''||!preg_match('/^[a-f0-9]{64}$/i',$plainToken)){
        crRes(404,false,'This review link is invalid.');
    }

    foreach(array('token_hash','expires_at','opened_at') as $column){
        if(!crCol($pdo,'review_requests',$column)){
            crRes(500,false,'Review request support is not installed.');
        }
    }

    $hash=hash('sha256',$plainToken);

    $s=$pdo->prepare("
        SELECT
            rr.*,
            j.job_no,
            j.title AS job_title,
            j.subtotal,
            j.tax_total,
            j.total,
            j.completed_at,
            j.status AS job_status,
            j.product_service_id,
            c.display_name AS client_name,
            ps.name AS service_name
        FROM review_requests rr
        INNER JOIN jobs j
            ON j.id=rr.job_id
           AND j.tenant_id=rr.tenant_id
        INNER JOIN clients c
            ON c.id=rr.client_id
           AND c.tenant_id=rr.tenant_id
        LEFT JOIN product_services ps
            ON ps.id=j.product_service_id
           AND ps.tenant_id=j.tenant_id
        WHERE rr.token_hash=:token_hash
        LIMIT 1
    ");
    $s->execute(array(':token_hash'=>$hash));
    $r=$s->fetch(PDO::FETCH_ASSOC);

    if(!$r){
        crRes(404,false,'This review link is invalid or no longer available.');
    }

    if(!empty($r['expires_at'])&&strtotime($r['expires_at'])<time()&&$r['status']!=='completed'){
        $u=$pdo->prepare("UPDATE review_requests SET status='expired' WHERE id=:id AND status<>'completed'");
        $u->execute(array(':id'=>$r['id']));
        crRes(410,false,'This review link has expired.');
    }

    if($markOpened&&$r['status']==='sent'){
        $u=$pdo->prepare("
            UPDATE review_requests
            SET status='opened',
                opened_at=COALESCE(opened_at,NOW())
            WHERE id=:id
              AND status='sent'
        ");
        $u->execute(array(':id'=>$r['id']));
        $r['status']='opened';
        $r['opened_at']=date('Y-m-d H:i:s');
    }

    return $r;
}

function crWorkers(PDO $pdo,$tenantId,$jobId){
    $s=$pdo->prepare("
        SELECT
            u.id,
            CONCAT(
                u.first_name,
                CASE
                    WHEN u.last_name IS NOT NULL AND u.last_name<>''
                    THEN CONCAT(' ',u.last_name)
                    ELSE ''
                END
            ) AS name,
            u.job_title,
            ja.assignment_role,
            ja.is_primary_responsible
        FROM job_assignments ja
        INNER JOIN users u
            ON u.id=ja.user_id
           AND u.tenant_id=ja.tenant_id
        WHERE ja.tenant_id=:tenant_id
          AND ja.job_id=:job_id
          AND ja.status<>'removed'
        ORDER BY ja.is_primary_responsible DESC,ja.id
    ");
    $s->execute(array(
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId
    ));
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function crRating($value,$label,$required=true){
    $value=trim((string)$value);
    if($value===''&&!$required)return null;
    $n=(int)$value;
    if($n<1||$n>5){
        crRes(422,false,$label.' must be between 1 and 5.');
    }
    return $n;
}

if(
    !crTable($pdo,'review_requests')||
    !crTable($pdo,'reviews')||
    !crTable($pdo,'worker_reviews')
){
    crRes(500,false,'Review tables are not installed.');
}

$action=trim((string)crPost('action',''));
$token=trim((string)crPost('token',''));

try{
    if($action==='load'){
        $request=crRequest($pdo,$token,true);
        $workers=crWorkers($pdo,(int)$request['tenant_id'],(int)$request['job_id']);

        $review=$pdo->prepare("
            SELECT id,service_rating,timeliness_rating,overall_rating,review_text,status,created_at
            FROM reviews
            WHERE tenant_id=:tenant_id
              AND job_id=:job_id
              AND client_id=:client_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $review->execute(array(
            ':tenant_id'=>$request['tenant_id'],
            ':job_id'=>$request['job_id'],
            ':client_id'=>$request['client_id']
        ));
        $existing=$review->fetch(PDO::FETCH_ASSOC);

        crRes(200,true,'Review details loaded.',array(
            'completed'=>$existing?1:0,
            'review'=>$existing?:null,
            'job'=>array(
                'id'=>(int)$request['job_id'],
                'job_no'=>$request['job_no'],
                'title'=>$request['job_title'],
                'service_name'=>$request['service_name'],
                'subtotal'=>$request['subtotal'],
                'tax_total'=>$request['tax_total'],
                'total'=>$request['total'],
                'completed_at'=>$request['completed_at']
            ),
            'client'=>array(
                'id'=>(int)$request['client_id'],
                'name'=>$request['client_name']
            ),
            'workers'=>$workers,
            'currency'=>crCurrency($pdo,(int)$request['tenant_id']),
            'request'=>array(
                'id'=>(int)$request['id'],
                'status'=>$request['status'],
                'expires_at'=>$request['expires_at']
            )
        ));
    }

    if($action==='save'){
        $request=crRequest($pdo,$token,false);

        if($request['status']==='completed'){
            crRes(409,false,'This review has already been submitted.');
        }

        if($request['job_status']!=='completed'){
            crRes(409,false,'This job is not completed yet.');
        }

        $existing=$pdo->prepare("
            SELECT id
            FROM reviews
            WHERE tenant_id=:tenant_id
              AND job_id=:job_id
              AND client_id=:client_id
            LIMIT 1
        ");
        $existing->execute(array(
            ':tenant_id'=>$request['tenant_id'],
            ':job_id'=>$request['job_id'],
            ':client_id'=>$request['client_id']
        ));

        if($existing->fetchColumn()){
            $pdo->prepare("
                UPDATE review_requests
                SET status='completed',
                    completed_at=COALESCE(completed_at,NOW())
                WHERE id=:id
            ")->execute(array(':id'=>$request['id']));
            crRes(409,false,'A review has already been submitted for this job.');
        }

        $serviceRating=crRating(crPost('service_rating',''),'Service rating');
        $timelinessRating=crRating(crPost('timeliness_rating',''),'Timeliness rating');
        $overallRating=crRating(crPost('overall_rating',''),'Overall rating');
        $reviewText=trim((string)crPost('review_text',''));

        $workerRatings=json_decode((string)crPost('worker_ratings_json','[]'),true);
        if(!is_array($workerRatings))$workerRatings=array();

        $assigned=crWorkers($pdo,(int)$request['tenant_id'],(int)$request['job_id']);
        $assignedMap=array();
        foreach($assigned as $worker){
            $assignedMap[(int)$worker['id']]=$worker;
        }

        $portalUserId=null;
        if(crTable($pdo,'client_portal_users')){
            $p=$pdo->prepare("
                SELECT id
                FROM client_portal_users
                WHERE tenant_id=:tenant_id
                  AND client_id=:client_id
                  AND status='active'
                ORDER BY id
                LIMIT 1
            ");
            $p->execute(array(
                ':tenant_id'=>$request['tenant_id'],
                ':client_id'=>$request['client_id']
            ));
            $pid=$p->fetchColumn();
            if($pid)$portalUserId=(int)$pid;
        }

        $pdo->beginTransaction();

        try{
            $i=$pdo->prepare("
                INSERT INTO reviews(
                    tenant_id,job_id,client_id,portal_user_id,
                    service_rating,timeliness_rating,overall_rating,
                    review_text,status
                ) VALUES(
                    :tenant_id,:job_id,:client_id,:portal_user_id,
                    :service_rating,:timeliness_rating,:overall_rating,
                    :review_text,'published'
                )
            ");
            $i->execute(array(
                ':tenant_id'=>$request['tenant_id'],
                ':job_id'=>$request['job_id'],
                ':client_id'=>$request['client_id'],
                ':portal_user_id'=>$portalUserId,
                ':service_rating'=>$serviceRating,
                ':timeliness_rating'=>$timelinessRating,
                ':overall_rating'=>$overallRating,
                ':review_text'=>$reviewText!==''?$reviewText:null
            ));

            $reviewId=(int)$pdo->lastInsertId();

            $wi=$pdo->prepare("
                INSERT INTO worker_reviews(
                    tenant_id,review_id,job_id,user_id,
                    professionalism_rating,knowledge_rating,
                    communication_rating,cleanliness_rating,
                    overall_rating,comments
                ) VALUES(
                    :tenant_id,:review_id,:job_id,:user_id,
                    NULL,NULL,NULL,NULL,
                    :overall_rating,:comments
                )
            ");

            foreach($workerRatings as $workerRating){
                if(!is_array($workerRating))continue;
                $workerId=isset($workerRating['user_id'])?(int)$workerRating['user_id']:0;

                if($workerId<=0||!isset($assignedMap[$workerId])){
                    continue;
                }

                $workerOverall=crRating(
                    isset($workerRating['overall_rating'])?$workerRating['overall_rating']:'',
                    'Service professional rating',
                    false
                );

                $comments=isset($workerRating['comments'])
                    ?trim((string)$workerRating['comments'])
                    :'';

                if($workerOverall===null&&$comments===''){
                    continue;
                }

                $wi->execute(array(
                    ':tenant_id'=>$request['tenant_id'],
                    ':review_id'=>$reviewId,
                    ':job_id'=>$request['job_id'],
                    ':user_id'=>$workerId,
                    ':overall_rating'=>$workerOverall,
                    ':comments'=>$comments!==''?$comments:null
                ));
            }

            $u=$pdo->prepare("
                UPDATE review_requests
                SET status='completed',
                    completed_at=NOW(),
                    opened_at=COALESCE(opened_at,NOW())
                WHERE id=:id
            ");
            $u->execute(array(':id'=>$request['id']));

            try{
                $log=$pdo->prepare("
                    INSERT INTO activity_events(
                        tenant_id,branch_id,actor_user_id,actor_type,event_type,
                        related_type,related_id,client_id,title,details_json,visible_to_client
                    )
                    SELECT
                        j.tenant_id,j.branch_id,NULL,'client','review_completed',
                        'job',j.id,j.client_id,:title,:details,1
                    FROM jobs j
                    WHERE j.id=:job_id
                      AND j.tenant_id=:tenant_id
                ");
                $log->execute(array(
                    ':title'=>'Customer review completed: '.$request['job_no'],
                    ':details'=>json_encode(array(
                        'review_id'=>$reviewId,
                        'service_rating'=>$serviceRating,
                        'timeliness_rating'=>$timelinessRating,
                        'overall_rating'=>$overallRating
                    ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    ':job_id'=>$request['job_id'],
                    ':tenant_id'=>$request['tenant_id']
                ));
            }catch(Throwable $logError){
                error_log('Review completion activity: '.$logError->getMessage());
            }

            $pdo->commit();

            crRes(200,true,'Thank you. Your service review has been submitted successfully.',array(
                'review_id'=>$reviewId
            ));

        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    crRes(400,false,'Unsupported review action.');

}catch(PDOException $e){
    error_log('FieldPlx customer review PDO: '.$e->getMessage());
    crRes(500,false,'Unable to process the review.');
}catch(Throwable $e){
    error_log('FieldPlx customer review: '.$e->getMessage());
    crRes(500,false,$e->getMessage()!==''?$e->getMessage():'Unable to process the review.');
}
