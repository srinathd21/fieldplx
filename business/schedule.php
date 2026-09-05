<?php
/* FieldPlx Schedule Calendar - Version 2.0.0 - 2026-09-06 - Month / Week / Day resource schedule */
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
    $parts = preg_split('/\\s+/', $name);
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

function schCsvInts($value)
{
    $out = array();
    foreach (explode(',', trim((string)$value)) as $part) {
        $id = (int)trim($part);
        if ($id > 0) $out[$id] = $id;
    }
    return array_values($out);
}

function schCsvStrings($value, array $allowed)
{
    $out = array();
    foreach (explode(',', trim((string)$value)) as $part) {
        $part = strtolower(trim($part));
        if ($part !== '' && in_array($part, $allowed, true)) $out[$part] = $part;
    }
    return array_values($out);
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

function schMoney($value)
{
    return number_format((float)$value, 2, '.', ',');
}

function schLocationText(array $row)
{
    $parts = array();
    foreach (array('location_name','location_address','location_address2','location_city','location_state','location_postal') as $key) {
        if (!empty($row[$key])) $parts[] = trim((string)$row[$key]);
    }
    return implode(', ', $parts);
}

function schIsAnytime(array $event)
{
    $start = substr((string)$event['start'], 11, 8);
    $end = substr((string)$event['end'], 11, 8);
    return ($start === '00:00:00' && ($end === '23:59:59' || $end === '00:00:00'));
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

if (empty($_SESSION['schedule_csrf_token'])) {
    $_SESSION['schedule_csrf_token'] = bin2hex(random_bytes(32));
}
$scheduleCsrfToken = (string)$_SESSION['schedule_csrf_token'];

$currency = array('symbol' => '', 'symbol_position' => 'before', 'decimal_places' => 2);
try {
    if (schTable($pdo, 'tenants') && schTable($pdo, 'currencies')) {
        $stmt = $pdo->prepare("SELECT c.symbol,c.symbol_position,c.decimal_places FROM tenants t LEFT JOIN currencies c ON c.id=t.currency_id WHERE t.id=:tenant_id LIMIT 1");
        $stmt->execute(array(':tenant_id' => $tenantId));
        $currencyRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($currencyRow) $currency = array_merge($currency, $currencyRow);
    }
} catch (Throwable $e) {
    error_log('FieldPlx schedule currency: ' . $e->getMessage());
}

/* Lightweight schedule actions used by the preview/details UI. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $token = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!hash_equals($scheduleCsrfToken, $token)) {
        http_response_code(419);
        echo json_encode(array('success' => false, 'message' => 'Your session token has expired. Refresh the page and try again.'));
        exit;
    }
    $action = trim((string)$_POST['schedule_action']);
    if ($action === 'mark_complete') {
        $visitId = isset($_POST['visit_id']) ? (int)$_POST['visit_id'] : 0;
        $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        try {
            if ($visitId > 0 && schTable($pdo, 'visits')) {
                $stmt = $pdo->prepare("UPDATE visits v INNER JOIN jobs j ON j.id=v.job_id AND j.tenant_id=v.tenant_id SET v.status='completed' WHERE v.id=:visit_id AND v.tenant_id=:tenant_id AND j.deleted_at IS NULL");
                $stmt->execute(array(':visit_id' => $visitId, ':tenant_id' => $tenantId));
                if ($stmt->rowCount() < 1) throw new RuntimeException('Visit could not be marked complete.');
            } elseif ($jobId > 0) {
                $stmt = $pdo->prepare("UPDATE jobs SET status='completed' WHERE id=:job_id AND tenant_id=:tenant_id AND deleted_at IS NULL");
                $stmt->execute(array(':job_id' => $jobId, ':tenant_id' => $tenantId));
                if ($stmt->rowCount() < 1) throw new RuntimeException('Job could not be marked complete.');
            } else {
                throw new RuntimeException('Invalid schedule item.');
            }
            echo json_encode(array('success' => true, 'message' => 'Visit marked complete.'));
        } catch (Throwable $e) {
            error_log('FieldPlx schedule complete: ' . $e->getMessage());
            http_response_code(422);
            echo json_encode(array('success' => false, 'message' => $e->getMessage()));
        }
        exit;
    }
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => 'Unsupported schedule action.'));
    exit;
}

$view = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'month';
if (!in_array($view, array('day','week','month'), true)) $view = 'month';
$selectedDate = schValidDate(isset($_GET['date']) ? $_GET['date'] : '');
if ($selectedDate === '') $selectedDate = date('Y-m-d');
$branchId = isset($_GET['branch_id']) ? max(0, (int)$_GET['branch_id']) : 0;
if ($sessionBranchId > 0) $branchId = $sessionBranchId;

$typeFilter = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : 'all';
if (!in_array($typeFilter, array('all','visit','job'), true)) $typeFilter = 'all';
$teamFilterActive = isset($_GET['team_filter']) && (int)$_GET['team_filter'] === 1;
$selectedTeamIds = schCsvInts(isset($_GET['team_ids']) ? $_GET['team_ids'] : '');
$includeUnassigned = $teamFilterActive ? (!empty($_GET['include_unassigned']) ? 1 : 0) : 1;
$allowedStatuses = array('scheduled','accepted','travelling','arrived','in_progress','paused','rescheduled','follow_up_required','completed','cancelled','no_access');
$statusFilterActive = isset($_GET['status_filter']) && (int)$_GET['status_filter'] === 1;
$selectedStatuses = schCsvStrings(isset($_GET['statuses']) ? $_GET['statuses'] : '', $allowedStatuses);

$selected = new DateTime($selectedDate . ' 00:00:00');
$currentMonthKey = $selected->format('Y-m');
$displayDays = array();

/* Sunday-first calendar, matching the supplied schedule reference. */
if ($view === 'month') {
    $monthStart = new DateTime($selected->format('Y-m-01') . ' 00:00:00');
    $rangeStart = clone $monthStart;
    $dow = (int)$rangeStart->format('w');
    if ($dow > 0) $rangeStart->modify('-' . $dow . ' days');
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+42 days');
    for ($i = 0; $i < 42; $i++) {
        $d = clone $rangeStart;
        if ($i > 0) $d->modify('+' . $i . ' days');
        $displayDays[] = $d;
    }
    $heading = $selected->format('F Y');
    $prevDate = (clone $monthStart)->modify('-1 month')->format('Y-m-01');
    $nextDate = (clone $monthStart)->modify('+1 month')->format('Y-m-01');
} elseif ($view === 'week') {
    $rangeStart = clone $selected;
    $dow = (int)$rangeStart->format('w');
    if ($dow > 0) $rangeStart->modify('-' . $dow . ' days');
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+7 days');
    for ($i = 0; $i < 7; $i++) {
        $d = clone $rangeStart;
        if ($i > 0) $d->modify('+' . $i . ' days');
        $displayDays[] = $d;
    }
    $heading = $selected->format('F Y');
    $prevDate = (clone $selected)->modify('-7 days')->format('Y-m-d');
    $nextDate = (clone $selected)->modify('+7 days')->format('Y-m-d');
} else {
    $rangeStart = clone $selected;
    $rangeEnd = clone $selected;
    $rangeEnd->modify('+1 day');
    $displayDays = array(clone $selected);
    $heading = $selected->format('F Y');
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
$selectedTeamIds = array_values(array_filter($selectedTeamIds, function($id) use ($employeeMap) { return isset($employeeMap[(int)$id]); }));

$events = array();
$rangeStartSql = $rangeStart->format('Y-m-d H:i:s');
$rangeEndSql = $rangeEnd->format('Y-m-d H:i:s');
$locationSelect = "cl.name AS location_name,cl.address_line1 AS location_address,cl.city AS location_city";
$locationSelect .= schColumn($pdo, 'client_locations', 'address_line2') ? ",cl.address_line2 AS location_address2" : ",'' AS location_address2";
$locationSelect .= schColumn($pdo, 'client_locations', 'state') ? ",cl.state AS location_state" : ",'' AS location_state";
$locationSelect .= schColumn($pdo, 'client_locations', 'postal_code') ? ",cl.postal_code AS location_postal" : ",'' AS location_postal";

/* Expanded visits are the primary calendar source. */
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
        $sql = "SELECT v.id AS visit_id,v.visit_no,v.visit_number,v.scheduled_start,v.scheduled_end,v.status AS event_status,v.notes AS visit_notes,
                       j.id AS job_id,j.job_no,j.title,j.description,j.priority,j.status AS job_status,j.job_type,j.client_id,j.location_id,j.product_service_id,j.total,
                       c.display_name AS client_name,c.phone AS client_phone,c.email AS client_email,{$locationSelect},
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
                'key' => 'visit:' . (int)$row['visit_id'],
                'source' => 'visit',
                'visit_id' => (int)$row['visit_id'],
                'visit_no' => (string)$row['visit_no'],
                'visit_number' => (int)$row['visit_number'],
                'job_id' => (int)$row['job_id'],
                'job_no' => (string)$row['job_no'],
                'title' => (string)$row['title'],
                'customer' => (string)$row['client_name'],
                'client_phone' => (string)$row['client_phone'],
                'client_email' => (string)$row['client_email'],
                'service' => (string)$row['service_name'],
                'location' => schLocationText($row),
                'branch' => (string)$row['branch_name'],
                'priority' => (string)$row['priority'],
                'status' => (string)$row['event_status'],
                'job_status' => (string)$row['job_status'],
                'job_type' => (string)$row['job_type'],
                'instructions' => trim((string)$row['visit_notes']) !== '' ? (string)$row['visit_notes'] : (string)$row['description'],
                'start' => $start,
                'end' => $end,
                'total' => (float)$row['total'],
                'assignee_names' => schSplit($row['assignee_names'], '||'),
                'assignee_ids' => schSplit($row['assignee_ids'], ','),
                'line_items' => array(),
                'lane' => 0,
                'lane_count' => 1
            );
        }
    } catch (Throwable $e) {
        error_log('FieldPlx schedule visits query: ' . $e->getMessage());
    }
}

/* Compatibility fallback for older jobs with no visit rows. */
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
        $sql = "SELECT j.id AS job_id,j.job_no,j.title,j.description,j.priority,j.status AS event_status,j.job_type,j.total,
                       {$startExpr} AS scheduled_start,{$endExpr} AS scheduled_end,
                       c.display_name AS client_name,c.phone AS client_phone,c.email AS client_email,{$locationSelect},ps.name AS service_name,b.name AS branch_name,
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
                'key' => 'job:' . (int)$row['job_id'],
                'source' => 'job',
                'visit_id' => 0,
                'visit_no' => '',
                'visit_number' => 0,
                'job_id' => (int)$row['job_id'],
                'job_no' => (string)$row['job_no'],
                'title' => (string)$row['title'],
                'customer' => (string)$row['client_name'],
                'client_phone' => (string)$row['client_phone'],
                'client_email' => (string)$row['client_email'],
                'service' => (string)$row['service_name'],
                'location' => schLocationText($row),
                'branch' => (string)$row['branch_name'],
                'priority' => (string)$row['priority'],
                'status' => (string)$row['event_status'],
                'job_status' => (string)$row['event_status'],
                'job_type' => (string)$row['job_type'],
                'instructions' => (string)$row['description'],
                'start' => $start,
                'end' => $end,
                'total' => (float)$row['total'],
                'assignee_names' => schSplit($row['assignee_names'], '||'),
                'assignee_ids' => schSplit($row['assignee_ids'], ','),
                'line_items' => array(),
                'lane' => 0,
                'lane_count' => 1
            );
        }
    } catch (Throwable $e) {
        error_log('FieldPlx schedule fallback jobs query: ' . $e->getMessage());
    }
}

/* Multi-team, status and type filters. */
$events = array_values(array_filter($events, function($event) use ($typeFilter, $teamFilterActive, $selectedTeamIds, $includeUnassigned, $statusFilterActive, $selectedStatuses) {
    if ($typeFilter !== 'all' && $event['source'] !== $typeFilter) return false;
    if ($statusFilterActive && !in_array(strtolower((string)$event['status']), $selectedStatuses, true)) return false;
    if ($teamFilterActive) {
        $eventIds = array_map('intval', $event['assignee_ids']);
        if (!$eventIds) return $includeUnassigned === 1;
        foreach ($eventIds as $id) if (in_array($id, $selectedTeamIds, true)) return true;
        return false;
    }
    return true;
}));

usort($events, function($a, $b) {
    $cmp = strcmp($a['start'], $b['start']);
    if ($cmp !== 0) return $cmp;
    return (int)$a['job_id'] - (int)$b['job_id'];
});

/* Attach dynamic job line items to the preview/details panels. */
$jobIds = array();
foreach ($events as $event) $jobIds[(int)$event['job_id']] = (int)$event['job_id'];
$lineItemsByJob = array();
if ($jobIds && schTable($pdo, 'job_line_items')) {
    try {
        $ids = array_values($jobIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT job_id,item_name,description,quantity,unit_price,line_total FROM job_line_items WHERE tenant_id=? AND job_id IN ({$ph}) ORDER BY job_id,sort_order,id");
        $stmt->execute(array_merge(array($tenantId), $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $jid = (int)$item['job_id'];
            if (!isset($lineItemsByJob[$jid])) $lineItemsByJob[$jid] = array();
            $lineItemsByJob[$jid][] = array(
                'name' => (string)$item['item_name'],
                'description' => (string)$item['description'],
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'line_total' => (float)$item['line_total']
            );
        }
    } catch (Throwable $e) {
        error_log('FieldPlx schedule line items: ' . $e->getMessage());
    }
}
foreach ($events as $index => $event) {
    $events[$index]['line_items'] = isset($lineItemsByJob[(int)$event['job_id']]) ? $lineItemsByJob[(int)$event['job_id']] : array();
    $events[$index]['anytime'] = schIsAnytime($events[$index]) ? 1 : 0;
}

$eventsByDay = array();
$timedEventsByDay = array();
$anytimeEventsByDay = array();
foreach ($events as $event) {
    $dayKey = substr($event['start'], 0, 10);
    if (!isset($eventsByDay[$dayKey])) $eventsByDay[$dayKey] = array();
    $eventsByDay[$dayKey][] = $event;
    if (!empty($event['anytime'])) {
        if (!isset($anytimeEventsByDay[$dayKey])) $anytimeEventsByDay[$dayKey] = array();
        $anytimeEventsByDay[$dayKey][] = $event;
    } else {
        if (!isset($timedEventsByDay[$dayKey])) $timedEventsByDay[$dayKey] = array();
        $timedEventsByDay[$dayKey][] = $event;
    }
}
foreach ($timedEventsByDay as $key => $dayEvents) {
    schAssignLanes($dayEvents);
    $timedEventsByDay[$key] = $dayEvents;
}

/* Day view resource rows. */
$resourceRows = array();
if ($view === 'day') {
    if (!$teamFilterActive || $includeUnassigned) {
        $resourceRows[] = array('id' => 0, 'name' => 'Unassigned', 'initials' => '', 'job_title' => '');
    }
    foreach ($employees as $employee) {
        $eid = (int)$employee['id'];
        if ($teamFilterActive && !in_array($eid, $selectedTeamIds, true)) continue;
        $name = trim((string)$employee['first_name'] . ' ' . (string)$employee['last_name']);
        $resourceRows[] = array('id' => $eid, 'name' => $name, 'initials' => schInitials($name), 'job_title' => (string)$employee['job_title']);
    }
}

$todayUrl = schBuildUrl(array('date' => date('Y-m-d')));
$previousUrl = schBuildUrl(array('date' => $prevDate));
$nextUrl = schBuildUrl(array('date' => $nextDate));
$dayUrl = schBuildUrl(array('view' => 'day'));
$weekUrl = schBuildUrl(array('view' => 'week'));
$monthUrl = schBuildUrl(array('view' => 'month'));
$findTimeUrl = schBuildUrl(array('view' => 'day'));
$teamLabel = !$teamFilterActive ? 'All' : ((count($selectedTeamIds) + ($includeUnassigned ? 1 : 0)) . ' selected');
$statusLabel = !$statusFilterActive ? 'All' : (count($selectedStatuses) . ' selected');
$typeLabel = $typeFilter === 'all' ? 'All' : schReadable($typeFilter);
$selectedDayKey = $selected->format('Y-m-d');
$dayEvents = isset($eventsByDay[$selectedDayKey]) ? $eventsByDay[$selectedDayKey] : array();
$dayTimedEvents = isset($timedEventsByDay[$selectedDayKey]) ? $timedEventsByDay[$selectedDayKey] : array();
$dayAnytimeEvents = isset($anytimeEventsByDay[$selectedDayKey]) ? $anytimeEventsByDay[$selectedDayKey] : array();
$eventJson = json_encode($events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$currencyJson = json_encode($currency, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
   Schedule workspace v2
   ========================================================= */
.fd-dashboard.schedule-workspace{width:100%;max-width:none;margin:0;padding:0;background:#fff}
.sch2-card{min-height:calc(100vh - var(--fieldplx-topbar-height));background:#fff}
.sch2-toolbar{min-height:58px;padding:10px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--fd-border);background:#fff;position:sticky;top:var(--fieldplx-topbar-height);z-index:22}
.sch2-period{margin-right:14px;display:inline-flex;align-items:center;gap:7px;color:#10243e;font-size:20px;font-weight:700;white-space:nowrap}
.sch2-period i{font-size:11px;color:#60748b}
.sch2-icon-btn,.sch2-btn,.sch2-filter-btn,.sch2-view-btn{height:34px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid #dbe2e9;border-radius:7px;background:#fff;color:#31465f;text-decoration:none!important;font-size:10px;font-weight:600;cursor:pointer;white-space:nowrap}
.sch2-icon-btn{width:34px;padding:0}.sch2-btn{padding:0 11px}.sch2-btn.primary{border-color:#5d971b;background:#2f8b22;color:#fff}.sch2-btn.primary:hover{background:#24751b;color:#fff}
.sch2-filter-btn{padding:0 12px;border-radius:18px}.sch2-filter-btn.active{background:#ecebe7;border-color:#dedcd6}.sch2-filter-btn .muted{color:#7f8b99;font-weight:500}
.sch2-toolbar-spacer{flex:1}.sch2-info{color:#2688de;font-size:16px}.sch2-view-switch{height:34px;display:flex;border:1px solid #dbe2e9;border-radius:7px;overflow:hidden}.sch2-view-btn{height:32px;padding:0 13px;border:0;border-radius:0}.sch2-view-btn.active{box-shadow:inset 0 0 0 1px #5d971b;color:#4e7c20;background:#fbfff7}
.sch2-menu-wrap,.sch2-filter-wrap{position:relative}.sch2-dropdown{width:252px;position:absolute;top:42px;left:0;z-index:90;border:1px solid #d9dfe5;border-radius:8px;background:#fff;box-shadow:0 8px 22px rgba(0,17,49,.14);display:none;overflow:hidden}.sch2-dropdown.open{display:block}.sch2-dropdown.right{left:auto;right:0}.sch2-dropdown-search{padding:10px;border-bottom:1px solid #edf0f3}.sch2-dropdown-search input{width:100%;height:34px;padding:0 9px;border:1px solid #dbe2e9;border-radius:6px;outline:0;font-size:10px}.sch2-dropdown-list{max-height:280px;overflow:auto}.sch2-choice{min-height:40px;padding:8px 12px;display:flex;align-items:center;gap:9px;color:#40546b;font-size:10px;cursor:pointer}.sch2-choice:hover,.sch2-choice.selected{background:#f4f3ef}.sch2-choice-check{margin-left:auto;color:#24465a;font-size:15px}.sch2-choice-avatar{width:22px;height:22px;display:grid;place-items:center;border-radius:50%;background:#294b5d;color:#fff;font-size:7px;font-weight:700}.sch2-choice-avatar.unassigned{background:#fff0ea;color:#e05a45;border:1px solid #efb9ad}.sch2-dropdown-foot{padding:10px 12px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e6e9ec;background:#fff}.sch2-link-btn{border:0;background:transparent;color:#4f8d22;text-decoration:underline;font-size:10px;cursor:pointer}.sch2-link-btn.secondary{color:#7f8790;text-decoration:none;background:#efefed;border-radius:4px;padding:5px 8px}
.sch2-calendar{position:relative;background:#fff}.sch2-scroll{width:100%;overflow:auto;scrollbar-width:thin;scrollbar-color:#8c8f92 transparent}.sch2-scroll::-webkit-scrollbar{height:9px;width:9px}.sch2-scroll::-webkit-scrollbar-thumb{background:#8c8f92;border-radius:8px}
/* month */
.sch2-month-weekdays{min-width:980px;display:grid;grid-template-columns:repeat(7,minmax(140px,1fr));height:43px;border-bottom:1px solid #d9dee3;background:#fff;position:sticky;top:0;z-index:5}.sch2-month-weekday{display:flex;align-items:center;justify-content:center;color:#183149;font-size:10px;font-weight:700}.sch2-month-grid{min-width:980px;display:grid;grid-template-columns:repeat(7,minmax(140px,1fr));grid-template-rows:repeat(6,minmax(108px,1fr));height:calc(100vh - var(--fieldplx-topbar-height) - 102px);min-height:650px;background:#e2e3e3;gap:1px}.sch2-month-day{padding:5px 6px;background:#fff;overflow:hidden}.sch2-month-day.outside{background:#ececec}.sch2-month-day-head{height:24px;display:flex;align-items:center;gap:5px}.sch2-month-date{width:20px;height:20px;display:grid;place-items:center;border-radius:5px;color:#1d354a;font-size:10px}.sch2-month-day.today .sch2-month-date,.sch2-month-day.selected .sch2-month-date{background:#2e82bb;color:#fff;font-weight:700}.sch2-visit-count{padding:2px 4px;border:1px solid #d5dde4;border-radius:3px;background:#fff;color:#68798c;font-size:7px}.sch2-month-events{display:grid;gap:3px}.sch2-month-event{height:22px;padding:0 6px;display:flex;align-items:center;gap:5px;overflow:hidden;border:0;border-radius:2px;background:#e5efe3;color:#294640;text-decoration:none!important;font-size:8px;cursor:pointer}.sch2-month-event.progress{background:#fff1d9}.sch2-month-event.completed{background:#e6f2dc}.sch2-month-event.rescheduled{background:#f0eafa}.sch2-month-event.cancelled{background:#f9e3e5}.sch2-month-event .avatar{width:14px;height:14px;flex:0 0 14px;display:grid;place-items:center;border-radius:50%;background:#355768;color:#fff;font-size:5.5px;font-weight:700}.sch2-month-event .title{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sch2-month-more{color:#4f7f27;font-size:8px;text-decoration:none!important}
/* week */
.sch2-week-wrap{min-width:980px}.sch2-week-head{display:grid;grid-template-columns:55px repeat(7,minmax(130px,1fr));height:66px;background:#fff;border-bottom:0}.sch2-week-corner{border-right:1px solid #e1e4e7}.sch2-week-day{padding-top:11px;text-align:center;color:#30465b;font-size:10px;font-weight:700}.sch2-week-day .date{width:28px;height:28px;margin:5px auto 0;display:grid;place-items:center;border-radius:6px}.sch2-week-day.today .date,.sch2-week-day.selected .date{background:#2f83bb;color:#fff}.sch2-anytime-row{display:grid;grid-template-columns:55px repeat(7,minmax(130px,1fr));min-height:28px;border-bottom:1px solid #e0e3e6}.sch2-anytime-label{padding:6px 4px;color:#788899;font-size:8px;text-align:right;border-right:1px solid #e4e6e8}.sch2-anytime-cell{min-height:28px;padding:3px;border-right:1px solid #ededed}.sch2-anytime-event{height:20px;padding:0 5px;display:flex;align-items:center;gap:4px;border-radius:2px;background:#e5efe3;color:#294640;font-size:8px;overflow:hidden;cursor:pointer}.sch2-week-body{display:grid;grid-template-columns:55px repeat(7,minmax(130px,1fr));height:1536px;position:relative}.sch2-time-axis{position:relative;border-right:1px solid #e4e6e8;background:#fff}.sch2-time-label{height:64px;padding-right:4px;position:absolute;left:0;right:0;transform:translateY(-5px);color:#6a7a8c;font-size:8px;text-align:right}.sch2-week-column{position:relative;border-right:1px solid #ededed;background-image:repeating-linear-gradient(to bottom,#fff 0,#fff 63px,#e9e9e8 63px,#e9e9e8 64px)}.sch2-week-column.today{background-color:#fffefa}.sch2-week-event{position:absolute;z-index:4;min-height:22px;padding:4px 5px;overflow:hidden;border:0;border-radius:3px;background:#e5efe3;color:#294640;font-size:7.5px;cursor:pointer}.sch2-week-event.progress{background:#fff1d9}.sch2-week-event.completed{background:#e6f2dc}.sch2-week-event.rescheduled{background:#f0eafa}.sch2-week-event.cancelled{background:#f9e3e5}.sch2-week-event .line1{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sch2-week-event .line2{margin-top:2px;color:#65766f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* day resource timeline */
.sch2-day-wrap{min-width:1280px;display:grid;grid-template-columns:205px 1fr}.sch2-resource-head{height:76px;position:sticky;left:0;z-index:8;border-right:1px solid #d9d9d7;border-bottom:1px solid #e0e3e5;background:#fff;display:flex;align-items:flex-end;justify-content:flex-end;padding:0 8px 13px;color:#728197;font-size:8px}.sch2-day-timeline-head{height:76px;position:relative;border-bottom:1px solid #e0e3e5;background:#fff;overflow:hidden}.sch2-day-hours{width:2304px;height:100%;position:relative}.sch2-day-hour{width:96px;position:absolute;bottom:10px;color:#657587;font-size:8px}.sch2-resource-row{height:184px;position:sticky;left:0;z-index:7;padding:0 13px;display:flex;align-items:center;gap:9px;border-right:1px solid #d9d9d7;border-bottom:1px solid #e3e3e1;background:#fff}.sch2-resource-row.alt{background:#fbfaf8}.sch2-resource-avatar{width:24px;height:24px;display:grid;place-items:center;border-radius:50%;background:#294b5d;color:#fff;font-size:7px;font-weight:700}.sch2-resource-avatar.unassigned{background:transparent;color:#677786;border:0;font-size:17px}.sch2-resource-copy{min-width:0;flex:1}.sch2-resource-name{display:block;color:#31465a;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sch2-resource-count{margin-top:4px;display:inline-block;color:#4f8f27;font-size:8px}.sch2-resource-timeline{height:184px;position:relative;border-bottom:1px solid #e3e3e1;background-image:repeating-linear-gradient(to right,transparent 0,transparent 95px,#ddd 95px,#ddd 96px);background-color:#fff}.sch2-resource-timeline.alt{background-color:#fbfaf8}.sch2-day-event{height:34px;position:absolute;top:18px;padding:4px 7px;overflow:hidden;border-radius:3px;background:#e5efe3;color:#294640;font-size:8px;cursor:pointer}.sch2-day-event:nth-child(2n){top:58px}.sch2-day-event:nth-child(3n){top:98px}.sch2-day-event .line1{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sch2-day-event .line2{margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sch2-day-scroller{overflow:auto}.sch2-day-timeline-inner{width:2304px;position:relative}.sch2-anytime-toggle{position:absolute;top:9px;left:0;border:0;background:transparent;color:#66798e;font-size:8px;cursor:pointer}.sch2-anytime-drawer{width:180px;position:absolute;top:76px;bottom:0;left:205px;z-index:16;border-right:1px solid #cfd5d9;background:#fff;box-shadow:4px 0 10px rgba(0,0,0,.06);display:none}.sch2-anytime-drawer.open{display:block}.sch2-anytime-drawer-head{height:42px;padding:0 10px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e3e6e8;color:#30465b;font-size:10px;font-weight:700}.sch2-anytime-drawer-list{padding:8px;display:grid;gap:5px}.sch2-anytime-drawer-event{height:25px;padding:0 7px;display:flex;align-items:center;gap:4px;border-radius:2px;background:#e5efe3;color:#294640;font-size:8px;cursor:pointer}
/* popover */
.sch2-popover{width:335px;position:fixed;z-index:120;display:none;border:1px solid #d9dfe4;border-radius:8px;background:#fff;box-shadow:0 8px 22px rgba(0,17,49,.18);overflow:hidden}.sch2-popover.open{display:block}.sch2-popover-head{padding:12px 14px 8px;display:flex;align-items:flex-start;justify-content:space-between}.sch2-popover-kicker{color:#6f8092;font-size:9px}.sch2-popover-title{margin-top:5px;color:#18324a;font-size:13px;font-weight:700}.sch2-popover-close{border:0;background:transparent;color:#566879;font-size:16px}.sch2-popover-body{padding:0 14px 10px;color:#43576d;font-size:9px}.sch2-pop-row{margin:8px 0}.sch2-pop-label{display:block;margin-bottom:3px;color:#18324a;font-weight:700}.sch2-team-chips{display:flex;gap:5px;flex-wrap:wrap}.sch2-team-chip{height:24px;padding:0 7px;display:inline-flex;align-items:center;gap:5px;border-radius:13px;background:#e7e5e0;color:#31495c}.sch2-chip-avatar{width:17px;height:17px;display:grid;place-items:center;border-radius:50%;background:#294b5d;color:#fff;font-size:6px;font-weight:700}.sch2-pop-lines{border:1px solid #e2e6e9;border-radius:5px;overflow:hidden}.sch2-pop-line{min-height:30px;padding:6px 8px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;border-bottom:1px solid #eceeef}.sch2-pop-line:last-child{border-bottom:0}.sch2-pop-total{padding:7px 8px;text-align:right;font-weight:700}.sch2-pop-actions{padding:8px 12px;display:flex;gap:7px;border-top:1px solid #e6e8ea;background:#fff}.sch2-pop-actions .sch2-btn{flex:1}.sch2-pop-actions .sch2-btn.primary{background:#338d22}
/* modal */
.sch2-modal{position:fixed;inset:0;z-index:130;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,17,49,.34)}.sch2-modal.open{display:flex}.sch2-modal-dialog{width:min(510px,calc(100vw - 28px));max-height:calc(100vh - 36px);overflow:auto;border-radius:8px;background:#fff;box-shadow:0 18px 50px rgba(0,17,49,.22)}.sch2-modal-head{height:66px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e6e9}.sch2-modal-title{margin:0;color:#183149;font-size:18px;font-weight:700}.sch2-modal-close{width:34px;height:34px;border:0;background:transparent;color:#41586b;font-size:19px}.sch2-modal-body{padding:14px}.sch2-visit-top{display:grid;grid-template-columns:1.2fr .9fr;gap:14px}.sch2-visit-name{font-size:14px;font-weight:700;color:#183149}.sch2-visit-customer{margin-top:12px;color:#526779;font-size:10px;line-height:1.5}.sch2-visit-meta{display:grid;gap:10px;color:#526779;font-size:10px}.sch2-visit-meta-row{display:flex;align-items:flex-start;gap:8px}.sch2-visit-meta-row i{color:#5d971b;font-size:14px}.sch2-modal-actions{margin-top:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px}.sch2-complete{height:38px;border:0;border-radius:6px;background:#338d22;color:#fff;font-size:10px;font-weight:700}.sch2-more-action{height:38px;border:1px solid #dbe1e6;border-radius:6px;background:#fff;color:#4d8a24;font-size:10px;font-weight:700}.sch2-tabs{margin-top:14px;height:40px;display:flex;gap:25px;border-bottom:1px solid #dfe4e8}.sch2-tab{height:40px;padding:0 7px;border:0;border-bottom:3px solid transparent;background:transparent;color:#4a6074;font-size:10px;font-weight:600}.sch2-tab.active{border-bottom-color:#338d22;color:#19364a}.sch2-tab-panel{display:none;padding-top:12px}.sch2-tab-panel.active{display:block}.sch2-info-block{padding:10px 0;border-bottom:1px solid #e7eaec}.sch2-info-block:last-child{border-bottom:0}.sch2-info-title{margin-bottom:7px;color:#1a344a;font-size:10px;font-weight:700}.sch2-info-text{color:#65778a;font-size:9.5px;line-height:1.55;white-space:pre-wrap}.sch2-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.sch2-modal-line-items{margin-top:8px}.sch2-modal-line{padding:7px 0;display:grid;grid-template-columns:1fr auto;gap:8px;color:#53687b;font-size:9px}.sch2-modal-line .desc{display:block;margin-top:3px;color:#8290a0}.sch2-toast{min-width:240px;max-width:360px;position:fixed;top:86px;right:18px;z-index:180;padding:10px 12px;border-radius:7px;background:#2f8b22;color:#fff;font-size:9px;box-shadow:0 8px 25px rgba(0,0,0,.18);display:none}.sch2-toast.error{background:#cf4f58}.sch2-toast.show{display:block}
@media(max-width:1100px){.sch2-period{font-size:17px}.sch2-filter-btn{padding:0 9px}.sch2-toolbar{overflow-x:auto}.sch2-toolbar-spacer{min-width:10px}.sch2-view-switch{flex:0 0 auto}}
@media(max-width:767.98px){.sch2-toolbar{top:64px;padding:8px 10px}.sch2-period{font-size:16px;margin-right:6px}.sch2-btn.find-time{display:none}.sch2-info{display:none}.sch2-month-grid{height:700px}.sch2-modal{padding:8px}.sch2-visit-top{grid-template-columns:1fr}.sch2-popover{width:min(330px,calc(100vw - 18px))}}

    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">
            <div class="fd-dashboard schedule-workspace">
                <section class="sch2-card">
                    <div class="sch2-toolbar">
                        <div class="sch2-period"><?= schH($heading) ?> <i class="bi bi-chevron-down"></i></div>
                        <a class="sch2-icon-btn" href="<?= schH($previousUrl) ?>" aria-label="Previous"><i class="bi bi-arrow-left"></i></a>
                        <a class="sch2-icon-btn" href="<?= schH($nextUrl) ?>" aria-label="Next"><i class="bi bi-arrow-right"></i></a>
                        <a class="sch2-btn" href="<?= schH($todayUrl) ?>">Today</a>
                        <a class="sch2-btn primary find-time" href="<?= schH($findTimeUrl) ?>">Find a Time</a>

                        <div class="sch2-filter-wrap">
                            <button type="button" class="sch2-filter-btn <?= $typeFilter !== 'all' ? 'active' : '' ?>" data-dropdown="typeDropdown">Type <span class="muted">|</span> <?= schH($typeLabel) ?></button>
                            <div class="sch2-dropdown" id="typeDropdown">
                                <div class="sch2-dropdown-list">
                                    <?php foreach (array('all'=>'All','visit'=>'Visits','job'=>'Jobs') as $value=>$label): ?>
                                        <a class="sch2-choice <?= $typeFilter === $value ? 'selected' : '' ?>" href="<?= schH(schBuildUrl(array('type'=>$value))) ?>">
                                            <span><?= schH($label) ?></span><?php if ($typeFilter === $value): ?><i class="bi bi-check-lg sch2-choice-check"></i><?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="sch2-filter-wrap">
                            <button type="button" class="sch2-filter-btn <?= $teamFilterActive ? 'active' : '' ?>" data-dropdown="teamDropdown">Team <span class="muted">|</span> <?= schH($teamLabel) ?></button>
                            <div class="sch2-dropdown" id="teamDropdown">
                                <div class="sch2-dropdown-search"><input type="search" id="teamSearch" placeholder="Search"></div>
                                <div class="sch2-dropdown-list" id="teamChoices">
                                    <?php foreach ($employees as $employee): ?>
                                        <?php $eid=(int)$employee['id']; $ename=trim($employee['first_name'].' '.$employee['last_name']); $checked=!$teamFilterActive || in_array($eid,$selectedTeamIds,true); ?>
                                        <label class="sch2-choice team-choice" data-search="<?= schH(strtolower($ename)) ?>">
                                            <input type="checkbox" class="team-check" value="<?= $eid ?>" <?= $checked ? 'checked' : '' ?> hidden>
                                            <span class="sch2-choice-avatar"><?= schH(schInitials($ename)) ?></span>
                                            <span><?= schH($ename) ?></span>
                                            <i class="bi bi-check-lg sch2-choice-check"></i>
                                        </label>
                                    <?php endforeach; ?>
                                    <label class="sch2-choice team-choice" data-search="unassigned">
                                        <input type="checkbox" id="teamUnassigned" <?= $includeUnassigned ? 'checked' : '' ?> hidden>
                                        <span class="sch2-choice-avatar unassigned"><i class="bi bi-person-slash"></i></span>
                                        <span>Unassigned</span>
                                        <i class="bi bi-check-lg sch2-choice-check"></i>
                                    </label>
                                </div>
                                <div class="sch2-dropdown-foot">
                                    <button type="button" class="sch2-link-btn secondary" id="teamSelectAll">Select All</button>
                                    <button type="button" class="sch2-link-btn" id="teamClear">Clear</button>
                                </div>
                            </div>
                        </div>

                        <div class="sch2-filter-wrap">
                            <button type="button" class="sch2-filter-btn <?= $statusFilterActive ? 'active' : '' ?>" data-dropdown="statusDropdown">Status <span class="muted">|</span> <?= schH($statusLabel) ?></button>
                            <div class="sch2-dropdown" id="statusDropdown">
                                <div class="sch2-dropdown-list" id="statusChoices">
                                    <?php foreach ($allowedStatuses as $statusOption): ?>
                                        <?php $checked=!$statusFilterActive || in_array($statusOption,$selectedStatuses,true); ?>
                                        <label class="sch2-choice status-choice">
                                            <input type="checkbox" class="status-check" value="<?= schH($statusOption) ?>" <?= $checked ? 'checked' : '' ?> hidden>
                                            <span><?= schH(schReadable($statusOption)) ?></span>
                                            <i class="bi bi-check-lg sch2-choice-check"></i>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="sch2-dropdown-foot">
                                    <button type="button" class="sch2-link-btn secondary" id="statusSelectAll">Select All</button>
                                    <button type="button" class="sch2-link-btn" id="statusClear">Clear</button>
                                </div>
                            </div>
                        </div>

                        <div class="sch2-toolbar-spacer"></div>
                        <i class="bi bi-info-circle sch2-info" title="Schedule shows visits and job assignments for the selected period."></i>
                        <div class="sch2-view-switch">
                            <a class="sch2-view-btn <?= $view === 'month' ? 'active' : '' ?>" href="<?= schH($monthUrl) ?>">Month</a>
                            <a class="sch2-view-btn <?= $view === 'week' ? 'active' : '' ?>" href="<?= schH($weekUrl) ?>">Week</a>
                            <a class="sch2-view-btn <?= $view === 'day' ? 'active' : '' ?>" href="<?= schH($dayUrl) ?>">Day</a>
                        </div>
                        <div class="sch2-menu-wrap">
                            <button type="button" class="sch2-btn" data-dropdown="moreDropdown"><i class="bi bi-three-dots"></i> More</button>
                            <div class="sch2-dropdown right" id="moreDropdown">
                                <div class="sch2-dropdown-list">
                                    <a class="sch2-choice" href="job-form.php"><i class="bi bi-plus-lg"></i><span>Create Job</span></a>
                                    <a class="sch2-choice" href="jobs.php"><i class="bi bi-briefcase"></i><span>View Jobs</span></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form id="scheduleHiddenFilter" method="get" action="schedule.php" style="display:none">
                        <input type="hidden" name="view" value="<?= schH($view) ?>">
                        <input type="hidden" name="date" value="<?= schH($selectedDate) ?>">
                        <?php if ($branchId > 0): ?><input type="hidden" name="branch_id" value="<?= (int)$branchId ?>"><?php endif; ?>
                        <input type="hidden" name="type" value="<?= schH($typeFilter) ?>">
                        <input type="hidden" name="team_filter" id="teamFilterFlag" value="<?= $teamFilterActive ? 1 : 0 ?>">
                        <input type="hidden" name="team_ids" id="teamIdsInput" value="<?= schH(implode(',',$selectedTeamIds)) ?>">
                        <input type="hidden" name="include_unassigned" id="teamUnassignedInput" value="<?= $includeUnassigned ? 1 : 0 ?>">
                        <input type="hidden" name="status_filter" id="statusFilterFlag" value="<?= $statusFilterActive ? 1 : 0 ?>">
                        <input type="hidden" name="statuses" id="statusesInput" value="<?= schH(implode(',',$selectedStatuses)) ?>">
                    </form>

                    <div class="sch2-calendar">
                    <?php if ($view === 'month'): ?>
                        <div class="sch2-scroll">
                            <div class="sch2-month-weekdays">
                                <?php foreach (array('Sun','Mon','Tue','Wed','Thu','Fri','Sat') as $weekday): ?><div class="sch2-month-weekday"><?= schH($weekday) ?></div><?php endforeach; ?>
                            </div>
                            <div class="sch2-month-grid">
                                <?php foreach ($displayDays as $day): ?>
                                    <?php $dayKey=$day->format('Y-m-d'); $items=isset($eventsByDay[$dayKey])?$eventsByDay[$dayKey]:array(); $outside=$day->format('Y-m')!==$currentMonthKey; $isToday=$dayKey===date('Y-m-d'); $isSelected=$dayKey===$selectedDate; ?>
                                    <div class="sch2-month-day <?= $outside?'outside':'' ?> <?= $isToday?'today':'' ?> <?= $isSelected?'selected':'' ?>">
                                        <div class="sch2-month-day-head">
                                            <span class="sch2-month-date"><?= schH($day->format('j')) ?></span>
                                            <?php if ($items): ?><span class="sch2-visit-count"><?= count($items) ?> visit<?= count($items)===1?'':'s' ?></span><?php endif; ?>
                                        </div>
                                        <div class="sch2-month-events">
                                            <?php foreach (array_slice($items,0,4) as $event): ?>
                                                <?php $eventClass=schEventClass($event['status']); $person=!empty($event['assignee_names'][0])?$event['assignee_names'][0]:''; ?>
                                                <button type="button" class="sch2-month-event <?= schH($eventClass) ?> schedule-event" data-event-key="<?= schH($event['key']) ?>">
                                                    <?php if ($person!==''): ?><span class="avatar"><?= schH(schInitials($person)) ?></span><?php endif; ?>
                                                    <span class="title"><?= schH($event['service']!==''?$event['service']:$event['title']) ?></span>
                                                </button>
                                            <?php endforeach; ?>
                                            <?php if (count($items)>4): ?><a class="sch2-month-more" href="<?= schH(schBuildUrl(array('view'=>'day','date'=>$dayKey))) ?>">+<?= count($items)-4 ?> more</a><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php elseif ($view === 'week'): ?>
                        <div class="sch2-scroll">
                            <div class="sch2-week-wrap">
                                <div class="sch2-week-head">
                                    <div class="sch2-week-corner"></div>
                                    <?php foreach ($displayDays as $day): ?>
                                        <?php $dayKey=$day->format('Y-m-d'); ?>
                                        <div class="sch2-week-day <?= $dayKey===date('Y-m-d')?'today':'' ?> <?= $dayKey===$selectedDate?'selected':'' ?>">
                                            <div><?= schH($day->format('D')) ?></div><div class="date"><?= schH($day->format('j')) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="sch2-anytime-row">
                                    <div class="sch2-anytime-label">Anytime</div>
                                    <?php foreach ($displayDays as $day): ?>
                                        <?php $dayKey=$day->format('Y-m-d'); $items=isset($anytimeEventsByDay[$dayKey])?$anytimeEventsByDay[$dayKey]:array(); ?>
                                        <div class="sch2-anytime-cell">
                                            <?php foreach ($items as $event): ?><button type="button" class="sch2-anytime-event schedule-event" data-event-key="<?= schH($event['key']) ?>"><?= schH($event['service']!==''?$event['service']:$event['title']) ?></button><?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="sch2-week-body">
                                    <div class="sch2-time-axis">
                                        <?php for($hour=0;$hour<24;$hour++): ?><div class="sch2-time-label" style="top:<?= $hour*64 ?>px"><?= schH(date('g A',mktime($hour,0,0,1,1,2026))) ?></div><?php endfor; ?>
                                    </div>
                                    <?php foreach ($displayDays as $day): ?>
                                        <?php $dayKey=$day->format('Y-m-d'); $items=isset($timedEventsByDay[$dayKey])?$timedEventsByDay[$dayKey]:array(); ?>
                                        <div class="sch2-week-column <?= $dayKey===date('Y-m-d')?'today':'' ?>">
                                            <?php foreach ($items as $event): ?>
                                                <?php $st=strtotime($event['start']);$et=strtotime($event['end']);if(!$et||$et<=$st)$et=$st+3600;$minute=(int)date('G',$st)*60+(int)date('i',$st);$duration=max(20,($et-$st)/60);$top=$minute/60*64;$height=max(22,$duration/60*64);$lanes=max(1,(int)$event['lane_count']);$lane=max(0,(int)$event['lane']);$width=100/$lanes;$left=$lane*$width;$cls=schEventClass($event['status']); ?>
                                                <button type="button" class="sch2-week-event <?= schH($cls) ?> schedule-event" data-event-key="<?= schH($event['key']) ?>" style="top:<?= number_format($top,2,'.','') ?>px;height:<?= number_format($height,2,'.','') ?>px;left:calc(<?= number_format($left,4,'.','') ?>% + 3px);width:calc(<?= number_format($width,4,'.','') ?>% - 6px)">
                                                    <span class="line1"><?= schH($event['service']!==''?$event['service']:$event['title']) ?></span>
                                                    <?php if($height>=40): ?><span class="line2"><?= schH(date('g:i A',$st)) ?> · <?= schH($event['customer']) ?></span><?php endif; ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="sch2-day-scroller">
                            <div class="sch2-day-wrap">
                                <div class="sch2-resource-head">Anytime</div>
                                <div class="sch2-day-timeline-head">
                                    <button type="button" class="sch2-anytime-toggle" id="anytimeToggle">Anytime</button>
                                    <div class="sch2-day-hours">
                                        <?php for($hour=0;$hour<24;$hour++): ?><span class="sch2-day-hour" style="left:<?= $hour*96 ?>px"><?= schH(date('g A',mktime($hour,0,0,1,1,2026))) ?></span><?php endfor; ?>
                                    </div>
                                </div>
                                <?php foreach($resourceRows as $idx=>$resource): ?>
                                    <?php $rid=(int)$resource['id'];$rowItems=array();foreach($dayTimedEvents as $event){$ids=array_map('intval',$event['assignee_ids']);if(($rid===0&&!$ids)||($rid>0&&in_array($rid,$ids,true)))$rowItems[]=$event;} ?>
                                    <div class="sch2-resource-row <?= $idx%2?'alt':'' ?>">
                                        <span class="sch2-resource-avatar <?= $rid===0?'unassigned':'' ?>"><?= $rid===0?'<i class="bi bi-person-slash"></i>':schH($resource['initials']) ?></span>
                                        <span class="sch2-resource-copy"><span class="sch2-resource-name"><?= schH($resource['name']) ?></span><span class="sch2-resource-count"><?= count($rowItems) ?><?= count($rowItems)?' visit'.(count($rowItems)===1?'':'s'):'' ?></span></span>
                                    </div>
                                    <div class="sch2-resource-timeline <?= $idx%2?'alt':'' ?>">
                                        <div class="sch2-day-timeline-inner">
                                            <?php foreach($rowItems as $event): ?>
                                                <?php $st=strtotime($event['start']);$et=strtotime($event['end']);if(!$et||$et<=$st)$et=$st+3600;$minute=(int)date('G',$st)*60+(int)date('i',$st);$dur=max(15,($et-$st)/60);$left=$minute/60*96;$width=max(48,$dur/60*96); ?>
                                                <button type="button" class="sch2-day-event schedule-event" data-event-key="<?= schH($event['key']) ?>" style="left:<?= number_format($left,2,'.','') ?>px;width:<?= number_format($width,2,'.','') ?>px"><span class="line1"><?= schH($event['service']!==''?$event['service']:$event['title']) ?></span><span class="line2"><?= schH(date('g:i A',$st)) ?></span></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <aside class="sch2-anytime-drawer" id="anytimeDrawer">
                            <div class="sch2-anytime-drawer-head"><span>Anytime</span><button type="button" class="sch2-popover-close" id="anytimeClose">&times;</button></div>
                            <div class="sch2-anytime-drawer-list">
                                <?php if(!$dayAnytimeEvents): ?><div style="padding:8px;color:#83909d;font-size:9px">No anytime visits.</div><?php endif; ?>
                                <?php foreach($dayAnytimeEvents as $event): ?><button type="button" class="sch2-anytime-drawer-event schedule-event" data-event-key="<?= schH($event['key']) ?>"><?= schH($event['service']!==''?$event['service']:$event['title']) ?></button><?php endforeach; ?>
                            </div>
                        </aside>
                    <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<div class="sch2-popover" id="eventPopover" aria-hidden="true">
    <div class="sch2-popover-head"><div><div class="sch2-popover-kicker" id="popKicker">Visit</div><div class="sch2-popover-title" id="popTitle">-</div></div><button type="button" class="sch2-popover-close" id="popClose">&times;</button></div>
    <div class="sch2-popover-body" id="popBody"></div>
    <div class="sch2-pop-actions"><button type="button" class="sch2-btn" id="popFindTime"><i class="bi bi-clock"></i> Find a time</button><a class="sch2-btn" id="popEdit" href="#">Edit</a><button type="button" class="sch2-btn primary" id="popDetails">Details</button></div>
</div>

<div class="sch2-modal" id="visitModal" aria-hidden="true">
    <div class="sch2-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="visitModalTitle">
        <div class="sch2-modal-head"><h2 class="sch2-modal-title" id="visitModalTitle">Visit Details</h2><button type="button" class="sch2-modal-close" id="visitModalClose">&times;</button></div>
        <div class="sch2-modal-body">
            <div class="sch2-visit-top"><div><div class="sch2-visit-name" id="modalVisitName">-</div><div class="sch2-visit-customer" id="modalCustomer"></div></div><div class="sch2-visit-meta" id="modalMeta"></div></div>
            <div class="sch2-modal-actions"><button type="button" class="sch2-complete" id="modalComplete">Mark Complete</button><a class="sch2-more-action" id="modalEdit" href="#">Edit Job</a></div>
            <div class="sch2-tabs"><button type="button" class="sch2-tab active" data-tab="info">Info</button><button type="button" class="sch2-tab" data-tab="client">Customer</button><button type="button" class="sch2-tab" data-tab="notes">Notes</button></div>
            <div class="sch2-tab-panel active" data-panel="info" id="modalInfo"></div>
            <div class="sch2-tab-panel" data-panel="client" id="modalClient"></div>
            <div class="sch2-tab-panel" data-panel="notes" id="modalNotes"></div>
        </div>
    </div>
</div>
<div class="sch2-toast" id="scheduleToast"></div>
<script>
(function(){
'use strict';
var EVENTS=<?= $eventJson ? $eventJson : '[]' ?>;
var CURRENCY=<?= $currencyJson ? $currencyJson : '{}'; ?>;
var CSRF=<?= json_encode($scheduleCsrfToken) ?>;
var eventMap={};EVENTS.forEach(function(e){eventMap[e.key]=e;});
var activeEvent=null;
function qs(s,r){return (r||document).querySelector(s)}function qsa(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))}
function esc(v){var d=document.createElement('div');d.textContent=v==null?'':String(v);return d.innerHTML}
function title(v){return String(v||'').replace(/_/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase()})}
function money(v){var n=Number(v||0),d=parseInt(CURRENCY.decimal_places||2,10);if(isNaN(d))d=2;var value=n.toFixed(d),sym=String(CURRENCY.symbol||'');return String(CURRENCY.symbol_position||'before')==='after'?value+sym:sym+value}
function fmt(dt){if(!dt)return '-';var d=new Date(String(dt).replace(' ','T'));if(isNaN(d.getTime()))return dt;return d.toLocaleString([], {month:'short',day:'2-digit',year:'numeric',hour:'numeric',minute:'2-digit'})}
function toast(msg,error){var t=qs('#scheduleToast');t.textContent=msg;t.className='sch2-toast show'+(error?' error':'');setTimeout(function(){t.className='sch2-toast'},2600)}
function closeDropdowns(except){qsa('.sch2-dropdown.open').forEach(function(d){if(d!==except)d.classList.remove('open')})}
qsa('[data-dropdown]').forEach(function(btn){btn.addEventListener('click',function(e){e.stopPropagation();var d=qs('#'+btn.getAttribute('data-dropdown'));var open=d.classList.contains('open');closeDropdowns();if(!open)d.classList.add('open')})});
document.addEventListener('click',function(e){if(!e.target.closest('.sch2-filter-wrap')&&!e.target.closest('.sch2-menu-wrap'))closeDropdowns()});
function submitTeam(){var checked=qsa('.team-check:checked').map(function(x){return x.value});qs('#teamFilterFlag').value='1';qs('#teamIdsInput').value=checked.join(',');qs('#teamUnassignedInput').value=qs('#teamUnassigned').checked?'1':'0';qs('#scheduleHiddenFilter').submit()}
qsa('.team-check').forEach(function(x){x.addEventListener('change',function(){submitTeam()})});qs('#teamUnassigned').addEventListener('change',submitTeam);
qs('#teamSelectAll').addEventListener('click',function(){qsa('.team-check').forEach(function(x){x.checked=true});qs('#teamUnassigned').checked=true;qs('#teamFilterFlag').value='0';qs('#teamIdsInput').value='';qs('#teamUnassignedInput').value='1';qs('#scheduleHiddenFilter').submit()});
qs('#teamClear').addEventListener('click',function(){qsa('.team-check').forEach(function(x){x.checked=false});qs('#teamUnassigned').checked=false;submitTeam()});
qs('#teamSearch').addEventListener('input',function(){var q=this.value.toLowerCase().trim();qsa('.team-choice').forEach(function(row){row.style.display=!q||String(row.getAttribute('data-search')||'').indexOf(q)!==-1?'flex':'none'})});
function submitStatus(){var checked=qsa('.status-check:checked').map(function(x){return x.value});qs('#statusFilterFlag').value='1';qs('#statusesInput').value=checked.join(',');qs('#scheduleHiddenFilter').submit()}
qsa('.status-check').forEach(function(x){x.addEventListener('change',submitStatus)});qs('#statusSelectAll').addEventListener('click',function(){qs('#statusFilterFlag').value='0';qs('#statusesInput').value='';qs('#scheduleHiddenFilter').submit()});qs('#statusClear').addEventListener('click',function(){qsa('.status-check').forEach(function(x){x.checked=false});submitStatus()});
function teamHtml(e){if(!e.assignee_names||!e.assignee_names.length)return '<span style="color:#8996a3">Unassigned</span>';return '<div class="sch2-team-chips">'+e.assignee_names.map(function(n){var initials=n.split(/\s+/).map(function(p){return p.charAt(0)}).join('').slice(0,2).toUpperCase();return '<span class="sch2-team-chip"><span class="sch2-chip-avatar">'+esc(initials)+'</span>'+esc(n)+'</span>'}).join('')+'</div>'}
function linesHtml(e){if(!e.line_items||!e.line_items.length)return '<div style="padding:8px;color:#8894a0">No line items.</div>';var h=e.line_items.map(function(i){return '<div class="sch2-pop-line"><span>'+esc(Number(i.quantity||0))+'x&nbsp; '+esc(i.name)+'</span><strong>'+money(i.line_total)+'</strong></div>'}).join('');return h+'<div class="sch2-pop-total">Total '+money(e.total)+'</div>'}
function showPopover(btn,e){activeEvent=e;qs('#popKicker').textContent=e.source==='visit'?'Visit':'Job';qs('#popTitle').textContent=e.service||e.title||'Scheduled work';var complete=String(e.status||'').toLowerCase()==='completed';qs('#popBody').innerHTML='<label style="display:flex;align-items:center;gap:7px;margin-bottom:8px"><input type="checkbox" id="popCompleted" '+(complete?'checked':'')+' '+(complete?'disabled':'')+'> Completed</label><div class="sch2-pop-row"><span class="sch2-pop-label">Details</span>'+esc(e.customer||'-')+' · <a href="job-view.php?job_id='+Number(e.job_id)+'" style="color:#4f8b25">'+esc(e.job_no||'Job')+'</a></div><div class="sch2-pop-row"><span class="sch2-pop-label">Team</span>'+teamHtml(e)+'</div><div class="sch2-pop-row"><span class="sch2-pop-label">Location</span>'+esc(e.location||'-')+'</div><div class="sch2-pop-row"><span class="sch2-pop-label">Start</span>'+esc(fmt(e.start))+'</div><div class="sch2-pop-row"><span class="sch2-pop-label">Line items</span><div class="sch2-pop-lines">'+linesHtml(e)+'</div></div>';
qs('#popEdit').href='job-form.php?job_id='+Number(e.job_id);var p=qs('#eventPopover');p.classList.add('open');p.setAttribute('aria-hidden','false');var r=btn.getBoundingClientRect();var w=335;var left=Math.min(window.innerWidth-w-10,Math.max(10,r.left));var top=r.bottom+7;if(top+420>window.innerHeight)top=Math.max(74,r.top-420);p.style.left=left+'px';p.style.top=top+'px';var c=qs('#popCompleted');if(c&&!complete)c.addEventListener('change',function(){if(c.checked)markComplete(e)})}
function hidePopover(){var p=qs('#eventPopover');p.classList.remove('open');p.setAttribute('aria-hidden','true')}
qsa('.schedule-event').forEach(function(btn){btn.addEventListener('click',function(ev){ev.preventDefault();ev.stopPropagation();var e=eventMap[btn.getAttribute('data-event-key')];if(e)showPopover(btn,e)})});qs('#popClose').addEventListener('click',hidePopover);document.addEventListener('click',function(e){if(!e.target.closest('#eventPopover')&&!e.target.closest('.schedule-event'))hidePopover()});
function modalPanel(name){qsa('.sch2-tab').forEach(function(t){t.classList.toggle('active',t.getAttribute('data-tab')===name)});qsa('.sch2-tab-panel').forEach(function(p){p.classList.toggle('active',p.getAttribute('data-panel')===name)})}
function openModal(e){activeEvent=e;hidePopover();qs('#modalVisitName').textContent=e.service||e.title||'Scheduled work';qs('#modalCustomer').innerHTML=esc(e.customer||'-')+'<br>'+esc(e.location||'');qs('#modalMeta').innerHTML='<div class="sch2-visit-meta-row"><i class="bi bi-calendar3"></i><span>'+esc(fmt(e.start))+'</span></div>'+(e.client_phone?'<div class="sch2-visit-meta-row"><i class="bi bi-telephone"></i><span>'+esc(e.client_phone)+'</span></div>':'')+(e.location?'<div class="sch2-visit-meta-row"><i class="bi bi-geo-alt"></i><span>'+esc(e.location)+'</span></div>':'');qs('#modalEdit').href='job-form.php?job_id='+Number(e.job_id);var completed=String(e.status||'').toLowerCase()==='completed';qs('#modalComplete').disabled=completed;qs('#modalComplete').textContent=completed?'Completed':'Mark Complete';qs('#modalInfo').innerHTML='<div class="sch2-info-block"><div class="sch2-info-title">Instructions</div><div class="sch2-info-text">'+esc(e.instructions||'No additional instructions')+'</div></div><div class="sch2-info-block"><div class="sch2-info-grid"><div><div class="sch2-info-title">Job</div><div class="sch2-info-text"><a href="job-view.php?job_id='+Number(e.job_id)+'" style="color:#4f8b25">'+esc(e.job_no||'Job')+'</a><br>'+esc(e.title||'')+'</div></div><div><div class="sch2-info-title">Team</div>'+teamHtml(e)+'</div></div></div><div class="sch2-info-block"><div class="sch2-info-title">Line items</div><div class="sch2-modal-line-items">'+(e.line_items&&e.line_items.length?e.line_items.map(function(i){return '<div class="sch2-modal-line"><span>'+esc(i.name)+'<span class="desc">'+esc(i.description||'')+'</span></span><span>'+esc(Number(i.quantity||0))+'</span></div>'}).join(''):'<div class="sch2-info-text">No line items.</div>')+'</div></div>';
qs('#modalClient').innerHTML='<div class="sch2-info-block"><div class="sch2-info-title">Customer</div><div class="sch2-info-text">'+esc(e.customer||'-')+'<br>'+esc(e.client_phone||'')+'<br>'+esc(e.client_email||'')+'</div></div><div class="sch2-info-block"><div class="sch2-info-title">Service Location</div><div class="sch2-info-text">'+esc(e.location||'-')+'</div></div>';
qs('#modalNotes').innerHTML='<div class="sch2-info-block"><div class="sch2-info-title">Visit / Job Notes</div><div class="sch2-info-text">'+esc(e.instructions||'No notes')+'</div></div>';
modalPanel('info');qs('#visitModal').classList.add('open');qs('#visitModal').setAttribute('aria-hidden','false')}
function closeModal(){qs('#visitModal').classList.remove('open');qs('#visitModal').setAttribute('aria-hidden','true')}
qs('#popDetails').addEventListener('click',function(){if(activeEvent)openModal(activeEvent)});qs('#popFindTime').addEventListener('click',function(){if(activeEvent)window.location.href='schedule.php?view=day&date='+encodeURIComponent(String(activeEvent.start).slice(0,10))});qs('#visitModalClose').addEventListener('click',closeModal);qs('#visitModal').addEventListener('click',function(e){if(e.target===this)closeModal()});qsa('.sch2-tab').forEach(function(t){t.addEventListener('click',function(){modalPanel(t.getAttribute('data-tab'))})});
function markComplete(e){var fd=new FormData();fd.append('schedule_action','mark_complete');fd.append('csrf_token',CSRF);fd.append('visit_id',String(e.visit_id||0));fd.append('job_id',String(e.job_id||0));fetch('schedule.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json().then(function(j){if(!r.ok||!j.success)throw new Error(j.message||'Unable to complete visit.');return j})}).then(function(j){toast(j.message||'Visit marked complete.');setTimeout(function(){window.location.reload()},450)}).catch(function(err){toast(err.message||'Unable to complete visit.',true)})}
qs('#modalComplete').addEventListener('click',function(){if(activeEvent)markComplete(activeEvent)});
var anyToggle=qs('#anytimeToggle'),anyDrawer=qs('#anytimeDrawer'),anyClose=qs('#anytimeClose');if(anyToggle&&anyDrawer)anyToggle.addEventListener('click',function(){anyDrawer.classList.toggle('open')});if(anyClose&&anyDrawer)anyClose.addEventListener('click',function(){anyDrawer.classList.remove('open')});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){hidePopover();closeModal();closeDropdowns();if(anyDrawer)anyDrawer.classList.remove('open')}});
})();
</script>
</body>
</html>
