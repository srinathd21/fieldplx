<?php
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
                        <p class="jf-sub">Create scheduled field work from an approved quotation, assign one or more employees, and notify the assigned workforce and customer after the job is created.</p>
                    </div>
                    <div class="jf-actions">
                        <a href="jobs" class="jf-btn"><i class="bi bi-arrow-left"></i> Back to Jobs</a>
                    </div>
                </section>

                <form id="jobForm">
                    <input type="hidden" name="job_id" id="jobId" value="<?= (int)$jobId ?>">
                    <div class="jf-layout">
                        <div class="jf-stack">
                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-receipt"></i></span><div class="jf-card-copy"><h2>Approved Quotation</h2><p>Select the approved quotation that will be converted to executable work.</p></div></div>
                                <div class="jf-card-body">
                                    <div class="jf-grid">
                                        <div class="jf-field full">
                                            <label>Approved Quotation <span class="req">*</span></label>
                                            <select name="quote_id" id="quoteId" class="jf-select2" required><option value="">Select Approved Quotation</option></select>
                                            <div class="jf-hint">Only approved quotations not already converted to an active job are available.</div>
                                        </div>
                                        <div class="jf-quote-info full" id="quoteInfo">
                                            <div class="jf-info-grid">
                                                <div class="jf-info">
                                                    <span>Customer</span>
                                                    <strong id="quoteCustomer">-</strong>
                                                </div>
                                                <div class="jf-info">
                                                    <span>Customer Email</span>
                                                    <strong class="jf-info-email" id="quoteCustomerEmail">-</strong>
                                                </div>
                                                <div class="jf-info">
                                                    <span>Customer Phone</span>
                                                    <strong id="quoteCustomerPhone">-</strong>
                                                </div>
                                                <div class="jf-info">
                                                    <span>Service</span>
                                                    <strong id="quoteService">-</strong>
                                                </div>
                                                <div class="jf-info">
                                                    <span>Quotation No.</span>
                                                    <strong id="quoteNumber">-</strong>
                                                </div>
                                                <div class="jf-info">
                                                    <span>Quotation Total</span>
                                                    <strong class="jf-info-money" id="quoteTotal">-</strong>
                                                </div>
                                                <div class="jf-info">
                                                    <span>Workflow</span>
                                                    <strong id="quoteWorkflow">-</strong>
                                                </div>
                                                <div class="jf-info">
                                                    <span>Source</span>
                                                    <strong class="jf-info-source">Approved Quotation</strong>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="jf-field full" id="jobServiceWrap" style="display:none">
                                            <label>Service <span class="req">*</span></label>
                                            <select name="product_service_id" id="jobServiceId" class="jf-select2">
                                                <option value="">Select Service</option>
                                            </select>
                                            <div class="jf-hint">This quotation does not contain a service. Select the service for this job card. The default workflow mapped to the selected service will be saved automatically.</div>
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
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-calendar-event"></i></span><div class="jf-card-copy"><h2>Job Schedule</h2><p>Set the exact planned start and end date/time shown in employee and customer notifications.</p></div></div>
                                <div class="jf-card-body">
                                    <div class="jf-grid">
                                        <div class="jf-section">Start</div>
                                        <div class="jf-field"><label>Start Date <span class="req">*</span></label><input type="date" name="start_date" id="startDate" required></div>
                                        <div class="jf-field"><label>Start Time <span class="req">*</span></label><input type="time" name="start_time" id="startTime" required></div>
                                        <div class="jf-section">End</div>
                                        <div class="jf-field"><label>End Date <span class="req">*</span></label><input type="date" name="end_date" id="endDate" required></div>
                                        <div class="jf-field"><label>End Time <span class="req">*</span></label><input type="time" name="end_time" id="endTime" required></div>
                                    </div>
                                </div>
                            </section>

                            <section class="jf-card">
                                <div class="jf-card-head"><span class="jf-card-icon"><i class="bi bi-people"></i></span><div class="jf-card-copy"><h2>Assignment</h2><p>Select the employee, employees, or department responsible for this job.</p></div></div>
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
                                    <div class="jf-side-row"><span>Quotation</span><strong id="sideQuote">Not selected</strong></div>
                                    <div class="jf-side-row"><span>Customer</span><strong id="sideCustomer">Not selected</strong></div>
                                    <div class="jf-side-row"><span>Schedule</span><strong id="sideSchedule">Not scheduled</strong></div>
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
    var basePath = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>;
    var apiUrl = basePath + '/api/jobs.php';
    var meta = {quotes:[],users:[],departments:[],services:[],currency:{}};
    var currentQuotation = null;
    var existingJobServiceId = 0;
    var toastTimer = null;

    function el(id){return document.getElementById(id)}
    function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
    function notify(type,message){var t=el('toast'),m=el('toastMessage');if(toastTimer)clearTimeout(toastTimer);t.className='jf-toast '+(type||'')+' show';m.textContent=message||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},4200)}
    function loading(button,on){if(!button)return;button.disabled=!!on;button.classList.toggle('loading',!!on)}
    function parseResponse(response){return response.text().then(function(raw){var data,text=String(raw||'').trim();try{data=text?JSON.parse(text):{}}catch(e){throw new Error(text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!response.ok||!data.success)throw new Error(data.message||('Request failed with HTTP '+response.status+'.'));return data})}
    function request(fd){fd.append('csrf_token',csrfToken);return fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
    function money(value){var c=meta.currency||{},places=parseInt(c.decimal_places,10);if(isNaN(places))places=2;var n=Number(value||0).toFixed(places),sym=c.symbol||'';return c.symbol_position==='after'?n+(sym?' '+sym:''):(sym||'')+n}
    function optionRows(rows,label){var html='<option value="">'+esc(label)+'</option>';(rows||[]).forEach(function(x){html+='<option value="'+Number(x.id)+'">'+esc(x.name||'')+'</option>'});return html}
    function initSelect2(){$('.jf-select2').select2({width:'100%'});$('.jf-multi').select2({width:'100%',placeholder:'Select employees'})}
    function setMeta(m){
        meta=m||meta;

        var q='<option value="">Select Approved Quotation</option>';
        (meta.quotes||[]).forEach(function(x){
            q+='<option value="'+Number(x.id)+'">'+esc(x.quote_no)+' · '+esc(x.client_name)+' · '+esc(money(x.total))+'</option>';
        });
        $('#quoteId').html(q);

        $('#singleUserId').html(optionRows(meta.users,'Select Employee'));

        var uh='';
        (meta.users||[]).forEach(function(x){
            uh+='<option value="'+Number(x.id)+'">'+esc(x.name)+(x.job_title?' · '+esc(x.job_title):'')+'</option>';
        });
        $('#userIds').html(uh);

        $('#departmentId').html(optionRows(meta.departments,'Select Department'));
        $('#jobServiceId').html(optionRows(meta.services,'Select Service'));
    }

    function serviceMeta(id){
        id=Number(id||0);
        var rows=meta.services||[];

        for(var i=0;i<rows.length;i++){
            if(Number(rows[i].id)===id){
                return rows[i];
            }
        }

        return null;
    }

    function updateSelectedServicePreview(){
        if(!currentQuotation || Number(currentQuotation.product_service_id||0)>0){
            return;
        }

        var service=serviceMeta(el('jobServiceId').value);

        if(!service){
            el('quoteService').textContent='Select service below';
            el('quoteWorkflow').textContent='Workflow will be selected from the service';
            return;
        }

        el('quoteService').textContent=service.name||'-';
        el('quoteWorkflow').textContent=service.workflow_id
            ? (service.workflow_name||'Default workflow assigned')
            : 'No active workflow mapped';
    }

    function quoteDetails(id){
        if(!id){
            currentQuotation=null;
            el('quoteInfo').classList.remove('show');
            el('jobServiceWrap').style.display='none';
            el('jobServiceId').required=false;
            $('#jobServiceId').val('').trigger('change.select2');
            el('sideQuote').textContent='Not selected';
            el('sideCustomer').textContent='Not selected';
            return Promise.resolve();
        }

        var fd=new FormData();
        fd.append('action','quote_details');
        fd.append('quote_id',id);
        fd.append('job_id',jobId||0);

        return request(fd).then(function(d){
            var q=d.quotation||{};
            currentQuotation=q;
            meta.currency=d.currency||meta.currency;

            el('quoteCustomer').textContent=q.client_name||'-';
            el('quoteCustomerEmail').textContent=q.client_email||'No email';
            el('quoteCustomerPhone').textContent=q.client_phone||'-';
            el('quoteTotal').textContent=money(q.total);
            el('quoteNumber').textContent=q.quote_no||'-';
            el('sideQuote').textContent=q.quote_no||'-';
            el('sideCustomer').textContent=q.client_name||'-';

            var hasService=Number(q.product_service_id||0)>0;

            if(hasService){
                el('quoteService').textContent=q.service_name||'-';
                el('quoteWorkflow').textContent=q.workflow_id
                    ? (q.workflow_name||'Default workflow assigned')
                    : 'No active workflow mapped';

                el('jobServiceWrap').style.display='none';
                el('jobServiceId').required=false;
                $('#jobServiceId').val('').trigger('change.select2');
            }else{
                el('quoteService').textContent='Select service below';
                el('quoteWorkflow').textContent='Workflow will be selected from the service';

                el('jobServiceWrap').style.display='block';
                el('jobServiceId').required=true;

                if(existingJobServiceId>0){
                    $('#jobServiceId').val(String(existingJobServiceId)).trigger('change.select2');
                }else{
                    $('#jobServiceId').val('').trigger('change.select2');
                }

                updateSelectedServicePreview();
            }

            el('quoteInfo').classList.add('show');

            if(!el('title').value){
                el('title').value=q.title||q.request_title||'';
            }
        }).catch(function(e){
            notify('error',e.message);
            throw e;
        });
    }

    function updateAssignment(){var m=el('assignmentMode').value;el('singleUserWrap').style.display=m==='single_user'?'block':'none';el('multiUsersWrap').style.display=m==='multiple_users'?'block':'none';el('departmentWrap').style.display=m==='department'?'block':'none';el('sideAssignment').textContent=m==='single_user'?'Single Employee':m==='multiple_users'?'Multiple Employees':'Department'}
    function formatSchedule(){var sd=el('startDate').value,st=el('startTime').value,ed=el('endDate').value,et=el('endTime').value;el('sideSchedule').textContent=(sd&&st&&ed&&et)?sd+' '+st+' → '+ed+' '+et:'Not scheduled'}
    function loadMeta(){var fd=new FormData();fd.append('action','meta');fd.append('job_id',jobId||0);return request(fd).then(function(d){setMeta(d.meta||{})})}
    function loadExisting(){
        if(jobId<=0){
            if(requestedQuoteId>0){
                $('#quoteId').val(String(requestedQuoteId)).trigger('change.select2');
                return quoteDetails(requestedQuoteId);
            }
            return Promise.resolve();
        }

        var fd=new FormData();
        fd.append('action','get');
        fd.append('job_id',jobId);

        return request(fd).then(function(d){
            var r=d.job||{},a=d.assignments||[];
            setMeta(d.meta||meta);

            existingJobServiceId=Number(r.product_service_id||0);

            el('pageTitle').textContent='Edit '+(r.job_no||'Job Card');
            el('saveText').textContent='Update Job Card';
            el('jobId').value=r.id||jobId;
            $('#quoteId').val(String(r.quote_id||'')).trigger('change.select2');

            el('title').value=r.title||'';
            el('description').value=r.description||'';
            el('priority').value=r.priority||'normal';
            el('status').value=r.status||'scheduled';
            el('startDate').value=r.start_date||'';
            el('startTime').value=(r.start_time||'').substring(0,5);
            el('endDate').value=r.end_date||'';
            el('endTime').value=(r.end_time||'').substring(0,5);
            el('completionMode').value=r.assignment_completion_mode||'primary_only';

            if(r.assignment_mode==='single_user'){
                el('assignmentMode').value='single_user';
                var x=a.find(function(z){return z.user_id});
                $('#singleUserId').val(x?String(x.user_id):'').trigger('change.select2');
            }else{
                el('assignmentMode').value='multiple_users';
                $('#userIds').val(a.filter(function(z){return z.user_id}).map(function(z){return String(z.user_id)})).trigger('change');
            }

            updateAssignment();
            formatSchedule();

            return quoteDetails(r.quote_id);
        });
    }

    function validateSchedule(){var sd=el('startDate').value,st=el('startTime').value,ed=el('endDate').value,et=el('endTime').value;if(!sd||!st||!ed||!et){notify('warning','Enter start date/time and end date/time.');return false}var start=new Date(sd+'T'+st+':00'),end=new Date(ed+'T'+et+':00');if(isNaN(start.getTime())||isNaN(end.getTime())){notify('warning','Enter a valid job schedule.');return false}if(end<=start){notify('warning','End date/time must be after start date/time.');return false}return true}

    el('assignmentMode').addEventListener('change',updateAssignment);
    el('quoteId').addEventListener('change',function(){
        existingJobServiceId=0;
        quoteDetails(this.value);
    });
    el('jobServiceId').addEventListener('change',updateSelectedServicePreview);
    ['startDate','startTime','endDate','endTime'].forEach(function(id){el(id).addEventListener('change',formatSchedule)});
    el('jobForm').addEventListener('submit',function(e){e.preventDefault();if(!this.reportValidity()){notify('warning','Complete all required job card fields.');return}if(currentQuotation&&Number(currentQuotation.product_service_id||0)<=0&&!el('jobServiceId').value){notify('warning','Select a service for this quotation.');el('jobServiceId').focus();return}if(!validateSchedule())return;var mode=el('assignmentMode').value;if(mode==='single_user'&&!el('singleUserId').value){notify('warning','Select an employee.');return}if(mode==='multiple_users'&&!($('#userIds').val()||[]).length){notify('warning','Select at least one employee.');return}if(mode==='department'&&!el('departmentId').value){notify('warning','Select a department.');return}var fd=new FormData(this);fd.append('action','save');var b=el('saveButton');loading(b,true);request(fd).then(function(d){var n=d.notifications||{},msg=d.message||'Job saved.';if(!jobId){var emp=Number(n.employee_email_sent||0),cust=Number(n.customer_email_sent||0),failed=Number(n.email_failed||0);msg+=' Employee emails: '+emp+'. Customer email: '+(cust?'sent':'not sent')+'.';if(failed)msg+=' Failed emails: '+failed+'.'}notify(Number(n.email_failed||0)>0?'warning':'success',msg);setTimeout(function(){window.location.href='jobs'},1200)}).catch(function(err){notify('error',err.message)}).finally(function(){loading(b,false)})});

    initSelect2();updateAssignment();formatSchedule();
    loadMeta().then(loadExisting).catch(function(e){notify('error',e.message)});
})();
</script>
</body>
</html>