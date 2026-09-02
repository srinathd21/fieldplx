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


function uwCol(PDO $pdo,$table,$column){
    static $cache=array();
    $key=$table.'.'.$column;
    if(array_key_exists($key,$cache))return $cache[$key];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(array(':t'=>$table,':c'=>$column));
    $cache[$key]=(int)$s->fetchColumn()>0;
    return $cache[$key];
}

function uwCurrency(PDO $pdo,$tenantId){
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

function uwMoney($amount,$currency){
    $places=isset($currency['decimal_places'])?(int)$currency['decimal_places']:2;
    $value=number_format((float)$amount,$places,'.',',');
    $symbol=isset($currency['symbol'])?(string)$currency['symbol']:'';
    return isset($currency['symbol_position']) && $currency['symbol_position']==='after'
        ? $value.($symbol!==''?' '.$symbol:'')
        : $symbol.$value;
}

function uwSmtpSecretKey(){
    if(!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')){
        $secretFile=__DIR__.'/../includes/smtp-secret.php';
        if(is_file($secretFile)){
            require_once $secretFile;
        }
    }

    $key=defined('FIELDPLX_SMTP_ENCRYPTION_KEY')
        ?trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY)
        :'';

    if($key===''){
        $env=getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if($env!==false)$key=trim((string)$env);
    }

    if($key===''){
        $env=getenv('APP_KEY');
        if($env!==false)$key=trim((string)$env);
    }

    if($key===''||strlen($key)<32){
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.');
    }

    return hash('sha256',$key,true);
}

function uwDecryptSmtpPassword($encrypted){
    $encrypted=trim((string)$encrypted);
    if($encrypted==='')return '';

    if(strpos($encrypted,'v1:')!==0){
        throw new RuntimeException('SMTP password uses the old encryption format. Re-enter and save the SMTP password once in Master Controls.');
    }

    $raw=base64_decode(substr($encrypted,3),true);
    if($raw===false||strlen($raw)<=16){
        throw new RuntimeException('Stored SMTP password is invalid.');
    }

    $plain=openssl_decrypt(
        substr($raw,16),
        'AES-256-CBC',
        uwSmtpSecretKey(),
        OPENSSL_RAW_DATA,
        substr($raw,0,16)
    );

    if($plain===false){
        throw new RuntimeException('Unable to decrypt SMTP password. Confirm the same permanent SMTP encryption key is used on this server.');
    }

    return $plain;
}

function uwSmtpRead($socket){
    $response='';
    while(!feof($socket)){
        $line=fgets($socket,515);
        if($line===false)break;
        $response.=$line;
        if(strlen($line)>=4&&$line[3]===' ')break;
    }
    return trim($response);
}

function uwSmtpCmd($socket,$command,$validCodes,$label){
    if($command!==null&&@fwrite($socket,$command."\r\n")===false){
        throw new RuntimeException('SMTP connection closed during '.$label.'.');
    }

    $response=uwSmtpRead($socket);
    $code=(int)substr($response,0,3);

    if(!in_array($code,(array)$validCodes,true)){
        throw new RuntimeException(
            $label.' failed (SMTP '.$code.'): '.
            substr(preg_replace('/[\r\n]+/',' ',$response),0,220)
        );
    }

    return $response;
}

function uwSmtpConfig(PDO $pdo,$tenantId,$branchId){
    if(!uwTable($pdo,'smtp_configurations'))return null;

    $s=$pdo->prepare("
        SELECT *
        FROM smtp_configurations
        WHERE tenant_id=:tenant_id
          AND is_active=1
          AND scope_type IN('tenant','branch')
          AND (
              scope_type='tenant'
              OR (scope_type='branch' AND branch_id=:branch_id)
          )
        ORDER BY
          CASE WHEN scope_type='branch' AND branch_id=:branch_id2 THEN 0 ELSE 1 END,
          is_default DESC,
          id DESC
        LIMIT 1
    ");

    $branch=$branchId>0?$branchId:-1;
    $s->execute(array(
        ':tenant_id'=>$tenantId,
        ':branch_id'=>$branch,
        ':branch_id2'=>$branch
    ));

    $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r?$r:null;
}

function uwSendMail($config,$password,$to,$subject,$html){
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)){
        throw new RuntimeException('Customer email address is invalid.');
    }

    $host=trim((string)$config['host']);
    $port=(int)$config['port'];
    $encryption=strtolower(trim((string)$config['encryption']));
    $username=trim((string)$config['username']);
    $from=trim((string)$config['from_email']);
    $fromName=trim((string)$config['from_name']);
    $replyTo=isset($config['reply_to_email'])?trim((string)$config['reply_to_email']):'';

    if(!filter_var($from,FILTER_VALIDATE_EMAIL)){
        throw new RuntimeException('SMTP From Email is invalid.');
    }

    $remote=($encryption==='ssl'?'ssl://':'tcp://').$host.':'.$port;
    $context=stream_context_create(array(
        'ssl'=>array(
            'verify_peer'=>true,
            'verify_peer_name'=>true,
            'allow_self_signed'=>false,
            'peer_name'=>$host
        )
    ));

    $errno=0;
    $error='';
    $socket=@stream_socket_client(
        $remote,
        $errno,
        $error,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if(!$socket){
        throw new RuntimeException(
            'Unable to connect to SMTP server: '.
            ($error!==''?$error:'connection failed')
        );
    }

    stream_set_timeout($socket,20);

    try{
        uwSmtpCmd($socket,null,array(220),'SMTP greeting');

        $ehlo=!empty($_SERVER['SERVER_NAME'])
            ?preg_replace('/[^A-Za-z0-9.\-]/','',$_SERVER['SERVER_NAME'])
            :'fieldplx.local';
        if($ehlo==='')$ehlo='fieldplx.local';

        uwSmtpCmd($socket,'EHLO '.$ehlo,array(250),'EHLO');

        if($encryption==='tls'||$encryption==='starttls'){
            uwSmtpCmd($socket,'STARTTLS',array(220),'STARTTLS');
            $method=defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ?STREAM_CRYPTO_METHOD_TLS_CLIENT
                :STREAM_CRYPTO_METHOD_SSLv23_CLIENT;

            if(@stream_socket_enable_crypto($socket,true,$method)!==true){
                throw new RuntimeException('Unable to establish TLS encryption.');
            }

            uwSmtpCmd($socket,'EHLO '.$ehlo,array(250),'EHLO after TLS');
        }

        if($username!==''){
            uwSmtpCmd($socket,'AUTH LOGIN',array(334),'SMTP authentication');
            uwSmtpCmd($socket,base64_encode($username),array(334),'SMTP username');
            uwSmtpCmd($socket,base64_encode($password),array(235),'SMTP password');
        }

        uwSmtpCmd($socket,'MAIL FROM:<'.$from.'>',array(250),'MAIL FROM');
        uwSmtpCmd($socket,'RCPT TO:<'.$to.'>',array(250,251),'RCPT TO');
        uwSmtpCmd($socket,'DATA',array(354),'DATA');

        $safeSubject=str_replace(array("\r","\n"),' ',$subject);
        $safeFromName=str_replace(array("\r","\n"),' ',$fromName!==''?$fromName:'FieldPlx');

        $headers=array(
            'Date: '.date(DATE_RFC2822),
            'From: '.$safeFromName.' <'.$from.'>',
            'To: <'.$to.'>',
            'Subject: '.$safeSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        );

        if($replyTo!==''&&filter_var($replyTo,FILTER_VALIDATE_EMAIL)){
            $headers[]='Reply-To: <'.$replyTo.'>';
        }

        $payload=implode("\r\n",$headers)."\r\n\r\n".$html;
        $payload=preg_replace('/(?m)^\./','..',$payload);

        @fwrite($socket,$payload."\r\n.\r\n");
        uwSmtpCmd($socket,null,array(250),'Message delivery');
        @fwrite($socket,"QUIT\r\n");
    }finally{
        @fclose($socket);
    }

    return true;
}

function uwReviewUrl($plainToken){
    $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off');
    $scheme=$https?'https':'http';
    $host=!empty($_SERVER['HTTP_HOST'])?(string)$_SERVER['HTTP_HOST']:'';
    $businessPath=rtrim(dirname(dirname((string)$_SERVER['SCRIPT_NAME'])),'/\\');

    return $scheme.'://'.$host.$businessPath.'/customer-review?token='.rawurlencode($plainToken);
}

function uwAssignedWorkers(PDO $pdo,$tenantId,$jobId){
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

function uwSendReviewRequest(PDO $pdo,$tenantId,$jobId,$completedByUserId){
    $result=array(
        'status'=>'skipped',
        'email_sent'=>0,
        'review_request_id'=>0,
        'message'=>'Review email was not sent.'
    );

    if(
        !uwTable($pdo,'review_requests')||
        !uwTable($pdo,'reviews')||
        !uwTable($pdo,'worker_reviews')
    ){
        $result['message']='Review tables are not installed.';
        return $result;
    }

    foreach(array('token_hash','expires_at','opened_at') as $column){
        if(!uwCol($pdo,'review_requests',$column)){
            $result['message']='Review request token columns are not installed. Run migration_job_completion_reviews.sql once.';
            return $result;
        }
    }

    $s=$pdo->prepare("
        SELECT
            j.id,
            j.tenant_id,
            j.branch_id,
            j.job_no,
            j.title,
            j.subtotal,
            j.tax_total,
            j.total,
            j.completed_at,
            j.client_id,
            ps.name AS service_name,
            c.display_name AS client_name,
            c.email AS client_email,
            c.allow_email
        FROM jobs j
        INNER JOIN clients c
            ON c.id=j.client_id
           AND c.tenant_id=j.tenant_id
        LEFT JOIN product_services ps
            ON ps.id=j.product_service_id
           AND ps.tenant_id=j.tenant_id
        WHERE j.id=:job_id
          AND j.tenant_id=:tenant_id
          AND j.deleted_at IS NULL
          AND j.status='completed'
        LIMIT 1
    ");
    $s->execute(array(
        ':job_id'=>$jobId,
        ':tenant_id'=>$tenantId
    ));
    $job=$s->fetch(PDO::FETCH_ASSOC);

    if(!$job){
        $result['message']='Completed job could not be loaded for review notification.';
        return $result;
    }

    $existingReview=$pdo->prepare("
        SELECT id
        FROM reviews
        WHERE tenant_id=:tenant_id
          AND job_id=:job_id
          AND client_id=:client_id
        ORDER BY id DESC
        LIMIT 1
    ");
    $existingReview->execute(array(
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId,
        ':client_id'=>$job['client_id']
    ));

    if($existingReview->fetchColumn()){
        $result['status']='completed';
        $result['message']='Customer review has already been completed.';
        return $result;
    }

    $existingRequest=$pdo->prepare("
        SELECT *
        FROM review_requests
        WHERE tenant_id=:tenant_id
          AND job_id=:job_id
          AND client_id=:client_id
          AND status IN('sent','opened','completed')
        ORDER BY id DESC
        LIMIT 1
    ");
    $existingRequest->execute(array(
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId,
        ':client_id'=>$job['client_id']
    ));
    $alreadySent=$existingRequest->fetch(PDO::FETCH_ASSOC);

    if($alreadySent){
        $result['status']=$alreadySent['status'];
        $result['review_request_id']=(int)$alreadySent['id'];
        $result['message']='Review request has already been sent to this customer.';
        return $result;
    }

    $email=trim((string)$job['client_email']);
    if((int)$job['allow_email']!==1||$email===''||!filter_var($email,FILTER_VALIDATE_EMAIL)){
        $result['message']='Customer review email skipped because customer email notification is disabled or the email address is unavailable.';
        return $result;
    }

    $workers=uwAssignedWorkers($pdo,$tenantId,$jobId);
    $workerNames=array();
    foreach($workers as $worker){
        $workerNames[]=trim((string)$worker['name']);
    }

    $plainToken=bin2hex(random_bytes(32));
    $tokenHash=hash('sha256',$plainToken);
    $expiresAt=date('Y-m-d H:i:s',strtotime('+30 days'));

    $queued=$pdo->prepare("
        SELECT id
        FROM review_requests
        WHERE tenant_id=:tenant_id
          AND job_id=:job_id
          AND client_id=:client_id
          AND status='queued'
        ORDER BY id DESC
        LIMIT 1
    ");
    $queued->execute(array(
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId,
        ':client_id'=>$job['client_id']
    ));
    $requestId=(int)$queued->fetchColumn();

    if($requestId>0){
        $u=$pdo->prepare("
            UPDATE review_requests
            SET channel='email',
                token_hash=:token_hash,
                expires_at=:expires_at,
                opened_at=NULL,
                sent_at=NULL
            WHERE id=:id
              AND tenant_id=:tenant_id
        ");
        $u->execute(array(
            ':token_hash'=>$tokenHash,
            ':expires_at'=>$expiresAt,
            ':id'=>$requestId,
            ':tenant_id'=>$tenantId
        ));
    }else{
        $i=$pdo->prepare("
            INSERT INTO review_requests(
                tenant_id,job_id,client_id,token_hash,expires_at,
                channel,status,sent_at,opened_at,completed_at
            ) VALUES(
                :tenant_id,:job_id,:client_id,:token_hash,:expires_at,
                'email','queued',NULL,NULL,NULL
            )
        ");
        $i->execute(array(
            ':tenant_id'=>$tenantId,
            ':job_id'=>$jobId,
            ':client_id'=>$job['client_id'],
            ':token_hash'=>$tokenHash,
            ':expires_at'=>$expiresAt
        ));
        $requestId=(int)$pdo->lastInsertId();
    }

    $result['review_request_id']=$requestId;

    $smtp=uwSmtpConfig($pdo,$tenantId,(int)$job['branch_id']);
    if(!$smtp){
        $result['message']='Job completed, but review email was not sent because no active SMTP configuration was found.';
        return $result;
    }

    try{
        $password=uwDecryptSmtpPassword($smtp['password_encrypted']);
        $currency=uwCurrency($pdo,$tenantId);
        $reviewUrl=uwReviewUrl($plainToken);
        $completedAt=!empty($job['completed_at'])
            ?date('d M Y, h:i A',strtotime($job['completed_at']))
            :date('d M Y, h:i A');

        $workersHtml='';
        if($workerNames){
            foreach($workerNames as $workerName){
                $workersHtml.='<div style="padding:7px 0;border-bottom:1px solid #edf0f3">'
                    .htmlspecialchars($workerName,ENT_QUOTES,'UTF-8')
                    .'</div>';
            }
        }else{
            $workersHtml='<div style="color:#6f7b90">Assigned service team</div>';
        }

        $html='<div style="font-family:Arial,Helvetica,sans-serif;max-width:680px;margin:auto;color:#0b1933;background:#f6f8fb;padding:24px">'
            .'<div style="background:#001131;color:#fff;padding:22px 24px;border-radius:12px 12px 0 0">'
                .'<div style="font-size:12px;color:#9fda55;font-weight:700;text-transform:uppercase;letter-spacing:.6px">FieldPlx Service Complete</div>'
                .'<h2 style="margin:7px 0 0;font-size:22px">How was your service?</h2>'
            .'</div>'
            .'<div style="background:#fff;border:1px solid #e5eaf1;border-top:0;padding:24px;border-radius:0 0 12px 12px">'
                .'<p style="margin-top:0">Hello '.htmlspecialchars($job['client_name'],ENT_QUOTES,'UTF-8').',</p>'
                .'<p>Your job <strong>'.htmlspecialchars($job['job_no'],ENT_QUOTES,'UTF-8').'</strong> has been completed. Please review the service and the service professional(s) who attended your job.</p>'

                .'<div style="margin:20px 0;border:1px solid #e5eaf1;border-radius:10px;overflow:hidden">'
                    .'<div style="padding:14px 16px;background:#f8fafc;font-weight:700">Job Card Details</div>'
                    .'<div style="padding:14px 16px">'
                        .'<div style="margin-bottom:8px"><strong>Job:</strong> '.htmlspecialchars($job['title'],ENT_QUOTES,'UTF-8').'</div>'
                        .'<div style="margin-bottom:8px"><strong>Service:</strong> '.htmlspecialchars($job['service_name']?:'Service Job',ENT_QUOTES,'UTF-8').'</div>'
                        .'<div><strong>Completed:</strong> '.htmlspecialchars($completedAt,ENT_QUOTES,'UTF-8').'</div>'
                    .'</div>'
                .'</div>'

                .'<div style="margin:20px 0;border:1px solid #e5eaf1;border-radius:10px;overflow:hidden">'
                    .'<div style="padding:14px 16px;background:#f8fafc;font-weight:700">Service Professional(s)</div>'
                    .'<div style="padding:8px 16px">'.$workersHtml.'</div>'
                .'</div>'

                .'<div style="margin:20px 0;border:1px solid #e5eaf1;border-radius:10px;overflow:hidden">'
                    .'<div style="padding:14px 16px;background:#f8fafc;font-weight:700">Job Amount</div>'
                    .'<table style="width:100%;border-collapse:collapse;font-size:14px">'
                        .'<tr><td style="padding:9px 16px;color:#6f7b90">Subtotal</td><td style="padding:9px 16px;text-align:right">'.htmlspecialchars(uwMoney($job['subtotal'],$currency),ENT_QUOTES,'UTF-8').'</td></tr>'
                        .'<tr><td style="padding:9px 16px;color:#6f7b90">Tax</td><td style="padding:9px 16px;text-align:right">'.htmlspecialchars(uwMoney($job['tax_total'],$currency),ENT_QUOTES,'UTF-8').'</td></tr>'
                        .'<tr><td style="padding:12px 16px;border-top:1px solid #e5eaf1;font-weight:700">Total</td><td style="padding:12px 16px;border-top:1px solid #e5eaf1;text-align:right;font-size:17px;font-weight:700;color:#5d971b">'.htmlspecialchars(uwMoney($job['total'],$currency),ENT_QUOTES,'UTF-8').'</td></tr>'
                    .'</table>'
                .'</div>'

                .'<div style="text-align:center;margin:26px 0">'
                    .'<a href="'.htmlspecialchars($reviewUrl,ENT_QUOTES,'UTF-8').'" style="display:inline-block;padding:13px 24px;border-radius:9px;background:#74b824;color:#fff;text-decoration:none;font-weight:700">Review Your Service</a>'
                .'</div>'
                .'<p style="font-size:12px;color:#6f7b90">This secure review link expires in 30 days.</p>'
            .'</div>'
        .'</div>';

        uwSendMail(
            $smtp,
            $password,
            $email,
            'Please review your completed service - '.$job['job_no'],
            $html
        );

        $u=$pdo->prepare("
            UPDATE review_requests
            SET status='sent',
                sent_at=NOW()
            WHERE id=:id
              AND tenant_id=:tenant_id
        ");
        $u->execute(array(
            ':id'=>$requestId,
            ':tenant_id'=>$tenantId
        ));

        try{
            $log=$pdo->prepare("
                INSERT INTO activity_events(
                    tenant_id,branch_id,actor_user_id,actor_type,event_type,
                    related_type,related_id,client_id,title,details_json,visible_to_client
                ) VALUES(
                    :tenant_id,:branch_id,:user_id,'user','review_requested',
                    'job',:job_id,:client_id,:title,:details,1
                )
            ");
            $log->execute(array(
                ':tenant_id'=>$tenantId,
                ':branch_id'=>(int)$job['branch_id']>0?(int)$job['branch_id']:null,
                ':user_id'=>$completedByUserId,
                ':job_id'=>$jobId,
                ':client_id'=>$job['client_id'],
                ':title'=>'Review requested: '.$job['job_no'],
                ':details'=>json_encode(array(
                    'review_request_id'=>$requestId,
                    'email'=>$email,
                    'expires_at'=>$expiresAt,
                    'workers'=>$workerNames,
                    'subtotal'=>$job['subtotal'],
                    'tax_total'=>$job['tax_total'],
                    'total'=>$job['total']
                ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ));
        }catch(Throwable $logError){
            error_log('Review request activity: '.$logError->getMessage());
        }

        $result['status']='sent';
        $result['email_sent']=1;
        $result['message']='Review email sent to '.$email.'.';
        return $result;

    }catch(Throwable $mailError){
        error_log('Job completion review email: '.$mailError->getMessage());
        $result['status']='failed';
        $result['message']='Job completed, but review email failed: '.$mailError->getMessage();
        return $result;
    }
}



function uwNextDocumentNumber(PDO $pdo,$tenantId,$branchId,$documentType){
    $separatorColumn=uwCol($pdo,'document_sequences','number_separator')?'number_separator':'separator';

    $s=$pdo->prepare("
        SELECT ds.*,b.branch_code
        FROM document_sequences ds
        LEFT JOIN branches b
          ON b.id=ds.branch_id
         AND b.tenant_id=ds.tenant_id
        WHERE ds.tenant_id=:tenant_id
          AND ds.document_type=:document_type
          AND ds.is_active=1
          AND (ds.branch_id=:branch_id OR ds.branch_id IS NULL)
        ORDER BY
          CASE WHEN ds.branch_id=:branch_id2 THEN 0 ELSE 1 END,
          ds.id
        LIMIT 1
        FOR UPDATE
    ");

    $branch=$branchId>0?$branchId:0;
    $s->execute(array(
        ':tenant_id'=>$tenantId,
        ':document_type'=>$documentType,
        ':branch_id'=>$branch,
        ':branch_id2'=>$branch
    ));

    $row=$s->fetch(PDO::FETCH_ASSOC);

    if(!$row){
        throw new RuntimeException(ucfirst($documentType).' number format is not configured for this tenant. Configure it in Master Control first.');
    }

    $now=new DateTime('now');
    $year=$now->format('Y');
    $month=$now->format('m');
    $fyStart=max(1,min(12,(int)$row['financial_year_start_month']));
    $fyYear=(int)$now->format('n')>=$fyStart?(int)$year:(int)$year-1;
    $financialYear=$fyYear.'-'.substr((string)($fyYear+1),-2);

    $resetKey='never';
    if($row['reset_period']==='monthly')$resetKey=$year.$month;
    elseif($row['reset_period']==='yearly')$resetKey=$year;
    elseif($row['reset_period']==='financial_year')$resetKey=$financialYear;

    $current=(int)$row['current_number'];
    if($row['reset_period']!=='never'&&(string)$row['last_reset_key']!==(string)$resetKey){
        $current=0;
    }

    $next=$current+1;
    $middle='';

    if($row['middle_format']==='year')$middle=$year;
    elseif($row['middle_format']==='year_month')$middle=$year.$month;
    elseif($row['middle_format']==='financial_year')$middle=$financialYear;
    elseif($row['middle_format']==='branch_year'){
        $middle=(!empty($row['branch_code'])?$row['branch_code']:'BR').$year;
    }

    $parts=array();
    if(!empty($row['prefix']))$parts[]=$row['prefix'];
    if($middle!=='')$parts[]=$middle;
    $parts[]=str_pad((string)$next,max(1,(int)$row['number_length']),'0',STR_PAD_LEFT);
    if(!empty($row['suffix']))$parts[]=$row['suffix'];

    $separator=isset($row[$separatorColumn])?(string)$row[$separatorColumn]:'-';
    $number=implode($separator,$parts);

    $u=$pdo->prepare("
        UPDATE document_sequences
        SET current_number=:current_number,
            last_reset_key=:reset_key
        WHERE id=:id
    ");
    $u->execute(array(
        ':current_number'=>$next,
        ':reset_key'=>$resetKey,
        ':id'=>$row['id']
    ));

    return $number;
}

function uwEnsureCompletedJobInvoice(PDO $pdo,$tenantId,$jobId,$createdByUserId){
    if(!uwTable($pdo,'invoices')||!uwTable($pdo,'invoice_line_items')){
        throw new RuntimeException('Invoice tables are not installed.');
    }

    $existing=$pdo->prepare("
        SELECT id,invoice_no,status,total,amount_paid,balance_due
        FROM invoices
        WHERE tenant_id=:tenant_id
          AND job_id=:job_id
        ORDER BY id
        LIMIT 1
        FOR UPDATE
    ");
    $existing->execute(array(
        ':tenant_id'=>$tenantId,
        ':job_id'=>$jobId
    ));
    $invoice=$existing->fetch(PDO::FETCH_ASSOC);

    if($invoice){
        $invoice['generated_now']=0;
        return $invoice;
    }

    $jobStmt=$pdo->prepare("
        SELECT
            j.*,
            q.discount_total AS quote_discount_total
        FROM jobs j
        LEFT JOIN quotes q
          ON q.id=j.quote_id
         AND q.tenant_id=j.tenant_id
        WHERE j.id=:job_id
          AND j.tenant_id=:tenant_id
          AND j.deleted_at IS NULL
        LIMIT 1
        FOR UPDATE
    ");
    $jobStmt->execute(array(
        ':job_id'=>$jobId,
        ':tenant_id'=>$tenantId
    ));
    $job=$jobStmt->fetch(PDO::FETCH_ASSOC);

    if(!$job){
        throw new RuntimeException('Completed job could not be loaded for invoice generation.');
    }

    if($job['status']!=='completed'){
        throw new RuntimeException('Invoice can only be generated after the job is completed.');
    }

    $branchId=!empty($job['branch_id'])?(int)$job['branch_id']:0;
    $invoiceNo=uwNextDocumentNumber($pdo,$tenantId,$branchId,'invoice');
    $issueDate=!empty($job['completed_at'])?date('Y-m-d',strtotime($job['completed_at'])):date('Y-m-d');
    $total=(float)$job['total'];
    $discount=!empty($job['quote_discount_total'])?(float)$job['quote_discount_total']:0.0;

    $insert=$pdo->prepare("
        INSERT INTO invoices(
            tenant_id,branch_id,invoice_no,client_id,location_id,
            job_id,visit_id,quote_id,status,issue_date,due_date,
            subtotal,discount_total,tax_total,total,amount_paid,balance_due,
            payment_terms,notes,created_by
        ) VALUES(
            :tenant_id,:branch_id,:invoice_no,:client_id,:location_id,
            :job_id,NULL,:quote_id,'draft',:issue_date,:due_date,
            :subtotal,:discount_total,:tax_total,:total,0,:balance_due,
            :payment_terms,:notes,:created_by
        )
    ");

    $insert->execute(array(
        ':tenant_id'=>$tenantId,
        ':branch_id'=>$branchId>0?$branchId:null,
        ':invoice_no'=>$invoiceNo,
        ':client_id'=>$job['client_id'],
        ':location_id'=>!empty($job['location_id'])?(int)$job['location_id']:null,
        ':job_id'=>$jobId,
        ':quote_id'=>!empty($job['quote_id'])?(int)$job['quote_id']:null,
        ':issue_date'=>$issueDate,
        ':due_date'=>$issueDate,
        ':subtotal'=>(float)$job['subtotal'],
        ':discount_total'=>$discount,
        ':tax_total'=>(float)$job['tax_total'],
        ':total'=>$total,
        ':balance_due'=>$total,
        ':payment_terms'=>'Due on receipt',
        ':notes'=>'Automatically generated when job '.$job['job_no'].' was completed.',
        ':created_by'=>$createdByUserId
    ));

    $invoiceId=(int)$pdo->lastInsertId();
    $lineCount=0;

    if(!empty($job['quote_id'])&&uwTable($pdo,'quote_line_items')){
        $items=$pdo->prepare("
            SELECT
                product_service_id,item_name,description,quantity,
                unit_cost,unit_price,discount_amount,tax_percent,
                tax_amount,line_total,sort_order
            FROM quote_line_items
            WHERE quote_id=:quote_id
            ORDER BY sort_order,id
        ");
        $items->execute(array(':quote_id'=>$job['quote_id']));

        $lineInsert=$pdo->prepare("
            INSERT INTO invoice_line_items(
                invoice_id,product_service_id,item_name,description,quantity,
                unit_cost,unit_price,discount_amount,tax_percent,tax_amount,
                line_total,sort_order
            ) VALUES(
                :invoice_id,:product_service_id,:item_name,:description,:quantity,
                :unit_cost,:unit_price,:discount_amount,:tax_percent,:tax_amount,
                :line_total,:sort_order
            )
        ");

        foreach($items->fetchAll(PDO::FETCH_ASSOC) as $item){
            $lineInsert->execute(array(
                ':invoice_id'=>$invoiceId,
                ':product_service_id'=>!empty($item['product_service_id'])?(int)$item['product_service_id']:null,
                ':item_name'=>$item['item_name'],
                ':description'=>$item['description'],
                ':quantity'=>$item['quantity'],
                ':unit_cost'=>$item['unit_cost'],
                ':unit_price'=>$item['unit_price'],
                ':discount_amount'=>$item['discount_amount'],
                ':tax_percent'=>$item['tax_percent'],
                ':tax_amount'=>$item['tax_amount'],
                ':line_total'=>$item['line_total'],
                ':sort_order'=>$item['sort_order']
            ));
            $lineCount++;
        }
    }

    if($lineCount===0){
        $serviceName='Completed Job Service';
        if(!empty($job['product_service_id'])){
            $service=$pdo->prepare("
                SELECT name
                FROM product_services
                WHERE id=:id
                  AND tenant_id=:tenant_id
                LIMIT 1
            ");
            $service->execute(array(
                ':id'=>$job['product_service_id'],
                ':tenant_id'=>$tenantId
            ));
            $name=$service->fetchColumn();
            if($name)$serviceName=$name;
        }elseif(!empty($job['title'])){
            $serviceName=$job['title'];
        }

        $taxPercent=0.0;
        if((float)$job['subtotal']>0){
            $taxPercent=((float)$job['tax_total']/(float)$job['subtotal'])*100;
        }

        $line=$pdo->prepare("
            INSERT INTO invoice_line_items(
                invoice_id,product_service_id,item_name,description,quantity,
                unit_cost,unit_price,discount_amount,tax_percent,tax_amount,
                line_total,sort_order
            ) VALUES(
                :invoice_id,:product_service_id,:item_name,:description,1,
                0,:unit_price,0,:tax_percent,:tax_amount,:line_total,0
            )
        ");
        $line->execute(array(
            ':invoice_id'=>$invoiceId,
            ':product_service_id'=>!empty($job['product_service_id'])?(int)$job['product_service_id']:null,
            ':item_name'=>$serviceName,
            ':description'=>'Completed job '.$job['job_no'],
            ':unit_price'=>(float)$job['subtotal'],
            ':tax_percent'=>$taxPercent,
            ':tax_amount'=>(float)$job['tax_total'],
            ':line_total'=>$total
        ));
    }

    try{
        $activity=$pdo->prepare("
            INSERT INTO activity_events(
                tenant_id,branch_id,actor_user_id,actor_type,event_type,
                related_type,related_id,client_id,title,details_json,visible_to_client
            ) VALUES(
                :tenant_id,:branch_id,:user_id,'user','invoice_created',
                'invoice',:invoice_id,:client_id,:title,:details,1
            )
        ");
        $activity->execute(array(
            ':tenant_id'=>$tenantId,
            ':branch_id'=>$branchId>0?$branchId:null,
            ':user_id'=>$createdByUserId,
            ':invoice_id'=>$invoiceId,
            ':client_id'=>$job['client_id'],
            ':title'=>'Invoice generated: '.$invoiceNo,
            ':details'=>json_encode(array(
                'job_id'=>$jobId,
                'job_no'=>$job['job_no'],
                'invoice_no'=>$invoiceNo,
                'total'=>$total
            ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ));
    }catch(Throwable $activityError){
        error_log('Automatic invoice activity: '.$activityError->getMessage());
    }

    return array(
        'id'=>$invoiceId,
        'invoice_no'=>$invoiceNo,
        'status'=>'draft',
        'total'=>$total,
        'amount_paid'=>0,
        'balance_due'=>$total,
        'generated_now'=>1
    );
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
            $generatedInvoice=null;

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

            if($jobCompleted){
                $generatedInvoice=uwEnsureCompletedJobInvoice(
                    $pdo,
                    $tenantId,
                    $jobId,
                    $userId
                );
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
                    'job_completed'=>$jobCompleted,
                    'invoice_id'=>$generatedInvoice? (int)$generatedInvoice['id'] : null,
                    'invoice_no'=>$generatedInvoice? $generatedInvoice['invoice_no'] : null
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
                        'job_completed'=>$jobCompleted,
                        'invoice_id'=>$generatedInvoice? (int)$generatedInvoice['id'] : null,
                        'invoice_no'=>$generatedInvoice? $generatedInvoice['invoice_no'] : null
                    )
                );
            }

            $reviewNotification=array(
                'status'=>'not_required',
                'email_sent'=>0,
                'review_request_id'=>0,
                'message'=>''
            );

            if($jobCompleted){
                $reviewNotification=uwSendReviewRequest(
                    $pdo,
                    $tenantId,
                    $jobId,
                    $userId
                );
            }

            $message=$jobCompleted
                ?'Final workflow step completed. Job marked Completed.'
                :($complete?'Workflow step completed successfully.':'Work progress saved successfully.');

            if($jobCompleted && $generatedInvoice){
                $message.=' Invoice '.$generatedInvoice['invoice_no'].' generated automatically.';
            }

            if($jobCompleted && !empty($reviewNotification['message'])){
                $message.=' '.$reviewNotification['message'];
            }

            uwRes(200,true,$message,array(
                'job_completed'=>$jobCompleted,
                'invoice'=>$generatedInvoice,
                'invoice_url'=>$generatedInvoice?'invoice-view?invoice_id='.(int)$generatedInvoice['id']:null,
                'review_notification'=>$reviewNotification
            ));

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
