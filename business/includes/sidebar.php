<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Tenant Sidebar - Database Only
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
| Render database parent/child menu
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

$currentTenantId =
    isset($_SESSION['tenant_id'])
    ? (int) $_SESSION['tenant_id']
    : 0;

$currentPlanId =
    isset($_SESSION['plan_id'])
    ? (int) $_SESSION['plan_id']
    : 0;

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
| Load ONLY effective tenant sidebar modules
|--------------------------------------------------------------------------
|
| Rules:
| 1. Module must exist in plan_modules and is_enabled = 1.
| 2. Module must be active.
| 3. Module must be marked as sidebar item.
| 4. tenant_modules='disabled' hides it.
| 5. tenant_modules='enabled' does NOT bypass plan_modules.
|
*/
$sidebarRows = array();

if (
    $currentTenantId > 0 &&
    $currentPlanId > 0
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
        ':tenant_id' =>
            $currentTenantId,
        ':plan_id' =>
            $currentPlanId
    ));

    $sidebarRows =
        $stmt->fetchAll();
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
?>

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
                    ?>

                    <?php if (!empty($children)): ?>

                        <div class="fieldplx-sidebar-menu" data-module-id="<?= (int) $parent['id'] ?>" data-module-code="<?= ts_h(
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

                                    <a class="fieldplx-sidebar-sublink" href="<?= ts_h($parentUrl) ?>"
                                        data-module-id="<?= (int) $parent['id'] ?>" data-module-code="<?= ts_h(
                                              $parent['module_code']
                                          ) ?>">
                                        <?= ts_h(
                                            $parent['module_name']
                                        ) ?>
                                    </a>

                                <?php endif; ?>

                                <?php foreach ($children as $child): ?>

                                    <a class="fieldplx-sidebar-sublink" href="<?= ts_h(
                                        ts_safe_menu_url(
                                            $child['menu_url']
                                            ?? ''
                                        )
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

                        <a class="fieldplx-sidebar-link" href="<?= ts_h($parentUrl) ?>" data-module-id="<?= (int) $parent['id'] ?>"
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
                        'Escape' &&
                        body.classList.contains(
                            'fieldplx-sidebar-mobile-open'
                        )
                    ) {
                        closeMobileSidebar();
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
                }
            );

            syncSidebarMode();
        }
    );
</script>