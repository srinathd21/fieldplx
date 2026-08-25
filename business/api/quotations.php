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

function qRes($code,$ok,$message,$extra=array()){
    while(ob_get_level()>0){ @ob_end_clean(); }
    http_response_code((int)$code);
    echo json_encode(array_merge(array(
        'success'=>(bool)$ok,
        'message'=>(string)$message
    ),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function qP($key,$default=''){ return isset($_POST[$key]) ? $_POST[$key] : $default; }
function qCol(PDO $pdo,$table,$column){
    static $cache=array();
    $key=$table.'.'.$column;
    if(isset($cache[$key])) return $cache[$key];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(array(':t'=>$table,':c'=>$column));
    $cache[$key]=(int)$s->fetchColumn()>0;
    return $cache[$key];
}
function qTable(PDO $pdo,$table){
    static $cache=array();
    if(isset($cache[$table])) return $cache[$table];
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(array(':t'=>$table));
    $cache[$table]=(int)$s->fetchColumn()>0;
    return $cache[$table];
}
function qCurrency(PDO $pdo,$tenant){
    $s=$pdo->prepare("SELECT c.id,c.currency_code,c.currency_name,c.symbol,c.symbol_position,c.decimal_places,c.decimal_separator,c.thousand_separator FROM tenants t INNER JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1");
    $s->execute(array(':t'=>$tenant));
    $r=$s->fetch();
    return $r ? $r : array('id'=>null,'currency_code'=>'','currency_name'=>'','symbol'=>'','symbol_position'=>'before','decimal_places'=>2,'decimal_separator'=>'.','thousand_separator'=>',');
}
function qValidRequest(PDO $pdo,$tenant,$id){
    $s=$pdo->prepare("SELECT r.*,c.display_name client_name,cl.name location_name,ps.name service_name FROM service_requests r INNER JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id LEFT JOIN client_locations cl ON cl.id=r.location_id AND cl.tenant_id=r.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=r.tenant_id WHERE r.id=:id AND r.tenant_id=:t AND r.status NOT IN('closed','cancelled') LIMIT 1");
    $s->execute(array(':id'=>$id,':t'=>$tenant));
    $r=$s->fetch();
    return $r ? $r : null;
}
function qValidRevisit(PDO $pdo,$tenant,$requestId,$revisitId){
    if($revisitId<=0 || !qTable($pdo,'assessment_reschedule_history')) return null;
    $s=$pdo->prepare("SELECT h.*,COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.email,CONCAT('User #',h.rescheduled_by)) rescheduled_by_name FROM assessment_reschedule_history h LEFT JOIN users u ON u.id=h.rescheduled_by AND u.tenant_id=h.tenant_id WHERE h.id=:id AND h.tenant_id=:t AND h.request_id=:r LIMIT 1");
    $s->execute(array(':id'=>$revisitId,':t'=>$tenant,':r'=>$requestId));
    $row=$s->fetch();
    return $row ? $row : null;
}
function qNext(PDO $pdo,$tenant,$branch){
    $sep=qCol($pdo,'document_sequences','number_separator')?'number_separator':'separator';
    $s=$pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type='quote' AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1 FOR UPDATE");
    $s->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:0,':b2'=>$branch>0?$branch:0));
    $r=$s->fetch();
    if(!$r){
        $q=$pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(quote_no,'-',-1) AS UNSIGNED)) FROM quotes WHERE tenant_id=:t AND quote_no LIKE 'QUO-%'");
        $q->execute(array(':t'=>$tenant));
        return 'QUO-'.str_pad((string)((int)$q->fetchColumn()+1),6,'0',STR_PAD_LEFT);
    }
    $now=new DateTime('now');$y=$now->format('Y');$mo=$now->format('m');
    $fyStart=max(1,min(12,(int)$r['financial_year_start_month']));
    $fyY=(int)$now->format('n')>=$fyStart?(int)$y:(int)$y-1;
    $fy=$fyY.'-'.substr((string)($fyY+1),-2);
    $key='never';
    if($r['reset_period']==='monthly')$key=$y.$mo;
    elseif($r['reset_period']==='yearly')$key=$y;
    elseif($r['reset_period']==='financial_year')$key=$fy;
    $cur=(int)$r['current_number'];
    if($r['reset_period']!=='never' && (string)$r['last_reset_key']!==(string)$key)$cur=0;
    $next=$cur+1;
    $mid='';
    if($r['middle_format']==='year')$mid=$y;
    elseif($r['middle_format']==='year_month')$mid=$y.$mo;
    elseif($r['middle_format']==='financial_year')$mid=$fy;
    elseif($r['middle_format']==='branch_year')$mid=(!empty($r['branch_code'])?$r['branch_code']:'BR').$y;
    $parts=array();
    if(!empty($r['prefix']))$parts[]=$r['prefix'];
    if($mid!=='')$parts[]=$mid;
    $parts[]=str_pad((string)$next,max(1,(int)$r['number_length']),'0',STR_PAD_LEFT);
    if(!empty($r['suffix']))$parts[]=$r['suffix'];
    $no=implode(isset($r[$sep])?(string)$r[$sep]:'-',$parts);
    $u=$pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");
    $u->execute(array(':n'=>$next,':k'=>$key,':id'=>$r['id']));
    return $no;
}
function qLog(PDO $pdo,$tenant,$branch,$user,$quoteId,$client,$type,$title,$details){
    try{
        $s=$pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user',:e,'quote',:rid,:cid,:title,:d,0)");
        $s->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':u'=>$user,':e'=>$type,':rid'=>$quoteId,':cid'=>$client,':title'=>substr($title,0,255),':d'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    }catch(Throwable $e){ error_log('quote activity '.$e->getMessage()); }
}

$tenant=isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;
$user=isset($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:0;
$sessionBranch=isset($_SESSION['branch_id'])?(int)$_SESSION['branch_id']:0;
if($tenant<=0||$user<=0) qRes(401,false,'Authentication required.');

$csrf=(string)qP('csrf_token','');
$sc=isset($_SESSION['quotations_csrf_token'])?(string)$_SESSION['quotations_csrf_token']:'';
$legacySc=isset($_SESSION['my_jobs_csrf_token'])?(string)$_SESSION['my_jobs_csrf_token']:'';
$csrfOk=($csrf!=='' && (($sc!=='' && hash_equals($sc,$csrf)) || ($legacySc!=='' && hash_equals($legacySc,$csrf))));
if(!$csrfOk) qRes(419,false,'Your form session expired. Refresh and try again.');

$action=trim((string)qP('action',''));
$hasRevisitColumn=qCol($pdo,'quotes','assessment_reschedule_id');

try{
    if($action==='form_meta'){
        $editing=(int)qP('quote_id',0);
        if(!$hasRevisitColumn && qTable($pdo,'assessment_reschedule_history')){
            qRes(500,false,'Quotation revisit support is not installed. Run migration_quotation_revisit_support.sql once.');
        }

        $sources=array();
        $sql="SELECT r.id,r.request_no,r.title,r.description,r.status,r.branch_id,r.client_id,r.location_id,r.product_service_id,r.preferred_date,r.preferred_time_from,r.preferred_time_to,c.display_name client_name,cl.name location_name,ps.name service_name FROM service_requests r INNER JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id LEFT JOIN client_locations cl ON cl.id=r.location_id AND cl.tenant_id=r.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=r.tenant_id WHERE r.tenant_id=:t AND r.status NOT IN('closed','cancelled') ORDER BY CASE WHEN r.status='quote_required' THEN 0 ELSE 1 END,COALESCE(r.updated_at,r.created_at) DESC,r.id DESC";
        $s=$pdo->prepare($sql);$s->execute(array(':t'=>$tenant));$requests=$s->fetchAll();

        $editingQuote=null;
        if($editing>0){
            $eq=$pdo->prepare("SELECT id,request_id".($hasRevisitColumn?',assessment_reschedule_id':'')." FROM quotes WHERE id=:id AND tenant_id=:t LIMIT 1");
            $eq->execute(array(':id'=>$editing,':t'=>$tenant));
            $editingQuote=$eq->fetch();
        }

        foreach($requests as $r){
            $requestId=(int)$r['id'];
            $origAllowed=true;
            $dupSql="SELECT id FROM quotes WHERE tenant_id=:t AND request_id=:r AND status<>'archived'";
            if($hasRevisitColumn) $dupSql.=" AND assessment_reschedule_id IS NULL";
            $dupSql.=" LIMIT 1";
            $ds=$pdo->prepare($dupSql);$ds->execute(array(':t'=>$tenant,':r'=>$requestId));$existingOrig=$ds->fetchColumn();
            if($existingOrig && (!$editingQuote || (int)$editingQuote['id']!==(int)$existingOrig)) $origAllowed=false;
            if($origAllowed){
                $row=$r;
                $row['source_key']='request:'.$requestId;
                $row['request_id']=$requestId;
                $row['assessment_reschedule_id']=null;
                $row['source_type']='original';
                $row['source_label']='Original Enquiry';
                $row['visit_date']=$r['preferred_date'];
                $row['visit_time_from']=$r['preferred_time_from'];
                $row['visit_time_to']=$r['preferred_time_to'];
                $row['revisit_remarks']='';
                $sources[]=$row;
            }

            if($hasRevisitColumn && qTable($pdo,'assessment_reschedule_history')){
                $hs=$pdo->prepare("SELECT h.*,COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),u.email,CONCAT('User #',h.rescheduled_by)) rescheduled_by_name FROM assessment_reschedule_history h LEFT JOIN users u ON u.id=h.rescheduled_by AND u.tenant_id=h.tenant_id WHERE h.tenant_id=:t AND h.request_id=:r ORDER BY h.id DESC");
                $hs->execute(array(':t'=>$tenant,':r'=>$requestId));
                foreach($hs->fetchAll() as $h){
                    $hid=(int)$h['id'];
                    $qd=$pdo->prepare("SELECT id FROM quotes WHERE tenant_id=:t AND request_id=:r AND assessment_reschedule_id=:h AND status<>'archived' LIMIT 1");
                    $qd->execute(array(':t'=>$tenant,':r'=>$requestId,':h'=>$hid));
                    $existingRevisit=$qd->fetchColumn();
                    if($existingRevisit && (!$editingQuote || (int)$editingQuote['id']!==(int)$existingRevisit)) continue;
                    $row=$r;
                    $row['source_key']='revisit:'.$requestId.':'.$hid;
                    $row['request_id']=$requestId;
                    $row['assessment_reschedule_id']=$hid;
                    $row['source_type']='revisit';
                    $row['source_label']='Revisit #'.$hid;
                    $row['visit_date']=$h['new_preferred_date'];
                    $row['visit_time_from']=$h['new_time_from'];
                    $row['visit_time_to']=$h['new_time_to'];
                    $row['revisit_remarks']=$h['remarks'];
                    $row['rescheduled_by_name']=$h['rescheduled_by_name'];
                    $sources[]=$row;
                }
            }
        }

        $c=$pdo->prepare("SELECT id,name,item_type,sku,description,unit_name,unit_cost,unit_price,tax_percent FROM product_services WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY FIELD(item_type,'service','product','material','fee','discount'),name");
        $c->execute(array(':t'=>$tenant));
        qRes(200,true,'Quotation form data loaded.',array('meta'=>array('requests'=>$sources,'catalog'=>$c->fetchAll(),'currency'=>qCurrency($pdo,$tenant))));
    }

    if($action==='get'){
        $id=(int)qP('quote_id',0);
        $sel="q.*,DATE_FORMAT(q.created_at,'%d-%m-%Y') created_date,r.request_no,c.display_name client_name,c.phone client_phone,cl.name location_name,ps.name service_name";
        if($hasRevisitColumn) $sel.=" ,q.assessment_reschedule_id";
        $s=$pdo->prepare("SELECT $sel FROM quotes q LEFT JOIN service_requests r ON r.id=q.request_id AND r.tenant_id=q.tenant_id INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id LEFT JOIN client_locations cl ON cl.id=q.location_id AND cl.tenant_id=q.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=q.tenant_id WHERE q.id=:id AND q.tenant_id=:t LIMIT 1");
        $s->execute(array(':id'=>$id,':t'=>$tenant));
        $q=$s->fetch();
        if(!$q) qRes(404,false,'Quotation not found.');
        $rid=(int)$q['request_id'];$hid=$hasRevisitColumn?(int)$q['assessment_reschedule_id']:0;
        $q['source_key']=$hid>0?'revisit:'.$rid.':'.$hid:'request:'.$rid;
        $i=$pdo->prepare("SELECT qli.*,COALESCE(ps.item_type,'manual') item_type FROM quote_line_items qli LEFT JOIN product_services ps ON ps.id=qli.product_service_id WHERE qli.quote_id=:q ORDER BY qli.sort_order,qli.id");
        $i->execute(array(':q'=>$id));
        qRes(200,true,'Quotation loaded.',array('quotation'=>$q,'items'=>$i->fetchAll(),'currency'=>qCurrency($pdo,$tenant)));
    }

    if($action==='list'){
        $page=max(1,(int)qP('page',1));$pp=(int)qP('per_page',10);if(!in_array($pp,array(10,25,50),true))$pp=10;
        $search=trim((string)qP('search',''));$status=trim((string)qP('status',''));$from=trim((string)qP('from_date',''));$to=trim((string)qP('to_date',''));
        $where=array('q.tenant_id=:t');$p=array(':t'=>$tenant);
        if($search!==''){$sv='%'.$search.'%';$where[]='(q.quote_no LIKE :s1 OR r.request_no LIKE :s2 OR c.display_name LIKE :s3 OR q.title LIKE :s4)';$p[':s1']=$sv;$p[':s2']=$sv;$p[':s3']=$sv;$p[':s4']=$sv;}
        if(in_array($status,array('draft','internal_approval','sent','viewed','changes_requested','approved','rejected','expired','converted','archived'),true)){$where[]='q.status=:st';$p[':st']=$status;}
        if($from!==''){$where[]='DATE(q.created_at)>=:fd';$p[':fd']=$from;}
        if($to!==''){$where[]='DATE(q.created_at)<=:td';$p[':td']=$to;}
        $ws=implode(' AND ',$where);
        $cnt=$pdo->prepare("SELECT COUNT(*) FROM quotes q LEFT JOIN service_requests r ON r.id=q.request_id AND r.tenant_id=q.tenant_id INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id WHERE $ws");$cnt->execute($p);$total=(int)$cnt->fetchColumn();
        $pages=max(1,(int)ceil($total/$pp));if($page>$pages)$page=$pages;$off=($page-1)*$pp;
        $extraSource=$hasRevisitColumn?",q.assessment_reschedule_id,CASE WHEN q.assessment_reschedule_id IS NULL THEN 'Original Enquiry' ELSE CONCAT('Revisit #',q.assessment_reschedule_id) END quotation_source":"";
        $sql="SELECT q.*,r.request_no,c.display_name client_name,cl.name location_name,ps.name service_name $extraSource FROM quotes q LEFT JOIN service_requests r ON r.id=q.request_id AND r.tenant_id=q.tenant_id INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id LEFT JOIN client_locations cl ON cl.id=q.location_id AND cl.tenant_id=q.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=q.tenant_id WHERE $ws ORDER BY q.created_at DESC,q.id DESC LIMIT ".(int)$pp." OFFSET ".(int)$off;
        $s=$pdo->prepare($sql);$s->execute($p);
        $sm=$pdo->prepare("SELECT COUNT(*) total,SUM(status='draft') draft,SUM(status IN('sent','viewed')) sent_viewed,SUM(status='approved') approved FROM quotes WHERE tenant_id=:t");$sm->execute(array(':t'=>$tenant));$summary=$sm->fetch();
        qRes(200,true,'Quotations loaded.',array('quotations'=>$s->fetchAll(),'summary'=>array('total'=>(int)($summary['total']?:0),'draft'=>(int)($summary['draft']?:0),'sent_viewed'=>(int)($summary['sent_viewed']?:0),'approved'=>(int)($summary['approved']?:0)),'currency'=>qCurrency($pdo,$tenant),'pagination'=>array('page'=>$page,'per_page'=>$pp,'total'=>$total,'pages'=>$pages,'from'=>$total?($off+1):0,'to'=>$total?min($off+$pp,$total):0)));
    }

    if($action==='save'){
        if(!$hasRevisitColumn && qTable($pdo,'assessment_reschedule_history')) qRes(500,false,'Quotation revisit support is not installed. Run migration_quotation_revisit_support.sql once.');
        $id=(int)qP('quote_id',0);
        $requestId=(int)qP('request_id',0);
        $revisitId=(int)qP('assessment_reschedule_id',0);
        $title=trim((string)qP('title',''));
        $status=trim((string)qP('status','approved'));
        $allowedStatuses=array('approved','draft','internal_approval','sent','viewed','changes_requested','rejected','expired');
        if(!in_array($status,$allowedStatuses,true)) qRes(422,false,'Select a valid quotation status.');
        $intro=trim((string)qP('introduction',''));
        $valid=trim((string)qP('valid_until',''));
        $items=json_decode((string)qP('items_json','[]'),true);
        if($title==='') qRes(422,false,'Quotation title is required.');
        if(!is_array($items)||!count($items)) qRes(422,false,'Add at least one quotation item.');
        $request=qValidRequest($pdo,$tenant,$requestId);
        if(!$request) qRes(422,false,'Select a valid active enquiry or revisit.');
        $revisit=null;
        if($revisitId>0){
            $revisit=qValidRevisit($pdo,$tenant,$requestId,$revisitId);
            if(!$revisit) qRes(422,false,'Selected revisit does not belong to this enquiry.');
        }

        $existing=null;
        if($id>0){
            $s=$pdo->prepare("SELECT * FROM quotes WHERE id=:id AND tenant_id=:t LIMIT 1");$s->execute(array(':id'=>$id,':t'=>$tenant));$existing=$s->fetch();
            if(!$existing) qRes(404,false,'Quotation not found.');
            if(!in_array($existing['status'],array('draft','internal_approval','changes_requested'),true)) qRes(409,false,'This quotation can no longer be edited in its current status.');
        }else{
            if($hasRevisitColumn){
                if($revisitId>0){
                    $s=$pdo->prepare("SELECT id,quote_no FROM quotes WHERE tenant_id=:t AND request_id=:r AND assessment_reschedule_id=:h AND status<>'archived' LIMIT 1");
                    $s->execute(array(':t'=>$tenant,':r'=>$requestId,':h'=>$revisitId));
                    if($s->fetch()) qRes(409,false,'This revisit already has a quotation. Open the existing quotation instead.');
                }else{
                    $s=$pdo->prepare("SELECT id,quote_no FROM quotes WHERE tenant_id=:t AND request_id=:r AND assessment_reschedule_id IS NULL AND status<>'archived' LIMIT 1");
                    $s->execute(array(':t'=>$tenant,':r'=>$requestId));
                    if($s->fetch()) qRes(409,false,'This original enquiry already has a quotation. Select a revisit or open the existing quotation.');
                }
            }else{
                $s=$pdo->prepare("SELECT id,quote_no FROM quotes WHERE tenant_id=:t AND request_id=:r AND status<>'archived' LIMIT 1");$s->execute(array(':t'=>$tenant,':r'=>$requestId));
                if($s->fetch()) qRes(409,false,'This enquiry already has a quotation. Open the existing quotation instead.');
            }
        }

        $sub=0;$disc=0;$tax=0;$tot=0;$norm=array();
        foreach($items as $idx=>$x){
            $pid=isset($x['product_service_id'])?(int)$x['product_service_id']:0;
            if($pid>0){$ps=$pdo->prepare("SELECT id,item_type,name,unit_cost,unit_price,tax_percent FROM product_services WHERE id=:id AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");$ps->execute(array(':id'=>$pid,':t'=>$tenant));if(!$ps->fetch())qRes(422,false,'One selected quotation item is no longer active.');}else{$pid=null;}
            $name=trim((string)(isset($x['item_name'])?$x['item_name']:''));if($name==='')qRes(422,false,'Quotation item name is required.');
            $qty=max(.001,(float)(isset($x['quantity'])?$x['quantity']:1));$cost=max(0,(float)(isset($x['unit_cost'])?$x['unit_cost']:0));$price=max(0,(float)(isset($x['unit_price'])?$x['unit_price']:0));
            $base=$qty*$price;$d=max(0,min($base,(float)(isset($x['discount_amount'])?$x['discount_amount']:0)));$tp=max(0,(float)(isset($x['tax_percent'])?$x['tax_percent']:0));$taxable=max(0,$base-$d);$ta=$taxable*$tp/100;$lt=$taxable+$ta;
            $sub+=$base;$disc+=$d;$tax+=$ta;$tot+=$lt;
            $norm[]=array('pid'=>$pid,'name'=>$name,'description'=>trim((string)(isset($x['description'])?$x['description']:'')),'qty'=>$qty,'cost'=>$cost,'price'=>$price,'disc'=>$d,'tp'=>$tp,'ta'=>$ta,'lt'=>$lt,'optional'=>!empty($x['is_optional'])?1:0,'sort'=>$idx);
        }
        $branch=!empty($request['branch_id'])?(int)$request['branch_id']:$sessionBranch;

        $pdo->beginTransaction();
        try{
            if($id>0){
                $setRevisit=$hasRevisitColumn?',assessment_reschedule_id=:ar':'';
                $u=$pdo->prepare("UPDATE quotes SET branch_id=:b,client_id=:c,location_id=:l,request_id=:r $setRevisit,title=:title,introduction=:intro,status=:status,subtotal=:sub,discount_total=:disc,tax_total=:tax,total=:tot,valid_until=:vu WHERE id=:id AND tenant_id=:t");
                $up=array(':b'=>$branch>0?$branch:null,':c'=>$request['client_id'],':l'=>$request['location_id'],':r'=>$requestId,':title'=>$title,':intro'=>$intro!==''?$intro:null,':status'=>$status,':sub'=>$sub,':disc'=>$disc,':tax'=>$tax,':tot'=>$tot,':vu'=>$valid!==''?$valid:null,':id'=>$id,':t'=>$tenant);
                if($hasRevisitColumn)$up[':ar']=$revisitId>0?$revisitId:null;
                $u->execute($up);
                $d=$pdo->prepare("DELETE FROM quote_line_items WHERE quote_id=:q");$d->execute(array(':q'=>$id));
                $quoteNo=$existing['quote_no'];
            }else{
                $quoteNo=qNext($pdo,$tenant,$branch);
                if($hasRevisitColumn){
                    $ins=$pdo->prepare("INSERT INTO quotes(tenant_id,branch_id,quote_no,revision_no,client_id,location_id,request_id,assessment_reschedule_id,title,introduction,status,subtotal,discount_total,tax_total,total,valid_until,created_by) VALUES(:t,:b,:no,0,:c,:l,:r,:ar,:title,:intro,:status,:sub,:disc,:tax,:tot,:vu,:u)");
                    $ins->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':no'=>$quoteNo,':c'=>$request['client_id'],':l'=>$request['location_id'],':r'=>$requestId,':ar'=>$revisitId>0?$revisitId:null,':title'=>$title,':intro'=>$intro!==''?$intro:null,':status'=>$status,':sub'=>$sub,':disc'=>$disc,':tax'=>$tax,':tot'=>$tot,':vu'=>$valid!==''?$valid:null,':u'=>$user));
                }else{
                    $ins=$pdo->prepare("INSERT INTO quotes(tenant_id,branch_id,quote_no,revision_no,client_id,location_id,request_id,title,introduction,status,subtotal,discount_total,tax_total,total,valid_until,created_by) VALUES(:t,:b,:no,0,:c,:l,:r,:title,:intro,:status,:sub,:disc,:tax,:tot,:vu,:u)");
                    $ins->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':no'=>$quoteNo,':c'=>$request['client_id'],':l'=>$request['location_id'],':r'=>$requestId,':title'=>$title,':intro'=>$intro!==''?$intro:null,':status'=>$status,':sub'=>$sub,':disc'=>$disc,':tax'=>$tax,':tot'=>$tot,':vu'=>$valid!==''?$valid:null,':u'=>$user));
                }
                $id=(int)$pdo->lastInsertId();
            }

            $li=$pdo->prepare("INSERT INTO quote_line_items(quote_id,product_service_id,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,is_optional,sort_order) VALUES(:q,:pid,:name,:d,:qty,:cost,:price,:disc,:tp,:ta,:lt,:opt,:sort)");
            foreach($norm as $x){$li->execute(array(':q'=>$id,':pid'=>$x['pid'],':name'=>$x['name'],':d'=>$x['description']!==''?$x['description']:null,':qty'=>$x['qty'],':cost'=>$x['cost'],':price'=>$x['price'],':disc'=>$x['disc'],':tp'=>$x['tp'],':ta'=>$x['ta'],':lt'=>$x['lt'],':opt'=>$x['optional'],':sort'=>$x['sort']));}
            $pdo->commit();
        }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }

        $details=array('request_id'=>$requestId,'assessment_reschedule_id'=>$revisitId>0?$revisitId:null,'source'=>$revisitId>0?'revisit':'original_enquiry','total'=>$tot,'status'=>$status);
        qLog($pdo,$tenant,$branch,$user,$id,(int)$request['client_id'],$existing?'quote_updated':'quote_created',($existing?'Quotation updated: ':'Quotation created: ').$quoteNo,$details);
        if(function_exists('tenantAuditLog')){
            try{ tenantAuditLog($pdo,$existing?'QUOTE_UPDATED':'QUOTE_CREATED',$tenant,$branch,$user,'quote',$id,$existing,array('quote_no'=>$quoteNo,'request_id'=>$requestId,'assessment_reschedule_id'=>$revisitId>0?$revisitId:null,'total'=>$tot,'status'=>$status)); }catch(Throwable $auditError){ error_log('quote audit '.$auditError->getMessage()); }
        }
        qRes(200,true,'Quotation '.$quoteNo.($existing?' updated':' created').' successfully.',array('quote_id'=>$id,'quote_no'=>$quoteNo,'source'=>$revisitId>0?'revisit':'original_enquiry'));
    }

    qRes(400,false,'Unsupported quotation action.');
}catch(PDOException $e){
    error_log('FieldPlx quotations PDO '.$e->getMessage());
    if(isset($e->errorInfo[1])&&(int)$e->errorInfo[1]===1062) qRes(409,false,'A quotation already exists for this enquiry/revisit or the quotation number is duplicated.');
    qRes(500,false,'Unable to process the quotation request.');
}catch(Throwable $e){
    error_log('FieldPlx quotations '.$e->getMessage());
    qRes(500,false,'Unable to process the quotation request.');
}
