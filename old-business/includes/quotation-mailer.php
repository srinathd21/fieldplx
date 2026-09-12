<?php
if (!function_exists('fieldplxQuoteTableExists')) {
    function fieldplxQuoteTableExists(PDO $pdo, $table) {
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
        $q->execute(array(':t'=>$table));
        return (int)$q->fetchColumn()>0;
    }
}
if (!function_exists('fieldplxQuoteSmtpDecrypt')) {
    function fieldplxQuoteSmtpDecrypt($encrypted, $tenantId) {
        $encrypted=trim((string)$encrypted);
        if ($encrypted==='') return '';
        if (function_exists('fieldplxDecryptSmtpPassword')) return fieldplxDecryptSmtpPassword($encrypted, $tenantId);
        if (function_exists('decryptSmtpPassword')) return decryptSmtpPassword($encrypted, $tenantId);

        $secret='';
        if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) $secret=(string)FIELDPLX_SMTP_ENCRYPTION_KEY;
        if ($secret==='') {
            $e=getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
            if ($e!==false) $secret=trim((string)$e);
        }
        if (strpos($encrypted,'v1:')===0) {
            if ($secret==='') throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.');
            $raw=base64_decode(substr($encrypted,3),true);
            if ($raw===false || strlen($raw)<=16) throw new RuntimeException('Stored SMTP password is invalid.');
            $key=hash('sha256',$secret,true);
            $plain=openssl_decrypt(substr($raw,16),'AES-256-CBC',$key,OPENSSL_RAW_DATA,substr($raw,0,16));
            if ($plain===false) throw new RuntimeException('Unable to decrypt SMTP password.');
            return $plain;
        }

        $raw=base64_decode($encrypted,true);
        if ($raw===false || strlen($raw)<=16) throw new RuntimeException('Stored SMTP password is invalid.');
        $envKey=getenv('FIELDPLX_APP_KEY');
        if ($envKey===false || trim($envKey)==='') {
            $seed=(defined('DB_NAME')?DB_NAME:'').'|'.(defined('DB_USER')?DB_USER:'').'|'.(defined('DB_PASS')?DB_PASS:'').'|'.(int)$tenantId;
        } else {
            $seed=$envKey.'|'.(int)$tenantId;
        }
        $key=hash('sha256',$seed,true);
        $plain=openssl_decrypt(substr($raw,16),'AES-256-CBC',$key,OPENSSL_RAW_DATA,substr($raw,0,16));
        if ($plain===false) throw new RuntimeException('Unable to decrypt SMTP password.');
        return $plain;
    }
}
if (!function_exists('fieldplxQuoteSmtpConfig')) {
    function fieldplxQuoteSmtpConfig(PDO $pdo,$tenantId,$branchId) {
        if (!fieldplxQuoteTableExists($pdo,'smtp_configurations')) return null;
        $q=$pdo->prepare("SELECT * FROM smtp_configurations WHERE tenant_id=:t AND is_active=1 AND scope_type IN('tenant','branch') AND (scope_type='tenant' OR (scope_type='branch' AND branch_id=:b)) ORDER BY CASE WHEN scope_type='branch' AND branch_id=:b2 THEN 0 ELSE 1 END,is_default DESC,id DESC LIMIT 1");
        $q->execute(array(':t'=>$tenantId,':b'=>$branchId>0?$branchId:-1,':b2'=>$branchId>0?$branchId:-1));
        $r=$q->fetch(PDO::FETCH_ASSOC);
        return $r?:null;
    }
}
if (!function_exists('fieldplxQuoteApprovalUrl')) {
    function fieldplxQuoteApprovalUrl($token) {
        $scheme=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')?'https':'http';
        $host=isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'localhost';
        $script=isset($_SERVER['SCRIPT_NAME'])?str_replace('\\','/',$_SERVER['SCRIPT_NAME']):'/business/api/quotations.php';
        $root=preg_replace('#/business/api/[^/]+$#','',$script);
        if ($root===null) $root='';
        return $scheme.'://'.$host.rtrim($root,'/').'/quotation-response.php?token='.rawurlencode($token);
    }
}
if (!function_exists('fieldplxSendQuotationEmail')) {
    function fieldplxSendQuotationEmail(PDO $pdo,$tenantId,$branchId,array $quote,array $client,array $items,array $currency,$token) {
        $email=trim((string)(isset($client['email'])?$client['email']:''));
        if ($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) return array('status'=>'skipped','notice'=>'Customer email is not available.');
        $smtp=fieldplxQuoteSmtpConfig($pdo,$tenantId,$branchId);
        if (!$smtp) return array('status'=>'skipped','notice'=>'Tenant/Branch SMTP is not configured.');

        $autoload=dirname(__DIR__,2).'/vendor/autoload.php';
        if (!file_exists($autoload)) $autoload=dirname(__DIR__,3).'/vendor/autoload.php';
        if (!file_exists($autoload)) return array('status'=>'failed','notice'=>'PHPMailer vendor/autoload.php was not found.');
        require_once $autoload;
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return array('status'=>'failed','notice'=>'PHPMailer is not installed.');

        try {
            $mail=new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host=(string)$smtp['host'];
            $mail->Port=(int)$smtp['port'];
            $mail->SMTPAuth=trim((string)$smtp['username'])!=='';
            if ($mail->SMTPAuth) {
                $mail->Username=(string)$smtp['username'];
                $mail->Password=fieldplxQuoteSmtpDecrypt($smtp['password_encrypted'],$tenantId);
            }
            $enc=strtolower(trim((string)$smtp['encryption']));
            if ($enc==='ssl') $mail->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            elseif ($enc==='tls' || $enc==='starttls') $mail->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            else $mail->SMTPSecure='';
            $mail->CharSet='UTF-8';
            $mail->Timeout=25;
            $from=trim((string)$smtp['from_email']);
            $fromName=trim((string)$smtp['from_name']);
            $mail->setFrom($from,$fromName!==''?$fromName:'FieldPlx');
            if (!empty($smtp['reply_to_email']) && filter_var($smtp['reply_to_email'],FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($smtp['reply_to_email']);
            $mail->addAddress($email,(string)$client['display_name']);
            $mail->isHTML(true);
            $mail->Subject='Quotation '.$quote['quote_no'].' - approval required';
            $symbol=isset($currency['symbol'])?(string)$currency['symbol']:'';
            $position=isset($currency['symbol_position'])?(string)$currency['symbol_position']:'before';
            $dec=isset($currency['decimal_places'])?(int)$currency['decimal_places']:2;
            $money=function($n) use($symbol,$position,$dec){$v=number_format((float)$n,$dec,'.',',');return $position==='after'?$v.' '.$symbol:$symbol.$v;};
            $rows='';
            foreach($items as $it){$rows.='<tr><td style="padding:8px;border-bottom:1px solid #e7ebef">'.htmlspecialchars($it['item_name'],ENT_QUOTES,'UTF-8').'</td><td style="padding:8px;border-bottom:1px solid #e7ebef;text-align:right">'.htmlspecialchars((string)$it['quantity'],ENT_QUOTES,'UTF-8').'</td><td style="padding:8px;border-bottom:1px solid #e7ebef;text-align:right">'.$money($it['line_total']).'</td></tr>';}
            $url=fieldplxQuoteApprovalUrl($token);
            $mail->Body='<div style="font-family:Arial,sans-serif;max-width:700px;margin:auto;color:#1d2b40"><h2 style="color:#123d70">Quotation '.$quote['quote_no'].'</h2><p>Hello '.htmlspecialchars($client['display_name'],ENT_QUOTES,'UTF-8').',</p><p>Please review the quotation below and choose Approve or Reject.</p><table style="width:100%;border-collapse:collapse"><thead><tr><th style="padding:8px;text-align:left;background:#f5f8fb">Item</th><th style="padding:8px;text-align:right;background:#f5f8fb">Qty</th><th style="padding:8px;text-align:right;background:#f5f8fb">Total</th></tr></thead><tbody>'.$rows.'</tbody></table><p style="font-size:18px"><strong>Total: '.$money($quote['total']).'</strong></p><p><a href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'" style="display:inline-block;padding:12px 18px;border-radius:7px;background:#74b824;color:#fff;text-decoration:none;font-weight:bold">Review Quotation</a></p><p style="color:#6f7b90;font-size:12px">This secure link expires on '.htmlspecialchars($quote['approval_expires'],ENT_QUOTES,'UTF-8').'.</p></div>';
            $mail->AltBody='Quotation '.$quote['quote_no'].' total '.$money($quote['total']).'. Review: '.$url;
            $mail->send();
            return array('status'=>'sent','notice'=>'Quotation approval email sent to customer.');
        } catch (Throwable $e) {
            error_log('Quotation email failed: '.$e->getMessage());
            return array('status'=>'failed','notice'=>'Quotation created, but email failed: '.$e->getMessage());
        }
    }
}
