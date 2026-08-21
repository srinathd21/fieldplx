<?php
/**
 * FieldPlx Platform - Platform Users
 *
 * File:
 * platform/platform-users.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - platform_users authentication
 */

require_once __DIR__ . '/includes/auth.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
));

$pageTitle = 'Platform Users - FieldPlx';
$activePage = 'platform-users';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('platformUsersEscape')) {
    function platformUsersEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('platformUsersBind')) {
    function platformUsersBind(
        mysqli_stmt $stmt,
        $types,
        array &$values
    ) {
        if ($types === '') {
            return true;
        }

        $arguments = array($types);

        foreach ($values as $key => $value) {
            $arguments[] = &$values[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('platformUsersLabel')) {
    function platformUsersLabel($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '—';
        }

        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                $value
            )
        );
    }
}

if (!function_exists('platformUsersStatusClass')) {
    function platformUsersStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
                return 'success';

            case 'inactive':
                return 'warning';

            case 'suspended':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('platformUsersRoleClass')) {
    function platformUsersRoleClass($role)
    {
        switch (strtolower(trim((string) $role))) {
            case 'super_admin':
                return 'super';

            case 'platform_admin':
                return 'admin';

            case 'support_admin':
                return 'support';

            case 'billing_admin':
                return 'billing';

            case 'platform_read_only':
                return 'readonly';

            default:
                return 'default';
        }
    }
}

if (!function_exists('platformUsersDate')) {
    function platformUsersDate(
        $value,
        $withTime = true
    ) {
        if (empty($value)) {
            return 'Never';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return 'Never';
        }

        return $withTime
            ? date('d M Y, h:i A', $timestamp)
            : date('d M Y', $timestamp);
    }
}

if (!function_exists('platformUsersInitials')) {
    function platformUsersInitials(
        $firstName,
        $lastName
    ) {
        $firstName = trim((string) $firstName);
        $lastName = trim((string) $lastName);

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
}

if (!function_exists('platformUsersBuildQuery')) {
    function platformUsersBuildQuery(array $changes = array())
    {
        $query = $_GET;

        foreach ($changes as $key => $value) {
            if ($value === '' || $value === null) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return http_build_query($query);
    }
}

/*
|--------------------------------------------------------------------------
| Verify table
|--------------------------------------------------------------------------
*/

$tableCheck = $conn->query("
    SHOW TABLES LIKE 'platform_users'
");

if (!$tableCheck || $tableCheck->num_rows === 0) {
    http_response_code(500);
    exit('The platform_users table does not exist.');
}

$tableCheck->free();

/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search']) &&
    !is_array($_GET['search'])
        ? trim((string) $_GET['search'])
        : '';

$role = isset($_GET['role']) &&
    !is_array($_GET['role'])
        ? trim((string) $_GET['role'])
        : '';

$status = isset($_GET['status']) &&
    !is_array($_GET['status'])
        ? trim((string) $_GET['status'])
        : '';

$sort = isset($_GET['sort']) &&
    !is_array($_GET['sort'])
        ? trim((string) $_GET['sort'])
        : 'latest';

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = isset($_GET['per_page'])
    ? (int) $_GET['per_page']
    : 15;

$allowedRoles = array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
);

$allowedStatuses = array(
    'active',
    'inactive',
    'suspended'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'name_asc',
    'name_desc',
    'last_login_desc',
    'last_login_asc'
);

$allowedPerPage = array(
    10,
    15,
    25,
    50
);

if (!in_array($role, $allowedRoles, true)) {
    $role = '';
}

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 15;
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summarySql = "
    SELECT
        COUNT(*) AS total_count,
        SUM(
            CASE
                WHEN `status` = 'active'
                THEN 1 ELSE 0
            END
        ) AS active_count,
        SUM(
            CASE
                WHEN `status` = 'inactive'
                THEN 1 ELSE 0
            END
        ) AS inactive_count,
        SUM(
            CASE
                WHEN `status` = 'suspended'
                THEN 1 ELSE 0
            END
        ) AS suspended_count,
        SUM(
            CASE
                WHEN `role_code` = 'super_admin'
                THEN 1 ELSE 0
            END
        ) AS super_admin_count
    FROM platform_users
    WHERE `deleted_at` IS NULL
";

$summaryResult = $conn->query($summarySql);
$summaryRow = $summaryResult
    ? $summaryResult->fetch_assoc()
    : array();

$summary = array(
    'total' => isset($summaryRow['total_count'])
        ? (int) $summaryRow['total_count']
        : 0,
    'active' => isset($summaryRow['active_count'])
        ? (int) $summaryRow['active_count']
        : 0,
    'inactive' => isset($summaryRow['inactive_count'])
        ? (int) $summaryRow['inactive_count']
        : 0,
    'suspended' => isset($summaryRow['suspended_count'])
        ? (int) $summaryRow['suspended_count']
        : 0,
    'super_admin' => isset($summaryRow['super_admin_count'])
        ? (int) $summaryRow['super_admin_count']
        : 0
);

if ($summaryResult) {
    $summaryResult->free();
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$where = array(
    "`deleted_at` IS NULL"
);

$params = array();
$types = '';

if ($role !== '') {
    $where[] = "`role_code` = ?";
    $types .= 's';
    $params[] = $role;
}

if ($status !== '') {
    $where[] = "`status` = ?";
    $types .= 's';
    $params[] = $status;
}

if ($search !== '') {
    $where[] = "(
        `first_name` LIKE ?
        OR `last_name` LIKE ?
        OR `email` LIKE ?
        OR `phone` LIKE ?
        OR `job_title` LIKE ?
    )";

    $searchValue = '%' . $search . '%';

    for ($index = 0; $index < 5; $index++) {
        $types .= 's';
        $params[] = $searchValue;
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM platform_users
    {$whereSql}
");

platformUsersBind(
    $countStmt,
    $types,
    $params
);

$countStmt->execute();

$countRow = $countStmt
    ->get_result()
    ->fetch_assoc();

$totalRecords = isset($countRow['total'])
    ? (int) $countRow['total']
    : 0;

$countStmt->close();

$totalPages = max(
    1,
    (int) ceil($totalRecords / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Sorting
|--------------------------------------------------------------------------
*/

switch ($sort) {
    case 'oldest':
        $orderSql =
            "ORDER BY `created_at` ASC";
        break;

    case 'name_asc':
        $orderSql = "
            ORDER BY
                `first_name` ASC,
                `last_name` ASC
        ";
        break;

    case 'name_desc':
        $orderSql = "
            ORDER BY
                `first_name` DESC,
                `last_name` DESC
        ";
        break;

    case 'last_login_desc':
        $orderSql = "
            ORDER BY
                `last_login_at` IS NULL ASC,
                `last_login_at` DESC
        ";
        break;

    case 'last_login_asc':
        $orderSql = "
            ORDER BY
                `last_login_at` IS NULL ASC,
                `last_login_at` ASC
        ";
        break;

    case 'latest':
    default:
        $orderSql =
            "ORDER BY `created_at` DESC";
        break;
}

/*
|--------------------------------------------------------------------------
| Load users
|--------------------------------------------------------------------------
*/

$listSql = "
    SELECT
        `id`,
        `first_name`,
        `last_name`,
        `email`,
        `phone`,
        `avatar_path`,
        `job_title`,
        `role_code`,
        `status`,
        `last_login_at`,
        `created_at`,
        `updated_at`
    FROM platform_users
    {$whereSql}
    {$orderSql}
    LIMIT ? OFFSET ?
";

$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$listTypes = $types . 'ii';

$listStmt = $conn->prepare($listSql);

platformUsersBind(
    $listStmt,
    $listTypes,
    $listParams
);

$listStmt->execute();

$listResult = $listStmt->get_result();
$users = array();

while ($row = $listResult->fetch_assoc()) {
    $users[] = $row;
}

$listStmt->close();

/*
|--------------------------------------------------------------------------
| Current platform user
|--------------------------------------------------------------------------
*/

$currentPlatformUserId = 0;

if (!empty($_SESSION['platform_user_id'])) {
    $currentPlatformUserId =
        (int) $_SESSION['platform_user_id'];
} elseif (!empty($_SESSION['platform_admin_id'])) {
    $currentPlatformUserId =
        (int) $_SESSION['platform_admin_id'];
}

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$successMessage = '';

if (!empty($_SESSION['platform_success_message'])) {
    $successMessage =
        (string) $_SESSION['platform_success_message'];

    unset($_SESSION['platform_success_message']);
}

$errorMessage = '';

if (!empty($_SESSION['platform_error_message'])) {
    $errorMessage =
        (string) $_SESSION['platform_error_message'];

    unset($_SESSION['platform_error_message']);
}

$startRecord = $totalRecords > 0
    ? $offset + 1
    : 0;

$endRecord = min(
    $offset + $perPage,
    $totalRecords
);

$paginationStart = max(1, $page - 2);
$paginationEnd = min(
    $totalPages,
    $page + 2
);

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .platform-users-page {
        display: grid;
        gap: 15px;
    }

    .platform-users-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .platform-users-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .platform-users-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .platform-users-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .platform-users-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .platform-users-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .platform-users-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .platform-users-button {
        min-height: 37px;
        padding: 7px 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #4b5563;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none;
    }

    .platform-users-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .platform-users-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .platform-users-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .platform-users-summary {
        display: grid;
        grid-template-columns:
            repeat(5, minmax(0, 1fr));
        gap: 10px;
    }

    .platform-users-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
        text-decoration: none;
        box-shadow:
            0 4px 15px rgba(31, 41, 55, 0.03);
    }

    .platform-users-summary-card:hover,
    .platform-users-summary-card.selected {
        border-color: #ddd6fe;
        background: #faf8ff;
    }

    .platform-users-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 14px;
    }

    .platform-users-summary-icon.total {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .platform-users-summary-icon.active {
        background: #ecfdf5;
        color: #059669;
    }

    .platform-users-summary-icon.inactive {
        background: #fff7ed;
        color: #d97706;
    }

    .platform-users-summary-icon.suspended {
        background: #fef2f2;
        color: #dc2626;
    }

    .platform-users-summary-icon.super {
        background: #eff6ff;
        color: #2563eb;
    }

    .platform-users-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .platform-users-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .platform-users-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .platform-users-toolbar {
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-bottom: 1px solid #eef0f3;
    }

    .platform-users-search {
        min-width: 220px;
        position: relative;
        flex: 1;
    }

    .platform-users-search i {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 12px;
    }

    .platform-users-control {
        height: 36px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .platform-users-search .platform-users-control {
        padding-left: 33px;
    }

    .platform-users-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .platform-users-filter-button {
        height: 36px;
        padding: 7px 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 0;
        border-radius: 8px;
        background: #111827;
        color: #ffffff;
        font-size: 9px;
        font-weight: 700;
    }

    .platform-users-clear-button {
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #6b7280;
        text-decoration: none;
    }

    .platform-users-table-wrap {
        overflow-x: auto;
    }

    .platform-users-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .platform-users-table th {
        padding: 10px 13px;
        border-bottom: 1px solid #e9ebef;
        background: #fafafa;
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .platform-users-table td {
        padding: 11px 13px;
        border-bottom: 1px solid #f0f1f3;
        color: #374151;
        font-size: 9px;
        vertical-align: middle;
    }

    .platform-users-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .platform-users-table tbody tr:hover {
        background: #fcfbff;
    }

    .platform-user-profile {
        min-width: 210px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .platform-user-avatar {
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 11px;
        font-weight: 800;
    }

    .platform-user-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .platform-user-name {
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .platform-user-self {
        margin-left: 5px;
        padding: 2px 5px;
        border-radius: 999px;
        background: #ede9fe;
        color: #6d28d9;
        font-size: 7px;
        font-weight: 700;
    }

    .platform-user-job {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .platform-user-contact {
        color: #374151;
        font-size: 9px;
        font-weight: 600;
    }

    .platform-user-contact-meta {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .platform-user-role,
    .platform-user-status {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .platform-user-role.super {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .platform-user-role.admin {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .platform-user-role.support {
        background: #ecfeff;
        color: #0e7490;
    }

    .platform-user-role.billing {
        background: #fff7ed;
        color: #b45309;
    }

    .platform-user-role.readonly {
        background: #f3f4f6;
        color: #4b5563;
    }

    .platform-user-role.default {
        background: #f3f4f6;
        color: #4b5563;
    }

    .platform-user-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .platform-user-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .platform-user-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .platform-user-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .platform-user-actions {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .platform-user-action {
        width: 29px;
        height: 29px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        background: #ffffff;
        color: #6b7280;
        font-size: 11px;
        text-decoration: none;
    }

    .platform-user-action:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
        color: #7c3aed;
    }

    .platform-users-empty {
        padding: 48px 20px;
        color: #9ca3af;
        text-align: center;
        font-size: 10px;
    }

    .platform-users-empty i {
        margin-bottom: 10px;
        display: block;
        color: #c4b5fd;
        font-size: 30px;
    }

    .platform-users-pagination-bar {
        min-height: 54px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #eef0f3;
    }

    .platform-users-pagination-info {
        color: #6b7280;
        font-size: 9px;
    }

    .platform-users-pagination {
        margin: 0;
        display: flex;
        gap: 4px;
        list-style: none;
    }

    .platform-users-pagination a,
    .platform-users-pagination span {
        min-width: 29px;
        height: 29px;
        padding: 0 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        background: #ffffff;
        color: #6b7280;
        font-size: 8px;
        text-decoration: none;
    }

    .platform-users-pagination a:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .platform-users-pagination .active {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .platform-users-pagination .disabled {
        opacity: 0.45;
        pointer-events: none;
    }

    @media (max-width: 1100px) {
        .platform-users-summary {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 800px) {
        .platform-users-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .platform-users-actions {
            width: 100%;
        }

        .platform-users-button {
            flex: 1;
        }

        .platform-users-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .platform-users-search {
            min-width: 0;
        }

        .platform-users-toolbar .platform-users-control,
        .platform-users-filter-button,
        .platform-users-clear-button {
            width: 100% !important;
        }

        .platform-users-pagination-bar {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    @media (max-width: 600px) {
        .platform-users-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 420px) {
        .platform-users-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="platform-users-page">

    <?php if ($successMessage !== ''): ?>
        <div class="platform-users-alert success">
            <i class="bi bi-check-circle"></i>
            <span><?= platformUsersEscape($successMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="platform-users-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= platformUsersEscape($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <div class="platform-users-header">
        <div>
            <h2 class="platform-users-title">
                Platform Users
            </h2>

            <div class="platform-users-description">
                Manage platform administrators, support, billing, and read-only users.
            </div>
        </div>

        <div class="platform-users-actions">
            <?php if (
                hasPlatformRole(array(
                    'super_admin',
                    'platform_admin'
                ))
            ): ?>
                <a
                    href="platform-user-add.php"
                    class="platform-users-button primary"
                >
                    <i class="bi bi-person-plus"></i>
                    Add Platform User
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="platform-users-summary">

        <a
            href="?<?= platformUsersEscape(
                platformUsersBuildQuery(
                    array(
                        'status' => '',
                        'role' => '',
                        'page' => 1
                    )
                )
            ); ?>"
            class="platform-users-summary-card <?= $status === '' &&
                $role === ''
                    ? 'selected'
                    : ''; ?>"
        >
            <span class="platform-users-summary-icon total">
                <i class="bi bi-people"></i>
            </span>

            <span>
                <span class="platform-users-summary-label">
                    Total Users
                </span>

                <span class="platform-users-summary-value">
                    <?= number_format($summary['total']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= platformUsersEscape(
                platformUsersBuildQuery(
                    array(
                        'status' => 'active',
                        'page' => 1
                    )
                )
            ); ?>"
            class="platform-users-summary-card <?= $status === 'active'
                ? 'selected'
                : ''; ?>"
        >
            <span class="platform-users-summary-icon active">
                <i class="bi bi-check-circle"></i>
            </span>

            <span>
                <span class="platform-users-summary-label">
                    Active
                </span>

                <span class="platform-users-summary-value">
                    <?= number_format($summary['active']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= platformUsersEscape(
                platformUsersBuildQuery(
                    array(
                        'status' => 'inactive',
                        'page' => 1
                    )
                )
            ); ?>"
            class="platform-users-summary-card <?= $status === 'inactive'
                ? 'selected'
                : ''; ?>"
        >
            <span class="platform-users-summary-icon inactive">
                <i class="bi bi-pause-circle"></i>
            </span>

            <span>
                <span class="platform-users-summary-label">
                    Inactive
                </span>

                <span class="platform-users-summary-value">
                    <?= number_format($summary['inactive']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= platformUsersEscape(
                platformUsersBuildQuery(
                    array(
                        'status' => 'suspended',
                        'page' => 1
                    )
                )
            ); ?>"
            class="platform-users-summary-card <?= $status === 'suspended'
                ? 'selected'
                : ''; ?>"
        >
            <span class="platform-users-summary-icon suspended">
                <i class="bi bi-slash-circle"></i>
            </span>

            <span>
                <span class="platform-users-summary-label">
                    Suspended
                </span>

                <span class="platform-users-summary-value">
                    <?= number_format($summary['suspended']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= platformUsersEscape(
                platformUsersBuildQuery(
                    array(
                        'role' => 'super_admin',
                        'page' => 1
                    )
                )
            ); ?>"
            class="platform-users-summary-card <?= $role === 'super_admin'
                ? 'selected'
                : ''; ?>"
        >
            <span class="platform-users-summary-icon super">
                <i class="bi bi-shield-lock"></i>
            </span>

            <span>
                <span class="platform-users-summary-label">
                    Super Admins
                </span>

                <span class="platform-users-summary-value">
                    <?= number_format($summary['super_admin']); ?>
                </span>
            </span>
        </a>

    </div>

    <div class="platform-users-card">

        <form
            method="get"
            class="platform-users-toolbar"
            id="platformUsersFilterForm"
        >
            <div class="platform-users-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="search"
                    class="form-control platform-users-control"
                    value="<?= platformUsersEscape($search); ?>"
                    placeholder="Search name, email, phone, or job title..."
                    autocomplete="off"
                >
            </div>

            <select
                name="role"
                class="form-select platform-users-control"
                style="width:170px;"
            >
                <option value="">
                    All roles
                </option>

                <?php foreach ($allowedRoles as $roleOption): ?>
                    <option
                        value="<?= platformUsersEscape($roleOption); ?>"
                        <?= $role === $roleOption
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= platformUsersEscape(
                            platformUsersLabel($roleOption)
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="status"
                class="form-select platform-users-control"
                style="width:135px;"
            >
                <option value="">
                    All statuses
                </option>

                <?php foreach ($allowedStatuses as $statusOption): ?>
                    <option
                        value="<?= platformUsersEscape($statusOption); ?>"
                        <?= $status === $statusOption
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= platformUsersEscape(
                            platformUsersLabel($statusOption)
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="sort"
                class="form-select platform-users-control"
                style="width:150px;"
            >
                <option
                    value="latest"
                    <?= $sort === 'latest'
                        ? 'selected'
                        : ''; ?>
                >
                    Latest first
                </option>

                <option
                    value="oldest"
                    <?= $sort === 'oldest'
                        ? 'selected'
                        : ''; ?>
                >
                    Oldest first
                </option>

                <option
                    value="name_asc"
                    <?= $sort === 'name_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Name A-Z
                </option>

                <option
                    value="name_desc"
                    <?= $sort === 'name_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Name Z-A
                </option>

                <option
                    value="last_login_desc"
                    <?= $sort === 'last_login_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Recent login
                </option>

                <option
                    value="last_login_asc"
                    <?= $sort === 'last_login_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Oldest login
                </option>
            </select>

            <select
                name="per_page"
                class="form-select platform-users-control"
                style="width:90px;"
            >
                <?php foreach ($allowedPerPage as $pageSize): ?>
                    <option
                        value="<?= (int) $pageSize; ?>"
                        <?= $perPage === $pageSize
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= (int) $pageSize; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button
                type="submit"
                class="platform-users-filter-button"
            >
                <i class="bi bi-funnel"></i>
                Apply
            </button>

            <?php if (
                $search !== '' ||
                $role !== '' ||
                $status !== '' ||
                $sort !== 'latest' ||
                $perPage !== 15
            ): ?>
                <a
                    href="platform-users.php"
                    class="platform-users-clear-button"
                    title="Clear filters"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($users)): ?>
            <div class="platform-users-empty">
                <i class="bi bi-people"></i>
                No platform users matched your filters.
            </div>
        <?php else: ?>

            <div class="platform-users-table-wrap">
                <table class="platform-users-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Contact</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Created</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $fullName = trim(
                                (string) $user['first_name'] .
                                ' ' .
                                (string) $user['last_name']
                            );

                            $isCurrentUser =
                                $currentPlatformUserId > 0 &&
                                $currentPlatformUserId ===
                                (int) $user['id'];
                            ?>

                            <tr>
                                <td>
                                    <div class="platform-user-profile">
                                        <div class="platform-user-avatar">
                                            <?php if (
                                                !empty($user['avatar_path'])
                                            ): ?>
                                                <img
                                                    src="../<?= platformUsersEscape(
                                                        ltrim(
                                                            $user[
                                                                'avatar_path'
                                                            ],
                                                            '/'
                                                        )
                                                    ); ?>"
                                                    alt=""
                                                >
                                            <?php else: ?>
                                                <?= platformUsersEscape(
                                                    platformUsersInitials(
                                                        $user['first_name'],
                                                        $user['last_name']
                                                    )
                                                ); ?>
                                            <?php endif; ?>
                                        </div>

                                        <div>
                                            <span class="platform-user-name">
                                                <?= platformUsersEscape(
                                                    $fullName !== ''
                                                        ? $fullName
                                                        : 'Platform User'
                                                ); ?>

                                                <?php if ($isCurrentUser): ?>
                                                    <span class="platform-user-self">
                                                        You
                                                    </span>
                                                <?php endif; ?>
                                            </span>

                                            <span class="platform-user-job">
                                                <?= platformUsersEscape(
                                                    !empty(
                                                        $user['job_title']
                                                    )
                                                        ? $user['job_title']
                                                        : 'No job title'
                                                ); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="platform-user-contact">
                                        <?= platformUsersEscape(
                                            $user['email']
                                        ); ?>
                                    </span>

                                    <span class="platform-user-contact-meta">
                                        <?= platformUsersEscape(
                                            !empty($user['phone'])
                                                ? $user['phone']
                                                : 'No phone number'
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span
                                        class="platform-user-role <?= platformUsersEscape(
                                            platformUsersRoleClass(
                                                $user['role_code']
                                            )
                                        ); ?>"
                                    >
                                        <?= platformUsersEscape(
                                            platformUsersLabel(
                                                $user['role_code']
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span
                                        class="platform-user-status <?= platformUsersEscape(
                                            platformUsersStatusClass(
                                                $user['status']
                                            )
                                        ); ?>"
                                    >
                                        <?= platformUsersEscape(
                                            platformUsersLabel(
                                                $user['status']
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <?= platformUsersEscape(
                                        platformUsersDate(
                                            $user['last_login_at']
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <?= platformUsersEscape(
                                        platformUsersDate(
                                            $user['created_at'],
                                            false
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <div class="platform-user-actions">
                                        <a
                                            href="platform-user-view.php?id=<?= (int)
                                                $user['id']; ?>"
                                            class="platform-user-action"
                                            title="View user"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <?php if (
                                            hasPlatformRole(array(
                                                'super_admin',
                                                'platform_admin'
                                            ))
                                        ): ?>
                                            <a
                                                href="platform-user-edit.php?id=<?= (int)
                                                    $user['id']; ?>"
                                                class="platform-user-action"
                                                title="Edit user"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <div class="platform-users-pagination-bar">
            <div class="platform-users-pagination-info">
                Showing
                <?= number_format($startRecord); ?>
                to
                <?= number_format($endRecord); ?>
                of
                <?= number_format($totalRecords); ?>
                platform users
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Platform user pagination">
                    <ul class="platform-users-pagination">
                        <li>
                            <?php if ($page > 1): ?>
                                <a
                                    href="?<?= platformUsersEscape(
                                        platformUsersBuildQuery(
                                            array(
                                                'page' => $page - 1
                                            )
                                        )
                                    ); ?>"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    <i class="bi bi-chevron-left"></i>
                                </span>
                            <?php endif; ?>
                        </li>

                        <?php if ($paginationStart > 1): ?>
                            <li>
                                <a
                                    href="?<?= platformUsersEscape(
                                        platformUsersBuildQuery(
                                            array('page' => 1)
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

                        <?php for (
                            $pageNumber = $paginationStart;
                            $pageNumber <= $paginationEnd;
                            $pageNumber++
                        ): ?>
                            <li>
                                <?php if ($pageNumber === $page): ?>
                                    <span class="active">
                                        <?= (int) $pageNumber; ?>
                                    </span>
                                <?php else: ?>
                                    <a
                                        href="?<?= platformUsersEscape(
                                            platformUsersBuildQuery(
                                                array(
                                                    'page' =>
                                                        $pageNumber
                                                )
                                            )
                                        ); ?>"
                                    >
                                        <?= (int) $pageNumber; ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endfor; ?>

                        <?php if (
                            $paginationEnd < $totalPages
                        ): ?>
                            <?php if (
                                $paginationEnd <
                                $totalPages - 1
                            ): ?>
                                <li>
                                    <span>…</span>
                                </li>
                            <?php endif; ?>

                            <li>
                                <a
                                    href="?<?= platformUsersEscape(
                                        platformUsersBuildQuery(
                                            array(
                                                'page' =>
                                                    $totalPages
                                            )
                                        )
                                    ); ?>"
                                >
                                    <?= (int) $totalPages; ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li>
                            <?php if ($page < $totalPages): ?>
                                <a
                                    href="?<?= platformUsersEscape(
                                        platformUsersBuildQuery(
                                            array(
                                                'page' => $page + 1
                                            )
                                        )
                                    ); ?>"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    <i class="bi bi-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
(function () {
    'use strict';

    const form = document.getElementById(
        'platformUsersFilterForm'
    );

    if (!form) {
        return;
    }

    const selects = form.querySelectorAll('select');

    selects.forEach(function (select) {
        select.addEventListener(
            'change',
            function () {
                form.submit();
            }
        );
    });

    const searchInput = form.querySelector(
        'input[name="search"]'
    );

    let searchTimer = null;

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            function () {
                window.clearTimeout(searchTimer);

                searchTimer = window.setTimeout(
                    function () {
                        form.submit();
                    },
                    600
                );
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
