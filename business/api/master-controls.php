<?php
ob_start();

ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

header('Content-Type: application/json; charset=utf-8');

/* SMTP secret must never block normal Master Controls loading.
 * The key is loaded lazily only when an SMTP password must be encrypted
 * or decrypted. */
function mcLoadSmtpSecretFile()
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
                error_log('SMTP secret loader: '.$e->getMessage());
                continue;
            }

            if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
                return true;
            }
        }
    }

    return false;
}

require_once __DIR__ . '/../includes/auth.php';

if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function mcResponse($status,$success,$message,$extra=array())
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

function mcPost($key,$default='')
{
    return isset($_POST[$key])
        ? $_POST[$key]
        : $default;
}

function mcJson($value)
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    return $json === false ? null : $json;
}

function mcFetchRow(PDO $pdo,$table,$tenantId,$id)
{
    $allowed = array(
        'branches',
        'departments',
        'smtp_configurations',
        'document_sequences',
        'product_services'
    );

    if (!in_array($table,$allowed,true)) {
        return null;
    }

    $sql = "SELECT * FROM ".$table." WHERE id = :id";

    if ($table === 'smtp_configurations') {
        $sql .= " AND tenant_id = :tenant_id AND scope_type IN ('tenant','branch')";
    } elseif ($table === 'product_services') {
        $sql .= " AND tenant_id = :tenant_id AND item_type = 'service' AND deleted_at IS NULL";
    } else {
        $sql .= " AND tenant_id = :tenant_id";
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':id'=>(int)$id,
        ':tenant_id'=>(int)$tenantId
    ));

    return $stmt->fetch();
}

function mcActivity(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $eventType,
    $relatedType,
    $relatedId,
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
                title,
                details_json,
                visible_to_client
            ) VALUES (
                :tenant_id,
                :branch_id,
                :actor_user_id,
                'user',
                :event_type,
                :related_type,
                :related_id,
                :title,
                :details_json,
                0
            )
        ");

        $stmt->execute(array(
            ':tenant_id'=>(int)$tenantId,
            ':branch_id'=>$branchId > 0 ? (int)$branchId : null,
            ':actor_user_id'=>(int)$userId,
            ':event_type'=>substr((string)$eventType,0,120),
            ':related_type'=>substr((string)$relatedType,0,80),
            ':related_id'=>$relatedId > 0 ? (int)$relatedId : null,
            ':title'=>substr((string)$title,0,255),
            ':details_json'=>mcJson($details)
        ));
    } catch (Throwable $e) {
        error_log('Master controls activity log: '.$e->getMessage());
    }
}

function mcAudit(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $action,
    $objectType,
    $objectId,
    $oldValues,
    $newValues
) {
    if (function_exists('tenantAuditLog')) {
        tenantAuditLog(
            $pdo,
            $action,
            $tenantId,
            $branchId,
            $userId,
            $objectType,
            $objectId,
            $oldValues,
            $newValues
        );
    }
}

function mcSmtpSecretKey()
{
    $key = '';

    mcLoadSmtpSecretFile();

    if (defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        $key = trim((string)FIELDPLX_SMTP_ENCRYPTION_KEY);
    }

    if ($key === '') {
        $env = getenv('FIELDPLX_SMTP_ENCRYPTION_KEY');
        if ($env !== false) {
            $key = trim((string)$env);
        }
    }

    if ($key === '') {
        $env = getenv('APP_KEY');
        if ($env !== false) {
            $key = trim((string)$env);
        }
    }

    if (
        $key === '' ||
        $key === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY'
    ) {
        throw new RuntimeException(
            'FIELDPLX_SMTP_ENCRYPTION_KEY is not configured. Configure the same permanent SMTP encryption key on localhost and live server.'
        );
    }

    if (strlen($key) < 32) {
        throw new RuntimeException(
            'FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.'
        );
    }

    return hash('sha256',$key,true);
}

function mcEncryptPassword($plain,$tenantId)
{
    $plain = (string)$plain;

    if ($plain === '') {
        return null;
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL extension is required for SMTP password encryption.');
    }

    $iv = random_bytes(16);
    $cipher = openssl_encrypt(
        $plain,
        'AES-256-CBC',
        mcSmtpSecretKey(),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($cipher === false) {
        throw new RuntimeException('Unable to encrypt SMTP password.');
    }

    /*
     * v1 format is environment-independent. The same permanent
     * FIELDPLX_SMTP_ENCRYPTION_KEY can decrypt it on localhost/live.
     * tenantId remains in the signature for backward compatibility.
     */
    return 'v1:'.base64_encode($iv.$cipher);
}

function mcDecryptPassword($encrypted,$tenantId)
{
    $encrypted = trim((string)$encrypted);

    if ($encrypted === '') {
        return '';
    }

    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL extension is required for SMTP password decryption.');
    }

    if (strpos($encrypted,'v1:') !== 0) {
        throw new RuntimeException(
            'This SMTP password was saved with the old encryption format. Re-enter the SMTP password and save the configuration once to convert it to the permanent encryption format.'
        );
    }

    $raw = base64_decode(substr($encrypted,3),true);

    if ($raw === false || strlen($raw) <= 16) {
        throw new RuntimeException(
            'Stored SMTP password is invalid. Re-enter the SMTP password and save the configuration.'
        );
    }

    $iv = substr($raw,0,16);
    $cipher = substr($raw,16);

    $plain = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        mcSmtpSecretKey(),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($plain === false) {
        throw new RuntimeException(
            'Unable to decrypt SMTP password. Confirm localhost and live server use the exact same FIELDPLX_SMTP_ENCRYPTION_KEY, then re-enter and save the SMTP password.'
        );
    }

    return $plain;
}

function mcSmtpRead($socket)
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket,515);
        if ($line === false) break;
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return trim($response);
}

function mcSmtpCode($response)
{
    return (int)substr((string)$response,0,3);
}

function mcSmtpCommand($socket,$command,$expected,$label)
{
    if ($command !== null) {
        if (@fwrite($socket,$command."\r\n") === false) {
            throw new RuntimeException('SMTP connection closed while sending '.$label.'.');
        }
    }

    $response = mcSmtpRead($socket);
    $code = mcSmtpCode($response);
    $expected = (array)$expected;

    if (!in_array($code,$expected,true)) {
        $safe = preg_replace('/[\r\n]+/',' ',(string)$response);
        throw new RuntimeException($label.' failed (SMTP '.$code.'): '.substr($safe,0,350));
    }

    return $response;
}

function mcSmtpHeaderValue($value)
{
    return trim(str_replace(array("\r","\n"),' ',(string)$value));
}

function mcSendSmtpTest(array $config,$password,$recipient)
{
    $host = trim((string)$config['host']);
    $port = (int)$config['port'];
    $encryption = strtolower(trim((string)$config['encryption']));
    $username = trim((string)$config['username']);
    $fromEmail = trim((string)$config['from_email']);
    $fromName = trim((string)$config['from_name']);
    $replyTo = trim((string)$config['reply_to_email']);

    if ($host === '') throw new RuntimeException('SMTP host is empty.');
    if ($port < 1 || $port > 65535) throw new RuntimeException('SMTP port is invalid.');
    if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid test recipient email.');
    if (!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP From Email must be a valid email address.');

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
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
        throw new RuntimeException('Unable to connect to SMTP server: '.($errstr !== '' ? $errstr : 'connection failed').' ('.$errno.').');
    }

    stream_set_timeout($socket,20);

    try {
        mcSmtpCommand($socket,null,array(220),'SMTP greeting');

        $ehloHost = isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== ''
            ? preg_replace('/[^A-Za-z0-9.\-]/','',$_SERVER['SERVER_NAME'])
            : 'fieldplx.local';
        if ($ehloHost === '') $ehloHost = 'fieldplx.local';

        mcSmtpCommand($socket,'EHLO '.$ehloHost,array(250),'EHLO');

        if ($encryption === 'tls' || $encryption === 'starttls') {
            mcSmtpCommand($socket,'STARTTLS',array(220),'STARTTLS');

            $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ? STREAM_CRYPTO_METHOD_TLS_CLIENT
                : STREAM_CRYPTO_METHOD_SSLv23_CLIENT;

            $crypto = @stream_socket_enable_crypto($socket,true,$cryptoMethod);
            if ($crypto !== true) {
                throw new RuntimeException('Unable to establish TLS encryption with the SMTP server.');
            }

            mcSmtpCommand($socket,'EHLO '.$ehloHost,array(250),'EHLO after TLS');
        }

        if ($username !== '') {
            if ($password === '') throw new RuntimeException('SMTP password is empty.');
            mcSmtpCommand($socket,'AUTH LOGIN',array(334),'SMTP authentication');
            mcSmtpCommand($socket,base64_encode($username),array(334),'SMTP username');
            mcSmtpCommand($socket,base64_encode($password),array(235),'SMTP password');
        }

        mcSmtpCommand($socket,'MAIL FROM:<'.$fromEmail.'>',array(250),'MAIL FROM');
        mcSmtpCommand($socket,'RCPT TO:<'.$recipient.'>',array(250,251),'RCPT TO');
        mcSmtpCommand($socket,'DATA',array(354),'DATA');

        $subject = 'FieldPlx SMTP Test Email';
        $displayName = $fromName !== '' ? mcSmtpHeaderValue($fromName) : 'FieldPlx';
        $messageIdHost = preg_replace('/[^A-Za-z0-9.\-]/','',$host);
        if ($messageIdHost === '') $messageIdHost = 'fieldplx.local';

        $body = "FieldPlx SMTP Test\r\n\r\n" .
                "This test email confirms that your SMTP configuration is working correctly.\r\n" .
                "Configuration: ".mcSmtpHeaderValue($config['config_name'])."\r\n" .
                "SMTP Host: ".mcSmtpHeaderValue($host).":".$port."\r\n" .
                "Encryption: ".mcSmtpHeaderValue($encryption)."\r\n" .
                "Sent At: ".date('Y-m-d H:i:s')."\r\n\r\n" .
                "FieldPlx";

        $headers = array(
            'Date: '.date(DATE_RFC2822),
            'From: '.$displayName.' <'.$fromEmail.'>',
            'To: <'.$recipient.'>',
            'Subject: '.$subject,
            'Message-ID: <'.bin2hex(random_bytes(10)).'@'.$messageIdHost.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        );

        if ($replyTo !== '' && filter_var($replyTo,FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: <'.$replyTo.'>';
        }

        $payload = implode("\r\n",$headers)."\r\n\r\n".$body;
        $payload = preg_replace('/(?m)^\./','..',$payload);

        if (@fwrite($socket,$payload."\r\n.\r\n") === false) {
            throw new RuntimeException('Unable to send SMTP message data.');
        }

        mcSmtpCommand($socket,null,array(250),'Message delivery');
        @fwrite($socket,"QUIT\r\n");
    } finally {
        @fclose($socket);
    }

    return true;
}

$tenantId = isset($_SESSION['tenant_id'])
    ? (int)$_SESSION['tenant_id']
    : 0;

$userId = isset($_SESSION['tenant_user_id'])
    ? (int)$_SESSION['tenant_user_id']
    : 0;

$sessionBranchId = isset($_SESSION['branch_id'])
    ? (int)$_SESSION['branch_id']
    : 0;

if ($tenantId <= 0 || $userId <= 0) {
    mcResponse(401,false,'Authentication required.');
}

$csrf = (string)mcPost('csrf_token','');
$sessionCsrf = isset($_SESSION['master_controls_csrf_token'])
    ? (string)$_SESSION['master_controls_csrf_token']
    : '';

if (
    $csrf === '' ||
    $sessionCsrf === '' ||
    !hash_equals($sessionCsrf,$csrf)
) {
    mcResponse(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action = trim((string)mcPost('action',''));

try {

    if ($action === 'load_all') {

        $branchesStmt = $pdo->prepare("
            SELECT
                b.*,
                c.name AS country_name,
                cur.currency_code
            FROM branches b
            LEFT JOIN countries c
                ON c.id = b.country_id
            LEFT JOIN currencies cur
                ON cur.id = b.currency_id
            WHERE b.tenant_id = :tenant_id
            ORDER BY
                b.is_head_office DESC,
                b.name ASC
        ");
        $branchesStmt->execute(array(':tenant_id'=>$tenantId));
        $branches = $branchesStmt->fetchAll();

        $departmentsStmt = $pdo->prepare("
            SELECT
                d.*,
                b.name AS branch_name
            FROM departments d
            LEFT JOIN branches b
                ON b.id = d.branch_id
               AND b.tenant_id = d.tenant_id
            WHERE d.tenant_id = :tenant_id
            ORDER BY d.name ASC
        ");
        $departmentsStmt->execute(array(':tenant_id'=>$tenantId));
        $departments = $departmentsStmt->fetchAll();

        $servicesStmt = $pdo->prepare("
            SELECT
                ps.id,
                ps.tenant_id,
                ps.item_type,
                ps.name,
                ps.sku,
                ps.description,
                ps.unit_name,
                ps.unit_cost,
                ps.unit_price,
                ps.tax_percent,
                ps.is_bookable,
                ps.estimated_duration_minutes,
                ps.status,
                ps.created_at,
                ps.updated_at
            FROM product_services ps
            WHERE ps.tenant_id = :tenant_id
              AND ps.item_type = 'service'
              AND ps.deleted_at IS NULL
            ORDER BY
                FIELD(ps.status,'active','inactive','archived'),
                ps.name ASC
        ");
        $servicesStmt->execute(array(':tenant_id'=>$tenantId));
        $services = $servicesStmt->fetchAll();

        $smtpStmt = $pdo->prepare("
            SELECT
                s.id,
                s.scope_type,
                s.tenant_id,
                s.branch_id,
                s.config_name,
                s.host,
                s.port,
                s.encryption,
                s.username,
                s.from_name,
                s.from_email,
                s.reply_to_email,
                s.is_default,
                s.is_active,
                s.last_test_status,
                s.last_test_message,
                s.last_tested_at,
                s.created_at,
                s.updated_at,
                b.name AS branch_name
            FROM smtp_configurations s
            LEFT JOIN branches b
                ON b.id = s.branch_id
               AND b.tenant_id = s.tenant_id
            WHERE s.tenant_id = :tenant_id
              AND s.scope_type IN ('tenant','branch')
            ORDER BY
                s.is_default DESC,
                s.config_name ASC
        ");
        $smtpStmt->execute(array(':tenant_id'=>$tenantId));
        $smtp = $smtpStmt->fetchAll();

        $countries = $pdo->query("
            SELECT id,name,iso2
            FROM countries
            WHERE is_active = 1
            ORDER BY name ASC
        ")->fetchAll();

        $currencies = $pdo->query("
            SELECT id,currency_code,currency_name,symbol
            FROM currencies
            WHERE is_active = 1
            ORDER BY currency_code ASC
        ")->fetchAll();

        $tenantCurrencyStmt = $pdo->prepare("
            SELECT
                c.id,
                c.currency_code,
                c.currency_name,
                c.symbol,
                c.symbol_position,
                c.decimal_places
            FROM tenants t
            INNER JOIN currencies c
                ON c.id = t.currency_id
            WHERE t.id = :tenant_id
              AND t.deleted_at IS NULL
            LIMIT 1
        ");
        $tenantCurrencyStmt->execute(array(
            ':tenant_id'=>$tenantId
        ));
        $tenantCurrency = $tenantCurrencyStmt->fetch();

        if (!$tenantCurrency) {
            $tenantCurrency = array(
                'id'=>null,
                'currency_code'=>'',
                'currency_name'=>'',
                'symbol'=>'',
                'symbol_position'=>'before',
                'decimal_places'=>2
            );
        }

        $sequenceStmt = $pdo->prepare("
            SELECT *
            FROM document_sequences
            WHERE tenant_id = :tenant_id
              AND document_type IN ('invoice','quote','request')
            ORDER BY
                CASE document_type
                    WHEN 'invoice' THEN 1
                    WHEN 'quote' THEN 2
                    WHEN 'request' THEN 3
                    ELSE 4
                END,
                branch_id IS NULL DESC,
                id DESC
        ");
        $sequenceStmt->execute(array(':tenant_id'=>$tenantId));

        $sequences = array();

        foreach ($sequenceStmt->fetchAll() as $row) {
            $type = (string)$row['document_type'];

            /*
             * Prefer tenant-default sequence for the card.
             * If none exists, show newest branch-specific row.
             */
            if (!isset($sequences[$type])) {
                $sequences[$type] = $row;
            }

            if ($row['branch_id'] === null) {
                $sequences[$type] = $row;
            }
        }

        mcResponse(
            200,
            true,
            'Master controls loaded.',
            array(
                'branches'=>$branches,
                'departments'=>$departments,
                'services'=>$services,
                'smtp'=>$smtp,
                'sequences'=>$sequences,
                'meta'=>array(
                    'countries'=>$countries,
                    'currencies'=>$currencies,
                    'branches'=>$branches,
                    'tenant_currency'=>$tenantCurrency
                )
            )
        );
    }

    if ($action === 'save_branch') {

        $id = (int)mcPost('id',0);
        $old = $id > 0
            ? mcFetchRow($pdo,'branches',$tenantId,$id)
            : null;

        if ($id > 0 && !$old) {
            mcResponse(404,false,'Branch not found.');
        }

        $name = trim((string)mcPost('name',''));
        $code = trim((string)mcPost('branch_code',''));
        $status = trim((string)mcPost('status','active'));

        if ($name === '' || $code === '') {
            mcResponse(422,false,'Branch name and code are required.');
        }

        if (!in_array($status,array('active','inactive','archived'),true)) {
            mcResponse(422,false,'Invalid branch status.');
        }

        $countryId = (int)mcPost('country_id',0);
        $currencyId = (int)mcPost('currency_id',0);
        $isHead = mcPost('is_head_office','') !== '' ? 1 : 0;

        if ($isHead === 1) {
            $clear = $pdo->prepare("
                UPDATE branches
                SET is_head_office = 0
                WHERE tenant_id = :tenant_id
            ");
            $clear->execute(array(':tenant_id'=>$tenantId));
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE branches
                SET
                    branch_code = :branch_code,
                    name = :name,
                    email = :email,
                    phone = :phone,
                    address_line1 = :address_line1,
                    address_line2 = :address_line2,
                    city = :city,
                    state = :state,
                    postal_code = :postal_code,
                    country_id = :country_id,
                    currency_id = :currency_id,
                    timezone = :timezone,
                    is_head_office = :is_head_office,
                    status = :status
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");

            $stmt->execute(array(
                ':branch_code'=>$code,
                ':name'=>$name,
                ':email'=>trim((string)mcPost('email','')) ?: null,
                ':phone'=>trim((string)mcPost('phone','')) ?: null,
                ':address_line1'=>trim((string)mcPost('address_line1','')) ?: null,
                ':address_line2'=>trim((string)mcPost('address_line2','')) ?: null,
                ':city'=>trim((string)mcPost('city','')) ?: null,
                ':state'=>trim((string)mcPost('state','')) ?: null,
                ':postal_code'=>trim((string)mcPost('postal_code','')) ?: null,
                ':country_id'=>$countryId > 0 ? $countryId : null,
                ':currency_id'=>$currencyId > 0 ? $currencyId : null,
                ':timezone'=>trim((string)mcPost('timezone','')) ?: null,
                ':is_head_office'=>$isHead,
                ':status'=>$status,
                ':id'=>$id,
                ':tenant_id'=>$tenantId
            ));
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO branches (
                    tenant_id,
                    branch_code,
                    name,
                    email,
                    phone,
                    address_line1,
                    address_line2,
                    city,
                    state,
                    postal_code,
                    country_id,
                    currency_id,
                    timezone,
                    is_head_office,
                    status
                ) VALUES (
                    :tenant_id,
                    :branch_code,
                    :name,
                    :email,
                    :phone,
                    :address_line1,
                    :address_line2,
                    :city,
                    :state,
                    :postal_code,
                    :country_id,
                    :currency_id,
                    :timezone,
                    :is_head_office,
                    :status
                )
            ");

            $stmt->execute(array(
                ':tenant_id'=>$tenantId,
                ':branch_code'=>$code,
                ':name'=>$name,
                ':email'=>trim((string)mcPost('email','')) ?: null,
                ':phone'=>trim((string)mcPost('phone','')) ?: null,
                ':address_line1'=>trim((string)mcPost('address_line1','')) ?: null,
                ':address_line2'=>trim((string)mcPost('address_line2','')) ?: null,
                ':city'=>trim((string)mcPost('city','')) ?: null,
                ':state'=>trim((string)mcPost('state','')) ?: null,
                ':postal_code'=>trim((string)mcPost('postal_code','')) ?: null,
                ':country_id'=>$countryId > 0 ? $countryId : null,
                ':currency_id'=>$currencyId > 0 ? $currencyId : null,
                ':timezone'=>trim((string)mcPost('timezone','')) ?: null,
                ':is_head_office'=>$isHead,
                ':status'=>$status
            ));

            $id = (int)$pdo->lastInsertId();
        }

        $new = mcFetchRow($pdo,'branches',$tenantId,$id);

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $old ? 'branch_updated' : 'branch_created',
            'branch',$id,
            $old ? 'Branch updated: '.$name : 'Branch created: '.$name,
            $new
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $old ? 'BRANCH_UPDATED' : 'BRANCH_CREATED',
            'branch',$id,$old,$new
        );

        mcResponse(200,true,$old ? 'Branch updated successfully.' : 'Branch created successfully.');
    }

    if ($action === 'delete_branch') {

        $id = (int)mcPost('id',0);
        $old = mcFetchRow($pdo,'branches',$tenantId,$id);

        if (!$old) {
            mcResponse(404,false,'Branch not found.');
        }

        $usersStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM users
            WHERE tenant_id = :tenant_id
              AND branch_id = :branch_id
              AND deleted_at IS NULL
        ");
        $usersStmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':branch_id'=>$id
        ));

        if ((int)$usersStmt->fetchColumn() > 0) {
            mcResponse(409,false,'This branch is assigned to employees. Reassign them before deleting the branch.');
        }

        $stmt = $pdo->prepare("
            DELETE FROM branches
            WHERE id = :id
              AND tenant_id = :tenant_id
        ");
        $stmt->execute(array(
            ':id'=>$id,
            ':tenant_id'=>$tenantId
        ));

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'branch_deleted','branch',$id,
            'Branch deleted: '.$old['name'],$old
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'BRANCH_DELETED','branch',$id,$old,null
        );

        mcResponse(200,true,'Branch deleted successfully.');
    }

    if ($action === 'save_department') {

        $id = (int)mcPost('id',0);
        $old = $id > 0
            ? mcFetchRow($pdo,'departments',$tenantId,$id)
            : null;

        if ($id > 0 && !$old) {
            mcResponse(404,false,'Department not found.');
        }

        $name = trim((string)mcPost('name',''));
        $status = trim((string)mcPost('status','active'));
        $branchId = (int)mcPost('branch_id',0);

        if ($name === '') {
            mcResponse(422,false,'Department name is required.');
        }

        if (!in_array($status,array('active','inactive'),true)) {
            mcResponse(422,false,'Invalid department status.');
        }

        if ($branchId > 0) {
            $branchCheck = $pdo->prepare("
                SELECT id
                FROM branches
                WHERE id = :id
                  AND tenant_id = :tenant_id
                LIMIT 1
            ");
            $branchCheck->execute(array(
                ':id'=>$branchId,
                ':tenant_id'=>$tenantId
            ));

            if (!$branchCheck->fetchColumn()) {
                mcResponse(422,false,'Selected branch is invalid.');
            }
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE departments
                SET
                    branch_id = :branch_id,
                    name = :name,
                    code = :code,
                    description = :description,
                    status = :status
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");

            $stmt->execute(array(
                ':branch_id'=>$branchId > 0 ? $branchId : null,
                ':name'=>$name,
                ':code'=>trim((string)mcPost('code','')) ?: null,
                ':description'=>trim((string)mcPost('description','')) ?: null,
                ':status'=>$status,
                ':id'=>$id,
                ':tenant_id'=>$tenantId
            ));
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO departments (
                    tenant_id,
                    branch_id,
                    name,
                    code,
                    description,
                    status
                ) VALUES (
                    :tenant_id,
                    :branch_id,
                    :name,
                    :code,
                    :description,
                    :status
                )
            ");

            $stmt->execute(array(
                ':tenant_id'=>$tenantId,
                ':branch_id'=>$branchId > 0 ? $branchId : null,
                ':name'=>$name,
                ':code'=>trim((string)mcPost('code','')) ?: null,
                ':description'=>trim((string)mcPost('description','')) ?: null,
                ':status'=>$status
            ));

            $id = (int)$pdo->lastInsertId();
        }

        $new = mcFetchRow($pdo,'departments',$tenantId,$id);

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $old ? 'department_updated' : 'department_created',
            'department',$id,
            $old ? 'Department updated: '.$name : 'Department created: '.$name,
            $new
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $old ? 'DEPARTMENT_UPDATED' : 'DEPARTMENT_CREATED',
            'department',$id,$old,$new
        );

        mcResponse(200,true,$old ? 'Department updated successfully.' : 'Department created successfully.');
    }

    if ($action === 'delete_department') {

        $id = (int)mcPost('id',0);
        $old = mcFetchRow($pdo,'departments',$tenantId,$id);

        if (!$old) {
            mcResponse(404,false,'Department not found.');
        }

        $usersStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM users
            WHERE tenant_id = :tenant_id
              AND department_id = :department_id
              AND deleted_at IS NULL
        ");
        $usersStmt->execute(array(
            ':tenant_id'=>$tenantId,
            ':department_id'=>$id
        ));

        if ((int)$usersStmt->fetchColumn() > 0) {
            mcResponse(409,false,'This department is assigned to employees. Reassign them before deleting it.');
        }

        $stmt = $pdo->prepare("
            DELETE FROM departments
            WHERE id = :id
              AND tenant_id = :tenant_id
        ");
        $stmt->execute(array(
            ':id'=>$id,
            ':tenant_id'=>$tenantId
        ));

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'department_deleted','department',$id,
            'Department deleted: '.$old['name'],$old
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'DEPARTMENT_DELETED','department',$id,$old,null
        );

        mcResponse(200,true,'Department deleted successfully.');
    }

    if ($action === 'save_service') {

        $id = (int)mcPost('id',0);

        $old = $id > 0
            ? mcFetchRow($pdo,'product_services',$tenantId,$id)
            : null;

        if ($id > 0 && !$old) {
            mcResponse(404,false,'Service not found.');
        }

        $name = trim((string)mcPost('name',''));
        $sku = trim((string)mcPost('sku',''));
        $description = trim((string)mcPost('description',''));
        $unitName = trim((string)mcPost('unit_name',''));
        $unitCostRaw = trim((string)mcPost('unit_cost','0'));
        $unitPriceRaw = trim((string)mcPost('unit_price','0'));
        $taxPercentRaw = trim((string)mcPost('tax_percent','0'));
        $durationRaw = trim((string)mcPost('estimated_duration_minutes',''));
        $status = trim((string)mcPost('status','active'));
        $isBookable = mcPost('is_bookable','') !== '' ? 1 : 0;

        if ($name === '') {
            mcResponse(422,false,'Service name is required.');
        }

        if (!is_numeric($unitCostRaw) || (float)$unitCostRaw < 0) {
            mcResponse(422,false,'Unit cost must be a valid non-negative amount.');
        }

        if (!is_numeric($unitPriceRaw) || (float)$unitPriceRaw < 0) {
            mcResponse(422,false,'Unit price must be a valid non-negative amount.');
        }

        if (!in_array($status,array('active','inactive','archived'),true)) {
            mcResponse(422,false,'Invalid service status.');
        }

        $duration = null;

        if ($durationRaw !== '') {
            if (!ctype_digit($durationRaw)) {
                mcResponse(422,false,'Estimated duration must be a whole number of minutes.');
            }

            $duration = (int)$durationRaw;
        }

        if (!is_numeric($taxPercentRaw)) {
            mcResponse(422,false,'Tax percent must be a valid number.');
        }

        $taxPercent = (float)$taxPercentRaw;

        if ($taxPercent < 0 || $taxPercent > 100) {
            mcResponse(422,false,'Tax percent must be between 0 and 100.');
        }

        /*
         * Keep service name unique among non-deleted tenant services.
         */
        $nameSql = "
            SELECT id
            FROM product_services
            WHERE tenant_id = :tenant_id
              AND item_type = 'service'
              AND name = :name
              AND deleted_at IS NULL
        ";

        $nameParams = array(
            ':tenant_id'=>$tenantId,
            ':name'=>$name
        );

        if ($id > 0) {
            $nameSql .= " AND id <> :id";
            $nameParams[':id']=$id;
        }

        $nameCheck = $pdo->prepare($nameSql);
        $nameCheck->execute($nameParams);

        if ($nameCheck->fetchColumn()) {
            mcResponse(409,false,'A service with this name already exists.');
        }

        if ($sku !== '') {
            $skuSql = "
                SELECT id
                FROM product_services
                WHERE tenant_id = :tenant_id
                  AND sku = :sku
                  AND deleted_at IS NULL
            ";

            $skuParams = array(
                ':tenant_id'=>$tenantId,
                ':sku'=>$sku
            );

            if ($id > 0) {
                $skuSql .= " AND id <> :id";
                $skuParams[':id']=$id;
            }

            $skuCheck = $pdo->prepare($skuSql);
            $skuCheck->execute($skuParams);

            if ($skuCheck->fetchColumn()) {
                mcResponse(409,false,'This SKU / service code is already in use.');
            }
        }

        if ($id > 0) {

            $stmt = $pdo->prepare("
                UPDATE product_services
                SET
                    name = :name,
                    sku = :sku,
                    description = :description,
                    unit_name = :unit_name,
                    unit_cost = :unit_cost,
                    unit_price = :unit_price,
                    tax_percent = :tax_percent,
                    is_bookable = :is_bookable,
                    estimated_duration_minutes = :duration,
                    status = :status
                WHERE id = :id
                  AND tenant_id = :tenant_id
                  AND item_type = 'service'
                  AND deleted_at IS NULL
            ");

            $stmt->execute(array(
                ':name'=>$name,
                ':sku'=>$sku !== '' ? $sku : null,
                ':description'=>$description !== '' ? $description : null,
                ':unit_name'=>$unitName !== '' ? $unitName : null,
                ':unit_cost'=>number_format((float)$unitCostRaw,2,'.',''),
                ':unit_price'=>number_format((float)$unitPriceRaw,2,'.',''),
                ':tax_percent'=>number_format($taxPercent,4,'.',''),
                ':is_bookable'=>$isBookable,
                ':duration'=>$duration,
                ':status'=>$status,
                ':id'=>$id,
                ':tenant_id'=>$tenantId
            ));

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO product_services (
                    tenant_id,
                    item_type,
                    name,
                    sku,
                    description,
                    unit_name,
                    unit_cost,
                    unit_price,
                    tax_percent,
                    is_bookable,
                    estimated_duration_minutes,
                    status
                ) VALUES (
                    :tenant_id,
                    'service',
                    :name,
                    :sku,
                    :description,
                    :unit_name,
                    :unit_cost,
                    :unit_price,
                    :tax_percent,
                    :is_bookable,
                    :duration,
                    :status
                )
            ");

            $stmt->execute(array(
                ':tenant_id'=>$tenantId,
                ':name'=>$name,
                ':sku'=>$sku !== '' ? $sku : null,
                ':description'=>$description !== '' ? $description : null,
                ':unit_name'=>$unitName !== '' ? $unitName : null,
                ':unit_cost'=>number_format((float)$unitCostRaw,2,'.',''),
                ':unit_price'=>number_format((float)$unitPriceRaw,2,'.',''),
                ':tax_percent'=>number_format($taxPercent,4,'.',''),
                ':is_bookable'=>$isBookable,
                ':duration'=>$duration,
                ':status'=>$status
            ));

            $id = (int)$pdo->lastInsertId();
        }

        $new = mcFetchRow($pdo,'product_services',$tenantId,$id);

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $old ? 'service_updated' : 'service_created',
            'service',$id,
            $old ? 'Service updated: '.$name : 'Service created: '.$name,
            $new
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $old ? 'SERVICE_UPDATED' : 'SERVICE_CREATED',
            'service',$id,$old,$new
        );

        mcResponse(
            200,
            true,
            $old
                ? 'Service updated successfully.'
                : 'Service created successfully.'
        );
    }

    if ($action === 'delete_service') {

        $id = (int)mcPost('id',0);

        $old = mcFetchRow(
            $pdo,
            'product_services',
            $tenantId,
            $id
        );

        if (!$old) {
            mcResponse(404,false,'Service not found.');
        }

        /*
         * Soft-delete protects old quotes/jobs/invoices and historical reports.
         */
        $stmt = $pdo->prepare("
            UPDATE product_services
            SET
                status = 'archived',
                deleted_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND item_type = 'service'
              AND deleted_at IS NULL
        ");

        $stmt->execute(array(
            ':id'=>$id,
            ':tenant_id'=>$tenantId
        ));

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'service_archived',
            'service',$id,
            'Service archived: '.$old['name'],
            $old
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'SERVICE_ARCHIVED',
            'service',$id,
            $old,
            array(
                'status'=>'archived',
                'deleted_at'=>date('Y-m-d H:i:s')
            )
        );

        mcResponse(
            200,
            true,
            'Service archived successfully.'
        );
    }

    if ($action === 'save_smtp') {

        $id = (int)mcPost('id',0);
        $old = $id > 0
            ? mcFetchRow($pdo,'smtp_configurations',$tenantId,$id)
            : null;

        if ($id > 0 && !$old) {
            mcResponse(404,false,'SMTP configuration not found.');
        }

        $scope = trim((string)mcPost('scope_type','tenant'));
        $branchId = (int)mcPost('branch_id',0);
        $name = trim((string)mcPost('config_name',''));
        $host = trim((string)mcPost('host',''));
        $port = (int)mcPost('port',587);
        $encryption = trim((string)mcPost('encryption','tls'));
        $password = (string)mcPost('password','');
        $changePassword = mcPost('change_password','') !== '' ? 1 : 0;
        $isDefault = mcPost('is_default','') !== '' ? 1 : 0;
        $isActive = mcPost('is_active','') !== '' ? 1 : 0;

        if (!in_array($scope,array('tenant','branch'),true)) {
            mcResponse(422,false,'Invalid SMTP scope.');
        }

        if ($name === '' || $host === '') {
            mcResponse(422,false,'SMTP configuration name and host are required.');
        }

        if ($port < 1 || $port > 65535) {
            mcResponse(422,false,'SMTP port is invalid.');
        }

        if (!in_array($encryption,array('none','ssl','tls','starttls'),true)) {
            mcResponse(422,false,'Invalid SMTP encryption.');
        }

        if ($scope === 'branch') {
            if ($branchId <= 0) {
                mcResponse(422,false,'Select a branch for branch SMTP.');
            }

            $branchCheck = $pdo->prepare("
                SELECT id
                FROM branches
                WHERE id = :id
                  AND tenant_id = :tenant_id
                LIMIT 1
            ");
            $branchCheck->execute(array(
                ':id'=>$branchId,
                ':tenant_id'=>$tenantId
            ));

            if (!$branchCheck->fetchColumn()) {
                mcResponse(422,false,'Selected branch is invalid.');
            }
        } else {
            $branchId = 0;
        }

        if ($isDefault === 1) {
            $clear = $pdo->prepare("
                UPDATE smtp_configurations
                SET is_default = 0
                WHERE tenant_id = :tenant_id
                  AND scope_type IN ('tenant','branch')
            ");
            $clear->execute(array(':tenant_id'=>$tenantId));
        }

        /* Existing SMTP password is changed only when the user explicitly
         * enables Change SMTP Password. This blocks browser/password-manager
         * autofill from silently replacing the stored SMTP password. */
        $passwordEncrypted = null;

        if ($id > 0) {
            if ($changePassword === 1) {
                if (trim($password) === '') {
                    mcResponse(422,false,'Enter the new SMTP password.');
                }
                $passwordEncrypted = mcEncryptPassword($password,$tenantId);
            }
        } else {
            if (trim($password) === '') {
                mcResponse(422,false,'SMTP password is required for a new configuration.');
            }
            $passwordEncrypted = mcEncryptPassword($password,$tenantId);
        }

        if ($id > 0) {

            $sql = "
                UPDATE smtp_configurations
                SET
                    scope_type = :scope_type,
                    branch_id = :branch_id,
                    config_name = :config_name,
                    host = :host,
                    port = :port,
                    encryption = :encryption,
                    username = :username,
                    from_name = :from_name,
                    from_email = :from_email,
                    reply_to_email = :reply_to_email,
                    is_default = :is_default,
                    is_active = :is_active
            ";

            $params = array(
                ':scope_type'=>$scope,
                ':branch_id'=>$branchId > 0 ? $branchId : null,
                ':config_name'=>$name,
                ':host'=>$host,
                ':port'=>$port,
                ':encryption'=>$encryption,
                ':username'=>trim((string)mcPost('username','')) ?: null,
                ':from_name'=>trim((string)mcPost('from_name','')) ?: null,
                ':from_email'=>trim((string)mcPost('from_email','')) ?: null,
                ':reply_to_email'=>trim((string)mcPost('reply_to_email','')) ?: null,
                ':is_default'=>$isDefault,
                ':is_active'=>$isActive,
                ':id'=>$id,
                ':tenant_id'=>$tenantId
            );

            if ($passwordEncrypted !== null) {
                $sql .= ", password_encrypted = :password_encrypted";
                $params[':password_encrypted'] = $passwordEncrypted;
            }

            $sql .= "
                WHERE id = :id
                  AND tenant_id = :tenant_id
                  AND scope_type IN ('tenant','branch')
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO smtp_configurations (
                    scope_type,
                    tenant_id,
                    branch_id,
                    config_name,
                    host,
                    port,
                    encryption,
                    username,
                    password_encrypted,
                    from_name,
                    from_email,
                    reply_to_email,
                    is_default,
                    is_active,
                    created_by_tenant_user_id
                ) VALUES (
                    :scope_type,
                    :tenant_id,
                    :branch_id,
                    :config_name,
                    :host,
                    :port,
                    :encryption,
                    :username,
                    :password_encrypted,
                    :from_name,
                    :from_email,
                    :reply_to_email,
                    :is_default,
                    :is_active,
                    :created_by
                )
            ");

            $stmt->execute(array(
                ':scope_type'=>$scope,
                ':tenant_id'=>$tenantId,
                ':branch_id'=>$branchId > 0 ? $branchId : null,
                ':config_name'=>$name,
                ':host'=>$host,
                ':port'=>$port,
                ':encryption'=>$encryption,
                ':username'=>trim((string)mcPost('username','')) ?: null,
                ':password_encrypted'=>$passwordEncrypted,
                ':from_name'=>trim((string)mcPost('from_name','')) ?: null,
                ':from_email'=>trim((string)mcPost('from_email','')) ?: null,
                ':reply_to_email'=>trim((string)mcPost('reply_to_email','')) ?: null,
                ':is_default'=>$isDefault,
                ':is_active'=>$isActive,
                ':created_by'=>$userId
            ));

            $id = (int)$pdo->lastInsertId();
        }

        $new = mcFetchRow($pdo,'smtp_configurations',$tenantId,$id);

        if (is_array($old)) {
            unset($old['password_encrypted']);
        }

        if (is_array($new)) {
            unset($new['password_encrypted']);
        }

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $old ? 'smtp_updated' : 'smtp_created',
            'smtp_configuration',$id,
            $old ? 'SMTP updated: '.$name : 'SMTP created: '.$name,
            $new
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $old ? 'SMTP_UPDATED' : 'SMTP_CREATED',
            'smtp_configuration',$id,$old,$new
        );

        mcResponse(200,true,$old ? 'SMTP configuration updated successfully.' : 'SMTP configuration created successfully.');
    }

    if ($action === 'test_smtp') {

        $id = (int)mcPost('id',0);
        $recipient = trim((string)mcPost('test_email',''));
        $smtp = mcFetchRow($pdo,'smtp_configurations',$tenantId,$id);

        if (!$smtp) {
            mcResponse(404,false,'SMTP configuration not found.');
        }

        if ((int)$smtp['is_active'] !== 1) {
            mcResponse(409,false,'Activate this SMTP configuration before testing it.');
        }

        if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)) {
            mcResponse(422,false,'Enter a valid test recipient email address.');
        }

        $testStatus = 'failed';
        $testMessage = '';

        try {
            $password = mcDecryptPassword($smtp['password_encrypted'],$tenantId);
            mcSendSmtpTest($smtp,$password,$recipient);
            $testStatus = 'success';
            $testMessage = 'Test email sent successfully to '.$recipient.'.';
        } catch (Throwable $testError) {
            $testStatus = 'failed';
            $testMessage = substr($testError->getMessage(),0,2000);
        }

        $update = $pdo->prepare("\n            UPDATE smtp_configurations\n            SET last_test_status = :status,\n                last_test_message = :message,\n                last_tested_at = NOW()\n            WHERE id = :id\n              AND tenant_id = :tenant_id\n              AND scope_type IN ('tenant','branch')\n        ");
        $update->execute(array(
            ':status'=>$testStatus,
            ':message'=>$testMessage,
            ':id'=>$id,
            ':tenant_id'=>$tenantId
        ));

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $testStatus === 'success' ? 'smtp_test_success' : 'smtp_test_failed',
            'smtp_configuration',$id,
            ($testStatus === 'success' ? 'SMTP test succeeded: ' : 'SMTP test failed: ').$smtp['config_name'],
            array(
                'recipient'=>$recipient,
                'status'=>$testStatus,
                'message'=>$testMessage
            )
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            $testStatus === 'success' ? 'SMTP_TEST_SUCCESS' : 'SMTP_TEST_FAILED',
            'smtp_configuration',$id,null,
            array(
                'recipient'=>$recipient,
                'status'=>$testStatus,
                'message'=>$testMessage
            )
        );

        if ($testStatus !== 'success') {
            mcResponse(422,false,'SMTP test failed: '.$testMessage,array(
                'test_status'=>$testStatus,
                'test_message'=>$testMessage
            ));
        }

        mcResponse(200,true,$testMessage,array(
            'test_status'=>$testStatus,
            'test_message'=>$testMessage
        ));
    }

    if ($action === 'delete_smtp') {

        $id = (int)mcPost('id',0);
        $old = mcFetchRow($pdo,'smtp_configurations',$tenantId,$id);

        if (!$old) {
            mcResponse(404,false,'SMTP configuration not found.');
        }

        $stmt = $pdo->prepare("
            DELETE FROM smtp_configurations
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND scope_type IN ('tenant','branch')
        ");
        $stmt->execute(array(
            ':id'=>$id,
            ':tenant_id'=>$tenantId
        ));

        unset($old['password_encrypted']);

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'smtp_deleted','smtp_configuration',$id,
            'SMTP deleted: '.$old['config_name'],$old
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'SMTP_DELETED','smtp_configuration',$id,$old,null
        );

        mcResponse(200,true,'SMTP configuration deleted successfully.');
    }

    if ($action === 'save_sequence') {

        $documentType = trim((string)mcPost('document_type',''));

        if (!in_array($documentType,array('invoice','quote','request'),true)) {
            mcResponse(422,false,'Invalid document type.');
        }

        $branchId = (int)mcPost('branch_id',0);
        $middle = trim((string)mcPost('middle_format','none'));
        $reset = trim((string)mcPost('reset_period','never'));
        $length = (int)mcPost('number_length',6);
        $current = (int)mcPost('current_number',0);
        $fyMonth = (int)mcPost('financial_year_start_month',4);
        $isActive = mcPost('is_active','') !== '' ? 1 : 0;

        if (!in_array($middle,array('none','year','year_month','financial_year','branch_year'),true)) {
            mcResponse(422,false,'Invalid middle format.');
        }

        if (!in_array($reset,array('never','monthly','yearly','financial_year'),true)) {
            mcResponse(422,false,'Invalid reset period.');
        }

        if ($length < 1 || $length > 12) {
            mcResponse(422,false,'Number length must be between 1 and 12.');
        }

        if ($fyMonth < 1 || $fyMonth > 12) {
            mcResponse(422,false,'Financial year start month must be between 1 and 12.');
        }

        if ($branchId > 0) {
            $branchCheck = $pdo->prepare("
                SELECT id
                FROM branches
                WHERE id = :id
                  AND tenant_id = :tenant_id
                LIMIT 1
            ");
            $branchCheck->execute(array(
                ':id'=>$branchId,
                ':tenant_id'=>$tenantId
            ));

            if (!$branchCheck->fetchColumn()) {
                mcResponse(422,false,'Selected branch is invalid.');
            }
        }

        if ($branchId > 0) {
            $find = $pdo->prepare("
                SELECT id
                FROM document_sequences
                WHERE tenant_id = :tenant_id
                  AND branch_id = :branch_id
                  AND document_type = :document_type
                LIMIT 1
            ");
            $find->execute(array(
                ':tenant_id'=>$tenantId,
                ':branch_id'=>$branchId,
                ':document_type'=>$documentType
            ));
        } else {
            $find = $pdo->prepare("
                SELECT id
                FROM document_sequences
                WHERE tenant_id = :tenant_id
                  AND branch_id IS NULL
                  AND document_type = :document_type
                LIMIT 1
            ");
            $find->execute(array(
                ':tenant_id'=>$tenantId,
                ':document_type'=>$documentType
            ));
        }

        $id = (int)$find->fetchColumn();
        $old = $id > 0
            ? mcFetchRow($pdo,'document_sequences',$tenantId,$id)
            : null;

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE document_sequences
                SET
                    prefix = :prefix,
                    number_separator = :number_separator,
                    middle_format = :middle_format,
                    suffix = :suffix,
                    number_length = :number_length,
                    current_number = :current_number,
                    reset_period = :reset_period,
                    financial_year_start_month = :financial_year_start_month,
                    is_active = :is_active
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");

            $stmt->execute(array(
                ':prefix'=>trim((string)mcPost('prefix','')) ?: null,
                ':number_separator'=>(string)mcPost('number_separator','-'),
                ':middle_format'=>$middle,
                ':suffix'=>trim((string)mcPost('suffix','')) ?: null,
                ':number_length'=>$length,
                ':current_number'=>max(0,$current),
                ':reset_period'=>$reset,
                ':financial_year_start_month'=>$fyMonth,
                ':is_active'=>$isActive,
                ':id'=>$id,
                ':tenant_id'=>$tenantId
            ));
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO document_sequences (
                    tenant_id,
                    branch_id,
                    document_type,
                    prefix,
                    number_separator,
                    middle_format,
                    suffix,
                    number_length,
                    current_number,
                    reset_period,
                    financial_year_start_month,
                    is_active
                ) VALUES (
                    :tenant_id,
                    :branch_id,
                    :document_type,
                    :prefix,
                    :number_separator,
                    :middle_format,
                    :suffix,
                    :number_length,
                    :current_number,
                    :reset_period,
                    :financial_year_start_month,
                    :is_active
                )
            ");

            $stmt->execute(array(
                ':tenant_id'=>$tenantId,
                ':branch_id'=>$branchId > 0 ? $branchId : null,
                ':document_type'=>$documentType,
                ':prefix'=>trim((string)mcPost('prefix','')) ?: null,
                ':number_separator'=>(string)mcPost('number_separator','-'),
                ':middle_format'=>$middle,
                ':suffix'=>trim((string)mcPost('suffix','')) ?: null,
                ':number_length'=>$length,
                ':current_number'=>max(0,$current),
                ':reset_period'=>$reset,
                ':financial_year_start_month'=>$fyMonth,
                ':is_active'=>$isActive
            ));

            $id = (int)$pdo->lastInsertId();
        }

        $new = mcFetchRow($pdo,'document_sequences',$tenantId,$id);
        $label = $documentType === 'request' ? 'Enquiry' : ucfirst($documentType);

        mcActivity(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'document_sequence_saved',
            'document_sequence',$id,
            $label.' number format updated',
            $new
        );

        mcAudit(
            $pdo,$tenantId,$sessionBranchId,$userId,
            'DOCUMENT_SEQUENCE_SAVED',
            'document_sequence',$id,$old,$new
        );

        mcResponse(200,true,$label.' number format saved successfully.');
    }

    mcResponse(400,false,'Unsupported master control action.');

} catch (PDOException $e) {

    error_log('Master controls PDO error ['.$action.']: '.$e->getMessage());

    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        mcResponse(409,false,'A record with the same unique code or configuration already exists.');
    }

    if ($action === 'save_smtp' || $action === 'test_smtp') {
        mcResponse(500,false,'SMTP configuration database update failed. Check the smtp_configurations table structure and the PHP error log.');
    }

    mcResponse(500,false,'Unable to process the master control request.');

} catch (Throwable $e) {

    error_log('Master controls error ['.$action.']: '.$e->getMessage());

    if ($action === 'save_smtp' || $action === 'test_smtp') {
        mcResponse(500,false,$e->getMessage());
    }

    mcResponse(500,false,'Unable to process the master control request.');
}
