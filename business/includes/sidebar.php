<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Sidebar
|--------------------------------------------------------------------------
|
| File:
| business/includes/sidebar.php
|
| Uses:
| - PDO connection from includes/database.php
| - tenant_user_id / role_id from the current authenticated session
| - sidebar_menus
| - role_sidebar_access
|
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

if (
    !isset($pdo) ||
    !($pdo instanceof PDO)
) {
    require_once __DIR__ . '/database.php';
}

/*
|--------------------------------------------------------------------------
| Current Page
|--------------------------------------------------------------------------
*/

$currentPage =
    basename(
        (string)parse_url(
            $_SERVER['PHP_SELF'] ?? '',
            PHP_URL_PATH
        )
    );

/*
|--------------------------------------------------------------------------
| Current Authenticated User / Role
|--------------------------------------------------------------------------
|
| Current FieldPlx auth.php uses:
| - tenant_user_id
| - role_id
|
| user_id fallback is retained only for older sessions.
|
*/

$userId =
    (int)(
        $_SESSION['tenant_user_id']
        ?? $_SESSION['user_id']
        ?? 0
    );

$roleId =
    (int)(
        $_SESSION['role_id']
        ?? 0
    );

$sidebarMenus = array();

/*
|--------------------------------------------------------------------------
| Load Sidebar Menu Access
|--------------------------------------------------------------------------
|
| We use the current authenticated role directly.
|
| This avoids the old dependency on:
| - $conn / mysqli
| - user_roles
| - roles.is_active
|
| auth.php already validates the logged-in user's role and account.
|
*/

if (
    $userId > 0 &&
    $roleId > 0
) {
    try {

        $sidebarSql = "
            SELECT DISTINCT
                sm.id,
                sm.parent_id,
                sm.menu_title,
                sm.menu_slug,
                sm.menu_url,
                sm.icon,
                sm.menu_type,
                sm.has_submenu,
                sm.sort_order

            FROM sidebar_menus sm

            INNER JOIN role_sidebar_access rsa
                ON rsa.menu_id = sm.id
               AND rsa.role_id = :role_id
               AND rsa.can_view = 1

            WHERE sm.is_active = 1

            ORDER BY
                CASE
                    WHEN sm.parent_id IS NULL
                    THEN sm.sort_order
                    ELSE 999999
                END ASC,

                CASE
                    WHEN sm.parent_id IS NULL
                    THEN sm.id
                    ELSE sm.parent_id
                END ASC,

                CASE
                    WHEN sm.parent_id IS NOT NULL
                    THEN 1
                    ELSE 0
                END ASC,

                sm.sort_order ASC,
                sm.id ASC
        ";

        $stmt =
            $pdo->prepare($sidebarSql);

        $stmt->bindValue(
            ':role_id',
            $roleId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $sidebarMenus =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

    } catch (Throwable $e) {

        /*
         * Sidebar failure must not crash the entire application.
         */
        error_log(
            'FieldPlx sidebar load error: ' .
            $e->getMessage()
        );

        $sidebarMenus = array();
    }
}

/*
|--------------------------------------------------------------------------
| Demo / Development Fallback
|--------------------------------------------------------------------------
|
| Used only when no sidebar permissions are currently available.
|
*/

if (!$sidebarMenus) {

    $sidebarMenus = array(

        array(
            'id' => 1,
            'parent_id' => null,
            'menu_title' => 'Home',
            'menu_slug' => 'home',
            'menu_url' => 'index.php',
            'icon' => 'house',
            'menu_type' => 'main',
            'has_submenu' => 0,
            'sort_order' => 1
        ),

        array(
            'id' => 2,
            'parent_id' => null,
            'menu_title' => 'Invoices',
            'menu_slug' => 'invoices',
            'menu_url' => 'invoice-view.php',
            'icon' => 'receipt-indian-rupee',
            'menu_type' => 'main',
            'has_submenu' => 0,
            'sort_order' => 2
        ),

        array(
            'id' => 3,
            'parent_id' => null,
            'menu_title' => 'Theme Settings',
            'menu_slug' => 'theme-settings',
            'menu_url' => 'theme-settings.php',
            'icon' => 'palette',
            'menu_type' => 'main',
            'has_submenu' => 0,
            'sort_order' => 99
        )

    );
}

/*
|--------------------------------------------------------------------------
| Build Parent / Child Menu Tree
|--------------------------------------------------------------------------
*/

$mainMenus = array();
$subMenus = array();

foreach ($sidebarMenus as $menu) {

    $menuId =
        (int)($menu['id'] ?? 0);

    $parentId =
        (int)($menu['parent_id'] ?? 0);

    if ($menuId <= 0) {
        continue;
    }

    if ($parentId <= 0) {

        $mainMenus[$menuId] = $menu;

        $mainMenus[$menuId]['children'] =
            array();

    } else {

        if (!isset($subMenus[$parentId])) {
            $subMenus[$parentId] = array();
        }

        $subMenus[$parentId][] = $menu;
    }
}

foreach (
    $subMenus as $parentId => $children
) {
    if (isset($mainMenus[$parentId])) {
        $mainMenus[$parentId]['children'] =
            $children;
    }
}

/*
|--------------------------------------------------------------------------
| Active Menu Helper
|--------------------------------------------------------------------------
*/

if (!function_exists('sidebar_is_active')) {

    function sidebar_is_active(
        $url,
        $currentPage
    ) {
        $url =
            trim((string)$url);

        if (
            $url === '' ||
            $url === '#'
        ) {
            return false;
        }

        $urlPath =
            parse_url(
                $url,
                PHP_URL_PATH
            );

        if (!is_string($urlPath)) {
            return false;
        }

        return basename($urlPath) ===
            (string)$currentPage;
    }
}
?>

<aside
    id="sidebar"
    class="sidebar"
>
    <nav>

        <div class="nav-group">

            <?php foreach ($mainMenus as $menu): ?>

                <?php
                $children =
                    isset($menu['children']) &&
                    is_array($menu['children'])
                        ? $menu['children']
                        : array();

                $menuUrl =
                    trim(
                        (string)(
                            $menu['menu_url']
                            ?? ''
                        )
                    );

                $mainActive =
                    sidebar_is_active(
                        $menuUrl,
                        $currentPage
                    );

                $childActive = false;

                foreach ($children as $child) {

                    if (
                        sidebar_is_active(
                            $child['menu_url'] ?? '',
                            $currentPage
                        )
                    ) {
                        $childActive = true;
                        break;
                    }
                }

                $activeClass =
                    ($mainActive || $childActive)
                        ? ' active'
                        : '';

                $menuTitle =
                    (string)(
                        $menu['menu_title']
                        ?? ''
                    );

                $menuIcon =
                    trim(
                        (string)(
                            $menu['icon']
                            ?? ''
                        )
                    );

                if ($menuIcon === '') {
                    $menuIcon = 'circle';
                }

                $menuHref =
                    $menuUrl !== ''
                        ? $menuUrl
                        : '#';
                ?>

                <a
                    class="nav-item<?= $activeClass ?>"
                    data-label="<?= htmlspecialchars($menuTitle, ENT_QUOTES, 'UTF-8') ?>"
                    href="<?= htmlspecialchars($menuHref, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <i
                        data-lucide="<?= htmlspecialchars($menuIcon, ENT_QUOTES, 'UTF-8') ?>"
                    ></i>

                    <span>
                        <?= htmlspecialchars($menuTitle, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>

                <?php if ($children): ?>

                    <?php foreach ($children as $child): ?>

                        <?php
                        $childUrl =
                            trim(
                                (string)(
                                    $child['menu_url']
                                    ?? ''
                                )
                            );

                        $childTitle =
                            (string)(
                                $child['menu_title']
                                ?? ''
                            );

                        $childIcon =
                            trim(
                                (string)(
                                    $child['icon']
                                    ?? ''
                                )
                            );

                        if ($childIcon === '') {
                            $childIcon = 'circle';
                        }

                        $childHref =
                            $childUrl !== ''
                                ? $childUrl
                                : '#';

                        $childClass =
                            sidebar_is_active(
                                $childUrl,
                                $currentPage
                            )
                                ? ' active'
                                : '';
                        ?>

                        <a
                            class="nav-item<?= $childClass ?>"
                            style="padding-left:28px"
                            data-label="<?= htmlspecialchars($childTitle, ENT_QUOTES, 'UTF-8') ?>"
                            href="<?= htmlspecialchars($childHref, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <i
                                data-lucide="<?= htmlspecialchars($childIcon, ENT_QUOTES, 'UTF-8') ?>"
                            ></i>

                            <span>
                                <?= htmlspecialchars($childTitle, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </a>

                    <?php endforeach; ?>

                <?php endif; ?>

            <?php endforeach; ?>

        </div>

    </nav>
</aside>
