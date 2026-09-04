<?php
$pageTitle = 'Contact - FieldPlx';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/*
 * Use the website's single PDO bootstrap.
 * Do not create another connection and do not include a second db.php.
 */
require_once __DIR__ . '/platform/includes/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  throw new RuntimeException('FieldPlx database connection is not available.');
}

if (empty($_SESSION['fieldplx_contact_csrf']) || !is_string($_SESSION['fieldplx_contact_csrf'])) {
  $_SESSION['fieldplx_contact_csrf'] = bin2hex(random_bytes(32));
}

$contactCsrf = $_SESSION['fieldplx_contact_csrf'];
$contactSuccess = false;
$contactError = '';

function fieldplxContactValue($key)
{
  if (!isset($_POST[$key]) || is_array($_POST[$key])) {
    return '';
  }
  return trim((string) $_POST[$key]);
}

function fieldplxContactSmtpSecretKey()
{
  $secretFile = __DIR__ . '/platform/includes/smtp-secret.php';
  if (is_file($secretFile)) {
    require_once $secretFile;
  }

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
    throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY is not configured.');
  }
  if (strlen($key) < 32) {
    throw new RuntimeException('FIELDPLX_SMTP_ENCRYPTION_KEY must contain at least 32 characters.');
  }
  return hash('sha256', $key, true);
}

function fieldplxContactDecryptSmtpPassword($stored)
{
  $stored = (string) $stored;
  if ($stored === '' || strpos($stored, 'v1:') !== 0) return '';

  $raw = base64_decode(substr($stored, 3), true);
  if ($raw === false || strlen($raw) <= 16) return '';

  $iv = substr($raw, 0, 16);
  $cipher = substr($raw, 16);
  $plain = openssl_decrypt(
    $cipher,
    'AES-256-CBC',
    fieldplxContactSmtpSecretKey(),
    OPENSSL_RAW_DATA,
    $iv
  );

  return $plain === false ? '' : $plain;
}

function fieldplxContactLoadPhpMailer()
{
  if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) return;

  $projectRoot = __DIR__;
  $paths = array(
    $projectRoot . '/vendor/autoload.php',
    $projectRoot . '/platform/vendor/autoload.php',
    dirname($projectRoot) . '/vendor/autoload.php'
  );

  foreach ($paths as $path) {
    if (is_file($path)) {
      require_once $path;
      break;
    }
  }

  if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    throw new RuntimeException('PHPMailer could not be loaded.');
  }
}

function fieldplxContactDefaultPlatformSmtp(PDO $pdo)
{
  /* The default Platform SMTP row defines the platform notification email. */
  $stmt = $pdo->query("
    SELECT *
    FROM smtp_configurations
    WHERE scope_type = 'platform'
      AND is_active = 1
      AND is_default = 1
    ORDER BY id DESC
    LIMIT 1
  ");
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ? $row : null;
}

function fieldplxContactPlatformMailTransport(PDO $pdo)
{
  /*
   * Prefer a Platform SMTP configuration that has already tested successfully.
   * If none has a successful test, fall back to the active default transport.
   * The recipient still comes from the DEFAULT platform row.
   */
  $stmt = $pdo->query("
    SELECT *
    FROM smtp_configurations
    WHERE scope_type = 'platform'
      AND is_active = 1
    ORDER BY
      CASE WHEN last_test_status = 'success' THEN 0 ELSE 1 END ASC,
      is_default DESC,
      id DESC
    LIMIT 1
  ");
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ? $row : null;
}

function fieldplxContactReasonLabel($reason)
{
  $labels = array(
    'book_demo' => 'Book a demonstration',
    'start_trial' => 'Start a free trial',
    'product_pricing' => 'Product or pricing question',
    'technical_support' => 'Technical support',
    'partnership' => 'Partnership opportunity',
    'media' => 'Media inquiry',
    'general' => 'General question'
  );
  return isset($labels[$reason]) ? $labels[$reason] : $reason;
}

function fieldplxContactSendPlatformCopy(PDO $pdo, array $data)
{
  $defaultSmtp = fieldplxContactDefaultPlatformSmtp($pdo);
  if (!$defaultSmtp) {
    return array('sent' => false, 'message' => 'No active default Platform SMTP configuration is available.');
  }

  $smtp = fieldplxContactPlatformMailTransport($pdo);
  if (!$smtp) {
    return array('sent' => false, 'message' => 'No active Platform SMTP transport is available.');
  }

  try {
    fieldplxContactLoadPhpMailer();

    /* Always send the copy TO the default Platform email. */
    $recipient = '';
    foreach (array('reply_to_email', 'from_email', 'username') as $field) {
      $candidate = isset($defaultSmtp[$field]) ? trim((string) $defaultSmtp[$field]) : '';
      if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
        $recipient = $candidate;
        break;
      }
    }
    if ($recipient === '') {
      throw new RuntimeException('Default Platform SMTP recipient email is not valid.');
    }

    $password = fieldplxContactDecryptSmtpPassword(
      isset($smtp['password_encrypted']) ? $smtp['password_encrypted'] : ''
    );

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string) $smtp['host']);
    $mail->Port = (int) $smtp['port'];
    $mail->Timeout = 20;
    if (property_exists($mail, 'Timelimit')) $mail->Timelimit = 20;
    $mail->SMTPDebug = 0;

    $username = trim((string) $smtp['username']);
    $mail->SMTPAuth = $username !== '';
    if ($mail->SMTPAuth) {
      if ($password === '') throw new RuntimeException('SMTP password is empty or could not be decrypted.');
      $mail->Username = $username;
      $mail->Password = $password;
    }

    $encryption = strtolower(trim((string) $smtp['encryption']));
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

    $fromEmail = trim((string) $smtp['from_email']);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
      $fromEmail = $username;
    }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Platform SMTP From Email is invalid.');
    }

    $fromName = trim((string) $smtp['from_name']);
    if ($fromName === '') $fromName = 'FieldPlx';

    $customerName = trim($data['first_name'] . ' ' . $data['last_name']);
    $reasonLabel = fieldplxContactReasonLabel($data['reason']);

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($recipient, 'FieldPlx Platform');
    $mail->addReplyTo($data['email'], $customerName);
    $mail->isHTML(true);
    $mail->Subject = 'New FieldPlx enquiry - ' . $reasonLabel;

    $e = function ($value) {
      return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    $mail->Body = '<div style="font-family:Arial,sans-serif;color:#071c2f;line-height:1.6">'
      . '<h2 style="margin:0 0 16px;color:#071c2f">New FieldPlx Website Enquiry</h2>'
      . '<table cellpadding="7" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:720px">'
      . '<tr><td><strong>Name</strong></td><td>' . $e($customerName) . '</td></tr>'
      . '<tr><td><strong>Business</strong></td><td>' . $e($data['business_name']) . '</td></tr>'
      . '<tr><td><strong>Email</strong></td><td>' . $e($data['email']) . '</td></tr>'
      . '<tr><td><strong>Phone</strong></td><td>' . $e($data['phone']) . '</td></tr>'
      . '<tr><td><strong>Industry</strong></td><td>' . $e($data['industry']) . '</td></tr>'
      . '<tr><td><strong>Employees</strong></td><td>' . $e($data['employees']) . '</td></tr>'
      . '<tr><td><strong>Reason</strong></td><td>' . $e($reasonLabel) . '</td></tr>'
      . '<tr><td valign="top"><strong>Message</strong></td><td>' . nl2br($e($data['message'])) . '</td></tr>'
      . '</table>'
      . '<p style="margin-top:18px;color:#61707b">Reply to this email to respond directly to the customer.</p>'
      . '</div>';

    $mail->AltBody = "New FieldPlx Website Enquiry\n\n"
      . "Name: " . $customerName . "\n"
      . "Business: " . $data['business_name'] . "\n"
      . "Email: " . $data['email'] . "\n"
      . "Phone: " . $data['phone'] . "\n"
      . "Industry: " . $data['industry'] . "\n"
      . "Employees: " . $data['employees'] . "\n"
      . "Reason: " . $reasonLabel . "\n\n"
      . "Message:\n" . $data['message'];

    $mail->send();
    return array('sent' => true, 'message' => 'Platform enquiry email sent successfully.');
  } catch (Throwable $mailException) {
    error_log('FieldPlx contact platform email error: ' . $mailException->getMessage());
    return array('sent' => false, 'message' => $mailException->getMessage());
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fieldplx_contact_submit'])) {
  $csrfToken = fieldplxContactValue('csrf_token');

  if ($csrfToken === '' || !hash_equals($contactCsrf, $csrfToken)) {
    $contactError = 'Your form session expired. Please refresh the page and try again.';
  } else {
    $firstName = fieldplxContactValue('first_name');
    $lastName = fieldplxContactValue('last_name');
    $businessName = fieldplxContactValue('business_name');
    $email = strtolower(fieldplxContactValue('email'));
    $phone = fieldplxContactValue('phone');
    $industry = fieldplxContactValue('industry');
    $employees = fieldplxContactValue('employees');
    $reason = fieldplxContactValue('reason');
    $message = fieldplxContactValue('message');

    $allowedReasons = array(
      'book_demo',
      'start_trial',
      'product_pricing',
      'technical_support',
      'partnership',
      'media',
      'general'
    );

    $allowedEmployees = array('', '1', '2-5', '6-10', '11-25', '26-50', '51-100', '100+');

    if ($firstName === '' || strlen($firstName) > 100) {
      $contactError = 'Please enter your first name.';
    } elseif ($lastName === '' || strlen($lastName) > 100) {
      $contactError = 'Please enter your last name.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
      $contactError = 'Please enter a valid email address.';
    } elseif (!in_array($employees, $allowedEmployees, true)) {
      $contactError = 'Please select a valid team size.';
    } elseif (!in_array($reason, $allowedReasons, true)) {
      $contactError = 'Please select a valid reason for contacting us.';
    } elseif ($message === '' || strlen($message) > 5000) {
      $contactError = 'Please enter your message.';
    } else {
      try {
        /* Ensure the enquiry table has been installed before inserting. */
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'fieldplx_enquiry'");
        if (!$tableCheck || !$tableCheck->fetchColumn()) {
          throw new RuntimeException("Database table 'fieldplx_enquiry' does not exist. Run the supplied fieldplx_enquiry.sql once.");
        }

        $stmt = $pdo->prepare("
          INSERT INTO fieldplx_enquiry (
            first_name,
            last_name,
            business_name,
            email,
            phone,
            industry,
            employees,
            reason,
            message,
            status,
            created_at,
            updated_at
          ) VALUES (
            :first_name,
            :last_name,
            :business_name,
            :email,
            :phone,
            :industry,
            :employees,
            :reason,
            :message,
            'new',
            NOW(),
            NOW()
          )
        ");

        $stmt->execute(array(
          ':first_name' => $firstName,
          ':last_name' => $lastName,
          ':business_name' => $businessName !== '' ? $businessName : null,
          ':email' => $email,
          ':phone' => $phone !== '' ? $phone : null,
          ':industry' => $industry !== '' ? $industry : null,
          ':employees' => $employees !== '' ? $employees : null,
          ':reason' => $reason,
          ':message' => $message
        ));

        /* Save first. Email failure must never lose the enquiry. */
        $emailResult = fieldplxContactSendPlatformCopy($pdo, array(
          'first_name' => $firstName,
          'last_name' => $lastName,
          'business_name' => $businessName,
          'email' => $email,
          'phone' => $phone,
          'industry' => $industry,
          'employees' => $employees,
          'reason' => $reason,
          'message' => $message
        ));

        if (!$emailResult['sent']) {
          error_log('FieldPlx enquiry saved but platform email copy failed: ' . $emailResult['message']);
        }

        $contactSuccess = true;
        $_SESSION['fieldplx_contact_csrf'] = bin2hex(random_bytes(32));
        $contactCsrf = $_SESSION['fieldplx_contact_csrf'];
        $_POST = array();
      } catch (Throwable $e) {
        error_log('FieldPlx contact enquiry save error: ' . $e->getMessage());

        if (strpos($e->getMessage(), "fieldplx_enquiry") !== false) {
          $contactError = 'The enquiry database table is not installed yet. Please run fieldplx_enquiry.sql and try again.';
        } else {
          $contactError = 'We could not save your message right now. Please try again.';
        }
      }
    }
  }
}

include __DIR__ . '/topbar.php';
?>

<style>
  .contact-page {
    background: #fff;
    color: #071c2f;
  }

  .contact-page .site-container {
    max-width: 1460px;
  }

  .contact-hero {
    position: relative;
    overflow: hidden;
    min-height: 500px;
    display: flex;
    align-items: center;
    background: #06192a;
    color: #fff;
  }

  .contact-hero::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 60%;
    background:
      linear-gradient(90deg, rgba(6,25,42,1) 0%, rgba(6,25,42,.96) 18%, rgba(6,25,42,.62) 48%, rgba(6,25,42,.18) 76%, rgba(6,25,42,0) 100%),
      url('site-assets/contact/contact-hero.png') 68% center / cover no-repeat;
  }

  .contact-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
      radial-gradient(circle at 10% 20%, rgba(53,174,25,.18), transparent 25%),
      radial-gradient(circle at 82% 78%, rgba(38,121,176,.12), transparent 26%);
  }

  .contact-hero .site-container {
    position: relative;
    z-index: 2;
  }

  .contact-hero-copy {
    max-width: 710px;
    padding: 72px 0;
  }

  .contact-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
    padding: 7px 12px;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 999px;
    background: rgba(255,255,255,.07);
    color: #fff;
    font-size: .76rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .contact-hero h1 {
    margin: 0 0 18px;
    max-width: 780px;
    font-size: clamp(2.8rem, 5vw, 5rem);
    line-height: 1.02;
    font-weight: 900;
    letter-spacing: -.045em;
    color: #fff;
  }

  .contact-hero h1 span {
    color: #3eb51f;
  }

  .contact-hero p {
    max-width: 680px;
    margin: 0;
    color: #e2ebf1;
    font-size: 1.05rem;
    line-height: 1.72;
  }

  .contact-main {
    padding: 68px 0 76px;
    background: #f7f9fa;
  }

  .contact-layout {
    display: grid;
    grid-template-columns: .78fr 1.22fr;
    gap: 28px;
    align-items: stretch;
  }

  .contact-info-card,
  .contact-form-card {
    border: 1px solid #e2e7ea;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 12px 32px rgba(5,31,50,.055);
  }

  .contact-info-card {
    padding: 30px;
  }

  .contact-info-card .mini,
  .contact-form-card .mini {
    display: block;
    margin-bottom: 8px;
    color: #2f9d17;
    font-size: .75rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .contact-info-card h2,
  .contact-form-card h2 {
    margin: 0 0 12px;
    color: #071c2f;
    font-size: clamp(1.8rem, 3vw, 2.5rem);
    font-weight: 900;
    letter-spacing: -.03em;
  }

  .contact-info-card > p {
    margin: 0 0 26px;
    color: #66747f;
    font-size: .92rem;
    line-height: 1.62;
  }

  .contact-detail {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    padding: 16px 0;
    border-bottom: 1px solid #edf0f2;
  }

  .contact-detail:last-of-type {
    border-bottom: 0;
  }

  .contact-detail-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #eef8eb;
    color: #2f9d17;
    font-size: 1.05rem;
  }

  .contact-detail strong {
    display: block;
    margin-bottom: 3px;
    color: #142634;
    font-size: .85rem;
  }

  .contact-detail span {
    display: block;
    color: #6a7781;
    font-size: .8rem;
    line-height: 1.45;
    word-break: break-word;
  }

  .contact-placeholder-note {
    margin-top: 24px;
    padding: 14px 15px;
    border: 1px solid #eadfb8;
    border-radius: 10px;
    background: #fffaf0;
    color: #715d22;
    font-size: .78rem;
    line-height: 1.5;
  }

  .contact-product-note {
    margin-top: 18px;
    padding: 16px 17px;
    border-radius: 10px;
    background: #071c2f;
    color: #fff;
    font-size: .86rem;
    font-weight: 800;
  }

  .contact-form-card {
    padding: 30px;
  }

  .contact-form-card > p {
    margin: 0 0 24px;
    color: #66747f;
    font-size: .92rem;
    line-height: 1.6;
  }

  .contact-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }

  .contact-field.full {
    grid-column: 1 / -1;
  }

  .contact-field label {
    display: block;
    margin-bottom: 6px;
    color: #263743;
    font-size: .78rem;
    font-weight: 800;
  }

  .contact-field .form-control,
  .contact-field .form-select {
    min-height: 46px;
    border-color: #dce2e6;
    border-radius: 8px;
    font-size: .88rem;
    box-shadow: none;
  }

  .contact-field .form-control:focus,
  .contact-field .form-select:focus {
    border-color: #58ad40;
    box-shadow: 0 0 0 3px rgba(53,174,25,.10);
  }

  .contact-field textarea.form-control {
    min-height: 130px;
    resize: vertical;
  }

  .contact-submit-wrap {
    margin-top: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }

  .contact-submit-note {
    margin: 0;
    max-width: 520px;
    color: #7a858d;
    font-size: .73rem;
    line-height: 1.45;
  }

  .contact-submit-wrap .btn {
    min-width: 180px;
    font-weight: 800;
  }

  .contact-reasons {
    padding: 70px 0 74px;
  }

  .contact-section-head {
    max-width: 820px;
    margin: 0 auto 34px;
    text-align: center;
  }

  .contact-section-head .mini {
    display: block;
    margin-bottom: 8px;
    color: #2f9d17;
    font-size: .76rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
  }

  .contact-section-head h2 {
    margin: 0 0 12px;
    color: #071c2f;
    font-size: clamp(2rem, 3vw, 3rem);
    font-weight: 900;
    letter-spacing: -.03em;
  }

  .contact-section-head p {
    margin: 0;
    color: #66747f;
    font-size: .96rem;
    line-height: 1.65;
  }

  .reason-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
  }

  .reason-card {
    min-height: 170px;
    padding: 22px 20px;
    border: 1px solid #e2e8ec;
    border-radius: 13px;
    background: #fff;
    text-align: center;
    box-shadow: 0 9px 24px rgba(5,31,50,.04);
  }

  .reason-icon {
    width: 46px;
    height: 46px;
    margin: 0 auto 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #eef8eb;
    color: #2f9d17;
    font-size: 1.1rem;
    font-weight: 900;
  }

  .reason-card h3 {
    margin: 0;
    color: #071c2f;
    font-size: .93rem;
    font-weight: 900;
    line-height: 1.35;
  }

  .contact-cta {
    padding: 0 0 76px;
  }

  .contact-cta-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 28px;
    padding: 36px 38px;
    border-radius: 14px;
    background: linear-gradient(105deg, #071c2f, #0b3653);
    color: #fff;
  }

  .contact-cta-box h2 {
    margin: 0 0 8px;
    color: #fff;
    font-size: 1.9rem;
    font-weight: 900;
  }

  .contact-cta-box p {
    margin: 0;
    max-width: 760px;
    color: #d6e0e6;
    font-size: .91rem;
    line-height: 1.58;
  }

  .contact-cta-actions {
    display: flex;
    gap: 10px;
    flex: 0 0 auto;
  }

  @media (max-width: 1199.98px) {
    .contact-layout {
      grid-template-columns: .9fr 1.1fr;
    }
    .reason-grid {
      grid-template-columns: repeat(2, minmax(0,1fr));
    }
  }

  @media (max-width: 991.98px) {
    .contact-hero::before {
      width: 100%;
      background:
        linear-gradient(90deg, rgba(6,25,42,.98) 0%, rgba(6,25,42,.9) 42%, rgba(6,25,42,.58) 68%, rgba(6,25,42,.28) 100%),
        url('site-assets/contact/contact-hero.png') 68% center / cover no-repeat;
    }
    .contact-layout {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 767.98px) {
    .contact-hero {
      min-height: auto;
    }
    .contact-hero-copy {
      padding: 54px 0 48px;
    }
    .contact-hero h1 {
      font-size: 2.55rem;
    }
    .contact-main {
      padding: 52px 0 58px;
    }
    .contact-info-card,
    .contact-form-card {
      padding: 22px 18px;
    }
    .contact-form-grid {
      grid-template-columns: 1fr;
    }
    .contact-field.full {
      grid-column: auto;
    }
    .contact-submit-wrap {
      flex-direction: column;
      align-items: stretch;
    }
    .contact-submit-wrap .btn {
      width: 100%;
    }
    .reason-grid {
      grid-template-columns: 1fr;
    }
    .contact-cta-box {
      flex-direction: column;
      align-items: flex-start;
      padding: 28px 22px;
    }
    .contact-cta-actions {
      width: 100%;
      display: grid;
      grid-template-columns: 1fr;
    }
    .contact-cta-actions .btn {
      width: 100%;
    }
  }


  .contact-form-alert {
    margin-bottom: 18px;
    padding: 12px 14px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: .82rem;
    line-height: 1.55;
  }

  .contact-success-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(3, 19, 33, .62);
    backdrop-filter: blur(4px);
  }

  .contact-success-modal {
    width: min(430px, 100%);
    padding: 30px 26px 24px;
    border-radius: 16px;
    background: #fff;
    text-align: center;
    box-shadow: 0 24px 70px rgba(3,19,33,.24);
  }

  .contact-success-icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #eaf8e6;
    color: #2f9d17;
    font-size: 2rem;
    font-weight: 900;
  }

  .contact-success-modal h3 {
    margin: 0 0 8px;
    color: #071c2f;
    font-size: 1.45rem;
    font-weight: 900;
  }

  .contact-success-modal p {
    margin: 0;
    color: #687680;
    font-size: .9rem;
    line-height: 1.65;
  }

  .contact-success-close {
    min-width: 150px;
    margin-top: 20px;
    padding: 11px 18px;
    border: 0;
    border-radius: 9px;
    background: #38b813;
    color: #fff;
    font-size: .86rem;
    font-weight: 800;
    cursor: pointer;
  }

  .contact-success-close:hover {
    background: #2f9d17;
  }

</style>

<main class="contact-page">

  <section class="contact-hero">
    <div class="container-fluid site-container">
      <div class="contact-hero-copy">
        <div class="contact-kicker">Contact FieldPlx</div>
        <h1>Let's Talk About <span>Your Business</span></h1>
        <p>Have questions about FieldPlx, need product assistance, or want to schedule a demonstration? Our team is ready to help.</p>
      </div>
    </div>
  </section>

  <section class="contact-main">
    <div class="container-fluid site-container">
      <div class="contact-layout">

        <aside class="contact-info-card">
          <span class="mini">Contact Details</span>
          <h2>We're Here to Help</h2>
          <p>Reach out to the FieldPlx team for product questions, demos, support, partnerships, or general enquiries.</p>

          <div class="contact-detail">
            <div class="contact-detail-icon">✉</div>
            <div>
              <strong>Email</strong>
              <span>support@fieldplx.com</span>
            </div>
          </div>

          <div class="contact-detail">
            <div class="contact-detail-icon">↗</div>
            <div>
              <strong>Sales</strong>
              <span>sales@fieldplx.com</span>
            </div>
          </div>

          <div class="contact-detail">
            <div class="contact-detail-icon">☎</div>
            <div>
              <strong>Phone</strong>
              <span>+91 7406 209000</span>
            </div>
          </div>

          <div class="contact-detail">
            <div class="contact-detail-icon">◷</div>
            <div>
              <strong>Business Hours</strong>
              <span>[Days, hours, and time zone]</span>
            </div>
          </div>

          <div class="contact-detail">
            <div class="contact-detail-icon">●</div>
            <div>
              <strong>Address</strong>
              <span>Hyderabad, India</span>
            </div>
          </div>

          <div class="contact-product-note">FieldPlx - a product of CorePLX</div>
        </aside>

        <section class="contact-form-card">
          <span class="mini">Send Us a Message</span>
          <h2>Tell Us How We Can Help</h2>
          <p>Complete the form below and the FieldPlx team can follow up about your request.</p>

          <form id="fieldplxContactForm" method="post" action="contact.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($contactCsrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="fieldplx_contact_submit" value="1">

            <?php if ($contactError !== ''): ?>
              <div class="contact-form-alert" role="alert"><?= htmlspecialchars($contactError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <div class="contact-form-grid">

              <div class="contact-field">
                <label for="first_name">First Name *</label>
                <input class="form-control" type="text" id="first_name" name="first_name" maxlength="100" required value="<?= htmlspecialchars(fieldplxContactValue('first_name'), ENT_QUOTES, 'UTF-8'); ?>">
              </div>

              <div class="contact-field">
                <label for="last_name">Last Name *</label>
                <input class="form-control" type="text" id="last_name" name="last_name" maxlength="100" required value="<?= htmlspecialchars(fieldplxContactValue('last_name'), ENT_QUOTES, 'UTF-8'); ?>">
              </div>

              <div class="contact-field">
                <label for="business_name">Business Name</label>
                <input class="form-control" type="text" id="business_name" name="business_name" maxlength="190" value="<?= htmlspecialchars(fieldplxContactValue('business_name'), ENT_QUOTES, 'UTF-8'); ?>">
              </div>

              <div class="contact-field">
                <label for="email">Email Address *</label>
                <input class="form-control" type="email" id="email" name="email" maxlength="190" required value="<?= htmlspecialchars(fieldplxContactValue('email'), ENT_QUOTES, 'UTF-8'); ?>">
              </div>

              <div class="contact-field">
                <label for="phone">Phone Number</label>
                <input class="form-control" type="tel" id="phone" name="phone" maxlength="50" value="<?= htmlspecialchars(fieldplxContactValue('phone'), ENT_QUOTES, 'UTF-8'); ?>">
              </div>

              <div class="contact-field">
                <label for="industry">Industry</label>
                <input class="form-control" type="text" id="industry" name="industry" maxlength="120"
                       placeholder="HVAC, Plumbing, Electrical..." value="<?= htmlspecialchars(fieldplxContactValue('industry'), ENT_QUOTES, 'UTF-8'); ?>">
              </div>

              <div class="contact-field">
                <label for="employees">Number of Employees</label>
                <select class="form-select" id="employees" name="employees">
                  <option value="">Select team size</option>
                  <option value="1" <?= fieldplxContactValue('employees') === '1' ? 'selected' : ''; ?>>1</option>
                  <option value="2-5" <?= fieldplxContactValue('employees') === '2-5' ? 'selected' : ''; ?>>2-5</option>
                  <option value="6-10" <?= fieldplxContactValue('employees') === '6-10' ? 'selected' : ''; ?>>6-10</option>
                  <option value="11-25" <?= fieldplxContactValue('employees') === '11-25' ? 'selected' : ''; ?>>11-25</option>
                  <option value="26-50" <?= fieldplxContactValue('employees') === '26-50' ? 'selected' : ''; ?>>26-50</option>
                  <option value="51-100" <?= fieldplxContactValue('employees') === '51-100' ? 'selected' : ''; ?>>51-100</option>
                  <option value="100+" <?= fieldplxContactValue('employees') === '100+' ? 'selected' : ''; ?>>100+</option>
                </select>
              </div>

              <div class="contact-field">
                <label for="reason">Reason for Contacting Us *</label>
                <select class="form-select" id="reason" name="reason" required>
                  <option value="">Select a reason</option>
                  <option value="book_demo" <?= fieldplxContactValue('reason') === 'book_demo' ? 'selected' : ''; ?>>Book a demonstration</option>
                  <option value="start_trial" <?= fieldplxContactValue('reason') === 'start_trial' ? 'selected' : ''; ?>>Start a free trial</option>
                  <option value="product_pricing" <?= fieldplxContactValue('reason') === 'product_pricing' ? 'selected' : ''; ?>>Product or pricing question</option>
                  <option value="technical_support" <?= fieldplxContactValue('reason') === 'technical_support' ? 'selected' : ''; ?>>Technical support</option>
                  <option value="partnership" <?= fieldplxContactValue('reason') === 'partnership' ? 'selected' : ''; ?>>Partnership opportunity</option>
                  <option value="media" <?= fieldplxContactValue('reason') === 'media' ? 'selected' : ''; ?>>Media inquiry</option>
                  <option value="general" <?= fieldplxContactValue('reason') === 'general' ? 'selected' : ''; ?>>General question</option>
                </select>
              </div>

              <div class="contact-field full">
                <label for="message">Message *</label>
                <textarea class="form-control" id="message" name="message" required
                          placeholder="Tell us how we can help..." maxlength="5000"><?= htmlspecialchars(fieldplxContactValue('message'), ENT_QUOTES, 'UTF-8'); ?></textarea>
              </div>

            </div>

            <div class="contact-submit-wrap">
              <p class="contact-submit-note">
                By submitting this form, you are requesting that the FieldPlx team contact you regarding your enquiry.
              </p>
              <button type="submit" class="btn btn-brand btn-lg">Send Message</button>
            </div>
          </form>
        </section>

      </div>
    </div>
  </section>

  <section class="contact-reasons">
    <div class="container-fluid site-container">

      <div class="contact-section-head">
        <span class="mini">How Can We Help?</span>
        <h2>Choose the Right Conversation</h2>
        <p>Whether you're evaluating FieldPlx, need help with the product, or want to discuss an opportunity, our team is ready to connect.</p>
      </div>

      <div class="reason-grid">
        <article class="reason-card">
          <div class="reason-icon">▶</div>
          <h3>Book a Demonstration</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">✓</div>
          <h3>Start a Free Trial</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">$</div>
          <h3>Product or Pricing Question</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">⚙</div>
          <h3>Technical Support</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">◇</div>
          <h3>Partnership Opportunity</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">◎</div>
          <h3>Media Inquiry</h3>
        </article>

        <article class="reason-card">
          <div class="reason-icon">?</div>
          <h3>General Question</h3>
        </article>
      </div>

    </div>
  </section>

  <section class="contact-cta">
    <div class="container-fluid site-container">
      <div class="contact-cta-box">
        <div>
          <h2>Want to See FieldPlx in Action?</h2>
          <p>Book a personalized demonstration or start your free trial to explore how FieldPlx can support your field-service operation.</p>
        </div>

        <div class="contact-cta-actions">
          <a href="index.php?modal=demo"
             class="btn btn-brand btn-lg js-fieldplx-modal-trigger"
             data-open-modal="demoModal"
             data-modal-name="demo">Book a Demo</a>

          <a href="index.php?modal=trial"
             class="btn btn-outline-light btn-lg js-fieldplx-modal-trigger"
             data-open-modal="trialModal"
             data-modal-name="trial">Start Free Trial</a>
        </div>
      </div>
    </div>
  </section>

</main>

<?php if ($contactSuccess): ?>
<div class="contact-success-overlay" id="contactSuccessOverlay" role="dialog" aria-modal="true" aria-labelledby="contactSuccessTitle">
  <div class="contact-success-modal">
    <div class="contact-success-icon">✓</div>
    <h3 id="contactSuccessTitle">Message Sent Successfully</h3>
    <p>Thank you for contacting FieldPlx. We have received your enquiry and we will contact you soon.</p>
    <button type="button" class="contact-success-close" id="contactSuccessClose">Close</button>
  </div>
</div>
<?php endif; ?>


<script>
(function () {
  var form = document.getElementById('fieldplxContactForm');
  var submitButton = form ? form.querySelector('button[type="submit"]') : null;

  if (form && submitButton) {
    form.addEventListener('submit', function () {
      if (!form.checkValidity()) return;
      submitButton.disabled = true;
      submitButton.textContent = 'Sending...';
    });
  }

  var successOverlay = document.getElementById('contactSuccessOverlay');
  var closeButton = document.getElementById('contactSuccessClose');

  function closeSuccess() {
    if (successOverlay) successOverlay.remove();
  }

  if (closeButton) {
    closeButton.addEventListener('click', closeSuccess);
  }

  if (successOverlay) {
    successOverlay.addEventListener('click', function (event) {
      if (event.target === successOverlay) closeSuccess();
    });
  }
})();
</script>


<?php include __DIR__ . '/footer.php'; ?>
