<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Renewals';
$activePage = 'renewals';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['renewals_csrf'])) {
    $_SESSION['renewals_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['renewals_csrf'];

function rn_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

function rn_table_exists(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");

    $stmt->execute(array(
        ':table_name' => $table
    ));

    $cache[$table] =
        ((int)$stmt->fetchColumn() > 0);

    return $cache[$table];
}

$hasRenewalsTable =
    rn_table_exists(
        $pdo,
        'subscription_renewals'
    );

$hasPaymentsTable =
    rn_table_exists(
        $pdo,
        'subscription_payments'
    );

$search = isset($_GET['search'])
    ? trim((string)$_GET['search'])
    : '';

$status = isset($_GET['status'])
    ? trim((string)$_GET['status'])
    : '';

$planId = isset($_GET['plan_id'])
    ? (int)$_GET['plan_id']
    : 0;

$countryId = isset($_GET['country_id'])
    ? (int)$_GET['country_id']
    : 0;

$window = isset($_GET['window'])
    ? trim((string)$_GET['window'])
    : 'all';

$plans = $pdo->query("
    SELECT
        id,
        name,
        code,
        price,
        currency,
        billing_cycle,
        duration_days,
        trial_days,
        status
    FROM plans
    WHERE deleted_at IS NULL
    ORDER BY name
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

$currencies = $pdo->query("
    SELECT
        id,
        currency_code,
        currency_name
    FROM currencies
    WHERE is_active = 1
    ORDER BY currency_code
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

        t.tenant_code,
        t.display_name AS tenant_name,
        t.legal_name,
        t.email AS tenant_email,
        t.country_id,

        p.name AS plan_name,
        p.code AS plan_code,
        p.price AS plan_price,
        p.currency AS plan_currency,
        p.billing_cycle,
        p.duration_days,

        c.name AS country_name,

        cur.currency_code,
        cur.symbol AS currency_symbol,

        CASE
            WHEN s.expiry_date IS NULL THEN NULL
            ELSE DATEDIFF(s.expiry_date, CURDATE())
        END AS days_remaining
";

if ($hasRenewalsTable) {
    $sql .= ",
        (
            SELECT MAX(sr.renewed_at)
            FROM subscription_renewals sr
            WHERE sr.subscription_id = s.id
              AND sr.deleted_at IS NULL
              AND sr.status = 'completed'
        ) AS last_renewed_at,
        (
            SELECT COUNT(*)
            FROM subscription_renewals sr
            WHERE sr.subscription_id = s.id
              AND sr.deleted_at IS NULL
              AND sr.status = 'completed'
        ) AS renewal_count
    ";
} else {
    $sql .= ",
        NULL AS last_renewed_at,
        0 AS renewal_count
    ";
}

if ($hasPaymentsTable) {
    $sql .= ",
        (
            SELECT MAX(sp.payment_date)
            FROM subscription_payments sp
            WHERE sp.subscription_id = s.id
              AND sp.deleted_at IS NULL
              AND sp.status = 'succeeded'
        ) AS last_payment_date
    ";
} else {
    $sql .= ",
        NULL AS last_payment_date
    ";
}

$sql .= "
    FROM subscriptions s

    INNER JOIN tenants t
        ON t.id = s.tenant_id
       AND t.deleted_at IS NULL

    LEFT JOIN plans p
        ON p.id = s.plan_id
       AND p.deleted_at IS NULL

    LEFT JOIN countries c
        ON c.id = t.country_id

    LEFT JOIN currencies cur
        ON cur.id = s.currency_id

    WHERE s.deleted_at IS NULL
";

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
            OR c.name LIKE :search
            OR cur.currency_code LIKE :search
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

if ($planId > 0) {
    $sql .= " AND s.plan_id = :plan_id ";
    $params[':plan_id'] = $planId;
}

if ($countryId > 0) {
    $sql .= " AND t.country_id = :country_id ";
    $params[':country_id'] = $countryId;
}

if ($window === '7') {
    $sql .= "
        AND s.expiry_date IS NOT NULL
        AND DATEDIFF(s.expiry_date, CURDATE()) BETWEEN 0 AND 7
    ";
} elseif ($window === '30') {
    $sql .= "
        AND s.expiry_date IS NOT NULL
        AND DATEDIFF(s.expiry_date, CURDATE()) BETWEEN 0 AND 30
    ";
} elseif ($window === '60') {
    $sql .= "
        AND s.expiry_date IS NOT NULL
        AND DATEDIFF(s.expiry_date, CURDATE()) BETWEEN 0 AND 60
    ";
} elseif ($window === 'expired') {
    $sql .= "
        AND s.expiry_date IS NOT NULL
        AND s.expiry_date < CURDATE()
    ";
} elseif ($window === 'auto') {
    $sql .= "
        AND s.auto_renew = 1
    ";
}

$sql .= "
    ORDER BY
        CASE
            WHEN s.expiry_date IS NULL THEN 5
            WHEN s.expiry_date < CURDATE() THEN 1
            WHEN DATEDIFF(s.expiry_date, CURDATE()) <= 7 THEN 2
            WHEN DATEDIFF(s.expiry_date, CURDATE()) <= 30 THEN 3
            ELSE 4
        END,
        s.expiry_date ASC,
        t.display_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subscriptions = $stmt->fetchAll();

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(
            expiry_date IS NOT NULL
            AND expiry_date < CURDATE()
        ) AS expired_count,
        SUM(
            expiry_date IS NOT NULL
            AND DATEDIFF(expiry_date, CURDATE()) BETWEEN 0 AND 7
        ) AS due_7_count,
        SUM(
            expiry_date IS NOT NULL
            AND DATEDIFF(expiry_date, CURDATE()) BETWEEN 0 AND 30
        ) AS due_30_count,
        SUM(auto_renew = 1) AS auto_renew_count
    FROM subscriptions
    WHERE deleted_at IS NULL
      AND status <> 'cancelled'
")->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title><?= rn_h($pageTitle) ?> - FieldPlx</title>

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
    --fp-topbar-height:66px
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

.fp-content{
    padding:18px;
    background:#fff
}

/* Page */
.rn-page{
    display:grid;
    gap:16px
}

.rn-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px
}

.rn-title{
    margin:0;
    color:#111827;
    font-size:20px;
    font-weight:800
}

.rn-description{
    margin-top:4px;
    max-width:780px;
    color:#77718e;
    font-size:10px;
    line-height:1.55
}

.rn-primary,
.rn-secondary{
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

.rn-primary{
    border:0;
    background:linear-gradient(135deg,#7c3aed,#6d28d9);
    color:#fff;
    box-shadow:0 8px 20px rgba(109,40,217,.18)
}

.rn-secondary{
    border:1px solid #dcd5ef;
    background:#fff;
    color:#5f5870
}

.rn-primary:hover{
    background:linear-gradient(135deg,#6d28d9,#5b21b6)
}

.rn-secondary:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.rn-primary:disabled,
.rn-secondary:disabled{
    opacity:.65;
    cursor:not-allowed
}

/* Stats */
.rn-stats{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px
}

.rn-stat{
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

.rn-stat:hover{
    border-color:#cfc3ef;
    background:linear-gradient(180deg,#fff 0%,#f8f4ff 100%)
}

.rn-stat-icon{
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

.rn-stat-content{
    min-width:0;
    display:block
}

.rn-stat-label{
    display:block;
    color:#9a94ae;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
    line-height:1.3
}

.rn-stat-value{
    margin-top:2px;
    display:block;
    color:#111827;
    font-size:20px;
    font-weight:800;
    line-height:1.2
}

.rn-stat-note{
    margin-top:2px;
    display:block;
    color:#9d96ac;
    font-size:7.5px;
    line-height:1.35
}

/* Notice */
.rn-notice{
    padding:11px 13px;
    display:flex;
    align-items:flex-start;
    gap:9px;
    border:1px solid #f2d9a7;
    border-radius:11px;
    background:#fffaf0;
    color:#8a5b11;
    font-size:9px;
    line-height:1.5
}

.rn-notice i{
    margin-top:1px;
    font-size:13px
}

/* Table */
.rn-card{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 24px rgba(37,29,80,.05)
}

.rn-toolbar{
    padding:13px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    background:#fbf9ff;
    border-bottom:1px solid #ece7f7
}

.rn-toolbar-left{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap
}

.rn-search{
    position:relative
}

.rn-search i{
    position:absolute;
    left:11px;
    top:50%;
    transform:translateY(-50%);
    color:#948da7;
    font-size:12px
}

.rn-input,
.rn-select{
    height:39px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    background:#fff;
    color:#312b47;
    font-size:10px;
    outline:0
}

.rn-input{padding:8px 11px}

.rn-search .rn-input{
    width:235px;
    padding-left:33px
}

.rn-select{
    padding:8px 30px 8px 10px
}

.rn-input:focus,
.rn-select:focus,
.rn-textarea:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10)
}

.rn-table-wrap{
    overflow:auto;
    scrollbar-width:thin;
    scrollbar-color:#bcb4ca transparent
}

.rn-table-wrap::-webkit-scrollbar{
    width:4px;
    height:4px
}

.rn-table-wrap::-webkit-scrollbar-track{
    background:transparent
}

.rn-table-wrap::-webkit-scrollbar-thumb{
    background:#bcb4ca;
    border-radius:999px
}

.rn-table{
    width:100%;
    min-width:1260px;
    border-collapse:collapse
}

.rn-table th{
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

.rn-table td{
    padding:10px 12px;
    border-bottom:1px solid #f0ecf7;
    color:#433d54;
    font-size:9px;
    vertical-align:middle
}

.rn-table tbody tr:hover{
    background:#fcfbff
}

.rn-tenant{
    display:flex;
    align-items:center;
    gap:8px;
    min-width:180px
}

.rn-row-icon{
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

.rn-title-sm{
    display:block;
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.rn-sub-sm{
    display:block;
    margin-top:2px;
    color:#9b94a7;
    font-size:8px
}

.rn-amount{
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.rn-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700
}

.rn-badge.active{
    background:#ecfdf5;
    color:#047857
}

.rn-badge.trial{
    background:#eef2ff;
    color:#4338ca
}

.rn-badge.expired{
    background:#fef2f2;
    color:#b91c1c
}

.rn-badge.suspended{
    background:#fff7ed;
    color:#c2410c
}

.rn-badge.cancelled{
    background:#f3f4f6;
    color:#6b7280
}

.rn-due{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700
}

.rn-due.expired{
    background:#fef2f2;
    color:#b91c1c
}

.rn-due.urgent{
    background:#fff7ed;
    color:#c2410c
}

.rn-due.soon{
    background:#fefce8;
    color:#a16207
}

.rn-due.normal{
    background:#ecfdf5;
    color:#047857
}

.rn-due.none{
    background:#f3f4f6;
    color:#6b7280
}

.rn-actions{
    display:flex;
    align-items:center;
    gap:5px
}

.rn-icon-btn{
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

.rn-icon-btn:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.rn-empty{
    padding:36px 15px;
    text-align:center;
    color:#928aa5;
    font-size:10px
}

/* Modal */
.rn-modal-backdrop{
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

.rn-modal-backdrop.show{display:flex}

.rn-modal{
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

.rn-modal::-webkit-scrollbar{
    width:4px;
    height:4px
}

.rn-modal::-webkit-scrollbar-thumb{
    background:#bcb4ca;
    border-radius:999px
}

.rn-modal-header{
    padding:13px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff
}

.rn-modal-title-wrap{
    display:flex;
    align-items:center;
    gap:10px
}

.rn-modal-icon{
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

.rn-modal-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800
}

.rn-modal-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px
}

.rn-modal-close{
    width:30px;
    height:30px;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#fff;
    color:#6d657d;
    cursor:pointer
}

.rn-modal-body{padding:15px}

.rn-section{
    margin-bottom:13px;
    padding:12px;
    border:1px solid #e2dcf2;
    border-radius:10px;
    background:#fbf9ff
}

.rn-section:last-child{margin-bottom:0}

.rn-section-title{
    margin:0 0 10px;
    color:#393248;
    font-size:9px;
    font-weight:700
}

.rn-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:13px
}

.rn-field.full{grid-column:1/-1}

.rn-field label{
    margin-bottom:6px;
    display:block;
    color:#4c465f;
    font-size:9px;
    font-weight:700
}

.rn-required{color:#dc2626}

.rn-textarea{
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

.rn-toggle{
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

.rn-toggle strong{
    display:block;
    color:#393248;
    font-size:9px
}

.rn-toggle small{
    margin-top:2px;
    display:block;
    color:#9a94aa;
    font-size:8px
}

.rn-hint{
    margin-top:4px;
    color:#9a94aa;
    font-size:8px;
    line-height:1.45
}

.rn-summary-box{
    padding:10px 11px;
    border:1px solid #ded7ef;
    border-radius:10px;
    background:#fff
}

.rn-summary-row{
    padding:4px 0;
    display:flex;
    justify-content:space-between;
    gap:12px;
    color:#746c85;
    font-size:8.5px
}

.rn-summary-row strong{
    color:#352f45;
    font-weight:700;
    text-align:right
}

.rn-modal-footer{
    padding:12px 15px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid #ece7f7;
    background:#fbf9ff
}

/* Loader */
.rn-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:rnSpin .75s linear infinite
}

.rn-primary.loading .rn-loader{display:inline-block}

@keyframes rnSpin{
    to{transform:rotate(360deg)}
}

/* Toast */
.rn-toast{
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

.rn-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0)
}

.rn-toast.success{background:#059669}
.rn-toast.error{background:#dc2626}
.rn-toast.warning{background:#d97706}
.rn-toast.info{background:#4f46e5}

.rn-toast-icon{
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

.rn-toast-message{
    flex:1;
    min-width:0;
    font-weight:600
}

.rn-toast-close{
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
    cursor:pointer
}

@media(max-width:1100px){
    .rn-stats{
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
    .rn-header{
        flex-direction:column
    }

    .rn-toolbar{
        align-items:stretch
    }

    .rn-toolbar-left{
        width:100%
    }

    .rn-search{
        width:100%
    }

    .rn-search .rn-input{
        width:100%
    }

    .rn-form-grid{
        grid-template-columns:1fr
    }

    .rn-field.full{
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

    .rn-stats{
        grid-template-columns:1fr
    }

    .rn-stat{
        min-height:82px
    }

    .rn-modal-footer{
        flex-direction:column-reverse
    }

    .rn-modal-footer .rn-primary,
    .rn-modal-footer .rn-secondary{
        width:100%
    }

    .rn-toast{
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

<div class="rn-page">

<div class="rn-header">

    <div>

        <h2 class="rn-title">
            Renewals
        </h2>

        <div class="rn-description">
            Review subscriptions approaching expiry,
            expired accounts and auto-renew subscriptions,
            then extend the current subscription period
            while preserving renewal history.
        </div>

    </div>

</div>

<?php if (!$hasRenewalsTable): ?>

<div class="rn-notice">

    <i class="bi bi-exclamation-triangle"></i>

    <div>
        Renewal history table is not installed.
        Run <strong>subscription-renewals-migration.sql</strong>
        before using the Renew action.
    </div>

</div>

<?php endif; ?>

<div class="rn-stats">

<?php
$cards = array(
    array(
        'Subscriptions',
        'bi bi-credit-card',
        (int)($stats['total'] ?? 0),
        'Non-cancelled subscriptions'
    ),
    array(
        'Expired',
        'bi bi-clock-history',
        (int)($stats['expired_count'] ?? 0),
        'Already past expiry'
    ),
    array(
        'Due in 7 Days',
        'bi bi-exclamation-circle',
        (int)($stats['due_7_count'] ?? 0),
        'Requires immediate follow-up'
    ),
    array(
        'Due in 30 Days',
        'bi bi-calendar-event',
        (int)($stats['due_30_count'] ?? 0),
        'Upcoming renewals'
    ),
    array(
        'Auto Renew',
        'bi bi-arrow-repeat',
        (int)($stats['auto_renew_count'] ?? 0),
        'Auto-renew enabled'
    )
);

foreach ($cards as $card):
?>

<div class="rn-stat">

    <span class="rn-stat-icon">
        <i class="<?= rn_h($card[1]) ?>"></i>
    </span>

    <span class="rn-stat-content">

        <span class="rn-stat-label">
            <?= rn_h($card[0]) ?>
        </span>

        <span class="rn-stat-value">
            <?= number_format($card[2]) ?>
        </span>

        <span class="rn-stat-note">
            <?= rn_h($card[3]) ?>
        </span>

    </span>

</div>

<?php endforeach; ?>

</div>

<section class="rn-card">

<form
    method="get"
    class="rn-toolbar"
>

<div class="rn-toolbar-left">

    <div class="rn-search">

        <i class="bi bi-search"></i>

        <input
            type="text"
            class="rn-input"
            name="search"
            value="<?= rn_h($search) ?>"
            placeholder="Search tenant, plan or country"
        >

    </div>

    <select
        class="rn-select"
        name="window"
        onchange="this.form.submit()"
    >

        <option value="all" <?= $window === 'all' ? 'selected' : '' ?>>
            All Renewals
        </option>

        <option value="7" <?= $window === '7' ? 'selected' : '' ?>>
            Due in 7 Days
        </option>

        <option value="30" <?= $window === '30' ? 'selected' : '' ?>>
            Due in 30 Days
        </option>

        <option value="60" <?= $window === '60' ? 'selected' : '' ?>>
            Due in 60 Days
        </option>

        <option value="expired" <?= $window === 'expired' ? 'selected' : '' ?>>
            Expired
        </option>

        <option value="auto" <?= $window === 'auto' ? 'selected' : '' ?>>
            Auto Renew
        </option>

    </select>

    <select
        class="rn-select"
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
                'suspended',
                'cancelled'
            ) as $filterStatus
        ):
        ?>

        <option
            value="<?= rn_h($filterStatus) ?>"
            <?= $status === $filterStatus ? 'selected' : '' ?>
        >
            <?= rn_h(ucfirst($filterStatus)) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <select
        class="rn-select"
        name="plan_id"
        onchange="this.form.submit()"
    >

        <option value="">
            All Plans
        </option>

        <?php foreach ($plans as $plan): ?>

        <option
            value="<?= (int)$plan['id'] ?>"
            <?= $planId === (int)$plan['id'] ? 'selected' : '' ?>
        >
            <?= rn_h($plan['name']) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <select
        class="rn-select"
        name="country_id"
        onchange="this.form.submit()"
    >

        <option value="">
            All Countries
        </option>

        <?php foreach ($countries as $country): ?>

        <option
            value="<?= (int)$country['id'] ?>"
            <?= $countryId === (int)$country['id'] ? 'selected' : '' ?>
        >
            <?= rn_h($country['name']) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <?php
    if (
        $search !== '' ||
        $status !== '' ||
        $planId > 0 ||
        $countryId > 0 ||
        $window !== 'all'
    ):
    ?>

    <a
        href="renewals.php"
        class="rn-secondary"
    >
        <i class="bi bi-x-lg"></i>
        Clear
    </a>

    <?php endif; ?>

</div>

<button
    type="submit"
    class="rn-secondary"
>
    <i class="bi bi-funnel"></i>
    Filter
</button>

</form>

<div class="rn-table-wrap">

<table class="rn-table">

<thead>
<tr>
    <th>S/No</th>
    <th>Tenant</th>
    <th>Plan</th>
    <th>Amount</th>
    <th>Start Date</th>
    <th>Expiry Date</th>
    <th>Remaining</th>
    <th>Auto Renew</th>
    <th>Renewals</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$subscriptions): ?>

<tr>
    <td colspan="11">

        <div class="rn-empty">
            No renewal records found.
        </div>

    </td>
</tr>

<?php else: ?>

<?php foreach ($subscriptions as $index => $subscription): ?>

<?php
$days = $subscription['days_remaining'];

$dueClass = 'none';
$dueText = 'No expiry';

if ($days !== null) {
    $days = (int)$days;

    if ($days < 0) {
        $dueClass = 'expired';
        $dueText = abs($days) . ' days overdue';
    } elseif ($days <= 7) {
        $dueClass = 'urgent';
        $dueText = $days . ' days';
    } elseif ($days <= 30) {
        $dueClass = 'soon';
        $dueText = $days . ' days';
    } else {
        $dueClass = 'normal';
        $dueText = $days . ' days';
    }
}
?>

<tr>

    <td>
        <?= $index + 1 ?>
    </td>

    <td>

        <div class="rn-tenant">

            <span class="rn-row-icon">
                <i class="bi bi-building"></i>
            </span>

            <span>

                <span class="rn-title-sm">
                    <?= rn_h(
                        $subscription['tenant_name']
                        ?: $subscription['legal_name']
                    ) ?>
                </span>

                <span class="rn-sub-sm">
                    <?= rn_h($subscription['tenant_code']) ?>
                    <?php if ($subscription['country_name']): ?>
                        · <?= rn_h($subscription['country_name']) ?>
                    <?php endif; ?>
                </span>

            </span>

        </div>

    </td>

    <td>

        <span class="rn-title-sm">
            <?= rn_h($subscription['plan_name'] ?: 'No Plan') ?>
        </span>

        <span class="rn-sub-sm">
            <?= rn_h($subscription['plan_code'] ?: '-') ?>
            <?php if ($subscription['billing_cycle']): ?>
                ·
                <?= rn_h(
                    ucwords(
                        str_replace(
                            '_',
                            ' ',
                            $subscription['billing_cycle']
                        )
                    )
                ) ?>
            <?php endif; ?>
        </span>

    </td>

    <td>

        <span class="rn-amount">
            <?= rn_h($subscription['currency_code'] ?: '') ?>
            <?= number_format(
                (float)$subscription['amount'],
                2
            ) ?>
        </span>

    </td>

    <td>
        <?= $subscription['start_date']
            ? rn_h(
                date(
                    'd M Y',
                    strtotime($subscription['start_date'])
                )
            )
            : '-' ?>
    </td>

    <td>
        <?= $subscription['expiry_date']
            ? rn_h(
                date(
                    'd M Y',
                    strtotime($subscription['expiry_date'])
                )
            )
            : 'No expiry' ?>
    </td>

    <td>

        <span class="rn-due <?= rn_h($dueClass) ?>">
            <i class="bi bi-clock"></i>
            <?= rn_h($dueText) ?>
        </span>

    </td>

    <td>

        <?php if ((int)$subscription['auto_renew'] === 1): ?>

        <span class="rn-badge active">
            <i class="bi bi-arrow-repeat me-1"></i>
            Enabled
        </span>

        <?php else: ?>

        <span class="rn-badge cancelled">
            Manual
        </span>

        <?php endif; ?>

    </td>

    <td>

        <span class="rn-title-sm">
            <?= number_format(
                (int)$subscription['renewal_count']
            ) ?>
        </span>

        <?php if ($subscription['last_renewed_at']): ?>

        <span class="rn-sub-sm">
            Last:
            <?= rn_h(
                date(
                    'd M Y',
                    strtotime($subscription['last_renewed_at'])
                )
            ) ?>
        </span>

        <?php endif; ?>

    </td>

    <td>

        <span
            class="rn-badge <?= rn_h($subscription['status']) ?>"
        >
            <?= rn_h(ucfirst($subscription['status'])) ?>
        </span>

    </td>

    <td>

        <div class="rn-actions">

            <button
                type="button"
                class="rn-icon-btn renewal-open"
                title="Renew Subscription"
                data-row='<?= rn_h(
                    json_encode(
                        $subscription,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    )
                ) ?>'
                <?= !$hasRenewalsTable ? 'disabled' : '' ?>
            >
                <i class="bi bi-arrow-repeat"></i>
            </button>

            <?php if ($hasPaymentsTable): ?>

            <a
                class="rn-icon-btn"
                title="Subscription Payments"
                href="payments.php?search=<?= urlencode(
                    $subscription['tenant_code']
                ) ?>"
            >
                <i class="bi bi-credit-card"></i>
            </a>

            <?php endif; ?>

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
<div id="rnToast" class="rn-toast">

    <span class="rn-toast-icon">
        <i
            id="rnToastIcon"
            class="bi bi-check-lg"
        ></i>
    </span>

    <span
        id="rnToastMessage"
        class="rn-toast-message"
    ></span>

    <button
        type="button"
        id="rnToastClose"
        class="rn-toast-close"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<!-- Renewal Modal -->
<div
    class="rn-modal-backdrop"
    id="renewalModal"
>

<div class="rn-modal">

<form id="renewalForm" novalidate>

<input
    type="hidden"
    name="csrf_token"
    value="<?= rn_h($csrfToken) ?>"
>

<input
    type="hidden"
    name="action"
    value="renew_subscription"
>

<input
    type="hidden"
    name="subscription_id"
    id="renewSubscriptionId"
>

<input
    type="hidden"
    name="tenant_id"
    id="renewTenantId"
>

<div class="rn-modal-header">

    <div class="rn-modal-title-wrap">

        <span class="rn-modal-icon">
            <i class="bi bi-arrow-repeat"></i>
        </span>

        <span>

            <h3 class="rn-modal-title">
                Renew Subscription
            </h3>

            <span class="rn-modal-subtitle">
                Extend the current subscription period
            </span>

        </span>

    </div>

    <button
        type="button"
        class="rn-modal-close"
        id="renewalModalClose"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<div class="rn-modal-body">

<div class="rn-section">

<h4 class="rn-section-title">
    Subscription
</h4>

<div class="rn-summary-box">

    <div class="rn-summary-row">
        <span>Tenant</span>
        <strong id="renewTenantName">-</strong>
    </div>

    <div class="rn-summary-row">
        <span>Current Plan</span>
        <strong id="renewCurrentPlan">-</strong>
    </div>

    <div class="rn-summary-row">
        <span>Current Expiry</span>
        <strong id="renewCurrentExpiry">-</strong>
    </div>

    <div class="rn-summary-row">
        <span>Current Amount</span>
        <strong id="renewCurrentAmount">-</strong>
    </div>

</div>

</div>

<div class="rn-section">

<h4 class="rn-section-title">
    Renewal Period
</h4>

<div class="rn-form-grid">

<div class="rn-field">

    <label>
        Renewal Plan
        <span class="rn-required">*</span>
    </label>

    <select
        class="rn-select"
        style="width:100%"
        name="plan_id"
        id="renewPlanId"
        required
    >

        <option value="">
            Select Plan
        </option>

        <?php foreach ($plans as $plan): ?>

        <option
            value="<?= (int)$plan['id'] ?>"
            data-price="<?= rn_h($plan['price']) ?>"
            data-currency="<?= rn_h($plan['currency']) ?>"
            data-cycle="<?= rn_h($plan['billing_cycle']) ?>"
            data-duration="<?= rn_h($plan['duration_days']) ?>"
        >
            <?= rn_h($plan['name']) ?>
            -
            <?= rn_h(
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $plan['billing_cycle']
                    )
                )
            ) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="rn-field">

    <label>
        Currency
        <span class="rn-required">*</span>
    </label>

    <select
        class="rn-select"
        style="width:100%"
        name="currency_id"
        id="renewCurrencyId"
        required
    >

        <option value="">
            Select Currency
        </option>

        <?php foreach ($currencies as $currency): ?>

        <option
            value="<?= (int)$currency['id'] ?>"
            data-code="<?= rn_h($currency['currency_code']) ?>"
        >
            <?= rn_h($currency['currency_code']) ?>
            -
            <?= rn_h($currency['currency_name']) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="rn-field">

    <label>
        Renewal Start Date
        <span class="rn-required">*</span>
    </label>

    <input
        class="rn-input"
        style="width:100%"
        type="date"
        name="renewal_start_date"
        id="renewStartDate"
        required
    >

</div>

<div class="rn-field">

    <label>
        New Expiry Date
        <span class="rn-required">*</span>
    </label>

    <input
        class="rn-input"
        style="width:100%"
        type="date"
        name="new_expiry_date"
        id="renewExpiryDate"
        required
    >

    <div class="rn-hint">
        Calculated from the selected plan.
        You can adjust it when required.
    </div>

</div>

<div class="rn-field">

    <label>
        Renewal Amount
        <span class="rn-required">*</span>
    </label>

    <input
        class="rn-input"
        style="width:100%"
        type="number"
        name="amount"
        id="renewAmount"
        min="0"
        step="0.01"
        required
    >

</div>

<div class="rn-field">

    <label>
        Renewal Status
    </label>

    <select
        class="rn-select"
        style="width:100%"
        name="status"
        id="renewStatus"
    >
        <option value="completed">
            Completed
        </option>
        <option value="pending">
            Pending
        </option>
        <option value="cancelled">
            Cancelled
        </option>
    </select>

</div>

<div class="rn-field full">

    <label class="rn-toggle">

        <span>

            <strong>
                Auto Renew
            </strong>

            <small>
                Keep automatic renewal enabled
                after this renewal.
            </small>

        </span>

        <span class="form-check form-switch m-0">

            <input
                class="form-check-input"
                type="checkbox"
                name="auto_renew"
                id="renewAutoRenew"
                value="1"
            >

        </span>

    </label>

</div>

</div>

</div>

<?php if ($hasPaymentsTable): ?>

<div class="rn-section">

<h4 class="rn-section-title">
    Payment
</h4>

<div class="rn-form-grid">

<div class="rn-field full">

    <label class="rn-toggle">

        <span>

            <strong>
                Record Payment
            </strong>

            <small>
                Create a subscription payment
                together with this renewal.
            </small>

        </span>

        <span class="form-check form-switch m-0">

            <input
                class="form-check-input"
                type="checkbox"
                name="record_payment"
                id="recordPayment"
                value="1"
                checked
            >

        </span>

    </label>

</div>

<div class="rn-field">

    <label>
        Payment Method
    </label>

    <select
        class="rn-select"
        style="width:100%"
        name="payment_method"
        id="renewPaymentMethod"
    >
        <option value="bank">Bank</option>
        <option value="upi">UPI</option>
        <option value="card">Card</option>
        <option value="cash">Cash</option>
        <option value="cheque">Cheque</option>
        <option value="wallet">Wallet</option>
        <option value="other">Other</option>
    </select>

</div>

<div class="rn-field">

    <label>
        Transaction Reference
    </label>

    <input
        class="rn-input"
        style="width:100%"
        name="transaction_reference"
        id="renewTransactionReference"
        maxlength="190"
        placeholder="UTR / reference no."
    >

</div>

</div>

</div>

<?php endif; ?>

<div class="rn-section">

<h4 class="rn-section-title">
    Notes
</h4>

<div class="rn-field">

    <textarea
        class="rn-textarea"
        name="notes"
        id="renewNotes"
        maxlength="2000"
        placeholder="Optional renewal notes"
    ></textarea>

</div>

</div>

</div>

<div class="rn-modal-footer">

<button
    type="button"
    class="rn-secondary"
    id="renewalCancel"
>
    Cancel
</button>

<button
    type="submit"
    class="rn-primary"
    id="renewalSaveBtn"
>

    <span class="rn-loader"></span>

    <i class="bi bi-arrow-repeat"></i>

    <span id="renewalSaveText">
        Renew Subscription
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

    if(localStorage.getItem(storageKey)==='1'){

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
                )?'1':'0'
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
.querySelectorAll('.fp-sidebar-menu-toggle')
.forEach(function(btn){

    btn.addEventListener(
        'click',
        function(){

            var menu=
                btn.closest(
                    '.fp-sidebar-menu'
                );

            if(menu){
                menu.classList.toggle('open');
            }
        }
    );
});

/* Toast */
var toast=document.getElementById('rnToast');
var toastMessage=document.getElementById('rnToastMessage');
var toastIcon=document.getElementById('rnToastIcon');
var toastClose=document.getElementById('rnToastClose');
var toastTimer=null;

function showToast(type,message,duration){

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

    toast.className='rn-toast '+t;
    toastMessage.textContent=message||'Notification';
    toastIcon.className='bi '+(icons[t]||icons.info);
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

toastClose.addEventListener(
    'click',
    function(){

        if(toastTimer){
            clearTimeout(toastTimer);
        }

        toast.classList.remove('show');
    }
);

/* API */
function apiRequest(formData){

    return fetch(
        'api/renewals.php',
        {
            method:'POST',
            body:formData,
            credentials:'same-origin',
            headers:{
                'X-Requested-With':'XMLHttpRequest',
                'Accept':'application/json'
            }
        }
    )
    .then(function(response){

        return response.text().then(
            function(rawText){

                var text=(rawText||'').trim();
                var data=null;

                try{

                    data=
                        text!==''
                            ? JSON.parse(text)
                            : {};

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
            }
        );
    });
}

/* Date helpers */
function parseIsoDate(value){

    if(!value){
        return null;
    }

    var parts=value.split('-');

    if(parts.length!==3){
        return null;
    }

    return new Date(
        parseInt(parts[0],10),
        parseInt(parts[1],10)-1,
        parseInt(parts[2],10)
    );
}

function formatIsoDate(date){

    if(!date){
        return '';
    }

    return [
        date.getFullYear(),
        String(date.getMonth()+1).padStart(2,'0'),
        String(date.getDate()).padStart(2,'0')
    ].join('-');
}

function addPlanPeriod(start,cycle,duration){

    var d=parseIsoDate(start);

    if(!d){
        return '';
    }

    if(cycle==='monthly'){
        d.setMonth(d.getMonth()+1);
        d.setDate(d.getDate()-1);
    }else if(cycle==='quarterly'){
        d.setMonth(d.getMonth()+3);
        d.setDate(d.getDate()-1);
    }else if(cycle==='half_yearly'){
        d.setMonth(d.getMonth()+6);
        d.setDate(d.getDate()-1);
    }else if(cycle==='yearly'){
        d.setFullYear(d.getFullYear()+1);
        d.setDate(d.getDate()-1);
    }else if(cycle==='custom'){
        var days=parseInt(duration||'0',10);

        if(days>0){
            d.setDate(d.getDate()+days-1);
        }
    }else if(cycle==='lifetime'){
        return '';
    }

    return formatIsoDate(d);
}

function nextDay(value){

    var d=parseIsoDate(value);

    if(!d){

        var now=new Date();

        return formatIsoDate(now);
    }

    d.setDate(d.getDate()+1);

    return formatIsoDate(d);
}

/* Renewal modal */
var modal=document.getElementById('renewalModal');
var form=document.getElementById('renewalForm');
var saveBtn=document.getElementById('renewalSaveBtn');
var saveText=document.getElementById('renewalSaveText');
var planSelect=document.getElementById('renewPlanId');
var currencySelect=document.getElementById('renewCurrencyId');

function closeModal(){
    modal.classList.remove('show');
}

function syncPlanDefaults(){

    var option=
        planSelect.options[
            planSelect.selectedIndex
        ];

    if(!option||!option.value){
        return;
    }

    var price=
        option.getAttribute('data-price')||'0.00';

    var currency=
        option.getAttribute('data-currency')||'';

    var cycle=
        option.getAttribute('data-cycle')||'monthly';

    var duration=
        option.getAttribute('data-duration')||'';

    document.getElementById('renewAmount').value=price;

    if(currency){

        Array.prototype.forEach.call(
            currencySelect.options,
            function(currencyOption){

                if(
                    currencyOption.getAttribute('data-code')===currency
                ){
                    currencySelect.value=currencyOption.value;
                }
            }
        );
    }

    var start=
        document.getElementById('renewStartDate').value;

    document.getElementById('renewExpiryDate').value=
        addPlanPeriod(
            start,
            cycle,
            duration
        );
}

planSelect.addEventListener(
    'change',
    syncPlanDefaults
);

document
.getElementById('renewStartDate')
.addEventListener(
    'change',
    function(){

        var option=
            planSelect.options[
                planSelect.selectedIndex
            ];

        if(!option||!option.value){
            return;
        }

        document.getElementById('renewExpiryDate').value=
            addPlanPeriod(
                this.value,
                option.getAttribute('data-cycle')||'monthly',
                option.getAttribute('data-duration')||''
            );
    }
);

function openRenewal(row){

    form.reset();

    document.getElementById('renewSubscriptionId').value=row.id||'';
    document.getElementById('renewTenantId').value=row.tenant_id||'';

    document.getElementById('renewTenantName').textContent=
        row.tenant_name||row.legal_name||'-';

    document.getElementById('renewCurrentPlan').textContent=
        (row.plan_name||'-')+
        (row.plan_code?' ('+row.plan_code+')':'');

    document.getElementById('renewCurrentExpiry').textContent=
        row.expiry_date||'No expiry';

    document.getElementById('renewCurrentAmount').textContent=
        (row.currency_code||'')+
        ' '+
        Number(row.amount||0).toFixed(2);

    document.getElementById('renewPlanId').value=
        row.plan_id||'';

    document.getElementById('renewCurrencyId').value=
        row.currency_id||'';

    document.getElementById('renewStartDate').value=
        nextDay(row.expiry_date||'');

    document.getElementById('renewAmount').value=
        row.amount||row.plan_price||'0.00';

    document.getElementById('renewAutoRenew').checked=
        String(row.auto_renew)==='1';

    document.getElementById('renewStatus').value=
        'completed';

    <?php if ($hasPaymentsTable): ?>
    document.getElementById('recordPayment').checked=true;
    document.getElementById('renewPaymentMethod').value='bank';
    document.getElementById('renewTransactionReference').value='';
    <?php endif; ?>

    document.getElementById('renewNotes').value='';

    var option=
        planSelect.options[
            planSelect.selectedIndex
        ];

    if(option&&option.value){

        document.getElementById('renewExpiryDate').value=
            addPlanPeriod(
                document.getElementById('renewStartDate').value,
                option.getAttribute('data-cycle')||row.billing_cycle||'monthly',
                option.getAttribute('data-duration')||row.duration_days||''
            );
    }

    modal.classList.add('show');
}

document
.querySelectorAll('.renewal-open')
.forEach(function(btn){

    btn.addEventListener(
        'click',
        function(){

            if(btn.disabled){
                return;
            }

            try{

                openRenewal(
                    JSON.parse(
                        btn.getAttribute('data-row')
                    )
                );

            }catch(e){

                showToast(
                    'error',
                    'Unable to load renewal details.',
                    3000
                );
            }
        }
    );
});

document
.getElementById('renewalModalClose')
.addEventListener('click',closeModal);

document
.getElementById('renewalCancel')
.addEventListener('click',closeModal);

modal.addEventListener(
    'click',
    function(e){

        if(e.target===modal){
            closeModal();
        }
    }
);

form.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        if(!form.checkValidity()){

            showToast(
                'warning',
                'Please complete the required renewal fields.',
                3000
            );

            form.reportValidity();

            return;
        }

        saveBtn.disabled=true;
        saveBtn.classList.add('loading');
        saveText.textContent='Renewing...';

        apiRequest(
            new FormData(form)
        )
        .then(function(result){

            if(
                !result.ok ||
                !result.data.success
            ){
                throw new Error(
                    result.data.message||
                    'Unable to renew subscription.'
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
        })
        .catch(function(error){

            showToast(
                'error',
                error.message||
                'Unable to renew subscription.',
                3000
            );

            saveBtn.disabled=false;
            saveBtn.classList.remove('loading');
            saveText.textContent='Renew Subscription';
        });
    }
);

})();
</script>

</body>
</html>
