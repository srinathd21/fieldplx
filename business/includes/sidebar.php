<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Tenant Sidebar - Permission Aware - Version 1.2.0
|--------------------------------------------------------------------------
|
| IMPORTANT:
| No sidebar menu label, URL, icon, parent, child, or menu order is hardcoded.
|
| Effective tenant sidebar:
|
| Logged-in tenant
|      ↓
| Current subscription / session plan_id
|      ↓
| plan_modules.is_enabled = 1
|      ↓
| tenant_modules access_type
|      inherit / enabled = allowed
|      disabled          = hidden
|      ↓
| modules.is_active = 1
| modules.is_sidebar_item = 1
|      ↓
| role_permissions: module view must be allowed
|      ↓
| user_permissions: allow/deny override, when configured
|      ↓
| Render only the logged-in tenant user's permitted database menu
|
| Tenant override can disable a plan module.
| It cannot enable a module that is not included in the plan.
|
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/db.php';
}

function ts_h($value)
{
    return htmlspecialchars(
        (string) ($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

function ts_safe_menu_url($url)
{
    $url = trim((string) $url);

    if ($url === '') {
        return '#';
    }

    /*
     * Reject executable JS URLs.
     * Database can store relative business-panel URLs.
     */
    if (
        stripos($url, 'javascript:') === 0 ||
        stripos($url, 'data:') === 0
    ) {
        return '#';
    }

    return $url;
}


/*
|--------------------------------------------------------------------------
| Active sidebar item by current URL
|--------------------------------------------------------------------------
*/
function ts_normalize_path($value)
{
    $value = trim((string)$value);
    if ($value === '' || $value === '#') {
        return '';
    }

    $path = parse_url($value, PHP_URL_PATH);
    if ($path === null || $path === false) {
        $path = $value;
    }

    $path = trim((string)$path);
    $path = preg_replace('#/+#', '/', $path);
    $path = rtrim($path, '/');

    if ($path === '') {
        return '/';
    }

    return $path;
}

function ts_url_is_active($menuUrl, $currentPath)
{
    $menuPath = ts_normalize_path($menuUrl);
    if ($menuPath === '') {
        return false;
    }

    $currentPath = ts_normalize_path($currentPath);

    $menuBase = basename($menuPath);
    $currentBase = basename($currentPath);

    $menuBase = preg_replace('/\.php$/i', '', $menuBase);
    $currentBase = preg_replace('/\.php$/i', '', $currentBase);

    if ($menuBase !== '' && $menuBase === $currentBase) {
        return true;
    }

    return $menuPath === $currentPath;
}

$currentRequestPath = isset($_SERVER['REQUEST_URI'])
    ? (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
    : '';
function ts_icon_class($icon)
{
    $icon = trim((string) $icon);

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


/*
|--------------------------------------------------------------------------
| Optional schema helper
|--------------------------------------------------------------------------
*/
function ts_table_exists(PDO $pdo, $tableName)
{
    $tableName = trim((string)$tableName);

    if ($tableName === '') {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    " );

    $stmt->execute(array(
        ':table_name' => $tableName
    ));

    return (int)$stmt->fetchColumn() > 0;
}

$currentTenantId =
    isset($_SESSION['tenant_id'])
    ? (int) $_SESSION['tenant_id']
    : 0;

$currentPlanId =
    isset($_SESSION['plan_id'])
    ? (int) $_SESSION['plan_id']
    : 0;


$currentTenantUserId =
    isset($_SESSION['tenant_user_id'])
    ? (int) $_SESSION['tenant_user_id']
    : 0;

$currentRoleId = 0;
$currentTenantUserIsActive = false;

$currentTenantName =
    trim(
        (string) (
            $_SESSION['tenant_name']
            ?? 'FieldPlx'
        )
    );

$currentUserName =
    trim(
        (string) (
            $_SESSION['tenant_user_name']
            ?? 'User'
        )
    );

$currentRoleName =
    trim(
        (string) (
            $_SESSION['role_name']
            ?? $_SESSION['tenant_user_job_title']
            ?? 'User'
        )
    );

$currentUserAvatar =
    trim(
        (string) (
            $_SESSION['tenant_user_avatar']
            ?? ''
        )
    );

$currentTenantLogo =
    trim(
        (string) (
            $_SESSION['effective_logo_path']
            ?? $_SESSION['tenant_logo_path']
            ?? ''
        )
    );

if ($currentTenantName === '') {
    $currentTenantName = 'FieldPlx';
}

if ($currentUserName === '') {
    $currentUserName = 'User';
}

if ($currentRoleName === '') {
    $currentRoleName = 'User';
}

/*
|--------------------------------------------------------------------------
| Initials
|--------------------------------------------------------------------------
*/
$userInitials = '';

$nameParts =
    preg_split(
        '/\s+/',
        trim($currentUserName)
    );

if (is_array($nameParts)) {
    foreach ($nameParts as $namePart) {

        if ($namePart !== '') {
            $userInitials .=
                strtoupper(
                    substr(
                        $namePart,
                        0,
                        1
                    )
                );
        }

        if (strlen($userInitials) >= 2) {
            break;
        }
    }
}

if ($userInitials === '') {
    $userInitials = 'U';
}

/*
|--------------------------------------------------------------------------
| Resolve logged-in tenant user + role
|--------------------------------------------------------------------------
|
| Sidebar access is resolved from the database on every request so a role
| permission change takes effect immediately without requiring a new login.
|
*/
if (
    $currentTenantId > 0 &&
    $currentTenantUserId > 0
) {
    $userAccessStmt = $pdo->prepare("
        SELECT
            u.role_id,
            u.status AS user_status,
            r.status AS role_status
        FROM users u
        LEFT JOIN roles r
            ON r.id = u.role_id
           AND r.tenant_id = u.tenant_id
        WHERE u.id = :user_id
          AND u.tenant_id = :tenant_id
          AND u.deleted_at IS NULL
        LIMIT 1
    ");

    $userAccessStmt->execute(array(
        ':user_id' => $currentTenantUserId,
        ':tenant_id' => $currentTenantId
    ));

    $currentUserAccess = $userAccessStmt->fetch(PDO::FETCH_ASSOC);

    if (
        $currentUserAccess &&
        (string)$currentUserAccess['user_status'] === 'active'
    ) {
        $currentTenantUserIsActive = true;

        if (
            !empty($currentUserAccess['role_id']) &&
            (string)$currentUserAccess['role_status'] === 'active'
        ) {
            $currentRoleId = (int)$currentUserAccess['role_id'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load tenant-accessible sidebar modules first
|--------------------------------------------------------------------------
|
| Base rules:
| 1. Module must exist in plan_modules and is_enabled = 1.
| 2. Module must be active.
| 3. Module must be marked as sidebar item.
| 4. tenant_modules='disabled' hides it.
| 5. tenant_modules='enabled' does NOT bypass plan_modules.
|
| IMPORTANT:
| This is only the tenant entitlement layer. Role/user permission filtering
| is applied immediately after this query.
|
*/
$tenantSidebarRows = array();
$sidebarRows = array();
$moduleActionAllowed = array();

if (
    $currentTenantId > 0 &&
    $currentPlanId > 0 &&
    $currentTenantUserIsActive
) {

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
                WHEN m.parent_id IS NULL
                    THEN 0
                ELSE 1
            END ASC,
            m.menu_order ASC,
            m.module_name ASC
    ");

    $stmt->execute(array(
        ':tenant_id' => $currentTenantId,
        ':plan_id' => $currentPlanId
    ));

    $tenantSidebarRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Apply role permission + user override to sidebar modules
|--------------------------------------------------------------------------
|
| Effective permission:
| - user_permissions allow/deny wins when a user override exists.
| - otherwise role_permissions allow/deny is used.
| - missing permission / missing grant = denied.
|
| Sidebar visibility requires the module's VIEW permission.
| Quick Create additionally requires the module's CREATE permission.
|
*/
if (!empty($tenantSidebarRows)) {

    $moduleIds = array();

    foreach ($tenantSidebarRows as $tenantSidebarRow) {
        $moduleIds[] = (int)$tenantSidebarRow['id'];
    }

    $moduleIds = array_values(array_unique($moduleIds));

    if (!empty($moduleIds)) {
        $modulePlaceholders = implode(
            ',',
            array_fill(0, count($moduleIds), '?')
        );

        $hasUserPermissions = ts_table_exists(
            $pdo,
            'user_permissions'
        );

        $permissionSql = "
            SELECT
                p.module_id,
                p.action_code,
                rp.access_type AS role_access";

        if ($hasUserPermissions) {
            $permissionSql .= ",\n                up.access_type AS user_access";
        } else {
            $permissionSql .= ",\n                NULL AS user_access";
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

        $permissionParams = array(
            $currentTenantId,
            $currentRoleId
        );

        if ($hasUserPermissions) {
            $permissionParams[] = $currentTenantId;
            $permissionParams[] = $currentTenantUserId;
        }

        foreach ($moduleIds as $moduleId) {
            $permissionParams[] = (int)$moduleId;
        }

        $permissionStmt = $pdo->prepare($permissionSql);
        $permissionStmt->execute($permissionParams);

        foreach ($permissionStmt->fetchAll(PDO::FETCH_ASSOC) as $permissionRow) {
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
                $moduleActionAllowed[$moduleId] = array();
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
| Build database hierarchy
|--------------------------------------------------------------------------
*/
$moduleById = array();
$childrenByParent = array();
$topLevelIds = array();

/*
 * First collect all enabled plan modules.
 */
foreach ($sidebarRows as $row) {

    $moduleId =
        (int) $row['id'];

    $moduleById[
        $moduleId
    ] = $row;
}

/*
 * Then create parent-child map.
 */
foreach ($sidebarRows as $row) {

    $moduleId =
        (int) $row['id'];

    $parentId =
        !empty($row['parent_id'])
        ? (int) $row['parent_id']
        : 0;

    if ($parentId <= 0) {

        $topLevelIds[] =
            $moduleId;

        continue;
    }

    if (
        !isset(
        $childrenByParent[
            $parentId
        ]
    )
    ) {
        $childrenByParent[
            $parentId
        ] = array();
    }

    $childrenByParent[
        $parentId
    ][] = $row;
}

/*
|--------------------------------------------------------------------------
| Load missing parent rows for enabled child modules
|--------------------------------------------------------------------------
|
| A parent may not itself be assigned in plan_modules while one of its
| children is assigned. The parent is loaded only as a visual grouping row.
| It does NOT become an accessible destination unless it is itself enabled.
|
*/
$missingParentIds = array();

foreach (
    array_keys($childrenByParent)
    as $parentId
) {
    if (
        !isset(
        $moduleById[
            $parentId
        ]
    )
    ) {
        $missingParentIds[] =
            (int) $parentId;
    }
}

if (!empty($missingParentIds)) {

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($missingParentIds),
                '?'
            )
        );

    $parentStmt =
        $pdo->prepare("
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

    $parentStmt->execute(
        $missingParentIds
    );

    foreach (
        $parentStmt->fetchAll()
        as $parentRow
    ) {
        $parentId =
            (int) $parentRow['id'];

        $parentRow[
            'tenant_access_type'
        ] = 'group_only';

        $moduleById[
            $parentId
        ] = $parentRow;

        $topLevelIds[] =
            $parentId;
    }
}

/*
|--------------------------------------------------------------------------
| Ensure all accessible parent rows are top-level
|--------------------------------------------------------------------------
*/
foreach ($moduleById as $moduleId => $module) {

    if (
        empty($module['parent_id']) &&
        !in_array(
            (int) $moduleId,
            $topLevelIds,
            true
        )
    ) {
        $topLevelIds[] =
            (int) $moduleId;
    }
}

/*
|--------------------------------------------------------------------------
| Sort top-level database modules
|--------------------------------------------------------------------------
*/
usort(
    $topLevelIds,
    function ($a, $b) use ($moduleById) {

        $aRow =
            isset($moduleById[$a])
            ? $moduleById[$a]
            : array();

        $bRow =
            isset($moduleById[$b])
            ? $moduleById[$b]
            : array();

        $aOrder =
            isset($aRow['menu_order'])
            ? (int) $aRow['menu_order']
            : 0;

        $bOrder =
            isset($bRow['menu_order'])
            ? (int) $bRow['menu_order']
            : 0;

        if ($aOrder === $bOrder) {
            return strcasecmp(
                (string) (
                    $aRow['module_name']
                    ?? ''
                ),
                (string) (
                    $bRow['module_name']
                    ?? ''
                )
            );
        }

        return $aOrder <=> $bOrder;
    }
);

/*
|--------------------------------------------------------------------------
| Remove duplicate top-level IDs
|--------------------------------------------------------------------------
*/
$topLevelIds =
    array_values(
        array_unique(
            $topLevelIds
        )
    );

/*
|--------------------------------------------------------------------------
| Dynamic quick-create actions
|--------------------------------------------------------------------------
|
| The Create flyout follows the same effective sidebar result and additionally
| requires CREATE permission for the logged-in tenant user. User-specific
| permission overrides take precedence over the role. Module aliases support
| the existing legacy Quotation and Job Cards module codes without duplicates.
|
*/
$quickCreateDefinitions = array(
    'client' => array(
        'module_codes' => array('clients'),
        'label' => 'Client',
        'url' => 'client-form.php',
        'icon' => 'bi bi-person',
        'tone' => 'client'
    ),
    'request' => array(
        'module_codes' => array('requests'),
        'label' => 'Request',
        'url' => 'add-request.php',
        'icon' => 'bi bi-inbox',
        'tone' => 'request'
    ),
    'quote' => array(
        'module_codes' => array('quotes', 'quotation'),
        'label' => 'Quote',
        'url' => 'add-quotation.php',
        'icon' => 'bi bi-file-earmark-text',
        'tone' => 'quote'
    ),
    'job' => array(
        'module_codes' => array('jobs', 'job-cards'),
        'label' => 'Job',
        'url' => 'job-form.php',
        'icon' => 'bi bi-hammer',
        'tone' => 'job'
    ),
    'invoice' => array(
        'module_codes' => array('invoices'),
        'label' => 'Invoice',
        'url' => 'invoice-form.php',
        'icon' => 'bi bi-receipt',
        'tone' => 'invoice'
    )
);

$quickCreateActions = array();
$effectiveModulesByCode = array();

foreach ($moduleById as $effectiveModule) {
    $effectiveModuleCode = strtolower(
        trim((string)($effectiveModule['module_code'] ?? ''))
    );

    if (
        $effectiveModuleCode !== '' &&
        ($effectiveModule['tenant_access_type'] ?? '') !== 'group_only'
    ) {
        $effectiveModulesByCode[$effectiveModuleCode] = $effectiveModule;
    }
}

foreach ($quickCreateDefinitions as $quickCreateKey => $definition) {
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

    $definition['key'] = $quickCreateKey;
    $definition['module_id'] = (int)$matchedModule['id'];
    $definition['module_code'] = (string)$matchedModule['module_code'];
    $quickCreateActions[] = $definition;
}
?>

<style>
    .fieldplx-quick-create {
        position: relative;
        margin: 0 10px 12px;
    }

    .fieldplx-quick-create-button {
        width: 100%;
        min-height: 42px;
        padding: 8px 12px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid rgba(255, 255, 255, .12);
        border-radius: 10px;
        background: rgba(255, 255, 255, .08);
        color: #ffffff;
        font: inherit;
        cursor: pointer;
        transition: background .18s ease, border-color .18s ease;
    }

    .fieldplx-quick-create-button:hover,
    .fieldplx-quick-create.open .fieldplx-quick-create-button {
        border-color: rgba(255, 255, 255, .22);
        background: rgba(255, 255, 255, .14);
    }

    .fieldplx-quick-create-symbol {
        width: 24px;
        height: 24px;
        flex: 0 0 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #ffffff;
        color: #6d28d9;
        font-size: 16px;
        font-weight: 700;
        transition: transform .18s ease;
    }

    .fieldplx-quick-create.open .fieldplx-quick-create-symbol {
        transform: rotate(45deg);
    }

    .fieldplx-quick-create-label {
        min-width: 0;
        flex: 1;
        text-align: left;
        font-size: 13px;
        font-weight: 600;
    }

    .fieldplx-quick-create-chevron {
        color: rgba(255, 255, 255, .65);
        font-size: 12px;
        transition: transform .18s ease;
    }

    .fieldplx-quick-create.open .fieldplx-quick-create-chevron {
        transform: rotate(180deg);
    }

    .fieldplx-quick-create-menu {
        position: fixed;
        z-index: 1080;
        min-width: 410px;
        padding: 8px;
        display: grid;
        grid-template-columns: repeat(5, minmax(68px, 1fr));
        gap: 4px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-4px);
        transition: opacity .16s ease, transform .16s ease, visibility .16s ease;
    }

    .fieldplx-quick-create.open .fieldplx-quick-create-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .fieldplx-quick-create-action {
        min-width: 0;
        padding: 10px 6px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 7px;
        border-radius: 9px;
        color: #334155;
        text-align: center;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        transition: background .16s ease, color .16s ease;
    }

    .fieldplx-quick-create-action:hover {
        background: #f7f5ff;
        color: #5b21b6;
    }

    .fieldplx-quick-create-action i {
        font-size: 20px;
    }

    .fieldplx-quick-create-action.client i { color: #64748b; }
    .fieldplx-quick-create-action.request i { color: #d97706; }
    .fieldplx-quick-create-action.quote i { color: #be185d; }
    .fieldplx-quick-create-action.job i { color: #26832b; }
    .fieldplx-quick-create-action.invoice i { color: #2563eb; }

    body.fieldplx-sidebar-collapsed .fieldplx-quick-create {
        margin-left: 9px;
        margin-right: 9px;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-quick-create-button {
        justify-content: center;
        padding-left: 7px;
        padding-right: 7px;
    }

    body.fieldplx-sidebar-collapsed .fieldplx-quick-create-label,
    body.fieldplx-sidebar-collapsed .fieldplx-quick-create-chevron {
        display: none;
    }

    @media (max-width: 991.98px) {
        .fieldplx-quick-create-menu {
            position: static;
            width: 100%;
            min-width: 0;
            max-height: 0;
            margin-top: 0;
            padding-top: 0;
            padding-bottom: 0;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            overflow: hidden;
            border-width: 0;
            box-shadow: none;
        }

        .fieldplx-quick-create.open .fieldplx-quick-create-menu {
            max-height: 110px;
            margin-top: 7px;
            padding: 8px 4px;
            border-width: 1px;
        }

        .fieldplx-quick-create-action {
            padding-left: 2px;
            padding-right: 2px;
            font-size: 10px;
        }
    }
</style>

<aside class="fieldplx-sidebar" id="fieldplxSidebar">

    <div class="fieldplx-sidebar-header">

        <a class="fieldplx-sidebar-brand" href="#">

            <?php if ($currentTenantLogo !== ''): ?>

                <span class="fieldplx-sidebar-logo-placeholder" style="
                    overflow:hidden;
                    background:#fff;
                ">
                    <img src="<?= ts_h($currentTenantLogo) ?>" alt="<?= ts_h($currentTenantName) ?>" style="
                        width:100%;
                        height:100%;
                        object-fit:contain;
                    ">
                </span>

            <?php else: ?>

                <span class="fieldplx-sidebar-logo-placeholder">
                    <?= ts_h(
                        strtoupper(
                            substr(
                                $currentTenantName,
                                0,
                                1
                            )
                        )
                    ) ?>
                </span>

            <?php endif; ?>

            <span class="fieldplx-sidebar-brand-text">

                <span class="fieldplx-sidebar-company-name">
                    <?= ts_h($currentTenantName) ?>
                </span>

                <span class="fieldplx-sidebar-product-name">
                    FieldPlx
                </span>

            </span>

        </a>

        <button aria-label="Close sidebar" class="fieldplx-sidebar-close" id="sidebarClose" type="button">
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

    <div class="fieldplx-sidebar-body">

        <?php if (!empty($quickCreateActions)): ?>

            <div class="fieldplx-quick-create" id="fieldplxQuickCreate">

                <button
                    class="fieldplx-quick-create-button"
                    id="fieldplxQuickCreateButton"
                    type="button"
                    aria-expanded="false"
                    aria-controls="fieldplxQuickCreateMenu"
                >
                    <span class="fieldplx-quick-create-symbol">
                        <i class="bi bi-plus"></i>
                    </span>
                    <span class="fieldplx-quick-create-label">Create</span>
                    <span class="fieldplx-quick-create-chevron">
                        <i class="bi bi-chevron-down"></i>
                    </span>
                </button>

                <div
                    class="fieldplx-quick-create-menu"
                    id="fieldplxQuickCreateMenu"
                    role="menu"
                    aria-label="Create new"
                >
                    <?php foreach ($quickCreateActions as $quickAction): ?>
                        <a
                            class="fieldplx-quick-create-action <?= ts_h($quickAction['tone']) ?>"
                            href="<?= ts_h(ts_safe_menu_url($quickAction['url'])) ?>"
                            role="menuitem"
                            data-module-id="<?= (int)$quickAction['module_id'] ?>"
                            data-module-code="<?= ts_h($quickAction['module_code']) ?>"
                        >
                            <i class="<?= ts_h($quickAction['icon']) ?>"></i>
                            <span><?= ts_h($quickAction['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

            </div>

        <?php endif; ?>

        <nav class="fieldplx-sidebar-nav">

            <?php if (empty($topLevelIds)): ?>

                <div style="
                    padding:14px 12px;
                    color:rgba(255,255,255,.55);
                    font-size:10px;
                    line-height:1.55;
                    text-align:center;
                ">
                    No sidebar modules are enabled
                    for this tenant.
                </div>

            <?php else: ?>

                <?php foreach ($topLevelIds as $parentId): ?>

                    <?php
                    if (
                        !isset(
                        $moduleById[
                            $parentId
                        ]
                    )
                    ) {
                        continue;
                    }

                    $parent =
                        $moduleById[
                            $parentId
                        ];

                    $children =
                        isset(
                        $childrenByParent[
                            $parentId
                        ]
                    )
                        ? $childrenByParent[
                            $parentId
                        ]
                        : array();

                    $parentAccessible =
                        isset(
                        $parent[
                            'tenant_access_type'
                        ]
                    ) &&
                        $parent[
                            'tenant_access_type'
                        ] !== 'group_only';

                    $parentUrl =
                        $parentAccessible
                        ? ts_safe_menu_url(
                            $parent['menu_url']
                            ?? ''
                        )
                        : '#';

                    $parentIcon =
                        ts_icon_class(
                            $parent['icon_name']
                            ?? ''
                        );

                    $parentIsActive =
                        $parentAccessible &&
                        ts_url_is_active(
                            $parentUrl,
                            $currentRequestPath
                        );

                    $childActive = false;
                    foreach ($children as $childCheck) {
                        if (
                            ts_url_is_active(
                                ts_safe_menu_url(
                                    $childCheck['menu_url'] ?? ''
                                ),
                                $currentRequestPath
                            )
                        ) {
                            $childActive = true;
                            break;
                        }
                    }

                    $menuShouldOpen =
                        $parentIsActive || $childActive;
                    ?>

                    <?php if (!empty($children)): ?>

                        <div class="fieldplx-sidebar-menu<?= $menuShouldOpen ? ' menu-open' : '' ?>" data-module-id="<?= (int) $parent['id'] ?>" data-module-code="<?= ts_h(
                              $parent['module_code']
                          ) ?>">

                            <button class="
                            fieldplx-sidebar-link
                            fieldplx-sidebar-menu-toggle
                        " type="button">

                                <span class="fieldplx-sidebar-link-icon">
                                    <i class="<?= ts_h($parentIcon) ?>"></i>
                                </span>

                                <span class="fieldplx-sidebar-link-text">
                                    <?= ts_h(
                                        $parent['module_name']
                                    ) ?>
                                </span>

                                <span class="fieldplx-sidebar-arrow">
                                    <i class="bi bi-chevron-down"></i>
                                </span>

                            </button>

                            <div class="fieldplx-sidebar-submenu">

                                <?php
                                /*
                                 * If the parent itself has a DB URL and is plan-accessible,
                                 * render it using its own DB values. No hardcoded "Overview".
                                 */
                                if (
                                    $parentAccessible &&
                                    $parentUrl !== '#'
                                ):
                                    ?>

                                    <a class="fieldplx-sidebar-sublink<?= $parentIsActive ? ' active' : '' ?>" href="<?= ts_h($parentUrl) ?>"
                                        data-module-id="<?= (int) $parent['id'] ?>" data-module-code="<?= ts_h(
                                              $parent['module_code']
                                          ) ?>">
                                        <?= ts_h(
                                            $parent['module_name']
                                        ) ?>
                                    </a>

                                <?php endif; ?>

                                <?php foreach ($children as $child): ?>
                                    <?php
                                    $childUrl = ts_safe_menu_url(
                                        $child['menu_url'] ?? ''
                                    );
                                    $childIsActive = ts_url_is_active(
                                        $childUrl,
                                        $currentRequestPath
                                    );
                                    ?>

                                    <a class="fieldplx-sidebar-sublink<?= $childIsActive ? ' active' : '' ?>" href="<?= ts_h(
                                        $childUrl
                                    ) ?>" data-module-id="<?= (int) $child['id'] ?>" data-module-code="<?= ts_h(
                                           $child['module_code']
                                       ) ?>">
                                        <?= ts_h(
                                            $child['module_name']
                                        ) ?>
                                    </a>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php else: ?>

                        <?php
                        /*
                         * A group-only parent with no children has nothing to show.
                         */
                        if (!$parentAccessible) {
                            continue;
                        }
                        ?>

                        <a class="fieldplx-sidebar-link<?= $parentIsActive ? ' active' : '' ?>" href="<?= ts_h($parentUrl) ?>" data-module-id="<?= (int) $parent['id'] ?>"
                            data-module-code="<?= ts_h(
                                $parent['module_code']
                            ) ?>">

                            <span class="fieldplx-sidebar-link-icon">
                                <i class="<?= ts_h($parentIcon) ?>"></i>
                            </span>

                            <span class="fieldplx-sidebar-link-text">
                                <?= ts_h(
                                    $parent['module_name']
                                ) ?>
                            </span>

                        </a>

                    <?php endif; ?>

                <?php endforeach; ?>

            <?php endif; ?>

        </nav>

    </div>

    <div class="fieldplx-sidebar-footer">

        <div class="fieldplx-sidebar-user">

            <?php if ($currentUserAvatar !== ''): ?>

                <span class="fieldplx-sidebar-user-avatar" style="overflow:hidden">
                    <img src="<?= ts_h($currentUserAvatar) ?>" alt="<?= ts_h($currentUserName) ?>" style="
                        width:100%;
                        height:100%;
                        object-fit:cover;
                    ">
                </span>

            <?php else: ?>

                <span class="fieldplx-sidebar-user-avatar">
                    <?= ts_h($userInitials) ?>
                </span>

            <?php endif; ?>

            <span class="fieldplx-sidebar-user-details">

                <span class="fieldplx-sidebar-user-name">
                    <?= ts_h($currentUserName) ?>
                </span>

                <span class="fieldplx-sidebar-user-role">
                    <?= ts_h($currentRoleName) ?>
                </span>

            </span>

            <a aria-label="Logout" class="fieldplx-sidebar-logout" href="logout.php" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>

        </div>

    </div>

</aside>

<div class="fieldplx-sidebar-overlay" id="fieldplxSidebarOverlay"></div>

<script>
    document.addEventListener(
        'DOMContentLoaded',
        function () {
            'use strict';

            const body = document.body;
            const sidebar =
                document.getElementById(
                    'fieldplxSidebar'
                );
            const sidebarToggle =
                document.getElementById(
                    'sidebarToggle'
                );
            const sidebarClose =
                document.getElementById(
                    'sidebarClose'
                );
            const sidebarOverlay =
                document.getElementById(
                    'fieldplxSidebarOverlay'
                );
            const menuToggles =
                document.querySelectorAll(
                    '.fieldplx-sidebar-menu-toggle'
                );
            const mobileMedia =
                window.matchMedia(
                    '(max-width: 991.98px)'
                );
            const quickCreate =
                document.getElementById(
                    'fieldplxQuickCreate'
                );
            const quickCreateButton =
                document.getElementById(
                    'fieldplxQuickCreateButton'
                );
            const quickCreateMenu =
                document.getElementById(
                    'fieldplxQuickCreateMenu'
                );

            function positionQuickCreateMenu() {
                if (
                    !quickCreateButton ||
                    !quickCreateMenu ||
                    isMobileSidebar()
                ) {
                    return;
                }

                const rect =
                    quickCreateButton.getBoundingClientRect();

                quickCreateMenu.style.top =
                    Math.max(8, rect.top) + 'px';
                quickCreateMenu.style.left =
                    (rect.right + 10) + 'px';
            }

            function closeQuickCreate() {
                if (!quickCreate || !quickCreateButton) {
                    return;
                }

                quickCreate.classList.remove('open');
                quickCreateButton.setAttribute(
                    'aria-expanded',
                    'false'
                );
            }

            function toggleQuickCreate() {
                if (!quickCreate || !quickCreateButton) {
                    return;
                }

                const willOpen =
                    !quickCreate.classList.contains('open');

                quickCreate.classList.toggle('open', willOpen);
                quickCreateButton.setAttribute(
                    'aria-expanded',
                    willOpen ? 'true' : 'false'
                );

                if (willOpen) {
                    positionQuickCreateMenu();
                }
            }

            function isMobileSidebar() {
                return mobileMedia.matches;
            }

            function readDesktopCollapsedState() {
                try {
                    return localStorage.getItem(
                        'fieldplx_sidebar_collapsed'
                    ) === '1';
                } catch (error) {
                    return false;
                }
            }

            function saveDesktopCollapsedState(
                isCollapsed
            ) {
                try {
                    localStorage.setItem(
                        'fieldplx_sidebar_collapsed',
                        isCollapsed
                            ? '1'
                            : '0'
                    );
                } catch (error) {
                }
            }

            function openMobileSidebar() {
                if (!isMobileSidebar()) {
                    return;
                }

                body.classList.add(
                    'fieldplx-sidebar-mobile-open'
                );

                if (sidebarToggle) {
                    sidebarToggle.setAttribute(
                        'aria-expanded',
                        'true'
                    );
                }

                if (sidebar) {
                    sidebar.setAttribute(
                        'aria-hidden',
                        'false'
                    );
                }
            }

            function closeMobileSidebar() {
                body.classList.remove(
                    'fieldplx-sidebar-mobile-open'
                );

                if (sidebarToggle) {
                    sidebarToggle.setAttribute(
                        'aria-expanded',
                        'false'
                    );
                }

                if (
                    sidebar &&
                    isMobileSidebar()
                ) {
                    sidebar.setAttribute(
                        'aria-hidden',
                        'true'
                    );
                }
            }

            function toggleMobileSidebar() {
                if (
                    body.classList.contains(
                        'fieldplx-sidebar-mobile-open'
                    )
                ) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            }

            function applyDesktopSidebarState() {
                const collapsed =
                    readDesktopCollapsedState();

                body.classList.toggle(
                    'fieldplx-sidebar-collapsed',
                    collapsed
                );

                if (sidebar) {
                    sidebar.removeAttribute(
                        'aria-hidden'
                    );
                }
            }

            function toggleDesktopSidebar() {
                const collapsed =
                    !body.classList.contains(
                        'fieldplx-sidebar-collapsed'
                    );

                body.classList.toggle(
                    'fieldplx-sidebar-collapsed',
                    collapsed
                );

                saveDesktopCollapsedState(
                    collapsed
                );
            }

            function syncSidebarMode() {
                if (isMobileSidebar()) {

                    body.classList.remove(
                        'fieldplx-sidebar-collapsed'
                    );

                    closeMobileSidebar();

                    if (sidebar) {
                        sidebar.setAttribute(
                            'aria-hidden',
                            'true'
                        );
                    }

                } else {

                    closeMobileSidebar();
                    applyDesktopSidebarState();
                }
            }

            if (sidebarToggle) {

                sidebarToggle.setAttribute(
                    'aria-controls',
                    'fieldplxSidebar'
                );

                sidebarToggle.setAttribute(
                    'aria-expanded',
                    'false'
                );

                sidebarToggle.addEventListener(
                    'click',
                    function () {

                        if (isMobileSidebar()) {
                            toggleMobileSidebar();
                        } else {
                            toggleDesktopSidebar();
                        }
                    }
                );
            }

            if (sidebarClose) {
                sidebarClose.addEventListener(
                    'click',
                    closeMobileSidebar
                );
            }

            if (sidebarOverlay) {
                sidebarOverlay.addEventListener(
                    'click',
                    closeMobileSidebar
                );
            }

            if (quickCreateButton) {
                quickCreateButton.addEventListener(
                    'click',
                    function (event) {
                        event.stopPropagation();
                        toggleQuickCreate();
                    }
                );
            }

            if (quickCreateMenu) {
                quickCreateMenu.addEventListener(
                    'click',
                    function (event) {
                        event.stopPropagation();
                    }
                );
            }

            document.addEventListener(
                'click',
                function (event) {
                    if (
                        quickCreate &&
                        !quickCreate.contains(event.target)
                    ) {
                        closeQuickCreate();
                    }
                }
            );

            menuToggles.forEach(
                function (menuToggle) {

                    const menu =
                        menuToggle.closest(
                            '.fieldplx-sidebar-menu'
                        );

                    if (!menu) {
                        return;
                    }

                    menuToggle.setAttribute(
                        'aria-expanded',
                        menu.classList.contains(
                            'menu-open'
                        )
                            ? 'true'
                            : 'false'
                    );

                    menuToggle.addEventListener(
                        'click',
                        function () {

                            if (
                                !isMobileSidebar() &&
                                body.classList.contains(
                                    'fieldplx-sidebar-collapsed'
                                )
                            ) {
                                body.classList.remove(
                                    'fieldplx-sidebar-collapsed'
                                );

                                saveDesktopCollapsedState(
                                    false
                                );
                            }

                            const willOpen =
                                !menu.classList.contains(
                                    'menu-open'
                                );

                            document
                                .querySelectorAll(
                                    '.fieldplx-sidebar-menu.menu-open'
                                )
                                .forEach(
                                    function (
                                        openMenu
                                    ) {
                                        if (
                                            openMenu !==
                                            menu
                                        ) {
                                            openMenu.classList.remove(
                                                'menu-open'
                                            );

                                            const openToggle =
                                                openMenu.querySelector(
                                                    '.fieldplx-sidebar-menu-toggle'
                                                );

                                            if (
                                                openToggle
                                            ) {
                                                openToggle.setAttribute(
                                                    'aria-expanded',
                                                    'false'
                                                );
                                            }
                                        }
                                    }
                                );

                            menu.classList.toggle(
                                'menu-open',
                                willOpen
                            );

                            menuToggle.setAttribute(
                                'aria-expanded',
                                willOpen
                                    ? 'true'
                                    : 'false'
                            );
                        }
                    );
                }
            );

            document
                .querySelectorAll(
                    '.fieldplx-sidebar a.fieldplx-sidebar-link, ' +
                    '.fieldplx-sidebar a.fieldplx-sidebar-sublink'
                )
                .forEach(
                    function (link) {

                        link.addEventListener(
                            'click',
                            function () {

                                if (
                                    isMobileSidebar()
                                ) {
                                    closeMobileSidebar();
                                }
                            }
                        );
                    }
                );

            document.addEventListener(
                'keydown',
                function (event) {

                    if (
                        event.key ===
                        'Escape'
                    ) {
                        closeQuickCreate();

                        if (
                            body.classList.contains(
                                'fieldplx-sidebar-mobile-open'
                            )
                        ) {
                            closeMobileSidebar();
                        }
                    }
                }
            );

            let previousMobileState =
                isMobileSidebar();

            window.addEventListener(
                'resize',
                function () {

                    const currentMobileState =
                        isMobileSidebar();

                    if (
                        currentMobileState !==
                        previousMobileState
                    ) {
                        previousMobileState =
                            currentMobileState;

                        syncSidebarMode();
                    }

                    if (
                        quickCreate &&
                        quickCreate.classList.contains('open')
                    ) {
                        positionQuickCreateMenu();
                    }
                }
            );

            syncSidebarMode();
        }
    );
</script>
