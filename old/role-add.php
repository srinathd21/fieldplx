<?php
/**
 * FieldPlx - Add Role
 *
 * Upload as:
 * /public_html/role-add.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/* Keep database errors inside this page instead of an uncaught HTTP 500. */
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

/*
|--------------------------------------------------------------------------
| Authentication and permission
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('role-add.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'team.manage',
        'You do not have permission to create roles.'
    );
}

$pageTitle = 'Add Role - FieldPlx';
$activePage = 'role-add';
$searchPlaceholder = 'Search roles...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('roleAddFetchAssoc')) {
    function roleAddFetchAssoc(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                return $result->fetch_assoc();
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return null;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        if (!$stmt->fetch()) {
            return null;
        }

        $copy = array();

        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }

        return $copy;
    }
}

if (!function_exists('roleAddFetchAll')) {
    function roleAddFetchAll(mysqli_stmt $stmt)
    {
        $rows = array();

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }

                return $rows;
            }
        }

        $metadata = $stmt->result_metadata();

        if (!$metadata) {
            return $rows;
        }

        $row = array();
        $bind = array();

        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

        while ($stmt->fetch()) {
            $copy = array();

            foreach ($row as $key => $value) {
                $copy[$key] = $value;
            }

            $rows[] = $copy;
        }

        return $rows;
    }
}

if (!function_exists('roleAddOld')) {
    function roleAddOld($key, $default = '')
    {
        if (
            isset($_POST[$key]) &&
            !is_array($_POST[$key])
        ) {
            return trim((string) $_POST[$key]);
        }

        return $default;
    }
}

if (!function_exists('roleAddNullable')) {
    function roleAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('roleAddCode')) {
    function roleAddCode($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim((string) $value, '_');

        return substr($value, 0, 120);
    }
}

if (!function_exists('roleAddModuleLabel')) {
    function roleAddModuleLabel($module)
    {
        $labels = array(
            'api' => 'Developer API',
            'ai_receptionist' => 'AI Receptionist',
            'job_costing' => 'Job Costing',
            'product_services' => 'Products & Services'
        );

        if (isset($labels[$module])) {
            return $labels[$module];
        }

        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $module
            )
        );
    }
}

if (!function_exists('roleAddActionLabel')) {
    function roleAddActionLabel($action)
    {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $action
            )
        );
    }
}

if (!function_exists('roleAddCsrfToken')) {
    function roleAddCsrfToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));
            } catch (Throwable $error) {
                $_SESSION['csrf_token'] =
                    sha1(
                        uniqid(
                            (string) mt_rand(),
                            true
                        )
                    );
            }
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('roleAddVerifyCsrf')) {
    function roleAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('roleAddAuditLog')) {
    function roleAddAuditLog(
        mysqli $conn,
        $tenantId,
        $actorUserId,
        $roleId,
        array $newValue
    ) {
        $stmt = $conn->prepare("
            INSERT INTO permission_audit_logs (
                tenant_id,
                actor_user_id,
                target_user_id,
                role_id,
                action,
                old_value,
                new_value,
                ip_address,
                user_agent,
                created_at
            ) VALUES (
                ?,
                ?,
                NULL,
                ?,
                'role_created',
                NULL,
                ?,
                ?,
                ?,
                NOW()
            )
        ");

        if (!$stmt) {
            return false;
        }

        $newJson = json_encode(
            $newValue,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $ipAddress = isset($_SERVER['REMOTE_ADDR'])
            ? substr(
                (string) $_SERVER['REMOTE_ADDR'],
                0,
                80
            )
            : null;

        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(
                (string) $_SERVER['HTTP_USER_AGENT'],
                0,
                255
            )
            : null;

        $stmt->bind_param(
            'iiisss',
            $tenantId,
            $actorUserId,
            $roleId,
            $newJson,
            $ipAddress,
            $userAgent
        );

        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }
}

if (!function_exists('roleAddActivityLog')) {
    function roleAddActivityLog(
        mysqli $conn,
        $tenantId,
        $actorUserId,
        $roleId,
        $roleName,
        $roleCode,
        $permissionCount
    ) {
        $stmt = $conn->prepare("
            INSERT INTO activity_events (
                tenant_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                client_id,
                title,
                details_json,
                visible_to_client,
                created_at
            ) VALUES (
                ?,
                ?,
                'user',
                'role_created',
                'role',
                ?,
                NULL,
                ?,
                ?,
                0,
                NOW()
            )
        ");

        if (!$stmt) {
            return false;
        }

        $title = 'Role created: ' . $roleName;

        $detailsJson = json_encode(
            array(
                'role_id' => (int) $roleId,
                'role_name' => (string) $roleName,
                'role_code' => (string) $roleCode,
                'permission_count' => (int) $permissionCount
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiss',
            $tenantId,
            $actorUserId,
            $roleId,
            $title,
            $detailsJson
        );

        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }
}

/*
|--------------------------------------------------------------------------
| Load permissions
|--------------------------------------------------------------------------
*/

$permissions = array();
$permissionMap = array();
$permissionGroups = array();

$stmt = $conn->prepare("
    SELECT
        `id`,
        `module`,
        `action`,
        `code`,
        `description`,
        `created_at`
    FROM `permissions`
    ORDER BY
        `module` ASC,
        `action` ASC,
        `code` ASC
");

if (!$stmt) {
    $errors[] =
        'Unable to prepare the permission query: ' .
        $conn->error;
} else {
    if (!$stmt->execute()) {
        $errors[] =
            'Unable to load permissions: ' .
            $stmt->error;
    } else {
        $permissions = roleAddFetchAll($stmt);
    }

    $stmt->close();
}

foreach ($permissions as $permission) {
    $permissionId = (int) $permission['id'];
    $module = (string) $permission['module'];

    $permissionMap[$permissionId] = $permission;

    if (!isset($permissionGroups[$module])) {
        $permissionGroups[$module] = array();
    }

    $permissionGroups[$module][] = $permission;
}

/*
|--------------------------------------------------------------------------
| Save role
|--------------------------------------------------------------------------
*/

$selectedPermissionIds = array();

if (
    isset($_POST['permission_ids']) &&
    is_array($_POST['permission_ids'])
) {
    foreach ($_POST['permission_ids'] as $permissionId) {
        $permissionId = (int) $permissionId;

        if ($permissionId > 0) {
            $selectedPermissionIds[$permissionId] = $permissionId;
        }
    }
}

$selectedPermissionIds = array_values(
    $selectedPermissionIds
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!roleAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $name = roleAddOld('name');
    $code = roleAddCode(
        roleAddOld('code') !== ''
            ? roleAddOld('code')
            : $name
    );

    $description = roleAddOld('description');
    $isActive = isset($_POST['is_active'])
        ? 1
        : 0;

    if ($name === '') {
        $errors[] = 'Role name is required.';
    } elseif (strlen($name) > 120) {
        $errors[] =
            'Role name cannot exceed 120 characters.';
    }

    if ($code === '') {
        $errors[] = 'Role code is required.';
    } elseif (strlen($code) > 120) {
        $errors[] =
            'Role code cannot exceed 120 characters.';
    } elseif (!preg_match('/^[a-z][a-z0-9_]{0,119}$/', $code)) {
        $errors[] =
            'Role code must start with a letter and contain only lowercase letters, numbers, and underscores.';
    }

    if (strlen($description) > 5000) {
        $errors[] =
            'Role description is too long.';
    }

    foreach ($selectedPermissionIds as $permissionId) {
        if (!isset($permissionMap[$permissionId])) {
            $errors[] =
                'One or more selected permissions are invalid.';
            break;
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT
                `id`,
                `name`,
                `code`
            FROM `roles`
            WHERE `tenant_id` = ?
              AND (
                  `name` = ?
                  OR `code` = ?
              )
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the role name and code: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'iss',
                $tenantId,
                $name,
                $code
            );

            if (!$stmt->execute()) {
                $errors[] =
                    'Unable to validate the role: ' .
                    $stmt->error;
            } else {
                $duplicate = roleAddFetchAssoc($stmt);

                if ($duplicate) {
                    if (
                        strcasecmp(
                            (string) $duplicate['name'],
                            $name
                        ) === 0
                    ) {
                        $errors[] =
                            'A role with this name already exists.';
                    } else {
                        $errors[] =
                            'A role with this code already exists.';
                    }
                }
            }

            $stmt->close();
        }
    }

    if (empty($errors)) {
        $descriptionValue = roleAddNullable(
            $description
        );

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                INSERT INTO roles (
                    tenant_id,
                    name,
                    code,
                    description,
                    is_system,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    0,
                    ?,
                    NOW(),
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare role creation: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'isssi',
                $tenantId,
                $name,
                $code,
                $descriptionValue,
                $isActive
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Role could not be created: ' .
                    $stmt->error
                );
            }

            $roleId = (int) $stmt->insert_id;
            $stmt->close();

            if (!empty($selectedPermissionIds)) {
                $permissionInsert = $conn->prepare("
                    INSERT IGNORE INTO role_permissions (
                        tenant_id,
                        role_id,
                        permission_id,
                        created_at
                    ) VALUES (
                        ?,
                        ?,
                        ?,
                        NOW()
                    )
                ");

                if (!$permissionInsert) {
                    throw new Exception(
                        'Unable to prepare role permission assignment: ' .
                        $conn->error
                    );
                }

                foreach ($selectedPermissionIds as $permissionId) {
                    $permissionInsert->bind_param(
                        'iii',
                        $tenantId,
                        $roleId,
                        $permissionId
                    );

                    if (!$permissionInsert->execute()) {
                        throw new Exception(
                            'Unable to assign permission ' .
                            $permissionId .
                            ': ' .
                            $permissionInsert->error
                        );
                    }
                }

                $permissionInsert->close();
            }

            $permissionCodes = array();

            foreach ($selectedPermissionIds as $permissionId) {
                $permissionCodes[] =
                    (string) $permissionMap[$permissionId]['code'];
            }

            $conn->commit();

            /*
             * Logging is best-effort. A missing/older logging table must not
             * roll back or crash an otherwise valid role creation.
             */
            try {
                roleAddAuditLog(
                    $conn,
                    $tenantId,
                    $currentUserId,
                    $roleId,
                    array(
                        'id' => $roleId,
                        'name' => $name,
                        'code' => $code,
                        'description' => $descriptionValue,
                        'is_system' => 0,
                        'is_active' => $isActive,
                        'permission_ids' => $selectedPermissionIds,
                        'permission_codes' => $permissionCodes
                    )
                );

                roleAddActivityLog(
                    $conn,
                    $tenantId,
                    $currentUserId,
                    $roleId,
                    $name,
                    $code,
                    count($selectedPermissionIds)
                );
            } catch (Throwable $loggingError) {
                error_log(
                    'Role created but logging failed: ' .
                    $loggingError->getMessage()
                );
            }

            $_SESSION['flash_success'] =
                'Role created successfully.';

            header('Location: roles.php');
            exit;
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            error_log(
                'role-add.php error: ' .
                $error->getMessage()
            );

            $errors[] =
                'Role could not be saved. ' .
                $error->getMessage();
        }
    }
}

$csrfToken = roleAddCsrfToken();
$oldCode = roleAddOld('code');

if ($oldCode === '' && roleAddOld('name') !== '') {
    $oldCode = roleAddCode(
        roleAddOld('name')
    );
}

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.role-add-page {
    --ra-primary: #6d28d9;
    --ra-text: #111827;
    --ra-muted: #6b7280;
    --ra-border: #e5e7eb;
}

.ra-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.ra-header h1 {
    margin: 0;
    color: var(--ra-text);
    font-size: 21px;
    font-weight: 700;
}

.ra-header p {
    margin: 5px 0 0;
    color: var(--ra-muted);
    font-size: 11px;
}

.ra-back,
.ra-btn {
    min-height: 36px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 9px;
    font-family: inherit;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.ra-back,
.ra-btn.secondary {
    border: 1px solid var(--ra-border);
    background: #fff;
    color: #374151;
}

.ra-btn.primary {
    border: 0;
    background: var(--ra-primary);
    color: #fff;
    cursor: pointer;
}

.ra-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.ra-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(300px,.68fr);
    gap: 13px;
    align-items: start;
}

.ra-card {
    overflow: hidden;
    border: 1px solid var(--ra-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.ra-card + .ra-card {
    margin-top: 13px;
}

.ra-card-head {
    min-height: 46px;
    padding: 11px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.ra-card-head h2 {
    margin: 0;
    color: var(--ra-text);
    font-size: 11px;
    font-weight: 700;
}

.ra-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.ra-card-body {
    padding: 14px;
}

.ra-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.ra-field {
    min-width: 0;
}

.ra-field.full {
    grid-column: 1 / -1;
}

.ra-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.ra-required {
    color: #dc2626;
}

.ra-input,
.ra-textarea,
.ra-search {
    width: 100%;
    min-height: 38px;
    padding: 9px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 9px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 10px;
    outline: none;
}

.ra-textarea {
    min-height: 105px;
    resize: vertical;
}

.ra-input:focus,
.ra-textarea:focus,
.ra-search:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.ra-help {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.ra-switch-row {
    min-height: 38px;
    padding: 9px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border: 1px solid #e5e7eb;
    border-radius: 9px;
    background: #fafafa;
}

.ra-switch-copy strong {
    display: block;
    color: #111827;
    font-size: 9px;
}

.ra-switch-copy span {
    margin-top: 2px;
    display: block;
    color: #9ca3af;
    font-size: 8px;
}

.ra-switch {
    position: relative;
    width: 42px;
    height: 24px;
    flex: 0 0 auto;
}

.ra-switch input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.ra-switch-track {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: #d1d5db;
    cursor: pointer;
    transition: .2s ease;
}

.ra-switch-track::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 4px rgba(15,23,42,.24);
    transition: .2s ease;
}

.ra-switch input:checked + .ra-switch-track {
    background: var(--ra-primary);
}

.ra-switch input:checked + .ra-switch-track::after {
    transform: translateX(18px);
}

.ra-permission-tools {
    display: grid;
    grid-template-columns: minmax(200px,1fr) auto auto;
    gap: 7px;
    align-items: center;
}

.ra-small-btn {
    min-height: 36px;
    padding: 8px 11px;
    border: 1px solid var(--ra-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-family: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.ra-small-btn.primary {
    border-color: #ddd6fe;
    background: #faf8ff;
    color: var(--ra-primary);
}

.ra-permission-groups {
    margin-top: 11px;
    display: grid;
    gap: 10px;
}

.ra-permission-group {
    overflow: hidden;
    border: 1px solid #e7e9ee;
    border-radius: 10px;
    background: #fff;
}

.ra-permission-group.hidden {
    display: none;
}

.ra-permission-head {
    padding: 10px 11px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
    background: #fafafa;
}

.ra-permission-title {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #111827;
    font-size: 9px;
    font-weight: 700;
}

.ra-module-count {
    padding: 3px 6px;
    border-radius: 999px;
    background: #f5f3ff;
    color: #6d28d9;
    font-size: 7px;
    font-weight: 700;
}

.ra-module-toggle {
    border: 0;
    background: transparent;
    color: #6d28d9;
    font-family: inherit;
    font-size: 8px;
    font-weight: 700;
    cursor: pointer;
}

.ra-permission-list {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
}

.ra-permission-item {
    min-width: 0;
    padding: 10px 11px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    border-bottom: 1px solid #f5f5f5;
}

.ra-permission-item:nth-child(odd) {
    border-right: 1px solid #f5f5f5;
}

.ra-permission-item.hidden {
    display: none;
}

.ra-permission-item input {
    margin-top: 2px;
    accent-color: var(--ra-primary);
}

.ra-permission-copy {
    min-width: 0;
}

.ra-permission-copy strong {
    display: block;
    color: #111827;
    font-size: 9px;
    line-height: 1.4;
}

.ra-permission-code {
    margin-top: 2px;
    display: inline-block;
    color: #6d28d9;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 7px;
    font-weight: 700;
}

.ra-permission-copy span:last-child {
    margin-top: 3px;
    display: block;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.35;
}

.ra-empty-permission {
    padding: 18px;
    color: #9ca3af;
    font-size: 9px;
    text-align: center;
}

.ra-summary {
    display: grid;
    gap: 9px;
}

.ra-summary-item {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.ra-summary-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.ra-summary-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.ra-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

@media (max-width: 1050px) {
    .ra-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .ra-permission-list {
        grid-template-columns: 1fr;
    }

    .ra-permission-item:nth-child(odd) {
        border-right: 0;
    }
}

@media (max-width: 680px) {
    .ra-header {
        flex-direction: column;
    }

    .ra-grid,
    .ra-permission-tools {
        grid-template-columns: 1fr;
    }

    .ra-field.full {
        grid-column: auto;
    }

    .ra-actions {
        flex-direction: column-reverse;
    }

    .ra-btn,
    .ra-small-btn {
        width: 100%;
    }
}
</style>

<div class="role-add-page">
    <div class="ra-header">
        <div>
            <h1>Add Role</h1>
            <p>
                Create a tenant role and assign its workspace permissions.
            </p>
        </div>

        <a href="roles.php" class="ra-back">
            <i class="bi bi-arrow-left"></i>
            Back to Roles
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="ra-alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action=""
        autocomplete="off"
        id="roleAddForm"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken); ?>"
        >

        <div class="ra-layout">
            <main>
                <section class="ra-card">
                    <div class="ra-card-head">
                        <div>
                            <h2>Role Information</h2>
                            <p>
                                Enter the role name, internal code, description, and status.
                            </p>
                        </div>
                    </div>

                    <div class="ra-card-body">
                        <div class="ra-grid">
                            <div class="ra-field">
                                <label class="ra-label" for="roleName">
                                    Role Name
                                    <span class="ra-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    id="roleName"
                                    class="ra-input"
                                    maxlength="120"
                                    value="<?= e(
                                        roleAddOld('name')
                                    ); ?>"
                                    placeholder="Example: Operations Manager"
                                    required
                                >
                            </div>

                            <div class="ra-field">
                                <label class="ra-label" for="roleCode">
                                    Role Code
                                    <span class="ra-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="code"
                                    id="roleCode"
                                    class="ra-input"
                                    maxlength="120"
                                    value="<?= e($oldCode); ?>"
                                    placeholder="operations_manager"
                                    pattern="[a-z][a-z0-9_]*"
                                    required
                                >

                                <div class="ra-help">
                                    Lowercase letters, numbers, and underscores only. This code is used internally.
                                </div>
                            </div>

                            <div class="ra-field full">
                                <label class="ra-label" for="roleDescription">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    id="roleDescription"
                                    class="ra-textarea"
                                    placeholder="Describe the responsibilities and intended access for this role."
                                ><?= e(
                                    roleAddOld('description')
                                ); ?></textarea>
                            </div>

                            <div class="ra-field full">
                                <div class="ra-switch-row">
                                    <div class="ra-switch-copy">
                                        <strong>Active Role</strong>
                                        <span>
                                            Active roles can be assigned to tenant users immediately.
                                        </span>
                                    </div>

                                    <label class="ra-switch">
                                        <input
                                            type="checkbox"
                                            name="is_active"
                                            id="roleActive"
                                            value="1"
                                            <?= !isset($_POST['is_active']) &&
                                                $_SERVER['REQUEST_METHOD'] !== 'POST'
                                                    ? 'checked'
                                                    : (
                                                        isset($_POST['is_active'])
                                                            ? 'checked'
                                                            : ''
                                                    ); ?>
                                        >
                                        <span class="ra-switch-track"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ra-card" id="permissions">
                    <div class="ra-card-head">
                        <div>
                            <h2>Role Permissions</h2>
                            <p>
                                Select the actions users with this role are allowed to perform.
                            </p>
                        </div>
                    </div>

                    <div class="ra-card-body">
                        <div class="ra-permission-tools">
                            <input
                                type="search"
                                id="permissionSearch"
                                class="ra-search"
                                placeholder="Search permission, module, or code"
                            >

                            <button
                                type="button"
                                class="ra-small-btn primary"
                                id="selectAllPermissions"
                            >
                                Select All
                            </button>

                            <button
                                type="button"
                                class="ra-small-btn"
                                id="clearAllPermissions"
                            >
                                Clear All
                            </button>
                        </div>

                        <?php if (!empty($permissionGroups)): ?>
                            <div class="ra-permission-groups" id="permissionGroups">
                                <?php foreach (
                                    $permissionGroups as
                                    $module => $modulePermissions
                                ): ?>
                                    <?php
                                    $moduleSelectedCount = 0;

                                    foreach ($modulePermissions as $permission) {
                                        if (
                                            in_array(
                                                (int) $permission['id'],
                                                $selectedPermissionIds,
                                                true
                                            )
                                        ) {
                                            $moduleSelectedCount++;
                                        }
                                    }
                                    ?>

                                    <section
                                        class="ra-permission-group"
                                        data-module="<?= e(
                                            strtolower(
                                                roleAddModuleLabel($module)
                                            )
                                        ); ?>"
                                    >
                                        <div class="ra-permission-head">
                                            <div class="ra-permission-title">
                                                <?= e(
                                                    roleAddModuleLabel($module)
                                                ); ?>

                                                <span class="ra-module-count">
                                                    <span class="module-selected-count">
                                                        <?= e($moduleSelectedCount); ?>
                                                    </span>
                                                    /
                                                    <?= e(
                                                        count($modulePermissions)
                                                    ); ?>
                                                </span>
                                            </div>

                                            <button
                                                type="button"
                                                class="ra-module-toggle"
                                            >
                                                Select Module
                                            </button>
                                        </div>

                                        <div class="ra-permission-list">
                                            <?php foreach (
                                                $modulePermissions as
                                                $permission
                                            ): ?>
                                                <?php
                                                $permissionId =
                                                    (int) $permission['id'];

                                                $permissionSearchText = strtolower(
                                                    implode(
                                                        ' ',
                                                        array(
                                                            roleAddModuleLabel(
                                                                $permission['module']
                                                            ),
                                                            roleAddActionLabel(
                                                                $permission['action']
                                                            ),
                                                            $permission['code'],
                                                            $permission['description']
                                                        )
                                                    )
                                                );
                                                ?>

                                                <label
                                                    class="ra-permission-item"
                                                    data-search="<?= e(
                                                        $permissionSearchText
                                                    ); ?>"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        name="permission_ids[]"
                                                        value="<?= $permissionId; ?>"
                                                        class="permission-checkbox"
                                                        <?= in_array(
                                                            $permissionId,
                                                            $selectedPermissionIds,
                                                            true
                                                        )
                                                            ? 'checked'
                                                            : ''; ?>
                                                    >

                                                    <span class="ra-permission-copy">
                                                        <strong>
                                                            <?= e(
                                                                roleAddActionLabel(
                                                                    $permission['action']
                                                                )
                                                            ); ?>
                                                        </strong>

                                                        <span class="ra-permission-code">
                                                            <?= e(
                                                                $permission['code']
                                                            ); ?>
                                                        </span>

                                                        <span>
                                                            <?= e(
                                                                trim(
                                                                    (string) $permission['description']
                                                                ) !== ''
                                                                    ? $permission['description']
                                                                    : 'No description available.'
                                                            ); ?>
                                                        </span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>

                            <div
                                class="ra-empty-permission"
                                id="permissionEmptyState"
                                style="display:none;"
                            >
                                No permissions match your search.
                            </div>
                        <?php else: ?>
                            <div class="ra-empty-permission">
                                No permissions are configured in the database.
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </main>

            <aside>
                <section class="ra-card">
                    <div class="ra-card-head">
                        <div>
                            <h2>Role Summary</h2>
                            <p>
                                Review the role before saving.
                            </p>
                        </div>
                    </div>

                    <div class="ra-card-body">
                        <div class="ra-summary">
                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Role Name
                                </span>
                                <span
                                    class="ra-summary-value"
                                    id="summaryRoleName"
                                >
                                    Not entered
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Role Code
                                </span>
                                <span
                                    class="ra-summary-value"
                                    id="summaryRoleCode"
                                >
                                    Not entered
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Role Type
                                </span>
                                <span class="ra-summary-value">
                                    Custom Tenant Role
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Status
                                </span>
                                <span
                                    class="ra-summary-value"
                                    id="summaryRoleStatus"
                                >
                                    Active
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Selected Permissions
                                </span>
                                <span
                                    class="ra-summary-value"
                                    id="summaryPermissionCount"
                                >
                                    <?= e(
                                        count($selectedPermissionIds)
                                    ); ?>
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Permission Modules
                                </span>
                                <span
                                    class="ra-summary-value"
                                    id="summaryModuleCount"
                                >
                                    0
                                </span>
                            </div>

                            <div class="ra-summary-item">
                                <span class="ra-summary-label">
                                    Assigned Users
                                </span>
                                <span class="ra-summary-value">
                                    0 users
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ra-actions">
                        <a
                            href="roles.php"
                            class="ra-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="ra-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Save Role
                        </button>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var nameInput =
        document.getElementById('roleName');

    var codeInput =
        document.getElementById('roleCode');

    var activeInput =
        document.getElementById('roleActive');

    var searchInput =
        document.getElementById('permissionSearch');

    var selectAllButton =
        document.getElementById('selectAllPermissions');

    var clearAllButton =
        document.getElementById('clearAllPermissions');

    var codeWasManuallyEdited =
        codeInput.value.trim() !== '';

    function normalizeCode(value) {
        return value
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 120);
    }

    function allPermissionCheckboxes() {
        return Array.prototype.slice.call(
            document.querySelectorAll(
                '.permission-checkbox'
            )
        );
    }

    function visiblePermissionCheckboxes() {
        return allPermissionCheckboxes().filter(
            function (checkbox) {
                var item = checkbox.closest(
                    '.ra-permission-item'
                );

                var group = checkbox.closest(
                    '.ra-permission-group'
                );

                return item &&
                    group &&
                    !item.classList.contains('hidden') &&
                    !group.classList.contains('hidden');
            }
        );
    }

    function updateModuleCounts() {
        Array.prototype.forEach.call(
            document.querySelectorAll(
                '.ra-permission-group'
            ),
            function (group) {
                var selected = group.querySelectorAll(
                    '.permission-checkbox:checked'
                ).length;

                var countElement = group.querySelector(
                    '.module-selected-count'
                );

                if (countElement) {
                    countElement.textContent =
                        String(selected);
                }

                var toggle = group.querySelector(
                    '.ra-module-toggle'
                );

                var total = group.querySelectorAll(
                    '.permission-checkbox'
                ).length;

                if (toggle) {
                    toggle.textContent =
                        total > 0 && selected === total
                            ? 'Clear Module'
                            : 'Select Module';
                }
            }
        );
    }

    function updateSummary() {
        document.getElementById(
            'summaryRoleName'
        ).textContent =
            nameInput.value.trim() !== ''
                ? nameInput.value.trim()
                : 'Not entered';

        document.getElementById(
            'summaryRoleCode'
        ).textContent =
            codeInput.value.trim() !== ''
                ? codeInput.value.trim()
                : 'Not entered';

        document.getElementById(
            'summaryRoleStatus'
        ).textContent =
            activeInput.checked
                ? 'Active'
                : 'Inactive';

        var selected = allPermissionCheckboxes().filter(
            function (checkbox) {
                return checkbox.checked;
            }
        );

        document.getElementById(
            'summaryPermissionCount'
        ).textContent = String(selected.length);

        var selectedModules = {};

        selected.forEach(function (checkbox) {
            var group = checkbox.closest(
                '.ra-permission-group'
            );

            if (group) {
                selectedModules[group.dataset.module] = true;
            }
        });

        document.getElementById(
            'summaryModuleCount'
        ).textContent = String(
            Object.keys(selectedModules).length
        );

        updateModuleCounts();
    }

    function filterPermissions() {
        var query = searchInput.value
            .toLowerCase()
            .trim();

        var visibleGroupCount = 0;

        Array.prototype.forEach.call(
            document.querySelectorAll(
                '.ra-permission-group'
            ),
            function (group) {
                var visibleItemCount = 0;

                Array.prototype.forEach.call(
                    group.querySelectorAll(
                        '.ra-permission-item'
                    ),
                    function (item) {
                        var searchText =
                            item.dataset.search || '';

                        var visible =
                            query === '' ||
                            searchText.indexOf(query) !== -1;

                        item.classList.toggle(
                            'hidden',
                            !visible
                        );

                        if (visible) {
                            visibleItemCount++;
                        }
                    }
                );

                var groupVisible =
                    visibleItemCount > 0;

                group.classList.toggle(
                    'hidden',
                    !groupVisible
                );

                if (groupVisible) {
                    visibleGroupCount++;
                }
            }
        );

        var emptyState = document.getElementById(
            'permissionEmptyState'
        );

        if (emptyState) {
            emptyState.style.display =
                visibleGroupCount === 0
                    ? 'block'
                    : 'none';
        }
    }

    nameInput.addEventListener('input', function () {
        if (!codeWasManuallyEdited) {
            codeInput.value = normalizeCode(
                nameInput.value
            );
        }

        updateSummary();
    });

    codeInput.addEventListener('input', function () {
        codeWasManuallyEdited = true;
        codeInput.value = normalizeCode(
            codeInput.value
        );
        updateSummary();
    });

    activeInput.addEventListener(
        'change',
        updateSummary
    );

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            filterPermissions
        );
    }

    if (selectAllButton) {
        selectAllButton.addEventListener(
            'click',
            function () {
                visiblePermissionCheckboxes().forEach(
                    function (checkbox) {
                        checkbox.checked = true;
                    }
                );

                updateSummary();
            }
        );
    }

    if (clearAllButton) {
        clearAllButton.addEventListener(
            'click',
            function () {
                visiblePermissionCheckboxes().forEach(
                    function (checkbox) {
                        checkbox.checked = false;
                    }
                );

                updateSummary();
            }
        );
    }

    Array.prototype.forEach.call(
        document.querySelectorAll(
            '.permission-checkbox'
        ),
        function (checkbox) {
            checkbox.addEventListener(
                'change',
                updateSummary
            );
        }
    );

    Array.prototype.forEach.call(
        document.querySelectorAll(
            '.ra-module-toggle'
        ),
        function (button) {
            button.addEventListener(
                'click',
                function () {
                    var group = button.closest(
                        '.ra-permission-group'
                    );

                    if (!group) {
                        return;
                    }

                    var checkboxes = Array.prototype.slice.call(
                        group.querySelectorAll(
                            '.permission-checkbox'
                        )
                    );

                    var allSelected =
                        checkboxes.length > 0 &&
                        checkboxes.every(
                            function (checkbox) {
                                return checkbox.checked;
                            }
                        );

                    checkboxes.forEach(
                        function (checkbox) {
                            checkbox.checked = !allSelected;
                        }
                    );

                    updateSummary();
                }
            );
        }
    );

    document.getElementById(
        'roleAddForm'
    ).addEventListener(
        'submit',
        function (event) {
            codeInput.value = normalizeCode(
                codeInput.value
            );

            if (
                codeInput.value === '' ||
                !/^[a-z][a-z0-9_]*$/.test(
                    codeInput.value
                )
            ) {
                event.preventDefault();

                window.alert(
                    'Enter a valid role code using lowercase letters, numbers, and underscores.'
                );
            }
        }
    );

    updateSummary();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
