
<?php
/**
 * FieldPlx Platform Administrator Login
 *
 * URL:
 * https://fieldplx.com/platform/login.php
 *
 * Authentication table:
 * platform_users
 *
 * Compatible with PHP 7.2 and MariaDB/MySQLi.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

$dashboardUrl = 'dashboard.php';

$maximumLoginAttempts = 5;
$loginLockSeconds = 900;

$allowedPlatformRoles = array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
);

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('platformLoginEscape')) {
    function platformLoginEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('platformLoginRedirect')) {
    function platformLoginRedirect($url)
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('platformLoginClientIp')) {
    function platformLoginClientIp()
    {
        return isset($_SERVER['REMOTE_ADDR'])
            ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45)
            : '';
    }
}

if (!function_exists('platformLoginUserAgent')) {
    function platformLoginUserAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500)
            : '';
    }
}

if (!function_exists('platformLoginIsSafeRedirect')) {
    function platformLoginIsSafeRedirect($url)
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        if (
            strpos($url, 'http://') === 0 ||
            strpos($url, 'https://') === 0 ||
            strpos($url, '//') === 0
        ) {
            return false;
        }

        if (strpos($url, "\r") !== false) {
            return false;
        }

        if (strpos($url, "\n") !== false) {
            return false;
        }

        return preg_match(
            '/^[a-zA-Z0-9_\-\/]+\.php(?:\?[a-zA-Z0-9_\-=&%]*)?$/',
            $url
        ) === 1;
    }
}

if (!function_exists('platformLoginClearSession')) {
    function platformLoginClearSession()
    {
        $keys = array(
            'platform_authenticated',
            'platform_user_id',
            'platform_user_name',
            'platform_first_name',
            'platform_last_name',
            'platform_email',
            'platform_phone',
            'platform_avatar_path',
            'platform_job_title',
            'platform_role_code',
            'platform_role_name',
            'platform_last_activity',
            'platform_login_at',
            'platform_login_ip',
            'platform_login_user_agent'
        );

        foreach ($keys as $key) {
            unset($_SESSION[$key]);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Redirect already logged-in platform user
|--------------------------------------------------------------------------
*/

if (
    !empty($_SESSION['platform_authenticated']) &&
    !empty($_SESSION['platform_user_id'])
) {
    platformLoginRedirect($dashboardUrl);
}

/*
|--------------------------------------------------------------------------
| Safe destination after login
|--------------------------------------------------------------------------
*/

$redirectUrl = '';

if (
    isset($_GET['redirect']) &&
    !is_array($_GET['redirect'])
) {
    $redirectUrl = trim(
        (string) $_GET['redirect']
    );
}

if (
    $redirectUrl === '' &&
    isset($_POST['redirect']) &&
    !is_array($_POST['redirect'])
) {
    $redirectUrl = trim(
        (string) $_POST['redirect']
    );
}

if (!platformLoginIsSafeRedirect($redirectUrl)) {
    $redirectUrl = $dashboardUrl;
}

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$errorMessage = '';
$successMessage = '';
$email = '';

if (!empty($_SESSION['platform_login_message'])) {
    $errorMessage =
        (string) $_SESSION['platform_login_message'];

    unset($_SESSION['platform_login_message']);
}

if (!empty($_SESSION['platform_logout_message'])) {
    $successMessage =
        (string) $_SESSION['platform_logout_message'];

    unset($_SESSION['platform_logout_message']);
}

/*
|--------------------------------------------------------------------------
| Session-based login throttling
|--------------------------------------------------------------------------
*/

$loginAttempts = isset(
    $_SESSION['platform_login_attempts']
)
    ? (int) $_SESSION['platform_login_attempts']
    : 0;

$lastFailedLoginAt = isset(
    $_SESSION['platform_last_failed_login_at']
)
    ? (int) $_SESSION['platform_last_failed_login_at']
    : 0;

if (
    $loginAttempts >= $maximumLoginAttempts &&
    $lastFailedLoginAt > 0 &&
    (time() - $lastFailedLoginAt) >= $loginLockSeconds
) {
    $_SESSION['platform_login_attempts'] = 0;
    $_SESSION['platform_last_failed_login_at'] = 0;

    $loginAttempts = 0;
    $lastFailedLoginAt = 0;
}

$isLoginLocked =
    $loginAttempts >= $maximumLoginAttempts &&
    $lastFailedLoginAt > 0 &&
    (time() - $lastFailedLoginAt) < $loginLockSeconds;

$remainingLockSeconds = $isLoginLocked
    ? $loginLockSeconds - (time() - $lastFailedLoginAt)
    : 0;

/*
|--------------------------------------------------------------------------
| Process login
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $email = isset($_POST['email']) &&
        !is_array($_POST['email'])
            ? strtolower(trim((string) $_POST['email']))
            : '';

    $password = isset($_POST['password']) &&
        !is_array($_POST['password'])
            ? (string) $_POST['password']
            : '';

    if ($isLoginLocked) {
        $remainingMinutes = max(
            1,
            (int) ceil($remainingLockSeconds / 60)
        );

        $errorMessage =
            'Too many unsuccessful login attempts. Please try again in ' .
            $remainingMinutes .
            ' minute' .
            ($remainingMinutes !== 1 ? 's' : '') .
            '.';
    } elseif ($email === '') {
        $errorMessage =
            'Enter your email address.';
    } elseif (
        filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) === false
    ) {
        $errorMessage =
            'Enter a valid email address.';
    } elseif ($password === '') {
        $errorMessage =
            'Enter your password.';
    } else {
        $stmt = $conn->prepare("
            SELECT
                id,
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
                deleted_at
            FROM platform_users
            WHERE LOWER(email) = ?
            LIMIT 1
        ");

        if (!$stmt) {
            error_log(
                'Platform login prepare error: ' .
                $conn->error
            );

            $errorMessage =
                'Unable to process your login right now.';
        } else {
            $stmt->bind_param(
                's',
                $email
            );

            if (!$stmt->execute()) {
                error_log(
                    'Platform login execute error: ' .
                    $stmt->error
                );

                $errorMessage =
                    'Unable to process your login right now.';
            } else {
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();

                $loginValid = true;

                if (!$user) {
                    $loginValid = false;
                }

                if (
                    $loginValid &&
                    !empty($user['deleted_at'])
                ) {
                    $loginValid = false;
                }

                if (
                    $loginValid &&
                    strtolower(
                        trim((string) $user['status'])
                    ) !== 'active'
                ) {
                    $loginValid = false;
                }

                $roleCode = $loginValid
                    ? strtolower(
                        trim((string) $user['role_code'])
                    )
                    : '';

                if (
                    $loginValid &&
                    !in_array(
                        $roleCode,
                        $allowedPlatformRoles,
                        true
                    )
                ) {
                    $loginValid = false;
                }

                if (
                    $loginValid &&
                    !password_verify(
                        $password,
                        (string) $user['password_hash']
                    )
                ) {
                    $loginValid = false;
                }

                if (!$loginValid) {
                    $_SESSION['platform_login_attempts'] =
                        $loginAttempts + 1;

                    $_SESSION['platform_last_failed_login_at'] =
                        time();

                    $errorMessage =
                        'Invalid email address or password.';
                } else {
                    /*
                    |--------------------------------------------------------------------------
                    | Successful login
                    |--------------------------------------------------------------------------
                    */

                    platformLoginClearSession();

                    /*
                     * Remove tenant-panel authentication values.
                     */
                    unset(
                        $_SESSION['user_id'],
                        $_SESSION['tenant_id'],
                        $_SESSION['role_id'],
                        $_SESSION['user_name'],
                        $_SESSION['role_name'],
                        $_SESSION['role_code'],
                        $_SESSION['permissions'],
                        $_SESSION['tenant_settings']
                    );

                    session_regenerate_id(true);

                    $fullName = trim(
                        (string) $user['first_name'] .
                        ' ' .
                        (string) $user['last_name']
                    );

                    if ($fullName === '') {
                        $fullName =
                            'Platform Administrator';
                    }

                    $roleName = ucwords(
                        str_replace(
                            '_',
                            ' ',
                            $roleCode
                        )
                    );

                    $_SESSION['platform_authenticated'] =
                        true;

                    $_SESSION['platform_user_id'] =
                        (int) $user['id'];

                    $_SESSION['platform_user_name'] =
                        $fullName;

                    $_SESSION['platform_first_name'] =
                        (string) $user['first_name'];

                    $_SESSION['platform_last_name'] =
                        (string) $user['last_name'];

                    $_SESSION['platform_email'] =
                        (string) $user['email'];

                    $_SESSION['platform_phone'] =
                        (string) $user['phone'];

                    $_SESSION['platform_avatar_path'] =
                        (string) $user['avatar_path'];

                    $_SESSION['platform_job_title'] =
                        (string) $user['job_title'];

                    $_SESSION['platform_role_code'] =
                        $roleCode;

                    $_SESSION['platform_role_name'] =
                        $roleName;

                    $_SESSION['platform_last_activity'] =
                        time();

                    $_SESSION['platform_login_at'] =
                        date('Y-m-d H:i:s');

                    $_SESSION['platform_login_ip'] =
                        platformLoginClientIp();

                    $_SESSION['platform_login_user_agent'] =
                        platformLoginUserAgent();

                    $_SESSION['platform_login_attempts'] = 0;
                    $_SESSION['platform_last_failed_login_at'] = 0;

                    regenerateCsrfToken();

                    /*
                     * Update last login timestamp.
                     */
                    $updateStmt = $conn->prepare("
                        UPDATE platform_users
                        SET
                            last_login_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ");

                    if ($updateStmt) {
                        $platformUserId =
                            (int) $user['id'];

                        $updateStmt->bind_param(
                            'i',
                            $platformUserId
                        );

                        if (!$updateStmt->execute()) {
                            error_log(
                                'Platform last login update failed: ' .
                                $updateStmt->error
                            );
                        }

                        $updateStmt->close();
                    }

                    platformLoginRedirect(
                        $redirectUrl
                    );
                }
            }

            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Attempts remaining
|--------------------------------------------------------------------------
*/

$currentLoginAttempts = isset(
    $_SESSION['platform_login_attempts']
)
    ? (int) $_SESSION['platform_login_attempts']
    : 0;

$attemptsRemaining = max(
    0,
    $maximumLoginAttempts -
    $currentLoginAttempts
);
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

    <meta
        name="csrf-token"
        content="<?= platformLoginEscape(
            csrfToken()
        ); ?>"
    >

    <title>Platform Login - FieldPlx</title>

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
            --login-primary: #6d28d9;
            --login-primary-dark: #5b21b6;
            --login-text: #1f2937;
            --login-muted: #6b7280;
            --login-border: #e5e7eb;
            --login-background: #f5f3ff;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(
                    circle at 12% 18%,
                    rgba(124, 58, 237, 0.14),
                    transparent 29%
                ),
                radial-gradient(
                    circle at 88% 82%,
                    rgba(37, 99, 235, 0.10),
                    transparent 30%
                ),
                var(--login-background);
            color: var(--login-text);
            font-family: "Inter", sans-serif;
            font-size: 13px;
        }

        .platform-login-page {
            min-height: 100vh;
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .platform-login-container {
            width: 100%;
            max-width: 980px;
            min-height: 590px;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1.08fr 0.92fr;
            border: 1px solid rgba(229, 231, 235, 0.95);
            border-radius: 24px;
            background: #ffffff;
            box-shadow:
                0 30px 80px rgba(31, 41, 55, 0.16);
        }

        .platform-login-visual {
            min-height: 590px;
            padding: 52px;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background:
                linear-gradient(
                    145deg,
                    #111827,
                    #312e81
                );
            color: #ffffff;
        }

        .platform-login-visual::before,
        .platform-login-visual::after {
            position: absolute;
            content: "";
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.07);
        }

        .platform-login-visual::before {
            width: 310px;
            height: 310px;
            top: -135px;
            right: -120px;
        }

        .platform-login-visual::after {
            width: 230px;
            height: 230px;
            bottom: -110px;
            left: -85px;
        }

        .platform-login-brand {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .platform-login-logo {
            width: 46px;
            height: 46px;
            flex: 0 0 46px;
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
            box-shadow:
                0 12px 30px rgba(0, 0, 0, 0.20);
            color: #ffffff;
            font-size: 16px;
            font-weight: 800;
        }

        .platform-brand-name {
            display: block;
            color: #ffffff;
            font-size: 17px;
            font-weight: 800;
        }

        .platform-brand-label {
            margin-top: 2px;
            display: block;
            color: #c4b5fd;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.9px;
            text-transform: uppercase;
        }

        .platform-login-intro {
            max-width: 390px;
            position: relative;
            z-index: 2;
        }

        .platform-login-intro h1 {
            margin: 0 0 16px;
            font-size: 36px;
            font-weight: 800;
            line-height: 1.17;
            letter-spacing: -1px;
        }

        .platform-login-intro p {
            margin: 0;
            color: #d8d8ec;
            font-size: 13px;
            line-height: 1.8;
        }

        .platform-login-features {
            margin: 26px 0 0;
            padding: 0;
            display: grid;
            gap: 12px;
            list-style: none;
        }

        .platform-login-features li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ede9fe;
            font-size: 11px;
        }

        .platform-feature-icon {
            width: 29px;
            height: 29px;
            flex: 0 0 29px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.08);
            color: #c4b5fd;
        }

        .platform-visual-footer {
            position: relative;
            z-index: 2;
            color: #a5b4c7;
            font-size: 9px;
        }

        .platform-login-form-side {
            padding: 52px 48px;
            display: flex;
            align-items: center;
            background: #ffffff;
        }

        .platform-login-form-wrap {
            width: 100%;
            max-width: 360px;
            margin: 0 auto;
        }

        .platform-mobile-brand {
            margin-bottom: 30px;
            display: none;
            align-items: center;
            gap: 10px;
        }

        .platform-form-title {
            margin: 0;
            color: #111827;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .platform-form-subtitle {
            margin: 9px 0 26px;
            color: var(--login-muted);
            font-size: 11px;
            line-height: 1.65;
        }

        .platform-login-alert {
            margin-bottom: 18px;
            padding: 11px 13px;
            display: flex;
            align-items: flex-start;
            gap: 9px;
            border: 1px solid;
            border-radius: 10px;
            font-size: 10px;
            line-height: 1.55;
        }

        .platform-login-alert-danger {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b91c1c;
        }

        .platform-login-alert-success {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #15803d;
        }

        .platform-form-label {
            margin-bottom: 7px;
            color: #374151;
            font-size: 10px;
            font-weight: 700;
        }

        .platform-input-wrap {
            position: relative;
        }

        .platform-input-icon {
            position: absolute;
            top: 50%;
            left: 13px;
            z-index: 2;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 14px;
            pointer-events: none;
        }

        .platform-login-input {
            height: 44px;
            padding: 9px 42px 9px 39px;
            border: 1px solid var(--login-border);
            border-radius: 11px;
            background: #fafafa;
            box-shadow: none;
            font-size: 11px;
        }

        .platform-login-input:focus {
            border-color: #c4b5fd;
            background: #ffffff;
            box-shadow:
                0 0 0 3px rgba(124, 58, 237, 0.09);
        }

        .platform-password-toggle {
            width: 35px;
            height: 35px;
            padding: 0;
            position: absolute;
            top: 50%;
            right: 5px;
            z-index: 3;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #9ca3af;
            font-size: 14px;
        }

        .platform-password-toggle:hover {
            background: #f3f0ff;
            color: var(--login-primary);
        }

        .platform-login-options {
            margin: 13px 0 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .platform-remember-label {
            display: flex;
            align-items: center;
            gap: 7px;
            color: #6b7280;
            font-size: 10px;
        }

        .platform-remember-label input {
            width: 15px;
            height: 15px;
            accent-color: var(--login-primary);
        }

        .platform-forgot-link {
            color: var(--login-primary);
            font-size: 10px;
            font-weight: 600;
            text-decoration: none;
        }

        .platform-forgot-link:hover {
            color: var(--login-primary-dark);
            text-decoration: underline;
        }

        .platform-login-submit {
            width: 100%;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 11px;
            background:
                linear-gradient(
                    135deg,
                    #7c3aed,
                    #6d28d9
                );
            box-shadow:
                0 10px 24px rgba(109, 40, 217, 0.23);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            transition:
                transform 0.16s ease,
                box-shadow 0.16s ease;
        }

        .platform-login-submit:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow:
                0 14px 28px rgba(109, 40, 217, 0.30);
        }

        .platform-login-submit:disabled {
            cursor: not-allowed;
            opacity: 0.65;
        }

        .platform-security-text {
            margin-top: 20px;
            padding-top: 17px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border-top: 1px solid #f0f1f3;
            color: #9ca3af;
            font-size: 9px;
        }

        .platform-attempts {
            margin-top: 8px;
            color: #9ca3af;
            font-size: 9px;
            text-align: center;
        }

        @media (max-width: 830px) {
            .platform-login-container {
                max-width: 470px;
                min-height: auto;
                display: block;
            }

            .platform-login-visual {
                display: none;
            }

            .platform-login-form-side {
                padding: 44px 38px;
            }

            .platform-mobile-brand {
                display: flex;
            }
        }

        @media (max-width: 520px) {
            .platform-login-page {
                padding: 13px;
                align-items: flex-start;
            }

            .platform-login-container {
                margin-top: 18px;
                border-radius: 18px;
            }

            .platform-login-form-side {
                padding: 32px 23px;
            }

            .platform-form-title {
                font-size: 22px;
            }
        }
    </style>
</head>

<body>

<div class="platform-login-page">
    <div class="platform-login-container">

        <section class="platform-login-visual">

            <div class="platform-login-brand">
                <span class="platform-login-logo">
                    FP
                </span>

                <span>
                    <span class="platform-brand-name">
                        FieldPlx
                    </span>

                    <span class="platform-brand-label">
                        Platform Administration
                    </span>
                </span>
            </div>

            <div class="platform-login-intro">
                <h1>
                    Manage the entire FieldPlx platform.
                </h1>

                <p>
                    Manage tenants, subscriptions, platform users,
                    support access and platform monitoring from one
                    secure administration panel.
                </p>

                <ul class="platform-login-features">
                    <li>
                        <span class="platform-feature-icon">
                            <i class="bi bi-buildings"></i>
                        </span>

                        Manage all tenant workspaces
                    </li>

                    <li>
                        <span class="platform-feature-icon">
                            <i class="bi bi-credit-card"></i>
                        </span>

                        Control plans and subscriptions
                    </li>

                    <li>
                        <span class="platform-feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </span>

                        Secure platform administrator access
                    </li>
                </ul>
            </div>

            <div class="platform-visual-footer">
                Authorised FieldPlx administrators only.
            </div>

        </section>

        <section class="platform-login-form-side">
            <div class="platform-login-form-wrap">

                <div class="platform-mobile-brand">
                    <span class="platform-login-logo">
                        FP
                    </span>

                    <span>
                        <span class="platform-brand-name" style="color:#111827;">
                            FieldPlx
                        </span>

                        <span class="platform-brand-label" style="color:#7c3aed;">
                            Platform Administration
                        </span>
                    </span>
                </div>

                <h1 class="platform-form-title">
                    Welcome back
                </h1>

                <p class="platform-form-subtitle">
                    Sign in using your authorised platform
                    administrator account.
                </p>

                <?php if ($errorMessage !== ''): ?>
                    <div
                        class="platform-login-alert platform-login-alert-danger"
                        role="alert"
                    >
                        <i class="bi bi-exclamation-circle"></i>

                        <span>
                            <?= platformLoginEscape(
                                $errorMessage
                            ); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage !== ''): ?>
                    <div
                        class="platform-login-alert platform-login-alert-success"
                        role="alert"
                    >
                        <i class="bi bi-check-circle"></i>

                        <span>
                            <?= platformLoginEscape(
                                $successMessage
                            ); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <form
                    method="post"
                    id="platformLoginForm"
                    autocomplete="on"
                >
                    <?php csrfField(); ?>

                    <input
                        type="hidden"
                        name="redirect"
                        value="<?= platformLoginEscape(
                            $redirectUrl
                        ); ?>"
                    >

                    <div class="mb-3">
                        <label
                            for="platformEmail"
                            class="platform-form-label"
                        >
                            Email Address
                        </label>

                        <div class="platform-input-wrap">
                            <i
                                class="bi bi-envelope platform-input-icon"
                            ></i>

                            <input
                                type="email"
                                class="form-control platform-login-input"
                                id="platformEmail"
                                name="email"
                                value="<?= platformLoginEscape(
                                    $email
                                ); ?>"
                                placeholder="admin@fieldplx.com"
                                autocomplete="username"
                                maxlength="190"
                                required
                                autofocus
                                <?= $isLoginLocked
                                    ? 'disabled'
                                    : ''; ?>
                            >
                        </div>
                    </div>

                    <div>
                        <label
                            for="platformPassword"
                            class="platform-form-label"
                        >
                            Password
                        </label>

                        <div class="platform-input-wrap">
                            <i
                                class="bi bi-lock platform-input-icon"
                            ></i>

                            <input
                                type="password"
                                class="form-control platform-login-input"
                                id="platformPassword"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                                <?= $isLoginLocked
                                    ? 'disabled'
                                    : ''; ?>
                            >

                            <button
                                type="button"
                                class="platform-password-toggle"
                                id="platformPasswordToggle"
                                aria-label="Show password"
                            >
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="platform-login-options">
                        <label class="platform-remember-label">
                            <input
                                type="checkbox"
                                id="rememberEmail"
                            >

                            Remember email
                        </label>

                        <a
                            href="forgot-password.php"
                            class="platform-forgot-link"
                        >
                            Forgot password?
                        </a>
                    </div>

                    <button
                        type="submit"
                        class="platform-login-submit"
                        id="platformLoginButton"
                        <?= $isLoginLocked
                            ? 'disabled'
                            : ''; ?>
                    >
                        <i class="bi bi-box-arrow-in-right"></i>

                        <span>
                            <?= $isLoginLocked
                                ? 'Login temporarily locked'
                                : 'Sign in to Platform'; ?>
                        </span>
                    </button>

                    <?php if (
                        !$isLoginLocked &&
                        $currentLoginAttempts > 0
                    ): ?>
                        <div class="platform-attempts">
                            <?= (int) $attemptsRemaining; ?>
                            login attempt<?= $attemptsRemaining !== 1
                                ? 's'
                                : ''; ?>
                            remaining.
                        </div>
                    <?php endif; ?>
                </form>

                <div class="platform-security-text">
                    <i class="bi bi-shield-lock"></i>
                    Protected platform administrator access
                </div>

            </div>
        </section>

    </div>
</div>

<script>
(function () {
    'use strict';

    const loginForm = document.getElementById(
        'platformLoginForm'
    );

    const loginButton = document.getElementById(
        'platformLoginButton'
    );

    const emailInput = document.getElementById(
        'platformEmail'
    );

    const passwordInput = document.getElementById(
        'platformPassword'
    );

    const passwordToggle = document.getElementById(
        'platformPasswordToggle'
    );

    const rememberEmail = document.getElementById(
        'rememberEmail'
    );

    /*
    |--------------------------------------------------------------------------
    | Remember email
    |--------------------------------------------------------------------------
    */

    if (emailInput && rememberEmail) {
        const savedEmail = localStorage.getItem(
            'fieldplx_platform_email'
        );

        if (
            savedEmail &&
            emailInput.value.trim() === ''
        ) {
            emailInput.value = savedEmail;
            rememberEmail.checked = true;

            if (passwordInput) {
                passwordInput.focus();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Password visibility
    |--------------------------------------------------------------------------
    */

    if (passwordToggle && passwordInput) {
        passwordToggle.addEventListener(
            'click',
            function () {
                const showPassword =
                    passwordInput.type === 'password';

                passwordInput.type = showPassword
                    ? 'text'
                    : 'password';

                const icon =
                    passwordToggle.querySelector('i');

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

                passwordToggle.setAttribute(
                    'aria-label',
                    showPassword
                        ? 'Hide password'
                        : 'Show password'
                );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicate form submission
    |--------------------------------------------------------------------------
    */

    if (loginForm && loginButton) {
        loginForm.addEventListener(
            'submit',
            function (event) {
                if (!loginForm.checkValidity()) {
                    return;
                }

                if (
                    loginForm.dataset.submitting === '1'
                ) {
                    event.preventDefault();
                    return;
                }

                loginForm.dataset.submitting = '1';

                if (
                    rememberEmail &&
                    rememberEmail.checked &&
                    emailInput
                ) {
                    localStorage.setItem(
                        'fieldplx_platform_email',
                        emailInput.value.trim()
                    );
                } else {
                    localStorage.removeItem(
                        'fieldplx_platform_email'
                    );
                }

                loginButton.disabled = true;

                loginButton.innerHTML =
                    '<span class="spinner-border ' +
                    'spinner-border-sm" ' +
                    'aria-hidden="true"></span>' +
                    '<span>Signing in...</span>';
            }
        );
    }
})();
</script>

</body>
</html>

