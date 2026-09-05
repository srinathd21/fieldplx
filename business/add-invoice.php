<?php
/* FieldPlx Add Invoice - Version 2.1.0 - 2026-09-06
 * Jobber-style invoice form with Job Card / Direct source, Services / Products / Manual items,
 * custom label/value fields, Images, Attachments and automatic customer email after creation.
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
            --ni-navy:#001131;
            --ni-green:#2f8d25;
            --ni-green-dark:#24751d;
            --ni-green-soft:#f2f8ee;
            --ni-text:#0b2b37;
            --ni-muted:#5f7380;
            --ni-border:#dce4e8;
            --ni-soft:#f8fafb;
            --ni-danger:#c74646;
        }
        body{background:#fff!important}
        .ni-page{width:100%;max-width:none;margin:0;background:#fff;padding:0 0 88px}
        .ni-form{background:#fff}
        .ni-top{padding:24px 28px 30px;border-bottom:1px solid var(--ni-border);background:#fff}
        .ni-heading{display:flex;align-items:center;gap:12px;margin-bottom:18px}
        .ni-heading i{color:#2b75ac;font-size:18px}
        .ni-heading h1{margin:0;color:var(--ni-text);font-size:20px;line-height:1.2;font-weight:700}
        .ni-subject{margin-bottom:14px}
        .ni-floating{position:relative}
        .ni-floating label{position:absolute;top:7px;left:13px;z-index:2;color:#647a87;font-size:9px;line-height:1;pointer-events:none}
        .ni-floating input,.ni-floating select,.ni-floating textarea{width:100%;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#163644;outline:0;font-family:inherit;font-size:12px}
        .ni-floating input,.ni-floating select{height:43px;padding:17px 12px 6px}
        .ni-floating textarea{min-height:96px;padding:21px 12px 10px;resize:vertical}
        .ni-floating input:focus,.ni-floating select:focus,.ni-floating textarea:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.09)}
        .ni-source-line{display:flex;align-items:center;gap:10px;margin:0 0 14px}
        .ni-source-label{color:#526a78;font-size:10px}
        .ni-segment{display:inline-flex;border:1px solid var(--ni-border);border-radius:8px;overflow:hidden;background:#fff}
        .ni-segment button{height:34px;padding:0 13px;border:0;border-right:1px solid var(--ni-border);background:#fff;color:#526a78;font:600 10px Arial,Helvetica,sans-serif;cursor:pointer}
        .ni-segment button:last-child{border-right:0}.ni-segment button.active{background:var(--ni-green-soft);color:var(--ni-green-dark)}
        .ni-top-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(420px,1fr);gap:18px}
        .ni-left,.ni-right{min-width:0}
        .ni-client-wrap,.ni-job-wrap{display:none}.ni-client-wrap.show,.ni-job-wrap.show{display:block}
        .ni-right-row{min-height:44px;display:grid;grid-template-columns:180px minmax(0,1fr);align-items:center;border-bottom:1px solid var(--ni-border)}
        .ni-right-row:last-child{border-bottom:0}.ni-right-label{color:#607582;font-size:10px}.ni-right-value{color:#153442;font-size:11px}
        .ni-right-value input,.ni-right-value select{width:100%;height:33px;padding:6px 10px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#153442;font-family:inherit;font-size:10px;outline:0}
        .ni-right-value input:focus,.ni-right-value select:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
        .ni-job-context{display:none;margin-top:10px;padding:10px 12px;border:1px solid #e3e9ec;border-radius:7px;background:#fbfcfc;color:#526b78;font-size:9.5px;line-height:1.55}.ni-job-context.show{display:block}.ni-job-context strong{color:#173846}
        .ni-location-row{margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .ni-section{padding:26px 28px;border-bottom:1px solid var(--ni-border);background:#fff}
        .ni-box{border:1px solid var(--ni-border);border-radius:8px;background:#fff;overflow:hidden}
        .ni-box-pad{padding:22px 20px}
        .ni-section-title{margin:0 0 17px;color:var(--ni-text);font-size:16px;font-weight:700}
        .ni-item-add{display:flex;align-items:center;gap:8px;margin-bottom:13px;flex-wrap:wrap}
        .ni-item-mode{display:inline-flex;border:1px solid var(--ni-border);border-radius:7px;overflow:hidden}
        .ni-item-mode button{height:34px;padding:0 12px;border:0;border-right:1px solid var(--ni-border);background:#fff;color:#566e7b;font-size:9px;font-weight:700;cursor:pointer}.ni-item-mode button:last-child{border-right:0}.ni-item-mode button.active{color:var(--ni-green-dark);background:var(--ni-green-soft)}
        .ni-item-select{min-width:300px;flex:1 1 360px}.ni-add-line{height:34px;padding:0 13px;border:1px solid var(--ni-green);border-radius:7px;color:#fff;background:var(--ni-green);font-size:9px;font-weight:700;cursor:pointer}.ni-add-line:hover{background:var(--ni-green-dark)}
        .ni-line{position:relative;padding:13px 14px 15px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;margin-bottom:10px}
        .ni-line-main{display:grid;grid-template-columns:minmax(260px,1fr) 110px 150px 150px 34px;gap:8px;align-items:start}
        .ni-line-extra{display:grid;grid-template-columns:1fr 120px 120px 120px;gap:8px;margin-top:8px}
        .ni-line input,.ni-line textarea{width:100%;border:1px solid var(--ni-border);border-radius:6px;background:#fff;color:#183845;font-family:inherit;font-size:10px;outline:0}.ni-line input{height:39px;padding:7px 9px}.ni-line textarea{min-height:74px;padding:9px;resize:vertical}.ni-line input:focus,.ni-line textarea:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.07)}
        .ni-labeled{position:relative}.ni-labeled span{position:absolute;top:5px;left:9px;color:#718692;font-size:7.5px;pointer-events:none}.ni-labeled input{padding-top:15px}
        .ni-line-total{height:39px;padding:15px 9px 5px;border:1px solid var(--ni-border);border-radius:6px;color:#183845;background:#fff;font-size:10px;font-weight:700;position:relative}.ni-line-total:before{content:'Total';position:absolute;top:5px;left:9px;color:#718692;font-size:7.5px;font-weight:400}
        .ni-remove{width:34px;height:34px;margin-top:2px;display:grid;place-items:center;border:0;border-radius:6px;color:#6c7f89;background:transparent;cursor:pointer}.ni-remove:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-empty{padding:26px;border:1px dashed #ccd7dd;border-radius:7px;color:#8797a0;text-align:center;font-size:10px}
        .ni-summary{margin-top:18px;padding-top:18px;border-top:2px solid #e2e7ea;display:grid;grid-template-columns:1fr minmax(420px,52%);gap:20px}
        .ni-client-view{display:flex;align-items:flex-start;gap:10px;color:#516a77;font-size:9.5px}.ni-client-view i{font-size:16px;color:#3b5966}.ni-client-view a{color:var(--ni-green-dark);text-decoration:underline!important}
        .ni-totals{display:grid;gap:0}.ni-total-row{min-height:43px;padding:0 0;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--ni-border);color:#445f6d;font-size:10px}.ni-total-row strong{color:#183845;font-size:10px}.ni-total-row.grand{min-height:48px;border-bottom:3px solid #e1e6e9;font-weight:700}.ni-total-row.grand strong{font-size:16px}.ni-total-row.balance{margin-top:14px;padding:0 12px;border:0;border-radius:6px;background:#faf9f7}.ni-total-link{border:0;background:transparent;color:var(--ni-green-dark);font:600 9px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}
        .ni-add-section{padding:12px 28px 0;background:#fff}.ni-add-section-inner{display:inline-flex;align-items:center;gap:7px;padding:7px 9px;border-radius:8px;background:#f1f0ed}.ni-add-section-inner span{color:#3e5966;font-size:9px}.ni-section-chip{height:29px;padding:0 11px;border:1px solid #d5dde1;border-radius:6px;background:#fff;color:#24424f;font-size:9px;font-weight:600;cursor:pointer}.ni-section-chip.active{color:var(--ni-green-dark);border-color:#b9d9ab;background:#f7fbf5}
        .ni-extra-section{display:none;padding:20px 28px 0;background:#fff}.ni-extra-section.show{display:block}.ni-extra-card{position:relative;padding:21px 20px;border:1px solid var(--ni-border);border-radius:8px;background:#fff}.ni-extra-card h2{margin:0 0 16px;color:var(--ni-text);font-size:15px}.ni-extra-remove{position:absolute;top:17px;right:18px;width:30px;height:30px;border:0;border-radius:6px;background:transparent;color:#46616e;cursor:pointer}.ni-extra-remove:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-customize-row .ni-right-value{display:flex;justify-content:flex-start}.ni-custom-add{height:31px;padding:0 11px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:var(--ni-green-dark);font-size:9px;font-weight:700;cursor:pointer}.ni-custom-add:hover{border-color:#b9d9ab;background:var(--ni-green-soft)}
        .ni-custom-fields{grid-column:1/-1;display:grid;gap:7px;padding:8px 0 4px}.ni-custom-field{position:relative;display:grid;grid-template-columns:1fr 1fr 31px;gap:7px;align-items:center}.ni-custom-field input{width:100%;height:36px;padding:7px 9px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#183845;font-family:inherit;font-size:9.5px;outline:0}.ni-custom-field input:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}.ni-custom-field button{width:31px;height:31px;border:0;border-radius:6px;background:transparent;color:#718692;cursor:pointer}.ni-custom-field button:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-upload-copy{margin:0 0 11px;color:var(--ni-muted);font-size:9.5px}.ni-upload-counter{position:absolute;right:52px;top:23px;color:#718692;font-size:9px}.ni-upload-drop{min-height:74px;padding:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;border:1px dashed #cbd7dd;border-radius:7px;background:#fff;color:#708490;font-size:9px;text-align:center}.ni-upload-btn{height:31px;padding:0 12px;border:1px solid #d5dde1;border-radius:6px;background:#fff;color:var(--ni-green-dark);font-size:9px;font-weight:700;cursor:pointer}.ni-upload-btn:hover{border-color:#b8d89e;background:#f7fbf5}.ni-file-list{margin-top:10px;display:grid;gap:7px}.ni-file-row{min-height:38px;padding:7px 10px;display:flex;align-items:center;gap:8px;border:1px solid #e4eaee;border-radius:7px;background:#fbfcfc;color:#425e6b;font-size:9.5px}.ni-file-row i{color:#607d89}.ni-file-row span{min-width:0;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.ni-file-row small{color:#899aa3}.ni-file-remove{width:28px;height:28px;border:0;border-radius:6px;background:transparent;color:#718692;cursor:pointer}.ni-file-remove:hover{color:var(--ni-danger);background:#fff1f1}
        .ni-notes{padding:22px 28px 28px}.ni-notes h2{margin:0 0 12px;color:var(--ni-text);font-size:16px}.ni-notes textarea{width:100%;min-height:125px;padding:13px;border:1px dashed #cbd7dd;border-radius:8px;background:#fff;color:#183845;font-family:inherit;font-size:10px;resize:vertical;outline:0}.ni-notes textarea:focus{border-style:solid;border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
        .ni-savebar{position:fixed;left:var(--fieldplx-sidebar-width);right:0;bottom:0;z-index:1025;height:64px;padding:10px 28px;display:flex;align-items:center;justify-content:flex-end;gap:8px;border-top:1px solid var(--ni-border);background:rgba(255,255,255,.98);box-shadow:0 -4px 12px rgba(0,17,49,.04)}body.fieldplx-sidebar-collapsed .ni-savebar{left:var(--fieldplx-sidebar-collapsed-width)}
        .ni-btn{height:38px;padding:0 14px;border:1px solid var(--ni-border);border-radius:7px;background:#fff;color:#31505d;font-size:10px;font-weight:700;cursor:pointer}.ni-btn.primary{border-color:var(--ni-green);background:var(--ni-green);color:#fff}.ni-btn.primary:hover{background:var(--ni-green-dark)}.ni-btn:disabled{opacity:.55;cursor:not-allowed}
        .ni-toast{position:fixed;top:82px;right:18px;z-index:14000;width:min(390px,calc(100vw - 36px));padding:12px 14px;border-radius:8px;color:#fff;background:#1f5f7a;box-shadow:0 12px 30px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s;font-size:10px;font-weight:700}.ni-toast.show{opacity:1;transform:translateY(0)}.ni-toast.error{background:#c94f55}.ni-toast.success{background:#2f8d25}.ni-toast.warning{background:#9a741a}
        .select2-container{width:100%!important}.select2-container .select2-selection--single{height:43px!important;border:1px solid var(--ni-border)!important;border-radius:7px!important;background:#fff!important}.select2-container .select2-selection--single .select2-selection__rendered{height:41px!important;line-height:41px!important;padding-left:12px!important;padding-right:28px!important;color:#183845!important;font-size:10px!important}.select2-container .select2-selection--single .select2-selection__arrow{height:41px!important}.select2-container--focus .select2-selection--single,.select2-container--open .select2-selection--single{border-color:#91bd7e!important;box-shadow:0 0 0 2px rgba(47,141,37,.08)!important}.select2-dropdown{border:1px solid var(--ni-border)!important;border-radius:7px!important;overflow:hidden!important;box-shadow:0 12px 24px rgba(0,17,49,.12)!important}.select2-search__field{height:34px!important;border:1px solid var(--ni-border)!important;border-radius:6px!important;font-size:10px!important}.select2-results__option{padding:8px 10px!important;font-size:9.5px!important}.select2-results__option--highlighted[aria-selected]{background:#eaf5e5!important;color:#244f1c!important}
        @media(max-width:991.98px){.ni-savebar,body.fieldplx-sidebar-collapsed .ni-savebar{left:0}.ni-top-grid{grid-template-columns:1fr}.ni-right-row{grid-template-columns:150px 1fr}.ni-summary{grid-template-columns:1fr}.ni-line-main{grid-template-columns:minmax(220px,1fr) 90px 120px 120px 34px}.ni-line-extra{grid-template-columns:1fr 100px 100px 100px}}
        @media(max-width:767.98px){.ni-top,.ni-section,.ni-notes{padding-left:14px;padding-right:14px}.ni-add-section,.ni-extra-section{padding-left:14px;padding-right:14px}.ni-location-row{grid-template-columns:1fr}.ni-right-row{grid-template-columns:120px 1fr}.ni-line-main,.ni-line-extra{grid-template-columns:1fr 1fr}.ni-line-main .ni-line-name{grid-column:1/-1}.ni-line-main .ni-remove{position:absolute;top:11px;right:10px}.ni-line-extra textarea{grid-column:1/-1}.ni-summary{grid-template-columns:1fr}.ni-item-select{min-width:0;flex-basis:100%}.ni-savebar{padding-left:14px;padding-right:14px}}
        @media(max-width:575.98px){.ni-line-main,.ni-line-extra{grid-template-columns:1fr}.ni-right-row{grid-template-columns:1fr;gap:4px;padding:8px 0}.ni-custom-field{grid-template-columns:1fr}.ni-custom-field button{position:absolute;right:0}.ni-item-add{display:grid;grid-template-columns:1fr}.ni-item-mode{width:max-content}.ni-summary{gap:14px}.ni-source-line{align-items:flex-start;flex-direction:column}.ni-segment{width:100%}.ni-segment button{flex:1}}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="ni-page">
                <form id="invoiceForm" class="ni-form" autocomplete="off">
                    <input type="hidden" name="items_json" id="itemsJson" value="[]">
                    <input type="hidden" name="source_mode" id="sourceMode" value="direct">
                    <input type="hidden" name="branch_id" id="branchId" value="">
                    <input type="hidden" name="custom_fields_json" id="customFieldsJson" value="[]">

                    <section class="ni-top">
                        <div class="ni-heading"><i class="bi bi-file-earmark-text"></i><h1>New Invoice</h1></div>
                        <div class="ni-subject ni-floating">
                            <label for="invoiceSubject">Subject</label>
                            <input id="invoiceSubject" name="subject" type="text" maxlength="190" value="For Services Rendered">
                        </div>

                        <div class="ni-source-line">
                            <span class="ni-source-label">Invoice source</span>
                            <div class="ni-segment" id="sourceButtons">
                                <button type="button" data-source="job">From Job Card</button>
                                <button type="button" class="active" data-source="direct">Direct Invoice</button>
                            </div>
                        </div>

                        <div class="ni-top-grid">
                            <div class="ni-left">
                                <div class="ni-job-wrap" id="jobSourcePanel">
                                    <select id="jobId" name="job_id"><option value=""></option></select>
                                    <div class="ni-job-context" id="jobContext"></div>
                                    <div id="jobVisitWrap" style="display:none;margin-top:10px"><select id="visitId" name="visit_id"><option value=""></option></select></div>
                                </div>
                                <div class="ni-client-wrap show" id="directSourcePanel">
                                    <select id="clientId" name="client_id"><option value=""></option></select>
                                    <div class="ni-location-row">
                                        <select id="locationId" name="location_id"><option value=""></option></select>
                                        <div class="ni-floating"><label>Customer email</label><input id="customerEmail" type="text" readonly></div>
                                    </div>
                                </div>
                            </div>
                            <div class="ni-right">
                                <div class="ni-right-row"><div class="ni-right-label">Invoice #</div><div class="ni-right-value"><input type="text" value="Auto" readonly></div></div>
                                <div class="ni-right-row"><div class="ni-right-label">Issued date</div><div class="ni-right-value"><input type="date" id="issueDate" name="issue_date" required></div></div>
                                <div class="ni-right-row"><div class="ni-right-label">Payment terms</div><div class="ni-right-value"><select id="paymentTermsPreset"><option value="0">Due upon receipt</option><option value="7">Net 7</option><option value="15">Net 15</option><option value="30">Net 30</option><option value="45">Net 45</option><option value="custom">Custom</option></select><input type="hidden" id="paymentTerms" name="payment_terms" value="Due upon receipt"></div></div>
                                <div class="ni-right-row" id="dueDateRow" style="display:none"><div class="ni-right-label">Due date</div><div class="ni-right-value"><input type="date" id="dueDate" name="due_date"></div></div>
                                <div class="ni-right-row"><div class="ni-right-label">Salesperson</div><div class="ni-right-value"><span id="salespersonName">Current user</span></div></div>
                                <div class="ni-right-row ni-customize-row"><div class="ni-right-label">Customize</div><div class="ni-right-value"><button type="button" class="ni-custom-add" id="addCustomField"><i class="bi bi-plus-lg"></i> Add Field</button></div></div>
                                <div id="customFields" class="ni-custom-fields"></div>
                            </div>
                        </div>
                    </section>

                    <section class="ni-section">
                        <div class="ni-box">
                            <div class="ni-box-pad">
                                <h2 class="ni-section-title">Product / Service</h2>
                                <div class="ni-item-add">
                                    <div class="ni-item-mode" id="itemModeButtons">
                                        <button type="button" class="active" data-item-mode="service">Service</button>
                                        <button type="button" data-item-mode="product">Product</button>
                                        <button type="button" data-item-mode="manual">Manual</button>
                                    </div>
                                    <div class="ni-item-select" id="serviceSelectWrap"><select id="serviceSelect"><option value=""></option></select></div>
                                    <div class="ni-item-select" id="productSelectWrap" style="display:none"><select id="productSelect"><option value=""></option></select></div>
                                    <button type="button" class="ni-add-line" id="addLineButton"><i class="bi bi-plus-lg"></i> Add Line Item</button>
                                </div>
                                <div id="itemRows"><div class="ni-empty">Add a service, product or manual line item.</div></div>

                                <div class="ni-summary">
                                    <div class="ni-client-view"><i class="bi bi-eye"></i><div><span>Customer view</span><br><a href="#" id="changeCustomerLink">Change</a></div></div>
                                    <div class="ni-totals">
                                        <div class="ni-total-row"><span>Subtotal</span><strong id="sumSubtotal">0.00</strong></div>
                                        <div class="ni-total-row"><span>Discount</span><button type="button" class="ni-total-link" id="discountLink">Add Discount</button><strong id="sumDiscount" style="display:none">0.00</strong></div>
                                        <div class="ni-total-row"><span>Tax</span><button type="button" class="ni-total-link" id="taxLink">Add Tax</button><strong id="sumTax" style="display:none">0.00</strong></div>
                                        <div class="ni-total-row grand"><span>Total</span><strong id="sumTotal">0.00</strong></div>
                                        <div class="ni-total-row balance"><span>Invoice balance</span><strong id="sumBalance">0.00</strong></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="ni-add-section">
                        <div class="ni-add-section-inner"><span><i class="bi bi-plus-lg"></i> Add section</span><button type="button" class="ni-section-chip" data-section-toggle="clientMessageSection">Client Message</button><button type="button" class="ni-section-chip" data-section-toggle="imagesSection">Images</button><button type="button" class="ni-section-chip" data-section-toggle="attachmentsSection">Attachments</button><button type="button" class="ni-section-chip active" data-section-toggle="contractSection">Contract / Disclaimer</button></div>
                    </div>

                    <section class="ni-extra-section" id="clientMessageSection">
                        <div class="ni-extra-card"><button type="button" class="ni-extra-remove" data-remove-section="clientMessageSection"><i class="bi bi-trash"></i></button><h2>Client Message</h2><div class="ni-floating"><label>Description</label><textarea name="client_message" id="clientMessage"></textarea></div></div>
                    </section>

                    <section class="ni-extra-section show" id="contractSection">
                        <div class="ni-extra-card"><button type="button" class="ni-extra-remove" data-remove-section="contractSection"><i class="bi bi-trash"></i></button><h2>Contract / Disclaimer</h2><div class="ni-floating"><label>Description</label><textarea name="contract_disclaimer" id="contractDisclaimer">Thank you for your business. Please contact us with any questions regarding this invoice.</textarea></div></div>
                    </section>

                    <section class="ni-extra-section" id="imagesSection">
                        <div class="ni-extra-card">
                            <button type="button" class="ni-extra-remove" data-remove-section="imagesSection"><i class="bi bi-trash"></i></button>
                            <h2>Images</h2>
                            <p class="ni-upload-copy">Add images from before and after the job</p>
                            <div class="ni-upload-counter" id="imageCounter">0 of 10 uploaded</div>
                            <div class="ni-upload-drop">
                                <input type="file" id="imageInput" accept="image/avif,image/jpeg,image/png,image/webp,image/heic,.heic" multiple hidden>
                                <button type="button" class="ni-upload-btn" data-pick-file="imageInput">Add Images</button>
                                <span>AVIF, JPEG, PNG, WEBP, HEIC up to 25MB each</span>
                            </div>
                            <div class="ni-file-list" id="imageList"></div>
                        </div>
                    </section>

                    <section class="ni-extra-section" id="attachmentsSection">
                        <div class="ni-extra-card">
                            <button type="button" class="ni-extra-remove" data-remove-section="attachmentsSection"><i class="bi bi-trash"></i></button>
                            <h2>Attachments</h2>
                            <p class="ni-upload-copy">Include all attachments for your invoice in one place</p>
                            <div class="ni-upload-counter" id="attachmentCounter">0 of 10 uploaded</div>
                            <div class="ni-upload-drop">
                                <input type="file" id="attachmentInput" accept=".avif,.jpg,.jpeg,.png,.webp,.heic,.pdf,.doc,.docx" multiple hidden>
                                <button type="button" class="ni-upload-btn" data-pick-file="attachmentInput">Select Files</button>
                                <span>AVIF, JPEG, PNG, WEBP, HEIC, PDF, DOCX up to 50MB each</span>
                            </div>
                            <div class="ni-file-list" id="attachmentList"></div>
                        </div>
                    </section>

                    <section class="ni-notes">
                        <h2>Notes</h2>
                        <textarea name="notes" id="invoiceNotes" placeholder="Leave an internal note for yourself or a team member"></textarea>
                    </section>

                    <div class="ni-savebar">
                        <button type="button" class="ni-btn" id="cancelButton">Cancel</button>
                        <button type="submit" class="ni-btn primary" id="saveButton">Save Invoice</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<div class="ni-toast" id="toast">Notification</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
'use strict';
var csrfToken=<?= json_encode($invoiceFormCsrfToken) ?>;
var preJobId=<?= (int)$preJobId ?>,preClientId=<?= (int)$preClientId ?>,preLocationId=<?= (int)$preLocationId ?>;
var meta={clients:[],locations:[],branches:[],jobs:[],services:[],products:[],currency:{},current_user:{}};
var cart=[],source='direct',itemMode='service',currentJob=null,customFields=[],pendingFiles={image:[],attachment:[]},toastTimer=null;
function E(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function title(v){return String(v||'').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
function toast(type,msg){var t=E('toast');if(toastTimer)clearTimeout(toastTimer);t.className='ni-toast '+(type||'')+' show';t.textContent=msg||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},3200)}
function parse(r){return r.text().then(function(raw){var d;try{d=raw?JSON.parse(raw):{}}catch(e){throw new Error(raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d})}
function request(fd){fd.append('csrf_token',csrfToken);return fetch('api/invoice-form.php',{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parse)}
function money(v){var c=meta.currency||{},p=parseInt(c.decimal_places,10);if(isNaN(p))p=2;var n=Number(v||0).toFixed(p),s=c.symbol||'';return c.symbol_position==='after'?n+(s?' '+s:''):(s||'')+n}
function today(){var d=new Date(),o=d.getTimezoneOffset();d=new Date(d.getTime()-o*60000);return d.toISOString().slice(0,10)}
function initSelect(id,placeholder,opts){opts=opts||{};var cfg={width:'100%',placeholder:placeholder||'',allowClear:true};Object.keys(opts).forEach(function(k){cfg[k]=opts[k]});$('#'+id).select2(cfg)}
function resetSelect(id,html,placeholder,opts){var $n=$('#'+id);if($n.hasClass('select2-hidden-accessible'))$n.select2('destroy');E(id).innerHTML=html;initSelect(id,placeholder,opts)}
function setSource(next){source=next==='job'?'job':'direct';E('sourceMode').value=source;document.querySelectorAll('[data-source]').forEach(function(b){b.classList.toggle('active',b.getAttribute('data-source')===source)});E('jobSourcePanel').classList.toggle('show',source==='job');E('directSourcePanel').classList.toggle('show',source==='direct');if(source==='job'&&E('jobId').value)loadJob(E('jobId').value,E('visitId').value);updateCustomerView()}
function setItemMode(next){itemMode=['service','product','manual'].indexOf(next)>=0?next:'service';document.querySelectorAll('[data-item-mode]').forEach(function(b){b.classList.toggle('active',b.getAttribute('data-item-mode')===itemMode)});E('serviceSelectWrap').style.display=itemMode==='service'?'block':'none';E('productSelectWrap').style.display=itemMode==='product'?'block':'none'}
function setMeta(m){meta=m||meta;var jobs='<option value=""></option>';meta.jobs.forEach(function(j){jobs+='<option value="'+Number(j.id)+'">'+esc(j.job_no+' - '+j.client_name+' - '+(j.title||j.service_name||''))+'</option>'});resetSelect('jobId',jobs,'Select a Job Card');var clients='<option value=""></option>';meta.clients.forEach(function(c){clients+='<option value="'+Number(c.id)+'">'+esc(c.name+(c.company_name?' - '+c.company_name:'')+(c.phone?' - '+c.phone:''))+'</option>'});resetSelect('clientId',clients,'Select a customer');var services='<option value=""></option>';meta.services.forEach(function(s){services+='<option value="'+Number(s.id)+'">'+esc(s.name+' - '+money(s.unit_price))+'</option>'});resetSelect('serviceSelect',services,'Select service');var products='<option value=""></option>';meta.products.forEach(function(p){products+='<option value="'+Number(p.id)+'">'+esc(p.name+' - '+money(p.selling_price))+'</option>'});resetSelect('productSelect',products,'Select product or type a new product',{tags:true,createTag:function(params){var term=$.trim(params.term||'');if(!term)return null;return{id:'new:'+term,text:term,newTag:true}}});E('salespersonName').textContent=(meta.current_user&&meta.current_user.name)?meta.current_user.name:'Current user';filterLocations(0,0)}
function filterLocations(clientId,selected){var html='<option value=""></option>';meta.locations.filter(function(x){return Number(x.client_id)===Number(clientId||0)}).forEach(function(x){var a=[x.address_line1,x.city,x.state].filter(Boolean).join(', ');html+='<option value="'+Number(x.id)+'">'+esc(x.name+(a?' - '+a:''))+'</option>'});resetSelect('locationId',html,'Service location');if(Number(selected)>0)$('#locationId').val(String(selected)).trigger('change.select2')}
function currentClient(){var id=source==='job'&&currentJob?Number(currentJob.client_id):Number(E('clientId').value||0);return meta.clients.find(function(c){return Number(c.id)===id})||null}
function applyClient(selectedLocation){var c=currentClient();if(source==='direct')filterLocations(c?c.id:0,selectedLocation||0);E('customerEmail').value=c&&c.email?c.email:'';if(c&&c.branch_id)E('branchId').value=String(c.branch_id);updateCustomerView()}
function updateCustomerView(){var c=currentClient(),link=E('changeCustomerLink');link.textContent=c?c.name:'Change';link.onclick=function(e){e.preventDefault();if(source==='job'){$('#jobId').select2('open')}else{$('#clientId').select2('open')}}}
function renderCustomFields(){var box=E('customFields');box.innerHTML=customFields.map(function(x,i){return '<div class="ni-custom-field"><input data-custom-f="label" data-custom-i="'+i+'" value="'+esc(x.label||'')+'" maxlength="190" placeholder="Field name"><input data-custom-f="value" data-custom-i="'+i+'" value="'+esc(x.value||'')+'" maxlength="1000" placeholder="Value"><button type="button" data-remove-custom="'+i+'" title="Remove"><i class="bi bi-x-lg"></i></button></div>'}).join('');E('customFieldsJson').value=JSON.stringify(customFields)}
function fileSize(n){n=Number(n||0);if(n>=1024*1024)return (n/(1024*1024)).toFixed(1)+' MB';if(n>=1024)return Math.round(n/1024)+' KB';return n+' B'}
function renderPendingFiles(){function draw(cat,listId,counterId){var a=pendingFiles[cat]||[],box=E(listId);box.innerHTML=a.map(function(f,i){return '<div class="ni-file-row"><i class="bi bi-paperclip"></i><span>'+esc(f.name)+' <small>('+esc(fileSize(f.size))+')</small></span><button type="button" class="ni-file-remove" data-remove-file="'+cat+'" data-file-index="'+i+'" title="Remove"><i class="bi bi-x-lg"></i></button></div>'}).join('');E(counterId).textContent=a.length+' of 10 uploaded'}draw('image','imageList','imageCounter');draw('attachment','attachmentList','attachmentCounter')}
function queueFiles(input,cat){var files=Array.prototype.slice.call(input.files||[]),max=cat==='image'?25*1024*1024:50*1024*1024,allowedImage=['avif','jpg','jpeg','png','webp','heic'],allowedAttachment=['avif','jpg','jpeg','png','webp','heic','pdf','doc','docx'],allowed=cat==='image'?allowedImage:allowedAttachment,current=pendingFiles[cat].length;files.forEach(function(f){if(current>=10){toast('warning','Maximum 10 '+(cat==='image'?'images':'attachments')+' allowed.');return}var ext=(f.name.split('.').pop()||'').toLowerCase();if(allowed.indexOf(ext)<0){toast('warning',f.name+' has an unsupported file type.');return}if(Number(f.size||0)<=0||Number(f.size)>max){toast('warning',f.name+' exceeds the '+(cat==='image'?'25':'50')+'MB limit.');return}pendingFiles[cat].push(f);current++});input.value='';renderPendingFiles()}
function appendPendingFiles(fd){pendingFiles.image.forEach(function(f){fd.append('invoice_images[]',f,f.name)});pendingFiles.attachment.forEach(function(f){fd.append('invoice_attachments[]',f,f.name)})}
function resetJob(){currentJob=null;E('jobContext').classList.remove('show');E('jobContext').innerHTML='';E('jobVisitWrap').style.display='none';cart=[];renderItems();updateCustomerView()}
function renderJob(d){currentJob=d.job||null;if(!currentJob){resetJob();return}E('branchId').value=currentJob.branch_id||'';var text='<strong>'+esc(currentJob.job_no||'Job')+'</strong> &nbsp; '+esc(currentJob.client_name||'')+(currentJob.location_name?' &nbsp; - &nbsp; '+esc(currentJob.location_name):'')+(currentJob.quote_no?' &nbsp; - &nbsp; '+esc(currentJob.quote_no):'');E('jobContext').innerHTML=text;E('jobContext').classList.add('show');var slots=Array.isArray(d.billing_slots)?d.billing_slots:[],available=slots.filter(function(x){return Number(x.invoiced||0)===0}),html='<option value=""></option>';available.forEach(function(s){html+='<option value="'+(s.visit_id?Number(s.visit_id):'')+'">'+esc((s.visit_no||('Visit '+s.visit_number))+(s.scheduled_start?' - '+String(s.scheduled_start).slice(0,16):''))+'</option>'});resetSelect('visitId',html,'Select billing visit');E('jobVisitWrap').style.display=(Number(currentJob.total_invoices||1)>1||available.length>1)?'block':'none';if(available.length===1&&available[0].visit_id)$('#visitId').val(String(available[0].visit_id)).trigger('change.select2');cart=(d.items||[]).map(normalize);renderItems();applyClient(0)}
function loadJob(id,visitId){id=Number(id||0);if(id<=0){resetJob();return Promise.resolve()}var fd=new FormData();fd.append('action','job_context');fd.append('job_id',id);if(Number(visitId)>0)fd.append('visit_id',visitId);return request(fd).then(function(d){renderJob(d);return d}).catch(function(e){resetJob();toast('error',e.message);throw e})}
function normalize(x){var src=String(x&&x.item_source||((x&&x.product_id)?'product':((x&&x.product_service_id)?'service':'manual')));if(src==='product_service')src='service';return{product_service_id:Number(x&&x.product_service_id||0)||null,product_id:Number(x&&x.product_id||0)||null,item_source:src,new_product_name:String(x&&x.new_product_name||''),item_name:String(x&&x.item_name||''),description:String(x&&x.description||''),quantity:Math.max(.001,Number(x&&x.quantity||1)),unit_cost:Math.max(0,Number(x&&x.unit_cost||0)),unit_price:Math.max(0,Number(x&&x.unit_price||0)),discount_amount:Math.max(0,Number(x&&x.discount_amount||0)),tax_percent:Math.max(0,Number(x&&x.tax_percent||0)),service_date:String(x&&x.service_date||'')}}
function calc(x){var base=Math.round(x.quantity*x.unit_price*100)/100,disc=Math.min(base,Math.max(0,x.discount_amount)),taxable=Math.max(0,base-disc),tax=Math.round(taxable*x.tax_percent)/100,total=Math.round((taxable+tax)*100)/100;return{base:base,discount:disc,tax:tax,total:total}}
function totals(){var o={subtotal:0,discount:0,tax:0,total:0};cart.forEach(function(x){var c=calc(x);o.subtotal+=c.base;o.discount+=c.discount;o.tax+=c.tax;o.total+=c.total});Object.keys(o).forEach(function(k){o[k]=Math.round(o[k]*100)/100});return o}
function renderItems(){var w=E('itemRows');if(!cart.length){w.innerHTML='<div class="ni-empty">Add a service, product or manual line item.</div>';updateTotals();return}var h='';cart.forEach(function(x,i){var c=calc(x);h+='<div class="ni-line" data-index="'+i+'"><div class="ni-line-main"><input class="ni-line-name" data-f="item_name" value="'+esc(x.item_name)+'" placeholder="Name"><div class="ni-labeled"><span>Quantity</span><input data-f="quantity" type="number" min="0.001" step="0.001" value="'+esc(x.quantity)+'"></div><div class="ni-labeled"><span>Unit price</span><input data-f="unit_price" type="number" min="0" step="0.01" value="'+esc(x.unit_price)+'"></div><div class="ni-line-total">'+esc(money(c.total))+'</div><button type="button" class="ni-remove" data-remove="'+i+'" title="Remove"><i class="bi bi-trash"></i></button></div><div class="ni-line-extra"><textarea data-f="description" placeholder="Description">'+esc(x.description)+'</textarea><div class="ni-labeled"><span>Unit cost</span><input data-f="unit_cost" type="number" min="0" step="0.01" value="'+esc(x.unit_cost)+'"></div><div class="ni-labeled"><span>Discount</span><input data-f="discount_amount" type="number" min="0" step="0.01" value="'+esc(x.discount_amount)+'"></div><div class="ni-labeled"><span>Tax %</span><input data-f="tax_percent" type="number" min="0" step="0.01" value="'+esc(x.tax_percent)+'"></div></div></div>'});w.innerHTML=h;updateTotals()}
function updateTotals(){var t=totals();E('sumSubtotal').textContent=money(t.subtotal);E('sumDiscount').textContent=money(t.discount);E('sumTax').textContent=money(t.tax);E('sumTotal').textContent=money(t.total);E('sumBalance').textContent=money(t.total);E('sumDiscount').style.display=t.discount>0?'inline':'none';E('discountLink').style.display=t.discount>0?'none':'inline';E('sumTax').style.display=t.tax>0?'inline':'none';E('taxLink').style.display=t.tax>0?'none':'inline'}
function addSelected(){if(itemMode==='manual'){cart.push(normalize({item_source:'manual',quantity:1}));renderItems();focusLast();return}if(itemMode==='service'){var id=Number(E('serviceSelect').value||0),x=meta.services.find(function(s){return Number(s.id)===id});if(!x){toast('warning','Select a service.');return}cart.push(normalize({product_service_id:x.id,item_source:'service',item_name:x.name,description:x.description,quantity:1,unit_cost:x.unit_cost,unit_price:x.unit_price,tax_percent:x.tax_percent}));$('#serviceSelect').val(null).trigger('change');renderItems();return}var raw=String(E('productSelect').value||'');if(!raw){toast('warning','Select a product or type a new product.');return}if(raw.indexOf('new:')===0){var name=raw.slice(4).trim();cart.push(normalize({item_source:'product',new_product_name:name,item_name:name,quantity:1}));$('#productSelect').val(null).trigger('change');renderItems();return}var idp=Number(raw),p=meta.products.find(function(x){return Number(x.id)===idp});if(!p){toast('warning','Select a valid product.');return}cart.push(normalize({product_id:p.id,item_source:'product',item_name:p.name,description:p.description,quantity:1,unit_cost:p.base_unit_price,unit_price:p.selling_price,tax_percent:p.tax_percent}));$('#productSelect').val(null).trigger('change');renderItems()}
function focusLast(){var rows=E('itemRows').querySelectorAll('.ni-line');if(rows.length){var x=rows[rows.length-1].querySelector('[data-f="item_name"]');if(x)x.focus()}}
function syncTerms(){var v=E('paymentTermsPreset').value,issue=E('issueDate').value||today(),d=new Date(issue+'T00:00:00');if(v==='custom'){E('dueDateRow').style.display='grid';E('paymentTerms').value='Custom';return}var days=Number(v||0);d.setDate(d.getDate()+days);E('dueDate').value=d.toISOString().slice(0,10);E('dueDateRow').style.display='none';E('paymentTerms').value=days===0?'Due upon receipt':'Net '+days}
function serialize(){E('itemsJson').value=JSON.stringify(cart.map(function(x){return{product_service_id:x.product_service_id,product_id:x.product_id,item_source:x.item_source,new_product_name:x.new_product_name,item_name:x.item_name,description:x.description,quantity:Number(x.quantity),unit_cost:Number(x.unit_cost),unit_price:Number(x.unit_price),discount_amount:Number(x.discount_amount),tax_percent:Number(x.tax_percent),service_date:x.service_date}}));E('customFieldsJson').value=JSON.stringify(customFields.filter(function(x){return String(x.label||'').trim()!==''||String(x.value||'').trim()!==''}))}
function validate(){if(source==='job'){if(!E('jobId').value){toast('warning','Select a Job Card.');return false}if(currentJob&&Number(currentJob.total_invoices||1)>1&&E('jobVisitWrap').style.display!=='none'&&!E('visitId').value){toast('warning','Select the billing visit.');return false}}else if(!E('clientId').value){toast('warning','Select a customer.');return false}if(!cart.length){toast('warning','Add at least one invoice item.');return false}for(var i=0;i<cart.length;i++){if(!cart[i].item_name.trim()){toast('warning','Line item '+(i+1)+' needs a name.');return false}if(Number(cart[i].quantity)<=0){toast('warning','Line item '+(i+1)+' needs a valid quantity.');return false}}if(totals().total<=0){toast('warning','Invoice total must be greater than zero.');return false}if(!E('dueDate').value){toast('warning','Select a due date.');return false}return true}
function loadMeta(){var fd=new FormData();fd.append('action','form_meta');return request(fd).then(function(d){setMeta(d.meta||{});if(preJobId>0){setSource('job');$('#jobId').val(String(preJobId)).trigger('change.select2');return loadJob(preJobId,0)}if(preClientId>0){setSource('direct');$('#clientId').val(String(preClientId)).trigger('change.select2');applyClient(preLocationId)}return Promise.resolve()})}
E('issueDate').value=today();syncTerms();
document.querySelectorAll('[data-source]').forEach(function(b){b.addEventListener('click',function(){setSource(this.getAttribute('data-source'))})});
document.querySelectorAll('[data-item-mode]').forEach(function(b){b.addEventListener('click',function(){setItemMode(this.getAttribute('data-item-mode'))})});
$('#jobId').on('change',function(){loadJob(this.value,0)});$('#visitId').on('change',function(){if(E('jobId').value&&this.value)loadJob(E('jobId').value,this.value)});$('#clientId').on('change',function(){applyClient(0)});
E('paymentTermsPreset').addEventListener('change',syncTerms);E('issueDate').addEventListener('change',syncTerms);E('addLineButton').addEventListener('click',addSelected);
E('itemRows').addEventListener('input',function(e){var r=e.target.closest('.ni-line');if(!r||!e.target.matches('[data-f]'))return;var i=Number(r.getAttribute('data-index')),f=e.target.getAttribute('data-f');if(!cart[i])return;if(['quantity','unit_cost','unit_price','discount_amount','tax_percent'].indexOf(f)>=0)cart[i][f]=Math.max(f==='quantity'?.001:0,Number(e.target.value||0));else cart[i][f]=e.target.value;var t=r.querySelector('.ni-line-total');if(t)t.textContent=money(calc(cart[i]).total);updateTotals()});
E('itemRows').addEventListener('click',function(e){var b=e.target.closest('[data-remove]');if(!b)return;cart.splice(Number(b.getAttribute('data-remove')),1);renderItems()});
E('discountLink').addEventListener('click',function(){if(!cart.length){toast('warning','Add an invoice item first.');return}var x=E('itemRows').querySelector('[data-f="discount_amount"]');if(x){x.focus();x.select()}});E('taxLink').addEventListener('click',function(){if(!cart.length){toast('warning','Add an invoice item first.');return}var x=E('itemRows').querySelector('[data-f="tax_percent"]');if(x){x.focus();x.select()}});
E('addCustomField').addEventListener('click',function(){customFields.push({label:'',value:''});renderCustomFields();var rows=E('customFields').querySelectorAll('[data-custom-f="label"]');if(rows.length)rows[rows.length-1].focus()});
E('customFields').addEventListener('input',function(e){var i=Number(e.target.getAttribute('data-custom-i')),f=e.target.getAttribute('data-custom-f');if(!f||!customFields[i])return;customFields[i][f]=e.target.value;E('customFieldsJson').value=JSON.stringify(customFields)});
E('customFields').addEventListener('click',function(e){var b=e.target.closest('[data-remove-custom]');if(!b)return;customFields.splice(Number(b.getAttribute('data-remove-custom')),1);renderCustomFields()});
document.querySelectorAll('[data-pick-file]').forEach(function(b){b.addEventListener('click',function(){var el=E(this.getAttribute('data-pick-file'));if(el)el.click()})});
E('imageInput').addEventListener('change',function(){queueFiles(this,'image')});E('attachmentInput').addEventListener('change',function(){queueFiles(this,'attachment')});
document.addEventListener('click',function(e){var b=e.target.closest('[data-remove-file]');if(!b)return;var cat=b.getAttribute('data-remove-file'),i=Number(b.getAttribute('data-file-index'));if(pendingFiles[cat])pendingFiles[cat].splice(i,1);renderPendingFiles()});
document.querySelectorAll('[data-section-toggle]').forEach(function(b){b.addEventListener('click',function(){var id=this.getAttribute('data-section-toggle'),s=E(id),show=!s.classList.contains('show');s.classList.toggle('show',show);this.classList.toggle('active',show)})});document.querySelectorAll('[data-remove-section]').forEach(function(b){b.addEventListener('click',function(){var id=this.getAttribute('data-remove-section');E(id).classList.remove('show');var t=document.querySelector('[data-section-toggle="'+id+'"]');if(t)t.classList.remove('active');if(id==='imagesSection'){pendingFiles.image=[];renderPendingFiles()}if(id==='attachmentsSection'){pendingFiles.attachment=[];renderPendingFiles()}if(id==='clientMessageSection')E('clientMessage').value='';if(id==='contractSection')E('contractDisclaimer').value=''})});
E('cancelButton').addEventListener('click',function(){window.location.href='invoices.php'});
E('invoiceForm').addEventListener('submit',function(e){e.preventDefault();serialize();if(!validate())return;serialize();var fd=new FormData(this);appendPendingFiles(fd);fd.append('action','save');var b=E('saveButton');b.disabled=true;b.textContent='Saving...';request(fd).then(function(d){var type=d.email_sent===0&&d.email_message?'warning':'success',msg=d.message||'Invoice created successfully.';if(d.email_message)msg+=' '+d.email_message;if(d.upload_message)msg+=' '+d.upload_message;toast(type,msg);setTimeout(function(){window.location.href='invoice-view.php?invoice_id='+Number(d.invoice_id)},type==='warning'?1800:1000)}).catch(function(err){toast('error',err.message)}).finally(function(){b.disabled=false;b.textContent='Save Invoice'})});
initSelect('visitId','Select billing visit');renderCustomFields();renderPendingFiles();loadMeta().then(function(){setSource(source);setItemMode(itemMode);renderItems();updateCustomerView()}).catch(function(e){toast('error',e.message)});
})();
</script>
</body>
</html>
