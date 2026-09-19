<?php
ob_start();
ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');
require_once __DIR__ . '/request-booking-common.php';
list($tenantId,$userId)=rbRequireAuth();
rbRequireCsrf();

function rbBaseUrl()
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\','/',(string)$_SERVER['SCRIPT_NAME']) : '';
    $base = preg_replace('#/api/[^/]+$#','',$script);
    return $host !== '' ? $scheme . '://' . $host . $base : $base;
}

try {
    $schemaReady = rbSchemaReady($pdo);
    if ($schemaReady) rbEnsureSystemForms($pdo,$tenantId,$userId);
    $action = trim((string)rbPost('action','list'));

    if ($action === 'list') {
        $systemSelect = $schemaReady ? 'c.is_system_form,c.system_key,' : '0 AS is_system_form,NULL AS system_key,';
        $sql = "SELECT c.id,c.form_template_id,c.form_type,{$systemSelect}c.public_token,c.slug,c.form_pages,c.is_request_default,c.is_booking_default,c.require_booking_approval,c.service_area_enabled,c.created_at,c.updated_at,t.name,t.description,t.status,
                (SELECT COUNT(*) FROM request_booking_tracking_links tl WHERE tl.form_template_id=c.form_template_id) AS tracking_count
                FROM request_booking_form_configs c
                INNER JOIN form_templates t ON t.id=c.form_template_id AND t.tenant_id=c.tenant_id
                WHERE c.tenant_id=:tenant_id
                ORDER BY CASE WHEN " . ($schemaReady ? "c.system_key='default_assessment'" : "0") . " THEN 0 WHEN " . ($schemaReady ? "c.system_key='default_request'" : "0") . " THEN 1 ELSE 2 END, t.created_at ASC, c.id ASC";
        $stmt=$pdo->prepare($sql);
        $stmt->execute(array(':tenant_id'=>$tenantId));
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $base=rbBaseUrl();
        foreach($rows as &$row){
            $row['id']=(int)$row['id'];
            $row['form_template_id']=(int)$row['form_template_id'];
            $row['is_system_form']=(int)$row['is_system_form'];
            $row['is_request_default']=(int)$row['is_request_default'];
            $row['is_booking_default']=(int)$row['is_booking_default'];
            $row['tracking_count']=(int)$row['tracking_count'];
            $row['used_count']=$row['tracking_count'] + ($row['is_request_default']?1:0);
            $row['public_url']=$base.'/request-booking-form.php?token='.rawurlencode($row['public_token']);
            $row['embed_code']='<iframe src="'.htmlspecialchars($row['public_url'].'&embed=1',ENT_QUOTES,'UTF-8').'" style="width:100%;min-height:760px;border:0" loading="lazy"></iframe>';
        }
        unset($row);
        rbApiRespond(200,true,'Forms loaded.',array('forms'=>$rows,'schema_ready'=>$schemaReady));
    }

    $id=max(0,(int)rbPost('id',0));
    if($id<=0) rbApiRespond(422,false,'Select a valid form.');
    $systemSelect=$schemaReady?'c.is_system_form,c.system_key,':'0 AS is_system_form,NULL AS system_key,';
    $stmt=$pdo->prepare("SELECT c.id,c.form_template_id,c.form_type,{$systemSelect}c.is_request_default,c.is_booking_default,c.public_token,t.name,t.status FROM request_booking_form_configs c INNER JOIN form_templates t ON t.id=c.form_template_id WHERE c.id=:id AND c.tenant_id=:tenant_id LIMIT 1");
    $stmt->execute(array(':id'=>$id,':tenant_id'=>$tenantId));
    $form=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$form) rbApiRespond(404,false,'Form not found.');

    if($action==='toggle_status'){
        $active=(int)rbPost('active',0)===1;
        $stmt=$pdo->prepare("UPDATE form_templates SET status=:status WHERE id=:template_id AND tenant_id=:tenant_id");
        $stmt->execute(array(':status'=>$active?'active':'inactive',':template_id'=>(int)$form['form_template_id'],':tenant_id'=>$tenantId));
        rbApiRespond(200,true,$active?'Form enabled.':'Form disabled.',array('active'=>$active?1:0));
    }

    if($action==='set_default'){
        $kind=trim((string)rbPost('kind',''));
        if($kind==='request'){
            if($form['form_type']!=='request') rbApiRespond(422,false,'Only request forms can be the request default.');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE request_booking_form_configs SET is_request_default=0 WHERE tenant_id=:tenant_id")->execute(array(':tenant_id'=>$tenantId));
            $pdo->prepare("UPDATE request_booking_form_configs SET is_request_default=1 WHERE id=:id AND tenant_id=:tenant_id")->execute(array(':id'=>$id,':tenant_id'=>$tenantId));
            $pdo->commit();
            rbApiRespond(200,true,'Request default updated.');
        }
        if($kind==='booking'){
            if($form['form_type']==='request') rbApiRespond(422,false,'Choose a booking or assessment form.');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE request_booking_form_configs SET is_booking_default=0 WHERE tenant_id=:tenant_id")->execute(array(':tenant_id'=>$tenantId));
            $pdo->prepare("UPDATE request_booking_form_configs SET is_booking_default=1 WHERE id=:id AND tenant_id=:tenant_id")->execute(array(':id'=>$id,':tenant_id'=>$tenantId));
            $pdo->commit();
            rbApiRespond(200,true,'Booking default updated.');
        }
        rbApiRespond(422,false,'Select a valid default type.');
    }

    if($action==='delete'){
        if((int)$form['is_system_form']===1) rbApiRespond(403,false,'Default Form and Assessment Booking Form are built-in forms and cannot be deleted.');
        $wasRequestDefault=(int)$form['is_request_default']===1;
        $wasBookingDefault=(int)$form['is_booking_default']===1;
        $pdo->beginTransaction();
        try{
            $pdo->prepare("DELETE FROM form_templates WHERE id=:template_id AND tenant_id=:tenant_id")->execute(array(':template_id'=>(int)$form['form_template_id'],':tenant_id'=>$tenantId));
            if($schemaReady && $wasRequestDefault){
                $pdo->prepare("UPDATE request_booking_form_configs SET is_request_default=1 WHERE tenant_id=:tenant_id AND system_key='default_request' LIMIT 1")->execute(array(':tenant_id'=>$tenantId));
            }
            if($schemaReady && $wasBookingDefault){
                $pdo->prepare("UPDATE request_booking_form_configs SET is_booking_default=1 WHERE tenant_id=:tenant_id AND system_key='default_assessment' LIMIT 1")->execute(array(':tenant_id'=>$tenantId));
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        rbApiRespond(200,true,'Form deleted.');
    }

    if($action==='tracking_list'){
        $stmt=$pdo->prepare("SELECT id,source_key,source_label,tracking_token,clicks,submissions,created_at FROM request_booking_tracking_links WHERE tenant_id=:tenant_id AND form_template_id=:template_id ORDER BY id DESC");
        $stmt->execute(array(':tenant_id'=>$tenantId,':template_id'=>(int)$form['form_template_id']));
        $links=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $base=rbBaseUrl();
        foreach($links as &$link){$link['url']=$base.'/request-booking-form.php?token='.rawurlencode($form['public_token']).'&source='.rawurlencode($link['tracking_token']);}
        unset($link);
        rbApiRespond(200,true,'Tracking links loaded.',array('links'=>$links));
    }

    if($action==='tracking_add'){
        $label=trim((string)rbPost('source_label',''));
        if($label==='') rbApiRespond(422,false,'Enter a tracking source name.');
        $baseKey=rbSlug($label);
        $key=$baseKey;
        $n=2;
        while(true){
            $check=$pdo->prepare("SELECT id FROM request_booking_tracking_links WHERE form_template_id=:template_id AND source_key=:source_key LIMIT 1");
            $check->execute(array(':template_id'=>(int)$form['form_template_id'],':source_key'=>$key));
            if(!$check->fetchColumn())break;
            $key=$baseKey.'-'.$n;$n++;
        }
        $token=bin2hex(random_bytes(18));
        $stmt=$pdo->prepare("INSERT INTO request_booking_tracking_links (tenant_id,form_template_id,source_key,source_label,tracking_token) VALUES (:tenant_id,:template_id,:source_key,:source_label,:tracking_token)");
        $stmt->execute(array(':tenant_id'=>$tenantId,':template_id'=>(int)$form['form_template_id'],':source_key'=>$key,':source_label'=>substr($label,0,120),':tracking_token'=>$token));
        $url=rbBaseUrl().'/request-booking-form.php?token='.rawurlencode($form['public_token']).'&source='.rawurlencode($token);
        rbApiRespond(200,true,'Tracking link created.',array('id'=>(int)$pdo->lastInsertId(),'url'=>$url));
    }

    rbApiRespond(400,false,'Unsupported action.');
}catch(Throwable $e){
    if($pdo instanceof PDO && $pdo->inTransaction())$pdo->rollBack();
    error_log('FieldPlx request-booking settings API: '.$e->getMessage());
    rbApiRespond(500,false,'Unable to update forms right now.');
}
