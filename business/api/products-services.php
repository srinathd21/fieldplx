<?php
declare(strict_types=1);
ob_start();
ini_set('display_errors','0');ini_set('html_errors','0');ini_set('log_errors','1');
require_once __DIR__ . '/../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function psOut($code,$ok,$message,$extra=array()){
    while(ob_get_level()>0){@ob_end_clean();}
    http_response_code($code);header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('success'=>$ok,'message'=>$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
function psPost($k,$d=''){if(!isset($_POST[$k])||is_array($_POST[$k]))return $d;return trim((string)$_POST[$k]);}
function psTable(PDO $pdo,$t){static $c=array();if(isset($c[$t]))return $c[$t];$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");$s->execute(array(':t'=>$t));return $c[$t]=(bool)$s->fetchColumn();}
function psCol(PDO $pdo,$t,$c){static $m=array();$k=$t.'.'.$c;if(isset($m[$k]))return $m[$k];$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");$s->execute(array(':t'=>$t,':c'=>$c));return $m[$k]=(bool)$s->fetchColumn();}
function psH($v){return trim((string)$v);}
function psNum($v,$d=0){return is_numeric($v)?(float)$v:(float)$d;}
function psBool($v){return in_array(strtolower(trim((string)$v)),array('1','yes','true','on'),true)?1:0;}
function psCsrf(){if($_SERVER['REQUEST_METHOD']==='POST'){$t=psPost('csrf_token');if(empty($_SESSION['products_services_csrf'])||!is_string($_SESSION['products_services_csrf'])||$t===''||!hash_equals($_SESSION['products_services_csrf'],$t))psOut(419,false,'Your form session expired. Refresh the page and try again.');}}
function psAudit(PDO $pdo,$tenant,$branch,$user,$action,$entityType,$entityId,$new){if(function_exists('tenantAuditLog')){try{tenantAuditLog($pdo,$action,$tenant,$branch>0?$branch:null,$user,$entityType,$entityId,null,$new);}catch(Throwable $e){error_log('Products services audit: '.$e->getMessage());}}}
function psStoreImage(PDO $pdo,$tenant,$kind,$field='item_image'){
    if(empty($_FILES[$field])||!isset($_FILES[$field]['error'])||$_FILES[$field]['error']===UPLOAD_ERR_NO_FILE)return null;
    if($_FILES[$field]['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Unable to upload image.');
    if((int)$_FILES[$field]['size']>5*1024*1024)throw new RuntimeException('Image must be 5 MB or smaller.');
    $tmp=$_FILES[$field]['tmp_name'];$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($tmp);$map=array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp');if(!isset($map[$mime]))throw new RuntimeException('Use a JPG, PNG, or WEBP image.');
    $dir=__DIR__.'/../uploads/catalog/'.(int)$tenant.'/'.($kind==='product'?'products':'services');if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Unable to create image directory.');
    $name=date('YmdHis').'-'.bin2hex(random_bytes(5)).'.'.$map[$mime];$abs=$dir.'/'.$name;if(!move_uploaded_file($tmp,$abs))throw new RuntimeException('Unable to save image.');
    return 'uploads/catalog/'.(int)$tenant.'/'.($kind==='product'?'products':'services').'/'.$name;
}
function psCsvCell($v){$v=(string)$v;if(preg_match('/^[=+\-@]/',$v))$v="'".$v;return '"'.str_replace('"','""',$v).'"';}
function psDownloadCsv($filename,$headers,$rows){while(ob_get_level()>0){@ob_end_clean();}header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');echo "\xEF\xBB\xBF";echo implode(',',array_map('psCsvCell',$headers))."\r\n";foreach($rows as $r){$line=array();foreach($headers as $h)$line[]=psCsvCell(isset($r[$h])?$r[$h]:'');echo implode(',',$line)."\r\n";}exit;}
function psHeaderMap($row){
    $map=array();
    foreach($row as $i=>$h){
        $k=(string)$h;
        // Excel/UTF-8 CSV files commonly place a BOM on the first header.
        if($i===0){$k=preg_replace('/^\xEF\xBB\xBF/', '', $k);}
        $k=str_replace("\xC2\xA0", ' ', $k);
        $k=strtolower(trim($k));
        $k=preg_replace('/[^a-z0-9]+/', '_', $k);
        $k=trim((string)$k,'_');
        if($k!==''){$map[$k]=$i;}
    }
    return $map;
}
function psVal($row,$map,$keys,$default=''){foreach($keys as $k){if(isset($map[$k])&&isset($row[$map[$k]]))return trim((string)$row[$map[$k]]);}return $default;}
function psDefaultTaxPercent(PDO $pdo,$tenant){
    if(!psTable($pdo,'product_tax_rates'))return 0.0;
    $sql="SELECT rate_percent FROM product_tax_rates WHERE tenant_id=:t AND status='active'";
    if(psCol($pdo,'product_tax_rates','is_default'))$sql.=" ORDER BY is_default DESC,id ASC";else $sql.=" ORDER BY id ASC";
    $sql.=" LIMIT 1";$s=$pdo->prepare($sql);$s->execute(array(':t'=>$tenant));$v=$s->fetchColumn();return $v!==false?(float)$v:0.0;
}
function psExisting(PDO $pdo,$tenant,$type,$name){
    if($type==='service'&&psTable($pdo,'product_services')){$sql="SELECT id FROM product_services WHERE tenant_id=:t AND item_type='service' AND LOWER(name)=LOWER(:n)".(psCol($pdo,'product_services','deleted_at')?" AND deleted_at IS NULL":"")." LIMIT 1";$s=$pdo->prepare($sql);$s->execute(array(':t'=>$tenant,':n'=>$name));$id=$s->fetchColumn();return $id?array('table'=>'product_services','id'=>(int)$id):null;}
    if($type==='product'&&psTable($pdo,'products')){$sql="SELECT id FROM products WHERE tenant_id=:t AND LOWER(name)=LOWER(:n)".(psCol($pdo,'products','deleted_at')?" AND deleted_at IS NULL":"")." LIMIT 1";$s=$pdo->prepare($sql);$s->execute(array(':t'=>$tenant,':n'=>$name));$id=$s->fetchColumn();return $id?array('table'=>'products','id'=>(int)$id):null;}
    if($type==='product'&&psTable($pdo,'product_services')){$sql="SELECT id FROM product_services WHERE tenant_id=:t AND item_type='product' AND LOWER(name)=LOWER(:n)".(psCol($pdo,'product_services','deleted_at')?" AND deleted_at IS NULL":"")." LIMIT 1";$s=$pdo->prepare($sql);$s->execute(array(':t'=>$tenant,':n'=>$name));$id=$s->fetchColumn();return $id?array('table'=>'product_services','id'=>(int)$id):null;}
    return null;
}
function psSaveRow(PDO $pdo,$tenant,$user,$row,$mode='create',$existing=null){
    $type=$row['item_type'];$name=$row['name'];$desc=$row['description'];$cost=(float)$row['unit_cost'];$markup=(float)$row['markup_percent'];$price=(float)$row['unit_price'];$taxExempt=(int)$row['tax_exempt'];$tax=$taxExempt?0:(float)$row['tax_percent'];
    if($type==='service'){
        if(!psTable($pdo,'product_services'))throw new RuntimeException('product_services table is not available.');
        $fields=array('tenant_id'=>$tenant,'item_type'=>'service','name'=>$name,'description'=>$desc!==''?$desc:null,'unit_name'=>'Service','unit_cost'=>$cost,'unit_price'=>$price,'tax_percent'=>$tax,'is_bookable'=>1,'estimated_duration_minutes'=>(int)$row['duration_minutes'],'status'=>'active');
        if(psCol($pdo,'product_services','markup_percent'))$fields['markup_percent']=$markup;if(psCol($pdo,'product_services','allow_customer_quantity'))$fields['allow_customer_quantity']=(int)$row['allow_customer_quantity'];if(psCol($pdo,'product_services','min_quantity'))$fields['min_quantity']=(int)$row['min_quantity'];if(psCol($pdo,'product_services','max_quantity'))$fields['max_quantity']=(int)$row['max_quantity'];
        if(!psCol($pdo,'product_services','is_bookable'))unset($fields['is_bookable']);if(!psCol($pdo,'product_services','estimated_duration_minutes'))unset($fields['estimated_duration_minutes']);if(!psCol($pdo,'product_services','unit_name'))unset($fields['unit_name']);if(!psCol($pdo,'product_services','tax_percent'))unset($fields['tax_percent']);
        if($existing&&$existing['table']==='product_services'){$sets=array();$p=array(':id'=>$existing['id'],':t'=>$tenant);foreach($fields as $k=>$v){if($k==='tenant_id'||$k==='item_type')continue;if(!psCol($pdo,'product_services',$k))continue;$sets[]="`$k`=:$k";$p[':'.$k]=$v;}if(psCol($pdo,'product_services','updated_at'))$sets[]='updated_at=NOW()';$s=$pdo->prepare('UPDATE product_services SET '.implode(',',$sets).' WHERE id=:id AND tenant_id=:t');$s->execute($p);return (int)$existing['id'];}
        $cols=array();$marks=array();$p=array();foreach($fields as $k=>$v){if(!psCol($pdo,'product_services',$k))continue;$cols[]='`'.$k.'`';$marks[]=':'.$k;$p[':'.$k]=$v;}if(psCol($pdo,'product_services','created_at')){$cols[]='created_at';$marks[]='NOW()';}$s=$pdo->prepare('INSERT INTO product_services('.implode(',',$cols).') VALUES('.implode(',',$marks).')');$s->execute($p);return (int)$pdo->lastInsertId();
    }
    if(psTable($pdo,'products')){
        $fields=array('tenant_id'=>$tenant,'name'=>$name,'description'=>$desc!==''?$desc:null,'unit_name'=>'Unit','base_unit_price'=>$cost,'markup_type'=>'percentage','markup_value'=>$markup,'selling_price'=>$price,'tax_percent'=>$tax,'track_inventory'=>0,'status'=>'active','created_by'=>$user);
        if($existing&&$existing['table']==='products'){$sets=array();$p=array(':id'=>$existing['id'],':t'=>$tenant);foreach($fields as $k=>$v){if($k==='tenant_id'||$k==='created_by')continue;if(!psCol($pdo,'products',$k))continue;$sets[]="`$k`=:$k";$p[':'.$k]=$v;}if(psCol($pdo,'products','updated_at'))$sets[]='updated_at=NOW()';$s=$pdo->prepare('UPDATE products SET '.implode(',',$sets).' WHERE id=:id AND tenant_id=:t');$s->execute($p);return (int)$existing['id'];}
        $cols=array();$marks=array();$p=array();foreach($fields as $k=>$v){if(!psCol($pdo,'products',$k))continue;$cols[]='`'.$k.'`';$marks[]=':'.$k;$p[':'.$k]=$v;}if(psCol($pdo,'products','created_at')){$cols[]='created_at';$marks[]='NOW()';}if(psCol($pdo,'products','updated_at')){$cols[]='updated_at';$marks[]='NOW()';}$s=$pdo->prepare('INSERT INTO products('.implode(',',$cols).') VALUES('.implode(',',$marks).')');$s->execute($p);return (int)$pdo->lastInsertId();
    }
    if(psTable($pdo,'product_services')){
        $fields=array('tenant_id'=>$tenant,'item_type'=>'product','name'=>$name,'description'=>$desc!==''?$desc:null,'unit_name'=>'Unit','unit_cost'=>$cost,'unit_price'=>$price,'tax_percent'=>$tax,'is_bookable'=>0,'status'=>'active');
        if(psCol($pdo,'product_services','markup_percent'))$fields['markup_percent']=$markup;
        if(!psCol($pdo,'product_services','unit_name'))unset($fields['unit_name']);if(!psCol($pdo,'product_services','tax_percent'))unset($fields['tax_percent']);if(!psCol($pdo,'product_services','is_bookable'))unset($fields['is_bookable']);
        if($existing&&$existing['table']==='product_services'){$sets=array();$p=array(':id'=>$existing['id'],':t'=>$tenant);foreach($fields as $k=>$v){if($k==='tenant_id'||$k==='item_type')continue;if(!psCol($pdo,'product_services',$k))continue;$sets[]="`$k`=:$k";$p[':'.$k]=$v;}if(psCol($pdo,'product_services','updated_at'))$sets[]='updated_at=NOW()';$s=$pdo->prepare('UPDATE product_services SET '.implode(',',$sets).' WHERE id=:id AND tenant_id=:t');$s->execute($p);return (int)$existing['id'];}
        $cols=array();$marks=array();$p=array();foreach($fields as $k=>$v){if(!psCol($pdo,'product_services',$k))continue;$cols[]='`'.$k.'`';$marks[]=':'.$k;$p[':'.$k]=$v;}if(psCol($pdo,'product_services','created_at')){$cols[]='created_at';$marks[]='NOW()';}$s=$pdo->prepare('INSERT INTO product_services('.implode(',',$cols).') VALUES('.implode(',',$marks).')');$s->execute($p);return (int)$pdo->lastInsertId();
    }
    throw new RuntimeException('No product table is available.');
}

$tenant=isset($currentTenantId)?(int)$currentTenantId:(isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0);$user=isset($currentTenantUserId)?(int)$currentTenantUserId:(isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:0);$branch=isset($currentBranchId)?(int)$currentBranchId:(isset($_SESSION['branch_id'])?(int)$_SESSION['branch_id']:0);
if($tenant<=0)psOut(401,false,'Tenant session is not available.');

$action=isset($_GET['action'])?trim((string)$_GET['action']):psPost('action');
if($action==='sample_csv'){
    $headers=array('Type','Name','Description','Cost','Markup Percent','Unit Price','Tax Exempt','Tax Percent','Service Duration Minutes','Allow Customer Quantity','Minimum Quantity','Maximum Quantity');
    $rows=array(
        array('Type'=>'Service','Name'=>'Air Conditioner Service','Description'=>'Standard AC inspection and service','Cost'=>'500.00','Markup Percent'=>'20','Unit Price'=>'600.00','Tax Exempt'=>'No','Tax Percent'=>'18','Service Duration Minutes'=>'60','Allow Customer Quantity'=>'Yes','Minimum Quantity'=>'1','Maximum Quantity'=>'10'),
        array('Type'=>'Product','Name'=>'Replacement Filter','Description'=>'Standard replacement filter','Cost'=>'250.00','Markup Percent'=>'20','Unit Price'=>'300.00','Tax Exempt'=>'No','Tax Percent'=>'18','Service Duration Minutes'=>'','Allow Customer Quantity'=>'','Minimum Quantity'=>'','Maximum Quantity'=>'')
    );psDownloadCsv('fieldplx-products-services-sample.csv',$headers,$rows);
}
if($action==='export_csv'){
    $headers=array('Type','Name','Description','Cost','Markup Percent','Unit Price','Tax Exempt','Tax Percent','Service Duration Minutes','Allow Customer Quantity','Minimum Quantity','Maximum Quantity');$rows=array();
    if(psTable($pdo,'product_services')){$cols="id,item_type,name,description,unit_cost,unit_price".(psCol($pdo,'product_services','tax_percent')?',tax_percent':',0 AS tax_percent').(psCol($pdo,'product_services','estimated_duration_minutes')?',estimated_duration_minutes':',NULL AS estimated_duration_minutes').(psCol($pdo,'product_services','markup_percent')?',markup_percent':',0 AS markup_percent').(psCol($pdo,'product_services','allow_customer_quantity')?',allow_customer_quantity':',0 AS allow_customer_quantity').(psCol($pdo,'product_services','min_quantity')?',min_quantity':',1 AS min_quantity').(psCol($pdo,'product_services','max_quantity')?',max_quantity':',10 AS max_quantity');$sql="SELECT $cols FROM product_services WHERE tenant_id=:t".(psCol($pdo,'product_services','deleted_at')?" AND deleted_at IS NULL":"")." AND status='active'";$s=$pdo->prepare($sql);$s->execute(array(':t'=>$tenant));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$rows[]=array('Type'=>ucfirst($r['item_type']),'Name'=>$r['name'],'Description'=>$r['description'],'Cost'=>number_format((float)$r['unit_cost'],2,'.',''),'Markup Percent'=>number_format((float)$r['markup_percent'],2,'.',''),'Unit Price'=>number_format((float)$r['unit_price'],2,'.',''),'Tax Exempt'=>(float)$r['tax_percent']<=0?'Yes':'No','Tax Percent'=>number_format((float)$r['tax_percent'],2,'.',''),'Service Duration Minutes'=>$r['item_type']==='service'?(string)$r['estimated_duration_minutes']:'','Allow Customer Quantity'=>$r['item_type']==='service'&&$r['allow_customer_quantity']?'Yes':'No','Minimum Quantity'=>$r['item_type']==='service'?$r['min_quantity']:'','Maximum Quantity'=>$r['item_type']==='service'?$r['max_quantity']:'');}}
    if(psTable($pdo,'products')){$cols="id,name,description,base_unit_price,selling_price".(psCol($pdo,'products','markup_value')?',markup_value':',0 AS markup_value').(psCol($pdo,'products','tax_percent')?',tax_percent':',0 AS tax_percent');$sql="SELECT $cols FROM products WHERE tenant_id=:t".(psCol($pdo,'products','deleted_at')?" AND deleted_at IS NULL":"")." AND status='active'";$s=$pdo->prepare($sql);$s->execute(array(':t'=>$tenant));foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$rows[]=array('Type'=>'Product','Name'=>$r['name'],'Description'=>$r['description'],'Cost'=>number_format((float)$r['base_unit_price'],2,'.',''),'Markup Percent'=>number_format((float)$r['markup_value'],2,'.',''),'Unit Price'=>number_format((float)$r['selling_price'],2,'.',''),'Tax Exempt'=>(float)$r['tax_percent']<=0?'Yes':'No','Tax Percent'=>number_format((float)$r['tax_percent'],2,'.',''),'Service Duration Minutes'=>'','Allow Customer Quantity'=>'','Minimum Quantity'=>'','Maximum Quantity'=>'');}}
    psDownloadCsv('fieldplx-products-services-'.date('Y-m-d').'.csv',$headers,$rows);
}

if($_SERVER['REQUEST_METHOD']!=='POST')psOut(405,false,'Method not allowed.');psCsrf();
try{
if($action==='list'){
    $search=psPost('search');$page=max(1,(int)psPost('page','1'));$per=max(10,min(100,(int)psPost('per_page','25')));$sort=psPost('sort','name');$dir=strtolower(psPost('dir','asc'))==='desc'?'DESC':'ASC';$rows=array();
    if(psTable($pdo,'product_services')){$sql="SELECT id,item_type,name,description,'product_services' source_table FROM product_services WHERE tenant_id=:t".(psCol($pdo,'product_services','deleted_at')?" AND deleted_at IS NULL":"")." AND status='active'";$p=array(':t'=>$tenant);if($search!==''){$sql.=" AND (name LIKE :q OR description LIKE :q)";$p[':q']='%'.$search.'%';}$s=$pdo->prepare($sql);$s->execute($p);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$rows[]=array('key'=>'product_services:'.$r['id'],'id'=>(int)$r['id'],'name'=>$r['name'],'description'=>$r['description'],'item_type'=>$r['item_type'],'type_label'=>ucfirst($r['item_type']),'source_table'=>'product_services');}
    if(psTable($pdo,'products')){$sql="SELECT id,name,description FROM products WHERE tenant_id=:t".(psCol($pdo,'products','deleted_at')?" AND deleted_at IS NULL":"")." AND status='active'";$p=array(':t'=>$tenant);if($search!==''){$sql.=" AND (name LIKE :q OR description LIKE :q)";$p[':q']='%'.$search.'%';}$s=$pdo->prepare($sql);$s->execute($p);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$rows[]=array('key'=>'products:'.$r['id'],'id'=>(int)$r['id'],'name'=>$r['name'],'description'=>$r['description'],'item_type'=>'product','type_label'=>'Product','source_table'=>'products');}
    usort($rows,function($a,$b)use($sort,$dir){$ka=$sort==='type'?$a['type_label']:$a['name'];$kb=$sort==='type'?$b['type_label']:$b['name'];$cmp=strcasecmp((string)$ka,(string)$kb);return $dir==='DESC'?-1*$cmp:$cmp;});$total=count($rows);$pages=max(1,(int)ceil($total/$per));if($page>$pages)$page=$pages;$from=$total?(($page-1)*$per+1):0;$to=min($total,$page*$per);$rows=array_slice($rows,($page-1)*$per,$per);psOut(200,true,'Products and services loaded.',array('items'=>$rows,'pagination'=>array('page'=>$page,'pages'=>$pages,'per_page'=>$per,'total'=>$total,'from'=>$from,'to'=>$to)));
}
if($action==='get_item'){
    $key=psPost('key');if(!preg_match('/^(product_services|products):(\d+)$/',$key,$m))psOut(422,false,'Invalid item.');$table=$m[1];$id=(int)$m[2];
    if($table==='products'){$cols="id,name,description,base_unit_price,selling_price".(psCol($pdo,'products','markup_value')?',markup_value':',0 markup_value').(psCol($pdo,'products','tax_percent')?',tax_percent':',0 tax_percent');$s=$pdo->prepare("SELECT $cols FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");$s->execute(array(':id'=>$id,':t'=>$tenant));$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)psOut(404,false,'Product not found.');$item=array('id'=>$id,'source_table'=>'products','item_type'=>'product','name'=>$r['name'],'description'=>$r['description'],'unit_cost'=>$r['base_unit_price'],'markup_percent'=>$r['markup_value'],'unit_price'=>$r['selling_price'],'tax_percent'=>$r['tax_percent'],'duration_minutes'=>60,'allow_customer_quantity'=>0,'min_quantity'=>1,'max_quantity'=>10);psOut(200,true,'Product loaded.',array('item'=>$item));}
    $cols="id,item_type,name,description,unit_cost,unit_price".(psCol($pdo,'product_services','tax_percent')?',tax_percent':',0 tax_percent').(psCol($pdo,'product_services','markup_percent')?',markup_percent':',0 markup_percent').(psCol($pdo,'product_services','estimated_duration_minutes')?',estimated_duration_minutes':',60 estimated_duration_minutes').(psCol($pdo,'product_services','allow_customer_quantity')?',allow_customer_quantity':',0 allow_customer_quantity').(psCol($pdo,'product_services','min_quantity')?',min_quantity':',1 min_quantity').(psCol($pdo,'product_services','max_quantity')?',max_quantity':',10 max_quantity');$s=$pdo->prepare("SELECT $cols FROM product_services WHERE id=:id AND tenant_id=:t LIMIT 1");$s->execute(array(':id'=>$id,':t'=>$tenant));$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)psOut(404,false,'Item not found.');$item=array('id'=>$id,'source_table'=>'product_services','item_type'=>$r['item_type'],'name'=>$r['name'],'description'=>$r['description'],'unit_cost'=>$r['unit_cost'],'markup_percent'=>$r['markup_percent'],'unit_price'=>$r['unit_price'],'tax_percent'=>$r['tax_percent'],'duration_minutes'=>$r['estimated_duration_minutes'],'allow_customer_quantity'=>$r['allow_customer_quantity'],'min_quantity'=>$r['min_quantity'],'max_quantity'=>$r['max_quantity']);psOut(200,true,'Item loaded.',array('item'=>$item));
}
if($action==='save_item'){
    $id=(int)psPost('item_id','0');$source=psPost('source_table');$type=strtolower(psPost('item_type','service'));if(!in_array($type,array('service','product'),true))$type='service';$name=psPost('name');$desc=psPost('description');$cost=max(0,psNum(psPost('unit_cost','0')));$markup=max(0,psNum(psPost('markup_percent','0')));$price=max(0,psNum(psPost('unit_price','0')));$taxExempt=isset($_POST['tax_exempt'])?1:0;$defaultTax=psDefaultTaxPercent($pdo,$tenant);$duration=max(15,(int)psPost('duration_minutes','60'));$allowQty=isset($_POST['allow_customer_quantity'])?1:0;$minQty=max(1,(int)psPost('min_quantity','1'));$maxQty=max($minQty,(int)psPost('max_quantity','10'));if($name==='')psOut(422,false,'Name is required.');
    $existing=null;if($id>0&&in_array($source,array('products','product_services'),true))$existing=array('table'=>$source,'id'=>$id);elseif($id<=0){$existing=psExisting($pdo,$tenant,$type,$name);if($existing)psOut(409,false,'An active '.($type==='service'?'service':'product').' with this name already exists.');}
    $row=array('item_type'=>$type,'name'=>$name,'description'=>$desc,'unit_cost'=>$cost,'markup_percent'=>$markup,'unit_price'=>$price,'tax_exempt'=>$taxExempt,'tax_percent'=>$taxExempt?0:$defaultTax,'duration_minutes'=>$duration,'allow_customer_quantity'=>$allowQty,'min_quantity'=>$minQty,'max_quantity'=>$maxQty);
    $pdo->beginTransaction();$savedId=psSaveRow($pdo,$tenant,$user,$row,'save',$existing);
    if(isset($_FILES['item_image'])&&$_FILES['item_image']['error']!==UPLOAD_ERR_NO_FILE){$path=psStoreImage($pdo,$tenant,$type);$table=$type==='product'&&psTable($pdo,'products')?'products':'product_services';if(psCol($pdo,$table,'image_path')){$s=$pdo->prepare("UPDATE `$table` SET image_path=:p WHERE id=:id AND tenant_id=:t");$s->execute(array(':p'=>$path,':id'=>$savedId,':t'=>$tenant));}}
    $pdo->commit();psAudit($pdo,$tenant,$branch,$user,$id>0?'PRODUCT_SERVICE_UPDATED':'PRODUCT_SERVICE_CREATED',$type,$savedId,$row);psOut(200,true,$id>0?'Product / service updated successfully.':'Product / service created successfully.',array('id'=>$savedId));
}
if($action==='preview_import'){
    if(empty($_FILES['csv_file'])||$_FILES['csv_file']['error']!==UPLOAD_ERR_OK)psOut(422,false,'Select a valid CSV file.');if((int)$_FILES['csv_file']['size']>5*1024*1024)psOut(422,false,'CSV file must be 5 MB or smaller.');$mode=psPost('duplicate_mode','skip');if(!in_array($mode,array('skip','update'),true))$mode='skip';$fh=fopen($_FILES['csv_file']['tmp_name'],'r');if(!$fh)psOut(422,false,'Unable to read CSV file.');$header=fgetcsv($fh);if(!$header){fclose($fh);psOut(422,false,'CSV is empty.');}$map=psHeaderMap($header);
    // Accept the FieldPlx generated sample/export headers plus common equivalent names.
    if(!isset($map['type'])){foreach(array('item_type','product_service_type','product_or_service','product_service') as $alias){if(isset($map[$alias])){$map['type']=$map[$alias];break;}}}
    if(!isset($map['name'])){foreach(array('item_name','product_service_name','product_name','service_name') as $alias){if(isset($map[$alias])){$map['name']=$map[$alias];break;}}}
    if(!isset($map['type'])||!isset($map['name'])){
        $detected=array_keys($map);
        fclose($fh);
        psOut(422,false,'CSV must include Type and Name columns.',array('detected_headers'=>$detected));
    }
    $rows=array();$saveRows=array();$errors=0;$warnings=0;$valid=0;$n=1;while(($r=fgetcsv($fh))!==false){$n++;if($n>2001){$errors++;$rows[]=array('row'=>$n,'item_type'=>'','name'=>'','unit_cost'=>'','markup_percent'=>'','unit_price'=>'','status'=>'error','message'=>'Maximum 2,000 data rows per import.');break;}$blank=true;foreach($r as $v){if(trim((string)$v)!==''){$blank=false;break;}}if($blank)continue;$type=strtolower(psVal($r,$map,array('type','item_type'),'service'));if($type==='services')$type='service';if($type==='products')$type='product';$name=psVal($r,$map,array('name','item_name'));$desc=psVal($r,$map,array('description'));$cost=psVal($r,$map,array('cost','unit_cost'),'0');$markup=psVal($r,$map,array('markup_percent','markup'),'0');$price=psVal($r,$map,array('unit_price','price'),'0');$taxEx=psVal($r,$map,array('tax_exempt','exempt_from_tax'),'No');$tax=psVal($r,$map,array('tax_percent','tax'),'0');$dur=psVal($r,$map,array('service_duration_minutes','duration_minutes','duration'),'60');$allow=psVal($r,$map,array('allow_customer_quantity','allow_customers_to_select_quantity'),'No');$min=psVal($r,$map,array('minimum_quantity','min_quantity'),'1');$max=psVal($r,$map,array('maximum_quantity','max_quantity'),'10');$status='ok';$msg='Ready to import.';
        if(!in_array($type,array('service','product'),true)){$status='error';$msg='Type must be Service or Product.';}elseif($name===''){$status='error';$msg='Name is required.';}elseif(!is_numeric($cost)||psNum($cost)<0||!is_numeric($markup)||psNum($markup)<0||!is_numeric($price)||psNum($price)<0){$status='error';$msg='Cost, markup and unit price must be valid positive numbers.';}elseif($type==='service'&&(!is_numeric($dur)||(int)$dur<15)){$status='error';$msg='Service duration must be at least 15 minutes.';}elseif($type==='service'&&psBool($allow)&&((int)$min<1||(int)$max<(int)$min)){$status='error';$msg='Quantity range is invalid.';}
        $existing=null;if($status!=='error'){$existing=psExisting($pdo,$tenant,$type,$name);if($existing&&$mode==='skip'){$status='warning';$msg='Existing item found. This row will be skipped.';$warnings++;}elseif($existing&&$mode==='update'){$status='warning';$msg='Existing item found. This row will update it.';$warnings++;}}
        if($status==='error')$errors++;else{$valid++;$saveRows[]=array('row'=>$n,'item_type'=>$type,'name'=>$name,'description'=>$desc,'unit_cost'=>psNum($cost),'markup_percent'=>psNum($markup),'unit_price'=>psNum($price),'tax_exempt'=>psBool($taxEx),'tax_percent'=>psNum($tax),'duration_minutes'=>(int)$dur,'allow_customer_quantity'=>psBool($allow),'min_quantity'=>(int)$min,'max_quantity'=>(int)$max,'existing'=>$existing,'skip'=>$existing&&$mode==='skip'?1:0);}
        $rows[]=array('row'=>$n,'item_type'=>ucfirst($type),'name'=>$name,'unit_cost'=>$cost,'markup_percent'=>$markup,'unit_price'=>$price,'status'=>$status,'message'=>$msg);
    }fclose($fh);$token=bin2hex(random_bytes(20));$_SESSION['ps_import_preview_'.$token]=array('tenant_id'=>$tenant,'user_id'=>$user,'mode'=>$mode,'rows'=>$saveRows,'created_at'=>time());psOut(200,true,$errors>0?'Preview contains errors. Correct the CSV and preview again.':'Preview completed. Review the rows, then click Import.',array('preview_token'=>$token,'rows'=>$rows,'total'=>count($rows),'valid'=>$valid,'warnings'=>$warnings,'errors'=>$errors));
}
if($action==='commit_import'){
    $token=psPost('preview_token');$key='ps_import_preview_'.$token;if($token===''||empty($_SESSION[$key])||!is_array($_SESSION[$key]))psOut(422,false,'Import preview expired. Preview the CSV again.');$pv=$_SESSION[$key];if((int)$pv['tenant_id']!==$tenant||time()-(int)$pv['created_at']>1800){unset($_SESSION[$key]);psOut(422,false,'Import preview expired. Preview the CSV again.');}$rows=$pv['rows'];$created=0;$updated=0;$skipped=0;$pdo->beginTransaction();foreach($rows as $r){if(!empty($r['skip'])){$skipped++;continue;}$existing=isset($r['existing'])?$r['existing']:null;$id=psSaveRow($pdo,$tenant,$user,$r,'import',$existing);if($existing)$updated++;else$created++;} $pdo->commit();unset($_SESSION[$key]);psAudit($pdo,$tenant,$branch,$user,'PRODUCT_SERVICES_IMPORTED','product_service',0,array('created'=>$created,'updated'=>$updated,'skipped'=>$skipped));psOut(200,true,'Import completed: '.$created.' created, '.$updated.' updated, '.$skipped.' skipped.',array('created'=>$created,'updated'=>$updated,'skipped'=>$skipped));
}
psOut(400,false,'Invalid action.');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('FieldPlx products services API error: '.$e->getMessage());psOut(500,false,'Unable to complete the products & services action.');}
