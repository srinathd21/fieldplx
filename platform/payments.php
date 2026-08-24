<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Subscription Payments';
$activePage = 'subscription-payments';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['subscription_payments_csrf'])) {
    $_SESSION['subscription_payments_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['subscription_payments_csrf'];

function sp_h($value)
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

$method = isset($_GET['payment_method'])
    ? trim((string)$_GET['payment_method'])
    : '';

$planId = isset($_GET['plan_id'])
    ? (int)$_GET['plan_id']
    : 0;

$countryId = isset($_GET['country_id'])
    ? (int)$_GET['country_id']
    : 0;

$plans = $pdo->query("
    SELECT id, name, code
    FROM plans
    WHERE deleted_at IS NULL
    ORDER BY name
")->fetchAll();

$countries = $pdo->query("
    SELECT id, name, iso2
    FROM countries
    WHERE is_active = 1
    ORDER BY name
")->fetchAll();

$subscriptions = $pdo->query("
    SELECT
        s.id,
        s.tenant_id,
        s.plan_id,
        s.currency_id,
        s.amount,
        s.start_date,
        s.expiry_date,
        s.status,
        t.tenant_code,
        t.display_name,
        t.legal_name,
        p.name AS plan_name,
        p.code AS plan_code,
        cur.currency_code
    FROM subscriptions s
    INNER JOIN tenants t
        ON t.id = s.tenant_id
       AND t.deleted_at IS NULL
    LEFT JOIN plans p
        ON p.id = s.plan_id
       AND p.deleted_at IS NULL
    LEFT JOIN currencies cur
        ON cur.id = s.currency_id
    WHERE s.deleted_at IS NULL
    ORDER BY
        CASE s.status
            WHEN 'active' THEN 1
            WHEN 'trial' THEN 2
            ELSE 3
        END,
        t.display_name,
        s.id DESC
")->fetchAll();

$sql = "
    SELECT
        sp.id,
        sp.subscription_id,
        sp.tenant_id,
        sp.payment_no,
        sp.payment_date,
        sp.amount,
        sp.currency_id,
        sp.payment_method,
        sp.payment_channel,
        sp.status,
        sp.transaction_reference,
        sp.provider,
        sp.provider_payment_id,
        sp.transaction_fee,
        sp.notes,
        sp.created_by,
        sp.created_at,
        sp.updated_at,

        s.plan_id,
        s.start_date AS subscription_start_date,
        s.expiry_date AS subscription_expiry_date,

        t.tenant_code,
        t.display_name AS tenant_name,
        t.legal_name,
        t.country_id,

        p.name AS plan_name,
        p.code AS plan_code,

        c.name AS country_name,

        cur.currency_code,
        cur.symbol AS currency_symbol

    FROM subscription_payments sp

    INNER JOIN subscriptions s
        ON s.id = sp.subscription_id
       AND s.deleted_at IS NULL

    INNER JOIN tenants t
        ON t.id = sp.tenant_id
       AND t.deleted_at IS NULL

    LEFT JOIN plans p
        ON p.id = s.plan_id
       AND p.deleted_at IS NULL

    LEFT JOIN countries c
        ON c.id = t.country_id

    LEFT JOIN currencies cur
        ON cur.id = sp.currency_id

    WHERE sp.deleted_at IS NULL
";

$params = array();

if ($search !== '') {
    $sql .= "
        AND (
            sp.payment_no LIKE :search1
            OR sp.transaction_reference LIKE :search2
            OR sp.provider_payment_id LIKE :search3
            OR t.display_name LIKE :search4
            OR t.legal_name LIKE :search5
            OR t.tenant_code LIKE :search6
            OR p.name LIKE :search7
            OR p.code LIKE :search8
            OR cur.currency_code LIKE :search9
            OR c.name LIKE :search10
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[':search1'] = $searchValue;
    $params[':search2'] = $searchValue;
    $params[':search3'] = $searchValue;
    $params[':search4'] = $searchValue;
    $params[':search5'] = $searchValue;
    $params[':search6'] = $searchValue;
    $params[':search7'] = $searchValue;
    $params[':search8'] = $searchValue;
    $params[':search9'] = $searchValue;
    $params[':search10'] = $searchValue;
}

if (
    in_array(
        $status,
        array(
            'pending',
            'authorized',
            'succeeded',
            'failed',
            'refunded',
            'partially_refunded',
            'cancelled'
        ),
        true
    )
) {
    $sql .= " AND sp.status = :status ";
    $params[':status'] = $status;
}

if (
    in_array(
        $method,
        array(
            'cash',
            'card',
            'bank',
            'upi',
            'cheque',
            'wallet',
            'other'
        ),
        true
    )
) {
    $sql .= " AND sp.payment_method = :payment_method ";
    $params[':payment_method'] = $method;
}

if ($planId > 0) {
    $sql .= " AND s.plan_id = :plan_id ";
    $params[':plan_id'] = $planId;
}

if ($countryId > 0) {
    $sql .= " AND t.country_id = :country_id ";
    $params[':country_id'] = $countryId;
}

$sql .= "
    ORDER BY
        sp.payment_date DESC,
        sp.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'succeeded') AS succeeded_count,
        SUM(status = 'pending') AS pending_count,
        SUM(status = 'failed') AS failed_count,
        COALESCE(
            SUM(
                CASE
                    WHEN status = 'succeeded'
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS successful_amount
    FROM subscription_payments
    WHERE deleted_at IS NULL
")->fetch();

$currencyTotals = $pdo->query("
    SELECT
        cur.currency_code,
        COALESCE(SUM(sp.amount), 0) AS total_amount
    FROM subscription_payments sp
    LEFT JOIN currencies cur
        ON cur.id = sp.currency_id
    WHERE sp.deleted_at IS NULL
      AND sp.status = 'succeeded'
    GROUP BY sp.currency_id, cur.currency_code
    ORDER BY cur.currency_code
")->fetchAll();

$currencySummary = array();

foreach ($currencyTotals as $row) {
    $currencySummary[] =
        ($row['currency_code'] ?: '-') .
        ' ' .
        number_format(
            (float)$row['total_amount'],
            2
        );
}

$successfulAmountLabel =
    !empty($currencySummary)
        ? implode(' · ', $currencySummary)
        : '0.00';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= sp_h($pageTitle) ?> - FieldPlx</title>

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
.sp-page{
    display:grid;
    gap:16px
}

.sp-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px
}

.sp-title{
    margin:0;
    color:#111827;
    font-size:20px;
    font-weight:800
}

.sp-description{
    margin-top:4px;
    max-width:780px;
    color:#77718e;
    font-size:10px;
    line-height:1.55
}

.sp-header-actions{
    display:flex;
    align-items:center;
    gap:8px
}

.sp-primary,
.sp-secondary{
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

.sp-primary{
    border:0;
    background:linear-gradient(135deg,#7c3aed,#6d28d9);
    color:#fff;
    box-shadow:0 8px 20px rgba(109,40,217,.18)
}

.sp-secondary{
    border:1px solid #dcd5ef;
    background:#fff;
    color:#5f5870
}

.sp-primary:hover{
    background:linear-gradient(135deg,#6d28d9,#5b21b6)
}

.sp-secondary:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.sp-primary:disabled,
.sp-secondary:disabled{
    opacity:.65;
    cursor:not-allowed
}

/* Stats */
.sp-stats{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px
}

.sp-stat{
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

.sp-stat:hover{
    border-color:#cfc3ef;
    background:linear-gradient(180deg,#fff 0%,#f8f4ff 100%)
}

.sp-stat-icon{
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

.sp-stat-content{
    min-width:0;
    display:block
}

.sp-stat-label{
    display:block;
    color:#9a94ae;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
    line-height:1.3
}

.sp-stat-value{
    margin-top:2px;
    display:block;
    color:#111827;
    font-size:20px;
    font-weight:800;
    line-height:1.2
}

.sp-stat-note{
    margin-top:2px;
    display:block;
    color:#9d96ac;
    font-size:7.5px;
    line-height:1.35
}

.sp-stat-value.amount{
    font-size:12px;
    line-height:1.45
}

/* Table */
.sp-card{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 24px rgba(37,29,80,.05)
}

.sp-toolbar{
    padding:13px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    background:#fbf9ff;
    border-bottom:1px solid #ece7f7
}

.sp-toolbar-left{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap
}

.sp-search{
    position:relative
}

.sp-search i{
    position:absolute;
    left:11px;
    top:50%;
    transform:translateY(-50%);
    color:#948da7;
    font-size:12px
}

.sp-input,
.sp-select{
    height:39px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    background:#fff;
    color:#312b47;
    font-size:10px;
    outline:0
}

.sp-input{padding:8px 11px}

.sp-search .sp-input{
    width:240px;
    padding-left:33px
}

.sp-select{
    padding:8px 30px 8px 10px
}

.sp-input:focus,
.sp-select:focus,
.sp-textarea:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10)
}

.sp-table-wrap{
    overflow:auto;
    scrollbar-width:thin;
    scrollbar-color:#bcb4ca transparent
}

.sp-table-wrap::-webkit-scrollbar{
    width:4px;
    height:4px
}

.sp-table-wrap::-webkit-scrollbar-track{
    background:transparent
}

.sp-table-wrap::-webkit-scrollbar-thumb{
    background:#bcb4ca;
    border-radius:999px
}

.sp-table{
    width:100%;
    min-width:1320px;
    border-collapse:collapse
}

.sp-table th{
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

.sp-table td{
    padding:10px 12px;
    border-bottom:1px solid #f0ecf7;
    color:#433d54;
    font-size:9px;
    vertical-align:middle
}

.sp-table tbody tr:hover{
    background:#fcfbff
}

.sp-tenant{
    display:flex;
    align-items:center;
    gap:8px;
    min-width:180px
}

.sp-row-icon{
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

.sp-tenant-name,
.sp-plan-name,
.sp-payment-no{
    display:block;
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.sp-tenant-code,
.sp-plan-code,
.sp-small{
    display:block;
    margin-top:2px;
    color:#9b94a7;
    font-size:8px
}

.sp-amount{
    display:block;
    color:#302a40;
    font-size:9px;
    font-weight:800
}

.sp-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700;
    text-transform:capitalize
}

.sp-badge.pending{
    background:#fff7ed;
    color:#c2410c
}

.sp-badge.authorized{
    background:#eef2ff;
    color:#4338ca
}

.sp-badge.succeeded{
    background:#ecfdf5;
    color:#047857
}

.sp-badge.failed,
.sp-badge.cancelled{
    background:#fef2f2;
    color:#b91c1c
}

.sp-badge.refunded,
.sp-badge.partially_refunded{
    background:#f3f4f6;
    color:#6b7280
}

.sp-method{
    display:inline-flex;
    align-items:center;
    gap:5px;
    color:#5a526b;
    font-size:8.5px;
    text-transform:capitalize
}

.sp-actions{
    display:flex;
    align-items:center;
    gap:5px
}

.sp-icon-btn{
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

.sp-icon-btn:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.sp-empty{
    padding:36px 15px;
    text-align:center;
    color:#928aa5;
    font-size:10px
}

/* Modal */
.sp-modal-backdrop{
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

.sp-modal-backdrop.show{display:flex}

.sp-modal{
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

.sp-modal::-webkit-scrollbar{
    width:4px;
    height:4px
}

.sp-modal::-webkit-scrollbar-thumb{
    background:#bcb4ca;
    border-radius:999px
}

.sp-modal-header{
    padding:13px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff
}

.sp-modal-title-wrap{
    display:flex;
    align-items:center;
    gap:10px
}

.sp-modal-icon{
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

.sp-modal-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800
}

.sp-modal-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px
}

.sp-modal-close{
    width:30px;
    height:30px;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#fff;
    color:#6d657d;
    cursor:pointer
}

.sp-modal-body{padding:15px}

.sp-section{
    margin-bottom:13px;
    padding:12px;
    border:1px solid #e2dcf2;
    border-radius:10px;
    background:#fbf9ff
}

.sp-section:last-child{
    margin-bottom:0
}

.sp-section-title{
    margin:0 0 10px;
    color:#393248;
    font-size:9px;
    font-weight:700
}

.sp-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:13px
}

.sp-field.full{
    grid-column:1/-1
}

.sp-field label{
    margin-bottom:6px;
    display:block;
    color:#4c465f;
    font-size:9px;
    font-weight:700
}

.sp-required{color:#dc2626}

.sp-textarea{
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

.sp-hint{
    margin-top:4px;
    color:#9a94aa;
    font-size:8px;
    line-height:1.45
}

.sp-modal-footer{
    padding:12px 15px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid #ece7f7;
    background:#fbf9ff
}

/* Loader */
.sp-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:spSpin .75s linear infinite
}

.sp-primary.loading .sp-loader{
    display:inline-block
}

@keyframes spSpin{
    to{transform:rotate(360deg)}
}

/* Toast */
.sp-toast{
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

.sp-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0)
}

.sp-toast.success{background:#059669}
.sp-toast.error{background:#dc2626}
.sp-toast.warning{background:#d97706}
.sp-toast.info{background:#4f46e5}

.sp-toast-icon{
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

.sp-toast-message{
    flex:1;
    min-width:0;
    font-weight:600
}

.sp-toast-close{
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
    .sp-stats{
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
    .sp-header{
        flex-direction:column
    }

    .sp-header-actions{
        width:100%
    }

    .sp-header-actions .sp-primary{
        width:100%
    }

    .sp-toolbar{
        align-items:stretch
    }

    .sp-toolbar-left{
        width:100%
    }

    .sp-search{
        width:100%
    }

    .sp-search .sp-input{
        width:100%
    }

    .sp-form-grid{
        grid-template-columns:1fr
    }

    .sp-field.full{
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

    .sp-stats{
        grid-template-columns:1fr
    }

    .sp-stat{
        min-height:82px
    }

    .sp-modal-footer{
        flex-direction:column-reverse
    }

    .sp-modal-footer .sp-primary,
    .sp-modal-footer .sp-secondary{
        width:100%
    }

    .sp-toast{
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

<div class="sp-page">

<div class="sp-header">

    <div>

        <h2 class="sp-title">
            Subscription Payments
        </h2>

        <div class="sp-description">
            Manage FieldPlx tenant subscription payment records,
            payment references, methods, channels, providers,
            transaction fees and payment status.
        </div>

    </div>

    <div class="sp-header-actions">

        <button
            type="button"
            class="sp-primary"
            id="addPaymentBtn"
        >
            <i class="bi bi-plus-lg"></i>
            Add Payment
        </button>

    </div>

</div>

<div class="sp-stats">

<?php
$cards = array(
    array(
        'Total Payments',
        'bi bi-credit-card',
        (int)($stats['total'] ?? 0),
        'All subscription payments',
        false
    ),
    array(
        'Succeeded',
        'bi bi-check-circle',
        (int)($stats['succeeded_count'] ?? 0),
        'Completed payments',
        false
    ),
    array(
        'Pending',
        'bi bi-clock-history',
        (int)($stats['pending_count'] ?? 0),
        'Awaiting completion',
        false
    ),
    array(
        'Failed',
        'bi bi-x-circle',
        (int)($stats['failed_count'] ?? 0),
        'Failed transactions',
        false
    ),
    array(
        'Collected',
        'bi bi-cash-stack',
        $successfulAmountLabel,
        'Successful amount by currency',
        true
    )
);

foreach ($cards as $card):
?>

<div class="sp-stat">

    <span class="sp-stat-icon">
        <i class="<?= sp_h($card[1]) ?>"></i>
    </span>

    <span class="sp-stat-content">

        <span class="sp-stat-label">
            <?= sp_h($card[0]) ?>
        </span>

        <span class="sp-stat-value <?= $card[4] ? 'amount' : '' ?>">
            <?= $card[4]
                ? sp_h($card[2])
                : number_format((int)$card[2]) ?>
        </span>

        <span class="sp-stat-note">
            <?= sp_h($card[3]) ?>
        </span>

    </span>

</div>

<?php endforeach; ?>

</div>

<section class="sp-card">

<form
    method="get"
    class="sp-toolbar"
>

<div class="sp-toolbar-left">

    <div class="sp-search">

        <i class="bi bi-search"></i>

        <input
            type="text"
            class="sp-input"
            name="search"
            value="<?= sp_h($search) ?>"
            placeholder="Search payment, tenant or plan"
        >

    </div>

    <select
        class="sp-select"
        name="status"
        onchange="this.form.submit()"
    >

        <option value="">
            All Status
        </option>

        <?php
        foreach (
            array(
                'pending',
                'authorized',
                'succeeded',
                'failed',
                'refunded',
                'partially_refunded',
                'cancelled'
            ) as $filterStatus
        ):
        ?>

        <option
            value="<?= sp_h($filterStatus) ?>"
            <?= $status === $filterStatus ? 'selected' : '' ?>
        >
            <?= sp_h(
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $filterStatus
                    )
                )
            ) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <select
        class="sp-select"
        name="payment_method"
        onchange="this.form.submit()"
    >

        <option value="">
            All Methods
        </option>

        <?php
        foreach (
            array(
                'cash',
                'card',
                'bank',
                'upi',
                'cheque',
                'wallet',
                'other'
            ) as $filterMethod
        ):
        ?>

        <option
            value="<?= sp_h($filterMethod) ?>"
            <?= $method === $filterMethod ? 'selected' : '' ?>
        >
            <?= sp_h(ucfirst($filterMethod)) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <select
        class="sp-select"
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
            <?= sp_h($plan['name']) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <select
        class="sp-select"
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
            <?= sp_h($country['name']) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <?php
    if (
        $search !== '' ||
        $status !== '' ||
        $method !== '' ||
        $planId > 0 ||
        $countryId > 0
    ):
    ?>

    <a
        href="payments.php"
        class="sp-secondary"
    >
        <i class="bi bi-x-lg"></i>
        Clear
    </a>

    <?php endif; ?>

</div>

<button
    type="submit"
    class="sp-secondary"
>
    <i class="bi bi-funnel"></i>
    Filter
</button>

</form>

<div class="sp-table-wrap">

<table class="sp-table">

<thead>
<tr>
    <th>S/No</th>
    <th>Payment</th>
    <th>Tenant</th>
    <th>Plan</th>
    <th>Payment Date</th>
    <th>Amount</th>
    <th>Method</th>
    <th>Reference</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$payments): ?>

<tr>
    <td colspan="10">
        <div class="sp-empty">
            No subscription payments found.
        </div>
    </td>
</tr>

<?php else: ?>

<?php foreach ($payments as $index => $payment): ?>

<tr>

    <td><?= $index + 1 ?></td>

    <td>

        <span class="sp-payment-no">
            <?= sp_h($payment['payment_no']) ?>
        </span>

        <span class="sp-small">
            <?= sp_h(
                ucfirst(
                    str_replace(
                        '_',
                        ' ',
                        $payment['payment_channel']
                    )
                )
            ) ?>
        </span>

    </td>

    <td>

        <div class="sp-tenant">

            <span class="sp-row-icon">
                <i class="bi bi-building"></i>
            </span>

            <span>

                <span class="sp-tenant-name">
                    <?= sp_h(
                        $payment['tenant_name']
                        ?: $payment['legal_name']
                    ) ?>
                </span>

                <span class="sp-tenant-code">
                    <?= sp_h($payment['tenant_code']) ?>
                    <?php if ($payment['country_name']): ?>
                        · <?= sp_h($payment['country_name']) ?>
                    <?php endif; ?>
                </span>

            </span>

        </div>

    </td>

    <td>

        <span class="sp-plan-name">
            <?= sp_h($payment['plan_name'] ?: 'No Plan') ?>
        </span>

        <span class="sp-plan-code">
            <?= sp_h($payment['plan_code'] ?: '-') ?>
        </span>

    </td>

    <td>
        <?= sp_h(
            date(
                'd M Y',
                strtotime($payment['payment_date'])
            )
        ) ?>
    </td>

    <td>

        <span class="sp-amount">
            <?= sp_h($payment['currency_code'] ?: '') ?>
            <?= number_format(
                (float)$payment['amount'],
                2
            ) ?>
        </span>

        <?php if ((float)$payment['transaction_fee'] > 0): ?>
        <span class="sp-small">
            Fee:
            <?= number_format(
                (float)$payment['transaction_fee'],
                2
            ) ?>
        </span>
        <?php endif; ?>

    </td>

    <td>

        <span class="sp-method">
            <i class="bi bi-wallet2"></i>
            <?= sp_h(
                ucfirst(
                    $payment['payment_method']
                )
            ) ?>
        </span>

        <?php if ($payment['provider']): ?>
        <span class="sp-small">
            <?= sp_h($payment['provider']) ?>
        </span>
        <?php endif; ?>

    </td>

    <td>

        <span class="sp-payment-no">
            <?= sp_h(
                $payment['transaction_reference']
                ?: '-'
            ) ?>
        </span>

        <?php if ($payment['provider_payment_id']): ?>
        <span class="sp-small">
            <?= sp_h($payment['provider_payment_id']) ?>
        </span>
        <?php endif; ?>

    </td>

    <td>

        <span
            class="sp-badge <?= sp_h($payment['status']) ?>"
        >
            <?= sp_h(
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $payment['status']
                    )
                )
            ) ?>
        </span>

    </td>

    <td>

        <div class="sp-actions">

            <button
                type="button"
                class="sp-icon-btn payment-edit"
                title="Edit"
                data-row='<?= sp_h(
                    json_encode(
                        $payment,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    )
                ) ?>'
            >
                <i class="bi bi-pencil"></i>
            </button>

            <button
                type="button"
                class="sp-icon-btn payment-status"
                title="Update Status"
                data-row='<?= sp_h(
                    json_encode(
                        $payment,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    )
                ) ?>'
            >
                <i class="bi bi-arrow-repeat"></i>
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
<div id="spToast" class="sp-toast">

    <span class="sp-toast-icon">
        <i
            id="spToastIcon"
            class="bi bi-check-lg"
        ></i>
    </span>

    <span
        id="spToastMessage"
        class="sp-toast-message"
    ></span>

    <button
        type="button"
        id="spToastClose"
        class="sp-toast-close"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<!-- Payment Modal -->
<div
    class="sp-modal-backdrop"
    id="paymentModal"
>

<div class="sp-modal">

<form id="paymentForm" novalidate>

<input
    type="hidden"
    name="csrf_token"
    value="<?= sp_h($csrfToken) ?>"
>

<input
    type="hidden"
    name="action"
    value="save_payment"
>

<input
    type="hidden"
    name="id"
    id="paymentId"
>

<div class="sp-modal-header">

    <div class="sp-modal-title-wrap">

        <span class="sp-modal-icon">
            <i class="bi bi-credit-card"></i>
        </span>

        <span>

            <h3
                class="sp-modal-title"
                id="paymentModalTitle"
            >
                Add Subscription Payment
            </h3>

            <span class="sp-modal-subtitle">
                Record payment details for a tenant subscription
            </span>

        </span>

    </div>

    <button
        type="button"
        class="sp-modal-close"
        id="paymentModalClose"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<div class="sp-modal-body">

<div class="sp-section">

<h4 class="sp-section-title">
    Subscription & Amount
</h4>

<div class="sp-form-grid">

<div class="sp-field full">

    <label>
        Subscription
        <span class="sp-required">*</span>
    </label>

    <select
        class="sp-select"
        style="width:100%"
        name="subscription_id"
        id="subscriptionId"
        required
    >

        <option value="">
            Select Subscription
        </option>

        <?php foreach ($subscriptions as $subscription): ?>

        <option
            value="<?= (int)$subscription['id'] ?>"
            data-tenant-id="<?= (int)$subscription['tenant_id'] ?>"
            data-currency-id="<?= (int)$subscription['currency_id'] ?>"
            data-amount="<?= sp_h($subscription['amount']) ?>"
        >
            <?= sp_h(
                $subscription['display_name']
                ?: $subscription['legal_name']
            ) ?>
            -
            <?= sp_h($subscription['plan_name'] ?: 'No Plan') ?>
            -
            <?= sp_h($subscription['currency_code'] ?: '') ?>
            <?= number_format(
                (float)$subscription['amount'],
                2
            ) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="sp-field">

    <label>
        Payment Number
        <span class="sp-required">*</span>
    </label>

    <input
        class="sp-input"
        style="width:100%"
        name="payment_no"
        id="paymentNo"
        maxlength="80"
        required
        placeholder="Auto generated"
    >

    <div class="sp-hint">
        Leave the generated value unchanged unless required.
    </div>

</div>

<div class="sp-field">

    <label>
        Payment Date
        <span class="sp-required">*</span>
    </label>

    <input
        class="sp-input"
        style="width:100%"
        type="date"
        name="payment_date"
        id="paymentDate"
        required
    >

</div>

<div class="sp-field">

    <label>
        Currency
        <span class="sp-required">*</span>
    </label>

    <select
        class="sp-select"
        style="width:100%"
        name="currency_id"
        id="currencyId"
        required
    >

        <option value="">
            Select Currency
        </option>

        <?php
        $paymentCurrencies = $pdo->query("
            SELECT
                id,
                currency_code,
                currency_name
            FROM currencies
            WHERE is_active = 1
            ORDER BY currency_code
        ")->fetchAll();

        foreach ($paymentCurrencies as $currency):
        ?>

        <option value="<?= (int)$currency['id'] ?>">
            <?= sp_h($currency['currency_code']) ?>
            -
            <?= sp_h($currency['currency_name']) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="sp-field">

    <label>
        Amount
        <span class="sp-required">*</span>
    </label>

    <input
        class="sp-input"
        style="width:100%"
        type="number"
        name="amount"
        id="paymentAmount"
        min="0.01"
        step="0.01"
        required
    >

</div>

</div>

</div>

<div class="sp-section">

<h4 class="sp-section-title">
    Payment Details
</h4>

<div class="sp-form-grid">

<div class="sp-field">

    <label>
        Payment Method
        <span class="sp-required">*</span>
    </label>

    <select
        class="sp-select"
        style="width:100%"
        name="payment_method"
        id="paymentMethod"
        required
    >

        <?php
        foreach (
            array(
                'cash',
                'card',
                'bank',
                'upi',
                'cheque',
                'wallet',
                'other'
            ) as $optionMethod
        ):
        ?>

        <option value="<?= sp_h($optionMethod) ?>">
            <?= sp_h(ucfirst($optionMethod)) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="sp-field">

    <label>
        Payment Channel
        <span class="sp-required">*</span>
    </label>

    <select
        class="sp-select"
        style="width:100%"
        name="payment_channel"
        id="paymentChannel"
        required
    >

        <?php
        foreach (
            array(
                'online',
                'client_portal',
                'mobile',
                'office',
                'tap_to_pay',
                'manual'
            ) as $channel
        ):
        ?>

        <option value="<?= sp_h($channel) ?>">
            <?= sp_h(
                ucwords(
                    str_replace('_',' ',$channel)
                )
            ) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="sp-field">

    <label>
        Status
        <span class="sp-required">*</span>
    </label>

    <select
        class="sp-select"
        style="width:100%"
        name="status"
        id="paymentStatus"
        required
    >

        <?php
        foreach (
            array(
                'pending',
                'authorized',
                'succeeded',
                'failed',
                'refunded',
                'partially_refunded',
                'cancelled'
            ) as $optionStatus
        ):
        ?>

        <option value="<?= sp_h($optionStatus) ?>">
            <?= sp_h(
                ucwords(
                    str_replace('_',' ',$optionStatus)
                )
            ) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

<div class="sp-field">

    <label>
        Transaction Fee
    </label>

    <input
        class="sp-input"
        style="width:100%"
        type="number"
        name="transaction_fee"
        id="transactionFee"
        min="0"
        step="0.01"
        value="0.00"
    >

</div>

<div class="sp-field">

    <label>
        Transaction Reference
    </label>

    <input
        class="sp-input"
        style="width:100%"
        name="transaction_reference"
        id="transactionReference"
        maxlength="190"
        placeholder="UTR / bank ref / cheque no."
    >

</div>

<div class="sp-field">

    <label>
        Provider
    </label>

    <input
        class="sp-input"
        style="width:100%"
        name="provider"
        id="provider"
        maxlength="120"
        placeholder="Razorpay / Stripe / Bank"
    >

</div>

<div class="sp-field full">

    <label>
        Provider Payment ID
    </label>

    <input
        class="sp-input"
        style="width:100%"
        name="provider_payment_id"
        id="providerPaymentId"
        maxlength="190"
        placeholder="External provider payment ID"
    >

</div>

<div class="sp-field full">

    <label>
        Notes
    </label>

    <textarea
        class="sp-textarea"
        name="notes"
        id="paymentNotes"
        maxlength="2000"
    ></textarea>

</div>

</div>

</div>

</div>

<div class="sp-modal-footer">

<button
    type="button"
    class="sp-secondary"
    id="paymentCancel"
>
    Cancel
</button>

<button
    type="submit"
    class="sp-primary"
    id="paymentSaveBtn"
>

    <span class="sp-loader"></span>

    <i class="bi bi-check2-circle"></i>

    <span id="paymentSaveText">
        Save Payment
    </span>

</button>

</div>

</form>

</div>

</div>

<!-- Status Modal -->
<div
    class="sp-modal-backdrop"
    id="statusModal"
>

<div class="sp-modal" style="width:min(460px,100%)">

<form id="statusForm" novalidate>

<input
    type="hidden"
    name="csrf_token"
    value="<?= sp_h($csrfToken) ?>"
>

<input
    type="hidden"
    name="action"
    value="change_status"
>

<input
    type="hidden"
    name="id"
    id="statusPaymentId"
>

<div class="sp-modal-header">

    <div class="sp-modal-title-wrap">

        <span class="sp-modal-icon">
            <i class="bi bi-arrow-repeat"></i>
        </span>

        <span>

            <h3 class="sp-modal-title">
                Update Payment Status
            </h3>

            <span class="sp-modal-subtitle">
                Change the current transaction status
            </span>

        </span>

    </div>

    <button
        type="button"
        class="sp-modal-close"
        id="statusModalClose"
    >
        <i class="bi bi-x-lg"></i>
    </button>

</div>

<div class="sp-modal-body">

<div class="sp-field">

    <label>
        Payment Status
        <span class="sp-required">*</span>
    </label>

    <select
        class="sp-select"
        style="width:100%"
        name="status"
        id="statusValue"
        required
    >

        <?php
        foreach (
            array(
                'pending',
                'authorized',
                'succeeded',
                'failed',
                'refunded',
                'partially_refunded',
                'cancelled'
            ) as $optionStatus
        ):
        ?>

        <option value="<?= sp_h($optionStatus) ?>">
            <?= sp_h(
                ucwords(
                    str_replace('_',' ',$optionStatus)
                )
            ) ?>
        </option>

        <?php endforeach; ?>

    </select>

</div>

</div>

<div class="sp-modal-footer">

<button
    type="button"
    class="sp-secondary"
    id="statusCancel"
>
    Cancel
</button>

<button
    type="submit"
    class="sp-primary"
    id="statusSaveBtn"
>

    <span class="sp-loader"></span>

    <i class="bi bi-check2-circle"></i>

    <span id="statusSaveText">
        Update Status
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
var toast=document.getElementById('spToast');
var toastMessage=document.getElementById('spToastMessage');
var toastIcon=document.getElementById('spToastIcon');
var toastClose=document.getElementById('spToastClose');
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

    toast.className='sp-toast '+t;

    toastMessage.textContent=
        message||'Notification';

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
        'api/payments.php',
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

/* Helpers */
function todayIso(){

    var d=new Date();

    return [
        d.getFullYear(),
        String(d.getMonth()+1).padStart(2,'0'),
        String(d.getDate()).padStart(2,'0')
    ].join('-');
}

var paymentModal=
    document.getElementById('paymentModal');

var paymentForm=
    document.getElementById('paymentForm');

var paymentSaveBtn=
    document.getElementById('paymentSaveBtn');

var paymentSaveText=
    document.getElementById('paymentSaveText');

function closePaymentModal(){
    paymentModal.classList.remove('show');
}

function resetPaymentModal(){

    paymentForm.reset();

    document.getElementById('paymentId').value='';
    document.getElementById('paymentModalTitle').textContent='Add Subscription Payment';
    document.getElementById('paymentDate').value=todayIso();
    document.getElementById('paymentMethod').value='bank';
    document.getElementById('paymentChannel').value='manual';
    document.getElementById('paymentStatus').value='succeeded';
    document.getElementById('transactionFee').value='0.00';
    document.getElementById('paymentNo').value='AUTO';

    paymentSaveText.textContent='Save Payment';
}

function openPaymentModal(row){

    resetPaymentModal();

    if(row){

        document.getElementById('paymentId').value=row.id||'';
        document.getElementById('subscriptionId').value=row.subscription_id||'';
        document.getElementById('paymentNo').value=row.payment_no||'';
        document.getElementById('paymentDate').value=row.payment_date||'';
        document.getElementById('currencyId').value=row.currency_id||'';
        document.getElementById('paymentAmount').value=row.amount||'';
        document.getElementById('paymentMethod').value=row.payment_method||'bank';
        document.getElementById('paymentChannel').value=row.payment_channel||'manual';
        document.getElementById('paymentStatus').value=row.status||'pending';
        document.getElementById('transactionFee').value=row.transaction_fee||'0.00';
        document.getElementById('transactionReference').value=row.transaction_reference||'';
        document.getElementById('provider').value=row.provider||'';
        document.getElementById('providerPaymentId').value=row.provider_payment_id||'';
        document.getElementById('paymentNotes').value=row.notes||'';

        document.getElementById('paymentModalTitle').textContent='Edit Subscription Payment';
        paymentSaveText.textContent='Update Payment';
    }

    paymentModal.classList.add('show');
}

document
.getElementById('addPaymentBtn')
.addEventListener(
    'click',
    function(){
        openPaymentModal(null);
    }
);

document
.getElementById('paymentModalClose')
.addEventListener('click',closePaymentModal);

document
.getElementById('paymentCancel')
.addEventListener('click',closePaymentModal);

paymentModal.addEventListener(
    'click',
    function(e){
        if(e.target===paymentModal){
            closePaymentModal();
        }
    }
);

document
.querySelectorAll('.payment-edit')
.forEach(function(btn){

    btn.addEventListener(
        'click',
        function(){

            try{

                openPaymentModal(
                    JSON.parse(
                        btn.getAttribute('data-row')
                    )
                );

            }catch(e){

                showToast(
                    'error',
                    'Unable to load payment details.',
                    3000
                );
            }
        }
    );
});

/* Subscription defaults */
var subscriptionSelect=
    document.getElementById('subscriptionId');

subscriptionSelect.addEventListener(
    'change',
    function(){

        var option=
            subscriptionSelect.options[
                subscriptionSelect.selectedIndex
            ];

        if(!option||!option.value){
            return;
        }

        document.getElementById('currencyId').value=
            option.getAttribute('data-currency-id')||'';

        document.getElementById('paymentAmount').value=
            option.getAttribute('data-amount')||'';
    }
);

/* Save */
paymentForm.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        if(!paymentForm.checkValidity()){

            showToast(
                'warning',
                'Please complete the required payment fields.',
                3000
            );

            paymentForm.reportValidity();

            return;
        }

        paymentSaveBtn.disabled=true;
        paymentSaveBtn.classList.add('loading');
        paymentSaveText.textContent='Saving...';

        apiRequest(
            new FormData(paymentForm)
        )
        .then(function(result){

            if(
                !result.ok ||
                !result.data.success
            ){
                throw new Error(
                    result.data.message||
                    'Unable to save payment.'
                );
            }

            showToast(
                'success',
                result.data.message,
                3000
            );

            closePaymentModal();

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
                'Unable to save payment.',
                3000
            );

            paymentSaveBtn.disabled=false;
            paymentSaveBtn.classList.remove('loading');

            paymentSaveText.textContent=
                document.getElementById('paymentId').value!==''
                    ? 'Update Payment'
                    : 'Save Payment';
        });
    }
);

/* Status modal */
var statusModal=
    document.getElementById('statusModal');

var statusForm=
    document.getElementById('statusForm');

var statusSaveBtn=
    document.getElementById('statusSaveBtn');

var statusSaveText=
    document.getElementById('statusSaveText');

function closeStatusModal(){
    statusModal.classList.remove('show');
}

document
.getElementById('statusModalClose')
.addEventListener('click',closeStatusModal);

document
.getElementById('statusCancel')
.addEventListener('click',closeStatusModal);

statusModal.addEventListener(
    'click',
    function(e){

        if(e.target===statusModal){
            closeStatusModal();
        }
    }
);

document
.querySelectorAll('.payment-status')
.forEach(function(btn){

    btn.addEventListener(
        'click',
        function(){

            try{

                var row=
                    JSON.parse(
                        btn.getAttribute('data-row')
                    );

                document.getElementById('statusPaymentId').value=
                    row.id||'';

                document.getElementById('statusValue').value=
                    row.status||'pending';

                statusModal.classList.add('show');

            }catch(e){

                showToast(
                    'error',
                    'Unable to load payment status.',
                    3000
                );
            }
        }
    );
});

statusForm.addEventListener(
    'submit',
    function(e){

        e.preventDefault();

        statusSaveBtn.disabled=true;
        statusSaveBtn.classList.add('loading');
        statusSaveText.textContent='Updating...';

        apiRequest(
            new FormData(statusForm)
        )
        .then(function(result){

            if(
                !result.ok ||
                !result.data.success
            ){
                throw new Error(
                    result.data.message||
                    'Unable to update payment status.'
                );
            }

            showToast(
                'success',
                result.data.message,
                3000
            );

            closeStatusModal();

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
                'Unable to update payment status.',
                3000
            );

            statusSaveBtn.disabled=false;
            statusSaveBtn.classList.remove('loading');
            statusSaveText.textContent='Update Status';
        });
    }
);

})();
</script>

</body>
</html>