<?php

ob_start();

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sessionStatusResponse($code, $data)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| PHP session must exist
|--------------------------------------------------------------------------
*/

$tenantId = !empty($_SESSION['tenant_id'])
    ? (int)$_SESSION['tenant_id']
    : 0;

$userId = !empty($_SESSION['tenant_user_id'])
    ? (int)$_SESSION['tenant_user_id']
    : 0;

$deviceId = !empty($_SESSION['tenant_device_session_id'])
    ? (int)$_SESSION['tenant_device_session_id']
    : 0;

if (
    $tenantId <= 0 ||
    $userId <= 0 ||
    $deviceId <= 0
) {
    sessionStatusResponse(
        401,
        array(
            'success' => false,
            'authenticated' => false,
            'reason' => 'session_missing'
        )
    );
}

/*
|--------------------------------------------------------------------------
| Check current device session
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare("
        SELECT
            id,
            status,
            last_seen_at
        FROM user_devices
        WHERE id = :device_id
          AND tenant_id = :tenant_id
          AND user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute(array(
        ':device_id' => $deviceId,
        ':tenant_id' => $tenantId,
        ':user_id' => $userId
    ));

    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Device was remotely revoked
    |--------------------------------------------------------------------------
    */

    if (
        !$device ||
        (string)$device['status'] !== 'active'
    ) {

        /*
         * Destroy this browser's PHP session.
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

        sessionStatusResponse(
            401,
            array(
                'success' => false,
                'authenticated' => false,
                'reason' => 'remote_logout',
                'message' =>
                    'This session was signed out from another device.'
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Session still active
    |--------------------------------------------------------------------------
    |
    | Update last_seen_at at most once per minute.
    |
    */

    $now = time();

    $lastTouch =
        isset($_SESSION['tenant_device_last_touch'])
            ? (int)$_SESSION['tenant_device_last_touch']
            : 0;

    if (
        $lastTouch <= 0 ||
        ($now - $lastTouch) >= 60
    ) {

        $update = $pdo->prepare("
            UPDATE user_devices
            SET
                last_seen_at = NOW(),
                updated_at = NOW()
            WHERE id = :device_id
              AND tenant_id = :tenant_id
              AND user_id = :user_id
              AND status = 'active'
            LIMIT 1
        ");

        $update->execute(array(
            ':device_id' => $deviceId,
            ':tenant_id' => $tenantId,
            ':user_id' => $userId
        ));

        $_SESSION['tenant_device_last_touch'] = $now;
    }

    sessionStatusResponse(
        200,
        array(
            'success' => true,
            'authenticated' => true,
            'device_id' => $deviceId
        )
    );

} catch (Throwable $e) {

    error_log(
        'FieldPlx session-status error: ' .
        $e->getMessage()
    );

    /*
     * Don't log somebody out because of a temporary DB error.
     */
    sessionStatusResponse(
        500,
        array(
            'success' => false,
            'authenticated' => true,
            'temporary_error' => true
        )
    );
}