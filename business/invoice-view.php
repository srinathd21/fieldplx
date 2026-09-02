<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle='Invoice';
$activePage='invoices';

if(session_status()===PHP_SESSION_NONE){
    session_start();
}

if(empty($_SESSION['invoices_csrf_token'])){
    $_SESSION['invoices_csrf_token']=bin2hex(random_bytes(32));
}

$invoiceCsrfToken=(string)$_SESSION['invoices_csrf_token'];
$invoiceId=isset($_GET['invoice_id'])?(int)$_GET['invoice_id']:0;
$jobId=isset($_GET['job_id'])?(int)$_GET['job_id']:0;
$openCollect=isset($_GET['collect'])&&$_GET['collect']==='1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Invoice - FieldPlx</title>
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
            --fd-bg:#f6f8fb;
            --fd-text:#0b1933;
            --fd-muted:#6f7b90;
            --fd-border:#e5eaf1;
        }
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;overflow-x:hidden;background:var(--fd-bg)!important;color:var(--fd-text);font-family:Arial,Helvetica,sans-serif!important;font-size:14px}
        a,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}
.iv-page{width:100%;max-width:1600px;margin:auto;padding:25px 27px 36px}
        .iv-head{margin-bottom:17px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
        .iv-title-row{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
        .iv-title{margin:0;color:var(--fd-text);font-size:21px;line-height:1.2;font-weight:700}
        .iv-sub{margin:7px 0 0;color:var(--fd-muted);font-size:10.5px;line-height:1.55}
        .iv-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .iv-btn{min-height:40px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;color:#43546c;background:#fff;font-size:10px;font-weight:700;cursor:pointer}
        .iv-btn:hover{border-color:#cbd5e1;color:var(--fd-navy)}
        .iv-btn.primary{border-color:var(--fd-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);box-shadow:0 7px 16px rgba(104,170,29,.16)}
        .iv-btn:disabled{opacity:.55;cursor:not-allowed}
        .iv-badge{padding:5px 9px;border-radius:999px;color:#41536c;background:#edf2f7;font-size:9px;font-weight:700;text-transform:capitalize}
        .iv-badge.paid{color:#4f8618;background:#eaf6da}.iv-badge.partially_paid{color:#9b6c10;background:#fff4d8}.iv-badge.overdue{color:#b9444d;background:#fff0f1}.iv-badge.draft{color:#355a85;background:#edf4fb}
        .iv-summary{margin-bottom:16px}
        .iv-stat{min-height:112px;padding:18px 20px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}
        .iv-stat-row{min-height:72px;display:flex;align-items:center;gap:18px}.iv-stat-icon{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;border-radius:16px;color:#fff;background:#123f73;font-size:24px}
        .iv-stat-icon.green{background:#6aa91f}.iv-stat-icon.orange{background:#a97814}.iv-stat-icon.red{background:#b94c54}
        .iv-stat-label{display:block;margin-bottom:8px;color:#506784;font-size:12px}.iv-stat-value{display:block;color:#020b16;font-size:25px;line-height:1;font-weight:700}
        .iv-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(300px,.75fr);gap:16px;align-items:start}
        .iv-card{margin-bottom:16px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035);overflow:hidden}
        .iv-card-head{min-height:62px;padding:15px 17px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--fd-border);background:#fff}
        .iv-card-head h2{margin:0;font-size:13px;font-weight:700}.iv-card-head small{display:block;margin-top:4px;color:var(--fd-muted);font-size:9px}
        .iv-card-body{padding:16px 17px}
        .iv-company{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding-bottom:17px;margin-bottom:16px;border-bottom:1px solid var(--fd-border)}
        .iv-company-left{display:flex;align-items:flex-start;gap:13px;min-width:0}.iv-logo{width:62px;height:62px;display:grid;place-items:center;overflow:hidden;border:1px solid #e1e7ee;border-radius:11px;background:#f8fafc;color:var(--fd-green-dark);font-size:24px;font-weight:700}.iv-logo img{width:100%;height:100%;object-fit:contain}.iv-company h3{margin:1px 0 5px;font-size:16px}.iv-company p{margin:2px 0;color:var(--fd-muted);font-size:10px;line-height:1.45}
        .iv-number{text-align:right}.iv-number small{display:block;margin-bottom:5px;color:#8793a5;font-size:9px;text-transform:uppercase}.iv-number strong{display:block;font-size:20px;color:var(--fd-navy)}
        .iv-info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.iv-info{min-height:72px;padding:11px 12px;border:1px solid #e8edf2;border-radius:9px;background:#fbfcfd}.iv-info span,.iv-info strong{display:block}.iv-info span{margin-bottom:6px;color:#8793a5;font-size:8.5px;font-weight:700;text-transform:uppercase}.iv-info strong{font-size:10.5px;line-height:1.45;overflow-wrap:anywhere}
        .iv-table-wrap{overflow:auto}.iv-table{width:100%;min-width:850px;border-collapse:collapse}.iv-table th{padding:10px 11px;text-align:left;color:#6f7b90;background:#f8fafc;border-bottom:1px solid var(--fd-border);font-size:8.5px;text-transform:uppercase;white-space:nowrap}.iv-table td{padding:11px;border-bottom:1px solid #edf1f4;color:#33445f;font-size:9.5px;vertical-align:top}.iv-table td.num,.iv-table th.num{text-align:right}.iv-item strong{display:block;color:var(--fd-text);font-size:10px}.iv-item small{display:block;margin-top:3px;color:var(--fd-muted);font-size:8.5px}
        .iv-totals{margin-left:auto;width:min(380px,100%);padding:13px 17px 17px}.iv-total-row{padding:8px 0;display:flex;justify-content:space-between;gap:20px;color:#53637a;font-size:10px}.iv-total-row.grand{margin-top:4px;padding-top:12px;border-top:1px solid var(--fd-border);color:var(--fd-text);font-size:13px;font-weight:700}.iv-total-row.balance strong{color:var(--fd-green-dark);font-size:15px}
        .iv-customer{padding:12px;border:1px solid #e8edf2;border-radius:9px;background:#fbfcfd}.iv-customer strong{display:block;font-size:12px}.iv-customer span{display:block;margin-top:5px;color:var(--fd-muted);font-size:9.5px;line-height:1.5;overflow-wrap:anywhere}
        .iv-pay-list{display:flex;flex-direction:column;gap:8px}.iv-payment{padding:11px 12px;border:1px solid #e8edf2;border-radius:9px;background:#fbfcfd}.iv-payment-top{display:flex;justify-content:space-between;gap:12px}.iv-payment strong{font-size:10.5px}.iv-payment-amount{color:var(--fd-green-dark)!important}.iv-payment small{display:block;margin-top:5px;color:var(--fd-muted);font-size:8.5px;line-height:1.45}.iv-empty{padding:26px 15px;text-align:center;color:var(--fd-muted);font-size:10px}
        .iv-modal-backdrop{position:fixed;inset:0;z-index:12000;padding:18px;display:none;align-items:center;justify-content:center;background:rgba(0,17,49,.56)}.iv-modal-backdrop.show{display:flex}.iv-modal{width:min(520px,100%);max-height:calc(100vh - 36px);overflow:auto;border-radius:13px;background:#fff;box-shadow:0 24px 70px rgba(0,17,49,.25)}
        .iv-modal-head,.iv-modal-foot{padding:15px 17px;display:flex;align-items:center;justify-content:space-between;gap:12px}.iv-modal-head{border-bottom:1px solid var(--fd-border)}.iv-modal-foot{border-top:1px solid var(--fd-border);justify-content:flex-end}.iv-modal-head h3{margin:0;font-size:14px}.iv-close{width:32px;height:32px;border:0;border-radius:8px;background:#f5f7fa;color:#50617a;cursor:pointer}.iv-modal-body{padding:17px}.iv-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.iv-field.full{grid-column:1/-1}.iv-field label{display:block;margin-bottom:6px;color:#42546c;font-size:9px;font-weight:700}.iv-field input,.iv-field select,.iv-field textarea{width:100%;min-height:40px;padding:9px 10px;border:1px solid #dfe5ec;border-radius:8px;outline:0;color:#263750;background:#fff;font:inherit;font-size:10px}.iv-field textarea{min-height:85px;resize:vertical}.iv-field input:focus,.iv-field select:focus,.iv-field textarea:focus{border-color:#b8d88d;box-shadow:0 0 0 3px rgba(116,184,36,.11)}.iv-help{margin-top:6px;color:var(--fd-muted);font-size:8.5px}
        .iv-toast{position:fixed;top:82px;right:18px;z-index:14000;width:min(380px,calc(100vw - 36px));padding:12px 14px;border-radius:9px;color:#fff;background:#123d70;box-shadow:0 12px 30px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s;font-size:10px;font-weight:700}.iv-toast.show{opacity:1;transform:translateY(0)}.iv-toast.success{background:#5d971b}.iv-toast.error{background:#e45b66}
        @media(max-width:1199.98px){.iv-grid{grid-template-columns:1fr}}
        
        @media(max-width:767.98px){.iv-page{padding:17px 13px 28px}.iv-head{flex-direction:column}.iv-actions{width:100%}.iv-actions .iv-btn{flex:1}.iv-info-grid{grid-template-columns:1fr 1fr}.iv-company{flex-direction:column}.iv-number{text-align:left}.iv-form-grid{grid-template-columns:1fr}.iv-field.full{grid-column:auto}}
        @media(max-width:520px){.iv-info-grid{grid-template-columns:1fr}}
        @media print{.fieldplx-topbar,.fieldplx-sidebar,.fieldplx-footer,.iv-actions,.iv-summary,.iv-modal-backdrop,.iv-toast{display:none!important}.fieldplx-main-content{margin-left:0!important}.iv-page{max-width:none;padding:0}.iv-grid{display:block}.iv-card{box-shadow:none;break-inside:avoid}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="iv-page">
                <section class="iv-head">
                    <div>
                        <div class="iv-title-row">
                            <h1 class="iv-title" id="pageTitle">Invoice</h1>
                            <span class="iv-badge draft" id="statusBadge">Loading</span>
                        </div>
                        <p class="iv-sub" id="pageSubtitle">Completed job invoice, payment collection and payment history.</p>
                    </div>
                    <div class="iv-actions">
                        <a class="iv-btn" href="invoices"><i class="bi bi-arrow-left"></i> Invoices</a>
                        <a class="iv-btn" target="_blank" href="invoice-print?invoice_id=<?php echo $invoiceId; ?>">
    <i class="bi bi-printer"></i> Print
</a>
                        <button type="button" class="iv-btn primary" id="collectButton" disabled><i class="bi bi-cash-stack"></i> Collect Payment</button>
                    </div>
                </section>

                <section class="row g-3 iv-summary">
                    <div class="col-xl-3 col-6"><article class="iv-stat"><div class="iv-stat-row"><span class="iv-stat-icon"><i class="bi bi-receipt"></i></span><div><span class="iv-stat-label">Invoice Total</span><strong class="iv-stat-value" id="statTotal">-</strong></div></div></article></div>
                    <div class="col-xl-3 col-6"><article class="iv-stat"><div class="iv-stat-row"><span class="iv-stat-icon green"><i class="bi bi-check2-circle"></i></span><div><span class="iv-stat-label">Collected</span><strong class="iv-stat-value" id="statPaid">-</strong></div></div></article></div>
                    <div class="col-xl-3 col-6"><article class="iv-stat"><div class="iv-stat-row"><span class="iv-stat-icon orange"><i class="bi bi-hourglass-split"></i></span><div><span class="iv-stat-label">Balance Due</span><strong class="iv-stat-value" id="statBalance">-</strong></div></div></article></div>
                    <div class="col-xl-3 col-6"><article class="iv-stat"><div class="iv-stat-row"><span class="iv-stat-icon"><i class="bi bi-briefcase"></i></span><div><span class="iv-stat-label">Job Card</span><strong class="iv-stat-value" id="statJob" style="font-size:18px">-</strong></div></div></article></div>
                </section>

                <div class="iv-grid">
                    <div>
                        <section class="iv-card">
                            <div class="iv-card-body">
                                <div class="iv-company">
                                    <div class="iv-company-left">
                                        <div class="iv-logo" id="companyLogo">F</div>
                                        <div>
                                            <h3 id="companyName">FieldPlx</h3>
                                            <p id="companyAddress">-</p>
                                            <p id="companyContact">-</p>
                                            <p id="companyTax" style="display:none"></p>
                                        </div>
                                    </div>
                                    <div class="iv-number">
                                        <small>Invoice Number</small>
                                        <strong id="invoiceNo">-</strong>
                                    </div>
                                </div>

                                <div class="iv-info-grid">
                                    <div class="iv-info"><span>Issue Date</span><strong id="issueDate">-</strong></div>
                                    <div class="iv-info"><span>Due Date</span><strong id="dueDate">-</strong></div>
                                    <div class="iv-info"><span>Payment Terms</span><strong id="paymentTerms">-</strong></div>
                                    <div class="iv-info"><span>Job Card</span><strong id="jobNo">-</strong></div>
                                    <div class="iv-info"><span>Quotation</span><strong id="quoteNo">-</strong></div>
                                    <div class="iv-info"><span>Branch</span><strong id="branchName">-</strong></div>
                                </div>
                            </div>
                        </section>

                        <section class="iv-card">
                            <div class="iv-card-head"><div><h2>Invoice Items</h2><small>Services, products and charges copied from the completed job quotation.</small></div></div>
                            <div class="iv-table-wrap">
                                <table class="iv-table">
                                    <thead><tr><th>S.No</th><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Discount</th><th class="num">Tax %</th><th class="num">Tax</th><th class="num">Total</th></tr></thead>
                                    <tbody id="itemRows"><tr><td colspan="8" class="iv-empty">Loading invoice items...</td></tr></tbody>
                                </table>
                            </div>
                            <div class="iv-totals">
                                <div class="iv-total-row"><span>Subtotal</span><strong id="subtotal">-</strong></div>
                                <div class="iv-total-row"><span>Discount</span><strong id="discountTotal">-</strong></div>
                                <div class="iv-total-row"><span>Tax</span><strong id="taxTotal">-</strong></div>
                                <div class="iv-total-row grand"><span>Invoice Total</span><strong id="grandTotal">-</strong></div>
                                <div class="iv-total-row"><span>Amount Paid</span><strong id="amountPaid">-</strong></div>
                                <div class="iv-total-row balance"><span>Balance Due</span><strong id="balanceDue">-</strong></div>
                            </div>
                        </section>
                    </div>

                    <aside>
                        <section class="iv-card">
                            <div class="iv-card-head"><div><h2>Bill To</h2><small>Customer details linked to this completed job.</small></div></div>
                            <div class="iv-card-body"><div class="iv-customer"><strong id="clientName">-</strong><span id="clientCompany"></span><span id="clientContact">-</span><span id="clientAddress">-</span></div></div>
                        </section>

                        <section class="iv-card">
                            <div class="iv-card-head"><div><h2>Payment History</h2><small>Successful and attempted payments recorded for this invoice.</small></div><span class="iv-badge" id="paymentCount">0</span></div>
                            <div class="iv-card-body"><div class="iv-pay-list" id="paymentList"><div class="iv-empty">No payments collected yet.</div></div></div>
                        </section>
                    </aside>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="iv-modal-backdrop" id="paymentModal">
    <section class="iv-modal">
        <div class="iv-modal-head"><div><h3>Collect Payment</h3><div class="iv-help" id="paymentInvoiceText">Record a payment against this invoice.</div></div><button type="button" class="iv-close" id="closePayment"><i class="bi bi-x-lg"></i></button></div>
        <form id="paymentForm">
            <div class="iv-modal-body">
                <div class="iv-form-grid">
                    <div class="iv-field"><label>Amount *</label><input type="number" id="paymentAmount" min="0.01" step="0.01" required></div>
                    <div class="iv-field"><label>Payment Method *</label><select id="paymentMethod" required><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="bank">Bank Transfer</option><option value="cheque">Cheque</option><option value="wallet">Wallet</option><option value="other">Other</option></select></div>
                    <div class="iv-field full"><label>Transaction / Reference No.</label><input type="text" id="paymentReference" maxlength="190" placeholder="UPI ref, bank ref, cheque no., etc."></div>
                    <div class="iv-field full"><label>Received Date & Time *</label><input type="datetime-local" id="paymentReceivedAt" required></div>
                    <div class="iv-field full"><label>Notes</label><textarea id="paymentNotes" maxlength="3000" placeholder="Optional payment remarks"></textarea></div>
                </div>
            </div>
            <div class="iv-modal-foot"><button type="button" class="iv-btn" id="cancelPayment">Cancel</button><button type="submit" class="iv-btn primary" id="savePayment"><i class="bi bi-check2-circle"></i> Save Payment</button></div>
        </form>
    </section>
</div>

<div class="iv-toast" id="toast">Notification</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    'use strict';

    var csrfToken=<?= json_encode($invoiceCsrfToken) ?>;
    var invoiceId=<?= (int)$invoiceId ?>;
    var jobId=<?= (int)$jobId ?>;
    var openCollect=<?= $openCollect ? 'true' : 'false' ?>;
    var state={invoice:null,currency:{},payments:[]};
    var toastTimer=null;

    function el(id){return document.getElementById(id)}
    function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
    function title(v){return String(v||'-').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
    function notify(type,message){var t=el('toast');if(toastTimer)clearTimeout(toastTimer);t.className='iv-toast '+(type||'')+' show';t.textContent=message||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},3500)}
    function parse(response){return response.text().then(function(raw){var d,text=String(raw||'').trim();try{d=text?JSON.parse(text):{}}catch(e){throw new Error(text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!response.ok||!d.success)throw new Error(d.message||'Request failed.');return d})}
    function request(fd){fd.append('csrf_token',csrfToken);return fetch('api/invoices.php',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parse)}
    function money(v){var c=state.currency||{},places=parseInt(c.decimal_places,10);if(isNaN(places))places=2;var n=Number(v||0).toFixed(places),sym=c.symbol||'';return c.symbol_position==='after'?n+(sym?' '+sym:''):(sym||'')+n}
    function fmtDate(v){if(!v)return '-';var d=new Date(String(v).substring(0,10)+'T00:00:00');return isNaN(d.getTime())?String(v):d.toLocaleDateString(undefined,{day:'2-digit',month:'short',year:'numeric'})}
    function fmtDateTime(v){if(!v)return '-';var d=new Date(String(v).replace(' ','T'));return isNaN(d.getTime())?String(v):d.toLocaleString(undefined,{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}
    function joinAddress(parts){return (parts||[]).filter(function(v){return v!=null&&String(v).trim()!==''}).join(', ')}
    function localDateTime(){var d=new Date(),off=d.getTimezoneOffset();d=new Date(d.getTime()-off*60000);return d.toISOString().slice(0,16)}

    function renderItems(items){var body=el('itemRows');if(!items||!items.length){body.innerHTML='<tr><td colspan="8" class="iv-empty">No invoice items found.</td></tr>';return}var html='';items.forEach(function(item,index){html+='<tr><td>'+(index+1)+'</td><td><div class="iv-item"><strong>'+esc(item.item_name||'-')+'</strong>'+(item.description?'<small>'+esc(item.description)+'</small>':'')+'</div></td><td class="num">'+esc(Number(item.quantity||0).toFixed(3).replace(/\.?0+$/,''))+'</td><td class="num">'+esc(money(item.unit_price))+'</td><td class="num">'+esc(money(item.discount_amount))+'</td><td class="num">'+esc(Number(item.tax_percent||0).toFixed(2).replace(/\.?0+$/,''))+'%</td><td class="num">'+esc(money(item.tax_amount))+'</td><td class="num"><strong>'+esc(money(item.line_total))+'</strong></td></tr>'});body.innerHTML=html}

    function renderPayments(payments){state.payments=payments||[];el('paymentCount').textContent=state.payments.length;var box=el('paymentList');if(!state.payments.length){box.innerHTML='<div class="iv-empty">No payments collected yet.</div>';return}var html='';state.payments.forEach(function(p){html+='<div class="iv-payment"><div class="iv-payment-top"><strong>'+esc(p.payment_no||'-')+'</strong><strong class="iv-payment-amount">'+esc(money(p.amount))+'</strong></div><small>'+esc(title(p.payment_method))+' • '+esc(title(p.status))+' • '+esc(fmtDateTime(p.received_at||p.created_at))+'</small>'+(p.provider_payment_id?'<small>Reference: '+esc(p.provider_payment_id)+'</small>':'')+(p.notes?'<small>'+esc(p.notes)+'</small>':'')+'</div>'});box.innerHTML=html}

    function render(data){
        var i=data.invoice||{};state.invoice=i;state.currency=data.currency||{};
        invoiceId=Number(i.id||invoiceId||0);
        el('pageTitle').textContent='Invoice '+(i.invoice_no||'');
        el('pageSubtitle').textContent=(i.job_no?'Generated from completed job '+i.job_no+'. ':'')+'Collect and track customer payments against this invoice.';
        var badge=el('statusBadge');badge.textContent=title(i.status);badge.className='iv-badge '+String(i.status||'draft');
        el('statTotal').textContent=money(i.total);el('statPaid').textContent=money(i.amount_paid);el('statBalance').textContent=money(i.balance_due);el('statJob').textContent=i.job_no||'-';
        el('invoiceNo').textContent=i.invoice_no||'-';el('issueDate').textContent=fmtDate(i.issue_date);el('dueDate').textContent=fmtDate(i.due_date);el('paymentTerms').textContent=i.payment_terms||'-';el('jobNo').textContent=i.job_no||'-';el('quoteNo').textContent=i.quote_no||'-';el('branchName').textContent=i.branch_name||'Head Office';
        el('subtotal').textContent=money(i.subtotal);el('discountTotal').textContent=money(i.discount_total);el('taxTotal').textContent=money(i.tax_total);el('grandTotal').textContent=money(i.total);el('amountPaid').textContent=money(i.amount_paid);el('balanceDue').textContent=money(i.balance_due);
        el('companyName').textContent=i.branch_name||i.tenant_name||i.tenant_legal_name||'FieldPlx';
        el('companyAddress').textContent=joinAddress(i.branch_name?[i.branch_address_line1,i.branch_address_line2,i.branch_city,i.branch_state,i.branch_postal_code]:[i.tenant_address_line1,i.tenant_address_line2,i.tenant_city,i.tenant_state,i.tenant_postal_code])||'-';
        el('companyContact').textContent=[i.branch_email||i.tenant_email,i.branch_phone||i.tenant_phone].filter(Boolean).join(' • ')||'-';
        if(i.tenant_tax_number){el('companyTax').style.display='block';el('companyTax').textContent='Tax No: '+i.tenant_tax_number}
        if(i.invoice_logo){el('companyLogo').innerHTML='<img src="'+esc(i.invoice_logo)+'" alt="Logo">'}else{el('companyLogo').textContent=(i.tenant_name||'F').charAt(0).toUpperCase()}
        el('clientName').textContent=i.client_name||'-';el('clientCompany').textContent=i.client_company||'';el('clientContact').textContent=[i.client_email,i.client_phone].filter(Boolean).join(' • ')||'-';el('clientAddress').textContent=joinAddress([i.location_name,i.address_line1,i.address_line2,i.city,i.state,i.postal_code])||'-';
        renderItems(data.items||[]);renderPayments(data.payments||[]);
        var canCollect=Number(i.balance_due||0)>0.005&&['cancelled','archived','written_off'].indexOf(String(i.status||''))===-1;el('collectButton').disabled=!canCollect;
        if(openCollect&&canCollect){
            openCollect=false;
            setTimeout(openPayment,80);
        }
    }

    function load(){var fd=new FormData();fd.append('action','load');if(invoiceId>0)fd.append('invoice_id',invoiceId);if(jobId>0)fd.append('job_id',jobId);request(fd).then(render).catch(function(e){notify('error',e.message);el('itemRows').innerHTML='<tr><td colspan="8" class="iv-empty">'+esc(e.message)+'</td></tr>'})}
    function openPayment(){if(!state.invoice||Number(state.invoice.balance_due||0)<=0)return;el('paymentAmount').value=Number(state.invoice.balance_due||0).toFixed(2);el('paymentAmount').max=Number(state.invoice.balance_due||0).toFixed(2);el('paymentMethod').value='cash';el('paymentReference').value='';el('paymentNotes').value='';el('paymentReceivedAt').value=localDateTime();el('paymentInvoiceText').textContent='Invoice '+(state.invoice.invoice_no||'')+' • Balance '+money(state.invoice.balance_due);el('paymentModal').classList.add('show')}
    function closePayment(){el('paymentModal').classList.remove('show')}

    el('collectButton').addEventListener('click',openPayment);
    el('closePayment').addEventListener('click',closePayment);
    el('cancelPayment').addEventListener('click',closePayment);
    el('paymentModal').addEventListener('click',function(e){if(e.target===this)closePayment()});
    el('paymentForm').addEventListener('submit',function(e){e.preventDefault();var btn=el('savePayment'),amount=Number(el('paymentAmount').value||0);if(!state.invoice)return;if(amount<=0){notify('error','Enter a valid payment amount.');return}if(amount>Number(state.invoice.balance_due||0)+0.005){notify('error','Payment cannot exceed the balance due.');return}btn.disabled=true;var fd=new FormData();fd.append('action','collect_payment');fd.append('invoice_id',invoiceId);fd.append('amount',amount.toFixed(2));fd.append('payment_method',el('paymentMethod').value);fd.append('reference',el('paymentReference').value.trim());fd.append('received_at',el('paymentReceivedAt').value);fd.append('notes',el('paymentNotes').value.trim());request(fd).then(function(d){closePayment();notify('success',d.message||'Payment collected successfully.');load()}).catch(function(err){notify('error',err.message)}).finally(function(){btn.disabled=false})});

    if(invoiceId<=0&&jobId<=0){notify('error','Invoice or completed job is required.')}else{load()}
})();
</script>
</body>
</html>
