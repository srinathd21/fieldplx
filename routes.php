<?php
/**
 * FieldPlx - Routes List
 *
 * Upload as:
 * /public_html/routes.php
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
        rawurlencode('routes.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'routes.view',
        'You do not have permission to view routes.'
    );
}

$pageTitle = 'Routes - FieldPlx';
$activePage = 'routes';
$searchPlaceholder = 'Search routes...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

$canManage = function_exists('hasPermission')
    ? hasPermission('routes.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('routesFetchAssoc')) {
    function routesFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('routesFetchAll')) {
    function routesFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('routesBindParams')) {
    function routesBindParams(
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

if (!function_exists('routesDate')) {
    function routesDate($value)
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

if (!function_exists('routesDateTime')) {
    function routesDateTime($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('routesTime')) {
    function routesTime($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('h:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('routesLabel')) {
    function routesLabel($value)
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

if (!function_exists('routesStatusClass')) {
    function routesStatusClass($value)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $value))
        );
    }
}

if (!function_exists('routesDuration')) {
    function routesDuration($minutes)
    {
        if ($minutes === null || $minutes === '') {
            return '—';
        }

        $minutes = max(0, (int) $minutes);
        $hours = (int) floor($minutes / 60);
        $remaining = $minutes % 60;

        if ($hours > 0 && $remaining > 0) {
            return $hours . ' hr ' . $remaining . ' min';
        }

        if ($hours > 0) {
            return $hours .
                ($hours === 1 ? ' hour' : ' hours');
        }

        return $minutes . ' min';
    }
}

if (!function_exists('routesCsrfToken')) {
    function routesCsrfToken()
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

if (!function_exists('routesVerifyCsrf')) {
    function routesVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('routesQueryString')) {
    function routesQueryString(array $overrides = array())
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

if (!function_exists('routesLogActivity')) {
    function routesLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $routeId,
        $routeName,
        $oldStatus,
        $newStatus
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
                'route_status_updated',
                'route_plan',
                ?,
                NULL,
                ?,
                ?,
                0,
                NOW()
            )
        ");

        if (!$stmt) {
            return;
        }

        $title =
            'Route status changed: ' .
            $routeName .
            ' · ' .
            routesLabel($oldStatus) .
            ' to ' .
            routesLabel($newStatus);

        $details = json_encode(
            array(
                'route_plan_id' =>
                    (int) $routeId,
                'route_name' =>
                    (string) $routeName,
                'old_status' =>
                    (string) $oldStatus,
                'new_status' =>
                    (string) $newStatus
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiss',
            $tenantId,
            $userId,
            $routeId,
            $title,
            $details
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Route status workflow action
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'update_status'
) {
    if (!$canManage) {
        $errors[] =
            'You do not have permission to update routes.';
    }

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!routesVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $routeId = isset($_POST['route_id'])
        ? (int) $_POST['route_id']
        : 0;

    $newStatus = isset($_POST['new_status'])
        ? trim((string) $_POST['new_status'])
        : '';

    $allowedStatuses = array(
        'draft',
        'optimized',
        'dispatched',
        'completed',
        'cancelled'
    );

    if ($routeId <= 0) {
        $errors[] =
            'Invalid route selected.';
    }

    if (
        !in_array(
            $newStatus,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Invalid route status selected.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                status
            FROM route_plans
            WHERE id = ?
              AND tenant_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to prepare the route lookup: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'ii',
                $routeId,
                $tenantId
            );

            if (!$stmt->execute()) {
                $errors[] =
                    'Unable to load the route: ' .
                    $stmt->error;
            } else {
                $routeRecord =
                    routesFetchAssoc($stmt);

                if (!$routeRecord) {
                    $errors[] =
                        'Route not found or access denied.';
                }
            }

            $stmt->close();
        }
    }

    if (
        empty($errors) &&
        !empty($routeRecord)
    ) {
        $oldStatus =
            (string) $routeRecord['status'];

        $validTransitions = array(
            'draft' => array(
                'optimized',
                'dispatched',
                'cancelled'
            ),
            'optimized' => array(
                'draft',
                'dispatched',
                'cancelled'
            ),
            'dispatched' => array(
                'completed',
                'cancelled'
            ),
            'completed' => array(),
            'cancelled' => array(
                'draft'
            )
        );

        if (
            $newStatus !== $oldStatus &&
            !in_array(
                $newStatus,
                isset($validTransitions[$oldStatus])
                    ? $validTransitions[$oldStatus]
                    : array(),
                true
            )
        ) {
            $errors[] =
                'The route cannot move from ' .
                routesLabel($oldStatus) .
                ' to ' .
                routesLabel($newStatus) .
                '.';
        }
    }

    if (
        empty($errors) &&
        !empty($routeRecord)
    ) {
        $stmt = $conn->prepare("
            UPDATE route_plans
            SET
                status = ?,
                updated_at = NOW()
            WHERE id = ?
              AND tenant_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to prepare the route update: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'sii',
                $newStatus,
                $routeId,
                $tenantId
            );

            if (!$stmt->execute()) {
                $errors[] =
                    'Route status could not be updated: ' .
                    $stmt->error;
            }

            $stmt->close();
        }

        if (empty($errors)) {
            routesLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $routeId,
                (string) $routeRecord['name'],
                (string) $routeRecord['status'],
                $newStatus
            );

            $_SESSION['flash_success'] =
                'Route status updated successfully.';

            $redirectQuery = isset($_POST['return_query'])
                ? trim((string) $_POST['return_query'])
                : '';

            header(
                'Location: routes.php' .
                (
                    $redirectQuery !== ''
                        ? '?' . $redirectQuery
                        : ''
                )
            );
            exit;
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

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$workerFilter = isset($_GET['assigned_user_id'])
    ? (int) $_GET['assigned_user_id']
    : 0;

$datePreset = isset($_GET['date_preset'])
    ? trim((string) $_GET['date_preset'])
    : '';

$dateFrom = isset($_GET['date_from'])
    ? trim((string) $_GET['date_from'])
    : '';

$dateTo = isset($_GET['date_to'])
    ? trim((string) $_GET['date_to'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'date_asc';

$allowedStatuses = array(
    '',
    'draft',
    'optimized',
    'dispatched',
    'completed',
    'cancelled'
);

$allowedPresets = array(
    '',
    'today',
    'tomorrow',
    'this_week',
    'upcoming',
    'past'
);

$allowedSorts = array(
    'date_asc',
    'date_desc',
    'latest',
    'oldest',
    'name_asc',
    'worker_asc',
    'status_asc',
    'distance_desc',
    'stops_desc'
);

if (
    !in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $statusFilter = '';
}

if (
    !in_array(
        $datePreset,
        $allowedPresets,
        true
    )
) {
    $datePreset = '';
}

if (
    !in_array(
        $sort,
        $allowedSorts,
        true
    )
) {
    $sort = 'date_asc';
}

if (
    $dateFrom !== '' &&
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $dateFrom
    )
) {
    $dateFrom = '';
}

if (
    $dateTo !== '' &&
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $dateTo
    )
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
| Worker options
|--------------------------------------------------------------------------
*/

$workers = array();

$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name,
        email,
        phone,
        job_title,
        color_code,
        is_field_worker,
        is_bookable
    FROM users
    WHERE tenant_id = ?
      AND status = 'active'
      AND deleted_at IS NULL
    ORDER BY
        is_field_worker DESC,
        is_bookable DESC,
        first_name ASC,
        last_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);

    if ($stmt->execute()) {
        $workers =
            routesFetchAll($stmt);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'today' => 0,
    'upcoming' => 0,
    'dispatched' => 0,
    'completed_month' => 0,
    'unassigned' => 0
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,

        SUM(
            route_date = CURDATE()
            AND status <> 'cancelled'
        ) AS today_count,

        SUM(
            route_date > CURDATE()
            AND status NOT IN (
                'completed',
                'cancelled'
            )
        ) AS upcoming_count,

        SUM(status = 'dispatched')
            AS dispatched_count,

        SUM(
            status = 'completed'
            AND YEAR(route_date) =
                YEAR(CURDATE())
            AND MONTH(route_date) =
                MONTH(CURDATE())
        ) AS completed_month_count,

        SUM(
            assigned_user_id IS NULL
            AND status NOT IN (
                'completed',
                'cancelled'
            )
        ) AS unassigned_count

    FROM route_plans
    WHERE tenant_id = ?
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);

    if ($stmt->execute()) {
        $row = routesFetchAssoc($stmt);

        if ($row) {
            $stats['total'] =
                (int) $row['total_count'];

            $stats['today'] =
                (int) $row['today_count'];

            $stats['upcoming'] =
                (int) $row['upcoming_count'];

            $stats['dispatched'] =
                (int) $row['dispatched_count'];

            $stats['completed_month'] =
                (int) $row['completed_month_count'];

            $stats['unassigned'] =
                (int) $row['unassigned_count'];
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Build route query
|--------------------------------------------------------------------------
*/

$where = array(
    'rp.tenant_id = ?'
);

$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        rp.name LIKE ?
        OR rp.optimization_provider LIKE ?
        OR CONCAT(
            COALESCE(u.first_name, ''),
            ' ',
            COALESCE(u.last_name, '')
        ) LIKE ?
        OR EXISTS (
            SELECT 1
            FROM route_plan_stops srps
            WHERE srps.route_plan_id = rp.id
              AND (
                  srps.address LIKE ?
                  OR CAST(srps.stop_order AS CHAR) LIKE ?
              )
        )
    )";

    $searchLike = '%' . $search . '%';

    for ($index = 0; $index < 5; $index++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($statusFilter !== '') {
    $where[] = 'rp.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($workerFilter > 0) {
    $where[] = 'rp.assigned_user_id = ?';
    $params[] = $workerFilter;
    $types .= 'i';
} elseif (
    isset($_GET['assigned_user_id']) &&
    (string) $_GET['assigned_user_id'] === '0'
) {
    $where[] =
        'rp.assigned_user_id IS NULL';
}

if ($datePreset === 'today') {
    $where[] =
        'rp.route_date = CURDATE()';
} elseif ($datePreset === 'tomorrow') {
    $where[] =
        'rp.route_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
} elseif ($datePreset === 'this_week') {
    $where[] = "
        YEARWEEK(rp.route_date, 1) =
        YEARWEEK(CURDATE(), 1)
    ";
} elseif ($datePreset === 'upcoming') {
    $where[] = "
        rp.route_date >= CURDATE()
        AND rp.status NOT IN (
            'completed',
            'cancelled'
        )
    ";
} elseif ($datePreset === 'past') {
    $where[] =
        'rp.route_date < CURDATE()';
}

if ($dateFrom !== '') {
    $where[] =
        'rp.route_date >= ?';

    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] =
        'rp.route_date <= ?';

    $params[] = $dateTo;
    $types .= 's';
}

$whereSql =
    implode(' AND ', $where);

$orderSql = "
    CASE
        WHEN rp.route_date < CURDATE()
         AND rp.status NOT IN (
            'completed',
            'cancelled'
         )
        THEN 0
        WHEN rp.route_date >= CURDATE()
        THEN 1
        ELSE 2
    END ASC,
    rp.route_date ASC,
    rp.id DESC
";

if ($sort === 'date_desc') {
    $orderSql =
        'rp.route_date DESC, rp.id DESC';
} elseif ($sort === 'latest') {
    $orderSql =
        'rp.created_at DESC, rp.id DESC';
} elseif ($sort === 'oldest') {
    $orderSql =
        'rp.created_at ASC, rp.id ASC';
} elseif ($sort === 'name_asc') {
    $orderSql =
        'rp.name ASC, rp.route_date ASC';
} elseif ($sort === 'worker_asc') {
    $orderSql = "
        u.first_name ASC,
        u.last_name ASC,
        rp.route_date ASC
    ";
} elseif ($sort === 'status_asc') {
    $orderSql =
        'rp.status ASC, rp.route_date ASC';
} elseif ($sort === 'distance_desc') {
    $orderSql = "
        rp.total_distance_km DESC,
        rp.route_date DESC
    ";
} elseif ($sort === 'stops_desc') {
    $orderSql = "
        stop_count DESC,
        rp.route_date DESC
    ";
}

/*
|--------------------------------------------------------------------------
| Count filtered routes
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM route_plans rp

    LEFT JOIN users u
        ON u.id = rp.assigned_user_id
       AND u.tenant_id = rp.tenant_id
       AND u.deleted_at IS NULL

    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare route count query: ' .
        $conn->error;
} else {
    if (
        !routesBindParams(
            $stmt,
            $types,
            $params
        )
    ) {
        $errors[] =
            'Unable to bind route filters.';
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to count routes: ' .
            $stmt->error;
    } else {
        $row = routesFetchAssoc($stmt);

        if ($row) {
            $totalFiltered =
                (int) $row['total'];
        }
    }

    $stmt->close();
}

$totalPages = max(
    1,
    (int) ceil(
        $totalFiltered / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset =
        ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Load route plans
|--------------------------------------------------------------------------
*/

$routes = array();

$listSql = "
    SELECT
        rp.id,
        rp.name,
        rp.assigned_user_id,
        rp.route_date,
        rp.status,
        rp.optimization_provider,
        rp.total_distance_km,
        rp.total_duration_minutes,
        rp.created_by,
        rp.created_at,
        rp.updated_at,

        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS worker_name,

        u.phone AS worker_phone,
        u.email AS worker_email,
        u.job_title AS worker_job_title,
        u.color_code AS worker_color,

        CONCAT(
            COALESCE(cu.first_name, ''),
            CASE
                WHEN cu.last_name IS NOT NULL
                 AND cu.last_name <> ''
                THEN CONCAT(' ', cu.last_name)
                ELSE ''
            END
        ) AS created_by_name,

        COUNT(DISTINCT rps.id)
            AS stop_count,

        COUNT(
            DISTINCT CASE
                WHEN v.status = 'completed'
                THEN rps.id
                ELSE NULL
            END
        ) AS completed_stop_count,

        COUNT(
            DISTINCT rps.property_id
        ) AS property_count,

        COUNT(
            DISTINCT rps.visit_id
        ) AS visit_count,

        MIN(rps.estimated_arrival)
            AS first_estimated_arrival,

        MAX(rps.estimated_departure)
            AS last_estimated_departure,

        MIN(rps.stop_order)
            AS first_stop_order,

        MAX(rps.stop_order)
            AS last_stop_order

    FROM route_plans rp

    LEFT JOIN users u
        ON u.id = rp.assigned_user_id
       AND u.tenant_id = rp.tenant_id
       AND u.deleted_at IS NULL

    LEFT JOIN users cu
        ON cu.id = rp.created_by
       AND cu.tenant_id = rp.tenant_id
       AND cu.deleted_at IS NULL

    LEFT JOIN route_plan_stops rps
        ON rps.route_plan_id = rp.id
       AND rps.tenant_id = rp.tenant_id

    LEFT JOIN visits v
        ON v.id = rps.visit_id
       AND v.tenant_id = rp.tenant_id

    WHERE {$whereSql}

    GROUP BY
        rp.id,
        rp.name,
        rp.assigned_user_id,
        rp.route_date,
        rp.status,
        rp.optimization_provider,
        rp.total_distance_km,
        rp.total_duration_minutes,
        rp.created_by,
        rp.created_at,
        rp.updated_at,
        u.first_name,
        u.last_name,
        u.phone,
        u.email,
        u.job_title,
        u.color_code,
        cu.first_name,
        cu.last_name

    ORDER BY {$orderSql}

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($listSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare route list query: ' .
        $conn->error;
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    if (
        !routesBindParams(
            $stmt,
            $listTypes,
            $listParams
        )
    ) {
        $errors[] =
            'Unable to bind route list filters.';
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to load routes: ' .
            $stmt->error;
    } else {
        $routes =
            routesFetchAll($stmt);
    }

    $stmt->close();
}

$csrfToken =
    routesCsrfToken();

$returnQuery = routesQueryString(
    array('page' => $page)
);

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.routes-page {
    --rt-primary: #6d28d9;
    --rt-text: #111827;
    --rt-muted: #6b7280;
    --rt-border: #e5e7eb;
}

.rt-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.rt-header h1 {
    margin: 0;
    color: var(--rt-text);
    font-size: 21px;
    font-weight: 700;
}

.rt-header p {
    margin: 5px 0 0;
    color: var(--rt-muted);
    font-size: 11px;
}

.rt-add {
    min-height: 36px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 9px;
    background: var(--rt-primary);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.rt-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
}

.rt-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.rt-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.rt-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns:
        repeat(6,minmax(0,1fr));
    gap: 10px;
}

.rt-stat {
    padding: 13px;
    border: 1px solid var(--rt-border);
    border-radius: 11px;
    background: #fff;
}

.rt-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.rt-stat-value {
    margin-top: 4px;
    color: var(--rt-text);
    font-size: 19px;
    font-weight: 700;
}

.rt-panel {
    overflow: hidden;
    border: 1px solid var(--rt-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.rt-filters {
    padding: 12px;
    display: grid;
    grid-template-columns:
        minmax(230px,1.3fr)
        repeat(3,minmax(145px,.72fr))
        repeat(2,minmax(125px,.58fr))
        minmax(165px,.75fr)
        auto;
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.rt-input,
.rt-select {
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

.rt-input:focus,
.rt-select:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.08);
}

.rt-filter-actions {
    display: flex;
    gap: 6px;
}

.rt-filter-btn,
.rt-reset {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 700;
}

.rt-filter-btn {
    border: 0;
    background: var(--rt-primary);
    color: #fff;
    cursor: pointer;
}

.rt-reset {
    border: 1px solid var(--rt-border);
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.rt-cards {
    padding: 13px;
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 12px;
}

.rt-card {
    overflow: hidden;
    border: 1px solid #e7e9ee;
    border-radius: 11px;
    background: #fff;
}

.rt-card-top {
    padding: 13px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.rt-route-name {
    color: #111827;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
}

.rt-route-date {
    margin-top: 4px;
    color: #6b7280;
    font-size: 8px;
}

.rt-status {
    padding: 5px 8px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
}

.rt-status.draft {
    background: #f3f4f6;
    color: #4b5563;
}

.rt-status.optimized {
    background: #f5f3ff;
    color: #6d28d9;
}

.rt-status.dispatched {
    background: #eff6ff;
    color: #1d4ed8;
}

.rt-status.completed {
    background: #ecfdf5;
    color: #047857;
}

.rt-status.cancelled {
    background: #fef2f2;
    color: #b91c1c;
}

.rt-overdue {
    margin-left: 5px;
    padding: 4px 7px;
    display: inline-flex;
    border-radius: 999px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 7px;
    font-weight: 700;
}

.rt-card-body {
    padding: 13px;
}

.rt-worker {
    margin-bottom: 11px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.rt-worker-dot {
    width: 10px;
    height: 10px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: #d1d5db;
}

.rt-worker-name {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
}

.rt-worker-role {
    margin-top: 2px;
    color: #9ca3af;
    font-size: 8px;
}

.rt-metrics {
    display: grid;
    grid-template-columns:
        repeat(4,minmax(0,1fr));
    gap: 7px;
}

.rt-metric {
    padding: 8px;
    border: 1px solid #edf0f5;
    border-radius: 8px;
    background: #fafafa;
}

.rt-metric-label {
    color: #9ca3af;
    font-size: 7px;
    font-weight: 700;
    text-transform: uppercase;
}

.rt-metric-value {
    margin-top: 3px;
    display: block;
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.rt-progress {
    margin-top: 11px;
}

.rt-progress-head {
    margin-bottom: 5px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    color: #6b7280;
    font-size: 8px;
}

.rt-progress-track {
    height: 6px;
    overflow: hidden;
    border-radius: 999px;
    background: #f1f5f9;
}

.rt-progress-bar {
    height: 100%;
    border-radius: inherit;
    background: var(--rt-primary);
}

.rt-card-footer {
    padding: 10px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

.rt-card-meta {
    color: #9ca3af;
    font-size: 8px;
}

.rt-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 5px;
}

.rt-action {
    min-height: 30px;
    padding: 6px 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    border: 1px solid var(--rt-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-family: inherit;
    font-size: 8px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.rt-action.primary {
    border-color: var(--rt-primary);
    background: var(--rt-primary);
    color: #fff;
}

.rt-action.danger {
    border-color: #fecaca;
    color: #b91c1c;
}

.rt-action-form {
    margin: 0;
}

.rt-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #f1f5f9;
}

.rt-result {
    color: #6b7280;
    font-size: 9px;
}

.rt-pages {
    display: flex;
    gap: 5px;
}

.rt-page-link {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--rt-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.rt-page-link.active {
    border-color: var(--rt-primary);
    background: var(--rt-primary);
    color: #fff;
}

.rt-empty {
    padding: 44px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1350px) {
    .rt-filters {
        grid-template-columns:
            repeat(4,minmax(0,1fr));
    }

    .rt-metrics {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 1000px) {
    .rt-stats {
        grid-template-columns:
            repeat(3,minmax(0,1fr));
    }

    .rt-cards {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .rt-header {
        flex-direction: column;
    }

    .rt-filters {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 560px) {
    .rt-stats,
    .rt-filters,
    .rt-metrics {
        grid-template-columns: 1fr;
    }

    .rt-filter-actions {
        width: 100%;
    }

    .rt-filter-btn,
    .rt-reset {
        flex: 1;
    }

    .rt-card-top,
    .rt-card-footer,
    .rt-footer {
        align-items: flex-start;
        flex-direction: column;
    }

    .rt-actions {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }
}
</style>

<div class="routes-page">
    <div class="rt-header">
        <div>
            <h1>Routes</h1>
            <p>
                Plan daily worker travel, monitor route progress, and manage route dispatch.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a href="route-add.php" class="rt-add">
                <i class="bi bi-plus-lg"></i>
                Add Route
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="rt-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="rt-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="rt-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="rt-stats">
        <article class="rt-stat">
            <div class="rt-stat-label">
                Total Routes
            </div>
            <div class="rt-stat-value">
                <?= e($stats['total']); ?>
            </div>
        </article>

        <article class="rt-stat">
            <div class="rt-stat-label">
                Today
            </div>
            <div class="rt-stat-value">
                <?= e($stats['today']); ?>
            </div>
        </article>

        <article class="rt-stat">
            <div class="rt-stat-label">
                Upcoming
            </div>
            <div class="rt-stat-value">
                <?= e($stats['upcoming']); ?>
            </div>
        </article>

        <article class="rt-stat">
            <div class="rt-stat-label">
                Dispatched
            </div>
            <div class="rt-stat-value">
                <?= e($stats['dispatched']); ?>
            </div>
        </article>

        <article class="rt-stat">
            <div class="rt-stat-label">
                Completed This Month
            </div>
            <div class="rt-stat-value">
                <?= e($stats['completed_month']); ?>
            </div>
        </article>

        <article class="rt-stat">
            <div class="rt-stat-label">
                Unassigned
            </div>
            <div class="rt-stat-value">
                <?= e($stats['unassigned']); ?>
            </div>
        </article>
    </section>

    <section class="rt-panel">
        <form method="get" action="" class="rt-filters">
            <input
                type="search"
                name="search"
                class="rt-input"
                value="<?= e($search); ?>"
                placeholder="Search route, worker, provider or stop address"
            >

            <select name="status" class="rt-select">
                <option value="">All Statuses</option>

                <?php foreach (
                    array(
                        'draft' => 'Draft',
                        'optimized' => 'Optimized',
                        'dispatched' => 'Dispatched',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled'
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
                name="assigned_user_id"
                class="rt-select"
            >
                <option value="">All Workers</option>

                <option
                    value="0"
                    <?= isset(
                        $_GET['assigned_user_id']
                    ) &&
                    (string) $_GET['assigned_user_id'] === '0'
                        ? 'selected'
                        : ''; ?>
                >
                    Unassigned
                </option>

                <?php foreach ($workers as $worker): ?>
                    <?php
                    $workerName = trim(
                        (string) $worker['first_name'] .
                        ' ' .
                        (string) $worker['last_name']
                    );
                    ?>
                    <option
                        value="<?= (int) $worker['id']; ?>"
                        <?= $workerFilter ===
                            (int) $worker['id']
                                ? 'selected'
                                : ''; ?>
                    >
                        <?= e($workerName); ?>

                        <?php if (
                            trim(
                                (string) $worker['job_title']
                            ) !== ''
                        ): ?>
                            · <?= e($worker['job_title']); ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="date_preset"
                class="rt-select"
            >
                <option value="">All Dates</option>

                <?php foreach (
                    array(
                        'today' => 'Today',
                        'tomorrow' => 'Tomorrow',
                        'this_week' => 'This Week',
                        'upcoming' => 'Upcoming',
                        'past' => 'Past'
                    ) as $value => $label
                ): ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $datePreset === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input
                type="date"
                name="date_from"
                class="rt-input"
                value="<?= e($dateFrom); ?>"
                title="Route date from"
            >

            <input
                type="date"
                name="date_to"
                class="rt-input"
                value="<?= e($dateTo); ?>"
                title="Route date to"
            >

            <select name="sort" class="rt-select">
                <option
                    value="date_asc"
                    <?= $sort === 'date_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Route Date Ascending
                </option>

                <option
                    value="date_desc"
                    <?= $sort === 'date_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Route Date Descending
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
                    value="name_asc"
                    <?= $sort === 'name_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Route Name
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

                <option
                    value="distance_desc"
                    <?= $sort === 'distance_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Highest Distance
                </option>

                <option
                    value="stops_desc"
                    <?= $sort === 'stops_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Most Stops
                </option>
            </select>

            <div class="rt-filter-actions">
                <button
                    type="submit"
                    class="rt-filter-btn"
                >
                    Apply
                </button>

                <a
                    href="routes.php"
                    class="rt-reset"
                >
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($routes)): ?>
            <div class="rt-cards">
                <?php foreach ($routes as $route): ?>
                    <?php
                    $workerName = trim(
                        (string) $route['worker_name']
                    );

                    $workerColor = trim(
                        (string) $route['worker_color']
                    );

                    if (
                        !preg_match(
                            '/^#[0-9A-Fa-f]{6}$/',
                            $workerColor
                        )
                    ) {
                        $workerColor = '#d1d5db';
                    }

                    $stopCount =
                        (int) $route['stop_count'];

                    $completedStops =
                        (int) $route['completed_stop_count'];

                    $progress = $stopCount > 0
                        ? min(
                            100,
                            round(
                                ($completedStops /
                                    $stopCount) * 100
                            )
                        )
                        : 0;

                    if (
                        $route['status'] === 'completed'
                    ) {
                        $progress = 100;
                    }

                    $isPastDue =
                        !empty($route['route_date']) &&
                        strtotime(
                            $route['route_date'] .
                            ' 23:59:59'
                        ) < time() &&
                        !in_array(
                            $route['status'],
                            array(
                                'completed',
                                'cancelled'
                            ),
                            true
                        );

                    $workflowActions = array();

                    if ($canManage) {
                        if (
                            $route['status'] === 'draft'
                        ) {
                            $workflowActions = array(
                                'optimized' => 'Mark Optimized',
                                'dispatched' => 'Dispatch',
                                'cancelled' => 'Cancel'
                            );
                        } elseif (
                            $route['status'] === 'optimized'
                        ) {
                            $workflowActions = array(
                                'dispatched' => 'Dispatch',
                                'draft' => 'Return to Draft',
                                'cancelled' => 'Cancel'
                            );
                        } elseif (
                            $route['status'] === 'dispatched'
                        ) {
                            $workflowActions = array(
                                'completed' => 'Complete',
                                'cancelled' => 'Cancel'
                            );
                        } elseif (
                            $route['status'] === 'cancelled'
                        ) {
                            $workflowActions = array(
                                'draft' => 'Reopen'
                            );
                        }
                    }
                    ?>

                    <article class="rt-card">
                        <div class="rt-card-top">
                            <div>
                                <a
                                    href="route-view.php?id=<?= (int) $route['id']; ?>"
                                    class="rt-route-name"
                                >
                                    <?= e($route['name']); ?>
                                </a>

                                <div class="rt-route-date">
                                    <i class="bi bi-calendar3"></i>
                                    <?= e(
                                        routesDate(
                                            $route['route_date']
                                        )
                                    ); ?>

                                    <?php if (
                                        !empty(
                                            $route['first_estimated_arrival']
                                        )
                                    ): ?>
                                        · Starts
                                        <?= e(
                                            routesTime(
                                                $route['first_estimated_arrival']
                                            )
                                        ); ?>
                                    <?php endif; ?>

                                    <?php if (
                                        !empty(
                                            $route['last_estimated_departure']
                                        )
                                    ): ?>
                                        · Ends
                                        <?= e(
                                            routesTime(
                                                $route['last_estimated_departure']
                                            )
                                        ); ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <span class="rt-status <?= e(
                                    routesStatusClass(
                                        $route['status']
                                    )
                                ); ?>">
                                    <?= e(
                                        routesLabel(
                                            $route['status']
                                        )
                                    ); ?>
                                </span>

                                <?php if ($isPastDue): ?>
                                    <span class="rt-overdue">
                                        Overdue
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="rt-card-body">
                            <div class="rt-worker">
                                <span
                                    class="rt-worker-dot"
                                    style="background:<?= e(
                                        $workerColor
                                    ); ?>;"
                                ></span>

                                <div>
                                    <div class="rt-worker-name">
                                        <?= e(
                                            $workerName !== ''
                                                ? $workerName
                                                : 'Unassigned'
                                        ); ?>
                                    </div>

                                    <div class="rt-worker-role">
                                        <?= e(
                                            trim(
                                                (string) $route['worker_job_title']
                                            ) !== ''
                                                ? $route['worker_job_title']
                                                : 'Route worker'
                                        ); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="rt-metrics">
                                <div class="rt-metric">
                                    <span class="rt-metric-label">
                                        Stops
                                    </span>
                                    <span class="rt-metric-value">
                                        <?= e($stopCount); ?>
                                    </span>
                                </div>

                                <div class="rt-metric">
                                    <span class="rt-metric-label">
                                        Visits
                                    </span>
                                    <span class="rt-metric-value">
                                        <?= e(
                                            (int) $route['visit_count']
                                        ); ?>
                                    </span>
                                </div>

                                <div class="rt-metric">
                                    <span class="rt-metric-label">
                                        Distance
                                    </span>
                                    <span class="rt-metric-value">
                                        <?= $route['total_distance_km'] !== null
                                            ? e(
                                                number_format(
                                                    (float) $route['total_distance_km'],
                                                    2
                                                ) . ' km'
                                            )
                                            : '—'; ?>
                                    </span>
                                </div>

                                <div class="rt-metric">
                                    <span class="rt-metric-label">
                                        Duration
                                    </span>
                                    <span class="rt-metric-value">
                                        <?= e(
                                            routesDuration(
                                                $route['total_duration_minutes']
                                            )
                                        ); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="rt-progress">
                                <div class="rt-progress-head">
                                    <span>Route Progress</span>
                                    <span>
                                        <?= e($completedStops); ?>
                                        /
                                        <?= e($stopCount); ?>
                                        completed
                                    </span>
                                </div>

                                <div class="rt-progress-track">
                                    <div
                                        class="rt-progress-bar"
                                        style="width:<?= e($progress); ?>%;"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <div class="rt-card-footer">
                            <div class="rt-card-meta">
                                <?php if (
                                    trim(
                                        (string) $route['optimization_provider']
                                    ) !== ''
                                ): ?>
                                    Optimized by
                                    <?= e(
                                        $route['optimization_provider']
                                    ); ?>
                                    ·
                                <?php endif; ?>

                                Created
                                <?= e(
                                    routesDate(
                                        $route['created_at']
                                    )
                                ); ?>
                            </div>

                            <div class="rt-actions">
                                <a
                                    href="route-view.php?id=<?= (int) $route['id']; ?>"
                                    class="rt-action primary"
                                >
                                    <i class="bi bi-eye"></i>
                                    View Route
                                </a>

                                <?php foreach (
                                    $workflowActions as
                                    $newStatus => $actionLabel
                                ): ?>
                                    <?php
                                    $actionClass =
                                        in_array(
                                            $newStatus,
                                            array(
                                                'cancelled'
                                            ),
                                            true
                                        )
                                            ? 'danger'
                                            : '';
                                    ?>

                                    <form
                                        method="post"
                                        action=""
                                        class="rt-action-form"
                                        onsubmit="return confirm('Change this route to <?= e(
                                            routesLabel(
                                                $newStatus
                                            )
                                        ); ?>?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e(
                                                $csrfToken
                                            ); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="update_status"
                                        >

                                        <input
                                            type="hidden"
                                            name="route_id"
                                            value="<?= (int) $route['id']; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="new_status"
                                            value="<?= e($newStatus); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="return_query"
                                            value="<?= e(
                                                $returnQuery
                                            ); ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="rt-action <?= e(
                                                $actionClass
                                            ); ?>"
                                        >
                                            <?= e($actionLabel); ?>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="rt-footer">
                <div class="rt-result">
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
                            $offset + count($routes)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    routes
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="rt-pages">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    routesQueryString(
                                        array(
                                            'page' =>
                                                $page - 1
                                        )
                                    )
                                ); ?>"
                                class="rt-page-link"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage =
                            max(1, $page - 2);

                        $endPage =
                            min(
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
                                    routesQueryString(
                                        array(
                                            'page' =>
                                                $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="rt-page-link <?= $pageNumber === $page
                                    ? 'active'
                                    : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if (
                            $page < $totalPages
                        ): ?>
                            <a
                                href="?<?= e(
                                    routesQueryString(
                                        array(
                                            'page' =>
                                                $page + 1
                                        )
                                    )
                                ); ?>"
                                class="rt-page-link"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="rt-empty">
                <?php if (
                    $search !== '' ||
                    $statusFilter !== '' ||
                    isset(
                        $_GET['assigned_user_id']
                    ) ||
                    $datePreset !== '' ||
                    $dateFrom !== '' ||
                    $dateTo !== ''
                ): ?>
                    No routes were found for the selected filters.
                <?php else: ?>
                    No route plans are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
