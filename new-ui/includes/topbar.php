<header class="topbar">

        <button class="icon-button" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="false">
            <i class="bi bi-list fs-5"></i>
        </button>

        <div class="topbar-actions">

            <label class="search-box">
                <i class="bi bi-search"></i>
                <input type="search" placeholder="Search anything...">
            </label>

            <!-- Notifications -->
            <div class="dropdown">
                <button
                    class="icon-button notification-button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    aria-label="Notifications"
                >
                    <i class="bi bi-bell fs-5"></i>
                    <span class="notification-count">3</span>
                </button>

                <div class="dropdown-menu dropdown-menu-end notification-dropdown">
                    <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                        <strong>Notifications</strong>
                        <button class="btn btn-sm text-primary p-0">Mark all read</button>
                    </div>

                    <div class="notification-item">
                        <i class="bi bi-circle-fill text-primary"></i>
                        <span>New job JOB-1249 was assigned.</span>
                    </div>

                    <div class="notification-item">
                        <i class="bi bi-circle-fill text-success"></i>
                        <span>Payment of $18,500 received.</span>
                    </div>

                    <div class="notification-item">
                        <i class="bi bi-circle-fill text-warning"></i>
                        <span>Invoice #INV-209 is due today.</span>
                    </div>

                    <button class="dropdown-item text-center text-primary py-2">
                        View all notifications
                    </button>
                </div>
            </div>

            <!-- Profile -->
            <div class="dropdown">
                <button
                    class="top-profile"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                >
                    <span class="avatar">AU</span>

                    <span class="top-profile-text">
                        <strong>Admin User</strong>
                        <small>Super Admin</small>
                    </span>

                    <i class="bi bi-chevron-down"></i>
                </button>

                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item rounded py-2" href="#">
                            <i class="bi bi-person me-2"></i>My Profile
                        </a>
                    </li>

                    <li>
                        <a class="dropdown-item rounded py-2" href="#">
                            <i class="bi bi-gear me-2"></i>Settings
                        </a>
                    </li>

                    <li><hr class="dropdown-divider"></li>

                    <li>
                        <a class="dropdown-item rounded py-2 text-danger" href="#">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </header>
