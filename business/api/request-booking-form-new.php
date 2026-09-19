<?php
ob_start();
ini_set('display_errors','0');ini_set('html_errors','0');ini_set('log_errors','1');
require_once __DIR__ . '/request-booking-common.php';
list($tenantId,$userId)=rbRequireAuth();rbRequireCsrf();
try{
    if(!rbSchemaReady($pdo))rbApiRespond(500,false,'Run the request-booking Jobber forms SQL migration first.',array('code'=>'schema_missing'));
    rbEnsureSystemForms($pdo,$tenantId,$userId);
    $type=trim((string)rbPost('form_type','request'));
    if(!in_array($type,array('request','job_booking','assessment_booking'),true))rbApiRespond(422,false,'Select a valid form type.');
    $name=trim((string)rbPost('name',''));
    if($name==='')rbApiRespond(422,false,'Form title is required.');
    if(strlen($name)>190)rbApiRespond(422,false,'Form title is too long.');
    $description=trim((string)rbPost('description',''));
    $formPages=(int)rbPost('form_pages',1)===1;
    $created=rbCreateForm($pdo,$tenantId,$userId,$name,$description,$type,$formPages,null);
    rbApiRespond(200,true,'Form created.',array('id'=>$created['id'],'redirect'=>'request-booking-form-builder.php?id='.$created['id']));
}catch(Throwable $e){error_log('FieldPlx new request-booking form: '.$e->getMessage());rbApiRespond(500,false,'Unable to create the form right now.');}
