<?php
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$pageTitle = 'Quotation';
$activePage = 'quotes';

$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = !empty($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
$quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($tenantId <= 0 || $quoteId <= 0) {
    header('Location: quotes.php');
    exit;
}

function qvTableExists(PDO $pdo, $table) {
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $s->execute(array(':t'=>$table));
    return $cache[$table] = ((int)$s->fetchColumn() > 0);
}
function qvEsc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function qvMoney($v) { return '₹' . number_format((float)$v, 2); }
function qvDate($v, $time=false) {
    if (!$v) return '—';
    $ts = strtotime((string)$v);
    return $ts ? date($time ? 'M j, Y · g:i A' : 'M j, Y', $ts) : '—';
}
function qvInitials($name) {
    $parts = preg_split('/\s+/', trim((string)$name)); $out='';
    foreach ($parts as $p) { if ($p!=='') $out .= strtoupper(substr($p,0,1)); if (strlen($out)>=2) break; }
    return $out ?: 'U';
}
function qvQuoteOwned(PDO $pdo, $quoteId, $tenantId) {
    $s=$pdo->prepare("SELECT id FROM quotes WHERE id=:q AND tenant_id=:t LIMIT 1");
    $s->execute(array(':q'=>$quoteId, ':t'=>$tenantId));
    return (int)$s->fetchColumn() > 0;
}
function qvSetFlash($type,$message) {
    $_SESSION['qv_flash'] = array('type'=>$type,'message'=>$message);
}
function qvRedirect($quoteId,$anchor='') {
    $url='quotation-view.php?id='.(int)$quoteId;
    if ($anchor!=='') $url.='#'.rawurlencode($anchor);
    header('Location: '.$url); exit;
}
function qvDeleteDiskFile($path) {
    $path = ltrim((string)$path,'/');
    if ($path==='') return;
    $full = __DIR__ . '/' . $path;
    if (is_file($full)) @unlink($full);
}

function qvSaveSignature(PDO $pdo,$tenantId,$quoteId,$userId,$clientId,$signerName,$dataUrl) {
    if (!qvTableExists($pdo,'attachments')) throw new RuntimeException('Signature storage is unavailable.');
    $signerName=trim((string)$signerName);
    if ($signerName==='') throw new RuntimeException('Enter the signer name.');
    if (!preg_match('~^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$~',(string)$dataUrl,$m)) throw new RuntimeException('Please draw a signature before saving.');
    $binary=base64_decode(str_replace(array("\r","\n"),'',$m[1]),true);
    if ($binary===false || strlen($binary)<100) throw new RuntimeException('The signature is empty or invalid.');
    if (strlen($binary)>5*1024*1024) throw new RuntimeException('The signature image is too large.');
    $dir=__DIR__.'/uploads/quotes/'.(int)$tenantId.'/'.(int)$quoteId;
    if (!is_dir($dir) && !@mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Unable to create the signature folder.');
    $stored='signature-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.png';
    $full=$dir.'/'.$stored;
    if (@file_put_contents($full,$binary)===false) throw new RuntimeException('Unable to save the signature image.');
    $path='uploads/quotes/'.(int)$tenantId.'/'.(int)$quoteId.'/'.$stored;
    try {
        $st=$pdo->prepare("INSERT INTO attachments (tenant_id,related_type,related_id,job_id,visit_id,workflow_step_id,uploaded_by,file_name,file_path,file_mime,file_size,attachment_type,description,created_at) VALUES (:t,'quote',:q,NULL,NULL,NULL,:u,:n,:p,'image/png',:z,'signature',:d,NOW())");
        $st->execute(array(':t'=>$tenantId,':q'=>$quoteId,':u'=>$userId?:null,':n'=>$stored,':p'=>$path,':z'=>strlen($binary),':d'=>'Quotation client signature - '.$signerName));
        $attachmentId=(int)$pdo->lastInsertId();
        if (qvTableExists($pdo,'audit_logs')) {
            $userName=(string)($_SESSION['user_name']??$_SESSION['tenant_user_name']??'');
            $log=$pdo->prepare("INSERT INTO audit_logs (tenant_id,user_id,user_name,audit_category,module,action,object_type,object_id,record_no,new_values,ip_address,device_type,user_agent,created_at) VALUES (:t,:u,:un,'QUOTE_CHANGES','Quotes','QUOTE_SIGNATURE_COLLECTED','quote',:q,NULL,:nv,:ip,:device,:ua,NOW())");
            $log->execute(array(':t'=>$tenantId,':u'=>$userId?:null,':un'=>$userName!==''?$userName:null,':q'=>$quoteId,':nv'=>json_encode(array('attachment_id'=>$attachmentId,'signed_by'=>$signerName),JSON_UNESCAPED_UNICODE),':ip'=>(string)($_SERVER['REMOTE_ADDR']??''),':device'=>'desktop',':ua'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)));
        }
        return $attachmentId;
    } catch (Throwable $e) {
        @unlink($full);
        throw $e;
    }
}
function qvUploadQuoteFile(PDO $pdo,$tenantId,$quoteId,$userId,$category,array $file) {
    $allowedCategories=array('attachment','image','introduction_image','note_attachment');
    if (!in_array($category,$allowedCategories,true)) throw new RuntimeException('Invalid file category.');
    if (!isset($file['error']) || $file['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Choose a valid file.');
    $isImage=in_array($category,array('image','introduction_image'),true);
    $allowed=$isImage?array('jpg','jpeg','png','gif','webp','avif','heic'):array('jpg','jpeg','png','gif','webp','avif','heic','pdf','doc','docx');
    $max=$isImage?25*1024*1024:50*1024*1024;
    if ((int)$file['size']<=0 || (int)$file['size']>$max) throw new RuntimeException('The selected file is too large.');
    $ext=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
    if (!in_array($ext,$allowed,true)) throw new RuntimeException('This file type is not allowed.');
    $dir=__DIR__.'/uploads/quotations/'.(int)$tenantId.'/'.(int)$quoteId;
    if (!is_dir($dir) && !@mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Unable to create upload folder.');
    $safeBase=preg_replace('/[^A-Za-z0-9._-]+/','-',pathinfo((string)$file['name'],PATHINFO_FILENAME));
    $stored=date('YmdHis').'-'.bin2hex(random_bytes(5)).'-'.trim($safeBase,'-').'.'.$ext;
    $target=$dir.'/'.$stored;
    if (!@move_uploaded_file($file['tmp_name'],$target)) throw new RuntimeException('Unable to save the uploaded file.');
    $path='uploads/quotations/'.(int)$tenantId.'/'.(int)$quoteId.'/'.$stored;
    $s=$pdo->prepare("INSERT INTO quote_files (tenant_id,quote_id,file_category,original_name,stored_name,file_path,mime_type,file_size,sort_order,created_by,created_at) VALUES (:t,:q,:c,:o,:s,:p,:m,:z,0,:u,NOW())");
    $s->execute(array(':t'=>$tenantId,':q'=>$quoteId,':c'=>$category,':o'=>(string)$file['name'],':s'=>$stored,':p'=>$path,':m'=>(string)($file['type']??''),':z'=>(int)$file['size'],':u'=>$userId?:null));
    return (int)$pdo->lastInsertId();
}

if (empty($_SESSION['quotation_view_csrf'])) $_SESSION['quotation_view_csrf']=bin2hex(random_bytes(32));
$csrfToken=(string)$_SESSION['quotation_view_csrf'];

/* Same-page direct section actions: no quotation API. */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=(string)($_POST['action']??'');
    $token=(string)($_POST['csrf_token']??'');
    if ($action!=='' && ($token==='' || !hash_equals($csrfToken,$token))) {
        qvSetFlash('error','Your session expired. Refresh and try again.');
        qvRedirect($quoteId);
    }
    if ($action!=='' && !qvQuoteOwned($pdo,$quoteId,$tenantId)) {
        qvSetFlash('error','Quotation not found.');
        qvRedirect($quoteId);
    }
    try {
        if ($action==='mark_awaiting_response') {
            $pdo->prepare("UPDATE quotes SET status='awaiting_response', updated_at=NOW() WHERE id=:q AND tenant_id=:t")->execute(array(':q'=>$quoteId,':t'=>$tenantId));
            qvSetFlash('success','Quote marked as Awaiting Response.'); qvRedirect($quoteId);
        }
        if ($action==='mark_approved') {
            $pdo->prepare("UPDATE quotes SET status='approved', approved_at=COALESCE(approved_at,NOW()), updated_at=NOW() WHERE id=:q AND tenant_id=:t")->execute(array(':q'=>$quoteId,':t'=>$tenantId));
            qvSetFlash('success','Quote marked as Approved.'); qvRedirect($quoteId);
        }
        if ($action==='save_note') {
            $note=trim((string)($_POST['internal_notes']??''));
            $s=$pdo->prepare("UPDATE quotes SET internal_notes=:v,updated_at=NOW() WHERE id=:q AND tenant_id=:t");
            $s->execute(array(':v'=>$note!==''?$note:null,':q'=>$quoteId,':t'=>$tenantId));
            qvSetFlash('success','Internal note saved.'); qvRedirect($quoteId,'notes');
        }
        if ($action==='save_introduction') {
            $title=trim((string)($_POST['introduction_title']??''));
            $body=trim((string)($_POST['introduction']??''));
            $s=$pdo->prepare("UPDATE quotes SET introduction_title=:title,introduction=:body,updated_at=NOW() WHERE id=:q AND tenant_id=:t");
            $s->execute(array(':title'=>$title!==''?$title:null,':body'=>$body!==''?$body:null,':q'=>$quoteId,':t'=>$tenantId));
            if (!empty($_FILES['section_image']['name'])) {
                if (qvTableExists($pdo,'quote_files')) {
                    $old=$pdo->prepare("SELECT id,file_path FROM quote_files WHERE tenant_id=:t AND quote_id=:q AND file_category='introduction_image'");
                    $old->execute(array(':t'=>$tenantId,':q'=>$quoteId));
                    foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $r) qvDeleteDiskFile($r['file_path']);
                    $pdo->prepare("DELETE FROM quote_files WHERE tenant_id=:t AND quote_id=:q AND file_category='introduction_image'")->execute(array(':t'=>$tenantId,':q'=>$quoteId));
                    qvUploadQuoteFile($pdo,$tenantId,$quoteId,$userId,'introduction_image',$_FILES['section_image']);
                }
            }
            qvSetFlash('success','Introduction saved.'); qvRedirect($quoteId,'introduction');
        }
        if ($action==='delete_introduction') {
            $pdo->prepare("UPDATE quotes SET introduction_title=NULL,introduction=NULL,updated_at=NOW() WHERE id=:q AND tenant_id=:t")->execute(array(':q'=>$quoteId,':t'=>$tenantId));
            if (qvTableExists($pdo,'quote_files')) {
                $old=$pdo->prepare("SELECT file_path FROM quote_files WHERE tenant_id=:t AND quote_id=:q AND file_category='introduction_image'");
                $old->execute(array(':t'=>$tenantId,':q'=>$quoteId)); foreach($old->fetchAll(PDO::FETCH_ASSOC) as $r) qvDeleteDiskFile($r['file_path']);
                $pdo->prepare("DELETE FROM quote_files WHERE tenant_id=:t AND quote_id=:q AND file_category='introduction_image'")->execute(array(':t'=>$tenantId,':q'=>$quoteId));
            }
            qvSetFlash('success','Introduction removed.'); qvRedirect($quoteId);
        }
        if ($action==='save_client_message') {
            $v=trim((string)($_POST['client_message']??''));
            $pdo->prepare("UPDATE quotes SET client_message=:v,updated_at=NOW() WHERE id=:q AND tenant_id=:t")->execute(array(':v'=>$v!==''?$v:null,':q'=>$quoteId,':t'=>$tenantId));
            qvSetFlash('success','Client message saved.'); qvRedirect($quoteId,'client-message');
        }
        if ($action==='delete_client_message') {
            $pdo->prepare("UPDATE quotes SET client_message=NULL,updated_at=NOW() WHERE id=:q AND tenant_id=:t")->execute(array(':q'=>$quoteId,':t'=>$tenantId));
            qvSetFlash('success','Client message removed.'); qvRedirect($quoteId);
        }
        if ($action==='save_disclaimer') {
            $v=trim((string)($_POST['disclaimer']??''));
            $pdo->prepare("UPDATE quotes SET disclaimer=:v,updated_at=NOW() WHERE id=:q AND tenant_id=:t")->execute(array(':v'=>$v!==''?$v:null,':q'=>$quoteId,':t'=>$tenantId));
            qvSetFlash('success','Contract / Disclaimer saved.'); qvRedirect($quoteId,'disclaimer');
        }
        if ($action==='delete_disclaimer') {
            $pdo->prepare("UPDATE quotes SET disclaimer=NULL,updated_at=NOW() WHERE id=:q AND tenant_id=:t")->execute(array(':q'=>$quoteId,':t'=>$tenantId));
            qvSetFlash('success','Contract / Disclaimer removed.'); qvRedirect($quoteId);
        }
        if ($action==='save_text_section') {
            $sectionId=(int)($_POST['section_id']??0);
            $title=trim((string)($_POST['section_title']??''));
            $body=trim((string)($_POST['section_body']??''));
            if ($title==='' && $body==='') throw new RuntimeException('Enter a title or description.');
            if ($sectionId>0) {
                $s=$pdo->prepare("UPDATE quote_sections SET title=:title,body=:body,updated_at=NOW() WHERE id=:id AND quote_id=:q AND tenant_id=:t");
                $s->execute(array(':title'=>$title!==''?$title:null,':body'=>$body!==''?$body:null,':id'=>$sectionId,':q'=>$quoteId,':t'=>$tenantId));
            } else {
                $sort=(int)$pdo->query("SELECT COALESCE(MAX(sort_order),-1)+1 FROM quote_sections WHERE tenant_id=".(int)$tenantId." AND quote_id=".(int)$quoteId)->fetchColumn();
                $s=$pdo->prepare("INSERT INTO quote_sections (tenant_id,quote_id,section_key,title,body,sort_order,created_by,created_at) VALUES (:t,:q,'text',:title,:body,:sort,:u,NOW())");
                $s->execute(array(':t'=>$tenantId,':q'=>$quoteId,':title'=>$title!==''?$title:null,':body'=>$body!==''?$body:null,':sort'=>$sort,':u'=>$userId?:null));
            }
            qvSetFlash('success','Text section saved.'); qvRedirect($quoteId,'custom-sections');
        }
        if ($action==='delete_text_section') {
            $sectionId=(int)($_POST['section_id']??0);
            $pdo->prepare("DELETE FROM quote_sections WHERE id=:id AND quote_id=:q AND tenant_id=:t")->execute(array(':id'=>$sectionId,':q'=>$quoteId,':t'=>$tenantId));
            qvSetFlash('success','Text section removed.'); qvRedirect($quoteId,'custom-sections');
        }
        if ($action==='upload_quote_file') {
            $category=(string)($_POST['file_category']??'attachment');
            if (!qvTableExists($pdo,'quote_files')) throw new RuntimeException('Quote file storage is unavailable.');
            if (empty($_FILES['quote_file']['name'])) throw new RuntimeException('Choose a file to upload.');
            qvUploadQuoteFile($pdo,$tenantId,$quoteId,$userId,$category,$_FILES['quote_file']);
            qvSetFlash('success',$category==='image'?'Image added.':'Attachment added.'); qvRedirect($quoteId,$category==='image'?'images':'attachments');
        }
        if ($action==='delete_quote_file') {
            $fileId=(int)($_POST['file_id']??0);
            $s=$pdo->prepare("SELECT file_path,file_category FROM quote_files WHERE id=:id AND tenant_id=:t AND quote_id=:q LIMIT 1");
            $s->execute(array(':id'=>$fileId,':t'=>$tenantId,':q'=>$quoteId)); $f=$s->fetch(PDO::FETCH_ASSOC);
            if ($f) { $pdo->prepare("DELETE FROM quote_files WHERE id=:id AND tenant_id=:t AND quote_id=:q")->execute(array(':id'=>$fileId,':t'=>$tenantId,':q'=>$quoteId)); qvDeleteDiskFile($f['file_path']); }
            qvSetFlash('success','File removed.'); qvRedirect($quoteId,$f&&$f['file_category']==='image'?'images':'attachments');
        }

        if ($action==='save_signature') {
            $signerName=trim((string)($_POST['signed_by_name']??''));
            $signatureData=(string)($_POST['signature_data']??'');
            qvSaveSignature($pdo,$tenantId,$quoteId,$userId,(int)($_POST['client_id']??0),$signerName,$signatureData);
            qvSetFlash('success','Client signature saved.'); qvRedirect($quoteId);
        }
        if ($action==='archive_quote') {
            $pdo->prepare("UPDATE quotes SET status='archived',updated_at=NOW() WHERE id=:q AND tenant_id=:t")->execute(array(':q'=>$quoteId,':t'=>$tenantId));
            $_SESSION['flash_success']='Quotation archived.';
            header('Location: quotes.php'); exit;
        }
    } catch (Throwable $e) {
        error_log('FieldPlx quotation-view section action: '.$e->getMessage());
        qvSetFlash('error',$e instanceof RuntimeException ? $e->getMessage() : 'Unable to update quotation section.');
        qvRedirect($quoteId);
    }
}

$flash=$_SESSION['qv_flash']??null; unset($_SESSION['qv_flash']);

$sql="SELECT q.*,c.display_name AS client_name,c.company_name AS client_company,c.phone AS client_phone,c.alternate_phone AS client_alt_phone,c.email AS client_email,
            l.name AS location_name,l.address_line1 AS location_address1,l.address_line2 AS location_address2,l.city AS location_city,l.state AS location_state,l.postal_code AS location_postal,
            CONCAT_WS(' ',sp.first_name,sp.last_name) AS salesperson_name,CONCAT_WS(' ',cr.first_name,cr.last_name) AS creator_name,r.request_no,r.title AS request_title
      FROM quotes q
      INNER JOIN clients c ON c.id=q.client_id AND c.tenant_id=q.tenant_id
      LEFT JOIN client_locations l ON l.id=q.location_id AND l.tenant_id=q.tenant_id
      LEFT JOIN users sp ON sp.id=q.salesperson_id AND sp.tenant_id=q.tenant_id
      LEFT JOIN users cr ON cr.id=q.created_by AND cr.tenant_id=q.tenant_id
      LEFT JOIN service_requests r ON r.id=q.request_id AND r.tenant_id=q.tenant_id
      WHERE q.id=:q AND q.tenant_id=:t LIMIT 1";
$s=$pdo->prepare($sql); $s->execute(array(':q'=>$quoteId,':t'=>$tenantId)); $quote=$s->fetch(PDO::FETCH_ASSOC);
if (!$quote) { $_SESSION['flash_error']='Quotation not found.'; header('Location: quotes.php'); exit; }

$items=array(); if(qvTableExists($pdo,'quote_line_items')){ $s=$pdo->prepare("SELECT * FROM quote_line_items WHERE quote_id=:q ORDER BY sort_order,id");$s->execute(array(':q'=>$quoteId));$items=$s->fetchAll(PDO::FETCH_ASSOC); }
$sections=array(); if(qvTableExists($pdo,'quote_sections')){ $s=$pdo->prepare("SELECT * FROM quote_sections WHERE tenant_id=:t AND quote_id=:q ORDER BY sort_order,id");$s->execute(array(':t'=>$tenantId,':q'=>$quoteId));$sections=$s->fetchAll(PDO::FETCH_ASSOC); }
$paymentSchedule=array(); if(qvTableExists($pdo,'quote_payment_schedule_items')){ $s=$pdo->prepare("SELECT * FROM quote_payment_schedule_items WHERE tenant_id=:t AND quote_id=:q ORDER BY sort_order,id");$s->execute(array(':t'=>$tenantId,':q'=>$quoteId));$paymentSchedule=$s->fetchAll(PDO::FETCH_ASSOC); }
$quoteFiles=array(); if(qvTableExists($pdo,'quote_files')){ $s=$pdo->prepare("SELECT * FROM quote_files WHERE tenant_id=:t AND quote_id=:q ORDER BY sort_order,id");$s->execute(array(':t'=>$tenantId,':q'=>$quoteId));$quoteFiles=$s->fetchAll(PDO::FETCH_ASSOC); }

$introImages=array();$images=array();$attachments=array();$noteFiles=array();
foreach($quoteFiles as $f){ $cat=(string)$f['file_category']; if($cat==='introduction_image')$introImages[]=$f; elseif($cat==='image')$images[]=$f; elseif($cat==='note_attachment')$noteFiles[]=$f; else $attachments[]=$f; }
$quoteSignatures=array();
if(qvTableExists($pdo,'attachments')){ $s=$pdo->prepare("SELECT id,file_name,file_path,description,created_at FROM attachments WHERE tenant_id=:t AND related_type='quote' AND related_id=:q AND attachment_type='signature' ORDER BY id DESC");$s->execute(array(':t'=>$tenantId,':q'=>$quoteId));$quoteSignatures=$s->fetchAll(PDO::FETCH_ASSOC); }

$job=null; if(qvTableExists($pdo,'jobs')){ $s=$pdo->prepare("SELECT id,job_no,title,status,created_at FROM jobs WHERE tenant_id=:t AND quote_id=:q AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");$s->execute(array(':t'=>$tenantId,':q'=>$quoteId));$job=$s->fetch(PDO::FETCH_ASSOC)?:null; }

$history=array();
if(qvTableExists($pdo,'audit_logs')){
    $s=$pdo->prepare("SELECT user_name,action,created_at FROM audit_logs WHERE tenant_id=:t AND object_type='quote' AND object_id=:q ORDER BY created_at DESC,id DESC LIMIT 100");$s->execute(array(':t'=>$tenantId,':q'=>$quoteId));
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){ $a=strtoupper((string)$r['action']);$map=array('QUOTE_CREATED'=>'created the quote','QUOTE_UPDATED'=>'updated the quote','QUOTE_STATUS_CHANGED'=>'changed the quote status','QUOTE_APPROVED_BY_CLIENT'=>'approved the quote','QUOTE_REJECTED_BY_CLIENT'=>'rejected the quote');$history[]=array('who'=>trim((string)$r['user_name'])?:'System','action'=>$map[$a]??strtolower(str_replace('_',' ',$a)),'created_at'=>$r['created_at']); }
}
if(!$history){ $history[]=array('who'=>trim((string)$quote['creator_name'])?:'Team member','action'=>'created the quote','created_at'=>$quote['created_at']); if(!empty($quote['sent_at']))$history[]=array('who'=>'System','action'=>'sent the quote','created_at'=>$quote['sent_at']); if(!empty($quote['viewed_at']))$history[]=array('who'=>'Customer','action'=>'viewed the quote','created_at'=>$quote['viewed_at']); if(!empty($quote['approved_at']))$history[]=array('who'=>'Customer','action'=>'approved the quote','created_at'=>$quote['approved_at']); if($job)$history[]=array('who'=>'Team member','action'=>'converted the quote to '.$job['job_no'],'created_at'=>$job['created_at']); usort($history,function($a,$b){return strcmp((string)$b['created_at'],(string)$a['created_at']);}); }

$propertyAddress=implode(', ',array_filter(array($quote['location_address1']??'',$quote['location_address2']??'',$quote['location_city']??'',$quote['location_state']??'',$quote['location_postal']??''),function($v){return trim((string)$v)!=='';}));
$statusLabel=ucwords(str_replace('_',' ',(string)$quote['status']));

require __DIR__ . '/includes/header.php';
?>
<style>
.qv-page{--qv-text:#0d3442;--qv-muted:#627a86;--qv-line:#d9e2e6;--qv-soft:#f4f3ef;--qv-accent:var(--primary,#2f8d25);--qv-accent-dark:color-mix(in srgb,var(--primary,#2f8d25) 82%,#000);--qv-accent-text:var(--primary-text,#fff);background:#fff;color:var(--qv-text);min-height:calc(100vh - 70px)}
.qv-page,.qv-page *{box-sizing:border-box}.qv-page a{text-decoration:none}.qv-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;min-height:calc(100vh - 70px)}.qv-main{min-width:0;border-right:1px solid var(--qv-line)}.qv-main-inner{max-width:1120px;margin:auto;padding:24px 34px 60px}.qv-top-rule{height:4px;margin:-24px -34px 24px;background:color-mix(in srgb,var(--qv-accent) 75%,#8f4051)}
.qv-top-actions,.qv-actions,.qv-status-wrap,.qv-client-head,.qv-card-head,.qv-note-actions,.qv-editor-actions{display:flex;align-items:center}.qv-top-actions,.qv-client-head,.qv-card-head{justify-content:space-between}.qv-actions{gap:9px}.qv-status-wrap{gap:12px}.qv-quote-icon{color:#a64052;font-size:19px}.qv-status{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;background:#eff3f4;font-size:12px;font-weight:700}.qv-status:before{content:"";width:7px;height:7px;border-radius:50%;background:#617b86}.qv-btn,.qv-icon-btn,.qv-chip{border:1px solid var(--qv-line);border-radius:8px;background:#fff;color:#294d5b;font-weight:700;cursor:pointer}.qv-btn{height:40px;padding:0 15px;display:inline-flex;align-items:center;gap:8px}.qv-btn.primary{background:var(--qv-accent);border-color:var(--qv-accent);color:var(--qv-accent-text)}.qv-icon-btn{width:40px;height:40px;display:grid;place-items:center;font-size:18px}.qv-btn:hover,.qv-icon-btn:hover,.qv-chip:hover{background:#f5faf2}.qv-btn.primary:hover{background:var(--qv-accent-dark)}
.qv-more-wrap{position:relative}.qv-more-menu{display:none;position:absolute;right:0;top:46px;z-index:80;width:300px;padding:10px;border:1px solid var(--qv-line);border-radius:10px;background:#fff;box-shadow:0 12px 28px rgba(10,39,50,.18)}.qv-more-menu.show{display:block}.qv-more-item{width:100%;min-height:44px;display:flex;align-items:center;gap:13px;padding:9px 12px;border:0;border-radius:7px;background:transparent;color:#294d5b;text-align:left;font:700 15px Arial,Helvetica,sans-serif;cursor:pointer}.qv-more-item i{width:24px;font-size:20px;text-align:center}.qv-more-item:hover{background:#f5f7f6}.qv-more-item.convert i,.qv-more-item.approved i{color:var(--qv-accent)}.qv-more-item.delete{color:#b83a32}.qv-more-item.delete i{color:#d94335}.qv-more-separator{height:1px;margin:8px 7px;background:var(--qv-line)}.qv-more-label{padding:7px 12px 4px;color:#526c77;font-weight:800;font-size:14px}.qv-more-menu form{margin:0}.qv-more-menu a.qv-more-item{text-decoration:none}
.qv-title-row{margin:22px 0 18px;display:flex;align-items:center;justify-content:space-between}.qv-title{margin:0;font-size:32px;font-weight:800;color:#062d3b}.qv-overview{display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,.9fr);gap:18px}.qv-client-card{padding:22px 24px;border:1px solid var(--qv-line);border-radius:9px}.qv-client-name{font-size:17px;font-weight:800}.qv-dot{display:inline-block;width:7px;height:7px;margin-left:5px;border-radius:50%;background:#3e9ee4}.qv-label{display:block;margin-bottom:4px;color:var(--qv-muted)}.qv-address{margin:0 0 16px;line-height:1.35}.qv-contact{display:grid;gap:4px}.qv-contact a{color:var(--qv-accent-dark);text-decoration:underline}.qv-meta-row{min-height:53px;display:grid;grid-template-columns:150px 1fr;align-items:center;border-bottom:1px solid var(--qv-line);font-size:14px}.qv-meta-row span{color:#56707c}
.qv-divider{height:1px;background:var(--qv-line);margin:32px -34px}.qv-add-strip{display:flex;align-items:center;gap:8px;flex-wrap:wrap;width:max-content;max-width:100%;margin:0 0 24px;padding:7px 9px;border-radius:10px;background:#efeeea}.qv-add-strip>span{padding:0 5px;font-weight:700}.qv-chip{height:34px;padding:0 13px;font-size:13px}.qv-card{margin-bottom:24px;border:1px solid var(--qv-line);border-radius:9px;background:#fff;overflow:hidden}.qv-card-head{gap:12px;padding:22px 24px 17px}.qv-card-head h2{margin:0;font-size:21px}.qv-section-tools{display:flex;gap:5px}.qv-section-tools button{border:0;background:transparent;color:#214754;font-size:18px;cursor:pointer}.qv-section-tools .danger{color:#b74646}.qv-body{padding:0 24px 24px}.qv-copy{margin:0;white-space:pre-wrap;line-height:1.55;color:#294c59}.qv-intro-image{margin-bottom:16px;max-height:280px;overflow:hidden;border-radius:8px;background:#f6f8f8}.qv-intro-image img{width:100%;max-height:280px;object-fit:cover}
.qv-editor-card{display:none;margin-bottom:24px;padding:22px 24px;border:1px solid var(--qv-line);border-radius:9px;background:#fff}.qv-editor-card.show{display:block}.qv-editor-card h3{margin:0 0 16px;font-size:20px}.qv-field{margin-bottom:12px}.qv-field label{display:block;margin-bottom:5px;color:#58717d;font-size:12px;font-weight:700}.qv-field input,.qv-field textarea{width:100%;border:1px solid var(--qv-line);border-radius:7px;background:#fff;color:#173d49;font:14px Arial,Helvetica,sans-serif}.qv-field input{height:43px;padding:0 12px}.qv-field textarea{min-height:120px;padding:12px;resize:vertical}.qv-upload-zone{padding:15px;border:1px dashed #cfdade;border-radius:8px;background:#fbfcfc}.qv-upload-zone input{width:100%}.qv-editor-actions{justify-content:flex-end;gap:8px;margin-top:14px}.qv-hidden-delete{display:inline}.qv-hidden-delete button{border:0;background:transparent;color:#b74646;cursor:pointer}
.qv-items{width:100%;border-collapse:collapse}.qv-items th{padding:14px 24px;border-bottom:1px solid var(--qv-line);text-align:left;font-size:13px}.qv-items th.num,.qv-items td.num{text-align:right}.qv-items td{padding:19px 24px;border-bottom:1px solid #edf1f2;vertical-align:top}.qv-item-name{font-weight:800}.qv-item-desc{margin-top:7px;max-width:520px;line-height:1.4}.qv-item-image{width:58px;height:58px;margin-top:9px;border-radius:7px;object-fit:cover}.qv-totals{width:min(450px,100%);margin-left:auto;padding:20px 24px 24px}.qv-total-row{min-height:42px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #dfe5e7}.qv-total-row.grand{font-size:17px;font-weight:800;border-bottom:0}.qv-payment{margin:0 24px 24px;padding:14px;border-radius:8px;background:#f8f9f8}.qv-payment-row{display:flex;justify-content:space-between;padding:6px 0}.qv-gallery{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:0 24px 24px}.qv-gallery img{width:100%;height:150px;object-fit:cover;border-radius:8px;border:1px solid var(--qv-line)}.qv-file-list{display:grid;gap:8px;padding:0 24px 24px}.qv-file{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 12px;border:1px solid #e2e8ea;border-radius:7px}.qv-file a{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--qv-accent-dark);text-decoration:underline}.qv-file-actions{display:flex;align-items:center;gap:7px}.qv-file-actions form{margin:0}.qv-file-actions button{border:0;background:transparent;color:#b74646;cursor:pointer}
.qv-notes-rail{position:sticky;top:0;align-self:start;height:calc(100vh - 70px);padding:28px;background:#fff;overflow:auto}.qv-notes-title{margin:0 0 24px;font-size:24px}.qv-note-box{min-height:255px;padding:18px;border:2px dashed #d7dfe2;border-radius:8px}.qv-note-empty{height:215px;display:grid;place-items:center;text-align:center}.qv-note-empty i{display:grid;place-items:center;width:58px;height:58px;margin:0 auto 16px;border-radius:50%;background:#f7f6f3;font-size:23px}.qv-note-view{white-space:pre-wrap;line-height:1.5}.qv-note-actions{justify-content:flex-end;margin-top:14px}.qv-note-editor{display:none;margin-top:12px}.qv-note-editor.show{display:block}.qv-note-editor textarea{width:100%;min-height:155px;padding:12px;border:1px solid var(--qv-line);border-radius:7px;resize:vertical}.qv-note-editor-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px}

.qv-signed-block{margin:34px 0 18px;padding:8px 0 0}.qv-signed-grid{display:grid;grid-template-columns:minmax(0,1fr) 155px;gap:34px;align-items:end}.qv-signature-side,.qv-date-side{min-width:0}.qv-signature-image-wrap{height:82px;display:flex;align-items:flex-end;justify-content:center;padding:0 12px}.qv-signature-image-wrap img{display:block;max-width:300px;max-height:78px;object-fit:contain}.qv-signature-line,.qv-date-line{height:1px;background:#cfd9dd}.qv-signature-caption,.qv-date-caption{text-align:center;padding-top:8px;color:#274956;font-size:14px}.qv-date-value{text-align:center;padding:0 4px 10px;color:#516c78;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;letter-spacing:.04em}.qv-signed-by{text-align:center;margin-top:5px;color:#6b8089;font-size:12px}.qv-signed-note{margin-bottom:14px;padding:16px;border:1px solid var(--qv-line);border-radius:9px;background:#fff}.qv-signed-note-head{display:flex;align-items:flex-start;gap:10px}.qv-signed-note-avatar{width:28px;height:28px;flex:0 0 28px;display:grid;place-items:center;border-radius:50%;background:#eef2f3;color:#234b58}.qv-signed-note-meta{min-width:0;flex:1}.qv-signed-note-meta strong{display:block;font-size:13px}.qv-signed-note-meta small{display:block;margin-top:2px;color:var(--qv-muted);font-size:12px}.qv-signed-note-text{margin:13px 0 10px;line-height:1.4}.qv-signed-copy{display:inline-flex;flex-direction:column;align-items:center;justify-content:center;width:62px;height:66px;border:1px solid #d9e2e6;border-radius:7px;background:#f2f3f1;color:#294d5b;font-size:10px;font-weight:700}.qv-signed-copy i{font-size:23px;margin-bottom:4px}.qv-signed-copy:hover{background:#e9efec}.qv-signed-note-signer{margin-top:9px;color:#6b8089;font-size:11px}@media(max-width:760px){.qv-signed-grid{grid-template-columns:1fr 115px;gap:18px}.qv-signature-image-wrap img{max-width:230px}}
.qv-flash{margin:0 0 18px;padding:11px 13px;border-radius:7px;font-size:13px}.qv-flash.success{background:#edf7e9;color:#276d24}.qv-flash.error{background:#fff0f0;color:#9f3333}.qv-history-backdrop{display:none;position:fixed;inset:0;z-index:2090;background:rgba(11,39,49,.22)}.qv-history-backdrop.show{display:block}.qv-history-drawer{position:fixed;top:0;right:0;z-index:2100;width:min(440px,92vw);height:100vh;padding:24px 26px;background:#fff;box-shadow:-8px 0 30px rgba(8,38,49,.14);transform:translateX(101%);transition:transform .22s ease;overflow:auto}.qv-history-drawer.show{transform:translateX(0)}.qv-history-head{display:flex;align-items:center;justify-content:space-between}.qv-history-head h2{margin:0;font-size:25px}.qv-close{width:38px;height:38px;border:0;background:transparent;font-size:25px;cursor:pointer}.qv-history-filters{display:flex;gap:8px;flex-wrap:wrap;margin:22px 0 12px}.qv-filter-pill{padding:9px 14px;border-radius:999px;background:#e9e8e4;font-weight:700;font-size:13px}.qv-history-sort{margin:12px 0 22px;text-decoration:underline;font-weight:700}.qv-history-item{display:grid;grid-template-columns:30px 1fr;gap:11px;padding:15px 0;border-bottom:1px solid #e4eaec}.qv-avatar{width:26px;height:26px;display:grid;place-items:center;border-radius:50%;background:#254a57;color:#fff;font-size:10px}.qv-history-item strong,.qv-history-item small{display:block}.qv-history-item small{margin-top:2px;color:#687e88}
@media(max-width:1180px){.qv-layout{grid-template-columns:1fr}.qv-main{border-right:0}.qv-notes-rail{position:relative;height:auto;border-top:1px solid var(--qv-line)}}@media(max-width:760px){.qv-main-inner{padding:18px 14px 40px}.qv-top-rule{margin:-18px -14px 18px}.qv-overview{grid-template-columns:1fr}.qv-actions{flex-wrap:wrap;justify-content:flex-end}.qv-divider{margin:24px -14px}.qv-gallery{grid-template-columns:1fr 1fr}}
.qv-modal-backdrop{display:none;position:fixed;inset:0;z-index:2200;background:rgba(7,31,40,.48);padding:24px;align-items:center;justify-content:center}.qv-modal-backdrop.show{display:flex}.qv-signature-modal{width:min(750px,100%);max-height:94vh;overflow:auto;background:#fff;border-radius:10px;box-shadow:0 28px 70px rgba(5,31,40,.25)}.qv-modal-head{display:flex;align-items:center;justify-content:space-between;padding:24px 26px 18px}.qv-modal-head h2{margin:0;font-size:25px;color:#082f3d}.qv-modal-body{padding:26px}.qv-signature-wrap{position:relative;height:208px;border:1px solid #cfd9dd;border-radius:8px;background:#fff;overflow:hidden}.qv-signature-canvas{display:block;width:100%;height:208px;touch-action:none;cursor:crosshair;background:#fff}.qv-signature-clear{position:absolute;top:10px;right:10px;z-index:2;height:34px;padding:0 12px;border:1px solid #cfd9dd;border-radius:7px;background:#fff;color:#294d5b;font-weight:700;cursor:pointer}.qv-signature-baseline{position:absolute;left:10px;right:10px;bottom:27px;border-top:1px dashed #315666;pointer-events:none}.qv-signature-write-label{position:absolute;left:0;right:0;bottom:6px;text-align:center;color:#264b58;font-size:12px;pointer-events:none}.qv-copy-check{display:flex;align-items:center;gap:9px;margin-top:0;color:#173d49;font-size:14px}.qv-copy-check input{width:20px;height:20px;margin:0}.qv-modal-actions{display:flex;align-items:center;justify-content:flex-end;gap:9px;margin-top:32px}.qv-modal-actions .qv-btn{height:42px}.qv-modal-actions .primary{min-width:84px;justify-content:center}
/* This is an application workspace page. Keep the shared footer available for shell closing/scripts, but do not render it inside the quote workspace. */
.fieldplx-footer{display:none!important}
@media print{.qv-notes-rail,.qv-actions,.qv-add-strip,.qv-section-tools,.qv-editor-card,.qv-history-drawer,.qv-history-backdrop{display:none!important}.qv-layout{display:block}.qv-main{border:0}.qv-main-inner{max-width:none;padding:0}.qv-top-rule{margin:0 0 18px}}
</style>

<div class="qv-page">
  <div class="qv-layout">
    <main class="qv-main">
      <div class="qv-main-inner">
        <div class="qv-top-rule"></div>
        <?php if($flash): ?><div class="qv-flash <?= qvEsc($flash['type']) ?>"><?= qvEsc($flash['message']) ?></div><?php endif; ?>
        <div class="qv-top-actions">
          <div class="qv-status-wrap"><i class="bi bi-file-earmark-text qv-quote-icon"></i><span class="qv-status"><?= qvEsc(ucwords(str_replace('_',' ',$quote['status']))) ?></span></div>
          <div class="qv-actions">
            <button class="qv-icon-btn" type="button" id="openQuoteHistory" title="Quote History"><i class="bi bi-clock-history"></i></button>
            <div class="qv-more-wrap">
              <button class="qv-btn" type="button" id="quoteMoreButton"><i class="bi bi-three-dots"></i> More</button>
              <div class="qv-more-menu" id="quoteMoreMenu">
                <a class="qv-more-item convert" href="add-job.php?quote_id=<?= (int)$quoteId ?>"><i class="bi bi-tools"></i><span>Convert to Job</span></a>
                <a class="qv-more-item" href="add-quotation.php?similar_from=<?= (int)$quoteId ?>"><i class="bi bi-files"></i><span>Create Similar Quote</span></a>
                <div class="qv-more-separator"></div>
                <div class="qv-more-label">Mark as...</div>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="action" value="mark_awaiting_response"><button class="qv-more-item" type="submit"><i class="bi bi-envelope-check"></i><span>Awaiting Response</span></button></form>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="action" value="mark_approved"><button class="qv-more-item approved" type="submit"><i class="bi bi-check-lg"></i><span>Approved</span></button></form>
                <div class="qv-more-separator"></div>
                <a class="qv-more-item" href="quotation-print.php?id=<?= (int)$quoteId ?>&amp;mode=client" target="_blank" rel="noopener"><i class="bi bi-eye"></i><span>Preview as Client</span></a>
                <button class="qv-more-item" type="button" id="openSignatureModal"><i class="bi bi-pen"></i><span>Collect Signature</span></button>
                <a class="qv-more-item" href="quotation-print.php?id=<?= (int)$quoteId ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i><span>Print or Save PDF</span></a>
                <form method="post" onsubmit="return confirm('Archive this quotation? It will be removed from the active quotation list.');"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="action" value="archive_quote"><button class="qv-more-item delete" type="submit"><i class="bi bi-trash"></i><span>Delete</span></button></form>
              </div>
            </div>
            <button class="qv-btn primary" type="button"><i class="bi bi-envelope"></i> Send Email</button>
          </div>
        </div>

        <div class="qv-title-row"><h1 class="qv-title"><?= qvEsc($quote['title'] ?: 'Quotation') ?></h1><a href="add-quotation.php?quote_id=<?= (int)$quoteId ?>" title="Edit quote"><i class="bi bi-pencil"></i></a></div>
        <div class="qv-overview">
          <section class="qv-client-card">
            <div class="qv-client-head"><div class="qv-client-name"><?= qvEsc($quote['client_name']) ?><span class="qv-dot"></span></div><span>•••</span></div>
            <span class="qv-label">Property Address</span><p class="qv-address"><?= qvEsc($propertyAddress ?: 'No property address') ?></p>
            <div class="qv-contact"><?php if(!empty($quote['client_phone'])):?><a href="tel:<?= qvEsc(preg_replace('/[^+0-9]/','',$quote['client_phone'])) ?>"><?= qvEsc($quote['client_phone']) ?></a><?php endif; ?><?php if(!empty($quote['client_email'])):?><a href="mailto:<?= qvEsc($quote['client_email']) ?>"><?= qvEsc($quote['client_email']) ?></a><?php endif; ?></div>
          </section>
          <section>
            <div class="qv-meta-row"><span>Quote #</span><strong><?= qvEsc($quote['quote_no']) ?></strong></div>
            <div class="qv-meta-row"><span>Created</span><strong><?= qvEsc(qvDate($quote['created_at'])) ?></strong></div>
            <?php if(!empty($quote['valid_until'])):?><div class="qv-meta-row"><span>Valid until</span><strong><?= qvEsc(qvDate($quote['valid_until'])) ?></strong></div><?php endif; ?>
            <?php if(trim((string)$quote['salesperson_name'])!==''):?><div class="qv-meta-row"><span>Salesperson</span><strong><?= qvEsc($quote['salesperson_name']) ?></strong></div><?php endif; ?>
          </section>
        </div>

        <div class="qv-divider"></div>
        <div class="qv-add-strip"><span><i class="bi bi-plus-lg"></i> Add section</span><?php if(empty($quote['introduction_title']) && empty($quote['introduction']) && !$introImages): ?><button class="qv-chip" type="button" data-section-target="introduction">Introduction</button><?php endif; ?><button class="qv-chip" type="button" data-section-target="text">Text</button></div>

        <section class="qv-editor-card" id="qvIntroductionEditor">
          <h3>Introduction</h3>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_introduction"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>">
            <div class="qv-field"><label>Image</label><div class="qv-upload-zone"><input type="file" name="section_image" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,.heic"></div></div>
            <div class="qv-field"><label>Title</label><input type="text" name="introduction_title" maxlength="190" value="<?= qvEsc($quote['introduction_title']) ?>" placeholder="Title"></div>
            <div class="qv-field"><label>Description</label><textarea name="introduction" placeholder="Description"><?= qvEsc($quote['introduction']) ?></textarea></div>
            <div class="qv-editor-actions"><button class="qv-btn" type="button" data-editor-cancel="introduction">Cancel</button><button class="qv-btn primary" type="submit">Save</button></div>
          </form>
        </section>

        <?php if(!empty($quote['introduction_title']) || !empty($quote['introduction']) || $introImages): ?>
        <section class="qv-card" id="introduction">
          <div class="qv-card-head"><h2>Introduction</h2><div class="qv-section-tools"><button type="button" data-editor-open="introduction"><i class="bi bi-pencil"></i></button><form method="post" class="qv-hidden-delete" onsubmit="return confirm('Remove Introduction?')"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="action" value="delete_introduction"><button class="danger" type="submit"><i class="bi bi-trash"></i></button></form></div></div>
          <div class="qv-body"><?php if($introImages):?><div class="qv-intro-image"><img src="<?= qvEsc($introImages[0]['file_path']) ?>" alt="Introduction"></div><?php endif; ?><?php if(!empty($quote['introduction_title'])):?><h3><?= qvEsc($quote['introduction_title']) ?></h3><?php endif; ?><?php if(!empty($quote['introduction'])):?><p class="qv-copy"><?= qvEsc($quote['introduction']) ?></p><?php endif; ?></div>
        </section>
        <?php endif; ?>

        <section class="qv-editor-card" id="qvTextEditor">
          <h3 id="qvTextEditorTitle">Add Text</h3>
          <form method="post"><input type="hidden" name="action" value="save_text_section"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="section_id" id="qvTextSectionId" value="0"><div class="qv-field"><label>Title</label><input type="text" name="section_title" id="qvTextSectionTitle" maxlength="190" placeholder="Title"></div><div class="qv-field"><label>Description</label><textarea name="section_body" id="qvTextSectionBody" placeholder="Description"></textarea></div><div class="qv-editor-actions"><button class="qv-btn" type="button" data-editor-cancel="text">Cancel</button><button class="qv-btn primary" type="submit">Save</button></div></form>
        </section>

        <div id="custom-sections">
        <?php foreach($sections as $section): ?>
          <section class="qv-card">
            <div class="qv-card-head"><h2><?= qvEsc($section['title'] ?: 'Text') ?></h2><div class="qv-section-tools"><button type="button" class="qv-edit-text" data-id="<?= (int)$section['id'] ?>" data-title="<?= qvEsc($section['title']) ?>" data-body="<?= qvEsc($section['body']) ?>"><i class="bi bi-pencil"></i></button><form method="post" onsubmit="return confirm('Remove this text section?')"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="action" value="delete_text_section"><input type="hidden" name="section_id" value="<?= (int)$section['id'] ?>"><button class="danger" type="submit"><i class="bi bi-trash"></i></button></form></div></div>
            <?php if(!empty($section['body'])):?><div class="qv-body"><p class="qv-copy"><?= qvEsc($section['body']) ?></p></div><?php endif; ?>
          </section>
        <?php endforeach; ?>
        </div>

        <section class="qv-card">
          <div class="qv-card-head"><h2>Product / Service</h2><a href="add-quotation.php?quote_id=<?= (int)$quoteId ?>" title="Edit line items"><i class="bi bi-pencil"></i></a></div>
          <div style="overflow-x:auto"><table class="qv-items"><thead><tr><th>Line Item</th><th class="num">Quantity</th><th class="num">Unit Price</th><th class="num">Total</th></tr></thead><tbody><?php if(!$items):?><tr><td colspan="4">No line items.</td></tr><?php else:foreach($items as $item):?><tr><td><div class="qv-item-name"><?= qvEsc($item['item_name']) ?></div><?php if(!empty($item['description'])):?><div class="qv-item-desc"><?= qvEsc($item['description']) ?></div><?php endif; ?><?php if(!empty($item['image_path'])):?><img class="qv-item-image" src="<?= qvEsc($item['image_path']) ?>" alt="<?= qvEsc($item['item_name']) ?>"><?php endif; ?></td><td class="num"><?= qvEsc(rtrim(rtrim(number_format((float)$item['quantity'],3,'.',''),'0'),'.')) ?></td><td class="num"><?= qvEsc(qvMoney($item['unit_price'])) ?></td><td class="num"><?= qvEsc(qvMoney($item['line_total'])) ?></td></tr><?php endforeach;endif; ?></tbody></table></div>
          <div class="qv-totals"><div class="qv-total-row"><span>Subtotal</span><strong><?= qvEsc(qvMoney($quote['subtotal'])) ?></strong></div><?php if((float)$quote['discount_total']>0):?><div class="qv-total-row"><span>Discount</span><strong>-<?= qvEsc(qvMoney($quote['discount_total'])) ?></strong></div><?php endif; ?><?php if((float)$quote['tax_total']>0):?><div class="qv-total-row"><span>Tax</span><strong><?= qvEsc(qvMoney($quote['tax_total'])) ?></strong></div><?php endif; ?><div class="qv-total-row grand"><span>Total</span><strong><?= qvEsc(qvMoney($quote['total'])) ?></strong></div></div>
          <?php if((int)$quote['deposit_required']===1 || $paymentSchedule):?><div class="qv-payment"><?php if((int)$quote['deposit_required']===1):?><div class="qv-payment-row"><span>Required deposit</span><strong><?= qvEsc(qvMoney($quote['deposit_amount'])) ?></strong></div><?php endif; ?><?php foreach($paymentSchedule as $p):?><div class="qv-payment-row"><span><?= qvEsc($p['description']?:'Scheduled payment') ?></span><strong><?= qvEsc(qvMoney($p['amount'])) ?></strong></div><?php endforeach; ?></div><?php endif; ?>
        </section>

        <div class="qv-add-strip"><span><i class="bi bi-plus-lg"></i> Add section</span><?php if(!$attachments): ?><button class="qv-chip" type="button" data-section-target="attachments">Attachments</button><?php endif; ?><?php if(!$images): ?><button class="qv-chip" type="button" data-section-target="images">Images</button><?php endif; ?><?php if(empty($quote['client_message'])): ?><button class="qv-chip" type="button" data-section-target="client-message">Client Message</button><?php endif; ?><?php if(empty($quote['disclaimer'])): ?><button class="qv-chip" type="button" data-section-target="disclaimer">Contract / Disclaimer</button><?php endif; ?></div>

        <section class="qv-editor-card" id="qvAttachmentsEditor"><h3>Add Attachment</h3><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_quote_file"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="file_category" value="attachment"><div class="qv-field"><div class="qv-upload-zone"><input type="file" name="quote_file" accept=".jpg,.jpeg,.png,.gif,.webp,.avif,.heic,.pdf,.doc,.docx" required></div></div><div class="qv-editor-actions"><button class="qv-btn" type="button" data-editor-cancel="attachments">Cancel</button><button class="qv-btn primary" type="submit">Upload</button></div></form></section>
        <?php if($attachments):?><section class="qv-card" id="attachments"><div class="qv-card-head"><h2>Attachments</h2><button type="button" data-editor-open="attachments"><i class="bi bi-plus-lg"></i></button></div><div class="qv-file-list"><?php foreach($attachments as $f):?><div class="qv-file"><a href="<?= qvEsc($f['file_path']) ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> <?= qvEsc($f['original_name']) ?></a><div class="qv-file-actions"><small><?= qvEsc(qvDate($f['created_at'])) ?></small><form method="post" onsubmit="return confirm('Remove this attachment?')"><input type="hidden" name="action" value="delete_quote_file"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>"><button type="submit"><i class="bi bi-trash"></i></button></form></div></div><?php endforeach; ?></div></section><?php endif; ?>

        <section class="qv-editor-card" id="qvImagesEditor"><h3>Add Image</h3><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_quote_file"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="file_category" value="image"><div class="qv-field"><div class="qv-upload-zone"><input type="file" name="quote_file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif,.heic" required></div></div><div class="qv-editor-actions"><button class="qv-btn" type="button" data-editor-cancel="images">Cancel</button><button class="qv-btn primary" type="submit">Upload</button></div></form></section>
        <?php if($images):?><section class="qv-card" id="images"><div class="qv-card-head"><h2>Images</h2><button type="button" data-editor-open="images"><i class="bi bi-plus-lg"></i></button></div><div class="qv-gallery"><?php foreach($images as $f):?><div><a href="<?= qvEsc($f['file_path']) ?>" target="_blank"><img src="<?= qvEsc($f['file_path']) ?>" alt="<?= qvEsc($f['original_name']) ?>"></a><form method="post" style="margin-top:5px;text-align:right" onsubmit="return confirm('Remove this image?')"><input type="hidden" name="action" value="delete_quote_file"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>"><button type="submit" style="border:0;background:transparent;color:#b74646;cursor:pointer"><i class="bi bi-trash"></i></button></form></div><?php endforeach; ?></div></section><?php endif; ?>

        <section class="qv-editor-card" id="qvClientMessageEditor"><h3>Client Message</h3><form method="post"><input type="hidden" name="action" value="save_client_message"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><div class="qv-field"><label>Message</label><textarea name="client_message" placeholder="Message shown to the client"><?= qvEsc($quote['client_message']) ?></textarea></div><div class="qv-editor-actions"><button class="qv-btn" type="button" data-editor-cancel="client-message">Cancel</button><button class="qv-btn primary" type="submit">Save</button></div></form></section>
        <?php if(!empty($quote['client_message'])):?><section class="qv-card" id="client-message"><div class="qv-card-head"><h2>Client Message</h2><div class="qv-section-tools"><button type="button" data-editor-open="client-message"><i class="bi bi-pencil"></i></button><form method="post" onsubmit="return confirm('Remove Client Message?')"><input type="hidden" name="action" value="delete_client_message"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><button class="danger" type="submit"><i class="bi bi-trash"></i></button></form></div></div><div class="qv-body"><p class="qv-copy"><?= qvEsc($quote['client_message']) ?></p></div></section><?php endif; ?>

        <section class="qv-editor-card" id="qvDisclaimerEditor"><h3>Contract / Disclaimer</h3><form method="post"><input type="hidden" name="action" value="save_disclaimer"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><div class="qv-field"><label>Contract / Disclaimer</label><textarea name="disclaimer" placeholder="Terms, contract language or disclaimer"><?= qvEsc($quote['disclaimer']) ?></textarea></div><div class="qv-editor-actions"><button class="qv-btn" type="button" data-editor-cancel="disclaimer">Cancel</button><button class="qv-btn primary" type="submit">Save</button></div></form></section>
        <?php if(!empty($quote['disclaimer'])):?><section class="qv-card" id="disclaimer"><div class="qv-card-head"><h2>Contract / Disclaimer</h2><div class="qv-section-tools"><button type="button" data-editor-open="disclaimer"><i class="bi bi-pencil"></i></button><form method="post" onsubmit="return confirm('Remove Contract / Disclaimer?')"><input type="hidden" name="action" value="delete_disclaimer"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><button class="danger" type="submit"><i class="bi bi-trash"></i></button></form></div></div><div class="qv-body"><p class="qv-copy"><?= qvEsc($quote['disclaimer']) ?></p></div></section><?php endif; ?>

        <?php if($quoteSignatures): $latestSignature=$quoteSignatures[0]; $latestSigner=trim((string)preg_replace('/^Quotation client signature\s*-\s*/','',(string)$latestSignature['description'])); ?>
        <section class="qv-signed-block" aria-label="Collected signature">
          <div class="qv-signed-grid">
            <div class="qv-signature-side">
              <div class="qv-signature-image-wrap"><img src="<?= qvEsc($latestSignature['file_path']) ?>" alt="<?= qvEsc($latestSigner!=='' ? $latestSigner.' signature' : 'Client signature') ?>"></div>
              <div class="qv-signature-line"></div>
              <div class="qv-signature-caption">Signature</div>
              <?php if($latestSigner!==''): ?><div class="qv-signed-by">Signed by <?= qvEsc($latestSigner) ?></div><?php endif; ?>
            </div>
            <div class="qv-date-side">
              <div class="qv-date-value"><?= qvEsc(date('M j, Y',strtotime((string)$latestSignature['created_at']))) ?></div>
              <div class="qv-date-line"></div>
              <div class="qv-date-caption">Date</div>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <?php if($job):?><section class="qv-card"><div class="qv-card-head"><h2>Converted Job</h2></div><div class="qv-body"><a href="job-view.php?id=<?= (int)$job['id'] ?>" style="color:var(--qv-accent-dark);font-weight:800"><?= qvEsc($job['job_no']) ?> · <?= qvEsc($job['title']) ?></a></div></section><?php endif; ?>
      </div>
    </main>

    <aside class="qv-notes-rail" id="notes">
      <h2 class="qv-notes-title">Notes</h2>
      <?php if($quoteSignatures): $sig=$quoteSignatures[0]; $sigSigner=trim((string)preg_replace('/^Quotation client signature\s*-\s*/','',(string)$sig['description'])); ?>
      <article class="qv-signed-note">
        <div class="qv-signed-note-head">
          <span class="qv-signed-note-avatar"><i class="bi bi-file-earmark-check"></i></span>
          <div class="qv-signed-note-meta"><strong><?= qvEsc($sigSigner!==''?$sigSigner:'Team member') ?></strong><small><?= qvEsc(qvDate($sig['created_at'],true)) ?></small></div>
        </div>
        <div class="qv-signed-note-text">Signed copy of quote #<?= qvEsc($quote['quote_no']) ?></div>
        <a class="qv-signed-copy" href="quotation-print.php?id=<?= (int)$quoteId ?>&amp;signed=1" target="_blank" rel="noopener" title="Open signed quotation"><i class="bi bi-file-earmark-pdf"></i><span>signed-…</span></a>
      </article>
      <?php endif; ?>
      <div class="qv-note-box"><?php if(trim((string)$quote['internal_notes'])===''):?><div class="qv-note-empty"><div><i class="bi bi-journal-plus"></i><p>Leave an internal note for yourself or a team member</p></div></div><?php else:?><div class="qv-note-view"><?= qvEsc($quote['internal_notes']) ?></div><?php endif; ?><div class="qv-note-actions"><button class="qv-btn" type="button" id="editNoteButton"><i class="bi bi-pencil"></i> <?= trim((string)$quote['internal_notes'])===''?'Add note':'Edit note' ?></button></div><form class="qv-note-editor" id="noteEditor" method="post"><input type="hidden" name="action" value="save_note"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><textarea name="internal_notes" maxlength="10000"><?= qvEsc($quote['internal_notes']) ?></textarea><div class="qv-note-editor-actions"><button class="qv-btn" type="button" id="cancelNoteButton">Cancel</button><button class="qv-btn primary" type="submit">Save</button></div></form></div>
    </aside>
  </div>
</div>

<div class="qv-modal-backdrop" id="qvSignatureModal" aria-hidden="true">
  <div class="qv-signature-modal" role="dialog" aria-modal="true" aria-labelledby="qvSignatureTitle">
    <div class="qv-modal-head"><h2 id="qvSignatureTitle">Signature Pad</h2><button type="button" class="qv-close" id="closeSignatureModal" aria-label="Close">×</button></div>
    <div class="qv-modal-body">
      <form method="post" id="signatureForm">
        <input type="hidden" name="action" value="save_signature"><input type="hidden" name="csrf_token" value="<?= qvEsc($csrfToken) ?>"><input type="hidden" name="client_id" value="<?= (int)$quote['client_id'] ?>"><input type="hidden" name="signature_data" id="signatureData"><input type="hidden" name="signed_by_name" value="<?= qvEsc($quote['client_name']) ?>">
        <div class="qv-signature-wrap">
          <button type="button" class="qv-signature-clear" id="clearSignature">Clear</button>
          <canvas class="qv-signature-canvas" id="signatureCanvas"></canvas>
          <div class="qv-signature-baseline"></div>
          <div class="qv-signature-write-label">Write signature</div>
        </div>
        <label class="qv-copy-check"><input type="checkbox" name="send_client_copy" value="1"><span>Send your client a copy</span></label>
        <div class="qv-modal-actions"><button type="button" class="qv-btn" id="cancelSignature">Cancel</button><button type="submit" class="qv-btn primary">Submit</button></div>
      </form>
    </div>
  </div>
</div>

<div class="qv-history-backdrop" id="quoteHistoryBackdrop"></div>
<aside class="qv-history-drawer" id="quoteHistoryDrawer" aria-hidden="true"><div class="qv-history-head"><h2>Quote History</h2><button type="button" class="qv-close" id="closeQuoteHistory">×</button></div><div class="qv-history-filters"><span class="qv-filter-pill">Team | All</span><span class="qv-filter-pill">Type | All</span><span class="qv-filter-pill"><i class="bi bi-calendar3"></i> Date | All</span></div><div class="qv-history-sort">Newest <i class="bi bi-chevron-down"></i></div><?php foreach($history as $e):?><div class="qv-history-item"><span class="qv-avatar"><?= qvEsc(qvInitials($e['who'])) ?></span><div><strong><?= qvEsc($e['who'].' '.$e['action']) ?></strong><small><?= qvEsc(qvDate($e['created_at'],true)) ?></small></div></div><?php endforeach; ?></aside>

<script>
(function(){
  var activeAddButtons={};
  function editorId(key){return {introduction:'qvIntroductionEditor',text:'qvTextEditor',attachments:'qvAttachmentsEditor',images:'qvImagesEditor','client-message':'qvClientMessageEditor',disclaimer:'qvDisclaimerEditor'}[key]||''}
  function openEditor(key){var id=editorId(key),el=document.getElementById(id);if(!el)return;el.classList.add('show')}
  function closeEditor(key){var id=editorId(key),el=document.getElementById(id);if(el)el.classList.remove('show')}
  document.querySelectorAll('[data-section-target]').forEach(function(b){b.addEventListener('click',function(){var k=b.getAttribute('data-section-target');if(k==='text'){document.getElementById('qvTextSectionId').value='0';document.getElementById('qvTextSectionTitle').value='';document.getElementById('qvTextSectionBody').value='';document.getElementById('qvTextEditorTitle').textContent='Add Text'}else{activeAddButtons[k]=b;b.style.display='none'}openEditor(k)})});
  document.querySelectorAll('[data-editor-open]').forEach(function(b){b.addEventListener('click',function(){openEditor(b.getAttribute('data-editor-open'))})});
  document.querySelectorAll('[data-editor-cancel]').forEach(function(b){b.addEventListener('click',function(){var key=b.getAttribute('data-editor-cancel');closeEditor(key);if(activeAddButtons[key]){activeAddButtons[key].style.display='';delete activeAddButtons[key]}})});
  document.querySelectorAll('.qv-edit-text').forEach(function(b){b.addEventListener('click',function(){document.getElementById('qvTextSectionId').value=b.getAttribute('data-id')||'0';document.getElementById('qvTextSectionTitle').value=b.getAttribute('data-title')||'';document.getElementById('qvTextSectionBody').value=b.getAttribute('data-body')||'';document.getElementById('qvTextEditorTitle').textContent='Edit Text';openEditor('text')})});

  var mb=document.getElementById('quoteMoreButton'),mm=document.getElementById('quoteMoreMenu');if(mb&&mm){mb.addEventListener('click',function(e){e.stopPropagation();mm.classList.toggle('show')});document.addEventListener('click',function(){mm.classList.remove('show')})}
  var sigModal=document.getElementById('qvSignatureModal'),sigOpen=document.getElementById('openSignatureModal'),sigClose=document.getElementById('closeSignatureModal'),sigCancel=document.getElementById('cancelSignature'),sigClear=document.getElementById('clearSignature'),sigCanvas=document.getElementById('signatureCanvas'),sigForm=document.getElementById('signatureForm'),sigData=document.getElementById('signatureData');
  var sigCtx=null,sigDrawing=false,sigHasInk=false,sigLastX=0,sigLastY=0;
  function resizeSignatureCanvas(){if(!sigCanvas)return;var r=sigCanvas.getBoundingClientRect(),ratio=Math.max(window.devicePixelRatio||1,1);sigCanvas.width=Math.max(1,Math.floor(r.width*ratio));sigCanvas.height=Math.max(1,Math.floor(r.height*ratio));sigCtx=sigCanvas.getContext('2d');sigCtx.scale(ratio,ratio);sigCtx.lineWidth=2.2;sigCtx.lineCap='round';sigCtx.lineJoin='round';sigCtx.strokeStyle='#153d4b';sigHasInk=false}
  function signaturePoint(e){var r=sigCanvas.getBoundingClientRect(),p=e.touches?e.touches[0]:e;return {x:p.clientX-r.left,y:p.clientY-r.top}}
  function signatureStart(e){if(!sigCtx)return;e.preventDefault();var p=signaturePoint(e);sigDrawing=true;sigLastX=p.x;sigLastY=p.y}
  function signatureMove(e){if(!sigDrawing||!sigCtx)return;e.preventDefault();var p=signaturePoint(e);sigCtx.beginPath();sigCtx.moveTo(sigLastX,sigLastY);sigCtx.lineTo(p.x,p.y);sigCtx.stroke();sigLastX=p.x;sigLastY=p.y;sigHasInk=true}
  function signatureEnd(){sigDrawing=false}
  function signatureModal(open){if(!sigModal)return;sigModal.classList.toggle('show',open);sigModal.setAttribute('aria-hidden',open?'false':'true');if(open){if(mm)mm.classList.remove('show');document.body.style.overflow='hidden';requestAnimationFrame(resizeSignatureCanvas)}else{document.body.style.overflow=''}}
  if(sigCanvas){['mousedown','touchstart'].forEach(function(n){sigCanvas.addEventListener(n,signatureStart,{passive:false})});['mousemove','touchmove'].forEach(function(n){sigCanvas.addEventListener(n,signatureMove,{passive:false})});['mouseup','mouseleave','touchend','touchcancel'].forEach(function(n){sigCanvas.addEventListener(n,signatureEnd)})}
  if(sigOpen)sigOpen.addEventListener('click',function(e){e.stopPropagation();signatureModal(true)});if(sigClose)sigClose.addEventListener('click',function(){signatureModal(false)});if(sigCancel)sigCancel.addEventListener('click',function(){signatureModal(false)});if(sigModal)sigModal.addEventListener('click',function(e){if(e.target===sigModal)signatureModal(false)});if(sigClear)sigClear.addEventListener('click',function(){resizeSignatureCanvas()});
  if(sigForm)sigForm.addEventListener('submit',function(e){if(!sigHasInk){e.preventDefault();alert('Please draw the signature before saving.');return}sigData.value=sigCanvas.toDataURL('image/png')});
  var drawer=document.getElementById('quoteHistoryDrawer'),back=document.getElementById('quoteHistoryBackdrop');function history(open){drawer.classList.toggle('show',open);back.classList.toggle('show',open);document.body.style.overflow=open?'hidden':''}document.getElementById('openQuoteHistory').addEventListener('click',function(){history(true)});document.getElementById('closeQuoteHistory').addEventListener('click',function(){history(false)});back.addEventListener('click',function(){history(false)});
  var en=document.getElementById('noteEditor'),eb=document.getElementById('editNoteButton'),cb=document.getElementById('cancelNoteButton');if(eb&&en)eb.addEventListener('click',function(){en.classList.add('show');eb.style.display='none';var t=en.querySelector('textarea');if(t)t.focus()});if(cb&&en)cb.addEventListener('click',function(){en.classList.remove('show');eb.style.display=''})
  document.addEventListener('keydown',function(e){if(e.key==='Escape'){history(false);signatureModal(false);document.querySelectorAll('.qv-editor-card.show').forEach(function(x){x.classList.remove('show')})}})
})();
</script>
<?php
/* Keep the shell-closing markup/scripts from the shared footer, but remove the visible footer itself on this workspace page. */
$qvFooterFile = __DIR__ . '/includes/footer.php';
if (is_file($qvFooterFile)) {
    ob_start();
    require $qvFooterFile;
    $qvFooterHtml = (string)ob_get_clean();
    $qvFooterHtml = preg_replace('~<footer class="fieldplx-footer">.*?</footer>~is', '', $qvFooterHtml);
    echo $qvFooterHtml;
}
?>
