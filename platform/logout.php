<?php
/**
 * FieldPlx Platform - Logout
 *
 * File:
 * platform/logout.php
 *
 * Securely logs out the current platform user and
 * redirects to the platform login page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Clear platform session values
|--------------------------------------------------------------------------
*/

$platformSessionKeys = array(
    'platform_user_id',
    'platform_admin_id',
    'platform_user_name',
    'platform_user_email',
    'platform_role',
    'platform_user_role',
    'platform_user_avatar',
    'platform_logged_in',
    'platform_last_activity',
    'platform_success_message',
    'platform_error_message',
    'csrf_token'
);

foreach ($platformSessionKeys as $sessionKey) {
    if (isset($_SESSION[$sessionKey])) {
        unset($_SESSION[$sessionKey]);
    }
}

/*
|--------------------------------------------------------------------------
| Clear complete session data
|--------------------------------------------------------------------------
*/

$_SESSION = array();

/*
|--------------------------------------------------------------------------
| Remove session cookie
|--------------------------------------------------------------------------
*/

if (ini_get('session.use_cookies')) {
    $cookieParameters =
        session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        isset($cookieParameters['path'])
            ? $cookieParameters['path']
            : '/',
        isset($cookieParameters['domain'])
            ? $cookieParameters['domain']
            : '',
        !empty($cookieParameters['secure']),
        !empty($cookieParameters['httponly'])
    );
}

/*
|--------------------------------------------------------------------------
| Destroy session
|--------------------------------------------------------------------------
*/

session_destroy();

/*
|--------------------------------------------------------------------------
| Prevent browser caching
|--------------------------------------------------------------------------
*/

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

header(
    'Cache-Control: post-check=0, pre-check=0',
    false
);

header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

/*
|--------------------------------------------------------------------------
| Redirect to platform login
|--------------------------------------------------------------------------
*/

header(
    'Location: login.php?logged_out=1',
    true,
    303
);

exit;
