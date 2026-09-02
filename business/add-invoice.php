<?php
/* FieldPlx Add Invoice - Version 1.0.0 - 2026-09-02
 * Job-based or Direct Invoice + Select2 + split payment allocation.
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Create Invoice';
$activePage = 'invoices';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['invoice_form_csrf_token'])) {
    $_SESSION['invoice_form_csrf_token'] = bin2hex(random_bytes(32));
}
$invoiceFormCsrfToken = (string)$_SESSION['invoice_form_csrf_token'];
$preJobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$preClientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$preLocationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Create Invoice - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
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
            --ai-navy:#001131;
            --ai-green:#74b824;
            --ai-green-dark:#5d971b;
            --ai-green-soft:#f0f8e5;
            --ai-red:#e45b66;
            --ai-orange:#a97814;
            --ai-text:#0b1933;
            --ai-muted:#6f7b90;
            --ai-border:#e5eaf1;
            --ai-bg:#f6f8fb;
        }
        .ai-page{width:100%;max-width:1600px;margin:auto;padding:25px 27px 36px}
        .ai-head{margin-bottom:18px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
        .ai-title{margin:0;color:var(--ai-text);font-size:21px;line-height:1.2;font-weight:700}
        .ai-sub{max-width:820px;margin:7px 0 0;color:var(--ai-muted);font-size:10.5px;line-height:1.55}
        .ai-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .ai-btn{min-height:40px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid #dfe5ec;border-radius:8px;color:#43546c;background:#fff;font-size:10px;font-weight:700;cursor:pointer;box-shadow:none}
        .ai-btn:hover{border-color:#c7d3df;color:var(--ai-navy);background:#fbfcfd}
        .ai-btn.primary{border-color:var(--ai-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);box-shadow:0 7px 16px rgba(104,170,29,.16)}
        .ai-btn.primary:hover{color:#fff;border-color:var(--ai-green-dark)}
        .ai-btn.soft{border-color:#dce8ce;color:var(--ai-green-dark);background:#f8fbf3}
        .ai-btn.danger{color:#b9444d;background:#fff;border-color:#f0d9dc}
        .ai-btn:disabled{opacity:.55;cursor:not-allowed}
        .ai-btn.loading .ai-btn-text{opacity:.7}.ai-btn.loading:before{width:12px;height:12px;border:2px dotted currentColor;border-radius:50%;content:"";animation:aiSpin .8s linear infinite}
        @keyframes aiSpin{to{transform:rotate(360deg)}}

        .ai-layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(310px,.62fr);gap:16px;align-items:start}
        .ai-stack{display:grid;gap:14px}
        .ai-card{border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035);overflow:hidden}
        .ai-card-head{min-height:61px;padding:13px 15px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--ai-border);background:#fff}
        .ai-card-icon{width:34px;height:34px;flex:0 0 34px;display:grid;place-items:center;border-radius:9px;color:var(--ai-green-dark);background:var(--ai-green-soft);font-size:15px}
        .ai-card-copy{min-width:0}.ai-card-copy h2{margin:0;color:#263750;font-size:12px;font-weight:700}.ai-card-copy p{margin:4px 0 0;color:#8793a5;font-size:8.5px;line-height:1.45}
        .ai-card-body{padding:14px 15px}
        .ai-section-label{margin:1px 0 9px;color:#718096;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
        .ai-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}
        .ai-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.ai-field.full{grid-column:1/-1}
        .ai-field label{display:block;margin-bottom:5px;color:#42536c;font-size:9px;font-weight:700}
        .ai-field input,.ai-field select,.ai-field textarea{width:100%;min-height:39px;padding:8px 10px;border:1px solid #dfe5ec;border-radius:8px;outline:0;background:#fff;color:#263750;font-family:inherit;font-size:10px;box-shadow:none}
        .ai-field textarea{min-height:82px;resize:vertical;line-height:1.5}
        .ai-field input:focus,.ai-field select:focus,.ai-field textarea:focus{border-color:#b8d88d;box-shadow:0 0 0 3px rgba(116,184,36,.1)}
        .ai-hint{margin-top:5px;color:#929dad;font-size:8px;line-height:1.45}
        .ai-required{color:#c94d57}

        .ai-source-types{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:13px}
        .ai-radio-card{position:relative;padding:12px 12px 12px 39px;border:1px solid #dfe6ed;border-radius:9px;background:#fff;cursor:pointer}
        .ai-radio-card input{position:absolute;left:13px;top:15px;width:14px;height:14px;accent-color:var(--ai-green)}
        .ai-radio-card strong,.ai-radio-card small{display:block}.ai-radio-card strong{color:#273951;font-size:9.5px}.ai-radio-card small{margin-top:4px;color:#7e8b9d;font-size:8px;line-height:1.5}
        .ai-radio-card.selected{border-color:#a8d174;background:#f9fcf5}
        .ai-source-panel{display:none}.ai-source-panel.show{display:block}
        .ai-context{margin-top:11px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
        .ai-context-item{min-height:58px;padding:9px 10px;border:1px solid #e7ecf1;border-radius:8px;background:#fbfcfd}
        .ai-context-item span,.ai-context-item strong{display:block}.ai-context-item span{margin-bottom:5px;color:#8793a5;font-size:7.8px;text-transform:uppercase;font-weight:700}.ai-context-item strong{overflow:hidden;color:#263750;font-size:9px;text-overflow:ellipsis;white-space:nowrap}
        .ai-warning{display:none;margin-top:10px;padding:9px 10px;border:1px solid #f0d89e;border-radius:8px;background:#fff9e8;color:#80631b;font-size:8.5px;line-height:1.5}.ai-warning.show{display:block}

        .select2-container{width:100%!important}.select2-container .select2-selection--single{height:39px!important;border:1px solid #dfe5ec!important;border-radius:8px!important;background:#fff!important}.select2-container .select2-selection--single .select2-selection__rendered{height:37px!important;line-height:37px!important;padding-left:10px!important;padding-right:28px!important;color:#263750!important;font-size:10px!important}.select2-container .select2-selection--single .select2-selection__arrow{height:37px!important}.select2-container--focus .select2-selection--single,.select2-container--open .select2-selection--single{border-color:#b8d88d!important;box-shadow:0 0 0 3px rgba(116,184,36,.1)!important}.select2-dropdown{border:1px solid #dfe5ec!important;border-radius:8px!important;overflow:hidden;box-shadow:0 12px 28px rgba(24,45,76,.12)!important}.select2-search--dropdown{padding:8px!important}.select2-search__field{height:34px!important;border:1px solid #dfe5ec!important;border-radius:7px!important;font-size:10px!important}.select2-results__option{padding:8px 10px!important;font-size:9.5px!important}.select2-results__option--highlighted[aria-selected]{background:#f0f8e5!important;color:#35551d!important}

        .ai-item-tools{display:grid;grid-template-columns:minmax(220px,1fr) auto auto;gap:8px;align-items:end;margin-bottom:11px}
        .ai-table-wrap{overflow:auto;border:1px solid #e7ecf1;border-radius:9px}
        .ai-items{width:100%;min-width:930px;border-collapse:collapse}.ai-items th{padding:9px 8px;color:#718096;background:#f8fafc;border-bottom:1px solid var(--ai-border);font-size:7.8px;font-weight:700;text-transform:uppercase;white-space:nowrap}.ai-items td{padding:7px 6px;border-bottom:1px solid #edf1f4;vertical-align:top}.ai-items tbody tr:last-child td{border-bottom:0}
        .ai-line-input{width:100%;height:34px;padding:6px 7px;border:1px solid #dfe5ec;border-radius:7px;color:#344760;background:#fff;font-size:9px;outline:0}.ai-line-input:focus{border-color:#b8d88d;box-shadow:0 0 0 2px rgba(116,184,36,.08)}
        .ai-item-name{min-width:170px}.ai-item-desc{min-width:150px}.ai-num{width:84px;text-align:right}.ai-tax{width:72px;text-align:right}.ai-line-total{min-width:92px;padding-top:9px!important;text-align:right;color:#15283f;font-size:9.5px;font-weight:700;white-space:nowrap}.ai-remove{width:29px;height:29px;margin-top:2px;display:grid;place-items:center;border:0;border-radius:7px;color:#a65057;background:#fff0f1;cursor:pointer}
        .ai-empty{padding:25px!important;text-align:center;color:#8b97a8;font-size:9px!important}

        .ai-payment-list{display:grid;gap:10px}.ai-payment-row{border:1px solid #e3e9ef;border-radius:10px;background:#fbfcfd;overflow:hidden}.ai-payment-head{padding:9px 10px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e8edf2;background:#fff}.ai-payment-no{width:25px;height:25px;display:grid;place-items:center;border-radius:7px;color:var(--ai-green-dark);background:var(--ai-green-soft);font-size:8px;font-weight:700}.ai-payment-head strong{color:#33475f;font-size:9px}.ai-payment-head .ai-remove{margin-left:auto;margin-top:0}.ai-payment-body{padding:10px}.ai-payment-grid{display:grid;grid-template-columns:180px 150px minmax(0,1fr);gap:9px}.ai-payment-details{grid-column:1/-1;margin-top:1px;padding-top:9px;border-top:1px dashed #dde4eb;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.ai-payment-details.cash,.ai-payment-details.credit{grid-template-columns:1fr}.ai-payment-saved{grid-column:1/-1}
        .ai-payment-note{margin-top:9px;padding:8px 9px;border-radius:7px;background:#fff;color:#748297;font-size:8px;line-height:1.5}.ai-credit-note{color:#8a620e;background:#fff8e7}
        .ai-use-balance{height:32px;padding:0 9px;border:1px solid #dce7cf;border-radius:7px;color:var(--ai-green-dark);background:#f8fbf3;font-size:8px;font-weight:700;cursor:pointer}
        .ai-payment-safe{margin-top:10px;padding:9px 10px;display:flex;gap:8px;border:1px solid #e1e8ef;border-radius:8px;background:#fbfcfd;color:#718096;font-size:8px;line-height:1.5}.ai-payment-safe i{color:var(--ai-green-dark);font-size:14px}

        .ai-side{position:sticky;top:86px;display:grid;gap:14px}.ai-total-list{display:grid;gap:9px}.ai-total-row{display:flex;align-items:center;justify-content:space-between;gap:12px;color:#66768a;font-size:9px}.ai-total-row strong{color:#263750;font-size:10px}.ai-total-row.grand{margin-top:3px;padding-top:11px;border-top:1px solid #e5eaf0;color:#273951;font-size:10px;font-weight:700}.ai-total-row.grand strong{font-size:17px;color:#0f2139}.ai-total-row.paid strong{color:var(--ai-green-dark)}.ai-total-row.credit strong{color:#96670d}.ai-total-row.remaining strong.bad{color:#b9444d}.ai-total-row.remaining strong.good{color:var(--ai-green-dark)}
        .ai-summary-status{margin-top:12px;padding:9px 10px;border-radius:8px;background:#f8fafc;color:#68788e;font-size:8.5px;line-height:1.5}.ai-summary-status strong{color:#2f435c}
        .ai-save-card .ai-btn{width:100%;margin-top:10px}.ai-mini{margin-top:10px;color:#8a96a7;font-size:8px;line-height:1.5}

        .ai-toast{position:fixed;top:82px;right:18px;z-index:14000;width:min(390px,calc(100vw - 36px));padding:12px 14px;border-radius:9px;color:#fff;background:#123d70;box-shadow:0 12px 30px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s;font-size:10px;font-weight:700}.ai-toast.show{opacity:1;transform:translateY(0)}.ai-toast.error{background:#e45b66}.ai-toast.success{background:#5d971b}.ai-toast.warning{background:#9a741a}

        @media(max-width:1199.98px){.ai-layout{grid-template-columns:1fr}.ai-side{position:static;grid-template-columns:repeat(2,minmax(0,1fr))}.ai-context{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:991.98px){.ai-grid.three{grid-template-columns:repeat(2,minmax(0,1fr))}.ai-payment-grid{grid-template-columns:1fr 1fr}.ai-payment-details{grid-template-columns:1fr 1fr}.ai-payment-saved{grid-column:1/-1}}
        @media(max-width:767.98px){.ai-page{padding:17px 13px 28px}.ai-head{flex-direction:column}.ai-actions{width:100%}.ai-actions .ai-btn{flex:1}.ai-source-types,.ai-grid,.ai-grid.three,.ai-side{grid-template-columns:1fr}.ai-context{grid-template-columns:1fr 1fr}.ai-item-tools{grid-template-columns:1fr 1fr}.ai-item-tools .ai-field{grid-column:1/-1}.ai-payment-grid,.ai-payment-details{grid-template-columns:1fr}.ai-payment-saved{grid-column:auto}.ai-field.full{grid-column:auto}}
        @media(max-width:575.98px){.ai-context{grid-template-columns:1fr}.ai-item-tools{grid-template-columns:1fr}.ai-actions{display:grid;grid-template-columns:1fr 1fr}.ai-payment-head{align-items:flex-start}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="ai-page">
                <section class="ai-head">
                    <div>
                        <h1 class="ai-title">Create Invoice</h1>
                        <p class="ai-sub">Create an invoice from an existing Job Card or create a Direct Invoice for a customer. Add billable items and allocate the full total across Cash, Card, Online and Credit.</p>
                    </div>
                    <div class="ai-actions">
                        <a class="ai-btn" href="invoices.php"><i class="bi bi-arrow-left"></i> Back to Invoices</a>
                    </div>
                </section>

                <form id="invoiceForm" autocomplete="off">
                    <input type="hidden" name="items_json" id="itemsJson" value="[]">
                    <input type="hidden" name="payments_json" id="paymentsJson" value="[]">
                    <div class="ai-layout">
                        <div class="ai-stack">
                            <section class="ai-card">
                                <div class="ai-card-head"><span class="ai-card-icon"><i class="bi bi-signpost-split"></i></span><div class="ai-card-copy"><h2>Invoice Source</h2><p>Select a Job Card to pull customer and billing context, or create the invoice directly.</p></div></div>
                                <div class="ai-card-body">
                                    <div class="ai-source-types">
                                        <label class="ai-radio-card" data-source-card="job"><input type="radio" name="source_mode" value="job"><strong>From Job Card</strong><small>Select an existing job. Customer, location, service, quotation and recurring billing visit are loaded automatically.</small></label>
                                        <label class="ai-radio-card selected" data-source-card="direct"><input type="radio" name="source_mode" value="direct" checked><strong>Direct Invoice</strong><small>Create an invoice without a Job Card. Select the customer and add invoice items directly.</small></label>
                                    </div>

                                    <div class="ai-source-panel" id="jobSourcePanel">
                                        <div class="ai-grid">
                                            <div class="ai-field full"><label>Job Card <span class="ai-required">*</span></label><select id="jobId" name="job_id"><option value="">Select Job Card</option></select></div>
                                            <div class="ai-field full" id="jobVisitWrap" style="display:none"><label>Billing Visit / Invoice Slot <span class="ai-required">*</span></label><select id="visitId" name="visit_id"><option value="">Select billing visit</option></select><div class="ai-hint">Recurring jobs can create multiple invoices. Each visit can be invoiced only once.</div></div>
                                        </div>
                                        <div class="ai-context" id="jobContext">
                                            <div class="ai-context-item"><span>Customer</span><strong id="jobCustomer">-</strong></div>
                                            <div class="ai-context-item"><span>Location</span><strong id="jobLocation">-</strong></div>
                                            <div class="ai-context-item"><span>Service</span><strong id="jobService">-</strong></div>
                                            <div class="ai-context-item"><span>Billing</span><strong id="jobBilling">-</strong></div>
                                            <div class="ai-context-item"><span>Quotation</span><strong id="jobQuote">-</strong></div>
                                            <div class="ai-context-item"><span>Status</span><strong id="jobStatus">-</strong></div>
                                            <div class="ai-context-item"><span>Branch</span><strong id="jobBranch">-</strong></div>
                                            <div class="ai-context-item"><span>Job Total</span><strong id="jobTotal">-</strong></div>
                                        </div>
                                        <div class="ai-warning" id="uniqueIndexWarning"><i class="bi bi-exclamation-triangle"></i> This database has the old one-invoice-per-job unique index. Run the recurring invoice migration before creating the second invoice for a recurring Job Card.</div>
                                    </div>

                                    <div class="ai-source-panel show" id="directSourcePanel">
                                        <div class="ai-grid">
                                            <div class="ai-field"><label>Customer <span class="ai-required">*</span></label><select id="clientId" name="client_id"><option value="">Select Customer</option></select></div>
                                            <div class="ai-field"><label>Customer Location</label><select id="locationId" name="location_id"><option value="">No Location</option></select></div>
                                            <div class="ai-field full"><label>Branch</label><select id="branchId" name="branch_id"><option value="">Use Customer / Current Branch</option></select></div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="ai-card">
                                <div class="ai-card-head"><span class="ai-card-icon"><i class="bi bi-receipt"></i></span><div class="ai-card-copy"><h2>Invoice Details</h2><p>Set invoice dates, initial invoice state, payment terms and customer-facing notes.</p></div></div>
                                <div class="ai-card-body">
                                    <div class="ai-grid three">
                                        <div class="ai-field"><label>Issue Date <span class="ai-required">*</span></label><input type="date" name="issue_date" id="issueDate" required></div>
                                        <div class="ai-field"><label>Due Date <span id="dueRequired" class="ai-required" style="display:none">*</span></label><input type="date" name="due_date" id="dueDate"><div class="ai-hint" id="dueHint">Required automatically when Credit is used.</div></div>
                                        <div class="ai-field"><label>Invoice Status</label><select name="invoice_status" id="invoiceStatus"><option value="draft">Draft</option><option value="sent">Sent</option></select></div>
                                        <div class="ai-field full"><label>Payment Terms</label><input type="text" name="payment_terms" id="paymentTerms" maxlength="255" placeholder="Example: Due on receipt / Net 15"></div>
                                        <div class="ai-field full"><label>Notes</label><textarea name="notes" id="invoiceNotes" placeholder="Invoice notes or billing instructions"></textarea></div>
                                    </div>
                                </div>
                            </section>

                            <section class="ai-card">
                                <div class="ai-card-head"><span class="ai-card-icon"><i class="bi bi-list-ul"></i></span><div class="ai-card-copy"><h2>Invoice Items</h2><p>Job pricing is suggested automatically. You can still edit the rows or add catalogue/manual items before saving.</p></div></div>
                                <div class="ai-card-body">
                                    <div class="ai-item-tools">
                                        <div class="ai-field"><label>Add from Services / Products / Materials / Fees</label><select id="catalogSelect"><option value="">Search billable item</option></select></div>
                                        <button type="button" class="ai-btn soft" id="addCatalogButton"><i class="bi bi-plus-lg"></i> Add Item</button>
                                        <button type="button" class="ai-btn" id="addManualButton"><i class="bi bi-pencil-square"></i> Manual Item</button>
                                    </div>
                                    <div class="ai-table-wrap">
                                        <table class="ai-items">
                                            <thead><tr><th>#</th><th>Item</th><th>Description</th><th>Qty</th><th>Unit Cost</th><th>Unit Price</th><th>Discount</th><th>Tax %</th><th>Line Total</th><th></th></tr></thead>
                                            <tbody id="itemRows"><tr><td colspan="10" class="ai-empty">Add an invoice item to continue.</td></tr></tbody>
                                        </table>
                                    </div>
                                </div>
                            </section>

                            <section class="ai-card">
                                <div class="ai-card-head"><span class="ai-card-icon"><i class="bi bi-wallet2"></i></span><div class="ai-card-copy"><h2>Payment Details &amp; Split Payment</h2><p>Split the invoice between Cash, Card, Online and Credit. Credit remains outstanding and requires a due date.</p></div><button type="button" class="ai-btn soft" id="addPaymentButton" style="margin-left:auto"><i class="bi bi-plus-lg"></i> Add Split</button></div>
                                <div class="ai-card-body">
                                    <div class="ai-payment-list" id="paymentRows"></div>
                                    <div class="ai-payment-safe"><i class="bi bi-shield-check"></i><span>For Card and Online payments, FieldPlx stores only provider/bank/gateway and transaction references. Full card numbers and CVV are not stored. Previous successful payment references can be reused as input hints.</span></div>
                                </div>
                            </section>
                        </div>

                        <aside class="ai-side">
                            <section class="ai-card">
                                <div class="ai-card-head"><span class="ai-card-icon"><i class="bi bi-calculator"></i></span><div class="ai-card-copy"><h2>Invoice Summary</h2><p>Calculated from the current invoice items.</p></div></div>
                                <div class="ai-card-body">
                                    <div class="ai-total-list">
                                        <div class="ai-total-row"><span>Subtotal</span><strong id="sumSubtotal">0.00</strong></div>
                                        <div class="ai-total-row"><span>Discount</span><strong id="sumDiscount">0.00</strong></div>
                                        <div class="ai-total-row"><span>Tax</span><strong id="sumTax">0.00</strong></div>
                                        <div class="ai-total-row grand"><span>Total</span><strong id="sumTotal">0.00</strong></div>
                                    </div>
                                </div>
                            </section>

                            <section class="ai-card ai-save-card">
                                <div class="ai-card-head"><span class="ai-card-icon"><i class="bi bi-cash-stack"></i></span><div class="ai-card-copy"><h2>Payment Allocation</h2><p>The payment split must equal the invoice total.</p></div></div>
                                <div class="ai-card-body">
                                    <div class="ai-total-list">
                                        <div class="ai-total-row"><span>Invoice Total</span><strong id="payTotal">0.00</strong></div>
                                        <div class="ai-total-row paid"><span>Received Now</span><strong id="payReceived">0.00</strong></div>
                                        <div class="ai-total-row credit"><span>Credit / Outstanding</span><strong id="payCredit">0.00</strong></div>
                                        <div class="ai-total-row remaining"><span>Remaining to Allocate</span><strong id="payRemaining">0.00</strong></div>
                                    </div>
                                    <div class="ai-summary-status" id="paymentStatusText"><strong>Allocation:</strong> Credit is initially set to the full invoice balance.</div>
                                    <button type="submit" class="ai-btn primary" id="saveButton"><span class="ai-btn-text"><i class="bi bi-check2-circle"></i> Create Invoice</span></button>
                                    <div class="ai-mini">After saving, successful Cash/Card/Online portions are recorded as payments. Credit remains in the invoice balance until collected.</div>
                                </div>
                            </section>
                        </aside>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<div class="ai-toast" id="toast">Notification</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
'use strict';
var csrfToken=<?= json_encode($invoiceFormCsrfToken) ?>;
var preJobId=<?= (int)$preJobId ?>,preClientId=<?= (int)$preClientId ?>,preLocationId=<?= (int)$preLocationId ?>;
var meta={clients:[],locations:[],branches:[],catalog:[],jobs:[],currency:{},has_recurring_job_unique_index:0};
var cart=[];
var paymentHints={card:[],online:[]};
var paymentSeq=0;
var currentJob=null;
var currentJobContext=null;
var toastTimer=null;

function el(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function title(v){return String(v||'-').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
function notify(type,message){var t=el('toast');if(toastTimer)clearTimeout(toastTimer);t.className='ai-toast '+(type||'')+' show';t.textContent=message||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},3400)}
function parse(response){return response.text().then(function(raw){var text=String(raw||'').trim(),d;try{d=text?JSON.parse(text):{}}catch(e){throw new Error(text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!response.ok||!d.success)throw new Error(d.message||'Request failed.');return d})}
function request(fd){fd.append('csrf_token',csrfToken);return fetch('api/invoice-form.php',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parse)}
function money(v){var c=meta.currency||{},places=parseInt(c.decimal_places,10);if(isNaN(places))places=2;var n=Number(v||0).toFixed(places),sym=c.symbol||'';return c.symbol_position==='after'?n+(sym?' '+sym:''):(sym||'')+n}
function today(){var d=new Date(),off=d.getTimezoneOffset();d=new Date(d.getTime()-off*60000);return d.toISOString().slice(0,10)}
function fmtDateTime(v){if(!v)return '-';var d=new Date(String(v).replace(' ','T'));return isNaN(d.getTime())?String(v):d.toLocaleString(undefined,{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}
function loading(button,on){button.disabled=!!on;button.classList.toggle('loading',!!on)}
function sourceMode(){var x=document.querySelector('input[name="source_mode"]:checked');return x?x.value:'direct'}

function initSelect2(selector,placeholder){$(selector).select2({width:'100%',placeholder:placeholder||'',allowClear:true})}
function destroySelect2(selector){if($(selector).hasClass('select2-hidden-accessible'))$(selector).select2('destroy')}
function setOptions(id,html,placeholder){var node=el(id);destroySelect2('#'+id);node.innerHTML=html;initSelect2('#'+id,placeholder)}

function setMeta(m){meta=m||meta;var jobs='<option value=""></option>';meta.jobs.forEach(function(j){jobs+='<option value="'+Number(j.id)+'">'+esc(j.job_no+' · '+j.client_name+' · '+(j.service_name||j.title)+' · '+title(j.status))+'</option>'});setOptions('jobId',jobs,'Search Job Card');
var clients='<option value=""></option>';meta.clients.forEach(function(c){clients+='<option value="'+Number(c.id)+'">'+esc(c.name+(c.company_name?' · '+c.company_name:'')+(c.phone?' · '+c.phone:''))+'</option>'});setOptions('clientId',clients,'Search Customer');
var branches='<option value=""></option>';meta.branches.forEach(function(b){branches+='<option value="'+Number(b.id)+'">'+esc(b.name+(b.branch_code?' · '+b.branch_code:''))+'</option>'});setOptions('branchId',branches,'Use Customer / Current Branch');
var catalog='<option value=""></option>';meta.catalog.forEach(function(x){catalog+='<option value="'+Number(x.id)+'">'+esc(x.name+' · '+title(x.item_type)+' · '+money(x.unit_price))+'</option>'});setOptions('catalogSelect',catalog,'Search billable item');
filterLocations(0,0);
}

function filterLocations(clientId,selectedId){clientId=Number(clientId||0);var html='<option value=""></option>';meta.locations.filter(function(x){return clientId>0&&Number(x.client_id)===clientId}).forEach(function(x){var addr=[x.address_line1,x.city,x.state].filter(Boolean).join(', ');html+='<option value="'+Number(x.id)+'">'+esc(x.name+(addr?' · '+addr:''))+'</option>'});setOptions('locationId',html,'No Location');if(selectedId>0)$('#locationId').val(String(selectedId)).trigger('change.select2')}
function selectedClient(){var id=Number(el('clientId').value||0);return meta.clients.find(function(x){return Number(x.id)===id})||null}
function applyClient(selectedLocation){var c=selectedClient();filterLocations(c?c.id:0,selectedLocation||0);if(c&&c.branch_id&&!el('branchId').value)$('#branchId').val(String(c.branch_id)).trigger('change.select2');loadPaymentHints(c?c.id:0)}

function loadPaymentHints(clientId){paymentHints={card:[],online:[]};if(Number(clientId)<=0){refreshPaymentRows();return Promise.resolve()}var fd=new FormData();fd.append('action','payment_hints');fd.append('client_id',clientId);return request(fd).then(function(d){paymentHints=d.hints||paymentHints;refreshPaymentRows()}).catch(function(e){paymentHints={card:[],online:[]};refreshPaymentRows()})}

function updateSource(){var mode=sourceMode();el('jobSourcePanel').classList.toggle('show',mode==='job');el('directSourcePanel').classList.toggle('show',mode==='direct');document.querySelectorAll('[data-source-card]').forEach(function(c){c.classList.toggle('selected',c.getAttribute('data-source-card')===mode)});if(mode==='job'&&el('jobId').value)loadJobContext(el('jobId').value,el('visitId').value);if(mode==='direct')loadPaymentHints(Number(el('clientId').value||0))}

function resetJobContext(){currentJob=null;currentJobContext=null;['jobCustomer','jobLocation','jobService','jobBilling','jobQuote','jobStatus','jobBranch','jobTotal'].forEach(function(id){el(id).textContent='-'});el('jobVisitWrap').style.display='none';destroySelect2('#visitId');el('visitId').innerHTML='<option value=""></option>';initSelect2('#visitId','Select billing visit')}
function renderJobContext(d){currentJob=d.job||null;currentJobContext=d;if(!currentJob){resetJobContext();return}el('jobCustomer').textContent=currentJob.client_name||'-';el('jobLocation').textContent=currentJob.location_name||'-';el('jobService').textContent=currentJob.service_name||currentJob.title||'-';el('jobBilling').textContent=title(currentJob.billing_type||'visit_based')+' · '+Number(currentJob.total_invoices||1)+' invoice(s)';el('jobQuote').textContent=currentJob.quote_no||'Direct Job';el('jobStatus').textContent=title(currentJob.status);el('jobBranch').textContent=currentJob.branch_name||'Current Branch';el('jobTotal').textContent=money(currentJob.total||0);paymentHints=d.payment_hints||{card:[],online:[]};
var slots=Array.isArray(d.billing_slots)?d.billing_slots:[],available=slots.filter(function(x){return Number(x.invoiced||0)===0}),requires=Number(currentJob.total_invoices||1)>1||available.length>1;var html='<option value=""></option>';available.forEach(function(s){var label=s.visit_no||('Visit '+s.visit_number);if(s.scheduled_start)label+=' · '+fmtDateTime(s.scheduled_start);html+='<option value="'+(s.visit_id?Number(s.visit_id):'')+'">'+esc(label)+'</option>'});setOptions('visitId',html,'Select billing visit');el('jobVisitWrap').style.display=requires?'block':'none';if(available.length===1&&available[0].visit_id){$('#visitId').val(String(available[0].visit_id)).trigger('change.select2')}cart=(d.items||[]).map(normalizeItem);renderItems();refreshPaymentRows();syncAutoCredit();}
function loadJobContext(jobId,visitId){jobId=Number(jobId||0);if(jobId<=0){resetJobContext();cart=[];renderItems();paymentHints={card:[],online:[]};refreshPaymentRows();return Promise.resolve()}var fd=new FormData();fd.append('action','job_context');fd.append('job_id',jobId);if(Number(visitId)>0)fd.append('visit_id',visitId);return request(fd).then(function(d){renderJobContext(d);return d}).catch(function(e){resetJobContext();cart=[];renderItems();notify('error',e.message);throw e})}

function normalizeItem(x){return{product_service_id:x&&x.product_service_id?Number(x.product_service_id):null,item_name:x&&x.item_name?String(x.item_name):'',description:x&&x.description?String(x.description):'',quantity:Math.max(.001,Number(x&&x.quantity||1)),unit_cost:Math.max(0,Number(x&&x.unit_cost||0)),unit_price:Math.max(0,Number(x&&x.unit_price||0)),discount_amount:Math.max(0,Number(x&&x.discount_amount||0)),tax_percent:Math.max(0,Number(x&&x.tax_percent||0))}}
function lineCalc(x){var base=Math.round(Number(x.quantity||0)*Number(x.unit_price||0)*100)/100,disc=Math.max(0,Math.min(base,Number(x.discount_amount||0))),taxable=Math.max(0,base-disc),tax=Math.round(taxable*Number(x.tax_percent||0))/100,total=Math.round((taxable+tax)*100)/100;return{base:base,discount:disc,tax:tax,total:total}}
function totals(){var out={subtotal:0,discount:0,tax:0,total:0};cart.forEach(function(x){var c=lineCalc(x);out.subtotal+=c.base;out.discount+=c.discount;out.tax+=c.tax;out.total+=c.total});Object.keys(out).forEach(function(k){out[k]=Math.round(out[k]*100)/100});return out}
function renderItems(){var body=el('itemRows');if(!cart.length){body.innerHTML='<tr><td colspan="10" class="ai-empty">Add an invoice item to continue.</td></tr>';updateTotals();return}var html='';cart.forEach(function(x,i){var c=lineCalc(x);html+='<tr data-index="'+i+'"><td style="padding-top:14px;text-align:center;color:#8a96a7;font-size:8px">'+(i+1)+'</td><td><input class="ai-line-input ai-item-name" data-field="item_name" value="'+esc(x.item_name)+'" placeholder="Item name"></td><td><input class="ai-line-input ai-item-desc" data-field="description" value="'+esc(x.description)+'" placeholder="Description"></td><td><input class="ai-line-input ai-num" data-field="quantity" type="number" min="0.001" step="0.001" value="'+esc(x.quantity)+'"></td><td><input class="ai-line-input ai-num" data-field="unit_cost" type="number" min="0" step="0.01" value="'+esc(x.unit_cost)+'"></td><td><input class="ai-line-input ai-num" data-field="unit_price" type="number" min="0" step="0.01" value="'+esc(x.unit_price)+'"></td><td><input class="ai-line-input ai-num" data-field="discount_amount" type="number" min="0" step="0.01" value="'+esc(x.discount_amount)+'"></td><td><input class="ai-line-input ai-tax" data-field="tax_percent" type="number" min="0" step="0.01" value="'+esc(x.tax_percent)+'"></td><td class="ai-line-total">'+esc(money(c.total))+'</td><td><button type="button" class="ai-remove" data-remove-item="'+i+'" title="Remove"><i class="bi bi-trash"></i></button></td></tr>'});body.innerHTML=html;updateTotals()}
function updateTotals(){var t=totals();el('sumSubtotal').textContent=money(t.subtotal);el('sumDiscount').textContent=money(t.discount);el('sumTax').textContent=money(t.tax);el('sumTotal').textContent=money(t.total);el('payTotal').textContent=money(t.total);syncAutoCredit();updatePaymentSummary()}
function addCatalog(){var id=Number(el('catalogSelect').value||0);if(id<=0){notify('warning','Select a billable item first.');return}var x=meta.catalog.find(function(r){return Number(r.id)===id});if(!x)return;cart.push(normalizeItem({product_service_id:x.id,item_name:x.name,description:x.description||'',quantity:1,unit_cost:x.unit_cost,unit_price:x.unit_price,discount_amount:0,tax_percent:x.tax_percent}));renderItems();$('#catalogSelect').val(null).trigger('change')}
function addManual(){cart.push(normalizeItem({item_name:'',description:'',quantity:1,unit_cost:0,unit_price:0,discount_amount:0,tax_percent:0}));renderItems();var rows=el('itemRows').querySelectorAll('tr[data-index]');if(rows.length){var n=rows[rows.length-1].querySelector('[data-field="item_name"]');if(n)n.focus()}}

function paymentMethodOptions(selected){return['cash','card','online','credit'].map(function(m){return'<option value="'+m+'" '+(m===selected?'selected':'')+'>'+title(m)+'</option>'}).join('')}
function savedOptions(method){var rows=paymentHints[method]||[],html='<option value="">Enter new details</option>';rows.forEach(function(x,i){var label=(x.provider||title(method))+(x.reference?' · '+x.reference:'')+(x.received_at?' · '+String(x.received_at).slice(0,10):'');html+='<option value="'+i+'">'+esc(label)+'</option>'});return html}
function paymentDetailHtml(row){var method=row.getAttribute('data-method')||'credit',id=row.getAttribute('data-payment-id');if(method==='cash')return'<div class="ai-payment-details cash"><div class="ai-field"><label>Cash Notes / Reference</label><input type="text" data-pay-notes placeholder="Optional cash reference"></div></div>';if(method==='credit')return'<div class="ai-payment-details credit"><div class="ai-payment-note ai-credit-note"><strong>Credit:</strong> This amount is not recorded as a payment. It remains outstanding on the invoice. A Due Date is required.</div></div>';var rows=paymentHints[method]||[],saved=rows.length?'<div class="ai-field ai-payment-saved"><label>Previous '+title(method)+' Payment Detail</label><select data-saved-detail><option value="">Enter new details</option>'+savedOptions(method).replace('<option value="">Enter new details</option>','')+'</select><div class="ai-hint">Select a previous provider/reference to reuse it, or enter new details below.</div></div>':'';return'<div class="ai-payment-details">'+saved+'<div class="ai-field"><label>'+(method==='card'?'Card Provider / Bank':'Online Provider / Gateway')+'</label><input type="text" data-pay-provider placeholder="'+(method==='card'?'Example: HDFC POS / Visa':'Example: Razorpay / Bank Transfer')+'"></div><div class="ai-field"><label>'+(method==='card'?'Transaction / Terminal Reference':'Transaction / UTR Reference')+'</label><input type="text" data-pay-reference placeholder="Reference number"></div><div class="ai-field"><label>Notes</label><input type="text" data-pay-notes placeholder="Optional payment notes"></div></div>'}
function addPayment(method,amount,autoCredit){paymentSeq++;var row=document.createElement('div');row.className='ai-payment-row';row.setAttribute('data-payment-id',paymentSeq);row.setAttribute('data-method',method||'cash');row.setAttribute('data-auto-credit',autoCredit?'1':'0');row.innerHTML='<div class="ai-payment-head"><span class="ai-payment-no">'+(el('paymentRows').children.length+1)+'</span><strong>Payment Split</strong><button type="button" class="ai-remove" data-remove-payment title="Remove"><i class="bi bi-trash"></i></button></div><div class="ai-payment-body"><div class="ai-payment-grid"><div class="ai-field"><label>Method</label><select data-pay-method>'+paymentMethodOptions(method||'cash')+'</select></div><div class="ai-field"><label>Amount</label><input type="number" min="0" step="0.01" data-pay-amount value="'+(Number(amount||0)>0?Number(amount).toFixed(2):'')+'"></div><div class="ai-field" data-balance-wrap style="display:'+(method==='credit'?'block':'none')+'"><label>&nbsp;</label><button type="button" class="ai-use-balance" data-use-balance><i class="bi bi-arrow-down-circle"></i> Use Remaining Balance</button></div></div><div data-payment-detail>'+paymentDetailHtml(row)+'</div></div>';el('paymentRows').appendChild(row);initPaymentRow(row);renumberPayments();syncAutoCredit();updatePaymentSummary();return row}
function initPaymentRow(row){var methodSel=row.querySelector('[data-pay-method]');$(methodSel).select2({width:'100%',minimumResultsForSearch:Infinity});var saved=row.querySelector('[data-saved-detail]');if(saved)$(saved).select2({width:'100%'});methodSel.addEventListener('change',function(){row.setAttribute('data-method',this.value);if(this.value!=='credit')row.setAttribute('data-auto-credit','0');row.querySelector('[data-balance-wrap]').style.display=this.value==='credit'?'block':'none';row.querySelector('[data-payment-detail]').innerHTML=paymentDetailHtml(row);var s=row.querySelector('[data-saved-detail]');if(s){$(s).select2({width:'100%'});$(s).on('change',function(){applySavedDetail(row)})}updateDueRequirement();syncAutoCredit();updatePaymentSummary()});var amount=row.querySelector('[data-pay-amount]');amount.addEventListener('input',function(){if(row.getAttribute('data-method')==='credit')row.setAttribute('data-auto-credit','0');updatePaymentSummary()});row.querySelector('[data-remove-payment]').addEventListener('click',function(){if(el('paymentRows').children.length<=1){notify('warning','Keep at least one payment allocation row.');return}row.remove();renumberPayments();syncAutoCredit();updatePaymentSummary()});row.querySelector('[data-use-balance]').addEventListener('click',function(){var remaining=remainingExcluding(row);amount.value=Math.max(0,remaining).toFixed(2);row.setAttribute('data-auto-credit','1');updatePaymentSummary()});var s=row.querySelector('[data-saved-detail]');if(s)$(s).on('change',function(){applySavedDetail(row)})}
function applySavedDetail(row){var method=row.getAttribute('data-method'),s=row.querySelector('[data-saved-detail]'),idx=s?parseInt(s.value,10):NaN;if(isNaN(idx)||!(paymentHints[method]||[])[idx])return;var x=paymentHints[method][idx],p=row.querySelector('[data-pay-provider]'),r=row.querySelector('[data-pay-reference]');if(p)p.value=x.provider||'';if(r)r.value=x.reference||''}
function renumberPayments(){el('paymentRows').querySelectorAll('.ai-payment-row').forEach(function(row,i){row.querySelector('.ai-payment-no').textContent=i+1})}
function refreshPaymentRows(){el('paymentRows').querySelectorAll('.ai-payment-row').forEach(function(row){var detail=row.querySelector('[data-payment-detail]'),provider=row.querySelector('[data-pay-provider]'),reference=row.querySelector('[data-pay-reference]'),notes=row.querySelector('[data-pay-notes]'),old={provider:provider?provider.value:'',reference:reference?reference.value:'',notes:notes?notes.value:''};detail.innerHTML=paymentDetailHtml(row);provider=row.querySelector('[data-pay-provider]');reference=row.querySelector('[data-pay-reference]');notes=row.querySelector('[data-pay-notes]');if(provider)provider.value=old.provider;if(reference)reference.value=old.reference;if(notes)notes.value=old.notes;var s=row.querySelector('[data-saved-detail]');if(s)$(s).select2({width:'100%'}).on('change',function(){applySavedDetail(row)})})}
function remainingExcluding(exclude){var total=totals().total,allocated=0;el('paymentRows').querySelectorAll('.ai-payment-row').forEach(function(row){if(row===exclude)return;allocated+=Math.max(0,Number(row.querySelector('[data-pay-amount]').value||0))});return Math.round((total-allocated)*100)/100}
function syncAutoCredit(){var total=totals().total,rows=Array.prototype.slice.call(el('paymentRows').querySelectorAll('.ai-payment-row')),autoRows=rows.filter(function(r){return r.getAttribute('data-method')==='credit'&&r.getAttribute('data-auto-credit')==='1'});if(!autoRows.length)return;var auto=autoRows[0],other=0;rows.forEach(function(r){if(r===auto)return;other+=Math.max(0,Number(r.querySelector('[data-pay-amount]').value||0))});auto.querySelector('[data-pay-amount]').value=Math.max(0,Math.round((total-other)*100)/100).toFixed(2)}
function updateDueRequirement(){var hasCredit=false;el('paymentRows').querySelectorAll('.ai-payment-row').forEach(function(row){if(row.getAttribute('data-method')==='credit'&&Number(row.querySelector('[data-pay-amount]').value||0)>0.001)hasCredit=true});el('dueRequired').style.display=hasCredit?'inline':'none';el('dueDate').required=hasCredit;el('dueHint').textContent=hasCredit?'Required because this invoice has a Credit balance.':'Required automatically when Credit is used.'}
function paymentData(){var out=[];el('paymentRows').querySelectorAll('.ai-payment-row').forEach(function(row){var method=row.getAttribute('data-method')||'cash',amount=Math.max(0,Number(row.querySelector('[data-pay-amount]').value||0)),provider=row.querySelector('[data-pay-provider]'),reference=row.querySelector('[data-pay-reference]'),notes=row.querySelector('[data-pay-notes]');if(amount>0)out.push({method:method,amount:amount,provider:provider?provider.value.trim():'',reference:reference?reference.value.trim():'',notes:notes?notes.value.trim():''})});return out}
function updatePaymentSummary(){var t=totals().total,received=0,credit=0;paymentData().forEach(function(p){if(p.method==='credit')credit+=p.amount;else received+=p.amount});received=Math.round(received*100)/100;credit=Math.round(credit*100)/100;var allocated=Math.round((received+credit)*100)/100,remaining=Math.round((t-allocated)*100)/100;el('payReceived').textContent=money(received);el('payCredit').textContent=money(credit);el('payRemaining').textContent=money(Math.abs(remaining)<.005?0:remaining);el('payRemaining').className=Math.abs(remaining)<=.01?'good':'bad';var text;if(Math.abs(remaining)<=.01)text='<strong>Allocation complete.</strong> '+(credit>0?'Credit balance will remain outstanding until the due date.':'Invoice is fully allocated to received payments.');else if(remaining>0)text='<strong>Still to allocate:</strong> '+money(remaining)+'. Add a split or use Credit for the remaining balance.';else text='<strong>Over allocated:</strong> Reduce the split payments by '+money(Math.abs(remaining))+'.';el('paymentStatusText').innerHTML=text;updateDueRequirement()}

function serialize(){el('itemsJson').value=JSON.stringify(cart.map(function(x){return{product_service_id:x.product_service_id,item_name:x.item_name,description:x.description,quantity:Number(x.quantity),unit_cost:Number(x.unit_cost),unit_price:Number(x.unit_price),discount_amount:Number(x.discount_amount),tax_percent:Number(x.tax_percent)}}));el('paymentsJson').value=JSON.stringify(paymentData())}
function validate(){var mode=sourceMode();if(mode==='job'){if(!el('jobId').value){notify('warning','Select a Job Card.');return false}if(currentJob&&Number(currentJob.total_invoices||1)>1&&el('jobVisitWrap').style.display!=='none'&&!el('visitId').value){notify('warning','Select a billing visit for this recurring job.');return false}}else{if(!el('clientId').value){notify('warning','Select a customer.');return false}}if(!cart.length){notify('warning','Add at least one invoice item.');return false}for(var i=0;i<cart.length;i++){if(!String(cart[i].item_name||'').trim()){notify('warning','Invoice item '+(i+1)+' needs an item name.');return false}if(Number(cart[i].quantity)<=0){notify('warning','Invoice item '+(i+1)+' needs a valid quantity.');return false}}var t=totals();if(t.total<=0){notify('warning','Invoice total must be greater than zero.');return false}var p=paymentData(),allocated=0,credit=0;p.forEach(function(x){allocated+=x.amount;if(x.method==='credit')credit+=x.amount});if(Math.abs(allocated-t.total)>.01){notify('warning','Split payments must equal the invoice total.');return false}if(credit>0&&!el('dueDate').value){notify('warning','Select a due date for the Credit balance.');el('dueDate').focus();return false}return true}

function loadMeta(){var fd=new FormData();fd.append('action','form_meta');return request(fd).then(function(d){setMeta(d.meta||{});el('uniqueIndexWarning').classList.toggle('show',Number(meta.has_recurring_job_unique_index||0)===1);if(preJobId>0){document.querySelector('input[name="source_mode"][value="job"]').checked=true;updateSource();$('#jobId').val(String(preJobId)).trigger('change.select2');return loadJobContext(preJobId,0)}if(preClientId>0){document.querySelector('input[name="source_mode"][value="direct"]').checked=true;updateSource();$('#clientId').val(String(preClientId)).trigger('change.select2');applyClient(preLocationId)}return Promise.resolve()})}

el('issueDate').value=today();
document.querySelectorAll('input[name="source_mode"]').forEach(function(x){x.addEventListener('change',function(){updateSource()})});
$('#jobId').on('change',function(){loadJobContext(this.value,0)});
$('#visitId').on('change',function(){if(el('jobId').value&&this.value)loadJobContext(el('jobId').value,this.value)});
$('#clientId').on('change',function(){applyClient(0)});
el('addCatalogButton').addEventListener('click',addCatalog);el('addManualButton').addEventListener('click',addManual);el('addPaymentButton').addEventListener('click',function(){addPayment('cash',0,false)});
el('itemRows').addEventListener('input',function(e){var row=e.target.closest('tr[data-index]');if(!row||!e.target.matches('[data-field]'))return;var i=Number(row.getAttribute('data-index')),field=e.target.getAttribute('data-field');if(!cart[i])return;if(['quantity','unit_cost','unit_price','discount_amount','tax_percent'].indexOf(field)>=0)cart[i][field]=Math.max(field==='quantity'?.001:0,Number(e.target.value||0));else cart[i][field]=e.target.value;var totalCell=row.querySelector('.ai-line-total');if(totalCell)totalCell.textContent=money(lineCalc(cart[i]).total);updateTotals()});
el('itemRows').addEventListener('click',function(e){var b=e.target.closest('[data-remove-item]');if(!b)return;cart.splice(Number(b.getAttribute('data-remove-item')),1);renderItems()});

el('invoiceForm').addEventListener('submit',function(e){e.preventDefault();if(!this.reportValidity()){notify('warning','Complete the required invoice fields.');return}serialize();if(!validate())return;serialize();var fd=new FormData(this);fd.append('action','save');var b=el('saveButton');loading(b,true);request(fd).then(function(d){notify('success',d.message||'Invoice created successfully.');setTimeout(function(){window.location.href='invoice-view?invoice_id='+Number(d.invoice_id)},850)}).catch(function(err){notify('error',err.message)}).finally(function(){loading(b,false)})});

initSelect2('#visitId','Select billing visit');
addPayment('credit',0,true);
loadMeta().then(function(){updateSource();renderItems();syncAutoCredit();updatePaymentSummary()}).catch(function(e){notify('error',e.message)});
})();
</script>
</body>
</html>
