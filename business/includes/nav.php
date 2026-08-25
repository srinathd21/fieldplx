<header class="fieldplx-topbar">
        <div class="fieldplx-topbar-inner">
            <button aria-label="Toggle sidebar" class="fieldplx-menu-toggle" id="sidebarToggle" type="button">
                <i class="bi bi-list"></i>
            </button>
            <a class="fieldplx-brand-mobile" href="#">

                <span class="fieldplx-brand-placeholder">F</span>
                <span class="fieldplx-brand-name">FieldPlx</span>
            </a>
            <div class="fieldplx-page-heading">
                <h1 class="fieldplx-page-title">Dashboard</h1>
                <div class="fieldplx-page-subtitle">FieldPlx</div>
            </div>
            <div class="fieldplx-search-wrap">
                <i class="bi bi-search fieldplx-search-icon"></i>
                <input aria-label="" autocomplete="off" class="form-control fieldplx-search-input"
                    id="globalSearchInput" placeholder="Search clients, jobs, invoices..." type="search" />
            </div>
            <div class="fieldplx-topbar-spacer"></div>
            <div class="dropdown">
                <button aria-expanded="false" aria-label="Notifications" class="fieldplx-topbar-action"
                    data-bs-auto-close="outside" data-bs-toggle="dropdown" title="Notifications" type="button">
                    <i class="bi bi-bell"></i>
                    <span class="fieldplx-notification-count">4</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end fieldplx-dropdown">
                    <div class="fieldplx-dropdown-header">
                        <h2 class="fieldplx-dropdown-title">Notifications</h2>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"
                            style="font-size:10px;color:#6d28d9;">Mark all read</button>
                    </div>
                    <div id="topbarNotificationList">
                        <a class="fieldplx-notification-item is-unread" href="#">
                            <span class="fieldplx-notification-icon"><i class="bi bi-briefcase"></i></span>
                            <span class="fieldplx-notification-content">
                                <span class="fieldplx-notification-title">New job assigned</span>
                                <span class="fieldplx-notification-message">JOB-1048 - Office Electrical Fit-out has
                                    been assigned to you.</span>
                                <span class="fieldplx-notification-time">5 minutes ago</span>
                            </span>
                        </a>
                        <a class="fieldplx-notification-item is-unread" href="#">
                            <span class="fieldplx-notification-icon"><i class="bi bi-credit-card"></i></span>
                            <span class="fieldplx-notification-content">
                                <span class="fieldplx-notification-title">Payment received</span>
                                <span class="fieldplx-notification-message">₹24,500 received from GreenLeaf Retail Pvt
                                    Ltd.</span>
                                <span class="fieldplx-notification-time">28 minutes ago</span>
                            </span>
                        </a>
                        <a class="fieldplx-notification-item is-unread" href="#">
                            <span class="fieldplx-notification-icon"><i class="bi bi-calendar-check"></i></span>
                            <span class="fieldplx-notification-content">
                                <span class="fieldplx-notification-title">Visit reminder</span>
                                <span class="fieldplx-notification-message">Site inspection at Orion Tech Park is
                                    scheduled for 2:30 PM.</span>
                                <span class="fieldplx-notification-time">1 hour ago</span>
                            </span>
                        </a>
                        <a class="fieldplx-notification-item is-unread" href="#">
                            <span class="fieldplx-notification-icon"><i class="bi bi-receipt"></i></span>
                            <span class="fieldplx-notification-content">
                                <span class="fieldplx-notification-title">Invoice overdue</span>
                                <span class="fieldplx-notification-message">INV-2026-0187 is overdue by 3 days. Balance
                                    due ₹18,750.</span>
                                <span class="fieldplx-notification-time">2 hours ago</span>
                            </span>
                        </a>
                    </div>
                    <div class="fieldplx-dropdown-footer">
                        <a href="#">
                            View all notifications
                        </a>
                    </div>
                </div>
            </div>
            <div class="dropdown">
                <button aria-expanded="false" class="fieldplx-profile-button" data-bs-toggle="dropdown" type="button">
                    <span class="fieldplx-avatar">DU</span>
                    <span class="fieldplx-profile-details">
                        <span class="fieldplx-profile-name d-block">Demo User</span>
                        <span class="fieldplx-profile-role d-block">Administrator</span>
                    </span>
                    <i class="bi bi-chevron-down" style="font-size:10px;color:#9ca3af;"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end fieldplx-profile-menu">
                    <div class="fieldplx-profile-menu-header">
                        <div class="fieldplx-profile-menu-name">Demo User</div>
                        <div class="fieldplx-profile-menu-email">demo@example.com</div>
                    </div>
                    <a class="dropdown-item mt-1" href="#">
                        <i class="bi bi-person"></i>
                        My Profile
                    </a>
                    <a class="dropdown-item" href="#">
                        <i class="bi bi-gear"></i>
                        Settings
                    </a>
                    <div class="dropdown-divider my-1"></div>
                    <a class="dropdown-item text-danger" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>