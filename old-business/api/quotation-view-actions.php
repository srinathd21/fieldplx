<?php
/* FieldPlx Quotation View Actions - Request View aligned v3.0.0 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}
require_once __DIR__ . '/../includes/platform-smtp.php';

function qvaOut($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qvaDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('PDO database connection is not available.');
}

function qvaPost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function qvaTable(PDO $pdo, $table)
{
    static $cache = array();
    if (array_key_exists($table, $cache)) return $cache[$table];
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:n");
    $st->execute(array(':n' => $table));
    $cache[$table] = ((int)$st->fetchColumn() > 0);
    return $cache[$table];
}

function qvaColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $st->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$st->fetchColumn() > 0);
    return $cache[$key];
}

function qvaColumns(PDO $pdo, $table)
{
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION");
    $st->execute(array(':t' => $table));
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function qvaCurrency(PDO $pdo, $tenantId, $branchId)
{
    $st = $pdo->prepare(
        "SELECT c.currency_code,c.currency_name,c.symbol,c.symbol_position,c.decimal_places,c.decimal_separator,c.thousand_separator
         FROM tenants t
         LEFT JOIN branches b ON b.id=:b AND b.tenant_id=t.id
         LEFT JOIN currencies c ON c.id=COALESCE(b.currency_id,t.currency_id)
         WHERE t.id=:t LIMIT 1"
    );
    $st->execute(array(':b' => $branchId > 0 ? $branchId : -1, ':t' => $tenantId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['currency_code'])) {
        return array('currency_code'=>'INR','currency_name'=>'Indian Rupee','symbol'=>'₹','symbol_position'=>'before','decimal_places'=>2,'decimal_separator'=>'.','thousand_separator'=>',');
    }
    $row['decimal_places'] = (int)$row['decimal_places'];
    return $row;
}

function qvaQuote(PDO $pdo, $tenantId, $quoteId)
{
    $st = $pdo->prepare(
        "SELECT q.*,
                DATE(q.created_at) AS created_date,
                c.display_name AS client_name,c.company_name AS client_company,c.email AS client_email,c.phone AS client_phone,c.alternate_phone AS client_alternate_phone,
                cl.name AS location_name,cl.address_line1 AS location_address1,cl.address_line2 AS location_address2,cl.city AS location_city,cl.state AS location_state,cl.postal_code AS location_postal_code,
                sr.request_no,sr.title AS request_title,sr.source AS request_source,sr.status AS request_status,
                b.name AS branch_name,b.branch_code,
                t.display_name AS tenant_name,t.legal_name AS tenant_legal_name,
                TRIM(CONCAT(COALESCE(sp.first_name,''),CASE WHEN COALESCE(sp.last_name,'')<>'' THEN CONCAT(' ',sp.last_name) ELSE '' END)) AS salesperson_name
         FROM quotes q
         INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id
         LEFT JOIN client_locations cl ON cl.id=q.location_id AND cl.tenant_id=q.tenant_id AND cl.client_id=q.client_id
         LEFT JOIN service_requests sr ON sr.id=q.request_id AND sr.tenant_id=q.tenant_id
         LEFT JOIN branches b ON b.id=q.branch_id AND b.tenant_id=q.tenant_id
         INNER JOIN tenants t ON t.id=q.tenant_id
         LEFT JOIN users sp ON sp.id=q.salesperson_id AND sp.tenant_id=q.tenant_id
         WHERE q.id=:id AND q.tenant_id=:t LIMIT 1"
    );
    $st->execute(array(':id'=>$quoteId, ':t'=>$tenantId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) qvaOut(404, false, 'Quotation not found or you do not have access to it.');
    $row['quotation_source'] = empty($row['request_id']) ? 'Direct Quotation' : 'Original Enquiry';
    return $row;
}

function qvaItems(PDO $pdo, $quoteId)
{
    if (!qvaTable($pdo, 'quote_line_items')) return array();
    $st = $pdo->prepare("SELECT * FROM quote_line_items WHERE quote_id=:q ORDER BY sort_order,id");
    $st->execute(array(':q'=>$quoteId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function qvaActions(PDO $pdo, $tenantId, $quoteId)
{
    if (!qvaTable($pdo, 'quote_actions')) return array();
    $st = $pdo->prepare(
        "SELECT qa.*,TRIM(CONCAT(COALESCE(u.first_name,''),CASE WHEN COALESCE(u.last_name,'')<>'' THEN CONCAT(' ',u.last_name) ELSE '' END)) AS user_name
         FROM quote_actions qa
         LEFT JOIN users u ON u.id=qa.user_id AND u.tenant_id=qa.tenant_id
         WHERE qa.tenant_id=:t AND qa.quote_id=:q
         ORDER BY qa.created_at DESC,qa.id DESC"
    );
    $st->execute(array(':t'=>$tenantId, ':q'=>$quoteId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function qvaLinkedJob(PDO $pdo, $tenantId, $quoteId)
{
    if (!qvaTable($pdo, 'jobs')) return null;
    $deletedSql = qvaColumn($pdo, 'jobs', 'deleted_at') ? " AND deleted_at IS NULL" : '';
    $st = $pdo->prepare("SELECT id,job_no,status FROM jobs WHERE tenant_id=:t AND quote_id=:q" . $deletedSql . " ORDER BY id DESC LIMIT 1");
    $st->execute(array(':t'=>$tenantId, ':q'=>$quoteId));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function qvaSignature(PDO $pdo, $tenantId, $quoteId)
{
    if (!qvaTable($pdo, 'attachments')) return null;
    $st = $pdo->prepare("SELECT id,file_name,file_path,file_mime,file_size,description,created_at FROM attachments WHERE tenant_id=:t AND related_type='quote' AND related_id=:q AND attachment_type='signature' ORDER BY id DESC LIMIT 1");
    $st->execute(array(':t'=>$tenantId, ':q'=>$quoteId));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function qvaSections(PDO $pdo, $tenantId, $quoteId)
{
    if (!qvaTable($pdo, 'quote_sections')) return array();
    $st = $pdo->prepare("SELECT * FROM quote_sections WHERE tenant_id=:t AND quote_id=:q ORDER BY sort_order,id");
    $st->execute(array(':t'=>$tenantId, ':q'=>$quoteId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function qvaFiles(PDO $pdo, $tenantId, $quoteId)
{
    if (!qvaTable($pdo, 'quote_files')) return array();
    $where = "quote_id=:q";
    $params = array(':q'=>$quoteId);
    if (qvaColumn($pdo,'quote_files','tenant_id')) {
        $where .= " AND tenant_id=:t";
        $params[':t'] = $tenantId;
    }
    $order = qvaColumn($pdo,'quote_files','sort_order') ? 'sort_order,id' : 'id';
    $st = $pdo->prepare("SELECT * FROM quote_files WHERE ".$where." ORDER BY ".$order);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function qvaPaymentSchedule(PDO $pdo, $tenantId, $quoteId)
{
    if (!qvaTable($pdo,'quote_payment_schedule_items')) return array();
    $where = "quote_id=:q";
    $params = array(':q'=>$quoteId);
    if (qvaColumn($pdo,'quote_payment_schedule_items','tenant_id')) {
        $where .= " AND tenant_id=:t";
        $params[':t'] = $tenantId;
    }
    $order = qvaColumn($pdo,'quote_payment_schedule_items','sort_order') ? 'sort_order,id' : 'id';
    $st = $pdo->prepare("SELECT * FROM quote_payment_schedule_items WHERE ".$where." ORDER BY ".$order);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function qvaLogAction(PDO $pdo, $tenantId, $quoteId, $userId, $action, $comment)
{
    if (!qvaTable($pdo, 'quote_actions')) return;
    $allowed = array('sent','viewed','approved','rejected','changes_requested','commented');
    if (!in_array($action, $allowed, true)) return;
    try {
        $st = $pdo->prepare("INSERT INTO quote_actions(tenant_id,quote_id,action,comment,actor_type,user_id,created_at) VALUES(:t,:q,:a,:c,'user',:u,NOW())");
        $st->execute(array(':t'=>$tenantId, ':q'=>$quoteId, ':a'=>$action, ':c'=>$comment !== '' ? $comment : null, ':u'=>$userId));
    } catch (Throwable $e) {
        error_log('quotation view quote_action: ' . $e->getMessage());
    }
}

function qvaActivity(PDO $pdo, $tenantId, $branchId, $userId, $quoteId, $clientId, $eventType, $title, $details)
{
    if (!qvaTable($pdo, 'activity_events')) return;
    try {
        $st = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user',:e,'quote',:q,:c,:title,:d,0)");
        $st->execute(array(':t'=>$tenantId, ':b'=>$branchId > 0 ? $branchId : null, ':u'=>$userId, ':e'=>$eventType, ':q'=>$quoteId, ':c'=>$clientId > 0 ? $clientId : null, ':title'=>substr($title,0,255), ':d'=>json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    } catch (Throwable $e) {
        error_log('quotation view activity: ' . $e->getMessage());
    }
}

function qvaAudit(PDO $pdo, $action, $tenantId, $branchId, $userId, $quoteId, $old, $new)
{
    if (!function_exists('tenantAuditLog')) return;
    try {
        tenantAuditLog($pdo, $action, $tenantId, $branchId, $userId, 'quote', $quoteId, $old, $new, array('module'=>'Quotes','audit_category'=>'GENERAL_ACTIVITY'));
    } catch (Throwable $e) {
        error_log('quotation view audit: ' . $e->getMessage());
    }
}



/* --------------------------------------------------------------------------
 * Quotation email composer / SMTP helpers
 * -------------------------------------------------------------------------- */
function qvaSmtpSecretKey()
{
    if (!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $secretFile = __DIR__ . '/../includes/smtp-secret.php';
        if (is_file($secretFile)) require_once $secretFile;
    }
    $key = defined('FIELDPLX_SMTP_ENCRYPTION_KEY') ? trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY) : '';
    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) $key = trim((string)$env);
    }
    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) $key = trim((string)$env);
    }
    if ($key === '' || strlen($key) < 32) throw new RuntimeException('SMTP encryption key is not configured.');
    return hash('sha256', $key, true);
}

function qvaDecryptSmtpPassword($stored)
{
    $stored = trim((string)$stored);
    if ($stored === '') return '';
    if (!function_exists('openssl_decrypt')) throw new RuntimeException('OpenSSL is required to decrypt the SMTP password.');
    if (strpos($stored, 'v1:') !== 0) throw new RuntimeException('SMTP password uses an unsupported encryption format. Re-save the SMTP password in Master Controls.');
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= 16) throw new RuntimeException('Stored SMTP password is invalid.');
    $plain = openssl_decrypt(substr($raw,16),'AES-256-CBC',qvaSmtpSecretKey(),OPENSSL_RAW_DATA,substr($raw,0,16));
    if ($plain === false) throw new RuntimeException('Unable to decrypt the SMTP password. Confirm the permanent SMTP encryption key.');
    return $plain;
}

function qvaLoadPhpMailer()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;
    $paths = array(
        dirname(__DIR__,2) . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../vendor/autoload.php'
    );
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;
        }
    }
    return false;
}

function qvaSmtpConfiguration(PDO $pdo, $tenantId, $branchId)
{
    if (!qvaTable($pdo,'smtp_configurations')) return null;
    if ($branchId > 0) {
        $st = $pdo->prepare("SELECT * FROM smtp_configurations WHERE is_active=1 AND ((scope_type='branch' AND tenant_id=:t AND branch_id=:b) OR (scope_type='tenant' AND tenant_id=:t2) OR (scope_type='platform' AND tenant_id IS NULL)) ORDER BY CASE WHEN scope_type='branch' THEN 0 WHEN scope_type='tenant' THEN 1 ELSE 2 END,is_default DESC,id DESC LIMIT 1");
        $st->execute(array(':t'=>$tenantId,':b'=>$branchId,':t2'=>$tenantId));
    } else {
        $st = $pdo->prepare("SELECT * FROM smtp_configurations WHERE is_active=1 AND ((scope_type='tenant' AND tenant_id=:t) OR (scope_type='platform' AND tenant_id IS NULL)) ORDER BY CASE WHEN scope_type='tenant' THEN 0 ELSE 1 END,is_default DESC,id DESC LIMIT 1");
        $st->execute(array(':t'=>$tenantId));
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function qvaCreateMailer(PDO $pdo, $tenantId, $branchId)
{
    if (!qvaLoadPhpMailer()) throw new RuntimeException('PHPMailer is not installed. Install PHPMailer with Composer before sending quotation email.');
    $config = qvaSmtpConfiguration($pdo,$tenantId,$branchId);
    if (!$config) throw new RuntimeException('No active SMTP configuration is available for this company.');
    $password = qvaDecryptSmtpPassword(isset($config['password_encrypted']) ? $config['password_encrypted'] : '');
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string)$config['host']);
    $mail->Port = (int)$config['port'];
    $mail->Timeout = 20;
    if (property_exists($mail,'Timelimit')) $mail->Timelimit = 20;
    $mail->SMTPDebug = 0;
    $username = trim((string)$config['username']);
    $mail->SMTPAuth = $username !== '';
    if ($mail->SMTPAuth) {
        if ($password === '') throw new RuntimeException('SMTP password is empty or could not be decrypted.');
        $mail->Username = $username;
        $mail->Password = $password;
    }
    $encryption = strtolower(trim((string)$config['encryption']));
    if ($encryption === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
    } elseif ($encryption === 'tls' || $encryption === 'starttls') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    $fromEmail = trim((string)$config['from_email']);
    if (!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) $fromEmail = $username;
    if (!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The configured SMTP From Email is invalid.');
    $fromName = trim((string)$config['from_name']);
    if ($fromName === '') $fromName = 'FieldPlx';
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail,$fromName);
    $replyTo = trim((string)$config['reply_to_email']);
    if ($replyTo !== '' && filter_var($replyTo,FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
    return $mail;
}

function qvaParseEmails($raw)
{
    $parts = preg_split('/[;,\\s]+/',trim((string)$raw));
    $out = array();
    foreach ($parts as $email) {
        $email = strtolower(trim((string)$email));
        if ($email !== '' && filter_var($email,FILTER_VALIDATE_EMAIL)) $out[$email] = $email;
    }
    return array_values($out);
}

function qvaEmailAttachmentFiles()
{
    $out = array();
    if (empty($_FILES['email_attachments']) || !is_array($_FILES['email_attachments'])) return $out;
    $f = $_FILES['email_attachments'];
    $names = isset($f['name']) && is_array($f['name']) ? $f['name'] : array(isset($f['name']) ? $f['name'] : '');
    $total = 0;
    foreach ($names as $idx=>$name) {
        $error = is_array($f['error']) ? (int)$f['error'][$idx] : (int)$f['error'];
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('One email attachment could not be uploaded.');
        $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$idx] : $f['tmp_name'];
        $size = is_array($f['size']) ? (int)$f['size'][$idx] : (int)$f['size'];
        if (!is_uploaded_file($tmp)) throw new RuntimeException('One email attachment is invalid.');
        $total += $size;
        if ($total > 10*1024*1024) throw new RuntimeException('Email attachments cannot exceed 10 MB in total.');
        $safeName = preg_replace('/[^A-Za-z0-9._() -]/','_',basename((string)$name));
        if ($safeName === '') $safeName = 'attachment';
        $out[] = array('tmp'=>$tmp,'name'=>$safeName,'size'=>$size);
    }
    return $out;
}

function qvaDate($value)
{
    if (!$value) return '-';
    $ts = strtotime((string)$value);
    return $ts ? date('M d, Y',$ts) : (string)$value;
}

function qvaMoney($value,$currency)
{
    $places = isset($currency['decimal_places']) ? max(0,min(4,(int)$currency['decimal_places'])) : 2;
    $number = number_format((float)$value,$places,'.',',');
    $symbol = isset($currency['symbol']) ? trim((string)$currency['symbol']) : '';
    if ($symbol === '') return $number;
    return isset($currency['symbol_position']) && $currency['symbol_position']==='after' ? $number.' '.$symbol : $symbol.$number;
}


function qvaPlatformSmtpConfig(PDO $pdo)
{
    $cfg = fieldplxPlatformSmtpConfig($pdo);
    if (!$cfg) {
        throw new RuntimeException('No active default Platform SMTP configuration is available. Configure one Platform SMTP as Active + Default in Master Controls.');
    }
    if (
        (isset($cfg['scope_type']) && (string)$cfg['scope_type'] !== 'platform') ||
        !empty($cfg['tenant_id']) ||
        !empty($cfg['branch_id']) ||
        empty($cfg['is_default'])
    ) {
        throw new RuntimeException('Quotation email requires the global default Platform SMTP configuration.');
    }
    return $cfg;
}

function qvaNotificationEventId(PDO $pdo, $eventKey)
{
    if (!qvaTable($pdo,'notification_events')) return 0;
    $st = $pdo->prepare("SELECT id FROM notification_events WHERE event_key=:k AND is_active=1 ORDER BY id LIMIT 1");
    $st->execute(array(':k'=>$eventKey));
    return (int)$st->fetchColumn();
}

function qvaQueueLogCreate(PDO $pdo, $tenantId, $branchId, $clientId, $email, $quoteId, $subject, $body, $smtpId, $status, $error)
{
    if (!qvaTable($pdo,'notification_queue')) return 0;
    $eventId = qvaNotificationEventId($pdo,'quote.sent');
    if ($eventId <= 0) return 0;
    $allowed = array('queued','processing','sent','delivered','read','failed','suppressed');
    if (!in_array($status,$allowed,true)) $status='processing';
    $attempts = in_array($status,array('processing','sent','failed'),true) ? 1 : 0;
    try {
        $st = $pdo->prepare("INSERT INTO notification_queue(tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address,related_type,related_id,subject,body,smtp_config_id,status,attempts,scheduled_at,sent_at,error_message,created_at) VALUES(:t,:b,:e,'email','client',:c,:addr,'quote',:q,:sub,:body,:smtp,:st,:a,NOW(),:sent,:err,NOW())");
        $st->execute(array(
            ':t'=>$tenantId,
            ':b'=>$branchId>0?$branchId:null,
            ':e'=>$eventId,
            ':c'=>$clientId>0?$clientId:null,
            ':addr'=>$email!==''?$email:null,
            ':q'=>$quoteId,
            ':sub'=>$subject!==''?substr($subject,0,255):null,
            ':body'=>(string)$body,
            ':smtp'=>$smtpId>0?$smtpId:null,
            ':st'=>$status,
            ':a'=>$attempts,
            ':sent'=>$status==='sent'?date('Y-m-d H:i:s'):null,
            ':err'=>$error!==''?$error:null
        ));
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('quotation view notification queue insert: '.$e->getMessage());
        return 0;
    }
}

function qvaQueueLogFinish(PDO $pdo, $logId, $status, $error)
{
    if ($logId <= 0 || !qvaTable($pdo,'notification_queue')) return;
    if (!in_array($status,array('sent','failed','suppressed'),true)) $status='failed';
    try {
        $st=$pdo->prepare("UPDATE notification_queue SET status=:s,sent_at=CASE WHEN :sent='sent' THEN NOW() ELSE NULL END,error_message=:e WHERE id=:id LIMIT 1");
        $st->execute(array(':s'=>$status,':sent'=>$status,':e'=>$error!==''?$error:null,':id'=>$logId));
    } catch (Throwable $e) {
        error_log('quotation view notification queue update: '.$e->getMessage());
    }
}

function qvaGenerateQuotationPdfForEmail($quoteId)
{
    $quoteId=(int)$quoteId;
    if ($quoteId<=0) throw new RuntimeException('Invalid quotation selected for PDF attachment.');
    $printFile=dirname(__DIR__).'/quotation-print.php';
    if (!is_file($printFile)) throw new RuntimeException('quotation-print.php was not found, so the quotation PDF could not be attached.');
    if (!defined('FIELDPLX_QUOTE_PDF_CAPTURE')) define('FIELDPLX_QUOTE_PDF_CAPTURE',true);

    $hadQuote=array_key_exists('quote_id',$_GET); $oldQuote=$hadQuote?$_GET['quote_id']:null;
    $hadId=array_key_exists('id',$_GET); $oldId=$hadId?$_GET['id']:null;
    unset($GLOBALS['fieldplx_quote_pdf_bytes'],$GLOBALS['fieldplx_quote_pdf_name']);
    $_GET['quote_id']=$quoteId; unset($_GET['id']);
    $level=ob_get_level(); ob_start();
    try {
        include $printFile;
    } catch (Throwable $e) {
        while (ob_get_level()>$level) @ob_end_clean();
        if ($hadQuote) $_GET['quote_id']=$oldQuote; else unset($_GET['quote_id']);
        if ($hadId) $_GET['id']=$oldId; else unset($_GET['id']);
        throw new RuntimeException('Unable to generate the quotation PDF attachment: '.$e->getMessage(),0,$e);
    }
    while (ob_get_level()>$level) @ob_end_clean();
    if ($hadQuote) $_GET['quote_id']=$oldQuote; else unset($_GET['quote_id']);
    if ($hadId) $_GET['id']=$oldId; else unset($_GET['id']);

    $bytes=isset($GLOBALS['fieldplx_quote_pdf_bytes'])?$GLOBALS['fieldplx_quote_pdf_bytes']:'';
    $name=isset($GLOBALS['fieldplx_quote_pdf_name'])?$GLOBALS['fieldplx_quote_pdf_name']:('Quote-'.$quoteId.'.pdf');
    unset($GLOBALS['fieldplx_quote_pdf_bytes'],$GLOBALS['fieldplx_quote_pdf_name']);
    if (!is_string($bytes) || strlen($bytes)<100 || substr($bytes,0,4)!=='%PDF') {
        throw new RuntimeException('The quotation print page did not return a valid PDF document.');
    }
    return array('bytes'=>$bytes,'name'=>$name,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes));
}

function qvaCreatePendingApprovalToken(PDO $pdo, $tenantId, $quoteId, $clientId, $days)
{
    if (!qvaTable($pdo,'quotation_action_tokens')) {
        throw new RuntimeException('Quotation approval support is not installed. Run the quotation approval migration first.');
    }
    $plain=bin2hex(random_bytes(32));
    $hash=hash('sha256',$plain);
    $expires=date('Y-m-d H:i:s',strtotime('+'.max(1,(int)$days).' days'));
    $st=$pdo->prepare("INSERT INTO quotation_action_tokens(tenant_id,quote_id,client_id,token_hash,expires_at) VALUES(:t,:q,:c,:h,:e)");
    $st->execute(array(':t'=>$tenantId,':q'=>$quoteId,':c'=>$clientId>0?$clientId:null,':h'=>$hash,':e'=>$expires));
    return array('id'=>(int)$pdo->lastInsertId(),'plain'=>$plain,'expires'=>$expires);
}

function qvaAppBaseUrl()
{
    $https=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT']===443);
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $https=strtolower(trim(explode(',',$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]))==='https';
    $scheme=$https?'https':'http';
    $host=isset($_SERVER['HTTP_HOST'])?trim((string)$_SERVER['HTTP_HOST']):'';
    if ($host==='') throw new RuntimeException('Unable to determine application URL for quotation approval link.');
    $script=isset($_SERVER['SCRIPT_NAME'])?str_replace('\\','/',(string)$_SERVER['SCRIPT_NAME']):'/business/api/quotation-view-actions.php';
    $root=dirname(dirname(dirname($script)));
    if ($root==='/' || $root==='.' || $root==='\\') $root='';
    return $scheme.'://'.$host.rtrim($root,'/');
}

function qvaApprovalEmailHtml($quote,$items,$currency,$message,$reviewUrl,$expires)
{
    $e=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
    $company=!empty($quote['branch_name'])?$quote['branch_name']:(!empty($quote['tenant_name'])?$quote['tenant_name']:'FieldPlx');
    $view=array('quantities'=>true,'unit_prices'=>true,'line_item_totals'=>true,'totals'=>true);
    if (!empty($quote['client_view_options_json'])) {
        $tmp=json_decode((string)$quote['client_view_options_json'],true);
        if (is_array($tmp)) foreach ($view as $k=>$v) if (array_key_exists($k,$tmp)) $view[$k]=(bool)$tmp[$k];
    }
    $head='<th style="padding:9px 8px;text-align:left;border-bottom:2px solid #dfe6ea">Product / Service</th>';
    if ($view['quantities']) $head.='<th style="padding:9px 8px;text-align:center;border-bottom:2px solid #dfe6ea">Qty</th>';
    if ($view['unit_prices']) $head.='<th style="padding:9px 8px;text-align:right;border-bottom:2px solid #dfe6ea">Unit price</th>';
    if ($view['line_item_totals']) $head.='<th style="padding:9px 8px;text-align:right;border-bottom:2px solid #dfe6ea">Total</th>';
    $rows='';
    foreach ((array)$items as $item) {
        $rows.='<tr><td style="padding:9px 8px;border-bottom:1px solid #e7ebee">'.$e(isset($item['item_name'])?$item['item_name']:'-').'</td>';
        if ($view['quantities']) $rows.='<td style="padding:9px 8px;border-bottom:1px solid #e7ebee;text-align:center">'.$e(rtrim(rtrim(number_format((float)$item['quantity'],3,'.',''),'0'),'.')).'</td>';
        if ($view['unit_prices']) $rows.='<td style="padding:9px 8px;border-bottom:1px solid #e7ebee;text-align:right">'.$e(qvaMoney(isset($item['unit_price'])?$item['unit_price']:0,$currency)).'</td>';
        if ($view['line_item_totals']) $rows.='<td style="padding:9px 8px;border-bottom:1px solid #e7ebee;text-align:right">'.$e(qvaMoney(isset($item['line_total'])?$item['line_total']:0,$currency)).'</td>';
        $rows.='</tr>';
    }
    $safeMessage=trim((string)$message)!==''?'<div style="line-height:1.65;margin-bottom:18px">'.nl2br($e($message)).'</div>':'';
    $expiresText=$expires!==''?date('d M Y, h:i A',strtotime($expires)):'';
    return '<!doctype html><html><body style="margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#0b1933">'
        .'<div style="max-width:680px;margin:0 auto;padding:28px 14px">'
        .'<div style="background:#001131;padding:22px 24px;border-radius:12px 12px 0 0"><div style="color:#9fda55;font-size:13px;font-weight:700">'.$e($company).'</div><div style="margin-top:5px;color:#fff;font-size:22px;font-weight:700">Quotation '.$e($quote['quote_no']).'</div></div>'
        .'<div style="background:#fff;padding:24px;border:1px solid #e5eaf1;border-top:0;border-radius:0 0 12px 12px">'
        .$safeMessage
        .'<div style="margin:16px 0;padding:14px;background:#f7f9fb;border-radius:8px"><strong>Quote:</strong> '.$e($quote['quote_no']).'<br><strong>Created:</strong> '.$e(qvaDate($quote['created_at'])).'<br><strong>Valid until:</strong> '.$e(qvaDate($quote['valid_until'])).($view['totals']?'<br><strong>Total:</strong> '.$e(qvaMoney($quote['total'],$currency)):'').'</div>'
        .'<table style="width:100%;border-collapse:collapse;margin:18px 0"><thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>'
        .'<div style="text-align:center;margin:24px 0"><a href="'.$e($reviewUrl).'" style="display:inline-block;padding:13px 22px;border-radius:8px;background:#74b824;color:#fff;text-decoration:none;font-weight:700">Review &amp; Approve / Reject</a></div>'
        .($expiresText!==''?'<p style="font-size:12px;color:#7b8798;text-align:center">This secure response link expires on '.$e($expiresText).'.</p>':'')
        .'<p style="font-size:12px;color:#7b8798;line-height:1.5">The quotation PDF is attached to this email for your records.</p>'
        .'</div></div></body></html>';
}

function qvaBuildUploadedMailAttachments($files)
{
    $out=array();
    foreach ((array)$files as $file) {
        $bytes=@file_get_contents($file['tmp']);
        if ($bytes===false) throw new RuntimeException('One email attachment could not be read.');
        $mime='application/octet-stream';
        if (class_exists('finfo')) {
            $fi=new finfo(FILEINFO_MIME_TYPE);
            $detected=$fi->file($file['tmp']);
            if (is_string($detected) && trim($detected)!=='') $mime=trim($detected);
        }
        $out[]=array('name'=>$file['name'],'mime'=>$mime,'content'=>$bytes);
    }
    return $out;
}

function qvaQuoteEmailHtml($quote,$items,$currency,$message)
{
    $e = function($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); };
    $company = !empty($quote['branch_name']) ? $quote['branch_name'] : (!empty($quote['tenant_name']) ? $quote['tenant_name'] : 'FieldPlx');
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:auto;color:#173946;background:#fff">';
    $html .= '<div style="padding:20px 22px;background:#001131;color:#fff"><div style="font-size:12px;opacity:.8">'.$e($company).'</div><h2 style="margin:5px 0 0;font-size:22px">Quotation '.$e($quote['quote_no']).'</h2></div>';
    $html .= '<div style="padding:22px;border:1px solid #e2e8ec;border-top:0">';
    if (trim((string)$message) !== '') $html .= '<div style="line-height:1.65;margin-bottom:18px">'.nl2br($e($message)).'</div>';
    $html .= '<div style="padding:13px 15px;background:#f7f9fb;border-radius:8px;line-height:1.75"><strong>Quote:</strong> '.$e($quote['quote_no']).'<br><strong>Created:</strong> '.$e(qvaDate($quote['created_at'])).'<br><strong>Valid until:</strong> '.$e(qvaDate($quote['valid_until'])).'<br><strong>Total:</strong> '.$e(qvaMoney($quote['total'],$currency)).'</div>';
    if ($items) {
        $html .= '<table style="width:100%;border-collapse:collapse;margin-top:18px"><thead><tr><th style="padding:9px;text-align:left;border-bottom:2px solid #dfe6ea">Product / Service</th><th style="padding:9px;text-align:center;border-bottom:2px solid #dfe6ea">Qty</th><th style="padding:9px;text-align:right;border-bottom:2px solid #dfe6ea">Total</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $html .= '<tr><td style="padding:9px;border-bottom:1px solid #e7ebee">'.$e(isset($item['item_name'])?$item['item_name']:'-').'</td><td style="padding:9px;text-align:center;border-bottom:1px solid #e7ebee">'.$e(rtrim(rtrim(number_format((float)$item['quantity'],3,'.',''),'0'),'.')).'</td><td style="padding:9px;text-align:right;border-bottom:1px solid #e7ebee">'.$e(qvaMoney($item['line_total'],$currency)).'</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $html .= '<p style="margin:20px 0 0">Thank you,<br>'.$e($company).'</p></div></div>';
    return $html;
}

function qvaAttachQuotePdfIfAvailable($mail,$quote,$items,$currency)
{
    if (!class_exists('Dompdf\\Dompdf')) return false;
    try {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml(qvaQuoteEmailHtml($quote,$items,$currency,''),'UTF-8');
        $dompdf->setPaper('A4','portrait');
        $dompdf->render();
        $pdf = $dompdf->output();
        if ($pdf !== '') {
            $name = 'quote_'.preg_replace('/[^A-Za-z0-9_-]/','_',isset($quote['quote_no'])?$quote['quote_no']:'quote').'.pdf';
            $mail->addStringAttachment($pdf,$name,'base64','application/pdf');
            return true;
        }
    } catch (Throwable $e) {
        error_log('Quotation PDF email attachment: '.$e->getMessage());
    }
    return false;
}

function qvaNextQuoteNo(PDO $pdo, $tenantId, $branchId)
{
    if (!qvaTable($pdo, 'document_sequences')) {
        $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(quote_no,'-',-1) AS UNSIGNED)) FROM quotes WHERE tenant_id=:t AND quote_no LIKE 'QUO-%'");
        $st->execute(array(':t'=>$tenantId));
        return 'QUO-' . str_pad((string)((int)$st->fetchColumn()+1), 6, '0', STR_PAD_LEFT);
    }

    $sepCol = qvaColumn($pdo, 'document_sequences', 'number_separator') ? 'number_separator' : (qvaColumn($pdo, 'document_sequences', 'separator') ? 'separator' : null);
    $st = $pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type='quote' AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1 FOR UPDATE");
    $st->execute(array(':t'=>$tenantId, ':b'=>$branchId > 0 ? $branchId : 0, ':b2'=>$branchId > 0 ? $branchId : 0));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        $s = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(quote_no,'-',-1) AS UNSIGNED)) FROM quotes WHERE tenant_id=:t AND quote_no LIKE 'QUO-%'");
        $s->execute(array(':t'=>$tenantId));
        return 'QUO-' . str_pad((string)((int)$s->fetchColumn()+1), 6, '0', STR_PAD_LEFT);
    }

    $now = new DateTime('now');
    $year = $now->format('Y');
    $month = $now->format('m');
    $fyStart = isset($r['financial_year_start_month']) ? max(1,min(12,(int)$r['financial_year_start_month'])) : 4;
    $fyY = (int)$now->format('n') >= $fyStart ? (int)$year : (int)$year - 1;
    $fy = $fyY . '-' . substr((string)($fyY+1), -2);
    $reset = isset($r['reset_period']) ? strtolower((string)$r['reset_period']) : 'never';
    $key = 'never';
    if (in_array($reset, array('monthly','month'), true)) $key = $year . $month;
    elseif (in_array($reset, array('yearly','year'), true)) $key = $year;
    elseif ($reset === 'financial_year') $key = $fy;
    $cur = isset($r['current_number']) ? (int)$r['current_number'] : 0;
    if ($reset !== 'never' && isset($r['last_reset_key']) && (string)$r['last_reset_key'] !== (string)$key) $cur = 0;
    $next = $cur + 1;
    $mid = '';
    $mf = isset($r['middle_format']) ? (string)$r['middle_format'] : 'none';
    if ($mf === 'year') $mid = $year;
    elseif ($mf === 'year_month') $mid = $year . $month;
    elseif ($mf === 'financial_year') $mid = $fy;
    elseif ($mf === 'branch_year') $mid = (!empty($r['branch_code']) ? $r['branch_code'] : 'BR') . $year;
    $parts = array();
    if (!empty($r['prefix'])) $parts[] = $r['prefix'];
    if ($mid !== '') $parts[] = $mid;
    $length = isset($r['number_length']) ? max(1,(int)$r['number_length']) : 4;
    $parts[] = str_pad((string)$next, $length, '0', STR_PAD_LEFT);
    if (!empty($r['suffix'])) $parts[] = $r['suffix'];
    $separator = $sepCol !== null && isset($r[$sepCol]) ? (string)$r[$sepCol] : '-';
    $no = implode($separator, $parts);
    $u = $pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");
    $u->execute(array(':n'=>$next, ':k'=>$key, ':id'=>$r['id']));
    return $no;
}

function qvaCloneChildTable(PDO $pdo, $table, $sourceQuoteId, $newQuoteId, $tenantId)
{
    if (!qvaTable($pdo, $table)) return;
    $cols = qvaColumns($pdo, $table);
    if (!in_array('quote_id', $cols, true)) return;
    $where = 'quote_id=:q';
    $params = array(':q'=>$sourceQuoteId);
    if (in_array('tenant_id', $cols, true)) {
        $where .= ' AND tenant_id=:t';
        $params[':t'] = $tenantId;
    }
    $st = $pdo->prepare("SELECT * FROM `" . str_replace('`','',$table) . "` WHERE " . $where . " ORDER BY id");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $insertCols = array();
        $placeholders = array();
        $insertParams = array();
        foreach ($cols as $col) {
            if ($col === 'id') continue;
            if (!array_key_exists($col, $row) && $col !== 'quote_id') continue;
            $insertCols[] = '`' . $col . '`';
            $ph = ':p_' . count($insertParams);
            $placeholders[] = $ph;
            $insertParams[$ph] = $col === 'quote_id' ? $newQuoteId : $row[$col];
        }
        if ($insertCols) {
            $ins = $pdo->prepare("INSERT INTO `" . str_replace('`','',$table) . "`(" . implode(',', $insertCols) . ") VALUES(" . implode(',', $placeholders) . ")");
            $ins->execute($insertParams);
        }
    }
}

$pdo = qvaDb();
$tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : (!empty($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0);
$userId = !empty($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$sessionBranchId = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
if ($tenantId <= 0 || $userId <= 0) qvaOut(401, false, 'Authentication required.');

$csrf = trim((string)qvaPost('csrf_token',''));
$sessionCsrf = isset($_SESSION['quotations_csrf_token']) ? (string)$_SESSION['quotations_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    qvaOut(419, false, 'Your quotation session expired. Refresh the page and try again.');
}

$action = trim((string)qvaPost('action','get'));
$quoteId = (int)qvaPost('quote_id',0);

try {
    if ($action === 'get') {
        if ($quoteId <= 0) qvaOut(422, false, 'Invalid quotation.');
        $quote = qvaQuote($pdo, $tenantId, $quoteId);
        $linkedJob = qvaLinkedJob($pdo, $tenantId, $quoteId);
        $quoteStatus = strtolower((string)$quote['status']);
        $quote['can_convert_to_job'] = (!$linkedJob && !in_array($quoteStatus,array('converted','archived'),true)) ? 1 : 0;
        $quote['can_email'] = !empty($quote['client_email']) ? 1 : 0;
        qvaOut(200, true, 'Quotation loaded successfully.', array(
            'quotation'=>$quote,
            'items'=>qvaItems($pdo,$quoteId),
            'actions'=>qvaActions($pdo,$tenantId,$quoteId),
            'sections'=>qvaSections($pdo,$tenantId,$quoteId),
            'files'=>qvaFiles($pdo,$tenantId,$quoteId),
            'payment_schedule'=>qvaPaymentSchedule($pdo,$tenantId,$quoteId),
            'currency'=>qvaCurrency($pdo,$tenantId,!empty($quote['branch_id'])?(int)$quote['branch_id']:$sessionBranchId),
            'linked_job'=>$linkedJob,
            'signature'=>qvaSignature($pdo,$tenantId,$quoteId)
        ));
    }

    if ($quoteId <= 0) qvaOut(422, false, 'Invalid quotation.');
    $quote = qvaQuote($pdo, $tenantId, $quoteId);
    $branchId = !empty($quote['branch_id']) ? (int)$quote['branch_id'] : $sessionBranchId;


    if ($action === 'send_email') {
        $to=qvaParseEmails(qvaPost('to_emails',''));
        if (!$to) qvaOut(422,false,'Add at least one valid recipient email address.');
        $emailSubject=trim((string)qvaPost('email_subject',''));
        if ($emailSubject==='') qvaOut(422,false,'Email subject is required.');
        $emailMessage=trim((string)qvaPost('email_message',''));
        if ($emailMessage==='') qvaOut(422,false,'Email message is required.');

        if ((string)qvaPost('send_me_copy','')==='1' && qvaTable($pdo,'users')) {
            $me=$pdo->prepare("SELECT email FROM users WHERE id=:u AND tenant_id=:t LIMIT 1");
            $me->execute(array(':u'=>$userId,':t'=>$tenantId));
            $meEmail=strtolower(trim((string)$me->fetchColumn()));
            if ($meEmail!=='' && filter_var($meEmail,FILTER_VALIDATE_EMAIL) && !in_array($meEmail,$to,true)) $to[]=$meEmail;
        }

        $token=null;
        $logIds=array();
        try {
            $cfg=qvaPlatformSmtpConfig($pdo);
            $password=fieldplxDecryptSmtpPassword(isset($cfg['password_encrypted'])?$cfg['password_encrypted']:'');
            if (trim((string)(isset($cfg['username'])?$cfg['username']:''))!=='' && trim((string)$password)==='') {
                throw new RuntimeException('SMTP password is empty or could not be decrypted. Re-enter the password for the default Platform SMTP in Master Controls and test it once.');
            }

            $token=qvaCreatePendingApprovalToken($pdo,$tenantId,$quoteId,(int)$quote['client_id'],14);
            $reviewUrl=qvaAppBaseUrl().'/quotation-response.php?token='.rawurlencode((string)$token['plain']);
            $mailItems=qvaItems($pdo,$quoteId);
            $mailCurrency=qvaCurrency($pdo,$tenantId,$branchId);
            $html=qvaApprovalEmailHtml($quote,$mailItems,$mailCurrency,$emailMessage,$reviewUrl,$token['expires']);

            /* Generate the exact quotation-print.php PDF, same capture pattern as Invoice. */
            $pdf=qvaGenerateQuotationPdfForEmail($quoteId);
            $attachments=array(array('name'=>$pdf['name'],'mime'=>'application/pdf','content'=>$pdf['bytes']));
            $uploaded=qvaEmailAttachmentFiles();
            foreach (qvaBuildUploadedMailAttachments($uploaded) as $extraAttachment) $attachments[]=$extraAttachment;

            $sentRecipients=array();
            $failedRecipients=array();
            foreach ($to as $recipient) {
                $logId=qvaQueueLogCreate($pdo,$tenantId,$branchId,(int)$quote['client_id'],$recipient,$quoteId,$emailSubject,$html,(int)$cfg['id'],'processing','');
                if ($logId>0) $logIds[$recipient]=$logId;
                try {
                    fieldplxSmtpSendWithConfig($cfg,$password,$recipient,substr($emailSubject,0,255),$html,$attachments);
                    if ($logId>0) qvaQueueLogFinish($pdo,$logId,'sent','');
                    $sentRecipients[]=$recipient;
                } catch (Throwable $sendError) {
                    $err='SMTP config #'.(int)$cfg['id'].' ['.(string)$cfg['host'].':'.(int)$cfg['port'].' / '.(string)$cfg['encryption'].'] - '.$sendError->getMessage();
                    if ($logId>0) qvaQueueLogFinish($pdo,$logId,'failed',$err);
                    $failedRecipients[$recipient]=$sendError->getMessage();
                }
            }

            if (!$sentRecipients) {
                throw new RuntimeException('Unable to send the quotation email to the selected recipient(s).'.($failedRecipients?' '.reset($failedRecipients):''));
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE quotation_action_tokens SET used_at=NOW() WHERE tenant_id=:t AND quote_id=:q AND used_at IS NULL AND id<>:id")->execute(array(':t'=>$tenantId,':q'=>$quoteId,':id'=>$token['id']));
                $oldStatus=(string)$quote['status'];
                $newStatus=in_array($oldStatus,array('draft','internal_approval','changes_requested'),true)?'sent':$oldStatus;
                $up=$pdo->prepare("UPDATE quotes SET status=:s,sent_at=COALESCE(sent_at,NOW()),updated_at=NOW() WHERE id=:id AND tenant_id=:t");
                $up->execute(array(':s'=>$newStatus,':id'=>$quoteId,':t'=>$tenantId));
                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $txe;
            }

            qvaLogAction($pdo,$tenantId,$quoteId,$userId,'sent','Quotation approval email sent from quote view.');
            qvaActivity($pdo,$tenantId,$branchId,$userId,$quoteId,(int)$quote['client_id'],'quote_email_sent','Quotation email sent',array(
                'recipients'=>$sentRecipients,
                'failed_recipients'=>array_keys($failedRecipients),
                'subject'=>$emailSubject,
                'pdf_attached'=>1,
                'pdf_name'=>$pdf['name'],
                'pdf_size'=>$pdf['size'],
                'pdf_sha256'=>$pdf['sha256'],
                'smtp_config_id'=>(int)$cfg['id'],
                'approval_expires'=>$token['expires']
            ));
            qvaAudit($pdo,'QUOTE_EMAIL_SENT',$tenantId,$branchId,$userId,$quoteId,array('status'=>$quote['status']),array(
                'status'=>$newStatus,
                'recipients'=>$sentRecipients,
                'failed_recipients'=>array_keys($failedRecipients),
                'subject'=>$emailSubject,
                'pdf_attached'=>1,
                'pdf_name'=>$pdf['name'],
                'pdf_size'=>$pdf['size'],
                'pdf_sha256'=>$pdf['sha256'],
                'smtp_config_id'=>(int)$cfg['id'],
                'approval_expires'=>$token['expires']
            ));

            $message='Quotation email sent successfully with PDF attachment and customer approval link.';
            if ($failedRecipients) $message.=' Some additional recipient(s) could not be sent.';
            qvaOut(200,true,$message,array(
                'status'=>$newStatus,
                'pdf_attached'=>1,
                'pdf_name'=>$pdf['name'],
                'pdf_size'=>(int)$pdf['size'],
                'pdf_sha256'=>$pdf['sha256'],
                'smtp_config_id'=>(int)$cfg['id'],
                'approval_expires'=>$token['expires'],
                'sent_recipients'=>$sentRecipients,
                'failed_recipients'=>array_keys($failedRecipients)
            ));
        } catch (Throwable $mailError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($token && !empty($token['id'])) {
                try {
                    $pdo->prepare("DELETE FROM quotation_action_tokens WHERE id=:id AND tenant_id=:t AND quote_id=:q AND used_at IS NULL")->execute(array(':id'=>$token['id'],':t'=>$tenantId,':q'=>$quoteId));
                } catch (Throwable $cleanupError) {
                    error_log('quotation view approval token cleanup: '.$cleanupError->getMessage());
                }
            }
            throw $mailError;
        }
    }

    if ($action === 'save_notes') {
        if (!qvaColumn($pdo,'quotes','internal_notes')) qvaOut(422,false,'Internal notes are not enabled for quotations.');
        $note = trim((string)qvaPost('internal_notes',''));
        if (strlen($note) > 8000) qvaOut(422,false,'Internal note is too long.');
        $set = 'internal_notes=:n';
        if (qvaColumn($pdo,'quotes','updated_at')) $set .= ',updated_at=NOW()';
        $st = $pdo->prepare("UPDATE quotes SET ".$set." WHERE id=:q AND tenant_id=:t");
        $st->execute(array(':n'=>$note!==''?$note:null,':q'=>$quoteId,':t'=>$tenantId));
        qvaLogAction($pdo,$tenantId,$quoteId,$userId,'commented','Internal quote note updated.');
        qvaActivity($pdo,$tenantId,$branchId,$userId,$quoteId,(int)$quote['client_id'],'quote_internal_note_updated','Quotation internal note updated',array('has_note'=>$note!==''?1:0));
        qvaAudit($pdo,'QUOTE_INTERNAL_NOTE_UPDATED',$tenantId,$branchId,$userId,$quoteId,array('internal_notes'=>$quote['internal_notes']??null),array('internal_notes'=>$note!==''?$note:null));
        qvaOut(200,true,'Internal note saved successfully.',array('internal_notes'=>$note));
    }

    if ($action === 'save_client_view') {
        if (!qvaColumn($pdo,'quotes','client_view_options_json')) qvaOut(422,false,'Customer view settings are not enabled for quotations.');
        $raw = trim((string)qvaPost('client_view_options_json','{}'));
        $decoded = json_decode($raw,true);
        if (!is_array($decoded)) qvaOut(422,false,'Customer view settings are invalid.');
        $clean = array();
        foreach (array('quantities','unit_prices','line_item_totals','totals') as $key) {
            $clean[$key] = !array_key_exists($key,$decoded) || (bool)$decoded[$key];
        }
        $json = json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $set = 'client_view_options_json=:j';
        if (qvaColumn($pdo,'quotes','updated_at')) $set .= ',updated_at=NOW()';
        $st = $pdo->prepare("UPDATE quotes SET ".$set." WHERE id=:q AND tenant_id=:t");
        $st->execute(array(':j'=>$json,':q'=>$quoteId,':t'=>$tenantId));
        qvaActivity($pdo,$tenantId,$branchId,$userId,$quoteId,(int)$quote['client_id'],'quote_customer_view_updated','Quotation customer view updated',$clean);
        qvaAudit($pdo,'QUOTE_CUSTOMER_VIEW_UPDATED',$tenantId,$branchId,$userId,$quoteId,array('client_view_options_json'=>$quote['client_view_options_json']??null),array('client_view_options_json'=>$json));
        qvaOut(200,true,'Customer view settings saved successfully.',array('client_view_options'=>$clean));
    }

    if ($action === 'set_status') {
        $requested = strtolower(trim((string)qvaPost('status','')));
        $map = array('awaiting_response'=>'sent','sent'=>'sent','approved'=>'approved','rejected'=>'rejected','draft'=>'draft','archived'=>'archived');
        if (!isset($map[$requested])) qvaOut(422,false,'Select a valid quotation status.');
        if (strtolower((string)$quote['status']) === 'converted') qvaOut(422,false,'Converted quotations cannot be changed.');
        $target = $map[$requested];
        $set = array('status=:status');
        $params = array(':status'=>$target, ':id'=>$quoteId, ':t'=>$tenantId);
        if ($target === 'sent') $set[] = 'sent_at=COALESCE(sent_at,NOW())';
        if ($target === 'approved') $set[] = 'approved_at=COALESCE(approved_at,NOW())';
        if ($target === 'draft') {
            $set[] = 'sent_at=NULL';
            $set[] = 'viewed_at=NULL';
            $set[] = 'approved_at=NULL';
        }
        $st = $pdo->prepare("UPDATE quotes SET " . implode(',', $set) . " WHERE id=:id AND tenant_id=:t");
        $st->execute($params);
        if (in_array($target,array('sent','approved','rejected'),true)) qvaLogAction($pdo,$tenantId,$quoteId,$userId,$target,'Status changed from quotation view.');
        qvaActivity($pdo,$tenantId,$branchId,$userId,$quoteId,(int)$quote['client_id'],'quote_status_changed','Quotation status changed',array('old_status'=>$quote['status'],'new_status'=>$target));
        qvaAudit($pdo,'QUOTE_STATUS_CHANGED',$tenantId,$branchId,$userId,$quoteId,array('status'=>$quote['status']),array('status'=>$target));
        qvaOut(200,true,$requested==='awaiting_response'?'Quotation marked Awaiting Response.':'Quotation status updated.',array('status'=>$target));
    }

    if ($action === 'create_similar') {
        $linked = qvaLinkedJob($pdo,$tenantId,$quoteId);
        $pdo->beginTransaction();
        try {
            $newNo = qvaNextQuoteNo($pdo,$tenantId,$branchId);
            $columns = qvaColumns($pdo,'quotes');
            $copyable = array('tenant_id','branch_id','quote_no','revision_no','parent_quote_id','client_id','location_id','request_id','assessment_id','assessment_reschedule_id','salesperson_id','title','introduction','introduction_title','client_message','disclaimer','custom_fields_json','client_view_options_json','payment_plan_mode','payment_plan_split_type','link_notes_to_jobs','link_notes_to_invoices','status','subtotal','discount_total','tax_total','total','deposit_required','deposit_type','deposit_value','deposit_amount','valid_until','created_by');
            $insertCols = array(); $placeholders = array(); $params = array();
            foreach ($copyable as $col) {
                if (!in_array($col,$columns,true)) continue;
                $insertCols[] = '`'.$col.'`';
                $ph = ':p'.count($params);
                $placeholders[] = $ph;
                if ($col === 'tenant_id') $value = $tenantId;
                elseif ($col === 'quote_no') $value = $newNo;
                elseif ($col === 'revision_no') $value = 0;
                elseif ($col === 'parent_quote_id') $value = $quoteId;
                elseif ($col === 'status') $value = 'draft';
                elseif ($col === 'created_by') $value = $userId;
                else $value = array_key_exists($col,$quote) ? $quote[$col] : null;
                $params[$ph] = $value;
            }
            $ins = $pdo->prepare("INSERT INTO quotes(".implode(',',$insertCols).") VALUES(".implode(',',$placeholders).")");
            $ins->execute($params);
            $newId = (int)$pdo->lastInsertId();
            qvaCloneChildTable($pdo,'quote_line_items',$quoteId,$newId,$tenantId);
            qvaCloneChildTable($pdo,'quote_sections',$quoteId,$newId,$tenantId);
            qvaCloneChildTable($pdo,'quote_custom_fields',$quoteId,$newId,$tenantId);
            qvaCloneChildTable($pdo,'quote_payment_schedule_items',$quoteId,$newId,$tenantId);
            $pdo->commit();
            qvaActivity($pdo,$tenantId,$branchId,$userId,$newId,(int)$quote['client_id'],'quote_created_similar','Similar quotation created',array('source_quote_id'=>$quoteId,'source_quote_no'=>$quote['quote_no'],'new_quote_no'=>$newNo));
            qvaAudit($pdo,'QUOTE_CREATED_SIMILAR',$tenantId,$branchId,$userId,$newId,null,array('source_quote_id'=>$quoteId,'quote_no'=>$newNo));
            qvaOut(200,true,'Similar quotation created as a new draft.',array('quote_id'=>$newId,'quote_no'=>$newNo,'edit_url'=>'add-quotation.php?quote_id='.$newId));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'save_signature') {
        if (!qvaTable($pdo,'attachments')) qvaOut(500,false,'Attachments table is not available.');
        $dataUrl = trim((string)qvaPost('signature_data',''));
        if (!preg_match('#^data:image/png;base64,(.+)$#s',$dataUrl,$m)) qvaOut(422,false,'Draw a signature before submitting.');
        $binary = base64_decode($m[1],true);
        if ($binary === false || strlen($binary) < 50) qvaOut(422,false,'The signature image is invalid.');
        if (strlen($binary) > 3*1024*1024) qvaOut(422,false,'Signature image is too large.');
        $relativeDir = 'uploads/quotes/'.$tenantId.'/'.$quoteId;
        $absoluteDir = dirname(__DIR__).'/'.$relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir,0775,true) && !is_dir($absoluteDir)) throw new RuntimeException('Unable to create quotation upload folder.');
        $fileName = 'signature-'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'.png';
        $relativePath = $relativeDir.'/'.$fileName;
        $absolutePath = dirname(__DIR__).'/'.$relativePath;
        if (@file_put_contents($absolutePath,$binary) === false) throw new RuntimeException('Unable to save the signature file.');
        $st = $pdo->prepare("INSERT INTO attachments(tenant_id,related_type,related_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type,description,created_at) VALUES(:t,'quote',:q,:u,:n,:p,'image/png',:s,'signature','Quotation client signature',NOW())");
        $st->execute(array(':t'=>$tenantId,':q'=>$quoteId,':u'=>$userId,':n'=>$fileName,':p'=>$relativePath,':s'=>strlen($binary)));
        $attachmentId = (int)$pdo->lastInsertId();
        $emailNotice = '';
        $emailFailed = 0;
        if ((string)qvaPost('send_copy','') === '1') {
            $to = strtolower(trim((string)$quote['client_email']));
            if ($to !== '' && filter_var($to,FILTER_VALIDATE_EMAIL)) {
                try {
                    $mailItems = qvaItems($pdo,$quoteId);
                    $mailCurrency = qvaCurrency($pdo,$tenantId,$branchId);
                    $mail = qvaCreateMailer($pdo,$tenantId,$branchId);
                    $mail->addAddress($to);
                    $company = !empty($quote['branch_name']) ? $quote['branch_name'] : (!empty($quote['tenant_name']) ? $quote['tenant_name'] : 'FieldPlx');
                    $mail->isHTML(true);
                    $mail->Subject = 'Signed quotation '.$quote['quote_no'].' - '.$company;
                    $mail->Body = qvaQuoteEmailHtml($quote,$mailItems,$mailCurrency,'A signature has been collected for this quotation. Please keep this copy for your records.');
                    $mail->AltBody = 'A signature has been collected for quotation '.$quote['quote_no'].'. Total '.qvaMoney($quote['total'],$mailCurrency).'.';
                    $pdfAttached = qvaAttachQuotePdfIfAvailable($mail,$quote,$mailItems,$mailCurrency);
                    $mail->send();
                    $emailNotice = 'A copy was emailed to '.$to.'.';
                    qvaActivity($pdo,$tenantId,$branchId,$userId,$quoteId,(int)$quote['client_id'],'quote_signature_copy_sent','Signed quotation copy sent',array('recipient'=>$to,'pdf_attached'=>$pdfAttached?1:0));
                } catch (Throwable $mailError) {
                    $emailFailed = 1;
                    $emailNotice = 'Signature saved, but the customer copy could not be emailed.';
                    error_log('quotation signature copy email: '.$mailError->getMessage());
                }
            } else {
                $emailFailed = 1;
                $emailNotice = 'Signature saved, but the customer does not have a valid email address.';
            }
        }
        qvaActivity($pdo,$tenantId,$branchId,$userId,$quoteId,(int)$quote['client_id'],'quote_signature_collected','Quotation signature collected',array('attachment_id'=>$attachmentId));
        qvaAudit($pdo,'QUOTE_SIGNATURE_COLLECTED',$tenantId,$branchId,$userId,$quoteId,null,array('signature'=>'saved','send_copy'=>(string)qvaPost('send_copy','')==='1'?1:0,'email_failed'=>$emailFailed));
        qvaOut(200,true,'Signature saved successfully.',array('file_path'=>$relativePath,'email_notice'=>$emailNotice,'email_failed'=>$emailFailed));
    }

    if ($action === 'delete') {
        $linked = qvaLinkedJob($pdo,$tenantId,$quoteId);
        if ($linked) qvaOut(422,false,'This quotation is linked to job '.$linked['job_no'].' and cannot be deleted. Archive it instead.');
        $pdo->beginTransaction();
        try {
            if (qvaTable($pdo,'attachments')) $pdo->prepare("DELETE FROM attachments WHERE tenant_id=:t AND related_type='quote' AND related_id=:q")->execute(array(':t'=>$tenantId,':q'=>$quoteId));
            foreach (array('quotation_action_tokens','quote_actions','quote_files','quote_sections','quote_custom_fields','quote_line_items') as $table) {
                if (!qvaTable($pdo,$table)) continue;
                $sql = "DELETE FROM `".$table."` WHERE quote_id=:q";
                $params = array(':q'=>$quoteId);
                if (qvaColumn($pdo,$table,'tenant_id')) { $sql .= " AND tenant_id=:t"; $params[':t']=$tenantId; }
                $pdo->prepare($sql)->execute($params);
            }
            $del = $pdo->prepare("DELETE FROM quotes WHERE id=:q AND tenant_id=:t");
            $del->execute(array(':q'=>$quoteId,':t'=>$tenantId));
            if ($del->rowCount() !== 1) throw new RuntimeException('Quotation could not be deleted.');
            $pdo->commit();
            qvaAudit($pdo,'QUOTE_DELETED',$tenantId,$branchId,$userId,$quoteId,$quote,null);
            qvaOut(200,true,'Quotation deleted successfully.',array('redirect'=>'quotations'));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    qvaOut(400,false,'Unsupported quotation action.');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('quotation view actions PDO: '.$e->getMessage());
    qvaOut(500,false,'Unable to process the quotation request.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('quotation view actions: '.$e->getMessage());
    qvaOut(500,false,$e->getMessage());
}
