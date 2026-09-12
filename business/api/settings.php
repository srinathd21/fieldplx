<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

function fsa_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}
function fsa_bool($key)
{
    return isset($_POST[$key]) && (string)$_POST[$key] === '1' ? 1 : 0;
}
function fsa_json($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('success'=>(bool)$success,'message'=>(string)$message),$extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function fsa_table_exists(PDO $pdo, $table)
{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t'=>$table));
    return (int)$stmt->fetchColumn()>0;
}
function fsa_column_exists(PDO $pdo, $table, $column)
{
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $stmt->execute(array(':t'=>$table,':c'=>$column));
    return (int)$stmt->fetchColumn()>0;
}
function fsa_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $newValues)
{
    if (function_exists('tenantAuditLog')) {
        tenantAuditLog($pdo,$action,$tenantId,$branchId>0?$branchId:null,$userId,'company_setting',$tenantId,null,$newValues);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fsa_json(405,false,'Method not allowed.');

$tenantId=(int)$currentTenantId;
$userId=(int)$currentTenantUserId;
$branchId=(int)$currentBranchId;
$csrf=fsa_post('csrf_token');
if (empty($_SESSION['company_settings_csrf']) || !is_string($_SESSION['company_settings_csrf']) || $csrf==='' || !hash_equals($_SESSION['company_settings_csrf'],$csrf)) {
    fsa_json(419,false,'Your form session expired. Refresh the page and try again.');
}

$action=fsa_post('action');

try {
    if ($action === 'save_company_settings') {
        $required=array('tax_id_name','time_format','first_day_of_week','show_business_hours','allow_public_discovery','address_private');
        foreach ($required as $col) {
            if (!fsa_column_exists($pdo,'tenants',$col)) fsa_json(500,false,'Company settings migration has not been completed.');
        }
        if (!fsa_table_exists($pdo,'tenant_business_hours')) fsa_json(500,false,'Company settings migration has not been completed.');

        $displayName=fsa_post('display_name');
        $phone=fsa_post('phone');
        $email=fsa_post('email');
        $websiteUrl=fsa_post('website_url');
        $address1=fsa_post('address_line1');
        $address2=fsa_post('address_line2');
        $city=fsa_post('city');
        $state=fsa_post('state');
        $postalCode=fsa_post('postal_code');
        $countryId=(int)fsa_post('country_id','0');
        $timezone=fsa_post('timezone','UTC');
        $dateFormat=fsa_post('date_format','d-m-Y');
        $timeFormat=fsa_post('time_format','12');
        $firstDay=fsa_post('first_day_of_week','sunday');
        $taxIdName=fsa_post('tax_id_name');
        $taxNumber=fsa_post('tax_number');
        $showBusinessHours=fsa_bool('show_business_hours');
        $allowPublicDiscovery=fsa_bool('allow_public_discovery');
        $addressPrivate=fsa_bool('address_private');

        if ($displayName==='') fsa_json(422,false,'Company name is required.');
        if ($email!=='' && filter_var($email,FILTER_VALIDATE_EMAIL)===false) fsa_json(422,false,'Enter a valid email address.');
        if ($websiteUrl!=='' && filter_var($websiteUrl,FILTER_VALIDATE_URL)===false) fsa_json(422,false,'Enter a valid website URL including http:// or https://.');
        if (!in_array($timezone,timezone_identifiers_list(),true)) fsa_json(422,false,'Select a valid timezone.');
        if (!in_array($dateFormat,array('d-m-Y','m-d-Y','Y-m-d','d/m/Y','m/d/Y','M j, Y'),true)) $dateFormat='d-m-Y';
        if (!in_array($timeFormat,array('12','24'),true)) $timeFormat='12';
        if (!in_array($firstDay,array('sunday','monday','saturday'),true)) $firstDay='sunday';

        $pdo->beginTransaction();
        $stmt=$pdo->prepare("UPDATE tenants SET display_name=:display_name,phone=:phone,email=:email,website_url=:website_url,address_line1=:address_line1,address_line2=:address_line2,city=:city,state=:state,postal_code=:postal_code,country_id=:country_id,timezone=:timezone,date_format=:date_format,time_format=:time_format,first_day_of_week=:first_day,tax_id_name=:tax_id_name,tax_number=:tax_number,show_business_hours=:show_business_hours,allow_public_discovery=:allow_public_discovery,address_private=:address_private WHERE id=:tenant_id AND deleted_at IS NULL");
        $stmt->execute(array(
            ':display_name'=>$displayName, ':phone'=>$phone!==''?$phone:null, ':email'=>$email!==''?$email:null,
            ':website_url'=>$websiteUrl!==''?$websiteUrl:null, ':address_line1'=>$address1!==''?$address1:null,
            ':address_line2'=>$address2!==''?$address2:null, ':city'=>$city!==''?$city:null, ':state'=>$state!==''?$state:null,
            ':postal_code'=>$postalCode!==''?$postalCode:null, ':country_id'=>$countryId>0?$countryId:null,
            ':timezone'=>$timezone, ':date_format'=>$dateFormat, ':time_format'=>$timeFormat, ':first_day'=>$firstDay,
            ':tax_id_name'=>$taxIdName!==''?$taxIdName:null, ':tax_number'=>$taxNumber!==''?$taxNumber:null,
            ':show_business_hours'=>$showBusinessHours, ':allow_public_discovery'=>$allowPublicDiscovery,
            ':address_private'=>$addressPrivate, ':tenant_id'=>$tenantId
        ));

        $days=array('sunday','monday','tuesday','wednesday','thursday','friday','saturday');
        $up=$pdo->prepare("INSERT INTO tenant_business_hours (tenant_id,day_of_week,is_closed,open_time,close_time,created_at,updated_at) VALUES (:tenant_id,:day,:closed,:open_time,:close_time,NOW(),NOW()) ON DUPLICATE KEY UPDATE is_closed=VALUES(is_closed),open_time=VALUES(open_time),close_time=VALUES(close_time),updated_at=NOW()");
        foreach($days as $day){
            $closed=isset($_POST['hours'][$day]['closed']) && (string)$_POST['hours'][$day]['closed']==='1' ? 1 : 0;
            $open=isset($_POST['hours'][$day]['open'])?trim((string)$_POST['hours'][$day]['open']):'';
            $close=isset($_POST['hours'][$day]['close'])?trim((string)$_POST['hours'][$day]['close']):'';
            if($closed){$open='';$close='';}
            $up->execute(array(':tenant_id'=>$tenantId,':day'=>$day,':closed'=>$closed,':open_time'=>$open!==''?$open.':00':null,':close_time'=>$close!==''?$close.':00':null));
        }
        $pdo->commit();

        $_SESSION['tenant_name']=$displayName;
        $_SESSION['tenant_timezone']=$timezone;
        $_SESSION['effective_timezone']=$timezone;
        $_SESSION['tenant_date_format']=$dateFormat;
        fsa_audit($pdo,$tenantId,$branchId,$userId,'COMPANY_SETTINGS_UPDATED',array('display_name'=>$displayName,'timezone'=>$timezone,'date_format'=>$dateFormat));
        fsa_json(200,true,'Company settings updated successfully.');
    }

    if ($action === 'save_tax_rate') {
        $taxName=fsa_post('tax_name');
        $rate=fsa_post('rate_percent');
        $description=fsa_post('tax_description');
        $makeDefault=fsa_bool('is_default');
        $taxId=(int)fsa_post('tax_rate_id','0');
        if($taxName==='') fsa_json(422,false,'Tax name is required.');
        if($rate==='' || !is_numeric($rate) || (float)$rate<0 || (float)$rate>100) fsa_json(422,false,'Tax rate must be between 0 and 100.');

        $countryStmt=$pdo->prepare("SELECT c.iso2 FROM tenants t LEFT JOIN countries c ON c.id=t.country_id WHERE t.id=:tenant_id LIMIT 1");
        $countryStmt->execute(array(':tenant_id'=>$tenantId));
        $countryCode=strtoupper((string)$countryStmt->fetchColumn());
        if($countryCode==='') $countryCode='IN';

        $pdo->beginTransaction();
        if($makeDefault){$q=$pdo->prepare("UPDATE product_tax_rates SET is_default=0 WHERE tenant_id=:tenant_id");$q->execute(array(':tenant_id'=>$tenantId));}
        if($taxId>0){
            $q=$pdo->prepare("UPDATE product_tax_rates SET tax_name=:tax_name,rate_percent=:rate_percent,description=:description,is_default=:is_default,status='active',country_code=:country_code WHERE id=:id AND tenant_id=:tenant_id");
            $q->execute(array(':tax_name'=>$taxName,':rate_percent'=>(float)$rate,':description'=>$description!==''?$description:null,':is_default'=>$makeDefault,':country_code'=>substr($countryCode,0,2),':id'=>$taxId,':tenant_id'=>$tenantId));
        } else {
            $q=$pdo->prepare("INSERT INTO product_tax_rates (tenant_id,tax_name,rate_percent,country_code,state_code,tax_type,description,status,is_default,created_by,created_at) VALUES (:tenant_id,:tax_name,:rate_percent,:country_code,'','other',:description,'active',:is_default,:created_by,NOW())");
            $q->execute(array(':tenant_id'=>$tenantId,':tax_name'=>$taxName,':rate_percent'=>(float)$rate,':country_code'=>substr($countryCode,0,2),':description'=>$description!==''?$description:null,':is_default'=>$makeDefault,':created_by'=>$userId));
            $taxId=(int)$pdo->lastInsertId();
        }
        $pdo->commit();
        fsa_audit($pdo,$tenantId,$branchId,$userId,'TAX_RATE_SAVED',array('tax_rate_id'=>$taxId,'tax_name'=>$taxName,'rate_percent'=>(float)$rate,'is_default'=>$makeDefault));
        fsa_json(200,true,'Tax rate saved successfully.',array('tax_rate_id'=>$taxId));
    }

    if ($action === 'delete_tax_rate') {
        $taxId=(int)fsa_post('tax_rate_id','0');
        if($taxId<=0) fsa_json(422,false,'Invalid tax rate.');
        $q=$pdo->prepare("UPDATE product_tax_rates SET status='inactive',is_default=0 WHERE id=:id AND tenant_id=:tenant_id");
        $q->execute(array(':id'=>$taxId,':tenant_id'=>$tenantId));
        fsa_audit($pdo,$tenantId,$branchId,$userId,'TAX_RATE_DISABLED',array('tax_rate_id'=>$taxId));
        fsa_json(200,true,'Tax rate removed successfully.');
    }

    fsa_json(400,false,'Invalid settings action.');
} catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx company settings API error: '.$e->getMessage());
    fsa_json(500,false,'Unable to complete the settings action. Please try again.');
}
