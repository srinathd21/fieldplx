<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    require_once __DIR__ . '/../includes/smtp-secret.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('success'=>false,'message'=>'Platform bootstrap failed. Check database/SMTP configuration.'),JSON_UNESCAPED_SLASHES);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

function pu_post(string $key,string $default=''): string
{
    return isset($_POST[$key]) && !is_array($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function pu_json(int $status,bool $success,string $message,array $extra=array()): void
{
    while(ob_get_level()>0)@ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('success'=>$success,'message'=>$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function pu_ip(): string
{
    return isset($_SERVER['REMOTE_ADDR'])?substr((string)$_SERVER['REMOTE_ADDR'],0,80):'';
}

function pu_device(): string
{
    $ua=strtolower(isset($_SERVER['HTTP_USER_AGENT'])?(string)$_SERVER['HTTP_USER_AGENT']:'');
    return (strpos($ua,'mobile')!==false||strpos($ua,'android')!==false||strpos($ua,'iphone')!==false)?'mobile':'desktop';
}

function pu_audit(PDO $pdo,string $action,?int $actorId,?int $objectId,array $old=array(),array $new=array()): void
{
    try{
        $stmt=$pdo->prepare("INSERT INTO audit_logs(tenant_id,branch_id,user_id,platform_user_id,action,object_type,object_id,old_values,new_values,ip_address,device_type,user_agent,created_at) VALUES(NULL,NULL,NULL,:actor,:action,'platform_user',:object_id,:old_values,:new_values,:ip,:device,:ua,NOW())");
        $stmt->execute(array(':actor'=>$actorId,':action'=>$action,':object_id'=>$objectId,':old_values'=>$old?json_encode($old,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,':new_values'=>$new?json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,':ip'=>pu_ip(),':device'=>pu_device(),':ua'=>substr(isset($_SERVER['HTTP_USER_AGENT'])?(string)$_SERVER['HTTP_USER_AGENT']:'',0,500)));
    }catch(Throwable $e){error_log('FieldPlx platform user audit error: '.$e->getMessage());}
}

function pu_find_user(PDO $pdo,int $id): array
{
    $stmt=$pdo->prepare("SELECT * FROM platform_users WHERE id=:id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute(array(':id'=>$id));
    $row=$stmt->fetch();
    if(!$row)pu_json(404,false,'Platform User not found.');
    return $row;
}

function pu_current_user(PDO $pdo): array
{
    if(empty($_SESSION['platform_user_id']))pu_json(401,false,'Your Platform session has expired. Sign in again.');
    $user=pu_find_user($pdo,(int)$_SESSION['platform_user_id']);
    if($user['status']!=='active')pu_json(403,false,'Your Platform account is not active.');
    if(!in_array($user['role_code'],array('super_admin','platform_admin'),true))pu_json(403,false,'You do not have permission to manage Platform Users.');
    return $user;
}

function pu_role_allowed(string $actorRole,string $targetRole): bool
{
    $all=array('super_admin','platform_admin','support_admin','billing_admin','platform_read_only');
    if(!in_array($targetRole,$all,true))return false;
    if($actorRole==='super_admin')return true;
    return $targetRole!=='super_admin';
}

function pu_username_base(string $first,string $last): string
{
    $raw=trim($first.'.'.$last,'. ');
    if(function_exists('iconv')){
        $converted=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$raw);
        if($converted!==false)$raw=$converted;
    }
    $raw=strtolower($raw);
    $raw=preg_replace('/[^a-z0-9]+/','.',(string)$raw);
    $raw=trim((string)$raw,'.');
    if($raw===''||strlen($raw)<3)$raw='fieldplx.user';
    return substr($raw,0,80);
}

function pu_generate_username(PDO $pdo,string $first,string $last): string
{
    $base=pu_username_base($first,$last);
    $candidates=array($base);
    for($i=0;$i<25;$i++)$candidates[]=$base.(string)random_int(1000,9999);
    $stmt=$pdo->prepare("SELECT id FROM platform_users WHERE username=:username LIMIT 1");
    foreach($candidates as $candidate){$candidate=substr($candidate,0,100);$stmt->execute(array(':username'=>$candidate));if(!$stmt->fetchColumn())return $candidate;}
    return substr($base,0,88).'.'.bin2hex(random_bytes(5));
}

function pu_generate_password(): string
{
    $upper='ABCDEFGHJKLMNPQRSTUVWXYZ';$lower='abcdefghijkmnopqrstuvwxyz';$digits='23456789';$symbols='@#$!';$all=$upper.$lower.$digits.$symbols;
    $chars=array($upper[random_int(0,strlen($upper)-1)],$lower[random_int(0,strlen($lower)-1)],$digits[random_int(0,strlen($digits)-1)],$symbols[random_int(0,strlen($symbols)-1)]);
    for($i=0;$i<8;$i++)$chars[]=$all[random_int(0,strlen($all)-1)];
    for($i=count($chars)-1;$i>0;$i--){$j=random_int(0,$i);$t=$chars[$i];$chars[$i]=$chars[$j];$chars[$j]=$t;}
    return implode('',$chars);
}

function pu_secret_key(): string
{
    $key='';
    if(defined('FIELDPLX_SMTP_ENCRYPTION_KEY'))$key=trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY);
    if($key===''){ $env=getenv('FIELDPLX_SMTP_ENCRYPTION_KEY'); if($env!==false)$key=trim((string)$env); }
    if($key===''){ $env=getenv('APP_KEY'); if($env!==false)$key=trim((string)$env); }
    if($key===''||$key==='CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY'||strlen($key)<32)throw new RuntimeException('Platform SMTP encryption key is not configured correctly.');
    return hash('sha256',$key,true);
}

function pu_decrypt_password(?string $stored): string
{
    $stored=(string)$stored;if($stored===''||strpos($stored,'v1:')!==0)return '';
    $raw=base64_decode(substr($stored,3),true);if($raw===false||strlen($raw)<=16)return '';
    $plain=openssl_decrypt(substr($raw,16),'AES-256-CBC',pu_secret_key(),OPENSSL_RAW_DATA,substr($raw,0,16));
    return $plain===false?'':$plain;
}

function pu_autoload(): string
{
    $projectRoot=dirname(dirname(__DIR__));
    $paths=array($projectRoot.'/vendor/autoload.php',dirname(__DIR__).'/vendor/autoload.php',__DIR__.'/../../vendor/autoload.php',__DIR__.'/../vendor/autoload.php');
    foreach($paths as $p)if(is_file($p))return $p;
    return '';
}

function pu_load_phpmailer(): void
{
    if(class_exists('PHPMailer\\PHPMailer\\PHPMailer',false))return;
    $path=pu_autoload();if($path==='')throw new RuntimeException('Composer vendor/autoload.php was not found.');
    require_once $path;
    if(!class_exists('PHPMailer\\PHPMailer\\PHPMailer'))throw new RuntimeException('PHPMailer could not be loaded.');
}

function pu_platform_smtp(PDO $pdo): ?array
{
    $stmt=$pdo->query("SELECT * FROM smtp_configurations WHERE scope_type='platform' AND is_active=1 ORDER BY is_default DESC,id DESC LIMIT 1");
    $row=$stmt->fetch();return $row?$row:null;
}

function pu_html($value): string
{
    return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
}

function pu_login_url(): string
{
    if(empty($_SERVER['HTTP_HOST']))return '';
    $scheme=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')?'https':'http';
    $script=isset($_SERVER['SCRIPT_NAME'])?(string)$_SERVER['SCRIPT_NAME']:'';
    $platformPath=rtrim(str_replace('\\','/',dirname(dirname($script))),'/');
    return $scheme.'://'.$_SERVER['HTTP_HOST'].($platformPath!==''?$platformPath:'').'/login.php';
}


function pu_is_local_request(): bool
{
    $host=isset($_SERVER['HTTP_HOST'])?strtolower((string)$_SERVER['HTTP_HOST']):'';
    $host=preg_replace('/:\\d+$/','',$host);
    return in_array($host,array('localhost','127.0.0.1','::1'),true);
}

function pu_ca_bundle(): string
{
    $candidates=array();
    $openssl=trim((string)ini_get('openssl.cafile'));
    $curl=trim((string)ini_get('curl.cainfo'));
    if($openssl!=='')$candidates[]=$openssl;
    if($curl!=='')$candidates[]=$curl;
    if(function_exists('openssl_get_cert_locations')){
        $loc=openssl_get_cert_locations();
        foreach(array('ini_cafile','default_cert_file') as $key){if(!empty($loc[$key]))$candidates[]=(string)$loc[$key];}
    }
    $phpDir=dirname((string)PHP_BINARY);
    $candidates[]=dirname(__DIR__).'/includes/cacert.pem';
    $candidates[]=dirname(__DIR__).'/cacert.pem';
    $candidates[]=$phpDir.'/extras/ssl/cacert.pem';
    $candidates[]=dirname($phpDir).'/apache/bin/curl-ca-bundle.crt';
    $candidates[]=dirname($phpDir).'/apache/bin/cacert.pem';
    foreach(array_unique($candidates) as $candidate){$candidate=trim((string)$candidate);if($candidate!==''&&is_file($candidate)&&is_readable($candidate))return $candidate;}
    return '';
}

function pu_is_certificate_error(Throwable $e): bool
{
    $m=strtolower($e->getMessage());
    return strpos($m,'certificate verify failed')!==false||strpos($m,'unable to get local issuer certificate')!==false||strpos($m,'self signed certificate')!==false;
}

function pu_smtp_friendly_error(Throwable $e,array $smtp): string
{
    $message=trim($e->getMessage());$lower=strtolower($message);$host=isset($smtp['host'])?(string)$smtp['host']:'';$port=isset($smtp['port'])?(int)$smtp['port']:0;
    if(pu_is_certificate_error($e))return 'TLS certificate verification failed. Local PHP/OpenSSL has no usable CA bundle. The Platform Users API automatically retries this only on localhost; live hosting keeps certificate verification enabled.';
    if(strpos($lower,'could not authenticate')!==false||strpos($lower,'authentication failed')!==false||strpos($lower,'username and password not accepted')!==false||strpos($lower,'535')!==false||strpos($lower,'5.7.8')!==false)return 'SMTP authentication failed. If this is Gmail, save a Google App Password as the SMTP password instead of the normal Gmail password.';
    if(strpos($lower,'timed out')!==false||strpos($lower,'connection refused')!==false||strpos($lower,'failed to open stream')!==false)return 'Unable to connect to '.$host.':'.$port.'. Check internet access, firewall/antivirus rules, and outbound SMTP port '.$port.'.';
    if(strpos($lower,'getaddrinfo')!==false||strpos($lower,'php_network_getaddresses')!==false||strpos($lower,'name or service not known')!==false)return 'SMTP DNS lookup failed for '.$host.'. Check the SMTP host name and internet/DNS connection.';
    if(strpos($lower,'decrypt')!==false)return 'The saved Platform SMTP password cannot be decrypted. Keep the same smtp-secret.php key and re-enter/save the SMTP password once.';
    if(strpos($lower,'sender address rejected')!==false||strpos($lower,'553')!==false||strpos($lower,'550')!==false)return 'SMTP rejected the From Email. Use the authenticated Gmail address or a verified sending alias.';
    if(strpos($lower,'rate')!==false||strpos($lower,'quota')!==false||strpos($lower,'421')!==false||strpos($lower,'452')!==false)return 'SMTP provider temporarily rejected the message because of a sending rate/quota limit.';
    return $message!==''?$message:'Unknown SMTP error.';
}

function pu_apply_tls_options($mail,bool $allowLocalFallback=false): void
{
    $ca=pu_ca_bundle();
    if($allowLocalFallback&&pu_is_local_request()){
        $mail->SMTPOptions=array('ssl'=>array('verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true));
        return;
    }
    if($ca!==''){
        $mail->SMTPOptions=array('ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'cafile'=>$ca));
        return;
    }
    $mail->SMTPOptions=array('ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false));
}

function pu_send_credentials(PDO $pdo,array $user,string $temporaryPassword,bool $isReset=false,bool $allowLocalTlsFallback=false): array
{
    $smtp=pu_platform_smtp($pdo);
    if(!$smtp)return array('sent'=>false,'message'=>'No active Platform SMTP configuration is available.');
    try{
        pu_load_phpmailer();
        $smtpPassword=pu_decrypt_password(isset($smtp['password_encrypted'])?(string)$smtp['password_encrypted']:'');
        $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host=trim((string)$smtp['host']);
        $mail->Port=(int)$smtp['port'];
        $mail->Timeout=25;
        if(property_exists($mail,'Timelimit'))$mail->Timelimit=25;
        $mail->SMTPDebug=0;
        $mail->SMTPKeepAlive=false;

        $username=trim((string)$smtp['username']);
        $mail->SMTPAuth=$username!=='';
        if($mail->SMTPAuth){
            if($smtpPassword==='')throw new RuntimeException('Stored Platform SMTP password could not be decrypted.');
            $mail->Username=$username;
            $mail->Password=$smtpPassword;
        }

        $enc=strtolower(trim((string)$smtp['encryption']));
        if($mail->Port===587)$enc='tls';
        elseif($mail->Port===465)$enc='ssl';

        if($enc==='ssl'){
            $mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPAutoTLS=false;
        }elseif($enc==='tls'||$enc==='starttls'){
            $mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS=true;
        }else{
            $mail->SMTPSecure='';
            $mail->SMTPAutoTLS=false;
        }

        pu_apply_tls_options($mail,$allowLocalTlsFallback);

        $fromEmail=trim((string)$smtp['from_email']);
        if(!filter_var($fromEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Platform SMTP From Email is invalid.');
        $fromName=trim((string)$smtp['from_name']);
        if($fromName==='')$fromName='FieldPlx';
        $mail->CharSet='UTF-8';
        $mail->setFrom($fromEmail,$fromName);
        $reply=trim((string)$smtp['reply_to_email']);
        if($reply!==''&&filter_var($reply,FILTER_VALIDATE_EMAIL))$mail->addReplyTo($reply);

        $recipient=trim((string)$user['email']);
        if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Platform User email address is invalid.');
        $fullName=trim((string)$user['first_name'].' '.(string)$user['last_name']);
        $mail->addAddress($recipient,$fullName);

        $loginUrl=pu_login_url();
        $safeName=pu_html($fullName!==''?$fullName:'Platform User');
        $safeUsername=pu_html($user['username']);
        $safePassword=pu_html($temporaryPassword);
        $safeRole=pu_html(ucwords(str_replace('_',' ',(string)$user['role_code'])));
        $safeLogin=pu_html($loginUrl);
        $subject=$isReset?'FieldPlx Platform - Login credentials reset':'Welcome to FieldPlx Platform - Your login is ready';
        $heading=$isReset?'Your Platform login was reset':'Welcome to FieldPlx Platform';
        $intro=$isReset?'A new temporary password has been generated for your FieldPlx Platform account.':'Your FieldPlx Platform account has been created successfully.';
        $button=$loginUrl!==''?'<div style="text-align:center;margin:24px 0 4px"><a href="'.$safeLogin.'" style="display:inline-block;padding:12px 22px;border-radius:9px;background:#6d28d9;color:#fff;text-decoration:none;font-size:14px;font-weight:700">Open FieldPlx Platform</a></div>':'';
        $html='<!DOCTYPE html><html><body style="margin:0;background:#f5f2fb;font-family:Arial,Helvetica,sans-serif;color:#211c32"><table width="100%" cellpadding="0" cellspacing="0" style="padding:28px 12px;background:#f5f2fb"><tr><td align="center"><table width="620" cellpadding="0" cellspacing="0" style="width:100%;max-width:620px;background:#fff;border:1px solid #e3dcf3;border-radius:16px;overflow:hidden;box-shadow:0 12px 34px rgba(37,29,80,.08)"><tr><td style="padding:22px 26px;background:#1c2250"><div style="display:inline-block;padding:8px 10px;border-radius:9px;background:#8b5cf6;color:#fff;font-size:14px;font-weight:800">FP</div><div style="margin-top:14px;color:#fff;font-size:23px;font-weight:800">'.$heading.'</div><div style="margin-top:5px;color:#d9d1f7;font-size:13px;line-height:1.6">'.$intro.'</div></td></tr><tr><td style="padding:28px 26px"><div style="font-size:18px;font-weight:800">Hello '.$safeName.',</div><div style="margin-top:12px;color:#6f677d;font-size:14px;line-height:1.75">Use the credentials below to access the FieldPlx Platform administration area.</div><div style="margin-top:22px;padding:18px;border:1px solid #e4ddf3;border-radius:12px;background:#fbf9ff"><div style="margin-bottom:10px;color:#6d28d9;font-size:12px;font-weight:800;text-transform:uppercase">Platform Account</div><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:9px 0;color:#7c738d;font-size:13px">Username</td><td style="padding:9px 0;text-align:right;font-size:13px;font-weight:700">'.$safeUsername.'</td></tr><tr><td style="padding:9px 0;color:#7c738d;font-size:13px">Temporary Password</td><td style="padding:9px 0;text-align:right;font-size:13px;font-weight:700">'.$safePassword.'</td></tr><tr><td style="padding:9px 0;color:#7c738d;font-size:13px">Role</td><td style="padding:9px 0;text-align:right;font-size:13px;font-weight:700">'.$safeRole.'</td></tr></table></div><div style="margin-top:16px;padding:13px;border-radius:10px;background:#fff7ed;color:#9a3412;font-size:12px;line-height:1.6"><strong>Security:</strong> Keep this temporary password private and change it after you sign in.</div>'.$button.'<div style="margin-top:22px;color:#8b8498;font-size:11px;line-height:1.7">This is an automated account notification from FieldPlx.</div></td></tr></table></td></tr></table></body></html>';
        $mail->isHTML(true);
        $mail->Subject=$subject;
        $mail->Body=$html;
        $mail->AltBody="Hello {$fullName}

Username: {$user['username']}
Temporary Password: {$temporaryPassword}
Role: ".ucwords(str_replace('_',' ',(string)$user['role_code'])).($loginUrl!==''?"
Login: {$loginUrl}":'')."

Keep this temporary password private.";
        $mail->send();
        return array('sent'=>true,'message'=>'Login credentials emailed successfully.','tls_fallback'=>$allowLocalTlsFallback);
    }catch(Throwable $e){
        if(!$allowLocalTlsFallback&&pu_is_local_request()&&pu_is_certificate_error($e)){
            return pu_send_credentials($pdo,$user,$temporaryPassword,$isReset,true);
        }
        $friendly=pu_smtp_friendly_error($e,$smtp);
        error_log('FieldPlx Platform User credential email error: '.$e->getMessage());
        return array('sent'=>false,'message'=>$friendly,'tls_fallback'=>$allowLocalTlsFallback);
    }
}

if($_SERVER['REQUEST_METHOD']!=='POST')pu_json(405,false,'Method not allowed.');
$current=pu_current_user($pdo);
$csrf=pu_post('csrf_token');
if(empty($_SESSION['platform_users_csrf'])||!is_string($_SESSION['platform_users_csrf'])||$csrf===''||!hash_equals($_SESSION['platform_users_csrf'],$csrf))pu_json(419,false,'Your form session expired. Refresh the page and try again.');
$action=pu_post('action');

try{
    if($action==='save_user'){
        $id=(int)pu_post('id','0');$first=pu_post('first_name');$last=pu_post('last_name');$email=strtolower(pu_post('email'));$phone=pu_post('phone');$jobTitle=pu_post('job_title');$role=pu_post('role_code','platform_read_only');$status=pu_post('status','active');
        if($first===''||strlen($first)>120)pu_json(422,false,'First name is required and must be 120 characters or less.');
        if(strlen($last)>120)pu_json(422,false,'Last name must be 120 characters or less.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)pu_json(422,false,'Enter a valid email address.');
        if(strlen($phone)>50)pu_json(422,false,'Phone number is too long.');
        if(strlen($jobTitle)>120)pu_json(422,false,'Job title must be 120 characters or less.');
        if(!pu_role_allowed((string)$current['role_code'],$role))pu_json(403,false,'You cannot assign the selected Platform role.');
        if(!in_array($status,array('active','inactive','suspended'),true))pu_json(422,false,'Invalid Platform User status.');
        $dup=$pdo->prepare("SELECT id FROM platform_users WHERE email=:email AND deleted_at IS NULL AND id<>:id LIMIT 1");$dup->execute(array(':email'=>$email,':id'=>$id));if($dup->fetchColumn())pu_json(409,false,'This email address is already used by another Platform User.');

        if($id>0){
            $existing=pu_find_user($pdo,$id);
            if($existing['role_code']==='super_admin'&&$current['role_code']!=='super_admin')pu_json(403,false,'Only a Super Admin can edit another Super Admin.');
            if($id===(int)$current['id']&&$status!=='active')pu_json(409,false,'You cannot deactivate or suspend your own Platform account.');
            if($existing['role_code']==='super_admin' && ($role!=='super_admin'||$status!=='active')){
                $count=$pdo->query("SELECT COUNT(*) FROM platform_users WHERE role_code='super_admin' AND status='active' AND deleted_at IS NULL")->fetchColumn();
                if((int)$count<=1)pu_json(409,false,'You cannot demote or deactivate the last active Super Admin.');
            }
            $stmt=$pdo->prepare("UPDATE platform_users SET first_name=:first,last_name=:last,email=:email,phone=:phone,job_title=:job_title,role_code=:role,status=:status WHERE id=:id AND deleted_at IS NULL");
            $stmt->execute(array(':first'=>$first,':last'=>$last===''?null:$last,':email'=>$email,':phone'=>$phone===''?null:$phone,':job_title'=>$jobTitle===''?null:$jobTitle,':role'=>$role,':status'=>$status,':id'=>$id));
            pu_audit($pdo,'PLATFORM_USER_UPDATED',(int)$current['id'],$id,array('first_name'=>$existing['first_name'],'last_name'=>$existing['last_name'],'email'=>$existing['email'],'phone'=>$existing['phone'],'job_title'=>$existing['job_title'],'role_code'=>$existing['role_code'],'status'=>$existing['status']),array('first_name'=>$first,'last_name'=>$last,'email'=>$email,'phone'=>$phone,'job_title'=>$jobTitle,'role_code'=>$role,'status'=>$status));
            pu_json(200,true,'Platform User updated successfully.',array('email_sent'=>null));
        }

        $username=pu_generate_username($pdo,$first,$last);$temporaryPassword=pu_generate_password();$hash=password_hash($temporaryPassword,PASSWORD_DEFAULT);if($hash===false)pu_json(500,false,'Unable to secure the generated Platform User password.');
        $pdo->beginTransaction();
        $stmt=$pdo->prepare("INSERT INTO platform_users(first_name,last_name,username,email,phone,password_hash,avatar_path,job_title,role_code,status,last_login_at,created_at,updated_at,deleted_at) VALUES(:first,:last,:username,:email,:phone,:hash,NULL,:job_title,:role,:status,NULL,NOW(),NULL,NULL)");
        $stmt->execute(array(':first'=>$first,':last'=>$last===''?null:$last,':username'=>$username,':email'=>$email,':phone'=>$phone===''?null:$phone,':hash'=>$hash,':job_title'=>$jobTitle===''?null:$jobTitle,':role'=>$role,':status'=>$status));
        $newId=(int)$pdo->lastInsertId();$created=array('id'=>$newId,'first_name'=>$first,'last_name'=>$last,'username'=>$username,'email'=>$email,'phone'=>$phone,'job_title'=>$jobTitle,'role_code'=>$role,'status'=>$status);
        $emailResult=pu_send_credentials($pdo,$created,$temporaryPassword,false);
        if(!$emailResult['sent']){
            $pdo->rollBack();
            pu_json(502,false,'Platform User creation was cancelled because the credentials email could not be sent. '.$emailResult['message'],array('email_sent'=>false,'email_message'=>$emailResult['message']));
        }
        $pdo->commit();
        pu_audit($pdo,'PLATFORM_USER_CREATED',(int)$current['id'],$newId,array(),array('username'=>$username,'email'=>$email,'role_code'=>$role,'status'=>$status,'email_sent'=>true,'localhost_tls_fallback'=>!empty($emailResult['tls_fallback'])));
        $message='Platform User created successfully. Username: '.$username.'. Login credentials sent to '.$email.'.';
        pu_json(201,true,$message,array('user_id'=>$newId,'username'=>$username,'email_sent'=>true,'email_message'=>$emailResult['message'],'localhost_tls_fallback'=>!empty($emailResult['tls_fallback'])));
    }

    if($action==='change_status'){
        $id=(int)pu_post('id','0');$status=pu_post('status');if($id<=0)pu_json(422,false,'Invalid Platform User.');if(!in_array($status,array('active','inactive','suspended'),true))pu_json(422,false,'Invalid Platform User status.');
        $user=pu_find_user($pdo,$id);if($id===(int)$current['id']&&$status!=='active')pu_json(409,false,'You cannot deactivate or suspend your own Platform account.');if($user['role_code']==='super_admin'&&$current['role_code']!=='super_admin')pu_json(403,false,'Only a Super Admin can change a Super Admin account.');
        if($user['role_code']==='super_admin'&&$status!=='active'){ $count=$pdo->query("SELECT COUNT(*) FROM platform_users WHERE role_code='super_admin' AND status='active' AND deleted_at IS NULL")->fetchColumn();if((int)$count<=1)pu_json(409,false,'You cannot deactivate the last active Super Admin.'); }
        $stmt=$pdo->prepare("UPDATE platform_users SET status=:status WHERE id=:id AND deleted_at IS NULL");$stmt->execute(array(':status'=>$status,':id'=>$id));pu_audit($pdo,'PLATFORM_USER_STATUS_CHANGED',(int)$current['id'],$id,array('status'=>$user['status']),array('status'=>$status));pu_json(200,true,$status==='active'?'Platform User activated successfully.':'Platform User status updated successfully.');
    }

    if($action==='reset_credentials'){
        $id=(int)pu_post('id','0');if($id<=0)pu_json(422,false,'Invalid Platform User.');$user=pu_find_user($pdo,$id);if($id===(int)$current['id'])pu_json(409,false,'Use your account password-change option to change your own password.');if($user['role_code']==='super_admin'&&$current['role_code']!=='super_admin')pu_json(403,false,'Only a Super Admin can reset a Super Admin account.');
        $temporaryPassword=pu_generate_password();$hash=password_hash($temporaryPassword,PASSWORD_DEFAULT);if($hash===false)pu_json(500,false,'Unable to secure the generated password.');
        $pdo->beginTransaction();$stmt=$pdo->prepare("UPDATE platform_users SET password_hash=:hash WHERE id=:id AND deleted_at IS NULL");$stmt->execute(array(':hash'=>$hash,':id'=>$id));$emailResult=pu_send_credentials($pdo,$user,$temporaryPassword,true);
        if(!$emailResult['sent']){$pdo->rollBack();pu_json(502,false,'Password reset was cancelled because the credentials email could not be sent. '.$emailResult['message']);}
        $pdo->commit();pu_audit($pdo,'PLATFORM_USER_CREDENTIALS_RESET',(int)$current['id'],$id,array(),array('username'=>$user['username'],'email'=>$user['email'],'email_sent'=>true));pu_json(200,true,'New temporary login credentials were generated and emailed to '.$user['email'].'.');
    }

    pu_json(400,false,'Invalid action.');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('FieldPlx Platform Users API Error: '.$e->getMessage());pu_json(500,false,'Unable to complete the Platform User action. Check the PHP error log if the problem continues.');}
