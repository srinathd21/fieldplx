<?php
/* FieldPlx Quotation List API - Version 2.0.0 - 2026-09-09 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

const FIELDPLX_QUOTATION_LIST_API_VERSION = '2.0.0';

function qlResponse($code, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int)$code);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message,
        'api_version' => FIELDPLX_QUOTATION_LIST_API_VERSION
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qlPost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function qlColumnExists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );
    $stmt->execute(array(
        ':table_name' => $table,
        ':column_name' => $column
    ));

    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function qlCurrency(PDO $pdo, $tenantId, $branchId)
{
    $stmt = $pdo->prepare(
        "SELECT
            c.id,
            c.currency_code,
            c.currency_name,
            c.symbol,
            c.symbol_position,
            c.decimal_places,
            c.decimal_separator,
            c.thousand_separator
         FROM tenants t
         LEFT JOIN branches b
           ON b.id = :branch_id
          AND b.tenant_id = t.id
         LEFT JOIN currencies c
           ON c.id = COALESCE(b.currency_id, t.currency_id)
         WHERE t.id = :tenant_id
         LIMIT 1"
    );
    $stmt->execute(array(
        ':branch_id' => $branchId > 0 ? $branchId : -1,
        ':tenant_id' => $tenantId
    ));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['currency_code'])) {
        return $row;
    }

    return array(
        'id' => null,
        'currency_code' => 'INR',
        'currency_name' => 'Indian Rupee',
        'symbol' => '₹',
        'symbol_position' => 'before',
        'decimal_places' => 2,
        'decimal_separator' => '.',
        'thousand_separator' => ','
    );
}

function qlValidDate($value)
{
    if ($value === '') {
        return true;
    }

    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}


/* ===== Unified quotation form API helpers (Jobber-style add-quotation.php) ===== */
function qxTableExists(PDO $pdo, $table)
{
    static $cache = array();
    $table = (string)$table;
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
    $stmt->execute(array(':t' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function qxColumns(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    if (!qxTableExists($pdo, $table)) return $cache[$table] = array();
    $stmt = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
    $stmt->execute(array(':t' => $table));
    $out = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['COLUMN_NAME']] = $r['COLUMN_TYPE'];
    return $cache[$table] = $out;
}

function qxFilterColumns(PDO $pdo, $table, array $data)
{
    $cols = qxColumns($pdo, $table);
    $out = array();
    foreach ($data as $k => $v) if (isset($cols[$k])) $out[$k] = $v;
    return $out;
}

function qxInsert(PDO $pdo, $table, array $data)
{
    $data = qxFilterColumns($pdo, $table, $data);
    if (!$data) throw new RuntimeException('No compatible columns found for ' . $table . '.');
    $cols = array_keys($data);
    $marks = array_map(function($c){ return ':' . $c; }, $cols);
    $sql = 'INSERT INTO `' . str_replace('`','',$table) . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $marks) . ')';
    $stmt = $pdo->prepare($sql);
    $params = array(); foreach ($data as $k=>$v) $params[':' . $k] = $v;
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function qxUpdate(PDO $pdo, $table, array $data, $whereSql, array $whereParams)
{
    $data = qxFilterColumns($pdo, $table, $data);
    if (!$data) return 0;
    $set = array(); $params = array();
    foreach ($data as $k=>$v) { $set[] = '`' . $k . '` = :u_' . $k; $params[':u_' . $k] = $v; }
    foreach ($whereParams as $k=>$v) $params[$k] = $v;
    $stmt = $pdo->prepare('UPDATE `' . str_replace('`','',$table) . '` SET ' . implode(', ', $set) . ' WHERE ' . $whereSql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function qxJsonArray($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return array();
    $v = json_decode($raw, true);
    return is_array($v) ? $v : array();
}

function qxLocationTable(PDO $pdo)
{
    if (qxTableExists($pdo, 'client_locations')) return 'client_locations';
    if (qxTableExists($pdo, 'properties')) return 'properties';
    return '';
}

function qxRequestTable(PDO $pdo)
{
    if (qxTableExists($pdo, 'service_requests')) return 'service_requests';
    if (qxTableExists($pdo, 'requests')) return 'requests';
    return '';
}

function qxQuoteLocationColumn(PDO $pdo)
{
    if (qlColumnExists($pdo, 'quotes', 'location_id')) return 'location_id';
    if (qlColumnExists($pdo, 'quotes', 'property_id')) return 'property_id';
    return '';
}

function qxEnumAllows(PDO $pdo, $table, $column, $value)
{
    $cols = qxColumns($pdo, $table);
    if (!isset($cols[$column])) return false;
    $type = (string)$cols[$column];
    if (stripos($type, 'enum(') !== 0) return true;
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $m);
    return in_array((string)$value, isset($m[1]) ? $m[1] : array(), true);
}

function qxNextQuoteNo(PDO $pdo, $tenantId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0) + 1 FROM quotes WHERE tenant_id = :t");
    $stmt->execute(array(':t'=>$tenantId));
    return 'QUO-' . str_pad((string)((int)$stmt->fetchColumn()), 6, '0', STR_PAD_LEFT);
}

function qxTaxRows(PDO $pdo, $tenantId)
{
    if (!qxTableExists($pdo, 'tax_rates')) return array();
    $cols = qxColumns($pdo, 'tax_rates');
    $sql = 'SELECT * FROM tax_rates WHERE tenant_id = :t';
    if (isset($cols['is_active'])) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY ' . (isset($cols['is_default']) ? 'is_default DESC, ' : '') . 'id ASC';
    $st = $pdo->prepare($sql); $st->execute(array(':t'=>$tenantId));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = array(
            'id'=>(int)$r['id'],
            'tax_name'=>(string)(isset($r['name'])?$r['name']:(isset($r['tax_name'])?$r['tax_name']:'Tax')),
            'name'=>(string)(isset($r['name'])?$r['name']:(isset($r['tax_name'])?$r['tax_name']:'Tax')),
            'rate_percent'=>(float)(isset($r['rate'])?$r['rate']:(isset($r['rate_percent'])?$r['rate_percent']:0)),
            'rate'=>(float)(isset($r['rate'])?$r['rate']:(isset($r['rate_percent'])?$r['rate_percent']:0)),
            'is_default'=>(int)(isset($r['is_default'])?$r['is_default']:0)
        );
    }
    return $out;
}

function qxTaxIdByPercent(PDO $pdo, $tenantId, $percent)
{
    $percent = (float)$percent;
    if ($percent <= 0 || !qxTableExists($pdo, 'tax_rates')) return null;
    $cols = qxColumns($pdo, 'tax_rates');
    $rateCol = isset($cols['rate']) ? 'rate' : (isset($cols['rate_percent']) ? 'rate_percent' : '');
    if ($rateCol === '') return null;
    $st = $pdo->prepare("SELECT id FROM tax_rates WHERE tenant_id=:t AND ABS(`$rateCol`-:r)<0.0001 ORDER BY id LIMIT 1");
    $st->execute(array(':t'=>$tenantId, ':r'=>$percent));
    $id = (int)$st->fetchColumn(); return $id > 0 ? $id : null;
}

function qxEnsureQuote(PDO $pdo, $tenantId, $quoteId)
{
    $st = $pdo->prepare('SELECT * FROM quotes WHERE id=:id AND tenant_id=:t LIMIT 1');
    $st->execute(array(':id'=>(int)$quoteId, ':t'=>(int)$tenantId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) qlResponse(404, false, 'Quotation not found.');
    return $row;
}

function qxUploadPath($tenantId, $quoteId, array $file, array $allowedExt, $maxBytes)
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Choose a valid file.');
    if ((int)$file['size'] <= 0 || (int)$file['size'] > (int)$maxBytes) throw new RuntimeException('The uploaded file is too large.');
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) throw new RuntimeException('This file type is not allowed.');
    $dir = dirname(__DIR__) . '/uploads/quotes/' . (int)$tenantId . '/' . (int)$quoteId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Unable to create the quotation upload folder.');
    $name = date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    $target = $dir . '/' . $name;
    if (!@move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('Unable to save the uploaded file.');
    return array('disk'=>$target, 'path'=>'uploads/quotes/' . (int)$tenantId . '/' . (int)$quoteId . '/' . $name, 'name'=>(string)$file['name'], 'mime'=>(string)(isset($file['type'])?$file['type']:''), 'size'=>(int)$file['size']);
}

function qxAttachmentRows(PDO $pdo, $tenantId, $quoteId)
{
    if (!qxTableExists($pdo, 'attachments')) return array();
    $st = $pdo->prepare("SELECT * FROM attachments WHERE tenant_id=:t AND related_id=:q AND related_type IN ('quote','quotation','quote_internal') ORDER BY id");
    $st->execute(array(':t'=>$tenantId, ':q'=>$quoteId));
    $out=array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d=(string)(isset($r['description'])?$r['description']:'');
        if (strpos($d,'line_image:')===0) continue;
        $cat = strpos($d,'quote_category:')===0 ? substr($d,15) : ((isset($r['attachment_type']) && $r['attachment_type']==='photo')?'image':'attachment');
        $out[] = array('id'=>(int)$r['id'],'file_category'=>$cat,'original_name'=>(string)(isset($r['file_name'])?$r['file_name']:'File'),'file_path'=>(string)$r['file_path'],'file_size'=>(int)(isset($r['file_size'])?$r['file_size']:0),'file_mime'=>(string)(isset($r['file_mime'])?$r['file_mime']:''));
    }
    return $out;
}

function qxLineImageMap(PDO $pdo, $tenantId, $quoteId)
{
    $map=array(); if (!qxTableExists($pdo,'attachments')) return $map;
    $st=$pdo->prepare("SELECT * FROM attachments WHERE tenant_id=:t AND related_id=:q AND related_type='quote' AND description LIKE 'line_image:%' ORDER BY id");
    $st->execute(array(':t'=>$tenantId, ':q'=>$quoteId));
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $idx=(int)substr((string)$r['description'],11); $map[$idx]=(string)$r['file_path']; }
    return $map;
}

$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = !empty($_SESSION['tenant_user_id'])
    ? (int)$_SESSION['tenant_user_id']
    : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$branchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;

if ($tenantId <= 0 || $userId <= 0) {
    qlResponse(401, false, 'Authentication required.');
}

$csrfToken = trim((string)qlPost('csrf_token', ''));
$sessionCsrf = isset($_SESSION['quotations_csrf_token'])
    ? (string)$_SESSION['quotations_csrf_token']
    : '';

if ($csrfToken === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    qlResponse(419, false, 'Your form session expired. Refresh and try again.');
}

$action = trim((string)qlPost('action', 'list'));
/* Form-builder actions used by add-quotation.php. Each successful branch exits via qlResponse(). */
if ($action === 'form_meta') {
    $clients=array();
    if (qxTableExists($pdo,'clients')) {
        $sql="SELECT * FROM clients WHERE tenant_id=:t";
        if (qlColumnExists($pdo,'clients','status')) $sql .= " AND status NOT IN ('inactive','archived')";
        if (qlColumnExists($pdo,'clients','deleted_at')) $sql .= " AND deleted_at IS NULL";
        $sql.=' ORDER BY display_name,id';
        $st=$pdo->prepare($sql);$st->execute(array(':t'=>$tenantId));
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$r['name']=$r['display_name'];$clients[]=$r;}
    }

    $locations=array(); $lt=qxLocationTable($pdo);
    if($lt!==''){
        $sql="SELECT * FROM `$lt` WHERE tenant_id=:t";
        if(qlColumnExists($pdo,$lt,'deleted_at'))$sql.=' AND deleted_at IS NULL';
        if(qlColumnExists($pdo,$lt,'status'))$sql.=" AND status <> 'archived'";
        $sql.=' ORDER BY client_id,is_primary DESC,id';
        $st=$pdo->prepare($sql);$st->execute(array(':t'=>$tenantId));
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){if(!isset($r['location_type']))$r['location_type']='site';$locations[]=$r;}
    }

    $catalog=array();
    if(qxTableExists($pdo,'product_services')){
        $sql="SELECT * FROM product_services WHERE tenant_id=:t";
        if(qlColumnExists($pdo,'product_services','deleted_at'))$sql.=' AND deleted_at IS NULL';
        if(qlColumnExists($pdo,'product_services','status'))$sql.=" AND status='active'";
        $sql.=' ORDER BY name,id';
        $st=$pdo->prepare($sql);$st->execute(array(':t'=>$tenantId));
        $taxRows=qxTaxRows($pdo,$tenantId);$taxMap=array();foreach($taxRows as $tr)$taxMap[(int)$tr['id']]=$tr;
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$tid=(int)(isset($r['tax_rate_id'])?$r['tax_rate_id']:0);$r['tax_percent']=$tid&&isset($taxMap[$tid])?(float)$taxMap[$tid]['rate_percent']:0;$catalog[]=$r;}
    }

    $sales=array();
    if(qxTableExists($pdo,'users')){
        $sql="SELECT id,first_name,last_name,email,phone FROM users WHERE tenant_id=:t";
        if(qlColumnExists($pdo,'users','status'))$sql.=" AND status='active'";
        if(qlColumnExists($pdo,'users','deleted_at'))$sql.=' AND deleted_at IS NULL';
        $sql.=' ORDER BY first_name,last_name,id';
        $st=$pdo->prepare($sql);$st->execute(array(':t'=>$tenantId));$sales=$st->fetchAll(PDO::FETCH_ASSOC);
    }

    $requests=array();$rt=qxRequestTable($pdo);
    if($rt!==''){
        $sql="SELECT r.*,c.display_name AS client_name FROM `$rt` r LEFT JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id WHERE r.tenant_id=:t";
        if(qlColumnExists($pdo,$rt,'archived_at'))$sql.=' AND r.archived_at IS NULL';
        if(qlColumnExists($pdo,$rt,'deleted_at'))$sql.=' AND r.deleted_at IS NULL';
        if(qlColumnExists($pdo,$rt,'converted_quote_id'))$sql.=' AND (r.converted_quote_id IS NULL OR r.converted_quote_id=0)';
        $sql.=' ORDER BY r.id DESC LIMIT 300';
        $st=$pdo->prepare($sql);$st->execute(array(':t'=>$tenantId));
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$r['source_key']='request:'.(int)$r['id'];$r['source_label']='Original Enquiry';if(!isset($r['location_id'])&&isset($r['property_id']))$r['location_id']=$r['property_id'];$requests[]=$r;}
    }

    $taxRates=qxTaxRows($pdo,$tenantId);$defaultTax=0;foreach($taxRates as $tr)if((int)$tr['is_default']===1){$defaultTax=(int)$tr['id'];break;}
    qlResponse(200,true,'Quotation form loaded.',array('meta'=>array('currency'=>qlCurrency($pdo,$tenantId,$branchId),'clients'=>$clients,'locations'=>$locations,'catalog'=>$catalog,'products'=>array(),'requests'=>$requests,'salespersons'=>$sales,'team_members'=>$sales,'tax_rates'=>$taxRates,'default_tax_rate_id'=>$defaultTax,'next_quote_no'=>qxNextQuoteNo($pdo,$tenantId),'current_user_id'=>$userId,'quote_settings'=>array('default_disclaimer'=>''))));
}

if ($action === 'get') {
    $quoteId=(int)qlPost('quote_id',0);$q=qxEnsureQuote($pdo,$tenantId,$quoteId);
    $locCol=qxQuoteLocationColumn($pdo);if($locCol&&$locCol!=='location_id')$q['location_id']=isset($q[$locCol])?$q[$locCol]:null;
    if(!empty($q['request_id']))$q['source_key']='request:'.(int)$q['request_id'];else$q['source_key']='';
    if(!isset($q['internal_notes'])&&qxTableExists($pdo,'notes')){$st=$pdo->prepare("SELECT note FROM notes WHERE tenant_id=:t AND related_type='quote_internal' AND related_id=:q ORDER BY id DESC LIMIT 1");$st->execute(array(':t'=>$tenantId,':q'=>$quoteId));$q['internal_notes']=(string)($st->fetchColumn()?:'');}
    $items=array();
    if(qxTableExists($pdo,'quote_line_items')){$st=$pdo->prepare('SELECT * FROM quote_line_items WHERE tenant_id=:t AND quote_id=:q ORDER BY sort_order,id');$st->execute(array(':t'=>$tenantId,':q'=>$quoteId));$items=$st->fetchAll(PDO::FETCH_ASSOC);$img=qxLineImageMap($pdo,$tenantId,$quoteId);foreach($items as $i=>&$x){$tid=(int)(isset($x['tax_rate_id'])?$x['tax_rate_id']:0);$x['tax_percent']=0;if($tid){foreach(qxTaxRows($pdo,$tenantId) as $tr)if((int)$tr['id']===$tid){$x['tax_percent']=$tr['rate_percent'];break;}}if(!isset($x['image_path'])&&isset($img[$i]))$x['image_path']=$img[$i];}unset($x);}
    $sections=array();if(qxTableExists($pdo,'quote_sections')){$st=$pdo->prepare('SELECT * FROM quote_sections WHERE tenant_id=:t AND quote_id=:q ORDER BY sort_order,id');$st->execute(array(':t'=>$tenantId,':q'=>$quoteId));$sections=$st->fetchAll(PDO::FETCH_ASSOC);}elseif(isset($q['sections_json'])){$sections=qxJsonArray($q['sections_json']);}
    $schedule=array();$pst=qxTableExists($pdo,'quote_payment_schedule')?'quote_payment_schedule':(qxTableExists($pdo,'quote_payment_schedules')?'quote_payment_schedules':'');if($pst){$st=$pdo->prepare("SELECT * FROM `$pst` WHERE tenant_id=:t AND quote_id=:q ORDER BY id");$st->execute(array(':t'=>$tenantId,':q'=>$quoteId));$schedule=$st->fetchAll(PDO::FETCH_ASSOC);}
    qlResponse(200,true,'Quotation loaded.',array('quotation'=>$q,'items'=>$items,'files'=>qxAttachmentRows($pdo,$tenantId,$quoteId),'sections'=>$sections,'payment_schedule'=>$schedule));
}

if ($action === 'request_quote_source') {
    $requestId=(int)qlPost('request_id',0);$rt=qxRequestTable($pdo);if($rt===''||$requestId<=0)qlResponse(404,false,'Request not found.');
    $st=$pdo->prepare("SELECT * FROM `$rt` WHERE id=:id AND tenant_id=:t LIMIT 1");$st->execute(array(':id'=>$requestId,':t'=>$tenantId));$r=$st->fetch(PDO::FETCH_ASSOC);if(!$r)qlResponse(404,false,'Request not found.');
    if(!isset($r['location_id'])&&isset($r['property_id']))$r['location_id']=$r['property_id'];
    $items=array();foreach(array('request_line_items','service_request_line_items') as $tbl){if(qxTableExists($pdo,$tbl)){$st=$pdo->prepare("SELECT * FROM `$tbl` WHERE tenant_id=:t AND request_id=:r ORDER BY id");$st->execute(array(':t'=>$tenantId,':r'=>$requestId));$items=$st->fetchAll(PDO::FETCH_ASSOC);break;}}
    qlResponse(200,true,'Request source loaded.',array('request'=>$r,'line_items'=>$items));
}

if ($action === 'create_customer') {
    $name=trim((string)qlPost('display_name',''));if($name==='')qlResponse(422,false,'Customer name is required.');
    $email=trim((string)qlPost('email',''));$phone=trim((string)qlPost('phone',''));
    if($email!==''||$phone!==''){$parts=array();$p=array(':t'=>$tenantId);if($email!==''){$parts[]='LOWER(email)=LOWER(:e)';$p[':e']=$email;}if($phone!==''){$parts[]='phone=:p';$p[':p']=$phone;}$st=$pdo->prepare('SELECT id FROM clients WHERE tenant_id=:t AND ('.implode(' OR ',$parts).') LIMIT 1');$st->execute($p);if((int)$st->fetchColumn()>0)qlResponse(409,false,'A customer with this email or phone already exists.');}
    $data=array('tenant_id'=>$tenantId,'client_type'=>'client','display_name'=>$name,'company_name'=>trim((string)qlPost('company_name','')),'email'=>$email,'phone'=>$phone,'status'=>qxEnumAllows($pdo,'clients','status','active')?'active':'new','created_by'=>$userId);
    $id=qxInsert($pdo,'clients',$data);$st=$pdo->prepare('SELECT * FROM clients WHERE id=:id AND tenant_id=:t');$st->execute(array(':id'=>$id,':t'=>$tenantId));$c=$st->fetch(PDO::FETCH_ASSOC);$c['name']=$c['display_name'];qlResponse(201,true,'Customer created successfully.',array('client'=>$c));
}

if ($action === 'create_location') {
    $clientId=(int)qlPost('client_id',0);$name=trim((string)qlPost('name',''));$a1=trim((string)qlPost('address_line1',''));if($clientId<=0||$name===''||$a1==='')qlResponse(422,false,'Customer, location name and address line 1 are required.');
    $st=$pdo->prepare('SELECT id FROM clients WHERE id=:id AND tenant_id=:t');$st->execute(array(':id'=>$clientId,':t'=>$tenantId));if(!(int)$st->fetchColumn())qlResponse(404,false,'Customer not found.');
    $lt=qxLocationTable($pdo);if($lt==='')qlResponse(500,false,'No client location table is available.');$primary=(int)qlPost('is_primary',0)?1:0;if($primary&&qlColumnExists($pdo,$lt,'is_primary'))qxUpdate($pdo,$lt,array('is_primary'=>0),'tenant_id=:t AND client_id=:c',array(':t'=>$tenantId,':c'=>$clientId));
    $id=qxInsert($pdo,$lt,array('tenant_id'=>$tenantId,'client_id'=>$clientId,'name'=>$name,'location_type'=>trim((string)qlPost('location_type','site')),'address_line1'=>$a1,'address_line2'=>trim((string)qlPost('address_line2','')),'city'=>trim((string)qlPost('city','')),'state'=>trim((string)qlPost('state','')),'postal_code'=>trim((string)qlPost('postal_code','')),'country'=>'India','is_primary'=>$primary,'status'=>'active'));
    $st=$pdo->prepare("SELECT * FROM `$lt` WHERE id=:id AND tenant_id=:t");$st->execute(array(':id'=>$id,':t'=>$tenantId));$loc=$st->fetch(PDO::FETCH_ASSOC);if(!isset($loc['location_type']))$loc['location_type']='site';qlResponse(201,true,'Location created successfully.',array('location'=>$loc));
}

if ($action === 'create_catalog_item') {
    if(!qxTableExists($pdo,'product_services'))qlResponse(500,false,'Product / service catalog table is unavailable.');
    $name=trim((string)qlPost('name',''));if($name==='')qlResponse(422,false,'Product / service name is required.');
    $type=strtolower(trim((string)qlPost('item_type','service')));if(!in_array($type,array('service','product','material','fee','discount'),true))$type='service';
    $taxId=(int)qlPost('tax_rate_id',0);$id=qxInsert($pdo,'product_services',array('tenant_id'=>$tenantId,'item_type'=>$type,'name'=>$name,'description'=>(string)qlPost('description',''),'unit_cost'=>max(0,(float)qlPost('unit_cost',0)),'unit_price'=>max(0,(float)qlPost('unit_price',0)),'tax_rate_id'=>$taxId>0?$taxId:null,'status'=>'active'));
    if(isset($_FILES['item_image'])&&$_FILES['item_image']['error']===UPLOAD_ERR_OK&&qlColumnExists($pdo,'product_services','image_path')){$u=qxUploadPath($tenantId,0,$_FILES['item_image'],array('jpg','jpeg','png','webp'),4*1024*1024);qxUpdate($pdo,'product_services',array('image_path'=>$u['path']),'id=:id AND tenant_id=:t',array(':id'=>$id,':t'=>$tenantId));}
    $st=$pdo->prepare('SELECT * FROM product_services WHERE id=:id AND tenant_id=:t');$st->execute(array(':id'=>$id,':t'=>$tenantId));$it=$st->fetch(PDO::FETCH_ASSOC);$taxRows=qxTaxRows($pdo,$tenantId);$it['tax_percent']=0;foreach($taxRows as $tr)if((int)$tr['id']===(int)(isset($it['tax_rate_id'])?$it['tax_rate_id']:0)){$it['tax_percent']=$tr['rate_percent'];break;}qlResponse(201,true,'Product / service created successfully.',array('item'=>$it,'item_kind'=>'service'));
}

if ($action === 'create_tax_rate') {
    if(!qxTableExists($pdo,'tax_rates'))qlResponse(500,false,'Tax rate table is unavailable.');$name=trim((string)qlPost('name',''));$rate=(float)qlPost('rate_percent',0);if($name===''||$rate<0||$rate>100)qlResponse(422,false,'Enter a valid tax name and rate between 0 and 100.');$def=(int)qlPost('is_default',0)?1:0;if($def&&qlColumnExists($pdo,'tax_rates','is_default'))qxUpdate($pdo,'tax_rates',array('is_default'=>0),'tenant_id=:t',array(':t'=>$tenantId));
    $id=qxInsert($pdo,'tax_rates',array('tenant_id'=>$tenantId,'name'=>$name,'tax_name'=>$name,'rate'=>$rate,'rate_percent'=>$rate,'description'=>trim((string)qlPost('description','')),'tax_type'=>'exclusive','is_default'=>$def,'is_active'=>1));
    $rows=qxTaxRows($pdo,$tenantId);$row=null;foreach($rows as $r)if((int)$r['id']===$id){$row=$r;break;}qlResponse(201,true,'Tax rate created successfully.',array('tax_rate'=>$row));
}

if ($action === 'save') {
    $quoteId=(int)qlPost('quote_id',0);$clientId=(int)qlPost('client_id',0);$title=trim((string)qlPost('title',''));$items=qxJsonArray(qlPost('items_json','[]'));if($clientId<=0||$title==='')qlResponse(422,false,'Title and customer are required.');if(!$items)qlResponse(422,false,'Add at least one product / service item.');
    $st=$pdo->prepare('SELECT id FROM clients WHERE id=:id AND tenant_id=:t');$st->execute(array(':id'=>$clientId,':t'=>$tenantId));if(!(int)$st->fetchColumn())qlResponse(404,false,'Customer not found.');
    $status=strtolower(trim((string)qlPost('status','draft')));if(!qxEnumAllows($pdo,'quotes','status',$status))$status=qxEnumAllows($pdo,'quotes','status','sent')&&$status==='internal_approval'?'draft':'draft';
    $subtotal=0;$discTotal=0;$taxTotal=0;$total=0;$normalized=array();
    foreach($items as $i=>$x){$name=trim((string)(isset($x['item_name'])?$x['item_name']:''));if($name==='')qlResponse(422,false,'Every line item needs a name.');$qty=max(0.001,(float)(isset($x['quantity'])?$x['quantity']:1));$price=max(0,(float)(isset($x['unit_price'])?$x['unit_price']:0));$disc=max(0,(float)(isset($x['discount_amount'])?$x['discount_amount']:0));$base=$qty*$price;$disc=min($base,$disc);$taxPercent=max(0,(float)(isset($x['tax_percent'])?$x['tax_percent']:0));$tax=($base-$disc)*$taxPercent/100;$line=$base-$disc+$tax;$subtotal+=$base;$discTotal+=$disc;$taxTotal+=$tax;$total+=$line;$x['_calc']=array('qty'=>$qty,'price'=>$price,'disc'=>$disc,'tax_percent'=>$taxPercent,'tax'=>$tax,'line'=>$line);$normalized[]=$x;}
    $locCol=qxQuoteLocationColumn($pdo);$locationId=(int)qlPost('location_id',0);$requestId=(int)qlPost('request_id',0);$quoteNo=trim((string)qlPost('quote_no',''));if($quoteNo===''||!(int)qlPost('quote_no_custom',0))$quoteNo=$quoteId>0?(string)(qxEnsureQuote($pdo,$tenantId,$quoteId)['quote_no']):qxNextQuoteNo($pdo,$tenantId);
    $depositRequired=(int)qlPost('deposit_required',0)?1:0;$depositType=trim((string)qlPost('deposit_type','percent'));$depositValue=max(0,(float)qlPost('deposit_value',0));$depositAmount=$depositRequired?($depositType==='percent'?$total*min(100,$depositValue)/100:min($total,$depositValue)):0;
    $data=array('tenant_id'=>$tenantId,'quote_no'=>$quoteNo,'client_id'=>$clientId,'request_id'=>$requestId>0?$requestId:null,'salesperson_id'=>(int)qlPost('salesperson_id',0)?:null,'title'=>$title,'introduction'=>(string)qlPost('introduction',''),'introduction_title'=>(string)qlPost('introduction_title',''),'status'=>$status,'subtotal'=>round($subtotal,2),'discount_total'=>round($discTotal,2),'tax_total'=>round($taxTotal,2),'total'=>round($total,2),'deposit_required'=>$depositRequired,'deposit_type'=>$depositRequired?$depositType:null,'deposit_value'=>$depositRequired?$depositValue:null,'deposit_amount'=>round($depositAmount,2),'valid_until'=>trim((string)qlPost('valid_until',''))?:null,'created_by'=>$userId,'assessment_reschedule_id'=>(int)qlPost('assessment_reschedule_id',0)?:null,'custom_fields_json'=>(string)qlPost('custom_fields_json','[]'),'client_view_options_json'=>(string)qlPost('client_view_options_json','{}'),'payment_plan_mode'=>(string)qlPost('payment_plan_mode','none'),'payment_plan_split_type'=>(string)qlPost('payment_plan_split_type','percent'),'client_message'=>(string)qlPost('client_message',''),'disclaimer'=>(string)qlPost('disclaimer',''),'internal_notes'=>(string)qlPost('internal_notes',''),'note_mentions_json'=>(string)qlPost('note_mentions_json','[]'),'link_notes_to_jobs'=>(int)qlPost('link_notes_to_jobs',0)?1:0,'link_notes_to_invoices'=>(int)qlPost('link_notes_to_invoices',0)?1:0,'sections_json'=>(string)qlPost('sections_json','[]'));
    if($locCol!=='')$data[$locCol]=$locationId>0?$locationId:null;if($status==='sent'&&qlColumnExists($pdo,'quotes','sent_at'))$data['sent_at']=date('Y-m-d H:i:s');
    try{$pdo->beginTransaction();if($quoteId>0){qxEnsureQuote($pdo,$tenantId,$quoteId);qxUpdate($pdo,'quotes',$data,'id=:id AND tenant_id=:t',array(':id'=>$quoteId,':t'=>$tenantId));}else{$quoteId=qxInsert($pdo,'quotes',$data);}if(qxTableExists($pdo,'quote_line_items')){$st=$pdo->prepare('DELETE FROM quote_line_items WHERE tenant_id=:t AND quote_id=:q');$st->execute(array(':t'=>$tenantId,':q'=>$quoteId));foreach($normalized as $i=>$x){$c=$x['_calc'];$pid=(int)(isset($x['product_service_id'])?$x['product_service_id']:0);$taxId=qxTaxIdByPercent($pdo,$tenantId,$c['tax_percent']);qxInsert($pdo,'quote_line_items',array('tenant_id'=>$tenantId,'quote_id'=>$quoteId,'product_service_id'=>$pid>0?$pid:null,'product_id'=>(int)(isset($x['product_id'])?$x['product_id']:0)?:null,'item_name'=>(string)$x['item_name'],'description'=>(string)(isset($x['description'])?$x['description']:''),'quantity'=>$c['qty'],'unit_cost'=>max(0,(float)(isset($x['unit_cost'])?$x['unit_cost']:0)),'unit_price'=>$c['price'],'markup_percent'=>max(0,(float)(isset($x['markup_percent'])?$x['markup_percent']:0)),'discount_amount'=>$c['disc'],'tax_rate_id'=>$taxId,'tax_percent'=>$c['tax_percent'],'tax_amount'=>round($c['tax'],2),'line_total'=>round($c['line'],2),'is_optional'=>!empty($x['is_optional'])?1:0,'is_selected_by_client'=>1,'sort_order'=>$i,'service_date'=>(string)(isset($x['service_date'])?$x['service_date']:''),'image_path'=>(string)(isset($x['image_path'])?$x['image_path']:'')));}}
        if(!qlColumnExists($pdo,'quotes','internal_notes')&&qxTableExists($pdo,'notes')){$st=$pdo->prepare("DELETE FROM notes WHERE tenant_id=:t AND related_type='quote_internal' AND related_id=:q");$st->execute(array(':t'=>$tenantId,':q'=>$quoteId));$note=trim((string)qlPost('internal_notes',''));if($note!=='')qxInsert($pdo,'notes',array('tenant_id'=>$tenantId,'related_type'=>'quote_internal','related_id'=>$quoteId,'user_id'=>$userId,'note'=>$note,'is_internal'=>1));}
        if($requestId>0){$rt=qxRequestTable($pdo);if($rt!==''&&qlColumnExists($pdo,$rt,'converted_quote_id'))qxUpdate($pdo,$rt,array('converted_quote_id'=>$quoteId),'id=:r AND tenant_id=:t',array(':r'=>$requestId,':t'=>$tenantId));}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('FieldPlx quotation save: '.$e->getMessage());qlResponse(500,false,'Unable to save quotation. Check the server error log for the database detail.');}
    qlResponse(200,true,$status==='draft'?'Quotation draft saved.':'Quotation saved successfully.',array('quote_id'=>$quoteId,'quote_no'=>$quoteNo,'email_status'=>'skipped','email_notice'=>$status==='sent'?'Quote saved. Automatic email sending is not configured in this API action.':''));
}

if ($action === 'upload_file' || $action === 'upload_line_image') {
    $quoteId=(int)qlPost('quote_id',0);qxEnsureQuote($pdo,$tenantId,$quoteId);if(!qxTableExists($pdo,'attachments'))qlResponse(500,false,'Attachments table is unavailable.');$isLine=$action==='upload_line_image';$field=$isLine?'file':'file';if(!isset($_FILES[$field]))qlResponse(422,false,'Choose a file to upload.');$cat=$isLine?('line_image:'.max(0,(int)qlPost('line_index',0))):trim((string)qlPost('file_category','attachment'));$imgCats=array('image','introduction_image','note_attachment');$allowed=in_array($cat,$imgCats,true)||$isLine?array('jpg','jpeg','png','gif','webp','avif','heic'):array('jpg','jpeg','png','gif','webp','avif','heic','pdf','doc','docx');$max=in_array($cat,$imgCats,true)||$isLine?25*1024*1024:50*1024*1024;
    try{$u=qxUploadPath($tenantId,$quoteId,$_FILES[$field],$allowed,$max);if($isLine){$st=$pdo->prepare("SELECT id,file_path FROM attachments WHERE tenant_id=:t AND related_type='quote' AND related_id=:q AND description=:d");$st->execute(array(':t'=>$tenantId,':q'=>$quoteId,':d'=>$cat));foreach($st->fetchAll(PDO::FETCH_ASSOC) as $old){if(!empty($old['file_path'])){@unlink(dirname(__DIR__).'/'.ltrim($old['file_path'],'/'));}$pdo->prepare('DELETE FROM attachments WHERE id=:id AND tenant_id=:t')->execute(array(':id'=>$old['id'],':t'=>$tenantId));}}
        $id=qxInsert($pdo,'attachments',array('tenant_id'=>$tenantId,'related_type'=>'quote','related_id'=>$quoteId,'uploaded_by'=>$userId,'file_name'=>$u['name'],'file_path'=>$u['path'],'file_mime'=>$u['mime'],'file_size'=>$u['size'],'attachment_type'=>($isLine||in_array($cat,array('image','introduction_image'),true))?'photo':'file','description'=>$isLine?$cat:('quote_category:'.$cat)));qlResponse(201,true,'File uploaded.',array('file'=>array('id'=>$id,'file_category'=>$cat,'original_name'=>$u['name'],'file_path'=>$u['path'])));
    }catch(Throwable $e){qlResponse(422,false,$e->getMessage());}
}

if ($action === 'delete_file') {
    $id=(int)qlPost('file_id',0);if(!qxTableExists($pdo,'attachments')||$id<=0)qlResponse(404,false,'File not found.');$st=$pdo->prepare("SELECT a.* FROM attachments a JOIN quotes q ON q.id=a.related_id AND q.tenant_id=a.tenant_id WHERE a.id=:id AND a.tenant_id=:t AND a.related_type IN ('quote','quotation') LIMIT 1");$st->execute(array(':id'=>$id,':t'=>$tenantId));$r=$st->fetch(PDO::FETCH_ASSOC);if(!$r)qlResponse(404,false,'File not found.');$pdo->prepare('DELETE FROM attachments WHERE id=:id AND tenant_id=:t')->execute(array(':id'=>$id,':t'=>$tenantId));if(!empty($r['file_path']))@unlink(dirname(__DIR__).'/'.ltrim($r['file_path'],'/'));qlResponse(200,true,'File removed.');
}

if ($action === 'delete_line_image') {
    $quoteId=(int)qlPost('quote_id',0);qxEnsureQuote($pdo,$tenantId,$quoteId);$idx=max(0,(int)qlPost('line_index',0));if(qxTableExists($pdo,'attachments')){$st=$pdo->prepare("SELECT id,file_path FROM attachments WHERE tenant_id=:t AND related_type='quote' AND related_id=:q AND description=:d");$st->execute(array(':t'=>$tenantId,':q'=>$quoteId,':d'=>'line_image:'.$idx));foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$pdo->prepare('DELETE FROM attachments WHERE id=:id AND tenant_id=:t')->execute(array(':id'=>$r['id'],':t'=>$tenantId));if(!empty($r['file_path']))@unlink(dirname(__DIR__).'/'.ltrim($r['file_path'],'/'));}}qlResponse(200,true,'Line item image removed.');
}

if ($action !== 'list') {
    qlResponse(400, false, 'Unsupported quotation list action.');
}

try {
    $page = max(1, (int)qlPost('page', 1));
    $perPage = (int)qlPost('per_page', 25);
    if (!in_array($perPage, array(10, 25, 50), true)) {
        $perPage = 25;
    }

    $search = trim((string)qlPost('search', ''));
    $status = strtolower(trim((string)qlPost('status', '')));
    $fromDate = trim((string)qlPost('from_date', ''));
    $toDate = trim((string)qlPost('to_date', ''));
    $sort = strtolower(trim((string)qlPost('sort', 'created')));
    $direction = strtolower(trim((string)qlPost('direction', 'desc')));

    $allowedStatuses = array(
        'draft',
        'internal_approval',
        'awaiting_response',
        'sent',
        'viewed',
        'changes_requested',
        'approved',
        'rejected',
        'expired',
        'converted',
        'archived'
    );

    if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
        qlResponse(422, false, 'Select a valid quotation status.');
    }

    if (!qlValidDate($fromDate) || !qlValidDate($toDate)) {
        qlResponse(422, false, 'Select a valid date range.');
    }

    if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
        qlResponse(422, false, 'From date cannot be later than To date.');
    }

    $sortMap = array(
        'customer' => 'c.display_name',
        'quote_number' => 'q.id',
        'created' => 'q.created_at',
        'status' => 'q.status',
        'total' => 'q.total'
    );
    if (!isset($sortMap[$sort])) {
        $sort = 'created';
    }
    if (!in_array($direction, array('asc', 'desc'), true)) {
        $direction = 'desc';
    }

    $where = array('q.tenant_id = :tenant_id');
    $params = array(':tenant_id' => $tenantId);

    if ($search !== '') {
        $searchValue = '%' . $search . '%';
        $where[] = "(
            q.quote_no LIKE :search_quote
            OR COALESCE(r.request_no, '') LIKE :search_request
            OR COALESCE(c.display_name, '') LIKE :search_client
            OR COALESCE(q.title, '') LIKE :search_title
            OR COALESCE(cl.name, '') LIKE :search_location
            OR COALESCE(cl.address_line1, '') LIKE :search_address1
            OR COALESCE(cl.address_line2, '') LIKE :search_address2
            OR COALESCE(cl.city, '') LIKE :search_city
            OR COALESCE(cl.state, '') LIKE :search_state
            OR COALESCE(cl.postal_code, '') LIKE :search_postal
        )";
        $params[':search_quote'] = $searchValue;
        $params[':search_request'] = $searchValue;
        $params[':search_client'] = $searchValue;
        $params[':search_title'] = $searchValue;
        $params[':search_location'] = $searchValue;
        $params[':search_address1'] = $searchValue;
        $params[':search_address2'] = $searchValue;
        $params[':search_city'] = $searchValue;
        $params[':search_state'] = $searchValue;
        $params[':search_postal'] = $searchValue;
    }

    if ($status !== '') {
        if ($status === 'awaiting_response') {
            $where[] = "q.status IN ('sent', 'viewed')";
        } elseif ($status === 'converted') {
            $where[] = "(
                q.status = 'converted'
                OR EXISTS (
                    SELECT 1
                    FROM jobs j_filter
                    WHERE j_filter.tenant_id = q.tenant_id
                      AND j_filter.quote_id = q.id
                      AND j_filter.deleted_at IS NULL
                      AND j_filter.status NOT IN ('cancelled', 'archived')
                )
            )";
        } else {
            $where[] = 'q.status = :status';
            $params[':status'] = $status;
        }
    }

    if ($fromDate !== '') {
        $where[] = 'DATE(q.created_at) >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $where[] = 'DATE(q.created_at) <= :to_date';
        $params[':to_date'] = $toDate;
    }

    $whereSql = implode(' AND ', $where);
    $baseJoins = "
        LEFT JOIN service_requests r
          ON r.id = q.request_id
         AND r.tenant_id = q.tenant_id
        INNER JOIN clients c
          ON c.id = q.client_id
         AND c.tenant_id = q.tenant_id
        LEFT JOIN client_locations cl
          ON cl.id = q.location_id
         AND cl.tenant_id = q.tenant_id
         AND cl.client_id = q.client_id
    ";

    $countSql = "SELECT COUNT(*) FROM quotes q " . $baseJoins . " WHERE " . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }

    $offset = ($page - 1) * $perPage;
    $hasRevisitColumn = qlColumnExists($pdo, 'quotes', 'assessment_reschedule_id');

    if ($hasRevisitColumn) {
        $sourceSelect = ",
            q.assessment_reschedule_id,
            CASE
                WHEN q.request_id IS NULL THEN 'Direct Quotation'
                WHEN q.assessment_reschedule_id IS NULL THEN 'Original Enquiry'
                ELSE CONCAT('Revisit #', q.assessment_reschedule_id)
            END AS quotation_source";
    } else {
        $sourceSelect = ",
            CASE
                WHEN q.request_id IS NULL THEN 'Direct Quotation'
                ELSE 'Original Enquiry'
            END AS quotation_source";
    }

    $orderSql = $sortMap[$sort] . ' ' . strtoupper($direction) . ', q.id ' . strtoupper($direction);

    $listSql =
        "SELECT
            q.id,
            q.quote_no,
            q.revision_no,
            q.request_id,
            q.client_id,
            q.location_id,
            q.title,
            q.status,
            q.subtotal,
            q.discount_total,
            q.tax_total,
            q.total,
            q.valid_until,
            q.sent_at,
            q.approved_at,
            q.created_at,
            q.updated_at,
            DATE_FORMAT(q.created_at, '%d-%m-%Y') AS created_date,
            r.request_no,
            c.display_name AS client_name,
            c.phone AS client_phone,
            c.email AS client_email,
            cl.name AS location_name,
            cl.address_line1 AS location_address1,
            cl.address_line2 AS location_address2,
            cl.city AS location_city,
            cl.state AS location_state,
            cl.postal_code AS location_postal_code,
            (
                SELECT j.id
                FROM jobs j
                WHERE j.tenant_id = q.tenant_id
                  AND j.quote_id = q.id
                  AND j.deleted_at IS NULL
                  AND j.status NOT IN ('cancelled', 'archived')
                ORDER BY j.id DESC
                LIMIT 1
            ) AS linked_job_id,
            (
                SELECT j.job_no
                FROM jobs j
                WHERE j.tenant_id = q.tenant_id
                  AND j.quote_id = q.id
                  AND j.deleted_at IS NULL
                  AND j.status NOT IN ('cancelled', 'archived')
                ORDER BY j.id DESC
                LIMIT 1
            ) AS linked_job_no
            " . $sourceSelect . "
         FROM quotes q
         " . $baseJoins . "
         WHERE " . $whereSql . "
         ORDER BY " . $orderSql . "
         LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $quotations = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $summaryStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(q.status = 'draft'), 0) AS draft,
            COALESCE(SUM(q.status = 'internal_approval'), 0) AS internal_approval,
            COALESCE(SUM(q.status IN ('sent','viewed')), 0) AS awaiting_response,
            COALESCE(SUM(q.status = 'sent'), 0) AS sent,
            COALESCE(SUM(q.status = 'viewed'), 0) AS viewed,
            COALESCE(SUM(q.status = 'changes_requested'), 0) AS changes_requested,
            COALESCE(SUM(q.status = 'approved'), 0) AS approved,
            COALESCE(SUM(q.status = 'rejected'), 0) AS rejected,
            COALESCE(SUM(q.status = 'expired'), 0) AS expired,
            COALESCE(SUM(q.status = 'archived'), 0) AS archived,
            COALESCE(SUM(
                q.status = 'converted'
                OR EXISTS (
                    SELECT 1
                    FROM jobs j_status
                    WHERE j_status.tenant_id = q.tenant_id
                      AND j_status.quote_id = q.id
                      AND j_status.deleted_at IS NULL
                      AND j_status.status NOT IN ('cancelled', 'archived')
                )
            ), 0) AS converted
         FROM quotes q
         WHERE q.tenant_id = :tenant_id"
    );
    $summaryStmt->execute(array(':tenant_id' => $tenantId));
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();

    $statusCounts = array(
        'total' => (int)(isset($summaryRow['total']) ? $summaryRow['total'] : 0),
        'draft' => (int)(isset($summaryRow['draft']) ? $summaryRow['draft'] : 0),
        'internal_approval' => (int)(isset($summaryRow['internal_approval']) ? $summaryRow['internal_approval'] : 0),
        'awaiting_response' => (int)(isset($summaryRow['awaiting_response']) ? $summaryRow['awaiting_response'] : 0),
        'sent' => (int)(isset($summaryRow['sent']) ? $summaryRow['sent'] : 0),
        'viewed' => (int)(isset($summaryRow['viewed']) ? $summaryRow['viewed'] : 0),
        'changes_requested' => (int)(isset($summaryRow['changes_requested']) ? $summaryRow['changes_requested'] : 0),
        'approved' => (int)(isset($summaryRow['approved']) ? $summaryRow['approved'] : 0),
        'rejected' => (int)(isset($summaryRow['rejected']) ? $summaryRow['rejected'] : 0),
        'expired' => (int)(isset($summaryRow['expired']) ? $summaryRow['expired'] : 0),
        'archived' => (int)(isset($summaryRow['archived']) ? $summaryRow['archived'] : 0),
        'converted' => (int)(isset($summaryRow['converted']) ? $summaryRow['converted'] : 0)
    );

    /* Old response keys are kept for compatibility with older quotation pages. */
    $summary = array_merge($statusCounts, array(
        'sent_viewed' => $statusCounts['awaiting_response']
    ));

    qlResponse(200, true, 'Quotations loaded successfully.', array(
        'quotations' => $quotations,
        'summary' => $summary,
        'status_counts' => $statusCounts,
        'currency' => qlCurrency($pdo, $tenantId, $branchId),
        'pagination' => array(
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'from' => $total > 0 ? ($offset + 1) : 0,
            'to' => $total > 0 ? min($offset + $perPage, $total) : 0
        )
    ));
} catch (PDOException $e) {
    error_log('FieldPlx quotation-list PDO: ' . $e->getMessage());
    qlResponse(500, false, 'Unable to load quotations.');
} catch (Throwable $e) {
    error_log('FieldPlx quotation-list: ' . $e->getMessage());
    qlResponse(500, false, 'Unable to load quotations.');
}
