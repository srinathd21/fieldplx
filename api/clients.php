<?php
/**
 * FieldPlx Mobile REST API - Clients List
 * Upload as: /public_html/api/clients.php
 * PHP 7.2+ / MySQLi
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
const FIELDPLX_API_SECRET = 'coreplx';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-FieldPlx-Token');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
function apiResponse(int $status, array $payload): void { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function apiBase64UrlDecode(string $value): string { $r=strlen($value)%4; if($r){$value.=str_repeat('=',4-$r);} $d=base64_decode(strtr($value,'-_','+/'),true); return $d===false?'':$d; }
function apiAuthorizationHeader(): string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    if (function_exists('getallheaders')) { $h=getallheaders(); if(is_array($h)){ foreach($h as $k=>$v){ if(strtolower((string)$k)==='authorization') return trim((string)$v); } } }
    return '';
}
function apiBearerToken(): string {
    $h=apiAuthorizationHeader();
    if($h!=='' && preg_match('/^Bearer\s+(.+)$/i',$h,$m)) return trim((string)$m[1]);
    if(!empty($_SERVER['HTTP_X_FIELDPLX_TOKEN'])) return trim((string)$_SERVER['HTTP_X_FIELDPLX_TOKEN']);
    if(function_exists('getallheaders')) { $headers=getallheaders(); if(is_array($headers)){ foreach($headers as $k=>$v){ if(strtolower((string)$k)==='x-fieldplx-token') return trim((string)$v); } } }
    return '';
}
function apiValidateToken(string $token): array {
    $parts=explode('.',$token);
    if(count($parts)!==3) apiResponse(401,array('success'=>false,'code'=>'invalid_token','message'=>'Invalid access token.'));
    list($eh,$ep,$es)=$parts;
    $header=json_decode(apiBase64UrlDecode($eh),true);
    $payload=json_decode(apiBase64UrlDecode($ep),true);
    $sig=apiBase64UrlDecode($es);
    if(!is_array($header)||!is_array($payload)||!isset($header['alg'])||$header['alg']!=='HS256') apiResponse(401,array('success'=>false,'code'=>'invalid_token','message'=>'Invalid access token.'));
    $expected=hash_hmac('sha256',$eh.'.'.$ep,FIELDPLX_API_SECRET,true);
    if($sig===''||!hash_equals($expected,$sig)) apiResponse(401,array('success'=>false,'code'=>'invalid_token','message'=>'Invalid access token signature.'));
    $now=time();
    if(!isset($payload['exp'])||(int)$payload['exp']<=$now) apiResponse(401,array('success'=>false,'code'=>'token_expired','message'=>'Access token has expired.'));
    if(!isset($payload['iss'])||$payload['iss']!=='FieldPlx'||!isset($payload['aud'])||$payload['aud']!=='FieldPlx-Mobile'||empty($payload['sub'])||empty($payload['tenant_id'])) apiResponse(401,array('success'=>false,'code'=>'invalid_token','message'=>'Invalid access token.'));
    return $payload;
}
function apiFetchAll(mysqli_stmt $stmt): array {
    $rows=array();
    if(method_exists($stmt,'get_result')){ $r=$stmt->get_result(); if($r){ while($row=$r->fetch_assoc()) $rows[]=$row; return $rows; } }
    $meta=$stmt->result_metadata(); if(!$meta) return $rows;
    $row=array(); $bind=array(); while($f=$meta->fetch_field()){ $row[$f->name]=null; $bind[]=&$row[$f->name]; }
    call_user_func_array(array($stmt,'bind_result'),$bind);
    while($stmt->fetch()){ $copy=array(); foreach($row as $k=>$v)$copy[$k]=$v; $rows[]=$copy; }
    return $rows;
}
function apiFetchOne(mysqli_stmt $stmt){ $rows=apiFetchAll($stmt); return !empty($rows)?$rows[0]:null; }
function apiBindParams(mysqli_stmt $stmt,string $types,array &$params): bool { if($types===''||empty($params)) return true; $args=array($types); foreach($params as $k=>$v)$args[]=&$params[$k]; return (bool)call_user_func_array(array($stmt,'bind_param'),$args); }
function apiUserPermissions(mysqli $conn,int $tenantId,int $userId,int $roleId): array {
    $permissions=array();
    if($roleId>0){
        $stmt=$conn->prepare("SELECT DISTINCT p.code FROM role_permissions rp INNER JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND (rp.tenant_id=? OR rp.tenant_id IS NULL)");
        if($stmt){ $stmt->bind_param('ii',$roleId,$tenantId); if($stmt->execute()){ foreach(apiFetchAll($stmt) as $row){ if(!empty($row['code'])) $permissions[(string)$row['code']]=true; } } $stmt->close(); }
    }
    $stmt=$conn->prepare("SELECT p.code,up.effect FROM user_permissions up INNER JOIN permissions p ON p.id=up.permission_id WHERE up.tenant_id=? AND up.user_id=?");
    if($stmt){ $stmt->bind_param('ii',$tenantId,$userId); if($stmt->execute()){ foreach(apiFetchAll($stmt) as $row){ $code=(string)($row['code']??''); if($code==='') continue; if(($row['effect']??'allow')==='deny') unset($permissions[$code]); else $permissions[$code]=true; } } $stmt->close(); }
    $list=array_keys($permissions); sort($list); return $list;
}
if($_SERVER['REQUEST_METHOD']!=='GET') apiResponse(405,array('success'=>false,'code'=>'method_not_allowed','message'=>'Only GET requests are allowed.'));
$token=apiBearerToken();
if($token==='') apiResponse(401,array('success'=>false,'code'=>'missing_token','message'=>'Access token is required.'));
$claims=apiValidateToken($token); $userId=(int)$claims['sub']; $tenantId=(int)$claims['tenant_id'];
try {
    $stmt=$conn->prepare("SELECT u.id,u.tenant_id,u.role_id,u.status AS user_status,u.deleted_at AS user_deleted_at,t.status AS tenant_status,t.trial_ends_at,t.deleted_at AS tenant_deleted_at,t.currency_code,r.code AS role_code,r.is_active AS role_is_active FROM users u INNER JOIN tenants t ON t.id=u.tenant_id LEFT JOIN roles r ON r.id=u.role_id AND (r.tenant_id=u.tenant_id OR r.tenant_id IS NULL) WHERE u.id=? AND u.tenant_id=? LIMIT 1");
    if(!$stmt) throw new Exception($conn->error); $stmt->bind_param('ii',$userId,$tenantId); if(!$stmt->execute()) throw new Exception($stmt->error); $account=apiFetchOne($stmt); $stmt->close();
    if(!$account) apiResponse(401,array('success'=>false,'code'=>'user_not_found','message'=>'User not found.'));
    if(!empty($account['user_deleted_at'])||strtolower((string)$account['user_status'])!=='active') apiResponse(403,array('success'=>false,'code'=>'user_inactive','message'=>'This user account is not active.'));
    if(!empty($account['tenant_deleted_at'])) apiResponse(403,array('success'=>false,'code'=>'workspace_unavailable','message'=>'This workspace is unavailable.'));
    $tenantStatus=strtolower((string)$account['tenant_status']);
    if(in_array($tenantStatus,array('inactive','suspended'),true)) apiResponse(403,array('success'=>false,'code'=>'workspace_inactive','message'=>'This workspace is inactive.'));
    if($tenantStatus==='trial'&&!empty($account['trial_ends_at'])&&strtotime((string)$account['trial_ends_at'])<time()) apiResponse(403,array('success'=>false,'code'=>'trial_expired','message'=>'This workspace trial has expired.'));
    if(!empty($account['role_id'])&&isset($account['role_is_active'])&&(int)$account['role_is_active']!==1) apiResponse(403,array('success'=>false,'code'=>'role_inactive','message'=>'The assigned role is inactive.'));
    $permissions=apiUserPermissions($conn,$tenantId,$userId,(int)$account['role_id']);
    $roleCode=(string)($account['role_code']??'');
    if(!in_array('clients.view',$permissions,true)&&!in_array($roleCode,array('owner','admin'),true)) apiResponse(403,array('success'=>false,'code'=>'permission_denied','message'=>'You do not have permission to view clients.'));

    $search=isset($_GET['search'])&&!is_array($_GET['search'])?trim((string)$_GET['search']):'';
    $typeFilter=isset($_GET['type'])&&!is_array($_GET['type'])?trim((string)$_GET['type']):'';
    $statusFilter=isset($_GET['status'])&&!is_array($_GET['status'])?trim((string)$_GET['status']):'';
    $sort=isset($_GET['sort'])&&!is_array($_GET['sort'])?trim((string)$_GET['sort']):'latest';
    $allowedTypes=array('','lead','client','archived'); $allowedStatuses=array('','new','active','inactive','archived'); $allowedSorts=array('latest','oldest','name_asc','name_desc');
    if(!in_array($typeFilter,$allowedTypes,true)) apiResponse(422,array('success'=>false,'code'=>'invalid_type','message'=>'Invalid client type filter.'));
    if(!in_array($statusFilter,$allowedStatuses,true)) apiResponse(422,array('success'=>false,'code'=>'invalid_status','message'=>'Invalid status filter.'));
    if(!in_array($sort,$allowedSorts,true)) apiResponse(422,array('success'=>false,'code'=>'invalid_sort','message'=>'Invalid sort option.'));
    $page=isset($_GET['page'])?max(1,(int)$_GET['page']):1; $perPage=isset($_GET['per_page'])?(int)$_GET['per_page']:20; if($perPage<1)$perPage=20; if($perPage>100)$perPage=100;

    $stats=array('total'=>0,'leads'=>0,'active'=>0,'inactive'=>0);
    $stmt=$conn->prepare("SELECT COUNT(*) AS total,SUM(client_type='lead') AS leads,SUM(status='active') AS active,SUM(status='inactive') AS inactive FROM clients WHERE tenant_id=? AND deleted_at IS NULL");
    if(!$stmt) throw new Exception($conn->error); $stmt->bind_param('i',$tenantId); if(!$stmt->execute()) throw new Exception($stmt->error); $row=apiFetchOne($stmt); $stmt->close();
    if($row){ $stats['total']=(int)$row['total']; $stats['leads']=(int)$row['leads']; $stats['active']=(int)$row['active']; $stats['inactive']=(int)$row['inactive']; }

    $where=array('c.tenant_id = ?','c.deleted_at IS NULL'); $params=array($tenantId); $types='i';
    if($search!==''){ $where[]="(c.display_name LIKE ? OR c.company_name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.alternate_phone LIKE ?)"; $like='%'.$search.'%'; for($i=0;$i<7;$i++){ $params[]=$like; $types.='s'; } }
    if($typeFilter!==''){ $where[]='c.client_type = ?'; $params[]=$typeFilter; $types.='s'; }
    if($statusFilter!==''){ $where[]='c.status = ?'; $params[]=$statusFilter; $types.='s'; }
    $whereSql=implode(' AND ',$where); $orderSql='c.created_at DESC'; if($sort==='oldest')$orderSql='c.created_at ASC'; elseif($sort==='name_asc')$orderSql='c.display_name ASC'; elseif($sort==='name_desc')$orderSql='c.display_name DESC';

    $stmt=$conn->prepare("SELECT COUNT(*) AS total FROM clients c WHERE {$whereSql}"); if(!$stmt) throw new Exception($conn->error); apiBindParams($stmt,$types,$params); if(!$stmt->execute()) throw new Exception($stmt->error); $countRow=apiFetchOne($stmt); $stmt->close();
    $totalFiltered=$countRow?(int)$countRow['total']:0; $totalPages=max(1,(int)ceil($totalFiltered/$perPage)); if($page>$totalPages)$page=$totalPages; $offset=($page-1)*$perPage;

    $sql="SELECT c.id,c.client_type,c.display_name,c.company_name,c.first_name,c.last_name,c.email,c.phone,c.alternate_phone,c.source,c.status,c.notes,c.preferred_contact_method,c.allow_email,c.allow_sms,c.last_activity_at,c.created_at,c.updated_at,c.billing_address_line1,c.billing_address_line2,c.billing_city,c.billing_state,c.billing_postal_code,c.billing_country,c.tax_number,c.account_manager_id,CONCAT(COALESCE(u.first_name,''),CASE WHEN COALESCE(u.last_name,'')<>'' THEN CONCAT(' ',u.last_name) ELSE '' END) AS account_manager_name,(SELECT COUNT(*) FROM properties p WHERE p.tenant_id=c.tenant_id AND p.client_id=c.id AND p.deleted_at IS NULL) AS property_count,(SELECT COUNT(*) FROM jobs j WHERE j.tenant_id=c.tenant_id AND j.client_id=c.id AND j.deleted_at IS NULL) AS job_count,(SELECT COALESCE(SUM(i.balance_due),0) FROM invoices i WHERE i.tenant_id=c.tenant_id AND i.client_id=c.id AND i.archived_at IS NULL AND i.balance_due>0) AS outstanding_amount FROM clients c LEFT JOIN users u ON u.id=c.account_manager_id AND u.tenant_id=c.tenant_id WHERE {$whereSql} ORDER BY {$orderSql} LIMIT ? OFFSET ?";
    $stmt=$conn->prepare($sql); if(!$stmt) throw new Exception($conn->error); $listParams=$params; $listTypes=$types.'ii'; $listParams[]=$perPage; $listParams[]=$offset; apiBindParams($stmt,$listTypes,$listParams); if(!$stmt->execute()) throw new Exception($stmt->error); $clients=apiFetchAll($stmt); $stmt->close();
    foreach($clients as &$client){ $client['id']=(int)$client['id']; $client['allow_email']=(bool)$client['allow_email']; $client['allow_sms']=(bool)$client['allow_sms']; $client['account_manager_id']=$client['account_manager_id']!==null?(int)$client['account_manager_id']:null; $client['property_count']=(int)$client['property_count']; $client['job_count']=(int)$client['job_count']; $client['outstanding_amount']=(float)$client['outstanding_amount']; } unset($client);

    $canCreate=in_array('clients.create',$permissions,true)||in_array($roleCode,array('owner','admin'),true);
    $canUpdate=in_array('clients.update',$permissions,true)||in_array($roleCode,array('owner','admin'),true);
    $canDelete=in_array('clients.delete',$permissions,true)||in_array($roleCode,array('owner','admin'),true);

    apiResponse(200,array('success'=>true,'message'=>'Clients loaded successfully.','data'=>array(
        'stats'=>$stats,
        'filters'=>array('search'=>$search,'type'=>$typeFilter,'status'=>$statusFilter,'sort'=>$sort),
        'pagination'=>array('page'=>$page,'per_page'=>$perPage,'total'=>$totalFiltered,'total_pages'=>$totalPages,'has_previous'=>$page>1,'has_next'=>$page<$totalPages),
        'currency_code'=>!empty($account['currency_code'])?(string)$account['currency_code']:'INR',
        'access'=>array('view'=>true,'create'=>$canCreate,'update'=>$canUpdate,'delete'=>$canDelete),
        'clients'=>$clients
    )));
} catch(Throwable $exception){ error_log('FieldPlx clients API error: '.$exception->getMessage()); apiResponse(500,array('success'=>false,'code'=>'server_error','message'=>'Unable to load clients.')); }
