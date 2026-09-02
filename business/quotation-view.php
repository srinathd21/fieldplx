<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Quotation View';
$activePage = 'quotes';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['quotations_csrf_token'])) {
    $_SESSION['quotations_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['quotations_csrf_token'];
$quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : 0;
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
    </style>
</head>

<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>

<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="fd-dashboard qv-page">

                <section class="qv-head">
                    <div class="qv-head-copy">
                        <div class="qv-title-row">
                            <h1 class="qv-title" id="pageTitle">Quotation</h1>
                            <span class="qv-badge draft" id="pageStatus">Loading</span>
                        </div>
                        <p class="qv-sub" id="pageSubtitle">
                            View complete saved quotation details, customer information, enquiry, pricing and quotation activity.
                        </p>
                    </div>

                    <div class="qv-actions">
                        <a class="qv-btn" href="quotations">
                            <i class="bi bi-arrow-left"></i>
                            Back
                        </a>

                        <button type="button" class="qv-btn" id="printButton">
                            <i class="bi bi-printer"></i>
                            Print
                        </button>

                        <button type="button" class="qv-btn email" id="sendEmailButton" style="display:none">
                            <span class="qv-btn-spinner" aria-hidden="true"></span>
                            <i class="bi bi-envelope-arrow-up"></i>
                            <span id="sendEmailButtonText">Send Email</span>
                        </button>

                        <a class="qv-btn primary" href="#" id="editButton" style="display:none">
                            <i class="bi bi-pencil"></i>
                            Edit Quotation
                        </a>
                    </div>
                </section>

                <div id="loadingState" class="qv-loading">
                    <div class="qv-loading-inner">
                        <div class="qv-spinner"></div>
                        <div>Loading quotation details...</div>
                    </div>
                </div>

                <div id="errorState" class="qv-error" style="display:none">
                    <div>
                        <i class="bi bi-exclamation-circle"></i>
                        <h2>Unable to load quotation</h2>
                        <p id="errorMessage">The quotation could not be loaded.</p>
                        <a class="qv-btn" href="quotations">
                            <i class="bi bi-arrow-left"></i>
                            Back to Quotations
                        </a>
                    </div>
                </div>

                <div id="quotationContent" style="display:none">

                    <section class="qv-card" style="margin-bottom:16px">
                        <div class="qv-card-head">
                            <div>
                                <h2>Quotation Overview</h2>
                                <small>Primary quotation information</small>
                            </div>
                        </div>

                        <div class="qv-card-body">
                            <div class="qv-overview">
                                <div class="qv-info">
                                    <span class="qv-info-label">Quotation No.</span>
                                    <span class="qv-info-value large" id="quoteNo">-</span>
                                </div>

                                <div class="qv-info">
                                    <span class="qv-info-label">Revision</span>
                                    <span class="qv-info-value" id="revisionNo">-</span>
                                </div>

                                <div class="qv-info">
                                    <span class="qv-info-label">Quotation Date</span>
                                    <span class="qv-info-value" id="createdDate">-</span>
                                </div>

                                <div class="qv-info">
                                    <span class="qv-info-label">Valid Until</span>
                                    <span class="qv-info-value" id="validUntil">-</span>
                                </div>

                                <div class="qv-info">
                                    <span class="qv-info-label">Source</span>
                                    <span class="qv-info-value" id="quotationSource">-</span>
                                </div>

                                <div class="qv-info">
                                    <span class="qv-info-label">Enquiry No.</span>
                                    <span class="qv-info-value" id="requestNo">-</span>
                                </div>

                                <div class="qv-info">
                                    <span class="qv-info-label">Branch</span>
                                    <span class="qv-info-value" id="branchName">-</span>
                                </div>

                                <div class="qv-info">
                                    <span class="qv-info-label">Salesperson</span>
                                    <span class="qv-info-value" id="salespersonName">-</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="qv-grid">

                        <div class="qv-stack">

                            <section class="qv-card">
                                <div class="qv-card-head">
                                    <div>
                                        <h2>Quotation Details</h2>
                                        <small>Customer, enquiry and quotation description</small>
                                    </div>
                                </div>

                                <div class="qv-card-body">
                                    <div class="qv-section-grid">
                                        <div class="qv-detail">
                                            <span class="label">Title</span>
                                            <span class="value" id="quoteTitle">-</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Status</span>
                                            <span class="value" id="statusText">-</span>
                                        </div>

                                        <div class="qv-detail full">
                                            <span class="label">Introduction / Notes</span>
                                            <span class="value" id="introduction">-</span>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="qv-card">
                                <div class="qv-card-head">
                                    <div>
                                        <h2>Customer & Enquiry</h2>
                                        <small>Linked customer and original service enquiry information</small>
                                    </div>
                                </div>

                                <div class="qv-card-body">
                                    <div class="qv-section-grid">
                                        <div class="qv-detail">
                                            <span class="label">Customer</span>
                                            <span class="value" id="clientName">-</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Company</span>
                                            <span class="value" id="clientCompany">-</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Email</span>
                                            <span class="value" id="clientEmail">-</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Email Notification</span>
                                            <span class="value" id="emailNotificationStatus">-</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Phone</span>
                                            <span class="value" id="clientPhone">-</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Enquiry</span>
                                            <span class="value" id="enquiryNumber">-</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Enquiry Status</span>
                                            <span class="value" id="enquiryStatus">-</span>
                                        </div>

                                        <div class="qv-detail full">
                                            <span class="label">Enquiry Title</span>
                                            <span class="value" id="enquiryTitle">-</span>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="qv-card">
                                <div class="qv-card-head">
                                    <div>
                                        <h2>Quotation Items</h2>
                                        <small id="itemCount">0 items</small>
                                    </div>
                                </div>

                                <div class="qv-table-wrap">
                                    <table class="qv-table">
                                        <thead>
                                        <tr>
                                            <th>S.No</th>
                                            <th>Item</th>
                                            <th>Qty</th>
                                            <th>Unit Cost</th>
                                            <th>Unit Price</th>
                                            <th>Discount</th>
                                            <th>Tax %</th>
                                            <th>Tax</th>
                                            <th>Line Total</th>
                                        </tr>
                                        </thead>
                                        <tbody id="itemRows">
                                        <tr>
                                            <td colspan="9" class="qv-empty">No items.</td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                        </div>

                        <aside class="qv-stack">

                            <section class="qv-card">
                                <div class="qv-card-head">
                                    <div>
                                        <h2>Amount Summary</h2>
                                        <small>Saved quotation calculation</small>
                                    </div>
                                </div>

                                <div class="qv-card-body">
                                    <div class="qv-summary">
                                        <div class="qv-summary-row">
                                            <span>Subtotal</span>
                                            <strong id="subtotal">-</strong>
                                        </div>

                                        <div class="qv-summary-row">
                                            <span>Discount</span>
                                            <strong id="discountTotal">-</strong>
                                        </div>

                                        <div class="qv-summary-row">
                                            <span>Tax</span>
                                            <strong id="taxTotal">-</strong>
                                        </div>

                                        <div class="qv-summary-row total">
                                            <span>Grand Total</span>
                                            <strong id="grandTotal">-</strong>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="qv-card">
                                <div class="qv-card-head">
                                    <div>
                                        <h2>Deposit Details</h2>
                                        <small>Required deposit configuration</small>
                                    </div>
                                </div>

                                <div class="qv-card-body">
                                    <div class="qv-section-grid">
                                        <div class="qv-detail">
                                            <span class="label">Deposit Required</span>
                                            <span class="value" id="depositRequired">No</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Deposit Type</span>
                                            <span class="value" id="depositType">-</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Deposit Value</span>
                                            <span class="value" id="depositValue">-</span>
                                        </div>

                                        <div class="qv-detail">
                                            <span class="label">Deposit Amount</span>
                                            <span class="value" id="depositAmount">-</span>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="qv-card">
                                <div class="qv-card-head">
                                    <div>
                                        <h2>Quotation Timeline</h2>
                                        <small>Sent, viewed, approved and response history</small>
                                    </div>
                                </div>

                                <div class="qv-card-body">
                                    <div class="qv-timeline" id="timeline">
                                        <div class="qv-empty">No quotation activity recorded.</div>
                                    </div>
                                </div>
                            </section>

                        </aside>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="qv-toast" id="toast">
    <span id="toastMessage">Notification</span>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function () {
    'use strict';

    var quoteId = <?= (int)$quoteId ?>;
    var csrfToken = <?= json_encode($csrfToken) ?>;
    var basePath = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')) ?>;
    var apiUrl = basePath + '/api/quotation-list.php';
    var toastTimer = null;

    function el(id) {
        return document.getElementById(id);
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function text(id, value, fallback) {
        var node = el(id);

        if (!node) {
            return;
        }

        var output = value;

        if (output === null || output === undefined || String(output).trim() === '') {
            output = fallback === undefined ? '-' : fallback;
        }

        node.textContent = String(output);
    }

    function title(value) {
        return String(value || '-')
            .replace(/_/g, ' ')
            .replace(/\b\w/g, function (letter) {
                return letter.toUpperCase();
            });
    }

    function formatDate(value) {
        if (!value) {
            return '-';
        }

        var source = String(value).substring(0, 10);
        var parts = source.split('-');

        if (parts.length === 3) {
            return parts[2] + '-' + parts[1] + '-' + parts[0];
        }

        return source;
    }

    function formatDateTime(value) {
        if (!value) {
            return '-';
        }

        var raw = String(value);
        var parts = raw.split(' ');
        var date = formatDate(parts[0]);

        if (parts.length < 2) {
            return date;
        }

        var timeParts = parts[1].split(':');

        if (timeParts.length < 2) {
            return date;
        }

        var hour = parseInt(timeParts[0], 10);
        var minute = timeParts[1];
        var suffix = hour >= 12 ? 'PM' : 'AM';

        hour = hour % 12;
        if (hour === 0) {
            hour = 12;
        }

        return date + ' ' + hour + ':' + minute + ' ' + suffix;
    }

    function money(value, currency) {
        var amount = Number(value || 0);
        var config = currency || {};
        var places = parseInt(config.decimal_places, 10);

        if (isNaN(places)) {
            places = 2;
        }

        var number = amount.toFixed(places);
        var symbol = config.symbol || '';

        return config.symbol_position === 'after'
            ? number + (symbol ? ' ' + symbol : '')
            : (symbol || '') + number;
    }

    function notify(type, message) {
        var toast = el('toast');
        var msg = el('toastMessage');

        if (!toast || !msg) {
            return;
        }

        if (toastTimer) {
            clearTimeout(toastTimer);
        }

        toast.className = 'qv-toast ' + (type || '') + ' show';
        msg.textContent = message || 'Notification';

        toastTimer = setTimeout(function () {
            toast.classList.remove('show');
        }, 3500);
    }

    function parseResponse(response) {
        return response.text().then(function (raw) {
            var data;
            var output = String(raw || '').trim();

            try {
                data = output ? JSON.parse(output) : {};
            } catch (error) {
                throw new Error(
                    output.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() ||
                    'Invalid server response.'
                );
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || ('Request failed with HTTP ' + response.status + '.'));
            }

            return data;
        });
    }

    function requestQuotation() {
        var formData = new FormData();

        formData.append('action', 'get');
        formData.append('quote_id', quoteId);
        formData.append('csrf_token', csrfToken);

        return fetch(apiUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(parseResponse);
    }


    function resendQuotationEmail() {
        var button = el('sendEmailButton');
        var buttonText = el('sendEmailButtonText');

        if (!button || button.disabled) {
            return;
        }

        var originalText = buttonText ? buttonText.textContent : 'Send Email';
        var formData = new FormData();

        formData.append('action', 'resend_email');
        formData.append('quote_id', quoteId);
        formData.append('csrf_token', csrfToken);

        button.disabled = true;
        button.classList.add('is-loading');

        if (buttonText) {
            buttonText.textContent = 'Sending...';
        }

        fetch(apiUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(parseResponse)
        .then(function (data) {
            notify('success', data.message || 'Quotation email sent successfully.');

            if (buttonText) {
                buttonText.textContent = 'Resend Email';
            }

            return requestQuotation();
        })
        .then(function (data) {
            renderQuotation(data);
        })
        .catch(function (error) {
            console.error('FieldPlx quotation email error:', error);
            notify('error', error.message || 'Unable to send quotation email.');

            if (buttonText) {
                buttonText.textContent = originalText;
            }
        })
        .finally(function () {
            button.disabled = false;
            button.classList.remove('is-loading');
        });
    }

    function renderStatus(status) {
        var badge = el('pageStatus');

        if (!badge) {
            return;
        }

        var safeStatus = status || 'draft';
        badge.className = 'qv-badge ' + safeStatus;
        badge.textContent = title(safeStatus);
    }

    function renderItems(items, currency) {
        var body = el('itemRows');

        if (!body) {
            return;
        }

        text('itemCount', items.length + (items.length === 1 ? ' item' : ' items'));

        if (!items.length) {
            body.innerHTML = '<tr><td colspan="9" class="qv-empty">No quotation items found.</td></tr>';
            return;
        }

        var html = [];

        items.forEach(function (item, index) {
            var description = item.description
                ? '<small>' + esc(item.description) + '</small>'
                : '';

            var optional = Number(item.is_optional || 0) === 1
                ? '<span class="qv-optional">Optional</span>'
                : '';

            html.push(
                '<tr>' +
                    '<td>' + (index + 1) + '</td>' +
                    '<td><div class="qv-item-name"><strong>' +
                        esc(item.item_name || '-') +
                        '</strong>' + description + optional + '</div></td>' +
                    '<td>' + esc(Number(item.quantity || 0).toFixed(3).replace(/\.?0+$/, '')) + '</td>' +
                    '<td>' + esc(money(item.unit_cost, currency)) + '</td>' +
                    '<td>' + esc(money(item.unit_price, currency)) + '</td>' +
                    '<td>' + esc(money(item.discount_amount, currency)) + '</td>' +
                    '<td>' + esc(Number(item.tax_percent || 0).toFixed(2).replace(/\.?0+$/, '')) + '%</td>' +
                    '<td>' + esc(money(item.tax_amount, currency)) + '</td>' +
                    '<td><strong>' + esc(money(item.line_total, currency)) + '</strong></td>' +
                '</tr>'
            );
        });

        body.innerHTML = html.join('');
    }

    function eventIcon(action) {
        var icons = {
            sent: 'bi-send',
            viewed: 'bi-eye',
            approved: 'bi-check2-circle',
            rejected: 'bi-x-circle',
            changes_requested: 'bi-arrow-repeat',
            commented: 'bi-chat-left-text'
        };

        return icons[action] || 'bi-clock-history';
    }

    function renderTimeline(quotation, actions) {
        var timeline = el('timeline');

        if (!timeline) {
            return;
        }

        var events = [];

        events.push({
            action: 'created',
            title: 'Quotation Created',
            at: quotation.created_at,
            comment: quotation.quote_no || ''
        });

        if (quotation.sent_at) {
            events.push({
                action: 'sent',
                title: 'Quotation Sent',
                at: quotation.sent_at,
                comment: ''
            });
        }

        if (quotation.viewed_at) {
            events.push({
                action: 'viewed',
                title: 'Quotation Viewed',
                at: quotation.viewed_at,
                comment: ''
            });
        }

        if (quotation.approved_at) {
            events.push({
                action: 'approved',
                title: 'Quotation Approved',
                at: quotation.approved_at,
                comment: ''
            });
        }

        actions.forEach(function (action) {
            events.push({
                action: action.action || 'activity',
                title: title(action.action || 'Activity'),
                at: action.created_at,
                comment: action.comment || '',
                actor: action.user_name || title(action.actor_type || '')
            });
        });

        events.sort(function (a, b) {
            return String(b.at || '').localeCompare(String(a.at || ''));
        });

        if (!events.length) {
            timeline.innerHTML = '<div class="qv-empty">No quotation activity recorded.</div>';
            return;
        }

        var html = [];

        events.forEach(function (event) {
            var actor = event.actor
                ? ' • ' + esc(event.actor)
                : '';

            var comment = event.comment
                ? '<p>' + esc(event.comment) + '</p>'
                : '';

            html.push(
                '<div class="qv-event">' +
                    '<span class="qv-event-icon"><i class="bi ' + esc(eventIcon(event.action)) + '"></i></span>' +
                    '<strong>' + esc(event.title) + '</strong>' +
                    '<small>' + esc(formatDateTime(event.at)) + actor + '</small>' +
                    comment +
                '</div>'
            );
        });

        timeline.innerHTML = html.join('');
    }

    function renderQuotation(data) {
        var quotation = data.quotation || {};
        var items = Array.isArray(data.items) ? data.items : [];
        var actions = Array.isArray(data.actions) ? data.actions : [];
        var currency = data.currency || {};

        text('pageTitle', quotation.quote_no ? 'Quotation ' + quotation.quote_no : 'Quotation');
        renderStatus(quotation.status);

        text(
            'pageSubtitle',
            quotation.title ||
            'View complete saved quotation details, customer information, enquiry, pricing and quotation activity.'
        );

        text('quoteNo', quotation.quote_no);
        text('revisionNo', Number(quotation.revision_no || 0));
        text('createdDate', formatDate(quotation.created_date || quotation.created_at));
        text('validUntil', formatDate(quotation.valid_until));
        text('quotationSource', quotation.quotation_source);
        text('requestNo', quotation.request_no, 'Direct');
        text('branchName', quotation.branch_name);
        text('salespersonName', quotation.salesperson_name);

        text('quoteTitle', quotation.title);
        text('statusText', title(quotation.status));
        text('introduction', quotation.introduction);

        text('clientName', quotation.client_name);
        text('clientCompany', quotation.client_company);
        text('clientEmail', quotation.client_email);
        text('clientPhone', quotation.client_phone);

        if (quotation.sent_at) {
            text('emailNotificationStatus', 'Sent on ' + formatDateTime(quotation.sent_at));
        } else if (Number(quotation.can_resend_email || 0) === 1) {
            text('emailNotificationStatus', 'Not sent yet');
        } else {
            text('emailNotificationStatus', 'Email unavailable');
        }

        text('enquiryNumber', quotation.request_no, 'Direct Quotation');
        text('enquiryStatus', quotation.request_status ? title(quotation.request_status) : '-');
        text('enquiryTitle', quotation.request_title);

        text('subtotal', money(quotation.subtotal, currency));
        text('discountTotal', money(quotation.discount_total, currency));
        text('taxTotal', money(quotation.tax_total, currency));
        text('grandTotal', money(quotation.total, currency));

        var depositRequired = Number(quotation.deposit_required || 0) === 1;

        text('depositRequired', depositRequired ? 'Yes' : 'No');
        text('depositType', depositRequired ? title(quotation.deposit_type) : '-');

        if (depositRequired && quotation.deposit_type === 'percent') {
            text('depositValue', Number(quotation.deposit_value || 0).toFixed(2).replace(/\.?0+$/, '') + '%');
        } else if (depositRequired) {
            text('depositValue', money(quotation.deposit_value, currency));
        } else {
            text('depositValue', '-');
        }

        text(
            'depositAmount',
            depositRequired
                ? money(quotation.deposit_amount, currency)
                : '-'
        );

        renderItems(items, currency);
        renderTimeline(quotation, actions);

        var editableStatuses = [
            'draft',
            'internal_approval',
            'changes_requested'
        ];

        var editButton = el('editButton');
        var sendEmailButton = el('sendEmailButton');
        var sendEmailButtonText = el('sendEmailButtonText');

        if (editButton) {
            editButton.style.display = 'none';

            if (editableStatuses.indexOf(quotation.status) >= 0) {
                editButton.href = 'add-quotation?quote_id=' + Number(quotation.id || quoteId);
                editButton.style.display = 'inline-flex';
            }
        }

        if (sendEmailButton) {
            sendEmailButton.style.display = 'none';
            sendEmailButton.disabled = false;
            sendEmailButton.classList.remove('is-loading');

            if (Number(quotation.can_resend_email || 0) === 1) {
                if (sendEmailButtonText) {
                    sendEmailButtonText.textContent = quotation.sent_at
                        ? 'Resend Email'
                        : 'Send Email';
                }

                sendEmailButton.style.display = 'inline-flex';
            }
        }

        el('loadingState').style.display = 'none';
        el('errorState').style.display = 'none';
        el('quotationContent').style.display = 'block';
    }

    function showError(message) {
        el('loadingState').style.display = 'none';
        el('quotationContent').style.display = 'none';
        el('errorState').style.display = 'flex';
        text('errorMessage', message || 'The quotation could not be loaded.');
        notify('error', message || 'Unable to load quotation.');
    }

    function init() {
        if (quoteId <= 0) {
            showError('Invalid quotation ID.');
            return;
        }

        var printButton = el('printButton');
        var sendEmailButton = el('sendEmailButton');

        if (printButton) {
            printButton.addEventListener('click', function () {
                window.print();
            });
        }

        if (sendEmailButton) {
            sendEmailButton.addEventListener('click', function () {
                resendQuotationEmail();
            });
        }

        requestQuotation()
            .then(renderQuotation)
            .catch(function (error) {
                console.error('FieldPlx quotation view error:', error);
                showError(error.message || 'Unable to load quotation.');
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

</body>
</html>
