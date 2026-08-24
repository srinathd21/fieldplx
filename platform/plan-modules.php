<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Plan Modules';
$activePage = 'plan-modules';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['plan_modules_csrf'])) {
    $_SESSION['plan_modules_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['plan_modules_csrf'];

function pm_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

$selectedPlanId = isset($_GET['plan_id'])
    ? (int)$_GET['plan_id']
    : 0;

$search = isset($_GET['search'])
    ? trim((string)$_GET['search'])
    : '';

$status = isset($_GET['status'])
    ? trim((string)$_GET['status'])
    : '';

$plans = $pdo->query("
    SELECT
        id,
        name,
        code,
        status,
        is_featured
    FROM plans
    WHERE deleted_at IS NULL
    ORDER BY
        is_featured DESC,
        CASE status
            WHEN 'active' THEN 1
            WHEN 'draft' THEN 2
            WHEN 'inactive' THEN 3
            ELSE 4
        END,
        name
")->fetchAll();

if ($selectedPlanId <= 0 && !empty($plans)) {
    $selectedPlanId = (int)$plans[0]['id'];
}

$selectedPlan = null;

foreach ($plans as $plan) {
    if ((int)$plan['id'] === $selectedPlanId) {
        $selectedPlan = $plan;
        break;
    }
}

$moduleSql = "
    SELECT
        m.id,
        m.parent_id,
        m.module_code,
        m.module_name,
        m.description,
        m.menu_url,
        m.icon_name,
        m.menu_order,
        m.is_core,
        m.is_sidebar_item,
        m.is_active,
        p.module_name AS parent_name,
        p.menu_order AS parent_menu_order,
        COALESCE(pm.is_enabled, 0) AS plan_enabled
    FROM modules m
    LEFT JOIN modules p
        ON p.id = m.parent_id
    LEFT JOIN plan_modules pm
        ON pm.module_id = m.id
       AND pm.plan_id = :plan_id
    WHERE 1=1
";

$moduleParams = array(
    ':plan_id' => $selectedPlanId
);

if ($search !== '') {
    $moduleSql .= "
        AND (
            m.module_name LIKE :search
            OR m.module_code LIKE :search
            OR m.menu_url LIKE :search
            OR p.module_name LIKE :search
        )
    ";

    $moduleParams[':search'] = '%' . $search . '%';
}

if ($status === 'enabled') {
    $moduleSql .= " AND COALESCE(pm.is_enabled, 0) = 1 ";
} elseif ($status === 'disabled') {
    $moduleSql .= " AND COALESCE(pm.is_enabled, 0) = 0 ";
}

$moduleSql .= "
    ORDER BY
        COALESCE(p.menu_order, m.menu_order),
        COALESCE(m.parent_id, m.id),
        CASE WHEN m.parent_id IS NULL THEN 0 ELSE 1 END,
        m.menu_order,
        m.module_name
";

$moduleStmt = $pdo->prepare($moduleSql);
$moduleStmt->execute($moduleParams);
$modules = $moduleStmt->fetchAll();

$statsStmt = $pdo->prepare("
    SELECT
        COUNT(m.id) AS total_modules,
        SUM(
            CASE
                WHEN m.parent_id IS NULL THEN 1
                ELSE 0
            END
        ) AS parent_modules,
        SUM(
            CASE
                WHEN m.parent_id IS NOT NULL THEN 1
                ELSE 0
            END
        ) AS child_modules,
        SUM(
            CASE
                WHEN COALESCE(pm.is_enabled,0) = 1 THEN 1
                ELSE 0
            END
        ) AS enabled_modules,
        SUM(
            CASE
                WHEN COALESCE(pm.is_enabled,0) = 0 THEN 1
                ELSE 0
            END
        ) AS disabled_modules
    FROM modules m
    LEFT JOIN plan_modules pm
        ON pm.module_id = m.id
       AND pm.plan_id = :plan_id
");

$statsStmt->execute(
    array(
        ':plan_id' => $selectedPlanId
    )
);

$stats = $statsStmt->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title><?= pm_h($pageTitle) ?> - FieldPlx</title>

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

*{
    box-sizing:border-box
}

body{
    margin:0;
    min-height:100vh;
    overflow-x:hidden;
    background:#fff;
    color:var(--fp-text);
    font-family:"Inter",sans-serif;
    font-size:13px
}

a{
    text-decoration:none
}

button,
input,
select,
textarea{
    font-family:inherit
}

.fp-layout{
    min-height:100vh
}

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

.fp-notification-wrap{
    position:relative
}

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
    background:linear-gradient(
        135deg,
        #6d4df4,
        #9a5cff
    );
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

.fp-mobile-brand{
    display:none
}

.fp-content{
    padding:18px;
    background:#fff
}

/* Page */
.pm-page{
    display:grid;
    gap:16px
}

.pm-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px
}

.pm-title{
    margin:0;
    color:#111827;
    font-size:20px;
    font-weight:800
}

.pm-description{
    margin-top:4px;
    max-width:780px;
    color:#77718e;
    font-size:10px;
    line-height:1.55
}

.pm-header-actions{
    display:flex;
    align-items:center;
    gap:8px
}

.pm-primary,
.pm-secondary{
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

.pm-primary{
    border:0;
    background:linear-gradient(
        135deg,
        #7c3aed,
        #6d28d9
    );
    color:#fff;
    box-shadow:0 8px 20px rgba(109,40,217,.18)
}

.pm-secondary{
    border:1px solid #dcd5ef;
    background:#fff;
    color:#5f5870
}

.pm-primary:hover{
    background:linear-gradient(
        135deg,
        #6d28d9,
        #5b21b6
    )
}

.pm-secondary:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.pm-primary:disabled,
.pm-secondary:disabled{
    opacity:.65;
    cursor:not-allowed
}

/* Plan selector */
.pm-plan-card{
    padding:13px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    border:1px solid #ddd5f1;
    border-radius:13px;
    background:linear-gradient(
        180deg,
        #fff 0%,
        #fbf9ff 100%
    )
}

.pm-plan-info{
    display:flex;
    align-items:center;
    gap:10px
}

.pm-plan-icon{
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

.pm-plan-name{
    display:block;
    color:#111827;
    font-size:11px;
    font-weight:800
}

.pm-plan-code{
    margin-top:2px;
    display:block;
    color:#9a94ae;
    font-size:8px
}

.pm-plan-select{
    min-width:260px
}

/* Summary */
.pm-stats{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px
}

.pm-stat{
    min-height:90px;
    padding:14px 15px;
    display:flex;
    align-items:center;
    gap:11px;
    border:1px solid #ddd5f1;
    border-radius:13px;
    background:linear-gradient(
        180deg,
        #fff 0%,
        #fbf9ff 100%
    );
    box-shadow:none
}

.pm-stat:hover{
    border-color:#cfc3ef;
    background:linear-gradient(
        180deg,
        #fff 0%,
        #f8f4ff 100%
    )
}

.pm-stat-icon{
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

.pm-stat-content{
    min-width:0;
    display:block
}

.pm-stat-label{
    display:block;
    color:#9a94ae;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
    line-height:1.3
}

.pm-stat-value{
    margin-top:2px;
    display:block;
    color:#111827;
    font-size:20px;
    font-weight:800;
    line-height:1.2
}

.pm-stat-note{
    margin-top:2px;
    display:block;
    color:#9d96ac;
    font-size:7.5px;
    line-height:1.35
}

/* Table card */
.pm-card{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 24px rgba(37,29,80,.05)
}

.pm-toolbar{
    padding:13px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    background:#fbf9ff;
    border-bottom:1px solid #ece7f7
}

.pm-toolbar-left{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap
}

.pm-toolbar-right{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap
}

.pm-search{
    position:relative
}

.pm-search i{
    position:absolute;
    left:11px;
    top:50%;
    transform:translateY(-50%);
    color:#948da7;
    font-size:12px
}

.pm-input,
.pm-select{
    height:39px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    background:#fff;
    color:#312b47;
    font-size:10px;
    outline:0
}

.pm-input{
    padding:8px 11px
}

.pm-search .pm-input{
    width:260px;
    padding-left:33px
}

.pm-select{
    padding:8px 30px 8px 10px
}

.pm-input:focus,
.pm-select:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10)
}

.pm-table-wrap{
    overflow:auto;
    scrollbar-width:thin;
    scrollbar-color:#bcb4ca transparent
}

.pm-table-wrap::-webkit-scrollbar{
    width:4px;
    height:4px
}

.pm-table-wrap::-webkit-scrollbar-track{
    background:transparent
}

.pm-table-wrap::-webkit-scrollbar-thumb{
    background:#bcb4ca;
    border-radius:999px
}

.pm-table-wrap::-webkit-scrollbar-thumb:hover{
    background:#9f96b2
}

.pm-table{
    width:100%;
    min-width:1040px;
    border-collapse:collapse
}

.pm-table th{
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

.pm-table td{
    padding:10px 12px;
    border-bottom:1px solid #f0ecf7;
    color:#433d54;
    font-size:9px;
    vertical-align:middle
}

.pm-table tbody tr:hover{
    background:#fcfbff
}

.pm-parent-row td{
    background:#fff
}

.pm-child-row td{
    background:#fdfcff
}

.pm-child-row:hover td{
    background:#faf7ff
}

.pm-module-name{
    display:flex;
    align-items:center;
    gap:8px;
    min-width:0
}

.pm-row-icon{
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

.pm-child-row .pm-row-icon{
    width:27px;
    height:27px;
    flex:0 0 27px;
    border-radius:7px;
    background:#f5f1ff;
    color:#8b5cf6;
    font-size:11px
}

.pm-module-title{
    display:block;
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.pm-child-row .pm-module-title{
    color:#51495f;
    font-weight:700
}

.pm-module-code{
    display:block;
    margin-top:2px;
    color:#9b94a7;
    font-size:8px
}

.pm-submodule{
    position:relative;
    padding-left:30px
}

.pm-submodule:before{
    content:"";
    position:absolute;
    left:10px;
    top:-11px;
    bottom:50%;
    width:1px;
    background:#d7cfee
}

.pm-submodule:after{
    content:"";
    position:absolute;
    left:10px;
    top:50%;
    width:13px;
    height:1px;
    background:#d7cfee
}

.pm-parent-label,
.pm-child-label{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700
}

.pm-parent-label{
    background:#eef2ff;
    color:#4338ca
}

.pm-child-label{
    background:#f5f3ff;
    color:#7c3aed
}

.pm-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700
}

.pm-badge.active{
    background:#ecfdf5;
    color:#047857
}

.pm-badge.inactive{
    background:#f3f4f6;
    color:#6b7280
}

.pm-badge.sidebar{
    background:#f1ecff;
    color:#6d28d9
}

.pm-badge.hidden{
    background:#fff7ed;
    color:#c2410c
}

.pm-switch-cell{
    text-align:center
}

.pm-table th:nth-child(1),
.pm-table td:nth-child(1){
    width:58px;
    text-align:center
}

.pm-table th:nth-child(6),
.pm-table td:nth-child(6){
    width:100px;
    text-align:center
}

.pm-table th:nth-child(7),
.pm-table td:nth-child(7){
    width:110px;
    text-align:center
}

.pm-empty{
    padding:36px 15px;
    text-align:center;
    color:#928aa5;
    font-size:10px
}

/* Footer save bar */
.pm-savebar{
    padding:12px 13px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    border-top:1px solid #ece7f7;
    background:#fbf9ff
}

.pm-save-note{
    color:#8e879e;
    font-size:8.5px
}

/* Loader */
.pm-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:pmSpin .75s linear infinite
}

.pm-primary.loading .pm-loader{
    display:inline-block
}

@keyframes pmSpin{
    to{
        transform:rotate(360deg)
    }
}

/* Toast */
.pm-toast{
    position:fixed;
    top:82px;
    right:20px;
    z-index:20000;
    width:min(
        380px,
        calc(100vw - 24px)
    );
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
    transition:
        opacity .2s ease,
        transform .2s ease,
        visibility .2s ease;
    font-size:10px;
    line-height:1.45
}

.pm-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0)
}

.pm-toast.success{
    background:#059669
}

.pm-toast.error{
    background:#dc2626
}

.pm-toast.warning{
    background:#d97706
}

.pm-toast.info{
    background:#4f46e5
}

.pm-toast-icon{
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

.pm-toast-message{
    flex:1;
    min-width:0;
    font-weight:600
}

.pm-toast-close{
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

.pm-toast-close:hover{
    background:rgba(255,255,255,.12);
    opacity:1
}

/* Responsive */
@media(max-width:1100px){
    .pm-stats{
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
    .pm-header{
        flex-direction:column
    }

    .pm-header-actions{
        width:100%
    }

    .pm-header-actions .pm-primary,
    .pm-header-actions .pm-secondary{
        flex:1
    }

    .pm-plan-card{
        align-items:stretch
    }

    .pm-plan-select{
        width:100%;
        min-width:0
    }

    .pm-toolbar{
        align-items:stretch
    }

    .pm-toolbar-left,
    .pm-toolbar-right{
        width:100%
    }

    .pm-search{
        width:100%
    }

    .pm-search .pm-input{
        width:100%
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

    .pm-stats{
        grid-template-columns:1fr
    }

    .pm-stat{
        min-height:82px
    }

    .pm-savebar{
        align-items:stretch
    }

    .pm-savebar .pm-primary,
    .pm-savebar .pm-secondary{
        width:100%
    }

    .pm-toast{
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

<div class="pm-page">

<div class="pm-header">

    <div>

        <h2 class="pm-title">
            Plan Modules
        </h2>

        <div class="pm-description">
            Configure which sidebar modules are available
            for each FieldPlx subscription plan.
            Parent modules and child modules are displayed
            in their actual sidebar hierarchy.
        </div>

    </div>

</div>

<div class="pm-plan-card">

    <div class="pm-plan-info">

        <span class="pm-plan-icon">
            <i class="bi bi-box"></i>
        </span>

        <span>

            <span class="pm-plan-name">
                <?= $selectedPlan
                    ? pm_h($selectedPlan['name'])
                    : 'No Plan Selected' ?>
            </span>

            <span class="pm-plan-code">
                <?= $selectedPlan
                    ? pm_h($selectedPlan['code'])
                    : 'Select a plan to configure modules' ?>
            </span>

        </span>

    </div>

    <form
        method="get"
        id="planSelectorForm"
    >

        <select
            class="pm-select pm-plan-select"
            name="plan_id"
            onchange="this.form.submit()"
        >

            <?php if (!$plans): ?>

                <option value="">
                    No plans available
                </option>

            <?php else: ?>

                <?php foreach ($plans as $plan): ?>

                    <option
                        value="<?= (int)$plan['id'] ?>"
                        <?= (int)$plan['id'] === $selectedPlanId
                            ? 'selected'
                            : '' ?>
                    >
                        <?= pm_h($plan['name']) ?>
                        (<?= pm_h($plan['code']) ?>)
                    </option>

                <?php endforeach; ?>

            <?php endif; ?>

        </select>

    </form>

</div>

<div class="pm-stats">

<?php

$cards = array(
    array(
        'Total Modules',
        'bi bi-grid',
        (int)($stats['total_modules'] ?? 0),
        'All available modules'
    ),
    array(
        'Parent Modules',
        'bi bi-folder2-open',
        (int)($stats['parent_modules'] ?? 0),
        'Top-level sidebar groups'
    ),
    array(
        'Sub Modules',
        'bi bi-diagram-3',
        (int)($stats['child_modules'] ?? 0),
        'Child navigation items'
    ),
    array(
        'Enabled',
        'bi bi-check-circle',
        (int)($stats['enabled_modules'] ?? 0),
        'Allowed for this plan'
    ),
    array(
        'Disabled',
        'bi bi-slash-circle',
        (int)($stats['disabled_modules'] ?? 0),
        'Not available in this plan'
    )
);

foreach ($cards as $card):

?>

<div class="pm-stat">

    <span class="pm-stat-icon">
        <i class="<?= pm_h($card[1]) ?>"></i>
    </span>

    <span class="pm-stat-content">

        <span class="pm-stat-label">
            <?= pm_h($card[0]) ?>
        </span>

        <span class="pm-stat-value">
            <?= number_format($card[2]) ?>
        </span>

        <span class="pm-stat-note">
            <?= pm_h($card[3]) ?>
        </span>

    </span>

</div>

<?php endforeach; ?>

</div>

<section class="pm-card">

<form
    method="get"
    class="pm-toolbar"
>

<input
    type="hidden"
    name="plan_id"
    value="<?= (int)$selectedPlanId ?>"
>

<div class="pm-toolbar-left">

    <div class="pm-search">

        <i class="bi bi-search"></i>

        <input
            type="text"
            class="pm-input"
            name="search"
            value="<?= pm_h($search) ?>"
            placeholder="Search module, code or URL"
        >

    </div>

    <select
        class="pm-select"
        name="status"
        onchange="this.form.submit()"
    >

        <option value="">
            All Access
        </option>

        <option
            value="enabled"
            <?= $status === 'enabled'
                ? 'selected'
                : '' ?>
        >
            Enabled
        </option>

        <option
            value="disabled"
            <?= $status === 'disabled'
                ? 'selected'
                : '' ?>
        >
            Disabled
        </option>

    </select>

    <?php
    if (
        $search !== '' ||
        $status !== ''
    ):
    ?>

    <a
        class="pm-secondary"
        href="plan-modules.php?plan_id=<?= (int)$selectedPlanId ?>"
    >
        <i class="bi bi-x-lg"></i>
        Clear
    </a>

    <?php endif; ?>

</div>

<button
    type="submit"
    class="pm-secondary"
>
    <i class="bi bi-funnel"></i>
    Filter
</button>

</form>

<form
    id="planModulesForm"
    novalidate
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= pm_h($csrfToken) ?>"
>

<input
    type="hidden"
    name="action"
    value="save_plan_modules"
>

<input
    type="hidden"
    name="plan_id"
    value="<?= (int)$selectedPlanId ?>"
>

<div class="pm-toolbar">

    <div class="pm-toolbar-left">

        <button
            type="button"
            class="pm-secondary"
            id="selectAllBtn"
        >
            <i class="bi bi-check2-square"></i>
            Select All
        </button>

        <button
            type="button"
            class="pm-secondary"
            id="clearAllBtn"
        >
            <i class="bi bi-square"></i>
            Clear All
        </button>

    </div>

    <div class="pm-toolbar-right">

        <span class="pm-save-note">
            Enabling a child automatically enables its parent.
            Disabling a parent disables all its children.
        </span>

    </div>

</div>

<div class="pm-table-wrap">

<table class="pm-table">

<thead>

<tr>
    <th>S/No</th>
    <th>Module</th>
    <th>Parent</th>
    <th>Menu URL</th>
    <th>Sidebar</th>
    <th>Module Status</th>
    <th>Plan Access</th>
</tr>

</thead>

<tbody>

<?php if (!$modules): ?>

<tr>

    <td colspan="7">

        <div class="pm-empty">
            No modules found.
        </div>

    </td>

</tr>

<?php else: ?>

<?php
foreach (
    $modules as $index => $module
):
?>

<tr
    class="<?= $module['parent_id']
        ? 'pm-child-row'
        : 'pm-parent-row' ?>"
>

    <td>
        <?= $index + 1 ?>
    </td>

    <td>

        <div
            class="<?= $module['parent_id']
                ? 'pm-submodule'
                : '' ?>"
        >

            <div class="pm-module-name">

                <span class="pm-row-icon">

                    <i
                        class="<?= pm_h(
                            $module['icon_name']
                                ?: (
                                    $module['parent_id']
                                        ? 'bi bi-dot'
                                        : 'bi bi-grid'
                                )
                        ) ?>"
                    ></i>

                </span>

                <span>

                    <span class="pm-module-title">
                        <?= pm_h($module['module_name']) ?>
                    </span>

                    <span class="pm-module-code">

                        <?= $module['parent_id']
                            ? 'Child · '
                            : 'Parent · ' ?>

                        <?= pm_h($module['module_code']) ?>

                    </span>

                </span>

            </div>

        </div>

    </td>

    <td>

        <?php if ($module['parent_id']): ?>

            <span class="pm-child-label">

                <i class="bi bi-arrow-return-right"></i>

                <?= pm_h($module['parent_name']) ?>

            </span>

        <?php else: ?>

            <span class="pm-parent-label">

                <i class="bi bi-folder2-open"></i>

                Parent

            </span>

        <?php endif; ?>

    </td>

    <td>
        <?= pm_h(
            $module['menu_url'] ?: '-'
        ) ?>
    </td>

    <td>

        <span
            class="pm-badge <?= (int)$module['is_sidebar_item'] === 1
                ? 'sidebar'
                : 'hidden' ?>"
        >

            <?= (int)$module['is_sidebar_item'] === 1
                ? 'Visible'
                : 'Hidden' ?>

        </span>

    </td>

    <td>

        <span
            class="pm-badge <?= (int)$module['is_active'] === 1
                ? 'active'
                : 'inactive' ?>"
        >

            <?= (int)$module['is_active'] === 1
                ? 'Active'
                : 'Inactive' ?>

        </span>

    </td>

    <td class="pm-switch-cell">

        <span class="form-check form-switch m-0 d-inline-flex">

            <input
                class="form-check-input pm-module-toggle <?= $module['parent_id']
                    ? 'pm-child-toggle'
                    : 'pm-parent-toggle' ?>"
                type="checkbox"
                name="module_ids[]"
                value="<?= (int)$module['id'] ?>"
                data-module-id="<?= (int)$module['id'] ?>"
                data-parent-id="<?= (int)($module['parent_id'] ?: 0) ?>"
                <?= (int)$module['plan_enabled'] === 1
                    ? 'checked'
                    : '' ?>
                <?= (int)$module['is_active'] !== 1
                    ? 'disabled'
                    : '' ?>
            >

        </span>

    </td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

<div class="pm-savebar">

    <div class="pm-save-note">

        Changes affect new sidebar access for tenants
        subscribed to this plan. Tenant/role permissions
        can further restrict access but cannot bypass
        plan entitlement.

    </div>

    <button
        type="submit"
        class="pm-primary"
        id="savePlanModulesBtn"
        <?= $selectedPlanId <= 0
            ? 'disabled'
            : '' ?>
    >

        <span class="pm-loader"></span>

        <i class="bi bi-check2-circle"></i>

        <span id="savePlanModulesText">
            Save Plan Modules
        </span>

    </button>

</div>

</form>

</section>

</div>

</div>

</main>

</div>

<div
    id="pmToast"
    class="pm-toast"
>

    <span class="pm-toast-icon">

        <i
            id="pmToastIcon"
            class="bi bi-check-lg"
        ></i>

    </span>

    <span
        id="pmToastMessage"
        class="pm-toast-message"
    ></span>

    <button
        type="button"
        id="pmToastClose"
        class="pm-toast-close"
    >

        <i class="bi bi-x-lg"></i>

    </button>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
'use strict';

/* Sidebar */
var body=document.body;
var toggle=document.getElementById('fpSidebarToggle');
var closeBtn=document.getElementById('fpSidebarClose');
var overlay=document.getElementById('fpSidebarOverlay');
var storageKey='fieldplx_sidebar_collapsed';

function restoreSidebar(){

    if(window.innerWidth<992){

        body.classList.remove(
            'fp-sidebar-collapsed'
        );

        return;
    }

    if(
        localStorage.getItem(
            storageKey
        )==='1'
    ){

        body.classList.add(
            'fp-sidebar-collapsed'
        );

    }else{

        body.classList.remove(
            'fp-sidebar-collapsed'
        );

    }
}

restoreSidebar();

if(toggle){

    toggle.addEventListener(
        'click',
        function(){

            if(window.innerWidth<992){

                body.classList.toggle(
                    'fp-sidebar-mobile-open'
                );

                return;
            }

            body.classList.toggle(
                'fp-sidebar-collapsed'
            );

            localStorage.setItem(
                storageKey,
                body.classList.contains(
                    'fp-sidebar-collapsed'
                )
                    ? '1'
                    : '0'
            );
        }
    );
}

if(closeBtn){

    closeBtn.addEventListener(
        'click',
        function(){

            body.classList.remove(
                'fp-sidebar-mobile-open'
            );

        }
    );
}

if(overlay){

    overlay.addEventListener(
        'click',
        function(){

            body.classList.remove(
                'fp-sidebar-mobile-open'
            );

        }
    );
}

document
.querySelectorAll(
    '.fp-sidebar-menu-toggle'
)
.forEach(
    function(btn){

        btn.addEventListener(
            'click',
            function(){

                var menu=
                    btn.closest(
                        '.fp-sidebar-menu'
                    );

                if(menu){

                    menu.classList.toggle(
                        'open'
                    );

                }
            }
        );

    }
);

/* Toast */
var toast=document.getElementById('pmToast');
var toastMessage=document.getElementById('pmToastMessage');
var toastIcon=document.getElementById('pmToastIcon');
var toastClose=document.getElementById('pmToastClose');
var toastTimer=null;

function showToast(
    type,
    message,
    duration
){

    if(toastTimer){

        clearTimeout(
            toastTimer
        );

    }

    var icons={
        success:'bi-check-lg',
        error:'bi-x-lg',
        warning:'bi-exclamation-lg',
        info:'bi-info-lg'
    };

    var t=
        type||'info';

    toast.className=
        'pm-toast '+t;

    toastMessage.textContent=
        message||'Notification';

    toastIcon.className=
        'bi '+
        (
            icons[t]||
            icons.info
        );

    toast.classList.add(
        'show'
    );

    toastTimer=
        setTimeout(
            function(){

                toast.classList.remove(
                    'show'
                );

                toastTimer=null;

            },
            typeof duration==='number'
                ? duration
                : 3000
        );
}

toastClose.addEventListener(
    'click',
    function(){

        if(toastTimer){

            clearTimeout(
                toastTimer
            );

        }

        toast.classList.remove(
            'show'
        );

    }
);

/* Safe API */
function apiRequest(
    formData
){

    return fetch(
        'api/plan-modules.php',
        {
            method:'POST',
            body:formData,
            credentials:'same-origin',
            headers:{
                'X-Requested-With':
                    'XMLHttpRequest',
                'Accept':
                    'application/json'
            }
        }
    )
    .then(
        function(response){

            return response
            .text()
            .then(
                function(rawText){

                    var text=
                        (
                            rawText||
                            ''
                        ).trim();

                    var data=null;

                    try{

                        data=
                            text!==''
                                ? JSON.parse(
                                    text
                                )
                                : {};

                    }catch(e){

                        var clean=
                            text
                            .replace(
                                /<br\s*\/?>/gi,
                                ' '
                            )
                            .replace(
                                /<[^>]*>/g,
                                ' '
                            )
                            .replace(
                                /\s+/g,
                                ' '
                            )
                            .trim();

                        throw new Error(
                            clean!==''
                                ? 'Server error: '+
                                    clean
                                : 'Server returned an invalid response.'
                        );
                    }

                    return {
                        ok:
                            response.ok,
                        status:
                            response.status,
                        data:
                            data
                    };
                }
            );
        }
    );
}

/* Parent/child access rules */
var toggles=
    document.querySelectorAll(
        '.pm-module-toggle'
    );

function findParentToggle(
    parentId
){

    return document.querySelector(
        '.pm-parent-toggle[data-module-id="'+
        parentId+
        '"]'
    );
}

function childToggles(
    parentId
){

    return document.querySelectorAll(
        '.pm-child-toggle[data-parent-id="'+
        parentId+
        '"]'
    );
}

toggles.forEach(
    function(toggleInput){

        toggleInput.addEventListener(
            'change',
            function(){

                var moduleId=
                    toggleInput.getAttribute(
                        'data-module-id'
                    );

                var parentId=
                    toggleInput.getAttribute(
                        'data-parent-id'
                    );

                if(
                    toggleInput.classList.contains(
                        'pm-parent-toggle'
                    )
                ){

                    if(!toggleInput.checked){

                        childToggles(
                            moduleId
                        )
                        .forEach(
                            function(child){

                                if(!child.disabled){

                                    child.checked=false;

                                }
                            }
                        );

                    }

                    return;
                }

                if(
                    toggleInput.checked &&
                    parentId &&
                    parentId!=='0'
                ){

                    var parent=
                        findParentToggle(
                            parentId
                        );

                    if(
                        parent &&
                        !parent.disabled
                    ){

                        parent.checked=true;

                    }
                }
            }
        );

    }
);

/* Select all */
document
.getElementById(
    'selectAllBtn'
)
.addEventListener(
    'click',
    function(){

        toggles.forEach(
            function(input){

                if(!input.disabled){

                    input.checked=true;

                }
            }
        );

    }
);

/* Clear all */
document
.getElementById(
    'clearAllBtn'
)
.addEventListener(
    'click',
    function(){

        toggles.forEach(
            function(input){

                if(!input.disabled){

                    input.checked=false;

                }
            }
        );

    }
);

/* Save */
var form=
    document.getElementById(
        'planModulesForm'
    );

var saveBtn=
    document.getElementById(
        'savePlanModulesBtn'
    );

var saveText=
    document.getElementById(
        'savePlanModulesText'
    );

form.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        if(
            <?= (int)$selectedPlanId ?> <= 0
        ){

            showToast(
                'warning',
                'Please select a plan.',
                3000
            );

            return;
        }

        saveBtn.disabled=true;

        saveBtn.classList.add(
            'loading'
        );

        saveText.textContent=
            'Saving...';

        apiRequest(
            new FormData(
                form
            )
        )
        .then(
            function(result){

                if(
                    !result.ok ||
                    !result.data.success
                ){

                    throw new Error(
                        result.data.message ||
                        'Unable to save plan modules.'
                    );
                }

                showToast(
                    'success',
                    result.data.message,
                    3000
                );

                setTimeout(
                    function(){

                        window.location.reload();

                    },
                    500
                );
            }
        )
        .catch(
            function(error){

                showToast(
                    'error',
                    error.message ||
                    'Unable to save plan modules.',
                    3000
                );

                saveBtn.disabled=false;

                saveBtn.classList.remove(
                    'loading'
                );

                saveText.textContent=
                    'Save Plan Modules';

            }
        );

    }
);

})();
</script>

</body>
</html>
