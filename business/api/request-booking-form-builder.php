<?php
ob_start();
ini_set('display_errors','0');ini_set('html_errors','0');ini_set('log_errors','1');
require_once __DIR__ . '/request-booking-common.php';
list($tenantId,$userId)=rbRequireAuth();rbRequireCsrf();

function rbbBaseUrl(){
    $https=!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off';
    $host=isset($_SERVER['HTTP_HOST'])?(string)$_SERVER['HTTP_HOST']:'';
    $script=isset($_SERVER['SCRIPT_NAME'])?str_replace('\\','/',(string)$_SERVER['SCRIPT_NAME']):'';
    $base=preg_replace('#/api/[^/]+$#','',$script);
    return $host!==''?($https?'https':'http').'://'.$host.$base:$base;
}
function rbbForm(PDO $pdo,$tenantId,$id){
    $systemSelect=rbSchemaReady($pdo)?'c.is_system_form,c.system_key,':'0 AS is_system_form,NULL AS system_key,';
    $stmt=$pdo->prepare("SELECT c.id,c.tenant_id,c.form_template_id,c.form_type,{$systemSelect}c.public_token,c.slug,c.form_pages,c.is_request_default,c.is_booking_default,c.require_booking_approval,c.service_area_enabled,c.confirmation_title,c.confirmation_message,c.confirmation_url,c.builder_json,c.booking_json,c.efficient_scheduling_json,c.google_business_profile_json,c.google_analytics_code,c.tracking_json,t.name,t.description,t.status FROM request_booking_form_configs c INNER JOIN form_templates t ON t.id=c.form_template_id WHERE c.id=:id AND c.tenant_id=:tenant_id LIMIT 1");
    $stmt->execute(array(':id'=>$id,':tenant_id'=>$tenantId));return $stmt->fetch(PDO::FETCH_ASSOC);
}
function rbbCatalog(PDO $pdo,$tenantId){
    $allowQty=rbColumnExists($pdo,'product_services','allow_booking_quantity')?'ps.allow_booking_quantity':'0 AS allow_booking_quantity';
    $taxExempt=rbColumnExists($pdo,'product_services','is_tax_exempt')?'ps.is_tax_exempt':'CASE WHEN ps.tax_percent=0 THEN 1 ELSE 0 END AS is_tax_exempt';
    $stmt=$pdo->prepare("SELECT ps.id,CONCAT('ps:',ps.id) AS `key`,ps.item_type,ps.name,ps.description,ps.image_path,ps.unit_cost,ps.unit_price,ps.tax_percent,{$taxExempt},ps.is_bookable,ps.estimated_duration_minutes,{$allowQty},bs.id AS bookable_service_id FROM product_services ps LEFT JOIN bookable_services bs ON bs.tenant_id=ps.tenant_id AND bs.product_service_id=ps.id AND bs.is_active=1 WHERE ps.tenant_id=:tenant_id AND ps.status='active' AND ps.deleted_at IS NULL ORDER BY ps.name ASC");
    $stmt->execute(array(':tenant_id'=>$tenantId));$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$x){$x['id']=(int)$x['id'];$x['unit_cost']=(float)$x['unit_cost'];$x['unit_price']=(float)$x['unit_price'];$x['tax_percent']=(float)$x['tax_percent'];$x['is_tax_exempt']=(int)$x['is_tax_exempt'];$x['is_bookable']=(int)$x['is_bookable'];$x['estimated_duration_minutes']=$x['estimated_duration_minutes']!==null?(int)$x['estimated_duration_minutes']:60;$x['allow_booking_quantity']=(int)$x['allow_booking_quantity'];$x['bookable_service_id']=$x['bookable_service_id']!==null?(int)$x['bookable_service_id']:0;$x['markup_percent']=$x['unit_cost']>0?round((($x['unit_price']-$x['unit_cost'])/$x['unit_cost'])*100,2):0;}
    unset($x);return $rows;
}
function rbbSaveImage($tenantId,$file,$existing){
    if(!is_array($file)||!isset($file['tmp_name'])||$file['tmp_name']===''||!is_uploaded_file($file['tmp_name']))return $existing;
    if((int)$file['size']>8*1024*1024)throw new RuntimeException('Product / service image must be 8MB or smaller.');
    $mime='';if(function_exists('finfo_open')){$fi=finfo_open(FILEINFO_MIME_TYPE);if($fi){$mime=(string)finfo_file($fi,$file['tmp_name']);finfo_close($fi);}}
    $allowed=array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp');if(!isset($allowed[$mime]))throw new RuntimeException('Use a JPG, PNG, or WEBP image.');
    $root=dirname(__DIR__).'/uploads/request-booking/tenant-'.$tenantId.'/catalog';if(!is_dir($root)&&!@mkdir($root,0775,true)&&!is_dir($root))throw new RuntimeException('Unable to create the product image folder.');
    $name='catalog-'.date('YmdHis').'-'.substr(bin2hex(random_bytes(6)),0,12).'.'.$allowed[$mime];$dest=$root.'/'.$name;if(!@move_uploaded_file($file['tmp_name'],$dest))throw new RuntimeException('Unable to save the product image.');
    if($existing&&strpos($existing,'uploads/request-booking/tenant-'.$tenantId.'/catalog/')===0){$old=dirname(__DIR__).'/'.$existing;if(is_file($old))@unlink($old);}return 'uploads/request-booking/tenant-'.$tenantId.'/catalog/'.$name;
}
function rbbUpsertBookable(PDO $pdo,$tenantId,$productServiceId,$name,$description,$price,$duration,$active){
    $stmt=$pdo->prepare("SELECT id FROM bookable_services WHERE tenant_id=:tenant_id AND product_service_id=:product_service_id ORDER BY id ASC LIMIT 1");$stmt->execute(array(':tenant_id'=>$tenantId,':product_service_id'=>$productServiceId));$id=(int)$stmt->fetchColumn();
    if($id>0){$u=$pdo->prepare("UPDATE bookable_services SET name=:name,description=:description,estimated_price=:price,duration_minutes=:duration,is_active=:active WHERE id=:id AND tenant_id=:tenant_id");$u->execute(array(':name'=>$name,':description'=>$description!==''?$description:null,':price'=>$price,':duration'=>$duration,':active'=>$active?1:0,':id'=>$id,':tenant_id'=>$tenantId));return $id;}
    if(!$active)return 0;$u=$pdo->prepare("INSERT INTO bookable_services (tenant_id,product_service_id,name,description,estimated_price,duration_minutes,is_active) VALUES (:tenant_id,:product_service_id,:name,:description,:price,:duration,1)");$u->execute(array(':tenant_id'=>$tenantId,':product_service_id'=>$productServiceId,':name'=>$name,':description'=>$description!==''?$description:null,':price'=>$price,':duration'=>$duration));return (int)$pdo->lastInsertId();
}

try{
    if(!rbSchemaReady($pdo))rbApiRespond(500,false,'Run the request-booking Jobber forms SQL migration first.',array('code'=>'schema_missing'));
    rbEnsureSystemForms($pdo,$tenantId,$userId);
    $action=trim((string)rbPost('action','get'));

    if($action==='get'){
        $id=max(0,(int)rbPost('id',0));$row=rbbForm($pdo,$tenantId,$id);if(!$row)rbApiRespond(404,false,'Form not found.');
        $builder=rbJsonDecode($row['builder_json'],array('sections'=>array(),'actions'=>array()));$booking=rbJsonDecode($row['booking_json'],array());$efficient=rbJsonDecode($row['efficient_scheduling_json'],array());$google=rbJsonDecode($row['google_business_profile_json'],array());$tracking=rbJsonDecode($row['tracking_json'],array());
        $base=rbbBaseUrl();
        $form=array(
            'id'=>(int)$row['id'],'form_template_id'=>(int)$row['form_template_id'],'form_type'=>$row['form_type'],'is_system_form'=>(int)$row['is_system_form'],'system_key'=>$row['system_key'],
            'name'=>$row['name'],'description'=>$row['description'],'status'=>$row['status'],'form_pages'=>(int)$row['form_pages'],'is_request_default'=>(int)$row['is_request_default'],'is_booking_default'=>(int)$row['is_booking_default'],'require_booking_approval'=>(int)$row['require_booking_approval'],'service_area_enabled'=>(int)$row['service_area_enabled'],
            'confirmation_title'=>$row['confirmation_title'],'confirmation_message'=>$row['confirmation_message'],'confirmation_url'=>$row['confirmation_url'],'builder'=>$builder,'booking'=>$booking,'efficient_scheduling'=>$efficient,'google_business_profile'=>$google,'google_analytics_code'=>$row['google_analytics_code'],'tracking'=>$tracking,
            'public_url'=>$base.'/request-booking-form.php?token='.rawurlencode($row['public_token']),
            'embed_code'=>'<iframe src="'.$base.'/request-booking-form.php?token='.rawurlencode($row['public_token']).'&embed=1" style="width:100%;min-height:760px;border:0" loading="lazy"></iframe>'
        );
        $stmt=$pdo->prepare("SELECT u.id,u.first_name,u.last_name,u.email FROM users u WHERE u.tenant_id=:tenant_id AND u.status='active' AND u.deleted_at IS NULL ORDER BY u.first_name,u.last_name,u.email");$stmt->execute(array(':tenant_id'=>$tenantId));$users=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $tenantStmt=$pdo->prepare("SELECT t.display_name,t.phone,t.website_url,t.address_line1,t.city,t.state,t.postal_code,c.symbol,p.logo_path,p.main_brand_color,p.accent_color FROM tenants t LEFT JOIN currencies c ON c.id=t.currency_id LEFT JOIN tenant_business_profiles p ON p.tenant_id=t.id WHERE t.id=:tenant_id LIMIT 1");$tenantStmt->execute(array(':tenant_id'=>$tenantId));$tenant=$tenantStmt->fetch(PDO::FETCH_ASSOC);if(!$tenant)$tenant=array('display_name'=>'FieldPlx','symbol'=>'');
        $areaStmt=$pdo->prepare("SELECT id,address_text,latitude,longitude,radius_km FROM request_booking_service_areas WHERE tenant_id=:tenant_id AND is_active=1 ORDER BY id DESC LIMIT 1");$areaStmt->execute(array(':tenant_id'=>$tenantId));$area=$areaStmt->fetch(PDO::FETCH_ASSOC);if(!$area)$area=array();
        $googleConnection=null;if(rbTableExists($pdo,'integration_connections')){$g=$pdo->prepare("SELECT id,provider,status FROM integration_connections WHERE tenant_id=:tenant_id AND provider LIKE '%google%' ORDER BY id DESC LIMIT 1");$g->execute(array(':tenant_id'=>$tenantId));$googleConnection=$g->fetch(PDO::FETCH_ASSOC);}
        rbApiRespond(200,true,'Form loaded.',array('form'=>$form,'catalog_items'=>rbbCatalog($pdo,$tenantId),'services'=>array(),'users'=>$users,'tenant'=>$tenant,'currency'=>array('symbol'=>isset($tenant['symbol'])?$tenant['symbol']:''),'service_area'=>$area,'google_connection'=>$googleConnection));
    }

    if($action==='save'){
        $id=max(0,(int)rbPost('id',0));$row=rbbForm($pdo,$tenantId,$id);if(!$row)rbApiRespond(404,false,'Form not found.');
        $name=trim((string)rbPost('name',$row['name']));if($name==='')rbApiRespond(422,false,'Form title is required.');
        $description=(string)rbPost('description','');$builder=rbJsonDecode(rbPost('builder_json',''),null);if(!is_array($builder)||!isset($builder['sections'])||!is_array($builder['sections']))rbApiRespond(422,false,'Form builder data is invalid.');
        $booking=rbJsonDecode(rbPost('booking_json','{}'),array());$efficient=rbJsonDecode(rbPost('efficient_scheduling_json','{}'),array());$google=rbJsonDecode(rbPost('google_business_profile_json','{}'),array());$tracking=rbJsonDecode(rbPost('tracking_json','{}'),array());
        $formPages=(int)rbPost('form_pages',0)===1;$requestDefault=(int)rbPost('is_request_default',0)===1;$bookingDefault=(int)rbPost('is_booking_default',0)===1;
        if($row['form_type']!=='request')$requestDefault=false;if($row['form_type']==='request')$bookingDefault=false;
        /* Keep at least one request default and one booking default per tenant. */
        if($row['form_type']==='request' && !$requestDefault && (int)$row['is_request_default']===1){
            $check=$pdo->prepare("SELECT COUNT(*) FROM request_booking_form_configs WHERE tenant_id=:tenant_id AND id<>:id AND is_request_default=1");
            $check->execute(array(':tenant_id'=>$tenantId,':id'=>$id));
            if((int)$check->fetchColumn()===0)$requestDefault=true;
        }
        if($row['form_type']!=='request' && !$bookingDefault && (int)$row['is_booking_default']===1){
            $check=$pdo->prepare("SELECT COUNT(*) FROM request_booking_form_configs WHERE tenant_id=:tenant_id AND id<>:id AND is_booking_default=1");
            $check->execute(array(':tenant_id'=>$tenantId,':id'=>$id));
            if((int)$check->fetchColumn()===0)$bookingDefault=true;
        }
        $pdo->beginTransaction();
        try{
            if($requestDefault)$pdo->prepare("UPDATE request_booking_form_configs SET is_request_default=0 WHERE tenant_id=:tenant_id")->execute(array(':tenant_id'=>$tenantId));
            if($bookingDefault)$pdo->prepare("UPDATE request_booking_form_configs SET is_booking_default=0 WHERE tenant_id=:tenant_id")->execute(array(':tenant_id'=>$tenantId));
            $pdo->prepare("UPDATE form_templates SET name=:name,description=:description,related_module=:related_module WHERE id=:template_id AND tenant_id=:tenant_id")->execute(array(':name'=>substr($name,0,190),':description'=>trim($description)!==''?$description:null,':related_module'=>rbRelatedModule($row['form_type']),':template_id'=>(int)$row['form_template_id'],':tenant_id'=>$tenantId));
            $slug=rbUniqueSlug($pdo,$tenantId,$name,$id);
            $stmt=$pdo->prepare("UPDATE request_booking_form_configs SET slug=:slug,form_pages=:form_pages,is_request_default=:request_default,is_booking_default=:booking_default,require_booking_approval=:approval,service_area_enabled=:service_area,confirmation_title=:confirmation_title,confirmation_message=:confirmation_message,confirmation_url=:confirmation_url,builder_json=:builder_json,booking_json=:booking_json,efficient_scheduling_json=:efficient_json,google_business_profile_json=:google_json,google_analytics_code=:ga_code,tracking_json=:tracking_json,updated_by=:updated_by WHERE id=:id AND tenant_id=:tenant_id");
            $stmt->execute(array(':slug'=>$slug,':form_pages'=>$formPages?1:0,':request_default'=>$requestDefault?1:0,':booking_default'=>$bookingDefault?1:0,':approval'=>(int)rbPost('require_booking_approval',0)===1?1:0,':service_area'=>(int)rbPost('service_area_enabled',0)===1?1:0,':confirmation_title'=>trim((string)rbPost('confirmation_title',''))!==''?(string)rbPost('confirmation_title',''):null,':confirmation_message'=>trim((string)rbPost('confirmation_message',''))!==''?(string)rbPost('confirmation_message',''):null,':confirmation_url'=>trim((string)rbPost('confirmation_url',''))!==''?(string)rbPost('confirmation_url',''):null,':builder_json'=>json_encode($builder,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':booking_json'=>json_encode($booking,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':efficient_json'=>json_encode($efficient,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':google_json'=>json_encode($google,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':ga_code'=>trim((string)rbPost('google_analytics_code',''))!==''?trim((string)rbPost('google_analytics_code','')):null,':tracking_json'=>json_encode($tracking,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':updated_by'=>$userId,':id'=>$id,':tenant_id'=>$tenantId));
            rbSyncBuilderTables($pdo,$tenantId,(int)$row['form_template_id'],$builder);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        rbApiRespond(200,true,'Form saved.');
    }

    if($action==='save_service_area'){
        $id=max(0,(int)rbPost('id',0));$address=trim((string)rbPost('address_text',''));$lat=trim((string)rbPost('latitude',''));$lng=trim((string)rbPost('longitude',''));$radius=max(1,min(250,(float)rbPost('radius_km',25)));
        if($id>0){$stmt=$pdo->prepare("UPDATE request_booking_service_areas SET address_text=:address,latitude=:lat,longitude=:lng,radius_km=:radius,updated_by=:user_id,is_active=1 WHERE id=:id AND tenant_id=:tenant_id");$stmt->execute(array(':address'=>$address!==''?$address:null,':lat'=>$lat!==''?$lat:null,':lng'=>$lng!==''?$lng:null,':radius'=>$radius,':user_id'=>$userId,':id'=>$id,':tenant_id'=>$tenantId));}
        else{$stmt=$pdo->prepare("INSERT INTO request_booking_service_areas (tenant_id,name,address_text,latitude,longitude,radius_km,is_active,created_by,updated_by) VALUES (:tenant_id,'Primary service area',:address,:lat,:lng,:radius,1,:user_id,:user_id)");$stmt->execute(array(':tenant_id'=>$tenantId,':address'=>$address!==''?$address:null,':lat'=>$lat!==''?$lat:null,':lng'=>$lng!==''?$lng:null,':radius'=>$radius,':user_id'=>$userId));$id=(int)$pdo->lastInsertId();}
        rbApiRespond(200,true,'Service area saved.',array('id'=>$id));
    }

    if($action==='google_business_connection'){
        $id=max(0,(int)rbPost('id',0));$row=rbbForm($pdo,$tenantId,$id);if(!$row)rbApiRespond(404,false,'Form not found.');
        $connect=(int)rbPost('connect',0)===1;
        if($connect){
            $connection=null;
            if(rbTableExists($pdo,'integration_connections')){
                $stmt=$pdo->prepare("SELECT id,provider,status FROM integration_connections WHERE tenant_id=:tenant_id AND provider LIKE '%google%' AND status='connected' ORDER BY id DESC LIMIT 1");
                $stmt->execute(array(':tenant_id'=>$tenantId));
                $connection=$stmt->fetch(PDO::FETCH_ASSOC);
            }
            if(!$connection)rbApiRespond(422,false,'Connect Google in Account Connections before connecting this form.');
            $google=array('connected'=>1,'integration_connection_id'=>(int)$connection['id'],'provider'=>$connection['provider']);
            $message='Google Business Profile connected to this form.';
        }else{
            $google=array('connected'=>0);
            $message='Google Business Profile connection removed from this form.';
        }
        $stmt=$pdo->prepare("UPDATE request_booking_form_configs SET google_business_profile_json=:google_json,updated_by=:updated_by WHERE id=:id AND tenant_id=:tenant_id");
        $stmt->execute(array(':google_json'=>json_encode($google,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':updated_by'=>$userId,':id'=>$id,':tenant_id'=>$tenantId));
        rbApiRespond(200,true,$message,array('google_business_profile'=>$google));
    }

    if($action==='save_catalog_item'){
        $catalogId=max(0,(int)rbPost('catalog_id',0));$wasExisting=$catalogId>0;$name=trim((string)rbPost('name',''));if($name==='')rbApiRespond(422,false,'Name is required.');
        $itemType=trim((string)rbPost('item_type','service'));if(!in_array($itemType,array('service','product','material','fee'),true))$itemType='service';
        $description=trim((string)rbPost('description',''));$cost=max(0,(float)rbPost('unit_cost',0));$price=max(0,(float)rbPost('unit_price',0));$duration=max(15,min(720,(int)rbPost('duration_minutes',60)));$taxExempt=(int)rbPost('tax_exempt',0)===1;$allowQty=(int)rbPost('allow_quantity',0)===1;$isBookable=$itemType==='service'?1:0;
        $existingPath=null;if($catalogId>0){$s=$pdo->prepare("SELECT image_path FROM product_services WHERE id=:id AND tenant_id=:tenant_id AND deleted_at IS NULL LIMIT 1");$s->execute(array(':id'=>$catalogId,':tenant_id'=>$tenantId));$existingPath=$s->fetchColumn();if($existingPath===false)rbApiRespond(404,false,'Product / service not found.');}
        $imagePath=rbbSaveImage($tenantId,isset($_FILES['image'])?$_FILES['image']:null,$existingPath!==false?$existingPath:null);
        $hasTaxExempt=rbColumnExists($pdo,'product_services','is_tax_exempt');$hasQty=rbColumnExists($pdo,'product_services','allow_booking_quantity');
        if($catalogId>0){
            $sql="UPDATE product_services SET item_type=:item_type,name=:name,description=:description,unit_cost=:unit_cost,unit_price=:unit_price,tax_percent=:tax_percent,is_bookable=:is_bookable,estimated_duration_minutes=:duration,image_path=:image_path";
            if($hasTaxExempt)$sql.=",is_tax_exempt=:is_tax_exempt";if($hasQty)$sql.=",allow_booking_quantity=:allow_quantity";$sql.=" WHERE id=:id AND tenant_id=:tenant_id AND deleted_at IS NULL";$stmt=$pdo->prepare($sql);
            $p=array(':item_type'=>$itemType,':name'=>substr($name,0,190),':description'=>$description!==''?$description:null,':unit_cost'=>$cost,':unit_price'=>$price,':tax_percent'=>$taxExempt?0:(float)rbPost('tax_percent',0),':is_bookable'=>$isBookable,':duration'=>$duration,':image_path'=>$imagePath!==''?$imagePath:null,':id'=>$catalogId,':tenant_id'=>$tenantId);if($hasTaxExempt)$p[':is_tax_exempt']=$taxExempt?1:0;if($hasQty)$p[':allow_quantity']=$allowQty?1:0;$stmt->execute($p);
        }else{
            $cols="tenant_id,item_type,name,description,unit_name,unit_cost,unit_price,tax_percent,is_bookable,estimated_duration_minutes,status,image_path";$vals=":tenant_id,:item_type,:name,:description,:unit_name,:unit_cost,:unit_price,:tax_percent,:is_bookable,:duration,'active',:image_path";if($hasTaxExempt){$cols.=',is_tax_exempt';$vals.=',:is_tax_exempt';}if($hasQty){$cols.=',allow_booking_quantity';$vals.=',:allow_quantity';}$stmt=$pdo->prepare("INSERT INTO product_services ($cols) VALUES ($vals)");$p=array(':tenant_id'=>$tenantId,':item_type'=>$itemType,':name'=>substr($name,0,190),':description'=>$description!==''?$description:null,':unit_name'=>$itemType==='service'?'Service':'Unit',':unit_cost'=>$cost,':unit_price'=>$price,':tax_percent'=>$taxExempt?0:(float)rbPost('tax_percent',0),':is_bookable'=>$isBookable,':duration'=>$duration,':image_path'=>$imagePath!==''?$imagePath:null);if($hasTaxExempt)$p[':is_tax_exempt']=$taxExempt?1:0;if($hasQty)$p[':allow_quantity']=$allowQty?1:0;$stmt->execute($p);$catalogId=(int)$pdo->lastInsertId();
        }
        rbbUpsertBookable($pdo,$tenantId,$catalogId,$name,$description,$price,$duration,$isBookable===1);
        $items=rbbCatalog($pdo,$tenantId);$item=null;foreach($items as $x){if((int)$x['id']===$catalogId){$item=$x;break;}}
        rbApiRespond(200,true,$wasExisting?'Product / service updated and added.':'Product / service created and added.',array('item'=>$item,'catalog_items'=>$items));
    }

    rbApiRespond(400,false,'Unsupported action.');
}catch(Throwable $e){if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('FieldPlx request-booking builder API: '.$e->getMessage());rbApiRespond(500,false,'Unable to update this form right now.');}
