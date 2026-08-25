<?php
ob_start();
ini_set('display_errors','0');ini_set('html_errors','0');ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
if(file_exists(__DIR__.'/../includes/audit.php')) require_once __DIR__.'/../includes/audit.php';

function rfRes($code,$ok,$msg,$extra=array()){while(ob_get_level()>0){@ob_end_clean();}http_response_code((int)$code);echo json_encode(array_merge(array('success'=>(bool)$ok,'message'=>(string)$msg),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function rfP($k,$d=''){return isset($_POST[$k])?$_POST[$k]:$d;}
function rfTable(PDO $pdo,$t){static $c=array();if(isset($c[$t]))return $c[$t];$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");$s->execute(array(':t'=>$t));return $c[$t]=(int)$s->fetchColumn()>0;}
function rfCol(PDO $pdo,$t,$c){static $x=array();$k=$t.'.'.$c;if(isset($x[$k]))return $x[$k];$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");$s->execute(array(':t'=>$t,':c'=>$c));return $x[$k]=(int)$s->fetchColumn()>0;}
function rfValid(PDO $pdo,$table,$tenant,$id,$extra=''){$id=(int)$id;if($id<=0)return null;$allowed=array('branches','clients','client_locations','product_services','users','teams','workflows');if(!in_array($table,$allowed,true))return null;$sql="SELECT id FROM $table WHERE id=:id AND tenant_id=:t";if(rfCol($pdo,$table,'deleted_at'))$sql.=" AND deleted_at IS NULL";if($extra!=='')$sql.=' '.$extra;$sql.=' LIMIT 1';$s=$pdo->prepare($sql);$s->execute(array(':id'=>$id,':t'=>$tenant));return $s->fetchColumn()?$id:null;}
function rfCurrency(PDO $pdo,$tenant){$s=$pdo->prepare("SELECT c.id,c.currency_code,c.currency_name,c.symbol,c.symbol_position,c.decimal_places,c.decimal_separator,c.thousand_separator FROM tenants t INNER JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1");$s->execute(array(':t'=>$tenant));$r=$s->fetch();return $r?$r:array('id'=>null,'currency_code'=>'','currency_name'=>'','symbol'=>'','symbol_position'=>'before','decimal_places'=>2,'decimal_separator'=>'.','thousand_separator'=>',');}

function rfDefaultWorkflow(PDO $pdo,$tenant,$serviceId){
    $serviceId=(int)$serviceId;
    if($serviceId<=0)return null;

    $s=$pdo->prepare("
        SELECT w.id
        FROM service_workflows sw
        INNER JOIN workflows w
            ON w.id=sw.workflow_id
           AND w.tenant_id=:tenant_id
           AND w.status='active'
        INNER JOIN product_services ps
            ON ps.id=sw.product_service_id
           AND ps.tenant_id=:tenant_id2
           AND ps.item_type='service'
           AND ps.status='active'
           AND ps.deleted_at IS NULL
        WHERE sw.product_service_id=:service_id
        ORDER BY
            sw.is_default DESC,
            w.version_no DESC,
            w.id DESC
        LIMIT 1
    ");
    $s->execute(array(
        ':tenant_id'=>$tenant,
        ':tenant_id2'=>$tenant,
        ':service_id'=>$serviceId
    ));

    $id=(int)$s->fetchColumn();
    return $id>0?$id:null;
}

function rfInitJobWorkflow(PDO $pdo,$tenant,$jobId,$workflowId,$primaryUserId=null,$teamId=null){
    $jobId=(int)$jobId;
    $workflowId=(int)$workflowId;

    if($jobId<=0||$workflowId<=0||!rfTable($pdo,'job_workflow_progress')){
        return;
    }

    $steps=$pdo->prepare("
        SELECT id,sort_order
        FROM workflow_steps
        WHERE workflow_id=:workflow_id
        ORDER BY sort_order,id
    ");
    $steps->execute(array(':workflow_id'=>$workflowId));
    $rows=$steps->fetchAll();

    if(!$rows)return;

    $exists=$pdo->prepare("
        SELECT id
        FROM job_workflow_progress
        WHERE tenant_id=:tenant_id
          AND job_id=:job_id
          AND visit_id IS NULL
          AND workflow_step_id=:step_id
        LIMIT 1
    ");

    $insert=$pdo->prepare("
        INSERT INTO job_workflow_progress(
            tenant_id,
            job_id,
            visit_id,
            workflow_step_id,
            assigned_user_id,
            assigned_team_id,
            status
        ) VALUES(
            :tenant_id,
            :job_id,
            NULL,
            :step_id,
            :user_id,
            :team_id,
            :status
        )
    ");

    foreach($rows as $index=>$step){
        $exists->execute(array(
            ':tenant_id'=>$tenant,
            ':job_id'=>$jobId,
            ':step_id'=>$step['id']
        ));

        if($exists->fetchColumn())continue;

        $insert->execute(array(
            ':tenant_id'=>$tenant,
            ':job_id'=>$jobId,
            ':step_id'=>$step['id'],
            ':user_id'=>$primaryUserId!==null?(int)$primaryUserId:null,
            ':team_id'=>$teamId!==null?(int)$teamId:null,
            ':status'=>$index===0?'available':'pending'
        ));
    }
}

function rfSmtpConfig(PDO $pdo,$tenant,$branch){
    if(!rfTable($pdo,'smtp_configurations'))return null;
    $s=$pdo->prepare("SELECT * FROM smtp_configurations WHERE tenant_id=:t AND is_active=1 AND scope_type IN('tenant','branch') AND (scope_type='tenant' OR (scope_type='branch' AND branch_id=:b)) ORDER BY CASE WHEN scope_type='branch' AND branch_id=:b2 THEN 0 ELSE 1 END,is_default DESC,id DESC LIMIT 1");
    $s->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:-1,':b2'=>$branch>0?$branch:-1));
    $r=$s->fetch();
    return $r?$r:null;
}
function rfSmtp(PDO $pdo,$tenant,$branch){$r=rfSmtpConfig($pdo,$tenant,$branch);return $r?(int)$r['id']:null;}
function rfSmtpDecrypt($encrypted,$tenantId){
    $encrypted=trim((string)$encrypted);if($encrypted==='')return '';
    $raw=base64_decode($encrypted,true);
    if($raw===false||strlen($raw)<=16)throw new RuntimeException('Stored SMTP password is invalid.');
    $envKey=getenv('FIELDPLX_APP_KEY');
    if($envKey===false||trim($envKey)===''){$seed=(defined('DB_NAME')?DB_NAME:'').'|'.(defined('DB_USER')?DB_USER:'').'|'.(defined('DB_PASS')?DB_PASS:'').'|'.(int)$tenantId;}else{$seed=$envKey.'|'.(int)$tenantId;}
    $key=hash('sha256',$seed,true);
    $plain=openssl_decrypt(substr($raw,16),'AES-256-CBC',$key,OPENSSL_RAW_DATA,substr($raw,0,16));
    if($plain===false)throw new RuntimeException('Unable to decrypt SMTP password.');
    return $plain;
}
function rfSmtpRead($socket){$response='';while(!feof($socket)){$line=fgets($socket,515);if($line===false)break;$response.=$line;if(strlen($line)>=4&&$line[3]===' ')break;}return trim($response);}
function rfSmtpCmd($socket,$cmd,$expected,$label){if($cmd!==null&&@fwrite($socket,$cmd."\r\n")===false)throw new RuntimeException('SMTP connection closed while sending '.$label.'.');$res=rfSmtpRead($socket);$code=(int)substr($res,0,3);if(!in_array($code,(array)$expected,true))throw new RuntimeException($label.' failed (SMTP '.$code.'): '.substr(preg_replace('/[\r\n]+/',' ',$res),0,250));return $res;}
function rfSmtpHeader($v){return trim(str_replace(array("\r","\n"),' ',(string)$v));}
function rfSmtpSend(array $config,$password,$to,$subject,$html){
    if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Employee email is invalid.');
    $host=trim((string)$config['host']);$port=(int)$config['port'];$enc=strtolower(trim((string)$config['encryption']));$user=trim((string)$config['username']);$from=trim((string)$config['from_email']);$fromName=trim((string)$config['from_name']);$reply=trim((string)$config['reply_to_email']);
    if(!filter_var($from,FILTER_VALIDATE_EMAIL))throw new RuntimeException('SMTP From Email is invalid.');
    $remote=($enc==='ssl'?'ssl://':'tcp://').$host.':'.$port;
    $ctx=stream_context_create(array('ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host)));
    $errno=0;$errstr='';$socket=@stream_socket_client($remote,$errno,$errstr,20,STREAM_CLIENT_CONNECT,$ctx);
    if(!$socket)throw new RuntimeException('Unable to connect to SMTP server: '.($errstr!==''?$errstr:'connection failed').'.');
    stream_set_timeout($socket,20);
    try{
        rfSmtpCmd($socket,null,array(220),'SMTP greeting');
        $ehlo=!empty($_SERVER['SERVER_NAME'])?preg_replace('/[^A-Za-z0-9.\-]/','',$_SERVER['SERVER_NAME']):'fieldplx.local';if($ehlo==='')$ehlo='fieldplx.local';
        rfSmtpCmd($socket,'EHLO '.$ehlo,array(250),'EHLO');
        if($enc==='tls'||$enc==='starttls'){
            rfSmtpCmd($socket,'STARTTLS',array(220),'STARTTLS');
            $method=defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')?STREAM_CRYPTO_METHOD_TLS_CLIENT:STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if(@stream_socket_enable_crypto($socket,true,$method)!==true)throw new RuntimeException('Unable to establish TLS encryption.');
            rfSmtpCmd($socket,'EHLO '.$ehlo,array(250),'EHLO after TLS');
        }
        if($user!==''){
            rfSmtpCmd($socket,'AUTH LOGIN',array(334),'SMTP authentication');
            rfSmtpCmd($socket,base64_encode($user),array(334),'SMTP username');
            rfSmtpCmd($socket,base64_encode($password),array(235),'SMTP password');
        }
        rfSmtpCmd($socket,'MAIL FROM:<'.$from.'>',array(250),'MAIL FROM');
        rfSmtpCmd($socket,'RCPT TO:<'.$to.'>',array(250,251),'RCPT TO');
        rfSmtpCmd($socket,'DATA',array(354),'DATA');
        $headers=array('Date: '.date(DATE_RFC2822),'From: '.rfSmtpHeader($fromName!==''?$fromName:'FieldPlx').' <'.$from.'>','To: <'.$to.'>','Subject: '.rfSmtpHeader($subject),'MIME-Version: 1.0','Content-Type: text/html; charset=UTF-8','Content-Transfer-Encoding: 8bit');
        if($reply!==''&&filter_var($reply,FILTER_VALIDATE_EMAIL))$headers[]='Reply-To: <'.$reply.'>';
        $payload=implode("\r\n",$headers)."\r\n\r\n".$html;$payload=preg_replace('/(?m)^\./','..',$payload);
        @fwrite($socket,$payload."\r\n.\r\n");rfSmtpCmd($socket,null,array(250),'Message delivery');@fwrite($socket,"QUIT\r\n");
    }finally{@fclose($socket);}return true;
}
function rfMeta(PDO $pdo,$tenant,$branch){$m=array();$s=$pdo->prepare("SELECT id,name FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name");$s->execute(array(':t'=>$tenant));$m['branches']=$s->fetchAll();$s=$pdo->prepare("SELECT id,display_name name FROM clients WHERE tenant_id=:t AND deleted_at IS NULL AND status<>'archived' ORDER BY display_name");$s->execute(array(':t'=>$tenant));$m['clients']=$s->fetchAll();$s=$pdo->prepare("SELECT id,name,sku FROM product_services WHERE tenant_id=:t AND item_type='service' AND status='active' AND deleted_at IS NULL ORDER BY name");$s->execute(array(':t'=>$tenant));$m['services']=$s->fetchAll();$s=$pdo->prepare("SELECT id,name,item_type,description,unit_cost,unit_price,tax_percent FROM product_services WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY item_type,name");$s->execute(array(':t'=>$tenant));$m['catalog']=$s->fetchAll();$s=$pdo->prepare("SELECT id,CONCAT(first_name,CASE WHEN last_name IS NOT NULL AND last_name<>'' THEN CONCAT(' ',last_name) ELSE '' END) name,email,job_title FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY first_name,last_name");$s->execute(array(':t'=>$tenant));$m['users']=$s->fetchAll();$s=$pdo->prepare("SELECT id,name,leader_user_id FROM teams WHERE tenant_id=:t AND status='active' ORDER BY name");$s->execute(array(':t'=>$tenant));$teams=$s->fetchAll();$ms=$pdo->prepare("SELECT tm.team_id,u.id,CONCAT(u.first_name,CASE WHEN u.last_name IS NOT NULL AND u.last_name<>'' THEN CONCAT(' ',u.last_name) ELSE '' END) name,u.email,u.job_title,tm.is_primary FROM team_members tm INNER JOIN users u ON u.id=tm.user_id AND u.tenant_id=:t AND u.status='active' AND u.deleted_at IS NULL INNER JOIN teams tt ON tt.id=tm.team_id AND tt.tenant_id=:t2 AND tt.status='active' ORDER BY tm.team_id,tm.is_primary DESC,u.id");$ms->execute(array(':t'=>$tenant,':t2'=>$tenant));$by=array();foreach($ms->fetchAll() as $r){$tid=(int)$r['team_id'];if(!isset($by[$tid]))$by[$tid]=array();$by[$tid][]=array('id'=>(int)$r['id'],'name'=>$r['name'],'email'=>$r['email'],'job_title'=>$r['job_title'],'is_primary'=>(int)$r['is_primary']);}foreach($teams as &$t){$t['members']=isset($by[(int)$t['id']])?$by[(int)$t['id']]:array();}unset($t);$m['teams']=$teams;$m['currency']=rfCurrency($pdo,$tenant);$m['smtp_configured']=rfSmtp($pdo,$tenant,$branch)!==null;return $m;}
function rfNext(PDO $pdo,$tenant,$branch,$type,$table,$column,$fallback){$allowed=array('request'=>array('service_requests','request_no','REQ'),'quote'=>array('quotes','quote_no','QUO'),'job'=>array('jobs','job_no','JOB'));if(!isset($allowed[$type]))throw new Exception('Unsupported document type.');$sep=rfCol($pdo,'document_sequences','number_separator')?'number_separator':'separator';$s=$pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type=:dt AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1 FOR UPDATE");$s->execute(array(':t'=>$tenant,':dt'=>$type,':b'=>$branch>0?$branch:0,':b2'=>$branch>0?$branch:0));$r=$s->fetch();if(!$r){$tb=$allowed[$type][0];$col=$allowed[$type][1];$pre=$allowed[$type][2];$q=$pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX($col,'-',-1) AS UNSIGNED)) FROM $tb WHERE tenant_id=:t AND $col LIKE :p");$q->execute(array(':t'=>$tenant,':p'=>$pre.'-%'));return $pre.'-'.str_pad((string)((int)$q->fetchColumn()+1),6,'0',STR_PAD_LEFT);} $now=new DateTime('now');$y=$now->format('Y');$mo=$now->format('m');$fyStart=max(1,min(12,(int)$r['financial_year_start_month']));$fyY=(int)$now->format('n')>=$fyStart?(int)$y:(int)$y-1;$fy=$fyY.'-'.substr((string)($fyY+1),-2);$key='never';if($r['reset_period']==='monthly')$key=$y.$mo;elseif($r['reset_period']==='yearly')$key=$y;elseif($r['reset_period']==='financial_year')$key=$fy;$cur=(int)$r['current_number'];if($r['reset_period']!=='never'&&(string)$r['last_reset_key']!==(string)$key)$cur=0;$next=$cur+1;$mid='';if($r['middle_format']==='year')$mid=$y;elseif($r['middle_format']==='year_month')$mid=$y.$mo;elseif($r['middle_format']==='financial_year')$mid=$fy;elseif($r['middle_format']==='branch_year')$mid=(!empty($r['branch_code'])?$r['branch_code']:'BR').$y;$parts=array();if(!empty($r['prefix']))$parts[]=$r['prefix'];if($mid!=='')$parts[]=$mid;$parts[]=str_pad((string)$next,max(1,(int)$r['number_length']),'0',STR_PAD_LEFT);if(!empty($r['suffix']))$parts[]=$r['suffix'];$no=implode(isset($r[$sep])?(string)$r[$sep]:'-',$parts);$u=$pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");$u->execute(array(':n'=>$next,':k'=>$key,':id'=>$r['id']));return $no;}
function rfResolveUsers(PDO $pdo,$tenant,$mode,$individual,$team,$multi){$ids=array();$resolvedTeam=null;if($mode==='individual'){$u=rfValid($pdo,'users',$tenant,$individual,"AND status='active'");if($u===null)rfRes(422,false,'Select a valid employee.');$ids[]=$u;}elseif($mode==='team'){$resolvedTeam=rfValid($pdo,'teams',$tenant,$team,"AND status='active'");if($resolvedTeam===null)rfRes(422,false,'Select a valid team.');$s=$pdo->prepare("SELECT u.id FROM team_members tm INNER JOIN users u ON u.id=tm.user_id AND u.tenant_id=:t AND u.status='active' AND u.deleted_at IS NULL INNER JOIN teams tt ON tt.id=tm.team_id AND tt.tenant_id=:t2 AND tt.status='active' WHERE tm.team_id=:team ORDER BY CASE WHEN tt.leader_user_id=u.id THEN 0 ELSE 1 END,tm.is_primary DESC,u.id");$s->execute(array(':t'=>$tenant,':t2'=>$tenant,':team'=>$resolvedTeam));$ids=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));if(!$ids)rfRes(422,false,'Selected team has no active employees.');}else{foreach((array)$multi as $raw){$u=rfValid($pdo,'users',$tenant,(int)$raw,"AND status='active'");if($u!==null)$ids[$u]=$u;}$ids=array_values($ids);if(!$ids)rfRes(422,false,'Select at least one employee.');}return array('users'=>array_values(array_unique($ids)),'team_id'=>$resolvedTeam);}
function rfNotify(PDO $pdo,$tenant,$branch,$users,$eventKey,$title,$message,$relatedType,$relatedId,$inApp,$email){
    $sum=array('in_app'=>0,'email_sent'=>0,'email_failed'=>0,'email_skipped'=>0,'email_messages'=>array(),'smtp_config_id'=>null,'smtp_config_name'=>null);
    if(!$users)return $sum;

    $config=$email?rfSmtpConfig($pdo,$tenant,$branch):null;
    $smtpPassword=null;
    if($config){
        $sum['smtp_config_id']=(int)$config['id'];
        $sum['smtp_config_name']=isset($config['config_name'])?$config['config_name']:null;
        try{$smtpPassword=rfSmtpDecrypt($config['password_encrypted'],$tenant);}catch(Throwable $e){$sum['email_messages'][]=$e->getMessage();$config=null;}
    }

    $ph=array();$pa=array(':t'=>$tenant);
    foreach($users as $i=>$id){$k=':u'.$i;$ph[]=$k;$pa[$k]=(int)$id;}
    $s=$pdo->prepare("SELECT id,email,first_name,last_name FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL AND id IN(".implode(',',$ph).")");
    $s->execute($pa);

    foreach($s->fetchAll() as $r){
        if($inApp && rfTable($pdo,'in_app_notifications')){
            try{
                $q=$pdo->prepare("INSERT INTO in_app_notifications(tenant_id,user_id,title,message,related_type,related_id,action_url,icon_name,is_read) VALUES(:t,:u,:title,:message,:rt,:rid,:url,'bell',0)");
                $q->execute(array(':t'=>$tenant,':u'=>$r['id'],':title'=>$title,':message'=>$message,':rt'=>$relatedType,':rid'=>$relatedId,':url'=>$relatedType==='job'?'my-job-view.php?id='.(int)$relatedId:'requests.php?request_id='.(int)$relatedId));
                $sum['in_app']++;
            }catch(Throwable $e){error_log('request in-app notification: '.$e->getMessage());}
        }

        if($email){
            $addr=trim((string)$r['email']);
            if(!filter_var($addr,FILTER_VALIDATE_EMAIL)){$sum['email_skipped']++;$sum['email_messages'][]='Employee '.(int)$r['id'].' has no valid email.';continue;}
            if(!$config){$sum['email_skipped']++;if(!$sum['email_messages'])$sum['email_messages'][]='No active Tenant SMTP configuration was found.';continue;}

            try{
                $name=trim((string)$r['first_name'].' '.(string)$r['last_name']);
                $html='<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#1f2d3d">'.
                    '<h2 style="color:#123d70">'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h2>'.
                    '<p>Hello '.htmlspecialchars($name!==''?$name:'Employee',ENT_QUOTES,'UTF-8').',</p>'.
                    '<p>'.nl2br(htmlspecialchars($message,ENT_QUOTES,'UTF-8')).'</p>'.
                    '<p>Please login to FieldPlx to view the assigned work details.</p>'.
                    '<p style="margin-top:24px">FieldPlx</p></div>';
                rfSmtpSend($config,$smtpPassword,$addr,$title,$html);
                $sum['email_sent']++;
                $sum['email_messages'][]='Email sent to '.$addr.'.';

                /* Keep a sent notification_queue record when the table/event exists, but do not rely on a background worker. */
                if(rfTable($pdo,'notification_queue')&&rfTable($pdo,'notification_events')){
                    try{
                        $ev=$pdo->prepare("SELECT id FROM notification_events WHERE event_key=:k AND is_active=1 LIMIT 1");$ev->execute(array(':k'=>$eventKey));$eventId=(int)$ev->fetchColumn();
                        if($eventId<=0){$ev=$pdo->prepare("SELECT id FROM notification_events WHERE event_key='request.assigned' AND is_active=1 LIMIT 1");$ev->execute();$eventId=(int)$ev->fetchColumn();}
                        if($eventId>0){
                            $q=$pdo->prepare("INSERT INTO notification_queue(tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address,related_type,related_id,subject,body,smtp_config_id,status,scheduled_at,sent_at) VALUES(:t,:b,:e,'email','user',:u,:addr,:rt,:rid,:sub,:body,:smtp,'sent',NOW(),NOW())");
                            $q->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':e'=>$eventId,':u'=>$r['id'],':addr'=>$addr,':rt'=>$relatedType,':rid'=>$relatedId,':sub'=>$title,':body'=>$message,':smtp'=>$config['id']));
                        }
                    }catch(Throwable $e){error_log('request email log queue: '.$e->getMessage());}
                }
            }catch(Throwable $e){$sum['email_failed']++;$sum['email_messages'][]='Email failed for '.$addr.': '.$e->getMessage();error_log('request direct SMTP: '.$e->getMessage());}
        }
    }
    return $sum;
}
function rfLog(PDO $pdo,$tenant,$branch,$user,$type,$related,$rid,$client,$title,$details){try{$s=$pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user',:e,:rt,:rid,:cid,:title,:details,0)");$s->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':u'=>$user,':e'=>$type,':rt'=>$related,':rid'=>$rid,':cid'=>$client,':title'=>substr($title,0,255),':details'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));}catch(Throwable $e){error_log('activity '.$e->getMessage());}}

$tenant=isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;$user=isset($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:0;$sessionBranch=isset($_SESSION['branch_id'])?(int)$_SESSION['branch_id']:0;if($tenant<=0||$user<=0)rfRes(401,false,'Authentication required.');$csrf=(string)rfP('csrf_token','');$sess=isset($_SESSION['add_request_csrf_token'])?(string)$_SESSION['add_request_csrf_token']:'';if($csrf===''||$sess===''||!hash_equals($sess,$csrf))rfRes(419,false,'Your form session expired. Refresh and try again.');$a=trim((string)rfP('action',''));
try{
if($a==='meta')rfRes(200,true,'Form data loaded.',array('meta'=>rfMeta($pdo,$tenant,$sessionBranch)));
if($a==='locations'){$client=(int)rfP('client_id',0);if(rfValid($pdo,'clients',$tenant,$client)===null)rfRes(422,false,'Selected client is invalid.');$s=$pdo->prepare("SELECT id,name FROM client_locations WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL AND status='active' ORDER BY is_primary DESC,name");$s->execute(array(':t'=>$tenant,':c'=>$client));rfRes(200,true,'Locations loaded.',array('locations'=>$s->fetchAll()));}

if($a==='get_edit'){
  $requestId=(int)rfP('request_id',0);
  if($requestId<=0)rfRes(422,false,'Invalid service request.');

  $s=$pdo->prepare("SELECT * FROM service_requests WHERE id=:id AND tenant_id=:t LIMIT 1");
  $s->execute(array(':id'=>$requestId,':t'=>$tenant));
  $request=$s->fetch();
  if(!$request)rfRes(404,false,'Service request not found.');

  $assignment=array('mode'=>null,'team_id'=>null,'user_ids'=>array());
  if(rfTable($pdo,'request_assignments')){
    $as=$pdo->prepare("SELECT assignment_mode,team_id,user_id,is_primary FROM request_assignments WHERE tenant_id=:t AND request_id=:r ORDER BY is_primary DESC,id");
    $as->execute(array(':t'=>$tenant,':r'=>$requestId));
    $arows=$as->fetchAll();
    if($arows){
      $assignment['mode']=$arows[0]['assignment_mode'];
      $assignment['team_id']=$arows[0]['team_id']!==null?(int)$arows[0]['team_id']:null;
      foreach($arows as $x){if(!empty($x['user_id']))$assignment['user_ids'][]=(int)$x['user_id'];}
    }elseif(!empty($request['assigned_user_id'])){
      $assignment['mode']='individual';
      $assignment['user_ids']=array((int)$request['assigned_user_id']);
    }
  }elseif(!empty($request['assigned_user_id'])){
    $assignment['mode']='individual';
    $assignment['user_ids']=array((int)$request['assigned_user_id']);
  }

  $quoteId=null;$quoteNo=null;$quoteItems=array();
  $qs=$pdo->prepare("SELECT id,quote_no FROM quotes WHERE tenant_id=:t AND request_id=:r ORDER BY id DESC LIMIT 1");
  $qs->execute(array(':t'=>$tenant,':r'=>$requestId));
  $qr=$qs->fetch();
  if($qr){
    $quoteId=(int)$qr['id'];$quoteNo=$qr['quote_no'];
    $qi=$pdo->prepare("SELECT qli.*,ps.item_type FROM quote_line_items qli LEFT JOIN product_services ps ON ps.id=qli.product_service_id WHERE qli.quote_id=:q ORDER BY qli.sort_order,qli.id");
    $qi->execute(array(':q'=>$quoteId));$quoteItems=$qi->fetchAll();
  }

  $jobNo=null;
  $js=$pdo->prepare("SELECT job_no FROM jobs WHERE tenant_id=:t AND request_id=:r AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
  $js->execute(array(':t'=>$tenant,':r'=>$requestId));
  $jobNo=$js->fetchColumn();
  $editStatus=$request['status'];
  if($request['status']==='converted' && $jobNo)$editStatus='job_required';

  $request['edit_status']=$editStatus;

  rfRes(200,true,'Service request loaded.',array(
    'request'=>$request,
    'assignment'=>$assignment,
    'quote_items'=>$quoteItems,
    'linked_quote_id'=>$quoteId,
    'linked_quote_no'=>$quoteNo,
    'linked_job_no'=>$jobNo
  ));
}

if($a==='update'){
  $requestId=(int)rfP('request_id',0);
  if($requestId<=0)rfRes(422,false,'Invalid service request.');

  $oldStmt=$pdo->prepare("SELECT * FROM service_requests WHERE id=:id AND tenant_id=:t LIMIT 1");
  $oldStmt->execute(array(':id'=>$requestId,':t'=>$tenant));
  $old=$oldStmt->fetch();
  if(!$old)rfRes(404,false,'Service request not found.');

  $client=(int)rfP('client_id',0);
  $location=(int)rfP('location_id',0);
  $service=(int)rfP('product_service_id',0);
  $branch=(int)rfP('branch_id',0);
  $title=trim((string)rfP('title',''));
  $description=trim((string)rfP('description',''));
  $source=trim((string)rfP('source','office'));
  $priority=trim((string)rfP('priority','normal'));
  $status=trim((string)rfP('status','new'));
  $date=trim((string)rfP('preferred_date',''));
  $from=trim((string)rfP('preferred_time_from',''));
  $to=trim((string)rfP('preferred_time_to',''));

  if($title==='')rfRes(422,false,'Request title is required.');
  $client=rfValid($pdo,'clients',$tenant,$client);
  if($client===null)rfRes(422,false,'Select a valid client.');
  $service=rfValid($pdo,'product_services',$tenant,$service,"AND item_type='service' AND status='active'");
  if($service===null)rfRes(422,false,'Select a valid active service.');
  $branch=rfValid($pdo,'branches',$tenant,$branch);

  if($location>0){
    $location=rfValid($pdo,'client_locations',$tenant,$location);
    if($location===null)rfRes(422,false,'Selected location is invalid.');
    $lc=$pdo->prepare("SELECT id FROM client_locations WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL LIMIT 1");
    $lc->execute(array(':id'=>$location,':t'=>$tenant,':c'=>$client));
    if(!$lc->fetchColumn())rfRes(422,false,'Selected location does not belong to client.');
  }else{$location=null;}

  if(!in_array($source,array('office','website','portal','phone','sms','email','ai','other'),true))rfRes(422,false,'Invalid request source.');
  if(!in_array($priority,array('low','normal','high','urgent'),true))rfRes(422,false,'Invalid priority.');
  if(!in_array($status,array('new','contacting','information_required','assessment_required','quote_required','job_required'),true))rfRes(422,false,'Invalid request stage.');
  if($from!==''&&$to!==''&&$from>=$to)rfRes(422,false,'Preferred end time must be after start time.');

  $needsAssignment=in_array($status,array('assessment_required','job_required'),true);
  $assignmentMode=null;
  $assigned=array('users'=>array(),'team_id'=>null);

  if($needsAssignment){
    if(!rfTable($pdo,'request_assignments'))rfRes(500,false,'Run migration_request_flow.sql once before using Assessment/Job employee assignment.');
    $assignmentMode=trim((string)rfP('assignment_mode','individual'));
    if(!in_array($assignmentMode,array('individual','team','multiple'),true))rfRes(422,false,'Invalid assignment mode.');
    $multi=isset($_POST['employee_ids'])&&is_array($_POST['employee_ids'])?$_POST['employee_ids']:array();
    $assigned=rfResolveUsers($pdo,$tenant,$assignmentMode,(int)rfP('individual_user_id',0),(int)rfP('team_id',0),$multi);
  }

  $quoteItems=array();
  if($status==='quote_required'){
    $raw=(string)rfP('quote_items_json','[]');
    $quoteItems=json_decode($raw,true);
    if(!is_array($quoteItems)||!count($quoteItems))rfRes(422,false,'Add at least one quotation item.');
  }

  $existingQuoteStmt=$pdo->prepare("SELECT id,quote_no FROM quotes WHERE tenant_id=:t AND request_id=:r ORDER BY id DESC LIMIT 1");
  $existingQuoteStmt->execute(array(':t'=>$tenant,':r'=>$requestId));
  $existingQuote=$existingQuoteStmt->fetch();

  $existingJobStmt=$pdo->prepare("SELECT id,job_no,status FROM jobs WHERE tenant_id=:t AND request_id=:r AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
  $existingJobStmt->execute(array(':t'=>$tenant,':r'=>$requestId));
  $existingJob=$existingJobStmt->fetch();

  $oldAssignIds=array();
  if(rfTable($pdo,'request_assignments')){
    $oa=$pdo->prepare("SELECT user_id FROM request_assignments WHERE tenant_id=:t AND request_id=:r ORDER BY user_id");
    $oa->execute(array(':t'=>$tenant,':r'=>$requestId));
    $oldAssignIds=array_values(array_filter(array_map('intval',$oa->fetchAll(PDO::FETCH_COLUMN))));
  }
  $newAssignIds=$needsAssignment?$assigned['users']:array();
  sort($oldAssignIds);sort($newAssignIds);
  $assignmentChanged=$oldAssignIds!==$newAssignIds;

  $pdo->beginTransaction();
  $quoteNo=$existingQuote?$existingQuote['quote_no']:null;
  $quoteId=$existingQuote?(int)$existingQuote['id']:null;
  $jobNo=$existingJob?$existingJob['job_no']:null;
  $jobId=$existingJob?(int)$existingJob['id']:null;
  $finalStatus=$status;

  try{
    $primary=$needsAssignment&&count($assigned['users'])?(int)$assigned['users'][0]:null;

    $up=$pdo->prepare("UPDATE service_requests SET branch_id=:b,client_id=:c,location_id=:l,product_service_id=:ps,source=:src,priority=:pri,title=:title,description=:d,preferred_date=:dt,preferred_time_from=:f,preferred_time_to=:to,assigned_user_id=:au,status=:st WHERE id=:id AND tenant_id=:t");
    $up->execute(array(
      ':b'=>$branch,':c'=>$client,':l'=>$location,':ps'=>$service,':src'=>$source,':pri'=>$priority,
      ':title'=>$title,':d'=>$description!==''?$description:null,':dt'=>$date!==''?$date:null,
      ':f'=>$from!==''?$from:null,':to'=>$to!==''?$to:null,':au'=>$primary,':st'=>$status,
      ':id'=>$requestId,':t'=>$tenant
    ));

    if(rfTable($pdo,'request_assignments')){
      $del=$pdo->prepare("DELETE FROM request_assignments WHERE tenant_id=:t AND request_id=:r");
      $del->execute(array(':t'=>$tenant,':r'=>$requestId));
      if($needsAssignment){
        $ri=$pdo->prepare("INSERT INTO request_assignments(tenant_id,request_id,assignment_mode,team_id,user_id,is_primary,assigned_by) VALUES(:t,:r,:m,:team,:uid,:p,:by)");
        foreach($assigned['users'] as $i=>$uid){
          $ri->execute(array(':t'=>$tenant,':r'=>$requestId,':m'=>$assignmentMode,':team'=>$assigned['team_id'],':uid'=>$uid,':p'=>$i===0?1:0,':by'=>$user));
        }
      }
    }

    if($status==='quote_required'){
      $sub=0;$disc=0;$tax=0;$tot=0;$normalized=array();
      foreach($quoteItems as $idx=>$x){
        $pid=isset($x['product_service_id'])?(int)$x['product_service_id']:0;
        $pid=$pid>0?rfValid($pdo,'product_services',$tenant,$pid,"AND status='active'"):null;
        $name=trim((string)($x['item_name']??''));
        if($name==='')rfRes(422,false,'Quotation item name is required.');
        $qty=max(.001,(float)($x['quantity']??1));
        $cost=max(0,(float)($x['unit_cost']??0));
        $price=max(0,(float)($x['unit_price']??0));
        $base=$qty*$price;
        $d=max(0,min($base,(float)($x['discount_amount']??0)));
        $taxPct=max(0,(float)($x['tax_percent']??0));
        $taxable=max(0,$base-$d);
        $taxAmt=$taxable*$taxPct/100;
        $line=$taxable+$taxAmt;
        $sub+=$base;$disc+=$d;$tax+=$taxAmt;$tot+=$line;
        $normalized[]=array('pid'=>$pid,'name'=>$name,'description'=>trim((string)($x['description']??'')),'qty'=>$qty,'cost'=>$cost,'price'=>$price,'discount'=>$d,'tax_percent'=>$taxPct,'tax_amount'=>$taxAmt,'line_total'=>$line,'optional'=>!empty($x['is_optional'])?1:0,'sort'=>$idx);
      }

      if($quoteId){
        $q=$pdo->prepare("UPDATE quotes SET branch_id=:b,client_id=:c,location_id=:l,title=:title,introduction=:intro,subtotal=:sub,discount_total=:disc,tax_total=:tax,total=:tot WHERE id=:id AND tenant_id=:t");
        $q->execute(array(':b'=>$branch,':c'=>$client,':l'=>$location,':title'=>$title,':intro'=>$description!==''?$description:null,':sub'=>$sub,':disc'=>$disc,':tax'=>$tax,':tot'=>$tot,':id'=>$quoteId,':t'=>$tenant));
        $dq=$pdo->prepare("DELETE FROM quote_line_items WHERE quote_id=:q");
        $dq->execute(array(':q'=>$quoteId));
      }else{
        $quoteNo=rfNext($pdo,$tenant,$branch!==null?$branch:$sessionBranch,'quote','quotes','quote_no','QUO');
        $q=$pdo->prepare("INSERT INTO quotes(tenant_id,branch_id,quote_no,revision_no,client_id,location_id,request_id,title,introduction,status,subtotal,discount_total,tax_total,total,created_by) VALUES(:t,:b,:no,0,:c,:l,:r,:title,:intro,'draft',:sub,:disc,:tax,:tot,:u)");
        $q->execute(array(':t'=>$tenant,':b'=>$branch,':no'=>$quoteNo,':c'=>$client,':l'=>$location,':r'=>$requestId,':title'=>$title,':intro'=>$description!==''?$description:null,':sub'=>$sub,':disc'=>$disc,':tax'=>$tax,':tot'=>$tot,':u'=>$user));
        $quoteId=(int)$pdo->lastInsertId();
      }

      $qi=$pdo->prepare("INSERT INTO quote_line_items(quote_id,product_service_id,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,is_optional,sort_order) VALUES(:q,:pid,:name,:d,:qty,:cost,:price,:disc,:tp,:ta,:lt,:opt,:sort)");
      foreach($normalized as $x){
        $qi->execute(array(':q'=>$quoteId,':pid'=>$x['pid'],':name'=>$x['name'],':d'=>$x['description']!==''?$x['description']:null,':qty'=>$x['qty'],':cost'=>$x['cost'],':price'=>$x['price'],':disc'=>$x['discount'],':tp'=>$x['tax_percent'],':ta'=>$x['tax_amount'],':lt'=>$x['line_total'],':opt'=>$x['optional'],':sort'=>$x['sort']));
      }
    }

    if($status==='job_required'){
      $jobMode=$assignmentMode==='individual'?'single_user':($assignmentMode==='multiple'?'multiple_users':'team');
      $jobStatus=$date!==''?'scheduled':'active';
      $workflowId=rfDefaultWorkflow($pdo,$tenant,$service);

      if($jobId){
        $j=$pdo->prepare("UPDATE jobs SET branch_id=:b,client_id=:c,location_id=:l,product_service_id=:ps,workflow_id=:workflow,title=:title,description=:d,priority=:pri,assignment_mode=:am,status=:st,start_date=:sd WHERE id=:id AND tenant_id=:t");
        $j->execute(array(':b'=>$branch,':c'=>$client,':l'=>$location,':ps'=>$service,':workflow'=>$workflowId,':title'=>$title,':d'=>$description!==''?$description:null,':pri'=>$priority,':am'=>$jobMode,':st'=>$jobStatus,':sd'=>$date!==''?$date:null,':id'=>$jobId,':t'=>$tenant));
        $dj=$pdo->prepare("DELETE FROM job_assignments WHERE tenant_id=:t AND job_id=:j");
        $dj->execute(array(':t'=>$tenant,':j'=>$jobId));
      }else{
        $jobNo=rfNext($pdo,$tenant,$branch!==null?$branch:$sessionBranch,'job','jobs','job_no','JOB');
        $j=$pdo->prepare("INSERT INTO jobs(tenant_id,branch_id,job_no,client_id,location_id,request_id,product_service_id,workflow_id,title,description,job_type,priority,assignment_mode,assignment_completion_mode,status,start_date,invoicing_preference,subtotal,tax_total,total,created_by) VALUES(:t,:b,:no,:c,:l,:r,:ps,:workflow,:title,:d,'one_off',:pri,:am,'primary_only',:st,:sd,'when_job_complete',0,0,0,:u)");
        $j->execute(array(':t'=>$tenant,':b'=>$branch,':no'=>$jobNo,':c'=>$client,':l'=>$location,':r'=>$requestId,':ps'=>$service,':workflow'=>$workflowId,':title'=>$title,':d'=>$description!==''?$description:null,':pri'=>$priority,':am'=>$jobMode,':st'=>$jobStatus,':sd'=>$date!==''?$date:null,':u'=>$user));
        $jobId=(int)$pdo->lastInsertId();
      }

      $ja=$pdo->prepare("INSERT INTO job_assignments(tenant_id,job_id,user_id,team_id,assignment_role,is_primary_responsible,assigned_by,status) VALUES(:t,:j,:uid,:team,:role,:p,:by,'assigned')");
      if($assignmentMode==='team'){
        $ja->execute(array(':t'=>$tenant,':j'=>$jobId,':uid'=>null,':team'=>$assigned['team_id'],':role'=>'primary',':p'=>1,':by'=>$user));
      }else{
        foreach($assigned['users'] as $i=>$uid){
          $ja->execute(array(':t'=>$tenant,':j'=>$jobId,':uid'=>$uid,':team'=>null,':role'=>$i===0?'primary':'technician',':p'=>$i===0?1:0,':by'=>$user));
        }
      }

      rfInitJobWorkflow(
        $pdo,
        $tenant,
        $jobId,
        $workflowId,
        $assignmentMode==='team'?null:(count($assigned['users'])?(int)$assigned['users'][0]:null),
        $assignmentMode==='team'?$assigned['team_id']:null
      );

      $finalStatus='converted';
      $u=$pdo->prepare("UPDATE service_requests SET status='converted' WHERE id=:r AND tenant_id=:t");
      $u->execute(array(':r'=>$requestId,':t'=>$tenant));
    }

    $historyOld=$old['status'];
    if($historyOld!==$finalStatus){
      $h=$pdo->prepare("INSERT INTO request_status_history(tenant_id,request_id,old_status,new_status,notes,changed_by) VALUES(:t,:r,:old,:new,:n,:u)");
      $note=$finalStatus==='converted'&&$jobNo?'Job '.$jobNo.' generated/updated automatically':'Service request updated';
      $h->execute(array(':t'=>$tenant,':r'=>$requestId,':old'=>$historyOld,':new'=>$finalStatus,':n'=>$note,':u'=>$user));
    }

    $pdo->commit();
  }catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
  }

  $notify=array('in_app'=>0,'email_sent'=>0,'email_failed'=>0,'email_skipped'=>0,'email_messages'=>array(),'smtp_config_id'=>null,'smtp_config_name'=>null);
  if($needsAssignment && ($assignmentChanged || $old['status']!==$finalStatus)){
    $in=(string)rfP('notify_in_app','1')!=='0';
    $em=(string)rfP('notify_email','1')!=='0';
    $entity=$status==='job_required'?'job':'service_request';
    $rid=$status==='job_required'?$jobId:$requestId;
    $event=$status==='job_required'?'job.assigned':'request.assigned';
    $nt=$status==='job_required'?'Job Assignment Updated':'Assessment Assignment Updated';
    $msg=($status==='job_required'?'You have been assigned to job '.$jobNo:'You have been assigned to assess request '.$old['request_no']).' - '.$title;
    $notify=rfNotify($pdo,$tenant,$branch!==null?$branch:$sessionBranch,$assigned['users'],$event,$nt,$msg,$entity,$rid,$in,$em);
  }

  rfLog($pdo,$tenant,$branch!==null?$branch:$sessionBranch,$user,'service_request_updated','service_request',$requestId,$client,'Service request updated: '.$old['request_no'],array('status'=>$finalStatus,'quote_id'=>$quoteId,'job_id'=>$jobId,'assigned_users'=>$assigned['users'],'notifications'=>$notify));
  if(function_exists('tenantAuditLog'))tenantAuditLog($pdo,'SERVICE_REQUEST_UPDATED',$tenant,$branch!==null?$branch:$sessionBranch,$user,'service_request',$requestId,$old,array('status'=>$finalStatus,'quote_id'=>$quoteId,'job_id'=>$jobId));

  rfRes(200,true,'Service request '.$old['request_no'].' updated successfully.',array(
    'request_id'=>$requestId,
    'request_no'=>$old['request_no'],
    'quote_id'=>$quoteId,
    'quote_no'=>$quoteNo,
    'job_id'=>$jobId,
    'job_no'=>$jobNo,
    'notification_summary'=>$notify
  ));
}

if($a==='create'){
  $client=(int)rfP('client_id',0);$location=(int)rfP('location_id',0);$service=(int)rfP('product_service_id',0);$branch=(int)rfP('branch_id',0);$title=trim((string)rfP('title',''));$description=trim((string)rfP('description',''));$source=trim((string)rfP('source','office'));$priority=trim((string)rfP('priority','normal'));$status=trim((string)rfP('status','new'));$date=trim((string)rfP('preferred_date',''));$from=trim((string)rfP('preferred_time_from',''));$to=trim((string)rfP('preferred_time_to',''));
  if($title==='')rfRes(422,false,'Request title is required.');$client=rfValid($pdo,'clients',$tenant,$client);if($client===null)rfRes(422,false,'Select a valid client.');$service=rfValid($pdo,'product_services',$tenant,$service,"AND item_type='service' AND status='active'");if($service===null)rfRes(422,false,'Select a valid active service.');$branch=rfValid($pdo,'branches',$tenant,$branch);if($location>0){$location=rfValid($pdo,'client_locations',$tenant,$location);if($location===null)rfRes(422,false,'Selected location is invalid.');$s=$pdo->prepare("SELECT id FROM client_locations WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL LIMIT 1");$s->execute(array(':id'=>$location,':t'=>$tenant,':c'=>$client));if(!$s->fetchColumn())rfRes(422,false,'Selected location does not belong to client.');}else{$location=null;}
  if(!in_array($source,array('office','website','portal','phone','sms','email','ai','other'),true))rfRes(422,false,'Invalid request source.');if(!in_array($priority,array('low','normal','high','urgent'),true))rfRes(422,false,'Invalid priority.');if(!in_array($status,array('new','contacting','information_required','assessment_required','quote_required','job_required'),true))rfRes(422,false,'Invalid request stage.');if($from!==''&&$to!==''&&$from>=$to)rfRes(422,false,'Preferred end time must be after start time.');
  $needsAssignment=in_array($status,array('assessment_required','job_required'),true);$assignmentMode=null;$assigned=array('users'=>array(),'team_id'=>null);if($needsAssignment){if(!rfTable($pdo,'request_assignments'))rfRes(500,false,'Run migration_request_flow.sql once before using Assessment/Job employee assignment.');$assignmentMode=trim((string)rfP('assignment_mode','individual'));if(!in_array($assignmentMode,array('individual','team','multiple'),true))rfRes(422,false,'Invalid assignment mode.');$multi=isset($_POST['employee_ids'])&&is_array($_POST['employee_ids'])?$_POST['employee_ids']:array();$assigned=rfResolveUsers($pdo,$tenant,$assignmentMode,(int)rfP('individual_user_id',0),(int)rfP('team_id',0),$multi);}
  $quoteItems=array();if($status==='quote_required'){$raw=(string)rfP('quote_items_json','[]');$quoteItems=json_decode($raw,true);if(!is_array($quoteItems)||!count($quoteItems))rfRes(422,false,'Add at least one quotation item.');}
  $pdo->beginTransaction();$quoteNo=null;$jobNo=null;$jobId=null;$quoteId=null;
  try{
    $requestNo=rfNext($pdo,$tenant,$branch!==null?$branch:$sessionBranch,'request','service_requests','request_no','REQ');$primary=$needsAssignment&&count($assigned['users'])?(int)$assigned['users'][0]:null;
    $s=$pdo->prepare("INSERT INTO service_requests(tenant_id,branch_id,request_no,client_id,location_id,product_service_id,source,priority,title,description,preferred_date,preferred_time_from,preferred_time_to,assigned_user_id,status,created_by_user_id) VALUES(:t,:b,:no,:c,:l,:ps,:src,:pri,:title,:d,:dt,:f,:to,:au,:st,:u)");$s->execute(array(':t'=>$tenant,':b'=>$branch,':no'=>$requestNo,':c'=>$client,':l'=>$location,':ps'=>$service,':src'=>$source,':pri'=>$priority,':title'=>$title,':d'=>$description!==''?$description:null,':dt'=>$date!==''?$date:null,':f'=>$from!==''?$from:null,':to'=>$to!==''?$to:null,':au'=>$primary,':st'=>$status,':u'=>$user));$requestId=(int)$pdo->lastInsertId();
    $h=$pdo->prepare("INSERT INTO request_status_history(tenant_id,request_id,old_status,new_status,notes,changed_by) VALUES(:t,:r,NULL,:st,'Service request created',:u)");$h->execute(array(':t'=>$tenant,':r'=>$requestId,':st'=>$status,':u'=>$user));
    if($needsAssignment){$ri=$pdo->prepare("INSERT INTO request_assignments(tenant_id,request_id,assignment_mode,team_id,user_id,is_primary,assigned_by) VALUES(:t,:r,:m,:team,:uid,:p,:by)");foreach($assigned['users'] as $i=>$uid){$ri->execute(array(':t'=>$tenant,':r'=>$requestId,':m'=>$assignmentMode,':team'=>$assigned['team_id'],':uid'=>$uid,':p'=>$i===0?1:0,':by'=>$user));}}
    if($status==='quote_required'){
      $quoteNo=rfNext($pdo,$tenant,$branch!==null?$branch:$sessionBranch,'quote','quotes','quote_no','QUO');$sub=0;$disc=0;$tax=0;$tot=0;$normalized=array();foreach($quoteItems as $idx=>$x){$pid=isset($x['product_service_id'])?(int)$x['product_service_id']:0;$pid=$pid>0?rfValid($pdo,'product_services',$tenant,$pid,"AND status='active'"):null;$name=trim((string)($x['item_name']??''));if($name==='')rfRes(422,false,'Quotation item name is required.');$qty=max(.001,(float)($x['quantity']??1));$cost=max(0,(float)($x['unit_cost']??0));$price=max(0,(float)($x['unit_price']??0));$base=$qty*$price;$d=max(0,min($base,(float)($x['discount_amount']??0)));$taxPct=max(0,(float)($x['tax_percent']??0));$taxable=max(0,$base-$d);$taxAmt=$taxable*$taxPct/100;$line=$taxable+$taxAmt;$sub+=$base;$disc+=$d;$tax+=$taxAmt;$tot+=$line;$normalized[]=array('pid'=>$pid,'name'=>$name,'description'=>trim((string)($x['description']??'')),'qty'=>$qty,'cost'=>$cost,'price'=>$price,'discount'=>$d,'tax_percent'=>$taxPct,'tax_amount'=>$taxAmt,'line_total'=>$line,'optional'=>!empty($x['is_optional'])?1:0,'sort'=>$idx);}
      $q=$pdo->prepare("INSERT INTO quotes(tenant_id,branch_id,quote_no,revision_no,client_id,location_id,request_id,title,introduction,status,subtotal,discount_total,tax_total,total,created_by) VALUES(:t,:b,:no,0,:c,:l,:r,:title,:intro,'draft',:sub,:disc,:tax,:tot,:u)");$q->execute(array(':t'=>$tenant,':b'=>$branch,':no'=>$quoteNo,':c'=>$client,':l'=>$location,':r'=>$requestId,':title'=>$title,':intro'=>$description!==''?$description:null,':sub'=>$sub,':disc'=>$disc,':tax'=>$tax,':tot'=>$tot,':u'=>$user));$quoteId=(int)$pdo->lastInsertId();$qi=$pdo->prepare("INSERT INTO quote_line_items(quote_id,product_service_id,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,is_optional,sort_order) VALUES(:q,:pid,:name,:d,:qty,:cost,:price,:disc,:tp,:ta,:lt,:opt,:sort)");foreach($normalized as $x){$qi->execute(array(':q'=>$quoteId,':pid'=>$x['pid'],':name'=>$x['name'],':d'=>$x['description']!==''?$x['description']:null,':qty'=>$x['qty'],':cost'=>$x['cost'],':price'=>$x['price'],':disc'=>$x['discount'],':tp'=>$x['tax_percent'],':ta'=>$x['tax_amount'],':lt'=>$x['line_total'],':opt'=>$x['optional'],':sort'=>$x['sort']));}
    }
    if($status==='job_required'){
      $jobNo=rfNext($pdo,$tenant,$branch!==null?$branch:$sessionBranch,'job','jobs','job_no','JOB');$jobStatus=$date!==''?'scheduled':'active';$workflowId=rfDefaultWorkflow($pdo,$tenant,$service);$j=$pdo->prepare("INSERT INTO jobs(tenant_id,branch_id,job_no,client_id,location_id,request_id,product_service_id,workflow_id,title,description,job_type,priority,assignment_mode,assignment_completion_mode,status,start_date,invoicing_preference,subtotal,tax_total,total,created_by) VALUES(:t,:b,:no,:c,:l,:r,:ps,:workflow,:title,:d,'one_off',:pri,:am,'primary_only',:st,:sd,'when_job_complete',0,0,0,:u)");$jobMode=$assignmentMode==='individual'?'single_user':($assignmentMode==='multiple'?'multiple_users':'team');$j->execute(array(':t'=>$tenant,':b'=>$branch,':no'=>$jobNo,':c'=>$client,':l'=>$location,':r'=>$requestId,':ps'=>$service,':workflow'=>$workflowId,':title'=>$title,':d'=>$description!==''?$description:null,':pri'=>$priority,':am'=>$jobMode,':st'=>$jobStatus,':sd'=>$date!==''?$date:null,':u'=>$user));$jobId=(int)$pdo->lastInsertId();$ja=$pdo->prepare("INSERT INTO job_assignments(tenant_id,job_id,user_id,team_id,assignment_role,is_primary_responsible,assigned_by,status) VALUES(:t,:j,:uid,:team,:role,:p,:by,'assigned')");if($assignmentMode==='team'){$ja->execute(array(':t'=>$tenant,':j'=>$jobId,':uid'=>null,':team'=>$assigned['team_id'],':role'=>'primary',':p'=>1,':by'=>$user));}else{foreach($assigned['users'] as $i=>$uid){$ja->execute(array(':t'=>$tenant,':j'=>$jobId,':uid'=>$uid,':team'=>null,':role'=>$i===0?'primary':'technician',':p'=>$i===0?1:0,':by'=>$user));}}rfInitJobWorkflow($pdo,$tenant,$jobId,$workflowId,$assignmentMode==='team'?null:(count($assigned['users'])?(int)$assigned['users'][0]:null),$assignmentMode==='team'?$assigned['team_id']:null);
      $u=$pdo->prepare("UPDATE service_requests SET status='converted' WHERE id=:r AND tenant_id=:t");$u->execute(array(':r'=>$requestId,':t'=>$tenant));$h=$pdo->prepare("INSERT INTO request_status_history(tenant_id,request_id,old_status,new_status,notes,changed_by) VALUES(:t,:r,'job_required','converted',:n,:u)");$h->execute(array(':t'=>$tenant,':r'=>$requestId,':n'=>'Job '.$jobNo.' generated automatically',':u'=>$user));
    }
    $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  $notify=array('in_app'=>0,'email_sent'=>0,'email_failed'=>0,'email_skipped'=>0,'email_messages'=>array(),'smtp_config_id'=>null,'smtp_config_name'=>null);if($needsAssignment){$in=(string)rfP('notify_in_app','1')!=='0';$em=(string)rfP('notify_email','1')!=='0';$entity=$status==='job_required'?'job':'service_request';$rid=$status==='job_required'?$jobId:$requestId;$event=$status==='job_required'?'job.assigned':'request.assigned';$nt=$status==='job_required'?'New Job Assigned':'Assessment Required';$msg=($status==='job_required'?'You have been assigned to job '.$jobNo:'You have been assigned to assess request '.$requestNo).' - '.$title;$notify=rfNotify($pdo,$tenant,$branch!==null?$branch:$sessionBranch,$assigned['users'],$event,$nt,$msg,$entity,$rid,$in,$em);}
  rfLog($pdo,$tenant,$branch!==null?$branch:$sessionBranch,$user,'service_request_created','service_request',$requestId,$client,'Service request created: '.$requestNo,array('status'=>$status,'quote_id'=>$quoteId,'job_id'=>$jobId,'assigned_users'=>$assigned['users'],'notifications'=>$notify));
  if(function_exists('tenantAuditLog'))tenantAuditLog($pdo,'SERVICE_REQUEST_CREATED',$tenant,$branch!==null?$branch:$sessionBranch,$user,'service_request',$requestId,null,array('request_no'=>$requestNo,'status'=>$status,'quote_id'=>$quoteId,'job_id'=>$jobId));
  rfRes(200,true,'Service request '.$requestNo.' created successfully.',array('request_id'=>$requestId,'request_no'=>$requestNo,'quote_id'=>$quoteId,'quote_no'=>$quoteNo,'job_id'=>$jobId,'job_no'=>$jobNo,'notification_summary'=>$notify));
}
rfRes(400,false,'Unsupported request form action.');
}catch(PDOException $e){error_log('request flow PDO '.$e->getMessage());if(isset($e->errorInfo[1])&&(int)$e->errorInfo[1]===1062)rfRes(409,false,'A generated document number already exists. Check Document Number Formatting and retry.');rfRes(500,false,'Unable to create the service request.');}catch(Throwable $e){error_log('request flow '.$e->getMessage());rfRes(500,false,'Unable to create the service request.');}
