<?php
/**
 * FieldPlx Platform - Edit Platform User
 *
 * File:
 * platform/platform-user-edit.php
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
    'platform_admin'
));

$pageTitle = 'Edit Platform User - FieldPlx';
$activePage = 'platform-users';
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

if (!function_exists('platformUserEditEscape')) {
    function platformUserEditEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('platformUserEditPost')) {
    function platformUserEditPost(
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

if (!function_exists('platformUserEditTableExists')) {
    function platformUserEditTableExists(
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

if (!function_exists('platformUserEditDeleteAvatar')) {
    function platformUserEditDeleteAvatar(
        $relativePath
    ) {
        $relativePath = trim((string) $relativePath);

        if ($relativePath === '') {
            return;
        }

        $normalised = str_replace('\\', '/', $relativePath);

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

if (!function_exists('platformUserEditUploadAvatar')) {
    function platformUserEditUploadAvatar(
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

        $maxSize = 3 * 1024 * 1024;

        if (
            !empty($file['size']) &&
            (int) $file['size'] > $maxSize
        ) {
            $errorMessage =
                'Avatar size must not exceed 3 MB.';
            return false;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        $mimeType = $finfo->file(
            $file['tmp_name']
        );

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

        $uploadRelativeDirectory =
            'uploads/platform-users/avatars';

        $uploadAbsoluteDirectory =
            dirname(__DIR__) .
            DIRECTORY_SEPARATOR .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $uploadRelativeDirectory
            );

        if (
            !is_dir($uploadAbsoluteDirectory) &&
            !mkdir(
                $uploadAbsoluteDirectory,
                0775,
                true
            ) &&
            !is_dir($uploadAbsoluteDirectory)
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
            $uploadAbsoluteDirectory .
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

        return $uploadRelativeDirectory .
            '/' .
            $fileName;
    }
}

/*
|--------------------------------------------------------------------------
| Verify table
|--------------------------------------------------------------------------
*/

if (
    !platformUserEditTableExists(
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
    : (
        isset($_POST['user_id'])
            ? (int) $_POST['user_id']
            : 0
    );

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

$userStmt->bind_param('i', $userId);
$userStmt->execute();

$currentUser = $userStmt
    ->get_result()
    ->fetch_assoc();

$userStmt->close();

if (!$currentUser) {
    $_SESSION['platform_error_message'] =
        'Platform user not found.';

    header('Location: platform-users.php');
    exit;
}

if (
    $currentRole === 'platform_admin' &&
    $currentUser['role_code'] === 'super_admin'
) {
    http_response_code(403);
    exit('Platform administrators cannot edit super administrators.');
}

/*
|--------------------------------------------------------------------------
| Role rules
|--------------------------------------------------------------------------
*/

$allowedRoles = array(
    'super_admin' => 'Super Admin',
    'platform_admin' => 'Platform Admin',
    'support_admin' => 'Support Admin',
    'billing_admin' => 'Billing Admin',
    'platform_read_only' => 'Platform Read Only'
);

if ($currentRole === 'platform_admin') {
    unset($allowedRoles['super_admin']);
}

$allowedStatuses = array(
    'active' => 'Active',
    'inactive' => 'Inactive',
    'suspended' => 'Suspended'
);

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$firstName = isset($_POST['first_name'])
    ? platformUserEditPost('first_name')
    : (string) $currentUser['first_name'];

$lastName = isset($_POST['last_name'])
    ? platformUserEditPost('last_name')
    : (string) $currentUser['last_name'];

$email = isset($_POST['email'])
    ? strtolower(
        platformUserEditPost('email')
    )
    : strtolower(
        (string) $currentUser['email']
    );

$phone = isset($_POST['phone'])
    ? platformUserEditPost('phone')
    : (string) $currentUser['phone'];

$jobTitle = isset($_POST['job_title'])
    ? platformUserEditPost('job_title')
    : (string) $currentUser['job_title'];

$roleCode = isset($_POST['role_code'])
    ? platformUserEditPost('role_code')
    : (string) $currentUser['role_code'];

$status = isset($_POST['status'])
    ? platformUserEditPost('status')
    : (string) $currentUser['status'];

$removeAvatar = !empty($_POST['remove_avatar'])
    ? 1
    : 0;

if (!isset($allowedRoles[$roleCode])) {
    if (
        $currentRole === 'platform_admin' &&
        $currentUser['role_code'] === 'super_admin'
    ) {
        $roleCode = 'super_admin';
    } else {
        $roleCode = 'platform_read_only';
    }
}

if (!isset($allowedStatuses[$status])) {
    $status = 'active';
}

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

    $password = isset($_POST['password']) &&
        !is_array($_POST['password'])
            ? (string) $_POST['password']
            : '';

    $confirmPassword =
        isset($_POST['confirm_password']) &&
        !is_array($_POST['confirm_password'])
            ? (string) $_POST['confirm_password']
            : '';

    if ($firstName === '') {
        $errorMessage =
            'Enter the first name.';
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
    } elseif (!isset($allowedRoles[$roleCode])) {
        $errorMessage =
            'Select a valid platform role.';
    } elseif (!isset($allowedStatuses[$status])) {
        $errorMessage =
            'Select a valid status.';
    }

    if (
        $errorMessage === '' &&
        $password !== ''
    ) {
        if (strlen($password) < 8) {
            $errorMessage =
                'Password must contain at least 8 characters.';
        } elseif (
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password)
        ) {
            $errorMessage =
                'Password must include uppercase, lowercase, and a number.';
        } elseif ($password !== $confirmPassword) {
            $errorMessage =
                'Password confirmation does not match.';
        }
    }

    if (
        $errorMessage === '' &&
        $currentPlatformUserId === $userId &&
        $status !== 'active'
    ) {
        $errorMessage =
            'You cannot deactivate or suspend your own account.';
    }

    if (
        $errorMessage === '' &&
        $currentPlatformUserId === $userId &&
        $roleCode !== $currentUser['role_code']
    ) {
        $errorMessage =
            'You cannot change your own platform role.';
    }

    if (
        $errorMessage === '' &&
        $currentUser['role_code'] === 'super_admin' &&
        (
            $roleCode !== 'super_admin' ||
            $status !== 'active'
        )
    ) {
        $superAdminStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM platform_users
            WHERE `role_code` = 'super_admin'
              AND `status` = 'active'
              AND `deleted_at` IS NULL
        ");

        $superAdminStmt->execute();

        $superAdminRow = $superAdminStmt
            ->get_result()
            ->fetch_assoc();

        $superAdminStmt->close();

        if (
            isset($superAdminRow['total']) &&
            (int) $superAdminRow['total'] <= 1
        ) {
            $errorMessage =
                'At least one active super administrator is required.';
        }
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
            $userId
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

    $newAvatarPath = null;

    if ($errorMessage === '') {
        $newAvatarPath =
            platformUserEditUploadAvatar(
                isset($_FILES['avatar'])
                    ? $_FILES['avatar']
                    : null,
                $errorMessage
            );
    }

    if ($errorMessage === '') {
        try {
            $conn->begin_transaction();

            $avatarPath =
                (string) $currentUser['avatar_path'];

            if ($removeAvatar === 1) {
                $avatarPath = null;
            }

            if (
                $newAvatarPath !== null &&
                $newAvatarPath !== false
            ) {
                $avatarPath = $newAvatarPath;
            }

            if ($password !== '') {
                $passwordHash =
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                if ($passwordHash === false) {
                    throw new RuntimeException(
                        'Unable to secure the password.'
                    );
                }

                $updateStmt = $conn->prepare("
                    UPDATE platform_users
                    SET
                        `first_name` = ?,
                        `last_name` = ?,
                        `email` = ?,
                        `phone` = ?,
                        `password_hash` = ?,
                        `avatar_path` = ?,
                        `job_title` = ?,
                        `role_code` = ?,
                        `status` = ?,
                        `updated_at` = NOW()
                    WHERE `id` = ?
                    LIMIT 1
                ");

                $updateStmt->bind_param(
                    'sssssssssi',
                    $firstName,
                    $lastName,
                    $email,
                    $phone,
                    $passwordHash,
                    $avatarPath,
                    $jobTitle,
                    $roleCode,
                    $status,
                    $userId
                );
            } else {
                $updateStmt = $conn->prepare("
                    UPDATE platform_users
                    SET
                        `first_name` = ?,
                        `last_name` = ?,
                        `email` = ?,
                        `phone` = ?,
                        `avatar_path` = ?,
                        `job_title` = ?,
                        `role_code` = ?,
                        `status` = ?,
                        `updated_at` = NOW()
                    WHERE `id` = ?
                    LIMIT 1
                ");

                $updateStmt->bind_param(
                    'ssssssssi',
                    $firstName,
                    $lastName,
                    $email,
                    $phone,
                    $avatarPath,
                    $jobTitle,
                    $roleCode,
                    $status,
                    $userId
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
                !empty($currentUser['avatar_path'])
            ) {
                platformUserEditDeleteAvatar(
                    $currentUser['avatar_path']
                );
            }

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Platform user updated successfully.';

            header(
                'Location: platform-user-view.php?id=' .
                $userId,
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
                platformUserEditDeleteAvatar(
                    $newAvatarPath
                );
            }

            error_log(
                'Platform user update failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to update the platform user: ' .
                $exception->getMessage();
        }
    }
}

$avatarDisplayPath = '';

if (
    $removeAvatar !== 1 &&
    !empty($currentUser['avatar_path'])
) {
    $avatarDisplayPath =
        (string) $currentUser['avatar_path'];
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .platform-user-edit-page {
        max-width: 1000px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .platform-user-edit-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .platform-user-edit-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .platform-user-edit-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .platform-user-edit-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .platform-user-edit-button {
        min-height: 36px;
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

    .platform-user-edit-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .platform-user-edit-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid #fecaca;
        border-radius: 10px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 10px;
        line-height: 1.55;
    }

    .platform-user-edit-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(280px, 320px);
        gap: 15px;
        align-items: start;
    }

    .platform-user-edit-main,
    .platform-user-edit-side {
        display: grid;
        gap: 15px;
    }

    .platform-user-edit-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .platform-user-edit-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .platform-user-edit-card-icon {
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

    .platform-user-edit-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .platform-user-edit-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .platform-user-edit-card-body {
        padding: 15px;
    }

    .platform-user-edit-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .platform-user-edit-required {
        color: #dc2626;
    }

    .platform-user-edit-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .platform-user-edit-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .platform-user-edit-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .platform-user-edit-password-wrap {
        position: relative;
    }

    .platform-user-edit-password-toggle {
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

    .platform-user-edit-avatar {
        width: 118px;
        height: 118px;
        margin: 0 auto 12px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px dashed #c4b5fd;
        border-radius: 18px;
        background:
            linear-gradient(
                135deg,
                #faf8ff,
                #f3e8ff
            );
        color: #7c3aed;
        font-size: 31px;
    }

    .platform-user-edit-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .platform-user-edit-avatar-input {
        display: none;
    }

    .platform-user-edit-avatar-button {
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

    .platform-user-edit-remove {
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

    .platform-user-edit-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .platform-user-edit-submit {
        min-height: 41px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
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

    .platform-user-edit-submit:disabled {
        opacity: 0.65;
        cursor: not-allowed;
    }

    .platform-user-edit-cancel {
        min-height: 37px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #6b7280;
        font-size: 9px;
        font-weight: 600;
        text-decoration: none;
    }

    @media (max-width: 850px) {
        .platform-user-edit-layout {
            grid-template-columns: 1fr;
        }

        .platform-user-edit-side {
            order: -1;
        }
    }

    @media (max-width: 650px) {
        .platform-user-edit-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .platform-user-edit-actions {
            width: 100%;
        }

        .platform-user-edit-button {
            flex: 1;
        }
    }
</style>

<div class="platform-user-edit-page">

    <div class="platform-user-edit-header">
        <div>
            <h2 class="platform-user-edit-title">
                Edit Platform User
            </h2>

            <div class="platform-user-edit-description">
                Update profile, access role, account status, and password.
            </div>
        </div>

        <div class="platform-user-edit-actions">
            <a
                href="platform-user-view.php?id=<?= (int) $userId; ?>"
                class="platform-user-edit-button"
            >
                <i class="bi bi-eye"></i>
                View User
            </a>

            <a
                href="platform-users.php"
                class="platform-user-edit-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Users
            </a>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="platform-user-edit-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= platformUserEditEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="platform-user-edit.php?id=<?= (int) $userId; ?>"
        enctype="multipart/form-data"
        id="platformUserEditForm"
        autocomplete="off"
        novalidate
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="user_id"
            value="<?= (int) $userId; ?>"
        >

        <div class="platform-user-edit-layout">

            <div class="platform-user-edit-main">

                <section class="platform-user-edit-card">
                    <div class="platform-user-edit-card-header">
                        <span class="platform-user-edit-card-icon">
                            <i class="bi bi-person"></i>
                        </span>

                        <div>
                            <h3 class="platform-user-edit-card-title">
                                Personal Information
                            </h3>

                            <div class="platform-user-edit-card-subtitle">
                                Name and contact details
                            </div>
                        </div>
                    </div>

                    <div class="platform-user-edit-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label
                                    class="platform-user-edit-label"
                                    for="firstName"
                                >
                                    First Name
                                    <span class="platform-user-edit-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control platform-user-edit-control"
                                    id="firstName"
                                    name="first_name"
                                    value="<?= platformUserEditEscape(
                                        $firstName
                                    ); ?>"
                                    maxlength="120"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="platform-user-edit-label"
                                    for="lastName"
                                >
                                    Last Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control platform-user-edit-control"
                                    id="lastName"
                                    name="last_name"
                                    value="<?= platformUserEditEscape(
                                        $lastName
                                    ); ?>"
                                    maxlength="120"
                                >
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="platform-user-edit-label"
                                    for="email"
                                >
                                    Email Address
                                    <span class="platform-user-edit-required">*</span>
                                </label>

                                <input
                                    type="email"
                                    class="form-control platform-user-edit-control"
                                    id="email"
                                    name="email"
                                    value="<?= platformUserEditEscape(
                                        $email
                                    ); ?>"
                                    maxlength="190"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="platform-user-edit-label"
                                    for="phone"
                                >
                                    Phone Number
                                </label>

                                <input
                                    type="text"
                                    class="form-control platform-user-edit-control"
                                    id="phone"
                                    name="phone"
                                    value="<?= platformUserEditEscape(
                                        $phone
                                    ); ?>"
                                    maxlength="50"
                                >
                            </div>

                            <div class="col-12">
                                <label
                                    class="platform-user-edit-label"
                                    for="jobTitle"
                                >
                                    Job Title
                                </label>

                                <input
                                    type="text"
                                    class="form-control platform-user-edit-control"
                                    id="jobTitle"
                                    name="job_title"
                                    value="<?= platformUserEditEscape(
                                        $jobTitle
                                    ); ?>"
                                    maxlength="120"
                                >
                            </div>

                        </div>
                    </div>
                </section>

                <section class="platform-user-edit-card">
                    <div class="platform-user-edit-card-header">
                        <span class="platform-user-edit-card-icon">
                            <i class="bi bi-key"></i>
                        </span>

                        <div>
                            <h3 class="platform-user-edit-card-title">
                                Change Password
                            </h3>

                            <div class="platform-user-edit-card-subtitle">
                                Leave blank to keep the existing password
                            </div>
                        </div>
                    </div>

                    <div class="platform-user-edit-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label
                                    class="platform-user-edit-label"
                                    for="password"
                                >
                                    New Password
                                </label>

                                <div class="platform-user-edit-password-wrap">
                                    <input
                                        type="password"
                                        class="form-control platform-user-edit-control pe-5"
                                        id="password"
                                        name="password"
                                        minlength="8"
                                    >

                                    <button
                                        type="button"
                                        class="platform-user-edit-password-toggle"
                                        data-password-target="password"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="platform-user-edit-label"
                                    for="confirmPassword"
                                >
                                    Confirm Password
                                </label>

                                <div class="platform-user-edit-password-wrap">
                                    <input
                                        type="password"
                                        class="form-control platform-user-edit-control pe-5"
                                        id="confirmPassword"
                                        name="confirm_password"
                                        minlength="8"
                                    >

                                    <button
                                        type="button"
                                        class="platform-user-edit-password-toggle"
                                        data-password-target="confirmPassword"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="platform-user-edit-help">
                                    New passwords require at least 8 characters with uppercase, lowercase, and a number.
                                </div>
                            </div>

                        </div>
                    </div>
                </section>

            </div>

            <aside class="platform-user-edit-side">

                <section class="platform-user-edit-card">
                    <div class="platform-user-edit-card-header">
                        <span class="platform-user-edit-card-icon">
                            <i class="bi bi-image"></i>
                        </span>

                        <div>
                            <h3 class="platform-user-edit-card-title">
                                Profile Avatar
                            </h3>

                            <div class="platform-user-edit-card-subtitle">
                                Replace or remove the profile picture
                            </div>
                        </div>
                    </div>

                    <div class="platform-user-edit-card-body">
                        <div
                            class="platform-user-edit-avatar"
                            id="avatarPreview"
                        >
                            <?php if ($avatarDisplayPath !== ''): ?>
                                <img
                                    src="../<?= platformUserEditEscape(
                                        ltrim(
                                            $avatarDisplayPath,
                                            '/'
                                        )
                                    ); ?>"
                                    alt=""
                                >
                            <?php else: ?>
                                <i class="bi bi-person"></i>
                            <?php endif; ?>
                        </div>

                        <input
                            type="file"
                            class="platform-user-edit-avatar-input"
                            id="avatarInput"
                            name="avatar"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >

                        <label
                            for="avatarInput"
                            class="platform-user-edit-avatar-button"
                        >
                            <i class="bi bi-upload"></i>
                            Replace Avatar
                        </label>

                        <?php if (
                            !empty($currentUser['avatar_path'])
                        ): ?>
                            <label class="platform-user-edit-remove">
                                <input
                                    type="checkbox"
                                    name="remove_avatar"
                                    id="removeAvatar"
                                    value="1"
                                    <?= $removeAvatar === 1
                                        ? 'checked'
                                        : ''; ?>
                                >

                                Remove current avatar
                            </label>
                        <?php endif; ?>

                        <div class="platform-user-edit-help text-center">
                            JPG, PNG, or WEBP. Maximum 3 MB.
                        </div>
                    </div>
                </section>

                <section class="platform-user-edit-card">
                    <div class="platform-user-edit-card-header">
                        <span class="platform-user-edit-card-icon">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <div>
                            <h3 class="platform-user-edit-card-title">
                                Access Settings
                            </h3>

                            <div class="platform-user-edit-card-subtitle">
                                Role and account status
                            </div>
                        </div>
                    </div>

                    <div class="platform-user-edit-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label
                                    class="platform-user-edit-label"
                                    for="roleCode"
                                >
                                    Platform Role
                                    <span class="platform-user-edit-required">*</span>
                                </label>

                                <select
                                    class="form-select platform-user-edit-control"
                                    id="roleCode"
                                    name="role_code"
                                    required
                                    <?= $currentPlatformUserId === $userId
                                        ? 'disabled'
                                        : ''; ?>
                                >
                                    <?php foreach (
                                        $allowedRoles as
                                        $roleValue => $roleLabel
                                    ): ?>
                                        <option
                                            value="<?= platformUserEditEscape(
                                                $roleValue
                                            ); ?>"
                                            <?= $roleCode ===
                                                $roleValue
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= platformUserEditEscape(
                                                $roleLabel
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if (
                                    $currentPlatformUserId === $userId
                                ): ?>
                                    <input
                                        type="hidden"
                                        name="role_code"
                                        value="<?= platformUserEditEscape(
                                            $currentUser['role_code']
                                        ); ?>"
                                    >

                                    <div class="platform-user-edit-help">
                                        You cannot change your own platform role.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-12">
                                <label
                                    class="platform-user-edit-label"
                                    for="status"
                                >
                                    Account Status
                                </label>

                                <select
                                    class="form-select platform-user-edit-control"
                                    id="status"
                                    name="status"
                                    <?= $currentPlatformUserId === $userId
                                        ? 'disabled'
                                        : ''; ?>
                                >
                                    <?php foreach (
                                        $allowedStatuses as
                                        $statusValue => $statusLabel
                                    ): ?>
                                        <option
                                            value="<?= platformUserEditEscape(
                                                $statusValue
                                            ); ?>"
                                            <?= $status ===
                                                $statusValue
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= platformUserEditEscape(
                                                $statusLabel
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if (
                                    $currentPlatformUserId === $userId
                                ): ?>
                                    <input
                                        type="hidden"
                                        name="status"
                                        value="active"
                                    >

                                    <div class="platform-user-edit-help">
                                        You cannot deactivate or suspend your own account.
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                </section>

                <div class="platform-user-edit-submit-card">
                    <button
                        type="button"
                        class="platform-user-edit-submit"
                        id="platformUserEditSubmit"
                    >
                        <i class="bi bi-check2-circle"></i>

                        <span id="platformUserEditSubmitText">
                            Update Platform User
                        </span>
                    </button>

                    <a
                        href="platform-user-view.php?id=<?= (int) $userId; ?>"
                        class="platform-user-edit-cancel"
                    >
                        Cancel
                    </a>
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
                    avatarInput.value = '';
                    avatarPreview.innerHTML =
                        '<i class="bi bi-person"></i>';
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
            'platformUserEditForm'
        );

    const submitButton =
        document.getElementById(
            'platformUserEditSubmit'
        );

    const submitText =
        document.getElementById(
            'platformUserEditSubmitText'
        );

    let submitting = false;

    function submitForm() {
        if (
            !form ||
            submitting
        ) {
            return;
        }

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const password =
            document.getElementById(
                'password'
            );

        const confirmPassword =
            document.getElementById(
                'confirmPassword'
            );

        if (
            password &&
            confirmPassword &&
            password.value !==
            confirmPassword.value
        ) {
            confirmPassword.setCustomValidity(
                'Passwords do not match.'
            );

            confirmPassword.reportValidity();

            confirmPassword.setCustomValidity(
                ''
            );

            return;
        }

        submitting = true;

        if (submitButton) {
            submitButton.disabled = true;
        }

        if (submitText) {
            submitText.textContent =
                'Updating...';
        }

        HTMLFormElement.prototype.submit.call(
            form
        );
    }

    if (submitButton) {
        submitButton.addEventListener(
            'click',
            function (event) {
                event.preventDefault();
                event.stopPropagation();
                submitForm();
            }
        );
    }

    if (form) {
        form.addEventListener(
            'submit',
            function (event) {
                event.preventDefault();
                event.stopImmediatePropagation();
                submitForm();
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
