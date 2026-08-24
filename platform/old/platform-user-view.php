<?php
/**
 * FieldPlx Platform - View Platform User
 *
 * File:
 * platform/platform-user-view.php
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

$pageTitle = 'Platform User Details - FieldPlx';
$activePage = 'platform-users';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('platformUserViewEscape')) {
    function platformUserViewEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('platformUserViewTableExists')) {
    function platformUserViewTableExists(
        mysqli $conn,
        $tableName
    ) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        $stmt->bind_param('s', $tableName);
        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        return !empty($row['total']);
    }
}

if (!function_exists('platformUserViewLabel')) {
    function platformUserViewLabel($value)
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

if (!function_exists('platformUserViewStatusClass')) {
    function platformUserViewStatusClass($status)
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

if (!function_exists('platformUserViewRoleClass')) {
    function platformUserViewRoleClass($role)
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

if (!function_exists('platformUserViewDate')) {
    function platformUserViewDate(
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

if (!function_exists('platformUserViewInitials')) {
    function platformUserViewInitials(
        $firstName,
        $lastName
    ) {
        $initials = '';

        $firstName = trim((string) $firstName);
        $lastName = trim((string) $lastName);

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

/*
|--------------------------------------------------------------------------
| Verify table
|--------------------------------------------------------------------------
*/

if (
    !platformUserViewTableExists(
        $conn,
        'platform_users'
    )
) {
    http_response_code(500);
    exit('The platform_users table does not exist.');
}

/*
|--------------------------------------------------------------------------
| Current authenticated platform user
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

$currentRole = isset($_SESSION['platform_role'])
    ? (string) $_SESSION['platform_role']
    : (
        isset($_SESSION['platform_user_role'])
            ? (string) $_SESSION['platform_user_role']
            : ''
    );

/*
|--------------------------------------------------------------------------
| Load target user
|--------------------------------------------------------------------------
*/

$userId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($userId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid platform user.';

    header('Location: platform-users.php');
    exit;
}

$userStmt = $conn->prepare("
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
        `updated_at`,
        `deleted_at`
    FROM platform_users
    WHERE `id` = ?
      AND `deleted_at` IS NULL
    LIMIT 1
");

$userStmt->bind_param('i', $userId);
$userStmt->execute();

$user = $userStmt
    ->get_result()
    ->fetch_assoc();

$userStmt->close();

if (!$user) {
    $_SESSION['platform_error_message'] =
        'Platform user not found.';

    header('Location: platform-users.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Permission to edit
|--------------------------------------------------------------------------
*/

$canEdit = false;

if (
    $currentRole === 'super_admin'
) {
    $canEdit = true;
} elseif (
    $currentRole === 'platform_admin' &&
    $user['role_code'] !== 'super_admin'
) {
    $canEdit = true;
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

$fullName = trim(
    (string) $user['first_name'] .
    ' ' .
    (string) $user['last_name']
);

$isCurrentUser =
    $currentPlatformUserId > 0 &&
    $currentPlatformUserId ===
    (int) $user['id'];

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .platform-user-view-page {
        max-width: 1050px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .platform-user-view-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .platform-user-view-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .platform-user-view-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .platform-user-view-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .platform-user-view-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .platform-user-view-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .platform-user-view-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .platform-user-view-button {
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

    .platform-user-view-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .platform-user-view-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .platform-user-view-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .platform-user-view-hero {
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 17px;
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

    .platform-user-view-avatar {
        width: 84px;
        height: 84px;
        flex: 0 0 84px;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 20px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 26px;
        font-weight: 800;
        box-shadow:
            0 8px 22px rgba(91, 33, 182, 0.18);
    }

    .platform-user-view-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .platform-user-view-hero-content {
        min-width: 0;
        flex: 1;
    }

    .platform-user-view-name {
        margin: 0;
        color: #111827;
        font-size: 21px;
        font-weight: 800;
    }

    .platform-user-view-job {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .platform-user-view-badges {
        margin-top: 9px;
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
    }

    .platform-user-view-role,
    .platform-user-view-status,
    .platform-user-view-self {
        padding: 5px 8px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .platform-user-view-role.super {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .platform-user-view-role.admin {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .platform-user-view-role.support {
        background: #ecfeff;
        color: #0e7490;
    }

    .platform-user-view-role.billing {
        background: #fff7ed;
        color: #b45309;
    }

    .platform-user-view-role.readonly {
        background: #f3f4f6;
        color: #4b5563;
    }

    .platform-user-view-role.default {
        background: #f3f4f6;
        color: #4b5563;
    }

    .platform-user-view-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .platform-user-view-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .platform-user-view-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .platform-user-view-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .platform-user-view-self {
        background: #ede9fe;
        color: #6d28d9;
    }

    .platform-user-view-grid {
        display: grid;
        grid-template-columns:
            minmax(0, 1.3fr)
            minmax(280px, 0.8fr);
        gap: 15px;
        align-items: start;
    }

    .platform-user-view-main,
    .platform-user-view-side {
        display: grid;
        gap: 15px;
    }

    .platform-user-view-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .platform-user-view-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .platform-user-view-card-icon {
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

    .platform-user-view-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .platform-user-view-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .platform-user-view-card-body {
        padding: 15px;
    }

    .platform-user-view-details {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 11px;
    }

    .platform-user-view-detail {
        padding: 11px 12px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .platform-user-view-detail-label {
        display: block;
        color: #9ca3af;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.35px;
        text-transform: uppercase;
    }

    .platform-user-view-detail-value {
        margin-top: 4px;
        display: block;
        color: #374151;
        font-size: 10px;
        font-weight: 700;
        word-break: break-word;
    }

    .platform-user-view-security {
        padding: 12px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        border: 1px solid #dbeafe;
        border-radius: 10px;
        background: #eff6ff;
        color: #1e40af;
        font-size: 9px;
        line-height: 1.55;
    }

    .platform-user-view-quick-links {
        display: grid;
        gap: 8px;
    }

    .platform-user-view-quick-link {
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

    .platform-user-view-quick-link:hover {
        border-color: #c4b5fd;
        background: #faf8ff;
        color: #7c3aed;
    }

    @media (max-width: 900px) {
        .platform-user-view-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 650px) {
        .platform-user-view-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .platform-user-view-actions {
            width: 100%;
        }

        .platform-user-view-button {
            flex: 1;
        }

        .platform-user-view-hero {
            align-items: flex-start;
            flex-direction: column;
        }

        .platform-user-view-details {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="platform-user-view-page">

    <?php if ($successMessage !== ''): ?>
        <div class="platform-user-view-alert success">
            <i class="bi bi-check-circle"></i>

            <span>
                <?= platformUserViewEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="platform-user-view-alert danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= platformUserViewEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="platform-user-view-header">
        <div>
            <h2 class="platform-user-view-title">
                Platform User Details
            </h2>

            <div class="platform-user-view-description">
                Review account information, access role, and login activity.
            </div>
        </div>

        <div class="platform-user-view-actions">
            <a
                href="platform-users.php"
                class="platform-user-view-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Users
            </a>

            <?php if ($canEdit): ?>
                <a
                    href="platform-user-edit.php?id=<?= (int) $userId; ?>"
                    class="platform-user-view-button primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit User
                </a>
            <?php endif; ?>
        </div>
    </div>

    <section class="platform-user-view-hero">
        <div class="platform-user-view-avatar">
            <?php if (!empty($user['avatar_path'])): ?>
                <img
                    src="../<?= platformUserViewEscape(
                        ltrim(
                            $user['avatar_path'],
                            '/'
                        )
                    ); ?>"
                    alt=""
                >
            <?php else: ?>
                <?= platformUserViewEscape(
                    platformUserViewInitials(
                        $user['first_name'],
                        $user['last_name']
                    )
                ); ?>
            <?php endif; ?>
        </div>

        <div class="platform-user-view-hero-content">
            <h1 class="platform-user-view-name">
                <?= platformUserViewEscape(
                    $fullName !== ''
                        ? $fullName
                        : 'Platform User'
                ); ?>
            </h1>

            <div class="platform-user-view-job">
                <?= platformUserViewEscape(
                    !empty($user['job_title'])
                        ? $user['job_title']
                        : 'No job title assigned'
                ); ?>
            </div>

            <div class="platform-user-view-badges">
                <span
                    class="platform-user-view-role <?= platformUserViewEscape(
                        platformUserViewRoleClass(
                            $user['role_code']
                        )
                    ); ?>"
                >
                    <i class="bi bi-shield-check"></i>

                    <?= platformUserViewEscape(
                        platformUserViewLabel(
                            $user['role_code']
                        )
                    ); ?>
                </span>

                <span
                    class="platform-user-view-status <?= platformUserViewEscape(
                        platformUserViewStatusClass(
                            $user['status']
                        )
                    ); ?>"
                >
                    <i class="bi bi-circle-fill"></i>

                    <?= platformUserViewEscape(
                        platformUserViewLabel(
                            $user['status']
                        )
                    ); ?>
                </span>

                <?php if ($isCurrentUser): ?>
                    <span class="platform-user-view-self">
                        <i class="bi bi-person-check"></i>
                        Your Account
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="platform-user-view-grid">

        <div class="platform-user-view-main">

            <section class="platform-user-view-card">
                <div class="platform-user-view-card-header">
                    <span class="platform-user-view-card-icon">
                        <i class="bi bi-person-lines-fill"></i>
                    </span>

                    <div>
                        <h3 class="platform-user-view-card-title">
                            Contact Information
                        </h3>

                        <div class="platform-user-view-card-subtitle">
                            Personal and communication details
                        </div>
                    </div>
                </div>

                <div class="platform-user-view-card-body">
                    <div class="platform-user-view-details">
                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                First Name
                            </span>

                            <span class="platform-user-view-detail-value">
                                <?= platformUserViewEscape(
                                    $user['first_name']
                                ); ?>
                            </span>
                        </div>

                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                Last Name
                            </span>

                            <span class="platform-user-view-detail-value">
                                <?= platformUserViewEscape(
                                    !empty($user['last_name'])
                                        ? $user['last_name']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                Email Address
                            </span>

                            <span class="platform-user-view-detail-value">
                                <?= platformUserViewEscape(
                                    $user['email']
                                ); ?>
                            </span>
                        </div>

                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                Phone Number
                            </span>

                            <span class="platform-user-view-detail-value">
                                <?= platformUserViewEscape(
                                    !empty($user['phone'])
                                        ? $user['phone']
                                        : '—'
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="platform-user-view-card">
                <div class="platform-user-view-card-header">
                    <span class="platform-user-view-card-icon">
                        <i class="bi bi-shield-lock"></i>
                    </span>

                    <div>
                        <h3 class="platform-user-view-card-title">
                            Access & Security
                        </h3>

                        <div class="platform-user-view-card-subtitle">
                            Role, status, and account security
                        </div>
                    </div>
                </div>

                <div class="platform-user-view-card-body">
                    <div class="platform-user-view-details">
                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                Platform Role
                            </span>

                            <span class="platform-user-view-detail-value">
                                <?= platformUserViewEscape(
                                    platformUserViewLabel(
                                        $user['role_code']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                Account Status
                            </span>

                            <span class="platform-user-view-detail-value">
                                <?= platformUserViewEscape(
                                    platformUserViewLabel(
                                        $user['status']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                Password
                            </span>

                            <span class="platform-user-view-detail-value">
                                Secured
                            </span>
                        </div>

                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                User ID
                            </span>

                            <span class="platform-user-view-detail-value">
                                #<?= (int) $user['id']; ?>
                            </span>
                        </div>
                    </div>

                    <div
                        class="platform-user-view-security"
                        style="margin-top:11px;"
                    >
                        <i class="bi bi-info-circle"></i>

                        <span>
                            Password values are never displayed. Administrators can set a new password from the edit page when required.
                        </span>
                    </div>
                </div>
            </section>

        </div>

        <aside class="platform-user-view-side">

            <section class="platform-user-view-card">
                <div class="platform-user-view-card-header">
                    <span class="platform-user-view-card-icon">
                        <i class="bi bi-clock-history"></i>
                    </span>

                    <div>
                        <h3 class="platform-user-view-card-title">
                            Login Activity
                        </h3>

                        <div class="platform-user-view-card-subtitle">
                            Account access timestamps
                        </div>
                    </div>
                </div>

                <div class="platform-user-view-card-body">
                    <div class="platform-user-view-details">
                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                Last Login
                            </span>

                            <span class="platform-user-view-detail-value">
                                <?= platformUserViewEscape(
                                    platformUserViewDate(
                                        $user['last_login_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                Created
                            </span>

                            <span class="platform-user-view-detail-value">
                                <?= platformUserViewEscape(
                                    platformUserViewDate(
                                        $user['created_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="platform-user-view-detail">
                            <span class="platform-user-view-detail-label">
                                Last Updated
                            </span>

                            <span class="platform-user-view-detail-value">
                                <?= platformUserViewEscape(
                                    platformUserViewDate(
                                        $user['updated_at']
                                    )
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="platform-user-view-card">
                <div class="platform-user-view-card-header">
                    <span class="platform-user-view-card-icon">
                        <i class="bi bi-lightning-charge"></i>
                    </span>

                    <div>
                        <h3 class="platform-user-view-card-title">
                            Quick Actions
                        </h3>

                        <div class="platform-user-view-card-subtitle">
                            Related account actions
                        </div>
                    </div>
                </div>

                <div class="platform-user-view-card-body">
                    <div class="platform-user-view-quick-links">

                        <?php if ($canEdit): ?>
                            <a
                                href="platform-user-edit.php?id=<?= (int) $userId; ?>"
                                class="platform-user-view-quick-link"
                            >
                                <i class="bi bi-pencil-square"></i>
                                Edit Platform User
                            </a>
                        <?php endif; ?>

                        <a
                            href="mailto:<?= platformUserViewEscape(
                                $user['email']
                            ); ?>"
                            class="platform-user-view-quick-link"
                        >
                            <i class="bi bi-envelope"></i>
                            Send Email
                        </a>

                        <?php if (!empty($user['phone'])): ?>
                            <a
                                href="tel:<?= platformUserViewEscape(
                                    preg_replace(
                                        '/[^0-9+]/',
                                        '',
                                        $user['phone']
                                    )
                                ); ?>"
                                class="platform-user-view-quick-link"
                            >
                                <i class="bi bi-telephone"></i>
                                Call User
                            </a>
                        <?php endif; ?>

                        <a
                            href="platform-users.php"
                            class="platform-user-view-quick-link"
                        >
                            <i class="bi bi-people"></i>
                            View All Platform Users
                        </a>

                    </div>
                </div>
            </section>

        </aside>

    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
