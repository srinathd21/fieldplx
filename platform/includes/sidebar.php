<?php
/**
 * FieldPlx Platform Panel Sidebar
 *
 * Requires:
 * - platform/includes/auth.php
 * - platform/includes/topbar.php
 *
 * Expected variables:
 * - $activePage
 * - $basePath
 *
 * Compatible with PHP 7.2.
 */

$activePage = isset($activePage)
    ? trim((string) $activePage)
    : '';

$basePath = isset($basePath)
    ? (string) $basePath
    : '';

$platformUser = platformAuthUser();

$platformUserName = !empty($platformUser['name'])
    ? $platformUser['name']
    : 'Platform Administrator';

$platformRoleName = !empty($platformUser['role_name'])
    ? $platformUser['role_name']
    : 'Administrator';

$platformRoleCode = currentPlatformRoleCode();

/*
|--------------------------------------------------------------------------
| Sidebar helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('platformSidebarIsActive')) {
    function platformSidebarIsActive($activePage, $pages)
    {
        if (!is_array($pages)) {
            $pages = array($pages);
        }

        return in_array($activePage, $pages, true);
    }
}

if (!function_exists('platformSidebarActiveClass')) {
    function platformSidebarActiveClass(
        $activePage,
        $pages
    ) {
        return platformSidebarIsActive(
            $activePage,
            $pages
        )
            ? 'active'
            : '';
    }
}

if (!function_exists('platformSidebarOpenClass')) {
    function platformSidebarOpenClass(
        $activePage,
        $pages
    ) {
        return platformSidebarIsActive(
            $activePage,
            $pages
        )
            ? 'menu-open'
            : '';
    }
}

/*
|--------------------------------------------------------------------------
| Role access
|--------------------------------------------------------------------------
*/

$canManageTenants = hasPlatformRole(array(
    'super_admin',
    'platform_admin'
));

$canViewTenants = hasPlatformRole(array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
));

$canManageBilling = hasPlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin'
));

$canViewBilling = hasPlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin',
    'platform_read_only'
));

$canManagePlatformUsers = hasPlatformRole(array(
    'super_admin'
));

$canViewPlatformUsers = hasPlatformRole(array(
    'super_admin',
    'platform_admin',
    'platform_read_only'
));

$canManageSupport = hasPlatformRole(array(
    'super_admin',
    'platform_admin',
    'support_admin'
));

$canViewActivityLogs = hasPlatformRole(array(
    'super_admin',
    'platform_admin',
    'support_admin',
    'billing_admin',
    'platform_read_only'
));

$canManageSettings = hasPlatformRole(array(
    'super_admin',
    'platform_admin'
));


$canManageModules = hasPlatformRole(array(
    'super_admin',
    'platform_admin'
));

/*
|--------------------------------------------------------------------------
| Platform totals
|--------------------------------------------------------------------------
*/

$totalTenants = 0;
$activeTenants = 0;
$trialTenants = 0;
$pendingTenants = 0;

$tenantCountResult = $conn->query("
    SELECT
        COUNT(*) AS total_count,

        SUM(
            CASE
                WHEN status = 'active' THEN 1
                ELSE 0
            END
        ) AS active_count,

        SUM(
            CASE
                WHEN status = 'trial' THEN 1
                ELSE 0
            END
        ) AS trial_count,

        SUM(
            CASE
                WHEN status = 'pending' THEN 1
                ELSE 0
            END
        ) AS pending_count

    FROM tenants
    WHERE deleted_at IS NULL
");

if ($tenantCountResult) {
    $tenantCountRow =
        $tenantCountResult->fetch_assoc();

    $totalTenants = isset(
        $tenantCountRow['total_count']
    )
        ? (int) $tenantCountRow['total_count']
        : 0;

    $activeTenants = isset(
        $tenantCountRow['active_count']
    )
        ? (int) $tenantCountRow['active_count']
        : 0;

    $trialTenants = isset(
        $tenantCountRow['trial_count']
    )
        ? (int) $tenantCountRow['trial_count']
        : 0;

    $pendingTenants = isset(
        $tenantCountRow['pending_count']
    )
        ? (int) $tenantCountRow['pending_count']
        : 0;

    $tenantCountResult->free();
}

/*
|--------------------------------------------------------------------------
| User initials
|--------------------------------------------------------------------------
*/

$platformUserInitials = '';

$platformNameParts = preg_split(
    '/\s+/',
    trim($platformUserName)
);

if (!empty($platformNameParts[0])) {
    $platformUserInitials .= strtoupper(
        substr(
            $platformNameParts[0],
            0,
            1
        )
    );
}

if (count($platformNameParts) > 1) {
    $platformLastName = end(
        $platformNameParts
    );

    if ($platformLastName !== '') {
        $platformUserInitials .= strtoupper(
            substr(
                $platformLastName,
                0,
                1
            )
        );
    }
}

if ($platformUserInitials === '') {
    $platformUserInitials = 'PA';
}
?>

<aside
    class="platform-sidebar"
    id="platformSidebar"
>
    <div class="platform-sidebar-header">

        <a
            href="<?= platformEscape($basePath); ?>dashboard.php"
            class="platform-sidebar-brand"
        >
            <span class="platform-sidebar-logo">
                FP
            </span>

            <span class="platform-sidebar-brand-content">
                <span class="platform-sidebar-brand-name">
                    FieldPlx
                </span>

                <span class="platform-sidebar-brand-label">
                    Platform Admin
                </span>
            </span>
        </a>

        <button
            type="button"
            class="platform-sidebar-close"
            id="platformSidebarClose"
            aria-label="Close sidebar"
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>

    <div class="platform-sidebar-body">

        <div class="platform-sidebar-section">
            Overview
        </div>

        <nav class="platform-sidebar-nav">

            <a
                href="<?= platformEscape($basePath); ?>dashboard.php"
                class="platform-sidebar-link <?= platformSidebarActiveClass(
                    $activePage,
                    'dashboard'
                ); ?>"
            >
                <span class="platform-sidebar-link-icon">
                    <i class="bi bi-grid"></i>
                </span>

                <span class="platform-sidebar-link-text">
                    Dashboard
                </span>
            </a>

            <?php if ($canViewTenants): ?>
                <div
                    class="platform-sidebar-menu <?= platformSidebarOpenClass(
                        $activePage,
                        array(
                            'tenants',
                            'tenant-add',
                            'tenant-edit',
                            'tenant-view',
                            'tenant-users',
                            'tenant-usage',
                            'tenant-modules',
                            'tenant-limits',
                            'roles',
                            'role-add',
                            'role-edit',
                            'role-view'
                        )
                    ); ?>"
                >
                    <button
                        type="button"
                        class="platform-sidebar-link platform-sidebar-menu-toggle <?= platformSidebarActiveClass(
                            $activePage,
                            array(
                                'tenants',
                                'tenant-add',
                                'tenant-edit',
                                'tenant-view',
                                'tenant-users',
                                'tenant-usage',
                                'tenant-modules',
                                'tenant-limits'
                            )
                        ); ?>"
                    >
                        <span class="platform-sidebar-link-icon">
                            <i class="bi bi-buildings"></i>
                        </span>

                        <span class="platform-sidebar-link-text">
                            Tenants
                        </span>

                        <?php if ($pendingTenants > 0): ?>
                            <span class="platform-sidebar-badge">
                                <?= $pendingTenants > 99
                                    ? '99+'
                                    : (int) $pendingTenants; ?>
                            </span>
                        <?php endif; ?>

                        <span class="platform-sidebar-arrow">
                            <i class="bi bi-chevron-down"></i>
                        </span>
                    </button>

                    <div class="platform-sidebar-submenu">

                        <a
                            href="<?= platformEscape($basePath); ?>tenants.php"
                            class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                $activePage,
                                array(
                                    'tenants',
                                    'tenant-edit',
                                    'tenant-view',
                                    'tenant-users',
                                    'tenant-usage',
                                    'tenant-modules',
                                    'tenant-limits'
                                )
                            ); ?>"
                        >
                            All Tenants

                            <span class="platform-sidebar-subcount">
                                <?= (int) $totalTenants; ?>
                            </span>
                        </a>

                        <?php if ($canManageTenants): ?>
                            <a
                                href="<?= platformEscape($basePath); ?>tenant-add.php"
                                class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                    $activePage,
                                    'tenant-add'
                                ); ?>"
                            >
                                Add Tenant
                            </a>
                        <?php endif; ?>


                        <?php if ($canManageTenants): ?>
                            <a
                                href="<?= platformEscape($basePath); ?>tenant-modules.php"
                                class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                    $activePage,
                                    'tenant-modules'
                                ); ?>"
                            >
                                Tenant Modules & Features
                            </a>

                            <a
                                href="<?= platformEscape($basePath); ?>tenant-limits.php"
                                class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                    $activePage,
                                    'tenant-limits'
                                ); ?>"
                            >
                                Tenant Usage Limits
                            </a>
                        <?php endif; ?>

                        <a
                            href="<?= platformEscape($basePath); ?>roles.php"
                            class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                $activePage,
                                array(
                                    'roles',
                                    'role-edit',
                                    'role-view'
                                )
                            ); ?>"
                        >
                            Tenant Roles
                        </a>

                        <?php if ($canManageTenants): ?>
                            <a
                                href="<?= platformEscape($basePath); ?>role-add.php"
                                class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                    $activePage,
                                    'role-add'
                                ); ?>"
                            >
                                Add Role
                            </a>
                        <?php endif; ?>

                        <a
                            href="<?= platformEscape($basePath); ?>tenants.php?status=active"
                            class="platform-sidebar-sublink"
                        >
                            Active Tenants

                            <span class="platform-sidebar-subcount">
                                <?= (int) $activeTenants; ?>
                            </span>
                        </a>

                        <a
                            href="<?= platformEscape($basePath); ?>tenants.php?status=trial"
                            class="platform-sidebar-sublink"
                        >
                            Trial Tenants

                            <span class="platform-sidebar-subcount">
                                <?= (int) $trialTenants; ?>
                            </span>
                        </a>

                        <?php if ($pendingTenants > 0): ?>
                            <a
                                href="<?= platformEscape($basePath); ?>tenants.php?status=pending"
                                class="platform-sidebar-sublink"
                            >
                                Pending Approval

                                <span class="platform-sidebar-subcount warning">
                                    <?= (int) $pendingTenants; ?>
                                </span>
                            </a>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canViewBilling): ?>
                <div
                    class="platform-sidebar-menu <?= platformSidebarOpenClass(
                        $activePage,
                        array(
                            'plans',
                            'plan-add',
                            'plan-edit',
                            'plan-view',
                            'plan-modules',
                            'subscriptions',
                            'subscription-view',
                            'billing',
                            'payments'
                        )
                    ); ?>"
                >
                    <button
                        type="button"
                        class="platform-sidebar-link platform-sidebar-menu-toggle <?= platformSidebarActiveClass(
                            $activePage,
                            array(
                                'plans',
                                'plan-add',
                                'plan-edit',
                                'plan-view',
                                'plan-modules',
                                'subscriptions',
                                'subscription-view',
                                'billing',
                                'payments'
                            )
                        ); ?>"
                    >
                        <span class="platform-sidebar-link-icon">
                            <i class="bi bi-credit-card"></i>
                        </span>

                        <span class="platform-sidebar-link-text">
                            Billing
                        </span>

                        <span class="platform-sidebar-arrow">
                            <i class="bi bi-chevron-down"></i>
                        </span>
                    </button>

                    <div class="platform-sidebar-submenu">

                        <a
                            href="<?= platformEscape($basePath); ?>plans.php"
                            class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                $activePage,
                                array(
                                    'plans',
                                    'plan-add',
                                    'plan-edit',
                                    'plan-view',
                                    'plan-modules'
                                )
                            ); ?>"
                        >
                            Subscription Plans
                        </a>


                        <?php if ($canManageBilling): ?>
                            <a
                                href="<?= platformEscape($basePath); ?>plan-modules.php"
                                class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                    $activePage,
                                    'plan-modules'
                                ); ?>"
                            >
                                Plan Modules & Features
                            </a>
                        <?php endif; ?>

                        <a
                            href="<?= platformEscape($basePath); ?>subscriptions.php"
                            class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                $activePage,
                                array(
                                    'subscriptions',
                                    'subscription-view'
                                )
                            ); ?>"
                        >
                            Subscriptions
                        </a>

                        <a
                            href="<?= platformEscape($basePath); ?>billing.php"
                            class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                $activePage,
                                'billing'
                            ); ?>"
                        >
                            Billing Overview
                        </a>

                        <a
                            href="<?= platformEscape($basePath); ?>payments.php"
                            class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                $activePage,
                                'payments'
                            ); ?>"
                        >
                            Platform Payments
                        </a>

                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canViewPlatformUsers): ?>
                <div
                    class="platform-sidebar-menu <?= platformSidebarOpenClass(
                        $activePage,
                        array(
                            'platform-users',
                            'platform-user-add',
                            'platform-user-edit',
                            'platform-user-view'
                        )
                    ); ?>"
                >
                    <button
                        type="button"
                        class="platform-sidebar-link platform-sidebar-menu-toggle <?= platformSidebarActiveClass(
                            $activePage,
                            array(
                                'platform-users',
                                'platform-user-add',
                                'platform-user-edit',
                                'platform-user-view'
                            )
                        ); ?>"
                    >
                        <span class="platform-sidebar-link-icon">
                            <i class="bi bi-people"></i>
                        </span>

                        <span class="platform-sidebar-link-text">
                            Platform Users
                        </span>

                        <span class="platform-sidebar-arrow">
                            <i class="bi bi-chevron-down"></i>
                        </span>
                    </button>

                    <div class="platform-sidebar-submenu">

                        <a
                            href="<?= platformEscape($basePath); ?>platform-users.php"
                            class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                $activePage,
                                array(
                                    'platform-users',
                                    'platform-user-edit',
                                    'platform-user-view'
                                )
                            ); ?>"
                        >
                            All Platform Users
                        </a>

                        <?php if ($canManagePlatformUsers): ?>
                            <a
                                href="<?= platformEscape($basePath); ?>platform-user-add.php"
                                class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                    $activePage,
                                    'platform-user-add'
                                ); ?>"
                            >
                                Add Platform User
                            </a>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endif; ?>

            <?php if ($canManageSupport): ?>
                <div
                    class="platform-sidebar-menu <?= platformSidebarOpenClass(
                        $activePage,
                        array(
                            'support-access',
                            'support-sessions',
                            'support-session-view'
                        )
                    ); ?>"
                >
                    <button
                        type="button"
                        class="platform-sidebar-link platform-sidebar-menu-toggle <?= platformSidebarActiveClass(
                            $activePage,
                            array(
                                'support-access',
                                'support-sessions',
                                'support-session-view'
                            )
                        ); ?>"
                    >
                        <span class="platform-sidebar-link-icon">
                            <i class="bi bi-headset"></i>
                        </span>

                        <span class="platform-sidebar-link-text">
                            Support Access
                        </span>

                        <span class="platform-sidebar-arrow">
                            <i class="bi bi-chevron-down"></i>
                        </span>
                    </button>

                    <div class="platform-sidebar-submenu">

                        <a
                            href="<?= platformEscape($basePath); ?>support-access.php"
                            class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                $activePage,
                                'support-access'
                            ); ?>"
                        >
                            Tenant Support
                        </a>

                        <a
                            href="<?= platformEscape($basePath); ?>support-sessions.php"
                            class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                $activePage,
                                array(
                                    'support-sessions',
                                    'support-session-view'
                                )
                            ); ?>"
                        >
                            Support Sessions
                        </a>

                    </div>
                </div>
            <?php endif; ?>

            <div class="platform-sidebar-section mt-3">
                Monitoring
            </div>

            <a
                href="<?= platformEscape($basePath); ?>usage-reports.php"
                class="platform-sidebar-link <?= platformSidebarActiveClass(
                    $activePage,
                    array(
                        'usage-reports',
                        'usage-report-view'
                    )
                ); ?>"
            >
                <span class="platform-sidebar-link-icon">
                    <i class="bi bi-graph-up"></i>
                </span>

                <span class="platform-sidebar-link-text">
                    Usage Reports
                </span>
            </a>

            <a
                href="<?= platformEscape($basePath); ?>system-health.php"
                class="platform-sidebar-link <?= platformSidebarActiveClass(
                    $activePage,
                    'system-health'
                ); ?>"
            >
                <span class="platform-sidebar-link-icon">
                    <i class="bi bi-activity"></i>
                </span>

                <span class="platform-sidebar-link-text">
                    System Health
                </span>
            </a>

            <?php if ($canViewActivityLogs): ?>
                <a
                    href="<?= platformEscape($basePath); ?>activity-logs.php"
                    class="platform-sidebar-link <?= platformSidebarActiveClass(
                        $activePage,
                        array(
                            'activity-logs',
                            'activity-log-view'
                        )
                    ); ?>"
                >
                    <span class="platform-sidebar-link-icon">
                        <i class="bi bi-clock-history"></i>
                    </span>

                    <span class="platform-sidebar-link-text">
                        Activity Logs
                    </span>
                </a>
            <?php endif; ?>

            <?php if ($canManageSettings): ?>
                <div class="platform-sidebar-section mt-3">
                    Configuration
                </div>

                <?php if ($canManageModules): ?>
                    <div
                        class="platform-sidebar-menu <?= platformSidebarOpenClass(
                            $activePage,
                            array(
                                'modules',
                                'module-add',
                                'module-edit',
                                'module-view',
                                'module-features'
                            )
                        ); ?>"
                    >
                        <button
                            type="button"
                            class="platform-sidebar-link platform-sidebar-menu-toggle <?= platformSidebarActiveClass(
                                $activePage,
                                array(
                                    'modules',
                                    'module-add',
                                    'module-edit',
                                    'module-view',
                                    'module-features'
                                )
                            ); ?>"
                        >
                            <span class="platform-sidebar-link-icon">
                                <i class="bi bi-grid-3x3-gap"></i>
                            </span>

                            <span class="platform-sidebar-link-text">
                                Modules & Features
                            </span>

                            <span class="platform-sidebar-arrow">
                                <i class="bi bi-chevron-down"></i>
                            </span>
                        </button>

                        <div class="platform-sidebar-submenu">
                            <a
                                href="<?= platformEscape($basePath); ?>modules.php"
                                class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                    $activePage,
                                    array(
                                        'modules',
                                        'module-edit',
                                        'module-view'
                                    )
                                ); ?>"
                            >
                                All Modules
                            </a>

                            <a
                                href="<?= platformEscape($basePath); ?>module-add.php"
                                class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                    $activePage,
                                    'module-add'
                                ); ?>"
                            >
                                Add Module
                            </a>

                            <a
                                href="<?= platformEscape($basePath); ?>module-features.php"
                                class="platform-sidebar-sublink <?= platformSidebarActiveClass(
                                    $activePage,
                                    'module-features'
                                ); ?>"
                            >
                                Module Features
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <a
                    href="<?= platformEscape($basePath); ?>settings.php"
                    class="platform-sidebar-link <?= platformSidebarActiveClass(
                        $activePage,
                        'settings'
                    ); ?>"
                >
                    <span class="platform-sidebar-link-icon">
                        <i class="bi bi-gear"></i>
                    </span>

                    <span class="platform-sidebar-link-text">
                        Platform Settings
                    </span>
                </a>

                <a
                    href="<?= platformEscape($basePath); ?>system-settings.php"
                    class="platform-sidebar-link <?= platformSidebarActiveClass(
                        $activePage,
                        array(
                            'system-settings',
                            'system-settings-edit'
                        )
                    ); ?>"
                >
                    <span class="platform-sidebar-link-icon">
                        <i class="bi bi-sliders"></i>
                    </span>

                    <span class="platform-sidebar-link-text">
                        System Settings
                    </span>
                </a>

                <a
                    href="<?= platformEscape($basePath); ?>integrations.php"
                    class="platform-sidebar-link <?= platformSidebarActiveClass(
                        $activePage,
                        'integrations'
                    ); ?>"
                >
                    <span class="platform-sidebar-link-icon">
                        <i class="bi bi-plug"></i>
                    </span>

                    <span class="platform-sidebar-link-text">
                        Integrations
                    </span>
                </a>

                <a
                    href="<?= platformEscape($basePath); ?>automation.php"
                    class="platform-sidebar-link <?= platformSidebarActiveClass(
                        $activePage,
                        'automation'
                    ); ?>"
                >
                    <span class="platform-sidebar-link-icon">
                        <i class="bi bi-lightning-charge"></i>
                    </span>

                    <span class="platform-sidebar-link-text">
                        Automation
                    </span>
                </a>
            <?php endif; ?>

        </nav>

    </div>

    <div class="platform-sidebar-footer">
        <div class="platform-sidebar-user">

            <span class="platform-sidebar-user-avatar">
                <?= platformEscape(
                    $platformUserInitials
                ); ?>
            </span>

            <span class="platform-sidebar-user-content">
                <span class="platform-sidebar-user-name">
                    <?= platformEscape(
                        $platformUserName
                    ); ?>
                </span>

                <span class="platform-sidebar-user-role">
                    <?= platformEscape(
                        $platformRoleName
                    ); ?>
                </span>
            </span>

            <a
                href="<?= platformEscape($basePath); ?>logout.php"
                class="platform-sidebar-logout"
                title="Logout"
                aria-label="Logout"
            >
                <i class="bi bi-box-arrow-right"></i>
            </a>

        </div>
    </div>

</aside>

<div
    class="platform-sidebar-overlay"
    id="platformSidebarOverlay"
></div>

<style>
    :root {
        --platform-sidebar-width: 252px;
        --platform-sidebar-collapsed-width: 72px;
    }

    .platform-sidebar {
        width: var(--platform-sidebar-width);
        min-width: var(--platform-sidebar-width);
        height: calc(
            100vh - var(--platform-topbar-height)
        );
        position: sticky;
        top: var(--platform-topbar-height);
        z-index: 1020;
        display: flex;
        flex-direction: column;
        background: #111827;
        border-right: 1px solid #1f2937;
        transition:
            width 0.22s ease,
            min-width 0.22s ease,
            transform 0.22s ease;
    }

    .platform-sidebar-header {
        min-height: 65px;
        padding: 11px 13px;
        display: flex;
        align-items: center;
        border-bottom: 1px solid
            rgba(255, 255, 255, 0.08);
    }

    .platform-sidebar-brand {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #ffffff;
        text-decoration: none;
    }

    .platform-sidebar-logo {
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        );
        box-shadow:
            0 8px 20px
            rgba(124, 58, 237, 0.28);
        color: #ffffff;
        font-size: 14px;
        font-weight: 800;
    }

    .platform-sidebar-brand-content {
        min-width: 0;
        display: block;
    }

    .platform-sidebar-brand-name {
        display: block;
        color: #ffffff;
        font-size: 14px;
        font-weight: 700;
    }

    .platform-sidebar-brand-label {
        margin-top: 1px;
        display: block;
        color: #a78bfa;
        font-size: 8px;
        font-weight: 600;
        letter-spacing: 0.7px;
        text-transform: uppercase;
    }

    .platform-sidebar-close {
        width: 31px;
        height: 31px;
        margin-left: auto;
        padding: 0;
        display: none;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #9ca3af;
        font-size: 15px;
    }

    .platform-sidebar-close:hover {
        background: rgba(
            255,
            255,
            255,
            0.08
        );
        color: #ffffff;
    }

    .platform-sidebar-body {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 12px 9px;
        scrollbar-width: thin;
        scrollbar-color:
            #374151 transparent;
    }

    .platform-sidebar-body::-webkit-scrollbar {
        width: 5px;
    }

    .platform-sidebar-body::-webkit-scrollbar-thumb {
        border-radius: 10px;
        background: #374151;
    }

    .platform-sidebar-section {
        margin: 4px 10px 7px;
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.75px;
        text-transform: uppercase;
    }

    .platform-sidebar-nav {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .platform-sidebar-link {
        width: 100%;
        min-height: 40px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #cbd5e1;
        text-align: left;
        text-decoration: none;
        font-family: inherit;
        font-size: 11px;
        font-weight: 500;
        transition:
            color 0.16s ease,
            background 0.16s ease;
    }

    .platform-sidebar-link:hover {
        background: rgba(
            124,
            58,
            237,
            0.13
        );
        color: #ffffff;
    }

    .platform-sidebar-link.active {
        background: linear-gradient(
            135deg,
            rgba(124, 58, 237, 0.95),
            rgba(109, 40, 217, 0.95)
        );
        box-shadow:
            0 7px 18px
            rgba(91, 33, 182, 0.25);
        color: #ffffff;
        font-weight: 700;
    }

    .platform-sidebar-link-icon {
        width: 20px;
        height: 20px;
        flex: 0 0 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
    }

    .platform-sidebar-link-text {
        min-width: 0;
        flex: 1;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .platform-sidebar-arrow {
        margin-left: auto;
        color: #6b7280;
        font-size: 9px;
        transition: transform 0.2s ease;
    }

    .platform-sidebar-link.active
    .platform-sidebar-arrow {
        color: rgba(
            255,
            255,
            255,
            0.72
        );
    }

    .platform-sidebar-menu.menu-open
    .platform-sidebar-arrow {
        transform: rotate(180deg);
    }

    .platform-sidebar-badge {
        min-width: 19px;
        height: 18px;
        padding: 0 5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #dc2626;
        color: #ffffff;
        font-size: 8px;
        font-weight: 700;
    }

    .platform-sidebar-submenu {
        max-height: 0;
        overflow: hidden;
        padding-left: 39px;
        transition:
            max-height 0.25s ease,
            padding 0.25s ease;
    }

    .platform-sidebar-menu.menu-open
    .platform-sidebar-submenu {
        max-height: 620px;
        padding-top: 3px;
        padding-bottom: 4px;
    }

    .platform-sidebar-sublink {
        min-height: 31px;
        padding: 7px 9px;
        display: flex;
        align-items: center;
        gap: 8px;
        border-radius: 7px;
        color: #9ca3af;
        text-decoration: none;
        font-size: 9px;
        font-weight: 500;
        transition:
            color 0.16s ease,
            background 0.16s ease;
    }

    .platform-sidebar-sublink::before {
        width: 5px;
        height: 5px;
        flex: 0 0 5px;
        content: "";
        border-radius: 50%;
        background: #4b5563;
    }

    .platform-sidebar-sublink:hover {
        background: rgba(
            124,
            58,
            237,
            0.1
        );
        color: #ddd6fe;
    }

    .platform-sidebar-sublink.active {
        background: rgba(
            124,
            58,
            237,
            0.14
        );
        color: #c4b5fd;
        font-weight: 700;
    }

    .platform-sidebar-sublink.active::before {
        background: #a78bfa;
    }

    .platform-sidebar-subcount {
        min-width: 19px;
        height: 17px;
        margin-left: auto;
        padding: 0 5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #263244;
        color: #9ca3af;
        font-size: 7px;
        font-weight: 700;
    }

    .platform-sidebar-subcount.warning {
        background: rgba(
            217,
            119,
            6,
            0.2
        );
        color: #fbbf24;
    }

    .platform-sidebar-footer {
        padding: 10px;
        border-top: 1px solid
            rgba(255, 255, 255, 0.08);
    }

    .platform-sidebar-user {
        padding: 8px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 1px solid
            rgba(255, 255, 255, 0.06);
        border-radius: 10px;
        background: rgba(
            255,
            255,
            255,
            0.04
        );
    }

    .platform-sidebar-user-avatar {
        width: 31px;
        height: 31px;
        flex: 0 0 31px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: linear-gradient(
            135deg,
            #8b5cf6,
            #6d28d9
        );
        color: #ffffff;
        font-size: 9px;
        font-weight: 700;
    }

    .platform-sidebar-user-content {
        min-width: 0;
        flex: 1;
    }

    .platform-sidebar-user-name,
    .platform-sidebar-user-role {
        display: block;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .platform-sidebar-user-name {
        color: #f9fafb;
        font-size: 10px;
        font-weight: 700;
    }

    .platform-sidebar-user-role {
        margin-top: 1px;
        color: #6b7280;
        font-size: 8px;
    }

    .platform-sidebar-logout {
        width: 29px;
        height: 29px;
        flex: 0 0 29px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #6b7280;
        text-decoration: none;
        font-size: 14px;
    }

    .platform-sidebar-logout:hover {
        background: rgba(
            220,
            38,
            38,
            0.14
        );
        color: #f87171;
    }

    .platform-sidebar-overlay {
        display: none;
    }

    body.platform-sidebar-collapsed
    .platform-sidebar {
        width:
            var(
                --platform-sidebar-collapsed-width
            );
        min-width:
            var(
                --platform-sidebar-collapsed-width
            );
    }

    body.platform-sidebar-collapsed
    .platform-sidebar-brand-content,
    body.platform-sidebar-collapsed
    .platform-sidebar-section,
    body.platform-sidebar-collapsed
    .platform-sidebar-link-text,
    body.platform-sidebar-collapsed
    .platform-sidebar-arrow,
    body.platform-sidebar-collapsed
    .platform-sidebar-badge,
    body.platform-sidebar-collapsed
    .platform-sidebar-submenu,
    body.platform-sidebar-collapsed
    .platform-sidebar-user-content,
    body.platform-sidebar-collapsed
    .platform-sidebar-logout {
        display: none;
    }

    body.platform-sidebar-collapsed
    .platform-sidebar-header {
        justify-content: center;
        padding-left: 8px;
        padding-right: 8px;
    }

    body.platform-sidebar-collapsed
    .platform-sidebar-link {
        justify-content: center;
        padding-left: 8px;
        padding-right: 8px;
    }

    body.platform-sidebar-collapsed
    .platform-sidebar-user {
        justify-content: center;
        padding-left: 5px;
        padding-right: 5px;
    }

    @media (max-width: 991.98px) {
        .platform-sidebar {
            width: 264px;
            min-width: 264px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1050;
            transform: translateX(-100%);
            box-shadow:
                18px 0 50px
                rgba(17, 24, 39, 0.28);
        }

        body.platform-sidebar-mobile-open
        .platform-sidebar {
            transform: translateX(0);
        }

        body.platform-sidebar-collapsed
        .platform-sidebar {
            width: 264px;
            min-width: 264px;
        }

        body.platform-sidebar-collapsed
        .platform-sidebar-brand-content,
        body.platform-sidebar-collapsed
        .platform-sidebar-section,
        body.platform-sidebar-collapsed
        .platform-sidebar-link-text,
        body.platform-sidebar-collapsed
        .platform-sidebar-arrow,
        body.platform-sidebar-collapsed
        .platform-sidebar-badge,
        body.platform-sidebar-collapsed
        .platform-sidebar-user-content,
        body.platform-sidebar-collapsed
        .platform-sidebar-logout {
            display: block;
        }

        body.platform-sidebar-collapsed
        .platform-sidebar-submenu {
            display: block;
        }

        body.platform-sidebar-collapsed
        .platform-sidebar-header,
        body.platform-sidebar-collapsed
        .platform-sidebar-link,
        body.platform-sidebar-collapsed
        .platform-sidebar-user {
            justify-content: initial;
        }

        .platform-sidebar-close {
            display: inline-flex;
        }

        .platform-sidebar-overlay {
            position: fixed;
            inset: 0;
            z-index: 1040;
            display: block;
            visibility: hidden;
            background: rgba(
                17,
                24,
                39,
                0.55
            );
            opacity: 0;
            transition:
                opacity 0.2s ease,
                visibility 0.2s ease;
        }

        body.platform-sidebar-mobile-open
        .platform-sidebar-overlay {
            visibility: visible;
            opacity: 1;
        }
    }
</style>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        const body = document.body;

        const sidebarToggle =
            document.getElementById(
                'platformSidebarToggle'
            );

        const sidebarClose =
            document.getElementById(
                'platformSidebarClose'
            );

        const sidebarOverlay =
            document.getElementById(
                'platformSidebarOverlay'
            );

        const mobileBreakpoint = 992;

        function isMobileSidebar() {
            return window.innerWidth <
                mobileBreakpoint;
        }

        function openMobileSidebar() {
            body.classList.add(
                'platform-sidebar-mobile-open'
            );
        }

        function closeMobileSidebar() {
            body.classList.remove(
                'platform-sidebar-mobile-open'
            );
        }

        function toggleDesktopSidebar() {
            body.classList.toggle(
                'platform-sidebar-collapsed'
            );

            localStorage.setItem(
                'fieldplx_platform_sidebar_collapsed',
                body.classList.contains(
                    'platform-sidebar-collapsed'
                )
                    ? '1'
                    : '0'
            );
        }

        if (!isMobileSidebar()) {
            const savedSidebarState =
                localStorage.getItem(
                    'fieldplx_platform_sidebar_collapsed'
                );

            if (savedSidebarState === '1') {
                body.classList.add(
                    'platform-sidebar-collapsed'
                );
            }
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener(
                'click',
                function () {
                    if (isMobileSidebar()) {
                        openMobileSidebar();
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

        const menuToggles =
            document.querySelectorAll(
                '.platform-sidebar-menu-toggle'
            );

        menuToggles.forEach(
            function (menuToggle) {
                menuToggle.addEventListener(
                    'click',
                    function () {
                        const menu =
                            menuToggle.closest(
                                '.platform-sidebar-menu'
                            );

                        if (!menu) {
                            return;
                        }

                        if (
                            body.classList.contains(
                                'platform-sidebar-collapsed'
                            ) &&
                            !isMobileSidebar()
                        ) {
                            body.classList.remove(
                                'platform-sidebar-collapsed'
                            );

                            localStorage.setItem(
                                'fieldplx_platform_sidebar_collapsed',
                                '0'
                            );
                        }

                        const shouldOpen =
                            !menu.classList.contains(
                                'menu-open'
                            );

                        document
                            .querySelectorAll(
                                '.platform-sidebar-menu.menu-open'
                            )
                            .forEach(
                                function (openMenu) {
                                    if (
                                        openMenu !== menu
                                    ) {
                                        openMenu
                                            .classList
                                            .remove(
                                                'menu-open'
                                            );
                                    }
                                }
                            );

                        menu.classList.toggle(
                            'menu-open',
                            shouldOpen
                        );
                    }
                );
            }
        );

        const sidebarLinks =
            document.querySelectorAll(
                '.platform-sidebar a'
            );

        sidebarLinks.forEach(
            function (sidebarLink) {
                sidebarLink.addEventListener(
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

        window.addEventListener(
            'resize',
            function () {
                if (!isMobileSidebar()) {
                    closeMobileSidebar();
                }
            }
        );
    }
);
</script>