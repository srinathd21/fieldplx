<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Plans';
$activePage = 'plans';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['plans_csrf'])) {
    $_SESSION['plans_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['plans_csrf'];

function pl_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

function pl_limit($value)
{
    return ($value === null || $value === '')
        ? 'Unlimited'
        : number_format((float)$value, 0);
}

$search = isset($_GET['search'])
    ? trim((string)$_GET['search'])
    : '';

$status = isset($_GET['status'])
    ? trim((string)$_GET['status'])
    : '';

$cycle = isset($_GET['billing_cycle'])
    ? trim((string)$_GET['billing_cycle'])
    : '';

$countryId = isset($_GET['country_id'])
    ? (int)$_GET['country_id']
    : 0;

$countries = $pdo->query("
    SELECT
        id,
        name,
        iso2,
        default_currency_code
    FROM countries
    WHERE is_active = 1
    ORDER BY name ASC
")->fetchAll();

$sql = "
    SELECT
        p.*,
        (
            SELECT COUNT(*)
            FROM subscriptions s
            WHERE s.plan_id = p.id
              AND s.deleted_at IS NULL
        ) AS subscription_count,
        (
            SELECT COUNT(*)
            FROM plan_modules pm
            WHERE pm.plan_id = p.id
              AND pm.is_enabled = 1
        ) AS module_count,
        (
            SELECT COUNT(*)
            FROM plan_features pf
            WHERE pf.plan_id = p.id
              AND pf.is_enabled = 1
        ) AS feature_count
    FROM plans p
    WHERE p.deleted_at IS NULL
";

$params = array();

if ($search !== '') {
    $sql .= " AND (
        p.name LIKE :search
        OR p.code LIKE :search
        OR p.description LIKE :search
        OR p.currency LIKE :search
    ) ";

    $params[':search'] =
        '%' . $search . '%';
}

if (
    in_array(
        $status,
        array(
            'active',
            'inactive',
            'draft',
            'archived'
        ),
        true
    )
) {
    $sql .= " AND p.status = :status ";

    $params[':status'] =
        $status;
}

if (
    in_array(
        $cycle,
        array(
            'monthly',
            'quarterly',
            'half_yearly',
            'yearly',
            'lifetime',
            'custom'
        ),
        true
    )
) {
    $sql .= " AND p.billing_cycle = :billing_cycle ";

    $params[':billing_cycle'] =
        $cycle;
}

if ($countryId > 0) {
    $sql .= "
        AND EXISTS (
            SELECT 1
            FROM countries c_filter
            WHERE c_filter.id = :country_id
              AND c_filter.is_active = 1
              AND c_filter.default_currency_code = p.currency
        )
    ";

    $params[':country_id'] =
        $countryId;
}

$sql .= "
    ORDER BY
        p.is_featured DESC,
        CASE p.status
            WHEN 'active' THEN 1
            WHEN 'draft' THEN 2
            WHEN 'inactive' THEN 3
            ELSE 4
        END,
        p.price ASC,
        p.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$plans = $stmt->fetchAll();

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active_count,
        SUM(status = 'draft') AS draft_count,
        SUM(is_featured = 1) AS featured_count
    FROM plans
    WHERE deleted_at IS NULL
")->fetch();

$currencies = $pdo->query("
    SELECT
        currency_code,
        currency_name,
        symbol
    FROM currencies
    WHERE is_active = 1
    ORDER BY currency_code
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
    <?= pl_h($pageTitle) ?> - FieldPlx
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

/* =========================================================
   PAGE
   ========================================================= */

.pl-page{
    display:grid;
    gap:16px
}

.pl-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px
}

.pl-title{
    margin:0;
    color:#111827;
    font-size:20px;
    font-weight:800
}

.pl-description{
    margin-top:4px;
    max-width:780px;
    color:#77718e;
    font-size:10px;
    line-height:1.55
}

.pl-header-actions{
    display:flex;
    align-items:center;
    gap:8px
}

.pl-primary,
.pl-secondary{
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

.pl-primary{
    border:0;
    background:linear-gradient(
        135deg,
        #7c3aed,
        #6d28d9
    );
    color:#fff;
    box-shadow:0 8px 20px rgba(109,40,217,.18)
}

.pl-secondary{
    border:1px solid #dcd5ef;
    background:#fff;
    color:#5f5870
}

.pl-primary:hover{
    background:linear-gradient(
        135deg,
        #6d28d9,
        #5b21b6
    )
}

.pl-secondary:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.pl-primary:disabled,
.pl-secondary:disabled{
    opacity:.65;
    cursor:not-allowed
}

/* =========================================================
   APPROVED SUMMARY CARDS
   ========================================================= */

.pl-stats{
    display:grid;
    grid-template-columns:repeat(
        4,
        minmax(0,1fr)
    );
    gap:12px
}

.pl-stat{
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

.pl-stat:hover{
    border-color:#cfc3ef;
    background:linear-gradient(
        180deg,
        #fff 0%,
        #f8f4ff 100%
    )
}

.pl-stat-icon{
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

.pl-stat-content{
    min-width:0;
    display:block
}

.pl-stat-label{
    display:block;
    color:#9a94ae;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
    line-height:1.3
}

.pl-stat-value{
    margin-top:2px;
    display:block;
    color:#111827;
    font-size:20px;
    font-weight:800;
    line-height:1.2
}

.pl-stat-note{
    margin-top:2px;
    display:block;
    color:#9d96ac;
    font-size:7.5px;
    line-height:1.35
}

/* =========================================================
   APPROVED TABLE CARD
   ========================================================= */

.pl-card{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 24px rgba(37,29,80,.05)
}

.pl-toolbar{
    padding:13px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    background:#fbf9ff;
    border-bottom:1px solid #ece7f7
}

.pl-toolbar-left{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap
}

.pl-search{
    position:relative
}

.pl-search i{
    position:absolute;
    left:11px;
    top:50%;
    transform:translateY(-50%);
    color:#948da7;
    font-size:12px
}

.pl-input,
.pl-select{
    height:39px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    background:#fff;
    color:#312b47;
    font-size:10px;
    outline:0
}

.pl-input{
    padding:8px 11px
}

.pl-search .pl-input{
    width:260px;
    padding-left:33px
}

.pl-select{
    padding:8px 30px 8px 10px
}

.pl-input:focus,
.pl-select:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10)
}

.pl-table-wrap{
    overflow:auto
}

/* Very small scrollbar */
.pl-table-wrap{
    scrollbar-width:thin;
    scrollbar-color:#b9b1c9 transparent;
}

.pl-table-wrap::-webkit-scrollbar{
    height:4px;
    width:4px;
}

.pl-table-wrap::-webkit-scrollbar-track{
    background:transparent;
}

.pl-table-wrap::-webkit-scrollbar-thumb{
    background:#b9b1c9;
    border-radius:999px;
}

.pl-table-wrap::-webkit-scrollbar-thumb:hover{
    background:#9f94b8;
}

.pl-modal{
    scrollbar-width:thin;
    scrollbar-color:#b9b1c9 transparent;
}

.pl-modal::-webkit-scrollbar{
    width:4px;
}

.pl-modal::-webkit-scrollbar-track{
    background:transparent;
}

.pl-modal::-webkit-scrollbar-thumb{
    background:#b9b1c9;
    border-radius:999px;
}

.pl-modal::-webkit-scrollbar-thumb:hover{
    background:#9f94b8;
}

.pl-table{
    width:100%;
    min-width:1120px;
    border-collapse:collapse
}

.pl-table th{
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

.pl-table td{
    padding:10px 12px;
    border-bottom:1px solid #f0ecf7;
    color:#433d54;
    font-size:9px;
    vertical-align:middle
}

.pl-table tbody tr:hover{
    background:#fcfbff
}

.pl-plan-name{
    display:flex;
    align-items:center;
    gap:8px;
    min-width:0
}

.pl-plan-icon{
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

.pl-plan-title{
    display:block;
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.pl-plan-code{
    display:block;
    margin-top:2px;
    color:#9b94a7;
    font-size:8px
}

.pl-featured-star{
    margin-left:4px;
    color:#7c3aed
}

.pl-price{
    display:block;
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.pl-price-cycle{
    display:block;
    margin-top:2px;
    color:#9b94a7;
    font-size:8px;
    text-transform:capitalize
}

.pl-limit-grid{
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:3px 10px;
    min-width:165px
}

.pl-limit-grid span{
    white-space:nowrap;
    color:#6d667b;
    font-size:8px
}

.pl-limit-grid strong{
    color:#393248;
    font-weight:700
}

.pl-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700;
    text-transform:capitalize
}

.pl-badge.active{
    background:#ecfdf5;
    color:#047857
}

.pl-badge.inactive{
    background:#f3f4f6;
    color:#6b7280
}

.pl-badge.draft{
    background:#fff7ed;
    color:#c2410c
}

.pl-badge.archived{
    background:#fef2f2;
    color:#b91c1c
}

.pl-actions{
    display:flex;
    align-items:center;
    gap:5px
}

.pl-icon-btn{
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

.pl-icon-btn:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.pl-icon-btn.danger:hover{
    border-color:#fecaca;
    background:#fef2f2;
    color:#dc2626
}

.pl-empty{
    padding:36px 15px;
    text-align:center;
    color:#928aa5;
    font-size:10px
}

/* =========================================================
   MODAL
   ========================================================= */

.pl-modal-backdrop{
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

.pl-modal-backdrop.show{
    display:flex
}

.pl-modal{
    width:min(700px,100%);
    max-height:calc(100vh - 36px);
    overflow:auto;
    border:1px solid #ded7ef;
    border-radius:15px;
    background:#fff;
    box-shadow:0 24px 60px rgba(28,20,70,.22)
}

.pl-modal-header{
    padding:13px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff
}

.pl-modal-title-wrap{
    display:flex;
    align-items:center;
    gap:10px
}

.pl-modal-icon{
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

.pl-modal-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800
}

.pl-modal-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px
}

.pl-modal-close{
    width:30px;
    height:30px;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#fff;
    color:#6d657d;
    cursor:pointer
}

.pl-modal-close:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.pl-modal-body{
    padding:15px
}

.pl-section{
    margin-bottom:13px;
    padding:12px;
    border:1px solid #e2dcf2;
    border-radius:10px;
    background:#fbf9ff
}

.pl-section:last-child{
    margin-bottom:0
}

.pl-section-title{
    margin:0 0 10px;
    color:#393248;
    font-size:9px;
    font-weight:700
}

.pl-form-grid{
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:13px
}

.pl-field.full{
    grid-column:1/-1
}

.pl-field label{
    margin-bottom:6px;
    display:block;
    color:#4c465f;
    font-size:9px;
    font-weight:700
}

.pl-required{
    color:#dc2626
}

.pl-textarea{
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

.pl-textarea:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10)
}

.pl-hint{
    margin-top:4px;
    color:#9a94aa;
    font-size:8px
}

.pl-toggle{
    min-height:48px;
    padding:9px 10px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border:1px solid #e2dcf2;
    border-radius:10px;
    background:#fff
}

.pl-toggle strong{
    display:block;
    color:#393248;
    font-size:9px
}

.pl-toggle small{
    margin-top:2px;
    display:block;
    color:#9a94aa;
    font-size:8px
}

.pl-modal-footer{
    padding:12px 15px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid #ece7f7;
    background:#fbf9ff
}

/* =========================================================
   LOADER
   ========================================================= */

.pl-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:plSpin .75s linear infinite
}

.pl-primary.loading .pl-loader{
    display:inline-block
}

@keyframes plSpin{
    to{
        transform:rotate(360deg)
    }
}

/* =========================================================
   APPROVED TOAST
   ========================================================= */

.pl-toast{
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
    box-shadow:
        0 16px 34px
        rgba(16,24,40,.18);
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

.pl-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0)
}

.pl-toast.success{
    background:#059669
}

.pl-toast.error{
    background:#dc2626
}

.pl-toast.warning{
    background:#d97706
}

.pl-toast.info{
    background:#4f46e5
}

.pl-toast-icon{
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

.pl-toast-message{
    flex:1;
    min-width:0;
    font-weight:600
}

.pl-toast-close{
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

.pl-toast-close:hover{
    background:rgba(255,255,255,.12);
    opacity:1
}

/* =========================================================
   RESPONSIVE
   ========================================================= */

@media(max-width:1100px){
    .pl-stats{
        grid-template-columns:
            repeat(2,minmax(0,1fr))
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
    .pl-header{
        flex-direction:column
    }

    .pl-header-actions{
        width:100%
    }

    .pl-header-actions .pl-primary,
    .pl-header-actions .pl-secondary{
        flex:1
    }

    .pl-toolbar{
        align-items:stretch
    }

    .pl-toolbar-left{
        width:100%
    }

    .pl-search{
        width:100%
    }

    .pl-search .pl-input{
        width:100%
    }

    .pl-form-grid{
        grid-template-columns:1fr
    }

    .pl-field.full{
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

    .pl-stats{
        grid-template-columns:1fr
    }

    .pl-stat{
        min-height:82px
    }

    .pl-modal-footer{
        flex-direction:column-reverse
    }

    .pl-modal-footer .pl-primary,
    .pl-modal-footer .pl-secondary{
        width:100%
    }

    .pl-toast{
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

<?php
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="fp-main">

<?php
require_once __DIR__ . '/includes/topbar.php';
?>

<div class="fp-content">

<div class="pl-page">

<div class="pl-header">

    <div>

        <h2 class="pl-title">
            Plans
        </h2>

        <div class="pl-description">
            Create and manage FieldPlx subscription plans,
            base pricing, billing cycle, trial period,
            usage limits and availability.
        </div>

    </div>

    <div class="pl-header-actions">

        <button
            type="button"
            class="pl-primary"
            id="addPlanBtn"
        >
            <i class="bi bi-plus-lg"></i>
            Add Plan
        </button>

    </div>

</div>

<div class="pl-stats">

<?php

$cards = array(
    array(
        'Total Plans',
        'bi bi-box',
        (int)($stats['total'] ?? 0),
        'All configured plans'
    ),
    array(
        'Active',
        'bi bi-check-circle',
        (int)($stats['active_count'] ?? 0),
        'Available for subscriptions'
    ),
    array(
        'Draft',
        'bi bi-file-earmark',
        (int)($stats['draft_count'] ?? 0),
        'Not yet published'
    ),
    array(
        'Featured',
        'bi bi-star',
        (int)($stats['featured_count'] ?? 0),
        'Highlighted plan options'
    )
);

foreach ($cards as $card):

?>

<div class="pl-stat">

    <span class="pl-stat-icon">
        <i class="<?= pl_h($card[1]) ?>"></i>
    </span>

    <span class="pl-stat-content">

        <span class="pl-stat-label">
            <?= pl_h($card[0]) ?>
        </span>

        <span class="pl-stat-value">
            <?= number_format($card[2]) ?>
        </span>

        <span class="pl-stat-note">
            <?= pl_h($card[3]) ?>
        </span>

    </span>

</div>

<?php endforeach; ?>

</div>

<section class="pl-card">

<form
    method="get"
    class="pl-toolbar"
>

<div class="pl-toolbar-left">

    <div class="pl-search">

        <i class="bi bi-search"></i>

        <input
            type="text"
            class="pl-input"
            name="search"
            value="<?= pl_h($search) ?>"
            placeholder="Search plan, code or currency"
        >

    </div>

    <select
        class="pl-select"
        name="status"
        onchange="this.form.submit()"
    >

        <option value="">
            All Status
        </option>

        <option
            value="active"
            <?= $status === 'active'
                ? 'selected'
                : '' ?>
        >
            Active
        </option>

        <option
            value="inactive"
            <?= $status === 'inactive'
                ? 'selected'
                : '' ?>
        >
            Inactive
        </option>

        <option
            value="draft"
            <?= $status === 'draft'
                ? 'selected'
                : '' ?>
        >
            Draft
        </option>

        <option
            value="archived"
            <?= $status === 'archived'
                ? 'selected'
                : '' ?>
        >
            Archived
        </option>

    </select>

    <select
        class="pl-select"
        name="billing_cycle"
        onchange="this.form.submit()"
    >

        <option value="">
            All Billing Cycles
        </option>

        <option
            value="monthly"
            <?= $cycle === 'monthly'
                ? 'selected'
                : '' ?>
        >
            Monthly
        </option>

        <option
            value="quarterly"
            <?= $cycle === 'quarterly'
                ? 'selected'
                : '' ?>
        >
            Quarterly
        </option>

        <option
            value="half_yearly"
            <?= $cycle === 'half_yearly'
                ? 'selected'
                : '' ?>
        >
            Half Yearly
        </option>

        <option
            value="yearly"
            <?= $cycle === 'yearly'
                ? 'selected'
                : '' ?>
        >
            Yearly
        </option>

        <option
            value="lifetime"
            <?= $cycle === 'lifetime'
                ? 'selected'
                : '' ?>
        >
            Lifetime
        </option>

        <option
            value="custom"
            <?= $cycle === 'custom'
                ? 'selected'
                : '' ?>
        >
            Custom
        </option>

    </select>

    <select
        class="pl-select"
        name="country_id"
        onchange="this.form.submit()"
    >

        <option value="">
            All Countries
        </option>

        <?php foreach ($countries as $country): ?>

        <option
            value="<?= (int)$country['id'] ?>"
            <?= $countryId === (int)$country['id']
                ? 'selected'
                : '' ?>
        >
            <?= pl_h($country['name']) ?>
            <?php if (!empty($country['iso2'])): ?>
                (<?= pl_h($country['iso2']) ?>)
            <?php endif; ?>
        </option>

        <?php endforeach; ?>

    </select>

    <?php
    if (
        $search !== '' ||
        $status !== '' ||
        $cycle !== '' ||
        $countryId > 0
    ):
    ?>

    <a
        class="pl-secondary"
        href="plans.php"
    >
        <i class="bi bi-x-lg"></i>
        Clear
    </a>

    <?php endif; ?>

</div>

<button
    type="submit"
    class="pl-secondary"
>
    <i class="bi bi-funnel"></i>
    Filter
</button>

</form>

<div class="pl-table-wrap">

<table class="pl-table">

<thead>

<tr>
    <th>S/No</th>
    <th>Plan</th>
    <th>Base Price</th>
    <th>Trial</th>
    <th>Limits</th>
    <th>Modules</th>
    <th>Features</th>
    <th>Subscriptions</th>
    <th>Status</th>
    <th>Action</th>
</tr>

</thead>

<tbody>

<?php if (!$plans): ?>

<tr>
    <td colspan="10">

        <div class="pl-empty">
            No plans found.
        </div>

    </td>
</tr>

<?php else: ?>

<?php
foreach (
    $plans as $index => $plan
):
?>

<tr>

    <td>
        <?= $index + 1 ?>
    </td>

    <td>

        <div class="pl-plan-name">

            <span class="pl-plan-icon">
                <i class="bi bi-box"></i>
            </span>

            <span>

                <span class="pl-plan-title">

                    <?= pl_h($plan['name']) ?>

                    <?php
                    if (
                        (int)$plan['is_featured'] === 1
                    ):
                    ?>

                    <i
                        class="bi bi-star-fill pl-featured-star"
                        title="Featured"
                    ></i>

                    <?php endif; ?>

                </span>

                <span class="pl-plan-code">
                    <?= pl_h($plan['code']) ?>
                </span>

            </span>

        </div>

    </td>

    <td>

        <span class="pl-price">

            <?= pl_h($plan['currency']) ?>

            <?= number_format(
                (float)$plan['price'],
                2
            ) ?>

        </span>

        <span class="pl-price-cycle">

            <?= pl_h(
                str_replace(
                    '_',
                    ' ',
                    $plan['billing_cycle']
                )
            ) ?>

        </span>

    </td>

    <td>

        <?= (int)$plan['trial_days'] > 0
            ? (int)$plan['trial_days'] . ' days'
            : 'No trial' ?>

    </td>

    <td>

        <div class="pl-limit-grid">

            <span>
                Users:
                <strong>
                    <?= pl_limit(
                        $plan['max_users']
                    ) ?>
                </strong>
            </span>

            <span>
                Branches:
                <strong>
                    <?= pl_limit(
                        $plan['max_branches']
                    ) ?>
                </strong>
            </span>

            <span>
                Customers:
                <strong>
                    <?= pl_limit(
                        $plan['max_customers']
                    ) ?>
                </strong>
            </span>

            <span>
                Storage:
                <strong>
                    <?= $plan['storage_limit_mb'] === null
                        ? 'Unlimited'
                        : number_format(
                            (float)$plan['storage_limit_mb'] / 1024,
                            1
                        ) . ' GB' ?>
                </strong>
            </span>

        </div>

    </td>

    <td>
        <?= number_format(
            (int)$plan['module_count']
        ) ?>
    </td>

    <td>
        <?= number_format(
            (int)$plan['feature_count']
        ) ?>
    </td>

    <td>
        <?= number_format(
            (int)$plan['subscription_count']
        ) ?>
    </td>

    <td>

        <span
            class="pl-badge <?= pl_h(
                $plan['status']
            ) ?>"
        >
            <?= pl_h(
                ucfirst(
                    $plan['status']
                )
            ) ?>
        </span>

    </td>

    <td>

        <div class="pl-actions">

            <button
                type="button"
                class="pl-icon-btn plan-edit"
                title="Edit"
                data-row='<?= pl_h(
                    json_encode(
                        $plan,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    )
                ) ?>'
            >
                <i class="bi bi-pencil"></i>
            </button>

            <button
                type="button"
                class="pl-icon-btn plan-featured"
                title="<?= (int)$plan['is_featured'] === 1
                    ? 'Remove Featured'
                    : 'Mark Featured' ?>"
                data-id="<?= (int)$plan['id'] ?>"
                data-value="<?= (int)$plan['is_featured'] ?>"
            >
                <i
                    class="bi <?= (int)$plan['is_featured'] === 1
                        ? 'bi-star-fill'
                        : 'bi-star' ?>"
                ></i>
            </button>

            <button
                type="button"
                class="pl-icon-btn plan-status"
                title="<?= $plan['status'] === 'active'
                    ? 'Deactivate'
                    : 'Activate' ?>"
                data-id="<?= (int)$plan['id'] ?>"
                data-status="<?= pl_h(
                    $plan['status']
                ) ?>"
            >
                <i
                    class="bi <?= $plan['status'] === 'active'
                        ? 'bi-toggle-on'
                        : 'bi-toggle-off' ?>"
                ></i>
            </button>

            <button
                type="button"
                class="pl-icon-btn danger plan-archive"
                title="Archive"
                data-id="<?= (int)$plan['id'] ?>"
                data-subscriptions="<?= (int)$plan['subscription_count'] ?>"
            >
                <i class="bi bi-archive"></i>
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

<!-- =========================================================
     TOAST
     ========================================================= -->

<div
    id="plToast"
    class="pl-toast"
>

    <span class="pl-toast-icon">
        <i
            id="plToastIcon"
            class="bi bi-check-lg"
        ></i>
    </span>

    <span
        id="plToastMessage"
        class="pl-toast-message"
    ></span>

    <button
        type="button"
        id="plToastClose"
        class="pl-toast-close"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<!-- =========================================================
     PLAN MODAL
     ========================================================= -->

<div
    class="pl-modal-backdrop"
    id="planModal"
>

<div class="pl-modal">

<form
    id="planForm"
    novalidate
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= pl_h($csrfToken) ?>"
>

<input
    type="hidden"
    name="action"
    value="save_plan"
>

<input
    type="hidden"
    name="id"
    id="planId"
>

<div class="pl-modal-header">

    <div class="pl-modal-title-wrap">

        <span class="pl-modal-icon">
            <i class="bi bi-box"></i>
        </span>

        <span>

            <h3
                class="pl-modal-title"
                id="planModalTitle"
            >
                Add Plan
            </h3>

            <span class="pl-modal-subtitle">
                Configure plan information,
                pricing and usage limits
            </span>

        </span>

    </div>

    <button
        type="button"
        class="pl-modal-close"
        id="planModalClose"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<div class="pl-modal-body">

<div class="pl-section">

    <h4 class="pl-section-title">
        Plan Information
    </h4>

    <div class="pl-form-grid">

        <div class="pl-field">

            <label>
                Plan Name
                <span class="pl-required">*</span>
            </label>

            <input
                class="pl-input"
                style="width:100%"
                name="name"
                id="planName"
                maxlength="190"
                required
                placeholder="Professional"
            >

        </div>

        <div class="pl-field">

            <label>
                Plan Code
                <span class="pl-required">*</span>
            </label>

            <input
                class="pl-input"
                style="width:100%"
                name="code"
                id="planCode"
                maxlength="120"
                required
                placeholder="professional"
            >

        </div>

        <div class="pl-field full">

            <label>
                Description
            </label>

            <textarea
                class="pl-textarea"
                name="description"
                id="planDescription"
                maxlength="1000"
            ></textarea>

        </div>

    </div>

</div>

<div class="pl-section">

    <h4 class="pl-section-title">
        Pricing & Billing
    </h4>

    <div class="pl-form-grid">

        <div class="pl-field">

            <label>
                Base Price
                <span class="pl-required">*</span>
            </label>

            <input
                class="pl-input"
                style="width:100%"
                type="number"
                name="price"
                id="planPrice"
                min="0"
                step="0.01"
                value="0.00"
                required
            >

        </div>

        <div class="pl-field">

            <label>
                Currency
                <span class="pl-required">*</span>
            </label>

            <select
                class="pl-select"
                style="width:100%"
                name="currency"
                id="planCurrency"
                required
            >

                <option value="">
                    Select Currency
                </option>

                <?php
                foreach (
                    $currencies as $currency
                ):
                ?>

                <option
                    value="<?= pl_h(
                        $currency['currency_code']
                    ) ?>"
                >
                    <?= pl_h(
                        $currency['currency_code']
                    ) ?>
                    -
                    <?= pl_h(
                        $currency['currency_name']
                    ) ?>
                </option>

                <?php endforeach; ?>

            </select>

        </div>

        <div class="pl-field">

            <label>
                Billing Cycle
                <span class="pl-required">*</span>
            </label>

            <select
                class="pl-select"
                style="width:100%"
                name="billing_cycle"
                id="planBillingCycle"
                required
            >

                <option value="monthly">
                    Monthly
                </option>

                <option value="quarterly">
                    Quarterly
                </option>

                <option value="half_yearly">
                    Half Yearly
                </option>

                <option value="yearly">
                    Yearly
                </option>

                <option value="lifetime">
                    Lifetime
                </option>

                <option value="custom">
                    Custom
                </option>

            </select>

        </div>

        <div class="pl-field">

            <label>
                Duration Days
            </label>

            <input
                class="pl-input"
                style="width:100%"
                type="number"
                name="duration_days"
                id="durationDays"
                min="1"
                placeholder="Custom cycle only"
            >

        </div>

        <div class="pl-field">

            <label>
                Trial Days
            </label>

            <input
                class="pl-input"
                style="width:100%"
                type="number"
                name="trial_days"
                id="trialDays"
                min="0"
                value="0"
            >

        </div>

        <div class="pl-field">

            <label>
                Status
            </label>

            <select
                class="pl-select"
                style="width:100%"
                name="status"
                id="planStatus"
            >

                <option value="active">
                    Active
                </option>

                <option value="inactive">
                    Inactive
                </option>

                <option value="draft">
                    Draft
                </option>

                <option value="archived">
                    Archived
                </option>

            </select>

        </div>

    </div>

</div>

<div class="pl-section">

    <h4 class="pl-section-title">
        Plan Limits
    </h4>

    <div class="pl-form-grid">

<?php

$limitFields = array(
    array(
        'max_users',
        'Max Users'
    ),
    array(
        'max_branches',
        'Max Branches'
    ),
    array(
        'max_customers',
        'Max Customers'
    ),
    array(
        'storage_limit_mb',
        'Storage Limit (MB)'
    ),
    array(
        'api_calls_per_month',
        'API Calls / Month'
    ),
    array(
        'sms_per_month',
        'SMS / Month'
    ),
    array(
        'email_per_month',
        'Emails / Month'
    ),
    array(
        'ai_minutes_per_month',
        'AI Minutes / Month'
    )
);

foreach (
    $limitFields as $field
):

?>

        <div class="pl-field">

            <label>
                <?= pl_h($field[1]) ?>
            </label>

            <input
                class="pl-input"
                style="width:100%"
                type="number"
                name="<?= pl_h($field[0]) ?>"
                id="<?= pl_h($field[0]) ?>"
                min="0"
                placeholder="Unlimited"
            >

            <div class="pl-hint">
                Leave empty for unlimited.
            </div>

        </div>

<?php endforeach; ?>

    </div>

</div>

<div class="pl-section">

    <h4 class="pl-section-title">
        Display
    </h4>

    <label class="pl-toggle">

        <span>

            <strong>
                Featured Plan
            </strong>

            <small>
                Highlight this plan in
                plan selection screens.
            </small>

        </span>

        <span class="form-check form-switch m-0">

            <input
                class="form-check-input"
                type="checkbox"
                name="is_featured"
                id="isFeatured"
                value="1"
            >

        </span>

    </label>

</div>

</div>

<div class="pl-modal-footer">

    <button
        type="button"
        class="pl-secondary"
        id="planCancel"
    >
        Cancel
    </button>

    <button
        type="submit"
        class="pl-primary"
        id="planSaveBtn"
    >

        <span class="pl-loader"></span>

        <i class="bi bi-check2-circle"></i>

        <span id="planSaveText">
            Save Plan
        </span>

    </button>

</div>

</form>

</div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
'use strict';

/* =========================================================
   APPROVED SIDEBAR BEHAVIOR
   ========================================================= */

var body =
    document.body;

var toggle =
    document.getElementById(
        'fpSidebarToggle'
    );

var closeBtn =
    document.getElementById(
        'fpSidebarClose'
    );

var overlay =
    document.getElementById(
        'fpSidebarOverlay'
    );

var storageKey =
    'fieldplx_sidebar_collapsed';

function restoreSidebar(){

    if(window.innerWidth < 992){

        body.classList.remove(
            'fp-sidebar-collapsed'
        );

        return;
    }

    if(
        localStorage.getItem(
            storageKey
        ) === '1'
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

            if(window.innerWidth < 992){

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

                    var menu =
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

/* =========================================================
   TOAST
   ========================================================= */

var toast =
    document.getElementById(
        'plToast'
    );

var toastMessage =
    document.getElementById(
        'plToastMessage'
    );

var toastIcon =
    document.getElementById(
        'plToastIcon'
    );

var toastClose =
    document.getElementById(
        'plToastClose'
    );

var toastTimer =
    null;

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

    var icons = {
        success:'bi-check-lg',
        error:'bi-x-lg',
        warning:'bi-exclamation-lg',
        info:'bi-info-lg'
    };

    var t =
        type || 'info';

    toast.className =
        'pl-toast ' + t;

    toastMessage.textContent =
        message || 'Notification';

    toastIcon.className =
        'bi ' +
        (
            icons[t] ||
            icons.info
        );

    toast.classList.add(
        'show'
    );

    toastTimer =
        setTimeout(
            function(){

                toast.classList.remove(
                    'show'
                );

                toastTimer =
                    null;

            },
            typeof duration === 'number'
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

/* =========================================================
   SAFE API
   ========================================================= */

function apiRequest(
    formData
){

    return fetch(
        'api/plans.php',
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

                        var text =
                            (
                                rawText ||
                                ''
                            ).trim();

                        var data =
                            null;

                        try{

                            data =
                                text !== ''
                                    ? JSON.parse(
                                        text
                                    )
                                    : {};

                        }catch(e){

                            var clean =
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
                                clean !== ''
                                    ? 'Server error: ' +
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

/* =========================================================
   MODAL
   ========================================================= */

var modal =
    document.getElementById(
        'planModal'
    );

var form =
    document.getElementById(
        'planForm'
    );

var saveBtn =
    document.getElementById(
        'planSaveBtn'
    );

var saveText =
    document.getElementById(
        'planSaveText'
    );

var limitIds = [
    'max_users',
    'max_branches',
    'max_customers',
    'storage_limit_mb',
    'api_calls_per_month',
    'sms_per_month',
    'email_per_month',
    'ai_minutes_per_month'
];

function closeModal(){

    modal.classList.remove(
        'show'
    );
}

function openModal(
    row
){

    form.reset();

    document.getElementById(
        'planId'
    ).value = '';

    document.getElementById(
        'planModalTitle'
    ).textContent =
        'Add Plan';

    document.getElementById(
        'planPrice'
    ).value =
        '0.00';

    document.getElementById(
        'trialDays'
    ).value =
        '0';

    document.getElementById(
        'planBillingCycle'
    ).value =
        'monthly';

    document.getElementById(
        'planStatus'
    ).value =
        'active';

    document.getElementById(
        'isFeatured'
    ).checked =
        false;

    saveText.textContent =
        'Save Plan';

    limitIds.forEach(
        function(id){

            document.getElementById(
                id
            ).value = '';

        }
    );

    if(row){

        document.getElementById(
            'planId'
        ).value =
            row.id || '';

        document.getElementById(
            'planName'
        ).value =
            row.name || '';

        document.getElementById(
            'planCode'
        ).value =
            row.code || '';

        document.getElementById(
            'planDescription'
        ).value =
            row.description || '';

        document.getElementById(
            'planPrice'
        ).value =
            row.price || '0.00';

        document.getElementById(
            'planCurrency'
        ).value =
            row.currency || '';

        document.getElementById(
            'planBillingCycle'
        ).value =
            row.billing_cycle ||
            'monthly';

        document.getElementById(
            'durationDays'
        ).value =
            row.duration_days ||
            '';

        document.getElementById(
            'trialDays'
        ).value =
            row.trial_days || 0;

        document.getElementById(
            'planStatus'
        ).value =
            row.status ||
            'active';

        document.getElementById(
            'isFeatured'
        ).checked =
            String(
                row.is_featured
            ) === '1';

        limitIds.forEach(
            function(id){

                document.getElementById(
                    id
                ).value =
                    row[id] === null ||
                    typeof row[id] ===
                        'undefined'
                        ? ''
                        : row[id];

            }
        );

        document.getElementById(
            'planModalTitle'
        ).textContent =
            'Edit Plan';

        saveText.textContent =
            'Update Plan';
    }

    modal.classList.add(
        'show'
    );
}

document
    .getElementById(
        'addPlanBtn'
    )
    .addEventListener(
        'click',
        function(){

            openModal(
                null
            );

        }
    );

document
    .getElementById(
        'planModalClose'
    )
    .addEventListener(
        'click',
        closeModal
    );

document
    .getElementById(
        'planCancel'
    )
    .addEventListener(
        'click',
        closeModal
    );

modal.addEventListener(
    'click',
    function(e){

        if(
            e.target === modal
        ){

            closeModal();

        }
    }
);

/* =========================================================
   AUTO CODE
   ========================================================= */

document
    .getElementById(
        'planName'
    )
    .addEventListener(
        'input',
        function(){

            if(
                document
                    .getElementById(
                        'planId'
                    )
                    .value !== ''
            ){
                return;
            }

            document
                .getElementById(
                    'planCode'
                )
                .value =
                    this.value
                    .toLowerCase()
                    .trim()
                    .replace(
                        /[^a-z0-9]+/g,
                        '_'
                    )
                    .replace(
                        /^_+|_+$/g,
                        ''
                    );

        }
    );

/* =========================================================
   EDIT
   ========================================================= */

document
    .querySelectorAll(
        '.plan-edit'
    )
    .forEach(
        function(btn){

            btn.addEventListener(
                'click',
                function(){

                    try{

                        openModal(
                            JSON.parse(
                                btn.getAttribute(
                                    'data-row'
                                )
                            )
                        );

                    }catch(e){

                        showToast(
                            'error',
                            'Unable to load plan details.',
                            3000
                        );

                    }
                }
            );

        }
    );

/* =========================================================
   SAVE
   ========================================================= */

form.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        if(
            !form.checkValidity()
        ){

            showToast(
                'warning',
                'Please complete the required plan fields.',
                3000
            );

            form.reportValidity();

            return;
        }

        saveBtn.disabled =
            true;

        saveBtn.classList.add(
            'loading'
        );

        saveText.textContent =
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
                        'Unable to save plan.'
                    );
                }

                showToast(
                    'success',
                    result.data.message,
                    3000
                );

                closeModal();

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
                    'Unable to save plan.',
                    3000
                );

                saveBtn.disabled =
                    false;

                saveBtn.classList.remove(
                    'loading'
                );

                saveText.textContent =
                    document
                        .getElementById(
                            'planId'
                        )
                        .value !== ''
                            ? 'Update Plan'
                            : 'Save Plan';
            }
        );
    }
);

/* =========================================================
   STATUS
   ========================================================= */

document
    .querySelectorAll(
        '.plan-status'
    )
    .forEach(
        function(btn){

            btn.addEventListener(
                'click',
                function(){

                    var fd =
                        new FormData();

                    fd.append(
                        'csrf_token',
                        '<?= pl_h($csrfToken) ?>'
                    );

                    fd.append(
                        'action',
                        'change_status'
                    );

                    fd.append(
                        'id',
                        btn.getAttribute(
                            'data-id'
                        )
                    );

                    fd.append(
                        'status',
                        btn.getAttribute(
                            'data-status'
                        ) === 'active'
                            ? 'inactive'
                            : 'active'
                    );

                    apiRequest(
                        fd
                    )
                    .then(
                        function(result){

                            if(
                                !result.ok ||
                                !result.data.success
                            ){

                                throw new Error(
                                    result.data.message ||
                                    'Unable to update plan status.'
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
                                'Unable to update plan status.',
                                3000
                            );

                        }
                    );
                }
            );

        }
    );

/* =========================================================
   FEATURED
   ========================================================= */

document
    .querySelectorAll(
        '.plan-featured'
    )
    .forEach(
        function(btn){

            btn.addEventListener(
                'click',
                function(){

                    var fd =
                        new FormData();

                    fd.append(
                        'csrf_token',
                        '<?= pl_h($csrfToken) ?>'
                    );

                    fd.append(
                        'action',
                        'toggle_featured'
                    );

                    fd.append(
                        'id',
                        btn.getAttribute(
                            'data-id'
                        )
                    );

                    fd.append(
                        'is_featured',
                        btn.getAttribute(
                            'data-value'
                        ) === '1'
                            ? '0'
                            : '1'
                    );

                    apiRequest(
                        fd
                    )
                    .then(
                        function(result){

                            if(
                                !result.ok ||
                                !result.data.success
                            ){

                                throw new Error(
                                    result.data.message ||
                                    'Unable to update featured status.'
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
                                'Unable to update featured status.',
                                3000
                            );

                        }
                    );
                }
            );

        }
    );

/* =========================================================
   ARCHIVE
   ========================================================= */

document
    .querySelectorAll(
        '.plan-archive'
    )
    .forEach(
        function(btn){

            btn.addEventListener(
                'click',
                function(){

                    var subscriptions =
                        parseInt(
                            btn.getAttribute(
                                'data-subscriptions'
                            ) || '0',
                            10
                        );

                    if(
                        subscriptions > 0
                    ){

                        showToast(
                            'warning',
                            'This plan has subscription history. Set it inactive or archived instead of removing it.',
                            3000
                        );

                        return;
                    }

                    if(
                        !window.confirm(
                            'Archive this plan?'
                        )
                    ){
                        return;
                    }

                    var fd =
                        new FormData();

                    fd.append(
                        'csrf_token',
                        '<?= pl_h($csrfToken) ?>'
                    );

                    fd.append(
                        'action',
                        'archive_plan'
                    );

                    fd.append(
                        'id',
                        btn.getAttribute(
                            'data-id'
                        )
                    );

                    apiRequest(
                        fd
                    )
                    .then(
                        function(result){

                            if(
                                !result.ok ||
                                !result.data.success
                            ){

                                throw new Error(
                                    result.data.message ||
                                    'Unable to archive plan.'
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
                                'Unable to archive plan.',
                                3000
                            );

                        }
                    );
                }
            );

        }
    );

})();
</script>

</body>
</html>