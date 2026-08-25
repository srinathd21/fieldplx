<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Tenant Audit Helper
|--------------------------------------------------------------------------
|
| Stores authentication/security events in the existing audit_logs table.
| Passwords, password hashes and other credentials are NEVER logged.
|
*/

if (!function_exists('tenantAuditClientIp')) {

    function tenantAuditClientIp()
    {
        /*
         * REMOTE_ADDR is intentionally used as the trusted base value.
         * Forwarded headers should only be trusted when your reverse proxy
         * configuration explicitly guarantees them.
         */
        return isset($_SERVER['REMOTE_ADDR'])
            ? substr(
                trim((string)$_SERVER['REMOTE_ADDR']),
                0,
                80
            )
            : null;
    }

    function tenantAuditUserAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(
                trim((string)$_SERVER['HTTP_USER_AGENT']),
                0,
                500
            )
            : null;
    }

    function tenantAuditDeviceType()
    {
        $ua =
            strtolower(
                (string)(
                    $_SERVER['HTTP_USER_AGENT']
                    ?? ''
                )
            );

        if ($ua === '') {
            return 'unknown';
        }

        if (
            strpos($ua, 'ipad') !== false ||
            strpos($ua, 'tablet') !== false ||
            strpos($ua, 'kindle') !== false
        ) {
            return 'tablet';
        }

        if (
            strpos($ua, 'mobile') !== false ||
            strpos($ua, 'iphone') !== false ||
            strpos($ua, 'android') !== false
        ) {
            return 'mobile';
        }

        return 'desktop';
    }

    function tenantAuditJson($value)
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            $value = array(
                'value' => $value
            );
        }

        /*
         * Explicit credential defense.
         */
        foreach (
            array(
                'password',
                'password_hash',
                'current_password',
                'new_password',
                'confirm_password',
                'token',
                'csrf_token'
            ) as $sensitiveKey
        ) {
            if (array_key_exists($sensitiveKey, $value)) {
                unset($value[$sensitiveKey]);
            }
        }

        $json =
            json_encode(
                $value,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        return $json === false
            ? null
            : $json;
    }

    function tenantAuditTableExists(PDO $pdo)
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'audit_logs'
            ");

            $stmt->execute();

            $exists =
                ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            $exists = false;
        }

        return $exists;
    }

    function tenantAuditLog(
        PDO $pdo,
        $action,
        $tenantId = null,
        $branchId = null,
        $userId = null,
        $objectType = 'authentication',
        $objectId = null,
        $oldValues = null,
        $newValues = null
    ) {
        /*
         * Audit failure must never break authentication/logout.
         */
        try {

            if (!tenantAuditTableExists($pdo)) {
                return false;
            }

            $tenantId =
                $tenantId !== null &&
                (int)$tenantId > 0
                    ? (int)$tenantId
                    : null;

            $branchId =
                $branchId !== null &&
                (int)$branchId > 0
                    ? (int)$branchId
                    : null;

            $userId =
                $userId !== null &&
                (int)$userId > 0
                    ? (int)$userId
                    : null;

            $objectId =
                $objectId !== null &&
                (int)$objectId > 0
                    ? (int)$objectId
                    : null;

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
                    user_agent
                ) VALUES (
                    :tenant_id,
                    :branch_id,
                    :user_id,
                    NULL,
                    :action,
                    :object_type,
                    :object_id,
                    :old_values,
                    :new_values,
                    :ip_address,
                    :device_type,
                    :user_agent
                )
            ");

            $stmt->execute(array(
                ':tenant_id' =>
                    $tenantId,
                ':branch_id' =>
                    $branchId,
                ':user_id' =>
                    $userId,
                ':action' =>
                    substr(
                        (string)$action,
                        0,
                        120
                    ),
                ':object_type' =>
                    substr(
                        (string)$objectType,
                        0,
                        80
                    ),
                ':object_id' =>
                    $objectId,
                ':old_values' =>
                    tenantAuditJson(
                        $oldValues
                    ),
                ':new_values' =>
                    tenantAuditJson(
                        $newValues
                    ),
                ':ip_address' =>
                    tenantAuditClientIp(),
                ':device_type' =>
                    tenantAuditDeviceType(),
                ':user_agent' =>
                    tenantAuditUserAgent()
            ));

            return true;

        } catch (Throwable $e) {

            error_log(
                'FieldPlx tenant audit log error: ' .
                $e->getMessage()
            );

            return false;
        }
    }
}
