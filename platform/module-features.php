<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Module Features';
$activePage = 'module-features';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['module_features_csrf'])) {
    $_SESSION['module_features_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['module_features_csrf'];

function mf_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

$search = isset($_GET['search'])
    ? trim((string)$_GET['search'])
    : '';

$status = isset($_GET['status'])
    ? trim((string)$_GET['status'])
    : '';

$moduleFilter = isset($_GET['module_id'])
    ? (int)$_GET['module_id']
    : 0;

$modules = $pdo->query("
    SELECT
        m.id,
        m.parent_id,
        m.module_code,
        m.module_name,
        m.icon_name,
        m.menu_order,
        p.module_name AS parent_name
    FROM modules m
    LEFT JOIN modules p
        ON p.id = m.parent_id
    WHERE m.is_active = 1
    ORDER BY
        COALESCE(p.menu_order, m.menu_order),
        CASE WHEN m.parent_id IS NULL THEN 0 ELSE 1 END,
        m.menu_order,
        m.module_name
")->fetchAll();

$sql = "
    SELECT
        f.id,
        f.module_id,
        f.feature_code,
        f.feature_name,
        f.description,
        f.is_active,
        f.updated_by,
        f.created_at,
        f.updated_at,
        m.module_name,
        m.module_code,
        m.icon_name,
        m.parent_id,
        p.module_name AS parent_name
    FROM module_features f
    INNER JOIN modules m
        ON m.id = f.module_id
    LEFT JOIN modules p
        ON p.id = m.parent_id
    WHERE 1=1
";

$params = array();

if ($search !== '') {
    $sql .= "
        AND (
            f.feature_name LIKE :search
            OR f.feature_code LIKE :search
            OR f.description LIKE :search
            OR m.module_name LIKE :search
            OR m.module_code LIKE :search
            OR p.module_name LIKE :search
        )
    ";

    $params[':search'] = '%' . $search . '%';
}

if ($moduleFilter > 0) {
    $sql .= " AND f.module_id = :module_id ";
    $params[':module_id'] = $moduleFilter;
}

if ($status === 'active') {
    $sql .= " AND f.is_active = 1 ";
} elseif ($status === 'inactive') {
    $sql .= " AND f.is_active = 0 ";
}

$sql .= "
    ORDER BY
        COALESCE(p.menu_order, m.menu_order),
        m.menu_order,
        m.module_name,
        f.feature_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$features = $stmt->fetchAll();

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(is_active = 1) AS active_count,
        SUM(is_active = 0) AS inactive_count,
        COUNT(DISTINCT module_id) AS module_count
    FROM module_features
")->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= mf_h($pageTitle) ?> - FieldPlx</title>
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
    margin:0;min-height:100vh;overflow-x:hidden;background:#fff;color:var(--fp-text);
    font-family:"Inter",sans-serif;font-size:13px
}
a{text-decoration:none}
button,input,select,textarea{font-family:inherit}
.fp-layout{min-height:100vh}
.fp-main{
    min-height:calc(100vh - 52px);
    margin-left:var(--fp-sidebar-width);
    transition:margin-left .22s ease
}
body.fp-sidebar-collapsed .fp-main{margin-left:var(--fp-sidebar-collapsed-width)}
.fp-topbar{
    position:sticky;top:0;z-index:1030;min-height:var(--fp-topbar-height);
    border-bottom:1px solid #ded8f3;background:rgba(248,246,255,.96);backdrop-filter:blur(14px)
}
.fp-topbar-inner{min-height:var(--fp-topbar-height);padding:8px 18px;display:flex;align-items:center;gap:13px}
.fp-menu-toggle,.fp-icon-button{
    width:39px;height:39px;min-width:39px;padding:0;display:inline-flex;align-items:center;justify-content:center;
    border:1px solid #d9d2ef;border-radius:10px;background:#fff;color:#39345f;font-size:18px
}
.fp-menu-toggle:hover,.fp-icon-button:hover{border-color:#bda9ff;background:#f4f0ff;color:var(--fp-accent-dark)}
.fp-page-heading{min-width:0;margin-right:auto}
.fp-page-title{margin:0;color:#17172e;font-size:15px;font-weight:700;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fp-page-subtitle{margin-top:2px;color:var(--fp-muted);font-size:10px}
.fp-search{width:min(340px,31vw);position:relative;flex:0 1 340px}
.fp-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8f88aa;font-size:14px;pointer-events:none}
.fp-search input{width:100%;height:39px;padding:8px 13px 8px 36px;border:1px solid #dcd5ef;border-radius:10px;outline:0;background:#f8f6ff;font-size:12px}
.fp-search input:focus{border-color:#a78bfa;background:#fff;box-shadow:0 0 0 3px rgba(139,92,246,.12)}
.fp-notification-wrap{position:relative}
.fp-notification-count{
    position:absolute;top:-5px;right:-5px;z-index:3;min-width:18px;height:18px;padding:0 5px;
    display:inline-flex;align-items:center;justify-content:center;border:2px solid #fff;border-radius:999px;
    background:var(--fp-danger);color:#fff;font-size:9px;font-weight:700
}
.fp-profile{min-width:0;padding:4px 9px 4px 5px;display:flex;align-items:center;gap:9px;border:1px solid var(--fp-border);border-radius:11px;background:#fff}
.fp-avatar{width:32px;height:32px;flex:0 0 32px;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;background:linear-gradient(135deg,#6d4df4,#9a5cff);color:#fff;font-size:10px;font-weight:700}
.fp-profile-text{max-width:145px;min-width:0}
.fp-profile-name,.fp-profile-role{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.fp-profile-name{color:#111827;font-size:11px;font-weight:700}
.fp-profile-role{margin-top:1px;color:var(--fp-muted);font-size:9px}
.fp-mobile-brand{display:none}
.fp-content{padding:18px;background:#fff}

.mf-page{display:grid;gap:16px}
.mf-header{display:flex;align-items:flex-start;justify-content:space-between;gap:15px}
.mf-title{margin:0;color:#111827;font-size:20px;font-weight:800}
.mf-description{margin-top:4px;max-width:780px;color:#77718e;font-size:10px;line-height:1.55}
.mf-header-actions{display:flex;align-items:center;gap:8px}

.mf-primary,.mf-secondary{
    min-height:38px;padding:8px 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;
    border-radius:9px;font-size:10px;font-weight:700;cursor:pointer
}
.mf-primary{border:0;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;box-shadow:0 8px 20px rgba(109,40,217,.18)}
.mf-secondary{border:1px solid #dcd5ef;background:#fff;color:#5f5870}
.mf-primary:hover{background:linear-gradient(135deg,#6d28d9,#5b21b6)}
.mf-secondary:hover{border-color:#bda9ff;background:#f7f3ff;color:#6d28d9}
.mf-primary:disabled,.mf-secondary:disabled{opacity:.65;cursor:not-allowed}

.mf-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.mf-stat{
    min-height:90px;padding:14px 15px;display:flex;align-items:center;gap:11px;
    border:1px solid #ddd5f1;border-radius:13px;
    background:linear-gradient(180deg,#fff 0%,#fbf9ff 100%);box-shadow:none
}
.mf-stat:hover{border-color:#cfc3ef;background:linear-gradient(180deg,#fff 0%,#f8f4ff 100%)}
.mf-stat-icon{
    width:38px;height:38px;flex:0 0 38px;display:inline-flex;align-items:center;justify-content:center;
    border-radius:10px;background:#eee8ff;color:#7c3aed;font-size:16px
}
.mf-stat-content{min-width:0;display:block}
.mf-stat-label{display:block;color:#9a94ae;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;line-height:1.3}
.mf-stat-value{margin-top:2px;display:block;color:#111827;font-size:20px;font-weight:800;line-height:1.2}
.mf-stat-note{margin-top:2px;display:block;color:#9d96ac;font-size:7.5px;line-height:1.35}

.mf-card{overflow:hidden;border:1px solid #ded7ef;border-radius:14px;background:#fff;box-shadow:0 8px 24px rgba(37,29,80,.05)}
.mf-toolbar{
    padding:13px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    background:#fbf9ff;border-bottom:1px solid #ece7f7
}
.mf-toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.mf-search{position:relative}
.mf-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#948da7;font-size:12px}
.mf-input,.mf-select{
    height:39px;border:1px solid #dcd5ef;border-radius:9px;background:#fff;color:#312b47;font-size:10px;outline:0
}
.mf-input{padding:8px 11px}
.mf-search .mf-input{width:260px;padding-left:33px}
.mf-select{padding:8px 30px 8px 10px}
.mf-input:focus,.mf-select:focus{border-color:#a78bfa;box-shadow:0 0 0 3px rgba(139,92,246,.10)}

.mf-table-wrap{overflow:auto}
.mf-table{width:100%;min-width:1040px;border-collapse:collapse}
.mf-table th{
    padding:10px 12px;border-bottom:1px solid #e8e2f2;background:#f6f2ff;color:#726a86;
    text-align:left;font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap
}
.mf-table td{padding:10px 12px;border-bottom:1px solid #f0ecf7;color:#433d54;font-size:9px;vertical-align:middle}
.mf-table tbody tr:hover{background:#fcfbff}

.mf-feature{display:flex;align-items:center;gap:8px;min-width:0}
.mf-feature-icon{
    width:30px;height:30px;flex:0 0 30px;display:inline-flex;align-items:center;justify-content:center;
    border-radius:8px;background:#f1ecff;color:#7c3aed;font-size:12px
}
.mf-feature-name{display:block;color:#302a40;font-size:9px;font-weight:800}
.mf-feature-code{display:block;margin-top:2px;color:#9b94a7;font-size:8px}
.mf-module-path{color:#5f5870;font-size:9px}
.mf-module-parent{display:block;color:#9b94a7;font-size:8px;margin-bottom:2px}
.mf-description-cell{max-width:320px;color:#756d83;line-height:1.45}

.mf-badge{display:inline-flex;align-items:center;padding:4px 7px;border-radius:999px;font-size:8px;font-weight:700}
.mf-badge.active{background:#ecfdf5;color:#047857}
.mf-badge.inactive{background:#f3f4f6;color:#6b7280}

.mf-actions{display:flex;align-items:center;gap:5px}
.mf-icon-btn{
    width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;
    border:1px solid #ddd6ec;border-radius:8px;background:#fff;color:#655d78;font-size:12px;cursor:pointer
}
.mf-icon-btn:hover{border-color:#bda9ff;background:#f7f3ff;color:#6d28d9}
.mf-empty{padding:36px 15px;text-align:center;color:#928aa5;font-size:10px}

.mf-modal-backdrop{
    position:fixed;inset:0;z-index:15000;display:none;align-items:center;justify-content:center;padding:18px;
    background:rgba(18,24,45,.42);backdrop-filter:blur(3px)
}
.mf-modal-backdrop.show{display:flex}
.mf-modal{
    width:min(650px,100%);max-height:calc(100vh - 36px);overflow:auto;
    border:1px solid #ded7ef;border-radius:15px;background:#fff;box-shadow:0 24px 60px rgba(28,20,70,.22)
}
.mf-modal-header{
    padding:13px 15px;display:flex;align-items:center;justify-content:space-between;gap:10px;
    border-bottom:1px solid #ece7f7;background:#fbf9ff
}
.mf-modal-title-wrap{display:flex;align-items:center;gap:10px}
.mf-modal-icon{
    width:34px;height:34px;flex:0 0 34px;display:inline-flex;align-items:center;justify-content:center;
    border-radius:9px;background:#eee8ff;color:#7c3aed;font-size:14px
}
.mf-modal-title{margin:0;color:#111827;font-size:12px;font-weight:800}
.mf-modal-subtitle{margin-top:2px;color:#9a94aa;font-size:8px}
.mf-modal-close{width:30px;height:30px;border:1px solid #ddd6ec;border-radius:8px;background:#fff;color:#6d657d;cursor:pointer}
.mf-modal-close:hover{border-color:#bda9ff;background:#f7f3ff;color:#6d28d9}
.mf-modal-body{padding:15px}
.mf-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
.mf-field.full{grid-column:1/-1}
.mf-field label{margin-bottom:6px;display:block;color:#4c465f;font-size:9px;font-weight:700}
.mf-required{color:#dc2626}
.mf-textarea{
    width:100%;min-height:88px;resize:vertical;padding:9px 11px;border:1px solid #dcd5ef;
    border-radius:9px;outline:0;background:#fff;color:#312b47;font-size:10px
}
.mf-textarea:focus{border-color:#a78bfa;box-shadow:0 0 0 3px rgba(139,92,246,.10)}
.mf-toggle{
    min-height:48px;padding:9px 10px;display:flex;align-items:center;justify-content:space-between;gap:10px;
    border:1px solid #e2dcf2;border-radius:10px;background:#fbf9ff
}
.mf-toggle strong{display:block;color:#393248;font-size:9px}
.mf-toggle small{margin-top:2px;display:block;color:#9a94aa;font-size:8px}
.mf-modal-footer{
    padding:12px 15px;display:flex;justify-content:flex-end;gap:8px;
    border-top:1px solid #ece7f7;background:#fbf9ff
}

.mf-loader{
    width:14px;height:14px;display:none;border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;animation:mfSpin .75s linear infinite
}
.mf-primary.loading .mf-loader{display:inline-block}
@keyframes mfSpin{to{transform:rotate(360deg)}}

.mf-toast{
    position:fixed;top:82px;right:20px;z-index:20000;width:min(380px,calc(100vw - 24px));padding:12px 14px;
    display:flex;align-items:center;gap:10px;border:0;border-radius:11px;color:#fff;
    box-shadow:0 16px 34px rgba(16,24,40,.18);opacity:0;visibility:hidden;transform:translateY(-10px);
    transition:opacity .2s ease,transform .2s ease,visibility .2s ease;font-size:10px;line-height:1.45
}
.mf-toast.show{opacity:1;visibility:visible;transform:translateY(0)}
.mf-toast.success{background:#059669}
.mf-toast.error{background:#dc2626}
.mf-toast.warning{background:#d97706}
.mf-toast.info{background:#4f46e5}
.mf-toast-icon{
    width:24px;height:24px;flex:0 0 24px;display:inline-flex;align-items:center;justify-content:center;
    border-radius:999px;background:rgba(255,255,255,.18);font-size:12px
}
.mf-toast-message{flex:1;min-width:0;font-weight:600}
.mf-toast-close{
    width:24px;height:24px;padding:0;display:inline-flex;align-items:center;justify-content:center;
    border:0;border-radius:7px;background:transparent;color:#fff;font-size:15px;cursor:pointer;opacity:.82
}
.mf-toast-close:hover{background:rgba(255,255,255,.12);opacity:1}

@media(max-width:1100px){
    .mf-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:991.98px){
    .fp-main,body.fp-sidebar-collapsed .fp-main{margin-left:0}
    .fp-search,.fp-profile-text{display:none}
    .fp-mobile-brand{display:inline-flex}
}
@media(max-width:700px){
    .mf-header{flex-direction:column}
    .mf-header-actions{width:100%}
    .mf-header-actions .mf-primary,.mf-header-actions .mf-secondary{flex:1}
    .mf-toolbar{align-items:stretch}
    .mf-toolbar-left{width:100%}
    .mf-search{width:100%}
    .mf-search .mf-input{width:100%}
    .mf-form-grid{grid-template-columns:1fr}
    .mf-field.full{grid-column:auto}
}
@media(max-width:575.98px){
    .fp-topbar-inner{padding:8px 11px}
    .fp-page-subtitle{display:none}
    .fp-page-title{font-size:13px}
    .fp-content{padding:12px}
    .mf-stats{grid-template-columns:1fr}
    .mf-stat{min-height:82px}
    .mf-modal-footer{flex-direction:column-reverse}
    .mf-modal-footer .mf-primary,.mf-modal-footer .mf-secondary{width:100%}
    .mf-toast{top:74px;right:12px;left:12px;width:auto}
}
</style>
</head>

<body>
<div class="fp-layout">

<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="fp-main">

<?php require_once __DIR__ . '/includes/topbar.php'; ?>

<div class="fp-content">
<div class="mf-page">

<div class="mf-header">
    <div>
        <h2 class="mf-title">Module Features</h2>
        <div class="mf-description">
            Define feature-level capabilities inside each FieldPlx module.
            These features can later be assigned to plans and tenant feature access.
        </div>
    </div>

    <div class="mf-header-actions">
        <button type="button" class="mf-primary" id="addFeatureBtn">
            <i class="bi bi-plus-lg"></i>
            Add Feature
        </button>
    </div>
</div>

<div class="mf-stats">

<div class="mf-stat">
    <span class="mf-stat-icon"><i class="bi bi-stars"></i></span>
    <span class="mf-stat-content">
        <span class="mf-stat-label">Total Features</span>
        <span class="mf-stat-value"><?= number_format((int)($stats['total'] ?? 0)) ?></span>
        <span class="mf-stat-note">All configured module features</span>
    </span>
</div>

<div class="mf-stat">
    <span class="mf-stat-icon"><i class="bi bi-check-circle"></i></span>
    <span class="mf-stat-content">
        <span class="mf-stat-label">Active Features</span>
        <span class="mf-stat-value"><?= number_format((int)($stats['active_count'] ?? 0)) ?></span>
        <span class="mf-stat-note">Available for entitlement</span>
    </span>
</div>

<div class="mf-stat">
    <span class="mf-stat-icon"><i class="bi bi-pause-circle"></i></span>
    <span class="mf-stat-content">
        <span class="mf-stat-label">Inactive Features</span>
        <span class="mf-stat-value"><?= number_format((int)($stats['inactive_count'] ?? 0)) ?></span>
        <span class="mf-stat-note">Currently disabled</span>
    </span>
</div>

<div class="mf-stat">
    <span class="mf-stat-icon"><i class="bi bi-grid"></i></span>
    <span class="mf-stat-content">
        <span class="mf-stat-label">Modules Covered</span>
        <span class="mf-stat-value"><?= number_format((int)($stats['module_count'] ?? 0)) ?></span>
        <span class="mf-stat-note">Modules with feature definitions</span>
    </span>
</div>

</div>

<section class="mf-card">

<form method="get" class="mf-toolbar">

    <div class="mf-toolbar-left">

        <div class="mf-search">
            <i class="bi bi-search"></i>
            <input
                type="text"
                class="mf-input"
                name="search"
                value="<?= mf_h($search) ?>"
                placeholder="Search feature, code or module"
            >
        </div>

        <select
            class="mf-select"
            name="module_id"
            onchange="this.form.submit()"
        >
            <option value="">All Modules</option>

            <?php foreach ($modules as $module): ?>
                <option
                    value="<?= (int)$module['id'] ?>"
                    <?= $moduleFilter === (int)$module['id'] ? 'selected' : '' ?>
                >
                    <?= mf_h(
                        $module['parent_name']
                            ? $module['parent_name'] . ' / ' . $module['module_name']
                            : $module['module_name']
                    ) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select
            class="mf-select"
            name="status"
            onchange="this.form.submit()"
        >
            <option value="">All Status</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>

        <?php if ($search !== '' || $status !== '' || $moduleFilter > 0): ?>
            <a href="module-features.php" class="mf-secondary">
                <i class="bi bi-x-lg"></i>
                Clear
            </a>
        <?php endif; ?>

    </div>

    <button type="submit" class="mf-secondary">
        <i class="bi bi-funnel"></i>
        Filter
    </button>

</form>

<div class="mf-table-wrap">
<table class="mf-table">

<thead>
<tr>
    <th>S/No</th>
    <th>Feature</th>
    <th>Module</th>
    <th>Description</th>
    <th>Status</th>
    <th>Created</th>
    <th>Updated</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$features): ?>

<tr>
    <td colspan="8">
        <div class="mf-empty">
            No module features found.
        </div>
    </td>
</tr>

<?php else: ?>

<?php foreach ($features as $index => $feature): ?>

<tr>
    <td><?= $index + 1 ?></td>

    <td>
        <div class="mf-feature">
            <span class="mf-feature-icon">
                <i class="bi bi-stars"></i>
            </span>

            <span>
                <span class="mf-feature-name">
                    <?= mf_h($feature['feature_name']) ?>
                </span>

                <span class="mf-feature-code">
                    <?= mf_h($feature['feature_code']) ?>
                </span>
            </span>
        </div>
    </td>

    <td>
        <span class="mf-module-path">
            <?php if ($feature['parent_name']): ?>
                <span class="mf-module-parent">
                    <?= mf_h($feature['parent_name']) ?>
                </span>
            <?php endif; ?>

            <?= mf_h($feature['module_name']) ?>
        </span>
    </td>

    <td>
        <div class="mf-description-cell">
            <?= mf_h($feature['description'] ?: '-') ?>
        </div>
    </td>

    <td>
        <span class="mf-badge <?= (int)$feature['is_active'] === 1 ? 'active' : 'inactive' ?>">
            <?= (int)$feature['is_active'] === 1 ? 'Active' : 'Inactive' ?>
        </span>
    </td>

    <td>
        <?= $feature['created_at']
            ? mf_h(date('d M Y', strtotime($feature['created_at'])))
            : '-' ?>
    </td>

    <td>
        <?= $feature['updated_at']
            ? mf_h(date('d M Y', strtotime($feature['updated_at'])))
            : '-' ?>
    </td>

    <td>
        <div class="mf-actions">

            <button
                type="button"
                class="mf-icon-btn feature-edit"
                title="Edit"
                data-row='<?= mf_h(
                    json_encode(
                        $feature,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    )
                ) ?>'
            >
                <i class="bi bi-pencil"></i>
            </button>

            <button
                type="button"
                class="mf-icon-btn feature-status"
                data-id="<?= (int)$feature['id'] ?>"
                data-value="<?= (int)$feature['is_active'] ?>"
                title="<?= (int)$feature['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>"
            >
                <i class="bi <?= (int)$feature['is_active'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
            </button>

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

<div
    id="mfToast"
    class="mf-toast"
    role="status"
    aria-live="polite"
    aria-atomic="true"
>
    <span class="mf-toast-icon">
        <i id="mfToastIcon" class="bi bi-check-lg"></i>
    </span>

    <span
        id="mfToastMessage"
        class="mf-toast-message"
    ></span>

    <button
        type="button"
        id="mfToastClose"
        class="mf-toast-close"
        aria-label="Close"
    >
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<div class="mf-modal-backdrop" id="featureModal">
<div class="mf-modal">

<form id="featureForm" novalidate>

<input
    type="hidden"
    name="csrf_token"
    value="<?= mf_h($csrfToken) ?>"
>

<input
    type="hidden"
    name="action"
    value="save_feature"
>

<input
    type="hidden"
    name="id"
    id="featureId"
>

<div class="mf-modal-header">

    <div class="mf-modal-title-wrap">

        <span class="mf-modal-icon">
            <i class="bi bi-stars"></i>
        </span>

        <span>
            <h3
                class="mf-modal-title"
                id="featureModalTitle"
            >
                Add Module Feature
            </h3>

            <span class="mf-modal-subtitle">
                Define a capability that can be controlled by plan or tenant access
            </span>
        </span>

    </div>

    <button
        type="button"
        class="mf-modal-close"
        id="featureModalClose"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<div class="mf-modal-body">
<div class="mf-form-grid">

<div class="mf-field full">
    <label>
        Module
        <span class="mf-required">*</span>
    </label>

    <select
        class="mf-select"
        style="width:100%"
        name="module_id"
        id="featureModule"
        required
    >
        <option value="">Select Module</option>

        <?php foreach ($modules as $module): ?>
            <option value="<?= (int)$module['id'] ?>">
                <?= mf_h(
                    $module['parent_name']
                        ? $module['parent_name'] . ' / ' . $module['module_name']
                        : $module['module_name']
                ) ?>
            </option>
        <?php endforeach; ?>

    </select>
</div>

<div class="mf-field">
    <label>
        Feature Name
        <span class="mf-required">*</span>
    </label>

    <input
        class="mf-input"
        style="width:100%"
        name="feature_name"
        id="featureName"
        maxlength="150"
        required
        placeholder="Assign Worker"
    >
</div>

<div class="mf-field">
    <label>
        Feature Code
        <span class="mf-required">*</span>
    </label>

    <input
        class="mf-input"
        style="width:100%"
        name="feature_code"
        id="featureCode"
        maxlength="120"
        required
        placeholder="assign_worker"
    >
</div>

<div class="mf-field full">
    <label>Description</label>

    <textarea
        class="mf-textarea"
        name="description"
        id="featureDescription"
        maxlength="500"
        placeholder="Explain what this feature enables inside the selected module."
    ></textarea>
</div>

<div class="mf-field full">

    <label class="mf-toggle">

        <span>
            <strong>Active Feature</strong>
            <small>
                Active features can be assigned to plans and tenants.
            </small>
        </span>

        <span class="form-check form-switch m-0">
            <input
                class="form-check-input"
                type="checkbox"
                name="is_active"
                id="featureActive"
                value="1"
                checked
            >
        </span>

    </label>

</div>

</div>
</div>

<div class="mf-modal-footer">

    <button
        type="button"
        class="mf-secondary"
        id="featureCancel"
    >
        Cancel
    </button>

    <button
        type="submit"
        class="mf-primary"
        id="featureSaveBtn"
    >
        <span class="mf-loader"></span>
        <i class="bi bi-check2-circle"></i>
        <span id="featureSaveText">
            Save Feature
        </span>
    </button>

</div>

</form>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
(function(){
'use strict';

/* Approved persistent sidebar behavior */
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
            body.classList.contains('fp-sidebar-collapsed')
                ? '1'
                : '0'
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

/* Toast */
var toast=document.getElementById('mfToast');
var toastMessage=document.getElementById('mfToastMessage');
var toastIcon=document.getElementById('mfToastIcon');
var toastClose=document.getElementById('mfToastClose');
var toastTimer=null;

function showToast(type,message,duration){
    if(!toast){
        return;
    }

    if(toastTimer){
        clearTimeout(toastTimer);
    }

    var icons={
        success:'bi-check-lg',
        error:'bi-x-lg',
        warning:'bi-exclamation-lg',
        info:'bi-info-lg'
    };

    var t=type||'info';

    toast.className='mf-toast '+t;
    toastMessage.textContent=message||'Notification';
    toastIcon.className='bi '+(icons[t]||icons.info);
    toast.classList.add('show');

    toastTimer=setTimeout(function(){
        toast.classList.remove('show');
        toastTimer=null;
    },typeof duration==='number'?duration:3000);
}

if(toastClose){
    toastClose.addEventListener('click',function(){
        if(toastTimer){
            clearTimeout(toastTimer);
        }

        toast.classList.remove('show');
    });
}

/* Safe API response parser */
function apiRequest(formData){
    return fetch('api/module-features.php',{
        method:'POST',
        body:formData,
        credentials:'same-origin',
        headers:{
            'X-Requested-With':'XMLHttpRequest',
            'Accept':'application/json'
        }
    })
    .then(function(response){
        return response.text().then(function(rawText){
            var text=(rawText||'').trim();
            var data=null;

            try{
                data=text!==''?JSON.parse(text):{};
            }catch(e){
                var clean=text
                    .replace(/<br\s*\/?>/gi,' ')
                    .replace(/<[^>]*>/g,' ')
                    .replace(/\s+/g,' ')
                    .trim();

                throw new Error(
                    clean!==''
                        ? 'Server error: '+clean
                        : 'Server returned an invalid response.'
                );
            }

            return {
                ok:response.ok,
                status:response.status,
                data:data
            };
        });
    });
}

/* Modal */
var modal=document.getElementById('featureModal');
var form=document.getElementById('featureForm');
var saveBtn=document.getElementById('featureSaveBtn');
var saveText=document.getElementById('featureSaveText');

function closeFeatureModal(){
    modal.classList.remove('show');
}

function openFeatureModal(row){
    form.reset();

    document.getElementById('featureId').value='';
    document.getElementById('featureModalTitle').textContent='Add Module Feature';
    document.getElementById('featureActive').checked=true;
    saveText.textContent='Save Feature';

    if(row){
        document.getElementById('featureId').value=row.id||'';
        document.getElementById('featureModule').value=row.module_id||'';
        document.getElementById('featureName').value=row.feature_name||'';
        document.getElementById('featureCode').value=row.feature_code||'';
        document.getElementById('featureDescription').value=row.description||'';
        document.getElementById('featureActive').checked=String(row.is_active)==='1';

        document.getElementById('featureModalTitle').textContent='Edit Module Feature';
        saveText.textContent='Update Feature';
    }

    modal.classList.add('show');
}

document.getElementById('addFeatureBtn').addEventListener('click',function(){
    openFeatureModal(null);
});

document.getElementById('featureModalClose').addEventListener('click',closeFeatureModal);
document.getElementById('featureCancel').addEventListener('click',closeFeatureModal);

modal.addEventListener('click',function(e){
    if(e.target===modal){
        closeFeatureModal();
    }
});

document.querySelectorAll('.feature-edit').forEach(function(btn){
    btn.addEventListener('click',function(){
        try{
            openFeatureModal(
                JSON.parse(
                    btn.getAttribute('data-row')
                )
            );
        }catch(e){
            showToast(
                'error',
                'Unable to load feature details.',
                3000
            );
        }
    });
});

/* Auto-generate feature code for new feature */
var featureName=document.getElementById('featureName');
var featureCode=document.getElementById('featureCode');

featureName.addEventListener('input',function(){
    if(document.getElementById('featureId').value!==''){
        return;
    }

    featureCode.value=featureName.value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g,'_')
        .replace(/^_+|_+$/g,'');
});

/* Save */
form.addEventListener('submit',function(e){
    e.preventDefault();

    if(!form.checkValidity()){
        showToast(
            'warning',
            'Please complete the required feature fields.',
            3000
        );

        form.reportValidity();
        return;
    }

    saveBtn.disabled=true;
    saveBtn.classList.add('loading');
    saveText.textContent='Saving...';

    apiRequest(new FormData(form))
    .then(function(result){
        if(!result.ok||!result.data.success){
            throw new Error(
                result.data.message||
                'Unable to save module feature.'
            );
        }

        showToast(
            'success',
            result.data.message,
            3000
        );

        closeFeatureModal();

        setTimeout(function(){
            window.location.reload();
        },500);
    })
    .catch(function(error){
        showToast(
            'error',
            error.message||
            'Unable to save module feature.',
            3000
        );

        saveBtn.disabled=false;
        saveBtn.classList.remove('loading');

        saveText.textContent=
            document.getElementById('featureId').value!==''
                ? 'Update Feature'
                : 'Save Feature';
    });
});

/* Status */
document.querySelectorAll('.feature-status').forEach(function(btn){
    btn.addEventListener('click',function(){
        var fd=new FormData();

        fd.append(
            'csrf_token',
            '<?= mf_h($csrfToken) ?>'
        );

        fd.append(
            'action',
            'toggle_status'
        );

        fd.append(
            'id',
            btn.getAttribute('data-id')
        );

        fd.append(
            'is_active',
            btn.getAttribute('data-value')==='1'
                ? '0'
                : '1'
        );

        btn.disabled=true;

        apiRequest(fd)
        .then(function(result){
            if(!result.ok||!result.data.success){
                throw new Error(
                    result.data.message||
                    'Unable to update feature status.'
                );
            }

            showToast(
                'success',
                result.data.message,
                3000
            );

            setTimeout(function(){
                window.location.reload();
            },500);
        })
        .catch(function(error){
            showToast(
                'error',
                error.message||
                'Unable to update feature status.',
                3000
            );

            btn.disabled=false;
        });
    });
});

})();
</script>

</body>
</html>
