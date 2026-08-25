<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Capture session context before destroying it
|--------------------------------------------------------------------------
*/
$tenantId =
    !empty($_SESSION['tenant_id'])
        ? (int)$_SESSION['tenant_id']
        : null;

$branchId =
    !empty($_SESSION['branch_id'])
        ? (int)$_SESSION['branch_id']
        : null;

$userId =
    !empty($_SESSION['tenant_user_id'])
        ? (int)$_SESSION['tenant_user_id']
        : null;

$userName =
    (string)(
        $_SESSION['tenant_user_name']
        ?? ''
    );

$userEmail =
    (string)(
        $_SESSION['tenant_user_email']
        ?? ''
    );

$tenantCode =
    (string)(
        $_SESSION['tenant_code']
        ?? ''
    );

$loginAt =
    isset($_SESSION['tenant_login_at'])
        ? (int)$_SESSION['tenant_login_at']
        : null;

$sessionId =
    session_id();

/*
|--------------------------------------------------------------------------
| Audit manual logout
|--------------------------------------------------------------------------
*/
tenantAuditLog(
    $pdo,
    'LOGOUT_SUCCESS',
    $tenantId,
    $branchId,
    $userId,
    'tenant_session',
    $userId,
    null,
    array(
        'result' =>
            'logged_out',
        'reason' =>
            'manual_logout',
        'tenant_code' =>
            $tenantCode,
        'user_name' =>
            $userName,
        'user_email' =>
            $userEmail,
        'session_id' =>
            $sessionId,
        'login_at_unix' =>
            $loginAt,
        'session_duration_seconds' =>
            $loginAt
                ? max(
                    0,
                    time() - $loginAt
                )
                : null,
        'logout_at' =>
            date('Y-m-d H:i:s')
    )
);

/*
|--------------------------------------------------------------------------
| Destroy the session
|--------------------------------------------------------------------------
*/
$_SESSION = array();

if (ini_get('session.use_cookies')) {

    $params =
        session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header(
    'Location: login.php?reason=logout'
);

exit;
