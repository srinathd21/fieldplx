<?php
require_once __DIR__ . '/includes/db.php';

/*
|--------------------------------------------------------------------------
| WEBSITE SMTP CONFIG
|--------------------------------------------------------------------------
| SMTP credentials are entered directly in includes/smtp-config.php.
| No SMTP database lookup and no encryption/decryption are used here.
*/
$websiteSmtpConfigFile = __DIR__ . '/includes/smtp-config.php';
if (!is_file($websiteSmtpConfigFile)) {
    throw new RuntimeException('Missing includes/smtp-config.php.');
}

$websiteSmtpConfig = require $websiteSmtpConfigFile;
if (!is_array($websiteSmtpConfig)) {
    throw new RuntimeException('includes/smtp-config.php must return an SMTP configuration array.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['website_csrf'])) {
    $_SESSION['website_csrf'] = bin2hex(random_bytes(32));
}
$websiteCsrf = $_SESSION['website_csrf'];

function websiteJson($success, $message, $extra = array(), $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array(
        'success' => (bool) $success,
        'message' => (string) $message
    ), $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function websitePost($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function websiteEnsureTables($conn)
{
    $sql1 = "CREATE TABLE IF NOT EXISTS website_leads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_type ENUM('contact','demo') NOT NULL,
        full_name VARCHAR(190) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        business_name VARCHAR(190) DEFAULT NULL,
        message TEXT DEFAULT NULL,
        preferred_date DATE DEFAULT NULL,
        preferred_time VARCHAR(50) DEFAULT NULL,
        status ENUM('new','contacted','qualified','closed','spam') NOT NULL DEFAULT 'new',
        source VARCHAR(120) NOT NULL DEFAULT 'website',
        ip_address VARCHAR(80) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_website_leads_type_status (lead_type, status),
        KEY idx_website_leads_email (email),
        KEY idx_website_leads_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql2 = "CREATE TABLE IF NOT EXISTS tenant_activation_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tenant_activation_token_hash (token_hash),
        KEY idx_tenant_activation_user (user_id),
        KEY idx_tenant_activation_expiry (expires_at),
        CONSTRAINT fk_tenant_activation_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        CONSTRAINT fk_tenant_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql1)) {
        throw new Exception('Unable to prepare website leads storage: ' . $conn->error);
    }
    if (!$conn->query($sql2)) {
        throw new Exception('Unable to prepare activation storage: ' . $conn->error);
    }
}

function websiteTenantCode($conn)
{
    for ($i = 0; $i < 20; $i++) {
        $code = 'TNT-' . date('ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("SELECT id FROM tenants WHERE tenant_code = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            return $code;
        }
    }
    return 'TNT-' . strtoupper(bin2hex(random_bytes(5)));
}

function websiteSplitName($fullName)
{
    $fullName = preg_replace('/\s+/', ' ', trim($fullName));
    $parts = $fullName === '' ? array('User') : explode(' ', $fullName, 2);
    return array($parts[0], isset($parts[1]) ? $parts[1] : null);
}

function websiteActivationUrl($rawToken)
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/index.php';
    $dir = rtrim(dirname($scriptName), '/.');
    return $scheme . '://' . $host . ($dir !== '' ? $dir : '') . '/activate-account.php?token=' . rawurlencode($rawToken);
}

function websiteLoadSmtpConfig()
{
    global $websiteSmtpConfig;

    if (!is_array($websiteSmtpConfig)) {
        throw new RuntimeException('Website SMTP configuration is unavailable.');
    }

    $required = array('host', 'port', 'username', 'password', 'from_email');
    foreach ($required as $key) {
        if (!isset($websiteSmtpConfig[$key]) || trim((string) $websiteSmtpConfig[$key]) === '') {
            throw new RuntimeException('SMTP configuration field "' . $key . '" is missing in includes/smtp-config.php.');
        }
    }

    if (!filter_var((string) $websiteSmtpConfig['from_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP from_email in includes/smtp-config.php is invalid.');
    }

    return $websiteSmtpConfig;
}

function websiteComposerAutoloadPath()
{
    $projectRoot = dirname(__DIR__);

    $paths = array(
        $projectRoot . '/vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php'
    );

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

function websiteLoadPhpMailer()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
        return;
    }

    $autoloadPath = websiteComposerAutoloadPath();
    if ($autoloadPath === '') {
        throw new RuntimeException(
            'Composer vendor/autoload.php was not found. PHPMailer is required for activation email.'
        );
    }

    require_once $autoloadPath;

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('PHPMailer could not be loaded from Composer autoload.php.');
    }
}

function websiteSendSmtpHtml($config, $toEmail, $toName, $subject, $html, $replyToOverride = '')
{
    websiteLoadPhpMailer();

    $password = isset($config['password']) ? (string) $config['password'] : '';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = trim((string) $config['host']);
    $mail->Port = (int) $config['port'];
    $mail->Timeout = isset($config['timeout']) ? (int) $config['timeout'] : 30;

    if (property_exists($mail, 'Timelimit')) {
        $mail->Timelimit = isset($config['timeout']) ? (int) $config['timeout'] : 30;
    }

    $mail->SMTPDebug = 0;
    $mail->SMTPKeepAlive = false;

    $username = trim((string) $config['username']);
    $mail->SMTPAuth = $username !== '';

    if ($mail->SMTPAuth) {
        if ($password === '') {
            throw new RuntimeException(
                'SMTP password is empty in includes/smtp-config.php.'
            );
        }

        $mail->Username = $username;
        $mail->Password = $password;
    }

    $encryption = strtolower(trim((string) $config['encryption']));

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

    $fromEmail = trim((string) $config['from_email']);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The configured SMTP From Email is invalid.');
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The activation recipient email is invalid.');
    }

    $fromName = trim((string) $config['from_name']);
    if ($fromName === '') {
        $fromName = 'FieldPlx';
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);

    $replyTo = trim((string) $replyToOverride);
    if ($replyTo === '') {
        $replyTo = isset($config['reply_to_email']) ? trim((string) $config['reply_to_email']) : '';
    }
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo($replyTo);
    }

    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $html;
    $mail->AltBody = strip_tags(
        str_replace(
            array('<br>', '<br/>', '<br />', '</p>'),
            array("\n", "\n", "\n", "\n"),
            $html
        )
    );

    $mail->send();
    return true;
}

function websiteSendActivationEmail($conn, $email, $name, $businessName, $activationUrl)
{
    $smtp = websiteLoadSmtpConfig();

    $subject = 'Activate your FieldPlx 60-Day Free Trial';
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeBusiness = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($activationUrl, ENT_QUOTES, 'UTF-8');

    $html = '<!doctype html><html><body style="margin:0;background:#f5f7fa;font-family:Arial,sans-serif;color:#14202b">'
        . '<div style="max-width:620px;margin:30px auto;background:#fff;border:1px solid #e4e8ec;border-radius:14px;overflow:hidden">'
        . '<div style="padding:24px 28px;background:#071c2f;color:#fff"><h2 style="margin:0">Welcome to FieldPlx</h2></div>'
        . '<div style="padding:28px"><p>Hi ' . $safeName . ',</p>'
        . '<p>Your <strong>60-day FieldPlx Free Trial</strong> workspace for <strong>' . $safeBusiness . '</strong> has been created.</p>'
        . '<p>Click the button below to create your password and activate your account.</p>'
        . '<p style="margin:26px 0"><a href="' . $safeUrl . '" style="display:inline-block;padding:13px 20px;background:#0b7a75;color:#fff;text-decoration:none;border-radius:8px;font-weight:700">Create Password &amp; Activate</a></p>'
        . '<p style="font-size:12px;color:#66727c">This activation link expires in 24 hours.</p>'
        . '<p style="font-size:12px;color:#66727c;word-break:break-all">' . $safeUrl . '</p>'
        . '</div></div></body></html>';

    return websiteSendSmtpHtml($smtp, $email, $name, $subject, $html);
}


function websiteMailEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function websiteSendNewTenantNotificationEmails($tenantId, $tenantCode, $businessName, $fullName, $businessType, $email, $phone, $trialDays, $startDate, $endDate)
{
    $smtp = websiteLoadSmtpConfig();

    $safeTenantId = websiteMailEscape($tenantId);
    $safeTenantCode = websiteMailEscape($tenantCode);
    $safeBusinessName = websiteMailEscape($businessName);
    $safeFullName = websiteMailEscape($fullName);
    $safeBusinessType = websiteMailEscape($businessType !== '' ? $businessType : '-');
    $safeEmail = websiteMailEscape($email);
    $safePhone = websiteMailEscape($phone !== '' ? $phone : '-');
    $safeTrialDays = websiteMailEscape($trialDays);
    $safeStartDate = websiteMailEscape($startDate);
    $safeEndDate = websiteMailEscape($endDate);

    $subject = 'New FieldPlx Tenant Onboarded - ' . $businessName;

    $html = '<!doctype html><html><body style="margin:0;background:#f5f7fa;font-family:Arial,sans-serif;color:#14202b">'
        . '<div style="max-width:660px;margin:30px auto;background:#fff;border:1px solid #e4e8ec;border-radius:14px;overflow:hidden">'
        . '<div style="padding:22px 28px;background:#071c2f;color:#fff"><h2 style="margin:0">New FieldPlx Tenant Onboarded</h2></div>'
        . '<div style="padding:26px 28px">'
        . '<p style="margin-top:0">A new tenant has started the FieldPlx free trial from the website.</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
        . '<tr><td style="padding:8px 0;color:#66727c;width:170px">Tenant ID</td><td style="padding:8px 0"><strong>' . $safeTenantId . '</strong></td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Tenant Code</td><td style="padding:8px 0"><strong>' . $safeTenantCode . '</strong></td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Business Name</td><td style="padding:8px 0"><strong>' . $safeBusinessName . '</strong></td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Admin Name</td><td style="padding:8px 0">' . $safeFullName . '</td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Business Type</td><td style="padding:8px 0">' . $safeBusinessType . '</td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Email</td><td style="padding:8px 0">' . $safeEmail . '</td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Phone</td><td style="padding:8px 0">' . $safePhone . '</td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Trial Period</td><td style="padding:8px 0">' . $safeTrialDays . ' days</td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Trial Start</td><td style="padding:8px 0">' . $safeStartDate . '</td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Trial End</td><td style="padding:8px 0">' . $safeEndDate . '</td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Account Status</td><td style="padding:8px 0"><strong>Invited / Awaiting Activation</strong></td></tr>'
        . '</table>'
        . '<p style="margin:24px 0 0;font-size:12px;color:#66727c">The customer has been sent an activation link to create their password.</p>'
        . '</div></div></body></html>';

    $recipients = array(
        array('email' => 'support@coreplx.com', 'name' => 'CorePlx Support'),
        array('email' => 'ary@coreplx.com', 'name' => 'Ary')
    );

    foreach ($recipients as $recipient) {
        websiteSendSmtpHtml(
            $smtp,
            $recipient['email'],
            $recipient['name'],
            $subject,
            $html,
            $email
        );
    }

    return true;
}

function websiteSendLeadNotificationEmails($leadType, $fullName, $email, $phone, $businessName, $message, $preferredDate, $preferredTime)
{
    $smtp = websiteLoadSmtpConfig();

    $isDemo = $leadType === 'demo';
    $safeName = websiteMailEscape($fullName);
    $safeEmail = websiteMailEscape($email);
    $safePhone = websiteMailEscape($phone !== '' ? $phone : '-');
    $safeBusiness = websiteMailEscape($businessName !== '' ? $businessName : '-');
    $safeMessage = nl2br(websiteMailEscape($message !== '' ? $message : '-'));
    $safeDate = websiteMailEscape($preferredDate !== '' ? $preferredDate : '-');
    $safeTime = websiteMailEscape($preferredTime !== '' ? $preferredTime : '-');

    $adminSubject = $isDemo
        ? 'New FieldPlx Demo Request - ' . $fullName
        : 'New FieldPlx Website Enquiry - ' . $fullName;

    $adminHeading = $isDemo ? 'New Book a Demo Request' : "New Let's Talk Enquiry";

    $adminHtml = '<!doctype html><html><body style="margin:0;background:#f5f7fa;font-family:Arial,sans-serif;color:#14202b">'
        . '<div style="max-width:650px;margin:30px auto;background:#fff;border:1px solid #e4e8ec;border-radius:14px;overflow:hidden">'
        . '<div style="padding:22px 28px;background:#071c2f;color:#fff"><h2 style="margin:0">' . websiteMailEscape($adminHeading) . '</h2></div>'
        . '<div style="padding:26px 28px">'
        . '<p style="margin-top:0">A new website enquiry has been received.</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px">'
        . '<tr><td style="padding:8px 0;color:#66727c;width:150px">Name</td><td style="padding:8px 0"><strong>' . $safeName . '</strong></td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Email</td><td style="padding:8px 0">' . $safeEmail . '</td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Phone</td><td style="padding:8px 0">' . $safePhone . '</td></tr>'
        . '<tr><td style="padding:8px 0;color:#66727c">Business</td><td style="padding:8px 0">' . $safeBusiness . '</td></tr>';

    if ($isDemo) {
        $adminHtml .= '<tr><td style="padding:8px 0;color:#66727c">Preferred Date</td><td style="padding:8px 0">' . $safeDate . '</td></tr>'
            . '<tr><td style="padding:8px 0;color:#66727c">Preferred Time</td><td style="padding:8px 0">' . $safeTime . '</td></tr>';
    }

    $adminHtml .= '<tr><td style="padding:8px 0;color:#66727c;vertical-align:top">Message</td><td style="padding:8px 0">' . $safeMessage . '</td></tr>'
        . '</table>'
        . '<p style="margin:24px 0 0;font-size:12px;color:#66727c">You can reply directly to this email to contact ' . $safeName . '.</p>'
        . '</div></div></body></html>';

    $adminRecipients = array(
        array('email' => 'support@coreplx.com', 'name' => 'CorePlx Support'),
        array('email' => 'ary@coreplx.com', 'name' => 'Ary')
    );

    foreach ($adminRecipients as $recipient) {
        websiteSendSmtpHtml(
            $smtp,
            $recipient['email'],
            $recipient['name'],
            $adminSubject,
            $adminHtml,
            $email
        );
    }

    $customerSubject = $isDemo
        ? 'Thank you for your interest in FieldPlx'
        : 'Thank you for reaching out to FieldPlx';

    if ($isDemo) {
        $customerMessage = '<p>Thank you for your interest in <strong>FieldPlx</strong>.</p>'
            . '<p>We have received your demo request and our team will contact you soon to confirm a convenient time and show you how FieldPlx can help manage your field service operations.</p>';
    } else {
        $customerMessage = '<p>Thank you for reaching out to <strong>FieldPlx</strong>.</p>'
            . '<p>We have received your message. Our team will review your enquiry and contact you soon.</p>';
    }

    $customerHtml = '<!doctype html><html><body style="margin:0;background:#f5f7fa;font-family:Arial,sans-serif;color:#14202b">'
        . '<div style="max-width:620px;margin:30px auto;background:#fff;border:1px solid #e4e8ec;border-radius:14px;overflow:hidden">'
        . '<div style="padding:24px 28px;background:#071c2f;color:#fff"><h2 style="margin:0">FieldPlx</h2></div>'
        . '<div style="padding:28px"><p>Hi ' . $safeName . ',</p>'
        . $customerMessage
        . '<p>If you need anything in the meantime, you can reply to this email or contact us at <strong>support@coreplx.com</strong>.</p>'
        . '<p style="margin-top:24px">Regards,<br><strong>FieldPlx Team</strong></p>'
        . '</div></div></body></html>';

    websiteSendSmtpHtml(
        $smtp,
        $email,
        $fullName,
        $customerSubject,
        $customerHtml
    );

    return true;
}

/* Account activation is handled by activate-account.php. */

/* Handle all website AJAX POSTs in THIS SAME PAGE. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['website_action'])) {
    try {
        websiteEnsureTables($conn);

        $postedToken = websitePost('csrf_token');
        if ($postedToken === '' || !hash_equals((string) $_SESSION['website_csrf'], $postedToken)) {
            websiteJson(false, 'Your session expired. Please refresh the page and try again.', array(), 419);
        }

        $action = websitePost('website_action');

        if ($action === 'save_lead') {
            $leadType = websitePost('lead_type');
            $fullName = websitePost('full_name');
            $email = strtolower(websitePost('email'));
            $phone = websitePost('phone');
            $businessName = websitePost('business_name');
            $message = websitePost('message');
            $preferredDate = websitePost('preferred_date');
            $preferredTime = websitePost('preferred_time');

            if (!in_array($leadType, array('contact', 'demo'), true)) {
                websiteJson(false, 'Invalid request type.', array(), 422);
            }
            if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                websiteJson(false, 'Please enter your name and a valid email address.', array(), 422);
            }
            if ($leadType === 'demo' && $businessName === '') {
                websiteJson(false, 'Please enter your business name.', array(), 422);
            }
            if ($preferredDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferredDate)) {
                websiteJson(false, 'Invalid preferred date.', array(), 422);
            }

            $preferredDateDb = $preferredDate !== '' ? $preferredDate : null;
            $preferredTimeDb = $preferredTime !== '' ? $preferredTime : null;
            $phoneDb = $phone !== '' ? $phone : null;
            $businessDb = $businessName !== '' ? $businessName : null;
            $messageDb = $message !== '' ? $message : null;
            $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 80) : null;
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : null;

            $stmt = $conn->prepare("INSERT INTO website_leads
                (lead_type, full_name, email, phone, business_name, message, preferred_date, preferred_time, source, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'website', ?, ?, NOW())");
            $stmt->bind_param('ssssssssss', $leadType, $fullName, $email, $phoneDb, $businessDb, $messageDb, $preferredDateDb, $preferredTimeDb, $ip, $ua);
            if (!$stmt->execute()) {
                throw new Exception('Unable to save website enquiry: ' . $stmt->error);
            }
            $stmt->close();

            $mailSent = true;
            $mailErrorMessage = '';

            try {
                websiteSendLeadNotificationEmails(
                    $leadType,
                    $fullName,
                    $email,
                    $phone,
                    $businessName,
                    $message,
                    $preferredDate,
                    $preferredTime
                );
            } catch (Throwable $leadMailError) {
                $mailSent = false;
                $mailErrorMessage = $leadMailError->getMessage();
                error_log(
                    'FieldPlx website lead email error [' . $leadType . '] for ' .
                    $email . ': ' . $mailErrorMessage
                );
            }

            websiteJson(
                true,
                $leadType === 'demo'
                    ? 'Thank you for your interest in FieldPlx. Your demo request has been received and our team will contact you soon.'
                    : 'Thank you for reaching out to FieldPlx. Our team will contact you soon.',
                array(
                    'mail_sent' => $mailSent
                ),
                200
            );
        }

        if ($action === 'start_trial') {
            $businessName = websitePost('business_name');
            $fullName = websitePost('full_name');
            $businessType = websitePost('business_type');
            $email = strtolower(websitePost('email'));
            $phone = websitePost('phone');
            $countryId = (int) websitePost('country_id', '0');

            if ($businessName === '' || $fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $countryId <= 0) {
                websiteJson(false, 'Please complete all required fields with a valid email address.', array(), 422);
            }

            $countryStmt = $conn->prepare("SELECT id, default_currency_code, default_timezone, date_format FROM countries WHERE id = ? AND is_active = 1 LIMIT 1");
            $countryStmt->bind_param('i', $countryId);
            $countryStmt->execute();
            $countryResult = $countryStmt->get_result();
            $country = $countryResult ? $countryResult->fetch_assoc() : null;
            $countryStmt->close();
            if (!$country) {
                websiteJson(false, 'Please select a valid country.', array(), 422);
            }

            $currencyCode = !empty($country['default_currency_code']) ? $country['default_currency_code'] : 'USD';
            $currencyStmt = $conn->prepare("SELECT id FROM currencies WHERE currency_code = ? AND is_active = 1 LIMIT 1");
            $currencyStmt->bind_param('s', $currencyCode);
            $currencyStmt->execute();
            $currencyStmt->bind_result($currencyId);
            if (!$currencyStmt->fetch()) {
                $currencyStmt->close();
                websiteJson(false, 'The selected country does not have an active currency configured.', array(), 422);
            }
            $currencyStmt->close();
            $currencyId = (int) $currencyId;

            $planStmt = $conn->prepare("SELECT id, name, code, price, trial_days, duration_days FROM plans WHERE code = 'trial' AND status = 'active' AND deleted_at IS NULL LIMIT 1");
            $planStmt->execute();
            $planResult = $planStmt->get_result();
            $plan = $planResult ? $planResult->fetch_assoc() : null;
            $planStmt->close();
            if (!$plan) {
                websiteJson(false, 'The Free Trial plan is not active. Please contact support.', array(), 503);
            }

            $trialDays = 60;
            if (!empty($plan['trial_days'])) {
                $trialDays = (int) $plan['trial_days'];
            } elseif (!empty($plan['duration_days'])) {
                $trialDays = (int) $plan['duration_days'];
            }
            if ($trialDays <= 0) {
                $trialDays = 60;
            }

            $existingStmt = $conn->prepare("SELECT u.id, u.tenant_id, u.status FROM users u INNER JOIN tenants t ON t.id = u.tenant_id WHERE LOWER(u.email) = LOWER(?) AND u.deleted_at IS NULL AND t.deleted_at IS NULL LIMIT 1");
            $existingStmt->bind_param('s', $email);
            $existingStmt->execute();
            $existingResult = $existingStmt->get_result();
            $existingUser = $existingResult ? $existingResult->fetch_assoc() : null;
            $existingStmt->close();
            if ($existingUser) {
                websiteJson(false, 'An account already exists for this email. Please use the login page or contact support.', array(), 409);
            }

            $conn->begin_transaction();
            try {
                $tenantCode = websiteTenantCode($conn);
                $timezone = !empty($country['default_timezone']) ? $country['default_timezone'] : 'UTC';
                $dateFormat = !empty($country['date_format']) ? $country['date_format'] : 'd-m-Y';
                $phoneDb = $phone !== '' ? $phone : null;
                $businessTypeDb = $businessType !== '' ? $businessType : null;
                $subscriptionPlan = 'trial';

                $tenantStmt = $conn->prepare("INSERT INTO tenants
                    (tenant_code, legal_name, display_name, business_type, email, phone, country_id, currency_id, timezone, date_format, status, subscription_plan, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'trial', ?, NOW())");
                $tenantStmt->bind_param('ssssssiisss', $tenantCode, $businessName, $businessName, $businessTypeDb, $email, $phoneDb, $countryId, $currencyId, $timezone, $dateFormat, $subscriptionPlan);
                if (!$tenantStmt->execute()) {
                    throw new Exception('Tenant creation failed: ' . $tenantStmt->error);
                }
                $tenantId = (int) $tenantStmt->insert_id;
                $tenantStmt->close();

                $roleName = 'Admin';
                $roleCode = 'admin';
                $roleDescription = 'Tenant administrator';
                $roleStmt = $conn->prepare("INSERT INTO roles (tenant_id, name, code, description, is_admin, is_system_role, status, created_at) VALUES (?, ?, ?, ?, 1, 1, 'active', NOW())");
                $roleStmt->bind_param('isss', $tenantId, $roleName, $roleCode, $roleDescription);
                if (!$roleStmt->execute()) {
                    throw new Exception('Admin role creation failed: ' . $roleStmt->error);
                }
                $roleId = (int) $roleStmt->insert_id;
                $roleStmt->close();

                list($firstName, $lastName) = websiteSplitName($fullName);
                $temporaryPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $employeeCode = 'ADM001';
                $userStmt = $conn->prepare("INSERT INTO users
                    (tenant_id, role_id, employee_code, first_name, last_name, email, phone, password_hash, is_bookable, is_field_worker, is_tenant_admin, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1, 'invited', NOW())");
                $userStmt->bind_param('iissssss', $tenantId, $roleId, $employeeCode, $firstName, $lastName, $email, $phoneDb, $temporaryPasswordHash);
                if (!$userStmt->execute()) {
                    throw new Exception('Admin user creation failed: ' . $userStmt->error);
                }
                $userId = (int) $userStmt->insert_id;
                $userStmt->close();

                $startDate = date('Y-m-d');
                $endDate = date('Y-m-d', strtotime('+' . $trialDays . ' days'));
                $amount = 0.00;
                $planId = (int) $plan['id'];
                $subscriptionStmt = $conn->prepare("INSERT INTO subscriptions
                    (tenant_id, plan_id, currency_id, amount, start_date, expiry_date, trial_end_date, auto_renew, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'trial', NOW())");
                $subscriptionStmt->bind_param('iiidsss', $tenantId, $planId, $currencyId, $amount, $startDate, $endDate, $endDate);
                if (!$subscriptionStmt->execute()) {
                    throw new Exception('Trial subscription creation failed: ' . $subscriptionStmt->error);
                }
                $subscriptionId = (int) $subscriptionStmt->insert_id;
                $subscriptionStmt->close();

                $historyAction = 'trial_started';
                $historyJson = json_encode(array('plan_id' => $planId, 'trial_days' => $trialDays, 'expiry_date' => $endDate));
                $historyStmt = $conn->prepare("INSERT INTO subscription_history (tenant_id, subscription_id, new_plan_id, action, effective_at, new_values, created_at) VALUES (?, ?, ?, ?, NOW(), ?, NOW())");
                $historyStmt->bind_param('iiiss', $tenantId, $subscriptionId, $planId, $historyAction, $historyJson);
                if (!$historyStmt->execute()) {
                    throw new Exception('Subscription history creation failed: ' . $historyStmt->error);
                }
                $historyStmt->close();

                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $tokenStmt = $conn->prepare("INSERT INTO tenant_activation_tokens (tenant_id, user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())");
                $tokenStmt->bind_param('iis', $tenantId, $userId, $tokenHash);
                if (!$tokenStmt->execute()) {
                    throw new Exception('Activation token creation failed: ' . $tokenStmt->error);
                }
                $tokenStmt->close();

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }

            $activationUrl = websiteActivationUrl($rawToken);

            /*
             * The workspace is already committed at this point.
             * A mail problem must not make tenant creation look like it failed.
             */
            $activationMailSent = true;
            $internalMailSent = true;

            try {
                websiteSendActivationEmail(
                    $conn,
                    $email,
                    $fullName,
                    $businessName,
                    $activationUrl
                );
            } catch (Throwable $mailError) {
                $activationMailSent = false;
                error_log(
                    'FieldPlx trial activation SMTP error for tenant '
                    . $tenantId
                    . ' / '
                    . $email
                    . ': '
                    . $mailError->getMessage()
                );
            }

            try {
                websiteSendNewTenantNotificationEmails(
                    $tenantId,
                    $tenantCode,
                    $businessName,
                    $fullName,
                    $businessType,
                    $email,
                    $phone,
                    $trialDays,
                    $startDate,
                    $endDate
                );
            } catch (Throwable $internalMailError) {
                $internalMailSent = false;
                error_log(
                    'FieldPlx new tenant internal notification error for tenant '
                    . $tenantId
                    . ' / '
                    . $email
                    . ': '
                    . $internalMailError->getMessage()
                );
            }

            if ($activationMailSent) {
                websiteJson(
                    true,
                    'Your 60-day Free Trial is ready. Check your email for the activation link to create your password.',
                    array(
                        'mail_sent' => true,
                        'internal_mail_sent' => $internalMailSent,
                        'tenant_id' => $tenantId
                    ),
                    200
                );
            }

            websiteJson(
                true,
                'Your 60-day Free Trial workspace was created successfully, but the activation email could not be sent. Please contact support.',
                array(
                    'mail_sent' => false,
                    'internal_mail_sent' => $internalMailSent,
                    'tenant_id' => $tenantId
                ),
                200
            );
        }

        websiteJson(false, 'Unknown website action.', array(), 400);
    } catch (Throwable $e) {
        error_log('FieldPlx website form error: ' . $e->getMessage());
        websiteJson(false, 'Unable to process your request: ' . $e->getMessage(), array(), 500);
    }
}

$trialCountries = array();
$countryQuery = $conn->query("SELECT id, name, iso2 FROM countries WHERE is_active = 1 ORDER BY name ASC");
if ($countryQuery) {
    while ($row = $countryQuery->fetch_assoc()) {
        $trialCountries[] = $row;
    }
    $countryQuery->free();
}

$pageTitle = 'FieldPlx - Smarter Operations. Stronger Business.';
include __DIR__ . '/topbar.php';
?>

<style>
.fp-web-modal{position:fixed;inset:0;z-index:5000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(4,16,29,.62);backdrop-filter:blur(4px)}
.fp-web-modal.show{display:flex}.fp-web-modal-card{width:min(620px,100%);max-height:calc(100vh - 36px);overflow:auto;border-radius:18px;background:#fff;box-shadow:0 28px 80px rgba(0,0,0,.28)}
.fp-web-modal-head{padding:20px 22px 14px;display:flex;justify-content:space-between;gap:18px;border-bottom:1px solid #e7eaed}.fp-web-modal-head h3{margin:0;color:#071c2f;font-size:22px}.fp-web-modal-head p{margin:5px 0 0;color:#65717d;font-size:13px}.fp-web-modal-close{width:38px;height:38px;border:1px solid #dce2e6;border-radius:10px;background:#fff;font-size:24px;line-height:1;cursor:pointer}
.fp-web-modal-body{padding:20px 22px 22px}.fp-web-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.fp-web-field.full{grid-column:1/-1}.fp-web-field label{display:block;margin-bottom:6px;font-size:12px;font-weight:700;color:#263643}.fp-web-field .form-control,.fp-web-field .form-select{min-height:44px;font-size:14px}.fp-web-actions{margin-top:18px;display:flex;justify-content:flex-end;gap:10px}.fp-web-note{margin:0 0 16px;padding:11px 13px;border-radius:10px;background:#effaf1;color:#2f6d39;font-size:12px}.fp-web-alert{display:none;margin-bottom:14px;padding:11px 13px;border-radius:10px;font-size:13px}.fp-web-alert.show{display:block}.fp-web-alert.error{background:#fef2f2;color:#b91c1c}.fp-web-alert.success{background:#ecfdf5;color:#047857}.fp-web-submit[disabled]{opacity:.65;cursor:not-allowed}
@media(max-width:600px){.fp-web-grid{grid-template-columns:1fr}.fp-web-field.full{grid-column:auto}.fp-web-modal-head,.fp-web-modal-body{padding-left:16px;padding-right:16px}.fp-web-actions{flex-direction:column-reverse}.fp-web-actions .btn{width:100%}}
</style>

  <main>
    <section id="home" class="hero-section">
      <div class="container-fluid site-container h-100">
        <div class="row h-100 align-items-center">
          <div class="col-lg-5 hero-copy">
            <h1>Smarter Operations.<br><span>Stronger Business.</span></h1>
            <p class="hero-lead">FieldPlx is the all-in-one field service management software built for small and mid-sized businesses. Streamline operations, dispatch smarter, and get paid faster—all in one powerful platform.</p>

            <div class="row row-cols-2 row-cols-md-4 g-3 hero-benefits">
              <div class="col"><div class="benefit"><i class="bi bi-check-circle-fill"></i><span>All-in-One<br>Platform</span></div></div>
              <div class="col"><div class="benefit"><i class="bi bi-check-circle-fill"></i><span>Easy to<br>Use</span></div></div>
              <div class="col"><div class="benefit"><i class="bi bi-check-circle-fill"></i><span>Works<br>Anywhere</span></div></div>
              <div class="col"><div class="benefit"><i class="bi bi-check-circle-fill"></i><span>Built for<br>Growth</span></div></div>
            </div>

            <div class="d-flex flex-wrap gap-3 hero-buttons">
              <button type="button" class="btn btn-brand btn-lg hero-primary" data-open-modal="trialModal">Start Your Free Trial <i class="bi bi-arrow-right ms-2"></i></button>
              <button type="button" class="btn btn-outline-brand btn-lg" data-open-modal="demoModal">Book a Demo</button>
            </div>

            <div class="offer-card">
              <div class="offer-icon"><i class="bi bi-gift"></i></div>
              <div>
                <div class="offer-kicker">LIMITED TIME OFFER</div>
                <div class="offer-title">60 Days Free Trial!</div>
                <div class="offer-subtitle">No credit card required. Cancel anytime.</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section id="industries" class="industry-section">
      <div class="container-fluid site-container">
        <h2 class="section-heading">Built for <span>Every</span> Field Service Business</h2>

        <div id="industrySlider" class="industry-slider" aria-label="Field service industries">
          <button class="industry-slider-arrow industry-slider-prev" type="button" aria-label="Previous industries">
            <span aria-hidden="true">&#8249;</span>
          </button>

          <div class="industry-slider-viewport">
            <div class="industry-slider-track">
              <article class="industry-card"><img src="site-assets/industry/industry-01.png" alt="HVAC"><div>HVAC</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-02.png" alt="Electrical"><div>Electrical</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-03.png" alt="Plumbing"><div>Plumbing</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-04.png" alt="Landscaping"><div>Landscaping</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-05.png" alt="Cleaning Services"><div>Cleaning Services</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-06.png" alt="Pest Control"><div>Pest Control</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-07.png" alt="Handyman"><div>Handyman</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-08.png" alt="Roofing"><div>Roofing</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-09.png" alt="Painting"><div>Painting</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-10.png" alt="Pool Service"><div>Pool Service</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-11.png" alt="Appliance Repair"><div>Appliance Repair</div></article>
              <article class="industry-card"><img src="site-assets/industry/industry-12.png" alt="Junk Removal"><div>Junk Removal</div></article>
            </div>
          </div>

          <button class="industry-slider-arrow industry-slider-next" type="button" aria-label="Next industries">
            <span aria-hidden="true">&#8250;</span>
          </button>
        </div>
      </div>
    </section>

    <section id="features" class="features-strip">
      <div class="container-fluid site-container">
        <div class="row g-0 feature-row">
          <div class="col-md feature-box">
            <i class="bi bi-cpu"></i>
            <div><h3>Built with<br>Advanced Tech Stack</h3><p>Modern, secure, and reliable technology that grows with your business.</p></div>
          </div>
          <div class="col-md feature-box">
            <i class="bi bi-people-fill"></i>
            <div><h3>Small Business<br>Friendly</h3><p>Simple to use, quick to set up, and affordable for businesses of all sizes.</p></div>
          </div>
          <div class="col-md feature-box">
            <i class="bi bi-headset"></i>
            <div><h3>U.S.-Based<br>Tech Support</h3><p>Real people. Real support. Right here in the USA when you need it.</p></div>
          </div>
          <div class="col-md feature-box">
            <i class="bi bi-currency-dollar"></i>
            <div><h3>40% to 50%<br>Cost Savings</h3><p>Save significantly compared to other field service software.</p></div>
          </div>
          <div class="col-md feature-box">
            <div class="us-flag" aria-hidden="true"><span></span></div>
            <div><h3>Born in the Heartland<br>Made in Ohio, USA</h3><p>Built with heart, integrity, and American values.</p></div>
          </div>
        </div>
      </div>
    </section>

    <section id="contact" class="contact-section">
      <div class="container-fluid site-container">
        <div class="row g-3 align-items-stretch">
          <div class="col-lg-4">
            <div class="content-card testimonial-card h-100">
              <h2>Trusted by Field Service Pros</h2>
              <div class="stars">★★★★★</div>
              <blockquote>“FieldPlx has completely transformed how we run our business. We save hours every week and our customers love the updates.”</blockquote>
              <div class="d-flex align-items-center gap-3">
                <img src="site-assets/avatar.png" alt="Customer avatar" class="avatar">
                <div><strong>Mike T.</strong><br><span>Vinrock HVAC</span></div>
              </div>
            </div>
          </div>
          <div class="col-lg-3">
            <div class="content-card photo-card h-100"><img src="site-assets/technician.png" alt="Field service technician"></div>
          </div>
          <div class="col-lg-5">
            <div class="content-card contact-card h-100">
              <div class="row g-3 h-100">
                <div class="col-md-6">
                  <h2>Let’s Talk</h2>
                  <p>Have questions? We’re here to help.</p>
                  <ul class="contact-list">
                    <li><i class="bi bi-telephone"></i><strong>5134464241</strong></li>
                    <li><i class="bi bi-envelope"></i><strong>support@coreplx.com</strong></li>
                    <li><i class="bi bi-geo-alt"></i><strong>Born in the Heartland<br>Made in Ohio, USA</strong></li>
                  </ul>
                </div>
                <div class="col-md-6">
                  <form class="contact-form" id="contactLeadForm" method="post">
                    <input type="hidden" name="website_action" value="save_lead">
                    <input type="hidden" name="csrf_token" value="<?= e($websiteCsrf) ?>">
                    <input type="hidden" name="lead_type" value="contact">
                    <div class="fp-web-alert" data-form-alert></div>
                    <input class="form-control" type="text" name="full_name" placeholder="Full Name" aria-label="Full Name" required>
                    <input class="form-control" type="email" name="email" placeholder="Email Address" aria-label="Email Address" required>
                    <input class="form-control" type="tel" name="phone" placeholder="Phone Number" aria-label="Phone Number">
                    <textarea class="form-control" name="message" rows="2" placeholder="How can we help?" aria-label="How can we help?"></textarea>
                    <button type="submit" class="btn btn-brand w-100 fp-web-submit">Get in Touch</button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>


<!-- FREE TRIAL ONBOARDING MODAL -->
<div class="fp-web-modal" id="trialModal" role="dialog" aria-modal="true" aria-labelledby="trialModalTitle">
  <div class="fp-web-modal-card">
    <div class="fp-web-modal-head">
      <div><h3 id="trialModalTitle">Start Your 60-Day Free Trial</h3><p>We will create your FieldPlx workspace and email you an activation link.</p></div>
      <button type="button" class="fp-web-modal-close" data-close-modal aria-label="Close">&times;</button>
    </div>
    <div class="fp-web-modal-body">
      <div class="fp-web-note"><strong>60 days free.</strong>You will create your password securely from the activation email.</div>
      <form id="trialOnboardingForm" method="post">
        <input type="hidden" name="website_action" value="start_trial">
        <input type="hidden" name="csrf_token" value="<?= e($websiteCsrf) ?>">
        <div class="fp-web-alert" data-form-alert></div>
        <div class="fp-web-grid">
          <div class="fp-web-field full"><label>Business Name *</label><input class="form-control" type="text" name="business_name" maxlength="190" required></div>
          <div class="fp-web-field"><label>Your Full Name *</label><input class="form-control" type="text" name="full_name" maxlength="190" required></div>
          <div class="fp-web-field"><label>Business Type</label><input class="form-control" type="text" name="business_type" maxlength="120" placeholder="HVAC, Plumbing, Electrical..."></div>
          <div class="fp-web-field"><label>Business Email *</label><input class="form-control" type="email" name="email" maxlength="190" required></div>
          <div class="fp-web-field"><label>Phone</label><input class="form-control" type="tel" name="phone" maxlength="50"></div>
          <div class="fp-web-field full">
    <label>Country *</label>
    <select class="form-select" name="country_id" required>
        <option value="">Select country</option>

        <?php foreach ($trialCountries as $country): ?>
            <option value="<?= (int)$country['id'] ?>"
                <?= $country['name'] === 'United States' ? 'selected' : '' ?>>
                <?= e($country['name']) ?>
                <?= !empty($country['iso2']) ? ' (' . e($country['iso2']) . ')' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
        </div>
        <div class="fp-web-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-brand fp-web-submit">Create Free Trial</button></div>
      </form>
    </div>
  </div>
</div>

<!-- BOOK DEMO MODAL -->
<div class="fp-web-modal" id="demoModal" role="dialog" aria-modal="true" aria-labelledby="demoModalTitle">
  <div class="fp-web-modal-card">
    <div class="fp-web-modal-head">
      <div><h3 id="demoModalTitle">Book a Demo</h3><p>Tell us a convenient date and time. We will contact you to confirm.</p></div>
      <button type="button" class="fp-web-modal-close" data-close-modal aria-label="Close">&times;</button>
    </div>
    <div class="fp-web-modal-body">
      <form id="demoLeadForm" method="post">
        <input type="hidden" name="website_action" value="save_lead">
        <input type="hidden" name="csrf_token" value="<?= e($websiteCsrf) ?>"><input type="hidden" name="lead_type" value="demo"><div class="fp-web-alert" data-form-alert></div>
        <div class="fp-web-grid">
          <div class="fp-web-field"><label>Full Name *</label><input class="form-control" type="text" name="full_name" required></div>
          <div class="fp-web-field"><label>Business Name *</label><input class="form-control" type="text" name="business_name" required></div>
          <div class="fp-web-field"><label>Email *</label><input class="form-control" type="email" name="email" required></div>
          <div class="fp-web-field"><label>Phone</label><input class="form-control" type="tel" name="phone"></div>
          <div class="fp-web-field"><label>Preferred Date</label><input class="form-control" type="date" name="preferred_date" min="<?= date('Y-m-d') ?>"></div>
          <div class="fp-web-field"><label>Preferred Time</label><input class="form-control" type="time" name="preferred_time"></div>
          <div class="fp-web-field full"><label>Anything we should know?</label><textarea class="form-control" name="message" rows="3"></textarea></div>
        </div>
        <div class="fp-web-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-brand fp-web-submit">Request Demo</button></div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';
function openModal(id){var m=document.getElementById(id);if(m){m.classList.add('show');document.body.style.overflow='hidden';}}
function closeModal(m){if(m){m.classList.remove('show');document.body.style.overflow='';}}
document.querySelectorAll('[data-open-modal]').forEach(function(b){b.addEventListener('click',function(){openModal(b.getAttribute('data-open-modal'));});});
document.querySelectorAll('[data-close-modal]').forEach(function(b){b.addEventListener('click',function(){closeModal(b.closest('.fp-web-modal'));});});
document.querySelectorAll('.fp-web-modal').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m){closeModal(m);}});});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.querySelectorAll('.fp-web-modal.show').forEach(closeModal);}});
function alertBox(form,type,msg){var x=form.querySelector('[data-form-alert]');if(x){x.className='fp-web-alert show '+type;x.textContent=msg;}}
function submit(form,done){
  if(!form.checkValidity()){form.reportValidity();return;}
  var x=form.querySelector('[data-form-alert]');
  if(x){x.className='fp-web-alert';x.textContent='';}
  var btn=form.querySelector('.fp-web-submit');
  var old=btn.textContent;
  btn.disabled=true;
  btn.textContent='Please wait...';

  fetch(window.location.pathname,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}})
    .then(function(r){
      return r.text().then(function(t){
        var d;
        try{d=JSON.parse(t);}catch(e){
          console.error('FieldPlx server response:',t);
          throw new Error('The server returned an invalid response. Please check the PHP error log.');
        }
        return {ok:r.ok,data:d};
      });
    })
    .then(function(r){
      if(!r.ok||!r.data.success){throw new Error(r.data.message||'Unable to submit.');}
      alertBox(form,'success',r.data.message||'Submitted successfully.');
      if(done){done(r.data);}
    })
    .catch(function(e){alertBox(form,'error',e.message||'Unable to submit.');})
    .then(function(){btn.disabled=false;btn.textContent=old;});
}
var trial=document.getElementById('trialOnboardingForm');if(trial){trial.addEventListener('submit',function(e){e.preventDefault();submit(trial,function(d){if(d.mail_sent){trial.reset();}});});}
var demo=document.getElementById('demoLeadForm');if(demo){demo.addEventListener('submit',function(e){e.preventDefault();submit(demo,function(){demo.reset();});});}
var contact=document.getElementById('contactLeadForm');if(contact){contact.addEventListener('submit',function(e){e.preventDefault();submit(contact,function(){contact.reset();});});}
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
