<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Quotation View';
// FieldPlx quotation view - Request View visual system + Jobber workflow v3.0.0
$activePage = 'quotes';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['quotations_csrf_token'])) {
    $_SESSION['quotations_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['quotations_csrf_token'];
$quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : 0;
$clientPreview = isset($_GET['client_preview']) && (string)$_GET['client_preview'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quotation View - FieldPlx</title>
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


        .qv-btn.email{
            border-color:#b9d98d;
            color:var(--fd-green-dark);
            background:var(--fd-green-soft);
        }

        .qv-btn.email:hover{
            border-color:var(--fd-green);
            color:#fff;
            background:linear-gradient(90deg,#7fc92d,#68aa1d);
        }

        .qv-btn:disabled,
        .qv-btn.is-loading{
            cursor:not-allowed;
            opacity:.68;
            pointer-events:none;
        }

        .qv-btn .qv-btn-spinner{
            width:12px;
            height:12px;
            display:none;
            border:2px solid currentColor;
            border-right-color:transparent;
            border-radius:50%;
            animation:qvButtonSpin .7s linear infinite;
        }

        .qv-btn.is-loading .qv-btn-spinner{
            display:inline-block;
        }

        .qv-btn.is-loading > i{
            display:none;
        }

        @keyframes qvButtonSpin{
            to{transform:rotate(360deg)}
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

        .qv-toast.success{
            background:#5d971b;
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
           Jobber-style quotation view
           ========================================================== */
        .qj-page{width:100%;max-width:1500px;margin:0 auto;padding:24px 26px 38px!important;color:#09263a}
        .qj-loading{min-height:430px;display:flex;align-items:center;justify-content:center;background:#fff;border:1px solid #dce4e8;border-radius:8px;color:#60727e}
        .qj-loading .qj-spin{width:28px;height:28px;margin:0 auto 10px;border:3px solid #e1e8eb;border-top-color:#2d8b27;border-radius:50%;animation:qjspin .7s linear infinite}
        @keyframes qjspin{to{transform:rotate(360deg)}}
        .qj-error{min-height:320px;display:none;align-items:center;justify-content:center;text-align:center;background:#fff;border:1px solid #f0cfd3;border-radius:8px;padding:30px}
        .qj-error i{display:block;font-size:36px;color:#d64552;margin-bottom:12px}.qj-error h2{font-size:20px;margin:0 0 8px}.qj-error p{color:#6d7b85}
        .qj-hero{background:#fff;border:1px solid #d9e2e7;border-radius:7px;padding:22px 28px 28px;box-shadow:0 1px 2px rgba(0,28,45,.02)}
        .qj-hero-top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:10px}
        .qj-status-wrap{display:flex;align-items:center;gap:12px}.qj-doc-icon{font-size:18px;color:#2e6f91}.qj-status{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;background:#eef3f6;color:#51616a;font-size:12px;font-weight:600}.qj-status:before{content:"";width:7px;height:7px;border-radius:50%;background:#7e8d96}.qj-status.approved,.qj-status.converted{background:#e8f3e6;color:#22651f}.qj-status.approved:before,.qj-status.converted:before{background:#2c8d29}.qj-status.sent,.qj-status.viewed{background:#f7f1d7;color:#7b6712}.qj-status.sent:before,.qj-status.viewed:before{background:#d0ad13}.qj-status.rejected,.qj-status.expired,.qj-status.archived{background:#fdecee;color:#a62d38}.qj-status.rejected:before,.qj-status.expired:before,.qj-status.archived:before{background:#d94b57}
        .qj-actions{display:flex;align-items:center;gap:10px;position:relative}.qj-icon-btn,.qj-btn{border:1px solid #d5dfe4;background:#fff;color:#15394c;border-radius:8px;min-height:41px;display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:600;font-size:13px;cursor:pointer;text-decoration:none!important}.qj-icon-btn{width:41px;padding:0}.qj-btn{padding:0 15px}.qj-btn:hover,.qj-icon-btn:hover{background:#f7fafb;color:#205d31}.qj-btn.primary{border-color:#278322;background:#2f8f29;color:#fff}.qj-btn.primary:hover{background:#257720;color:#fff}.qj-btn.danger{color:#c73540}.qj-btn[hidden]{display:none!important}
        .qj-title-row{display:flex;align-items:flex-start;gap:10px;margin:8px 0 18px}.qj-title{margin:0;min-width:0;flex:1;font-size:31px;line-height:1.18;font-weight:700;color:#04263a;overflow-wrap:anywhere}.qj-edit-link{width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;color:#173f52;border-radius:7px;text-decoration:none!important}.qj-edit-link:hover{background:#f2f7f7;color:#2d8b27}
        .qj-detail-grid{display:grid;grid-template-columns:minmax(310px,1.05fr) minmax(360px,.95fr);gap:28px;align-items:stretch}.qj-client-card{border:1px solid #d6e0e5;border-radius:8px;padding:20px 22px;min-height:210px;position:relative}.qj-client-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}.qj-client-name{font-size:16px;font-weight:700;color:#082d42}.qj-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#3b96d0;margin-left:5px}.qj-client-block{margin-top:12px}.qj-label{display:block;color:#657985;font-size:12px;margin-bottom:3px}.qj-value{color:#17384a;font-size:13px;line-height:1.5;white-space:pre-line}.qj-client-links{margin-top:14px;display:grid;gap:4px}.qj-client-links a{color:#347b23;text-decoration:underline!important;font-size:13px;overflow-wrap:anywhere}
        .qj-meta{display:grid;align-content:start}.qj-meta-row{min-height:46px;display:grid;grid-template-columns:145px minmax(0,1fr);align-items:center;border-bottom:1px solid #dbe3e7;gap:14px;padding:0 5px}.qj-meta-row span:first-child{color:#607481;font-size:13px}.qj-meta-row strong{font-size:13px;font-weight:500;color:#17384a;overflow-wrap:anywhere}.qj-avatar-line{display:inline-flex;align-items:center;gap:8px}.qj-avatar{width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#143b4c;color:#fff;font-size:10px;font-weight:700}
        .qj-section-strip{margin:28px 0 22px;display:flex;align-items:center;gap:10px;min-height:48px}.qj-section-add{display:inline-flex;align-items:center;gap:7px;padding:10px 14px;border-radius:8px;background:#f0eeeb;color:#0c3042;font-size:13px;text-decoration:none!important}.qj-section-chip{display:inline-flex;padding:8px 12px;border:1px solid #d8e0e4;border-radius:7px;background:#fff;color:#13364a;font-size:12px;font-weight:600}
        .qj-card{background:#fff;border:1px solid #d8e2e7;border-radius:8px;overflow:hidden}.qj-card-title{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:20px 22px 12px}.qj-card-title h2{margin:0;font-size:18px;color:#092b3f}.qj-card-title a{color:#123f54;font-size:17px;padding:6px;border-radius:7px}.qj-card-title a:hover{background:#f3f7f8;color:#2d8b27}
        .qj-items-head,.qj-item-row{display:grid;grid-template-columns:minmax(0,1fr) 110px 130px 130px;gap:14px;align-items:start;padding:13px 22px}.qj-items-head{border-bottom:1px solid #dfe6ea;font-size:12px;font-weight:700;color:#17384a}.qj-item-row{border-bottom:1px solid #e5ebee;min-height:88px;font-size:13px}.qj-item-row:last-child{border-bottom:0}.qj-item-name strong{display:block;color:#102f40;font-size:13px;margin-bottom:5px}.qj-item-desc{color:#506773;line-height:1.45;max-width:760px}.qj-item-meta{margin-top:7px;color:#81909a;font-size:11px}.qj-num{text-align:right;color:#17384a}.qj-items-head .qj-num{text-align:right}.qj-empty{padding:26px;text-align:center;color:#83929b}
        .qj-summary-wrap{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,440px);gap:30px;padding:18px 22px 24px;border-top:2px solid #e1e7e9}.qj-intro{color:#4e6572;font-size:13px;line-height:1.6}.qj-intro h3{margin:0 0 8px;color:#17384a;font-size:13px}.qj-summary{display:grid;gap:0}.qj-summary-row{display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:43px;border-bottom:1px solid #e0e6e9;color:#526a77;font-size:13px}.qj-summary-row strong{color:#18384a;font-size:13px}.qj-summary-row.total{font-weight:700;color:#0d3043}.qj-summary-row.total strong{font-size:17px;color:#0d3043}.qj-deposit{margin-top:13px;padding:12px 14px;background:#f8faf9;border-radius:7px;color:#536975;font-size:12px}
        .qj-info-grid{margin-top:20px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.qj-info-card{background:#fff;border:1px solid #d8e2e7;border-radius:8px;padding:16px}.qj-info-card h3{margin:0 0 10px;font-size:14px;color:#12364a}.qj-info-line{display:flex;justify-content:space-between;gap:12px;padding:6px 0;color:#667985;font-size:12px}.qj-info-line strong{color:#17384a;font-weight:600;text-align:right}.qj-signature-preview{max-width:190px;max-height:78px;display:block;margin-top:8px;border:1px solid #e0e6e9;border-radius:5px;background:#fff}
        .qj-more-menu{width:250px;position:absolute;top:49px;right:160px;z-index:2100;display:none;padding:6px 0;background:#fff;border:1px solid #d6dfe4;border-radius:8px;box-shadow:0 12px 28px rgba(0,32,48,.16)}.qj-more-menu.show{display:block}.qj-menu-label{padding:9px 16px 5px;color:#536a76;font-size:12px;font-weight:700}.qj-menu-divider{height:1px;background:#e2e8eb;margin:6px 0}.qj-menu-item{width:100%;min-height:39px;padding:8px 16px;display:flex;align-items:center;gap:11px;border:0;background:#fff;color:#12364a;text-decoration:none!important;text-align:left;font-size:13px;cursor:pointer}.qj-menu-item i{width:18px;text-align:center;font-size:16px}.qj-menu-item:hover{background:#f5f8f8;color:#297f25}.qj-menu-item.danger{color:#cf3642}.qj-menu-item.disabled{opacity:.45;pointer-events:none}
        .qj-drawer-overlay{position:fixed;inset:0;z-index:2990;background:rgba(0,20,32,.18);opacity:0;visibility:hidden;transition:.2s}.qj-drawer-overlay.show{opacity:1;visibility:visible}.qj-history-drawer{width:min(390px,92vw);position:fixed;top:0;right:0;bottom:0;z-index:3000;background:#fff;box-shadow:-8px 0 24px rgba(0,31,47,.13);transform:translateX(100%);transition:.22s;overflow-y:auto}.qj-history-drawer.show{transform:translateX(0)}.qj-drawer-head{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;padding:19px 20px;border-bottom:1px solid #e1e7ea;background:#fff}.qj-drawer-head h2{margin:0;font-size:20px}.qj-drawer-close{border:0;background:transparent;font-size:22px;color:#17384a;cursor:pointer}.qj-history-list{padding:8px 20px 30px}.qj-history-item{position:relative;padding:15px 0 15px 38px;border-bottom:1px solid #e1e7ea}.qj-history-avatar{width:25px;height:25px;position:absolute;left:0;top:15px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#254c5b;color:#fff;font-size:9px;font-weight:700}.qj-history-item strong{display:block;font-size:12px;color:#17384a}.qj-history-item small{display:block;margin-top:3px;color:#7a8991;font-size:11px}.qj-history-item p{margin:6px 0 0;color:#536a76;font-size:12px;line-height:1.45}
        .qj-modal-backdrop{position:fixed;inset:0;z-index:3100;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,25,38,.42)}.qj-modal-backdrop.show{display:flex}.qj-modal{width:min(620px,96vw);background:#fff;border-radius:10px;box-shadow:0 20px 60px rgba(0,25,38,.23);overflow:hidden}.qj-modal-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid #e0e6e9}.qj-modal-head h3{margin:0;font-size:19px}.qj-modal-close{border:0;background:transparent;font-size:22px;cursor:pointer;color:#17384a}.qj-modal-body{padding:20px}.qj-modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 20px;border-top:1px solid #e0e6e9}.qj-confirm-text{color:#536975;line-height:1.55}.qj-signature-box{border:1px solid #d8e1e5;border-radius:8px;overflow:hidden;background:#fff}.qj-signature-box canvas{width:100%;height:220px;display:block;touch-action:none}.qj-signature-tools{display:flex;justify-content:flex-end;padding:8px;border-top:1px dashed #c7d2d8}.qj-small-btn{border:1px solid #d6dfe4;background:#fff;border-radius:6px;padding:7px 12px;color:#234658;cursor:pointer}
        .qj-toast{width:min(380px,calc(100vw - 28px));position:fixed;top:82px;right:18px;z-index:4000;padding:12px 14px;border-radius:8px;background:#173e52;color:#fff;box-shadow:0 10px 28px rgba(0,25,38,.2);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s;font-size:13px;font-weight:600}.qj-toast.show{opacity:1;transform:translateY(0)}.qj-toast.success{background:#2f862b}.qj-toast.error{background:#cf4651}.qj-toast.warning{background:#a97916}
        .qj-client-preview .fieldplx-topbar,.qj-client-preview .fieldplx-sidebar,.qj-client-preview .fieldplx-footer,.qj-client-preview .qj-admin-only{display:none!important}.qj-client-preview .fieldplx-main-content{margin-left:0!important;width:100%!important}.qj-client-preview .qj-page{max-width:1100px;padding-top:26px!important}.qj-client-preview body{background:#fff!important}
        @media(max-width:1050px){.qj-detail-grid{grid-template-columns:1fr}.qj-info-grid{grid-template-columns:1fr 1fr}.qj-more-menu{right:0}.qj-summary-wrap{grid-template-columns:1fr}.qj-items-head,.qj-item-row{grid-template-columns:minmax(0,1fr) 80px 110px 110px}}
        @media(max-width:767.98px){.qj-page{padding:14px 12px 28px!important}.qj-hero{padding:16px}.qj-hero-top{align-items:flex-start}.qj-actions{flex-wrap:wrap;justify-content:flex-end}.qj-title{font-size:25px}.qj-detail-grid{gap:16px}.qj-client-card{padding:16px}.qj-meta-row{grid-template-columns:118px minmax(0,1fr)}.qj-items-head{display:none}.qj-item-row{grid-template-columns:1fr 1fr;gap:9px;padding:15px}.qj-item-name{grid-column:1/-1}.qj-num{text-align:left}.qj-num:before{display:block;color:#7a8991;font-size:10px;margin-bottom:2px}.qj-item-row .qj-qty:before{content:'Quantity'}.qj-item-row .qj-unit:before{content:'Unit Price'}.qj-item-row .qj-total:before{content:'Total'}.qj-info-grid{grid-template-columns:1fr}.qj-more-menu{position:fixed;top:72px;right:12px;width:min(280px,calc(100vw - 24px))}}
        @media print{.fieldplx-topbar,.fieldplx-sidebar,.fieldplx-footer,.qj-admin-only,.qj-drawer-overlay,.qj-history-drawer,.qj-modal-backdrop,.qj-toast{display:none!important}.fieldplx-main-content{margin-left:0!important}.qj-page{padding:0!important;max-width:none}.qj-hero,.qj-card,.qj-info-card{border-color:#ccd5da;box-shadow:none}.qj-section-strip{display:none}.qj-item-meta{color:#666}}


        /* ==========================================================
           Quotation invoice-view alignment - v2.1.0
           ========================================================== */
        body{background:#fff!important}
        .qj-page{max-width:1600px;padding:24px 26px 44px!important;background:#fff}
        .qj-shell{display:grid;grid-template-columns:minmax(0,1fr) 292px;gap:28px;align-items:start}
        .qj-main{min-width:0}.qj-side{min-width:0;position:sticky;top:88px}
        .qj-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
        .qj-hero{border:0;border-radius:0;padding:0;background:transparent;box-shadow:none}
        .qj-hero-top{display:none}
        .qj-title-row{margin:0 0 18px}.qj-title{font-size:31px;line-height:1.14;letter-spacing:-.4px}
        .qj-detail-grid{grid-template-columns:minmax(300px,1.03fr) minmax(340px,.97fr);gap:24px;align-items:start;padding-bottom:28px;border-bottom:1px solid #dbe3e7}
        .qj-client-card{min-height:220px;padding:20px 22px;border-color:#dbe3e7}
        .qj-meta-row{min-height:43px;grid-template-columns:160px minmax(0,1fr);border-color:#dbe3e7;padding:0}
        .qj-card{margin-top:28px;border-color:#dbe3e7;border-radius:8px;box-shadow:none}
        .qj-card-title{min-height:70px;padding:18px 21px}.qj-card-title h2{font-size:18px}
        .qj-items-head,.qj-item-row{padding-left:21px;padding-right:21px}
        .qj-summary-only{width:min(480px,100%);margin-left:auto;padding:12px 21px 20px}
        .qj-summary-row{min-height:39px;border-bottom:1px solid #dbe3e7;padding:0}
        .qj-summary-row.total{margin:0;padding:0;border-top:0;border-bottom:3px solid #e5e9eb;font-size:15px}
        .qj-summary-row.total strong{font-size:15px}.qj-deposit{margin-top:12px}
        .qj-section-card{margin-top:18px;border:1px solid #dbe3e7;border-radius:8px;padding:18px 20px;background:#fff}
        .qj-section-card h3{margin:0 0 10px;font-size:16px;color:#103440}.qj-section-card p{margin:0;color:#405e6b;font-size:13px;line-height:1.55;white-space:pre-wrap}
        .qj-info-grid{margin-top:18px;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.qj-info-card{box-shadow:none;border-color:#dbe3e7}
        .qj-side-card{margin-bottom:15px;padding:17px;border:1px solid #dbe3e7;border-radius:8px;background:#fff}
        .qj-side-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}.qj-side-head h3{margin:0;font-size:15px;color:#103440}.qj-side-head a{color:#294957;font-size:15px}
        .qj-client-option,.qj-side-row{min-height:30px;display:flex;align-items:center;justify-content:space-between;gap:12px;color:#3b5966;font-size:12px}.qj-side-row{border-bottom:1px solid #edf1f2}.qj-side-row:last-child{border-bottom:0}.qj-side-row strong{color:#234653;font-weight:600;text-align:right}
        .qj-onoff{min-width:47px;height:22px;padding:0 9px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:#ebefef;color:#75878d;font-size:10px;font-weight:700}.qj-onoff.on{background:#e3f0df;color:#2f7928}
        .qj-note-box{min-height:150px;border:1px dashed #cbd6db;border-radius:8px;display:flex;align-items:center;justify-content:center;text-align:center;color:#4a6672;font-size:12px;padding:18px;white-space:pre-wrap;line-height:1.45}
        .qj-actions{position:relative}.qj-icon-btn,.qj-btn{height:38px;min-height:38px;border-radius:7px;font-size:13px}.qj-icon-btn{width:38px}.qj-btn{padding:0 13px}
        /* Smaller More popup, same sizing language as invoice view */
        .qj-more-menu{top:44px;right:0;width:215px;padding:7px 0;border-radius:8px;box-shadow:0 12px 28px rgba(13,48,62,.18)}
        .qj-menu-label{padding:8px 13px 5px;font-size:11px}.qj-menu-item{min-height:36px;padding:7px 13px;gap:10px;font-size:12px;font-weight:600}.qj-menu-item i{font-size:14px}.qj-menu-divider{margin:6px 0}
        /* Compact Jobber-style quote email composer */
        .qj-email-modal{width:min(760px,96vw);max-height:calc(100vh - 44px)}
        .qj-email-modal .qj-modal-head{padding:14px 16px}.qj-email-modal .qj-modal-head h3{font-size:17px}
        .qj-email-modal .qj-modal-body{padding:14px 16px}.qj-email-modal .qj-modal-foot{padding:12px 16px}
        .qj-email-grid{display:grid;grid-template-columns:minmax(0,1.48fr) minmax(220px,.72fr);gap:16px}
        .qj-email-to{min-height:66px;padding:8px 9px;display:flex;align-items:flex-start;gap:7px;border:1px solid #d9e1e5;border-radius:7px;background:#fff}.qj-email-to-label{padding-top:8px;color:#61747f;font-size:11px}.qj-email-chips{display:flex;flex-wrap:wrap;gap:5px;min-width:0}.qj-email-chip{display:inline-flex;align-items:center;gap:5px;max-width:100%;padding:6px 8px;border:1px solid #dde4e7;border-radius:999px;background:#fff;color:#31505d;font-size:11px}.qj-email-chip button{border:0;background:transparent;color:#6d7f87;padding:0;cursor:pointer}.qj-email-to input{min-width:100px;flex:1;border:0;outline:0;padding:8px 2px;font-size:11px;color:#254653}
        .qj-email-field{margin-top:9px}.qj-email-field label{display:block;margin:0 0 4px;color:#697c86;font-size:10px}.qj-email-field input,.qj-email-field textarea{width:100%;border:1px solid #d9e1e5;border-radius:6px;outline:0;color:#274854;font:inherit;font-size:11px}.qj-email-field input{height:39px;padding:8px 10px}.qj-email-field textarea{min-height:150px;padding:10px;resize:vertical;line-height:1.55}.qj-email-field input:focus,.qj-email-field textarea:focus{border-color:#afd083;box-shadow:0 0 0 3px rgba(116,184,36,.1)}
        .qj-email-auto-file{margin-top:9px;min-height:52px;padding:8px 9px;border:1px solid #dfe5e8;border-radius:7px;display:flex;align-items:center;gap:9px}.qj-email-pdf-icon{width:34px;height:34px;flex:0 0 34px;display:grid;place-items:center;border-radius:5px;background:#f3eee6;color:#d94b3f;font-size:17px}.qj-email-auto-file strong,.qj-email-auto-file small{display:block}.qj-email-auto-file strong{font-size:11px;color:#38525e}.qj-email-auto-file small{margin-top:2px;font-size:9px;color:#7c8d94}
        .qj-email-attachments h4{margin:0 0 10px;font-size:13px;color:#163846}.qj-email-drop{min-height:96px;border:1px dashed #cad5da;border-radius:7px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;color:#687d86;font-size:10px;text-align:center;padding:10px}.qj-email-drop .qj-btn{height:32px;min-height:32px;color:#4d8b2a}.qj-email-limit{margin-top:8px;color:#73858e;font-size:9px}.qj-email-progress{height:5px;margin-top:6px;border-radius:999px;background:#e8eceb;overflow:hidden}.qj-email-progress span{display:block;width:0;height:100%;background:#8ebd56}.qj-email-file-list{display:grid;gap:6px;margin-top:9px}.qj-email-file{min-height:42px;padding:7px;border:1px solid #dfe5e8;border-radius:7px;display:grid;grid-template-columns:24px minmax(0,1fr) auto 22px;gap:6px;align-items:center;color:#3d5964;font-size:10px}.qj-email-file small{color:#82939a;font-size:9px}.qj-email-file button{border:0;background:transparent;color:#75878f;cursor:pointer}
        .qj-email-approval-note{margin-top:10px;padding:9px 10px;display:flex;align-items:flex-start;gap:8px;border:1px solid #dfe8d5;border-radius:7px;background:#f8fbf5;color:#526d5c;font-size:10.5px;line-height:1.45}.qj-email-approval-note i{margin-top:1px;color:var(--qv-green-dark);font-size:14px}.qj-email-system-file{min-height:55px;margin-top:9px;padding:7px 8px;display:grid;grid-template-columns:20px 38px minmax(0,1fr) 28px;gap:7px;align-items:center;border:1px solid #dfe5e8;border-radius:7px;background:#fff}.qj-email-system-check{display:flex;align-items:center;justify-content:center}.qj-email-system-check input{width:15px;height:15px;accent-color:var(--qv-green)}.qj-email-system-file-copy{min-width:0}.qj-email-system-file-copy strong,.qj-email-system-file-copy small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.qj-email-system-file-copy strong{color:#38525e;font-size:11px}.qj-email-system-file-copy small{margin-top:2px;color:#7c8d94;font-size:9px}.qj-email-open-pdf{width:28px;height:28px;display:grid;place-items:center;border:0;border-radius:6px;background:transparent;color:#55717d;cursor:pointer}.qj-email-open-pdf:hover{background:var(--qv-green-soft);color:var(--qv-green-dark)}
        .qj-checkline{display:inline-flex;align-items:center;gap:7px;color:#536b77;font-size:11px}.qj-checkline input{width:15px;height:15px}
        .qj-modal{border-radius:10px}.qj-modal-backdrop{background:rgba(0,25,38,.38)}
        .qj-client-preview .qj-side{display:none!important}.qj-client-preview .qj-shell{grid-template-columns:1fr}
        @media(max-width:1100px){.qj-shell{grid-template-columns:1fr}.qj-side{position:static;display:grid;grid-template-columns:1fr 1fr;gap:14px}.qj-side-card{margin:0}.qj-detail-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:760px){.qj-page{padding:16px 13px 32px!important}.qj-toolbar{align-items:flex-start;flex-direction:column}.qj-actions{width:100%;flex-wrap:wrap}.qj-title{font-size:25px}.qj-detail-grid{grid-template-columns:1fr}.qj-meta-row{grid-template-columns:125px 1fr}.qj-side{grid-template-columns:1fr}.qj-info-grid{grid-template-columns:1fr}.qj-email-grid{grid-template-columns:1fr}.qj-email-modal{width:min(560px,96vw)}.qj-more-menu{position:absolute;top:44px;right:0;width:205px}}


        /* ==========================================================
           Quotation View v3.0.0
           UI follows FieldPlx Request View. Jobber is workflow-only.
           ========================================================== */
        :root{
            --qv-green:#2f8d25;
            --qv-green-dark:#27781f;
            --qv-green-soft:#edf6e8;
            --qv-navy:#001131;
            --qv-text:#0b3142;
            --qv-body:#314f5d;
            --qv-muted:#6c818d;
            --qv-line:#dbe3e7;
            --qv-line-soft:#e9eef0;
            --qv-surface:#ffffff;
            --qv-soft:#f8fafb;
            --qv-danger:#dc4c55;
            --qv-blue:#2f83c6;
        }
        body{background:#fff!important;color:var(--qv-body)!important;font-family:Arial,Helvetica,sans-serif!important;font-size:14px!important}
        .qj-page{width:100%!important;max-width:none!important;margin:0!important;padding:0!important;background:#fff!important;color:var(--qv-body)!important}
        .qj-shell{display:grid!important;grid-template-columns:minmax(0,1fr) 330px!important;gap:0!important;align-items:stretch!important;min-height:calc(100vh - 70px)!important}
        .qj-main{min-width:0!important;width:100%!important;max-width:1160px!important;margin:0 auto!important;padding:27px 31px 55px!important}
        .qj-side{min-width:0!important;position:static!important;padding:28px 25px!important;border-left:1px solid var(--qv-line-soft)!important;background:#fff!important}
        .qj-side-inner{position:sticky;top:86px}

        /* Top action row */
        .qj-toolbar{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:12px!important;margin:0 0 18px!important}
        .qj-status-wrap{display:flex!important;align-items:center!important;gap:12px!important;min-width:0}
        .qj-doc-icon{width:28px;height:28px;display:grid;place-items:center;color:#b85a67!important;font-size:20px!important}
        .qj-status{min-height:27px!important;padding:4px 11px!important;display:inline-flex!important;align-items:center!important;gap:7px!important;border-radius:999px!important;background:#eef3f5!important;color:#456573!important;font-size:13px!important;font-weight:400!important;text-transform:capitalize}
        .qj-status:before{width:8px!important;height:8px!important;flex:0 0 8px;border-radius:50%!important;background:#7892a0!important;content:""!important}
        .qj-status.draft{background:#eef3f5!important;color:#456573!important}.qj-status.draft:before{background:#7892a0!important}
        .qj-status.sent,.qj-status.viewed{background:#fbf1c9!important;color:#7b6817!important}.qj-status.sent:before,.qj-status.viewed:before{background:#c7a915!important}
        .qj-status.approved,.qj-status.converted{background:#eaf5e5!important;color:#2a6c25!important}.qj-status.approved:before,.qj-status.converted:before{background:#3d9a35!important}
        .qj-status.rejected,.qj-status.expired,.qj-status.archived{background:#fdebed!important;color:#a52f3b!important}.qj-status.rejected:before,.qj-status.expired:before,.qj-status.archived:before{background:#d94c57!important}
        .qj-status.internal_approval,.qj-status.changes_requested{background:#f2efff!important;color:#5b4dad!important}.qj-status.internal_approval:before,.qj-status.changes_requested:before{background:#7565c9!important}
        .qj-actions{margin-left:auto!important;display:flex!important;align-items:center!important;gap:8px!important;position:relative!important;flex-wrap:wrap}
        .qj-icon-btn,.qj-btn{height:41px!important;min-height:41px!important;padding:0 15px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;border:1px solid var(--qv-line)!important;border-radius:8px!important;background:#fff!important;color:#2e4b59!important;box-shadow:none!important;font:700 14px Arial,Helvetica,sans-serif!important;cursor:pointer!important;text-decoration:none!important}
        .qj-icon-btn{width:42px!important;padding:0!important;border-color:transparent!important;font-size:19px!important}
        .qj-icon-btn:hover{background:#f4f8f2!important;color:var(--qv-green)!important}
        .qj-btn:hover{border-color:#b8d9ac!important;background:#fbfef9!important;color:var(--qv-green-dark)!important}
        .qj-btn.primary{border-color:var(--qv-green)!important;background:var(--qv-green)!important;color:#fff!important}
        .qj-btn.primary:hover{background:var(--qv-green-dark)!important;color:#fff!important}
        .qj-btn.danger{color:#c9444e!important;background:#fff!important;border-color:#efcfd2!important}
        .qj-btn[hidden]{display:none!important}

        /* More menu */
        .qj-more-menu{width:225px!important;position:absolute!important;right:0!important;top:48px!important;z-index:2100!important;display:none;padding:7px!important;border:1px solid var(--qv-line)!important;border-radius:9px!important;background:#fff!important;box-shadow:0 10px 26px rgba(0,17,49,.15)!important}
        .qj-more-menu.show{display:block!important}.qj-menu-divider{height:1px!important;margin:6px 5px!important;background:var(--qv-line-soft)!important}.qj-menu-label{padding:8px 10px 5px!important;color:#6f818a!important;font-size:11px!important;font-weight:700!important}
        .qj-menu-item{width:100%!important;min-height:40px!important;padding:8px 10px!important;display:flex!important;align-items:center!important;gap:10px!important;border:0!important;border-radius:7px!important;background:#fff!important;color:#334f5d!important;font:600 13px Arial,Helvetica,sans-serif!important;text-align:left!important;cursor:pointer!important;text-decoration:none!important}
        .qj-menu-item:hover{background:#f5f8f5!important;color:var(--qv-green-dark)!important}.qj-menu-item i{width:20px!important;font-size:17px!important}.qj-menu-item.danger{color:#c9444e!important}.qj-menu-item.disabled{opacity:.45!important;pointer-events:none!important}

        /* Title and customer/meta */
        .qj-title-row{display:flex!important;align-items:center!important;gap:10px!important;margin:0 0 15px!important}
        .qj-title{margin:0!important;min-width:0!important;flex:1!important;color:#052f40!important;font-size:30px!important;line-height:1.16!important;font-weight:700!important;letter-spacing:-.3px!important;overflow-wrap:anywhere}
        .qj-edit-link{width:36px!important;height:36px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;border:0!important;border-radius:8px!important;background:transparent!important;color:#2b4b59!important;font-size:18px!important;text-decoration:none!important}
        .qj-edit-link:hover{background:#f4f8f2!important;color:var(--qv-green)!important}
        .qj-detail-grid{display:grid!important;grid-template-columns:minmax(310px,450px) minmax(300px,1fr)!important;gap:17px!important;align-items:start!important;margin:0 0 30px!important;padding:0!important;border:0!important}
        .qj-client-card{min-height:177px!important;padding:24px!important;position:relative!important;border:1px solid var(--qv-line)!important;border-radius:8px!important;background:#fff!important}
        .qj-client-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:10px!important;margin-bottom:11px!important}.qj-client-name{margin:0 34px 0 0!important;color:#0b3142!important;font-size:17px!important;font-weight:700!important}.qj-dot{width:8px!important;height:8px!important;border-radius:50%!important;background:#37a3e6!important}
        .qj-client-block{margin-top:10px!important}.qj-label{display:block!important;margin-bottom:3px!important;color:#6b808c!important;font-size:12px!important}.qj-value{color:#2f4e5d!important;font-size:14px!important;line-height:1.35!important;white-space:pre-line!important}.qj-client-links{margin-top:12px!important;display:grid!important;gap:5px!important}.qj-client-links a{width:max-content;max-width:100%;color:#3b8d26!important;text-decoration:underline!important;font-size:14px!important;overflow-wrap:anywhere}
        .qj-meta{display:grid!important;align-content:start!important}.qj-meta-row{min-height:43px!important;display:grid!important;grid-template-columns:130px minmax(0,1fr)!important;align-items:center!important;gap:24px!important;padding:0!important;border-bottom:1px solid var(--qv-line)!important;color:#526d79!important}.qj-meta-row span:first-child{color:#526d79!important;font-size:14px!important}.qj-meta-row strong{color:#173d4d!important;font-size:14px!important;font-weight:400!important;overflow-wrap:anywhere}.qj-avatar-line{display:inline-flex!important;align-items:center!important;gap:8px!important}.qj-avatar{width:27px!important;height:27px!important;border-radius:50%!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;background:#173d4c!important;color:#fff!important;font-size:10px!important;font-weight:700!important}

        /* Request-view section rhythm */
        .qj-section{padding:29px 0!important;border-top:1px solid var(--qv-line-soft)!important}.qj-section:first-of-type{border-top:1px solid var(--qv-line-soft)!important}
        .qj-card,.qj-section-card{margin:0!important;padding:0!important;overflow:hidden!important;border:1px solid var(--qv-line)!important;border-radius:8px!important;background:#fff!important;box-shadow:none!important}
        .qj-card-title,.qj-section-head{min-height:0!important;padding:27px 24px 20px!important;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:12px!important;border:0!important;background:#fff!important}.qj-card-title h2,.qj-section-head h2{margin:0!important;color:#073247!important;font-size:22px!important;line-height:1.2!important;font-weight:700!important}.qj-card-title a,.qj-section-head a{margin-left:auto!important}
        .qj-section-body{padding:0 24px 27px!important}.qj-section-body h3{margin:0 0 7px!important;color:#0e3446!important;font-size:16px!important;font-weight:700!important}.qj-section-body p{margin:0!important;color:#2f4e5c!important;font-size:14px!important;line-height:1.55!important;white-space:pre-wrap!important}.qj-section-helper{margin:0 0 7px!important;color:#6d838f!important;font-size:13px!important}
        .qj-intro-image-grid,.qj-image-grid{display:flex!important;gap:10px!important;flex-wrap:wrap!important;margin-top:12px!important}.qj-intro-image,.qj-image-tile{width:118px;height:118px;overflow:hidden;border:1px solid var(--qv-line)!important;border-radius:8px;background:#f7f9fa}.qj-intro-image img,.qj-image-tile img{width:100%;height:100%;object-fit:cover}
        .qj-file-list{display:grid;gap:8px}.qj-file-row-view{min-height:42px;padding:8px 10px;display:flex;align-items:center;gap:9px;border:1px solid var(--qv-line-soft);border-radius:7px;background:#fbfcfc;color:#31505d}.qj-file-row-view i{color:var(--qv-green-dark)}.qj-file-row-view span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.qj-file-row-view small{margin-left:auto;color:#83949d;font-size:11px;white-space:nowrap}.qj-file-row-view a{color:inherit!important;text-decoration:none!important}

        /* Products / services */
        .qj-items-head,.qj-item-row{display:grid!important;grid-template-columns:minmax(0,1fr) 90px 130px 130px!important;gap:10px!important;align-items:start!important;padding:13px 24px!important}.qj-items-head{border-bottom:1px solid var(--qv-line)!important;color:#17384a!important;background:#fff!important;font-size:12px!important;font-weight:700!important}.qj-item-row{min-height:88px!important;border-bottom:1px solid var(--qv-line-soft)!important;color:#314f5d!important;font-size:13px!important}.qj-item-row:last-child{border-bottom:0!important}.qj-item-name strong{display:block!important;color:#183845!important;font-size:14px!important}.qj-item-desc{max-width:760px!important;margin-top:5px!important;color:#506773!important;font-size:13px!important;line-height:1.45!important;white-space:normal!important}.qj-item-meta{margin-top:7px!important;color:#81909a!important;font-size:11px!important}.qj-num{text-align:right!important;color:#17384a!important}.qj-empty{padding:26px!important;text-align:center!important;color:#83929b!important}
        .qj-product-footer{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(320px,440px)!important;gap:30px!important;padding:18px 24px 24px!important;border-top:3px solid #e3e8ea!important}.qj-client-view-area{min-width:0;padding-top:7px}.qj-client-view-head{display:flex;align-items:center;gap:12px;color:#294957;font-size:14px}.qj-client-view-head i{font-size:20px}.qj-client-view-head strong{font-weight:400}.qj-client-view-head button{border:0;background:transparent;color:var(--qv-green-dark);font:700 14px Arial,Helvetica,sans-serif;text-decoration:underline;cursor:pointer}.qj-client-view-help{margin:12px 0 0;color:#607783;font-size:12px;line-height:1.5;max-width:470px}.qj-client-view-panel{display:none;margin-top:12px;padding:12px;border:1px solid var(--qv-line-soft);border-radius:8px;background:#fbfcfc}.qj-client-view-panel.show{display:block}.qj-client-view-checks{display:flex;gap:16px;flex-wrap:wrap}.qj-client-view-checks label{display:inline-flex;align-items:center;gap:7px;color:#4f6975;font-size:12px}.qj-client-view-checks input{width:17px;height:17px;accent-color:var(--qv-green)}.qj-client-view-actions{display:flex;gap:8px;margin-top:12px}
        .qj-summary-only{width:100%!important;margin:0!important;padding:0!important}.qj-summary{display:grid!important;gap:0!important}.qj-summary-row{min-height:43px!important;padding:0!important;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:20px!important;border-bottom:1px solid var(--qv-line)!important;color:#526a77!important;font-size:14px!important}.qj-summary-row strong{color:#18384a!important;font-size:14px!important}.qj-summary-row.total{margin:0!important;padding:0!important;border-top:0!important;border-bottom:3px solid #e5e9eb!important;color:#0d3043!important;font-size:17px!important;font-weight:700!important}.qj-summary-row.total strong{font-size:18px!important;color:#0d3043!important}.qj-deposit{margin-top:13px!important;padding:0!important;background:transparent!important;color:var(--qv-green-dark)!important;font-size:13px!important;font-weight:700!important;text-decoration:underline}.qj-payment-plan-title{margin-bottom:7px;color:var(--qv-green-dark);font-size:13px;font-weight:700}.qj-payment-schedule-view{display:grid;gap:0;border-top:1px solid var(--qv-line)}.qj-payment-schedule-view>div{min-height:38px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid var(--qv-line);color:#526a77;font-size:12px}.qj-payment-schedule-view strong{color:#18384a;font-size:12px}

        /* Right notes */
        .qj-notes-title{margin:0 0 18px!important;color:#073247!important;font-size:20px!important;line-height:1.2!important;font-weight:700!important}.qj-notes-card{padding:17px!important;border:1px solid var(--qv-line)!important;border-radius:8px!important;background:#fff!important}.qj-note-empty{min-height:245px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:15px;padding:20px;border:1px dashed #cbd6dc;border-radius:8px;text-align:center;color:#244655;cursor:pointer}.qj-note-empty-icon{width:58px;height:58px;display:grid;place-items:center;border-radius:50%;background:#f5f5f3;color:#244a58;font-size:24px}.qj-note-empty p{margin:0;max-width:210px;line-height:1.5}.qj-note-view{position:relative;min-height:150px;padding:12px;border:1px solid var(--qv-line-soft);border-radius:7px;background:#fbfcfc}.qj-note-view p{margin:0;padding-right:30px;color:#4e6975;font-size:13px;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}.qj-note-edit-icon{position:absolute;top:6px;right:6px;width:32px;height:32px;border:0;border-radius:7px;background:transparent;color:#31505d;font-size:15px;cursor:pointer}.qj-note-edit-icon:hover{background:#f4f8f2;color:var(--qv-green)}.qj-note-editor{display:none}.qj-note-editor.show{display:block}.qj-note-editor textarea{width:100%;min-height:125px;padding:10px 11px;border:1px solid #79ad64;border-radius:7px;outline:0;color:#183845;font:14px/1.5 Arial,Helvetica,sans-serif;resize:vertical}.qj-note-editor-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px}

        /* History drawer follows Request View */
        .qj-drawer-overlay{z-index:11990!important;background:rgba(0,17,49,.18)!important}.qj-history-drawer{width:min(420px,92vw)!important;z-index:12000!important;box-shadow:-12px 0 36px rgba(0,17,49,.15)!important}.qj-drawer-head{padding:22px 21px 12px!important;border-bottom:0!important}.qj-drawer-head h2{margin:0!important;color:#073247!important;font-size:24px!important}.qj-drawer-close{width:36px!important;height:36px!important;border:0!important;background:transparent!important;color:#284957!important;font-size:21px!important}.qj-history-list{padding:15px 18px 30px!important}.qj-history-item{position:relative!important;padding:12px 0 12px 38px!important;border-bottom:1px solid var(--qv-line-soft)!important}.qj-history-avatar{width:26px!important;height:26px!important;top:12px!important;background:#173d4c!important}.qj-history-item strong{color:#294957!important;font-size:13px!important}.qj-history-item small{margin-top:3px!important;color:#83949d!important;font-size:11px!important}.qj-history-item p{margin-top:7px!important;color:#526d79!important;font-size:12px!important;line-height:1.45!important}

        /* Modals follow Request View */
        .qj-modal-backdrop{z-index:20000!important;padding:18px!important;background:rgba(0,17,49,.4)!important}.qj-modal{width:min(720px,100%)!important;max-height:calc(100vh - 36px)!important;overflow:auto!important;border-radius:10px!important;background:#fff!important;box-shadow:0 22px 60px rgba(0,17,49,.22)!important}.qj-email-modal{width:min(860px,100%)!important}.qj-modal-head{padding:20px 23px 12px!important;border:0!important}.qj-modal-head h3{margin:0!important;color:#073247!important;font-size:23px!important}.qj-modal-close{width:34px!important;height:34px!important;border:0!important;border-radius:7px!important;background:transparent!important;color:#607783!important;font-size:19px!important}.qj-modal-body{padding:8px 23px 13px!important}.qj-modal-foot{padding:12px 23px 20px!important;border:0!important}.qj-signature-box{border:1px solid var(--qv-line)!important;border-radius:8px!important}.qj-signature-box canvas{height:220px!important}.qj-small-btn{height:36px!important;border:1px solid var(--qv-line)!important;border-radius:7px!important;background:#fff!important;color:#31505d!important;font:700 13px Arial,Helvetica,sans-serif!important}.qj-toast{top:82px!important;right:18px!important;z-index:25000!important;border-radius:7px!important;font-size:12px!important}.qj-toast.success{background:#5d971b!important}.qj-toast.error{background:#e45b66!important}.qj-toast.warning{background:#a97916!important}

        /* Signature section */
        .qj-signature-preview{max-width:230px!important;max-height:100px!important;margin-top:10px!important;border:1px solid var(--qv-line)!important;border-radius:7px!important;background:#fff!important}.qj-signature-copy{color:#526d79;font-size:13px}

        .qj-unit-cost-wrap{position:relative;display:inline-block}.qj-unit-price-button{padding:0;border:0;background:transparent;color:#17384a;font:inherit;cursor:pointer}.qj-unit-price-button:hover{color:var(--qv-green-dark)}.qj-cost-popover{width:225px;position:absolute;right:0;top:29px;z-index:2200;display:none;padding:12px 14px;border:1px solid var(--qv-line);border-radius:8px;background:#fff;box-shadow:0 8px 22px rgba(0,17,49,.14);text-align:left}.qj-cost-popover.show{display:block}.qj-cost-popover>div{min-height:54px;padding:8px 10px;margin-bottom:8px;border:1px solid var(--qv-line);border-radius:7px}.qj-cost-popover span,.qj-cost-popover strong{display:block}.qj-cost-popover span{color:#6e818b;font-size:11px}.qj-cost-popover strong{margin-top:3px;color:#173d4c;font-size:14px;font-weight:400}.qj-cost-popover p{margin:4px 0 0;color:#6e818b;font-size:11px;line-height:1.35}.qj-line-image{width:52px;height:52px;display:block;margin-top:8px;overflow:hidden;border:1px solid var(--qv-line);border-radius:6px;background:#f7f9fa}.qj-line-image img{width:100%;height:100%;object-fit:cover}

        /* Customer preview removes all admin chrome and respects selected columns */
        .qj-client-preview .fieldplx-topbar,.qj-client-preview .fieldplx-sidebar,.qj-client-preview .fieldplx-footer,.qj-client-preview .qj-admin-only{display:none!important}.qj-client-preview .fieldplx-main-content{margin-left:0!important;width:100%!important}.qj-client-preview .qj-shell{grid-template-columns:1fr!important}.qj-client-preview .qj-main{max-width:1100px!important;border:0!important}.qj-client-preview .qj-page{background:#fff!important}

        @media(max-width:1199.98px){.qj-shell{grid-template-columns:minmax(0,1fr) 290px!important}.qj-main{padding-left:24px!important;padding-right:24px!important}.qj-product-footer{grid-template-columns:1fr 400px!important}}
        @media(max-width:991.98px){.qj-shell{grid-template-columns:1fr!important}.qj-main{max-width:none!important}.qj-side{border-left:0!important;border-top:1px solid var(--qv-line-soft)!important;padding:24px 22px!important}.qj-side-inner{position:static!important}.qj-product-footer{grid-template-columns:1fr!important}.qj-detail-grid{grid-template-columns:1fr!important}.qj-client-card{min-height:auto!important}}
        @media(max-width:767.98px){.qj-main{padding:20px 16px 42px!important}.qj-side{padding:20px 16px!important}.qj-toolbar{flex-wrap:wrap!important}.qj-actions{width:100%!important;margin-left:0!important;justify-content:flex-end!important}.qj-title{font-size:25px!important}.qj-detail-grid{gap:18px!important}.qj-meta-row{grid-template-columns:105px 1fr!important;gap:16px!important}.qj-card-title,.qj-section-head{padding-left:17px!important;padding-right:17px!important}.qj-section-body{padding-left:17px!important;padding-right:17px!important}.qj-items-head{display:none!important}.qj-item-row{grid-template-columns:1fr 1fr!important;gap:9px!important;padding:15px 17px!important}.qj-item-name{grid-column:1/-1!important}.qj-num{text-align:left!important}.qj-num:before{display:block;color:#7a8991;font-size:10px;margin-bottom:2px}.qj-item-row .qj-qty:before{content:'Quantity'}.qj-item-row .qj-unit:before{content:'Unit Price'}.qj-item-row .qj-total:before{content:'Total'}.qj-product-footer{padding-left:17px!important;padding-right:17px!important}.qj-email-grid{grid-template-columns:1fr!important}.qj-more-menu{position:absolute!important;top:48px!important;right:0!important;width:220px!important}}
        @media(max-width:520px){.qj-btn span.qj-action-label{display:none}.qj-meta-row{grid-template-columns:1fr!important;gap:5px!important;padding:8px 0!important}.qj-card-title h2,.qj-section-head h2,.qj-notes-title{font-size:20px!important}.qj-client-view-checks{display:grid!important;gap:9px!important}.qj-modal-foot{flex-wrap:wrap!important}}
        @media print{.qj-side,.qj-toolbar,.qj-admin-only,.qj-drawer-overlay,.qj-history-drawer,.qj-modal-backdrop,.qj-toast{display:none!important}.qj-shell{display:block!important}.qj-main{max-width:none!important;padding:15px!important}.qj-section,.qj-card,.qj-section-card{break-inside:avoid}.qj-client-preview .qj-main{max-width:none!important}}

    </style>
</head>

<body class="<?php echo $clientPreview ? 'qj-client-preview' : ''; ?>">
<?php require_once __DIR__ . '/includes/nav.php'; ?>

<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="fd-dashboard qj-page">

                <div id="loadingState" class="qj-loading">
                    <div><div class="qj-spin"></div><div>Loading quotation details...</div></div>
                </div>

                <div id="errorState" class="qj-error">
                    <div>
                        <i class="bi bi-exclamation-circle"></i>
                        <h2>Unable to load quotation</h2>
                        <p id="errorMessage">The quotation could not be loaded.</p>
                        <a class="qj-btn qj-admin-only" href="quotations"><i class="bi bi-arrow-left"></i> Back to Quotations</a>
                    </div>
                </div>

                <div id="quotationContent" style="display:none">
                    <div class="qj-shell">
                        <section class="qj-main">
                            <div class="qj-toolbar qj-admin-only">
                                <div class="qj-status-wrap">
                                    <i class="bi bi-file-earmark-text qj-doc-icon"></i>
                                    <span class="qj-status draft" id="pageStatus">Loading</span>
                                </div>
                                <div class="qj-actions">
                                    <button type="button" class="qj-icon-btn" id="historyButton" title="Quote history"><i class="bi bi-clock-history"></i></button>
                                    <button type="button" class="qj-btn" id="moreButton"><i class="bi bi-three-dots"></i> <span class="qj-action-label">More</span></button>
                                    <button type="button" class="qj-btn primary" id="emailInvoiceButton"><i class="bi bi-envelope"></i> <span class="qj-action-label">Send Email</span></button>
                                    <div class="qj-more-menu" id="moreMenu">
                                        <a class="qj-menu-item" id="convertJobButton" href="#" hidden><i class="bi bi-hammer"></i> <span id="convertJobText">Convert to Job</span></a>
                                        <button type="button" class="qj-menu-item" id="similarButton"><i class="bi bi-files"></i> Create Similar Quote</button>
                                        <div class="qj-menu-divider"></div>
                                        <div class="qj-menu-label">Send as...</div>
                                        <a class="qj-menu-item" id="sendTextLink" href="#"><i class="bi bi-chat-left-text"></i> Send Text</a>
                                        <div class="qj-menu-divider"></div>
                                        <div class="qj-menu-label">Mark as...</div>
                                        <button type="button" class="qj-menu-item" id="awaitingButton"><i class="bi bi-envelope-check"></i> Awaiting Response</button>
                                        <button type="button" class="qj-menu-item" id="approveButton"><i class="bi bi-check2"></i> Approved</button>
                                        <button type="button" class="qj-menu-item" id="rejectButton"><i class="bi bi-x-circle"></i> Rejected</button>
                                        <button type="button" class="qj-menu-item" id="reopenButton"><i class="bi bi-arrow-counterclockwise"></i> Reopen as Draft</button>
                                        <button type="button" class="qj-menu-item" id="archiveButton"><i class="bi bi-archive"></i> Archive</button>
                                        <div class="qj-menu-divider"></div>
                                        <a class="qj-menu-item" id="previewClientLink" target="_blank" href="#"><i class="bi bi-eye"></i> Preview as Customer</a>
                                        <button type="button" class="qj-menu-item" id="signatureButton"><i class="bi bi-pen"></i> Collect Signature</button>
                                        <button type="button" class="qj-menu-item" id="printButton"><i class="bi bi-file-earmark-pdf"></i> Print or Save PDF</button>
                                        <div class="qj-menu-divider"></div>
                                        <button type="button" class="qj-menu-item danger" id="deleteButton"><i class="bi bi-trash3"></i> Delete</button>
                                    </div>
                                </div>
                            </div>

                            <div class="qj-title-row">
                                <h1 class="qj-title" id="pageTitle">Quotation</h1>
                                <a class="qj-edit-link qj-admin-only" id="editButton" data-edit-quote href="#" title="Edit quote"><i class="bi bi-pencil"></i></a>
                            </div>

                            <div class="qj-detail-grid">
                                <div class="qj-client-card">
                                    <div class="qj-client-head">
                                        <div class="qj-client-name"><span id="clientName">-</span><span class="qj-dot"></span></div>
                                        <a class="qj-edit-link qj-admin-only" id="clientEditLink" data-edit-quote href="#" title="Edit customer on quote"><i class="bi bi-three-dots"></i></a>
                                    </div>
                                    <div class="qj-client-block" id="billingAddressWrap">
                                        <span class="qj-label">Customer</span>
                                        <div class="qj-value" id="clientCompany">-</div>
                                    </div>
                                    <div class="qj-client-block">
                                        <span class="qj-label">Property Address</span>
                                        <div class="qj-value" id="propertyAddress">-</div>
                                    </div>
                                    <div class="qj-client-links">
                                        <a id="clientPhoneLink" href="#"><span id="clientPhone">-</span></a>
                                        <a id="clientEmailLink" href="#"><span id="clientEmail">-</span></a>
                                    </div>
                                </div>

                                <div class="qj-meta">
                                    <div class="qj-meta-row"><span>Quote #</span><strong id="quoteNo">-</strong></div>
                                    <div class="qj-meta-row"><span>Created</span><strong id="createdDate">-</strong></div>
                                    <div class="qj-meta-row"><span>Sent</span><strong id="sentDate">-</strong></div>
                                    <div class="qj-meta-row"><span>Viewed</span><strong id="viewedDate">-</strong></div>
                                    <div class="qj-meta-row" id="approvedRow"><span>Approved</span><strong id="approvedDate">-</strong></div>
                                    <div class="qj-meta-row"><span>Valid until</span><strong id="validUntil">-</strong></div>
                                    <div class="qj-meta-row"><span>Salesperson</span><strong id="salespersonName">-</strong></div>
                                </div>
                            </div>

                            <div id="introductionSectionHost"></div>
                            <div id="textSectionsHost"></div>

                            <section class="qj-section">
                                <div class="qj-card qj-products-card">
                                    <div class="qj-card-title">
                                        <h2>Product / Service</h2>
                                        <a class="qj-edit-link qj-admin-only" id="editItemsLink" data-edit-quote href="#" title="Edit products and services"><i class="bi bi-pencil"></i></a>
                                    </div>
                                    <div class="qj-items-head">
                                        <div>Line Item</div><div class="qj-num qj-head-qty">Quantity</div><div class="qj-num qj-head-unit">Unit Price</div><div class="qj-num qj-head-total">Total</div>
                                    </div>
                                    <div id="itemRows"><div class="qj-empty">No quotation items found.</div></div>
                                    <div class="qj-product-footer">
                                        <div class="qj-client-view-area qj-admin-only">
                                            <div class="qj-client-view-head"><i class="bi bi-eye"></i><strong>Customer view</strong><button type="button" id="clientViewChange">Change</button></div>
                                            <p class="qj-client-view-help">Adjust what your customer will see on this quote. Internal cost and markup are never shown.</p>
                                            <div class="qj-client-view-panel" id="clientViewPanel">
                                                <div class="qj-client-view-checks">
                                                    <label><input type="checkbox" id="cvQuantities"> Quantities</label>
                                                    <label><input type="checkbox" id="cvUnitPrices"> Unit prices</label>
                                                    <label><input type="checkbox" id="cvLineTotals"> Line item totals</label>
                                                    <label><input type="checkbox" id="cvTotals"> Totals</label>
                                                </div>
                                                <div class="qj-client-view-actions"><button class="qj-btn" type="button" id="clientViewCancel">Cancel</button><button class="qj-btn primary" type="button" id="clientViewSave">Save</button></div>
                                            </div>
                                        </div>
                                        <div class="qj-summary-only" id="quoteTotalsBlock">
                                            <div class="qj-summary">
                                                <div class="qj-summary-row"><span>Subtotal</span><strong id="subtotal">-</strong></div>
                                                <div class="qj-summary-row"><span>Discount</span><strong id="discountTotal">-</strong></div>
                                                <div class="qj-summary-row"><span>Tax</span><strong id="taxTotal">-</strong></div>
                                                <div class="qj-summary-row total"><span>Total</span><strong id="grandTotal">-</strong></div>
                                            </div>
                                            <div class="qj-deposit" id="depositBox">No deposit required.</div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <div id="extraSectionsHost"></div>

                            <section class="qj-section" id="signatureSection" style="display:none">
                                <div class="qj-section-card">
                                    <div class="qj-section-head"><h2>Signature</h2></div>
                                    <div class="qj-section-body"><div class="qj-signature-copy" id="signatureStatus">No signature collected.</div><img id="signaturePreview" class="qj-signature-preview" alt="Quote signature" style="display:none"></div>
                                </div>
                            </section>

                            <!-- Existing IDs retained for API/render compatibility -->
                            <span id="revisionNo" hidden></span><span id="quotationSource" hidden></span><span id="branchName" hidden></span>
                            <span id="enquiryNumber" hidden></span><span id="enquiryStatus" hidden></span><span id="enquiryTitle" hidden></span>
                            <span id="introductionTitle" hidden></span><span id="introduction" hidden></span>
                            <span id="sideStatus" hidden></span><span id="sideTotal" hidden></span><span id="sideValidUntil" hidden></span><span id="sideJob" hidden></span>
                        </section>

                        <aside class="qj-side qj-admin-only">
                            <div class="qj-side-inner">
                                <h2 class="qj-notes-title">Notes</h2>
                                <section class="qj-notes-card">
                                    <div id="sideNotesEmpty" class="qj-note-empty">
                                        <div class="qj-note-empty-icon"><i class="bi bi-journal-plus"></i></div>
                                        <p>Leave an internal note for yourself or a team member</p>
                                    </div>
                                    <div id="sideNotesView" class="qj-note-view" style="display:none">
                                        <button type="button" class="qj-note-edit-icon" id="noteEditButton" title="Edit note"><i class="bi bi-pencil"></i></button>
                                        <p id="sideNotes">No internal notes added.</p>
                                    </div>
                                    <div id="sideNotesEditor" class="qj-note-editor">
                                        <textarea id="sideNotesTextarea" maxlength="8000" placeholder="Internal note"></textarea>
                                        <div class="qj-note-editor-actions"><button type="button" class="qj-btn" id="noteCancelButton">Cancel</button><button type="button" class="qj-btn primary" id="noteSaveButton">Save</button></div>
                                    </div>
                                </section>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="qj-drawer-overlay qj-admin-only" id="historyOverlay"></div>
<aside class="qj-history-drawer qj-admin-only" id="historyDrawer">
    <div class="qj-drawer-head"><h2>Quote History</h2><button type="button" class="qj-drawer-close" id="historyClose"><i class="bi bi-x-lg"></i></button></div>
    <div class="qj-history-list" id="historyList"></div>
</aside>


<div class="qj-modal-backdrop qj-admin-only" id="emailModal">
    <div class="qj-modal qj-email-modal">
        <div class="qj-modal-head">
            <h3 id="emailModalTitle">Email quote</h3>
            <button type="button" class="qj-modal-close" id="emailClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="emailForm" enctype="multipart/form-data" autocomplete="off">
            <div class="qj-modal-body">
                <div class="qj-email-grid">
                    <div>
                        <input type="hidden" id="emailToHidden" name="to_emails" value="">
                        <div class="qj-email-to">
                            <span class="qj-email-to-label">To</span>
                            <div class="qj-email-chips" id="emailRecipientChips"></div>
                            <input type="text" id="emailRecipientInput" placeholder="Add email">
                        </div>
                        <div class="qj-email-field"><label>Subject</label><input type="text" id="emailSubject" name="email_subject" maxlength="255" required></div>
                        <div class="qj-email-field"><label>Message</label><textarea id="emailMessage" name="email_message" maxlength="8000" required></textarea></div>
                        <div class="qj-email-approval-note"><i class="bi bi-shield-check"></i><span>The email includes a secure <strong>Review &amp; Approve / Reject</strong> button for the customer.</span></div>
                    </div>
                    <aside class="qj-email-attachments">
                        <h4>Attachments</h4>
                        <div class="qj-email-drop" id="emailDropZone">
                            <button type="button" class="qj-btn" id="emailAttachmentPick">Select</button>
                            <span>Select or drag files here to upload</span>
                            <input type="file" name="email_attachments[]" id="emailAttachmentInput" multiple hidden>
                        </div>
                        <div class="qj-email-limit">You've attached <span id="emailAttachmentMb">0.00</span> MB of the 10.00 MB limit.<div class="qj-email-progress"><span id="emailAttachmentProgress"></span></div></div>
                        <div class="qj-email-system-file" id="emailAutoPdfRow">
                            <label class="qj-email-system-check" title="The quotation PDF is always attached"><input type="checkbox" checked disabled></label>
                            <div class="qj-email-pdf-icon"><i class="bi bi-file-earmark-pdf"></i></div>
                            <div class="qj-email-system-file-copy"><strong id="emailAutoPdfName">quote.pdf</strong><small>Quote PDF · attached automatically</small></div>
                            <button type="button" class="qj-email-open-pdf" id="emailOpenPdf" title="Open quote PDF"><i class="bi bi-box-arrow-up-right"></i></button>
                        </div>
                        <div class="qj-email-file-list" id="emailAttachmentList"></div>
                    </aside>
                </div>
            </div>
            <div class="qj-modal-foot" style="justify-content:space-between">
                <label class="qj-checkline"><input type="checkbox" id="emailSendCopy" value="1"> Send me a copy</label>
                <div style="display:flex;gap:8px"><button type="button" class="qj-btn" id="emailCancel">Cancel</button><button type="submit" class="qj-btn primary" id="emailSendButton">Send Email</button></div>
            </div>
        </form>
    </div>
</div>

<div class="qj-modal-backdrop qj-admin-only" id="signatureModal">
    <div class="qj-modal">
        <div class="qj-modal-head"><h3>Signature Pad</h3><button type="button" class="qj-modal-close" data-close-signature><i class="bi bi-x-lg"></i></button></div>
        <div class="qj-modal-body">
            <div class="qj-signature-box"><canvas id="signatureCanvas" width="900" height="300"></canvas><div class="qj-signature-tools"><button class="qj-small-btn" type="button" id="clearSignature">Clear</button></div></div>
            <div style="margin-top:8px;color:#657985;font-size:12px;text-align:center">Write signature</div>
            <label class="qj-checkline" style="margin-top:10px"><input type="checkbox" id="signatureSendCopy"> Send your customer a copy</label>
        </div>
        <div class="qj-modal-foot"><button type="button" class="qj-btn" data-close-signature>Cancel</button><button type="button" class="qj-btn primary" id="saveSignatureButton">Submit</button></div>
    </div>
</div>

<div class="qj-modal-backdrop qj-admin-only" id="confirmModal">
    <div class="qj-modal" style="max-width:480px">
        <div class="qj-modal-head"><h3 id="confirmTitle">Confirm action</h3><button type="button" class="qj-modal-close" id="confirmClose"><i class="bi bi-x-lg"></i></button></div>
        <div class="qj-modal-body"><div class="qj-confirm-text" id="confirmText">Are you sure?</div></div>
        <div class="qj-modal-foot"><button type="button" class="qj-btn" id="confirmCancel">Cancel</button><button type="button" class="qj-btn primary" id="confirmOk">Continue</button></div>
    </div>
</div>

<div class="qj-toast" id="toast">Notification</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
'use strict';
var quoteId=<?= (int)$quoteId ?>;
var csrfToken=<?= json_encode($csrfToken) ?>;
var clientPreview=<?= $clientPreview ? 'true' : 'false' ?>;
var basePath=<?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>;
var actionApi=basePath+'/api/quotation-view-actions.php';
var quotePrintUrl=basePath+'/quotation-print.php?quote_id='+encodeURIComponent(quoteId);
var state={quotation:null,items:[],actions:[],sections:[],files:[],paymentSchedule:[],currency:{},linked_job:null,signature:null,clientView:{quantities:true,unit_prices:true,line_item_totals:true,totals:true}};
var toastTimer=null,confirmCallback=null,signatureDrawing=false,signatureCtx=null;
var recipients=[],emailFiles=[];
function E(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function title(v){return String(v||'-').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
function formatDate(v){if(!v)return '-';var s=String(v).slice(0,10),p=s.split('-');return p.length===3?p[2]+'-'+p[1]+'-'+p[0]:s}
function formatDateTime(v){if(!v)return '-';var d=new Date(String(v).replace(' ','T'));if(isNaN(d.getTime()))return String(v);return d.toLocaleString([], {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}
function money(v){var c=state.currency||{},p=parseInt(c.decimal_places,10);if(isNaN(p))p=2;var n=Number(v||0).toFixed(p),sym=c.symbol||'';return c.symbol_position==='after'?n+(sym?' '+sym:''):(sym||'')+n}
function toast(type,msg){var t=E('toast');if(!t)return;if(toastTimer)clearTimeout(toastTimer);t.className='qj-toast '+(type||'')+' show';t.textContent=msg||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},3500)}
function parseResponse(r){return r.text().then(function(raw){var d;try{d=raw?JSON.parse(raw):{}}catch(e){throw new Error(raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||('Request failed with HTTP '+r.status+'.'));return d})}
function request(action,extra){var fd=new FormData();fd.append('action',action);fd.append('quote_id',quoteId);fd.append('csrf_token',csrfToken);Object.keys(extra||{}).forEach(function(k){fd.append(k,extra[k])});return fetch(actionApi,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
function setText(id,v,fallback){var n=E(id);if(n)n.textContent=(v===null||v===undefined||String(v).trim()==='')?(fallback===undefined?'-':fallback):String(v)}
function initials(name){var p=String(name||'').trim().split(/\s+/).filter(Boolean);return ((p[0]||'U').charAt(0)+(p.length>1?p[p.length-1].charAt(0):'')).toUpperCase()}
function propertyAddress(q){return [q.location_address1,q.location_address2,[q.location_city,q.location_state].filter(Boolean).join(', '),q.location_postal_code].filter(Boolean).join('\n')||q.location_name||'-'}
function statusLabel(status){return String(status||'draft')==='sent'?'Awaiting response':title(status)}
function renderStatus(status){var s=String(status||'draft');var badge=E('pageStatus');if(badge){badge.className='qj-status '+s;badge.textContent=statusLabel(s)}setText('sideStatus',statusLabel(s))}
function renderItems(){
    var box=E('itemRows'),items=state.items||[];if(!items.length){box.innerHTML='<div class="qj-empty">No quotation items found.</div>';return}
    box.innerHTML=items.map(function(x,i){
        var meta=[];if(!clientPreview&&Number(x.discount_amount||0)>0)meta.push('Discount '+money(x.discount_amount));if(Number(x.tax_percent||0)>0)meta.push('Tax '+Number(x.tax_percent).toFixed(2).replace(/\.?0+$/,'')+'%');if(Number(x.is_optional||0)===1)meta.push('Optional');
        var cost=Number(x.unit_cost||0),price=Number(x.unit_price||0),markup=x.markup_percent!=null?Number(x.markup_percent||0):(cost>0?((price-cost)/cost*100):0);
        var unitHtml=clientPreview?esc(money(price)):'<div class="qj-unit-cost-wrap"><button type="button" class="qj-unit-price-button" data-cost-popover="'+i+'">'+esc(money(price))+'</button><div class="qj-cost-popover" id="costPopover'+i+'"><div><span>Unit cost</span><strong>'+esc(money(cost))+'</strong></div><div><span>Markup (%)</span><strong>'+esc(markup.toFixed(2).replace(/\.?0+$/,''))+'%</strong></div><p>These calculations won\'t be visible to your customers</p></div></div>';
        var image=x.image_path?'<a class="qj-line-image" href="'+esc(x.image_path)+'" target="_blank"><img src="'+esc(x.image_path)+'" alt=""></a>':'';
        return '<div class="qj-item-row"><div class="qj-item-name"><strong>'+esc(x.item_name||'-')+'</strong><div class="qj-item-desc">'+esc(x.description||'')+'</div>'+image+(meta.length?'<div class="qj-item-meta">'+esc(meta.join(' · '))+'</div>':'')+'</div><div class="qj-num qj-qty">'+esc(Number(x.quantity||0).toFixed(3).replace(/\.?0+$/,''))+'</div><div class="qj-num qj-unit">'+unitHtml+'</div><div class="qj-num qj-total"><strong>'+esc(money(x.line_total))+'</strong></div></div>'
    }).join('');
}
function historyEvents(){var q=state.quotation||{},ev=[];ev.push({action:'created',title:'Quote created',at:q.created_at,actor:q.salesperson_name||'',comment:q.quote_no||''});if(q.sent_at)ev.push({action:'sent',title:'Quote sent',at:q.sent_at,actor:q.salesperson_name||''});if(q.viewed_at)ev.push({action:'viewed',title:'Quote viewed',at:q.viewed_at,actor:'Client'});if(q.approved_at)ev.push({action:'approved',title:'Quote approved',at:q.approved_at,actor:'Client'});(state.actions||[]).forEach(function(a){ev.push({action:a.action||'activity',title:title(a.action||'Activity'),at:a.created_at,actor:a.user_name||title(a.actor_type||''),comment:a.comment||''})});ev.sort(function(a,b){return String(b.at||'').localeCompare(String(a.at||''))});return ev}
function renderHistory(){var box=E('historyList'),ev=historyEvents();if(!ev.length){box.innerHTML='<div class="qj-empty">No quote history recorded.</div>';return}box.innerHTML=ev.map(function(x){return '<div class="qj-history-item"><span class="qj-history-avatar">'+esc(initials(x.actor||'System'))+'</span><strong>'+esc(x.actor?x.actor+' '+x.title.toLowerCase():x.title)+'</strong><small>'+esc(formatDateTime(x.at))+'</small>'+(x.comment?'<p>'+esc(x.comment)+'</p>':'')+'</div>'}).join('')}
function renderActions(){
    var q=state.quotation||{},status=String(q.status||''),edit='add-quotation.php?quote_id='+quoteId;
    document.querySelectorAll('[data-edit-quote]').forEach(function(e){e.href=edit});
    ['editButton','clientEditLink','editItemsLink'].forEach(function(id){var e=E(id);if(e)e.href=edit});
    var preview='quotation-view.php?quote_id='+quoteId+'&client_preview=1';
    if(E('previewClientLink'))E('previewClientLink').href=preview;
    var sms=E('sendTextLink');if(sms){if(q.client_phone){sms.href='sms:'+encodeURIComponent(q.client_phone);sms.classList.remove('disabled')}else{sms.href='#';sms.classList.add('disabled')}}
    if(E('emailInvoiceButton'))E('emailInvoiceButton').disabled=!q.client_email;
    if(E('awaitingButton'))E('awaitingButton').style.display=['converted','archived'].indexOf(status)>=0?'none':'flex';
    if(E('approveButton'))E('approveButton').style.display=['approved','converted','archived'].indexOf(status)>=0?'none':'flex';
    if(E('rejectButton'))E('rejectButton').style.display=['rejected','converted','archived'].indexOf(status)>=0?'none':'flex';
    if(E('reopenButton'))E('reopenButton').style.display=['archived','rejected','expired'].indexOf(status)>=0?'flex':'none';
    if(E('archiveButton'))E('archiveButton').style.display=status==='archived'?'none':'flex';
    var c=E('convertJobButton');
    if(c){
        if(state.linked_job){c.hidden=false;c.href='job-view.php?job_id='+Number(state.linked_job.id);if(E('convertJobText'))E('convertJobText').textContent='View Job';setText('sideJob',state.linked_job.job_no||'View Job')}
        else if(Number(q.can_convert_to_job||0)===1){c.hidden=false;c.href='job-form.php?quote_id='+quoteId;if(E('convertJobText'))E('convertJobText').textContent='Convert to Job';setText('sideJob','Ready to convert')}
        else{c.hidden=true;setText('sideJob','Not converted')}
    }
}

function safeJson(v,fallback){try{var x=JSON.parse(String(v||''));return x&&typeof x==='object'?x:fallback}catch(e){return fallback}}
function fileSize(n){n=Number(n||0);if(!n)return '';if(n>=1048576)return (n/1048576).toFixed(n>=10485760?0:1)+' MB';if(n>=1024)return Math.max(1,Math.round(n/1024))+' KB';return n+' B'}
function quoteFileHref(f){return String(f.file_path||f.path||'#')}
function quoteFileName(f){return String(f.original_name||f.file_name||f.name||'File')}
function filesByCategory(cat){return (state.files||[]).filter(function(f){return String(f.file_category||f.category||'')===cat})}
function editPencil(){return clientPreview?'':'<a class="qj-edit-link qj-admin-only" data-edit-quote href="add-quotation.php?quote_id='+quoteId+'" title="Edit quote"><i class="bi bi-pencil"></i></a>'}
function renderIntroduction(){
    var q=state.quotation||{},imgs=filesByCategory('introduction_image'),titleText=String(q.introduction_title||'').trim(),body=String(q.introduction||'').trim(),host=E('introductionSectionHost');if(!host)return;
    if(!titleText&&!body&&!imgs.length){host.innerHTML='';return}
    var imgHtml=imgs.length?'<div class="qj-intro-image-grid">'+imgs.map(function(f){return '<a class="qj-intro-image" href="'+esc(quoteFileHref(f))+'" target="_blank"><img src="'+esc(quoteFileHref(f))+'" alt=""></a>'}).join('')+'</div>':'';
    host.innerHTML='<section class="qj-section"><div class="qj-section-card"><div class="qj-section-head"><h2>'+esc(titleText||'Introduction')+'</h2>'+editPencil()+'</div><div class="qj-section-body">'+(body?'<p>'+esc(body)+'</p>':'')+imgHtml+'</div></div></section>';
}
function renderTextSections(){
    var host=E('textSectionsHost');if(!host)return;var rows=(state.sections||[]).filter(function(s){return String(s.section_key||'text')==='text'});
    host.innerHTML=rows.map(function(s){var heading=String(s.title||'').trim()||'Additional Information',body=String(s.body||'').trim();return '<section class="qj-section"><div class="qj-section-card"><div class="qj-section-head"><h2>'+esc(heading)+'</h2>'+editPencil()+'</div><div class="qj-section-body"><p>'+esc(body||'—')+'</p></div></div></section>'}).join('');
}
function renderExtraSections(){
    var q=state.quotation||{},host=E('extraSectionsHost');if(!host)return;var html='',attachments=filesByCategory('attachment'),images=filesByCategory('image');
    if(attachments.length){html+='<section class="qj-section"><div class="qj-section-card"><div class="qj-section-head"><h2>Attachments</h2>'+editPencil()+'</div><div class="qj-section-body"><div class="qj-file-list">'+attachments.map(function(f){return '<a class="qj-file-row-view" href="'+esc(quoteFileHref(f))+'" target="_blank"><i class="bi bi-paperclip"></i><span>'+esc(quoteFileName(f))+'</span><small>'+esc(fileSize(f.file_size))+'</small></a>'}).join('')+'</div></div></div></section>'}
    if(images.length){html+='<section class="qj-section"><div class="qj-section-card"><div class="qj-section-head"><h2>Images</h2>'+editPencil()+'</div><div class="qj-section-body"><div class="qj-image-grid">'+images.map(function(f){return '<a class="qj-image-tile" href="'+esc(quoteFileHref(f))+'" target="_blank"><img src="'+esc(quoteFileHref(f))+'" alt=""></a>'}).join('')+'</div></div></div></section>'}
    if(String(q.client_message||'').trim()){html+='<section class="qj-section"><div class="qj-section-card"><div class="qj-section-head"><h2>Customer Message</h2>'+editPencil()+'</div><div class="qj-section-body"><p>'+esc(q.client_message)+'</p></div></div></section>'}
    if(String(q.disclaimer||'').trim()){html+='<section class="qj-section"><div class="qj-section-card"><div class="qj-section-head"><h2>Contract / Disclaimer</h2>'+editPencil()+'</div><div class="qj-section-body"><p>'+esc(q.disclaimer)+'</p></div></div></section>'}
    host.innerHTML=html;
}
function syncClientViewEditor(){if(E('cvQuantities'))E('cvQuantities').checked=state.clientView.quantities!==false;if(E('cvUnitPrices'))E('cvUnitPrices').checked=state.clientView.unit_prices!==false;if(E('cvLineTotals'))E('cvLineTotals').checked=state.clientView.line_item_totals!==false;if(E('cvTotals'))E('cvTotals').checked=state.clientView.totals!==false}
function applyClientView(){
    syncClientViewEditor();if(!clientPreview)return;
    var showQty=state.clientView.quantities!==false,showUnit=state.clientView.unit_prices!==false,showLine=state.clientView.line_item_totals!==false,showTotals=state.clientView.totals!==false;
    document.querySelectorAll('.qj-head-qty,.qj-item-row .qj-qty').forEach(function(x){x.style.display=showQty?'':'none'});
    document.querySelectorAll('.qj-head-unit,.qj-item-row .qj-unit').forEach(function(x){x.style.display=showUnit?'':'none'});
    document.querySelectorAll('.qj-head-total,.qj-item-row .qj-total').forEach(function(x){x.style.display=showLine?'':'none'});
    var cols=['minmax(0,1fr)'];if(showQty)cols.push('90px');if(showUnit)cols.push('130px');if(showLine)cols.push('130px');
    document.querySelectorAll('.qj-items-head,.qj-item-row').forEach(function(x){x.style.gridTemplateColumns=cols.join(' ')});
    if(E('quoteTotalsBlock'))E('quoteTotalsBlock').style.display=showTotals?'':'none';
}
function renderPaymentPlan(){
    var q=state.quotation||{},box=E('depositBox');if(!box)return;var mode=String(q.payment_plan_mode||'');
    if(mode==='schedule'&&state.paymentSchedule.length){
        var rows=state.paymentSchedule||[];box.style.textDecoration='none';box.innerHTML='<div class="qj-payment-plan-title">Payment Schedule</div><div class="qj-payment-schedule-view">'+rows.map(function(r,i){var amt=Number(r.amount||0);if(!amt&&String(r.split_type||q.payment_plan_split_type)==='percent')amt=Number(q.total||0)*Number(r.split_value||0)/100;else if(!amt)amt=Number(r.split_value||0);return '<div><span>'+esc(r.description||('Payment '+(i+1)))+(Number(r.required_quote_deposit||0)===1?' · Quote deposit':'')+'</span><strong>'+esc(money(amt))+'</strong></div>'}).join('')+'</div>';return;
    }
    box.style.textDecoration='underline';
    var dep=Number(q.deposit_required||0)===1;if(dep){var dv=q.deposit_type==='percent'?Number(q.deposit_value||0).toFixed(2).replace(/\.?0+$/,'')+'%':money(q.deposit_value);box.textContent='Deposit required: '+dv+' · Deposit amount '+money(q.deposit_amount)}else box.textContent='No deposit required.';
}

function renderNotes(){
    if(clientPreview)return;var q=state.quotation||{},note=String(q.internal_notes||'').trim();
    if(E('sideNotes'))E('sideNotes').textContent=note||'No internal notes added.';
    if(E('sideNotesTextarea'))E('sideNotesTextarea').value=note;
    if(E('sideNotesEmpty'))E('sideNotesEmpty').style.display=note?'none':'flex';
    if(E('sideNotesView'))E('sideNotesView').style.display=note?'block':'none';
    if(E('sideNotesEditor'))E('sideNotesEditor').classList.remove('show');
}
function openNoteEditor(){if(!E('sideNotesEditor'))return;if(E('sideNotesEmpty'))E('sideNotesEmpty').style.display='none';if(E('sideNotesView'))E('sideNotesView').style.display='none';E('sideNotesEditor').classList.add('show');E('sideNotesTextarea').value=String((state.quotation||{}).internal_notes||'');setTimeout(function(){E('sideNotesTextarea').focus()},20)}
function closeNoteEditor(){renderNotes()}
function saveNote(){var b=E('noteSaveButton'),note=E('sideNotesTextarea').value;b.disabled=true;b.textContent='Saving...';request('save_notes',{internal_notes:note}).then(function(d){state.quotation.internal_notes=note;toast('success',d.message||'Note saved.');renderNotes();renderHistory();return load()}).catch(function(e){toast('error',e.message)}).finally(function(){b.disabled=false;b.textContent='Save'})}
function saveClientView(){var b=E('clientViewSave'),opts={quantities:E('cvQuantities').checked,unit_prices:E('cvUnitPrices').checked,line_item_totals:E('cvLineTotals').checked,totals:E('cvTotals').checked};b.disabled=true;b.textContent='Saving...';request('save_client_view',{client_view_options_json:JSON.stringify(opts)}).then(function(d){state.clientView=opts;toast('success',d.message||'Customer view updated.');E('clientViewPanel').classList.remove('show');applyClientView()}).catch(function(e){toast('error',e.message)}).finally(function(){b.disabled=false;b.textContent='Save'})}

function render(data){
    state.quotation=data.quotation||{};state.items=Array.isArray(data.items)?data.items:[];state.actions=Array.isArray(data.actions)?data.actions:[];state.sections=Array.isArray(data.sections)?data.sections:[];state.files=Array.isArray(data.files)?data.files:[];state.paymentSchedule=Array.isArray(data.payment_schedule)?data.payment_schedule:[];state.currency=data.currency||{};state.linked_job=data.linked_job||null;state.signature=data.signature||null;
    var q=state.quotation,defaults={quantities:true,unit_prices:true,line_item_totals:true,totals:true},saved=safeJson(q.client_view_options_json,{});state.clientView=Object.assign(defaults,saved||{});
    setText('pageTitle',q.title||('Quote '+(q.quote_no||'')),'Quotation');renderStatus(q.status);setText('clientName',q.client_name);setText('clientCompany',q.client_company||q.client_name);setText('propertyAddress',propertyAddress(q));setText('clientPhone',q.client_phone);setText('clientEmail',q.client_email);
    if(E('clientPhoneLink'))E('clientPhoneLink').href=q.client_phone?'tel:'+q.client_phone:'#';if(E('clientEmailLink'))E('clientEmailLink').href=q.client_email?'mailto:'+q.client_email:'#';setText('quoteNo',q.quote_no);
    var sp=E('salespersonName');if(sp)sp.innerHTML=q.salesperson_name?'<span class="qj-avatar-line"><span class="qj-avatar">'+esc(initials(q.salesperson_name))+'</span>'+esc(q.salesperson_name)+'</span>':'-';
    setText('createdDate',formatDate(q.created_at));setText('approvedDate',formatDate(q.approved_at));setText('sentDate',formatDate(q.sent_at));setText('viewedDate',formatDate(q.viewed_at));setText('validUntil',formatDate(q.valid_until));if(E('approvedRow'))E('approvedRow').style.display=q.approved_at?'grid':'none';
    setText('revisionNo',Number(q.revision_no||0));setText('quotationSource',q.quotation_source||'Direct Quotation');setText('branchName',q.branch_name);setText('enquiryNumber',q.request_no,'Direct Quote');setText('enquiryStatus',q.request_status?title(q.request_status):'-');setText('enquiryTitle',q.request_title);setText('introductionTitle',q.introduction_title||'Introduction');setText('introduction',q.introduction,'');
    setText('subtotal',money(q.subtotal));setText('discountTotal',money(q.discount_total));setText('taxTotal',money(q.tax_total));setText('grandTotal',money(q.total));setText('sideTotal',money(q.total));setText('sideValidUntil',formatDate(q.valid_until));
    renderPaymentPlan();
    renderItems();renderIntroduction();renderTextSections();renderExtraSections();renderNotes();renderHistory();renderActions();applyClientView();
    if(state.signature&&state.signature.file_path){if(E('signatureStatus'))E('signatureStatus').textContent='Signature collected '+formatDateTime(state.signature.created_at);if(E('signaturePreview')){E('signaturePreview').src=state.signature.file_path;E('signaturePreview').style.display='block'}if(E('signatureSection'))E('signatureSection').style.display='block'}else{if(E('signatureStatus'))E('signatureStatus').textContent='No signature collected.';if(E('signaturePreview'))E('signaturePreview').style.display='none';if(E('signatureSection'))E('signatureSection').style.display='none'}
    E('loadingState').style.display='none';E('errorState').style.display='none';E('quotationContent').style.display='block';
}
function load(){return request('get').then(render).catch(function(e){E('loadingState').style.display='none';E('quotationContent').style.display='none';E('errorState').style.display='flex';setText('errorMessage',e.message);toast('error',e.message)})}
function closeMore(){E('moreMenu').classList.remove('show')}
function showConfirm(titleText,textText,okText,cb,danger){setText('confirmTitle',titleText);setText('confirmText',textText);E('confirmOk').textContent=okText||'Continue';E('confirmOk').className='qj-btn '+(danger?'danger':'primary');confirmCallback=cb;E('confirmModal').classList.add('show')}
function closeConfirm(){E('confirmModal').classList.remove('show');confirmCallback=null}
function setStatus(status,label){closeMore();request('set_status',{status:status}).then(function(d){toast('success',d.message||label+' updated.');return load()}).catch(function(e){toast('error',e.message)})}
function createSimilar(){closeMore();request('create_similar').then(function(d){toast('success',d.message||'Similar quotation created.');setTimeout(function(){window.location.href=d.edit_url||('add-quotation.php?quote_id='+Number(d.quote_id))},650)}).catch(function(e){toast('error',e.message)})}
function openHistory(){E('historyOverlay').classList.add('show');E('historyDrawer').classList.add('show')}
function closeHistory(){E('historyOverlay').classList.remove('show');E('historyDrawer').classList.remove('show')}
function openSignature(){closeMore();if(E('signatureSendCopy'))E('signatureSendCopy').checked=false;E('signatureModal').classList.add('show');setTimeout(initSignatureCanvas,60)}
function closeSignature(){E('signatureModal').classList.remove('show')}
function initSignatureCanvas(){var c=E('signatureCanvas');if(!c)return;signatureCtx=c.getContext('2d');signatureCtx.lineWidth=3;signatureCtx.lineCap='round';signatureCtx.strokeStyle='#2478bd';clearSignature()}
function canvasPoint(e){var c=E('signatureCanvas'),r=c.getBoundingClientRect(),pt=e.touches&&e.touches[0]?e.touches[0]:e;return{x:(pt.clientX-r.left)*(c.width/r.width),y:(pt.clientY-r.top)*(c.height/r.height)}}
function startDraw(e){e.preventDefault();signatureDrawing=true;var pt=canvasPoint(e);signatureCtx.beginPath();signatureCtx.moveTo(pt.x,pt.y)}
function moveDraw(e){if(!signatureDrawing)return;e.preventDefault();var pt=canvasPoint(e);signatureCtx.lineTo(pt.x,pt.y);signatureCtx.stroke()}
function endDraw(e){if(e)e.preventDefault();signatureDrawing=false}
function clearSignature(){var c=E('signatureCanvas');if(!c||!signatureCtx)return;signatureCtx.clearRect(0,0,c.width,c.height);signatureCtx.fillStyle='#fff';signatureCtx.fillRect(0,0,c.width,c.height);signatureCtx.strokeStyle='#2478bd'}
function saveSignature(){var c=E('signatureCanvas'),data=c.toDataURL('image/png'),b=E('saveSignatureButton'),sendCopy=E('signatureSendCopy')&&E('signatureSendCopy').checked?1:0;b.disabled=true;b.textContent='Saving...';request('save_signature',{signature_data:data,send_copy:sendCopy}).then(function(d){toast(d.email_failed?'warning':'success',(d.message||'Signature saved.')+(d.email_notice?' '+d.email_notice:''));closeSignature();return load()}).catch(function(e){toast('error',e.message)}).finally(function(){b.disabled=false;b.textContent='Submit'})}
function validEmail(v){return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)}
function syncRecipients(){var h=E('emailToHidden'),box=E('emailRecipientChips');if(h)h.value=recipients.join(',');if(box)box.innerHTML=recipients.map(function(v,i){return '<span class="qj-email-chip">'+esc(v)+'<button type="button" data-remove-recipient="'+i+'"><i class="bi bi-x"></i></button></span>'}).join('')}
function addRecipient(v){String(v||'').split(/[;,\s]+/).forEach(function(x){x=x.trim().toLowerCase();if(validEmail(x)&&recipients.indexOf(x)<0)recipients.push(x)});syncRecipients()}
function rebuildEmailFiles(){var inp=E('emailAttachmentInput');if(inp&&typeof DataTransfer!=='undefined'){var dt=new DataTransfer();emailFiles.forEach(function(f){dt.items.add(f)});inp.files=dt.files}var total=emailFiles.reduce(function(n,f){return n+Number(f.size||0)},0);setText('emailAttachmentMb',(total/1048576).toFixed(2),'0.00');if(E('emailAttachmentProgress'))E('emailAttachmentProgress').style.width=Math.min(100,total/(10*1048576)*100)+'%';if(E('emailAttachmentList'))E('emailAttachmentList').innerHTML=emailFiles.map(function(f,i){return '<div class="qj-email-file"><i class="bi bi-paperclip"></i><span>'+esc(f.name)+'</span><small>'+Math.max(1,Math.round(f.size/1024))+' KB</small><button type="button" data-remove-email-file="'+i+'"><i class="bi bi-x-lg"></i></button></div>'}).join('')}
function addEmailFiles(list){Array.prototype.slice.call(list||[]).forEach(function(f){var current=emailFiles.reduce(function(n,x){return n+Number(x.size||0)},0);if(current+Number(f.size||0)>10*1048576){toast('error','Email attachments cannot exceed 10 MB in total.');return}emailFiles.push(f)});rebuildEmailFiles()}
function openQuotePdf(){var w=window.open(quotePrintUrl,'_blank','noopener');if(!w)window.location.href=quotePrintUrl}
function openEmail(){closeMore();var q=state.quotation||{};if(!q.client_email){toast('warning','This customer does not have an email address.');return}recipients=[];emailFiles=[];addRecipient(q.client_email);rebuildEmailFiles();var company=q.branch_name||q.tenant_name||'FieldPlx';setText('emailModalTitle','Email quote '+(q.quote_no||'')+' to '+(q.client_name||'Customer'));E('emailSubject').value='Quote from '+company+' - '+formatDate(q.created_at);E('emailMessage').value='Hi '+(q.client_name||'')+',\n\nThank you for asking us to quote on your project.\n\nThe quote total is '+money(q.total)+' as of '+formatDate(q.created_at)+'.\n\nPlease review the attached quotation PDF and use the secure approval button in this email to Approve or Reject the quote.\n\nIf you have any questions or concerns regarding this quote, please don\'t hesitate to get in touch with us.\n\nSincerely,\n\n'+company;setText('emailAutoPdfName','quote_'+String(q.quote_no||quoteId).replace(/[^A-Za-z0-9_-]/g,'_')+'.pdf');E('emailSendCopy').checked=false;E('emailModal').classList.add('show');setTimeout(function(){E('emailRecipientInput').focus()},70)}
function closeEmail(){E('emailModal').classList.remove('show')}
function sendEmailForm(e){e.preventDefault();var pending=E('emailRecipientInput').value.trim();if(pending){addRecipient(pending);E('emailRecipientInput').value=''}if(!recipients.length){toast('error','Add at least one valid recipient email address.');return}var subject=E('emailSubject').value.trim(),message=E('emailMessage').value.trim();if(!subject){toast('error','Email subject is required.');E('emailSubject').focus();return}if(!message){toast('error','Email message is required.');E('emailMessage').focus();return}var fd=new FormData();fd.append('action','send_email');fd.append('quote_id',quoteId);fd.append('csrf_token',csrfToken);fd.append('to_emails',recipients.join(','));fd.append('email_subject',subject);fd.append('email_message',message);fd.append('attach_quote_pdf','1');fd.append('include_approval_link','1');if(E('emailSendCopy').checked)fd.append('send_me_copy','1');emailFiles.forEach(function(f){fd.append('email_attachments[]',f,f.name)});var b=E('emailSendButton');b.disabled=true;b.textContent='Sending...';fetch(actionApi,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse).then(function(d){closeEmail();var msg=d.message||'Quotation email sent successfully.';if(Number(d.pdf_attached||0)!==1)msg+=' PDF was not attached.';toast(Number(d.pdf_attached||0)===1?'success':'warning',msg);return load()}).catch(function(err){toast('error',err.message)}).finally(function(){b.disabled=false;b.textContent='Send Email'})}
function initEmailComposer(){var inp=E('emailRecipientInput'),chips=E('emailRecipientChips'),fileInput=E('emailAttachmentInput'),pick=E('emailAttachmentPick'),list=E('emailAttachmentList'),drop=E('emailDropZone');if(inp){inp.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===','||e.key===';'){e.preventDefault();addRecipient(this.value);this.value=''}});inp.addEventListener('blur',function(){if(this.value.trim()){addRecipient(this.value);this.value=''}})}if(chips)chips.addEventListener('click',function(e){var b=e.target.closest('[data-remove-recipient]');if(!b)return;recipients.splice(Number(b.getAttribute('data-remove-recipient')),1);syncRecipients()});if(pick)pick.addEventListener('click',function(){fileInput.click()});if(fileInput)fileInput.addEventListener('change',function(){addEmailFiles(this.files)});if(list)list.addEventListener('click',function(e){var b=e.target.closest('[data-remove-email-file]');if(!b)return;emailFiles.splice(Number(b.getAttribute('data-remove-email-file')),1);rebuildEmailFiles()});if(drop){['dragenter','dragover'].forEach(function(n){drop.addEventListener(n,function(e){e.preventDefault()})});drop.addEventListener('drop',function(e){e.preventDefault();addEmailFiles(e.dataTransfer.files)})}if(E('emailOpenPdf'))E('emailOpenPdf').addEventListener('click',openQuotePdf);if(E('emailAutoPdfRow'))E('emailAutoPdfRow').addEventListener('dblclick',openQuotePdf);E('emailForm').addEventListener('submit',sendEmailForm);E('emailClose').addEventListener('click',closeEmail);E('emailCancel').addEventListener('click',closeEmail);E('emailModal').addEventListener('click',function(e){if(e.target===E('emailModal'))closeEmail()})}
function init(){if(quoteId<=0){E('loadingState').style.display='none';E('errorState').style.display='flex';setText('errorMessage','Invalid quotation ID.');return}if(!clientPreview){E('moreButton').addEventListener('click',function(e){e.stopPropagation();E('moreMenu').classList.toggle('show')});document.addEventListener('click',function(e){if(!e.target.closest('#moreMenu')&&!e.target.closest('#moreButton'))closeMore()});E('historyButton').addEventListener('click',openHistory);E('historyClose').addEventListener('click',closeHistory);E('historyOverlay').addEventListener('click',closeHistory);E('similarButton').addEventListener('click',createSimilar);E('emailInvoiceButton').addEventListener('click',openEmail);E('awaitingButton').addEventListener('click',function(){setStatus('awaiting_response','Awaiting Response')});E('approveButton').addEventListener('click',function(){setStatus('approved','Approved')});E('rejectButton').addEventListener('click',function(){showConfirm('Mark quotation rejected?','This changes the quotation status to Rejected.','Mark Rejected',function(){setStatus('rejected','Rejected')},true)});E('reopenButton').addEventListener('click',function(){setStatus('draft','Draft')});E('archiveButton').addEventListener('click',function(){closeMore();showConfirm('Archive quotation?','The quotation will remain in FieldPlx but move to the archived status.','Archive',function(){setStatus('archived','Archived')},false)});E('deleteButton').addEventListener('click',function(){closeMore();showConfirm('Delete quotation?','This permanently deletes the quotation and its line items. A quote linked to a job cannot be deleted.','Delete',function(){request('delete').then(function(d){toast('success',d.message);setTimeout(function(){window.location.href=d.redirect||'quotations'},650)}).catch(function(e){toast('error',e.message)})},true)});E('printButton').addEventListener('click',function(){closeMore();openQuotePdf()});E('signatureButton').addEventListener('click',openSignature);document.querySelectorAll('[data-close-signature]').forEach(function(x){x.addEventListener('click',closeSignature)});E('clearSignature').addEventListener('click',clearSignature);E('saveSignatureButton').addEventListener('click',saveSignature);var c=E('signatureCanvas');['mousedown','touchstart'].forEach(function(n){c.addEventListener(n,startDraw,{passive:false})});['mousemove','touchmove'].forEach(function(n){c.addEventListener(n,moveDraw,{passive:false})});['mouseup','mouseleave','touchend','touchcancel'].forEach(function(n){c.addEventListener(n,endDraw,{passive:false})});E('confirmCancel').addEventListener('click',closeConfirm);E('confirmClose').addEventListener('click',closeConfirm);E('confirmOk').addEventListener('click',function(){var cb=confirmCallback;closeConfirm();if(typeof cb==='function')cb()});initEmailComposer();if(E('sideNotesEmpty'))E('sideNotesEmpty').addEventListener('click',openNoteEditor);if(E('noteEditButton'))E('noteEditButton').addEventListener('click',openNoteEditor);if(E('noteCancelButton'))E('noteCancelButton').addEventListener('click',closeNoteEditor);if(E('noteSaveButton'))E('noteSaveButton').addEventListener('click',saveNote);if(E('clientViewChange'))E('clientViewChange').addEventListener('click',function(){syncClientViewEditor();E('clientViewPanel').classList.add('show')});if(E('clientViewCancel'))E('clientViewCancel').addEventListener('click',function(){E('clientViewPanel').classList.remove('show');syncClientViewEditor()});if(E('clientViewSave'))E('clientViewSave').addEventListener('click',saveClientView);document.addEventListener('click',function(e){var b=e.target.closest('[data-cost-popover]');if(b){e.stopPropagation();var id='costPopover'+b.getAttribute('data-cost-popover');document.querySelectorAll('.qj-cost-popover').forEach(function(p){if(p.id!==id)p.classList.remove('show')});if(E(id))E(id).classList.toggle('show');return}if(!e.target.closest('.qj-cost-popover'))document.querySelectorAll('.qj-cost-popover').forEach(function(p){p.classList.remove('show')})});document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeMore();closeHistory();closeEmail();closeSignature();closeConfirm();if(E('clientViewPanel'))E('clientViewPanel').classList.remove('show')}})}load()}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
</script>
</body>
</html>
