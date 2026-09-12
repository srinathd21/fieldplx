<?php
/*
|--------------------------------------------------------------------------
| FieldPlx Business Dashboard
|--------------------------------------------------------------------------
|
| File:
| business/index.php
|
| Protected landing page after successful business user login.
| Uses the existing FieldPlx authentication/session context and shared
| header/footer/theme system.
|
*/

require_once 'includes/auth.php';

$pageTitle = 'Dashboard · FieldPlx';
$pageDescription = 'Business overview and daily operations';

require __DIR__ . '/includes/header.php';

/*
|--------------------------------------------------------------------------
| Current authenticated context
|--------------------------------------------------------------------------
|
| auth.php already exposes:
| $currentTenantId
| $currentTenantUserId
| $currentBranchId
| $currentTenantName
| $currentTenantUserName
| $currentTimezone
|
*/

$tenantId = (int)$currentTenantId;
$branchId = (int)$currentBranchId;
$userId   = (int)$currentTenantUserId;

$displayName = trim((string)$currentTenantUserName);
if ($displayName === '') {
    $displayName = 'User';
}

$businessName = trim((string)$currentTenantName);
if ($businessName === '') {
    $businessName = 'FieldPlx';
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function dashboardTableExists(PDO $pdo, $table)
{
    static $cache = array();

    $table = trim((string)$table);

    if ($table === '') {
        return false;
    }

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");

        $stmt->execute(array(
            ':table_name' => $table
        ));

        $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function dashboardColumnExists(PDO $pdo, $table, $column)
{
    static $cache = array();

    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");

        $stmt->execute(array(
            ':table_name' => $table,
            ':column_name' => $column
        ));

        $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function dashboardCount(PDO $pdo, $table, $tenantId, $branchId = 0, $extraWhere = '')
{
    if (!dashboardTableExists($pdo, $table)) {
        return 0;
    }

    if (!dashboardColumnExists($pdo, $table, 'tenant_id')) {
        return 0;
    }

    try {
        $sql = "SELECT COUNT(*) FROM `" . $table . "` WHERE tenant_id = :tenant_id";
        $params = array(
            ':tenant_id' => (int)$tenantId
        );

        if (
            $branchId > 0 &&
            dashboardColumnExists($pdo, $table, 'branch_id')
        ) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = (int)$branchId;
        }

        if (
            dashboardColumnExists($pdo, $table, 'deleted_at')
        ) {
            $sql .= " AND deleted_at IS NULL";
        }

        if ($extraWhere !== '') {
            $sql .= " " . $extraWhere;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dashboardMoney($amount)
{
    return number_format((float)$amount, 2);
}

/*
|--------------------------------------------------------------------------
| Dashboard summary
|--------------------------------------------------------------------------
|
| These counters intentionally fail safely when a module/table is not
| installed yet, so the dashboard still loads during staged development.
|
*/

$totalCustomers = dashboardCount(
    $pdo,
    'customers',
    $tenantId,
    $branchId
);

$totalJobs = dashboardCount(
    $pdo,
    'jobs',
    $tenantId,
    $branchId
);

$openJobs = 0;

if (dashboardTableExists($pdo, 'jobs')) {
    try {
        $statusColumn = dashboardColumnExists($pdo, 'jobs', 'status');

        if ($statusColumn) {
            $sql = "
                SELECT COUNT(*)
                FROM jobs
                WHERE tenant_id = :tenant_id
            ";

            $params = array(
                ':tenant_id' => $tenantId
            );

            if (
                $branchId > 0 &&
                dashboardColumnExists($pdo, 'jobs', 'branch_id')
            ) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branchId;
            }

            if (dashboardColumnExists($pdo, 'jobs', 'deleted_at')) {
                $sql .= " AND deleted_at IS NULL";
            }

            $sql .= "
                AND LOWER(COALESCE(status,'')) NOT IN (
                    'completed',
                    'closed',
                    'cancelled',
                    'canceled'
                )
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $openJobs = (int)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        $openJobs = 0;
    }
}

$totalInvoices = dashboardCount(
    $pdo,
    'invoices',
    $tenantId,
    $branchId
);

$outstandingAmount = 0.00;

if (dashboardTableExists($pdo, 'invoices')) {
    try {
        $amountColumn = null;

        foreach (
            array(
                'balance_due',
                'amount_due',
                'outstanding_amount',
                'balance'
            ) as $candidate
        ) {
            if (
                dashboardColumnExists(
                    $pdo,
                    'invoices',
                    $candidate
                )
            ) {
                $amountColumn = $candidate;
                break;
            }
        }

        if ($amountColumn !== null) {
            $sql = "
                SELECT COALESCE(SUM(`" . $amountColumn . "`),0)
                FROM invoices
                WHERE tenant_id = :tenant_id
            ";

            $params = array(
                ':tenant_id' => $tenantId
            );

            if (
                $branchId > 0 &&
                dashboardColumnExists(
                    $pdo,
                    'invoices',
                    'branch_id'
                )
            ) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branchId;
            }

            if (
                dashboardColumnExists(
                    $pdo,
                    'invoices',
                    'deleted_at'
                )
            ) {
                $sql .= " AND deleted_at IS NULL";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $outstandingAmount =
                (float)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        $outstandingAmount = 0.00;
    }
}

/*
|--------------------------------------------------------------------------
| Recent jobs
|--------------------------------------------------------------------------
*/

$recentJobs = array();

if (dashboardTableExists($pdo, 'jobs')) {
    try {
        $select = array('id');

        foreach (
            array(
                'job_no',
                'title',
                'status',
                'scheduled_date',
                'start_date',
                'created_at'
            ) as $column
        ) {
            if (
                dashboardColumnExists(
                    $pdo,
                    'jobs',
                    $column
                )
            ) {
                $select[] = $column;
            }
        }

        $sql = "
            SELECT " . implode(', ', $select) . "
            FROM jobs
            WHERE tenant_id = :tenant_id
        ";

        $params = array(
            ':tenant_id' => $tenantId
        );

        if (
            $branchId > 0 &&
            dashboardColumnExists(
                $pdo,
                'jobs',
                'branch_id'
            )
        ) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $branchId;
        }

        if (
            dashboardColumnExists(
                $pdo,
                'jobs',
                'deleted_at'
            )
        ) {
            $sql .= " AND deleted_at IS NULL";
        }

        if (
            dashboardColumnExists(
                $pdo,
                'jobs',
                'created_at'
            )
        ) {
            $sql .= " ORDER BY created_at DESC";
        } else {
            $sql .= " ORDER BY id DESC";
        }

        $sql .= " LIMIT 5";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $recentJobs = $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    } catch (Throwable $e) {
        $recentJobs = array();
    }
}

$todayLabel = date('l, d M Y');
?>

<style>
.business-dashboard{
    overflow-x:hidden;
}

.dashboard-hero{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:20px;
    flex-wrap:wrap;
    padding:28px;
    margin-bottom:18px;
    background:var(--card-bg);
    border:1px solid var(--card-border);
    border-radius:22px;
    box-shadow:var(--card-shadow);
}

.dashboard-eyebrow{
    margin:0 0 8px;
    color:var(--primary);
    font-size:12px;
    font-weight:var(--font-weight-bold);
    letter-spacing:.04em;
    text-transform:uppercase;
}

.dashboard-hero h1{
    margin:0;
    color:var(--text);
    font-size:30px;
    line-height:1.2;
    font-weight:var(--font-weight-bold);
}

.dashboard-hero p{
    margin:8px 0 0;
    max-width:720px;
    color:var(--muted);
    font-size:14px;
    line-height:1.6;
}

.dashboard-date{
    display:flex;
    align-items:center;
    gap:9px;
    min-height:44px;
    padding:0 14px;
    border:1px solid var(--card-border);
    border-radius:14px;
    background:color-mix(in srgb,var(--card-bg) 92%,var(--body-bg));
    color:var(--text);
    font-size:12px;
    font-weight:var(--font-weight-semibold);
}

.dashboard-date svg{
    width:17px;
    height:17px;
    color:var(--primary);
}

.dashboard-stats{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:14px;
    margin-bottom:18px;
}

.dashboard-stat{
    position:relative;
    min-width:0;
    padding:20px;
    background:var(--card-bg);
    border:1px solid var(--card-border);
    border-radius:20px;
    box-shadow:var(--card-shadow);
    overflow:hidden;
}

.dashboard-stat-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:18px;
}

.dashboard-stat-icon{
    width:42px;
    height:42px;
    display:grid;
    place-items:center;
    border-radius:13px;
    background:color-mix(in srgb,var(--primary) 10%,var(--card-bg));
    color:var(--primary);
}

.dashboard-stat-icon svg{
    width:20px;
    height:20px;
}

.dashboard-stat-label{
    color:var(--muted);
    font-size:12px;
    font-weight:var(--font-weight-semibold);
}

.dashboard-stat strong{
    display:block;
    margin-top:4px;
    color:var(--text);
    font-size:25px;
    line-height:1.15;
    font-weight:var(--font-weight-bold);
}

.dashboard-stat small{
    display:block;
    margin-top:7px;
    color:var(--muted);
    font-size:11px;
    line-height:1.4;
}

.dashboard-grid{
    display:grid;
    grid-template-columns:minmax(0,1.65fr) minmax(300px,.85fr);
    gap:16px;
    align-items:start;
}

.dashboard-card{
    min-width:0;
    background:var(--card-bg);
    border:1px solid var(--card-border);
    border-radius:22px;
    box-shadow:var(--card-shadow);
    overflow:hidden;
}

.dashboard-card-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    padding:22px 24px 16px;
}

.dashboard-card-head h2{
    margin:0;
    color:var(--text);
    font-size:18px;
    font-weight:var(--font-weight-bold);
}

.dashboard-card-head p{
    margin:5px 0 0;
    color:var(--muted);
    font-size:12px;
    line-height:1.5;
}

.dashboard-card-link{
    display:inline-flex;
    align-items:center;
    gap:7px;
    min-height:36px;
    padding:0 11px;
    border:1px solid var(--button-border);
    border-radius:11px;
    color:var(--text);
    background:var(--button-bg);
    text-decoration:none;
    font-size:11px;
    font-weight:var(--font-weight-semibold);
    white-space:nowrap;
}

.dashboard-card-link:hover{
    background:var(--button-hover-bg);
    color:var(--button-hover-text);
}

.dashboard-table-wrap{
    overflow-x:auto;
}

.dashboard-table{
    width:100%;
    border-collapse:collapse;
}

.dashboard-table th{
    padding:12px 24px;
    text-align:left;
    background:var(--table-header-bg);
    color:var(--table-header-text);
    border-top:1px solid var(--table-border);
    border-bottom:1px solid var(--table-border);
    font-size:11px;
    font-weight:var(--font-weight-bold);
    white-space:nowrap;
}

.dashboard-table td{
    padding:15px 24px;
    color:var(--text);
    border-bottom:1px solid var(--table-border);
    font-size:12px;
    vertical-align:middle;
}

.dashboard-table tbody tr:hover{
    background:var(--table-hover-bg);
}

.dashboard-table tbody tr:last-child td{
    border-bottom:0;
}

.job-primary{
    color:var(--text);
    font-weight:var(--font-weight-semibold);
}

.job-secondary{
    display:block;
    margin-top:3px;
    color:var(--muted);
    font-size:10px;
}

.status-chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:5px 9px;
    border-radius:999px;
    background:var(--status-bg);
    color:var(--status-text);
    font-size:10px;
    font-weight:var(--font-weight-semibold);
    white-space:nowrap;
}

.status-chip::before{
    content:"";
    width:6px;
    height:6px;
    border-radius:50%;
    background:var(--status-dot);
}

.dashboard-empty{
    padding:36px 24px;
    text-align:center;
    color:var(--muted);
    font-size:12px;
}

.quick-actions{
    display:grid;
    gap:10px;
    padding:0 20px 20px;
}

.quick-action{
    display:flex;
    align-items:center;
    gap:12px;
    min-height:58px;
    padding:12px 13px;
    border:1px solid var(--card-border);
    border-radius:15px;
    background:color-mix(in srgb,var(--card-bg) 94%,var(--body-bg));
    text-decoration:none;
    transition:.16s ease;
}

.quick-action:hover{
    transform:translateY(-1px);
    border-color:var(--primary);
}

.quick-action-icon{
    width:38px;
    height:38px;
    flex:0 0 auto;
    display:grid;
    place-items:center;
    border-radius:11px;
    background:color-mix(in srgb,var(--primary) 10%,var(--card-bg));
    color:var(--primary);
}

.quick-action-icon svg{
    width:18px;
    height:18px;
}

.quick-action strong{
    display:block;
    color:var(--text);
    font-size:12px;
    font-weight:var(--font-weight-semibold);
}

.quick-action span{
    display:block;
    margin-top:3px;
    color:var(--muted);
    font-size:10px;
    line-height:1.35;
}

.business-summary{
    padding:0 20px 20px;
}

.business-summary-box{
    padding:16px;
    border:1px solid var(--card-border);
    border-radius:15px;
    background:color-mix(in srgb,var(--card-bg) 94%,var(--body-bg));
}

.business-summary-row{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    padding:10px 0;
    border-bottom:1px solid var(--card-border);
}

.business-summary-row:first-child{
    padding-top:0;
}

.business-summary-row:last-child{
    padding-bottom:0;
    border-bottom:0;
}

.business-summary-row span{
    color:var(--muted);
    font-size:11px;
}

.business-summary-row strong{
    color:var(--text);
    font-size:11px;
    font-weight:var(--font-weight-semibold);
    text-align:right;
    word-break:break-word;
}

@media(max-width:1200px){
    .dashboard-stats{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .dashboard-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:760px){
    .dashboard-hero{
        padding:20px;
    }

    .dashboard-hero h1{
        font-size:24px;
    }

    .dashboard-stats{
        grid-template-columns:1fr;
    }

    .dashboard-card-head{
        padding:18px 18px 14px;
    }

    .dashboard-table th,
    .dashboard-table td{
        padding-left:18px;
        padding-right:18px;
    }
}
</style>

<div class="business-dashboard">

    <section class="dashboard-hero">
        <div>
            <p class="dashboard-eyebrow">
                <?= htmlspecialchars($businessName) ?>
            </p>

            <h1>
                Welcome back, <?= htmlspecialchars($displayName) ?>
            </h1>

            <p>
                View your business activity, manage daily work and quickly
                access the areas you use most.
            </p>
        </div>

        <div class="dashboard-date">
            <i data-lucide="calendar-days"></i>
            <span><?= htmlspecialchars($todayLabel) ?></span>
        </div>
    </section>

    <section class="dashboard-stats">

        <article class="dashboard-stat">
            <div class="dashboard-stat-top">
                <div>
                    <div class="dashboard-stat-label">Customers</div>
                    <strong><?= number_format($totalCustomers) ?></strong>
                </div>

                <div class="dashboard-stat-icon">
                    <i data-lucide="users"></i>
                </div>
            </div>

            <small>Total customer records available to your current business context.</small>
        </article>

        <article class="dashboard-stat">
            <div class="dashboard-stat-top">
                <div>
                    <div class="dashboard-stat-label">Total Jobs</div>
                    <strong><?= number_format($totalJobs) ?></strong>
                </div>

                <div class="dashboard-stat-icon">
                    <i data-lucide="briefcase-business"></i>
                </div>
            </div>

            <small>All jobs currently available for this business or assigned branch.</small>
        </article>

        <article class="dashboard-stat">
            <div class="dashboard-stat-top">
                <div>
                    <div class="dashboard-stat-label">Open Jobs</div>
                    <strong><?= number_format($openJobs) ?></strong>
                </div>

                <div class="dashboard-stat-icon">
                    <i data-lucide="clipboard-list"></i>
                </div>
            </div>

            <small>Jobs that are not marked completed, closed or cancelled.</small>
        </article>

        <article class="dashboard-stat">
            <div class="dashboard-stat-top">
                <div>
                    <div class="dashboard-stat-label">Outstanding</div>
                    <strong>₹<?= dashboardMoney($outstandingAmount) ?></strong>
                </div>

                <div class="dashboard-stat-icon">
                    <i data-lucide="indian-rupee"></i>
                </div>
            </div>

            <small><?= number_format($totalInvoices) ?> invoice record<?= $totalInvoices === 1 ? '' : 's' ?> found.</small>
        </article>

    </section>

    <section class="dashboard-grid">

        <div class="dashboard-card">
            <div class="dashboard-card-head">
                <div>
                    <h2>Recent Jobs</h2>
                    <p>Latest work activity for your current business context.</p>
                </div>

                <a href="jobs.php" class="dashboard-card-link">
                    View jobs
                    <i data-lucide="arrow-right" style="width:14px;height:14px"></i>
                </a>
            </div>

            <?php if (!empty($recentJobs)): ?>

                <div class="dashboard-table-wrap">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Job</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($recentJobs as $job): ?>
                                <?php
                                $jobNo = trim(
                                    (string)($job['job_no'] ?? '')
                                );

                                if ($jobNo === '') {
                                    $jobNo =
                                        'JOB-' .
                                        str_pad(
                                            (string)(int)$job['id'],
                                            6,
                                            '0',
                                            STR_PAD_LEFT
                                        );
                                }

                                $jobTitle = trim(
                                    (string)($job['title'] ?? '')
                                );

                                $jobStatus = trim(
                                    (string)($job['status'] ?? '')
                                );

                                if ($jobStatus === '') {
                                    $jobStatus = 'Pending';
                                }

                                $jobDate = '';

                                foreach (
                                    array(
                                        'scheduled_date',
                                        'start_date',
                                        'created_at'
                                    ) as $dateKey
                                ) {
                                    if (
                                        !empty($job[$dateKey]) &&
                                        strtotime(
                                            (string)$job[$dateKey]
                                        ) !== false
                                    ) {
                                        $jobDate =
                                            date(
                                                'd M Y',
                                                strtotime(
                                                    (string)$job[$dateKey]
                                                )
                                            );
                                        break;
                                    }
                                }

                                if ($jobDate === '') {
                                    $jobDate = '—';
                                }
                                ?>

                                <tr>
                                    <td>
                                        <span class="job-primary">
                                            <?= htmlspecialchars($jobNo) ?>
                                        </span>

                                        <?php if ($jobTitle !== ''): ?>
                                            <span class="job-secondary">
                                                <?= htmlspecialchars($jobTitle) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="status-chip">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $jobStatus))) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($jobDate) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <div class="dashboard-empty">
                    No recent job records are available yet.
                </div>

            <?php endif; ?>
        </div>

        <aside>

            <div class="dashboard-card" style="margin-bottom:16px">
                <div class="dashboard-card-head">
                    <div>
                        <h2>Quick Actions</h2>
                        <p>Start common FieldPlx tasks.</p>
                    </div>
                </div>

                <div class="quick-actions">

                    <a href="customers.php" class="quick-action">
                        <div class="quick-action-icon">
                            <i data-lucide="user-plus"></i>
                        </div>

                        <div>
                            <strong>Customers</strong>
                            <span>Open customer management.</span>
                        </div>
                    </a>

                    <a href="jobs.php" class="quick-action">
                        <div class="quick-action-icon">
                            <i data-lucide="briefcase-business"></i>
                        </div>

                        <div>
                            <strong>Jobs</strong>
                            <span>View and manage field work.</span>
                        </div>
                    </a>

                    <a href="schedule.php" class="quick-action">
                        <div class="quick-action-icon">
                            <i data-lucide="calendar-range"></i>
                        </div>

                        <div>
                            <strong>Schedule</strong>
                            <span>Review appointments and assignments.</span>
                        </div>
                    </a>

                    <a href="invoices.php" class="quick-action">
                        <div class="quick-action-icon">
                            <i data-lucide="receipt-text"></i>
                        </div>

                        <div>
                            <strong>Invoices</strong>
                            <span>Manage invoices and payments.</span>
                        </div>
                    </a>

                </div>
            </div>

            <div class="dashboard-card">
                <div class="dashboard-card-head">
                    <div>
                        <h2>Current Context</h2>
                        <p>Your active business session.</p>
                    </div>
                </div>

                <div class="business-summary">
                    <div class="business-summary-box">

                        <div class="business-summary-row">
                            <span>Business</span>
                            <strong><?= htmlspecialchars($businessName) ?></strong>
                        </div>

                        <div class="business-summary-row">
                            <span>Branch</span>
                            <strong>
                                <?= htmlspecialchars(
                                    (string)(
                                        $_SESSION['branch_name'] ?? 'All / Main'
                                    )
                                ) ?>
                            </strong>
                        </div>

                        <div class="business-summary-row">
                            <span>Role</span>
                            <strong>
                                <?= htmlspecialchars(
                                    (string)(
                                        $_SESSION['role_name'] ?? 'User'
                                    )
                                ) ?>
                            </strong>
                        </div>

                        <div class="business-summary-row">
                            <span>Timezone</span>
                            <strong><?= htmlspecialchars($currentTimezone) ?></strong>
                        </div>

                    </div>
                </div>
            </div>

        </aside>

    </section>

</div>

<script>
(function(){
    if (
        window.lucide &&
        typeof window.lucide.createIcons === 'function'
    ) {
        window.lucide.createIcons();
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
