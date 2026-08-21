<?php
/**
 * FieldPlx Roles and Permissions
 *
 * File:
 * includes/permissions.php
 *
 * Permission priority:
 *
 * 1. Tenant module / feature availability
 * 2. User-specific deny
 * 3. User-specific allow
 * 4. Role permission
 * 5. No permission
 *
 * Compatible with:
 * - PHP 7.2+
 * - MySQLi
 * - MariaDB / MySQL
 */

require_once __DIR__ . '/auth.php';

/*
|--------------------------------------------------------------------------
| Permission cache
|--------------------------------------------------------------------------
*/

$GLOBALS['fieldplx_permissions'] = null;
$GLOBALS['fieldplx_permission_meta'] = null;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('permissionNormaliseCode')) {
    function permissionNormaliseCode($value)
    {
        $value = strtolower(
            trim((string) $value)
        );

        $value = preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $value
        );

        return trim(
            (string) $value,
            '_'
        );
    }
}

if (!function_exists('permissionTableExists')) {
    function permissionTableExists(
        mysqli $conn,
        $tableName
    ) {
        if (function_exists('dbTableExists')) {
            return dbTableExists(
                $conn,
                $tableName
            );
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            's',
            $tableName
        );

        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        return !empty($row['total']);
    }
}

if (!function_exists('permissionColumns')) {
    function permissionColumns(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        if (
            !permissionTableExists(
                $conn,
                $tableName
            )
        ) {
            return $cache[$tableName];
        }

        $safeTable = str_replace(
            '`',
            '``',
            $tableName
        );

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTable}`"
        );

        while (
            $row =
            $result->fetch_assoc()
        ) {
            if (!empty($row['Field'])) {
                $cache[$tableName][
                    (string) $row['Field']
                ] = $row;
            }
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('permissionFirstColumn')) {
    function permissionFirstColumn(
        array $columns,
        array $candidates
    ) {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('permissionDeniedResponse')) {
    function permissionDeniedResponse(
        $message,
        $permissionCodes = array()
    ) {
        if (!is_array($permissionCodes)) {
            $permissionCodes = array(
                (string) $permissionCodes
            );
        }

        if (isAjaxRequest()) {
            if (function_exists('jsonResponse')) {
                jsonResponse(
                    false,
                    $message,
                    array(),
                    403,
                    array(
                        'error_code' =>
                            'permission_denied',
                        'permissions' =>
                            array_values(
                                $permissionCodes
                            )
                    )
                );
            }

            http_response_code(403);

            header(
                'Content-Type: application/json; charset=UTF-8'
            );

            echo json_encode(
                array(
                    'success' => false,
                    'message' => $message,
                    'error_code' =>
                        'permission_denied',
                    'permissions' =>
                        array_values(
                            $permissionCodes
                        )
                ),
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            exit;
        }

        http_response_code(403);

        $pageTitle =
            'Access Denied - FieldPlx';

        $activePage = '';

        $errorMessage = $message;

        $requiredPermission =
            implode(
                ', ',
                $permissionCodes
            );

        $errorPage =
            dirname(__DIR__) .
            '/403.php';

        if (file_exists($errorPage)) {
            require $errorPage;
            exit;
        }

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Access Denied</title>';
        echo '<style>';
        echo 'body{margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#111827;}';
        echo '.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}';
        echo '.card{max-width:440px;padding:28px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;text-align:center;box-shadow:0 12px 36px rgba(17,24,39,.08);}';
        echo 'h1{margin:0;font-size:21px;}';
        echo 'p{margin:10px 0 0;color:#6b7280;font-size:13px;line-height:1.6;}';
        echo 'a{margin-top:18px;padding:10px 16px;display:inline-block;border-radius:9px;background:#7c3aed;color:#fff;text-decoration:none;font-size:12px;font-weight:700;}';
        echo '</style>';
        echo '</head>';
        echo '<body>';
        echo '<div class="wrap"><div class="card">';
        echo '<h1>403 - Access Denied</h1>';
        echo '<p>' . e($message) . '</p>';
        echo '<a href="dashboard.php">Back to Dashboard</a>';
        echo '</div></div>';
        echo '</body>';
        echo '</html>';

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Load permission metadata
|--------------------------------------------------------------------------
*/

if (!function_exists('loadPermissionMeta')) {
    function loadPermissionMeta($forceReload = false)
    {
        global $conn;

        if (
            !$forceReload &&
            is_array(
                $GLOBALS[
                    'fieldplx_permission_meta'
                ]
            )
        ) {
            return $GLOBALS[
                'fieldplx_permission_meta'
            ];
        }

        $meta = array();

        if (
            !permissionTableExists(
                $conn,
                'permissions'
            )
        ) {
            $GLOBALS[
                'fieldplx_permission_meta'
            ] = $meta;

            return $meta;
        }

        $columns = permissionColumns(
            $conn,
            'permissions'
        );

        $idColumn =
            permissionFirstColumn(
                $columns,
                array('id', 'permission_id')
            );

        $codeColumn =
            permissionFirstColumn(
                $columns,
                array('code', 'permission_code')
            );

        $nameColumn =
            permissionFirstColumn(
                $columns,
                array('name', 'permission_name')
            );

        $moduleColumn =
            permissionFirstColumn(
                $columns,
                array(
                    'module_code',
                    'module',
                    'module_name'
                )
            );

        $featureColumn =
            permissionFirstColumn(
                $columns,
                array(
                    'feature_code',
                    'feature'
                )
            );

        $activeColumn =
            permissionFirstColumn(
                $columns,
                array('is_active', 'active')
            );

        if (
            $idColumn === '' ||
            $codeColumn === ''
        ) {
            $GLOBALS[
                'fieldplx_permission_meta'
            ] = $meta;

            return $meta;
        }

        $select = array(
            "`{$idColumn}` AS permission_id",
            "`{$codeColumn}` AS permission_code"
        );

        $select[] =
            $nameColumn !== ''
                ? "`{$nameColumn}` AS permission_name"
                : "'' AS permission_name";

        $select[] =
            $moduleColumn !== ''
                ? "`{$moduleColumn}` AS module_code"
                : "'' AS module_code";

        $select[] =
            $featureColumn !== ''
                ? "`{$featureColumn}` AS feature_code"
                : "'' AS feature_code";

        $sql = "
            SELECT
                " .
                implode(
                    ",\n                ",
                    $select
                ) .
                "
            FROM permissions
        ";

        if ($activeColumn !== '') {
            $sql .= "
                WHERE `{$activeColumn}` = 1
            ";
        }

        $sql .= "
            ORDER BY `{$codeColumn}` ASC
        ";

        $result = $conn->query($sql);

        while (
            $row =
            $result->fetch_assoc()
        ) {
            $code =
                permissionNormaliseCode(
                    $row['permission_code']
                );

            if ($code === '') {
                continue;
            }

            $meta[$code] = array(
                'id' =>
                    (int)
                    $row['permission_id'],
                'code' =>
                    $code,
                'name' =>
                    (string)
                    $row['permission_name'],
                'module_code' =>
                    permissionNormaliseCode(
                        $row['module_code']
                    ),
                'feature_code' =>
                    permissionNormaliseCode(
                        $row['feature_code']
                    )
            );
        }

        $result->free();

        $GLOBALS[
            'fieldplx_permission_meta'
        ] = $meta;

        return $meta;
    }
}

/*
|--------------------------------------------------------------------------
| Load current user's permissions
|--------------------------------------------------------------------------
*/

if (!function_exists('loadUserPermissions')) {
    function loadUserPermissions($forceReload = false)
    {
        global $conn;

        if (
            !$forceReload &&
            is_array(
                $GLOBALS[
                    'fieldplx_permissions'
                ]
            )
        ) {
            return $GLOBALS[
                'fieldplx_permissions'
            ];
        }

        $tenantId = currentTenantId();
        $userId = currentUserId();
        $roleId = currentRoleId();

        $permissions = array();
        $permissionMeta =
            loadPermissionMeta(
                $forceReload
            );

        if (
            $tenantId <= 0 ||
            $userId <= 0
        ) {
            $GLOBALS[
                'fieldplx_permissions'
            ] = $permissions;

            return $permissions;
        }

        if (empty($permissionMeta)) {
            $GLOBALS[
                'fieldplx_permissions'
            ] = $permissions;

            return $permissions;
        }

        /*
         * Tenant owner starts with all active permissions.
         */
        if (isTenantOwner()) {
            foreach (
                $permissionMeta as
                $permissionCode =>
                $permissionDetails
            ) {
                $permissions[
                    $permissionCode
                ] = true;
            }
        } elseif (
            $roleId > 0 &&
            permissionTableExists(
                $conn,
                'role_permissions'
            )
        ) {
            $rolePermissionColumns =
                permissionColumns(
                    $conn,
                    'role_permissions'
                );

            $roleColumn =
                permissionFirstColumn(
                    $rolePermissionColumns,
                    array('role_id')
                );

            $permissionColumn =
                permissionFirstColumn(
                    $rolePermissionColumns,
                    array('permission_id')
                );

            $tenantColumn =
                permissionFirstColumn(
                    $rolePermissionColumns,
                    array('tenant_id')
                );

            $allowedColumn =
                permissionFirstColumn(
                    $rolePermissionColumns,
                    array(
                        'is_allowed',
                        'allowed',
                        'is_enabled'
                    )
                );

            if (
                $roleColumn !== '' &&
                $permissionColumn !== ''
            ) {
                $sql = "
                    SELECT
                        p.code AS permission_code
                    FROM role_permissions rp
                    INNER JOIN permissions p
                        ON p.id =
                           rp.`{$permissionColumn}`
                    WHERE rp.`{$roleColumn}` = ?
                ";

                $params = array($roleId);
                $types = 'i';

                if ($tenantColumn !== '') {
                    $sql .= "
                        AND (
                            rp.`{$tenantColumn}` = ?
                            OR rp.`{$tenantColumn}` IS NULL
                        )
                    ";

                    $params[] = $tenantId;
                    $types .= 'i';
                }

                if ($allowedColumn !== '') {
                    $sql .= "
                        AND rp.`{$allowedColumn}` = 1
                    ";
                }

                $stmt = $conn->prepare($sql);

                if ($stmt) {
                    if (
                        function_exists(
                            'dbBindParams'
                        )
                    ) {
                        dbBindParams(
                            $stmt,
                            $types,
                            $params
                        );
                    } else {
                        $arguments =
                            array($types);

                        foreach (
                            $params as
                            $key =>
                            $value
                        ) {
                            $arguments[] =
                                &$params[$key];
                        }

                        call_user_func_array(
                            array(
                                $stmt,
                                'bind_param'
                            ),
                            $arguments
                        );
                    }

                    $stmt->execute();

                    $result =
                        $stmt->get_result();

                    while (
                        $row =
                        $result->fetch_assoc()
                    ) {
                        $code =
                            permissionNormaliseCode(
                                $row[
                                    'permission_code'
                                ]
                            );

                        if ($code !== '') {
                            $permissions[$code] =
                                true;
                        }
                    }

                    $stmt->close();
                }
            }
        }

        /*
         * User overrides are applied last.
         */
        if (
            permissionTableExists(
                $conn,
                'user_permissions'
            )
        ) {
            $userPermissionColumns =
                permissionColumns(
                    $conn,
                    'user_permissions'
                );

            $userColumn =
                permissionFirstColumn(
                    $userPermissionColumns,
                    array('user_id')
                );

            $permissionColumn =
                permissionFirstColumn(
                    $userPermissionColumns,
                    array('permission_id')
                );

            $tenantColumn =
                permissionFirstColumn(
                    $userPermissionColumns,
                    array('tenant_id')
                );

            $effectColumn =
                permissionFirstColumn(
                    $userPermissionColumns,
                    array(
                        'effect',
                        'access_type'
                    )
                );

            $allowedColumn =
                permissionFirstColumn(
                    $userPermissionColumns,
                    array(
                        'is_allowed',
                        'allowed',
                        'is_enabled'
                    )
                );

            if (
                $userColumn !== '' &&
                $permissionColumn !== ''
            ) {
                $selectEffect = "1 AS permission_allowed";

                if ($effectColumn !== '') {
                    $selectEffect =
                        "up.`{$effectColumn}` AS permission_effect";
                } elseif ($allowedColumn !== '') {
                    $selectEffect =
                        "up.`{$allowedColumn}` AS permission_allowed";
                }

                $sql = "
                    SELECT
                        p.code AS permission_code,
                        {$selectEffect}
                    FROM user_permissions up
                    INNER JOIN permissions p
                        ON p.id =
                           up.`{$permissionColumn}`
                    WHERE up.`{$userColumn}` = ?
                ";

                $params = array($userId);
                $types = 'i';

                if ($tenantColumn !== '') {
                    $sql .= "
                        AND up.`{$tenantColumn}` = ?
                    ";

                    $params[] = $tenantId;
                    $types .= 'i';
                }

                $stmt = $conn->prepare($sql);

                if ($stmt) {
                    if (
                        function_exists(
                            'dbBindParams'
                        )
                    ) {
                        dbBindParams(
                            $stmt,
                            $types,
                            $params
                        );
                    } else {
                        $arguments = array($types);

                        foreach (
                            $params as
                            $key => $value
                        ) {
                            $arguments[] =
                                &$params[$key];
                        }

                        call_user_func_array(
                            array(
                                $stmt,
                                'bind_param'
                            ),
                            $arguments
                        );
                    }

                    $stmt->execute();

                    $result =
                        $stmt->get_result();

                    while (
                        $row =
                        $result->fetch_assoc()
                    ) {
                        $code =
                            permissionNormaliseCode(
                                $row[
                                    'permission_code'
                                ]
                            );

                        if ($code === '') {
                            continue;
                        }

                        if (
                            isset(
                                $row[
                                    'permission_effect'
                                ]
                            )
                        ) {
                            $effect =
                                strtolower(
                                    trim(
                                        (string)
                                        $row[
                                            'permission_effect'
                                        ]
                                    )
                                );

                            if (
                                in_array(
                                    $effect,
                                    array(
                                        'deny',
                                        'denied',
                                        'disabled',
                                        'exclude'
                                    ),
                                    true
                                )
                            ) {
                                $permissions[$code] =
                                    false;
                            } elseif (
                                in_array(
                                    $effect,
                                    array(
                                        'allow',
                                        'allowed',
                                        'enabled',
                                        'include'
                                    ),
                                    true
                                )
                            ) {
                                $permissions[$code] =
                                    true;
                            }
                        } else {
                            $permissions[$code] =
                                !empty(
                                    $row[
                                        'permission_allowed'
                                    ]
                                );
                        }
                    }

                    $stmt->close();
                }
            }
        }

        $GLOBALS[
            'fieldplx_permissions'
        ] = $permissions;

        return $permissions;
    }
}


/*
|--------------------------------------------------------------------------
| Resolve permission modules to actual tenant module codes
|--------------------------------------------------------------------------
*/

if (!function_exists('permissionResolveTenantModule')) {
    function permissionResolveTenantModule($moduleCode)
    {
        $moduleCode = permissionNormaliseCode($moduleCode);

        $map = array(
            'dashboard' => 'dashboard',
            'clients' => 'clients',
            'properties' => 'clients',
            'requests' => 'requests',
            'quotes' => 'quotes',
            'jobs' => 'jobs',
            'job_costing' => 'jobs',
            'schedule' => 'scheduling',
            'invoices' => 'invoices',
            'payments' => 'payments',
            'expenses' => 'payments',
            'team' => 'workers',
            'timesheets' => 'workers',
            'messages' => 'messages',
            'reports' => 'reports'
        );

        return isset($map[$moduleCode])
            ? $map[$moduleCode]
            : '';
    }
}

/*
|--------------------------------------------------------------------------
| Permission access checks
|--------------------------------------------------------------------------
*/

if (!function_exists('permissionPassesModuleFeature')) {
    function permissionPassesModuleFeature(
        $permissionCode
    ) {
        $permissionCode =
            permissionNormaliseCode(
                $permissionCode
            );

        $meta = loadPermissionMeta();

        if (!isset($meta[$permissionCode])) {
            return true;
        }

        $details = $meta[$permissionCode];

        $permissionModule =
            isset($details['module_code'])
                ? $details['module_code']
                : '';

        $featureCode =
            isset($details['feature_code'])
                ? $details['feature_code']
                : '';

        /*
         * Permission modules and tenant modules do not always use
         * the same code. Resolve only modules that actually exist
         * in the tenant module catalogue.
         */
        $tenantModule =
            permissionResolveTenantModule(
                $permissionModule
            );

        if (
            $tenantModule !== '' &&
            function_exists('tenantHasModule')
        ) {
            try {
                if (!tenantHasModule($tenantModule)) {
                    return false;
                }
            } catch (Throwable $moduleError) {
                error_log(
                    'Permission module check failed: ' .
                    $moduleError->getMessage()
                );

                /*
                 * Do not crash or deny an otherwise valid role
                 * permission because the module helper failed.
                 */
            }
        }

        if (
            $tenantModule !== '' &&
            $featureCode !== '' &&
            function_exists('tenantHasFeature')
        ) {
            try {
                if (
                    !tenantHasFeature(
                        $tenantModule,
                        $featureCode
                    )
                ) {
                    return false;
                }
            } catch (Throwable $featureError) {
                error_log(
                    'Permission feature check failed: ' .
                    $featureError->getMessage()
                );
            }
        }

        return true;
    }
}

if (!function_exists('hasPermission')) {
    function hasPermission($permissionCode)
    {
        $permissionCode =
            permissionNormaliseCode(
                $permissionCode
            );

        if ($permissionCode === '') {
            return false;
        }

        if (
            !permissionPassesModuleFeature(
                $permissionCode
            )
        ) {
            return false;
        }

        $permissions =
            loadUserPermissions();

        return isset(
            $permissions[$permissionCode]
        ) &&
        $permissions[$permissionCode] ===
            true;
    }
}

if (!function_exists('requirePermission')) {
    function requirePermission(
        $permissionCode,
        $message =
            'You do not have permission to access this page.'
    ) {
        if (
            hasPermission(
                $permissionCode
            )
        ) {
            return true;
        }

        permissionDeniedResponse(
            $message,
            array(
                permissionNormaliseCode(
                    $permissionCode
                )
            )
        );

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Any / all permission helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('hasAnyPermission')) {
    function hasAnyPermission(
        $permissionCodes
    ) {
        if (
            !is_array($permissionCodes) ||
            empty($permissionCodes)
        ) {
            return false;
        }

        foreach (
            $permissionCodes as
            $permissionCode
        ) {
            if (
                hasPermission(
                    $permissionCode
                )
            ) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('requireAnyPermission')) {
    function requireAnyPermission(
        $permissionCodes,
        $message =
            'You do not have permission to perform this action.'
    ) {
        if (
            hasAnyPermission(
                $permissionCodes
            )
        ) {
            return true;
        }

        permissionDeniedResponse(
            $message,
            (array) $permissionCodes
        );

        return false;
    }
}

if (!function_exists('hasAllPermissions')) {
    function hasAllPermissions(
        $permissionCodes
    ) {
        if (
            !is_array($permissionCodes) ||
            empty($permissionCodes)
        ) {
            return false;
        }

        foreach (
            $permissionCodes as
            $permissionCode
        ) {
            if (
                !hasPermission(
                    $permissionCode
                )
            ) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('requireAllPermissions')) {
    function requireAllPermissions(
        $permissionCodes,
        $message =
            'You do not have all required permissions.'
    ) {
        if (
            hasAllPermissions(
                $permissionCodes
            )
        ) {
            return true;
        }

        permissionDeniedResponse(
            $message,
            (array) $permissionCodes
        );

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Module-aware permission helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('hasModulePermission')) {
    function hasModulePermission(
        $moduleCode,
        $permissionCode
    ) {
        if (
            function_exists(
                'tenantHasModule'
            ) &&
            !tenantHasModule(
                $moduleCode
            )
        ) {
            return false;
        }

        return hasPermission(
            $permissionCode
        );
    }
}

if (!function_exists('requireModulePermission')) {
    function requireModulePermission(
        $moduleCode,
        $permissionCode,
        $message =
            'You do not have permission to access this module.'
    ) {
        if (
            hasModulePermission(
                $moduleCode,
                $permissionCode
            )
        ) {
            return true;
        }

        permissionDeniedResponse(
            $message,
            array(
                permissionNormaliseCode(
                    $permissionCode
                )
            )
        );

        return false;
    }
}

if (!function_exists('hasFeaturePermission')) {
    function hasFeaturePermission(
        $moduleCode,
        $featureCode,
        $permissionCode
    ) {
        if (
            function_exists(
                'tenantHasFeature'
            ) &&
            !tenantHasFeature(
                $moduleCode,
                $featureCode
            )
        ) {
            return false;
        }

        return hasPermission(
            $permissionCode
        );
    }
}

if (!function_exists('requireFeaturePermission')) {
    function requireFeaturePermission(
        $moduleCode,
        $featureCode,
        $permissionCode,
        $message =
            'You do not have permission to use this feature.'
    ) {
        if (
            hasFeaturePermission(
                $moduleCode,
                $featureCode,
                $permissionCode
            )
        ) {
            return true;
        }

        permissionDeniedResponse(
            $message,
            array(
                permissionNormaliseCode(
                    $permissionCode
                )
            )
        );

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Permission lists
|--------------------------------------------------------------------------
*/

if (!function_exists('getUserPermissionCodes')) {
    function getUserPermissionCodes()
    {
        $permissions =
            loadUserPermissions();

        $allowedPermissions =
            array();

        foreach (
            $permissions as
            $code =>
            $allowed
        ) {
            if (
                $allowed === true &&
                permissionPassesModuleFeature(
                    $code
                )
            ) {
                $allowedPermissions[] =
                    $code;
            }
        }

        sort(
            $allowedPermissions
        );

        return $allowedPermissions;
    }
}

if (!function_exists('getPermissionsByPrefix')) {
    function getPermissionsByPrefix(
        $prefix
    ) {
        $prefix =
            permissionNormaliseCode(
                $prefix
            );

        if ($prefix === '') {
            return array();
        }

        $matches = array();

        foreach (
            getUserPermissionCodes() as
            $permissionCode
        ) {
            if (
                strpos(
                    $permissionCode,
                    $prefix
                ) === 0
            ) {
                $matches[] =
                    $permissionCode;
            }
        }

        return $matches;
    }
}

/*
|--------------------------------------------------------------------------
| Cache controls
|--------------------------------------------------------------------------
*/

if (!function_exists('clearPermissionCache')) {
    function clearPermissionCache()
    {
        $GLOBALS[
            'fieldplx_permissions'
        ] = null;

        $GLOBALS[
            'fieldplx_permission_meta'
        ] = null;
    }
}

if (!function_exists('refreshPermissionCache')) {
    function refreshPermissionCache()
    {
        clearPermissionCache();

        return loadUserPermissions(
            true
        );
    }
}
