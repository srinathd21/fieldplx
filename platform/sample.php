<?php
/**
 * FieldPlx Platform - Sample Platform User Seeder
 *
 * Place this file inside the /platform directory and open it once in browser.
 * Example: http://localhost/git/fieldplx/platform/platform-sample-user.php
 *
 * Creates or resets one sample Platform Admin account for:
 * rubiksakthi0907@gmail.com
 *
 * IMPORTANT: Delete this file immediately after successful use.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/includes/smtp-secret.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function psu_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function psu_random_password(int $length = 14): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $symbols = '@#$!%*?';
    $all = $upper . $lower . $digits . $symbols;

    $password =
        $upper[random_int(0, strlen($upper) - 1)] .
        $lower[random_int(0, strlen($lower) - 1)] .
        $digits[random_int(0, strlen($digits) - 1)] .
        $symbols[random_int(0, strlen($symbols) - 1)];

    while (strlen($password) < $length) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }

    return str_shuffle($password);
}

function psu_unique_username(PDO $pdo, string $base, int $excludeId = 0): string
{
    $base = strtolower(trim($base));
    $base = preg_replace('/[^a-z0-9._-]+/', '.', $base);
    $base = trim((string)$base, '.-_');

    if ($base === '') {
        $base = 'platform.user';
    }

    $candidate = substr($base, 0, 90);
    $counter = 0;

    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id FROM platform_users WHERE username = :username AND id <> :exclude_id LIMIT 1'
        );
        $stmt->execute(array(
            ':username' => $candidate,
            ':exclude_id' => $excludeId
        ));

        if (!$stmt->fetchColumn()) {
            return $candidate;
        }

        $counter++;
        $suffix = '.' . $counter;
        $candidate = substr($base, 0, 100 - strlen($suffix)) . $suffix;
    }
}

function psu_secret_key(): string
{
    $key = '';

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

    if ($key === '' || strlen($key) < 32) {
        throw new RuntimeException('Platform SMTP encryption key is not configured correctly.');
    }

    return hash('sha256', $key, true);
}

function psu_decrypt_smtp_password(?string $stored): string
{
    $stored = (string)$stored;

    if ($stored === '' || strpos($stored, 'v1:') !== 0) {
        return '';
    }

    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= 16) {
        return '';
    }

    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);

    $plain = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        psu_secret_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    return $plain === false ? '' : $plain;
}

function psu_composer_autoload(): string
{
    $projectRoot = dirname(__DIR__);

    $paths = array(
        $projectRoot . '/vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php'
    );

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

function psu_platform_login_url(): string
{
    if (empty($_SERVER['HTTP_HOST'])) {
        return 'login.php';
    }

    $https = isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== '' &&
        strtolower((string)$_SERVER['HTTPS']) !== 'off';

    $scheme = $https ? 'https' : 'http';
    $scriptDir = str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME']));
    $scriptDir = rtrim($scriptDir, '/');

    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $scriptDir . '/login.php';
}

function psu_send_credentials(PDO $pdo, array $user, string $plainPassword): void
{
    $stmt = $pdo->query(
        "SELECT *
         FROM smtp_configurations
         WHERE scope_type = 'platform'
           AND is_active = 1
         ORDER BY is_default DESC, id DESC
         LIMIT 1"
    );

    $smtp = $stmt->fetch();

    if (!$smtp) {
        throw new RuntimeException('No active Platform SMTP configuration is available.');
    }

    $autoload = psu_composer_autoload();
    if ($autoload === '') {
        throw new RuntimeException('Composer vendor/autoload.php was not found.');
    }

    require_once $autoload;

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('PHPMailer could not be loaded.');
    }

    $smtpPassword = psu_decrypt_smtp_password(
        isset($smtp['password_encrypted']) ? (string)$smtp['password_encrypted'] : ''
    );

    $username = trim((string)$smtp['username']);
    if ($username !== '' && $smtpPassword === '') {
        throw new RuntimeException('SMTP password is empty or could not be decrypted.');
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string)$smtp['host']);
    $mail->Port = (int)$smtp['port'];
    $mail->Timeout = 25;
    $mail->SMTPDebug = 0;
    $mail->SMTPKeepAlive = false;
    $mail->SMTPAuth = $username !== '';

    if ($mail->SMTPAuth) {
        $mail->Username = $username;
        $mail->Password = $smtpPassword;
    }

    $encryption = strtolower(trim((string)$smtp['encryption']));

    if ($encryption === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
    } elseif ($encryption === 'tls' || $encryption === 'starttls') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $fromEmail = trim((string)$smtp['from_email']);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Configured Platform SMTP From Email is invalid.');
    }

    $fromName = trim((string)$smtp['from_name']);
    if ($fromName === '') {
        $fromName = 'FieldPlx';
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);

    $replyTo = trim((string)$smtp['reply_to_email']);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($replyTo);
    }

    $fullName = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
    $mail->addAddress((string)$user['email'], $fullName);

    $loginUrl = psu_platform_login_url();

    $mail->isHTML(true);
    $mail->Subject = 'FieldPlx Platform - Sample Login Credentials';

    $mail->Body =
        '<!doctype html><html><body style="margin:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#182033">' .
        '<div style="padding:28px 14px">' .
        '<div style="max-width:620px;margin:auto;background:#fff;border:1px solid #dde4ef;border-radius:16px;overflow:hidden">' .
        '<div style="background:#061d49;padding:24px 28px;color:#fff">' .
        '<div style="display:inline-block;background:#7ccd22;padding:10px 12px;border-radius:10px;font-weight:800;font-size:18px">F</div>' .
        '<h2 style="margin:16px 0 5px">Welcome to FieldPlx Platform</h2>' .
        '<div style="color:#c7d5ea;font-size:13px">Your sample Platform Administrator account is ready.</div>' .
        '</div>' .
        '<div style="padding:28px">' .
        '<p style="font-size:16px">Hello <strong>' . psu_escape($fullName) . '</strong>,</p>' .
        '<p style="color:#657089;line-height:1.7">Use the temporary credentials below to sign in to the FieldPlx Platform.</p>' .
        '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin:20px 0">' .
        '<div style="margin-bottom:10px"><strong>Username:</strong> ' . psu_escape($user['username']) . '</div>' .
        '<div style="margin-bottom:10px"><strong>Email:</strong> ' . psu_escape($user['email']) . '</div>' .
        '<div style="margin-bottom:10px"><strong>Temporary Password:</strong> ' . psu_escape($plainPassword) . '</div>' .
        '<div><strong>Role:</strong> Platform Admin</div>' .
        '</div>' .
        '<div style="text-align:center;margin:26px 0">' .
        '<a href="' . psu_escape($loginUrl) . '" style="display:inline-block;background:#7ccd22;color:#fff;text-decoration:none;padding:12px 22px;border-radius:9px;font-weight:700">Open FieldPlx Platform</a>' .
        '</div>' .
        '<p style="font-size:12px;color:#8992a5">For security, change this temporary password after testing.</p>' .
        '</div></div></div></body></html>';

    $mail->AltBody =
        "FieldPlx Platform - Sample Login Credentials\n\n" .
        "Username: " . $user['username'] . "\n" .
        "Email: " . $user['email'] . "\n" .
        "Temporary Password: " . $plainPassword . "\n" .
        "Role: Platform Admin\n" .
        "Login: " . $loginUrl;

    $mail->send();
}

$email = 'rubiksakthi0907@gmail.com';
$firstName = 'Rubika';
$lastName = 'Sakthi';
$roleCode = 'platform_admin';
$status = 'active';
$jobTitle = 'Platform Administrator';
$plainPassword = psu_random_password(14);

$message = '';
$messageType = 'success';
$createdOrReset = '';
$username = '';
$userId = 0;

try {
    $pdo->beginTransaction();

    $check = $pdo->prepare(
        'SELECT id, username FROM platform_users WHERE email = :email LIMIT 1'
    );
    $check->execute(array(':email' => $email));
    $existing = $check->fetch();

    if ($existing) {
        $userId = (int)$existing['id'];
        $username = trim((string)$existing['username']);

        if ($username === '') {
            $username = psu_unique_username($pdo, 'rubika.platform', $userId);
        }

        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash the temporary password.');
        }

        $update = $pdo->prepare(
            "UPDATE platform_users
             SET first_name = :first_name,
                 last_name = :last_name,
                 username = :username,
                 phone = NULL,
                 password_hash = :password_hash,
                 job_title = :job_title,
                 role_code = :role_code,
                 status = :status,
                 deleted_at = NULL
             WHERE id = :id"
        );

        $update->execute(array(
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':username' => $username,
            ':password_hash' => $hash,
            ':job_title' => $jobTitle,
            ':role_code' => $roleCode,
            ':status' => $status,
            ':id' => $userId
        ));

        $createdOrReset = 'reset';
    } else {
        $username = psu_unique_username($pdo, 'rubika.platform');

        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash the temporary password.');
        }

        $insert = $pdo->prepare(
            "INSERT INTO platform_users (
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
                NULL,
                :password_hash,
                NULL,
                :job_title,
                :role_code,
                :status,
                NULL,
                NOW(),
                NULL,
                NULL
             )"
        );

        $insert->execute(array(
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => $hash,
            ':job_title' => $jobTitle,
            ':role_code' => $roleCode,
            ':status' => $status
        ));

        $userId = (int)$pdo->lastInsertId();
        $createdOrReset = 'created';
    }

    psu_send_credentials(
        $pdo,
        array(
            'id' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'email' => $email,
            'role_code' => $roleCode,
            'status' => $status
        ),
        $plainPassword
    );

    $pdo->commit();

    $message = 'Sample Platform Admin ' . $createdOrReset . ' successfully and login credentials were emailed.';

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('FieldPlx sample platform user error: ' . $e->getMessage());
    $messageType = 'error';
    $message = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FieldPlx Sample Platform User</title>
    <style>
    body {
        margin: 0;
        background: #f4f7fb;
        font-family: Arial, sans-serif;
        color: #172033;
        padding: 40px 16px
    }

    .card {
        max-width: 720px;
        margin: auto;
        background: #fff;
        border: 1px solid #dce4ef;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 18px 45px rgba(25, 41, 72, .10)
    }

    .head {
        background: #061d49;
        color: #fff;
        padding: 24px 28px
    }

    .head b {
        display: inline-block;
        background: #7ccd22;
        padding: 9px 12px;
        border-radius: 9px;
        font-size: 18px
    }

    .body {
        padding: 28px
    }

    .alert {
        padding: 14px 16px;
        border-radius: 10px;
        margin-bottom: 20px
    }

    .success {
        background: #eff9e8;
        border: 1px solid #c9e6ad;
        color: #38720b
    }

    .error {
        background: #fff0f0;
        border: 1px solid #f1bcbc;
        color: #a51d1d
    }

    .grid {
        display: grid;
        grid-template-columns: 180px 1fr;
        border: 1px solid #e1e7ef;
        border-radius: 12px;
        overflow: hidden
    }

    .grid div {
        padding: 12px 14px;
        border-bottom: 1px solid #edf1f6
    }

    .grid div:nth-last-child(-n+2) {
        border-bottom: 0
    }

    .label {
        background: #f8fafc;
        color: #69758b;
        font-weight: 700
    }

    .warning {
        margin-top: 20px;
        padding: 14px;
        border-radius: 10px;
        background: #fff8e8;
        border: 1px solid #f0ddb0;
        color: #8a6100;
        font-size: 13px;
        line-height: 1.6
    }

    @media(max-width:600px) {
        .grid {
            grid-template-columns: 1fr
        }

        .label {
            border-bottom: 0 !important
        }

        .grid div:nth-last-child(-n+2) {
            border-bottom: 1px solid #edf1f6
        }

        .grid div:last-child {
            border-bottom: 0
        }
    }
    </style>
</head>

<body>
    <div class="card">
        <div class="head"><b>F</b>
            <h2>FieldPlx Platform Sample User</h2>
        </div>
        <div class="body">
            <div class="alert <?= psu_escape($messageType); ?>"><?= psu_escape($message); ?></div>

            <?php if ($messageType === 'success'): ?>
            <div class="grid">
                <div class="label">Name</div>
                <div><?= psu_escape($firstName . ' ' . $lastName); ?></div>
                <div class="label">Email</div>
                <div><?= psu_escape($email); ?></div>
                <div class="label">Username</div>
                <div><?= psu_escape($username); ?></div>
                <div class="label">Temporary Password</div>
                <div><strong><?= psu_escape($plainPassword); ?></strong></div>
                <div class="label">Role</div>
                <div>Platform Admin</div>
                <div class="label">Status</div>
                <div>Active</div>
            </div>
            <?php endif; ?>

            <div class="warning">
                <strong>Important:</strong> This is a one-time setup utility. Delete
                <code>platform-sample-user.php</code> immediately after the account is created successfully.
            </div>
        </div>
    </div>
</body>

</html>