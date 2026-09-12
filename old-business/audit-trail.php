<?php
/*
 * FieldPlx Tenant Audit Trail
 * Canonical Add-Invoice / Invoice UI version 3.0.0 - 2026-09-08
 * Deployment path: /business/audit-trail.php
 * PHP 7.2 compatible.
 */

require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Audit Trail';
$activePage = 'audit-trail';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is not available.');
}

$tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
if ($tenantId <= 0) {
    http_response_code(403);
    exit('Tenant session is not available.');
}

function atH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function atGet($key, $default = '')
{
    return isset($_GET[$key]) && !is_array($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function atTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(array(':t' => $table));
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function atPretty($value)
{
    $value = trim((string)$value);
    if ($value === '') return '-';
    return ucwords(strtolower(str_replace('_', ' ', $value)));
}

function atJsonValue($raw)
{
    if ($raw === null || trim((string)$raw) === '') return null;
    $decoded = json_decode((string)$raw, true);
    if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    return (string)$raw;
}

function atDateLabel($value)
{
    if (!$value) return 'Any';
    $ts = strtotime((string)$value);
    return $ts ? date('d M Y', $ts) : (string)$value;
}

function atEmailFilterLabel($value)
{
    $map = array(
        '' => 'All',
        'activity' => 'Email activity',
        'sent' => 'Sent',
        'failed' => 'Failed'
    );
    return isset($map[$value]) ? $map[$value] : 'All';
}

if (!atTable($pdo, 'audit_logs')) {
    http_response_code(500);
    exit('The audit_logs table is not available.');
}

$q           = atGet('q');
$dateFrom    = atGet('date_from');
$dateTo      = atGet('date_to');
$module      = atGet('module');
$category    = atGet('category');
$action      = atGet('action');
$objectType  = atGet('object_type');
$emailStatus = strtolower(atGet('email_status'));
$userId      = (int)atGet('user_id', '0');
$branchId    = (int)atGet('branch_id', '0');
$page        = max(1, (int)atGet('page', '1'));
$perPage     = (int)atGet('per_page', '25');
if (!in_array($perPage, array(10, 25, 50, 100), true)) $perPage = 25;
if (!in_array($emailStatus, array('', 'activity', 'sent', 'failed'), true)) $emailStatus = '';

$where = array('a.tenant_id = :tenant_id');
$params = array(':tenant_id' => $tenantId);

if ($q !== '') {
    $where[] = "(a.user_name LIKE :q OR a.module LIKE :q OR a.action LIKE :q OR a.object_type LIKE :q OR a.record_no LIKE :q OR a.ip_address LIKE :q OR a.old_values LIKE :q OR a.new_values LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'a.created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'a.created_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}
if ($module !== '') {
    $where[] = 'a.module = :module';
    $params[':module'] = $module;
}
if ($category !== '') {
    $where[] = 'a.audit_category = :category';
    $params[':category'] = $category;
}
if ($action !== '') {
    $where[] = 'a.action = :action';
    $params[':action'] = $action;
}
if ($objectType !== '') {
    $where[] = 'a.object_type = :object_type';
    $params[':object_type'] = $objectType;
}
if ($userId > 0) {
    $where[] = 'a.user_id = :user_id';
    $params[':user_id'] = $userId;
}
if ($branchId > 0) {
    $where[] = 'a.branch_id = :branch_id';
    $params[':branch_id'] = $branchId;
}

/* Dedicated email activity filter. Works with events such as
 * INVOICE_EMAIL_SENT, INVOICE_EMAIL_FAILED,
 * PAYMENT_RECEIPT_EMAIL_SENT and PAYMENT_RECEIPT_EMAIL_FAILED.
 */
if ($emailStatus === 'activity') {
    $where[] = "UPPER(a.action) LIKE '%EMAIL%'";
} elseif ($emailStatus === 'sent') {
    $where[] = "(UPPER(a.action) LIKE '%EMAIL_SENT%' OR UPPER(a.action) LIKE '%EMAIL_SUCCESS%')";
} elseif ($emailStatus === 'failed') {
    $where[] = "(UPPER(a.action) LIKE '%EMAIL_FAILED%' OR UPPER(a.action) LIKE '%EMAIL_ERROR%')";
}

$whereSql = implode(' AND ', $where);

$hasBranches = atTable($pdo, 'branches');
$hasUsers = atTable($pdo, 'users');

/* Filter options */
$modules = array();
$categories = array();
$actions = array();
$objectTypes = array();
$users = array();
$branches = array();

$stmt = $pdo->prepare("SELECT DISTINCT module FROM audit_logs WHERE tenant_id=:t AND module IS NOT NULL AND module<>'' ORDER BY module");
$stmt->execute(array(':t' => $tenantId));
$modules = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT DISTINCT audit_category FROM audit_logs WHERE tenant_id=:t AND audit_category IS NOT NULL AND audit_category<>'' ORDER BY audit_category");
$stmt->execute(array(':t' => $tenantId));
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT DISTINCT action FROM audit_logs WHERE tenant_id=:t AND action<>'' ORDER BY action");
$stmt->execute(array(':t' => $tenantId));
$actions = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT DISTINCT object_type FROM audit_logs WHERE tenant_id=:t AND object_type<>'' ORDER BY object_type");
$stmt->execute(array(':t' => $tenantId));
$objectTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

if ($hasUsers) {
    $stmt = $pdo->prepare("SELECT id,first_name,last_name,email FROM users WHERE tenant_id=:t ORDER BY first_name,last_name,id");
    $stmt->execute(array(':t' => $tenantId));
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($hasBranches) {
    $stmt = $pdo->prepare("SELECT id,name,branch_code FROM branches WHERE tenant_id=:t AND status<>'archived' ORDER BY is_head_office DESC,name,id");
    $stmt->execute(array(':t' => $tenantId));
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Summary cards */
$summary = array(
    'total' => 0,
    'today' => 0,
    'week' => 0,
    'users' => 0,
    'email_sent' => 0,
    'email_failed' => 0
);
$stmt = $pdo->prepare("SELECT
    COUNT(*) total_count,
    SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) today_count,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) week_count,
    COUNT(DISTINCT user_id) user_count,
    SUM(CASE WHEN UPPER(action) LIKE '%EMAIL_SENT%' OR UPPER(action) LIKE '%EMAIL_SUCCESS%' THEN 1 ELSE 0 END) email_sent_count,
    SUM(CASE WHEN UPPER(action) LIKE '%EMAIL_FAILED%' OR UPPER(action) LIKE '%EMAIL_ERROR%' THEN 1 ELSE 0 END) email_failed_count
    FROM audit_logs WHERE tenant_id=:t");
$stmt->execute(array(':t' => $tenantId));
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if ($s) {
    $summary['total'] = (int)$s['total_count'];
    $summary['today'] = (int)$s['today_count'];
    $summary['week'] = (int)$s['week_count'];
    $summary['users'] = (int)$s['user_count'];
    $summary['email_sent'] = (int)$s['email_sent_count'];
    $summary['email_failed'] = (int)$s['email_failed_count'];
}

/* Count filtered records */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$branchSelect = $hasBranches ? ",b.name AS branch_name,b.branch_code" : ",NULL AS branch_name,NULL AS branch_code";
$branchJoin = $hasBranches ? " LEFT JOIN branches b ON b.id=a.branch_id AND b.tenant_id=a.tenant_id " : '';
$userSelect = $hasUsers ? ",u.first_name,u.last_name,u.email AS actor_email" : ",NULL AS first_name,NULL AS last_name,NULL AS actor_email";
$userJoin = $hasUsers ? " LEFT JOIN users u ON u.id=a.user_id AND u.tenant_id=a.tenant_id " : '';

$baseSelect = "SELECT a.*{$branchSelect}{$userSelect} FROM audit_logs a{$branchJoin}{$userJoin} WHERE {$whereSql} ORDER BY a.created_at DESC,a.id DESC";

/* CSV export uses the exact same active filters. */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->prepare($baseSelect . ' LIMIT 10000');
    $exportStmt->execute($params);
    $fileName = 'fieldplx-audit-trail-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Date & Time','User','Branch','Category','Module','Action','Object Type','Object ID','Record No','IP Address','Device','Location','Old Values','New Values'));
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        $actor = trim((string)$row['user_name']);
        if ($actor === '') $actor = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
        if ($actor === '') $actor = !empty($row['platform_user_id']) ? 'Platform User #' . (int)$row['platform_user_id'] : 'System';
        fputcsv($out, array(
            $row['created_at'],
            $actor,
            !empty($row['branch_name']) ? $row['branch_name'] : '',
            $row['audit_category'],
            $row['module'],
            $row['action'],
            $row['object_type'],
            $row['object_id'],
            $row['record_no'],
            $row['ip_address'],
            $row['device_type'],
            $row['location_label'],
            $row['old_values'],
            $row['new_values']
        ));
    }
    fclose($out);
    exit;
}

$dataStmt = $pdo->prepare($baseSelect . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

$detailPayload = array();
foreach ($rows as $row) {
    $actorName = trim((string)$row['user_name']);
    if ($actorName === '') $actorName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    if ($actorName === '') $actorName = !empty($row['platform_user_id']) ? 'Platform User #' . (int)$row['platform_user_id'] : 'System';

    $detailPayload[(string)$row['id']] = array(
        'id' => (int)$row['id'],
        'created_at' => (string)$row['created_at'],
        'actor' => $actorName,
        'actor_email' => isset($row['actor_email']) ? (string)$row['actor_email'] : '',
        'branch' => !empty($row['branch_name']) ? (string)$row['branch_name'] : '',
        'category' => (string)$row['audit_category'],
        'module' => (string)$row['module'],
        'action' => (string)$row['action'],
        'object_type' => (string)$row['object_type'],
        'object_id' => $row['object_id'] !== null ? (int)$row['object_id'] : null,
        'record_no' => (string)$row['record_no'],
        'ip_address' => (string)$row['ip_address'],
        'device_type' => (string)$row['device_type'],
        'user_agent' => (string)$row['user_agent'],
        'location' => (string)$row['location_label'],
        'latitude' => $row['location_latitude'],
        'longitude' => $row['location_longitude'],
        'accuracy' => $row['location_accuracy_meters'],
        'old_values' => atJsonValue($row['old_values']),
        'new_values' => atJsonValue($row['new_values'])
    );
}

function atPageUrl($pageNo)
{
    $query = $_GET;
    unset($query['export']);
    $query['page'] = (int)$pageNo;
    return '?' . http_build_query($query);
}

$exportQuery = $_GET;
unset($exportQuery['page']);
$exportQuery['export'] = 'csv';
$exportUrl = '?' . http_build_query($exportQuery);

$userFilterLabel = 'All';
if ($userId > 0) {
    foreach ($users as $u) {
        if ((int)$u['id'] === $userId) {
            $name = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']);
            $userFilterLabel = $name !== '' ? $name : (string)$u['email'];
            break;
        }
    }
}

$activityParts = array();
if ($module !== '') $activityParts[] = $module;
if ($category !== '') $activityParts[] = atPretty($category);
if ($action !== '') $activityParts[] = atPretty($action);
if ($objectType !== '') $activityParts[] = atPretty($objectType);
if ($branchId > 0) {
    foreach ($branches as $b) {
        if ((int)$b['id'] === $branchId) {
            $activityParts[] = (string)$b['name'];
            break;
        }
    }
}
$activityFilterLabel = $activityParts ? implode(' · ', $activityParts) : 'All';
$dateFilterLabel = ($dateFrom !== '' || $dateTo !== '')
    ? atDateLabel($dateFrom) . ' - ' . atDateLabel($dateTo)
    : 'All';
$emailFilterLabel = atEmailFilterLabel($emailStatus);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Audit Trail - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <style>
        /* ==========================================================
           FieldPlx canonical tenant shell
           Copied from the current Add Invoice template so Audit Trail
           uses the exact same topbar, sidebar, footer and responsive shell.
           ========================================================== */
        :root{
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

        *{
            box-sizing:border-box;
        }

        html,
        body{
            margin:0;
            min-height:100%;
            overflow-x:hidden;
        }

        body{
            min-height:100vh;
            background:var(--fd-bg)!important;
            color:var(--fd-text);
            font-family:Arial,Helvetica,sans-serif!important;
            font-size:14px;
        }

        a,
        a:link,
        a:visited,
        a:hover,
        a:focus,
        a:active{
            text-decoration:none!important;
        }

        /* ---------- Topbar ---------- */
        .fieldplx-topbar{
            min-height:70px!important;
            position:sticky!important;
            top:0!important;
            z-index:1030!important;
            margin-left:var(--fieldplx-sidebar-width);
            width:calc(100% - var(--fieldplx-sidebar-width));
            background:#fff!important;
            border-bottom:1px solid var(--fd-border)!important;
            box-shadow:0 3px 14px rgba(0,17,49,.035)!important;
            backdrop-filter:none!important;
            transition:margin-left .25s ease,width .25s ease;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-topbar{
            margin-left:var(--fieldplx-sidebar-collapsed-width);
            width:calc(100% - var(--fieldplx-sidebar-collapsed-width));
        }

        .fieldplx-topbar-inner{
            min-height:70px!important;
            padding:0 27px!important;
            display:flex!important;
            align-items:center!important;
            gap:13px!important;
        }

        .fieldplx-brand-mobile{
            display:none!important;
            align-items:center!important;
            gap:9px!important;
            min-width:0!important;
            color:var(--fd-text)!important;
        }

        .fieldplx-brand-logo{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            border-radius:10px!important;
            object-fit:contain!important;
        }

        .fieldplx-brand-placeholder{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:10px!important;
            color:#fff!important;
            background:linear-gradient(135deg,#8fd236,#68aa1d)!important;
            font-weight:700!important;
        }

        .fieldplx-brand-name{
            max-width:170px!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:var(--fd-text)!important;
            font-size:14px!important;
            font-weight:700!important;
        }

        .fieldplx-page-heading{
            display:none!important;
        }

        .fieldplx-menu-toggle,
        .fieldplx-topbar-action{
            width:41px!important;
            height:41px!important;
            min-width:41px!important;
            padding:0!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            position:relative!important;
            border:0!important;
            border-radius:9px!important;
            color:var(--fd-navy)!important;
            background:transparent!important;
            font-size:18px!important;
            box-shadow:none!important;
        }

        .fieldplx-menu-toggle:hover,
        .fieldplx-topbar-action:hover{
            color:var(--fd-navy)!important;
            background:var(--fd-green-soft)!important;
        }

        .fieldplx-search-wrap{
            width:280px!important;
            margin-left:auto!important;
            position:relative!important;
        }

        .fieldplx-search-icon{
            position:absolute!important;
            top:50%!important;
            left:13px!important;
            z-index:2!important;
            transform:translateY(-50%)!important;
            color:#98a3b2!important;
            font-size:14px!important;
            pointer-events:none!important;
        }

        .fieldplx-search-input{
            width:100%!important;
            height:41px!important;
            padding:8px 13px 8px 38px!important;
            border:0!important;
            border-radius:8px!important;
            outline:0!important;
            background:#f5f8fb!important;
            color:var(--fd-text)!important;
            font-size:12px!important;
            box-shadow:none!important;
        }

        .fieldplx-search-input:focus{
            background:#f5f8fb!important;
            box-shadow:0 0 0 3px rgba(116,184,36,.14)!important;
        }

        .fieldplx-notification-count{
            position:absolute!important;
            top:-5px!important;
            right:-5px!important;
            min-width:18px!important;
            height:18px!important;
            padding:0 5px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border:2px solid #fff!important;
            border-radius:999px!important;
            color:#fff!important;
            background:var(--fd-red)!important;
            font-size:9px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-button{
            min-width:0!important;
            padding:2px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border:0!important;
            border-radius:9px!important;
            background:transparent!important;
            color:var(--fd-text)!important;
            text-align:left!important;
            box-shadow:none!important;
        }

        .fieldplx-profile-button:hover{
            background:var(--fd-green-soft)!important;
        }

        .fieldplx-avatar{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            overflow:hidden!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border:0!important;
            border-radius:50%!important;
            color:var(--fd-navy)!important;
            background:linear-gradient(135deg,#fff,#e8f3d9)!important;
            font-size:12px!important;
            font-weight:800!important;
        }

        .fieldplx-avatar img{
            width:100%!important;
            height:100%!important;
            object-fit:cover!important;
        }

        .fieldplx-profile-details{
            max-width:145px!important;
            min-width:0!important;
        }

        .fieldplx-profile-name,
        .fieldplx-profile-role{
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-profile-name{
            color:#111827!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-role{
            margin-top:1px!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
        }

        /* ---------- Dropdowns ---------- */
        .fieldplx-dropdown{
            width:340px!important;
            max-width:calc(100vw - 24px)!important;
            padding:0!important;
            margin-top:10px!important;
            overflow:hidden!important;
            border:1px solid var(--fd-border)!important;
            border-radius:14px!important;
            background:#fff!important;
            box-shadow:0 18px 45px rgba(29,38,74,.14)!important;
        }

        .fieldplx-dropdown-header{
            min-height:48px!important;
            padding:11px 16px!important;
            display:flex!important;
            align-items:center!important;
            justify-content:space-between!important;
            border-bottom:1px solid var(--fd-border)!important;
            background:#fff!important;
        }

        .fieldplx-dropdown-title{
            margin:0!important;
            color:#111827!important;
            font-size:14px!important;
            font-weight:700!important;
        }

        #topbarNotificationList{
            max-height:300px!important;
            overflow-y:auto!important;
            background:#fff!important;
        }

        .fieldplx-notification-item{
            padding:11px 14px!important;
            display:flex!important;
            gap:10px!important;
            border-bottom:1px solid #f1f2f4!important;
            color:inherit!important;
            text-decoration:none!important;
        }

        .fieldplx-notification-item:hover,
        .fieldplx-notification-item.is-unread{
            background:#f8fbf3!important;
        }

        .fieldplx-notification-icon{
            width:32px!important;
            height:32px!important;
            flex:0 0 32px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:9px!important;
            color:var(--fd-green-dark)!important;
            background:var(--fd-green-soft)!important;
            font-size:14px!important;
        }

        .fieldplx-notification-content{
            min-width:0!important;
        }

        .fieldplx-notification-title{
            margin:0!important;
            color:#111827!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-notification-message{
            margin-top:3px!important;
            overflow:hidden!important;
            display:-webkit-box!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
            line-height:1.45!important;
            -webkit-line-clamp:2!important;
            -webkit-box-orient:vertical!important;
        }

        .fieldplx-notification-time{
            margin-top:4px!important;
            color:#9ca3af!important;
            font-size:9px!important;
        }

        .fieldplx-empty-notifications{
            min-height:155px!important;
            padding:28px 18px 24px!important;
            display:flex!important;
            flex-direction:column!important;
            align-items:center!important;
            justify-content:center!important;
            color:#718096!important;
            background:#fff!important;
            text-align:center!important;
            font-size:13px!important;
        }

        .fieldplx-empty-notifications i{
            margin-bottom:10px!important;
            color:#a9cf75!important;
            font-size:30px!important;
        }

        .fieldplx-dropdown-footer{
            min-height:44px!important;
            padding:10px 14px!important;
            display:flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-top:1px solid var(--fd-border)!important;
            background:#fff!important;
        }

        .fieldplx-dropdown-footer a{
            color:var(--fd-green-dark)!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-menu{
            width:230px!important;
            padding:7px!important;
            border:1px solid var(--fd-border)!important;
            border-radius:12px!important;
            background:#fff!important;
            box-shadow:0 18px 45px rgba(29,38,74,.14)!important;
        }

        .fieldplx-profile-menu-header{
            padding:9px 10px 11px!important;
            border-bottom:1px solid #f0f1f3!important;
        }

        .fieldplx-profile-menu-name{
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:#111827!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-menu-email{
            margin-top:2px!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
        }

        .fieldplx-profile-menu .dropdown-item{
            padding:9px 10px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border-radius:8px!important;
            color:#374151!important;
            background:transparent!important;
            font-size:11px!important;
        }

        .fieldplx-profile-menu .dropdown-item:hover{
            color:var(--fd-green-dark)!important;
            background:var(--fd-green-soft)!important;
        }

        /* ---------- Sidebar ---------- */
        .fieldplx-sidebar{
            width:var(--fieldplx-sidebar-width)!important;
            min-width:var(--fieldplx-sidebar-width)!important;
            height:100vh!important;
            position:fixed!important;
            top:0!important;
            left:0!important;
            z-index:1045!important;
            display:flex!important;
            flex-direction:column!important;
            color:#fff!important;
            background:linear-gradient(180deg,var(--fd-navy-light),var(--fd-navy))!important;
            border-right:0!important;
            transition:width .25s ease,min-width .25s ease,transform .25s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
            width:var(--fieldplx-sidebar-collapsed-width)!important;
            min-width:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-sidebar-header{
            min-height:68px!important;
            padding:9px 14px 10px!important;
            display:flex!important;
            align-items:center!important;
            border-bottom:1px solid rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-brand{
            min-width:0!important;
            display:flex!important;
            align-items:center!important;
            gap:10px!important;
            color:#fff!important;
        }

        .fieldplx-sidebar-logo,
        .fieldplx-sidebar-logo-placeholder{
            width:40px!important;
            height:40px!important;
            flex:0 0 40px!important;
            border-radius:10px!important;
        }

        .fieldplx-sidebar-logo{
            object-fit:contain!important;
        }

        .fieldplx-sidebar-logo-placeholder{
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            color:#fff!important;
            background:linear-gradient(135deg,#8fd236,#68aa1d)!important;
            font-size:18px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-brand-text{
            min-width:0!important;
            display:block!important;
        }

        .fieldplx-sidebar-company-name{
            max-width:155px!important;
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:#fff!important;
            font-size:16px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-product-name{
            margin-top:1px!important;
            display:block!important;
            color:#9fda55!important;
            font-size:9px!important;
            font-weight:600!important;
            letter-spacing:.4px!important;
            text-transform:uppercase!important;
        }

        .fieldplx-sidebar-close{
            width:32px!important;
            height:32px!important;
            margin-left:auto!important;
            padding:0!important;
            display:none!important;
            align-items:center!important;
            justify-content:center!important;
            border:0!important;
            border-radius:8px!important;
            color:rgba(255,255,255,.82)!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-body{
            min-height:0!important;
            flex:1 1 auto!important;
            overflow-y:auto!important;
            overflow-x:hidden!important;
            padding:12px 14px!important;
            scrollbar-width:none!important;
        }

        .fieldplx-sidebar-body::-webkit-scrollbar{
            display:none!important;
        }

        .fieldplx-sidebar-section-label{
            margin:7px 12px!important;
            color:rgba(255,255,255,.5)!important;
            font-size:9px!important;
            font-weight:700!important;
            letter-spacing:.65px!important;
            text-transform:uppercase!important;
        }

        .fieldplx-sidebar-nav{
            display:flex!important;
            flex-direction:column!important;
            gap:3px!important;
        }

        .fieldplx-sidebar-link{
            width:100%!important;
            min-height:46px!important;
            margin-bottom:3px!important;
            padding:0 14px!important;
            display:flex!important;
            align-items:center!important;
            gap:15px!important;
            border:0!important;
            border-radius:9px!important;
            color:rgba(255,255,255,.94)!important;
            background:transparent!important;
            text-align:left!important;
            font-family:inherit!important;
            font-size:14px!important;
            font-weight:600!important;
        }

        .fieldplx-sidebar-link:hover{
            color:#fff!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-link.active,
        .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link{
            color:#fff!important;
            background:linear-gradient(90deg,#7fc92d,#68aa1d)!important;
            box-shadow:0 6px 18px rgba(0,17,49,.28)!important;
        }

        .fieldplx-sidebar-link-icon{
            width:21px!important;
            height:21px!important;
            flex:0 0 21px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            font-size:19px!important;
        }

        .fieldplx-sidebar-link-text{
            min-width:0!important;
            flex:1!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-sidebar-arrow{
            margin-left:auto!important;
            color:rgba(255,255,255,.65)!important;
            font-size:10px!important;
            transition:transform .2s ease!important;
        }

        .fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow{
            transform:rotate(180deg)!important;
        }

        .fieldplx-sidebar-submenu{
            max-height:0!important;
            overflow:hidden!important;
            padding-left:36px!important;
            transition:max-height .25s ease,padding-top .25s ease,padding-bottom .25s ease!important;
        }

        .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu{
            max-height:680px!important;
            padding-top:4px!important;
            padding-bottom:5px!important;
        }

        .fieldplx-sidebar-sublink{
            min-height:34px!important;
            padding:7px 9px!important;
            display:flex!important;
            align-items:center!important;
            border-radius:7px!important;
            color:rgba(255,255,255,.72)!important;
            background:transparent!important;
            font-size:11px!important;
            font-weight:500!important;
        }

        .fieldplx-sidebar-sublink::before{
            width:5px!important;
            height:5px!important;
            margin-right:9px!important;
            flex:0 0 5px!important;
            content:""!important;
            border-radius:50%!important;
            background:rgba(255,255,255,.35)!important;
        }

        .fieldplx-sidebar-sublink:hover,
        .fieldplx-sidebar-sublink.active{
            color:#fff!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-sublink.active::before{
            background:#9fda55!important;
        }

        .fieldplx-sidebar-footer{
            flex:0 0 auto!important;
            padding:10px 14px 14px!important;
            border-top:1px solid rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-user{
            min-height:62px!important;
            padding:8px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border-radius:10px!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-user-avatar{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:50%!important;
            color:var(--fd-navy)!important;
            background:linear-gradient(135deg,#fff,#e8f3d9)!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-user-details{
            min-width:0!important;
            flex:1!important;
        }

        .fieldplx-sidebar-user-name,
        .fieldplx-sidebar-user-role{
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-sidebar-user-name{
            color:#fff!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-user-role{
            margin-top:1px!important;
            color:rgba(255,255,255,.6)!important;
            font-size:9px!important;
        }

        .fieldplx-sidebar-logout{
            width:29px!important;
            height:29px!important;
            flex:0 0 29px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:8px!important;
            color:rgba(255,255,255,.7)!important;
            font-size:14px!important;
        }

        .fieldplx-sidebar-logout:hover{
            color:#fff!important;
            background:rgba(228,91,102,.3)!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout{
            display:none!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user{
            justify-content:center!important;
        }

        /* ---------- Main content ---------- */
        .fieldplx-main-layout{
            display:block!important;
            min-height:calc(100vh - 70px)!important;
        }

        .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-width)!important;
            min-width:0!important;
            transition:margin-left .25s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-content-wrapper{
            padding:0!important;
        }

        /* ---------- Footer ---------- */
        .fieldplx-footer{
            min-height:52px!important;
            margin-left:var(--fieldplx-sidebar-width)!important;
            display:block!important;
            border-top:1px solid var(--fd-border)!important;
            background:#fff!important;
            transition:margin-left .22s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-footer{
            margin-left:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-footer-inner{
            min-height:52px!important;
            padding:10px 18px!important;
            display:flex!important;
            align-items:center!important;
            gap:18px!important;
            color:#6b7280!important;
            font-size:10px!important;
        }

        .fieldplx-footer-links{
            display:flex!important;
            align-items:center!important;
            gap:8px!important;
        }

        .fieldplx-footer-links a{
            color:#6b7280!important;
        }

        .fieldplx-footer-links a:hover,
        .fieldplx-footer-product strong{
            color:var(--fd-green-dark)!important;
        }

        .fieldplx-footer-separator{
            color:#d1d5db!important;
            font-size:8px!important;
        }

        .fieldplx-footer-product{
            margin-left:auto!important;
            white-space:nowrap!important;
            color:#9ca3af!important;
        }

        /* ---------- Mobile sidebar ---------- */
        .fieldplx-sidebar-overlay{
            display:none;
        }

        @media(max-width:991.98px){
            html,
            body{
                overflow-x:hidden!important;
            }

            body.fieldplx-sidebar-mobile-open{
                overflow:hidden!important;
            }

            .fieldplx-topbar,
            body.fieldplx-sidebar-collapsed .fieldplx-topbar{
                margin-left:0!important;
                width:100%!important;
            }

            .fieldplx-brand-mobile{
                display:flex!important;
            }

            .fieldplx-main-content,
            body.fieldplx-sidebar-collapsed .fieldplx-main-content{
                width:100%!important;
                margin-left:0!important;
            }

            .fieldplx-footer,
            body.fieldplx-sidebar-collapsed .fieldplx-footer{
                margin-left:0!important;
            }

            .fieldplx-sidebar,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                width:min(300px,calc(100vw - 52px))!important;
                min-width:0!important;
                max-width:300px!important;
                height:100vh!important;
                height:100dvh!important;
                position:fixed!important;
                top:0!important;
                bottom:0!important;
                left:0!important;
                z-index:1060!important;
                display:flex!important;
                flex-direction:column!important;
                overflow:hidden!important;
                visibility:hidden!important;
                transform:translate3d(-100%,0,0)!important;
                box-shadow:none!important;
                transition:transform .25s ease,visibility .25s ease!important;
            }

            body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
            body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                visibility:visible!important;
                transform:translate3d(0,0,0)!important;
            }

            .fieldplx-sidebar-close{
                display:inline-flex!important;
            }

            .fieldplx-sidebar-brand-text,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
            .fieldplx-sidebar-section-label,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
            .fieldplx-sidebar-link-text,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
            .fieldplx-sidebar-user-details,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details{
                display:block!important;
            }

            .fieldplx-sidebar-arrow,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
            .fieldplx-sidebar-logout,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout{
                display:inline-flex!important;
            }

            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user{
                justify-content:flex-start!important;
            }

            .fieldplx-sidebar-submenu,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu{
                display:block!important;
                max-height:0!important;
                overflow:hidden!important;
                padding-top:0!important;
                padding-bottom:0!important;
            }

            .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu{
                max-height:680px!important;
                padding-top:4px!important;
                padding-bottom:5px!important;
            }

            .fieldplx-sidebar-overlay{
                position:fixed!important;
                inset:0!important;
                z-index:1055!important;
                display:block!important;
                visibility:hidden!important;
                opacity:0!important;
                pointer-events:none!important;
                background:rgba(0,17,49,.48)!important;
                transition:opacity .25s ease,visibility .25s ease!important;
            }

            body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay{
                visibility:visible!important;
                opacity:1!important;
                pointer-events:auto!important;
            }
        }

        @media(max-width:767.98px){
            :root{
                --fieldplx-topbar-height:64px;
            }

            .fieldplx-topbar,
            .fieldplx-topbar-inner{
                min-height:64px!important;
            }

            .fieldplx-topbar-inner{
                padding:0 13px!important;
            }

            .fieldplx-search-wrap{
                display:none!important;
            }

            .fieldplx-profile-details{
                display:none!important;
            }

            .fieldplx-footer-inner{
                padding:12px!important;
                flex-wrap:wrap!important;
                justify-content:center!important;
                gap:7px 14px!important;
                text-align:center!important;
            }

            .fieldplx-footer-product{
                width:100%!important;
                margin-left:0!important;
            }
        }

        @media(max-width:575.98px){
            .fieldplx-sidebar,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                width:min(288px,calc(100vw - 44px))!important;
            }

            .fieldplx-sidebar-body{
                padding-left:10px!important;
                padding-right:10px!important;
            }

            .fieldplx-sidebar-link{
                min-height:43px!important;
                padding-left:12px!important;
                padding-right:12px!important;
                gap:12px!important;
                font-size:13px!important;
            }

            .fieldplx-sidebar-submenu{
                padding-left:31px!important;
            }
        }

        :root{
            --ji-navy:#001131;
            --ji-text:#0b2b37;
            --ji-muted:#5f7380;
            --ji-green:#2f8d25;
            --ji-green-dark:#24751d;
            --ji-green-soft:#f2f8ee;
            --ji-border:#dce4e8;
            --ji-soft:#f8fafb;
            --ji-danger:#d94841;
            --ji-yellow:#e7c832;
            --ji-blue:#446979;
        }
        *{box-sizing:border-box}
        html,body{margin:0;min-height:100%;overflow-x:hidden}
        body{background:#fff!important;color:var(--ji-text);font-family:Arial,Helvetica,sans-serif!important;font-size:14px}
        a,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}
        .fieldplx-content-wrapper{padding:0!important}

        /* Invoice-page content language */
        .ji-page{width:100%;max-width:none;margin:0;padding:24px 28px 42px;background:#fff;min-height:calc(100vh - 70px)}
        .ji-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:22px}
        .ji-title{margin:0;color:#0b2b37;font-size:24px;line-height:1.2;font-weight:700;letter-spacing:0}
        .ji-page-subtitle{margin:5px 0 0;color:#607782;font-size:12px;line-height:1.4}
        .ji-head-actions{position:relative;display:flex;align-items:center;gap:9px}
        .ji-btn{height:38px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid var(--ji-border);border-radius:7px;background:#fff;color:#31505d;font:700 14px Arial,Helvetica,sans-serif;cursor:pointer;white-space:nowrap}
        .ji-btn:hover{border-color:#b9c6cc;background:#fbfcfc;color:#173846}
        .ji-btn.primary{border-color:var(--ji-green);background:var(--ji-green);color:#fff}
        .ji-btn.primary:hover{background:var(--ji-green-dark);border-color:var(--ji-green-dark);color:#fff}

        .ji-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:26px}
        .ji-card{min-width:0;min-height:141px;padding:15px 15px 14px;border:1px solid var(--ji-border);border-radius:7px;background:#fff}
        .ji-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:9px}
        .ji-card-title{margin:0;color:#123845;font-size:15px;line-height:1.25;font-weight:700}
        .ji-card-sub{margin-top:2px;color:#607782;font-size:12px;line-height:1.3}
        .ji-card-corner{color:#355866;font-size:12px}
        .ji-overview-list{margin:9px 0 0;padding:0;list-style:none;display:grid;gap:5px}
        .ji-overview-item{display:grid;grid-template-columns:8px minmax(0,1fr) auto;gap:6px;align-items:center;color:#405d6a;font-size:12px;line-height:1.25}
        .ji-overview-item strong{color:#405d6a;font-size:12px;font-weight:400;text-align:right;white-space:nowrap}
        .ji-dot{width:7px;height:7px;border-radius:50%;background:#708793}
        .ji-dot.green{background:#3d9833}.ji-dot.red{background:#df5147}.ji-dot.blue{background:#4a6c79}
        .ji-metric-main{margin-top:24px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .ji-metric-value{color:#0b2b37;font-size:34px;line-height:.95;font-weight:700;letter-spacing:-1px}
        .ji-metric-foot{margin-top:5px;color:#627984;font-size:12px}

        .ji-list-heading{display:flex;align-items:baseline;gap:8px;margin:0 0 18px}
        .ji-list-heading h2{margin:0;color:#123845;font-size:19px;font-weight:700}
        .ji-result-count{color:#607782;font-size:13px;font-weight:400}
        .ji-toolbar{position:relative;display:flex;align-items:center;gap:8px;margin-bottom:10px;min-height:47px}
        .ji-filter-pill{height:38px;max-width:260px;padding:0 13px;display:inline-flex;align-items:center;gap:7px;border:0;border-radius:999px;background:#e9e8e4;color:#173846;font:700 13px Arial,Helvetica,sans-serif;cursor:pointer}
        .ji-filter-pill:hover{background:#deddd8}
        .ji-filter-pill i{font-size:17px}
        .ji-filter-divider{color:#6e7e85;font-weight:400}
        .ji-filter-value{max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ji-search{width:280px;margin-left:auto;position:relative}
        .ji-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:17px;color:#587381;pointer-events:none}
        .ji-search input{width:100%;height:45px;padding:0 13px 0 45px;border:1px solid var(--ji-border);border-radius:7px;background:#fff;color:#173846;outline:0;font-family:inherit;font-size:13px}
        .ji-search input:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}

        .ji-filter-pop{display:none;position:absolute;top:45px;z-index:1240;width:300px;padding:14px;border:1px solid var(--ji-border);border-radius:8px;background:#fff;box-shadow:0 12px 28px rgba(0,17,49,.14)}
        .ji-filter-pop.show{display:block}
        .ji-filter-pop.user{left:0;width:290px}
        .ji-filter-pop.activity{left:105px;width:335px}
        .ji-filter-pop.email{left:230px;width:275px}
        .ji-filter-pop.date{left:340px;width:320px}
        .ji-filter-label{display:block;margin:0 0 5px;color:#526b78;font-size:12px;font-weight:700}
        .ji-filter-pop select,.ji-filter-pop input{width:100%;height:39px;margin:0 0 10px;padding:7px 10px;border:1px solid var(--ji-border);border-radius:7px;background:#fff;color:#173846;font:14px Arial,Helvetica,sans-serif;outline:0}
        .ji-filter-pop select:focus,.ji-filter-pop input:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
        .ji-filter-pop-actions{display:flex;justify-content:flex-end;gap:7px;margin-top:3px}
        .ji-filter-pop-actions .ji-btn{height:34px;font-size:12px;padding:0 11px}

        .ji-table-wrap{width:100%;overflow:visible}
        .ji-table{width:100%;min-width:0;border-collapse:collapse;table-layout:fixed}
        .ji-table th{height:40px;padding:0 7px;border-bottom:1px solid #cfd9de;color:#46616e;background:#fff;font-size:11.5px;font-weight:400;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ji-table td{height:48px;padding:7px;border-bottom:1px solid #dde5e9;color:#314f5d;background:#fff;font-size:12px;vertical-align:middle;overflow:hidden;text-overflow:ellipsis}
        .ji-table tbody tr{transition:background .12s ease}
        .ji-table tbody tr:hover td{background:#f3f1ed}
        .ji-table th:nth-child(1){width:13%}.ji-table th:nth-child(2){width:14%}.ji-table th:nth-child(3){width:14%}.ji-table th:nth-child(4){width:14%}.ji-table th:nth-child(5){width:14%}.ji-table th:nth-child(6){width:11%}.ji-table th:nth-child(7){width:14%}.ji-table th:nth-child(8){width:6%;text-align:right}
        .ji-table td:nth-child(8){text-align:right}
        .ji-date{white-space:nowrap;color:#36515d}.ji-date small{display:block;margin-top:2px;color:#8999a1;font-size:10.5px}
        .ji-user{min-width:0}.ji-user-name{display:block;color:#173744;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ji-user-sub{display:block;margin-top:2px;color:#85969e;font-size:10.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ji-module strong{display:block;color:#294956;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ji-module small{display:block;margin-top:3px;color:#81939c;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ji-record strong{display:block;color:#294956;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ji-record small{display:block;color:#8999a1;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ji-device span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ji-device small{display:block;margin-top:3px;color:#8999a1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ji-badge{display:inline-flex;align-items:center;gap:6px;min-height:23px;max-width:100%;padding:3px 9px;border-radius:999px;background:#edf1f2;color:#52717f;font-size:11px;font-weight:400;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ji-badge:before{content:'';width:7px;height:7px;flex:0 0 7px;border-radius:50%;background:currentColor}
        .ji-badge.create,.ji-badge.success,.ji-badge.login{color:#3b8a33;background:#e7f2e4}
        .ji-badge.update,.ji-badge.edit,.ji-badge.change{color:#3f718b;background:#eaf2f7}
        .ji-badge.delete,.ji-badge.failed,.ji-badge.denied,.ji-badge.error{color:#bc4941;background:#fae8e6}
        .ji-badge.payment,.ji-badge.sent,.ji-badge.email{color:#8b7410;background:#f8f0c8}
        .ji-badge.logout{color:#687b84;background:#eef1f2}
        .ji-view{width:31px;height:31px;padding:0;display:inline-grid;place-items:center;border:1px solid #d7e0e4;border-radius:7px;background:#fff;color:#214555;cursor:pointer;font-size:15px}
        .ji-view:hover{background:#f8fbf6;color:var(--ji-green-dark)}
        .ji-empty{height:150px!important;text-align:center!important;color:#71858f!important;font-size:13px!important}

        .ji-footer{min-height:48px;padding:11px 0 0;display:flex;align-items:center;justify-content:space-between;gap:12px;color:#6d818c;font-size:12px}
        .ji-pagination{display:flex;align-items:center;gap:6px}
        .ji-page-btn{height:32px;min-width:32px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--ji-border);border-radius:6px;background:#fff;color:#31505d;cursor:pointer}
        .ji-page-btn.active{border-color:var(--ji-green);background:var(--ji-green);color:#fff}
        .ji-page-btn.disabled{opacity:.45;pointer-events:none}
        .ji-per-page{height:34px;padding:5px 9px;border:1px solid var(--ji-border);border-radius:7px;background:#fff;color:#405b67;font-size:12px;outline:0}.ji-per-page:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}

        .ji-modal-backdrop{display:none;position:fixed;inset:0;z-index:13000;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.33)}
        .ji-modal-backdrop.show{display:flex}
        .ji-modal{width:min(900px,calc(100vw - 30px));max-height:calc(100vh - 36px);overflow:auto;border:1px solid #d8e0e4;border-radius:10px;background:#fff;box-shadow:0 22px 60px rgba(0,17,49,.23)}
        .ji-modal-head{padding:18px 20px 14px;display:flex;align-items:center;justify-content:space-between;gap:15px;border-bottom:1px solid var(--ji-border);background:#fff}
        .ji-modal-head h3{margin:0;color:#123845;font-size:22px;font-weight:700}
        .ji-modal-close{width:34px;height:34px;padding:0;border:0;border-radius:7px;background:transparent;color:#274c5b;font-size:20px;cursor:pointer}.ji-modal-close:hover{background:#f2f5f5}
        .ji-modal-body{padding:18px 20px 20px}
        .ji-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px}
        .ji-detail{padding:10px;border:1px solid #e5eaed;border-radius:7px;background:#fbfcfc;min-width:0}
        .ji-detail label{display:block;margin-bottom:4px;color:#83949c;font-size:10.5px}.ji-detail span{display:block;color:#2a4855;font-size:12.5px;word-break:break-word}
        .ji-change-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ji-change-title{margin:0 0 7px;color:#526b76;font-size:12px}.ji-code{min-height:180px;max-height:360px;overflow:auto;margin:0;padding:12px;border:1px solid #dde5e9;border-radius:7px;background:#f8fafb;color:#2d4855;font:12px/1.5 Consolas,Monaco,monospace;white-space:pre-wrap;word-break:break-word}

        @media(max-width:1199.98px){.ji-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.ji-filter-pop.activity,.ji-filter-pop.email,.ji-filter-pop.date{left:0}}
        @media(max-width:991.98px){.ji-page{padding:20px 16px 36px}.ji-head{align-items:flex-start}.ji-toolbar{align-items:flex-start;flex-wrap:wrap}.ji-search{width:100%;order:-1;margin-left:0}.ji-filter-pop.user,.ji-filter-pop.activity,.ji-filter-pop.email,.ji-filter-pop.date{left:0;top:92px;width:min(335px,calc(100vw - 32px))}.ji-detail-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:767.98px){.ji-page{padding:17px 13px 32px}.ji-title{font-size:24px}.ji-head{flex-direction:column}.ji-head-actions{width:100%}.ji-head-actions>.ji-btn{flex:1}.ji-metrics{grid-template-columns:1fr}.ji-filter-pill{max-width:100%}.ji-change-grid{grid-template-columns:1fr}.ji-detail-grid{grid-template-columns:1fr 1fr}.ji-footer{align-items:flex-start;flex-direction:column}.ji-table thead{display:none}.ji-table,.ji-table tbody,.ji-table tr,.ji-table td{display:block;width:100%}.ji-table tbody tr{position:relative;display:grid;grid-template-columns:1fr 1fr;padding:9px 48px 9px 10px;border-bottom:1px solid #dde5e9}.ji-table td{min-height:36px;padding:4px 7px;border-bottom:0;white-space:normal;overflow:visible}.ji-table td:before{content:attr(data-label);display:block;margin-bottom:2px;color:#748690;font-size:10px}.ji-table td:last-child{position:absolute;right:8px;top:8px;width:34px!important;padding:0!important}.ji-user-name,.ji-user-sub,.ji-module strong,.ji-module small,.ji-record strong,.ji-record small,.ji-device span,.ji-device small{white-space:normal}.ji-modal-head h3{font-size:19px}}
        @media(max-width:520px){.ji-detail-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/toast.php'; ?>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="ji-page">
                <header class="ji-head">
                    <div>
                        <h1 class="ji-title">Audit Trail</h1>
                        <p class="ji-page-subtitle">Track user activity, record changes, invoice/payment emails, access details, and system actions.</p>
                    </div>
                    <div class="ji-head-actions">
                        <a class="ji-btn" href="<?php echo atH($exportUrl); ?>"><i class="bi bi-download"></i> Export CSV</a>
                    </div>
                </header>

                <section class="ji-metrics" aria-label="Audit overview">
                    <article class="ji-card">
                        <div class="ji-card-title">Overview</div>
                        <ul class="ji-overview-list">
                            <li class="ji-overview-item"><span class="ji-dot blue"></span><span>Total audit events</span><strong><?php echo number_format($summary['total']); ?></strong></li>
                            <li class="ji-overview-item"><span class="ji-dot green"></span><span>Email sent</span><strong><?php echo number_format($summary['email_sent']); ?></strong></li>
                            <li class="ji-overview-item"><span class="ji-dot red"></span><span>Email failed</span><strong><?php echo number_format($summary['email_failed']); ?></strong></li>
                        </ul>
                    </article>
                    <article class="ji-card">
                        <div class="ji-card-head"><div><div class="ji-card-title">Today</div><div class="ji-card-sub">Audit activity</div></div><span class="ji-card-corner"><i class="bi bi-arrow-up-right"></i></span></div>
                        <div class="ji-metric-main"><strong class="ji-metric-value"><?php echo number_format($summary['today']); ?></strong></div>
                        <div class="ji-metric-foot">Events recorded today</div>
                    </article>
                    <article class="ji-card">
                        <div class="ji-card-head"><div><div class="ji-card-title">Last 7 days</div><div class="ji-card-sub">Audit activity</div></div></div>
                        <div class="ji-metric-main"><strong class="ji-metric-value"><?php echo number_format($summary['week']); ?></strong></div>
                        <div class="ji-metric-foot">Events in the last week</div>
                    </article>
                    <article class="ji-card">
                        <div class="ji-card-head"><div><div class="ji-card-title">Users</div><div class="ji-card-sub">Audit history</div></div><span class="ji-card-corner"><i class="bi bi-people"></i></span></div>
                        <div class="ji-metric-main"><strong class="ji-metric-value"><?php echo number_format($summary['users']); ?></strong></div>
                        <div class="ji-metric-foot">Users with recorded activity</div>
                    </article>
                </section>

                <section>
                    <div class="ji-list-heading"><h2>All audit events</h2><span class="ji-result-count">(<?php echo number_format($totalRows); ?> result<?php echo $totalRows === 1 ? '' : 's'; ?>)</span></div>

                    <form method="get" id="auditFilterForm">
                        <input type="hidden" name="page" id="filterPage" value="1">
                        <input type="hidden" name="per_page" value="<?php echo (int)$perPage; ?>">
                        <div class="ji-toolbar">
                            <button type="button" class="ji-filter-pill" data-popover="userPopover"><i class="bi bi-person"></i><span>User</span><span class="ji-filter-divider">|</span><span class="ji-filter-value"><?php echo atH($userFilterLabel); ?></span></button>
                            <button type="button" class="ji-filter-pill" data-popover="activityPopover"><span>Activity</span><span class="ji-filter-divider">|</span><span class="ji-filter-value"><?php echo atH($activityFilterLabel); ?></span></button>
                            <button type="button" class="ji-filter-pill" data-popover="emailPopover"><i class="bi bi-envelope"></i><span>Email</span><span class="ji-filter-divider">|</span><span class="ji-filter-value"><?php echo atH($emailFilterLabel); ?></span></button>
                            <button type="button" class="ji-filter-pill" data-popover="datePopover"><i class="bi bi-calendar2"></i><span>Date</span><span class="ji-filter-divider">|</span><span class="ji-filter-value"><?php echo atH($dateFilterLabel); ?></span></button>

                            <div class="ji-filter-pop user" id="userPopover">
                                <label class="ji-filter-label" for="userFilter">User</label>
                                <select name="user_id" id="userFilter">
                                    <option value="0">All users</option>
                                    <?php foreach ($users as $u): $uName = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']); ?>
                                        <option value="<?php echo (int)$u['id']; ?>" <?php echo $userId === (int)$u['id'] ? 'selected' : ''; ?>><?php echo atH($uName !== '' ? $uName : $u['email']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="ji-filter-pop-actions"><button type="button" class="ji-btn" data-clear="user">Clear</button><button type="submit" class="ji-btn primary">Apply</button></div>
                            </div>

                            <div class="ji-filter-pop activity" id="activityPopover">
                                <label class="ji-filter-label" for="moduleFilter">Module</label>
                                <select name="module" id="moduleFilter"><option value="">All modules</option><?php foreach ($modules as $v): ?><option value="<?php echo atH($v); ?>" <?php echo $module === $v ? 'selected' : ''; ?>><?php echo atH($v); ?></option><?php endforeach; ?></select>
                                <label class="ji-filter-label" for="categoryFilter">Category</label>
                                <select name="category" id="categoryFilter"><option value="">All categories</option><?php foreach ($categories as $v): ?><option value="<?php echo atH($v); ?>" <?php echo $category === $v ? 'selected' : ''; ?>><?php echo atH(atPretty($v)); ?></option><?php endforeach; ?></select>
                                <label class="ji-filter-label" for="actionFilter">Action</label>
                                <select name="action" id="actionFilter"><option value="">All actions</option><?php foreach ($actions as $v): ?><option value="<?php echo atH($v); ?>" <?php echo $action === $v ? 'selected' : ''; ?>><?php echo atH(atPretty($v)); ?></option><?php endforeach; ?></select>
                                <label class="ji-filter-label" for="objectFilter">Record type</label>
                                <select name="object_type" id="objectFilter"><option value="">All record types</option><?php foreach ($objectTypes as $v): ?><option value="<?php echo atH($v); ?>" <?php echo $objectType === $v ? 'selected' : ''; ?>><?php echo atH(atPretty($v)); ?></option><?php endforeach; ?></select>
                                <label class="ji-filter-label" for="branchFilter">Branch</label>
                                <select name="branch_id" id="branchFilter">
                                    <option value="0">All branches</option>
                                    <?php foreach ($branches as $b): ?><option value="<?php echo (int)$b['id']; ?>" <?php echo $branchId === (int)$b['id'] ? 'selected' : ''; ?>><?php echo atH($b['name']); ?></option><?php endforeach; ?>
                                </select>
                                <div class="ji-filter-pop-actions"><button type="button" class="ji-btn" data-clear="activity">Clear</button><button type="submit" class="ji-btn primary">Apply</button></div>
                            </div>

                            <div class="ji-filter-pop email" id="emailPopover">
                                <label class="ji-filter-label" for="emailStatusFilter">Email status</label>
                                <select name="email_status" id="emailStatusFilter">
                                    <option value="" <?php echo $emailStatus === '' ? 'selected' : ''; ?>>All</option>
                                    <option value="activity" <?php echo $emailStatus === 'activity' ? 'selected' : ''; ?>>All email activity</option>
                                    <option value="sent" <?php echo $emailStatus === 'sent' ? 'selected' : ''; ?>>Sent successfully</option>
                                    <option value="failed" <?php echo $emailStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                </select>
                                <div class="ji-filter-pop-actions"><button type="button" class="ji-btn" data-clear="email">Clear</button><button type="submit" class="ji-btn primary">Apply</button></div>
                            </div>

                            <div class="ji-filter-pop date" id="datePopover">
                                <label class="ji-filter-label" for="dateFrom">From</label><input type="date" name="date_from" id="dateFrom" value="<?php echo atH($dateFrom); ?>">
                                <label class="ji-filter-label" for="dateTo">To</label><input type="date" name="date_to" id="dateTo" value="<?php echo atH($dateTo); ?>">
                                <div class="ji-filter-pop-actions"><button type="button" class="ji-btn" data-clear="date">Clear</button><button type="submit" class="ji-btn primary">Apply</button></div>
                            </div>

                            <div class="ji-search"><i class="bi bi-search"></i><input type="search" name="q" id="auditSearch" value="<?php echo atH($q); ?>" placeholder="Search audit trail..."></div>
                        </div>
                    </form>

                    <div class="ji-table-wrap">
                        <?php if (!$rows): ?>
                            <table class="ji-table"><tbody><tr><td colspan="8" class="ji-empty">No audit trail records match the selected filters.</td></tr></tbody></table>
                        <?php else: ?>
                        <table class="ji-table">
                            <thead><tr>
                                <th>Date &amp; Time</th><th>User</th><th>Module / Category</th><th>Action</th><th>Record</th><th>Branch</th><th>IP / Device</th><th aria-label="Details"></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row):
                                $actorName = trim((string)$row['user_name']);
                                if ($actorName === '') $actorName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
                                if ($actorName === '') $actorName = !empty($row['platform_user_id']) ? 'Platform User #' . (int)$row['platform_user_id'] : 'System';
                                $actionLower = strtolower((string)$row['action']);
                                $badgeClass = '';
                                if (strpos($actionLower, 'email_failed') !== false || strpos($actionLower, 'email_error') !== false || strpos($actionLower, 'fail') !== false || strpos($actionLower, 'denied') !== false || strpos($actionLower, 'delete') !== false) $badgeClass = 'failed';
                                elseif (strpos($actionLower, 'email_sent') !== false || strpos($actionLower, 'email_success') !== false || strpos($actionLower, 'sent') !== false) $badgeClass = 'sent';
                                elseif (strpos($actionLower, 'create') !== false || strpos($actionLower, 'login') !== false || strpos($actionLower, 'success') !== false) $badgeClass = 'create';
                                elseif (strpos($actionLower, 'update') !== false || strpos($actionLower, 'edit') !== false || strpos($actionLower, 'change') !== false) $badgeClass = 'update';
                                elseif (strpos($actionLower, 'payment') !== false) $badgeClass = 'payment';
                                elseif (strpos($actionLower, 'logout') !== false) $badgeClass = 'logout';
                                $datePart = $row['created_at'] ? date('d M Y', strtotime($row['created_at'])) : '-';
                                $timePart = $row['created_at'] ? date('h:i:s A', strtotime($row['created_at'])) : '';
                            ?>
                                <tr>
                                    <td data-label="Date & Time" class="ji-date"><?php echo atH($datePart); ?><small><?php echo atH($timePart); ?></small></td>
                                    <td data-label="User" class="ji-user"><span class="ji-user-name"><?php echo atH($actorName); ?></span><span class="ji-user-sub"><?php echo !empty($row['actor_email']) ? atH($row['actor_email']) : ('User ID: ' . ($row['user_id'] !== null ? (int)$row['user_id'] : '-')); ?></span></td>
                                    <td data-label="Module / Category" class="ji-module"><strong><?php echo atH($row['module'] !== null && $row['module'] !== '' ? $row['module'] : '-'); ?></strong><small><?php echo atH(atPretty($row['audit_category'])); ?></small></td>
                                    <td data-label="Action"><span class="ji-badge <?php echo atH($badgeClass); ?>" title="<?php echo atH(atPretty($row['action'])); ?>"><?php echo atH(atPretty($row['action'])); ?></span></td>
                                    <td data-label="Record" class="ji-record"><strong><?php echo atH($row['record_no'] !== null && $row['record_no'] !== '' ? $row['record_no'] : atPretty($row['object_type'])); ?></strong><small><?php echo atH(atPretty($row['object_type'])); ?><?php echo $row['object_id'] !== null ? ' #' . (int)$row['object_id'] : ''; ?></small></td>
                                    <td data-label="Branch"><?php echo !empty($row['branch_name']) ? atH($row['branch_name']) : '<span style="color:#91a0a7">-</span>'; ?></td>
                                    <td data-label="IP / Device" class="ji-device"><span><?php echo atH($row['ip_address'] !== null && $row['ip_address'] !== '' ? $row['ip_address'] : '-'); ?></span><small><?php echo atH($row['device_type'] !== null && $row['device_type'] !== '' ? atPretty($row['device_type']) : '-'); ?></small></td>
                                    <td data-label="Details"><button type="button" class="ji-view" data-audit-id="<?php echo (int)$row['id']; ?>" title="View audit details"><i class="bi bi-eye"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <div class="ji-footer">
                        <div style="display:flex;align-items:center;gap:10px">
                            <span><?php echo $totalRows > 0 ? 'Showing ' . number_format($offset + 1) . '-' . number_format(min($offset + $perPage, $totalRows)) . ' of ' . number_format($totalRows) . ' events' : 'Showing 0 events'; ?></span>
                            <form method="get" id="perPageForm">
                                <?php foreach ($_GET as $k => $v): if (in_array($k, array('page','per_page','export'), true) || is_array($v)) continue; ?>
                                    <input type="hidden" name="<?php echo atH($k); ?>" value="<?php echo atH($v); ?>">
                                <?php endforeach; ?>
                                <select class="ji-per-page" name="per_page" onchange="this.form.submit()">
                                    <?php foreach (array(10,25,50,100) as $n): ?><option value="<?php echo $n; ?>" <?php echo $perPage === $n ? 'selected' : ''; ?>><?php echo $n; ?> rows</option><?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <div class="ji-pagination">
                            <a class="ji-page-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page > 1 ? atH(atPageUrl($page - 1)) : '#'; ?>"><i class="bi bi-chevron-left"></i></a>
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            if ($startPage > 1) echo '<a class="ji-page-btn" href="' . atH(atPageUrl(1)) . '">1</a>';
                            if ($startPage > 2) echo '<span>...</span>';
                            for ($p = $startPage; $p <= $endPage; $p++) {
                                if ($p === $page) echo '<span class="ji-page-btn active">' . $p . '</span>';
                                else echo '<a class="ji-page-btn" href="' . atH(atPageUrl($p)) . '">' . $p . '</a>';
                            }
                            if ($endPage < $totalPages - 1) echo '<span>...</span>';
                            if ($endPage < $totalPages) echo '<a class="ji-page-btn" href="' . atH(atPageUrl($totalPages)) . '">' . $totalPages . '</a>';
                            ?>
                            <a class="ji-page-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo $page < $totalPages ? atH(atPageUrl($page + 1)) : '#'; ?>"><i class="bi bi-chevron-right"></i></a>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<div class="ji-modal-backdrop" id="auditModal" aria-hidden="true">
    <section class="ji-modal" role="dialog" aria-modal="true" aria-labelledby="auditModalTitle">
        <div class="ji-modal-head"><h3 id="auditModalTitle">Audit Details</h3><button type="button" class="ji-modal-close" id="auditModalClose" aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
        <div class="ji-modal-body">
            <div class="ji-detail-grid">
                <div class="ji-detail"><label>Date &amp; Time</label><span id="adDate">-</span></div>
                <div class="ji-detail"><label>User</label><span id="adActor">-</span></div>
                <div class="ji-detail"><label>Module</label><span id="adModule">-</span></div>
                <div class="ji-detail"><label>Category</label><span id="adCategory">-</span></div>
                <div class="ji-detail"><label>Action</label><span id="adAction">-</span></div>
                <div class="ji-detail"><label>Record</label><span id="adRecord">-</span></div>
                <div class="ji-detail"><label>Branch</label><span id="adBranch">-</span></div>
                <div class="ji-detail"><label>IP / Device</label><span id="adDevice">-</span></div>
                <div class="ji-detail" style="grid-column:1/-1"><label>Location</label><span id="adLocation">-</span></div>
                <div class="ji-detail" style="grid-column:1/-1"><label>User Agent</label><span id="adUserAgent">-</span></div>
            </div>
            <div class="ji-change-grid">
                <div><div class="ji-change-title">Old values</div><pre class="ji-code" id="adOld">No previous values recorded.</pre></div>
                <div><div class="ji-change-title">New values</div><pre class="ji-code" id="adNew">No new values recorded.</pre></div>
            </div>
        </div>
    </section>
</div>

<script>
window.FIELDPLX_AUDIT_DETAILS = <?php echo json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
(function(){
    'use strict';
    function E(id){return document.getElementById(id)}
    function closePopovers(){document.querySelectorAll('.ji-filter-pop.show').forEach(function(p){p.classList.remove('show')})}
    document.querySelectorAll('[data-popover]').forEach(function(btn){
        btn.addEventListener('click',function(e){
            e.stopPropagation();
            var id=this.getAttribute('data-popover'),pop=E(id),show=pop&&!pop.classList.contains('show');
            closePopovers();
            if(show)pop.classList.add('show');
        });
    });
    document.querySelectorAll('.ji-filter-pop').forEach(function(pop){pop.addEventListener('click',function(e){e.stopPropagation()})});
    document.addEventListener('click',closePopovers);

    var form=E('auditFilterForm');
    document.querySelectorAll('[data-clear]').forEach(function(btn){
        btn.addEventListener('click',function(){
            var type=this.getAttribute('data-clear');
            if(type==='user') E('userFilter').value='0';
            if(type==='activity'){
                E('moduleFilter').value='';E('categoryFilter').value='';E('actionFilter').value='';E('objectFilter').value='';E('branchFilter').value='0';
            }
            if(type==='email') E('emailStatusFilter').value='';
            if(type==='date'){E('dateFrom').value='';E('dateTo').value=''}
            E('filterPage').value='1';
            form.submit();
        });
    });
    if(form) form.addEventListener('submit',function(){E('filterPage').value='1'});

    var search=E('auditSearch'),searchTimer=null,lastSearch=search?search.value:'';
    if(search){
        search.addEventListener('input',function(){
            var value=this.value;
            if(searchTimer)window.clearTimeout(searchTimer);
            searchTimer=window.setTimeout(function(){
                if(value!==lastSearch){lastSearch=value;E('filterPage').value='1';form.submit()}
            },450);
        });
    }

    var modal=E('auditModal'),closeBtn=E('auditModalClose');
    function text(id,value){var el=E(id);if(el)el.textContent=(value===null||value===undefined||value==='')?'-':String(value)}
    function pretty(value){return String(value||'').replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase()})}
    function jsonText(value,empty){if(value===null||value===undefined||value==='')return empty;try{return typeof value==='string'?value:JSON.stringify(value,null,2)}catch(e){return String(value)}}
    function openAudit(id){
        var d=window.FIELDPLX_AUDIT_DETAILS[String(id)];if(!d)return;
        text('adDate',d.created_at);
        text('adActor',d.actor+(d.actor_email?' ('+d.actor_email+')':''));
        text('adModule',d.module||'-');
        text('adCategory',pretty(d.category));
        text('adAction',pretty(d.action));
        text('adRecord',(d.record_no||pretty(d.object_type))+(d.object_id!==null&&d.object_id!==undefined?' #'+d.object_id:''));
        text('adBranch',d.branch||'-');
        text('adDevice',(d.ip_address||'-')+' / '+pretty(d.device_type||'-'));
        var loc=d.location||'';
        if(d.latitude!==null&&d.latitude!==undefined&&d.latitude!=='')loc+=(loc?' | ':'')+d.latitude+', '+d.longitude+(d.accuracy?' (±'+d.accuracy+'m)':'');
        text('adLocation',loc||'-');
        text('adUserAgent',d.user_agent||'-');
        text('adOld',jsonText(d.old_values,'No previous values recorded.'));
        text('adNew',jsonText(d.new_values,'No new values recorded.'));
        modal.classList.add('show');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';
    }
    function closeAudit(){modal.classList.remove('show');modal.setAttribute('aria-hidden','true');document.body.style.overflow=''}
    document.addEventListener('click',function(e){var btn=e.target.closest('[data-audit-id]');if(btn){e.stopPropagation();openAudit(btn.getAttribute('data-audit-id'));return}if(e.target===modal)closeAudit()});
    if(closeBtn)closeBtn.addEventListener('click',closeAudit);
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){closePopovers();if(modal.classList.contains('show'))closeAudit()}});
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
