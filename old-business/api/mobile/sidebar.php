<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile Sidebar API
 *
 * URL:
 *   https://fieldplx.com/business/api/mobile/sidebar.php
 *
 * Expected structure:
 *   /business/includes/db.php
 *   /business/includes/api-config.php
 *   /business/api/mobile/sidebar.php
 *
 * Authentication:
 *   Authorization: Bearer <access_token>
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api-config.php';

/*
|--------------------------------------------------------------------------
| RESPONSE HEADERS
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| CONFIG VALIDATION
|--------------------------------------------------------------------------
*/
if (
    !defined('FIELDPLX_API_SECRET') ||
    trim((string)FIELDPLX_API_SECRET) === ''
) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'api_secret_not_configured',
            'message' => 'FieldPlx mobile API configuration is incomplete.'
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit;
}

if (!defined('FIELDPLX_TOKEN_ISSUER')) {
    define('FIELDPLX_TOKEN_ISSUER', 'FieldPlx');
}

if (!defined('FIELDPLX_TOKEN_AUDIENCE')) {
    define('FIELDPLX_TOKEN_AUDIENCE', 'FieldPlx-Mobile');
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function ms_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function ms_base64url_decode(string $data)
{
    $remainder = strlen($data) % 4;

    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(
        strtr($data, '-_', '+/'),
        true
    );
}

function ms_get_authorization_header(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strtolower((string)$name) === 'authorization') {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function ms_get_bearer_token(): string
{
    $header = ms_get_authorization_header();

    if ($header === '') {
        return '';
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return '';
    }

    return trim((string)$matches[1]);
}

function ms_verify_token(string $token): array
{
    $parts = explode('.', $token);

    if (count($parts) !== 3) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'invalid_token',
                'message' => 'Invalid access token.'
            ]
        ]);
    }

    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

    $headerJson = ms_base64url_decode($headerEncoded);
    $payloadJson = ms_base64url_decode($payloadEncoded);
    $signature = ms_base64url_decode($signatureEncoded);

    if (
        $headerJson === false ||
        $payloadJson === false ||
        $signature === false
    ) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'invalid_token',
                'message' => 'Invalid access token.'
            ]
        ]);
    }

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'invalid_token',
                'message' => 'Invalid access token.'
            ]
        ]);
    }

    if (
        ($header['alg'] ?? '') !== 'HS256' ||
        ($header['typ'] ?? '') !== 'JWT'
    ) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'invalid_token',
                'message' => 'Unsupported access token.'
            ]
        ]);
    }

    $expectedSignature = hash_hmac(
        'sha256',
        $headerEncoded . '.' . $payloadEncoded,
        FIELDPLX_API_SECRET,
        true
    );

    if (!hash_equals($expectedSignature, $signature)) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'invalid_token_signature',
                'message' => 'Invalid access token.'
            ]
        ]);
    }

    $now = time();

    if (
        isset($payload['nbf']) &&
        (int)$payload['nbf'] > $now
    ) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'token_not_active',
                'message' => 'Access token is not active yet.'
            ]
        ]);
    }

    if (
        !isset($payload['exp']) ||
        (int)$payload['exp'] <= $now
    ) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'token_expired',
                'message' => 'Access token has expired. Please sign in again.'
            ]
        ]);
    }

    if (
        isset($payload['iss']) &&
        (string)$payload['iss'] !== (string)FIELDPLX_TOKEN_ISSUER
    ) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'invalid_token_issuer',
                'message' => 'Invalid access token.'
            ]
        ]);
    }

    if (
        isset($payload['aud']) &&
        (string)$payload['aud'] !== (string)FIELDPLX_TOKEN_AUDIENCE
    ) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'invalid_token_audience',
                'message' => 'Invalid access token.'
            ]
        ]);
    }

    $userId = isset($payload['user_id'])
        ? (int)$payload['user_id']
        : 0;

    $tenantId = isset($payload['tenant_id'])
        ? (int)$payload['tenant_id']
        : 0;

    if ($userId <= 0 || $tenantId <= 0) {
        ms_response(401, [
            'success' => false,
            'error' => [
                'code' => 'invalid_token_context',
                'message' => 'Invalid access token.'
            ]
        ]);
    }

    return $payload;
}

function ms_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");

    $stmt->execute([
        ':table_name' => $table
    ]);

    $cache[$table] = ((int)$stmt->fetchColumn() > 0);

    return $cache[$table];
}

function ms_normalize_icon($icon): string
{
    $icon = trim((string)$icon);

    if ($icon === '') {
        return 'bi bi-grid';
    }

    if (strpos($icon, 'bi ') === 0) {
        return $icon;
    }

    if (strpos($icon, 'bi-') === 0) {
        return 'bi ' . $icon;
    }

    return $icon;
}

function ms_safe_menu_url($url): string
{
    $url = trim((string)$url);

    if ($url === '') {
        return '';
    }

    if (
        stripos($url, 'javascript:') === 0 ||
        stripos($url, 'data:') === 0
    ) {
        return '';
    }

    return $url;
}

/*
|--------------------------------------------------------------------------
| GET ONLY
|--------------------------------------------------------------------------
*/
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    ms_response(405, [
        'success' => false,
        'error' => [
            'code' => 'method_not_allowed',
            'message' => 'Only GET requests are allowed.'
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| AUTHENTICATE ACCESS TOKEN
|--------------------------------------------------------------------------
*/
$token = ms_get_bearer_token();

if ($token === '') {
    ms_response(401, [
        'success' => false,
        'error' => [
            'code' => 'authorization_required',
            'message' => 'Authorization Bearer token is required.'
        ]
    ]);
}

$claims = ms_verify_token($token);

$currentUserId = (int)$claims['user_id'];
$currentTenantId = (int)$claims['tenant_id'];

/*
|--------------------------------------------------------------------------
| VERIFY CURRENT USER + TENANT
|--------------------------------------------------------------------------
|
| Do not trust token context alone forever.
| Confirm the user and tenant still exist and are active.
|
*/
$userStmt = $pdo->prepare("
    SELECT
        u.id AS user_id,
        u.tenant_id,
        u.branch_id,
        u.department_id,
        u.role_id,
        u.first_name,
        u.last_name,
        u.email,
        u.avatar_path,
        u.job_title,
        u.is_tenant_admin,
        u.status AS user_status,

        t.tenant_code,
        t.display_name AS tenant_name,
        t.logo_path AS tenant_logo_path,
        t.status AS tenant_status,

        b.name AS branch_name,
        b.status AS branch_status,

        d.name AS department_name,
        d.status AS department_status,

        r.name AS role_name,
        r.code AS role_code,
        r.status AS role_status

    FROM users u

    INNER JOIN tenants t
        ON t.id = u.tenant_id
       AND t.deleted_at IS NULL

    LEFT JOIN branches b
        ON b.id = u.branch_id
       AND b.tenant_id = u.tenant_id

    LEFT JOIN departments d
        ON d.id = u.department_id
       AND d.tenant_id = u.tenant_id

    LEFT JOIN roles r
        ON r.id = u.role_id
       AND r.tenant_id = u.tenant_id

    WHERE u.id = :user_id
      AND u.tenant_id = :tenant_id
      AND u.deleted_at IS NULL

    LIMIT 1
");

$userStmt->execute([
    ':user_id' => $currentUserId,
    ':tenant_id' => $currentTenantId
]);

$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    ms_response(401, [
        'success' => false,
        'error' => [
            'code' => 'user_not_found',
            'message' => 'Authenticated user account was not found.'
        ]
    ]);
}

if ((string)$currentUser['user_status'] !== 'active') {
    ms_response(403, [
        'success' => false,
        'error' => [
            'code' => 'user_not_active',
            'message' => 'Your user account is not active.'
        ]
    ]);
}

if (
    !in_array(
        (string)$currentUser['tenant_status'],
        ['trial', 'active'],
        true
    )
) {
    ms_response(403, [
        'success' => false,
        'error' => [
            'code' => 'tenant_not_active',
            'message' => 'This business account is not active.'
        ]
    ]);
}

if (
    !empty($currentUser['branch_id']) &&
    !empty($currentUser['branch_status']) &&
    (string)$currentUser['branch_status'] !== 'active'
) {
    ms_response(403, [
        'success' => false,
        'error' => [
            'code' => 'branch_not_active',
            'message' => 'Your assigned branch is not active.'
        ]
    ]);
}

if (
    !empty($currentUser['department_id']) &&
    !empty($currentUser['department_status']) &&
    (string)$currentUser['department_status'] !== 'active'
) {
    ms_response(403, [
        'success' => false,
        'error' => [
            'code' => 'department_not_active',
            'message' => 'Your assigned department is not active.'
        ]
    ]);
}

if (
    !empty($currentUser['role_id']) &&
    !empty($currentUser['role_status']) &&
    (string)$currentUser['role_status'] !== 'active'
) {
    ms_response(403, [
        'success' => false,
        'error' => [
            'code' => 'role_not_active',
            'message' => 'Your assigned role is not active.'
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| RESOLVE CURRENT SUBSCRIPTION / PLAN
|--------------------------------------------------------------------------
*/
$currentPlanId = 0;
$currentSubscriptionId = 0;
$currentSubscriptionStatus = '';

if (ms_table_exists($pdo, 'subscriptions')) {
    $subStmt = $pdo->prepare("
        SELECT
            s.id AS subscription_id,
            s.plan_id,
            s.expiry_date,
            s.status
        FROM subscriptions s
        WHERE s.tenant_id = :tenant_id
        ORDER BY
            CASE s.status
                WHEN 'active' THEN 1
                WHEN 'trial' THEN 2
                WHEN 'suspended' THEN 3
                WHEN 'expired' THEN 4
                ELSE 5
            END,
            s.id DESC
        LIMIT 1
    ");

    $subStmt->execute([
        ':tenant_id' => $currentTenantId
    ]);

    $subscription = $subStmt->fetch(PDO::FETCH_ASSOC);

    if ($subscription) {
        $currentSubscriptionId =
            (int)$subscription['subscription_id'];

        $currentPlanId =
            (int)$subscription['plan_id'];

        $currentSubscriptionStatus =
            (string)$subscription['status'];

        if (
            !in_array(
                $currentSubscriptionStatus,
                ['trial', 'active'],
                true
            )
        ) {
            ms_response(403, [
                'success' => false,
                'error' => [
                    'code' => 'subscription_not_active',
                    'message' => 'Your subscription is not active.'
                ]
            ]);
        }

        if (
            !empty($subscription['expiry_date']) &&
            (string)$subscription['expiry_date'] < date('Y-m-d')
        ) {
            ms_response(403, [
                'success' => false,
                'error' => [
                    'code' => 'subscription_expired',
                    'message' => 'Your subscription has expired.'
                ]
            ]);
        }
    }
}

if ($currentPlanId <= 0) {
    ms_response(403, [
        'success' => false,
        'error' => [
            'code' => 'plan_not_found',
            'message' => 'No active subscription plan is available for this tenant.'
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| LOAD TENANT-ENTITLED SIDEBAR MODULES
|--------------------------------------------------------------------------
|
| Tenant entitlement layer:
| 1. Module must be included in plan_modules.
| 2. plan_modules.is_enabled = 1.
| 3. modules.is_active = 1.
| 4. modules.is_sidebar_item = 1.
| 5. tenant_modules='disabled' hides the module.
| 6. tenant_modules='enabled' does not bypass plan_modules.
|
| Permission filtering is applied immediately after this query.
|
*/
$tenantSidebarRows = [];
$sidebarRows = [];
$moduleActionAllowed = [];

$stmt = $pdo->prepare("
    SELECT
        m.id,
        m.parent_id,
        m.module_code,
        m.module_name,
        m.description,
        m.menu_url,
        m.icon_name,
        m.menu_order,
        m.is_core,
        m.is_sidebar_item,
        m.is_active,

        COALESCE(
            tm.access_type,
            'inherit'
        ) AS tenant_access_type,

        parent.id AS parent_module_id,
        parent.module_code AS parent_module_code,
        parent.module_name AS parent_module_name,
        parent.menu_url AS parent_menu_url,
        parent.icon_name AS parent_icon_name,
        parent.menu_order AS parent_menu_order,
        parent.is_active AS parent_is_active,
        parent.is_sidebar_item AS parent_is_sidebar_item

    FROM plan_modules pm

    INNER JOIN modules m
        ON m.id = pm.module_id

    LEFT JOIN tenant_modules tm
        ON tm.tenant_id = :tenant_id
       AND tm.module_id = m.id

    LEFT JOIN modules parent
        ON parent.id = m.parent_id

    WHERE pm.plan_id = :plan_id
      AND pm.is_enabled = 1
      AND m.is_active = 1
      AND m.is_sidebar_item = 1
      AND COALESCE(
            tm.access_type,
            'inherit'
          ) <> 'disabled'

    ORDER BY
        COALESCE(
            parent.menu_order,
            m.menu_order
        ) ASC,
        COALESCE(
            m.parent_id,
            m.id
        ) ASC,
        CASE
            WHEN m.parent_id IS NULL THEN 0
            ELSE 1
        END ASC,
        m.menu_order ASC,
        m.module_name ASC
");

$stmt->execute([
    ':tenant_id' => $currentTenantId,
    ':plan_id' => $currentPlanId
]);

$tenantSidebarRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| APPLY ROLE PERMISSIONS + USER OVERRIDES
|--------------------------------------------------------------------------
|
| Current web sidebar permission rules:
|
| - Sidebar visibility requires VIEW permission.
| - Quick Create requires CREATE permission.
| - user_permissions allow/deny wins when a user override exists.
| - otherwise role_permissions allow/deny is used.
| - missing permission / missing grant = denied.
|
*/
$currentRoleId = !empty($currentUser['role_id'])
    ? (int)$currentUser['role_id']
    : 0;

if (!empty($tenantSidebarRows)) {

    $moduleIds = [];

    foreach ($tenantSidebarRows as $tenantSidebarRow) {
        $moduleIds[] = (int)$tenantSidebarRow['id'];
    }

    $moduleIds = array_values(array_unique($moduleIds));

    if (!empty($moduleIds)) {
        $modulePlaceholders = implode(
            ',',
            array_fill(0, count($moduleIds), '?')
        );

        $hasUserPermissions = ms_table_exists(
            $pdo,
            'user_permissions'
        );

        $permissionSql = "
            SELECT
                p.module_id,
                p.action_code,
                rp.access_type AS role_access";

        if ($hasUserPermissions) {
            $permissionSql .= ",
                up.access_type AS user_access";
        } else {
            $permissionSql .= ",
                NULL AS user_access";
        }

        $permissionSql .= "
            FROM permissions p

            LEFT JOIN role_permissions rp
                ON rp.permission_id = p.id
               AND rp.tenant_id = ?
               AND rp.role_id = ?";

        if ($hasUserPermissions) {
            $permissionSql .= "

            LEFT JOIN user_permissions up
                ON up.permission_id = p.id
               AND up.tenant_id = ?
               AND up.user_id = ?";
        }

        $permissionSql .= "

            WHERE p.module_id IN ($modulePlaceholders)
              AND p.action_code IN ('view','create')
            ORDER BY p.module_id, p.action_code, p.id
        ";

        $permissionParams = [
            $currentTenantId,
            $currentRoleId
        ];

        if ($hasUserPermissions) {
            $permissionParams[] = $currentTenantId;
            $permissionParams[] = $currentUserId;
        }

        foreach ($moduleIds as $moduleId) {
            $permissionParams[] = (int)$moduleId;
        }

        $permissionStmt = $pdo->prepare($permissionSql);
        $permissionStmt->execute($permissionParams);

        foreach (
            $permissionStmt->fetchAll(PDO::FETCH_ASSOC)
            as $permissionRow
        ) {
            $moduleId = (int)$permissionRow['module_id'];

            $actionCode = strtolower(
                trim((string)$permissionRow['action_code'])
            );

            $roleAccess = strtolower(
                trim((string)($permissionRow['role_access'] ?? ''))
            );

            $userAccess = strtolower(
                trim((string)($permissionRow['user_access'] ?? ''))
            );

            $effectiveAccess = $userAccess !== ''
                ? $userAccess
                : $roleAccess;

            if (!isset($moduleActionAllowed[$moduleId])) {
                $moduleActionAllowed[$moduleId] = [];
            }

            $moduleActionAllowed[$moduleId][$actionCode] =
                $effectiveAccess === 'allow';
        }
    }

    foreach ($tenantSidebarRows as $tenantSidebarRow) {
        $moduleId = (int)$tenantSidebarRow['id'];

        if (
            !empty(
                $moduleActionAllowed[$moduleId]['view']
            )
        ) {
            $sidebarRows[] = $tenantSidebarRow;
        }
    }
}

/*
|--------------------------------------------------------------------------
| BUILD HIERARCHY
|--------------------------------------------------------------------------
*/
$moduleById = [];
$childrenByParent = [];
$topLevelIds = [];

foreach ($sidebarRows as $row) {
    $moduleId = (int)$row['id'];
    $moduleById[$moduleId] = $row;
}

foreach ($sidebarRows as $row) {
    $moduleId = (int)$row['id'];

    $parentId = !empty($row['parent_id'])
        ? (int)$row['parent_id']
        : 0;

    if ($parentId <= 0) {
        $topLevelIds[] = $moduleId;
        continue;
    }

    if (!isset($childrenByParent[$parentId])) {
        $childrenByParent[$parentId] = [];
    }

    $childrenByParent[$parentId][] = $row;
}

/*
|--------------------------------------------------------------------------
| LOAD GROUP-ONLY PARENTS
|--------------------------------------------------------------------------
|
| Same behavior as web:
| A parent can be returned as a visual grouping row even when the parent itself
| is not in plan_modules, as long as an enabled child belongs to it.
|
*/
$missingParentIds = [];

foreach (array_keys($childrenByParent) as $parentId) {
    if (!isset($moduleById[$parentId])) {
        $missingParentIds[] = (int)$parentId;
    }
}

if (!empty($missingParentIds)) {
    $placeholders = implode(
        ',',
        array_fill(0, count($missingParentIds), '?')
    );

    $parentStmt = $pdo->prepare("
        SELECT
            id,
            parent_id,
            module_code,
            module_name,
            description,
            menu_url,
            icon_name,
            menu_order,
            is_core,
            is_sidebar_item,
            is_active
        FROM modules
        WHERE id IN ($placeholders)
          AND is_active = 1
          AND is_sidebar_item = 1
    ");

    $parentStmt->execute($missingParentIds);

    foreach ($parentStmt->fetchAll(PDO::FETCH_ASSOC) as $parentRow) {
        $parentId = (int)$parentRow['id'];

        $parentRow['tenant_access_type'] = 'group_only';

        $moduleById[$parentId] = $parentRow;
        $topLevelIds[] = $parentId;
    }
}

/*
|--------------------------------------------------------------------------
| ENSURE ACCESSIBLE PARENTS ARE TOP LEVEL
|--------------------------------------------------------------------------
*/
foreach ($moduleById as $moduleId => $module) {
    if (
        empty($module['parent_id']) &&
        !in_array((int)$moduleId, $topLevelIds, true)
    ) {
        $topLevelIds[] = (int)$moduleId;
    }
}

$topLevelIds = array_values(
    array_unique($topLevelIds)
);

usort(
    $topLevelIds,
    function ($a, $b) use ($moduleById) {
        $aRow = $moduleById[$a] ?? [];
        $bRow = $moduleById[$b] ?? [];

        $aOrder = isset($aRow['menu_order'])
            ? (int)$aRow['menu_order']
            : 0;

        $bOrder = isset($bRow['menu_order'])
            ? (int)$bRow['menu_order']
            : 0;

        if ($aOrder === $bOrder) {
            return strcasecmp(
                (string)($aRow['module_name'] ?? ''),
                (string)($bRow['module_name'] ?? '')
            );
        }

        return $aOrder <=> $bOrder;
    }
);

/*
|--------------------------------------------------------------------------
| FORMAT ANDROID MENU
|--------------------------------------------------------------------------
*/
$menu = [];

foreach ($topLevelIds as $parentId) {
    if (!isset($moduleById[$parentId])) {
        continue;
    }

    $parent = $moduleById[$parentId];

    $children = $childrenByParent[$parentId] ?? [];

    usort(
        $children,
        function ($a, $b) {
            $aOrder = isset($a['menu_order'])
                ? (int)$a['menu_order']
                : 0;

            $bOrder = isset($b['menu_order'])
                ? (int)$b['menu_order']
                : 0;

            if ($aOrder === $bOrder) {
                return strcasecmp(
                    (string)($a['module_name'] ?? ''),
                    (string)($b['module_name'] ?? '')
                );
            }

            return $aOrder <=> $bOrder;
        }
    );

    $parentAccessible =
        isset($parent['tenant_access_type']) &&
        (string)$parent['tenant_access_type'] !== 'group_only';

    if (!$parentAccessible && empty($children)) {
        continue;
    }

    $childItems = [];

    foreach ($children as $child) {
        $childItems[] = [
            'id' => (int)$child['id'],
            'parent_id' => !empty($child['parent_id'])
                ? (int)$child['parent_id']
                : null,
            'module_code' => (string)$child['module_code'],
            'module_name' => (string)$child['module_name'],
            'description' => (string)($child['description'] ?? ''),
            'menu_url' => ms_safe_menu_url($child['menu_url'] ?? ''),
            'icon_name' => ms_normalize_icon($child['icon_name'] ?? ''),
            'menu_order' => (int)$child['menu_order'],
            'is_core' => (bool)$child['is_core'],
            'access_type' => (string)$child['tenant_access_type'],
            'permissions' => [
                'view' => !empty(
                    $moduleActionAllowed[(int)$child['id']]['view']
                ),
                'create' => !empty(
                    $moduleActionAllowed[(int)$child['id']]['create']
                )
            ],
            'is_group' => false,
            'children' => []
        ];
    }

    $menu[] = [
        'id' => (int)$parent['id'],
        'parent_id' => !empty($parent['parent_id'])
            ? (int)$parent['parent_id']
            : null,
        'module_code' => (string)$parent['module_code'],
        'module_name' => (string)$parent['module_name'],
        'description' => (string)($parent['description'] ?? ''),
        'menu_url' => $parentAccessible
            ? ms_safe_menu_url($parent['menu_url'] ?? '')
            : '',
        'icon_name' => ms_normalize_icon($parent['icon_name'] ?? ''),
        'menu_order' => (int)$parent['menu_order'],
        'is_core' => (bool)$parent['is_core'],
        'access_type' => (string)$parent['tenant_access_type'],
        'permissions' => [
            'view' => $parentAccessible
                ? !empty(
                    $moduleActionAllowed[(int)$parent['id']]['view']
                )
                : false,
            'create' => $parentAccessible
                ? !empty(
                    $moduleActionAllowed[(int)$parent['id']]['create']
                )
                : false
        ],
        'is_group' => !$parentAccessible || !empty($childItems),
        'is_accessible' => $parentAccessible,
        'children' => $childItems
    ];
}

/*
|--------------------------------------------------------------------------
| QUICK CREATE
|--------------------------------------------------------------------------
|
| Matches current web sidebar:
| - module must be effectively visible
| - CREATE permission is required
| - user permission override wins over role permission
|
*/
$quickCreateDefinitions = [
    'client' => [
        'module_codes' => ['clients'],
        'label' => 'Client',
        'url' => 'client-form.php',
        'icon' => 'bi bi-person',
        'tone' => 'client'
    ],
    'request' => [
        'module_codes' => ['requests'],
        'label' => 'Request',
        'url' => 'add-request.php',
        'icon' => 'bi bi-inbox',
        'tone' => 'request'
    ],
    'quote' => [
        'module_codes' => ['quotes', 'quotation'],
        'label' => 'Quote',
        'url' => 'add-quotation.php',
        'icon' => 'bi bi-file-earmark-text',
        'tone' => 'quote'
    ],
    'job' => [
        'module_codes' => ['jobs', 'job-cards'],
        'label' => 'Job',
        'url' => 'job-form.php',
        'icon' => 'bi bi-hammer',
        'tone' => 'job'
    ],
    'invoice' => [
        'module_codes' => ['invoices'],
        'label' => 'Invoice',
        'url' => 'add-invoice.php',
        'icon' => 'bi bi-receipt',
        'tone' => 'invoice'
    ]
];

$effectiveModulesByCode = [];

foreach ($moduleById as $effectiveModule) {
    $code = strtolower(
        trim((string)($effectiveModule['module_code'] ?? ''))
    );

    if (
        $code !== '' &&
        (string)($effectiveModule['tenant_access_type'] ?? '') !== 'group_only'
    ) {
        $effectiveModulesByCode[$code] = $effectiveModule;
    }
}

$quickCreate = [];

foreach ($quickCreateDefinitions as $key => $definition) {
    $matchedModule = null;

    foreach ($definition['module_codes'] as $candidateCode) {
        if (isset($effectiveModulesByCode[$candidateCode])) {
            $matchedModule = $effectiveModulesByCode[$candidateCode];
            break;
        }
    }

    if ($matchedModule === null) {
        continue;
    }

    $matchedModuleId = (int)$matchedModule['id'];

    if (
        empty(
            $moduleActionAllowed[$matchedModuleId]['create']
        )
    ) {
        continue;
    }

    $quickCreate[] = [
        'key' => $key,
        'module_id' => $matchedModuleId,
        'module_code' => (string)$matchedModule['module_code'],
        'label' => (string)$definition['label'],
        'menu_url' => ms_safe_menu_url($definition['url']),
        'icon_name' => (string)$definition['icon'],
        'tone' => (string)$definition['tone']
    ];
}

/*
|--------------------------------------------------------------------------
| USER DISPLAY CONTEXT
|--------------------------------------------------------------------------
*/
$fullName = trim(
    (string)$currentUser['first_name'] . ' ' .
    (string)($currentUser['last_name'] ?? '')
);

if ($fullName === '') {
    $fullName = (string)$currentUser['email'];
}

/*
|--------------------------------------------------------------------------
| SUCCESS
|--------------------------------------------------------------------------
*/
ms_response(200, [
    'success' => true,
    'message' => 'Sidebar loaded successfully.',

    'data' => [
        'user' => [
            'id' => (int)$currentUser['user_id'],
            'name' => $fullName,
            'email' => (string)$currentUser['email'],
            'avatar_path' => (string)($currentUser['avatar_path'] ?? ''),
            'job_title' => (string)($currentUser['job_title'] ?? ''),
            'role_id' => !empty($currentUser['role_id'])
                ? (int)$currentUser['role_id']
                : null,
            'role_name' => (string)($currentUser['role_name'] ?? ''),
            'role_code' => (string)($currentUser['role_code'] ?? ''),
            'branch_id' => !empty($currentUser['branch_id'])
                ? (int)$currentUser['branch_id']
                : null,
            'branch_name' => (string)($currentUser['branch_name'] ?? ''),
            'department_id' => !empty($currentUser['department_id'])
                ? (int)$currentUser['department_id']
                : null,
            'department_name' => (string)($currentUser['department_name'] ?? '')
        ],

        'tenant' => [
            'id' => (int)$currentUser['tenant_id'],
            'tenant_code' => (string)$currentUser['tenant_code'],
            'name' => (string)$currentUser['tenant_name'],
            'logo_path' => (string)($currentUser['tenant_logo_path'] ?? '')
        ],

        'subscription' => [
            'id' => $currentSubscriptionId,
            'plan_id' => $currentPlanId,
            'status' => $currentSubscriptionStatus
        ],

        'menu' => $menu,

        'quick_create' => $quickCreate,

        'meta' => [
            'menu_count' => count($menu),
            'quick_create_count' => count($quickCreate),
            'permission_model' => [
                'sidebar_requires' => 'view',
                'quick_create_requires' => 'create',
                'user_override_precedence' => true,
                'missing_permission_default' => 'deny'
            ]
        ]
    ]
]);
