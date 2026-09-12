<?php
$currentUserName = $_SESSION['name'] ?? 'Demo User';
$currentUserEmail = $_SESSION['email'] ?? 'rubikasakthi0907@gmail.com';
$currentUserInitial = strtoupper(substr(trim($currentUserName), 0, 1)) ?: 'U';
?>

<div class="topbar">
    <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
        <i data-lucide="menu"></i>
    </button>

    <button id="mobileMenuButton" class="top-icon mobile-menu" type="button" aria-label="Open navigation">
        <i data-lucide="menu"></i>
    </button>

    <!-- Search box temporarily disabled
  <button class="command-search" type="button">
    <i data-lucide="search"></i>
    <span>Press / to search</span>
  </button>
  -->

    <!-- AI Assistant removed -->

    <button class="top-icon right-drawer-trigger" type="button" data-right-drawer="notifications"
        aria-label="Notifications" title="Notifications">
        <i data-lucide="bell"></i>
        <span class="notification-dot"></span>
    </button>

    <button class="top-icon help right-drawer-trigger" type="button" data-right-drawer="help" aria-label="Help"
        title="Help">
        <i data-lucide="circle-help"></i>
    </button>

    <div class="topbar-account-wrap">
        <button id="accountMenuToggle" class="top-icon settings" type="button" aria-label="Settings"
            aria-haspopup="true" aria-expanded="false" title="Settings">
            <i data-lucide="settings"></i>
        </button>

        <!-- Profile/D user icon removed -->

        <div id="accountDropdown" class="account-dropdown" aria-hidden="true">

            <div class="account-dropdown-user">
                <div class="account-dropdown-avatar">
                    <?= htmlspecialchars($currentUserInitial) ?>
                </div>

                <div class="account-dropdown-user-copy">
                    <strong><?= htmlspecialchars($currentUserName) ?></strong>
                    <span><?= htmlspecialchars($currentUserEmail) ?></span>
                </div>
            </div>

            <div class="account-dropdown-menu">
                <a href="settings.php" class="account-dropdown-item">
                    <span>Settings</span>
                </a>

                <a href="#" class="account-dropdown-item">
                    <span>Account and Billing</span>
                </a>

                <a href="#" class="account-dropdown-item">
                    <span>Manage Team</span>
                </a>

                <a href="#" class="account-dropdown-item">
                    <span>Product Updates</span>
                </a>

                <button type="button" id="accountDarkModeToggle" class="account-dropdown-item account-dark-row">
                    <span>Dark Mode <span aria-hidden="true">🌒</span></span>

                    <span id="accountDarkModeSwitch" class="account-dark-switch">
                        <span></span>
                    </span>
                </button>
            </div>

            <div class="account-dropdown-divider"></div>

            <a href="logout.php" class="account-dropdown-item account-dropdown-logout">
                <span>Log Out</span>
            </a>
        </div>
    </div>
</div>