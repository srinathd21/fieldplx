<?php
declare(strict_types=1);

ob_start();

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function pm_post(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }

    return trim((string)$_POST[$key]);
}

function pm_json(
    int $status,
    bool $success,
    string $message,
    array $extra = array()
): void {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            array(
                'success' => $success,
                'message' => $message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function pm_validate_plan(PDO $pdo, int $planId): void
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM plans
        WHERE id = :id
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute(array(':id' => $planId));

    if (!$stmt->fetchColumn()) {
        pm_json(404, false, 'Plan not found.');
    }
}

function pm_get_plan_tenants(PDO $pdo, int $planId): array
{
    $stmt = $pdo->prepare("
        SELECT s.tenant_id
        FROM subscriptions s
        INNER JOIN (
            SELECT
                tenant_id,
                MAX(id) AS latest_subscription_id
            FROM subscriptions
            WHERE deleted_at IS NULL
            GROUP BY tenant_id
        ) latest
            ON latest.latest_subscription_id = s.id
        WHERE s.plan_id = :plan_id
          AND s.deleted_at IS NULL
          AND s.status IN ('trial', 'active')
          AND s.start_date <= CURDATE()
          AND (
                s.expiry_date IS NULL
                OR s.expiry_date >= CURDATE()
          )
          AND (
                s.status <> 'trial'
                OR s.trial_end_date IS NULL
                OR s.trial_end_date >= CURDATE()
          )
        ORDER BY s.tenant_id
    ");

    $stmt->execute(array(':plan_id' => $planId));

    return array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
}

function pm_get_module_permissions(PDO $pdo, array $moduleIds): array
{
    if (empty($moduleIds)) {
        return array();
    }

    $moduleIds = array_values(
        array_unique(
            array_map('intval', $moduleIds)
        )
    );

    $moduleIds = array_values(
        array_filter(
            $moduleIds,
            function ($id) {
                return $id > 0;
            }
        )
    );

    if (empty($moduleIds)) {
        return array();
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($moduleIds), '?')
    );

    $stmt = $pdo->prepare("
        SELECT
            id,
            module_id,
            action_code,
            permission_code
        FROM permissions
        WHERE module_id IN ($placeholders)
        ORDER BY module_id, id
    ");

    $stmt->execute($moduleIds);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pm_sync_admin_permissions_for_modules(
    PDO $pdo,
    int $planId,
    array $moduleIds
): array {
    $result = array(
        'tenant_count' => 0,
        'admin_role_count' => 0,
        'admin_user_count' => 0,
        'permission_count' => 0,
        'role_permission_grants' => 0,
        'user_permission_grants' => 0
    );

    if (empty($moduleIds)) {
        return $result;
    }

    $permissions = pm_get_module_permissions($pdo, $moduleIds);

    if (empty($permissions)) {
        return $result;
    }

    $result['permission_count'] = count($permissions);

    $tenantIds = pm_get_plan_tenants($pdo, $planId);

    if (empty($tenantIds)) {
        return $result;
    }

    $result['tenant_count'] = count($tenantIds);

    $adminRolesStmt = $pdo->prepare("
        SELECT id
        FROM roles
        WHERE tenant_id = :tenant_id
          AND is_admin = 1
          AND status = 'active'
        ORDER BY id
    ");

    $adminUsersStmt = $pdo->prepare("
        SELECT DISTINCT u.id
        FROM users u
        LEFT JOIN roles r
            ON r.id = u.role_id
           AND r.tenant_id = u.tenant_id
        WHERE u.tenant_id = :tenant_id
          AND u.deleted_at IS NULL
          AND (
                u.is_tenant_admin = 1
                OR (
                    r.id IS NOT NULL
                    AND r.is_admin = 1
                )
          )
        ORDER BY u.id
    ");

    $rolePermissionStmt = $pdo->prepare("
        INSERT INTO role_permissions (
            tenant_id,
            role_id,
            permission_id,
            access_type,
            created_at
        )
        VALUES (
            :tenant_id,
            :role_id,
            :permission_id,
            'allow',
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            tenant_id = VALUES(tenant_id),
            access_type = 'allow'
    ");

    $userPermissionStmt = $pdo->prepare("
        INSERT INTO user_permissions (
            tenant_id,
            user_id,
            permission_id,
            access_type,
            created_at
        )
        VALUES (
            :tenant_id,
            :user_id,
            :permission_id,
            'allow',
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            tenant_id = VALUES(tenant_id),
            access_type = 'allow'
    ");

    $seenRoles = array();
    $seenUsers = array();

    foreach ($tenantIds as $tenantId) {
        $tenantId = (int)$tenantId;

        if ($tenantId <= 0) {
            continue;
        }

        $adminRolesStmt->execute(
            array(':tenant_id' => $tenantId)
        );

        $adminRoleIds = array_map(
            'intval',
            $adminRolesStmt->fetchAll(PDO::FETCH_COLUMN)
        );

        foreach ($adminRoleIds as $roleId) {
            if ($roleId <= 0) {
                continue;
            }

            $seenRoles[$roleId] = true;

            foreach ($permissions as $permission) {
                $permissionId = (int)$permission['id'];

                if ($permissionId <= 0) {
                    continue;
                }

                $rolePermissionStmt->execute(
                    array(
                        ':tenant_id' => $tenantId,
                        ':role_id' => $roleId,
                        ':permission_id' => $permissionId
                    )
                );

                $result['role_permission_grants']++;
            }
        }

        $adminUsersStmt->execute(
            array(':tenant_id' => $tenantId)
        );

        $adminUserIds = array_map(
            'intval',
            $adminUsersStmt->fetchAll(PDO::FETCH_COLUMN)
        );

        foreach ($adminUserIds as $userId) {
            if ($userId <= 0) {
                continue;
            }

            $seenUsers[$userId] = true;

            foreach ($permissions as $permission) {
                $permissionId = (int)$permission['id'];

                if ($permissionId <= 0) {
                    continue;
                }

                $userPermissionStmt->execute(
                    array(
                        ':tenant_id' => $tenantId,
                        ':user_id' => $userId,
                        ':permission_id' => $permissionId
                    )
                );

                $result['user_permission_grants']++;
            }
        }
    }

    $result['admin_role_count'] = count($seenRoles);
    $result['admin_user_count'] = count($seenUsers);

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pm_json(405, false, 'Method not allowed.');
}

$csrf = pm_post('csrf_token');

if (
    empty($_SESSION['plan_modules_csrf']) ||
    !is_string($_SESSION['plan_modules_csrf']) ||
    $csrf === '' ||
    !hash_equals($_SESSION['plan_modules_csrf'], $csrf)
) {
    pm_json(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action = pm_post('action');

try {

    if ($action === 'save_plan_modules') {

        $planId = (int)pm_post('plan_id', '0');

        if ($planId <= 0) {
            pm_json(422, false, 'Please select a plan.');
        }

        pm_validate_plan($pdo, $planId);

        $selectedIds =
            isset($_POST['module_ids']) &&
            is_array($_POST['module_ids'])
                ? $_POST['module_ids']
                : array();

        $cleanIds = array();

        foreach ($selectedIds as $value) {
            $moduleId = (int)$value;

            if ($moduleId > 0) {
                $cleanIds[$moduleId] = $moduleId;
            }
        }

        $cleanIds = array_values($cleanIds);

        $moduleRows = $pdo->query("
            SELECT
                id,
                parent_id,
                is_active
            FROM modules
        ")->fetchAll(PDO::FETCH_ASSOC);

        $moduleMap = array();

        foreach ($moduleRows as $row) {
            $moduleMap[(int)$row['id']] = $row;
        }

        $finalIds = array();

        foreach ($cleanIds as $moduleId) {
            if (!isset($moduleMap[$moduleId])) {
                continue;
            }

            if ((int)$moduleMap[$moduleId]['is_active'] !== 1) {
                continue;
            }

            $finalIds[$moduleId] = $moduleId;

            $parentId = (int)(
                $moduleMap[$moduleId]['parent_id'] ?: 0
            );

            $visitedParents = array();

            while (
                $parentId > 0 &&
                isset($moduleMap[$parentId]) &&
                !isset($visitedParents[$parentId])
            ) {
                $visitedParents[$parentId] = true;

                if ((int)$moduleMap[$parentId]['is_active'] !== 1) {
                    break;
                }

                $finalIds[$parentId] = $parentId;

                $parentId = (int)(
                    $moduleMap[$parentId]['parent_id'] ?: 0
                );
            }
        }

        $finalIds = array_values($finalIds);

        $pdo->beginTransaction();

        $existingEnabledStmt = $pdo->prepare("
            SELECT module_id
            FROM plan_modules
            WHERE plan_id = :plan_id
              AND is_enabled = 1
        ");

        $existingEnabledStmt->execute(
            array(':plan_id' => $planId)
        );

        $existingEnabledIds = array_map(
            'intval',
            $existingEnabledStmt->fetchAll(PDO::FETCH_COLUMN)
        );

        $newlyEnabledIds = array_values(
            array_diff(
                $finalIds,
                $existingEnabledIds
            )
        );

        $upsert = $pdo->prepare("
            INSERT INTO plan_modules (
                plan_id,
                module_id,
                is_enabled
            )
            VALUES (
                :plan_id,
                :module_id,
                :is_enabled
            )
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled)
        ");

        foreach ($moduleMap as $moduleId => $moduleRow) {
            $enabled = in_array(
                (int)$moduleId,
                $finalIds,
                true
            ) ? 1 : 0;

            $upsert->execute(
                array(
                    ':plan_id' => $planId,
                    ':module_id' => (int)$moduleId,
                    ':is_enabled' => $enabled
                )
            );
        }

        $permissionSync = pm_sync_admin_permissions_for_modules(
            $pdo,
            $planId,
            $newlyEnabledIds
        );

        $pdo->commit();

        $message = 'Plan modules updated successfully.';

        if (!empty($newlyEnabledIds)) {
            if ((int)$permissionSync['permission_count'] > 0) {
                $message .=
                    ' Full permissions for the newly enabled module' .
                    (count($newlyEnabledIds) === 1 ? '' : 's') .
                    ' were automatically assigned to ' .
                    (int)$permissionSync['admin_user_count'] .
                    ' existing admin user' .
                    ((int)$permissionSync['admin_user_count'] === 1 ? '' : 's') .
                    ' across ' .
                    (int)$permissionSync['tenant_count'] .
                    ' tenant' .
                    ((int)$permissionSync['tenant_count'] === 1 ? '' : 's') .
                    '.';
            } else {
                $message .=
                    ' Newly enabled modules currently have no permission records to assign.';
            }
        }

        pm_json(
            200,
            true,
            $message,
            array(
                'enabled_count' => count($finalIds),
                'newly_enabled_count' => count($newlyEnabledIds),
                'newly_enabled_module_ids' => array_values(
                    array_map('intval', $newlyEnabledIds)
                ),
                'permission_sync' => $permissionSync
            )
        );
    }

    pm_json(400, false, 'Invalid action.');

} catch (Throwable $e) {

    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    error_log(
        'FieldPlx Plan Modules API Error: ' .
        $e->getMessage()
    );

    pm_json(
        500,
        false,
        'Unable to complete the plan module action.'
    );
}
