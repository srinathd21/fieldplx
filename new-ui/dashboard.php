<?php
declare(strict_types=1);

$pageTitle = 'FieldPlx Dashboard';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Loaded here so the saved sidebar state is applied before the page renders. -->
    <script src="assets/script.js"></script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>

<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-wrapper">
    <?php require __DIR__ . '/includes/topbar.php'; ?>

    <main class="dashboard-content">
        <!-- Welcome -->
        <section class="welcome-section">
            <div>
                <h1>Welcome back, Admin! 👋</h1>
                <p>Here’s what’s happening with your job work management system.</p>
            </div>

            <div class="date-actions">
                <button class="date-button">
                    <i class="bi bi-calendar3"></i>
                    <span>May 12 – May 18, 2024</span>
                    <i class="bi bi-chevron-down"></i>
                </button>

                <button class="filter-button action-button" data-action="Dashboard filters">
                    <i class="bi bi-sliders"></i>
                </button>
            </div>
        </section>

        <!-- Statistics -->
        <section class="row g-3 mb-3">

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card stat-card">
                    <button class="card-more">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>

                    <div class="stat-row">
                        <span class="stat-icon blue">
                            <i class="bi bi-briefcase"></i>
                        </span>

                        <div>
                            <span class="stat-label">Total Jobs</span>
                            <strong class="stat-value">1,248</strong>
                            <small class="growth">
                                <strong>↑ 12.5%</strong> vs last week
                            </small>
                        </div>
                    </div>

                    <div class="sparkline">
                        <canvas class="spark-chart" data-color="#123d70"
                                data-values="20,7,16,8,19,10,26,8,18,5,28,31"></canvas>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card stat-card">
                    <button class="card-more">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>

                    <div class="stat-row">
                        <span class="stat-icon green">
                            <i class="bi bi-check-lg"></i>
                        </span>

                        <div>
                            <span class="stat-label">Completed Jobs</span>
                            <strong class="stat-value">982</strong>
                            <small class="growth">
                                <strong>↑ 15.8%</strong> vs last week
                            </small>
                        </div>
                    </div>

                    <div class="sparkline">
                        <canvas class="spark-chart" data-color="#74b824"
                                data-values="20,8,17,11,21,9,24,13,29,19,10,30"></canvas>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card stat-card">
                    <button class="card-more">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>

                    <div class="stat-row">
                        <span class="stat-icon purple">
                            <i class="bi bi-clock"></i>
                        </span>

                        <div>
                            <span class="stat-label">In Progress</span>
                            <strong class="stat-value">246</strong>
                            <small class="growth">
                                <strong>↑ 5.3%</strong> vs last week
                            </small>
                        </div>
                    </div>

                    <div class="sparkline">
                        <canvas class="spark-chart" data-color="#5d971b"
                                data-values="20,8,16,9,20,11,24,12,19,6,27,30"></canvas>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card stat-card">
                    <button class="card-more">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>

                    <div class="stat-row">
                        <span class="stat-icon orange">
                            <i class="bi bi-currency-dollar"></i>
                        </span>

                        <div>
                            <span class="stat-label">Total Revenue</span>
                            <strong class="stat-value">$248,650</strong>
                            <small class="growth">
                                <strong>↑ 18.6%</strong> vs last week
                            </small>
                        </div>
                    </div>

                    <div class="sparkline">
                        <canvas class="spark-chart" data-color="#96c945"
                                data-values="22,8,18,9,14,6,23,17,5,20,9,24"></canvas>
                    </div>
                </article>
            </div>
        </section>

        <!-- Charts and today's tasks -->
        <section class="row g-3 mb-3">

            <div class="col-xl-6">
                <article class="dashboard-card panel chart-card">
                    <div class="panel-title">
                        <h2>Jobs Overview</h2>

                        <div class="small text-secondary">
                            <span class="me-3">
                                <i class="bi bi-square-fill text-primary me-1"></i>
                                Total Jobs
                            </span>

                            <span>
                                <i class="bi bi-square-fill text-success me-1"></i>
                                Completed Jobs
                            </span>
                        </div>
                    </div>

                    <div class="chart-area">
                        <canvas id="jobsChart"></canvas>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card panel chart-card">
                    <div class="panel-title">
                        <h2>Jobs by Status</h2>
                    </div>

                    <div class="status-wrapper">
                        <div class="donut">
                            <div class="donut-center">
                                <strong>1,248</strong>
                                <small>Total</small>
                            </div>
                        </div>

                        <div class="status-legend">
                            <div class="legend-row">
                                <span class="legend-dot" style="background:#a7cf5b"></span>
                                <span>Pending<br><strong>320</strong> (25.6%)</span>
                            </div>

                            <div class="legend-row">
                                <span class="legend-dot" style="background:#123d70"></span>
                                <span>In Progress<br><strong>246</strong> (19.7%)</span>
                            </div>

                            <div class="legend-row">
                                <span class="legend-dot" style="background:#74b824"></span>
                                <span>Completed<br><strong>982</strong> (78.7%)</span>
                            </div>

                            <div class="legend-row">
                                <span class="legend-dot" style="background:#e45b66"></span>
                                <span>Cancelled<br><strong>18</strong> (1.4%)</span>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card panel chart-card today-tasks-card">
                    <div class="panel-title">
                        <h2>Today's Tasks</h2>
                        <span class="tasks-count">5 Tasks</span>
                    </div>

                    <div class="today-task-list">
                        <label class="today-task-item is-complete">
                            <input class="form-check-input today-task-check" type="checkbox" checked>
                            <span class="today-task-content">
                                <strong>Confirm irrigation team</strong>
                                <small>Green Fields Agro</small>
                            </span>
                            <span class="today-task-time">9:00 AM</span>
                        </label>

                        <label class="today-task-item">
                            <input class="form-check-input today-task-check" type="checkbox">
                            <span class="today-task-content">
                                <strong>Review drone spraying job</strong>
                                <small>Krishi Farm House</small>
                            </span>
                            <span class="today-task-time">11:30 AM</span>
                        </label>

                        <label class="today-task-item">
                            <input class="form-check-input today-task-check" type="checkbox">
                            <span class="today-task-content">
                                <strong>Approve soil testing report</strong>
                                <small>Agri World</small>
                            </span>
                            <span class="today-task-time">2:00 PM</span>
                        </label>

                        <label class="today-task-item">
                            <input class="form-check-input today-task-check" type="checkbox">
                            <span class="today-task-content">
                                <strong>Call worker about harvesting</strong>
                                <small>Golden Harvest Ltd.</small>
                            </span>
                            <span class="today-task-time">4:30 PM</span>
                        </label>

                        <label class="today-task-item">
                            <input class="form-check-input today-task-check" type="checkbox">
                            <span class="today-task-content">
                                <strong>Send pending invoice reminder</strong>
                                <small>Accounts follow-up</small>
                            </span>
                            <span class="today-task-time">5:30 PM</span>
                        </label>
                    </div>

                    <div class="today-task-footer">
                        <button class="purple-link action-button" data-action="All Tasks">View all tasks →</button>
                    </div>
                </article>
            </div>
        </section>

        <!-- Jobs, schedule and activity -->
        <section class="row g-3 mb-3">

            <div class="col-xl-9">
                <article class="dashboard-card panel recent-jobs-card">
                    <div class="panel-title">
                        <h2>Recent Jobs</h2>

                        <button class="view-button action-button" data-action="View All Jobs">
                            View All Jobs
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table jobs-table">
                            <thead>
                            <tr>
                                <th>Job ID</th>
                                <th>Job Name</th>
                                <th>Client</th>
                                <th>Service</th>
                                <th>Worker</th>
                                <th>Status</th>
                                <th>Due Date</th>
                                <th>Actions</th>
                            </tr>
                            </thead>

                            <tbody>
                            <tr>
                                <td>JOB-1248</td>
                                <td>Farm Irrigation Setup</td>
                                <td>Green Fields Agro</td>
                                <td>Irrigation Installation</td>
                                <td>Ramesh Kumar</td>
                                <td><span class="status-badge status-progress">In Progress</span></td>
                                <td>May 20, 2024</td>
                                <td><button class="card-more position-static"><i class="bi bi-three-dots-vertical"></i></button></td>
                            </tr>

                            <tr>
                                <td>JOB-1247</td>
                                <td>Drone Spraying</td>
                                <td>Krishi Farm House</td>
                                <td>Drone Spraying</td>
                                <td>Suresh Patel</td>
                                <td><span class="status-badge status-completed">Completed</span></td>
                                <td>May 18, 2024</td>
                                <td><button class="card-more position-static"><i class="bi bi-three-dots-vertical"></i></button></td>
                            </tr>

                            <tr>
                                <td>JOB-1246</td>
                                <td>Soil Testing</td>
                                <td>Agri World</td>
                                <td>Soil Testing</td>
                                <td>Mahesh Yadav</td>
                                <td><span class="status-badge status-pending">Pending</span></td>
                                <td>May 21, 2024</td>
                                <td><button class="card-more position-static"><i class="bi bi-three-dots-vertical"></i></button></td>
                            </tr>

                            <tr>
                                <td>JOB-1245</td>
                                <td>Harvesting Service</td>
                                <td>Golden Harvest Ltd.</td>
                                <td>Harvesting</td>
                                <td>Vikram Singh</td>
                                <td><span class="status-badge status-progress">In Progress</span></td>
                                <td>May 22, 2024</td>
                                <td><button class="card-more position-static"><i class="bi bi-three-dots-vertical"></i></button></td>
                            </tr>

                            <tr>
                                <td>JOB-1244</td>
                                <td>Land Leveling</td>
                                <td>Nature Farms</td>
                                <td>Land Leveling</td>
                                <td>Ramesh Kumar</td>
                                <td><span class="status-badge status-completed">Completed</span></td>
                                <td>May 17, 2024</td>
                                <td><button class="card-more position-static"><i class="bi bi-three-dots-vertical"></i></button></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-footer">
                        <span>Showing 1 to 5 of 25 jobs</span>

                        <nav aria-label="Recent jobs pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item"><a class="page-link" href="#">‹</a></li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item"><a class="page-link" href="#">2</a></li>
                                <li class="page-item"><a class="page-link" href="#">3</a></li>
                                <li class="page-item"><a class="page-link" href="#">›</a></li>
                            </ul>
                        </nav>
                    </div>
                </article>
            </div>

            <div class="col-xl-3">
                <div class="row g-3">

                    <div class="col-xl-12 col-md-6">
                        <article class="dashboard-card panel">
                            <div class="panel-title">
                                <h2>Today’s Schedule</h2>
                                <button class="purple-link">View Calendar</button>
                            </div>

                            <div class="schedule-event">
                                <span class="schedule-dot bg-primary"></span>
                                <span class="schedule-time">9:00 AM</span>
                                <span class="schedule-info">
                                    <strong>Farm Irrigation Setup</strong>
                                    <small>Green Fields Agro</small>
                                </span>
                            </div>

                            <div class="schedule-event">
                                <span class="schedule-dot bg-success"></span>
                                <span class="schedule-time">11:30 AM</span>
                                <span class="schedule-info">
                                    <strong>Drone Spraying</strong>
                                    <small>Krishi Farm House</small>
                                </span>
                            </div>

                            <div class="schedule-event">
                                <span class="schedule-dot bg-warning"></span>
                                <span class="schedule-time">2:00 PM</span>
                                <span class="schedule-info">
                                    <strong>Soil Testing</strong>
                                    <small>Agri World</small>
                                </span>
                            </div>

                            <div class="schedule-event">
                                <span class="schedule-dot" style="background:#5d971b"></span>
                                <span class="schedule-time">4:30 PM</span>
                                <span class="schedule-info">
                                    <strong>Harvesting Service</strong>
                                    <small>Golden Harvest Ltd.</small>
                                </span>
                            </div>

                            <button class="purple-link action-button" data-action="Full Schedule">
                                View full schedule →
                            </button>
                        </article>
                    </div>

                    <div class="col-xl-12 col-md-6">
                        <article class="dashboard-card panel">
                            <div class="panel-title">
                                <h2>Recent Activity</h2>
                            </div>

                            <div class="activity-item">
                                <span class="activity-icon bg-soft-green">
                                    <i class="bi bi-check-lg"></i>
                                </span>

                                <span class="activity-content">
                                    <strong>Job JOB-1247 completed</strong>
                                    <small>by Suresh Patel · 2 min ago</small>
                                </span>
                            </div>

                            <div class="activity-item">
                                <span class="activity-icon bg-soft-orange">
                                    <i class="bi bi-credit-card"></i>
                                </span>

                                <span class="activity-content">
                                    <strong>Payment received</strong>
                                    <small>$18,500 from Krishi Farm House · 15 min ago</small>
                                </span>
                            </div>

                            <div class="activity-item">
                                <span class="activity-icon bg-soft-purple">
                                    <i class="bi bi-briefcase"></i>
                                </span>

                                <span class="activity-content">
                                    <strong>New job created</strong>
                                    <small>Land Leveling · Nature Farms · 1 hour ago</small>
                                </span>
                            </div>

                            <button class="purple-link action-button" data-action="All Activity">
                                View all activity →
                            </button>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <!-- Bottom statistics -->
        <section class="row g-3">

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card bottom-card">
                    <span class="bottom-card-icon" style="color:#123d70;background:#edf2f7">
                        <i class="bi bi-people"></i>
                    </span>

                    <div class="bottom-card-content">
                        <small>Total Clients</small>
                        <strong>356</strong>
                        <span class="text-growth">↑ 8.3% vs last week</span>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card bottom-card">
                    <span class="bottom-card-icon" style="color:#74b824;background:#f0f8e5">
                        <i class="bi bi-person-badge"></i>
                    </span>

                    <div class="bottom-card-content">
                        <small>Total Workers</small>
                        <strong>128</strong>
                        <span class="text-growth">↑ 6.7% vs last week</span>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card bottom-card">
                    <span class="bottom-card-icon" style="color:#5d971b;background:#eef7df">
                        <i class="bi bi-receipt"></i>
                    </span>

                    <div class="bottom-card-content">
                        <small>Pending Invoices</small>
                        <strong>23</strong>
                        <span>$24,500</span>
                    </div>
                </article>
            </div>

            <div class="col-xl-3 col-md-6">
                <article class="dashboard-card bottom-card">
                    <span class="bottom-card-icon" style="color:#96c945;background:#f4f9ea">
                        <i class="bi bi-credit-card"></i>
                    </span>

                    <div class="bottom-card-content">
                        <small>Pending Payments</small>
                        <strong>18</strong>
                        <span>$13,250</span>
                    </div>
                </article>
            </div>
        </section>
    

<?php require __DIR__ . '/includes/footer.php'; ?>
