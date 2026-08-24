<?php
$pageTitle = 'Platform Dashboard';
$activePage = 'dashboard';


?>
<?php
$pageTitle = isset($pageTitle) ? $pageTitle : 'Dashboard';
$activePage = isset($activePage) ? $activePage : 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle); ?> - FieldPlx</title>

    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <style>
        :root {
            --fp-primary: #12182d;
            --fp-primary-2: #1c2250;
            --fp-primary-3: #201f6b;
            --fp-accent: #8b5cf6;
            --fp-accent-light: #a78bfa;
            --fp-accent-dark: #6d28d9;
            --fp-text: #20213f;
            --fp-muted: #6f6b8f;
            --fp-border: #ded9ef;
            --fp-bg: #f1edff;
            --fp-surface: #ffffff;
            --fp-surface-soft: #f8f6ff;
            --fp-success: #059669;
            --fp-warning: #d97706;
            --fp-danger: #dc2626;
            --fp-info: #6366f1;
            --fp-sidebar-width: 260px;
            --fp-sidebar-collapsed-width: 76px;
            --fp-topbar-height: 66px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background: #ffffff;
            color: var(--fp-text);
            font-family: "Inter", sans-serif;
            font-size: 13px;
        }

        a {
            text-decoration: none;
        }

        .fp-layout {
            min-height: 100vh;
        }

        .fp-main {
            min-height: calc(100vh - 52px);
            margin-left: var(--fp-sidebar-width);
            transition: margin-left .22s ease;
        }

        body.fp-sidebar-collapsed .fp-main {
            margin-left: var(--fp-sidebar-collapsed-width);
        }

        .fp-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            min-height: var(--fp-topbar-height);
            border-bottom: 1px solid #ded8f3;
            background: rgba(248, 246, 255, .96);
            backdrop-filter: blur(14px);
        }

        .fp-topbar-inner {
            min-height: var(--fp-topbar-height);
            padding: 8px 18px;
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .fp-menu-toggle,
        .fp-icon-button {
            width: 39px;
            height: 39px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d9d2ef;
            border-radius: 10px;
            background: #ffffff;
            color: #39345f;
            font-size: 18px;
            transition: .16s ease;
        }

        .fp-menu-toggle:hover,
        .fp-icon-button:hover {
            border-color: #bda9ff;
            background: #f4f0ff;
            color: var(--fp-accent-dark);
        }

        .fp-page-heading {
            min-width: 0;
            margin-right: auto;
        }

        .fp-page-title {
            margin: 0;
            overflow: hidden;
            color: #17172e;
            font-size: 15px;
            font-weight: 700;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fp-page-subtitle {
            margin-top: 2px;
            color: var(--fp-muted);
            font-size: 10px;
        }

        .fp-search {
            width: min(340px, 31vw);
            position: relative;
        }

        .fp-search i {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(-50%);
            color: #8f88aa;
            font-size: 14px;
        }

        .fp-search input {
            height: 39px;
            padding: 8px 13px 8px 36px;
            border: 1px solid #dcd5ef;
            border-radius: 10px;
            background: #f8f6ff;
            box-shadow: none;
            font-size: 12px;
        }

        .fp-search input:focus {
            border-color: #a78bfa;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, .12);
        }

        .fp-notification-wrap {
            position: relative;
        }

        .fp-notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            border-radius: 999px;
            background: var(--fp-danger);
            color: #fff;
            font-size: 9px;
            font-weight: 700;
        }

        .fp-profile {
            min-width: 0;
            padding: 4px 9px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--fp-border);
            border-radius: 11px;
            background: #fff;
        }

        .fp-avatar {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: linear-gradient(135deg, #6d4df4, #9a5cff);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
        }

        .fp-profile-text {
            max-width: 145px;
            min-width: 0;
        }

        .fp-profile-name,
        .fp-profile-role {
            overflow: hidden;
            display: block;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fp-profile-name {
            color: #111827;
            font-size: 11px;
            font-weight: 700;
        }

        .fp-profile-role {
            margin-top: 1px;
            color: var(--fp-muted);
            font-size: 9px;
        }

        .fp-content {
            padding: 18px;
            background: #ffffff;
        }

        .fp-mobile-brand {
            display: none;
        }

        @media (max-width: 991.98px) {

            .fp-main,
            body.fp-sidebar-collapsed .fp-main {
                margin-left: 0;
            }

            .fp-search {
                display: none;
            }

            .fp-profile-text {
                display: none;
            }

            .fp-mobile-brand {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: #ffffff;
                font-weight: 700;
            }

            .fp-mobile-brand-logo {
                width: 34px;
                height: 34px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 9px;
                background: linear-gradient(135deg, #6d4df4, #9a5cff);
                color: #fff;
                font-size: 13px;
            }
        }

        @media (max-width: 575.98px) {
            .fp-topbar-inner {
                padding: 8px 11px;
            }

            .fp-page-subtitle {
                display: none;
            }

            .fp-page-title {
                font-size: 13px;
            }

            .fp-content {
                padding: 12px;
            }
        }
    </style>

    <style>
        .dashboard-page {
            display: grid;
            gap: 18px;
        }

        .dashboard-welcome {
            padding: 24px 26px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            border: 1px solid rgba(139, 92, 246, .24);
            border-radius: 16px;
            background:
                radial-gradient(circle at 96% 0%, rgba(255, 255, 255, .08), transparent 28%),
                linear-gradient(135deg, #12182d 0%, #20255a 58%, #35317b 100%);
            box-shadow: 0 12px 34px rgba(36, 29, 82, .10);
        }

        .dashboard-welcome h2 {
            margin: 0 0 7px;
            color: #ffffff;
            font-size: 20px;
            font-weight: 800;
        }

        .dashboard-welcome p {
            max-width: 650px;
            margin: 0;
            color: #d9d7ef;
            font-size: 11px;
            line-height: 1.65;
        }

        .dashboard-date {
            min-width: 170px;
            padding: 12px 14px;
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 12px;
            background: rgba(255, 255, 255, .08);
            color: #d8d5f0;
            text-align: center;
            font-size: 10px;
        }

        .dashboard-date strong {
            margin-top: 3px;
            display: block;
            color: #ffffff;
            font-size: 13px;
        }

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .dashboard-stat {
            min-width: 0;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 13px;
            border: 1px solid #ddd5f1;
            border-radius: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #fbf9ff 100%);
            box-shadow: 0 8px 24px rgba(39, 31, 84, .055);
        }

        .dashboard-stat-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 18px;
        }

        .dashboard-stat-icon.purple {
            background: #ede7ff;
            color: #7c3aed;
        }

        .dashboard-stat-icon.green {
            background: #eee9ff;
            color: #6d4df4;
        }

        .dashboard-stat-icon.orange {
            background: #f2eaff;
            color: #8b5cf6;
        }

        .dashboard-stat-icon.blue {
            background: #e8e7ff;
            color: #5757d9;
        }

        .dashboard-stat-icon.red {
            background: #f0e8ff;
            color: #7c3aed;
        }

        .dashboard-stat-icon.cyan {
            background: #e9e8ff;
            color: #5956c8;
        }

        .dashboard-stat-icon.indigo {
            background: #e7e3ff;
            color: #5b50c8;
        }

        .dashboard-stat-icon.gray {
            background: #eeeaf8;
            color: #615a7d;
        }

        .dashboard-stat-label {
            color: #9ca3af;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .dashboard-stat-value {
            margin-top: 3px;
            color: #111827;
            font-size: 22px;
            font-weight: 800;
        }

        .dashboard-stat-meta {
            margin-top: 2px;
            color: #6b7280;
            font-size: 9px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(300px, .85fr);
            gap: 16px;
        }

        .dashboard-card {
            overflow: hidden;
            border: 1px solid #ded7ef;
            border-radius: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfaff 100%);
            box-shadow: 0 10px 28px rgba(37, 29, 80, .06);
        }

        .dashboard-card-header {
            min-height: 52px;
            padding: 12px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid #ece7f7;
            background: #fbf9ff;
        }

        .dashboard-card-title {
            margin: 0;
            color: #111827;
            font-size: 12px;
            font-weight: 700;
        }

        .dashboard-card-subtitle {
            margin-top: 2px;
            color: #9ca3af;
            font-size: 9px;
        }

        .dashboard-card-link {
            color: #7c3aed;
            font-size: 9px;
            font-weight: 600;
        }

        .dashboard-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .dashboard-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
            white-space: nowrap;
        }

        .dashboard-table th {
            padding: 10px 13px;
            border-bottom: 1px solid #eceef2;
            background: #f6f2ff;
            color: #847d9e;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .dashboard-table td {
            padding: 11px 13px;
            border-bottom: 1px solid #f2f3f5;
            color: #4b5563;
            font-size: 10px;
            vertical-align: middle;
        }

        .dashboard-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .tenant-name {
            color: #111827;
            font-weight: 700;
        }

        .tenant-code {
            margin-top: 2px;
            color: #9ca3af;
            font-size: 8px;
        }

        .status-badge {
            min-height: 22px;
            padding: 4px 8px;
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 700;
        }

        .status-active {
            background: #d1fae5;
            color: #047857;
        }

        .status-trial {
            background: #fef3c7;
            color: #b45309;
        }

        .status-suspended {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-pending {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .activity-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .activity-item {
            padding: 13px 15px;
            display: flex;
            gap: 11px;
            border-bottom: 1px solid #f1f2f4;
        }

        .activity-item:last-child {
            border-bottom: 0;
        }

        .activity-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #eee8ff;
            color: #7c3aed;
            font-size: 14px;
        }

        .activity-title {
            color: #111827;
            font-size: 10px;
            font-weight: 700;
        }

        .activity-text {
            margin-top: 2px;
            color: #6b7280;
            font-size: 9px;
            line-height: 1.45;
        }

        .activity-time {
            margin-top: 3px;
            color: #9ca3af;
            font-size: 8px;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px;
            padding: 14px;
        }

        .quick-action {
            min-height: 82px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: 1px solid #e0d8f2;
            border-radius: 11px;
            background: #fbf9ff;
            color: #4e486a;
            transition: .15s ease;
        }

        .quick-action:hover {
            border-color: #bca7ff;
            background: #f1ebff;
            color: #6d28d9;
            transform: translateY(-1px);
        }

        .quick-action i {
            font-size: 18px;
        }

        .quick-action span {
            font-size: 9px;
            font-weight: 700;
        }

        .subscription-progress {
            padding: 15px;
        }

        .subscription-row {
            margin-bottom: 14px;
        }

        .subscription-row:last-child {
            margin-bottom: 0;
        }

        .subscription-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 9px;
        }

        .subscription-line strong {
            color: #111827;
            font-size: 10px;
        }

        .progress {
            height: 7px;
            margin-top: 7px;
            background: #f0f1f3;
            border-radius: 999px;
        }

        .progress-bar {
            border-radius: 999px;
        }

        @media (max-width: 1199px) {
            .dashboard-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 991px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .dashboard-welcome {
                align-items: flex-start;
                flex-direction: column;
            }

            .dashboard-date {
                width: 100%;
                text-align: left;
            }

            .dashboard-stats {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <div class="fp-layout">

        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>


        <main class="fp-main">

            <?php require_once __DIR__ . '/includes/topbar.php'; ?>

            <div class="fp-content">


                <div class="dashboard-page">

                    <section class="dashboard-welcome">
                        <div>
                            <h2>Welcome back, Sanjay</h2>
                            <p>
                                Here is a sample overview of the FieldPlx platform. All numbers and records on this page
                                are static sample data for frontend design only.
                            </p>
                        </div>

                        <div class="dashboard-date">
                            Saturday
                            <strong>22 Aug 2026</strong>
                        </div>
                    </section>

                    <section class="dashboard-stats">

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon purple"><i class="bi bi-buildings"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Total Tenants</span>
                                <span class="dashboard-stat-value">128</span>
                                <span class="dashboard-stat-meta">+8 this month</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon green"><i class="bi bi-check-circle"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Active Tenants</span>
                                <span class="dashboard-stat-value">96</span>
                                <span class="dashboard-stat-meta">75% of all tenants</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon orange"><i class="bi bi-hourglass-split"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Trial Tenants</span>
                                <span class="dashboard-stat-value">18</span>
                                <span class="dashboard-stat-meta">6 expire this week</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon blue"><i class="bi bi-credit-card"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Subscriptions</span>
                                <span class="dashboard-stat-value">112</span>
                                <span class="dashboard-stat-meta">108 currently active</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon cyan"><i class="bi bi-people"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Platform Users</span>
                                <span class="dashboard-stat-value">12</span>
                                <span class="dashboard-stat-meta">10 active administrators</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon indigo"><i class="bi bi-person-workspace"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Tenant Users</span>
                                <span class="dashboard-stat-value">2,846</span>
                                <span class="dashboard-stat-meta">Across all workspaces</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon red"><i class="bi bi-exclamation-circle"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Needs Attention</span>
                                <span class="dashboard-stat-value">7</span>
                                <span class="dashboard-stat-meta">Payments / expiries</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon gray"><i class="bi bi-currency-dollar"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Monthly Revenue</span>
                                <span class="dashboard-stat-value">$18.4K</span>
                                <span class="dashboard-stat-meta">Sample platform billing</span>
                            </span>
                        </article>

                    </section>

                    <section class="dashboard-grid">

                        <div class="dashboard-card">
                            <div class="dashboard-card-header">
                                <div>
                                    <h3 class="dashboard-card-title">Recent Tenants</h3>
                                    <div class="dashboard-card-subtitle">Latest registered businesses</div>
                                </div>
                                <a href="#" class="dashboard-card-link">View all</a>
                            </div>

                            <div class="dashboard-table-wrap">
                                <table class="dashboard-table">
                                    <thead>
                                        <tr>
                                            <th>Business</th>
                                            <th>Plan</th>
                                            <th>Country</th>
                                            <th>Users</th>
                                            <th>Status</th>
                                            <th>Joined</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>
                                                <div class="tenant-name">Prime HVAC Services</div>
                                                <div class="tenant-code">TNT-00128</div>
                                            </td>
                                            <td>Professional</td>
                                            <td>India</td>
                                            <td>18</td>
                                            <td><span class="status-badge status-active">Active</span></td>
                                            <td>22 Aug 2026</td>
                                        </tr>

                                        <tr>
                                            <td>
                                                <div class="tenant-name">Urban Fix Solutions</div>
                                                <div class="tenant-code">TNT-00127</div>
                                            </td>
                                            <td>Starter</td>
                                            <td>UAE</td>
                                            <td>5</td>
                                            <td><span class="status-badge status-trial">Trial</span></td>
                                            <td>21 Aug 2026</td>
                                        </tr>

                                        <tr>
                                            <td>
                                                <div class="tenant-name">BlueLine Electrical</div>
                                                <div class="tenant-code">TNT-00126</div>
                                            </td>
                                            <td>Enterprise</td>
                                            <td>United Kingdom</td>
                                            <td>64</td>
                                            <td><span class="status-badge status-active">Active</span></td>
                                            <td>20 Aug 2026</td>
                                        </tr>

                                        <tr>
                                            <td>
                                                <div class="tenant-name">GreenField Maintenance</div>
                                                <div class="tenant-code">TNT-00125</div>
                                            </td>
                                            <td>Professional</td>
                                            <td>Australia</td>
                                            <td>22</td>
                                            <td><span class="status-badge status-pending">Pending</span></td>
                                            <td>19 Aug 2026</td>
                                        </tr>

                                        <tr>
                                            <td>
                                                <div class="tenant-name">Rapid Plumbing Co.</div>
                                                <div class="tenant-code">TNT-00124</div>
                                            </td>
                                            <td>Starter</td>
                                            <td>Canada</td>
                                            <td>4</td>
                                            <td><span class="status-badge status-suspended">Suspended</span></td>
                                            <td>18 Aug 2026</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="dashboard-card">
                            <div class="dashboard-card-header">
                                <div>
                                    <h3 class="dashboard-card-title">Recent Activity</h3>
                                    <div class="dashboard-card-subtitle">Sample platform events</div>
                                </div>
                                <a href="#" class="dashboard-card-link">View log</a>
                            </div>

                            <ul class="activity-list">
                                <li class="activity-item">
                                    <span class="activity-icon"><i class="bi bi-building-add"></i></span>
                                    <span>
                                        <span class="activity-title">New tenant registered</span>
                                        <span class="activity-text">Prime HVAC Services created a Professional
                                            workspace.</span>
                                        <span class="activity-time">8 minutes ago</span>
                                    </span>
                                </li>

                                <li class="activity-item">
                                    <span class="activity-icon"><i class="bi bi-arrow-up-circle"></i></span>
                                    <span>
                                        <span class="activity-title">Plan upgraded</span>
                                        <span class="activity-text">BlueLine Electrical upgraded to Enterprise.</span>
                                        <span class="activity-time">42 minutes ago</span>
                                    </span>
                                </li>

                                <li class="activity-item">
                                    <span class="activity-icon"><i class="bi bi-credit-card"></i></span>
                                    <span>
                                        <span class="activity-title">Subscription renewed</span>
                                        <span class="activity-text">ABC Facility Care renewed for another year.</span>
                                        <span class="activity-time">2 hours ago</span>
                                    </span>
                                </li>

                                <li class="activity-item">
                                    <span class="activity-icon"><i class="bi bi-person-plus"></i></span>
                                    <span>
                                        <span class="activity-title">Platform user added</span>
                                        <span class="activity-text">A new support administrator was added.</span>
                                        <span class="activity-time">Yesterday</span>
                                    </span>
                                </li>
                            </ul>
                        </div>

                    </section>

                    <section class="dashboard-grid">

                        <div class="dashboard-card">
                            <div class="dashboard-card-header">
                                <div>
                                    <h3 class="dashboard-card-title">Subscription Distribution</h3>
                                    <div class="dashboard-card-subtitle">Sample plan usage across tenants</div>
                                </div>
                            </div>

                            <div class="subscription-progress">

                                <div class="subscription-row">
                                    <div class="subscription-line">
                                        <strong>Starter</strong>
                                        <span>47 tenants · 42%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" style="width:42%;background:#8b5cf6;"></div>
                                    </div>
                                </div>

                                <div class="subscription-row">
                                    <div class="subscription-line">
                                        <strong>Professional</strong>
                                        <span>43 tenants · 38%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" style="width:38%;background:#5b5bd6;"></div>
                                    </div>
                                </div>

                                <div class="subscription-row">
                                    <div class="subscription-line">
                                        <strong>Enterprise</strong>
                                        <span>22 tenants · 20%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" style="width:20%;background:#37307a;"></div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <div class="dashboard-card">
                            <div class="dashboard-card-header">
                                <div>
                                    <h3 class="dashboard-card-title">Quick Actions</h3>
                                    <div class="dashboard-card-subtitle">Frontend sample shortcuts</div>
                                </div>
                            </div>

                            <div class="quick-actions">
                                <a href="#" class="quick-action">
                                    <i class="bi bi-building-add"></i>
                                    <span>Add Tenant</span>
                                </a>

                                <a href="#" class="quick-action">
                                    <i class="bi bi-plus-square"></i>
                                    <span>Create Plan</span>
                                </a>

                                <a href="#" class="quick-action">
                                    <i class="bi bi-person-plus"></i>
                                    <span>Add Platform User</span>
                                </a>

                                <a href="#" class="quick-action">
                                    <i class="bi bi-gear"></i>
                                    <span>Platform Settings</span>
                                </a>
                            </div>
                        </div>

                    </section>

                </div>

            </div>
        </main>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        (function () {
            'use strict';

            const body = document.body;
            const toggle = document.getElementById('fpSidebarToggle');
            const close = document.getElementById('fpSidebarClose');
            const overlay = document.getElementById('fpSidebarOverlay');

            const SIDEBAR_STORAGE_KEY = 'fieldplx_sidebar_collapsed';

            /*
            |--------------------------------------------------------------------------
            | Restore saved desktop sidebar state
            |--------------------------------------------------------------------------
            |
            | collapsed = "1"
            | expanded  = "0"
            |
            | The sidebar will keep the same state even after page refresh.
            |
            */

            function restoreSidebarState() {
                if (window.innerWidth < 992) {
                    body.classList.remove('fp-sidebar-collapsed');
                    return;
                }

                const savedState = localStorage.getItem(
                    SIDEBAR_STORAGE_KEY
                );

                if (savedState === '1') {
                    body.classList.add('fp-sidebar-collapsed');
                } else {
                    body.classList.remove('fp-sidebar-collapsed');
                }
            }

            function saveSidebarState() {
                const isCollapsed = body.classList.contains(
                    'fp-sidebar-collapsed'
                );

                localStorage.setItem(
                    SIDEBAR_STORAGE_KEY,
                    isCollapsed ? '1' : '0'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Initial sidebar state
            |--------------------------------------------------------------------------
            */

            restoreSidebarState();

            /*
            |--------------------------------------------------------------------------
            | Sidebar toggle
            |--------------------------------------------------------------------------
            */

            if (toggle) {
                toggle.addEventListener('click', function () {
                    if (window.innerWidth < 992) {
                        body.classList.toggle(
                            'fp-sidebar-mobile-open'
                        );
                        return;
                    }

                    body.classList.toggle(
                        'fp-sidebar-collapsed'
                    );

                    saveSidebarState();
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Mobile sidebar controls
            |--------------------------------------------------------------------------
            */

            if (close) {
                close.addEventListener('click', function () {
                    body.classList.remove(
                        'fp-sidebar-mobile-open'
                    );
                });
            }

            if (overlay) {
                overlay.addEventListener('click', function () {
                    body.classList.remove(
                        'fp-sidebar-mobile-open'
                    );
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Sidebar submenu toggle
            |--------------------------------------------------------------------------
            */

            document
                .querySelectorAll('.fp-sidebar-menu-toggle')
                .forEach(function (button) {
                    button.addEventListener(
                        'click',
                        function () {
                            const menu = button.closest(
                                '.fp-sidebar-menu'
                            );

                            if (menu) {
                                menu.classList.toggle('open');
                            }
                        }
                    );
                });

            /*
            |--------------------------------------------------------------------------
            | Close mobile sidebar after navigation
            |--------------------------------------------------------------------------
            */

            document
                .querySelectorAll('.fp-sidebar a')
                .forEach(function (link) {
                    link.addEventListener(
                        'click',
                        function () {
                            if (window.innerWidth < 992) {
                                body.classList.remove(
                                    'fp-sidebar-mobile-open'
                                );
                            }
                        }
                    );
                });

            /*
            |--------------------------------------------------------------------------
            | Responsive handling
            |--------------------------------------------------------------------------
            |
            | Desktop:
            |   restore saved collapsed/expanded state.
            |
            | Mobile:
            |   use overlay sidebar only.
            |
            */

            let lastDesktopState =
                window.innerWidth >= 992;

            window.addEventListener(
                'resize',
                function () {
                    const isDesktop =
                        window.innerWidth >= 992;

                    if (isDesktop !== lastDesktopState) {
                        body.classList.remove(
                            'fp-sidebar-mobile-open'
                        );

                        restoreSidebarState();

                        lastDesktopState = isDesktop;
                    }
                }
            );
        })();
    </script>

</body>

</html>