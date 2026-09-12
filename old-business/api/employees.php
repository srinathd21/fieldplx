<?php
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}
function er($s, $ok, $m, $x = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int) $s);
    echo json_encode(array_merge(array('success' => (bool) $ok, 'message' => (string) $m), $x), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ep($k, $d = '')
{
    return isset($_POST[$k]) ? $_POST[$k] : $d;
}
function ej($v)
{
    $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $j === false ? null : $j;
}
function eget(PDO $pdo, $tid, $id)
{
    $q = $pdo->prepare("SELECT id,tenant_id,branch_id,department_id,role_id,employee_code,first_name,last_name,email,phone,alternate_phone,avatar_path,job_title,labor_rate,is_bookable,is_field_worker,is_tenant_admin,status,last_login_at,created_at,updated_at FROM users WHERE id=:id AND tenant_id=:tid AND deleted_at IS NULL LIMIT 1");
    $q->execute(array(':id' => (int) $id, ':tid' => (int) $tid));
    $r = $q->fetch();
    if (!$r)
        er(404, false, 'Employee not found.');
    return $r;
}
function emeta(PDO $pdo, $tid)
{
    $b = $pdo->prepare("SELECT id,name,branch_code FROM branches WHERE tenant_id=:tid AND status='active' ORDER BY is_head_office DESC,name");
    $b->execute(array(':tid' => $tid));
    $d = $pdo->prepare("SELECT id,branch_id,name,code FROM departments WHERE tenant_id=:tid AND status='active' ORDER BY name");
    $d->execute(array(':tid' => $tid));
    $r = $pdo->prepare("SELECT id,name,code,is_admin FROM roles WHERE tenant_id=:tid AND status='active' ORDER BY is_admin DESC,name");
    $r->execute(array(':tid' => $tid));
    return array('branches' => $b->fetchAll(), 'departments' => $d->fetchAll(), 'roles' => $r->fetchAll());
}
function efk(PDO $pdo, $table, $tid, $id)
{
    if ($id <= 0)
        return null;
    if (!in_array($table, array('branches', 'departments', 'roles'), true))
        return null;
    $q = $pdo->prepare("SELECT id FROM $table WHERE id=:id AND tenant_id=:tid LIMIT 1");
    $q->execute(array(':id' => $id, ':tid' => $tid));
    return $q->fetchColumn() ? $id : null;
}
function elog(PDO $pdo, $tid, $bid, $uid, $event, $id, $title, $details, $audit, $old, $new)
{
    try {
        $q = $pdo->prepare("INSERT INTO activity_events(tenant_id,branch_id,actor_user_id,actor_type,event_type,related_type,related_id,title,details_json,visible_to_client) VALUES(:tid,:bid,:uid,'user',:event,'employee',:rid,:title,:details,0)");
        $q->execute(array(':tid' => $tid, ':bid' => $bid ?: null, ':uid' => $uid, ':event' => $event, ':rid' => $id, ':title' => substr($title, 0, 255), ':details' => ej($details)));
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
    try {
        if (function_exists('tenantAuditLog')) {
            tenantAuditLog($pdo, $audit, $tid, $bid, $uid, 'employee', $id, $old, $new);
        } else {
            $q = $pdo->prepare("INSERT INTO audit_logs(tenant_id,branch_id,user_id,platform_user_id,action,object_type,object_id,old_values,new_values,ip_address,device_type,user_agent) VALUES(:tid,:bid,:uid,NULL,:action,'employee',:oid,:old,:new,:ip,'unknown',:ua)");
            $q->execute(array(':tid' => $tid, ':bid' => $bid ?: null, ':uid' => $uid, ':action' => $audit, ':oid' => $id, ':old' => ej($old), ':new' => ej($new), ':ip' => isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 80) : null, ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null));
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}


/*
 * --------------------------------------------------------------------------
 * Employee welcome email helpers
 * --------------------------------------------------------------------------
 * Reuses the same encrypted SMTP configuration format as Master Controls.
 * New employee creation remains successful even if email delivery fails.
 */
function employeeMailLoadSmtpSecretFile()
{
    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        return true;
    }

    $candidates = array(
        __DIR__ . '/../includes/smtp-secret.php',
        __DIR__ . '/../../includes/smtp-secret.php'
    );

    foreach ($candidates as $file) {
        if (is_file($file)) {
            try {
                require_once $file;
            } catch (Throwable $e) {
                error_log('Employee SMTP secret loader: ' . $e->getMessage());
                continue;
            }

            if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
                return true;
            }
        }
    }

    return false;
}

function employeeMailSmtpSecretKey()
{
    $key = '';
    employeeMailLoadSmtpSecretFile();

    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $key = trim((string) FIELDPLX_SMTP_ENCRYPTION_KEY);
    }

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

    if ($key === '' || $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY') {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.');
    }

    if (strlen($key) < 32) {
        throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.');
    }

    return hash('sha256', $key, true);
}

function employeeMailDecryptPassword($encrypted, $tenantId)
{
    $encrypted = trim((string) $encrypted);

    if ($encrypted === '') {
        return '';
    }

    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL extension is required for SMTP password decryption.');
    }

    if (strpos($encrypted, 'v1:') !== 0) {
        throw new RuntimeException('The saved SMTP password uses an old encryption format. Re-enter and save the SMTP password in Master Controls.');
    }

    $raw = base64_decode(substr($encrypted, 3), true);
    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException('The stored SMTP password is invalid.');
    }

    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        employeeMailSmtpSecretKey(),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($plain === false) {
        throw new RuntimeException('Unable to decrypt the SMTP password. Confirm the server uses the same SMTP encryption key used when the configuration was saved.');
    }

    return $plain;
}

function employeeMailRead($socket)
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

function employeeMailCode($response)
{
    return (int) substr((string) $response, 0, 3);
}

function employeeMailCommand($socket, $command, $expected, $label)
{
    if ($command !== null) {
        if (@fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('SMTP connection closed while sending ' . $label . '.');
        }
    }

    $response = employeeMailRead($socket);
    $code = employeeMailCode($response);
    $expected = (array) $expected;

    if (!in_array($code, $expected, true)) {
        $safe = preg_replace('/[\r\n]+/', ' ', (string) $response);
        throw new RuntimeException($label . ' failed (SMTP ' . $code . '): ' . substr($safe, 0, 350));
    }

    return $response;
}

function employeeMailHeaderValue($value)
{
    return trim(str_replace(array("\r", "\n"), ' ', (string) $value));
}

function employeeMailEncodedHeader($value)
{
    $value = employeeMailHeaderValue($value);
    return $value === '' ? '' : '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function employeeMailEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function employeeMailFindConfig(PDO $pdo, $tenantId, $branchId)
{
    if ((int) $branchId > 0) {
        $stmt = $pdo->prepare(
            "SELECT id,scope_type,tenant_id,branch_id,config_name,host,port,encryption,username,password_encrypted,from_name,from_email,reply_to_email,is_default,is_active
             FROM smtp_configurations
             WHERE tenant_id = :tenant_id
               AND is_active = 1
               AND (
                    (scope_type = 'branch' AND branch_id = :branch_filter)
                    OR scope_type = 'tenant'
               )
             ORDER BY
                CASE
                    WHEN scope_type = 'branch' AND branch_id = :branch_order THEN 0
                    WHEN scope_type = 'tenant' THEN 1
                    ELSE 2
                END,
                is_default DESC,
                id DESC
             LIMIT 1"
        );
        $stmt->execute(array(
            ':tenant_id' => (int) $tenantId,
            ':branch_filter' => (int) $branchId,
            ':branch_order' => (int) $branchId
        ));
    } else {
        $stmt = $pdo->prepare(
            "SELECT id,scope_type,tenant_id,branch_id,config_name,host,port,encryption,username,password_encrypted,from_name,from_email,reply_to_email,is_default,is_active
             FROM smtp_configurations
             WHERE tenant_id = :tenant_id
               AND is_active = 1
               AND scope_type = 'tenant'
             ORDER BY is_default DESC,id DESC
             LIMIT 1"
        );
        $stmt->execute(array(':tenant_id' => (int) $tenantId));
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function employeeMailContext(PDO $pdo, $tenantId, $employeeId)
{
    $stmt = $pdo->prepare(
        "SELECT
            u.id,
            u.branch_id,
            u.employee_code,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            u.job_title,
            u.status,
            b.name AS branch_name,
            d.name AS department_name,
            r.name AS role_name,
            t.display_name AS tenant_display_name,
            t.legal_name AS tenant_legal_name
         FROM users u
         INNER JOIN tenants t
                 ON t.id = u.tenant_id
                AND t.deleted_at IS NULL
         LEFT JOIN branches b
                ON b.id = u.branch_id
               AND b.tenant_id = u.tenant_id
         LEFT JOIN departments d
                ON d.id = u.department_id
               AND d.tenant_id = u.tenant_id
         LEFT JOIN roles r
                ON r.id = u.role_id
               AND r.tenant_id = u.tenant_id
         WHERE u.id = :employee_id
           AND u.tenant_id = :tenant_id
           AND u.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute(array(
        ':employee_id' => (int) $employeeId,
        ':tenant_id' => (int) $tenantId
    ));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Employee email details are not available.');
    }

    return $row;
}

function employeeMailSendWelcome(array $config, $password, array $employee)
{
    $host = trim((string) $config['host']);
    $port = (int) $config['port'];
    $encryption = strtolower(trim((string) $config['encryption']));
    $username = trim((string) $config['username']);
    $fromEmail = trim((string) $config['from_email']);
    $fromName = trim((string) $config['from_name']);
    $replyTo = trim((string) $config['reply_to_email']);
    $recipient = trim((string) $employee['email']);

    if ($host === '') {
        throw new RuntimeException('SMTP host is empty.');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('SMTP port is invalid.');
    }
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Employee email address is invalid.');
    }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP From Email must be a valid email address.');
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $context = stream_context_create(array(
        'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host
        )
    ));

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException('Unable to connect to SMTP server: ' . ($errstr !== '' ? $errstr : 'connection failed') . ' (' . $errno . ').');
    }

    stream_set_timeout($socket, 20);

    try {
        employeeMailCommand($socket, null, array(220), 'SMTP greeting');

        $ehloHost = isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== ''
            ? preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME'])
            : 'fieldplx.local';
        if ($ehloHost === '') {
            $ehloHost = 'fieldplx.local';
        }

        employeeMailCommand($socket, 'EHLO ' . $ehloHost, array(250), 'EHLO');

        if ($encryption === 'tls' || $encryption === 'starttls') {
            employeeMailCommand($socket, 'STARTTLS', array(220), 'STARTTLS');
            $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ? STREAM_CRYPTO_METHOD_TLS_CLIENT
                : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            $crypto = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
            if ($crypto !== true) {
                throw new RuntimeException('Unable to establish TLS encryption with the SMTP server.');
            }
            employeeMailCommand($socket, 'EHLO ' . $ehloHost, array(250), 'EHLO after TLS');
        }

        if ($username !== '') {
            if ($password === '') {
                throw new RuntimeException('SMTP password is empty.');
            }
            employeeMailCommand($socket, 'AUTH LOGIN', array(334), 'SMTP authentication');
            employeeMailCommand($socket, base64_encode($username), array(334), 'SMTP username');
            employeeMailCommand($socket, base64_encode($password), array(235), 'SMTP password');
        }

        employeeMailCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', array(250), 'MAIL FROM');
        employeeMailCommand($socket, 'RCPT TO:<' . $recipient . '>', array(250, 251), 'RCPT TO');
        employeeMailCommand($socket, 'DATA', array(354), 'DATA');

        $tenantName = trim((string) $employee['tenant_display_name']);
        if ($tenantName === '') {
            $tenantName = trim((string) $employee['tenant_legal_name']);
        }
        if ($tenantName === '') {
            $tenantName = 'FieldPlx';
        }

        $employeeName = trim((string) $employee['first_name'] . ' ' . (string) $employee['last_name']);
        if ($employeeName === '') {
            $employeeName = 'Employee';
        }

        $subject = $tenantName . ' - Employee Account Created';
        $displayName = $fromName !== '' ? $fromName : $tenantName;
        $messageIdHost = preg_replace('/[^A-Za-z0-9.\-]/', '', $host);
        if ($messageIdHost === '') {
            $messageIdHost = 'fieldplx.local';
        }

        $rows = array(
            array('Employee Code', trim((string) $employee['employee_code']) !== '' ? $employee['employee_code'] : '-'),
            array('Registered Email', $employee['email']),
            array('Branch', trim((string) $employee['branch_name']) !== '' ? $employee['branch_name'] : '-'),
            array('Department', trim((string) $employee['department_name']) !== '' ? $employee['department_name'] : '-'),
            array('Role', trim((string) $employee['role_name']) !== '' ? $employee['role_name'] : '-'),
            array('Job Title', trim((string) $employee['job_title']) !== '' ? $employee['job_title'] : '-'),
            array('Account Status', ucfirst((string) $employee['status']))
        );

        $detailRows = '';
        foreach ($rows as $row) {
            $detailRows .= '<tr>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #e8edf3;color:#6f7b90;font-size:12px;width:38%">' . employeeMailEscape($row[0]) . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #e8edf3;color:#0b1933;font-size:12px">' . employeeMailEscape($row[1]) . '</td>'
                . '</tr>';
        }

        $body = '<!doctype html><html><body style="margin:0;padding:0;background:#f6f8fb;font-family:Arial,Helvetica,sans-serif;color:#0b1933">'
            . '<div style="padding:28px 14px">'
            . '<div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e5eaf1;border-radius:12px;overflow:hidden">'
            . '<div style="padding:22px 24px;background:#001131;color:#ffffff">'
            . '<div style="font-size:20px;font-weight:700">Welcome to ' . employeeMailEscape($tenantName) . '</div>'
            . '<div style="margin-top:5px;font-size:12px;color:#cbd5e1">Your FieldPlx employee account has been created.</div>'
            . '</div>'
            . '<div style="padding:24px">'
            . '<p style="margin:0 0 12px;font-size:14px">Hello ' . employeeMailEscape($employeeName) . ',</p>'
            . '<p style="margin:0 0 18px;color:#506784;font-size:13px;line-height:1.65">Your employee account has been created successfully. Your registered account details are shown below.</p>'
            . '<table style="width:100%;border-collapse:collapse;border:1px solid #e5eaf1;border-radius:8px;overflow:hidden">' . $detailRows . '</table>'
            . '<div style="margin-top:18px;padding:12px 14px;border-radius:8px;background:#f0f8e5;color:#385d12;font-size:12px;line-height:1.6">For security, your password is not included in this email. Use the password provided by your administrator to sign in.</div>'
            . '<p style="margin:20px 0 0;color:#7d899d;font-size:11px;line-height:1.6">If you were not expecting this account, contact your administrator.</p>'
            . '</div>'
            . '<div style="padding:14px 24px;border-top:1px solid #e5eaf1;color:#8a96a7;font-size:10px">Sent automatically by FieldPlx for ' . employeeMailEscape($tenantName) . '.</div>'
            . '</div></div></body></html>';

        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . employeeMailEncodedHeader($displayName) . ' <' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . employeeMailEncodedHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $messageIdHost . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        );

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $payload = preg_replace('/(?m)^\./', '..', $payload);

        if (@fwrite($socket, $payload . "\r\n.\r\n") === false) {
            throw new RuntimeException('Unable to send SMTP message data.');
        }

        employeeMailCommand($socket, null, array(250), 'Message delivery');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }

    return true;
}

$tid = (int) ($_SESSION['tenant_id'] ?? 0);
$uid = (int) ($_SESSION['tenant_user_id'] ?? 0);
$bid = (int) ($_SESSION['branch_id'] ?? 0);
if ($tid <= 0 || $uid <= 0)
    er(401, false, 'Authentication required.');
$csrf = (string) ep('csrf_token', '');
$sess = (string) ($_SESSION['employees_csrf_token'] ?? '');
if ($csrf === '' || $sess === '' || !hash_equals($sess, $csrf))
    er(419, false, 'Your form session expired. Refresh the page and try again.');
$a = trim((string) ep('action', ''));
try {
    if ($a === 'list') {
        $page = max(1, (int) ep('page', 1));
        $pp = (int) ep('per_page', 10);
        if (!in_array($pp, array(10, 25, 50), true))
            $pp = 10;
        $search = trim((string) ep('search', ''));
        $status = trim((string) ep('status', ''));
        $bf = (int) ep('branch_id', 0);
        $rf = (int) ep('role_id', 0);
        $w = array('u.tenant_id=:tid', 'u.deleted_at IS NULL');
        $p = array(':tid' => $tid);
        if ($search !== '') {
            $v = '%' . $search . '%';
            $w[] = '(u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3 OR u.phone LIKE :s4 OR u.employee_code LIKE :s5 OR u.job_title LIKE :s6)';
            for ($i = 1; $i <= 6; $i++)
                $p[':s' . $i] = $v;
        }
        if (in_array($status, array('active', 'inactive', 'invited', 'suspended'), true)) {
            $w[] = 'u.status=:status';
            $p[':status'] = $status;
        }
        if ($bf > 0) {
            $w[] = 'u.branch_id=:bf';
            $p[':bf'] = $bf;
        }
        if ($rf > 0) {
            $w[] = 'u.role_id=:rf';
            $p[':rf'] = $rf;
        }
        $ws = implode(' AND ', $w);
        $c = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $ws");
        $c->execute($p);
        $total = (int) $c->fetchColumn();
        $pages = max(1, (int) ceil($total / $pp));
        if ($page > $pages)
            $page = $pages;
        $off = ($page - 1) * $pp;
        $sql = "SELECT u.id,u.employee_code,u.first_name,u.last_name,u.email,u.phone,u.avatar_path,u.job_title,u.is_bookable,u.is_field_worker,u.is_tenant_admin,u.status,u.last_login_at,b.name branch_name,d.name department_name,r.name role_name,CASE WHEN u.id=:current THEN 1 ELSE 0 END is_current_user FROM users u LEFT JOIN branches b ON b.id=u.branch_id AND b.tenant_id=u.tenant_id LEFT JOIN departments d ON d.id=u.department_id AND d.tenant_id=u.tenant_id LEFT JOIN roles r ON r.id=u.role_id AND r.tenant_id=u.tenant_id WHERE $ws ORDER BY u.is_tenant_admin DESC,u.first_name,u.last_name LIMIT $pp OFFSET $off";
        $lp = $p;
        $lp[':current'] = $uid;
        $q = $pdo->prepare($sql);
        $q->execute($lp);
        $rows = $q->fetchAll();
        $s = $pdo->prepare("SELECT COUNT(*) total,SUM(status='active') active,SUM(is_field_worker=1) field_workers,COUNT(DISTINCT branch_id) branches FROM users WHERE tenant_id=:tid AND deleted_at IS NULL");
        $s->execute(array(':tid' => $tid));
        $sum = $s->fetch();
        er(200, true, 'Employees loaded.', array('employees' => $rows, 'meta' => emeta($pdo, $tid), 'summary' => array('total' => (int) ($sum['total'] ?? 0), 'active' => (int) ($sum['active'] ?? 0), 'field_workers' => (int) ($sum['field_workers'] ?? 0), 'branches' => (int) ($sum['branches'] ?? 0)), 'pagination' => array('page' => $page, 'per_page' => $pp, 'total' => $total, 'pages' => $pages, 'from' => $total ? $off + 1 : 0, 'to' => $total ? min($off + count($rows), $total) : 0)));
    }
    if ($a === 'get') {
        $id = (int) ep('employee_id', 0);
        er(200, true, 'Employee loaded.', array('employee' => eget($pdo, $tid, $id), 'meta' => emeta($pdo, $tid)));
    }
    if ($a === 'save') {
        $id = (int) ep('employee_id', 0);
        $code = trim((string) ep('employee_code', ''));
        $fn = trim((string) ep('first_name', ''));
        $ln = trim((string) ep('last_name', ''));
        $email = strtolower(trim((string) ep('email', '')));
        $phone = trim((string) ep('phone', ''));
        $alt = trim((string) ep('alternate_phone', ''));
        $job = trim((string) ep('job_title', ''));
        $rate = trim((string) ep('labor_rate', ''));
        $pass = (string) ep('password', '');
        $status = trim((string) ep('status', 'active'));
        $branch = efk($pdo, 'branches', $tid, (int) ep('branch_id', 0));
        $dept = efk($pdo, 'departments', $tid, (int) ep('department_id', 0));
        $role = efk($pdo, 'roles', $tid, (int) ep('role_id', 0));
        $book = ep('is_bookable', '') !== '' ? 1 : 0;
        $field = ep('is_field_worker', '') !== '' ? 1 : 0;
        $admin = ep('is_tenant_admin', '') !== '' ? 1 : 0;
        if ($fn === '')
            er(422, false, 'First name is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            er(422, false, 'Enter a valid email address.');
        if (!in_array($status, array('active', 'inactive', 'invited', 'suspended'), true))
            er(422, false, 'Invalid employee status.');
        if ($id <= 0 && strlen($pass) < 8)
            er(422, false, 'Password must be at least 8 characters.');
        if ($id > 0 && $pass !== '' && strlen($pass) < 8)
            er(422, false, 'New password must be at least 8 characters.');
        if ($rate !== '' && !is_numeric($rate))
            er(422, false, 'Labor rate must be a valid number.');
        if ($id <= 0) {
            $max = (int) ($_SESSION['plan_max_users'] ?? 0);
            if ($max > 0) {
                $q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=:tid AND deleted_at IS NULL");
                $q->execute(array(':tid' => $tid));
                if ((int) $q->fetchColumn() >= $max)
                    er(409, false, 'Your current plan user limit has been reached.');
            }
        }
        $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:tid AND email=:email AND deleted_at IS NULL" . ($id > 0 ? ' AND id<>:id' : ''));
        $pp = array(':tid' => $tid, ':email' => $email);
        if ($id > 0)
            $pp[':id'] = $id;
        $q->execute($pp);
        if ($q->fetchColumn())
            er(409, false, 'This email address is already used by another employee.');
        if ($code !== '') {
            $q = $pdo->prepare("SELECT id FROM users WHERE tenant_id=:tid AND employee_code=:code AND deleted_at IS NULL" . ($id > 0 ? ' AND id<>:id' : ''));
            $pp = array(':tid' => $tid, ':code' => $code);
            if ($id > 0)
                $pp[':id'] = $id;
            $q->execute($pp);
            if ($q->fetchColumn())
                er(409, false, 'This employee code is already in use.');
        }
        $old = $id > 0 ? eget($pdo, $tid, $id) : null;
        $lr = $rate !== '' ? number_format(max(0, (float) $rate), 2, '.', '') : null;
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $sql = "UPDATE users SET branch_id=:branch,department_id=:dept,role_id=:role,employee_code=:code,first_name=:fn,last_name=:ln,email=:email,phone=:phone,alternate_phone=:alt,job_title=:job,labor_rate=:rate,is_bookable=:book,is_field_worker=:field,is_tenant_admin=:admin,status=:status";
                $par = array(':branch' => $branch, ':dept' => $dept, ':role' => $role, ':code' => $code !== '' ? $code : null, ':fn' => $fn, ':ln' => $ln !== '' ? $ln : null, ':email' => $email, ':phone' => $phone !== '' ? $phone : null, ':alt' => $alt !== '' ? $alt : null, ':job' => $job !== '' ? $job : null, ':rate' => $lr, ':book' => $book, ':field' => $field, ':admin' => $admin, ':status' => $status, ':id' => $id, ':tid' => $tid);
                if ($pass !== '') {
                    $sql .= ',password_hash=:ph';
                    $par[':ph'] = password_hash($pass, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id=:id AND tenant_id=:tid AND deleted_at IS NULL';
                $q = $pdo->prepare($sql);
                $q->execute($par);
            } else {
                $q = $pdo->prepare("INSERT INTO users(tenant_id,branch_id,department_id,role_id,employee_code,first_name,last_name,email,phone,alternate_phone,password_hash,job_title,labor_rate,is_bookable,is_field_worker,is_tenant_admin,status) VALUES(:tid,:branch,:dept,:role,:code,:fn,:ln,:email,:phone,:alt,:ph,:job,:rate,:book,:field,:admin,:status)");
                $q->execute(array(':tid' => $tid, ':branch' => $branch, ':dept' => $dept, ':role' => $role, ':code' => $code !== '' ? $code : null, ':fn' => $fn, ':ln' => $ln !== '' ? $ln : null, ':email' => $email, ':phone' => $phone !== '' ? $phone : null, ':alt' => $alt !== '' ? $alt : null, ':ph' => password_hash($pass, PASSWORD_DEFAULT), ':job' => $job !== '' ? $job : null, ':rate' => $lr, ':book' => $book, ':field' => $field, ':admin' => $admin, ':status' => $status));
                $id = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
            $new = eget($pdo, $tid, $id);
            elog($pdo, $tid, $bid, $uid, $old ? 'employee_updated' : 'employee_created', $id, ($old ? 'Employee updated: ' : 'Employee created: ') . $new['first_name'], array('employee' => $new), $old ? 'EMPLOYEE_UPDATED' : 'EMPLOYEE_CREATED', $old, $new);

            if ($old) {
                er(200, true, 'Employee updated successfully.', array('employee_id' => $id));
            }

            $emailSent = false;
            $emailMessage = '';
            $smtpConfigName = '';

            try {
                $mailEmployee = employeeMailContext($pdo, $tid, $id);
                $smtpConfig = employeeMailFindConfig($pdo, $tid, !empty($mailEmployee['branch_id']) ? (int) $mailEmployee['branch_id'] : 0);

                if (!$smtpConfig) {
                    throw new RuntimeException('No active branch or tenant SMTP configuration is available. Configure SMTP in Master Controls.');
                }

                $smtpConfigName = isset($smtpConfig['config_name']) ? (string) $smtpConfig['config_name'] : '';
                $smtpPassword = employeeMailDecryptPassword($smtpConfig['password_encrypted'], $tid);
                employeeMailSendWelcome($smtpConfig, $smtpPassword, $mailEmployee);
                $emailSent = true;
                $emailMessage = 'Welcome email sent successfully to ' . $email . '.';
            } catch (Throwable $mailError) {
                $emailSent = false;
                $emailMessage = substr($mailError->getMessage(), 0, 1000);
                error_log('FieldPlx employee welcome email failed for employee ' . $id . ': ' . $mailError->getMessage());
            }

            er(
                200,
                true,
                $emailSent
                    ? 'Employee created successfully. Welcome email sent to ' . $email . '.'
                    : 'Employee created successfully, but the welcome email could not be sent.',
                array(
                    'employee_id' => $id,
                    'email_sent' => $emailSent,
                    'email_to' => $email,
                    'email_message' => $emailMessage,
                    'smtp_configuration' => $smtpConfigName
                )
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            throw $e;
        }
    }
    if ($a === 'change_status') {
        $id = (int) ep('employee_id', 0);
        $status = trim((string) ep('status', ''));
        if ($id === $uid && $status !== 'active')
            er(409, false, 'You cannot disable your own logged-in account.');
        if (!in_array($status, array('active', 'inactive', 'invited', 'suspended'), true))
            er(422, false, 'Invalid employee status.');
        $old = eget($pdo, $tid, $id);
        $q = $pdo->prepare("UPDATE users SET status=:status WHERE id=:id AND tenant_id=:tid AND deleted_at IS NULL");
        $q->execute(array(':status' => $status, ':id' => $id, ':tid' => $tid));
        $new = eget($pdo, $tid, $id);
        elog($pdo, $tid, $bid, $uid, 'employee_status_changed', $id, 'Employee status changed: ' . $new['first_name'], array('old_status' => $old['status'], 'new_status' => $new['status']), 'EMPLOYEE_STATUS_CHANGED', $old, $new);
        er(200, true, 'Employee status updated successfully.');
    }
    if ($a === 'delete') {
        $id = (int) ep('employee_id', 0);
        if ($id === $uid)
            er(409, false, 'You cannot delete your own logged-in account.');
        $old = eget($pdo, $tid, $id);
        $q = $pdo->prepare("UPDATE users SET status='inactive',deleted_at=NOW() WHERE id=:id AND tenant_id=:tid AND deleted_at IS NULL");
        $q->execute(array(':id' => $id, ':tid' => $tid));
        elog($pdo, $tid, $bid, $uid, 'employee_deleted', $id, 'Employee deleted: ' . $old['first_name'], array('employee' => $old), 'EMPLOYEE_DELETED', $old, array('status' => 'inactive', 'deleted_at' => date('Y-m-d H:i:s')));
        er(200, true, 'Employee deleted successfully.');
    }
    er(400, false, 'Unsupported employees action.');
} catch (Throwable $e) {
    error_log('FieldPlx employees API error: ' . $e->getMessage());
    if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062)
        er(409, false, 'Employee email or employee code already exists.');
    er(500, false, 'Unable to process the employees request.');
}
