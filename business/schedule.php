<?php
/* FieldPlx Schedule Calendar - Version 1.1.0 - 2026-09-02 - Manage UI + Month View */
require_once __DIR__ . '/includes/auth.php';
if ((!isset($pdo) || !($pdo instanceof PDO)) && (!isset($db) || !($db instanceof PDO)) && file_exists(__DIR__ . '/includes/db.php')) {
    require_once __DIR__ . '/includes/db.php';
}

$pageTitle = 'Schedule';
$activePage = 'schedule';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function schDb()
{
    global $pdo, $db;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($db) && $db instanceof PDO) return $db;
    throw new RuntimeException('Database connection is not available.');
}

function schH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function schTable(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $q->execute(array(':t' => $table));
    $cache[$table] = ((int)$q->fetchColumn() > 0);
    return $cache[$table];
}

function schColumn(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute(array(':t' => $table, ':c' => $column));
    $cache[$key] = ((int)$q->fetchColumn() > 0);
    return $cache[$key];
}

function schRows(PDO $pdo, $sql, array $params)
{
    $q = $pdo->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function schValidDate($value)
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : '';
}

function schReadable($value)
{
    $value = trim((string)$value);
    if ($value === '') return '-';
    return ucwords(str_replace('_', ' ', $value));
}

function schInitials($name)
{
    $name = trim((string)$name);
    if ($name === '') return '?';
    $parts = preg_split('/\s+/', $name);
    $out = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $out .= strtoupper(substr($part, 0, 1));
        if (strlen($out) >= 2) break;
    }
    return $out !== '' ? $out : '?';
}

function schSplit($value, $separator)
{
    $value = trim((string)$value);
    if ($value === '') return array();
    $parts = explode($separator, $value);
    $out = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') $out[] = $part;
    }
    return $out;
}

function schEventClass($status)
{
    $status = strtolower(trim((string)$status));
    if (in_array($status, array('completed','closed','invoiced','ready_to_invoice'), true)) return 'completed';
    if (in_array($status, array('in_progress','travelling','arrived','paused'), true)) return 'progress';
    if (in_array($status, array('cancelled','archived','no_access'), true)) return 'cancelled';
    if (in_array($status, array('rescheduled','follow_up_required'), true)) return 'rescheduled';
    return 'scheduled';
}

function schBuildUrl(array $replace)
{
    $query = $_GET;
    foreach ($replace as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return 'schedule.php?' . http_build_query($query);
}

function schAssignLanes(array &$events)
{
    usort($events, function($a, $b) {
        $cmp = strcmp($a['start'], $b['start']);
        if ($cmp !== 0) return $cmp;
        return strcmp($a['end'], $b['end']);
    });
    $laneEnds = array();
    $maxLanes = 1;
    foreach ($events as $index => $event) {
        $startTs = strtotime($event['start']);
        $endTs = strtotime($event['end']);
        $lane = 0;
        while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $startTs) $lane++;
        $laneEnds[$lane] = max($startTs + 900, $endTs);
        $events[$index]['lane'] = $lane;
        if (($lane + 1) > $maxLanes) $maxLanes = $lane + 1;
    }
    foreach ($events as $index => $event) $events[$index]['lane_count'] = $maxLanes;
}

try {
    $pdo = schDb();
} catch (Throwable $e) {
    error_log('FieldPlx schedule DB: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load schedule.');
}

$tenantId = !empty($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : (!empty($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0);
$userId = !empty($_SESSION['tenant_user_id']) ? (int)$_SESSION['tenant_user_id'] : (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (!empty($_SESSION['id']) ? (int)$_SESSION['id'] : 0));
$sessionBranchId = !empty($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
if ($tenantId <= 0 || $userId <= 0) {
    header('Location: login.php');
    exit;
}

$view = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'week';
if (!in_array($view, array('day','week','month'), true)) $view = 'week';
$selectedDate = schValidDate(isset($_GET['date']) ? $_GET['date'] : '');
if ($selectedDate === '') $selectedDate = date('Y-m-d');
$employeeId = isset($_GET['employee_id']) ? max(0, (int)$_GET['employee_id']) : 0;
$branchId = isset($_GET['branch_id']) ? max(0, (int)$_GET['branch_id']) : 0;
if ($sessionBranchId > 0) $branchId = $sessionBranchId;
$statusFilter = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';

$selected = new DateTime($selectedDate . ' 00:00:00');
$currentMonthKey = $selected->format('Y-m');
$displayDays = array();

if ($view === 'month') {
    $monthStart = new DateTime($selected->format('Y-m-01') . ' 00:00:00');
    $monthEnd = new DateTime($selected->format('Y-m-t') . ' 00:00:00');

    $rangeStart = clone $monthStart;
    $firstDow = (int)$rangeStart->format('N');
    if ($firstDow > 1) $rangeStart->modify('-' . ($firstDow - 1) . ' days');

    $lastGridDay = clone $monthEnd;
    $lastDow = (int)$lastGridDay->format('N');
    if ($lastDow < 7) $lastGridDay->modify('+' . (7 - $lastDow) . ' days');

    $rangeEnd = clone $lastGridDay;
    $rangeEnd->modify('+1 day');

    $cursor = clone $rangeStart;
    while ($cursor < $rangeEnd) {
        $displayDays[] = clone $cursor;
        $cursor->modify('+1 day');
    }

    $heading = $selected->format('F Y');
    $prevDate = (clone $monthStart)->modify('-1 month')->format('Y-m-01');
    $nextDate = (clone $monthStart)->modify('+1 month')->format('Y-m-01');
} elseif ($view === 'week') {
    $dayNumber = (int)$selected->format('N');
    $rangeStart = clone $selected;
    if ($dayNumber > 1) $rangeStart->modify('-' . ($dayNumber - 1) . ' days');
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+7 days');
    for ($i = 0; $i < 7; $i++) {
        $d = clone $rangeStart;
        if ($i > 0) $d->modify('+' . $i . ' days');
        $displayDays[] = $d;
    }
    $heading = $rangeStart->format('d M') . ' - ' . (clone $rangeEnd)->modify('-1 day')->format('d M Y');
    $prevDate = (clone $selected)->modify('-7 days')->format('Y-m-d');
    $nextDate = (clone $selected)->modify('+7 days')->format('Y-m-d');
} else {
    $rangeStart = clone $selected;
    $rangeEnd = clone $selected;
    $rangeEnd->modify('+1 day');
    $displayDays = array(clone $selected);
    $heading = $selected->format('l, d F Y');
    $prevDate = (clone $selected)->modify('-1 day')->format('Y-m-d');
    $nextDate = (clone $selected)->modify('+1 day')->format('Y-m-d');
}

$employees = array();
$userDeletedCondition = schColumn($pdo, 'users', 'deleted_at') ? " AND deleted_at IS NULL" : '';
try {
    $employees = schRows($pdo,
        "SELECT id,branch_id,employee_code,first_name,last_name,email,job_title,is_field_worker,is_bookable,is_tenant_admin
         FROM users
         WHERE tenant_id=:t AND status='active' {$userDeletedCondition}
         ORDER BY first_name,last_name,id",
        array(':t' => $tenantId)
    );
} catch (Throwable $e) {
    error_log('FieldPlx schedule employees: ' . $e->getMessage());
}

$employeeMap = array();
foreach ($employees as $employee) {
    $employeeMap[(int)$employee['id']] = $employee;
}
if ($employeeId > 0 && !isset($employeeMap[$employeeId])) $employeeId = 0;

$branches = array();
if (schTable($pdo, 'branches')) {
    try {
        $branches = schRows($pdo, "SELECT id,name FROM branches WHERE tenant_id=:t AND status='active' ORDER BY is_head_office DESC,name", array(':t' => $tenantId));
    } catch (Throwable $e) {
        error_log('FieldPlx schedule branches: ' . $e->getMessage());
    }
}

$events = array();
$rangeStartSql = $rangeStart->format('Y-m-d H:i:s');
$rangeEndSql = $rangeEnd->format('Y-m-d H:i:s');

/* Recurring/expanded Job Cards use visits as the exact calendar occurrences. */
if (schTable($pdo, 'visits') && schTable($pdo, 'jobs')) {
    try {
        $hasVisitAssignments = schTable($pdo, 'visit_assignments');
        $visitJoin = '';
        $assigneeSelect = "'' AS assignee_names,'' AS assignee_ids";
        if ($hasVisitAssignments) {
            $visitJoin = " LEFT JOIN visit_assignments va ON va.tenant_id=v.tenant_id AND va.visit_id=v.id AND va.status<>'removed'
                           LEFT JOIN users au ON au.id=va.user_id AND au.tenant_id=v.tenant_id ";
            $assigneeSelect = "GROUP_CONCAT(DISTINCT CONCAT_WS(' ',au.first_name,au.last_name) ORDER BY va.is_primary DESC,au.first_name,au.id SEPARATOR '||') AS assignee_names,
                               GROUP_CONCAT(DISTINCT au.id ORDER BY va.is_primary DESC,au.id SEPARATOR ',') AS assignee_ids";
        } else {
            $visitJoin = " LEFT JOIN users au ON au.id=v.assigned_user_id AND au.tenant_id=v.tenant_id ";
            $assigneeSelect = "COALESCE(CONCAT_WS(' ',au.first_name,au.last_name),'') AS assignee_names,COALESCE(CAST(au.id AS CHAR),'') AS assignee_ids";
        }

        $where = array(
            'v.tenant_id=:tenant_id',
            'j.deleted_at IS NULL',
            'v.scheduled_start IS NOT NULL',
            'v.scheduled_start < :range_end',
            'COALESCE(v.scheduled_end,v.scheduled_start) >= :range_start'
        );
        $params = array(':tenant_id' => $tenantId, ':range_start' => $rangeStartSql, ':range_end' => $rangeEndSql);
        if ($branchId > 0) {
            $where[] = 'COALESCE(v.branch_id,j.branch_id)=:branch_id';
            $params[':branch_id'] = $branchId;
        }
        if ($employeeId > 0) {
            if ($hasVisitAssignments) {
                $where[] = "(v.assigned_user_id=:emp_primary OR EXISTS(SELECT 1 FROM visit_assignments vax WHERE vax.tenant_id=v.tenant_id AND vax.visit_id=v.id AND vax.user_id=:emp_multi AND vax.status<>'removed'))";
                $params[':emp_primary'] = $employeeId;
                $params[':emp_multi'] = $employeeId;
            } else {
                $where[] = 'v.assigned_user_id=:employee_id';
                $params[':employee_id'] = $employeeId;
            }
        }
        if ($statusFilter !== '') {
            $where[] = 'v.status=:visit_status';
            $params[':visit_status'] = $statusFilter;
        }

        $sql = "SELECT v.id AS visit_id,v.visit_no,v.visit_number,v.scheduled_start,v.scheduled_end,v.status AS event_status,v.notes AS visit_notes,
                       j.id AS job_id,j.job_no,j.title,j.description,j.priority,j.status AS job_status,j.job_type,j.client_id,j.location_id,j.product_service_id,
                       c.display_name AS client_name,cl.name AS location_name,cl.address_line1 AS location_address,cl.city AS location_city,
                       ps.name AS service_name,b.name AS branch_name,{$assigneeSelect}
                FROM visits v
                INNER JOIN jobs j ON j.id=v.job_id AND j.tenant_id=v.tenant_id
                LEFT JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id
                LEFT JOIN client_locations cl ON cl.id=j.location_id AND cl.tenant_id=j.tenant_id
                LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id
                LEFT JOIN branches b ON b.id=COALESCE(v.branch_id,j.branch_id) AND b.tenant_id=v.tenant_id
                {$visitJoin}
                WHERE " . implode(' AND ', $where) . "
                GROUP BY v.id
                ORDER BY v.scheduled_start,v.id";
        $visitRows = schRows($pdo, $sql, $params);
        foreach ($visitRows as $row) {
            $start = (string)$row['scheduled_start'];
            $end = !empty($row['scheduled_end']) ? (string)$row['scheduled_end'] : date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));
            $events[] = array(
                'source' => 'visit',
                'visit_id' => (int)$row['visit_id'],
                'visit_no' => (string)$row['visit_no'],
                'visit_number' => (int)$row['visit_number'],
                'job_id' => (int)$row['job_id'],
                'job_no' => (string)$row['job_no'],
                'title' => (string)$row['title'],
                'customer' => (string)$row['client_name'],
                'service' => (string)$row['service_name'],
                'location' => trim((string)$row['location_name'] . (!empty($row['location_city']) ? ', ' . $row['location_city'] : '')),
                'branch' => (string)$row['branch_name'],
                'priority' => (string)$row['priority'],
                'status' => (string)$row['event_status'],
                'job_status' => (string)$row['job_status'],
                'job_type' => (string)$row['job_type'],
                'notes' => (string)$row['visit_notes'],
                'start' => $start,
                'end' => $end,
                'assignee_names' => schSplit($row['assignee_names'], '||'),
                'assignee_ids' => schSplit($row['assignee_ids'], ','),
                'lane' => 0,
                'lane_count' => 1
            );
        }
    } catch (Throwable $e) {
        error_log('FieldPlx schedule visits query: ' . $e->getMessage());
    }
}

/* Compatibility fallback: older scheduled jobs that do not have visit rows yet. */
if (schTable($pdo, 'jobs') && schTable($pdo, 'job_assignments')) {
    try {
        $hasStartTime = schColumn($pdo, 'jobs', 'start_time');
        $hasEndTime = schColumn($pdo, 'jobs', 'end_time');
        $startExpr = $hasStartTime
            ? "STR_TO_DATE(CONCAT(j.start_date,' ',COALESCE(j.start_time,'09:00:00')),'%Y-%m-%d %H:%i:%s')"
            : "STR_TO_DATE(CONCAT(j.start_date,' 09:00:00'),'%Y-%m-%d %H:%i:%s')";
        $endExpr = $hasEndTime
            ? "STR_TO_DATE(CONCAT(COALESCE(j.end_date,j.start_date),' ',COALESCE(j.end_time,'10:00:00')),'%Y-%m-%d %H:%i:%s')"
            : "STR_TO_DATE(CONCAT(COALESCE(j.end_date,j.start_date),' 10:00:00'),'%Y-%m-%d %H:%i:%s')";
        $where = array(
            'j.tenant_id=:tenant_id',
            'j.deleted_at IS NULL',
            'j.start_date IS NOT NULL',
            "{$startExpr} < :range_end",
            "{$endExpr} >= :range_start"
        );
        $params = array(':tenant_id' => $tenantId, ':range_start' => $rangeStartSql, ':range_end' => $rangeEndSql);
        if (schTable($pdo, 'visits')) $where[] = 'NOT EXISTS(SELECT 1 FROM visits vx WHERE vx.tenant_id=j.tenant_id AND vx.job_id=j.id)';
        if ($branchId > 0) {
            $where[] = 'j.branch_id=:branch_id';
            $params[':branch_id'] = $branchId;
        }
        if ($employeeId > 0) {
            $where[] = "EXISTS(SELECT 1 FROM job_assignments jax WHERE jax.tenant_id=j.tenant_id AND jax.job_id=j.id AND jax.user_id=:employee_id AND jax.status<>'removed')";
            $params[':employee_id'] = $employeeId;
        }
        if ($statusFilter !== '') {
            $where[] = 'j.status=:job_status';
            $params[':job_status'] = $statusFilter;
        }

        $sql = "SELECT j.id AS job_id,j.job_no,j.title,j.description,j.priority,j.status AS event_status,j.job_type,
                       {$startExpr} AS scheduled_start,{$endExpr} AS scheduled_end,
                       c.display_name AS client_name,cl.name AS location_name,cl.city AS location_city,ps.name AS service_name,b.name AS branch_name,
                       GROUP_CONCAT(DISTINCT CONCAT_WS(' ',au.first_name,au.last_name) ORDER BY ja.is_primary_responsible DESC,au.first_name,au.id SEPARATOR '||') AS assignee_names,
                       GROUP_CONCAT(DISTINCT au.id ORDER BY ja.is_primary_responsible DESC,au.id SEPARATOR ',') AS assignee_ids
                FROM jobs j
                LEFT JOIN clients c ON c.id=j.client_id AND c.tenant_id=j.tenant_id
                LEFT JOIN client_locations cl ON cl.id=j.location_id AND cl.tenant_id=j.tenant_id
                LEFT JOIN product_services ps ON ps.id=j.product_service_id AND ps.tenant_id=j.tenant_id
                LEFT JOIN branches b ON b.id=j.branch_id AND b.tenant_id=j.tenant_id
                LEFT JOIN job_assignments ja ON ja.tenant_id=j.tenant_id AND ja.job_id=j.id AND ja.status<>'removed'
                LEFT JOIN users au ON au.id=ja.user_id AND au.tenant_id=j.tenant_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY j.id
                ORDER BY scheduled_start,j.id";
        $jobRows = schRows($pdo, $sql, $params);
        foreach ($jobRows as $row) {
            $start = (string)$row['scheduled_start'];
            $end = !empty($row['scheduled_end']) ? (string)$row['scheduled_end'] : date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));
            $events[] = array(
                'source' => 'job',
                'visit_id' => 0,
                'visit_no' => '',
                'visit_number' => 0,
                'job_id' => (int)$row['job_id'],
                'job_no' => (string)$row['job_no'],
                'title' => (string)$row['title'],
                'customer' => (string)$row['client_name'],
                'service' => (string)$row['service_name'],
                'location' => trim((string)$row['location_name'] . (!empty($row['location_city']) ? ', ' . $row['location_city'] : '')),
                'branch' => (string)$row['branch_name'],
                'priority' => (string)$row['priority'],
                'status' => (string)$row['event_status'],
                'job_status' => (string)$row['event_status'],
                'job_type' => (string)$row['job_type'],
                'notes' => (string)$row['description'],
                'start' => $start,
                'end' => $end,
                'assignee_names' => schSplit($row['assignee_names'], '||'),
                'assignee_ids' => schSplit($row['assignee_ids'], ','),
                'lane' => 0,
                'lane_count' => 1
            );
        }
    } catch (Throwable $e) {
        error_log('FieldPlx schedule fallback jobs query: ' . $e->getMessage());
    }
}

usort($events, function($a, $b) {
    $cmp = strcmp($a['start'], $b['start']);
    if ($cmp !== 0) return $cmp;
    return $a['job_id'] - $b['job_id'];
});

$eventsByDay = array();
$uniqueEmployees = array();
$progressCount = 0;
$completedCount = 0;
$scheduledCount = 0;
$minEventHour = 7;
$maxEventHour = 19;
foreach ($events as $event) {
    $dayKey = substr($event['start'], 0, 10);
    if (!isset($eventsByDay[$dayKey])) $eventsByDay[$dayKey] = array();
    $eventsByDay[$dayKey][] = $event;
    foreach ($event['assignee_ids'] as $eid) if ((int)$eid > 0) $uniqueEmployees[(int)$eid] = true;
    $eventClass = schEventClass($event['status']);
    if ($eventClass === 'progress') $progressCount++;
    elseif ($eventClass === 'completed') $completedCount++;
    elseif ($eventClass === 'scheduled' || $eventClass === 'rescheduled') $scheduledCount++;
    $startTs = strtotime($event['start']);
    $endTs = strtotime($event['end']);
    if ($startTs) $minEventHour = min($minEventHour, (int)date('G', $startTs));
    if ($endTs) $maxEventHour = max($maxEventHour, (int)date('G', $endTs) + ((int)date('i', $endTs) > 0 ? 1 : 0));
}
foreach ($eventsByDay as $key => $dayEvents) {
    schAssignLanes($dayEvents);
    $eventsByDay[$key] = $dayEvents;
}

$calendarStartHour = max(0, min(7, $minEventHour));
$calendarEndHour = min(24, max(20, $maxEventHour));
if (($calendarEndHour - $calendarStartHour) < 8) $calendarEndHour = min(24, $calendarStartHour + 8);
$slotHeight = 64;
$calendarHeight = ($calendarEndHour - $calendarStartHour) * $slotHeight;
$hours = array();
for ($hour = $calendarStartHour; $hour <= $calendarEndHour; $hour++) $hours[] = $hour;

$selectedEmployeeName = 'All Employees';
if ($employeeId > 0 && isset($employeeMap[$employeeId])) {
    $selectedEmployeeName = trim($employeeMap[$employeeId]['first_name'] . ' ' . $employeeMap[$employeeId]['last_name']);
}

$todayUrl = schBuildUrl(array('date' => date('Y-m-d')));
$previousUrl = schBuildUrl(array('date' => $prevDate));
$nextUrl = schBuildUrl(array('date' => $nextDate));
$dayUrl = schBuildUrl(array('view' => 'day'));
$weekUrl = schBuildUrl(array('view' => 'week'));
$monthUrl = schBuildUrl(array('view' => 'month'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schedule - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
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
  --fd-red: #e45b66;
  --fd-bg: #f6f8fb;
  --fd-text: #0b1933;
  --fd-muted: #6f7b90;
  --fd-border: #e5eaf1;
}

* { box-sizing: border-box; }
html, body { min-height: 100%; }
body {
  margin: 0;
  min-height: 100vh;
  overflow-x: hidden;
  background: var(--fd-bg) !important;
  color: var(--fd-text);
  font-family: Arial, Helvetica, sans-serif !important;
  font-size: 14px;
}

/* =========================================================
   Shared FieldPlx topbar
   ========================================================= */
.fieldplx-topbar {
  min-height: var(--fieldplx-topbar-height) !important;
  margin-left: var(--fieldplx-sidebar-width);
  width: calc(100% - var(--fieldplx-sidebar-width));
  position: sticky !important;
  top: 0;
  z-index: 1030;
  background: #ffffff !important;
  border-bottom: 1px solid var(--fd-border) !important;
  box-shadow: 0 3px 14px rgba(0, 17, 49, 0.035);
  backdrop-filter: none !important;
  transition: margin-left .25s ease, width .25s ease;
}

body.fieldplx-sidebar-collapsed .fieldplx-topbar {
  margin-left: var(--fieldplx-sidebar-collapsed-width);
  width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
}

.fieldplx-topbar-inner {
  min-height: var(--fieldplx-topbar-height) !important;
  padding: 0 27px !important;
  display: flex !important;
  align-items: center !important;
  gap: 13px !important;
}

.fieldplx-page-heading { display: none !important; }

.fieldplx-menu-toggle,
.fieldplx-topbar-action {
  width: 41px !important;
  height: 41px !important;
  padding: 0 !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
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
  position: relative;
}

.fieldplx-search-icon {
  position: absolute;
  top: 50%;
  left: 13px;
  z-index: 2;
  transform: translateY(-50%);
  color: #8795a8;
  pointer-events: none;
}

.fieldplx-search-input {
  width: 100%;
  height: 41px !important;
  padding: 8px 13px 8px 38px !important;
  border: 0 !important;
  border-radius: 8px !important;
  background: #f5f8fb !important;
  color: var(--fd-text) !important;
  box-shadow: none !important;
  font-size: 12px !important;
}

.fieldplx-search-input:focus {
  background: #f5f8fb !important;
  box-shadow: 0 0 0 3px rgba(116, 184, 36, .14) !important;
}

.fieldplx-profile-button {
  min-width: 0;
  padding: 2px !important;
  display: flex !important;
  align-items: center !important;
  gap: 9px !important;
  border: 0 !important;
  border-radius: 9px !important;
  background: transparent !important;
  text-align: left;
}

.fieldplx-profile-button:hover { background: var(--fd-green-soft) !important; }

.fieldplx-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  overflow: hidden;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border: 0 !important;
  border-radius: 50% !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg, #ffffff, #e8f3d9) !important;
  font-size: 12px !important;
  font-weight: 800 !important;
}

.fieldplx-avatar img { width: 100%; height: 100%; object-fit: cover; }
.fieldplx-profile-details { max-width: 145px; min-width: 0; }
.fieldplx-profile-name,
.fieldplx-profile-role { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.fieldplx-profile-name { color: var(--fd-text) !important; font-size: 12px !important; font-weight: 700; }
.fieldplx-profile-role { margin-top: 1px; color: var(--fd-muted) !important; font-size: 10px !important; }
.fieldplx-notification-count { background: var(--fd-red) !important; }

.fieldplx-dropdown,
.fieldplx-profile-menu {
  border: 1px solid var(--fd-border) !important;
  background: #ffffff !important;
  box-shadow: 0 18px 45px rgba(29, 38, 74, .14) !important;
}

.fieldplx-dropdown { width: 340px; max-width: calc(100vw - 24px); margin-top: 10px !important; border-radius: 14px !important; overflow: hidden; }
.fieldplx-dropdown-header { border-bottom: 1px solid var(--fd-border) !important; background: #fff !important; }
.fieldplx-dropdown-footer { border-top: 1px solid var(--fd-border) !important; background: #fff !important; }
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--fd-green-dark) !important; }
#topbarNotificationList { max-height: 300px; overflow-y: auto; background: #fff; }
.fieldplx-notification-item:hover,
.fieldplx-notification-item.is-unread { background: #f8fbf3 !important; }
.fieldplx-notification-icon { color: var(--fd-green-dark) !important; background: var(--fd-green-soft) !important; }
.fieldplx-empty-notifications { background: #fff !important; }
.fieldplx-empty-notifications i { color: #9fca68 !important; }
.fieldplx-profile-menu { width: 230px; border-radius: 12px !important; }

/* =========================================================
   Shared FieldPlx sidebar
   ========================================================= */
.fieldplx-sidebar {
  width: var(--fieldplx-sidebar-width) !important;
  min-width: var(--fieldplx-sidebar-width) !important;
  height: 100vh !important;
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  z-index: 1045 !important;
  display: flex !important;
  flex-direction: column !important;
  color: #fff !important;
  background: linear-gradient(180deg, var(--fd-navy-light), var(--fd-navy)) !important;
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
  display: flex !important;
  align-items: center !important;
  border-bottom: 1px solid rgba(255, 255, 255, .08) !important;
}

.fieldplx-sidebar-brand {
  min-width: 0;
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  color: #fff !important;
  text-decoration: none !important;
}

.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
  width: 40px !important;
  height: 40px !important;
  flex: 0 0 40px !important;
  border-radius: 10px !important;
}

.fieldplx-sidebar-logo { object-fit: contain; background: #fff !important; }
.fieldplx-sidebar-logo-placeholder {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  color: #fff !important;
  background: linear-gradient(135deg, #8fd236, #68aa1d) !important;
  font-size: 18px !important;
  font-weight: 700 !important;
}

.fieldplx-sidebar-brand-text { min-width: 0; display: block; }
.fieldplx-sidebar-company-name {
  max-width: 155px !important;
  display: block;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: #fff !important;
  font-size: 16px !important;
  font-weight: 700 !important;
}
.fieldplx-sidebar-product-name {
  margin-top: 1px;
  display: block;
  color: #9fda55 !important;
  font-size: 9px !important;
  font-weight: 600;
  letter-spacing: .4px;
  text-transform: uppercase;
}

.fieldplx-sidebar-close {
  width: 34px;
  height: 34px;
  margin-left: auto;
  padding: 0;
  display: none;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 8px;
  color: rgba(255,255,255,.88);
  background: rgba(255,255,255,.08);
}

.fieldplx-sidebar-body {
  min-height: 0 !important;
  flex: 1 1 auto !important;
  overflow-x: hidden !important;
  overflow-y: auto !important;
  padding: 12px 14px !important;
  scrollbar-width: none !important;
}
.fieldplx-sidebar-body::-webkit-scrollbar { display: none; }
.fieldplx-sidebar-section-label {
  margin: 7px 12px !important;
  color: rgba(255, 255, 255, .5) !important;
  font-size: 9px !important;
  font-weight: 700;
  letter-spacing: .65px;
  text-transform: uppercase;
}
.fieldplx-sidebar-nav { display: flex; flex-direction: column; gap: 3px !important; }
.fieldplx-sidebar-link {
  width: 100%;
  min-height: 46px !important;
  margin-bottom: 3px !important;
  padding: 0 14px !important;
  display: flex !important;
  align-items: center !important;
  gap: 15px !important;
  border: 0 !important;
  border-radius: 9px !important;
  color: rgba(255, 255, 255, .94) !important;
  background: transparent !important;
  text-align: left;
  text-decoration: none !important;
  font-family: inherit;
  font-size: 14px !important;
  font-weight: 600 !important;
}
.fieldplx-sidebar-link:hover { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link {
  color: #fff !important;
  background: linear-gradient(90deg, #7fc92d, #68aa1d) !important;
  box-shadow: 0 6px 18px rgba(0,17,49,.28) !important;
}
.fieldplx-sidebar-link-icon {
  width: 21px !important;
  height: 21px !important;
  flex: 0 0 21px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  font-size: 19px !important;
}
.fieldplx-sidebar-link-text {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.fieldplx-sidebar-arrow {
  margin-left: auto;
  color: rgba(255,255,255,.65) !important;
  font-size: 10px;
  transition: transform .2s ease;
}
.fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow { transform: rotate(180deg); }
.fieldplx-sidebar-submenu {
  display: block;
  max-height: 0;
  overflow: hidden;
  padding: 0 0 0 36px !important;
  transition: max-height .25s ease, padding-top .25s ease, padding-bottom .25s ease;
}
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu {
  max-height: 680px;
  padding-top: 4px !important;
  padding-bottom: 5px !important;
}
.fieldplx-sidebar-sublink {
  min-height: 34px !important;
  padding: 7px 9px;
  display: flex;
  align-items: center;
  border-radius: 7px;
  color: rgba(255,255,255,.72) !important;
  text-decoration: none;
  font-size: 11px !important;
  font-weight: 500;
}
.fieldplx-sidebar-sublink::before {
  width: 5px;
  height: 5px;
  margin-right: 9px;
  flex: 0 0 5px;
  content: "";
  border-radius: 50%;
  background: rgba(255,255,255,.35) !important;
}
.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-sublink.active::before { background: #9fda55 !important; }

.fieldplx-sidebar-footer {
  flex: 0 0 auto !important;
  padding: 10px 14px 14px !important;
  border-top: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-user {
  min-height: 62px;
  padding: 8px;
  display: flex !important;
  align-items: center !important;
  gap: 9px;
  border-radius: 10px;
  background: rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-user-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  overflow: hidden;
  border-radius: 50% !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg,#fff,#e8f3d9) !important;
}
.fieldplx-sidebar-user-avatar img { width: 100%; height: 100%; object-fit: cover; }
.fieldplx-sidebar-user-details { min-width: 0; flex: 1; }
.fieldplx-sidebar-user-name,
.fieldplx-sidebar-user-role { display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.fieldplx-sidebar-user-name { color: #fff !important; font-size: 12px !important; font-weight: 700; }
.fieldplx-sidebar-user-role { margin-top: 1px; color: rgba(255,255,255,.6) !important; font-size: 9px !important; }
.fieldplx-sidebar-logout {
  width: 29px;
  height: 29px;
  flex: 0 0 29px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  color: rgba(255,255,255,.7) !important;
  text-decoration: none;
}
.fieldplx-sidebar-logout:hover { color: #fff !important; background: rgba(228,91,102,.3) !important; }
.fieldplx-sidebar-overlay { display: none; }

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout { display: none; }
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header { justify-content: center !important; padding-left: 8px !important; padding-right: 8px !important; }
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link { justify-content: center !important; padding-left: 8px !important; padding-right: 8px !important; }
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user { justify-content: center !important; padding-left: 5px !important; padding-right: 5px !important; }

/* =========================================================
   Main content and footer
   ========================================================= */
.fieldplx-main-layout { display: block !important; min-height: calc(100vh - var(--fieldplx-topbar-height)) !important; }
.fieldplx-main-content {
  margin-left: var(--fieldplx-sidebar-width);
  min-width: 0;
  transition: margin-left .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-main-content { margin-left: var(--fieldplx-sidebar-collapsed-width); }
.fieldplx-content-wrapper { padding: 0 !important; }
.fieldplx-footer {
  display: block !important;
  min-height: 52px;
  margin-left: var(--fieldplx-sidebar-width) !important;
  border-top: 1px solid var(--fieldplx-border);
  background: #fff;
  transition: margin-left .22s ease, background-color .22s ease !important;
}
body.fieldplx-sidebar-collapsed .fieldplx-footer { margin-left: var(--fieldplx-sidebar-collapsed-width) !important; }
.fieldplx-footer-inner {
  min-height: 52px;
  padding: 10px 18px;
  display: flex;
  align-items: center;
  gap: 18px;
  color: #6b7280;
  font-size: 10px;
}
.fieldplx-footer-links { display: flex; align-items: center; gap: 8px; }
.fieldplx-footer-links a { color: #6b7280; text-decoration: none; }
.fieldplx-footer-links a:hover { color: var(--fieldplx-primary); }
.fieldplx-footer-product { margin-left: auto; white-space: nowrap; color: #9ca3af; }
.fieldplx-footer-product strong { color: var(--fieldplx-primary); font-weight: 700; }

/* =========================================================
   Reports page
   ========================================================= */
.fd-dashboard {
  width: 100%;
  max-width: 1600px;
  margin: auto;
  padding: 25px 27px 35px;
}
.fd-dashboard .row > * { min-width: 0; }

.fr-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  margin-bottom: 18px;
}
.fr-title { margin: 0 0 7px; color: var(--fd-text); font-size: 21px; line-height: 1.2; font-weight: 700; }
.fr-sub { margin: 0; max-width: 820px; color: var(--fd-muted); font-size: 10.5px; line-height: 1.55; }
.fr-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.fr-btn {
  min-height: 39px;
  padding: 0 13px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  border: 1px solid var(--fd-border);
  border-radius: 8px;
  color: #43546c;
  background: #fff;
  box-shadow: 0 4px 12px rgba(31,43,88,.04);
  font-size: 10px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  transition: border-color .16s ease, color .16s ease, background .16s ease, box-shadow .16s ease;
}
.fr-btn:hover { border-color: #cfe3ae; color: var(--fd-green-dark); background: #f9fcf4; }
.fr-btn.primary {
  border-color: var(--fd-green);
  color: #fff;
  background: linear-gradient(90deg,#7fc92d,#68aa1d);
  box-shadow: 0 7px 16px rgba(104,170,29,.18);
}
.fr-btn.primary:hover { color: #fff; background: linear-gradient(90deg,#74b824,#5d971b); }

.fr-filter-card,
.fr-card {
  border: 1px solid var(--fd-border);
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 3px 12px rgba(24,45,76,.035);
}
.fr-filter-card { padding: 13px 14px; margin-bottom: 16px; }
.fr-filter { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
.fr-field { min-width: 160px; }
.fr-field label {
  display: block;
  margin-bottom: 6px;
  color: #506784;
  font-size: 9px;
  line-height: 1.2;
  font-weight: 600;
  text-transform: uppercase;
}
.fr-input {
  width: 100%;
  height: 39px;
  padding: 8px 10px;
  border: 1px solid #dde4ec;
  border-radius: 8px;
  color: #33445f;
  background: #fff;
  font-size: 10px;
  outline: 0;
}
.fr-input:focus { border-color: #a9cf75; box-shadow: 0 0 0 3px rgba(116,184,36,.11); }
.fr-input:disabled { color: #8490a0; background: #f6f8fa; cursor: not-allowed; }
.fr-filter-spacer { margin-left: auto; }

.fr-summary { margin-bottom: 16px; }
.fr-stat {
  height: 100%;
  min-height: 112px;
  padding: 18px 20px;
  border: 1px solid #dfe6ef;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 3px 12px rgba(24,45,76,.035);
}
.fr-stat-row { min-height: 72px; display: flex; align-items: center; gap: 18px; }
.fr-stat-row > div { min-width: 0; }
.fr-stat-icon {
  width: 58px;
  height: 58px;
  flex: 0 0 58px;
  display: grid;
  place-items: center;
  border-radius: 16px;
  color: #fff;
  background: #123f73;
  font-size: 25px;
}
.fr-stat-icon i { line-height: 1; }
.fr-stat-label { display: block; margin-bottom: 8px; color: #506784; font-size: 13px; line-height: 1.2; font-weight: 400; }
.fr-stat-value {
  display: block;
  max-width: 100%;
  overflow: hidden;
  color: #020b16;
  font-size: 27px;
  line-height: 1.05;
  font-weight: 700;
  letter-spacing: -.35px;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.fr-stat-note { display: block; margin-top: 7px; color: #8a96a7; font-size: 8.5px; line-height: 1.35; }

.fr-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 16px; margin-bottom: 16px; }
.fr-card { min-width: 0; overflow: hidden; }
.fr-card-head {
  min-height: 54px;
  padding: 13px 15px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid var(--fd-border);
  background: #fbfcfd;
}
.fr-card-head > div { min-width: 0; }
.fr-card-head h2 { margin: 0; color: var(--fd-text); font-size: 13px; line-height: 1.25; font-weight: 700; }
.fr-card-head p { margin: 3px 0 0; color: var(--fd-muted); font-size: 9px; line-height: 1.35; }
.fr-card-head > i { flex: 0 0 auto; color: var(--fd-green-dark) !important; font-size: 16px; }
.fr-card-body { padding: 14px; }

.fr-status-list { display: flex; flex-direction: column; gap: 8px; }
.fr-status-row {
  min-height: 42px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border: 1px solid #eef1f5;
  border-radius: 8px;
  background: #fff;
}
.fr-status-row:hover { border-color: #e2e8ef; background: #fbfcfd; }
.fr-status-dot { width: 8px; height: 8px; flex: 0 0 8px; border-radius: 50%; background: var(--fd-green); }
.fr-status-name { min-width: 0; flex: 1; overflow: hidden; color: #33445f; font-size: 10px; text-transform: capitalize; text-overflow: ellipsis; white-space: nowrap; }
.fr-status-count { color: var(--fd-navy); font-size: 10px; font-weight: 700; }
.fr-status-amount { min-width: 90px; text-align: right; color: #6f7b90; font-size: 9px; }

.fr-table-wrap {
  width: 100%;
  overflow-x: auto;
  overflow-y: hidden;
  scrollbar-width: thin;
  scrollbar-color: #9aa0a6 transparent;
}
.fr-table-wrap::-webkit-scrollbar { height: 3px; }
.fr-table-wrap::-webkit-scrollbar-track { background: transparent; }
.fr-table-wrap::-webkit-scrollbar-thumb { border-radius: 999px; background: #9aa0a6; }
.fr-table { width: 100%; min-width: 780px; margin: 0; border-collapse: collapse; white-space: nowrap; }
.fr-table th {
  padding: 11px 12px;
  border-bottom: 1px solid var(--fd-border);
  color: #65738a;
  background: #f8fafc;
  font-size: 9px;
  font-weight: 600;
  text-align: left;
  text-transform: uppercase;
}
.fr-table td {
  padding: 12px;
  border-bottom: 1px solid #f1f3f7;
  color: #33445f;
  font-size: 9.5px;
  vertical-align: middle;
}
.fr-table tbody tr:last-child td { border-bottom: 0; }
.fr-table tbody tr:hover { background: #fbfcfa; }
.fr-name { display: block; color: var(--fd-text); font-weight: 700; }
.fr-muted { display: block; margin-top: 2px; color: #8d98a8; font-size: 8.5px; }
.fr-badge {
  display: inline-flex;
  align-items: center;
  padding: 5px 7px;
  border-radius: 5px;
  color: #5d971b;
  background: #f0f8e5;
  font-size: 8.5px;
  font-weight: 600;
  text-transform: capitalize;
}
.fr-badge.blue { color: #123d70; background: #edf2f7; }
.fr-badge.gray { color: #6f7b90; background: #eef2f6; }
.fr-empty { padding: 28px 18px !important; color: #9aa4b3 !important; text-align: center !important; font-size: 10px !important; }
.fr-section-gap { margin-top: 16px; }

/* =========================================================
   Responsive shell and reports
   ========================================================= */
@media (max-width: 991.98px) {
  html, body { overflow-x: hidden !important; }
  body.fieldplx-sidebar-mobile-open { overflow: hidden !important; }

  .fieldplx-topbar,
  body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: 0 !important;
    width: 100% !important;
  }

  .fieldplx-main-content,
  body.fieldplx-sidebar-collapsed .fieldplx-main-content {
    margin-left: 0 !important;
    width: 100% !important;
  }

  .fieldplx-footer,
  body.fieldplx-sidebar-collapsed .fieldplx-footer { margin-left: 0 !important; }

  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: min(300px, calc(100vw - 52px)) !important;
    min-width: 0 !important;
    max-width: 300px !important;
    height: 100vh !important;
    height: 100dvh !important;
    position: fixed !important;
    top: 0 !important;
    bottom: 0 !important;
    left: 0 !important;
    z-index: 1060 !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    visibility: hidden !important;
    transform: translate3d(-100%,0,0) !important;
    border-right: 0 !important;
    box-shadow: none !important;
    filter: none !important;
    transition: transform .25s ease, visibility .25s ease !important;
    will-change: transform;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
  body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    visibility: visible !important;
    transform: translate3d(0,0,0) !important;
  }

  .fieldplx-sidebar-header,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
    flex: 0 0 auto !important;
    justify-content: flex-start !important;
    padding-left: 14px !important;
    padding-right: 10px !important;
  }

  .fieldplx-sidebar-close {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
  }

  .fieldplx-sidebar-body {
    min-height: 0 !important;
    flex: 1 1 auto !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
  }
  .fieldplx-sidebar-footer { flex: 0 0 auto !important; }

  .fieldplx-sidebar-brand-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
  .fieldplx-sidebar-section-label,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
  .fieldplx-sidebar-link-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
  .fieldplx-sidebar-user-details,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details { display: block !important; }

  .fieldplx-sidebar-arrow,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
  .fieldplx-sidebar-logout,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout { display: inline-flex !important; }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user { justify-content: flex-start !important; }

  .fieldplx-sidebar-submenu,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
    display: block !important;
    max-height: 0 !important;
    overflow: hidden !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    transition: max-height .25s ease, padding-top .25s ease, padding-bottom .25s ease !important;
  }
  .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu {
    display: block !important;
    max-height: 680px !important;
    padding-top: 4px !important;
    padding-bottom: 5px !important;
  }

  .fieldplx-sidebar-overlay {
    position: fixed !important;
    inset: 0 !important;
    z-index: 1055 !important;
    display: block !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
    background: rgba(0,17,49,.48) !important;
    transition: opacity .25s ease, visibility .25s ease !important;
  }
  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
    visibility: visible !important;
    opacity: 1 !important;
    pointer-events: auto !important;
  }

  .fieldplx-brand-mobile { display: flex !important; }
  .fieldplx-page-heading { display: none !important; }
  .fieldplx-profile-details { display: none; }
  .fr-grid { grid-template-columns: 1fr; }
}

@media (max-width: 767.98px) {
  :root { --fieldplx-topbar-height: 64px; }
  .fieldplx-topbar,
  .fieldplx-topbar-inner { min-height: 64px !important; }
  .fieldplx-topbar-inner { padding: 0 13px !important; gap: 8px !important; }
  .fieldplx-search-wrap { display: none !important; }
  .fieldplx-dropdown { width: min(330px, calc(100vw - 22px)); }

  .fd-dashboard { padding: 17px 13px 28px; }
  .fr-head { flex-direction: column; gap: 13px; }
  .fr-title { font-size: 19px; }
  .fr-sub { max-width: 100%; font-size: 10.5px; }
  .fr-actions { width: 100%; }
  .fr-actions .fr-btn { flex: 1; }

  .fr-filter { align-items: stretch; }
  .fr-field { width: 100%; min-width: 0; }
  .fr-filter-spacer { display: none; }
  .fr-filter .fr-btn { flex: 1; }

  .fr-stat { min-height: 102px; padding: 15px 17px; }
  .fr-stat-row { min-height: 66px; gap: 15px; }
  .fr-stat-icon { width: 54px; height: 54px; flex-basis: 54px; border-radius: 15px; font-size: 23px; }
  .fr-stat-value { font-size: 24px; }
  .fr-card-head { align-items: flex-start; }
  .fr-card-head .fr-btn { min-height: 34px; padding: 0 10px; }

  .fieldplx-footer-inner { padding: 12px; flex-wrap: wrap; justify-content: center; gap: 7px 14px; text-align: center; }
  .fieldplx-footer-product { width: 100%; margin-left: 0; }
}

@media (max-width: 575.98px) {
  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar { width: min(288px, calc(100vw - 44px)) !important; }
  .fieldplx-sidebar-body { padding-left: 10px !important; padding-right: 10px !important; }
  .fieldplx-sidebar-link { min-height: 43px !important; padding-left: 12px !important; padding-right: 12px !important; gap: 12px !important; font-size: 13px !important; }
  .fieldplx-sidebar-submenu { padding-left: 31px !important; }
  .fieldplx-sidebar-sublink { min-height: 33px !important; font-size: 11px !important; }

  .fr-status-row { gap: 8px; }
  .fr-status-amount { min-width: 74px; }
  .fr-card-head { padding: 12px; }
  .fr-card-body { padding: 12px; }
}

/* =========================================================
   Schedule manage page
   ========================================================= */
:root{--sch-hour-height:64px}
.fd-dashboard{width:100%;max-width:1600px;margin:0 auto;padding:25px 27px 35px}
.sch-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px}
.sch-title{margin:0 0 7px;color:var(--fd-text);font-size:21px;line-height:1.2;font-weight:700}
.sch-sub{max-width:760px;margin:0;color:var(--fd-muted);font-size:10.5px;line-height:1.55}
.sch-head-actions{display:flex;align-items:center;gap:8px}
.sch-btn{min-height:39px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;color:#43546c;background:#fff;text-decoration:none!important;font-size:10px;font-weight:700;cursor:pointer;transition:.15s ease}
.sch-btn:hover{border-color:#c9dcae;color:var(--fd-green-dark);background:#fbfef8}
.sch-btn.primary{border-color:var(--fd-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);box-shadow:0 7px 16px rgba(104,170,29,.16)}
.sch-btn.primary:hover{color:#fff;background:linear-gradient(90deg,#75bb27,#5f9f18)}

.sch-filter-card{margin-bottom:16px;padding:13px;border:1px solid var(--fd-border);border-radius:10px;background:#fff;box-shadow:0 4px 14px rgba(31,43,88,.035)}
.sch-filter{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap}
.sch-field{min-width:150px}.sch-field.employee{min-width:260px;flex:1 1 280px}
.sch-field label{display:block;margin:0 0 5px;color:#778499;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.sch-control{width:100%;height:39px;padding:0 10px;border:1px solid #dfe5ec;border-radius:8px;background:#fff;color:#2b3f5a;font-family:inherit;font-size:10px;outline:0}
.sch-control:focus{border-color:#a7cd79;box-shadow:0 0 0 3px rgba(116,184,36,.10)}
.sch-filter-spacer{flex:1 1 16px}
.sch-selected{height:39px;padding:0 11px;display:flex;align-items:center;gap:8px;border:1px solid #dce9cc;border-radius:8px;background:#f7fbf1;color:#465d2d;font-size:9px;font-weight:700;white-space:nowrap}
.sch-selected-avatar{width:25px;height:25px;display:grid;place-items:center;border-radius:50%;background:#fff;color:var(--fd-green-dark);font-size:8px;font-weight:800}
.select2-container{font-size:10px}.select2-container .select2-selection--single{height:39px!important;border:1px solid #dfe5ec!important;border-radius:8px!important}.select2-container--default .select2-selection--single .select2-selection__rendered{height:37px;line-height:37px!important;padding-left:10px!important;color:#2b3f5a!important}.select2-container--default .select2-selection--single .select2-selection__arrow{height:37px!important}.select2-dropdown{border-color:#dfe5ec!important;border-radius:8px!important;overflow:hidden;font-size:10px}.select2-search__field{border:1px solid #dfe5ec!important;border-radius:6px!important;outline:0!important}.select2-results__option--highlighted.select2-results__option--selectable{background:var(--fd-green)!important}

.sch-stats{margin-bottom:16px}
.sch-manage-stat{height:100%;min-height:112px;padding:18px 20px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035);position:relative}
.sch-overview-title,.sch-metric-title{display:block;color:#55677f;font-size:9.5px;font-weight:700}
.sch-overview-list{margin-top:12px;display:grid;gap:7px}
.sch-overview-row{display:flex;align-items:center;justify-content:space-between;gap:10px;color:#748197;font-size:8.5px}
.sch-overview-row span{display:flex;align-items:center;gap:7px}.sch-overview-row i{font-size:6px;color:var(--fd-green)}.sch-overview-row strong{color:#24364f;font-size:9px;font-weight:700}
.sch-metric-period{display:block;margin-top:5px;color:#909bab;font-size:8px}.sch-metric-value{display:block;margin-top:13px;color:#10203a;font-size:28px;line-height:1;font-weight:700}.sch-metric-note{display:block;margin-top:7px;color:#8491a3;font-size:8px}
.sch-stat-arrow{width:28px;height:28px;position:absolute;top:15px;right:15px;display:grid;place-items:center;border:1px solid #e4eaf0;border-radius:8px;background:#fafcfd;color:#75859a;font-size:11px}

.sch-calendar-card{overflow:hidden;border:1px solid var(--fd-border);border-radius:11px;background:#fff;box-shadow:0 4px 14px rgba(31,43,88,.04)}
.sch-toolbar{min-height:67px;padding:12px 14px;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;border-bottom:1px solid var(--fd-border);background:#fff}
.sch-nav,.sch-view-switch{display:flex;align-items:center;gap:6px}.sch-view-switch{justify-content:flex-end}
.sch-nav-btn,.sch-view-btn{height:35px;padding:0 11px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid #dde4eb;border-radius:8px;background:#fff;color:#52647b;text-decoration:none!important;font-size:9px;font-weight:700;transition:.15s ease}.sch-nav-btn.square{width:35px;padding:0}.sch-nav-btn:hover,.sch-view-btn:hover{border-color:#c5d9a7;color:var(--fd-green-dark);background:#fbfef8}.sch-view-btn.active{border-color:#b9da90;background:var(--fd-green-soft);color:var(--fd-green-dark)}
.sch-calendar-heading{text-align:center}.sch-calendar-heading h2{margin:0;color:#172842;font-size:16px;font-weight:700}.sch-calendar-heading small{display:block;margin-top:4px;color:#8b97a7;font-size:8px}
.sch-calendar-scroll{overflow:auto;background:#fff}

/* Day / week time grid */
.sch-week-head{min-width:900px;display:grid;grid-template-columns:70px repeat(7,minmax(118px,1fr));position:sticky;top:0;z-index:7;border-bottom:1px solid var(--fd-border);background:#fff}.sch-day-view .sch-week-head{min-width:600px;grid-template-columns:70px minmax(500px,1fr)}
.sch-time-head{border-right:1px solid #edf0f4}.sch-day-head{min-height:64px;padding:9px 8px;display:flex;flex-direction:column;align-items:center;justify-content:center;border-right:1px solid #edf0f4;color:#69788d;text-align:center}.sch-day-head:last-child{border-right:0}.sch-day-head .dow{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.08em}.sch-day-head .date{width:30px;height:30px;margin-top:4px;display:grid;place-items:center;border-radius:50%;color:#273b57;font-size:11px;font-weight:700}.sch-day-head.today .date{background:var(--fd-green);color:#fff}.sch-day-head .count{margin-top:3px;color:#9aa5b4;font-size:7px}
.sch-calendar-body{min-width:900px;display:grid;grid-template-columns:70px repeat(7,minmax(118px,1fr));position:relative}.sch-day-view .sch-calendar-body{min-width:600px;grid-template-columns:70px minmax(500px,1fr)}
.sch-time-axis{height:var(--sch-calendar-height);position:relative;border-right:1px solid #e9edf2;background:#fbfcfd}.sch-time-label{height:var(--sch-hour-height);padding:0 9px;position:absolute;left:0;right:0;transform:translateY(-6px);color:#8794a5;font-size:8px;text-align:right}.sch-day-column{height:var(--sch-calendar-height);position:relative;border-right:1px solid #edf0f4;background-image:repeating-linear-gradient(to bottom,transparent 0,transparent calc(var(--sch-hour-height) - 1px),#edf1f4 calc(var(--sch-hour-height) - 1px),#edf1f4 var(--sch-hour-height));background-color:#fff}.sch-day-column:last-child{border-right:0}.sch-day-column.today{background-color:#fcfef9}
.sch-event{position:absolute;z-index:3;min-height:34px;padding:6px 7px;overflow:hidden;border:1px solid #ccdbea;border-left:3px solid #4678a8;border-radius:7px;background:#f5f9fd;color:#203650;text-decoration:none!important;box-shadow:0 2px 6px rgba(30,49,76,.06);transition:.12s ease}.sch-event:hover{z-index:8;transform:translateY(-1px);box-shadow:0 7px 18px rgba(27,45,72,.14);border-color:#9fc66f;color:#203650}.sch-event.scheduled{border-left-color:#4678a8;background:#f5f9fd}.sch-event.progress{border-left-color:#d39527;background:#fffaf0}.sch-event.completed{border-left-color:#74b824;background:#f6faef}.sch-event.rescheduled{border-left-color:#8b69bd;background:#faf7ff}.sch-event.cancelled{border-left-color:#df6269;background:#fff7f7;opacity:.75}.sch-event-time{display:flex;align-items:center;gap:4px;color:#61738a;font-size:7.5px;font-weight:700;white-space:nowrap}.sch-event-dot{width:5px;height:5px;flex:0 0 5px;border-radius:50%;background:currentColor}.sch-event-title{display:block;margin-top:3px;overflow:hidden;color:#1b304b;font-size:8.5px;font-weight:700;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}.sch-event-meta{display:block;margin-top:2px;overflow:hidden;color:#7a899c;font-size:7.2px;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}.sch-event-team{margin-top:4px;display:flex;align-items:center;gap:3px;overflow:hidden}.sch-mini-avatar{width:18px;height:18px;flex:0 0 18px;display:grid;place-items:center;border:1px solid #fff;border-radius:50%;background:#e9f3dc;color:#4f7923;font-size:6px;font-weight:800}.sch-team-more{color:#77869a;font-size:7px;font-weight:700}.sch-now-line{height:1px;position:absolute;left:0;right:0;z-index:5;background:#df4d56;pointer-events:none}.sch-now-line:before{content:'';width:7px;height:7px;position:absolute;left:-3px;top:-3px;border-radius:50%;background:#df4d56}

/* Month calendar */
.sch-month-wrap{overflow:auto;background:#fff}.sch-month-weekdays{min-width:980px;display:grid;grid-template-columns:repeat(7,minmax(140px,1fr));border-bottom:1px solid var(--fd-border);background:#fbfcfd}.sch-month-weekday{height:40px;display:flex;align-items:center;justify-content:center;border-right:1px solid #edf0f4;color:#76859a;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.08em}.sch-month-weekday:last-child{border-right:0}
.sch-month-grid{min-width:980px;display:grid;grid-template-columns:repeat(7,minmax(140px,1fr));background:#edf0f4;gap:1px}.sch-month-day{min-height:148px;padding:8px;background:#fff}.sch-month-day.outside{background:#fafbfd}.sch-month-day.today{background:#fcfef9;box-shadow:inset 0 0 0 1px #c8e0a8}.sch-month-day.selected{box-shadow:inset 0 0 0 2px rgba(116,184,36,.38)}.sch-month-day-head{height:28px;display:flex;align-items:center;justify-content:space-between;gap:8px}.sch-month-date{width:27px;height:27px;display:grid;place-items:center;border-radius:50%;color:#3e526d;font-size:9px;font-weight:700}.sch-month-day.outside .sch-month-date{color:#a5afbc}.sch-month-day.today .sch-month-date{color:#fff;background:var(--fd-green)}.sch-month-count{color:#9aa5b3;font-size:7px}.sch-month-events{margin-top:5px;display:grid;gap:4px}.sch-month-event{padding:6px 7px;display:block;overflow:hidden;border:1px solid #dbe5ee;border-left:3px solid #4678a8;border-radius:6px;background:#f7fafd;color:#203650;text-decoration:none!important;transition:.12s ease}.sch-month-event:hover{border-color:#acd07f;background:#f8fced;color:#203650}.sch-month-event.progress{border-left-color:#d39527;background:#fffaf0}.sch-month-event.completed{border-left-color:#74b824;background:#f6faef}.sch-month-event.rescheduled{border-left-color:#8b69bd;background:#faf7ff}.sch-month-event.cancelled{border-left-color:#df6269;background:#fff7f7;opacity:.76}.sch-month-event-time{display:flex;align-items:center;gap:4px;color:#63748a;font-size:7px;font-weight:700}.sch-month-event-title{display:block;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#21354f;font-size:8px;font-weight:700}.sch-month-event-meta{display:block;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#8290a1;font-size:6.8px}.sch-month-more{height:25px;display:flex;align-items:center;padding:0 5px;color:var(--fd-green-dark);text-decoration:none!important;font-size:7.5px;font-weight:700}

.sch-legend{min-height:48px;padding:10px 14px;display:flex;align-items:center;gap:13px;flex-wrap:wrap;border-top:1px solid var(--fd-border);background:#fff;color:#718095;font-size:8px}.sch-legend-item{display:flex;align-items:center;gap:5px}.sch-legend-dot{width:7px;height:7px;border-radius:50%;background:#4678a8}.sch-legend-dot.progress{background:#d39527}.sch-legend-dot.completed{background:#74b824}.sch-legend-dot.rescheduled{background:#8b69bd}.sch-legend-dot.cancelled{background:#df6269}.sch-legend-note{margin-left:auto;color:#93a0af}.sch-no-events{padding:8px 14px;border-bottom:1px solid #edf0f4;background:#fffdf5;color:#8a7330;font-size:8.5px}.sch-no-events i{margin-right:5px}

@media(max-width:1199.98px){.sch-toolbar{grid-template-columns:auto 1fr auto}.sch-manage-stat{min-height:108px}}
@media(max-width:991.98px){.sch-head{flex-direction:column}.sch-head-actions{width:100%}.sch-head-actions .sch-btn{flex:1}}
@media(max-width:767.98px){.fd-dashboard{padding:17px 13px 28px}.sch-title{font-size:19px}.sch-sub{max-width:100%}.sch-filter{align-items:stretch}.sch-field,.sch-field.employee{width:100%;min-width:0;flex:1 1 100%}.sch-filter-spacer{display:none}.sch-selected{width:100%}.sch-filter .sch-btn{flex:1}.sch-toolbar{grid-template-columns:1fr;gap:8px}.sch-calendar-heading{grid-row:1}.sch-nav{grid-row:2;justify-content:center}.sch-view-switch{grid-row:3;justify-content:center}.sch-week-head,.sch-calendar-body{min-width:820px}.sch-day-view .sch-week-head,.sch-day-view .sch-calendar-body{min-width:560px}.sch-month-weekdays,.sch-month-grid{min-width:910px}.sch-month-day{min-height:138px}.sch-legend-note{width:100%;margin-left:0}}
@media(max-width:575.98px){.sch-head-actions{display:grid;grid-template-columns:1fr 1fr}.sch-head-actions .sch-btn.primary{grid-column:1/-1}.sch-manage-stat{padding:15px}.sch-metric-value{font-size:24px}}

    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="fd-dashboard">
                <section class="sch-head">
                    <div>
                        <h1 class="sch-title">Schedule</h1>
                        <p class="sch-sub">View employee Job Card assignments and recurring visits in day, week or month calendar format.</p>
                    </div>
                    <div class="sch-head-actions">
                        <a class="sch-btn" href="jobs.php"><i class="bi bi-briefcase"></i> Jobs</a>
                        <a class="sch-btn" href="schedule.php"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
                        <a class="sch-btn primary" href="job-form.php"><i class="bi bi-plus-lg"></i> Create Job Card</a>
                    </div>
                </section>

                <section class="sch-filter-card">
                    <form class="sch-filter" id="scheduleFilter" method="get" action="schedule.php">
                        <input type="hidden" name="view" value="<?= schH($view) ?>">
                        <div class="sch-field employee">
                            <label>Employee</label>
                            <select class="sch-control" id="employeeFilter" name="employee_id">
                                <option value="0">All Employees</option>
                                <?php foreach ($employees as $employee): ?>
                                    <?php $empName = trim($employee['first_name'] . ' ' . $employee['last_name']); ?>
                                    <option value="<?= (int)$employee['id'] ?>" <?= $employeeId === (int)$employee['id'] ? 'selected' : '' ?>>
                                        <?= schH($empName) ?><?= !empty($employee['job_title']) ? ' · ' . schH($employee['job_title']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sch-field">
                            <label>Date</label>
                            <input class="sch-control" type="date" name="date" value="<?= schH($selectedDate) ?>">
                        </div>
                        <div class="sch-field">
                            <label>Branch</label>
                            <select class="sch-control" name="branch_id" <?= $sessionBranchId > 0 ? 'disabled' : '' ?>>
                                <option value="0">All Branches</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?= (int)$branch['id'] ?>" <?= $branchId === (int)$branch['id'] ? 'selected' : '' ?>><?= schH($branch['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($sessionBranchId > 0): ?><input type="hidden" name="branch_id" value="<?= (int)$sessionBranchId ?>"><?php endif; ?>
                        </div>
                        <div class="sch-field">
                            <label>Status</label>
                            <select class="sch-control" name="status">
                                <option value="">All Status</option>
                                <?php foreach (array('scheduled','accepted','travelling','arrived','in_progress','paused','rescheduled','follow_up_required','completed','cancelled','no_access') as $statusOption): ?>
                                    <option value="<?= schH($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= schH(schReadable($statusOption)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sch-filter-spacer"></div>
                        <div class="sch-selected" title="Current employee filter">
                            <span class="sch-selected-avatar"><?= schH(schInitials($selectedEmployeeName)) ?></span>
                            <span><?= schH($selectedEmployeeName) ?></span>
                        </div>
                        <button class="sch-btn primary" type="submit"><i class="bi bi-funnel"></i> Apply</button>
                    </form>
                </section>

                <?php
                    $viewPeriodText = $view === 'month' ? 'Selected month' : ($view === 'week' ? 'Selected week' : 'Selected day');
                ?>
                <section class="row g-3 sch-stats">
                    <div class="col-xl-3 col-md-6">
                        <article class="sch-manage-stat">
                            <span class="sch-overview-title">Overview</span>
                            <div class="sch-overview-list">
                                <div class="sch-overview-row"><span><i class="bi bi-circle-fill"></i> Scheduled work</span><strong><?= count($events) ?></strong></div>
                                <div class="sch-overview-row"><span><i class="bi bi-circle-fill"></i> Employees scheduled</span><strong><?= count($uniqueEmployees) ?></strong></div>
                                <div class="sch-overview-row"><span><i class="bi bi-circle-fill"></i> In progress</span><strong><?= (int)$progressCount ?></strong></div>
                                <div class="sch-overview-row"><span><i class="bi bi-circle-fill"></i> Completed</span><strong><?= (int)$completedCount ?></strong></div>
                            </div>
                        </article>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <article class="sch-manage-stat">
                            <span class="sch-metric-title">Scheduled Work</span>
                            <span class="sch-metric-period"><?= schH($viewPeriodText) ?></span>
                            <strong class="sch-metric-value"><?= count($events) ?></strong>
                            <span class="sch-metric-note">Job Cards and recurring visits</span>
                            <span class="sch-stat-arrow"><i class="bi bi-arrow-up-right"></i></span>
                        </article>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <article class="sch-manage-stat">
                            <span class="sch-metric-title">In Progress</span>
                            <span class="sch-metric-period"><?= schH($viewPeriodText) ?></span>
                            <strong class="sch-metric-value"><?= (int)$progressCount ?></strong>
                            <span class="sch-metric-note">Work currently underway</span>
                            <span class="sch-stat-arrow"><i class="bi bi-arrow-up-right"></i></span>
                        </article>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <article class="sch-manage-stat">
                            <span class="sch-metric-title">Completed</span>
                            <span class="sch-metric-period"><?= schH($viewPeriodText) ?></span>
                            <strong class="sch-metric-value"><?= (int)$completedCount ?></strong>
                            <span class="sch-metric-note"><?= count($uniqueEmployees) ?> employee<?= count($uniqueEmployees) === 1 ? '' : 's' ?> scheduled</span>
                            <span class="sch-stat-arrow"><i class="bi bi-arrow-up-right"></i></span>
                        </article>
                    </div>
                </section>

                <section class="sch-calendar-card <?= $view === 'day' ? 'sch-day-view' : ($view === 'month' ? 'sch-month-view' : '') ?>" style="--sch-calendar-height:<?= (int)$calendarHeight ?>px;--sch-hour-height:<?= (int)$slotHeight ?>px;">
                    <div class="sch-toolbar">
                        <div class="sch-nav">
                            <a class="sch-nav-btn square" href="<?= schH($previousUrl) ?>" title="Previous <?= schH($view) ?>"><i class="bi bi-chevron-left"></i></a>
                            <a class="sch-nav-btn" href="<?= schH($todayUrl) ?>">Today</a>
                            <a class="sch-nav-btn square" href="<?= schH($nextUrl) ?>" title="Next <?= schH($view) ?>"><i class="bi bi-chevron-right"></i></a>
                        </div>
                        <div class="sch-calendar-heading">
                            <h2><?= schH($heading) ?></h2>
                            <small><?= schH($selectedEmployeeName) ?> · <?= count($events) ?> scheduled item<?= count($events) === 1 ? '' : 's' ?></small>
                        </div>
                        <div class="sch-view-switch">
                            <a class="sch-view-btn <?= $view === 'day' ? 'active' : '' ?>" href="<?= schH($dayUrl) ?>"><i class="bi bi-calendar-day"></i> Day</a>
                            <a class="sch-view-btn <?= $view === 'week' ? 'active' : '' ?>" href="<?= schH($weekUrl) ?>"><i class="bi bi-calendar-week"></i> Week</a>
                            <a class="sch-view-btn <?= $view === 'month' ? 'active' : '' ?>" href="<?= schH($monthUrl) ?>"><i class="bi bi-calendar3"></i> Month</a>
                        </div>
                    </div>

                    <?php if (!$events): ?>
                        <div class="sch-no-events"><i class="bi bi-info-circle"></i>No scheduled jobs found for the selected employee/filter. The calendar is still shown so you can navigate to another date.</div>
                    <?php endif; ?>

                    <?php if ($view === 'month'): ?>
                        <div class="sch-month-wrap">
                            <div class="sch-month-weekdays">
                                <?php foreach (array('Mon','Tue','Wed','Thu','Fri','Sat','Sun') as $weekday): ?><div class="sch-month-weekday"><?= schH($weekday) ?></div><?php endforeach; ?>
                            </div>
                            <div class="sch-month-grid">
                                <?php foreach ($displayDays as $day): ?>
                                    <?php
                                        $dayKey = $day->format('Y-m-d');
                                        $dayEvents = isset($eventsByDay[$dayKey]) ? $eventsByDay[$dayKey] : array();
                                        $outside = $day->format('Y-m') !== $currentMonthKey;
                                        $isToday = $dayKey === date('Y-m-d');
                                        $isSelected = $dayKey === $selectedDate;
                                        $maxMonthEvents = 4;
                                    ?>
                                    <div class="sch-month-day <?= $outside ? 'outside' : '' ?> <?= $isToday ? 'today' : '' ?> <?= $isSelected ? 'selected' : '' ?>">
                                        <div class="sch-month-day-head">
                                            <span class="sch-month-date"><?= schH($day->format('d')) ?></span>
                                            <span class="sch-month-count"><?= count($dayEvents) ?> job<?= count($dayEvents) === 1 ? '' : 's' ?></span>
                                        </div>
                                        <div class="sch-month-events">
                                            <?php foreach (array_slice($dayEvents, 0, $maxMonthEvents) as $event): ?>
                                                <?php
                                                    $startTs = strtotime($event['start']);
                                                    $endTs = strtotime($event['end']);
                                                    if (!$endTs || $endTs <= $startTs) $endTs = $startTs + 3600;
                                                    $eventClass = schEventClass($event['status']);
                                                    $teamText = implode(', ', $event['assignee_names']);
                                                    $tooltip = $event['job_no'] . ' · ' . $event['title'] . "\n" . date('h:i A', $startTs) . ' - ' . date('h:i A', $endTs) . "\n" . ($event['customer'] ?: 'No customer') . ($teamText !== '' ? "\n" . $teamText : '');
                                                ?>
                                                <a class="sch-month-event <?= schH($eventClass) ?>" href="job-view.php?job_id=<?= (int)$event['job_id'] ?>" title="<?= schH($tooltip) ?>">
                                                    <span class="sch-month-event-time"><span class="sch-event-dot"></span><?= schH(date('h:i A', $startTs)) ?><?= $event['source'] === 'visit' && $event['visit_number'] > 0 ? ' · V' . (int)$event['visit_number'] : '' ?></span>
                                                    <span class="sch-month-event-title"><?= schH($event['job_no']) ?> · <?= schH($event['title']) ?></span>
                                                    <span class="sch-month-event-meta"><?= schH($event['customer'] ?: 'Customer not set') ?><?= $event['assignee_names'] ? ' · ' . schH(implode(', ', array_slice($event['assignee_names'], 0, 2))) : '' ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                            <?php if (count($dayEvents) > $maxMonthEvents): ?>
                                                <a class="sch-month-more" href="<?= schH(schBuildUrl(array('view'=>'day','date'=>$dayKey))) ?>">+<?= count($dayEvents) - $maxMonthEvents ?> more · View day</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="sch-calendar-scroll">
                            <div class="sch-week-head">
                                <div class="sch-time-head"></div>
                                <?php foreach ($displayDays as $day): ?>
                                    <?php $dayKey = $day->format('Y-m-d'); $dayCount = isset($eventsByDay[$dayKey]) ? count($eventsByDay[$dayKey]) : 0; ?>
                                    <div class="sch-day-head <?= $dayKey === date('Y-m-d') ? 'today' : '' ?>">
                                        <span class="dow"><?= schH($day->format('D')) ?></span>
                                        <span class="date"><?= schH($day->format('d')) ?></span>
                                        <span class="count"><?= (int)$dayCount ?> job<?= $dayCount === 1 ? '' : 's' ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="sch-calendar-body" data-start-hour="<?= (int)$calendarStartHour ?>" data-end-hour="<?= (int)$calendarEndHour ?>" data-slot-height="<?= (int)$slotHeight ?>">
                                <div class="sch-time-axis">
                                    <?php foreach ($hours as $hour): ?>
                                        <?php $top = ($hour - $calendarStartHour) * $slotHeight; ?>
                                        <div class="sch-time-label" style="top:<?= (int)$top ?>px"><?= schH(date('g A', mktime($hour,0,0,1,1,2026))) ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php foreach ($displayDays as $day): ?>
                                    <?php
                                        $dayKey = $day->format('Y-m-d');
                                        $dayEvents = isset($eventsByDay[$dayKey]) ? $eventsByDay[$dayKey] : array();
                                    ?>
                                    <div class="sch-day-column <?= $dayKey === date('Y-m-d') ? 'today' : '' ?>" data-day="<?= schH($dayKey) ?>">
                                        <?php foreach ($dayEvents as $event): ?>
                                            <?php
                                                $startTs = strtotime($event['start']);
                                                $endTs = strtotime($event['end']);
                                                if (!$endTs || $endTs <= $startTs) $endTs = $startTs + 3600;
                                                $dayStartTs = strtotime($dayKey . ' ' . sprintf('%02d:00:00', $calendarStartHour));
                                                $dayEndTs = strtotime($dayKey . ' ' . sprintf('%02d:00:00', $calendarEndHour));
                                                if ($calendarEndHour === 24) $dayEndTs = strtotime($dayKey . ' 00:00:00 +1 day');
                                                $visibleStart = max($startTs, $dayStartTs);
                                                $visibleEnd = min($endTs, $dayEndTs);
                                                $topPx = max(0, (($visibleStart - $dayStartTs) / 3600) * $slotHeight);
                                                $heightPx = max(36, (($visibleEnd - $visibleStart) / 3600) * $slotHeight);
                                                if (($topPx + $heightPx) > $calendarHeight) $heightPx = max(30, $calendarHeight - $topPx - 2);
                                                $laneCount = max(1, (int)$event['lane_count']);
                                                $lane = max(0, (int)$event['lane']);
                                                $widthPercent = 100 / $laneCount;
                                                $leftPercent = $lane * $widthPercent;
                                                $eventClass = schEventClass($event['status']);
                                                $timeText = date('h:i A', $startTs) . ' - ' . date('h:i A', $endTs);
                                                $teamNames = $event['assignee_names'];
                                                $tooltip = $event['job_no'] . ' · ' . $event['title'] . "\n" . $timeText . "\n" . ($event['customer'] ?: 'No customer') . "\n" . implode(', ', $teamNames);
                                            ?>
                                            <a class="sch-event <?= schH($eventClass) ?>" href="job-view.php?job_id=<?= (int)$event['job_id'] ?>" title="<?= schH($tooltip) ?>" style="top:<?= number_format($topPx, 2, '.', '') ?>px;height:<?= number_format($heightPx, 2, '.', '') ?>px;left:calc(<?= number_format($leftPercent, 4, '.', '') ?>% + 4px);width:calc(<?= number_format($widthPercent, 4, '.', '') ?>% - 8px);">
                                                <span class="sch-event-time"><span class="sch-event-dot"></span><?= schH($timeText) ?><?= $event['source'] === 'visit' && $event['visit_number'] > 0 ? ' · V' . (int)$event['visit_number'] : '' ?></span>
                                                <span class="sch-event-title"><?= schH($event['job_no']) ?> · <?= schH($event['title']) ?></span>
                                                <span class="sch-event-meta"><?= schH($event['customer'] ?: 'Customer not set') ?><?= $event['service'] !== '' ? ' · ' . schH($event['service']) : '' ?></span>
                                                <?php if ($heightPx >= 62 && $event['location'] !== ''): ?><span class="sch-event-meta"><i class="bi bi-geo-alt"></i> <?= schH($event['location']) ?></span><?php endif; ?>
                                                <?php if ($heightPx >= 82 && $teamNames): ?>
                                                    <span class="sch-event-team">
                                                        <?php foreach (array_slice($teamNames, 0, 3) as $teamName): ?><span class="sch-mini-avatar" title="<?= schH($teamName) ?>"><?= schH(schInitials($teamName)) ?></span><?php endforeach; ?>
                                                        <?php if (count($teamNames) > 3): ?><span class="sch-team-more">+<?= count($teamNames) - 3 ?></span><?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="sch-legend">
                        <span class="sch-legend-item"><span class="sch-legend-dot"></span> Scheduled</span>
                        <span class="sch-legend-item"><span class="sch-legend-dot progress"></span> In Progress</span>
                        <span class="sch-legend-item"><span class="sch-legend-dot completed"></span> Completed</span>
                        <span class="sch-legend-item"><span class="sch-legend-dot rescheduled"></span> Rescheduled / Follow-up</span>
                        <span class="sch-legend-item"><span class="sch-legend-dot cancelled"></span> Cancelled / No Access</span>
                        <span class="sch-legend-note">Click a calendar item to open Job View.</span>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
    'use strict';
    if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
        jQuery('#employeeFilter').select2({width:'100%',placeholder:'All Employees'});
    }
    var form=document.getElementById('scheduleFilter');
    if(form){
        var autoFields=form.querySelectorAll('select[name="branch_id"],select[name="status"],input[name="date"]');
        Array.prototype.forEach.call(autoFields,function(el){el.addEventListener('change',function(){form.submit();});});
        if(window.jQuery){jQuery('#employeeFilter').on('change',function(){form.submit();});}
    }

    function placeNowLine(){
        var body=document.querySelector('.sch-calendar-body');
        if(!body)return;
        var now=new Date();
        var y=now.getFullYear();
        var m=String(now.getMonth()+1).padStart(2,'0');
        var d=String(now.getDate()).padStart(2,'0');
        var key=y+'-'+m+'-'+d;
        var column=document.querySelector('.sch-day-column[data-day="'+key+'"]');
        if(!column)return;
        var startHour=parseInt(body.getAttribute('data-start-hour')||'7',10);
        var endHour=parseInt(body.getAttribute('data-end-hour')||'20',10);
        var slotHeight=parseFloat(body.getAttribute('data-slot-height')||'64');
        var minute=now.getHours()*60+now.getMinutes();
        if(minute < startHour*60 || minute > endHour*60)return;
        var top=((minute-startHour*60)/60)*slotHeight;
        var line=document.createElement('div');
        line.className='sch-now-line';
        line.style.top=top+'px';
        line.title='Current time';
        column.appendChild(line);
    }
    placeNowLine();
})();
</script>
</body>
</html>
