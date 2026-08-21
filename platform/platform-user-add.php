<?php
/**
 * FieldPlx Platform - Add Platform User
 *
 * File:
 * platform/platform-user-add.php
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

$pageTitle = 'Add Platform User - FieldPlx';
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

if (!function_exists('platformUserAddEscape')) {
    function platformUserAddEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('platformUserAddPost')) {
    function platformUserAddPost(
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

if (!function_exists('platformUserAddTableExists')) {
    function platformUserAddTableExists(
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

if (!function_exists('platformUserAddUploadAvatar')) {
    function platformUserAddUploadAvatar(
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
    !platformUserAddTableExists(
        $conn,
        'platform_users'
    )
) {
    http_response_code(500);
    exit('The platform_users table does not exist.');
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

$currentRole = isset($_SESSION['platform_role'])
    ? (string) $_SESSION['platform_role']
    : (
        isset($_SESSION['platform_user_role'])
            ? (string) $_SESSION['platform_user_role']
            : ''
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

$firstName = platformUserAddPost('first_name');
$lastName = platformUserAddPost('last_name');
$email = strtolower(
    platformUserAddPost('email')
);
$phone = platformUserAddPost('phone');
$jobTitle = platformUserAddPost('job_title');
$roleCode = platformUserAddPost(
    'role_code',
    'platform_read_only'
);
$status = platformUserAddPost(
    'status',
    'active'
);

if (!isset($allowedRoles[$roleCode])) {
    $roleCode = 'platform_read_only';
}

if (!isset($allowedStatuses[$status])) {
    $status = 'active';
}

/*
|--------------------------------------------------------------------------
| Process form
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
    } elseif (strlen($password) < 8) {
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

    if ($errorMessage === '') {
        $duplicateStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM platform_users
            WHERE LOWER(`email`) = LOWER(?)
              AND `deleted_at` IS NULL
        ");

        $duplicateStmt->bind_param(
            's',
            $email
        );

        $duplicateStmt->execute();

        $duplicateRow = $duplicateStmt
            ->get_result()
            ->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'A platform user with this email already exists.';
        }
    }

    $avatarPath = null;

    if ($errorMessage === '') {
        $avatarPath =
            platformUserAddUploadAvatar(
                isset($_FILES['avatar'])
                    ? $_FILES['avatar']
                    : null,
                $errorMessage
            );
    }

    if ($errorMessage === '') {
        try {
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

            $insertStmt = $conn->prepare("
                INSERT INTO platform_users (
                    `first_name`,
                    `last_name`,
                    `email`,
                    `phone`,
                    `password_hash`,
                    `avatar_path`,
                    `job_title`,
                    `role_code`,
                    `status`,
                    `created_at`
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ");

            $insertStmt->bind_param(
                'sssssssss',
                $firstName,
                $lastName,
                $email,
                $phone,
                $passwordHash,
                $avatarPath,
                $jobTitle,
                $roleCode,
                $status
            );

            $insertStmt->execute();

            $newUserId =
                (int) $insertStmt->insert_id;

            $insertStmt->close();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Platform user created successfully.';

            header(
                'Location: platform-user-view.php?id=' .
                $newUserId,
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            if (
                $avatarPath !== null &&
                $avatarPath !== false
            ) {
                $avatarAbsolutePath =
                    dirname(__DIR__) .
                    DIRECTORY_SEPARATOR .
                    str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $avatarPath
                    );

                if (is_file($avatarAbsolutePath)) {
                    @unlink($avatarAbsolutePath);
                }
            }

            error_log(
                'Platform user creation failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to create the platform user: ' .
                $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .platform-user-add-page {
        max-width: 1000px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .platform-user-add-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .platform-user-add-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .platform-user-add-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .platform-user-add-back {
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

    .platform-user-add-back:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .platform-user-add-alert {
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

    .platform-user-add-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(280px, 320px);
        gap: 15px;
        align-items: start;
    }

    .platform-user-add-main,
    .platform-user-add-side {
        display: grid;
        gap: 15px;
    }

    .platform-user-add-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .platform-user-add-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .platform-user-add-card-icon {
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

    .platform-user-add-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .platform-user-add-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .platform-user-add-card-body {
        padding: 15px;
    }

    .platform-user-add-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .platform-user-add-required {
        color: #dc2626;
    }

    .platform-user-add-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .platform-user-add-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .platform-user-add-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .platform-user-add-password-wrap {
        position: relative;
    }

    .platform-user-add-password-toggle {
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

    .platform-user-add-avatar {
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

    .platform-user-add-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .platform-user-add-avatar-input {
        display: none;
    }

    .platform-user-add-avatar-button {
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

    .platform-user-add-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .platform-user-add-submit {
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

    .platform-user-add-submit:disabled {
        opacity: 0.65;
        cursor: not-allowed;
    }

    .platform-user-add-cancel {
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
        .platform-user-add-layout {
            grid-template-columns: 1fr;
        }

        .platform-user-add-side {
            order: -1;
        }
    }

    @media (max-width: 650px) {
        .platform-user-add-header {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<div class="platform-user-add-page">

    <div class="platform-user-add-header">
        <div>
            <h2 class="platform-user-add-title">
                Add Platform User
            </h2>

            <div class="platform-user-add-description">
                Create a new administrator or platform team account.
            </div>
        </div>

        <a
            href="platform-users.php"
            class="platform-user-add-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Platform Users
        </a>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="platform-user-add-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= platformUserAddEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="platform-user-add.php"
        enctype="multipart/form-data"
        id="platformUserAddForm"
        autocomplete="off"
        novalidate
    >
        <?php csrfField(); ?>

        <div class="platform-user-add-layout">

            <div class="platform-user-add-main">

                <section class="platform-user-add-card">
                    <div class="platform-user-add-card-header">
                        <span class="platform-user-add-card-icon">
                            <i class="bi bi-person"></i>
                        </span>

                        <div>
                            <h3 class="platform-user-add-card-title">
                                Personal Information
                            </h3>

                            <div class="platform-user-add-card-subtitle">
                                Name and contact details
                            </div>
                        </div>
                    </div>

                    <div class="platform-user-add-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label
                                    class="platform-user-add-label"
                                    for="firstName"
                                >
                                    First Name
                                    <span class="platform-user-add-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control platform-user-add-control"
                                    id="firstName"
                                    name="first_name"
                                    value="<?= platformUserAddEscape(
                                        $firstName
                                    ); ?>"
                                    maxlength="120"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="platform-user-add-label"
                                    for="lastName"
                                >
                                    Last Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control platform-user-add-control"
                                    id="lastName"
                                    name="last_name"
                                    value="<?= platformUserAddEscape(
                                        $lastName
                                    ); ?>"
                                    maxlength="120"
                                >
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="platform-user-add-label"
                                    for="email"
                                >
                                    Email Address
                                    <span class="platform-user-add-required">*</span>
                                </label>

                                <input
                                    type="email"
                                    class="form-control platform-user-add-control"
                                    id="email"
                                    name="email"
                                    value="<?= platformUserAddEscape(
                                        $email
                                    ); ?>"
                                    maxlength="190"
                                    required
                                >
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="platform-user-add-label"
                                    for="phone"
                                >
                                    Phone Number
                                </label>

                                <input
                                    type="text"
                                    class="form-control platform-user-add-control"
                                    id="phone"
                                    name="phone"
                                    value="<?= platformUserAddEscape(
                                        $phone
                                    ); ?>"
                                    maxlength="50"
                                >
                            </div>

                            <div class="col-12">
                                <label
                                    class="platform-user-add-label"
                                    for="jobTitle"
                                >
                                    Job Title
                                </label>

                                <input
                                    type="text"
                                    class="form-control platform-user-add-control"
                                    id="jobTitle"
                                    name="job_title"
                                    value="<?= platformUserAddEscape(
                                        $jobTitle
                                    ); ?>"
                                    maxlength="120"
                                    placeholder="Example: Platform Support Manager"
                                >
                            </div>

                        </div>
                    </div>
                </section>

                <section class="platform-user-add-card">
                    <div class="platform-user-add-card-header">
                        <span class="platform-user-add-card-icon">
                            <i class="bi bi-shield-lock"></i>
                        </span>

                        <div>
                            <h3 class="platform-user-add-card-title">
                                Login Credentials
                            </h3>

                            <div class="platform-user-add-card-subtitle">
                                Secure password for platform access
                            </div>
                        </div>
                    </div>

                    <div class="platform-user-add-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label
                                    class="platform-user-add-label"
                                    for="password"
                                >
                                    Password
                                    <span class="platform-user-add-required">*</span>
                                </label>

                                <div class="platform-user-add-password-wrap">
                                    <input
                                        type="password"
                                        class="form-control platform-user-add-control pe-5"
                                        id="password"
                                        name="password"
                                        minlength="8"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="platform-user-add-password-toggle"
                                        data-password-target="password"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="platform-user-add-label"
                                    for="confirmPassword"
                                >
                                    Confirm Password
                                    <span class="platform-user-add-required">*</span>
                                </label>

                                <div class="platform-user-add-password-wrap">
                                    <input
                                        type="password"
                                        class="form-control platform-user-add-control pe-5"
                                        id="confirmPassword"
                                        name="confirm_password"
                                        minlength="8"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="platform-user-add-password-toggle"
                                        data-password-target="confirmPassword"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="platform-user-add-help">
                                    Use at least 8 characters with uppercase, lowercase, and a number.
                                </div>
                            </div>

                        </div>
                    </div>
                </section>

            </div>

            <aside class="platform-user-add-side">

                <section class="platform-user-add-card">
                    <div class="platform-user-add-card-header">
                        <span class="platform-user-add-card-icon">
                            <i class="bi bi-image"></i>
                        </span>

                        <div>
                            <h3 class="platform-user-add-card-title">
                                Profile Avatar
                            </h3>

                            <div class="platform-user-add-card-subtitle">
                                Optional profile picture
                            </div>
                        </div>
                    </div>

                    <div class="platform-user-add-card-body">
                        <div
                            class="platform-user-add-avatar"
                            id="avatarPreview"
                        >
                            <i class="bi bi-person"></i>
                        </div>

                        <input
                            type="file"
                            class="platform-user-add-avatar-input"
                            id="avatarInput"
                            name="avatar"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >

                        <label
                            for="avatarInput"
                            class="platform-user-add-avatar-button"
                        >
                            <i class="bi bi-upload"></i>
                            Choose Avatar
                        </label>

                        <div class="platform-user-add-help text-center">
                            JPG, PNG, or WEBP. Maximum 3 MB.
                        </div>
                    </div>
                </section>

                <section class="platform-user-add-card">
                    <div class="platform-user-add-card-header">
                        <span class="platform-user-add-card-icon">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <div>
                            <h3 class="platform-user-add-card-title">
                                Access Settings
                            </h3>

                            <div class="platform-user-add-card-subtitle">
                                Role and account status
                            </div>
                        </div>
                    </div>

                    <div class="platform-user-add-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label
                                    class="platform-user-add-label"
                                    for="roleCode"
                                >
                                    Platform Role
                                    <span class="platform-user-add-required">*</span>
                                </label>

                                <select
                                    class="form-select platform-user-add-control"
                                    id="roleCode"
                                    name="role_code"
                                    required
                                >
                                    <?php foreach (
                                        $allowedRoles as
                                        $roleValue => $roleLabel
                                    ): ?>
                                        <option
                                            value="<?= platformUserAddEscape(
                                                $roleValue
                                            ); ?>"
                                            <?= $roleCode ===
                                                $roleValue
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= platformUserAddEscape(
                                                $roleLabel
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label
                                    class="platform-user-add-label"
                                    for="status"
                                >
                                    Account Status
                                </label>

                                <select
                                    class="form-select platform-user-add-control"
                                    id="status"
                                    name="status"
                                >
                                    <?php foreach (
                                        $allowedStatuses as
                                        $statusValue => $statusLabel
                                    ): ?>
                                        <option
                                            value="<?= platformUserAddEscape(
                                                $statusValue
                                            ); ?>"
                                            <?= $status ===
                                                $statusValue
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= platformUserAddEscape(
                                                $statusLabel
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>
                    </div>
                </section>

                <div class="platform-user-add-submit-card">
                    <button
                        type="button"
                        class="platform-user-add-submit"
                        id="platformUserAddSubmit"
                    >
                        <i class="bi bi-person-plus"></i>

                        <span id="platformUserAddSubmitText">
                            Create Platform User
                        </span>
                    </button>

                    <a
                        href="platform-users.php"
                        class="platform-user-add-cancel"
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
            'platformUserAddForm'
        );

    const submitButton =
        document.getElementById(
            'platformUserAddSubmit'
        );

    const submitText =
        document.getElementById(
            'platformUserAddSubmitText'
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
                'Creating...';
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
