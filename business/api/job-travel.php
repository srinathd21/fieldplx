<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function jtOut($success, $message, array $extra = array(), $status = 200)
{
    http_response_code($status);
    echo json_encode(array_merge(array('success' => (bool)$success, 'message' => (string)$message), $extra));
    exit;
}

function jtDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('PDO database connection is not available.');
}

function jtTenantId()
{
    foreach (array('tenant_id', 'business_id') as $k) {
        if (!empty($_SESSION[$k])) return (int)$_SESSION[$k];
    }
    return 0;
}

function jtUserId()
{
    foreach (array('tenant_user_id', 'user_id', 'business_user_id', 'id') as $k) {
        if (!empty($_SESSION[$k])) return (int)$_SESSION[$k];
    }
    return 0;
}

function jtJobAccess(PDO $pdo, $tenantId, $userId, $jobId)
{
    $sql = "SELECT
                j.id,
                j.status,
                j.client_id,
                j.branch_id,
                j.job_no,
                j.title,
                j.start_date,
                j.end_date,
                c.display_name AS client_name,
                c.email AS client_email,
                c.allow_email AS client_allow_email,
                CONCAT(
                    COALESCE(u.first_name, ''),
                    CASE
                        WHEN u.last_name IS NOT NULL AND u.last_name <> ''
                        THEN CONCAT(' ', u.last_name)
                        ELSE ''
                    END
                ) AS technician_name,
                t.display_name AS tenant_name
            FROM jobs j
            INNER JOIN clients c
              ON c.id = j.client_id
             AND c.tenant_id = j.tenant_id
             AND c.deleted_at IS NULL
            LEFT JOIN users u
              ON u.id = :user_name
             AND u.tenant_id = j.tenant_id
             AND u.deleted_at IS NULL
            LEFT JOIN tenants t
              ON t.id = j.tenant_id
            WHERE j.id = :job_id
              AND j.tenant_id = :tenant_id
              AND j.deleted_at IS NULL
              AND (
                    EXISTS (
                        SELECT 1 FROM job_assignments ja
                        WHERE ja.job_id = j.id
                          AND ja.tenant_id = j.tenant_id
                          AND ja.user_id = :user_direct
                          AND ja.status <> 'removed'
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM job_assignments ja2
                        INNER JOIN team_members tm ON tm.team_id = ja2.team_id
                        WHERE ja2.job_id = j.id
                          AND ja2.tenant_id = j.tenant_id
                          AND tm.user_id = :user_team
                          AND ja2.status <> 'removed'
                    )
              )
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute(array(
        ':job_id' => $jobId,
        ':tenant_id' => $tenantId,
        ':user_name' => $userId,
        ':user_direct' => $userId,
        ':user_team' => $userId,
    ));
    return $st->fetch(PDO::FETCH_ASSOC);
}


function jtTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];

    $st = $pdo->prepare("SELECT COUNT(*)
                         FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = :table_name");
    $st->execute(array(':table_name' => $table));
    $cache[$table] = ((int)$st->fetchColumn() > 0);
    return $cache[$table];
}

function jtSmtpSecretKey()
{
    if (!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $secretFile = __DIR__ . '/../includes/smtp-secret.php';
        if (is_file($secretFile)) require_once $secretFile;
    }

    $key = defined('FIELDPLX_SMTP_ENCRYPTION_KEY')
        ? trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY)
        : '';

    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) $key = trim((string)$env);
    }

    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) $key = trim((string)$env);
    }

    if ($key === '' || strlen($key) < 32) {
        throw new RuntimeException('SMTP encryption key is not configured.');
    }

    return hash('sha256', $key, true);
}

function jtDecryptSmtpPassword($encrypted)
{
    $encrypted = trim((string)$encrypted);
    if ($encrypted === '') return '';

    if (strpos($encrypted, 'v1:') !== 0) {
        throw new RuntimeException('SMTP password uses an unsupported encryption format.');
    }

    $raw = base64_decode(substr($encrypted, 3), true);
    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException('Stored SMTP password is invalid.');
    }

    $plain = openssl_decrypt(
        substr($raw, 16),
        'AES-256-CBC',
        jtSmtpSecretKey(),
        OPENSSL_RAW_DATA,
        substr($raw, 0, 16)
    );

    if ($plain === false) {
        throw new RuntimeException('Unable to decrypt SMTP password.');
    }

    return $plain;
}

function jtSmtpRead($socket)
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

function jtSmtpCmd($socket, $cmd, $okCodes, $label)
{
    if ($cmd !== null && @fwrite($socket, $cmd . "\r\n") === false) {
        throw new RuntimeException('SMTP connection closed during ' . $label . '.');
    }

    $response = jtSmtpRead($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, (array)$okCodes, true)) {
        throw new RuntimeException($label . ' failed with SMTP code ' . $code . '.');
    }
    return $response;
}

function jtSmtpConfig(PDO $pdo, $tenantId, $branchId)
{
    if (!jtTable($pdo, 'smtp_configurations')) return null;

    $st = $pdo->prepare("SELECT *
                         FROM smtp_configurations
                         WHERE tenant_id = :tenant_id
                           AND is_active = 1
                           AND scope_type IN ('tenant','branch')
                           AND (
                                scope_type = 'tenant'
                                OR (scope_type = 'branch' AND branch_id = :branch_id)
                           )
                         ORDER BY
                           CASE
                             WHEN scope_type = 'branch' AND branch_id = :branch_id_order THEN 0
                             ELSE 1
                           END,
                           is_default DESC,
                           id DESC
                         LIMIT 1");
    $branch = $branchId > 0 ? $branchId : -1;
    $st->execute(array(
        ':tenant_id' => $tenantId,
        ':branch_id' => $branch,
        ':branch_id_order' => $branch,
    ));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

function jtSendMail($cfg, $password, $to, $subject, $html)
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Customer email address is invalid.');
    }

    $host = trim((string)$cfg['host']);
    $port = (int)$cfg['port'];
    $encryption = strtolower(trim((string)$cfg['encryption']));
    $username = trim((string)$cfg['username']);
    $fromEmail = trim((string)$cfg['from_email']);
    $fromName = trim((string)$cfg['from_name']);

    if ($host === '' || $port <= 0 || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP configuration is incomplete.');
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $context = stream_context_create(array(
        'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
        )
    ));

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException('Unable to connect to the SMTP server.');
    stream_set_timeout($socket, 20);

    try {
        jtSmtpCmd($socket, null, array(220), 'SMTP greeting');
        jtSmtpCmd($socket, 'EHLO fieldplx.local', array(250), 'EHLO');

        if ($encryption === 'tls' || $encryption === 'starttls') {
            jtSmtpCmd($socket, 'STARTTLS', array(220), 'STARTTLS');
            $method = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ? STREAM_CRYPTO_METHOD_TLS_CLIENT
                : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if (@stream_socket_enable_crypto($socket, true, $method) !== true) {
                throw new RuntimeException('Unable to establish SMTP TLS encryption.');
            }
            jtSmtpCmd($socket, 'EHLO fieldplx.local', array(250), 'EHLO after TLS');
        }

        if ($username !== '') {
            jtSmtpCmd($socket, 'AUTH LOGIN', array(334), 'SMTP authentication');
            jtSmtpCmd($socket, base64_encode($username), array(334), 'SMTP username');
            jtSmtpCmd($socket, base64_encode($password), array(235), 'SMTP password');
        }

        jtSmtpCmd($socket, 'MAIL FROM:<' . $fromEmail . '>', array(250), 'MAIL FROM');
        jtSmtpCmd($socket, 'RCPT TO:<' . $to . '>', array(250, 251), 'RCPT TO');
        jtSmtpCmd($socket, 'DATA', array(354), 'DATA');

        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($fromName !== '' ? $fromName : 'FieldPlx') . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . str_replace(array("\r", "\n"), ' ', $subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        );

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $html;
        $payload = preg_replace('/(?m)^\./', '..', $payload);
        @fwrite($socket, $payload . "\r\n.\r\n");
        jtSmtpCmd($socket, null, array(250), 'Message delivery');
        @fwrite($socket, "QUIT\r\n");
    } finally {
        @fclose($socket);
    }

    return true;
}

function jtAbsoluteUrl($path)
{
    $path = '/' . ltrim((string)$path, '/');
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = !empty($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST']) : '';
    if ($host === '') return $path;
    return $scheme . '://' . $host . $path;
}

function jtCustomerPortalUsers(PDO $pdo, $tenantId, $clientId)
{
    if (!jtTable($pdo, 'client_portal_users')) return array();

    $st = $pdo->prepare("SELECT id, email
                         FROM client_portal_users
                         WHERE tenant_id = :tenant_id
                           AND client_id = :client_id
                           AND status = 'active'
                         ORDER BY id");
    $st->execute(array(':tenant_id' => $tenantId, ':client_id' => $clientId));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function jtNotifyCustomerOnTheWay(PDO $pdo, $tenantId, $userId, $job, $trackingToken)
{
    $summary = array(
        'in_app_sent' => 0,
        'in_app_skipped' => 0,
        'email_sent' => 0,
        'email_failed' => 0,
        'email_skipped' => 0,
    );

    $portalUsers = jtCustomerPortalUsers($pdo, $tenantId, (int)$job['client_id']);
    $trackingPath = '/customer/job-tracking.php?token=' . rawurlencode($trackingToken);
    $trackingUrl = jtAbsoluteUrl($trackingPath);
    $technician = trim((string)$job['technician_name']);
    if ($technician === '') $technician = 'Your service technician';

    $notificationMessage = $technician . ' has started travelling for job ' . $job['job_no'] . ' - ' . $job['title'] . '. You can track the technician live.';

    if (jtTable($pdo, 'in_app_notifications') && !empty($portalUsers)) {
        $ins = $pdo->prepare("INSERT INTO in_app_notifications
            (tenant_id, user_id, portal_user_id, title, message, related_type, related_id, action_url, icon_name, is_read)
            VALUES (:tenant_id, NULL, :portal_user_id, 'Technician On The Way', :message, 'job', :job_id, :action_url, 'geo-alt-fill', 0)");

        foreach ($portalUsers as $portalUser) {
            try {
                $ins->execute(array(
                    ':tenant_id' => $tenantId,
                    ':portal_user_id' => (int)$portalUser['id'],
                    ':message' => $notificationMessage,
                    ':job_id' => (int)$job['id'],
                    ':action_url' => $trackingPath,
                ));
                $summary['in_app_sent']++;
            } catch (Throwable $e) {
                error_log('FieldPlx travel customer in-app notification failed: ' . $e->getMessage());
            }
        }
    } else {
        $summary['in_app_skipped'] = 1;
    }

    $email = trim((string)$job['client_email']);
    if (($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) && !empty($portalUsers)) {
        foreach ($portalUsers as $portalUser) {
            $candidate = trim((string)$portalUser['email']);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $email = $candidate;
                break;
            }
        }
    }

    if ((int)$job['client_allow_email'] !== 1 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $summary['email_skipped'] = 1;
    } else {
        $cfg = jtSmtpConfig($pdo, $tenantId, isset($job['branch_id']) ? (int)$job['branch_id'] : 0);
        if (!$cfg) {
            $summary['email_skipped'] = 1;
        } else {
            try {
                $password = jtDecryptSmtpPassword($cfg['password_encrypted']);
                $customer = trim((string)$job['client_name']);
                if ($customer === '') $customer = 'Customer';
                $business = trim((string)$job['tenant_name']);
                if ($business === '') $business = 'FieldPlx';

                $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1f2d3d">'
                    . '<div style="padding:18px 20px;background:#001131;color:#fff"><h2 style="margin:0;font-size:20px">Technician On The Way</h2></div>'
                    . '<div style="padding:20px;border:1px solid #e5eaf1;border-top:0">'
                    . '<p>Hello ' . htmlspecialchars($customer, ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p><strong>' . htmlspecialchars($technician, ENT_QUOTES, 'UTF-8') . '</strong> has started travelling to your service location.</p>'
                    . '<div style="margin:16px 0;padding:14px;background:#f6f8fb;border-radius:8px">'
                    . '<strong>Job:</strong> ' . htmlspecialchars((string)$job['job_no'], ENT_QUOTES, 'UTF-8') . '<br>'
                    . '<strong>Service:</strong> ' . htmlspecialchars((string)$job['title'], ENT_QUOTES, 'UTF-8')
                    . '</div>'
                    . '<p style="margin:22px 0"><a href="' . htmlspecialchars($trackingUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:11px 18px;border-radius:7px;background:#74b824;color:#fff;text-decoration:none;font-weight:700">Track Technician Live</a></p>'
                    . '<p>You can use the tracking link while the technician is travelling. Tracking will stop after the technician marks the job location as arrived.</p>'
                    . '<p>Thank you,<br>' . htmlspecialchars($business, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '</div></div>';

                jtSendMail(
                    $cfg,
                    $password,
                    $email,
                    'Technician On The Way - ' . $job['job_no'],
                    $html
                );
                $summary['email_sent'] = 1;
            } catch (Throwable $e) {
                $summary['email_failed'] = 1;
                error_log('FieldPlx travel customer email failed: ' . $e->getMessage());
            }
        }
    }

    if (jtTable($pdo, 'activity_events')) {
        try {
            $activity = $pdo->prepare("INSERT INTO activity_events
                (tenant_id, branch_id, actor_user_id, actor_type, event_type, related_type, related_id, client_id, title, details_json, visible_to_client)
                VALUES (:tenant_id, :branch_id, :actor_user_id, 'user', 'job_travel_started', 'job', :job_id, :client_id, :title, :details, 1)");
            $activity->execute(array(
                ':tenant_id' => $tenantId,
                ':branch_id' => !empty($job['branch_id']) ? (int)$job['branch_id'] : null,
                ':actor_user_id' => $userId,
                ':job_id' => (int)$job['id'],
                ':client_id' => (int)$job['client_id'],
                ':title' => 'Technician on the way: ' . $job['job_no'],
                ':details' => json_encode(array(
                    'tracking_url' => $trackingPath,
                    'technician' => $technician,
                    'customer_notification' => $summary,
                ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));
        } catch (Throwable $e) {
            error_log('FieldPlx travel activity log failed: ' . $e->getMessage());
        }
    }

    return $summary;
}

function jtStartMessage($notification, $duplicate)
{
    if ($duplicate) {
        return 'Travelling is already active. Live location tracking continues.';
    }

    $inApp = isset($notification['in_app_sent']) ? (int)$notification['in_app_sent'] : 0;
    $emailSent = isset($notification['email_sent']) ? (int)$notification['email_sent'] : 0;
    $emailFailed = isset($notification['email_failed']) ? (int)$notification['email_failed'] : 0;

    if ($inApp > 0 && $emailSent > 0) {
        return 'Marked On the Way. Customer notified by email and in-app notification.';
    }
    if ($emailSent > 0) {
        return 'Marked On the Way. Customer email notification sent.';
    }
    if ($inApp > 0) {
        return 'Marked On the Way. Customer in-app notification sent. Email was not sent.';
    }
    if ($emailFailed > 0) {
        return 'Marked On the Way. Live tracking started, but the customer email could not be sent.';
    }
    return 'Marked On the Way. Live tracking started, but no customer notification destination was available.';
}

try {
    $pdo = jtDb();
    $tenantId = jtTenantId();
    $userId = jtUserId();
    if ($tenantId <= 0 || $userId <= 0) jtOut(false, 'Your login session is not valid.', array(), 401);

    $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $sessionCsrf = isset($_SESSION['my_jobs_csrf_token']) ? (string)$_SESSION['my_jobs_csrf_token'] : '';
    if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        jtOut(false, 'Invalid request token. Refresh the page and try again.', array(), 419);
    }

    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    if ($jobId <= 0) jtOut(false, 'Invalid job.', array(), 422);

    $job = jtJobAccess($pdo, $tenantId, $userId, $jobId);
    if (!$job) jtOut(false, 'Job not found or you are not assigned to this job.', array(), 403);

    $terminal = array('cancelled', 'completed', 'ready_to_invoice', 'invoiced', 'closed', 'archived');
    if (in_array((string)$job['status'], $terminal, true) && $action !== 'get') {
        jtOut(false, 'Travelling cannot be changed for this job status.', array(), 422);
    }

    if ($action === 'get') {
        $st = $pdo->prepare("SELECT status, tracking_token, started_at, arrived_at, stopped_at,
                                   latest_latitude, latest_longitude, latest_accuracy,
                                   latest_heading, latest_speed, last_location_at
                            FROM job_travel_tracking
                            WHERE tenant_id = :tenant_id AND job_id = :job_id
                            LIMIT 1");
        $st->execute(array(':tenant_id' => $tenantId, ':job_id' => $jobId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        jtOut(true, 'Travel status loaded.', array('travel' => $row ?: null));
    }

    if ($action === 'start') {
        $latitude = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $accuracy = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? (float)$_POST['accuracy'] : null;
        if ($latitude === null || $longitude === null || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            jtOut(false, 'A valid current GPS location is required to start travelling.', array(), 422);
        }

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT id, tracking_token, status FROM job_travel_tracking WHERE job_id = :job_id FOR UPDATE");
        $st->execute(array(':job_id' => $jobId));
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        $token = $existing && !empty($existing['tracking_token']) ? (string)$existing['tracking_token'] : bin2hex(random_bytes(32));

        if ($existing) {
            $trackingId = (int)$existing['id'];
            $up = $pdo->prepare("UPDATE job_travel_tracking
                                 SET tenant_id = :tenant_id, user_id = :user_id, status = 'on_the_way',
                                     started_at = COALESCE(started_at, NOW()), arrived_at = NULL, stopped_at = NULL,
                                     latest_latitude = :lat, latest_longitude = :lng, latest_accuracy = :accuracy,
                                     last_location_at = NOW()
                                 WHERE id = :id");
            $up->execute(array(':tenant_id'=>$tenantId, ':user_id'=>$userId, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy, ':id'=>$trackingId));
        } else {
            $ins = $pdo->prepare("INSERT INTO job_travel_tracking
                                  (tenant_id, job_id, user_id, status, tracking_token, started_at,
                                   latest_latitude, latest_longitude, latest_accuracy, last_location_at)
                                  VALUES (:tenant_id, :job_id, :user_id, 'on_the_way', :token, NOW(), :lat, :lng, :accuracy, NOW())");
            $ins->execute(array(':tenant_id'=>$tenantId, ':job_id'=>$jobId, ':user_id'=>$userId, ':token'=>$token, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy));
            $trackingId = (int)$pdo->lastInsertId();
        }

        $loc = $pdo->prepare("INSERT INTO job_travel_locations
                              (tracking_id, tenant_id, job_id, user_id, latitude, longitude, accuracy, recorded_at)
                              VALUES (:tracking_id, :tenant_id, :job_id, :user_id, :lat, :lng, :accuracy, NOW())");
        $loc->execute(array(':tracking_id'=>$trackingId, ':tenant_id'=>$tenantId, ':job_id'=>$jobId, ':user_id'=>$userId, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy));
        $pdo->commit();

        $alreadyOnTheWay = $existing && isset($existing['status']) && (string)$existing['status'] === 'on_the_way';
        $notification = array(
            'in_app_sent' => 0,
            'in_app_skipped' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
            'email_skipped' => 0,
        );

        if (!$alreadyOnTheWay) {
            $notification = jtNotifyCustomerOnTheWay($pdo, $tenantId, $userId, $job, $token);
        }

        $trackingPath = '/customer/job-tracking.php?token=' . rawurlencode($token);
        jtOut(true, jtStartMessage($notification, $alreadyOnTheWay), array(
            'travel_status' => 'on_the_way',
            'tracking_token' => $token,
            'tracking_url' => $trackingPath,
            'tracking_absolute_url' => jtAbsoluteUrl($trackingPath),
            'customer_notification' => $notification,
            'notification_duplicate_prevented' => $alreadyOnTheWay ? 1 : 0,
        ));
    }

    if ($action === 'location') {
        $latitude = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
        $accuracy = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? (float)$_POST['accuracy'] : null;
        $heading = isset($_POST['heading']) && $_POST['heading'] !== '' ? (float)$_POST['heading'] : null;
        $speed = isset($_POST['speed']) && $_POST['speed'] !== '' ? (float)$_POST['speed'] : null;
        if ($latitude === null || $longitude === null || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            jtOut(false, 'Invalid location update.', array(), 422);
        }
        $st = $pdo->prepare("SELECT id, status FROM job_travel_tracking WHERE tenant_id = :tenant_id AND job_id = :job_id LIMIT 1");
        $st->execute(array(':tenant_id'=>$tenantId, ':job_id'=>$jobId));
        $travel = $st->fetch(PDO::FETCH_ASSOC);
        if (!$travel || $travel['status'] !== 'on_the_way') jtOut(false, 'Travelling has not been started for this job.', array(), 409);

        $pdo->beginTransaction();
        $up = $pdo->prepare("UPDATE job_travel_tracking
                             SET user_id=:user_id, latest_latitude=:lat, latest_longitude=:lng,
                                 latest_accuracy=:accuracy, latest_heading=:heading, latest_speed=:speed,
                                 last_location_at=NOW()
                             WHERE id=:id");
        $up->execute(array(':user_id'=>$userId, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy, ':heading'=>$heading, ':speed'=>$speed, ':id'=>(int)$travel['id']));
        $loc = $pdo->prepare("INSERT INTO job_travel_locations
                              (tracking_id, tenant_id, job_id, user_id, latitude, longitude, accuracy, heading, speed, recorded_at)
                              VALUES (:tracking_id, :tenant_id, :job_id, :user_id, :lat, :lng, :accuracy, :heading, :speed, NOW())");
        $loc->execute(array(':tracking_id'=>(int)$travel['id'], ':tenant_id'=>$tenantId, ':job_id'=>$jobId, ':user_id'=>$userId, ':lat'=>$latitude, ':lng'=>$longitude, ':accuracy'=>$accuracy, ':heading'=>$heading, ':speed'=>$speed));
        $pdo->commit();
        jtOut(true, 'Location updated.', array('travel_status'=>'on_the_way'));
    }

    if ($action === 'arrived') {
        $st = $pdo->prepare("UPDATE job_travel_tracking
                             SET status='arrived', arrived_at=NOW(), stopped_at=NOW()
                             WHERE tenant_id=:tenant_id AND job_id=:job_id AND status='on_the_way'");
        $st->execute(array(':tenant_id'=>$tenantId, ':job_id'=>$jobId));
        jtOut(true, 'Marked as Arrived.', array('travel_status'=>'arrived'));
    }

    jtOut(false, 'Unknown action.', array(), 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    jtOut(false, $e->getMessage(), array(), 500);
}
