<?php
/**
 * FieldPlx - Country & Currency Master
 * PHP 7.2+
 */

require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Country & Currency Master';
$activePage = 'global-masters';

if (empty($_SESSION['country_currency_master_csrf'])) {
    $_SESSION['country_currency_master_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['country_currency_master_csrf'];

function gm_h($value)
{
    return htmlspecialchars(
        (string) ($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| PAGE DATA
|--------------------------------------------------------------------------
*/

$activeTab = isset($_GET['tab'])
    ? (string) $_GET['tab']
    : 'countries';

if (!in_array($activeTab, array('countries', 'currencies'), true)) {
    $activeTab = 'countries';
}

$search = trim(
    isset($_GET['search'])
        ? (string) $_GET['search']
        : ''
);

$status = isset($_GET['status'])
    ? (string) $_GET['status']
    : '';

$page = max(
    1,
    (int) (
        isset($_GET['page'])
            ? $_GET['page']
            : 1
    )
);

$perPage = 10;
$offset = ($page - 1) * $perPage;

$listRows = array();
$totalRows = 0;
$totalPages = 1;

if ($activeTab === 'countries') {
    $where = array();
    $params = array();

    if ($search !== '') {
        $where[] = "
            (
                name LIKE :search
                OR iso2 LIKE :search
                OR iso3 LIKE :search
                OR phone_code LIKE :search
                OR default_currency_code LIKE :search
            )
        ";

        $params[':search'] = '%' . $search . '%';
    }

    if ($status === 'active') {
        $where[] = "is_active = 1";
    } elseif ($status === 'inactive') {
        $where[] = "is_active = 0";
    }

    $whereSql = $where
        ? ' WHERE ' . implode(' AND ', $where)
        : '';

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM countries" . $whereSql
    );

    $countStmt->execute($params);

    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(
        1,
        (int) ceil($totalRows / $perPage)
    );

    $listSql = "
        SELECT
            id,
            name,
            iso2,
            iso3,
            phone_code,
            default_currency_code,
            default_timezone,
            date_format,
            number_format,
            tax_label,
            is_active
        FROM countries
        " . $whereSql . "
        ORDER BY name ASC
        LIMIT " . (int) $perPage . "
        OFFSET " . (int) $offset;

    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $listRows = $listStmt->fetchAll();

} else {
    $where = array();
    $params = array();

    if ($search !== '') {
        $where[] = "
            (
                currency_code LIKE :search
                OR currency_name LIKE :search
                OR symbol LIKE :search
            )
        ";

        $params[':search'] = '%' . $search . '%';
    }

    if ($status === 'active') {
        $where[] = "is_active = 1";
    } elseif ($status === 'inactive') {
        $where[] = "is_active = 0";
    }

    $whereSql = $where
        ? ' WHERE ' . implode(' AND ', $where)
        : '';

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM currencies" . $whereSql
    );

    $countStmt->execute($params);

    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(
        1,
        (int) ceil($totalRows / $perPage)
    );

    $listSql = "
        SELECT
            id,
            currency_code,
            currency_name,
            symbol,
            symbol_position,
            decimal_places,
            decimal_separator,
            thousand_separator,
            is_active
        FROM currencies
        " . $whereSql . "
        ORDER BY currency_code ASC
        LIMIT " . (int) $perPage . "
        OFFSET " . (int) $offset;

    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $listRows = $listStmt->fetchAll();
}

$currencyRows = $pdo->query("
    SELECT
        currency_code,
        currency_name,
        symbol
    FROM currencies
    WHERE is_active = 1
    ORDER BY currency_code ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title>
    <?= gm_h($pageTitle); ?> - FieldPlx
</title>

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
    --fp-success:#059669;
    --fp-warning:#d97706;
    --fp-info:#4f46e5;
    --fp-sidebar-width:260px;
    --fp-sidebar-collapsed-width:76px;
    --fp-topbar-height:66px;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    min-height:100vh;
    overflow-x:hidden;
    background:#ffffff;
    color:var(--fp-text);
    font-family:"Inter",sans-serif;
    font-size:13px;
}

a{
    text-decoration:none;
}

button,
input,
select,
textarea{
    font-family:inherit;
}

.fp-layout{
    min-height:100vh;
}

.fp-main{
    min-height:calc(100vh - 52px);
    margin-left:var(--fp-sidebar-width);
    transition:margin-left .22s ease;
}

body.fp-sidebar-collapsed .fp-main{
    margin-left:var(--fp-sidebar-collapsed-width);
}

.fp-topbar{
    position:sticky;
    top:0;
    z-index:1030;
    min-height:var(--fp-topbar-height);
    border-bottom:1px solid #ded8f3;
    background:rgba(248,246,255,.96);
    backdrop-filter:blur(14px);
}

.fp-topbar-inner{
    min-height:var(--fp-topbar-height);
    padding:8px 18px;
    display:flex;
    align-items:center;
    gap:13px;
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
    background:#ffffff;
    color:#39345f;
    font-size:18px;
}

.fp-menu-toggle:hover,
.fp-icon-button:hover{
    border-color:#bda9ff;
    background:#f4f0ff;
    color:var(--fp-accent-dark);
}

.fp-page-heading{
    min-width:0;
    margin-right:auto;
}

.fp-page-title{
    margin:0;
    color:#17172e;
    font-size:15px;
    font-weight:700;
    line-height:1.25;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.fp-page-subtitle{
    margin-top:2px;
    color:var(--fp-muted);
    font-size:10px;
}

.fp-search{
    width:min(340px,31vw);
    position:relative;
    flex:0 1 340px;
}

.fp-search i{
    position:absolute;
    left:12px;
    top:50%;
    transform:translateY(-50%);
    color:#8f88aa;
    font-size:14px;
    pointer-events:none;
}

.fp-search input{
    width:100%;
    height:39px;
    padding:8px 13px 8px 36px;
    border:1px solid #dcd5ef;
    border-radius:10px;
    outline:0;
    background:#f8f6ff;
    font-size:12px;
}

.fp-search input:focus{
    border-color:#a78bfa;
    background:#ffffff;
    box-shadow:0 0 0 3px rgba(139,92,246,.12);
}

.fp-notification-wrap{
    position:relative;
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
    border:2px solid #ffffff;
    border-radius:999px;
    background:var(--fp-danger);
    color:#ffffff;
    font-size:9px;
    font-weight:700;
}

.fp-profile{
    min-width:0;
    padding:4px 9px 4px 5px;
    display:flex;
    align-items:center;
    gap:9px;
    border:1px solid var(--fp-border);
    border-radius:11px;
    background:#ffffff;
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
    color:#ffffff;
    font-size:10px;
    font-weight:700;
}

.fp-profile-text{
    max-width:145px;
    min-width:0;
}

.fp-profile-name,
.fp-profile-role{
    display:block;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
}

.fp-profile-name{
    color:#111827;
    font-size:11px;
    font-weight:700;
}

.fp-profile-role{
    margin-top:1px;
    color:var(--fp-muted);
    font-size:9px;
}

.fp-mobile-brand{
    display:none;
}

.fp-content{
    padding:18px;
    background:#ffffff;
}

.gm-page{
    display:grid;
    gap:16px;
}

.gm-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px;
}

.gm-title{
    margin:0;
    color:#111827;
    font-size:20px;
    font-weight:800;
}

.gm-description{
    margin-top:4px;
    color:#77718e;
    font-size:10px;
    line-height:1.55;
}

.gm-tabs{
    display:flex;
    align-items:center;
    gap:8px;
    padding:5px;
    width:max-content;
    border:1px solid #ded7ef;
    border-radius:11px;
    background:#f8f6ff;
}

.gm-tab{
    min-height:34px;
    padding:7px 12px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border-radius:8px;
    color:#706880;
    font-size:9px;
    font-weight:700;
}

.gm-tab.active{
    background:#ffffff;
    color:#6d28d9;
    box-shadow:0 3px 9px rgba(64,46,120,.08);
}

.gm-primary{
    min-height:38px;
    padding:8px 13px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:0;
    border-radius:9px;
    background:linear-gradient(135deg,#7c3aed,#6d28d9);
    color:#ffffff;
    box-shadow:0 8px 20px rgba(109,40,217,.18);
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}

.gm-primary:disabled{
    opacity:.65;
    cursor:not-allowed;
}

.gm-card{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:14px;
    background:#ffffff;
    box-shadow:0 8px 24px rgba(37,29,80,.05);
}

.gm-card-header{
    padding:12px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff;
}

.gm-card-title-wrap{
    display:flex;
    align-items:center;
    gap:10px;
}

.gm-card-icon{
    width:34px;
    height:34px;
    flex:0 0 34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:9px;
    background:#eee8ff;
    color:#7c3aed;
    font-size:14px;
}

.gm-card-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800;
}

.gm-card-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px;
}

.gm-tools{
    padding:13px 15px;
    display:grid;
    grid-template-columns:minmax(220px,1fr) 170px auto;
    gap:10px;
    border-bottom:1px solid #eee9f7;
}

.gm-input,
.gm-select{
    width:100%;
    height:39px;
    padding:8px 11px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    outline:0;
    background:#ffffff;
    color:#312b47;
    font-size:10px;
}

.gm-input:focus,
.gm-select:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10);
}

.gm-search{
    position:relative;
}

.gm-search i{
    position:absolute;
    left:12px;
    top:50%;
    transform:translateY(-50%);
    color:#918aa2;
    font-size:13px;
}

.gm-search .gm-input{
    padding-left:34px;
}

.gm-secondary{
    min-height:39px;
    padding:8px 12px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    background:#ffffff;
    color:#5f5870;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}

.gm-table-wrap{
    overflow:auto;
}

.gm-table{
    width:100%;
    border-collapse:collapse;
    min-width:950px;
}

.gm-table th{
    padding:10px 12px;
    border-bottom:1px solid #e8e2f2;
    background:#f8f6ff;
    color:#726a86;
    text-align:left;
    font-size:8px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.04em;
    white-space:nowrap;
}

.gm-table td{
    padding:11px 12px;
    border-bottom:1px solid #f0ecf7;
    color:#433d54;
    font-size:9px;
    vertical-align:middle;
}

.gm-table tbody tr:hover{
    background:#fcfbff;
}

.gm-empty{
    padding:38px 15px;
    text-align:center;
    color:#928aa5;
    font-size:10px;
}

.gm-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 8px;
    border-radius:999px;
    font-size:8px;
    font-weight:700;
}

.gm-badge.active{
    background:#ecfdf5;
    color:#047857;
}

.gm-badge.inactive{
    background:#f3f4f6;
    color:#6b7280;
}

.gm-actions{
    display:flex;
    align-items:center;
    gap:6px;
}

.gm-icon-btn{
    width:30px;
    height:30px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#ffffff;
    color:#655d78;
    font-size:12px;
    cursor:pointer;
}

.gm-icon-btn:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9;
}

.gm-icon-btn.danger:hover{
    border-color:#fecaca;
    background:#fef2f2;
    color:#dc2626;
}

.gm-footer{
    padding:11px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    background:#fbf9ff;
}

.gm-count{
    color:#8f879f;
    font-size:9px;
}

.gm-pagination{
    display:flex;
    align-items:center;
    gap:5px;
}

.gm-page-btn{
    min-width:30px;
    height:30px;
    padding:0 9px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#ffffff;
    color:#615970;
    font-size:9px;
}

.gm-page-btn.active{
    border-color:#8b5cf6;
    background:#8b5cf6;
    color:#ffffff;
}

.gm-page-btn.disabled{
    opacity:.45;
    pointer-events:none;
}

.gm-modal-backdrop{
    position:fixed;
    inset:0;
    z-index:15000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px;
    background:rgba(18,24,45,.42);
    backdrop-filter:blur(3px);
}

.gm-modal-backdrop.show{
    display:flex;
}

.gm-modal{
    width:min(640px,100%);
    max-height:calc(100vh - 36px);
    overflow:auto;
    border:1px solid #ded7ef;
    border-radius:15px;
    background:#ffffff;
    box-shadow:0 24px 60px rgba(28,20,70,.22);
}

.gm-modal-header{
    padding:13px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff;
}

.gm-modal-title-wrap{
    display:flex;
    align-items:center;
    gap:10px;
}

.gm-modal-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800;
}

.gm-modal-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px;
}

.gm-modal-close{
    width:30px;
    height:30px;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#ffffff;
    color:#6d657d;
    cursor:pointer;
}

.gm-modal-body{
    padding:15px;
}

.gm-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:13px;
}

.gm-field.full{
    grid-column:1/-1;
}

.gm-field label{
    margin-bottom:6px;
    display:block;
    color:#4c465f;
    font-size:9px;
    font-weight:700;
}

.gm-required{
    color:#dc2626;
}

.gm-modal-footer{
    padding:12px 15px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid #ece7f7;
    background:#fbf9ff;
}

.gm-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:gmSpin .75s linear infinite;
}

.gm-primary.loading .gm-loader{
    display:inline-block;
}

@keyframes gmSpin{
    to{
        transform:rotate(360deg);
    }
}

.gm-toast{
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
    color:#ffffff;
    box-shadow:0 16px 34px rgba(16,24,40,.18);
    opacity:0;
    visibility:hidden;
    transform:translateY(-10px);
    transition:opacity .2s ease,transform .2s ease,visibility .2s ease;
    font-size:10px;
    line-height:1.45;
}

.gm-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0);
}

.gm-toast.success{
    background:#059669;
}

.gm-toast.error{
    background:#dc2626;
}

.gm-toast.warning{
    background:#d97706;
}

.gm-toast.info{
    background:#4f46e5;
}

.gm-toast-icon{
    width:24px;
    height:24px;
    flex:0 0 24px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    background:rgba(255,255,255,.18);
    font-size:12px;
}

.gm-toast-message{
    flex:1;
    min-width:0;
    font-weight:600;
}

.gm-toast-close{
    width:24px;
    height:24px;
    padding:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:0;
    border-radius:7px;
    background:transparent;
    color:#ffffff;
    font-size:15px;
    cursor:pointer;
    opacity:.82;
}

.gm-toast-close:hover{
    background:rgba(255,255,255,.12);
    opacity:1;
}

@media(max-width:991.98px){
    .fp-main,
    body.fp-sidebar-collapsed .fp-main{
        margin-left:0;
    }

    .fp-search,
    .fp-profile-text{
        display:none;
    }

    .fp-mobile-brand{
        display:inline-flex;
    }
}

@media(max-width:700px){
    .gm-header{
        flex-direction:column;
    }

    .gm-tabs{
        width:100%;
    }

    .gm-tab{
        flex:1;
    }

    .gm-primary{
        width:100%;
    }

    .gm-tools{
        grid-template-columns:1fr;
    }

    .gm-form-grid{
        grid-template-columns:1fr;
    }

    .gm-field.full{
        grid-column:auto;
    }
}

@media(max-width:575.98px){
    .fp-topbar-inner{
        padding:8px 11px;
    }

    .fp-page-subtitle{
        display:none;
    }

    .fp-page-title{
        font-size:13px;
    }

    .fp-content{
        padding:12px;
    }

    .gm-footer{
        align-items:flex-start;
        flex-direction:column;
    }

    .gm-toast{
        top:74px;
        right:12px;
        left:12px;
        width:auto;
    }

    .gm-modal-footer{
        flex-direction:column-reverse;
    }

    .gm-modal-footer .gm-secondary,
    .gm-modal-footer .gm-primary{
        width:100%;
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

<div class="gm-page">

<div class="gm-header">
    <div>
        <h2 class="gm-title">
            Country & Currency Master
        </h2>

        <div class="gm-description">
            Manage countries, currencies and localization defaults used across FieldPlx.
        </div>
    </div>

    <button
        type="button"
        class="gm-primary"
        id="gmAddButton"
    >
        <i class="bi bi-plus-lg"></i>

        <span>
            <?= $activeTab === 'countries'
                ? 'Add Country'
                : 'Add Currency'; ?>
        </span>
    </button>
</div>

<div class="gm-tabs">
    <a
        href="?tab=countries"
        class="gm-tab <?= $activeTab === 'countries' ? 'active' : ''; ?>"
    >
        <i class="bi bi-globe2"></i>
        Countries
    </a>

    <a
        href="?tab=currencies"
        class="gm-tab <?= $activeTab === 'currencies' ? 'active' : ''; ?>"
    >
        <i class="bi bi-currency-exchange"></i>
        Currencies
    </a>
</div>

<section class="gm-card">

<div class="gm-card-header">
    <div class="gm-card-title-wrap">
        <span class="gm-card-icon">
            <i class="bi <?= $activeTab === 'countries'
                ? 'bi-globe2'
                : 'bi-currency-exchange'; ?>"></i>
        </span>

        <span>
            <h3 class="gm-card-title">
                <?= $activeTab === 'countries'
                    ? 'Countries'
                    : 'Currencies'; ?>
            </h3>

            <span class="gm-card-subtitle">
                <?= $activeTab === 'countries'
                    ? 'Global country and localization master'
                    : 'Global currency and number-format master'; ?>
            </span>
        </span>
    </div>
</div>

<form
    class="gm-tools"
    method="get"
>
    <input
        type="hidden"
        name="tab"
        value="<?= gm_h($activeTab); ?>"
    >

    <div class="gm-search">
        <i class="bi bi-search"></i>

        <input
            type="text"
            class="gm-input"
            name="search"
            value="<?= gm_h($search); ?>"
            placeholder="<?= $activeTab === 'countries'
                ? 'Search country, ISO or currency...'
                : 'Search currency code, name or symbol...'; ?>"
        >
    </div>

    <select
        class="gm-select"
        name="status"
        onchange="this.form.submit()"
    >
        <option value="">
            All Status
        </option>

        <option
            value="active"
            <?= $status === 'active' ? 'selected' : ''; ?>
        >
            Active
        </option>

        <option
            value="inactive"
            <?= $status === 'inactive' ? 'selected' : ''; ?>
        >
            Inactive
        </option>
    </select>

    <button
        type="submit"
        class="gm-secondary"
    >
        <i class="bi bi-funnel"></i>
        Filter
    </button>
</form>

<div class="gm-table-wrap">

<?php if ($activeTab === 'countries'): ?>

<table class="gm-table">
<thead>
<tr>
    <th>S/No</th>
    <th>Country</th>
    <th>ISO</th>
    <th>Phone Code</th>
    <th>Currency</th>
    <th>Timezone</th>
    <th>Date Format</th>
    <th>Tax Label</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$listRows): ?>

<tr>
<td colspan="10">
    <div class="gm-empty">
        No countries found.
    </div>
</td>
</tr>

<?php else: ?>

<?php foreach ($listRows as $index => $row): ?>

<tr>
<td>
    <?= $offset + $index + 1; ?>
</td>

<td>
    <?= gm_h($row['name']); ?>
</td>

<td>
    <?= gm_h($row['iso2']); ?>
    /
    <?= gm_h($row['iso3']); ?>
</td>

<td>
    <?= gm_h($row['phone_code']); ?>
</td>

<td>
    <?= gm_h($row['default_currency_code']); ?>
</td>

<td>
    <?= gm_h($row['default_timezone']); ?>
</td>

<td>
    <?= gm_h($row['date_format']); ?>
</td>

<td>
    <?= gm_h($row['tax_label']); ?>
</td>

<td>
    <span
        class="gm-badge <?= (int) $row['is_active'] === 1
            ? 'active'
            : 'inactive'; ?>"
    >
        <?= (int) $row['is_active'] === 1
            ? 'Active'
            : 'Inactive'; ?>
    </span>
</td>

<td>
    <div class="gm-actions">

        <button
            type="button"
            class="gm-icon-btn gm-country-edit"
            data-row='<?= gm_h(
                json_encode(
                    $row,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            ); ?>'
            title="Edit"
        >
            <i class="bi bi-pencil"></i>
        </button>

        <button
            type="button"
            class="gm-icon-btn gm-country-status"
            data-id="<?= (int) $row['id']; ?>"
            data-status="<?= (int) $row['is_active']; ?>"
            title="<?= (int) $row['is_active'] === 1
                ? 'Deactivate'
                : 'Activate'; ?>"
        >
            <i class="bi <?= (int) $row['is_active'] === 1
                ? 'bi-toggle-on'
                : 'bi-toggle-off'; ?>"></i>
        </button>

        <button
            type="button"
            class="gm-icon-btn danger gm-country-delete"
            data-id="<?= (int) $row['id']; ?>"
            data-name="<?= gm_h($row['name']); ?>"
            title="Delete"
        >
            <i class="bi bi-trash"></i>
        </button>

    </div>
</td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>

<?php else: ?>

<table class="gm-table">
<thead>
<tr>
    <th>S/No</th>
    <th>Code</th>
    <th>Currency Name</th>
    <th>Symbol</th>
    <th>Position</th>
    <th>Decimals</th>
    <th>Decimal Separator</th>
    <th>Thousand Separator</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$listRows): ?>

<tr>
<td colspan="10">
    <div class="gm-empty">
        No currencies found.
    </div>
</td>
</tr>

<?php else: ?>

<?php foreach ($listRows as $index => $row): ?>

<tr>
<td>
    <?= $offset + $index + 1; ?>
</td>

<td>
    <?= gm_h($row['currency_code']); ?>
</td>

<td>
    <?= gm_h($row['currency_name']); ?>
</td>

<td>
    <?= gm_h($row['symbol']); ?>
</td>

<td>
    <?= ucfirst(
        gm_h($row['symbol_position'])
    ); ?>
</td>

<td>
    <?= (int) $row['decimal_places']; ?>
</td>

<td>
    <?= gm_h($row['decimal_separator']); ?>
</td>

<td>
    <?= gm_h($row['thousand_separator']); ?>
</td>

<td>
    <span
        class="gm-badge <?= (int) $row['is_active'] === 1
            ? 'active'
            : 'inactive'; ?>"
    >
        <?= (int) $row['is_active'] === 1
            ? 'Active'
            : 'Inactive'; ?>
    </span>
</td>

<td>
    <div class="gm-actions">

        <button
            type="button"
            class="gm-icon-btn gm-currency-edit"
            data-row='<?= gm_h(
                json_encode(
                    $row,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            ); ?>'
            title="Edit"
        >
            <i class="bi bi-pencil"></i>
        </button>

        <button
            type="button"
            class="gm-icon-btn gm-currency-status"
            data-id="<?= (int) $row['id']; ?>"
            data-status="<?= (int) $row['is_active']; ?>"
            title="<?= (int) $row['is_active'] === 1
                ? 'Deactivate'
                : 'Activate'; ?>"
        >
            <i class="bi <?= (int) $row['is_active'] === 1
                ? 'bi-toggle-on'
                : 'bi-toggle-off'; ?>"></i>
        </button>

        <button
            type="button"
            class="gm-icon-btn danger gm-currency-delete"
            data-id="<?= (int) $row['id']; ?>"
            data-name="<?= gm_h($row['currency_code']); ?>"
            title="Delete"
        >
            <i class="bi bi-trash"></i>
        </button>

    </div>
</td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>

<?php endif; ?>

</div>

<div class="gm-footer">

<div class="gm-count">
    Showing
    <?= $totalRows ? $offset + 1 : 0; ?>
    to
    <?= min(
        $offset + $perPage,
        $totalRows
    ); ?>
    of
    <?= $totalRows; ?>
    <?= $activeTab === 'countries'
        ? 'countries'
        : 'currencies'; ?>
</div>

<div class="gm-pagination">

<?php
$prevQuery = $_GET;
$prevQuery['tab'] = $activeTab;
$prevQuery['page'] = max(1, $page - 1);
?>

<a
    class="gm-page-btn <?= $page <= 1 ? 'disabled' : ''; ?>"
    href="?<?= gm_h(http_build_query($prevQuery)); ?>"
>
    <i class="bi bi-chevron-left"></i>
</a>

<?php
for (
    $p = max(1, $page - 2);
    $p <= min($totalPages, $page + 2);
    $p++
):
    $pageQuery = $_GET;
    $pageQuery['tab'] = $activeTab;
    $pageQuery['page'] = $p;
?>

<a
    class="gm-page-btn <?= $p === $page ? 'active' : ''; ?>"
    href="?<?= gm_h(http_build_query($pageQuery)); ?>"
>
    <?= $p; ?>
</a>

<?php endfor; ?>

<?php
$nextQuery = $_GET;
$nextQuery['tab'] = $activeTab;
$nextQuery['page'] = min(
    $totalPages,
    $page + 1
);
?>

<a
    class="gm-page-btn <?= $page >= $totalPages ? 'disabled' : ''; ?>"
    href="?<?= gm_h(http_build_query($nextQuery)); ?>"
>
    <i class="bi bi-chevron-right"></i>
</a>

</div>

</div>

</section>

</div>
</div>

</main>
</div>

<!-- TOAST -->
<div
    id="gmToast"
    class="gm-toast"
    role="status"
    aria-live="polite"
    aria-atomic="true"
>
    <span class="gm-toast-icon">
        <i
            id="gmToastIcon"
            class="bi bi-check-lg"
        ></i>
    </span>

    <span
        id="gmToastMessage"
        class="gm-toast-message"
    >
        Saved successfully.
    </span>

    <button
        type="button"
        id="gmToastClose"
        class="gm-toast-close"
        aria-label="Close"
    >
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<!-- COUNTRY MODAL -->
<div
    class="gm-modal-backdrop"
    id="countryModal"
>
<div class="gm-modal">

<form
    id="countryForm"
    novalidate
>
<input
    type="hidden"
    name="csrf_token"
    value="<?= gm_h($csrfToken); ?>"
>

<input
    type="hidden"
    name="ajax_action"
    value="country_save"
>

<input
    type="hidden"
    name="id"
    id="countryId"
>

<div class="gm-modal-header">
    <div class="gm-modal-title-wrap">

        <span class="gm-card-icon">
            <i class="bi bi-globe2"></i>
        </span>

        <span>
            <h3
                class="gm-modal-title"
                id="countryModalTitle"
            >
                Add Country
            </h3>

            <span class="gm-modal-subtitle">
                Country and localization defaults
            </span>
        </span>

    </div>

    <button
        type="button"
        class="gm-modal-close"
        id="countryModalClose"
    >
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<div class="gm-modal-body">

<div class="gm-form-grid">

<div class="gm-field full">
    <label>
        Country Name
        <span class="gm-required">*</span>
    </label>

    <input
        class="gm-input"
        name="name"
        id="countryName"
        maxlength="120"
        required
    >
</div>

<div class="gm-field">
    <label>
        ISO2
        <span class="gm-required">*</span>
    </label>

    <input
        class="gm-input"
        name="iso2"
        id="countryIso2"
        maxlength="2"
        required
        placeholder="IN"
    >
</div>

<div class="gm-field">
    <label>
        ISO3
        <span class="gm-required">*</span>
    </label>

    <input
        class="gm-input"
        name="iso3"
        id="countryIso3"
        maxlength="3"
        required
        placeholder="IND"
    >
</div>

<div class="gm-field">
    <label>
        Phone Code
    </label>

    <input
        class="gm-input"
        name="phone_code"
        id="countryPhoneCode"
        maxlength="12"
        placeholder="+91"
    >
</div>

<div class="gm-field">
    <label>
        Default Currency
    </label>

    <select
        class="gm-select"
        name="default_currency_code"
        id="countryCurrency"
    >
        <option value="">
            Select currency
        </option>

        <?php foreach ($currencyRows as $currency): ?>

        <option
            value="<?= gm_h($currency['currency_code']); ?>"
        >
            <?= gm_h($currency['currency_code']); ?>
            -
            <?= gm_h($currency['currency_name']); ?>
        </option>

        <?php endforeach; ?>
    </select>
</div>

<div class="gm-field">
    <label>
        Default Timezone
    </label>

    <input
        class="gm-input"
        name="default_timezone"
        id="countryTimezone"
        maxlength="100"
        placeholder="Asia/Kolkata"
    >
</div>

<div class="gm-field">
    <label>
        Date Format
    </label>

    <select
        class="gm-select"
        name="date_format"
        id="countryDateFormat"
    >
        <option value="d-m-Y">
            DD-MM-YYYY
        </option>

        <option value="d/m/Y">
            DD/MM/YYYY
        </option>

        <option value="m-d-Y">
            MM-DD-YYYY
        </option>

        <option value="m/d/Y">
            MM/DD/YYYY
        </option>

        <option value="Y-m-d">
            YYYY-MM-DD
        </option>
    </select>
</div>

<div class="gm-field">
    <label>
        Number Format
    </label>

    <input
        class="gm-input"
        name="number_format"
        id="countryNumberFormat"
        maxlength="30"
        value="1,234.56"
    >
</div>

<div class="gm-field">
    <label>
        Tax Label
    </label>

    <input
        class="gm-input"
        name="tax_label"
        id="countryTaxLabel"
        maxlength="80"
        placeholder="GST / VAT / Tax"
    >
</div>

<div class="gm-field">
    <label>
        Status
    </label>

    <select
        class="gm-select"
        name="is_active"
        id="countryStatus"
    >
        <option value="1">
            Active
        </option>

        <option value="0">
            Inactive
        </option>
    </select>
</div>

</div>
</div>

<div class="gm-modal-footer">

<button
    type="button"
    class="gm-secondary"
    id="countryCancel"
>
    Cancel
</button>

<button
    type="submit"
    class="gm-primary"
    id="countrySaveBtn"
>
    <span class="gm-loader"></span>

    <i class="bi bi-check2-circle"></i>

    <span id="countrySaveText">
        Save Country
    </span>
</button>

</div>

</form>

</div>
</div>

<!-- CURRENCY MODAL -->
<div
    class="gm-modal-backdrop"
    id="currencyModal"
>
<div class="gm-modal">

<form
    id="currencyForm"
    novalidate
>
<input
    type="hidden"
    name="csrf_token"
    value="<?= gm_h($csrfToken); ?>"
>

<input
    type="hidden"
    name="ajax_action"
    value="currency_save"
>

<input
    type="hidden"
    name="id"
    id="currencyId"
>

<div class="gm-modal-header">

<div class="gm-modal-title-wrap">

<span class="gm-card-icon">
    <i class="bi bi-currency-exchange"></i>
</span>

<span>
    <h3
        class="gm-modal-title"
        id="currencyModalTitle"
    >
        Add Currency
    </h3>

    <span class="gm-modal-subtitle">
        Currency identity and number formatting
    </span>
</span>

</div>

<button
    type="button"
    class="gm-modal-close"
    id="currencyModalClose"
>
    <i class="bi bi-x-lg"></i>
</button>

</div>

<div class="gm-modal-body">

<div class="gm-form-grid">

<div class="gm-field">
    <label>
        Currency Code
        <span class="gm-required">*</span>
    </label>

    <input
        class="gm-input"
        name="currency_code"
        id="currencyCode"
        maxlength="3"
        required
        placeholder="INR"
    >
</div>

<div class="gm-field">
    <label>
        Currency Name
        <span class="gm-required">*</span>
    </label>

    <input
        class="gm-input"
        name="currency_name"
        id="currencyName"
        maxlength="120"
        required
        placeholder="Indian Rupee"
    >
</div>

<div class="gm-field">
    <label>
        Symbol
        <span class="gm-required">*</span>
    </label>

    <input
        class="gm-input"
        name="symbol"
        id="currencySymbol"
        maxlength="12"
        required
        placeholder="₹"
    >
</div>

<div class="gm-field">
    <label>
        Symbol Position
    </label>

    <select
        class="gm-select"
        name="symbol_position"
        id="currencyPosition"
    >
        <option value="before">
            Before
        </option>

        <option value="after">
            After
        </option>
    </select>
</div>

<div class="gm-field">
    <label>
        Decimal Places
    </label>

    <input
        class="gm-input"
        type="number"
        name="decimal_places"
        id="currencyDecimals"
        min="0"
        max="8"
        value="2"
    >
</div>

<div class="gm-field">
    <label>
        Decimal Separator
    </label>

    <input
        class="gm-input"
        name="decimal_separator"
        id="currencyDecimalSeparator"
        maxlength="4"
        value="."
    >
</div>

<div class="gm-field">
    <label>
        Thousand Separator
    </label>

    <input
        class="gm-input"
        name="thousand_separator"
        id="currencyThousandSeparator"
        maxlength="4"
        value=","
    >
</div>

<div class="gm-field">
    <label>
        Status
    </label>

    <select
        class="gm-select"
        name="is_active"
        id="currencyStatus"
    >
        <option value="1">
            Active
        </option>

        <option value="0">
            Inactive
        </option>
    </select>
</div>

</div>
</div>

<div class="gm-modal-footer">

<button
    type="button"
    class="gm-secondary"
    id="currencyCancel"
>
    Cancel
</button>

<button
    type="submit"
    class="gm-primary"
    id="currencySaveBtn"
>
    <span class="gm-loader"></span>

    <i class="bi bi-check2-circle"></i>

    <span id="currencySaveText">
        Save Currency
    </span>
</button>

</div>

</form>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

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

document
    .querySelectorAll('.fp-sidebar-menu-toggle')
    .forEach(function(btn){
        btn.addEventListener('click',function(){
            var menu=btn.closest('.fp-sidebar-menu');

            if(menu){
                menu.classList.toggle('open');
            }
        });
    });

var toast=document.getElementById('gmToast');
var toastMessage=document.getElementById('gmToastMessage');
var toastIcon=document.getElementById('gmToastIcon');
var toastClose=document.getElementById('gmToastClose');
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

    toast.className='gm-toast '+t;
    toastMessage.textContent=message||'Notification';

    toastIcon.className=
        'bi '+(icons[t]||icons.info);

    toast.classList.add('show');

    toastTimer=setTimeout(
        function(){
            toast.classList.remove('show');
            toastTimer=null;
        },
        typeof duration==='number'
            ? duration
            : 3000
    );
}

if(toastClose){
    toastClose.addEventListener('click',function(){
        if(toastTimer){
            clearTimeout(toastTimer);
        }

        toast.classList.remove('show');
    });
}

function postFormData(formData){
    return fetch(
        'api/country-currency-master.php',
        {
            method:'POST',
            body:formData,
            credentials:'same-origin',
            headers:{
                'X-Requested-With':'XMLHttpRequest'
            }
        }
    )
    .then(function(response){
        return response
            .json()
            .then(function(data){
                return {
                    ok:response.ok,
                    data:data
                };
            });
    });
}

/*
|--------------------------------------------------------------------------
| COUNTRY MODAL
|--------------------------------------------------------------------------
*/

var countryModal=
    document.getElementById('countryModal');

var countryForm=
    document.getElementById('countryForm');

var countrySaveBtn=
    document.getElementById('countrySaveBtn');

var countrySaveText=
    document.getElementById('countrySaveText');

function openCountryModal(row){
    countryForm.reset();

    document.getElementById('countryId').value='';
    document.getElementById('countryModalTitle').textContent='Add Country';
    countrySaveText.textContent='Save Country';

    if(row){
        document.getElementById('countryId').value=row.id||'';
        document.getElementById('countryName').value=row.name||'';
        document.getElementById('countryIso2').value=row.iso2||'';
        document.getElementById('countryIso3').value=row.iso3||'';
        document.getElementById('countryPhoneCode').value=row.phone_code||'';
        document.getElementById('countryCurrency').value=row.default_currency_code||'';
        document.getElementById('countryTimezone').value=row.default_timezone||'';
        document.getElementById('countryDateFormat').value=row.date_format||'d-m-Y';
        document.getElementById('countryNumberFormat').value=row.number_format||'1,234.56';
        document.getElementById('countryTaxLabel').value=row.tax_label||'';
        document.getElementById('countryStatus').value=String(row.is_active);

        document.getElementById('countryModalTitle').textContent='Edit Country';
        countrySaveText.textContent='Update Country';
    }

    countryModal.classList.add('show');
}

function closeCountryModal(){
    countryModal.classList.remove('show');
}

document
    .getElementById('countryModalClose')
    .addEventListener('click',closeCountryModal);

document
    .getElementById('countryCancel')
    .addEventListener('click',closeCountryModal);

countryModal.addEventListener('click',function(e){
    if(e.target===countryModal){
        closeCountryModal();
    }
});

document
    .querySelectorAll('.gm-country-edit')
    .forEach(function(btn){
        btn.addEventListener('click',function(){
            try{
                openCountryModal(
                    JSON.parse(
                        btn.getAttribute('data-row')
                    )
                );
            }catch(error){
                showToast(
                    'error',
                    'Unable to load country details.',
                    3000
                );
            }
        });
    });

countryForm.addEventListener('submit',function(e){
    e.preventDefault();

    if(!countryForm.checkValidity()){
        showToast(
            'warning',
            'Please complete the required fields correctly.',
            3000
        );

        countryForm.reportValidity();
        return;
    }

    countrySaveBtn.disabled=true;
    countrySaveBtn.classList.add('loading');
    countrySaveText.textContent='Saving...';

    postFormData(
        new FormData(countryForm)
    )
    .then(function(result){
        if(
            !result.ok ||
            !result.data.success
        ){
            throw new Error(
                result.data.message ||
                'Unable to save country.'
            );
        }

        showToast(
            'success',
            result.data.message,
            3000
        );

        closeCountryModal();

        setTimeout(
            function(){
                window.location.reload();
            },
            500
        );
    })
    .catch(function(error){
        showToast(
            'error',
            error.message ||
            'Unable to save country.',
            3000
        );

        countrySaveBtn.disabled=false;
        countrySaveBtn.classList.remove('loading');
        countrySaveText.textContent='Save Country';
    });
});

/*
|--------------------------------------------------------------------------
| CURRENCY MODAL
|--------------------------------------------------------------------------
*/

var currencyModal=
    document.getElementById('currencyModal');

var currencyForm=
    document.getElementById('currencyForm');

var currencySaveBtn=
    document.getElementById('currencySaveBtn');

var currencySaveText=
    document.getElementById('currencySaveText');

function openCurrencyModal(row){
    currencyForm.reset();

    document.getElementById('currencyId').value='';
    document.getElementById('currencyModalTitle').textContent='Add Currency';
    currencySaveText.textContent='Save Currency';

    if(row){
        document.getElementById('currencyId').value=row.id||'';
        document.getElementById('currencyCode').value=row.currency_code||'';
        document.getElementById('currencyName').value=row.currency_name||'';
        document.getElementById('currencySymbol').value=row.symbol||'';
        document.getElementById('currencyPosition').value=row.symbol_position||'before';
        document.getElementById('currencyDecimals').value=row.decimal_places||2;
        document.getElementById('currencyDecimalSeparator').value=row.decimal_separator||'.';
        document.getElementById('currencyThousandSeparator').value=row.thousand_separator||',';
        document.getElementById('currencyStatus').value=String(row.is_active);

        document.getElementById('currencyModalTitle').textContent='Edit Currency';
        currencySaveText.textContent='Update Currency';
    }

    currencyModal.classList.add('show');
}

function closeCurrencyModal(){
    currencyModal.classList.remove('show');
}

document
    .getElementById('currencyModalClose')
    .addEventListener('click',closeCurrencyModal);

document
    .getElementById('currencyCancel')
    .addEventListener('click',closeCurrencyModal);

currencyModal.addEventListener('click',function(e){
    if(e.target===currencyModal){
        closeCurrencyModal();
    }
});

document
    .querySelectorAll('.gm-currency-edit')
    .forEach(function(btn){
        btn.addEventListener('click',function(){
            try{
                openCurrencyModal(
                    JSON.parse(
                        btn.getAttribute('data-row')
                    )
                );
            }catch(error){
                showToast(
                    'error',
                    'Unable to load currency details.',
                    3000
                );
            }
        });
    });

currencyForm.addEventListener('submit',function(e){
    e.preventDefault();

    if(!currencyForm.checkValidity()){
        showToast(
            'warning',
            'Please complete the required fields correctly.',
            3000
        );

        currencyForm.reportValidity();
        return;
    }

    currencySaveBtn.disabled=true;
    currencySaveBtn.classList.add('loading');
    currencySaveText.textContent='Saving...';

    postFormData(
        new FormData(currencyForm)
    )
    .then(function(result){
        if(
            !result.ok ||
            !result.data.success
        ){
            throw new Error(
                result.data.message ||
                'Unable to save currency.'
            );
        }

        showToast(
            'success',
            result.data.message,
            3000
        );

        closeCurrencyModal();

        setTimeout(
            function(){
                window.location.reload();
            },
            500
        );
    })
    .catch(function(error){
        showToast(
            'error',
            error.message ||
            'Unable to save currency.',
            3000
        );

        currencySaveBtn.disabled=false;
        currencySaveBtn.classList.remove('loading');
        currencySaveText.textContent='Save Currency';
    });
});

/*
|--------------------------------------------------------------------------
| ADD BUTTON
|--------------------------------------------------------------------------
*/

document
    .getElementById('gmAddButton')
    .addEventListener('click',function(){
        var tab=
            '<?= gm_h($activeTab); ?>';

        if(tab==='countries'){
            openCountryModal(null);
        }else{
            openCurrencyModal(null);
        }
    });

/*
|--------------------------------------------------------------------------
| STATUS / DELETE VIA API
|--------------------------------------------------------------------------
*/

function simpleAction(
    action,
    id,
    isActive
){
    var fd=new FormData();

    fd.append(
        'csrf_token',
        '<?= gm_h($csrfToken); ?>'
    );

    fd.append(
        'ajax_action',
        action
    );

    fd.append(
        'id',
        id
    );

    if(
        typeof isActive !==
        'undefined'
    ){
        fd.append(
            'is_active',
            isActive
        );
    }

    postFormData(fd)
    .then(function(result){
        if(
            !result.ok ||
            !result.data.success
        ){
            throw new Error(
                result.data.message ||
                'Unable to complete action.'
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
    })
    .catch(function(error){
        showToast(
            'error',
            error.message ||
            'Unable to complete action.',
            3000
        );
    });
}

document
    .querySelectorAll('.gm-country-status')
    .forEach(function(btn){
        btn.addEventListener('click',function(){
            simpleAction(
                'country_status',
                btn.getAttribute('data-id'),
                btn.getAttribute('data-status')==='1'
                    ? '0'
                    : '1'
            );
        });
    });

document
    .querySelectorAll('.gm-currency-status')
    .forEach(function(btn){
        btn.addEventListener('click',function(){
            simpleAction(
                'currency_status',
                btn.getAttribute('data-id'),
                btn.getAttribute('data-status')==='1'
                    ? '0'
                    : '1'
            );
        });
    });

document
    .querySelectorAll('.gm-country-delete')
    .forEach(function(btn){
        btn.addEventListener('click',function(){
            if(
                !window.confirm(
                    'Delete '+
                    btn.getAttribute('data-name')+
                    '?'
                )
            ){
                return;
            }

            simpleAction(
                'country_delete',
                btn.getAttribute('data-id')
            );
        });
    });

document
    .querySelectorAll('.gm-currency-delete')
    .forEach(function(btn){
        btn.addEventListener('click',function(){
            if(
                !window.confirm(
                    'Delete '+
                    btn.getAttribute('data-name')+
                    '?'
                )
            ){
                return;
            }

            simpleAction(
                'currency_delete',
                btn.getAttribute('data-id')
            );
        });
    });

})();
</script>

</body>
</html>
