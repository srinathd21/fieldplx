<?php
/* FieldPlx Job View - Version 2.3.0 - separate service location + dynamic custom fields */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Job View';
$activePage = 'jobs';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['jobs_csrf_token'])) {
    $_SESSION['jobs_csrf_token'] = bin2hex(random_bytes(32));
}

$jobsCsrfToken = (string)$_SESSION['jobs_csrf_token'];
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Job View - FieldPlx</title>
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

        :root{
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
            --fieldplx-sidebar-width:250px;
            --fieldplx-sidebar-collapsed-width:78px;
        }

        body{
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

        .fieldplx-main-layout{
            display:block!important;
            min-height:calc(100vh - 70px)!important;
        }

        .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-width);
            min-width:0;
            transition:margin-left .25s ease;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-collapsed-width);
        }

        .fieldplx-content-wrapper{
            padding:0!important;
        }

        .qv-page{
            width:100%;
        }

        .qv-head{
            margin-bottom:18px;
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:16px;
        }

        .qv-head-copy{
            min-width:0;
        }

        .qv-title-row{
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:8px;
        }

        .qv-title{
            margin:0;
            color:var(--fd-text);
            font-size:21px;
            line-height:1.2;
            font-weight:700;
        }

        .qv-sub{
            margin:7px 0 0;
            max-width:900px;
            color:var(--fd-muted);
            font-size:10.5px;
            line-height:1.55;
        }

        .qv-actions{
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:8px;
        }

        .qv-btn{
            min-height:39px;
            padding:0 13px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            border:1px solid var(--fd-border);
            border-radius:8px;
            color:#43546c;
            background:#fff;
            box-shadow:0 4px 12px rgba(31,43,88,.04);
            font-size:10px;
            font-weight:700;
            cursor:pointer;
        }

        .qv-btn:hover{
            border-color:#cfe3ae;
            color:var(--fd-green-dark);
            background:#f9fcf4;
        }

        .qv-btn.primary{
            border-color:var(--fd-green);
            color:#fff;
            background:linear-gradient(90deg,#7fc92d,#68aa1d);
            box-shadow:0 7px 16px rgba(104,170,29,.18);
        }

        .qv-btn.primary:hover{
            color:#fff;
            background:linear-gradient(90deg,#74b824,#5d971b);
        }

        .qv-badge{
            min-height:24px;
            padding:5px 8px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border-radius:6px;
            font-size:8.5px;
            font-weight:700;
            text-transform:capitalize;
        }

        .qv-badge.draft{
            color:#123d70;
            background:#edf2f7;
        }

        .qv-badge.internal_approval,
        .qv-badge.sent,
        .qv-badge.viewed{
            color:#8a5e10;
            background:#fff7df;
        }

        .qv-badge.approved,
        .qv-badge.converted{
            color:#5d971b;
            background:#f0f8e5;
        }

        .qv-badge.rejected,
        .qv-badge.expired,
        .qv-badge.archived{
            color:#bd2f3a;
            background:#fff0f1;
        }

        .qv-badge.changes_requested{
            color:#5b4dad;
            background:#f1efff;
        }

        .qv-grid{
            display:grid;
            grid-template-columns:minmax(0,1.6fr) minmax(300px,.7fr);
            gap:16px;
        }

        .qv-stack{
            display:grid;
            align-content:start;
            gap:16px;
        }

        .qv-card{
            overflow:hidden;
            border:1px solid var(--fd-border);
            border-radius:12px;
            background:#fff;
            box-shadow:0 3px 12px rgba(24,45,76,.035);
        }

        .qv-card-head{
            min-height:54px;
            padding:12px 15px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            border-bottom:1px solid var(--fd-border);
            background:#fbfcfd;
        }

        .qv-card-head h2{
            margin:0;
            color:#17233b;
            font-size:12px;
            font-weight:700;
        }

        .qv-card-head small{
            display:block;
            margin-top:3px;
            color:var(--fd-muted);
            font-size:8.5px;
        }

        .qv-card-body{
            padding:15px;
        }

        .qv-overview{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:10px;
        }

        .qv-info{
            min-height:79px;
            padding:11px 12px;
            border:1px solid #e8edf2;
            border-radius:9px;
            background:#fbfcfd;
        }

        .qv-info-label{
            display:block;
            margin-bottom:6px;
            color:#8793a5;
            font-size:8px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.03em;
        }

        .qv-info-value{
            display:block;
            overflow-wrap:anywhere;
            color:#263750;
            font-size:10px;
            line-height:1.5;
            font-weight:600;
        }

        .qv-info-value.large{
            color:#123d70;
            font-size:13px;
        }

        .qv-section-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:10px;
        }

        .qv-detail{
            min-height:61px;
            padding:9px 10px;
            border:1px solid #edf0f4;
            border-radius:8px;
            background:#fff;
        }

        .qv-detail.full{
            grid-column:1/-1;
        }

        .qv-detail span{
            display:block;
        }

        .qv-detail .label{
            margin-bottom:5px;
            color:#8793a5;
            font-size:8px;
            font-weight:700;
            text-transform:uppercase;
        }

        .qv-detail .value{
            color:#34465f;
            font-size:9.5px;
            line-height:1.5;
            overflow-wrap:anywhere;
        }

        .qv-table-wrap{
            width:100%;
            overflow-x:auto;
            overflow-y:hidden;
            scrollbar-width:thin;
            scrollbar-color:#9aa0a6 transparent;
        }

        .qv-table-wrap::-webkit-scrollbar{
            height:3px;
        }

        .qv-table-wrap::-webkit-scrollbar-track{
            background:transparent;
        }

        .qv-table-wrap::-webkit-scrollbar-thumb{
            border-radius:999px;
            background:#9aa0a6;
        }

        .qv-table{
            width:100%;
            min-width:980px;
            border-collapse:collapse;
            white-space:nowrap;
        }

        .qv-table th{
            padding:11px 12px;
            border-bottom:1px solid var(--fd-border);
            color:#65738a;
            background:#f8fafc;
            font-size:8.5px;
            font-weight:700;
            text-align:left;
            text-transform:uppercase;
        }

        .qv-table td{
            padding:12px;
            border-bottom:1px solid #f1f3f7;
            color:#33445f;
            font-size:9.5px;
            vertical-align:top;
        }

        .qv-item-name strong,
        .qv-item-name small{
            display:block;
        }

        .qv-item-name strong{
            color:#17233b;
            font-size:10px;
        }

        .qv-item-name small{
            max-width:320px;
            margin-top:3px;
            overflow:hidden;
            color:#8995a6;
            font-size:8.3px;
            text-overflow:ellipsis;
            white-space:nowrap;
        }

        .qv-optional{
            margin-top:5px;
            padding:3px 6px;
            display:inline-flex;
            border-radius:5px;
            color:#5b4dad;
            background:#f1efff;
            font-size:7.5px;
            font-weight:700;
        }

        .qv-summary{
            display:grid;
            gap:8px;
        }

        .qv-summary-row{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:2px 0;
            color:#5f6f86;
            font-size:9.5px;
        }

        .qv-summary-row strong{
            color:#263750;
            font-size:10px;
        }

        .qv-summary-row.total{
            margin-top:5px;
            padding-top:11px;
            border-top:1px solid var(--fd-border);
            color:#17233b;
            font-size:11px;
            font-weight:700;
        }

        .qv-summary-row.total strong{
            color:#123d70;
            font-size:17px;
        }

        .qv-timeline{
            display:grid;
            gap:9px;
        }

        .qv-event{
            position:relative;
            padding:10px 10px 10px 35px;
            border:1px solid #e7ebf0;
            border-radius:8px;
            background:#fbfcfd;
        }

        .qv-event-icon{
            width:22px;
            height:22px;
            position:absolute;
            top:10px;
            left:9px;
            display:grid;
            place-items:center;
            border-radius:6px;
            color:var(--fd-green-dark);
            background:var(--fd-green-soft);
            font-size:10px;
        }

        .qv-event strong{
            display:block;
            color:#263750;
            font-size:9.5px;
        }

        .qv-event small{
            display:block;
            margin-top:3px;
            color:#8793a5;
            font-size:8px;
        }

        .qv-event p{
            margin:6px 0 0;
            color:#56667c;
            font-size:8.5px;
            line-height:1.5;
        }

        .qv-empty{
            min-height:100px;
            padding:24px;
            display:grid;
            place-items:center;
            color:#98a3b2;
            font-size:9.5px;
            text-align:center;
        }

        .qv-loading{
            min-height:320px;
            display:grid;
            place-items:center;
            border:1px solid var(--fd-border);
            border-radius:12px;
            background:#fff;
            color:#768397;
            box-shadow:0 3px 12px rgba(24,45,76,.035);
        }

        .qv-loading-inner{
            text-align:center;
        }

        .qv-spinner{
            width:26px;
            height:26px;
            margin:0 auto 10px;
            border:3px dotted var(--fd-green);
            border-radius:50%;
            animation:qvSpin .8s linear infinite;
        }

        @keyframes qvSpin{
            to{transform:rotate(360deg)}
        }

        .qv-error{
            min-height:260px;
            padding:25px;
            display:flex;
            align-items:center;
            justify-content:center;
            border:1px solid #ffd8dc;
            border-radius:12px;
            background:#fff;
            text-align:center;
        }

        .qv-error i{
            display:block;
            margin-bottom:10px;
            color:var(--fd-red);
            font-size:34px;
        }

        .qv-error h2{
            margin:0 0 7px;
            color:#17233b;
            font-size:15px;
        }

        .qv-error p{
            margin:0 0 14px;
            color:var(--fd-muted);
            font-size:10px;
        }

        .qv-toast{
            width:min(300px,calc(100vw - 24px));
            position:fixed;
            top:82px;
            right:16px;
            z-index:25000;
            padding:8px 9px;
            display:flex;
            align-items:center;
            gap:7px;
            border-radius:7px;
            color:#fff;
            background:#123d70;
            box-shadow:0 10px 26px rgba(0,17,49,.18);
            opacity:0;
            transform:translateY(-8px);
            pointer-events:none;
            transition:.18s;
        }

        .qv-toast.show{
            opacity:1;
            transform:translateY(0);
        }

        .qv-toast.error{
            background:#e45b66;
        }

        .qv-toast span{
            flex:1;
            font-size:8.5px;
            font-weight:600;
        }

        @media(max-width:1199.98px){
            .qv-grid{
                grid-template-columns:1fr;
            }

            .qv-overview{
                grid-template-columns:repeat(2,minmax(0,1fr));
            }
        }

        @media(max-width:991.98px){
            .fieldplx-main-content,
            body.fieldplx-sidebar-collapsed .fieldplx-main-content{
                width:100%!important;
                margin-left:0!important;
            }
        }

        @media(max-width:767.98px){
            .qv-page{
                width:100%;
            }

            .qv-head{
                flex-direction:column;
            }

            .qv-actions{
                width:100%;
            }

            .qv-actions .qv-btn{
                flex:1;
            }

            .qv-section-grid{
                grid-template-columns:1fr;
            }

            .qv-detail.full{
                grid-column:auto;
            }
        }

        @media(max-width:575.98px){
            .qv-overview{
                grid-template-columns:1fr;
            }

            .qv-toast{
                top:72px;
                left:12px;
                right:12px;
                width:auto;
            }
        }

        @media print{
            .fieldplx-topbar,
            .fieldplx-sidebar,
            .fieldplx-footer,
            .qv-actions,
            .qv-toast{
                display:none!important;
            }

            .fieldplx-main-content{
                margin-left:0!important;
            }

            .qv-page{
                max-width:none;
                padding:0;
            }

            .qv-card{
                break-inside:avoid;
                box-shadow:none;
            }

            body{
                background:#fff!important;
            }
        }
    

/* ==========================================================
   Job View page
   ========================================================== */
.jv-page{width:100%}
.jv-head{margin-bottom:18px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.jv-head-copy{min-width:0}
.jv-title-row{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.jv-title{margin:0;color:var(--fd-text);font-size:21px;line-height:1.2;font-weight:700}
.jv-sub{margin:7px 0 0;max-width:900px;color:var(--fd-muted);font-size:10.5px;line-height:1.55}
.jv-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.jv-btn{min-height:39px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;color:#43546c;background:#fff;box-shadow:0 4px 12px rgba(31,43,88,.04);font-size:10px;font-weight:700;cursor:pointer;text-decoration:none!important}
.jv-btn:hover{border-color:#cfe3ae;color:var(--fd-green-dark);background:#f9fcf4}
.jv-btn.primary{border-color:var(--fd-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);box-shadow:0 7px 16px rgba(104,170,29,.18)}
.jv-btn.primary:hover{color:#fff;background:linear-gradient(90deg,#74b824,#5d971b)}
.jv-btn.email{border-color:#b9d98d;color:var(--fd-green-dark);background:var(--fd-green-soft)}
.jv-btn.email:hover{color:#fff;border-color:var(--fd-green);background:linear-gradient(90deg,#7fc92d,#68aa1d)}
.jv-btn:disabled,.jv-btn.loading{opacity:.62;cursor:not-allowed;pointer-events:none}
.jv-loader{width:12px;height:12px;display:none;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:jvSpin .7s linear infinite}.jv-btn.loading .jv-loader{display:inline-block}.jv-btn.loading>i{display:none}@keyframes jvSpin{to{transform:rotate(360deg)}}
.jv-badge{min-height:24px;padding:5px 8px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;font-size:8.5px;font-weight:700;text-transform:capitalize}
.jv-badge.scheduled,.jv-badge.upcoming,.jv-badge.today{color:#123d70;background:#edf2f7}.jv-badge.active,.jv-badge.in_progress{color:#5d971b;background:#f0f8e5}.jv-badge.completed,.jv-badge.closed,.jv-badge.invoiced,.jv-badge.ready_to_invoice{color:#5d971b;background:#f0f8e5}.jv-badge.cancelled{color:#b9444d;background:#fff0f1}.jv-badge.waiting_customer,.jv-badge.waiting_material,.jv-badge.rescheduled,.jv-badge.needs_review{color:#8a5e10;background:#fff7df}
.jv-summary{margin-bottom:16px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.jv-stat{min-height:104px;padding:15px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}
.jv-stat-label{display:block;margin-bottom:8px;color:#7b889a;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.03em}.jv-stat-value{display:block;color:#17233b;font-size:12px;line-height:1.45;font-weight:700;overflow-wrap:anywhere}.jv-stat-value.money{color:#123d70;font-size:17px}
.jv-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(300px,.72fr);gap:16px}.jv-stack{display:grid;align-content:start;gap:16px}
.jv-card{overflow:hidden;border:1px solid var(--fd-border);border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}
.jv-card-head{min-height:54px;padding:12px 15px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--fd-border);background:#fbfcfd}.jv-card-head h2{margin:0;color:#17233b;font-size:12px;font-weight:700}.jv-card-head small{display:block;margin-top:3px;color:var(--fd-muted);font-size:8.5px}
.jv-card-body{padding:15px}.jv-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.jv-detail{min-height:64px;padding:10px 11px;border:1px solid #edf0f4;border-radius:8px;background:#fff}.jv-detail.full{grid-column:1/-1}.jv-detail .label{display:block;margin-bottom:5px;color:#8793a5;font-size:8px;font-weight:700;text-transform:uppercase}.jv-detail .value{display:block;color:#34465f;font-size:9.5px;line-height:1.55;overflow-wrap:anywhere;white-space:pre-wrap}
.jv-schedule{display:grid;grid-template-columns:1fr 1fr;gap:10px}.jv-schedule-box{padding:13px;border:1px solid #e6ebf0;border-radius:9px;background:#fbfcfd}.jv-schedule-box span,.jv-schedule-box strong{display:block}.jv-schedule-box span{margin-bottom:5px;color:#8793a5;font-size:8px;font-weight:700;text-transform:uppercase}.jv-schedule-box strong{color:#263750;font-size:11px}
.jv-table-wrap{width:100%;overflow-x:auto;overflow-y:hidden}.jv-table{width:100%;min-width:680px;border-collapse:collapse;white-space:nowrap}.jv-table th{padding:11px 12px;border-bottom:1px solid var(--fd-border);color:#65738a;background:#f8fafc;font-size:8.5px;font-weight:700;text-align:left;text-transform:uppercase}.jv-table td{padding:12px;border-bottom:1px solid #f1f3f7;color:#33445f;font-size:9.5px;vertical-align:middle}.jv-person strong,.jv-person small{display:block}.jv-person strong{color:#17233b;font-size:10px}.jv-person small{margin-top:2px;color:#8793a5;font-size:8.3px}.jv-primary{padding:4px 6px;display:inline-flex;border-radius:5px;color:#5d971b;background:#f0f8e5;font-size:8px;font-weight:700}
.jv-email-note{padding:12px;border:1px solid #dcebc8;border-radius:9px;color:#536476;background:#f9fcf4;font-size:9px;line-height:1.55}.jv-email-note strong{color:#31425b}.jv-empty{padding:25px;color:#98a3b2;font-size:9.5px;text-align:center}.jv-loading{min-height:320px;display:grid;place-items:center;border:1px solid var(--fd-border);border-radius:12px;background:#fff;color:#768397}.jv-spinner{width:26px;height:26px;margin:0 auto 10px;border:3px dotted var(--fd-green);border-radius:50%;animation:jvSpin .8s linear infinite}.jv-error{min-height:260px;padding:25px;display:flex;align-items:center;justify-content:center;border:1px solid #ffd8dc;border-radius:12px;background:#fff;text-align:center}.jv-error i{display:block;margin-bottom:10px;color:var(--fd-red);font-size:34px}.jv-error h2{margin:0 0 7px;color:#17233b;font-size:15px}.jv-error p{margin:0 0 14px;color:var(--fd-muted);font-size:10px}
.jv-toast{width:min(330px,calc(100vw - 24px));position:fixed;top:82px;right:16px;z-index:25000;padding:9px 10px;display:flex;align-items:flex-start;gap:8px;border-radius:7px;color:#fff;background:#123d70;box-shadow:0 10px 26px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s}.jv-toast.show{opacity:1;transform:translateY(0)}.jv-toast.success{background:#5d971b}.jv-toast.error{background:#e45b66}.jv-toast span{flex:1;font-size:8.5px;line-height:1.45}
@media(max-width:1199.98px){.jv-grid{grid-template-columns:1fr}.jv-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767.98px){.jv-head{flex-direction:column}.jv-actions{width:100%}.jv-actions .jv-btn{flex:1}.jv-details,.jv-schedule{grid-template-columns:1fr}.jv-detail.full{grid-column:auto}}
@media(max-width:575.98px){.jv-summary{grid-template-columns:1fr}.jv-toast{top:72px;left:12px;right:12px;width:auto}}


/* ==========================================================
   Job View 2.0 - expanded job card
   ========================================================== */
.jv-source-pill{min-height:25px;padding:5px 8px;display:inline-flex;align-items:center;gap:5px;border-radius:6px;color:#5d971b;background:#f0f8e5;font-size:8.5px;font-weight:700}
.jv-source-pill.quote{color:#123d70;background:#edf4fb}
.jv-source-pill i{font-size:10px}
.jv-card-head-actions{display:flex;align-items:center;gap:7px}
.jv-count{min-width:24px;height:24px;padding:0 7px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;color:#5d971b;background:#f0f8e5;font-size:8px;font-weight:700}
.jv-schedule-list{display:grid;gap:10px}
.jv-schedule-plan{padding:12px;border:1px solid #e7ecf1;border-radius:10px;background:#fff}
.jv-schedule-plan-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px}
.jv-schedule-plan-title{display:flex;align-items:center;gap:8px;color:#17233b;font-size:10.5px;font-weight:700}
.jv-schedule-plan-title span{width:25px;height:25px;display:inline-grid;place-items:center;border-radius:7px;color:#5d971b;background:#f0f8e5;font-size:9px}
.jv-repeat-badge{padding:4px 7px;border-radius:6px;color:#123d70;background:#edf4fb;font-size:7.8px;font-weight:700;white-space:nowrap}
.jv-schedule-plan-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.jv-mini{padding:8px 9px;border:1px solid #edf1f4;border-radius:7px;background:#fbfcfd;min-width:0}
.jv-mini label{display:block;margin-bottom:4px;color:#8793a5;font-size:7.5px;font-weight:700;text-transform:uppercase}
.jv-mini strong{display:block;color:#34465f;font-size:9px;line-height:1.45;overflow-wrap:anywhere}
.jv-mini.full{grid-column:1/-1}
.jv-team-chips{display:flex;flex-wrap:wrap;gap:5px}
.jv-team-chip{padding:4px 7px;display:inline-flex;align-items:center;gap:4px;border:1px solid #dcebc8;border-radius:999px;color:#536476;background:#f9fcf4;font-size:8px;font-weight:600}
.jv-team-chip i{color:#74b824;font-size:8px}
.jv-billing-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
.jv-billing-box{min-height:70px;padding:10px;border:1px solid #e9edf2;border-radius:8px;background:#fbfcfd}
.jv-billing-box span,.jv-billing-box strong{display:block}.jv-billing-box span{margin-bottom:5px;color:#8793a5;font-size:7.8px;font-weight:700;text-transform:uppercase}.jv-billing-box strong{color:#263750;font-size:10px;line-height:1.45}.jv-billing-box strong.money{color:#123d70;font-size:13px}
.jv-auto-pay{margin-top:10px;padding:10px 11px;display:flex;align-items:center;gap:9px;border:1px solid #dcebc8;border-radius:8px;background:#f9fcf4;color:#536476;font-size:9px}.jv-auto-pay i{width:28px;height:28px;display:grid;place-items:center;border-radius:8px;color:#5d971b;background:#eef8df;font-size:13px}.jv-auto-pay strong{display:block;color:#31425b;font-size:9.5px}.jv-auto-pay small{display:block;margin-top:2px;color:#7c899a;font-size:8px}
.jv-attachments{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
.jv-file{min-height:72px;padding:9px;display:flex;align-items:center;gap:9px;border:1px solid #e9edf2;border-radius:8px;color:inherit;background:#fff;text-decoration:none!important}.jv-file:hover{border-color:#cfe3ae;background:#f9fcf4}.jv-file-preview{width:43px;height:43px;flex:0 0 43px;overflow:hidden;display:grid;place-items:center;border-radius:8px;color:#5d971b;background:#f0f8e5;font-size:17px}.jv-file-preview img{width:100%;height:100%;object-fit:cover}.jv-file-copy{min-width:0}.jv-file-copy strong{display:block;overflow:hidden;color:#263750;font-size:8.8px;text-overflow:ellipsis;white-space:nowrap}.jv-file-copy small{display:block;margin-top:4px;color:#8793a5;font-size:7.7px}
.jv-checklists{display:flex;flex-wrap:wrap;gap:7px}
.jv-checklist{padding:8px 10px;display:inline-flex;align-items:center;gap:7px;border:1px solid #dcebc8;border-radius:8px;color:#43546c;background:#f9fcf4;font-family:inherit;font-size:8.5px;font-weight:600;text-align:left;cursor:pointer;transition:.16s ease}
.jv-checklist:hover,.jv-checklist:focus{border-color:#a9cf75;color:#5d971b;background:#f3fae9;box-shadow:0 0 0 3px rgba(116,184,36,.10);outline:0}
.jv-checklist i{color:#74b824;font-size:11px}.jv-checklist small{display:block;margin-top:2px;color:#8793a5;font-size:7.5px;font-weight:500}
.jv-checklist-arrow{margin-left:2px;color:#8fa0b2!important;font-size:9px!important}

/* ---------- Full-width checklist details modal ---------- */
.jv-checklist-modal{position:fixed;inset:0;z-index:30000;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(0,17,49,.58);backdrop-filter:blur(2px)}
.jv-checklist-modal.open{display:flex}
.jv-checklist-modal-dialog{width:calc(100vw - 48px);max-width:1500px;height:calc(100vh - 48px);display:flex;flex-direction:column;overflow:hidden;border:1px solid #dfe6ef;border-radius:14px;background:#fff;box-shadow:0 24px 70px rgba(0,17,49,.26)}
.jv-checklist-modal-head{min-height:72px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;border-bottom:1px solid var(--fd-border);background:#fff}
.jv-checklist-modal-title-wrap{min-width:0}.jv-checklist-modal-title{margin:0;color:#17233b;font-size:16px;font-weight:700}.jv-checklist-modal-sub{margin-top:4px;color:#7f8c9e;font-size:9px;line-height:1.45}
.jv-checklist-modal-actions{display:flex;align-items:center;gap:8px}.jv-checklist-close{width:36px;height:36px;display:inline-grid;place-items:center;border:1px solid var(--fd-border);border-radius:8px;color:#40536b;background:#fff;font-size:16px;cursor:pointer}.jv-checklist-close:hover{border-color:#cfe3ae;color:#5d971b;background:#f9fcf4}
.jv-checklist-modal-meta{padding:12px 18px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;border-bottom:1px solid var(--fd-border);background:#fbfcfd}
.jv-checklist-modal-meta-box{min-height:62px;padding:9px 10px;border:1px solid #e8edf2;border-radius:8px;background:#fff}.jv-checklist-modal-meta-box span,.jv-checklist-modal-meta-box strong{display:block}.jv-checklist-modal-meta-box span{margin-bottom:5px;color:#8793a5;font-size:7.8px;font-weight:700;text-transform:uppercase}.jv-checklist-modal-meta-box strong{color:#263750;font-size:10px;line-height:1.4}
.jv-checklist-progress{height:6px;margin-top:6px;overflow:hidden;border-radius:999px;background:#edf1f4}.jv-checklist-progress>span{height:100%;display:block;border-radius:999px;background:#74b824}
.jv-checklist-modal-body{min-height:0;flex:1;overflow:auto;padding:18px;background:#f7f9fb}
.jv-checklist-section{margin-bottom:14px;overflow:hidden;border:1px solid #e2e8ef;border-radius:10px;background:#fff}.jv-checklist-section:last-child{margin-bottom:0}
.jv-checklist-section-head{padding:11px 14px;border-bottom:1px solid #edf1f4;color:#17233b;background:#fbfcfd;font-size:10.5px;font-weight:700}
.jv-checklist-question{padding:13px 14px;display:grid;grid-template-columns:34px minmax(0,1fr) minmax(220px,.55fr);gap:12px;border-bottom:1px solid #f0f2f5}.jv-checklist-question:last-child{border-bottom:0}
.jv-checklist-question-no{width:28px;height:28px;display:grid;place-items:center;border-radius:8px;color:#5d971b;background:#f0f8e5;font-size:9px;font-weight:700}
.jv-checklist-question-title{display:flex;align-items:center;flex-wrap:wrap;gap:6px;color:#263750;font-size:10.5px;font-weight:700}.jv-checklist-required{padding:3px 6px;border-radius:5px;color:#b9444d;background:#fff0f1;font-size:7px;font-weight:700}.jv-checklist-type{padding:3px 6px;border-radius:5px;color:#47627d;background:#edf4fb;font-size:7px;font-weight:700}
.jv-checklist-question-desc{margin-top:4px;color:#7f8c9e;font-size:8.5px;line-height:1.5;white-space:pre-wrap}.jv-checklist-options{margin-top:7px;display:flex;flex-wrap:wrap;gap:5px}.jv-checklist-option{padding:4px 7px;border:1px solid #e2e8ef;border-radius:999px;color:#536476;background:#fbfcfd;font-size:7.8px}
.jv-checklist-answer{min-height:48px;padding:9px 10px;border:1px solid #e7ecf1;border-radius:8px;background:#fbfcfd}.jv-checklist-answer-label{display:block;margin-bottom:5px;color:#8793a5;font-size:7.5px;font-weight:700;text-transform:uppercase}.jv-checklist-answer-value{color:#34465f;font-size:9px;line-height:1.5;overflow-wrap:anywhere;white-space:pre-wrap}.jv-checklist-answer-value.empty{color:#98a3b2}.jv-checklist-completion{margin-top:6px;display:flex;align-items:center;gap:5px;color:#8793a5;font-size:7.8px}.jv-checklist-completion.done{color:#5d971b}.jv-checklist-completion i{font-size:9px}
.jv-checklist-modal-empty{min-height:220px;display:grid;place-items:center;color:#98a3b2;font-size:10px;text-align:center}
body.jv-modal-open{overflow:hidden!important}
@media(max-width:991.98px){.jv-checklist-modal{padding:12px}.jv-checklist-modal-dialog{width:calc(100vw - 24px);height:calc(100vh - 24px)}.jv-checklist-question{grid-template-columns:30px minmax(0,1fr)}.jv-checklist-answer{grid-column:2}}
@media(max-width:575.98px){.jv-checklist-modal{padding:0}.jv-checklist-modal-dialog{width:100vw;height:100vh;border:0;border-radius:0}.jv-checklist-modal-head{padding:12px 13px}.jv-checklist-modal-meta{padding:10px 13px;grid-template-columns:repeat(2,minmax(0,1fr))}.jv-checklist-modal-body{padding:12px}.jv-checklist-question{padding:11px;grid-template-columns:28px minmax(0,1fr);gap:9px}.jv-checklist-answer{grid-column:1/-1}}
.jv-source-details{display:grid;gap:8px}.jv-source-row{padding:9px 10px;display:flex;justify-content:space-between;gap:12px;border:1px solid #edf0f4;border-radius:8px;background:#fff}.jv-source-row span{color:#8793a5;font-size:8px;font-weight:700;text-transform:uppercase}.jv-source-row strong{color:#34465f;font-size:9px;text-align:right;overflow-wrap:anywhere}
.jv-items-table{min-width:920px}
.jv-item-source{padding:4px 7px;display:inline-flex;align-items:center;border-radius:999px;font-size:7.8px;font-weight:700;white-space:nowrap}
.jv-item-source.service{color:#5d971b;background:#f0f8e5}
.jv-item-source.product{color:#123d70;background:#edf4fb}
.jv-item-source.manual{color:#7a5b10;background:#fff7df}
.jv-item-copy strong,.jv-item-copy small{display:block}
.jv-item-copy strong{color:#17233b;font-size:10px}
.jv-item-copy small{max-width:340px;margin-top:3px;overflow:hidden;color:#8793a5;font-size:8.2px;text-overflow:ellipsis;white-space:nowrap}
.jv-items-summary{padding:12px 15px 14px;display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,360px);gap:16px;border-top:1px solid var(--fd-border);background:#fbfcfd}
.jv-items-totals{display:grid;gap:0}
.jv-items-total-row{min-height:34px;padding:8px 0;display:flex;align-items:center;justify-content:space-between;gap:14px;border-bottom:1px solid #e8edf2;color:#5f6f86;font-size:9.5px}
.jv-items-total-row:last-child{border-bottom:0}
.jv-items-total-row strong{color:#263750;font-size:10px}
.jv-items-total-row.grand{font-weight:700;color:#17233b}.jv-items-total-row.grand strong{color:#123d70;font-size:14px}
.jv-note-view{min-height:90px;padding:13px;border:1px dashed #d9e1e9;border-radius:9px;background:#fbfcfd;color:#40536b;font-size:9.5px;line-height:1.65;white-space:pre-wrap}
.jv-note-view.empty{display:grid;place-items:center;color:#98a3b2;text-align:center}
.jv-custom-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.jv-custom-field{min-height:64px;padding:10px 11px;border:1px solid #edf0f4;border-radius:8px;background:#fff}.jv-custom-field .label{display:block;margin-bottom:5px;color:#8793a5;font-size:8px;font-weight:700;text-transform:uppercase}.jv-custom-field .value{display:block;color:#34465f;font-size:9.5px;line-height:1.55;overflow-wrap:anywhere;white-space:pre-wrap}.jv-location-details{display:grid;gap:8px}.jv-location-row{padding:9px 10px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;border:1px solid #edf0f4;border-radius:8px;background:#fff}.jv-location-row span{flex:0 0 86px;color:#8793a5;font-size:8px;font-weight:700;text-transform:uppercase}.jv-location-row strong{flex:1;color:#34465f;font-size:9px;line-height:1.5;text-align:right;overflow-wrap:anywhere;white-space:pre-wrap}
@media(max-width:1199.98px){.jv-schedule-plan-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.jv-attachments{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767.98px){.jv-schedule-plan-grid,.jv-billing-grid,.jv-attachments,.jv-custom-fields{grid-template-columns:1fr}.jv-mini.full{grid-column:auto}.jv-schedule-plan-head{align-items:center}.jv-items-summary{grid-template-columns:1fr}}



        /* ==========================================================
        /* ==========================================================
           Job View v4 - Jobber placement + FieldPlx visual system
           ========================================================== */
        :root{
            --jvx-navy:#073247;
            --jvx-copy:#314f5d;
            --jvx-muted:#6d838e;
            --jvx-line:#dce4e8;
            --jvx-line-soft:#edf0f2;
            --jvx-green:#2f9228;
            --jvx-green-dark:#277b22;
            --jvx-green-soft:#edf6e8;
            --jvx-red:#d94f58;
        }
        .fd-dashboard{max-width:none!important;padding:0!important;background:#fff!important}
        .jvx-page{width:100%;min-height:calc(100vh - 70px);background:#fff;color:var(--jvx-copy)}
        .jvx-layout{display:grid;grid-template-columns:minmax(0,1fr) 315px;align-items:start}
        .jvx-main{min-width:0;padding:20px 26px 34px}
        .jvx-rail{min-height:calc(100vh - 70px);padding:20px 24px;border-left:1px solid var(--jvx-line);background:#fff;position:sticky;top:70px;display:grid;align-content:start;gap:18px}
        .jvx-header{min-height:84px;display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:0 0 18px;border-bottom:1px solid var(--jvx-line)}
        .jvx-title-area{min-width:0}.jvx-kicker{display:flex;align-items:center;gap:10px;margin:3px 0 15px}.jvx-kicker>i{font-size:16px;color:var(--jvx-green)}
        .jvx-status{min-height:22px;padding:4px 9px;display:inline-flex;align-items:center;gap:5px;border-radius:999px;background:var(--jvx-green-soft);color:#467c16;font-size:10px;font-weight:600}.jvx-status:before{width:6px;height:6px;content:"";border-radius:50%;background:currentColor}
        .jvx-status.overdue{background:#fff0f0;color:#c23e46}.jvx-status.completed,.jvx-status.closed,.jvx-status.archived{background:#eef2f4;color:#3f5b68}
        .jvx-title{margin:0;color:#002333;font-size:30px;line-height:1.15;font-weight:700}.jvx-source{margin-top:7px;color:#758b95;font-size:10px}
        .jvx-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.jvx-btn{min-height:38px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--jvx-line);border-radius:8px;background:#fff;color:#34515f;font:600 12px Arial,Helvetica,sans-serif;cursor:pointer;text-decoration:none!important}.jvx-btn:hover{border-color:#b8d88e;background:#f8fbf4;color:#438b20}.jvx-btn.primary{border-color:var(--jvx-green);background:var(--jvx-green);color:#fff}.jvx-btn.primary:hover{background:var(--jvx-green-dark);color:#fff}.jvx-btn.icon{width:38px;padding:0}.jvx-btn.danger{border-color:#f1c9cc;color:#c94049}.jvx-btn:disabled{opacity:.55;cursor:not-allowed}
        .jvx-more-wrap,.jvx-plus{position:relative}.jvx-more,.jvx-plus-menu{position:absolute;right:0;top:45px;z-index:2200;display:none;padding:7px;border:1px solid var(--jvx-line);border-radius:9px;background:#fff;box-shadow:0 12px 32px rgba(0,17,49,.16)}.jvx-more{width:235px}.jvx-plus-menu{width:180px}.jvx-more.open,.jvx-plus-menu.open{display:block}.jvx-more button,.jvx-more a,.jvx-plus-menu button{width:100%;min-height:38px;padding:8px 10px;display:flex;align-items:center;gap:10px;border:0;border-radius:7px;background:transparent;color:#34515f;font:600 11px Arial,Helvetica,sans-serif;cursor:pointer;text-align:left;text-decoration:none!important}.jvx-more button:hover,.jvx-more a:hover,.jvx-plus-menu button:hover{background:#f5f8f2;color:#2f8725}.jvx-more .danger{color:var(--jvx-red)}.jvx-more hr{margin:5px 0;border:0;border-top:1px solid #e6ebef}.jvx-more i{width:16px;font-size:15px;text-align:center}
        .jvx-overview{padding:0 0 24px;margin-top:20px;border-bottom:1px solid var(--jvx-line)}.jvx-top{display:grid;grid-template-columns:minmax(300px,.98fr) minmax(360px,1.12fr);gap:18px}.jvx-customer{min-height:176px;padding:18px 20px;position:relative;border:1px solid var(--jvx-line);border-radius:8px;background:#fff}.jvx-customer-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.jvx-customer-name{display:flex;align-items:center;gap:7px;color:var(--jvx-navy);font-size:15px;font-weight:700}.jvx-customer-dot{width:6px;height:6px;border-radius:50%;background:#339af0}.jvx-customer-menu-wrap{position:relative}.jvx-customer-menu-button{width:31px;height:31px;display:grid;place-items:center;border:0;border-radius:7px;background:#fff;color:#365465;cursor:pointer}.jvx-customer-menu-button:hover{background:#f2f5f6}.jvx-customer-menu{position:absolute;top:35px;right:0;z-index:1400;width:170px;padding:6px;display:none;border:1px solid var(--jvx-line);border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(0,17,49,.13)}.jvx-customer-menu.open{display:block}.jvx-customer-menu button{width:100%;padding:9px 10px;border:0;border-radius:6px;background:#fff;color:#34515f;font:600 10px Arial;text-align:left;cursor:pointer}.jvx-customer-menu button:hover{background:#f5f8f2;color:#2f8725}.jvx-customer-menu .danger{color:#c94049}.jvx-address-label{margin-top:14px;color:#748994;font-size:10px}.jvx-address{margin-top:4px;color:#314f5d;font-size:12px;line-height:1.42;white-space:pre-line}.jvx-contact{margin-top:10px;display:grid;gap:4px}.jvx-contact a{color:#2f8725!important;text-decoration:underline!important;font-size:12px}.jvx-customer-lock{margin-top:9px;color:#889ba4;font-size:9px;line-height:1.4}
        .jvx-detail-list{display:grid}.jvx-detail-row{min-height:43px;padding:9px 0;display:grid;grid-template-columns:155px 1fr;gap:10px;border-bottom:1px solid var(--jvx-line);align-items:center}.jvx-detail-row:last-child{border-bottom:0}.jvx-detail-row span{color:#69808d;font-size:11px}.jvx-detail-row strong{color:#314f5d;font-size:12px;font-weight:500}.jvx-inline-edit{display:none;margin-top:16px;padding:16px;border:1px solid var(--jvx-line);border-radius:8px;background:#fbfcfc}.jvx-inline-edit.open{display:block}.jvx-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.jvx-field{margin-bottom:11px}.jvx-field>label{display:block;margin-bottom:5px;color:#314f5d;font-size:10px;font-weight:600}.jvx-floating{position:relative}.jvx-floating label{position:absolute;top:6px;left:11px;z-index:1;color:#667e89;font-size:8px;pointer-events:none}.jvx-control{width:100%;min-height:45px;padding:9px 11px;border:1px solid var(--jvx-line);border-radius:8px;background:#fff;color:#173746;font:400 12px Arial,Helvetica,sans-serif;outline:0}.jvx-floating .jvx-control{padding-top:17px}.jvx-control:focus{border-color:#73b848;box-shadow:0 0 0 3px rgba(116,184,36,.11)}.jvx-edit-actions{margin-top:13px;display:flex;justify-content:flex-end;gap:8px}
        .jvx-card{margin-top:22px;border:1px solid var(--jvx-line);border-radius:9px;background:#fff;overflow:visible}.jvx-card-head{min-height:62px;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--jvx-line)}.jvx-card-head h2{margin:0;color:var(--jvx-navy);font-size:18px;font-weight:700}.jvx-link{padding:0;border:0;background:none;color:#2f8725;font:600 11px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}.jvx-icon-btn{width:33px;height:33px;display:grid;place-items:center;border:0;border-radius:7px;background:#fff;color:#173f4e;cursor:pointer}.jvx-icon-btn:hover{background:#f0f8e5;color:#2f8725}
        .jvx-table-wrap{overflow-x:auto}.jvx-table{width:100%;min-width:720px;border-collapse:collapse}.jvx-table th{padding:12px 15px;border-bottom:1px solid var(--jvx-line);color:#173746;font-size:10px;font-weight:700;text-align:left}.jvx-table td{padding:14px 15px;border-bottom:1px solid var(--jvx-line-soft);color:#314f5d;font-size:11px;vertical-align:middle}.jvx-item strong{display:block;color:var(--jvx-navy);font-size:11px}.jvx-item small{display:block;margin-top:5px;max-width:430px;color:#607985;font-size:10px;line-height:1.4;white-space:normal}.jvx-totals{width:min(440px,100%);margin:14px 18px 16px auto}.jvx-total-row{min-height:42px;padding:8px 0;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e5e9eb;color:#425e6b;font-size:11px}.jvx-total-row.grand{font-weight:700;color:var(--jvx-navy);font-size:12px}.jvx-total-row.total-cost{border-bottom:0}.jvx-money{text-align:right}
        /* Product / Service edit: follows current Add Job page */
        .jvx-products-edit{display:none;padding:16px 14px 14px}.jvx-products-edit.open{display:block}.jvx-products-edit .jvx-lines{display:grid;gap:0}.jvx-edit-line{position:relative;margin:0 0 14px;padding:16px 14px 15px;border:1px solid var(--jvx-line);border-radius:8px;background:#fff}.jvx-edit-line-top{display:grid;grid-template-columns:minmax(220px,1.45fr) minmax(86px,.55fr) minmax(105px,.72fr) minmax(110px,.76fr) minmax(115px,.78fr) 34px;gap:10px;align-items:end}.jvx-line-field,.jvx-money-field,.jvx-qty-field{position:relative}.jvx-line-select{width:100%;height:50px;padding:18px 32px 6px 10px;border:1px solid var(--jvx-line);border-radius:8px;background:#fff;color:#173845;font:13px Arial,Helvetica,sans-serif;outline:0;appearance:auto}.jvx-line-select:focus,.jvx-control:focus{border-color:#79ad64;box-shadow:0 0 0 2px rgba(116,184,36,.10)}.jvx-line-field>label,.jvx-money-field>label,.jvx-qty-field>label{position:absolute;left:10px;top:5px;z-index:3;color:#61798a;font-size:9px;pointer-events:none}.jvx-money-field input,.jvx-qty-field input{width:100%;height:50px;padding:18px 10px 6px;border:1px solid var(--jvx-line);border-radius:8px;background:#fff;color:#173845;font-size:13px;outline:0}.jvx-line-total{height:50px;padding:6px 10px;display:flex;flex-direction:column;justify-content:center;align-items:flex-end;border:1px solid var(--jvx-line);border-radius:8px;text-align:right}.jvx-line-total small{color:#61798a;font-size:9px}.jvx-line-total strong{margin-top:3px;color:#173845;font-size:13px;font-weight:500}.jvx-edit-line textarea{width:100%;min-height:82px;margin-top:10px;padding:12px;border:1px solid var(--jvx-line);border-radius:8px;background:#fff;color:#173845;font:13px Arial,Helvetica,sans-serif;resize:vertical;outline:0}.jvx-remove{width:34px;height:34px;display:grid;place-items:center;border:0;border-radius:7px;background:transparent;color:#c94b55;cursor:pointer}.jvx-remove:hover{background:#fff0f1}.jvx-add-line{height:37px;padding:0 13px;border:0;border-radius:7px;background:var(--jvx-green);color:#fff;font:700 11px Arial,Helvetica,sans-serif;cursor:pointer}.jvx-add-line:hover{background:var(--jvx-green-dark)}
        .jvx-products-edit .select2-container{width:100%!important}.jvx-products-edit .select2-selection--single{height:50px!important;border:1px solid var(--jvx-line)!important;border-radius:8px!important;background:#fff!important}.jvx-products-edit .select2-selection__rendered{height:50px!important;padding:18px 32px 5px 10px!important;color:#173845!important;font-size:13px!important;line-height:24px!important}.jvx-products-edit .select2-selection__arrow{height:48px!important}.jvx-catalog-result{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:3px 0}.jvx-catalog-copy{min-width:0}.jvx-catalog-name{display:flex;align-items:center;gap:6px;color:#173845;font-size:12px}.jvx-catalog-desc{max-width:430px;margin-top:2px;overflow:hidden;color:#7b8d96;font-size:9px;text-overflow:ellipsis;white-space:nowrap}.jvx-catalog-price{color:#31505d;font-size:10px;white-space:nowrap}.jvx-type-badge{padding:2px 5px;border-radius:999px;background:#edf4fb;color:#123d70;font-size:7px;font-weight:700}.jvx-type-badge.service{background:#f0f8e5;color:#5d971b}
        .jvx-edit-totals-wrap{margin-top:20px;padding-top:18px;border-top:3px solid #e1e6e9;display:grid;grid-template-columns:1fr minmax(380px,48%)}.jvx-edit-totals{grid-column:2}.jvx-edit-total-row{min-height:44px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--jvx-line);color:#445f6d;font-size:13px}.jvx-edit-total-row.grand{min-height:50px;border-bottom:3px solid #e1e6e9;color:#173845;font-size:15px;font-weight:700}.jvx-edit-total-row.grand strong{font-size:18px}.jvx-total-action{border:0;background:transparent;color:#2f8725;font:700 12px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}.jvx-adjust-row{display:none;grid-template-columns:minmax(0,1fr) 115px auto;gap:7px;padding:8px 0;border-bottom:1px solid var(--jvx-line)}.jvx-adjust-row.show{display:grid}.jvx-adjust-row .jvx-control{min-height:38px;height:38px}.jvx-small-green{height:38px;padding:0 12px;border:0;border-radius:7px;background:var(--jvx-green);color:#fff;font-weight:700;font-size:11px;cursor:pointer}.jvx-products-actions{padding-top:14px;display:flex;justify-content:flex-end;gap:8px}
        .jvx-simple-row{min-height:72px;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:15px}.jvx-simple-copy{display:flex;align-items:center;gap:12px;color:#425d69;font-size:11px}.jvx-simple-copy i{width:31px;height:31px;display:grid;place-items:center;border-right:1px solid #e0e6e9;color:#173f4e;font-size:17px}.jvx-entries{border-top:1px solid var(--jvx-line-soft)}.jvx-entry{padding:10px 20px;display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--jvx-line-soft);color:#516b77;font-size:10px}.jvx-entry strong{color:#173746}
        .jvx-visits-summary{padding:18px 20px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;border-bottom:1px solid var(--jvx-line)}.jvx-visits-summary span{display:block;color:#6d838e;font-size:10px}.jvx-visits-summary strong{display:block;margin-top:7px;color:#314f5d;font-size:11px;font-weight:500}.jvx-summary-label{display:flex!important;align-items:center;gap:7px}.jvx-visit-tools{padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px}.jvx-segment{display:inline-flex;border:1px solid var(--jvx-line);border-radius:999px;overflow:hidden}.jvx-segment button{padding:7px 11px;border:0;background:#fff;color:#34515f;font:600 10px Arial;cursor:pointer}.jvx-segment button.active{background:#eff6e9;color:#2f8725}.jvx-selection{display:none;padding:10px 20px;align-items:center;gap:22px;border-top:1px solid var(--jvx-line-soft);border-bottom:1px solid var(--jvx-line);color:#425d69;font-size:11px}.jvx-selection.show{display:flex}.jvx-selection a,.jvx-selection button{padding:0;border:0;background:none;color:#2f8725;font:600 11px Arial;text-decoration:underline;cursor:pointer}.jvx-selection .danger{color:#d24850}.jvx-checkbox{width:17px;height:17px;accent-color:var(--jvx-green)}.jvx-visit-status{padding:5px 8px;display:inline-flex;align-items:center;gap:5px;border-radius:999px;font-size:9px}.jvx-visit-status:before{width:6px;height:6px;content:"";border-radius:50%;background:currentColor}.jvx-visit-status.today,.jvx-visit-status.upcoming{background:#edf6e8;color:#46801c}.jvx-visit-status.overdue{background:#fff0ed;color:#bd3c33}.jvx-visit-status.completed{background:#eef2f4;color:#405e6c}.jvx-assignee-chip{display:inline-flex;align-items:center;gap:7px}.jvx-avatar{width:23px;height:23px;display:grid;place-items:center;border-radius:50%;background:#173f4e;color:#fff;font-size:8px}.jvx-row-actions{display:flex;gap:5px;justify-content:flex-end}
        .jvx-billing-head{padding:18px 20px}.jvx-billing-reminder{color:#314f5d;font-size:11px;line-height:1.5}.jvx-tabs{display:flex;gap:24px;margin-top:14px;border-bottom:1px solid var(--jvx-line)}.jvx-tab{padding:10px 0;border:0;border-bottom:3px solid transparent;background:none;color:#415b68;font:600 11px Arial;cursor:pointer}.jvx-tab.active{border-bottom-color:var(--jvx-green);color:var(--jvx-navy)}.jvx-tabpane{display:none}.jvx-tabpane.active{display:block}.jvx-payment-summary{padding:14px;margin:14px 20px;border-radius:8px;background:#f8f7f4}.jvx-payment-bar{height:9px;margin:11px 0 7px;border-radius:999px;background:#deddd7;overflow:hidden}.jvx-payment-bar span{display:block;height:100%;background:var(--jvx-green)}.jvx-payment-legend{display:flex;flex-wrap:wrap;gap:12px;color:#506975;font-size:9px}.jvx-reminder-empty{padding:25px!important;text-align:center;color:#607985!important;font-size:10px!important}
        .jvx-cost h2,.jvx-note-card h2{margin:0;color:var(--jvx-navy);font-size:18px}.jvx-cost-main{margin:10px 0 15px;color:#001f2f;font-size:31px;font-weight:700}.jvx-bars{display:grid;gap:8px}.jvx-bar-row{display:grid;grid-template-columns:48px 1fr 68px;gap:7px;align-items:center;color:#4d6874;font-size:9px}.jvx-bar{height:9px;border-radius:2px;background:#edf0f1;overflow:hidden}.jvx-bar span{height:100%;display:block;background:#1690a5}.jvx-bar.cost span{background:#321c64}.jvx-profit{padding-top:10px;margin-top:8px;border-top:1px solid var(--jvx-line);display:flex;justify-content:space-between;color:#314f5d;font-size:9px}.jvx-note-empty{min-height:220px;margin-top:18px;display:grid;place-items:center;border:1px dashed #ced9de;border-radius:8px;color:#34515f;text-align:center;cursor:pointer}.jvx-note-empty i{width:52px;height:52px;margin:0 auto 12px;display:grid;place-items:center;border-radius:50%;background:#f8f8f6;font-size:20px}.jvx-note-text{margin-top:18px;padding:12px;border:1px dashed #d4dde1;border-radius:8px;color:#314f5d;font-size:11px;line-height:1.6;white-space:pre-wrap;cursor:pointer}
        /* generic modals */
        .jvx-modal,.jvx-special-modal{position:fixed;inset:0;z-index:30000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.42)}.jvx-modal.open,.jvx-special-modal.open{display:flex}.jvx-dialog,.jvx-special-dialog{width:min(650px,calc(100vw - 36px));max-height:calc(100vh - 36px);overflow:auto;border-radius:10px;background:#fff;box-shadow:0 22px 65px rgba(0,17,49,.24)}.jvx-dialog.wide,.jvx-special-dialog.wide{width:min(930px,calc(100vw - 36px))}.jvx-modal-head{padding:20px 22px 11px;display:flex;align-items:center;justify-content:space-between;gap:12px}.jvx-modal-head h3{margin:0;color:var(--jvx-navy);font-size:21px}.jvx-close{width:34px;height:34px;border:0;border-radius:7px;background:none;color:#49646f;font-size:18px;cursor:pointer}.jvx-modal-body{padding:8px 22px 16px}.jvx-modal-foot{padding:12px 22px 20px;display:flex;justify-content:flex-end;gap:8px}.jvx-check{display:flex;align-items:center;gap:8px;color:#314f5d;font-size:11px}.jvx-check input{width:18px;height:18px;accent-color:var(--jvx-green)}.jvx-team-checks{max-height:180px;overflow:auto;padding:8px;border:1px solid var(--jvx-line);border-radius:8px}.jvx-team-checks label{padding:6px;display:flex;align-items:center;gap:7px;color:#34515f;font-size:10px}.jvx-date-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.jvx-selected-dates{display:flex;gap:7px;flex-wrap:wrap;margin:10px 0}.jvx-date-pill{padding:6px 9px;border:1px solid var(--jvx-line);border-radius:999px;background:#fff;color:#34515f;font-size:9px}.jvx-modal-note{margin:7px 0;color:#718993;font-size:9px;line-height:1.5}
        .jvx-arrival-options{display:flex;flex-wrap:wrap;gap:8px}.jvx-arrival-option{min-height:38px;padding:0 15px;border:1px solid var(--jvx-line);border-radius:999px;background:#fff;color:#34515f;font:600 11px Arial;cursor:pointer}.jvx-arrival-option.active{background:#e8e6e1;border-color:#e8e6e1;color:#173746}.jvx-arrival-option.active:after{margin-left:7px;content:"\2713";display:inline-grid;width:21px;height:21px;place-items:center;border-radius:50%;background:#fff;color:#34515f}.jvx-move-list{display:grid;gap:8px}.jvx-move-head,.jvx-move-row{display:grid;grid-template-columns:125px 32px 1fr;gap:12px;align-items:center}.jvx-move-head{color:#173746;font-size:9px;font-weight:700;text-transform:uppercase}.jvx-move-row{min-height:52px}.jvx-move-row .arrow{text-align:center;color:#49646f;font-size:18px}.jvx-move-current{color:#314f5d;font-size:11px}
        .jvx-reminder-grid{display:grid;grid-template-columns:1.15fr .75fr;gap:22px}.jvx-job-mini{display:grid;grid-template-columns:75px 1fr;gap:7px;color:#34515f;font-size:11px}.jvx-job-mini a{color:#2f8725!important;text-decoration:underline!important}.jvx-reminder-divider{height:1px;margin:17px 0;background:var(--jvx-line)}.jvx-reminder-bottom{display:grid;grid-template-columns:1.15fr .75fr;gap:22px}.jvx-schedule-four{display:grid;grid-template-columns:1fr 1fr;gap:9px}.jvx-team-select{width:100%}
        /* quotation-view style email composer */
        .jvx-email-dialog{width:min(930px,calc(100vw - 36px))}.jvx-email-grid{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:20px}.jvx-email-main{min-width:0}.jvx-email-to{min-height:53px;padding:8px 9px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;border:1px solid var(--jvx-line);border-radius:8px}.jvx-email-prefix{color:#6e838e;font-size:11px}.jvx-email-chips{display:flex;align-items:center;gap:5px;flex-wrap:wrap}.jvx-email-chip{min-height:32px;padding:0 10px;display:inline-flex;align-items:center;gap:8px;border:1px solid #d9e1e5;border-radius:999px;background:#fff;color:#34515f;font-size:11px}.jvx-email-chip button{padding:0;border:0;background:none;color:#697e88;cursor:pointer}.jvx-email-input{min-width:150px;flex:1;border:0;outline:0;color:#173746;font:11px Arial}.jvx-email-field{position:relative;margin-top:11px}.jvx-email-field label{position:absolute;top:6px;left:12px;color:#708691;font-size:9px}.jvx-email-field input,.jvx-email-field textarea{width:100%;padding:19px 12px 7px;border:1px solid var(--jvx-line);border-radius:8px;color:#173746;font:12px/1.45 Arial;outline:0}.jvx-email-field input{height:49px}.jvx-email-field textarea{min-height:245px;resize:vertical}.jvx-email-attachments h4{margin:2px 0 11px;color:var(--jvx-navy);font-size:12px}.jvx-email-drop{min-height:105px;padding:15px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:9px;border:1px dashed #cbd7dd;border-radius:8px;color:#607581;background:#fff;font-size:9px}.jvx-email-drop.drag{border-color:#8fbd72;background:#f8fcf4}.jvx-email-drop button{height:34px;padding:0 12px;border:1px solid var(--jvx-line);border-radius:7px;background:#fff;color:#2f8725;font-weight:700;font-size:10px;cursor:pointer}.jvx-email-limit{margin-top:10px;color:#6f838d;font-size:9px}.jvx-email-progress{height:7px;margin-top:5px;border-radius:999px;background:#dddcd7;overflow:hidden}.jvx-email-progress span{display:block;width:0;height:100%;background:var(--jvx-green)}.jvx-email-files{margin-top:9px;display:grid;gap:6px}.jvx-email-file{padding:7px 8px;display:flex;align-items:center;gap:7px;border:1px solid var(--jvx-line);border-radius:7px;color:#425d69;font-size:9px}.jvx-email-file span{min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.jvx-email-file button{border:0;background:none;color:#c94b55;cursor:pointer}
        /* quotation-view style signature pad */
        .jvx-signature-wrap{position:relative;border:1px solid var(--jvx-line);border-radius:8px;overflow:hidden;background:#fff}.jvx-signature-canvas{width:100%;height:225px;display:block;touch-action:none}.jvx-signature-clear{position:absolute;top:10px;right:10px;height:34px;padding:0 11px;border:1px solid var(--jvx-line);border-radius:7px;background:#fff;color:#31505d;font:700 10px Arial;cursor:pointer}.jvx-signature-line{height:1px;margin:0 25px;background:#cbd4d9}.jvx-signature-label{padding:8px 0 11px;color:#657985;font-size:10px;text-align:center}
        /* history drawer follows Quotation View */
        .jvx-drawer-overlay{position:fixed;inset:0;z-index:31990;visibility:hidden;opacity:0;background:rgba(0,17,49,.18);transition:.2s}.jvx-drawer-overlay.show{visibility:visible;opacity:1}.jvx-history-drawer{width:min(420px,92vw);height:100vh;position:fixed;top:0;right:0;z-index:32000;display:flex;flex-direction:column;background:#fff;box-shadow:-12px 0 36px rgba(0,17,49,.15);transform:translateX(102%);transition:transform .23s ease}.jvx-history-drawer.show{transform:translateX(0)}.jvx-drawer-head{padding:22px 21px 12px;display:flex;align-items:center;justify-content:space-between}.jvx-drawer-head h2{margin:0;color:var(--jvx-navy);font-size:24px}.jvx-drawer-body{min-height:0;flex:1;overflow:auto;padding:15px 18px 30px}.jvx-history-item{position:relative;padding:12px 0 12px 38px;border-bottom:1px solid #edf0f2}.jvx-history-avatar{width:26px;height:26px;position:absolute;top:12px;left:0;display:grid;place-items:center;border-radius:50%;background:#173d4c;color:#fff;font-size:9px}.jvx-history-item strong{display:block;color:#294957;font-size:13px}.jvx-history-item small{display:block;margin-top:3px;color:#83949d;font-size:11px}.jvx-history-item p{margin:7px 0 0;color:#526d79;font-size:11px;line-height:1.45;white-space:pre-wrap}.jvx-history-empty{padding:35px 10px;color:#8799a2;text-align:center;font-size:11px}
        .jv-toast{z-index:40000!important}.jvx-hidden{display:none!important}
        @media(max-width:1199.98px){.jvx-layout{grid-template-columns:1fr}.jvx-rail{min-height:0;position:static;grid-template-columns:1fr 1fr;border-left:0;border-top:1px solid var(--jvx-line)}.jvx-edit-line-top{grid-template-columns:minmax(230px,1fr) 95px 115px 125px 135px 34px}}
        @media(max-width:900px){.jvx-top{grid-template-columns:1fr}.jvx-email-grid,.jvx-reminder-grid,.jvx-reminder-bottom{grid-template-columns:1fr}.jvx-visits-summary{grid-template-columns:1fr 1fr}.jvx-edit-totals-wrap{grid-template-columns:1fr}.jvx-edit-totals{grid-column:1}.jvx-main{padding:16px}.jvx-rail{padding:16px}}
        @media(max-width:767.98px){.jvx-header{flex-direction:column}.jvx-actions{width:100%}.jvx-detail-row{grid-template-columns:110px 1fr}.jvx-grid2,.jvx-schedule-four,.jvx-date-grid{grid-template-columns:1fr}.jvx-edit-line-top{grid-template-columns:1fr 1fr}.jvx-line-field:first-child{grid-column:1/-1}.jvx-rail{grid-template-columns:1fr}.jvx-selection{align-items:flex-start;flex-wrap:wrap;gap:10px 18px}.jvx-move-head,.jvx-move-row{grid-template-columns:100px 25px 1fr}.jvx-title{font-size:25px}}
        @media(max-width:520px){.jvx-edit-line-top{grid-template-columns:1fr}.jvx-line-field:first-child{grid-column:auto}.jvx-visits-summary{grid-template-columns:1fr}.jvx-email-dialog{width:100%;max-height:100vh;border-radius:0}.jvx-special-modal{padding:0}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fieldplx-main-content"><div class="fieldplx-content-wrapper"><div class="fd-dashboard"><div class="jvx-page">
    <div id="loadingState" class="jv-loading"><div><div class="jv-spinner"></div><div>Loading job...</div></div></div>
    <div id="errorState" class="jv-error" style="display:none"><div><i class="bi bi-exclamation-circle"></i><h2>Unable to load job</h2><p id="errorMessage"></p><a class="jvx-btn" href="jobs">Back to Jobs</a></div></div>

    <div id="jobContent" style="display:none">
      <div class="jvx-layout">
        <div class="jvx-main">
          <header class="jvx-header">
            <div class="jvx-title-area">
              <div class="jvx-kicker"><i class="bi bi-hammer"></i><span class="jvx-status" id="statusPill">Scheduled</span></div>
              <h1 class="jvx-title" id="jobHeading">Job</h1>
              <div class="jvx-source" id="sourceLine"></div>
            </div>
            <div class="jvx-actions">
              <button type="button" class="jvx-btn icon" id="historyButton" title="History"><i class="bi bi-clock-history"></i></button>
              <div class="jvx-more-wrap">
                <button type="button" class="jvx-btn" id="moreButton"><i class="bi bi-three-dots"></i> More</button>
                <div class="jvx-more" id="moreMenu">
                  <button type="button" data-more="close"><i class="bi bi-hammer"></i> Close Job</button>
                  <button type="button" data-more="similar"><i class="bi bi-copy"></i> Create Similar Job</button>
                  <button type="button" data-more="followup"><i class="bi bi-envelope"></i> Send Job Follow-up Email</button>
                  <button type="button" data-more="booking"><i class="bi bi-envelope-check"></i> Resend Booking Confirmation Email</button>
                  <hr>
                  <a href="#" id="createInvoiceMore"><i class="bi bi-receipt"></i> Create Invoice</a>
                  <button type="button" data-more="signature"><i class="bi bi-pen"></i> Collect Signature</button>
                  <button type="button" data-more="costcsv"><i class="bi bi-file-earmark-spreadsheet"></i> Email Job Costs CSV</button>
                  <a href="#" id="printPdfMore" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Print or Save PDF</a>
                  <hr>
                  <button type="button" class="danger" data-more="delete"><i class="bi bi-trash"></i> Delete</button>
                </div>
              </div>
              <button type="button" class="jvx-btn icon" id="editDetailsButton" title="Edit job"><i class="bi bi-pencil"></i></button>
            </div>
          </header>

          <section class="jvx-overview" id="detailsCard">
            <div class="jvx-top">
              <div class="jvx-customer" id="customerCard">
                <div class="jvx-customer-head">
                  <div class="jvx-customer-name"><span id="customerName">-</span><span class="jvx-customer-dot"></span></div>
                  <div class="jvx-customer-menu-wrap" id="customerMenuWrap">
                    <button type="button" class="jvx-customer-menu-button" id="customerMenuButton" title="Customer options"><i class="bi bi-three-dots"></i></button>
                    <div class="jvx-customer-menu" id="customerMenu">
                      <button type="button" id="changePropertyButton">Change property</button>
                      <button type="button" class="danger" id="changeCustomerButton">Change customer</button>
                    </div>
                  </div>
                </div>
                <div class="jvx-address-label">Property Address</div>
                <div class="jvx-address" id="propertyAddress">-</div>
                <div class="jvx-contact"><a id="customerPhone" href="#">-</a><a id="customerEmail" href="#">-</a></div>
                <div class="jvx-customer-lock jvx-hidden" id="customerLockNote">Customer and property follow the source quotation / request.</div>
                <div class="jvx-inline-edit" id="customerEditor">
                  <div class="jvx-field"><label>Customer</label><select id="directClientId" class="jvx-control"></select></div>
                  <div class="jvx-field"><label>Property</label><select id="serviceLocationSelect" class="jvx-control"></select></div>
                  <input type="hidden" id="directLocationId" value="">
                  <div class="jvx-edit-actions"><button type="button" class="jvx-btn" id="cancelCustomerEdit">Cancel</button><button type="button" class="jvx-btn primary" id="saveCustomerEdit">Save</button></div>
                </div>
              </div>

              <div>
                <div class="jvx-detail-list">
                  <div class="jvx-detail-row"><span>Job #</span><strong id="jobNo">-</strong></div>
                  <div class="jvx-detail-row"><span>Job type</span><strong id="jobType">-</strong></div>
                  <div class="jvx-detail-row"><span>Starts on</span><strong id="startsOn">-</strong></div>
                  <div class="jvx-detail-row"><span>Ends on</span><strong id="endsOn">-</strong></div>
                  <div class="jvx-detail-row"><span>Billing frequency</span><strong id="billingFrequency">-</strong></div>
                  <div class="jvx-detail-row jvx-hidden" id="bookingSentRow"><span>Booking confirmation sent at</span><strong id="bookingSentAt">-</strong></div>
                  <div class="jvx-detail-row"><span>Priority</span><strong id="priorityView">-</strong></div>
                </div>
                <div class="jvx-inline-edit" id="detailsEditor">
                  <div class="jvx-field"><div class="jvx-floating"><label>Job title</label><input class="jvx-control" id="editTitle"></div></div>
                  <div class="jvx-grid2">
                    <div class="jvx-floating"><label>Job #</label><input class="jvx-control" id="editJobNo"></div>
                    <div class="jvx-floating"><label>Job type</label><select class="jvx-control" id="editJobType"><option value="one_off">One-off job</option><option value="recurring">Recurring job</option></select></div>
                    <div class="jvx-floating"><label>Starts on</label><input type="date" class="jvx-control" id="editStartDate"></div>
                    <div class="jvx-floating"><label>Ends on</label><input type="date" class="jvx-control" id="editEndDate"></div>
                    <div class="jvx-floating"><label>Priority</label><select class="jvx-control" id="editPriority"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
                  </div>
                  <div class="jvx-edit-actions"><button type="button" class="jvx-btn" id="cancelDetailsEdit">Cancel</button><button type="button" class="jvx-btn primary" id="saveDetailsEdit">Save</button></div>
                </div>
              </div>
            </div>
          </section>

          <section class="jvx-card" id="itemsCard">
            <div class="jvx-card-head"><h2>Product / Service</h2><button type="button" class="jvx-icon-btn" id="editItemsButton" title="Edit Product / Service"><i class="bi bi-pencil"></i></button></div>
            <div id="itemsView"><div class="jvx-table-wrap"><table class="jvx-table"><thead><tr><th>Line Item</th><th>Quantity</th><th>Unit Cost</th><th>Unit Price</th><th>Total</th></tr></thead><tbody id="itemRows"></tbody></table></div><div class="jvx-totals" id="itemTotals"></div></div>
            <div class="jvx-products-edit" id="productsEditor">
              <div class="jvx-lines" id="lineEditor"></div>
              <button type="button" class="jvx-add-line" id="addLineButton">Add Line Item</button>
              <div class="jvx-edit-totals-wrap"><div class="jvx-edit-totals">
                <div class="jvx-edit-total-row"><span>Subtotal</span><strong id="editSubtotal">-</strong></div>
                <div class="jvx-edit-total-row"><span>Discount</span><button type="button" class="jvx-total-action" id="discountToggle">Add Discount</button><strong id="editDiscountAmount" class="jvx-hidden">-</strong></div>
                <div class="jvx-adjust-row" id="discountRow"><select class="jvx-control" id="editDiscountType"><option value="fixed">Fixed amount</option><option value="percentage">Percentage</option></select><input type="number" min="0" step="0.01" class="jvx-control" id="editDiscountValue" value="0"><button type="button" class="jvx-small-green" id="discountDone">Done</button></div>
                <div class="jvx-edit-total-row"><span>Tax</span><button type="button" class="jvx-total-action" id="taxToggle">Add Tax</button><strong id="editTaxAmount" class="jvx-hidden">-</strong></div>
                <div class="jvx-adjust-row" id="taxRow"><select class="jvx-control" id="editTaxPreset"><option value="">Select tax</option></select><input type="number" min="0" max="100" step="0.01" class="jvx-control" id="editTaxPercent" value="0"><button type="button" class="jvx-small-green" id="taxDone">Done</button></div>
                <div class="jvx-edit-total-row grand"><span>Total price</span><strong id="editGrandTotal">-</strong></div>
                <div class="jvx-edit-total-row"><span>Total cost</span><strong id="editCostTotal">-</strong></div>
              </div></div>
              <div class="jvx-products-actions"><button type="button" class="jvx-btn" id="cancelItemsEdit">Cancel</button><button type="button" class="jvx-btn primary" id="saveItemsEdit">Save</button></div>
            </div>
          </section>

          <section class="jvx-card"><div class="jvx-card-head"><h2>Labor</h2></div><div class="jvx-simple-row"><div class="jvx-simple-copy"><i class="bi bi-clock"></i><span>Time tracked to this job will show here</span></div><button type="button" class="jvx-link" id="addTimeButton">Add Time Entry</button></div><div class="jvx-entries" id="timeEntries"></div></section>
          <section class="jvx-card"><div class="jvx-card-head"><h2>Expenses</h2></div><div class="jvx-simple-row"><div class="jvx-simple-copy"><i class="bi bi-receipt"></i><span>Track all expenses for this job in one place</span></div><button type="button" class="jvx-link" id="addExpenseButton">Add Expense</button></div><div class="jvx-entries" id="expenseEntries"></div></section>

          <section class="jvx-card" id="visitsCard">
            <div class="jvx-card-head"><h2>Scheduled visits</h2></div>
            <div class="jvx-visits-summary">
              <div><span>First visit</span><strong id="firstVisit">-</strong></div>
              <div><span>Last visit</span><strong id="lastVisit">-</strong></div>
              <div><span class="jvx-summary-label">Checklists <button type="button" class="jvx-link" id="checklistEditLink">Edit</button></span><strong id="checklistSummary">-</strong></div>
              <div><span class="jvx-summary-label">Arrival window <button type="button" class="jvx-link" id="arrivalEditLink">Edit</button></span><strong id="arrivalWindowText">Arrive at start time</strong></div>
            </div>
            <div class="jvx-visit-tools">
              <div class="jvx-segment"><button type="button" class="active" id="visitStatusButton">Status</button><button type="button" data-visit-filter="all" class="active">All</button></div>
              <div class="jvx-plus"><button type="button" class="jvx-btn icon" id="visitPlus"><i class="bi bi-plus-lg"></i></button><div class="jvx-plus-menu" id="visitPlusMenu"><button type="button" id="addSingleVisit">Add Single Visit</button><button type="button" id="addMultipleVisits">Add Multiple Visits</button></div></div>
            </div>
            <div class="jvx-selection" id="visitSelection"><span><input type="checkbox" class="jvx-checkbox" id="selectionCheck" checked> <span id="selectionCount">0 selected</span></span><button type="button" id="deselectVisits">Deselect All</button><button type="button" id="moveVisits">Move dates</button><button type="button" class="danger" id="deleteVisits">Delete visits</button></div>
            <div class="jvx-table-wrap"><table class="jvx-table"><thead><tr><th style="width:36px"><input type="checkbox" class="jvx-checkbox" id="visitCheckAll"></th><th>Date and time</th><th>Title and instructions</th><th>Status</th><th>Assigned</th><th></th></tr></thead><tbody id="visitRows"></tbody></table></div>
          </section>

          <section class="jvx-card" id="billingCard">
            <div class="jvx-card-head"><h2>Billing</h2><button type="button" class="jvx-btn" id="editBillingButton">Edit Invoice Settings</button></div>
            <div class="jvx-billing-head"><div class="jvx-billing-reminder"><span style="color:#728893">Reminders</span><br><span id="billingReminderText">When the job is marked closed</span></div><div class="jvx-tabs"><button type="button" class="jvx-tab active" data-btab="invoicing">Invoicing</button><button type="button" class="jvx-tab" data-btab="reminders">Reminders</button></div></div>
            <div class="jvx-tabpane active" id="billingInvoicing"><div id="paymentSchedule"></div></div>
            <div class="jvx-tabpane" id="billingReminders"><div class="jvx-table-wrap"><table class="jvx-table"><thead><tr><th>Scheduled</th><th>Description</th><th>Status</th><th>Assigned</th><th></th></tr></thead><tbody id="reminderRows"></tbody></table></div><div style="padding:12px 20px"><button type="button" class="jvx-link" id="addReminderButton">Add Reminder</button></div></div>
          </section>
        </div>

        <aside class="jvx-rail">
          <section class="jvx-card jvx-cost" style="margin-top:0"><div style="padding:20px"><h2>Total cost to date</h2><div class="jvx-cost-main" id="costMain">-</div><div class="jvx-bars"><div class="jvx-bar-row"><span>Revenue</span><div class="jvx-bar"><span id="revenueBar"></span></div><strong id="revenueValue">-</strong></div><div class="jvx-bar-row"><span>Cost</span><div class="jvx-bar cost"><span id="costBar"></span></div><strong id="costValue">-</strong></div></div><div class="jvx-profit"><span>Profit</span><span><strong id="profitPercent">0%</strong> &nbsp; <span id="profitValue">-</span></span></div></div></section>
          <section class="jvx-card jvx-note-card" style="margin-top:0"><div style="padding:20px"><h2>Notes</h2><div id="notesBox"></div></div></section>
        </aside>
      </div>
    </div>
</div></div></main></div>

<!-- Generic modal for visits, labor, expenses, reminders and settings -->
<div class="jvx-modal" id="modal"><div class="jvx-dialog" id="modalDialog"><div class="jvx-modal-head"><h3 id="modalTitle">Modal</h3><button type="button" class="jvx-close" id="modalClose"><i class="bi bi-x-lg"></i></button></div><div class="jvx-modal-body" id="modalBody"></div><div class="jvx-modal-foot" id="modalFoot"></div></div></div>

<!-- Booking confirmation email modal - based on Quotation View composer -->
<div class="jvx-special-modal" id="bookingModal"><div class="jvx-special-dialog jvx-email-dialog"><div class="jvx-modal-head"><h3 id="bookingModalTitle">Email booking confirmation</h3><button type="button" class="jvx-close" data-close-booking><i class="bi bi-x-lg"></i></button></div><form id="bookingForm"><div class="jvx-modal-body"><div class="jvx-email-grid"><div class="jvx-email-main"><div class="jvx-email-to"><span class="jvx-email-prefix">To</span><div class="jvx-email-chips" id="bookingRecipientChips"></div><input type="email" class="jvx-email-input" id="bookingRecipientInput" placeholder="Add email"></div><div class="jvx-email-field"><label>Subject</label><input type="text" id="bookingSubject" maxlength="255" required></div><div class="jvx-email-field"><label>Message</label><textarea id="bookingMessage" maxlength="8000" required></textarea></div></div><aside class="jvx-email-attachments"><h4>Attachments</h4><div class="jvx-email-drop" id="bookingDrop"><button type="button" id="bookingFilePick">Select</button><span>Select or drag files here to upload</span><input type="file" id="bookingFilesInput" multiple hidden></div><div class="jvx-email-limit">You've attached <span id="bookingMb">0.00</span> MB of the 10.00 MB limit.<div class="jvx-email-progress"><span id="bookingProgress"></span></div></div><div class="jvx-email-files" id="bookingFileList"></div></aside></div></div><div class="jvx-modal-foot" style="justify-content:space-between"><label class="jvx-check"><input type="checkbox" id="bookingSendCopy"> Send me a copy</label><div style="display:flex;gap:8px"><button type="button" class="jvx-btn" data-close-booking>Cancel</button><button type="submit" class="jvx-btn primary" id="bookingSendButton">Send Email</button></div></div></form></div></div>

<!-- Signature modal - based on Quotation View -->
<div class="jvx-special-modal" id="signatureModal"><div class="jvx-special-dialog"><div class="jvx-modal-head"><h3>Signature Pad</h3><button type="button" class="jvx-close" data-close-signature><i class="bi bi-x-lg"></i></button></div><div class="jvx-modal-body"><div class="jvx-signature-wrap"><canvas id="signatureCanvas" class="jvx-signature-canvas" width="900" height="300"></canvas><button type="button" class="jvx-signature-clear" id="clearSignature">Clear</button><div class="jvx-signature-line"></div><div class="jvx-signature-label">Write signature</div></div><label class="jvx-check" style="margin-top:10px"><input type="checkbox" id="signatureSendCopy"> Send your customer a copy</label></div><div class="jvx-modal-foot"><button type="button" class="jvx-btn" data-close-signature>Cancel</button><button type="button" class="jvx-btn primary" id="saveSignatureButton">Submit</button></div></div></div>

<!-- History drawer - same interaction pattern as Quotation View -->
<div class="jvx-drawer-overlay" id="historyOverlay"></div><aside class="jvx-history-drawer" id="historyDrawer"><div class="jvx-drawer-head"><h2>Job History</h2><button type="button" class="jvx-close" id="historyClose"><i class="bi bi-x-lg"></i></button></div><div class="jvx-drawer-body" id="historyList"></div></aside>

<!-- Quick customer -->
<div class="jvx-special-modal" id="customerModal"><div class="jvx-special-dialog"><div class="jvx-modal-head"><h3>New Customer</h3><button type="button" class="jvx-close" data-close-customer><i class="bi bi-x-lg"></i></button></div><div class="jvx-modal-body"><div class="jvx-grid2"><div class="jvx-field"><label>Customer name *</label><input class="jvx-control" id="newCustomerName" maxlength="190"></div><div class="jvx-field"><label>Company</label><input class="jvx-control" id="newCustomerCompany" maxlength="190"></div><div class="jvx-field"><label>Phone</label><input class="jvx-control" id="newCustomerPhone" maxlength="50"></div><div class="jvx-field"><label>Email</label><input type="email" class="jvx-control" id="newCustomerEmail" maxlength="190"></div></div></div><div class="jvx-modal-foot"><button type="button" class="jvx-btn" data-close-customer>Cancel</button><button type="button" class="jvx-btn primary" id="createCustomerButton">Create Customer</button></div></div></div>

<!-- Quick property -->
<div class="jvx-special-modal" id="propertyModal"><div class="jvx-special-dialog"><div class="jvx-modal-head"><h3>Add Property</h3><button type="button" class="jvx-close" data-close-property><i class="bi bi-x-lg"></i></button></div><div class="jvx-modal-body"><div class="jvx-grid2"><div class="jvx-field" style="grid-column:1/-1"><label>Property name *</label><input class="jvx-control" id="newPropertyName" maxlength="190"></div><div class="jvx-field" style="grid-column:1/-1"><label>Street 1 *</label><input class="jvx-control" id="newPropertyStreet1" maxlength="255"></div><div class="jvx-field" style="grid-column:1/-1"><label>Street 2</label><input class="jvx-control" id="newPropertyStreet2" maxlength="255"></div><div class="jvx-field"><label>City</label><input class="jvx-control" id="newPropertyCity" maxlength="120"></div><div class="jvx-field"><label>Province / State</label><input class="jvx-control" id="newPropertyState" maxlength="120"></div><div class="jvx-field"><label>Postal code</label><input class="jvx-control" id="newPropertyPostal" maxlength="40"></div><div class="jvx-field"><label>Country</label><select class="jvx-control" id="newPropertyCountry"></select></div><div class="jvx-field" style="grid-column:1/-1"><label class="jvx-check"><input type="checkbox" id="newPropertyPrimary"> Use as primary / billing location</label></div></div></div><div class="jvx-modal-foot"><button type="button" class="jvx-btn" data-close-property>Cancel</button><button type="button" class="jvx-btn primary" id="createPropertyButton">Add Property</button></div></div></div>

<!-- Quick Product / Service -->
<div class="jvx-special-modal" id="catalogModal"><div class="jvx-special-dialog"><div class="jvx-modal-head"><h3>Add Product / Service</h3><button type="button" class="jvx-close" data-close-catalog><i class="bi bi-x-lg"></i></button></div><div class="jvx-modal-body"><div class="jvx-grid2"><div class="jvx-field"><label>Item type</label><select class="jvx-control" id="newItemType"><option value="service">Service</option><option value="product">Product</option></select></div><div class="jvx-field"><label>Name *</label><input class="jvx-control" id="newItemName" maxlength="190"></div><div class="jvx-field" style="grid-column:1/-1"><label>Description</label><textarea class="jvx-control" id="newItemDescription" rows="4"></textarea></div><div class="jvx-field"><label>Unit cost</label><input type="number" min="0" step="0.01" class="jvx-control" id="newItemUnitCost" value="0.00"></div><div class="jvx-field"><label>Markup (%)</label><input type="number" min="0" step="0.01" class="jvx-control" id="newItemMarkup" value="0"></div><div class="jvx-field"><label>Unit price</label><input type="number" min="0" step="0.01" class="jvx-control" id="newItemUnitPrice" value="0.00"></div><div class="jvx-field"><label>Tax %</label><input type="number" min="0" max="100" step="0.01" class="jvx-control" id="newItemTax" value="0"></div><div class="jvx-field" style="grid-column:1/-1"><label>Image (optional)</label><input type="file" accept="image/jpeg,image/png,image/webp" class="jvx-control" id="newItemImage"></div></div></div><div class="jvx-modal-foot"><button type="button" class="jvx-btn" data-close-catalog>Cancel</button><button type="button" class="jvx-btn primary" id="createCatalogButton">Add Product / Service</button></div></div></div>

<!-- Checklist viewer retained from existing Job View -->
<div class="jv-checklist-modal" id="checklistModal" aria-hidden="true"><div class="jv-checklist-modal-dialog" role="dialog" aria-modal="true"><div class="jv-checklist-modal-head"><div><h2 class="jv-checklist-modal-title" id="checklistModalTitle">Checklists</h2><div class="jv-checklist-modal-sub" id="checklistModalSubtitle"></div></div><button type="button" class="jv-checklist-close" id="checklistModalClose"><i class="bi bi-x-lg"></i></button></div><div class="jv-checklist-modal-body" id="checklistModalBody"></div></div></div>
<div class="jv-toast" id="toast"><span id="toastMessage"></span></div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
'use strict';
var jobId=<?= (int)$jobId ?>;
var csrfToken=<?= json_encode($jobsCsrfToken) ?>;
var basePath=<?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>;
var apiUrl=basePath+'/api/job-view.php';
var data={},job={},meta={},currency={},lineItems=[],editLines=[],visits=[],checklists=[],selectedVisits={},visitFilter='all';
var toastTimer=null,discountType='',discountValue=0,taxPercent=0,pendingCatalogLine=-1,bookingRecipients=[],bookingFiles=[];

function E(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function cap(v){return String(v||'-').replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase()})}
function money(v){var n=Number(v||0),p=parseInt(currency.decimal_places,10);if(isNaN(p))p=2;var s=currency.symbol||'',t=n.toFixed(p);return currency.symbol_position==='after'?t+(s?' '+s:''):(s||'')+t}
function date(v){if(!v)return '-';var raw=String(v).slice(0,10),d=new Date(raw+'T00:00:00');if(isNaN(d.getTime()))return raw;return d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}
function time(v){if(!v)return '';var x=String(v).slice(0,5).split(':'),h=parseInt(x[0],10),m=x[1]||'00',a=h>=12?'PM':'AM';h=h%12||12;return h+':'+m+' '+a}
function dt(v){if(!v)return '-';var x=String(v).replace('T',' ').split(' ');return date(x[0])+(x[1]?' '+time(x[1]):'')}
function today(){var d=new Date(),off=d.getTimezoneOffset();d=new Date(d.getTime()-off*60000);return d.toISOString().slice(0,10)}
function clone(v){return JSON.parse(JSON.stringify(v||[]))}
function toast(type,msg){var t=E('toast');if(!t)return;E('toastMessage').textContent=msg||'Notification';t.className='jv-toast '+(type||'')+' show';clearTimeout(toastTimer);toastTimer=setTimeout(function(){t.classList.remove('show')},4200)}
function parseResponse(r){return r.text().then(function(raw){var j,s=String(raw||'').trim();try{j=s?JSON.parse(s):{}}catch(e){throw new Error(s.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!j.success)throw new Error(j.message||('Request failed with HTTP '+r.status+'.'));return j})}
function post(action,extra){var fd=new FormData();fd.append('action',action);fd.append('job_id',jobId);fd.append('csrf_token',csrfToken);Object.keys(extra||{}).forEach(function(k){var v=extra[k];if(Array.isArray(v))v.forEach(function(x){fd.append(k+'[]',x)});else fd.append(k,v==null?'':v)});return fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
function postForm(fd){fd.append('job_id',jobId);fd.append('csrf_token',csrfToken);return fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
function openModal(title,html,buttons,wide){E('modalTitle').textContent=title;E('modalBody').innerHTML=html;E('modalFoot').innerHTML=buttons||'';E('modalDialog').classList.toggle('wide',!!wide);E('modal').classList.add('open')}
function closeModal(){E('modal').classList.remove('open');E('modalBody').innerHTML='';E('modalFoot').innerHTML=''}
E('modalClose').onclick=closeModal;E('modal').onclick=function(e){if(e.target===this)closeModal()};

function locationAddress(loc){loc=loc||data.location||{};return [loc.address_line1,loc.address_line2,loc.city,loc.state,loc.postal_code].filter(function(x){return String(x||'').trim()!==''}).join(', ')||'-'}
function billingLabel(){var b=data.billing||{},f=String(b.invoice_frequency||job.invoicing_preference||'');return f==='monthly_last_day'?'Monthly on last day':f==='after_each_visit'?'After each visit':f==='job_completion'||f==='when_job_complete'?'Upon job completion':f==='as_needed'?'As needed':f==='custom'?'Custom':cap(f)}
function statusForVisit(v){if(String(v.status||'').toLowerCase()==='completed')return 'completed';var raw=v.scheduled_start?String(v.scheduled_start).slice(0,10):'',n=today();if(raw===n)return 'today';if(raw&&raw<n)return 'overdue';return 'upcoming'}
function arrivalLabel(v){var m=Number(v||0);if(!m)return 'Arrive at start time';if(m===60)return '1 hour arrival window';if(m%60===0)return (m/60)+' hour arrival window';return m+' min arrival window'}
function clientById(id){id=Number(id||0);return (meta.clients||[]).find(function(x){return Number(x.id)===id})||null}
function locationById(id){id=Number(id||0);return (meta.locations||[]).find(function(x){return Number(x.id)===id})||null}
function locationsForClient(id){id=Number(id||0);return (meta.locations||[]).filter(function(x){return Number(x.client_id)===id})}
function primaryLocation(id){return locationsForClient(id).find(function(x){return Number(x.is_primary||0)===1})||locationsForClient(id)[0]||null}
function userById(id){id=Number(id||0);return ((meta.team_members&&meta.team_members.length?meta.team_members:meta.users)||[]).find(function(x){return Number(x.id)===id})||null}
function catalogByKey(key){key=String(key||'');return (meta.catalog_items||[]).find(function(x){return String(x.catalog_key||'')===key})||null}
function lineKey(l){if(Number(l.product_id||0)>0)return 'product:'+Number(l.product_id);if(Number(l.product_service_id||0)>0)return 'ps:'+Number(l.product_service_id);return ''}

function load(){return post('get').then(function(r){data=r;render()}).catch(function(e){console.error('FieldPlx Job View:',e);E('loadingState').style.display='none';E('jobContent').style.display='none';E('errorState').style.display='flex';E('errorMessage').textContent=e.message||'Unable to load job.';toast('error',e.message)})}
function render(){job=data.job||{};meta=data.meta||{};currency=data.currency||meta.currency||{};lineItems=data.line_items||[];visits=data.visits||[];checklists=data.checklists||[];
  E('jobHeading').textContent=job.title||job.job_no||'Job';var st=String(job.status||'scheduled').toLowerCase(),displaySt=st;if(st!=='completed'&&st!=='closed'&&st!=='archived'){if(visits.some(function(v){return statusForVisit(v)==='today'}))displaySt='today';else if(visits.some(function(v){return statusForVisit(v)==='overdue'}))displaySt='overdue'}E('statusPill').textContent=cap(displaySt);E('statusPill').className='jvx-status '+displaySt;E('sourceLine').textContent=job.quote_no?'Converted from Quote '+job.quote_no:(job.request_no?'Converted from Request '+job.request_no:'');
  E('customerName').textContent=job.client_name||'-';E('propertyAddress').textContent=locationAddress();var p=E('customerPhone'),em=E('customerEmail');p.textContent=job.client_phone||'-';p.href=job.client_phone?'tel:'+String(job.client_phone).replace(/[^+0-9]/g,''):'#';em.textContent=job.client_email||'-';em.href=job.client_email?'mailto:'+job.client_email:'#';
  var sourceLocked=!!(Number(job.quote_id||0)||Number(job.request_id||0));E('customerMenuWrap').classList.toggle('jvx-hidden',sourceLocked);E('customerLockNote').classList.toggle('jvx-hidden',!sourceLocked);
  E('jobNo').textContent=job.job_no||'-';E('jobType').textContent=String(job.job_type)==='recurring'?'Recurring job':'One-off job';E('startsOn').textContent=date(job.start_date);E('endsOn').textContent=date(job.end_date);E('billingFrequency').textContent=billingLabel();E('priorityView').textContent=cap(job.priority);
  var sent=job.booking_confirmation_sent_at||'';E('bookingSentRow').classList.toggle('jvx-hidden',!sent);E('bookingSentAt').textContent=sent?dt(sent):'-';
  E('editTitle').value=job.title||'';E('editJobNo').value=job.job_no||'';E('editJobType').value=job.job_type||'one_off';E('editStartDate').value=job.start_date||'';E('editEndDate').value=job.end_date||'';E('editPriority').value=job.priority||'normal';
  E('createInvoiceMore').href='add-invoice.php?job_id='+jobId;E('printPdfMore').href='job-print.php?job_id='+jobId;
  E('arrivalWindowText').textContent=arrivalLabel(job.arrival_window_minutes);
  renderItems();renderTime();renderExpenses();renderVisits();renderBilling();renderCost();renderNotes();renderHistory();
  E('loadingState').style.display='none';E('errorState').style.display='none';E('jobContent').style.display='block';
}

/* ---------- Job details ---------- */
E('editDetailsButton').onclick=function(){E('detailsEditor').classList.toggle('open')};E('cancelDetailsEdit').onclick=function(){E('detailsEditor').classList.remove('open')};
E('saveDetailsEdit').onclick=function(){post('update_view_details',{job_no:E('editJobNo').value,title:E('editTitle').value,job_type:E('editJobType').value,start_date:E('editStartDate').value,end_date:E('editEndDate').value,priority:E('editPriority').value}).then(function(r){E('detailsEditor').classList.remove('open');toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})};

/* ---------- Customer/property edit follows Add Job ---------- */
function customerOptionHtml(selected){var h='<option value="">Select a customer</option>';(meta.clients||[]).forEach(function(c){h+='<option value="'+Number(c.id)+'"'+(Number(selected)===Number(c.id)?' selected':'')+'>'+esc(c.name||c.display_name||'Customer')+'</option>'});h+='<option value="__new_customer__">+ Create new customer</option>';return h}
function propertyOptionHtml(clientId,selected){var h='<option value="">Select a property</option>';locationsForClient(clientId).forEach(function(l){var name=locationAddress(l);h+='<option value="'+Number(l.id)+'"'+(Number(selected)===Number(l.id)?' selected':'')+'>'+esc(name==='-'?(l.name||('Property '+l.id)):name)+'</option>'});h+='<option value="__new_property__">+ Create new property</option>';return h}
function initCustomerEditor(changeCustomer){var cid=Number(job.client_id||0),c=E('directClientId');c.innerHTML=customerOptionHtml(cid);c.value=String(cid||'');refreshPropertySelect(cid,Number(job.location_id||0));c.onchange=function(){var raw=String(c.value||'');if(raw==='__new_customer__'){c.value=String(cid||'');openCustomerModal('');return}var newCid=Number(raw||0);refreshPropertySelect(newCid,0)};E('customerEditor').classList.add('open');setTimeout(function(){try{(changeCustomer?c:E('serviceLocationSelect')).focus()}catch(ex){}},50)}
function refreshPropertySelect(cid,selected){var p=E('serviceLocationSelect');p.innerHTML=propertyOptionHtml(cid,selected);p.value=selected?String(selected):'';E('directLocationId').value=selected?String(selected):'';p.onchange=function(){var raw=String(p.value||'');if(raw==='__new_property__'){p.value=E('directLocationId').value||'';openPropertyModal('');return}E('directLocationId').value=raw}}
E('customerMenuButton').onclick=function(e){e.stopPropagation();E('customerMenu').classList.toggle('open')};E('changePropertyButton').onclick=function(){E('customerMenu').classList.remove('open');initCustomerEditor(false)};E('changeCustomerButton').onclick=function(){E('customerMenu').classList.remove('open');initCustomerEditor(true)};E('cancelCustomerEdit').onclick=function(){E('customerEditor').classList.remove('open')};E('saveCustomerEdit').onclick=function(){var cid=Number(E('directClientId').value||0);if(!cid){toast('warning','Select a customer.');return}var lid=Number(E('serviceLocationSelect').value||0);post('update_view_customer',{client_id:cid,location_id:lid}).then(function(r){E('customerEditor').classList.remove('open');toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})};
function openCustomerModal(name){E('newCustomerName').value=name||'';E('newCustomerCompany').value='';E('newCustomerPhone').value='';E('newCustomerEmail').value='';E('customerModal').classList.add('open');setTimeout(function(){E('newCustomerName').focus()},50)}
function closeCustomerModal(){E('customerModal').classList.remove('open')}
document.querySelectorAll('[data-close-customer]').forEach(function(b){b.onclick=closeCustomerModal});
E('createCustomerButton').onclick=function(){var n=E('newCustomerName').value.trim();if(!n){toast('warning','Enter a customer name.');return}var fd=new FormData();fd.append('action','create_customer');fd.append('display_name',n);fd.append('company_name',E('newCustomerCompany').value);fd.append('phone',E('newCustomerPhone').value);fd.append('email',E('newCustomerEmail').value);var btn=this;btn.disabled=true;postForm(fd).then(function(r){var c=r.client||{};meta.clients.push(c);closeCustomerModal();initCustomerEditor(true);E('directClientId').value=String(c.id);refreshPropertySelect(c.id,0);toast('success',r.message)}).catch(function(e){toast('error',e.message)}).then(function(){btn.disabled=false})};
function openPropertyModal(name){E('newPropertyName').value=name||'';E('newPropertyStreet1').value='';E('newPropertyStreet2').value='';E('newPropertyCity').value='';E('newPropertyState').value='';E('newPropertyPostal').value='';E('newPropertyCountry').innerHTML=countryOptions();E('newPropertyPrimary').checked=locationsForClient(Number(E('directClientId').value||0)).length===0;E('propertyModal').classList.add('open')}
function closePropertyModal(){E('propertyModal').classList.remove('open')}
document.querySelectorAll('[data-close-property]').forEach(function(b){b.onclick=closePropertyModal});
E('createPropertyButton').onclick=function(){var cid=Number(E('directClientId').value||0),name=E('newPropertyName').value.trim(),street=E('newPropertyStreet1').value.trim();if(!cid){toast('warning','Select a customer first.');return}if(!name||!street){toast('warning','Enter property name and Street 1.');return}var fd=new FormData();fd.append('action','create_location');fd.append('client_id',cid);fd.append('name',name);fd.append('location_type','site');fd.append('address_line1',street);fd.append('address_line2',E('newPropertyStreet2').value);fd.append('city',E('newPropertyCity').value);fd.append('state',E('newPropertyState').value);fd.append('postal_code',E('newPropertyPostal').value);fd.append('country_id',E('newPropertyCountry').value);fd.append('is_primary',E('newPropertyPrimary').checked?'1':'0');fd.append('custom_values_json','{}');fd.append('contacts_json','[]');var btn=this;btn.disabled=true;postForm(fd).then(function(r){var l=r.location||{};meta.locations.push(l);closePropertyModal();refreshPropertySelect(cid,l.id);E('serviceLocationSelect').value=String(l.id);E('directLocationId').value=String(l.id);toast('success',r.message)}).catch(function(e){toast('error',e.message)}).then(function(){btn.disabled=false})};
function countryOptions(){var h='<option value="">Select country</option>';(meta.countries||[]).forEach(function(c){h+='<option value="'+Number(c.id)+'">'+esc(c.name||c.iso2||'Country')+'</option>'});return h}
/* ---------- Product / Service view + Add Job style editor ---------- */
function renderItems(){var b=E('itemRows');if(!lineItems.length)b.innerHTML='<tr><td colspan="5" class="jvx-reminder-empty">No Product / Service items.</td></tr>';else b.innerHTML=lineItems.map(function(x){return '<tr><td><div class="jvx-item"><strong>'+esc(x.item_name||'Item')+'</strong><small>'+esc(x.description||'')+'</small></div></td><td>'+Number(x.quantity||0)+'</td><td>'+money(x.unit_cost)+'</td><td>'+money(x.unit_price)+'</td><td><strong>'+money(x.line_total)+'</strong></td></tr>'}).join('');E('itemTotals').innerHTML='<div class="jvx-total-row"><span>Subtotal</span><strong>'+money(job.subtotal)+'</strong></div>'+(Number(job.discount_total||0)>0?'<div class="jvx-total-row"><span>Discount</span><strong>-'+money(job.discount_total)+'</strong></div>':'')+(Number(job.tax_total||0)>0?'<div class="jvx-total-row"><span>Tax</span><strong>'+money(job.tax_total)+'</strong></div>':'')+'<div class="jvx-total-row grand"><span>Total price</span><strong>'+money(job.total)+'</strong></div><div class="jvx-total-row total-cost"><span>Total cost</span><strong>'+money((data.cost_summary||{}).item_cost)+'</strong></div>'}
function normalizeLine(x){x=x||{};return{product_service_id:Number(x.product_service_id||0)||null,product_id:Number(x.product_id||0)||null,item_source:x.item_source||'manual',item_type:x.item_type||'',item_name:x.item_name||'',description:x.description||'',quantity:Number(x.quantity==null?1:x.quantity),unit_cost:Number(x.unit_cost||0),unit_price:Number(x.unit_price||0),tax_percent:Number(x.tax_percent||0)}}
function catalogOptions(l){var selected=lineKey(l),h='<option value="">Select product / service</option>'; (meta.catalog_items||[]).forEach(function(x){var key=x.catalog_key||((x.source_type==='product'?'product:':'ps:')+x.id);h+='<option value="'+esc(key)+'" data-kind="'+esc(x.source_label||x.item_type||x.source_type||'')+'" data-price="'+Number(x.unit_price||x.selling_price||0)+'" data-description="'+esc(x.description||'')+'"'+(key===selected?' selected':'')+'>'+esc(x.name||'Item')+'</option>'});if(!selected&&String(l.item_name||'').trim()!=='')h+='<option value="manual" selected>'+esc(l.item_name)+'</option>';h+='<option value="__new_catalog__">+ Create new product / service</option>';return h}
function catalogResult(item){if(!item.id)return item.text;var id=String(item.id||'');if(id.indexOf('new:')===0){var n=document.createElement('div');n.textContent='Create new product / service';return n}var el=item.element,kind=el?String(el.getAttribute('data-kind')||''):'',price=el?el.getAttribute('data-price'):'',desc=el?el.getAttribute('data-description'):'';var row=document.createElement('div');row.className='jvx-catalog-result';var copy=document.createElement('div');copy.className='jvx-catalog-copy';var nm=document.createElement('div');nm.className='jvx-catalog-name';var tx=document.createElement('span');tx.textContent=item.text||'';nm.appendChild(tx);if(kind){var badge=document.createElement('span');badge.className='jvx-type-badge '+(kind.toLowerCase().indexOf('service')>=0?'service':'');badge.textContent=kind;nm.appendChild(badge)}copy.appendChild(nm);if(desc){var ds=document.createElement('div');ds.className='jvx-catalog-desc';ds.textContent=desc;copy.appendChild(ds)}row.appendChild(copy);if(price!==''){var pr=document.createElement('div');pr.className='jvx-catalog-price';pr.textContent=money(price);row.appendChild(pr)}return row}
function applyCatalog(i,key){if(!editLines[i])return;key=String(key||'');if(key.indexOf('new:')===0){pendingCatalogLine=i;openCatalogModal(key.slice(4));return}if(key==='manual'||key==='')return;var c=catalogByKey(key);if(!c)return;editLines[i]=normalizeLine({product_service_id:c.product_service_id,product_id:c.product_id,item_source:c.source_type==='product'?'product':'product_service',item_type:c.item_type||c.source_label||'',item_name:c.name,description:c.description,quantity:editLines[i].quantity||1,unit_cost:c.unit_cost!=null?c.unit_cost:c.base_unit_price,unit_price:c.unit_price!=null?c.unit_price:c.selling_price,tax_percent:c.tax_percent});renderEditLines()}
function renderEditLines(){
  E('lineEditor').innerHTML=editLines.map(function(l,i){return '<article class="jvx-edit-line" data-line="'+i+'"><div class="jvx-edit-line-top"><div class="jvx-line-field"><label>Name</label><select class="jvx-line-select" data-line-catalog="'+i+'">'+catalogOptions(l)+'</select></div><div class="jvx-qty-field"><label>Quantity</label><input data-line-qty="'+i+'" type="number" min="0.001" step="0.001" value="'+Number(l.quantity||1)+'"></div><div class="jvx-money-field"><label>Unit cost</label><input data-line-cost="'+i+'" type="number" min="0" step="0.01" value="'+Number(l.unit_cost||0).toFixed(2)+'"></div><div class="jvx-money-field"><label>Unit price</label><input data-line-price="'+i+'" type="number" min="0" step="0.01" value="'+Number(l.unit_price||0).toFixed(2)+'"></div><div class="jvx-line-total"><small>Total</small><strong data-line-total="'+i+'">'+money(Number(l.quantity||0)*Number(l.unit_price||0))+'</strong></div><button type="button" class="jvx-remove" data-remove-line="'+i+'" title="Remove"><i class="bi bi-trash"></i></button></div><textarea data-line-desc="'+i+'" placeholder="Description">'+esc(l.description||'')+'</textarea></article>'}).join('');

  editLines.forEach(function(l,i){
    var sel=document.querySelector('[data-line-catalog="'+i+'"]');
    if(!sel)return;

    var key=lineKey(l);
    if(key)sel.value=key;

    function handleCatalogValue(v){
      v=String(v||'');
      if(v==='__new_catalog__'){
        if(key)sel.value=key; else sel.value='';
        if(window.jQuery&&window.jQuery.fn&&window.jQuery.fn.select2){window.jQuery(sel).val(key||'').trigger('change.select2')}
        pendingCatalogLine=i;
        openCatalogModal('');
        return;
      }
      applyCatalog(i,v);
    }

    /* Native fallback remains functional even if the CDN is unavailable. */
    sel.onchange=function(){
      if(window.jQuery&&window.jQuery.fn&&window.jQuery.fn.select2&&window.jQuery(sel).data('select2'))return;
      handleCatalogValue(this.value);
    };

    /* Initialize Select2 only after the dynamically-created select exists. */
    if(window.jQuery&&window.jQuery.fn&&typeof window.jQuery.fn.select2==='function'){
      var $sel=window.jQuery(sel);
      $sel.select2({
        width:'100%',
        placeholder:'Select product / service',
        allowClear:true,
        templateResult:catalogResult,
        templateSelection:function(item){return item&&item.text?item.text:'Select product / service'},
        dropdownParent:window.jQuery('#itemsCard')
      });
      if(key)$sel.val(key).trigger('change.select2');
      $sel.off('select2:select.jvx select2:clear.jvx').on('select2:select.jvx',function(e){
        handleCatalogValue(e.params&&e.params.data?e.params.data.id:this.value);
      }).on('select2:clear.jvx',function(){
        handleCatalogValue('');
      });
    }
  });
  renderEditTotals();
}
function syncEditLines(){editLines.forEach(function(l,i){var q=document.querySelector('[data-line-qty="'+i+'"]'),c=document.querySelector('[data-line-cost="'+i+'"]'),p=document.querySelector('[data-line-price="'+i+'"]'),d=document.querySelector('[data-line-desc="'+i+'"]');if(q)l.quantity=Math.max(.001,Number(q.value||1));if(c)l.unit_cost=Math.max(0,Number(c.value||0));if(p)l.unit_price=Math.max(0,Number(p.value||0));if(d)l.description=d.value;var tot=document.querySelector('[data-line-total="'+i+'"]');if(tot)tot.textContent=money(l.quantity*l.unit_price)})}
function editTotals(){syncEditLines();var subtotal=editLines.reduce(function(a,l){return a+(Number(l.quantity||0)*Number(l.unit_price||0))},0),cost=editLines.reduce(function(a,l){return a+(Number(l.quantity||0)*Number(l.unit_cost||0))},0),disc=0;if(discountType==='percentage')disc=Math.min(subtotal,subtotal*Math.min(100,Math.max(0,discountValue))/100);else if(discountType==='fixed')disc=Math.min(subtotal,Math.max(0,discountValue));var tax=0,remaining=disc;editLines.forEach(function(l,i){var base=Number(l.quantity||0)*Number(l.unit_price||0),share=subtotal>0?disc*(base/subtotal):0;if(i===editLines.length-1)share=remaining;remaining-=share;tax+=Math.max(0,base-share)*Number(l.tax_percent||0)/100});return{subtotal:subtotal,cost:cost,discount:disc,tax:tax,total:Math.max(0,subtotal-disc)+tax}}
function renderEditTotals(){var t=editTotals();E('editSubtotal').textContent=money(t.subtotal);E('editDiscountAmount').textContent='-'+money(t.discount);E('editDiscountAmount').classList.toggle('jvx-hidden',t.discount<=0);E('discountToggle').textContent=t.discount>0?'Change':'Add Discount';E('editTaxAmount').textContent=money(t.tax);E('editTaxAmount').classList.toggle('jvx-hidden',t.tax<=0);E('taxToggle').textContent=t.tax>0?'Change':'Add Tax';E('editGrandTotal').textContent=money(t.total);E('editCostTotal').textContent=money(t.cost)}
function beginItemsEdit(){editLines=clone(lineItems).map(normalizeLine);if(!editLines.length)editLines.push(normalizeLine({quantity:1}));discountType=job.discount_type||'';discountValue=Number(job.discount_value||0);taxPercent=editLines.length?Number(editLines[0].tax_percent||0):0;E('editDiscountType').value=discountType||'fixed';E('editDiscountValue').value=discountValue;E('editTaxPercent').value=taxPercent;var opts='<option value="">Select tax</option>';(meta.tax_rates||[]).forEach(function(t){opts+='<option value="'+Number(t.rate_percent||0)+'">'+esc(t.tax_name||'Tax')+' - '+Number(t.rate_percent||0)+'%</option>'});E('editTaxPreset').innerHTML=opts;E('itemsView').style.display='none';E('productsEditor').classList.add('open');renderEditLines()}
function endItemsEdit(){E('productsEditor').classList.remove('open');E('itemsView').style.display='block'}
E('editItemsButton').onclick=beginItemsEdit;E('cancelItemsEdit').onclick=endItemsEdit;E('addLineButton').onclick=function(){syncEditLines();editLines.push(normalizeLine({quantity:1,tax_percent:taxPercent}));renderEditLines()};E('lineEditor').addEventListener('input',function(){renderEditTotals()});E('lineEditor').addEventListener('click',function(e){var b=e.target.closest('[data-remove-line]');if(!b)return;syncEditLines();editLines.splice(Number(b.dataset.removeLine),1);if(!editLines.length)editLines.push(normalizeLine({quantity:1,tax_percent:taxPercent}));renderEditLines()});
E('discountToggle').onclick=function(){E('discountRow').classList.add('show')};E('discountDone').onclick=function(){discountType=E('editDiscountType').value;discountValue=Math.max(0,Number(E('editDiscountValue').value||0));E('discountRow').classList.remove('show');renderEditTotals()};E('taxToggle').onclick=function(){E('taxRow').classList.add('show')};E('editTaxPreset').onchange=function(){if(this.value!=='')E('editTaxPercent').value=this.value};E('taxDone').onclick=function(){taxPercent=Math.max(0,Math.min(100,Number(E('editTaxPercent').value||0)));editLines.forEach(function(l){l.tax_percent=taxPercent});E('taxRow').classList.remove('show');renderEditTotals()};
E('saveItemsEdit').onclick=function(){syncEditLines();for(var i=0;i<editLines.length;i++){if(!String(editLines[i].item_name||'').trim()){var key=lineKey(editLines[i]),c=catalogByKey(key);if(c)editLines[i].item_name=c.name}if(!String(editLines[i].item_name||'').trim()){toast('warning','Select a Product / Service name for line '+(i+1)+'.');return}}post('save_view_line_items',{line_items_json:JSON.stringify(editLines),discount_type:discountType,discount_value:discountValue}).then(function(r){endItemsEdit();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})};
function openCatalogModal(name){E('newItemType').value='service';E('newItemName').value=name||'';E('newItemDescription').value='';E('newItemUnitCost').value='0.00';E('newItemMarkup').value='0';E('newItemUnitPrice').value='0.00';E('newItemTax').value=String(taxPercent||0);E('newItemImage').value='';E('catalogModal').classList.add('open')}
function closeCatalogModal(){E('catalogModal').classList.remove('open');pendingCatalogLine=-1}document.querySelectorAll('[data-close-catalog]').forEach(function(b){b.onclick=closeCatalogModal});E('newItemMarkup').oninput=function(){var cost=Number(E('newItemUnitCost').value||0),m=Number(this.value||0);E('newItemUnitPrice').value=(cost*(1+m/100)).toFixed(2)};E('newItemUnitCost').oninput=function(){var cost=Number(this.value||0),m=Number(E('newItemMarkup').value||0);E('newItemUnitPrice').value=(cost*(1+m/100)).toFixed(2)};
E('createCatalogButton').onclick=function(){var name=E('newItemName').value.trim();if(!name){toast('warning','Enter Product / Service name.');return}var idx=pendingCatalogLine,fd=new FormData();fd.append('action','create_catalog_item');fd.append('item_type',E('newItemType').value);fd.append('name',name);fd.append('description',E('newItemDescription').value);fd.append('unit_cost',E('newItemUnitCost').value);fd.append('markup_percent',E('newItemMarkup').value);fd.append('unit_price',E('newItemUnitPrice').value);fd.append('tax_percent',E('newItemTax').value);var f=E('newItemImage').files[0];if(f)fd.append('item_image',f);var btn=this;btn.disabled=true;postForm(fd).then(function(r){var item=r.item||{};if(!item.catalog_key)throw new Error('Product / Service was not returned.');meta.catalog_items.push(item);if(idx>=0&&editLines[idx]){editLines[idx]=normalizeLine({product_service_id:item.product_service_id,product_id:item.product_id,item_source:item.source_type==='product'?'product':'product_service',item_type:item.item_type||item.source_label,item_name:item.name,description:item.description,quantity:editLines[idx].quantity||1,unit_cost:item.unit_cost!=null?item.unit_cost:item.base_unit_price,unit_price:item.unit_price!=null?item.unit_price:item.selling_price,tax_percent:item.tax_percent});}closeCatalogModal();renderEditLines();toast('success',r.message)}).catch(function(e){toast('error',e.message)}).then(function(){btn.disabled=false})};

/* ---------- Labor + Expenses ---------- */
function renderTime(){var rows=data.time_entries||[];E('timeEntries').innerHTML=rows.map(function(x){var hrs=Number(x.duration_minutes||0)/60;return '<div class="jvx-entry"><span><strong>'+esc(x.user_name||'Team member')+'</strong> '+date(x.entry_date)+' · '+hrs.toFixed(2)+' hrs</span><strong>'+money(x.labor_cost)+'</strong></div>'}).join('')}
function teamSelectOptions(selected){var h='<option value="">Select team member</option>';((meta.team_members&&meta.team_members.length?meta.team_members:meta.users)||[]).forEach(function(u){h+='<option value="'+Number(u.id)+'"'+(Number(selected)===Number(u.id)?' selected':'')+'>'+esc(u.name||'Team member')+'</option>'});return h}
E('addTimeButton').onclick=function(){openModal('New Time Entry','<div class="jvx-grid2"><div class="jvx-field"><label>Team member</label><select id="teUser" class="jvx-control">'+teamSelectOptions(0)+'</select></div><div class="jvx-field"><label>Date</label><input type="date" id="teDate" class="jvx-control" value="'+today()+'"></div><div class="jvx-field"><label>Start time</label><input type="time" id="teStart" class="jvx-control"></div><div class="jvx-field"><label>End time</label><input type="time" id="teEnd" class="jvx-control"></div><div class="jvx-field"><label>Hourly rate</label><input type="number" min="0" step="0.01" id="teRate" class="jvx-control" value="0"></div><div class="jvx-field" style="grid-column:1/-1"><label>Notes</label><textarea id="teNotes" class="jvx-control" rows="3"></textarea></div></div>','<button type="button" class="jvx-btn" id="teCancel">Cancel</button><button type="button" class="jvx-btn primary" id="teSave">Save</button>');E('teCancel').onclick=closeModal;E('teSave').onclick=function(){post('add_time_entry',{employee_id:E('teUser').value,entry_date:E('teDate').value,start_time:E('teStart').value,end_time:E('teEnd').value,hourly_rate:E('teRate').value,notes:E('teNotes').value}).then(function(r){closeModal();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}
function renderExpenses(){var rows=data.expenses||[];E('expenseEntries').innerHTML=rows.map(function(x){return '<div class="jvx-entry"><span><strong>'+esc(x.item_name||'Expense')+'</strong> '+date(x.expense_date)+(x.reimburse_name?' · '+esc(x.reimburse_name):'')+'</span><strong>'+money(Number(x.amount||0)+Number(x.tax_amount||0))+'</strong></div>'}).join('')}
E('addExpenseButton').onclick=function(){openModal('Add Expense','<div class="jvx-grid2"><div class="jvx-field"><label>Item name</label><input id="exName" class="jvx-control"></div><div class="jvx-field"><label>Accounting code</label><input id="exCode" class="jvx-control"></div><div class="jvx-field"><label>Date</label><input type="date" id="exDate" class="jvx-control" value="'+today()+'"></div><div class="jvx-field"><label>Amount</label><input type="number" min="0" step="0.01" id="exAmount" class="jvx-control" value="0.00"></div><div class="jvx-field"><label>Reimburse to</label><select id="exUser" class="jvx-control">'+teamSelectOptions(0)+'</select></div><div class="jvx-field"><label>Receipt</label><input type="file" id="exReceipt" class="jvx-control" accept="image/*,.pdf"></div><div class="jvx-field" style="grid-column:1/-1"><label>Description</label><textarea id="exDesc" class="jvx-control" rows="3"></textarea></div></div>','<button type="button" class="jvx-btn" id="exCancel">Cancel</button><button type="button" class="jvx-btn primary" id="exSave">Save</button>',true);E('exCancel').onclick=closeModal;E('exSave').onclick=function(){var fd=new FormData();fd.append('action','add_expense');fd.append('item_name',E('exName').value);fd.append('accounting_code',E('exCode').value);fd.append('expense_date',E('exDate').value);fd.append('amount',E('exAmount').value);fd.append('reimburse_user_id',E('exUser').value);fd.append('description',E('exDesc').value);var f=E('exReceipt').files[0];if(f)fd.append('receipt',f);postForm(fd).then(function(r){closeModal();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}

/* ---------- Visits, selection, move dates, arrival window ---------- */
function renderVisits(){var ordered=visits.slice(),dated=ordered.filter(function(v){return v.scheduled_start});E('firstVisit').textContent=dated.length?date(dated[0].scheduled_start):'-';E('lastVisit').textContent=dated.length?date(dated[dated.length-1].scheduled_start):'-';E('checklistSummary').textContent=checklists.length?(checklists.length===1?(checklists[0].name||'Checklist'):(checklists.length+' checklists')):'No checklist';var rows=ordered.filter(function(v){return visitFilter==='all'||statusForVisit(v)===visitFilter});E('visitRows').innerHTML=rows.length?rows.map(function(v){var vs=statusForVisit(v),checked=!!selectedVisits[Number(v.id)],names=v.assignee_names||[];return '<tr><td><input type="checkbox" class="jvx-checkbox" data-visit-check="'+Number(v.id)+'"'+(checked?' checked':'')+'></td><td><strong>'+date(v.scheduled_start)+'</strong><br><span style="color:#6d838e">'+(Number(v.anytime||0)===1?'Anytime':time(v.scheduled_start))+'</span></td><td><strong>'+esc(v.display_title||job.title||'Visit')+'</strong>'+(v.display_instructions?'<br><span style="color:#657d88">'+esc(v.display_instructions)+'</span>':'')+'</td><td><span class="jvx-visit-status '+vs+'">'+cap(vs)+'</span></td><td>'+(names.length?'<span class="jvx-assignee-chip"><span class="jvx-avatar">'+esc((names[0]||'U').trim().charAt(0).toUpperCase())+'</span>'+esc(names.join(', '))+'</span>':'-')+'</td><td><div class="jvx-row-actions">'+(String(v.status)!=='completed'?'<button type="button" class="jvx-icon-btn" data-complete-visit="'+Number(v.id)+'" title="Complete"><i class="bi bi-check2-circle"></i></button><button type="button" class="jvx-icon-btn" data-edit-visit="'+Number(v.id)+'" title="Edit"><i class="bi bi-pencil"></i></button>':'<button type="button" class="jvx-icon-btn" data-edit-visit="'+Number(v.id)+'"><i class="bi bi-three-dots"></i></button>')+'</div></td></tr>'}).join(''):'<tr><td colspan="6" class="jvx-reminder-empty">No scheduled visits.</td></tr>';syncVisitSelectionUi()}
function selectedVisitIds(){return Object.keys(selectedVisits).filter(function(k){return selectedVisits[k]}).map(Number)}
function syncVisitSelectionUi(){var ids=selectedVisitIds();E('visitSelection').classList.toggle('show',ids.length>0);E('selectionCount').textContent=ids.length+' selected';E('visitCheckAll').checked=visits.length>0&&ids.length===visits.length;E('visitCheckAll').indeterminate=ids.length>0&&ids.length<visits.length}
E('visitRows').addEventListener('change',function(e){var c=e.target.closest('[data-visit-check]');if(!c)return;selectedVisits[Number(c.dataset.visitCheck)]=c.checked;syncVisitSelectionUi()});E('visitCheckAll').onchange=function(){var val=this.checked;visits.forEach(function(v){selectedVisits[Number(v.id)]=val});renderVisits()};E('selectionCheck').onclick=function(e){e.preventDefault()};E('deselectVisits').onclick=function(){selectedVisits={};renderVisits()};
E('visitRows').addEventListener('click',function(e){var edit=e.target.closest('[data-edit-visit]'),complete=e.target.closest('[data-complete-visit]');if(edit){var id=Number(edit.dataset.editVisit),v=visits.find(function(x){return Number(x.id)===id});openVisit(v)}if(complete&&confirm('Mark this visit completed?'))post('complete_visit',{visit_id:Number(complete.dataset.completeVisit)}).then(function(r){toast('success',r.message);load()}).catch(function(x){toast('error',x.message)})});
E('visitPlus').onclick=function(e){e.stopPropagation();E('visitPlusMenu').classList.toggle('open')};E('addSingleVisit').onclick=function(){E('visitPlusMenu').classList.remove('open');openVisit(null)};E('addMultipleVisits').onclick=function(){E('visitPlusMenu').classList.remove('open');openMultipleVisits()};
function selectedValues(id){var s=E(id);return s?Array.prototype.slice.call(s.options).filter(function(o){return o.selected}).map(function(o){return Number(o.value)}).filter(function(v){return v>0}):[]}
function visitPayload(v){var ids=selectedValues('visitAssignees');return{title:E('visitTitle').value,date:E('visitDate').value,start_time:E('visitStart').value,end_time:E('visitEnd').value,anytime:E('visitAnytime').checked?1:0,schedule_later:E('visitLater').checked?1:0,assignee_ids:ids,instructions:E('visitInstructions').value,email_team_about_assignment:E('visitEmailTeam').checked?1:0}}
function visitAssigneeOptions(ids){ids=ids||[];return ((meta.users||[]).map(function(u){return '<option value="'+Number(u.id)+'"'+(ids.indexOf(Number(u.id))>=0?' selected':'')+'>'+esc(u.name||'Team member')+'</option>'})).join('')}
function openVisit(v){v=v||{};var raw=v.scheduled_start?String(v.scheduled_start):'',d=raw?raw.slice(0,10):(job.start_date||today()),st=raw?raw.slice(11,16):'09:00',en=v.scheduled_end?String(v.scheduled_end).slice(11,16):'10:00';openModal(v.id?'Edit Visit':'Add Visit','<div class="jvx-grid2"><div class="jvx-field" style="grid-column:1/-1"><label>Title</label><input id="visitTitle" class="jvx-control" value="'+esc(v.display_title||job.title||'')+'"></div><div class="jvx-field"><label>Date</label><input type="date" id="visitDate" class="jvx-control" value="'+esc(d)+'"></div><div class="jvx-field"><label>Assigned</label><select multiple id="visitAssignees" class="jvx-control">'+visitAssigneeOptions(v.assignee_ids||[])+'</select></div><div class="jvx-field"><label>Start time</label><input type="time" id="visitStart" class="jvx-control" value="'+esc(st)+'"></div><div class="jvx-field"><label>End time</label><input type="time" id="visitEnd" class="jvx-control" value="'+esc(en)+'"></div><label class="jvx-check"><input type="checkbox" id="visitAnytime"'+(Number(v.anytime||0)?' checked':'')+'> Anytime</label><label class="jvx-check"><input type="checkbox" id="visitLater"'+(Number(v.schedule_later||0)?' checked':'')+'> Schedule later</label><div class="jvx-field" style="grid-column:1/-1"><label>Visit instructions</label><textarea id="visitInstructions" class="jvx-control" rows="4">'+esc(v.display_instructions||'')+'</textarea></div><label class="jvx-check" style="grid-column:1/-1"><input type="checkbox" id="visitEmailTeam"'+(Number(v.email_team_about_assignment||0)?' checked':'')+'> Email team when assigned</label></div>','<button type="button" class="jvx-btn" id="visitCancel">Cancel</button><button type="button" class="jvx-btn primary" id="visitSave">Save</button>',true);E('visitCancel').onclick=closeModal;E('visitSave').onclick=function(){post('save_visit',{visit_id:Number(v.id||0),visit_json:JSON.stringify(visitPayload(v))}).then(function(r){closeModal();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}
function openMultipleVisits(){openModal('Add Multiple Visits','<div class="jvx-field"><label>Dates</label><div class="jvx-date-grid" id="multiDates"><input type="date" class="jvx-control" value="'+(job.start_date||today())+'"><input type="date" class="jvx-control"><input type="date" class="jvx-control"></div><button type="button" class="jvx-link" id="multiAddDate" style="margin-top:9px">Add another date</button></div><div class="jvx-grid2"><div class="jvx-field"><label>Start time</label><input type="time" id="multiStart" class="jvx-control" value="09:00"></div><div class="jvx-field"><label>End time</label><input type="time" id="multiEnd" class="jvx-control" value="10:00"></div><div class="jvx-field" style="grid-column:1/-1"><label>Assigned</label><select multiple id="multiUsers" class="jvx-control">'+visitAssigneeOptions([])+'</select></div><div class="jvx-field" style="grid-column:1/-1"><label>Title</label><input id="multiTitle" class="jvx-control" value="'+esc(job.title||'')+'"></div><div class="jvx-field" style="grid-column:1/-1"><label>Visit instructions</label><textarea id="multiInstructions" class="jvx-control" rows="3"></textarea></div><label class="jvx-check"><input type="checkbox" id="multiAnytime"> Anytime</label><label class="jvx-check"><input type="checkbox" id="multiEmail"> Email team when assigned</label></div>','<button type="button" class="jvx-btn" id="multiCancel">Cancel</button><button type="button" class="jvx-btn primary" id="multiSave">Add Visits</button>',true);E('multiCancel').onclick=closeModal;E('multiAddDate').onclick=function(){var inputs=E('multiDates').querySelectorAll('input');if(inputs.length>=20){toast('warning','Maximum 20 visits.');return}var n=document.createElement('input');n.type='date';n.className='jvx-control';E('multiDates').appendChild(n)};E('multiSave').onclick=function(){var dates=Array.prototype.slice.call(E('multiDates').querySelectorAll('input')).map(function(x){return x.value}).filter(Boolean),users=selectedValues('multiUsers');var rows=dates.map(function(d){return{title:E('multiTitle').value,date:d,start_time:E('multiStart').value,end_time:E('multiEnd').value,anytime:E('multiAnytime').checked?1:0,schedule_later:0,assignee_ids:users,instructions:E('multiInstructions').value,email_team_about_assignment:E('multiEmail').checked?1:0}});post('add_multiple_visits',{visits_json:JSON.stringify(rows)}).then(function(r){closeModal();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}
E('moveVisits').onclick=function(){var ids=selectedVisitIds(),picked=visits.filter(function(v){return ids.indexOf(Number(v.id))>=0});if(!picked.length)return;openModal('Move dates','<div class="jvx-move-list"><div class="jvx-move-head"><span>Current date</span><span></span><span>Updated date</span></div>'+picked.map(function(v){var d=String(v.scheduled_start||'').slice(0,10);return '<div class="jvx-move-row"><span class="jvx-move-current">'+date(d)+'</span><span class="arrow">&rarr;</span><input type="date" class="jvx-control" data-move-visit="'+Number(v.id)+'" value="'+esc(d)+'"></div>'}).join('')+'</div>','<button type="button" class="jvx-btn" id="moveCancel">Cancel</button><button type="button" class="jvx-btn primary" id="moveSave">Update</button>',true);E('moveCancel').onclick=closeModal;E('moveSave').onclick=function(){var rows=Array.prototype.slice.call(document.querySelectorAll('[data-move-visit]')).map(function(x){return{visit_id:Number(x.dataset.moveVisit),date:x.value}});post('move_visits',{moves_json:JSON.stringify(rows)}).then(function(r){selectedVisits={};closeModal();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}
E('deleteVisits').onclick=function(){var ids=selectedVisitIds();if(!ids.length||!confirm('Delete '+ids.length+' selected visit(s)?'))return;post('delete_visits',{visit_ids_json:JSON.stringify(ids)}).then(function(r){selectedVisits={};toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})};
E('arrivalEditLink').onclick=function(){var current=Number(job.arrival_window_minutes||0),opts=[{v:0,t:'None'},{v:15,t:'15 min'},{v:30,t:'30 min'},{v:60,t:'1 hr'},{v:120,t:'2 hr'},{v:180,t:'3 hr'},{v:240,t:'4 hr'}];openModal('Arrival window','<div class="jvx-arrival-options">'+opts.map(function(o){return '<button type="button" class="jvx-arrival-option'+(o.v===current?' active':'')+'" data-arrival="'+o.v+'">'+o.t+'</button>'}).join('')+'</div><div class="jvx-modal-note">The arrival window is shown to the team and customer around the scheduled visit start time.</div>','<button type="button" class="jvx-btn" id="arrivalCancel">Cancel</button><button type="button" class="jvx-btn primary" id="arrivalSave">Save</button>');var selected=current;E('modalBody').onclick=function(e){var b=e.target.closest('[data-arrival]');if(!b)return;selected=Number(b.dataset.arrival);document.querySelectorAll('[data-arrival]').forEach(function(x){x.classList.toggle('active',x===b)})};E('arrivalCancel').onclick=closeModal;E('arrivalSave').onclick=function(){post('save_arrival_window',{arrival_window_minutes:selected}).then(function(r){closeModal();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}

/* ---------- Checklists ---------- */
function openChecklists(){var modal=E('checklistModal'),body=E('checklistModalBody');E('checklistModalTitle').textContent='Checklists';E('checklistModalSubtitle').textContent=checklists.length+' checklist'+(checklists.length===1?'':'s')+' attached to this job';if(!checklists.length)body.innerHTML='<div class="jv-checklist-modal-empty">No checklist attached.</div>';else body.innerHTML=checklists.map(function(c){var grouped={};(c.items||[]).forEach(function(i){var s=i.section_title||'General';(grouped[s]=grouped[s]||[]).push(i)});return '<section class="jv-checklist-section"><div class="jv-checklist-section-head">'+esc(c.name||'Checklist')+'</div>'+Object.keys(grouped).map(function(s){return '<div style="padding:12px 14px;background:#fbfcfd;font-weight:700;font-size:10px">'+esc(s)+'</div>'+grouped[s].map(function(i){var ans=Array.isArray(i.answer)?i.answer.join(', '):(i.answer||'Not answered');return '<div class="jv-checklist-question"><div class="jv-checklist-question-no">'+(Number(i.sort_order||0)||'&bull;')+'</div><div><div class="jv-checklist-question-title">'+esc(i.title||'Question')+' <span class="jv-checklist-type">'+esc(cap(i.question_type))+'</span></div><div class="jv-checklist-question-desc">'+esc(i.description||'')+'</div></div><div class="jv-checklist-answer"><span class="jv-checklist-answer-label">Answer</span><div class="jv-checklist-answer-value">'+esc(ans)+'</div></div></div>'}).join('')}).join('')+'</section>'}).join('');modal.classList.add('open')}
E('checklistEditLink').onclick=openChecklists;E('checklistModalClose').onclick=function(){E('checklistModal').classList.remove('open')};E('checklistModal').onclick=function(e){if(e.target===this)this.classList.remove('open')};

/* ---------- Billing + invoice reminders ---------- */
function renderBilling(){var b=data.billing||{},invs=data.invoices||[],rem=data.invoice_reminders||[];E('billingReminderText').textContent=Number(b.remind_to_invoice_on_close||0)?'When the job is marked closed':'No automatic close reminder';var rows=[];invs.forEach(function(i){rows.push('<tr><td>'+esc(i.invoice_no||'-')+'</td><td>'+date(i.due_date)+'</td><td>'+cap(i.status)+'</td><td>'+money(i.total)+'</td><td>'+money(i.balance_due)+'</td></tr>')});var schedule=[];try{schedule=JSON.parse(b.payment_schedule_json||'[]')}catch(e){schedule=[]}var total=Number(job.total||0),scheduled=0;schedule.forEach(function(r){scheduled+=b.payment_split_type==='amount'?Number(r.value||0):total*Number(r.value||0)/100});E('paymentSchedule').innerHTML='<div class="jvx-payment-summary"><strong>Total: '+money(total)+'</strong><div class="jvx-payment-bar"><span style="width:'+Math.min(100,total>0?(scheduled/total*100):0)+'%"></span></div><div class="jvx-payment-legend"><span>Scheduled: '+money(scheduled)+'</span><span>Remaining: '+money(Math.max(0,total-scheduled))+'</span></div></div><div class="jvx-table-wrap"><table class="jvx-table"><thead><tr><th>Invoice</th><th>Due date</th><th>Status</th><th>Total</th><th>Balance</th></tr></thead><tbody>'+(rows.length?rows.join(''):'<tr><td colspan="5" class="jvx-reminder-empty">No invoices have been created for this job.</td></tr>')+'</tbody></table></div>';E('reminderRows').innerHTML=rem.length?rem.map(function(r){var sched=Number(r.schedule_later||0)?'Schedule later':(date(r.start_date)+(Number(r.anytime||0)?' · Anytime':(r.start_time?' · '+time(r.start_time):'')));return '<tr><td>'+sched+'</td><td>'+esc(r.description||'Invoice reminder')+'</td><td>'+cap(r.status||'scheduled')+'</td><td>'+esc(r.assigned_user_name||'-')+'</td><td><button type="button" class="jvx-icon-btn" data-delete-reminder="'+Number(r.id)+'"><i class="bi bi-trash"></i></button></td></tr>'}).join(''):'<tr><td colspan="5" class="jvx-reminder-empty">You\'ll be reminded to invoice based on your invoicing frequency.</td></tr>'}
E('reminderRows').addEventListener('click',function(e){var b=e.target.closest('[data-delete-reminder]');if(!b||!confirm('Delete this invoice reminder?'))return;post('delete_invoice_reminder',{reminder_id:Number(b.dataset.deleteReminder)}).then(function(r){toast('success',r.message);load()}).catch(function(x){toast('error',x.message)})});
document.querySelectorAll('[data-btab]').forEach(function(b){b.onclick=function(){document.querySelectorAll('[data-btab]').forEach(function(x){x.classList.toggle('active',x===b)});E('billingInvoicing').classList.toggle('active',b.dataset.btab==='invoicing');E('billingReminders').classList.toggle('active',b.dataset.btab==='reminders')}});
E('addReminderButton').onclick=function(){var loc=locationAddress();openModal('New invoice reminder','<div class="jvx-reminder-grid"><div class="jvx-field"><label>Details</label><textarea id="remDetails" class="jvx-control" rows="5" placeholder="Details"></textarea></div><div><div style="color:#173746;font-size:12px;font-weight:700;margin-bottom:10px">Job details</div><div class="jvx-job-mini"><span>Job #</span><a href="#">'+esc(job.job_no||'-')+'</a><span>Customer</span><a href="#">'+esc(job.client_name||'-')+'</a><span>Phone</span><a href="'+(job.client_phone?'tel:'+esc(job.client_phone):'#')+'">'+esc(job.client_phone||'-')+'</a><span>Address</span><a href="#">'+esc(loc)+'</a></div></div></div><div class="jvx-reminder-divider"></div><div class="jvx-reminder-bottom"><div><h3 style="margin:0 0 12px;color:#073247">Schedule</h3><div class="jvx-schedule-four"><input type="date" id="remStartDate" class="jvx-control"><input type="date" id="remEndDate" class="jvx-control"><input type="time" id="remStartTime" class="jvx-control"><input type="time" id="remEndTime" class="jvx-control"></div><label class="jvx-check" style="margin-top:10px"><input type="checkbox" id="remLater"> Schedule later</label><label class="jvx-check" style="margin-top:8px"><input type="checkbox" id="remAnytime"> Anytime</label></div><div><h3 style="margin:0 0 12px;color:#073247">Team</h3><div class="jvx-field"><label>Assign</label><select id="remUser" class="jvx-control">'+teamSelectOptions(0)+'</select></div><label class="jvx-check"><input type="checkbox" id="remEmail"> Email team when assigned</label></div></div>','<button type="button" class="jvx-btn" id="remCancel">Cancel</button><button type="button" class="jvx-btn primary" id="remSave">Save</button>',true);E('remCancel').onclick=closeModal;E('remSave').onclick=function(){post('add_invoice_reminder',{description:E('remDetails').value,start_date:E('remStartDate').value,end_date:E('remEndDate').value,start_time:E('remStartTime').value,end_time:E('remEndTime').value,schedule_later:E('remLater').checked?1:0,anytime:E('remAnytime').checked?1:0,assigned_user_id:E('remUser').value,email_team_when_assigned:E('remEmail').checked?1:0}).then(function(r){closeModal();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}
E('editBillingButton').onclick=function(){var b=data.billing||{},rows=[];try{rows=JSON.parse(b.payment_schedule_json||'[]')}catch(e){rows=[]}openModal('Invoice Settings','<label class="jvx-check"><input type="checkbox" id="bRemind"'+(Number(b.remind_to_invoice_on_close||0)?' checked':'')+'> Remind me to invoice when I close the job</label><label class="jvx-check" style="margin-top:12px"><input type="checkbox" id="bSplit"'+(Number(b.split_payment_schedule||0)?' checked':'')+'> Split into multiple invoices with a payment schedule</label><div style="margin-top:16px"><div class="jvx-field"><label>Split payments by</label><select id="bType" class="jvx-control"><option value="percentage">%</option><option value="amount">Amount</option></select></div><div id="bRows"></div><button type="button" class="jvx-link" id="bAdd">Add Invoice to Payment Schedule</button></div>','<button type="button" class="jvx-btn" id="bCancel">Cancel</button><button type="button" class="jvx-btn primary" id="bSave">Save</button>',true);var br=Array.isArray(rows)?rows.slice():[];function rr(){E('bRows').innerHTML=br.map(function(r,i){return '<div class="jvx-grid2" style="margin-bottom:8px"><input class="jvx-control" data-bd="'+i+'" value="'+esc(r.description||('Payment '+(i+1)))+'"><input type="number" class="jvx-control" data-bv="'+i+'" value="'+Number(r.value||0)+'"></div>'}).join('')}E('bType').value=b.payment_split_type||'percentage';rr();E('bCancel').onclick=closeModal;E('bAdd').onclick=function(){br.push({description:'Payment '+(br.length+1),value:0,due_date:''});rr()};E('bSave').onclick=function(){br.forEach(function(r,i){var d=document.querySelector('[data-bd="'+i+'"]'),v=document.querySelector('[data-bv="'+i+'"]');r.description=d?d.value:r.description;r.value=v?Number(v.value||0):r.value});post('save_billing_settings',{remind_to_invoice_on_close:E('bRemind').checked?1:0,split_payment_schedule:E('bSplit').checked?1:0,payment_split_type:E('bType').value,payment_schedule_json:JSON.stringify(br)}).then(function(r){closeModal();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}

/* ---------- Cost + Notes ---------- */
function renderCost(){var c=data.cost_summary||{},max=Math.max(Number(c.revenue||0),Number(c.cost||0),1);E('costMain').textContent=money(c.cost);E('revenueValue').textContent=money(c.revenue);E('costValue').textContent=money(c.cost);E('profitValue').textContent=money(c.profit);E('profitPercent').textContent=Number(c.margin_percent||0).toFixed(2)+'%';E('revenueBar').style.width=Math.min(100,Number(c.revenue||0)/max*100)+'%';E('costBar').style.width=Math.min(100,Number(c.cost||0)/max*100)+'%'}
function renderNotes(){var n=String(data.internal_note||'');E('notesBox').innerHTML=n?'<div class="jvx-note-text" id="editNoteBox">'+esc(n)+'</div>':'<div class="jvx-note-empty" id="editNoteBox"><div><i class="bi bi-journal-plus"></i><div>Leave an internal note for<br>yourself or a team member</div></div></div>';E('editNoteBox').onclick=openNote}
function noteTeamOptions(selected){selected=selected||[];return ((meta.team_members||meta.users||[]).map(function(u){return '<label><input type="checkbox" value="'+Number(u.id)+'"'+(selected.indexOf(Number(u.id))>=0?' checked':'')+'> '+esc(u.name||'Team member')+'</label>'})).join('')}
function openNote(){openModal('Notes','<div class="jvx-field"><label>Use @ in notes to mention your team</label><textarea id="nText" class="jvx-control" rows="6">'+esc(data.internal_note||'')+'</textarea></div><div class="jvx-field"><label>Team mentions</label><div id="nTeam" class="jvx-team-checks">'+noteTeamOptions(data.note_mention_ids||[])+'</div></div><div class="jvx-field"><label>Attach files & photos</label><input id="nFiles" type="file" multiple class="jvx-control"></div>','<button type="button" class="jvx-btn" id="nCancel">Cancel</button><button type="button" class="jvx-btn primary" id="nSave">Save</button>');E('nCancel').onclick=closeModal;E('nSave').onclick=function(){var ids=Array.prototype.slice.call(E('nTeam').querySelectorAll('input:checked')).map(function(x){return Number(x.value)}),fd=new FormData();fd.append('action','save_view_note');fd.append('note',E('nText').value);fd.append('mention_ids_json',JSON.stringify(ids));Array.prototype.slice.call(E('nFiles').files||[]).forEach(function(f){fd.append('job_attachments[]',f)});postForm(fd).then(function(r){closeModal();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)})}}

/* ---------- History drawer ---------- */
function renderHistory(){var h=(data.history||[]).slice();var created=job.created_at||'';var hasCreated=h.some(function(x){return String(x.event_type||'').indexOf('created')>=0});if(created&&!hasCreated)h.push({event_type:'job_created',title:'Job created',actor_name:'System',created_at:created,details_json:''});E('historyList').innerHTML=h.length?h.map(function(x){var actor=x.actor_name||'System',details='';try{var d=JSON.parse(x.details_json||'{}');if(d&&d.message)details=String(d.message)}catch(e){}return '<div class="jvx-history-item"><span class="jvx-history-avatar">'+esc(String(actor).trim().charAt(0).toUpperCase()||'S')+'</span><strong>'+esc(x.title||cap(x.event_type))+'</strong><small>'+esc(actor)+' · '+esc(dt(x.created_at))+'</small>'+(details?'<p>'+esc(details)+'</p>':'')+'</div>'}).join(''):'<div class="jvx-history-empty">No job history found.</div>'}
function history(show){E('historyDrawer').classList.toggle('show',show);E('historyOverlay').classList.toggle('show',show)}E('historyButton').onclick=function(){renderHistory();history(true)};E('historyClose').onclick=function(){history(false)};E('historyOverlay').onclick=function(){history(false)};

/* ---------- Booking confirmation email composer ---------- */
function addBookingRecipient(email){email=String(email||'').trim().toLowerCase();if(!email||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){if(email)toast('warning','Enter a valid email address.');return false}if(bookingRecipients.indexOf(email)<0)bookingRecipients.push(email);renderBookingRecipients();return true}
function renderBookingRecipients(){E('bookingRecipientChips').innerHTML=bookingRecipients.map(function(x,i){return '<span class="jvx-email-chip">'+esc(x)+' <button type="button" data-remove-booking-email="'+i+'"><i class="bi bi-x"></i></button></span>'}).join('')}
E('bookingRecipientChips').onclick=function(e){var b=e.target.closest('[data-remove-booking-email]');if(!b)return;bookingRecipients.splice(Number(b.dataset.removeBookingEmail),1);renderBookingRecipients()};
E('bookingRecipientInput').addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===','){e.preventDefault();if(addBookingRecipient(this.value))this.value=''}});E('bookingRecipientInput').addEventListener('blur',function(){if(this.value.trim()&&addBookingRecipient(this.value))this.value=''})
function bookingDefaults(){var company=job.branch_name||'FieldPlx',loc=locationAddress(),when=job.start_date?date(job.start_date):'-';return{subject:'Booking confirmation from '+company,message:'Hi '+(job.client_name||'')+',\n\nThank you for booking with us.\n\nLocation: '+loc+'\nDate: '+when+'\n\nSincerely,\n\n'+company}}
function openBooking(){bookingRecipients=[];bookingFiles=[];if(job.client_email)addBookingRecipient(job.client_email);var d=bookingDefaults();E('bookingModalTitle').textContent='Email booking confirmation to '+(job.client_name||'Customer');E('bookingSubject').value=d.subject;E('bookingMessage').value=d.message;E('bookingSendCopy').checked=false;E('bookingRecipientInput').value='';renderBookingFiles();E('bookingModal').classList.add('open')}
function closeBooking(){E('bookingModal').classList.remove('open')}document.querySelectorAll('[data-close-booking]').forEach(function(b){b.onclick=closeBooking});E('bookingModal').onclick=function(e){if(e.target===this)closeBooking()};
function renderBookingFiles(){var bytes=bookingFiles.reduce(function(a,f){return a+f.size},0),mb=bytes/1024/1024;E('bookingMb').textContent=mb.toFixed(2);E('bookingProgress').style.width=Math.min(100,mb/10*100)+'%';E('bookingFileList').innerHTML=bookingFiles.map(function(f,i){return '<div class="jvx-email-file"><i class="bi bi-paperclip"></i><span>'+esc(f.name)+' · '+(f.size/1024/1024).toFixed(2)+' MB</span><button type="button" data-remove-booking-file="'+i+'"><i class="bi bi-x-lg"></i></button></div>'}).join('')}
function addBookingFiles(list){Array.prototype.slice.call(list||[]).forEach(function(f){if(bookingFiles.length>=10){toast('warning','A maximum of 10 attachments is allowed.');return}var total=bookingFiles.reduce(function(a,x){return a+x.size},0)+f.size;if(total>10*1024*1024){toast('warning','Attachments cannot exceed 10 MB total.');return}bookingFiles.push(f)});renderBookingFiles()}
E('bookingFilePick').onclick=function(){E('bookingFilesInput').click()};E('bookingFilesInput').onchange=function(){addBookingFiles(this.files);this.value=''};E('bookingDrop').ondragover=function(e){e.preventDefault();this.classList.add('drag')};E('bookingDrop').ondragleave=function(){this.classList.remove('drag')};E('bookingDrop').ondrop=function(e){e.preventDefault();this.classList.remove('drag');addBookingFiles(e.dataTransfer.files)};E('bookingFileList').onclick=function(e){var b=e.target.closest('[data-remove-booking-file]');if(!b)return;bookingFiles.splice(Number(b.dataset.removeBookingFile),1);renderBookingFiles()};
E('bookingForm').onsubmit=function(e){e.preventDefault();var pending=E('bookingRecipientInput').value.trim();if(pending){if(!addBookingRecipient(pending))return;E('bookingRecipientInput').value=''}if(!bookingRecipients.length){toast('warning','Add at least one recipient.');return}var fd=new FormData();fd.append('action','send_booking_confirmation');fd.append('to_emails',bookingRecipients.join(','));fd.append('email_subject',E('bookingSubject').value);fd.append('email_message',E('bookingMessage').value);fd.append('send_me_copy',E('bookingSendCopy').checked?'1':'0');bookingFiles.forEach(function(f){fd.append('email_attachments[]',f)});var btn=E('bookingSendButton');btn.disabled=true;btn.textContent='Sending...';postForm(fd).then(function(r){closeBooking();toast('success',r.message);load()}).catch(function(x){toast('error',x.message)}).then(function(){btn.disabled=false;btn.textContent='Send Email'})};

/* ---------- Signature ---------- */
var sigCtx=null,sigDrawing=false,sigDirty=false;function initSignature(){var c=E('signatureCanvas');sigCtx=c.getContext('2d');sigCtx.lineWidth=2;sigCtx.lineCap='round';sigCtx.strokeStyle='#173746';sigCtx.clearRect(0,0,c.width,c.height);sigDirty=false;function pos(ev){var r=c.getBoundingClientRect(),p=ev.touches?ev.touches[0]:ev;return{x:(p.clientX-r.left)*(c.width/r.width),y:(p.clientY-r.top)*(c.height/r.height)}}function down(ev){sigDrawing=true;sigDirty=true;var p=pos(ev);sigCtx.beginPath();sigCtx.moveTo(p.x,p.y);ev.preventDefault()}function move(ev){if(!sigDrawing)return;var p=pos(ev);sigCtx.lineTo(p.x,p.y);sigCtx.stroke();ev.preventDefault()}function up(){sigDrawing=false}c.onmousedown=down;c.onmousemove=move;c.onmouseup=up;c.onmouseleave=up;c.ontouchstart=down;c.ontouchmove=move;c.ontouchend=up}
function openSignature(){E('signatureSendCopy').checked=false;E('signatureModal').classList.add('open');setTimeout(initSignature,40)}function closeSignature(){E('signatureModal').classList.remove('open')}document.querySelectorAll('[data-close-signature]').forEach(function(b){b.onclick=closeSignature});E('signatureModal').onclick=function(e){if(e.target===this)closeSignature()};E('clearSignature').onclick=function(){if(sigCtx){var c=E('signatureCanvas');sigCtx.clearRect(0,0,c.width,c.height);sigDirty=false}};E('saveSignatureButton').onclick=function(){if(!sigDirty){toast('warning','Please write a signature before submitting.');return}var c=E('signatureCanvas'),btn=this;btn.disabled=true;post('save_signature',{signature_data:c.toDataURL('image/png'),send_copy:E('signatureSendCopy').checked?1:0}).then(function(r){closeSignature();toast('success',r.message);load()}).catch(function(e){toast('error',e.message)}).then(function(){btn.disabled=false})};

/* ---------- More actions ---------- */
E('moreButton').onclick=function(e){e.stopPropagation();E('moreMenu').classList.toggle('open')};document.addEventListener('click',function(e){if(!e.target.closest('.jvx-more-wrap'))E('moreMenu').classList.remove('open');if(!e.target.closest('.jvx-plus'))E('visitPlusMenu').classList.remove('open');if(!e.target.closest('.jvx-customer-menu-wrap'))E('customerMenu').classList.remove('open')});
E('moreMenu').onclick=function(e){var b=e.target.closest('[data-more]');if(!b)return;E('moreMenu').classList.remove('open');var a=b.dataset.more;if(a==='close'&&confirm('Close this job?'))post('close_job').then(function(r){toast('success',r.message);load()}).catch(function(x){toast('error',x.message)});if(a==='delete'&&confirm('Delete this job? The job will be archived.'))post('delete_job').then(function(){location.href='jobs'}).catch(function(x){toast('error',x.message)});if(a==='similar'&&confirm('Create a similar draft job from this job?'))post('create_similar_job').then(function(r){location.href='job-form.php?job_id='+r.job_id}).catch(function(x){toast('error',x.message)});if(a==='followup')openFollowup();if(a==='booking')openBooking();if(a==='signature')openSignature();if(a==='costcsv')openCostEmail()};
function openFollowup(){openModal('Send Job Follow-up Email','<div class="jvx-field"><label>To</label><input id="fTo" class="jvx-control" value="'+esc(job.client_email||'')+'"></div><div class="jvx-field"><label>Subject</label><input id="fSubject" class="jvx-control" value="Follow-up for '+esc(job.job_no||'')+'"></div><div class="jvx-field"><label>Message</label><textarea id="fMessage" class="jvx-control" rows="7">Hi '+esc(job.client_name||'')+',\n\nWe are following up regarding '+esc(job.title||'your job')+'.\n\nThank you.</textarea></div>','<button type="button" class="jvx-btn" id="fCancel">Cancel</button><button type="button" class="jvx-btn primary" id="fSend">Send</button>');E('fCancel').onclick=closeModal;E('fSend').onclick=function(){post('send_followup_email',{to_email:E('fTo').value,subject:E('fSubject').value,message:E('fMessage').value}).then(function(r){closeModal();toast('success',r.message)}).catch(function(e){toast('error',e.message)})}}
function openCostEmail(){openModal('Email Job Costs CSV','<div class="jvx-field"><label>Email address</label><input id="cTo" type="email" class="jvx-control"></div><p class="jvx-modal-note">The CSV includes Product / Service cost, labor, expenses, revenue, total cost and profit.</p>','<button type="button" class="jvx-btn" id="cCancel">Cancel</button><button type="button" class="jvx-btn primary" id="cSend">Send CSV</button>');E('cCancel').onclick=closeModal;E('cSend').onclick=function(){post('email_job_costs_csv',{to_email:E('cTo').value}).then(function(r){closeModal();toast('success',r.message)}).catch(function(e){toast('error',e.message)})}}

/* ---------- close special modals / keyboard ---------- */
document.addEventListener('keydown',function(e){if(e.key!=='Escape')return;closeModal();closeBooking();closeSignature();closeCustomerModal();closePropertyModal();closeCatalogModal();history(false);E('checklistModal').classList.remove('open')});
if(jobId<=0){E('loadingState').style.display='none';E('errorState').style.display='flex';E('errorMessage').textContent='Invalid job ID.'}else load();
})();
</script>
</body>
</html>
