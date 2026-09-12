<?php
/* FieldPlx Dynamic Topbar API - Version 1.0.0 - 2026-08-28 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function tbOut($success, $message, array $extra = array(), $status = 200)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message,
        'api_version' => '1.0.0'
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tbDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('PDO database connection is not available.');
}

function tbTenantId()
{
    foreach (array('tenant_id', 'business_id') as $key) {
        if (!empty($_SESSION[$key])) return (int)$_SESSION[$key];
    }
    return 0;
}

function tbUserId()
{
    foreach (array('tenant_user_id', 'user_id', 'id', 'business_user_id') as $key) {
        if (!empty($_SESSION[$key])) return (int)$_SESSION[$key];
    }
    return 0;
}

function tbCsrf()
{
    if (empty($_SESSION['topbar_csrf_token'])) {
        $_SESSION['topbar_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['topbar_csrf_token'];
}

function tbRelativeTime($dateTime)
{
    if (!$dateTime) return '';
    $ts = strtotime($dateTime);
    if (!$ts) return '';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) {
        $m = (int)floor($diff / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = (int)floor($diff / 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $d = (int)floor($diff / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }
    return date('d M Y, h:i A', $ts);
}

function tbNotificationIcon($eventKey, $relatedType)
{
    $eventKey = strtolower((string)$eventKey);
    $relatedType = strtolower((string)$relatedType);
    if (strpos($eventKey, 'payment') !== false || $relatedType === 'payment') return 'bi bi-credit-card';
    if (strpos($eventKey, 'invoice') !== false || $relatedType === 'invoice') return 'bi bi-receipt';
    if (strpos($eventKey, 'quote') !== false || $relatedType === 'quote') return 'bi bi-file-earmark-text';
    if (strpos($eventKey, 'visit') !== false || $relatedType === 'visit') return 'bi bi-calendar-check';
    if (strpos($eventKey, 'request') !== false || $relatedType === 'service_request') return 'bi bi-inbox';
    if (strpos($eventKey, 'review') !== false || $relatedType === 'review') return 'bi bi-star';
    if (strpos($eventKey, 'job') !== false || $relatedType === 'job') return 'bi bi-briefcase';
    return 'bi bi-bell';
}

function tbNotificationUrl($relatedType, $relatedId)
{
    $id = (int)$relatedId;
    if ($id <= 0) return '#';
    switch (strtolower((string)$relatedType)) {
        case 'job': return 'job-view.php?id=' . $id;
        case 'quote': return 'view-quotation.php?quote_id=' . $id;
        case 'service_request': return 'request-view.php?id=' . $id;
        case 'invoice': return 'invoice-view.php?id=' . $id;
        case 'payment': return 'payments.php';
        case 'visit': return 'visit-view.php?id=' . $id;
        case 'review': return 'reviews.php';
        default: return '#';
    }
}

try {
    $pdo = tbDb();
    $tenantId = tbTenantId();
    $userId = tbUserId();
    if ($tenantId <= 0 || $userId <= 0) {
        tbOut(false, 'Your login session is not valid.', array(), 401);
    }

    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'load';

    if ($action !== 'load') {
        $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
        $sessionCsrf = tbCsrf();
        if ($csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
            tbOut(false, 'Invalid request token. Refresh the page and try again.', array(), 419);
        }
    }

    if ($action === 'mark_read') {
        $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
        if ($notificationId <= 0) tbOut(false, 'Invalid notification.', array(), 422);

        $st = $pdo->prepare("UPDATE notification_queue
                            SET status = 'read'
                            WHERE id = :id
                              AND tenant_id = :tenant_id
                              AND channel = 'in_app'
                              AND recipient_type = 'user'
                              AND recipient_id = :user_id
                              AND status <> 'read'");
        $st->execute(array(':id'=>$notificationId, ':tenant_id'=>$tenantId, ':user_id'=>$userId));
        tbOut(true, 'Notification marked as read.');
    }

    if ($action === 'mark_all_read') {
        $st = $pdo->prepare("UPDATE notification_queue
                            SET status = 'read'
                            WHERE tenant_id = :tenant_id
                              AND channel = 'in_app'
                              AND recipient_type = 'user'
                              AND recipient_id = :user_id
                              AND status <> 'read'");
        $st->execute(array(':tenant_id'=>$tenantId, ':user_id'=>$userId));
        tbOut(true, 'All notifications marked as read.', array('updated' => $st->rowCount()));
    }

    if ($action !== 'load') {
        tbOut(false, 'Unknown action.', array(), 400);
    }

    $profileStmt = $pdo->prepare("SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            u.avatar_path,
            u.job_title,
            u.employee_code,
            u.is_tenant_admin,
            u.branch_id,
            u.department_id,
            u.role_id,
            COALESCE(r.name, '') AS role_name,
            COALESCE(d.name, '') AS department_name,
            COALESCE(b.name, '') AS branch_name,
            COALESCE(t.display_name, t.legal_name, '') AS tenant_name
        FROM users u
        INNER JOIN tenants t ON t.id = u.tenant_id
        LEFT JOIN roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id
        LEFT JOIN departments d ON d.id = u.department_id AND d.tenant_id = u.tenant_id
        LEFT JOIN branches b ON b.id = u.branch_id AND b.tenant_id = u.tenant_id
        WHERE u.id = :user_id
          AND u.tenant_id = :tenant_id
          AND u.deleted_at IS NULL
        LIMIT 1");
    $profileStmt->execute(array(':user_id'=>$userId, ':tenant_id'=>$tenantId));
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) tbOut(false, 'Logged-in user profile was not found.', array(), 404);

    $fullName = trim((string)$profile['first_name'] . ' ' . (string)$profile['last_name']);
    if ($fullName === '') $fullName = (string)$profile['email'];
    $role = trim((string)$profile['role_name']);
    if ($role === '') {
        $role = ((int)$profile['is_tenant_admin'] === 1) ? 'Administrator' : (trim((string)$profile['job_title']) !== '' ? trim((string)$profile['job_title']) : 'User');
    }
    $initials = '';
    $parts = preg_split('/\s+/', trim($fullName));
    if (!empty($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1 && !empty($parts[count($parts)-1])) $initials .= strtoupper(substr($parts[count($parts)-1], 0, 1));
    if ($initials === '') $initials = 'U';

    $countStmt = $pdo->prepare("SELECT COUNT(*)
        FROM notification_queue
        WHERE tenant_id = :tenant_id
          AND channel = 'in_app'
          AND recipient_type = 'user'
          AND recipient_id = :user_id
          AND status <> 'read'");
    $countStmt->execute(array(':tenant_id'=>$tenantId, ':user_id'=>$userId));
    $unreadCount = (int)$countStmt->fetchColumn();

    $listStmt = $pdo->prepare("SELECT
            nq.id,
            nq.event_id,
            nq.related_type,
            nq.related_id,
            nq.subject,
            nq.body,
            nq.status,
            nq.created_at,
            ne.event_key,
            ne.event_name
        FROM notification_queue nq
        LEFT JOIN notification_events ne ON ne.id = nq.event_id
        WHERE nq.tenant_id = :tenant_id
          AND nq.channel = 'in_app'
          AND nq.recipient_type = 'user'
          AND nq.recipient_id = :user_id
          AND nq.status <> 'suppressed'
        ORDER BY (nq.status = 'read') ASC, nq.created_at DESC, nq.id DESC
        LIMIT 10");
    $listStmt->execute(array(':tenant_id'=>$tenantId, ':user_id'=>$userId));
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = array();
    foreach ($rows as $row) {
        $title = trim((string)$row['subject']);
        if ($title === '') $title = trim((string)$row['event_name']);
        if ($title === '') $title = 'Notification';
        $notifications[] = array(
            'id' => (int)$row['id'],
            'title' => $title,
            'message' => trim((string)$row['body']),
            'status' => (string)$row['status'],
            'is_unread' => ((string)$row['status'] !== 'read'),
            'icon' => tbNotificationIcon($row['event_key'], $row['related_type']),
            'url' => tbNotificationUrl($row['related_type'], $row['related_id']),
            'time' => tbRelativeTime($row['created_at']),
            'created_at' => (string)$row['created_at']
        );
    }

    tbOut(true, 'Topbar loaded successfully.', array(
        'csrf_token' => tbCsrf(),
        'unread_count' => $unreadCount,
        'notifications' => $notifications,
        'profile' => array(
            'id' => (int)$profile['id'],
            'name' => $fullName,
            'email' => (string)$profile['email'],
            'phone' => (string)$profile['phone'],
            'role' => $role,
            'job_title' => (string)$profile['job_title'],
            'employee_code' => (string)$profile['employee_code'],
            'avatar_path' => (string)$profile['avatar_path'],
            'initials' => $initials,
            'tenant_name' => (string)$profile['tenant_name'],
            'branch_name' => (string)$profile['branch_name'],
            'department_name' => (string)$profile['department_name']
        )
    ));
} catch (Throwable $e) {
    error_log('FieldPlx topbar API: ' . $e->getMessage());
    tbOut(false, $e->getMessage(), array(), 500);
}
