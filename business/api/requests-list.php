<?php
/* FieldPlx Requests List API - Version 2.0.0 - 2026-09-15 */
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function rqlOut($status,$success,$message,$extra=array()){
    while(ob_get_level()>0) @ob_end_clean();
    http_response_code((int)$status);
    echo json_encode(array_merge(array('success'=>(bool)$success,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function rqlPost($key,$default=''){return isset($_POST[$key])&&!is_array($_POST[$key])?trim((string)$_POST[$key]):$default;}
function rqlValidDate($v){if($v==='')return true;$d=DateTime::createFromFormat('Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v;}

$pdoConn = (isset($pdo)&&$pdo instanceof PDO) ? $pdo : ((isset($db)&&$db instanceof PDO) ? $db : null);
if(!$pdoConn) rqlOut(500,false,'Database connection is unavailable.');
$tenantId=!empty($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:(!empty($_SESSION['business_id'])?(int)$_SESSION['business_id']:0);
$userId=!empty($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:(!empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:(!empty($_SESSION['id'])?(int)$_SESSION['id']:0));
if($tenantId<=0||$userId<=0) rqlOut(401,false,'Authentication required.');
$csrf=rqlPost('csrf_token');$sessionCsrf=isset($_SESSION['requests_csrf_token'])?(string)$_SESSION['requests_csrf_token']:'';
if($csrf===''||$sessionCsrf===''||!hash_equals($sessionCsrf,$csrf)) rqlOut(419,false,'Your form session expired. Refresh the page and try again.');
if(rqlPost('action','list')!=='list') rqlOut(400,false,'Unsupported request-list action.');

try{
    $page=max(1,(int)rqlPost('page','1'));
    $perPage=(int)rqlPost('per_page','10');if(!in_array($perPage,array(10,25,50),true))$perPage=10;
    $search=rqlPost('search');$status=strtolower(rqlPost('status'));$dateFilter=rqlPost('date_filter');
    $allowedStatuses=array('new','contacting','information_required','assessment_required','quote_required','job_required','converted','closed','cancelled');
    if($status!==''&&!in_array($status,$allowedStatuses,true)) rqlOut(422,false,'Select a valid request status.');
    if(!in_array($dateFilter,array('','today','last_7','last_30','this_month'),true)) rqlOut(422,false,'Select a valid request date filter.');

    $where=array('r.tenant_id=:tenant_id');$params=array(':tenant_id'=>$tenantId);
    if($search!==''){
        $v='%'.$search.'%';
        $where[]="(r.request_no LIKE :s1 OR r.title LIKE :s2 OR COALESCE(r.description,'') LIKE :s3 OR c.display_name LIKE :s4 OR COALESCE(c.phone,'') LIKE :s5 OR COALESCE(c.email,'') LIKE :s6 OR COALESCE(cl.name,'') LIKE :s7 OR COALESCE(cl.address_line1,'') LIKE :s8 OR COALESCE(cl.address_line2,'') LIKE :s9 OR COALESCE(cl.city,'') LIKE :s10 OR COALESCE(cl.state,'') LIKE :s11 OR COALESCE(cl.postal_code,'') LIKE :s12)";
        for($i=1;$i<=12;$i++)$params[':s'.$i]=$v;
    }
    if($status!==''){$where[]='r.status=:status';$params[':status']=$status;}
    if($dateFilter==='today')$where[]='DATE(r.created_at)=CURDATE()';
    elseif($dateFilter==='last_7')$where[]='r.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)';
    elseif($dateFilter==='last_30')$where[]='r.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)';
    elseif($dateFilter==='this_month'){$where[]="r.created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')";$where[]='r.created_at<DATE_ADD(LAST_DAY(CURDATE()),INTERVAL 1 DAY)';}
    $whereSql=implode(' AND ',$where);
    $joins=" FROM service_requests r INNER JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id LEFT JOIN client_locations cl ON cl.id=r.location_id AND cl.tenant_id=r.tenant_id AND cl.client_id=r.client_id AND cl.deleted_at IS NULL ";

    $count=$pdoConn->prepare('SELECT COUNT(*)'.$joins.' WHERE '.$whereSql);$count->execute($params);$total=(int)$count->fetchColumn();
    $pages=max(1,(int)ceil($total/$perPage));if($page>$pages)$page=$pages;$offset=($page-1)*$perPage;

    $sql="SELECT r.id,r.request_no,r.title,r.status,r.priority,r.created_at,r.updated_at,c.display_name AS client_name,c.phone AS client_phone,c.email AS client_email,cl.name AS location_name,cl.address_line1 AS location_address_line1,cl.address_line2 AS location_address_line2,cl.city AS location_city,cl.state AS location_state,cl.postal_code AS location_postal_code".$joins." WHERE ".$whereSql." ORDER BY r.created_at DESC,r.id DESC LIMIT ".(int)$perPage." OFFSET ".(int)$offset;
    $st=$pdoConn->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);

    $summarySql="SELECT
        COUNT(*) AS total_all,
        COALESCE(SUM(r.status='quote_required'),0) AS needs_approval,
        COALESCE(SUM(r.status='new'),0) AS new_count,
        COALESCE(SUM(r.status NOT IN ('converted','closed','cancelled') AND EXISTS(SELECT 1 FROM assessments a WHERE a.tenant_id=r.tenant_id AND a.request_id=r.id AND a.status='completed')),0) AS assessment_completed,
        COALESCE(SUM(r.status NOT IN ('converted','closed','cancelled') AND r.preferred_date IS NOT NULL AND r.preferred_date<CURDATE()),0) AS overdue,
        COALESCE(SUM(r.status NOT IN ('converted','closed','cancelled') AND r.preferred_date IS NULL),0) AS unscheduled,
        COALESCE(SUM(r.created_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND r.created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)),0) AS requests_30,
        COALESCE(SUM(r.created_at>=DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND r.created_at<DATE_SUB(CURDATE(),INTERVAL 29 DAY)),0) AS previous_requests_30,
        COALESCE(SUM(r.created_at>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND r.created_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY) AND (EXISTS(SELECT 1 FROM quotes q WHERE q.tenant_id=r.tenant_id AND q.request_id=r.id) OR EXISTS(SELECT 1 FROM jobs j WHERE j.tenant_id=r.tenant_id AND j.request_id=r.id AND j.deleted_at IS NULL))),0) AS converted_30,
        COALESCE(SUM(r.created_at>=DATE_SUB(CURDATE(),INTERVAL 59 DAY) AND r.created_at<DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND (EXISTS(SELECT 1 FROM quotes q2 WHERE q2.tenant_id=r.tenant_id AND q2.request_id=r.id) OR EXISTS(SELECT 1 FROM jobs j2 WHERE j2.tenant_id=r.tenant_id AND j2.request_id=r.id AND j2.deleted_at IS NULL))),0) AS previous_converted_30
        FROM service_requests r WHERE r.tenant_id=:tenant_id";
    $sum=$pdoConn->prepare($summarySql);$sum->execute(array(':tenant_id'=>$tenantId));$s=$sum->fetch(PDO::FETCH_ASSOC)?:array();
    $req30=(int)($s['requests_30']??0);$prevReq=(int)($s['previous_requests_30']??0);$conv30=(int)($s['converted_30']??0);$prevConv=(int)($s['previous_converted_30']??0);
    $conversion=$req30>0?($conv30/$req30)*100:0;$prevConversion=$prevReq>0?($prevConv/$prevReq)*100:0;
    $requestsChange=$prevReq>0?(($req30-$prevReq)/$prevReq)*100:($req30>0?100:0);
    $summary=array(
        'total_all'=>(int)($s['total_all']??0),'needs_approval'=>(int)($s['needs_approval']??0),'new_count'=>(int)($s['new_count']??0),'assessment_completed'=>(int)($s['assessment_completed']??0),'overdue'=>(int)($s['overdue']??0),'unscheduled'=>(int)($s['unscheduled']??0),'requests_30'=>$req30,'previous_requests_30'=>$prevReq,'converted_30'=>$conv30,'conversion_rate'=>$conversion,'previous_conversion_rate'=>$prevConversion,'requests_change'=>$requestsChange,'conversion_change'=>$conversion-$prevConversion
    );

    rqlOut(200,true,'Requests loaded successfully.',array(
        'requests'=>$rows,
        'tenant_total'=>$summary['total_all'],
        'summary'=>$summary,
        'pagination'=>array('page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$total>0?$offset+1:0,'to'=>$total>0?min($offset+count($rows),$total):0)
    ));
}catch(Throwable $e){error_log('FieldPlx requests-list API: '.$e->getMessage());rqlOut(500,false,'Unable to load service requests.');}
