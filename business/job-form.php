<?php
/* FieldPlx Create Job - Version 2.9.0 - separate location/job details + custom label/value fields */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Create Job';
$activePage = 'jobs';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['jobs_csrf_token'])) { $_SESSION['jobs_csrf_token'] = bin2hex(random_bytes(32)); }
$jobsCsrfToken = (string)$_SESSION['jobs_csrf_token'];
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : 0;
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
$serviceId = isset($_GET['product_service_id']) ? (int)$_GET['product_service_id'] : (isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0);
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $jobId > 0 ? 'Edit Job' : 'New Job' ?> - FieldPlx</title>
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




:root{--jb-green:#2f8b27;--jb-green-dark:#24751f;--jb-navy:#092d3d;--jb-text:#183548;--jb-muted:#667b8d;--jb-line:#dbe2e7;--jb-soft:#f7f8f8;--jb-canvas:#fff;--fieldplx-sidebar-width:250px;--fieldplx-sidebar-collapsed-width:78px}
*{box-sizing:border-box}body{margin:0;background:#f6f8fb;color:var(--jb-text);font-family:Arial,Helvetica,sans-serif;font-size:14px}.fieldplx-content-wrapper{padding:0!important}.fieldplx-main-content{min-width:0}.jb-page{max-width:none;margin:0;background:#fff;min-height:calc(100vh - 70px);padding:18px 20px 94px}.jb-head{display:flex;align-items:center;gap:13px;margin:3px 0 18px;font-size:21px;font-weight:700;color:#082b3d}.jb-head i{color:var(--jb-green);font-size:18px}.jb-top{border-bottom:1px solid var(--jb-line);padding-bottom:24px;margin-bottom:27px}.jb-control,.jb-select2 .select2-selection{border:1px solid var(--jb-line)!important;border-radius:7px!important;background:#fff!important;color:var(--jb-text)!important;box-shadow:none!important}.jb-control{width:100%;height:42px;padding:8px 13px;outline:0;font-size:13px}.jb-control:focus{border-color:#8da8b5!important;box-shadow:0 0 0 2px rgba(23,65,84,.08)!important}.jb-title-input{height:43px;margin-bottom:14px}.jb-top-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.jb-top-right{display:grid;grid-template-columns:180px 1fr;align-items:center;row-gap:0}.jb-row-label{height:42px;display:flex;align-items:center;border-bottom:1px solid var(--jb-line);color:#557083;font-size:12px}.jb-top-right .jb-control,.jb-top-right .select2-container{margin-bottom:7px}.jb-customize{height:35px;display:flex;align-items:center;color:#557083;font-size:12px}.jb-add-field{margin-left:18px;border:1px solid var(--jb-line);background:#fff;border-radius:7px;padding:6px 12px;color:var(--jb-green-dark);font-weight:700;font-size:11px}.jb-section-card{border:1px solid var(--jb-line);border-radius:8px;background:#fff;padding:20px;margin:0 0 22px}.jb-section-title{font-size:18px;font-weight:700;margin:0 0 17px;color:#0b3142}.jb-schedule-bar{border:1px solid var(--jb-line);border-radius:7px;padding:11px 14px;display:flex;align-items:center;gap:16px;min-height:72px}.jb-schedule-label{font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:5px}.jb-tabs{display:flex}.jb-tab{height:34px;padding:0 16px;border:1px solid var(--jb-line);background:#fff;color:#315166;font-weight:600;font-size:12px;cursor:pointer}.jb-tab:first-child{border-radius:7px 0 0 7px}.jb-tab:last-child{border-radius:0 7px 7px 0;margin-left:-1px}.jb-tab.active{border-color:#6eb255;color:#3b822d;background:#fff;position:relative;z-index:1}.jb-schedule-summary{display:flex;gap:25px;align-items:center;color:#35556a;font-size:13px}.jb-create-visits{margin-left:auto;background:var(--jb-green);color:#fff;border:0;border-radius:6px;padding:8px 13px;font-weight:700;font-size:11px}.jb-visits{display:grid;gap:16px;margin-top:17px}.jb-visit{border:1px solid var(--jb-line);border-radius:8px;display:grid;grid-template-columns:108px 1fr;overflow:hidden}.jb-date-rail{padding:15px 13px;border-right:1px solid var(--jb-line);font-size:12px;color:#4d697b}.jb-date-rail strong{display:block;font-size:20px;color:#12384b;margin-top:3px}.jb-visit-main{padding:13px 15px}.jb-visit-title-row{display:grid;grid-template-columns:1fr 30px;gap:8px;align-items:start}.jb-visit-title-row input{height:42px}.jb-more{border:0;background:transparent;font-size:18px;color:#35556a;cursor:pointer}.jb-hint{font-size:11px;color:#4d697b;margin:7px 0 14px}.jb-hint a{color:var(--jb-green-dark);text-decoration:underline}.jb-field-box{position:relative;margin-bottom:10px}.jb-floating{position:absolute;top:5px;left:13px;font-size:9px;color:#61798a;z-index:2}.jb-field-box.with-label .jb-control{padding-top:15px}.jb-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.jb-checkline{display:flex;align-items:center;gap:7px;font-size:12px;color:#35556a;margin:6px 0 11px}.jb-checkline input{width:15px;height:15px}.jb-assign{height:43px}.jb-textarea{height:78px;resize:vertical;padding-top:12px}.jb-capture{display:flex;align-items:flex-start;gap:13px;margin:17px 0 6px}.jb-capture-icon{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:#f0f3f3;color:#264c5f;font-size:18px}.jb-capture-copy strong{display:block;text-transform:uppercase;font-size:11px;color:#183e50}.jb-capture-copy span{display:block;margin-top:4px;font-size:12px;color:#4f687a}.jb-link-btn{border:0;background:transparent;padding:0;margin-top:12px;color:var(--jb-green-dark);text-decoration:underline;font-weight:700;font-size:12px;cursor:pointer}.jb-add-visit{margin-top:13px;border:1px solid var(--jb-line);background:#fff;color:#21495c;border-radius:6px;padding:7px 11px;font-size:11px}.jb-recurring-box{display:none;border:1px solid var(--jb-line);border-radius:8px;padding:14px;margin-top:17px}.jb-recurring-box.show{display:block}.jb-rec-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.jb-rec-grid .full{grid-column:1/-1}.jb-radio-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.jb-radio-panel{display:flex;align-items:center;gap:8px;font-size:12px}.jb-radio-panel input{accent-color:var(--jb-green)}.jb-end-grid{display:grid;grid-template-columns:105px 1fr 1.25fr;gap:8px}.jb-billing-meta{display:flex;gap:16px;font-size:12px;color:#4d697b;margin-bottom:18px}.jb-billing-main{display:grid;grid-template-columns:1fr 1fr;gap:20px}.jb-billing-types label{display:block;margin:8px 0;color:#294b5d;font-size:12px}.jb-billing-types input{accent-color:var(--jb-green);margin-right:8px}.jb-billing-types small{display:block;margin:3px 0 0 23px;color:#718493;font-size:10px}.jb-paybox{background:#eeece7;border-radius:7px;padding:15px 18px;min-height:135px}.jb-paybox h4{margin:0 0 12px;font-size:14px}.jb-paybox p{font-size:12px;color:#3b596b;line-height:1.45;margin:0 0 12px}.jb-paybox button{background:#153e4e;border:0;color:#fff;border-radius:6px;padding:9px 14px;font-weight:700;font-size:11px}.jb-line-table{border:1px solid var(--jb-line);border-radius:8px;overflow:hidden}.jb-line-row{display:grid;grid-template-columns:2.7fr .9fr .9fr .9fr .9fr 32px;gap:8px;padding:8px 10px;align-items:start}.jb-line-row+.jb-line-row{border-top:1px solid var(--jb-line)}.jb-line-row .jb-control{height:43px}.jb-line-row textarea{grid-column:1/-2;height:92px}.jb-money-box{height:43px;border:1px solid var(--jb-line);border-radius:7px;padding:5px 10px}.jb-money-box small{display:block;font-size:9px;color:#61798a}.jb-money-box strong{font-size:12px;font-weight:500}.jb-add-line{margin:14px 0;border:0;background:var(--jb-green);color:#fff;border-radius:6px;padding:8px 12px;font-weight:700;font-size:11px}.jb-totals{border-top:2px solid var(--jb-line);display:grid;grid-template-columns:1fr 50%;padding:25px 10px 8px}.jb-total-grid{grid-column:2;display:grid;gap:0}.jb-total-row{display:flex;justify-content:space-between;align-items:center;min-height:43px;border-bottom:1px solid var(--jb-line);font-size:12px}.jb-total-row a{color:var(--jb-green-dark);text-decoration:underline;font-weight:700}.jb-total-row.grand{font-size:14px;font-weight:700}.jb-notes-title{font-size:20px;font-weight:700;margin:16px 0 12px}.jb-note-box{height:190px;border:1px dashed #cfd9df;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;cursor:text}.jb-note-box i{width:45px;height:45px;border-radius:50%;display:grid;place-items:center;background:#f7f7f5;color:#294d5e;font-size:20px}.jb-note-box textarea{width:100%;height:100%;border:0;outline:0;resize:vertical;padding:18px;background:transparent;color:#294b5d}.jb-note-placeholder{font-size:12px;color:#4f687a}.jb-sticky{position:fixed;bottom:0;left:var(--fieldplx-sidebar-width);right:0;z-index:1040;height:63px;border-top:1px solid var(--jb-line);background:#fff;display:flex;align-items:center;justify-content:flex-end;padding:9px 24px;box-shadow:0 -3px 10px rgba(0,0,0,.03)}body.fieldplx-sidebar-collapsed .jb-sticky{left:var(--fieldplx-sidebar-collapsed-width)}.jb-cancel{border:1px solid var(--jb-line);background:#fff;color:#3a7a2e;border-radius:7px;padding:9px 15px;font-weight:700;font-size:11px}.jb-save-group{display:flex;margin-left:8px}.jb-save{border:0;background:var(--jb-green);color:#fff;padding:10px 15px;font-weight:700;font-size:11px;border-radius:7px 0 0 7px}.jb-save-caret{width:36px;border:0;border-left:1px solid rgba(255,255,255,.45);background:var(--jb-green);color:#fff;border-radius:0 7px 7px 0}.jb-save:disabled,.jb-save-caret:disabled{opacity:.6}.jb-hidden{display:none!important}.select2-container{width:100%!important}.select2-container .select2-selection--single{height:42px!important;border:1px solid var(--jb-line)!important;border-radius:7px!important}.select2-container .select2-selection--single .select2-selection__rendered{height:40px!important;display:flex!important;align-items:center!important;padding-left:13px!important;font-size:12px;color:#294b5d!important}.select2-container .select2-selection--multiple{min-height:42px!important;border:1px solid var(--jb-line)!important;border-radius:7px!important}.select2-dropdown{z-index:22000!important}.jb-toast{position:fixed;top:82px;right:18px;z-index:35000;padding:10px 13px;border-radius:7px;background:#173e4f;color:#fff;opacity:0;transform:translateY(-7px);transition:.2s;pointer-events:none;font-size:12px}.jb-toast.show{opacity:1;transform:translateY(0)}.jb-toast.success{background:#2f8b27}.jb-toast.error{background:#c64a4a}.jb-toast.warning{background:#9a7d23}
/* checklist modal */.jb-modal{position:fixed;inset:0;z-index:30000;background:rgba(7,31,49,.35);display:none;align-items:center;justify-content:center;padding:16px}.jb-modal.show{display:flex}.jb-dialog{width:min(1450px,98vw);height:min(860px,96vh);background:#fff;display:flex;flex-direction:column;overflow:hidden;border-radius:8px}.jb-modal-head{height:66px;border-bottom:1px solid var(--jb-line);display:flex;align-items:center;padding:0 20px;gap:12px}.jb-modal-head h2{font-size:20px;margin:0}.jb-modal-actions{margin-left:auto;display:flex;gap:8px}.jb-modal-body{min-height:0;flex:1;display:grid;grid-template-columns:1fr 330px;background:#efede8}.jb-builder-canvas{overflow:auto;padding:14px}.jb-builder-paper{max-width:900px;margin:auto;background:#fff;border-radius:8px;padding:13px}.jb-builder-section{border:1px solid var(--jb-line);border-radius:7px;overflow:hidden;margin-bottom:12px}.jb-builder-sec-head{display:flex;align-items:center;padding:10px;border-bottom:1px solid var(--jb-line)}.jb-builder-sec-head input{border:0;outline:0;font-weight:700;color:#183548;flex:1}.jb-builder-question{padding:10px 12px;background:#fff}.jb-builder-question+.jb-builder-question{border-top:1px solid #eef0f1}.jb-q-top{display:grid;grid-template-columns:1fr 190px 32px;gap:8px}.jb-q-top input,.jb-q-top select,.jb-option-row input,.jb-builder-side input{height:39px;border:1px solid var(--jb-line);border-radius:7px;padding:8px 10px}.jb-options{padding:8px 0 0 30px;display:grid;gap:7px}.jb-option-row{display:grid;grid-template-columns:22px 1fr 32px;gap:7px;align-items:center}.jb-q-foot{display:flex;align-items:center;gap:10px;margin-top:8px;font-size:11px}.jb-builder-side{background:#fff;border-left:1px solid var(--jb-line);padding:18px;overflow:auto}.jb-builder-side h3{margin:0 0 16px;font-size:16px}.jb-palette{display:grid;gap:7px}.jb-palette button{border:0;background:#fff;border-radius:6px;display:flex;align-items:center;gap:10px;padding:6px;cursor:pointer;color:#294b5d}.jb-palette button:hover{background:#f0f1ef}.jb-palette i{width:34px;height:34px;border-radius:7px;background:#efeee9;display:grid;place-items:center;font-size:16px}.jb-icon-btn{width:32px;height:32px;border:0;background:#fff;border-radius:6px;cursor:pointer}.jb-icon-btn:hover{background:#f4f5f5}.jb-modal-foot{height:60px;border-top:1px solid var(--jb-line);display:flex;align-items:center;justify-content:flex-end;padding:0 18px;gap:8px;background:#fff}
@media(max-width:991.98px){.jb-page{padding-left:12px;padding-right:12px}.jb-top-grid,.jb-billing-main,.jb-rec-grid{grid-template-columns:1fr}.jb-top-right{grid-template-columns:120px 1fr}.jb-line-row{grid-template-columns:1.7fr .7fr .8fr .8fr .8fr 32px}.jb-sticky{left:0}.jb-modal-body{grid-template-columns:1fr}.jb-builder-side{display:none}}
@media(max-width:700px){.jb-visit{grid-template-columns:1fr}.jb-date-rail{border-right:0;border-bottom:1px solid var(--jb-line)}.jb-two,.jb-radio-row{grid-template-columns:1fr}.jb-line-row{grid-template-columns:1fr 1fr}.jb-line-row textarea{grid-column:1/-1}.jb-line-row .jb-line-remove{grid-column:2}.jb-totals{grid-template-columns:1fr}.jb-total-grid{grid-column:1}.jb-schedule-summary{display:none}.jb-top-right{grid-template-columns:100px 1fr}}

/* Create Job refinements */
.jb-page{width:100%;max-width:none!important;padding:20px 22px 90px!important;background:#fff!important;color:var(--fd-text,#0b1933)}
.jb-head{min-height:42px!important;padding:0 4px 14px!important;border-bottom:1px solid #e3e8ee!important;display:flex!important;align-items:center!important;gap:12px!important;color:#102b42!important;font-size:19px!important;font-weight:700!important}
.jb-head>i{color:#4d9822!important;font-size:17px!important}
.jb-top{padding:0 0 28px!important;border-bottom:1px solid #dfe5eb!important;background:#fff!important}
.jb-title-input{height:42px!important;margin-top:0!important;border-radius:7px!important}
.jb-top-grid{margin-top:14px!important}
.jb-control,.jb-field-box,.select2-container--default .select2-selection--single,.select2-container--default .select2-selection--multiple{border-color:#d7e0e8!important;border-radius:7px!important;box-shadow:none!important}
.jb-control:focus,.select2-container--default.select2-container--focus .select2-selection--single,.select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#83b754!important;box-shadow:0 0 0 2px rgba(116,184,36,.11)!important}
.jb-add-field,.jb-link-btn,.jb-add-line,.jb-add-visit{color:#3d851f!important}
.jb-job-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px;padding-top:14px;border-top:1px solid #edf0f3}.jb-job-details-title{grid-column:1/-1;color:#557083;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.45px}.jb-custom-fields{display:grid;gap:9px;margin-top:14px;padding-top:14px;border-top:1px solid #edf0f3}.jb-custom-fields:empty{display:none}.jb-custom-field{display:grid;grid-template-columns:minmax(150px,.75fr) minmax(220px,1.25fr) 34px;gap:8px;align-items:center}.jb-custom-field .jb-control{height:40px}.jb-custom-remove{width:34px;height:34px;border:1px solid #e2e7ec;border-radius:7px;background:#fff;color:#b24949;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.jb-custom-remove:hover{background:#fff4f4;border-color:#efcaca}.jb-custom-empty-note{color:#7a8b98;font-size:11px}
.jb-section-card{margin-top:24px!important;border:1px solid #dce4eb!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important}
.jb-section-title{margin:0!important;padding:20px 20px 8px!important;color:#0d2c45!important;font-size:18px!important;font-weight:700!important}
.jb-schedule-bar{margin:8px 20px 20px!important;border:1px solid #dce4eb!important;border-radius:7px!important;background:#fff!important}
.jb-tab.active,.jb-create-visits,.jb-add-line,.jb-save{border-color:#348f24!important;background:#348f24!important;color:#fff!important}
.jb-tab.active{background:#f9fff5!important;color:#3a8620!important;border-color:#62a448!important}
.jb-create-visits:hover,.jb-add-line:hover,.jb-save:hover{background:#2d7b20!important}
.jb-visit,.jb-recurring-box{margin:0 20px 20px!important;border:1px solid #dce4eb!important;border-radius:7px!important;background:#fff!important}
.jb-billing-main{padding:10px 20px 20px!important}.jb-billing-meta{padding:2px 20px 8px!important}.jb-paybox{background:#f4f2ed!important;border-radius:7px!important}.jb-paybox button{display:none!important}.jb-auto-pay-toggle{display:inline-flex;align-items:center;gap:8px;margin-top:12px;padding:9px 12px;border:1px solid #d7dfd1;border-radius:7px;background:#fff;color:#21425b;font-size:11px;font-weight:700}.jb-auto-pay-toggle input{accent-color:#5d971b}
.jb-line-table{padding:10px 20px 20px!important}.jb-line-row{border-top:0!important}.jb-notes-title{margin:20px 0 8px!important;color:#0d2c45!important;font-size:18px!important}.jb-note-box{min-height:150px!important;border:1px dashed #ccd7e1!important;border-radius:8px!important;background:#fff!important}
.jb-sticky{left:var(--fieldplx-sidebar-width)!important;z-index:1035!important;background:rgba(255,255,255,.98)!important}.fieldplx-sidebar-collapsed~* .jb-sticky,body.fieldplx-sidebar-collapsed .jb-sticky{left:var(--fieldplx-sidebar-collapsed-width)!important}
@media(max-width:991.98px){.jb-page{padding:16px 14px 86px!important}.jb-sticky,body.fieldplx-sidebar-collapsed .jb-sticky{left:0!important}.jb-job-details{grid-template-columns:1fr}.jb-custom-field{grid-template-columns:minmax(130px,.7fr) minmax(180px,1.3fr) 34px}}


/* v2.7 functional controls */
.jb-billing-main-single{grid-template-columns:minmax(0,680px)!important}
.jb-visit-title-row{position:relative}.jb-visit-menu-wrap{position:relative}.jb-visit-menu{position:absolute;right:0;top:34px;z-index:50;min-width:145px;padding:5px;border:1px solid var(--jb-line);border-radius:7px;background:#fff;box-shadow:0 12px 28px rgba(0,17,49,.12);display:none}.jb-visit-menu.show{display:block}.jb-visit-menu button{width:100%;padding:8px 10px;border:0;border-radius:5px;background:#fff;color:#b03d3d;text-align:left;font-size:11px}.jb-visit-menu button:hover{background:#fff3f3}
.jb-line-table{overflow:visible!important}.jb-line-row{grid-template-columns:2.45fr .72fr .9fr .9fr .72fr .9fr 34px!important;overflow:visible!important}.jb-item-cell{min-width:0}.jb-item-type{display:flex;gap:5px;margin:0 0 7px}.jb-item-type button{height:25px;padding:0 10px;border:1px solid #d7e0e8;border-radius:6px;background:#fff;color:#516a7d;font-size:9px;font-weight:700}.jb-item-type button.active{border-color:#8abd61;background:#f6fbea;color:#4f8d26}.jb-service-wrap,.jb-product-wrap,.jb-manual-wrap{display:none}.jb-line-row[data-mode="service"] .jb-service-wrap{display:block}.jb-line-row[data-mode="product"] .jb-product-wrap{display:block}.jb-line-row[data-mode="manual"] .jb-manual-wrap{display:block}.jb-money-box{padding:4px 8px!important}.jb-money-box input{width:100%;border:0;outline:0;background:transparent;color:#17364a;font-size:12px;padding:0}.jb-money-box input:focus{outline:0}.jb-line-description{grid-column:1/-1!important;height:76px!important}.jb-line-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:0 10px 14px}.jb-add-product,.jb-add-manual{border:1px solid #d7e0e8;background:#fff;color:#3d851f;border-radius:6px;padding:8px 12px;font-weight:700;font-size:11px}.jb-catalog-result{padding:3px 0}.jb-catalog-result strong{display:block;font-size:12px;color:#19384b}.jb-catalog-result small{display:block;margin-top:2px;color:#718493;font-size:10px}.jb-new-product-result{padding:5px 0;color:#3d851f;font-weight:700}.jb-text-action{border:0;background:transparent;padding:0;color:#4d8d25;text-decoration:underline;font-size:11px;font-weight:700}.jb-discount-right{display:flex;align-items:center;gap:10px}.jb-discount-editor{display:none;grid-template-columns:150px 130px auto auto;gap:7px;padding:9px 0;border-bottom:1px solid var(--jb-line)}.jb-discount-editor.show{display:grid}.jb-discount-editor .jb-control{height:36px}.jb-apply-discount,.jb-remove-discount{height:36px;padding:0 11px;border-radius:6px;font-size:10px;font-weight:700}.jb-apply-discount{border:0;background:#348f24;color:#fff}.jb-remove-discount{border:1px solid #d7e0e8;background:#fff;color:#b24949}.jb-note-box-edit{display:block!important;height:auto!important;min-height:145px!important}.jb-note-box-edit textarea{display:block!important;width:100%!important;min-height:145px!important;padding:15px!important;border:0!important;outline:0!important;background:transparent!important;resize:vertical!important;color:#294b5d!important}.jb-capture-copy span:empty{display:none}.jb-capture-copy .jb-link-btn{margin-top:7px}
@media(max-width:991.98px){.jb-line-row{grid-template-columns:1.7fr .7fr .85fr .85fr .7fr .85fr 34px!important}.jb-discount-editor{grid-template-columns:1fr 1fr auto auto}}
@media(max-width:700px){.jb-line-row{grid-template-columns:1fr 1fr!important}.jb-item-cell{grid-column:1/-1}.jb-line-row .jb-line-remove{grid-column:auto!important}.jb-line-description{grid-column:1/-1!important}.jb-discount-editor{grid-template-columns:1fr 1fr}.jb-apply-discount,.jb-remove-discount{width:100%}}
@media(max-width:575.98px){.jb-custom-field{grid-template-columns:1fr 34px}.jb-custom-field [data-custom-value]{grid-column:1/2}.jb-custom-remove{grid-column:2/3;grid-row:1/3;align-self:center}}

</style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fieldplx-main-content"><div class="fieldplx-content-wrapper"><div class="jb-page">
<form id="jobForm" enctype="multipart/form-data">
<input type="hidden" name="job_id" id="jobId" value="<?= (int)$jobId ?>">
<input type="hidden" name="request_id" id="requestId" value="<?= (int)$requestId ?>">
<input type="hidden" name="job_source" id="jobSource" value="<?= $quoteId > 0 ? 'quotation' : 'direct' ?>">
<input type="hidden" name="quote_id" id="quoteId" value="<?= (int)$quoteId ?>">
<input type="hidden" name="location_id" id="directLocationId" value="<?= (int)$locationId ?>">
<input type="hidden" name="direct_branch_id" id="directBranchId" value="">
<input type="hidden" name="direct_product_service_id" id="directServiceId" value="<?= (int)$serviceId ?>">
<input type="hidden" name="product_service_id" id="jobServiceId" value="<?= (int)$serviceId ?>">
<input type="hidden" name="assignment_mode" id="assignmentMode" value="single_user">
<input type="hidden" name="assignment_completion_mode" id="completionMode" value="primary_only">
<input type="hidden" name="status" id="status" value="scheduled">
<input type="hidden" name="priority" id="priority" value="normal">
<input type="hidden" name="schedule_json" id="scheduleJson">
<input type="hidden" name="new_checklist_json" id="newChecklistJson">
<input type="hidden" name="line_items_json" id="lineItemsJson">
<input type="hidden" name="custom_fields_json" id="customFieldsJson" value="[]">
<input type="hidden" name="description" id="description" value="">

<div class="jb-head"><i class="bi bi-hammer"></i><span id="pageTitle"><?= $jobId > 0 ? 'Edit Job' : 'New Job' ?></span></div>
<div class="jb-top">
<input class="jb-control jb-title-input" type="text" name="title" id="title" maxlength="190" placeholder="Title" required>
<div class="jb-top-grid">
<div><select name="client_id" id="directClientId" class="jb-select2" required><option value="">Select a customer</option></select></div>
<div class="jb-top-right">
<div class="jb-row-label">Job #</div><input class="jb-control" id="jobNumberPreview" value="Auto" readonly>
<div class="jb-row-label">Salesperson</div><select name="single_user_id" id="singleUserId" class="jb-select2" required><option value="">Select employee</option></select>
<div class="jb-customize">Customize <button type="button" class="jb-add-field" id="toggleCustomize"><i class="bi bi-plus-lg"></i> Add Field</button></div><div></div>
</div>
</div>
<div class="jb-job-details" id="jobDetailsPanel">
  <div class="jb-job-details-title">Location &amp; Job Details</div>
  <div class="jb-field-box with-label"><span class="jb-floating">Service location</span><select class="jb-control jb-select2" id="serviceLocationSelect"><option value="">Use primary / no saved location</option></select></div>
  <div class="jb-field-box with-label"><span class="jb-floating">Priority</span><select class="jb-control" id="priorityEditor"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
  <div class="jb-field-box with-label"><span class="jb-floating">Status</span><select class="jb-control" id="statusEditor"><option value="scheduled" selected>Scheduled</option><option value="active">Active</option><option value="upcoming">Upcoming</option><option value="today">Today</option><option value="in_progress">In Progress</option><option value="waiting_customer">Waiting Customer</option><option value="waiting_material">Waiting Material</option></select></div>
</div>
<div class="jb-custom-fields" id="customFieldsPanel"></div>
</div>

<section class="jb-section-card">
<h2 class="jb-section-title">Visits</h2>
<div class="jb-schedule-bar">
<div><div class="jb-schedule-label">Schedule</div><div class="jb-tabs"><button class="jb-tab active" type="button" data-mode="one_off">One-off</button><button class="jb-tab" type="button" data-mode="recurring">Recurring</button></div></div>
<div class="jb-schedule-summary" id="scheduleSummary"><span id="scheduleRange"></span><span id="visitCount">1 visit</span><span id="repeatSummary"></span></div>
<button class="jb-create-visits" type="button" id="createVisitsButton"><i class="bi bi-pencil"></i> Create Visits</button>
</div>
<div id="oneOffPanel"><div class="jb-visits" id="visitsBox"></div><button class="jb-add-visit" type="button" id="addVisitButton">Add a Visit</button></div>
<div class="jb-recurring-box" id="recurringPanel">
<div class="jb-rec-grid">
<div class="jb-field-box with-label full"><span class="jb-floating">Start date</span><input class="jb-control" type="date" id="recStartDate"></div>
<div class="jb-field-box"><input class="jb-control" type="time" id="recStartTime" placeholder="Start time"></div><div class="jb-field-box"><input class="jb-control" type="time" id="recEndTime" placeholder="End time"></div>
<label class="jb-checkline full"><input type="checkbox" id="recAnytime"> Anytime</label>
<div class="jb-field-box with-label full"><span class="jb-floating">Repeats</span><select class="jb-control" id="recRepeat"><option value="weekly">Weekly on selected days</option><option value="daily">Daily</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></div>
<div class="full" id="weekdayRow" style="display:flex;gap:8px;flex-wrap:wrap"></div>
<div class="jb-radio-row full"><label class="jb-radio-panel"><input type="radio" name="rec_end_mode" value="after_duration" checked> Ends after</label><label class="jb-radio-panel"><input type="radio" name="rec_end_mode" value="on_date"> Ends on</label></div>
<div class="jb-end-grid full"><input class="jb-control" id="recEndValue" type="number" min="1" value="6"><select class="jb-control" id="recEndUnit"><option value="months">Months</option><option value="weeks">Weeks</option><option value="days">Days</option><option value="years">Years</option></select><input class="jb-control" id="recEndDate" type="date" disabled></div>
<div class="jb-field-box full"><select multiple id="recAssignees" class="jb-select2"></select></div>
<div class="jb-field-box full"><textarea class="jb-control jb-textarea" id="recInstructions" placeholder="Visit instructions"></textarea></div>
</div>
<div class="jb-capture"><div class="jb-capture-icon"><i class="bi bi-clipboard2-check"></i></div><div class="jb-capture-copy"><strong>Capture on-site details</strong><button type="button" class="jb-link-btn js-open-checklist">Create a Checklist</button></div></div>
</div>
</section>

<section class="jb-section-card">
<h2 class="jb-section-title">Billing</h2>
<div class="jb-billing-meta"><span>Total invoices <strong id="billingInvoiceCount">1</strong></span><span>First <strong id="billingFirstDate">-</strong></span><span>Last <strong id="billingLastDate">-</strong></span></div>
<div class="jb-billing-main jb-billing-main-single">
<div class="jb-billing-types"><strong>Billing type</strong><label><input type="radio" name="billing_type" value="visit_based" checked> Visit based</label><label><input type="radio" name="billing_type" value="fixed_price"> Fixed price</label><div style="margin-top:18px"><strong>Invoice frequency</strong><div class="jb-field-box with-label" style="margin-top:9px"><span class="jb-floating">Repeats</span><select class="jb-control" id="invoiceFrequency" name="invoice_frequency"><option value="monthly_last_day">Monthly on the last day of the month</option><option value="after_each_visit">After each visit</option><option value="job_completion">When job is completed</option></select></div></div><div id="fixedInvoiceAmountWrap" class="jb-field-box" style="display:none"><input class="jb-control" type="number" step="0.01" min="0" name="fixed_invoice_amount" id="fixedInvoiceAmount" placeholder="Fixed amount per invoice"></div></div>
</div>
</section>

<section class="jb-section-card">
<h2 class="jb-section-title">Product / Service</h2>
<div class="jb-line-table">
<div id="lineItemsBox"></div>
<div class="jb-line-actions"><button type="button" class="jb-add-line" id="addServiceItem">Add Service</button><button type="button" class="jb-add-product" id="addProductItem">Add Product</button><button type="button" class="jb-add-manual" id="addManualItem">Add Manual Item</button></div>
<input type="hidden" name="discount_type" id="discountType" value="">
<input type="hidden" name="discount_value" id="discountValue" value="0">
<div class="jb-totals"><div></div><div class="jb-total-grid">
<div class="jb-total-row"><span>Subtotal</span><span id="subtotalDisplay">0.00</span></div>
<div class="jb-total-row jb-discount-row"><span>Discount</span><div class="jb-discount-right"><span id="discountDisplay">0.00</span><button type="button" class="jb-text-action" id="addDiscount">Add Discount</button></div></div>
<div class="jb-discount-editor" id="discountEditor"><select class="jb-control" id="discountTypeEditor"><option value="fixed">Fixed amount</option><option value="percentage">Percentage</option></select><input class="jb-control" id="discountValueEditor" type="number" min="0" step="0.01" value="0"><button type="button" class="jb-apply-discount" id="applyDiscount">Apply</button><button type="button" class="jb-remove-discount" id="removeDiscount">Remove</button></div>
<div class="jb-total-row"><span>Tax</span><span id="taxDisplay">0.00</span></div>
<div class="jb-total-row grand"><span>Total price</span><span id="totalDisplay">0.00</span></div>
<div class="jb-total-row"><span>Total cost</span><span id="costDisplay">0.00</span></div>
</div></div></div>
</section>

<h2 class="jb-notes-title">Notes</h2><div class="jb-note-box jb-note-box-edit"><textarea name="internal_note" id="internalNote" placeholder="Notes"></textarea></div>

<select multiple name="checklist_template_ids[]" id="checklistTemplates" class="jb-hidden"></select>
<input class="jb-hidden" type="file" name="job_attachments[]" id="jobAttachments" multiple>
</form>
</div></div></main></div>
<div class="jb-sticky"><a href="jobs" class="jb-cancel">Cancel</a><div class="jb-save-group"><button type="submit" form="jobForm" class="jb-save" id="saveButton"><span id="saveText"><?= $jobId > 0 ? 'Update Job' : 'Save Job' ?></span></button><button type="button" class="jb-save-caret" id="saveCaret"><i class="bi bi-chevron-down"></i></button></div></div>
<div class="jb-toast" id="toast"></div>

<div class="jb-modal" id="checklistModal"><div class="jb-dialog"><div class="jb-modal-head"><h2 id="checklistModalTitle">Edit New Checklist</h2><div class="jb-modal-actions"><button class="jb-cancel" type="button" id="cancelChecklist">Cancel</button><button class="jb-save" type="button" id="saveChecklist" style="border-radius:7px">Save</button></div></div><div class="jb-modal-body"><div class="jb-builder-canvas"><div class="jb-builder-paper" id="builderCanvas"></div></div><aside class="jb-builder-side"><h3>Manage checklist</h3><div style="margin-bottom:18px"><label style="font-size:10px;color:#607688">Form title</label><input id="checklistName" value="New checklist" style="width:100%;margin-top:6px"></div><h3 style="font-size:13px">Checklist contents</h3><div class="jb-palette"><button type="button" data-add-type="section"><i class="bi bi-file-earmark-plus"></i> Add section</button><button type="button" data-add-type="short_answer"><i class="bi bi-list"></i> Short answer</button><button type="button" data-add-type="long_answer"><i class="bi bi-text-paragraph"></i> Long answer</button><button type="button" data-add-type="dropdown"><i class="bi bi-chevron-circle-down"></i> Dropdown (single choice)</button><button type="button" data-add-type="checkbox"><i class="bi bi-check-square"></i> Checkbox</button><button type="button" data-add-type="number"><i class="bi bi-hash"></i> Numerical answer</button><button type="button" data-add-type="image"><i class="bi bi-image"></i> Upload images</button><button type="button" data-add-type="date"><i class="bi bi-calendar"></i> Date picker</button><button type="button" data-add-type="signature"><i class="bi bi-pen"></i> Signature</button></div></aside></div><div class="jb-modal-foot"><button class="jb-cancel" type="button" id="modalClose">Cancel</button><button class="jb-save" type="button" id="modalApply" style="border-radius:7px">Save Checklist</button></div></div></div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){'use strict';
var csrf=<?= json_encode($jobsCsrfToken) ?>,jobId=<?= (int)$jobId ?>,requestedQuote=<?= (int)$quoteId ?>,requestedClient=<?= (int)$clientId ?>,requestedLocation=<?= (int)$locationId ?>,requestedService=<?= (int)$serviceId ?>,api=(<?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>||'')+'/api/jobs.php';
var meta={clients:[],locations:[],users:[],services:[],products:[],catalog_items:[],checklist_templates:[],currency:{}},mode='one_off',visitSeq=0,toastTimer=null,builder=[{title:'Section 1',questions:[{title:'Question',type:'short_answer',required:0,options:[]}]}];
function E(id){return document.getElementById(id)}function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}function toast(type,msg){var t=E('toast');clearTimeout(toastTimer);t.className='jb-toast '+type+' show';t.textContent=msg;toastTimer=setTimeout(function(){t.classList.remove('show')},4300)}function req(fd){fd.append('csrf_token',csrf);return fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.text().then(function(x){var d;try{d=JSON.parse(x)}catch(e){throw new Error(x.replace(/<[^>]+>/g,' ').trim()||'Invalid server response')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed');return d})})}
function money(v){var c=meta.currency||{},p=parseInt(c.decimal_places,10);if(isNaN(p))p=2;var n=Number(v||0).toFixed(p),s=c.symbol||'';return c.symbol_position==='after'?n+(s?' '+s:''):(s||'')+n}function userOptions(selected){selected=(selected||[]).map(Number);return (meta.users||[]).map(function(u){return '<option value="'+u.id+'"'+(selected.indexOf(Number(u.id))>=0?' selected':'')+'>'+esc(u.name)+'</option>'}).join('')}function catalog(key){key=String(key||'');return (meta.catalog_items||[]).find(function(x){return String(x.catalog_key||'')===key})||null}function catalogKeyForLine(x){if(x&&Number(x.product_id||0)>0)return 'product:'+Number(x.product_id);if(x&&Number(x.product_service_id||0)>0)return 'ps:'+Number(x.product_service_id);return ''}
function initSelects(){
    meta.clients=Array.isArray(meta.clients)?meta.clients:[];
    meta.locations=Array.isArray(meta.locations)?meta.locations:[];
    meta.users=Array.isArray(meta.users)?meta.users:[];
    meta.services=Array.isArray(meta.services)?meta.services:[];
    meta.products=Array.isArray(meta.products)?meta.products:[];
    meta.catalog_items=Array.isArray(meta.catalog_items)?meta.catalog_items:[];
    meta.checklist_templates=Array.isArray(meta.checklist_templates)?meta.checklist_templates:[];
    var $base=$('#directClientId,#singleUserId,#serviceLocationSelect');
    $base.filter('.select2-hidden-accessible').each(function(){$(this).select2('destroy')});
    E('directClientId').innerHTML='<option value="">Select a customer</option>'+meta.clients.map(function(c){return '<option value="'+c.id+'">'+esc(c.name)+'</option>'}).join('');
    E('singleUserId').innerHTML='<option value="">Select employee</option>'+userOptions([]);
    $base.not('#serviceLocationSelect').select2({width:'100%'});
    refreshLocationOptions(requestedLocation||Number(E('directLocationId').value||0));
    if(requestedClient)$('#directClientId').val(String(requestedClient)).trigger('change.select2');
    if(requestedService)E('directServiceId').value=requestedService;
}
function refreshLocationOptions(selectedId){
    var cid=Number($('#directClientId').val()||0),rows=meta.locations.filter(function(x){return !cid||Number(x.client_id)===cid});
    var sel=E('serviceLocationSelect');
    if($(sel).hasClass('select2-hidden-accessible'))$(sel).select2('destroy');
    sel.innerHTML='<option value="">Use primary / no saved location</option>'+rows.map(function(x){return '<option value="'+x.id+'">'+esc(x.name||('Location #'+x.id))+'</option>'}).join('');
    $(sel).select2({width:'100%',placeholder:'Service location'});
    if(selectedId){$(sel).val(String(selectedId)).trigger('change.select2');E('directLocationId').value=String(selectedId)}
}
function customFieldHtml(x){
    x=x||{};
    return '<div class="jb-custom-field" data-custom-field>'+        '<input type="text" class="jb-control" data-custom-label maxlength="120" placeholder="Custom label" value="'+esc(x.field_label||x.label||'')+'">'+        '<input type="text" class="jb-control" data-custom-value maxlength="2000" placeholder="Value" value="'+esc(x.field_value||x.value||'')+'">'+        '<button type="button" class="jb-custom-remove" data-remove-custom title="Remove custom field"><i class="bi bi-x-lg"></i></button>'+        '</div>';
}
function addCustomField(x,focusLabel){
    var panel=E('customFieldsPanel'),count=panel.querySelectorAll('[data-custom-field]').length;
    if(count>=20){toast('warning','A job can contain a maximum of 20 custom fields.');return}
    panel.insertAdjacentHTML('beforeend',customFieldHtml(x||{}));
    var row=panel.lastElementChild;
    row.querySelector('[data-remove-custom]').addEventListener('click',function(){row.remove()});
    if(focusLabel)row.querySelector('[data-custom-label]').focus();
}
function loadCustomFields(rows){
    var panel=E('customFieldsPanel');panel.innerHTML='';
    (Array.isArray(rows)?rows:[]).forEach(function(x){addCustomField(x,false)});
}
function collectCustomFields(){
    var out=[],invalid=false;
    document.querySelectorAll('[data-custom-field]').forEach(function(row){
        var label=String(row.querySelector('[data-custom-label]').value||'').trim();
        var value=String(row.querySelector('[data-custom-value]').value||'').trim();
        if(label===''&&value==='')return;
        if(label===''){invalid=true;return}
        out.push({field_label:label,field_value:value,sort_order:out.length+1});
    });
    if(invalid){toast('warning','Enter a custom label for each custom field value.');return null}
    return out;
}
function today(){var d=new Date(),m=String(d.getMonth()+1).padStart(2,'0'),day=String(d.getDate()).padStart(2,'0');return d.getFullYear()+'-'+m+'-'+day}function friendlyDate(v){if(!v)return '-';var d=new Date(v+'T00:00:00');return d.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'})}function railDate(v){var d=new Date((v||today())+'T00:00:00');return '<span>'+d.toLocaleDateString(undefined,{month:'short'})+'</span><strong>'+d.getDate()+'</strong>'}
function visitHtml(v){
    v=v||{};visitSeq++;
    var id=visitSeq,date=v.start_date||today(),assigned=Array.isArray(v.assignee_ids)?v.assignee_ids:[];
    return '<div class="jb-visit" data-id="'+id+'">'+
        '<div class="jb-date-rail" data-rail>'+railDate(date)+'</div>'+
        '<div class="jb-visit-main">'+
            '<div class="jb-visit-title-row"><input class="jb-control" data-f="title" placeholder="Title" value="'+esc(v.title||'')+'">'+
                '<div class="jb-visit-menu-wrap"><button type="button" class="jb-more" data-visit-menu><i class="bi bi-three-dots"></i></button><div class="jb-visit-menu"><button type="button" data-remove-visit><i class="bi bi-trash"></i> Delete visit</button></div></div></div>'+
            '<div class="jb-field-box with-label"><span class="jb-floating">Date</span><input class="jb-control" data-f="date" type="date" value="'+esc(date)+'"></div>'+
            '<label class="jb-checkline"><input type="checkbox" data-f="later" '+(v.schedule_later?'checked':'')+'> Schedule later</label>'+
            '<div class="jb-two"><input class="jb-control" data-f="start" type="time" value="'+esc((v.start_time||'').substr(0,5))+'"><input class="jb-control" data-f="end" type="time" value="'+esc((v.end_time||'').substr(0,5))+'"></div>'+
            '<label class="jb-checkline"><input type="checkbox" data-f="anytime" '+(v.anytime?'checked':'')+'> Anytime</label>'+
            '<div class="jb-field-box"><select multiple class="jb-select2 jb-visit-assign" data-f="assign">'+userOptions(assigned)+'</select></div>'+
            '<div class="jb-field-box"><textarea class="jb-control jb-textarea" data-f="instructions" placeholder="Visit instructions">'+esc(v.instructions||'')+'</textarea></div>'+
            '<div class="jb-capture"><div class="jb-capture-icon"><i class="bi bi-clipboard2-check"></i></div><div class="jb-capture-copy"><strong>Capture on-site details</strong><button type="button" class="jb-link-btn js-open-checklist">Create a Checklist</button></div></div>'+
        '</div></div>';
}
function addVisit(v){
    E('visitsBox').insertAdjacentHTML('beforeend',visitHtml(v));
    var row=E('visitsBox').lastElementChild,$assign=$(row).find('.jb-visit-assign');
    $assign.select2({width:'100%',placeholder:'Assign'});
    row.querySelector('[data-f="date"]').addEventListener('change',function(){row.querySelector('[data-rail]').innerHTML=railDate(this.value);updateSummary()});
    row.querySelector('[data-visit-menu]').addEventListener('click',function(e){e.stopPropagation();document.querySelectorAll('.jb-visit-menu.show').forEach(function(m){if(m!==row.querySelector('.jb-visit-menu'))m.classList.remove('show')});row.querySelector('.jb-visit-menu').classList.toggle('show')});
    row.querySelector('[data-remove-visit]').addEventListener('click',function(){
        if(document.querySelectorAll('.jb-visit').length<=1){toast('warning','At least one visit is required.');return}
        if($assign.hasClass('select2-hidden-accessible'))$assign.select2('destroy');
        row.remove();
        updateSummary();
    });
    row.querySelectorAll('input,select,textarea').forEach(function(x){x.addEventListener('change',updateSummary);x.addEventListener('input',updateSummary)});
    updateSummary();
}
function collectOneOff(){var out=[];document.querySelectorAll('.jb-visit').forEach(function(r){var g=function(n){var x=r.querySelector('[data-f="'+n+'"]');return x?x.value:''},later=r.querySelector('[data-f="later"]').checked,any=r.querySelector('[data-f="anytime"]').checked,s=g('start'),e=g('end');if((later||any)&&!s)s='00:00';if((later||any)&&!e)e='23:59';out.push({start_date:g('date')||today(),end_date:g('date')||today(),start_time:s,end_time:e,repeat_type:'none',repeat_interval:1,weekly_days:[],end_mode:'after_occurrences',repeat_occurrences:1,assignee_ids:($(r).find('.jb-visit-assign').val()||[]).map(Number),instructions:g('instructions'),title:g('title'),schedule_later:later?1:0,anytime:any?1:0})});return out}
document.addEventListener('click',function(e){if(!e.target.closest('.jb-visit-menu-wrap'))document.querySelectorAll('.jb-visit-menu.show').forEach(function(m){m.classList.remove('show')})});
var weekdays=[['Sun',0],['Mon',1],['Tue',2],['Wed',3],['Thu',4],['Fri',5],['Sat',6]];function renderWeekdays(){E('weekdayRow').innerHTML=weekdays.map(function(d){return '<label class="jb-checkline" style="margin:0;border:1px solid #dbe2e7;border-radius:6px;padding:7px 9px"><input type="checkbox" data-week value="'+d[1]+'"'+(d[1]===new Date((E('recStartDate').value||today())+'T00:00:00').getDay()?' checked':'')+'>'+d[0]+'</label>'}).join('')}
function collectRecurring(){var date=E('recStartDate').value||today(),s=E('recStartTime').value,e=E('recEndTime').value,any=E('recAnytime').checked;if(any&&!s)s='00:00';if(any&&!e)e='23:59';var em=document.querySelector('input[name="rec_end_mode"]:checked').value;return [{start_date:date,end_date:date,start_time:s,end_time:e,repeat_type:E('recRepeat').value,repeat_interval:1,weekly_days:Array.prototype.slice.call(document.querySelectorAll('[data-week]:checked')).map(function(x){return Number(x.value)}),end_mode:em,repeat_end_date:em==='on_date'?E('recEndDate').value:'',repeat_occurrences:1,end_after_value:Number(E('recEndValue').value||6),end_after_unit:E('recEndUnit').value,assignee_ids:($('#recAssignees').val()||[]).map(Number),instructions:E('recInstructions').value,anytime:any?1:0}]}
function addMonths(d,n){var x=new Date(d.getTime()),day=x.getDate();x.setDate(1);x.setMonth(x.getMonth()+n);x.setDate(Math.min(day,new Date(x.getFullYear(),x.getMonth()+1,0).getDate()));return x}function recOccurrences(s){var start=new Date(s.start_date+'T'+(s.start_time||'00:00')+':00'),out=[],limit=500,endDate=null;if(s.end_mode==='on_date'&&s.repeat_end_date)endDate=new Date(s.repeat_end_date+'T23:59:59');if(s.end_mode==='after_duration'){endDate=new Date(start.getTime());var n=Number(s.end_after_value||1);if(s.end_after_unit==='days')endDate.setDate(endDate.getDate()+n);else if(s.end_after_unit==='weeks')endDate.setDate(endDate.getDate()+n*7);else if(s.end_after_unit==='years')endDate.setFullYear(endDate.getFullYear()+n);else endDate=addMonths(endDate,n)}for(var i=0;i<limit;i++){var d;if(s.repeat_type==='daily'){d=new Date(start);d.setDate(d.getDate()+i)}else if(s.repeat_type==='monthly'){d=addMonths(start,i)}else if(s.repeat_type==='yearly'){d=new Date(start);d.setFullYear(d.getFullYear()+i)}else{d=new Date(start);d.setDate(d.getDate()+i);if((s.weekly_days||[]).indexOf(d.getDay())<0)continue}if(endDate&&d>endDate)break;out.push(d);if(s.end_mode==='after_occurrences'&&out.length>=Number(s.repeat_occurrences||1))break}return out}
function schedules(){return mode==='recurring'?collectRecurring():collectOneOff()}function updateSummary(){var ss=schedules(),dates=[];ss.forEach(function(s){dates=dates.concat(s.repeat_type==='none'?[new Date(s.start_date+'T00:00:00')]:recOccurrences(s))});dates.sort(function(a,b){return a-b});var count=dates.length||ss.length||1;E('visitCount').textContent=count+' visit'+(count===1?'':'s');E('billingInvoiceCount').textContent=count;E('scheduleRange').textContent=dates.length?(friendlyDate(dates[0].toISOString().slice(0,10))+(dates.length>1?' – '+friendlyDate(dates[dates.length-1].toISOString().slice(0,10)):'')):'';E('billingFirstDate').textContent=dates.length?friendlyDate(dates[0].toISOString().slice(0,10)):'-';E('billingLastDate').textContent=dates.length?friendlyDate(dates[dates.length-1].toISOString().slice(0,10)):'-';E('repeatSummary').textContent=mode==='recurring'?'Repeats '+E('recRepeat').value:''}
function switchMode(m){mode=m;document.querySelectorAll('.jb-tab').forEach(function(b){b.classList.toggle('active',b.getAttribute('data-mode')===m)});E('oneOffPanel').style.display=m==='one_off'?'block':'none';E('recurringPanel').classList.toggle('show',m==='recurring');updateSummary()}
function serviceById(id){id=Number(id||0);return (meta.services||[]).find(function(x){return Number(x.id)===id})||null}
function productById(id){id=Number(id||0);return (meta.products||[]).find(function(x){return Number(x.id)===id})||null}
function serviceOptions(selectedId){var out='<option value="">Select service</option>';(meta.services||[]).forEach(function(i){out+='<option value="'+Number(i.id)+'"'+(Number(selectedId||0)===Number(i.id)?' selected':'')+'>'+esc(i.name||'')+'</option>'});return out}
function productOptions(selectedId){var out='<option value="">Select product or enter new product</option>';(meta.products||[]).forEach(function(i){out+='<option value="'+Number(i.id)+'"'+(Number(selectedId||0)===Number(i.id)?' selected':'')+'>'+esc(i.name||'')+'</option>'});return out}
function inferLineMode(x){if(String(x&&x.item_source||'')==='manual')return 'manual';if(Number(x&&x.product_id||0)>0)return 'product';if(Number(x&&x.product_service_id||0)>0)return 'service';if(String(x&&x.item_source||'')==='new_product')return 'product';return String(x&&x.line_mode||'service')}
function lineHtml(x){
    x=x||{};
    var id='li'+Math.random().toString(36).slice(2),lineMode=inferLineMode(x),qty=Number(x.quantity==null?1:x.quantity),cost=Number(x.unit_cost||0),price=Number(x.unit_price||0),tax=Number(x.tax_percent||0);
    var productSelected=Number(x.product_id||0),serviceSelected=Number(x.product_service_id||0),newProductName=String(x.new_product_name||'');
    return '<div class="jb-line-row" data-line="'+id+'" data-mode="'+lineMode+'">'+
        '<div class="jb-item-cell"><div class="jb-item-type"><button type="button" data-line-mode="service" class="'+(lineMode==='service'?'active':'')+'">Service</button><button type="button" data-line-mode="product" class="'+(lineMode==='product'?'active':'')+'">Product</button><button type="button" data-line-mode="manual" class="'+(lineMode==='manual'?'active':'')+'">Manual</button></div>'+
        '<div class="jb-service-wrap"><select class="jb-control" data-li="service">'+serviceOptions(serviceSelected)+'</select></div>'+
        '<div class="jb-product-wrap"><select class="jb-control" data-li="product">'+productOptions(productSelected)+(newProductName?'<option value="new:'+esc(newProductName)+'" selected>'+esc(newProductName)+'</option>':'')+'</select></div>'+
        '<div class="jb-manual-wrap"><input class="jb-control" data-li="manual_name" placeholder="Item name" value="'+esc(lineMode==='manual'?(x.item_name||''):'')+'"></div></div>'+
        '<div class="jb-money-box"><small>Quantity</small><input data-li="qty" type="number" step="0.001" min="0.001" value="'+qty+'"></div>'+
        '<div class="jb-money-box"><small>Unit cost</small><input data-li="cost" type="number" min="0" step="0.01" value="'+cost.toFixed(2)+'"></div>'+
        '<div class="jb-money-box"><small>Unit price</small><input data-li="price" type="number" min="0" step="0.01" value="'+price.toFixed(2)+'"></div>'+
        '<div class="jb-money-box"><small>Tax %</small><input data-li="tax" type="number" min="0" max="100" step="0.01" value="'+tax.toFixed(2)+'"></div>'+
        '<div class="jb-money-box"><small>Total</small><strong data-li="total">'+money(qty*price)+'</strong></div>'+
        '<button type="button" class="jb-more jb-line-remove" title="Remove item"><i class="bi bi-trash"></i></button>'+
        '<textarea class="jb-control jb-line-description" data-li="desc" placeholder="Description">'+esc(x.description||'')+'</textarea>'+
        '</div>';
}
function serviceTemplate(item){
    if(!item.id)return item.text;var x=serviceById(item.id);if(!x)return item.text;
    var $box=$('<div class="jb-catalog-result"></div>');$('<strong></strong>').text(x.name||'').appendTo($box);var parts=[];if(x.sku)parts.push(x.sku);parts.push('Price '+money(x.unit_price||0));$('<small></small>').text(parts.join(' · ')).appendTo($box);return $box;
}
function productTemplate(item){
    if(item.newTag){return $('<div class="jb-new-product-result"></div>').text('Create product: '+item.text)}
    if(!item.id)return item.text;var x=productById(item.id);if(!x)return item.text;
    var $box=$('<div class="jb-catalog-result"></div>');$('<strong></strong>').text(x.name||'').appendTo($box);var parts=[];if(x.sku)parts.push(x.sku);parts.push('Price '+money(x.selling_price||0));$('<small></small>').text(parts.join(' · ')).appendTo($box);return $box;
}
function setLineMode(r,newMode){r.dataset.mode=newMode;r.querySelectorAll('[data-line-mode]').forEach(function(b){b.classList.toggle('active',b.getAttribute('data-line-mode')===newMode)});updateLine(r)}
function fillServiceLine(r,id){var x=serviceById(id);if(!x)return;r.querySelector('[data-li="cost"]').value=Number(x.unit_cost||0).toFixed(2);r.querySelector('[data-li="price"]').value=Number(x.unit_price||0).toFixed(2);r.querySelector('[data-li="tax"]').value=Number(x.tax_percent||0).toFixed(2);r.querySelector('[data-li="desc"]').value=x.description||'';updateLine(r)}
function fillProductLine(r,id){var x=productById(id);if(!x)return;r.querySelector('[data-li="cost"]').value=Number(x.base_unit_price||0).toFixed(2);r.querySelector('[data-li="price"]').value=Number(x.selling_price||0).toFixed(2);r.querySelector('[data-li="tax"]').value=Number(x.tax_percent||0).toFixed(2);r.querySelector('[data-li="desc"]').value=x.description||'';updateLine(r)}
function addLine(x){
    E('lineItemsBox').insertAdjacentHTML('beforeend',lineHtml(x));
    var r=E('lineItemsBox').lastElementChild,serviceSel=r.querySelector('[data-li="service"]'),productSel=r.querySelector('[data-li="product"]'),$service=$(serviceSel),$product=$(productSel);
    $service.select2({width:'100%',placeholder:'Select service',templateResult:serviceTemplate});
    $product.select2({width:'100%',placeholder:'Select product or enter new product',tags:true,createTag:function(params){var term=$.trim(params.term||'');if(!term)return null;var same=(meta.products||[]).find(function(x){return String(x.name||'').trim().toLowerCase()===term.toLowerCase()});if(same)return null;return {id:'new:'+term,text:term,newTag:true}},templateResult:productTemplate});
    $service.on('change',function(){if(this.value)fillServiceLine(r,this.value)});
    $product.on('change',function(){var raw=String($(this).val()||'');if(raw.indexOf('new:')===0){r.querySelector('[data-li="desc"]').value='';r.querySelector('[data-li="cost"]').value='0.00';r.querySelector('[data-li="price"]').value='0.00';r.querySelector('[data-li="tax"]').value='0.00';updateLine(r)}else if(raw){fillProductLine(r,raw)}});
    r.querySelectorAll('[data-line-mode]').forEach(function(b){b.addEventListener('click',function(){setLineMode(r,this.getAttribute('data-line-mode'))})});
    ['qty','cost','price','tax','manual_name'].forEach(function(k){var el=r.querySelector('[data-li="'+k+'"]');if(el){el.addEventListener('input',function(){updateLine(r)});el.addEventListener('change',function(){updateLine(r)})}});
    r.querySelector('.jb-line-remove').addEventListener('click',function(){if(document.querySelectorAll('[data-line]').length<=1){toast('warning','At least one item is required.');return}if($service.hasClass('select2-hidden-accessible'))$service.select2('destroy');if($product.hasClass('select2-hidden-accessible'))$product.select2('destroy');r.remove();updateTotals()});
    if(Number(x&&x.product_service_id||0)>0&&Number(x&&x.unit_price||0)===0&&Number(x&&x.unit_cost||0)===0)fillServiceLine(r,x.product_service_id);
    if(Number(x&&x.product_id||0)>0&&Number(x&&x.unit_price||0)===0&&Number(x&&x.unit_cost||0)===0)fillProductLine(r,x.product_id);
    updateLine(r);
}
function updateLine(r){var q=Math.max(0,Number(r.querySelector('[data-li="qty"]').value||0)),price=Math.max(0,Number(r.querySelector('[data-li="price"]').value||0));r.querySelector('[data-li="total"]').textContent=money(q*price);updateTotals()}
function collectLines(){
    var a=[];
    document.querySelectorAll('[data-line]').forEach(function(r,n){
        var m=r.dataset.mode||'service',name='',productServiceId=0,productId=0,itemSource='manual',itemType='manual';
        if(m==='service'){
            var sid=Number($(r).find('[data-li="service"]').val()||0),svc=serviceById(sid);if(!svc)return;productServiceId=sid;name=String(svc.name||'');itemSource='product_service';itemType='service';
        }else if(m==='product'){
            var raw=String($(r).find('[data-li="product"]').val()||'');if(!raw)return;
            if(raw.indexOf('new:')===0){name=raw.slice(4).trim();if(!name)return;itemSource='new_product';itemType='product'}
            else{var pid=Number(raw||0),prod=productById(pid);if(!prod)return;productId=pid;name=String(prod.name||'');itemSource='product';itemType='product'}
        }else{
            name=String(r.querySelector('[data-li="manual_name"]').value||'').trim();if(!name)return;itemSource='manual';itemType='manual';
        }
        var q=Math.max(0,Number(r.querySelector('[data-li="qty"]').value||0)),cost=Math.max(0,Number(r.querySelector('[data-li="cost"]').value||0)),price=Math.max(0,Number(r.querySelector('[data-li="price"]').value||0)),tax=Math.max(0,Math.min(100,Number(r.querySelector('[data-li="tax"]').value||0)));if(q<=0)return;
        a.push({product_service_id:productServiceId,product_id:productId,item_source:itemSource,item_type:itemType,item_name:name,description:r.querySelector('[data-li="desc"]').value,quantity:q,unit_cost:cost,unit_price:price,tax_percent:tax,sort_order:n+1})
    });
    return a;
}
function discountAmount(sub){var t=E('discountType').value,v=Math.max(0,Number(E('discountValue').value||0));if(!t||v<=0||sub<=0)return 0;if(t==='percentage')return Math.min(sub,sub*Math.min(100,v)/100);return Math.min(sub,v)}
function financials(lines){var sub=0,cost=0;lines.forEach(function(i){sub+=i.quantity*i.unit_price;cost+=i.quantity*i.unit_cost});var disc=discountAmount(sub),tax=0;lines.forEach(function(i){var base=i.quantity*i.unit_price,share=sub>0?disc*(base/sub):0,taxable=Math.max(0,base-share);tax+=taxable*i.tax_percent/100});return {subtotal:sub,discount:disc,tax:tax,total:Math.max(0,sub-disc)+tax,cost:cost}}
function updateTotals(){var f=financials(collectLines());E('subtotalDisplay').textContent=money(f.subtotal);E('discountDisplay').textContent=money(f.discount);E('taxDisplay').textContent=money(f.tax);E('totalDisplay').textContent=money(f.total);E('costDisplay').textContent=money(f.cost)}

function openChecklist(){E('checklistModal').classList.add('show');renderBuilder()}function closeChecklist(){E('checklistModal').classList.remove('show')}document.addEventListener('click',function(e){if(e.target.closest('.js-open-checklist'))openChecklist()});E('cancelChecklist').onclick=closeChecklist;E('modalClose').onclick=closeChecklist;E('checklistModal').addEventListener('click',function(e){if(e.target===this)closeChecklist()});
function newQuestion(type){return {title:'Question',type:type||'short_answer',required:0,options:(type==='dropdown'||type==='checkbox')?['Option 1','Option 2','Option 3']:[]}}function renderBuilder(){E('builderCanvas').innerHTML=builder.map(function(s,si){return '<div class="jb-builder-section" data-s="'+si+'"><div class="jb-builder-sec-head"><input data-sec-title value="'+esc(s.title)+'"><button type="button" class="jb-icon-btn" data-del-sec><i class="bi bi-three-dots"></i></button></div>'+s.questions.map(function(q,qi){var ops=(q.type==='dropdown'||q.type==='checkbox')?'<div class="jb-options">'+q.options.map(function(o,oi){return '<div class="jb-option-row"><span>'+(oi+1)+'.</span><input data-opt="'+oi+'" value="'+esc(o)+'"><button type="button" class="jb-icon-btn" data-del-opt="'+oi+'"><i class="bi bi-x-lg"></i></button></div>'}).join('')+'<button type="button" class="jb-cancel" data-add-opt style="width:max-content;padding:5px 10px">Add option</button></div>':'';return '<div class="jb-builder-question" data-q="'+qi+'"><div class="jb-q-top"><input data-q-title value="'+esc(q.title)+'"><select data-q-type><option value="short_answer"'+(q.type==='short_answer'?' selected':'')+'>Short answer</option><option value="long_answer"'+(q.type==='long_answer'?' selected':'')+'>Long answer</option><option value="dropdown"'+(q.type==='dropdown'?' selected':'')+'>Dropdown</option><option value="checkbox"'+(q.type==='checkbox'?' selected':'')+'>Checkbox</option><option value="number"'+(q.type==='number'?' selected':'')+'>Numerical answer</option><option value="image"'+(q.type==='image'?' selected':'')+'>Upload images</option><option value="date"'+(q.type==='date'?' selected':'')+'>Date picker</option><option value="signature"'+(q.type==='signature'?' selected':'')+'>Signature</option></select><button type="button" class="jb-icon-btn" data-del-q><i class="bi bi-trash"></i></button></div>'+ops+'<div class="jb-q-foot"><label><input type="checkbox" data-required '+(q.required?'checked':'')+'> Required</label></div></div>'}).join('')+'<button type="button" class="jb-link-btn" data-add-q style="margin:10px 14px">+ Add Question</button></div>'}).join('')}
function syncBuilder(){document.querySelectorAll('[data-s]').forEach(function(sec){var si=Number(sec.dataset.s);builder[si].title=sec.querySelector('[data-sec-title]').value;sec.querySelectorAll('[data-q]').forEach(function(qel){var qi=Number(qel.dataset.q),q=builder[si].questions[qi];q.title=qel.querySelector('[data-q-title]').value;q.type=qel.querySelector('[data-q-type]').value;q.required=qel.querySelector('[data-required]').checked?1:0;q.options=[];qel.querySelectorAll('[data-opt]').forEach(function(o){q.options.push(o.value)})})})}
E('builderCanvas').addEventListener('click',function(e){var sec=e.target.closest('[data-s]'),qel=e.target.closest('[data-q]');if(e.target.closest('[data-add-q]')&&sec){syncBuilder();builder[Number(sec.dataset.s)].questions.push(newQuestion('short_answer'));renderBuilder();return}if(e.target.closest('[data-del-q]')&&sec&&qel){syncBuilder();builder[Number(sec.dataset.s)].questions.splice(Number(qel.dataset.q),1);renderBuilder();return}if(e.target.closest('[data-del-sec]')&&sec){syncBuilder();if(builder.length>1)builder.splice(Number(sec.dataset.s),1);renderBuilder();return}if(e.target.closest('[data-add-opt]')&&sec&&qel){syncBuilder();builder[Number(sec.dataset.s)].questions[Number(qel.dataset.q)].options.push('Option '+(builder[Number(sec.dataset.s)].questions[Number(qel.dataset.q)].options.length+1));renderBuilder();return}var ro=e.target.closest('[data-del-opt]');if(ro&&sec&&qel){syncBuilder();builder[Number(sec.dataset.s)].questions[Number(qel.dataset.q)].options.splice(Number(ro.dataset.delOpt),1);renderBuilder()}});E('builderCanvas').addEventListener('change',function(e){if(e.target.matches('[data-q-type]')){syncBuilder();var sec=e.target.closest('[data-s]'),q=e.target.closest('[data-q]'),obj=builder[Number(sec.dataset.s)].questions[Number(q.dataset.q)];if((obj.type==='dropdown'||obj.type==='checkbox')&&!obj.options.length)obj.options=['Option 1'];renderBuilder()}});document.querySelectorAll('[data-add-type]').forEach(function(b){b.addEventListener('click',function(){syncBuilder();var t=this.dataset.addType;if(t==='section')builder.push({title:'Section '+(builder.length+1),questions:[]});else builder[builder.length-1].questions.push(newQuestion(t));renderBuilder()})});function saveChecklist(){syncBuilder();var name=E('checklistName').value.trim()||'New checklist',items=[];builder.forEach(function(s){s.questions.forEach(function(q){if(q.title.trim())items.push({section_title:s.title.trim(),title:q.title.trim(),question_type:q.type,options:q.options,is_required:q.required})})});if(!items.length){toast('warning','Add at least one checklist question.');return}E('newChecklistJson').value=JSON.stringify({name:name,description:'',items:items});closeChecklist();toast('success','Checklist added.')}E('saveChecklist').onclick=saveChecklist;E('modalApply').onclick=saveChecklist;
function serialize(){var ss=schedules();for(var i=0;i<ss.length;i++){if(!ss[i].start_date){toast('warning','Select a visit date.');return false}if(!ss[i].start_time||!ss[i].end_time){toast('warning','Enter start and end time, or select Anytime / Schedule later.');return false}}var lines=collectLines();if(!lines.length){toast('warning','Select at least one Product / Service line item.');return false}E('scheduleJson').value=JSON.stringify(ss);E('lineItemsJson').value=JSON.stringify(lines);var custom=collectCustomFields();if(custom===null)return false;E('customFieldsJson').value=JSON.stringify(custom);if(E('jobSource').value==='direct'){var serviceLine=lines.find(function(li){if(!li.product_service_id)return false;var c=catalog('ps:'+li.product_service_id);return c&&String(c.item_type||'service')==='service'});if(!serviceLine&&Number(E('directServiceId').value||0)<=0){toast('warning','Select at least one service for this job.');return false}if(serviceLine)E('directServiceId').value=serviceLine.product_service_id}return true}
function loadMeta(){var fd=new FormData();fd.append('action','meta');fd.append('job_id',jobId);return req(fd).then(function(d){meta=d.meta||meta;initSelects();E('jobNumberPreview').value=(meta.next_job_no_preview||'Auto');E('checklistTemplates').innerHTML=(meta.checklist_templates||[]).map(function(x){return '<option value="'+x.id+'">'+esc(x.name)+'</option>'}).join('');var $ra=$('#recAssignees');if($ra.hasClass('select2-hidden-accessible'))$ra.select2('destroy');$ra.html(userOptions([])).select2({width:'100%',placeholder:'Assign'});E('recStartDate').value=today();renderWeekdays();if(!jobId){addVisit({start_date:today(),assignee_ids:[]});var preService=requestedService;if(requestedQuote){var q=(Array.isArray(meta.quotes)?meta.quotes:[]).find(function(x){return Number(x.id)===Number(requestedQuote)});if(q){E('jobSource').value='quotation';E('quoteId').value=String(q.id);requestedClient=Number(q.client_id||requestedClient||0);requestedLocation=Number(q.location_id||requestedLocation||0);preService=Number(q.product_service_id||preService||0);if(!E('title').value&&q.title)E('title').value=q.title;}}addLine(preService?{product_service_id:preService,quantity:1,line_mode:'service'}:{line_mode:'service',item_source:'product_service',quantity:1});if(requestedClient){$('#directClientId').val(String(requestedClient)).trigger('change');if(requestedLocation){refreshLocationOptions(requestedLocation);E('directLocationId').value=String(requestedLocation)}}}})}
function loadExisting(){if(!jobId)return Promise.resolve();var fd=new FormData();fd.append('action','get');fd.append('job_id',jobId);return req(fd).then(function(d){var r=d.job||{},a=d.assignments||[],s=d.schedules||[],b=d.billing||null;meta=d.meta||meta;initSelects();E('title').value=r.title||'';E('jobNumberPreview').value=r.job_no||'Auto';E('jobSource').value=Number(r.quote_id||0)>0?'quotation':'direct';E('quoteId').value=r.quote_id||0;$('#directClientId').val(String(r.client_id||'')).trigger('change.select2');refreshLocationOptions(Number(r.location_id||0));var first=a.find(function(x){return x.user_id});if(first)$('#singleUserId').val(String(first.user_id)).trigger('change');E('directServiceId').value=r.product_service_id||'';E('priority').value=r.priority||'normal';E('status').value=r.status||'scheduled';E('priorityEditor').value=E('priority').value;E('statusEditor').value=E('status').value;E('completionMode').value=r.assignment_completion_mode||'primary_only';E('internalNote').value=d.internal_note||'';E('discountType').value=r.discount_type||'';E('discountValue').value=Number(r.discount_value||0);E('discountTypeEditor').value=E('discountType').value||'fixed';E('discountValueEditor').value=Number(E('discountValue').value||0);E('visitsBox').innerHTML='';if(s.length&&s.some(function(x){return x.repeat_type&&x.repeat_type!=='none'})){switchMode('recurring');var x=s[0];E('recStartDate').value=x.start_date||today();E('recStartTime').value=(x.start_time||'').substr(0,5);E('recEndTime').value=(x.end_time||'').substr(0,5);E('recRepeat').value=x.repeat_type||'weekly';E('recEndValue').value=x.end_after_value||6;E('recEndUnit').value=x.end_after_unit||'months';E('recEndDate').value=x.repeat_end_date||'';$('#recAssignees').val((x.assignee_ids||[]).map(String)).trigger('change');E('recInstructions').value=x.instructions||'';renderWeekdays();(x.weekly_days||[]).forEach(function(w){var c=document.querySelector('[data-week][value="'+w+'"]');if(c)c.checked=true})}else{switchMode('one_off');(s.length?s:[{start_date:r.start_date,start_time:r.start_time,end_time:r.end_time,assignee_ids:a.map(function(z){return Number(z.user_id)}),instructions:r.description||''}]).forEach(addVisit)}if(b){document.querySelector('input[name="billing_type"][value="'+b.billing_type+'"]').checked=true;E('fixedInvoiceAmount').value=b.fixed_invoice_amount||'';if(E('invoiceFrequency'))E('invoiceFrequency').value=b.invoice_frequency||'monthly_last_day';}E('lineItemsBox').innerHTML='';(d.line_items&&d.line_items.length?d.line_items:[{product_service_id:r.product_service_id,quantity:1}]).forEach(addLine);$('#checklistTemplates').val((d.checklist_template_ids||[]).map(String));loadCustomFields(d.custom_fields||[]);updateSummary();updateTotals()})}
$('#directClientId').on('change',function(){refreshLocationOptions(0);E('directLocationId').value='';});
$('#serviceLocationSelect').on('change',function(){E('directLocationId').value=this.value||'';});
E('toggleCustomize').addEventListener('click',function(){addCustomField({},true);});
E('priorityEditor').addEventListener('change',function(){E('priority').value=this.value;});
E('statusEditor').addEventListener('change',function(){E('status').value=this.value;});
E('addVisitButton').onclick=function(){addVisit({start_date:today()})};E('createVisitsButton').onclick=function(){if(mode==='one_off')addVisit({start_date:today()})};document.querySelectorAll('.jb-tab').forEach(function(b){b.onclick=function(){switchMode(this.dataset.mode)}});['recStartDate','recStartTime','recEndTime','recRepeat','recEndValue','recEndUnit','recEndDate','recAnytime','recInstructions'].forEach(function(id){E(id).addEventListener('change',function(){if(id==='recStartDate')renderWeekdays();updateSummary()});E(id).addEventListener('input',updateSummary)});document.querySelectorAll('input[name="rec_end_mode"]').forEach(function(r){r.onchange=function(){E('recEndDate').disabled=this.value!=='on_date';E('recEndValue').disabled=this.value==='on_date';E('recEndUnit').disabled=this.value==='on_date';updateSummary()}});document.querySelectorAll('input[name="billing_type"]').forEach(function(r){r.onchange=function(){E('fixedInvoiceAmountWrap').style.display=this.value==='fixed_price'?'block':'none'}});E('addServiceItem').onclick=function(){addLine({line_mode:'service',item_source:'product_service',quantity:1})};E('addProductItem').onclick=function(){addLine({line_mode:'product',item_source:'product',quantity:1})};E('addManualItem').onclick=function(){addLine({line_mode:'manual',item_source:'manual',item_name:'',quantity:1,unit_cost:0,unit_price:0,tax_percent:0})};E('addDiscount').onclick=function(){E('discountEditor').classList.toggle('show');E('discountTypeEditor').value=E('discountType').value||'fixed';E('discountValueEditor').value=Number(E('discountValue').value||0);};E('applyDiscount').onclick=function(){var t=E('discountTypeEditor').value,v=Math.max(0,Number(E('discountValueEditor').value||0));if(t==='percentage')v=Math.min(100,v);E('discountType').value=v>0?t:'';E('discountValue').value=v>0?v:0;E('discountEditor').classList.remove('show');updateTotals()};E('removeDiscount').onclick=function(){E('discountType').value='';E('discountValue').value='0';E('discountValueEditor').value='0';E('discountEditor').classList.remove('show');updateTotals()};E('jobForm').addEventListener('submit',function(e){e.preventDefault();if(!this.reportValidity()){toast('warning','Complete the required fields.');return}if(!serialize())return;var fd=new FormData(this);fd.append('action','save');var btn=E('saveButton');btn.disabled=true;E('saveCaret').disabled=true;req(fd).then(function(d){var n=d.notifications||{},msg=d.message||'Job saved.';var sent=Number(n.email_sent||0),inapp=Number(n.in_app||0)+Number(n.customer_in_app_sent||0);if(sent||inapp)msg+=' Notifications: '+sent+' email(s), '+inapp+' in-app.';toast(Number(n.email_failed||0)>0?'warning':'success',msg);setTimeout(function(){location.href='jobs'},1500)}).catch(function(err){toast('error',err.message)}).finally(function(){btn.disabled=false;E('saveCaret').disabled=false})});
loadMeta().then(loadExisting).catch(function(e){toast('error',e.message)});
})();
</script>
</body></html>
