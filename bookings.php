<?php
/**
 * FieldPlx - Bookings List
 * Upload as: /public_html/bookings.php
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: login.php?redirect=' . rawurlencode('bookings.php'));
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'bookings.view',
        'You do not have permission to view bookings.'
    );
}

$pageTitle = 'Bookings - FieldPlx';
$activePage = 'bookings';
$searchPlaceholder = 'Search bookings...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$errors = array();

$canManage = function_exists('hasPermission')
    ? hasPermission('bookings.manage')
    : true;

if (!function_exists('bookingsFetchAssoc')) {
    function bookingsFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('bookingsFetchAll')) {
    function bookingsFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('bookingsBindParams')) {
    function bookingsBindParams(
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

if (!function_exists('bookingsDateTime')) {
    function bookingsDateTime($value)
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

if (!function_exists('bookingsDate')) {
    function bookingsDate($value)
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

if (!function_exists('bookingsMoney')) {
    function bookingsMoney($amount)
    {
        return number_format(
            (float) $amount,
            2,
            '.',
            ','
        );
    }
}

if (!function_exists('bookingsLabel')) {
    function bookingsLabel($value)
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

if (!function_exists('bookingsClass')) {
    function bookingsClass($value)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $value))
        );
    }
}

if (!function_exists('bookingsQueryString')) {
    function bookingsQueryString(array $overrides = array())
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

$serviceFilter = isset($_GET['bookable_service_id'])
    ? (int) $_GET['bookable_service_id']
    : 0;

$assignedFilter = isset($_GET['assigned_user_id'])
    ? (int) $_GET['assigned_user_id']
    : 0;

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
    'submitted',
    'confirmed',
    'declined',
    'cancelled',
    'converted'
);

$allowedSorts = array(
    'schedule_asc',
    'schedule_desc',
    'latest',
    'oldest',
    'customer_asc',
    'service_asc'
);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
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
| Filter options
|--------------------------------------------------------------------------
*/

$serviceOptions = array();
$userOptions = array();

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        duration_minutes,
        estimated_price,
        is_active
    FROM bookable_services
    WHERE tenant_id = ?
    ORDER BY is_active DESC, name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $serviceOptions = bookingsFetchAll($stmt);
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
    $userOptions = bookingsFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Summary statistics
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'submitted' => 0,
    'confirmed' => 0,
    'today' => 0,
    'converted' => 0
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(status = 'submitted') AS submitted_count,
        SUM(status = 'confirmed') AS confirmed_count,
        SUM(DATE(scheduled_start) = CURDATE()) AS today_count,
        SUM(status = 'converted') AS converted_count
    FROM bookings
    WHERE tenant_id = ?
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = bookingsFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['total'] = (int) $row['total_count'];
        $stats['submitted'] = (int) $row['submitted_count'];
        $stats['confirmed'] = (int) $row['confirmed_count'];
        $stats['today'] = (int) $row['today_count'];
        $stats['converted'] = (int) $row['converted_count'];
    }
}

/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
*/

$where = array('b.tenant_id = ?');
$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        b.booking_no LIKE ?
        OR b.customer_name LIKE ?
        OR b.customer_email LIKE ?
        OR b.customer_phone LIKE ?
        OR c.display_name LIKE ?
        OR p.name LIKE ?
        OR p.address_line1 LIKE ?
        OR bs.name LIKE ?
        OR r.request_no LIKE ?
    )";

    $searchLike = '%' . $search . '%';

    for ($i = 0; $i < 9; $i++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($statusFilter !== '') {
    $where[] = 'b.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($serviceFilter > 0) {
    $where[] = 'b.bookable_service_id = ?';
    $params[] = $serviceFilter;
    $types .= 'i';
}

if ($assignedFilter > 0) {
    $where[] = 'b.assigned_user_id = ?';
    $params[] = $assignedFilter;
    $types .= 'i';
}

if ($dateFrom !== '') {
    $where[] = 'DATE(b.scheduled_start) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = 'DATE(b.scheduled_start) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$orderSql = "
    CASE
        WHEN b.scheduled_start IS NULL THEN 1
        ELSE 0
    END ASC,
    b.scheduled_start ASC,
    b.id DESC
";

if ($sort === 'schedule_desc') {
    $orderSql = "
        CASE
            WHEN b.scheduled_start IS NULL THEN 1
            ELSE 0
        END ASC,
        b.scheduled_start DESC,
        b.id DESC
    ";
} elseif ($sort === 'latest') {
    $orderSql = 'b.created_at DESC, b.id DESC';
} elseif ($sort === 'oldest') {
    $orderSql = 'b.created_at ASC, b.id ASC';
} elseif ($sort === 'customer_asc') {
    $orderSql = 'b.customer_name ASC, b.created_at DESC';
} elseif ($sort === 'service_asc') {
    $orderSql = 'bs.name ASC, b.scheduled_start ASC';
}

/*
|--------------------------------------------------------------------------
| Count rows
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM bookings b

    LEFT JOIN clients c
        ON c.id = b.client_id
       AND c.tenant_id = b.tenant_id

    LEFT JOIN properties p
        ON p.id = b.property_id
       AND p.tenant_id = b.tenant_id

    LEFT JOIN bookable_services bs
        ON bs.id = b.bookable_service_id
       AND bs.tenant_id = b.tenant_id

    LEFT JOIN requests r
        ON r.id = b.request_id
       AND r.tenant_id = b.tenant_id

    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare booking count query: ' .
        $conn->error;
} else {
    if (!bookingsBindParams($stmt, $types, $params)) {
        $errors[] =
            'Unable to bind booking count filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to count bookings: ' .
            $stmt->error;
    } else {
        $row = bookingsFetchAssoc($stmt);

        if ($row) {
            $totalFiltered = (int) $row['total'];
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
| Load bookings
|--------------------------------------------------------------------------
*/

$bookings = array();

$listSql = "
    SELECT
        b.id,
        b.booking_no,
        b.client_id,
        b.property_id,
        b.bookable_service_id,
        b.request_id,
        b.assigned_user_id,
        b.customer_name,
        b.customer_email,
        b.customer_phone,
        b.scheduled_start,
        b.scheduled_end,
        b.status,
        b.payload,
        b.created_at,
        b.updated_at,

        c.display_name AS client_name,

        p.name AS property_name,
        p.address_line1 AS property_address,
        p.city AS property_city,

        bs.name AS service_name,
        bs.estimated_price,
        bs.duration_minutes,

        r.request_no,
        r.title AS request_title,

        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS assigned_user_name

    FROM bookings b

    LEFT JOIN clients c
        ON c.id = b.client_id
       AND c.tenant_id = b.tenant_id

    LEFT JOIN properties p
        ON p.id = b.property_id
       AND p.tenant_id = b.tenant_id

    LEFT JOIN bookable_services bs
        ON bs.id = b.bookable_service_id
       AND bs.tenant_id = b.tenant_id

    LEFT JOIN requests r
        ON r.id = b.request_id
       AND r.tenant_id = b.tenant_id

    LEFT JOIN users u
        ON u.id = b.assigned_user_id
       AND u.tenant_id = b.tenant_id
       AND u.deleted_at IS NULL

    WHERE {$whereSql}

    ORDER BY {$orderSql}

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($listSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare booking list query: ' .
        $conn->error;
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    if (
        !bookingsBindParams(
            $stmt,
            $listTypes,
            $listParams
        )
    ) {
        $errors[] =
            'Unable to bind booking filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to load bookings: ' .
            $stmt->error;
    } else {
        $bookings = bookingsFetchAll($stmt);
    }

    $stmt->close();
}

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.bookings-page {
    --bk-primary: #6d28d9;
    --bk-text: #111827;
    --bk-muted: #6b7280;
    --bk-border: #e5e7eb;
}

.bk-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.bk-header h1 {
    margin: 0;
    color: var(--bk-text);
    font-size: 21px;
    font-weight: 700;
}

.bk-header p {
    margin: 5px 0 0;
    color: var(--bk-muted);
    font-size: 11px;
}

.bk-add {
    min-height: 35px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 9px;
    background: var(--bk-primary);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.bk-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
}

.bk-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.bk-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.bk-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(5,minmax(0,1fr));
    gap: 10px;
}

.bk-stat {
    padding: 13px;
    border: 1px solid var(--bk-border);
    border-radius: 11px;
    background: #fff;
}

.bk-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.bk-stat-value {
    margin-top: 4px;
    color: var(--bk-text);
    font-size: 19px;
    font-weight: 700;
}

.bk-panel {
    overflow: hidden;
    border: 1px solid var(--bk-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.bk-filters {
    padding: 12px;
    display: grid;
    grid-template-columns:
        minmax(220px,1.3fr)
        minmax(145px,.65fr)
        minmax(165px,.75fr)
        minmax(165px,.75fr)
        minmax(120px,.55fr)
        minmax(120px,.55fr)
        minmax(155px,.7fr)
        auto;
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.bk-input,
.bk-select {
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

.bk-filter-btn,
.bk-reset {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 700;
}

.bk-filter-btn {
    border: 0;
    background: var(--bk-primary);
    color: #fff;
    cursor: pointer;
}

.bk-reset {
    border: 1px solid var(--bk-border);
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.bk-table-wrap {
    overflow-x: auto;
}

.bk-table {
    width: 100%;
    border-collapse: collapse;
}

.bk-table th,
.bk-table td {
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    white-space: nowrap;
    vertical-align: middle;
}

.bk-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.bk-table td {
    color: #374151;
    font-size: 9px;
}

.bk-main {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.bk-sub {
    margin-top: 2px;
    display: block;
    max-width: 240px;
    overflow: hidden;
    color: #9ca3af;
    font-size: 8px;
    text-overflow: ellipsis;
}

.bk-badge {
    padding: 4px 7px;
    display: inline-flex;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
}

.bk-badge.submitted {
    background: #fffbeb;
    color: #b45309;
}

.bk-badge.confirmed {
    background: #eff6ff;
    color: #1d4ed8;
}

.bk-badge.converted {
    background: #ecfdf5;
    color: #047857;
}

.bk-badge.declined,
.bk-badge.cancelled {
    background: #fef2f2;
    color: #b91c1c;
}

.bk-actions {
    display: flex;
    justify-content: flex-end;
    gap: 5px;
}

.bk-action {
    width: 29px;
    height: 29px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--bk-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.bk-action:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--bk-primary);
}

.bk-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #f1f5f9;
}

.bk-result {
    color: #6b7280;
    font-size: 9px;
}

.bk-pages {
    display: flex;
    gap: 5px;
}

.bk-page {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--bk-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.bk-page.active {
    border-color: var(--bk-primary);
    background: var(--bk-primary);
    color: #fff;
}

.bk-empty {
    padding: 40px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1280px) {
    .bk-filters {
        grid-template-columns: repeat(4,minmax(0,1fr));
    }
}

@media (max-width: 900px) {
    .bk-stats {
        grid-template-columns: repeat(3,minmax(0,1fr));
    }

    .bk-filters {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 650px) {
    .bk-header {
        flex-direction: column;
    }

    .bk-stats,
    .bk-filters {
        grid-template-columns: 1fr;
    }

    .bk-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="bookings-page">
    <div class="bk-header">
        <div>
            <h1>Bookings</h1>
            <p>
                Manage online and manually scheduled service bookings.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a href="booking-add.php" class="bk-add">
                <i class="bi bi-plus-lg"></i>
                Add Booking
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="bk-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="bk-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="bk-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="bk-stats">
        <article class="bk-stat">
            <div class="bk-stat-label">Total Bookings</div>
            <div class="bk-stat-value"><?= e($stats['total']); ?></div>
        </article>

        <article class="bk-stat">
            <div class="bk-stat-label">Submitted</div>
            <div class="bk-stat-value"><?= e($stats['submitted']); ?></div>
        </article>

        <article class="bk-stat">
            <div class="bk-stat-label">Confirmed</div>
            <div class="bk-stat-value"><?= e($stats['confirmed']); ?></div>
        </article>

        <article class="bk-stat">
            <div class="bk-stat-label">Scheduled Today</div>
            <div class="bk-stat-value"><?= e($stats['today']); ?></div>
        </article>

        <article class="bk-stat">
            <div class="bk-stat-label">Converted</div>
            <div class="bk-stat-value"><?= e($stats['converted']); ?></div>
        </article>
    </section>

    <section class="bk-panel">
        <form method="get" action="" class="bk-filters">
            <input
                type="search"
                name="search"
                class="bk-input"
                value="<?= e($search); ?>"
                placeholder="Search booking, customer, service, property or request"
            >

            <select name="status" class="bk-select">
                <option value="">All Statuses</option>

                <?php foreach (
                    array(
                        'submitted' => 'Submitted',
                        'confirmed' => 'Confirmed',
                        'declined' => 'Declined',
                        'cancelled' => 'Cancelled',
                        'converted' => 'Converted'
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

            <select
                name="bookable_service_id"
                class="bk-select"
            >
                <option value="">All Services</option>

                <?php foreach ($serviceOptions as $service): ?>
                    <option
                        value="<?= (int) $service['id']; ?>"
                        <?= $serviceFilter === (int) $service['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($service['name']); ?>
                        <?php if (empty($service['is_active'])): ?>
                            · Inactive
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="assigned_user_id"
                class="bk-select"
            >
                <option value="">All Assigned Users</option>

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
                        <?= $assignedFilter === (int) $user['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($userName); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input
                type="date"
                name="date_from"
                class="bk-input"
                value="<?= e($dateFrom); ?>"
                title="Scheduled from"
            >

            <input
                type="date"
                name="date_to"
                class="bk-input"
                value="<?= e($dateTo); ?>"
                title="Scheduled to"
            >

            <select name="sort" class="bk-select">
                <option value="schedule_asc" <?= $sort === 'schedule_asc' ? 'selected' : ''; ?>>
                    Schedule Ascending
                </option>
                <option value="schedule_desc" <?= $sort === 'schedule_desc' ? 'selected' : ''; ?>>
                    Schedule Descending
                </option>
                <option value="latest" <?= $sort === 'latest' ? 'selected' : ''; ?>>
                    Latest Created
                </option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : ''; ?>>
                    Oldest Created
                </option>
                <option value="customer_asc" <?= $sort === 'customer_asc' ? 'selected' : ''; ?>>
                    Customer A-Z
                </option>
                <option value="service_asc" <?= $sort === 'service_asc' ? 'selected' : ''; ?>>
                    Service A-Z
                </option>
            </select>

            <div style="display:flex;gap:6px;">
                <button type="submit" class="bk-filter-btn">
                    Apply
                </button>

                <a href="bookings.php" class="bk-reset">
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($bookings)): ?>
            <div class="bk-table-wrap">
                <table class="bk-table">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Customer</th>
                            <th>Service</th>
                            <th>Property</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Request</th>
                            <th>Est. Price</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <?php
                        $propertyTitle =
                            trim((string) $booking['property_name']) !== ''
                                ? (string) $booking['property_name']
                                : (
                                    trim((string) $booking['property_address']) !== ''
                                        ? (string) $booking['property_address']
                                        : '—'
                                );

                        $customerContact = trim(
                            implode(
                                ' · ',
                                array_filter(
                                    array(
                                        $booking['customer_phone'],
                                        $booking['customer_email']
                                    ),
                                    function ($value) {
                                        return trim((string) $value) !== '';
                                    }
                                )
                            )
                        );
                        ?>
                        <tr>
                            <td>
                                <a
                                    href="booking-view.php?id=<?= (int) $booking['id']; ?>"
                                    class="bk-main"
                                >
                                    <?= e($booking['booking_no']); ?>
                                </a>

                                <?php if (
                                    trim((string) $booking['client_name']) !== ''
                                ): ?>
                                    <span class="bk-sub">
                                        Client: <?= e($booking['client_name']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="bk-main">
                                    <?= e($booking['customer_name']); ?>
                                </span>

                                <?php if ($customerContact !== ''): ?>
                                    <span class="bk-sub">
                                        <?= e($customerContact); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="bk-main">
                                    <?= e(
                                        trim((string) $booking['service_name']) !== ''
                                            ? $booking['service_name']
                                            : 'No service selected'
                                    ); ?>
                                </span>

                                <?php if (!empty($booking['duration_minutes'])): ?>
                                    <span class="bk-sub">
                                        <?= e((int) $booking['duration_minutes']); ?>
                                        minutes
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($booking['property_id'])): ?>
                                    <a
                                        href="property-view.php?id=<?= (int) $booking['property_id']; ?>"
                                        class="bk-main"
                                    >
                                        <?= e($propertyTitle); ?>
                                    </a>

                                    <?php if (
                                        trim((string) $booking['property_city']) !== ''
                                    ): ?>
                                        <span class="bk-sub">
                                            <?= e($booking['property_city']); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="bk-main">
                                    <?= e(
                                        bookingsDateTime(
                                            $booking['scheduled_start']
                                        )
                                    ); ?>
                                </span>

                                <?php if (!empty($booking['scheduled_end'])): ?>
                                    <span class="bk-sub">
                                        Ends:
                                        <?= e(
                                            bookingsDateTime(
                                                $booking['scheduled_end']
                                            )
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="bk-badge <?= e(
                                    bookingsClass(
                                        $booking['status']
                                    )
                                ); ?>">
                                    <?= e(
                                        bookingsLabel(
                                            $booking['status']
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <?= e(
                                    trim(
                                        (string) $booking['assigned_user_name']
                                    ) !== ''
                                        ? $booking['assigned_user_name']
                                        : 'Unassigned'
                                ); ?>
                            </td>

                            <td>
                                <?php if (!empty($booking['request_id'])): ?>
                                    <a
                                        href="request-view.php?id=<?= (int) $booking['request_id']; ?>"
                                        class="bk-main"
                                    >
                                        <?= e($booking['request_no']); ?>
                                    </a>

                                    <span class="bk-sub">
                                        <?= e($booking['request_title']); ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($booking['estimated_price'] !== null): ?>
                                    <?= e(
                                        bookingsMoney(
                                            $booking['estimated_price']
                                        )
                                    ); ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= e(
                                    bookingsDate(
                                        $booking['created_at']
                                    )
                                ); ?>
                            </td>

                            <td>
                                <div class="bk-actions">
                                    <a
                                        href="booking-view.php?id=<?= (int) $booking['id']; ?>"
                                        class="bk-action"
                                        title="View"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($canManage): ?>
                                        <a
                                            href="booking-edit.php?id=<?= (int) $booking['id']; ?>"
                                            class="bk-action"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($booking['request_id'])): ?>
                                        <a
                                            href="request-view.php?id=<?= (int) $booking['request_id']; ?>"
                                            class="bk-action"
                                            title="View Request"
                                        >
                                            <i class="bi bi-inbox"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="bk-footer">
                <div class="bk-result">
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
                            $offset + count($bookings)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    bookings
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="bk-pages">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    bookingsQueryString(
                                        array('page' => $page - 1)
                                    )
                                ); ?>"
                                class="bk-page"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);

                        for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(
                                    bookingsQueryString(
                                        array('page' => $pageNumber)
                                    )
                                ); ?>"
                                class="bk-page <?= $pageNumber === $page ? 'active' : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    bookingsQueryString(
                                        array('page' => $page + 1)
                                    )
                                ); ?>"
                                class="bk-page"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="bk-empty">
                <?php if (
                    $search !== '' ||
                    $statusFilter !== '' ||
                    $serviceFilter > 0 ||
                    $assignedFilter > 0 ||
                    $dateFrom !== '' ||
                    $dateTo !== ''
                ): ?>
                    No bookings found for the selected filters.
                <?php else: ?>
                    No bookings are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
