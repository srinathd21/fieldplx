<?php
declare(strict_types=1);

/**
 * FieldPlx Platform - Sample Platform User + SMTP Verification
 * PHP 7.2+
 *
 * ONE-TIME SETUP FILE.
 * Delete this file immediately after the sample account has been created.
 *
 * Sample:
 * Name: Rubika Sakthi
 * Email: rubiksakthi0907@gmail.com
 * Password: Abc@1234
 * Role: platform_admin
 */

require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/
$sample = array(
    'first_name' => 'Rubika',
    'last_name' => 'Sakthi',
    'preferred_username' => 'rubika.platform',
    'email' => 'rubiksakthi0907@gmail.com',
    'phone' => null,
    'job_title' => 'Platform Administrator',
    'role_code' => 'platform_admin',
    'status' => 'active',
    'password' => 'Abc@1234'
);

$result = array(
    'database' => 'Pending',
    'smtp_config' => 'Pending',
    'smtp_authentication' => 'Pending',
    'tls_security' => 'Pending',
    'email_delivery' => 'Pending',
    'final' => 'Pending'
);

$resultType = 'info';
$resultMessage = '';
$createdUsername = '';
$createdUserId = 0;
$smtpDisplay = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function fps_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

function fps_find_secret_file()
{
    $paths = array(
        __DIR__ . '/includes/smtp-secret.php',
        __DIR__ . '/smtp-secret.php'
    );

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

function fps_smtp_key()
{
    if (!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
        throw new RuntimeException(
            'FIELDPLX_SMTP_ENCRYPTION_KEY is not defined. Check includes/smtp-secret.php.'
        );
    }

    return hash(
        'sha256',
        (string)FIELDPLX_SMTP_ENCRYPTION_KEY,
        true
    );
}

function fps_decrypt_smtp_password($stored)
{
    $stored = (string)$stored;

    if ($stored === '') {
        return '';
    }

    if (strpos($stored, 'v1:') !== 0) {
        throw new RuntimeException(
            'The saved Platform SMTP password is not using the supported v1 encryption format. Re-enter and save the SMTP password from Email & SMTP.'
        );
    }

    $raw = base64_decode(
        substr($stored, 3),
        true
    );

    if (
        $raw === false ||
        strlen($raw) <= 16
    ) {
        throw new RuntimeException(
            'The saved Platform SMTP password is invalid.'
        );
    }

    $iv = substr($raw, 0, 16);
    $cipherText = substr($raw, 16);

    $plain = openssl_decrypt(
        $cipherText,
        'AES-256-CBC',
        fps_smtp_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($plain === false) {
        throw new RuntimeException(
            'Platform SMTP password decryption failed. Confirm that the same smtp-secret.php key was used when this SMTP password was saved.'
        );
    }

    return $plain;
}

function fps_find_autoload()
{
    $paths = array(
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php'
    );

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

function fps_load_phpmailer()
{
    if (
        class_exists(
            'PHPMailer\\PHPMailer\\PHPMailer',
            false
        )
    ) {
        return;
    }

    $autoload = fps_find_autoload();

    if ($autoload === '') {
        throw new RuntimeException(
            'Composer vendor/autoload.php was not found. PHPMailer cannot be loaded.'
        );
    }

    require_once $autoload;

    if (
        !class_exists(
            'PHPMailer\\PHPMailer\\PHPMailer'
        )
    ) {
        throw new RuntimeException(
            'PHPMailer could not be loaded.'
        );
    }
}

function fps_unique_username(
    PDO $pdo,
    $preferred,
    $email,
    $existingId
) {
    $preferred = strtolower(
        preg_replace(
            '/[^a-z0-9._-]+/',
            '',
            (string)$preferred
        )
    );

    if ($preferred === '') {
        $preferred = 'platform.user';
    }

    $candidate = $preferred;
    $attempt = 0;

    while (true) {
        $stmt = $pdo->prepare("
            SELECT id, email
            FROM platform_users
            WHERE username = :username
              AND id <> :existing_id
            LIMIT 1
        ");

        $stmt->execute(array(
            ':username' => $candidate,
            ':existing_id' => (int)$existingId
        ));

        $row = $stmt->fetch();

        if (!$row) {
            return $candidate;
        }

        $attempt++;

        if ($attempt > 100) {
            throw new RuntimeException(
                'Unable to generate a unique Platform username.'
            );
        }

        $candidate =
            $preferred .
            random_int(1000, 9999);
    }
}

function fps_login_url()
{
    if (
        empty($_SERVER['HTTP_HOST'])
    ) {
        return 'login.php';
    }

    $https =
        isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== '' &&
        strtolower(
            (string)$_SERVER['HTTPS']
        ) !== 'off';

    $scheme = $https
        ? 'https'
        : 'http';

    $scriptName =
        isset($_SERVER['SCRIPT_NAME'])
            ? (string)$_SERVER['SCRIPT_NAME']
            : '';

    $base =
        rtrim(
            str_replace(
                '\\',
                '/',
                dirname($scriptName)
            ),
            '/'
        );

    return
        $scheme .
        '://' .
        $_SERVER['HTTP_HOST'] .
        $base .
        '/login.php';
}


function fps_is_local_request()
{
    $host = isset($_SERVER['HTTP_HOST'])
        ? strtolower((string)$_SERVER['HTTP_HOST'])
        : '';

    $host = preg_replace('/:\\d+$/', '', $host);

    return in_array(
        $host,
        array('localhost', '127.0.0.1', '::1'),
        true
    );
}

function fps_ca_bundle()
{
    $candidates = array();

    $iniOpenSsl = trim((string)ini_get('openssl.cafile'));
    $iniCurl = trim((string)ini_get('curl.cainfo'));

    if ($iniOpenSsl !== '') {
        $candidates[] = $iniOpenSsl;
    }

    if ($iniCurl !== '') {
        $candidates[] = $iniCurl;
    }

    if (function_exists('openssl_get_cert_locations')) {
        $locations = openssl_get_cert_locations();

        foreach (
            array(
                'ini_cafile',
                'default_cert_file'
            ) as $key
        ) {
            if (!empty($locations[$key])) {
                $candidates[] = (string)$locations[$key];
            }
        }
    }

    $phpDir = dirname((string)PHP_BINARY);

    $candidates[] = __DIR__ . '/includes/cacert.pem';
    $candidates[] = __DIR__ . '/cacert.pem';
    $candidates[] = $phpDir . '/extras/ssl/cacert.pem';
    $candidates[] = dirname($phpDir) . '/apache/bin/curl-ca-bundle.crt';
    $candidates[] = dirname($phpDir) . '/apache/bin/cacert.pem';

    foreach (array_unique($candidates) as $candidate) {
        $candidate = trim((string)$candidate);

        if (
            $candidate !== '' &&
            is_file($candidate) &&
            is_readable($candidate)
        ) {
            return $candidate;
        }
    }

    return '';
}

function fps_is_certificate_error(Throwable $e)
{
    $message = strtolower($e->getMessage());

    return (
        strpos($message, 'certificate verify failed') !== false ||
        strpos($message, 'unable to get local issuer certificate') !== false ||
        strpos($message, 'self signed certificate') !== false
    );
}

function fps_smtp_friendly_error(Throwable $e, array $smtp)
{
    $message = trim($e->getMessage());
    $lower = strtolower($message);
    $host = isset($smtp['host']) ? (string)$smtp['host'] : '';
    $port = isset($smtp['port']) ? (int)$smtp['port'] : 0;

    if (fps_is_certificate_error($e)) {
        return 'TLS certificate verification failed. Your local PHP/OpenSSL CA bundle is missing or outdated. This corrected page automatically retries with a localhost-only development fallback; on live hosting certificate verification remains enabled.';
    }

    if (
        strpos($lower, 'could not authenticate') !== false ||
        strpos($lower, 'authentication failed') !== false ||
        strpos($lower, 'username and password not accepted') !== false ||
        strpos($lower, '535') !== false ||
        strpos($lower, '5.7.8') !== false
    ) {
        return 'SMTP authentication failed. For Gmail, use a Google App Password (normally 16 characters) as the SMTP password, not the normal Gmail account password. Then re-save the Platform SMTP configuration.';
    }

    if (
        strpos($lower, 'timed out') !== false ||
        strpos($lower, 'connection refused') !== false ||
        strpos($lower, 'failed to open stream') !== false
    ) {
        return 'SMTP network connection failed for ' . $host . ':' . $port . '. Check internet access, firewall/antivirus rules, and whether outbound SMTP port ' . $port . ' is allowed.';
    }

    if (
        strpos($lower, 'getaddrinfo') !== false ||
        strpos($lower, 'php_network_getaddresses') !== false ||
        strpos($lower, 'name or service not known') !== false
    ) {
        return 'SMTP DNS lookup failed for ' . $host . '. Check the SMTP host name and local DNS/internet connection.';
    }

    if (
        strpos($lower, 'password decryption') !== false ||
        strpos($lower, 'decrypt') !== false
    ) {
        return 'The saved SMTP password cannot be decrypted. Keep the same smtp-secret.php key used when the SMTP password was saved, then re-enter and save the SMTP password once.';
    }

    if (
        strpos($lower, 'sender address rejected') !== false ||
        strpos($lower, '553') !== false ||
        strpos($lower, '550') !== false
    ) {
        return 'SMTP rejected the sender address. Make the Platform SMTP From Email match the authenticated Gmail account or a verified sending alias.';
    }

    if (
        strpos($lower, 'rate') !== false ||
        strpos($lower, 'quota') !== false ||
        strpos($lower, '421') !== false ||
        strpos($lower, '452') !== false
    ) {
        return 'SMTP provider temporarily rejected the message because of a rate/quota limit. Wait briefly or review the provider sending limits.';
    }

    return $message !== ''
        ? $message
        : 'Unknown SMTP error.';
}

function fps_configure_mail(
    $smtp,
    $smtpPassword,
    $allowInsecureLocalhost = false,
    &$tlsInfo = null
) {
    fps_load_phpmailer();

    $mail =
        new \PHPMailer\PHPMailer\PHPMailer(
            true
        );

    $mail->isSMTP();
    $mail->Host = trim((string)$smtp['host']);
    $mail->Port = (int)$smtp['port'];
    $mail->Timeout = 20;

    if (property_exists($mail, 'Timelimit')) {
        $mail->Timelimit = 20;
    }

    $mail->SMTPDebug = 0;
    $mail->SMTPKeepAlive = false;

    $username = trim((string)$smtp['username']);
    $mail->SMTPAuth = $username !== '';

    if ($mail->SMTPAuth) {
        if ($smtpPassword === '') {
            throw new RuntimeException(
                'Platform SMTP username exists but the decrypted SMTP password is empty.'
            );
        }

        $mail->Username = $username;
        $mail->Password = $smtpPassword;
    }

    $encryption = strtolower(trim((string)$smtp['encryption']));

    /* Gmail-safe runtime correction for common port/encryption mismatches. */
    if ($mail->Port === 587) {
        $encryption = 'tls';
    } elseif ($mail->Port === 465) {
        $encryption = 'ssl';
    }

    if ($encryption === 'ssl') {
        $mail->SMTPSecure =
            \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
    } elseif (
        $encryption === 'tls' ||
        $encryption === 'starttls'
    ) {
        $mail->SMTPSecure =
            \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $caBundle = fps_ca_bundle();

    if (
        $allowInsecureLocalhost &&
        fps_is_local_request()
    ) {
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $tlsInfo = array(
            'mode' => 'localhost_fallback',
            'ca_bundle' => ''
        );
    } elseif ($caBundle !== '') {
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'cafile' => $caBundle
            )
        );

        $tlsInfo = array(
            'mode' => 'verified_ca_bundle',
            'ca_bundle' => $caBundle
        );
    } else {
        /* Keep normal strict OpenSSL verification on non-local/live systems. */
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false
            )
        );

        $tlsInfo = array(
            'mode' => 'system_default',
            'ca_bundle' => ''
        );
    }

    $fromEmail = trim((string)$smtp['from_email']);

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            'The Platform SMTP From Email is invalid.'
        );
    }

    $fromName = trim((string)$smtp['from_name']);

    if ($fromName === '') {
        $fromName = 'FieldPlx';
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);

    $replyTo = trim((string)$smtp['reply_to_email']);

    if (
        $replyTo !== '' &&
        filter_var($replyTo, FILTER_VALIDATE_EMAIL)
    ) {
        $mail->addReplyTo($replyTo);
    }

    return $mail;
}

function fps_audit(
    PDO $pdo,
    $platformUserId,
    $action,
    array $details
) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                tenant_id,
                branch_id,
                user_id,
                platform_user_id,
                action,
                object_type,
                object_id,
                old_values,
                new_values,
                ip_address,
                device_type,
                user_agent,
                created_at
            ) VALUES (
                NULL,
                NULL,
                NULL,
                :platform_user_id,
                :action,
                'platform_user',
                :object_id,
                NULL,
                :new_values,
                :ip_address,
                :device_type,
                :user_agent,
                NOW()
            )
        ");

        $userAgent =
            isset($_SERVER['HTTP_USER_AGENT'])
                ? (string)$_SERVER['HTTP_USER_AGENT']
                : '';

        $deviceType =
            preg_match(
                '/mobile|android|iphone|ipad/i',
                $userAgent
            )
                ? 'mobile'
                : 'desktop';

        $stmt->execute(array(
            ':platform_user_id' =>
                (int)$platformUserId,
            ':action' =>
                (string)$action,
            ':object_id' =>
                (int)$platformUserId,
            ':new_values' =>
                json_encode(
                    $details,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                ),
            ':ip_address' =>
                isset($_SERVER['REMOTE_ADDR'])
                    ? substr(
                        (string)$_SERVER['REMOTE_ADDR'],
                        0,
                        80
                    )
                    : null,
            ':device_type' =>
                $deviceType,
            ':user_agent' =>
                substr(
                    $userAgent,
                    0,
                    500
                )
        ));
    } catch (Throwable $e) {
        error_log(
            'FieldPlx sample Platform User audit error: ' .
            $e->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Run only after explicit button click
|--------------------------------------------------------------------------
*/
$run =
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['create_sample']) &&
    $_POST['create_sample'] === '1';

if ($run) {
    try {
        /*
        |--------------------------------------------------------------------------
        | Load SMTP secret
        |--------------------------------------------------------------------------
        */
        $secretFile =
            fps_find_secret_file();

        if ($secretFile === '') {
            throw new RuntimeException(
                'SMTP secret file was not found. Expected includes/smtp-secret.php.'
            );
        }

        require_once $secretFile;

        /*
        |--------------------------------------------------------------------------
        | Find active Platform SMTP
        |--------------------------------------------------------------------------
        */
        $smtpStmt = $pdo->query("
            SELECT *
            FROM smtp_configurations
            WHERE scope_type = 'platform'
              AND is_active = 1
            ORDER BY
                is_default DESC,
                id DESC
            LIMIT 1
        ");

        $smtp =
            $smtpStmt->fetch();

        if (!$smtp) {
            $result['smtp_config'] = 'Failed';

            throw new RuntimeException(
                'No active Platform SMTP configuration was found. Configure and test Email & SMTP first.'
            );
        }

        $smtpDisplay =
            trim(
                (string)$smtp['config_name']
            ) .
            ' — ' .
            trim(
                (string)$smtp['host']
            ) .
            ':' .
            (int)$smtp['port'];

        $result['smtp_config'] =
            'Verified: ' .
            $smtpDisplay;

        /*
        |--------------------------------------------------------------------------
        | Decrypt SMTP password
        |--------------------------------------------------------------------------
        */
        $smtpPassword =
            fps_decrypt_smtp_password(
                isset(
                    $smtp['password_encrypted']
                )
                    ? $smtp['password_encrypted']
                    : ''
            );

        /*
        |--------------------------------------------------------------------------
        | Verify SMTP authentication before changing the user
        |--------------------------------------------------------------------------
        */
        $tlsInfo = array();
        $useLocalTlsFallback = false;

        try {
            $verifyMail =
                fps_configure_mail(
                    $smtp,
                    $smtpPassword,
                    false,
                    $tlsInfo
                );

            if (!$verifyMail->smtpConnect()) {
                throw new RuntimeException(
                    'SMTP connection/authentication failed.'
                );
            }

            $verifyMail->smtpClose();
        } catch (Throwable $strictTlsError) {
            if (
                fps_is_local_request() &&
                fps_is_certificate_error($strictTlsError)
            ) {
                $useLocalTlsFallback = true;
                $tlsInfo = array();

                $verifyMail =
                    fps_configure_mail(
                        $smtp,
                        $smtpPassword,
                        true,
                        $tlsInfo
                    );

                if (!$verifyMail->smtpConnect()) {
                    throw new RuntimeException(
                        'SMTP connection/authentication failed after localhost TLS fallback.'
                    );
                }

                $verifyMail->smtpClose();
            } else {
                throw $strictTlsError;
            }
        }

        $result['smtp_authentication'] =
            'Verified successfully';

        if ($useLocalTlsFallback) {
            $result['tls_security'] =
                'Localhost development fallback used because PHP CA verification failed. Live hosting remains strict.';
        } elseif (
            isset($tlsInfo['mode']) &&
            $tlsInfo['mode'] === 'verified_ca_bundle'
        ) {
            $result['tls_security'] =
                'Certificate verified using CA bundle: ' .
                $tlsInfo['ca_bundle'];
        } else {
            $result['tls_security'] =
                'Certificate verification succeeded using the PHP/OpenSSL system trust store.';
        }

        /*
        |--------------------------------------------------------------------------
        | Start transaction
        |--------------------------------------------------------------------------
        */
        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | Find existing user by sample email
        |--------------------------------------------------------------------------
        */
        $existingStmt = $pdo->prepare("
            SELECT *
            FROM platform_users
            WHERE email = :email
            LIMIT 1
        ");

        $existingStmt->execute(array(
            ':email' =>
                $sample['email']
        ));

        $existing =
            $existingStmt->fetch();

        $existingId =
            $existing
                ? (int)$existing['id']
                : 0;

        $createdUsername =
            fps_unique_username(
                $pdo,
                $existing &&
                !empty(
                    $existing['username']
                )
                    ? $existing['username']
                    : $sample[
                        'preferred_username'
                    ],
                $sample['email'],
                $existingId
            );

        $passwordHash =
            password_hash(
                $sample['password'],
                PASSWORD_DEFAULT
            );

        if ($passwordHash === false) {
            throw new RuntimeException(
                'Unable to hash the sample Platform User password.'
            );
        }

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE platform_users
                SET
                    first_name = :first_name,
                    last_name = :last_name,
                    username = :username,
                    phone = :phone,
                    password_hash = :password_hash,
                    job_title = :job_title,
                    role_code = :role_code,
                    status = :status,
                    deleted_at = NULL
                WHERE id = :id
            ");

            $stmt->execute(array(
                ':first_name' =>
                    $sample['first_name'],
                ':last_name' =>
                    $sample['last_name'],
                ':username' =>
                    $createdUsername,
                ':phone' =>
                    $sample['phone'],
                ':password_hash' =>
                    $passwordHash,
                ':job_title' =>
                    $sample['job_title'],
                ':role_code' =>
                    $sample['role_code'],
                ':status' =>
                    $sample['status'],
                ':id' =>
                    $existingId
            ));

            $createdUserId =
                $existingId;

            $result['database'] =
                'Existing sample user updated/reset';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO platform_users (
                    first_name,
                    last_name,
                    username,
                    email,
                    phone,
                    password_hash,
                    avatar_path,
                    job_title,
                    role_code,
                    status,
                    last_login_at,
                    created_at,
                    updated_at,
                    deleted_at
                ) VALUES (
                    :first_name,
                    :last_name,
                    :username,
                    :email,
                    :phone,
                    :password_hash,
                    NULL,
                    :job_title,
                    :role_code,
                    :status,
                    NULL,
                    NOW(),
                    NULL,
                    NULL
                )
            ");

            $stmt->execute(array(
                ':first_name' =>
                    $sample['first_name'],
                ':last_name' =>
                    $sample['last_name'],
                ':username' =>
                    $createdUsername,
                ':email' =>
                    $sample['email'],
                ':phone' =>
                    $sample['phone'],
                ':password_hash' =>
                    $passwordHash,
                ':job_title' =>
                    $sample['job_title'],
                ':role_code' =>
                    $sample['role_code'],
                ':status' =>
                    $sample['status']
            ));

            $createdUserId =
                (int)$pdo->lastInsertId();

            $result['database'] =
                'Sample Platform User inserted';
        }

        /*
        |--------------------------------------------------------------------------
        | Send credential email
        |--------------------------------------------------------------------------
        */
        $sendTlsInfo = array();

        $mail =
            fps_configure_mail(
                $smtp,
                $smtpPassword,
                $useLocalTlsFallback,
                $sendTlsInfo
            );

        $fullName =
            trim(
                $sample['first_name'] .
                ' ' .
                $sample['last_name']
            );

        $mail->addAddress(
            $sample['email'],
            $fullName
        );

        $loginUrl =
            fps_login_url();

        $safeName =
            fps_h($fullName);
        $safeUsername =
            fps_h($createdUsername);
        $safeEmail =
            fps_h($sample['email']);
        $safePassword =
            fps_h($sample['password']);
        $safeRole =
            fps_h(
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $sample['role_code']
                    )
                )
            );
        $safeLoginUrl =
            fps_h($loginUrl);

        $mail->isHTML(true);

        $mail->Subject =
            'FieldPlx Platform - Sample login account';

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f5f2fb;font-family:Arial,Helvetica,sans-serif;color:#211c32">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f2fb;padding:28px 12px">
<tr>
<td align="center">
<table width="620" cellpadding="0" cellspacing="0" style="width:100%;max-width:620px;background:#fff;border:1px solid #e3dcf3;border-radius:16px;overflow:hidden">
<tr>
<td style="padding:22px 26px;background:#1c2250">
<div style="display:inline-block;padding:8px 10px;border-radius:9px;background:#8b5cf6;color:#fff;font-size:14px;font-weight:800">FP</div>
<div style="margin-top:14px;color:#fff;font-size:22px;font-weight:800">FieldPlx Platform Account</div>
<div style="margin-top:5px;color:#d9d1f7;font-size:13px;line-height:1.6">Your sample Platform login has been verified and created.</div>
</td>
</tr>
<tr>
<td style="padding:28px 26px">
<div style="font-size:18px;font-weight:800">Hello ' . $safeName . ',</div>
<div style="margin-top:11px;color:#6f677d;font-size:14px;line-height:1.7">
Your FieldPlx Platform sample account is ready. Use the credentials below to sign in.
</div>

<div style="margin-top:22px;padding:18px;border:1px solid #e4ddf3;border-radius:12px;background:#fbf9ff">
<div style="color:#6d28d9;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px">Platform Login Details</div>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px">
<tr>
<td style="padding:9px 0;color:#7c738d;font-size:13px">Username</td>
<td style="padding:9px 0;text-align:right;font-size:13px;font-weight:700">' . $safeUsername . '</td>
</tr>
<tr>
<td style="padding:9px 0;color:#7c738d;font-size:13px">Email</td>
<td style="padding:9px 0;text-align:right;font-size:13px;font-weight:700">' . $safeEmail . '</td>
</tr>
<tr>
<td style="padding:9px 0;color:#7c738d;font-size:13px">Password</td>
<td style="padding:9px 0;text-align:right;font-size:13px;font-weight:700">' . $safePassword . '</td>
</tr>
<tr>
<td style="padding:9px 0;color:#7c738d;font-size:13px">Role</td>
<td style="padding:9px 0;text-align:right;font-size:13px;font-weight:700">' . $safeRole . '</td>
</tr>
</table>
</div>

<div style="margin:24px 0 4px;text-align:center">
<a href="' . $safeLoginUrl . '" style="display:inline-block;padding:12px 22px;border-radius:9px;background:#6d28d9;color:#fff;text-decoration:none;font-size:14px;font-weight:700">Open FieldPlx Platform</a>
</div>

<div style="margin-top:20px;color:#9a94aa;font-size:11px;line-height:1.6">
This is a sample/test account. Change the password after verification if the account will remain active.
</div>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>';

        $mail->AltBody =
            "FieldPlx Platform Sample Account\n\n" .
            "Name: " . $fullName . "\n" .
            "Username: " . $createdUsername . "\n" .
            "Email: " . $sample['email'] . "\n" .
            "Password: " . $sample['password'] . "\n" .
            "Role: Platform Admin\n" .
            "Login: " . $loginUrl;

        $mail->send();

        $result['email_delivery'] =
            'Email sent successfully to ' .
            $sample['email'];

        /*
        |--------------------------------------------------------------------------
        | Commit only after SMTP send succeeds
        |--------------------------------------------------------------------------
        */
        $pdo->commit();

        $result['final'] =
            'SUCCESS - User committed and SMTP email verified';

        $resultType =
            'success';

        $resultMessage =
            'Sample Platform User is ready and the credential email was sent successfully.';

        fps_audit(
            $pdo,
            $createdUserId,
            $existing
                ? 'PLATFORM_USER_SAMPLE_RESET'
                : 'PLATFORM_USER_SAMPLE_CREATED',
            array(
                'username' =>
                    $createdUsername,
                'email' =>
                    $sample['email'],
                'role_code' =>
                    $sample['role_code'],
                'status' =>
                    $sample['status'],
                'smtp_config_id' =>
                    (int)$smtp['id'],
                'smtp_verified' =>
                    true,
                'credential_email_sent' =>
                    true
            )
        );

    } catch (Throwable $e) {
        if (
            isset($pdo) &&
            $pdo instanceof PDO &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        if (
            $result['smtp_authentication'] ===
            'Pending'
        ) {
            $result['smtp_authentication'] =
                'Not completed';
        }

        if (
            $result['tls_security'] ===
            'Pending'
        ) {
            $result['tls_security'] =
                'Not completed';
        }

        if (
            $result['email_delivery'] ===
            'Pending'
        ) {
            $result['email_delivery'] =
                'Not sent';
        }

        $result['final'] =
            'FAILED - Database changes rolled back';

        $resultType =
            'error';

        $resultMessage =
            isset($smtp) && is_array($smtp)
                ? fps_smtp_friendly_error($e, $smtp)
                : $e->getMessage();

        error_log(
            'FieldPlx Platform sample user SMTP verification error: ' .
            $e->getMessage()
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Platform Sample User - FieldPlx</title>

    <style>
    :root {
        --navy: #12182d;
        --navy2: #1c2250;
        --violet: #7c3aed;
        --violet2: #6d28d9;
        --soft: #f8f5ff;
        --border: #ded7ef;
        --text: #20213f;
        --muted: #77718e;
        --success: #059669;
        --danger: #dc2626;
    }

    * {
        box-sizing: border-box
    }

    body {
        margin: 0;
        min-height: 100vh;
        padding: 28px 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f7f5fb;
        color: var(--text);
        font-family: Inter, Arial, sans-serif;
    }

    .card {
        width: min(760px, 100%);
        overflow: hidden;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 18px 50px rgba(28, 20, 70, .12);
    }

    .header {
        padding: 22px 24px;
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        color: #fff;
    }

    .logo {
        width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 11px;
        background: linear-gradient(135deg, #a273ff, var(--violet));
        font-weight: 800;
    }

    .header h1 {
        margin: 14px 0 0;
        font-size: 22px;
    }

    .header p {
        margin: 6px 0 0;
        color: #d9d1f7;
        font-size: 12px;
        line-height: 1.6;
    }

    .body {
        padding: 22px 24px
    }

    .user-box {
        padding: 15px;
        border: 1px solid #e3daf8;
        border-radius: 12px;
        background: var(--soft);
    }

    .row {
        padding: 9px 0;
        display: flex;
        justify-content: space-between;
        gap: 14px;
        border-bottom: 1px dashed #ddd6ef;
        font-size: 12px;
    }

    .row:last-child {
        border-bottom: 0
    }

    .row span {
        color: var(--muted)
    }

    .row strong {
        text-align: right
    }

    .warning {
        margin-top: 14px;
        padding: 12px;
        border: 1px solid #fed7aa;
        border-radius: 10px;
        background: #fff7ed;
        color: #9a5a14;
        font-size: 11px;
        line-height: 1.6;
    }

    .result {
        margin-top: 16px;
        padding: 14px;
        border-radius: 11px;
        font-size: 11px;
    }

    .result.success {
        border: 1px solid #a7f3d0;
        background: #ecfdf5;
        color: #047857;
    }

    .result.error {
        border: 1px solid #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .result.info {
        border: 1px solid #ddd6fe;
        background: #f5f3ff;
        color: #5b21b6;
    }

    .checks {
        margin-top: 12px;
        display: grid;
        gap: 7px;
    }

    .check {
        padding: 9px 10px;
        display: flex;
        justify-content: space-between;
        gap: 10px;
        border: 1px solid #ece7f7;
        border-radius: 9px;
        background: #fff;
    }

    .actions {
        margin-top: 18px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    button {
        min-height: 40px;
        padding: 9px 15px;
        border: 0;
        border-radius: 9px;
        background: linear-gradient(135deg, var(--violet), var(--violet2));
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
    }

    a {
        min-height: 40px;
        padding: 9px 15px;
        display: inline-flex;
        align-items: center;
        border: 1px solid var(--border);
        border-radius: 9px;
        background: #fff;
        color: #5f5870;
        text-decoration: none;
        font-size: 11px;
        font-weight: 700;
    }

    .small {
        margin-top: 12px;
        color: #9a94aa;
        font-size: 10px;
        line-height: 1.6;
    }

    @media(max-width:560px) {

        .row,
        .check {
            flex-direction: column
        }

        .row strong {
            text-align: left
        }

        .actions {
            flex-direction: column
        }

        .actions a,
        .actions button {
            width: 100%;
            justify-content: center
        }
    }
    </style>
</head>

<body>

    <div class="card">

        <div class="header">
            <span class="logo">FP</span>
            <h1>Platform Sample User + SMTP Verification</h1>
            <p>
                Creates/resets the requested sample Platform Admin and verifies that
                the configured Platform SMTP can authenticate and deliver the login email.
            </p>
        </div>

        <div class="body">

            <div class="user-box">
                <div class="row">
                    <span>Name</span>
                    <strong><?= fps_h(
            $sample['first_name'] . ' ' . $sample['last_name']
        ); ?></strong>
                </div>

                <div class="row">
                    <span>Email</span>
                    <strong><?= fps_h($sample['email']); ?></strong>
                </div>

                <div class="row">
                    <span>Preferred Username</span>
                    <strong><?= fps_h($sample['preferred_username']); ?></strong>
                </div>

                <div class="row">
                    <span>Sample Password</span>
                    <strong><?= fps_h($sample['password']); ?></strong>
                </div>

                <div class="row">
                    <span>Role</span>
                    <strong>Platform Admin</strong>
                </div>

                <div class="row">
                    <span>Status</span>
                    <strong>Active</strong>
                </div>
            </div>

            <div class="warning">
                <strong>One-time file:</strong>
                this page contains the sample password in plain text because you explicitly
                requested <strong>Abc@1234</strong>. Delete this PHP file immediately after
                the test is complete.
            </div>

            <?php if ($run): ?>

            <div class="result <?= fps_h($resultType); ?>">
                <strong><?= $resultType === 'success' ? 'Completed' : 'Verification failed'; ?></strong>
                <div style="margin-top:5px;">
                    <?= fps_h($resultMessage); ?>
                </div>

                <div class="checks">
                    <div class="check">
                        <span>Database</span>
                        <strong><?= fps_h($result['database']); ?></strong>
                    </div>

                    <div class="check">
                        <span>SMTP Configuration</span>
                        <strong><?= fps_h($result['smtp_config']); ?></strong>
                    </div>

                    <div class="check">
                        <span>SMTP Authentication</span>
                        <strong><?= fps_h($result['smtp_authentication']); ?></strong>
                    </div>

                    <div class="check">
                        <span>TLS / Certificate</span>
                        <strong><?= fps_h($result['tls_security']); ?></strong>
                    </div>

                    <div class="check">
                        <span>Email Delivery</span>
                        <strong><?= fps_h($result['email_delivery']); ?></strong>
                    </div>

                    <div class="check">
                        <span>Final Result</span>
                        <strong><?= fps_h($result['final']); ?></strong>
                    </div>

                    <?php if ($createdUsername !== ''): ?>
                    <div class="check">
                        <span>Final Username</span>
                        <strong><?= fps_h($createdUsername); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php else: ?>

            <div class="result info">
                No database or SMTP action has run yet. Click the button below once to verify
                Platform SMTP, create/reset the sample user, and send the credential email.
            </div>

            <?php endif; ?>

            <div class="actions">
                <a href="platform-users.php">
                    Back to Platform Users
                </a>

                <form method="post" style="margin:0;">
                    <input type="hidden" name="create_sample" value="1">

                    <button type="submit">
                        Verify SMTP & Create Sample User
                    </button>
                </form>
            </div>

            <div class="small">
                Success means: active Platform SMTP found → TLS established → SMTP authentication succeeded →
                Platform User saved/reset → credential email sent → database transaction committed.
                On localhost only, the page can temporarily bypass certificate verification when PHP has no usable CA
                bundle.
                Live hosting always keeps certificate verification enabled. If any later step fails, the database
                transaction is rolled back.
            </div>

        </div>
    </div>

</body>

</html>