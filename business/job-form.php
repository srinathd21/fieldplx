<?php
/* FieldPlx Job Card - Version 2.1.0 - direct job or quotation + recurring schedules, billing, attachments and checklists */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Job Card';
$activePage = 'jobs';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['jobs_csrf_token'])) {
    $_SESSION['jobs_csrf_token'] = bin2hex(random_bytes(32));
}

$jobsCsrfToken = (string)$_SESSION['jobs_csrf_token'];
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : 0;
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
$serviceId = isset($_GET['product_service_id']) ? (int)$_GET['product_service_id'] : (isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0);
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $jobId > 0 ? 'Edit Job Card' : 'Create Job Card' ?> - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>


        /* ==========================================================
           FieldPlx canonical tenant shell
           Matches the working Customers / Teams template.
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
            align-items:center;
            gap:9px;
            min-width:0;
            color:var(--fd-text)!important;
            text-decoration:none!important;
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
            max-width:170px;
            overflow:hidden;
            white-space:nowrap;
            text-overflow:ellipsis;
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
            max-width:145px;
            min-width:0;
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
            text-decoration:none!important;
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
            text-decoration:none!important;
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
            text-decoration:none!important;
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
            text-decoration:none!important;
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
            text-decoration:none!important;
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

        /* ---------- Main layout ---------- */
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

        .fd-dashboard{
            width:100%!important;
            max-width:1600px!important;
            margin:auto!important;
            padding:25px 27px 35px!important;
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
            text-decoration:none!important;
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

            .fd-dashboard{
                padding:17px 13px 28px!important;
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
           Create / Edit Job Card page
           ========================================================== */
        a,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}

        .jf-head{
            margin-bottom:18px;
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
        }
        .jf-title{margin:0;color:var(--fd-text);font-size:21px;font-weight:700;line-height:1.2}
        .jf-sub{margin:7px 0 0;max-width:900px;color:var(--fd-muted);font-size:10.5px;line-height:1.55}
        .jf-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .jf-btn{
            min-height:39px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;
            border:1px solid var(--fd-border);border-radius:8px;background:#fff;color:#43546c;
            box-shadow:0 4px 12px rgba(31,43,88,.04);font-size:10px;font-weight:700;cursor:pointer
        }
        .jf-btn:hover{border-color:#cfe3ae;background:#f9fcf4;color:var(--fd-green-dark)}
        .jf-btn.primary{border-color:var(--fd-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);box-shadow:0 7px 16px rgba(104,170,29,.17)}
        .jf-btn.primary:hover{color:#fff;background:linear-gradient(90deg,#74b824,#5d971b)}
        .jf-btn:disabled{opacity:.58;cursor:not-allowed}
        .jf-loader{width:13px;height:13px;display:none;border:2px dotted currentColor;border-radius:50%;animation:jfSpin .75s linear infinite}
        .jf-btn.loading .jf-loader{display:inline-block}.jf-btn.loading>i{display:none}@keyframes jfSpin{to{transform:rotate(360deg)}}

        .jf-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:16px;align-items:start}
        .jf-stack{display:grid;gap:16px;align-content:start}
        .jf-card{overflow:hidden;border:1px solid var(--fd-border);border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}
        .jf-card-head{min-height:54px;padding:12px 15px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--fd-border);background:#fbfcfd}
        .jf-card-icon{width:34px;height:34px;flex:0 0 34px;display:grid;place-items:center;border-radius:9px;color:var(--fd-green-dark);background:var(--fd-green-soft);font-size:14px}
        .jf-card-copy{min-width:0;flex:1}.jf-card-copy h2{margin:0;color:#17233b;font-size:12px;font-weight:700}.jf-card-copy p{margin:3px 0 0;color:var(--fd-muted);font-size:8.5px;line-height:1.45}
        .jf-card-body{padding:15px}
        .jf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
        .jf-field.full,.jf-section,.jf-assignment,.jf-note,.jf-quote-info.full{grid-column:1/-1}
        .jf-section{margin-top:3px;padding:8px 0 4px;border-bottom:1px solid #eef2f5;color:#31425b;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.02em}
        .jf-field label{display:block;margin-bottom:6px;color:#42536c;font-size:9px;font-weight:700}
        .jf-field label .req{color:#e45b66}
        .jf-field input,.jf-field select,.jf-field textarea{
            width:100%;min-height:40px;padding:8px 10px;border:1px solid #dfe5ec;border-radius:8px;outline:0;background:#fff;color:#263750;font-size:10px
        }
        .jf-field textarea{min-height:95px;resize:vertical}
        .jf-field input:focus,.jf-field select:focus,.jf-field textarea:focus{border-color:#b8d88d;box-shadow:0 0 0 3px rgba(116,184,36,.10)}
        .jf-hint{margin-top:5px;color:#8793a5;font-size:8px;line-height:1.45}
        .jf-assignment{padding:12px;border:1px solid #e4e9ef;border-radius:9px;background:#fbfcfd}
        .jf-assignment-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .jf-quote-info{display:none;width:100%;min-width:0;padding:13px;border:1px solid #dbe9c9;border-radius:10px;background:#f8fbf4}
        .jf-quote-info.show{display:block}
        .jf-info-grid{width:100%;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .jf-info{min-width:0;min-height:82px;padding:11px 12px;display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;border:1px solid #e3ebda;border-radius:9px;background:#fff}
        .jf-info span,.jf-info strong{display:block;width:100%;min-width:0}
        .jf-info span{margin:0 0 8px;color:#8793a5;font-size:8px;line-height:1.2;font-weight:700;letter-spacing:.03em;text-transform:uppercase}
        .jf-info strong{margin:0;color:#263750;font-size:10.5px;line-height:1.42;font-weight:700;overflow-wrap:break-word;word-break:normal}
        .jf-info strong.jf-info-email{font-size:9.8px;word-break:break-word}
        .jf-info strong.jf-info-money{color:#123d70;font-size:11px}
        .jf-info strong.jf-info-source{color:#5d971b}
        .jf-note{padding:11px 12px;display:flex;gap:9px;border:1px solid #dbe9c9;border-radius:9px;background:#f7fbed;color:#43546c;font-size:8.8px;line-height:1.55}
        .jf-note i{margin-top:1px;color:var(--fd-green-dark);font-size:15px}
        .jf-footer{padding:12px 15px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--fd-border);background:#fbfcfd}

        .jf-side-list{display:grid;gap:8px}.jf-side-row{padding:10px 11px;border:1px solid #e8edf2;border-radius:8px;background:#fbfcfd}.jf-side-row span,.jf-side-row strong{display:block}.jf-side-row span{margin-bottom:4px;color:#8793a5;font-size:8px;font-weight:700;text-transform:uppercase}.jf-side-row strong{color:#263750;font-size:9.5px;line-height:1.45;overflow-wrap:anywhere}
        .jf-notify-list{display:grid;gap:9px}.jf-notify-item{display:flex;gap:9px;padding:10px;border:1px solid #e8edf2;border-radius:8px;background:#fbfcfd}.jf-notify-icon{width:30px;height:30px;flex:0 0 30px;display:grid;place-items:center;border-radius:8px;color:var(--fd-green-dark);background:var(--fd-green-soft)}.jf-notify-item strong,.jf-notify-item small{display:block}.jf-notify-item strong{color:#263750;font-size:9.5px}.jf-notify-item small{margin-top:3px;color:#8793a5;font-size:8px;line-height:1.45}

        .jf-toast{width:min(320px,calc(100vw - 24px));position:fixed;top:82px;right:16px;z-index:25000;padding:9px 10px;display:flex;align-items:center;gap:8px;border-radius:7px;color:#fff;background:#123d70;box-shadow:0 10px 26px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s}
        .jf-toast.show{opacity:1;transform:translateY(0)}.jf-toast.success{background:#5d971b}.jf-toast.error{background:#e45b66}.jf-toast.warning{background:#96a52f}.jf-toast span{flex:1;font-size:8.5px;font-weight:600}

        .select2-container{width:100%!important}.select2-container .select2-selection--single{height:40px!important;border:1px solid #dfe5ec!important;border-radius:8px!important}.select2-container .select2-selection--single .select2-selection__rendered{height:38px!important;padding:0 31px 0 10px!important;display:flex!important;align-items:center!important;color:#263750!important;font-size:10px!important}.select2-container .select2-selection--single .select2-selection__arrow{height:38px!important}.select2-container .select2-selection--multiple{min-height:40px!important;border:1px solid #dfe5ec!important;border-radius:8px!important}.select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#b8d88d!important}.select2-dropdown{z-index:20000!important;border:1px solid #dfe5ec!important}.select2-results__option{font-size:9px!important}

        @media(max-width:1199.98px){.jf-layout{grid-template-columns:1fr}.jf-info-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:767.98px){.jf-head{flex-direction:column}.jf-actions{width:100%}.jf-actions .jf-btn{flex:1}.jf-grid,.jf-assignment-grid{grid-template-columns:1fr}.jf-field.full,.jf-section,.jf-assignment,.jf-note{grid-column:auto}.jf-quote-info.full{grid-column:1/-1}.jf-info-grid{grid-template-columns:1fr 1fr}.jf-footer{flex-direction:column-reverse}.jf-footer .jf-btn{width:100%}}
        @media(max-width:575.98px){.jf-info-grid{grid-template-columns:1fr}.jf-toast{top:72px;left:12px;right:12px;width:auto}}

    

        /* ---------- Expanded scheduling / billing ---------- */
        .jf-schedule-list{display:grid;gap:12px}
        .jf-schedule-card{border:1px solid #dfe6ee;border-radius:10px;background:#fbfcfd;overflow:hidden}
        .jf-schedule-head{padding:10px 12px;display:flex;align-items:center;gap:9px;border-bottom:1px solid #e7ecf1;background:#fff}
        .jf-schedule-number{width:27px;height:27px;display:grid;place-items:center;border-radius:8px;background:var(--fd-green-soft);color:var(--fd-green-dark);font-size:10px;font-weight:700}
        .jf-schedule-head strong{color:#263750;font-size:10px}.jf-schedule-head small{display:block;margin-top:2px;color:#8a96a7;font-size:8px}
        .jf-schedule-head .jf-icon-btn{margin-left:auto}
        .jf-schedule-body{padding:12px}
        .jf-schedule-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}
        .jf-schedule-grid .span-2{grid-column:span 2}.jf-schedule-grid .span-4{grid-column:1/-1}
        .jf-inline-title{margin:3px 0 -1px;grid-column:1/-1;color:#52637a;font-size:8.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
        .jf-weekdays{display:flex;flex-wrap:wrap;gap:6px}
        .jf-weekday{min-width:39px;height:31px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #dce4eb;border-radius:7px;background:#fff;color:#586a81;font-size:8px;cursor:pointer}
        .jf-weekday input{position:absolute;opacity:0;pointer-events:none}.jf-weekday:has(input:checked){border-color:#9bc967;background:var(--fd-green-soft);color:var(--fd-green-dark);font-weight:700}
        .jf-icon-btn{width:29px;height:29px;padding:0;display:grid;place-items:center;border:0;border-radius:7px;background:transparent;color:#788699;cursor:pointer}
        .jf-icon-btn:hover{background:var(--fd-green-soft);color:var(--fd-green-dark)}.jf-icon-btn.danger:hover{background:#fff0f1;color:#b9444d}
        .jf-add-schedule{margin-left:auto}
        .jf-feature-alert{margin-bottom:12px;padding:10px 11px;display:none;gap:8px;border:1px solid #f1d8a4;border-radius:9px;background:#fff9ea;color:#7f641c;font-size:8.5px;line-height:1.5}.jf-feature-alert.show{display:flex}.jf-feature-alert i{font-size:15px}
        .jf-selected-people{margin-top:8px;display:flex;flex-wrap:wrap;gap:6px}.jf-person-chip{min-height:27px;padding:5px 8px;display:inline-flex;align-items:center;gap:6px;border:1px solid #dce8cf;border-radius:999px;background:#f7fbf1;color:#3f5528;font-size:8.5px;font-weight:600}.jf-person-chip i{color:var(--fd-green-dark)}
        .jf-empty-chips{color:#98a3b2;font-size:8px}
        .jf-billing-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-bottom:13px}.jf-billing-metric{padding:11px;border:1px solid #e3e9ef;border-radius:9px;background:#fbfcfd}.jf-billing-metric span,.jf-billing-metric strong{display:block}.jf-billing-metric span{margin-bottom:5px;color:#8793a5;font-size:8px;text-transform:uppercase;font-weight:700}.jf-billing-metric strong{color:#17233b;font-size:14px}
        .jf-billing-types{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.jf-radio-card{position:relative;padding:12px 12px 12px 38px;border:1px solid #dfe6ed;border-radius:9px;background:#fff;cursor:pointer}.jf-radio-card input{position:absolute;left:13px;top:14px;width:14px;height:14px;accent-color:var(--fd-green)}.jf-radio-card strong,.jf-radio-card small{display:block}.jf-radio-card strong{font-size:9.5px;color:#273951}.jf-radio-card small{margin-top:4px;color:#7e8b9d;font-size:8px;line-height:1.5}.jf-radio-card:has(input:checked){border-color:#a8d174;background:#f9fcf5}
        .jf-source-types{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:13px}.jf-source-panel{display:none}.jf-source-panel.show{display:block}.jf-direct-preview{margin-top:12px}.jf-source-label{margin:0 0 8px;color:#718096;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
        .jf-switch-row{margin-top:11px;padding:10px 11px;display:flex;align-items:center;gap:10px;border:1px solid #e5eaf0;border-radius:9px;background:#fbfcfd}.jf-switch-row input{width:16px;height:16px;accent-color:var(--fd-green)}.jf-switch-row strong,.jf-switch-row small{display:block}.jf-switch-row strong{font-size:9px}.jf-switch-row small{margin-top:2px;color:#8a96a7;font-size:8px;line-height:1.4}
        .jf-file-drop{padding:18px;border:1px dashed #cfd9e3;border-radius:10px;background:#fbfcfd;text-align:center}.jf-file-drop i{display:block;margin-bottom:7px;color:var(--fd-green-dark);font-size:25px}.jf-file-drop strong{display:block;color:#31445e;font-size:10px}.jf-file-drop small{display:block;margin-top:4px;color:#8a96a7;font-size:8px}.jf-file-drop input{margin-top:10px;max-width:100%;font-size:9px}
        .jf-file-list{margin-top:10px;display:grid;gap:6px}.jf-file-row{padding:7px 9px;display:flex;align-items:center;gap:8px;border:1px solid #e7ecf1;border-radius:7px;background:#fff;color:#53657d;font-size:8.5px}.jf-file-row i{color:#789f31}.jf-file-row span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.jf-file-row small{margin-left:auto;color:#9aa4b3}
        .jf-checklist-select{margin-bottom:10px}.jf-capture-banner{padding:12px;display:flex;gap:10px;align-items:center;border:1px solid #dbe9c9;border-radius:9px;background:#f7fbed}.jf-capture-icon{width:36px;height:36px;flex:0 0 36px;display:grid;place-items:center;border-radius:10px;background:#fff;color:var(--fd-green-dark);font-size:17px}.jf-capture-copy{min-width:0;flex:1}.jf-capture-copy strong{display:block;color:#2d3e54;font-size:9.5px}.jf-capture-copy small{display:block;margin-top:3px;color:#7d8a9b;font-size:8px;line-height:1.45}
        .jf-checklist-builder{display:none;margin-top:11px;padding:12px;border:1px solid #e3e8ed;border-radius:10px;background:#fbfcfd}.jf-checklist-builder.show{display:block}.jf-checklist-items{margin-top:9px;display:grid;gap:7px}.jf-checklist-item{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:7px;align-items:center}.jf-checklist-item input[type=text]{height:36px}.jf-required-mini{display:flex;align-items:center;gap:5px;color:#66768a;font-size:8px;white-space:nowrap}.jf-required-mini input{width:13px;height:13px;accent-color:var(--fd-green)}
        .jf-preview-line{margin-top:10px;padding:9px 10px;border:1px solid #e2e8ee;border-radius:8px;background:#fff;color:#607188;font-size:8.5px;line-height:1.5}.jf-preview-line strong{color:#263750}
        @media(max-width:991.98px){.jf-schedule-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.jf-schedule-grid .span-4{grid-column:1/-1}}
        @media(max-width:575.98px){.jf-schedule-grid{grid-template-columns:1fr}.jf-schedule-grid .span-2,.jf-schedule-grid .span-4{grid-column:auto}.jf-billing-summary,.jf-billing-types,.jf-source-types{grid-template-columns:1fr}.jf-checklist-item{grid-template-columns:1fr auto}.jf-required-mini{grid-column:1}.jf-schedule-head{align-items:flex-start}}

    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="fd-dashboard">
                <section class="jf-head">
                    <div>
                        <h1 class="jf-title" id="pageTitle"><?= $jobId > 0 ? 'Edit Job Card' : 'Create Job Card' ?></h1>
                        <p class="jf-sub">Create a direct job or convert an approved quotation, then build one-off or recurring visits, assign employees, configure billing, and capture field requirements.</p>
                    </div>
                    <div class="jf-actions">
                        <a href="jobs" class="jf-btn"><i class="bi bi-arrow-left"></i> Back to Jobs</a>
                    </div>
                </section>

                <form id="jobForm" enctype="multipart/form-data">
                    <input type="hidden" name="schedule_json" id="scheduleJson" value="">
                    <input type="hidden" name="new_checklist_json" id="newChecklistJson" value="">
                    <input type="hidden" name="job_id" id="jobId" value="<?= (int)$jobId ?>">
                    <input type="hidden" name="request_id" id="requestId" value="<?= (int)$requestId ?>">
                    <div class="jf-layout">
                        <div class="jf-stack">
                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-signpost-split"></i></span><div class="jf-card-copy"><h2>Job Source</h2><p>Create the job directly for a customer or convert an approved quotation.</p></div></div>
                                <div class="jf-card-body">
                                    <div class="jf-source-types">
                                        <label class="jf-radio-card"><input type="radio" name="job_source" value="direct" checked><strong>Direct Job</strong><small>Create a job without an approved quotation. Select the customer, location and service directly.</small></label>
                                        <label class="jf-radio-card"><input type="radio" name="job_source" value="quotation"><strong>From Approved Quotation</strong><small>Use customer, location, service and pricing from an approved quotation.</small></label>
                                    </div>

                                    <div class="jf-source-panel" id="quotationSourcePanel">
                                        <div class="jf-grid">
                                            <div class="jf-field full">
                                                <label>Approved Quotation <span class="req">*</span></label>
                                                <select name="quote_id" id="quoteId" class="jf-select2"><option value="">Select Approved Quotation</option></select>
                                                <div class="jf-hint">Only approved quotations not already converted to an active job are available.</div>
                                            </div>
                                            <div class="jf-quote-info full" id="quoteInfo">
                                                <div class="jf-info-grid">
                                                    <div class="jf-info"><span>Customer</span><strong id="quoteCustomer">-</strong></div>
                                                    <div class="jf-info"><span>Customer Email</span><strong class="jf-info-email" id="quoteCustomerEmail">-</strong></div>
                                                    <div class="jf-info"><span>Customer Phone</span><strong id="quoteCustomerPhone">-</strong></div>
                                                    <div class="jf-info"><span>Service</span><strong id="quoteService">-</strong></div>
                                                    <div class="jf-info"><span>Quotation No.</span><strong id="quoteNumber">-</strong></div>
                                                    <div class="jf-info"><span>Quotation Total</span><strong class="jf-info-money" id="quoteTotal">-</strong></div>
                                                    <div class="jf-info"><span>Workflow</span><strong id="quoteWorkflow">-</strong></div>
                                                    <div class="jf-info"><span>Source</span><strong class="jf-info-source">Approved Quotation</strong></div>
                                                </div>
                                            </div>
                                            <div class="jf-field full" id="jobServiceWrap" style="display:none">
                                                <label>Service <span class="req">*</span></label>
                                                <select name="product_service_id" id="jobServiceId" class="jf-select2"><option value="">Select Service</option></select>
                                                <div class="jf-hint">This quotation does not contain a service. Select the service for this job card. The default workflow mapped to the selected service will be saved automatically.</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="jf-source-panel show" id="directSourcePanel">
                                        <div class="jf-grid">
                                            <div class="jf-field"><label>Customer <span class="req">*</span></label><select name="client_id" id="directClientId" class="jf-select2"><option value="">Select Customer</option></select></div>
                                            <div class="jf-field"><label>Service Location</label><select name="location_id" id="directLocationId" class="jf-select2"><option value="">Select Location</option></select><div class="jf-hint">Optional when the job is not tied to a saved service location.</div></div>
                                            <div class="jf-field"><label>Service <span class="req">*</span></label><select name="direct_product_service_id" id="directServiceId" class="jf-select2"><option value="">Select Service</option></select></div>
                                            <div class="jf-field"><label>Branch</label><select name="direct_branch_id" id="directBranchId" class="jf-select2"><option value="">Use Customer / Current Branch</option></select></div>
                                        </div>
                                        <div class="jf-quote-info show jf-direct-preview" id="directInfo">
                                            <div class="jf-info-grid">
                                                <div class="jf-info"><span>Customer</span><strong id="directCustomerName">Not selected</strong></div>
                                                <div class="jf-info"><span>Email</span><strong class="jf-info-email" id="directCustomerEmail">-</strong></div>
                                                <div class="jf-info"><span>Phone</span><strong id="directCustomerPhone">-</strong></div>
                                                <div class="jf-info"><span>Location</span><strong id="directLocationName">Not selected</strong></div>
                                                <div class="jf-info"><span>Service</span><strong id="directServiceName">Not selected</strong></div>
                                                <div class="jf-info"><span>Workflow</span><strong id="directWorkflowName">Select a service</strong></div>
                                                <div class="jf-info"><span>Branch</span><strong id="directBranchName">Current / customer branch</strong></div>
                                                <div class="jf-info"><span>Source</span><strong class="jf-info-source">Direct Job</strong></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-briefcase"></i></span><div class="jf-card-copy"><h2>Job Details</h2><p>Job title, work instructions, priority and operational state.</p></div></div>
                                <div class="jf-card-body">
                                    <div class="jf-grid">
                                        <div class="jf-field full"><label>Job Title <span class="req">*</span></label><input type="text" name="title" id="title" maxlength="190" required></div>
                                        <div class="jf-field full"><label>Description / Work Instructions</label><textarea name="description" id="description" placeholder="Describe the work to be completed, access details or technician instructions."></textarea></div>
                                        <div class="jf-field"><label>Priority</label><select name="priority" id="priority"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
                                        <div class="jf-field"><label>Status</label><select name="status" id="status"><option value="scheduled" selected>Scheduled</option><option value="active">Active</option><option value="upcoming">Upcoming</option><option value="today">Today</option><option value="in_progress">In Progress</option><option value="waiting_customer">Waiting Customer</option><option value="waiting_material">Waiting Material</option><option value="rescheduled">Rescheduled</option></select></div>
                                    </div>
                                </div>
                            </section>

                            <section class="jf-card">
                                <div class="jf-card-head">
                                    <span class="jf-card-icon"><i class="bi bi-calendar-event"></i></span>
                                    <div class="jf-card-copy"><h2>Job Schedule</h2><p>Add one or more one-off or recurring visit schedules. Each schedule can use its own team and visit instructions.</p></div>
                                    <button type="button" class="jf-btn jf-add-schedule" id="addScheduleButton"><i class="bi bi-plus-lg"></i> Add Schedule</button>
                                </div>
                                <div class="jf-card-body">
                                    <div class="jf-feature-alert" id="scheduleMigrationAlert"><i class="bi bi-exclamation-triangle"></i><div>Expanded scheduling is not installed yet. Run <strong>migration_job_recurring_schedules_v2.sql</strong> once, then refresh this page.</div></div>
                                    <div class="jf-schedule-list" id="schedulesBox"></div>
                                    <div class="jf-preview-line" id="schedulePreview"><strong>Schedule preview:</strong> Add a schedule to calculate visits.</div>
                                </div>
                            </section>

                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-credit-card"></i></span><div class="jf-card-copy"><h2>Billing &amp; Automatic Payments</h2><p>Configure how recurring visits become billable and preview the expected invoice dates.</p></div></div>
                                <div class="jf-card-body">
                                    <div class="jf-billing-summary">
                                        <div class="jf-billing-metric"><span>Total invoices</span><strong id="billingInvoiceCount">0</strong></div>
                                        <div class="jf-billing-metric"><span>First</span><strong id="billingFirstDate">-</strong></div>
                                        <div class="jf-billing-metric"><span>Last</span><strong id="billingLastDate">-</strong></div>
                                    </div>
                                    <div class="jf-section">Billing Type</div>
                                    <div class="jf-billing-types">
                                        <label class="jf-radio-card"><input type="radio" name="billing_type" value="visit_based" checked><strong>Visit based</strong><small>Each scheduled visit is billable. Visits can be listed as billable items when invoices are generated.</small></label>
                                        <label class="jf-radio-card"><input type="radio" name="billing_type" value="fixed_price"><strong>Fixed price</strong><small>Each scheduled invoice uses the same fixed amount.</small></label>
                                    </div>
                                    <div class="jf-field" id="fixedInvoiceAmountWrap" style="display:none;margin-top:11px"><label>Fixed Amount Per Invoice</label><input type="number" name="fixed_invoice_amount" id="fixedInvoiceAmount" min="0" step="0.01" placeholder="0.00"><div class="jf-hint">Leave blank to divide the approved quotation total equally across the scheduled invoices.</div></div>
                                    <label class="jf-switch-row"><input type="checkbox" name="automatic_payments_enabled" id="automaticPayments" value="1"><span><strong>Enable automatic payments</strong><small>Save this job as eligible for automatic collection. Actual charging still requires a configured payment provider and a saved customer payment method.</small></span></label>
                                </div>
                            </section>

                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-paperclip"></i></span><div class="jf-card-copy"><h2>Attach Files &amp; Photos</h2><p>Add common job documents, reference files and photos available with this job card.</p></div></div>
                                <div class="jf-card-body">
                                    <div class="jf-file-drop"><i class="bi bi-cloud-arrow-up"></i><strong>Attach files &amp; photos</strong><small>Up to 12 files per save, maximum 10 MB each. Images, PDF, Office documents and text files are supported.</small><input type="file" name="job_attachments[]" id="jobAttachments" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"></div>
                                    <div class="jf-file-list" id="selectedFileList"></div>
                                    <div class="jf-file-list" id="existingFileList"></div>
                                </div>
                            </section>

                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-list-check"></i></span><div class="jf-card-copy"><h2>Capture On-Site Details</h2><p>Attach custom-built checklists so that nothing gets missed while the team is on site.</p></div></div>
                                <div class="jf-card-body">
                                    <div class="jf-field jf-checklist-select"><label>Checklist Templates</label><select name="checklist_template_ids[]" id="checklistTemplates" multiple></select><div class="jf-hint">Select one or more reusable checklists to attach to this job.</div><div class="jf-selected-people" id="selectedChecklistNames"></div></div>
                                    <div class="jf-capture-banner"><span class="jf-capture-icon"><i class="bi bi-clipboard-check"></i></span><div class="jf-capture-copy"><strong>Create a Checklist</strong><small>Build a reusable checklist here and attach it to this job immediately.</small></div><button type="button" class="jf-btn" id="toggleChecklistBuilder"><i class="bi bi-plus-lg"></i> Create a Checklist</button></div>
                                    <div class="jf-checklist-builder" id="checklistBuilder">
                                        <div class="jf-grid">
                                            <div class="jf-field"><label>Checklist Name</label><input type="text" id="newChecklistName" maxlength="190" placeholder="e.g. AC Service Completion"></div>
                                            <div class="jf-field"><label>Description</label><input type="text" id="newChecklistDescription" maxlength="500" placeholder="Optional description"></div>
                                        </div>
                                        <div class="jf-checklist-items" id="checklistItems"></div>
                                        <div style="margin-top:9px"><button type="button" class="jf-btn" id="addChecklistItem"><i class="bi bi-plus-lg"></i> Add Checklist Item</button></div>
                                    </div>
                                </div>
                            </section>

                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-people"></i></span><div class="jf-card-copy"><h2>Default Assignment</h2><p>Select the default employee, employees, or department. Individual schedules can override this team.</p></div></div>
                                <div class="jf-card-body">
                                    <div class="jf-grid">
                                        <div class="jf-field"><label>Assignment Mode</label><select name="assignment_mode" id="assignmentMode"><option value="single_user">Single Employee</option><option value="multiple_users">Multiple Employees</option><option value="department">Department</option></select></div>
                                        <div class="jf-field"><label>Completion Rule</label><select name="assignment_completion_mode" id="completionMode"><option value="primary_only">Primary Only</option><option value="task_owner">Task Owner</option><option value="all_assignees">All Assignees</option></select></div>
                                        <div class="jf-assignment">
                                            <div class="jf-assignment-grid">
                                                <div class="jf-field" id="singleUserWrap"><label>Primary Employee <span class="req">*</span></label><select name="single_user_id" id="singleUserId" class="jf-select2"><option value="">Select Employee</option></select><div class="jf-hint">This employee becomes the primary responsible user.</div></div>
                                                <div class="jf-field" id="multiUsersWrap" style="display:none"><label>Employees <span class="req">*</span></label><select name="user_ids[]" id="userIds" class="jf-multi" multiple></select><div class="jf-hint">The first selected employee is stored as primary responsible.</div></div>
                                                <div class="jf-field" id="departmentWrap" style="display:none"><label>Department <span class="req">*</span></label><select name="department_id" id="departmentId" class="jf-select2"><option value="">Select Department</option></select><div class="jf-hint">All active service users in the department will be assigned.</div></div>
                                            </div>
                                        </div>
                                        <div class="jf-field full"><label>Selected Team Members</label><div class="jf-selected-people" id="defaultAssigneeNames"><span class="jf-empty-chips">No employees selected.</span></div></div>
                                        <div class="jf-note"><i class="bi bi-envelope-check"></i><div><strong>Email notification:</strong> after a new job card is created, every selected employee with a valid email address receives the job assignment and schedule. The linked customer also receives the job confirmation and schedule when customer email notification is allowed.</div></div>
                                    </div>
                                </div>
                                <div class="jf-footer"><a href="jobs" class="jf-btn">Cancel</a><button type="submit" class="jf-btn primary" id="saveButton"><span class="jf-loader"></span><i class="bi bi-check-lg"></i><span id="saveText"><?= $jobId > 0 ? 'Update Job Card' : 'Create Job Card' ?></span></button></div>
                            </section>
                        </div>

                        <aside class="jf-stack">
                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-info-circle"></i></span><div class="jf-card-copy"><h2>Job Card Summary</h2><p>Information that will be stored with the job.</p></div></div>
                                <div class="jf-card-body"><div class="jf-side-list">
                                    <div class="jf-side-row"><span>Source</span><strong id="sideQuote">Direct Job</strong></div>
                                    <div class="jf-side-row"><span>Customer</span><strong id="sideCustomer">Not selected</strong></div>
                                    <div class="jf-side-row"><span>Schedule</span><strong id="sideSchedule">Not scheduled</strong></div>
                                    <div class="jf-side-row"><span>Visits</span><strong id="sideVisitCount">0 scheduled visit(s)</strong></div>
                                    <div class="jf-side-row"><span>Billing</span><strong id="sideBilling">Visit based</strong></div>
                                    <div class="jf-side-row"><span>Assignment</span><strong id="sideAssignment">Single Employee</strong></div>
                                </div></div>
                            </section>
                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-bell"></i></span><div class="jf-card-copy"><h2>Notifications</h2><p>Automatic notifications after successful job creation.</p></div></div>
                                <div class="jf-card-body"><div class="jf-notify-list">
                                    <div class="jf-notify-item"><span class="jf-notify-icon"><i class="bi bi-person-badge"></i></span><div><strong>Assigned Employees</strong><small>In-app notification plus email when SMTP and employee email are available.</small></div></div>
                                    <div class="jf-notify-item"><span class="jf-notify-icon"><i class="bi bi-person-heart"></i></span><div><strong>Customer</strong><small>Email confirmation with job number, title and planned start/end schedule.</small></div></div>
                                </div></div>
                            </section>
                        </aside>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<div class="jf-toast" id="toast"><span id="toastMessage">Notification</span></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
    'use strict';
    var csrfToken = <?= json_encode($jobsCsrfToken) ?>;
    var jobId = <?= (int)$jobId ?>;
    var requestedQuoteId = <?= (int)$quoteId ?>;
    var requestedClientId = <?= (int)$clientId ?>;
    var requestedLocationId = <?= (int)$locationId ?>;
    var requestedServiceId = <?= (int)$serviceId ?>;
    var requestedRequestId = <?= (int)$requestId ?>;
    var basePath = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>;
    var apiUrl = basePath + '/api/jobs.php';
    var meta = {quotes:[],clients:[],locations:[],branches:[],users:[],departments:[],services:[],checklist_templates:[],currency:{}};
    var currentQuotation = null, existingJobServiceId = 0, toastTimer = null, scheduleSeq = 0;

    function el(id){return document.getElementById(id)}
    function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
    function notify(type,message){var t=el('toast'),m=el('toastMessage');if(toastTimer)clearTimeout(toastTimer);t.className='jf-toast '+(type||'')+' show';m.textContent=message||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},4200)}
    function loading(button,on){if(!button)return;button.disabled=!!on;button.classList.toggle('loading',!!on)}
    function parseResponse(response){return response.text().then(function(raw){var data,text=String(raw||'').trim();try{data=text?JSON.parse(text):{}}catch(e){throw new Error(text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!response.ok||!data.success)throw new Error(data.message||('Request failed with HTTP '+response.status+'.'));return data})}
    function request(fd){fd.append('csrf_token',csrfToken);return fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
    function money(value){var c=meta.currency||{},places=parseInt(c.decimal_places,10);if(isNaN(places))places=2;var n=Number(value||0).toFixed(places),sym=c.symbol||'';return c.symbol_position==='after'?n+(sym?' '+sym:''):(sym||'')+n}
    function optionRows(rows,label){var html='<option value="">'+esc(label)+'</option>';(rows||[]).forEach(function(x){html+='<option value="'+Number(x.id)+'">'+esc(x.name||'')+'</option>'});return html}
    function userById(id){id=Number(id||0);return (meta.users||[]).find(function(x){return Number(x.id)===id})||null}
    function userOptions(){return (meta.users||[]).map(function(x){return '<option value="'+Number(x.id)+'">'+esc(x.name||'')+(x.job_title?' · '+esc(x.job_title):'')+'</option>'}).join('')}
    function chipHtml(ids){ids=(ids||[]).map(Number).filter(Boolean);if(!ids.length)return '<span class="jf-empty-chips">No employees selected.</span>';return ids.map(function(id){var u=userById(id);return u?'<span class="jf-person-chip"><i class="bi bi-person-check"></i>'+esc(u.name)+'</span>':''}).join('')||'<span class="jf-empty-chips">No employees selected.</span>'}

    function initStaticSelect2(){
        $('.jf-select2').select2({width:'100%'});
        $('#userIds').select2({width:'100%',placeholder:'Select employees'});
        $('#checklistTemplates').select2({width:'100%',placeholder:'Select checklist templates'});
    }

    function setMeta(m){
        meta=m||meta;
        var q='<option value="">Select Approved Quotation</option>';
        (meta.quotes||[]).forEach(function(x){q+='<option value="'+Number(x.id)+'">'+esc(x.quote_no)+' · '+esc(x.client_name)+' · '+esc(money(x.total))+'</option>'});
        $('#quoteId').html(q);
        $('#directClientId').html(optionRows(meta.clients,'Select Customer'));
        $('#directServiceId').html(optionRows(meta.services,'Select Service'));
        $('#directBranchId').html(optionRows(meta.branches,'Use Customer / Current Branch'));
        $('#singleUserId').html(optionRows(meta.users,'Select Employee'));
        $('#userIds').html(userOptions());
        $('#departmentId').html(optionRows(meta.departments,'Select Department'));
        $('#jobServiceId').html(optionRows(meta.services,'Select Service'));
        var ch=(meta.checklist_templates||[]).map(function(x){return '<option value="'+Number(x.id)+'">'+esc(x.name)+(Number(x.item_count||0)?' · '+Number(x.item_count)+' items':'')+'</option>'}).join('');
        $('#checklistTemplates').html(ch);
        refreshScheduleUserOptions();
        el('scheduleMigrationAlert').classList.toggle('show',Number(meta.expanded_schedule_ready||0)!==1);
    }

    function sourceMode(){var x=document.querySelector('input[name="job_source"]:checked');return x?x.value:'direct'}
    function clientMeta(id){id=Number(id||0);return (meta.clients||[]).find(function(x){return Number(x.id)===id})||null}
    function locationMeta(id){id=Number(id||0);return (meta.locations||[]).find(function(x){return Number(x.id)===id})||null}
    function branchMeta(id){id=Number(id||0);return (meta.branches||[]).find(function(x){return Number(x.id)===id})||null}
    function refreshDirectLocations(selected){var cid=Number(el('directClientId').value||0),rows=(meta.locations||[]).filter(function(x){return Number(x.client_id||0)===cid});var html='<option value="">Select Location</option>';rows.forEach(function(x){html+='<option value="'+Number(x.id)+'">'+esc(x.name||'Service Location')+'</option>'});$('#directLocationId').html(html);if(selected&&rows.some(function(x){return Number(x.id)===Number(selected)}))$('#directLocationId').val(String(selected)).trigger('change.select2');else $('#directLocationId').val('').trigger('change.select2')}
    function renderDirectPreview(){var c=clientMeta(el('directClientId').value),l=locationMeta(el('directLocationId').value),sv=serviceMeta(el('directServiceId').value),b=branchMeta(el('directBranchId').value);el('directCustomerName').textContent=c?(c.name||'-'):'Not selected';el('directCustomerEmail').textContent=c?(c.email||'No email'):'-';el('directCustomerPhone').textContent=c?(c.phone||'-'):'-';el('directLocationName').textContent=l?(l.name||'Service Location'):'Not selected';el('directServiceName').textContent=sv?(sv.name||'-'):'Not selected';el('directWorkflowName').textContent=sv?(sv.workflow_id?(sv.workflow_name||'Default workflow assigned'):'No active workflow mapped'):'Select a service';el('directBranchName').textContent=b?(b.name||'-'):(c&&c.branch_name?c.branch_name:'Current / customer branch');el('sideCustomer').textContent=c?(c.name||'-'):'Not selected'}
    function applyDirectCustomer(selectedLocation){var c=clientMeta(el('directClientId').value);refreshDirectLocations(selectedLocation||0);if(c&&Number(c.branch_id||0)>0&&!el('directBranchId').value)$('#directBranchId').val(String(c.branch_id)).trigger('change.select2');renderDirectPreview()}
    function updateSourceMode(){var mode=sourceMode(),quote=mode==='quotation';el('quotationSourcePanel').classList.toggle('show',quote);el('directSourcePanel').classList.toggle('show',!quote);el('quoteId').required=quote;el('directClientId').required=!quote;el('directServiceId').required=!quote;if(!quote){currentQuotation=null;el('quoteInfo').classList.remove('show');el('jobServiceWrap').style.display='none';el('jobServiceId').required=false;el('sideQuote').textContent='Direct Job';renderDirectPreview()}else{el('sideQuote').textContent=el('quoteId').value?'Loading quotation...':'Approved Quotation';if(el('quoteId').value)quoteDetails(el('quoteId').value)}updateBillingPreview()}
    function serviceMeta(id){id=Number(id||0);return (meta.services||[]).find(function(x){return Number(x.id)===id})||null}
    function updateSelectedServicePreview(){if(!currentQuotation||Number(currentQuotation.product_service_id||0)>0)return;var service=serviceMeta(el('jobServiceId').value);if(!service){el('quoteService').textContent='Select service below';el('quoteWorkflow').textContent='Workflow will be selected from the service';return}el('quoteService').textContent=service.name||'-';el('quoteWorkflow').textContent=service.workflow_id?(service.workflow_name||'Default workflow assigned'):'No active workflow mapped'}

    function quoteDetails(id){
        if(!id){currentQuotation=null;el('quoteInfo').classList.remove('show');el('jobServiceWrap').style.display='none';el('jobServiceId').required=false;$('#jobServiceId').val('').trigger('change.select2');el('sideQuote').textContent='Approved Quotation';if(sourceMode()==='quotation')el('sideCustomer').textContent='Not selected';return Promise.resolve()}
        var fd=new FormData();fd.append('action','quote_details');fd.append('quote_id',id);fd.append('job_id',jobId||0);
        return request(fd).then(function(d){var q=d.quotation||{};currentQuotation=q;meta.currency=d.currency||meta.currency;el('quoteCustomer').textContent=q.client_name||'-';el('quoteCustomerEmail').textContent=q.client_email||'No email';el('quoteCustomerPhone').textContent=q.client_phone||'-';el('quoteTotal').textContent=money(q.total);el('quoteNumber').textContent=q.quote_no||'-';el('sideQuote').textContent=q.quote_no||'-';el('sideCustomer').textContent=q.client_name||'-';var hasService=Number(q.product_service_id||0)>0;if(hasService){el('quoteService').textContent=q.service_name||'-';el('quoteWorkflow').textContent=q.workflow_id?(q.workflow_name||'Default workflow assigned'):'No active workflow mapped';el('jobServiceWrap').style.display='none';el('jobServiceId').required=false;$('#jobServiceId').val('').trigger('change.select2')}else{el('quoteService').textContent='Select service below';el('quoteWorkflow').textContent='Workflow will be selected from the service';el('jobServiceWrap').style.display='block';el('jobServiceId').required=true;$('#jobServiceId').val(existingJobServiceId>0?String(existingJobServiceId):'').trigger('change.select2');updateSelectedServicePreview()}el('quoteInfo').classList.add('show');if(!el('title').value)el('title').value=q.title||q.request_title||'';updateBillingPreview()}).catch(function(e){notify('error',e.message);throw e})
    }

    function defaultAssignedIds(){
        var mode=el('assignmentMode').value;
        if(mode==='single_user')return el('singleUserId').value?[Number(el('singleUserId').value)]:[];
        if(mode==='multiple_users')return ($('#userIds').val()||[]).map(Number);
        if(mode==='department'){var d=Number(el('departmentId').value||0);return (meta.users||[]).filter(function(x){return Number(x.department_id||0)===d}).map(function(x){return Number(x.id)})}
        return [];
    }
    function renderDefaultNames(){el('defaultAssigneeNames').innerHTML=chipHtml(defaultAssignedIds())}
    function updateAssignment(){var m=el('assignmentMode').value;el('singleUserWrap').style.display=m==='single_user'?'block':'none';el('multiUsersWrap').style.display=m==='multiple_users'?'block':'none';el('departmentWrap').style.display=m==='department'?'block':'none';el('sideAssignment').textContent=m==='single_user'?'Single Employee':m==='multiple_users'?'Multiple Employees':'Department';renderDefaultNames()}

    function scheduleEmployeeOptions(selected){selected=(selected||[]).map(Number);return (meta.users||[]).map(function(x){return '<option value="'+Number(x.id)+'"'+(selected.indexOf(Number(x.id))>=0?' selected':'')+'>'+esc(x.name||'')+(x.job_title?' · '+esc(x.job_title):'')+'</option>'}).join('')}
    function weekdayHtml(selected){selected=(selected||[]).map(Number);var days=[['Sun',0],['Mon',1],['Tue',2],['Wed',3],['Thu',4],['Fri',5],['Sat',6]];return days.map(function(d){return '<label class="jf-weekday"><input type="checkbox" data-field="weekly-day" value="'+d[1]+'"'+(selected.indexOf(d[1])>=0?' checked':'')+'>'+d[0]+'</label>'}).join('')}
    function scheduleRow(data){
        data=data||{};scheduleSeq++;var id=scheduleSeq,repeat=data.repeat_type||'none',endMode=data.end_mode||'after_occurrences';
        return '<article class="jf-schedule-card" data-schedule-id="'+id+'">'+
            '<div class="jf-schedule-head"><span class="jf-schedule-number">'+id+'</span><div><strong>Schedule</strong><small>One-off or recurring visit plan</small></div><button type="button" class="jf-icon-btn danger" data-remove-schedule title="Remove schedule"><i class="bi bi-trash"></i></button></div>'+
            '<div class="jf-schedule-body"><div class="jf-schedule-grid">'+
            '<div class="jf-inline-title">Date &amp; Time</div>'+
            field('Start Date','<input type="date" data-field="start_date" value="'+esc(data.start_date||'')+'" required>')+
            field('Start Time','<input type="time" data-field="start_time" value="'+esc((data.start_time||'').substring(0,5))+'" required>')+
            field('End Date','<input type="date" data-field="end_date" value="'+esc(data.end_date||'')+'" required>')+
            field('End Time','<input type="time" data-field="end_time" value="'+esc((data.end_time||'').substring(0,5))+'" required>')+
            '<div class="jf-inline-title">Repeats</div>'+
            field('Repeats','<select data-field="repeat_type"><option value="none"'+(repeat==='none'?' selected':'')+'>Does not repeat</option><option value="daily"'+(repeat==='daily'?' selected':'')+'>Daily</option><option value="weekly"'+(repeat==='weekly'?' selected':'')+'>Weekly</option><option value="monthly"'+(repeat==='monthly'?' selected':'')+'>Monthly</option><option value="yearly"'+(repeat==='yearly'?' selected':'')+'>Yearly</option></select>')+
            '<div class="jf-field" data-repeat-options><label>Every</label><div style="display:grid;grid-template-columns:85px 1fr;gap:7px"><input type="number" min="1" max="365" data-field="repeat_interval" value="'+Number(data.repeat_interval||1)+'"><div class="jf-preview-line" style="margin:0;padding:10px" data-repeat-unit>day(s)</div></div></div>'+
            '<div class="jf-field span-2" data-weekly-options><label>Weekly on</label><div class="jf-weekdays">'+weekdayHtml(data.weekly_days||[])+'</div></div>'+
            '<div class="jf-field" data-end-options><label>Ends</label><select data-field="end_mode"><option value="after_occurrences"'+(endMode==='after_occurrences'?' selected':'')+'>After number of visits</option><option value="after_duration"'+(endMode==='after_duration'?' selected':'')+'>After a duration</option><option value="on_date"'+(endMode==='on_date'?' selected':'')+'>On date</option></select></div>'+
            '<div class="jf-field" data-occurrence-options><label>Ends after</label><input type="number" min="1" max="500" data-field="repeat_occurrences" value="'+Number(data.repeat_occurrences||1)+'"><div class="jf-hint">Number of scheduled visits.</div></div>'+
            '<div class="jf-field" data-duration-options><label>Ends after</label><div style="display:grid;grid-template-columns:85px 1fr;gap:7px"><input type="number" min="1" max="120" data-field="end_after_value" value="'+Number(data.end_after_value||6)+'"><select data-field="end_after_unit"><option value="days"'+(data.end_after_unit==='days'?' selected':'')+'>Days</option><option value="weeks"'+(data.end_after_unit==='weeks'?' selected':'')+'>Weeks</option><option value="months"'+(!data.end_after_unit||data.end_after_unit==='months'?' selected':'')+'>Months</option><option value="years"'+(data.end_after_unit==='years'?' selected':'')+'>Years</option></select></div></div>'+
            '<div class="jf-field" data-end-date-options><label>Ends on</label><input type="date" data-field="repeat_end_date" value="'+esc(data.repeat_end_date||'')+'"></div>'+
            '<div class="jf-inline-title">Assign &amp; Instructions</div>'+
            '<div class="jf-field span-2"><label>Team Members</label><select multiple data-field="assignee_ids" class="jf-schedule-users">'+scheduleEmployeeOptions(data.assignee_ids||[])+'</select><div class="jf-hint">Leave blank to use the Default Assignment below.</div><div class="jf-selected-people" data-selected-users></div></div>'+
            '<div class="jf-field span-2"><label>Visit Instructions</label><textarea data-field="instructions" placeholder="Access notes, technician instructions, recurring visit details...">'+esc(data.instructions||'')+'</textarea></div>'+
            '</div></div></article>';
    }
    function field(label,control){return '<div class="jf-field"><label>'+label+'</label>'+control+'</div>'}
    function addSchedule(data){el('schedulesBox').insertAdjacentHTML('beforeend',scheduleRow(data||{}));var card=el('schedulesBox').lastElementChild;enhanceScheduleCard(card);renumberSchedules();updateSchedulePreview()}
    function enhanceScheduleCard(card){
        var sel=$(card).find('.jf-schedule-users');sel.select2({width:'100%',placeholder:'Use default assignment or select team members'});sel.on('change',function(){renderScheduleNames(card);updateSchedulePreview()});
        card.querySelector('[data-remove-schedule]').addEventListener('click',function(){if(el('schedulesBox').children.length<=1){notify('warning','A job needs at least one schedule.');return}$(card).find('.jf-schedule-users').select2('destroy');card.remove();renumberSchedules();updateSchedulePreview()});
        card.querySelectorAll('input,select,textarea').forEach(function(x){x.addEventListener('change',function(){toggleScheduleFields(card);updateSchedulePreview()});x.addEventListener('input',function(){updateSchedulePreview()})});
        toggleScheduleFields(card);renderScheduleNames(card);
    }
    function refreshScheduleUserOptions(){document.querySelectorAll('.jf-schedule-card').forEach(function(card){var sel=$(card).find('.jf-schedule-users'),selected=(sel.val()||[]).map(Number);if(sel.hasClass('select2-hidden-accessible'))sel.select2('destroy');sel.html(scheduleEmployeeOptions(selected));sel.select2({width:'100%',placeholder:'Use default assignment or select team members'});sel.on('change',function(){renderScheduleNames(card);updateSchedulePreview()});renderScheduleNames(card)})}
    function renumberSchedules(){document.querySelectorAll('.jf-schedule-card').forEach(function(card,i){card.querySelector('.jf-schedule-number').textContent=i+1;card.querySelector('.jf-schedule-head strong').textContent='Schedule '+(i+1)})}
    function repeatUnit(type){return type==='daily'?'day(s)':type==='weekly'?'week(s)':type==='monthly'?'month(s)':type==='yearly'?'year(s)':'visit'}
    function toggleScheduleFields(card){var repeat=card.querySelector('[data-field="repeat_type"]').value,endMode=card.querySelector('[data-field="end_mode"]').value;card.querySelector('[data-repeat-options]').style.display=repeat==='none'?'none':'block';card.querySelector('[data-weekly-options]').style.display=repeat==='weekly'?'block':'none';card.querySelector('[data-end-options]').style.display=repeat==='none'?'none':'block';card.querySelector('[data-occurrence-options]').style.display=repeat!=='none'&&endMode==='after_occurrences'?'block':'none';card.querySelector('[data-duration-options]').style.display=repeat!=='none'&&endMode==='after_duration'?'block':'none';card.querySelector('[data-end-date-options]').style.display=repeat!=='none'&&endMode==='on_date'?'block':'none';card.querySelector('[data-repeat-unit]').textContent=repeatUnit(repeat)}
    function renderScheduleNames(card){var ids=($(card).find('.jf-schedule-users').val()||[]).map(Number);card.querySelector('[data-selected-users]').innerHTML=ids.length?chipHtml(ids):'<span class="jf-empty-chips">Uses Default Assignment.</span>'}

    function collectSchedules(){var rows=[];document.querySelectorAll('.jf-schedule-card').forEach(function(card){var val=function(name){var n=card.querySelector('[data-field="'+name+'"]');return n?n.value:''};rows.push({start_date:val('start_date'),start_time:val('start_time'),end_date:val('end_date'),end_time:val('end_time'),repeat_type:val('repeat_type')||'none',repeat_interval:Number(val('repeat_interval')||1),weekly_days:Array.prototype.slice.call(card.querySelectorAll('[data-field="weekly-day"]:checked')).map(function(x){return Number(x.value)}),end_mode:val('end_mode')||'after_occurrences',repeat_end_date:val('repeat_end_date'),repeat_occurrences:Number(val('repeat_occurrences')||1),end_after_value:Number(val('end_after_value')||6),end_after_unit:val('end_after_unit')||'months',assignee_ids:($(card).find('.jf-schedule-users').val()||[]).map(Number),instructions:val('instructions')})});return rows}
    function localDate(d,t){if(!d||!t)return null;var x=new Date(d+'T'+t+':00');return isNaN(x.getTime())?null:x}
    function addDays(d,n){var x=new Date(d.getTime());x.setDate(x.getDate()+n);return x}
    function addMonthsSafe(d,n){var day=d.getDate(),x=new Date(d.getTime());x.setDate(1);x.setMonth(x.getMonth()+n);var last=new Date(x.getFullYear(),x.getMonth()+1,0).getDate();x.setDate(Math.min(day,last));return x}
    function addYearsSafe(d,n){var day=d.getDate(),month=d.getMonth(),x=new Date(d.getTime());x.setDate(1);x.setFullYear(x.getFullYear()+n);x.setMonth(month);var last=new Date(x.getFullYear(),month+1,0).getDate();x.setDate(Math.min(day,last));return x}
    function dayKey(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')}
    function scheduleOccurrences(s){
        var start=localDate(s.start_date,s.start_time),end=localDate(s.end_date,s.end_time);if(!start||!end||end<=start)return [];
        var duration=end-start,out=[],repeat=s.repeat_type||'none',interval=Math.max(1,Number(s.repeat_interval||1)),limit=Math.min(500,Math.max(1,Number(s.repeat_occurrences||1))),endDate=s.repeat_end_date||'';
        if(s.end_mode==='after_duration'){var amount=Math.max(1,Number(s.end_after_value||1)),base=new Date(start.getTime()),until;if(s.end_after_unit==='days')until=addDays(base,amount);else if(s.end_after_unit==='weeks')until=addDays(base,amount*7);else if(s.end_after_unit==='years')until=addYearsSafe(base,amount);else until=addMonthsSafe(base,amount);endDate=dayKey(until)}
        function allowed(d){return (s.end_mode!=='on_date'&&s.end_mode!=='after_duration')||!endDate||dayKey(d)<=endDate}
        function push(d){if(!allowed(d))return false;out.push({start:new Date(d.getTime()),end:new Date(d.getTime()+duration)});return true}
        if(repeat==='none'){push(start);return out}
        if(repeat==='daily'){for(var i=0;i<500;i++){var d=addDays(start,i*interval);if(!push(d))break;if(s.end_mode!=='on_date'&&out.length>=limit)break}}
        if(repeat==='monthly'){for(var m=0;m<500;m++){var md=addMonthsSafe(start,m*interval);if(!push(md))break;if(s.end_mode!=='on_date'&&out.length>=limit)break}}
        if(repeat==='yearly'){for(var y=0;y<500;y++){var yd=addYearsSafe(start,y*interval);if(!push(yd))break;if(s.end_mode!=='on_date'&&out.length>=limit)break}}
        if(repeat==='weekly'){var days=(s.weekly_days||[]).map(Number);if(!days.length)days=[start.getDay()];var anchor=new Date(start.getFullYear(),start.getMonth(),start.getDate());var nd=anchor.getDay();var mondayOffset=nd===0?6:nd-1;anchor=addDays(anchor,-mondayOffset);for(var g=0,c=new Date(start.getTime());g<20000&&out.length<500;g++,c=addDays(c,1)){if(!allowed(c))break;var cm=new Date(c.getFullYear(),c.getMonth(),c.getDate()),week=Math.floor((cm-anchor)/(7*86400000));if(week%interval===0&&days.indexOf(c.getDay())>=0){push(c);if(s.end_mode!=='on_date'&&out.length>=limit)break}}}
        return out
    }
    function allOccurrences(){var all=[];collectSchedules().forEach(function(s){all=all.concat(scheduleOccurrences(s))});all.sort(function(a,b){return a.start-b.start});return all.slice(0,500)}
    function humanDate(d){if(!d)return '-';return d.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'})}
    function updateSchedulePreview(){var schedules=collectSchedules(),occ=allOccurrences();if(!occ.length){el('schedulePreview').innerHTML='<strong>Schedule preview:</strong> Complete the date/time fields to calculate visits.';el('sideSchedule').textContent='Not scheduled';el('sideVisitCount').textContent='0 scheduled visit(s)'}else{el('schedulePreview').innerHTML='<strong>Schedule preview:</strong> '+occ.length+' visit(s) from '+esc(humanDate(occ[0].start))+' to '+esc(humanDate(occ[occ.length-1].start))+'. '+schedules.length+' schedule definition(s).';el('sideSchedule').textContent=humanDate(occ[0].start)+' → '+humanDate(occ[occ.length-1].end);el('sideVisitCount').textContent=occ.length+' scheduled visit(s)'}updateBillingPreview(occ)}
    function validateSchedules(){var rows=collectSchedules();if(!rows.length){notify('warning','Add at least one job schedule.');return false}for(var i=0;i<rows.length;i++){var s=rows[i],start=localDate(s.start_date,s.start_time),end=localDate(s.end_date,s.end_time);if(!start||!end){notify('warning','Schedule '+(i+1)+': enter the start and end date/time.');return false}if(end<=start){notify('warning','Schedule '+(i+1)+': end date/time must be after start date/time.');return false}if(s.repeat_type!=='none'&&s.end_mode==='on_date'&&!s.repeat_end_date){notify('warning','Schedule '+(i+1)+': select when the recurrence ends.');return false}if(s.repeat_type==='weekly'&&!s.weekly_days.length){notify('warning','Schedule '+(i+1)+': choose at least one weekday.');return false}}var occ=allOccurrences();if(!occ.length){notify('warning','The schedule does not create any visits.');return false}if(occ.length>=500){notify('warning','The schedule is limited to 500 visits. Reduce the recurrence range.');return false}el('scheduleJson').value=JSON.stringify(rows);return true}

    function billingType(){var x=document.querySelector('input[name="billing_type"]:checked');return x?x.value:'visit_based'}
    function updateBillingPreview(precomputed){var occ=precomputed||allOccurrences(),type=billingType();el('billingInvoiceCount').textContent=occ.length||0;el('billingFirstDate').textContent=occ.length?humanDate(occ[0].start):'-';el('billingLastDate').textContent=occ.length?humanDate(occ[occ.length-1].start):'-';el('fixedInvoiceAmountWrap').style.display=type==='fixed_price'?'block':'none';el('sideBilling').textContent=(type==='fixed_price'?'Fixed price':'Visit based')+(occ.length?' · '+occ.length+' invoice(s)':'');if(type==='fixed_price'&&!el('fixedInvoiceAmount').value&&occ.length&&currentQuotation&&Number(currentQuotation.total||0)>0){el('fixedInvoiceAmount').placeholder=money(Number(currentQuotation.total)/occ.length)}}

    function renderChecklistNames(){var ids=($('#checklistTemplates').val()||[]).map(Number);if(!ids.length){el('selectedChecklistNames').innerHTML='<span class="jf-empty-chips">No checklist selected.</span>';return}el('selectedChecklistNames').innerHTML=ids.map(function(id){var x=(meta.checklist_templates||[]).find(function(t){return Number(t.id)===id});return x?'<span class="jf-person-chip"><i class="bi bi-list-check"></i>'+esc(x.name)+'</span>':''}).join('')}
    function addChecklistItemRow(item){item=item||{};el('checklistItems').insertAdjacentHTML('beforeend','<div class="jf-checklist-item"><input type="text" data-check-title maxlength="255" placeholder="Checklist item" value="'+esc(item.title||'')+'"><label class="jf-required-mini"><input type="checkbox" data-check-required '+(item.required?'checked':'')+'> Required</label><button type="button" class="jf-icon-btn danger" data-remove-check><i class="bi bi-trash"></i></button></div>');var row=el('checklistItems').lastElementChild;row.querySelector('[data-remove-check]').onclick=function(){row.remove()}}
    function serializeNewChecklist(){var builder=el('checklistBuilder');if(!builder.classList.contains('show')){el('newChecklistJson').value='';return true}var name=el('newChecklistName').value.trim();if(!name){notify('warning','Enter a name for the new checklist or close the checklist builder.');return false}var items=[];el('checklistItems').querySelectorAll('.jf-checklist-item').forEach(function(row){var title=row.querySelector('[data-check-title]').value.trim();if(title)items.push({title:title,required:row.querySelector('[data-check-required]').checked?1:0})});if(!items.length){notify('warning','Add at least one item to the new checklist.');return false}el('newChecklistJson').value=JSON.stringify({name:name,description:el('newChecklistDescription').value.trim(),items:items});return true}

    function renderSelectedFiles(){var files=el('jobAttachments').files||[],html='';for(var i=0;i<files.length;i++){html+='<div class="jf-file-row"><i class="bi bi-paperclip"></i><span>'+esc(files[i].name)+'</span><small>'+Math.max(1,Math.round(files[i].size/1024))+' KB</small></div>'}el('selectedFileList').innerHTML=html}
    function renderExistingFiles(rows){if(!rows||!rows.length){el('existingFileList').innerHTML='';return}el('existingFileList').innerHTML='<div class="jf-section" style="margin-top:3px">Already Attached</div>'+rows.map(function(f){return '<div class="jf-file-row"><i class="bi bi-'+(String(f.file_mime||'').indexOf('image/')===0?'image':'file-earmark')+'"></i><span>'+esc(f.file_name||'File')+'</span><small>'+esc(f.attachment_type||'file')+'</small></div>'}).join('')}

    function loadMeta(){var fd=new FormData();fd.append('action','meta');fd.append('job_id',jobId||0);return request(fd).then(function(d){setMeta(d.meta||{})})}
    function legacySchedule(r){if(!r.start_date||!r.start_time||!r.end_date||!r.end_time)return null;return {start_date:r.start_date,start_time:r.start_time,end_date:r.end_date,end_time:r.end_time,repeat_type:r.job_type==='recurring'?'daily':'none',repeat_interval:1,end_mode:'after_occurrences',repeat_occurrences:1,weekly_days:[],instructions:''}}
    function loadExisting(){
        if(jobId<=0){
            addSchedule({});
            if(requestedQuoteId>0){document.querySelector('input[name="job_source"][value="quotation"]').checked=true;updateSourceMode();$('#quoteId').val(String(requestedQuoteId)).trigger('change.select2');return quoteDetails(requestedQuoteId)}
            document.querySelector('input[name="job_source"][value="direct"]').checked=true;updateSourceMode();
            if(requestedClientId>0){$('#directClientId').val(String(requestedClientId)).trigger('change.select2');applyDirectCustomer(requestedLocationId);if(requestedServiceId>0)$('#directServiceId').val(String(requestedServiceId)).trigger('change.select2');renderDirectPreview()}
            return Promise.resolve();
        }
        var fd=new FormData();fd.append('action','get');fd.append('job_id',jobId);
        return request(fd).then(function(d){var r=d.job||{},a=d.assignments||[],schedules=d.schedules||[],billing=d.billing||null;setMeta(d.meta||meta);existingJobServiceId=Number(r.product_service_id||0);el('pageTitle').textContent='Edit '+(r.job_no||'Job Card');el('saveText').textContent='Update Job Card';el('jobId').value=r.id||jobId;el('requestId').value=r.request_id||requestedRequestId||0;el('title').value=r.title||'';el('description').value=r.description||'';el('priority').value=r.priority||'normal';el('status').value=r.status||'scheduled';el('completionMode').value=r.assignment_completion_mode||'primary_only';if(r.assignment_mode==='single_user'){el('assignmentMode').value='single_user';var x=a.find(function(z){return z.user_id});$('#singleUserId').val(x?String(x.user_id):'').trigger('change.select2')}else{el('assignmentMode').value='multiple_users';$('#userIds').val(a.filter(function(z){return z.user_id}).map(function(z){return String(z.user_id)})).trigger('change')}
            updateAssignment();el('schedulesBox').innerHTML='';scheduleSeq=0;if(schedules.length){schedules.forEach(function(x){addSchedule(x)})}else{var legacy=legacySchedule(r);addSchedule(legacy||{})}
            if(billing){$('input[name="billing_type"][value="'+billing.billing_type+'"]').prop('checked',true);el('automaticPayments').checked=Number(billing.automatic_payments_enabled||0)===1;el('fixedInvoiceAmount').value=billing.fixed_invoice_amount||''}
            $('#checklistTemplates').val((d.checklist_template_ids||[]).map(String)).trigger('change');renderChecklistNames();renderExistingFiles(d.attachments||[]);
            if(Number(r.quote_id||0)>0){document.querySelector('input[name="job_source"][value="quotation"]').checked=true;updateSourceMode();$('#quoteId').val(String(r.quote_id)).trigger('change.select2');updateBillingPreview();return quoteDetails(r.quote_id)}
            document.querySelector('input[name="job_source"][value="direct"]').checked=true;updateSourceMode();$('#directClientId').val(String(r.client_id||'')).trigger('change.select2');applyDirectCustomer(r.location_id||0);$('#directServiceId').val(String(r.product_service_id||'')).trigger('change.select2');if(r.branch_id)$('#directBranchId').val(String(r.branch_id)).trigger('change.select2');renderDirectPreview();updateBillingPreview();return Promise.resolve()})
    }

    el('assignmentMode').addEventListener('change',updateAssignment);
    $('#singleUserId,#userIds,#departmentId').on('change',function(){renderDefaultNames();updateSchedulePreview()});
    document.querySelectorAll('input[name="job_source"]').forEach(function(x){x.addEventListener('change',updateSourceMode)});
    el('quoteId').addEventListener('change',function(){existingJobServiceId=0;if(sourceMode()==='quotation')quoteDetails(this.value)});
    $('#directClientId').on('change',function(){applyDirectCustomer(0)});
    $('#directLocationId,#directServiceId,#directBranchId').on('change',renderDirectPreview);
    el('jobServiceId').addEventListener('change',updateSelectedServicePreview);
    el('addScheduleButton').addEventListener('click',function(){addSchedule({})});
    document.querySelectorAll('input[name="billing_type"]').forEach(function(x){x.addEventListener('change',updateBillingPreview)});
    el('jobAttachments').addEventListener('change',renderSelectedFiles);
    $('#checklistTemplates').on('change',renderChecklistNames);
    el('toggleChecklistBuilder').addEventListener('click',function(){el('checklistBuilder').classList.toggle('show');if(el('checklistBuilder').classList.contains('show')&&!el('checklistItems').children.length)addChecklistItemRow({})});
    el('addChecklistItem').addEventListener('click',function(){addChecklistItemRow({})});

    el('jobForm').addEventListener('submit',function(e){e.preventDefault();if(!this.reportValidity()){notify('warning','Complete all required job card fields.');return}if(Number(meta.expanded_schedule_ready||0)!==1){notify('error','Run migration_job_recurring_schedules_v2.sql before saving this job.');return}var source=sourceMode();if(source==='quotation'){if(!el('quoteId').value){notify('warning','Select an approved quotation.');return}if(currentQuotation&&Number(currentQuotation.product_service_id||0)<=0&&!el('jobServiceId').value){notify('warning','Select a service for this quotation.');return}}else{if(!el('directClientId').value){notify('warning','Select a customer for the direct job.');return}if(!el('directServiceId').value){notify('warning','Select a service for the direct job.');return}}var mode=el('assignmentMode').value;if(mode==='single_user'&&!el('singleUserId').value){notify('warning','Select an employee.');return}if(mode==='multiple_users'&&!($('#userIds').val()||[]).length){notify('warning','Select at least one employee.');return}if(mode==='department'&&!el('departmentId').value){notify('warning','Select a department.');return}if(!validateSchedules()||!serializeNewChecklist())return;var fd=new FormData(this);fd.set('schedule_json',el('scheduleJson').value);fd.set('new_checklist_json',el('newChecklistJson').value);fd.append('action','save');var b=el('saveButton');loading(b,true);request(fd).then(function(d){var n=d.notifications||{},msg=d.message||'Job saved.';if(d.attachments&&Number(d.attachments.saved||0)>0)msg+=' '+Number(d.attachments.saved)+' attachment(s) saved.';notify(Number(n.email_failed||0)>0?'warning':'success',msg);setTimeout(function(){window.location.href='jobs'},1300)}).catch(function(err){notify('error',err.message)}).finally(function(){loading(b,false)})});

    initStaticSelect2();updateAssignment();renderChecklistNames();
    loadMeta().then(loadExisting).catch(function(e){notify('error',e.message);if(!el('schedulesBox').children.length)addSchedule({})});
})();
</script>
</body>
</html>