<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/website-mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function trialJson($success, $message, $statusCode, $extra)
{
    http_response_code((int) $statusCode);
    $out = array(
        'success' => (bool) $success,
        'message' => (string) $message
    );
    if (is_array($extra)) {
        foreach ($extra as $key => $value) {
            $out[$key] = $value;
        }
    }
    echo json_encode($out);
    exit;
}

function trialTenantCode($conn, $businessName)
{
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $businessName));
    $prefix = substr($prefix, 0, 3);
    if (strlen($prefix) < 2) {
        $prefix = 'TNT';
    }

    for ($i = 0; $i < 20; $i++) {
        $code = $prefix . '-' . date('ymd') . '-' . random_int(1000, 9999);
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

    return 'TNT-' . time() . '-' . random_int(100, 999);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    trialJson(false, 'Only POST requests are allowed.', 405, array());
}

$csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
if (empty($_SESSION['website_csrf']) || !hash_equals($_SESSION['website_csrf'], $csrf)) {
    trialJson(false, 'Session expired. Please refresh and try again.', 419, array());
}

$businessName = isset($_POST['business_name']) ? trim((string) $_POST['business_name']) : '';
$businessType = isset($_POST['business_type']) ? trim((string) $_POST['business_type']) : '';
$fullName = isset($_POST['full_name']) ? trim((string) $_POST['full_name']) : '';
$email = isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '';
$phone = isset($_POST['phone']) ? trim((string) $_POST['phone']) : '';
$countryId = isset($_POST['country_id']) ? (int) $_POST['country_id'] : 0;

if ($businessName === '' || $fullName === '' || $email === '' || $countryId <= 0) {
    trialJson(false, 'Please complete all required fields.', 422, array());
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    trialJson(false, 'Please enter a valid business email.', 422, array());
}

// Prevent accidental duplicate public sign-ups with the same email.
$dup = $conn->prepare(
    "SELECT u.id
     FROM users u
     WHERE u.email = ?
       AND u.deleted_at IS NULL
     LIMIT 1"
);
$dup->bind_param('s', $email);
$dup->execute();
$dup->store_result();
if ($dup->num_rows > 0) {
    $dup->close();
    trialJson(false, 'An account already exists for this email. Please use the login page or contact support.', 409, array());
}
$dup->close();

// Load the active 60-day Free Trial plan.
$planStmt = $conn->prepare(
    "SELECT id, price, trial_days, duration_days
     FROM plans
     WHERE code = 'trial'
       AND status = 'active'
       AND deleted_at IS NULL
     LIMIT 1"
);
$planStmt->execute();
$planStmt->bind_result($planId, $planPrice, $trialDays, $durationDays);
if (!$planStmt->fetch()) {
    $planStmt->close();
    trialJson(false, 'The Free Trial plan is not available. Please contact support.', 500, array());
}
$planStmt->close();

$trialDays = (int) $trialDays;
if ($trialDays <= 0) {
    $trialDays = 60;
}

// Resolve country defaults and currency id.
$countryStmt = $conn->prepare(
    "SELECT c.default_currency_code, c.default_timezone, c.date_format, cu.id
     FROM countries c
     INNER JOIN currencies cu
       ON cu.currency_code = c.default_currency_code
      AND cu.is_active = 1
     WHERE c.id = ?
       AND c.is_active = 1
     LIMIT 1"
);
$countryStmt->bind_param('i', $countryId);
$countryStmt->execute();
$countryStmt->bind_result($currencyCode, $timezone, $dateFormat, $currencyId);
if (!$countryStmt->fetch()) {
    $countryStmt->close();
    trialJson(false, 'Please select a valid country.', 422, array());
}
$countryStmt->close();

$tenantCode = trialTenantCode($conn, $businessName);
$startDate = date('Y-m-d');
$trialEndDate = date('Y-m-d', strtotime('+' . $trialDays . ' days'));
$expiryDate = $trialEndDate;
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+48 hours'));
$temporaryPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

$nameParts = preg_split('/\s+/', $fullName, 2);
$firstName = isset($nameParts[0]) ? $nameParts[0] : $fullName;
$lastName = isset($nameParts[1]) ? $nameParts[1] : '';

$conn->begin_transaction();

try {
$tenantStmt = $conn->prepare(
    "INSERT INTO tenants
    (tenant_code, legal_name, display_name, business_type, email, phone, country_id, currency_id, timezone, date_format, status, subscription_plan, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'trial', 'trial', NOW())"
);
$tenantStmt->bind_param(
    'ssssssiiss',
    $tenantCode,
    $businessName,
    $businessName,
    $businessType,
    $email,
    $phone,
    $countryId,
    $currencyId,
    $timezone,
    $dateFormat
);

if (!$tenantStmt->execute()) {
    throw new Exception('Unable to create tenant: ' . $tenantStmt->error);
}
$tenantId = (int) $conn->insert_id;
$tenantStmt->close();

$userStmt = $conn->prepare(
    "INSERT INTO users
    (tenant_id, first_name, last_name, email, phone, password_hash, is_bookable, is_field_worker, is_tenant_admin, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, 0, 0, 1, 'invited', NOW())"
);
$userStmt->bind_param(
    'isssss',
    $tenantId,
    $firstName,
    $lastName,
    $email,
    $phone,
    $temporaryPasswordHash
);
if (!$userStmt->execute()) {
    throw new Exception('Unable to create admin user: ' . $userStmt->error);
}
$userId = (int) $conn->insert_id;
$userStmt->close();

$subscriptionStmt = $conn->prepare(
    "INSERT INTO subscriptions
    (tenant_id, plan_id, currency_id, amount, start_date, expiry_date, trial_end_date, auto_renew, status, created_at)
    VALUES (?, ?, ?, 0.00, ?, ?, ?, 0, 'trial', NOW())"
);
$subscriptionStmt->bind_param(
    'iiisss',
    $tenantId,
    $planId,
    $currencyId,
    $startDate,
    $expiryDate,
    $trialEndDate
);
if (!$subscriptionStmt->execute()) {
    throw new Exception('Unable to create trial subscription: ' . $subscriptionStmt->error);
}
$subscriptionId = (int) $conn->insert_id;
$subscriptionStmt->close();

$historyStmt = $conn->prepare(
    "INSERT INTO subscription_history
    (tenant_id, subscription_id, old_plan_id, new_plan_id, action, effective_at, new_values, created_at)
    VALUES (?, ?, NULL, ?, 'trial_started', NOW(), ?, NOW())"
);
$newValues = json_encode(array(
    'plan_code' => 'trial',
    'trial_days' => $trialDays,
    'start_date' => $startDate,
    'trial_end_date' => $trialEndDate
));
$historyStmt->bind_param('iiis', $tenantId, $subscriptionId, $planId, $newValues);
if (!$historyStmt->execute()) {
    throw new Exception('Unable to create subscription history: ' . $historyStmt->error);
}
$historyStmt->close();

$tokenStmt = $conn->prepare(
    "INSERT INTO tenant_activation_tokens
    (tenant_id, user_id, token_hash, expires_at, created_at)
    VALUES (?, ?, ?, ?, NOW())"
);
$tokenStmt->bind_param('iiss', $tenantId, $userId, $tokenHash, $tokenExpiresAt);
if (!$tokenStmt->execute()) {
    throw new Exception('Unable to create activation token: ' . $tokenStmt->error);
}
$tokenStmt->close();

$conn->commit();

$activationUrl = websiteBaseUrl() . '/activate-account.php?token=' . urlencode($token);
$safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
$safeBusiness = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');
$safeUrl = htmlspecialchars($activationUrl, ENT_QUOTES, 'UTF-8');

$mailHtml = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1f2937;line-height:1.6">'
    . '<div style="max-width:620px;margin:0 auto;padding:24px">'
    . '<h2 style="margin:0 0 12px;color:#111827">Welcome to FieldPlx</h2>'
    . '<p>Hi ' . $safeName . ',</p>'
    . '<p>Your <strong>60-day Free Trial</strong> workspace for <strong>' . $safeBusiness . '</strong> has been created.</p>'
    . '<p>Activate your account and create your password using the button below.</p>'
    . '<p style="margin:24px 0"><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 20px;background:#6d28d9;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">Activate Account</a></p>'
    . '<p>This activation link expires in 48 hours.</p>'
    . '<p>Trial end date: <strong>' . htmlspecialchars($trialEndDate, ENT_QUOTES, 'UTF-8') . '</strong></p>'
    . '<p>If the button does not work, copy this link into your browser:<br>' . $safeUrl . '</p>'
    . '<p>FieldPlx Support<br>support@coreplx.com</p>'
    . '</div></body></html>';

$mailSent = websiteSendHtmlMail(
    $email,
    'Activate your FieldPlx 60-Day Free Trial',
    $mailHtml
);

trialJson(
    true,
    $mailSent
        ? 'Your 60-day Free Trial is ready. Check your email to activate your account and create a password.'
        : 'Your 60-day Free Trial was created, but the activation email could not be sent. Please contact support.',
    200,
    array('mail_sent' => $mailSent)
);

} catch (Exception $e) {
    $conn->rollback();
    error_log('Website free trial onboarding failed: ' . $e->getMessage());
    trialJson(false, 'Unable to create your trial account right now. Please try again or contact support.', 500, array());
}
