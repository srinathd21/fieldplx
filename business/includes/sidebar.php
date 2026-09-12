<?php
$currentPage = basename(parse_url($_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH));
$userId = (int)($_SESSION['user_id'] ?? 0);
$sidebarMenus = [];

if ($userId > 0) {
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
           AND rsa.can_view = 1
        INNER JOIN user_roles ur
            ON ur.role_id = rsa.role_id
        INNER JOIN roles r
            ON r.id = ur.role_id
           AND r.is_active = 1
        INNER JOIN users u
            ON u.id = ur.user_id
           AND u.status = 'active'
        WHERE ur.user_id = ?
          AND sm.is_active = 1
        ORDER BY
            CASE WHEN sm.parent_id IS NULL THEN sm.sort_order ELSE 999999 END ASC,
            CASE WHEN sm.parent_id IS NULL THEN sm.id ELSE sm.parent_id END ASC,
            sm.parent_id IS NOT NULL ASC,
            sm.sort_order ASC,
            sm.id ASC
    ";

    $stmt = mysqli_prepare($conn, $sidebarSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $sidebarMenus[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}

/* Demo fallback when authentication tables are not yet populated */
if (!$sidebarMenus) {
    $sidebarMenus = [
        ['id'=>1,'parent_id'=>null,'menu_title'=>'Home','menu_slug'=>'home','menu_url'=>'invoice-view.php','icon'=>'house','menu_type'=>'main','has_submenu'=>0,'sort_order'=>1],
        ['id'=>2,'parent_id'=>null,'menu_title'=>'Invoices','menu_slug'=>'invoices','menu_url'=>'invoice-view.php','icon'=>'receipt-indian-rupee','menu_type'=>'main','has_submenu'=>0,'sort_order'=>2],
        ['id'=>3,'parent_id'=>null,'menu_title'=>'Theme Settings','menu_slug'=>'theme-settings','menu_url'=>'theme-settings.php','icon'=>'palette','menu_type'=>'main','has_submenu'=>0,'sort_order'=>99],
    ];
}

$mainMenus = [];
$subMenus = [];

foreach ($sidebarMenus as $menu) {
    $menuId = (int)$menu['id'];
    $parentId = (int)($menu['parent_id'] ?? 0);
    if ($parentId <= 0) {
        $mainMenus[$menuId] = $menu;
        $mainMenus[$menuId]['children'] = [];
    } else {
        $subMenus[$parentId][] = $menu;
    }
}

foreach ($subMenus as $parentId => $children) {
    if (isset($mainMenus[$parentId])) {
        $mainMenus[$parentId]['children'] = $children;
    }
}

function sidebar_is_active(string $url, string $currentPage): bool
{
    if ($url === '' || $url === '#') return false;
    return basename((string)parse_url($url, PHP_URL_PATH)) === $currentPage;
}
?>
<aside id="sidebar" class="sidebar">
  <nav>
    <div class="nav-group">
      <?php foreach ($mainMenus as $menu): ?>
        <?php
          $children = $menu['children'] ?? [];
          $mainActive = sidebar_is_active($menu['menu_url'] ?? '', $currentPage);
          $childActive = false;
          foreach ($children as $child) {
              if (sidebar_is_active($child['menu_url'] ?? '', $currentPage)) {
                  $childActive = true;
                  break;
              }
          }
          $activeClass = ($mainActive || $childActive) ? ' active' : '';
        ?>

        <a class="nav-item<?= $activeClass ?>"
           data-label="<?= htmlspecialchars($menu['menu_title']) ?>"
           href="<?= htmlspecialchars($menu['menu_url'] ?: '#') ?>">
          <i data-lucide="<?= htmlspecialchars($menu['icon'] ?: 'circle') ?>"></i>
          <span><?= htmlspecialchars($menu['menu_title']) ?></span>
        </a>

        <?php if ($children): ?>
          <?php foreach ($children as $child): ?>
            <a class="nav-item<?= sidebar_is_active($child['menu_url'] ?? '', $currentPage) ? ' active' : '' ?>"
               style="padding-left:28px"
               data-label="<?= htmlspecialchars($child['menu_title']) ?>"
               href="<?= htmlspecialchars($child['menu_url'] ?: '#') ?>">
              <i data-lucide="<?= htmlspecialchars($child['icon'] ?: 'circle') ?>"></i>
              <span><?= htmlspecialchars($child['menu_title']) ?></span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </nav>
</aside>
