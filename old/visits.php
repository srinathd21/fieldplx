<?php
/**
 * FieldPlx - Visits List
 *
 * Upload as:
 * /public_html/visits.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and permission
|--------------------------------------------------------------------------
|
| Visits are currently controlled through the Jobs module permissions.
|
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('visits.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'jobs.view',
        'You do not have permission to view visits.'
    );
}

$pageTitle = 'Visits - FieldPlx';
$activePage = 'visits';
$searchPlaceholder = 'Search visits...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

$canManage = function_exists('hasPermission')
    ? hasPermission('jobs.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('visitsFetchAssoc')) {
    function visitsFetchAssoc(mysqli_stmt $stmt)
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

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

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

if (!function_exists('visitsFetchAll')) {
    function visitsFetchAll(mysqli_stmt $stmt)
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

        call_user_func_array(
            array($stmt, 'bind_result'),
            $bind
        );

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

if (!function_exists('visitsBindParams')) {
    function visitsBindParams(
        mysqli_stmt $stmt,
        $types,
        array &$params
    ) {
        if ($types === '' || empty($params)) {
            return true;
        }

        $arguments = array($types);

        foreach ($params as $key => $value) {
            $arguments[] = &$params[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('visitsDate')) {
    function visitsDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y', $timestamp)
            : '—';
    }
}

if (!function_exists('visitsDateTime')) {
    function visitsDateTime($value)
    {
        if (empty($value)) {
            return 'Not scheduled';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : 'Not scheduled';
    }
}

if (!function_exists('visitsLabel')) {
    function visitsLabel($value)
    {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $value
            )
        );
    }
}

if (!function_exists('visitsStatusClass')) {
    function visitsStatusClass($value)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $value))
        );
    }
}

if (!function_exists('visitsQueryString')) {
    function visitsQueryString(array $overrides = array())
    {
        $query = $_GET;

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return http_build_query($query);
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$jobFilter = isset($_GET['job_id'])
    ? (int) $_GET['job_id']
    : 0;

$clientFilter = isset($_GET['client_id'])
    ? (int) $_GET['client_id']
    : 0;

$workerFilter = isset($_GET['assigned_user_id'])
    ? (int) $_GET['assigned_user_id']
    : 0;

$scheduleFilter = isset($_GET['schedule'])
    ? trim((string) $_GET['schedule'])
    : '';

$invoiceFilter = isset($_GET['invoice'])
    ? trim((string) $_GET['invoice'])
    : '';

$dateFrom = isset($_GET['date_from'])
    ? trim((string) $_GET['date_from'])
    : '';

$dateTo = isset($_GET['date_to'])
    ? trim((string) $_GET['date_to'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'schedule_asc';

$allowedStatuses = array(
    '',
    'draft',
    'scheduled',
    'dispatched',
    'on_my_way',
    'in_progress',
    'completed',
    'missed',
    'cancelled',
    'needs_review'
);

$allowedSchedules = array(
    '',
    'today',
    'upcoming',
    'overdue',
    'scheduled',
    'unscheduled',
    'past'
);

$allowedInvoiceFilters = array(
    '',
    'required',
    'not_required'
);

$allowedSorts = array(
    'schedule_asc',
    'schedule_desc',
    'latest',
    'oldest',
    'visit_no',
    'client_asc',
    'worker_asc',
    'status_asc'
);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if (!in_array($scheduleFilter, $allowedSchedules, true)) {
    $scheduleFilter = '';
}

if (!in_array($invoiceFilter, $allowedInvoiceFilters, true)) {
    $invoiceFilter = '';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'schedule_asc';
}

if (
    $dateFrom !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
) {
    $dateFrom = '';
}

if (
    $dateTo !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
) {
    $dateTo = '';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 20;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Filter option data
|--------------------------------------------------------------------------
*/

$jobOptions = array();
$clientOptions = array();
$workerOptions = array();

$stmt = $conn->prepare("
    SELECT
        id,
        job_no,
        title,
        status
    FROM jobs
    WHERE tenant_id = ?
      AND deleted_at IS NULL
    ORDER BY created_at DESC, id DESC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $jobOptions = visitsFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        display_name
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $clientOptions = visitsFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name,
        email,
        is_field_worker
    FROM users
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status = 'active'
    ORDER BY first_name ASC, last_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $workerOptions = visitsFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Summary statistics
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'today' => 0,
    'upcoming' => 0,
    'active' => 0,
    'needs_review' => 0,
    'completed' => 0
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,

        SUM(
            scheduled_start IS NOT NULL
            AND DATE(scheduled_start) = CURDATE()
            AND status <> 'cancelled'
        ) AS today_count,

        SUM(
            scheduled_start IS NOT NULL
            AND scheduled_start > NOW()
            AND status NOT IN (
                'completed',
                'missed',
                'cancelled'
            )
        ) AS upcoming_count,

        SUM(
            status IN (
                'dispatched',
                'on_my_way',
                'in_progress'
            )
        ) AS active_count,

        SUM(status = 'needs_review')
            AS needs_review_count,

        SUM(status = 'completed')
            AS completed_count

    FROM visits
    WHERE tenant_id = ?
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = visitsFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['total'] =
            (int) $row['total_count'];

        $stats['today'] =
            (int) $row['today_count'];

        $stats['upcoming'] =
            (int) $row['upcoming_count'];

        $stats['active'] =
            (int) $row['active_count'];

        $stats['needs_review'] =
            (int) $row['needs_review_count'];

        $stats['completed'] =
            (int) $row['completed_count'];
    }
}

/*
|--------------------------------------------------------------------------
| Build filtered query
|--------------------------------------------------------------------------
*/

$where = array(
    'v.tenant_id = ?'
);

$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        v.visit_no LIKE ?
        OR j.job_no LIKE ?
        OR j.title LIKE ?
        OR c.display_name LIKE ?
        OR p.name LIKE ?
        OR p.address_line1 LIKE ?
        OR p.city LIKE ?
        OR CONCAT(
            COALESCE(u.first_name, ''),
            ' ',
            COALESCE(u.last_name, '')
        ) LIKE ?
        OR v.instructions LIKE ?
        OR v.completion_notes LIKE ?
    )";

    $searchLike = '%' . $search . '%';

    for ($index = 0; $index < 10; $index++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($statusFilter !== '') {
    $where[] = 'v.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($jobFilter > 0) {
    $where[] = 'v.job_id = ?';
    $params[] = $jobFilter;
    $types .= 'i';
}

if ($clientFilter > 0) {
    $where[] = 'j.client_id = ?';
    $params[] = $clientFilter;
    $types .= 'i';
}

if ($workerFilter > 0) {
    $where[] = 'v.assigned_user_id = ?';
    $params[] = $workerFilter;
    $types .= 'i';
}

if ($scheduleFilter === 'today') {
    $where[] = "
        v.scheduled_start IS NOT NULL
        AND DATE(v.scheduled_start) = CURDATE()
    ";
} elseif ($scheduleFilter === 'upcoming') {
    $where[] = "
        v.scheduled_start IS NOT NULL
        AND v.scheduled_start > NOW()
        AND v.status NOT IN (
            'completed',
            'missed',
            'cancelled'
        )
    ";
} elseif ($scheduleFilter === 'overdue') {
    $where[] = "
        v.scheduled_end IS NOT NULL
        AND v.scheduled_end < NOW()
        AND v.status NOT IN (
            'completed',
            'missed',
            'cancelled'
        )
    ";
} elseif ($scheduleFilter === 'scheduled') {
    $where[] = 'v.scheduled_start IS NOT NULL';
} elseif ($scheduleFilter === 'unscheduled') {
    $where[] = 'v.scheduled_start IS NULL';
} elseif ($scheduleFilter === 'past') {
    $where[] = "
        v.scheduled_end IS NOT NULL
        AND v.scheduled_end < NOW()
    ";
}

if ($invoiceFilter === 'required') {
    $where[] = 'v.requires_invoice = 1';
} elseif ($invoiceFilter === 'not_required') {
    $where[] = 'v.requires_invoice = 0';
}

if ($dateFrom !== '') {
    $where[] = 'DATE(v.scheduled_start) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = 'DATE(v.scheduled_start) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$orderSql = "
    CASE
        WHEN v.scheduled_start IS NULL THEN 1
        ELSE 0
    END ASC,
    v.scheduled_start ASC,
    v.id DESC
";

if ($sort === 'schedule_desc') {
    $orderSql = "
        CASE
            WHEN v.scheduled_start IS NULL THEN 1
            ELSE 0
        END ASC,
        v.scheduled_start DESC,
        v.id DESC
    ";
} elseif ($sort === 'latest') {
    $orderSql = 'v.created_at DESC, v.id DESC';
} elseif ($sort === 'oldest') {
    $orderSql = 'v.created_at ASC, v.id ASC';
} elseif ($sort === 'visit_no') {
    $orderSql = 'v.visit_no ASC, v.id ASC';
} elseif ($sort === 'client_asc') {
    $orderSql = 'c.display_name ASC, v.scheduled_start ASC';
} elseif ($sort === 'worker_asc') {
    $orderSql = "
        u.first_name ASC,
        u.last_name ASC,
        v.scheduled_start ASC
    ";
} elseif ($sort === 'status_asc') {
    $orderSql = 'v.status ASC, v.scheduled_start ASC';
}

/*
|--------------------------------------------------------------------------
| Count filtered visits
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM visits v

    INNER JOIN jobs j
        ON j.id = v.job_id
       AND j.tenant_id = v.tenant_id
       AND j.deleted_at IS NULL

    INNER JOIN clients c
        ON c.id = j.client_id
       AND c.tenant_id = j.tenant_id
       AND c.deleted_at IS NULL

    LEFT JOIN properties p
        ON p.id = j.property_id
       AND p.tenant_id = j.tenant_id
       AND p.deleted_at IS NULL

    LEFT JOIN users u
        ON u.id = v.assigned_user_id
       AND u.tenant_id = v.tenant_id
       AND u.deleted_at IS NULL

    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare the visit count query: ' .
        $conn->error;
} else {
    if (!visitsBindParams($stmt, $types, $params)) {
        $errors[] =
            'Unable to bind visit filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to count visits: ' .
            $stmt->error;
    } else {
        $row = visitsFetchAssoc($stmt);

        if ($row) {
            $totalFiltered =
                (int) $row['total'];
        }
    }

    $stmt->close();
}

$totalPages = max(
    1,
    (int) ceil($totalFiltered / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Load visits
|--------------------------------------------------------------------------
*/

$visits = array();

$listSql = "
    SELECT
        v.id,
        v.visit_no,
        v.job_id,
        v.assigned_user_id,
        v.scheduled_start,
        v.scheduled_end,
        v.actual_start,
        v.actual_end,
        v.status,
        v.instructions,
        v.completion_notes,
        v.requires_invoice,
        v.created_at,
        v.updated_at,

        j.job_no,
        j.title AS job_title,
        j.status AS job_status,
        j.client_id,
        j.property_id,

        c.display_name AS client_name,
        c.phone AS client_phone,
        c.email AS client_email,

        p.name AS property_name,
        p.address_line1 AS property_address_line1,
        p.address_line2 AS property_address_line2,
        p.city AS property_city,
        p.state AS property_state,
        p.postal_code AS property_postal_code,

        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS assigned_user_name,

        u.phone AS assigned_user_phone,
        u.job_title AS assigned_user_job_title,
        u.color_code AS assigned_user_color

    FROM visits v

    INNER JOIN jobs j
        ON j.id = v.job_id
       AND j.tenant_id = v.tenant_id
       AND j.deleted_at IS NULL

    INNER JOIN clients c
        ON c.id = j.client_id
       AND c.tenant_id = j.tenant_id
       AND c.deleted_at IS NULL

    LEFT JOIN properties p
        ON p.id = j.property_id
       AND p.tenant_id = j.tenant_id
       AND p.deleted_at IS NULL

    LEFT JOIN users u
        ON u.id = v.assigned_user_id
       AND u.tenant_id = v.tenant_id
       AND u.deleted_at IS NULL

    WHERE {$whereSql}

    ORDER BY {$orderSql}

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($listSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare the visit list query: ' .
        $conn->error;
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    if (
        !visitsBindParams(
            $stmt,
            $listTypes,
            $listParams
        )
    ) {
        $errors[] =
            'Unable to bind visit list filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to load visits: ' .
            $stmt->error;
    } else {
        $visits =
            visitsFetchAll($stmt);
    }

    $stmt->close();
}

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.visits-page {
    --visit-primary: #6d28d9;
    --visit-text: #111827;
    --visit-muted: #6b7280;
    --visit-border: #e5e7eb;
}

.visits-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.visits-header h1 {
    margin: 0;
    color: var(--visit-text);
    font-size: 21px;
    font-weight: 700;
}

.visits-header p {
    margin: 5px 0 0;
    color: var(--visit-muted);
    font-size: 11px;
}

.visits-add {
    min-height: 35px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 9px;
    background: var(--visit-primary);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.visits-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
}

.visits-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.visits-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.visits-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(6,minmax(0,1fr));
    gap: 10px;
}

.visits-stat {
    padding: 13px;
    border: 1px solid var(--visit-border);
    border-radius: 11px;
    background: #fff;
}

.visits-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.visits-stat-value {
    margin-top: 4px;
    color: var(--visit-text);
    font-size: 19px;
    font-weight: 700;
}

.visits-panel {
    overflow: hidden;
    border: 1px solid var(--visit-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.visits-filters {
    padding: 12px;
    display: grid;
    grid-template-columns:
        minmax(220px,1.3fr)
        minmax(140px,.65fr)
        minmax(170px,.8fr)
        minmax(155px,.72fr)
        minmax(155px,.72fr)
        minmax(140px,.65fr)
        minmax(130px,.6fr)
        minmax(120px,.55fr)
        minmax(120px,.55fr)
        minmax(150px,.68fr)
        auto;
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.visits-input,
.visits-select {
    width: 100%;
    min-height: 36px;
    padding: 8px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 8px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 9px;
    outline: none;
}

.visits-input:focus,
.visits-select:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.08);
}

.visits-filter-actions {
    display: flex;
    gap: 6px;
}

.visits-filter-btn,
.visits-reset {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 700;
}

.visits-filter-btn {
    border: 0;
    background: var(--visit-primary);
    color: #fff;
    cursor: pointer;
}

.visits-reset {
    border: 1px solid var(--visit-border);
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.visits-table-wrap {
    overflow-x: auto;
}

.visits-table {
    width: 100%;
    border-collapse: collapse;
}

.visits-table th,
.visits-table td {
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    white-space: nowrap;
    vertical-align: middle;
}

.visits-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.visits-table td {
    color: #374151;
    font-size: 9px;
}

.visit-main {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.visit-sub {
    margin-top: 2px;
    display: block;
    max-width: 250px;
    overflow: hidden;
    color: #9ca3af;
    font-size: 8px;
    text-overflow: ellipsis;
}

.visit-badge {
    padding: 4px 7px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
}

.visit-badge.draft {
    background: #f3f4f6;
    color: #4b5563;
}

.visit-badge.scheduled {
    background: #eff6ff;
    color: #1d4ed8;
}

.visit-badge.dispatched {
    background: #f5f3ff;
    color: #6d28d9;
}

.visit-badge.on_my_way {
    background: #ecfeff;
    color: #0e7490;
}

.visit-badge.in_progress {
    background: #fff7ed;
    color: #c2410c;
}

.visit-badge.completed {
    background: #ecfdf5;
    color: #047857;
}

.visit-badge.needs_review {
    background: #fffbeb;
    color: #b45309;
}

.visit-badge.missed,
.visit-badge.cancelled {
    background: #fef2f2;
    color: #b91c1c;
}

.visit-invoice {
    margin-left: 5px;
    padding: 3px 6px;
    display: inline-flex;
    border-radius: 999px;
    background: #f5f3ff;
    color: #6d28d9;
    font-size: 7px;
    font-weight: 700;
}

.visit-overdue {
    margin-left: 5px;
    padding: 3px 6px;
    display: inline-flex;
    border-radius: 999px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 7px;
    font-weight: 700;
}

.visit-worker {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.visit-worker-dot {
    width: 8px;
    height: 8px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: #d1d5db;
}

.visit-actions {
    display: flex;
    justify-content: flex-end;
    gap: 5px;
}

.visit-action {
    width: 29px;
    height: 29px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--visit-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.visit-action:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--visit-primary);
}

.visits-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #f1f5f9;
}

.visits-result {
    color: #6b7280;
    font-size: 9px;
}

.visits-pages {
    display: flex;
    gap: 5px;
}

.visits-page-link {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--visit-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.visits-page-link.active {
    border-color: var(--visit-primary);
    background: var(--visit-primary);
    color: #fff;
}

.visits-empty {
    padding: 42px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1650px) {
    .visits-filters {
        grid-template-columns: repeat(5,minmax(0,1fr));
    }
}

@media (max-width: 1100px) {
    .visits-stats {
        grid-template-columns: repeat(3,minmax(0,1fr));
    }

    .visits-filters {
        grid-template-columns: repeat(3,minmax(0,1fr));
    }
}

@media (max-width: 760px) {
    .visits-header {
        flex-direction: column;
    }

    .visits-filters {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 560px) {
    .visits-stats,
    .visits-filters {
        grid-template-columns: 1fr;
    }

    .visits-filter-actions {
        width: 100%;
    }

    .visits-filter-btn,
    .visits-reset {
        flex: 1;
    }

    .visits-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="visits-page">
    <div class="visits-header">
        <div>
            <h1>Visits</h1>
            <p>
                Manage field visits, schedules, assigned workers, progress, and invoicing requirements.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a href="visit-add.php" class="visits-add">
                <i class="bi bi-plus-lg"></i>
                Add Visit
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="visits-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="visits-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="visits-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="visits-stats">
        <article class="visits-stat">
            <div class="visits-stat-label">
                Total Visits
            </div>
            <div class="visits-stat-value">
                <?= e($stats['total']); ?>
            </div>
        </article>

        <article class="visits-stat">
            <div class="visits-stat-label">
                Today
            </div>
            <div class="visits-stat-value">
                <?= e($stats['today']); ?>
            </div>
        </article>

        <article class="visits-stat">
            <div class="visits-stat-label">
                Upcoming
            </div>
            <div class="visits-stat-value">
                <?= e($stats['upcoming']); ?>
            </div>
        </article>

        <article class="visits-stat">
            <div class="visits-stat-label">
                Active
            </div>
            <div class="visits-stat-value">
                <?= e($stats['active']); ?>
            </div>
        </article>

        <article class="visits-stat">
            <div class="visits-stat-label">
                Needs Review
            </div>
            <div class="visits-stat-value">
                <?= e($stats['needs_review']); ?>
            </div>
        </article>

        <article class="visits-stat">
            <div class="visits-stat-label">
                Completed
            </div>
            <div class="visits-stat-value">
                <?= e($stats['completed']); ?>
            </div>
        </article>
    </section>

    <section class="visits-panel">
        <form method="get" action="" class="visits-filters">
            <input
                type="search"
                name="search"
                class="visits-input"
                value="<?= e($search); ?>"
                placeholder="Search visit, job, client, property, worker or notes"
            >

            <select name="status" class="visits-select">
                <option value="">All Statuses</option>

                <?php foreach (
                    array(
                        'draft' => 'Draft',
                        'scheduled' => 'Scheduled',
                        'dispatched' => 'Dispatched',
                        'on_my_way' => 'On My Way',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'missed' => 'Missed',
                        'cancelled' => 'Cancelled',
                        'needs_review' => 'Needs Review'
                    ) as $value => $label
                ): ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $statusFilter === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="job_id" class="visits-select">
                <option value="">All Jobs</option>

                <?php foreach ($jobOptions as $job): ?>
                    <option
                        value="<?= (int) $job['id']; ?>"
                        <?= $jobFilter === (int) $job['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($job['job_no']); ?>
                        · <?= e($job['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="client_id" class="visits-select">
                <option value="">All Clients</option>

                <?php foreach ($clientOptions as $client): ?>
                    <option
                        value="<?= (int) $client['id']; ?>"
                        <?= $clientFilter === (int) $client['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($client['display_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="assigned_user_id"
                class="visits-select"
            >
                <option value="">All Workers</option>

                <?php foreach ($workerOptions as $worker): ?>
                    <?php
                    $workerName = trim(
                        (string) $worker['first_name'] .
                        ' ' .
                        (string) $worker['last_name']
                    );
                    ?>
                    <option
                        value="<?= (int) $worker['id']; ?>"
                        <?= $workerFilter === (int) $worker['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($workerName); ?>

                        <?php if (
                            !empty($worker['is_field_worker'])
                        ): ?>
                            · Field Worker
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="schedule" class="visits-select">
                <option value="">All Schedules</option>

                <option
                    value="today"
                    <?= $scheduleFilter === 'today'
                        ? 'selected'
                        : ''; ?>
                >
                    Today
                </option>

                <option
                    value="upcoming"
                    <?= $scheduleFilter === 'upcoming'
                        ? 'selected'
                        : ''; ?>
                >
                    Upcoming
                </option>

                <option
                    value="overdue"
                    <?= $scheduleFilter === 'overdue'
                        ? 'selected'
                        : ''; ?>
                >
                    Overdue
                </option>

                <option
                    value="scheduled"
                    <?= $scheduleFilter === 'scheduled'
                        ? 'selected'
                        : ''; ?>
                >
                    Scheduled
                </option>

                <option
                    value="unscheduled"
                    <?= $scheduleFilter === 'unscheduled'
                        ? 'selected'
                        : ''; ?>
                >
                    Unscheduled
                </option>

                <option
                    value="past"
                    <?= $scheduleFilter === 'past'
                        ? 'selected'
                        : ''; ?>
                >
                    Past
                </option>
            </select>

            <select name="invoice" class="visits-select">
                <option value="">Invoice: All</option>

                <option
                    value="required"
                    <?= $invoiceFilter === 'required'
                        ? 'selected'
                        : ''; ?>
                >
                    Invoice Required
                </option>

                <option
                    value="not_required"
                    <?= $invoiceFilter === 'not_required'
                        ? 'selected'
                        : ''; ?>
                >
                    Invoice Not Required
                </option>
            </select>

            <input
                type="date"
                name="date_from"
                class="visits-input"
                value="<?= e($dateFrom); ?>"
                title="Scheduled from"
            >

            <input
                type="date"
                name="date_to"
                class="visits-input"
                value="<?= e($dateTo); ?>"
                title="Scheduled to"
            >

            <select name="sort" class="visits-select">
                <option
                    value="schedule_asc"
                    <?= $sort === 'schedule_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Schedule Ascending
                </option>

                <option
                    value="schedule_desc"
                    <?= $sort === 'schedule_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Schedule Descending
                </option>

                <option
                    value="latest"
                    <?= $sort === 'latest'
                        ? 'selected'
                        : ''; ?>
                >
                    Latest Created
                </option>

                <option
                    value="oldest"
                    <?= $sort === 'oldest'
                        ? 'selected'
                        : ''; ?>
                >
                    Oldest Created
                </option>

                <option
                    value="visit_no"
                    <?= $sort === 'visit_no'
                        ? 'selected'
                        : ''; ?>
                >
                    Visit Number
                </option>

                <option
                    value="client_asc"
                    <?= $sort === 'client_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Client A-Z
                </option>

                <option
                    value="worker_asc"
                    <?= $sort === 'worker_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Worker A-Z
                </option>

                <option
                    value="status_asc"
                    <?= $sort === 'status_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Status
                </option>
            </select>

            <div class="visits-filter-actions">
                <button
                    type="submit"
                    class="visits-filter-btn"
                >
                    Apply
                </button>

                <a
                    href="visits.php"
                    class="visits-reset"
                >
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($visits)): ?>
            <div class="visits-table-wrap">
                <table class="visits-table">
                    <thead>
                        <tr>
                            <th>Visit</th>
                            <th>Job</th>
                            <th>Client</th>
                            <th>Property</th>
                            <th>Worker</th>
                            <th>Schedule</th>
                            <th>Actual Time</th>
                            <th>Status</th>
                            <th>Invoice</th>
                            <th>Created</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($visits as $visit): ?>
                        <?php
                        $workerName = trim(
                            (string) $visit['assigned_user_name']
                        );

                        $propertyName =
                            trim((string) $visit['property_name']) !== ''
                                ? (string) $visit['property_name']
                                : (
                                    trim(
                                        (string) $visit['property_address_line1']
                                    ) !== ''
                                        ? (string) $visit['property_address_line1']
                                        : '—'
                                );

                        $propertyAddress = implode(
                            ', ',
                            array_filter(
                                array(
                                    $visit['property_address_line1'],
                                    $visit['property_address_line2'],
                                    $visit['property_city'],
                                    $visit['property_state'],
                                    $visit['property_postal_code']
                                ),
                                function ($value) {
                                    return trim((string) $value) !== '';
                                }
                            )
                        );

                        $isOverdue =
                            !empty($visit['scheduled_end']) &&
                            strtotime((string) $visit['scheduled_end']) <
                                time() &&
                            !in_array(
                                $visit['status'],
                                array(
                                    'completed',
                                    'missed',
                                    'cancelled'
                                ),
                                true
                            );

                        $workerColor = trim(
                            (string) $visit['assigned_user_color']
                        );

                        if (
                            $workerColor === '' ||
                            !preg_match(
                                '/^#[0-9A-Fa-f]{3,8}$/',
                                $workerColor
                            )
                        ) {
                            $workerColor = '#d1d5db';
                        }
                        ?>
                        <tr>
                            <td>
                                <a
                                    href="visit-view.php?id=<?= (int) $visit['id']; ?>"
                                    class="visit-main"
                                >
                                    <?= e(
                                        trim((string) $visit['visit_no']) !== ''
                                            ? $visit['visit_no']
                                            : 'Visit #' . $visit['id']
                                    ); ?>
                                </a>

                                <?php if (
                                    trim((string) $visit['instructions']) !== ''
                                ): ?>
                                    <span class="visit-sub">
                                        <?= e($visit['instructions']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <a
                                    href="job-view.php?id=<?= (int) $visit['job_id']; ?>"
                                    class="visit-main"
                                >
                                    <?= e($visit['job_no']); ?>
                                </a>

                                <span class="visit-sub">
                                    <?= e($visit['job_title']); ?>
                                </span>
                            </td>

                            <td>
                                <a
                                    href="client-view.php?id=<?= (int) $visit['client_id']; ?>"
                                    class="visit-main"
                                >
                                    <?= e($visit['client_name']); ?>
                                </a>

                                <?php if (
                                    trim((string) $visit['client_phone']) !== ''
                                ): ?>
                                    <span class="visit-sub">
                                        <?= e($visit['client_phone']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($visit['property_id'])): ?>
                                    <a
                                        href="property-view.php?id=<?= (int) $visit['property_id']; ?>"
                                        class="visit-main"
                                    >
                                        <?= e($propertyName); ?>
                                    </a>

                                    <?php if (
                                        $propertyAddress !== '' &&
                                        $propertyAddress !== $propertyName
                                    ): ?>
                                        <span class="visit-sub">
                                            <?= e($propertyAddress); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="visit-worker">
                                    <span
                                        class="visit-worker-dot"
                                        style="background:<?= e($workerColor); ?>;"
                                    ></span>

                                    <span>
                                        <?= e(
                                            $workerName !== ''
                                                ? $workerName
                                                : 'Unassigned'
                                        ); ?>
                                    </span>
                                </span>

                                <?php if (
                                    trim(
                                        (string) $visit['assigned_user_job_title']
                                    ) !== ''
                                ): ?>
                                    <span class="visit-sub">
                                        <?= e(
                                            $visit['assigned_user_job_title']
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="visit-main">
                                    <?= e(
                                        visitsDateTime(
                                            $visit['scheduled_start']
                                        )
                                    ); ?>
                                </span>

                                <?php if (
                                    !empty($visit['scheduled_end'])
                                ): ?>
                                    <span class="visit-sub">
                                        Ends:
                                        <?= e(
                                            visitsDateTime(
                                                $visit['scheduled_end']
                                            )
                                        ); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($isOverdue): ?>
                                    <span class="visit-overdue">
                                        Overdue
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (
                                    !empty($visit['actual_start'])
                                ): ?>
                                    <span class="visit-main">
                                        <?= e(
                                            visitsDateTime(
                                                $visit['actual_start']
                                            )
                                        ); ?>
                                    </span>

                                    <?php if (
                                        !empty($visit['actual_end'])
                                    ): ?>
                                        <span class="visit-sub">
                                            Ends:
                                            <?= e(
                                                visitsDateTime(
                                                    $visit['actual_end']
                                                )
                                            ); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="visit-sub">
                                            In progress
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="visit-badge <?= e(
                                    visitsStatusClass(
                                        $visit['status']
                                    )
                                ); ?>">
                                    <?= e(
                                        visitsLabel(
                                            $visit['status']
                                        )
                                    ); ?>
                                </span>

                                <?php if (
                                    trim(
                                        (string) $visit['completion_notes']
                                    ) !== ''
                                ): ?>
                                    <span class="visit-sub">
                                        <?= e(
                                            $visit['completion_notes']
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (
                                    !empty($visit['requires_invoice'])
                                ): ?>
                                    <span class="visit-invoice">
                                        Required
                                    </span>
                                <?php else: ?>
                                    <span class="visit-sub">
                                        Not required
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e(
                                    visitsDate(
                                        $visit['created_at']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <div class="visit-actions">
                                    <a
                                        href="visit-view.php?id=<?= (int) $visit['id']; ?>"
                                        class="visit-action"
                                        title="View Visit"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($canManage): ?>
                                        <a
                                            href="visit-edit.php?id=<?= (int) $visit['id']; ?>"
                                            class="visit-action"
                                            title="Edit Visit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>

                                    <a
                                        href="job-view.php?id=<?= (int) $visit['job_id']; ?>"
                                        class="visit-action"
                                        title="View Job"
                                    >
                                        <i class="bi bi-briefcase"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="visits-footer">
                <div class="visits-result">
                    Showing
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + 1
                        )
                    ); ?>
                    -
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + count($visits)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    visits
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="visits-pages">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    visitsQueryString(
                                        array(
                                            'page' => $page - 1
                                        )
                                    )
                                ); ?>"
                                class="visits-page-link"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min(
                            $totalPages,
                            $page + 2
                        );

                        for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(
                                    visitsQueryString(
                                        array(
                                            'page' => $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="visits-page-link <?= $pageNumber === $page ? 'active' : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    visitsQueryString(
                                        array(
                                            'page' => $page + 1
                                        )
                                    )
                                ); ?>"
                                class="visits-page-link"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="visits-empty">
                <?php if (
                    $search !== '' ||
                    $statusFilter !== '' ||
                    $jobFilter > 0 ||
                    $clientFilter > 0 ||
                    $workerFilter > 0 ||
                    $scheduleFilter !== '' ||
                    $invoiceFilter !== '' ||
                    $dateFrom !== '' ||
                    $dateTo !== ''
                ): ?>
                    No visits found for the selected filters.
                <?php else: ?>
                    No visits are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
