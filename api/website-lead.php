<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function leadJson($success, $message, $statusCode)
{
    http_response_code((int) $statusCode);
    echo json_encode(array(
        'success' => (bool) $success,
        'message' => (string) $message
    ));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    leadJson(false, 'Only POST requests are allowed.', 405);
}

$csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
if (empty($_SESSION['website_csrf']) || !hash_equals($_SESSION['website_csrf'], $csrf)) {
    leadJson(false, 'Session expired. Please refresh and try again.', 419);
}

$type = isset($_POST['lead_type']) ? trim((string) $_POST['lead_type']) : 'contact';
if (!in_array($type, array('contact', 'demo'), true)) {
    $type = 'contact';
}

$fullName = isset($_POST['full_name']) ? trim((string) $_POST['full_name']) : '';
$email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
$phone = isset($_POST['phone']) ? trim((string) $_POST['phone']) : '';
$businessName = isset($_POST['business_name']) ? trim((string) $_POST['business_name']) : '';
$message = isset($_POST['message']) ? trim((string) $_POST['message']) : '';
$preferredDate = isset($_POST['preferred_date']) ? trim((string) $_POST['preferred_date']) : '';
$preferredTime = isset($_POST['preferred_time']) ? trim((string) $_POST['preferred_time']) : '';

if ($fullName === '') {
    leadJson(false, 'Please enter your name.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    leadJson(false, 'Please enter a valid email address.', 422);
}
if ($type === 'demo' && $businessName === '') {
    leadJson(false, 'Please enter your business name.', 422);
}

if ($preferredDate !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $preferredDate);
    if (!$d || $d->format('Y-m-d') !== $preferredDate) {
        leadJson(false, 'Please select a valid preferred date.', 422);
    }
} else {
    $preferredDate = null;
}

$ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 80) : null;
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : null;

$stmt = $conn->prepare(
    "INSERT INTO website_leads
    (lead_type, full_name, email, phone, business_name, message, preferred_date, preferred_time, status, source, ip_address, user_agent, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new', 'website', ?, ?, NOW())"
);

if (!$stmt) {
    leadJson(false, 'Unable to save your request right now.', 500);
}

$stmt->bind_param(
    'ssssssssss',
    $type,
    $fullName,
    $email,
    $phone,
    $businessName,
    $message,
    $preferredDate,
    $preferredTime,
    $ip,
    $userAgent
);

if (!$stmt->execute()) {
    error_log('Website lead insert failed: ' . $stmt->error);
    $stmt->close();
    leadJson(false, 'Unable to save your request right now.', 500);
}

$stmt->close();

leadJson(
    true,
    $type === 'demo'
        ? 'Your demo request has been received. Our team will contact you shortly.'
        : 'Thanks for contacting us. Our team will get back to you shortly.',
    200
);
