<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Business Sidebar
|--------------------------------------------------------------------------
|
| File:
| business/includes/sidebar.php
|
| Visibility order:
| 1. Logged-in tenant/user context
| 2. Active/trial subscription
| 3. Plan module entitlement (plan_modules)
| 4. Tenant module override (tenant_modules)
| 5. Role view permission (role_permissions)
| 6. Parent modules are kept only when a visible child needs them
|
| Important:
| - A tenant override can DISABLE a plan module.
| - A tenant override never enables a module that is not in the plan.
| - Tenant admins receive all modules that remain available in their plan.
| - Non-admin users require the module's "view" permission.
|
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -----------------------------------------------------------------------
 * Database
 * --------------------------------------------------------------------- */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (is_file(__DIR__ . '/database.php')) {
        require_once __DIR__ . '/database.php';
    } elseif (is_file(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
    }
}

/* -----------------------------------------------------------------------
 * Small schema helpers - keep this include compatible with older installs
 * --------------------------------------------------------------------- */
if (!function_exists('fieldplx_sidebar_table_exists')) {
    function fieldplx_sidebar_table_exists(PDO $pdo, $table)
    {
        static $cache = array();
        $table = trim((string)$table);
        if ($table === '') {
            return false;
        }
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables ' .
                'WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $stmt->execute(array(':table_name' => $table));
            $cache[$table] = (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}

/* -----------------------------------------------------------------------
 * Current page
 * --------------------------------------------------------------------- */
$currentPage = basename(
    (string)parse_url(
        isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '',
        PHP_URL_PATH
    )
);

/* -----------------------------------------------------------------------
 * Session user context
 * --------------------------------------------------------------------- */
$userId = (int)(
    isset($_SESSION['tenant_user_id'])
        ? $_SESSION['tenant_user_id']
        : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0)
);

$tenantId = (int)(isset($_SESSION['tenant_id']) ? $_SESSION['tenant_id'] : 0);
$roleId = (int)(isset($_SESSION['role_id']) ? $_SESSION['role_id'] : 0);
$isTenantAdmin = !empty($_SESSION['is_tenant_admin']) ? 1 : 0;

$sidebarMenus = array();
$activePlanId = 0;

/* -----------------------------------------------------------------------
 * Resolve authoritative user / tenant / role context
 * --------------------------------------------------------------------- */
if (
    isset($pdo) &&
    $pdo instanceof PDO &&
    $userId > 0 &&
    fieldplx_sidebar_table_exists($pdo, 'users')
) {
    try {
        $userStmt = $pdo->prepare(
            "SELECT tenant_id, role_id, is_tenant_admin
             FROM users
             WHERE id = :user_id
               AND status = 'active'
             LIMIT 1"
        );
        $userStmt->execute(array(':user_id' => $userId));
        $sidebarUser = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($sidebarUser) {
            $tenantId = (int)$sidebarUser['tenant_id'];
            $roleId = (int)$sidebarUser['role_id'];
            $isTenantAdmin = !empty($sidebarUser['is_tenant_admin']) ? 1 : 0;
        }
    } catch (Throwable $e) {
        error_log('FieldPlx sidebar user context error: ' . $e->getMessage());
    }
}

/* -----------------------------------------------------------------------
 * Resolve the tenant's current active/trial subscription
 * --------------------------------------------------------------------- */
if (
    isset($pdo) &&
    $pdo instanceof PDO &&
    $tenantId > 0 &&
    fieldplx_sidebar_table_exists($pdo, 'subscriptions')
) {
    try {
        $planStmt = $pdo->prepare(
            "SELECT id, plan_id, status
             FROM subscriptions
             WHERE tenant_id = :tenant_id
               AND status IN ('active', 'trial')
               AND (
                    (status = 'active' AND (expiry_date IS NULL OR expiry_date >= CURDATE()))
                    OR
                    (status = 'trial' AND (trial_end_date IS NULL OR trial_end_date >= CURDATE()))
               )
             ORDER BY
                 CASE WHEN status = 'active' THEN 0 ELSE 1 END ASC,
                 id DESC
             LIMIT 1"
        );
        $planStmt->execute(array(':tenant_id' => $tenantId));
        $planRow = $planStmt->fetch(PDO::FETCH_ASSOC);
        if ($planRow) {
            $activePlanId = (int)$planRow['plan_id'];
        }
    } catch (Throwable $e) {
        error_log('FieldPlx sidebar subscription load error: ' . $e->getMessage());
    }
}

/* -----------------------------------------------------------------------
 * Load modules enabled by the tenant plan
 * --------------------------------------------------------------------- */
if (
    isset($pdo) &&
    $pdo instanceof PDO &&
    $tenantId > 0 &&
    $activePlanId > 0 &&
    fieldplx_sidebar_table_exists($pdo, 'modules') &&
    fieldplx_sidebar_table_exists($pdo, 'plan_modules')
) {
    try {
        $hasTenantModules = fieldplx_sidebar_table_exists($pdo, 'tenant_modules');

        $tenantJoin = '';
        $tenantSelect = "'inherit' AS tenant_access";
        $tenantWhere = '';

        if ($hasTenantModules) {
            $tenantJoin = "
                LEFT JOIN tenant_modules tm
                    ON tm.tenant_id = :tenant_id_for_override
                   AND tm.module_id = m.id
            ";
            $tenantSelect = "COALESCE(tm.access_type, 'inherit') AS tenant_access";
            $tenantWhere = "AND COALESCE(tm.access_type, 'inherit') <> 'disabled'";
        }

        $moduleSql = "
            SELECT
                m.id,
                m.parent_id,
                m.module_code,
                m.module_name,
                m.menu_url,
                m.icon_name,
                m.menu_order,
                m.is_core,
                {$tenantSelect}
            FROM modules m
            INNER JOIN plan_modules pm
                ON pm.module_id = m.id
               AND pm.plan_id = :plan_id
               AND pm.is_enabled = 1
            {$tenantJoin}
            WHERE m.is_active = 1
              AND m.is_sidebar_item = 1
              {$tenantWhere}
            ORDER BY m.menu_order ASC, m.id ASC
        ";

        $moduleStmt = $pdo->prepare($moduleSql);
        $moduleParams = array(':plan_id' => $activePlanId);
        if ($hasTenantModules) {
            $moduleParams[':tenant_id_for_override'] = $tenantId;
        }
        $moduleStmt->execute($moduleParams);
        $planModules = $moduleStmt->fetchAll(PDO::FETCH_ASSOC);

        /* ---------------------------------------------------------------
         * Resolve role view permissions for non-admin users
         * ------------------------------------------------------------- */
        $roleViewAccess = array();

        if (!$isTenantAdmin && $roleId > 0) {
            if (
                fieldplx_sidebar_table_exists($pdo, 'permissions') &&
                fieldplx_sidebar_table_exists($pdo, 'role_permissions')
            ) {
                $permissionStmt = $pdo->prepare(
                    "SELECT p.module_id, rp.access_type
                     FROM permissions p
                     INNER JOIN role_permissions rp
                         ON rp.permission_id = p.id
                        AND rp.tenant_id = :tenant_id
                        AND rp.role_id = :role_id
                     WHERE p.action_code = 'view'"
                );
                $permissionStmt->execute(array(
                    ':tenant_id' => $tenantId,
                    ':role_id' => $roleId
                ));

                foreach ($permissionStmt->fetchAll(PDO::FETCH_ASSOC) as $permissionRow) {
                    $moduleId = (int)$permissionRow['module_id'];
                    $roleViewAccess[$moduleId] =
                        ((string)$permissionRow['access_type'] === 'allow');
                }
            }

            /*
             * Backward compatibility for installations that still store
             * sidebar permissions in role_sidebar_access/sidebar_menus.
             * This never bypasses plan_modules: it only supplies the role
             * permission portion for an already plan-enabled module.
             */
            if (
                !$roleViewAccess &&
                fieldplx_sidebar_table_exists($pdo, 'sidebar_menus') &&
                fieldplx_sidebar_table_exists($pdo, 'role_sidebar_access')
            ) {
                try {
                    $legacyStmt = $pdo->prepare(
                        "SELECT m.id AS module_id
                         FROM modules m
                         INNER JOIN sidebar_menus sm
                             ON sm.menu_slug = m.module_code
                         INNER JOIN role_sidebar_access rsa
                             ON rsa.menu_id = sm.id
                            AND rsa.role_id = :role_id
                            AND rsa.can_view = 1
                         WHERE m.is_active = 1"
                    );
                    $legacyStmt->execute(array(':role_id' => $roleId));
                    foreach ($legacyStmt->fetchAll(PDO::FETCH_COLUMN) as $legacyModuleId) {
                        $roleViewAccess[(int)$legacyModuleId] = true;
                    }
                } catch (Throwable $legacyError) {
                    error_log('FieldPlx sidebar legacy permission error: ' . $legacyError->getMessage());
                }
            }
        }

        /* ---------------------------------------------------------------
         * Map plan modules and calculate direct visibility
         * ------------------------------------------------------------- */
        $moduleMap = array();
        $directVisibleIds = array();

        foreach ($planModules as $module) {
            $moduleId = (int)$module['id'];
            if ($moduleId <= 0) {
                continue;
            }

            $moduleMap[$moduleId] = $module;

            if ($isTenantAdmin) {
                $directVisibleIds[$moduleId] = true;
            } elseif (isset($roleViewAccess[$moduleId]) && $roleViewAccess[$moduleId] === true) {
                $directVisibleIds[$moduleId] = true;
            }
        }

        /* ---------------------------------------------------------------
         * Keep ancestors for visible child modules.
         * A parent used only as a container receives no clickable URL.
         * ------------------------------------------------------------- */
        $visibleIds = $directVisibleIds;

        foreach (array_keys($directVisibleIds) as $visibleModuleId) {
            $parentId = isset($moduleMap[$visibleModuleId])
                ? (int)$moduleMap[$visibleModuleId]['parent_id']
                : 0;

            $guard = 0;
            while ($parentId > 0 && isset($moduleMap[$parentId]) && $guard < 25) {
                $visibleIds[$parentId] = true;
                $parentId = (int)$moduleMap[$parentId]['parent_id'];
                $guard++;
            }
        }

        foreach ($planModules as $module) {
            $moduleId = (int)$module['id'];
            if (!isset($visibleIds[$moduleId])) {
                continue;
            }

            $isDirectlyAllowed = isset($directVisibleIds[$moduleId]);

            $sidebarMenus[] = array(
                'id' => $moduleId,
                'parent_id' => !empty($module['parent_id']) ? (int)$module['parent_id'] : null,
                'menu_title' => (string)$module['module_name'],
                'menu_slug' => (string)$module['module_code'],
                'menu_url' => $isDirectlyAllowed ? (string)$module['menu_url'] : '',
                'icon' => trim((string)$module['icon_name']) !== ''
                    ? (string)$module['icon_name']
                    : 'circle',
                'sort_order' => (int)$module['menu_order'],
                'is_container_only' => $isDirectlyAllowed ? 0 : 1
            );
        }

    } catch (Throwable $e) {
        error_log('FieldPlx plan sidebar load error: ' . $e->getMessage());
        $sidebarMenus = array();
    }
}

/* -----------------------------------------------------------------------
 * No hard-coded module fallback
 * -----------------------------------------------------------------------
 * Important: only modules with modules.is_sidebar_item = 1 (the
 * "Display this module in navigation" switch) are rendered. If no module
 * qualifies, the navigation list remains empty.
 * --------------------------------------------------------------------- */

/* -----------------------------------------------------------------------
 * Build tree - supports more than two levels
 * --------------------------------------------------------------------- */
$sidebarById = array();
foreach ($sidebarMenus as $menu) {
    $id = (int)$menu['id'];
    $menu['children'] = array();
    $sidebarById[$id] = $menu;
}

$mainMenus = array();
foreach ($sidebarById as $id => $menu) {
    $parentId = !empty($menu['parent_id']) ? (int)$menu['parent_id'] : 0;
    if ($parentId > 0 && isset($sidebarById[$parentId])) {
        $sidebarById[$parentId]['children'][] = &$sidebarById[$id];
    } else {
        $mainMenus[] = &$sidebarById[$id];
    }
}
unset($menu);

if (!function_exists('fieldplx_sidebar_sort_tree')) {
    function fieldplx_sidebar_sort_tree(&$items)
    {
        usort($items, function ($a, $b) {
            $ao = isset($a['sort_order']) ? (int)$a['sort_order'] : 0;
            $bo = isset($b['sort_order']) ? (int)$b['sort_order'] : 0;
            if ($ao === $bo) {
                return (int)$a['id'] <=> (int)$b['id'];
            }
            return $ao <=> $bo;
        });

        foreach ($items as &$item) {
            if (!empty($item['children'])) {
                fieldplx_sidebar_sort_tree($item['children']);
            }
        }
        unset($item);
    }
}
fieldplx_sidebar_sort_tree($mainMenus);

/* -----------------------------------------------------------------------
 * Quick Create - static placement, actions follow enabled navigation modules
 * --------------------------------------------------------------------- */
$enabledModuleCodes = array();
foreach ($sidebarMenus as $sidebarMenuRow) {
    if (!empty($sidebarMenuRow['is_container_only'])) {
        continue;
    }
    $code = strtolower(trim((string)($sidebarMenuRow['menu_slug'] ?? '')));
    if ($code !== '') {
        $enabledModuleCodes[$code] = true;
    }
}

$quickCreateDefinitions = array(
    array(
        'codes' => array('clients', 'customers', 'crm-clients'),
        'label' => 'Client',
        'url' => 'client-form.php',
        'icon' => 'bi bi-person'
    ),
    array(
        'codes' => array('requests', 'service-requests', 'service_requests'),
        'label' => 'Request',
        'url' => 'add-request.php',
        'icon' => 'bi bi-inbox'
    ),
    array(
        'codes' => array('quotes', 'quotation', 'quotations'),
        'label' => 'Quote',
        'url' => 'add-quotation.php',
        'icon' => 'bi bi-file-earmark-text'
    ),
    array(
        'codes' => array('jobs', 'job-cards', 'job_cards'),
        'label' => 'Job',
        'url' => 'job-form.php',
        'icon' => 'bi bi-hammer'
    ),
    array(
        'codes' => array('invoices', 'invoice'),
        'label' => 'Invoice',
        'url' => 'add-invoice.php',
        'icon' => 'bi bi-receipt'
    )
);

$quickCreateActions = array();
foreach ($quickCreateDefinitions as $definition) {
    foreach ($definition['codes'] as $candidateCode) {
        if (isset($enabledModuleCodes[$candidateCode])) {
            $quickCreateActions[] = $definition;
            break;
        }
    }
}

/* -----------------------------------------------------------------------
 * Helpers
 * --------------------------------------------------------------------- */
if (!function_exists('sidebar_is_active')) {
    function sidebar_is_active($url, $currentPage)
    {
        $url = trim((string)$url);
        if ($url === '' || $url === '#') {
            return false;
        }
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (!is_string($urlPath)) {
            return false;
        }
        return basename($urlPath) === (string)$currentPage;
    }
}

if (!function_exists('fieldplx_sidebar_has_active_child')) {
    function fieldplx_sidebar_has_active_child($menu, $currentPage)
    {
        if (!empty($menu['children']) && is_array($menu['children'])) {
            foreach ($menu['children'] as $child) {
                if (sidebar_is_active(isset($child['menu_url']) ? $child['menu_url'] : '', $currentPage)) {
                    return true;
                }
                if (fieldplx_sidebar_has_active_child($child, $currentPage)) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('fieldplx_sidebar_icon_class')) {
    function fieldplx_sidebar_icon_class($icon, $code)
    {
        $icon = trim((string)$icon);
        $code = strtolower(trim((string)$code));

        if ($icon !== '') {
            if (preg_match('/(^|\\s)bi(\\s|$)/', $icon)) {
                return $icon;
            }
            if (strpos($icon, 'bi-') === 0) {
                return 'bi ' . $icon;
            }
        }

        $map = array(
            'home' => 'bi bi-house-door',
            'dashboard' => 'bi bi-house-door',
            'clients' => 'bi bi-people',
            'customers' => 'bi bi-people',
            'crm' => 'bi bi-person-vcard',
            'requests' => 'bi bi-inbox',
            'service-requests' => 'bi bi-inbox',
            'quotes' => 'bi bi-file-earmark-text',
            'quotation' => 'bi bi-file-earmark-text',
            'quotations' => 'bi bi-file-earmark-text',
            'jobs' => 'bi bi-briefcase',
            'job-cards' => 'bi bi-briefcase',
            'schedule' => 'bi bi-calendar3',
            'scheduling' => 'bi bi-calendar3',
            'team' => 'bi bi-people',
            'teams' => 'bi bi-people',
            'workforce' => 'bi bi-person-workspace',
            'products' => 'bi bi-box-seam',
            'products-services' => 'bi bi-box-seam',
            'inventory' => 'bi bi-boxes',
            'finance' => 'bi bi-cash-stack',
            'invoices' => 'bi bi-receipt',
            'invoice' => 'bi bi-receipt',
            'payments' => 'bi bi-credit-card',
            'expenses' => 'bi bi-wallet2',
            'reports' => 'bi bi-bar-chart-line',
            'insights' => 'bi bi-graph-up-arrow',
            'audit-log' => 'bi bi-clock-history',
            'administration' => 'bi bi-gear',
            'roles' => 'bi bi-shield-check',
            'workflows' => 'bi bi-diagram-3',
            'settings' => 'bi bi-gear',
            'theme-settings' => 'bi bi-palette',
            'theme-control' => 'bi bi-palette'
        );

        if (isset($map[$code])) {
            return $map[$code];
        }

        $legacy = array(
            'house' => 'bi bi-house-door',
            'layout-dashboard' => 'bi bi-grid',
            'receipt-indian-rupee' => 'bi bi-receipt',
            'receipt' => 'bi bi-receipt',
            'palette' => 'bi bi-palette',
            'users' => 'bi bi-people',
            'user-round' => 'bi bi-person',
            'calendar' => 'bi bi-calendar3',
            'calendar-days' => 'bi bi-calendar3',
            'briefcase' => 'bi bi-briefcase',
            'wallet-cards' => 'bi bi-wallet2',
            'credit-card' => 'bi bi-credit-card',
            'bar-chart-3' => 'bi bi-bar-chart-line',
            'chart-no-axes-combined' => 'bi bi-bar-chart-line',
            'shield-check' => 'bi bi-shield-check',
            'workflow' => 'bi bi-diagram-3',
            'settings-2' => 'bi bi-gear',
            'settings' => 'bi bi-gear',
            'circle' => 'bi bi-circle'
        );

        if ($icon !== '' && isset($legacy[$icon])) {
            return $legacy[$icon];
        }

        return 'bi bi-circle';
    }
}

if (!function_exists('fieldplx_render_sidebar_item')) {
    function fieldplx_render_sidebar_item($menu, $currentPage, $depth)
    {
        $children = !empty($menu['children']) && is_array($menu['children'])
            ? $menu['children']
            : array();

        $title = (string)($menu['menu_title'] ?? '');
        $code = (string)($menu['menu_slug'] ?? '');
        $url = trim((string)($menu['menu_url'] ?? ''));
        $iconClass = fieldplx_sidebar_icon_class($menu['icon'] ?? '', $code);

        $selfActive = sidebar_is_active($url, $currentPage);
        $childActive = fieldplx_sidebar_has_active_child($menu, $currentPage);
        $isOpen = $childActive || $selfActive;

        if ($children) {
            echo '<div class="fp-sidebar-menu' . ($isOpen ? ' menu-open' : '') . '" data-menu-id="' . (int)$menu['id'] . '">';
            echo '<button type="button" class="nav-item fp-sidebar-parent-toggle' . ($isOpen ? ' active' : '') . '"';
            echo ' data-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
            echo ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
            echo ' aria-expanded="' . ($isOpen ? 'true' : 'false') . '">';
            echo '<i class="' . htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') . ' fp-nav-icon"></i>';
            echo '<span class="fp-nav-label">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '<i class="bi bi-chevron-down fp-sidebar-chevron"></i>';
            echo '</button>';

            echo '<div class="sidebar-children">';

            if ($url !== '') {
                echo '<a class="fp-sidebar-sublink' . ($selfActive ? ' active' : '') . '"';
                echo ' data-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
                echo ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
                echo ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
                echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
                echo '</a>';
            }

            foreach ($children as $child) {
                fieldplx_render_sidebar_item($child, $currentPage, $depth + 1);
            }

            echo '</div>';
            echo '</div>';
            return;
        }

        if ($url === '') {
            return;
        }

        if ($depth > 0) {
            echo '<a class="fp-sidebar-sublink' . ($selfActive ? ' active' : '') . '"';
            echo ' data-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
            echo ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
            echo ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
            echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            echo '</a>';
            return;
        }

        echo '<a class="nav-item' . ($selfActive ? ' active' : '') . '"';
        echo ' data-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
        echo ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
        echo ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
        echo '<i class="' . htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') . ' fp-nav-icon"></i>';
        echo '<span class="fp-nav-label">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
        echo '</a>';
    }
}
?>

<style>
/* FieldPlx compact Jobber-style business sidebar */
#sidebar.sidebar {
    z-index: 1400 !important;
    isolation: isolate;
    overflow: visible !important;
    font-size: 13px !important;
}

#sidebar.sidebar nav {
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 8px 8px 14px;
    scrollbar-width: thin;
    scrollbar-color: rgba(23, 63, 77, .34) transparent;
}

/* Very compact sidebar scrollbar. */
#sidebar.sidebar nav::-webkit-scrollbar {
    width: 3px;
    height: 3px;
}
#sidebar.sidebar nav::-webkit-scrollbar-track {
    background: transparent;
    margin: 5px 0;
}
#sidebar.sidebar nav::-webkit-scrollbar-thumb {
    background: rgba(23, 63, 77, .28);
    border-radius: 999px;
}
#sidebar.sidebar nav::-webkit-scrollbar-thumb:hover {
    background: rgba(8, 143, 188, .62);
}
#sidebar.sidebar nav::-webkit-scrollbar-corner {
    background: transparent;
}

#sidebar.sidebar .nav-group {
    position: relative;
    z-index: 3;
}

#sidebar.sidebar .nav-item,
#sidebar.sidebar .fp-sidebar-parent-toggle {
    width: 100%;
    min-height: 36px !important;
    margin: 2px 0 !important;
    padding: 5px 9px !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: transparent;
    color: inherit;
    text-align: left;
    text-decoration: none;
    font-family: inherit;
    font-size: 12.5px !important;
    font-weight: 500 !important;
    line-height: 1.2 !important;
    cursor: pointer;
    position: relative;
}

#sidebar.sidebar .nav-item:hover,
#sidebar.sidebar .fp-sidebar-parent-toggle:hover {
    background: rgba(8, 165, 210, .08) !important;
}

#sidebar.sidebar .nav-item.active,
#sidebar.sidebar .fp-sidebar-parent-toggle.active {
    background: rgba(8, 165, 210, .10) !important;
    color: #078fbc !important;
}

#sidebar.sidebar .fp-nav-icon {
    width: 18px;
    min-width: 18px;
    font-size: 17px !important;
    line-height: 1;
    text-align: center;
}

#sidebar.sidebar .fp-nav-label {
    min-width: 0;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

#sidebar.sidebar .fp-sidebar-chevron {
    margin-left: auto;
    font-size: 11px !important;
    transition: transform .18s ease;
}

#sidebar.sidebar .fp-sidebar-menu.menu-open > .fp-sidebar-parent-toggle .fp-sidebar-chevron {
    transform: rotate(180deg);
}

/* Submenus are closed by default and only open after parent click. */
#sidebar.sidebar .sidebar-children {
    display: none !important;
    position: relative;
    padding: 2px 0 4px 28px;
    z-index: 3;
}

#sidebar.sidebar .fp-sidebar-menu.menu-open > .sidebar-children {
    display: block !important;
}

#sidebar.sidebar .fp-sidebar-sublink {
    min-height: 32px;
    margin: 1px 0;
    padding: 7px 9px;
    display: flex;
    align-items: center;
    border-radius: 7px;
    color: inherit;
    text-decoration: none;
    font-size: 12.5px !important;
    font-weight: 400 !important;
    line-height: 1.2;
}

#sidebar.sidebar .fp-sidebar-sublink:hover {
    background: rgba(8, 165, 210, .07);
}

#sidebar.sidebar .fp-sidebar-sublink.active {
    color: #078fbc;
    background: rgba(8, 165, 210, .09);
    font-weight: 500 !important;
}

/* Static Create row, Jobber-style. */
.fp-sidebar-create {
    position: relative;
    z-index: 20;
    margin: 0 0 6px;
}

.fp-sidebar-create-button {
    width: 100%;
    min-height: 38px;
    padding: 6px 10px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #e1e7ec;
    border-radius: 8px;
    background: #fff;
    color: #0b2d3c;
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
}

.fp-sidebar-create-button:hover,
.fp-sidebar-create.open .fp-sidebar-create-button {
    background: #f7f9fa;
}

.fp-sidebar-create-button .fp-create-symbol {
    width: 22px;
    min-width: 22px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #173f4d;
    color: #fff;
    font-size: 13px;
}

.fp-sidebar-create.open .fp-create-symbol .bi::before {
    content: "\\f62a"; /* bootstrap icon x */
}

.fp-sidebar-create-menu {
    position: fixed;
    z-index: 2147482000;
    min-width: 430px;
    max-width: calc(100vw - 24px);
    padding: 7px;
    display: grid;
    grid-template-columns: repeat(5, minmax(72px, 1fr));
    gap: 4px;
    border: 1px solid #dfe5e9;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 14px 36px rgba(15, 23, 42, .16);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-3px);
    transition: opacity .15s ease, transform .15s ease, visibility .15s ease;
}

.fp-sidebar-create.open .fp-sidebar-create-menu {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.fp-sidebar-create-action {
    min-width: 0;
    padding: 9px 5px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    border-radius: 7px;
    color: #253b47;
    text-align: center;
    text-decoration: none;
    font-size: 11.5px;
    font-weight: 500;
}

.fp-sidebar-create-action:hover {
    background: #f5f8fa;
}

.fp-sidebar-create-action i {
    font-size: 20px;
}

/* Collapsed sidebar: keep only icons and use portal tooltip. */
body.sidebar-collapsed #sidebar.sidebar .fp-nav-label,
body.sidebar-collapsed #sidebar.sidebar .fp-sidebar-chevron,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-nav-label,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-sidebar-chevron {
    display: none !important;
}

body.sidebar-collapsed #sidebar.sidebar .nav-item,
body.sidebar-collapsed #sidebar.sidebar .fp-sidebar-parent-toggle,
body.fp-sidebar-collapsed #sidebar.sidebar .nav-item,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-sidebar-parent-toggle {
    justify-content: center;
    padding-left: 7px !important;
    padding-right: 7px !important;
}

body.sidebar-collapsed #sidebar.sidebar .sidebar-children,
body.fp-sidebar-collapsed #sidebar.sidebar .sidebar-children {
    display: none !important;
}

.fp-sidebar-floating-tooltip {
    position: fixed;
    z-index: 2147483000;
    display: none;
    max-width: 260px;
    padding: 5px 8px;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 6px;
    background: #123946;
    color: #fff;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.2;
    white-space: nowrap;
    box-shadow: 0 6px 18px rgba(8, 43, 58, .20);
    pointer-events: none;
}

.fp-sidebar-floating-tooltip.is-visible { display: block; }

/* ------------------------------------------------------------------
 * Collapsed/sidebar icon rail alignment
 * ------------------------------------------------------------------ */
#sidebar.sidebar nav::-webkit-scrollbar-button {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}

/* Keep the scrollbar almost invisible until the user is actually over it. */
#sidebar.sidebar nav::-webkit-scrollbar {
    width: 2px !important;
}
#sidebar.sidebar nav::-webkit-scrollbar-thumb {
    background: rgba(23, 63, 77, .20) !important;
}
#sidebar.sidebar nav:hover::-webkit-scrollbar-thumb {
    background: rgba(23, 63, 77, .34) !important;
}
#sidebar.sidebar nav::-webkit-scrollbar-thumb:hover {
    background: rgba(8, 143, 188, .58) !important;
}
#sidebar.sidebar nav {
    scrollbar-width: thin;
    scrollbar-color: rgba(23, 63, 77, .24) transparent;
}

/* Normal expanded sidebar remains compact and visually aligned. */
#sidebar.sidebar .nav-item,
#sidebar.sidebar .fp-sidebar-parent-toggle {
    min-height: 36px !important;
}
#sidebar.sidebar .fp-nav-icon {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    vertical-align: middle !important;
}

/* All supported collapsed-state class names. */
body.sidebar-collapsed #sidebar.sidebar nav,
body.fp-sidebar-collapsed #sidebar.sidebar nav,
body.fieldplx-sidebar-collapsed #sidebar.sidebar nav,
#sidebar.sidebar.collapsed nav {
    padding: 8px 8px 12px !important;
    overflow-x: hidden !important;
}

body.sidebar-collapsed #sidebar.sidebar .nav-group,
body.fp-sidebar-collapsed #sidebar.sidebar .nav-group,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .nav-group,
#sidebar.sidebar.collapsed .nav-group {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    width: 100% !important;
}

body.sidebar-collapsed #sidebar.sidebar .fp-sidebar-create,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-sidebar-create,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .fp-sidebar-create,
#sidebar.sidebar.collapsed .fp-sidebar-create,
body.sidebar-collapsed #sidebar.sidebar .fp-sidebar-menu,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-sidebar-menu,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .fp-sidebar-menu,
#sidebar.sidebar.collapsed .fp-sidebar-menu {
    width: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
}

body.sidebar-collapsed #sidebar.sidebar .nav-item,
body.sidebar-collapsed #sidebar.sidebar .fp-sidebar-parent-toggle,
body.fp-sidebar-collapsed #sidebar.sidebar .nav-item,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-sidebar-parent-toggle,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .nav-item,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .fp-sidebar-parent-toggle,
#sidebar.sidebar.collapsed .nav-item,
#sidebar.sidebar.collapsed .fp-sidebar-parent-toggle {
    width: 42px !important;
    min-width: 42px !important;
    max-width: 42px !important;
    height: 40px !important;
    min-height: 40px !important;
    margin: 2px auto !important;
    padding: 0 !important;
    gap: 0 !important;
    justify-content: center !important;
    align-items: center !important;
    border-radius: 9px !important;
}

body.sidebar-collapsed #sidebar.sidebar .fp-sidebar-create-button,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-sidebar-create-button,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .fp-sidebar-create-button,
#sidebar.sidebar.collapsed .fp-sidebar-create-button {
    width: 42px !important;
    min-width: 42px !important;
    max-width: 42px !important;
    height: 40px !important;
    min-height: 40px !important;
    margin: 0 auto 4px !important;
    padding: 0 !important;
    gap: 0 !important;
    justify-content: center !important;
    border-radius: 9px !important;
}

body.sidebar-collapsed #sidebar.sidebar .fp-sidebar-create-button .fp-create-symbol,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-sidebar-create-button .fp-create-symbol,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .fp-sidebar-create-button .fp-create-symbol,
#sidebar.sidebar.collapsed .fp-sidebar-create-button .fp-create-symbol {
    width: 24px !important;
    min-width: 24px !important;
    height: 24px !important;
    margin: 0 !important;
}

body.sidebar-collapsed #sidebar.sidebar .fp-nav-icon,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-nav-icon,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .fp-nav-icon,
#sidebar.sidebar.collapsed .fp-nav-icon {
    width: 20px !important;
    min-width: 20px !important;
    height: 20px !important;
    margin: 0 !important;
    font-size: 18px !important;
    line-height: 20px !important;
}

body.sidebar-collapsed #sidebar.sidebar .fp-nav-label,
body.sidebar-collapsed #sidebar.sidebar .fp-sidebar-chevron,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-nav-label,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-sidebar-chevron,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .fp-nav-label,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .fp-sidebar-chevron,
#sidebar.sidebar.collapsed .fp-nav-label,
#sidebar.sidebar.collapsed .fp-sidebar-chevron {
    display: none !important;
}

body.sidebar-collapsed #sidebar.sidebar .sidebar-children,
body.fp-sidebar-collapsed #sidebar.sidebar .sidebar-children,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .sidebar-children,
#sidebar.sidebar.collapsed .sidebar-children {
    display: none !important;
}

/* No horizontal movement when active/hovered in icon rail mode. */
body.sidebar-collapsed #sidebar.sidebar .nav-item:hover,
body.sidebar-collapsed #sidebar.sidebar .fp-sidebar-parent-toggle:hover,
body.fp-sidebar-collapsed #sidebar.sidebar .nav-item:hover,
body.fp-sidebar-collapsed #sidebar.sidebar .fp-sidebar-parent-toggle:hover,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .nav-item:hover,
body.fieldplx-sidebar-collapsed #sidebar.sidebar .fp-sidebar-parent-toggle:hover,
#sidebar.sidebar.collapsed .nav-item:hover,
#sidebar.sidebar.collapsed .fp-sidebar-parent-toggle:hover {
    transform: none !important;
}

/* Tooltip: compact, centered against the hovered row and always above pages. */
.fp-sidebar-floating-tooltip {
    z-index: 2147483640 !important;
    max-width: 220px !important;
    padding: 6px 9px !important;
    border: 0 !important;
    border-radius: 7px !important;
    background: #123946 !important;
    color: #fff !important;
    font-size: 11.5px !important;
    font-weight: 500 !important;
    line-height: 1.2 !important;
    box-shadow: 0 7px 20px rgba(8, 43, 58, .20) !important;
}

@media (max-width: 991.98px) {
    .fp-sidebar-create-menu {
        min-width: 360px;
        grid-template-columns: repeat(5, minmax(60px, 1fr));
    }
}
</style>

<aside id="sidebar" class="sidebar" data-plan-id="<?= (int)$activePlanId ?>">
    <nav aria-label="Business navigation">
        <div class="nav-group">
            <?php if (!empty($quickCreateActions)): ?>
                <div class="fp-sidebar-create" id="fpSidebarCreate">
                    <button type="button" class="fp-sidebar-create-button" id="fpSidebarCreateButton" data-label="Create" title="Create" aria-expanded="false" aria-controls="fpSidebarCreateMenu">
                        <span class="fp-create-symbol"><i class="bi bi-plus"></i></span>
                        <span class="fp-nav-label">Create</span>
                    </button>
                    <div class="fp-sidebar-create-menu" id="fpSidebarCreateMenu" role="menu" aria-label="Create new">
                        <?php foreach ($quickCreateActions as $action): ?>
                            <a class="fp-sidebar-create-action" data-label="<?= htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($action['url'], ENT_QUOTES, 'UTF-8') ?>" role="menuitem">
                                <i class="<?= htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                                <span><?= htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($mainMenus as $menu): ?>
                <?php fieldplx_render_sidebar_item($menu, $currentPage, 0); ?>
            <?php endforeach; ?>
        </div>
    </nav>
</aside>

<script>
(function () {
    'use strict';

    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    var tooltip = document.createElement('div');
    tooltip.className = 'fp-sidebar-floating-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    document.body.appendChild(tooltip);

    function sidebarIsCollapsed() {
        var rect = sidebar.getBoundingClientRect();
        var body = document.body;
        return rect.width <= 100 ||
            body.classList.contains('sidebar-collapsed') ||
            body.classList.contains('fp-sidebar-collapsed') ||
            body.classList.contains('fieldplx-sidebar-collapsed') ||
            sidebar.classList.contains('collapsed');
    }

    function hideTooltip() {
        tooltip.classList.remove('is-visible');
        tooltip.textContent = '';
    }

    function showTooltip(item) {
        var label = item.getAttribute('data-label') || item.getAttribute('title') || '';
        if (!label) return;

        var rect = item.getBoundingClientRect();
        var sidebarRect = sidebar.getBoundingClientRect();
        tooltip.textContent = label;

        /* Keep tooltip outside the sidebar so it is never clipped by scrolling. */
        tooltip.style.left = Math.round(sidebarRect.right + 7) + 'px';
        tooltip.style.top = Math.round(rect.top + rect.height / 2) + 'px';
        tooltip.style.transform = 'translateY(-50%)';
        tooltip.classList.add('is-visible');

        /* Prevent the tooltip from running off the bottom of the viewport. */
        requestAnimationFrame(function () {
            var tr = tooltip.getBoundingClientRect();
            if (tr.bottom > window.innerHeight - 6) {
                tooltip.style.top = Math.max(6, window.innerHeight - tr.height - 6) + 'px';
                tooltip.style.transform = 'none';
            } else if (tr.top < 6) {
                tooltip.style.top = '6px';
                tooltip.style.transform = 'none';
            }
        });
    }

    Array.prototype.forEach.call(sidebar.querySelectorAll('.nav-item[data-label], .fp-sidebar-parent-toggle[data-label], .fp-sidebar-sublink[data-label], .fp-sidebar-create-button[data-label], .fp-sidebar-create-action[data-label]'), function (item) {
        /* We render our own immediate tooltip; suppress the delayed browser title bubble. */
        if (item.hasAttribute('title')) {
            item.setAttribute('data-native-title', item.getAttribute('title'));
            item.removeAttribute('title');
        }
        item.addEventListener('mouseenter', function () { showTooltip(item); });
        item.addEventListener('focus', function () { showTooltip(item); });
        item.addEventListener('mouseleave', hideTooltip);
        item.addEventListener('blur', hideTooltip);
    });

    /* Parent accordion: submenu becomes visible only when parent is clicked. */
    Array.prototype.forEach.call(sidebar.querySelectorAll('.fp-sidebar-parent-toggle'), function (toggle) {
        toggle.addEventListener('click', function () {
            var menu = toggle.closest('.fp-sidebar-menu');
            if (!menu) return;

            if (sidebarIsCollapsed()) {
                document.body.classList.remove('sidebar-collapsed', 'fp-sidebar-collapsed', 'fieldplx-sidebar-collapsed');
            }

            var willOpen = !menu.classList.contains('menu-open');

            Array.prototype.forEach.call(sidebar.querySelectorAll('.fp-sidebar-menu.menu-open'), function (openMenu) {
                if (openMenu !== menu) {
                    openMenu.classList.remove('menu-open');
                    var openToggle = openMenu.querySelector(':scope > .fp-sidebar-parent-toggle');
                    if (openToggle) openToggle.setAttribute('aria-expanded', 'false');
                }
            });

            menu.classList.toggle('menu-open', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });

    /* Jobber-style static Create flyout. */
    var createWrap = document.getElementById('fpSidebarCreate');
    var createButton = document.getElementById('fpSidebarCreateButton');
    var createMenu = document.getElementById('fpSidebarCreateMenu');

    function positionCreateMenu() {
        if (!createButton || !createMenu) return;
        var rect = createButton.getBoundingClientRect();
        var width = Math.min(430, window.innerWidth - rect.right - 20);
        if (width < 300) width = Math.min(430, window.innerWidth - 24);
        createMenu.style.width = width + 'px';
        createMenu.style.left = Math.min(rect.right + 8, window.innerWidth - width - 12) + 'px';
        createMenu.style.top = Math.max(8, rect.top) + 'px';
    }

    function closeCreate() {
        if (!createWrap || !createButton) return;
        createWrap.classList.remove('open');
        createButton.setAttribute('aria-expanded', 'false');
    }

    if (createButton && createWrap) {
        createButton.addEventListener('click', function (event) {
            event.stopPropagation();
            var willOpen = !createWrap.classList.contains('open');
            createWrap.classList.toggle('open', willOpen);
            createButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) positionCreateMenu();
        });
    }

    if (createMenu) {
        createMenu.addEventListener('click', function (event) { event.stopPropagation(); });
    }

    document.addEventListener('click', function (event) {
        if (createWrap && !createWrap.contains(event.target) && (!createMenu || !createMenu.contains(event.target))) {
            closeCreate();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeCreate();
    });

    window.addEventListener('scroll', function () {
        hideTooltip();
        if (createWrap && createWrap.classList.contains('open')) positionCreateMenu();
    }, true);

    window.addEventListener('resize', function () {
        hideTooltip();
        if (createWrap && createWrap.classList.contains('open')) positionCreateMenu();
    });
})();
</script>