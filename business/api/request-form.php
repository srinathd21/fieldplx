<?php
/* FieldPlx Request Form API - Version 3.5.0 - 2026-09-15
 * Fixes request-form meta JSON loading and keeps customer email disabled on request creation.
 * Team assignment email remains available and loads Platform SMTP only when actually required.
 * Adds existing checklist selection and Product / Service cost-markup metadata.
 */
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');

/* Always return JSON for unexpected fatal errors instead of an empty/HTML response. */
register_shutdown_function(function(){
    $e=error_get_last();
    if(!$e || !in_array((int)$e['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR),true)) return;
    while(ob_get_level()>0){ @ob_end_clean(); }
    if(!headers_sent()){
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log('request-form fatal: '.(isset($e['message'])?$e['message']:'Unknown fatal error'));
    echo '{"success":false,"message":"Request form API encountered a server error. Check the PHP error log."}';
});

require_once __DIR__ . '/../includes/auth.php';
if(file_exists(__DIR__.'/../includes/audit.php')) require_once __DIR__.'/../includes/audit.php';

function rfRes($code,$ok,$msg,$extra=array()){
    while(ob_get_level()>0){ @ob_end_clean(); }
    http_response_code((int)$code);
    header('Content-Type: application/json; charset=utf-8');
    $flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR;
    if(defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags|=JSON_INVALID_UTF8_SUBSTITUTE;
    $json=json_encode(array_merge(array('success'=>(bool)$ok,'message'=>(string)$msg),$extra),$flags);
    if($json===false){
        $json='{"success":false,"message":"Unable to encode the request API response."}';
        if((int)$code<400) http_response_code(500);
    }
    echo $json;
    exit;
}

/* auth.php may expose the PDO connection as either $pdo or $db. */
if(!(isset($pdo) && $pdo instanceof PDO)){
    if(isset($db) && $db instanceof PDO){
        $pdo=$db;
    }else{
        rfRes(500,false,'Database connection is unavailable.');
    }
}
function rfP($k,$d=''){return isset($_POST[$k])?$_POST[$k]:$d;}
function rfTable(PDO $pdo,$t){static $c=array();if(isset($c[$t]))return $c[$t];$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");$s->execute(array(':t'=>$t));return $c[$t]=(int)$s->fetchColumn()>0;}
function rfCol(PDO $pdo,$t,$c){static $x=array();$k=$t.'.'.$c;if(isset($x[$k]))return $x[$k];$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");$s->execute(array(':t'=>$t,':c'=>$c));return $x[$k]=(int)$s->fetchColumn()>0;}
function rfValid(PDO $pdo,$table,$tenant,$id,$extra=''){$id=(int)$id;if($id<=0)return null;$allowed=array('branches','clients','client_locations','product_services','products','users','teams','workflows');if(!in_array($table,$allowed,true))return null;$sql="SELECT id FROM $table WHERE id=:id AND tenant_id=:t";if(rfCol($pdo,$table,'deleted_at'))$sql.=" AND deleted_at IS NULL";if($extra!=='')$sql.=' '.$extra;$sql.=' LIMIT 1';$s=$pdo->prepare($sql);$s->execute(array(':id'=>$id,':t'=>$tenant));return $s->fetchColumn()?$id:null;}
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
    /* Load SMTP lazily. Meta loading and normal request creation must not depend on SMTP. */
    static $attempted=false;
    if(!function_exists('fieldplxPlatformSmtpConfig') && !$attempted){
        $attempted=true;
        $smtpFile=__DIR__.'/../includes/platform-smtp.php';
        if(is_file($smtpFile)){
            try{ require_once $smtpFile; }
            catch(Throwable $e){ error_log('request platform SMTP include: '.$e->getMessage()); }
        }
    }
    if(!function_exists('fieldplxPlatformSmtpConfig')) return null;
    try{ return fieldplxPlatformSmtpConfig($pdo); }
    catch(Throwable $e){ error_log('request platform SMTP config: '.$e->getMessage()); return null; }
}
function rfSmtp(PDO $pdo,$tenant,$branch){$r=rfSmtpConfig($pdo,$tenant,$branch);return $r?(int)$r['id']:null;}
function rfMeta(PDO $pdo,$tenant,$branch){
    $m=array();

    $s=$pdo->prepare("SELECT id,name FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name");
    $s->execute(array(':t'=>$tenant));
    $m['branches']=$s->fetchAll();

    $s=$pdo->prepare("SELECT c.id,c.display_name name,c.company_name,c.email,c.phone,c.branch_id,(SELECT cl.id FROM client_locations cl WHERE cl.tenant_id=c.tenant_id AND cl.client_id=c.id AND cl.deleted_at IS NULL AND cl.status='active' ORDER BY cl.is_primary DESC,cl.id LIMIT 1) primary_location_id FROM clients c WHERE c.tenant_id=:t AND c.deleted_at IS NULL AND c.status<>'archived' ORDER BY c.display_name");
    $s->execute(array(':t'=>$tenant));
    $m['clients']=$s->fetchAll();

    /* Services continue to come from product_services. */
    $m['services']=array();
    $catalog=array();
    if(rfTable($pdo,'product_services')){
        $serviceSql="SELECT id,name,sku,description,unit_cost,unit_price,tax_percent FROM product_services WHERE tenant_id=:t AND item_type='service' AND status='active'";
        if(rfCol($pdo,'product_services','deleted_at'))$serviceSql.=" AND deleted_at IS NULL";
        $serviceSql.=" ORDER BY name,id";
        $s=$pdo->prepare($serviceSql);
        $s->execute(array(':t'=>$tenant));
        $serviceRows=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($serviceRows as $r){
            $m['services'][]=array('id'=>(int)$r['id'],'name'=>$r['name'],'sku'=>isset($r['sku'])?$r['sku']:null);
            $serviceCost=isset($r['unit_cost'])?(float)$r['unit_cost']:0;
            $servicePrice=isset($r['unit_price'])?(float)$r['unit_price']:0;
            $serviceMarkup=$serviceCost>0?(($servicePrice-$serviceCost)/$serviceCost)*100:0;
            $catalog[]=array(
                'id'=>(int)$r['id'],
                'source_id'=>(int)$r['id'],
                'item_source'=>'service',
                'item_type'=>'service',
                'name'=>$r['name'],
                'sku'=>isset($r['sku'])?$r['sku']:null,
                'description'=>isset($r['description'])?$r['description']:'',
                'unit_cost'=>$serviceCost,
                'unit_price'=>$servicePrice,
                'markup_percent'=>$serviceMarkup,
                'tax_percent'=>isset($r['tax_percent'])?(float)$r['tax_percent']:0
            );
        }
    }

    /* Products MUST come from the real products table, same as Add Invoice.
       Product option ids are returned as negative values so a products.id can
       never collide with a product_services.id in the single Select2 control. */
    if(rfTable($pdo,'products')){
        $productHasTaxPercent=rfCol($pdo,'products','tax_percent');
        $productHasTaxId=rfCol($pdo,'products','tax_id') && rfTable($pdo,'product_tax_rates');
        $taxExpr=$productHasTaxPercent?'p.tax_percent':'0';
        $taxJoin='';
        if($productHasTaxId && $productHasTaxPercent){
            $taxExpr="COALESCE(NULLIF(p.tax_percent,0),ptr.rate_percent,0)";
            $taxJoin=" LEFT JOIN product_tax_rates ptr ON ptr.id=p.tax_id AND ptr.tenant_id=p.tenant_id AND ptr.status='active' ";
        }
        $descExpr=rfCol($pdo,'products','description')?'p.description':'NULL';
        $skuExpr=rfCol($pdo,'products','sku')?'p.sku':'NULL';
        $costExpr=rfCol($pdo,'products','base_unit_price')?'p.base_unit_price':'0';
        $priceExpr=rfCol($pdo,'products','selling_price')?'p.selling_price':$costExpr;
        $markupTypeExpr=rfCol($pdo,'products','markup_type')?'p.markup_type':'NULL';
        $markupValueExpr=rfCol($pdo,'products','markup_value')?'p.markup_value':'NULL';
        $productSql="SELECT p.id AS source_id,$skuExpr AS sku,p.name,$descExpr AS description,$costExpr AS unit_cost,$priceExpr AS unit_price,$markupTypeExpr AS markup_type,$markupValueExpr AS markup_value,$taxExpr AS tax_percent FROM products p".$taxJoin." WHERE p.tenant_id=:t AND p.status='active'";
        if(rfCol($pdo,'products','deleted_at'))$productSql.=" AND p.deleted_at IS NULL";
        $productSql.=" ORDER BY p.name,p.id";
        $ps=$pdo->prepare($productSql);
        $ps->execute(array(':t'=>$tenant));
        foreach($ps->fetchAll(PDO::FETCH_ASSOC) as $r){
            $realId=(int)$r['source_id'];
            if($realId<=0)continue;
            $productCost=isset($r['unit_cost'])?(float)$r['unit_cost']:0;
            $productPrice=isset($r['unit_price'])?(float)$r['unit_price']:0;
            if(isset($r['markup_type']) && $r['markup_type']==='percentage' && $r['markup_value']!==null){
                $productMarkup=(float)$r['markup_value'];
            }else{
                $productMarkup=$productCost>0?(($productPrice-$productCost)/$productCost)*100:0;
            }
            $catalog[]=array(
                'id'=>-$realId,
                'source_id'=>$realId,
                'item_source'=>'product',
                'item_type'=>'product',
                'name'=>$r['name'],
                'sku'=>isset($r['sku'])?$r['sku']:null,
                'description'=>isset($r['description'])?$r['description']:'',
                'unit_cost'=>$productCost,
                'unit_price'=>$productPrice,
                'markup_percent'=>$productMarkup,
                'tax_percent'=>isset($r['tax_percent'])?(float)$r['tax_percent']:0
            );
        }
    }
    usort($catalog,function($a,$b){
        $ta=$a['item_type']==='service'?0:1;
        $tb=$b['item_type']==='service'?0:1;
        if($ta!==$tb)return $ta-$tb;
        return strcasecmp((string)$a['name'],(string)$b['name']);
    });
    $m['catalog']=$catalog;

    $s=$pdo->prepare("SELECT id,CONCAT(first_name,CASE WHEN last_name IS NOT NULL AND last_name<>'' THEN CONCAT(' ',last_name) ELSE '' END) name,email,job_title FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY first_name,last_name");
    $s->execute(array(':t'=>$tenant));
    $m['users']=$s->fetchAll();

    $s=$pdo->prepare("SELECT id,name,leader_user_id FROM teams WHERE tenant_id=:t AND status='active' ORDER BY name");
    $s->execute(array(':t'=>$tenant));
    $teams=$s->fetchAll();
    $ms=$pdo->prepare("SELECT tm.team_id,u.id,CONCAT(u.first_name,CASE WHEN u.last_name IS NOT NULL AND u.last_name<>'' THEN CONCAT(' ',u.last_name) ELSE '' END) name,u.email,u.job_title,tm.is_primary FROM team_members tm INNER JOIN users u ON u.id=tm.user_id AND u.tenant_id=:t AND u.status='active' AND u.deleted_at IS NULL INNER JOIN teams tt ON tt.id=tm.team_id AND tt.tenant_id=:t2 AND tt.status='active' ORDER BY tm.team_id,tm.is_primary DESC,u.id");
    $ms->execute(array(':t'=>$tenant,':t2'=>$tenant));
    $by=array();
    foreach($ms->fetchAll() as $r){$tid=(int)$r['team_id'];if(!isset($by[$tid]))$by[$tid]=array();$by[$tid][]=array('id'=>(int)$r['id'],'name'=>$r['name'],'email'=>$r['email'],'job_title'=>$r['job_title'],'is_primary'=>(int)$r['is_primary']);}
    foreach($teams as &$t){$t['members']=isset($by[(int)$t['id']])?$by[(int)$t['id']]:array();}
    unset($t);
    $m['teams']=$teams;
    $m['currency']=rfCurrency($pdo,$tenant);
    $m['checklist_templates']=array();
    if(rfTable($pdo,'checklist_templates')){
        $cs=$pdo->prepare("SELECT id,name,description,(SELECT COUNT(*) FROM checklist_template_items ci WHERE ci.checklist_template_id=checklist_templates.id) item_count FROM checklist_templates WHERE tenant_id=:t AND status='active' ORDER BY name");
        $cs->execute(array(':t'=>$tenant));
        $m['checklist_templates']=$cs->fetchAll();
    }
    $m['customer_email_on_create']=false;
    /* Do not touch SMTP while loading the request form metadata. */
    $m['smtp_configured']=null;
    return $m;
}
function rfNext(PDO $pdo,$tenant,$branch,$type,$table,$column,$fallback){$allowed=array('request'=>array('service_requests','request_no','REQ'),'assessment'=>array('assessments','assessment_no','ASM'),'quote'=>array('quotes','quote_no','QUO'),'job'=>array('jobs','job_no','JOB'));if(!isset($allowed[$type]))throw new Exception('Unsupported document type.');$sep=rfCol($pdo,'document_sequences','number_separator')?'number_separator':'separator';$s=$pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type=:dt AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1 FOR UPDATE");$s->execute(array(':t'=>$tenant,':dt'=>$type,':b'=>$branch>0?$branch:0,':b2'=>$branch>0?$branch:0));$r=$s->fetch();if(!$r){$tb=$allowed[$type][0];$col=$allowed[$type][1];$pre=$allowed[$type][2];$q=$pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX($col,'-',-1) AS UNSIGNED)) FROM $tb WHERE tenant_id=:t AND $col LIKE :p");$q->execute(array(':t'=>$tenant,':p'=>$pre.'-%'));return $pre.'-'.str_pad((string)((int)$q->fetchColumn()+1),6,'0',STR_PAD_LEFT);} $now=new DateTime('now');$y=$now->format('Y');$mo=$now->format('m');$fyStart=max(1,min(12,(int)$r['financial_year_start_month']));$fyY=(int)$now->format('n')>=$fyStart?(int)$y:(int)$y-1;$fy=$fyY.'-'.substr((string)($fyY+1),-2);$key='never';if($r['reset_period']==='monthly')$key=$y.$mo;elseif($r['reset_period']==='yearly')$key=$y;elseif($r['reset_period']==='financial_year')$key=$fy;$cur=(int)$r['current_number'];if($r['reset_period']!=='never'&&(string)$r['last_reset_key']!==(string)$key)$cur=0;$next=$cur+1;$mid='';if($r['middle_format']==='year')$mid=$y;elseif($r['middle_format']==='year_month')$mid=$y.$mo;elseif($r['middle_format']==='financial_year')$mid=$fy;elseif($r['middle_format']==='branch_year')$mid=(!empty($r['branch_code'])?$r['branch_code']:'BR').$y;$parts=array();if(!empty($r['prefix']))$parts[]=$r['prefix'];if($mid!=='')$parts[]=$mid;$parts[]=str_pad((string)$next,max(1,(int)$r['number_length']),'0',STR_PAD_LEFT);if(!empty($r['suffix']))$parts[]=$r['suffix'];$no=implode(isset($r[$sep])?(string)$r[$sep]:'-',$parts);$u=$pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");$u->execute(array(':n'=>$next,':k'=>$key,':id'=>$r['id']));return $no;}
function rfResolveUsers(PDO $pdo,$tenant,$mode,$individual,$team,$multi){$ids=array();$resolvedTeam=null;if($mode==='individual'){$u=rfValid($pdo,'users',$tenant,$individual,"AND status='active'");if($u===null)rfRes(422,false,'Select a valid employee.');$ids[]=$u;}elseif($mode==='team'){$resolvedTeam=rfValid($pdo,'teams',$tenant,$team,"AND status='active'");if($resolvedTeam===null)rfRes(422,false,'Select a valid team.');$s=$pdo->prepare("SELECT u.id FROM team_members tm INNER JOIN users u ON u.id=tm.user_id AND u.tenant_id=:t AND u.status='active' AND u.deleted_at IS NULL INNER JOIN teams tt ON tt.id=tm.team_id AND tt.tenant_id=:t2 AND tt.status='active' WHERE tm.team_id=:team ORDER BY CASE WHEN tt.leader_user_id=u.id THEN 0 ELSE 1 END,tm.is_primary DESC,u.id");$s->execute(array(':t'=>$tenant,':t2'=>$tenant,':team'=>$resolvedTeam));$ids=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));if(!$ids)rfRes(422,false,'Selected team has no active employees.');}else{foreach((array)$multi as $raw){$u=rfValid($pdo,'users',$tenant,(int)$raw,"AND status='active'");if($u!==null)$ids[$u]=$u;}$ids=array_values($ids);if(!$ids)rfRes(422,false,'Select at least one employee.');}return array('users'=>array_values(array_unique($ids)),'team_id'=>$resolvedTeam);}
function rfNotify(PDO $pdo,$tenant,$branch,$users,$eventKey,$title,$message,$relatedType,$relatedId,$inApp,$email){
    $sum=array('in_app'=>0,'email_sent'=>0,'email_failed'=>0,'email_skipped'=>0,'email_messages'=>array(),'smtp_config_id'=>null,'smtp_config_name'=>null);
    if(!$users)return $sum;
    $config=$email?rfSmtpConfig($pdo,$tenant,$branch):null;
    if($config){$sum['smtp_config_id']=(int)$config['id'];$sum['smtp_config_name']=isset($config['config_name'])?$config['config_name']:null;}
    $ph=array();$pa=array(':t'=>$tenant);
    foreach($users as $i=>$id){$k=':u'.$i;$ph[]=$k;$pa[$k]=(int)$id;}
    $q=$pdo->prepare("SELECT id,email,first_name,last_name FROM users WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL AND id IN(".implode(',',$ph).")");
    $q->execute($pa);
    foreach($q->fetchAll() as $r){
        if($inApp && rfTable($pdo,'in_app_notifications')){
            try{$n=$pdo->prepare("INSERT INTO in_app_notifications(tenant_id,user_id,title,message,related_type,related_id,action_url,icon_name,is_read) VALUES(:t,:u,:title,:message,:rt,:rid,:url,'bell',0)");$n->execute(array(':t'=>$tenant,':u'=>$r['id'],':title'=>$title,':message'=>$message,':rt'=>$relatedType,':rid'=>$relatedId,':url'=>$relatedType==='job'?'my-job-view.php?id='.(int)$relatedId:'requests.php?request_id='.(int)$relatedId));$sum['in_app']++;}catch(Throwable $e){error_log('request in-app notification: '.$e->getMessage());}
        }
        if($email){
            $addr=trim((string)$r['email']);
            if(!filter_var($addr,FILTER_VALIDATE_EMAIL)){$sum['email_skipped']++;$sum['email_messages'][]='Employee '.(int)$r['id'].' has no valid email.';continue;}
            if(!$config || !function_exists('fieldplxSendPlatformMail')){$sum['email_skipped']++;if(!$sum['email_messages'])$sum['email_messages'][]='No active default Platform SMTP configuration was found.';continue;}
            try{
                $name=trim((string)$r['first_name'].' '.(string)$r['last_name']);
                $html='<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#1f2d3d">'.
                    '<h2 style="color:#123d70">'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h2>'.
                    '<p>Hello '.htmlspecialchars($name!==''?$name:'Employee',ENT_QUOTES,'UTF-8').',</p>'.
                    '<p>'.nl2br(htmlspecialchars($message,ENT_QUOTES,'UTF-8')).'</p>'.
                    '<p>Please login to FieldPlx to view the assigned work details.</p>'.
                    '<p style="margin-top:24px">FieldPlx</p></div>';
                $sentMeta=fieldplxSendPlatformMail($pdo,$addr,$title,$html);
                if(is_array($sentMeta)&&!empty($sentMeta['smtp_id'])){$sum['smtp_config_id']=(int)$sentMeta['smtp_id'];if(!empty($sentMeta['config_name']))$sum['smtp_config_name']=$sentMeta['config_name'];}
                $sum['email_sent']++;$sum['email_messages'][]='Email sent to '.$addr.'.';
                if(rfTable($pdo,'notification_queue')&&rfTable($pdo,'notification_events')){
                    try{$ev=$pdo->prepare("SELECT id FROM notification_events WHERE event_key=:k AND is_active=1 LIMIT 1");$ev->execute(array(':k'=>$eventKey));$eventId=(int)$ev->fetchColumn();if($eventId<=0){$ev=$pdo->prepare("SELECT id FROM notification_events WHERE event_key='request.assigned' AND is_active=1 LIMIT 1");$ev->execute();$eventId=(int)$ev->fetchColumn();}if($eventId>0){$n=$pdo->prepare("INSERT INTO notification_queue(tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address,related_type,related_id,subject,body,smtp_config_id,status,scheduled_at,sent_at) VALUES(:t,:b,:e,'email','user',:u,:addr,:rt,:rid,:sub,:body,:smtp,'sent',NOW(),NOW())");$n->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':e'=>$eventId,':u'=>$r['id'],':addr'=>$addr,':rt'=>$relatedType,':rid'=>$relatedId,':sub'=>$title,':body'=>$message,':smtp'=>$sum['smtp_config_id']));}}catch(Throwable $e){error_log('request email queue: '.$e->getMessage());}
                }
            }catch(Throwable $e){$sum['email_failed']++;$sum['email_messages'][]='Email failed for '.$addr.': '.$e->getMessage();error_log('request platform SMTP: '.$e->getMessage());}
        }
    }
    return $sum;
}


function rfRequestNotificationEventId(PDO $pdo){
    if(!rfTable($pdo,'notification_events'))return 0;
    foreach(array('request.created','service_request.created','request.received','request.assigned') as $key){
        try{$s=$pdo->prepare("SELECT id FROM notification_events WHERE event_key=:k AND is_active=1 ORDER BY id LIMIT 1");$s->execute(array(':k'=>$key));$id=(int)$s->fetchColumn();if($id>0)return $id;}catch(Throwable $e){error_log('request notification event lookup '.$e->getMessage());return 0;}
    }
    return 0;
}
function rfRequestCustomerQueueStart(PDO $pdo,$tenant,$branch,$clientId,$email,$requestId,$subject,$body,$smtpId){
    if(!rfTable($pdo,'notification_queue'))return 0;
    $eventId=rfRequestNotificationEventId($pdo);if($eventId<=0)return 0;
    try{
        $s=$pdo->prepare("INSERT INTO notification_queue(tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address,related_type,related_id,subject,body,smtp_config_id,status,attempts,scheduled_at,sent_at,error_message,created_at) VALUES(:t,:b,:e,'email','client',:c,:addr,'service_request',:rid,:sub,:body,:smtp,'processing',1,NOW(),NULL,NULL,NOW())");
        $s->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':e'=>$eventId,':c'=>$clientId>0?$clientId:null,':addr'=>$email!==''?$email:null,':rid'=>$requestId,':sub'=>substr($subject,0,255),':body'=>$body,':smtp'=>$smtpId>0?$smtpId:null));
        return (int)$pdo->lastInsertId();
    }catch(Throwable $e){error_log('request customer mail queue '.$e->getMessage());return 0;}
}
function rfRequestCustomerQueueFinish(PDO $pdo,$id,$status,$error=''){
    if($id<=0||!rfTable($pdo,'notification_queue'))return;
    if(!in_array($status,array('sent','failed','suppressed'),true))$status='failed';
    try{$s=$pdo->prepare("UPDATE notification_queue SET status=:st,sent_at=CASE WHEN :sent='sent' THEN NOW() ELSE NULL END,error_message=:err WHERE id=:id LIMIT 1");$s->execute(array(':st'=>$status,':sent'=>$status,':err'=>$error!==''?$error:null,':id'=>$id));}catch(Throwable $e){error_log('request customer mail queue finish '.$e->getMessage());}
}
function rfCustomerRequestEmail(PDO $pdo,$tenant,$branch,$clientId,$requestId,$requestNo,$title,$description,$assessment=null){
    $out=array('requested'=>1,'sent'=>0,'status'=>'skipped','recipient'=>'','message'=>'','smtp_config_id'=>null,'smtp_config_name'=>null,'mail_log_id'=>0);
    try{
        $s=$pdo->prepare("SELECT id,display_name,company_name,email FROM clients WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL AND status<>'archived' LIMIT 1");
        $s->execute(array(':id'=>$clientId,':t'=>$tenant));$client=$s->fetch(PDO::FETCH_ASSOC);
        if(!$client){$out['message']='Customer email was skipped because the customer record could not be loaded.';return $out;}
        $email=trim((string)$client['email']);$out['recipient']=$email;
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)){$out['message']='Customer email was skipped because no valid customer email address is saved.';return $out;}
        $cfg=rfSmtpConfig($pdo,$tenant,$branch);
        if(!$cfg||!function_exists('fieldplxSendPlatformMail')){$out['message']='Customer email was skipped because no active default Platform SMTP configuration was found.';return $out;}
        $out['smtp_config_id']=isset($cfg['id'])?(int)$cfg['id']:null;$out['smtp_config_name']=isset($cfg['config_name'])?$cfg['config_name']:null;
        $tenantName='FieldPlx';
        try{$ts=$pdo->prepare("SELECT COALESCE(NULLIF(display_name,''),NULLIF(legal_name,''),'FieldPlx') FROM tenants WHERE id=:t LIMIT 1");$ts->execute(array(':t'=>$tenant));$tn=trim((string)$ts->fetchColumn());if($tn!=='')$tenantName=$tn;}catch(Throwable $e){}
        $customerName=trim((string)$client['display_name']);if($customerName==='')$customerName='Customer';
        $subject='Service Request '.$requestNo.' received';
        $assessmentText='';
        if(is_array($assessment)&&!empty($assessment['assessment_no']))$assessmentText='<p><strong>On-site assessment:</strong> '.htmlspecialchars((string)$assessment['assessment_no'],ENT_QUOTES,'UTF-8').'</p>';
        $html='<div style="font-family:Arial,Helvetica,sans-serif;max-width:680px;margin:0 auto;color:#183548;line-height:1.55">'.
            '<h2 style="margin:0 0 18px;color:#0b3142">Service request received</h2>'.
            '<p>Hello '.htmlspecialchars($customerName,ENT_QUOTES,'UTF-8').',</p>'.
            '<p>We have received your service request and saved it successfully.</p>'.
            '<div style="margin:18px 0;padding:16px;border:1px solid #e2e8ec;border-radius:8px;background:#f8fafb">'.
            '<p style="margin:0 0 7px"><strong>Request:</strong> '.htmlspecialchars($requestNo,ENT_QUOTES,'UTF-8').'</p>'.
            '<p style="margin:0 0 7px"><strong>Title:</strong> '.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</p>'.
            ($description!==''?'<p style="margin:0"><strong>Service details:</strong><br>'.nl2br(htmlspecialchars($description,ENT_QUOTES,'UTF-8')).'</p>':'').
            '</div>'.$assessmentText.
            '<p>Our team will contact you if any additional information or scheduling confirmation is required.</p>'.
            '<p style="margin-top:24px">Thank you,<br>'.htmlspecialchars($tenantName,ENT_QUOTES,'UTF-8').'</p></div>';
        $logId=rfRequestCustomerQueueStart($pdo,$tenant,$branch,$clientId,$email,$requestId,$subject,$html,(int)$cfg['id']);$out['mail_log_id']=$logId;
        try{
            $sentMeta=fieldplxSendPlatformMail($pdo,$email,$subject,$html);
            if(is_array($sentMeta)&&!empty($sentMeta['smtp_id']))$out['smtp_config_id']=(int)$sentMeta['smtp_id'];
            if(is_array($sentMeta)&&!empty($sentMeta['config_name']))$out['smtp_config_name']=$sentMeta['config_name'];
            $out['sent']=1;$out['status']='sent';$out['message']='Customer confirmation email sent to '.$email.'.';rfRequestCustomerQueueFinish($pdo,$logId,'sent','');
        }catch(Throwable $e){$out['status']='failed';$out['message']='Customer email failed: '.$e->getMessage();rfRequestCustomerQueueFinish($pdo,$logId,'failed',$e->getMessage());error_log('request customer platform SMTP '.$e->getMessage());}
    }catch(Throwable $e){$out['status']='failed';$out['message']='Customer email failed: '.$e->getMessage();error_log('request customer email '.$e->getMessage());}
    return $out;
}
function rfMentionUsers(PDO $pdo,$tenant,$raw){
    $ids=rfJsonArray($raw);$out=array();foreach($ids as $id){$id=(int)$id;if($id<=0)continue;$v=rfValid($pdo,'users',$tenant,$id,"AND status='active'");if($v!==null)$out[$v]=$v;}return array_values($out);
}

function rfLog(PDO $pdo,$tenant,$branch,$user,$type,$related,$rid,$client,$title,$details){try{$s=$pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user',:e,:rt,:rid,:cid,:title,:details,0)");$s->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':u'=>$user,':e'=>$type,':rt'=>$related,':rid'=>$rid,':cid'=>$client,':title'=>substr($title,0,255),':details'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));}catch(Throwable $e){error_log('activity '.$e->getMessage());}}


function rfJsonArray($raw){$d=json_decode((string)$raw,true);return is_array($d)?$d:array();}
function rfNormalizeRequestLines(PDO $pdo,$tenant,$raw){
    $rows=rfJsonArray($raw);$out=array();
    foreach($rows as $i=>$x){
        /* Positive id = product_services service. Negative id = products product. */
        $catalogId=isset($x['product_service_id'])?(int)$x['product_service_id']:0;
        $cat=null;$productServiceId=null;$productId=null;

        if($catalogId>0){
            $sql="SELECT id,name,item_type,description,unit_cost,unit_price,tax_percent FROM product_services WHERE id=:id AND tenant_id=:t AND status='active'";
            if(rfCol($pdo,'product_services','deleted_at'))$sql.=" AND deleted_at IS NULL";
            $sql.=" LIMIT 1";
            $s=$pdo->prepare($sql);$s->execute(array(':id'=>$catalogId,':t'=>$tenant));$cat=$s->fetch(PDO::FETCH_ASSOC);
            if(!$cat)continue;
            $productServiceId=(int)$cat['id'];
        }elseif($catalogId<0){
            $productId=abs($catalogId);
            if(!rfTable($pdo,'products'))continue;
            $productHasTaxPercent=rfCol($pdo,'products','tax_percent');
            $productHasTaxId=rfCol($pdo,'products','tax_id') && rfTable($pdo,'product_tax_rates');
            $taxExpr=$productHasTaxPercent?'p.tax_percent':'0';$taxJoin='';
            if($productHasTaxId && $productHasTaxPercent){$taxExpr="COALESCE(NULLIF(p.tax_percent,0),ptr.rate_percent,0)";$taxJoin=" LEFT JOIN product_tax_rates ptr ON ptr.id=p.tax_id AND ptr.tenant_id=p.tenant_id AND ptr.status='active' ";}
            $descExpr=rfCol($pdo,'products','description')?'p.description':'NULL';
            $costExpr=rfCol($pdo,'products','base_unit_price')?'p.base_unit_price':'0';
            $priceExpr=rfCol($pdo,'products','selling_price')?'p.selling_price':$costExpr;
            $sql="SELECT p.id,p.name,'product' AS item_type,$descExpr AS description,$costExpr AS unit_cost,$priceExpr AS unit_price,$taxExpr AS tax_percent FROM products p".$taxJoin." WHERE p.id=:id AND p.tenant_id=:t AND p.status='active'";
            if(rfCol($pdo,'products','deleted_at'))$sql.=" AND p.deleted_at IS NULL";
            $sql.=" LIMIT 1";
            $s=$pdo->prepare($sql);$s->execute(array(':id'=>$productId,':t'=>$tenant));$cat=$s->fetch(PDO::FETCH_ASSOC);
            if(!$cat)continue;
        }

        $name=trim((string)(isset($x['item_name'])?$x['item_name']:($cat?$cat['name']:'')));if($name==='')continue;
        $qty=max(.001,(float)(isset($x['quantity'])?$x['quantity']:1));
        $cost=max(0,(float)(isset($x['unit_cost'])?$x['unit_cost']:($cat?$cat['unit_cost']:0)));
        $price=max(0,(float)(isset($x['unit_price'])?$x['unit_price']:($cat?$cat['unit_price']:0)));
        $taxPct=max(0,min(100,(float)(isset($x['tax_percent'])?$x['tax_percent']:($cat?$cat['tax_percent']:0))));
        $base=$qty*$price;$tax=$base*$taxPct/100;
        $out[]=array(
            'product_service_id'=>$productServiceId,
            'product_id'=>$productId,
            'catalog_id'=>$catalogId,
            'item_type'=>$productId?'product':trim((string)(isset($x['item_type'])?$x['item_type']:($cat?$cat['item_type']:'service'))),
            'item_name'=>substr($name,0,255),
            'description'=>trim((string)(isset($x['description'])?$x['description']:($cat?$cat['description']:''))),
            'quantity'=>$qty,
            'unit_cost'=>$cost,
            'unit_price'=>$price,
            'tax_percent'=>$taxPct,
            'tax_amount'=>$tax,
            'line_total'=>$base+$tax,
            'sort_order'=>$i+1
        );
    }
    return $out;
}
function rfSaveRequestLines(PDO $pdo,$tenant,$requestId,$rows){
    if(!$rows)return 0;if(!rfTable($pdo,'service_request_line_items'))rfRes(500,false,'Run migration_request_jobber_ui.sql once before saving request Product / Service line items.');
    $d=$pdo->prepare("DELETE FROM service_request_line_items WHERE tenant_id=:t AND request_id=:r");$d->execute(array(':t'=>$tenant,':r'=>$requestId));
    $s=$pdo->prepare("INSERT INTO service_request_line_items(tenant_id,request_id,product_service_id,item_type,item_name,description,quantity,unit_cost,unit_price,tax_percent,tax_amount,line_total,sort_order) VALUES(:t,:r,:ps,:type,:name,:d,:q,:cost,:price,:tp,:ta,:total,:sort)");
    foreach($rows as $x)$s->execute(array(':t'=>$tenant,':r'=>$requestId,':ps'=>$x['product_service_id'],':type'=>$x['item_type']!==''?$x['item_type']:'other',':name'=>$x['item_name'],':d'=>$x['description']!==''?$x['description']:null,':q'=>$x['quantity'],':cost'=>$x['unit_cost'],':price'=>$x['unit_price'],':tp'=>$x['tax_percent'],':ta'=>$x['tax_amount'],':total'=>$x['line_total'],':sort'=>$x['sort_order']));
    return count($rows);
}
function rfSaveRequestChecklist(PDO $pdo,$tenant,$requestId,$createdBy,$raw,$selectedRaw='[]',$jobId=null,$primaryUser=null){
    $raw=trim((string)$raw);
    $selected=rfJsonArray($selectedRaw);
    $selectedIds=array();
    foreach($selected as $id){$id=(int)$id;if($id>0)$selectedIds[$id]=$id;}
    if($raw===''&&!$selectedIds)return array('template_ids'=>array(),'request_links'=>array(),'job_checklists'=>array());
    foreach(array('checklist_templates','checklist_template_items','service_request_checklists') as $t){if(!rfTable($pdo,$t))rfRes(500,false,'Run migration_request_jobber_ui.sql once before using checklists on requests.');}

    $templateIds=array_values($selectedIds);
    if($raw!==''){
        $c=json_decode($raw,true);if(!is_array($c))rfRes(422,false,'Checklist data is invalid.');
        $name=substr(trim((string)(isset($c['name'])?$c['name']:'')),0,190);
        $items=isset($c['items'])&&is_array($c['items'])?$c['items']:array();
        if($name===''||!$items)rfRes(422,false,'The checklist needs a name and at least one question.');
        $s=$pdo->prepare("INSERT INTO checklist_templates(tenant_id,name,description,status,created_by) VALUES(:t,:n,:d,'active',:u)");
        $s->execute(array(':t'=>$tenant,':n'=>$name,':d'=>!empty($c['description'])?$c['description']:null,':u'=>$createdBy));
        $newTemplateId=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare("INSERT INTO checklist_template_items(checklist_template_id,title,description,is_required,sort_order) VALUES(:ct,:title,:d,:r,:o)");
        $order=0;
        foreach($items as $item){
            $title=substr(trim((string)(isset($item['title'])?$item['title']:'')),0,255);if($title==='')continue;$order++;
            $meta=array('section_title'=>isset($item['section_title'])?(string)$item['section_title']:'','question_type'=>isset($item['question_type'])?(string)$item['question_type']:'short_answer','options'=>isset($item['options'])&&is_array($item['options'])?$item['options']:array());
            $ins->execute(array(':ct'=>$newTemplateId,':title'=>$title,':d'=>json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':r'=>!empty($item['is_required'])||!empty($item['required'])?1:0,':o'=>$order));
        }
        if($order===0)rfRes(422,false,'The checklist needs at least one valid question.');
        $templateIds[]=$newTemplateId;
    }

    $templateIds=array_values(array_unique(array_map('intval',$templateIds)));
    $validTemplates=array();
    if($templateIds){
        $ph=array();$params=array(':t'=>$tenant);
        foreach($templateIds as $i=>$id){$k=':ct'.$i;$ph[]=$k;$params[$k]=$id;}
        $q=$pdo->prepare("SELECT id,name FROM checklist_templates WHERE tenant_id=:t AND status='active' AND id IN(".implode(',',$ph).") ORDER BY name,id");
        $q->execute($params);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$validTemplates[(int)$row['id']]=$row;
    }
    if(!$validTemplates)return array('template_ids'=>array(),'request_links'=>array(),'job_checklists'=>array());

    $requestLinks=array();$jobChecklistIds=array();
    $exists=$pdo->prepare("SELECT id FROM service_request_checklists WHERE tenant_id=:t AND request_id=:r AND checklist_template_id=:ct LIMIT 1");
    $link=$pdo->prepare("INSERT INTO service_request_checklists(tenant_id,request_id,checklist_template_id,created_by) VALUES(:t,:r,:ct,:u)");
    foreach($validTemplates as $templateId=>$template){
        $exists->execute(array(':t'=>$tenant,':r'=>$requestId,':ct'=>$templateId));
        $linkId=(int)$exists->fetchColumn();
        if($linkId<=0){$link->execute(array(':t'=>$tenant,':r'=>$requestId,':ct'=>$templateId,':u'=>$createdBy));$linkId=(int)$pdo->lastInsertId();}
        $requestLinks[]=$linkId;

        if($jobId&&rfTable($pdo,'job_checklists')&&rfTable($pdo,'job_checklist_items')){
            $cols="tenant_id,job_id,visit_id,checklist_template_id,name,status";$vals=":t,:j,NULL,:ct,:n,'open'";$pa=array(':t'=>$tenant,':j'=>$jobId,':ct'=>$templateId,':n'=>$template['name']);
            if(rfCol($pdo,'job_checklists','assigned_user_id')){$cols.=',assigned_user_id';$vals.=',:au';$pa[':au']=$primaryUser?$primaryUser:null;}
            $jc=$pdo->prepare("INSERT INTO job_checklists($cols) VALUES($vals)");$jc->execute($pa);$jid=(int)$pdo->lastInsertId();$jobChecklistIds[]=$jid;
            $it=$pdo->prepare("SELECT title,description,is_required,sort_order FROM checklist_template_items WHERE checklist_template_id=:ct ORDER BY sort_order,id");$it->execute(array(':ct'=>$templateId));
            foreach($it->fetchAll() as $x){
                if(rfCol($pdo,'job_checklist_items','tenant_id')){
                    $ji=$pdo->prepare("INSERT INTO job_checklist_items(tenant_id,job_checklist_id,title,description,is_required,is_completed,sort_order) VALUES(:t,:jc,:title,:d,:r,0,:o)");
                    $ji->execute(array(':t'=>$tenant,':jc'=>$jid,':title'=>$x['title'],':d'=>$x['description'],':r'=>$x['is_required'],':o'=>$x['sort_order']));
                }else{
                    $ji=$pdo->prepare("INSERT INTO job_checklist_items(job_checklist_id,title,description,is_required,is_completed,sort_order) VALUES(:jc,:title,:d,:r,0,:o)");
                    $ji->execute(array(':jc'=>$jid,':title'=>$x['title'],':d'=>$x['description'],':r'=>$x['is_required'],':o'=>$x['sort_order']));
                }
            }
        }
    }
    return array('template_ids'=>array_keys($validTemplates),'request_links'=>$requestLinks,'job_checklists'=>$jobChecklistIds);
}
function rfCreateAssessment(PDO $pdo,$tenant,$branch,$requestId,$client,$location,$assigned,$createdBy){
    if((string)rfP('assessment_enabled','0')!=='1')return null;if(!rfTable($pdo,'assessments'))rfRes(500,false,'The assessments table is missing. Run the FieldPlx request/assessment migration first.');
    $later=isset($_POST['assessment_schedule_later'])&&$_POST['assessment_schedule_later']==='1';$any=isset($_POST['assessment_anytime'])&&$_POST['assessment_anytime']==='1';$sd=trim((string)rfP('assessment_start_date',''));$ed=trim((string)rfP('assessment_end_date',''));$st=trim((string)rfP('assessment_start_time',''));$et=trim((string)rfP('assessment_end_time',''));$instructions=trim((string)rfP('assessment_instructions',''));$reminder=trim((string)rfP('team_reminder','none'));
    $start=null;$end=null;$status='draft';if(!$later){if($sd==='')rfRes(422,false,'Select the assessment start date or choose Schedule later.');if($ed==='')$ed=$sd;if($any){$st='00:00';$et='23:59';}if($st===''||$et==='')rfRes(422,false,'Enter assessment start and end time, or select Anytime.');$start=$sd.' '.$st.':00';$end=$ed.' '.$et.':00';if(strtotime($end)<=strtotime($start))rfRes(422,false,'Assessment end must be after the start.');$status='scheduled';}
    $no=rfNext($pdo,$tenant,$branch,'assessment','assessments','assessment_no','ASM');$primary=count($assigned)?(int)$assigned[0]:null;$notes=$instructions;if($reminder!==''&&$reminder!=='none')$notes.=($notes!==''?"\n\n":'').'Team reminder: '.str_replace('_',' ',$reminder);
    $s=$pdo->prepare("INSERT INTO assessments(tenant_id,branch_id,assessment_no,request_id,client_id,location_id,assigned_user_id,scheduled_start,scheduled_end,status,notes,created_by) VALUES(:t,:b,:no,:r,:c,:l,:u,:ss,:se,:st,:n,:by)");$s->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':no'=>$no,':r'=>$requestId,':c'=>$client,':l'=>$location,':u'=>$primary,':ss'=>$start,':se'=>$end,':st'=>$status,':n'=>$notes!==''?$notes:null,':by'=>$createdBy));return array('id'=>(int)$pdo->lastInsertId(),'assessment_no'=>$no,'status'=>$status);
}
function rfSaveRequestUploads(PDO $pdo,$tenant,$requestId,$user){
    $sum=array('saved'=>0,'failed'=>0,'messages'=>array());
    if(!rfTable($pdo,'attachments')) return $sum;
    $groups=array(
        'request_images'=>array('before_photo',10),
        'request_attachments'=>array('file',20),
        'line_item_images'=>array('before_photo',20)
    );
    $base=dirname(__DIR__).'/uploads/requests/'.$tenant.'/'.$requestId;
    if(!is_dir($base) && !@mkdir($base,0775,true) && !is_dir($base)){
        $sum['messages'][]='Unable to create request upload directory.';
        return $sum;
    }
    foreach($groups as $field=>$cfg){
        if(empty($_FILES[$field]) || !isset($_FILES[$field]['name'])) continue;
        $names=(array)$_FILES[$field]['name'];
        $tmp=(array)$_FILES[$field]['tmp_name'];
        $err=(array)$_FILES[$field]['error'];
        $sizes=(array)$_FILES[$field]['size'];
        $types=(array)$_FILES[$field]['type'];
        $limit=$cfg[1];
        for($i=0;$i<count($names)&&$i<$limit;$i++){
            if((int)$err[$i]!==UPLOAD_ERR_OK || !is_uploaded_file($tmp[$i])) continue;
            $original=basename((string)$names[$i]);
            $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
            $safe=preg_replace('/[^A-Za-z0-9._-]+/','-',pathinfo($original,PATHINFO_FILENAME));
            if($safe==='') $safe='file';
            $file=$safe.'-'.date('YmdHis').'-'.bin2hex(random_bytes(3)).($ext!==''?'.'.$ext:'');
            $dest=$base.'/'.$file;
            if(!@move_uploaded_file($tmp[$i],$dest)){$sum['failed']++;continue;}
            $rel='uploads/requests/'.$tenant.'/'.$requestId.'/'.$file;
            try{
                $q=$pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type) VALUES(:t,'service_request',:r,:u,:n,:p,:m,:s,:a)");
                $q->execute(array(':t'=>$tenant,':r'=>$requestId,':u'=>$user,':n'=>$original,':p'=>$rel,':m'=>isset($types[$i])?$types[$i]:null,':s'=>isset($sizes[$i])?(int)$sizes[$i]:null,':a'=>$cfg[0]));
                $sum['saved']++;
            }catch(Throwable $e){
                $sum['failed']++;
                $sum['messages'][]=$e->getMessage();
            }
        }
    }
    return $sum;
}

$tenant=isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;$user=isset($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:0;$sessionBranch=isset($_SESSION['branch_id'])?(int)$_SESSION['branch_id']:0;if($tenant<=0||$user<=0)rfRes(401,false,'Authentication required.');$csrf=(string)rfP('csrf_token','');$sess=isset($_SESSION['add_request_csrf_token'])?(string)$_SESSION['add_request_csrf_token']:'';if($csrf===''||$sess===''||!hash_equals($sess,$csrf))rfRes(419,false,'Your form session expired. Refresh and try again.');$a=trim((string)rfP('action',''));
try{
if($a==='meta')rfRes(200,true,'Form data loaded.',array('meta'=>rfMeta($pdo,$tenant,$sessionBranch)));

if($a==='create_customer'){
  if(!rfTable($pdo,'clients'))rfRes(500,false,'Customers table is not available.');
  $name=substr(trim((string)rfP('display_name','')),0,190);
  $company=substr(trim((string)rfP('company_name','')),0,190);
  $email=strtolower(substr(trim((string)rfP('email','')),0,190));
  $phone=substr(trim((string)rfP('phone','')),0,50);
  $branchId=(int)rfP('branch_id',$sessionBranch);
  if($name==='')rfRes(422,false,'Customer name is required.');
  if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))rfRes(422,false,'Enter a valid customer email address.');
  if($branchId>0&&rfValid($pdo,'branches',$tenant,$branchId,"AND status='active'")===null)$branchId=$sessionBranch;
  if($branchId<=0)$branchId=null;
  if($email!==''){
    $dup=$pdo->prepare("SELECT id FROM clients WHERE tenant_id=:t AND LOWER(email)=LOWER(:e) AND deleted_at IS NULL LIMIT 1");
    $dup->execute(array(':t'=>$tenant,':e'=>$email));
    if($dup->fetchColumn())rfRes(409,false,'This email is already used by another customer.');
  }
  $stmt=$pdo->prepare("INSERT INTO clients(tenant_id,branch_id,client_type,display_name,company_name,first_name,last_name,email,phone,alternate_phone,source,preferred_contact_method,allow_email,allow_sms,status,tax_number,notes,account_manager_id,created_by,last_activity_at,created_at,updated_at,deleted_at) VALUES(:t,:b,'client',:n,:company,NULL,NULL,:email,:phone,NULL,'request','email',:allow_email,0,'active',NULL,NULL,NULL,:u,NOW(),NOW(),NOW(),NULL)");
  $stmt->execute(array(':t'=>$tenant,':b'=>$branchId,':n'=>$name,':company'=>$company!==''?$company:null,':email'=>$email!==''?$email:null,':phone'=>$phone!==''?$phone:null,':allow_email'=>$email!==''?1:0,':u'=>$user));
  $id=(int)$pdo->lastInsertId();
  $client=array('id'=>$id,'name'=>$name,'display_name'=>$name,'company_name'=>$company!==''?$company:null,'email'=>$email!==''?$email:null,'phone'=>$phone!==''?$phone:null,'branch_id'=>$branchId,'primary_location_id'=>null);
  rfLog($pdo,$tenant,$branchId!==null?$branchId:$sessionBranch,$user,'customer_created_from_request','client',$id,$id,'Customer created from service request',array('customer'=>$client));
  if(function_exists('tenantAuditLog')){try{tenantAuditLog($pdo,'CUSTOMER_CREATED_FROM_REQUEST',$tenant,$branchId!==null?$branchId:$sessionBranch,$user,'client',$id,null,$client);}catch(Throwable $e){error_log('request customer audit '.$e->getMessage());}}
  rfRes(200,true,'Customer created successfully.',array('client'=>$client));
}

if($a==='create_catalog_item'){
  $itemType=strtolower(trim((string)rfP('item_type','service')));
  if(!in_array($itemType,array('service','product'),true))rfRes(422,false,'Select Service or Product.');
  $name=substr(trim((string)rfP('name','')),0,190);
  $description=trim((string)rfP('description',''));
  $unitCost=max(0,(float)rfP('unit_cost',0));
  $markup=max(0,(float)rfP('markup_percent',0));
  $unitPriceRaw=trim((string)rfP('unit_price',''));
  $unitPrice=$unitPriceRaw===''?round($unitCost*(1+($markup/100)),2):max(0,(float)$unitPriceRaw);
  $taxPercent=max(0,min(100,(float)rfP('tax_percent',0)));
  if($name==='')rfRes(422,false,'Product / Service name is required.');

  if($itemType==='service'){
    if(!rfTable($pdo,'product_services'))rfRes(500,false,'Service table is not available.');
    $findSql="SELECT id,name,item_type,description,unit_cost,unit_price,tax_percent FROM product_services WHERE tenant_id=:t AND item_type='service' AND LOWER(name)=LOWER(:n) AND status='active'";
    if(rfCol($pdo,'product_services','deleted_at'))$findSql.=" AND deleted_at IS NULL";
    $findSql.=" LIMIT 1";
    $find=$pdo->prepare($findSql);$find->execute(array(':t'=>$tenant,':n'=>$name));
    $existing=$find->fetch(PDO::FETCH_ASSOC);
    if($existing){$existing['id']=(int)$existing['id'];$existing['source_id']=$existing['id'];$existing['item_source']='service';$ec=(float)$existing['unit_cost'];$ep=(float)$existing['unit_price'];$existing['markup_percent']=$ec>0?(($ep-$ec)/$ec)*100:0;rfRes(200,true,'Existing service selected.',array('created'=>0,'item'=>$existing));}

    $stmt=$pdo->prepare("INSERT INTO product_services(tenant_id,category_id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent,is_bookable,estimated_duration_minutes,status,created_at,updated_at,deleted_at) VALUES(:t,NULL,'service',:n,NULL,:d,'Service',:cost,:price,:tax,0,NULL,'active',NOW(),NOW(),NULL)");
    $stmt->execute(array(':t'=>$tenant,':n'=>$name,':d'=>$description!==''?$description:null,':cost'=>$unitCost,':price'=>$unitPrice,':tax'=>$taxPercent));
    $id=(int)$pdo->lastInsertId();
    $serviceMarkup=$unitCost>0?(($unitPrice-$unitCost)/$unitCost)*100:0;$item=array('id'=>$id,'source_id'=>$id,'item_source'=>'service','name'=>$name,'item_type'=>'service','description'=>$description,'unit_cost'=>$unitCost,'unit_price'=>$unitPrice,'markup_percent'=>$serviceMarkup,'tax_percent'=>$taxPercent);
    rfLog($pdo,$tenant,$sessionBranch,$user,'service_created_from_request','product_service',$id,null,'Service created from service request',array('item'=>$item));
    if(function_exists('tenantAuditLog')){try{tenantAuditLog($pdo,'SERVICE_CREATED_FROM_REQUEST',$tenant,$sessionBranch,$user,'product_service',$id,null,$item);}catch(Throwable $e){error_log('request catalog audit '.$e->getMessage());}}
    rfRes(200,true,'Service created successfully.',array('created'=>1,'item'=>$item));
  }

  /* Product creation uses the real products table, exactly like Add Invoice. */
  if(!rfTable($pdo,'products'))rfRes(500,false,'Products table is not available.');
  $findSql="SELECT p.id,p.name".
      (rfCol($pdo,'products','description')?',p.description':",NULL AS description").
      (rfCol($pdo,'products','base_unit_price')?',p.base_unit_price':",0 AS base_unit_price").
      (rfCol($pdo,'products','selling_price')?',p.selling_price':",0 AS selling_price").
      (rfCol($pdo,'products','markup_type')?',p.markup_type':",NULL AS markup_type").
      (rfCol($pdo,'products','markup_value')?',p.markup_value':",NULL AS markup_value").
      (rfCol($pdo,'products','tax_percent')?',p.tax_percent':",0 AS tax_percent").
      " FROM products p WHERE p.tenant_id=:t AND LOWER(p.name)=LOWER(:n) AND p.status='active'".
      (rfCol($pdo,'products','deleted_at')?" AND p.deleted_at IS NULL":"")." LIMIT 1";
  $find=$pdo->prepare($findSql);$find->execute(array(':t'=>$tenant,':n'=>$name));
  $existing=$find->fetch(PDO::FETCH_ASSOC);
  if($existing){
      $realId=(int)$existing['id'];
      $ec=isset($existing['base_unit_price'])?(float)$existing['base_unit_price']:0;$ep=isset($existing['selling_price'])?(float)$existing['selling_price']:0;$em=(isset($existing['markup_type'])&&$existing['markup_type']==='percentage'&&$existing['markup_value']!==null)?(float)$existing['markup_value']:($ec>0?(($ep-$ec)/$ec)*100:0);$item=array('id'=>-$realId,'source_id'=>$realId,'item_source'=>'product','name'=>$existing['name'],'item_type'=>'product','description'=>isset($existing['description'])?$existing['description']:'','unit_cost'=>$ec,'unit_price'=>$ep,'markup_percent'=>$em,'tax_percent'=>isset($existing['tax_percent'])?(float)$existing['tax_percent']:0);
      rfRes(200,true,'Existing product selected.',array('created'=>0,'item'=>$item));
  }

  /* This mirrors the existing Add Invoice products schema. */
  $stmt=$pdo->prepare("INSERT INTO products(tenant_id,category_id,sku,name,description,unit_of_measure_id,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_id,tax_percent,track_inventory,status,created_by,created_at,updated_at,deleted_at) VALUES(:t,NULL,NULL,:n,:d,NULL,'Unit',:cost,'percentage',:markup,:price,NULL,:tax,0,'active',:u,NOW(),NOW(),NULL)");
  $stmt->execute(array(':t'=>$tenant,':n'=>$name,':d'=>$description!==''?$description:null,':cost'=>$unitCost,':markup'=>$markup,':price'=>$unitPrice,':tax'=>$taxPercent,':u'=>$user));
  $realId=(int)$pdo->lastInsertId();
  $item=array('id'=>-$realId,'source_id'=>$realId,'item_source'=>'product','name'=>$name,'item_type'=>'product','description'=>$description,'unit_cost'=>$unitCost,'unit_price'=>$unitPrice,'markup_percent'=>$markup,'tax_percent'=>$taxPercent);
  rfLog($pdo,$tenant,$sessionBranch,$user,'product_created_from_request','product',$realId,null,'Product created from service request',array('item'=>$item));
  if(function_exists('tenantAuditLog')){try{tenantAuditLog($pdo,'PRODUCT_CREATED_FROM_REQUEST',$tenant,$sessionBranch,$user,'product',$realId,null,$item);}catch(Throwable $e){error_log('request product audit '.$e->getMessage());}}
  rfRes(200,true,'Product created successfully.',array('created'=>1,'item'=>$item));
}

if($a==='locations'){$client=(int)rfP('client_id',0);if(rfValid($pdo,'clients',$tenant,$client)===null)rfRes(422,false,'Selected client is invalid.');$s=$pdo->prepare("SELECT id,name,address_line1,address_line2,city,state,postal_code,country_id,is_primary FROM client_locations WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL AND status='active' ORDER BY is_primary DESC,name,id");$s->execute(array(':t'=>$tenant,':c'=>$client));rfRes(200,true,'Locations loaded.',array('locations'=>$s->fetchAll()));}

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
    $em=(string)rfP('email_team_when_assigned','0')==='1'||(string)rfP('notify_email','0')==='1';
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
  $client=(int)rfP('client_id',0);$location=(int)rfP('location_id',0);$serviceRaw=(int)rfP('product_service_id',0);$branch=(int)rfP('branch_id',0);$title=trim((string)rfP('title',''));$description=trim((string)rfP('description',''));$source=trim((string)rfP('source','office'));$priority=trim((string)rfP('priority','normal'));$status=trim((string)rfP('status','new'));$date=trim((string)rfP('preferred_date',''));$from=trim((string)rfP('preferred_time_from',''));$to=trim((string)rfP('preferred_time_to',''));
  if((string)rfP('assessment_enabled','0')==='1')$status='assessment_required';
  if($title==='')rfRes(422,false,'Request title is required.');$client=rfValid($pdo,'clients',$tenant,$client);if($client===null)rfRes(422,false,'Select a valid client.');
  $service=null;if($serviceRaw>0){$service=rfValid($pdo,'product_services',$tenant,$serviceRaw,"AND status='active'");if($service===null)rfRes(422,false,'Selected Product / Service is invalid.');}
  $branch=rfValid($pdo,'branches',$tenant,$branch);if($location>0){$location=rfValid($pdo,'client_locations',$tenant,$location);if($location===null)rfRes(422,false,'Selected location is invalid.');$lc=$pdo->prepare("SELECT id FROM client_locations WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL LIMIT 1");$lc->execute(array(':id'=>$location,':t'=>$tenant,':c'=>$client));if(!$lc->fetchColumn())rfRes(422,false,'Selected location does not belong to client.');}else{$location=null;}
  if(!in_array($source,array('office','website','portal','phone','sms','email','ai','other'),true))rfRes(422,false,'Invalid request source.');if(!in_array($priority,array('low','normal','high','urgent'),true))rfRes(422,false,'Invalid priority.');if(!in_array($status,array('new','contacting','information_required','assessment_required','quote_required','job_required'),true))rfRes(422,false,'Invalid request stage.');if($from!==''&&$to!==''&&$from>=$to)rfRes(422,false,'Preferred end time must be after start time.');
  $requestLines=rfNormalizeRequestLines($pdo,$tenant,(string)rfP('line_items_json','[]'));if(!$service){foreach($requestLines as $li){if($li['product_service_id']&&$li['item_type']==='service'){$service=(int)$li['product_service_id'];break;}}}
  if($status==='job_required'&&(!$service||$service<=0))rfRes(422,false,'Select at least one active service before creating a Job.');
  if($requestLines&&!rfTable($pdo,'service_request_line_items'))rfRes(500,false,'Run migration_request_jobber_ui.sql once before saving request Product / Service line items.');
  $checkRaw=trim((string)rfP('new_checklist_json',''));$selectedCheckRaw=trim((string)rfP('selected_checklist_ids_json','[]'));$selectedCheckIds=rfJsonArray($selectedCheckRaw);if(($checkRaw!==''||count($selectedCheckIds)>0)&&!rfTable($pdo,'service_request_checklists'))rfRes(500,false,'Run migration_request_jobber_ui.sql once before using checklists on requests.');
  $needsAssignment=in_array($status,array('assessment_required','job_required'),true);$assignmentMode=null;$assigned=array('users'=>array(),'team_id'=>null);
  if($needsAssignment){if(!rfTable($pdo,'request_assignments'))rfRes(500,false,'Run migration_request_flow.sql once before using Assessment/Job employee assignment.');$multi=isset($_POST['employee_ids'])&&is_array($_POST['employee_ids'])?$_POST['employee_ids']:array();$postedMode=trim((string)rfP('assignment_mode',''));if($postedMode===''&&count($multi))$postedMode=count($multi)>1?'multiple':'multiple';$assignmentMode=$postedMode!==''?$postedMode:'individual';if(!in_array($assignmentMode,array('individual','team','multiple'),true))rfRes(422,false,'Invalid assignment mode.');if($assignmentMode==='multiple'){$assigned=rfResolveUsers($pdo,$tenant,'multiple',0,0,$multi);}else{$assigned=rfResolveUsers($pdo,$tenant,$assignmentMode,(int)rfP('individual_user_id',0),(int)rfP('team_id',0),$multi);}}
  $quoteItems=array();if($status==='quote_required'){$raw=(string)rfP('quote_items_json','[]');$quoteItems=rfJsonArray($raw);if(!$quoteItems)$quoteItems=$requestLines;if(!count($quoteItems))rfRes(422,false,'Add at least one quotation item.');}
  $pdo->beginTransaction();$quoteNo=null;$jobNo=null;$jobId=null;$quoteId=null;$assessment=null;$checkInfo=array('template_ids'=>array(),'request_links'=>array(),'job_checklists'=>array());
  try{
    $requestNo=rfNext($pdo,$tenant,$branch!==null?$branch:$sessionBranch,'request','service_requests','request_no','REQ');$primary=$needsAssignment&&count($assigned['users'])?(int)$assigned['users'][0]:null;
    $q=$pdo->prepare("INSERT INTO service_requests(tenant_id,branch_id,request_no,client_id,location_id,product_service_id,source,priority,title,description,preferred_date,preferred_time_from,preferred_time_to,assigned_user_id,status,created_by_user_id) VALUES(:t,:b,:no,:c,:l,:ps,:src,:pri,:title,:d,:dt,:f,:to,:au,:st,:u)");$q->execute(array(':t'=>$tenant,':b'=>$branch,':no'=>$requestNo,':c'=>$client,':l'=>$location,':ps'=>$service,':src'=>$source,':pri'=>$priority,':title'=>$title,':d'=>$description!==''?$description:null,':dt'=>$date!==''?$date:null,':f'=>$from!==''?$from:null,':to'=>$to!==''?$to:null,':au'=>$primary,':st'=>$status,':u'=>$user));$requestId=(int)$pdo->lastInsertId();
    $h=$pdo->prepare("INSERT INTO request_status_history(tenant_id,request_id,old_status,new_status,notes,changed_by) VALUES(:t,:r,NULL,:st,'Service request created',:u)");$h->execute(array(':t'=>$tenant,':r'=>$requestId,':st'=>$status,':u'=>$user));
    if($needsAssignment){$ri=$pdo->prepare("INSERT INTO request_assignments(tenant_id,request_id,assignment_mode,team_id,user_id,is_primary,assigned_by) VALUES(:t,:r,:m,:team,:uid,:p,:by)");foreach($assigned['users'] as $i=>$uid){$ri->execute(array(':t'=>$tenant,':r'=>$requestId,':m'=>$assignmentMode,':team'=>$assigned['team_id'],':uid'=>$uid,':p'=>$i===0?1:0,':by'=>$user));}}
    rfSaveRequestLines($pdo,$tenant,$requestId,$requestLines);
    if($status==='quote_required'){
      $quoteNo=rfNext($pdo,$tenant,$branch!==null?$branch:$sessionBranch,'quote','quotes','quote_no','QUO');$sub=0;$disc=0;$tax=0;$tot=0;$normalized=array();foreach($quoteItems as $idx=>$x){$pid=isset($x['product_service_id'])?(int)$x['product_service_id']:0;$pid=$pid>0?rfValid($pdo,'product_services',$tenant,$pid,"AND status='active'"):null;$name=trim((string)(isset($x['item_name'])?$x['item_name']:''));if($name==='')continue;$qty=max(.001,(float)(isset($x['quantity'])?$x['quantity']:1));$cost=max(0,(float)(isset($x['unit_cost'])?$x['unit_cost']:0));$price=max(0,(float)(isset($x['unit_price'])?$x['unit_price']:0));$base=$qty*$price;$d=max(0,min($base,(float)(isset($x['discount_amount'])?$x['discount_amount']:0)));$taxPct=max(0,(float)(isset($x['tax_percent'])?$x['tax_percent']:0));$taxable=max(0,$base-$d);$taxAmt=$taxable*$taxPct/100;$line=$taxable+$taxAmt;$sub+=$base;$disc+=$d;$tax+=$taxAmt;$tot+=$line;$normalized[]=array('pid'=>$pid,'name'=>$name,'description'=>trim((string)(isset($x['description'])?$x['description']:'')),'qty'=>$qty,'cost'=>$cost,'price'=>$price,'discount'=>$d,'tax_percent'=>$taxPct,'tax_amount'=>$taxAmt,'line_total'=>$line,'optional'=>!empty($x['is_optional'])?1:0,'sort'=>$idx);}
      if(!$normalized)rfRes(422,false,'Add at least one valid quotation item.');$q=$pdo->prepare("INSERT INTO quotes(tenant_id,branch_id,quote_no,revision_no,client_id,location_id,request_id,title,introduction,status,subtotal,discount_total,tax_total,total,created_by) VALUES(:t,:b,:no,0,:c,:l,:r,:title,:intro,'draft',:sub,:disc,:tax,:tot,:u)");$q->execute(array(':t'=>$tenant,':b'=>$branch,':no'=>$quoteNo,':c'=>$client,':l'=>$location,':r'=>$requestId,':title'=>$title,':intro'=>$description!==''?$description:null,':sub'=>$sub,':disc'=>$disc,':tax'=>$tax,':tot'=>$tot,':u'=>$user));$quoteId=(int)$pdo->lastInsertId();$qi=$pdo->prepare("INSERT INTO quote_line_items(quote_id,product_service_id,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,is_optional,sort_order) VALUES(:q,:pid,:name,:d,:qty,:cost,:price,:disc,:tp,:ta,:lt,:opt,:sort)");foreach($normalized as $x){$qi->execute(array(':q'=>$quoteId,':pid'=>$x['pid'],':name'=>$x['name'],':d'=>$x['description']!==''?$x['description']:null,':qty'=>$x['qty'],':cost'=>$x['cost'],':price'=>$x['price'],':disc'=>$x['discount'],':tp'=>$x['tax_percent'],':ta'=>$x['tax_amount'],':lt'=>$x['line_total'],':opt'=>$x['optional'],':sort'=>$x['sort']));}
    }
    if($status==='job_required'){
      $jobNo=rfNext($pdo,$tenant,$branch!==null?$branch:$sessionBranch,'job','jobs','job_no','JOB');$jobStatus=$date!==''?'scheduled':'active';$workflowId=rfDefaultWorkflow($pdo,$tenant,$service);$jobMode=$assignmentMode==='individual'?'single_user':($assignmentMode==='multiple'?'multiple_users':'team');$j=$pdo->prepare("INSERT INTO jobs(tenant_id,branch_id,job_no,client_id,location_id,request_id,product_service_id,workflow_id,title,description,job_type,priority,assignment_mode,assignment_completion_mode,status,start_date,invoicing_preference,subtotal,tax_total,total,created_by) VALUES(:t,:b,:no,:c,:l,:r,:ps,:workflow,:title,:d,'one_off',:pri,:am,'primary_only',:st,:sd,'when_job_complete',0,0,0,:u)");$j->execute(array(':t'=>$tenant,':b'=>$branch,':no'=>$jobNo,':c'=>$client,':l'=>$location,':r'=>$requestId,':ps'=>$service,':workflow'=>$workflowId,':title'=>$title,':d'=>$description!==''?$description:null,':pri'=>$priority,':am'=>$jobMode,':st'=>$jobStatus,':sd'=>$date!==''?$date:null,':u'=>$user));$jobId=(int)$pdo->lastInsertId();$ja=$pdo->prepare("INSERT INTO job_assignments(tenant_id,job_id,user_id,team_id,assignment_role,is_primary_responsible,assigned_by,status) VALUES(:t,:j,:uid,:team,:role,:p,:by,'assigned')");if($assignmentMode==='team'){$ja->execute(array(':t'=>$tenant,':j'=>$jobId,':uid'=>null,':team'=>$assigned['team_id'],':role'=>'primary',':p'=>1,':by'=>$user));}else{foreach($assigned['users'] as $i=>$uid){$ja->execute(array(':t'=>$tenant,':j'=>$jobId,':uid'=>$uid,':team'=>null,':role'=>$i===0?'primary':'technician',':p'=>$i===0?1:0,':by'=>$user));}}rfInitJobWorkflow($pdo,$tenant,$jobId,$workflowId,$assignmentMode==='team'?null:(count($assigned['users'])?(int)$assigned['users'][0]:null),$assignmentMode==='team'?$assigned['team_id']:null);$u=$pdo->prepare("UPDATE service_requests SET status='converted' WHERE id=:r AND tenant_id=:t");$u->execute(array(':r'=>$requestId,':t'=>$tenant));$h=$pdo->prepare("INSERT INTO request_status_history(tenant_id,request_id,old_status,new_status,notes,changed_by) VALUES(:t,:r,'job_required','converted',:n,:u)");$h->execute(array(':t'=>$tenant,':r'=>$requestId,':n'=>'Job '.$jobNo.' generated automatically',':u'=>$user));
    }
    $assessment=rfCreateAssessment($pdo,$tenant,$branch!==null?$branch:$sessionBranch,$requestId,$client,$location,$assigned['users'],$user);
    $checkInfo=rfSaveRequestChecklist($pdo,$tenant,$requestId,$user,$checkRaw,$selectedCheckRaw,$jobId,$primary);
    $pdo->commit();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  $uploads=rfSaveRequestUploads($pdo,$tenant,$requestId,$user);
  $mailBranch=$branch!==null?$branch:$sessionBranch;

  /* Customer email is intentionally disabled for request creation. */
  $customerEmail=array(
      'requested'=>0,
      'sent'=>0,
      'status'=>'disabled',
      'recipient'=>'',
      'message'=>'Customer email is disabled for service request creation.',
      'smtp_config_id'=>null,
      'smtp_config_name'=>null,
      'mail_log_id'=>0
  );
  $notify=array('in_app'=>0,'email_sent'=>0,'email_failed'=>0,'email_skipped'=>0,'email_messages'=>array(),'smtp_config_id'=>null,'smtp_config_name'=>null);
  $teamEmailRequested=(string)rfP('email_team_when_assigned','0')==='1'||(string)rfP('notify_email','0')==='1';
  if($needsAssignment){$in=(string)rfP('notify_in_app','1')!=='0';$entity=$status==='job_required'?'job':'service_request';$rid=$status==='job_required'?$jobId:$requestId;$event=$status==='job_required'?'job.assigned':'request.assigned';$nt=$status==='job_required'?'New Job Assigned':'On-site Assessment Assigned';$msg=($status==='job_required'?'You have been assigned to job '.$jobNo:'You have been assigned to assess request '.$requestNo).' - '.$title;if($assessment&&isset($assessment['status']))$msg.=' (Assessment status: '.str_replace('_',' ',(string)$assessment['status']).')';$notify=rfNotify($pdo,$tenant,$mailBranch,$assigned['users'],$event,$nt,$msg,$entity,$rid,$in,$teamEmailRequested);}
  $internalNote=trim((string)rfP('internal_note',''));$noteMentionIds=rfMentionUsers($pdo,$tenant,(string)rfP('note_mentions_json','[]'));$noteNotify=array('in_app'=>0,'email_sent'=>0,'email_failed'=>0,'email_skipped'=>0,'email_messages'=>array(),'smtp_config_id'=>null,'smtp_config_name'=>null);
  if($internalNote!==''&&$noteMentionIds){$noteNotify=rfNotify($pdo,$tenant,$mailBranch,$noteMentionIds,'request.note_mentioned','Mentioned in request note','You were mentioned in an internal note on request '.$requestNo.' - '.$title,'service_request',$requestId,true,false);rfLog($pdo,$tenant,$mailBranch,$user,'service_request_note_added','service_request',$requestId,$client,'Internal note added to request '.$requestNo,array('note'=>$internalNote,'mentioned_user_ids'=>$noteMentionIds,'notifications'=>$noteNotify));}
  rfLog($pdo,$tenant,$mailBranch,$user,'service_request_created','service_request',$requestId,$client,'Service request created: '.$requestNo,array('status'=>$status,'quote_id'=>$quoteId,'job_id'=>$jobId,'assessment'=>$assessment,'checklists'=>$checkInfo,'line_items'=>count($requestLines),'internal_note'=>$internalNote,'note_mentions'=>$noteMentionIds,'link_to_related'=>array('quotes'=>(string)rfP('link_quotes','0')==='1','jobs'=>(string)rfP('link_jobs','0')==='1','invoices'=>(string)rfP('link_invoices','0')==='1'),'uploads'=>$uploads,'customer_email_on_create'=>false,'team_email_requested'=>$teamEmailRequested,'notifications'=>$notify));
  if(function_exists('tenantAuditLog'))tenantAuditLog($pdo,'SERVICE_REQUEST_CREATED',$tenant,$mailBranch,$user,'service_request',$requestId,null,array('request_no'=>$requestNo,'status'=>$status,'quote_id'=>$quoteId,'job_id'=>$jobId,'assessment_id'=>$assessment?$assessment['id']:null,'checklist_template_ids'=>$checkInfo['template_ids'],'line_item_count'=>count($requestLines),'uploads'=>$uploads,'customer_email_on_create'=>false,'team_email_requested'=>$teamEmailRequested,'team_notifications'=>$notify,'note_mentions'=>$noteMentionIds));
  rfRes(200,true,'Service request '.$requestNo.' created successfully.',array('request_id'=>$requestId,'request_no'=>$requestNo,'quote_id'=>$quoteId,'quote_no'=>$quoteNo,'job_id'=>$jobId,'job_no'=>$jobNo,'assessment_id'=>$assessment?$assessment['id']:null,'assessment_no'=>$assessment?$assessment['assessment_no']:null,'checklist_template_ids'=>$checkInfo['template_ids'],'attachment_summary'=>$uploads,'customer_email'=>$customerEmail,'team_email_requested'=>$teamEmailRequested?1:0,'notification_summary'=>$notify,'note_notification_summary'=>$noteNotify));
}

rfRes(400,false,'Unsupported request form action.');
}catch(PDOException $e){error_log('request flow PDO '.$e->getMessage());if(isset($e->errorInfo[1])&&(int)$e->errorInfo[1]===1062)rfRes(409,false,'A generated document number already exists. Check Document Number Formatting and retry.');rfRes(500,false,'Unable to create the service request.');}catch(Throwable $e){error_log('request flow '.$e->getMessage());rfRes(500,false,'Unable to create the service request.');}
