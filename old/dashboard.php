<?php
/**
 * FieldPlx Main Tenant Dashboard - Navy / Lime redesign
 * Upload as: /public_html/dashboard.php
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('dashboard.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'dashboard.view',
        'You do not have permission to view the dashboard.'
    );
}

$pageTitle = 'Dashboard - FieldPlx';
$activePage = 'dashboard';
$searchPlaceholder = 'Search anything...';
$basePath = '';

if (!function_exists('dashboardFetchAssoc')) {
    function dashboardFetchAssoc(mysqli_stmt $stmt)
    {
        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if ($result) {
                return $result->fetch_assoc();
            }
        }

        $metadata = $stmt->result_metadata();
        if (!$metadata) {
            return null;
        }

        $row = array();
        $bind = array();
        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(array($stmt, 'bind_result'), $bind);
        if (!$stmt->fetch()) {
            return null;
        }

        $copy = array();
        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }
        return $copy;
    }
}

if (!function_exists('dashboardFetchAll')) {
    function dashboardFetchAll(mysqli_stmt $stmt)
    {
        $rows = array();

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                return $rows;
            }
        }

        $metadata = $stmt->result_metadata();
        if (!$metadata) {
            return $rows;
        }

        $row = array();
        $bind = array();
        while ($field = $metadata->fetch_field()) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }

        call_user_func_array(array($stmt, 'bind_result'), $bind);
        while ($stmt->fetch()) {
            $copy = array();
            foreach ($row as $key => $value) {
                $copy[$key] = $value;
            }
            $rows[] = $copy;
        }

        return $rows;
    }
}

if (!function_exists('dashboardScalar')) {
    function dashboardScalar(mysqli $conn, $sql, $tenantId)
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Dashboard prepare error: ' . $conn->error);
            return 0;
        }

        $stmt->bind_param('i', $tenantId);
        if (!$stmt->execute()) {
            error_log('Dashboard execute error: ' . $stmt->error);
            $stmt->close();
            return 0;
        }

        $row = dashboardFetchAssoc($stmt);
        $stmt->close();

        if (!$row) {
            return 0;
        }

        $values = array_values($row);
        return isset($values[0]) ? $values[0] : 0;
    }
}

if (!function_exists('dashboardRows')) {
    function dashboardRows(mysqli $conn, $sql, $tenantId)
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Dashboard prepare error: ' . $conn->error);
            return array();
        }

        $stmt->bind_param('i', $tenantId);
        if (!$stmt->execute()) {
            error_log('Dashboard execute error: ' . $stmt->error);
            $stmt->close();
            return array();
        }

        $rows = dashboardFetchAll($stmt);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('dashboardMoney')) {
    function dashboardMoney($amount, $currency)
    {
        return trim((string) $currency) . ' ' . number_format((float) $amount, 2);
    }
}

if (!function_exists('dashboardDate')) {
    function dashboardDate($value)
    {
        if (empty($value)) {
            return '-';
        }

        $timestamp = strtotime((string) $value);
        return $timestamp ? date('d M Y', $timestamp) : '-';
    }
}

if (!function_exists('dashboardTime')) {
    function dashboardTime($value)
    {
        if (empty($value)) {
            return '-';
        }

        $timestamp = strtotime((string) $value);
        return $timestamp ? date('h:i A', $timestamp) : '-';
    }
}

if (!function_exists('dashboardDateTime')) {
    function dashboardDateTime($value)
    {
        if (empty($value)) {
            return '-';
        }

        $timestamp = strtotime((string) $value);
        return $timestamp ? date('d M Y, h:i A', $timestamp) : '-';
    }
}

if (!function_exists('dashboardStatusClass')) {
    function dashboardStatusClass($status)
    {
        $status = strtolower(trim((string) $status));

        if (in_array($status, array('completed', 'closed', 'paid', 'invoiced'), true)) {
            return 'completed';
        }

        if (in_array($status, array(
            'active', 'in_progress', 'today', 'late', 'action_required',
            'needs_review', 'requires_invoicing', 'ready_to_invoice'
        ), true)) {
            return 'progress';
        }

        if (in_array($status, array('cancelled', 'archived', 'missed'), true)) {
            return 'cancelled';
        }

        return 'pending';
    }
}

if (!function_exists('dashboardGrowth')) {
    function dashboardGrowth($current, $previous)
    {
        $current = (float) $current;
        $previous = (float) $previous;

        if (abs($previous) < 0.00001) {
            if (abs($current) < 0.00001) {
                return array('direction' => 'flat', 'text' => 'No change vs last week');
            }
            return array('direction' => 'up', 'text' => 'New activity this week');
        }

        $percent = (($current - $previous) / abs($previous)) * 100;
        return array(
            'direction' => $percent >= 0 ? 'up' : 'down',
            'text' => number_format(abs($percent), 1) . '% vs last week'
        );
    }
}

if (!function_exists('dashboardActivityIcon')) {
    function dashboardActivityIcon($eventType)
    {
        $value = strtolower((string) $eventType);

        if (strpos($value, 'payment') !== false || strpos($value, 'invoice') !== false) {
            return array('bi-credit-card', 'orange');
        }
        if (strpos($value, 'client') !== false || strpos($value, 'customer') !== false) {
            return array('bi-person', 'blue');
        }
        if (strpos($value, 'task') !== false) {
            return array('bi-check2-square', 'lime');
        }
        if (strpos($value, 'visit') !== false || strpos($value, 'schedule') !== false) {
            return array('bi-calendar3', 'blue');
        }

        return array('bi-briefcase', 'green');
    }
}

if (!function_exists('dashboardTimeAgo')) {
    function dashboardTimeAgo($value)
    {
        if (function_exists('timeAgo')) {
            return timeAgo($value);
        }
        return dashboardDateTime($value);
    }
}

$tenantId = isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : 0;
$currentUser = function_exists('authUser') ? authUser() : array();
$userName = !empty($currentUser['name'])
    ? (string) $currentUser['name']
    : (!empty($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : 'User');
$companyName = !empty($_SESSION['company_name']) ? (string) $_SESSION['company_name'] : 'FieldPlx';
$currencyCode = !empty($_SESSION['currency_code']) ? (string) $_SESSION['currency_code'] : 'INR';

$canViewClients = function_exists('hasPermission') ? hasPermission('clients.view') : true;
$canViewJobs = function_exists('hasPermission') ? hasPermission('jobs.view') : true;
$canViewInvoices = function_exists('hasPermission') ? hasPermission('invoices.view') : true;
$canViewPayments = function_exists('hasPermission') ? hasPermission('payments.view') : true;
$canViewSchedule = function_exists('hasPermission') ? hasPermission('schedule.view') : true;
$canViewTeam = function_exists('hasPermission') ? hasPermission('team.view') : true;
$canViewReports = function_exists('hasPermission') ? hasPermission('reports.view') : true;
$canViewTasks = $canViewJobs;

$totalJobs = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status <> 'archived'",
    $tenantId
);

$completedJobs = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status IN ('completed','closed')",
    $tenantId
);

$inProgressJobs = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status IN (
           'active','in_progress','late','action_required','needs_review',
           'requires_invoicing','ready_to_invoice','invoiced','ending_within_30_days'
       )",
    $tenantId
);

$totalClients = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM clients
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND client_type <> 'archived'",
    $tenantId
);

$fieldWorkers = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM users
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status = 'active'
       AND is_field_worker = 1",
    $tenantId
);

$pendingInvoiceCount = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM invoices
     WHERE tenant_id = ?
       AND archived_at IS NULL
       AND balance_due > 0
       AND status NOT IN ('paid','cancelled','written_off','archived')",
    $tenantId
);

$pendingInvoiceAmount = (float) dashboardScalar(
    $conn,
    "SELECT COALESCE(SUM(balance_due), 0)
     FROM invoices
     WHERE tenant_id = ?
       AND archived_at IS NULL
       AND balance_due > 0
       AND status NOT IN ('paid','cancelled','written_off','archived')",
    $tenantId
);

$totalRevenue = (float) dashboardScalar(
    $conn,
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE tenant_id = ?
       AND status = 'succeeded'",
    $tenantId
);

$pendingPaymentCount = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM payments
     WHERE tenant_id = ?
       AND status IN ('pending','authorized')",
    $tenantId
);

$pendingPaymentAmount = (float) dashboardScalar(
    $conn,
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE tenant_id = ?
       AND status IN ('pending','authorized')",
    $tenantId
);

$currentWeekJobs = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    $tenantId
);

$previousWeekJobs = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
       AND created_at < DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    $tenantId
);

$currentWeekCompleted = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status IN ('completed','closed')
       AND COALESCE(completed_at, updated_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    $tenantId
);

$previousWeekCompleted = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status IN ('completed','closed')
       AND COALESCE(completed_at, updated_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
       AND COALESCE(completed_at, updated_at, created_at) < DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    $tenantId
);

$currentWeekProgress = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status IN ('active','in_progress','late','action_required','needs_review')
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    $tenantId
);

$previousWeekProgress = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status IN ('active','in_progress','late','action_required','needs_review')
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
       AND created_at < DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    $tenantId
);

$currentWeekRevenue = (float) dashboardScalar(
    $conn,
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE tenant_id = ?
       AND status = 'succeeded'
       AND COALESCE(received_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    $tenantId
);

$previousWeekRevenue = (float) dashboardScalar(
    $conn,
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE tenant_id = ?
       AND status = 'succeeded'
       AND COALESCE(received_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
       AND COALESCE(received_at, created_at) < DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    $tenantId
);

$jobGrowth = dashboardGrowth($currentWeekJobs, $previousWeekJobs);
$completedGrowth = dashboardGrowth($currentWeekCompleted, $previousWeekCompleted);
$progressGrowth = dashboardGrowth($currentWeekProgress, $previousWeekProgress);
$revenueGrowth = dashboardGrowth($currentWeekRevenue, $previousWeekRevenue);

$statusRows = dashboardRows(
    $conn,
    "SELECT status, COUNT(*) AS total_count
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status <> 'archived'
     GROUP BY status",
    $tenantId
);

$pendingJobs = 0;
$progressJobs = 0;
$statusCompletedJobs = 0;
$cancelledJobs = 0;

foreach ($statusRows as $row) {
    $status = strtolower((string) $row['status']);
    $count = (int) $row['total_count'];

    if (in_array($status, array('draft','scheduled','upcoming','today','unscheduled'), true)) {
        $pendingJobs += $count;
    } elseif (in_array($status, array('completed','closed'), true)) {
        $statusCompletedJobs += $count;
    } elseif ($status === 'cancelled') {
        $cancelledJobs += $count;
    } else {
        $progressJobs += $count;
    }
}

$statusTotal = $pendingJobs + $progressJobs + $statusCompletedJobs + $cancelledJobs;
$statusDenominator = $statusTotal > 0 ? $statusTotal : 1;
$pendingPct = ($pendingJobs / $statusDenominator) * 100;
$progressPct = ($progressJobs / $statusDenominator) * 100;
$completedPct = ($statusCompletedJobs / $statusDenominator) * 100;
$cancelledPct = ($cancelledJobs / $statusDenominator) * 100;
$stopPending = $pendingPct;
$stopProgress = $stopPending + $progressPct;
$stopCompleted = $stopProgress + $completedPct;

if ($statusTotal > 0) {
    $donutStyle = sprintf(
        'background:conic-gradient(#a7cf5b 0 %.3f%%,#123d70 %.3f%% %.3f%%,#74b824 %.3f%% %.3f%%,#e45b66 %.3f%% 100%%);',
        $stopPending,
        $stopPending,
        $stopProgress,
        $stopProgress,
        $stopCompleted,
        $stopCompleted
    );
} else {
    $donutStyle = 'background:#eef2f6;';
}

$recentJobs = dashboardRows(
    $conn,
    "SELECT
        j.id,
        j.job_no,
        j.title,
        j.status,
        j.start_date,
        j.end_date,
        c.display_name AS client_name,
        COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''),
            'Unassigned'
        ) AS worker_name,
        COALESCE(
            (
                SELECT jli.item_name
                FROM job_line_items jli
                WHERE jli.tenant_id = j.tenant_id
                  AND jli.job_id = j.id
                ORDER BY jli.sort_order ASC, jli.id ASC
                LIMIT 1
            ),
            'Service'
        ) AS service_name
     FROM jobs j
     INNER JOIN clients c
        ON c.id = j.client_id
       AND c.tenant_id = j.tenant_id
     LEFT JOIN users u
        ON u.id = j.assigned_user_id
       AND u.tenant_id = j.tenant_id
     WHERE j.tenant_id = ?
       AND j.deleted_at IS NULL
     ORDER BY j.created_at DESC
     LIMIT 5",
    $tenantId
);

$todayTasks = dashboardRows(
    $conn,
    "SELECT
        t.id,
        t.title,
        t.status,
        t.priority,
        t.due_at,
        COALESCE(c.display_name, 'General task') AS client_name
     FROM tasks t
     LEFT JOIN clients c
        ON c.id = t.client_id
       AND c.tenant_id = t.tenant_id
     WHERE t.tenant_id = ?
       AND DATE(t.due_at) = CURDATE()
       AND t.status <> 'cancelled'
     ORDER BY
        CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END,
        CASE t.priority
            WHEN 'urgent' THEN 1
            WHEN 'high' THEN 2
            WHEN 'normal' THEN 3
            ELSE 4
        END,
        t.due_at ASC
     LIMIT 5",
    $tenantId
);

$todayTaskCount = (int) dashboardScalar(
    $conn,
    "SELECT COUNT(*)
     FROM tasks
     WHERE tenant_id = ?
       AND DATE(due_at) = CURDATE()
       AND status <> 'cancelled'",
    $tenantId
);

$todaySchedule = dashboardRows(
    $conn,
    "SELECT
        v.id,
        v.scheduled_start,
        v.status,
        j.id AS job_id,
        j.title AS job_title,
        c.display_name AS client_name
     FROM visits v
     INNER JOIN jobs j
        ON j.id = v.job_id
       AND j.tenant_id = v.tenant_id
     INNER JOIN clients c
        ON c.id = j.client_id
       AND c.tenant_id = j.tenant_id
     WHERE v.tenant_id = ?
       AND v.scheduled_start IS NOT NULL
       AND DATE(v.scheduled_start) = CURDATE()
       AND v.status NOT IN ('cancelled','missed')
     ORDER BY v.scheduled_start ASC
     LIMIT 4",
    $tenantId
);

$schedulePanelTitle = "Today's Schedule";
$scheduleRows = $todaySchedule;

if (empty($scheduleRows)) {
    $scheduleRows = dashboardRows(
        $conn,
        "SELECT
            v.id,
            v.scheduled_start,
            v.status,
            j.id AS job_id,
            j.title AS job_title,
            c.display_name AS client_name
         FROM visits v
         INNER JOIN jobs j
            ON j.id = v.job_id
           AND j.tenant_id = v.tenant_id
         INNER JOIN clients c
            ON c.id = j.client_id
           AND c.tenant_id = j.tenant_id
         WHERE v.tenant_id = ?
           AND v.scheduled_start IS NOT NULL
           AND v.scheduled_start >= NOW()
           AND v.status NOT IN ('completed','cancelled','missed')
         ORDER BY v.scheduled_start ASC
         LIMIT 4",
        $tenantId
    );

    if (!empty($scheduleRows)) {
        $schedulePanelTitle = 'Upcoming Schedule';
    }
}

$recentActivity = dashboardRows(
    $conn,
    "SELECT id, event_type, title, created_at
     FROM activity_events
     WHERE tenant_id = ?
     ORDER BY created_at DESC
     LIMIT 3",
    $tenantId
);

$chartDates = array();
$chartLabels = array();
$jobsSeries = array();
$completedSeries = array();
$revenueSeries = array();

for ($i = 6; $i >= 0; $i--) {
    $timestamp = strtotime('-' . $i . ' day');
    $key = date('Y-m-d', $timestamp);
    $chartDates[] = $key;
    $chartLabels[] = date('M j', $timestamp);
    $jobsSeries[$key] = 0;
    $completedSeries[$key] = 0;
    $revenueSeries[$key] = 0;
}

$jobSeriesRows = dashboardRows(
    $conn,
    "SELECT DATE(created_at) AS day_key, COUNT(*) AS total_count
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(created_at)",
    $tenantId
);

foreach ($jobSeriesRows as $row) {
    $key = (string) $row['day_key'];
    if (array_key_exists($key, $jobsSeries)) {
        $jobsSeries[$key] = (int) $row['total_count'];
    }
}

$completedSeriesRows = dashboardRows(
    $conn,
    "SELECT DATE(COALESCE(completed_at, updated_at, created_at)) AS day_key,
            COUNT(*) AS total_count
     FROM jobs
     WHERE tenant_id = ?
       AND deleted_at IS NULL
       AND status IN ('completed','closed')
       AND COALESCE(completed_at, updated_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(COALESCE(completed_at, updated_at, created_at))",
    $tenantId
);

foreach ($completedSeriesRows as $row) {
    $key = (string) $row['day_key'];
    if (array_key_exists($key, $completedSeries)) {
        $completedSeries[$key] = (int) $row['total_count'];
    }
}

$revenueSeriesRows = dashboardRows(
    $conn,
    "SELECT DATE(COALESCE(received_at, created_at)) AS day_key,
            COALESCE(SUM(amount), 0) AS total_amount
     FROM payments
     WHERE tenant_id = ?
       AND status = 'succeeded'
       AND COALESCE(received_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(COALESCE(received_at, created_at))",
    $tenantId
);

foreach ($revenueSeriesRows as $row) {
    $key = (string) $row['day_key'];
    if (array_key_exists($key, $revenueSeries)) {
        $revenueSeries[$key] = (float) $row['total_amount'];
    }
}

$jobsChartValues = array_values($jobsSeries);
$completedChartValues = array_values($completedSeries);
$revenueChartValues = array_values($revenueSeries);
$progressSparkValues = array();

foreach ($jobsChartValues as $index => $value) {
    $completedValue = isset($completedChartValues[$index]) ? $completedChartValues[$index] : 0;
    $progressSparkValues[] = max(0, (int) $value - (int) $completedValue);
}

$weekStart = strtotime('monday this week');
$weekEnd = strtotime('sunday this week');
$weekRangeLabel = date('d M', $weekStart) . ' - ' . date('d M Y', $weekEnd);

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
:root {
    --fieldplx-primary: #74b824;
    --fieldplx-primary-dark: #5d971b;
    --fieldplx-text: #0b1933;
    --fieldplx-muted: #6f7b90;
    --fieldplx-border: #e5eaf1;
    --fieldplx-surface: #ffffff;
    --fieldplx-background: #f6f8fb;
    --fieldplx-topbar-height: 70px;
    --fieldplx-sidebar-width: 250px;
    --fieldplx-sidebar-collapsed-width: 78px;
    --fd-navy: #001131;
    --fd-navy-light: #071f49;
    --fd-blue: #123d70;
    --fd-green: #74b824;
    --fd-green-dark: #5d971b;
    --fd-green-soft: #f0f8e5;
    --fd-orange: #96c945;
    --fd-red: #e45b66;
    --fd-bg: #f6f8fb;
    --fd-text: #0b1933;
    --fd-muted: #6f7b90;
    --fd-border: #e5eaf1;
}

body {
    background: var(--fd-bg) !important;
    color: var(--fd-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--fd-border) !important;
    box-shadow: 0 3px 14px rgba(0, 17, 49, .035);
    backdrop-filter: none !important;
    transition: margin-left .25s ease, width .25s ease;
}

body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: var(--fieldplx-sidebar-collapsed-width);
    width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
}

.fieldplx-topbar-inner {
    min-height: 70px !important;
    padding: 0 27px !important;
    gap: 13px !important;
}

.fieldplx-page-heading {
    display: none !important;
}

.fieldplx-menu-toggle,
.fieldplx-topbar-action {
    width: 41px !important;
    height: 41px !important;
    border: 0 !important;
    border-radius: 9px !important;
    color: var(--fd-navy) !important;
    background: transparent !important;
}

.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--fd-navy) !important;
    background: var(--fd-green-soft) !important;
}

.fieldplx-search-wrap {
    width: 280px !important;
    margin-left: auto;
}

.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--fd-text) !important;
    font-size: 12px !important;
}

.fieldplx-search-input:focus {
    background: #f5f8fb !important;
    box-shadow: 0 0 0 3px rgba(116, 184, 36, .14) !important;
}

.fieldplx-profile-button {
    padding: 2px !important;
    border: 0 !important;
    border-radius: 9px !important;
    background: transparent !important;
}

.fieldplx-profile-button:hover {
    background: var(--fd-green-soft) !important;
}

.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--fd-navy) !important;
    background: linear-gradient(135deg, #fff, #e8f3d9) !important;
    font-size: 12px !important;
    font-weight: 800 !important;
}

.fieldplx-profile-name {
    font-size: 12px !important;
}

.fieldplx-profile-role {
    color: var(--fd-muted) !important;
    font-size: 10px !important;
}

.fieldplx-notification-count {
    background: var(--fd-red) !important;
}

.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--fd-border) !important;
    box-shadow: 0 18px 45px rgba(29, 38, 74, .14) !important;
}

.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover {
    color: var(--fd-green-dark) !important;
}

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg, var(--fd-navy-light), var(--fd-navy)) !important;
    border-top: 4px solid var(--fd-green) !important;
    border-right: 0 !important;
    transition: width .25s ease, min-width .25s ease, transform .25s ease !important;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: var(--fieldplx-sidebar-collapsed-width) !important;
    min-width: var(--fieldplx-sidebar-collapsed-width) !important;
}

.fieldplx-sidebar-header {
    min-height: 68px !important;
    padding: 9px 14px 10px !important;
    border-bottom: 1px solid rgba(255, 255, 255, .08) !important;
}

.fieldplx-sidebar-brand {
    color: #fff !important;
}

.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
    width: 40px !important;
    height: 40px !important;
    flex: 0 0 40px !important;
    border-radius: 10px !important;
}

.fieldplx-sidebar-logo-placeholder {
    color: #fff !important;
    background: linear-gradient(135deg, #8fd236, #68aa1d) !important;
    font-size: 18px !important;
}

.fieldplx-sidebar-company-name {
    max-width: 155px !important;
    color: #fff !important;
    font-size: 16px !important;
    font-weight: 700 !important;
}

.fieldplx-sidebar-product-name {
    color: #9fda55 !important;
    font-size: 9px !important;
}

.fieldplx-sidebar-body {
    padding: 12px 14px !important;
    scrollbar-width: none !important;
}

.fieldplx-sidebar-body::-webkit-scrollbar {
    display: none;
}

.fieldplx-sidebar-section-label {
    margin: 7px 12px 7px !important;
    color: rgba(255, 255, 255, .50) !important;
    font-size: 9px !important;
}

.fieldplx-sidebar-nav {
    gap: 3px !important;
}

.fieldplx-sidebar-link {
    min-height: 46px !important;
    margin-bottom: 3px !important;
    padding: 0 14px !important;
    gap: 15px !important;
    border-radius: 9px !important;
    color: rgba(255, 255, 255, .94) !important;
    font-size: 14px !important;
    font-weight: 600 !important;
}

.fieldplx-sidebar-link:hover {
    color: #fff !important;
    background: rgba(255, 255, 255, .08) !important;
}

.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link {
    color: #fff !important;
    background: linear-gradient(90deg, #7fc92d, #68aa1d) !important;
    box-shadow: 0 6px 18px rgba(0, 17, 49, .28) !important;
}

.fieldplx-sidebar-link-icon {
    width: 21px !important;
    height: 21px !important;
    flex: 0 0 21px !important;
    font-size: 19px !important;
}

.fieldplx-sidebar-arrow {
    color: rgba(255, 255, 255, .65) !important;
}

.fieldplx-sidebar-submenu {
    padding-left: 36px !important;
}

.fieldplx-sidebar-sublink {
    min-height: 34px !important;
    color: rgba(255, 255, 255, .72) !important;
    font-size: 11px !important;
}

.fieldplx-sidebar-sublink::before {
    background: rgba(255, 255, 255, .35) !important;
}

.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active {
    color: #fff !important;
    background: rgba(255, 255, 255, .08) !important;
}

.fieldplx-sidebar-sublink.active::before {
    background: #9fda55 !important;
}

.fieldplx-sidebar-footer {
    padding: 10px 14px 14px !important;
    border-top: 1px solid rgba(255, 255, 255, .08) !important;
}

.fieldplx-sidebar-user {
    min-height: 62px;
    background: rgba(255, 255, 255, .08) !important;
}

.fieldplx-sidebar-user-name {
    color: #fff !important;
    font-size: 12px !important;
}

.fieldplx-sidebar-user-role {
    color: rgba(255, 255, 255, .60) !important;
    font-size: 9px !important;
}

.fieldplx-sidebar-user-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    color: var(--fd-navy) !important;
    background: linear-gradient(135deg, #fff, #e8f3d9) !important;
}

.fieldplx-sidebar-logout {
    color: rgba(255, 255, 255, .70) !important;
}

.fieldplx-sidebar-logout:hover {
    color: #fff !important;
    background: rgba(228, 91, 102, .30) !important;
}

.fieldplx-main-layout {
    display: block !important;
    min-height: calc(100vh - 70px) !important;
}

.fieldplx-main-content {
    margin-left: var(--fieldplx-sidebar-width);
    min-width: 0;
    transition: margin-left .25s ease;
}

body.fieldplx-sidebar-collapsed .fieldplx-main-content {
    margin-left: var(--fieldplx-sidebar-collapsed-width);
}

.fieldplx-content-wrapper {
    padding: 0 !important;
}

.fieldplx-footer {
    display: none !important;
}

.fd-dashboard {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.fd-dashboard .row > * {
    min-width: 0;
}

.fd-welcome {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 23px;
}

.fd-welcome h1 {
    margin: 0 0 8px;
    color: var(--fd-text);
    font-size: 21px;
    font-weight: 700;
}

.fd-welcome p {
    margin: 0;
    color: var(--fd-muted);
    font-size: 12px;
}

.fd-date-actions {
    display: flex;
    gap: 9px;
}

.fd-date-button,
.fd-filter-button {
    height: 46px;
    border: 1px solid var(--fd-border);
    border-radius: 9px;
    color: var(--fd-navy);
    background: #fff;
    box-shadow: 0 5px 15px rgba(31, 43, 88, .05);
    text-decoration: none;
}

.fd-date-button {
    min-width: 213px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 11px;
    padding: 0 14px;
    font-size: 11px;
    font-weight: 700;
}

.fd-filter-button {
    width: 46px;
    display: grid;
    place-items: center;
}

.fd-date-button:hover,
.fd-filter-button:hover {
    border-color: #cfe3ae;
    color: var(--fd-green-dark);
    background: #f9fcf4;
}

.fd-card {
    height: 100%;
    border: 1px solid var(--fd-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31, 43, 88, .05);
}

.fd-stat-card {
    position: relative;
    min-height: 170px;
    padding: 25px 20px 8px;
    overflow: hidden;
}

.fd-stat-more {
    position: absolute;
    top: 13px;
    right: 11px;
    color: #8995a8;
    font-size: 15px;
}

.fd-stat-row {
    display: flex;
    align-items: flex-start;
    gap: 18px;
}

.fd-stat-icon {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    font-size: 25px;
}

.fd-stat-icon.blue { background: var(--fd-blue); }
.fd-stat-icon.green { background: var(--fd-green); }
.fd-stat-icon.lime { background: var(--fd-green-dark); }
.fd-stat-icon.orange { background: var(--fd-orange); }

.fd-stat-label {
    display: block;
    margin-bottom: 10px;
    color: #66748b;
    font-size: 11px;
}

.fd-stat-value {
    display: block;
    color: var(--fd-text);
    font-size: 27px;
    line-height: 1;
    font-weight: 700;
}

.fd-growth {
    display: block;
    margin-top: 14px;
    color: #8a95a8;
    font-size: 9px;
}

.fd-growth strong {
    font-size: 10px;
}

.fd-growth.up strong { color: var(--fd-green-dark); }
.fd-growth.down strong { color: var(--fd-red); }
.fd-growth.flat strong { color: #7d899d; }

.fd-sparkline {
    position: absolute;
    right: 18px;
    bottom: 7px;
    left: 18px;
    height: 45px;
}

.fd-panel {
    padding: 18px;
}

.fd-panel-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 13px;
}

.fd-panel-title h2 {
    margin: 0;
    color: var(--fd-text);
    font-size: 14px;
    font-weight: 700;
}

.fd-chart-card {
    min-height: 313px;
}

.fd-chart-area {
    position: relative;
    height: 245px;
}

.fd-chart-area canvas {
    width: 100% !important;
    height: 100% !important;
}

.fd-chart-legend {
    color: var(--fd-muted);
    font-size: 10px;
    white-space: nowrap;
}

.fd-status-wrapper {
    min-height: 245px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 22px;
}

.fd-donut {
    position: relative;
    width: 165px;
    height: 165px;
    flex: 0 0 165px;
    display: grid;
    place-items: center;
    border-radius: 50%;
}

.fd-donut::before {
    position: absolute;
    width: 104px;
    height: 104px;
    border-radius: 50%;
    background: #fff;
    content: '';
}

.fd-donut-center {
    position: relative;
    z-index: 1;
    text-align: center;
}

.fd-donut-center strong {
    display: block;
    color: var(--fd-text);
    font-size: 21px;
}

.fd-donut-center small {
    color: var(--fd-muted);
    font-size: 10px;
}

.fd-status-legend {
    display: flex;
    flex-direction: column;
    gap: 11px;
}

.fd-legend-row {
    display: flex;
    gap: 8px;
    color: var(--fd-muted);
    font-size: 10px;
    line-height: 1.45;
}

.fd-legend-dot {
    width: 8px;
    height: 8px;
    flex: 0 0 8px;
    margin-top: 3px;
    border-radius: 50%;
}

.fd-legend-row strong {
    color: var(--fd-text);
}

.fd-tasks-count {
    padding: 4px 8px;
    border-radius: 999px;
    color: var(--fd-green-dark);
    background: var(--fd-green-soft);
    font-size: 9px;
    font-weight: 700;
}

.fd-task-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.fd-task-item {
    min-height: 41px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border: 1px solid var(--fd-border);
    border-radius: 8px;
    color: inherit;
    background: #fbfcfa;
    text-decoration: none;
    transition: border-color .2s ease, background .2s ease;
}

.fd-task-item:hover {
    border-color: #cfe3ae;
    color: inherit;
    background: #f7fbed;
}

.fd-task-check {
    width: 17px;
    height: 17px;
    flex: 0 0 17px;
    display: grid;
    place-items: center;
    border: 1px solid #cdd3df;
    border-radius: 4px;
    color: #fff;
    font-size: 10px;
}

.fd-task-item.complete {
    background: #f5faee;
}

.fd-task-item.complete .fd-task-check {
    border-color: var(--fd-green);
    background: var(--fd-green);
}

.fd-task-content {
    min-width: 0;
    flex: 1;
}

.fd-task-content strong,
.fd-task-content small {
    display: block;
}

.fd-task-content strong {
    overflow: hidden;
    color: var(--fd-navy);
    font-size: 10px;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fd-task-content small {
    margin-top: 2px;
    color: var(--fd-muted);
    font-size: 9px;
}

.fd-task-item.complete .fd-task-content strong {
    color: #8792a4;
    text-decoration: line-through;
}

.fd-task-time {
    flex: 0 0 auto;
    padding: 4px 7px;
    border-radius: 999px;
    color: #5c6b81;
    background: #eef2f6;
    font-size: 8.5px;
    font-weight: 700;
    white-space: nowrap;
}

.fd-task-footer {
    display: flex;
    justify-content: flex-end;
    padding-top: 7px;
}

.fd-link {
    color: var(--fd-green-dark);
    font-size: 10px;
    font-weight: 600;
    text-decoration: none;
}

.fd-link:hover {
    color: var(--fd-green);
}

.fd-recent-jobs-card {
    min-height: 360px;
    overflow: hidden;
}

.fd-view-button {
    padding: 6px 11px;
    border: 1px solid var(--fd-border);
    border-radius: 5px;
    color: #53627a;
    background: #fff;
    font-size: 10px;
    text-decoration: none;
}

.fd-view-button:hover {
    border-color: #cfe3ae;
    color: var(--fd-green-dark);
    background: #f9fcf4;
}

.fd-jobs-table {
    min-width: 820px;
    margin: 4px 0 0;
    white-space: nowrap;
}

.fd-jobs-table th {
    padding: 11px 6px;
    border-bottom-color: var(--fd-border);
    color: #65738a;
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
}

.fd-jobs-table td {
    padding: 12px 6px;
    border-bottom-color: #f1f3f7;
    color: #33445f;
    font-size: 9.5px;
    vertical-align: middle;
}

.fd-job-name {
    color: var(--fd-text);
    font-weight: 700;
}

.fd-status {
    display: inline-flex;
    padding: 5px 7px;
    border-radius: 5px;
    font-size: 9px;
    font-weight: 600;
}

.fd-status.progress { color: #123d70; background: #edf2f7; }
.fd-status.completed { color: #5d971b; background: #f0f8e5; }
.fd-status.pending { color: #678a23; background: #f5f9ea; }
.fd-status.cancelled { color: #b9444d; background: #fff0f1; }

.fd-action-link {
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    border-radius: 6px;
    color: #66748b;
    text-decoration: none;
}

.fd-action-link:hover {
    color: var(--fd-green-dark);
    background: var(--fd-green-soft);
}

.fd-schedule-event {
    min-height: 45px;
    display: grid;
    grid-template-columns: 10px 58px 1fr;
    align-items: start;
    color: inherit;
    text-decoration: none;
}

.fd-schedule-event:hover .fd-schedule-info strong {
    color: var(--fd-green-dark);
}

.fd-schedule-dot {
    width: 8px;
    height: 8px;
    margin-top: 3px;
    border-radius: 50%;
    background: var(--fd-green);
}

.fd-schedule-time {
    padding-top: 1px;
    color: var(--fd-muted);
    font-size: 9px;
}

.fd-schedule-info strong,
.fd-schedule-info small {
    display: block;
}

.fd-schedule-info strong {
    color: var(--fd-text);
    font-size: 10px;
}

.fd-schedule-info small {
    margin-top: 2px;
    color: var(--fd-muted);
    font-size: 9px;
}

.fd-activity-item {
    display: flex;
    gap: 10px;
    padding: 8px 0;
}

.fd-activity-icon {
    width: 30px;
    height: 30px;
    flex: 0 0 30px;
    display: grid;
    place-items: center;
    border-radius: 9px;
}

.fd-activity-icon.green,
.fd-activity-icon.lime { color: var(--fd-green-dark); background: #f0f8e5; }
.fd-activity-icon.orange { color: #789d2c; background: #f4f9ea; }
.fd-activity-icon.blue { color: #123d70; background: #edf2f7; }

.fd-activity-content strong,
.fd-activity-content small {
    display: block;
}

.fd-activity-content strong {
    color: var(--fd-text);
    font-size: 10px;
}

.fd-activity-content small {
    margin-top: 2px;
    color: var(--fd-muted);
    font-size: 9px;
    line-height: 1.4;
}

.fd-bottom-card {
    position: relative;
    min-height: 132px;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 22px;
    overflow: hidden;
}

.fd-bottom-icon {
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    font-size: 22px;
}

.fd-bottom-content small,
.fd-bottom-content strong,
.fd-bottom-content span {
    display: block;
}

.fd-bottom-content small {
    color: var(--fd-muted);
    font-size: 10px;
    font-weight: 600;
}

.fd-bottom-content strong {
    margin-top: 5px;
    color: var(--fd-text);
    font-size: 23px;
    line-height: 1.1;
}

.fd-bottom-content span {
    margin-top: 7px;
    color: #33445f;
    font-size: 9px;
    font-weight: 700;
}

.fd-bottom-content .growth {
    color: var(--fd-green-dark);
}

.fd-empty {
    min-height: 120px;
    display: grid;
    place-items: center;
    padding: 20px;
    color: #9aa4b3;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1199.98px) {
    .fd-status-wrapper {
        gap: 14px;
    }
}

@media (max-width: 991.98px) {
    .fieldplx-topbar,
    body.fieldplx-sidebar-collapsed .fieldplx-topbar {
        margin-left: 0 !important;
        width: 100% !important;
    }

    .fieldplx-sidebar,
    body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
        width: 250px !important;
        min-width: 250px !important;
        transform: translateX(-100%);
    }

    body.fieldplx-sidebar-mobile-open .fieldplx-sidebar {
        transform: translateX(0) !important;
    }

    .fieldplx-main-content,
    body.fieldplx-sidebar-collapsed .fieldplx-main-content {
        margin-left: 0 !important;
    }

    .fieldplx-sidebar-brand-text,
    .fieldplx-sidebar-section-label,
    .fieldplx-sidebar-link-text,
    .fieldplx-sidebar-arrow,
    .fieldplx-sidebar-user-details,
    .fieldplx-sidebar-logout,
    .fieldplx-sidebar-submenu {
        display: initial;
    }
}

@media (max-width: 767.98px) {
    :root {
        --fieldplx-topbar-height: 64px;
    }

    .fieldplx-topbar,
    .fieldplx-topbar-inner {
        min-height: 64px !important;
    }

    .fieldplx-topbar-inner {
        padding: 0 13px !important;
    }

    .fieldplx-search-wrap {
        display: none !important;
    }

    .fd-dashboard {
        padding: 17px 13px 28px;
    }

    .fd-welcome {
        align-items: flex-start;
    }

    .fd-welcome h1 {
        font-size: 19px;
    }

    .fd-welcome p {
        max-width: 260px;
        font-size: 11px;
        line-height: 1.5;
    }

    .fd-date-button {
        min-width: 46px;
        width: 46px;
        padding: 0;
        justify-content: center;
    }

    .fd-date-button span,
    .fd-date-button .bi-chevron-down {
        display: none;
    }

    .fd-stat-card {
        min-height: 160px;
    }

    .fd-donut {
        width: 145px;
        height: 145px;
        flex-basis: 145px;
    }

    .fd-donut::before {
        width: 91px;
        height: 91px;
    }
}

@media (max-width: 420px) {
    .fd-welcome {
        min-height: 65px;
        gap: 10px;
    }

    .fd-date-actions {
        gap: 5px;
    }

    .fd-filter-button {
        width: 42px;
    }

    .fd-status-wrapper {
        transform: scale(.92);
        margin-inline: -14px;
    }
}
</style>

<div class="fd-dashboard">
    <section class="fd-welcome">
        <div>
            <h1>Welcome back, <?= e($userName); ?>!</h1>
            <p>Here is what is happening with <?= e($companyName); ?> today.</p>
        </div>

        <div class="fd-date-actions">
            <div class="fd-date-button" title="Current week">
                <i class="bi bi-calendar3"></i>
                <span><?= e($weekRangeLabel); ?></span>
                <i class="bi bi-chevron-down"></i>
            </div>

            <?php if ($canViewReports): ?>
                <a href="reports.php" class="fd-filter-button" title="Open reports">
                    <i class="bi bi-sliders"></i>
                </a>
            <?php endif; ?>
        </div>
    </section>

    <section class="row g-3 mb-3">
        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-stat-card">
                <span class="fd-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
                <div class="fd-stat-row">
                    <span class="fd-stat-icon blue"><i class="bi bi-briefcase"></i></span>
                    <div>
                        <span class="fd-stat-label">Total Jobs</span>
                        <strong class="fd-stat-value"><?= e(number_format($totalJobs)); ?></strong>
                        <small class="fd-growth <?= e($jobGrowth['direction']); ?>">
                            <strong>
                                <?php if ($jobGrowth['direction'] === 'up'): ?>
                                    <i class="bi bi-arrow-up"></i>
                                <?php elseif ($jobGrowth['direction'] === 'down'): ?>
                                    <i class="bi bi-arrow-down"></i>
                                <?php endif; ?>
                                <?= e($jobGrowth['text']); ?>
                            </strong>
                        </small>
                    </div>
                </div>
                <div class="fd-sparkline">
                    <canvas class="fd-spark" data-color="#123d70" data-values='<?= e(json_encode($jobsChartValues)); ?>'></canvas>
                </div>
            </article>
        </div>

        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-stat-card">
                <span class="fd-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
                <div class="fd-stat-row">
                    <span class="fd-stat-icon green"><i class="bi bi-check-lg"></i></span>
                    <div>
                        <span class="fd-stat-label">Completed Jobs</span>
                        <strong class="fd-stat-value"><?= e(number_format($completedJobs)); ?></strong>
                        <small class="fd-growth <?= e($completedGrowth['direction']); ?>">
                            <strong>
                                <?php if ($completedGrowth['direction'] === 'up'): ?>
                                    <i class="bi bi-arrow-up"></i>
                                <?php elseif ($completedGrowth['direction'] === 'down'): ?>
                                    <i class="bi bi-arrow-down"></i>
                                <?php endif; ?>
                                <?= e($completedGrowth['text']); ?>
                            </strong>
                        </small>
                    </div>
                </div>
                <div class="fd-sparkline">
                    <canvas class="fd-spark" data-color="#74b824" data-values='<?= e(json_encode($completedChartValues)); ?>'></canvas>
                </div>
            </article>
        </div>

        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-stat-card">
                <span class="fd-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
                <div class="fd-stat-row">
                    <span class="fd-stat-icon lime"><i class="bi bi-clock"></i></span>
                    <div>
                        <span class="fd-stat-label">In Progress</span>
                        <strong class="fd-stat-value"><?= e(number_format($inProgressJobs)); ?></strong>
                        <small class="fd-growth <?= e($progressGrowth['direction']); ?>">
                            <strong>
                                <?php if ($progressGrowth['direction'] === 'up'): ?>
                                    <i class="bi bi-arrow-up"></i>
                                <?php elseif ($progressGrowth['direction'] === 'down'): ?>
                                    <i class="bi bi-arrow-down"></i>
                                <?php endif; ?>
                                <?= e($progressGrowth['text']); ?>
                            </strong>
                        </small>
                    </div>
                </div>
                <div class="fd-sparkline">
                    <canvas class="fd-spark" data-color="#5d971b" data-values='<?= e(json_encode($progressSparkValues)); ?>'></canvas>
                </div>
            </article>
        </div>

        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-stat-card">
                <span class="fd-stat-more"><i class="bi bi-three-dots-vertical"></i></span>
                <div class="fd-stat-row">
                    <span class="fd-stat-icon orange"><i class="bi bi-cash-coin"></i></span>
                    <div>
                        <span class="fd-stat-label">Total Revenue</span>
                        <strong class="fd-stat-value">
                            <?= $canViewPayments ? e(dashboardMoney($totalRevenue, $currencyCode)) : '-'; ?>
                        </strong>
                        <small class="fd-growth <?= e($revenueGrowth['direction']); ?>">
                            <strong>
                                <?php if ($revenueGrowth['direction'] === 'up'): ?>
                                    <i class="bi bi-arrow-up"></i>
                                <?php elseif ($revenueGrowth['direction'] === 'down'): ?>
                                    <i class="bi bi-arrow-down"></i>
                                <?php endif; ?>
                                <?= e($revenueGrowth['text']); ?>
                            </strong>
                        </small>
                    </div>
                </div>
                <div class="fd-sparkline">
                    <canvas class="fd-spark" data-color="#96c945" data-values='<?= e(json_encode($revenueChartValues)); ?>'></canvas>
                </div>
            </article>
        </div>
    </section>

    <section class="row g-3 mb-3">
        <div class="col-xl-6">
            <article class="fd-card fd-panel fd-chart-card">
                <div class="fd-panel-title">
                    <h2>Jobs Overview</h2>
                    <div class="fd-chart-legend">
                        <span class="me-3"><i class="bi bi-square-fill me-1" style="color:#123d70"></i>Total Jobs</span>
                        <span><i class="bi bi-square-fill me-1" style="color:#74b824"></i>Completed Jobs</span>
                    </div>
                </div>
                <div class="fd-chart-area">
                    <canvas id="fdJobsChart"></canvas>
                </div>
            </article>
        </div>

        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-panel fd-chart-card">
                <div class="fd-panel-title"><h2>Jobs by Status</h2></div>
                <div class="fd-status-wrapper">
                    <div class="fd-donut" style="<?= e($donutStyle); ?>">
                        <div class="fd-donut-center">
                            <strong><?= e(number_format($statusTotal)); ?></strong>
                            <small>Total</small>
                        </div>
                    </div>
                    <div class="fd-status-legend">
                        <div class="fd-legend-row">
                            <span class="fd-legend-dot" style="background:#a7cf5b"></span>
                            <span>Pending<br><strong><?= e(number_format($pendingJobs)); ?></strong> (<?= e(number_format($pendingPct, 1)); ?>%)</span>
                        </div>
                        <div class="fd-legend-row">
                            <span class="fd-legend-dot" style="background:#123d70"></span>
                            <span>In Progress<br><strong><?= e(number_format($progressJobs)); ?></strong> (<?= e(number_format($progressPct, 1)); ?>%)</span>
                        </div>
                        <div class="fd-legend-row">
                            <span class="fd-legend-dot" style="background:#74b824"></span>
                            <span>Completed<br><strong><?= e(number_format($statusCompletedJobs)); ?></strong> (<?= e(number_format($completedPct, 1)); ?>%)</span>
                        </div>
                        <div class="fd-legend-row">
                            <span class="fd-legend-dot" style="background:#e45b66"></span>
                            <span>Cancelled<br><strong><?= e(number_format($cancelledJobs)); ?></strong> (<?= e(number_format($cancelledPct, 1)); ?>%)</span>
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-panel fd-chart-card">
                <div class="fd-panel-title">
                    <h2>Today's Tasks</h2>
                    <span class="fd-tasks-count"><?= e(number_format($todayTaskCount)); ?> Tasks</span>
                </div>

                <?php if ($canViewTasks && !empty($todayTasks)): ?>
                    <div class="fd-task-list">
                        <?php foreach ($todayTasks as $task): ?>
                            <?php $taskComplete = strtolower((string) $task['status']) === 'completed'; ?>
                            <a href="task-view.php?id=<?= (int) $task['id']; ?>" class="fd-task-item <?= $taskComplete ? 'complete' : ''; ?>">
                                <span class="fd-task-check">
                                    <?php if ($taskComplete): ?><i class="bi bi-check-lg"></i><?php endif; ?>
                                </span>
                                <span class="fd-task-content">
                                    <strong><?= e($task['title']); ?></strong>
                                    <small><?= e($task['client_name']); ?></small>
                                </span>
                                <span class="fd-task-time"><?= e(dashboardTime($task['due_at'])); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="fd-empty">
                        <?= $canViewTasks ? 'No tasks scheduled for today.' : 'Task access is not available for this account.'; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canViewTasks): ?>
                    <div class="fd-task-footer"><a href="tasks.php" class="fd-link">View all tasks &rarr;</a></div>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <section class="row g-3 mb-3">
        <div class="col-xl-9">
            <article class="fd-card fd-panel fd-recent-jobs-card">
                <div class="fd-panel-title">
                    <h2>Recent Jobs</h2>
                    <?php if ($canViewJobs): ?><a href="jobs.php" class="fd-view-button">View All Jobs</a><?php endif; ?>
                </div>

                <?php if (!empty($recentJobs)): ?>
                    <div class="table-responsive">
                        <table class="table fd-jobs-table">
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
                                <?php foreach ($recentJobs as $job): ?>
                                    <tr>
                                        <td><?= e($job['job_no']); ?></td>
                                        <td class="fd-job-name"><?= e($job['title']); ?></td>
                                        <td><?= e($job['client_name']); ?></td>
                                        <td><?= e($job['service_name']); ?></td>
                                        <td><?= e($job['worker_name']); ?></td>
                                        <td>
                                            <span class="fd-status <?= e(dashboardStatusClass($job['status'])); ?>">
                                                <?= e(function_exists('statusLabel') ? statusLabel($job['status']) : $job['status']); ?>
                                            </span>
                                        </td>
                                        <td><?= e(dashboardDate(!empty($job['end_date']) ? $job['end_date'] : $job['start_date'])); ?></td>
                                        <td>
                                            <a href="job-view.php?id=<?= (int) $job['id']; ?>" class="fd-action-link" title="View job">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3" style="color:#6f7b90;font-size:9px;">
                        <span>Showing the 5 most recent jobs</span>
                        <?php if ($canViewJobs): ?><a href="jobs.php" class="fd-link">Open jobs list &rarr;</a><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="fd-empty">No jobs have been created yet.</div>
                <?php endif; ?>
            </article>
        </div>

        <div class="col-xl-3">
            <div class="row g-3">
                <div class="col-xl-12 col-md-6">
                    <article class="fd-card fd-panel">
                        <div class="fd-panel-title">
                            <h2><?= e($schedulePanelTitle); ?></h2>
                            <?php if ($canViewSchedule): ?><a href="scheduling.php" class="fd-link">View Calendar</a><?php endif; ?>
                        </div>

                        <?php if (!empty($scheduleRows)): ?>
                            <?php $scheduleIndex = 0; ?>
                            <?php foreach ($scheduleRows as $visit): ?>
                                <?php
                                $scheduleColors = array('#74b824','#123d70','#96c945','#5d971b');
                                $dotColor = $scheduleColors[$scheduleIndex % count($scheduleColors)];
                                $scheduleIndex++;
                                ?>
                                <a href="job-view.php?id=<?= (int) $visit['job_id']; ?>" class="fd-schedule-event">
                                    <span class="fd-schedule-dot" style="background:<?= e($dotColor); ?>"></span>
                                    <span class="fd-schedule-time"><?= e(dashboardTime($visit['scheduled_start'])); ?></span>
                                    <span class="fd-schedule-info">
                                        <strong><?= e($visit['job_title']); ?></strong>
                                        <small><?= e($visit['client_name']); ?></small>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="fd-empty">No scheduled visits available.</div>
                        <?php endif; ?>

                        <?php if ($canViewSchedule): ?><a href="scheduling.php" class="fd-link">View full schedule &rarr;</a><?php endif; ?>
                    </article>
                </div>

                <div class="col-xl-12 col-md-6">
                    <article class="fd-card fd-panel">
                        <div class="fd-panel-title"><h2>Recent Activity</h2></div>

                        <?php if (!empty($recentActivity)): ?>
                            <?php foreach ($recentActivity as $activity): ?>
                                <?php $activityIcon = dashboardActivityIcon($activity['event_type']); ?>
                                <div class="fd-activity-item">
                                    <span class="fd-activity-icon <?= e($activityIcon[1]); ?>">
                                        <i class="bi <?= e($activityIcon[0]); ?>"></i>
                                    </span>
                                    <span class="fd-activity-content">
                                        <strong><?= e($activity['title']); ?></strong>
                                        <small><?= e(dashboardTimeAgo($activity['created_at'])); ?></small>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="fd-empty">No recent activity available.</div>
                        <?php endif; ?>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-3">
        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-bottom-card">
                <span class="fd-bottom-icon" style="color:#123d70;background:#edf2f7"><i class="bi bi-people"></i></span>
                <div class="fd-bottom-content">
                    <small>Total Clients</small>
                    <strong><?= e(number_format($totalClients)); ?></strong>
                    <span class="growth"><?= $canViewClients ? 'Active client records' : 'Dashboard total'; ?></span>
                </div>
            </article>
        </div>

        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-bottom-card">
                <span class="fd-bottom-icon" style="color:#74b824;background:#f0f8e5"><i class="bi bi-person-badge"></i></span>
                <div class="fd-bottom-content">
                    <small>Total Workers</small>
                    <strong><?= e(number_format($fieldWorkers)); ?></strong>
                    <span class="growth"><?= $canViewTeam ? 'Active field workers' : 'Dashboard total'; ?></span>
                </div>
            </article>
        </div>

        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-bottom-card">
                <span class="fd-bottom-icon" style="color:#5d971b;background:#eef7df"><i class="bi bi-receipt"></i></span>
                <div class="fd-bottom-content">
                    <small>Pending Invoices</small>
                    <strong><?= $canViewInvoices ? e(number_format($pendingInvoiceCount)) : '-'; ?></strong>
                    <span><?= $canViewInvoices ? e(dashboardMoney($pendingInvoiceAmount, $currencyCode)) : 'Restricted'; ?></span>
                </div>
            </article>
        </div>

        <div class="col-xl-3 col-md-6">
            <article class="fd-card fd-bottom-card">
                <span class="fd-bottom-icon" style="color:#96c945;background:#f4f9ea"><i class="bi bi-credit-card"></i></span>
                <div class="fd-bottom-content">
                    <small>Pending Payments</small>
                    <strong><?= $canViewPayments ? e(number_format($pendingPaymentCount)) : '-'; ?></strong>
                    <span><?= $canViewPayments ? e(dashboardMoney($pendingPaymentAmount, $currencyCode)) : 'Restricted'; ?></span>
                </div>
            </article>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';

    if (typeof Chart === 'undefined') {
        return;
    }

    var chartLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_SLASHES); ?>;
    var jobsValues = <?= json_encode($jobsChartValues, JSON_UNESCAPED_SLASHES); ?>;
    var completedValues = <?= json_encode($completedChartValues, JSON_UNESCAPED_SLASHES); ?>;

    var jobsCanvas = document.getElementById('fdJobsChart');
    if (jobsCanvas) {
        var jobsContext = jobsCanvas.getContext('2d');
        var blueGradient = jobsContext.createLinearGradient(0, 0, 0, 250);
        blueGradient.addColorStop(0, 'rgba(18,61,112,.22)');
        blueGradient.addColorStop(1, 'rgba(18,61,112,0)');

        var greenGradient = jobsContext.createLinearGradient(0, 0, 0, 250);
        greenGradient.addColorStop(0, 'rgba(116,184,36,.18)');
        greenGradient.addColorStop(1, 'rgba(116,184,36,0)');

        new Chart(jobsCanvas, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        label: 'Total Jobs',
                        data: jobsValues,
                        borderColor: '#123d70',
                        backgroundColor: blueGradient,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#123d70',
                        tension: 0.2
                    },
                    {
                        label: 'Completed Jobs',
                        data: completedValues,
                        borderColor: '#74b824',
                        backgroundColor: greenGradient,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#74b824',
                        tension: 0.2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#202743',
                        padding: 10,
                        titleFont: { size: 10 },
                        bodyFont: { size: 10 }
                    }
                },
                scales: {
                    x: {
                        border: { display: false },
                        grid: { display: false },
                        ticks: { color: '#6f7b90', font: { size: 8 } }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '#e5eaf1' },
                        ticks: { precision: 0, color: '#6f7b90', font: { size: 8 } }
                    }
                }
            }
        });
    }

    document.querySelectorAll('.fd-spark').forEach(function (canvas) {
        var color = canvas.getAttribute('data-color') || '#74b824';
        var rawValues = canvas.getAttribute('data-values') || '[]';
        var values = [];

        try {
            values = JSON.parse(rawValues);
        } catch (error) {
            values = [];
        }

        if (!values.length) {
            values = [0, 0, 0, 0, 0, 0, 0];
        }

        var context = canvas.getContext('2d');
        var gradient = context.createLinearGradient(0, 0, 0, 45);
        gradient.addColorStop(0, hexToRgba(color, .22));
        gradient.addColorStop(1, hexToRgba(color, 0));

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: values.map(function (_, index) { return index + 1; }),
                datasets: [{
                    data: values,
                    borderColor: color,
                    backgroundColor: gradient,
                    fill: true,
                    borderWidth: 1.5,
                    pointRadius: 0,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false } }
            }
        });
    });

    function hexToRgba(hex, opacity) {
        var value = hex.replace('#', '');
        var red = parseInt(value.substring(0, 2), 16);
        var green = parseInt(value.substring(2, 4), 16);
        var blue = parseInt(value.substring(4, 6), 16);
        return 'rgba(' + red + ',' + green + ',' + blue + ',' + opacity + ')';
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
