<?php
/* FieldPlx Quotations API - Version 3.2.1 - 2026-09-09 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php'))
    require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/platform-smtp.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* FieldPlx Quotations API v3.2.1 - Invoice-reference Platform SMTP + PDF quotation approval sender */
if (!defined('FIELDPLX_QUOTATIONS_API_VERSION')) {
    define('FIELDPLX_QUOTATIONS_API_VERSION', '3.2.1');
}


function qRes($code, $ok, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int) $code);
    echo json_encode(array_merge(array('success' => (bool) $ok, 'message' => (string) $message, 'api_version' => FIELDPLX_QUOTATIONS_API_VERSION), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function qP($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}
function qCol(PDO $pdo, $table, $column)
{
    static $c = array();
    $k = $table . '.' . $column;
    if (isset($c[$k]))
        return $c[$k];
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(array(':t' => $table, ':c' => $column));
    $c[$k] = (int) $s->fetchColumn() > 0;
    return $c[$k];
}
function qTable(PDO $pdo, $table)
{
    static $c = array();
    if (isset($c[$table]))
        return $c[$table];
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(array(':t' => $table));
    $c[$table] = (int) $s->fetchColumn() > 0;
    return $c[$table];
}
function qCurrency(PDO $pdo, $tenant)
{
    $s = $pdo->prepare("SELECT c.id,c.currency_code,c.currency_name,c.symbol,c.symbol_position,c.decimal_places,c.decimal_separator,c.thousand_separator FROM tenants t INNER JOIN currencies c ON c.id=t.currency_id WHERE t.id=:t LIMIT 1");
    $s->execute(array(':t' => $tenant));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : array('id' => null, 'currency_code' => '', 'currency_name' => '', 'symbol' => '', 'symbol_position' => 'before', 'decimal_places' => 2, 'decimal_separator' => '.', 'thousand_separator' => ',');
}
function qValidRequest(PDO $pdo, $tenant, $id)
{
    if ($id <= 0)
        return null;
    $s = $pdo->prepare("SELECT r.*,c.display_name client_name,c.email client_email,c.phone client_phone,cl.name location_name,ps.name service_name FROM service_requests r INNER JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id LEFT JOIN client_locations cl ON cl.id=r.location_id AND cl.tenant_id=r.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=r.tenant_id WHERE r.id=:id AND r.tenant_id=:t AND r.status NOT IN('closed','cancelled') LIMIT 1");
    $s->execute(array(':id' => $id, ':t' => $tenant));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function qValidRevisit(PDO $pdo, $tenant, $requestId, $revisitId)
{
    if ($revisitId <= 0 || !qTable($pdo, 'assessment_reschedule_history'))
        return null;
    $s = $pdo->prepare("SELECT h.* FROM assessment_reschedule_history h WHERE h.id=:id AND h.tenant_id=:t AND h.request_id=:r LIMIT 1");
    $s->execute(array(':id' => $revisitId, ':t' => $tenant, ':r' => $requestId));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function qClient(PDO $pdo, $tenant, $id)
{
    if ($id <= 0)
        return null;
    $s = $pdo->prepare("SELECT id,branch_id,display_name,email,phone,status FROM clients WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL AND status<>'archived' LIMIT 1");
    $s->execute(array(':id' => $id, ':t' => $tenant));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function qLocation(PDO $pdo, $tenant, $clientId, $id)
{
    if ($id <= 0)
        return null;
    $s = $pdo->prepare("SELECT id,client_id,name,address_line1,city,state,postal_code FROM client_locations WHERE id=:id AND tenant_id=:t AND client_id=:c AND deleted_at IS NULL AND status='active' LIMIT 1");
    $s->execute(array(':id' => $id, ':t' => $tenant, ':c' => $clientId));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function qNext(PDO $pdo, $tenant, $branch)
{
    $sep = qCol($pdo, 'document_sequences', 'number_separator') ? 'number_separator' : 'separator';
    $s = $pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type='quote' AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1 FOR UPDATE");
    $s->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : 0, ':b2' => $branch > 0 ? $branch : 0));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        $q = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(quote_no,'-',-1) AS UNSIGNED)) FROM quotes WHERE tenant_id=:t AND quote_no LIKE 'QUO-%'");
        $q->execute(array(':t' => $tenant));
        return 'QUO-' . str_pad((string) ((int) $q->fetchColumn() + 1), 6, '0', STR_PAD_LEFT);
    }
    $now = new DateTime('now');
    $y = $now->format('Y');
    $mo = $now->format('m');
    $fyStart = max(1, min(12, (int) $r['financial_year_start_month']));
    $fyY = (int) $now->format('n') >= $fyStart ? (int) $y : (int) $y - 1;
    $fy = $fyY . '-' . substr((string) ($fyY + 1), -2);
    $key = 'never';
    if ($r['reset_period'] === 'monthly')
        $key = $y . $mo;
    elseif ($r['reset_period'] === 'yearly')
        $key = $y;
    elseif ($r['reset_period'] === 'financial_year')
        $key = $fy;
    $cur = (int) $r['current_number'];
    if ($r['reset_period'] !== 'never' && (string) $r['last_reset_key'] !== (string) $key)
        $cur = 0;
    $next = $cur + 1;
    $mid = '';
    if ($r['middle_format'] === 'year')
        $mid = $y;
    elseif ($r['middle_format'] === 'year_month')
        $mid = $y . $mo;
    elseif ($r['middle_format'] === 'financial_year')
        $mid = $fy;
    elseif ($r['middle_format'] === 'branch_year')
        $mid = (!empty($r['branch_code']) ? $r['branch_code'] : 'BR') . $y;
    $parts = array();
    if (!empty($r['prefix']))
        $parts[] = $r['prefix'];
    if ($mid !== '')
        $parts[] = $mid;
    $parts[] = str_pad((string) $next, max(1, (int) $r['number_length']), '0', STR_PAD_LEFT);
    if (!empty($r['suffix']))
        $parts[] = $r['suffix'];
    $no = implode(isset($r[$sep]) ? (string) $r[$sep] : '-', $parts);
    $u = $pdo->prepare("UPDATE document_sequences SET current_number=:n,last_reset_key=:k WHERE id=:id");
    $u->execute(array(':n' => $next, ':k' => $key, ':id' => $r['id']));
    return $no;
}

function qPreviewNext(PDO $pdo, $tenant, $branch)
{
    $sep = qCol($pdo, 'document_sequences', 'number_separator') ? 'number_separator' : 'separator';
    $s = $pdo->prepare("SELECT ds.*,b.branch_code FROM document_sequences ds LEFT JOIN branches b ON b.id=ds.branch_id AND b.tenant_id=ds.tenant_id WHERE ds.tenant_id=:t AND ds.document_type='quote' AND ds.is_active=1 AND (ds.branch_id=:b OR ds.branch_id IS NULL) ORDER BY CASE WHEN ds.branch_id=:b2 THEN 0 ELSE 1 END,ds.id LIMIT 1");
    $s->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : 0, ':b2' => $branch > 0 ? $branch : 0));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        $q = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(quote_no,'-',-1) AS UNSIGNED)) FROM quotes WHERE tenant_id=:t AND quote_no LIKE 'QUO-%'");
        $q->execute(array(':t' => $tenant));
        return 'QUO-' . str_pad((string) ((int) $q->fetchColumn() + 1), 6, '0', STR_PAD_LEFT);
    }
    $now = new DateTime('now');
    $y = $now->format('Y');
    $mo = $now->format('m');
    $fyStart = max(1, min(12, (int) $r['financial_year_start_month']));
    $fyY = (int) $now->format('n') >= $fyStart ? (int) $y : (int) $y - 1;
    $fy = $fyY . '-' . substr((string) ($fyY + 1), -2);
    $key = 'never';
    if ($r['reset_period'] === 'monthly') $key = $y . $mo;
    elseif ($r['reset_period'] === 'yearly') $key = $y;
    elseif ($r['reset_period'] === 'financial_year') $key = $fy;
    $cur = (int) $r['current_number'];
    if ($r['reset_period'] !== 'never' && (string) $r['last_reset_key'] !== (string) $key) $cur = 0;
    $next = $cur + 1;
    $mid = '';
    if ($r['middle_format'] === 'year') $mid = $y;
    elseif ($r['middle_format'] === 'year_month') $mid = $y . $mo;
    elseif ($r['middle_format'] === 'financial_year') $mid = $fy;
    elseif ($r['middle_format'] === 'branch_year') $mid = (!empty($r['branch_code']) ? $r['branch_code'] : 'BR') . $y;
    $parts = array();
    if (!empty($r['prefix'])) $parts[] = $r['prefix'];
    if ($mid !== '') $parts[] = $mid;
    $parts[] = str_pad((string) $next, max(1, (int) $r['number_length']), '0', STR_PAD_LEFT);
    if (!empty($r['suffix'])) $parts[] = $r['suffix'];
    return implode(isset($r[$sep]) ? (string) $r[$sep] : '-', $parts);
}

function qQuoteOwned(PDO $pdo, $tenant, $quoteId)
{
    if ($quoteId <= 0) return false;
    $s = $pdo->prepare("SELECT id FROM quotes WHERE id=:q AND tenant_id=:t LIMIT 1");
    $s->execute(array(':q'=>$quoteId, ':t'=>$tenant));
    return (bool)$s->fetchColumn();
}

function qQuoteContext(PDO $pdo, $tenant, $quoteId)
{
    if ($quoteId <= 0) return null;
    $s=$pdo->prepare("SELECT id,quote_no,client_id,branch_id FROM quotes WHERE id=:q AND tenant_id=:t LIMIT 1");
    $s->execute(array(':q'=>$quoteId,':t'=>$tenant));
    $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

function qUploadDirectory($tenant, $quoteId)
{
    $base = dirname(__DIR__) . '/uploads/quotations/' . (int)$tenant . '/' . (int)$quoteId;
    if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
        throw new RuntimeException('Unable to create quotation upload directory.');
    }
    return $base;
}

function qSafeFileName($name)
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string)$name));
    $name = trim((string)$name, '-.');
    return $name !== '' ? $name : 'file';
}


function qStoreCatalogImage($field, $tenant, $kind)
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return array('relative'=>null,'absolute'=>null);
    $file = $_FILES[$field];
    $error = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) return array('relative'=>null,'absolute'=>null);
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Unable to upload the product / service image.');
    $size = isset($file['size']) ? (int)$file['size'] : 0;
    if ($size <= 0 || $size > 4 * 1024 * 1024) throw new RuntimeException('Product / service image must be 4 MB or smaller.');
    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Uploaded product / service image is invalid.');
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string)@finfo_file($fi, $tmp); @finfo_close($fi); }
    }
    $allowed = array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp');
    if (!isset($allowed[$mime])) throw new RuntimeException('Product / service image must be JPG, PNG or WEBP.');
    $folder = $kind === 'service' ? 'services' : 'products';
    $relativeDir = 'uploads/product-masters/tenant-' . (int)$tenant . '/' . $folder;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) throw new RuntimeException('Unable to create the product / service image upload folder.');
    $prefix = $kind === 'service' ? 'service' : 'product';
    $fileName = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $absolutePath = $absoluteDir . '/' . $fileName;
    if (!@move_uploaded_file($tmp, $absolutePath)) throw new RuntimeException('Unable to save the product / service image.');
    return array('relative'=>$relativeDir . '/' . $fileName,'absolute'=>$absolutePath);
}

function qLog(PDO $pdo, $tenant, $branch, $user, $quoteId, $client, $type, $title, $details)
{
    try {
        $s = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user',:e,'quote',:rid,:cid,:title,:d,0)");
        $s->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : null, ':u' => $user, ':e' => $type, ':rid' => $quoteId, ':cid' => $client, ':title' => substr($title, 0, 255), ':d' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    } catch (Throwable $e) {
        error_log('quote activity ' . $e->getMessage());
    }
}
function qProducts(PDO $pdo, $tenant)
{
    if (!qTable($pdo, 'products'))
        return array();
    $imageSelect = qCol($pdo, 'products', 'image_path') ? ',image_path' : ',NULL AS image_path';
    $s = $pdo->prepare("SELECT id,sku,name,description,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_percent" . $imageSelect . " FROM products WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY name");
    $s->execute(array(':t' => $tenant));
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function qCreateApprovalToken(PDO $pdo, $tenant, $quoteId, $clientId, $days)
{
    $plain = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $expires = date('Y-m-d H:i:s', strtotime('+' . max(1, (int) $days) . ' days'));
    $pdo->prepare("UPDATE quotation_action_tokens SET used_at=NOW() WHERE tenant_id=:t AND quote_id=:q AND used_at IS NULL")->execute(array(':t' => $tenant, ':q' => $quoteId));
    $s = $pdo->prepare("INSERT INTO quotation_action_tokens(tenant_id,quote_id,client_id,token_hash,expires_at) VALUES(:t,:q,:c,:h,:e)");
    $s->execute(array(':t' => $tenant, ':q' => $quoteId, ':c' => $clientId, ':h' => $hash, ':e' => $expires));
    return array('plain' => $plain, 'expires' => $expires);
}

function qCreatePendingApprovalToken(PDO $pdo, $tenant, $quoteId, $clientId, $days)
{
    $plain = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $expires = date('Y-m-d H:i:s', strtotime('+' . max(1, (int) $days) . ' days'));
    $s = $pdo->prepare("INSERT INTO quotation_action_tokens(tenant_id,quote_id,client_id,token_hash,expires_at) VALUES(:t,:q,:c,:h,:e)");
    $s->execute(array(':t'=>$tenant, ':q'=>$quoteId, ':c'=>$clientId, ':h'=>$hash, ':e'=>$expires));
    return array('id'=>(int)$pdo->lastInsertId(), 'plain'=>$plain, 'expires'=>$expires);
}



/* --------------------------------------------------------------------------
 * Platform SMTP helper
 * Follow the Invoice API exactly: use includes/platform-smtp.php only.
 * Do not load/decrypt SMTP secrets with quotation-specific code.
 * -------------------------------------------------------------------------- */
function qSmtpConfig(PDO $pdo)
{
    return fieldplxPlatformSmtpConfig($pdo);
}

function qNotificationEventId(PDO $pdo, $eventKey)
{
    if (!qTable($pdo, 'notification_events')) return 0;
    $s = $pdo->prepare("SELECT id FROM notification_events WHERE event_key=:k AND is_active=1 ORDER BY id LIMIT 1");
    $s->execute(array(':k'=>$eventKey));
    return (int)$s->fetchColumn();
}

function qQueueLogCreate(PDO $pdo, $tenant, $branch, $clientId, $email, $quoteId, $subject, $body, $smtpId, $status, $error)
{
    if (!qTable($pdo, 'notification_queue')) return 0;
    $eventId = qNotificationEventId($pdo, 'quote.sent');
    if ($eventId <= 0) return 0;
    $allowed = array('queued','processing','sent','delivered','read','failed','suppressed');
    if (!in_array($status, $allowed, true)) $status = 'processing';
    $attempts = in_array($status, array('processing','sent','failed'), true) ? 1 : 0;
    try {
        $stmt = $pdo->prepare("INSERT INTO notification_queue(tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address,related_type,related_id,subject,body,smtp_config_id,status,attempts,scheduled_at,sent_at,error_message,created_at) VALUES(:t,:b,:e,'email','client',:c,:addr,'quote',:rid,:sub,:body,:smtp,:st,:a,NOW(),:sent,:err,NOW())");
        $stmt->execute(array(':t'=>$tenant, ':b'=>$branch>0?$branch:null, ':e'=>$eventId, ':c'=>$clientId>0?$clientId:null, ':addr'=>$email!==''?$email:null, ':rid'=>$quoteId, ':sub'=>$subject!==''?substr($subject,0,255):null, ':body'=>(string)$body, ':smtp'=>$smtpId>0?$smtpId:null, ':st'=>$status, ':a'=>$attempts, ':sent'=>$status==='sent'?date('Y-m-d H:i:s'):null, ':err'=>$error!==''?$error:null));
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('quotation notification queue insert ' . $e->getMessage());
        return 0;
    }
}

function qQueueLogFinish(PDO $pdo, $logId, $status, $error)
{
    if ($logId <= 0 || !qTable($pdo, 'notification_queue')) return;
    if (!in_array($status, array('sent','failed','suppressed'), true)) $status='failed';
    try {
        $s=$pdo->prepare("UPDATE notification_queue SET status=:s,sent_at=CASE WHEN :sent='sent' THEN NOW() ELSE NULL END,error_message=:e WHERE id=:id LIMIT 1");
        $s->execute(array(':s'=>$status, ':sent'=>$status, ':e'=>$error!==''?$error:null, ':id'=>$logId));
    } catch (Throwable $e) { error_log('quotation notification queue update '.$e->getMessage()); }
}

function qValidMentionUsers(PDO $pdo, $tenant, $ids)
{
    $clean=array();
    foreach ((array)$ids as $id) { $id=(int)$id; if ($id>0) $clean[$id]=$id; }
    if (!$clean || !qTable($pdo,'users')) return array();
    $params=array(':t'=>$tenant); $marks=array(); $n=0;
    foreach ($clean as $id) { $k=':u'.$n++; $marks[]=$k; $params[$k]=$id; }
    $sql="SELECT id,first_name,last_name,email,job_title FROM users WHERE tenant_id=:t AND status='active' AND id IN (".implode(',',$marks).")";
    if (qCol($pdo,'users','deleted_at')) $sql.=" AND deleted_at IS NULL";
    $st=$pdo->prepare($sql); $st->execute($params); $out=array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']]=$r;
    return $out;
}

function qNotifyQuoteMentions(PDO $pdo, $tenant, $branch, $actorUser, $quoteId, $quoteNo, $mentionedUsers)
{
    if (!$mentionedUsers || !qTable($pdo,'notification_queue')) return;
    $eventId=qNotificationEventId($pdo,'quote.note_mentioned');
    if ($eventId<=0) return;
    $actorName='A team member';
    try {
        $s=$pdo->prepare("SELECT first_name,last_name FROM users WHERE id=:u AND tenant_id=:t LIMIT 1");
        $s->execute(array(':u'=>$actorUser,':t'=>$tenant)); $r=$s->fetch(PDO::FETCH_ASSOC);
        if ($r) { $n=trim((string)$r['first_name'].' '.(string)$r['last_name']); if ($n!=='') $actorName=$n; }
        $ins=$pdo->prepare("INSERT INTO notification_queue(tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,recipient_address,related_type,related_id,subject,body,smtp_config_id,status,attempts,scheduled_at,sent_at,error_message,created_at) VALUES(:t,:b,:e,'in_app','user',:u,NULL,'quote',:rid,:sub,:body,NULL,'queued',0,NOW(),NULL,NULL,NOW())");
        foreach ($mentionedUsers as $uid=>$row) {
            $uid=(int)$uid; if ($uid<=0 || $uid===(int)$actorUser) continue;
            $ins->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':e'=>$eventId,':u'=>$uid,':rid'=>$quoteId,':sub'=>'Mentioned in quote note',':body'=>$actorName.' mentioned you in an internal note on quote '.$quoteNo.'.'));
        }
    } catch (Throwable $e) { error_log('quote note mention notification '.$e->getMessage()); }
}

function qAppBaseUrl()
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $https = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
    if ($host === '') throw new RuntimeException('Unable to determine application URL for quotation approval link.');
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '/business/api/quotations.php';
    $root = dirname(dirname(dirname($script)));
    if ($root === '/' || $root === '.' || $root === '\\') $root = '';
    return $scheme . '://' . $host . rtrim($root, '/');
}

function qMoney($value, array $currency)
{
    $dp = isset($currency['decimal_places']) ? max(0, (int) $currency['decimal_places']) : 2;
    $dec = isset($currency['decimal_separator']) && $currency['decimal_separator'] !== '' ? $currency['decimal_separator'] : '.';
    $th = isset($currency['thousand_separator']) ? $currency['thousand_separator'] : ',';
    $n = number_format((float) $value, $dp, $dec, $th);
    $symbol = isset($currency['symbol']) ? trim((string) $currency['symbol']) : '';
    if ($symbol === '') return $n;
    return (isset($currency['symbol_position']) && $currency['symbol_position'] === 'after') ? $n . ' ' . $symbol : $symbol . ' ' . $n;
}

function qGenerateQuotationPdfForEmail($quoteId)
{
    $quoteId = (int)$quoteId;
    if ($quoteId <= 0) {
        throw new RuntimeException('Invalid quotation selected for PDF attachment.');
    }

    $printFile = dirname(__DIR__) . '/quotation-print.php';
    if (!is_file($printFile)) {
        throw new RuntimeException('quotation-print.php was not found, so the quotation PDF could not be attached.');
    }

    if (!defined('FIELDPLX_QUOTE_PDF_CAPTURE')) {
        define('FIELDPLX_QUOTE_PDF_CAPTURE', true);
    }

    $oldQuoteIdExists = array_key_exists('quote_id', $_GET);
    $oldQuoteId = $oldQuoteIdExists ? $_GET['quote_id'] : null;
    $oldIdExists = array_key_exists('id', $_GET);
    $oldId = $oldIdExists ? $_GET['id'] : null;

    unset($GLOBALS['fieldplx_quote_pdf_bytes'], $GLOBALS['fieldplx_quote_pdf_name']);
    $_GET['quote_id'] = $quoteId;
    unset($_GET['id']);

    $bufferLevel = ob_get_level();
    ob_start();

    try {
        include $printFile;
    } catch (Throwable $e) {
        while (ob_get_level() > $bufferLevel) {
            @ob_end_clean();
        }
        if ($oldQuoteIdExists) $_GET['quote_id'] = $oldQuoteId; else unset($_GET['quote_id']);
        if ($oldIdExists) $_GET['id'] = $oldId; else unset($_GET['id']);
        throw new RuntimeException('Unable to generate the quotation PDF attachment: ' . $e->getMessage(), 0, $e);
    }

    while (ob_get_level() > $bufferLevel) {
        @ob_end_clean();
    }

    if ($oldQuoteIdExists) $_GET['quote_id'] = $oldQuoteId; else unset($_GET['quote_id']);
    if ($oldIdExists) $_GET['id'] = $oldId; else unset($_GET['id']);

    $bytes = isset($GLOBALS['fieldplx_quote_pdf_bytes']) ? $GLOBALS['fieldplx_quote_pdf_bytes'] : '';
    $name = isset($GLOBALS['fieldplx_quote_pdf_name']) ? $GLOBALS['fieldplx_quote_pdf_name'] : ('Quote-' . $quoteId . '.pdf');
    unset($GLOBALS['fieldplx_quote_pdf_bytes'], $GLOBALS['fieldplx_quote_pdf_name']);

    if (!is_string($bytes) || strlen($bytes) < 100 || substr($bytes, 0, 4) !== '%PDF') {
        throw new RuntimeException('The quotation print page did not return a valid PDF document.');
    }

    return array(
        'bytes' => $bytes,
        'name' => $name,
        'size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes)
    );
}

function qSendQuotationEmail(PDO $pdo, $tenant, $branch, array $quote, array $client, array $items, array $currency, $plainToken)
{
    $out = array(
        'status' => 'suppressed',
        'notice' => '',
        'to' => '',
        'mail_log_id' => 0,
        'smtp_id' => 0,
        'smtp_scope' => 'platform',
        'smtp_is_default' => 0,
        'pdf_attached' => 0,
        'pdf_name' => '',
        'pdf_size' => 0,
        'pdf_sha256' => '',
        'approval_url' => '',
        'approval_expires' => isset($quote['approval_expires']) ? (string)$quote['approval_expires'] : ''
    );

    $to = trim((string)(isset($client['email']) ? $client['email'] : ''));
    $out['to'] = $to;
    $clientId = isset($client['id']) ? (int)$client['id'] : 0;
    $quoteId = isset($quote['id']) ? (int)$quote['id'] : 0;
    $quoteNo = (string)(isset($quote['quote_no']) ? $quote['quote_no'] : '');
    $subject = 'Quotation ' . $quoteNo . ' - Approval Required';

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $reason = $to === '' ? 'Customer email is empty. Add an email address and use Resend Email.' : 'Customer email address is invalid.';
        $out['notice'] = $reason;
        $out['mail_log_id'] = qQueueLogCreate($pdo,$tenant,$branch,$clientId,$to,$quoteId,$subject,'',0,'suppressed',$reason);
        return $out;
    }

    /* Same SMTP selection flow as the Invoice API. */
    $config = qSmtpConfig($pdo);
    if (!$config) {
        $reason = 'Customer email was not sent because no active default platform SMTP configuration was found. Configure one platform SMTP as Active + Default in Master Controls.';
        $out['notice'] = $reason;
        $out['mail_log_id'] = qQueueLogCreate(
            $pdo, $tenant, $branch, $clientId, $to, $quoteId,
            $subject, '', 0, 'suppressed', $reason
        );
        return $out;
    }

    $out['smtp_id'] = (int)$config['id'];
    $out['smtp_is_default'] = !empty($config['is_default']) ? 1 : 0;

    if (
        (isset($config['scope_type']) && (string)$config['scope_type'] !== 'platform') ||
        !empty($config['tenant_id']) ||
        !empty($config['branch_id']) ||
        empty($config['is_default'])
    ) {
        $reason = 'Customer email was not sent because the selected SMTP configuration is not the global default platform SMTP.';
        $out['notice'] = $reason;
        $out['mail_log_id'] = qQueueLogCreate(
            $pdo, $tenant, $branch, $clientId, $to, $quoteId,
            $subject, '', 0, 'suppressed', $reason
        );
        return $out;
    }

    $reviewUrl = qAppBaseUrl() . '/quotation-response.php?token=' . rawurlencode((string)$plainToken);
    $out['approval_url'] = $reviewUrl;

    $clientName = trim((string)(isset($client['display_name']) ? $client['display_name'] : 'Customer'));
    if ($clientName === '') $clientName='Customer';

    $view = isset($quote['client_view_options']) && is_array($quote['client_view_options'])
        ? $quote['client_view_options']
        : array('quantities'=>true,'unit_prices'=>true,'line_item_totals'=>true,'totals'=>true);
    foreach (array('quantities','unit_prices','line_item_totals','totals') as $vk) {
        if (!array_key_exists($vk,$view)) $view[$vk]=true;
    }

    $head='<th style="padding:10px;text-align:left">Item</th>';
    if ($view['quantities']) $head.='<th style="padding:10px;text-align:center">Qty</th>';
    if ($view['unit_prices']) $head.='<th style="padding:10px;text-align:right">Unit price</th>';
    if ($view['line_item_totals']) $head.='<th style="padding:10px;text-align:right">Amount</th>';
    $visibleCols=1+($view['quantities']?1:0)+($view['unit_prices']?1:0)+($view['line_item_totals']?1:0);

    $rows='';
    foreach ($items as $item) {
        $name=htmlspecialchars((string)$item['item_name'],ENT_QUOTES,'UTF-8');
        $qty=rtrim(rtrim(number_format((float)$item['quantity'],3,'.',''),'0'),'.');
        $unit=qMoney(isset($item['unit_price'])?$item['unit_price']:0,$currency);
        $amount=qMoney(isset($item['line_total'])?$item['line_total']:0,$currency);
        $rows.='<tr><td style="padding:10px;border-bottom:1px solid #e8edf3;color:#17233b">'.$name.'</td>';
        if ($view['quantities']) $rows.='<td style="padding:10px;border-bottom:1px solid #e8edf3;text-align:center;color:#52627a">'.htmlspecialchars($qty,ENT_QUOTES,'UTF-8').'</td>';
        if ($view['unit_prices']) $rows.='<td style="padding:10px;border-bottom:1px solid #e8edf3;text-align:right;color:#17233b">'.htmlspecialchars($unit,ENT_QUOTES,'UTF-8').'</td>';
        if ($view['line_item_totals']) $rows.='<td style="padding:10px;border-bottom:1px solid #e8edf3;text-align:right;color:#17233b">'.htmlspecialchars($amount,ENT_QUOTES,'UTF-8').'</td>';
        $rows.='</tr>';
    }
    if ($rows==='') $rows='<tr><td colspan="'.(int)$visibleCols.'" style="padding:12px;color:#6f7b90">Quotation items are available in the attached PDF.</td></tr>';

    $expires=!empty($quote['approval_expires'])?date('d M Y, h:i A',strtotime($quote['approval_expires'])):'';
    $total=htmlspecialchars(qMoney(isset($quote['total'])?$quote['total']:0,$currency),ENT_QUOTES,'UTF-8');
    $safeClient=htmlspecialchars($clientName,ENT_QUOTES,'UTF-8');
    $safeNo=htmlspecialchars($quoteNo,ENT_QUOTES,'UTF-8');
    $safeUrl=htmlspecialchars($reviewUrl,ENT_QUOTES,'UTF-8');

    $html='<!doctype html><html><body style="margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#0b1933">'
        .'<div style="max-width:680px;margin:0 auto;padding:28px 14px">'
        .'<div style="background:#001131;padding:22px 24px;border-radius:12px 12px 0 0">'
        .'<div style="color:#9fda55;font-size:13px;font-weight:700">FieldPlx</div>'
        .'<div style="margin-top:5px;color:#fff;font-size:22px;font-weight:700">Quotation '.$safeNo.'</div>'
        .'</div>'
        .'<div style="background:#fff;padding:24px;border:1px solid #e5eaf1;border-top:0;border-radius:0 0 12px 12px">'
        .'<p style="margin-top:0">Hello '.$safeClient.',</p>'
        .'<p style="color:#52627a;line-height:1.6">A quotation has been prepared for you. The quotation PDF is attached to this email. Please review it and use the secure button below to approve or reject the quotation.</p>'
        .'<table style="width:100%;border-collapse:collapse;margin:18px 0"><thead><tr style="background:#f6f8fb">'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>'
        .($view['totals']?'<div style="text-align:right;font-size:18px;font-weight:700;margin:16px 0">Total: '.$total.'</div>':'')
        .'<div style="text-align:center;margin:24px 0"><a href="'.$safeUrl.'" style="display:inline-block;padding:13px 22px;border-radius:8px;background:#74b824;color:#fff;text-decoration:none;font-weight:700">Review &amp; Approve / Reject</a></div>'
        .($expires!==''?'<p style="font-size:12px;color:#7b8798;text-align:center">This secure response link expires on '.htmlspecialchars($expires,ENT_QUOTES,'UTF-8').'.</p>':'')
        .'<p style="font-size:12px;color:#7b8798;line-height:1.5">For your records, the same quotation is attached as a PDF.</p>'
        .'</div></div></body></html>';

    /* Create the log BEFORE PDF generation / SMTP so every attempt is traceable. */
    $logId=qQueueLogCreate($pdo,$tenant,$branch,$clientId,$to,$quoteId,$subject,$html,(int)$config['id'],'processing','');
    $out['mail_log_id'] = $logId;

    try {
        $password = fieldplxDecryptSmtpPassword(isset($config['password_encrypted']) ? $config['password_encrypted'] : '');
        if (trim((string)(isset($config['username']) ? $config['username'] : '')) !== '' && trim((string)$password) === '') {
            throw new RuntimeException('SMTP password is empty or could not be decrypted. The quotation module now uses the same Platform SMTP encryption method and secret key source as the Invoice module and Master Controls. If this remains, edit the default Platform SMTP, enter the password again, save, and test it once.');
        }

        /* Quote is already committed before this function is called. */
        $quotePdf = qGenerateQuotationPdfForEmail($quoteId);

        fieldplxSmtpSendWithConfig(
            $config,
            $password,
            $to,
            $subject,
            $html,
            array(
                array(
                    'name' => $quotePdf['name'],
                    'mime' => 'application/pdf',
                    'content' => $quotePdf['bytes']
                )
            )
        );

        $out['pdf_attached'] = 1;
        $out['pdf_name'] = $quotePdf['name'];
        $out['pdf_size'] = (int)$quotePdf['size'];
        $out['pdf_sha256'] = $quotePdf['sha256'];
        $out['status'] = 'sent';
        $out['notice'] = 'Quotation approval email with PDF attachment sent successfully.';
        qQueueLogFinish($pdo,$logId,'sent','');
    } catch (Throwable $e) {
        $err='SMTP config #'.(int)$config['id'].' ['.(string)$config['host'].':'.(int)$config['port'].' / '.(string)$config['encryption'].'] - '.$e->getMessage();
        $out['status'] = 'failed';
        $out['notice'] = 'Quotation was saved, but customer approval email failed: ' . $e->getMessage();
        qQueueLogFinish($pdo,$logId,'failed',$err);
        error_log('quotation customer approval email '.$err);
    }

    return $out;
}

$tenant = isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : 0;
$user = isset($_SESSION['tenant_user_id']) ? (int) $_SESSION['tenant_user_id'] : 0;
$sessionBranch = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : 0;
if ($tenant <= 0 || $user <= 0)
    qRes(401, false, 'Authentication required.');
$csrf = (string) qP('csrf_token', '');
$sc = isset($_SESSION['quotations_csrf_token']) ? (string) $_SESSION['quotations_csrf_token'] : '';
$legacySc = isset($_SESSION['my_jobs_csrf_token']) ? (string) $_SESSION['my_jobs_csrf_token'] : '';
$csrfOk = ($csrf !== '' && (($sc !== '' && hash_equals($sc, $csrf)) || ($legacySc !== '' && hash_equals($legacySc, $csrf))));
if (!$csrfOk)
    qRes(419, false, 'Your form session expired. Refresh and try again.');
$action = trim((string) qP('action', ''));
$hasRevisitColumn = qCol($pdo, 'quotes', 'assessment_reschedule_id');

try {
    if ($action === 'form_meta') {
        $editing = (int) qP('quote_id', 0);
        $sources = array();
        $sql = "SELECT r.id,r.request_no,r.title,r.description,r.status,r.branch_id,r.client_id,r.location_id,r.product_service_id,r.preferred_date,r.preferred_time_from,r.preferred_time_to,c.display_name client_name,cl.name location_name,ps.name service_name FROM service_requests r INNER JOIN clients c ON c.id=r.client_id AND c.tenant_id=r.tenant_id LEFT JOIN client_locations cl ON cl.id=r.location_id AND cl.tenant_id=r.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=r.tenant_id WHERE r.tenant_id=:t AND r.status NOT IN('closed','cancelled') ORDER BY CASE WHEN r.status='quote_required' THEN 0 ELSE 1 END,COALESCE(r.updated_at,r.created_at) DESC,r.id DESC";
        $s = $pdo->prepare($sql);
        $s->execute(array(':t' => $tenant));
        $requests = $s->fetchAll(PDO::FETCH_ASSOC);
        $editingQuote = null;
        if ($editing > 0) {
            $eq = $pdo->prepare("SELECT id,request_id" . ($hasRevisitColumn ? ',assessment_reschedule_id' : '') . " FROM quotes WHERE id=:id AND tenant_id=:t LIMIT 1");
            $eq->execute(array(':id' => $editing, ':t' => $tenant));
            $editingQuote = $eq->fetch(PDO::FETCH_ASSOC);
        }
        foreach ($requests as $r) {
            $rid = (int) $r['id'];
            $origAllowed = true;
            $dupSql = "SELECT id FROM quotes WHERE tenant_id=:t AND request_id=:r AND status<>'archived'" . ($hasRevisitColumn ? " AND assessment_reschedule_id IS NULL" : "") . " LIMIT 1";
            $ds = $pdo->prepare($dupSql);
            $ds->execute(array(':t' => $tenant, ':r' => $rid));
            $existingOrig = $ds->fetchColumn();
            if ($existingOrig && (!$editingQuote || (int) $editingQuote['id'] !== (int) $existingOrig))
                $origAllowed = false;
            if ($origAllowed) {
                $row = $r;
                $row['source_key'] = 'request:' . $rid;
                $row['request_id'] = $rid;
                $row['assessment_reschedule_id'] = null;
                $row['source_type'] = 'original';
                $row['source_label'] = 'Original Enquiry';
                $row['visit_date'] = $r['preferred_date'];
                $row['visit_time_from'] = $r['preferred_time_from'];
                $row['visit_time_to'] = $r['preferred_time_to'];
                $row['revisit_remarks'] = '';
                $sources[] = $row;
            }
            if ($hasRevisitColumn && qTable($pdo, 'assessment_reschedule_history')) {
                $hs = $pdo->prepare("SELECT h.* FROM assessment_reschedule_history h WHERE h.tenant_id=:t AND h.request_id=:r ORDER BY h.id DESC");
                $hs->execute(array(':t' => $tenant, ':r' => $rid));
                foreach ($hs->fetchAll(PDO::FETCH_ASSOC) as $h) {
                    $hid = (int) $h['id'];
                    $qd = $pdo->prepare("SELECT id FROM quotes WHERE tenant_id=:t AND request_id=:r AND assessment_reschedule_id=:h AND status<>'archived' LIMIT 1");
                    $qd->execute(array(':t' => $tenant, ':r' => $rid, ':h' => $hid));
                    $existing = $qd->fetchColumn();
                    if ($existing && (!$editingQuote || (int) $editingQuote['id'] !== (int) $existing))
                        continue;
                    $row = $r;
                    $row['source_key'] = 'revisit:' . $rid . ':' . $hid;
                    $row['request_id'] = $rid;
                    $row['assessment_reschedule_id'] = $hid;
                    $row['source_type'] = 'revisit';
                    $row['source_label'] = 'Revisit #' . $hid;
                    $row['visit_date'] = $h['new_preferred_date'];
                    $row['visit_time_from'] = $h['new_time_from'];
                    $row['visit_time_to'] = $h['new_time_to'];
                    $row['revisit_remarks'] = $h['remarks'];
                    $sources[] = $row;
                }
            }
        }
        $serviceImageSelect = qCol($pdo, 'product_services', 'image_path') ? ',image_path' : ',NULL AS image_path';
        $c = $pdo->prepare("SELECT id,name,item_type,sku,description,unit_name,unit_cost,unit_price,tax_percent" . $serviceImageSelect . " FROM product_services WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY FIELD(item_type,'service','material','fee','discount','product'),name");
        $c->execute(array(':t' => $tenant));
        $cl = $pdo->prepare("SELECT id,branch_id,display_name,company_name,email,phone,status FROM clients WHERE tenant_id=:t AND deleted_at IS NULL AND status<>'archived' ORDER BY display_name");
        $cl->execute(array(':t' => $tenant));
        $clients = $cl->fetchAll(PDO::FETCH_ASSOC);
        $lo = $pdo->prepare("SELECT id,client_id,location_type,name,address_line1,address_line2,city,state,postal_code,is_primary,status FROM client_locations WHERE tenant_id=:t AND deleted_at IS NULL AND status='active' ORDER BY is_primary DESC,name");
        $lo->execute(array(':t' => $tenant));
        $salespersons = array();
        if (qTable($pdo, 'users')) {
            $us = $pdo->prepare("SELECT id,first_name,last_name,email,job_title FROM users WHERE tenant_id=:t AND deleted_at IS NULL AND status='active' ORDER BY first_name,last_name,id");
            $us->execute(array(':t'=>$tenant));
            $salespersons = $us->fetchAll(PDO::FETCH_ASSOC);
        }
        $previewBranch = $sessionBranch;
        if ($editingQuote && !empty($editingQuote['id'])) {
            $qb = $pdo->prepare("SELECT branch_id,quote_no FROM quotes WHERE id=:id AND tenant_id=:t LIMIT 1");
            $qb->execute(array(':id'=>(int)$editingQuote['id'], ':t'=>$tenant));
            $qbRow = $qb->fetch(PDO::FETCH_ASSOC);
            $nextPreview = $qbRow && !empty($qbRow['quote_no']) ? $qbRow['quote_no'] : qPreviewNext($pdo,$tenant,$previewBranch);
        } else {
            $nextPreview = qPreviewNext($pdo,$tenant,$previewBranch);
        }
        $taxRates = array();
        $defaultTaxRateId = 0;
        if (qTable($pdo,'product_tax_rates')) {
            $taxDescriptionSelect = qCol($pdo,'product_tax_rates','description') ? ',description' : ',NULL AS description';
            $tx=$pdo->prepare("SELECT id,tax_name,rate_percent,jurisdiction_name,status,is_default".$taxDescriptionSelect." FROM product_tax_rates WHERE tenant_id=:t AND status='active' ORDER BY is_default DESC,tax_name,id");
            $tx->execute(array(':t'=>$tenant));
            $taxRates=$tx->fetchAll(PDO::FETCH_ASSOC);
            foreach ($taxRates as $taxRateRow) { if ((int)$taxRateRow['is_default'] === 1) { $defaultTaxRateId=(int)$taxRateRow['id']; break; } }
        }
        $quoteSettings = array('default_disclaimer'=>'This quote is valid for the next 30 days, after which values may be subject to change.');
        if (qTable($pdo,'quote_settings')) {
            $qs=$pdo->prepare("SELECT default_disclaimer FROM quote_settings WHERE tenant_id=:t LIMIT 1"); $qs->execute(array(':t'=>$tenant)); $qr=$qs->fetch(PDO::FETCH_ASSOC);
            if ($qr && trim((string)$qr['default_disclaimer'])!=='') $quoteSettings['default_disclaimer']=$qr['default_disclaimer'];
        }
        qRes(200, true, 'Quotation form data loaded.', array('meta' => array(
            'requests' => $sources,
            'catalog' => $c->fetchAll(PDO::FETCH_ASSOC),
            'products' => qProducts($pdo, $tenant),
            'clients' => $clients,
            'locations' => $lo->fetchAll(PDO::FETCH_ASSOC),
            'salespersons' => $salespersons,
            'team_members' => $salespersons,
            'tax_rates' => $taxRates,
            'default_tax_rate_id' => $defaultTaxRateId,
            'quote_settings' => $quoteSettings,
            'current_user_id' => $user,
            'next_quote_no' => $nextPreview,
            'currency' => qCurrency($pdo, $tenant)
        )));
    }
    if ($action === 'request_quote_source') {
        $requestId = (int) qP('request_id', 0);
        $revisitId = (int) qP('assessment_reschedule_id', 0);
        if ($requestId <= 0) qRes(422, false, 'Request is required.');

        $request = qValidRequest($pdo, $tenant, $requestId);
        if (!$request) qRes(404, false, 'Request was not found or is unavailable.');
        if ($revisitId > 0) {
            $revisit = qValidRevisit($pdo, $tenant, $requestId, $revisitId);
            if (!$revisit) qRes(422, false, 'Selected revisit does not belong to this request.');
        }

        $lineItems = array();
        if (qTable($pdo, 'service_request_line_items')) {
            $psImage = qCol($pdo, 'product_services', 'image_path') ? 'ps.image_path' : 'NULL';
            $productImage = (qTable($pdo, 'products') && qCol($pdo, 'products', 'image_path')) ? 'p.image_path' : 'NULL';
            $productJoin = qTable($pdo, 'products')
                ? " LEFT JOIN products p ON p.id=li.product_id AND p.tenant_id=li.tenant_id"
                : "";
            $sql = "SELECT li.id,li.product_service_id,li.product_id,li.item_type,li.item_name,li.description,li.quantity,li.unit_cost,li.unit_price,li.tax_percent,li.tax_amount,li.line_total,li.sort_order,COALESCE(".$productImage.",".$psImage.") image_path FROM service_request_line_items li LEFT JOIN product_services ps ON ps.id=li.product_service_id AND ps.tenant_id=li.tenant_id".$productJoin." WHERE li.tenant_id=:t AND li.request_id=:r ORDER BY li.sort_order,li.id";
            $ls = $pdo->prepare($sql);
            $ls->execute(array(':t'=>$tenant, ':r'=>$requestId));
            $lineItems = $ls->fetchAll(PDO::FETCH_ASSOC);
        }

        /* Backward-compatible fallback for older requests that only store the main service on service_requests. */
        if (!$lineItems && !empty($request['product_service_id'])) {
            $imageSelect = qCol($pdo, 'product_services', 'image_path') ? ',image_path' : ',NULL AS image_path';
            $ps = $pdo->prepare("SELECT id,name,item_type,description,unit_cost,unit_price,tax_percent".$imageSelect." FROM product_services WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL LIMIT 1");
            $ps->execute(array(':id'=>(int)$request['product_service_id'], ':t'=>$tenant));
            $item = $ps->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                $base = max(0, (float)$item['unit_price']);
                $taxPercent = max(0, (float)$item['tax_percent']);
                $taxAmount = round($base * $taxPercent / 100, 2);
                $lineItems[] = array(
                    'id'=>0,
                    'product_service_id'=>(int)$item['id'],
                    'product_id'=>null,
                    'item_type'=>(string)$item['item_type'],
                    'item_name'=>(string)$item['name'],
                    'description'=>$item['description'],
                    'quantity'=>1,
                    'unit_cost'=>(float)$item['unit_cost'],
                    'unit_price'=>(float)$item['unit_price'],
                    'tax_percent'=>$taxPercent,
                    'tax_amount'=>$taxAmount,
                    'line_total'=>round($base + $taxAmount, 2),
                    'sort_order'=>0,
                    'image_path'=>$item['image_path']
                );
            }
        }

        foreach ($lineItems as &$lineItem) {
            $cost = max(0, (float)(isset($lineItem['unit_cost']) ? $lineItem['unit_cost'] : 0));
            $price = max(0, (float)(isset($lineItem['unit_price']) ? $lineItem['unit_price'] : 0));
            $lineItem['markup_percent'] = $cost > 0 ? max(0, round((($price - $cost) / $cost) * 100, 4)) : 0;
            $lineItem['service_date'] = !empty($request['preferred_date']) ? $request['preferred_date'] : '';
        }
        unset($lineItem);

        qRes(200, true, 'Request quotation source loaded.', array(
            'request'=>array(
                'id'=>(int)$request['id'],
                'request_no'=>(string)$request['request_no'],
                'title'=>(string)$request['title'],
                'description'=>(string)(isset($request['description']) ? $request['description'] : ''),
                'status'=>(string)$request['status'],
                'client_id'=>(int)$request['client_id'],
                'location_id'=>!empty($request['location_id']) ? (int)$request['location_id'] : null,
                'product_service_id'=>!empty($request['product_service_id']) ? (int)$request['product_service_id'] : null,
                'preferred_date'=>isset($request['preferred_date']) ? $request['preferred_date'] : null,
                'preferred_time_from'=>isset($request['preferred_time_from']) ? $request['preferred_time_from'] : null,
                'preferred_time_to'=>isset($request['preferred_time_to']) ? $request['preferred_time_to'] : null,
                'client_name'=>isset($request['client_name']) ? $request['client_name'] : '',
                'location_name'=>isset($request['location_name']) ? $request['location_name'] : '',
                'service_name'=>isset($request['service_name']) ? $request['service_name'] : ''
            ),
            'line_items'=>$lineItems
        ));
    }

    if ($action === 'create_tax_rate') {
        if (!qTable($pdo, 'product_tax_rates')) qRes(500, false, 'Tax rate support is not available.');
        $name = substr(trim((string)qP('name', '')), 0, 120);
        $rateRaw = trim((string)qP('rate_percent', '0'));
        $description = substr(trim((string)qP('description', '')), 0, 190);
        $isDefault = (int)qP('is_default', 0) === 1 ? 1 : 0;
        if ($name === '') qRes(422, false, 'Tax rate name is required.');
        if ($rateRaw === '' || !is_numeric($rateRaw)) qRes(422, false, 'Enter a valid tax rate.');
        $rate = (float)$rateRaw;
        if ($rate < 0 || $rate > 100) qRes(422, false, 'Tax rate must be between 0 and 100.');

        $du = $pdo->prepare("SELECT id FROM product_tax_rates WHERE tenant_id=:t AND LOWER(tax_name)=LOWER(:n) LIMIT 1");
        $du->execute(array(':t'=>$tenant, ':n'=>$name));
        if ($du->fetchColumn()) qRes(409, false, 'A tax rate with this name already exists.');

        $hasDescription = qCol($pdo, 'product_tax_rates', 'description');
        $pdo->beginTransaction();
        try {
            if ($isDefault === 1) {
                $pdo->prepare("UPDATE product_tax_rates SET is_default=0 WHERE tenant_id=:t")->execute(array(':t'=>$tenant));
            }
            if ($hasDescription) {
                $stmt = $pdo->prepare("INSERT INTO product_tax_rates(tenant_id,tax_name,rate_percent,tax_type,jurisdiction_name,description,status,is_default,created_by) VALUES(:t,:n,:r,'other',NULL,:d,'active',:def,:u)");
                $stmt->execute(array(':t'=>$tenant, ':n'=>$name, ':r'=>$rate, ':d'=>$description!==''?$description:null, ':def'=>$isDefault, ':u'=>$user));
            } else {
                $stmt = $pdo->prepare("INSERT INTO product_tax_rates(tenant_id,tax_name,rate_percent,tax_type,jurisdiction_name,status,is_default,created_by) VALUES(:t,:n,:r,'other',NULL,'active',:def,:u)");
                $stmt->execute(array(':t'=>$tenant, ':n'=>$name, ':r'=>$rate, ':def'=>$isDefault, ':u'=>$user));
            }
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $taxRate = array('id'=>$id,'tax_name'=>$name,'rate_percent'=>$rate,'jurisdiction_name'=>null,'description'=>$description,'status'=>'active','is_default'=>$isDefault);
        if (function_exists('tenantAuditLog')) {
            try { tenantAuditLog($pdo,'TAX_RATE_CREATED',$tenant,$sessionBranch,$user,'product_tax_rate',$id,null,$taxRate); }
            catch (Throwable $ae) { error_log('quote tax rate audit '.$ae->getMessage()); }
        }
        qRes(200, true, 'Tax rate created successfully.', array('tax_rate'=>$taxRate));
    }

    if ($action === 'create_customer') {
        if (!qTable($pdo, 'clients')) qRes(500, false, 'Customers table is not available.');
        $display = substr(trim((string)qP('display_name', '')), 0, 190);
        $company = substr(trim((string)qP('company_name', '')), 0, 190);
        $email = strtolower(substr(trim((string)qP('email', '')), 0, 190));
        $phone = substr(trim((string)qP('phone', '')), 0, 50);
        if ($display === '') qRes(422, false, 'Customer name is required.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) qRes(422, false, 'Enter a valid customer email address.');
        if ($email !== '') {
            $du = $pdo->prepare("SELECT id FROM clients WHERE tenant_id=:t AND email=:e AND deleted_at IS NULL LIMIT 1");
            $du->execute(array(':t'=>$tenant, ':e'=>$email));
            if ($du->fetchColumn()) qRes(409, false, 'This email is already used by another customer.');
        }
        $branch = $sessionBranch > 0 ? $sessionBranch : null;
        $pref = $email !== '' ? 'email' : ($phone !== '' ? 'phone' : 'none');
        $stmt = $pdo->prepare("INSERT INTO clients(tenant_id,branch_id,client_type,display_name,company_name,first_name,last_name,email,phone,alternate_phone,source,preferred_contact_method,allow_email,allow_sms,status,tax_number,notes,account_manager_id,created_by) VALUES(:t,:b,'client',:display,:company,:first,NULL,:email,:phone,NULL,'quotation',:pref,:ae,:as,'active',NULL,NULL,NULL,:u)");
        $stmt->execute(array(':t'=>$tenant, ':b'=>$branch, ':display'=>$display, ':company'=>$company!==''?$company:null, ':first'=>$display, ':email'=>$email!==''?$email:null, ':phone'=>$phone!==''?$phone:null, ':pref'=>$pref, ':ae'=>$email!==''?1:0, ':as'=>$phone!==''?1:0, ':u'=>$user));
        $id = (int)$pdo->lastInsertId();
        $client = array('id'=>$id,'branch_id'=>$branch,'display_name'=>$display,'company_name'=>$company,'email'=>$email,'phone'=>$phone,'status'=>'active');
        if (function_exists('tenantAuditLog')) {
            try { tenantAuditLog($pdo,'CLIENT_CREATED_FROM_QUOTATION',$tenant,$branch,$user,'client',$id,null,$client); }
            catch (Throwable $ae) { error_log('quote quick customer audit '.$ae->getMessage()); }
        }
        qRes(200, true, 'Customer created successfully.', array('client'=>$client));
    }

    if ($action === 'create_location') {
        if (!qTable($pdo, 'client_locations')) qRes(500, false, 'Customer locations table is not available.');
        $clientId = (int)qP('client_id', 0);
        $client = qClient($pdo, $tenant, $clientId);
        if (!$client) qRes(422, false, 'Select a valid customer first.');
        $type = strtolower(trim((string)qP('location_type', 'site')));
        $allowedTypes = array('home','office','warehouse','factory','farm','shop','site','other');
        if (!in_array($type,$allowedTypes,true)) $type='other';
        $name = substr(trim((string)qP('name','')),0,190);
        $a1 = substr(trim((string)qP('address_line1','')),0,255);
        $a2 = substr(trim((string)qP('address_line2','')),0,255);
        $city = substr(trim((string)qP('city','')),0,120);
        $state = substr(trim((string)qP('state','')),0,120);
        $postal = substr(trim((string)qP('postal_code','')),0,40);
        if ($name === '') qRes(422, false, 'Location name is required.');
        if ($a1 === '') qRes(422, false, 'Location address is required.');
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM client_locations WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL");
        $cnt->execute(array(':t'=>$tenant, ':c'=>$clientId));
        $primary = ((int)$cnt->fetchColumn() === 0 || (int)qP('is_primary',0) === 1) ? 1 : 0;
        $pdo->beginTransaction();
        try {
            if ($primary === 1) {
                $pdo->prepare("UPDATE client_locations SET is_primary=0 WHERE tenant_id=:t AND client_id=:c AND deleted_at IS NULL")->execute(array(':t'=>$tenant, ':c'=>$clientId));
            }
            $stmt = $pdo->prepare("INSERT INTO client_locations(tenant_id,client_id,location_type,name,address_line1,address_line2,city,state,postal_code,country_id,latitude,longitude,contact_name,contact_phone,gate_code,access_notes,service_instructions,is_primary,status) VALUES(:t,:c,:type,:name,:a1,:a2,:city,:state,:postal,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,:primary,'active')");
            $stmt->execute(array(':t'=>$tenant, ':c'=>$clientId, ':type'=>$type, ':name'=>$name, ':a1'=>$a1, ':a2'=>$a2!==''?$a2:null, ':city'=>$city!==''?$city:null, ':state'=>$state!==''?$state:null, ':postal'=>$postal!==''?$postal:null, ':primary'=>$primary));
            $id=(int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $location=array('id'=>$id,'client_id'=>$clientId,'location_type'=>$type,'name'=>$name,'address_line1'=>$a1,'address_line2'=>$a2,'city'=>$city,'state'=>$state,'postal_code'=>$postal,'is_primary'=>$primary,'status'=>'active');
        if (function_exists('tenantAuditLog')) {
            try { tenantAuditLog($pdo,'CLIENT_LOCATION_CREATED_FROM_QUOTATION',$tenant,!empty($client['branch_id'])?(int)$client['branch_id']:$sessionBranch,$user,'client_location',$id,null,$location); }
            catch (Throwable $ae) { error_log('quote quick location audit '.$ae->getMessage()); }
        }
        qRes(200, true, 'Location created successfully.', array('location'=>$location));
    }

    if ($action === 'create_catalog_item') {
        $itemType = strtolower(trim((string)qP('item_type','service')));
        if (!in_array($itemType,array('service','product'),true)) qRes(422,false,'Select Service or Product.');
        $name = substr(trim((string)qP('name','')),0,190);
        $description = trim((string)qP('description',''));
        $unitCost = max(0,(float)qP('unit_cost',0));
        $markupPercent = max(0,(float)qP('markup_percent',0));
        $unitPriceRaw = trim((string)qP('unit_price',''));
        $unitPrice = $unitPriceRaw==='' ? round($unitCost*(1+($markupPercent/100)),2) : max(0,(float)$unitPriceRaw);
        $exemptTax = (int)qP('exempt_tax',0)===1;
        $taxRateId = $exemptTax ? 0 : max(0,(int)qP('tax_rate_id',0));
        $taxPercent = $exemptTax ? 0.0 : max(0,(float)qP('tax_percent',0));
        if (!$exemptTax && $taxRateId > 0 && qTable($pdo,'product_tax_rates')) {
            $taxLookup=$pdo->prepare("SELECT rate_percent FROM product_tax_rates WHERE id=:id AND tenant_id=:t AND status='active' LIMIT 1");
            $taxLookup->execute(array(':id'=>$taxRateId,':t'=>$tenant));
            $taxLookupRate=$taxLookup->fetchColumn();
            if ($taxLookupRate === false) qRes(422,false,'Select a valid tax rate.');
            $taxPercent=max(0,(float)$taxLookupRate);
        }
        $hasUploadedImage = !empty($_FILES['item_image']) && is_array($_FILES['item_image']) && isset($_FILES['item_image']['error']) && (int)$_FILES['item_image']['error'] !== UPLOAD_ERR_NO_FILE;
        if ($name==='') qRes(422,false,'Product / Service name is required.');

        if ($itemType==='service') {
            $serviceHasImage = qCol($pdo,'product_services','image_path');
            $serviceImageSelect = $serviceHasImage ? ',image_path' : ',NULL AS image_path';
            $find=$pdo->prepare("SELECT id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent".$serviceImageSelect." FROM product_services WHERE tenant_id=:t AND item_type='service' AND LOWER(name)=LOWER(:n) AND deleted_at IS NULL AND status='active' LIMIT 1");
            $find->execute(array(':t'=>$tenant,':n'=>$name));
            $existing=$find->fetch(PDO::FETCH_ASSOC);
            if ($existing) qRes(200,true,'Existing service selected.',array('created'=>0,'item_kind'=>'service','item'=>$existing));
            if ($hasUploadedImage && !$serviceHasImage) qRes(500,false,'Service image storage is not available.');
            $image=array('relative'=>null,'absolute'=>null);
            try {
                if ($hasUploadedImage) $image=qStoreCatalogImage('item_image',$tenant,'service');
                if ($serviceHasImage) {
                    $stmt=$pdo->prepare("INSERT INTO product_services(tenant_id,category_id,item_type,name,sku,image_path,description,unit_name,unit_cost,unit_price,tax_percent,is_bookable,estimated_duration_minutes,status,created_at,updated_at,deleted_at) VALUES(:t,NULL,'service',:n,NULL,:img,:d,'Service',:cost,:price,:tax,0,NULL,'active',NOW(),NOW(),NULL)");
                    $stmt->execute(array(':t'=>$tenant,':n'=>$name,':img'=>$image['relative'],':d'=>$description!==''?$description:null,':cost'=>$unitCost,':price'=>$unitPrice,':tax'=>$taxPercent));
                } else {
                    $stmt=$pdo->prepare("INSERT INTO product_services(tenant_id,category_id,item_type,name,sku,description,unit_name,unit_cost,unit_price,tax_percent,is_bookable,estimated_duration_minutes,status,created_at,updated_at,deleted_at) VALUES(:t,NULL,'service',:n,NULL,:d,'Service',:cost,:price,:tax,0,NULL,'active',NOW(),NOW(),NULL)");
                    $stmt->execute(array(':t'=>$tenant,':n'=>$name,':d'=>$description!==''?$description:null,':cost'=>$unitCost,':price'=>$unitPrice,':tax'=>$taxPercent));
                }
            } catch (Throwable $e) {
                if (!empty($image['absolute']) && is_file($image['absolute'])) @unlink($image['absolute']);
                throw $e;
            }
            $newId=(int)$pdo->lastInsertId();
            $item=array('id'=>$newId,'item_type'=>'service','name'=>$name,'sku'=>null,'image_path'=>$image['relative'],'description'=>$description,'unit_name'=>'Service','unit_cost'=>$unitCost,'unit_price'=>$unitPrice,'tax_percent'=>$taxPercent);
            if (function_exists('tenantAuditLog')) { try { tenantAuditLog($pdo,'SERVICE_CREATED',$tenant,$sessionBranch,$user,'service',$newId,null,$item); } catch(Throwable $ae){ error_log('quote catalog service audit '.$ae->getMessage()); } }
            qRes(200,true,'Service created successfully.',array('created'=>1,'item_kind'=>'service','item'=>$item));
        }

        if (!qTable($pdo,'products')) qRes(500,false,'Products table is not available.');
        $deletedFilter=qCol($pdo,'products','deleted_at')?" AND deleted_at IS NULL":"";
        $productHasImage=qCol($pdo,'products','image_path');
        $productImageSelect=$productHasImage?',image_path':',NULL AS image_path';
        $find=$pdo->prepare("SELECT id,sku,name,description,unit_name,base_unit_price,selling_price,tax_percent".$productImageSelect." FROM products WHERE tenant_id=:t AND LOWER(name)=LOWER(:n) AND status='active'".$deletedFilter." LIMIT 1");
        $find->execute(array(':t'=>$tenant,':n'=>$name));
        $existing=$find->fetch(PDO::FETCH_ASSOC);
        if ($existing) qRes(200,true,'Existing product selected.',array('created'=>0,'item_kind'=>'product','item'=>$existing));
        if ($hasUploadedImage && !$productHasImage) qRes(500,false,'Product image storage is not available.');
        $image=array('relative'=>null,'absolute'=>null);
        try {
            if ($hasUploadedImage) $image=qStoreCatalogImage('item_image',$tenant,'product');
            if ($productHasImage) {
                $stmt=$pdo->prepare("INSERT INTO products(tenant_id,category_id,sku,name,image_path,description,unit_of_measure_id,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_id,tax_percent,track_inventory,status,created_by,created_at,updated_at,deleted_at) VALUES(:t,NULL,NULL,:n,:img,:d,NULL,'Unit',:cost,'percentage',:markup,:price,:taxid,:tax,0,'active',:u,NOW(),NOW(),NULL)");
                $stmt->execute(array(':t'=>$tenant,':n'=>$name,':img'=>$image['relative'],':d'=>$description!==''?$description:null,':cost'=>$unitCost,':markup'=>$markupPercent,':price'=>$unitPrice,':taxid'=>$taxRateId>0?$taxRateId:null,':tax'=>$taxPercent,':u'=>$user));
            } else {
                $stmt=$pdo->prepare("INSERT INTO products(tenant_id,category_id,sku,name,description,unit_of_measure_id,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_id,tax_percent,track_inventory,status,created_by,created_at,updated_at,deleted_at) VALUES(:t,NULL,NULL,:n,:d,NULL,'Unit',:cost,'percentage',:markup,:price,:taxid,:tax,0,'active',:u,NOW(),NOW(),NULL)");
                $stmt->execute(array(':t'=>$tenant,':n'=>$name,':d'=>$description!==''?$description:null,':cost'=>$unitCost,':markup'=>$markupPercent,':price'=>$unitPrice,':taxid'=>$taxRateId>0?$taxRateId:null,':tax'=>$taxPercent,':u'=>$user));
            }
        } catch (Throwable $e) {
            if (!empty($image['absolute']) && is_file($image['absolute'])) @unlink($image['absolute']);
            throw $e;
        }
        $newId=(int)$pdo->lastInsertId();
        $item=array('id'=>$newId,'sku'=>null,'name'=>$name,'image_path'=>$image['relative'],'description'=>$description,'unit_name'=>'Unit','base_unit_price'=>$unitCost,'selling_price'=>$unitPrice,'tax_id'=>$taxRateId>0?$taxRateId:null,'tax_percent'=>$taxPercent);
        if (function_exists('tenantAuditLog')) { try { tenantAuditLog($pdo,'PRODUCT_CREATED',$tenant,$sessionBranch,$user,'product',$newId,null,$item); } catch(Throwable $ae){ error_log('quote catalog product audit '.$ae->getMessage()); } }
        qRes(200,true,'Product created successfully.',array('created'=>1,'item_kind'=>'product','item'=>$item));
    }

    if ($action === 'create_product') {
        if (!qTable($pdo, 'products'))
            qRes(500, false, 'Products table is not installed. Run migration_quotation_products_approval.sql once.');
        $name = trim((string) qP('name', ''));
        $desc = trim((string) qP('description', ''));
        $base = max(0, (float) qP('base_unit_price', 0));
        $mt = trim((string) qP('markup_type', 'percentage'));
        $mv = max(0, (float) qP('markup_value', 0));
        $tax = max(0, (float) qP('tax_percent', 0));
        if ($name === '')
            qRes(422, false, 'Product name is required.');
        if (!in_array($mt, array('percentage', 'fixed'), true))
            qRes(422, false, 'Select a valid markup type.');
        $selling = $mt === 'fixed' ? $base + $mv : $base + ($base * $mv / 100);
        $du = $pdo->prepare("SELECT id FROM products WHERE tenant_id=:t AND name=:n AND deleted_at IS NULL LIMIT 1");
        $du->execute(array(':t' => $tenant, ':n' => $name));
        if ($du->fetchColumn())
            qRes(409, false, 'A product with this name already exists.');
        $s = $pdo->prepare("INSERT INTO products(tenant_id,name,description,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_percent,status,created_by) VALUES(:t,:n,:d,'unit',:b,:mt,:mv,:sp,:tax,'active',:u)");
        $s->execute(array(':t' => $tenant, ':n' => $name, ':d' => $desc !== '' ? $desc : null, ':b' => $base, ':mt' => $mt, ':mv' => $mv, ':sp' => $selling, ':tax' => $tax, ':u' => $user));
        $id = (int) $pdo->lastInsertId();
        $q = $pdo->prepare("SELECT id,sku,name,description,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_percent FROM products WHERE id=:id AND tenant_id=:t");
        $q->execute(array(':id' => $id, ':t' => $tenant));
        qRes(200, true, 'Product created successfully.', array('product' => $q->fetch(PDO::FETCH_ASSOC)));
    }
    if ($action === 'get') {
        $id = (int) qP('quote_id', 0);
        $sel = "q.*,DATE_FORMAT(q.created_at,'%d-%m-%Y') created_date,r.request_no,c.display_name client_name,c.phone client_phone,c.email client_email,cl.name location_name,ps.name service_name";
        if ($hasRevisitColumn)
            $sel .= " ,q.assessment_reschedule_id";
        $s = $pdo->prepare("SELECT $sel FROM quotes q LEFT JOIN service_requests r ON r.id=q.request_id AND r.tenant_id=q.tenant_id INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id LEFT JOIN client_locations cl ON cl.id=q.location_id AND cl.tenant_id=q.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=q.tenant_id WHERE q.id=:id AND q.tenant_id=:t LIMIT 1");
        $s->execute(array(':id' => $id, ':t' => $tenant));
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q)
            qRes(404, false, 'Quotation not found.');
        $rid = (int) $q['request_id'];
        $hid = $hasRevisitColumn ? (int) $q['assessment_reschedule_id'] : 0;
        $q['source_key'] = $rid > 0 ? ($hid > 0 ? 'revisit:' . $rid . ':' . $hid : 'request:' . $rid) : '';
        $productSelect = qCol($pdo, 'quote_line_items', 'product_id') ? ',qli.product_id' : '';
        $i = $pdo->prepare("SELECT qli.*$productSelect,COALESCE(ps.item_type,CASE WHEN " . (qCol($pdo, 'quote_line_items', 'product_id') ? 'qli.product_id IS NOT NULL' : '1=0') . " THEN 'product' ELSE 'manual' END) item_type FROM quote_line_items qli LEFT JOIN product_services ps ON ps.id=qli.product_service_id WHERE qli.quote_id=:q ORDER BY qli.sort_order,qli.id");
        $i->execute(array(':q' => $id));
        $approval = null;
        if (qTable($pdo, 'quotation_action_tokens')) {
            $at = $pdo->prepare("SELECT id,expires_at,used_at,created_at,CASE WHEN used_at IS NULL AND expires_at>=NOW() THEN 1 ELSE 0 END is_active FROM quotation_action_tokens WHERE tenant_id=:t AND quote_id=:q ORDER BY id DESC LIMIT 1");
            $at->execute(array(':t'=>$tenant, ':q'=>$id));
            $approval = $at->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $q['can_resend_email'] = (!in_array($q['status'], array('approved','rejected','converted','archived'), true) && !empty($q['client_email'])) ? 1 : 0;
        $sections = array();
        if (qTable($pdo, 'quote_sections')) {
            $sq = $pdo->prepare("SELECT id,section_key,title,body,sort_order FROM quote_sections WHERE tenant_id=:t AND quote_id=:q ORDER BY sort_order,id");
            $sq->execute(array(':t'=>$tenant, ':q'=>$id));
            $sections = $sq->fetchAll(PDO::FETCH_ASSOC);
        }
        $files = array();
        if (qTable($pdo, 'quote_files')) {
            $fq = $pdo->prepare("SELECT id,file_category,original_name,file_path,mime_type,file_size,sort_order,created_at FROM quote_files WHERE tenant_id=:t AND quote_id=:q ORDER BY file_category,sort_order,id");
            $fq->execute(array(':t'=>$tenant, ':q'=>$id));
            $files = $fq->fetchAll(PDO::FETCH_ASSOC);
        }
        $paymentSchedule = array();
        if (qTable($pdo,'quote_payment_schedule_items')) {
            $pq=$pdo->prepare("SELECT id,sort_order,split_type,split_value,amount,description,required_quote_deposit FROM quote_payment_schedule_items WHERE tenant_id=:t AND quote_id=:q ORDER BY sort_order,id");
            $pq->execute(array(':t'=>$tenant,':q'=>$id)); $paymentSchedule=$pq->fetchAll(PDO::FETCH_ASSOC);
        }
        qRes(200, true, 'Quotation loaded.', array('quotation' => $q, 'items' => $i->fetchAll(PDO::FETCH_ASSOC), 'sections'=>$sections, 'files'=>$files, 'payment_schedule'=>$paymentSchedule, 'currency' => qCurrency($pdo, $tenant), 'approval' => $approval));
    }
    if ($action === 'list') {
        $page = max(1, (int) qP('page', 1));
        $pp = (int) qP('per_page', 10);
        if (!in_array($pp, array(10, 25, 50), true))
            $pp = 10;
        $search = trim((string) qP('search', ''));
        $status = trim((string) qP('status', ''));
        $from = trim((string) qP('from_date', ''));
        $to = trim((string) qP('to_date', ''));
        $where = array('q.tenant_id=:t');
        $p = array(':t' => $tenant);
        if ($search !== '') {
            $sv = '%' . $search . '%';
            $where[] = '(q.quote_no LIKE :s1 OR r.request_no LIKE :s2 OR c.display_name LIKE :s3 OR q.title LIKE :s4)';
            $p[':s1'] = $sv;
            $p[':s2'] = $sv;
            $p[':s3'] = $sv;
            $p[':s4'] = $sv;
        }
        if (in_array($status, array('draft', 'internal_approval', 'sent', 'viewed', 'changes_requested', 'approved', 'rejected', 'expired', 'converted', 'archived'), true)) {
            $where[] = 'q.status=:st';
            $p[':st'] = $status;
        }
        if ($from !== '') {
            $where[] = 'DATE(q.created_at)>=:fd';
            $p[':fd'] = $from;
        }
        if ($to !== '') {
            $where[] = 'DATE(q.created_at)<=:td';
            $p[':td'] = $to;
        }
        $ws = implode(' AND ', $where);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM quotes q LEFT JOIN service_requests r ON r.id=q.request_id AND r.tenant_id=q.tenant_id INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id WHERE $ws");
        $cnt->execute($p);
        $total = (int) $cnt->fetchColumn();
        $pages = max(1, (int) ceil($total / $pp));
        if ($page > $pages)
            $page = $pages;
        $off = ($page - 1) * $pp;
        $extra = $hasRevisitColumn ? ",q.assessment_reschedule_id,CASE WHEN q.request_id IS NULL THEN 'Direct Quotation' WHEN q.assessment_reschedule_id IS NULL THEN 'Original Enquiry' ELSE CONCAT('Revisit #',q.assessment_reschedule_id) END quotation_source" : ",CASE WHEN q.request_id IS NULL THEN 'Direct Quotation' ELSE 'Original Enquiry' END quotation_source";
        $sql = "SELECT q.*,DATE_FORMAT(q.created_at,'%d-%m-%Y') created_date,r.request_no,c.display_name client_name,c.phone client_phone,c.email client_email,cl.name location_name,ps.name service_name $extra FROM quotes q LEFT JOIN service_requests r ON r.id=q.request_id AND r.tenant_id=q.tenant_id INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id LEFT JOIN client_locations cl ON cl.id=q.location_id AND cl.tenant_id=q.tenant_id LEFT JOIN product_services ps ON ps.id=r.product_service_id AND ps.tenant_id=q.tenant_id WHERE $ws ORDER BY q.created_at DESC,q.id DESC LIMIT " . (int) $pp . " OFFSET " . (int) $off;
        $s = $pdo->prepare($sql);
        $s->execute($p);
        $sm = $pdo->prepare("SELECT COUNT(*) total,SUM(status='draft') draft,SUM(status IN('sent','viewed')) sent_viewed,SUM(status='approved') approved FROM quotes WHERE tenant_id=:t");
        $sm->execute(array(':t' => $tenant));
        $summary = $sm->fetch(PDO::FETCH_ASSOC);
        qRes(200, true, 'Quotations loaded.', array('quotations' => $s->fetchAll(PDO::FETCH_ASSOC), 'summary' => array('total' => (int) ($summary['total'] ?: 0), 'draft' => (int) ($summary['draft'] ?: 0), 'sent_viewed' => (int) ($summary['sent_viewed'] ?: 0), 'approved' => (int) ($summary['approved'] ?: 0)), 'currency' => qCurrency($pdo, $tenant), 'pagination' => array('page' => $page, 'per_page' => $pp, 'total' => $total, 'pages' => $pages, 'from' => $total ? $off + 1 : 0, 'to' => $total ? min($off + $pp, $total) : 0)));
    }
    if ($action === 'resend_email') {
        if (!qTable($pdo, 'quotation_action_tokens'))
            qRes(500, false, 'Quotation approval support is not installed. Run migration_quotation_products_approval.sql once.');
        
        $id = (int) qP('quote_id', 0);
        if ($id <= 0)
            qRes(422, false, 'Quotation is required.');

        $sel = "q.*,c.display_name client_name,c.email client_email,c.phone client_phone";
        $s = $pdo->prepare("SELECT $sel FROM quotes q INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id WHERE q.id=:id AND q.tenant_id=:t LIMIT 1");
        $s->execute(array(':id'=>$id, ':t'=>$tenant));
        $quote = $s->fetch(PDO::FETCH_ASSOC);
        if (!$quote)
            qRes(404, false, 'Quotation not found.');
        if (in_array($quote['status'], array('approved','rejected','converted','archived'), true))
            qRes(409, false, 'Email cannot be resent for this quotation in its current status.');
        if (trim((string)$quote['client_email']) === '')
            qRes(422, false, 'Customer email is not available for this quotation.');

        $itemsQ = $pdo->prepare("SELECT item_name,quantity,unit_price,line_total FROM quote_line_items WHERE quote_id=:q ORDER BY sort_order,id");
        $itemsQ->execute(array(':q'=>$id));
        $mailItems = $itemsQ->fetchAll(PDO::FETCH_ASSOC);
        $branch = !empty($quote['branch_id']) ? (int)$quote['branch_id'] : $sessionBranch;
        $client = array(
            'id'=>(int)$quote['client_id'],
            'display_name'=>$quote['client_name'],
            'email'=>$quote['client_email'],
            'phone'=>$quote['client_phone']
        );

        $token = null;
        try {
            $token = qCreatePendingApprovalToken($pdo, $tenant, $id, (int)$quote['client_id'], 14);
            $quoteForMail = array(
                'id'=>$id,
                'quote_no'=>$quote['quote_no'],
                'total'=>$quote['total'],
                'approval_expires'=>$token['expires'],
                'client_view_options'=>json_decode((string)(isset($quote['client_view_options_json'])?$quote['client_view_options_json']:'{}'),true)
            );
            $mail = qSendQuotationEmail($pdo, $tenant, $branch, $quoteForMail, $client, $mailItems, qCurrency($pdo, $tenant), $token['plain']);
            if (!is_array($mail) || !isset($mail['status']) || $mail['status'] !== 'sent') {
                $notice = is_array($mail) && !empty($mail['notice']) ? $mail['notice'] : 'Unable to send quotation email.';
                throw new RuntimeException($notice);
            }

            $pdo->beginTransaction();
            try {
                $u = $pdo->prepare("UPDATE quotation_action_tokens SET used_at=NOW() WHERE tenant_id=:t AND quote_id=:q AND used_at IS NULL AND id<>:id");
                $u->execute(array(':t'=>$tenant, ':q'=>$id, ':id'=>$token['id']));
                $u = $pdo->prepare("UPDATE quotes SET status=CASE WHEN status IN('draft','internal_approval','changes_requested') THEN 'sent' ELSE status END,sent_at=NOW() WHERE id=:id AND tenant_id=:t");
                $u->execute(array(':id'=>$id, ':t'=>$tenant));
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            qLog($pdo,$tenant,$branch,$user,$id,(int)$quote['client_id'],'quote_email_resent','Quotation email resent: '.$quote['quote_no'],array('approval_expires'=>$token['expires'],'email'=>$quote['client_email']));
            if (function_exists('tenantAuditLog')) {
                try {
                    tenantAuditLog($pdo,'QUOTE_EMAIL_RESENT',$tenant,$branch,$user,'quote',$id,$quote,array('sent_at'=>date('Y-m-d H:i:s'),'approval_expires'=>$token['expires']));
                } catch (Throwable $ae) {
                    error_log('quote resend audit '.$ae->getMessage());
                }
            }
            qRes(200, true, 'Quotation approval email with PDF resent successfully.', array('email_status'=>'sent','approval_expires'=>$token['expires'],'email_log_id'=>isset($mail['mail_log_id'])?(int)$mail['mail_log_id']:0,'smtp_config_id'=>isset($mail['smtp_id'])?(int)$mail['smtp_id']:0,'email_recipient'=>isset($mail['to'])?$mail['to']:'','email_pdf_attached'=>isset($mail['pdf_attached'])?(int)$mail['pdf_attached']:0,'email_pdf_name'=>isset($mail['pdf_name'])?$mail['pdf_name']:'','email_pdf_size'=>isset($mail['pdf_size'])?(int)$mail['pdf_size']:0,'email_pdf_sha256'=>isset($mail['pdf_sha256'])?$mail['pdf_sha256']:'','approval_url'=>isset($mail['approval_url'])?$mail['approval_url']:'','api_file'=>'quotations-invoice-smtp-fixed.php'));
        } catch (Throwable $e) {
            if ($token && !empty($token['id'])) {
                try {
                    $d = $pdo->prepare("DELETE FROM quotation_action_tokens WHERE id=:id AND tenant_id=:t AND quote_id=:q AND used_at IS NULL");
                    $d->execute(array(':id'=>$token['id'], ':t'=>$tenant, ':q'=>$id));
                } catch (Throwable $cleanupError) {
                    error_log('quote resend token cleanup '.$cleanupError->getMessage());
                }
            }
            error_log('quotation resend email '.$e->getMessage());
            qRes(500, false, 'Unable to resend quotation email: '.$e->getMessage());
        }
    }

    if ($action === 'upload_file') {
        if (!qTable($pdo, 'quote_files')) {
            qRes(500, false, 'Quotation file support is not installed. Run migration_quotation_jobber_builder.sql once.');
        }
        $quoteId = (int) qP('quote_id', 0);
        $category = strtolower(trim((string) qP('file_category', 'attachment')));
        $allowedCategories = array('attachment','image','introduction_image','note_attachment');
        if (!in_array($category, $allowedCategories, true)) qRes(422, false, 'Invalid quotation file category.');
        if (!qQuoteOwned($pdo, $tenant, $quoteId)) qRes(404, false, 'Quotation not found.');
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) qRes(422, false, 'Select a file to upload.');
        $f = $_FILES['file'];
        if ((int)$f['error'] !== UPLOAD_ERR_OK) qRes(422, false, 'The selected file could not be uploaded.');
        $max = $category === 'attachment' ? 50 * 1024 * 1024 : 25 * 1024 * 1024;
        if ((int)$f['size'] <= 0 || (int)$f['size'] > $max) qRes(422, false, $category === 'attachment' ? 'Attachments must be 50MB or smaller.' : 'Images must be 25MB or smaller.');
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $imageExt = array('jpg','jpeg','png','gif','webp','avif','heic');
        $attachmentExt = array('jpg','jpeg','png','gif','webp','avif','heic','pdf','doc','docx');
        $allowedExt = in_array($category,array('attachment','note_attachment'),true) ? $attachmentExt : $imageExt;
        if (!in_array($ext, $allowedExt, true)) qRes(422, false, 'This file type is not allowed.');
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM quote_files WHERE tenant_id=:t AND quote_id=:q AND file_category=:c");
        $cnt->execute(array(':t'=>$tenant, ':q'=>$quoteId, ':c'=>$category));
        if ((int)$cnt->fetchColumn() >= 10) qRes(422, false, 'A maximum of 10 files is allowed in this section.');
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detected = finfo_file($fi, $f['tmp_name']);
                if ($detected) $mime = (string)$detected;
                finfo_close($fi);
            }
        }
        if (in_array($category,array('image','introduction_image'),true) && strpos($mime, 'image/') !== 0 && $ext !== 'heic') qRes(422, false, 'Only image files can be uploaded here.');
        if ($category === 'note_attachment') {
            $relativeDir = 'private_uploads/quote-notes/tenant-' . (int)$tenant . '/quote-' . (int)$quoteId;
            $dir = dirname(__DIR__) . '/' . $relativeDir;
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) qRes(500, false, 'Unable to create private quotation note upload directory.');
            $privateRoot = dirname(__DIR__) . '/private_uploads';
            if (is_dir($privateRoot)) {
                $deny = $privateRoot . '/.htaccess'; if (!is_file($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n");
                $index = $privateRoot . '/index.php'; if (!is_file($index)) @file_put_contents($index, "<?php http_response_code(403); exit;\n");
            }
        } else {
            $dir = qUploadDirectory($tenant, $quoteId);
            $relativeDir = 'uploads/quotations/' . (int)$tenant . '/' . (int)$quoteId;
        }
        $safe = qSafeFileName($f['name']);
        $stored = date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '-' . $safe;
        $dest = $dir . '/' . $stored;
        if (!@move_uploaded_file($f['tmp_name'], $dest)) qRes(500, false, 'Unable to store the uploaded quotation file.');
        if ($category === 'note_attachment') @chmod($dest, 0640);
        $relative = $relativeDir . '/' . $stored;
        try {
            $ins = $pdo->prepare("INSERT INTO quote_files(tenant_id,quote_id,file_category,original_name,stored_name,file_path,mime_type,file_size,sort_order,created_by,created_at) VALUES(:t,:q,:c,:o,:s,:p,:m,:z,0,:u,NOW())");
            $ins->execute(array(':t'=>$tenant, ':q'=>$quoteId, ':c'=>$category, ':o'=>substr((string)$f['name'],0,255), ':s'=>$stored, ':p'=>$relative, ':m'=>$mime, ':z'=>(int)$f['size'], ':u'=>$user));
        } catch (Throwable $e) {
            @unlink($dest);
            throw $e;
        }
        $fileId=(int)$pdo->lastInsertId();
        $ctx=qQuoteContext($pdo,$tenant,$quoteId);
        if ($ctx) {
            qLog($pdo,$tenant,!empty($ctx['branch_id'])?(int)$ctx['branch_id']:$sessionBranch,$user,$quoteId,(int)$ctx['client_id'],'quote_file_uploaded','Quotation file uploaded: '.$ctx['quote_no'],array('file_id'=>$fileId,'category'=>$category,'name'=>(string)$f['name'],'size'=>(int)$f['size']));
            if (function_exists('tenantAuditLog')) { try { tenantAuditLog($pdo,'QUOTE_FILE_UPLOADED',$tenant,!empty($ctx['branch_id'])?(int)$ctx['branch_id']:$sessionBranch,$user,'quote_file',$fileId,null,array('quote_id'=>$quoteId,'category'=>$category,'name'=>(string)$f['name'],'size'=>(int)$f['size'])); } catch(Throwable $ae){ error_log('quote file upload audit '.$ae->getMessage()); } }
        }
        qRes(200, true, 'File uploaded successfully.', array('file'=>array('id'=>$fileId,'file_category'=>$category,'original_name'=>(string)$f['name'],'file_path'=>$relative,'mime_type'=>$mime,'file_size'=>(int)$f['size'])));
    }


    if ($action === 'upload_line_image') {
        if (!qCol($pdo,'quote_line_items','image_path')) qRes(500,false,'Quotation line-item images are not installed. Run migration_quotation_jobber_v2.sql once.');
        $quoteId=(int)qP('quote_id',0); $sort=(int)qP('line_index',-1);
        if ($quoteId<=0 || $sort<0 || !qQuoteOwned($pdo,$tenant,$quoteId)) qRes(404,false,'Quotation line item was not found.');
        $li=$pdo->prepare("SELECT qli.id,qli.image_path,q.client_id,q.branch_id,q.quote_no FROM quote_line_items qli INNER JOIN quotes q ON q.id=qli.quote_id WHERE qli.quote_id=:q AND qli.sort_order=:s AND q.tenant_id=:t LIMIT 1");
        $li->execute(array(':q'=>$quoteId,':s'=>$sort,':t'=>$tenant)); $row=$li->fetch(PDO::FETCH_ASSOC);
        if (!$row) qRes(404,false,'Quotation line item was not found.');
        if (empty($_FILES['file']) || !is_array($_FILES['file']) || (int)$_FILES['file']['error']!==UPLOAD_ERR_OK) qRes(422,false,'Select a valid line-item image.');
        $f=$_FILES['file']; if ((int)$f['size']<=0 || (int)$f['size']>25*1024*1024) qRes(422,false,'Line-item image must be 25MB or smaller.');
        $ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION)); if (!in_array($ext,array('avif','gif','jpg','jpeg','png','webp','heic'),true)) qRes(422,false,'Unsupported line-item image type.');
        $dir=qUploadDirectory($tenant,$quoteId).'/line-items'; if (!is_dir($dir) && !@mkdir($dir,0755,true) && !is_dir($dir)) qRes(500,false,'Unable to create line-item image directory.');
        $stored='line-'.$sort.'-'.date('YmdHis').'-'.bin2hex(random_bytes(5)).'.'.$ext; $dest=$dir.'/'.$stored;
        if (!@move_uploaded_file($f['tmp_name'],$dest)) qRes(500,false,'Unable to store the line-item image.');
        $rel='uploads/quotations/'.(int)$tenant.'/'.(int)$quoteId.'/line-items/'.$stored;
        $pdo->prepare("UPDATE quote_line_items SET image_path=:p WHERE id=:id")->execute(array(':p'=>$rel,':id'=>(int)$row['id']));
        if (!empty($row['image_path'])) { $old=dirname(__DIR__).'/'.ltrim((string)$row['image_path'],'/'); if (is_file($old)) @unlink($old); }
        qLog($pdo,$tenant,!empty($row['branch_id'])?(int)$row['branch_id']:$sessionBranch,$user,$quoteId,(int)$row['client_id'],'quote_line_image_uploaded','Quotation line image updated: '.$row['quote_no'],array('line_index'=>$sort,'image_path'=>$rel));
        if (function_exists('tenantAuditLog')) { try { tenantAuditLog($pdo,'QUOTE_LINE_IMAGE_UPDATED',$tenant,!empty($row['branch_id'])?(int)$row['branch_id']:$sessionBranch,$user,'quote_line_item',(int)$row['id'],null,array('quote_id'=>$quoteId,'line_index'=>$sort,'image_path'=>$rel)); } catch(Throwable $ae){ error_log('quote line image audit '.$ae->getMessage()); } }
        qRes(200,true,'Line-item image uploaded.',array('image_path'=>$rel,'line_index'=>$sort));
    }

    if ($action === 'delete_line_image') {
        if (!qCol($pdo,'quote_line_items','image_path')) qRes(500,false,'Quotation line-item images are not installed.');
        $quoteId=(int)qP('quote_id',0); $sort=(int)qP('line_index',-1);
        $li=$pdo->prepare("SELECT qli.id,qli.image_path,q.client_id,q.branch_id,q.quote_no FROM quote_line_items qli INNER JOIN quotes q ON q.id=qli.quote_id WHERE qli.quote_id=:q AND qli.sort_order=:s AND q.tenant_id=:t LIMIT 1");
        $li->execute(array(':q'=>$quoteId,':s'=>$sort,':t'=>$tenant)); $row=$li->fetch(PDO::FETCH_ASSOC); if(!$row)qRes(404,false,'Quotation line item was not found.');
        $pdo->prepare("UPDATE quote_line_items SET image_path=NULL WHERE id=:id")->execute(array(':id'=>(int)$row['id']));
        if (!empty($row['image_path'])) { $old=dirname(__DIR__).'/'.ltrim((string)$row['image_path'],'/'); if (is_file($old)) @unlink($old); }
        qLog($pdo,$tenant,!empty($row['branch_id'])?(int)$row['branch_id']:$sessionBranch,$user,$quoteId,(int)$row['client_id'],'quote_line_image_removed','Quotation line image removed: '.$row['quote_no'],array('line_index'=>$sort));
        if (function_exists('tenantAuditLog')) { try { tenantAuditLog($pdo,'QUOTE_LINE_IMAGE_REMOVED',$tenant,!empty($row['branch_id'])?(int)$row['branch_id']:$sessionBranch,$user,'quote_line_item',(int)$row['id'],array('image_path'=>$row['image_path']),array('image_path'=>null)); } catch(Throwable $ae){ error_log('quote line image delete audit '.$ae->getMessage()); } }
        qRes(200,true,'Line-item image removed.');
    }

    if ($action === 'delete_file') {
        if (!qTable($pdo, 'quote_files')) qRes(500, false, 'Quotation file support is not installed.');
        $fileId = (int) qP('file_id', 0);
        $s = $pdo->prepare("SELECT qf.*,q.client_id,q.branch_id,q.quote_no FROM quote_files qf INNER JOIN quotes q ON q.id=qf.quote_id AND q.tenant_id=qf.tenant_id WHERE qf.id=:id AND qf.tenant_id=:t LIMIT 1");
        $s->execute(array(':id'=>$fileId, ':t'=>$tenant));
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) qRes(404, false, 'Quotation file not found.');
        $pdo->prepare("DELETE FROM quote_files WHERE id=:id AND tenant_id=:t")->execute(array(':id'=>$fileId, ':t'=>$tenant));
        $full = dirname(__DIR__) . '/' . ltrim((string)$row['file_path'], '/');
        if (is_file($full)) @unlink($full);
        qLog($pdo,$tenant,!empty($row['branch_id'])?(int)$row['branch_id']:$sessionBranch,$user,(int)$row['quote_id'],(int)$row['client_id'],'quote_file_deleted','Quotation file removed: '.$row['quote_no'],array('file_id'=>$fileId,'category'=>$row['file_category'],'name'=>$row['original_name']));
        if (function_exists('tenantAuditLog')) { try { tenantAuditLog($pdo,'QUOTE_FILE_DELETED',$tenant,!empty($row['branch_id'])?(int)$row['branch_id']:$sessionBranch,$user,'quote_file',$fileId,$row,null); } catch(Throwable $ae){ error_log('quote file delete audit '.$ae->getMessage()); } }
        qRes(200, true, 'Quotation file deleted.');
    }

    if ($action === 'save') {
        if (!qTable($pdo,'quotation_action_tokens')) qRes(500,false,'Quotation approval support is not installed. Run migration_quotation_products_approval.sql once.');
        $needed = array('client_view_options_json','note_mentions_json','link_notes_to_jobs','link_notes_to_invoices','payment_plan_mode','payment_plan_split_type');
        foreach ($needed as $col) if (!qCol($pdo,'quotes',$col)) qRes(500,false,'Jobber-style quotation fields are not installed. Run migration_quotation_jobber_v2.sql once.');
        if (!qCol($pdo,'quote_line_items','markup_percent') || !qCol($pdo,'quote_line_items','image_path') || !qTable($pdo,'quote_payment_schedule_items')) qRes(500,false,'Quotation line item/payment schedule fields are not installed. Run migration_quotation_jobber_v2.sql once.');

        $id=(int)qP('quote_id',0);
        $quoteNoInput=substr(trim((string)qP('quote_no','')),0,100); $quoteNoCustom=(int)qP('quote_no_custom',0)===1;
        if ($quoteNoInput!=='' && preg_match('/[\r\n]/',$quoteNoInput)) qRes(422,false,'Quotation number contains invalid characters.');
        $requestId=(int)qP('request_id',0); $revisitId=(int)qP('assessment_reschedule_id',0); $directClientId=(int)qP('client_id',0); $directLocationId=(int)qP('location_id',0);
        $title=trim((string)qP('title','')); if($title==='')qRes(422,false,'Quotation title is required.');
        $requestedStatus=trim((string)qP('status','sent')); $allowed=array('approved','draft','internal_approval','sent','viewed','changes_requested','rejected','expired'); if(!in_array($requestedStatus,$allowed,true))qRes(422,false,'Select a valid quotation status.');
        if (!qCol($pdo,'quotes','introduction_title') || !qCol($pdo,'quotes','client_message') || !qCol($pdo,'quotes','disclaimer') || !qCol($pdo,'quotes','internal_notes') || !qCol($pdo,'quotes','custom_fields_json') || !qTable($pdo,'quote_sections')) qRes(500,false,'Quotation builder fields are not installed. Run migration_quotation_jobber_builder.sql once.');

        $introTitle=trim((string)qP('introduction_title','')); $intro=trim((string)qP('introduction','')); $clientMessage=trim((string)qP('client_message','')); $disclaimer=trim((string)qP('disclaimer','')); $internalNotes=trim((string)qP('internal_notes',''));
        $applyDisclaimerDefault=(int)qP('apply_disclaimer_default',0)===1;
        $linkJobs=(int)qP('link_notes_to_jobs',0)===1?1:0; $linkInvoices=(int)qP('link_notes_to_invoices',0)===1?1:0;

        $mentionRaw=json_decode((string)qP('note_mentions_json','[]'),true); if(!is_array($mentionRaw))$mentionRaw=array(); $mentionedUsers=qValidMentionUsers($pdo,$tenant,$mentionRaw); $mentionJson=json_encode(array_map('intval',array_keys($mentionedUsers)));
        $clientViewRaw=json_decode((string)qP('client_view_options_json','{}'),true); if(!is_array($clientViewRaw))$clientViewRaw=array();
        $clientView=array(); foreach(array('quantities','unit_prices','line_item_totals','totals') as $k)$clientView[$k]=array_key_exists($k,$clientViewRaw)?(bool)$clientViewRaw[$k]:true; $clientViewJson=json_encode($clientView,JSON_UNESCAPED_SLASHES);

        $customFields=json_decode((string)qP('custom_fields_json','[]'),true); if(!is_array($customFields))qRes(422,false,'Custom quotation fields are invalid.'); if(count($customFields)>20)qRes(422,false,'A maximum of 20 custom fields is allowed.');
        $cleanCustomFields=array(); foreach($customFields as $cf){if(!is_array($cf))continue;$label=trim((string)(isset($cf['label'])?$cf['label']:''));$value=trim((string)(isset($cf['value'])?$cf['value']:''));if($label===''&&$value==='')continue;$cleanCustomFields[]=array('label'=>substr($label,0,120),'value'=>substr($value,0,500));} $customFieldsJson=json_encode($cleanCustomFields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $sections=json_decode((string)qP('sections_json','[]'),true); if(!is_array($sections))qRes(422,false,'Quotation sections are invalid.'); if(count($sections)>30)qRes(422,false,'A maximum of 30 quotation text sections is allowed.');
        $salespersonId=(int)qP('salesperson_id',0); if($salespersonId>0){$sp=$pdo->prepare("SELECT id FROM users WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL AND status='active' LIMIT 1");$sp->execute(array(':id'=>$salespersonId,':t'=>$tenant));if(!$sp->fetchColumn())qRes(422,false,'Selected salesperson is invalid.');}
        $valid=trim((string)qP('valid_until',''));
        $items=json_decode((string)qP('items_json','[]'),true); if(!is_array($items)||!count($items))qRes(422,false,'Add at least one quotation item.');

        $request=null;$revisit=null;$client=null;$location=null;$branch=$sessionBranch;
        if($requestId>0){$request=qValidRequest($pdo,$tenant,$requestId);if(!$request)qRes(422,false,'Selected enquiry is invalid or closed.');if($revisitId>0){$revisit=qValidRevisit($pdo,$tenant,$requestId,$revisitId);if(!$revisit)qRes(422,false,'Selected revisit does not belong to this enquiry.');}$client=qClient($pdo,$tenant,(int)$request['client_id']);$location=!empty($request['location_id'])?qLocation($pdo,$tenant,(int)$request['client_id'],(int)$request['location_id']):null;$branch=!empty($request['branch_id'])?(int)$request['branch_id']:$sessionBranch;}
        else{$client=qClient($pdo,$tenant,$directClientId);if(!$client)qRes(422,false,'Select a customer for a direct quotation.');if($directLocationId>0){$location=qLocation($pdo,$tenant,(int)$client['id'],$directLocationId);if(!$location)qRes(422,false,'Selected customer location is invalid.');}$branch=!empty($client['branch_id'])?(int)$client['branch_id']:$sessionBranch;$revisitId=0;}

        $existing=null; if($id>0){$st=$pdo->prepare("SELECT * FROM quotes WHERE id=:id AND tenant_id=:t LIMIT 1");$st->execute(array(':id'=>$id,':t'=>$tenant));$existing=$st->fetch(PDO::FETCH_ASSOC);if(!$existing)qRes(404,false,'Quotation not found.');if(!in_array($existing['status'],array('draft','internal_approval','changes_requested','sent'),true))qRes(409,false,'This quotation can no longer be edited in its current status.');}
        elseif($requestId>0){if($hasRevisitColumn){if($revisitId>0){$st=$pdo->prepare("SELECT id FROM quotes WHERE tenant_id=:t AND request_id=:r AND assessment_reschedule_id=:h AND status<>'archived' LIMIT 1");$st->execute(array(':t'=>$tenant,':r'=>$requestId,':h'=>$revisitId));}else{$st=$pdo->prepare("SELECT id FROM quotes WHERE tenant_id=:t AND request_id=:r AND assessment_reschedule_id IS NULL AND status<>'archived' LIMIT 1");$st->execute(array(':t'=>$tenant,':r'=>$requestId));}}else{$st=$pdo->prepare("SELECT id FROM quotes WHERE tenant_id=:t AND request_id=:r AND status<>'archived' LIMIT 1");$st->execute(array(':t'=>$tenant,':r'=>$requestId));}if($st->fetchColumn())qRes(409,false,'This request/revisit already has a quotation.');}

        $desiredQuoteNo=null;if($existing)$desiredQuoteNo=$quoteNoInput!==''?$quoteNoInput:(string)$existing['quote_no'];elseif($quoteNoCustom&&$quoteNoInput!=='')$desiredQuoteNo=$quoteNoInput;
        if($desiredQuoteNo!==null&&$desiredQuoteNo!==''){$sql="SELECT id FROM quotes WHERE tenant_id=:t AND quote_no=:n".($id>0?" AND id<>:id":"")." LIMIT 1";$pp=array(':t'=>$tenant,':n'=>$desiredQuoteNo);if($id>0)$pp[':id']=$id;$st=$pdo->prepare($sql);$st->execute($pp);if($st->fetchColumn())qRes(409,false,'This quotation number is already in use. Enter another quotation number.');}
        $persistStatus=(!$existing&&$requestedStatus==='sent')?'draft':$requestedStatus;$status=$persistStatus;

        $sub=0;$disc=0;$tax=0;$tot=0;$norm=array();
        foreach($items as $idx=>$x){
            $psid=isset($x['product_service_id'])?(int)$x['product_service_id']:0;$prodId=isset($x['product_id'])?(int)$x['product_id']:0;
            if($psid>0){$st=$pdo->prepare("SELECT id FROM product_services WHERE id=:id AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");$st->execute(array(':id'=>$psid,':t'=>$tenant));if(!$st->fetchColumn())qRes(422,false,'One selected catalog item is no longer active.');}else$psid=null;
            if($prodId>0){if(!qTable($pdo,'products'))qRes(422,false,'Product master is not installed.');$st=$pdo->prepare("SELECT id FROM products WHERE id=:id AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");$st->execute(array(':id'=>$prodId,':t'=>$tenant));if(!$st->fetchColumn())qRes(422,false,'One selected product is no longer active.');}else$prodId=null;
            $name=trim((string)(isset($x['item_name'])?$x['item_name']:''));if($name==='')qRes(422,false,'Quotation item name is required.');$qty=max(.001,(float)(isset($x['quantity'])?$x['quantity']:1));$cost=max(0,(float)(isset($x['unit_cost'])?$x['unit_cost']:0));$markup=max(0,(float)(isset($x['markup_percent'])?$x['markup_percent']:0));$price=max(0,(float)(isset($x['unit_price'])?$x['unit_price']:0));$base=$qty*$price;$d=max(0,min($base,(float)(isset($x['discount_amount'])?$x['discount_amount']:0)));$tp=max(0,(float)(isset($x['tax_percent'])?$x['tax_percent']:0));$taxable=max(0,$base-$d);$ta=round($taxable*$tp/100,2);$lt=round($taxable+$ta,2);$sub+=$base;$disc+=$d;$tax+=$ta;$tot+=$lt;
            $img=trim((string)(isset($x['image_path'])?$x['image_path']:'')); if($img!=='' && ($id<=0 || strpos($img,'uploads/quotations/'.(int)$tenant.'/'.(int)$id.'/')!==0))$img='';
            $norm[]=array('psid'=>$psid,'prodid'=>$prodId,'name'=>$name,'description'=>trim((string)(isset($x['description'])?$x['description']:'')),'image_path'=>$img!==''?$img:null,'qty'=>$qty,'cost'=>$cost,'markup'=>$markup,'price'=>$price,'disc'=>$d,'tp'=>$tp,'ta'=>$ta,'lt'=>$lt,'optional'=>!empty($x['is_optional'])?1:0,'service_date'=>(!empty($x['service_date'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$x['service_date']))?(string)$x['service_date']:null,'sort'=>$idx);
        }
        $tot=round($tot,2);$sub=round($sub,2);$disc=round($disc,2);$tax=round($tax,2);

        $paymentMode=strtolower(trim((string)qP('payment_plan_mode','none')));if(!in_array($paymentMode,array('none','deposit','schedule'),true))$paymentMode='none';$splitType=strtolower(trim((string)qP('payment_plan_split_type','percent')));if(!in_array($splitType,array('percent','fixed'),true))$splitType='percent';
        $depositRequired=0;$depositType=null;$depositValue=null;$depositAmount=0.0;$scheduleRows=array();
        if($paymentMode==='deposit'){$depositRequired=1;$depositType=trim((string)qP('deposit_type','percent'));if(!in_array($depositType,array('fixed','percent'),true))$depositType='percent';$depositValue=max(0,(float)qP('deposit_value',0));$depositAmount=$depositType==='percent'?min($tot,round($tot*min(100,$depositValue)/100,2)):min($tot,$depositValue);}
        elseif($paymentMode==='schedule'){
            $raw=json_decode((string)qP('payment_schedule_json','[]'),true);if(!is_array($raw)||!count($raw))qRes(422,false,'Add at least one payment schedule row.');if(count($raw)>20)qRes(422,false,'A maximum of 20 scheduled payments is allowed.');$allocated=0;$requiredSeen=false;
            foreach($raw as $idx=>$r){if(!is_array($r))continue;$v=max(0,(float)(isset($r['split_value'])?$r['split_value']:0));$amount=$splitType==='percent'?round($tot*min(100,$v)/100,2):round(min($tot,$v),2);$required=!empty($r['required_quote_deposit'])&&!$requiredSeen?1:0;if($required)$requiredSeen=true;$allocated+=$splitType==='percent'?$v:$amount;$scheduleRows[]=array('split_value'=>$v,'amount'=>$amount,'description'=>substr(trim((string)(isset($r['description'])?$r['description']:('Payment '.($idx+1)))),0,190),'required'=>$required,'sort'=>$idx);}
            if(!$scheduleRows)qRes(422,false,'Add at least one payment schedule row.');$remaining=$splitType==='percent'?100-$allocated:$tot-$allocated;if($remaining < -0.01)qRes(422,false,'Payment schedule exceeds the quote total.');if($requestedStatus==='sent' && abs($remaining)>0.01)qRes(422,false,'Complete the payment schedule before sending the quote. Remaining: '.($splitType==='percent'?round($remaining,2).'%':qMoney($remaining,qCurrency($pdo,$tenant))).'.');
            foreach($scheduleRows as $r){if($r['required']){$depositRequired=1;$depositType=$splitType==='percent'?'percent':'fixed';$depositValue=$r['split_value'];$depositAmount=$r['amount'];break;}}
        }

        $pdo->beginTransaction();
        try{
            $clientId=(int)$client['id'];$locationId=$location?(int)$location['id']:null;
            if($id>0){$setRevisit=$hasRevisitColumn?',assessment_reschedule_id=:ar':'';$sql="UPDATE quotes SET quote_no=:no,branch_id=:b,client_id=:c,location_id=:l,request_id=:r $setRevisit,salesperson_id=:sp,title=:title,introduction_title=:it,introduction=:intro,client_message=:cm,disclaimer=:disclaimer,internal_notes=:notes,note_mentions_json=:nm,link_notes_to_jobs=:lj,link_notes_to_invoices=:li,custom_fields_json=:cf,client_view_options_json=:cv,status=:status,subtotal=:sub,discount_total=:disc,tax_total=:tax,total=:tot,deposit_required=:dr,deposit_type=:dt,deposit_value=:dv,deposit_amount=:da,payment_plan_mode=:pm,payment_plan_split_type=:pst,valid_until=:vu WHERE id=:id AND tenant_id=:t";$u=$pdo->prepare($sql);$quoteNo=$desiredQuoteNo!==null&&$desiredQuoteNo!==''?$desiredQuoteNo:(string)$existing['quote_no'];$up=array(':no'=>$quoteNo,':b'=>$branch>0?$branch:null,':c'=>$clientId,':l'=>$locationId,':r'=>$requestId>0?$requestId:null,':sp'=>$salespersonId>0?$salespersonId:null,':title'=>$title,':it'=>$introTitle!==''?substr($introTitle,0,190):null,':intro'=>$intro!==''?$intro:null,':cm'=>$clientMessage!==''?$clientMessage:null,':disclaimer'=>$disclaimer!==''?$disclaimer:null,':notes'=>$internalNotes!==''?$internalNotes:null,':nm'=>$mentionJson,':lj'=>$linkJobs,':li'=>$linkInvoices,':cf'=>$customFieldsJson,':cv'=>$clientViewJson,':status'=>$persistStatus,':sub'=>$sub,':disc'=>$disc,':tax'=>$tax,':tot'=>$tot,':dr'=>$depositRequired,':dt'=>$depositType,':dv'=>$depositValue,':da'=>$depositAmount,':pm'=>$paymentMode,':pst'=>$paymentMode==='none'?null:$splitType,':vu'=>$valid!==''?$valid:null,':id'=>$id,':t'=>$tenant);if($hasRevisitColumn)$up[':ar']=$revisitId>0?$revisitId:null;$u->execute($up);$pdo->prepare("DELETE FROM quote_line_items WHERE quote_id=:q")->execute(array(':q'=>$id));}
            else{$quoteNo=$desiredQuoteNo!==null&&$desiredQuoteNo!==''?$desiredQuoteNo:qNext($pdo,$tenant,$branch);$cols="tenant_id,branch_id,quote_no,revision_no,client_id,location_id,request_id".($hasRevisitColumn?',assessment_reschedule_id':'').",salesperson_id,title,introduction_title,introduction,client_message,disclaimer,internal_notes,note_mentions_json,link_notes_to_jobs,link_notes_to_invoices,custom_fields_json,client_view_options_json,status,subtotal,discount_total,tax_total,total,deposit_required,deposit_type,deposit_value,deposit_amount,payment_plan_mode,payment_plan_split_type,valid_until,created_by";$vals=":t,:b,:no,0,:c,:l,:r".($hasRevisitColumn?',:ar':'').",:sp,:title,:it,:intro,:cm,:disclaimer,:notes,:nm,:lj,:li,:cf,:cv,:status,:sub,:disc,:tax,:tot,:dr,:dt,:dv,:da,:pm,:pst,:vu,:u";$ins=$pdo->prepare("INSERT INTO quotes($cols) VALUES($vals)");$pp=array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':no'=>$quoteNo,':c'=>$clientId,':l'=>$locationId,':r'=>$requestId>0?$requestId:null,':sp'=>$salespersonId>0?$salespersonId:null,':title'=>$title,':it'=>$introTitle!==''?substr($introTitle,0,190):null,':intro'=>$intro!==''?$intro:null,':cm'=>$clientMessage!==''?$clientMessage:null,':disclaimer'=>$disclaimer!==''?$disclaimer:null,':notes'=>$internalNotes!==''?$internalNotes:null,':nm'=>$mentionJson,':lj'=>$linkJobs,':li'=>$linkInvoices,':cf'=>$customFieldsJson,':cv'=>$clientViewJson,':status'=>$persistStatus,':sub'=>$sub,':disc'=>$disc,':tax'=>$tax,':tot'=>$tot,':dr'=>$depositRequired,':dt'=>$depositType,':dv'=>$depositValue,':da'=>$depositAmount,':pm'=>$paymentMode,':pst'=>$paymentMode==='none'?null:$splitType,':vu'=>$valid!==''?$valid:null,':u'=>$user);if($hasRevisitColumn)$pp[':ar']=$revisitId>0?$revisitId:null;$ins->execute($pp);$id=(int)$pdo->lastInsertId();}

            $li=$pdo->prepare("INSERT INTO quote_line_items(quote_id,product_service_id,product_id,item_name,description,image_path,service_date,quantity,unit_cost,markup_percent,unit_price,discount_amount,tax_percent,tax_amount,line_total,is_optional,sort_order) VALUES(:q,:ps,:pr,:name,:d,:img,:sd,:qty,:cost,:markup,:price,:disc,:tp,:ta,:lt,:opt,:sort)");foreach($norm as $x)$li->execute(array(':q'=>$id,':ps'=>$x['psid'],':pr'=>$x['prodid'],':name'=>$x['name'],':d'=>$x['description']!==''?$x['description']:null,':img'=>$x['image_path'],':sd'=>$x['service_date'],':qty'=>$x['qty'],':cost'=>$x['cost'],':markup'=>$x['markup'],':price'=>$x['price'],':disc'=>$x['disc'],':tp'=>$x['tp'],':ta'=>$x['ta'],':lt'=>$x['lt'],':opt'=>$x['optional'],':sort'=>$x['sort']));
            if(!$existing&&$requestId>0){$oldRequestStatus=(string)$request['status'];$rq=$pdo->prepare("UPDATE service_requests SET status='converted',updated_at=NOW() WHERE id=:r AND tenant_id=:t");$rq->execute(array(':r'=>$requestId,':t'=>$tenant));if(qTable($pdo,'activity_events')){$eventDetails=json_encode(array('request_id'=>$requestId,'request_no'=>$request['request_no'],'quote_id'=>$id,'quote_no'=>$quoteNo,'assessment_reschedule_id'=>$revisitId>0?$revisitId:null,'old_status'=>$oldRequestStatus,'new_status'=>'converted'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$ev=$pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,client_id,title,details_json,visible_to_client) VALUES(:t,:b,:u,'user','request_converted_to_quote','request',:rid,:cid,:title,:details,0)");$ev->execute(array(':t'=>$tenant,':b'=>$branch>0?$branch:null,':u'=>$user,':rid'=>$requestId,':cid'=>$clientId,':title'=>'Request '.$request['request_no'].' converted to quotation '.$quoteNo,':details'=>$eventDetails));}}
            $pdo->prepare("DELETE FROM quote_sections WHERE tenant_id=:t AND quote_id=:q")->execute(array(':t'=>$tenant,':q'=>$id));if($sections){$si=$pdo->prepare("INSERT INTO quote_sections(tenant_id,quote_id,section_key,title,body,sort_order,created_by,created_at) VALUES(:t,:q,:k,:title,:body,:sort,:u,NOW())");$n=0;foreach($sections as $sec){if(!is_array($sec))continue;$k=preg_replace('/[^a-z0-9_-]+/i','',(string)(isset($sec['section_key'])?$sec['section_key']:'text'));if($k==='')$k='text';$st=trim((string)(isset($sec['title'])?$sec['title']:''));$sb=trim((string)(isset($sec['body'])?$sec['body']:''));if($st===''&&$sb==='')continue;$si->execute(array(':t'=>$tenant,':q'=>$id,':k'=>substr($k,0,40),':title'=>$st!==''?substr($st,0,190):null,':body'=>$sb!==''?$sb:null,':sort'=>$n++,':u'=>$user));}}
            $pdo->prepare("DELETE FROM quote_payment_schedule_items WHERE tenant_id=:t AND quote_id=:q")->execute(array(':t'=>$tenant,':q'=>$id));if($paymentMode==='schedule'&&$scheduleRows){$pi=$pdo->prepare("INSERT INTO quote_payment_schedule_items(tenant_id,quote_id,sort_order,split_type,split_value,amount,description,required_quote_deposit,created_at) VALUES(:t,:q,:s,:st,:sv,:a,:d,:r,NOW())");foreach($scheduleRows as $r)$pi->execute(array(':t'=>$tenant,':q'=>$id,':s'=>$r['sort'],':st'=>$splitType,':sv'=>$r['split_value'],':a'=>$r['amount'],':d'=>$r['description']!==''?$r['description']:null,':r'=>$r['required']));}
            if($applyDisclaimerDefault){if(!qTable($pdo,'quote_settings'))throw new RuntimeException('Quote settings table is not installed. Run migration_quotation_jobber_v2.sql once.');$qs=$pdo->prepare("INSERT INTO quote_settings(tenant_id,default_disclaimer,created_by,updated_by,created_at,updated_at) VALUES(:t,:d,:u,:u,NOW(),NOW()) ON DUPLICATE KEY UPDATE default_disclaimer=VALUES(default_disclaimer),updated_by=VALUES(updated_by),updated_at=NOW()");$qs->execute(array(':t'=>$tenant,':d'=>$disclaimer!==''?$disclaimer:null,':u'=>$user));}
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

        $mail=array('status'=>'skipped','notice'=>'Email not sent.','mail_log_id'=>0,'smtp_id'=>0,'to'=>'','pdf_attached'=>0,'pdf_name'=>'','pdf_size'=>0,'pdf_sha256'=>'','approval_url'=>'','approval_expires'=>'');
        if($requestedStatus==='sent'&&(!$existing||in_array((string)$existing['status'],array('draft','internal_approval','sent','viewed','changes_requested','expired'),true))){$mailToken=null;try{$mailToken=qCreatePendingApprovalToken($pdo,$tenant,$id,(int)$client['id'],14);$quoteForMail=array('id'=>$id,'quote_no'=>$quoteNo,'total'=>$tot,'approval_expires'=>$mailToken['expires'],'client_view_options'=>$clientView);$mailItems=array();foreach($norm as $x)$mailItems[]=array('item_name'=>$x['name'],'quantity'=>$x['qty'],'unit_price'=>$x['price'],'line_total'=>$x['lt']);$mail=qSendQuotationEmail($pdo,$tenant,$branch,$quoteForMail,$client,$mailItems,qCurrency($pdo,$tenant),$mailToken['plain']);if($mail['status']==='sent'){$pdo->beginTransaction();try{$pdo->prepare("UPDATE quotation_action_tokens SET used_at=NOW() WHERE tenant_id=:t AND quote_id=:q AND used_at IS NULL AND id<>:id")->execute(array(':t'=>$tenant,':q'=>$id,':id'=>$mailToken['id']));$pdo->prepare("UPDATE quotes SET status='sent',sent_at=NOW() WHERE id=:id AND tenant_id=:t")->execute(array(':id'=>$id,':t'=>$tenant));$status='sent';$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}else{if($mailToken&&!empty($mailToken['id']))$pdo->prepare("DELETE FROM quotation_action_tokens WHERE id=:id AND tenant_id=:t AND quote_id=:q AND used_at IS NULL")->execute(array(':id'=>$mailToken['id'],':t'=>$tenant,':q'=>$id));}}catch(Throwable $mailError){if($mailToken&&!empty($mailToken['id'])){try{$pdo->prepare("DELETE FROM quotation_action_tokens WHERE id=:id AND tenant_id=:t AND quote_id=:q AND used_at IS NULL")->execute(array(':id'=>$mailToken['id'],':t'=>$tenant,':q'=>$id));}catch(Throwable $ce){error_log('quotation initial mail token cleanup '.$ce->getMessage());}}error_log('quotation approval email '.$mailError->getMessage());$mail=array('status'=>'failed','notice'=>'Quotation saved, but approval email failed: '.$mailError->getMessage(),'mail_log_id'=>0,'smtp_id'=>0,'to'=>isset($client['email'])?$client['email']:'','pdf_attached'=>0,'pdf_name'=>'','pdf_size'=>0,'pdf_sha256'=>'','approval_url'=>'','approval_expires'=>'');}}

        if($mentionedUsers)qNotifyQuoteMentions($pdo,$tenant,$branch,$user,$id,$quoteNo,$mentionedUsers);
        $details=array('request_id'=>$requestId>0?$requestId:null,'request_no'=>$requestId>0&&$request?$request['request_no']:null,'request_converted_to_quote'=>(!$existing&&$requestId>0)?1:0,'assessment_reschedule_id'=>$revisitId>0?$revisitId:null,'source'=>$requestId>0?($revisitId>0?'revisit':'original_enquiry'):'direct_quotation','total'=>$tot,'status'=>$status,'payment_plan_mode'=>$paymentMode,'mentioned_user_ids'=>array_map('intval',array_keys($mentionedUsers)),'email_status'=>$mail['status'],'email_log_id'=>isset($mail['mail_log_id'])?(int)$mail['mail_log_id']:0,'smtp_config_id'=>isset($mail['smtp_id'])?(int)$mail['smtp_id']:0,'email_recipient'=>isset($mail['to'])?$mail['to']:'','email_pdf_attached'=>isset($mail['pdf_attached'])?(int)$mail['pdf_attached']:0,'email_pdf_name'=>isset($mail['pdf_name'])?$mail['pdf_name']:'','email_pdf_size'=>isset($mail['pdf_size'])?(int)$mail['pdf_size']:0,'email_pdf_sha256'=>isset($mail['pdf_sha256'])?$mail['pdf_sha256']:'','approval_expires'=>isset($mailToken['expires'])?$mailToken['expires']:null);
        qLog($pdo,$tenant,$branch,$user,$id,(int)$client['id'],$existing?'quote_updated':'quote_created',($existing?'Quotation updated: ':'Quotation created: ').$quoteNo,$details);
        if(function_exists('tenantAuditLog')){try{tenantAuditLog($pdo,$existing?'QUOTE_UPDATED':'QUOTE_CREATED',$tenant,$branch,$user,'quote',$id,$existing,array('quote_no'=>$quoteNo,'request_id'=>$requestId>0?$requestId:null,'total'=>$tot,'status'=>$status,'payment_plan_mode'=>$paymentMode,'client_view_options'=>$clientView,'mentioned_user_ids'=>array_map('intval',array_keys($mentionedUsers)),'email_status'=>$mail['status'],'email_log_id'=>isset($mail['mail_log_id'])?(int)$mail['mail_log_id']:0,'smtp_config_id'=>isset($mail['smtp_id'])?(int)$mail['smtp_id']:0,'email_recipient'=>isset($mail['to'])?$mail['to']:'','email_pdf_attached'=>isset($mail['pdf_attached'])?(int)$mail['pdf_attached']:0,'email_pdf_name'=>isset($mail['pdf_name'])?$mail['pdf_name']:'','email_pdf_size'=>isset($mail['pdf_size'])?(int)$mail['pdf_size']:0,'email_pdf_sha256'=>isset($mail['pdf_sha256'])?$mail['pdf_sha256']:'','approval_expires'=>isset($mailToken['expires'])?$mailToken['expires']:null));}catch(Throwable $ae){error_log('quote audit '.$ae->getMessage());}}
        qRes(200,true,'Quotation '.$quoteNo.($existing?' updated':' created').' successfully.',array('quote_id'=>$id,'quote_no'=>$quoteNo,'request_id'=>$requestId>0?$requestId:null,'request_no'=>$requestId>0&&$request?$request['request_no']:null,'request_converted_to_quote'=>(!$existing&&$requestId>0)?1:0,'source'=>$requestId>0?($revisitId>0?'revisit':'original_enquiry'):'direct_quotation','status'=>$status,'email_status'=>$mail['status'],'email_notice'=>$mail['notice'],'email_log_id'=>isset($mail['mail_log_id'])?(int)$mail['mail_log_id']:0,'smtp_config_id'=>isset($mail['smtp_id'])?(int)$mail['smtp_id']:0,'email_recipient'=>isset($mail['to'])?$mail['to']:'','email_pdf_attached'=>isset($mail['pdf_attached'])?(int)$mail['pdf_attached']:0,'email_pdf_name'=>isset($mail['pdf_name'])?$mail['pdf_name']:'','email_pdf_size'=>isset($mail['pdf_size'])?(int)$mail['pdf_size']:0,'email_pdf_sha256'=>isset($mail['pdf_sha256'])?$mail['pdf_sha256']:'','approval_expires'=>isset($mailToken['expires'])?$mailToken['expires']:null,'api_file'=>'quotations-smtp-pdf-approval.php'));
    }
    qRes(400, false, 'Unsupported quotation action.');
} catch (PDOException $e) {
    error_log('FieldPlx quotations PDO ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062)
        qRes(409, false, 'A duplicate record already exists.');
    qRes(500, false, 'Unable to process the quotation request.');
} catch (Throwable $e) {
    error_log('FieldPlx quotations ' . $e->getMessage());
    qRes(500, false, 'Unable to process the quotation request.');
}