<?php
/* FieldPlx Client Merge Page - Version 1.1.0 - Invoice/Add Invoice UI */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Merge Clients';
$pageDescription = 'Merge duplicate client records while preserving linked work and history';
$activePage = 'clients';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['client_merge_csrf_token'])) {
    $_SESSION['client_merge_csrf_token'] = bin2hex(random_bytes(32));
}
$clientMergeCsrfToken = (string)$_SESSION['client_merge_csrf_token'];
$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$userId = isset($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);

function cmpTableExists(PDO $pdo, $table)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n");
    $q->execute(array(':n' => $table));
    return (int)$q->fetchColumn() > 0;
}

function cmpCanView(PDO $pdo, $tenantId, $userId)
{
    if ($tenantId <= 0 || $userId <= 0) {
        return false;
    }
    if (!cmpTableExists($pdo, 'users')) {
        return false;
    }
    $roleJoin = cmpTableExists($pdo, 'roles') ? "LEFT JOIN roles r ON r.id = u.role_id AND r.tenant_id = u.tenant_id" : '';
    $roleAdmin = cmpTableExists($pdo, 'roles') ? 'COALESCE(r.is_admin,0)' : '0';
    $q = $pdo->prepare("SELECT u.role_id, u.is_tenant_admin, $roleAdmin AS role_admin FROM users u $roleJoin WHERE u.id=:u AND u.tenant_id=:t AND u.deleted_at IS NULL LIMIT 1");
    $q->execute(array(':u' => $userId, ':t' => $tenantId));
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return false;
    }
    if ((int)$u['is_tenant_admin'] === 1 || (int)$u['role_admin'] === 1) {
        return true;
    }
    if (!cmpTableExists($pdo, 'permissions')) {
        return false;
    }
    $q = $pdo->prepare("SELECT id FROM permissions WHERE permission_code='clients.view' LIMIT 1");
    $q->execute();
    $permissionId = (int)$q->fetchColumn();
    if ($permissionId <= 0) {
        return false;
    }
    if (cmpTableExists($pdo, 'user_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM user_permissions WHERE tenant_id=:t AND user_id=:u AND permission_id=:p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':u' => $userId, ':p' => $permissionId));
        $a = $q->fetchColumn();
        if ($a !== false) {
            return $a === 'allow';
        }
    }
    $roleId = (int)($u['role_id'] ?? 0);
    if ($roleId > 0 && cmpTableExists($pdo, 'role_permissions')) {
        $q = $pdo->prepare("SELECT access_type FROM role_permissions WHERE tenant_id=:t AND role_id=:r AND permission_id=:p LIMIT 1");
        $q->execute(array(':t' => $tenantId, ':r' => $roleId, ':p' => $permissionId));
        return $q->fetchColumn() === 'allow';
    }
    return false;
}

if (!cmpCanView($pdo, $tenantId, $userId)) {
    http_response_code(403);
    exit('You do not have permission to view client merge.');
}

$initialIds = array();
if (!empty($_GET['client_ids'])) {
    foreach (explode(',', (string)$_GET['client_ids']) as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $initialIds[$id] = $id;
        }
    }
}
$initialIds = array_values($initialIds);
$initialPrimaryId = isset($_GET['primary_id']) ? (int)$_GET['primary_id'] : 0;


require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ==========================================================
           FieldPlx Client Merge - Invoice/Add Invoice UI v1.1.0
           Uses the canonical Invoice v3.1.0 tenant shell.
           ========================================================== */
        :root{
            --cm-navy:#001131;
            --cm-text:#0b2b37;
            --cm-muted:#5f7380;
            --cm-green:#2f8d25;
            --cm-green-dark:#24751d;
            --cm-green-soft:#f2f8ee;
            --cm-border:#dce4e8;
            --cm-soft:#f8fafb;
            --cm-danger:#d94841;
            --cm-warning:#9a741a;
        }

        body{
            background:#fff!important;
            color:var(--cm-text);
            font-family:Arial,Helvetica,sans-serif!important;
            font-size:14px;
        }

        .cm-page{
            width:100%;
            max-width:none;
            min-height:calc(100vh - 70px);
            margin:0;
            padding:24px 22px 112px;
            background:#fff;
        }

        .cm-head{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:18px;
            margin-bottom:22px;
        }
        .cm-head-copy{min-width:0}
        .cm-title{
            margin:0;
            color:#0b2b37;
            font-size:31px;
            line-height:1.1;
            font-weight:700;
            letter-spacing:-.7px;
        }
        .cm-subtitle{
            max-width:820px;
            margin:8px 0 0;
            color:#607782;
            font-size:13px;
            line-height:1.5;
        }
        .cm-head-actions{
            display:flex;
            align-items:center;
            gap:9px;
            flex:0 0 auto;
        }

        .cm-btn{
            height:38px;
            min-height:38px;
            padding:0 14px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            border:1px solid var(--cm-border);
            border-radius:7px;
            background:#fff;
            color:#31505d;
            font:700 14px Arial,Helvetica,sans-serif;
            text-decoration:none!important;
            cursor:pointer;
            white-space:nowrap;
            box-shadow:none;
        }
        .cm-btn:hover{
            border-color:#b9c6cc;
            background:#fbfcfc;
            color:#173846;
        }
        .cm-btn.primary{
            border-color:var(--cm-green);
            background:var(--cm-green);
            color:#fff;
        }
        .cm-btn.primary:hover{
            border-color:var(--cm-green-dark);
            background:var(--cm-green-dark);
            color:#fff;
        }
        .cm-btn.danger{
            border-color:var(--cm-danger);
            background:var(--cm-danger);
            color:#fff;
        }
        .cm-btn:disabled{opacity:.55;cursor:not-allowed}

        .cm-help{
            min-height:62px;
            padding:13px 15px;
            display:flex;
            align-items:flex-start;
            gap:10px;
            border:1px solid #d9e5d3;
            border-radius:7px;
            background:#f8fbf6;
            color:#405d6a;
            font-size:12px;
            line-height:1.5;
        }
        .cm-help i{
            margin-top:1px;
            color:var(--cm-green-dark);
            font-size:17px;
        }
        .cm-help strong{color:#173846}

        .cm-grid{
            display:grid;
            grid-template-columns:minmax(0,1.65fr) minmax(320px,.85fr);
            gap:14px;
            margin-top:14px;
            align-items:start;
        }
        .cm-card{
            min-width:0;
            border:1px solid var(--cm-border);
            border-radius:7px;
            background:#fff;
            box-shadow:none;
        }
        .cm-selected-card{
            position:sticky;
            top:84px;
        }
        .cm-card-head{
            padding:15px 15px 12px;
            border-bottom:1px solid #e5ecef;
        }
        .cm-card-head h2{
            margin:0;
            color:#123845;
            font-size:17px;
            line-height:1.25;
            font-weight:700;
        }
        .cm-card-head p{
            margin:4px 0 0;
            color:#607782;
            font-size:12px;
            line-height:1.4;
        }

        .cm-search-wrap{
            position:relative;
            margin:13px 14px 10px;
        }
        .cm-search-wrap i{
            position:absolute;
            left:14px;
            top:50%;
            transform:translateY(-50%);
            color:#587381;
            font-size:17px;
            pointer-events:none;
        }
        .cm-search{
            width:100%;
            height:45px;
            padding:0 13px 0 43px;
            border:1px solid var(--cm-border);
            border-radius:7px;
            background:#fff;
            color:#173846;
            outline:0;
            font:13px Arial,Helvetica,sans-serif;
        }
        .cm-search:focus{
            border-color:#91bd7e;
            box-shadow:0 0 0 2px rgba(47,141,37,.08);
        }
        .cm-results-meta{
            padding:0 15px 9px;
            color:#71858f;
            font-size:11px;
        }
        .cm-results{
            max-height:520px;
            overflow-y:auto;
            overflow-x:hidden;
            border-top:1px solid #edf1f3;
            scrollbar-width:thin;
            scrollbar-color:#cad5da transparent;
        }
        .cm-results::-webkit-scrollbar{width:7px}
        .cm-results::-webkit-scrollbar-thumb{border-radius:999px;background:#cad5da}
        .cm-result{
            min-height:78px;
            padding:11px 14px;
            display:grid;
            grid-template-columns:22px minmax(0,1fr) auto;
            gap:10px;
            align-items:flex-start;
            border-bottom:1px solid #e3eaed;
            background:#fff;
            cursor:pointer;
            transition:background .12s ease;
        }
        .cm-result:hover{background:#f3f1ed}
        .cm-result.selected{background:#f4f9ef}
        .cm-result input[type=checkbox]{
            width:17px;
            height:17px;
            margin:2px 0 0;
            accent-color:var(--cm-green);
        }
        .cm-result-name{
            overflow:hidden;
            color:#123845;
            font-size:13px;
            font-weight:700;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .cm-result-company{
            margin-top:2px;
            overflow:hidden;
            color:#6b7f89;
            font-size:11px;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .cm-result-contact,
        .cm-result-address{
            margin-top:5px;
            display:flex;
            flex-wrap:wrap;
            gap:5px 12px;
            color:#526b78;
            font-size:11px;
            line-height:1.3;
        }
        .cm-result-contact span{display:inline-flex;align-items:center;gap:5px;min-width:0}
        .cm-result-address{color:#71858f}

        .cm-status{
            min-height:23px;
            padding:3px 9px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            border-radius:999px;
            background:#e7f2e4;
            color:#3b8a33;
            font-size:11px;
            font-weight:400;
            text-transform:capitalize;
            white-space:nowrap;
        }
        .cm-status:before{
            width:7px;
            height:7px;
            border-radius:50%;
            background:currentColor;
            content:'';
        }
        .cm-status.new,
        .cm-status.lead{
            color:#8b7410;
            background:#f8f0c8;
        }
        .cm-status.inactive,
        .cm-status.archived{
            color:#687b84;
            background:#eef1f2;
        }
        .cm-empty{
            min-height:120px;
            padding:28px 18px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:#71858f;
            font-size:12px;
            text-align:center;
        }

        .cm-selected-body{padding:13px 14px 15px}
        .cm-selected-count{
            margin-bottom:9px;
            color:#607782;
            font-size:11px;
        }
        .cm-selected-item{
            min-height:55px;
            padding:8px 9px;
            display:grid;
            grid-template-columns:19px minmax(0,1fr) 28px;
            gap:8px;
            align-items:center;
            border:1px solid #e1e8eb;
            border-radius:7px;
            background:#fff;
        }
        .cm-selected-item + .cm-selected-item{margin-top:7px}
        .cm-selected-item.primary{
            border-color:#b8d3aa;
            background:#f4f9ef;
        }
        .cm-selected-radio{
            width:17px;
            height:17px;
            margin:0;
            accent-color:var(--cm-green);
        }
        .cm-selected-name{
            overflow:hidden;
            color:#173846;
            font-size:12px;
            font-weight:700;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .cm-selected-small{
            margin-top:2px;
            overflow:hidden;
            color:#82919a;
            font-size:10px;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .cm-remove{
            width:28px;
            height:28px;
            padding:0;
            display:grid;
            place-items:center;
            border:0;
            border-radius:6px;
            background:transparent;
            color:#71858f;
            cursor:pointer;
        }
        .cm-remove:hover{background:#fff0ef;color:var(--cm-danger)}
        .cm-primary-note{
            margin-top:11px;
            padding:9px 10px;
            border-radius:7px;
            background:#f5f7f8;
            color:#607782;
            font-size:10.5px;
            line-height:1.45;
        }
        .cm-primary-note strong{color:#294b59}

        .cm-preview{
            display:none;
            margin-top:14px;
        }
        .cm-preview.show{display:block}
        .cm-preview-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:14px;
        }
        .cm-preview-card{
            padding:14px 15px;
            border:1px solid var(--cm-border);
            border-radius:7px;
            background:#fff;
        }
        .cm-preview-card h3{
            margin:0 0 10px;
            color:#123845;
            font-size:15px;
            font-weight:700;
        }
        .cm-counts{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:7px;
        }
        .cm-count{
            min-height:53px;
            padding:9px 10px;
            border:1px solid #e5ecef;
            border-radius:7px;
            background:#fbfcfc;
        }
        .cm-count strong{
            display:block;
            color:#0b2b37;
            font-size:18px;
            line-height:1.1;
        }
        .cm-count span{
            display:block;
            margin-top:3px;
            color:#71858f;
            font-size:10px;
        }
        .cm-fill-list{display:grid;gap:7px}
        .cm-fill{
            padding:8px 9px;
            border:1px solid #edf1f3;
            border-radius:7px;
            background:#fbfcfc;
        }
        .cm-fill strong{
            display:block;
            color:#607782;
            font-size:10px;
        }
        .cm-fill span{
            display:block;
            margin-top:2px;
            color:#173846;
            font-size:12px;
        }
        .cm-fill small{
            display:block;
            margin-top:2px;
            color:#8a9aa2;
            font-size:9px;
        }

        .cm-sticky{
            position:fixed;
            left:var(--fieldplx-sidebar-width);
            right:0;
            bottom:0;
            z-index:1025;
            min-height:70px;
            padding:10px 22px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:14px;
            border-top:1px solid var(--cm-border);
            background:rgba(255,255,255,.98);
            box-shadow:0 -4px 14px rgba(0,17,49,.035);
            transition:left .25s ease;
        }
        body.fieldplx-sidebar-collapsed .cm-sticky{left:var(--fieldplx-sidebar-collapsed-width)}
        .cm-sticky-note{
            color:#607782;
            font-size:11px;
        }
        .cm-actions{display:flex;align-items:center;gap:8px}
        .cm-loader{
            width:13px;
            height:13px;
            display:none;
            border:2px dotted currentColor;
            border-radius:50%;
            animation:cmSpin .75s linear infinite;
        }
        .cm-btn.loading .cm-loader{display:inline-block}
        @keyframes cmSpin{to{transform:rotate(360deg)}}

        .cm-modal-backdrop{
            display:none;
            position:fixed;
            inset:0;
            z-index:13000;
            align-items:center;
            justify-content:center;
            padding:18px;
            background:rgba(0,17,49,.33);
        }
        .cm-modal-backdrop.show{display:flex}
        .cm-modal{
            width:min(540px,calc(100vw - 30px));
            max-height:calc(100vh - 36px);
            overflow:auto;
            border:1px solid #d8e0e4;
            border-radius:10px;
            background:#fff;
            box-shadow:0 22px 60px rgba(0,17,49,.23);
        }
        .cm-modal-head{
            padding:20px 22px 12px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:15px;
        }
        .cm-modal-head h3{
            margin:0;
            color:#123845;
            font-size:22px;
            font-weight:700;
        }
        .cm-modal-close{
            width:34px;
            height:34px;
            padding:0;
            border:0;
            border-radius:7px;
            background:transparent;
            color:#274c5b;
            font-size:20px;
            cursor:pointer;
        }
        .cm-modal-close:hover{background:#f2f5f5}
        .cm-modal-body{
            padding:10px 22px 12px;
            color:#405d6a;
            font-size:13px;
            line-height:1.55;
        }
        .cm-warning{
            margin-top:13px;
            padding:11px 12px;
            border-radius:7px;
            background:#fff8dc;
            color:#654e08;
        }
        .cm-modal-footer{
            padding:12px 22px 20px;
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:8px;
        }

        .cm-toast{
            position:fixed;
            top:82px;
            right:18px;
            z-index:14000;
            width:min(390px,calc(100vw - 36px));
            padding:12px 14px;
            display:flex;
            align-items:center;
            gap:9px;
            border-radius:8px;
            background:#1f5f7a;
            color:#fff;
            box-shadow:0 12px 30px rgba(0,17,49,.18);
            opacity:0;
            transform:translateY(-8px);
            pointer-events:none;
            transition:.18s;
            font-size:13px;
            font-weight:700;
        }
        .cm-toast.show{opacity:1;transform:translateY(0);pointer-events:auto}
        .cm-toast.success{background:#2f8d25}
        .cm-toast.error{background:#c94f55}
        .cm-toast.warning{background:#9a741a}
        .cm-toast.info{background:#1f5f7a}
        .cm-toast span{min-width:0;flex:1}
        .cm-toast button{border:0;background:transparent;color:#fff;cursor:pointer}

        @media(max-width:1199.98px){
            .cm-grid{grid-template-columns:minmax(0,1.35fr) minmax(300px,.85fr)}
        }
        @media(max-width:991.98px){
            .cm-page{padding:20px 16px 110px}
            .cm-grid{grid-template-columns:1fr}
            .cm-selected-card{position:static}
            .cm-sticky,
            body.fieldplx-sidebar-collapsed .cm-sticky{left:0}
        }
        @media(max-width:767.98px){
            .cm-page{padding:17px 13px 120px}
            .cm-head{flex-direction:column}
            .cm-head-actions{width:100%}
            .cm-head-actions .cm-btn{width:100%}
            .cm-title{font-size:27px}
            .cm-preview-grid{grid-template-columns:1fr}
            .cm-sticky{
                min-height:96px;
                padding:10px 13px;
                align-items:stretch;
                flex-direction:column;
            }
            .cm-sticky-note{text-align:center}
            .cm-actions{width:100%}
            .cm-actions .cm-btn{flex:1}
            .cm-modal-head h3{font-size:19px}
        }
        @media(max-width:575.98px){
            .cm-result{grid-template-columns:21px minmax(0,1fr)}
            .cm-result>.cm-status{grid-column:2;justify-self:start}
            .cm-counts{grid-template-columns:1fr}
            .cm-toast{top:72px;left:12px;right:12px;width:auto}
            .cm-modal-footer{flex-direction:column-reverse}
            .cm-modal-footer .cm-btn{width:100%}
        }

    

        /* ==========================================================
           FieldPlx current template + dynamic light/dark theme
           ========================================================== */
        :root{
            --cm-green:var(--primary,#08a5d2);
            --cm-green-dark:var(--primary,#08a5d2);
            --cm-green-soft:color-mix(in srgb,var(--primary,#08a5d2) 12%,var(--card-bg,#fff));
            --cm-navy:var(--text,#0b2b37);
            --cm-text:var(--text,#0b2b37);
            --cm-muted:var(--muted,#647787);
            --cm-border:var(--card-border,#dce3e7);
            --cm-soft:var(--body-bg,#f6f8fb);
        }

        body{
            background:var(--body-bg,#f6f8fb)!important;
            color:var(--text,#0b2b37)!important;
            font-family:inherit!important;
        }
        .cm-page{
            min-height:auto;
            padding:24px 24px 32px;
            background:transparent!important;
            color:var(--text,#0b2b37);
        }
        .cm-title,.cm-card-head h2,.cm-result-name,.cm-selected-name,.cm-preview-card h3,.cm-count strong,.cm-fill span,.cm-modal-head h3,.cm-help strong,.cm-primary-note strong{
            color:var(--text,#0b2b37)!important;
        }
        .cm-subtitle,.cm-card-head p,.cm-results-meta,.cm-result-company,.cm-result-contact,.cm-result-address,.cm-empty,.cm-selected-count,.cm-selected-small,.cm-primary-note,.cm-count span,.cm-fill strong,.cm-fill small,.cm-sticky-note,.cm-modal-body{
            color:var(--muted,#647787)!important;
        }
        .cm-card,.cm-preview-card,.cm-selected-item,.cm-count,.cm-fill,.cm-modal{
            border-color:var(--card-border,#dce3e7)!important;
            background:var(--card-bg,#fff)!important;
            color:var(--text,#0b2b37)!important;
            box-shadow:var(--card-shadow,none);
        }
        .cm-card-head,.cm-results,.cm-result,.cm-selected-item,.cm-count,.cm-fill{
            border-color:var(--table-border,var(--card-border,#dce3e7))!important;
        }
        .cm-result{
            background:var(--card-bg,#fff)!important;
        }
        .cm-result:hover{
            background:var(--table-hover-bg,color-mix(in srgb,var(--primary,#08a5d2) 7%,var(--card-bg,#fff)))!important;
        }
        .cm-result.selected,.cm-selected-item.primary{
            border-color:color-mix(in srgb,var(--primary,#08a5d2) 42%,var(--card-border,#dce3e7))!important;
            background:color-mix(in srgb,var(--primary,#08a5d2) 9%,var(--card-bg,#fff))!important;
        }
        .cm-search{
            border-color:var(--input-border,#dce3e7)!important;
            background:var(--input-bg,var(--card-bg,#fff))!important;
            color:var(--text,#0b2b37)!important;
        }
        .cm-search::placeholder{color:color-mix(in srgb,var(--muted,#647787) 82%,transparent)!important;opacity:1}
        .cm-search:focus{
            border-color:var(--primary,#08a5d2)!important;
            box-shadow:0 0 0 3px color-mix(in srgb,var(--primary,#08a5d2) 14%,transparent)!important;
        }
        .cm-btn{
            border-color:var(--button-border,var(--card-border,#dce3e7))!important;
            background:var(--button-bg,var(--card-bg,#fff))!important;
            color:var(--text,#0b2b37)!important;
            box-shadow:none!important;
        }
        .cm-btn:hover{
            border-color:var(--primary,#08a5d2)!important;
            background:var(--button-hover-bg,color-mix(in srgb,var(--primary,#08a5d2) 8%,var(--card-bg,#fff)))!important;
            color:var(--primary,#08a5d2)!important;
        }
        .cm-btn.primary{
            border-color:var(--primary,#08a5d2)!important;
            background:var(--primary,#08a5d2)!important;
            color:var(--primary-text,#fff)!important;
        }
        .cm-btn.primary:hover{filter:brightness(.94);color:var(--primary-text,#fff)!important}
        .cm-help{
            border-color:color-mix(in srgb,var(--primary,#08a5d2) 24%,var(--card-border,#dce3e7))!important;
            background:color-mix(in srgb,var(--primary,#08a5d2) 7%,var(--card-bg,#fff))!important;
            color:var(--text,#0b2b37)!important;
        }
        .cm-help i{color:var(--primary,#08a5d2)!important}
        .cm-primary-note{
            background:color-mix(in srgb,var(--card-bg,#fff) 90%,var(--body-bg,#f6f8fb))!important;
        }
        .cm-remove:hover{background:color-mix(in srgb,#e1554f 13%,var(--card-bg,#fff))!important}
        .cm-sticky{
            position:sticky!important;
            left:auto!important;
            right:auto!important;
            bottom:0;
            z-index:1200;
            width:100%;
            min-height:64px;
            padding:10px 24px;
            border-top:1px solid var(--card-border,#dce3e7)!important;
            background:color-mix(in srgb,var(--card-bg,#fff) 96%,transparent)!important;
            box-shadow:0 -8px 24px rgba(0,0,0,.06);
            backdrop-filter:blur(12px);
        }
        .cm-warning{
            background:color-mix(in srgb,#d5a51e 12%,var(--card-bg,#fff))!important;
            color:var(--text,#0b2b37)!important;
        }
        .cm-modal-backdrop{background:rgba(2,12,18,.58)!important;backdrop-filter:blur(2px)}
        html.app-dark-mode .cm-card,
        html.app-dark-mode .cm-preview-card,
        html.app-dark-mode .cm-selected-item,
        html.app-dark-mode .cm-count,
        html.app-dark-mode .cm-fill,
        html.app-dark-mode .cm-modal{box-shadow:0 12px 34px rgba(0,0,0,.16)}
        @media(max-width:767.98px){
            .cm-page{padding:18px 12px 24px}
            .cm-sticky{padding:10px 12px}
        }



        /* ==========================================================
           FieldPlx current-shell alignment fixes
           - compact controls
           - correct dark theme surfaces
           - true fixed bottom action bar
           ========================================================== */
        .cm-page{
            width:100%;
            max-width:1280px;
            margin:0 auto;
            padding:22px 24px 104px!important;
            background:transparent!important;
        }

        .cm-head{
            margin-bottom:16px;
            align-items:center;
        }

        .cm-title{
            font-size:30px;
            line-height:1.12;
        }

        .cm-subtitle{
            margin-top:6px;
            font-size:13px;
        }

        .cm-btn{
            height:36px;
            min-height:36px;
            padding:0 12px;
            border-radius:8px;
            font-family:inherit!important;
            font-size:13px;
            font-weight:600;
        }

        .cm-card,
        .cm-preview-card,
        .cm-selected-item,
        .cm-count,
        .cm-fill,
        .cm-modal{
            border-radius:10px;
        }

        .cm-help{
            min-height:auto;
            padding:11px 13px;
            border-radius:9px;
        }

        .cm-card-head{
            padding:14px 15px 11px;
        }

        .cm-search{
            height:42px;
            font-family:inherit!important;
        }

        .cm-result{
            min-height:72px;
            padding:10px 13px;
        }

        .cm-results::-webkit-scrollbar{width:5px}
        .cm-results::-webkit-scrollbar-track{background:transparent}
        .cm-results::-webkit-scrollbar-thumb{
            border-radius:999px;
            background:color-mix(in srgb,var(--muted,#647787) 42%,transparent);
        }

        .cm-sticky{
            position:fixed!important;
            left:var(--fieldplx-sidebar-width, 206px)!important;
            right:0!important;
            bottom:0!important;
            z-index:1250!important;
            width:auto!important;
            min-height:64px!important;
            margin:0!important;
            padding:10px 24px!important;
            display:flex!important;
            align-items:center!important;
            justify-content:space-between!important;
            gap:12px!important;
            border-top:1px solid var(--card-border,#dce3e7)!important;
            background:color-mix(in srgb,var(--card-bg,#fff) 96%,transparent)!important;
            box-shadow:0 -8px 24px rgba(0,0,0,.07)!important;
            backdrop-filter:blur(14px);
            -webkit-backdrop-filter:blur(14px);
            transition:left .2s ease!important;
        }

        body.fieldplx-sidebar-collapsed .cm-sticky{
            left:var(--fieldplx-sidebar-collapsed-width, 80px)!important;
        }

        .cm-actions{
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:8px;
            flex-wrap:nowrap;
        }

        .cm-actions .cm-btn{
            flex:0 0 auto;
            width:auto;
        }

        .cm-modal-backdrop{
            z-index:16000!important;
        }

        .cm-toast{
            z-index:17000!important;
        }

        html.app-dark-mode .cm-page{
            color:var(--text,#dce8ee)!important;
        }

        html.app-dark-mode .cm-card,
        html.app-dark-mode .cm-preview-card,
        html.app-dark-mode .cm-selected-item,
        html.app-dark-mode .cm-count,
        html.app-dark-mode .cm-fill,
        html.app-dark-mode .cm-modal,
        html.app-dark-mode .cm-result{
            background:var(--card-bg,#16252d)!important;
            border-color:var(--card-border,#2a3c46)!important;
        }

        html.app-dark-mode .cm-search{
            background:var(--input-bg,var(--card-bg,#16252d))!important;
            border-color:var(--input-border,var(--card-border,#2a3c46))!important;
            color:var(--text,#e3edf2)!important;
        }

        html.app-dark-mode .cm-help{
            background:color-mix(in srgb,var(--primary,#08a5d2) 8%,var(--card-bg,#16252d))!important;
            border-color:color-mix(in srgb,var(--primary,#08a5d2) 26%,var(--card-border,#2a3c46))!important;
        }

        html.app-dark-mode .cm-primary-note,
        html.app-dark-mode .cm-count,
        html.app-dark-mode .cm-fill{
            background:color-mix(in srgb,var(--card-bg,#16252d) 90%,var(--body-bg,#0d171c))!important;
        }

        html.app-dark-mode .cm-warning{
            background:color-mix(in srgb,#d5a51e 13%,var(--card-bg,#16252d))!important;
        }

        @media(max-width:991.98px){
            .cm-sticky,
            body.fieldplx-sidebar-collapsed .cm-sticky{
                left:0!important;
            }
        }

        @media(max-width:767.98px){
            .cm-page{padding:18px 12px 116px!important}
            .cm-head{align-items:stretch}
            .cm-head-actions{width:100%}
            .cm-head-actions .cm-btn{width:auto}
            .cm-sticky{
                min-height:72px!important;
                padding:10px 12px!important;
                flex-direction:row!important;
                align-items:center!important;
            }
            .cm-sticky-note{
                min-width:0;
                flex:1;
                text-align:left!important;
            }
            .cm-actions{
                width:auto!important;
                flex:0 0 auto;
            }
            .cm-actions .cm-btn{
                flex:0 0 auto!important;
                width:auto!important;
            }
        }

        @media(max-width:575.98px){
            .cm-page{padding-bottom:154px!important}
            .cm-sticky{
                min-height:126px!important;
                flex-direction:column!important;
                align-items:stretch!important;
            }
            .cm-sticky-note{text-align:center!important}
            .cm-actions{width:100%!important}
            .cm-actions .cm-btn{flex:1 1 0!important}
        }

</style>
<div class="cm-page">
                <header class="cm-head">
                    <div class="cm-head-copy">
                        <h1 class="cm-title">Merge Clients</h1>
                        <p class="cm-subtitle">Combine duplicate client profiles into one accurate record. Select at least two clients, choose which profile to keep, then review the work and billing records that will move.</p>
                    </div>
                    <div class="cm-head-actions">
                        <a href="clients.php" class="cm-btn"><i class="bi bi-arrow-left"></i> Back to Clients</a>
                    </div>
                </header>

                <div class="cm-help">
                    <i class="bi bi-info-circle"></i>
                    <div><strong>The profile you keep is never deleted.</strong> Existing values on that profile stay in place. Blank profile fields can be filled from the duplicate records, linked work moves to the kept client, and the duplicate client profiles are archived after the merge.</div>
                </div>

                <div class="cm-grid">
                    <section class="cm-card">
                        <div class="cm-card-head">
                            <h2>Select duplicate clients</h2>
                            <p>Search by client name, company, phone, email, or property address. You can merge up to 10 profiles at once.</p>
                        </div>
                        <div class="cm-search-wrap">
                            <i class="bi bi-search"></i>
                            <input class="cm-search" type="search" id="clientSearch" placeholder="Search clients..." autocomplete="off" />
                        </div>
                        <div class="cm-results-meta" id="resultsMeta">Loading clients...</div>
                        <div class="cm-results" id="clientResults">
                            <div class="cm-empty">Loading clients...</div>
                        </div>
                    </section>

                    <section class="cm-card cm-selected-card">
                        <div class="cm-card-head">
                            <h2>Clients to merge</h2>
                            <p>Choose the one profile that should remain after the merge.</p>
                        </div>
                        <div class="cm-selected-body">
                            <div class="cm-selected-count" id="selectedCount">0 clients selected</div>
                            <div id="selectedList"><div class="cm-empty">Select at least two client profiles.</div></div>
                            <div class="cm-primary-note">The selected <strong>Keep this profile</strong> client controls the final name and existing profile values. Linked locations, jobs, quotes, invoices, payments, contacts, and other related records are reassigned to it.</div>
                        </div>
                    </section>
                </div>

                <section class="cm-preview" id="mergePreview">
                    <div class="cm-preview-grid">
                        <article class="cm-preview-card">
                            <h3>Records that will move</h3>
                            <div class="cm-counts" id="linkedCounts"></div>
                        </article>
                        <article class="cm-preview-card">
                            <h3>Blank profile details that will be filled</h3>
                            <div class="cm-fill-list" id="profileFill"></div>
                        </article>
                    </div>
                </section>
            </div>

<div class="cm-sticky">
    <div class="cm-sticky-note" id="stickyNote">Select at least two clients to continue.</div>
    <div class="cm-actions">
        <a class="cm-btn" href="clients.php">Cancel</a>
        <button type="button" class="cm-btn primary" id="mergeButton" disabled><span class="cm-loader"></span><i class="bi bi-intersect"></i> Merge Clients</button>
    </div>
</div>

<div class="cm-modal-backdrop" id="confirmBackdrop" aria-hidden="true">
    <section class="cm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <div class="cm-modal-head">
            <h3 id="confirmTitle">Merge selected clients?</h3>
            <button type="button" class="cm-modal-close" id="confirmClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="cm-modal-body">
            <div id="confirmText">The duplicate profiles will be merged into the selected primary client.</div>
            <div class="cm-warning"><strong>This action changes linked records.</strong> Jobs, requests, quotes, invoices, payments, locations, contacts, and other client-linked records will point to the profile you keep. Duplicate client profiles are soft-archived for history.</div>
        </div>
        <div class="cm-modal-footer">
            <button type="button" class="cm-btn" id="confirmCancel">Cancel</button>
            <button type="button" class="cm-btn primary" id="confirmMerge"><span class="cm-loader"></span>Merge Clients</button>
        </div>
    </section>
</div>

<div class="cm-toast info" id="mergeToast"><i class="bi bi-info-circle"></i><span id="mergeToastMessage">Notification</span><button type="button" id="mergeToastClose"><i class="bi bi-x-lg"></i></button></div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    var csrfToken = <?= json_encode($clientMergeCsrfToken) ?>;
    var initialIds = <?= json_encode($initialIds) ?>;
    var initialPrimaryId = <?= (int)$initialPrimaryId ?>;
    var state = {
        rows: [],
        rowMap: {},
        selected: {},
        primaryId: initialPrimaryId || 0,
        canMerge: false,
        previewTimer: null,
        searchTimer: null,
        toastTimer: null
    };

    var results = document.getElementById('clientResults');
    var resultsMeta = document.getElementById('resultsMeta');
    var searchInput = document.getElementById('clientSearch');
    var selectedList = document.getElementById('selectedList');
    var selectedCount = document.getElementById('selectedCount');
    var mergePreview = document.getElementById('mergePreview');
    var linkedCounts = document.getElementById('linkedCounts');
    var profileFill = document.getElementById('profileFill');
    var stickyNote = document.getElementById('stickyNote');
    var mergeButton = document.getElementById('mergeButton');
    var confirmBackdrop = document.getElementById('confirmBackdrop');
    var confirmMerge = document.getElementById('confirmMerge');
    var toast = document.getElementById('mergeToast');
    var toastMessage = document.getElementById('mergeToastMessage');

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function parseResponse(response) {
        return response.text().then(function (raw) {
            var text = (raw || '').trim();
            var data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                var clean = text.replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                throw new Error(clean ? 'Server error: ' + clean : 'Server returned an invalid response.');
            }
            if (!response.ok || !data.success) {
                var fallback = response.status ? ('Request failed (HTTP ' + response.status + ').') : 'Request failed.';
                if (response.status === 500 && !data.message) {
                    fallback = 'Client merge failed on the server. Check the PHP error log for the exact database/PHP error.';
                }
                throw new Error(data.message || fallback);
            }
            return data;
        });
    }

    function request(data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (key) {
            fd.append(key, data[key]);
        });
        fd.append('csrf_token', csrfToken);
        var endpoint = new URL('api/client-merge.php', window.location.href).href;
        return fetch(endpoint, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(parseResponse);
    }

    function showToast(type, message) {
        if (state.toastTimer) clearTimeout(state.toastTimer);
        toast.className = 'cm-toast ' + (type || 'info') + ' show';
        toastMessage.textContent = message || 'Notification';
        state.toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 3500);
    }

    function setLoading(button, on) {
        if (!button) return;
        button.classList.toggle('loading', !!on);
        button.disabled = !!on;
    }

    function selectedIds() {
        return Object.keys(state.selected).map(function (id) { return Number(id); }).filter(function (id) { return id > 0; });
    }

    function clientSecondary(row) {
        return row.company_name || [row.first_name || '', row.last_name || ''].join(' ').trim() || row.email || row.phone || '';
    }

    function renderResults() {
        var rows = state.rows || [];
        resultsMeta.textContent = rows.length + (rows.length === 1 ? ' client' : ' clients') + ' found';
        if (!rows.length) {
            results.innerHTML = '<div class="cm-empty">No matching clients found.</div>';
            return;
        }
        var html = '';
        rows.forEach(function (row) {
            var id = Number(row.id || 0);
            var checked = !!state.selected[id];
            html += '<label class="cm-result' + (checked ? ' selected' : '') + '" data-id="' + id + '">' +
                '<input type="checkbox" data-select-id="' + id + '" ' + (checked ? 'checked' : '') + '>' +
                '<div>' +
                    '<div class="cm-result-name">' + esc(row.display_name || 'Unnamed client') + '</div>' +
                    '<div class="cm-result-company">' + esc(clientSecondary(row)) + '</div>' +
                    '<div class="cm-result-contact">' +
                        (row.email ? '<span><i class="bi bi-envelope"></i>' + esc(row.email) + '</span>' : '') +
                        (row.phone ? '<span><i class="bi bi-telephone"></i>' + esc(row.phone) + '</span>' : '') +
                    '</div>' +
                    (row.address ? '<div class="cm-result-address"><i class="bi bi-geo-alt"></i> ' + esc(row.address) + '</div>' : '') +
                '</div>' +
                '<span class="cm-status ' + esc(row.status || '') + '">' + esc(row.status || row.client_type || 'active') + '</span>' +
            '</label>';
        });
        results.innerHTML = html;
    }

    function ensurePrimary() {
        var ids = selectedIds();
        if (!ids.length) {
            state.primaryId = 0;
            return;
        }
        if (ids.indexOf(Number(state.primaryId)) === -1) {
            state.primaryId = ids[0];
        }
    }

    function renderSelected() {
        ensurePrimary();
        var ids = selectedIds();
        selectedCount.textContent = ids.length + (ids.length === 1 ? ' client selected' : ' clients selected');
        if (!ids.length) {
            selectedList.innerHTML = '<div class="cm-empty">Select at least two client profiles.</div>';
            updateActionState();
            return;
        }
        var html = '';
        ids.forEach(function (id) {
            var row = state.selected[id];
            var primary = Number(state.primaryId) === Number(id);
            html += '<div class="cm-selected-item' + (primary ? ' primary' : '') + '">' +
                '<input class="cm-selected-radio" type="radio" name="primaryClient" data-primary-id="' + id + '" ' + (primary ? 'checked' : '') + ' title="Keep this profile">' +
                '<div><div class="cm-selected-name">' + esc(row.display_name || 'Unnamed client') + '</div><div class="cm-selected-small">' + esc(row.email || row.phone || clientSecondary(row) || 'No contact information') + '</div></div>' +
                '<button class="cm-remove" type="button" data-remove-id="' + id + '" title="Remove"><i class="bi bi-x-lg"></i></button>' +
            '</div>';
        });
        selectedList.innerHTML = html;
        updateActionState();
        schedulePreview();
    }

    function updateActionState() {
        var ids = selectedIds();
        var ready = state.canMerge && ids.length >= 2 && state.primaryId > 0;
        mergeButton.disabled = !ready;
        if (!state.canMerge) {
            stickyNote.textContent = 'Your role does not have both Update and Delete customer permissions required to merge.';
        } else if (ids.length < 2) {
            stickyNote.textContent = 'Select at least two clients to continue.';
        } else {
            var primary = state.selected[state.primaryId];
            stickyNote.textContent = (ids.length - 1) + ' duplicate ' + ((ids.length - 1) === 1 ? 'profile' : 'profiles') + ' will be merged into ' + (primary ? primary.display_name : 'the kept client') + '.';
        }
    }

    function renderPreview(data) {
        var counts = data.linked_counts || [];
        var fills = data.profile_fill || [];
        if (!counts.length) {
            linkedCounts.innerHTML = '<div class="cm-empty" style="grid-column:1/-1">No linked work records found on the duplicate profiles.</div>';
        } else {
            linkedCounts.innerHTML = counts.map(function (row) {
                return '<div class="cm-count"><strong>' + Number(row.count || 0) + '</strong><span>' + esc(row.label) + '</span></div>';
            }).join('');
        }
        if (!fills.length) {
            profileFill.innerHTML = '<div class="cm-empty">The kept client already has values for the mergeable profile fields. Existing values will be preserved.</div>';
        } else {
            profileFill.innerHTML = fills.map(function (row) {
                return '<div class="cm-fill"><strong>' + esc(row.label) + '</strong><span>' + esc(row.value) + '</span><small>From ' + esc(row.source_name) + '</small></div>';
            }).join('');
        }
        mergePreview.classList.add('show');
    }

    function schedulePreview() {
        if (state.previewTimer) clearTimeout(state.previewTimer);
        var ids = selectedIds();
        if (ids.length < 2 || !state.primaryId || !state.canMerge) {
            mergePreview.classList.remove('show');
            return;
        }
        state.previewTimer = setTimeout(function () {
            request({
                action: 'preview',
                client_ids: JSON.stringify(ids),
                primary_id: state.primaryId
            }).then(renderPreview).catch(function (e) {
                mergePreview.classList.remove('show');
                showToast('error', e.message);
            });
        }, 180);
    }

    function loadClients(search) {
        results.innerHTML = '<div class="cm-empty">Loading clients...</div>';
        request({ action: 'list', search: search || '' }).then(function (data) {
            state.rows = data.clients || [];
            state.rowMap = {};
            state.rows.forEach(function (row) {
                state.rowMap[Number(row.id)] = row;
            });
            state.canMerge = !!(data.permissions && data.permissions.can_merge);
            initialIds.forEach(function (id) {
                if (state.rowMap[id]) state.selected[id] = state.rowMap[id];
            });
            if (initialPrimaryId && state.selected[initialPrimaryId]) state.primaryId = initialPrimaryId;
            renderResults();
            renderSelected();
        }).catch(function (e) {
            results.innerHTML = '<div class="cm-empty">' + esc(e.message) + '</div>';
            showToast('error', e.message);
        });
    }

    results.addEventListener('change', function (e) {
        var box = e.target.closest('[data-select-id]');
        if (!box) return;
        var id = Number(box.getAttribute('data-select-id'));
        if (box.checked) {
            if (selectedIds().length >= 10 && !state.selected[id]) {
                box.checked = false;
                showToast('warning', 'You can merge up to 10 clients at one time.');
                return;
            }
            if (state.rowMap[id]) state.selected[id] = state.rowMap[id];
        } else {
            delete state.selected[id];
        }
        ensurePrimary();
        renderResults();
        renderSelected();
    });

    selectedList.addEventListener('change', function (e) {
        var radio = e.target.closest('[data-primary-id]');
        if (!radio) return;
        state.primaryId = Number(radio.getAttribute('data-primary-id'));
        renderSelected();
    });

    selectedList.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-remove-id]');
        if (!remove) return;
        var id = Number(remove.getAttribute('data-remove-id'));
        delete state.selected[id];
        ensurePrimary();
        renderResults();
        renderSelected();
    });

    searchInput.addEventListener('input', function () {
        if (state.searchTimer) clearTimeout(state.searchTimer);
        var value = this.value.trim();
        state.searchTimer = setTimeout(function () { loadClients(value); }, 250);
    });

    function openConfirm() {
        var ids = selectedIds();
        if (!state.canMerge || ids.length < 2 || !state.primaryId) return;
        var primary = state.selected[state.primaryId];
        document.getElementById('confirmText').innerHTML = '<strong>' + esc(primary ? primary.display_name : 'Selected client') + '</strong> will be kept. ' + (ids.length - 1) + ' duplicate ' + ((ids.length - 1) === 1 ? 'client' : 'clients') + ' will be merged into this profile.';
        confirmBackdrop.classList.add('show');
        confirmBackdrop.setAttribute('aria-hidden', 'false');
    }

    function closeConfirm() {
        confirmBackdrop.classList.remove('show');
        confirmBackdrop.setAttribute('aria-hidden', 'true');
    }

    function performMerge() {
        var ids = selectedIds();
        if (!state.canMerge || ids.length < 2 || !state.primaryId) return;
        setLoading(confirmMerge, true);
        request({
            action: 'merge',
            client_ids: JSON.stringify(ids),
            primary_id: state.primaryId
        }).then(function (data) {
            showToast('success', data.message || 'Clients merged successfully.');
            closeConfirm();
            window.setTimeout(function () {
                window.location.href = data.redirect || ('client-view.php?client_id=' + encodeURIComponent(state.primaryId));
            }, 650);
        }).catch(function (e) {
            showToast('error', e.message);
        }).finally(function () {
            setLoading(confirmMerge, false);
        });
    }

    mergeButton.addEventListener('click', openConfirm);
    confirmMerge.addEventListener('click', performMerge);
    document.getElementById('confirmClose').addEventListener('click', closeConfirm);
    document.getElementById('confirmCancel').addEventListener('click', closeConfirm);
    confirmBackdrop.addEventListener('click', function (e) { if (e.target === confirmBackdrop) closeConfirm(); });
    document.getElementById('mergeToastClose').addEventListener('click', function () { toast.classList.remove('show'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeConfirm(); });

    loadClients('');
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
