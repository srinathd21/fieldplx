<?php
/* FieldPlx Invoices Manage Page - Version 3.1.0 - 2026-09-07 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle='Invoices';
$activePage='invoices';

if(session_status()===PHP_SESSION_NONE){
    session_start();
}

/*
 * Invoice permission resolver.
 * Mirrors FieldPlx effective access: plan -> tenant override -> role -> user override.
 * A user-specific allow/deny wins over the role value; missing grants are denied.
 */
function jiTableExists(PDO $pdo,$table){
    static $cache=array();
    if(array_key_exists($table,$cache)) return $cache[$table];
    try{
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
        $q->execute(array(':t'=>$table));
        return $cache[$table]=((int)$q->fetchColumn()>0);
    }catch(Throwable $e){return $cache[$table]=false;}
}
function jiInvoicePermission(PDO $pdo,$action){
    $action=strtolower(trim((string)$action));
    if(!in_array($action,array('view','create','update','delete','approve','export'),true)) return false;
    if((function_exists('isPlatformSuperAdmin') && isPlatformSuperAdmin()) || !empty($_SESSION['is_super_admin'])) return true;

    $tenantId=isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:0;
    $userId=isset($_SESSION['tenant_user_id'])?(int)$_SESSION['tenant_user_id']:(isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:0);
    $roleId=isset($_SESSION['role_id'])?(int)$_SESSION['role_id']:0;
    $planId=isset($_SESSION['plan_id'])?(int)$_SESSION['plan_id']:0;
    if($tenantId<=0 || $userId<=0 || $roleId<=0) return false;
    if(!jiTableExists($pdo,'modules') || !jiTableExists($pdo,'permissions') || !jiTableExists($pdo,'role_permissions')) return false;

    try{
        $m=$pdo->prepare("SELECT id FROM modules WHERE module_code='invoices' AND is_active=1 LIMIT 1");
        $m->execute();
        $moduleId=(int)$m->fetchColumn();
        if($moduleId<=0) return false;

        if(jiTableExists($pdo,'plan_modules')){
            if($planId<=0) return false;
            $pm=$pdo->prepare("SELECT COUNT(*) FROM plan_modules WHERE plan_id=:p AND module_id=:m AND is_enabled=1");
            $pm->execute(array(':p'=>$planId,':m'=>$moduleId));
            if((int)$pm->fetchColumn()<=0) return false;
        }
        if(jiTableExists($pdo,'tenant_modules')){
            $tm=$pdo->prepare("SELECT access_type FROM tenant_modules WHERE tenant_id=:t AND module_id=:m LIMIT 1");
            $tm->execute(array(':t'=>$tenantId,':m'=>$moduleId));
            if(strtolower(trim((string)$tm->fetchColumn()))==='disabled') return false;
        }

        $ps=$pdo->prepare("SELECT id FROM permissions WHERE module_id=:m AND (action_code=:a OR permission_code=:c) ORDER BY CASE WHEN permission_code=:c2 THEN 0 ELSE 1 END,id LIMIT 1");
        $ps->execute(array(':m'=>$moduleId,':a'=>$action,':c'=>'invoices.'.$action,':c2'=>'invoices.'.$action));
        $permissionId=(int)$ps->fetchColumn();
        if($permissionId<=0) return false;

        $rp=$pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $rp->execute(array(':t'=>$tenantId,':r'=>$roleId,':p'=>$permissionId));
        $roleAccess=strtolower(trim((string)$rp->fetchColumn()));
        $effective=$roleAccess;

        if(jiTableExists($pdo,'user_permissions')){
            $up=$pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
            $up->execute(array(':t'=>$tenantId,':u'=>$userId,':p'=>$permissionId));
            $userAccess=strtolower(trim((string)$up->fetchColumn()));
            if($userAccess!=='') $effective=$userAccess;
        }
        return $effective==='allow';
    }catch(Throwable $e){
        error_log('FieldPlx invoice page permission check: '.$e->getMessage());
        return false;
    }
}

$jiCanView=jiInvoicePermission($pdo,'view');
$jiCanCreate=jiInvoicePermission($pdo,'create');
$jiCanUpdate=jiInvoicePermission($pdo,'update');
$jiCanDelete=jiInvoicePermission($pdo,'delete');
if(!$jiCanView){
    http_response_code(403);
    exit('Access denied. Your role does not have permission to view invoices.');
}

if(empty($_SESSION['invoices_csrf_token'])){
    $_SESSION['invoices_csrf_token']=bin2hex(random_bytes(32));
}

$invoiceCsrfToken=(string)$_SESSION['invoices_csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Invoices - FieldPlx</title>
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
           FieldPlx Invoices - Jobber-style list UI v3.1.0
           Uses the same visual language as Add Invoice v2.2.0.
           ========================================================== */
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
        body{background:#fff!important;color:var(--ji-text);font-family:Arial,Helvetica,sans-serif!important;font-size:14px}
        .ji-page{width:100%;max-width:none;margin:0;padding:24px 22px 42px;background:#fff;min-height:calc(100vh - 70px)}
        .ji-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:25px}
        .ji-title{margin:0;color:#0b2b37;font-size:31px;line-height:1.1;font-weight:700;letter-spacing:-.7px}
        .ji-head-actions{position:relative;display:flex;align-items:center;gap:9px}
        .ji-btn{height:38px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid var(--ji-border);border-radius:7px;background:#fff;color:#31505d;font:700 14px Arial,Helvetica,sans-serif;cursor:pointer;white-space:nowrap}
        .ji-btn:hover{border-color:#b9c6cc;background:#fbfcfc;color:#173846}
        .ji-btn.primary{border-color:var(--ji-green);background:var(--ji-green);color:#fff}
        .ji-btn.primary:hover{background:var(--ji-green-dark);border-color:var(--ji-green-dark);color:#fff}
        .ji-btn.danger{border-color:var(--ji-danger);background:var(--ji-danger);color:#fff}
        .ji-btn.danger:hover{background:#c43e38;border-color:#c43e38;color:#fff}
        .ji-btn:disabled{opacity:.55;cursor:not-allowed}
        .ji-more-menu{display:none;position:absolute;right:0;top:45px;z-index:1220;width:182px;padding:7px 0;border:1px solid var(--ji-border);border-radius:8px;background:#fff;box-shadow:0 10px 25px rgba(0,17,49,.14)}
        .ji-more-menu.show{display:block}
        .ji-more-menu button,.ji-more-menu a{width:100%;min-height:44px;padding:9px 14px;display:flex;align-items:center;gap:10px;border:0;background:#fff;color:#294755;text-align:left;font:700 13px Arial,Helvetica,sans-serif;cursor:pointer}
        .ji-more-menu button:hover,.ji-more-menu a:hover{background:#f6f8f8;color:var(--ji-green-dark)}
        .ji-more-menu i{font-size:18px;color:#315967}

        .ji-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:26px}
        .ji-card{min-width:0;min-height:141px;padding:15px 15px 14px;border:1px solid var(--ji-border);border-radius:7px;background:#fff}
        .ji-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:9px}
        .ji-card-title{margin:0;color:#123845;font-size:15px;line-height:1.25;font-weight:700}
        .ji-card-sub{margin-top:2px;color:#607782;font-size:12px;line-height:1.3}
        .ji-card-corner{color:#355866;font-size:12px}
        .ji-overview-list{margin:9px 0 0;padding:0;list-style:none;display:grid;gap:4px}
        .ji-overview-item{display:grid;grid-template-columns:8px minmax(0,1fr) auto;gap:6px;align-items:center;color:#405d6a;font-size:12px;line-height:1.25}
        .ji-overview-item strong{color:#405d6a;font-size:12px;font-weight:400;text-align:right;white-space:nowrap}
        .ji-dot{width:7px;height:7px;border-radius:50%;background:#708793}
        .ji-dot.red{background:#df5147}.ji-dot.yellow{background:#e3bf24}.ji-dot.blue{background:#4a6c79}
        .ji-metric-main{margin-top:24px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .ji-metric-value{color:#0b2b37;font-size:34px;line-height:.95;font-weight:700;letter-spacing:-1px}
        .ji-metric-value.money{font-size:31px}
        .ji-trend{display:inline-flex;align-items:center;min-height:25px;padding:3px 8px;border-radius:999px;background:#eff6e8;color:#4e7a2d;font-size:12px;line-height:1;font-weight:700}
        .ji-trend.flat{background:#eef3f5;color:#617784}
        .ji-trend.down{background:#fff0ef;color:#a94b43}
        .ji-metric-foot{margin-top:5px;color:#627984;font-size:12px}
        .ji-trend-wrap{position:relative;display:inline-flex;align-items:center;outline:0}
        .ji-trend-wrap:focus-visible{border-radius:999px;box-shadow:0 0 0 3px rgba(47,141,37,.12)}
        .ji-stat-pop{position:absolute;left:50%;bottom:calc(100% + 12px);z-index:1300;width:max-content;min-width:185px;max-width:260px;padding:12px 14px;border:1px solid #d7e0e4;border-radius:8px;background:#fff;box-shadow:0 7px 22px rgba(0,17,49,.16);opacity:0;visibility:hidden;transform:translate(-50%,6px);transition:opacity .14s ease,transform .14s ease,visibility .14s ease;pointer-events:none;color:#304f5d}
        .ji-stat-pop:after{content:'';position:absolute;left:50%;bottom:-7px;width:13px;height:13px;background:#fff;border-right:1px solid #d7e0e4;border-bottom:1px solid #d7e0e4;transform:translateX(-50%) rotate(45deg)}
        .ji-trend-wrap:hover .ji-stat-pop,.ji-trend-wrap:focus .ji-stat-pop,.ji-trend-wrap:focus-within .ji-stat-pop{opacity:1;visibility:visible;transform:translate(-50%,0)}
        .ji-stat-pop-title{margin-bottom:6px;color:#71848e;font-size:12px;font-weight:400;line-height:1.25}
        .ji-stat-pop-row{display:grid;grid-template-columns:minmax(105px,1fr) auto;align-items:center;gap:10px;margin-top:3px;font-size:12px;font-weight:700;line-height:1.3;white-space:nowrap}
        .ji-stat-pop-row span:first-child{color:#294b59}.ji-stat-pop-row strong{color:#294b59;font-weight:700;text-align:right}
        
        .ji-list-heading{display:flex;align-items:baseline;gap:8px;margin:0 0 18px}
        .ji-list-heading h2{margin:0;color:#123845;font-size:19px;font-weight:700}
        .ji-result-count{color:#607782;font-size:13px;font-weight:400}
        .ji-toolbar{position:relative;display:flex;align-items:center;gap:8px;margin-bottom:10px;min-height:47px}
        .ji-filter-pill{height:38px;padding:0 13px;display:inline-flex;align-items:center;gap:7px;border:0;border-radius:999px;background:#e9e8e4;color:#173846;font:700 13px Arial,Helvetica,sans-serif;cursor:pointer}
        .ji-filter-pill:hover{background:#deddd8}.ji-filter-pill i{font-size:17px}
        .ji-filter-divider{color:#6e7e85;font-weight:400}
        .ji-search{width:202px;margin-left:auto;position:relative}
        .ji-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:17px;color:#587381;pointer-events:none}
        .ji-search input{width:100%;height:45px;padding:0 13px 0 45px;border:1px solid var(--ji-border);border-radius:7px;background:#fff;color:#173846;outline:0;font-family:inherit;font-size:13px}
        .ji-search input:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
        .ji-filter-pop{display:none;position:absolute;top:45px;z-index:1220;width:290px;padding:14px;border:1px solid var(--ji-border);border-radius:8px;background:#fff;box-shadow:0 12px 28px rgba(0,17,49,.14)}
        .ji-filter-pop.show{display:block}.ji-filter-pop.status{left:0}.ji-filter-pop.date{left:108px;width:320px}
        .ji-filter-label{display:block;margin:0 0 5px;color:#526b78;font-size:12px;font-weight:700}
        .ji-filter-pop select,.ji-filter-pop input{width:100%;height:39px;margin:0 0 10px;padding:7px 10px;border:1px solid var(--ji-border);border-radius:7px;background:#fff;color:#173846;font:14px Arial,Helvetica,sans-serif;outline:0}
        .ji-filter-pop select:focus,.ji-filter-pop input:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
        .ji-filter-pop-actions{display:flex;justify-content:flex-end;gap:7px;margin-top:3px}
        .ji-filter-pop-actions .ji-btn{height:34px;font-size:12px;padding:0 11px}

        .ji-table-wrap{width:100%;overflow:visible}
        .ji-table{width:100%;min-width:0;border-collapse:collapse;table-layout:fixed}
        .ji-table th{height:40px;padding:0 6px;border-bottom:1px solid #cfd9de;color:#46616e;background:#fff;font-size:11.5px;font-weight:400;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ji-table td{height:46px;padding:0 6px;border-bottom:1px solid #dde5e9;color:#314f5d;background:#fff;font-size:12px;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .ji-table tbody tr{cursor:pointer;transition:background .12s ease}
        .ji-table tbody tr:hover td,.ji-table tbody tr.row-active td{background:#f3f1ed}
        .ji-table tbody tr:focus{outline:2px solid rgba(47,141,37,.25);outline-offset:-2px}
        .ji-table th:nth-child(1){width:16%}.ji-table th:nth-child(2){width:13%}.ji-table th:nth-child(3){width:12%}.ji-table th:nth-child(4){width:20%}.ji-table th:nth-child(5){width:15%}.ji-table th:nth-child(6){width:9%;text-align:right}.ji-table th:nth-child(7){width:9%;text-align:right}.ji-table th:nth-child(8){width:6%}
        .ji-table td:nth-child(6),.ji-table td:nth-child(7){text-align:right}
        .ji-client{color:#123845;font-weight:700}.ji-number{color:#294b59}.ji-money{color:#153442;font-weight:700}
        .ji-sort{margin-left:4px;padding:0;border:0;background:transparent;color:#90a0a8;font-size:11px;cursor:pointer}.ji-sort.active{color:#3d5965}
        .ji-badge{display:inline-flex;align-items:center;gap:6px;min-height:23px;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:400;white-space:nowrap}
        .ji-badge:before{content:'';width:7px;height:7px;border-radius:50%;background:currentColor}
        .ji-badge.awaiting_payment,.ji-badge.sent,.ji-badge.viewed,.ji-badge.partially_paid{color:#8b7410;background:#f8f0c8}
        .ji-badge.paid{color:#3b8a33;background:#e7f2e4}
        .ji-badge.overdue{color:#bc4941;background:#fae8e6}
        .ji-badge.draft{color:#52717f;background:#edf1f2}
        .ji-badge.written_off,.ji-badge.cancelled,.ji-badge.archived{color:#687b84;background:#eef1f2}
        .ji-row-actions-cell{overflow:visible!important;position:relative;text-align:right!important}
        .ji-row-actions{position:relative;display:inline-flex;align-items:center;gap:0;opacity:0;pointer-events:none;transition:opacity .12s ease}
        .ji-table tbody tr:hover .ji-row-actions,.ji-table tbody tr.row-active .ji-row-actions{opacity:1;pointer-events:auto}
        .ji-row-action{width:31px;height:31px;padding:0;display:grid;place-items:center;border:1px solid #d7e0e4;background:#fff;color:#214555;cursor:pointer;font-size:16px}
        .ji-row-action:first-child{border-radius:7px 0 0 7px}.ji-row-action:last-of-type{border-radius:0 7px 7px 0;border-left:0}
        .ji-row-action:hover{background:#f8fbf6;color:var(--ji-green-dark)}
        .ji-row-menu{display:none;position:absolute;right:-2px;top:34px;z-index:1250;width:145px;padding:4px;border:1px solid var(--ji-border);border-radius:7px;background:#fff;box-shadow:0 8px 18px rgba(0,17,49,.14);text-align:left}
        .ji-row-menu.show{display:block}
        .ji-row-menu button,.ji-row-menu a{width:100%;min-height:38px;padding:7px 8px;display:flex;align-items:center;justify-content:space-between;border:0;border-radius:5px;background:#fff;color:#244653;font:13px Arial,Helvetica,sans-serif;cursor:pointer;text-align:left}
        .ji-row-menu button:hover,.ji-row-menu a:hover{background:#f3f1ed}.ji-row-menu .danger{color:#d94b43}
        .ji-empty{height:150px!important;text-align:center!important;color:#71858f!important;font-size:13px!important}
        .ji-footer{min-height:48px;padding:11px 0 0;display:flex;align-items:center;justify-content:space-between;gap:12px;color:#6d818c;font-size:12px}
        .ji-pagination{display:flex;align-items:center;gap:6px}.ji-page-btn{height:32px;min-width:32px;padding:0 8px;border:1px solid var(--ji-border);border-radius:6px;background:#fff;color:#31505d;cursor:pointer}.ji-page-btn:disabled{opacity:.45;cursor:not-allowed}

        .ji-modal-backdrop{display:none;position:fixed;inset:0;z-index:13000;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.33)}
        .ji-modal-backdrop.show{display:flex}
        .ji-modal{width:min(860px,calc(100vw - 30px));max-height:calc(100vh - 36px);overflow:auto;border:1px solid #d8e0e4;border-radius:10px;background:#fff;box-shadow:0 22px 60px rgba(0,17,49,.23)}
        .ji-modal.small{width:min(540px,calc(100vw - 30px))}
        .ji-modal-head{padding:20px 22px 12px;display:flex;align-items:center;justify-content:space-between;gap:15px}
        .ji-modal-head h3{margin:0;color:#123845;font-size:22px;font-weight:700}
        .ji-modal-close{width:34px;height:34px;padding:0;border:0;border-radius:7px;background:transparent;color:#274c5b;font-size:20px;cursor:pointer}.ji-modal-close:hover{background:#f2f5f5}
        .ji-modal-body{padding:10px 22px 12px}.ji-modal-foot{padding:12px 22px 20px;display:flex;align-items:center;justify-content:flex-end;gap:8px}
        .ji-email-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(245px,.65fr);gap:22px}
        .ji-email-to{min-height:92px;padding:10px 12px;display:flex;align-content:flex-start;align-items:center;gap:6px;flex-wrap:wrap;border:1px solid var(--ji-border);border-radius:7px;position:relative}
        .ji-email-to-label{color:#506976;font-size:13px;margin-right:2px}.ji-email-chips{display:flex;gap:5px;flex-wrap:wrap}
        .ji-email-chip{height:36px;padding:0 10px 0 12px;display:inline-flex;align-items:center;gap:7px;border:1px solid #dce4e8;border-radius:999px;background:#fff;color:#31505d;font-size:12px}
        .ji-email-chip button{width:22px;height:22px;padding:0;border:0;background:transparent;color:#4f6874;cursor:pointer}
        .ji-email-recipient-input{min-width:110px;flex:1;height:34px;border:0!important;outline:0!important;padding:0 4px;font:13px Arial,Helvetica,sans-serif;color:#173846}
        .ji-email-field{position:relative;margin-top:12px}.ji-email-field label{position:absolute;left:13px;top:6px;color:#687e89;font-size:11px;pointer-events:none}
        .ji-email-field input,.ji-email-field textarea{width:100%;border:1px solid var(--ji-border);border-radius:7px;background:#fff;color:#173846;outline:0;font-family:inherit;font-size:13px}.ji-email-field input{height:46px;padding:18px 12px 6px}.ji-email-field textarea{min-height:135px;padding:22px 12px 10px;line-height:1.55;resize:vertical}
        .ji-email-field input:focus,.ji-email-field textarea:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
        .ji-email-pdf{min-height:52px;margin-top:12px;padding:7px 10px;display:flex;align-items:center;gap:10px;border:1px solid var(--ji-border);border-radius:7px;background:#fff}
        .ji-email-pdf-icon{width:46px;height:46px;display:grid;place-items:center;background:#f2f0ed;color:#df3d34;font-size:20px}.ji-email-pdf strong{display:block;color:#405b67;font-size:12px}.ji-email-pdf small{display:block;margin-top:2px;color:#7c8e96;font-size:11px}
        .ji-attachments h4{margin:0 0 16px;color:#123845;font-size:14px}.ji-drop{min-height:108px;padding:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;border:1px dashed #d0dade;border-radius:7px;background:#fff;color:#617783;font-size:11px;text-align:center}.ji-drop.dragover{border-color:#79ad64;background:#f8fcf6}.ji-drop .ji-btn{height:31px;padding:0 12px;color:var(--ji-green-dark);font-size:12px}
        .ji-email-file-list{margin-top:9px;display:grid;gap:6px}.ji-email-file{min-height:38px;padding:7px 9px;display:flex;align-items:center;gap:8px;border:1px solid #e1e7ea;border-radius:6px;background:#fbfcfc;color:#405d6a;font-size:11px}.ji-email-file span{min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ji-email-file button{width:25px;height:25px;border:0;background:transparent;color:#7a8d96;cursor:pointer}
        .ji-limit{margin-top:11px;color:#607782;font-size:11px}.ji-progress{height:7px;margin-top:6px;overflow:hidden;border-radius:999px;background:#dedbd6}.ji-progress span{display:block;width:0;height:100%;background:#85b95f;transition:width .15s ease}
        .ji-check{display:flex;align-items:center;gap:8px;color:#405d6a;font-size:13px}.ji-check input{width:18px;height:18px;accent-color:var(--ji-green)}
        .ji-delete-copy{color:#405d6a;font-size:13px;line-height:1.55}.ji-delete-copy strong{display:block;margin-bottom:13px;color:#324e5b}.ji-delete-copy ul{margin:0;padding-left:19px}.ji-delete-copy li{margin:4px 0}
        .ji-toast{position:fixed;top:82px;right:18px;z-index:14000;width:min(390px,calc(100vw - 36px));padding:12px 14px;border-radius:8px;color:#fff;background:#1f5f7a;box-shadow:0 12px 30px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s;font-size:14px;font-weight:700}.ji-toast.show{opacity:1;transform:translateY(0)}.ji-toast.error{background:#c94f55}.ji-toast.success{background:#2f8d25}.ji-toast.warning{background:#9a741a}

        @media(max-width:1199.98px){.ji-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:991.98px){.ji-page{padding:20px 16px 36px}.ji-head{align-items:flex-start}.ji-email-grid{grid-template-columns:1fr}.ji-row-actions{opacity:1;pointer-events:auto}}
        @media(max-width:767.98px){.ji-page{padding:17px 13px 32px}.ji-title{font-size:27px}.ji-head{flex-direction:column}.ji-head-actions{width:100%}.ji-head-actions>.ji-btn{flex:1}.ji-metrics{grid-template-columns:1fr}.ji-toolbar{align-items:flex-start;flex-wrap:wrap}.ji-search{width:100%;order:-1;margin-left:0}.ji-filter-pop.status,.ji-filter-pop.date{left:0;top:92px;width:min(320px,calc(100vw - 26px))}.ji-email-grid{grid-template-columns:1fr}.ji-modal-head h3{font-size:19px}.ji-footer{align-items:flex-start;flex-direction:column}.ji-table thead{display:none}.ji-table,.ji-table tbody,.ji-table tr,.ji-table td{display:block;width:100%}.ji-table tbody tr{position:relative;display:grid;grid-template-columns:1fr 1fr;padding:9px 42px 9px 10px;border-bottom:1px solid #dde5e9}.ji-table td{height:auto;min-height:36px;padding:4px 7px;border-bottom:0;white-space:normal;overflow:visible}.ji-table td:before{content:attr(data-label);display:block;margin-bottom:2px;color:#748690;font-size:10px}.ji-row-actions-cell{position:absolute!important;right:8px;top:8px;width:34px!important;padding:0!important}.ji-row-actions{opacity:1;pointer-events:auto;flex-direction:column}.ji-row-action{border:1px solid #d7e0e4!important;border-radius:7px!important;margin-bottom:4px}.ji-row-menu{right:34px;top:0}.ji-stat-pop{left:0;transform:translate(0,6px)}.ji-trend-wrap:hover .ji-stat-pop,.ji-trend-wrap:focus .ji-stat-pop,.ji-trend-wrap:focus-within .ji-stat-pop{transform:translate(0,0)}.ji-stat-pop:after{left:22px}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="ji-page">
                <header class="ji-head">
                    <h1 class="ji-title">Invoices</h1>
                    <div class="ji-head-actions">
                        <?php if($jiCanCreate): ?><a href="add-invoice.php" class="ji-btn primary">New Invoice</a><?php endif; ?>
                        <?php if($jiCanCreate || $jiCanUpdate): ?>
                        <button type="button" class="ji-btn" id="moreActionsButton"><i class="bi bi-three-dots"></i> More Actions</button>
                        <div class="ji-more-menu" id="moreActionsMenu">
                            <?php if($jiCanCreate): ?><button type="button" data-top-action="batch-create"><i class="bi bi-files"></i><span>Batch Create<br>Invoices</span></button><?php endif; ?>
                            <?php if($jiCanUpdate): ?><button type="button" data-top-action="batch-deliver"><i class="bi bi-envelope"></i><span>Batch Deliver<br>Invoices</span></button><?php endif; ?>
                            <?php if($jiCanCreate): ?><button type="button" data-top-action="import"><i class="bi bi-box-arrow-in-down-left"></i><span>Import Invoice Data</span></button><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </header>

                <section class="ji-metrics" aria-label="Invoice overview">
                    <article class="ji-card">
                        <div class="ji-card-title">Overview</div>
                        <ul class="ji-overview-list">
                            <li class="ji-overview-item"><span class="ji-dot red"></span><span>Past due (<b id="pastDueCount">0</b>)</span><strong id="pastDueAmount">0.00</strong></li>
                            <li class="ji-overview-item"><span class="ji-dot yellow"></span><span>Sent but not due (<b id="sentNotDueCount">0</b>)</span><strong id="sentNotDueAmount">0.00</strong></li>
                            <li class="ji-overview-item"><span class="ji-dot blue"></span><span>Draft (<b id="draftCount">0</b>)</span><strong id="draftAmount">0.00</strong></li>
                        </ul>
                    </article>

                    <article class="ji-card">
                        <div class="ji-card-head"><div><div class="ji-card-title">Issued</div><div class="ji-card-sub">Past 30 days</div></div><span class="ji-card-corner"><i class="bi bi-arrow-up-right"></i></span></div>
                        <div class="ji-metric-main"><strong class="ji-metric-value" id="issuedCount">0</strong><span class="ji-trend-wrap" tabindex="0" aria-label="Issued comparison"><span class="ji-trend flat" id="issuedTrend">—%</span><span class="ji-stat-pop" role="tooltip"><span class="ji-stat-pop-title">Issued</span><span class="ji-stat-pop-row"><span id="issuedPrevRange">—</span><strong id="issuedPrevValue">0</strong></span><span class="ji-stat-pop-row"><span id="issuedCurrentRange">—</span><strong id="issuedCurrentValue">0</strong></span></span></span></div>
                        <div class="ji-metric-foot" id="issuedTotal">0.00</div>
                    </article>

                    <article class="ji-card">
                        <div class="ji-card-head"><div><div class="ji-card-title">Average invoice</div><div class="ji-card-sub">Past 30 days</div></div></div>
                        <div class="ji-metric-main"><strong class="ji-metric-value money" id="averageInvoice">0.00</strong><span class="ji-trend-wrap" tabindex="0" aria-label="Average invoice comparison"><span class="ji-trend flat" id="averageTrend">—%</span><span class="ji-stat-pop" role="tooltip"><span class="ji-stat-pop-title">Average invoice</span><span class="ji-stat-pop-row"><span id="averagePrevRange">—</span><strong id="averagePrevValue">0.00</strong></span><span class="ji-stat-pop-row"><span id="averageCurrentRange">—</span><strong id="averageCurrentValue">0.00</strong></span></span></span></div>
                    </article>

                    <article class="ji-card">
                        <div class="ji-card-head"><div class="ji-card-title">Invoice<br>payment<br>time</div><span class="ji-card-corner"><i class="bi bi-info-circle"></i>&nbsp;&nbsp;<i class="bi bi-arrow-up-right"></i></span></div>
                        <div class="ji-metric-main"><strong class="ji-metric-value" id="paymentDays">—</strong><span class="ji-trend-wrap" tabindex="0" aria-label="Invoice payment time comparison"><span class="ji-trend flat" id="paymentTrend">—%</span><span class="ji-stat-pop" role="tooltip"><span class="ji-stat-pop-title">Invoice payment time</span><span class="ji-stat-pop-row"><span id="paymentPrevRange">—</span><strong id="paymentPrevValue">— days</strong></span><span class="ji-stat-pop-row"><span id="paymentCurrentRange">—</span><strong id="paymentCurrentValue">— days</strong></span></span></span></div>
                        <div class="ji-metric-foot">Average days</div>
                    </article>
                </section>

                <section>
                    <div class="ji-list-heading"><h2>All invoices</h2><span class="ji-result-count" id="resultCount">(0 results)</span></div>
                    <div class="ji-toolbar">
                        <button type="button" class="ji-filter-pill" id="statusPill"><span>Status</span><span class="ji-filter-divider">|</span><span id="statusPillValue">All</span></button>
                        <button type="button" class="ji-filter-pill" id="datePill"><i class="bi bi-calendar2"></i><span>Date</span><span class="ji-filter-divider">|</span><span id="datePillValue">All</span></button>

                        <div class="ji-filter-pop status" id="statusPopover">
                            <label class="ji-filter-label" for="statusFilter">Invoice status</label>
                            <select id="statusFilter">
                                <option value="">All</option>
                                <option value="draft">Draft</option>
                                <option value="awaiting_payment">Awaiting payment</option>
                                <option value="overdue">Past due</option>
                                <option value="partially_paid">Partially paid</option>
                                <option value="paid">Paid</option>
                                <option value="written_off">Written off</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="archived">Archived</option>
                            </select>
                            <label class="ji-filter-label" for="paymentFilter">Payment status</label>
                            <select id="paymentFilter">
                                <option value="">All</option>
                                <option value="outstanding">Outstanding</option>
                                <option value="unpaid">Unpaid</option>
                                <option value="partial">Partially paid</option>
                                <option value="paid">Paid</option>
                                <option value="overdue">Overdue</option>
                            </select>
                            <div id="branchFilterWrap">
                                <label class="ji-filter-label" for="branchFilter">Branch</label>
                                <select id="branchFilter"><option value="">All branches</option></select>
                            </div>
                            <div class="ji-filter-pop-actions"><button type="button" class="ji-btn" id="clearStatusFilters">Clear</button><button type="button" class="ji-btn primary" id="applyStatusFilters">Apply</button></div>
                        </div>

                        <div class="ji-filter-pop date" id="datePopover">
                            <label class="ji-filter-label" for="dateType">Date type</label>
                            <select id="dateType"><option value="issue_date">Issued date</option><option value="due_date">Due date</option><option value="created_at">Created date</option></select>
                            <label class="ji-filter-label" for="fromDate">From</label><input type="date" id="fromDate">
                            <label class="ji-filter-label" for="toDate">To</label><input type="date" id="toDate">
                            <div class="ji-filter-pop-actions"><button type="button" class="ji-btn" id="clearDateFilters">Clear</button><button type="button" class="ji-btn primary" id="applyDateFilters">Apply</button></div>
                        </div>

                        <div class="ji-search"><i class="bi bi-search"></i><input type="search" id="search" placeholder="Search invoices..."></div>
                    </div>

                    <div class="ji-table-wrap">
                        <table class="ji-table">
                            <thead>
                                <tr>
                                    <th>Client <button type="button" class="ji-sort" data-sort="client"><i class="bi bi-chevron-expand"></i></button></th>
                                    <th>Invoice number <button type="button" class="ji-sort" data-sort="invoice_no"><i class="bi bi-chevron-expand"></i></button></th>
                                    <th>Due date <button type="button" class="ji-sort active" data-sort="due_date"><i class="bi bi-chevron-expand"></i></button></th>
                                    <th>Subject</th>
                                    <th>Status <button type="button" class="ji-sort" data-sort="status"><i class="bi bi-chevron-expand"></i></button></th>
                                    <th>Total <button type="button" class="ji-sort" data-sort="total"><i class="bi bi-chevron-expand"></i></button></th>
                                    <th>Balance <button type="button" class="ji-sort" data-sort="balance"><i class="bi bi-chevron-expand"></i></button></th>
                                    <th aria-label="Actions"></th>
                                </tr>
                            </thead>
                            <tbody id="invoiceRows"><tr><td colspan="8" class="ji-empty">Loading invoices...</td></tr></tbody>
                        </table>
                    </div>

                    <div class="ji-footer">
                        <div id="countText">Showing 0 invoices</div>
                        <div class="ji-pagination"><button type="button" class="ji-page-btn" id="prevButton"><i class="bi bi-chevron-left"></i></button><span id="pageText">Page 1 of 1</span><button type="button" class="ji-page-btn" id="nextButton"><i class="bi bi-chevron-right"></i></button></div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<div class="ji-modal-backdrop" id="emailModal" aria-hidden="true">
    <section class="ji-modal" role="dialog" aria-modal="true" aria-labelledby="emailModalTitle">
        <div class="ji-modal-head"><h3 id="emailModalTitle">Email invoice</h3><button type="button" class="ji-modal-close" id="emailClose" aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
        <form id="emailForm" enctype="multipart/form-data">
            <input type="hidden" id="emailInvoiceId" name="invoice_id">
            <input type="hidden" id="emailToHidden" name="to_emails">
            <div class="ji-modal-body">
                <div class="ji-email-grid">
                    <div>
                        <div class="ji-email-to"><span class="ji-email-to-label">To</span><div class="ji-email-chips" id="emailRecipientChips"></div><input type="text" class="ji-email-recipient-input" id="emailRecipientInput" placeholder="Add email"></div>
                        <div class="ji-email-field"><label for="emailSubject">Subject</label><input type="text" id="emailSubject" name="email_subject" maxlength="255" required></div>
                        <div class="ji-email-field"><label for="emailMessage">Message</label><textarea id="emailMessage" name="email_message" required></textarea></div>
                        <div class="ji-email-pdf"><div class="ji-email-pdf-icon"><i class="bi bi-file-earmark-pdf"></i></div><div><strong id="emailPdfName">invoice.pdf</strong><small>Invoice PDF is generated and attached when the email is sent.</small></div></div>
                    </div>
                    <div class="ji-attachments">
                        <h4>Attachments</h4>
                        <div class="ji-drop" id="emailDrop"><button type="button" class="ji-btn" id="emailAttachmentPick">Select</button><span>Select or drag files here to upload</span><input type="file" id="emailAttachmentInput" name="email_attachments[]" multiple hidden></div>
                        <div class="ji-email-file-list" id="emailAttachmentList"></div>
                        <div class="ji-limit">You've attached <span id="emailAttachmentMb">0.00</span> MB of the 10.00 MB limit.<div class="ji-progress"><span id="emailAttachmentProgress"></span></div></div>
                    </div>
                </div>
            </div>
            <div class="ji-modal-foot" style="justify-content:space-between"><label class="ji-check"><input type="checkbox" id="sendMeCopy" name="send_me_copy" value="1"> Send me a copy</label><div style="display:flex;gap:8px"><button type="button" class="ji-btn" id="emailCancel">Cancel</button><button type="submit" class="ji-btn primary" id="sendEmailButton">Send Email</button></div></div>
        </form>
    </section>
</div>

<div class="ji-modal-backdrop" id="deleteModal" aria-hidden="true">
    <section class="ji-modal small" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <div class="ji-modal-head"><h3 id="deleteModalTitle">Delete invoice?</h3><button type="button" class="ji-modal-close" id="deleteClose" aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
        <div class="ji-modal-body ji-delete-copy"><strong>By deleting this invoice:</strong><ul><li>Its total will be removed from your client's active balance</li><li>It will be removed from normal invoice reports</li><li>A new invoice reminder won't be created</li></ul></div>
        <div class="ji-modal-foot"><button type="button" class="ji-btn" id="deleteCancel">Cancel</button><button type="button" class="ji-btn danger" id="deleteConfirm">Delete Invoice</button></div>
    </section>
</div>

<div class="ji-toast" id="toast">Notification</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
'use strict';
var csrfToken=<?= json_encode($invoiceCsrfToken) ?>;
var pagePermissions={view:<?= $jiCanView?'true':'false' ?>,create:<?= $jiCanCreate?'true':'false' ?>,update:<?= $jiCanUpdate?'true':'false' ?>,delete:<?= $jiCanDelete?'true':'false' ?>};
var apiUrl='api/invoices-jobber.php';
var state={page:1,perPage:10,search:'',status:'',paymentStatus:'',branchId:'',dateType:'issue_date',fromDate:'',toDate:'',sortKey:'due_date',sortDir:'ASC',pagination:{page:1,pages:1,total:0,from:0,to:0},currency:{},branches:[],companyName:'FieldPlx'};
var rowMap={},searchTimer=null,toastTimer=null,selectedDeleteInvoiceId=0,emailFiles=[],emailRecipients=[];
function E(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function toast(type,msg){var t=E('toast');if(toastTimer)clearTimeout(toastTimer);t.className='ji-toast '+(type||'')+' show';t.textContent=msg||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},3200)}
function parse(r){return r.text().then(function(raw){var d=null,text=String(raw||'').trim();try{d=text?JSON.parse(text):{}}catch(e){throw new Error(text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d||d.success!==true)throw new Error(d&&d.message?d.message:'Request failed.');return d})}
function request(fd){fd.append('csrf_token',csrfToken);return fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parse)}
function money(v){var c=state.currency||{},p=parseInt(c.decimal_places,10);if(isNaN(p))p=2;var n=Number(v||0).toFixed(p),s=c.symbol||'';return c.symbol_position==='after'?n+(s?' '+s:''):(s||'')+n}
function fmtDate(v){if(!v)return '—';var d=new Date(String(v).substring(0,10)+'T00:00:00');return isNaN(d.getTime())?String(v):d.toLocaleDateString(undefined,{month:'short',day:'2-digit',year:'numeric'})}
function rangeDate(v){if(!v)return '—';var d=new Date(String(v).substring(0,10)+'T00:00:00');return isNaN(d.getTime())?String(v):d.toLocaleDateString(undefined,{month:'short',day:'numeric'})}
function rangeText(period){if(!period)return '—';return rangeDate(period.start)+' - '+rangeDate(period.end)}
function daysText(v,count){var n=Number(v);return Number(count||0)>0&&isFinite(n)?Math.round(n)+' days':'— days'}
function invoiceNo(v){v=String(v||'—');return /^\d+$/.test(v)?'#'+v:v}
function statusLabel(v){var map={awaiting_payment:'Awaiting payment',overdue:'Past due',partially_paid:'Partially paid',written_off:'Written off',draft:'Draft',paid:'Paid',sent:'Awaiting payment',viewed:'Awaiting payment',cancelled:'Cancelled',archived:'Archived'};return map[String(v||'').toLowerCase()]||String(v||'').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
function badge(v){var key=String(v||'').toLowerCase();return '<span class="ji-badge '+esc(key)+'">'+esc(statusLabel(key))+'</span>'}
function trend(current,previous){current=Number(current||0);previous=Number(previous||0);if(previous<=0){return current>0?{value:'↑ 100%',cls:''}:{value:'—%',cls:'flat'}}var pct=((current-previous)/previous)*100;var rounded=Math.round(Math.abs(pct));return pct>0?{value:'↑ '+rounded+'%',cls:''}:pct<0?{value:'↓ '+rounded+'%',cls:'down'}:{value:'—%',cls:'flat'}}
function setTrend(id,data){var n=E(id);n.textContent=data.value;n.className='ji-trend '+data.cls}
function paymentTrend(current,previous,currentCount,previousCount){current=Number(current||0);previous=Number(previous||0);if(Number(currentCount||0)<=0||Number(previousCount||0)<=0||previous<=0)return {value:'—%',cls:'flat'};var pct=((current-previous)/previous)*100,rounded=Math.round(Math.abs(pct));return pct<0?{value:'↓ '+rounded+'%',cls:''}:pct>0?{value:'↑ '+rounded+'%',cls:'down'}:{value:'—%',cls:'flat'}}
function closeMenus(){if(E('moreActionsMenu'))E('moreActionsMenu').classList.remove('show');E('statusPopover').classList.remove('show');E('datePopover').classList.remove('show');document.querySelectorAll('.ji-row-menu.show').forEach(function(m){m.classList.remove('show')});document.querySelectorAll('tr.row-active').forEach(function(r){r.classList.remove('row-active')})}
function modal(id,show){var n=E(id);n.classList.toggle('show',!!show);n.setAttribute('aria-hidden',show?'false':'true');document.body.style.overflow=show?'hidden':''}
function setBranches(rows){state.branches=rows||[];var html='<option value="">All branches</option>';state.branches.forEach(function(b){html+='<option value="'+Number(b.id)+'">'+esc(b.name||'Branch')+(b.branch_code?' · '+esc(b.branch_code):'')+'</option>'});E('branchFilter').innerHTML=html;E('branchFilter').value=state.branchId;E('branchFilterWrap').style.display=state.branches.length>1?'block':'none'}
function renderSummary(s,periods){s=s||{};periods=periods||{};var current=periods.current||{},previous=periods.previous||{};E('pastDueCount').textContent=Number(s.past_due_count||0).toLocaleString();E('pastDueAmount').textContent=money(s.past_due_amount);E('sentNotDueCount').textContent=Number(s.sent_not_due_count||0).toLocaleString();E('sentNotDueAmount').textContent=money(s.sent_not_due_amount);E('draftCount').textContent=Number(s.draft_count||0).toLocaleString();E('draftAmount').textContent=money(s.draft_amount);E('issuedCount').textContent=Number(s.issued_30_count||0).toLocaleString();E('issuedTotal').textContent=money(s.issued_30_total);E('averageInvoice').textContent=money(s.average_30);var paymentCount=Number(s.payment_30_count||0);E('paymentDays').textContent=paymentCount>0?Math.round(Number(s.payment_days_30||0)):'—';setTrend('issuedTrend',trend(s.issued_30_count,s.issued_prev_count));setTrend('averageTrend',trend(s.average_30,s.average_prev));setTrend('paymentTrend',paymentTrend(s.payment_days_30,s.payment_days_prev,s.payment_30_count,s.payment_prev_count));E('issuedPrevRange').textContent=rangeText(previous);E('issuedCurrentRange').textContent=rangeText(current);E('issuedPrevValue').textContent=Number(s.issued_prev_count||0).toLocaleString();E('issuedCurrentValue').textContent=Number(s.issued_30_count||0).toLocaleString();E('averagePrevRange').textContent=rangeText(previous);E('averageCurrentRange').textContent=rangeText(current);E('averagePrevValue').textContent=money(s.average_prev);E('averageCurrentValue').textContent=money(s.average_30);E('paymentPrevRange').textContent=rangeText(previous);E('paymentCurrentRange').textContent=rangeText(current);E('paymentPrevValue').textContent=daysText(s.payment_days_prev,s.payment_prev_count);E('paymentCurrentValue').textContent=daysText(s.payment_days_30,s.payment_30_count)}
function renderPagination(p){state.pagination=p||state.pagination;E('resultCount').textContent='('+Number(state.pagination.total||0).toLocaleString()+' result'+(Number(state.pagination.total||0)===1?'':'s')+')';E('countText').textContent=state.pagination.total>0?'Showing '+state.pagination.from+'-'+state.pagination.to+' of '+state.pagination.total+' invoices':'Showing 0 invoices';E('pageText').textContent='Page '+state.pagination.page+' of '+state.pagination.pages;E('prevButton').disabled=state.pagination.page<=1;E('nextButton').disabled=state.pagination.page>=state.pagination.pages}
function renderRows(rows){var body=E('invoiceRows');rowMap={};if(!rows||!rows.length){body.innerHTML='<tr><td colspan="8" class="ji-empty">No invoices found.</td></tr>';return}var html='';rows.forEach(function(r){rowMap[String(r.id)]=r;var stateKey=String(r.payment_state||r.status||'').toLowerCase();var emailAction=pagePermissions.update?'<button type="button" class="ji-row-action" data-email="'+Number(r.id)+'" title="Email invoice"><i class="bi bi-envelope"></i></button>':'';var deleteAction=pagePermissions.delete?'<button type="button" class="danger" data-delete="'+Number(r.id)+'">Delete</button>':'';html+='<tr data-invoice-id="'+Number(r.id)+'" tabindex="0" role="link">'
+'<td data-label="Client"><span class="ji-client">'+esc(r.client_name||'—')+'</span></td>'
+'<td data-label="Invoice number"><span class="ji-number">'+esc(invoiceNo(r.invoice_no))+'</span></td>'
+'<td data-label="Due date">'+esc(fmtDate(r.due_date))+'</td>'
+'<td data-label="Subject" title="'+esc(r.subject||'For Services Rendered')+'">'+esc(r.subject||'For Services Rendered')+'</td>'
+'<td data-label="Status">'+badge(stateKey)+'</td>'
+'<td data-label="Total"><span class="ji-money">'+esc(money(r.total))+'</span></td>'
+'<td data-label="Balance"><span class="ji-money">'+esc(money(r.balance_due))+'</span></td>'
+'<td data-label="Actions" class="ji-row-actions-cell"><div class="ji-row-actions">'+emailAction+'<button type="button" class="ji-row-action" data-row-more="'+Number(r.id)+'" title="More"><i class="bi bi-three-dots"></i></button><div class="ji-row-menu" data-row-menu="'+Number(r.id)+'">'+deleteAction+'<a href="invoice-view.php?invoice_id='+Number(r.id)+'" target="_blank" rel="noopener">Open in New Tab <i class="bi bi-box-arrow-up-right"></i></a></div></div></td>'
+'</tr>'});body.innerHTML=html}
function load(){var fd=new FormData();fd.append('action','list');fd.append('page',String(state.page));fd.append('per_page',String(state.perPage));fd.append('search',state.search);fd.append('status',state.status);fd.append('payment_status',state.paymentStatus);fd.append('branch_id',state.branchId);fd.append('date_type',state.dateType);fd.append('from_date',state.fromDate);fd.append('to_date',state.toDate);fd.append('sort_key',state.sortKey);fd.append('sort_dir',state.sortDir);request(fd).then(function(d){state.currency=d.currency||state.currency;state.companyName=d.company_name||'FieldPlx';if(d.permissions){pagePermissions=d.permissions}setBranches(d.branches||[]);renderSummary(d.summary||{},d.periods||{});renderPagination(d.pagination||{});renderRows(d.rows||[])}).catch(function(e){toast('error',e.message);E('invoiceRows').innerHTML='<tr><td colspan="8" class="ji-empty">'+esc(e.message)+'</td></tr>'})}
function resetPage(){state.page=1;load()}
function updateFilterLabels(){var statusParts=[];if(state.status)statusParts.push(statusLabel(state.status));if(state.paymentStatus)statusParts.push(statusLabel(state.paymentStatus));if(state.branchId){var b=state.branches.find(function(x){return String(x.id)===String(state.branchId)});if(b)statusParts.push(b.name)}E('statusPillValue').textContent=statusParts.length?statusParts.join(' · '):'All';E('datePillValue').textContent=(state.fromDate||state.toDate)?((state.fromDate?fmtDate(state.fromDate):'Any')+' - '+(state.toDate?fmtDate(state.toDate):'Any')):'All'}

if(E('moreActionsButton'))E('moreActionsButton').addEventListener('click',function(e){e.stopPropagation();var m=E('moreActionsMenu'),show=!m.classList.contains('show');closeMenus();if(show)m.classList.add('show')});
if(E('moreActionsMenu'))E('moreActionsMenu').addEventListener('click',function(e){var b=e.target.closest('[data-top-action]');if(!b)return;var a=b.getAttribute('data-top-action');closeMenus();if(a==='batch-create')toast('warning','Batch Create Invoices is ready for its dedicated workflow page.');if(a==='batch-deliver')toast('warning','Batch Deliver Invoices is ready for its dedicated workflow page.');if(a==='import')toast('warning','Invoice import is ready for its dedicated import workflow.');});
E('statusPill').addEventListener('click',function(e){e.stopPropagation();var p=E('statusPopover'),show=!p.classList.contains('show');closeMenus();if(show)p.classList.add('show')});
E('datePill').addEventListener('click',function(e){e.stopPropagation();var p=E('datePopover'),show=!p.classList.contains('show');closeMenus();if(show)p.classList.add('show')});
E('statusPopover').addEventListener('click',function(e){e.stopPropagation()});E('datePopover').addEventListener('click',function(e){e.stopPropagation()});
E('applyStatusFilters').addEventListener('click',function(){state.status=E('statusFilter').value;state.paymentStatus=E('paymentFilter').value;state.branchId=E('branchFilter').value;updateFilterLabels();closeMenus();resetPage()});
E('clearStatusFilters').addEventListener('click',function(){E('statusFilter').value='';E('paymentFilter').value='';E('branchFilter').value='';state.status='';state.paymentStatus='';state.branchId='';updateFilterLabels();closeMenus();resetPage()});
E('applyDateFilters').addEventListener('click',function(){state.dateType=E('dateType').value;state.fromDate=E('fromDate').value;state.toDate=E('toDate').value;updateFilterLabels();closeMenus();resetPage()});
E('clearDateFilters').addEventListener('click',function(){E('dateType').value='issue_date';E('fromDate').value='';E('toDate').value='';state.dateType='issue_date';state.fromDate='';state.toDate='';updateFilterLabels();closeMenus();resetPage()});
E('search').addEventListener('input',function(){state.search=this.value.trim();if(searchTimer)clearTimeout(searchTimer);searchTimer=setTimeout(resetPage,280)});
document.querySelectorAll('.ji-sort').forEach(function(btn){btn.addEventListener('click',function(){var key=this.getAttribute('data-sort');if(state.sortKey===key)state.sortDir=state.sortDir==='ASC'?'DESC':'ASC';else{state.sortKey=key;state.sortDir='ASC'}document.querySelectorAll('.ji-sort').forEach(function(x){x.classList.toggle('active',x.getAttribute('data-sort')===state.sortKey)});resetPage()})});
E('prevButton').addEventListener('click',function(){if(state.page>1){state.page--;load()}});E('nextButton').addEventListener('click',function(){if(state.page<state.pagination.pages){state.page++;load()}});

E('invoiceRows').addEventListener('click',function(e){var emailBtn=e.target.closest('[data-email]');if(emailBtn){e.stopPropagation();openEmail(Number(emailBtn.getAttribute('data-email')));return}var moreBtn=e.target.closest('[data-row-more]');if(moreBtn){e.stopPropagation();var id=moreBtn.getAttribute('data-row-more'),menu=document.querySelector('[data-row-menu="'+id+'"]'),tr=moreBtn.closest('tr'),show=!menu.classList.contains('show');closeMenus();if(show){menu.classList.add('show');if(tr)tr.classList.add('row-active')}return}var deleteBtn=e.target.closest('[data-delete]');if(deleteBtn){e.stopPropagation();openDelete(Number(deleteBtn.getAttribute('data-delete')));return}if(e.target.closest('a,button,input,select,textarea,label'))return;var tr=e.target.closest('tr[data-invoice-id]');if(tr)window.location.href='invoice-view.php?invoice_id='+encodeURIComponent(tr.getAttribute('data-invoice-id'))});
E('invoiceRows').addEventListener('keydown',function(e){if((e.key==='Enter'||e.key===' ')&&!e.target.closest('a,button')){var tr=e.target.closest('tr[data-invoice-id]');if(tr){e.preventDefault();window.location.href='invoice-view.php?invoice_id='+encodeURIComponent(tr.getAttribute('data-invoice-id'))}}});
document.addEventListener('click',function(){closeMenus()});

function validEmail(v){return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v||''))}
function syncRecipients(){E('emailToHidden').value=emailRecipients.join(',');E('emailRecipientChips').innerHTML=emailRecipients.map(function(v,i){return '<span class="ji-email-chip">'+esc(v)+'<button type="button" data-remove-recipient="'+i+'"><i class="bi bi-x"></i></button></span>'}).join('')}
function addRecipient(raw){String(raw||'').split(/[;,\s]+/).forEach(function(v){v=v.trim().toLowerCase();if(validEmail(v)&&emailRecipients.indexOf(v)<0)emailRecipients.push(v)});syncRecipients()}
function safePdfName(no){return 'invoice_'+String(no||'invoice').replace(/[^A-Za-z0-9_-]/g,'_')+'.pdf'}
function openEmail(id){closeMenus();var r=rowMap[String(id)];if(!r){toast('error','Invoice details are not available.');return}emailRecipients=[];emailFiles=[];if(r.client_email)addRecipient(r.client_email);syncEmailFiles();E('emailInvoiceId').value=id;E('emailModalTitle').textContent='Email invoice '+invoiceNo(r.invoice_no)+' to '+(r.client_name||'Client');E('emailSubject').value='Invoice from '+(state.companyName||'FieldPlx')+' - '+(r.subject||'For Services Rendered');E('emailMessage').value='Hi '+(r.client_name||'Client')+',\n\nThank you for choosing to work with us.\n\nJust a quick note to let you know your invoice is now available.\n\nPlease let us know if you have any questions.\n\nSincerely,\n'+(state.companyName||'FieldPlx');E('emailPdfName').textContent=safePdfName(r.invoice_no);E('sendMeCopy').checked=false;modal('emailModal',true);setTimeout(function(){E('emailRecipientInput').focus()},80)}
function closeEmail(){modal('emailModal',false);E('emailRecipientInput').value=''}
E('emailClose').addEventListener('click',closeEmail);E('emailCancel').addEventListener('click',closeEmail);E('emailModal').addEventListener('click',function(e){if(e.target===this)closeEmail()});
E('emailRecipientInput').addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===','||e.key===';'){e.preventDefault();addRecipient(this.value);this.value=''}});E('emailRecipientInput').addEventListener('blur',function(){if(this.value.trim()){addRecipient(this.value);this.value=''}});E('emailRecipientChips').addEventListener('click',function(e){var b=e.target.closest('[data-remove-recipient]');if(!b)return;emailRecipients.splice(Number(b.getAttribute('data-remove-recipient')),1);syncRecipients()});
function syncEmailFiles(){var total=0;E('emailAttachmentList').innerHTML=emailFiles.map(function(f,i){total+=Number(f.size||0);return '<div class="ji-email-file"><i class="bi bi-paperclip"></i><span>'+esc(f.name)+'</span><small>'+((f.size||0)/1024/1024).toFixed(2)+' MB</small><button type="button" data-remove-file="'+i+'"><i class="bi bi-x"></i></button></div>'}).join('');var mb=total/1024/1024;E('emailAttachmentMb').textContent=mb.toFixed(2);E('emailAttachmentProgress').style.width=Math.min(100,mb/10*100)+'%'}
function acceptEmailFiles(list){var next=emailFiles.slice();Array.prototype.forEach.call(list||[],function(f){if(f&&f.size>0)next.push(f)});var total=next.reduce(function(s,f){return s+Number(f.size||0)},0);if(total>10*1024*1024){toast('error','Email attachments cannot exceed 10 MB in total.');return}emailFiles=next;syncEmailFiles()}
E('emailAttachmentPick').addEventListener('click',function(){E('emailAttachmentInput').click()});E('emailAttachmentInput').addEventListener('change',function(){acceptEmailFiles(this.files);this.value=''});E('emailAttachmentList').addEventListener('click',function(e){var b=e.target.closest('[data-remove-file]');if(!b)return;emailFiles.splice(Number(b.getAttribute('data-remove-file')),1);syncEmailFiles()});['dragenter','dragover'].forEach(function(ev){E('emailDrop').addEventListener(ev,function(e){e.preventDefault();this.classList.add('dragover')})});['dragleave','drop'].forEach(function(ev){E('emailDrop').addEventListener(ev,function(e){e.preventDefault();this.classList.remove('dragover');if(ev==='drop')acceptEmailFiles(e.dataTransfer.files)})});
E('emailForm').addEventListener('submit',function(e){e.preventDefault();if(E('emailRecipientInput').value.trim()){addRecipient(E('emailRecipientInput').value);E('emailRecipientInput').value=''}if(!emailRecipients.length){toast('error','Add at least one valid recipient email address.');return}var fd=new FormData();fd.append('action','send_email');fd.append('invoice_id',E('emailInvoiceId').value);fd.append('to_emails',emailRecipients.join(','));fd.append('email_subject',E('emailSubject').value.trim());fd.append('email_message',E('emailMessage').value.trim());fd.append('send_me_copy',E('sendMeCopy').checked?'1':'0');emailFiles.forEach(function(f){fd.append('email_attachments[]',f,f.name)});var btn=E('sendEmailButton'),old=btn.textContent;btn.disabled=true;btn.textContent='Sending...';request(fd).then(function(d){toast('success',d.message||'Invoice email sent successfully.');closeEmail();load()}).catch(function(err){toast('error',err.message)}).then(function(){btn.disabled=false;btn.textContent=old})});

function openDelete(id){closeMenus();selectedDeleteInvoiceId=id;modal('deleteModal',true)}function closeDelete(){selectedDeleteInvoiceId=0;modal('deleteModal',false)}
E('deleteClose').addEventListener('click',closeDelete);E('deleteCancel').addEventListener('click',closeDelete);E('deleteModal').addEventListener('click',function(e){if(e.target===this)closeDelete()});E('deleteConfirm').addEventListener('click',function(){if(!selectedDeleteInvoiceId)return;var btn=this,old=btn.textContent;btn.disabled=true;btn.textContent='Deleting...';var fd=new FormData();fd.append('action','delete_invoice');fd.append('invoice_id',String(selectedDeleteInvoiceId));request(fd).then(function(d){toast('success',d.message||'Invoice deleted.');closeDelete();resetPage()}).catch(function(err){toast('error',err.message)}).then(function(){btn.disabled=false;btn.textContent=old})});

document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeMenus();if(E('emailModal').classList.contains('show'))closeEmail();if(E('deleteModal').classList.contains('show'))closeDelete()}});
updateFilterLabels();load();
})();
</script>
</body>
</html>
