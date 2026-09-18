<?php
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/audit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Logout reason
|--------------------------------------------------------------------------
*/
$logoutReason = isset($_GET['reason'])
    ? trim((string)$_GET['reason'])
    : 'manual_logout';

$allowedLogoutReasons = array(
    'manual_logout',
    'remote_logout',
    'session_expired',
    'session_missing',
    'idle_timeout',
    'absolute_timeout',
    'logout'
);

if (!in_array($logoutReason, $allowedLogoutReasons, true)) {
    $logoutReason = 'manual_logout';
}

if ($logoutReason === 'logout') {
    $logoutReason = 'manual_logout';
}

/*
|--------------------------------------------------------------------------
| Capture current session context
|--------------------------------------------------------------------------
*/
$tenantId = !empty($_SESSION['tenant_id'])
    ? (int)$_SESSION['tenant_id']
    : null;

$branchId = !empty($_SESSION['branch_id'])
    ? (int)$_SESSION['branch_id']
    : null;

$userId = !empty($_SESSION['tenant_user_id'])
    ? (int)$_SESSION['tenant_user_id']
    : null;

$userName = (string)($_SESSION['tenant_user_name'] ?? '');
$userEmail = (string)($_SESSION['tenant_user_email'] ?? '');
$tenantCode = (string)($_SESSION['tenant_code'] ?? '');

$loginAt = isset($_SESSION['tenant_login_at'])
    ? (int)$_SESSION['tenant_login_at']
    : null;

/*
|--------------------------------------------------------------------------
| Current PHP session/device information
|--------------------------------------------------------------------------
*/
$sessionId = session_id();

$deviceSessionId = !empty($_SESSION['tenant_device_session_id'])
    ? (int)$_SESSION['tenant_device_session_id']
    : 0;

$storedSessionHash = trim(
    (string)($_SESSION['tenant_device_session_hash'] ?? '')
);

$currentSessionHash = $sessionId !== ''
    ? hash('sha256', $sessionId)
    : '';

$deviceRevoked = false;
$deviceRevokeMethod = null;

/*
|--------------------------------------------------------------------------
| Revoke current login session in user_devices
|--------------------------------------------------------------------------
*/
if (
    isset($pdo) &&
    $pdo instanceof PDO &&
    $tenantId &&
    $userId
) {
    try {

        /* Preferred method: exact user_devices.id */
        if ($deviceSessionId > 0) {

            $stmt = $pdo->prepare("
                UPDATE user_devices
                SET
                    status = 'revoked',
                    last_seen_at = NOW(),
                    updated_at = NOW()
                WHERE id = :device_id
                  AND tenant_id = :tenant_id
                  AND user_id = :user_id
                  AND status = 'active'
                LIMIT 1
            ");

            $stmt->execute(array(
                ':device_id' => $deviceSessionId,
                ':tenant_id' => $tenantId,
                ':user_id' => $userId
            ));

            if ($stmt->rowCount() > 0) {
                $deviceRevoked = true;
                $deviceRevokeMethod = 'device_id';
            }
        }

        /* Fallback: session hash */
        if (!$deviceRevoked) {

            $sessionHash = $storedSessionHash !== ''
                ? $storedSessionHash
                : $currentSessionHash;

            if ($sessionHash !== '') {

                $stmt = $pdo->prepare("
                    UPDATE user_devices
                    SET
                        status = 'revoked',
                        last_seen_at = NOW(),
                        updated_at = NOW()
                    WHERE tenant_id = :tenant_id
                      AND user_id = :user_id
                      AND device_identifier_hash = :session_hash
                      AND status = 'active'
                ");

                $stmt->execute(array(
                    ':tenant_id' => $tenantId,
                    ':user_id' => $userId,
                    ':session_hash' => $sessionHash
                ));

                if ($stmt->rowCount() > 0) {
                    $deviceRevoked = true;
                    $deviceRevokeMethod = 'session_hash';
                }
            }
        }

    } catch (Throwable $e) {
        error_log(
            'FieldPlx logout device revoke error: ' .
            $e->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Audit logout
|--------------------------------------------------------------------------
*/
try {

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
            'result' => 'logged_out',
            'reason' => $logoutReason,
            'tenant_code' => $tenantCode,
            'user_name' => $userName,
            'user_email' => $userEmail,
            'session_hash' => $currentSessionHash,
            'device_session_id' => $deviceSessionId > 0
                ? $deviceSessionId
                : null,
            'device_session_revoked' => $deviceRevoked
                ? 1
                : 0,
            'device_revoke_method' => $deviceRevokeMethod,
            'login_at_unix' => $loginAt,
            'session_duration_seconds' => $loginAt
                ? max(0, time() - $loginAt)
                : null,
            'logout_at' => date('Y-m-d H:i:s')
        )
    );

} catch (Throwable $e) {
    error_log(
        'FieldPlx logout audit error: ' .
        $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| Clear PHP session
|--------------------------------------------------------------------------
*/
$_SESSION = array();

if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

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

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

/*
|--------------------------------------------------------------------------
| Redirect to login with reason
|--------------------------------------------------------------------------
*/
if ($logoutReason === 'remote_logout') {
    header('Location: login.php?reason=remote_logout');
} elseif ($logoutReason === 'idle_timeout') {
    header('Location: login.php?reason=idle_timeout');
} elseif ($logoutReason === 'absolute_timeout') {
    header('Location: login.php?reason=absolute_timeout');
} elseif (
    $logoutReason === 'session_expired' ||
    $logoutReason === 'session_missing'
) {
    header('Location: login.php?reason=session_expired');
} else {
    header('Location: login.php?reason=logout');
}

exit;
