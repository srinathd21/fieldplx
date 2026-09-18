<?php
ob_start();

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sessionStatusResponse($code, $data)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int)$code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

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
            'reason' => 'session_missing',
            'message' => 'Your session is no longer active.'
        )
    );
}

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
    | Remote sign-out detected
    |--------------------------------------------------------------------------
    |
    | Important: do NOT destroy the PHP session here.
    | The browser redirects to logout.php, where audit + session cleanup happen.
    |
    */
    if (
        !$device ||
        (string)$device['status'] !== 'active'
    ) {
        sessionStatusResponse(
            401,
            array(
                'success' => false,
                'authenticated' => false,
                'reason' => 'remote_logout',
                'message' => 'This session was signed out from another device.'
            )
        );
    }

    $now = time();

    $lastTouch = isset($_SESSION['tenant_device_last_touch'])
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

    sessionStatusResponse(
        500,
        array(
            'success' => false,
            'authenticated' => true,
            'temporary_error' => true,
            'message' => 'Unable to verify session right now.'
        )
    );
}
