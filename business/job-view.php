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

    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>

<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="fd-dashboard jv-page">
                <section class="jv-head">
                    <div class="jv-head-copy">
                        <div class="jv-title-row">
                            <h1 class="jv-title" id="pageTitle">Job Card</h1>
                            <span class="jv-badge scheduled" id="pageStatus">Loading</span>
                        </div>
                        <p class="jv-sub" id="pageSubtitle">Job details, visits, items, billing and assigned employees.</p>
                    </div>

                    <div class="jv-actions">
                        <a class="jv-btn" href="jobs"><i class="bi bi-arrow-left"></i> Back to Jobs</a>
                        <button type="button" class="jv-btn email" id="resendEmailButton" style="display:none">
                            <span class="jv-loader" aria-hidden="true"></span>
                            <i class="bi bi-envelope-arrow-up"></i>
                            <span id="resendEmailText">Resend Email</span>
                        </button>
                        <a class="jv-btn primary" href="#" id="editButton"><i class="bi bi-pencil"></i> Edit Job</a>
                    </div>
                </section>

                <div id="loadingState" class="jv-loading"><div><div class="jv-spinner"></div><div>Loading job card...</div></div></div>
                <div id="errorState" class="jv-error" style="display:none"><div><i class="bi bi-exclamation-circle"></i><h2>Unable to load job</h2><p id="errorMessage">The job could not be loaded.</p><a class="jv-btn" href="jobs"><i class="bi bi-arrow-left"></i> Back to Jobs</a></div></div>

                <div id="jobContent" style="display:none">
                    <section class="jv-summary">
                        <article class="jv-stat"><span class="jv-stat-label">Customer</span><strong class="jv-stat-value" id="summaryCustomer">-</strong></article>
                        <article class="jv-stat"><span class="jv-stat-label">Job Source</span><strong class="jv-stat-value" id="summarySource">-</strong></article>
                        <article class="jv-stat"><span class="jv-stat-label">Items</span><strong class="jv-stat-value" id="summaryService">-</strong></article>
                        <article class="jv-stat"><span class="jv-stat-label">Job Total</span><strong class="jv-stat-value money" id="summaryTotal">-</strong></article>
                    </section>

                    <div class="jv-grid">
                        <div class="jv-stack">
                            <section class="jv-card">
                                <div class="jv-card-head">
                                    <div><h2>Job Details</h2></div>
                                    <span class="jv-source-pill" id="jobSourceBadge"><i class="bi bi-briefcase"></i><span id="jobSourceBadgeText">Direct Job</span></span>
                                </div>
                                <div class="jv-card-body">
                                    <div class="jv-details">
                                        <div class="jv-detail"><span class="label">Job Number</span><span class="value" id="jobNo">-</span></div>
                                        <div class="jv-detail"><span class="label">Status</span><span class="value" id="statusText">-</span></div>
                                        <div class="jv-detail"><span class="label">Job Title</span><span class="value" id="jobTitle">-</span></div>
                                        <div class="jv-detail"><span class="label">Job Type</span><span class="value" id="jobType">-</span></div>
                                        <div class="jv-detail"><span class="label">Priority</span><span class="value" id="priority">-</span></div>
                                        <div class="jv-detail"><span class="label">Assignment Mode</span><span class="value" id="assignmentMode">-</span></div>
                                        <div class="jv-detail"><span class="label">Completion Rule</span><span class="value" id="completionMode">-</span></div>
                                        <div class="jv-detail"><span class="label">Branch</span><span class="value" id="branchName">-</span></div>
                                        <div class="jv-detail"><span class="label">Workflow</span><span class="value" id="workflowName">-</span></div>
                                        <div class="jv-detail"><span class="label">Request / Enquiry</span><span class="value" id="requestNoMain">-</span></div>
                                        <div class="jv-detail full"><span class="label">Work Instructions</span><span class="value" id="description">-</span></div>
                                    </div>
                                </div>
                            </section>

                            <section class="jv-card" id="customFieldsCard" style="display:none">
                                <div class="jv-card-head">
                                    <div><h2>Custom Fields</h2></div>
                                    <span class="jv-count" id="customFieldCount">0</span>
                                </div>
                                <div class="jv-card-body">
                                    <div class="jv-custom-fields" id="customFieldsList"></div>
                                </div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head">
                                    <div><h2>Product / Service</h2></div>
                                    <span class="jv-count" id="lineItemCount">0</span>
                                </div>
                                <div class="jv-table-wrap">
                                    <table class="jv-table jv-items-table">
                                        <thead><tr><th>Type</th><th>Item</th><th>Qty</th><th>Unit Cost</th><th>Unit Price</th><th>Discount</th><th>Tax</th><th>Total</th></tr></thead>
                                        <tbody id="lineItemRows"><tr><td colspan="8" class="jv-empty">No items added.</td></tr></tbody>
                                    </table>
                                </div>
                                <div class="jv-items-summary">
                                    <div></div>
                                    <div class="jv-items-totals">
                                        <div class="jv-items-total-row"><span>Subtotal</span><strong id="itemsSubtotal">-</strong></div>
                                        <div class="jv-items-total-row"><span>Discount</span><strong id="itemsDiscount">-</strong></div>
                                        <div class="jv-items-total-row"><span>Tax</span><strong id="itemsTax">-</strong></div>
                                        <div class="jv-items-total-row grand"><span>Total</span><strong id="itemsTotal">-</strong></div>
                                        <div class="jv-items-total-row"><span>Total Cost</span><strong id="itemsCost">-</strong></div>
                                    </div>
                                </div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head">
                                    <div><h2>Job Schedules & Visits</h2></div>
                                    <div class="jv-card-head-actions"><span class="jv-count" id="scheduleCount">0</span></div>
                                </div>
                                <div class="jv-card-body"><div class="jv-schedule-list" id="schedulePlans"><div class="jv-empty">No schedule plans.</div></div></div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head"><div><h2>Assigned Employees</h2></div><span class="jv-count" id="assignmentCount">0</span></div>
                                <div class="jv-table-wrap">
                                    <table class="jv-table">
                                        <thead><tr><th>S.No</th><th>Employee</th><th>Email</th><th>Role</th><th>Primary</th><th>Status</th></tr></thead>
                                        <tbody id="assignmentRows"><tr><td colspan="6" class="jv-empty">No assigned employees.</td></tr></tbody>
                                    </table>
                                </div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head"><div><h2>Files & Photos</h2></div><span class="jv-count" id="attachmentCount">0</span></div>
                                <div class="jv-card-body"><div class="jv-attachments" id="attachmentList"><div class="jv-empty">No files or photos attached.</div></div></div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head"><div><h2>Notes</h2></div></div>
                                <div class="jv-card-body"><div class="jv-note-view empty" id="internalNoteView">No internal note added.</div></div>
                            </section>
                        </div>

                        <aside class="jv-stack">
                            <section class="jv-card">
                                <div class="jv-card-head"><div><h2>Schedule Overview</h2></div></div>
                                <div class="jv-card-body"><div class="jv-schedule"><div class="jv-schedule-box"><span>Start</span><strong id="scheduleStart">-</strong></div><div class="jv-schedule-box"><span>End</span><strong id="scheduleEnd">-</strong></div></div></div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head"><div><h2>Customer & Job Source</h2></div></div>
                                <div class="jv-card-body"><div class="jv-source-details">
                                    <div class="jv-source-row"><span>Customer</span><strong id="clientName">-</strong></div>
                                    <div class="jv-source-row"><span>Email</span><strong id="clientEmail">-</strong></div>
                                    <div class="jv-source-row"><span>Phone</span><strong id="clientPhone">-</strong></div>
                                    <div class="jv-source-row"><span>Source</span><strong id="sourceTypeText">-</strong></div>
                                    <div class="jv-source-row" id="quoteSourceRow"><span>Quotation No.</span><strong id="quoteNo">-</strong></div>
                                    <div class="jv-source-row"><span>Service</span><strong id="serviceName">-</strong></div>
                                    <div class="jv-source-row"><span>Enquiry No.</span><strong id="requestNo">-</strong></div>
                                </div></div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head"><div><h2>Service Location</h2></div></div>
                                <div class="jv-card-body">
                                    <div class="jv-location-details">
                                        <div class="jv-location-row"><span>Location</span><strong id="locationName">Primary / no saved location</strong></div>
                                        <div class="jv-location-row"><span>Address</span><strong id="locationAddress">-</strong></div>
                                        <div class="jv-location-row"><span>City / State</span><strong id="locationCityState">-</strong></div>
                                        <div class="jv-location-row"><span>Country</span><strong id="locationCountry">-</strong></div>
                                    </div>
                                </div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head"><div><h2>Billing</h2></div></div>
                                <div class="jv-card-body">
                                    <div class="jv-billing-grid">
                                        <div class="jv-billing-box"><span>Billing Type</span><strong id="billingType">-</strong></div>
                                        <div class="jv-billing-box"><span>Invoice Frequency</span><strong id="invoiceFrequency">-</strong></div>
                                        <div class="jv-billing-box"><span>Total Invoices</span><strong id="totalInvoices">-</strong></div>
                                        <div class="jv-billing-box"><span>Fixed Amount</span><strong class="money" id="fixedInvoiceAmount">-</strong></div>
                                        <div class="jv-billing-box"><span>First</span><strong id="firstInvoiceDate">-</strong></div>
                                        <div class="jv-billing-box"><span>Last</span><strong id="lastInvoiceDate">-</strong></div>
                                    </div>
                                </div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head"><div><h2>Checklists</h2></div><span class="jv-count" id="checklistCount">0</span></div>
                                <div class="jv-card-body"><div class="jv-checklists" id="checklistList"><div class="jv-empty">No checklist attached.</div></div></div>
                            </section>

                            <section class="jv-card">
                                <div class="jv-card-head"><div><h2>Email Notification</h2></div></div>
                                <div class="jv-card-body"><div class="jv-email-note" id="emailNote"><strong>Resend Email</strong><br>The resend option is available only while this job is in Scheduled status.</div></div>
                            </section>
                        </aside>
                    </div>
                </div>                </div>
            </div>
        </div>
    </main>
</div>

<div class="jv-checklist-modal" id="checklistModal" aria-hidden="true">
    <div class="jv-checklist-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="checklistModalTitle">
        <div class="jv-checklist-modal-head">
            <div class="jv-checklist-modal-title-wrap">
                <h2 class="jv-checklist-modal-title" id="checklistModalTitle">Checklist</h2>
                <div class="jv-checklist-modal-sub" id="checklistModalSubtitle">Checklist details</div>
            </div>
            <div class="jv-checklist-modal-actions">
                <button type="button" class="jv-checklist-close" id="checklistModalClose" aria-label="Close checklist"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="jv-checklist-modal-meta">
            <div class="jv-checklist-modal-meta-box"><span>Status</span><strong id="checklistModalStatus">-</strong></div>
            <div class="jv-checklist-modal-meta-box"><span>Questions</span><strong id="checklistModalQuestions">0</strong></div>
            <div class="jv-checklist-modal-meta-box"><span>Completed</span><strong id="checklistModalCompleted">0 / 0</strong><div class="jv-checklist-progress"><span id="checklistModalProgress" style="width:0%"></span></div></div>
            <div class="jv-checklist-modal-meta-box"><span>Created</span><strong id="checklistModalCreated">-</strong></div>
        </div>
        <div class="jv-checklist-modal-body" id="checklistModalBody"><div class="jv-checklist-modal-empty">No checklist details available.</div></div>
    </div>
</div>

<div class="jv-toast" id="toast"><span id="toastMessage">Notification</span></div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
'use strict';
var jobId=<?= (int)$jobId ?>;
var csrfToken=<?= json_encode($jobsCsrfToken) ?>;
var basePath=<?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>;
var apiUrl=basePath+'/api/job-view.php';
var currentJob=null;
var currentChecklists=[];
var currentMeta={};
var toastTimer=null;
function el(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function text(id,v,f){var n=el(id);if(!n)return;var o=v;if(o===null||o===undefined||String(o).trim()==='')o=f===undefined?'-':f;n.textContent=String(o)}
function title(v){return String(v||'-').replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase()})}
function fmtDate(v){if(!v)return '-';var p=String(v).substring(0,10).split('-');return p.length===3?p[2]+'-'+p[1]+'-'+p[0]:String(v)}
function fmtTime(v){if(!v)return '';var p=String(v).split(':');if(p.length<2)return String(v);var h=parseInt(p[0],10),m=p[1],s=h>=12?'PM':'AM';h=h%12;if(h===0)h=12;return h+':'+m+' '+s}
function schedule(d,t){var x=fmtDate(d);return t?x+' '+fmtTime(t):x}
function money(v,c){var a=Number(v||0),p=parseInt((c||{}).decimal_places,10);if(isNaN(p))p=2;var n=a.toFixed(p),s=(c||{}).symbol||'';return (c||{}).symbol_position==='after'?n+(s?' '+s:''):(s||'')+n}
function notify(type,msg){var t=el('toast'),m=el('toastMessage');if(!t||!m)return;if(toastTimer)clearTimeout(toastTimer);t.className='jv-toast '+(type||'')+' show';m.textContent=msg||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},4500)}
function parseResponse(r){return r.text().then(function(raw){var d,s=String(raw||'').trim();try{d=s?JSON.parse(s):{}}catch(e){throw new Error(s.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||('Request failed with HTTP '+r.status+'.'));return d})}
function request(action){var fd=new FormData();fd.append('action',action);fd.append('job_id',jobId);fd.append('csrf_token',csrfToken);return fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
function userMap(meta){var map={};var users=(meta&&Array.isArray(meta.users))?meta.users:[];users.forEach(function(u){map[Number(u.id)]=u});return map}
function renderAssignments(rows){var body=el('assignmentRows');text('assignmentCount',rows.length);if(!rows.length){body.innerHTML='<tr><td colspan="6" class="jv-empty">No assigned employees.</td></tr>';return}var h=[];rows.forEach(function(r,i){h.push('<tr><td>'+(i+1)+'</td><td><div class="jv-person"><strong>'+esc(r.user_name||'Employee')+'</strong><small>'+esc(r.department_id?'Department ID '+r.department_id:'Assigned user')+'</small></div></td><td>'+esc(r.email||'-')+'</td><td>'+esc(title(r.assignment_role))+'</td><td>'+(Number(r.is_primary_responsible||0)===1?'<span class="jv-primary">Primary</span>':'-')+'</td><td>'+esc(title(r.status))+'</td></tr>')});body.innerHTML=h.join('')}
function repeatText(s){var type=String(s.repeat_type||'none');if(type==='none')return 'One-off';var every=Math.max(1,Number(s.repeat_interval||1));var label='Every '+every+' '+(type==='daily'?'day':type==='weekly'?'week':type==='monthly'?'month':'year')+(every===1?'':'s');if(type==='weekly'&&Array.isArray(s.weekly_days)&&s.weekly_days.length)label+=' · '+s.weekly_days.map(function(d){return title(d)}).join(', ');return label}
function endsText(s){var mode=String(s.end_mode||'');if(String(s.repeat_type||'none')==='none')return 'After this visit';if(mode==='on_date')return 'Ends on '+fmtDate(s.repeat_end_date);if(mode==='after_occurrences')return 'Ends after '+Number(s.repeat_occurrences||0)+' visit'+(Number(s.repeat_occurrences||0)===1?'':'s');if(mode==='after_duration')return 'Ends after '+Number(s.end_after_value||0)+' '+title(s.end_after_unit||'');return '-'}
function scheduleTeam(s,map){var ids=Array.isArray(s.assignee_ids)?s.assignee_ids:[];if(!ids.length)return '<span class="jv-team-chip"><i class="bi bi-people"></i>Default job team</span>';return ids.map(function(id){var u=map[Number(id)]||{};return '<span class="jv-team-chip"><i class="bi bi-person-check"></i>'+esc(u.name||('Employee #'+id))+'</span>'}).join('')}
function renderSchedules(rows,meta){var box=el('schedulePlans');text('scheduleCount',rows.length);if(!rows.length){box.innerHTML='<div class="jv-empty">No expanded schedule plans found.</div>';return}var map=userMap(meta),h=[];rows.forEach(function(s,i){var badge=String(s.repeat_type||'none')==='none'?'One-off':title(s.repeat_type);h.push('<article class="jv-schedule-plan"><div class="jv-schedule-plan-head"><div class="jv-schedule-plan-title"><span>'+(i+1)+'</span>Schedule '+(i+1)+'</div><span class="jv-repeat-badge">'+esc(badge)+'</span></div><div class="jv-schedule-plan-grid"><div class="jv-mini"><label>Start</label><strong>'+esc(schedule(s.start_date,s.start_time))+'</strong></div><div class="jv-mini"><label>End</label><strong>'+esc(schedule(s.end_date,s.end_time))+'</strong></div><div class="jv-mini"><label>Repeats</label><strong>'+esc(repeatText(s))+'</strong></div><div class="jv-mini"><label>Ends</label><strong>'+esc(endsText(s))+'</strong></div><div class="jv-mini full"><label>Team Members</label><div class="jv-team-chips">'+scheduleTeam(s,map)+'</div></div><div class="jv-mini full"><label>Visit Instructions</label><strong>'+esc(s.instructions||'No visit-specific instructions.')+'</strong></div></div></article>')});box.innerHTML=h.join('')}
function renderBilling(b,c){b=b||{};var type=String(b.billing_type||'');text('billingType',type==='visit_based'?'Visit based':type==='fixed_price'?'Fixed price':'-');var f=String(b.invoice_frequency||'');var fl=f==='monthly_last_day'?'Monthly - last day':f==='after_each_visit'?'After each visit':f==='job_completion'?'When job is completed':'-';text('invoiceFrequency',fl);text('totalInvoices',b.total_invoices||0);text('firstInvoiceDate',fmtDate(b.first_invoice_date));text('lastInvoiceDate',fmtDate(b.last_invoice_date));text('fixedInvoiceAmount',type==='fixed_price'?money(b.fixed_invoice_amount,c):'-')}
function lineSource(r){var src=String(r.item_source||'');if(src==='product')return {label:'Product',cls:'product'};if(src==='manual')return {label:'Manual',cls:'manual'};if(Number(r.product_id||0)>0)return {label:'Product',cls:'product'};if(Number(r.product_service_id||0)>0)return {label:'Service',cls:'service'};return {label:'Manual',cls:'manual'}}
function renderLineItems(rows,j,c){rows=Array.isArray(rows)?rows:[];var body=el('lineItemRows');text('lineItemCount',rows.length);text('summaryService',rows.length+(rows.length===1?' item':' items'));if(!rows.length){body.innerHTML='<tr><td colspan="8" class="jv-empty">No items added.</td></tr>';text('itemsSubtotal',money(j.subtotal,c));text('itemsDiscount',money(j.discount_total||0,c));text('itemsTax',money(j.tax_total,c));text('itemsTotal',money(j.total,c));text('itemsCost',money(0,c));return}var h=[],cost=0;rows.forEach(function(r){var src=lineSource(r),qty=Number(r.quantity||0),uc=Number(r.unit_cost||0),up=Number(r.unit_price||0),disc=Number(r.discount_amount||0),tax=Number(r.tax_amount||0),line=Number(r.line_total||0);cost+=qty*uc;h.push('<tr><td><span class="jv-item-source '+src.cls+'">'+src.label+'</span></td><td><div class="jv-item-copy"><strong>'+esc(r.item_name||'Item')+'</strong><small>'+esc(r.description||'')+'</small></div></td><td>'+esc(qty)+'</td><td>'+esc(money(uc,c))+'</td><td>'+esc(money(up,c))+'</td><td>'+esc(money(disc,c))+'</td><td>'+esc(money(tax,c))+'</td><td><strong>'+esc(money(line,c))+'</strong></td></tr>')});body.innerHTML=h.join('');text('itemsSubtotal',money(j.subtotal,c));var dl=money(j.discount_total||0,c);if(String(j.discount_type||'')==='percentage'&&Number(j.discount_value||0)>0)dl+=' ('+Number(j.discount_value||0).toFixed(2).replace(/\.00$/,'')+'%)';text('itemsDiscount',dl);text('itemsTax',money(j.tax_total,c));text('itemsTotal',money(j.total,c));text('itemsCost',money(cost,c))}
function renderInternalNote(note){var box=el('internalNoteView');if(!box)return;var v=String(note||'').trim();if(!v){box.className='jv-note-view empty';box.textContent='No internal note added.';return}box.className='jv-note-view';box.textContent=v}
function fileSize(v){var n=Number(v||0);if(!n)return '';if(n<1024)return n+' B';if(n<1048576)return (n/1024).toFixed(1)+' KB';return (n/1048576).toFixed(1)+' MB'}
function renderAttachments(rows){var box=el('attachmentList');text('attachmentCount',rows.length);if(!rows.length){box.innerHTML='<div class="jv-empty">No files or photos attached.</div>';return}var h=[];rows.forEach(function(r){var mime=String(r.file_mime||''),isImg=mime.indexOf('image/')===0,path=String(r.file_path||'#'),preview=isImg?'<img src="'+esc(path)+'" alt="">':'<i class="bi bi-paperclip"></i>';h.push('<a class="jv-file" href="'+esc(path)+'" target="_blank" rel="noopener"><span class="jv-file-preview">'+preview+'</span><span class="jv-file-copy"><strong>'+esc(r.file_name||'Attachment')+'</strong><small>'+esc(fileSize(r.file_size)||title(r.attachment_type||'file'))+'</small></span></a>')});box.innerHTML=h.join('')}
function checklistTypeLabel(v){var s=String(v||'checkbox');var map={short_answer:'Short answer',long_answer:'Long answer',dropdown:'Dropdown',checkbox:'Checkbox',number:'Numerical answer',image:'Upload images',date:'Date picker',signature:'Signature'};return map[s]||title(s)}
function checklistAnswerText(item){var a=item&&item.answer!==undefined?item.answer:null;if(a===null||a===undefined||a==='')return '';if(Array.isArray(a))return a.join(', ');if(typeof a==='object'){var parts=[];Object.keys(a).forEach(function(k){var val=a[k];if(Array.isArray(val))val=val.join(', ');else if(val&&typeof val==='object')val=JSON.stringify(val);parts.push((isNaN(Number(k))?k+': ':'')+String(val==null?'':val))});return parts.join('\n')}return String(a)}
function checklistUserName(id){var users=(currentMeta&&Array.isArray(currentMeta.users))?currentMeta.users:[];var found=users.find(function(u){return Number(u.id)===Number(id)});return found?(found.name||[found.first_name,found.last_name].filter(Boolean).join(' ')||('Employee #'+id)):id?('Employee #'+id):''}
function renderChecklistModal(checklist){var items=checklist&&Array.isArray(checklist.items)?checklist.items:[];text('checklistModalTitle',checklist&&checklist.name?checklist.name:'Checklist');text('checklistModalSubtitle','Checklist details');text('checklistModalStatus',title(checklist&&checklist.status?checklist.status:'open'));text('checklistModalQuestions',items.length);var done=Number(checklist&&checklist.completed_count!==undefined?checklist.completed_count:items.filter(function(i){return Number(i.is_completed||0)===1}).length);text('checklistModalCompleted',done+' / '+items.length);var pct=items.length?Math.round((done/items.length)*100):0;var bar=el('checklistModalProgress');if(bar)bar.style.width=pct+'%';text('checklistModalCreated',fmtDate(checklist&&checklist.created_at?checklist.created_at:''));var body=el('checklistModalBody');if(!body)return;if(!items.length){body.innerHTML='<div class="jv-checklist-modal-empty">No checklist questions are available.</div>';return}var grouped={},order=[];items.forEach(function(item){var key=String(item.section_title||'General');if(!grouped[key]){grouped[key]=[];order.push(key)}grouped[key].push(item)});var qn=0,html=[];order.forEach(function(section){html.push('<section class="jv-checklist-section"><div class="jv-checklist-section-head">'+esc(section)+'</div>');grouped[section].forEach(function(item){qn++;var answer=checklistAnswerText(item),opts=Array.isArray(item.options)?item.options:[],completed=Number(item.is_completed||0)===1,who=item.answered_by||item.completed_by||0,when=item.answered_at||item.completed_at||'';html.push('<div class="jv-checklist-question"><div class="jv-checklist-question-no">'+qn+'</div><div><div class="jv-checklist-question-title">'+esc(item.title||('Question '+qn))+(Number(item.is_required||0)===1?'<span class="jv-checklist-required">Required</span>':'')+'<span class="jv-checklist-type">'+esc(checklistTypeLabel(item.question_type||'checkbox'))+'</span></div>'+(item.description?'<div class="jv-checklist-question-desc">'+esc(item.description)+'</div>':'')+(opts.length?'<div class="jv-checklist-options">'+opts.map(function(o){return '<span class="jv-checklist-option">'+esc(o)+'</span>'}).join('')+'</div>':'')+'</div><div class="jv-checklist-answer"><span class="jv-checklist-answer-label">Answer</span><div class="jv-checklist-answer-value'+(answer?'':' empty')+'">'+esc(answer||'Not answered')+'</div><div class="jv-checklist-completion '+(completed?'done':'')+'"><i class="bi '+(completed?'bi-check-circle-fill':'bi-circle')+'"></i>'+(completed?'Completed':'Not completed')+(who?' by '+esc(checklistUserName(who)):'')+(when?' · '+esc(fmtDate(when)):'')+'</div></div></div>')});html.push('</section>')});body.innerHTML=html.join('')}
function openChecklistModal(id){var checklist=currentChecklists.find(function(c){return Number(c.id)===Number(id)});if(!checklist)return;renderChecklistModal(checklist);var modal=el('checklistModal');if(!modal)return;modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.classList.add('jv-modal-open')}
function closeChecklistModal(){var modal=el('checklistModal');if(!modal)return;modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('jv-modal-open')}
function renderLocation(location,j,meta){
    location=location&&typeof location==='object'?location:null;
    if(!location&&j&&Number(j.location_id||0)>0&&meta&&Array.isArray(meta.locations)){
        var fallback=meta.locations.find(function(x){return Number(x.id)===Number(j.location_id)});
        if(fallback)location=fallback;
    }
    if(!location){
        text('locationName','Primary / no saved location');
        text('locationAddress','-');
        text('locationCityState','-');
        text('locationCountry','-');
        return;
    }
    text('locationName',location.name||('Location #'+Number(location.id||0)),'-');
    var address=[location.address_line1||'',location.address_line2||''].filter(function(v){return String(v).trim()!==''}).join(', ');
    var cityState=[location.city||'',location.state||'',location.postal_code||''].filter(function(v){return String(v).trim()!==''}).join(', ');
    text('locationAddress',address||'-');
    text('locationCityState',cityState||'-');
    text('locationCountry',location.country||'-');
}
function renderCustomFields(rows){
    rows=Array.isArray(rows)?rows:[];
    var card=el('customFieldsCard'),box=el('customFieldsList');
    text('customFieldCount',rows.length);
    if(!card||!box)return;
    if(!rows.length){card.style.display='none';box.innerHTML='';return}
    card.style.display='block';
    box.innerHTML=rows.map(function(row){
        var label=String(row.field_label||row.label||'Custom Field').trim()||'Custom Field';
        var value=String(row.field_value==null?(row.value==null?'':row.value):row.field_value);
        return '<div class="jv-custom-field"><span class="label">'+esc(label)+'</span><span class="value">'+esc(value||'-')+'</span></div>';
    }).join('');
}
function renderChecklists(rows,meta){var box=el('checklistList');rows=Array.isArray(rows)?rows:[];currentMeta=meta||{};if(rows.length&&typeof rows[0]!=='object'){var templates=(meta&&Array.isArray(meta.checklist_templates))?meta.checklist_templates:[],tm={};templates.forEach(function(t){tm[Number(t.id)]=t});rows=rows.map(function(id){var t=tm[Number(id)]||{};return {id:Number(id),checklist_template_id:Number(id),name:t.name||('Checklist #'+id),status:'open',item_count:Number(t.item_count||0),completed_count:0,items:[]}})}currentChecklists=rows;text('checklistCount',rows.length);if(!rows.length){box.innerHTML='<div class="jv-empty">No checklist attached.</div>';return}box.innerHTML=rows.map(function(c){var count=Array.isArray(c.items)?c.items.length:Number(c.item_count||0),done=Number(c.completed_count||0);return '<button type="button" class="jv-checklist" data-checklist-id="'+Number(c.id||0)+'"><i class="bi bi-list-check"></i><span>'+esc(c.name||('Checklist #'+c.id))+'<small>'+esc(count+' item'+(count===1?'':'s')+(count?(' · '+done+' completed'):'') )+'</small></span><i class="bi bi-chevron-right jv-checklist-arrow"></i></button>'}).join('')}
function render(data){var j=data.job||{},a=Array.isArray(data.assignments)?data.assignments:[],schedules=Array.isArray(data.schedules)?data.schedules:[],attachments=Array.isArray(data.attachments)?data.attachments:[],lineItems=Array.isArray(data.line_items)?data.line_items:[],checklists=Array.isArray(data.checklists)?data.checklists:(Array.isArray(data.checklist_template_ids)?data.checklist_template_ids:[]),customFields=Array.isArray(data.custom_fields)?data.custom_fields:[],location=data.location&&typeof data.location==='object'?data.location:null,meta=data.meta||{},c=data.currency||meta.currency||{},billing=data.billing||{},internalNote=data.internal_note||'';currentJob=j;var hasQuote=Number(j.quote_id||0)>0&&String(j.quote_no||'').trim()!=='';var sourceText=hasQuote?('Quotation '+j.quote_no):'Direct Job';text('pageTitle',j.job_no?'Job '+j.job_no:'Job Card');var badge=el('pageStatus');badge.className='jv-badge '+(j.status||'scheduled');badge.textContent=title(j.status);text('pageSubtitle',(j.title||'Job')+' · '+sourceText+' · '+title(j.job_type||'one_off'));text('summaryCustomer',j.client_name);text('summarySource',sourceText);text('summaryTotal',money(j.total,c));text('jobNo',j.job_no);text('statusText',title(j.status));text('jobTitle',j.title);text('jobType',title(j.job_type||'one_off'));text('priority',title(j.priority));text('assignmentMode',title(j.assignment_mode));text('completionMode',title(j.assignment_completion_mode));text('branchName',j.branch_name);text('workflowName',j.workflow_name,'No workflow');text('description',j.description,'-');text('requestNoMain',j.request_no,'-');text('scheduleStart',schedule(j.start_date,j.start_time));text('scheduleEnd',schedule(j.end_date,j.end_time));text('clientName',j.client_name);text('clientEmail',j.client_email);text('clientPhone',j.client_phone);text('sourceTypeText',hasQuote?'Quotation':'Direct Job');text('quoteNo',j.quote_no,'-');text('serviceName',j.service_name,'-');text('requestNo',j.request_no,'-');var sourceBadge=el('jobSourceBadge');if(sourceBadge){sourceBadge.className='jv-source-pill'+(hasQuote?' quote':'');sourceBadge.querySelector('i').className=hasQuote?'bi bi-file-earmark-check':'bi bi-briefcase'}text('jobSourceBadgeText',hasQuote?'Quotation Job':'Direct Job');var qr=el('quoteSourceRow');if(qr)qr.style.display=hasQuote?'flex':'none';renderLocation(location,j,meta);renderCustomFields(customFields);renderLineItems(lineItems,j,c);renderAssignments(a);renderSchedules(schedules,meta);renderBilling(billing,c);renderAttachments(attachments);renderInternalNote(internalNote);renderChecklists(checklists,meta);var edit=el('editButton');edit.href='job-form.php?job_id='+Number(j.id||jobId);var resend=el('resendEmailButton');if(Number(j.can_resend_email||0)===1&&String(j.status||'').toLowerCase()==='scheduled'){resend.style.display='inline-flex';text('emailNote','Email can be resent to the customer and assigned employees while this job is Scheduled.')}else{resend.style.display='none';text('emailNote','Resend Email is available while the job is Scheduled.')}el('loadingState').style.display='none';el('errorState').style.display='none';el('jobContent').style.display='block'}
function load(){return request('get').then(render).catch(function(e){console.error('FieldPlx job view error:',e);el('loadingState').style.display='none';el('jobContent').style.display='none';el('errorState').style.display='flex';text('errorMessage',e.message||'Unable to load job.');notify('error',e.message)})}
function resend(){var b=el('resendEmailButton');if(!b||b.disabled)return;b.disabled=true;b.classList.add('loading');text('resendEmailText','Sending...');request('resend_email').then(function(d){var n=d.notifications||{};var msg=d.message||'Job email resent successfully.';if(Number(n.email_failed||0)>0)msg+=' '+Number(n.email_failed)+' email(s) failed.';if(Number(n.email_skipped||0)>0)msg+=' '+Number(n.email_skipped)+' email(s) skipped.';notify('success',msg);text('emailNote','Last resend completed: '+Number(n.email_sent||0)+' sent, '+Number(n.email_failed||0)+' failed, '+Number(n.email_skipped||0)+' skipped.');return request('get').then(render)}).catch(function(e){console.error('FieldPlx resend email error:',e);notify('error',e.message||'Unable to resend email.')}).finally(function(){b.disabled=false;b.classList.remove('loading');text('resendEmailText','Resend Email')})}
function init(){if(jobId<=0){el('loadingState').style.display='none';el('errorState').style.display='flex';text('errorMessage','Invalid job ID.');return}var b=el('resendEmailButton');if(b)b.addEventListener('click',resend);var list=el('checklistList');if(list)list.addEventListener('click',function(e){var btn=e.target.closest('[data-checklist-id]');if(btn)openChecklistModal(Number(btn.getAttribute('data-checklist-id')||0))});var close=el('checklistModalClose');if(close)close.addEventListener('click',closeChecklistModal);var modal=el('checklistModal');if(modal)modal.addEventListener('click',function(e){if(e.target===modal)closeChecklistModal()});document.addEventListener('keydown',function(e){if(e.key==='Escape')closeChecklistModal()});load()}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
</script>
</body>
</html>
