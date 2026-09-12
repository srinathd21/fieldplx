<?php
ob_start();

ini_set('display_errors','0');
ini_set('html_errors','0');
ini_set('log_errors','1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

if (
    file_exists(
        __DIR__ . '/../includes/audit.php'
    )
) {
    require_once __DIR__ . '/../includes/audit.php';
}

function rolesApiResponse(
    $status,
    $success,
    $message,
    $extra = array()
) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code(
        (int)$status
    );

    echo json_encode(
        array_merge(
            array(
                'success' =>
                    (bool)$success,
                'message' =>
                    (string)$message
            ),
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function rolesApiPost(
    $key,
    $default = ''
) {
    return isset($_POST[$key])
        ? $_POST[$key]
        : $default;
}

function rolesApiSlug($value)
{
    $value =
        strtolower(
            trim(
                (string)$value
            )
        );

    $value =
        preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $value
        );

    return trim(
        (string)$value,
        '_'
    );
}

function rolesApiClientIp()
{
    return isset($_SERVER['REMOTE_ADDR'])
        ? substr(
            trim(
                (string)$_SERVER['REMOTE_ADDR']
            ),
            0,
            80
        )
        : null;
}

function rolesApiUserAgent()
{
    return isset($_SERVER['HTTP_USER_AGENT'])
        ? substr(
            trim(
                (string)$_SERVER['HTTP_USER_AGENT']
            ),
            0,
            500
        )
        : null;
}

function rolesApiDeviceType()
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
        strpos($ua,'ipad') !== false ||
        strpos($ua,'tablet') !== false ||
        strpos($ua,'kindle') !== false
    ) {
        return 'tablet';
    }

    if (
        strpos($ua,'mobile') !== false ||
        strpos($ua,'iphone') !== false ||
        strpos($ua,'android') !== false
    ) {
        return 'mobile';
    }

    return 'desktop';
}

function rolesApiJson($value)
{
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

function rolesApiRole(
    PDO $pdo,
    $tenantId,
    $roleId
) {
    $stmt = $pdo->prepare("
        SELECT
            id,
            tenant_id,
            name,
            code,
            description,
            is_admin,
            is_system_role,
            status,
            created_at,
            updated_at
        FROM roles
        WHERE id = :id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");

    $stmt->execute(
        array(
            ':id' =>
                (int)$roleId,
            ':tenant_id' =>
                (int)$tenantId
        )
    );

    $role =
        $stmt->fetch();

    if (!$role) {
        rolesApiResponse(
            404,
            false,
            'Role not found.'
        );
    }

    return $role;
}

function rolesApiCurrentPlanId(
    PDO $pdo,
    $tenantId
) {
    if (
        !empty(
            $_SESSION['plan_id']
        )
    ) {
        return (int)$_SESSION['plan_id'];
    }

    $stmt = $pdo->prepare("
        SELECT plan_id
        FROM subscriptions
        WHERE tenant_id = :tenant_id
          AND status IN ('active','trial')
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute(
        array(
            ':tenant_id' =>
                (int)$tenantId
        )
    );

    return (int)$stmt->fetchColumn();
}

function rolesApiSyncStandardPermissions(
    PDO $pdo,
    $tenantId,
    $planId
) {
    if ($planId <= 0 || $tenantId <= 0) {
        return;
    }

    /*
     * New sidebar modules can be added after the original permission seed.
     * Keep the permission master synchronized with the exact same effective
     * module rules used by the tenant sidebar: plan enabled + active sidebar
     * module + not disabled by tenant override.
     */
    $definitions = array(
        array('view', '.view', 'View '),
        array('create', '.create', 'Create in '),
        array('update', '.update', 'Update '),
        array('delete', '.delete', 'Delete/archive in '),
        array('approve', '.approve', 'Approve in '),
        array('export', '.export', 'Export from ')
    );

    $sql = "
        INSERT IGNORE INTO permissions (
            module_id,
            action_code,
            permission_code,
            description
        )
        SELECT
            m.id,
            :action_code,
            CONCAT(m.module_code, :permission_suffix),
            CONCAT(:description_prefix, m.module_name)
        FROM plan_modules pm
        INNER JOIN modules m
            ON m.id = pm.module_id
        LEFT JOIN tenant_modules tm
            ON tm.tenant_id = :tenant_id
           AND tm.module_id = m.id
        WHERE pm.plan_id = :plan_id
          AND pm.is_enabled = 1
          AND m.is_active = 1
          AND m.is_sidebar_item = 1
          AND COALESCE(tm.access_type, 'inherit') <> 'disabled'
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($definitions as $definition) {
        $stmt->execute(array(
            ':action_code' => $definition[0],
            ':permission_suffix' => $definition[1],
            ':description_prefix' => $definition[2],
            ':tenant_id' => (int)$tenantId,
            ':plan_id' => (int)$planId
        ));
    }
}

function rolesApiPermissions(
    PDO $pdo,
    $tenantId,
    $planId
) {
    if ($planId <= 0) {
        return array();
    }

    rolesApiSyncStandardPermissions(
        $pdo,
        $tenantId,
        $planId
    );

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.module_id,
            p.action_code,
            p.permission_code,
            p.description,

            m.parent_id,
            m.module_code,
            m.module_name,
            m.menu_url,
            m.icon_name,
            m.menu_order,

            parent.module_code AS parent_module_code,
            parent.module_name AS parent_module_name,
            parent.menu_order AS parent_menu_order

        FROM permissions p

        INNER JOIN modules m
            ON m.id = p.module_id
           AND m.is_active = 1
           AND m.is_sidebar_item = 1

        INNER JOIN plan_modules pm
            ON pm.module_id = m.id
           AND pm.plan_id = :plan_id
           AND pm.is_enabled = 1

        LEFT JOIN tenant_modules tm
            ON tm.tenant_id = :tenant_id
           AND tm.module_id = m.id

        LEFT JOIN modules parent
            ON parent.id = m.parent_id

        WHERE COALESCE(
            tm.access_type,
            'inherit'
        ) <> 'disabled'

        ORDER BY
            COALESCE(parent.menu_order, m.menu_order),
            COALESCE(m.parent_id, m.id),
            CASE WHEN m.parent_id IS NULL THEN 0 ELSE 1 END,
            m.menu_order,
            m.module_name,
            CASE p.action_code
                WHEN 'view' THEN 1
                WHEN 'create' THEN 2
                WHEN 'update' THEN 3
                WHEN 'delete' THEN 4
                WHEN 'approve' THEN 5
                WHEN 'export' THEN 6
                ELSE 99
            END,
            p.action_code
    ");

    $stmt->execute(
        array(
            ':plan_id' => (int)$planId,
            ':tenant_id' => (int)$tenantId
        )
    );

    return $stmt->fetchAll();
}

function rolesApiSelectedPermissionIds(
    PDO $pdo,
    $tenantId,
    $roleId
) {
    $stmt = $pdo->prepare("
        SELECT permission_id
        FROM role_permissions
        WHERE tenant_id = :tenant_id
          AND role_id = :role_id
          AND access_type = 'allow'
    ");

    $stmt->execute(
        array(
            ':tenant_id' =>
                (int)$tenantId,
            ':role_id' =>
                (int)$roleId
        )
    );

    return array_map(
        'intval',
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        )
    );
}

function rolesApiLogActivity(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $eventType,
    $roleId,
    $title,
    $details
) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_events (
                tenant_id,
                branch_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                title,
                details_json,
                visible_to_client
            ) VALUES (
                :tenant_id,
                :branch_id,
                :actor_user_id,
                'user',
                :event_type,
                'role',
                :related_id,
                :title,
                :details_json,
                0
            )
        ");

        $stmt->execute(
            array(
                ':tenant_id' =>
                    (int)$tenantId,
                ':branch_id' =>
                    $branchId > 0
                        ? (int)$branchId
                        : null,
                ':actor_user_id' =>
                    (int)$userId,
                ':event_type' =>
                    substr(
                        (string)$eventType,
                        0,
                        120
                    ),
                ':related_id' =>
                    $roleId > 0
                        ? (int)$roleId
                        : null,
                ':title' =>
                    substr(
                        (string)$title,
                        0,
                        255
                    ),
                ':details_json' =>
                    rolesApiJson(
                        $details
                    )
            )
        );
    } catch (Throwable $e) {
        error_log(
            'FieldPlx role activity log error: ' .
            $e->getMessage()
        );
    }
}

function rolesApiLogAudit(
    PDO $pdo,
    $tenantId,
    $branchId,
    $userId,
    $action,
    $roleId,
    $oldValues,
    $newValues
) {
    if (
        function_exists(
            'tenantAuditLog'
        )
    ) {
        tenantAuditLog(
            $pdo,
            $action,
            $tenantId,
            $branchId,
            $userId,
            'role',
            $roleId,
            $oldValues,
            $newValues
        );

        return;
    }

    try {
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
                'role',
                :object_id,
                :old_values,
                :new_values,
                :ip_address,
                :device_type,
                :user_agent
            )
        ");

        $stmt->execute(
            array(
                ':tenant_id' =>
                    (int)$tenantId,
                ':branch_id' =>
                    $branchId > 0
                        ? (int)$branchId
                        : null,
                ':user_id' =>
                    (int)$userId,
                ':action' =>
                    substr(
                        (string)$action,
                        0,
                        120
                    ),
                ':object_id' =>
                    $roleId > 0
                        ? (int)$roleId
                        : null,
                ':old_values' =>
                    rolesApiJson(
                        $oldValues
                    ),
                ':new_values' =>
                    rolesApiJson(
                        $newValues
                    ),
                ':ip_address' =>
                    rolesApiClientIp(),
                ':device_type' =>
                    rolesApiDeviceType(),
                ':user_agent' =>
                    rolesApiUserAgent()
            )
        );
    } catch (Throwable $e) {
        error_log(
            'FieldPlx role audit log error: ' .
            $e->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Session context
|--------------------------------------------------------------------------
*/
$tenantId =
    isset($_SESSION['tenant_id'])
        ? (int)$_SESSION['tenant_id']
        : 0;

$userId =
    isset($_SESSION['tenant_user_id'])
        ? (int)$_SESSION['tenant_user_id']
        : 0;

$branchId =
    isset($_SESSION['branch_id'])
        ? (int)$_SESSION['branch_id']
        : 0;

if (
    $tenantId <= 0 ||
    $userId <= 0
) {
    rolesApiResponse(
        401,
        false,
        'Authentication required.'
    );
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
$csrfToken =
    (string)rolesApiPost(
        'csrf_token',
        ''
    );

$sessionToken =
    isset(
        $_SESSION[
            'roles_csrf_token'
        ]
    )
        ? (string)$_SESSION[
            'roles_csrf_token'
        ]
        : '';

if (
    $sessionToken === '' ||
    $csrfToken === '' ||
    !hash_equals(
        $sessionToken,
        $csrfToken
    )
) {
    rolesApiResponse(
        419,
        false,
        'Your form session expired. Refresh the page and try again.'
    );
}

$action =
    trim(
        (string)rolesApiPost(
            'action',
            ''
        )
    );

$planId =
    rolesApiCurrentPlanId(
        $pdo,
        $tenantId
    );

try {

    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */
    if ($action === 'list') {

        $page =
            max(
                1,
                (int)rolesApiPost(
                    'page',
                    1
                )
            );

        $perPage =
            (int)rolesApiPost(
                'per_page',
                10
            );

        if (
            !in_array(
                $perPage,
                array(10,25,50),
                true
            )
        ) {
            $perPage = 10;
        }

        $search =
            trim(
                (string)rolesApiPost(
                    'search',
                    ''
                )
            );

        $status =
            trim(
                (string)rolesApiPost(
                    'status',
                    ''
                )
            );

        $type =
            trim(
                (string)rolesApiPost(
                    'type',
                    ''
                )
            );

        $where =
            array(
                'r.tenant_id = :tenant_id'
            );

        $params =
            array(
                ':tenant_id' =>
                    $tenantId
            );

        if ($search !== '') {
            $where[] =
                '(
                    r.name LIKE :search1
                    OR r.code LIKE :search2
                    OR r.description LIKE :search3
                )';

            $searchValue =
                '%' . $search . '%';

            $params[':search1'] =
                $searchValue;

            $params[':search2'] =
                $searchValue;

            $params[':search3'] =
                $searchValue;
        }

        if (
            in_array(
                $status,
                array(
                    'active',
                    'inactive'
                ),
                true
            )
        ) {
            $where[] =
                'r.status = :status';

            $params[':status'] =
                $status;
        }

        if ($type === 'admin') {
            $where[] =
                'r.is_admin = 1';
        } elseif ($type === 'standard') {
            $where[] =
                'r.is_admin = 0';
        } elseif ($type === 'system') {
            $where[] =
                'r.is_system_role = 1';
        }

        $whereSql =
            implode(
                ' AND ',
                $where
            );

        $countStmt =
            $pdo->prepare("
                SELECT COUNT(*)
                FROM roles r
                WHERE $whereSql
            ");

        $countStmt->execute(
            $params
        );

        $total =
            (int)$countStmt->fetchColumn();

        $pages =
            max(
                1,
                (int)ceil(
                    $total /
                    $perPage
                )
            );

        if ($page > $pages) {
            $page = $pages;
        }

        $offset =
            ($page - 1) *
            $perPage;

        $sql = "
            SELECT
                r.id,
                r.name,
                r.code,
                r.description,
                r.is_admin,
                r.is_system_role,
                r.status,
                r.created_at,
                r.updated_at,

                (
                    SELECT COUNT(*)
                    FROM role_permissions rp
                    WHERE rp.tenant_id = r.tenant_id
                      AND rp.role_id = r.id
                      AND rp.access_type = 'allow'
                ) AS permission_count,

                (
                    SELECT COUNT(*)
                    FROM users u
                    WHERE u.tenant_id = r.tenant_id
                      AND u.role_id = r.id
                      AND u.deleted_at IS NULL
                ) AS user_count

            FROM roles r

            WHERE $whereSql

            ORDER BY
                r.is_system_role DESC,
                r.is_admin DESC,
                r.name ASC

            LIMIT " .
            (int)$perPage .
            " OFFSET " .
            (int)$offset;

        $stmt =
            $pdo->prepare(
                $sql
            );

        $stmt->execute(
            $params
        );

        $rows =
            $stmt->fetchAll();

        $summaryStmt =
            $pdo->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(
                        CASE
                            WHEN status = 'active'
                            THEN 1
                            ELSE 0
                        END
                    ) AS active,
                    SUM(
                        CASE
                            WHEN is_admin = 1
                            THEN 1
                            ELSE 0
                        END
                    ) AS admin
                FROM roles
                WHERE tenant_id = :tenant_id
            ");

        $summaryStmt->execute(
            array(
                ':tenant_id' =>
                    $tenantId
            )
        );

        $summary =
            $summaryStmt->fetch();

        $assignedStmt =
            $pdo->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE tenant_id = :tenant_id
                  AND role_id IS NOT NULL
                  AND deleted_at IS NULL
            ");

        $assignedStmt->execute(
            array(
                ':tenant_id' =>
                    $tenantId
            )
        );

        $assignedUsers =
            (int)$assignedStmt->fetchColumn();

        $from =
            $total > 0
                ? $offset + 1
                : 0;

        $to =
            $total > 0
                ? min(
                    $offset +
                    count($rows),
                    $total
                )
                : 0;

        rolesApiResponse(
            200,
            true,
            'Roles loaded.',
            array(
                'roles' =>
                    $rows,
                'summary' =>
                    array(
                        'total' =>
                            (int)(
                                $summary['total']
                                ?? 0
                            ),
                        'active' =>
                            (int)(
                                $summary['active']
                                ?? 0
                            ),
                        'admin' =>
                            (int)(
                                $summary['admin']
                                ?? 0
                            ),
                        'assigned_users' =>
                            $assignedUsers
                    ),
                'pagination' =>
                    array(
                        'page' =>
                            $page,
                        'per_page' =>
                            $perPage,
                        'total' =>
                            $total,
                        'pages' =>
                            $pages,
                        'from' =>
                            $from,
                        'to' =>
                            $to
                    )
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PERMISSIONS ONLY
    |--------------------------------------------------------------------------
    */
    if ($action === 'permissions') {

        $permissions =
            rolesApiPermissions(
                $pdo,
                $tenantId,
                $planId
            );

        rolesApiResponse(
            200,
            true,
            'Permissions loaded.',
            array(
                'permissions' =>
                    $permissions,
                'selected_permission_ids' =>
                    array()
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GET ROLE
    |--------------------------------------------------------------------------
    */
    if ($action === 'get') {

        $roleId =
            (int)rolesApiPost(
                'role_id',
                0
            );

        if ($roleId <= 0) {
            rolesApiResponse(
                422,
                false,
                'Invalid role.'
            );
        }

        $role =
            rolesApiRole(
                $pdo,
                $tenantId,
                $roleId
            );

        $permissions =
            rolesApiPermissions(
                $pdo,
                $tenantId,
                $planId
            );

        $selected =
            rolesApiSelectedPermissionIds(
                $pdo,
                $tenantId,
                $roleId
            );

        rolesApiResponse(
            200,
            true,
            'Role loaded.',
            array(
                'role' =>
                    $role,
                'permissions' =>
                    $permissions,
                'selected_permission_ids' =>
                    $selected
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE ROLE
    |--------------------------------------------------------------------------
    */
    if ($action === 'save') {

        $roleId =
            (int)rolesApiPost(
                'role_id',
                0
            );

        $name =
            trim(
                (string)rolesApiPost(
                    'name',
                    ''
                )
            );

        $code =
            rolesApiSlug(
                rolesApiPost(
                    'code',
                    $name
                )
            );

        $description =
            trim(
                (string)rolesApiPost(
                    'description',
                    ''
                )
            );

        $isAdmin =
            rolesApiPost(
                'is_admin',
                ''
            ) !== ''
                ? 1
                : 0;

        $status =
            trim(
                (string)rolesApiPost(
                    'status',
                    'active'
                )
            );

        if ($name === '') {
            rolesApiResponse(
                422,
                false,
                'Role name is required.'
            );
        }

        if ($code === '') {
            rolesApiResponse(
                422,
                false,
                'Role code is required.'
            );
        }

        if (
            !in_array(
                $status,
                array(
                    'active',
                    'inactive'
                ),
                true
            )
        ) {
            rolesApiResponse(
                422,
                false,
                'Invalid role status.'
            );
        }

        $allowedPermissions =
            rolesApiPermissions(
                $pdo,
                $tenantId,
                $planId
            );

        $allowedIds = array();

        foreach (
            $allowedPermissions
            as $permission
        ) {
            $allowedIds[
                (int)$permission['id']
            ] = true;
        }

        $postedPermissionIds =
            isset(
                $_POST[
                    'permission_ids'
                ]
            )
                ? (array)$_POST[
                    'permission_ids'
                ]
                : array();

        $permissionIds = array();

        foreach (
            $postedPermissionIds
            as $permissionId
        ) {
            $permissionId =
                (int)$permissionId;

            if (
                $permissionId > 0 &&
                isset(
                    $allowedIds[
                        $permissionId
                    ]
                )
            ) {
                $permissionIds[] =
                    $permissionId;
            }
        }

        $permissionIds =
            array_values(
                array_unique(
                    $permissionIds
                )
            );

        $duplicateSql = "
            SELECT id
            FROM roles
            WHERE tenant_id = :tenant_id
              AND code = :code
        ";

        $duplicateParams =
            array(
                ':tenant_id' =>
                    $tenantId,
                ':code' =>
                    $code
            );

        if ($roleId > 0) {
            $duplicateSql .=
                " AND id <> :id";

            $duplicateParams[
                ':id'
            ] = $roleId;
        }

        $duplicateStmt =
            $pdo->prepare(
                $duplicateSql
            );

        $duplicateStmt->execute(
            $duplicateParams
        );

        if (
            $duplicateStmt->fetchColumn()
        ) {
            rolesApiResponse(
                409,
                false,
                'Role code already exists.'
            );
        }

        $pdo->beginTransaction();

        try {

            $oldRole = null;
            $oldPermissionIds = array();

            if ($roleId > 0) {

                $oldRole =
                    rolesApiRole(
                        $pdo,
                        $tenantId,
                        $roleId
                    );

                $oldPermissionIds =
                    rolesApiSelectedPermissionIds(
                        $pdo,
                        $tenantId,
                        $roleId
                    );

                if (
                    (int)$oldRole[
                        'is_system_role'
                    ] === 1
                ) {
                    /*
                     * Protect system role identity.
                     */
                    $name =
                        (string)$oldRole['name'];

                    $code =
                        (string)$oldRole['code'];

                    $isAdmin =
                        (int)$oldRole['is_admin'];
                }

                $stmt =
                    $pdo->prepare("
                        UPDATE roles
                        SET
                            name = :name,
                            code = :code,
                            description = :description,
                            is_admin = :is_admin,
                            status = :status
                        WHERE id = :id
                          AND tenant_id = :tenant_id
                    ");

                $stmt->execute(
                    array(
                        ':name' =>
                            $name,
                        ':code' =>
                            $code,
                        ':description' =>
                            $description !== ''
                                ? $description
                                : null,
                        ':is_admin' =>
                            $isAdmin,
                        ':status' =>
                            $status,
                        ':id' =>
                            $roleId,
                        ':tenant_id' =>
                            $tenantId
                    )
                );

            } else {

                $stmt =
                    $pdo->prepare("
                        INSERT INTO roles (
                            tenant_id,
                            name,
                            code,
                            description,
                            is_admin,
                            is_system_role,
                            status
                        ) VALUES (
                            :tenant_id,
                            :name,
                            :code,
                            :description,
                            :is_admin,
                            0,
                            :status
                        )
                    ");

                $stmt->execute(
                    array(
                        ':tenant_id' =>
                            $tenantId,
                        ':name' =>
                            $name,
                        ':code' =>
                            $code,
                        ':description' =>
                            $description !== ''
                                ? $description
                                : null,
                        ':is_admin' =>
                            $isAdmin,
                        ':status' =>
                            $status
                    )
                );

                $roleId =
                    (int)$pdo->lastInsertId();
            }

            /*
            |--------------------------------------------------------------------------
            | Save role permissions
            |--------------------------------------------------------------------------
            */
            $deletePermissionStmt =
                $pdo->prepare("
                    DELETE FROM role_permissions
                    WHERE tenant_id = :tenant_id
                      AND role_id = :role_id
                ");

            $deletePermissionStmt->execute(
                array(
                    ':tenant_id' =>
                        $tenantId,
                    ':role_id' =>
                        $roleId
                )
            );

            if (!empty($permissionIds)) {

                $insertPermissionStmt =
                    $pdo->prepare("
                        INSERT INTO role_permissions (
                            tenant_id,
                            role_id,
                            permission_id,
                            access_type
                        ) VALUES (
                            :tenant_id,
                            :role_id,
                            :permission_id,
                            'allow'
                        )
                    ");

                foreach (
                    $permissionIds
                    as $permissionId
                ) {
                    $insertPermissionStmt->execute(
                        array(
                            ':tenant_id' =>
                                $tenantId,
                            ':role_id' =>
                                $roleId,
                            ':permission_id' =>
                                $permissionId
                        )
                    );
                }
            }

            $pdo->commit();

            $newRole =
                rolesApiRole(
                    $pdo,
                    $tenantId,
                    $roleId
                );

            $eventType =
                $oldRole
                    ? 'role_updated'
                    : 'role_created';

            $title =
                $oldRole
                    ? 'Role updated: ' .
                      $newRole['name']
                    : 'Role created: ' .
                      $newRole['name'];

            rolesApiLogActivity(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                $eventType,
                $roleId,
                $title,
                array(
                    'role' =>
                        $newRole,
                    'permission_ids' =>
                        $permissionIds
                )
            );

            rolesApiLogAudit(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                $oldRole
                    ? 'ROLE_UPDATED'
                    : 'ROLE_CREATED',
                $roleId,
                $oldRole
                    ? array(
                        'role' =>
                            $oldRole,
                        'permission_ids' =>
                            $oldPermissionIds
                    )
                    : null,
                array(
                    'role' =>
                        $newRole,
                    'permission_ids' =>
                        $permissionIds
                )
            );

            rolesApiResponse(
                200,
                true,
                $oldRole
                    ? 'Role updated successfully.'
                    : 'Role created successfully.',
                array(
                    'role_id' =>
                        $roleId
                )
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHANGE STATUS
    |--------------------------------------------------------------------------
    */
    if ($action === 'change_status') {

        $roleId =
            (int)rolesApiPost(
                'role_id',
                0
            );

        $status =
            trim(
                (string)rolesApiPost(
                    'status',
                    ''
                )
            );

        if (
            $roleId <= 0 ||
            !in_array(
                $status,
                array(
                    'active',
                    'inactive'
                ),
                true
            )
        ) {
            rolesApiResponse(
                422,
                false,
                'Invalid role status request.'
            );
        }

        $oldRole =
            rolesApiRole(
                $pdo,
                $tenantId,
                $roleId
            );

        $stmt =
            $pdo->prepare("
                UPDATE roles
                SET status = :status
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");

        $stmt->execute(
            array(
                ':status' =>
                    $status,
                ':id' =>
                    $roleId,
                ':tenant_id' =>
                    $tenantId
            )
        );

        $newRole =
            rolesApiRole(
                $pdo,
                $tenantId,
                $roleId
            );

        rolesApiLogActivity(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'role_status_changed',
            $roleId,
            'Role status changed: ' .
            $newRole['name'],
            array(
                'old_status' =>
                    $oldRole['status'],
                'new_status' =>
                    $newRole['status']
            )
        );

        rolesApiLogAudit(
            $pdo,
            $tenantId,
            $branchId,
            $userId,
            'ROLE_STATUS_CHANGED',
            $roleId,
            array(
                'status' =>
                    $oldRole['status']
            ),
            array(
                'status' =>
                    $newRole['status']
            )
        );

        rolesApiResponse(
            200,
            true,
            'Role status updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE ROLE
    |--------------------------------------------------------------------------
    */
    if ($action === 'delete') {

        $roleId =
            (int)rolesApiPost(
                'role_id',
                0
            );

        if ($roleId <= 0) {
            rolesApiResponse(
                422,
                false,
                'Invalid role.'
            );
        }

        $role =
            rolesApiRole(
                $pdo,
                $tenantId,
                $roleId
            );

        if (
            (int)$role[
                'is_system_role'
            ] === 1
        ) {
            rolesApiResponse(
                409,
                false,
                'System roles cannot be deleted.'
            );
        }

        $userCountStmt =
            $pdo->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE tenant_id = :tenant_id
                  AND role_id = :role_id
                  AND deleted_at IS NULL
            ");

        $userCountStmt->execute(
            array(
                ':tenant_id' =>
                    $tenantId,
                ':role_id' =>
                    $roleId
            )
        );

        if (
            (int)$userCountStmt->fetchColumn()
            > 0
        ) {
            rolesApiResponse(
                409,
                false,
                'This role is assigned to users. Reassign those users before deleting the role.'
            );
        }

        $permissionIds =
            rolesApiSelectedPermissionIds(
                $pdo,
                $tenantId,
                $roleId
            );

        $pdo->beginTransaction();

        try {

            $deletePermissions =
                $pdo->prepare("
                    DELETE FROM role_permissions
                    WHERE tenant_id = :tenant_id
                      AND role_id = :role_id
                ");

            $deletePermissions->execute(
                array(
                    ':tenant_id' =>
                        $tenantId,
                    ':role_id' =>
                        $roleId
                )
            );

            $deleteRole =
                $pdo->prepare("
                    DELETE FROM roles
                    WHERE id = :id
                      AND tenant_id = :tenant_id
                ");

            $deleteRole->execute(
                array(
                    ':id' =>
                        $roleId,
                    ':tenant_id' =>
                        $tenantId
                )
            );

            $pdo->commit();

            rolesApiLogActivity(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                'role_deleted',
                $roleId,
                'Role deleted: ' .
                $role['name'],
                array(
                    'role' =>
                        $role,
                    'permission_ids' =>
                        $permissionIds
                )
            );

            rolesApiLogAudit(
                $pdo,
                $tenantId,
                $branchId,
                $userId,
                'ROLE_DELETED',
                $roleId,
                array(
                    'role' =>
                        $role,
                    'permission_ids' =>
                        $permissionIds
                ),
                null
            );

            rolesApiResponse(
                200,
                true,
                'Role deleted successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    rolesApiResponse(
        400,
        false,
        'Unsupported roles action.'
    );

} catch (Throwable $e) {

    error_log(
        'FieldPlx roles API error: ' .
        $e->getMessage()
    );

    rolesApiResponse(
        500,
        false,
        'Unable to process the roles request.'
    );
}
