<?php
declare(strict_types=1);
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

function bp_post($key,$default=''){
    if(!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}
function bp_bool($key){ return isset($_POST[$key]) && (string)$_POST[$key]==='1' ? 1 : 0; }
function bp_json($status,$success,$message,$extra=array()){
    while(ob_get_level()>0) @ob_end_clean();
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('success'=>(bool)$success,'message'=>(string)$message),$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function bp_table_exists(PDO $pdo,$table){
    $s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(array(':t'=>$table)); return (int)$s->fetchColumn()>0;
}
function bp_url_or_null($value){
    $value=trim((string)$value);
    if($value==='') return null;
    if(filter_var($value,FILTER_VALIDATE_URL)===false) bp_json(422,false,'Enter a valid URL including http:// or https://.');
    return $value;
}
function bp_audit(PDO $pdo,$tenantId,$branchId,$userId,$action,$values){
    if(function_exists('tenantAuditLog')) tenantAuditLog($pdo,$action,$tenantId,$branchId>0?$branchId:null,$userId,'business_profile',$tenantId,null,$values);
}
if($_SERVER['REQUEST_METHOD']!=='POST') bp_json(405,false,'Method not allowed.');
$tenantId=(int)$currentTenantId; $userId=(int)$currentTenantUserId; $branchId=(int)$currentBranchId;
$csrf=bp_post('csrf_token');
if(empty($_SESSION['business_profile_csrf']) || !is_string($_SESSION['business_profile_csrf']) || $csrf==='' || !hash_equals($_SESSION['business_profile_csrf'],$csrf)) bp_json(419,false,'Your form session expired. Refresh the page and try again.');
if(!bp_table_exists($pdo,'tenant_business_profiles') || !bp_table_exists($pdo,'tenant_document_settings')) bp_json(500,false,'Run the Business Profile migration before using this page.');
$action=bp_post('action');
try{
    if($action==='save_essential'){
        $about=bp_post('about_text'); $policies=bp_post('policies_text'); $services=bp_post('services_text'); $tone=bp_post('tone_guide_text');
        if(mb_strlen($about)>1000 || mb_strlen($policies)>1000 || mb_strlen($services)>1000 || mb_strlen($tone)>1000) bp_json(422,false,'Each Essential information field can contain up to 1000 characters.');
        $hasTone=bp_table_exists($pdo,'tenant_business_profiles') && (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenant_business_profiles' AND COLUMN_NAME='tone_guide_text'")->fetchColumn()>0;
        if($hasTone){
            $q=$pdo->prepare("INSERT INTO tenant_business_profiles (tenant_id,about_text,policies_text,services_text,tone_guide_text) VALUES (:tenant_id,:about,:policies,:services,:tone) ON DUPLICATE KEY UPDATE about_text=VALUES(about_text),policies_text=VALUES(policies_text),services_text=VALUES(services_text),tone_guide_text=VALUES(tone_guide_text),updated_at=NOW()");
            $q->execute(array(':tenant_id'=>$tenantId,':about'=>$about!==''?$about:null,':policies'=>$policies!==''?$policies:null,':services'=>$services!==''?$services:null,':tone'=>$tone!==''?$tone:null));
        }else{
            $q=$pdo->prepare("INSERT INTO tenant_business_profiles (tenant_id,about_text,policies_text,services_text) VALUES (:tenant_id,:about,:policies,:services) ON DUPLICATE KEY UPDATE about_text=VALUES(about_text),policies_text=VALUES(policies_text),services_text=VALUES(services_text),updated_at=NOW()");
            $q->execute(array(':tenant_id'=>$tenantId,':about'=>$about!==''?$about:null,':policies'=>$policies!==''?$policies:null,':services'=>$services!==''?$services:null));
        }
        bp_audit($pdo,$tenantId,$branchId,$userId,'BUSINESS_PROFILE_ESSENTIAL_UPDATED',array('about_length'=>mb_strlen($about),'policies_length'=>mb_strlen($policies),'services_length'=>mb_strlen($services),'tone_length'=>mb_strlen($tone)));
        bp_json(200,true,'Business profile information saved.');
    }
    if($action==='save_legal'){
        $type=bp_post('legal_type'); $url=bp_url_or_null(bp_post('url'));
        if(!in_array($type,array('terms','privacy'),true)) bp_json(422,false,'Invalid legal information type.');
        $column=$type==='terms'?'terms_url':'privacy_url';
        $sql="INSERT INTO tenant_business_profiles (tenant_id,`$column`) VALUES (:tenant_id,:url) ON DUPLICATE KEY UPDATE `$column`=VALUES(`$column`),updated_at=NOW()";
        $q=$pdo->prepare($sql); $q->execute(array(':tenant_id'=>$tenantId,':url'=>$url));
        bp_audit($pdo,$tenantId,$branchId,$userId,'BUSINESS_PROFILE_LEGAL_UPDATED',array('type'=>$type,'url'=>$url));
        bp_json(200,true,$type==='terms'?'Terms and Conditions link saved.':'Privacy Policy link saved.',array('url'=>$url));
    }
    if($action==='save_brand_colors'){
        $main=strtoupper(bp_post('main_brand_color','#001131')); $accent=strtoupper(bp_post('accent_color','#74B824'));
        if(!preg_match('/^#[0-9A-F]{6}$/',$main) || !preg_match('/^#[0-9A-F]{6}$/',$accent)) bp_json(422,false,'Enter valid 6-digit HEX colors.');
        $q=$pdo->prepare("INSERT INTO tenant_business_profiles (tenant_id,main_brand_color,accent_color) VALUES (:tenant_id,:main,:accent) ON DUPLICATE KEY UPDATE main_brand_color=VALUES(main_brand_color),accent_color=VALUES(accent_color),updated_at=NOW()");
        $q->execute(array(':tenant_id'=>$tenantId,':main'=>$main,':accent'=>$accent));
        bp_audit($pdo,$tenantId,$branchId,$userId,'BUSINESS_PROFILE_COLORS_UPDATED',array('main_brand_color'=>$main,'accent_color'=>$accent));
        bp_json(200,true,'Brand colors saved.',array('main_brand_color'=>$main,'accent_color'=>$accent));
    }
    if($action==='upload_logo'){
        if(!isset($_FILES['logo']) || !is_array($_FILES['logo']) || (int)$_FILES['logo']['error']!==UPLOAD_ERR_OK) bp_json(422,false,'Select a logo image to upload.');
        if((int)$_FILES['logo']['size']>5*1024*1024) bp_json(422,false,'Logo must be 5 MB or smaller.');
        $tmp=(string)$_FILES['logo']['tmp_name'];
        $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=(string)$finfo->file($tmp);
        $map=array('image/png'=>'png','image/jpeg'=>'jpg');
        if(!isset($map[$mime])) bp_json(422,false,'Logo must be PNG, JPG or JPEG.');
        $root=dirname(__DIR__).'/uploads/tenant/'.$tenantId.'/branding';
        if(!is_dir($root) && !@mkdir($root,0775,true)) bp_json(500,false,'Unable to create the branding upload folder.');
        $name='logo-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$map[$mime];
        if(!move_uploaded_file($tmp,$root.'/'.$name)) bp_json(500,false,'Unable to save the uploaded logo.');
        $publicPath='uploads/tenant/'.$tenantId.'/branding/'.$name;
        $oldLogo='';
        try{$oq=$pdo->prepare("SELECT logo_path FROM tenant_business_profiles WHERE tenant_id=:tenant_id LIMIT 1");$oq->execute(array(':tenant_id'=>$tenantId));$oldLogo=(string)$oq->fetchColumn();}catch(Throwable $ignore){}
        $q=$pdo->prepare("INSERT INTO tenant_business_profiles (tenant_id,logo_path) VALUES (:tenant_id,:logo_path) ON DUPLICATE KEY UPDATE logo_path=VALUES(logo_path),updated_at=NOW()");
        $q->execute(array(':tenant_id'=>$tenantId,':logo_path'=>$publicPath));
        if($oldLogo!=='' && $oldLogo!==$publicPath){
            $currentBase=realpath(dirname(__DIR__));
            $oldCurrent=realpath(dirname(__DIR__).'/'.$oldLogo);
            if($currentBase && $oldCurrent && strpos($oldCurrent,$currentBase.DIRECTORY_SEPARATOR)===0 && is_file($oldCurrent)) @unlink($oldCurrent);
        }
        bp_audit($pdo,$tenantId,$branchId,$userId,'BUSINESS_PROFILE_LOGO_UPDATED',array('logo_path'=>$publicPath));
        bp_json(200,true,'Logo uploaded successfully.',array('logo_path'=>$publicPath));
    }
    if($action==='remove_logo'){
        $q=$pdo->prepare("SELECT logo_path FROM tenant_business_profiles WHERE tenant_id=:tenant_id LIMIT 1"); $q->execute(array(':tenant_id'=>$tenantId)); $old=(string)$q->fetchColumn();
        $q=$pdo->prepare("UPDATE tenant_business_profiles SET logo_path=NULL,updated_at=NOW() WHERE tenant_id=:tenant_id"); $q->execute(array(':tenant_id'=>$tenantId));
        if($old!==''){
            $currentBase=realpath(dirname(__DIR__));
            $file=realpath(dirname(__DIR__).'/'.$old);
            if($currentBase && $file && strpos($file,$currentBase.DIRECTORY_SEPARATOR)===0 && is_file($file)) @unlink($file);
            else {
                // Backward-compatible cleanup for logos saved by older builds in ../uploads.
                $legacyBase=realpath(dirname(__DIR__,2)); $legacyFile=realpath(dirname(__DIR__,2).'/'.$old);
                if($legacyBase && $legacyFile && strpos($legacyFile,$legacyBase.DIRECTORY_SEPARATOR)===0 && is_file($legacyFile)) @unlink($legacyFile);
            }
        }
        bp_json(200,true,'Logo removed.');
    }
    if($action==='save_social'){
        $fields=array('facebook_url','x_url','instagram_url','yelp_url','angi_url','google_business_url'); $vals=array(':tenant_id'=>$tenantId);
        foreach($fields as $f) $vals[':'.$f]=bp_url_or_null(bp_post($f));
        $q=$pdo->prepare("INSERT INTO tenant_business_profiles (tenant_id,facebook_url,x_url,instagram_url,yelp_url,angi_url,google_business_url) VALUES (:tenant_id,:facebook_url,:x_url,:instagram_url,:yelp_url,:angi_url,:google_business_url) ON DUPLICATE KEY UPDATE facebook_url=VALUES(facebook_url),x_url=VALUES(x_url),instagram_url=VALUES(instagram_url),yelp_url=VALUES(yelp_url),angi_url=VALUES(angi_url),google_business_url=VALUES(google_business_url),updated_at=NOW()");
        $q->execute($vals); bp_audit($pdo,$tenantId,$branchId,$userId,'BUSINESS_PROFILE_SOCIAL_UPDATED',$vals); bp_json(200,true,'Social network links saved.');
    }
    if($action==='save_document_settings'){
        $type=bp_post('document_type'); if(!in_array($type,array('quote','job','invoice','style'),true)) bp_json(422,false,'Invalid document settings type.');
        $allowedFields=array(
            'quote_label'=>bp_post('quote_label','Quote'),
            'show_qty'=>bp_bool('show_qty'),'show_unit_price'=>bp_bool('show_unit_price'),'show_line_total'=>bp_bool('show_line_total'),'show_totals_tax_footer'=>bp_bool('show_totals_tax_footer'),'show_client_signature_line'=>bp_bool('show_client_signature_line'),
            'contract_disclaimer'=>bp_post('contract_disclaimer'),'deposit_language'=>bp_post('deposit_language'),
            'include_return_payment_stub'=>bp_bool('include_return_payment_stub'),'show_late_stamp'=>bp_bool('show_late_stamp'),'show_account_balance'=>bp_bool('show_account_balance'),'show_paid_date'=>bp_bool('show_paid_date'),
            'header_layout'=>bp_post('header_layout','basic'),'header_style'=>bp_post('header_style','modern'),'logo_size'=>bp_post('logo_size','small'),'theme_color'=>bp_post('theme_color','default'),'footer_font_size'=>(int)bp_post('footer_font_size','9'),
            'show_company_name'=>bp_bool('show_company_name'),'show_company_phone'=>bp_bool('show_company_phone'),'show_company_email'=>bp_bool('show_company_email'),'show_company_website'=>bp_bool('show_company_website'),'show_client_phone'=>bp_bool('show_client_phone')
        );
        if(!in_array($allowedFields['header_layout'],array('basic','compact','envelope_dual','envelope_single'),true)) $allowedFields['header_layout']='basic';
        if(!in_array($allowedFields['header_style'],array('modern','clean'),true)) $allowedFields['header_style']='modern';
        if(!in_array($allowedFields['logo_size'],array('small','medium','large'),true)) $allowedFields['logo_size']='medium';
        if(!in_array($allowedFields['theme_color'],array('default','blue','red','green','orange','purple'),true)) $allowedFields['theme_color']='default';
        if($allowedFields['footer_font_size']<6 || $allowedFields['footer_font_size']>10) $allowedFields['footer_font_size']=9;
        $selected=isset($_POST['selected_fields']) && is_array($_POST['selected_fields']) ? array_values(array_filter(array_map('trim',$_POST['selected_fields']))) : array();
        $allowedFields['selected_fields_json']=json_encode($selected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $columns=array_keys($allowedFields); $insertCols=array('tenant_id','document_type'); $insertVals=array(':tenant_id',':document_type'); $updates=array(); $params=array(':tenant_id'=>$tenantId,':document_type'=>$type);
        foreach($columns as $c){$insertCols[]='`'.$c.'`';$insertVals[]=':'.$c;$updates[]='`'.$c.'`=VALUES(`'.$c.'`)';$params[':'.$c]=$allowedFields[$c];}
        $sql='INSERT INTO tenant_document_settings ('.implode(',',$insertCols).') VALUES ('.implode(',',$insertVals).') ON DUPLICATE KEY UPDATE '.implode(',',$updates).',updated_at=NOW()';
        $q=$pdo->prepare($sql); $q->execute($params);
        bp_audit($pdo,$tenantId,$branchId,$userId,'DOCUMENT_SETTINGS_UPDATED',array('document_type'=>$type));
        bp_json(200,true,ucfirst($type).' document settings saved.');
    }
    bp_json(400,false,'Invalid Business Profile action.');
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    error_log('FieldPlx Business Profile API error: '.$e->getMessage());
    bp_json(500,false,'Unable to complete the Business Profile action. Please try again.');
}
