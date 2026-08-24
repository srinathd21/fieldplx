<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Subscriptions';
$activePage = 'subscriptions';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['subscriptions_csrf'])) {
    $_SESSION['subscriptions_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['subscriptions_csrf'];

function sub_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

function sub_has_column(PDO $pdo, $table, $column)
{
    static $cache = array();

    $key = $table . '.' . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");

    $stmt->execute(array(
        ':table_name' => $table,
        ':column_name' => $column
    ));

    $cache[$key] = ((int)$stmt->fetchColumn() > 0);

    return $cache[$key];
}

$hasDeletedAt = sub_has_column(
    $pdo,
    'subscriptions',
    'deleted_at'
);

$search = isset($_GET['search'])
    ? trim((string)$_GET['search'])
    : '';

$status = isset($_GET['status'])
    ? trim((string)$_GET['status'])
    : '';

$planFilter = isset($_GET['plan_id'])
    ? (int)$_GET['plan_id']
    : 0;

$countryFilter = isset($_GET['country_id'])
    ? (int)$_GET['country_id']
    : 0;

$plans = $pdo->query("
    SELECT
        id,
        name,
        code,
        price,
        currency,
        billing_cycle,
        trial_days,
        max_users,
        max_branches,
        max_customers,
        storage_limit_mb,
        status
    FROM plans
    WHERE deleted_at IS NULL
    ORDER BY
        CASE status
            WHEN 'active' THEN 1
            WHEN 'draft' THEN 2
            WHEN 'inactive' THEN 3
            ELSE 4
        END,
        name
")->fetchAll();

$tenants = $pdo->query("
    SELECT
        t.id,
        t.tenant_code,
        t.display_name,
        t.legal_name,
        t.email,
        t.country_id,
        t.currency_id,
        t.status,
        c.name AS country_name,
        cur.currency_code
    FROM tenants t
    LEFT JOIN countries c
        ON c.id = t.country_id
    LEFT JOIN currencies cur
        ON cur.id = t.currency_id
    WHERE t.deleted_at IS NULL
    ORDER BY t.display_name, t.legal_name
")->fetchAll();

$currencies = $pdo->query("
    SELECT
        id,
        currency_code,
        currency_name,
        symbol
    FROM currencies
    WHERE is_active = 1
    ORDER BY currency_code
")->fetchAll();

$countries = $pdo->query("
    SELECT
        id,
        name,
        iso2
    FROM countries
    WHERE is_active = 1
    ORDER BY name
")->fetchAll();

$sql = "
    SELECT
        s.id,
        s.tenant_id,
        s.plan_id,
        s.currency_id,
        s.amount,
        s.start_date,
        s.expiry_date,
        s.trial_end_date,
        s.auto_renew,
        s.max_users_override,
        s.max_branches_override,
        s.max_customers_override,
        s.storage_limit_mb_override,
        s.status,
        s.created_by,
        s.created_at,
        s.updated_at,
        t.tenant_code,
        t.display_name AS tenant_name,
        t.legal_name,
        t.email AS tenant_email,
        t.country_id,
        p.name AS plan_name,
        p.code AS plan_code,
        p.billing_cycle AS plan_billing_cycle,
        p.max_users AS plan_max_users,
        p.max_branches AS plan_max_branches,
        p.max_customers AS plan_max_customers,
        p.storage_limit_mb AS plan_storage_limit_mb,
        cur.currency_code,
        cur.symbol AS currency_symbol,
        c.name AS country_name
    FROM subscriptions s
    INNER JOIN tenants t
        ON t.id = s.tenant_id
       AND t.deleted_at IS NULL
    LEFT JOIN plans p
        ON p.id = s.plan_id
       AND p.deleted_at IS NULL
    LEFT JOIN currencies cur
        ON cur.id = s.currency_id
    LEFT JOIN countries c
        ON c.id = t.country_id
    WHERE 1=1
";

if ($hasDeletedAt) {
    $sql .= " AND s.deleted_at IS NULL ";
}

$params = array();

if ($search !== '') {
    $sql .= "
        AND (
            t.display_name LIKE :search
            OR t.legal_name LIKE :search
            OR t.tenant_code LIKE :search
            OR t.email LIKE :search
            OR p.name LIKE :search
            OR p.code LIKE :search
            OR cur.currency_code LIKE :search
            OR c.name LIKE :search
        )
    ";

    $params[':search'] =
        '%' . $search . '%';
}

if (
    in_array(
        $status,
        array(
            'trial',
            'active',
            'expired',
            'cancelled',
            'suspended'
        ),
        true
    )
) {
    $sql .= " AND s.status = :status ";
    $params[':status'] = $status;
}

if ($planFilter > 0) {
    $sql .= " AND s.plan_id = :plan_id ";
    $params[':plan_id'] = $planFilter;
}

if ($countryFilter > 0) {
    $sql .= " AND t.country_id = :country_id ";
    $params[':country_id'] = $countryFilter;
}

$sql .= "
    ORDER BY
        CASE s.status
            WHEN 'active' THEN 1
            WHEN 'trial' THEN 2
            WHEN 'suspended' THEN 3
            WHEN 'expired' THEN 4
            ELSE 5
        END,
        COALESCE(s.expiry_date, '9999-12-31') ASC,
        s.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subscriptions = $stmt->fetchAll();

$statsSql = "
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active_count,
        SUM(status = 'trial') AS trial_count,
        SUM(status = 'expired') AS expired_count,
        SUM(status = 'suspended') AS suspended_count
    FROM subscriptions
    WHERE 1=1
";

if ($hasDeletedAt) {
    $statsSql .= " AND deleted_at IS NULL ";
}

$stats = $pdo->query($statsSql)->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>
<title><?= sub_h($pageTitle) ?> - FieldPlx</title>

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

button,
input,
select,
textarea{
    font-family:inherit
}

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

.fp-content{
    padding:18px;
    background:#fff
}

/* Page */
.sub-page{
    display:grid;
    gap:16px
}

.sub-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px
}

.sub-title{
    margin:0;
    color:#111827;
    font-size:20px;
    font-weight:800
}

.sub-description{
    margin-top:4px;
    max-width:780px;
    color:#77718e;
    font-size:10px;
    line-height:1.55
}

.sub-header-actions{
    display:flex;
    align-items:center;
    gap:8px
}

.sub-primary,
.sub-secondary{
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

.sub-primary{
    border:0;
    background:linear-gradient(135deg,#7c3aed,#6d28d9);
    color:#fff;
    box-shadow:0 8px 20px rgba(109,40,217,.18)
}

.sub-secondary{
    border:1px solid #dcd5ef;
    background:#fff;
    color:#5f5870
}

.sub-primary:hover{
    background:linear-gradient(135deg,#6d28d9,#5b21b6)
}

.sub-secondary:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.sub-primary:disabled,
.sub-secondary:disabled{
    opacity:.65;
    cursor:not-allowed
}

/* Summary */
.sub-stats{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px
}

.sub-stat{
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

.sub-stat:hover{
    border-color:#cfc3ef;
    background:linear-gradient(180deg,#fff 0%,#f8f4ff 100%)
}

.sub-stat-icon{
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

.sub-stat-content{
    min-width:0;
    display:block
}

.sub-stat-label{
    display:block;
    color:#9a94ae;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
    line-height:1.3
}

.sub-stat-value{
    margin-top:2px;
    display:block;
    color:#111827;
    font-size:20px;
    font-weight:800;
    line-height:1.2
}

.sub-stat-note{
    margin-top:2px;
    display:block;
    color:#9d96ac;
    font-size:7.5px;
    line-height:1.35
}

/* Table */
.sub-card{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 24px rgba(37,29,80,.05)
}

.sub-toolbar{
    padding:13px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    background:#fbf9ff;
    border-bottom:1px solid #ece7f7
}

.sub-toolbar-left{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap
}

.sub-search{
    position:relative
}

.sub-search i{
    position:absolute;
    left:11px;
    top:50%;
    transform:translateY(-50%);
    color:#948da7;
    font-size:12px
}

.sub-input,
.sub-select{
    height:39px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    background:#fff;
    color:#312b47;
    font-size:10px;
    outline:0
}

.sub-input{
    padding:8px 11px
}

.sub-search .sub-input{
    width:250px;
    padding-left:33px
}

.sub-select{
    padding:8px 30px 8px 10px
}

.sub-input:focus,
.sub-select:focus,
.sub-textarea:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10)
}

.sub-table-wrap{
    overflow:auto;
    scrollbar-width:thin;
    scrollbar-color:#bcb4ca transparent
}

.sub-table-wrap::-webkit-scrollbar{
    width:4px;
    height:4px
}

.sub-table-wrap::-webkit-scrollbar-track{
    background:transparent
}

.sub-table-wrap::-webkit-scrollbar-thumb{
    background:#bcb4ca;
    border-radius:999px
}

.sub-table-wrap::-webkit-scrollbar-thumb:hover{
    background:#9f96b2
}

.sub-table{
    width:100%;
    min-width:1260px;
    border-collapse:collapse
}

.sub-table th{
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

.sub-table td{
    padding:10px 12px;
    border-bottom:1px solid #f0ecf7;
    color:#433d54;
    font-size:9px;
    vertical-align:middle
}

.sub-table tbody tr:hover{
    background:#fcfbff
}

.sub-tenant{
    display:flex;
    align-items:center;
    gap:8px;
    min-width:180px
}

.sub-row-icon{
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

.sub-tenant-name,
.sub-plan-name{
    display:block;
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.sub-tenant-code,
.sub-plan-code{
    display:block;
    margin-top:2px;
    color:#9b94a7;
    font-size:8px
}

.sub-amount{
    display:block;
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.sub-small{
    margin-top:2px;
    display:block;
    color:#9b94a7;
    font-size:8px
}

.sub-date{
    white-space:nowrap
}

.sub-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700;
    text-transform:capitalize
}

.sub-badge.active{
    background:#ecfdf5;
    color:#047857
}

.sub-badge.trial{
    background:#eef2ff;
    color:#4338ca
}

.sub-badge.expired{
    background:#f3f4f6;
    color:#6b7280
}

.sub-badge.cancelled{
    background:#fef2f2;
    color:#b91c1c
}

.sub-badge.suspended{
    background:#fff7ed;
    color:#c2410c
}

.sub-renew{
    display:inline-flex;
    align-items:center;
    gap:4px;
    color:#6d28d9;
    font-size:8px;
    font-weight:700
}

.sub-actions{
    display:flex;
    align-items:center;
    gap:5px
}

.sub-icon-btn{
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

.sub-icon-btn:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.sub-icon-btn.danger:hover{
    border-color:#fecaca;
    background:#fef2f2;
    color:#dc2626
}

.sub-empty{
    padding:36px 15px;
    text-align:center;
    color:#928aa5;
    font-size:10px
}

/* Modal */
.sub-modal-backdrop{
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

.sub-modal-backdrop.show{
    display:flex
}

.sub-modal{
    width:min(700px,100%);
    max-height:calc(100vh - 36px);
    overflow:auto;
    border:1px solid #ded7ef;
    border-radius:15px;
    background:#fff;
    box-shadow:0 24px 60px rgba(28,20,70,.22);
    scrollbar-width:thin;
    scrollbar-color:#bcb4ca transparent
}

.sub-modal::-webkit-scrollbar{
    width:4px;
    height:4px
}

.sub-modal::-webkit-scrollbar-track{
    background:transparent
}

.sub-modal::-webkit-scrollbar-thumb{
    background:#bcb4ca;
    border-radius:999px
}

.sub-modal-header{
    padding:13px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff
}

.sub-modal-title-wrap{
    display:flex;
    align-items:center;
    gap:10px
}

.sub-modal-icon{
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

.sub-modal-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800
}

.sub-modal-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px
}

.sub-modal-close{
    width:30px;
    height:30px;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#fff;
    color:#6d657d;
    cursor:pointer
}

.sub-modal-close:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.sub-modal-body{
    padding:15px
}

.sub-section{
    margin-bottom:13px;
    padding:12px;
    border:1px solid #e2dcf2;
    border-radius:10px;
    background:#fbf9ff
}

.sub-section:last-child{
    margin-bottom:0
}

.sub-section-title{
    margin:0 0 10px;
    color:#393248;
    font-size:9px;
    font-weight:700
}

.sub-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:13px
}

.sub-field.full{
    grid-column:1/-1
}

.sub-field label{
    margin-bottom:6px;
    display:block;
    color:#4c465f;
    font-size:9px;
    font-weight:700
}

.sub-required{
    color:#dc2626
}

.sub-textarea{
    width:100%;
    min-height:78px;
    resize:vertical;
    padding:9px 11px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    outline:0;
    background:#fff;
    color:#312b47;
    font-size:10px
}

.sub-hint{
    margin-top:4px;
    color:#9a94aa;
    font-size:8px;
    line-height:1.45
}

.sub-toggle{
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

.sub-toggle strong{
    display:block;
    color:#393248;
    font-size:9px
}

.sub-toggle small{
    margin-top:2px;
    display:block;
    color:#9a94aa;
    font-size:8px
}

.sub-modal-footer{
    padding:12px 15px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid #ece7f7;
    background:#fbf9ff
}

/* Loader */
.sub-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:subSpin .75s linear infinite
}

.sub-primary.loading .sub-loader{
    display:inline-block
}

@keyframes subSpin{
    to{transform:rotate(360deg)}
}

/* Toast */
.sub-toast{
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
    transition:
        opacity .2s ease,
        transform .2s ease,
        visibility .2s ease;
    font-size:10px;
    line-height:1.45
}

.sub-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0)
}

.sub-toast.success{background:#059669}
.sub-toast.error{background:#dc2626}
.sub-toast.warning{background:#d97706}
.sub-toast.info{background:#4f46e5}

.sub-toast-icon{
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

.sub-toast-message{
    flex:1;
    min-width:0;
    font-weight:600
}

.sub-toast-close{
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

@media(max-width:1100px){
    .sub-stats{
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
    .sub-header{
        flex-direction:column
    }

    .sub-header-actions{
        width:100%
    }

    .sub-header-actions .sub-primary{
        width:100%
    }

    .sub-toolbar{
        align-items:stretch
    }

    .sub-toolbar-left{
        width:100%
    }

    .sub-search{
        width:100%
    }

    .sub-search .sub-input{
        width:100%
    }

    .sub-form-grid{
        grid-template-columns:1fr
    }

    .sub-field.full{
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

    .sub-stats{
        grid-template-columns:1fr
    }

    .sub-stat{
        min-height:82px
    }

    .sub-modal-footer{
        flex-direction:column-reverse
    }

    .sub-modal-footer .sub-primary,
    .sub-modal-footer .sub-secondary{
        width:100%
    }

    .sub-toast{
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

<div class="sub-page">

<div class="sub-header">

    <div>

        <h2 class="sub-title">
            Subscriptions
        </h2>

        <div class="sub-description">
            Manage tenant subscriptions, plans, billing amount,
            subscription periods, trials, auto-renewal and
            tenant-specific plan limit overrides.
        </div>

    </div>

    <div class="sub-header-actions">

        <button
            type="button"
            class="sub-primary"
            id="addSubscriptionBtn"
        >
            <i class="bi bi-plus-lg"></i>
            Add Subscription
        </button>

    </div>

</div>

<div class="sub-stats">

<?php
$cards = array(
    array(
        'Total Subscriptions',
        'bi bi-credit-card',
        (int)($stats['total'] ?? 0),
        'All subscription records'
    ),
    array(
        'Active',
        'bi bi-check-circle',
        (int)($stats['active_count'] ?? 0),
        'Currently active'
    ),
    array(
        'Trial',
        'bi bi-hourglass-split',
        (int)($stats['trial_count'] ?? 0),
        'Trial subscriptions'
    ),
    array(
        'Expired',
        'bi bi-clock-history',
        (int)($stats['expired_count'] ?? 0),
        'Expired subscriptions'
    ),
    array(
        'Suspended',
        'bi bi-pause-circle',
        (int)($stats['suspended_count'] ?? 0),
        'Temporarily suspended'
    )
);

foreach ($cards as $card):
?>

<div class="sub-stat">

    <span class="sub-stat-icon">
        <i class="<?= sub_h($card[1]) ?>"></i>
    </span>

    <span class="sub-stat-content">

        <span class="sub-stat-label">
            <?= sub_h($card[0]) ?>
        </span>

        <span class="sub-stat-value">
            <?= number_format($card[2]) ?>
        </span>

        <span class="sub-stat-note">
            <?= sub_h($card[3]) ?>
        </span>

    </span>

</div>

<?php endforeach; ?>

</div>

<section class="sub-card">

<form
    method="get"
    class="sub-toolbar"
>

<div class="sub-toolbar-left">

    <div class="sub-search">

        <i class="bi bi-search"></i>

        <input
            type="text"
            class="sub-input"
            name="search"
            value="<?= sub_h($search) ?>"
            placeholder="Search tenant, plan or currency"
        >

    </div>

    <select
        class="sub-select"
        name="status"
        onchange="this.form.submit()"
    >

        <option value="">
            All Status
        </option>

        <?php
        foreach (
            array(
                'trial',
                'active',
                'expired',
                'cancelled',
                'suspended'
            ) as $optionStatus
        ):
        ?>

        <option
            value="<?= sub_h($optionStatus) ?>"
            <?= $status === $optionStatus
                ? 'selected'
                : '' ?>
        >
            <?= sub_h(
                ucfirst($optionStatus)
            ) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <select
        class="sub-select"
        name="plan_id"
        onchange="this.form.submit()"
    >

        <option value="">
            All Plans
        </option>

        <?php foreach ($plans as $plan): ?>

        <option
            value="<?= (int)$plan['id'] ?>"
            <?= $planFilter === (int)$plan['id']
                ? 'selected'
                : '' ?>
        >
            <?= sub_h($plan['name']) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <select
        class="sub-select"
        name="country_id"
        onchange="this.form.submit()"
    >

        <option value="">
            All Countries
        </option>

        <?php foreach ($countries as $country): ?>

        <option
            value="<?= (int)$country['id'] ?>"
            <?= $countryFilter === (int)$country['id']
                ? 'selected'
                : '' ?>
        >
            <?= sub_h($country['name']) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <?php
    if (
        $search !== '' ||
        $status !== '' ||
        $planFilter > 0 ||
        $countryFilter > 0
    ):
    ?>

    <a
        href="subscriptions.php"
        class="sub-secondary"
    >
        <i class="bi bi-x-lg"></i>
        Clear
    </a>

    <?php endif; ?>

</div>

<button
    type="submit"
    class="sub-secondary"
>
    <i class="bi bi-funnel"></i>
    Filter
</button>

</form>

<div class="sub-table-wrap">

<table class="sub-table">

<thead>

<tr>
    <th>S/No</th>
    <th>Tenant</th>
    <th>Plan</th>
    <th>Amount</th>
    <th>Start Date</th>
    <th>Expiry Date</th>
    <th>Trial End</th>
    <th>Auto Renew</th>
    <th>Status</th>
    <th>Action</th>
</tr>

</thead>

<tbody>

<?php if (!$subscriptions): ?>

<tr>
    <td colspan="10">

        <div class="sub-empty">
            No subscriptions found.
        </div>

    </td>
</tr>

<?php else: ?>

<?php
foreach (
    $subscriptions as $index => $subscription
):
?>

<tr>

    <td>
        <?= $index + 1 ?>
    </td>

    <td>

        <div class="sub-tenant">

            <span class="sub-row-icon">
                <i class="bi bi-building"></i>
            </span>

            <span>

                <span class="sub-tenant-name">
                    <?= sub_h(
                        $subscription['tenant_name']
                        ?: $subscription['legal_name']
                    ) ?>
                </span>

                <span class="sub-tenant-code">

                    <?= sub_h(
                        $subscription['tenant_code']
                    ) ?>

                    <?php
                    if (
                        !empty(
                            $subscription['country_name']
                        )
                    ):
                    ?>
                        ·
                        <?= sub_h(
                            $subscription['country_name']
                        ) ?>
                    <?php endif; ?>

                </span>

            </span>

        </div>

    </td>

    <td>

        <span class="sub-plan-name">
            <?= sub_h(
                $subscription['plan_name']
                ?: 'No Plan'
            ) ?>
        </span>

        <span class="sub-plan-code">
            <?= sub_h(
                $subscription['plan_code']
                ?: '-'
            ) ?>
        </span>

    </td>

    <td>

        <span class="sub-amount">

            <?= sub_h(
                $subscription['currency_code']
                ?: ''
            ) ?>

            <?= number_format(
                (float)$subscription['amount'],
                2
            ) ?>

        </span>

    </td>

    <td class="sub-date">

        <?= $subscription['start_date']
            ? sub_h(
                date(
                    'd M Y',
                    strtotime(
                        $subscription['start_date']
                    )
                )
            )
            : '-' ?>

    </td>

    <td class="sub-date">

        <?= $subscription['expiry_date']
            ? sub_h(
                date(
                    'd M Y',
                    strtotime(
                        $subscription['expiry_date']
                    )
                )
            )
            : 'No expiry' ?>

    </td>

    <td class="sub-date">

        <?= $subscription['trial_end_date']
            ? sub_h(
                date(
                    'd M Y',
                    strtotime(
                        $subscription['trial_end_date']
                    )
                )
            )
            : '-' ?>

    </td>

    <td>

        <?php
        if (
            (int)$subscription['auto_renew'] === 1
        ):
        ?>

        <span class="sub-renew">
            <i class="bi bi-arrow-repeat"></i>
            Enabled
        </span>

        <?php else: ?>

        <span class="sub-small">
            Disabled
        </span>

        <?php endif; ?>

    </td>

    <td>

        <span
            class="sub-badge <?= sub_h(
                $subscription['status']
            ) ?>"
        >
            <?= sub_h(
                ucfirst(
                    $subscription['status']
                )
            ) ?>
        </span>

    </td>

    <td>

        <div class="sub-actions">

            <button
                type="button"
                class="sub-icon-btn subscription-edit"
                title="Edit"
                data-row='<?= sub_h(
                    json_encode(
                        $subscription,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    )
                ) ?>'
            >
                <i class="bi bi-pencil"></i>
            </button>

            <button
                type="button"
                class="sub-icon-btn subscription-status"
                title="<?= $subscription['status'] === 'active'
                    ? 'Suspend'
                    : 'Activate' ?>"
                data-id="<?= (int)$subscription['id'] ?>"
                data-status="<?= sub_h(
                    $subscription['status']
                ) ?>"
            >
                <i
                    class="bi <?= $subscription['status'] === 'active'
                        ? 'bi-pause-circle'
                        : 'bi-play-circle' ?>"
                ></i>
            </button>

            <button
                type="button"
                class="sub-icon-btn danger subscription-cancel"
                title="Cancel Subscription"
                data-id="<?= (int)$subscription['id'] ?>"
                data-status="<?= sub_h(
                    $subscription['status']
                ) ?>"
            >
                <i class="bi bi-x-circle"></i>
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

<!-- Toast -->
<div
    id="subToast"
    class="sub-toast"
>

    <span class="sub-toast-icon">
        <i
            id="subToastIcon"
            class="bi bi-check-lg"
        ></i>
    </span>

    <span
        id="subToastMessage"
        class="sub-toast-message"
    ></span>

    <button
        type="button"
        id="subToastClose"
        class="sub-toast-close"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<!-- Modal -->
<div
    class="sub-modal-backdrop"
    id="subscriptionModal"
>

<div class="sub-modal">

<form
    id="subscriptionForm"
    novalidate
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= sub_h($csrfToken) ?>"
>

<input
    type="hidden"
    name="action"
    value="save_subscription"
>

<input
    type="hidden"
    name="id"
    id="subscriptionId"
>

<div class="sub-modal-header">

    <div class="sub-modal-title-wrap">

        <span class="sub-modal-icon">
            <i class="bi bi-credit-card"></i>
        </span>

        <span>

            <h3
                class="sub-modal-title"
                id="subscriptionModalTitle"
            >
                Add Subscription
            </h3>

            <span class="sub-modal-subtitle">
                Configure tenant plan,
                subscription period and limits
            </span>

        </span>

    </div>

    <button
        type="button"
        class="sub-modal-close"
        id="subscriptionModalClose"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<div class="sub-modal-body">

<div class="sub-section">

<h4 class="sub-section-title">
    Tenant & Plan
</h4>

<div class="sub-form-grid">

<div class="sub-field full">

    <label>
        Tenant
        <span class="sub-required">*</span>
    </label>

    <select
        class="sub-select"
        style="width:100%"
        name="tenant_id"
        id="subscriptionTenant"
        required
    >

        <option value="">
            Select Tenant
        </option>

        <?php foreach ($tenants as $tenant): ?>

        <option
            value="<?= (int)$tenant['id'] ?>"
            data-currency-id="<?= (int)$tenant['currency_id'] ?>"
        >
            <?= sub_h(
                $tenant['display_name']
                ?: $tenant['legal_name']
            ) ?>
            -
            <?= sub_h(
                $tenant['tenant_code']
            ) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="sub-field">

    <label>
        Plan
        <span class="sub-required">*</span>
    </label>

    <select
        class="sub-select"
        style="width:100%"
        name="plan_id"
        id="subscriptionPlan"
        required
    >

        <option value="">
            Select Plan
        </option>

        <?php foreach ($plans as $plan): ?>

        <option
            value="<?= (int)$plan['id'] ?>"
            data-price="<?= sub_h($plan['price']) ?>"
            data-currency="<?= sub_h($plan['currency']) ?>"
            data-billing-cycle="<?= sub_h($plan['billing_cycle']) ?>"
            data-trial-days="<?= (int)$plan['trial_days'] ?>"
            data-max-users="<?= sub_h($plan['max_users']) ?>"
            data-max-branches="<?= sub_h($plan['max_branches']) ?>"
            data-max-customers="<?= sub_h($plan['max_customers']) ?>"
            data-storage="<?= sub_h($plan['storage_limit_mb']) ?>"
        >
            <?= sub_h($plan['name']) ?>
            (<?= sub_h($plan['code']) ?>)
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="sub-field">

    <label>
        Currency
        <span class="sub-required">*</span>
    </label>

    <select
        class="sub-select"
        style="width:100%"
        name="currency_id"
        id="subscriptionCurrency"
        required
    >

        <option value="">
            Select Currency
        </option>

        <?php foreach ($currencies as $currency): ?>

        <option
            value="<?= (int)$currency['id'] ?>"
            data-code="<?= sub_h(
                $currency['currency_code']
            ) ?>"
        >
            <?= sub_h(
                $currency['currency_code']
            ) ?>
            -
            <?= sub_h(
                $currency['currency_name']
            ) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="sub-field">

    <label>
        Amount
        <span class="sub-required">*</span>
    </label>

    <input
        class="sub-input"
        style="width:100%"
        type="number"
        name="amount"
        id="subscriptionAmount"
        min="0"
        step="0.01"
        value="0.00"
        required
    >

</div>

<div class="sub-field">

    <label>
        Status
        <span class="sub-required">*</span>
    </label>

    <select
        class="sub-select"
        style="width:100%"
        name="status"
        id="subscriptionStatus"
        required
    >

        <option value="active">
            Active
        </option>

        <option value="trial">
            Trial
        </option>

        <option value="expired">
            Expired
        </option>

        <option value="cancelled">
            Cancelled
        </option>

        <option value="suspended">
            Suspended
        </option>

    </select>

</div>

</div>

</div>

<div class="sub-section">

<h4 class="sub-section-title">
    Subscription Period
</h4>

<div class="sub-form-grid">

<div class="sub-field">

    <label>
        Start Date
        <span class="sub-required">*</span>
    </label>

    <input
        class="sub-input"
        style="width:100%"
        type="date"
        name="start_date"
        id="startDate"
        required
    >

</div>

<div class="sub-field">

    <label>
        Expiry Date
    </label>

    <input
        class="sub-input"
        style="width:100%"
        type="date"
        name="expiry_date"
        id="expiryDate"
    >

    <div class="sub-hint">
        Leave empty for lifetime/no-expiry plans.
    </div>

</div>

<div class="sub-field">

    <label>
        Trial End Date
    </label>

    <input
        class="sub-input"
        style="width:100%"
        type="date"
        name="trial_end_date"
        id="trialEndDate"
    >

</div>

<div class="sub-field">

    <label class="sub-toggle">

        <span>

            <strong>
                Auto Renew
            </strong>

            <small>
                Automatically renew this subscription.
            </small>

        </span>

        <span class="form-check form-switch m-0">

            <input
                class="form-check-input"
                type="checkbox"
                name="auto_renew"
                id="autoRenew"
                value="1"
            >

        </span>

    </label>

</div>

</div>

</div>

<div class="sub-section">

<h4 class="sub-section-title">
    Plan Limit Overrides
</h4>

<div class="sub-form-grid">

<div class="sub-field">

    <label>
        Max Users Override
    </label>

    <input
        class="sub-input"
        style="width:100%"
        type="number"
        name="max_users_override"
        id="maxUsersOverride"
        min="0"
        placeholder="Use plan limit"
    >

</div>

<div class="sub-field">

    <label>
        Max Branches Override
    </label>

    <input
        class="sub-input"
        style="width:100%"
        type="number"
        name="max_branches_override"
        id="maxBranchesOverride"
        min="0"
        placeholder="Use plan limit"
    >

</div>

<div class="sub-field">

    <label>
        Max Customers Override
    </label>

    <input
        class="sub-input"
        style="width:100%"
        type="number"
        name="max_customers_override"
        id="maxCustomersOverride"
        min="0"
        placeholder="Use plan limit"
    >

</div>

<div class="sub-field">

    <label>
        Storage Override (MB)
    </label>

    <input
        class="sub-input"
        style="width:100%"
        type="number"
        name="storage_limit_mb_override"
        id="storageOverride"
        min="0"
        placeholder="Use plan limit"
    >

</div>

<div class="sub-field full">

    <div class="sub-hint" id="planLimitHint">
        Leave override fields empty to inherit
        limits from the selected plan.
    </div>

</div>

</div>

</div>

</div>

<div class="sub-modal-footer">

<button
    type="button"
    class="sub-secondary"
    id="subscriptionCancel"
>
    Cancel
</button>

<button
    type="submit"
    class="sub-primary"
    id="subscriptionSaveBtn"
>

    <span class="sub-loader"></span>

    <i class="bi bi-check2-circle"></i>

    <span id="subscriptionSaveText">
        Save Subscription
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
var toast=document.getElementById('subToast');
var toastMessage=document.getElementById('subToastMessage');
var toastIcon=document.getElementById('subToastIcon');
var toastClose=document.getElementById('subToastClose');
var toastTimer=null;

function showToast(
    type,
    message,
    duration
){

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

    toast.className=
        'sub-toast '+t;

    toastMessage.textContent=
        message||'Notification';

    toastIcon.className=
        'bi '+(icons[t]||icons.info);

    toast.classList.add('show');

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
            clearTimeout(toastTimer);
        }

        toast.classList.remove(
            'show'
        );

    }
);

/* API */
function apiRequest(formData){

    return fetch(
        'api/subscriptions.php',
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
                        (rawText||'').trim();

                    var data=null;

                    try{

                        data=
                            text!==''
                                ? JSON.parse(text)
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
                        ok:response.ok,
                        status:response.status,
                        data:data
                    };
                }
            );
        }
    );
}

/* Modal */
var modal=
    document.getElementById(
        'subscriptionModal'
    );

var form=
    document.getElementById(
        'subscriptionForm'
    );

var saveBtn=
    document.getElementById(
        'subscriptionSaveBtn'
    );

var saveText=
    document.getElementById(
        'subscriptionSaveText'
    );

function todayIso(){

    var d=new Date();

    var year=d.getFullYear();

    var month=String(
        d.getMonth()+1
    ).padStart(2,'0');

    var day=String(
        d.getDate()
    ).padStart(2,'0');

    return year+'-'+month+'-'+day;
}

function closeModal(){

    modal.classList.remove(
        'show'
    );
}

function resetModal(){

    form.reset();

    document.getElementById(
        'subscriptionId'
    ).value='';

    document.getElementById(
        'subscriptionModalTitle'
    ).textContent=
        'Add Subscription';

    document.getElementById(
        'subscriptionStatus'
    ).value=
        'active';

    document.getElementById(
        'subscriptionAmount'
    ).value=
        '0.00';

    document.getElementById(
        'startDate'
    ).value=
        todayIso();

    document.getElementById(
        'expiryDate'
    ).value='';

    document.getElementById(
        'trialEndDate'
    ).value='';

    document.getElementById(
        'autoRenew'
    ).checked=
        false;

    saveText.textContent=
        'Save Subscription';

    document.getElementById(
        'planLimitHint'
    ).textContent=
        'Leave override fields empty to inherit limits from the selected plan.';
}

function openModal(row){

    resetModal();

    if(row){

        document.getElementById(
            'subscriptionId'
        ).value=
            row.id||'';

        document.getElementById(
            'subscriptionTenant'
        ).value=
            row.tenant_id||'';

        document.getElementById(
            'subscriptionPlan'
        ).value=
            row.plan_id||'';

        document.getElementById(
            'subscriptionCurrency'
        ).value=
            row.currency_id||'';

        document.getElementById(
            'subscriptionAmount'
        ).value=
            row.amount||'0.00';

        document.getElementById(
            'startDate'
        ).value=
            row.start_date||'';

        document.getElementById(
            'expiryDate'
        ).value=
            row.expiry_date||'';

        document.getElementById(
            'trialEndDate'
        ).value=
            row.trial_end_date||'';

        document.getElementById(
            'autoRenew'
        ).checked=
            String(
                row.auto_renew
            )==='1';

        document.getElementById(
            'subscriptionStatus'
        ).value=
            row.status||'active';

        document.getElementById(
            'maxUsersOverride'
        ).value=
            row.max_users_override===null
                ? ''
                : row.max_users_override;

        document.getElementById(
            'maxBranchesOverride'
        ).value=
            row.max_branches_override===null
                ? ''
                : row.max_branches_override;

        document.getElementById(
            'maxCustomersOverride'
        ).value=
            row.max_customers_override===null
                ? ''
                : row.max_customers_override;

        document.getElementById(
            'storageOverride'
        ).value=
            row.storage_limit_mb_override===null
                ? ''
                : row.storage_limit_mb_override;

        document.getElementById(
            'subscriptionModalTitle'
        ).textContent=
            'Edit Subscription';

        saveText.textContent=
            'Update Subscription';

        updatePlanHint();

    }

    modal.classList.add(
        'show'
    );
}

document
.getElementById(
    'addSubscriptionBtn'
)
.addEventListener(
    'click',
    function(){
        openModal(null);
    }
);

document
.getElementById(
    'subscriptionModalClose'
)
.addEventListener(
    'click',
    closeModal
);

document
.getElementById(
    'subscriptionCancel'
)
.addEventListener(
    'click',
    closeModal
);

modal.addEventListener(
    'click',
    function(e){

        if(e.target===modal){
            closeModal();
        }
    }
);

document
.querySelectorAll(
    '.subscription-edit'
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
                        'Unable to load subscription details.',
                        3000
                    );

                }
            }
        );
    }
);

/* Tenant currency */
var tenantSelect=
    document.getElementById(
        'subscriptionTenant'
    );

var currencySelect=
    document.getElementById(
        'subscriptionCurrency'
    );

tenantSelect.addEventListener(
    'change',
    function(){

        var option=
            tenantSelect.options[
                tenantSelect.selectedIndex
            ];

        var currencyId=
            option
                ? option.getAttribute(
                    'data-currency-id'
                )
                : '';

        if(currencyId){
            currencySelect.value=
                currencyId;
        }
    }
);

/* Plan defaults */
var planSelect=
    document.getElementById(
        'subscriptionPlan'
    );

function addDays(
    dateString,
    days
){

    if(!dateString){
        return '';
    }

    var parts=
        dateString.split('-');

    if(parts.length!==3){
        return '';
    }

    var d=
        new Date(
            parseInt(parts[0],10),
            parseInt(parts[1],10)-1,
            parseInt(parts[2],10)
        );

    d.setDate(
        d.getDate()+
        parseInt(days,10)
    );

    return [
        d.getFullYear(),
        String(
            d.getMonth()+1
        ).padStart(2,'0'),
        String(
            d.getDate()
        ).padStart(2,'0')
    ].join('-');
}

function updatePlanHint(){

    var option=
        planSelect.options[
            planSelect.selectedIndex
        ];

    if(!option||!option.value){
        return;
    }

    var users=
        option.getAttribute(
            'data-max-users'
        )||'Unlimited';

    var branches=
        option.getAttribute(
            'data-max-branches'
        )||'Unlimited';

    var customers=
        option.getAttribute(
            'data-max-customers'
        )||'Unlimited';

    var storage=
        option.getAttribute(
            'data-storage'
        )||'Unlimited';

    document.getElementById(
        'planLimitHint'
    ).textContent=
        'Plan defaults — Users: '+
        users+
        ' · Branches: '+
        branches+
        ' · Customers: '+
        customers+
        ' · Storage: '+
        storage+
        ' MB. Leave overrides empty to inherit these values.';
}

planSelect.addEventListener(
    'change',
    function(){

        var option=
            planSelect.options[
                planSelect.selectedIndex
            ];

        if(!option||!option.value){
            return;
        }

        var editing=
            document.getElementById(
                'subscriptionId'
            ).value!=='';

        if(!editing){

            document.getElementById(
                'subscriptionAmount'
            ).value=
                option.getAttribute(
                    'data-price'
                )||'0.00';

            var planCurrency=
                option.getAttribute(
                    'data-currency'
                )||'';

            if(planCurrency){

                Array.prototype
                .forEach.call(
                    currencySelect.options,
                    function(currencyOption){

                        if(
                            currencyOption.getAttribute(
                                'data-code'
                            )===planCurrency
                        ){

                            currencySelect.value=
                                currencyOption.value;
                        }
                    }
                );
            }

            var trialDays=
                parseInt(
                    option.getAttribute(
                        'data-trial-days'
                    )||'0',
                    10
                );

            if(trialDays>0){

                document.getElementById(
                    'trialEndDate'
                ).value=
                    addDays(
                        document.getElementById(
                            'startDate'
                        ).value,
                        trialDays
                    );

            }else{

                document.getElementById(
                    'trialEndDate'
                ).value='';
            }
        }

        updatePlanHint();
    }
);

/* Save */
form.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        if(!form.checkValidity()){

            showToast(
                'warning',
                'Please complete the required subscription fields.',
                3000
            );

            form.reportValidity();

            return;
        }

        saveBtn.disabled=true;

        saveBtn.classList.add(
            'loading'
        );

        saveText.textContent=
            'Saving...';

        apiRequest(
            new FormData(form)
        )
        .then(
            function(result){

                if(
                    !result.ok ||
                    !result.data.success
                ){

                    throw new Error(
                        result.data.message ||
                        'Unable to save subscription.'
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
                    'Unable to save subscription.',
                    3000
                );

                saveBtn.disabled=false;

                saveBtn.classList.remove(
                    'loading'
                );

                saveText.textContent=
                    document.getElementById(
                        'subscriptionId'
                    ).value!==''
                        ? 'Update Subscription'
                        : 'Save Subscription';
            }
        );
    }
);

/* Status */
document
.querySelectorAll(
    '.subscription-status'
)
.forEach(
    function(btn){

        btn.addEventListener(
            'click',
            function(){

                var fd=
                    new FormData();

                fd.append(
                    'csrf_token',
                    '<?= sub_h($csrfToken) ?>'
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
                    )==='active'
                        ? 'suspended'
                        : 'active'
                );

                apiRequest(fd)
                .then(
                    function(result){

                        if(
                            !result.ok ||
                            !result.data.success
                        ){

                            throw new Error(
                                result.data.message ||
                                'Unable to update subscription status.'
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
                            'Unable to update subscription status.',
                            3000
                        );

                    }
                );
            }
        );
    }
);

/* Cancel */
document
.querySelectorAll(
    '.subscription-cancel'
)
.forEach(
    function(btn){

        btn.addEventListener(
            'click',
            function(){

                if(
                    btn.getAttribute(
                        'data-status'
                    )==='cancelled'
                ){

                    showToast(
                        'info',
                        'This subscription is already cancelled.',
                        3000
                    );

                    return;
                }

                if(
                    !window.confirm(
                        'Cancel this subscription?'
                    )
                ){
                    return;
                }

                var fd=
                    new FormData();

                fd.append(
                    'csrf_token',
                    '<?= sub_h($csrfToken) ?>'
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
                    'cancelled'
                );

                apiRequest(fd)
                .then(
                    function(result){

                        if(
                            !result.ok ||
                            !result.data.success
                        ){

                            throw new Error(
                                result.data.message ||
                                'Unable to cancel subscription.'
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
                            'Unable to cancel subscription.',
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
