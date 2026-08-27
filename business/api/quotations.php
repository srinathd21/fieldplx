<?php
/* FieldPlx Quotations API - Version 3.0.0 - 2026-08-27 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php'))
    require_once __DIR__ . '/../includes/audit.php';

/* FieldPlx Quotations API v3.0.0 - inline quotation email sender */
if (!defined('FIELDPLX_QUOTATIONS_API_VERSION')) {
    define('FIELDPLX_QUOTATIONS_API_VERSION', '3.0.0');
}

/* Load the permanent SMTP secret when available. Email-only actions will
 * report a clear configuration error if the key is not configured. */
foreach (array(
    __DIR__ . '/../includes/smtp-secret.php',
    __DIR__ . '/../../includes/smtp-secret.php',
    __DIR__ . '/../../platform/includes/smtp-secret.php'
) as $qSecretFile) {
    if (is_file($qSecretFile)) {
        require_once $qSecretFile;
        break;
    }
}
unset($qSecretFile);

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
    $s = $pdo->prepare("SELECT id,sku,name,description,unit_name,base_unit_price,markup_type,markup_value,selling_price,tax_percent FROM products WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY name");
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
 * Inline SMTP / quotation email helpers
 * -------------------------------------------------------------------------- */
function qSmtpSecretKey()
{
    $key = '';
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $key = trim((string) FIELDPLX_SMTP_ENCRYPTION_KEY);
    }
    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) $key = trim((string) $env);
    }
    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) $key = trim((string) $env);
    }
    if ($key === '' || $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY') {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured. Configure the same permanent SMTP encryption key used when the SMTP password was saved.');
    }
    if (strlen($key) < 32) {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.');
    }
    return hash('sha256', $key, true);
}

function qDecryptSmtpPassword($stored)
{
    $stored = trim((string) $stored);
    if ($stored === '') return '';
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL extension is required to decrypt the SMTP password.');
    }
    if (strpos($stored, 'v1:') !== 0) {
        throw new RuntimeException('SMTP password uses the old encryption format. Open Master Controls, re-enter the SMTP password, and save it once.');
    }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException('Stored SMTP password is invalid. Re-enter and save the SMTP password.');
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', qSmtpSecretKey(), OPENSSL_RAW_DATA, $iv);
    if ($plain === false) {
        throw new RuntimeException('Unable to decrypt SMTP password. Confirm the same FIELDPLX_SMTP_ENCRYPTION_KEY is used on this server, then re-save the SMTP password.');
    }
    return $plain;
}

function qLoadSmtpConfig(PDO $pdo, $tenant, $branch)
{
    if (!qTable($pdo, 'smtp_configurations')) {
        throw new RuntimeException('SMTP configuration table is not available.');
    }
    $sql = "SELECT * FROM smtp_configurations
            WHERE is_active=1 AND (
                (scope_type='branch' AND tenant_id=:t1 AND branch_id=:b)
                OR (scope_type='tenant' AND tenant_id=:t2)
                OR (scope_type='platform')
            )
            ORDER BY
                CASE
                    WHEN scope_type='branch' AND tenant_id=:t3 AND branch_id=:b2 THEN 0
                    WHEN scope_type='tenant' AND tenant_id=:t4 THEN 1
                    WHEN scope_type='platform' THEN 2
                    ELSE 9
                END,
                is_default DESC,
                id DESC
            LIMIT 1";
    $s = $pdo->prepare($sql);
    $b = $branch > 0 ? $branch : -1;
    $s->execute(array(':t1'=>$tenant, ':b'=>$b, ':t2'=>$tenant, ':t3'=>$tenant, ':b2'=>$b, ':t4'=>$tenant));
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('No active Tenant, Branch, or Platform SMTP configuration is available.');
    }
    return $row;
}

function qLoadPhpMailer()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;
    $paths = array(
        dirname(__DIR__, 2) . '/vendor/autoload.php',
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

function qHeaderValue($value)
{
    return trim(str_replace(array("\r", "\n"), ' ', (string) $value));
}

function qSmtpRead($socket)
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) break;
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return trim($response);
}

function qSmtpCmd($socket, $command, $expected, $label)
{
    if ($command !== null && @fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('SMTP connection closed while sending ' . $label . '.');
    }
    $res = qSmtpRead($socket);
    $code = (int) substr($res, 0, 3);
    if (!in_array($code, (array) $expected, true)) {
        $safe = preg_replace('/[\r\n]+/', ' ', $res);
        throw new RuntimeException($label . ' failed (SMTP ' . $code . '): ' . substr($safe, 0, 300));
    }
    return $res;
}

function qRawSmtpSend(array $config, $password, $to, $subject, $html)
{
    $host = trim((string) $config['host']);
    $port = (int) $config['port'];
    $enc = strtolower(trim((string) $config['encryption']));
    $username = trim((string) $config['username']);
    $fromEmail = trim((string) $config['from_email']);
    $fromName = trim((string) $config['from_name']);
    $replyTo = trim((string) $config['reply_to_email']);
    if ($host === '') throw new RuntimeException('SMTP host is empty.');
    if ($port < 1 || $port > 65535) throw new RuntimeException('SMTP port is invalid.');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Customer email address is invalid.');
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP From Email is invalid.');
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(array('ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host)));
    $errno = 0; $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 25, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) throw new RuntimeException('Unable to connect to SMTP server: ' . ($errstr !== '' ? $errstr : 'connection failed') . '.');
    stream_set_timeout($socket, 25);
    try {
        qSmtpCmd($socket, null, array(220), 'SMTP greeting');
        $ehlo = !empty($_SERVER['SERVER_NAME']) ? preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME']) : 'fieldplx.local';
        if ($ehlo === '') $ehlo = 'fieldplx.local';
        qSmtpCmd($socket, 'EHLO ' . $ehlo, array(250), 'EHLO');
        if ($enc === 'tls' || $enc === 'starttls') {
            qSmtpCmd($socket, 'STARTTLS', array(220), 'STARTTLS');
            $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (@stream_socket_enable_crypto($socket, true, $method) !== true) throw new RuntimeException('Unable to establish TLS encryption.');
            qSmtpCmd($socket, 'EHLO ' . $ehlo, array(250), 'EHLO after STARTTLS');
        }
        if ($username !== '') {
            if ($password === '') throw new RuntimeException('SMTP password is empty or could not be decrypted.');
            qSmtpCmd($socket, 'AUTH LOGIN', array(334), 'SMTP authentication');
            qSmtpCmd($socket, base64_encode($username), array(334), 'SMTP username');
            qSmtpCmd($socket, base64_encode($password), array(235), 'SMTP password');
        }
        qSmtpCmd($socket, 'MAIL FROM:<' . $fromEmail . '>', array(250), 'MAIL FROM');
        qSmtpCmd($socket, 'RCPT TO:<' . $to . '>', array(250,251), 'RCPT TO');
        qSmtpCmd($socket, 'DATA', array(354), 'DATA');
        $subjectHeader = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . qHeaderValue($fromName !== '' ? $fromName : 'FieldPlx') . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subjectHeader,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        );
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: ' . $replyTo;
        $body = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $html) . "\r\n.";
        qSmtpCmd($socket, $body, array(250), 'Message body');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }
}

function qSendHtmlMail(array $config, $to, $subject, $html)
{
    $password = qDecryptSmtpPassword(isset($config['password_encrypted']) ? $config['password_encrypted'] : '');
    if (qLoadPhpMailer()) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = trim((string) $config['host']);
        $mail->Port = (int) $config['port'];
        $mail->Timeout = 25;
        $mail->Timelimit = 25;
        $mail->SMTPDebug = 0;
        $username = trim((string) $config['username']);
        $mail->SMTPAuth = $username !== '';
        if ($mail->SMTPAuth) {
            if ($password === '') throw new RuntimeException('SMTP password is empty or could not be decrypted.');
            $mail->Username = $username;
            $mail->Password = $password;
        }
        $enc = strtolower(trim((string) $config['encryption']));
        if ($enc === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls' || $enc === 'starttls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        $fromEmail = trim((string) $config['from_email']);
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP From Email is invalid.');
        $fromName = trim((string) $config['from_name']);
        if ($fromName === '') $fromName = 'FieldPlx';
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $replyTo = trim((string) $config['reply_to_email']);
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(array('<br>','<br/>','<br />'), "\n", $html))));
        $mail->send();
        return;
    }
    qRawSmtpSend($config, $password, $to, $subject, $html);
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

function qSendQuotationEmail(PDO $pdo, $tenant, $branch, array $quote, array $client, array $items, array $currency, $plainToken)
{
    $to = trim((string) (isset($client['email']) ? $client['email'] : ''));
    if ($to === '') return array('status'=>'skipped','notice'=>'Customer email is empty. Add an email address and use Resend Email.');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return array('status'=>'failed','notice'=>'Customer email address is invalid.');
    $config = qLoadSmtpConfig($pdo, $tenant, $branch);
    $reviewUrl = qAppBaseUrl() . '/quotation-response.php?token=' . rawurlencode((string) $plainToken);
    $clientName = trim((string) (isset($client['display_name']) ? $client['display_name'] : 'Customer'));
    if ($clientName === '') $clientName = 'Customer';
    $quoteNo = (string) $quote['quote_no'];
    $subject = 'Quotation ' . $quoteNo . ' - Approval Required';
    $rows = '';
    foreach ($items as $item) {
        $name = htmlspecialchars((string) $item['item_name'], ENT_QUOTES, 'UTF-8');
        $qty = rtrim(rtrim(number_format((float) $item['quantity'], 3, '.', ''), '0'), '.');
        $amount = qMoney(isset($item['line_total']) ? $item['line_total'] : 0, $currency);
        $rows .= '<tr><td style="padding:10px;border-bottom:1px solid #e8edf3;color:#17233b">'.$name.'</td><td style="padding:10px;border-bottom:1px solid #e8edf3;text-align:center;color:#52627a">'.htmlspecialchars($qty,ENT_QUOTES,'UTF-8').'</td><td style="padding:10px;border-bottom:1px solid #e8edf3;text-align:right;color:#17233b">'.htmlspecialchars($amount,ENT_QUOTES,'UTF-8').'</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="3" style="padding:12px;color:#6f7b90">Quotation items are available in the quotation.</td></tr>';
    $expires = !empty($quote['approval_expires']) ? date('d M Y, h:i A', strtotime($quote['approval_expires'])) : '';
    $total = htmlspecialchars(qMoney(isset($quote['total']) ? $quote['total'] : 0, $currency), ENT_QUOTES, 'UTF-8');
    $safeClient = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
    $safeNo = htmlspecialchars($quoteNo, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($reviewUrl, ENT_QUOTES, 'UTF-8');
    $html = '<!doctype html><html><body style="margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#0b1933">'
          . '<div style="max-width:680px;margin:0 auto;padding:28px 14px"><div style="background:#001131;padding:22px 24px;border-radius:12px 12px 0 0"><div style="color:#9fda55;font-size:13px;font-weight:700">FieldPlx</div><div style="margin-top:5px;color:#fff;font-size:22px;font-weight:700">Quotation '.$safeNo.'</div></div>'
          . '<div style="background:#fff;padding:24px;border:1px solid #e5eaf1;border-top:0;border-radius:0 0 12px 12px"><p style="margin-top:0">Hello '.$safeClient.',</p><p style="color:#52627a;line-height:1.6">A quotation has been prepared for you. Please review the details and choose Approve or Reject from the secure response page.</p>'
          . '<table style="width:100%;border-collapse:collapse;margin:18px 0"><thead><tr style="background:#f6f8fb"><th style="padding:10px;text-align:left">Item</th><th style="padding:10px;text-align:center">Qty</th><th style="padding:10px;text-align:right">Amount</th></tr></thead><tbody>'.$rows.'</tbody></table>'
          . '<div style="text-align:right;font-size:18px;font-weight:700;margin:16px 0">Total: '.$total.'</div>'
          . '<div style="text-align:center;margin:24px 0"><a href="'.$safeUrl.'" style="display:inline-block;padding:13px 22px;border-radius:8px;background:#74b824;color:#fff;text-decoration:none;font-weight:700">Review &amp; Approve / Reject</a></div>'
          . ($expires !== '' ? '<p style="font-size:12px;color:#7b8798;text-align:center">This secure response link expires on '.htmlspecialchars($expires,ENT_QUOTES,'UTF-8').'.</p>' : '')
          . '<p style="margin-bottom:0;font-size:12px;color:#8a96a7">If the button does not open, copy this link into your browser:<br><span style="word-break:break-all">'.$safeUrl.'</span></p></div></div></body></html>';
    qSendHtmlMail($config, $to, $subject, $html);
    return array('status'=>'sent','notice'=>'Quotation approval email sent successfully.','to'=>$to);
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
        $c = $pdo->prepare("SELECT id,name,item_type,sku,description,unit_name,unit_cost,unit_price,tax_percent FROM product_services WHERE tenant_id=:t AND status='active' AND deleted_at IS NULL ORDER BY FIELD(item_type,'service','material','fee','discount','product'),name");
        $c->execute(array(':t' => $tenant));
        $cl = $pdo->prepare("SELECT id,branch_id,display_name,email,phone FROM clients WHERE tenant_id=:t AND deleted_at IS NULL AND status<>'archived' ORDER BY display_name");
        $cl->execute(array(':t' => $tenant));
        $clients = $cl->fetchAll(PDO::FETCH_ASSOC);
        $lo = $pdo->prepare("SELECT id,client_id,name,address_line1,city,state,postal_code,is_primary FROM client_locations WHERE tenant_id=:t AND deleted_at IS NULL AND status='active' ORDER BY is_primary DESC,name");
        $lo->execute(array(':t' => $tenant));
        qRes(200, true, 'Quotation form data loaded.', array('meta' => array('requests' => $sources, 'catalog' => $c->fetchAll(PDO::FETCH_ASSOC), 'products' => qProducts($pdo, $tenant), 'clients' => $clients, 'locations' => $lo->fetchAll(PDO::FETCH_ASSOC), 'currency' => qCurrency($pdo, $tenant))));
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
        qRes(200, true, 'Quotation loaded.', array('quotation' => $q, 'items' => $i->fetchAll(PDO::FETCH_ASSOC), 'currency' => qCurrency($pdo, $tenant), 'approval' => $approval));
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

        $itemsQ = $pdo->prepare("SELECT item_name,quantity,line_total FROM quote_line_items WHERE quote_id=:q ORDER BY sort_order,id");
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
                'quote_no'=>$quote['quote_no'],
                'total'=>$quote['total'],
                'approval_expires'=>$token['expires']
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
            qRes(200, true, 'Quotation email resent successfully.', array('email_status'=>'sent','approval_expires'=>$token['expires'],'api_file'=>'quotations-api-v3.0.0.php'));
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

    if ($action === 'save') {
        if (!qTable($pdo, 'quotation_action_tokens'))
            qRes(500, false, 'Quotation approval support is not installed. Run migration_quotation_products_approval.sql once.');
        $id = (int) qP('quote_id', 0);
        $requestId = (int) qP('request_id', 0);
        $revisitId = (int) qP('assessment_reschedule_id', 0);
        $directClientId = (int) qP('client_id', 0);
        $directLocationId = (int) qP('location_id', 0);
        $title = trim((string) qP('title', ''));
        $status = trim((string) qP('status', 'sent'));
        $allowed = array('approved', 'draft', 'internal_approval', 'sent', 'viewed', 'changes_requested', 'rejected', 'expired');
        if (!in_array($status, $allowed, true))
            qRes(422, false, 'Select a valid quotation status.');
        $requestedStatus = $status;
        $intro = trim((string) qP('introduction', ''));
        $valid = trim((string) qP('valid_until', ''));
        $items = json_decode((string) qP('items_json', '[]'), true);
        if ($title === '')
            qRes(422, false, 'Quotation title is required.');
        if (!is_array($items) || !count($items))
            qRes(422, false, 'Add at least one quotation item.');
        $request = null;
        $revisit = null;
        $client = null;
        $location = null;
        $branch = $sessionBranch;
        if ($requestId > 0) {
            $request = qValidRequest($pdo, $tenant, $requestId);
            if (!$request)
                qRes(422, false, 'Selected enquiry is invalid or closed.');
            if ($revisitId > 0) {
                $revisit = qValidRevisit($pdo, $tenant, $requestId, $revisitId);
                if (!$revisit)
                    qRes(422, false, 'Selected revisit does not belong to this enquiry.');
            }
            $client = qClient($pdo, $tenant, (int) $request['client_id']);
            $location = !empty($request['location_id']) ? qLocation($pdo, $tenant, (int) $request['client_id'], (int) $request['location_id']) : null;
            $branch = !empty($request['branch_id']) ? (int) $request['branch_id'] : $sessionBranch;
        } else {
            $client = qClient($pdo, $tenant, $directClientId);
            if (!$client)
                qRes(422, false, 'Select a customer for a direct quotation.');
            if ($directLocationId > 0) {
                $location = qLocation($pdo, $tenant, (int) $client['id'], $directLocationId);
                if (!$location)
                    qRes(422, false, 'Selected customer location is invalid.');
            }
            $branch = !empty($client['branch_id']) ? (int) $client['branch_id'] : $sessionBranch;
            $revisitId = 0;
        }
        $existing = null;
        if ($id > 0) {
            $s = $pdo->prepare("SELECT * FROM quotes WHERE id=:id AND tenant_id=:t LIMIT 1");
            $s->execute(array(':id' => $id, ':t' => $tenant));
            $existing = $s->fetch(PDO::FETCH_ASSOC);
            if (!$existing)
                qRes(404, false, 'Quotation not found.');
            if (!in_array($existing['status'], array('draft', 'internal_approval', 'changes_requested', 'sent'), true))
                qRes(409, false, 'This quotation can no longer be edited in its current status.');
        } elseif ($requestId > 0) {
            if ($hasRevisitColumn) {
                if ($revisitId > 0) {
                    $s = $pdo->prepare("SELECT id FROM quotes WHERE tenant_id=:t AND request_id=:r AND assessment_reschedule_id=:h AND status<>'archived' LIMIT 1");
                    $s->execute(array(':t' => $tenant, ':r' => $requestId, ':h' => $revisitId));
                } else {
                    $s = $pdo->prepare("SELECT id FROM quotes WHERE tenant_id=:t AND request_id=:r AND assessment_reschedule_id IS NULL AND status<>'archived' LIMIT 1");
                    $s->execute(array(':t' => $tenant, ':r' => $requestId));
                }
                if ($s->fetchColumn())
                    qRes(409, false, 'This enquiry/revisit already has a quotation.');
            }
        }
        $persistStatus = (!$existing && $requestedStatus === 'sent') ? 'draft' : $requestedStatus;
        $status = $persistStatus;
        $hasProductId = qCol($pdo, 'quote_line_items', 'product_id');
        $sub = 0;
        $disc = 0;
        $tax = 0;
        $tot = 0;
        $norm = array();
        foreach ($items as $idx => $x) {
            $psid = isset($x['product_service_id']) ? (int) $x['product_service_id'] : 0;
            $prodId = isset($x['product_id']) ? (int) $x['product_id'] : 0;
            if ($psid > 0) {
                $ps = $pdo->prepare("SELECT id FROM product_services WHERE id=:id AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");
                $ps->execute(array(':id' => $psid, ':t' => $tenant));
                if (!$ps->fetchColumn())
                    qRes(422, false, 'One selected catalog item is no longer active.');
            } else {
                $psid = null;
            }
            if ($prodId > 0) {
                if (!qTable($pdo, 'products'))
                    qRes(422, false, 'Product master is not installed.');
                $ps = $pdo->prepare("SELECT id FROM products WHERE id=:id AND tenant_id=:t AND status='active' AND deleted_at IS NULL LIMIT 1");
                $ps->execute(array(':id' => $prodId, ':t' => $tenant));
                if (!$ps->fetchColumn())
                    qRes(422, false, 'One selected product is no longer active.');
            } else {
                $prodId = null;
            }
            $name = trim((string) (isset($x['item_name']) ? $x['item_name'] : ''));
            if ($name === '')
                qRes(422, false, 'Quotation item name is required.');
            $qty = max(.001, (float) (isset($x['quantity']) ? $x['quantity'] : 1));
            $cost = max(0, (float) (isset($x['unit_cost']) ? $x['unit_cost'] : 0));
            $price = max(0, (float) (isset($x['unit_price']) ? $x['unit_price'] : 0));
            $base = $qty * $price;
            $d = max(0, min($base, (float) (isset($x['discount_amount']) ? $x['discount_amount'] : 0)));
            $tp = max(0, (float) (isset($x['tax_percent']) ? $x['tax_percent'] : 0));
            $taxable = max(0, $base - $d);
            $ta = $taxable * $tp / 100;
            $lt = $taxable + $ta;
            $sub += $base;
            $disc += $d;
            $tax += $ta;
            $tot += $lt;
            $norm[] = array('psid' => $psid, 'prodid' => $prodId, 'name' => $name, 'description' => trim((string) (isset($x['description']) ? $x['description'] : '')), 'qty' => $qty, 'cost' => $cost, 'price' => $price, 'disc' => $d, 'tp' => $tp, 'ta' => $ta, 'lt' => $lt, 'optional' => !empty($x['is_optional']) ? 1 : 0, 'sort' => $idx);
        }
        $pdo->beginTransaction();
        try {
            $clientId = (int) $client['id'];
            $locationId = $location ? (int) $location['id'] : null;
            if ($id > 0) {
                $setRevisit = $hasRevisitColumn ? ',assessment_reschedule_id=:ar' : '';
                $u = $pdo->prepare("UPDATE quotes SET branch_id=:b,client_id=:c,location_id=:l,request_id=:r $setRevisit,title=:title,introduction=:intro,status=:status,subtotal=:sub,discount_total=:disc,tax_total=:tax,total=:tot,valid_until=:vu WHERE id=:id AND tenant_id=:t");
                $up = array(':b' => $branch > 0 ? $branch : null, ':c' => $clientId, ':l' => $locationId, ':r' => $requestId > 0 ? $requestId : null, ':title' => $title, ':intro' => $intro !== '' ? $intro : null, ':status' => $persistStatus, ':sub' => $sub, ':disc' => $disc, ':tax' => $tax, ':tot' => $tot, ':vu' => $valid !== '' ? $valid : null, ':id' => $id, ':t' => $tenant);
                if ($hasRevisitColumn)
                    $up[':ar'] = $revisitId > 0 ? $revisitId : null;
                $u->execute($up);
                $pdo->prepare("DELETE FROM quote_line_items WHERE quote_id=:q")->execute(array(':q' => $id));
                $quoteNo = $existing['quote_no'];
            } else {
                $quoteNo = qNext($pdo, $tenant, $branch);
                if ($hasRevisitColumn) {
                    $ins = $pdo->prepare("INSERT INTO quotes(tenant_id,branch_id,quote_no,revision_no,client_id,location_id,request_id,assessment_reschedule_id,title,introduction,status,subtotal,discount_total,tax_total,total,valid_until,created_by) VALUES(:t,:b,:no,0,:c,:l,:r,:ar,:title,:intro,:status,:sub,:disc,:tax,:tot,:vu,:u)");
                    $ins->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : null, ':no' => $quoteNo, ':c' => $clientId, ':l' => $locationId, ':r' => $requestId > 0 ? $requestId : null, ':ar' => $revisitId > 0 ? $revisitId : null, ':title' => $title, ':intro' => $intro !== '' ? $intro : null, ':status' => $persistStatus, ':sub' => $sub, ':disc' => $disc, ':tax' => $tax, ':tot' => $tot, ':vu' => $valid !== '' ? $valid : null, ':u' => $user));
                } else {
                    $ins = $pdo->prepare("INSERT INTO quotes(tenant_id,branch_id,quote_no,revision_no,client_id,location_id,request_id,title,introduction,status,subtotal,discount_total,tax_total,total,valid_until,created_by) VALUES(:t,:b,:no,0,:c,:l,:r,:title,:intro,:status,:sub,:disc,:tax,:tot,:vu,:u)");
                    $ins->execute(array(':t' => $tenant, ':b' => $branch > 0 ? $branch : null, ':no' => $quoteNo, ':c' => $clientId, ':l' => $locationId, ':r' => $requestId > 0 ? $requestId : null, ':title' => $title, ':intro' => $intro !== '' ? $intro : null, ':status' => $persistStatus, ':sub' => $sub, ':disc' => $disc, ':tax' => $tax, ':tot' => $tot, ':vu' => $valid !== '' ? $valid : null, ':u' => $user));
                }
                $id = (int) $pdo->lastInsertId();
            }
            if ($hasProductId) {
                $li = $pdo->prepare("INSERT INTO quote_line_items(quote_id,product_service_id,product_id,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,is_optional,sort_order) VALUES(:q,:ps,:pr,:name,:d,:qty,:cost,:price,:disc,:tp,:ta,:lt,:opt,:sort)");
                foreach ($norm as $x) {
                    $li->execute(array(':q' => $id, ':ps' => $x['psid'], ':pr' => $x['prodid'], ':name' => $x['name'], ':d' => $x['description'] !== '' ? $x['description'] : null, ':qty' => $x['qty'], ':cost' => $x['cost'], ':price' => $x['price'], ':disc' => $x['disc'], ':tp' => $x['tp'], ':ta' => $x['ta'], ':lt' => $x['lt'], ':opt' => $x['optional'], ':sort' => $x['sort']));
                }
            } else {
                $li = $pdo->prepare("INSERT INTO quote_line_items(quote_id,product_service_id,item_name,description,quantity,unit_cost,unit_price,discount_amount,tax_percent,tax_amount,line_total,is_optional,sort_order) VALUES(:q,:ps,:name,:d,:qty,:cost,:price,:disc,:tp,:ta,:lt,:opt,:sort)");
                foreach ($norm as $x) {
                    $li->execute(array(':q' => $id, ':ps' => $x['psid'], ':name' => $x['name'], ':d' => $x['description'] !== '' ? $x['description'] : null, ':qty' => $x['qty'], ':cost' => $x['cost'], ':price' => $x['price'], ':disc' => $x['disc'], ':tp' => $x['tp'], ':ta' => $x['ta'], ':lt' => $x['lt'], ':opt' => $x['optional'], ':sort' => $x['sort']));
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            throw $e;
        }
        $mail = array('status' => 'skipped', 'notice' => 'Email not sent.');
        if (!$existing && $requestedStatus === 'sent') {
            $mailToken = null;
            try {
                if (!qTable($pdo, 'quotation_action_tokens')) {
                    throw new RuntimeException('Quotation approval support is not installed.');
                }
                $mailToken = qCreatePendingApprovalToken($pdo, $tenant, $id, (int) $client['id'], 14);
                $quoteForMail = array('quote_no' => $quoteNo, 'total' => $tot, 'approval_expires' => $mailToken['expires']);
                $mailItems = array();
                foreach ($norm as $x) {
                    $mailItems[] = array('item_name' => $x['name'], 'quantity' => $x['qty'], 'line_total' => $x['lt']);
                }
                $mail = qSendQuotationEmail($pdo, $tenant, $branch, $quoteForMail, $client, $mailItems, qCurrency($pdo, $tenant), $mailToken['plain']);
                if ($mail['status'] === 'sent') {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("UPDATE quotation_action_tokens SET used_at=NOW() WHERE tenant_id=:t AND quote_id=:q AND used_at IS NULL AND id<>:id")
                            ->execute(array(':t'=>$tenant, ':q'=>$id, ':id'=>$mailToken['id']));
                        $pdo->prepare("UPDATE quotes SET status='sent',sent_at=NOW() WHERE id=:id AND tenant_id=:t")
                            ->execute(array(':id'=>$id, ':t'=>$tenant));
                        $status = 'sent';
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $e;
                    }
                } else {
                    if ($mailToken && !empty($mailToken['id'])) {
                        $pdo->prepare("DELETE FROM quotation_action_tokens WHERE id=:id AND tenant_id=:t AND quote_id=:q AND used_at IS NULL")
                            ->execute(array(':id'=>$mailToken['id'], ':t'=>$tenant, ':q'=>$id));
                    }
                }
            } catch (Throwable $mailError) {
                if ($mailToken && !empty($mailToken['id'])) {
                    try {
                        $pdo->prepare("DELETE FROM quotation_action_tokens WHERE id=:id AND tenant_id=:t AND quote_id=:q AND used_at IS NULL")
                            ->execute(array(':id'=>$mailToken['id'], ':t'=>$tenant, ':q'=>$id));
                    } catch (Throwable $cleanupError) {
                        error_log('quotation initial mail token cleanup ' . $cleanupError->getMessage());
                    }
                }
                error_log('quotation approval email ' . $mailError->getMessage());
                $mail = array('status' => 'failed', 'notice' => 'Quotation created, but approval email failed: ' . $mailError->getMessage());
            }
        }
        $details = array('request_id' => $requestId > 0 ? $requestId : null, 'assessment_reschedule_id' => $revisitId > 0 ? $revisitId : null, 'source' => $requestId > 0 ? ($revisitId > 0 ? 'revisit' : 'original_enquiry') : 'direct_quotation', 'total' => $tot, 'status' => $status);
        qLog($pdo, $tenant, $branch, $user, $id, (int) $client['id'], $existing ? 'quote_updated' : 'quote_created', ($existing ? 'Quotation updated: ' : 'Quotation created: ') . $quoteNo, $details);
        if (function_exists('tenantAuditLog')) {
            try {
                tenantAuditLog($pdo, $existing ? 'QUOTE_UPDATED' : 'QUOTE_CREATED', $tenant, $branch, $user, 'quote', $id, $existing, array('quote_no' => $quoteNo, 'request_id' => $requestId > 0 ? $requestId : null, 'total' => $tot, 'status' => $status));
            } catch (Throwable $ae) {
                error_log('quote audit ' . $ae->getMessage());
            }
        }
        qRes(200, true, 'Quotation ' . $quoteNo . ($existing ? ' updated' : ' created') . ' successfully.', array('quote_id' => $id, 'quote_no' => $quoteNo, 'source' => $requestId > 0 ? ($revisitId > 0 ? 'revisit' : 'original_enquiry') : 'direct_quotation', 'status' => $status, 'email_status' => $mail['status'], 'email_notice' => $mail['notice'], 'api_file' => 'quotations-api-v3.0.0.php'));
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