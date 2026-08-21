<?php
/**
 * FieldPlx - Jobs List
 *
 * Upload as:
 * /public_html/jobs.php
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
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('jobs.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'jobs.view',
        'You do not have permission to view jobs.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Jobs - FieldPlx';
$activePage = 'jobs';
$searchPlaceholder = 'Search jobs...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$errors = array();

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

$canManage = function_exists('hasPermission')
    ? hasPermission('jobs.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('jobsFetchAssoc')) {
    function jobsFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('jobsFetchAll')) {
    function jobsFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('jobsBindParams')) {
    function jobsBindParams(
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

if (!function_exists('jobsCsrfToken')) {
    function jobsCsrfToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));
            } catch (Throwable $error) {
                $_SESSION['csrf_token'] =
                    sha1(
                        uniqid(
                            (string) mt_rand(),
                            true
                        )
                    );
            }
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('jobsVerifyCsrf')) {
    function jobsVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('jobsStatusClass')) {
    function jobsStatusClass($status)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $status))
        );
    }
}

if (!function_exists('jobsDate')) {
    function jobsDate($value)
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

if (!function_exists('jobsMoney')) {
    function jobsMoney($amount, $currency)
    {
        return trim((string) $currency) .
            ' ' .
            number_format((float) $amount, 2);
    }
}

if (!function_exists('jobsBuildQueryString')) {
    function jobsBuildQueryString(
        array $overrides = array()
    ) {
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

if (!function_exists('jobsLogActivity')) {
    function jobsLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $jobId,
        $clientId,
        $jobNo,
        $title
    ) {
        $stmt = $conn->prepare("
            INSERT INTO activity_events (
                tenant_id,
                actor_user_id,
                actor_type,
                event_type,
                related_type,
                related_id,
                client_id,
                title,
                details_json,
                visible_to_client,
                created_at
            ) VALUES (
                ?,
                ?,
                'user',
                'job_archived',
                'job',
                ?,
                ?,
                ?,
                ?,
                0,
                NOW()
            )
        ");

        if (!$stmt) {
            return;
        }

        $activityTitle =
            'Job archived: ' .
            $jobNo .
            ' - ' .
            $title;

        $details = json_encode(
            array(
                'job_id' => (int) $jobId,
                'job_no' => (string) $jobNo,
                'title' => (string) $title
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $jobId,
            $clientId,
            $activityTitle,
            $details
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Archive job
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'archive'
) {
    if (!$canManage) {
        $errors[] =
            'You do not have permission to archive jobs.';
    }

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!jobsVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $archiveJobId = isset($_POST['job_id'])
        ? (int) $_POST['job_id']
        : 0;

    if ($archiveJobId <= 0) {
        $errors[] = 'Invalid job selected.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT
                id,
                client_id,
                job_no,
                title
            FROM jobs
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");

        $jobRow = null;

        if ($stmt) {
            $stmt->bind_param(
                'ii',
                $archiveJobId,
                $tenantId
            );

            $stmt->execute();
            $jobRow = jobsFetchAssoc($stmt);
            $stmt->close();
        }

        if (!$jobRow) {
            $errors[] = 'Job not found.';
        } else {
            $stmt = $conn->prepare("
                UPDATE jobs
                SET
                    status = 'archived',
                    deleted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                  AND deleted_at IS NULL
            ");

            if (!$stmt) {
                $errors[] =
                    'Unable to prepare archive operation.';
            } else {
                $stmt->bind_param(
                    'ii',
                    $archiveJobId,
                    $tenantId
                );

                if ($stmt->execute()) {
                    $stmt->close();

                    jobsLogActivity(
                        $conn,
                        $tenantId,
                        $currentUserId,
                        $archiveJobId,
                        (int) $jobRow['client_id'],
                        (string) $jobRow['job_no'],
                        (string) $jobRow['title']
                    );

                    $_SESSION['flash_success'] =
                        'Job archived successfully.';

                    header('Location: jobs.php');
                    exit;
                }

                $errors[] =
                    'Job could not be archived: ' .
                    $stmt->error;

                $stmt->close();
            }
        }
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

$clientFilter = isset($_GET['client_id'])
    ? (int) $_GET['client_id']
    : 0;

$assignedUserFilter = isset($_GET['assigned_user_id'])
    ? (int) $_GET['assigned_user_id']
    : 0;

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$typeFilter = isset($_GET['job_type'])
    ? trim((string) $_GET['job_type'])
    : '';

$dateFilter = isset($_GET['date_filter'])
    ? trim((string) $_GET['date_filter'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'latest';

$allowedStatuses = array(
    '',
    'draft',
    'active',
    'scheduled',
    'upcoming',
    'today',
    'in_progress',
    'completed',
    'late',
    'unscheduled',
    'action_required',
    'needs_review',
    'requires_invoicing',
    'ready_to_invoice',
    'invoiced',
    'ending_within_30_days',
    'closed',
    'cancelled',
    'archived'
);

$allowedTypes = array(
    '',
    'one_off',
    'recurring'
);

$allowedDates = array(
    '',
    'today',
    'this_week',
    'this_month',
    'overdue'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'start_asc',
    'start_desc',
    'title_asc',
    'title_desc',
    'total_desc'
);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = '';
}

if (!in_array($dateFilter, $allowedDates, true)) {
    $dateFilter = '';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 20;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Filter options
|--------------------------------------------------------------------------
*/

$clientOptions = array();
$userOptions = array();

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
    $clientOptions = jobsFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name
    FROM users
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status = 'active'
    ORDER BY first_name ASC, last_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $userOptions = jobsFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Stats
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'active' => 0,
    'completed' => 0,
    'invoicing' => 0
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(
            status IN (
                'active',
                'scheduled',
                'upcoming',
                'today',
                'in_progress'
            )
        ) AS active_count,
        SUM(status = 'completed') AS completed_count,
        SUM(
            status IN (
                'requires_invoicing',
                'ready_to_invoice'
            )
        ) AS invoicing_count
    FROM jobs
    WHERE tenant_id = ?
      AND deleted_at IS NULL
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = jobsFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['total'] =
            (int) $row['total'];

        $stats['active'] =
            (int) $row['active_count'];

        $stats['completed'] =
            (int) $row['completed_count'];

        $stats['invoicing'] =
            (int) $row['invoicing_count'];
    }
}

/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
*/

$where = array(
    'j.tenant_id = ?',
    'j.deleted_at IS NULL'
);

$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        j.job_no LIKE ?
        OR j.title LIKE ?
        OR j.description LIKE ?
        OR c.display_name LIKE ?
        OR p.name LIKE ?
        OR p.address_line1 LIKE ?
    )";

    $searchLike = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($clientFilter > 0) {
    $where[] = 'j.client_id = ?';
    $params[] = $clientFilter;
    $types .= 'i';
}

if ($assignedUserFilter > 0) {
    $where[] = 'j.assigned_user_id = ?';
    $params[] = $assignedUserFilter;
    $types .= 'i';
}

if ($statusFilter !== '') {
    $where[] = 'j.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($typeFilter !== '') {
    $where[] = 'j.job_type = ?';
    $params[] = $typeFilter;
    $types .= 's';
}

if ($dateFilter === 'today') {
    $where[] = 'j.start_date = CURDATE()';
} elseif ($dateFilter === 'this_week') {
    $where[] = '
        YEARWEEK(j.start_date, 1) =
        YEARWEEK(CURDATE(), 1)
    ';
} elseif ($dateFilter === 'this_month') {
    $where[] = '
        YEAR(j.start_date) = YEAR(CURDATE())
        AND MONTH(j.start_date) = MONTH(CURDATE())
    ';
} elseif ($dateFilter === 'overdue') {
    $where[] = "
        j.end_date IS NOT NULL
        AND j.end_date < CURDATE()
        AND j.status NOT IN (
            'completed',
            'closed',
            'cancelled',
            'archived',
            'invoiced'
        )
    ";
}

$whereSql = implode(' AND ', $where);

$orderSql = 'j.created_at DESC';

if ($sort === 'oldest') {
    $orderSql = 'j.created_at ASC';
} elseif ($sort === 'start_asc') {
    $orderSql = 'j.start_date ASC, j.created_at DESC';
} elseif ($sort === 'start_desc') {
    $orderSql = 'j.start_date DESC, j.created_at DESC';
} elseif ($sort === 'title_asc') {
    $orderSql = 'j.title ASC';
} elseif ($sort === 'title_desc') {
    $orderSql = 'j.title DESC';
} elseif ($sort === 'total_desc') {
    $orderSql = 'j.total DESC, j.created_at DESC';
}

/*
|--------------------------------------------------------------------------
| Count filtered
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM jobs j
    INNER JOIN clients c
        ON c.id = j.client_id
       AND c.tenant_id = j.tenant_id
    LEFT JOIN properties p
        ON p.id = j.property_id
       AND p.tenant_id = j.tenant_id
    WHERE {$whereSql}
");

if ($stmt) {
    jobsBindParams(
        $stmt,
        $types,
        $params
    );

    $stmt->execute();
    $row = jobsFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $totalFiltered = (int) $row['total'];
    }
}

$totalPages = max(
    1,
    (int) ceil(
        $totalFiltered / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Load jobs
|--------------------------------------------------------------------------
*/

$jobs = array();

$sql = "
    SELECT
        j.id,
        j.job_no,
        j.client_id,
        j.property_id,
        j.request_id,
        j.quote_id,
        j.title,
        j.job_type,
        j.status,
        j.assigned_user_id,
        j.start_date,
        j.end_date,
        j.invoicing_preference,
        j.subtotal,
        j.tax_total,
        j.total,
        j.created_at,
        c.display_name AS client_name,
        p.name AS property_name,
        p.address_line1 AS property_address,
        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN COALESCE(u.last_name, '') <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS assigned_user_name,
        (
            SELECT COUNT(*)
            FROM visits v
            WHERE v.tenant_id = j.tenant_id
              AND v.job_id = j.id
        ) AS visit_count,
        (
            SELECT COUNT(*)
            FROM job_line_items li
            WHERE li.tenant_id = j.tenant_id
              AND li.job_id = j.id
        ) AS item_count,
        (
            SELECT COUNT(*)
            FROM invoices i
            WHERE i.tenant_id = j.tenant_id
              AND i.job_id = j.id
              AND i.archived_at IS NULL
        ) AS invoice_count
    FROM jobs j
    INNER JOIN clients c
        ON c.id = j.client_id
       AND c.tenant_id = j.tenant_id
    LEFT JOIN properties p
        ON p.id = j.property_id
       AND p.tenant_id = j.tenant_id
    LEFT JOIN users u
        ON u.id = j.assigned_user_id
       AND u.tenant_id = j.tenant_id
    WHERE {$whereSql}
    ORDER BY {$orderSql}
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    jobsBindParams(
        $stmt,
        $listTypes,
        $listParams
    );

    $stmt->execute();
    $jobs = jobsFetchAll($stmt);
    $stmt->close();
}

$currencyCode = !empty($_SESSION['currency_code'])
    ? (string) $_SESSION['currency_code']
    : 'INR';

$csrfToken = jobsCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.jobs-page {
    --jb-primary: #6d28d9;
    --jb-primary-soft: #f4f0ff;
    --jb-text: #111827;
    --jb-muted: #6b7280;
    --jb-border: #e5e7eb;
}

.jb-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.jb-header h1 {
    margin: 0;
    color: var(--jb-text);
    font-size: 21px;
    font-weight: 700;
}

.jb-header p {
    margin: 5px 0 0;
    color: var(--jb-muted);
    font-size: 11px;
}

.jb-add-btn {
    min-height: 35px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 9px;
    background: var(--jb-primary);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.jb-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
    line-height: 1.6;
}

.jb-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.jb-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.jb-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 10px;
}

.jb-stat {
    padding: 13px;
    border: 1px solid var(--jb-border);
    border-radius: 11px;
    background: #fff;
}

.jb-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.jb-stat-value {
    margin-top: 4px;
    color: var(--jb-text);
    font-size: 19px;
    font-weight: 700;
}

.jb-panel {
    overflow: hidden;
    border: 1px solid var(--jb-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.jb-filter-bar {
    padding: 12px;
    display: grid;
    grid-template-columns:
        minmax(220px,1.3fr)
        minmax(145px,.7fr)
        minmax(145px,.7fr)
        minmax(130px,.6fr)
        minmax(120px,.55fr)
        minmax(125px,.55fr)
        minmax(130px,.6fr)
        auto;
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.jb-input,
.jb-select {
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

.jb-input:focus,
.jb-select:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.jb-filter-btn {
    min-height: 36px;
    padding: 8px 13px;
    border: 0;
    border-radius: 8px;
    background: var(--jb-primary);
    color: #fff;
    font-family: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.jb-reset-btn {
    min-height: 36px;
    padding: 8px 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--jb-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.jb-table-wrap {
    overflow-x: auto;
}

.jb-table {
    width: 100%;
    border-collapse: collapse;
}

.jb-table th,
.jb-table td {
    padding: 11px 13px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    white-space: nowrap;
}

.jb-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.jb-table td {
    color: #374151;
    font-size: 9px;
    vertical-align: middle;
}

.jb-job {
    display: flex;
    align-items: center;
    gap: 9px;
}

.jb-icon {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: var(--jb-primary-soft);
    color: var(--jb-primary);
    font-size: 13px;
}

.jb-main {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
}

.jb-sub {
    margin-top: 2px;
    display: block;
    color: #9ca3af;
    font-size: 8px;
}

.jb-badge {
    padding: 4px 7px;
    display: inline-flex;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
    text-transform: capitalize;
}

.jb-badge.active,
.jb-badge.completed,
.jb-badge.invoiced {
    background: #ecfdf5;
    color: #047857;
}

.jb-badge.draft,
.jb-badge.unscheduled,
.jb-badge.needs_review {
    background: #fffbeb;
    color: #b45309;
}

.jb-badge.scheduled,
.jb-badge.upcoming,
.jb-badge.today,
.jb-badge.in_progress {
    background: #eff6ff;
    color: #1d4ed8;
}

.jb-badge.late,
.jb-badge.cancelled,
.jb-badge.action_required {
    background: #fef2f2;
    color: #b91c1c;
}

.jb-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 5px;
}

.jb-action {
    width: 29px;
    height: 29px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--jb-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    text-decoration: none;
    cursor: pointer;
}

.jb-action:hover {
    border-color: #c4b5fd;
    color: var(--jb-primary);
    background: #faf8ff;
}

.jb-action.danger:hover {
    border-color: #fecaca;
    background: #fef2f2;
    color: #dc2626;
}

.jb-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #f1f5f9;
}

.jb-result-count {
    color: #6b7280;
    font-size: 9px;
}

.jb-pagination {
    display: flex;
    align-items: center;
    gap: 5px;
}

.jb-page-link {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--jb-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.jb-page-link.active {
    border-color: var(--jb-primary);
    background: var(--jb-primary);
    color: #fff;
}

.jb-empty {
    padding: 40px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1250px) {
    .jb-filter-bar {
        grid-template-columns: repeat(3,minmax(0,1fr));
    }
}

@media (max-width: 980px) {
    .jb-stats {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }

    .jb-filter-bar {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 680px) {
    .jb-header {
        flex-direction: column;
    }

    .jb-stats,
    .jb-filter-bar {
        grid-template-columns: 1fr;
    }

    .jb-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="jobs-page">
    <div class="jb-header">
        <div>
            <h1>Jobs</h1>
            <p>
                Manage service jobs, assignments, schedules, and billing status.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a
                href="job-add.php"
                class="jb-add-btn"
            >
                <i class="bi bi-plus-lg"></i>
                Add Job
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="jb-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="jb-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="jb-stats">
        <article class="jb-stat">
            <div class="jb-stat-label">Total Jobs</div>
            <div class="jb-stat-value">
                <?= e($stats['total']); ?>
            </div>
        </article>

        <article class="jb-stat">
            <div class="jb-stat-label">Active Jobs</div>
            <div class="jb-stat-value">
                <?= e($stats['active']); ?>
            </div>
        </article>

        <article class="jb-stat">
            <div class="jb-stat-label">Completed</div>
            <div class="jb-stat-value">
                <?= e($stats['completed']); ?>
            </div>
        </article>

        <article class="jb-stat">
            <div class="jb-stat-label">Requires Invoicing</div>
            <div class="jb-stat-value">
                <?= e($stats['invoicing']); ?>
            </div>
        </article>
    </section>

    <section class="jb-panel">
        <form
            method="get"
            action=""
            class="jb-filter-bar"
        >
            <input
                type="search"
                name="search"
                class="jb-input"
                value="<?= e($search); ?>"
                placeholder="Search job no, title, client or property"
            >

            <select
                name="client_id"
                class="jb-select"
            >
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
                class="jb-select"
            >
                <option value="">All Workers</option>

                <?php foreach ($userOptions as $user): ?>
                    <?php
                    $userName = trim(
                        (string) $user['first_name'] .
                        ' ' .
                        (string) $user['last_name']
                    );
                    ?>
                    <option
                        value="<?= (int) $user['id']; ?>"
                        <?= $assignedUserFilter === (int) $user['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($userName); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="status"
                class="jb-select"
            >
                <option value="">All Statuses</option>

                <?php
                $statusOptions = array(
                    'draft' => 'Draft',
                    'active' => 'Active',
                    'scheduled' => 'Scheduled',
                    'upcoming' => 'Upcoming',
                    'today' => 'Today',
                    'in_progress' => 'In Progress',
                    'completed' => 'Completed',
                    'late' => 'Late',
                    'unscheduled' => 'Unscheduled',
                    'action_required' => 'Action Required',
                    'needs_review' => 'Needs Review',
                    'requires_invoicing' => 'Requires Invoicing',
                    'ready_to_invoice' => 'Ready to Invoice',
                    'invoiced' => 'Invoiced',
                    'closed' => 'Closed',
                    'cancelled' => 'Cancelled',
                    'archived' => 'Archived'
                );

                foreach ($statusOptions as $value => $label):
                ?>
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

            <select
                name="job_type"
                class="jb-select"
            >
                <option value="">All Job Types</option>
                <option
                    value="one_off"
                    <?= $typeFilter === 'one_off'
                        ? 'selected'
                        : ''; ?>
                >
                    One-off
                </option>
                <option
                    value="recurring"
                    <?= $typeFilter === 'recurring'
                        ? 'selected'
                        : ''; ?>
                >
                    Recurring
                </option>
            </select>

            <select
                name="date_filter"
                class="jb-select"
            >
                <option value="">All Dates</option>
                <option
                    value="today"
                    <?= $dateFilter === 'today'
                        ? 'selected'
                        : ''; ?>
                >
                    Today
                </option>
                <option
                    value="this_week"
                    <?= $dateFilter === 'this_week'
                        ? 'selected'
                        : ''; ?>
                >
                    This Week
                </option>
                <option
                    value="this_month"
                    <?= $dateFilter === 'this_month'
                        ? 'selected'
                        : ''; ?>
                >
                    This Month
                </option>
                <option
                    value="overdue"
                    <?= $dateFilter === 'overdue'
                        ? 'selected'
                        : ''; ?>
                >
                    Overdue
                </option>
            </select>

            <select
                name="sort"
                class="jb-select"
            >
                <option value="latest" <?= $sort === 'latest' ? 'selected' : ''; ?>>
                    Latest First
                </option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : ''; ?>>
                    Oldest First
                </option>
                <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : ''; ?>>
                    Start Date Asc
                </option>
                <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : ''; ?>>
                    Start Date Desc
                </option>
                <option value="title_asc" <?= $sort === 'title_asc' ? 'selected' : ''; ?>>
                    Title A-Z
                </option>
                <option value="title_desc" <?= $sort === 'title_desc' ? 'selected' : ''; ?>>
                    Title Z-A
                </option>
                <option value="total_desc" <?= $sort === 'total_desc' ? 'selected' : ''; ?>>
                    Highest Total
                </option>
            </select>

            <div style="display:flex;gap:6px;">
                <button
                    type="submit"
                    class="jb-filter-btn"
                >
                    Apply
                </button>

                <a
                    href="jobs.php"
                    class="jb-reset-btn"
                >
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($jobs)): ?>
            <div class="jb-table-wrap">
                <table class="jb-table">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Client</th>
                            <th>Property</th>
                            <th>Status</th>
                            <th>Type</th>
                            <th>Assigned To</th>
                            <th>Dates</th>
                            <th>Visits</th>
                            <th>Items</th>
                            <th>Invoices</th>
                            <th>Total</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <?php
                        $propertyLabel =
                            trim((string) $job['property_name']) !== ''
                                ? (string) $job['property_name']
                                : (
                                    trim((string) $job['property_address']) !== ''
                                        ? (string) $job['property_address']
                                        : '—'
                                );
                        ?>
                        <tr>
                            <td>
                                <div class="jb-job">
                                    <span class="jb-icon">
                                        <i class="bi bi-briefcase"></i>
                                    </span>

                                    <span>
                                        <span class="jb-main">
                                            <?= e($job['title']); ?>
                                        </span>

                                        <span class="jb-sub">
                                            <?= e($job['job_no']); ?>
                                        </span>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <a
                                    href="client-view.php?id=<?= (int) $job['client_id']; ?>"
                                    class="jb-main"
                                    style="text-decoration:none;"
                                >
                                    <?= e($job['client_name']); ?>
                                </a>
                            </td>

                            <td>
                                <?= e($propertyLabel); ?>
                            </td>

                            <td>
                                <span class="jb-badge <?= e(
                                    jobsStatusClass(
                                        $job['status']
                                    )
                                ); ?>">
                                    <?= e(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $job['status']
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="jb-badge">
                                    <?= e(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $job['job_type']
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <?= e(
                                    trim(
                                        (string) $job['assigned_user_name']
                                    ) !== ''
                                        ? $job['assigned_user_name']
                                        : 'Not Assigned'
                                ); ?>
                            </td>

                            <td>
                                <span class="jb-main">
                                    <?= e(jobsDate($job['start_date'])); ?>
                                </span>

                                <span class="jb-sub">
                                    to <?= e(jobsDate($job['end_date'])); ?>
                                </span>
                            </td>

                            <td>
                                <?= e((int) $job['visit_count']); ?>
                            </td>

                            <td>
                                <?= e((int) $job['item_count']); ?>
                            </td>

                            <td>
                                <?= e((int) $job['invoice_count']); ?>
                            </td>

                            <td>
                                <?= e(
                                    jobsMoney(
                                        $job['total'],
                                        $currencyCode
                                    )
                                ); ?>
                            </td>

                            <td>
                                <div class="jb-actions">
                                    <a
                                        href="job-view.php?id=<?= (int) $job['id']; ?>"
                                        class="jb-action"
                                        title="View"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($canManage): ?>
                                        <a
                                            href="job-edit.php?id=<?= (int) $job['id']; ?>"
                                            class="jb-action"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <form
                                            method="post"
                                            action=""
                                            class="archive-job-form"
                                            style="display:inline;"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrfToken); ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="archive"
                                            >

                                            <input
                                                type="hidden"
                                                name="job_id"
                                                value="<?= (int) $job['id']; ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="jb-action danger"
                                                title="Archive"
                                                aria-label="Archive job"
                                            >
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="jb-footer">
                <div class="jb-result-count">
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
                            $offset + count($jobs)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    jobs
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="jb-pagination">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    jobsBuildQueryString(
                                        array(
                                            'page' => $page - 1
                                        )
                                    )
                                ); ?>"
                                class="jb-page-link"
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
                                    jobsBuildQueryString(
                                        array(
                                            'page' => $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="jb-page-link <?= $pageNumber === $page ? 'active' : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    jobsBuildQueryString(
                                        array(
                                            'page' => $page + 1
                                        )
                                    )
                                ); ?>"
                                class="jb-page-link"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="jb-empty">
                No jobs found for the selected filters.
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document
        .querySelectorAll('.archive-job-form')
        .forEach(function (form) {
            form.addEventListener(
                'submit',
                function (event) {
                    var confirmed = window.confirm(
                        'Archive this job? It will be removed from the active job list.'
                    );

                    if (!confirmed) {
                        event.preventDefault();
                    }
                }
            );
        });
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
