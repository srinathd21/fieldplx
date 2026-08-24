
<?php
/**
 * FieldPlx Platform Super Admin Creator
 *
 * URL:
 * https://fieldplx.com/platform/create-super-admin.php
 *
 * Uses:
 * - platform_users table
 * - includes/db.php
 * - includes/csrf.php
 *
 * IMPORTANT:
 * Delete this file immediately after creating the first
 * platform super administrator.
 *
 * Compatible with PHP 7.2 and MariaDB.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('superAdminEscape')) {
    function superAdminEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('superAdminPostString')) {
    function superAdminPostString($key)
    {
        if (
            !isset($_POST[$key]) ||
            is_array($_POST[$key])
        ) {
            return '';
        }

        return trim((string) $_POST[$key]);
    }
}

if (!function_exists('superAdminNormaliseEmail')) {
    function superAdminNormaliseEmail($email)
    {
        return strtolower(
            trim((string) $email)
        );
    }
}

if (!function_exists('validateSuperAdminPassword')) {
    /**
     * Validate administrator password strength.
     *
     * @param string $password
     * @return string
     */
    function validateSuperAdminPassword($password)
    {
        if (strlen($password) < 10) {
            return 'Password must contain at least 10 characters.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must contain at least one special character.';
        }

        return '';
    }
}

/*
|--------------------------------------------------------------------------
| Initial values
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';

$firstName = '';
$lastName = '';
$email = '';
$phone = '';
$jobTitle = 'Platform Super Administrator';

$tableExists = false;
$existingSuperAdminCount = 0;

/*
|--------------------------------------------------------------------------
| Check platform_users table
|--------------------------------------------------------------------------
*/

$tableResult = $conn->query("
    SHOW TABLES LIKE 'platform_users'
");

if ($tableResult) {
    $tableExists = $tableResult->num_rows > 0;
    $tableResult->free();
}

if (!$tableExists) {
    $errorMessage =
        'The platform_users table does not exist. ' .
        'Create the platform_users table before using this page.';
}

/*
|--------------------------------------------------------------------------
| Check for an existing super admin
|--------------------------------------------------------------------------
*/

if ($tableExists) {
    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM platform_users
        WHERE role_code = 'super_admin'
          AND deleted_at IS NULL
    ");

    if ($countStmt) {
        if ($countStmt->execute()) {
            $countResult = $countStmt->get_result();
            $countRow = $countResult->fetch_assoc();

            $existingSuperAdminCount =
                isset($countRow['total'])
                    ? (int) $countRow['total']
                    : 0;
        } else {
            error_log(
                'Super admin count execution failed: ' .
                $countStmt->error
            );
        }

        $countStmt->close();
    } else {
        error_log(
            'Super admin count preparation failed: ' .
            $conn->error
        );
    }
}

/*
|--------------------------------------------------------------------------
| Process form
|--------------------------------------------------------------------------
*/

if (
    $tableExists &&
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $firstName = superAdminPostString('first_name');
    $lastName = superAdminPostString('last_name');

    $email = superAdminNormaliseEmail(
        superAdminPostString('email')
    );

    $phone = superAdminPostString('phone');
    $jobTitle = superAdminPostString('job_title');

    $password = isset($_POST['password'])
        && !is_array($_POST['password'])
            ? (string) $_POST['password']
            : '';

    $confirmPassword = isset($_POST['confirm_password'])
        && !is_array($_POST['confirm_password'])
            ? (string) $_POST['confirm_password']
            : '';

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($existingSuperAdminCount > 0) {
        $errorMessage =
            'A platform super administrator already exists. ' .
            'Delete this setup file and use the platform login page.';
    } elseif ($firstName === '') {
        $errorMessage = 'Enter the first name.';
    } elseif (strlen($firstName) > 120) {
        $errorMessage =
            'First name must not exceed 120 characters.';
    } elseif (strlen($lastName) > 120) {
        $errorMessage =
            'Last name must not exceed 120 characters.';
    } elseif (
        filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        $errorMessage =
            'Enter a valid email address.';
    } elseif (strlen($email) > 190) {
        $errorMessage =
            'Email address must not exceed 190 characters.';
    } elseif (strlen($phone) > 50) {
        $errorMessage =
            'Phone number must not exceed 50 characters.';
    } elseif ($jobTitle === '') {
        $errorMessage =
            'Enter the job title.';
    } elseif (strlen($jobTitle) > 120) {
        $errorMessage =
            'Job title must not exceed 120 characters.';
    } else {
        $passwordError =
            validateSuperAdminPassword($password);

        if ($passwordError !== '') {
            $errorMessage = $passwordError;
        } elseif ($password !== $confirmPassword) {
            $errorMessage =
                'Password and confirmation password do not match.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create platform super administrator
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {
        try {
            $conn->begin_transaction();

            /*
             * Lock existing super-admin rows to prevent two setup
             * requests from creating two first administrators.
             */
            $lockStmt = $conn->prepare("
                SELECT id
                FROM platform_users
                WHERE role_code = 'super_admin'
                  AND deleted_at IS NULL
                FOR UPDATE
            ");

            if (!$lockStmt) {
                throw new Exception(
                    'Unable to prepare administrator validation: ' .
                    $conn->error
                );
            }

            if (!$lockStmt->execute()) {
                throw new Exception(
                    'Unable to validate administrators: ' .
                    $lockStmt->error
                );
            }

            $lockResult = $lockStmt->get_result();

            if ($lockResult->num_rows > 0) {
                $lockStmt->close();

                throw new Exception(
                    'A platform super administrator already exists.'
                );
            }

            $lockStmt->close();

            /*
             * Check whether the email already exists.
             */
            $emailStmt = $conn->prepare("
                SELECT id
                FROM platform_users
                WHERE LOWER(email) = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$emailStmt) {
                throw new Exception(
                    'Unable to prepare email validation: ' .
                    $conn->error
                );
            }

            $emailStmt->bind_param(
                's',
                $email
            );

            if (!$emailStmt->execute()) {
                throw new Exception(
                    'Unable to validate the email address: ' .
                    $emailStmt->error
                );
            }

            $emailResult = $emailStmt->get_result();
            $existingEmail = $emailResult->fetch_assoc();

            $emailStmt->close();

            if ($existingEmail) {
                throw new Exception(
                    'A platform user already exists with this email address.'
                );
            }

            /*
             * Hash password.
             */
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            if ($passwordHash === false) {
                throw new Exception(
                    'Unable to secure the password.'
                );
            }

            $roleCode = 'super_admin';
            $status = 'active';

            $insertStmt = $conn->prepare("
                INSERT INTO platform_users (
                    first_name,
                    last_name,
                    email,
                    phone,
                    password_hash,
                    avatar_path,
                    job_title,
                    role_code,
                    status,
                    last_login_at,
                    created_at,
                    updated_at,
                    deleted_at
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NULL,
                    ?,
                    ?,
                    ?,
                    NULL,
                    NOW(),
                    NOW(),
                    NULL
                )
            ");

            if (!$insertStmt) {
                throw new Exception(
                    'Unable to prepare administrator creation: ' .
                    $conn->error
                );
            }

            $insertStmt->bind_param(
                'ssssssss',
                $firstName,
                $lastName,
                $email,
                $phone,
                $passwordHash,
                $jobTitle,
                $roleCode,
                $status
            );

            if (!$insertStmt->execute()) {
                throw new Exception(
                    'Unable to create platform super administrator: ' .
                    $insertStmt->error
                );
            }

            $newPlatformUserId =
                (int) $insertStmt->insert_id;

            $insertStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $successMessage =
                'Platform super administrator created successfully. ' .
                'User ID: ' .
                $newPlatformUserId .
                '. Delete create-super-admin.php immediately.';

            $existingSuperAdminCount = 1;

            $firstName = '';
            $lastName = '';
            $email = '';
            $phone = '';
            $jobTitle =
                'Platform Super Administrator';
        } catch (Exception $exception) {
            $conn->rollback();

            error_log(
                'Platform super admin creation failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                $exception->getMessage();
        }
    }
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

    <meta
        name="robots"
        content="noindex, nofollow"
    >

    <title>
        Create Platform Super Admin - FieldPlx
    </title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --setup-primary: #6d28d9;
            --setup-primary-dark: #5b21b6;
            --setup-text: #1f2937;
            --setup-muted: #6b7280;
            --setup-border: #e5e7eb;
            --setup-background: #f5f3ff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(
                    circle at 15% 15%,
                    rgba(124, 58, 237, 0.13),
                    transparent 28%
                ),
                radial-gradient(
                    circle at 85% 85%,
                    rgba(37, 99, 235, 0.09),
                    transparent 28%
                ),
                var(--setup-background);
            color: var(--setup-text);
            font-family: "Inter", sans-serif;
            font-size: 13px;
        }

        .setup-wrapper {
            width: 100%;
            max-width: 660px;
        }

        .setup-card {
            overflow: hidden;
            border: 1px solid var(--setup-border);
            border-radius: 20px;
            background: #ffffff;
            box-shadow:
                0 24px 70px rgba(31, 41, 55, 0.15);
        }

        .setup-header {
            padding: 28px 30px 24px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid #f0f1f3;
            background:
                linear-gradient(
                    135deg,
                    #111827,
                    #312e81
                );
            color: #ffffff;
        }

        .setup-logo {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            background:
                linear-gradient(
                    135deg,
                    #a78bfa,
                    #7c3aed
                );
            font-size: 16px;
            font-weight: 800;
        }

        .setup-title {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
        }

        .setup-subtitle {
            margin-top: 4px;
            color: #c4b5fd;
            font-size: 10px;
        }

        .setup-body {
            padding: 28px 30px 30px;
        }

        .setup-warning {
            margin-bottom: 21px;
            padding: 12px 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid #fde68a;
            border-radius: 10px;
            background: #fffbeb;
            color: #92400e;
            font-size: 10px;
            line-height: 1.55;
        }

        .setup-alert {
            margin-bottom: 20px;
            padding: 12px 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid;
            border-radius: 10px;
            font-size: 10px;
            line-height: 1.55;
        }

        .setup-alert-danger {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b91c1c;
        }

        .setup-alert-success {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #15803d;
        }

        .form-label {
            margin-bottom: 6px;
            color: #374151;
            font-size: 10px;
            font-weight: 700;
        }

        .form-control {
            min-height: 42px;
            border: 1px solid var(--setup-border);
            border-radius: 10px;
            background: #fafafa;
            box-shadow: none;
            font-size: 11px;
        }

        .form-control:focus {
            border-color: #c4b5fd;
            background: #ffffff;
            box-shadow:
                0 0 0 3px rgba(124, 58, 237, 0.09);
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap .form-control {
            padding-right: 42px;
        }

        .password-toggle {
            width: 34px;
            height: 34px;
            padding: 0;
            position: absolute;
            top: 50%;
            right: 4px;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #9ca3af;
        }

        .password-toggle:hover {
            background: #f3f0ff;
            color: var(--setup-primary);
        }

        .password-help {
            margin-top: 6px;
            color: #9ca3af;
            font-size: 9px;
            line-height: 1.5;
        }

        .setup-submit {
            width: 100%;
            min-height: 43px;
            margin-top: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 10px;
            background:
                linear-gradient(
                    135deg,
                    #7c3aed,
                    #6d28d9
                );
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
        }

        .setup-submit:hover {
            background:
                linear-gradient(
                    135deg,
                    #6d28d9,
                    #5b21b6
                );
        }

        .setup-submit:disabled {
            cursor: not-allowed;
            opacity: 0.7;
        }

        .login-button {
            width: 100%;
            min-height: 43px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 10px;
            background: var(--setup-primary);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
        }

        .login-button:hover {
            background: var(--setup-primary-dark);
            color: #ffffff;
        }

        .setup-footer-note {
            margin-top: 18px;
            color: #9ca3af;
            font-size: 9px;
            line-height: 1.55;
            text-align: center;
        }

        @media (max-width: 600px) {
            body {
                padding: 13px;
                align-items: flex-start;
            }

            .setup-wrapper {
                margin-top: 15px;
            }

            .setup-header {
                padding: 23px 20px;
            }

            .setup-body {
                padding: 23px 20px;
            }
        }
    </style>
</head>

<body>

<div class="setup-wrapper">
    <div class="setup-card">

        <div class="setup-header">
            <span class="setup-logo">
                FP
            </span>

            <div>
                <h1 class="setup-title">
                    Create Platform Super Admin
                </h1>

                <div class="setup-subtitle">
                    Initial FieldPlx platform administrator setup
                </div>
            </div>
        </div>

        <div class="setup-body">

            <div class="setup-warning">
                <i class="bi bi-exclamation-triangle"></i>

                <div>
                    This page is only for creating the first
                    platform administrator. Permanently delete
                    <strong>platform/create-super-admin.php</strong>
                    immediately after the account is created.
                </div>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div
                    class="setup-alert setup-alert-danger"
                    role="alert"
                >
                    <i class="bi bi-exclamation-circle"></i>

                    <span>
                        <?= superAdminEscape($errorMessage); ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>
                <div
                    class="setup-alert setup-alert-success"
                    role="alert"
                >
                    <i class="bi bi-check-circle"></i>

                    <span>
                        <?= superAdminEscape($successMessage); ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if (
                $successMessage !== '' ||
                $existingSuperAdminCount > 0
            ): ?>

                <a
                    href="login.php"
                    class="login-button"
                >
                    <i class="bi bi-box-arrow-in-right"></i>
                    Open Platform Login
                </a>

                <div class="setup-footer-note">
                    Delete this setup file before continuing.
                </div>

            <?php elseif ($tableExists): ?>

                <form
                    method="post"
                    id="superAdminForm"
                    autocomplete="off"
                >
                    <?php csrfField(); ?>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label
                                for="firstName"
                                class="form-label"
                            >
                                First Name
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="firstName"
                                name="first_name"
                                value="<?= superAdminEscape(
                                    $firstName
                                ); ?>"
                                maxlength="120"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label
                                for="lastName"
                                class="form-label"
                            >
                                Last Name
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="lastName"
                                name="last_name"
                                value="<?= superAdminEscape(
                                    $lastName
                                ); ?>"
                                maxlength="120"
                            >
                        </div>

                        <div class="col-md-7">
                            <label
                                for="email"
                                class="form-label"
                            >
                                Email Address
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                value="<?= superAdminEscape(
                                    $email
                                ); ?>"
                                maxlength="190"
                                autocomplete="username"
                                required
                            >
                        </div>

                        <div class="col-md-5">
                            <label
                                for="phone"
                                class="form-label"
                            >
                                Phone Number
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="phone"
                                name="phone"
                                value="<?= superAdminEscape(
                                    $phone
                                ); ?>"
                                maxlength="50"
                            >
                        </div>

                        <div class="col-12">
                            <label
                                for="jobTitle"
                                class="form-label"
                            >
                                Job Title
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="jobTitle"
                                name="job_title"
                                value="<?= superAdminEscape(
                                    $jobTitle
                                ); ?>"
                                maxlength="120"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label
                                for="password"
                                class="form-label"
                            >
                                Password
                            </label>

                            <div class="password-wrap">
                                <input
                                    type="password"
                                    class="form-control"
                                    id="password"
                                    name="password"
                                    minlength="10"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-password-toggle="password"
                                    aria-label="Show password"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label
                                for="confirmPassword"
                                class="form-label"
                            >
                                Confirm Password
                            </label>

                            <div class="password-wrap">
                                <input
                                    type="password"
                                    class="form-control"
                                    id="confirmPassword"
                                    name="confirm_password"
                                    minlength="10"
                                    autocomplete="new-password"
                                    required
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    data-password-toggle="confirmPassword"
                                    aria-label="Show password"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="password-help">
                                Use at least 10 characters with an
                                uppercase letter, lowercase letter,
                                number and special character.
                            </div>
                        </div>

                        <div class="col-12">
                            <button
                                type="submit"
                                class="setup-submit"
                                id="createAdminButton"
                            >
                                <i class="bi bi-shield-plus"></i>
                                Create Platform Super Admin
                            </button>
                        </div>

                    </div>
                </form>

                <div class="setup-footer-note">
                    The account will be created with the
                    <strong>super_admin</strong> role and
                    <strong>active</strong> status.
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const passwordButtons = document.querySelectorAll(
        '[data-password-toggle]'
    );

    passwordButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const inputId = button.getAttribute(
                'data-password-toggle'
            );

            const input = document.getElementById(inputId);

            if (!input) {
                return;
            }

            const showPassword =
                input.type === 'password';

            input.type = showPassword
                ? 'text'
                : 'password';

            const icon = button.querySelector('i');

            if (icon) {
                icon.classList.toggle(
                    'bi-eye',
                    !showPassword
                );

                icon.classList.toggle(
                    'bi-eye-slash',
                    showPassword
                );
            }

            button.setAttribute(
                'aria-label',
                showPassword
                    ? 'Hide password'
                    : 'Show password'
            );
        });
    });

    const form = document.getElementById(
        'superAdminForm'
    );

    const submitButton = document.getElementById(
        'createAdminButton'
    );

    if (form && submitButton) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                return;
            }

            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            const password =
                document.getElementById('password');

            const confirmPassword =
                document.getElementById(
                    'confirmPassword'
                );

            if (
                password &&
                confirmPassword &&
                password.value !== confirmPassword.value
            ) {
                event.preventDefault();

                alert(
                    'Password and confirmation password do not match.'
                );

                confirmPassword.focus();
                return;
            }

            form.dataset.submitting = '1';
            submitButton.disabled = true;

            submitButton.innerHTML =
                '<span class="spinner-border ' +
                'spinner-border-sm" ' +
                'aria-hidden="true"></span>' +
                '<span>Creating administrator...</span>';
        });
    }
})();
</script>

</body>
</html>

