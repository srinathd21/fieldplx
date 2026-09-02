<?php
/* FieldPlx Invoices - Version 1.1.0 - 2026-08-28 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle='Invoices';
$activePage='invoices';

if(session_status()===PHP_SESSION_NONE){
    session_start();
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

        :root{
            --fieldplx-sidebar-width:250px;
            --fieldplx-sidebar-collapsed-width:78px;
            --fd-navy:#001131;
            --fd-navy-light:#071f49;
            --fd-blue:#123d70;
            --fd-green:#74b824;
            --fd-green-dark:#5d971b;
            --fd-green-soft:#f0f8e5;
            --fd-red:#e45b66;
            --fd-orange:#a97814;
            --fd-bg:#f6f8fb;
            --fd-text:#0b1933;
            --fd-muted:#6f7b90;
            --fd-border:#e5eaf1;
        }
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;overflow-x:hidden;background:var(--fd-bg)!important;color:var(--fd-text);font-family:Arial,Helvetica,sans-serif!important;font-size:14px}
        a,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}
.fd-inv-page{width:100%;max-width:1600px;margin:auto;padding:25px 27px 36px}

        .fd-inv-head{margin-bottom:17px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
        .fd-inv-title{margin:0;color:var(--fd-text);font-size:21px;line-height:1.2;font-weight:700}
        .fd-inv-sub{max-width:760px;margin:7px 0 0;color:var(--fd-muted);font-size:10.5px;line-height:1.55}
        .fd-inv-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .fd-inv-btn{min-height:40px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;color:#43546c;background:#fff;font-size:10px;font-weight:700;cursor:pointer}
        .fd-inv-btn:hover{border-color:#cbd5e1;color:var(--fd-navy)}
        .fd-inv-btn.primary{border-color:var(--fd-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);box-shadow:0 7px 16px rgba(104,170,29,.16)}
        .fd-inv-btn:disabled{opacity:.55;cursor:not-allowed}

        .fd-inv-stat{min-height:112px;padding:18px 20px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}
        .fd-inv-stat-row{min-height:72px;display:flex;align-items:center;gap:18px}
        .fd-inv-stat-icon{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;border-radius:16px;color:#fff;background:#123f73;font-size:24px}
        .fd-inv-stat-icon.green{background:#6aa91f}
        .fd-inv-stat-icon.orange{background:#a97814}
        .fd-inv-stat-icon.red{background:#b94c54}
        .fd-inv-stat-label{display:block;margin-bottom:8px;color:#506784;font-size:12px}
        .fd-inv-stat-value{display:block;color:#020b16;font-size:24px;line-height:1;font-weight:700}

        .fd-inv-card{margin-top:16px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035);overflow:hidden}
        .fd-inv-toolbar{padding:14px 15px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--fd-border)}
        .fd-inv-search{width:270px;min-width:210px;position:relative}
        .fd-inv-search i{position:absolute;top:50%;left:12px;transform:translateY(-50%);color:#94a1b2;font-size:13px}
        .fd-inv-search input,.fd-inv-filter{height:39px;border:1px solid #dfe5ec;border-radius:8px;outline:0;background:#fff;color:#31445e;font-size:9.5px}
        .fd-inv-search input{width:100%;padding:0 11px 0 34px}
        .fd-inv-filter{min-width:128px;padding:0 9px}
        .fd-inv-filter.date{min-width:132px}
        .fd-inv-search input:focus,.fd-inv-filter:focus{border-color:#b8d88d;box-shadow:0 0 0 3px rgba(116,184,36,.1)}
        .fd-inv-spacer{flex:1}
        .fd-inv-clear{height:39px;padding:0 11px;border:1px solid #dfe5ec;border-radius:8px;background:#fff;color:#66758b;font-size:9px;font-weight:700;cursor:pointer}
        .fd-inv-clear:hover{color:var(--fd-navy);background:#f8fafc}

        .fd-inv-table-wrap{overflow:auto}
        .fd-inv-table{width:100%;min-width:1370px;border-collapse:collapse}
        .fd-inv-table th{padding:11px 10px;text-align:left;color:#718096;background:#f8fafc;border-bottom:1px solid var(--fd-border);font-size:8.3px;font-weight:700;text-transform:uppercase;letter-spacing:.15px;white-space:nowrap}
        .fd-inv-table td{padding:12px 10px;border-bottom:1px solid #edf1f4;color:#344760;font-size:9.4px;vertical-align:middle}
        .fd-inv-table tbody tr:hover{background:#fbfcfd}
        .fd-inv-table .num{text-align:right}
        .fd-inv-table .center{text-align:center}
        .fd-inv-main{display:block;color:var(--fd-text);font-size:10.3px;font-weight:700}
        .fd-inv-subtext{display:block;margin-top:3px;color:#8793a5;font-size:8.4px;line-height:1.4}
        .fd-inv-link{color:#174b82!important;font-weight:700}
        .fd-inv-link:hover{color:var(--fd-green-dark)!important}
        .fd-inv-money{color:var(--fd-text);font-weight:700;white-space:nowrap}
        .fd-inv-money.paid{color:var(--fd-green-dark)}
        .fd-inv-money.balance{color:#a06b0c}

        .fd-inv-badge{display:inline-flex;align-items:center;justify-content:center;min-height:23px;padding:4px 8px;border-radius:999px;color:#52647b;background:#edf2f7;font-size:8px;font-weight:700;text-transform:capitalize;white-space:nowrap}
        .fd-inv-badge.draft{color:#355a85;background:#edf4fb}
        .fd-inv-badge.sent,.fd-inv-badge.viewed{color:#355a85;background:#eaf2fb}
        .fd-inv-badge.partially_paid,.fd-inv-badge.partial{color:#96670d;background:#fff4d8}
        .fd-inv-badge.paid{color:#4f8618;background:#eaf6da}
        .fd-inv-badge.overdue{color:#b9444d;background:#fff0f1}
        .fd-inv-badge.cancelled,.fd-inv-badge.archived,.fd-inv-badge.written_off{color:#6b7280;background:#f1f3f5}
        .fd-inv-badge.unpaid,.fd-inv-badge.outstanding{color:#865c0c;background:#fff5dd}

        .fd-inv-actions-cell{display:flex;align-items:center;justify-content:center;gap:6px}
        .fd-inv-icon{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #dfe5ec;border-radius:8px;color:#456078;background:#fff;font-size:12px}
        .fd-inv-icon:hover{border-color:#b8d88d;color:var(--fd-green-dark);background:var(--fd-green-soft)}
        .fd-inv-icon.collect{color:#fff;border-color:var(--fd-green);background:linear-gradient(90deg,#7fc92d,#68aa1d)}
        .fd-inv-icon.collect:hover{color:#fff;border-color:var(--fd-green-dark)}

        .fd-inv-empty{padding:42px 20px!important;text-align:center!important;color:var(--fd-muted)!important;font-size:10px!important}
        .fd-inv-empty i{display:block;margin-bottom:8px;font-size:25px;color:#b4bfcc}

        .fd-inv-footer{min-height:58px;padding:11px 15px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-top:1px solid var(--fd-border)}
        .fd-inv-count{color:#7a889b;font-size:9px}
        .fd-inv-pagination{display:flex;align-items:center;gap:6px}
        .fd-inv-page-btn{height:32px;min-width:32px;padding:0 9px;border:1px solid #dfe5ec;border-radius:7px;background:#fff;color:#52647a;font-size:9px;font-weight:700;cursor:pointer}
        .fd-inv-page-btn:disabled{opacity:.45;cursor:not-allowed}
        .fd-inv-page-label{padding:0 5px;color:#64748b;font-size:9px}

        .fd-inv-toast{position:fixed;top:82px;right:18px;z-index:14000;width:min(380px,calc(100vw - 36px));padding:12px 14px;border-radius:9px;color:#fff;background:#123d70;box-shadow:0 12px 30px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s;font-size:10px;font-weight:700}
        .fd-inv-toast.show{opacity:1;transform:translateY(0)}
        .fd-inv-toast.error{background:#e45b66}
        .fd-inv-toast.success{background:#5d971b}

        @media(max-width:1199.98px){.fd-inv-search{width:100%;flex:1 1 100%}.fd-inv-spacer{display:none}}
        
        @media(max-width:767.98px){.fd-inv-page{padding:17px 13px 28px}.fd-inv-head{flex-direction:column}.fd-inv-actions{width:100%}.fd-inv-actions .fd-inv-btn{flex:1}.fd-inv-filter{flex:1 1 calc(50% - 8px);min-width:0}.fd-inv-footer{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="fd-inv-page">

                <section class="fd-inv-head">
                    <div>
                        <h1 class="fd-inv-title">Invoices</h1>
                        <p class="fd-inv-sub">View completed-job invoices, customer billing, collections and outstanding balances. Use the filters to quickly find paid, unpaid, partially paid or overdue invoices.</p>
                    </div>
                    <div class="fd-inv-actions">
                        <button type="button" class="fd-inv-btn" id="refreshButton"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    </div>
                </section>

                <section class="row g-3">
                    <div class="col-xl-3 col-6"><article class="fd-inv-stat"><div class="fd-inv-stat-row"><span class="fd-inv-stat-icon"><i class="bi bi-receipt"></i></span><div><span class="fd-inv-stat-label">Total Invoices</span><strong class="fd-inv-stat-value" id="statInvoices">0</strong></div></div></article></div>
                    <div class="col-xl-3 col-6"><article class="fd-inv-stat"><div class="fd-inv-stat-row"><span class="fd-inv-stat-icon"><i class="bi bi-currency-dollar"></i></span><div><span class="fd-inv-stat-label">Total Billed</span><strong class="fd-inv-stat-value" id="statBilled">0.00</strong></div></div></article></div>
                    <div class="col-xl-3 col-6"><article class="fd-inv-stat"><div class="fd-inv-stat-row"><span class="fd-inv-stat-icon green"><i class="bi bi-check2-circle"></i></span><div><span class="fd-inv-stat-label">Collected</span><strong class="fd-inv-stat-value" id="statCollected">0.00</strong></div></div></article></div>
                    <div class="col-xl-3 col-6"><article class="fd-inv-stat"><div class="fd-inv-stat-row"><span class="fd-inv-stat-icon orange"><i class="bi bi-hourglass-split"></i></span><div><span class="fd-inv-stat-label">Outstanding</span><strong class="fd-inv-stat-value" id="statOutstanding">0.00</strong><span class="fd-inv-subtext" id="statOverdue">0 overdue</span></div></div></article></div>
                </section>

                <section class="fd-inv-card">
                    <div class="fd-inv-toolbar">
                        <div class="fd-inv-search"><i class="bi bi-search"></i><input type="search" id="search" placeholder="Invoice, customer, job, quote, phone..."></div>

                        <select class="fd-inv-filter" id="statusFilter">
                            <option value="">All Invoice Status</option>
                            <option value="draft">Draft</option>
                            <option value="sent">Sent</option>
                            <option value="viewed">Viewed</option>
                            <option value="partially_paid">Partially Paid</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                            <option value="written_off">Written Off</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="archived">Archived</option>
                        </select>

                        <select class="fd-inv-filter" id="paymentFilter">
                            <option value="">All Payment Status</option>
                            <option value="outstanding">Outstanding</option>
                            <option value="unpaid">Unpaid</option>
                            <option value="partial">Partially Paid</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                        </select>

                        <select class="fd-inv-filter" id="branchFilter">
                            <option value="">All Branches</option>
                        </select>

                        <select class="fd-inv-filter" id="dateType">
                            <option value="issue_date">Issue Date</option>
                            <option value="due_date">Due Date</option>
                        </select>

                        <input class="fd-inv-filter date" type="date" id="fromDate" title="From date">
                        <input class="fd-inv-filter date" type="date" id="toDate" title="To date">

                        <button type="button" class="fd-inv-clear" id="clearButton"><i class="bi bi-x-circle"></i> Clear</button>

                        <span class="fd-inv-spacer"></span>

                        <select class="fd-inv-filter" id="perPage" style="min-width:86px">
                            <option value="10">10 rows</option>
                            <option value="25">25 rows</option>
                            <option value="50">50 rows</option>
                        </select>
                    </div>

                    <div class="fd-inv-table-wrap">
                        <table class="fd-inv-table">
                            <thead>
                                <tr>
                                    <th class="center">S.No</th>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Job / Quotation</th>
                                    <th>Issue / Due</th>
                                    <th>Branch</th>
                                    <th class="num">Total</th>
                                    <th class="num">Collected</th>
                                    <th class="num">Balance</th>
                                    <th>Invoice Status</th>
                                    <th>Payment Status</th>
                                    <th class="center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="invoiceRows">
                                <tr><td colspan="12" class="fd-inv-empty"><i class="bi bi-hourglass-split"></i>Loading invoices...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="fd-inv-footer">
                        <div class="fd-inv-count" id="countText">Showing 0 invoices</div>
                        <div class="fd-inv-pagination">
                            <button type="button" class="fd-inv-page-btn" id="prevButton"><i class="bi bi-chevron-left"></i></button>
                            <span class="fd-inv-page-label" id="pageText">Page 1 of 1</span>
                            <button type="button" class="fd-inv-page-btn" id="nextButton"><i class="bi bi-chevron-right"></i></button>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </main>
</div>

<div class="fd-inv-toast" id="toast">Notification</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    'use strict';

    var csrfToken=<?= json_encode($invoiceCsrfToken) ?>;
    var state={
        page:1,
        perPage:10,
        search:'',
        status:'',
        paymentStatus:'',
        branchId:'',
        dateType:'issue_date',
        fromDate:'',
        toDate:'',
        pagination:{page:1,pages:1,total:0,from:0,to:0},
        currency:{},
        branches:[]
    };
    var searchTimer=null;
    var toastTimer=null;

    function el(id){return document.getElementById(id)}
    function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
    function title(v){return String(v||'-').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
    function notify(type,message){var t=el('toast');if(toastTimer)clearTimeout(toastTimer);t.className='fd-inv-toast '+(type||'')+' show';t.textContent=message||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},3200)}
    function parse(response){return response.text().then(function(raw){var d,text=String(raw||'').trim();try{d=text?JSON.parse(text):{}}catch(e){throw new Error(text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!response.ok||!d.success)throw new Error(d.message||'Request failed.');return d})}
    function request(fd){fd.append('csrf_token',csrfToken);return fetch('api/invoices.php',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parse)}
    function money(v){var c=state.currency||{},places=parseInt(c.decimal_places,10);if(isNaN(places))places=2;var n=Number(v||0).toFixed(places),sym=c.symbol||'';return c.symbol_position==='after'?n+(sym?' '+sym:''):(sym||'')+n}
    function fmtDate(v){if(!v)return '-';var d=new Date(String(v).substring(0,10)+'T00:00:00');return isNaN(d.getTime())?esc(v):d.toLocaleDateString(undefined,{day:'2-digit',month:'short',year:'numeric'})}
    function badge(value){var v=String(value||'').toLowerCase();return '<span class="fd-inv-badge '+esc(v)+'">'+esc(title(v))+'</span>'}

    function setBranches(rows){
        state.branches=rows||[];
        var selected=String(el('branchFilter').value||state.branchId||'');
        var html='<option value="">All Branches</option>';
        state.branches.forEach(function(b){
            html+='<option value="'+Number(b.id)+'">'+esc(b.name||'Branch')+(b.branch_code?' · '+esc(b.branch_code):'')+'</option>';
        });
        el('branchFilter').innerHTML=html;
        el('branchFilter').value=selected;
    }

    function renderSummary(summary){
        summary=summary||{};
        el('statInvoices').textContent=Number(summary.total_invoices||0).toLocaleString();
        el('statBilled').textContent=money(summary.total_billed);
        el('statCollected').textContent=money(summary.total_collected);
        el('statOutstanding').textContent=money(summary.total_outstanding);
        el('statOverdue').textContent=Number(summary.overdue_invoices||0)+' overdue';
    }

    function renderRows(rows){
        var body=el('invoiceRows');
        if(!rows||!rows.length){
            body.innerHTML='<tr><td colspan="12" class="fd-inv-empty"><i class="bi bi-receipt"></i>No invoices found for the selected filters.</td></tr>';
            return;
        }

        var start=(Number(state.pagination.from||1)-1);
        var html='';

        rows.forEach(function(r,index){
            var contact=[r.client_email,r.client_phone].filter(Boolean).join(' • ');
            var jobQuote=[];
            if(r.job_no)jobQuote.push('<a class="fd-inv-link" href="job-view?job_id='+Number(r.job_id)+'">'+esc(r.job_no)+'</a>');
            if(r.quote_no)jobQuote.push('<span class="fd-inv-subtext">Quote: '+esc(r.quote_no)+'</span>');

            var canCollect=Number(r.balance_due||0)>0.005&&['cancelled','archived','written_off'].indexOf(String(r.status||''))===-1;

            html+='<tr>'
                +'<td class="center">'+(start+index+1)+'</td>'
                +'<td><a class="fd-inv-main fd-inv-link" href="invoice-view?invoice_id='+Number(r.id)+'">'+esc(r.invoice_no||'-')+'</a><span class="fd-inv-subtext">Created '+esc(fmtDate(r.created_at))+'</span></td>'
                +'<td><span class="fd-inv-main">'+esc(r.client_name||'-')+'</span>'+(r.client_company?'<span class="fd-inv-subtext">'+esc(r.client_company)+'</span>':'')+(contact?'<span class="fd-inv-subtext">'+esc(contact)+'</span>':'')+'</td>'
                +'<td>'+(jobQuote.length?jobQuote.join(''):'-')+(r.job_title?'<span class="fd-inv-subtext">'+esc(r.job_title)+'</span>':'')+'</td>'
                +'<td><span class="fd-inv-main">'+esc(fmtDate(r.issue_date))+'</span><span class="fd-inv-subtext">Due: '+esc(fmtDate(r.due_date))+'</span></td>'
                +'<td><span class="fd-inv-main">'+esc(r.branch_name||'Head Office')+'</span></td>'
                +'<td class="num"><span class="fd-inv-money">'+esc(money(r.total))+'</span></td>'
                +'<td class="num"><span class="fd-inv-money paid">'+esc(money(r.amount_paid))+'</span></td>'
                +'<td class="num"><span class="fd-inv-money balance">'+esc(money(r.balance_due))+'</span></td>'
                +'<td>'+badge(r.status)+'</td>'
                +'<td>'+badge(r.payment_state)+'</td>'
                +'<td class="center"><div class="fd-inv-actions-cell">'
                    +'<a class="fd-inv-icon" href="invoice-view?invoice_id='+Number(r.id)+'" title="View Invoice"><i class="bi bi-eye"></i></a>'
                    +'<a class="fd-inv-icon" href="invoice-print?invoice_id='+Number(r.id)+'" target="_blank" rel="noopener" title="Print Invoice"><i class="bi bi-printer"></i></a>'
                    +(canCollect?'<a class="fd-inv-icon collect" href="invoice-view?invoice_id='+Number(r.id)+'&collect=1" title="Collect Payment"><i class="bi bi-cash-stack"></i></a>':'')
                +'</div></td>'
            +'</tr>';
        });

        body.innerHTML=html;
    }

    function renderPagination(p){
        state.pagination=p||state.pagination;
        el('countText').textContent=state.pagination.total>0
            ?'Showing '+state.pagination.from+'-'+state.pagination.to+' of '+state.pagination.total+' invoices'
            :'Showing 0 invoices';
        el('pageText').textContent='Page '+state.pagination.page+' of '+state.pagination.pages;
        el('prevButton').disabled=state.pagination.page<=1;
        el('nextButton').disabled=state.pagination.page>=state.pagination.pages;
    }

    function load(){
        var fd=new FormData();
        fd.append('action','list');
        fd.append('page',state.page);
        fd.append('per_page',state.perPage);
        fd.append('search',state.search);
        fd.append('status',state.status);
        fd.append('payment_status',state.paymentStatus);
        fd.append('branch_id',state.branchId);
        fd.append('date_type',state.dateType);
        fd.append('from_date',state.fromDate);
        fd.append('to_date',state.toDate);

        request(fd).then(function(data){
            state.currency=data.currency||state.currency;
            setBranches(data.branches||[]);
            renderSummary(data.summary||{});
            renderPagination(data.pagination||{});
            renderRows(data.rows||[]);
        }).catch(function(error){
            notify('error',error.message);
            el('invoiceRows').innerHTML='<tr><td colspan="12" class="fd-inv-empty"><i class="bi bi-exclamation-circle"></i>'+esc(error.message)+'</td></tr>';
        });
    }

    function resetPageAndLoad(){state.page=1;load()}

    el('search').addEventListener('input',function(){
        state.search=this.value.trim();
        if(searchTimer)clearTimeout(searchTimer);
        searchTimer=setTimeout(resetPageAndLoad,280);
    });

    el('statusFilter').addEventListener('change',function(){state.status=this.value;resetPageAndLoad()});
    el('paymentFilter').addEventListener('change',function(){state.paymentStatus=this.value;resetPageAndLoad()});
    el('branchFilter').addEventListener('change',function(){state.branchId=this.value;resetPageAndLoad()});
    el('dateType').addEventListener('change',function(){state.dateType=this.value;resetPageAndLoad()});
    el('fromDate').addEventListener('change',function(){state.fromDate=this.value;resetPageAndLoad()});
    el('toDate').addEventListener('change',function(){state.toDate=this.value;resetPageAndLoad()});
    el('perPage').addEventListener('change',function(){state.perPage=Number(this.value||10);resetPageAndLoad()});

    el('prevButton').addEventListener('click',function(){if(state.page>1){state.page--;load()}});
    el('nextButton').addEventListener('click',function(){if(state.page<state.pagination.pages){state.page++;load()}});
    el('refreshButton').addEventListener('click',load);

    el('clearButton').addEventListener('click',function(){
        state.page=1;
        state.search='';
        state.status='';
        state.paymentStatus='';
        state.branchId='';
        state.dateType='issue_date';
        state.fromDate='';
        state.toDate='';
        el('search').value='';
        el('statusFilter').value='';
        el('paymentFilter').value='';
        el('branchFilter').value='';
        el('dateType').value='issue_date';
        el('fromDate').value='';
        el('toDate').value='';
        load();
    });

    load();
})();
</script>
</body>
</html>
