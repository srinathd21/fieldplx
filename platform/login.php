<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function fp_login_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fp_login_ip(): string
{
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 80) : '';
}

function fp_login_device(): string
{
    $ua = strtolower(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '');
    if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) {
        return 'mobile';
    }
    return 'desktop';
}

function fp_login_audit(PDO $pdo, string $action, string $objectType, ?int $platformUserId, ?int $objectId, array $newValues = array()): void
{
    try {
        $stmt = $pdo->prepare("\n            INSERT INTO audit_logs (\n                tenant_id, branch_id, user_id, platform_user_id,\n                action, object_type, object_id, old_values, new_values,\n                ip_address, device_type, user_agent, created_at\n            ) VALUES (\n                NULL, NULL, NULL, :platform_user_id,\n                :action, :object_type, :object_id, NULL, :new_values,\n                :ip_address, :device_type, :user_agent, NOW()\n            )\n        ");

        $stmt->execute(array(
            ':platform_user_id' => $platformUserId,
            ':action' => $action,
            ':object_type' => $objectType,
            ':object_id' => $objectId,
            ':new_values' => json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip_address' => fp_login_ip(),
            ':device_type' => fp_login_device(),
            ':user_agent' => substr(isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '', 0, 500)
        ));
    } catch (Throwable $e) {
        error_log('FieldPlx platform login audit error: ' . $e->getMessage());
    }
}

function fp_login_return_to($value): string
{
    $value = trim((string)$value);
    if ($value === '' || strpos($value, '://') !== false || strpos($value, '//') === 0 || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        return 'index.php';
    }
    return ltrim($value, '/');
}

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $userId = isset($_SESSION['platform_user_id']) ? (int)$_SESSION['platform_user_id'] : null;

    if ($userId) {
        fp_login_audit($pdo, 'LOGOUT_SUCCESS', 'platform_session', $userId, $userId, array(
            'result' => 'logged_out',
            'reason' => 'manual_logout'
        ));
    }

    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }

    session_destroy();
    header('Location: login.php?logged_out=1');
    exit;
}

if (
    !empty($_SESSION['platform_user_id']) &&
    (
        !empty($_SESSION['platform_authenticated']) ||
        !empty($_SESSION['platform_logged_in'])
    )
) {
    $existingReturnTo = isset($_GET['return_to'])
        ? fp_login_return_to($_GET['return_to'])
        : 'index.php';

    header('Location: ' . $existingReturnTo);
    exit;
}

if (empty($_SESSION['platform_login_csrf'])) {
    $_SESSION['platform_login_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$identifier = '';
$returnTo = fp_login_return_to(isset($_GET['return_to']) ? $_GET['return_to'] : (isset($_POST['return_to']) ? $_POST['return_to'] : 'index.php'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf_token']) && !is_array($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $identifier = isset($_POST['identifier']) && !is_array($_POST['identifier']) ? trim((string)$_POST['identifier']) : '';
    $password = isset($_POST['password']) && !is_array($_POST['password']) ? (string)$_POST['password'] : '';

    if ($csrf === '' || !hash_equals((string)$_SESSION['platform_login_csrf'], $csrf)) {
        $error = 'Your login session expired. Refresh the page and try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Enter your username/email and password.';
    } else {
        $stmt = $pdo->prepare("
            SELECT *
            FROM platform_users
            WHERE deleted_at IS NULL
              AND (
                    username = :username_identifier
                    OR email = :email_identifier
              )
            LIMIT 1
        ");

        $stmt->execute(array(
            ':username_identifier' => $identifier,
            ':email_identifier' => $identifier
        ));
        $user = $stmt->fetch();

        $validPassword = $user && password_verify($password, (string)$user['password_hash']);

        if (!$validPassword) {
            fp_login_audit($pdo, 'LOGIN_FAILED', 'platform_login', $user ? (int)$user['id'] : null, $user ? (int)$user['id'] : null, array(
                'identifier' => $identifier,
                'result' => 'failed',
                'reason' => 'invalid_credentials'
            ));
            $error = 'Invalid username/email or password.';
        } elseif ((string)$user['status'] !== 'active') {
            fp_login_audit($pdo, 'LOGIN_FAILED', 'platform_login', (int)$user['id'], (int)$user['id'], array(
                'identifier' => $identifier,
                'result' => 'failed',
                'reason' => 'account_' . (string)$user['status']
            ));
            $error = 'Your platform account is not active. Contact a Platform Administrator.';
        } else {
            session_regenerate_id(true);

            $fullName = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);

            $platformRoleCode = (string)$user['role_code'];
            $platformRoleName = ucwords(
                str_replace('_', ' ', $platformRoleCode)
            );

            /*
            |--------------------------------------------------------------------------
            | Platform session - compatible with existing FieldPlx auth.php
            |--------------------------------------------------------------------------
            */
            $_SESSION['platform_authenticated'] = true;
            $_SESSION['platform_logged_in'] = true;

            $_SESSION['platform_user_id'] = (int)$user['id'];
            $_SESSION['platform_user_name'] =
                $fullName !== ''
                    ? $fullName
                    : (string)$user['username'];

            $_SESSION['platform_first_name'] =
                (string)$user['first_name'];

            $_SESSION['platform_last_name'] =
                (string)$user['last_name'];

            $_SESSION['platform_username'] =
                (string)$user['username'];

            /*
             * Keep both names because older/newer Platform includes use
             * different session keys.
             */
            $_SESSION['platform_email'] =
                (string)$user['email'];

            $_SESSION['platform_user_email'] =
                (string)$user['email'];

            $_SESSION['platform_phone'] =
                isset($user['phone'])
                    ? (string)$user['phone']
                    : '';

            $_SESSION['platform_avatar_path'] =
                isset($user['avatar_path'])
                    ? (string)$user['avatar_path']
                    : '';

            $_SESSION['platform_job_title'] =
                isset($user['job_title'])
                    ? (string)$user['job_title']
                    : '';

            $_SESSION['platform_role_code'] =
                $platformRoleCode;

            $_SESSION['platform_role_name'] =
                $platformRoleName;

            $_SESSION['platform_login_at'] =
                time();

            $update = $pdo->prepare("UPDATE platform_users SET last_login_at = NOW() WHERE id = :id");
            $update->execute(array(':id' => (int)$user['id']));

            fp_login_audit($pdo, 'LOGIN_SUCCESS', 'platform_login', (int)$user['id'], (int)$user['id'], array(
                'username' => (string)$user['username'],
                'email' => (string)$user['email'],
                'role_code' => (string)$user['role_code'],
                'result' => 'success',
                'session_id' => session_id()
            ));

            unset($_SESSION['platform_login_csrf']);
            header('Location: ' . $returnTo);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Platform Login - FieldPlx</title>
    <?php if (is_file(__DIR__ . '/includes/links.php')) { require_once __DIR__ . '/includes/links.php'; } ?>
    <style>
    :root {
        --fp-primary: #12182d;
        --fp-primary-2: #1c2250;
        --fp-accent: #8b5cf6;
        --fp-accent-dark: #6d28d9;
        --fp-text: #20213f;
        --fp-muted: #6f6b8f;
        --fp-border: #ded9ef;
        --fp-soft: #f8f6ff;
        --fp-danger: #dc2626;
    }

    * {
        box-sizing: border-box
    }

    body {
        margin: 0;
        min-height: 100vh;
        background: linear-gradient(135deg, #f8f6ff 0%, #fff 48%, #f4f0ff 100%);
        color: var(--fp-text);
        font-family: "Inter", Arial, sans-serif;
    }

    .login-shell {
        min-height: 100vh;
        display: grid;
        grid-template-columns: minmax(340px, 1fr) minmax(420px, 560px)
    }

    .login-brand {
        padding: 54px clamp(32px, 6vw, 92px);
        background: linear-gradient(145deg, var(--fp-primary) 0%, var(--fp-primary-2) 60%, #2c246f 100%);
        color: #fff;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        overflow: hidden
    }

    .login-brand:before,
    .login-brand:after {
        content: "";
        position: absolute;
        border-radius: 50%;
        background: rgba(139, 92, 246, .18)
    }

    .login-brand:before {
        width: 360px;
        height: 360px;
        right: -160px;
        top: -120px
    }

    .login-brand:after {
        width: 260px;
        height: 260px;
        left: -120px;
        bottom: -100px
    }

    .brand-content {
        position: relative;
        z-index: 1;
        max-width: 620px
    }

    .fp-mark {
        width: 58px;
        height: 58px;
        border-radius: 16px;
        background: var(--fp-accent);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        font-weight: 800;
        box-shadow: 0 16px 34px rgba(0, 0, 0, .16)
    }

    .brand-title {
        margin: 28px 0 10px;
        font-size: clamp(30px, 4vw, 48px);
        font-weight: 800;
        letter-spacing: -1.2px
    }

    .brand-copy {
        max-width: 560px;
        color: #d9d1f7;
        font-size: 15px;
        line-height: 1.8
    }

    .brand-points {
        display: grid;
        gap: 13px;
        margin-top: 32px
    }

    .brand-point {
        display: flex;
        align-items: center;
        gap: 12px;
        color: #eee9ff;
        font-size: 13px
    }

    .brand-point i {
        width: 30px;
        height: 30px;
        border-radius: 9px;
        background: rgba(255, 255, 255, .09);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #c4b5fd
    }

    .login-panel {
        padding: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, .9)
    }

    .login-card {
        width: 100%;
        max-width: 430px
    }

    .mobile-mark {
        display: none;
        margin-bottom: 24px
    }

    .login-eyebrow {
        color: var(--fp-accent-dark);
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .8px
    }

    .login-title {
        margin: 8px 0 7px;
        font-size: 28px;
        font-weight: 800;
        color: #17172e
    }

    .login-subtitle {
        margin: 0 0 28px;
        color: var(--fp-muted);
        font-size: 13px;
        line-height: 1.6
    }

    .alert-box {
        margin-bottom: 18px;
        padding: 12px 14px;
        border: 1px solid #fecaca;
        border-radius: 11px;
        background: #fef2f2;
        color: #991b1b;
        font-size: 12px;
        line-height: 1.55
    }

    .success-box {
        margin-bottom: 18px;
        padding: 12px 14px;
        border: 1px solid #bbf7d0;
        border-radius: 11px;
        background: #f0fdf4;
        color: #166534;
        font-size: 12px
    }

    .field {
        margin-bottom: 17px
    }

    .field label {
        display: block;
        margin-bottom: 7px;
        color: #373252;
        font-size: 12px;
        font-weight: 700
    }

    .input-wrap {
        position: relative
    }

    .input-wrap>i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #8b82a5;
        font-size: 15px
    }

    .control {
        width: 100%;
        height: 47px;
        padding: 0 44px;
        border: 1px solid var(--fp-border);
        border-radius: 11px;
        background: #fff;
        color: var(--fp-text);
        font: inherit;
        outline: none;
        transition: .16s ease
    }

    .control.with-icon {
        padding-left: 42px
    }

    .control:focus {
        border-color: #a78bfa;
        box-shadow: 0 0 0 3px rgba(139, 92, 246, .11)
    }

    .password-toggle {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #766e91;
        cursor: pointer
    }

    .password-toggle:hover {
        background: #f4f0ff;
        color: var(--fp-accent-dark)
    }

    .login-button {
        width: 100%;
        height: 47px;
        border: 0;
        border-radius: 11px;
        background: var(--fp-accent-dark);
        color: #fff;
        font: inherit;
        font-weight: 800;
        cursor: pointer;
        box-shadow: 0 11px 24px rgba(109, 40, 217, .2);
        transition: .16s ease
    }

    .login-button:hover {
        background: #5b21b6;
        transform: translateY(-1px)
    }

    .login-note {
        margin-top: 20px;
        padding: 13px 14px;
        border: 1px solid #ebe5f7;
        border-radius: 11px;
        background: var(--fp-soft);
        color: #77708e;
        font-size: 11px;
        line-height: 1.65
    }

    .login-footer {
        margin-top: 24px;
        color: #9a94aa;
        font-size: 11px;
        text-align: center
    }

    @media(max-width:900px) {
        .login-shell {
            grid-template-columns: 1fr
        }

        .login-brand {
            display: none
        }

        .login-panel {
            min-height: 100vh;
            padding: 24px
        }

        .mobile-mark {
            display: flex
        }

        .login-card {
            max-width: 460px
        }
    }
    </style>
</head>

<body>
    <div class="login-shell">
        <section class="login-brand">
            <div class="brand-content">
                <div class="fp-mark">FP</div>
                <h1 class="brand-title">FieldPlx Platform</h1>
                <p class="brand-copy">Secure administration for tenants, subscriptions, modules, platform users, billing
                    and platform-wide configuration.</p>
                <div class="brand-points">
                    <div class="brand-point"><i class="bi bi-buildings"></i><span>Manage every FieldPlx tenant from one
                            platform.</span></div>
                    <div class="brand-point"><i class="bi bi-shield-check"></i><span>Role-based Platform User
                            access.</span></div>
                    <div class="brand-point"><i class="bi bi-envelope-check"></i><span>Platform SMTP powered account
                            communication.</span></div>
                </div>
            </div>
        </section>

        <main class="login-panel">
            <div class="login-card">
                <div class="fp-mark mobile-mark">FP</div>
                <div class="login-eyebrow">Platform Administration</div>
                <h2 class="login-title">Welcome back</h2>
                <p class="login-subtitle">Sign in with your Platform username or email address.</p>

                <?php if (isset($_GET['logged_out'])): ?>
                <div class="success-box"><i class="bi bi-check-circle me-1"></i> You have been signed out successfully.
                </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                <div class="alert-box"><i class="bi bi-exclamation-circle me-1"></i> <?= fp_login_escape($error); ?>
                </div>
                <?php endif; ?>

                <form method="post"
                    action="login.php<?= $returnTo !== 'index.php' ? '?return_to=' . rawurlencode($returnTo) : ''; ?>"
                    autocomplete="on">
                    <input type="hidden" name="csrf_token"
                        value="<?= fp_login_escape($_SESSION['platform_login_csrf']); ?>">
                    <input type="hidden" name="return_to" value="<?= fp_login_escape($returnTo); ?>">

                    <div class="field">
                        <label for="identifier">Username or Email</label>
                        <div class="input-wrap">
                            <i class="bi bi-person"></i>
                            <input class="control with-icon" type="text" id="identifier" name="identifier"
                                value="<?= fp_login_escape($identifier); ?>" placeholder="Enter username or email"
                                autocomplete="username" required autofocus>
                        </div>
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <i class="bi bi-lock"></i>
                            <input class="control with-icon" type="password" id="password" name="password"
                                placeholder="Enter password" autocomplete="current-password" required>
                            <button class="password-toggle" type="button" id="passwordToggle"
                                aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>

                    <button class="login-button" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i> Sign In to
                        Platform</button>
                </form>

                <div class="login-note"><strong>Platform access only.</strong> New Platform Users receive their
                    generated username and temporary password by email through the configured Platform SMTP account.
                </div>
                <div class="login-footer">FieldPlx Platform Administration</div>
            </div>
        </main>
    </div>
    <script>
    (function() {
        'use strict';
        var toggle = document.getElementById('passwordToggle');
        var input = document.getElementById('password');
        if (toggle && input) {
            toggle.addEventListener('click', function() {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                toggle.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
            });
        }
    })();
    </script>
</body>

</html>