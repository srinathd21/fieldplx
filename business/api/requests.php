<?php
ob_start();

ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function rqResponse($status,$success,$message,$extra=array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int)$status);

    echo json_encode(
        array_merge(
            array(
                'success'=>(bool)$success,
                'message'=>(string)$message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function rqPost($key,$default='')
{
    return isset($_POST[$key])
        ? $_POST[$key]
        : $default;
}

function rqJson($value)
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    return $json === false ? null : $json;
}

function rqColumnExists(PDO $pdo,$table,$column)
{
    static $cache = array();

    $key = $table.'.'.$column;

    if (array_key_exists($key,$cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");

    $stmt->execute(array(
        ':table_name'=>$table,
        ':column_name'=>$column
    ));

    $cache[$key] =
        (int)$stmt->fetchColumn() > 0;

    return $cache[$key];
}

function rqTableExists(PDO $pdo,$table)
{
    static $cache = array();

    if (array_key_exists($table,$cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM information_schema.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");

    $stmt->execute(array(
        ':table_name'=>$table
    ));

    $cache[$table] =
        (int)$stmt->fetchColumn() > 0;

    return $cache[$table];
}

function rqSmtpConfig(PDO $pdo,$tenantId,$branchId)
{
    if (!rqTableExists($pdo,'smtp_configurations')) {
        return null;
    }

    /*
     * Use the SMTP saved by this tenant.
     * Priority: matching active branch SMTP -> default tenant SMTP -> any active tenant SMTP.
     */
    $stmt = $pdo->prepare("\n        SELECT *\n        FROM smtp_configurations\n        WHERE tenant_id = :tenant_id\n          AND is_active = 1\n          AND scope_type IN ('tenant','branch')\n          AND (\n                scope_type = 'tenant'\n                OR (\n                    scope_type = 'branch'\n                    AND branch_id = :branch_id\n                )\n          )\n        ORDER BY\n            CASE\n                WHEN scope_type = 'branch'\n                     AND branch_id = :branch_id2\n                THEN 0\n                WHEN scope_type = 'tenant'\n                     AND is_default = 1\n                THEN 1\n                WHEN scope_type = 'tenant'\n                THEN 2\n                ELSE 3\n            END,\n            id\n        LIMIT 1\n    ");

    $stmt->execute(array(
        ':tenant_id'=>(int)$tenantId,
        ':branch_id'=>$branchId > 0 ? (int)$branchId : 0,
        ':branch_id2'=>$branchId > 0 ? (int)$branchId : 0
    ));

    $row = $stmt->fetch();
    return $row ? $row : null;
}

function rqDecryptSmtpPassword($encrypted,$tenantId)
{
    $encrypted = trim((string)$encrypted);
    if ($encrypted === '') return '';

    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('PHP OpenSSL extension is required for SMTP email.');
    }

    $raw = base64_decode($encrypted,true);
    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException('Stored SMTP password is invalid. Open Master Controls > Tenant SMTP, re-enter the password and save it.');
    }

    $envKey = getenv('FIELDPLX_APP_KEY');
    if ($envKey === false || trim($envKey) === '') {
        $seed =
            (defined('DB_NAME') ? DB_NAME : '') . '|' .
            (defined('DB_USER') ? DB_USER : '') . '|' .
            (defined('DB_PASS') ? DB_PASS : '') . '|' .
            (int)$tenantId;
    } else {
        $seed = $envKey . '|' . (int)$tenantId;
    }

    $key = hash('sha256',$seed,true);
    $iv = substr($raw,0,16);
    $cipher = substr($raw,16);
    $plain = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($plain === false) {
        throw new RuntimeException('Unable to decrypt the saved tenant SMTP password. Re-enter the SMTP password and save the configuration.');
    }

    return $plain;
}

function rqSmtpRead($socket)
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket,515);
        if ($line === false) break;
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return trim($response);
}

function rqSmtpCommand($socket,$command,$expected,$label)
{
    if ($command !== null) {
        if (@fwrite($socket,$command."\r\n") === false) {
            throw new RuntimeException('SMTP connection closed while sending '.$label.'.');
        }
    }

    $response = rqSmtpRead($socket);
    $code = (int)substr((string)$response,0,3);

    if (!in_array($code,(array)$expected,true)) {
        $safe = preg_replace('/[\r\n]+/',' ',(string)$response);
        throw new RuntimeException(
            $label.' failed (SMTP '.$code.'): '.substr($safe,0,350)
        );
    }

    return $response;
}

function rqSmtpHeader($value)
{
    return trim(str_replace(array("\r","\n"),' ',(string)$value));
}

function rqSendTenantSmtpMail(array $config,$tenantId,$recipient,$subject,$body)
{
    $host = trim((string)$config['host']);
    $port = (int)$config['port'];
    $encryption = strtolower(trim((string)$config['encryption']));
    $username = trim((string)$config['username']);
    $fromEmail = trim((string)$config['from_email']);
    $fromName = trim((string)$config['from_name']);
    $replyTo = trim((string)$config['reply_to_email']);

    if ($host === '') {
        throw new RuntimeException('Tenant SMTP host is empty.');
    }

    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Tenant SMTP port is invalid.');
    }

    if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Selected employee does not have a valid email address.');
    }

    if (!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Tenant SMTP From Email is invalid.');
    }

    if (!in_array($encryption,array('none','ssl','tls','starttls'),true)) {
        throw new RuntimeException('Tenant SMTP encryption setting is invalid.');
    }

    $password = rqDecryptSmtpPassword(
        isset($config['password_encrypted']) ? $config['password_encrypted'] : '',
        $tenantId
    );

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.$port;
    $context = stream_context_create(array(
        'ssl'=>array(
            'verify_peer'=>true,
            'verify_peer_name'=>true,
            'allow_self_signed'=>false,
            'peer_name'=>$host
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
        throw new RuntimeException(
            'Unable to connect to tenant SMTP server: '.
            ($errstr !== '' ? $errstr : 'connection failed').
            ' ('.$errno.').' 
        );
    }

    stream_set_timeout($socket,20);

    try {
        rqSmtpCommand($socket,null,array(220),'SMTP greeting');

        $ehloHost = isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== ''
            ? preg_replace('/[^A-Za-z0-9.\-]/','',$_SERVER['SERVER_NAME'])
            : 'fieldplx.local';

        if ($ehloHost === '') $ehloHost = 'fieldplx.local';

        rqSmtpCommand($socket,'EHLO '.$ehloHost,array(250),'EHLO');

        if ($encryption === 'tls' || $encryption === 'starttls') {
            rqSmtpCommand($socket,'STARTTLS',array(220),'STARTTLS');

            $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ? STREAM_CRYPTO_METHOD_TLS_CLIENT
                : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                $cryptoMethod
            );

            if ($crypto !== true) {
                throw new RuntimeException('Unable to establish TLS encryption with the tenant SMTP server.');
            }

            rqSmtpCommand($socket,'EHLO '.$ehloHost,array(250),'EHLO after TLS');
        }

        if ($username !== '') {
            if ($password === '') {
                throw new RuntimeException('Tenant SMTP password is empty.');
            }

            rqSmtpCommand($socket,'AUTH LOGIN',array(334),'SMTP authentication');
            rqSmtpCommand($socket,base64_encode($username),array(334),'SMTP username');
            rqSmtpCommand($socket,base64_encode($password),array(235),'SMTP password');
        }

        rqSmtpCommand($socket,'MAIL FROM:<'.$fromEmail.'>',array(250),'MAIL FROM');
        rqSmtpCommand($socket,'RCPT TO:<'.$recipient.'>',array(250,251),'RCPT TO');
        rqSmtpCommand($socket,'DATA',array(354),'DATA');

        $displayName = $fromName !== '' ? rqSmtpHeader($fromName) : 'FieldPlx';
        $messageIdHost = preg_replace('/[^A-Za-z0-9.\-]/','',$host);
        if ($messageIdHost === '') $messageIdHost = 'fieldplx.local';

        $headers = array(
            'Date: '.date(DATE_RFC2822),
            'From: '.$displayName.' <'.$fromEmail.'>',
            'To: <'.$recipient.'>',
            'Subject: '.rqSmtpHeader($subject),
            'Message-ID: <'.bin2hex(random_bytes(10)).'@'.$messageIdHost.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        );

        if ($replyTo !== '' && filter_var($replyTo,FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: <'.$replyTo.'>';
        }

        $payload = implode("\r\n",$headers)."\r\n\r\n".
            str_replace(array("\r\n","\r"),"\n",(string)$body);
        $payload = str_replace("\n","\r\n",$payload);
        $payload = preg_replace('/(?m)^\./','..',$payload);

        if (@fwrite($socket,$payload."\r\n.\r\n") === false) {
            throw new RuntimeException('Unable to send SMTP message data.');
        }

        rqSmtpCommand($socket,null,array(250),'Message delivery');
        @fwrite($socket,"QUIT\r\n");
    } finally {
        @fclose($socket);
    }

    return true;
}

function rqRecordEmailResult(PDO $pdo,$tenantId,$branchId,$eventId,$employeeId,$email,$requestId,$subject,$body,$smtpId,$status,$errorMessage=null)
{
    if (!rqTableExists($pdo,'notification_queue')) return;

    try {
        $stmt = $pdo->prepare("\n            INSERT INTO notification_queue (\n                tenant_id,branch_id,event_id,channel,recipient_type,recipient_id,\n                recipient_address,related_type,related_id,subject,body,smtp_config_id,\n                status,attempts,scheduled_at,sent_at,last_error\n            ) VALUES (\n                :tenant_id,:branch_id,:event_id,'email','user',:recipient_id,\n                :recipient_address,'service_request',:related_id,:subject,:body,:smtp_config_id,\n                :status,1,NOW(),:sent_at,:last_error\n            )\n        ");

        $stmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':branch_id'=>$branchId > 0 ? $branchId : null,
            ':event_id'=>$eventId,
            ':recipient_id'=>$employeeId,
            ':recipient_address'=>$email,
            ':related_id'=>$requestId,
            ':subject'=>$subject,
            ':body'=>$body,
            ':smtp_config_id'=>$smtpId,
            ':status'=>$status,
            ':sent_at'=>$status === 'sent' ? date('Y-m-d H:i:s') : null,
            ':last_error'=>$errorMessage !== null ? substr((string)$errorMessage,0,2000) : null
        ));
    } catch (Throwable $e) {
        error_log('Request email result log error: '.$e->getMessage());
    }
}

function rqNotificationEventId(PDO $pdo)
{
    if (!rqTableExists($pdo,'notification_events')) {
        return null;
    }

    $stmt = $pdo->prepare("\n        SELECT id\n        FROM notification_events\n        WHERE event_key = 'request.assigned'\n          AND is_active = 1\n        LIMIT 1\n    ");

    $stmt->execute();

    $id = (int)$stmt->fetchColumn();

    return $id > 0 ? $id : null;
}

function rqNotifyAssignedEmployee(
    PDO $pdo,
    $tenantId,
    $branchId,
    $employeeId,
    $requestId,
    $requestNo,
    $title,
    $clientName,
    $sendInApp,
    $sendEmail
) {
    $summary = array(
        'employee_id'=>(int)$employeeId,
        'in_app_sent'=>0,
        'email_queued'=>0,
        'email_sent'=>0,
        'email_skipped'=>0,
        'email_message'=>''
    );

    if ((int)$employeeId <= 0) {
        return $summary;
    }

    $stmt = $pdo->prepare("\n        SELECT id,email,first_name,last_name\n        FROM users\n        WHERE id = :user_id\n          AND tenant_id = :tenant_id\n          AND status = 'active'\n          AND deleted_at IS NULL\n        LIMIT 1\n    ");

    $stmt->execute(array(
        ':user_id'=>(int)$employeeId,
        ':tenant_id'=>(int)$tenantId
    ));

    $employee = $stmt->fetch();

    if (!$employee) {
        return $summary;
    }

    $notificationTitle = 'Service Request Assigned';
    $message = 'You have been assigned to request '.
        $requestNo.
        ' - '.
        $title;

    if ($clientName !== '') {
        $message .= ' for '.$clientName;
    }

    if ($sendInApp && rqTableExists($pdo,'in_app_notifications')) {
        try {
            $insert = $pdo->prepare("\n                INSERT INTO in_app_notifications (\n                    tenant_id,\n                    user_id,\n                    title,\n                    message,\n                    related_type,\n                    related_id,\n                    action_url,\n                    icon_name,\n                    is_read\n                ) VALUES (\n                    :tenant_id,\n                    :user_id,\n                    :title,\n                    :message,\n                    'service_request',\n                    :related_id,\n                    :action_url,\n                    'clipboard-check',\n                    0\n                )\n            ");

            $insert->execute(array(
                ':tenant_id'=>$tenantId,
                ':user_id'=>$employeeId,
                ':title'=>$notificationTitle,
                ':message'=>$message,
                ':related_id'=>$requestId,
                ':action_url'=>'requests.php?request_id='.(int)$requestId
            ));

            $summary['in_app_sent'] = 1;
        } catch (Throwable $e) {
            error_log(
                'Request employee notification error: '.
                $e->getMessage()
            );
        }
    }

    if ($sendEmail) {
        $email = trim((string)$employee['email']);
        $smtp = rqSmtpConfig($pdo,$tenantId,$branchId);
        $eventId = rqNotificationEventId($pdo);

        if ($email === '' || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
            $summary['email_skipped'] = 1;
            $summary['email_message'] = 'Selected employee does not have a valid email address.';
        } elseif (!$smtp) {
            $summary['email_skipped'] = 1;
            $summary['email_message'] = 'No active Tenant SMTP configuration was found.';
        } else {
            $employeeName = trim(
                (string)$employee['first_name'].' '.
                (string)$employee['last_name']
            );

            if ($employeeName === '') {
                $employeeName = 'Employee';
            }

            $subject = $notificationTitle.' - '.$requestNo;
            $body =
                "Hello ".$employeeName.",\n\n".
                $message.".\n\n".
                "Request Number: ".$requestNo."\n".
                "Request Title: ".$title."\n".
                ($clientName !== '' ? "Customer: ".$clientName."\n" : '').
                "\nPlease login to FieldPlx to view the assigned request.\n\n".
                "FieldPlx";

            try {
                /*
                 * IMPORTANT: send immediately through the SMTP configuration
                 * saved in Master Controls for this tenant/branch. This does
                 * not depend on a background notification queue worker.
                 */
                rqSendTenantSmtpMail(
                    $smtp,
                    $tenantId,
                    $email,
                    $subject,
                    $body
                );

                $summary['email_sent'] = 1;
                $summary['email_queued'] = 0;
                $summary['email_skipped'] = 0;
                $summary['email_message'] = 'Assignment email sent successfully to '.$email.'.';
                $summary['smtp_config_id'] = (int)$smtp['id'];
                $summary['smtp_config_name'] = isset($smtp['config_name']) ? $smtp['config_name'] : '';

                rqRecordEmailResult(
                    $pdo,$tenantId,$branchId,$eventId,
                    $employeeId,$email,$requestId,$subject,$body,
                    (int)$smtp['id'],'sent',null
                );
            } catch (Throwable $e) {
                $summary['email_sent'] = 0;
                $summary['email_queued'] = 0;
                $summary['email_skipped'] = 1;
                $summary['email_message'] = $e->getMessage();
                $summary['smtp_config_id'] = (int)$smtp['id'];
                $summary['smtp_config_name'] = isset($smtp['config_name']) ? $smtp['config_name'] : '';

                rqRecordEmailResult(
                    $pdo,$tenantId,$branchId,$eventId,
                    $employeeId,$email,$requestId,$subject,$body,
                    (int)$smtp['id'],'failed',$e->getMessage()
                );

                error_log(
                    'Request employee direct SMTP error: '.
                    $e->getMessage()
                );
            }
        }
    }

    return $summary;
}

function rqGet(PDO $pdo,$tenantId,$requestId)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM service_requests
        WHERE id = :id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");

    $stmt->execute(array(
        ':id'=>(int)$requestId,
        ':tenant_id'=>(int)$tenantId
    ));

    $row = $stmt->fetch();

    if (!$row) {
        rqResponse(
            404,
            false,
            'Service request not found.'
        );
    }

    return $row;
}

function rqMeta(PDO $pdo,$tenantId)
{
    $branchesStmt = $pdo->prepare("
        SELECT id,name,branch_code
        FROM branches
        WHERE tenant_id = :tenant_id
          AND status = 'active'
        ORDER BY
            is_head_office DESC,
            name ASC
    ");

    $branchesStmt->execute(array(
        ':tenant_id'=>$tenantId
    ));

    $clientsStmt = $pdo->prepare("
        SELECT
            id,
            display_name AS name,
            company_name,
            phone,
            email
        FROM clients
        WHERE tenant_id = :tenant_id
          AND deleted_at IS NULL
          AND status <> 'archived'
        ORDER BY display_name
    ");

    $clientsStmt->execute(array(
        ':tenant_id'=>$tenantId
    ));

    $servicesStmt = $pdo->prepare("
        SELECT id,name,sku
        FROM product_services
        WHERE tenant_id = :tenant_id
          AND item_type = 'service'
          AND status = 'active'
          AND deleted_at IS NULL
        ORDER BY name
    ");

    $servicesStmt->execute(array(
        ':tenant_id'=>$tenantId
    ));

    $usersStmt = $pdo->prepare("
        SELECT
            id,
            CONCAT(
                first_name,
                CASE
                    WHEN last_name IS NOT NULL
                         AND last_name <> ''
                    THEN CONCAT(' ',last_name)
                    ELSE ''
                END
            ) AS name,
            employee_code,
            job_title
        FROM users
        WHERE tenant_id = :tenant_id
          AND status = 'active'
          AND deleted_at IS NULL
        ORDER BY first_name,last_name
    ");

    $usersStmt->execute(array(
        ':tenant_id'=>$tenantId
    ));

    return array(
        'branches'=>$branchesStmt->fetchAll(),
        'clients'=>$clientsStmt->fetchAll(),
        'services'=>$servicesStmt->fetchAll(),
        'users'=>$usersStmt->fetchAll()
    );
}

function rqValidateTenantRow(
    PDO $pdo,
    $table,
    $tenantId,
    $id,
    $extra=''
) {
    if ($id <= 0) {
        return null;
    }

    $allowed = array(
        'branches',
        'clients',
        'client_locations',
        'product_services',
        'users'
    );

    if (!in_array($table,$allowed,true)) {
        return null;
    }

    $sql = "
        SELECT id
        FROM ".$table."
        WHERE id = :id
          AND tenant_id = :tenant_id
    ";

    if (
        in_array(
            $table,
            array(
                'clients',
                'client_locations',
                'product_services',
                'users'
            ),
            true
        )
    ) {
        if (
            rqColumnExists(
                $pdo,
                $table,
                'deleted_at'
            )
        ) {
            $sql .= " AND deleted_at IS NULL";
        }
    }

    if ($extra !== '') {
        $sql .= ' '.$extra;
    }

    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);

    $stmt->execute(array(
        ':id'=>$id,
        ':tenant_id'=>$tenantId
    ));

    return $stmt->fetchColumn()
        ? (int)$id
        : null;
}

function rqActivity(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $eventType,
    $requestId,
    $clientId,
    $title,
    $details
) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_events (
                tenant_id,
                branch_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                client_id,
                title,
                details_json,
                visible_to_client
            ) VALUES (
                :tenant_id,
                :branch_id,
                :actor_user_id,
                'user',
                :event_type,
                'service_request',
                :related_id,
                :client_id,
                :title,
                :details_json,
                0
            )
        ");

        $stmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':branch_id'=>$branchId > 0 ? $branchId : null,
            ':actor_user_id'=>$userId,
            ':event_type'=>substr($eventType,0,120),
            ':related_id'=>$requestId > 0 ? $requestId : null,
            ':client_id'=>$clientId > 0 ? $clientId : null,
            ':title'=>substr($title,0,255),
            ':details_json'=>rqJson($details)
        ));
    } catch (Throwable $e) {
        error_log(
            'Request activity log error: ' .
            $e->getMessage()
        );
    }
}

function rqAudit(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $action,
    $requestId,
    $old,
    $new
) {
    if (function_exists('tenantAuditLog')) {
        tenantAuditLog(
            $pdo,
            $action,
            $tenantId,
            $branchId,
            $userId,
            'service_request',
            $requestId,
            $old,
            $new
        );
    }
}

/*
 * Build request number from the tenant/branch document sequence.
 * Supports current schema name number_separator and older separator.
 */
function rqNextNumber(
    PDO $pdo,
    $tenantId,
    $branchId
) {
    $separatorColumn =
        rqColumnExists(
            $pdo,
            'document_sequences',
            'number_separator'
        )
            ? 'number_separator'
            : 'separator';

    /*
     * Prefer branch-specific format, then tenant-level format.
     */
    $sequenceStmt = $pdo->prepare("
        SELECT
            ds.*,
            b.branch_code
        FROM document_sequences ds
        LEFT JOIN branches b
            ON b.id = ds.branch_id
           AND b.tenant_id = ds.tenant_id
        WHERE ds.tenant_id = :tenant_id
          AND ds.document_type = 'request'
          AND ds.is_active = 1
          AND (
                ds.branch_id = :branch_id
                OR ds.branch_id IS NULL
          )
        ORDER BY
            CASE
                WHEN ds.branch_id = :branch_id2
                THEN 0
                ELSE 1
            END,
            ds.id
        LIMIT 1
        FOR UPDATE
    ");

    $sequenceStmt->execute(array(
        ':tenant_id'=>$tenantId,
        ':branch_id'=>$branchId > 0 ? $branchId : 0,
        ':branch_id2'=>$branchId > 0 ? $branchId : 0
    ));

    $seq = $sequenceStmt->fetch();

    if (!$seq) {
        /*
         * Safe fallback when numbering has not yet been configured.
         * Keep uniqueness tenant scoped.
         */
        $maxStmt = $pdo->prepare("
            SELECT MAX(
                CAST(
                    SUBSTRING_INDEX(
                        request_no,
                        '-',
                        -1
                    ) AS UNSIGNED
                )
            )
            FROM service_requests
            WHERE tenant_id = :tenant_id
              AND request_no LIKE 'REQ-%'
        ");

        $maxStmt->execute(array(
            ':tenant_id'=>$tenantId
        ));

        $next =
            (int)$maxStmt->fetchColumn() + 1;

        return 'REQ-' .
            str_pad(
                (string)$next,
                6,
                '0',
                STR_PAD_LEFT
            );
    }

    $today = new DateTime('now');
    $year = $today->format('Y');
    $month = $today->format('m');
    $fyStart =
        max(
            1,
            min(
                12,
                (int)$seq['financial_year_start_month']
            )
        );

    $currentMonth =
        (int)$today->format('n');

    $fyYear =
        $currentMonth >= $fyStart
            ? (int)$year
            : (int)$year - 1;

    $fyLabel =
        $fyYear .
        '-' .
        substr(
            (string)($fyYear + 1),
            -2
        );

    $resetKey = 'never';

    switch ($seq['reset_period']) {
        case 'monthly':
            $resetKey = $year.$month;
            break;

        case 'yearly':
            $resetKey = $year;
            break;

        case 'financial_year':
            $resetKey = $fyLabel;
            break;
    }

    $currentNumber =
        (int)$seq['current_number'];

    if (
        $seq['reset_period'] !== 'never' &&
        (string)$seq['last_reset_key'] !==
        (string)$resetKey
    ) {
        $currentNumber = 0;
    }

    $next =
        $currentNumber + 1;

    $middle = '';

    switch ($seq['middle_format']) {
        case 'year':
            $middle = $year;
            break;

        case 'year_month':
            $middle = $year.$month;
            break;

        case 'financial_year':
            $middle = $fyLabel;
            break;

        case 'branch_year':
            $middle =
                (!empty($seq['branch_code'])
                    ? $seq['branch_code']
                    : 'BR').
                $year;
            break;
    }

    $separator =
        isset($seq[$separatorColumn])
            ? (string)$seq[$separatorColumn]
            : '-';

    $parts = array();

    if (!empty($seq['prefix'])) {
        $parts[] = $seq['prefix'];
    }

    if ($middle !== '') {
        $parts[] = $middle;
    }

    $parts[] =
        str_pad(
            (string)$next,
            max(
                1,
                (int)$seq['number_length']
            ),
            '0',
            STR_PAD_LEFT
        );

    if (!empty($seq['suffix'])) {
        $parts[] = $seq['suffix'];
    }

    $requestNo =
        implode(
            $separator,
            $parts
        );

    $update =
        $pdo->prepare("
            UPDATE document_sequences
            SET
                current_number = :current_number,
                last_reset_key = :last_reset_key
            WHERE id = :id
        ");

    $update->execute(array(
        ':current_number'=>$next,
        ':last_reset_key'=>$resetKey,
        ':id'=>$seq['id']
    ));

    return $requestNo;
}

$tenantId =
    isset($_SESSION['tenant_id'])
        ? (int)$_SESSION['tenant_id']
        : 0;

$userId =
    isset($_SESSION['tenant_user_id'])
        ? (int)$_SESSION['tenant_user_id']
        : 0;

$sessionBranchId =
    isset($_SESSION['branch_id'])
        ? (int)$_SESSION['branch_id']
        : 0;

if ($tenantId <= 0 || $userId <= 0) {
    rqResponse(
        401,
        false,
        'Authentication required.'
    );
}

$csrf =
    (string)rqPost(
        'csrf_token',
        ''
    );

$sessionCsrf =
    isset($_SESSION['requests_csrf_token'])
        ? (string)$_SESSION['requests_csrf_token']
        : '';

if (
    $csrf === '' ||
    $sessionCsrf === '' ||
    !hash_equals(
        $sessionCsrf,
        $csrf
    )
) {
    rqResponse(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action =
    trim(
        (string)rqPost(
            'action',
            ''
        )
    );

try {

    if ($action === 'list') {

        $page =
            max(
                1,
                (int)rqPost(
                    'page',
                    1
                )
            );

        $perPage =
            (int)rqPost(
                'per_page',
                10
            );

        if (!in_array(
            $perPage,
            array(10,25,50),
            true
        )) {
            $perPage = 10;
        }

        $search =
            trim(
                (string)rqPost(
                    'search',
                    ''
                )
            );

        $status =
            trim(
                (string)rqPost(
                    'status',
                    ''
                )
            );

        $priority =
            trim(
                (string)rqPost(
                    'priority',
                    ''
                )
            );

        $branchFilter =
            (int)rqPost(
                'branch_id',
                0
            );

        $serviceFilter =
            (int)rqPost(
                'product_service_id',
                0
            );

        $where = array(
            'r.tenant_id = :tenant_id'
        );

        $params = array(
            ':tenant_id'=>$tenantId
        );

        if ($search !== '') {
            $value = '%'.$search.'%';

            $where[] = "(
                r.request_no LIKE :search1
                OR r.title LIKE :search2
                OR r.description LIKE :search3
                OR c.display_name LIKE :search4
                OR c.phone LIKE :search5
                OR ps.name LIKE :search6
            )";

            $params[':search1']=$value;
            $params[':search2']=$value;
            $params[':search3']=$value;
            $params[':search4']=$value;
            $params[':search5']=$value;
            $params[':search6']=$value;
        }

        $statuses = array(
            'new',
            'contacting',
            'information_required',
            'assessment_required',
            'quote_required',
            'job_required',
            'converted',
            'closed',
            'cancelled'
        );

        if (in_array($status,$statuses,true)) {
            $where[] = 'r.status = :status';
            $params[':status']=$status;
        }

        $priorities = array(
            'low',
            'normal',
            'high',
            'urgent'
        );

        if (in_array($priority,$priorities,true)) {
            $where[] = 'r.priority = :priority';
            $params[':priority']=$priority;
        }

        if ($branchFilter > 0) {
            $where[] = 'r.branch_id = :branch_id';
            $params[':branch_id']=$branchFilter;
        }

        if ($serviceFilter > 0) {
            $where[] =
                'r.product_service_id = :service_id';

            $params[':service_id']=$serviceFilter;
        }

        $whereSql =
            implode(
                ' AND ',
                $where
            );

        $countStmt =
            $pdo->prepare("
                SELECT COUNT(*)
                FROM service_requests r
                INNER JOIN clients c
                    ON c.id = r.client_id
                   AND c.tenant_id = r.tenant_id
                LEFT JOIN product_services ps
                    ON ps.id = r.product_service_id
                   AND ps.tenant_id = r.tenant_id
                WHERE $whereSql
            ");

        $countStmt->execute($params);

        $total =
            (int)$countStmt->fetchColumn();

        $pages =
            max(
                1,
                (int)ceil(
                    $total / $perPage
                )
            );

        if ($page > $pages) {
            $page=$pages;
        }

        $offset =
            ($page - 1) * $perPage;

        $stmt =
            $pdo->prepare("
                SELECT
                    r.*,

                    c.display_name AS client_name,
                    c.phone AS client_phone,

                    cl.name AS location_name,
                    cl.city AS location_city,

                    ps.name AS service_name,

                    b.name AS branch_name,

                    CONCAT(
                        COALESCE(u.first_name,''),
                        CASE
                            WHEN u.last_name IS NOT NULL
                                 AND u.last_name <> ''
                            THEN CONCAT(' ',u.last_name)
                            ELSE ''
                        END
                    ) AS assigned_name

                FROM service_requests r

                INNER JOIN clients c
                    ON c.id = r.client_id
                   AND c.tenant_id = r.tenant_id

                LEFT JOIN client_locations cl
                    ON cl.id = r.location_id
                   AND cl.tenant_id = r.tenant_id

                LEFT JOIN product_services ps
                    ON ps.id = r.product_service_id
                   AND ps.tenant_id = r.tenant_id

                LEFT JOIN branches b
                    ON b.id = r.branch_id
                   AND b.tenant_id = r.tenant_id

                LEFT JOIN users u
                    ON u.id = r.assigned_user_id
                   AND u.tenant_id = r.tenant_id

                WHERE $whereSql

                ORDER BY
                    CASE r.priority
                        WHEN 'urgent' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'normal' THEN 3
                        ELSE 4
                    END,
                    CASE r.status
                        WHEN 'new' THEN 1
                        WHEN 'contacting' THEN 2
                        WHEN 'information_required' THEN 3
                        WHEN 'assessment_required' THEN 4
                        WHEN 'quote_required' THEN 5
                        WHEN 'job_required' THEN 6
                        ELSE 7
                    END,
                    COALESCE(r.updated_at,r.created_at) DESC,
                    r.id DESC

                LIMIT " .
                (int)$perPage .
                " OFFSET " .
                (int)$offset
            );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll();

        $summaryStmt =
            $pdo->prepare("
                SELECT
                    COUNT(*) AS total,

                    SUM(
                        CASE
                            WHEN status = 'new'
                            THEN 1
                            ELSE 0
                        END
                    ) AS new_count,

                    SUM(
                        CASE
                            WHEN status IN (
                                'information_required',
                                'assessment_required',
                                'quote_required',
                                'job_required'
                            )
                            THEN 1
                            ELSE 0
                        END
                    ) AS action_required,

                    SUM(
                        CASE
                            WHEN priority = 'urgent'
                             AND status NOT IN (
                                'converted',
                                'closed',
                                'cancelled'
                             )
                            THEN 1
                            ELSE 0
                        END
                    ) AS urgent

                FROM service_requests
                WHERE tenant_id = :tenant_id
            ");

        $summaryStmt->execute(array(
            ':tenant_id'=>$tenantId
        ));

        $summary =
            $summaryStmt->fetch();

        rqResponse(
            200,
            true,
            'Service requests loaded.',
            array(
                'requests'=>$rows,
                'meta'=>rqMeta(
                    $pdo,
                    $tenantId
                ),
                'summary'=>array(
                    'total'=>(int)($summary['total'] ?? 0),
                    'new_count'=>(int)($summary['new_count'] ?? 0),
                    'action_required'=>(int)($summary['action_required'] ?? 0),
                    'urgent'=>(int)($summary['urgent'] ?? 0)
                ),
                'pagination'=>array(
                    'page'=>$page,
                    'per_page'=>$perPage,
                    'total'=>$total,
                    'pages'=>$pages,
                    'from'=>$total > 0
                        ? $offset + 1
                        : 0,
                    'to'=>$total > 0
                        ? min(
                            $offset + count($rows),
                            $total
                        )
                        : 0
                )
            )
        );
    }

    if ($action === 'locations') {

        $clientId =
            (int)rqPost(
                'client_id',
                0
            );

        $validClient =
            rqValidateTenantRow(
                $pdo,
                'clients',
                $tenantId,
                $clientId
            );

        if ($validClient === null) {
            rqResponse(
                422,
                false,
                'Selected client is invalid.'
            );
        }

        $stmt =
            $pdo->prepare("
                SELECT
                    id,
                    name,
                    location_type,
                    city,
                    state,
                    is_primary
                FROM client_locations
                WHERE tenant_id = :tenant_id
                  AND client_id = :client_id
                  AND deleted_at IS NULL
                  AND status = 'active'
                ORDER BY
                    is_primary DESC,
                    name
            ");

        $stmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':client_id'=>$clientId
        ));

        rqResponse(
            200,
            true,
            'Locations loaded.',
            array(
                'locations'=>$stmt->fetchAll()
            )
        );
    }

    if ($action === 'get') {

        $requestId =
            (int)rqPost(
                'request_id',
                0
            );

        rqResponse(
            200,
            true,
            'Service request loaded.',
            array(
                'request'=>rqGet(
                    $pdo,
                    $tenantId,
                    $requestId
                ),
                'meta'=>rqMeta(
                    $pdo,
                    $tenantId
                )
            )
        );
    }

    if ($action === 'history') {

        $requestId =
            (int)rqPost(
                'request_id',
                0
            );

        rqGet(
            $pdo,
            $tenantId,
            $requestId
        );

        $stmt =
            $pdo->prepare("
                SELECT
                    h.id,
                    h.old_status,
                    h.new_status,
                    h.notes,
                    h.changed_at,

                    CONCAT(
                        COALESCE(u.first_name,''),
                        CASE
                            WHEN u.last_name IS NOT NULL
                                 AND u.last_name <> ''
                            THEN CONCAT(' ',u.last_name)
                            ELSE ''
                        END
                    ) AS changed_by_name

                FROM request_status_history h

                LEFT JOIN users u
                    ON u.id = h.changed_by
                   AND u.tenant_id = h.tenant_id

                WHERE h.tenant_id = :tenant_id
                  AND h.request_id = :request_id

                ORDER BY
                    h.changed_at DESC,
                    h.id DESC
            ");

        $stmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':request_id'=>$requestId
        ));

        rqResponse(
            200,
            true,
            'Status history loaded.',
            array(
                'history'=>$stmt->fetchAll()
            )
        );
    }

    if ($action === 'save') {

        $requestId =
            (int)rqPost(
                'request_id',
                0
            );

        $clientId =
            (int)rqPost(
                'client_id',
                0
            );

        $locationId =
            (int)rqPost(
                'location_id',
                0
            );

        $serviceId =
            (int)rqPost(
                'product_service_id',
                0
            );

        $branchId =
            (int)rqPost(
                'branch_id',
                0
            );

        $assignedUserId =
            (int)rqPost(
                'assigned_user_id',
                0
            );

        /*
         * Notification options:
         * - If the form does not send these fields, both remain enabled.
         * - Send only to the employee selected in assigned_user_id.
         */
        $notifyInApp =
            (string)rqPost('notify_in_app','1') !== '0';

        $notifyEmail =
            (string)rqPost('notify_email','1') !== '0';

        $title =
            trim(
                (string)rqPost(
                    'title',
                    ''
                )
            );

        $description =
            trim(
                (string)rqPost(
                    'description',
                    ''
                )
            );

        $source =
            trim(
                (string)rqPost(
                    'source',
                    'office'
                )
            );

        $priority =
            trim(
                (string)rqPost(
                    'priority',
                    'normal'
                )
            );

        $preferredDate =
            trim(
                (string)rqPost(
                    'preferred_date',
                    ''
                )
            );

        $preferredFrom =
            trim(
                (string)rqPost(
                    'preferred_time_from',
                    ''
                )
            );

        $preferredTo =
            trim(
                (string)rqPost(
                    'preferred_time_to',
                    ''
                )
            );

        $status =
            trim(
                (string)rqPost(
                    'status',
                    'new'
                )
            );

        $statusNote =
            trim(
                (string)rqPost(
                    'status_note',
                    ''
                )
            );

        if ($title === '') {
            rqResponse(
                422,
                false,
                'Request title is required.'
            );
        }

        $clientId =
            rqValidateTenantRow(
                $pdo,
                'clients',
                $tenantId,
                $clientId
            );

        if ($clientId === null) {
            rqResponse(
                422,
                false,
                'Select a valid client.'
            );
        }

        $locationId =
            rqValidateTenantRow(
                $pdo,
                'client_locations',
                $tenantId,
                $locationId
            );

        if ($locationId !== null) {
            $locationCheck =
                $pdo->prepare("
                    SELECT id
                    FROM client_locations
                    WHERE id = :id
                      AND tenant_id = :tenant_id
                      AND client_id = :client_id
                      AND deleted_at IS NULL
                    LIMIT 1
                ");

            $locationCheck->execute(array(
                ':id'=>$locationId,
                ':tenant_id'=>$tenantId,
                ':client_id'=>$clientId
            ));

            if (!$locationCheck->fetchColumn()) {
                rqResponse(
                    422,
                    false,
                    'Selected location does not belong to the selected client.'
                );
            }
        }

        $serviceId =
            rqValidateTenantRow(
                $pdo,
                'product_services',
                $tenantId,
                $serviceId,
                "AND item_type = 'service'"
            );

        $branchId =
            rqValidateTenantRow(
                $pdo,
                'branches',
                $tenantId,
                $branchId
            );

        $assignedUserId =
            rqValidateTenantRow(
                $pdo,
                'users',
                $tenantId,
                $assignedUserId
            );

        $sources = array(
            'office',
            'website',
            'portal',
            'phone',
            'sms',
            'email',
            'ai',
            'other'
        );

        if (!in_array($source,$sources,true)) {
            rqResponse(
                422,
                false,
                'Invalid request source.'
            );
        }

        $priorities = array(
            'low',
            'normal',
            'high',
            'urgent'
        );

        if (!in_array($priority,$priorities,true)) {
            rqResponse(
                422,
                false,
                'Invalid request priority.'
            );
        }

        $statuses = array(
            'new',
            'contacting',
            'information_required',
            'assessment_required',
            'quote_required',
            'job_required',
            'converted',
            'closed',
            'cancelled'
        );

        if (!in_array($status,$statuses,true)) {
            rqResponse(
                422,
                false,
                'Invalid request status.'
            );
        }

        if (
            $preferredFrom !== '' &&
            $preferredTo !== '' &&
            $preferredFrom >= $preferredTo
        ) {
            rqResponse(
                422,
                false,
                'Preferred end time must be after start time.'
            );
        }

        $old = null;

        if ($requestId > 0) {
            $old =
                rqGet(
                    $pdo,
                    $tenantId,
                    $requestId
                );

            if ($old['status'] === 'converted') {
                rqResponse(
                    409,
                    false,
                    'Converted requests cannot be edited from Service Requests.'
                );
            }
        }

        $clientNameStmt = $pdo->prepare("
            SELECT display_name
            FROM clients
            WHERE id = :client_id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");

        $clientNameStmt->execute(array(
            ':client_id'=>$clientId,
            ':tenant_id'=>$tenantId
        ));

        $clientName =
            trim((string)$clientNameStmt->fetchColumn());

        $pdo->beginTransaction();

        try {

            if ($requestId > 0) {

                $stmt =
                    $pdo->prepare("
                        UPDATE service_requests
                        SET
                            branch_id = :branch_id,
                            client_id = :client_id,
                            location_id = :location_id,
                            product_service_id = :service_id,
                            source = :source,
                            priority = :priority,
                            title = :title,
                            description = :description,
                            preferred_date = :preferred_date,
                            preferred_time_from = :preferred_from,
                            preferred_time_to = :preferred_to,
                            assigned_user_id = :assigned_user_id,
                            status = :status
                        WHERE id = :id
                          AND tenant_id = :tenant_id
                    ");

                $stmt->execute(array(
                    ':branch_id'=>$branchId,
                    ':client_id'=>$clientId,
                    ':location_id'=>$locationId,
                    ':service_id'=>$serviceId,
                    ':source'=>$source,
                    ':priority'=>$priority,
                    ':title'=>$title,
                    ':description'=>$description !== '' ? $description : null,
                    ':preferred_date'=>$preferredDate !== '' ? $preferredDate : null,
                    ':preferred_from'=>$preferredFrom !== '' ? $preferredFrom : null,
                    ':preferred_to'=>$preferredTo !== '' ? $preferredTo : null,
                    ':assigned_user_id'=>$assignedUserId,
                    ':status'=>$status,
                    ':id'=>$requestId,
                    ':tenant_id'=>$tenantId
                ));

            } else {

                $requestNo =
                    rqNextNumber(
                        $pdo,
                        $tenantId,
                        $branchId !== null
                            ? $branchId
                            : $sessionBranchId
                    );

                $stmt =
                    $pdo->prepare("
                        INSERT INTO service_requests (
                            tenant_id,
                            branch_id,
                            request_no,
                            client_id,
                            location_id,
                            product_service_id,
                            source,
                            priority,
                            title,
                            description,
                            preferred_date,
                            preferred_time_from,
                            preferred_time_to,
                            assigned_user_id,
                            status,
                            created_by_user_id
                        ) VALUES (
                            :tenant_id,
                            :branch_id,
                            :request_no,
                            :client_id,
                            :location_id,
                            :service_id,
                            :source,
                            :priority,
                            :title,
                            :description,
                            :preferred_date,
                            :preferred_from,
                            :preferred_to,
                            :assigned_user_id,
                            :status,
                            :created_by
                        )
                    ");

                $stmt->execute(array(
                    ':tenant_id'=>$tenantId,
                    ':branch_id'=>$branchId,
                    ':request_no'=>$requestNo,
                    ':client_id'=>$clientId,
                    ':location_id'=>$locationId,
                    ':service_id'=>$serviceId,
                    ':source'=>$source,
                    ':priority'=>$priority,
                    ':title'=>$title,
                    ':description'=>$description !== '' ? $description : null,
                    ':preferred_date'=>$preferredDate !== '' ? $preferredDate : null,
                    ':preferred_from'=>$preferredFrom !== '' ? $preferredFrom : null,
                    ':preferred_to'=>$preferredTo !== '' ? $preferredTo : null,
                    ':assigned_user_id'=>$assignedUserId,
                    ':status'=>$status,
                    ':created_by'=>$userId
                ));

                $requestId =
                    (int)$pdo->lastInsertId();
            }

            if (
                !$old ||
                $old['status'] !== $status
            ) {
                $history =
                    $pdo->prepare("
                        INSERT INTO request_status_history (
                            tenant_id,
                            request_id,
                            old_status,
                            new_status,
                            notes,
                            changed_by
                        ) VALUES (
                            :tenant_id,
                            :request_id,
                            :old_status,
                            :new_status,
                            :notes,
                            :changed_by
                        )
                    ");

                $history->execute(array(
                    ':tenant_id'=>$tenantId,
                    ':request_id'=>$requestId,
                    ':old_status'=>$old
                        ? $old['status']
                        : null,
                    ':new_status'=>$status,
                    ':notes'=>$statusNote !== ''
                        ? $statusNote
                        : null,
                    ':changed_by'=>$userId
                ));
            }

            $pdo->commit();

            $new =
                rqGet(
                    $pdo,
                    $tenantId,
                    $requestId
                );

            rqActivity(
                $pdo,
                $tenantId,
                $branchId !== null
                    ? $branchId
                    : $sessionBranchId,
                $userId,
                $old
                    ? 'service_request_updated'
                    : 'service_request_created',
                $requestId,
                $clientId,
                $old
                    ? 'Service request updated: '.$new['request_no']
                    : 'Service request created: '.$new['request_no'],
                $new
            );

            rqAudit(
                $pdo,
                $tenantId,
                $branchId !== null
                    ? $branchId
                    : $sessionBranchId,
                $userId,
                $old
                    ? 'SERVICE_REQUEST_UPDATED'
                    : 'SERVICE_REQUEST_CREATED',
                $requestId,
                $old,
                $new
            );

            $notificationSummary = array(
                'employee_id'=>$assignedUserId !== null
                    ? (int)$assignedUserId
                    : 0,
                'in_app_sent'=>0,
                'email_queued'=>0,
                'email_sent'=>0,
                'email_skipped'=>0,
                'email_message'=>''
            );

            /*
             * Notify only when an employee is selected and the assignment
             * is new or changed. Editing other request fields will not
             * repeatedly notify the same employee.
             */
            $assignmentChanged =
                $assignedUserId !== null &&
                (int)$assignedUserId > 0 &&
                (
                    !$old ||
                    (int)($old['assigned_user_id'] ?? 0) !==
                    (int)$assignedUserId
                );

            if ($assignmentChanged) {
                $notificationSummary =
                    rqNotifyAssignedEmployee(
                        $pdo,
                        $tenantId,
                        $branchId !== null
                            ? $branchId
                            : $sessionBranchId,
                        $assignedUserId,
                        $requestId,
                        $new['request_no'],
                        $new['title'],
                        $clientName,
                        $notifyInApp,
                        $notifyEmail
                    );
            }

            rqResponse(
                200,
                true,
                $old
                    ? 'Service request updated successfully.'
                    : 'Service request created successfully.',
                array(
                    'request_id'=>$requestId,
                    'request_no'=>$new['request_no'],
                    'notification_summary'=>$notificationSummary
                )
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    if ($action === 'cancel') {

        $requestId =
            (int)rqPost(
                'request_id',
                0
            );

        $notes =
            trim(
                (string)rqPost(
                    'notes',
                    ''
                )
            );

        if ($notes === '') {
            rqResponse(
                422,
                false,
                'Cancellation reason is required.'
            );
        }

        $old =
            rqGet(
                $pdo,
                $tenantId,
                $requestId
            );

        if ($old['status'] === 'converted') {
            rqResponse(
                409,
                false,
                'Converted requests cannot be cancelled.'
            );
        }

        if ($old['status'] === 'cancelled') {
            rqResponse(
                409,
                false,
                'This request is already cancelled.'
            );
        }

        $pdo->beginTransaction();

        try {

            $stmt =
                $pdo->prepare("
                    UPDATE service_requests
                    SET status = 'cancelled'
                    WHERE id = :id
                      AND tenant_id = :tenant_id
                ");

            $stmt->execute(array(
                ':id'=>$requestId,
                ':tenant_id'=>$tenantId
            ));

            $history =
                $pdo->prepare("
                    INSERT INTO request_status_history (
                        tenant_id,
                        request_id,
                        old_status,
                        new_status,
                        notes,
                        changed_by
                    ) VALUES (
                        :tenant_id,
                        :request_id,
                        :old_status,
                        'cancelled',
                        :notes,
                        :changed_by
                    )
                ");

            $history->execute(array(
                ':tenant_id'=>$tenantId,
                ':request_id'=>$requestId,
                ':old_status'=>$old['status'],
                ':notes'=>$notes,
                ':changed_by'=>$userId
            ));

            $pdo->commit();

            $new =
                rqGet(
                    $pdo,
                    $tenantId,
                    $requestId
                );

            rqActivity(
                $pdo,
                $tenantId,
                $sessionBranchId,
                $userId,
                'service_request_cancelled',
                $requestId,
                (int)$old['client_id'],
                'Service request cancelled: '.$old['request_no'],
                array(
                    'reason'=>$notes
                )
            );

            rqAudit(
                $pdo,
                $tenantId,
                $sessionBranchId,
                $userId,
                'SERVICE_REQUEST_CANCELLED',
                $requestId,
                $old,
                $new
            );

            rqResponse(
                200,
                true,
                'Service request cancelled successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    rqResponse(
        400,
        false,
        'Unsupported service request action.'
    );

} catch (PDOException $e) {

    error_log(
        'FieldPlx requests PDO error: ' .
        $e->getMessage()
    );

    if (
        isset($e->errorInfo[1]) &&
        (int)$e->errorInfo[1] === 1062
    ) {
        rqResponse(
            409,
            false,
            'The generated request number already exists. Check Request Number Formatting and try again.'
        );
    }

    rqResponse(
        500,
        false,
        'Unable to process the service request.'
    );

} catch (Throwable $e) {

    error_log(
        'FieldPlx requests API error: ' .
        $e->getMessage()
    );

    rqResponse(
        500,
        false,
        'Unable to process the service request.'
    );
}