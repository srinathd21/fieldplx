<?php
declare(strict_types=1);

/**
 * FieldPlx Platform Logout
 * PHP 7.2+
 *
 * - Records a best-effort platform logout audit entry.
 * - Clears every platform/session value.
 * - Removes the PHP session cookie.
 * - Destroys the server-side session.
 * - Redirects back to the Platform Login page.
 */

require_once __DIR__ . '/includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Prevent the logout response from being cached. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function fp_logout_ip(): string
{
    return isset($_SERVER['REMOTE_ADDR'])
        ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 80)
        : '';
}

function fp_logout_device(): string
{
    $userAgent = strtolower(
        isset($_SERVER['HTTP_USER_AGENT'])
            ? (string) $_SERVER['HTTP_USER_AGENT']
            : ''
    );

    if (
        strpos($userAgent, 'mobile') !== false ||
        strpos($userAgent, 'android') !== false ||
        strpos($userAgent, 'iphone') !== false ||
        strpos($userAgent, 'ipad') !== false
    ) {
        return 'mobile';
    }

    return 'desktop';
}

function fp_logout_audit(
    PDO $pdo,
    int $platformUserId,
    array $details
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                tenant_id,
                branch_id,
                user_id,
                platform_user_id,
                action,
                object_type,
                object_id,
                old_values,
                new_values,
                ip_address,
                device_type,
                user_agent,
                created_at
            ) VALUES (
                NULL,
                NULL,
                NULL,
                :platform_user_id,
                'LOGOUT_SUCCESS',
                'platform_session',
                :object_id,
                NULL,
                :new_values,
                :ip_address,
                :device_type,
                :user_agent,
                NOW()
            )
        ");

        $stmt->execute(array(
            ':platform_user_id' => $platformUserId,
            ':object_id' => $platformUserId,
            ':new_values' => json_encode(
                $details,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ),
            ':ip_address' => fp_logout_ip(),
            ':device_type' => fp_logout_device(),
            ':user_agent' => substr(
                isset($_SERVER['HTTP_USER_AGENT'])
                    ? (string) $_SERVER['HTTP_USER_AGENT']
                    : '',
                0,
                500
            )
        ));
    } catch (Throwable $e) {
        /* Logout must continue even if audit logging fails. */
        error_log(
            'FieldPlx platform logout audit error: ' .
            $e->getMessage()
        );
    }
}

$platformUserId = isset($_SESSION['platform_user_id'])
    ? (int) $_SESSION['platform_user_id']
    : 0;

$platformUsername = isset($_SESSION['platform_username'])
    ? (string) $_SESSION['platform_username']
    : '';

$platformUserEmail = isset($_SESSION['platform_user_email'])
    ? (string) $_SESSION['platform_user_email']
    : '';

$platformRoleCode = isset($_SESSION['platform_role_code'])
    ? (string) $_SESSION['platform_role_code']
    : '';

$loginAt = isset($_SESSION['platform_login_at'])
    ? (int) $_SESSION['platform_login_at']
    : 0;

$sessionIdBeforeDestroy = session_id();

if ($platformUserId > 0) {
    $logoutDetails = array(
        'result' => 'logged_out',
        'reason' => 'manual_logout',
        'username' => $platformUsername,
        'email' => $platformUserEmail,
        'role_code' => $platformRoleCode,
        'session_id' => $sessionIdBeforeDestroy,
        'logout_at' => date('Y-m-d H:i:s')
    );

    if ($loginAt > 0) {
        $logoutDetails['login_at_unix'] = $loginAt;
        $logoutDetails['session_duration_seconds'] = max(
            0,
            time() - $loginAt
        );
    }

    fp_logout_audit(
        $pdo,
        $platformUserId,
        $logoutDetails
    );
}

/* Remove all session values. */
$_SESSION = array();

/* Remove PHP session cookie from the browser. */
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        isset($params['path']) ? $params['path'] : '/',
        isset($params['domain']) ? $params['domain'] : '',
        !empty($params['secure']),
        !empty($params['httponly'])
    );
}

/* Destroy the server-side session. */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: login.php?logged_out=1');
exit;
