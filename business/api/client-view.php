<?php
/* FieldPlx Client View API - Version 1.0.0 - 2026-09-08 */
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) require_once __DIR__ . '/../includes/audit.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function cvr($status,$success,$message,$extra=array())
{
    while(ob_get_level()>0) @ob_end_clean();
    http_response_code((int)$status);
    echo json_encode(array_merge(array('success'=>(bool)$success,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function cvp($key,$default=''){ return isset($_POST[$key])&&!is_array($_POST[$key])?trim((string)$_POST[$key]):$default; }
function cvt(PDO $pdo,$table){ $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");$q->execute(array(':t'=>$table));return (int)$q->fetchColumn()>0; }
function cvClient(PDO $pdo,$tenant,$id){ $q=$pdo->prepare("SELECT * FROM clients WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");$q->execute(array(':id'=>$id,':t'=>$tenant));$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)cvr(404,false,'Client not found.');return $r; }
function cvAudit(PDO $pdo,$tenant,$branch,$user,$action,$client,$old,$new){ if(function_exists('tenantAuditLog')){try{tenantAuditLog($pdo,$action,$tenant,$branch,$user,'client',$client,$old,$new);}catch(Throwable $e){error_log('client view audit '.$e->getMessage());}} }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cvr(405,false,'Method not allowed.');
$sessionToken=isset($_SESSION['clients_csrf_token'])?(string)$_SESSION['clients_csrf_token']:'';
$posted=cvp('csrf_token');
if($sessionToken===''||$posted===''||!hash_equals($sessionToken,$posted)) cvr(419,false,'Your session expired. Refresh the page and try again.');
$tenantId=!empty($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;
$branchId=!empty($_SESSION['branch_id'])?(int)$_SESSION['branch_id']:0;
$userId=!empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:(!empty($_SESSION['id'])?(int)$_SESSION['id']:0);
if($tenantId<=0)cvr(401,false,'Tenant session is missing.');
$action=cvp('action');
$clientId=(int)cvp('client_id','0');
if($clientId<=0)cvr(422,false,'Invalid client.');
$client=cvClient($pdo,$tenantId,$clientId);

try{
    if($action==='save_notes'){
        $notes=cvp('notes');
        if(mb_strlen($notes,'UTF-8')>5000)cvr(422,false,'Notes cannot exceed 5000 characters.');
        $old=array('notes'=>isset($client['notes'])?$client['notes']:null);
        $q=$pdo->prepare("UPDATE clients SET notes=:n,updated_at=NOW() WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL");
        $q->execute(array(':n'=>$notes!==''?$notes:null,':id'=>$clientId,':t'=>$tenantId));
        cvAudit($pdo,$tenantId,$branchId,$userId,'CLIENT_NOTES_UPDATED',$clientId,$old,array('notes'=>$notes));
        cvr(200,true,'Client note saved successfully.');
    }

    if($action==='save_tags'){
        if(!cvt($pdo,'client_tags')||!cvt($pdo,'client_tag_assignments'))cvr(500,false,'Client tag tables are not installed. Run migration_client_tags.sql once.');
        $raw=cvp('tag_ids','[]');$ids=json_decode($raw,true);if(!is_array($ids))cvr(422,false,'Invalid tag selection.');
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),function($v){return $v>0;})));
        $oldQ=$pdo->prepare("SELECT tag_id FROM client_tag_assignments WHERE tenant_id=:t AND client_id=:c ORDER BY tag_id");$oldQ->execute(array(':t'=>$tenantId,':c'=>$clientId));$oldIds=array_map('intval',$oldQ->fetchAll(PDO::FETCH_COLUMN));
        if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT id FROM client_tags WHERE tenant_id=? AND is_active=1 AND id IN ($ph)");$q->execute(array_merge(array($tenantId),$ids));$valid=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));sort($valid);$check=$ids;sort($check);if($valid!==$check)cvr(422,false,'One or more selected tags are invalid.');}
        $pdo->beginTransaction();
        try{$pdo->prepare("DELETE FROM client_tag_assignments WHERE tenant_id=:t AND client_id=:c")->execute(array(':t'=>$tenantId,':c'=>$clientId));if($ids){$ins=$pdo->prepare("INSERT INTO client_tag_assignments(tenant_id,client_id,tag_id,created_by) VALUES(:t,:c,:tag,:u)");foreach($ids as $tagId)$ins->execute(array(':t'=>$tenantId,':c'=>$clientId,':tag'=>$tagId,':u'=>$userId>0?$userId:null));}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        cvAudit($pdo,$tenantId,$branchId,$userId,'CLIENT_TAGS_UPDATED',$clientId,array('tag_ids'=>$oldIds),array('tag_ids'=>$ids));
        cvr(200,true,'Client tags updated successfully.');
    }

    if($action==='create_tag'){
        if(!cvt($pdo,'client_tags')||!cvt($pdo,'client_tag_assignments'))cvr(500,false,'Client tag tables are not installed. Run migration_client_tags.sql once.');
        $name=cvp('name');if($name==='')cvr(422,false,'Enter a tag name.');if(mb_strlen($name,'UTF-8')>80)cvr(422,false,'Tag name must be 80 characters or less.');
        $q=$pdo->prepare("SELECT id,name,COALESCE(color,'') color FROM client_tags WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) LIMIT 1");$q->execute(array(':t'=>$tenantId,':n'=>$name));$tag=$q->fetch(PDO::FETCH_ASSOC);
        if($tag){$pdo->prepare("UPDATE client_tags SET is_active=1,updated_at=NOW() WHERE id=:id AND tenant_id=:t")->execute(array(':id'=>(int)$tag['id'],':t'=>$tenantId));}
        else{$q=$pdo->prepare("INSERT INTO client_tags(tenant_id,name,color,is_active,created_by,created_at,updated_at) VALUES(:t,:n,NULL,1,:u,NOW(),NOW())");$q->execute(array(':t'=>$tenantId,':n'=>$name,':u'=>$userId>0?$userId:null));$id=(int)$pdo->lastInsertId();$tag=array('id'=>$id,'name'=>$name,'color'=>'');}
        $pdo->prepare("INSERT IGNORE INTO client_tag_assignments(tenant_id,client_id,tag_id,created_by) VALUES(:t,:c,:tag,:u)")->execute(array(':t'=>$tenantId,':c'=>$clientId,':tag'=>(int)$tag['id'],':u'=>$userId>0?$userId:null));
        cvAudit($pdo,$tenantId,$branchId,$userId,'CLIENT_TAG_CREATED_AND_ASSIGNED',$clientId,null,array('tag_id'=>(int)$tag['id'],'tag_name'=>$tag['name']));
        cvr(200,true,'Tag created and added to client.',array('tag'=>$tag));
    }

    cvr(400,false,'Invalid action.');
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('FieldPlx client view API '.$e->getMessage());cvr(500,false,'Unable to complete the request: '.$e->getMessage());}
