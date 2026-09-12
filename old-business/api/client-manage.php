<?php
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function cmResponse($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int) $status);
    echo json_encode(array_merge(array(
        'success' => (bool) $success,
        'message' => (string) $message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cmPost($key, $default = '')
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function cmTableExists(PDO $pdo, $table)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n");
    $q->execute(array(':n' => $table));
    return (int) $q->fetchColumn() > 0;
}

function cmColumnExists(PDO $pdo, $table, $column)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $q->execute(array(':t' => $table, ':c' => $column));
    return (int) $q->fetchColumn() > 0;
}

function cmRequireTagTables(PDO $pdo)
{
    if (!cmTableExists($pdo, 'client_tags') || !cmTableExists($pdo, 'client_tag_assignments')) {
        cmResponse(500, false, 'Client tag tables are not installed. Run migration_client_tags.sql once.');
    }
}

function cmAudit(PDO $pdo, $tenantId, $branchId, $userId, $action, $clientId, $oldData, $newData)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog($pdo, $action, $tenantId, $branchId, $userId, 'client', $clientId, $oldData, $newData);
        } catch (Throwable $e) {
            error_log('Client manage audit error: ' . $e->getMessage());
        }
    }
}

function cmClient(PDO $pdo, $tenantId, $clientId)
{
    $q = $pdo->prepare("SELECT * FROM clients WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id' => $clientId, ':t' => $tenantId));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        cmResponse(404, false, 'Client not found.');
    }
    return $row;
}

function cmTags(PDO $pdo, $tenantId)
{
    if (!cmTableExists($pdo, 'client_tags')) {
        return array();
    }
    $q = $pdo->prepare("SELECT id, name, COALESCE(color, '') AS color FROM client_tags WHERE tenant_id = :t AND is_active = 1 ORDER BY name ASC");
    $q->execute(array(':t' => $tenantId));
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function cmStats(PDO $pdo, $tenantId)
{
    $jobsExists = cmTableExists($pdo, 'jobs');
    $invoicesExists = cmTableExists($pdo, 'invoices');

    $jobJoin = '';
    $invoiceJoin = '';
    $jobValue = 'NULL';
    $invoiceValue = 'NULL';

    if ($jobsExists) {
        $jobJoin = "LEFT JOIN (
            SELECT tenant_id, client_id, MIN(created_at) AS first_job_at
            FROM jobs
            WHERE deleted_at IS NULL
              AND status NOT IN ('draft', 'cancelled', 'archived')
            GROUP BY tenant_id, client_id
        ) j ON j.tenant_id = c.tenant_id AND j.client_id = c.id";
        $jobValue = 'j.first_job_at';
    }

    if ($invoicesExists) {
        $invoiceStatusFilter = cmColumnExists($pdo, 'invoices', 'status')
            ? "WHERE status NOT IN ('cancelled', 'archived', 'written_off')"
            : '';
        $invoiceJoin = "LEFT JOIN (
            SELECT tenant_id, client_id, MIN(created_at) AS first_invoice_at
            FROM invoices
            $invoiceStatusFilter
            GROUP BY tenant_id, client_id
        ) i ON i.tenant_id = c.tenant_id AND i.client_id = c.id";
        $invoiceValue = 'i.first_invoice_at';
    }

    $effective = "CASE
        WHEN c.client_type = 'client' THEN c.created_at
        WHEN $jobValue IS NULL THEN $invoiceValue
        WHEN $invoiceValue IS NULL THEN $jobValue
        WHEN $jobValue <= $invoiceValue THEN $jobValue
        ELSE $invoiceValue
    END";

    $sql = "SELECT
        SUM(CASE WHEN effective_customer_at IS NULL AND original_type = 'lead' AND client_created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND client_created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS new_leads_30,
        SUM(CASE WHEN effective_customer_at IS NULL AND original_type = 'lead' AND client_created_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY) AND client_created_at < DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN 1 ELSE 0 END) AS prior_leads_30,
        SUM(CASE WHEN effective_customer_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND effective_customer_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS new_clients_30,
        SUM(CASE WHEN effective_customer_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY) AND effective_customer_at < DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN 1 ELSE 0 END) AS prior_clients_30,
        SUM(CASE WHEN effective_customer_at >= MAKEDATE(YEAR(CURDATE()), 1) AND effective_customer_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS new_clients_ytd
    FROM (
        SELECT c.client_type AS original_type, c.created_at AS client_created_at, $effective AS effective_customer_at
        FROM clients c
        $jobJoin
        $invoiceJoin
        WHERE c.tenant_id = :t AND c.deleted_at IS NULL AND c.client_type <> 'archived'
    ) crm";

    $q = $pdo->prepare($sql);
    $q->execute(array(':t' => $tenantId));
    $r = $q->fetch(PDO::FETCH_ASSOC);

    $currentLeads = (int) ($r['new_leads_30'] ?? 0);
    $priorLeads = (int) ($r['prior_leads_30'] ?? 0);
    $currentClients = (int) ($r['new_clients_30'] ?? 0);
    $priorClients = (int) ($r['prior_clients_30'] ?? 0);

    $leadChange = $priorLeads > 0 ? (($currentLeads - $priorLeads) / $priorLeads) * 100 : ($currentLeads > 0 ? 100.0 : 0.0);
    $clientChange = $priorClients > 0 ? (($currentClients - $priorClients) / $priorClients) * 100 : ($currentClients > 0 ? 100.0 : 0.0);

    return array(
        'new_leads_30' => $currentLeads,
        'prior_leads_30' => $priorLeads,
        'new_leads_change' => round($leadChange, 1),
        'new_clients_30' => $currentClients,
        'prior_clients_30' => $priorClients,
        'new_clients_change' => round($clientChange, 1),
        'new_clients_ytd' => (int) ($r['new_clients_ytd'] ?? 0),
        'current_period_label' => date('M j', strtotime('-29 days')) . ' - ' . date('M j'),
        'prior_period_label' => date('M j', strtotime('-59 days')) . ' - ' . date('M j', strtotime('-30 days'))
    );
}

function cmBuildFilter(PDO $pdo, $tenantId, $search, $scope, $tagId, &$params)
{
    $where = array('c.tenant_id = :t', 'c.deleted_at IS NULL');
    $params = array(':t' => $tenantId);

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = "(c.display_name LIKE :s1 OR c.company_name LIKE :s2 OR c.first_name LIKE :s3 OR c.last_name LIKE :s4 OR c.email LIKE :s5 OR c.phone LIKE :s6 OR c.alternate_phone LIKE :s7 OR EXISTS (
            SELECT 1 FROM client_locations sl
            WHERE sl.tenant_id = c.tenant_id AND sl.client_id = c.id AND sl.deleted_at IS NULL
              AND (sl.address_line1 LIKE :s8 OR sl.address_line2 LIKE :s9 OR sl.city LIKE :s10 OR sl.state LIKE :s11 OR sl.postal_code LIKE :s12)
        ))";
        for ($i = 1; $i <= 12; $i++) {
            $params[':s' . $i] = $like;
        }
    }

    if ($scope === 'all') {
        // no status restriction
    } elseif ($scope === 'leads') {
        $where[] = "c.client_type = 'lead' AND c.status <> 'archived'";
    } elseif ($scope === 'active') {
        $where[] = "c.status = 'active' AND c.client_type <> 'archived'";
    } elseif ($scope === 'inactive') {
        $where[] = "c.status = 'inactive' AND c.client_type <> 'archived'";
    } elseif ($scope === 'archived') {
        $where[] = "(c.status = 'archived' OR c.client_type = 'archived')";
    } else {
        $where[] = "((c.client_type = 'lead' AND c.status <> 'archived') OR (c.client_type <> 'archived' AND c.status = 'active'))";
    }

    if ($tagId > 0) {
        cmRequireTagTables($pdo);
        $where[] = "EXISTS (
            SELECT 1 FROM client_tag_assignments cta_filter
            INNER JOIN client_tags ct_filter ON ct_filter.id = cta_filter.tag_id AND ct_filter.tenant_id = cta_filter.tenant_id AND ct_filter.is_active = 1
            WHERE cta_filter.tenant_id = c.tenant_id AND cta_filter.client_id = c.id AND cta_filter.tag_id = :tag_id
        )";
        $params[':tag_id'] = $tagId;
    }

    return implode(' AND ', $where);
}

function cmAttachTags(PDO $pdo, $tenantId, &$rows)
{
    if (!$rows || !cmTableExists($pdo, 'client_tags') || !cmTableExists($pdo, 'client_tag_assignments')) {
        foreach ($rows as &$row) {
            $row['tags'] = array();
        }
        unset($row);
        return;
    }

    $ids = array();
    foreach ($rows as $row) {
        $ids[] = (int) $row['id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT cta.client_id, ct.id, ct.name, COALESCE(ct.color, '') AS color
            FROM client_tag_assignments cta
            INNER JOIN client_tags ct ON ct.id = cta.tag_id AND ct.tenant_id = cta.tenant_id
            WHERE cta.tenant_id = ? AND ct.is_active = 1 AND cta.client_id IN ($placeholders)
            ORDER BY ct.name";
    $q = $pdo->prepare($sql);
    $args = array_merge(array($tenantId), $ids);
    $q->execute($args);
    $map = array();
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        $cid = (int) $r['client_id'];
        if (!isset($map[$cid])) {
            $map[$cid] = array();
        }
        $map[$cid][] = array('id' => (int) $r['id'], 'name' => $r['name'], 'color' => $r['color']);
    }
    foreach ($rows as &$row) {
        $row['tags'] = isset($map[(int) $row['id']]) ? $map[(int) $row['id']] : array();
    }
    unset($row);
}


function cmJsonIds($raw, $label)
{
    $ids = json_decode((string) $raw, true);
    if (!is_array($ids)) {
        cmResponse(422, false, 'Invalid ' . $label . ' selection.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) {
        return $v > 0;
    })));
    if (!$ids) {
        cmResponse(422, false, 'Select at least one ' . $label . '.');
    }
    if (count($ids) > 250) {
        cmResponse(422, false, 'Too many ' . $label . ' records selected at once.');
    }
    return $ids;
}

function cmSmtpSecretKey()
{
    if (!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $file = __DIR__ . '/../includes/smtp-secret.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
    $key = defined('FIELDPLX_SMTP_ENCRYPTION_KEY') ? trim((string) FIELDPLX_SMTP_ENCRYPTION_KEY) : '';
    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) {
            $key = trim((string) $env);
        }
    }
    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) {
            $key = trim((string) $env);
        }
    }
    if ($key === '' || strlen($key) < 32) {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.');
    }
    return hash('sha256', $key, true);
}

function cmSmtpDecrypt($encrypted)
{
    $encrypted = trim((string) $encrypted);
    if ($encrypted === '') {
        return '';
    }
    if (strpos($encrypted, 'v1:') !== 0) {
        throw new RuntimeException('SMTP password uses the old encryption format. Re-enter and save the SMTP password once in Master Controls.');
    }
    $raw = base64_decode(substr($encrypted, 3), true);
    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException('Stored SMTP password is invalid.');
    }
    $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', cmSmtpSecretKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    if ($plain === false) {
        throw new RuntimeException('Unable to decrypt SMTP password. Confirm the same permanent SMTP encryption key is used by all FieldPlx SMTP senders.');
    }
    return $plain;
}

function cmSmtpRead($socket)
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return trim($response);
}

function cmSmtpCmd($socket, $command, $expected, $label)
{
    if ($command !== null && @fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('SMTP connection closed while sending ' . $label . '.');
    }
    $response = cmSmtpRead($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, (array) $expected, true)) {
        throw new RuntimeException($label . ' failed (SMTP ' . $code . '): ' . substr(preg_replace('/[\r\n]+/', ' ', $response), 0, 250));
    }
    return $response;
}

function cmSmtpConfig(PDO $pdo, $tenantId, $branchId)
{
    if (!cmTableExists($pdo, 'smtp_configurations')) {
        return null;
    }
    $q = $pdo->prepare("SELECT * FROM smtp_configurations
                        WHERE tenant_id = :t AND is_active = 1 AND scope_type IN ('tenant','branch')
                          AND (scope_type = 'tenant' OR (scope_type = 'branch' AND branch_id = :b))
                        ORDER BY CASE WHEN scope_type = 'branch' AND branch_id = :b2 THEN 0 ELSE 1 END,
                                 is_default DESC, id DESC
                        LIMIT 1");
    $q->execute(array(
        ':t' => $tenantId,
        ':b' => $branchId > 0 ? $branchId : -1,
        ':b2' => $branchId > 0 ? $branchId : -1
    ));
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function cmSmtpHeader($value)
{
    return trim(str_replace(array("\r", "\n"), ' ', (string) $value));
}

function cmCurrentUserEmail(PDO $pdo, $tenantId, $userId)
{
    foreach (array('user_email', 'email') as $key) {
        if (!empty($_SESSION[$key]) && filter_var($_SESSION[$key], FILTER_VALIDATE_EMAIL)) {
            return trim((string) $_SESSION[$key]);
        }
    }
    if (cmTableExists($pdo, 'tenant_users') && cmColumnExists($pdo, 'tenant_users', 'email')) {
        $q = $pdo->prepare("SELECT email FROM tenant_users WHERE id = :id AND tenant_id = :t LIMIT 1");
        $q->execute(array(':id' => $userId, ':t' => $tenantId));
        $email = trim((string) $q->fetchColumn());
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }
    return '';
}

function cmEmailAttachments()
{
    if (!isset($_FILES['attachments'])) {
        return array();
    }
    $f = $_FILES['attachments'];
    $names = is_array($f['name']) ? $f['name'] : array($f['name']);
    $tmpNames = is_array($f['tmp_name']) ? $f['tmp_name'] : array($f['tmp_name']);
    $sizes = is_array($f['size']) ? $f['size'] : array($f['size']);
    $errors = is_array($f['error']) ? $f['error'] : array($f['error']);
    $types = isset($f['type']) ? (is_array($f['type']) ? $f['type'] : array($f['type'])) : array();
    if (count($names) > 10) {
        cmResponse(422, false, 'A maximum of 10 attachments can be sent at once.');
    }
    $out = array();
    $total = 0;
    $blocked = array('php','phtml','php3','php4','php5','phar','exe','com','bat','cmd','sh','bash','js','jar','msi','scr');
    foreach ($names as $i => $name) {
        $error = isset($errors[$i]) ? (int) $errors[$i] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            cmResponse(422, false, 'One of the email attachments could not be uploaded.');
        }
        $size = isset($sizes[$i]) ? (int) $sizes[$i] : 0;
        $total += $size;
        if ($total > 10 * 1024 * 1024) {
            cmResponse(422, false, 'Attachments exceed the 10.00 MB limit.');
        }
        $safe = trim((string) $name);
        $safe = preg_replace('/[\r\n\x00-\x1F\x7F]+/', '', $safe);
        $safe = basename(str_replace('\\', '/', $safe));
        if ($safe === '') {
            $safe = 'attachment';
        }
        $ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, $blocked, true)) {
            cmResponse(422, false, 'The attachment type .' . $ext . ' is not allowed.');
        }
        $tmp = isset($tmpNames[$i]) ? (string) $tmpNames[$i] : '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            cmResponse(422, false, 'One of the email attachments is invalid.');
        }
        $mime = isset($types[$i]) ? trim((string) $types[$i]) : '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detected = finfo_file($fi, $tmp);
                finfo_close($fi);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
        }
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $out[] = array('name' => $safe, 'tmp_name' => $tmp, 'size' => $size, 'type' => $mime);
    }
    return $out;
}

function cmSmtpSendEmail(array $config, $password, $to, $cc, $subject, $message, array $attachments)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Client email is invalid.');
    }
    $host = trim((string) $config['host']);
    $port = (int) $config['port'];
    $enc = strtolower(trim((string) $config['encryption']));
    $user = trim((string) $config['username']);
    $from = trim((string) $config['from_email']);
    $fromName = trim((string) $config['from_name']);
    $reply = trim((string) $config['reply_to_email']);
    if ($host === '' || $port <= 0) {
        throw new RuntimeException('SMTP host or port is not configured.');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP From Email is invalid.');
    }
    if ($user !== '' && $password === '') {
        throw new RuntimeException('SMTP password is empty or could not be decrypted.');
    }

    $recipients = array($to);
    if ($cc !== '' && filter_var($cc, FILTER_VALIDATE_EMAIL) && strcasecmp($cc, $to) !== 0) {
        $recipients[] = $cc;
    }

    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(array('ssl' => array(
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'peer_name' => $host
    )));
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        throw new RuntimeException('Unable to connect to SMTP server: ' . ($errstr !== '' ? $errstr : 'connection failed') . '.');
    }
    stream_set_timeout($socket, 20);
    try {
        cmSmtpCmd($socket, null, array(220), 'SMTP greeting');
        $ehlo = !empty($_SERVER['SERVER_NAME']) ? preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME']) : 'fieldplx.local';
        if ($ehlo === '') {
            $ehlo = 'fieldplx.local';
        }
        cmSmtpCmd($socket, 'EHLO ' . $ehlo, array(250), 'EHLO');
        if ($enc === 'tls' || $enc === 'starttls') {
            cmSmtpCmd($socket, 'STARTTLS', array(220), 'STARTTLS');
            $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (@stream_socket_enable_crypto($socket, true, $method) !== true) {
                throw new RuntimeException('Unable to establish TLS encryption.');
            }
            cmSmtpCmd($socket, 'EHLO ' . $ehlo, array(250), 'EHLO after TLS');
        }
        if ($user !== '') {
            cmSmtpCmd($socket, 'AUTH LOGIN', array(334), 'SMTP authentication');
            cmSmtpCmd($socket, base64_encode($user), array(334), 'SMTP username');
            cmSmtpCmd($socket, base64_encode($password), array(235), 'SMTP password');
        }
        cmSmtpCmd($socket, 'MAIL FROM:<' . $from . '>', array(250), 'MAIL FROM');
        foreach ($recipients as $recipient) {
            cmSmtpCmd($socket, 'RCPT TO:<' . $recipient . '>', array(250, 251), 'RCPT TO');
        }
        cmSmtpCmd($socket, 'DATA', array(354), 'DATA');

        $boundary = '=_FieldPlx_' . bin2hex(random_bytes(12));
        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . cmSmtpHeader($fromName !== '' ? $fromName : 'FieldPlx') . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"'
        );
        if ($cc !== '' && filter_var($cc, FILTER_VALIDATE_EMAIL) && strcasecmp($cc, $to) !== 0) {
            $headers[] = 'Cc: <' . $cc . '>';
        }
        if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: <' . $reply . '>';
        }

        $parts = array();
        $parts[] = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($message), 76, "\r\n");

        foreach ($attachments as $attachment) {
            $data = @file_get_contents($attachment['tmp_name']);
            if ($data === false) {
                throw new RuntimeException('Unable to read attachment ' . $attachment['name'] . '.');
            }
            $filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', $attachment['name']);
            $parts[] = '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $attachment['type'] . '; name="' . cmSmtpHeader($filename) . "\"\r\n"
                . 'Content-Disposition: attachment; filename="' . cmSmtpHeader($filename) . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($data), 76, "\r\n");
        }
        $parts[] = '--' . $boundary . '--';
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n";
        $payload = preg_replace('/(?m)^\./', '..', $payload);
        @fwrite($socket, $payload . ".\r\n");
        cmSmtpCmd($socket, null, array(250), 'Message delivery');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }
    return true;
}

$tenantId = isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : 0;
$userId = isset($_SESSION['tenant_user_id']) ? (int) $_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0);
$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : 0;

if ($tenantId <= 0 || $userId <= 0) {
    cmResponse(401, false, 'Authentication required.');
}

$csrf = (string) cmPost('csrf_token', '');
$sessionCsrf = isset($_SESSION['clients_csrf_token']) ? (string) $_SESSION['clients_csrf_token'] : '';
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    cmResponse(419, false, 'Your form session expired. Refresh the page and try again.');
}

$action = trim((string) cmPost('action', ''));

try {
    if ($action === 'list') {
        $page = max(1, (int) cmPost('page', 1));
        $perPage = (int) cmPost('per_page', 25);
        if (!in_array($perPage, array(10, 25, 50, 100), true)) {
            $perPage = 25;
        }
        $search = trim((string) cmPost('search', ''));
        $scope = trim((string) cmPost('status_scope', 'leads_active'));
        $tagId = max(0, (int) cmPost('tag_id', 0));
        $sort = trim((string) cmPost('sort', 'name'));
        $direction = strtolower(trim((string) cmPost('direction', 'asc'))) === 'desc' ? 'DESC' : 'ASC';

        $params = array();
        $where = cmBuildFilter($pdo, $tenantId, $search, $scope, $tagId, $params);

        $count = $pdo->prepare("SELECT COUNT(*) FROM clients c WHERE $where");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $orderBy = $sort === 'last_activity'
            ? "COALESCE(c.last_activity_at, c.updated_at, c.created_at) $direction, c.id DESC"
            : "c.display_name $direction, c.id $direction";

        $locationSelect = cmTableExists($pdo, 'client_locations')
            ? ", cl.address_line1, cl.address_line2, cl.city, cl.state, cl.postal_code"
            : ", NULL AS address_line1, NULL AS address_line2, NULL AS city, NULL AS state, NULL AS postal_code";

        $locationJoin = cmTableExists($pdo, 'client_locations')
            ? "LEFT JOIN client_locations cl ON cl.id = (
                SELECT cl2.id FROM client_locations cl2
                WHERE cl2.tenant_id = c.tenant_id AND cl2.client_id = c.id AND cl2.deleted_at IS NULL
                ORDER BY cl2.is_primary DESC, cl2.id ASC LIMIT 1
            )"
            : '';

        $sql = "SELECT c.id, c.client_type, c.display_name, c.company_name, c.first_name, c.last_name,
                       c.email, c.phone, c.status, c.last_activity_at, c.updated_at, c.created_at
                       $locationSelect
                FROM clients c
                $locationJoin
                WHERE $where
                ORDER BY $orderBy
                LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;

        $q = $pdo->prepare($sql);
        $q->execute($params);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        cmAttachTags($pdo, $tenantId, $rows);

        cmResponse(200, true, 'Clients loaded.', array(
            'clients' => $rows,
            'tags' => cmTags($pdo, $tenantId),
            'stats' => cmStats($pdo, $tenantId),
            'pagination' => array(
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => $total > 0 ? min($offset + count($rows), $total) : 0
            )
        ));
    }

    if ($action === 'save_tags') {
        cmRequireTagTables($pdo);
        $clientId = (int) cmPost('client_id', 0);
        if ($clientId <= 0) {
            cmResponse(422, false, 'Invalid client.');
        }
        $client = cmClient($pdo, $tenantId, $clientId);
        $raw = cmPost('tag_ids', '[]');
        $ids = json_decode((string) $raw, true);
        if (!is_array($ids)) {
            cmResponse(422, false, 'Invalid tag selection.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) {
            return $v > 0;
        })));

        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare("SELECT id FROM client_tags WHERE tenant_id = ? AND is_active = 1 AND id IN ($ph)");
            $q->execute(array_merge(array($tenantId), $ids));
            $valid = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            sort($valid);
            $check = $ids;
            sort($check);
            if ($valid !== $check) {
                cmResponse(422, false, 'One or more selected tags are invalid.');
            }
        }

        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("DELETE FROM client_tag_assignments WHERE tenant_id = :t AND client_id = :c");
            $q->execute(array(':t' => $tenantId, ':c' => $clientId));
            if ($ids) {
                $insert = $pdo->prepare("INSERT INTO client_tag_assignments (tenant_id, client_id, tag_id, created_by) VALUES (:t, :c, :tag, :u)");
                foreach ($ids as $tagId) {
                    $insert->execute(array(':t' => $tenantId, ':c' => $clientId, ':tag' => $tagId, ':u' => $userId));
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        cmAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_TAGS_UPDATED', $clientId, null, array('tag_ids' => $ids));
        cmResponse(200, true, 'Client tags updated successfully.', array('client_id' => $clientId, 'display_name' => $client['display_name']));
    }


    if ($action === 'bulk_add_tags') {
        cmRequireTagTables($pdo);
        $clientIds = cmJsonIds(cmPost('client_ids', '[]'), 'client');
        $tagIds = cmJsonIds(cmPost('tag_ids', '[]'), 'tag');

        $clientPh = implode(',', array_fill(0, count($clientIds), '?'));
        $q = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = ? AND deleted_at IS NULL AND id IN ($clientPh)");
        $q->execute(array_merge(array($tenantId), $clientIds));
        $validClients = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        sort($validClients);
        $checkClients = $clientIds;
        sort($checkClients);
        if ($validClients !== $checkClients) {
            cmResponse(422, false, 'One or more selected clients are invalid.');
        }

        $tagPh = implode(',', array_fill(0, count($tagIds), '?'));
        $q = $pdo->prepare("SELECT id FROM client_tags WHERE tenant_id = ? AND is_active = 1 AND id IN ($tagPh)");
        $q->execute(array_merge(array($tenantId), $tagIds));
        $validTags = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        sort($validTags);
        $checkTags = $tagIds;
        sort($checkTags);
        if ($validTags !== $checkTags) {
            cmResponse(422, false, 'One or more selected tags are invalid.');
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare("INSERT IGNORE INTO client_tag_assignments (tenant_id, client_id, tag_id, created_by) VALUES (:t, :c, :tag, :u)");
            foreach ($clientIds as $clientId) {
                foreach ($tagIds as $tagId) {
                    $insert->execute(array(':t' => $tenantId, ':c' => $clientId, ':tag' => $tagId, ':u' => $userId));
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($clientIds as $clientId) {
            cmAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_TAGS_BULK_ADDED', $clientId, null, array('tag_ids' => $tagIds));
        }
        cmResponse(200, true, 'Tags added to ' . count($clientIds) . ' clients successfully.');
    }

    if ($action === 'create_tag') {
        cmRequireTagTables($pdo);
        $name = trim((string) cmPost('name', ''));
        if ($name === '') {
            cmResponse(422, false, 'Enter a tag name.');
        }
        if (strlen($name) > 80) {
            cmResponse(422, false, 'Tag name must be 80 characters or less.');
        }
        $q = $pdo->prepare("SELECT id, name, COALESCE(color, '') AS color FROM client_tags WHERE tenant_id = :t AND LOWER(name) = LOWER(:n) LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':n' => $name));
        $existing = $q->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (cmColumnExists($pdo, 'client_tags', 'is_active')) {
                $pdo->prepare("UPDATE client_tags SET is_active = 1 WHERE id = :id AND tenant_id = :t")->execute(array(':id' => $existing['id'], ':t' => $tenantId));
            }
            cmResponse(200, true, 'Tag is ready to use.', array('tag' => $existing));
        }
        $q = $pdo->prepare("INSERT INTO client_tags (tenant_id, name, color, is_active, created_by) VALUES (:t, :n, NULL, 1, :u)");
        $q->execute(array(':t' => $tenantId, ':n' => $name, ':u' => $userId));
        $id = (int) $pdo->lastInsertId();
        cmResponse(200, true, 'Tag created successfully.', array('tag' => array('id' => $id, 'name' => $name, 'color' => '')));
    }

    if ($action === 'archive') {
        $clientId = (int) cmPost('client_id', 0);
        $old = cmClient($pdo, $tenantId, $clientId);
        $q = $pdo->prepare("UPDATE clients SET client_type = 'archived', status = 'archived' WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL");
        $q->execute(array(':id' => $clientId, ':t' => $tenantId));
        cmAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_ARCHIVED', $clientId, $old, array('client_type' => 'archived', 'status' => 'archived'));
        cmResponse(200, true, 'Client archived successfully.');
    }

    if ($action === 'delete') {
        $clientId = (int) cmPost('client_id', 0);
        $old = cmClient($pdo, $tenantId, $clientId);
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("UPDATE clients SET client_type = 'archived', status = 'archived', deleted_at = NOW() WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL");
            $q->execute(array(':id' => $clientId, ':t' => $tenantId));
            if (cmTableExists($pdo, 'client_portal_users')) {
                $q = $pdo->prepare("UPDATE client_portal_users SET status = 'inactive' WHERE tenant_id = :t AND client_id = :c");
                $q->execute(array(':t' => $tenantId, ':c' => $clientId));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        cmAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_DELETED', $clientId, $old, array('deleted_at' => date('Y-m-d H:i:s')));
        cmResponse(200, true, 'Client deleted successfully.');
    }


    if ($action === 'bulk_delete') {
        $clientIds = cmJsonIds(cmPost('client_ids', '[]'), 'client');
        $ph = implode(',', array_fill(0, count($clientIds), '?'));
        $q = $pdo->prepare("SELECT * FROM clients WHERE tenant_id = ? AND deleted_at IS NULL AND id IN ($ph)");
        $q->execute(array_merge(array($tenantId), $clientIds));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($clientIds)) {
            cmResponse(422, false, 'One or more selected clients are invalid.');
        }

        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("UPDATE clients SET client_type = 'archived', status = 'archived', deleted_at = NOW() WHERE tenant_id = ? AND deleted_at IS NULL AND id IN ($ph)");
            $q->execute(array_merge(array($tenantId), $clientIds));
            if (cmTableExists($pdo, 'client_portal_users')) {
                $q = $pdo->prepare("UPDATE client_portal_users SET status = 'inactive' WHERE tenant_id = ? AND client_id IN ($ph)");
                $q->execute(array_merge(array($tenantId), $clientIds));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($rows as $old) {
            cmAudit($pdo, $tenantId, $branchId, $userId, 'CLIENT_DELETED', (int) $old['id'], $old, array('deleted_at' => date('Y-m-d H:i:s'), 'bulk' => true));
        }
        cmResponse(200, true, count($rows) . ' clients deleted successfully.');
    }

    if ($action === 'send_email') {
        $clientId = (int) cmPost('client_id', 0);
        if ($clientId <= 0) {
            cmResponse(422, false, 'Invalid client.');
        }
        $client = cmClient($pdo, $tenantId, $clientId);
        $to = trim((string) ($client['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            cmResponse(422, false, 'This client does not have a valid email address.');
        }
        if (array_key_exists('allow_email', $client) && (int) $client['allow_email'] !== 1) {
            cmResponse(403, false, 'Email communication is disabled for this client.');
        }

        $subject = trim((string) cmPost('subject', ''));
        if ($subject === '') {
            $subject = 'Message from FieldPlx';
        }
        if (strlen($subject) > 250) {
            cmResponse(422, false, 'Email subject must be 250 characters or less.');
        }
        $message = (string) cmPost('message', '');
        if (strlen($message) > 20000) {
            cmResponse(422, false, 'Email message is too long.');
        }
        if (trim($message) === '') {
            $message = 'Hello ' . trim((string) ($client['display_name'] ?? '')) . ',' . "\n\n" . 'This message was sent from FieldPlx.';
        }

        $clientBranchId = isset($client['branch_id']) ? (int) $client['branch_id'] : 0;
        $smtpBranchId = $clientBranchId > 0 ? $clientBranchId : $branchId;
        $config = cmSmtpConfig($pdo, $tenantId, $smtpBranchId);
        if (!$config) {
            cmResponse(422, false, 'Tenant SMTP is not configured or active.');
        }
        $password = cmSmtpDecrypt(isset($config['password_encrypted']) ? $config['password_encrypted'] : '');
        $attachments = cmEmailAttachments();
        $cc = ((string) cmPost('send_copy', '0') === '1') ? cmCurrentUserEmail($pdo, $tenantId, $userId) : '';
        if ((string) cmPost('send_copy', '0') === '1' && $cc === '') {
            cmResponse(422, false, 'Your user account does not have a valid email address for Send me a copy.');
        }

        cmSmtpSendEmail($config, $password, $to, $cc, $subject, $message, $attachments);
        cmAudit($pdo, $tenantId, $smtpBranchId, $userId, 'CLIENT_EMAIL_SENT', $clientId, null, array(
            'to' => $to,
            'subject' => $subject,
            'send_copy' => $cc !== '',
            'attachment_count' => count($attachments)
        ));
        cmResponse(200, true, 'Email sent to ' . (string) ($client['display_name'] ?? 'client') . ' successfully.');
    }

    if ($action === 'export') {
        $search = trim((string) cmPost('search', ''));
        $scope = trim((string) cmPost('status_scope', 'leads_active'));
        $tagId = max(0, (int) cmPost('tag_id', 0));
        $params = array();
        $where = cmBuildFilter($pdo, $tenantId, $search, $scope, $tagId, $params);

        $locationSelect = cmTableExists($pdo, 'client_locations')
            ? ", cl.address_line1, cl.address_line2, cl.city, cl.state, cl.postal_code"
            : ", NULL AS address_line1, NULL AS address_line2, NULL AS city, NULL AS state, NULL AS postal_code";
        $locationJoin = cmTableExists($pdo, 'client_locations')
            ? "LEFT JOIN client_locations cl ON cl.id = (
                SELECT cl2.id FROM client_locations cl2
                WHERE cl2.tenant_id = c.tenant_id AND cl2.client_id = c.id AND cl2.deleted_at IS NULL
                ORDER BY cl2.is_primary DESC, cl2.id ASC LIMIT 1
            )"
            : '';

        $q = $pdo->prepare("SELECT c.id, c.display_name, c.company_name, c.email, c.phone, c.client_type, c.status,
                                   COALESCE(c.last_activity_at, c.updated_at, c.created_at) AS last_activity
                                   $locationSelect
                            FROM clients c
                            $locationJoin
                            WHERE $where
                            ORDER BY c.display_name ASC");
        $q->execute($params);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        cmAttachTags($pdo, $tenantId, $rows);
        foreach ($rows as &$row) {
            $tagNames = array();
            foreach ($row['tags'] as $tag) {
                $tagNames[] = $tag['name'];
            }
            $row['tags_text'] = implode(', ', $tagNames);
        }
        unset($row);
        cmAudit($pdo, $tenantId, $branchId, $userId, 'CLIENTS_EXPORTED', 0, null, array('row_count' => count($rows)));
        cmResponse(200, true, count($rows) . ' client rows exported successfully.', array('clients' => $rows));
    }

    cmResponse(400, false, 'Unsupported client management action.');
} catch (PDOException $e) {
    error_log('FieldPlx client manage PDO error: ' . $e->getMessage());
    if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
        cmResponse(409, false, 'That value already exists.');
    }
    cmResponse(500, false, 'Unable to process the client management request.');
} catch (Throwable $e) {
    error_log('FieldPlx client manage error: ' . $e->getMessage());
    cmResponse(500, false, $e->getMessage());
}
