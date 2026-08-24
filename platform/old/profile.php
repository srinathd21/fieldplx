<?php
/**
 * FieldPlx Platform - My Profile
 *
 * File:
 * platform/profile.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - platform_users authentication
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
));

$pageTitle = 'My Profile - FieldPlx';
$activePage = 'profile';
$basePath = '';

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('profileEscape')) {
    function profileEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('profilePost')) {
    function profilePost(
        $key,
        $default = ''
    ) {
        if (
            !isset($_POST[$key]) ||
            is_array($_POST[$key])
        ) {
            return $default;
        }

        return trim((string) $_POST[$key]);
    }
}

if (!function_exists('profileDeleteAvatar')) {
    function profileDeleteAvatar($relativePath)
    {
        $relativePath = trim((string) $relativePath);

        if ($relativePath === '') {
            return;
        }

        $normalised = str_replace(
            '\\',
            '/',
            $relativePath
        );

        if (
            strpos(
                $normalised,
                'uploads/platform-users/avatars/'
            ) !== 0
        ) {
            return;
        }

        $absolutePath =
            dirname(__DIR__) .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $normalised
            );

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}

if (!function_exists('profileUploadAvatar')) {
    function profileUploadAvatar(
        $file,
        &$errorMessage
    ) {
        if (
            !is_array($file) ||
            empty($file['name']) ||
            !isset($file['error'])
        ) {
            return null;
        }

        if (
            (int) $file['error'] ===
            UPLOAD_ERR_NO_FILE
        ) {
            return null;
        }

        if (
            (int) $file['error'] !==
            UPLOAD_ERR_OK
        ) {
            $errorMessage =
                'Avatar upload failed.';
            return false;
        }

        if (
            empty($file['tmp_name']) ||
            !is_uploaded_file(
                $file['tmp_name']
            )
        ) {
            $errorMessage =
                'Invalid avatar upload.';
            return false;
        }

        if (
            !empty($file['size']) &&
            (int) $file['size'] >
            3 * 1024 * 1024
        ) {
            $errorMessage =
                'Avatar size must not exceed 3 MB.';
            return false;
        }

        $mimeType = '';

        if (class_exists('finfo')) {
            $finfo = new finfo(
                FILEINFO_MIME_TYPE
            );

            $mimeType = $finfo->file(
                $file['tmp_name']
            );
        } elseif (
            function_exists('mime_content_type')
        ) {
            $mimeType = mime_content_type(
                $file['tmp_name']
            );
        }

        $allowedTypes = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        );

        if (!isset($allowedTypes[$mimeType])) {
            $errorMessage =
                'Avatar must be JPG, PNG, or WEBP.';
            return false;
        }

        $relativeDirectory =
            'uploads/platform-users/avatars';

        $absoluteDirectory =
            dirname(__DIR__) .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relativeDirectory
            );

        if (
            !is_dir($absoluteDirectory) &&
            !mkdir(
                $absoluteDirectory,
                0775,
                true
            ) &&
            !is_dir($absoluteDirectory)
        ) {
            $errorMessage =
                'Unable to create avatar directory.';
            return false;
        }

        $fileName =
            'platform-user-' .
            date('YmdHis') .
            '-' .
            bin2hex(random_bytes(4)) .
            '.' .
            $allowedTypes[$mimeType];

        $absolutePath =
            $absoluteDirectory .
            DIRECTORY_SEPARATOR .
            $fileName;

        if (
            !move_uploaded_file(
                $file['tmp_name'],
                $absolutePath
            )
        ) {
            $errorMessage =
                'Unable to save the avatar.';
            return false;
        }

        return $relativeDirectory .
            '/' .
            $fileName;
    }
}

if (!function_exists('profileInitials')) {
    function profileInitials(
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

if (!function_exists('profileLabel')) {
    function profileLabel($value)
    {
        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                (string) $value
            )
        );
    }
}

if (!function_exists('profileDate')) {
    function profileDate($value)
    {
        if (empty($value)) {
            return 'Never';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return 'Never';
        }

        return date(
            'd M Y, h:i A',
            $timestamp
        );
    }
}

/*
|--------------------------------------------------------------------------
| Resolve logged-in platform user
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

if ($currentPlatformUserId <= 0) {
    header('Location: login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load user
|--------------------------------------------------------------------------
*/

$userStmt = $conn->prepare("
    SELECT
        `id`,
        `first_name`,
        `last_name`,
        `email`,
        `phone`,
        `password_hash`,
        `avatar_path`,
        `job_title`,
        `role_code`,
        `status`,
        `last_login_at`,
        `created_at`,
        `updated_at`
    FROM platform_users
    WHERE `id` = ?
      AND `deleted_at` IS NULL
    LIMIT 1
");

$userStmt->bind_param(
    'i',
    $currentPlatformUserId
);

$userStmt->execute();

$user = $userStmt
    ->get_result()
    ->fetch_assoc();

$userStmt->close();

if (!$user) {
    session_destroy();

    header('Location: login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';

$firstName = isset($_POST['first_name'])
    ? profilePost('first_name')
    : (string) $user['first_name'];

$lastName = isset($_POST['last_name'])
    ? profilePost('last_name')
    : (string) $user['last_name'];

$email = isset($_POST['email'])
    ? strtolower(profilePost('email'))
    : strtolower((string) $user['email']);

$phone = isset($_POST['phone'])
    ? profilePost('phone')
    : (string) $user['phone'];

$jobTitle = isset($_POST['job_title'])
    ? profilePost('job_title')
    : (string) $user['job_title'];

$removeAvatar = !empty($_POST['remove_avatar'])
    ? 1
    : 0;

/*
|--------------------------------------------------------------------------
| Process update
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $currentPassword =
        isset($_POST['current_password']) &&
        !is_array($_POST['current_password'])
            ? (string) $_POST['current_password']
            : '';

    $newPassword =
        isset($_POST['new_password']) &&
        !is_array($_POST['new_password'])
            ? (string) $_POST['new_password']
            : '';

    $confirmPassword =
        isset($_POST['confirm_password']) &&
        !is_array($_POST['confirm_password'])
            ? (string) $_POST['confirm_password']
            : '';

    if ($firstName === '') {
        $errorMessage =
            'Enter your first name.';
    } elseif (strlen($firstName) > 120) {
        $errorMessage =
            'First name must not exceed 120 characters.';
    } elseif (strlen($lastName) > 120) {
        $errorMessage =
            'Last name must not exceed 120 characters.';
    } elseif (
        $email === '' ||
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errorMessage =
            'Enter a valid email address.';
    } elseif (strlen($email) > 190) {
        $errorMessage =
            'Email must not exceed 190 characters.';
    } elseif (strlen($phone) > 50) {
        $errorMessage =
            'Phone must not exceed 50 characters.';
    } elseif (strlen($jobTitle) > 120) {
        $errorMessage =
            'Job title must not exceed 120 characters.';
    }

    if ($errorMessage === '') {
        $duplicateStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM platform_users
            WHERE LOWER(`email`) = LOWER(?)
              AND `id` <> ?
              AND `deleted_at` IS NULL
        ");

        $duplicateStmt->bind_param(
            'si',
            $email,
            $currentPlatformUserId
        );

        $duplicateStmt->execute();

        $duplicateRow = $duplicateStmt
            ->get_result()
            ->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'Another platform user already uses this email.';
        }
    }

    if (
        $errorMessage === '' &&
        (
            $currentPassword !== '' ||
            $newPassword !== '' ||
            $confirmPassword !== ''
        )
    ) {
        if ($currentPassword === '') {
            $errorMessage =
                'Enter your current password.';
        } elseif (
            !password_verify(
                $currentPassword,
                $user['password_hash']
            )
        ) {
            $errorMessage =
                'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $errorMessage =
                'New password must contain at least 8 characters.';
        } elseif (
            !preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[a-z]/', $newPassword) ||
            !preg_match('/[0-9]/', $newPassword)
        ) {
            $errorMessage =
                'New password must include uppercase, lowercase, and a number.';
        } elseif (
            $newPassword !== $confirmPassword
        ) {
            $errorMessage =
                'New password confirmation does not match.';
        }
    }

    $newAvatarPath = null;

    if ($errorMessage === '') {
        $newAvatarPath =
            profileUploadAvatar(
                isset($_FILES['avatar'])
                    ? $_FILES['avatar']
                    : null,
                $errorMessage
            );
    }

    if ($errorMessage === '') {
        try {
            $conn->begin_transaction();

            $oldAvatarPath =
                (string) $user['avatar_path'];

            $avatarPath = $oldAvatarPath;

            if ($removeAvatar === 1) {
                $avatarPath = null;
            }

            if (
                $newAvatarPath !== null &&
                $newAvatarPath !== false
            ) {
                $avatarPath = $newAvatarPath;
            }

            if ($newPassword !== '') {
                $newPasswordHash =
                    password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                if ($newPasswordHash === false) {
                    throw new RuntimeException(
                        'Unable to secure the new password.'
                    );
                }

                $updateStmt = $conn->prepare("
                    UPDATE platform_users
                    SET
                        `first_name` = ?,
                        `last_name` = ?,
                        `email` = ?,
                        `phone` = ?,
                        `job_title` = ?,
                        `avatar_path` = ?,
                        `password_hash` = ?,
                        `updated_at` = NOW()
                    WHERE `id` = ?
                    LIMIT 1
                ");

                $updateStmt->bind_param(
                    'sssssssi',
                    $firstName,
                    $lastName,
                    $email,
                    $phone,
                    $jobTitle,
                    $avatarPath,
                    $newPasswordHash,
                    $currentPlatformUserId
                );
            } else {
                $updateStmt = $conn->prepare("
                    UPDATE platform_users
                    SET
                        `first_name` = ?,
                        `last_name` = ?,
                        `email` = ?,
                        `phone` = ?,
                        `job_title` = ?,
                        `avatar_path` = ?,
                        `updated_at` = NOW()
                    WHERE `id` = ?
                    LIMIT 1
                ");

                $updateStmt->bind_param(
                    'ssssssi',
                    $firstName,
                    $lastName,
                    $email,
                    $phone,
                    $jobTitle,
                    $avatarPath,
                    $currentPlatformUserId
                );
            }

            $updateStmt->execute();
            $updateStmt->close();

            $conn->commit();

            if (
                (
                    $removeAvatar === 1 ||
                    (
                        $newAvatarPath !== null &&
                        $newAvatarPath !== false
                    )
                ) &&
                $oldAvatarPath !== ''
            ) {
                profileDeleteAvatar($oldAvatarPath);
            }

            $_SESSION['platform_user_name'] =
                trim(
                    $firstName . ' ' . $lastName
                );

            $_SESSION['platform_user_email'] =
                $email;

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Profile updated successfully.';

            header(
                'Location: profile.php',
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            $conn->rollback();

            if (
                $newAvatarPath !== null &&
                $newAvatarPath !== false
            ) {
                profileDeleteAvatar(
                    $newAvatarPath
                );
            }

            error_log(
                'Platform profile update failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to update your profile: ' .
                $exception->getMessage();
        }
    }
}

if (!empty($_SESSION['platform_success_message'])) {
    $successMessage =
        (string) $_SESSION['platform_success_message'];

    unset($_SESSION['platform_success_message']);
}

$avatarDisplayPath = '';

if (
    $removeAvatar !== 1 &&
    !empty($user['avatar_path'])
) {
    $avatarDisplayPath =
        (string) $user['avatar_path'];
}

$fullName = trim(
    $firstName . ' ' . $lastName
);

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .profile-page {
        max-width: 1020px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .profile-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .profile-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .profile-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .profile-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .profile-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .profile-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .profile-hero {
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

    .profile-avatar {
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
        font-size: 25px;
        font-weight: 800;
    }

    .profile-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .profile-name {
        margin: 0;
        color: #111827;
        font-size: 21px;
        font-weight: 800;
    }

    .profile-job {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .profile-badges {
        margin-top: 8px;
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .profile-badge {
        padding: 5px 8px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border-radius: 999px;
        background: #ede9fe;
        color: #6d28d9;
        font-size: 8px;
        font-weight: 700;
    }

    .profile-badge.status {
        background: #ecfdf5;
        color: #047857;
    }

    .profile-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(280px, 320px);
        gap: 15px;
        align-items: start;
    }

    .profile-main,
    .profile-side {
        display: grid;
        gap: 15px;
    }

    .profile-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .profile-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .profile-card-icon {
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

    .profile-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .profile-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .profile-card-body {
        padding: 15px;
    }

    .profile-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .profile-required {
        color: #dc2626;
    }

    .profile-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .profile-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .profile-password-wrap {
        position: relative;
    }

    .profile-password-toggle {
        position: absolute;
        top: 50%;
        right: 8px;
        width: 30px;
        height: 30px;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 0;
        background: transparent;
        color: #9ca3af;
    }

    .profile-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .profile-avatar-preview {
        width: 120px;
        height: 120px;
        margin: 0 auto 12px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px dashed #c4b5fd;
        border-radius: 18px;
        background: #faf8ff;
        color: #7c3aed;
        font-size: 31px;
        font-weight: 800;
    }

    .profile-avatar-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .profile-avatar-input {
        display: none;
    }

    .profile-avatar-button {
        min-height: 37px;
        width: 100%;
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
        cursor: pointer;
    }

    .profile-remove {
        margin-top: 8px;
        padding: 9px 10px;
        display: flex;
        align-items: center;
        gap: 8px;
        border: 1px solid #fee2e2;
        border-radius: 8px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 8px;
        font-weight: 600;
    }

    .profile-meta {
        display: grid;
        gap: 9px;
    }

    .profile-meta-item {
        padding: 10px 11px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .profile-meta-label {
        display: block;
        color: #9ca3af;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .profile-meta-value {
        margin-top: 4px;
        display: block;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .profile-submit-card {
        padding: 13px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .profile-submit {
        width: 100%;
        min-height: 41px;
        border: 0;
        border-radius: 9px;
        background:
            linear-gradient(
                135deg,
                #7c3aed,
                #6d28d9
            );
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
    }

    .profile-submit:disabled {
        opacity: 0.65;
    }

    @media (max-width: 850px) {
        .profile-layout {
            grid-template-columns: 1fr;
        }

        .profile-side {
            order: -1;
        }
    }

    @media (max-width: 650px) {
        .profile-hero {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<div class="profile-page">

    <div class="profile-header">
        <div>
            <h2 class="profile-title">
                My Profile
            </h2>

            <div class="profile-description">
                Update your personal details, profile picture, and login password.
            </div>
        </div>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="profile-alert success">
            <i class="bi bi-check-circle"></i>
            <span>
                <?= profileEscape($successMessage); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="profile-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span>
                <?= profileEscape($errorMessage); ?>
            </span>
        </div>
    <?php endif; ?>

    <section class="profile-hero">
        <div class="profile-avatar">
            <?php if ($avatarDisplayPath !== ''): ?>
                <img
                    src="../<?= profileEscape(
                        ltrim(
                            $avatarDisplayPath,
                            '/'
                        )
                    ); ?>"
                    alt=""
                >
            <?php else: ?>
                <?= profileEscape(
                    profileInitials(
                        $firstName,
                        $lastName
                    )
                ); ?>
            <?php endif; ?>
        </div>

        <div>
            <h1 class="profile-name">
                <?= profileEscape(
                    $fullName !== ''
                        ? $fullName
                        : 'Platform User'
                ); ?>
            </h1>

            <div class="profile-job">
                <?= profileEscape(
                    $jobTitle !== ''
                        ? $jobTitle
                        : 'No job title assigned'
                ); ?>
            </div>

            <div class="profile-badges">
                <span class="profile-badge">
                    <i class="bi bi-shield-check"></i>
                    <?= profileEscape(
                        profileLabel(
                            $user['role_code']
                        )
                    ); ?>
                </span>

                <span class="profile-badge status">
                    <i class="bi bi-circle-fill"></i>
                    <?= profileEscape(
                        profileLabel(
                            $user['status']
                        )
                    ); ?>
                </span>
            </div>
        </div>
    </section>

    <form
        method="post"
        action="profile.php"
        enctype="multipart/form-data"
        id="profileForm"
        autocomplete="off"
    >
        <?php csrfField(); ?>

        <div class="profile-layout">

            <div class="profile-main">

                <section class="profile-card">
                    <div class="profile-card-header">
                        <span class="profile-card-icon">
                            <i class="bi bi-person-lines-fill"></i>
                        </span>

                        <div>
                            <h3 class="profile-card-title">
                                Personal Information
                            </h3>

                            <div class="profile-card-subtitle">
                                Your account and contact details
                            </div>
                        </div>
                    </div>

                    <div class="profile-card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="profile-label">
                                    First Name
                                    <span class="profile-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="first_name"
                                    class="form-control profile-control"
                                    value="<?= profileEscape(
                                        $firstName
                                    ); ?>"
                                    maxlength="120"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="profile-label">
                                    Last Name
                                </label>

                                <input
                                    type="text"
                                    name="last_name"
                                    class="form-control profile-control"
                                    value="<?= profileEscape(
                                        $lastName
                                    ); ?>"
                                    maxlength="120"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="profile-label">
                                    Email Address
                                    <span class="profile-required">*</span>
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control profile-control"
                                    value="<?= profileEscape(
                                        $email
                                    ); ?>"
                                    maxlength="190"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="profile-label">
                                    Phone Number
                                </label>

                                <input
                                    type="text"
                                    name="phone"
                                    class="form-control profile-control"
                                    value="<?= profileEscape(
                                        $phone
                                    ); ?>"
                                    maxlength="50"
                                >
                            </div>

                            <div class="col-12">
                                <label class="profile-label">
                                    Job Title
                                </label>

                                <input
                                    type="text"
                                    name="job_title"
                                    class="form-control profile-control"
                                    value="<?= profileEscape(
                                        $jobTitle
                                    ); ?>"
                                    maxlength="120"
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="profile-card">
                    <div class="profile-card-header">
                        <span class="profile-card-icon">
                            <i class="bi bi-key"></i>
                        </span>

                        <div>
                            <h3 class="profile-card-title">
                                Change Password
                            </h3>

                            <div class="profile-card-subtitle">
                                Leave all password fields blank to keep your current password
                            </div>
                        </div>
                    </div>

                    <div class="profile-card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="profile-label">
                                    Current Password
                                </label>

                                <div class="profile-password-wrap">
                                    <input
                                        type="password"
                                        name="current_password"
                                        id="currentPassword"
                                        class="form-control profile-control pe-5"
                                    >

                                    <button
                                        type="button"
                                        class="profile-password-toggle"
                                        data-password-target="currentPassword"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="profile-label">
                                    New Password
                                </label>

                                <div class="profile-password-wrap">
                                    <input
                                        type="password"
                                        name="new_password"
                                        id="newPassword"
                                        class="form-control profile-control pe-5"
                                        minlength="8"
                                    >

                                    <button
                                        type="button"
                                        class="profile-password-toggle"
                                        data-password-target="newPassword"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="profile-label">
                                    Confirm New Password
                                </label>

                                <div class="profile-password-wrap">
                                    <input
                                        type="password"
                                        name="confirm_password"
                                        id="confirmPassword"
                                        class="form-control profile-control pe-5"
                                        minlength="8"
                                    >

                                    <button
                                        type="button"
                                        class="profile-password-toggle"
                                        data-password-target="confirmPassword"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="profile-help">
                                    Use at least 8 characters with uppercase, lowercase, and a number.
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

            </div>

            <aside class="profile-side">

                <section class="profile-card">
                    <div class="profile-card-header">
                        <span class="profile-card-icon">
                            <i class="bi bi-image"></i>
                        </span>

                        <div>
                            <h3 class="profile-card-title">
                                Profile Avatar
                            </h3>

                            <div class="profile-card-subtitle">
                                Replace or remove your profile picture
                            </div>
                        </div>
                    </div>

                    <div class="profile-card-body">
                        <div
                            class="profile-avatar-preview"
                            id="avatarPreview"
                        >
                            <?php if ($avatarDisplayPath !== ''): ?>
                                <img
                                    src="../<?= profileEscape(
                                        ltrim(
                                            $avatarDisplayPath,
                                            '/'
                                        )
                                    ); ?>"
                                    alt=""
                                >
                            <?php else: ?>
                                <?= profileEscape(
                                    profileInitials(
                                        $firstName,
                                        $lastName
                                    )
                                ); ?>
                            <?php endif; ?>
                        </div>

                        <input
                            type="file"
                            name="avatar"
                            id="avatarInput"
                            class="profile-avatar-input"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >

                        <label
                            for="avatarInput"
                            class="profile-avatar-button"
                        >
                            <i class="bi bi-upload"></i>
                            Choose Avatar
                        </label>

                        <?php if (!empty($user['avatar_path'])): ?>
                            <label class="profile-remove">
                                <input
                                    type="checkbox"
                                    name="remove_avatar"
                                    id="removeAvatar"
                                    value="1"
                                >
                                Remove current avatar
                            </label>
                        <?php endif; ?>

                        <div class="profile-help text-center">
                            JPG, PNG, or WEBP. Maximum 3 MB.
                        </div>
                    </div>
                </section>

                <section class="profile-card">
                    <div class="profile-card-header">
                        <span class="profile-card-icon">
                            <i class="bi bi-clock-history"></i>
                        </span>

                        <div>
                            <h3 class="profile-card-title">
                                Account Information
                            </h3>

                            <div class="profile-card-subtitle">
                                Login and account timestamps
                            </div>
                        </div>
                    </div>

                    <div class="profile-card-body">
                        <div class="profile-meta">
                            <div class="profile-meta-item">
                                <span class="profile-meta-label">
                                    Role
                                </span>

                                <span class="profile-meta-value">
                                    <?= profileEscape(
                                        profileLabel(
                                            $user['role_code']
                                        )
                                    ); ?>
                                </span>
                            </div>

                            <div class="profile-meta-item">
                                <span class="profile-meta-label">
                                    Status
                                </span>

                                <span class="profile-meta-value">
                                    <?= profileEscape(
                                        profileLabel(
                                            $user['status']
                                        )
                                    ); ?>
                                </span>
                            </div>

                            <div class="profile-meta-item">
                                <span class="profile-meta-label">
                                    Last Login
                                </span>

                                <span class="profile-meta-value">
                                    <?= profileEscape(
                                        profileDate(
                                            $user['last_login_at']
                                        )
                                    ); ?>
                                </span>
                            </div>

                            <div class="profile-meta-item">
                                <span class="profile-meta-label">
                                    Account Created
                                </span>

                                <span class="profile-meta-value">
                                    <?= profileEscape(
                                        profileDate(
                                            $user['created_at']
                                        )
                                    ); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="profile-submit-card">
                    <button
                        type="submit"
                        class="profile-submit"
                        id="profileSubmit"
                    >
                        <i class="bi bi-check2-circle me-1"></i>
                        Save Profile
                    </button>
                </div>

            </aside>

        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const avatarInput =
        document.getElementById(
            'avatarInput'
        );

    const avatarPreview =
        document.getElementById(
            'avatarPreview'
        );

    const removeAvatar =
        document.getElementById(
            'removeAvatar'
        );

    if (
        avatarInput &&
        avatarPreview
    ) {
        avatarInput.addEventListener(
            'change',
            function () {
                const file =
                    avatarInput.files[0];

                if (!file) {
                    return;
                }

                if (removeAvatar) {
                    removeAvatar.checked = false;
                }

                const reader =
                    new FileReader();

                reader.onload =
                    function (event) {
                        avatarPreview.innerHTML = '';

                        const image =
                            document.createElement(
                                'img'
                            );

                        image.src =
                            event.target.result;
                        image.alt = '';

                        avatarPreview.appendChild(
                            image
                        );
                    };

                reader.readAsDataURL(file);
            }
        );
    }

    if (
        removeAvatar &&
        avatarPreview
    ) {
        removeAvatar.addEventListener(
            'change',
            function () {
                if (removeAvatar.checked) {
                    if (avatarInput) {
                        avatarInput.value = '';
                    }

                    avatarPreview.innerHTML =
                        '<?= profileEscape(
                            profileInitials(
                                $firstName,
                                $lastName
                            )
                        ); ?>';
                }
            }
        );
    }

    const passwordButtons =
        document.querySelectorAll(
            '[data-password-target]'
        );

    passwordButtons.forEach(
        function (button) {
            button.addEventListener(
                'click',
                function () {
                    const targetId =
                        button.getAttribute(
                            'data-password-target'
                        );

                    const input =
                        document.getElementById(
                            targetId
                        );

                    if (!input) {
                        return;
                    }

                    input.type =
                        input.type === 'password'
                            ? 'text'
                            : 'password';

                    const icon =
                        button.querySelector('i');

                    if (icon) {
                        icon.className =
                            input.type === 'password'
                                ? 'bi bi-eye'
                                : 'bi bi-eye-slash';
                    }
                }
            );
        }
    );

    const form =
        document.getElementById(
            'profileForm'
        );

    const submitButton =
        document.getElementById(
            'profileSubmit'
        );

    if (
        form &&
        submitButton
    ) {
        form.addEventListener(
            'submit',
            function (event) {
                const newPassword =
                    document.getElementById(
                        'newPassword'
                    );

                const confirmPassword =
                    document.getElementById(
                        'confirmPassword'
                    );

                if (
                    newPassword &&
                    confirmPassword &&
                    newPassword.value !==
                    confirmPassword.value
                ) {
                    event.preventDefault();

                    confirmPassword.setCustomValidity(
                        'Passwords do not match.'
                    );

                    confirmPassword.reportValidity();

                    confirmPassword.setCustomValidity(
                        ''
                    );

                    return;
                }

                submitButton.disabled = true;
                submitButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
