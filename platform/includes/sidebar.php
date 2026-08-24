<?php
        $activePage = isset($activePage) ? $activePage : 'dashboard';

        function fpActive($page, $activePage)
        {
            return $page === $activePage ? 'active' : '';
        }

        function fpMenuOpen($pages, $activePage)
        {
            return in_array($activePage, $pages, true) ? 'open' : '';
        }

        $tenantMenuPages = array(
            'tenants',
            'tenant-add',
            'tenant-edit',
            'tenant-view'
        );

        $settingsMenuPages = array(
            'platform-settings',
            'country-currency-master'
        );
        ?>
        <style>
            .fp-sidebar {
                width: var(--fp-sidebar-width);
                height: 100vh;
                position: fixed;
                top: 0;
                left: 0;
                z-index: 1040;
                display: flex;
                flex-direction: column;
                border-right: 1px solid rgba(255,255,255,.08);
                background:
                    radial-gradient(circle at 115% 0%, rgba(255,255,255,.08), transparent 27%),
                    linear-gradient(180deg, #12182d 0%, #191f47 48%, #2f2d76 100%);
                box-shadow: 12px 0 34px rgba(18, 24, 45, .12);
                transition: width .22s ease, transform .22s ease;
            }

            .fp-sidebar-header {
                min-height: var(--fp-topbar-height);
                padding: 10px 14px;
                display: flex;
                align-items: center;
                border-bottom: 1px solid rgba(255,255,255,.08);
            }

            .fp-sidebar-brand {
                min-width: 0;
                display: flex;
                align-items: center;
                gap: 10px;
                color: #111827;
            }

            .fp-sidebar-logo {
                width: 38px;
                height: 38px;
                flex: 0 0 38px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 11px;
                background: linear-gradient(135deg, #a273ff, #7c3aed);
                box-shadow: 0 10px 24px rgba(124,58,237,.28);
                color: #fff;
                font-size: 14px;
                font-weight: 800;
            }

            .fp-sidebar-brand-content {
                min-width: 0;
            }

            .fp-sidebar-brand-name {
                display: block;
                color: #ffffff;
                font-size: 14px;
                font-weight: 800;
            }

            .fp-sidebar-brand-label {
                margin-top: 1px;
                display: block;
                color: #bca7ff;
                font-size: 9px;
                font-weight: 700;
            }

            .fp-sidebar-close {
                display: none;
                margin-left: auto;
                border: 0;
                background: transparent;
                color: #d8d5ef;
                font-size: 18px;
            }

            .fp-sidebar-body {
                flex: 1;
                min-height: 0;
                overflow-y: auto;
                overflow-x: hidden;
                padding: 12px 10px 18px;

                /* Firefox */
                scrollbar-width: thin;
                scrollbar-color:
                    rgba(243, 242, 246, 0.81)
                    rgba(255,255,255,.05);
            }

            /* Chrome / Edge / Safari */
            .fp-sidebar-body::-webkit-scrollbar {
                width: 0.5px;
            }

            .fp-sidebar-body::-webkit-scrollbar-track {
                background: rgba(255,255,255,.04);
                border-radius: 999px;
            }

            .fp-sidebar-body::-webkit-scrollbar-thumb {
                background:
                    linear-gradient(
                        180deg,
                        rgba(167,139,250,.65),
                        rgba(124,58,237,.72)
                    );
                border-radius: 999px;
            }

            .fp-sidebar-body::-webkit-scrollbar-thumb:hover {
                background:
                    linear-gradient(
                        180deg,
                        rgba(196,181,253,.80),
                        rgba(139,92,246,.88)
                    );
            }

            .fp-sidebar-section {
                padding: 8px 10px 6px;
                color: #9f9bbb;
                font-size: 8px;
                font-weight: 700;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            .fp-sidebar-link {
                width: 100%;
                min-height: 42px;
                margin-bottom: 3px;
                padding: 9px 10px;
                display: flex;
                align-items: center;
                gap: 11px;
                border: 0;
                border-radius: 10px;
                background: transparent;
                color: #d9d7ea;
                font-size: 11px;
                font-weight: 600;
                text-align: left;
                transition: .15s ease;
            }

            .fp-sidebar-link:hover {
                background: rgba(139, 92, 246, .16);
                color: #ffffff;
            }

            .fp-sidebar-link.active {
                background: linear-gradient(90deg, rgba(139,92,246,.30), rgba(139,92,246,.12));
                color: #ffffff;
                box-shadow: none;
            }

            .fp-sidebar-link-icon {
                width: 22px;
                flex: 0 0 22px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
            }

            .fp-sidebar-link-text {
                flex: 1;
                min-width: 0;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
            }

            .fp-sidebar-badge {
                min-width: 19px;
                height: 19px;
                padding: 0 5px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                background: rgba(167,139,250,.18);
                color: #ddd3ff;
                border: 1px solid rgba(167,139,250,.18);
                font-size: 8px;
                font-weight: 700;
            }

            .fp-sidebar-arrow {
                font-size: 10px;
                transition: transform .18s ease;
            }

            .fp-sidebar-menu.open .fp-sidebar-arrow {
                transform: rotate(180deg);
            }

            .fp-sidebar-submenu {
                display: none;
                margin: 3px 0 8px 33px;
                padding-left: 9px;
                border-left: 1px solid rgba(255,255,255,.10);
            }

            .fp-sidebar-menu.open .fp-sidebar-submenu {
                display: block;
            }

            .fp-sidebar-sublink {
                min-height: 32px;
                padding: 7px 8px;
                display: flex;
                align-items: center;
                border-radius: 8px;
                color: #b9b5cf;
                font-size: 10px;
                font-weight: 500;
            }

            .fp-sidebar-sublink:hover,
            .fp-sidebar-sublink.active {
                background: rgba(139,92,246,.14);
                color: #ffffff;
            }

            .fp-sidebar-user {
                margin: 8px 10px 0;
                padding: 11px;
                display: flex;
                align-items: center;
                gap: 9px;
                border: 1px solid rgba(255,255,255,.10);
                border-radius: 11px;
                background: rgba(255,255,255,.06);
            }

            .fp-sidebar-user-info {
                min-width: 0;
            }

            .fp-sidebar-user-name {
                overflow: hidden;
                display: block;
                color: #ffffff;
                font-size: 10px;
                font-weight: 700;
                white-space: nowrap;
                text-overflow: ellipsis;
            }

            .fp-sidebar-user-role {
                margin-top: 1px;
                display: block;
                color: #9f9bbb;
                font-size: 8px;
            }

            body.fp-sidebar-collapsed .fp-sidebar {
                width: var(--fp-sidebar-collapsed-width);
            }

            body.fp-sidebar-collapsed .fp-sidebar-brand-content,
            body.fp-sidebar-collapsed .fp-sidebar-section,
            body.fp-sidebar-collapsed .fp-sidebar-link-text,
            body.fp-sidebar-collapsed .fp-sidebar-arrow,
            body.fp-sidebar-collapsed .fp-sidebar-badge,
            body.fp-sidebar-collapsed .fp-sidebar-submenu,
            body.fp-sidebar-collapsed .fp-sidebar-user-info {
                display: none !important;
            }

            body.fp-sidebar-collapsed .fp-sidebar-link {
                justify-content: center;
            }

            body.fp-sidebar-collapsed .fp-sidebar-user {
                justify-content: center;
                padding: 8px;
            }

            .fp-sidebar-overlay {
                position: fixed;
                inset: 0;
                z-index: 1035;
                display: none;
                background: rgba(17, 24, 39, .45);
            }

            @media (max-width: 991.98px) {
                .fp-sidebar {
                    width: min(286px, 86vw);
                    transform: translateX(-100%);
                    box-shadow: 15px 0 40px rgba(17, 24, 39, .16);
                }

                body.fp-sidebar-mobile-open .fp-sidebar {
                    transform: translateX(0);
                }

                body.fp-sidebar-mobile-open .fp-sidebar-overlay {
                    display: block;
                }

                .fp-sidebar-close {
                    display: inline-flex;
                }
            }
        </style>
<aside class="fp-sidebar" id="fpSidebar">

    <div class="fp-sidebar-header">
        <a href="../dashboard.php" class="fp-sidebar-brand">
            <span class="fp-sidebar-logo">FP</span>
            <span class="fp-sidebar-brand-content">
                <span class="fp-sidebar-brand-name">FieldPlx</span>
                <span class="fp-sidebar-brand-label">Platform Admin</span>
            </span>
        </a>

        <button type="button" class="fp-sidebar-close" id="fpSidebarClose">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="fp-sidebar-body" id="fpSidebarBody">

        <div class="fp-sidebar-section">Overview</div>

        <nav>
            <a href="dashboard.php" class="fp-sidebar-link <?= fpActive('dashboard', $activePage); ?>">
                <span class="fp-sidebar-link-icon"><i class="bi bi-grid"></i></span>
                <span class="fp-sidebar-link-text">Dashboard</span>
            </a>

            <div class="fp-sidebar-section">Platform</div>

            <div class="fp-sidebar-menu <?= fpMenuOpen($tenantMenuPages, $activePage); ?>">
                <button type="button" class="fp-sidebar-link fp-sidebar-menu-toggle <?= in_array($activePage, $tenantMenuPages, true) ? 'active' : ''; ?>">
                    <span class="fp-sidebar-link-icon"><i class="bi bi-buildings"></i></span>
                    <span class="fp-sidebar-link-text">Tenants</span>
                    <span class="fp-sidebar-badge">128</span>
                    <span class="fp-sidebar-arrow"><i class="bi bi-chevron-down"></i></span>
                </button>

                <div class="fp-sidebar-submenu">
                    <a href="tenants.php" class="fp-sidebar-sublink <?= fpActive('tenants', $activePage); ?>">All Tenants</a>
                    <a href="tenant-add.php" class="fp-sidebar-sublink <?= fpActive('tenant-add', $activePage); ?>">Add Tenant</a>
          
                </div>
            </div>

            <div class="fp-sidebar-menu">
                <button type="button" class="fp-sidebar-link fp-sidebar-menu-toggle">
                    <span class="fp-sidebar-link-icon"><i class="bi bi-credit-card"></i></span>
                    <span class="fp-sidebar-link-text">Plans & Billing</span>
                    <span class="fp-sidebar-arrow"><i class="bi bi-chevron-down"></i></span>
                </button>

                <div class="fp-sidebar-submenu">
                    <a href="#" class="fp-sidebar-sublink">Plans</a>
                    <a href="#" class="fp-sidebar-sublink">Subscriptions</a>
                    <a href="#" class="fp-sidebar-sublink">Payments</a>
                    <a href="#" class="fp-sidebar-sublink">Renewals</a>
                </div>
            </div>

            <div class="fp-sidebar-menu">
                <button type="button" class="fp-sidebar-link fp-sidebar-menu-toggle">
                    <span class="fp-sidebar-link-icon"><i class="bi bi-grid-1x2"></i></span>
                    <span class="fp-sidebar-link-text">Modules</span>
                    <span class="fp-sidebar-arrow"><i class="bi bi-chevron-down"></i></span>
                </button>

                <div class="fp-sidebar-submenu">
                    <a href="sidebar-modules.php" class="fp-sidebar-sublink">Sidebar Modules</a>
                    <a href="#" class="fp-sidebar-sublink">Module Features</a>
                    <a href="#" class="fp-sidebar-sublink">Icon Settings</a>
                </div>
            </div>

            <a href="#" class="fp-sidebar-link">
                <span class="fp-sidebar-link-icon"><i class="bi bi-people"></i></span>
                <span class="fp-sidebar-link-text">Platform Users</span>
            </a>

            <div class="fp-sidebar-section">Management</div>

            <a href="#" class="fp-sidebar-link">
                <span class="fp-sidebar-link-icon"><i class="bi bi-bar-chart"></i></span>
                <span class="fp-sidebar-link-text">Reports</span>
            </a>

            <a href="#" class="fp-sidebar-link">
                <span class="fp-sidebar-link-icon"><i class="bi bi-activity"></i></span>
                <span class="fp-sidebar-link-text">Activity Logs</span>
            </a>

            <a href="email-smtp.php" class="fp-sidebar-link">
                <span class="fp-sidebar-link-icon"><i class="bi bi-envelope"></i></span>
                <span class="fp-sidebar-link-text">Email & SMTP</span>
            </a>

            <a href="#" class="fp-sidebar-link">
                <span class="fp-sidebar-link-icon"><i class="bi bi-bell"></i></span>
                <span class="fp-sidebar-link-text">Notifications</span>
                <span class="fp-sidebar-badge">4</span>
            </a>

            <div class="fp-sidebar-menu">
                <button type="button" class="fp-sidebar-link fp-sidebar-menu-toggle">
                    <span class="fp-sidebar-link-icon"><i class="bi bi-gear"></i></span>
                    <span class="fp-sidebar-link-text">Settings</span>
                    <span class="fp-sidebar-arrow"><i class="bi bi-chevron-down"></i></span>
                </button>

                <div class="fp-sidebar-submenu">
                    <a href="platform-settings.php" class="fp-sidebar-sublink">Platform Settings</a>
                    <a href="country-currency-master.php" class="fp-sidebar-sublink">Country & Currency Master</a>
                    
                </div>
            </div>

            
        </nav>

        

    </div>
</aside>

<div class="fp-sidebar-overlay" id="fpSidebarOverlay"></div>

<script>
(function () {
    'use strict';

    var sidebarBody =
        document.getElementById('fpSidebarBody');

    if (!sidebarBody) {
        return;
    }

    function keepActiveSidebarItemVisible() {
        var activeItem =
            sidebarBody.querySelector(
                '.fp-sidebar-sublink.active, .fp-sidebar-link.active'
            );

        if (!activeItem) {
            return;
        }

        var bodyRect =
            sidebarBody.getBoundingClientRect();

        var itemRect =
            activeItem.getBoundingClientRect();

        var topPadding = 18;
        var bottomPadding = 18;

        if (
            itemRect.top <
            bodyRect.top + topPadding
        ) {
            sidebarBody.scrollTop -=
                (
                    bodyRect.top +
                    topPadding -
                    itemRect.top
                );
        } else if (
            itemRect.bottom >
            bodyRect.bottom -
            bottomPadding
        ) {
            sidebarBody.scrollTop +=
                (
                    itemRect.bottom -
                    (
                        bodyRect.bottom -
                        bottomPadding
                    )
                );
        }
    }

    window.requestAnimationFrame(
        function () {
            window.requestAnimationFrame(
                keepActiveSidebarItemVisible
            );
        }
    );
})();
</script>
