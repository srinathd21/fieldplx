<?php
/**
 * FieldPlx - Location Services API
 * File: business/api/location-services.php
 * Compatible with PHP 7.2+ / MariaDB 11.x
 */

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/auth.php';

if (file_exists(__DIR__ . '/../includes/audit.php')) {
    require_once __DIR__ . '/../includes/audit.php';
}

function lsapi_out($status, $success, $message, $extra = array())
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int)$status);
    echo json_encode(array_merge(array(
        'success' => (bool)$success,
        'message' => (string)$message
    ), $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lsapi_post($key, $default = '')
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) return $default;
    return trim((string)$_POST[$key]);
}

function lsapi_table(PDO $pdo, $table)
{
    $q = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $q->execute(array(':table_name' => $table));
    return ((int)$q->fetchColumn() > 0);
}

function lsapi_csrf()
{
    $posted = lsapi_post('csrf_token');
    $saved = isset($_SESSION['location_services_csrf'])
        ? (string)$_SESSION['location_services_csrf']
        : '';

    if ($posted === '' || $saved === '' || !hash_equals($saved, $posted)) {
        lsapi_out(419, false, 'Your form session expired. Refresh the page and try again.');
    }
}

function lsapi_audit(PDO $pdo, $tenantId, $branchId, $userId, $action, $values)
{
    if (function_exists('tenantAuditLog')) {
        try {
            tenantAuditLog(
                $pdo,
                $action,
                $tenantId,
                $branchId > 0 ? $branchId : null,
                $userId,
                'location_service_settings',
                $tenantId,
                null,
                $values
            );
        } catch (Throwable $ignore) {
        }
    }
}

$tenantId = isset($currentTenantId)
    ? (int)$currentTenantId
    : (isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0);

$userId = isset($currentTenantUserId)
    ? (int)$currentTenantUserId
    : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0));

$branchId = isset($currentBranchId)
    ? (int)$currentBranchId
    : (isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0);

if ($tenantId <= 0 || $userId <= 0) {
    lsapi_out(401, false, 'Tenant session is not available.');
}

if (!lsapi_table($pdo, 'tenant_location_service_settings')) {
    lsapi_out(503, false, 'Location Services database migration has not been installed.');
}

$action = lsapi_post('action');

try {
    if ($action !== 'save') {
        lsapi_out(400, false, 'Invalid Location Services action.');
    }

    lsapi_csrf();

    $enabled = lsapi_post('location_timers_enabled') === '1' ? 1 : 0;
    $timerMode = lsapi_post('timer_mode', 'automatic');

    if (!in_array($timerMode, array('automatic','reminder'), true)) {
        lsapi_out(422, false, 'Select a valid location timer mode.');
    }

    $q = $pdo->prepare("
        SELECT location_timers_enabled, timer_mode
        FROM tenant_location_service_settings
        WHERE tenant_id = :tenant_id
        LIMIT 1
    ");
    $q->execute(array(':tenant_id' => $tenantId));
    $old = $q->fetch(PDO::FETCH_ASSOC);

    $q = $pdo->prepare("
        INSERT INTO tenant_location_service_settings (
            tenant_id,
            location_timers_enabled,
            timer_mode,
            updated_by,
            created_at,
            updated_at
        ) VALUES (
            :tenant_id,
            :location_timers_enabled,
            :timer_mode,
            :updated_by,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            location_timers_enabled = VALUES(location_timers_enabled),
            timer_mode = VALUES(timer_mode),
            updated_by = VALUES(updated_by),
            updated_at = NOW()
    ");

    $q->execute(array(
        ':tenant_id' => $tenantId,
        ':location_timers_enabled' => $enabled,
        ':timer_mode' => $timerMode,
        ':updated_by' => $userId
    ));

    lsapi_audit(
        $pdo,
        $tenantId,
        $branchId,
        $userId,
        'LOCATION_SERVICE_SETTINGS_UPDATED',
        array(
            'old' => $old ?: null,
            'new' => array(
                'location_timers_enabled' => $enabled,
                'timer_mode' => $timerMode
            )
        )
    );

    lsapi_out(200, true, 'Location Services updated successfully.', array(
        'settings' => array(
            'location_timers_enabled' => $enabled,
            'timer_mode' => $timerMode
        )
    ));
} catch (PDOException $e) {
    error_log('FieldPlx Location Services API database error: ' . $e->getMessage());
    lsapi_out(500, false, 'Unable to save Location Services.');
} catch (Throwable $e) {
    error_log('FieldPlx Location Services API error: ' . $e->getMessage());
    lsapi_out(500, false, 'Unable to save Location Services.');
}
