<?php
/**
 * FieldPlx - Roles & Permissions
 *
 * Upload as:
 * /public_html/roles.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and permission
|--------------------------------------------------------------------------
|
| The shared sidebar exposes Roles & Permissions through team.manage.
| This page uses the same permission so the sidebar and page access remain
| consistent.
|
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('roles.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'team.manage',
        'You do not have permission to manage roles and permissions.'
    );
}

$pageTitle = 'Roles & Permissions - FieldPlx';
$activePage = 'roles';
$searchPlaceholder = 'Search roles...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$currentRoleId = 0;

if (function_exists('currentRoleId')) {
    $currentRoleId = (int) currentRoleId();
} elseif (!empty($_SESSION['role_id'])) {
    $currentRoleId = (int) $_SESSION['role_id'];
}

$canManage = function_exists('hasPermission')
    ? hasPermission('team.manage')
    : true;

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('rolesFetchAssoc')) {
    function rolesFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('rolesFetchAll')) {
    function rolesFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('rolesBindParams')) {
    function rolesBindParams(
        mysqli_stmt $stmt,
        $types,
        array &$params
    ) {
        if ($types === '' || empty($params)) {
            return true;
        }

        $arguments = array($types);

        foreach ($params as $key => $value) {
            $arguments[] = &$params[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('rolesPost')) {
    function rolesPost($key, $default = '')
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

if (!function_exists('rolesDate')) {
    function rolesDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y', $timestamp)
            : '—';
    }
}

if (!function_exists('rolesDateTime')) {
    function rolesDateTime($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('rolesQueryString')) {
    function rolesQueryString(array $overrides = array())
    {
        $query = $_GET;

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return http_build_query($query);
    }
}

if (!function_exists('rolesCsrfToken')) {
    function rolesCsrfToken()
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

if (!function_exists('rolesVerifyCsrf')) {
    function rolesVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('rolesLoadRole')) {
    function rolesLoadRole(
        mysqli $conn,
        $tenantId,
        $roleId,
        $forUpdate = false
    ) {
        $sql = "
            SELECT
                r.id,
                r.tenant_id,
                r.name,
                r.code,
                r.description,
                r.is_system,
                r.is_active,
                r.created_at,
                r.updated_at,

                (
                    SELECT COUNT(*)
                    FROM users u
                    WHERE u.tenant_id = r.tenant_id
                      AND u.role_id = r.id
                      AND u.deleted_at IS NULL
                ) AS user_count,

                (
                    SELECT COUNT(DISTINCT rp.permission_id)
                    FROM role_permissions rp
                    WHERE rp.role_id = r.id
                      AND (
                          rp.tenant_id = r.tenant_id
                          OR rp.tenant_id IS NULL
                      )
                ) AS permission_count

            FROM roles r

            WHERE r.id = ?
              AND r.tenant_id = ?

            LIMIT 1
        ";

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param(
            'ii',
            $roleId,
            $tenantId
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $role = rolesFetchAssoc($stmt);

        $stmt->close();

        return $role;
    }
}

if (!function_exists('rolesAuditLog')) {
    function rolesAuditLog(
        mysqli $conn,
        $tenantId,
        $actorUserId,
        $roleId,
        $action,
        $oldValue,
        $newValue
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
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW()
            )
        ");

        if (!$stmt) {
            return;
        }

        $oldJson = $oldValue === null
            ? null
            : json_encode(
                $oldValue,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        $newJson = $newValue === null
            ? null
            : json_encode(
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
            'iiisssss',
            $tenantId,
            $actorUserId,
            $roleId,
            $action,
            $oldJson,
            $newJson,
            $ipAddress,
            $userAgent
        );

        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('rolesActivityLog')) {
    function rolesActivityLog(
        mysqli $conn,
        $tenantId,
        $actorUserId,
        $roleId,
        $eventType,
        $title,
        array $details
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
                ?,
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
            return;
        }

        $detailsJson = json_encode(
            $details,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iisiss',
            $tenantId,
            $actorUserId,
            $eventType,
            $roleId,
            $title,
            $detailsJson
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    if (!$canManage) {
        $errors[] =
            'You do not have permission to manage roles.';
    }

    $csrfToken = rolesPost('csrf_token');

    if (!rolesVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $action = rolesPost('action');
    $roleId = (int) rolesPost('role_id');

    if (
        empty($errors) &&
        $action === 'toggle_status'
    ) {
        $newStatus = (int) rolesPost('new_status');

        if (
            $roleId <= 0 ||
            !in_array($newStatus, array(0, 1), true)
        ) {
            $errors[] =
                'Invalid role status action.';
        }

        if (empty($errors)) {
            try {
                $conn->begin_transaction();

                $role = rolesLoadRole(
                    $conn,
                    $tenantId,
                    $roleId,
                    true
                );

                if (!$role) {
                    throw new Exception(
                        'Role not found or access denied.'
                    );
                }

                if ((int) $role['is_system'] === 1) {
                    throw new Exception(
                        'System roles cannot be activated or deactivated from this page.'
                    );
                }

                if (
                    $newStatus === 0 &&
                    $roleId === $currentRoleId
                ) {
                    throw new Exception(
                        'You cannot deactivate the role assigned to your own account.'
                    );
                }

                $oldStatus = (int) $role['is_active'];

                $stmt = $conn->prepare("
                    UPDATE roles
                    SET
                        is_active = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare the role status update.'
                    );
                }

                $stmt->bind_param(
                    'iii',
                    $newStatus,
                    $roleId,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Role status could not be updated: ' .
                        $stmt->error
                    );
                }

                $stmt->close();

                $conn->commit();

                rolesAuditLog(
                    $conn,
                    $tenantId,
                    $currentUserId,
                    $roleId,
                    $newStatus === 1
                        ? 'role_activated'
                        : 'role_deactivated',
                    array(
                        'is_active' => $oldStatus
                    ),
                    array(
                        'is_active' => $newStatus
                    )
                );

                rolesActivityLog(
                    $conn,
                    $tenantId,
                    $currentUserId,
                    $roleId,
                    $newStatus === 1
                        ? 'role_activated'
                        : 'role_deactivated',
                    (
                        $newStatus === 1
                            ? 'Role activated: '
                            : 'Role deactivated: '
                    ) . $role['name'],
                    array(
                        'role_id' => $roleId,
                        'role_name' => $role['name'],
                        'role_code' => $role['code'],
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus
                    )
                );

                $_SESSION['flash_success'] =
                    $newStatus === 1
                        ? 'Role activated successfully.'
                        : 'Role deactivated successfully.';

                $returnQuery = rolesPost('return_query');

                header(
                    'Location: roles.php' .
                    (
                        $returnQuery !== ''
                            ? '?' . $returnQuery
                            : ''
                    )
                );
                exit;
            } catch (Throwable $error) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }

                $errors[] = $error->getMessage();
            }
        }
    }

    if (
        empty($errors) &&
        $action === 'delete_role'
    ) {
        if ($roleId <= 0) {
            $errors[] =
                'Invalid role selected.';
        }

        if (empty($errors)) {
            try {
                $conn->begin_transaction();

                $role = rolesLoadRole(
                    $conn,
                    $tenantId,
                    $roleId,
                    true
                );

                if (!$role) {
                    throw new Exception(
                        'Role not found or access denied.'
                    );
                }

                if ((int) $role['is_system'] === 1) {
                    throw new Exception(
                        'System roles cannot be deleted.'
                    );
                }

                if ($roleId === $currentRoleId) {
                    throw new Exception(
                        'You cannot delete the role assigned to your own account.'
                    );
                }

                if ((int) $role['user_count'] > 0) {
                    throw new Exception(
                        'This role is assigned to ' .
                        (int) $role['user_count'] .
                        ' user(s). Reassign those users before deleting the role.'
                    );
                }

                $stmt = $conn->prepare("
                    DELETE FROM role_permissions
                    WHERE role_id = ?
                      AND (
                          tenant_id = ?
                          OR tenant_id IS NULL
                      )
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare role permission cleanup.'
                    );
                }

                $stmt->bind_param(
                    'ii',
                    $roleId,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to remove role permissions: ' .
                        $stmt->error
                    );
                }

                $stmt->close();

                $stmt = $conn->prepare("
                    DELETE FROM roles
                    WHERE id = ?
                      AND tenant_id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare role deletion.'
                    );
                }

                $stmt->bind_param(
                    'ii',
                    $roleId,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Role could not be deleted: ' .
                        $stmt->error
                    );
                }

                $stmt->close();

                $conn->commit();

                rolesAuditLog(
                    $conn,
                    $tenantId,
                    $currentUserId,
                    $roleId,
                    'role_deleted',
                    array(
                        'id' => (int) $role['id'],
                        'name' => $role['name'],
                        'code' => $role['code'],
                        'description' => $role['description'],
                        'is_active' => (int) $role['is_active'],
                        'permission_count' =>
                            (int) $role['permission_count']
                    ),
                    null
                );

                rolesActivityLog(
                    $conn,
                    $tenantId,
                    $currentUserId,
                    $roleId,
                    'role_deleted',
                    'Role deleted: ' .
                        $role['name'],
                    array(
                        'role_id' => $roleId,
                        'role_name' => $role['name'],
                        'role_code' => $role['code']
                    )
                );

                $_SESSION['flash_success'] =
                    'Role deleted successfully.';

                header('Location: roles.php');
                exit;
            } catch (Throwable $error) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }

                $errors[] = $error->getMessage();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$typeFilter = isset($_GET['type'])
    ? trim((string) $_GET['type'])
    : '';

$assignmentFilter = isset($_GET['assignment'])
    ? trim((string) $_GET['assignment'])
    : '';

$permissionFilter = isset($_GET['permissions'])
    ? trim((string) $_GET['permissions'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'name_asc';

$allowedStatuses = array(
    '',
    'active',
    'inactive'
);

$allowedTypes = array(
    '',
    'system',
    'custom'
);

$allowedAssignments = array(
    '',
    'assigned',
    'unassigned'
);

$allowedPermissionFilters = array(
    '',
    'with_permissions',
    'without_permissions'
);

$allowedSorts = array(
    'name_asc',
    'name_desc',
    'latest',
    'oldest',
    'code_asc',
    'users_desc',
    'permissions_desc',
    'status_asc'
);

if (
    !in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $statusFilter = '';
}

if (
    !in_array(
        $typeFilter,
        $allowedTypes,
        true
    )
) {
    $typeFilter = '';
}

if (
    !in_array(
        $assignmentFilter,
        $allowedAssignments,
        true
    )
) {
    $assignmentFilter = '';
}

if (
    !in_array(
        $permissionFilter,
        $allowedPermissionFilters,
        true
    )
) {
    $permissionFilter = '';
}

if (
    !in_array(
        $sort,
        $allowedSorts,
        true
    )
) {
    $sort = 'name_asc';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 20;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Summary statistics
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'system' => 0,
    'custom' => 0,
    'assigned_users' => 0
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(is_active = 1) AS active_count,
        SUM(is_active = 0) AS inactive_count,
        SUM(is_system = 1) AS system_count,
        SUM(is_system = 0) AS custom_count,

        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.tenant_id = ?
              AND u.role_id IS NOT NULL
              AND u.deleted_at IS NULL
        ) AS assigned_user_count

    FROM roles
    WHERE tenant_id = ?
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $tenantId
    );

    $stmt->execute();

    $row = rolesFetchAssoc($stmt);

    $stmt->close();

    if ($row) {
        $stats['total'] =
            (int) $row['total_count'];

        $stats['active'] =
            (int) $row['active_count'];

        $stats['inactive'] =
            (int) $row['inactive_count'];

        $stats['system'] =
            (int) $row['system_count'];

        $stats['custom'] =
            (int) $row['custom_count'];

        $stats['assigned_users'] =
            (int) $row['assigned_user_count'];
    }
}

/*
|--------------------------------------------------------------------------
| Build filtered query
|--------------------------------------------------------------------------
*/

$where = array(
    'r.tenant_id = ?'
);

$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        r.name LIKE ?
        OR r.code LIKE ?
        OR r.description LIKE ?
        OR EXISTS (
            SELECT 1
            FROM role_permissions srp
            INNER JOIN permissions sp
                ON sp.id = srp.permission_id
            WHERE srp.role_id = r.id
              AND (
                  srp.tenant_id = r.tenant_id
                  OR srp.tenant_id IS NULL
              )
              AND (
                  sp.code LIKE ?
                  OR sp.module LIKE ?
                  OR sp.action LIKE ?
                  OR sp.description LIKE ?
              )
        )
    )";

    $searchLike = '%' . $search . '%';

    for ($index = 0; $index < 7; $index++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($statusFilter === 'active') {
    $where[] = 'r.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'r.is_active = 0';
}

if ($typeFilter === 'system') {
    $where[] = 'r.is_system = 1';
} elseif ($typeFilter === 'custom') {
    $where[] = 'r.is_system = 0';
}

if ($assignmentFilter === 'assigned') {
    $where[] = "
        EXISTS (
            SELECT 1
            FROM users au
            WHERE au.tenant_id = r.tenant_id
              AND au.role_id = r.id
              AND au.deleted_at IS NULL
        )
    ";
} elseif ($assignmentFilter === 'unassigned') {
    $where[] = "
        NOT EXISTS (
            SELECT 1
            FROM users au
            WHERE au.tenant_id = r.tenant_id
              AND au.role_id = r.id
              AND au.deleted_at IS NULL
        )
    ";
}

if ($permissionFilter === 'with_permissions') {
    $where[] = "
        EXISTS (
            SELECT 1
            FROM role_permissions prp
            WHERE prp.role_id = r.id
              AND (
                  prp.tenant_id = r.tenant_id
                  OR prp.tenant_id IS NULL
              )
        )
    ";
} elseif ($permissionFilter === 'without_permissions') {
    $where[] = "
        NOT EXISTS (
            SELECT 1
            FROM role_permissions prp
            WHERE prp.role_id = r.id
              AND (
                  prp.tenant_id = r.tenant_id
                  OR prp.tenant_id IS NULL
              )
        )
    ";
}

$whereSql = implode(' AND ', $where);

$orderSql = 'r.name ASC, r.id ASC';

if ($sort === 'name_desc') {
    $orderSql = 'r.name DESC, r.id DESC';
} elseif ($sort === 'latest') {
    $orderSql = 'r.created_at DESC, r.id DESC';
} elseif ($sort === 'oldest') {
    $orderSql = 'r.created_at ASC, r.id ASC';
} elseif ($sort === 'code_asc') {
    $orderSql = 'r.code ASC, r.name ASC';
} elseif ($sort === 'users_desc') {
    $orderSql = 'user_count DESC, r.name ASC';
} elseif ($sort === 'permissions_desc') {
    $orderSql = 'permission_count DESC, r.name ASC';
} elseif ($sort === 'status_asc') {
    $orderSql = 'r.is_active DESC, r.name ASC';
}

/*
|--------------------------------------------------------------------------
| Count filtered rows
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM roles r
    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare the role count query: ' .
        $conn->error;
} else {
    if (
        !rolesBindParams(
            $stmt,
            $types,
            $params
        )
    ) {
        $errors[] =
            'Unable to bind role filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to count roles: ' .
            $stmt->error;
    } else {
        $row = rolesFetchAssoc($stmt);

        if ($row) {
            $totalFiltered =
                (int) $row['total'];
        }
    }

    $stmt->close();
}

$totalPages = max(
    1,
    (int) ceil(
        $totalFiltered / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Load roles
|--------------------------------------------------------------------------
*/

$roles = array();

$listSql = "
    SELECT
        r.id,
        r.name,
        r.code,
        r.description,
        r.is_system,
        r.is_active,
        r.created_at,
        r.updated_at,

        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.tenant_id = r.tenant_id
              AND u.role_id = r.id
              AND u.deleted_at IS NULL
        ) AS user_count,

        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.tenant_id = r.tenant_id
              AND u.role_id = r.id
              AND u.deleted_at IS NULL
              AND u.status = 'active'
        ) AS active_user_count,

        (
            SELECT COUNT(DISTINCT rp.permission_id)
            FROM role_permissions rp
            WHERE rp.role_id = r.id
              AND (
                  rp.tenant_id = r.tenant_id
                  OR rp.tenant_id IS NULL
              )
        ) AS permission_count,

        (
            SELECT GROUP_CONCAT(
                DISTINCT p.module
                ORDER BY p.module ASC
                SEPARATOR ', '
            )
            FROM role_permissions rp
            INNER JOIN permissions p
                ON p.id = rp.permission_id
            WHERE rp.role_id = r.id
              AND (
                  rp.tenant_id = r.tenant_id
                  OR rp.tenant_id IS NULL
              )
        ) AS permission_modules

    FROM roles r

    WHERE {$whereSql}

    ORDER BY {$orderSql}

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($listSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare the role list query: ' .
        $conn->error;
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    if (
        !rolesBindParams(
            $stmt,
            $listTypes,
            $listParams
        )
    ) {
        $errors[] =
            'Unable to bind the role list filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to load roles: ' .
            $stmt->error;
    } else {
        $roles = rolesFetchAll($stmt);
    }

    $stmt->close();
}

$csrfToken = rolesCsrfToken();

$returnQuery = rolesQueryString(
    array(
        'page' => $page
    )
);

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.roles-page {
    --rl-primary: #6d28d9;
    --rl-text: #111827;
    --rl-muted: #6b7280;
    --rl-border: #e5e7eb;
}

.rl-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.rl-header h1 {
    margin: 0;
    color: var(--rl-text);
    font-size: 21px;
    font-weight: 700;
}

.rl-header p {
    margin: 5px 0 0;
    color: var(--rl-muted);
    font-size: 11px;
}

.rl-add {
    min-height: 35px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 9px;
    background: var(--rl-primary);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.rl-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
    line-height: 1.55;
}

.rl-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.rl-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.rl-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(6,minmax(0,1fr));
    gap: 10px;
}

.rl-stat {
    padding: 13px;
    border: 1px solid var(--rl-border);
    border-radius: 11px;
    background: #fff;
}

.rl-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.rl-stat-value {
    margin-top: 4px;
    color: var(--rl-text);
    font-size: 19px;
    font-weight: 700;
}

.rl-panel {
    overflow: hidden;
    border: 1px solid var(--rl-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.rl-filters {
    padding: 12px;
    display: grid;
    grid-template-columns:
        minmax(240px,1.35fr)
        minmax(145px,.68fr)
        minmax(145px,.68fr)
        minmax(165px,.75fr)
        minmax(175px,.78fr)
        minmax(175px,.78fr)
        auto;
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.rl-input,
.rl-select {
    width: 100%;
    height: 36px;
    min-height: 36px;
    padding: 8px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 8px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 9px;
    outline: none;
}

.rl-input:focus,
.rl-select:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.08);
}

.rl-filter-actions {
    display: flex;
    gap: 6px;
}

.rl-filter-btn,
.rl-reset {
    height: 36px;
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 700;
}

.rl-filter-btn {
    border: 0;
    background: var(--rl-primary);
    color: #fff;
    cursor: pointer;
}

.rl-reset {
    border: 1px solid var(--rl-border);
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.rl-table-wrap {
    overflow-x: auto;
}

.rl-table {
    width: 100%;
    border-collapse: collapse;
}

.rl-table th,
.rl-table td {
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    white-space: nowrap;
    vertical-align: middle;
}

.rl-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.rl-table td {
    color: #374151;
    font-size: 9px;
}

.rl-main {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.rl-sub {
    margin-top: 2px;
    display: block;
    max-width: 290px;
    overflow: hidden;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.4;
    text-overflow: ellipsis;
}

.rl-code {
    padding: 4px 7px;
    display: inline-flex;
    align-items: center;
    border: 1px solid #ddd6fe;
    border-radius: 7px;
    background: #faf8ff;
    color: #6d28d9;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 8px;
    font-weight: 700;
}

.rl-badge {
    padding: 4px 7px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
}

.rl-badge.active {
    background: #ecfdf5;
    color: #047857;
}

.rl-badge.inactive {
    background: #fef2f2;
    color: #b91c1c;
}

.rl-badge.system {
    background: #eff6ff;
    color: #1d4ed8;
}

.rl-badge.custom {
    background: #f5f3ff;
    color: #6d28d9;
}

.rl-count {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
}

.rl-count-link {
    color: var(--rl-primary);
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.rl-current {
    margin-left: 5px;
    padding: 3px 6px;
    display: inline-flex;
    border-radius: 999px;
    background: #fff7ed;
    color: #c2410c;
    font-size: 7px;
    font-weight: 700;
}

.rl-actions {
    display: flex;
    justify-content: flex-end;
    gap: 5px;
}

.rl-action-form {
    margin: 0;
}

.rl-action {
    width: 29px;
    height: 29px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--rl-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-family: inherit;
    text-decoration: none;
    cursor: pointer;
}

.rl-action:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--rl-primary);
}

.rl-action.success {
    border-color: #bbf7d0;
    color: #047857;
}

.rl-action.warning {
    border-color: #fed7aa;
    color: #c2410c;
}

.rl-action.danger {
    border-color: #fecaca;
    color: #b91c1c;
}

.rl-action:disabled {
    cursor: not-allowed;
    opacity: .45;
}

.rl-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #f1f5f9;
}

.rl-result {
    color: #6b7280;
    font-size: 9px;
}

.rl-pages {
    display: flex;
    gap: 5px;
}

.rl-page {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--rl-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.rl-page.active {
    border-color: var(--rl-primary);
    background: var(--rl-primary);
    color: #fff;
}

.rl-empty {
    padding: 42px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1450px) {
    .rl-filters {
        grid-template-columns: repeat(4,minmax(0,1fr));
    }
}

@media (max-width: 1050px) {
    .rl-stats {
        grid-template-columns: repeat(3,minmax(0,1fr));
    }
}

@media (max-width: 760px) {
    .rl-header {
        flex-direction: column;
    }

    .rl-filters {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 560px) {
    .rl-stats,
    .rl-filters {
        grid-template-columns: 1fr;
    }

    .rl-filter-actions {
        width: 100%;
    }

    .rl-filter-btn,
    .rl-reset {
        flex: 1;
    }

    .rl-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="roles-page">
    <div class="rl-header">
        <div>
            <h1>Roles & Permissions</h1>
            <p>
                Manage workspace roles, assigned users, status, and permission access.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a href="role-add.php" class="rl-add">
                <i class="bi bi-plus-lg"></i>
                Add Role
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="rl-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="rl-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="rl-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="rl-stats">
        <article class="rl-stat">
            <div class="rl-stat-label">Total Roles</div>
            <div class="rl-stat-value">
                <?= e($stats['total']); ?>
            </div>
        </article>

        <article class="rl-stat">
            <div class="rl-stat-label">Active</div>
            <div class="rl-stat-value">
                <?= e($stats['active']); ?>
            </div>
        </article>

        <article class="rl-stat">
            <div class="rl-stat-label">Inactive</div>
            <div class="rl-stat-value">
                <?= e($stats['inactive']); ?>
            </div>
        </article>

        <article class="rl-stat">
            <div class="rl-stat-label">System Roles</div>
            <div class="rl-stat-value">
                <?= e($stats['system']); ?>
            </div>
        </article>

        <article class="rl-stat">
            <div class="rl-stat-label">Custom Roles</div>
            <div class="rl-stat-value">
                <?= e($stats['custom']); ?>
            </div>
        </article>

        <article class="rl-stat">
            <div class="rl-stat-label">Assigned Users</div>
            <div class="rl-stat-value">
                <?= e($stats['assigned_users']); ?>
            </div>
        </article>
    </section>

    <section class="rl-panel">
        <form
            method="get"
            action=""
            class="rl-filters"
        >
            <input
                type="search"
                name="search"
                class="rl-input"
                value="<?= e($search); ?>"
                placeholder="Search role, code, description or permission"
            >

            <select
                name="status"
                class="rl-select"
            >
                <option value="">All Statuses</option>

                <option
                    value="active"
                    <?= $statusFilter === 'active'
                        ? 'selected'
                        : ''; ?>
                >
                    Active
                </option>

                <option
                    value="inactive"
                    <?= $statusFilter === 'inactive'
                        ? 'selected'
                        : ''; ?>
                >
                    Inactive
                </option>
            </select>

            <select
                name="type"
                class="rl-select"
            >
                <option value="">All Role Types</option>

                <option
                    value="system"
                    <?= $typeFilter === 'system'
                        ? 'selected'
                        : ''; ?>
                >
                    System Roles
                </option>

                <option
                    value="custom"
                    <?= $typeFilter === 'custom'
                        ? 'selected'
                        : ''; ?>
                >
                    Custom Roles
                </option>
            </select>

            <select
                name="assignment"
                class="rl-select"
            >
                <option value="">All Assignments</option>

                <option
                    value="assigned"
                    <?= $assignmentFilter === 'assigned'
                        ? 'selected'
                        : ''; ?>
                >
                    Has Assigned Users
                </option>

                <option
                    value="unassigned"
                    <?= $assignmentFilter === 'unassigned'
                        ? 'selected'
                        : ''; ?>
                >
                    No Assigned Users
                </option>
            </select>

            <select
                name="permissions"
                class="rl-select"
            >
                <option value="">All Permission States</option>

                <option
                    value="with_permissions"
                    <?= $permissionFilter === 'with_permissions'
                        ? 'selected'
                        : ''; ?>
                >
                    Has Permissions
                </option>

                <option
                    value="without_permissions"
                    <?= $permissionFilter === 'without_permissions'
                        ? 'selected'
                        : ''; ?>
                >
                    No Permissions
                </option>
            </select>

            <select
                name="sort"
                class="rl-select"
            >
                <option
                    value="name_asc"
                    <?= $sort === 'name_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Role Name A-Z
                </option>

                <option
                    value="name_desc"
                    <?= $sort === 'name_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Role Name Z-A
                </option>

                <option
                    value="latest"
                    <?= $sort === 'latest'
                        ? 'selected'
                        : ''; ?>
                >
                    Latest Created
                </option>

                <option
                    value="oldest"
                    <?= $sort === 'oldest'
                        ? 'selected'
                        : ''; ?>
                >
                    Oldest Created
                </option>

                <option
                    value="code_asc"
                    <?= $sort === 'code_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Role Code
                </option>

                <option
                    value="users_desc"
                    <?= $sort === 'users_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Most Users
                </option>

                <option
                    value="permissions_desc"
                    <?= $sort === 'permissions_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Most Permissions
                </option>

                <option
                    value="status_asc"
                    <?= $sort === 'status_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Active First
                </option>
            </select>

            <div class="rl-filter-actions">
                <button
                    type="submit"
                    class="rl-filter-btn"
                >
                    Apply
                </button>

                <a
                    href="roles.php"
                    class="rl-reset"
                >
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($roles)): ?>
            <div class="rl-table-wrap">
                <table class="rl-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Permissions</th>
                            <th>Assigned Users</th>
                            <th>Created</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($roles as $role): ?>
                        <?php
                        $roleId = (int) $role['id'];
                        $isSystem =
                            (int) $role['is_system'] === 1;
                        $isActive =
                            (int) $role['is_active'] === 1;
                        $isCurrentRole =
                            $roleId === $currentRoleId;
                        $userCount =
                            (int) $role['user_count'];
                        $activeUserCount =
                            (int) $role['active_user_count'];
                        $permissionCount =
                            (int) $role['permission_count'];
                        ?>

                        <tr>
                            <td>
                                <a
                                    href="role-edit.php?id=<?= $roleId; ?>"
                                    class="rl-main"
                                >
                                    <?= e($role['name']); ?>
                                </a>

                                <?php if ($isCurrentRole): ?>
                                    <span class="rl-current">
                                        Your Role
                                    </span>
                                <?php endif; ?>

                                <span class="rl-sub">
                                    <?= e(
                                        trim(
                                            (string) $role['description']
                                        ) !== ''
                                            ? $role['description']
                                            : 'No description'
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="rl-code">
                                    <?= e($role['code']); ?>
                                </span>
                            </td>

                            <td>
                                <span class="rl-badge <?= $isSystem
                                    ? 'system'
                                    : 'custom'; ?>">
                                    <?= $isSystem
                                        ? 'System'
                                        : 'Custom'; ?>
                                </span>
                            </td>

                            <td>
                                <span class="rl-badge <?= $isActive
                                    ? 'active'
                                    : 'inactive'; ?>">
                                    <?= $isActive
                                        ? 'Active'
                                        : 'Inactive'; ?>
                                </span>
                            </td>

                            <td>
                                <a
                                    href="role-edit.php?id=<?= $roleId; ?>#permissions"
                                    class="rl-count-link"
                                >
                                    <?= e($permissionCount); ?>
                                    permission<?= $permissionCount === 1
                                        ? ''
                                        : 's'; ?>
                                </a>

                                <span class="rl-sub">
                                    <?= e(
                                        trim(
                                            (string) $role['permission_modules']
                                        ) !== ''
                                            ? $role['permission_modules']
                                            : 'No permission modules'
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <a
                                    href="users.php?role_id=<?= $roleId; ?>"
                                    class="rl-count-link"
                                >
                                    <?= e($userCount); ?>
                                    user<?= $userCount === 1
                                        ? ''
                                        : 's'; ?>
                                </a>

                                <span class="rl-sub">
                                    <?= e($activeUserCount); ?>
                                    active
                                </span>
                            </td>

                            <td>
                                <span class="rl-main">
                                    <?= e(
                                        rolesDate(
                                            $role['created_at']
                                        )
                                    ); ?>
                                </span>

                                <?php if (
                                    !empty($role['updated_at'])
                                ): ?>
                                    <span class="rl-sub">
                                        Updated:
                                        <?= e(
                                            rolesDateTime(
                                                $role['updated_at']
                                            )
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="rl-actions">
                                    <a
                                        href="role-edit.php?id=<?= $roleId; ?>"
                                        class="rl-action"
                                        title="Edit Role and Permissions"
                                    >
                                        <i class="bi bi-pencil"></i>
                                    </a>

                                    <a
                                        href="users.php?role_id=<?= $roleId; ?>"
                                        class="rl-action"
                                        title="View Assigned Users"
                                    >
                                        <i class="bi bi-people"></i>
                                    </a>

                                    <?php if (
                                        $canManage &&
                                        !$isSystem
                                    ): ?>
                                        <form
                                            method="post"
                                            class="rl-action-form"
                                            onsubmit="return confirm('<?= $isActive
                                                ? 'Deactivate this role? Users assigned to it may lose access.'
                                                : 'Activate this role?'; ?>');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e(
                                                    $csrfToken
                                                ); ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="role_id"
                                                value="<?= $roleId; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="new_status"
                                                value="<?= $isActive
                                                    ? '0'
                                                    : '1'; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="return_query"
                                                value="<?= e(
                                                    $returnQuery
                                                ); ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="rl-action <?= $isActive
                                                    ? 'warning'
                                                    : 'success'; ?>"
                                                title="<?= $isActive
                                                    ? 'Deactivate Role'
                                                    : 'Activate Role'; ?>"
                                                <?= $isCurrentRole &&
                                                    $isActive
                                                        ? 'disabled'
                                                        : ''; ?>
                                            >
                                                <i class="bi <?= $isActive
                                                    ? 'bi-pause-circle'
                                                    : 'bi-check-circle'; ?>"></i>
                                            </button>
                                        </form>

                                        <form
                                            method="post"
                                            class="rl-action-form"
                                            onsubmit="return confirm('Delete this role permanently? This action cannot be undone.');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e(
                                                    $csrfToken
                                                ); ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete_role"
                                            >

                                            <input
                                                type="hidden"
                                                name="role_id"
                                                value="<?= $roleId; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="rl-action danger"
                                                title="<?= $userCount > 0
                                                    ? 'Reassign users before deleting'
                                                    : 'Delete Role'; ?>"
                                                <?= (
                                                    $userCount > 0 ||
                                                    $isCurrentRole
                                                )
                                                    ? 'disabled'
                                                    : ''; ?>
                                            >
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="rl-footer">
                <div class="rl-result">
                    Showing
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + 1
                        )
                    ); ?>
                    -
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + count($roles)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    roles
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="rl-pages">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    rolesQueryString(
                                        array(
                                            'page' =>
                                                $page - 1
                                        )
                                    )
                                ); ?>"
                                class="rl-page"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min(
                            $totalPages,
                            $page + 2
                        );

                        for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(
                                    rolesQueryString(
                                        array(
                                            'page' =>
                                                $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="rl-page <?= $pageNumber === $page
                                    ? 'active'
                                    : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    rolesQueryString(
                                        array(
                                            'page' =>
                                                $page + 1
                                        )
                                    )
                                ); ?>"
                                class="rl-page"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="rl-empty">
                <?php if (
                    $search !== '' ||
                    $statusFilter !== '' ||
                    $typeFilter !== '' ||
                    $assignmentFilter !== '' ||
                    $permissionFilter !== ''
                ): ?>
                    No roles found for the selected filters.
                <?php else: ?>
                    No roles are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
