<?php
/* FieldPlx Notifications Page - Version 1.1.0 - UI Shell Fix */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Notifications';
$activePage = 'notifications';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function fpNotifyDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }
    if (isset($db) && $db instanceof PDO) {
        return $db;
    }
    throw new RuntimeException('PDO database connection is not available.');
}

function fpNotifyTenantId()
{
    foreach (array('tenant_id', 'business_id') as $key) {
        if (!empty($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }
    return 0;
}

function fpNotifyUserId()
{
    foreach (array('tenant_user_id', 'user_id', 'id', 'business_user_id') as $key) {
        if (!empty($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }
    return 0;
}

function fpNotifyOut($success, $message, array $extra = array(), $status = 200)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code((int) $status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array(
        'success' => (bool) $success,
        'message' => (string) $message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fpNotifyTargetUrl($relatedType, $relatedId)
{
    $relatedType = strtolower(trim((string) $relatedType));
    $relatedId = (int) $relatedId;

    if ($relatedId <= 0) {
        return '';
    }

    $map = array(
        'job' => 'jobs.php?job_id=',
        'jobs' => 'jobs.php?job_id=',
        'quote' => 'view-quotation.php?quote_id=',
        'quotation' => 'view-quotation.php?quote_id=',
        'invoice' => 'invoices.php?invoice_id=',
        'payment' => 'payments.php?payment_id=',
        'service_request' => 'requests.php?request_id=',
        'request' => 'requests.php?request_id=',
        'visit' => 'visits.php?visit_id=',
        'review' => 'reviews.php?review_id='
    );

    return isset($map[$relatedType]) ? $map[$relatedType] . $relatedId : '';
}

function fpNotifyIcon($relatedType, $subject)
{
    $haystack = strtolower((string) $relatedType . ' ' . (string) $subject);

    if (strpos($haystack, 'payment') !== false) return 'bi-credit-card';
    if (strpos($haystack, 'invoice') !== false) return 'bi-receipt';
    if (strpos($haystack, 'quote') !== false || strpos($haystack, 'quotation') !== false) return 'bi-file-earmark-text';
    if (strpos($haystack, 'visit') !== false || strpos($haystack, 'calendar') !== false) return 'bi-calendar-check';
    if (strpos($haystack, 'request') !== false || strpos($haystack, 'enquiry') !== false) return 'bi-inbox';
    if (strpos($haystack, 'review') !== false) return 'bi-star';
    if (strpos($haystack, 'job') !== false) return 'bi-briefcase';

    return 'bi-bell';
}

function fpNotifyTimeAgo($dateTime)
{
    $ts = strtotime((string) $dateTime);
    if (!$ts) return '';

    $diff = time() - $ts;
    if ($diff < 0) $diff = 0;

    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) === 1 ? '' : 's') . ' ago';

    return date('d M Y, h:i A', $ts);
}

$pdo = fpNotifyDb();
$tenantId = fpNotifyTenantId();
$userId = fpNotifyUserId();

if ($tenantId <= 0 || $userId <= 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fpNotifyOut(false, 'Your login session is not valid.', array(), 401);
    }
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['notifications_csrf_token'])) {
    $_SESSION['notifications_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['notifications_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        fpNotifyOut(false, 'Invalid request token. Refresh the page and try again.', array(), 419);
    }

    $action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';

    if ($action === 'mark_read') {
        $notificationId = isset($_POST['notification_id']) ? (int) $_POST['notification_id'] : 0;
        if ($notificationId <= 0) {
            fpNotifyOut(false, 'Invalid notification.', array(), 422);
        }

        $st = $pdo->prepare(
            "UPDATE notification_queue
             SET status = 'read'
             WHERE id = :id
               AND tenant_id = :tenant_id
               AND recipient_type = 'user'
               AND recipient_id = :user_id
               AND channel = 'in_app'
               AND status <> 'read'"
        );
        $st->execute(array(
            ':id' => $notificationId,
            ':tenant_id' => $tenantId,
            ':user_id' => $userId
        ));

        $urlStmt = $pdo->prepare(
            "SELECT related_type, related_id
             FROM notification_queue
             WHERE id = :id
               AND tenant_id = :tenant_id
               AND recipient_type = 'user'
               AND recipient_id = :user_id
               AND channel = 'in_app'
             LIMIT 1"
        );
        $urlStmt->execute(array(
            ':id' => $notificationId,
            ':tenant_id' => $tenantId,
            ':user_id' => $userId
        ));
        $row = $urlStmt->fetch(PDO::FETCH_ASSOC);

        $unreadStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM notification_queue
             WHERE tenant_id = :tenant_id
               AND recipient_type = 'user'
               AND recipient_id = :user_id
               AND channel = 'in_app'
               AND status <> 'read'"
        );
        $unreadStmt->execute(array(':tenant_id' => $tenantId, ':user_id' => $userId));

        fpNotifyOut(true, 'Notification marked as read.', array(
            'unread_count' => (int) $unreadStmt->fetchColumn(),
            'redirect_url' => $row ? fpNotifyTargetUrl($row['related_type'], $row['related_id']) : ''
        ));
    }

    if ($action === 'mark_all_read') {
        $st = $pdo->prepare(
            "UPDATE notification_queue
             SET status = 'read'
             WHERE tenant_id = :tenant_id
               AND recipient_type = 'user'
               AND recipient_id = :user_id
               AND channel = 'in_app'
               AND status <> 'read'"
        );
        $st->execute(array(':tenant_id' => $tenantId, ':user_id' => $userId));

        fpNotifyOut(true, 'All notifications marked as read.', array('unread_count' => 0));
    }

    fpNotifyOut(false, 'Unknown notification action.', array(), 400);
}

$filter = isset($_GET['filter']) ? trim((string) $_GET['filter']) : 'all';
if (!in_array($filter, array('all', 'unread', 'read'), true)) {
    $filter = 'all';
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 20;
$where = array(
    'tenant_id = :tenant_id',
    "recipient_type = 'user'",
    'recipient_id = :user_id',
    "channel = 'in_app'"
);
$params = array(':tenant_id' => $tenantId, ':user_id' => $userId);

if ($filter === 'unread') {
    $where[] = "status <> 'read'";
} elseif ($filter === 'read') {
    $where[] = "status = 'read'";
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notification_queue WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT id, event_id, related_type, related_id, subject, body, status, created_at, sent_at
     FROM notification_queue
     WHERE {$whereSql}
     ORDER BY created_at DESC, id DESC
     LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
);
$listStmt->execute($params);
$notifications = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) AS read_count,
        SUM(CASE WHEN status <> 'read' THEN 1 ELSE 0 END) AS unread_count
     FROM notification_queue
     WHERE tenant_id = :tenant_id
       AND recipient_type = 'user'
       AND recipient_id = :user_id
       AND channel = 'in_app'"
);
$summaryStmt->execute(array(':tenant_id' => $tenantId, ':user_id' => $userId));
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: array();

function fpH($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --fieldplx-primary:#74b824;
            --fieldplx-primary-dark:#5d971b;
            --fieldplx-text:#0b1933;
            --fieldplx-muted:#6f7b90;
            --fieldplx-border:#e5eaf1;
            --fieldplx-surface:#ffffff;
            --fieldplx-background:#f6f8fb;
            --fieldplx-topbar-height:70px;
            --fieldplx-sidebar-width:250px;
            --fieldplx-sidebar-collapsed-width:78px;
            --fd-navy:#001131;
            --fd-navy-light:#071f49;
            --fd-blue:#123d70;
            --fd-green:#74b824;
            --fd-green-dark:#5d971b;
            --fd-green-soft:#f0f8e5;
            --fd-red:#e45b66;
            --fd-bg:#f6f8fb;
            --fd-text:#0b1933;
            --fd-muted:#6f7b90;
            --fd-border:#e5eaf1;
        }
        *{box-sizing:border-box}
        html,body{min-height:100%}
        body{margin:0;overflow-x:hidden;background:var(--fd-bg)!important;color:var(--fd-text);font-family:Arial,Helvetica,sans-serif!important;font-size:14px}
        a{text-decoration:none}

        /* Topbar shell */
        .fieldplx-topbar{position:sticky;top:0;z-index:1030;min-height:70px!important;margin-left:var(--fieldplx-sidebar-width);width:calc(100% - var(--fieldplx-sidebar-width));background:#fff!important;border-bottom:1px solid var(--fd-border)!important;box-shadow:0 3px 14px rgba(0,17,49,.035);transition:margin-left .25s ease,width .25s ease}
        body.fieldplx-sidebar-collapsed .fieldplx-topbar{margin-left:var(--fieldplx-sidebar-collapsed-width);width:calc(100% - var(--fieldplx-sidebar-collapsed-width))}
        .fieldplx-topbar-inner{min-height:70px!important;display:flex;align-items:center;gap:13px!important;padding:0 27px!important}
        .fieldplx-brand-mobile{display:none;align-items:center;gap:9px;min-width:0;color:var(--fd-text)}
        .fieldplx-brand-placeholder{width:34px;height:34px;flex:0 0 34px;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;color:#fff;background:linear-gradient(135deg,#8fd236,#68aa1d);font-size:15px;font-weight:700}
        .fieldplx-brand-name{max-width:170px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-size:14px;font-weight:700}
        .fieldplx-page-heading{display:none!important}
        .fieldplx-menu-toggle,.fieldplx-topbar-action{width:41px!important;height:41px!important;padding:0;display:inline-flex;align-items:center;justify-content:center;border:0!important;border-radius:9px!important;color:var(--fd-navy)!important;background:transparent!important;font-size:19px}
        .fieldplx-menu-toggle:hover,.fieldplx-topbar-action:hover{background:var(--fd-green-soft)!important}
        .fieldplx-search-wrap{width:280px!important;margin-left:auto;position:relative}
        .fieldplx-search-icon{position:absolute;top:50%;left:12px;z-index:2;transform:translateY(-50%);color:#9ca3af;font-size:14px;pointer-events:none}
        .fieldplx-search-input{height:41px!important;padding:8px 13px 8px 38px!important;border:0!important;border-radius:8px!important;background:#f5f8fb!important;color:var(--fd-text)!important;box-shadow:none!important;font-size:12px!important}
        .fieldplx-search-input:focus{background:#f5f8fb!important;box-shadow:0 0 0 3px rgba(116,184,36,.14)!important}
        .fieldplx-topbar-spacer{display:none}
        .fieldplx-notification-count{position:absolute;top:-5px;right:-5px;min-width:18px;height:18px;padding:0 5px;display:inline-flex;align-items:center;justify-content:center;border:2px solid #fff;border-radius:999px;background:var(--fd-red)!important;color:#fff;font-size:9px;font-weight:700}
        .fieldplx-profile-button{min-width:0;padding:2px!important;display:flex;align-items:center;gap:9px;border:0!important;border-radius:9px!important;background:transparent!important;text-align:left}
        .fieldplx-profile-button:hover{background:var(--fd-green-soft)!important}
        .fieldplx-avatar{width:38px!important;height:38px!important;flex:0 0 38px!important;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;border-radius:50%!important;color:var(--fd-navy)!important;background:linear-gradient(135deg,#fff,#e8f3d9)!important;font-size:12px!important;font-weight:800!important}
        .fieldplx-avatar img{width:100%;height:100%;object-fit:cover}
        .fieldplx-profile-details{max-width:145px;min-width:0}
        .fieldplx-profile-name,.fieldplx-profile-role{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
        .fieldplx-profile-name{color:#111827;font-size:12px!important;font-weight:700}
        .fieldplx-profile-role{margin-top:1px;color:var(--fd-muted)!important;font-size:10px!important}
        .fieldplx-dropdown{width:340px;max-width:calc(100vw - 24px);padding:0;margin-top:10px!important;overflow:hidden;border:1px solid var(--fd-border)!important;border-radius:14px;background:#fff;box-shadow:0 18px 45px rgba(29,38,74,.14)!important}
        .fieldplx-dropdown-header{min-height:48px;padding:11px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--fd-border);background:#fff}
        .fieldplx-dropdown-title{margin:0;color:#111827;font-size:14px;line-height:1.2;font-weight:700}
        .fieldplx-notification-item{padding:11px 14px;display:flex;gap:10px;border-bottom:1px solid #f1f2f4;color:inherit}
        .fieldplx-notification-item:hover{background:#f7fbed}.fieldplx-notification-item.is-unread{background:#f7fbed}
        .fieldplx-notification-icon{width:32px;height:32px;flex:0 0 32px;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;background:var(--fd-green-soft);color:var(--fd-green-dark);font-size:14px}
        .fieldplx-notification-content{min-width:0}.fieldplx-notification-title{margin:0;color:#111827;font-size:11px;font-weight:700}.fieldplx-notification-message{margin-top:3px;overflow:hidden;display:-webkit-box;color:var(--fd-muted);font-size:10px;line-height:1.45;-webkit-line-clamp:2;-webkit-box-orient:vertical}.fieldplx-notification-time{margin-top:4px;color:#9ca3af;font-size:9px}
        .fieldplx-empty-notifications{min-height:155px;padding:28px 18px 24px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#718096;background:#fff;font-size:13px}.fieldplx-empty-notifications i{margin-bottom:10px;color:#a9c77c;font-size:30px}
        .fieldplx-dropdown-footer{min-height:44px;padding:10px 14px;display:flex;align-items:center;justify-content:center;border-top:1px solid var(--fd-border);background:#fff}.fieldplx-dropdown-footer a{color:var(--fd-green-dark)!important;font-size:11px;font-weight:700}
        .fieldplx-profile-menu{width:230px;padding:7px;border:1px solid var(--fd-border)!important;border-radius:12px;box-shadow:0 18px 45px rgba(29,38,74,.14)!important}.fieldplx-profile-menu-header{padding:9px 10px 11px;border-bottom:1px solid #f0f1f3}.fieldplx-profile-menu-name{overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#111827;font-size:12px;font-weight:700}.fieldplx-profile-menu-email{margin-top:2px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:var(--fd-muted);font-size:10px}.fieldplx-profile-menu .dropdown-item{padding:9px 10px;display:flex;align-items:center;gap:9px;border-radius:8px;color:#374151;font-size:11px}.fieldplx-profile-menu .dropdown-item:hover{color:var(--fd-green-dark)!important;background:var(--fd-green-soft)}

        /* Sidebar shell */
        .fieldplx-sidebar{width:var(--fieldplx-sidebar-width)!important;min-width:var(--fieldplx-sidebar-width)!important;height:100vh!important;position:fixed!important;top:0!important;left:0!important;z-index:1045!important;display:flex;flex-direction:column;color:#fff!important;background:linear-gradient(180deg,var(--fd-navy-light),var(--fd-navy))!important;border-right:0!important;transition:width .25s ease,min-width .25s ease,transform .25s ease!important}
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar{width:var(--fieldplx-sidebar-collapsed-width)!important;min-width:var(--fieldplx-sidebar-collapsed-width)!important}
        .fieldplx-sidebar-header{min-height:68px!important;padding:9px 14px 10px!important;display:flex;align-items:center;border-bottom:1px solid rgba(255,255,255,.08)!important}
        .fieldplx-sidebar-brand{min-width:0;display:flex;align-items:center;gap:10px;color:#fff!important}
        .fieldplx-sidebar-logo,.fieldplx-sidebar-logo-placeholder{width:40px!important;height:40px!important;flex:0 0 40px!important;border-radius:10px!important}.fieldplx-sidebar-logo{object-fit:contain;background:#fff}.fieldplx-sidebar-logo-placeholder{display:inline-flex;align-items:center;justify-content:center;color:#fff!important;background:linear-gradient(135deg,#8fd236,#68aa1d)!important;font-size:18px!important;font-weight:700}
        .fieldplx-sidebar-brand-text{min-width:0;display:block}.fieldplx-sidebar-company-name{max-width:155px!important;display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#fff!important;font-size:16px!important;font-weight:700!important}.fieldplx-sidebar-product-name{margin-top:1px;display:block;color:#9fda55!important;font-size:9px!important;font-weight:600;letter-spacing:.4px;text-transform:uppercase}
        .fieldplx-sidebar-close{width:32px;height:32px;margin-left:auto;padding:0;display:none;align-items:center;justify-content:center;border:0;border-radius:8px;background:transparent;color:#fff;font-size:16px}
        .fieldplx-sidebar-body{flex:1;overflow-y:auto;overflow-x:hidden;padding:12px 14px!important;scrollbar-width:none!important}.fieldplx-sidebar-body::-webkit-scrollbar{display:none}.fieldplx-sidebar-section-label{margin:7px 12px!important;color:rgba(255,255,255,.5)!important;font-size:9px!important;font-weight:700;letter-spacing:.65px;text-transform:uppercase}.fieldplx-sidebar-nav{display:flex;flex-direction:column;gap:3px!important}
        .fieldplx-sidebar-link{width:100%;min-height:46px!important;margin-bottom:3px!important;padding:0 14px!important;display:flex;align-items:center;gap:15px!important;border:0;border-radius:9px!important;background:transparent;color:rgba(255,255,255,.94)!important;text-align:left;font-family:inherit;font-size:14px!important;font-weight:600!important}.fieldplx-sidebar-link:hover{color:#fff!important;background:rgba(255,255,255,.08)!important}.fieldplx-sidebar-link.active,.fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-link{color:#fff!important;background:linear-gradient(90deg,#7fc92d,#68aa1d)!important;box-shadow:0 6px 18px rgba(0,17,49,.28)!important}.fieldplx-sidebar-link-icon{width:21px!important;height:21px!important;flex:0 0 21px!important;display:inline-flex;align-items:center;justify-content:center;font-size:19px!important}.fieldplx-sidebar-link-text{min-width:0;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.fieldplx-sidebar-arrow{margin-left:auto;color:rgba(255,255,255,.65)!important;font-size:10px;transition:transform .2s ease}.fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow{transform:rotate(180deg)}
        .fieldplx-sidebar-submenu{max-height:0;overflow:hidden;padding-left:36px!important;transition:max-height .25s ease}.fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-submenu{max-height:520px;padding-top:3px;padding-bottom:3px}.fieldplx-sidebar-sublink{min-height:34px!important;padding:7px 9px;position:relative;display:flex;align-items:center;border-radius:7px;color:rgba(255,255,255,.72)!important;font-size:11px!important;font-weight:500}.fieldplx-sidebar-sublink::before{width:5px;height:5px;margin-right:9px;flex:0 0 5px;content:"";border-radius:50%;background:rgba(255,255,255,.35)!important}.fieldplx-sidebar-sublink:hover,.fieldplx-sidebar-sublink.active{color:#fff!important;background:rgba(255,255,255,.08)!important}.fieldplx-sidebar-sublink.active::before{background:#9fda55!important}
        .fieldplx-sidebar-footer{padding:10px 14px 14px!important;border-top:1px solid rgba(255,255,255,.08)!important}.fieldplx-sidebar-user{min-height:62px;padding:8px;display:flex;align-items:center;gap:9px;border-radius:10px;background:rgba(255,255,255,.08)!important}.fieldplx-sidebar-user-avatar{width:38px!important;height:38px!important;flex:0 0 38px!important;display:inline-flex;align-items:center;justify-content:center;border-radius:50%!important;color:var(--fd-navy)!important;background:linear-gradient(135deg,#fff,#e8f3d9)!important;font-size:11px;font-weight:700}.fieldplx-sidebar-user-details{min-width:0;flex:1}.fieldplx-sidebar-user-name,.fieldplx-sidebar-user-role{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.fieldplx-sidebar-user-name{color:#fff!important;font-size:12px!important;font-weight:700}.fieldplx-sidebar-user-role{margin-top:1px;color:rgba(255,255,255,.6)!important;font-size:9px!important}.fieldplx-sidebar-logout{width:29px;height:29px;flex:0 0 29px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;color:rgba(255,255,255,.7)!important;font-size:14px}.fieldplx-sidebar-logout:hover{color:#fff!important;background:rgba(228,91,102,.3)!important}.fieldplx-sidebar-overlay{display:none}
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout{display:none}.fieldplx-sidebar-collapsed .fieldplx-sidebar-header{justify-content:center;padding-left:8px!important;padding-right:8px!important}.fieldplx-sidebar-collapsed .fieldplx-sidebar-link{justify-content:center;padding-left:8px!important;padding-right:8px!important}.fieldplx-sidebar-collapsed .fieldplx-sidebar-user{justify-content:center;padding-left:5px;padding-right:5px}

        /* Main shell */
        .fieldplx-main-layout{display:block!important;min-height:calc(100vh - 70px)!important}.fieldplx-main-content{margin-left:var(--fieldplx-sidebar-width);min-width:0;transition:margin-left .25s ease}.fieldplx-sidebar-collapsed .fieldplx-main-content{margin-left:var(--fieldplx-sidebar-collapsed-width)}.fieldplx-content-wrapper{padding:0!important}.fieldplx-footer{display:block!important}

        /* Notifications page */
        .fn-page{width:100%;max-width:1600px;margin:0 auto;padding:25px 27px 35px}
        .fn-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:20px}.fn-title{margin:0 0 7px;color:var(--fd-navy);font-size:21px;font-weight:700}.fn-subtitle{margin:0;color:var(--fd-muted);font-size:12px}
        .fn-mark-all{height:42px;padding:0 15px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:9px;background:#fff;color:var(--fd-navy);box-shadow:0 4px 13px rgba(31,43,88,.04);font-size:12px;font-weight:600}.fn-mark-all:hover{border-color:#cfe3ae;color:var(--fd-green-dark);background:#f9fcf4}.fn-mark-all:disabled{opacity:.55;cursor:not-allowed}
        .fn-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:18px}.fn-summary-card{min-height:108px;padding:18px 20px;display:flex;flex-direction:column;justify-content:center;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}.fn-summary-label{margin-bottom:9px;color:#506784;font-size:13px}.fn-summary-value{color:#020b16;font-size:31px;line-height:1;font-weight:700}
        .fn-card{overflow:hidden;border:1px solid var(--fd-border);border-radius:10px;background:#fff;box-shadow:0 4px 14px rgba(31,43,88,.05)}.fn-toolbar{padding:14px 16px;display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--fd-border)}.fn-filter{height:34px;padding:0 13px;display:inline-flex;align-items:center;border:1px solid var(--fd-border);border-radius:8px;color:var(--fd-muted);background:#fff;font-size:11px;font-weight:600}.fn-filter:hover{border-color:#cfe3ae;color:var(--fd-green-dark);background:#f9fcf4}.fn-filter.active{border-color:#cfe3ae;color:var(--fd-green-dark);background:var(--fd-green-soft)}
        .fn-list{margin:0;padding:0;list-style:none}.fn-item{position:relative;min-height:78px;padding:15px 18px;display:flex;gap:13px;align-items:flex-start;border-bottom:1px solid var(--fd-border);background:#fff;cursor:pointer;transition:background .15s ease,border-color .15s ease}.fn-item:last-child{border-bottom:0}.fn-item:hover{background:#fbfcfa}.fn-item.unread{background:#f8fced}.fn-item.unread:hover{background:#f3f9e8}.fn-icon{width:42px;height:42px;min-width:42px;display:flex;align-items:center;justify-content:center;border-radius:11px;background:#eef3f8;color:#174a7e;font-size:18px}.fn-item.unread .fn-icon{background:#eaf6dc;color:var(--fd-green-dark)}.fn-body{min-width:0;flex:1}.fn-row{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.fn-subject{margin-bottom:5px;color:var(--fd-navy);font-size:13px;font-weight:700}.fn-message{max-width:950px;color:#59677c;font-size:12px;line-height:1.5;word-break:break-word}.fn-time{color:#8b96a8;font-size:10px;white-space:nowrap}.fn-unread-dot{width:8px;height:8px;margin-top:3px;flex:0 0 auto;border-radius:50%;background:var(--fd-green)}
        .fn-empty{padding:62px 20px;text-align:center;color:var(--fd-muted);font-size:12px}.fn-empty i{display:block;margin-bottom:10px;color:#b5bfcc;font-size:34px}.fn-pagination{min-height:58px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--fd-border);color:var(--fd-muted);font-size:11px}.fn-pages{display:flex;gap:7px}.fn-page-btn{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--fd-border);border-radius:8px;background:#fff;color:var(--fd-navy)}.fn-page-btn:hover{border-color:#cfe3ae;color:var(--fd-green-dark);background:#f9fcf4}.fn-page-btn.disabled{opacity:.4;pointer-events:none}
        .fn-toast{position:fixed;right:22px;top:86px;z-index:1085;min-width:280px;max-width:380px;padding:12px 14px;display:none;border:1px solid var(--fd-border);border-left:4px solid var(--fd-green);border-radius:8px;background:#fff;box-shadow:0 10px 30px rgba(0,17,49,.16)}.fn-toast.show{display:block}.fn-toast.error{border-left-color:#dc2626}.fn-toast-title{color:var(--fd-navy);font-size:12px;font-weight:600}

        @media(max-width:991.98px){
            .fieldplx-topbar,.fieldplx-sidebar-collapsed .fieldplx-topbar{margin-left:0!important;width:100%!important}.fieldplx-topbar-inner{padding:0 18px!important}.fieldplx-brand-mobile{display:flex}.fieldplx-sidebar,.fieldplx-sidebar-collapsed .fieldplx-sidebar{width:250px!important;min-width:250px!important;transform:translateX(-100%);box-shadow:none!important}.fieldplx-sidebar-mobile-open .fieldplx-sidebar{transform:translateX(0)!important}.fieldplx-main-content,.fieldplx-sidebar-collapsed .fieldplx-main-content{margin-left:0!important}.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout,.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu{display:initial}.fieldplx-sidebar-close{display:inline-flex}.fieldplx-sidebar-overlay{position:fixed;inset:0;z-index:1040;display:block;visibility:hidden;background:rgba(17,24,39,.42);opacity:0;transition:opacity .2s ease,visibility .2s ease}.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay{visibility:visible;opacity:1}.fieldplx-profile-details{display:none}
        }
        @media(max-width:767.98px){
            :root{--fieldplx-topbar-height:64px}.fieldplx-topbar,.fieldplx-topbar-inner{min-height:64px!important}.fieldplx-topbar-inner{padding:0 13px!important;gap:8px!important}.fieldplx-search-wrap{display:none!important}.fieldplx-brand-name{display:none}.fieldplx-topbar-spacer{display:block;margin-left:auto}.fn-page{padding:17px 13px 28px}.fn-head{align-items:stretch;flex-direction:column}.fn-mark-all{width:100%}.fn-summary{grid-template-columns:1fr}.fn-row{display:block}.fn-time{display:inline-block;margin-top:7px}.fn-item{padding:14px}.fn-pagination{gap:10px}.fieldplx-dropdown{width:min(330px,calc(100vw - 22px))}
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="fn-page">
                <div class="fn-head">
                    <div>
                        <h1 class="fn-title">Notifications</h1>
                        <div class="fn-subtitle">View account notifications and keep track of unread updates.</div>
                    </div>
                    <button type="button" class="fn-mark-all" id="markAllReadBtn">
                        <i class="bi bi-check2-all me-1"></i> Mark all read
                    </button>
                </div>

                <div class="fn-summary">
                    <div class="fn-summary-card"><div class="fn-summary-label">All Notifications</div><div class="fn-summary-value"><?= (int)($summary['total_count'] ?? 0) ?></div></div>
                    <div class="fn-summary-card"><div class="fn-summary-label">Unread</div><div class="fn-summary-value" id="summaryUnread"><?= (int)($summary['unread_count'] ?? 0) ?></div></div>
                    <div class="fn-summary-card"><div class="fn-summary-label">Read</div><div class="fn-summary-value"><?= (int)($summary['read_count'] ?? 0) ?></div></div>
                </div>

                <div class="fn-card">
                    <div class="fn-toolbar">
                        <a class="fn-filter <?= $filter === 'all' ? 'active' : '' ?>" href="notifications.php?filter=all">All</a>
                        <a class="fn-filter <?= $filter === 'unread' ? 'active' : '' ?>" href="notifications.php?filter=unread">Unread</a>
                        <a class="fn-filter <?= $filter === 'read' ? 'active' : '' ?>" href="notifications.php?filter=read">Read</a>
                    </div>

                    <?php if (!$notifications): ?>
                        <div class="fn-empty">
                            <i class="bi bi-bell-slash"></i>
                            No notifications found.
                        </div>
                    <?php else: ?>
                        <ul class="fn-list" id="notificationList">
                            <?php foreach ($notifications as $item):
                                $isUnread = ((string)$item['status'] !== 'read');
                                $targetUrl = fpNotifyTargetUrl($item['related_type'], $item['related_id']);
                            ?>
                                <li class="fn-item <?= $isUnread ? 'unread' : '' ?>"
                                    data-id="<?= (int)$item['id'] ?>"
                                    data-url="<?= fpH($targetUrl) ?>"
                                    tabindex="0"
                                    role="button">
                                    <span class="fn-icon"><i class="bi <?= fpH(fpNotifyIcon($item['related_type'], $item['subject'])) ?>"></i></span>
                                    <div class="fn-body">
                                        <div class="fn-row">
                                            <div>
                                                <div class="fn-subject"><?= fpH($item['subject'] ?: 'Notification') ?></div>
                                                <div class="fn-message"><?= nl2br(fpH($item['body'])) ?></div>
                                            </div>
                                            <div class="d-flex align-items-start gap-2">
                                                <span class="fn-time"><?= fpH(fpNotifyTimeAgo($item['created_at'])) ?></span>
                                                <?php if ($isUnread): ?><span class="fn-unread-dot" title="Unread"></span><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="fn-pagination">
                        <span>Showing <?= $total ? ($offset + 1) : 0 ?>-<?= $total ? min($offset + $perPage, $total) : 0 ?> of <?= $total ?></span>
                        <div class="fn-pages">
                            <a class="fn-page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="notifications.php?filter=<?= fpH($filter) ?>&page=<?= max(1, $page - 1) ?>"><i class="bi bi-chevron-left"></i></a>
                            <a class="fn-page-btn <?= $page >= $pages ? 'disabled' : '' ?>" href="notifications.php?filter=<?= fpH($filter) ?>&page=<?= min($pages, $page + 1) ?>"><i class="bi bi-chevron-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="fn-toast" id="fnToast"><div class="fn-toast-title" id="fnToastText"></div></div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    var csrfToken = <?= json_encode($csrfToken) ?>;
    var list = document.getElementById('notificationList');
    var markAll = document.getElementById('markAllReadBtn');
    var toast = document.getElementById('fnToast');
    var toastText = document.getElementById('fnToastText');
    var toastTimer = null;

    function showToast(message, error){
        if(!toast || !toastText) return;
        toastText.textContent = message || '';
        toast.classList.toggle('error', !!error);
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function(){ toast.classList.remove('show'); }, 3000);
    }

    function post(data){
        var fd = new FormData();
        Object.keys(data).forEach(function(key){ fd.append(key, data[key]); });
        fd.append('csrf_token', csrfToken);
        return fetch('notifications.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(function(response){
            return response.text().then(function(text){
                var payload;
                try { payload = JSON.parse(text); }
                catch(e) { throw new Error('Invalid server response. HTTP ' + response.status); }
                if(!response.ok || !payload.success){
                    throw new Error(payload.message || ('Request failed. HTTP ' + response.status));
                }
                return payload;
            });
        });
    }

    function markItemRead(item){
        if(!item) return;
        var id = item.getAttribute('data-id');
        var url = item.getAttribute('data-url') || '';
        if(!id) return;

        if(!item.classList.contains('unread')){
            if(url) window.location.href = url;
            return;
        }

        item.style.pointerEvents = 'none';
        post({action:'mark_read', notification_id:id})
            .then(function(data){
                item.classList.remove('unread');
                var dot = item.querySelector('.fn-unread-dot');
                if(dot) dot.remove();
                var unread = document.getElementById('summaryUnread');
                if(unread) unread.textContent = String(data.unread_count || 0);
                var topCount = document.querySelector('.fieldplx-notification-count');
                if(topCount){
                    topCount.textContent = String(data.unread_count || 0);
                    topCount.style.display = Number(data.unread_count || 0) > 0 ? '' : 'none';
                }
                if(data.redirect_url){
                    window.location.href = data.redirect_url;
                    return;
                }
                if(url){
                    window.location.href = url;
                }
            })
            .catch(function(err){ showToast(err.message || 'Unable to mark notification as read.', true); })
            .finally(function(){ item.style.pointerEvents = ''; });
    }

    if(list){
        list.addEventListener('click', function(e){
            var item = e.target.closest('.fn-item');
            if(item) markItemRead(item);
        });
        list.addEventListener('keydown', function(e){
            if(e.key === 'Enter' || e.key === ' '){
                var item = e.target.closest('.fn-item');
                if(item){ e.preventDefault(); markItemRead(item); }
            }
        });
    }

    if(markAll){
        markAll.addEventListener('click', function(){
            markAll.disabled = true;
            post({action:'mark_all_read'})
                .then(function(){
                    document.querySelectorAll('.fn-item.unread').forEach(function(item){
                        item.classList.remove('unread');
                        var dot = item.querySelector('.fn-unread-dot');
                        if(dot) dot.remove();
                    });
                    var unread = document.getElementById('summaryUnread');
                    if(unread) unread.textContent = '0';
                    var topCount = document.querySelector('.fieldplx-notification-count');
                    if(topCount){ topCount.textContent = '0'; topCount.style.display = 'none'; }
                    showToast('All notifications marked as read.', false);
                })
                .catch(function(err){ showToast(err.message || 'Unable to mark notifications as read.', true); })
                .finally(function(){ markAll.disabled = false; });
        });
    }
})();
</script>
</body>
</html>
