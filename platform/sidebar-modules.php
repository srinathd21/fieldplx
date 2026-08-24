<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Sidebar Modules';
$activePage = 'sidebar-modules';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['sidebar_modules_csrf'])) {
    $_SESSION['sidebar_modules_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['sidebar_modules_csrf'];

function sm_h($value)
{
    return htmlspecialchars((string)($value === null ? '' : $value), ENT_QUOTES, 'UTF-8');
}

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';

$sql = "
    SELECT
        m.id,
        m.parent_id,
        m.module_code,
        m.module_name,
        m.description,
        m.menu_url,
        m.icon_library_id,
        m.icon_name,
        m.menu_order,
        m.is_core,
        m.is_sidebar_item,
        m.is_active,
        m.created_at,
        m.updated_at,
        p.module_name AS parent_name
    FROM modules m
    LEFT JOIN modules p ON p.id = m.parent_id
    WHERE 1=1
";

$params = array();

if ($search !== '') {
    $sql .= " AND (
        m.module_name LIKE :search
        OR m.module_code LIKE :search
        OR m.menu_url LIKE :search
        OR p.module_name LIKE :search
    ) ";
    $params[':search'] = '%' . $search . '%';
}

if ($status === 'active') {
    $sql .= " AND m.is_active = 1 ";
} elseif ($status === 'inactive') {
    $sql .= " AND m.is_active = 0 ";
}

if ($type === 'parent') {
    $sql .= " AND m.parent_id IS NULL ";
} elseif ($type === 'child') {
    $sql .= " AND m.parent_id IS NOT NULL ";
}

$sql .= "
    ORDER BY
        CASE WHEN m.parent_id IS NULL THEN m.menu_order ELSE 999999 END,
        COALESCE(m.parent_id, m.id),
        CASE WHEN m.parent_id IS NULL THEN 0 ELSE 1 END,
        m.menu_order,
        m.module_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$modules = $stmt->fetchAll();

$parentModules = $pdo->query("
    SELECT id, module_name, module_code, menu_order, is_active
    FROM modules
    WHERE parent_id IS NULL
    ORDER BY menu_order, module_name
")->fetchAll();

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(parent_id IS NULL) AS parent_count,
        SUM(parent_id IS NOT NULL) AS child_count,
        SUM(is_sidebar_item = 1) AS sidebar_count,
        SUM(is_active = 1) AS active_count
    FROM modules
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= sm_h($pageTitle) ?> - FieldPlx</title>
<?php require_once __DIR__ . '/includes/links.php'; ?>
<style>
:root{
    --fp-primary:#12182d;
    --fp-primary-2:#1c2250;
    --fp-primary-3:#201f6b;
    --fp-accent:#8b5cf6;
    --fp-accent-light:#a78bfa;
    --fp-accent-dark:#6d28d9;
    --fp-text:#20213f;
    --fp-muted:#6f6b8f;
    --fp-border:#ded9ef;
    --fp-danger:#dc2626;
    --fp-sidebar-width:260px;
    --fp-sidebar-collapsed-width:76px;
    --fp-topbar-height:66px;
}

*{box-sizing:border-box}

body{
    margin:0;
    min-height:100vh;
    overflow-x:hidden;
    background:#fff;
    color:var(--fp-text);
    font-family:"Inter",sans-serif;
    font-size:13px
}

a{text-decoration:none}
button,input,select,textarea{font-family:inherit}

.fp-layout{min-height:100vh}

.fp-main{
    min-height:calc(100vh - 52px);
    margin-left:var(--fp-sidebar-width);
    transition:margin-left .22s ease
}

body.fp-sidebar-collapsed .fp-main{
    margin-left:var(--fp-sidebar-collapsed-width)
}

.fp-topbar{
    position:sticky;
    top:0;
    z-index:1030;
    min-height:var(--fp-topbar-height);
    border-bottom:1px solid #ded8f3;
    background:rgba(248,246,255,.96);
    backdrop-filter:blur(14px)
}

.fp-topbar-inner{
    min-height:var(--fp-topbar-height);
    padding:8px 18px;
    display:flex;
    align-items:center;
    gap:13px
}

.fp-menu-toggle,
.fp-icon-button{
    width:39px;
    height:39px;
    min-width:39px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid #d9d2ef;
    border-radius:10px;
    background:#fff;
    color:#39345f;
    font-size:18px
}

.fp-menu-toggle:hover,
.fp-icon-button:hover{
    border-color:#bda9ff;
    background:#f4f0ff;
    color:var(--fp-accent-dark)
}

.fp-page-heading{
    min-width:0;
    margin-right:auto
}

.fp-page-title{
    margin:0;
    color:#17172e;
    font-size:15px;
    font-weight:700;
    line-height:1.25;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis
}

.fp-page-subtitle{
    margin-top:2px;
    color:var(--fp-muted);
    font-size:10px
}

.fp-search{
    width:min(340px,31vw);
    position:relative;
    flex:0 1 340px
}

.fp-search i{
    position:absolute;
    left:12px;
    top:50%;
    transform:translateY(-50%);
    color:#8f88aa;
    font-size:14px;
    pointer-events:none
}

.fp-search input{
    width:100%;
    height:39px;
    padding:8px 13px 8px 36px;
    border:1px solid #dcd5ef;
    border-radius:10px;
    outline:0;
    background:#f8f6ff;
    font-size:12px
}

.fp-search input:focus{
    border-color:#a78bfa;
    background:#fff;
    box-shadow:0 0 0 3px rgba(139,92,246,.12)
}

.fp-notification-wrap{position:relative}

.fp-notification-count{
    position:absolute;
    top:-5px;
    right:-5px;
    z-index:3;
    min-width:18px;
    height:18px;
    padding:0 5px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:2px solid #fff;
    border-radius:999px;
    background:var(--fp-danger);
    color:#fff;
    font-size:9px;
    font-weight:700
}

.fp-profile{
    min-width:0;
    padding:4px 9px 4px 5px;
    display:flex;
    align-items:center;
    gap:9px;
    border:1px solid var(--fp-border);
    border-radius:11px;
    background:#fff
}

.fp-avatar{
    width:32px;
    height:32px;
    flex:0 0 32px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:9px;
    background:linear-gradient(135deg,#6d4df4,#9a5cff);
    color:#fff;
    font-size:10px;
    font-weight:700
}

.fp-profile-text{
    max-width:145px;
    min-width:0
}

.fp-profile-name,
.fp-profile-role{
    display:block;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis
}

.fp-profile-name{
    color:#111827;
    font-size:11px;
    font-weight:700
}

.fp-profile-role{
    margin-top:1px;
    color:var(--fp-muted);
    font-size:9px
}

.fp-mobile-brand{display:none}
.fp-content{padding:18px;background:#fff}

/* Page */
.sm-page{display:grid;gap:16px}

.sm-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px
}

.sm-title{
    margin:0;
    color:#111827;
    font-size:20px;
    font-weight:800
}

.sm-description{
    margin-top:4px;
    max-width:780px;
    color:#77718e;
    font-size:10px;
    line-height:1.55
}

.sm-header-actions{
    display:flex;
    align-items:center;
    gap:8px
}

.sm-primary,
.sm-secondary{
    min-height:38px;
    padding:8px 13px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border-radius:9px;
    font-size:10px;
    font-weight:700;
    cursor:pointer
}

.sm-primary{
    border:0;
    background:linear-gradient(135deg,#7c3aed,#6d28d9);
    color:#fff;
    box-shadow:0 8px 20px rgba(109,40,217,.18)
}

.sm-secondary{
    border:1px solid #dcd5ef;
    background:#fff;
    color:#5f5870
}

.sm-primary:hover{
    background:linear-gradient(135deg,#6d28d9,#5b21b6)
}

.sm-secondary:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.sm-primary:disabled,
.sm-secondary:disabled{
    opacity:.65;
    cursor:not-allowed
}

/* Approved summary cards */
.sm-stats{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px
}

.sm-stat{
    min-height:90px;
    padding:14px 15px;
    display:flex;
    align-items:center;
    gap:11px;
    border:1px solid #ddd5f1;
    border-radius:13px;
    background:linear-gradient(180deg,#fff 0%,#fbf9ff 100%);
    box-shadow:none
}

.sm-stat:hover{
    border-color:#cfc3ef;
    background:linear-gradient(180deg,#fff 0%,#f8f4ff 100%)
}

.sm-stat-icon{
    width:38px;
    height:38px;
    flex:0 0 38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    background:#eee8ff;
    color:#7c3aed;
    font-size:16px
}

.sm-stat-content{
    min-width:0;
    display:block
}

.sm-stat-label{
    display:block;
    color:#9a94ae;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
    line-height:1.3
}

.sm-stat-value{
    margin-top:2px;
    display:block;
    color:#111827;
    font-size:20px;
    font-weight:800;
    line-height:1.2
}

.sm-stat-note{
    margin-top:2px;
    display:block;
    color:#9d96ac;
    font-size:7.5px;
    line-height:1.35
}

/* Approved table card */
.sm-card{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 24px rgba(37,29,80,.05)
}

.sm-toolbar{
    padding:13px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    background:#fbf9ff;
    border-bottom:1px solid #ece7f7
}

.sm-toolbar-left{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap
}

.sm-search{
    position:relative
}

.sm-search i{
    position:absolute;
    left:11px;
    top:50%;
    transform:translateY(-50%);
    color:#948da7;
    font-size:12px
}

.sm-input,
.sm-select{
    height:39px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    background:#fff;
    color:#312b47;
    font-size:10px;
    outline:0
}

.sm-input{padding:8px 11px}

.sm-search .sm-input{
    width:260px;
    padding-left:33px
}

.sm-select{
    padding:8px 30px 8px 10px
}

.sm-input:focus,
.sm-select:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10)
}

.sm-table-wrap{overflow:auto}

.sm-table{
    width:100%;
    min-width:1120px;
    border-collapse:collapse
}

.sm-table th{
    padding:10px 12px;
    border-bottom:1px solid #e8e2f2;
    background:#f6f2ff;
    color:#726a86;
    text-align:left;
    font-size:8px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.04em;
    white-space:nowrap
}

.sm-table td{
    padding:10px 12px;
    border-bottom:1px solid #f0ecf7;
    color:#433d54;
    font-size:9px;
    vertical-align:middle
}

.sm-table tbody tr:hover{
    background:#fcfbff
}

.sm-module-name{
    display:flex;
    align-items:center;
    gap:8px;
    min-width:0
}

.sm-row-icon{
    width:30px;
    height:30px;
    flex:0 0 30px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:8px;
    background:#f1ecff;
    color:#7c3aed;
    font-size:12px
}

.sm-module-title{
    display:block;
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.sm-module-code{
    display:block;
    margin-top:2px;
    color:#9b94a7;
    font-size:8px
}

.sm-submodule{
    position:relative;
    padding-left:18px
}

.sm-submodule:before{
    content:"";
    position:absolute;
    left:3px;
    top:50%;
    width:9px;
    border-top:1px solid #cfc5e8
}

.sm-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700
}

.sm-badge.active{background:#ecfdf5;color:#047857}
.sm-badge.inactive{background:#f3f4f6;color:#6b7280}
.sm-badge.sidebar{background:#f1ecff;color:#6d28d9}
.sm-badge.hidden{background:#fff7ed;color:#c2410c}
.sm-badge.core{background:#eef2ff;color:#4338ca}

.sm-actions{
    display:flex;
    align-items:center;
    gap:5px
}

.sm-icon-btn{
    width:30px;
    height:30px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#fff;
    color:#655d78;
    font-size:12px;
    cursor:pointer
}

.sm-icon-btn:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.sm-empty{
    padding:36px 15px;
    text-align:center;
    color:#928aa5;
    font-size:10px
}

/* Modal */
.sm-modal-backdrop{
    position:fixed;
    inset:0;
    z-index:15000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    background:rgba(18,24,45,.42);
    backdrop-filter:blur(3px)
}

.sm-modal-backdrop.show{display:flex}

.sm-modal{
    width:min(700px,100%);
    max-height:calc(100vh - 36px);
    overflow:auto;
    border:1px solid #ded7ef;
    border-radius:15px;
    background:#fff;
    box-shadow:0 24px 60px rgba(28,20,70,.22)
}

.sm-modal-header{
    padding:13px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff
}

.sm-modal-title-wrap{
    display:flex;
    align-items:center;
    gap:10px
}

.sm-modal-icon{
    width:34px;
    height:34px;
    flex:0 0 34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:9px;
    background:#eee8ff;
    color:#7c3aed;
    font-size:14px
}

.sm-modal-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800
}

.sm-modal-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px
}

.sm-modal-close{
    width:30px;
    height:30px;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#fff;
    color:#6d657d;
    cursor:pointer
}

.sm-modal-close:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.sm-modal-body{padding:15px}

.sm-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:13px
}

.sm-field.full{grid-column:1/-1}

.sm-field label{
    margin-bottom:6px;
    display:block;
    color:#4c465f;
    font-size:9px;
    font-weight:700
}

.sm-required{color:#dc2626}

.sm-textarea{
    width:100%;
    min-height:84px;
    resize:vertical;
    padding:9px 11px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    outline:0;
    background:#fff;
    color:#312b47;
    font-size:10px
}

.sm-textarea:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10)
}

.sm-toggle{
    min-height:48px;
    padding:9px 10px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border:1px solid #e2dcf2;
    border-radius:10px;
    background:#fbf9ff
}

.sm-toggle strong{
    display:block;
    color:#393248;
    font-size:9px
}

.sm-toggle small{
    margin-top:2px;
    display:block;
    color:#9a94aa;
    font-size:8px
}

.sm-modal-footer{
    padding:12px 15px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid #ece7f7;
    background:#fbf9ff
}

/* Loader */
.sm-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:smSpin .75s linear infinite
}

.sm-primary.loading .sm-loader{display:inline-block}

@keyframes smSpin{
    to{transform:rotate(360deg)}
}

/* Approved toast */
.sm-toast{
    position:fixed;
    top:82px;
    right:20px;
    z-index:20000;
    width:min(380px,calc(100vw - 24px));
    padding:12px 14px;
    display:flex;
    align-items:center;
    gap:10px;
    border:0;
    border-radius:11px;
    color:#fff;
    box-shadow:0 16px 34px rgba(16,24,40,.18);
    opacity:0;
    visibility:hidden;
    transform:translateY(-10px);
    transition:opacity .2s ease,transform .2s ease,visibility .2s ease;
    font-size:10px;
    line-height:1.45
}

.sm-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0)
}

.sm-toast.success{background:#059669}
.sm-toast.error{background:#dc2626}
.sm-toast.warning{background:#d97706}
.sm-toast.info{background:#4f46e5}

.sm-toast-icon{
    width:24px;
    height:24px;
    flex:0 0 24px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    background:rgba(255,255,255,.18);
    font-size:12px
}

.sm-toast-message{
    flex:1;
    min-width:0;
    font-weight:600
}

.sm-toast-close{
    width:24px;
    height:24px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:0;
    border-radius:7px;
    background:transparent;
    color:#fff;
    font-size:15px;
    cursor:pointer;
    opacity:.82
}

.sm-toast-close:hover{
    background:rgba(255,255,255,.12);
    opacity:1
}

/* Responsive */
@media(max-width:1100px){
    .sm-stats{
        grid-template-columns:repeat(3,minmax(0,1fr))
    }
}

@media(max-width:991.98px){
    .fp-main,
    body.fp-sidebar-collapsed .fp-main{
        margin-left:0
    }

    .fp-search,
    .fp-profile-text{
        display:none
    }

    .fp-mobile-brand{
        display:inline-flex
    }
}

@media(max-width:700px){
    .sm-header{
        flex-direction:column
    }

    .sm-header-actions{
        width:100%
    }

    .sm-header-actions .sm-primary,
    .sm-header-actions .sm-secondary{
        flex:1
    }

    .sm-toolbar{
        align-items:stretch
    }

    .sm-toolbar-left{
        width:100%
    }

    .sm-search{
        width:100%
    }

    .sm-search .sm-input{
        width:100%
    }

    .sm-form-grid{
        grid-template-columns:1fr
    }

    .sm-field.full{
        grid-column:auto
    }
}

@media(max-width:575.98px){
    .fp-topbar-inner{
        padding:8px 11px
    }

    .fp-page-subtitle{
        display:none
    }

    .fp-page-title{
        font-size:13px
    }

    .fp-content{
        padding:12px
    }

    .sm-stats{
        grid-template-columns:1fr
    }

    .sm-stat{
        min-height:82px
    }

    .sm-modal-footer{
        flex-direction:column-reverse
    }

    .sm-modal-footer .sm-primary,
    .sm-modal-footer .sm-secondary{
        width:100%
    }

    .sm-toast{
        top:74px;
        right:12px;
        left:12px;
        width:auto
    }
}
</style>
</head>
<body>
<div class="fp-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fp-main">
<?php require_once __DIR__ . '/includes/topbar.php'; ?>

<div class="fp-content">
<div class="sm-page">

<div class="sm-header">
    <div>
        <h2 class="sm-title">Sidebar Modules</h2>
        <div class="sm-description">Manage platform sidebar modules, parent/submodule hierarchy, icons, menu URL, ordering and visibility.</div>
    </div>
    <div class="sm-header-actions">
        <button type="button" class="sm-primary" id="addModuleBtn">
            <i class="bi bi-plus-lg"></i>
            Add Module
        </button>
    </div>
</div>

<div class="sm-stats">
<?php
$cards = array(
    array('Total Modules','bi bi-grid',(int)($stats['total'] ?? 0),'All configured modules'),
    array('Parent Modules','bi bi-folder2-open',(int)($stats['parent_count'] ?? 0),'Top-level sidebar groups'),
    array('Sub Modules','bi bi-diagram-3',(int)($stats['child_count'] ?? 0),'Child navigation items'),
    array('Sidebar Visible','bi bi-layout-sidebar',(int)($stats['sidebar_count'] ?? 0),'Displayed in sidebar'),
    array('Active','bi bi-check-circle',(int)($stats['active_count'] ?? 0),'Enabled modules')
);
foreach ($cards as $card):
?>
<div class="sm-stat">
    <span class="sm-stat-icon">
        <i class="<?= sm_h($card[1]) ?>"></i>
    </span>

    <span class="sm-stat-content">
        <span class="sm-stat-label"><?= sm_h($card[0]) ?></span>
        <span class="sm-stat-value"><?= number_format($card[2]) ?></span>
        <span class="sm-stat-note"><?= sm_h($card[3]) ?></span>
    </span>
</div>
<?php endforeach; ?>
</div>

<section class="sm-card">
<form class="sm-toolbar" method="get">
    <div class="sm-toolbar-left">
        <div class="sm-search">
            <i class="bi bi-search"></i>
            <input type="text" class="sm-input" name="search" value="<?= sm_h($search) ?>" placeholder="Search module, code or URL">
        </div>

        <select class="sm-select" name="type" onchange="this.form.submit()">
            <option value="">All Types</option>
            <option value="parent" <?= $type === 'parent' ? 'selected' : '' ?>>Parent Modules</option>
            <option value="child" <?= $type === 'child' ? 'selected' : '' ?>>Sub Modules</option>
        </select>

        <select class="sm-select" name="status" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>

        <?php if ($search !== '' || $status !== '' || $type !== ''): ?>
        <a class="sm-secondary" href="sidebar-modules.php"><i class="bi bi-x-lg"></i> Clear</a>
        <?php endif; ?>
    </div>

    <button type="submit" class="sm-secondary"><i class="bi bi-funnel"></i> Filter</button>
</form>

<div class="sm-table-wrap">
<table class="sm-table">
<thead>
<tr>
    <th>S/No</th>
    <th>Module</th>
    <th>Parent</th>
    <th>Menu URL</th>
    <th>Order</th>
    <th>Sidebar</th>
    <th>Core</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
<?php if (!$modules): ?>
<tr><td colspan="9"><div class="sm-empty">No sidebar modules found.</div></td></tr>
<?php else: ?>
<?php foreach ($modules as $index => $module): ?>
<tr>
    <td><?= $index + 1 ?></td>
    <td>
        <div class="<?= $module['parent_id'] ? 'sm-submodule' : '' ?>">
            <div class="sm-module-name">
                <span class="sm-row-icon"><i class="<?= sm_h($module['icon_name'] ?: 'bi bi-grid') ?>"></i></span>
                <span>
                    <span class="sm-module-title"><?= sm_h($module['module_name']) ?></span>
                    <span class="sm-module-code"><?= sm_h($module['module_code']) ?></span>
                </span>
            </div>
        </div>
    </td>
    <td><?= sm_h($module['parent_name'] ?: 'Root') ?></td>
    <td><?= sm_h($module['menu_url'] ?: '-') ?></td>
    <td><?= (int)$module['menu_order'] ?></td>
    <td><span class="sm-badge <?= (int)$module['is_sidebar_item'] === 1 ? 'sidebar' : 'hidden' ?>"><?= (int)$module['is_sidebar_item'] === 1 ? 'Visible' : 'Hidden' ?></span></td>
    <td><?= (int)$module['is_core'] === 1 ? '<span class="sm-badge core">Core</span>' : '-' ?></td>
    <td><span class="sm-badge <?= (int)$module['is_active'] === 1 ? 'active' : 'inactive' ?>"><?= (int)$module['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
    <td>
        <div class="sm-actions">
            <button type="button" class="sm-icon-btn module-edit" title="Edit" data-row='<?= sm_h(json_encode($module, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'><i class="bi bi-pencil"></i></button>
            <button type="button" class="sm-icon-btn module-sidebar" title="<?= (int)$module['is_sidebar_item'] === 1 ? 'Hide from sidebar' : 'Show in sidebar' ?>" data-id="<?= (int)$module['id'] ?>" data-value="<?= (int)$module['is_sidebar_item'] ?>"><i class="bi <?= (int)$module['is_sidebar_item'] === 1 ? 'bi-eye' : 'bi-eye-slash' ?>"></i></button>
            <button type="button" class="sm-icon-btn module-status" title="<?= (int)$module['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>" data-id="<?= (int)$module['id'] ?>" data-value="<?= (int)$module['is_active'] ?>"><i class="bi <?= (int)$module['is_active'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

</div>
</div>
</main>
</div>

<div id="smToast" class="sm-toast">
    <span class="sm-toast-icon"><i id="smToastIcon" class="bi bi-check-lg"></i></span>
    <span id="smToastMessage" class="sm-toast-message"></span>
    <button type="button" id="smToastClose" class="sm-toast-close"><i class="bi bi-x-lg"></i></button>
</div>

<div class="sm-modal-backdrop" id="moduleModal">
<div class="sm-modal">
<form id="moduleForm" novalidate>
<input type="hidden" name="csrf_token" value="<?= sm_h($csrfToken) ?>">
<input type="hidden" name="action" value="save_module">
<input type="hidden" name="id" id="moduleId">

<div class="sm-modal-header">
    <div class="sm-modal-title-wrap">
        <span class="sm-modal-icon"><i class="bi bi-layout-sidebar"></i></span>
        <span>
            <h3 class="sm-modal-title" id="moduleModalTitle">Add Sidebar Module</h3>
            <span class="sm-modal-subtitle">Configure module hierarchy and navigation visibility</span>
        </span>
    </div>
    <button type="button" class="sm-modal-close" id="moduleModalClose"><i class="bi bi-x-lg"></i></button>
</div>

<div class="sm-modal-body">
<div class="sm-form-grid">
    <div class="sm-field">
        <label>Module Name <span class="sm-required">*</span></label>
        <input class="sm-input" style="width:100%" name="module_name" id="moduleName" maxlength="190" required placeholder="Customers">
    </div>

    <div class="sm-field">
        <label>Module Code <span class="sm-required">*</span></label>
        <input class="sm-input" style="width:100%" name="module_code" id="moduleCode" maxlength="120" required placeholder="customers">
    </div>

    <div class="sm-field">
        <label>Parent Module</label>
        <select class="sm-select" style="width:100%" name="parent_id" id="parentId">
            <option value="">Root / Parent Module</option>
            <?php foreach ($parentModules as $parent): ?>
            <option value="<?= (int)$parent['id'] ?>"><?= sm_h($parent['module_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="sm-field">
        <label>Menu URL</label>
        <input class="sm-input" style="width:100%" name="menu_url" id="menuUrl" maxlength="255" placeholder="customers.php">
    </div>

    <div class="sm-field">
        <label>Bootstrap Icon Class</label>
        <input class="sm-input" style="width:100%" name="icon_name" id="iconName" maxlength="120" placeholder="bi bi-people">
    </div>

    <div class="sm-field">
        <label>Menu Order</label>
        <input class="sm-input" style="width:100%" type="number" name="menu_order" id="menuOrder" min="0" max="99999" value="0">
    </div>

    <div class="sm-field full">
        <label>Description</label>
        <textarea class="sm-textarea" name="description" id="description" maxlength="1000"></textarea>
    </div>

    <div class="sm-field">
        <label class="sm-toggle">
            <span><strong>Show in Sidebar</strong><small>Display this module in navigation</small></span>
            <span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="is_sidebar_item" id="isSidebarItem" value="1" checked></span>
        </label>
    </div>

    <div class="sm-field">
        <label class="sm-toggle">
            <span><strong>Active Module</strong><small>Allow this module to be used</small></span>
            <span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" checked></span>
        </label>
    </div>
</div>
</div>

<div class="sm-modal-footer">
    <button type="button" class="sm-secondary" id="moduleCancel">Cancel</button>
    <button type="submit" class="sm-primary" id="moduleSaveBtn">
        <span class="sm-loader"></span>
        <i class="bi bi-check2-circle"></i>
        <span id="moduleSaveText">Save Module</span>
    </button>
</div>
</form>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
(function(){
'use strict';

var body=document.body;
var toggle=document.getElementById('fpSidebarToggle');
var closeBtn=document.getElementById('fpSidebarClose');
var overlay=document.getElementById('fpSidebarOverlay');
var storageKey='fieldplx_sidebar_collapsed';

function restoreSidebar(){
    if(window.innerWidth<992){
        body.classList.remove('fp-sidebar-collapsed');
        return;
    }

    if(localStorage.getItem(storageKey)==='1'){
        body.classList.add('fp-sidebar-collapsed');
    }else{
        body.classList.remove('fp-sidebar-collapsed');
    }
}

restoreSidebar();

if(toggle){
    toggle.addEventListener('click',function(){
        if(window.innerWidth<992){
            body.classList.toggle('fp-sidebar-mobile-open');
            return;
        }

        body.classList.toggle('fp-sidebar-collapsed');

        localStorage.setItem(
            storageKey,
            body.classList.contains('fp-sidebar-collapsed')?'1':'0'
        );
    });
}

if(closeBtn){
    closeBtn.addEventListener('click',function(){
        body.classList.remove('fp-sidebar-mobile-open');
    });
}

if(overlay){
    overlay.addEventListener('click',function(){
        body.classList.remove('fp-sidebar-mobile-open');
    });
}

document.querySelectorAll('.fp-sidebar-menu-toggle').forEach(function(btn){
    btn.addEventListener('click',function(){
        var menu=btn.closest('.fp-sidebar-menu');

        if(menu){
            menu.classList.toggle('open');
        }
    });
});

var toast=document.getElementById('smToast');
var toastMessage=document.getElementById('smToastMessage');
var toastIcon=document.getElementById('smToastIcon');
var toastClose=document.getElementById('smToastClose');
var toastTimer=null;

function showToast(type,message,duration){
    if(toastTimer){clearTimeout(toastTimer);}
    var icons={success:'bi-check-lg',error:'bi-x-lg',warning:'bi-exclamation-lg',info:'bi-info-lg'};
    var t=type||'info';
    toast.className='sm-toast '+t;
    toastMessage.textContent=message||'Notification';
    toastIcon.className='bi '+(icons[t]||icons.info);
    toast.classList.add('show');
    toastTimer=setTimeout(function(){toast.classList.remove('show');toastTimer=null;},typeof duration==='number'?duration:3000);
}

toastClose.addEventListener('click',function(){
    if(toastTimer){clearTimeout(toastTimer);}
    toast.classList.remove('show');
});

function apiRequest(formData){
    return fetch('api/sidebar-modules.php',{
        method:'POST',
        body:formData,
        credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
    })
    .then(function(response){
        return response.text().then(function(rawText){
            var text=(rawText||'').trim();
            var data=null;

            try{
                data=text!==''?JSON.parse(text):{};
            }catch(e){
                var clean=text.replace(/<br\s*\/?>/gi,' ').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
                throw new Error(clean!==''?'Server error: '+clean:'Server returned an invalid response.');
            }

            return {ok:response.ok,status:response.status,data:data};
        });
    });
}

var modal=document.getElementById('moduleModal');
var form=document.getElementById('moduleForm');
var saveBtn=document.getElementById('moduleSaveBtn');
var saveText=document.getElementById('moduleSaveText');

function closeModal(){modal.classList.remove('show');}

function openModal(row){
    form.reset();
    document.getElementById('moduleId').value='';
    document.getElementById('moduleModalTitle').textContent='Add Sidebar Module';
    saveText.textContent='Save Module';
    document.getElementById('isSidebarItem').checked=true;
    document.getElementById('isActive').checked=true;
    document.getElementById('menuOrder').value='0';

    Array.prototype.forEach.call(document.getElementById('parentId').options,function(option){option.disabled=false;});

    if(row){
        document.getElementById('moduleId').value=row.id||'';
        document.getElementById('moduleName').value=row.module_name||'';
        document.getElementById('moduleCode').value=row.module_code||'';
        document.getElementById('parentId').value=row.parent_id||'';
        document.getElementById('menuUrl').value=row.menu_url||'';
        document.getElementById('iconName').value=row.icon_name||'';
        document.getElementById('menuOrder').value=row.menu_order||0;
        document.getElementById('description').value=row.description||'';
        document.getElementById('isSidebarItem').checked=String(row.is_sidebar_item)==='1';
        document.getElementById('isActive').checked=String(row.is_active)==='1';
        document.getElementById('moduleModalTitle').textContent='Edit Sidebar Module';
        saveText.textContent='Update Module';

        Array.prototype.forEach.call(document.getElementById('parentId').options,function(option){
            if(String(option.value)===String(row.id)){option.disabled=true;}
        });
    }

    modal.classList.add('show');
}

document.getElementById('addModuleBtn').addEventListener('click',function(){openModal(null);});
document.getElementById('moduleModalClose').addEventListener('click',closeModal);
document.getElementById('moduleCancel').addEventListener('click',closeModal);
modal.addEventListener('click',function(e){if(e.target===modal){closeModal();}});

document.querySelectorAll('.module-edit').forEach(function(btn){
    btn.addEventListener('click',function(){
        try{openModal(JSON.parse(btn.getAttribute('data-row')));}
        catch(e){showToast('error','Unable to load module details.',3000);}
    });
});

form.addEventListener('submit',function(e){
    e.preventDefault();

    if(!form.checkValidity()){
        showToast('warning','Please complete the required module fields.',3000);
        form.reportValidity();
        return;
    }

    saveBtn.disabled=true;
    saveBtn.classList.add('loading');
    saveText.textContent='Saving...';

    apiRequest(new FormData(form))
    .then(function(result){
        if(!result.ok||!result.data.success){throw new Error(result.data.message||'Unable to save module.');}
        showToast('success',result.data.message,3000);
        closeModal();
        setTimeout(function(){window.location.reload();},500);
    })
    .catch(function(error){
        showToast('error',error.message||'Unable to save module.',3000);
        saveBtn.disabled=false;
        saveBtn.classList.remove('loading');
        saveText.textContent='Save Module';
    });
});

document.querySelectorAll('.module-status').forEach(function(btn){
    btn.addEventListener('click',function(){
        var fd=new FormData();
        fd.append('csrf_token','<?= sm_h($csrfToken) ?>');
        fd.append('action','toggle_status');
        fd.append('id',btn.getAttribute('data-id'));
        fd.append('is_active',btn.getAttribute('data-value')==='1'?'0':'1');

        apiRequest(fd).then(function(result){
            if(!result.ok||!result.data.success){throw new Error(result.data.message||'Unable to update module status.');}
            showToast('success',result.data.message,3000);
            setTimeout(function(){window.location.reload();},500);
        }).catch(function(error){
            showToast('error',error.message||'Unable to update module status.',3000);
        });
    });
});

document.querySelectorAll('.module-sidebar').forEach(function(btn){
    btn.addEventListener('click',function(){
        var fd=new FormData();
        fd.append('csrf_token','<?= sm_h($csrfToken) ?>');
        fd.append('action','toggle_sidebar');
        fd.append('id',btn.getAttribute('data-id'));
        fd.append('is_sidebar_item',btn.getAttribute('data-value')==='1'?'0':'1');

        apiRequest(fd).then(function(result){
            if(!result.ok||!result.data.success){throw new Error(result.data.message||'Unable to update sidebar visibility.');}
            showToast('success',result.data.message,3000);
            setTimeout(function(){window.location.reload();},500);
        }).catch(function(error){
            showToast('error',error.message||'Unable to update sidebar visibility.',3000);
        });
    });
});

})();
</script>
</body>
</html>
