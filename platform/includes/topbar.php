<header class="fp-topbar">
                <div class="fp-topbar-inner">

                    <button type="button" class="fp-menu-toggle" id="fpSidebarToggle" aria-label="Toggle sidebar">
                        <i class="bi bi-list"></i>
                    </button>

                    <a href="../dashboard.php" class="fp-mobile-brand">
                        <span class="fp-mobile-brand-logo">FP</span>
                        <span>FieldPlx</span>
                    </a>

                    <div class="fp-page-heading">
                        <h1 class="fp-page-title"><?= htmlspecialchars($pageTitle); ?></h1>
                        <div class="fp-page-subtitle">FieldPlx Platform Administration</div>
                    </div>

                    <div class="fp-search">
                        <i class="bi bi-search"></i>
                        <input type="search" class="form-control" placeholder="Search tenants, users, plans...">
                    </div>

                    <div class="dropdown fp-notification-wrap">
                        <button type="button" class="fp-icon-button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bell"></i>
                        </button>

                        <span class="fp-notification-count">4</span>

                        <div class="dropdown-menu dropdown-menu-end p-0 shadow border-0"
                            style="width:340px;border-radius:13px;overflow:hidden;">
                            <div class="px-3 py-3 border-bottom d-flex align-items-center justify-content-between">
                                <strong style="font-size:13px;">Notifications</strong>
                                <span class="badge text-bg-light">4 new</span>
                            </div>

                            <a href="#" class="dropdown-item py-3 border-bottom">
                                <div class="d-flex gap-2">
                                    <span class="fp-avatar"
                                        style="width:34px;height:34px;flex-basis:34px;background:#ede9fe;color:#7c3aed;">
                                        <i class="bi bi-building"></i>
                                    </span>
                                    <span>
                                        <strong class="d-block" style="font-size:11px;">New tenant registered</strong>
                                        <small class="text-muted">Prime HVAC Services joined 8 min ago</small>
                                    </span>
                                </div>
                            </a>

                            <a href="#" class="dropdown-item py-3 border-bottom">
                                <div class="d-flex gap-2">
                                    <span class="fp-avatar"
                                        style="width:34px;height:34px;flex-basis:34px;background:#fef3c7;color:#d97706;">
                                        <i class="bi bi-clock"></i>
                                    </span>
                                    <span>
                                        <strong class="d-block" style="font-size:11px;">Subscription expiring</strong>
                                        <small class="text-muted">3 tenants expire within 7 days</small>
                                    </span>
                                </div>
                            </a>

                            <a href="#" class="dropdown-item py-3">
                                <div class="d-flex gap-2">
                                    <span class="fp-avatar"
                                        style="width:34px;height:34px;flex-basis:34px;background:#fee2e2;color:#dc2626;">
                                        <i class="bi bi-exclamation-triangle"></i>
                                    </span>
                                    <span>
                                        <strong class="d-block" style="font-size:11px;">Payment requires
                                            attention</strong>
                                        <small class="text-muted">One subscription payment failed</small>
                                    </span>
                                </div>
                            </a>
                        </div>
                    </div>

                    <div class="dropdown">
                        <button type="button" class="fp-profile" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="fp-avatar">SA</span>
                            <span class="fp-profile-text">
                                <span class="fp-profile-name">Sanjay Kumar</span>
                                <span class="fp-profile-role">Super Admin</span>
                            </span>
                            <i class="bi bi-chevron-down text-muted" style="font-size:10px;"></i>
                        </button>

                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2"
                            style="min-width:210px;border-radius:12px;">
                            <li><a class="dropdown-item py-2" href="#"><i class="bi bi-person me-2"></i>My Profile</a>
                            </li>
                            <li><a class="dropdown-item py-2" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item py-2 text-danger" href="#"><i
                                        class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>

                </div>
            </header>