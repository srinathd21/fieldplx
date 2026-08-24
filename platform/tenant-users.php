<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Tenant Users';
$activePage = 'tenant-users';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['tenant_users_csrf'])) {
    $_SESSION['tenant_users_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['tenant_users_csrf'];

function tu_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

$tenantId = isset($_GET['tenant_id'])
    ? (int)$_GET['tenant_id']
    : 0;

if ($tenantId <= 0) {
    http_response_code(400);
    exit('Invalid tenant ID.');
}

$tenantStmt = $pdo->prepare("
    SELECT
        id,
        tenant_code,
        legal_name,
        display_name,
        business_type,
        email,
        phone,
        logo_path,
        status
    FROM tenants
    WHERE id = :tenant_id
      AND deleted_at IS NULL
    LIMIT 1
");

$tenantStmt->execute(array(
    ':tenant_id' => $tenantId
));

$tenant = $tenantStmt->fetch();

if (!$tenant) {
    http_response_code(404);
    exit('Tenant not found.');
}

$subscriptionStmt = $pdo->prepare("
    SELECT
        s.id,
        s.plan_id,
        s.status,
        s.max_users_override,
        s.expiry_date,
        p.name AS plan_name,
        p.max_users AS plan_max_users
    FROM subscriptions s
    INNER JOIN plans p
        ON p.id = s.plan_id
    WHERE s.tenant_id = :tenant_id
      AND s.deleted_at IS NULL
    ORDER BY
        CASE
            WHEN s.status = 'active' THEN 1
            WHEN s.status = 'trial' THEN 2
            ELSE 3
        END,
        s.id DESC
    LIMIT 1
");

$subscriptionStmt->execute(array(
    ':tenant_id' => $tenantId
));

$subscription = $subscriptionStmt->fetch();

$maxUsers = null;

if ($subscription) {
    if (
        $subscription['max_users_override'] !== null &&
        $subscription['max_users_override'] !== ''
    ) {
        $maxUsers = (int)$subscription['max_users_override'];
    } elseif (
        $subscription['plan_max_users'] !== null &&
        $subscription['plan_max_users'] !== ''
    ) {
        $maxUsers = (int)$subscription['plan_max_users'];
    }
}

$branchesStmt = $pdo->prepare("
    SELECT
        id,
        branch_code,
        name,
        is_head_office,
        status
    FROM branches
    WHERE tenant_id = :tenant_id
      AND status <> 'archived'
    ORDER BY is_head_office DESC, name ASC
");

$branchesStmt->execute(array(
    ':tenant_id' => $tenantId
));

$branches = $branchesStmt->fetchAll();

$departmentsStmt = $pdo->prepare("
    SELECT
        id,
        branch_id,
        name,
        code,
        status
    FROM departments
    WHERE tenant_id = :tenant_id
    ORDER BY name ASC
");

$departmentsStmt->execute(array(
    ':tenant_id' => $tenantId
));

$departments = $departmentsStmt->fetchAll();

$rolesStmt = $pdo->prepare("
    SELECT
        id,
        name,
        code,
        is_admin,
        is_system_role,
        status
    FROM roles
    WHERE tenant_id = :tenant_id
    ORDER BY is_admin DESC, name ASC
");

$rolesStmt->execute(array(
    ':tenant_id' => $tenantId
));

$roles = $rolesStmt->fetchAll();

$search = trim(
    isset($_GET['search'])
        ? (string)$_GET['search']
        : ''
);

$status = isset($_GET['status'])
    ? (string)$_GET['status']
    : '';

$roleId = isset($_GET['role_id'])
    ? (int)$_GET['role_id']
    : 0;

$branchId = isset($_GET['branch_id'])
    ? (int)$_GET['branch_id']
    : 0;

$page = max(
    1,
    (int)(
        isset($_GET['page'])
            ? $_GET['page']
            : 1
    )
);

$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = array(
    "u.tenant_id = :tenant_id",
    "u.deleted_at IS NULL"
);

$params = array(
    ':tenant_id' => $tenantId
);

if ($search !== '') {
    $where[] = "
        (
            u.first_name LIKE :search
            OR u.last_name LIKE :search
            OR u.email LIKE :search
            OR u.phone LIKE :search
            OR u.employee_code LIKE :search
            OR u.job_title LIKE :search
        )
    ";

    $params[':search'] =
        '%' . $search . '%';
}

$validStatuses = array(
    'active',
    'inactive',
    'invited',
    'suspended'
);

if (
    $status !== '' &&
    in_array($status, $validStatuses, true)
) {
    $where[] = "u.status = :status";
    $params[':status'] = $status;
}

if ($roleId > 0) {
    $where[] = "u.role_id = :role_id";
    $params[':role_id'] = $roleId;
}

if ($branchId > 0) {
    $where[] = "u.branch_id = :branch_id";
    $params[':branch_id'] = $branchId;
}

$whereSql =
    ' WHERE ' .
    implode(' AND ', $where);

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM users u
    " . $whereSql
);

$countStmt->execute($params);

$totalRows =
    (int)$countStmt->fetchColumn();

$totalPages = max(
    1,
    (int)ceil($totalRows / $perPage)
);

$listSql = "
    SELECT
        u.id,
        u.tenant_id,
        u.branch_id,
        u.department_id,
        u.role_id,
        u.employee_code,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.alternate_phone,
        u.avatar_path,
        u.job_title,
        u.labor_rate,
        u.is_bookable,
        u.is_field_worker,
        u.is_tenant_admin,
        u.status,
        u.last_login_at,
        u.created_at,
        b.name AS branch_name,
        b.branch_code,
        d.name AS department_name,
        r.name AS role_name,
        r.code AS role_code
    FROM users u
    LEFT JOIN branches b
        ON b.id = u.branch_id
       AND b.tenant_id = u.tenant_id
    LEFT JOIN departments d
        ON d.id = u.department_id
       AND d.tenant_id = u.tenant_id
    LEFT JOIN roles r
        ON r.id = u.role_id
       AND r.tenant_id = u.tenant_id
    " . $whereSql . "
    ORDER BY
        u.is_tenant_admin DESC,
        u.first_name ASC,
        u.last_name ASC
    LIMIT " . (int)$perPage . "
    OFFSET " . (int)$offset;

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$users = $listStmt->fetchAll();

$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_users,
        SUM(status = 'active') AS active_users,
        SUM(status = 'invited') AS invited_users,
        SUM(status = 'suspended') AS suspended_users,
        SUM(is_field_worker = 1) AS field_workers
    FROM users
    WHERE tenant_id = :tenant_id
      AND deleted_at IS NULL
");

$statsStmt->execute(array(
    ':tenant_id' => $tenantId
));

$stats = $statsStmt->fetch();

$totalTenantUsers =
    (int)($stats['total_users'] ?? 0);

$remainingUsers = null;

if ($maxUsers !== null) {
    $remainingUsers = max(
        0,
        $maxUsers - $totalTenantUsers
    );
}

function tu_initials($firstName, $lastName)
{
    $first =
        trim((string)$firstName);
    $last =
        trim((string)$lastName);

    $initials = '';

    if ($first !== '') {
        $initials .= strtoupper(
            substr($first, 0, 1)
        );
    }

    if ($last !== '') {
        $initials .= strtoupper(
            substr($last, 0, 1)
        );
    }

    return $initials !== ''
        ? $initials
        : 'U';
}

function tu_status_label($status)
{
    return ucwords(
        str_replace(
            '_',
            ' ',
            (string)$status
        )
    );
}
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
    <?= tu_h($pageTitle) ?> - FieldPlx
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

.fp-menu-toggle,.fp-icon-button{
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

.fp-menu-toggle:hover,.fp-icon-button:hover{
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

.fp-profile-text{max-width:145px;min-width:0}

.fp-profile-name,.fp-profile-role{
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

/* PAGE */
.tu-page{
    display:grid;
    gap:16px
}

.tu-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px
}

.tu-title{
    margin:0;
    color:#111827;
    font-size:20px;
    font-weight:800
}

.tu-description{
    margin-top:4px;
    color:#77718e;
    font-size:10px;
    line-height:1.55
}

.tu-header-actions{
    display:flex;
    align-items:center;
    gap:8px
}

.tu-primary,.tu-secondary{
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

.tu-primary{
    border:0;
    background:linear-gradient(135deg,#7c3aed,#6d28d9);
    color:#fff;
    box-shadow:0 8px 20px rgba(109,40,217,.18)
}

.tu-secondary{
    border:1px solid #dcd5ef;
    background:#fff;
    color:#5f5870
}

.tu-primary:disabled,.tu-secondary:disabled{
    opacity:.65;
    cursor:not-allowed
}

/* TENANT STRIP */
.tu-tenant-card{
    padding:13px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    border:1px solid #ded7ef;
    border-radius:13px;
    background:#fbf9ff
}

.tu-tenant-main{
    display:flex;
    align-items:center;
    gap:11px;
    min-width:0
}

.tu-tenant-logo{
    width:42px;
    height:42px;
    flex:0 0 42px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    border:1px solid #dfd7f2;
    border-radius:11px;
    background:#fff;
    color:#7c3aed;
    font-size:13px;
    font-weight:800
}

.tu-tenant-logo img{
    width:100%;
    height:100%;
    object-fit:contain
}

.tu-tenant-name{
    color:#211c32;
    font-size:12px;
    font-weight:800
}

.tu-tenant-meta{
    margin-top:3px;
    display:flex;
    flex-wrap:wrap;
    gap:5px 12px;
    color:#8e879e;
    font-size:8px
}

.tu-tenant-right{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    justify-content:flex-end
}

/* STATS - SAME DESIGN AS ALL TENANTS SUMMARY CARDS */
.tu-stats{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px
}

.tu-stat{
    min-height:90px;
    padding:14px 15px;
    display:flex;
    align-items:center;
    gap:11px;
    border:1px solid #ddd5f1;
    border-radius:13px;
    background:
        linear-gradient(
            180deg,
            #ffffff 0%,
            #fbf9ff 100%
        );
    box-shadow:none
}

.tu-stat:hover{
    border-color:#cfc3ef;
    background:
        linear-gradient(
            180deg,
            #ffffff 0%,
            #f8f4ff 100%
        )
}

.tu-stat-icon{
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

.tu-stat-content{
    min-width:0;
    display:block
}

.tu-stat-label{
    display:block;
    color:#9a94ae;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
    line-height:1.3
}

.tu-stat-value{
    margin-top:2px;
    display:block;
    color:#111827;
    font-size:20px;
    font-weight:800;
    line-height:1.2
}

.tu-stat-note{
    margin-top:2px;
    display:block;
    color:#9d96ac;
    font-size:7.5px;
    line-height:1.35
}
/* CARD */
.tu-card{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 24px rgba(37,29,80,.05)
}

.tu-card-header{
    min-height:54px;
    padding:12px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff
}

.tu-card-title-wrap{
    display:flex;
    align-items:center;
    gap:10px
}

.tu-card-icon{
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

.tu-card-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800
}

.tu-card-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px
}

/* FILTERS */
.tu-tools{
    padding:13px 15px;
    display:grid;
    grid-template-columns:minmax(210px,1fr) 155px 170px 170px auto;
    gap:9px;
    border-bottom:1px solid #eee9f7
}

.tu-search{position:relative}

.tu-search i{
    position:absolute;
    left:12px;
    top:50%;
    transform:translateY(-50%);
    color:#918aa2;
    font-size:13px
}

.tu-input,.tu-select{
    width:100%;
    height:39px;
    padding:8px 11px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    outline:0;
    background:#fff;
    color:#312b47;
    font-size:10px
}

.tu-search .tu-input{
    padding-left:34px
}

.tu-input:focus,.tu-select:focus{
    border-color:#a78bfa;
    box-shadow:0 0 0 3px rgba(139,92,246,.10)
}

/* TABLE */
.tu-table-wrap{overflow:auto}

.tu-table{
    width:100%;
    min-width:1080px;
    border-collapse:collapse
}

.tu-table th{
    padding:10px 12px;
    border-bottom:1px solid #e8e2f2;
    background:#f8f6ff;
    color:#726a86;
    text-align:left;
    font-size:8px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.04em;
    white-space:nowrap
}

.tu-table td{
    padding:10px 12px;
    border-bottom:1px solid #f0ecf7;
    color:#433d54;
    font-size:9px;
    vertical-align:middle
}

.tu-table tbody tr:hover{
    background:#fcfbff
}

.tu-user{
    display:flex;
    align-items:center;
    gap:9px;
    min-width:190px
}

.tu-user-avatar{
    width:34px;
    height:34px;
    flex:0 0 34px;
    overflow:hidden;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:9px;
    background:linear-gradient(135deg,#ede9fe,#ddd6fe);
    color:#6d28d9;
    font-size:9px;
    font-weight:800
}

.tu-user-avatar img{
    width:100%;
    height:100%;
    object-fit:cover
}

.tu-user-name{
    color:#2f2940;
    font-size:9px;
    font-weight:800
}

.tu-user-sub{
    margin-top:2px;
    color:#948da3;
    font-size:8px
}

.tu-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700
}

.tu-badge.active{
    background:#ecfdf5;
    color:#047857
}

.tu-badge.inactive{
    background:#f3f4f6;
    color:#6b7280
}

.tu-badge.invited{
    background:#eef2ff;
    color:#4338ca
}

.tu-badge.suspended{
    background:#fef2f2;
    color:#b91c1c
}

.tu-badge.admin{
    background:#f1ecff;
    color:#6d28d9
}

.tu-actions{
    display:flex;
    align-items:center;
    gap:5px
}

.tu-icon-btn{
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

.tu-icon-btn:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9
}

.tu-icon-btn.danger:hover{
    border-color:#fecaca;
    background:#fef2f2;
    color:#dc2626
}

.tu-empty{
    padding:38px 15px;
    text-align:center;
    color:#928aa5;
    font-size:10px
}

/* PAGINATION */
.tu-footer{
    padding:11px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    background:#fbf9ff
}

.tu-count{
    color:#8f879f;
    font-size:9px
}

.tu-pagination{
    display:flex;
    align-items:center;
    gap:5px
}

.tu-page-btn{
    min-width:30px;
    height:30px;
    padding:0 9px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#fff;
    color:#615970;
    font-size:9px
}

.tu-page-btn.active{
    border-color:#8b5cf6;
    background:#8b5cf6;
    color:#fff
}

.tu-page-btn.disabled{
    opacity:.45;
    pointer-events:none
}

/* MODAL */
.tu-modal-backdrop{
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

.tu-modal-backdrop.show{
    display:flex
}

.tu-modal{
    width:min(760px,100%);
    max-height:calc(100vh - 36px);
    overflow:auto;
    border:1px solid #ded7ef;
    border-radius:15px;
    background:#fff;
    box-shadow:0 24px 60px rgba(28,20,70,.22)
}

.tu-modal-header{
    padding:13px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff
}

.tu-modal-title-wrap{
    display:flex;
    align-items:center;
    gap:10px
}

.tu-modal-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800
}

.tu-modal-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px
}

.tu-modal-close{
    width:30px;
    height:30px;
    border:1px solid #ddd6ec;
    border-radius:8px;
    background:#fff;
    color:#6d657d;
    cursor:pointer
}

.tu-modal-body{padding:15px}

.tu-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:13px
}

.tu-field.full{
    grid-column:1/-1
}

.tu-field label{
    margin-bottom:6px;
    display:block;
    color:#4c465f;
    font-size:9px;
    font-weight:700
}

.tu-required{
    color:#dc2626
}

.tu-note{
    margin-top:5px;
    color:#9a94aa;
    font-size:8px;
    line-height:1.45
}

.tu-toggle{
    min-height:52px;
    padding:10px 11px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border:1px solid #ded7ef;
    border-radius:10px;
    background:#fbf9ff
}

.tu-toggle strong{
    display:block;
    color:#393248;
    font-size:9px
}

.tu-toggle span span{
    margin-top:3px;
    display:block;
    color:#9a94aa;
    font-size:8px
}

.tu-modal-footer{
    padding:12px 15px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
    border-top:1px solid #ece7f7;
    background:#fbf9ff
}

.tu-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:tuSpin .75s linear infinite
}

.tu-primary.loading .tu-loader{
    display:inline-block
}

@keyframes tuSpin{
    to{transform:rotate(360deg)}
}

/* TOAST */
.tu-toast{
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

.tu-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0)
}

.tu-toast.success{background:#059669}
.tu-toast.error{background:#dc2626}
.tu-toast.warning{background:#d97706}
.tu-toast.info{background:#4f46e5}

.tu-toast-icon{
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

.tu-toast-message{
    flex:1;
    min-width:0;
    font-weight:600
}

.tu-toast-close{
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

.tu-toast-close:hover{
    background:rgba(255,255,255,.12);
    opacity:1
}

@media(max-width:1200px){
    .tu-tools{
        grid-template-columns:minmax(220px,1fr) repeat(2,160px);
    }

    .tu-tools .tu-role-filter{
        grid-column:auto
    }

    .tu-tools .tu-filter-button{
        grid-column:1/-1
    }
}

@media(max-width:1100px){
    .tu-stats{
        grid-template-columns:repeat(2,minmax(0,1fr))
    }
}

@media(max-width:991.98px){
    .fp-main,
    body.fp-sidebar-collapsed .fp-main{
        margin-left:0
    }

    .fp-search,.fp-profile-text{
        display:none
    }

    .fp-mobile-brand{
        display:inline-flex
    }
}

@media(max-width:760px){
    .tu-header,.tu-tenant-card{
        flex-direction:column;
        align-items:stretch
    }

    .tu-header-actions{
        width:100%
    }

    .tu-header-actions .tu-primary,
    .tu-header-actions .tu-secondary{
        flex:1
    }

    .tu-tenant-right{
        justify-content:flex-start
    }

    .tu-tools{
        grid-template-columns:1fr
    }

    .tu-tools .tu-filter-button{
        grid-column:auto
    }

    .tu-form-grid{
        grid-template-columns:1fr
    }

    .tu-field.full{
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

    .tu-stats{
        grid-template-columns:1fr
    }

    .tu-stat{
        min-height:82px
    }

    .tu-footer{
        align-items:flex-start;
        flex-direction:column
    }

    .tu-modal-footer{
        flex-direction:column-reverse
    }

    .tu-modal-footer .tu-primary,
    .tu-modal-footer .tu-secondary{
        width:100%
    }

    .tu-toast{
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
<div class="tu-page">

<div class="tu-header">
    <div>
        <h2 class="tu-title">Tenant Users</h2>

        <div class="tu-description">
            Manage tenant administrators, office users, field workers,
            roles, branch assignment and account status.
        </div>
    </div>

    <div class="tu-header-actions">
        <a
            href="tenants.php"
            class="tu-secondary"
        >
            <i class="bi bi-arrow-left"></i>
            All Tenants
        </a>

        <button
            type="button"
            class="tu-primary"
            id="addUserBtn"
            <?= $remainingUsers !== null && $remainingUsers <= 0 ? 'disabled' : '' ?>
        >
            <i class="bi bi-person-plus"></i>
            Add User
        </button>
    </div>
</div>

<div class="tu-tenant-card">

<div class="tu-tenant-main">
    <div class="tu-tenant-logo">
        <?php if (!empty($tenant['logo_path'])): ?>
            <img
                src="<?= tu_h($tenant['logo_path']) ?>"
                alt="<?= tu_h($tenant['display_name']) ?>"
            >
        <?php else: ?>
            <?= tu_h(
                strtoupper(
                    substr(
                        $tenant['display_name'],
                        0,
                        2
                    )
                )
            ) ?>
        <?php endif; ?>
    </div>

    <div>
        <div class="tu-tenant-name">
            <?= tu_h($tenant['display_name']) ?>
        </div>

        <div class="tu-tenant-meta">
            <span>
                <i class="bi bi-hash"></i>
                <?= tu_h($tenant['tenant_code']) ?>
            </span>

            <?php if (!empty($tenant['business_type'])): ?>
            <span>
                <i class="bi bi-briefcase"></i>
                <?= tu_h($tenant['business_type']) ?>
            </span>
            <?php endif; ?>

            <?php if (!empty($tenant['email'])): ?>
            <span>
                <i class="bi bi-envelope"></i>
                <?= tu_h($tenant['email']) ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="tu-tenant-right">
    <span class="tu-badge <?= tu_h($tenant['status']) ?>">
        <?= tu_h(tu_status_label($tenant['status'])) ?>
    </span>

    <?php if ($subscription): ?>
    <span class="tu-badge admin">
        <?= tu_h($subscription['plan_name']) ?>
        ·
        <?= tu_h(tu_status_label($subscription['status'])) ?>
    </span>
    <?php endif; ?>
</div>

</div>

<div class="tu-stats">

<div class="tu-stat">
    <span class="tu-stat-icon">
        <i class="bi bi-people"></i>
    </span>

    <span class="tu-stat-content">
        <span class="tu-stat-label">
            Total Users
        </span>

        <span class="tu-stat-value">
            <?= number_format($totalTenantUsers) ?>
        </span>

        <span class="tu-stat-note">
            Non-deleted tenant accounts
        </span>
    </span>
</div>

<div class="tu-stat">
    <span class="tu-stat-icon">
        <i class="bi bi-person-check"></i>
    </span>

    <span class="tu-stat-content">
        <span class="tu-stat-label">
            Active
        </span>

        <span class="tu-stat-value">
            <?= number_format((int)($stats['active_users'] ?? 0)) ?>
        </span>

        <span class="tu-stat-note">
            Active user accounts
        </span>
    </span>
</div>

<div class="tu-stat">
    <span class="tu-stat-icon">
        <i class="bi bi-envelope-arrow-up"></i>
    </span>

    <span class="tu-stat-content">
        <span class="tu-stat-label">
            Invited
        </span>

        <span class="tu-stat-value">
            <?= number_format((int)($stats['invited_users'] ?? 0)) ?>
        </span>

        <span class="tu-stat-note">
            Waiting for activation
        </span>
    </span>
</div>

<div class="tu-stat">
    <span class="tu-stat-icon">
        <i class="bi bi-geo-alt"></i>
    </span>

    <span class="tu-stat-content">
        <span class="tu-stat-label">
            Field Workers
        </span>

        <span class="tu-stat-value">
            <?= number_format((int)($stats['field_workers'] ?? 0)) ?>
        </span>

        <span class="tu-stat-note">
            Field-enabled users
        </span>
    </span>
</div>

<div class="tu-stat">
    <span class="tu-stat-icon">
        <i class="bi bi-speedometer2"></i>
    </span>

    <span class="tu-stat-content">
        <span class="tu-stat-label">
            Plan Capacity
        </span>

        <span class="tu-stat-value">
            <?php if ($maxUsers === null): ?>
                ∞
            <?php else: ?>
                <?= number_format($totalTenantUsers) ?>/<?= number_format($maxUsers) ?>
            <?php endif; ?>
        </span>

        <span class="tu-stat-note">
            <?php if ($remainingUsers === null): ?>
                Unlimited / not restricted
            <?php else: ?>
                <?= number_format($remainingUsers) ?> user slot(s) remaining
            <?php endif; ?>
        </span>
    </span>
</div>

</div>

<section class="tu-card">

<div class="tu-card-header">
    <div class="tu-card-title-wrap">
        <span class="tu-card-icon">
            <i class="bi bi-people"></i>
        </span>

        <span>
            <h3 class="tu-card-title">
                Users - <?= tu_h($tenant['display_name']) ?>
            </h3>

            <span class="tu-card-subtitle">
                Tenant-scoped user accounts and access assignments
            </span>
        </span>
    </div>
</div>

<form
    method="get"
    class="tu-tools"
>
<input
    type="hidden"
    name="tenant_id"
    value="<?= $tenantId ?>"
>

<div class="tu-search">
    <i class="bi bi-search"></i>

    <input
        type="text"
        class="tu-input"
        name="search"
        value="<?= tu_h($search) ?>"
        placeholder="Search user, email, phone, employee code..."
    >
</div>

<select
    class="tu-select"
    name="status"
    onchange="this.form.submit()"
>
    <option value="">All Status</option>

    <?php foreach ($validStatuses as $statusOption): ?>
    <option
        value="<?= tu_h($statusOption) ?>"
        <?= $status === $statusOption ? 'selected' : '' ?>
    >
        <?= tu_h(tu_status_label($statusOption)) ?>
    </option>
    <?php endforeach; ?>
</select>

<select
    class="tu-select"
    name="branch_id"
    onchange="this.form.submit()"
>
    <option value="0">All Branches</option>

    <?php foreach ($branches as $branch): ?>
    <option
        value="<?= (int)$branch['id'] ?>"
        <?= $branchId === (int)$branch['id'] ? 'selected' : '' ?>
    >
        <?= tu_h($branch['name']) ?>
    </option>
    <?php endforeach; ?>
</select>

<select
    class="tu-select tu-role-filter"
    name="role_id"
    onchange="this.form.submit()"
>
    <option value="0">All Roles</option>

    <?php foreach ($roles as $role): ?>
    <option
        value="<?= (int)$role['id'] ?>"
        <?= $roleId === (int)$role['id'] ? 'selected' : '' ?>
    >
        <?= tu_h($role['name']) ?>
    </option>
    <?php endforeach; ?>
</select>

<button
    type="submit"
    class="tu-secondary tu-filter-button"
>
    <i class="bi bi-funnel"></i>
    Filter
</button>

</form>

<div class="tu-table-wrap">
<table class="tu-table">

<thead>
<tr>
    <th>S/No</th>
    <th>User</th>
    <th>Employee Code</th>
    <th>Role</th>
    <th>Branch / Department</th>
    <th>Phone</th>
    <th>User Type</th>
    <th>Last Login</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$users): ?>

<tr>
<td colspan="10">
    <div class="tu-empty">
        No tenant users found.
    </div>
</td>
</tr>

<?php else: ?>

<?php foreach ($users as $index => $user): ?>

<tr>

<td>
    <?= $offset + $index + 1 ?>
</td>

<td>
    <div class="tu-user">

        <div class="tu-user-avatar">
            <?php if (!empty($user['avatar_path'])): ?>
                <img
                    src="<?= tu_h($user['avatar_path']) ?>"
                    alt="<?= tu_h($user['first_name']) ?>"
                >
            <?php else: ?>
                <?= tu_h(
                    tu_initials(
                        $user['first_name'],
                        $user['last_name']
                    )
                ) ?>
            <?php endif; ?>
        </div>

        <div>
            <div class="tu-user-name">
                <?= tu_h(
                    trim(
                        $user['first_name'] .
                        ' ' .
                        $user['last_name']
                    )
                ) ?>
            </div>

            <div class="tu-user-sub">
                <?= tu_h($user['email']) ?>
            </div>

            <?php if (!empty($user['job_title'])): ?>
            <div class="tu-user-sub">
                <?= tu_h($user['job_title']) ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</td>

<td>
    <?= tu_h($user['employee_code']) ?: '-' ?>
</td>

<td>
    <?php if (!empty($user['role_name'])): ?>
        <?= tu_h($user['role_name']) ?>
        <div class="tu-user-sub">
            <?= tu_h($user['role_code']) ?>
        </div>
    <?php else: ?>
        -
    <?php endif; ?>
</td>

<td>
    <?= tu_h($user['branch_name']) ?: 'All / Unassigned' ?>

    <?php if (!empty($user['department_name'])): ?>
    <div class="tu-user-sub">
        <?= tu_h($user['department_name']) ?>
    </div>
    <?php endif; ?>
</td>

<td>
    <?= tu_h($user['phone']) ?: '-' ?>

    <?php if (!empty($user['alternate_phone'])): ?>
    <div class="tu-user-sub">
        Alt: <?= tu_h($user['alternate_phone']) ?>
    </div>
    <?php endif; ?>
</td>

<td>
    <div style="display:flex;flex-wrap:wrap;gap:4px">
        <?php if ((int)$user['is_tenant_admin'] === 1): ?>
            <span class="tu-badge admin">Tenant Admin</span>
        <?php endif; ?>

        <?php if ((int)$user['is_field_worker'] === 1): ?>
            <span class="tu-badge invited">Field Worker</span>
        <?php endif; ?>

        <?php if ((int)$user['is_bookable'] === 1): ?>
            <span class="tu-badge active">Bookable</span>
        <?php endif; ?>

        <?php if (
            (int)$user['is_tenant_admin'] !== 1 &&
            (int)$user['is_field_worker'] !== 1 &&
            (int)$user['is_bookable'] !== 1
        ): ?>
            -
        <?php endif; ?>
    </div>
</td>

<td>
    <?php if (!empty($user['last_login_at'])): ?>
        <?= tu_h(
            date(
                'd M Y',
                strtotime($user['last_login_at'])
            )
        ) ?>

        <div class="tu-user-sub">
            <?= tu_h(
                date(
                    'h:i A',
                    strtotime($user['last_login_at'])
                )
            ) ?>
        </div>
    <?php else: ?>
        Never
    <?php endif; ?>
</td>

<td>
    <span class="tu-badge <?= tu_h($user['status']) ?>">
        <?= tu_h(tu_status_label($user['status'])) ?>
    </span>
</td>

<td>
    <div class="tu-actions">

        <button
            type="button"
            class="tu-icon-btn tu-edit-user"
            data-row='<?= tu_h(
                json_encode(
                    $user,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            ) ?>'
            title="Edit user"
        >
            <i class="bi bi-pencil"></i>
        </button>

        <button
            type="button"
            class="tu-icon-btn tu-toggle-user"
            data-id="<?= (int)$user['id'] ?>"
            data-status="<?= tu_h($user['status']) ?>"
            title="<?= $user['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"
        >
            <i class="bi <?= $user['status'] === 'active' ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
        </button>

        <button
            type="button"
            class="tu-icon-btn danger tu-delete-user"
            data-id="<?= (int)$user['id'] ?>"
            data-name="<?= tu_h(
                trim(
                    $user['first_name'] .
                    ' ' .
                    $user['last_name']
                )
            ) ?>"
            title="Remove user"
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
</div>

<div class="tu-footer">

<div class="tu-count">
    Showing
    <?= $totalRows ? $offset + 1 : 0 ?>
    to
    <?= min($offset + $perPage, $totalRows) ?>
    of
    <?= $totalRows ?>
    user(s)
</div>

<div class="tu-pagination">

<?php
$prevQuery = $_GET;
$prevQuery['tenant_id'] = $tenantId;
$prevQuery['page'] = max(1, $page - 1);
?>

<a
    class="tu-page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
    href="?<?= tu_h(http_build_query($prevQuery)) ?>"
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
    $pageQuery['tenant_id'] = $tenantId;
    $pageQuery['page'] = $p;
?>

<a
    class="tu-page-btn <?= $p === $page ? 'active' : '' ?>"
    href="?<?= tu_h(http_build_query($pageQuery)) ?>"
>
    <?= $p ?>
</a>

<?php endfor; ?>

<?php
$nextQuery = $_GET;
$nextQuery['tenant_id'] = $tenantId;
$nextQuery['page'] =
    min(
        $totalPages,
        $page + 1
    );
?>

<a
    class="tu-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
    href="?<?= tu_h(http_build_query($nextQuery)) ?>"
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
    id="tuToast"
    class="tu-toast"
    role="status"
    aria-live="polite"
    aria-atomic="true"
>
    <span class="tu-toast-icon">
        <i
            id="tuToastIcon"
            class="bi bi-check-lg"
        ></i>
    </span>

    <span
        id="tuToastMessage"
        class="tu-toast-message"
    >
        Saved successfully.
    </span>

    <button
        type="button"
        id="tuToastClose"
        class="tu-toast-close"
        aria-label="Close"
    >
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<!-- ADD / EDIT USER MODAL -->
<div
    class="tu-modal-backdrop"
    id="userModal"
>
<div class="tu-modal">

<form
    id="userForm"
    novalidate
>
<input
    type="hidden"
    name="csrf_token"
    value="<?= tu_h($csrfToken) ?>"
>

<input
    type="hidden"
    name="action"
    value="save_user"
>

<input
    type="hidden"
    name="tenant_id"
    value="<?= $tenantId ?>"
>

<input
    type="hidden"
    name="id"
    id="userId"
>

<div class="tu-modal-header">

<div class="tu-modal-title-wrap">
    <span class="tu-card-icon">
        <i class="bi bi-person-gear"></i>
    </span>

    <span>
        <h3
            class="tu-modal-title"
            id="userModalTitle"
        >
            Add Tenant User
        </h3>

        <span class="tu-modal-subtitle">
            User account, organization assignment and access type
        </span>
    </span>
</div>

<button
    type="button"
    class="tu-modal-close"
    id="userModalClose"
>
    <i class="bi bi-x-lg"></i>
</button>

</div>

<div class="tu-modal-body">

<div class="tu-form-grid">

<div class="tu-field">
    <label>
        First Name
        <span class="tu-required">*</span>
    </label>

    <input
        class="tu-input"
        name="first_name"
        id="userFirstName"
        maxlength="120"
        required
    >
</div>

<div class="tu-field">
    <label>Last Name</label>

    <input
        class="tu-input"
        name="last_name"
        id="userLastName"
        maxlength="120"
    >
</div>

<div class="tu-field">
    <label>
        Email
        <span class="tu-required">*</span>
    </label>

    <input
        class="tu-input"
        type="email"
        name="email"
        id="userEmail"
        maxlength="190"
        required
    >
</div>

<div class="tu-field">
    <label>Employee Code</label>

    <input
        class="tu-input"
        name="employee_code"
        id="userEmployeeCode"
        maxlength="80"
    >
</div>

<div class="tu-field">
    <label>Phone</label>

    <input
        class="tu-input"
        name="phone"
        id="userPhone"
        maxlength="50"
    >
</div>

<div class="tu-field">
    <label>Alternate Phone</label>

    <input
        class="tu-input"
        name="alternate_phone"
        id="userAlternatePhone"
        maxlength="50"
    >
</div>

<div class="tu-field">
    <label>Job Title</label>

    <input
        class="tu-input"
        name="job_title"
        id="userJobTitle"
        maxlength="120"
    >
</div>

<div class="tu-field">
    <label>Labor Rate</label>

    <input
        class="tu-input"
        type="number"
        step="0.01"
        min="0"
        name="labor_rate"
        id="userLaborRate"
    >
</div>

<div class="tu-field">
    <label>Branch</label>

    <select
        class="tu-select"
        name="branch_id"
        id="userBranch"
    >
        <option value="">
            No specific branch
        </option>

        <?php foreach ($branches as $branch): ?>
        <option
            value="<?= (int)$branch['id'] ?>"
        >
            <?= tu_h($branch['name']) ?>
            <?= (int)$branch['is_head_office'] === 1 ? ' - Head Office' : '' ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="tu-field">
    <label>Department</label>

    <select
        class="tu-select"
        name="department_id"
        id="userDepartment"
    >
        <option value="">
            No department
        </option>

        <?php foreach ($departments as $department): ?>
        <option
            value="<?= (int)$department['id'] ?>"
            data-branch-id="<?= $department['branch_id'] !== null ? (int)$department['branch_id'] : '' ?>"
        >
            <?= tu_h($department['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="tu-field">
    <label>Role</label>

    <select
        class="tu-select"
        name="role_id"
        id="userRole"
    >
        <option value="">
            No role
        </option>

        <?php foreach ($roles as $role): ?>
        <option
            value="<?= (int)$role['id'] ?>"
        >
            <?= tu_h($role['name']) ?>
            <?= (int)$role['is_admin'] === 1 ? ' - Admin' : '' ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="tu-field">
    <label>
        Account Status
        <span class="tu-required">*</span>
    </label>

    <select
        class="tu-select"
        name="status"
        id="userStatus"
        required
    >
        <?php foreach ($validStatuses as $statusOption): ?>
        <option value="<?= tu_h($statusOption) ?>">
            <?= tu_h(tu_status_label($statusOption)) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="tu-field full">
    <label>
        <span id="passwordLabel">
            Temporary Password
        </span>
        <span
            class="tu-required"
            id="passwordRequired"
        >
            *
        </span>
    </label>

    <input
        class="tu-input"
        type="password"
        name="password"
        id="userPassword"
        minlength="8"
        maxlength="128"
        autocomplete="new-password"
    >

    <div
        class="tu-note"
        id="passwordNote"
    >
        Minimum 8 characters. Required when creating a new user.
    </div>
</div>

<div class="tu-field full">
    <label class="tu-toggle">
        <span>
            <strong>Tenant Administrator</strong>
            <span>
                Marks this account as a tenant administrator.
                Role permissions still control application access.
            </span>
        </span>

        <span class="form-check form-switch m-0">
            <input
                class="form-check-input"
                type="checkbox"
                name="is_tenant_admin"
                id="userTenantAdmin"
                value="1"
            >
        </span>
    </label>
</div>

<div class="tu-field">
    <label class="tu-toggle">
        <span>
            <strong>Field Worker</strong>
            <span>
                Enable field job and visit usage.
            </span>
        </span>

        <span class="form-check form-switch m-0">
            <input
                class="form-check-input"
                type="checkbox"
                name="is_field_worker"
                id="userFieldWorker"
                value="1"
            >
        </span>
    </label>
</div>

<div class="tu-field">
    <label class="tu-toggle">
        <span>
            <strong>Bookable</strong>
            <span>
                Allow scheduling/assignment availability.
            </span>
        </span>

        <span class="form-check form-switch m-0">
            <input
                class="form-check-input"
                type="checkbox"
                name="is_bookable"
                id="userBookable"
                value="1"
                checked
            >
        </span>
    </label>
</div>

</div>
</div>

<div class="tu-modal-footer">

<button
    type="button"
    class="tu-secondary"
    id="userCancel"
>
    Cancel
</button>

<button
    type="submit"
    class="tu-primary"
    id="userSaveBtn"
>
    <span class="tu-loader"></span>

    <i class="bi bi-check2-circle"></i>

    <span id="userSaveText">
        Save User
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

var toast=document.getElementById('tuToast');
var toastMessage=document.getElementById('tuToastMessage');
var toastIcon=document.getElementById('tuToastIcon');
var toastClose=document.getElementById('tuToastClose');
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

    toast.className='tu-toast '+t;
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

if(toastClose){
    toastClose.addEventListener('click',function(){
        if(toastTimer){
            clearTimeout(toastTimer);
        }

        toast.classList.remove('show');
    });
}

function apiRequest(formData){
    return fetch(
        'api/tenant-users.php',
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

/* USER MODAL */

var userModal=
    document.getElementById('userModal');

var userForm=
    document.getElementById('userForm');

var userSaveBtn=
    document.getElementById('userSaveBtn');

var userSaveText=
    document.getElementById('userSaveText');

var userBranch=
    document.getElementById('userBranch');

var userDepartment=
    document.getElementById('userDepartment');

var userPassword=
    document.getElementById('userPassword');

var passwordRequired=
    document.getElementById('passwordRequired');

var passwordNote=
    document.getElementById('passwordNote');

function filterDepartments(
    branchId,
    selectedDepartment
){
    var requested=
        String(
            selectedDepartment || ''
        );

    Array.prototype.forEach.call(
        userDepartment.options,
        function(option){
            if(option.value===''){
                option.hidden=false;
                return;
            }

            var optionBranch=
                option.getAttribute(
                    'data-branch-id'
                ) || '';

            var show=
                !branchId ||
                optionBranch==='' ||
                optionBranch===String(branchId);

            option.hidden=!show;

            if(
                !show &&
                option.selected
            ){
                option.selected=false;
            }
        }
    );

    if(requested!==''){
        var target=
            userDepartment.querySelector(
                'option[value="'+
                requested.replace(/"/g,'\\"')+
                '"]'
            );

        if(
            target &&
            !target.hidden
        ){
            userDepartment.value=requested;
        }
    }
}

function openUserModal(row){
    userForm.reset();

    document.getElementById('userId').value='';
    document.getElementById('userModalTitle').textContent='Add Tenant User';
    userSaveText.textContent='Save User';

    passwordRequired.style.display='';
    passwordNote.textContent=
        'Minimum 8 characters. Required when creating a new user.';
    userPassword.required=true;

    document.getElementById('userBookable').checked=true;

    if(row){
        document.getElementById('userId').value=row.id||'';
        document.getElementById('userFirstName').value=row.first_name||'';
        document.getElementById('userLastName').value=row.last_name||'';
        document.getElementById('userEmail').value=row.email||'';
        document.getElementById('userEmployeeCode').value=row.employee_code||'';
        document.getElementById('userPhone').value=row.phone||'';
        document.getElementById('userAlternatePhone').value=row.alternate_phone||'';
        document.getElementById('userJobTitle').value=row.job_title||'';
        document.getElementById('userLaborRate').value=row.labor_rate||'';
        document.getElementById('userBranch').value=row.branch_id||'';
        document.getElementById('userRole').value=row.role_id||'';
        document.getElementById('userStatus').value=row.status||'active';
        document.getElementById('userTenantAdmin').checked=String(row.is_tenant_admin)==='1';
        document.getElementById('userFieldWorker').checked=String(row.is_field_worker)==='1';
        document.getElementById('userBookable').checked=String(row.is_bookable)==='1';

        filterDepartments(
            row.branch_id||'',
            row.department_id||''
        );

        document.getElementById('userModalTitle').textContent='Edit Tenant User';
        userSaveText.textContent='Update User';

        passwordRequired.style.display='none';
        passwordNote.textContent=
            'Leave blank to keep the current password. Enter 8+ characters only when changing it.';
        userPassword.required=false;
    }else{
        filterDepartments('', '');
    }

    userModal.classList.add('show');
}

function closeUserModal(){
    userModal.classList.remove('show');
}

userBranch.addEventListener(
    'change',
    function(){
        filterDepartments(
            userBranch.value,
            ''
        );
    }
);

document
    .getElementById('addUserBtn')
    .addEventListener(
        'click',
        function(){
            openUserModal(null);
        }
    );

document
    .getElementById('userModalClose')
    .addEventListener(
        'click',
        closeUserModal
    );

document
    .getElementById('userCancel')
    .addEventListener(
        'click',
        closeUserModal
    );

userModal.addEventListener(
    'click',
    function(e){
        if(e.target===userModal){
            closeUserModal();
        }
    }
);

document
    .querySelectorAll('.tu-edit-user')
    .forEach(function(btn){
        btn.addEventListener(
            'click',
            function(){
                try{
                    openUserModal(
                        JSON.parse(
                            btn.getAttribute(
                                'data-row'
                            )
                        )
                    );
                }catch(error){
                    showToast(
                        'error',
                        'Unable to load user details.',
                        3000
                    );
                }
            }
        );
    });

userForm.addEventListener(
    'submit',
    function(e){
        e.preventDefault();

        if(!userForm.checkValidity()){
            showToast(
                'warning',
                'Please complete the required user fields correctly.',
                3000
            );

            userForm.reportValidity();
            return;
        }

        userSaveBtn.disabled=true;
        userSaveBtn.classList.add('loading');
        userSaveText.textContent='Saving...';

        apiRequest(
            new FormData(userForm)
        )
        .then(function(result){
            if(
                !result.ok ||
                !result.data.success
            ){
                throw new Error(
                    result.data.message ||
                    'Unable to save tenant user.'
                );
            }

            showToast(
                'success',
                result.data.message,
                3000
            );

            closeUserModal();

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
                'Unable to save tenant user.',
                3000
            );

            userSaveBtn.disabled=false;
            userSaveBtn.classList.remove('loading');

            userSaveText.textContent=
                document
                    .getElementById('userId')
                    .value
                    ? 'Update User'
                    : 'Save User';
        });
    });

/* STATUS */

document
    .querySelectorAll('.tu-toggle-user')
    .forEach(function(btn){
        btn.addEventListener(
            'click',
            function(){
                var currentStatus=
                    btn.getAttribute(
                        'data-status'
                    );

                var nextStatus=
                    currentStatus==='active'
                        ? 'inactive'
                        : 'active';

                var fd=new FormData();

                fd.append(
                    'csrf_token',
                    '<?= tu_h($csrfToken) ?>'
                );

                fd.append(
                    'action',
                    'change_status'
                );

                fd.append(
                    'tenant_id',
                    '<?= $tenantId ?>'
                );

                fd.append(
                    'id',
                    btn.getAttribute(
                        'data-id'
                    )
                );

                fd.append(
                    'status',
                    nextStatus
                );

                apiRequest(fd)
                .then(function(result){
                    if(
                        !result.ok ||
                        !result.data.success
                    ){
                        throw new Error(
                            result.data.message ||
                            'Unable to update user status.'
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
                        'Unable to update user status.',
                        3000
                    );
                });
            }
        );
    });

/* SOFT DELETE */

document
    .querySelectorAll('.tu-delete-user')
    .forEach(function(btn){
        btn.addEventListener(
            'click',
            function(){
                var name=
                    btn.getAttribute(
                        'data-name'
                    );

                if(
                    !window.confirm(
                        'Remove '+name+
                        ' from this tenant? The account will be soft deleted.'
                    )
                ){
                    return;
                }

                var fd=new FormData();

                fd.append(
                    'csrf_token',
                    '<?= tu_h($csrfToken) ?>'
                );

                fd.append(
                    'action',
                    'delete_user'
                );

                fd.append(
                    'tenant_id',
                    '<?= $tenantId ?>'
                );

                fd.append(
                    'id',
                    btn.getAttribute(
                        'data-id'
                    )
                );

                apiRequest(fd)
                .then(function(result){
                    if(
                        !result.ok ||
                        !result.data.success
                    ){
                        throw new Error(
                            result.data.message ||
                            'Unable to remove tenant user.'
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
                        'Unable to remove tenant user.',
                        3000
                    );
                });
            }
        );
    });

})();
</script>

</body>
</html>