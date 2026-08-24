<?php
/**
 * FieldPlx Platform - View Tenant User
 *
 * File:
 * platform/tenant-user-view.php
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

$pageTitle = 'Tenant User Details - FieldPlx';
$activePage = 'tenant-users';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('tenantUserViewEscape')) {
    function tenantUserViewEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantUserViewTableExists')) {
    function tenantUserViewTableExists(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        $tableName = trim((string) $tableName);

        if ($tableName === '') {
            return false;
        }

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        if (!$stmt) {
            $cache[$tableName] = false;
            return false;
        }

        $stmt->bind_param('s', $tableName);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('tenantUserViewColumns')) {
    function tenantUserViewColumns(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTable = str_replace('`', '``', $tableName);

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTable}`"
        );

        if (!$result) {
            return $cache[$tableName];
        }

        while ($row = $result->fetch_assoc()) {
            if (!empty($row['Field'])) {
                $cache[$tableName][
                    (string) $row['Field']
                ] = $row;
            }
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('tenantUserViewFirstColumn')) {
    function tenantUserViewFirstColumn(
        array $columns,
        array $candidates
    ) {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('tenantUserViewDate')) {
    function tenantUserViewDate(
        $value,
        $withTime = false
    ) {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return '—';
        }

        return $withTime
            ? date('d M Y, h:i A', $timestamp)
            : date('d M Y', $timestamp);
    }
}

if (!function_exists('tenantUserViewLabel')) {
    function tenantUserViewLabel($value)
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

if (!function_exists('tenantUserViewStatusClass')) {
    function tenantUserViewStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
                return 'success';

            case 'pending':
            case 'invited':
                return 'warning';

            case 'inactive':
            case 'suspended':
            case 'blocked':
            case 'deleted':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('tenantUserViewInitials')) {
    function tenantUserViewInitials(
        $firstName,
        $lastName,
        $email
    ) {
        $initials = '';

        if (trim((string) $firstName) !== '') {
            $initials .= strtoupper(
                substr(
                    trim((string) $firstName),
                    0,
                    1
                )
            );
        }

        if (trim((string) $lastName) !== '') {
            $initials .= strtoupper(
                substr(
                    trim((string) $lastName),
                    0,
                    1
                )
            );
        }

        if (
            $initials === '' &&
            trim((string) $email) !== ''
        ) {
            $initials = strtoupper(
                substr(
                    trim((string) $email),
                    0,
                    1
                )
            );
        }

        return $initials !== ''
            ? $initials
            : 'U';
    }
}

/*
|--------------------------------------------------------------------------
| Verify required tables
|--------------------------------------------------------------------------
*/

if (!tenantUserViewTableExists($conn, 'users')) {
    http_response_code(500);
    exit('The users table does not exist.');
}

if (!tenantUserViewTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$userColumns = tenantUserViewColumns($conn, 'users');
$tenantColumns = tenantUserViewColumns($conn, 'tenants');

/*
|--------------------------------------------------------------------------
| Detect user columns
|--------------------------------------------------------------------------
*/

$userIdColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('id', 'user_id')
);

$userTenantColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('tenant_id')
);

$userFirstNameColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('first_name', 'firstname', 'given_name')
);

$userLastNameColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('last_name', 'lastname', 'surname')
);

$userNameColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('name', 'full_name', 'display_name')
);

$userEmailColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('email', 'email_address')
);

$userPhoneColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('phone', 'mobile', 'phone_number')
);

$userUsernameColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('username', 'user_name')
);

$userStatusColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('status', 'account_status')
);

$userRoleIdColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('role_id')
);

$userRoleCodeColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('role_code', 'user_role', 'role')
);

$userAvatarColumn = tenantUserViewFirstColumn(
    $userColumns,
    array(
        'avatar_path',
        'profile_photo',
        'photo_path',
        'image'
    )
);

$userJobTitleColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('job_title', 'designation', 'position')
);

$userCreatedAtColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('created_at', 'created_on')
);

$userUpdatedAtColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('updated_at', 'updated_on')
);

$userLastLoginColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('last_login_at', 'last_login')
);

$userLastIpColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('last_login_ip', 'last_ip', 'login_ip')
);

$userDeletedColumn = tenantUserViewFirstColumn(
    $userColumns,
    array('deleted_at')
);

if (
    $userIdColumn === '' ||
    $userTenantColumn === ''
) {
    http_response_code(500);
    exit('The users table requires id and tenant_id columns.');
}

/*
|--------------------------------------------------------------------------
| Detect tenant columns
|--------------------------------------------------------------------------
*/

$tenantIdColumn = tenantUserViewFirstColumn(
    $tenantColumns,
    array('id', 'tenant_id')
);

$tenantNameColumn = tenantUserViewFirstColumn(
    $tenantColumns,
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$tenantCodeColumn = tenantUserViewFirstColumn(
    $tenantColumns,
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$tenantEmailColumn = tenantUserViewFirstColumn(
    $tenantColumns,
    array('email', 'business_email', 'contact_email')
);

$tenantPhoneColumn = tenantUserViewFirstColumn(
    $tenantColumns,
    array('phone', 'mobile', 'contact_phone')
);

$tenantStatusColumn = tenantUserViewFirstColumn(
    $tenantColumns,
    array('status')
);

$tenantLogoColumn = tenantUserViewFirstColumn(
    $tenantColumns,
    array('logo_path', 'logo', 'business_logo')
);

$tenantDeletedColumn = tenantUserViewFirstColumn(
    $tenantColumns,
    array('deleted_at')
);

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);
    exit('The tenants table requires id and name columns.');
}

/*
|--------------------------------------------------------------------------
| Detect roles table
|--------------------------------------------------------------------------
*/

$hasRolesTable = tenantUserViewTableExists($conn, 'roles');

$roleIdColumn = '';
$roleNameColumn = '';
$roleCodeColumn = '';
$roleDescriptionColumn = '';
$roleTenantColumn = '';
$roleDeletedColumn = '';

if ($hasRolesTable) {
    $roleColumns = tenantUserViewColumns($conn, 'roles');

    $roleIdColumn = tenantUserViewFirstColumn(
        $roleColumns,
        array('id', 'role_id')
    );

    $roleNameColumn = tenantUserViewFirstColumn(
        $roleColumns,
        array('name', 'role_name')
    );

    $roleCodeColumn = tenantUserViewFirstColumn(
        $roleColumns,
        array('code', 'role_code')
    );

    $roleDescriptionColumn = tenantUserViewFirstColumn(
        $roleColumns,
        array('description', 'notes', 'remarks')
    );

    $roleTenantColumn = tenantUserViewFirstColumn(
        $roleColumns,
        array('tenant_id')
    );

    $roleDeletedColumn = tenantUserViewFirstColumn(
        $roleColumns,
        array('deleted_at')
    );
}

/*
|--------------------------------------------------------------------------
| Load tenant user
|--------------------------------------------------------------------------
*/

$userId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($userId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid tenant user.';

    header('Location: tenant-users.php');
    exit;
}

$select = array(
    "u.`{$userIdColumn}` AS user_id",
    "u.`{$userTenantColumn}` AS tenant_id",
    "t.`{$tenantNameColumn}` AS tenant_name"
);

$select[] = $tenantCodeColumn !== ''
    ? "t.`{$tenantCodeColumn}` AS tenant_code"
    : "'' AS tenant_code";

$select[] = $tenantEmailColumn !== ''
    ? "t.`{$tenantEmailColumn}` AS tenant_email"
    : "'' AS tenant_email";

$select[] = $tenantPhoneColumn !== ''
    ? "t.`{$tenantPhoneColumn}` AS tenant_phone"
    : "'' AS tenant_phone";

$select[] = $tenantStatusColumn !== ''
    ? "t.`{$tenantStatusColumn}` AS tenant_status"
    : "'' AS tenant_status";

$select[] = $tenantLogoColumn !== ''
    ? "t.`{$tenantLogoColumn}` AS tenant_logo"
    : "'' AS tenant_logo";

$select[] = $userFirstNameColumn !== ''
    ? "u.`{$userFirstNameColumn}` AS first_name"
    : "'' AS first_name";

$select[] = $userLastNameColumn !== ''
    ? "u.`{$userLastNameColumn}` AS last_name"
    : "'' AS last_name";

$select[] = $userNameColumn !== ''
    ? "u.`{$userNameColumn}` AS full_name"
    : "'' AS full_name";

$select[] = $userEmailColumn !== ''
    ? "u.`{$userEmailColumn}` AS email"
    : "'' AS email";

$select[] = $userPhoneColumn !== ''
    ? "u.`{$userPhoneColumn}` AS phone"
    : "'' AS phone";

$select[] = $userUsernameColumn !== ''
    ? "u.`{$userUsernameColumn}` AS username"
    : "'' AS username";

$select[] = $userStatusColumn !== ''
    ? "u.`{$userStatusColumn}` AS user_status"
    : "'active' AS user_status";

$select[] = $userRoleIdColumn !== ''
    ? "u.`{$userRoleIdColumn}` AS role_id"
    : "0 AS role_id";

$select[] = $userRoleCodeColumn !== ''
    ? "u.`{$userRoleCodeColumn}` AS user_role_code"
    : "'' AS user_role_code";

$select[] = $userAvatarColumn !== ''
    ? "u.`{$userAvatarColumn}` AS avatar_path"
    : "'' AS avatar_path";

$select[] = $userJobTitleColumn !== ''
    ? "u.`{$userJobTitleColumn}` AS job_title"
    : "'' AS job_title";

$select[] = $userCreatedAtColumn !== ''
    ? "u.`{$userCreatedAtColumn}` AS created_at"
    : "NULL AS created_at";

$select[] = $userUpdatedAtColumn !== ''
    ? "u.`{$userUpdatedAtColumn}` AS updated_at"
    : "NULL AS updated_at";

$select[] = $userLastLoginColumn !== ''
    ? "u.`{$userLastLoginColumn}` AS last_login_at"
    : "NULL AS last_login_at";

$select[] = $userLastIpColumn !== ''
    ? "u.`{$userLastIpColumn}` AS last_login_ip"
    : "'' AS last_login_ip";

$roleJoinSql = '';

if (
    $userRoleIdColumn !== '' &&
    $hasRolesTable &&
    $roleIdColumn !== ''
) {
    $roleJoinSql = "
        LEFT JOIN roles r
            ON r.`{$roleIdColumn}` =
               u.`{$userRoleIdColumn}`
    ";

    if (
        $roleTenantColumn !== '' &&
        $userTenantColumn !== ''
    ) {
        $roleJoinSql .= "
            AND (
                r.`{$roleTenantColumn}` =
                    u.`{$userTenantColumn}`
                OR r.`{$roleTenantColumn}` IS NULL
            )
        ";
    }

    if ($roleDeletedColumn !== '') {
        $roleJoinSql .= "
            AND r.`{$roleDeletedColumn}` IS NULL
        ";
    }

    $select[] = $roleNameColumn !== ''
        ? "r.`{$roleNameColumn}` AS role_name"
        : "'' AS role_name";

    $select[] = $roleCodeColumn !== ''
        ? "r.`{$roleCodeColumn}` AS role_code"
        : "'' AS role_code";

    $select[] = $roleDescriptionColumn !== ''
        ? "r.`{$roleDescriptionColumn}` AS role_description"
        : "'' AS role_description";
} else {
    $select[] = "'' AS role_name";
    $select[] = "'' AS role_code";
    $select[] = "'' AS role_description";
}

$sql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM users u
    INNER JOIN tenants t
        ON t.`{$tenantIdColumn}` =
           u.`{$userTenantColumn}`
    {$roleJoinSql}
    WHERE u.`{$userIdColumn}` = ?
";

if ($userDeletedColumn !== '') {
    $sql .= "
        AND u.`{$userDeletedColumn}` IS NULL
    ";
}

if ($tenantDeletedColumn !== '') {
    $sql .= "
        AND t.`{$tenantDeletedColumn}` IS NULL
    ";
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userId);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

if (!$user) {
    $_SESSION['platform_error_message'] =
        'Tenant user not found.';

    header('Location: tenant-users.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Display values
|--------------------------------------------------------------------------
*/

$displayName = trim(
    (string) $user['full_name']
);

if ($displayName === '') {
    $displayName = trim(
        trim((string) $user['first_name']) .
        ' ' .
        trim((string) $user['last_name'])
    );
}

if ($displayName === '') {
    $displayName = !empty($user['email'])
        ? $user['email']
        : 'Unnamed User';
}

$roleName = trim((string) $user['role_name']);

if ($roleName === '') {
    $roleName = trim(
        (string) $user['user_role_code']
    );
}

if ($roleName === '') {
    $roleName = 'User';
}

$roleCode = trim((string) $user['role_code']);

if ($roleCode === '') {
    $roleCode = trim(
        (string) $user['user_role_code']
    );
}

$userStatus = strtolower(
    trim((string) $user['user_status'])
);

if ($userStatus === '') {
    $userStatus = 'active';
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .tenant-user-view-page {
        max-width: 1120px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .tenant-user-view-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .tenant-user-view-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-user-view-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .tenant-user-view-actions {
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
    }

    .tenant-user-view-button {
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

    .tenant-user-view-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-user-view-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .tenant-user-view-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .tenant-user-view-hero {
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background:
            linear-gradient(
                135deg,
                #ffffff,
                #faf8ff
            );
        box-shadow:
            0 6px 24px rgba(31, 41, 55, 0.04);
    }

    .tenant-user-view-avatar {
        width: 82px;
        height: 82px;
        flex: 0 0 82px;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 4px solid #ffffff;
        border-radius: 20px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        box-shadow:
            0 8px 22px rgba(91, 33, 182, 0.18);
        color: #ffffff;
        font-size: 22px;
        font-weight: 800;
    }

    .tenant-user-view-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .tenant-user-view-hero-content {
        min-width: 0;
        flex: 1;
    }

    .tenant-user-view-name {
        margin: 0;
        color: #111827;
        font-size: 20px;
        font-weight: 800;
    }

    .tenant-user-view-subline {
        margin-top: 5px;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        color: #6b7280;
        font-size: 9px;
    }

    .tenant-user-view-status {
        padding: 5px 8px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .tenant-user-view-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .tenant-user-view-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .tenant-user-view-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-user-view-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .tenant-user-view-grid {
        display: grid;
        grid-template-columns:
            minmax(0, 1.45fr)
            minmax(300px, 0.85fr);
        gap: 15px;
        align-items: start;
    }

    .tenant-user-view-main,
    .tenant-user-view-side {
        display: grid;
        gap: 15px;
    }

    .tenant-user-view-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-user-view-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .tenant-user-view-card-icon {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 13px;
    }

    .tenant-user-view-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .tenant-user-view-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-user-view-card-body {
        padding: 15px;
    }

    .tenant-user-view-details {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .tenant-user-view-detail {
        min-width: 0;
        padding: 11px 12px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .tenant-user-view-detail.full {
        grid-column: 1 / -1;
    }

    .tenant-user-view-detail-label {
        display: block;
        color: #9ca3af;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.35px;
        text-transform: uppercase;
    }

    .tenant-user-view-detail-value {
        margin-top: 4px;
        display: block;
        overflow-wrap: anywhere;
        color: #374151;
        font-size: 10px;
        font-weight: 700;
        line-height: 1.45;
    }

    .tenant-user-view-role-box {
        padding: 14px;
        border: 1px solid #ddd6fe;
        border-radius: 10px;
        background: #faf8ff;
    }

    .tenant-user-view-role-name {
        color: #5b21b6;
        font-size: 12px;
        font-weight: 800;
    }

    .tenant-user-view-role-code {
        margin-top: 3px;
        color: #8b5cf6;
        font-size: 8px;
    }

    .tenant-user-view-role-description {
        margin-top: 9px;
        color: #6b7280;
        font-size: 9px;
        line-height: 1.55;
    }

    .tenant-user-view-tenant {
        display: flex;
        align-items: center;
        gap: 11px;
    }

    .tenant-user-view-tenant-logo {
        width: 45px;
        height: 45px;
        flex: 0 0 45px;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #111827;
        color: #ffffff;
        font-size: 11px;
        font-weight: 800;
    }

    .tenant-user-view-tenant-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .tenant-user-view-tenant-name {
        color: #111827;
        font-size: 11px;
        font-weight: 800;
    }

    .tenant-user-view-tenant-code {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-user-view-quick-links {
        display: grid;
        gap: 8px;
    }

    .tenant-user-view-quick-link {
        min-height: 40px;
        padding: 9px 11px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #ffffff;
        color: #4b5563;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none;
    }

    .tenant-user-view-quick-link:hover {
        border-color: #c4b5fd;
        background: #faf8ff;
        color: #7c3aed;
    }

    .tenant-user-view-quick-link i {
        font-size: 13px;
    }

    @media (max-width: 900px) {
        .tenant-user-view-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 650px) {
        .tenant-user-view-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .tenant-user-view-actions {
            width: 100%;
        }

        .tenant-user-view-button {
            flex: 1;
        }

        .tenant-user-view-hero {
            align-items: flex-start;
            flex-direction: column;
        }

        .tenant-user-view-details {
            grid-template-columns: 1fr;
        }

        .tenant-user-view-detail.full {
            grid-column: auto;
        }
    }
</style>

<div class="tenant-user-view-page">

    <div class="tenant-user-view-header">
        <div>
            <h2 class="tenant-user-view-title">
                Tenant User Details
            </h2>

            <div class="tenant-user-view-description">
                Review user, tenant, role, and account information.
            </div>
        </div>

        <div class="tenant-user-view-actions">
            <a
                href="tenant-users.php?tenant_id=<?= (int) $user['tenant_id']; ?>"
                class="tenant-user-view-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Users
            </a>

            <?php if (
                function_exists('canManagePlatformTenants') &&
                canManagePlatformTenants()
            ): ?>
                <a
                    href="tenant-user-edit.php?id=<?= (int) $user['user_id']; ?>"
                    class="tenant-user-view-button primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit User
                </a>
            <?php endif; ?>
        </div>
    </div>

    <section class="tenant-user-view-hero">
        <div class="tenant-user-view-avatar">
            <?php if (!empty($user['avatar_path'])): ?>
                <img
                    src="../<?= tenantUserViewEscape(
                        ltrim(
                            $user['avatar_path'],
                            '/'
                        )
                    ); ?>"
                    alt=""
                >
            <?php else: ?>
                <?= tenantUserViewEscape(
                    tenantUserViewInitials(
                        $user['first_name'],
                        $user['last_name'],
                        $user['email']
                    )
                ); ?>
            <?php endif; ?>
        </div>

        <div class="tenant-user-view-hero-content">
            <h1 class="tenant-user-view-name">
                <?= tenantUserViewEscape($displayName); ?>
            </h1>

            <div class="tenant-user-view-subline">
                <?php if (!empty($user['job_title'])): ?>
                    <span>
                        <i class="bi bi-briefcase me-1"></i>
                        <?= tenantUserViewEscape(
                            $user['job_title']
                        ); ?>
                    </span>
                <?php endif; ?>

                <span>
                    <i class="bi bi-building me-1"></i>
                    <?= tenantUserViewEscape(
                        $user['tenant_name']
                    ); ?>
                </span>

                <span
                    class="tenant-user-view-status <?= tenantUserViewEscape(
                        tenantUserViewStatusClass(
                            $userStatus
                        )
                    ); ?>"
                >
                    <?= tenantUserViewEscape(
                        tenantUserViewLabel(
                            $userStatus
                        )
                    ); ?>
                </span>
            </div>
        </div>
    </section>

    <div class="tenant-user-view-grid">

        <div class="tenant-user-view-main">

            <section class="tenant-user-view-card">
                <div class="tenant-user-view-card-header">
                    <span class="tenant-user-view-card-icon">
                        <i class="bi bi-person-vcard"></i>
                    </span>

                    <div>
                        <h3 class="tenant-user-view-card-title">
                            User Information
                        </h3>

                        <div class="tenant-user-view-card-subtitle">
                            Personal and login details
                        </div>
                    </div>
                </div>

                <div class="tenant-user-view-card-body">
                    <div class="tenant-user-view-details">

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                First Name
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    !empty($user['first_name'])
                                        ? $user['first_name']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                Last Name
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    !empty($user['last_name'])
                                        ? $user['last_name']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                Email Address
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    !empty($user['email'])
                                        ? $user['email']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                Phone Number
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    !empty($user['phone'])
                                        ? $user['phone']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                Username
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    !empty($user['username'])
                                        ? $user['username']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                User ID
                            </span>

                            <span class="tenant-user-view-detail-value">
                                #<?= (int) $user['user_id']; ?>
                            </span>
                        </div>

                    </div>
                </div>
            </section>

            <section class="tenant-user-view-card">
                <div class="tenant-user-view-card-header">
                    <span class="tenant-user-view-card-icon">
                        <i class="bi bi-clock-history"></i>
                    </span>

                    <div>
                        <h3 class="tenant-user-view-card-title">
                            Account Activity
                        </h3>

                        <div class="tenant-user-view-card-subtitle">
                            Login and record timestamps
                        </div>
                    </div>
                </div>

                <div class="tenant-user-view-card-body">
                    <div class="tenant-user-view-details">

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                Last Login
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    tenantUserViewDate(
                                        $user['last_login_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                Last Login IP
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    !empty(
                                        $user['last_login_ip']
                                    )
                                        ? $user['last_login_ip']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                Created
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    tenantUserViewDate(
                                        $user['created_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail">
                            <span class="tenant-user-view-detail-label">
                                Last Updated
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    tenantUserViewDate(
                                        $user['updated_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>

                    </div>
                </div>
            </section>

        </div>

        <aside class="tenant-user-view-side">

            <section class="tenant-user-view-card">
                <div class="tenant-user-view-card-header">
                    <span class="tenant-user-view-card-icon">
                        <i class="bi bi-shield-check"></i>
                    </span>

                    <div>
                        <h3 class="tenant-user-view-card-title">
                            Assigned Role
                        </h3>

                        <div class="tenant-user-view-card-subtitle">
                            Tenant user access role
                        </div>
                    </div>
                </div>

                <div class="tenant-user-view-card-body">
                    <div class="tenant-user-view-role-box">
                        <div class="tenant-user-view-role-name">
                            <?= tenantUserViewEscape(
                                tenantUserViewLabel($roleName)
                            ); ?>
                        </div>

                        <div class="tenant-user-view-role-code">
                            <?= tenantUserViewEscape(
                                $roleCode !== ''
                                    ? $roleCode
                                    : 'No role code'
                            ); ?>
                        </div>

                        <div class="tenant-user-view-role-description">
                            <?= tenantUserViewEscape(
                                !empty(
                                    $user['role_description']
                                )
                                    ? $user[
                                        'role_description'
                                    ]
                                    : 'No description is available for this role.'
                            ); ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="tenant-user-view-card">
                <div class="tenant-user-view-card-header">
                    <span class="tenant-user-view-card-icon">
                        <i class="bi bi-building"></i>
                    </span>

                    <div>
                        <h3 class="tenant-user-view-card-title">
                            Tenant
                        </h3>

                        <div class="tenant-user-view-card-subtitle">
                            Workspace assignment
                        </div>
                    </div>
                </div>

                <div class="tenant-user-view-card-body">
                    <div class="tenant-user-view-tenant">
                        <span class="tenant-user-view-tenant-logo">
                            <?php if (
                                !empty($user['tenant_logo'])
                            ): ?>
                                <img
                                    src="../<?= tenantUserViewEscape(
                                        ltrim(
                                            $user['tenant_logo'],
                                            '/'
                                        )
                                    ); ?>"
                                    alt=""
                                >
                            <?php else: ?>
                                <?= tenantUserViewEscape(
                                    strtoupper(
                                        substr(
                                            $user['tenant_name'],
                                            0,
                                            2
                                        )
                                    )
                                ); ?>
                            <?php endif; ?>
                        </span>

                        <span>
                            <span class="tenant-user-view-tenant-name">
                                <?= tenantUserViewEscape(
                                    $user['tenant_name']
                                ); ?>
                            </span>

                            <span class="tenant-user-view-tenant-code">
                                <?= tenantUserViewEscape(
                                    !empty(
                                        $user['tenant_code']
                                    )
                                        ? $user['tenant_code']
                                        : 'Tenant ID ' .
                                            (int)
                                            $user['tenant_id']
                                ); ?>
                            </span>
                        </span>
                    </div>

                    <div
                        class="tenant-user-view-details"
                        style="margin-top:12px;"
                    >
                        <div class="tenant-user-view-detail full">
                            <span class="tenant-user-view-detail-label">
                                Tenant Status
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    tenantUserViewLabel(
                                        $user['tenant_status']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail full">
                            <span class="tenant-user-view-detail-label">
                                Tenant Email
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    !empty(
                                        $user['tenant_email']
                                    )
                                        ? $user['tenant_email']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-user-view-detail full">
                            <span class="tenant-user-view-detail-label">
                                Tenant Phone
                            </span>

                            <span class="tenant-user-view-detail-value">
                                <?= tenantUserViewEscape(
                                    !empty(
                                        $user['tenant_phone']
                                    )
                                        ? $user['tenant_phone']
                                        : '—'
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="tenant-user-view-card">
                <div class="tenant-user-view-card-header">
                    <span class="tenant-user-view-card-icon">
                        <i class="bi bi-lightning-charge"></i>
                    </span>

                    <div>
                        <h3 class="tenant-user-view-card-title">
                            Quick Actions
                        </h3>

                        <div class="tenant-user-view-card-subtitle">
                            Related tenant user actions
                        </div>
                    </div>
                </div>

                <div class="tenant-user-view-card-body">
                    <div class="tenant-user-view-quick-links">

                        <?php if (
                            function_exists(
                                'canManagePlatformTenants'
                            ) &&
                            canManagePlatformTenants()
                        ): ?>
                            <a
                                href="tenant-user-edit.php?id=<?= (int) $user['user_id']; ?>"
                                class="tenant-user-view-quick-link"
                            >
                                <i class="bi bi-pencil-square"></i>
                                Edit Tenant User
                            </a>
                        <?php endif; ?>

                        <a
                            href="tenant-view.php?id=<?= (int) $user['tenant_id']; ?>"
                            class="tenant-user-view-quick-link"
                        >
                            <i class="bi bi-building"></i>
                            View Tenant
                        </a>

                        <a
                            href="roles.php?tenant_id=<?= (int) $user['tenant_id']; ?>"
                            class="tenant-user-view-quick-link"
                        >
                            <i class="bi bi-shield-check"></i>
                            Manage Tenant Roles
                        </a>

                        <a
                            href="tenant-users.php?tenant_id=<?= (int) $user['tenant_id']; ?>"
                            class="tenant-user-view-quick-link"
                        >
                            <i class="bi bi-people"></i>
                            View Tenant Users
                        </a>

                    </div>
                </div>
            </section>

        </aside>

    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
