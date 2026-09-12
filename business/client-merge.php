<?php
/* FieldPlx Client Merge Page - Version 1.1.0 - Invoice/Add Invoice UI */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Merge Clients';
$activePage = 'clients';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['client_merge_csrf_token'])) {
    $_SESSION['client_merge_csrf_token'] = bin2hex(random_bytes(32));
}
$clientMergeCsrfToken = (string)$_SESSION['client_merge_csrf_token'];
$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);

function cmpTableExists(PDO $pdo, $table)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n");
    $q->execute(array(':n' => $table));
    return (int)$q->fetchColumn() > 0;
}

function cmpCanView(PDO $pdo, $tenantId, $userId)
{
    if ($tenantId <= 0 || $userId <= 0) {
        return false;
    }
    if (!cmpTableExists($pdo, 'users')) {
        return false;
    }
    $roleJoin = cmpTableExists($pdo, 'roles') ? "LEFT JOIN roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id" : '';
    $roleAdmin = cmpTableExists($pdo, 'roles') ? 'COALESCE(r.is_admin,0)' : '0';
    $q = $pdo->prepare("SELECT u.role_id, u.is_tenant_admin, $roleAdmin AS role_admin FROM users u $roleJoin WHERE u.id=:u AND u.tenant_id=:t AND u.deleted_at IS NULL LIMIT 1");
    $q->execute(array(':u' => $userId, ':t' => $tenantId));
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return false;
    }
    if ((int)$u['is_tenant_admin'] === 1 || (int)$u['role_admin'] === 1) {
        return true;
    }
    if (!cmpTableExists($pdo, 'permissions')) {
        return false;
    }
    $q = $pdo->prepare("SELECT id FROM permissions WHERE permission_code='clients.view' LIMIT 1");
    $q->execute();
    $permissionId = (int)$q->fetchColumn();
    if ($permissionId <= 0) {
        return false;
    }
    if (cmpTableExists($pdo, 'user_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':u' => $userId, ':p' => $permissionId));
        $a = $q->fetchColumn();
        if ($a !== false) {
            return $a === 'allow';
        }
    }
    $roleId = (int)($u['role_id'] ?? 0);
    if ($roleId > 0 && cmpTableExists($pdo, 'role_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':r' => $roleId, ':p' => $permissionId));
        return $q->fetchColumn() === 'allow';
    }
    return false;
}

if (!cmpCanView($pdo, $tenantId, $userId)) {
    http_response_code(403);
    exit('You do not have permission to view client merge.');
}

$initialIds = array();
if (!empty($_GET['client_ids'])) {
    foreach (explode(',', (string)$_GET['client_ids']) as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $initialIds[$id] = $id;
        }
    }
}
$initialIds = array_values($initialIds);
$initialPrimaryId = isset($_GET['primary_id']) ? (int)$_GET['primary_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Merge Clients - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <style>


        /* ==========================================================
           FieldPlx canonical tenant shell
           Same shell used by Customers / Jobs / Quotations pages.
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


        
        /* ==========================================================
           FieldPlx Client Merge - Invoice/Add Invoice UI v1.1.0
           Uses the canonical Invoice v3.1.0 tenant shell.
           ========================================================== */
        :root{
            --cm-navy:#001131;
            --cm-text:#0b2b37;
            --cm-muted:#5f7380;
            --cm-green:#2f8d25;
            --cm-green-dark:#24751d;
            --cm-green-soft:#f2f8ee;
            --cm-border:#dce4e8;
            --cm-soft:#f8fafb;
            --cm-danger:#d94841;
            --cm-warning:#9a741a;
        }

        body{
            background:#fff!important;
            color:var(--cm-text);
            font-family:Arial,Helvetica,sans-serif!important;
            font-size:14px;
        }

        .cm-page{
            width:100%;
            max-width:none;
            min-height:calc(100vh - 70px);
            margin:0;
            padding:24px 22px 112px;
            background:#fff;
        }

        .cm-head{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:18px;
            margin-bottom:22px;
        }
        .cm-head-copy{min-width:0}
        .cm-title{
            margin:0;
            color:#0b2b37;
            font-size:31px;
            line-height:1.1;
            font-weight:700;
            letter-spacing:-.7px;
        }
        .cm-subtitle{
            max-width:820px;
            margin:8px 0 0;
            color:#607782;
            font-size:13px;
            line-height:1.5;
        }
        .cm-head-actions{
            display:flex;
            align-items:center;
            gap:9px;
            flex:0 0 auto;
        }

        .cm-btn{
            height:38px;
            min-height:38px;
            padding:0 14px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            border:1px solid var(--cm-border);
            border-radius:7px;
            background:#fff;
            color:#31505d;
            font:700 14px Arial,Helvetica,sans-serif;
            text-decoration:none!important;
            cursor:pointer;
            white-space:nowrap;
            box-shadow:none;
        }
        .cm-btn:hover{
            border-color:#b9c6cc;
            background:#fbfcfc;
            color:#173846;
        }
        .cm-btn.primary{
            border-color:var(--cm-green);
            background:var(--cm-green);
            color:#fff;
        }
        .cm-btn.primary:hover{
            border-color:var(--cm-green-dark);
            background:var(--cm-green-dark);
            color:#fff;
        }
        .cm-btn.danger{
            border-color:var(--cm-danger);
            background:var(--cm-danger);
            color:#fff;
        }
        .cm-btn:disabled{opacity:.55;cursor:not-allowed}

        .cm-help{
            min-height:62px;
            padding:13px 15px;
            display:flex;
            align-items:flex-start;
            gap:10px;
            border:1px solid #d9e5d3;
            border-radius:7px;
            background:#f8fbf6;
            color:#405d6a;
            font-size:12px;
            line-height:1.5;
        }
        .cm-help i{
            margin-top:1px;
            color:var(--cm-green-dark);
            font-size:17px;
        }
        .cm-help strong{color:#173846}

        .cm-grid{
            display:grid;
            grid-template-columns:minmax(0,1.65fr) minmax(320px,.85fr);
            gap:14px;
            margin-top:14px;
            align-items:start;
        }
        .cm-card{
            min-width:0;
            border:1px solid var(--cm-border);
            border-radius:7px;
            background:#fff;
            box-shadow:none;
        }
        .cm-selected-card{
            position:sticky;
            top:84px;
        }
        .cm-card-head{
            padding:15px 15px 12px;
            border-bottom:1px solid #e5ecef;
        }
        .cm-card-head h2{
            margin:0;
            color:#123845;
            font-size:17px;
            line-height:1.25;
            font-weight:700;
        }
        .cm-card-head p{
            margin:4px 0 0;
            color:#607782;
            font-size:12px;
            line-height:1.4;
        }

        .cm-search-wrap{
            position:relative;
            margin:13px 14px 10px;
        }
        .cm-search-wrap i{
            position:absolute;
            left:14px;
            top:50%;
            transform:translateY(-50%);
            color:#587381;
            font-size:17px;
            pointer-events:none;
        }
        .cm-search{
            width:100%;
            height:45px;
            padding:0 13px 0 43px;
            border:1px solid var(--cm-border);
            border-radius:7px;
            background:#fff;
            color:#173846;
            outline:0;
            font:13px Arial,Helvetica,sans-serif;
        }
        .cm-search:focus{
            border-color:#91bd7e;
            box-shadow:0 0 0 2px rgba(47,141,37,.08);
        }
        .cm-results-meta{
            padding:0 15px 9px;
            color:#71858f;
            font-size:11px;
        }
        .cm-results{
            max-height:520px;
            overflow-y:auto;
            overflow-x:hidden;
            border-top:1px solid #edf1f3;
            scrollbar-width:thin;
            scrollbar-color:#cad5da transparent;
        }
        .cm-results::-webkit-scrollbar{width:7px}
        .cm-results::-webkit-scrollbar-thumb{border-radius:999px;background:#cad5da}
        .cm-result{
            min-height:78px;
            padding:11px 14px;
            display:grid;
            grid-template-columns:22px minmax(0,1fr) auto;
            gap:10px;
            align-items:flex-start;
            border-bottom:1px solid #e3eaed;
            background:#fff;
            cursor:pointer;
            transition:background .12s ease;
        }
        .cm-result:hover{background:#f3f1ed}
        .cm-result.selected{background:#f4f9ef}
        .cm-result input[type=checkbox]{
            width:17px;
            height:17px;
            margin:2px 0 0;
            accent-color:var(--cm-green);
        }
        .cm-result-name{
            overflow:hidden;
            color:#123845;
            font-size:13px;
            font-weight:700;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .cm-result-company{
            margin-top:2px;
            overflow:hidden;
            color:#6b7f89;
            font-size:11px;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .cm-result-contact,
        .cm-result-address{
            margin-top:5px;
            display:flex;
            flex-wrap:wrap;
            gap:5px 12px;
            color:#526b78;
            font-size:11px;
            line-height:1.3;
        }
        .cm-result-contact span{display:inline-flex;align-items:center;gap:5px;min-width:0}
        .cm-result-address{color:#71858f}

        .cm-status{
            min-height:23px;
            padding:3px 9px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            border-radius:999px;
            background:#e7f2e4;
            color:#3b8a33;
            font-size:11px;
            font-weight:400;
            text-transform:capitalize;
            white-space:nowrap;
        }
        .cm-status:before{
            width:7px;
            height:7px;
            border-radius:50%;
            background:currentColor;
            content:'';
        }
        .cm-status.new,
        .cm-status.lead{
            color:#8b7410;
            background:#f8f0c8;
        }
        .cm-status.inactive,
        .cm-status.archived{
            color:#687b84;
            background:#eef1f2;
        }
        .cm-empty{
            min-height:120px;
            padding:28px 18px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:#71858f;
            font-size:12px;
            text-align:center;
        }

        .cm-selected-body{padding:13px 14px 15px}
        .cm-selected-count{
            margin-bottom:9px;
            color:#607782;
            font-size:11px;
        }
        .cm-selected-item{
            min-height:55px;
            padding:8px 9px;
            display:grid;
            grid-template-columns:19px minmax(0,1fr) 28px;
            gap:8px;
            align-items:center;
            border:1px solid #e1e8eb;
            border-radius:7px;
            background:#fff;
        }
        .cm-selected-item + .cm-selected-item{margin-top:7px}
        .cm-selected-item.primary{
            border-color:#b8d3aa;
            background:#f4f9ef;
        }
        .cm-selected-radio{
            width:17px;
            height:17px;
            margin:0;
            accent-color:var(--cm-green);
        }
        .cm-selected-name{
            overflow:hidden;
            color:#173846;
            font-size:12px;
            font-weight:700;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .cm-selected-small{
            margin-top:2px;
            overflow:hidden;
            color:#82919a;
            font-size:10px;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .cm-remove{
            width:28px;
            height:28px;
            padding:0;
            display:grid;
            place-items:center;
            border:0;
            border-radius:6px;
            background:transparent;
            color:#71858f;
            cursor:pointer;
        }
        .cm-remove:hover{background:#fff0ef;color:var(--cm-danger)}
        .cm-primary-note{
            margin-top:11px;
            padding:9px 10px;
            border-radius:7px;
            background:#f5f7f8;
            color:#607782;
            font-size:10.5px;
            line-height:1.45;
        }
        .cm-primary-note strong{color:#294b59}

        .cm-preview{
            display:none;
            margin-top:14px;
        }
        .cm-preview.show{display:block}
        .cm-preview-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:14px;
        }
        .cm-preview-card{
            padding:14px 15px;
            border:1px solid var(--cm-border);
            border-radius:7px;
            background:#fff;
        }
        .cm-preview-card h3{
            margin:0 0 10px;
            color:#123845;
            font-size:15px;
            font-weight:700;
        }
        .cm-counts{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:7px;
        }
        .cm-count{
            min-height:53px;
            padding:9px 10px;
            border:1px solid #e5ecef;
            border-radius:7px;
            background:#fbfcfc;
        }
        .cm-count strong{
            display:block;
            color:#0b2b37;
            font-size:18px;
            line-height:1.1;
        }
        .cm-count span{
            display:block;
            margin-top:3px;
            color:#71858f;
            font-size:10px;
        }
        .cm-fill-list{display:grid;gap:7px}
        .cm-fill{
            padding:8px 9px;
            border:1px solid #edf1f3;
            border-radius:7px;
            background:#fbfcfc;
        }
        .cm-fill strong{
            display:block;
            color:#607782;
            font-size:10px;
        }
        .cm-fill span{
            display:block;
            margin-top:2px;
            color:#173846;
            font-size:12px;
        }
        .cm-fill small{
            display:block;
            margin-top:2px;
            color:#8a9aa2;
            font-size:9px;
        }

        .cm-sticky{
            position:fixed;
            left:var(--fieldplx-sidebar-width);
            right:0;
            bottom:0;
            z-index:1025;
            min-height:70px;
            padding:10px 22px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:14px;
            border-top:1px solid var(--cm-border);
            background:rgba(255,255,255,.98);
            box-shadow:0 -4px 14px rgba(0,17,49,.035);
            transition:left .25s ease;
        }
        body.fieldplx-sidebar-collapsed .cm-sticky{left:var(--fieldplx-sidebar-collapsed-width)}
        .cm-sticky-note{
            color:#607782;
            font-size:11px;
        }
        .cm-actions{display:flex;align-items:center;gap:8px}
        .cm-loader{
            width:13px;
            height:13px;
            display:none;
            border:2px dotted currentColor;
            border-radius:50%;
            animation:cmSpin .75s linear infinite;
        }
        .cm-btn.loading .cm-loader{display:inline-block}
        @keyframes cmSpin{to{transform:rotate(360deg)}}

        .cm-modal-backdrop{
            display:none;
            position:fixed;
            inset:0;
            z-index:13000;
            align-items:center;
            justify-content:center;
            padding:18px;
            background:rgba(0,17,49,.33);
        }
        .cm-modal-backdrop.show{display:flex}
        .cm-modal{
            width:min(540px,calc(100vw - 30px));
            max-height:calc(100vh - 36px);
            overflow:auto;
            border:1px solid #d8e0e4;
            border-radius:10px;
            background:#fff;
            box-shadow:0 22px 60px rgba(0,17,49,.23);
        }
        .cm-modal-head{
            padding:20px 22px 12px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:15px;
        }
        .cm-modal-head h3{
            margin:0;
            color:#123845;
            font-size:22px;
            font-weight:700;
        }
        .cm-modal-close{
            width:34px;
            height:34px;
            padding:0;
            border:0;
            border-radius:7px;
            background:transparent;
            color:#274c5b;
            font-size:20px;
            cursor:pointer;
        }
        .cm-modal-close:hover{background:#f2f5f5}
        .cm-modal-body{
            padding:10px 22px 12px;
            color:#405d6a;
            font-size:13px;
            line-height:1.55;
        }
        .cm-warning{
            margin-top:13px;
            padding:11px 12px;
            border-radius:7px;
            background:#fff8dc;
            color:#654e08;
        }
        .cm-modal-footer{
            padding:12px 22px 20px;
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:8px;
        }

        .cm-toast{
            position:fixed;
            top:82px;
            right:18px;
            z-index:14000;
            width:min(390px,calc(100vw - 36px));
            padding:12px 14px;
            display:flex;
            align-items:center;
            gap:9px;
            border-radius:8px;
            background:#1f5f7a;
            color:#fff;
            box-shadow:0 12px 30px rgba(0,17,49,.18);
            opacity:0;
            transform:translateY(-8px);
            pointer-events:none;
            transition:.18s;
            font-size:13px;
            font-weight:700;
        }
        .cm-toast.show{opacity:1;transform:translateY(0);pointer-events:auto}
        .cm-toast.success{background:#2f8d25}
        .cm-toast.error{background:#c94f55}
        .cm-toast.warning{background:#9a741a}
        .cm-toast.info{background:#1f5f7a}
        .cm-toast span{min-width:0;flex:1}
        .cm-toast button{border:0;background:transparent;color:#fff;cursor:pointer}

        @media(max-width:1199.98px){
            .cm-grid{grid-template-columns:minmax(0,1.35fr) minmax(300px,.85fr)}
        }
        @media(max-width:991.98px){
            .cm-page{padding:20px 16px 110px}
            .cm-grid{grid-template-columns:1fr}
            .cm-selected-card{position:static}
            .cm-sticky,
            body.fieldplx-sidebar-collapsed .cm-sticky{left:0}
        }
        @media(max-width:767.98px){
            .cm-page{padding:17px 13px 120px}
            .cm-head{flex-direction:column}
            .cm-head-actions{width:100%}
            .cm-head-actions .cm-btn{width:100%}
            .cm-title{font-size:27px}
            .cm-preview-grid{grid-template-columns:1fr}
            .cm-sticky{
                min-height:96px;
                padding:10px 13px;
                align-items:stretch;
                flex-direction:column;
            }
            .cm-sticky-note{text-align:center}
            .cm-actions{width:100%}
            .cm-actions .cm-btn{flex:1}
            .cm-modal-head h3{font-size:19px}
        }
        @media(max-width:575.98px){
            .cm-result{grid-template-columns:21px minmax(0,1fr)}
            .cm-result>.cm-status{grid-column:2;justify-self:start}
            .cm-counts{grid-template-columns:1fr}
            .cm-toast{top:72px;left:12px;right:12px;width:auto}
            .cm-modal-footer{flex-direction:column-reverse}
            .cm-modal-footer .cm-btn{width:100%}
        }

    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="cm-page">
                <header class="cm-head">
                    <div class="cm-head-copy">
                        <h1 class="cm-title">Merge Clients</h1>
                        <p class="cm-subtitle">Combine duplicate client profiles into one accurate record. Select at least two clients, choose which profile to keep, then review the work and billing records that will move.</p>
                    </div>
                    <div class="cm-head-actions">
                        <a href="clients.php" class="cm-btn"><i class="bi bi-arrow-left"></i> Back to Clients</a>
                    </div>
                </header>

                <div class="cm-help">
                    <i class="bi bi-info-circle"></i>
                    <div><strong>The profile you keep is never deleted.</strong> Existing values on that profile stay in place. Blank profile fields can be filled from the duplicate records, linked work moves to the kept client, and the duplicate client profiles are archived after the merge.</div>
                </div>

                <div class="cm-grid">
                    <section class="cm-card">
                        <div class="cm-card-head">
                            <h2>Select duplicate clients</h2>
                            <p>Search by client name, company, phone, email, or property address. You can merge up to 10 profiles at once.</p>
                        </div>
                        <div class="cm-search-wrap">
                            <i class="bi bi-search"></i>
                            <input class="cm-search" type="search" id="clientSearch" placeholder="Search clients..." autocomplete="off" />
                        </div>
                        <div class="cm-results-meta" id="resultsMeta">Loading clients...</div>
                        <div class="cm-results" id="clientResults">
                            <div class="cm-empty">Loading clients...</div>
                        </div>
                    </section>

                    <section class="cm-card cm-selected-card">
                        <div class="cm-card-head">
                            <h2>Clients to merge</h2>
                            <p>Choose the one profile that should remain after the merge.</p>
                        </div>
                        <div class="cm-selected-body">
                            <div class="cm-selected-count" id="selectedCount">0 clients selected</div>
                            <div id="selectedList"><div class="cm-empty">Select at least two client profiles.</div></div>
                            <div class="cm-primary-note">The selected <strong>Keep this profile</strong> client controls the final name and existing profile values. Linked locations, jobs, quotes, invoices, payments, contacts, and other related records are reassigned to it.</div>
                        </div>
                    </section>
                </div>

                <section class="cm-preview" id="mergePreview">
                    <div class="cm-preview-grid">
                        <article class="cm-preview-card">
                            <h3>Records that will move</h3>
                            <div class="cm-counts" id="linkedCounts"></div>
                        </article>
                        <article class="cm-preview-card">
                            <h3>Blank profile details that will be filled</h3>
                            <div class="cm-fill-list" id="profileFill"></div>
                        </article>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<div class="cm-sticky">
    <div class="cm-sticky-note" id="stickyNote">Select at least two clients to continue.</div>
    <div class="cm-actions">
        <a class="cm-btn" href="clients.php">Cancel</a>
        <button type="button" class="cm-btn primary" id="mergeButton" disabled><span class="cm-loader"></span><i class="bi bi-intersect"></i> Merge Clients</button>
    </div>
</div>

<div class="cm-modal-backdrop" id="confirmBackdrop" aria-hidden="true">
    <section class="cm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <div class="cm-modal-head">
            <h3 id="confirmTitle">Merge selected clients?</h3>
            <button type="button" class="cm-modal-close" id="confirmClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="cm-modal-body">
            <div id="confirmText">The duplicate profiles will be merged into the selected primary client.</div>
            <div class="cm-warning"><strong>This action changes linked records.</strong> Jobs, requests, quotes, invoices, payments, locations, contacts, and other client-linked records will point to the profile you keep. Duplicate client profiles are soft-archived for history.</div>
        </div>
        <div class="cm-modal-footer">
            <button type="button" class="cm-btn" id="confirmCancel">Cancel</button>
            <button type="button" class="cm-btn primary" id="confirmMerge"><span class="cm-loader"></span>Merge Clients</button>
        </div>
    </section>
</div>

<div class="cm-toast info" id="mergeToast"><i class="bi bi-info-circle"></i><span id="mergeToastMessage">Notification</span><button type="button" id="mergeToastClose"><i class="bi bi-x-lg"></i></button></div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    var csrfToken = <?= json_encode($clientMergeCsrfToken) ?>;
    var initialIds = <?= json_encode($initialIds) ?>;
    var initialPrimaryId = <?= (int)$initialPrimaryId ?>;
    var state = {
        rows: [],
        rowMap: {},
        selected: {},
        primaryId: initialPrimaryId || 0,
        canMerge: false,
        previewTimer: null,
        searchTimer: null,
        toastTimer: null
    };

    var results = document.getElementById('clientResults');
    var resultsMeta = document.getElementById('resultsMeta');
    var searchInput = document.getElementById('clientSearch');
    var selectedList = document.getElementById('selectedList');
    var selectedCount = document.getElementById('selectedCount');
    var mergePreview = document.getElementById('mergePreview');
    var linkedCounts = document.getElementById('linkedCounts');
    var profileFill = document.getElementById('profileFill');
    var stickyNote = document.getElementById('stickyNote');
    var mergeButton = document.getElementById('mergeButton');
    var confirmBackdrop = document.getElementById('confirmBackdrop');
    var confirmMerge = document.getElementById('confirmMerge');
    var toast = document.getElementById('mergeToast');
    var toastMessage = document.getElementById('mergeToastMessage');

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function parseResponse(response) {
        return response.text().then(function (raw) {
            var text = (raw || '').trim();
            var data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                var clean = text.replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                throw new Error(clean ? 'Server error: ' + clean : 'Server returned an invalid response.');
            }
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Request failed.');
            }
            return data;
        });
    }

    function request(data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (key) {
            fd.append(key, data[key]);
        });
        fd.append('csrf_token', csrfToken);
        return fetch('api/client-merge.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(parseResponse);
    }

    function showToast(type, message) {
        if (state.toastTimer) clearTimeout(state.toastTimer);
        toast.className = 'cm-toast ' + (type || 'info') + ' show';
        toastMessage.textContent = message || 'Notification';
        state.toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 3500);
    }

    function setLoading(button, on) {
        if (!button) return;
        button.classList.toggle('loading', !!on);
        button.disabled = !!on;
    }

    function selectedIds() {
        return Object.keys(state.selected).map(function (id) { return Number(id); }).filter(function (id) { return id > 0; });
    }

    function clientSecondary(row) {
        return row.company_name || [row.first_name || '', row.last_name || ''].join(' ').trim() || row.email || row.phone || '';
    }

    function renderResults() {
        var rows = state.rows || [];
        resultsMeta.textContent = rows.length + (rows.length === 1 ? ' client' : ' clients') + ' found';
        if (!rows.length) {
            results.innerHTML = '<div class="cm-empty">No matching clients found.</div>';
            return;
        }
        var html = '';
        rows.forEach(function (row) {
            var id = Number(row.id || 0);
            var checked = !!state.selected[id];
            html += '<label class="cm-result' + (checked ? ' selected' : '') + '" data-id="' + id + '">' +
                '<input type="checkbox" data-select-id="' + id + '" ' + (checked ? 'checked' : '') + '>' +
                '<div>' +
                    '<div class="cm-result-name">' + esc(row.display_name || 'Unnamed client') + '</div>' +
                    '<div class="cm-result-company">' + esc(clientSecondary(row)) + '</div>' +
                    '<div class="cm-result-contact">' +
                        (row.email ? '<span><i class="bi bi-envelope"></i>' + esc(row.email) + '</span>' : '') +
                        (row.phone ? '<span><i class="bi bi-telephone"></i>' + esc(row.phone) + '</span>' : '') +
                    '</div>' +
                    (row.address ? '<div class="cm-result-address"><i class="bi bi-geo-alt"></i> ' + esc(row.address) + '</div>' : '') +
                '</div>' +
                '<span class="cm-status ' + esc(row.status || '') + '">' + esc(row.status || row.client_type || 'active') + '</span>' +
            '</label>';
        });
        results.innerHTML = html;
    }

    function ensurePrimary() {
        var ids = selectedIds();
        if (!ids.length) {
            state.primaryId = 0;
            return;
        }
        if (ids.indexOf(Number(state.primaryId)) === -1) {
            state.primaryId = ids[0];
        }
    }

    function renderSelected() {
        ensurePrimary();
        var ids = selectedIds();
        selectedCount.textContent = ids.length + (ids.length === 1 ? ' client selected' : ' clients selected');
        if (!ids.length) {
            selectedList.innerHTML = '<div class="cm-empty">Select at least two client profiles.</div>';
            updateActionState();
            return;
        }
        var html = '';
        ids.forEach(function (id) {
            var row = state.selected[id];
            var primary = Number(state.primaryId) === Number(id);
            html += '<div class="cm-selected-item' + (primary ? ' primary' : '') + '">' +
                '<input class="cm-selected-radio" type="radio" name="primaryClient" data-primary-id="' + id + '" ' + (primary ? 'checked' : '') + ' title="Keep this profile">' +
                '<div><div class="cm-selected-name">' + esc(row.display_name || 'Unnamed client') + '</div><div class="cm-selected-small">' + esc(row.email || row.phone || clientSecondary(row) || 'No contact information') + '</div></div>' +
                '<button class="cm-remove" type="button" data-remove-id="' + id + '" title="Remove"><i class="bi bi-x-lg"></i></button>' +
            '</div>';
        });
        selectedList.innerHTML = html;
        updateActionState();
        schedulePreview();
    }

    function updateActionState() {
        var ids = selectedIds();
        var ready = state.canMerge && ids.length >= 2 && state.primaryId > 0;
        mergeButton.disabled = !ready;
        if (!state.canMerge) {
            stickyNote.textContent = 'Your role does not have both Update and Delete customer permissions required to merge.';
        } else if (ids.length < 2) {
            stickyNote.textContent = 'Select at least two clients to continue.';
        } else {
            var primary = state.selected[state.primaryId];
            stickyNote.textContent = (ids.length - 1) + ' duplicate ' + ((ids.length - 1) === 1 ? 'profile' : 'profiles') + ' will be merged into ' + (primary ? primary.display_name : 'the kept client') + '.';
        }
    }

    function renderPreview(data) {
        var counts = data.linked_counts || [];
        var fills = data.profile_fill || [];
        if (!counts.length) {
            linkedCounts.innerHTML = '<div class="cm-empty" style="grid-column:1/-1">No linked work records found on the duplicate profiles.</div>';
        } else {
            linkedCounts.innerHTML = counts.map(function (row) {
                return '<div class="cm-count"><strong>' + Number(row.count || 0) + '</strong><span>' + esc(row.label) + '</span></div>';
            }).join('');
        }
        if (!fills.length) {
            profileFill.innerHTML = '<div class="cm-empty">The kept client already has values for the mergeable profile fields. Existing values will be preserved.</div>';
        } else {
            profileFill.innerHTML = fills.map(function (row) {
                return '<div class="cm-fill"><strong>' + esc(row.label) + '</strong><span>' + esc(row.value) + '</span><small>From ' + esc(row.source_name) + '</small></div>';
            }).join('');
        }
        mergePreview.classList.add('show');
    }

    function schedulePreview() {
        if (state.previewTimer) clearTimeout(state.previewTimer);
        var ids = selectedIds();
        if (ids.length < 2 || !state.primaryId || !state.canMerge) {
            mergePreview.classList.remove('show');
            return;
        }
        state.previewTimer = setTimeout(function () {
            request({
                action: 'preview',
                client_ids: JSON.stringify(ids),
                primary_id: state.primaryId
            }).then(renderPreview).catch(function (e) {
                mergePreview.classList.remove('show');
                showToast('error', e.message);
            });
        }, 180);
    }

    function loadClients(search) {
        results.innerHTML = '<div class="cm-empty">Loading clients...</div>';
        request({ action: 'list', search: search || '' }).then(function (data) {
            state.rows = data.clients || [];
            state.rowMap = {};
            state.rows.forEach(function (row) {
                state.rowMap[Number(row.id)] = row;
            });
            state.canMerge = !!(data.permissions && data.permissions.can_merge);
            initialIds.forEach(function (id) {
                if (state.rowMap[id]) state.selected[id] = state.rowMap[id];
            });
            if (initialPrimaryId && state.selected[initialPrimaryId]) state.primaryId = initialPrimaryId;
            renderResults();
            renderSelected();
        }).catch(function (e) {
            results.innerHTML = '<div class="cm-empty">' + esc(e.message) + '</div>';
            showToast('error', e.message);
        });
    }

    results.addEventListener('change', function (e) {
        var box = e.target.closest('[data-select-id]');
        if (!box) return;
        var id = Number(box.getAttribute('data-select-id'));
        if (box.checked) {
            if (selectedIds().length >= 10 && !state.selected[id]) {
                box.checked = false;
                showToast('warning', 'You can merge up to 10 clients at one time.');
                return;
            }
            if (state.rowMap[id]) state.selected[id] = state.rowMap[id];
        } else {
            delete state.selected[id];
        }
        ensurePrimary();
        renderResults();
        renderSelected();
    });

    selectedList.addEventListener('change', function (e) {
        var radio = e.target.closest('[data-primary-id]');
        if (!radio) return;
        state.primaryId = Number(radio.getAttribute('data-primary-id'));
        renderSelected();
    });

    selectedList.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-remove-id]');
        if (!remove) return;
        var id = Number(remove.getAttribute('data-remove-id'));
        delete state.selected[id];
        ensurePrimary();
        renderResults();
        renderSelected();
    });

    searchInput.addEventListener('input', function () {
        if (state.searchTimer) clearTimeout(state.searchTimer);
        var value = this.value.trim();
        state.searchTimer = setTimeout(function () { loadClients(value); }, 250);
    });

    function openConfirm() {
        var ids = selectedIds();
        if (!state.canMerge || ids.length < 2 || !state.primaryId) return;
        var primary = state.selected[state.primaryId];
        document.getElementById('confirmText').innerHTML = '<strong>' + esc(primary ? primary.display_name : 'Selected client') + '</strong> will be kept. ' + (ids.length - 1) + ' duplicate ' + ((ids.length - 1) === 1 ? 'client' : 'clients') + ' will be merged into this profile.';
        confirmBackdrop.classList.add('show');
        confirmBackdrop.setAttribute('aria-hidden', 'false');
    }

    function closeConfirm() {
        confirmBackdrop.classList.remove('show');
        confirmBackdrop.setAttribute('aria-hidden', 'true');
    }

    function performMerge() {
        var ids = selectedIds();
        if (!state.canMerge || ids.length < 2 || !state.primaryId) return;
        setLoading(confirmMerge, true);
        request({
            action: 'merge',
            client_ids: JSON.stringify(ids),
            primary_id: state.primaryId
        }).then(function (data) {
            showToast('success', data.message || 'Clients merged successfully.');
            closeConfirm();
            window.setTimeout(function () {
                window.location.href = data.redirect || ('client-view.php?client_id=' + encodeURIComponent(state.primaryId));
            }, 650);
        }).catch(function (e) {
            showToast('error', e.message);
        }).finally(function () {
            setLoading(confirmMerge, false);
        });
    }

    mergeButton.addEventListener('click', openConfirm);
    confirmMerge.addEventListener('click', performMerge);
    document.getElementById('confirmClose').addEventListener('click', closeConfirm);
    document.getElementById('confirmCancel').addEventListener('click', closeConfirm);
    confirmBackdrop.addEventListener('click', function (e) { if (e.target === confirmBackdrop) closeConfirm(); });
    document.getElementById('mergeToastClose').addEventListener('click', function () { toast.classList.remove('show'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeConfirm(); });

    loadClients('');
})();
</script>
</body>
</html>
