<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Platform Users';
$activePage = 'platform-users';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Platform authentication
|--------------------------------------------------------------------------
| Existing FieldPlx Platform auth uses platform_authenticated + user id.
| platform_logged_in is accepted only for backward compatibility.
*/
if (
    empty($_SESSION['platform_user_id']) ||
    (
        empty($_SESSION['platform_authenticated']) &&
        empty($_SESSION['platform_logged_in'])
    )
) {
    header(
        'Location: login.php?return_to=' .
        rawurlencode('platform-users.php')
    );
    exit;
}

function puser_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

function puser_get($key, $default = '')
{
    return isset($_GET[$key]) && !is_array($_GET[$key])
        ? trim((string)$_GET[$key])
        : $default;
}

function puser_label($value)
{
    $value = trim((string)$value);

    return $value === ''
        ? '—'
        : ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                $value
            )
        );
}

function puser_date($value, $withTime = false)
{
    if (!$value) {
        return '—';
    }

    $time = strtotime((string)$value);

    if ($time === false) {
        return '—';
    }

    return date(
        $withTime
            ? 'd M Y, h:i A'
            : 'd M Y',
        $time
    );
}

function puser_initials($firstName, $lastName)
{
    $initials = '';

    if ($firstName !== '') {
        $initials .= strtoupper(
            substr($firstName, 0, 1)
        );
    }

    if ($lastName !== '') {
        $initials .= strtoupper(
            substr($lastName, 0, 1)
        );
    }

    return $initials !== ''
        ? $initials
        : 'PU';
}

function puser_url(array $changes)
{
    $query = $_GET;

    foreach ($changes as $key => $value) {
        if (
            $value === '' ||
            $value === null
        ) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return 'platform-users.php' .
        (
            empty($query)
                ? ''
                : '?' . http_build_query($query)
        );
}

/*
|--------------------------------------------------------------------------
| Current Platform User
|--------------------------------------------------------------------------
*/
$currentStmt = $pdo->prepare("
    SELECT
        id,
        first_name,
        last_name,
        username,
        email,
        phone,
        avatar_path,
        job_title,
        role_code,
        status,
        deleted_at
    FROM platform_users
    WHERE id = :id
    LIMIT 1
");

$currentStmt->execute(array(
    ':id' => (int)$_SESSION['platform_user_id']
));

$currentPlatformUser = $currentStmt->fetch();

if (
    !$currentPlatformUser ||
    !empty($currentPlatformUser['deleted_at']) ||
    (string)$currentPlatformUser['status'] !== 'active'
) {
    $_SESSION = array();

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    header('Location: login.php');
    exit;
}

$_SESSION['platform_authenticated'] = true;
$_SESSION['platform_logged_in'] = true;
$_SESSION['platform_role_code'] =
    (string)$currentPlatformUser['role_code'];

$currentRole =
    (string)$currentPlatformUser['role_code'];

$canManage = in_array(
    $currentRole,
    array(
        'super_admin',
        'platform_admin'
    ),
    true
);

if (!$canManage) {
    $_SESSION['platform_toast'] = array(
        'type' => 'warning',
        'message' =>
            'You do not have permission to manage Platform Users.'
    );

    header('Location: index.php');
    exit;
}

if (
    empty($_SESSION['platform_users_csrf']) ||
    !is_string($_SESSION['platform_users_csrf'])
) {
    $_SESSION['platform_users_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['platform_users_csrf'];

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$roles = array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
);

$statuses = array(
    'active',
    'inactive',
    'suspended'
);

$allowedPerPage = array(
    10,
    15,
    25,
    50,
    100
);

$search = puser_get('search');
$role = strtolower(
    puser_get('role')
);
$status = strtolower(
    puser_get('status')
);

$page = max(
    1,
    (int)puser_get('page', '1')
);

$perPage =
    (int)puser_get(
        'per_page',
        '10'
    );

if (
    !in_array(
        $perPage,
        $allowedPerPage,
        true
    )
) {
    $perPage = 10;
}

if (
    $role !== '' &&
    !in_array(
        $role,
        $roles,
        true
    )
) {
    $role = '';
}

if (
    $status !== '' &&
    !in_array(
        $status,
        $statuses,
        true
    )
) {
    $status = '';
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/
$summary = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(
            CASE
                WHEN status = 'active'
                THEN 1
                ELSE 0
            END
        ) AS active_count,
        SUM(
            CASE
                WHEN
                    role_code IN (
                        'super_admin',
                        'platform_admin'
                    )
                    AND status = 'active'
                THEN 1
                ELSE 0
            END
        ) AS admin_count,
        SUM(
            CASE
                WHEN status = 'suspended'
                THEN 1
                ELSE 0
            END
        ) AS suspended_count
    FROM platform_users
    WHERE deleted_at IS NULL
")->fetch();

if (!$summary) {
    $summary = array(
        'total' => 0,
        'active_count' => 0,
        'admin_count' => 0,
        'suspended_count' => 0
    );
}

/*
|--------------------------------------------------------------------------
| Default Platform SMTP status
|--------------------------------------------------------------------------
*/
$smtpStmt = $pdo->query("
    SELECT
        id,
        config_name,
        from_email,
        is_default,
        is_active,
        last_test_status
    FROM smtp_configurations
    WHERE scope_type = 'platform'
      AND is_active = 1
    ORDER BY
        is_default DESC,
        id DESC
    LIMIT 1
");

$platformSmtp = $smtpStmt->fetch();

/*
|--------------------------------------------------------------------------
| User list
|--------------------------------------------------------------------------
*/
$where = array(
    'deleted_at IS NULL'
);

$params = array();

if ($search !== '') {
    $where[] = "(
        first_name LIKE :search
        OR last_name LIKE :search
        OR username LIKE :search
        OR email LIKE :search
        OR phone LIKE :search
        OR job_title LIKE :search
        OR role_code LIKE :search
    )";

    $params[':search'] =
        '%' . $search . '%';
}

if ($role !== '') {
    $where[] =
        'role_code = :role';

    $params[':role'] =
        $role;
}

if ($status !== '') {
    $where[] =
        'status = :status';

    $params[':status'] =
        $status;
}

$whereSql =
    implode(
        ' AND ',
        $where
    );

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM platform_users
    WHERE {$whereSql}
");

$countStmt->execute($params);

$totalRecords =
    (int)$countStmt->fetchColumn();

$totalPages = max(
    1,
    (int)ceil(
        $totalRecords /
        $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset =
    ($page - 1) *
    $perPage;

$listStmt = $pdo->prepare("
    SELECT
        id,
        first_name,
        last_name,
        username,
        email,
        phone,
        avatar_path,
        job_title,
        role_code,
        status,
        last_login_at,
        created_at
    FROM platform_users
    WHERE {$whereSql}
    ORDER BY
        created_at DESC,
        id DESC
    LIMIT :limit
    OFFSET :offset
");

foreach ($params as $key => $value) {
    $listStmt->bindValue(
        $key,
        $value,
        PDO::PARAM_STR
    );
}

$listStmt->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);

$listStmt->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);

$listStmt->execute();

$users =
    $listStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Sample record preview
|--------------------------------------------------------------------------
| This is deliberately NOT inserted into the database.
| If this email is later created for real, the preview disappears.
*/
$sampleEmail =
    'rubiksakthi0907@gmail.com';

$sampleExistsStmt = $pdo->prepare("
    SELECT id
    FROM platform_users
    WHERE email = :email
      AND deleted_at IS NULL
    LIMIT 1
");

$sampleExistsStmt->execute(array(
    ':email' => $sampleEmail
));

$sampleExists =
    (bool)$sampleExistsStmt->fetchColumn();

$showSamplePreview =
    !$sampleExists &&
    $page === 1 &&
    $search === '' &&
    $role === '' &&
    $status === '';

$sampleUser = array(
    'id' => 0,
    'first_name' => 'Rubika',
    'last_name' => 'Sakthi',
    'username' => 'rubika.platform',
    'email' => $sampleEmail,
    'phone' => null,
    'avatar_path' => null,
    'job_title' => 'Platform Administrator',
    'role_code' => 'platform_admin',
    'status' => 'active',
    'last_login_at' => null,
    'created_at' => date('Y-m-d H:i:s'),
    'is_sample_preview' => 1
);

$displayUsers =
    $users;

if ($showSamplePreview) {
    array_unshift(
        $displayUsers,
        $sampleUser
    );
}

$startRecord =
    $totalRecords > 0
        ? $offset + 1
        : 0;

$endRecord = min(
    $offset + $perPage,
    $totalRecords
);

$paginationStart =
    max(
        1,
        $page - 2
    );

$paginationEnd =
    min(
        $totalPages,
        $page + 2
    );

/*
|--------------------------------------------------------------------------
| Flash toast
|--------------------------------------------------------------------------
*/
$platformToast = null;

if (
    isset($_SESSION['platform_toast']) &&
    is_array($_SESSION['platform_toast'])
) {
    $platformToast =
        $_SESSION['platform_toast'];

    unset(
        $_SESSION['platform_toast']
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
    <?= puser_h($pageTitle); ?> - FieldPlx
</title>

<?php
require_once __DIR__ . '/includes/links.php';
?>

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
    --fp-success:#059669;
    --fp-warning:#d97706;
    --fp-danger:#dc2626;
    --fp-info:#6366f1;
    --fp-sidebar-width:260px;
    --fp-sidebar-collapsed-width:76px;
    --fp-topbar-height:66px;
}

*{
    box-sizing:border-box;
}

html,
body{
    min-height:100%;
}

body{
    margin:0;
    min-height:100vh;
    overflow-x:hidden;
    background:#fff;
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
    -webkit-backdrop-filter:blur(14px);
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
    background:#fff;
    color:#39345f;
    font-size:18px;
    transition:.16s ease;
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
    overflow:hidden;
    color:#17172e;
    font-size:15px;
    font-weight:700;
    line-height:1.25;
    white-space:nowrap;
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
    top:50%;
    left:12px;
    z-index:2;
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
    background:#fff;
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
    border:2px solid #fff;
    border-radius:999px;
    background:var(--fp-danger);
    color:#fff;
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
    background:#fff;
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
    font-weight:700;
}

.fp-profile-text{
    max-width:145px;
    min-width:0;
}

.fp-profile-name,
.fp-profile-role{
    overflow:hidden;
    display:block;
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
    background:#fff;
}

/* =========================================================
   PLATFORM USERS
========================================================= */

.puser-page{
    display:grid;
    gap:16px;
}

.puser-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px;
}

.puser-title{
    margin:0;
    color:#111827;
    font-size:20px;
    font-weight:800;
}

.puser-description{
    margin-top:4px;
    max-width:760px;
    color:#77718e;
    font-size:10px;
    line-height:1.55;
}

.puser-header-actions{
    display:flex;
    align-items:center;
    gap:8px;
}

.puser-primary,
.puser-secondary{
    min-height:38px;
    padding:8px 13px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border-radius:9px;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}

.puser-primary{
    border:0;
    background:linear-gradient(
        135deg,
        #7c3aed,
        #6d28d9
    );
    color:#fff;
    box-shadow:0 8px 20px rgba(109,40,217,.18);
}

.puser-secondary{
    border:1px solid #dcd5ef;
    background:#fff;
    color:#5f5870;
}

.puser-primary:disabled,
.puser-secondary:disabled{
    opacity:.62;
    cursor:not-allowed;
}

.puser-stats{
    display:grid;
    grid-template-columns:
        repeat(4,minmax(0,1fr));
    gap:12px;
}

.puser-stat{
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
}

.puser-stat:hover{
    border-color:#cfc3ef;
    background:linear-gradient(
        180deg,
        #fff 0%,
        #f8f4ff 100%
    );
}

.puser-stat-icon{
    width:38px;
    height:38px;
    flex:0 0 38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    background:#eee8ff;
    color:#7c3aed;
    font-size:16px;
}

.puser-stat-content{
    min-width:0;
    display:block;
}

.puser-stat-label{
    display:block;
    color:#9a94ae;
    font-size:8px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.04em;
    line-height:1.3;
}

.puser-stat-value{
    margin-top:2px;
    display:block;
    color:#111827;
    font-size:20px;
    font-weight:800;
    line-height:1.2;
}

.puser-stat-note{
    margin-top:2px;
    display:block;
    color:#9d96ac;
    font-size:7.5px;
    line-height:1.35;
}

.puser-layout{
    display:grid;
    grid-template-columns:
        minmax(0,1fr)
        300px;
    gap:16px;
    align-items:start;
}

.puser-column{
    display:grid;
    gap:16px;
}

.puser-card{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:14px;
    background:#fff;
    box-shadow:0 8px 24px rgba(37,29,80,.05);
}

.puser-card-header{
    min-height:54px;
    padding:12px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff;
}

.puser-card-title-wrap{
    display:flex;
    align-items:center;
    gap:10px;
}

.puser-card-icon{
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

.puser-card-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800;
}

.puser-card-subtitle{
    margin-top:2px;
    display:block;
    color:#9a94aa;
    font-size:8px;
}

.puser-card-body{
    padding:15px;
}

.puser-toolbar{
    padding:12px 15px;
    display:grid;
    grid-template-columns:
        minmax(240px,1fr)
        155px
        145px
        105px;
    gap:9px;
    border-bottom:1px solid #ece7f7;
    background:#fff;
}

.puser-search{
    position:relative;
}

.puser-search > i{
    position:absolute;
    top:50%;
    left:11px;
    transform:translateY(-50%);
    color:#9b93aa;
    font-size:12px;
    pointer-events:none;
}

.puser-search-countdown{
    position:absolute;
    top:50%;
    right:10px;
    max-width:105px;
    overflow:hidden;
    transform:translateY(-50%);
    color:#8c849f;
    font-size:7px;
    white-space:nowrap;
    text-overflow:ellipsis;
}

.puser-input,
.puser-select{
    width:100%;
    height:38px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    outline:0;
    background:#fff;
    color:#312b47;
    font-size:9px;
}

.puser-search .puser-input{
    padding:8px 108px 8px 32px;
}

.puser-select{
    padding:8px 9px;
}

.puser-input:focus,
.puser-select:focus{
    border-color:#a78bfa;
    box-shadow:
        0 0 0 3px
        rgba(139,92,246,.10);
}

.puser-table-wrap{
    overflow:auto;
}

.puser-table{
    width:100%;
    min-width:1120px;
    border-collapse:collapse;
}

.puser-table th{
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

.puser-table td{
    padding:10px 12px;
    border-bottom:1px solid #f0ecf7;
    color:#433d54;
    font-size:9px;
    vertical-align:middle;
}

.puser-table tbody tr:hover{
    background:#fcfbff;
}

.puser-main{
    display:flex;
    align-items:center;
    gap:9px;
    min-width:210px;
}

.puser-avatar{
    width:34px;
    height:34px;
    flex:0 0 34px;
    overflow:hidden;
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
    font-size:9px;
    font-weight:800;
}

.puser-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.puser-name{
    display:block;
    color:#2f2940;
    font-size:10px;
    font-weight:800;
}

.puser-job{
    margin-top:2px;
    display:block;
    color:#958da4;
    font-size:8px;
}

.puser-username{
    display:inline-flex;
    align-items:center;
    padding:5px 7px;
    border-radius:7px;
    background:#f8f5ff;
    color:#655d78;
    font-size:8px;
    font-weight:700;
}

.puser-role,
.puser-status,
.puser-sample{
    display:inline-flex;
    align-items:center;
    padding:4px 7px;
    border-radius:999px;
    font-size:8px;
    font-weight:700;
    white-space:nowrap;
}

.puser-role{
    background:#f1ecff;
    color:#6d28d9;
}

.puser-status.active{
    background:#ecfdf5;
    color:#047857;
}

.puser-status.inactive{
    background:#f3f4f6;
    color:#6b7280;
}

.puser-status.suspended{
    background:#fef2f2;
    color:#b91c1c;
}

.puser-sample{
    margin-left:5px;
    background:#fff7ed;
    color:#c2410c;
}

.puser-contact strong{
    display:block;
    color:#3b344b;
    font-size:9px;
    font-weight:700;
}

.puser-contact span{
    margin-top:2px;
    display:block;
    color:#928a9f;
    font-size:8px;
}

.puser-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:5px;
}

.puser-icon-btn{
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
    cursor:pointer;
}

.puser-icon-btn:hover{
    border-color:#bda9ff;
    background:#f7f3ff;
    color:#6d28d9;
}

.puser-icon-btn.danger:hover{
    border-color:#fecaca;
    background:#fef2f2;
    color:#dc2626;
}

.puser-icon-btn.success:hover{
    border-color:#a7f3d0;
    background:#ecfdf5;
    color:#047857;
}

.puser-icon-btn:disabled{
    opacity:.38;
    cursor:not-allowed;
}

.puser-empty{
    padding:42px 15px;
    text-align:center;
    color:#928aa5;
}

.puser-empty i{
    display:block;
    margin-bottom:8px;
    font-size:25px;
    color:#c2b9d2;
}

.puser-empty h3{
    margin:0;
    color:#51495f;
    font-size:11px;
    font-weight:800;
}

.puser-empty p{
    margin:5px 0 0;
    font-size:8px;
}

.puser-pagination-bar{
    padding:11px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border-top:1px solid #ece7f7;
    background:#fbf9ff;
}

.puser-pagination-info{
    color:#8a8298;
    font-size:8px;
}

.puser-pagination{
    margin:0;
    padding:0;
    display:flex;
    align-items:center;
    gap:4px;
    list-style:none;
}

.puser-pagination a,
.puser-pagination span{
    min-width:28px;
    height:28px;
    padding:0 7px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid #ded7ef;
    border-radius:7px;
    background:#fff;
    color:#625a75;
    font-size:8px;
    font-weight:700;
}

.puser-pagination .active{
    border-color:#7c3aed;
    background:#7c3aed;
    color:#fff;
}

.puser-pagination .disabled{
    opacity:.45;
}

.puser-info-box{
    padding:13px;
    border:1px solid #e3daf8;
    border-radius:11px;
    background:#f8f5ff;
}

.puser-info-title{
    display:flex;
    align-items:center;
    gap:7px;
    color:#41394f;
    font-size:9px;
    font-weight:800;
}

.puser-info-title i{
    color:#7c3aed;
}

.puser-info-text{
    margin-top:7px;
    color:#777087;
    font-size:8px;
    line-height:1.65;
}

.puser-info-row{
    padding:8px 0;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px dashed #ddd6ef;
    font-size:8px;
}

.puser-info-row:last-child{
    padding-bottom:0;
    border-bottom:0;
}

.puser-info-row span{
    color:#8d859e;
}

.puser-info-row strong{
    max-width:170px;
    color:#302a40;
    text-align:right;
    overflow-wrap:anywhere;
}

.puser-role-list{
    display:grid;
    gap:8px;
}

.puser-role-item{
    padding:9px 10px;
    display:flex;
    align-items:center;
    gap:9px;
    border:1px solid #e2dcf2;
    border-radius:10px;
    background:#fff;
}

.puser-role-item i{
    width:27px;
    height:27px;
    flex:0 0 27px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:7px;
    background:#f1ecff;
    color:#7c3aed;
    font-size:11px;
}

.puser-role-item strong{
    display:block;
    color:#3d364d;
    font-size:8.5px;
}

.puser-role-item span{
    margin-top:2px;
    display:block;
    color:#928a9f;
    font-size:7px;
    line-height:1.35;
}

.puser-sample-note{
    margin:0 15px 12px;
    padding:10px 11px;
    display:flex;
    align-items:flex-start;
    gap:8px;
    border:1px solid #fed7aa;
    border-radius:9px;
    background:#fffaf2;
    color:#9a5a14;
    font-size:8px;
    line-height:1.55;
}

.puser-sample-note i{
    margin-top:1px;
}

/* Modal */
.puser-modal .modal-content{
    overflow:hidden;
    border:1px solid #ded7ef;
    border-radius:15px;
    box-shadow:0 24px 60px rgba(28,20,70,.22);
}

.puser-modal .modal-header{
    padding:13px 15px;
    border-bottom:1px solid #ece7f7;
    background:#fbf9ff;
}

.puser-modal-title-wrap{
    display:flex;
    align-items:center;
    gap:10px;
}

.puser-modal-icon{
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

.puser-modal-title{
    margin:0;
    color:#111827;
    font-size:12px;
    font-weight:800;
}

.puser-modal-subtitle{
    margin-top:2px;
    color:#9a94aa;
    font-size:8px;
}

.puser-modal .modal-body{
    padding:15px;
}

.puser-form-grid{
    display:grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap:13px;
}

.puser-field.full{
    grid-column:1/-1;
}

.puser-field label{
    margin-bottom:6px;
    display:block;
    color:#4c465f;
    font-size:9px;
    font-weight:700;
}

.puser-required{
    color:#dc2626;
}

.puser-form-input,
.puser-form-select{
    width:100%;
    height:39px;
    padding:8px 11px;
    border:1px solid #dcd5ef;
    border-radius:9px;
    outline:0;
    background:#fff;
    color:#312b47;
    font-size:10px;
}

.puser-form-input:focus,
.puser-form-select:focus{
    border-color:#a78bfa;
    box-shadow:
        0 0 0 3px
        rgba(139,92,246,.10);
}

.puser-form-input[readonly]{
    background:#f8f6ff;
    color:#8a8298;
}

.puser-field-note{
    margin-top:5px;
    color:#9a94aa;
    font-size:8px;
    line-height:1.45;
}

.puser-credential-box{
    margin-top:14px;
    padding:11px;
    border:1px solid #e3daf8;
    border-radius:10px;
    background:#f8f5ff;
    color:#655d78;
    font-size:8px;
    line-height:1.55;
}

.puser-credential-box i{
    margin-right:5px;
    color:#7c3aed;
}

.puser-modal .modal-footer{
    padding:12px 15px;
    border-top:1px solid #ece7f7;
    background:#fbf9ff;
}

.puser-loader{
    width:14px;
    height:14px;
    display:none;
    border:2px dotted rgba(255,255,255,.95);
    border-radius:50%;
    animation:puserSpin .75s linear infinite;
}

.puser-primary.loading .puser-loader{
    display:inline-block;
}

@keyframes puserSpin{
    to{
        transform:rotate(360deg);
    }
}

/* Toast */
.puser-toast{
    position:fixed;
    top:82px;
    right:20px;
    z-index:20000;
    width:min(
        390px,
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
    line-height:1.45;
}

.puser-toast.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0);
}

.puser-toast.success{
    background:#059669;
}

.puser-toast.error{
    background:#dc2626;
}

.puser-toast.warning{
    background:#d97706;
}

.puser-toast.info{
    background:#4f46e5;
}

.puser-toast-icon{
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

.puser-toast-message{
    flex:1;
    min-width:0;
    font-weight:600;
}

.puser-toast-close{
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
    opacity:.82;
}

.puser-toast-close:hover{
    background:rgba(255,255,255,.12);
    opacity:1;
}

@media(max-width:1180px){
    .puser-layout{
        grid-template-columns:1fr;
    }

    .puser-side{
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }
}

@media(max-width:1100px){
    .puser-stats{
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }

    .puser-toolbar{
        grid-template-columns:
            minmax(220px,1fr)
            145px
            135px;
    }

    .puser-toolbar .per-page{
        grid-column:3;
    }
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

@media(max-width:760px){
    .puser-header{
        flex-direction:column;
    }

    .puser-header-actions{
        width:100%;
    }

    .puser-header-actions .puser-primary,
    .puser-header-actions .puser-secondary{
        flex:1;
    }

    .puser-toolbar{
        grid-template-columns:1fr 1fr;
    }

    .puser-search{
        grid-column:1/-1;
    }

    .puser-side{
        grid-template-columns:1fr;
    }

    .puser-form-grid{
        grid-template-columns:1fr;
    }

    .puser-field.full{
        grid-column:auto;
    }

    .puser-pagination-bar{
        align-items:flex-start;
        flex-direction:column;
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

    .puser-stats{
        grid-template-columns:1fr;
    }

    .puser-stat{
        min-height:82px;
    }

    .puser-toolbar{
        grid-template-columns:1fr;
    }

    .puser-search,
    .puser-toolbar .per-page{
        grid-column:auto;
    }

    .puser-modal .modal-footer{
        flex-direction:column-reverse;
    }

    .puser-modal .modal-footer .puser-primary,
    .puser-modal .modal-footer .puser-secondary{
        width:100%;
    }

    .puser-toast{
        top:74px;
        right:12px;
        left:12px;
        width:auto;
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
<div class="puser-page">

<div class="puser-header">
    <div>
        <h2 class="puser-title">
            Platform Users
        </h2>

        <div class="puser-description">
            Manage users who can access FieldPlx Platform administration.
            New Platform Users receive an automatically generated username and
            temporary password through the configured Platform SMTP account.
        </div>
    </div>

    <div class="puser-header-actions">
        <a
            href="email-smtp.php"
            class="puser-secondary"
        >
            <i class="bi bi-envelope-gear"></i>
            Platform SMTP
        </a>

        <button
            type="button"
            class="puser-primary"
            id="addPlatformUserBtn"
        >
            <i class="bi bi-person-plus"></i>
            Add Platform User
        </button>
    </div>
</div>

<div class="puser-stats">

<div class="puser-stat">
    <span class="puser-stat-icon">
        <i class="bi bi-people"></i>
    </span>

    <span class="puser-stat-content">
        <span class="puser-stat-label">
            Total Users
        </span>

        <span class="puser-stat-value">
            <?= number_format(
                (int)$summary['total']
            ); ?>
        </span>

        <span class="puser-stat-note">
            All non-deleted Platform accounts
        </span>
    </span>
</div>

<div class="puser-stat">
    <span class="puser-stat-icon">
        <i class="bi bi-person-check"></i>
    </span>

    <span class="puser-stat-content">
        <span class="puser-stat-label">
            Active
        </span>

        <span class="puser-stat-value">
            <?= number_format(
                (int)$summary['active_count']
            ); ?>
        </span>

        <span class="puser-stat-note">
            Can currently sign in
        </span>
    </span>
</div>

<div class="puser-stat">
    <span class="puser-stat-icon">
        <i class="bi bi-shield-check"></i>
    </span>

    <span class="puser-stat-content">
        <span class="puser-stat-label">
            Platform Admins
        </span>

        <span class="puser-stat-value">
            <?= number_format(
                (int)$summary['admin_count']
            ); ?>
        </span>

        <span class="puser-stat-note">
            Super Admin + Platform Admin
        </span>
    </span>
</div>

<div class="puser-stat">
    <span class="puser-stat-icon">
        <i class="bi bi-person-x"></i>
    </span>

    <span class="puser-stat-content">
        <span class="puser-stat-label">
            Suspended
        </span>

        <span class="puser-stat-value">
            <?= number_format(
                (int)$summary['suspended_count']
            ); ?>
        </span>

        <span class="puser-stat-note">
            Sign-in access blocked
        </span>
    </span>
</div>

</div>

<div class="puser-layout">

<div class="puser-column">

<section class="puser-card">

<div class="puser-card-header">
    <div class="puser-card-title-wrap">
        <span class="puser-card-icon">
            <i class="bi bi-person-badge"></i>
        </span>

        <span>
            <h3 class="puser-card-title">
                Platform User Directory
            </h3>

            <span class="puser-card-subtitle">
                Search, filter and manage Platform access accounts
            </span>
        </span>
    </div>
</div>

<form
    method="get"
    action="platform-users.php"
    class="puser-toolbar"
    id="platformUserFilterForm"
>
    <div class="puser-search">
        <i class="bi bi-search"></i>

        <input
            type="search"
            name="search"
            id="platformUserSearch"
            class="puser-input"
            value="<?= puser_h(
                $search
            ); ?>"
            placeholder="Search name, username, email, phone or role..."
            autocomplete="off"
        >

        <span
            class="puser-search-countdown"
            id="platformUserSearchCountdown"
        ></span>
    </div>

    <select
        name="role"
        id="platformUserRoleFilter"
        class="puser-select"
    >
        <option value="">
            All Roles
        </option>

        <?php foreach ($roles as $roleOption): ?>
            <option
                value="<?= puser_h(
                    $roleOption
                ); ?>"
                <?= $role === $roleOption
                    ? 'selected'
                    : ''; ?>
            >
                <?= puser_h(
                    puser_label(
                        $roleOption
                    )
                ); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select
        name="status"
        id="platformUserStatusFilter"
        class="puser-select"
    >
        <option value="">
            All Status
        </option>

        <?php foreach ($statuses as $statusOption): ?>
            <option
                value="<?= puser_h(
                    $statusOption
                ); ?>"
                <?= $status === $statusOption
                    ? 'selected'
                    : ''; ?>
            >
                <?= puser_h(
                    puser_label(
                        $statusOption
                    )
                ); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select
        name="per_page"
        id="platformUserPerPage"
        class="puser-select per-page"
    >
        <?php foreach ($allowedPerPage as $size): ?>
            <option
                value="<?= (int)$size; ?>"
                <?= $perPage === $size
                    ? 'selected'
                    : ''; ?>
            >
                <?= (int)$size; ?> / page
            </option>
        <?php endforeach; ?>
    </select>

    <input
        type="hidden"
        name="page"
        id="platformUserPage"
        value="1"
    >
</form>

<?php if ($showSamplePreview): ?>
<div class="puser-sample-note">
    <i class="bi bi-info-circle"></i>

    <span>
        <strong>Sample preview:</strong>
        Rubika Sakthi is shown below only as a UI sample and is not stored in
        the database. Create the user using <strong>Add Platform User</strong>
        to generate real login credentials and send them through Platform SMTP.
    </span>
</div>
<?php endif; ?>

<?php if (empty($displayUsers)): ?>

<div class="puser-empty">
    <i class="bi bi-people"></i>

    <h3>
        No Platform Users found
    </h3>

    <p>
        Change the search or filters and try again.
    </p>
</div>

<?php else: ?>

<div class="puser-table-wrap">

<table class="puser-table">

<thead>
<tr>
    <th style="width:52px;">
        S.No
    </th>
    <th>User</th>
    <th>Username</th>
    <th>Role</th>
    <th>Contact</th>
    <th>Status</th>
    <th>Last Login</th>
    <th>Joined</th>
    <th style="text-align:right;">
        Action
    </th>
</tr>
</thead>

<tbody>

<?php
$realRowNumber = 0;

foreach ($displayUsers as $user):

    $isSample =
        !empty(
            $user[
                'is_sample_preview'
            ]
        );

    if (!$isSample) {
        $realRowNumber++;
    }

    $isSelf =
        !$isSample &&
        (int)$user['id'] ===
        (int)$currentPlatformUser['id'];

    $fullName =
        trim(
            (string)$user['first_name'] .
            ' ' .
            (string)$user['last_name']
        );

    if ($fullName === '') {
        $fullName = 'Platform User';
    }

    $rowData = array(
        'id' => (int)$user['id'],
        'first_name' =>
            (string)$user['first_name'],
        'last_name' =>
            (string)$user['last_name'],
        'username' =>
            (string)$user['username'],
        'email' =>
            (string)$user['email'],
        'phone' =>
            (string)$user['phone'],
        'job_title' =>
            (string)$user['job_title'],
        'role_code' =>
            (string)$user['role_code'],
        'status' =>
            (string)$user['status']
    );
?>

<tr>

<td>
    <?= $isSample
        ? '—'
        : (int)(
            $offset +
            $realRowNumber
        ); ?>
</td>

<td>
    <div class="puser-main">
        <span class="puser-avatar">

            <?php
            if (
                !$isSample &&
                !empty(
                    $user['avatar_path']
                )
            ):
            ?>
                <img
                    src="<?= puser_h(
                        $user['avatar_path']
                    ); ?>"
                    alt=""
                >
            <?php else: ?>
                <?= puser_h(
                    puser_initials(
                        (string)$user[
                            'first_name'
                        ],
                        (string)$user[
                            'last_name'
                        ]
                    )
                ); ?>
            <?php endif; ?>

        </span>

        <span>
            <span class="puser-name">
                <?= puser_h(
                    $fullName
                ); ?>

                <?php if ($isSelf): ?>
                    <span
                        style="
                            color:#7c3aed;
                            font-size:7px;
                        "
                    >
                        (You)
                    </span>
                <?php endif; ?>

                <?php if ($isSample): ?>
                    <span class="puser-sample">
                        Sample
                    </span>
                <?php endif; ?>
            </span>

            <span class="puser-job">
                <?= puser_h(
                    !empty(
                        $user['job_title']
                    )
                        ? $user['job_title']
                        : 'Platform User'
                ); ?>
            </span>
        </span>
    </div>
</td>

<td>
    <span class="puser-username">
        <?= puser_h(
            !empty(
                $user['username']
            )
                ? $user['username']
                : '—'
        ); ?>
    </span>
</td>

<td>
    <span class="puser-role">
        <?= puser_h(
            puser_label(
                $user['role_code']
            )
        ); ?>
    </span>
</td>

<td>
    <div class="puser-contact">
        <strong>
            <?= puser_h(
                $user['email']
            ); ?>
        </strong>

        <span>
            <?= puser_h(
                !empty(
                    $user['phone']
                )
                    ? $user['phone']
                    : '—'
            ); ?>
        </span>
    </div>
</td>

<td>
    <span
        class="puser-status <?= puser_h(
            $user['status']
        ); ?>"
    >
        <?= puser_h(
            puser_label(
                $user['status']
            )
        ); ?>
    </span>
</td>

<td>
    <?= $isSample
        ? 'Not signed in'
        : puser_h(
            puser_date(
                $user['last_login_at'],
                true
            )
        ); ?>
</td>

<td>
    <?= $isSample
        ? 'Sample'
        : puser_h(
            puser_date(
                $user['created_at']
            )
        ); ?>
</td>

<td>
    <div class="puser-actions">

        <?php if ($isSample): ?>

            <button
                type="button"
                class="puser-icon-btn"
                title="Sample preview only"
                disabled
            >
                <i class="bi bi-pencil"></i>
            </button>

            <button
                type="button"
                class="puser-icon-btn"
                title="Sample preview only"
                disabled
            >
                <i class="bi bi-key"></i>
            </button>

            <button
                type="button"
                class="puser-icon-btn"
                title="Sample preview only"
                disabled
            >
                <i class="bi bi-person-slash"></i>
            </button>

        <?php else: ?>

            <button
                type="button"
                class="puser-icon-btn edit-platform-user"
                title="Edit Platform User"
                data-user="<?= puser_h(
                    json_encode(
                        $rowData,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    )
                ); ?>"
            >
                <i class="bi bi-pencil"></i>
            </button>

            <button
                type="button"
                class="puser-icon-btn reset-platform-user"
                title="Generate and email new credentials"
                data-id="<?= (int)$user['id']; ?>"
                data-name="<?= puser_h(
                    $fullName
                ); ?>"
                <?= $isSelf
                    ? 'disabled'
                    : ''; ?>
            >
                <i class="bi bi-key"></i>
            </button>

            <?php
            $nextStatus =
                $user['status'] === 'active'
                    ? 'suspended'
                    : 'active';
            ?>

            <button
                type="button"
                class="puser-icon-btn <?= $nextStatus === 'active'
                    ? 'success'
                    : 'danger'; ?> status-platform-user"
                title="<?= $nextStatus === 'active'
                    ? 'Activate Platform User'
                    : 'Suspend Platform User'; ?>"
                data-id="<?= (int)$user['id']; ?>"
                data-status="<?= puser_h(
                    $nextStatus
                ); ?>"
                data-name="<?= puser_h(
                    $fullName
                ); ?>"
                <?= $isSelf
                    ? 'disabled'
                    : ''; ?>
            >
                <i
                    class="bi <?= $nextStatus === 'active'
                        ? 'bi-person-check'
                        : 'bi-person-slash'; ?>"
                ></i>
            </button>

        <?php endif; ?>

    </div>
</td>

</tr>

<?php endforeach; ?>

</tbody>
</table>

</div>

<?php endif; ?>

<div class="puser-pagination-bar">

<div class="puser-pagination-info">
    Showing
    <?= number_format(
        $startRecord
    ); ?>
    to
    <?= number_format(
        $endRecord
    ); ?>
    of
    <?= number_format(
        $totalRecords
    ); ?>
    Platform Users

    <?php if ($showSamplePreview): ?>
        + 1 sample preview
    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>

<ul class="puser-pagination">

<li>
    <?php if ($page > 1): ?>
        <a
            href="<?= puser_h(
                puser_url(
                    array(
                        'page' => 1
                    )
                )
            ); ?>"
            title="First Page"
        >
            <i class="bi bi-chevron-double-left"></i>
        </a>
    <?php else: ?>
        <span class="disabled">
            <i class="bi bi-chevron-double-left"></i>
        </span>
    <?php endif; ?>
</li>

<li>
    <?php if ($page > 1): ?>
        <a
            href="<?= puser_h(
                puser_url(
                    array(
                        'page' =>
                            $page - 1
                    )
                )
            ); ?>"
            title="Previous Page"
        >
            <i class="bi bi-chevron-left"></i>
        </a>
    <?php else: ?>
        <span class="disabled">
            <i class="bi bi-chevron-left"></i>
        </span>
    <?php endif; ?>
</li>

<?php
if ($paginationStart > 1):
?>
<li>
    <a
        href="<?= puser_h(
            puser_url(
                array(
                    'page' => 1
                )
            )
        ); ?>"
    >
        1
    </a>
</li>

<?php if ($paginationStart > 2): ?>
<li>
    <span>…</span>
</li>
<?php endif; ?>

<?php endif; ?>

<?php
for (
    $pageNumber =
        $paginationStart;
    $pageNumber <=
        $paginationEnd;
    $pageNumber++
):
?>
<li>
    <?php if ($pageNumber === $page): ?>
        <span class="active">
            <?= (int)$pageNumber; ?>
        </span>
    <?php else: ?>
        <a
            href="<?= puser_h(
                puser_url(
                    array(
                        'page' =>
                            $pageNumber
                    )
                )
            ); ?>"
        >
            <?= (int)$pageNumber; ?>
        </a>
    <?php endif; ?>
</li>
<?php endfor; ?>

<?php
if ($paginationEnd < $totalPages):
?>

<?php
if (
    $paginationEnd <
    $totalPages - 1
):
?>
<li>
    <span>…</span>
</li>
<?php endif; ?>

<li>
    <a
        href="<?= puser_h(
            puser_url(
                array(
                    'page' =>
                        $totalPages
                )
            )
        ); ?>"
    >
        <?= (int)$totalPages; ?>
    </a>
</li>

<?php endif; ?>

<li>
    <?php if ($page < $totalPages): ?>
        <a
            href="<?= puser_h(
                puser_url(
                    array(
                        'page' =>
                            $page + 1
                    )
                )
            ); ?>"
            title="Next Page"
        >
            <i class="bi bi-chevron-right"></i>
        </a>
    <?php else: ?>
        <span class="disabled">
            <i class="bi bi-chevron-right"></i>
        </span>
    <?php endif; ?>
</li>

<li>
    <?php if ($page < $totalPages): ?>
        <a
            href="<?= puser_h(
                puser_url(
                    array(
                        'page' =>
                            $totalPages
                    )
                )
            ); ?>"
            title="Last Page"
        >
            <i class="bi bi-chevron-double-right"></i>
        </a>
    <?php else: ?>
        <span class="disabled">
            <i class="bi bi-chevron-double-right"></i>
        </span>
    <?php endif; ?>
</li>

</ul>

<?php endif; ?>

</div>

</section>

</div>

<div class="puser-column puser-side">

<section class="puser-card">

<div class="puser-card-header">
    <div class="puser-card-title-wrap">
        <span class="puser-card-icon">
            <i class="bi bi-envelope-check"></i>
        </span>

        <span>
            <h3 class="puser-card-title">
                Credential Delivery
            </h3>

            <span class="puser-card-subtitle">
                Platform SMTP used for account emails
            </span>
        </span>
    </div>
</div>

<div class="puser-card-body">

<?php if ($platformSmtp): ?>

<div class="puser-info-box">

<div class="puser-info-title">
    <i class="bi bi-check-circle"></i>
    Platform SMTP Available
</div>

<div class="puser-info-text">
    New user credentials and reset passwords can be emailed using this
    active Platform SMTP configuration.
</div>

<div style="margin-top:9px;">

<div class="puser-info-row">
    <span>Configuration</span>
    <strong>
        <?= puser_h(
            $platformSmtp[
                'config_name'
            ]
        ); ?>
    </strong>
</div>

<div class="puser-info-row">
    <span>From Email</span>
    <strong>
        <?= puser_h(
            $platformSmtp[
                'from_email'
            ]
        ); ?>
    </strong>
</div>

<div class="puser-info-row">
    <span>Last Test</span>
    <strong>
        <?= puser_h(
            puser_label(
                $platformSmtp[
                    'last_test_status'
                ]
            )
        ); ?>
    </strong>
</div>

</div>

</div>

<?php else: ?>

<div class="puser-info-box">

<div class="puser-info-title">
    <i class="bi bi-exclamation-triangle"></i>
    No Active Platform SMTP
</div>

<div class="puser-info-text">
    User creation can still be processed by the API, but login credentials
    cannot be delivered by email until an active Platform SMTP configuration
    is available.
</div>

</div>

<?php endif; ?>

</div>

</section>

<section class="puser-card">

<div class="puser-card-header">
    <div class="puser-card-title-wrap">
        <span class="puser-card-icon">
            <i class="bi bi-shield-lock"></i>
        </span>

        <span>
            <h3 class="puser-card-title">
                Platform Roles
            </h3>

            <span class="puser-card-subtitle">
                Existing FieldPlx Platform access levels
            </span>
        </span>
    </div>
</div>

<div class="puser-card-body">
<div class="puser-role-list">

<div class="puser-role-item">
    <i class="bi bi-shield-fill-check"></i>
    <span>
        <strong>Super Admin</strong>
        <span>Highest Platform administration access.</span>
    </span>
</div>

<div class="puser-role-item">
    <i class="bi bi-person-gear"></i>
    <span>
        <strong>Platform Admin</strong>
        <span>Manages tenants and Platform operations.</span>
    </span>
</div>

<div class="puser-role-item">
    <i class="bi bi-headset"></i>
    <span>
        <strong>Support Admin</strong>
        <span>Support and operational assistance access.</span>
    </span>
</div>

<div class="puser-role-item">
    <i class="bi bi-receipt"></i>
    <span>
        <strong>Billing Admin</strong>
        <span>Billing-focused Platform access.</span>
    </span>
</div>

<div class="puser-role-item">
    <i class="bi bi-eye"></i>
    <span>
        <strong>Platform Read Only</strong>
        <span>View-only Platform access.</span>
    </span>
</div>

</div>
</div>

</section>

</div>

</div>

</div>
</div>

</main>
</div>

<!-- Add/Edit Platform User Modal -->
<div
    class="modal fade puser-modal"
    id="platformUserModal"
    tabindex="-1"
    aria-hidden="true"
>
<div
    class="modal-dialog modal-dialog-centered modal-lg"
>
<div class="modal-content">

<form id="platformUserForm">

<div class="modal-header">
    <div class="puser-modal-title-wrap">
        <span class="puser-modal-icon">
            <i class="bi bi-person-plus"></i>
        </span>

        <span>
            <h3
                class="puser-modal-title"
                id="platformUserModalTitle"
            >
                Add Platform User
            </h3>

            <span class="puser-modal-subtitle">
                Account login credentials are generated automatically for new users
            </span>
        </span>
    </div>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="modal"
        aria-label="Close"
    ></button>
</div>

<div class="modal-body">

<input
    type="hidden"
    name="csrf_token"
    value="<?= puser_h(
        $csrfToken
    ); ?>"
>

<input
    type="hidden"
    name="action"
    value="save_user"
>

<input
    type="hidden"
    name="id"
    id="platformUserId"
    value="0"
>

<div class="puser-form-grid">

<div class="puser-field">
    <label for="platformFirstName">
        First Name
        <span class="puser-required">*</span>
    </label>

    <input
        type="text"
        name="first_name"
        id="platformFirstName"
        class="puser-form-input"
        maxlength="120"
        required
        placeholder="Enter first name"
    >
</div>

<div class="puser-field">
    <label for="platformLastName">
        Last Name
    </label>

    <input
        type="text"
        name="last_name"
        id="platformLastName"
        class="puser-form-input"
        maxlength="120"
        placeholder="Enter last name"
    >
</div>

<div class="puser-field">
    <label for="platformEmail">
        Email Address
        <span class="puser-required">*</span>
    </label>

    <input
        type="email"
        name="email"
        id="platformEmail"
        class="puser-form-input"
        maxlength="190"
        required
        placeholder="user@example.com"
    >

    <div class="puser-field-note">
        Generated login credentials are sent to this email address.
    </div>
</div>

<div class="puser-field">
    <label for="platformPhone">
        Phone
    </label>

    <input
        type="text"
        name="phone"
        id="platformPhone"
        class="puser-form-input"
        maxlength="50"
        placeholder="Enter phone number"
    >
</div>

<div class="puser-field">
    <label for="platformJobTitle">
        Job Title
    </label>

    <input
        type="text"
        name="job_title"
        id="platformJobTitle"
        class="puser-form-input"
        maxlength="120"
        placeholder="Example: Platform Administrator"
    >
</div>

<div class="puser-field">
    <label for="platformUsername">
        Username
    </label>

    <input
        type="text"
        id="platformUsername"
        class="puser-form-input"
        value="Auto-generated after creation"
        readonly
    >

    <div class="puser-field-note">
        Existing users keep their current username when edited.
    </div>
</div>

<div class="puser-field">
    <label for="platformRoleCode">
        Platform Role
        <span class="puser-required">*</span>
    </label>

    <select
        name="role_code"
        id="platformRoleCode"
        class="puser-form-select"
        required
    >
        <?php
        foreach ($roles as $roleOption):

            if (
                $roleOption ===
                    'super_admin' &&
                $currentRole !==
                    'super_admin'
            ) {
                continue;
            }
        ?>
            <option
                value="<?= puser_h(
                    $roleOption
                ); ?>"
            >
                <?= puser_h(
                    puser_label(
                        $roleOption
                    )
                ); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="puser-field">
    <label for="platformStatus">
        Status
        <span class="puser-required">*</span>
    </label>

    <select
        name="status"
        id="platformStatus"
        class="puser-form-select"
        required
    >
        <option value="active">
            Active
        </option>

        <option value="inactive">
            Inactive
        </option>

        <option value="suspended">
            Suspended
        </option>
    </select>
</div>

</div>

<div class="puser-credential-box">
    <i class="bi bi-envelope-lock"></i>
    <strong>New user flow:</strong>
    FieldPlx generates a unique username and secure temporary password,
    stores only the password hash, and sends the login credentials through
    the active Platform SMTP configuration.
</div>

</div>

<div class="modal-footer">

<button
    type="button"
    class="puser-secondary"
    data-bs-dismiss="modal"
>
    Cancel
</button>

<button
    type="submit"
    class="puser-primary"
    id="savePlatformUserBtn"
>
    <span class="puser-loader"></span>

    <i class="bi bi-check-lg"></i>

    <span id="savePlatformUserText">
        Create User & Send Email
    </span>
</button>

</div>

</form>

</div>
</div>
</div>

<!-- Toast -->
<div
    class="puser-toast"
    id="platformUserToast"
    role="status"
    aria-live="polite"
    aria-atomic="true"
>
    <span class="puser-toast-icon">
        <i
            id="platformUserToastIcon"
            class="bi bi-info-lg"
        ></i>
    </span>

    <span
        class="puser-toast-message"
        id="platformUserToastMessage"
    >
        Notification
    </span>

    <button
        type="button"
        class="puser-toast-close"
        id="platformUserToastClose"
        aria-label="Close"
    >
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>
(function () {
    'use strict';

    var apiUrl =
        'api/platform-users.php';

    var csrfToken =
        <?= json_encode(
            $csrfToken,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>;

    var modalElement =
        document.getElementById(
            'platformUserModal'
        );

    var userModal =
        bootstrap.Modal.getOrCreateInstance(
            modalElement
        );

    var userForm =
        document.getElementById(
            'platformUserForm'
        );

    var saveButton =
        document.getElementById(
            'savePlatformUserBtn'
        );

    var saveText =
        document.getElementById(
            'savePlatformUserText'
        );

    /*
    |--------------------------------------------------------------------------
    | Toast
    |--------------------------------------------------------------------------
    */
    var toast =
        document.getElementById(
            'platformUserToast'
        );

    var toastMessage =
        document.getElementById(
            'platformUserToastMessage'
        );

    var toastIcon =
        document.getElementById(
            'platformUserToastIcon'
        );

    var toastTimer = null;

    function showToast(
        type,
        message
    ) {
        if (toastTimer) {
            clearTimeout(
                toastTimer
            );
        }

        var icons = {
            success: 'bi-check-lg',
            error: 'bi-x-lg',
            warning: 'bi-exclamation-lg',
            info: 'bi-info-lg'
        };

        if (!icons[type]) {
            type = 'info';
        }

        toast.className =
            'puser-toast ' +
            type +
            ' show';

        toastMessage.textContent =
            message ||
            'Notification';

        toastIcon.className =
            'bi ' +
            icons[type];

        toastTimer =
            setTimeout(
                function () {
                    toast.classList.remove(
                        'show'
                    );
                },
                3000
            );
    }

    window.showPlatformUserToast =
        showToast;

    document
        .getElementById(
            'platformUserToastClose'
        )
        .addEventListener(
            'click',
            function () {
                toast.classList.remove(
                    'show'
                );
            }
        );

    <?php
    if (
        is_array($platformToast) &&
        !empty(
            $platformToast['message']
        )
    ):
    ?>

    showToast(
        <?= json_encode(
            isset(
                $platformToast['type']
            )
                ? $platformToast['type']
                : 'info',
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>,
        <?= json_encode(
            $platformToast['message'],
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ); ?>
    );

    <?php endif; ?>

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */
    function apiPost(data) {
        return fetch(
            apiUrl,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body:
                    new URLSearchParams(
                        data
                    ).toString()
            }
        ).then(function (response) {
            return response
                .text()
                .then(function (text) {
                    var json;

                    try {
                        json =
                            JSON.parse(
                                text
                            );
                    } catch (error) {
                        throw new Error(
                            'Server returned an invalid response.'
                        );
                    }

                    if (
                        !response.ok ||
                        !json.success
                    ) {
                        var error =
                            new Error(
                                json.message ||
                                'Unable to complete the request.'
                            );

                        error.loginUrl =
                            json.login_url ||
                            '';

                        throw error;
                    }

                    return json;
                });
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Modal
    |--------------------------------------------------------------------------
    */
    function resetUserForm() {
        userForm.reset();

        document.getElementById(
            'platformUserId'
        ).value = '0';

        document.getElementById(
            'platformUsername'
        ).value =
            'Auto-generated after creation';

        document.getElementById(
            'platformStatus'
        ).value =
            'active';

        document.getElementById(
            'platformUserModalTitle'
        ).textContent =
            'Add Platform User';

        saveText.textContent =
            'Create User & Send Email';
    }

    document
        .getElementById(
            'addPlatformUserBtn'
        )
        .addEventListener(
            'click',
            function () {
                resetUserForm();

                /*
                 * Prefill sample values so the requested
                 * Rubika sample can be created in one click.
                 */
                document.getElementById(
                    'platformFirstName'
                ).value =
                    'Rubika';

                document.getElementById(
                    'platformLastName'
                ).value =
                    'Sakthi';

                document.getElementById(
                    'platformEmail'
                ).value =
                    'rubiksakthi0907@gmail.com';

                document.getElementById(
                    'platformJobTitle'
                ).value =
                    'Platform Administrator';

                document.getElementById(
                    'platformRoleCode'
                ).value =
                    'platform_admin';

                userModal.show();
            }
        );

    document
        .querySelectorAll(
            '.edit-platform-user'
        )
        .forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    var user =
                        JSON.parse(
                            button.getAttribute(
                                'data-user'
                            )
                        );

                    resetUserForm();

                    document.getElementById(
                        'platformUserId'
                    ).value =
                        user.id;

                    document.getElementById(
                        'platformFirstName'
                    ).value =
                        user.first_name ||
                        '';

                    document.getElementById(
                        'platformLastName'
                    ).value =
                        user.last_name ||
                        '';

                    document.getElementById(
                        'platformEmail'
                    ).value =
                        user.email ||
                        '';

                    document.getElementById(
                        'platformPhone'
                    ).value =
                        user.phone ||
                        '';

                    document.getElementById(
                        'platformJobTitle'
                    ).value =
                        user.job_title ||
                        '';

                    document.getElementById(
                        'platformUsername'
                    ).value =
                        user.username ||
                        '—';

                    document.getElementById(
                        'platformRoleCode'
                    ).value =
                        user.role_code;

                    document.getElementById(
                        'platformStatus'
                    ).value =
                        user.status;

                    document.getElementById(
                        'platformUserModalTitle'
                    ).textContent =
                        'Edit Platform User';

                    saveText.textContent =
                        'Update Platform User';

                    userModal.show();
                }
            );
        });

    userForm.addEventListener(
        'submit',
        function (event) {
            event.preventDefault();

            if (!userForm.reportValidity()) {
                return;
            }

            saveButton.disabled = true;
            saveButton.classList.add(
                'loading'
            );

            var formData =
                new FormData(
                    userForm
                );

            var data = {};

            formData.forEach(
                function (
                    value,
                    key
                ) {
                    data[key] =
                        value;
                }
            );

            apiPost(
                data
            ).then(function (response) {
                userModal.hide();

                showToast(
                    response.email_sent === false
                        ? 'warning'
                        : 'success',
                    response.message
                );

                setTimeout(
                    function () {
                        window.location.reload();
                    },
                    900
                );
            }).catch(function (error) {
                if (error.loginUrl) {
                    window.location.href =
                        error.loginUrl;

                    return;
                }

                showToast(
                    'error',
                    error.message
                );
            }).finally(function () {
                saveButton.disabled =
                    false;

                saveButton.classList.remove(
                    'loading'
                );
            });
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Reset credentials
    |--------------------------------------------------------------------------
    */
    document
        .querySelectorAll(
            '.reset-platform-user'
        )
        .forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    if (
                        !window.confirm(
                            'Generate a new temporary password for ' +
                            button.dataset.name +
                            ' and email the new login credentials?'
                        )
                    ) {
                        return;
                    }

                    apiPost({
                        csrf_token:
                            csrfToken,
                        action:
                            'reset_credentials',
                        id:
                            button.dataset.id
                    }).then(function (
                        response
                    ) {
                        showToast(
                            'success',
                            response.message
                        );
                    }).catch(function (
                        error
                    ) {
                        showToast(
                            'error',
                            error.message
                        );
                    });
                }
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */
    document
        .querySelectorAll(
            '.status-platform-user'
        )
        .forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    var actionLabel =
                        button.dataset.status ===
                            'active'
                            ? 'activate'
                            : 'suspend';

                    if (
                        !window.confirm(
                            'Are you sure you want to ' +
                            actionLabel +
                            ' ' +
                            button.dataset.name +
                            '?'
                        )
                    ) {
                        return;
                    }

                    apiPost({
                        csrf_token:
                            csrfToken,
                        action:
                            'change_status',
                        id:
                            button.dataset.id,
                        status:
                            button.dataset.status
                    }).then(function (
                        response
                    ) {
                        showToast(
                            'success',
                            response.message
                        );

                        setTimeout(
                            function () {
                                window.location.reload();
                            },
                            700
                        );
                    }).catch(function (
                        error
                    ) {
                        showToast(
                            'error',
                            error.message
                        );
                    });
                }
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Filters / 3 second search
    |--------------------------------------------------------------------------
    */
    var filterForm =
        document.getElementById(
            'platformUserFilterForm'
        );

    var searchInput =
        document.getElementById(
            'platformUserSearch'
        );

    var roleFilter =
        document.getElementById(
            'platformUserRoleFilter'
        );

    var statusFilter =
        document.getElementById(
            'platformUserStatusFilter'
        );

    var perPageFilter =
        document.getElementById(
            'platformUserPerPage'
        );

    var pageInput =
        document.getElementById(
            'platformUserPage'
        );

    var countdown =
        document.getElementById(
            'platformUserSearchCountdown'
        );

    var searchTimer = null;
    var countdownTimer = null;

    function submitFilters() {
        if (pageInput) {
            pageInput.value = '1';
        }

        filterForm.submit();
    }

    searchInput.addEventListener(
        'input',
        function () {
            clearTimeout(
                searchTimer
            );

            clearInterval(
                countdownTimer
            );

            var seconds = 3;

            countdown.textContent =
                'Searching in 3s';

            countdownTimer =
                setInterval(
                    function () {
                        seconds--;

                        if (seconds > 0) {
                            countdown.textContent =
                                'Searching in ' +
                                seconds +
                                's';
                        }
                    },
                    1000
                );

            searchTimer =
                setTimeout(
                    function () {
                        clearInterval(
                            countdownTimer
                        );

                        countdown.textContent =
                            'Loading...';

                        submitFilters();
                    },
                    3000
                );
        }
    );

    searchInput.addEventListener(
        'keydown',
        function (event) {
            if (
                event.key ===
                'Enter'
            ) {
                event.preventDefault();

                clearTimeout(
                    searchTimer
                );

                clearInterval(
                    countdownTimer
                );

                submitFilters();
            }
        }
    );

    [
        roleFilter,
        statusFilter,
        perPageFilter
    ].forEach(function (control) {
        control.addEventListener(
            'change',
            submitFilters
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Persistent Sidebar
    |--------------------------------------------------------------------------
    */
    var body =
        document.body;

    var toggle =
        document.getElementById(
            'fpSidebarToggle'
        );

    var close =
        document.getElementById(
            'fpSidebarClose'
        );

    var overlay =
        document.getElementById(
            'fpSidebarOverlay'
        );

    var storageKey =
        'fieldplx_sidebar_collapsed';

    function restoreSidebarState() {
        if (
            window.innerWidth <
            992
        ) {
            body.classList.remove(
                'fp-sidebar-collapsed'
            );

            return;
        }

        if (
            localStorage.getItem(
                storageKey
            ) === '1'
        ) {
            body.classList.add(
                'fp-sidebar-collapsed'
            );
        }
    }

    function saveSidebarState() {
        localStorage.setItem(
            storageKey,
            body.classList.contains(
                'fp-sidebar-collapsed'
            )
                ? '1'
                : '0'
        );
    }

    restoreSidebarState();

    if (toggle) {
        toggle.addEventListener(
            'click',
            function () {
                if (
                    window.innerWidth <
                    992
                ) {
                    body.classList.toggle(
                        'fp-sidebar-mobile-open'
                    );

                    return;
                }

                body.classList.toggle(
                    'fp-sidebar-collapsed'
                );

                saveSidebarState();
            }
        );
    }

    if (close) {
        close.addEventListener(
            'click',
            function () {
                body.classList.remove(
                    'fp-sidebar-mobile-open'
                );
            }
        );
    }

    if (overlay) {
        overlay.addEventListener(
            'click',
            function () {
                body.classList.remove(
                    'fp-sidebar-mobile-open'
                );
            }
        );
    }

})();
</script>

</body>
</html>
