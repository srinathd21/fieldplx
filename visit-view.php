<?php
/**
 * FieldPlx - Visit View
 *
 * Upload as:
 * /public_html/visit-view.php
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
| Visits currently use the Jobs module permissions.
|
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode(
            'visit-view.php?id=' .
            (
                isset($_GET['id'])
                    ? (int) $_GET['id']
                    : 0
            )
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'jobs.view',
        'You do not have permission to view visits.'
    );
}

$pageTitle = 'Visit Details - FieldPlx';
$activePage = 'visits';
$searchPlaceholder = 'Search visits...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$visitId = isset($_POST['id'])
    ? (int) $_POST['id']
    : (
        isset($_GET['id'])
            ? (int) $_GET['id']
            : 0
    );

if ($visitId <= 0) {
    $_SESSION['flash_error'] =
        'Invalid visit selected.';

    header('Location: visits.php');
    exit;
}

$canManage = function_exists('hasPermission')
    ? hasPermission('jobs.manage')
    : true;

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('visitViewFetchAssoc')) {
    function visitViewFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('visitViewFetchAll')) {
    function visitViewFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('visitViewDate')) {
    function visitViewDate($value)
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

if (!function_exists('visitViewDateTime')) {
    function visitViewDateTime($value)
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

if (!function_exists('visitViewLabel')) {
    function visitViewLabel($value)
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

if (!function_exists('visitViewStatusClass')) {
    function visitViewStatusClass($value)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(trim((string) $value))
        );
    }
}

if (!function_exists('visitViewDuration')) {
    function visitViewDuration($start, $end)
    {
        if (empty($start) || empty($end)) {
            return '—';
        }

        $startTime = strtotime((string) $start);
        $endTime = strtotime((string) $end);

        if (
            $startTime === false ||
            $endTime === false ||
            $endTime <= $startTime
        ) {
            return '—';
        }

        $minutes = (int) floor(
            ($endTime - $startTime) / 60
        );

        $hours = (int) floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($hours > 0 && $remainingMinutes > 0) {
            return $hours .
                ' hr ' .
                $remainingMinutes .
                ' min';
        }

        if ($hours > 0) {
            return $hours .
                ($hours === 1 ? ' hour' : ' hours');
        }

        return $minutes . ' min';
    }
}

if (!function_exists('visitViewCsrfToken')) {
    function visitViewCsrfToken()
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

if (!function_exists('visitViewVerifyCsrf')) {
    function visitViewVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('visitViewLogActivity')) {
    function visitViewLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $visitId,
        $clientId,
        $visitNo,
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
                'visit_status_updated',
                'visit',
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
            'Visit status changed: ' .
            $visitNo .
            ' · ' .
            visitViewLabel($oldStatus) .
            ' to ' .
            visitViewLabel($newStatus);

        $details = json_encode(
            array(
                'visit_id' => (int) $visitId,
                'visit_no' => (string) $visitNo,
                'old_status' => (string) $oldStatus,
                'new_status' => (string) $newStatus
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $visitId,
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
| Load visit
|--------------------------------------------------------------------------
*/

function loadVisitRecord(
    mysqli $conn,
    $visitId,
    $tenantId
) {
    $stmt = $conn->prepare("
        SELECT
            v.id,
            v.tenant_id,
            v.job_id,
            v.visit_no,
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
            j.description AS job_description,
            j.status AS job_status,
            j.client_id,
            j.property_id,
            j.assigned_user_id AS job_assigned_user_id,
            j.start_date AS job_start_date,
            j.end_date AS job_end_date,
            j.total AS job_total,
            j.invoicing_preference,

            c.display_name AS client_name,
            c.email AS client_email,
            c.phone AS client_phone,
            c.alternate_phone AS client_alternate_phone,

            p.name AS property_name,
            p.address_line1 AS property_address_line1,
            p.address_line2 AS property_address_line2,
            p.city AS property_city,
            p.state AS property_state,
            p.postal_code AS property_postal_code,
            p.country AS property_country,
            p.latitude AS property_latitude,
            p.longitude AS property_longitude,

            CONCAT(
                COALESCE(u.first_name, ''),
                CASE
                    WHEN u.last_name IS NOT NULL
                     AND u.last_name <> ''
                    THEN CONCAT(' ', u.last_name)
                    ELSE ''
                END
            ) AS assigned_user_name,

            u.email AS assigned_user_email,
            u.phone AS assigned_user_phone,
            u.job_title AS assigned_user_job_title,
            u.employee_code AS assigned_user_employee_code,
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

        WHERE v.id = ?
          AND v.tenant_id = ?

        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'ii',
        $visitId,
        $tenantId
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $visit = visitViewFetchAssoc($stmt);
    $stmt->close();

    return $visit;
}

$visit = loadVisitRecord(
    $conn,
    $visitId,
    $tenantId
);

if (!$visit) {
    $_SESSION['flash_error'] =
        'Visit not found or access denied.';

    header('Location: visits.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Status action
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'update_status'
) {
    if (!$canManage) {
        $errors[] =
            'You do not have permission to update this visit.';
    }

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!visitViewVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $newStatus = isset($_POST['new_status'])
        ? trim((string) $_POST['new_status'])
        : '';

    $allowedStatuses = array(
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

    if (
        !in_array(
            $newStatus,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'The selected visit status is invalid.';
    }

    if (empty($errors)) {
        $oldStatus =
            (string) $visit['status'];

        $actualStart =
            !empty($visit['actual_start'])
                ? (string) $visit['actual_start']
                : null;

        $actualEnd =
            !empty($visit['actual_end'])
                ? (string) $visit['actual_end']
                : null;

        $now = date('Y-m-d H:i:s');

        if (
            in_array(
                $newStatus,
                array(
                    'in_progress',
                    'completed',
                    'needs_review'
                ),
                true
            ) &&
            $actualStart === null
        ) {
            $actualStart = $now;
        }

        if (
            in_array(
                $newStatus,
                array(
                    'completed',
                    'needs_review'
                ),
                true
            ) &&
            $actualEnd === null
        ) {
            $actualEnd = $now;
        }

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                UPDATE visits
                SET
                    status = ?,
                    actual_start = ?,
                    actual_end = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the visit status update: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'sssii',
                $newStatus,
                $actualStart,
                $actualEnd,
                $visitId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Visit status could not be updated: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            $scheduleStatus = 'scheduled';

            if (
                in_array(
                    $newStatus,
                    array(
                        'completed',
                        'needs_review'
                    ),
                    true
                )
            ) {
                $scheduleStatus = 'completed';
            } elseif ($newStatus === 'missed') {
                $scheduleStatus = 'missed';
            } elseif ($newStatus === 'cancelled') {
                $scheduleStatus = 'cancelled';
            }

            $stmt = $conn->prepare("
                UPDATE schedule_events
                SET
                    status = ?,
                    updated_at = NOW()
                WHERE tenant_id = ?
                  AND related_type = 'visit'
                  AND related_id = ?
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'sii',
                    $scheduleStatus,
                    $tenantId,
                    $visitId
                );

                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();

            visitViewLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $visitId,
                (int) $visit['client_id'],
                (string) $visit['visit_no'],
                $oldStatus,
                $newStatus
            );

            $_SESSION['flash_success'] =
                'Visit status updated successfully.';

            header(
                'Location: visit-view.php?id=' .
                $visitId
            );
            exit;
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            $errors[] =
                $error->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Related work orders
|--------------------------------------------------------------------------
*/

$workOrders = array();

$stmt = $conn->prepare("
    SELECT
        id,
        work_order_no,
        title,
        status,
        scheduled_start,
        scheduled_end
    FROM work_orders
    WHERE tenant_id = ?
      AND job_id = ?
      AND deleted_at IS NULL
    ORDER BY created_at DESC, id DESC
    LIMIT 10
");

if ($stmt) {
    $jobId = (int) $visit['job_id'];

    $stmt->bind_param(
        'ii',
        $tenantId,
        $jobId
    );

    if ($stmt->execute()) {
        $workOrders =
            visitViewFetchAll($stmt);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Other visits for the job
|--------------------------------------------------------------------------
*/

$otherVisits = array();

$stmt = $conn->prepare("
    SELECT
        id,
        visit_no,
        assigned_user_id,
        scheduled_start,
        scheduled_end,
        status
    FROM visits
    WHERE tenant_id = ?
      AND job_id = ?
      AND id <> ?
    ORDER BY
        CASE
            WHEN scheduled_start IS NULL THEN 1
            ELSE 0
        END,
        scheduled_start DESC,
        id DESC
    LIMIT 10
");

if ($stmt) {
    $jobId = (int) $visit['job_id'];

    $stmt->bind_param(
        'iii',
        $tenantId,
        $jobId,
        $visitId
    );

    if ($stmt->execute()) {
        $otherVisits =
            visitViewFetchAll($stmt);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Activity history
|--------------------------------------------------------------------------
*/

$activities = array();

$stmt = $conn->prepare("
    SELECT
        ae.id,
        ae.event_type,
        ae.title,
        ae.details_json,
        ae.created_at,

        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS actor_name

    FROM activity_events ae

    LEFT JOIN users u
        ON u.id = ae.actor_user_id
       AND u.tenant_id = ae.tenant_id
       AND u.deleted_at IS NULL

    WHERE ae.tenant_id = ?
      AND ae.related_type = 'visit'
      AND ae.related_id = ?

    ORDER BY ae.created_at DESC, ae.id DESC
    LIMIT 25
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $visitId
    );

    if ($stmt->execute()) {
        $activities =
            visitViewFetchAll($stmt);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Display values
|--------------------------------------------------------------------------
*/

$visitNumber =
    trim((string) $visit['visit_no']) !== ''
        ? (string) $visit['visit_no']
        : 'Visit #' . $visitId;

$workerName =
    trim((string) $visit['assigned_user_name']);

$propertyParts = array_filter(
    array(
        $visit['property_address_line1'],
        $visit['property_address_line2'],
        $visit['property_city'],
        $visit['property_state'],
        $visit['property_postal_code'],
        $visit['property_country']
    ),
    function ($value) {
        return trim((string) $value) !== '';
    }
);

$propertyAddress =
    !empty($propertyParts)
        ? implode(', ', $propertyParts)
        : 'No property address';

$propertyName =
    trim((string) $visit['property_name']) !== ''
        ? (string) $visit['property_name']
        : (
            trim((string) $visit['property_address_line1']) !== ''
                ? (string) $visit['property_address_line1']
                : 'No Property'
        );

$plannedDuration = visitViewDuration(
    $visit['scheduled_start'],
    $visit['scheduled_end']
);

$actualDuration = visitViewDuration(
    $visit['actual_start'],
    $visit['actual_end']
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

$mapQuery = trim(
    $propertyAddress !== 'No property address'
        ? $propertyAddress
        : $propertyName
);

$csrfToken =
    visitViewCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.visit-view-page {
    --vv-primary: #6d28d9;
    --vv-text: #111827;
    --vv-muted: #6b7280;
    --vv-border: #e5e7eb;
}

.vv-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.vv-header h1 {
    margin: 0;
    color: var(--vv-text);
    font-size: 21px;
    font-weight: 700;
}

.vv-header p {
    margin: 5px 0 0;
    color: var(--vv-muted);
    font-size: 11px;
}

.vv-header-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 7px;
}

.vv-btn {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 1px solid var(--vv-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-family: inherit;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.vv-btn.primary {
    border-color: var(--vv-primary);
    background: var(--vv-primary);
    color: #fff;
}

.vv-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
}

.vv-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.vv-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.vv-overview {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns:
        repeat(6,minmax(0,1fr));
    gap: 10px;
}

.vv-stat {
    padding: 13px;
    border: 1px solid var(--vv-border);
    border-radius: 11px;
    background: #fff;
}

.vv-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.vv-stat-value {
    margin-top: 5px;
    display: block;
    color: var(--vv-text);
    font-size: 11px;
    font-weight: 700;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.vv-grid {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(300px,.72fr);
    gap: 13px;
    align-items: start;
}

.vv-card {
    overflow: hidden;
    border: 1px solid var(--vv-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.vv-card + .vv-card {
    margin-top: 13px;
}

.vv-card-head {
    min-height: 46px;
    padding: 11px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.vv-card-head h2 {
    margin: 0;
    color: var(--vv-text);
    font-size: 11px;
    font-weight: 700;
}

.vv-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.vv-card-body {
    padding: 14px;
}

.vv-status {
    padding: 5px 8px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
}

.vv-status.draft {
    background: #f3f4f6;
    color: #4b5563;
}

.vv-status.scheduled {
    background: #eff6ff;
    color: #1d4ed8;
}

.vv-status.dispatched {
    background: #f5f3ff;
    color: #6d28d9;
}

.vv-status.on_my_way {
    background: #ecfeff;
    color: #0e7490;
}

.vv-status.in_progress {
    background: #fff7ed;
    color: #c2410c;
}

.vv-status.completed {
    background: #ecfdf5;
    color: #047857;
}

.vv-status.needs_review {
    background: #fffbeb;
    color: #b45309;
}

.vv-status.missed,
.vv-status.cancelled {
    background: #fef2f2;
    color: #b91c1c;
}

.vv-overdue {
    margin-left: 5px;
    padding: 4px 7px;
    display: inline-flex;
    border-radius: 999px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 8px;
    font-weight: 700;
}

.vv-detail-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 10px;
}

.vv-detail {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.vv-detail.full {
    grid-column: 1 / -1;
}

.vv-detail-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.vv-detail-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 600;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.vv-detail-value a {
    color: var(--vv-primary);
    text-decoration: none;
}

.vv-copy {
    margin: 0;
    color: #374151;
    font-size: 10px;
    line-height: 1.75;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.vv-empty-copy {
    color: #9ca3af;
    font-size: 10px;
}

.vv-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.vv-list-item {
    padding: 10px 0;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.vv-list-item:first-child {
    padding-top: 0;
}

.vv-list-item:last-child {
    padding-bottom: 0;
    border-bottom: 0;
}

.vv-list-main {
    min-width: 0;
}

.vv-list-title {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.vv-list-sub {
    margin-top: 3px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.5;
}

.vv-list-tag {
    flex: 0 0 auto;
    padding: 4px 7px;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 7px;
    font-weight: 700;
}

.vv-contact-actions {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.vv-contact-link {
    min-height: 30px;
    padding: 6px 9px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 1px solid var(--vv-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
    text-decoration: none;
}

.vv-workflow {
    display: grid;
    gap: 7px;
}

.vv-status-form {
    margin: 0;
}

.vv-status-action {
    width: 100%;
    min-height: 36px;
    padding: 8px 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 1px solid var(--vv-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-family: inherit;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.vv-status-action.primary {
    border-color: var(--vv-primary);
    background: var(--vv-primary);
    color: #fff;
}

.vv-status-action.danger {
    border-color: #fecaca;
    background: #fff;
    color: #b91c1c;
}

.vv-activity {
    position: relative;
    padding-left: 19px;
}

.vv-activity::before {
    content: "";
    position: absolute;
    top: 4px;
    bottom: 4px;
    left: 5px;
    width: 1px;
    background: #e5e7eb;
}

.vv-activity-item {
    position: relative;
    padding: 0 0 13px;
}

.vv-activity-item:last-child {
    padding-bottom: 0;
}

.vv-activity-dot {
    position: absolute;
    top: 4px;
    left: -18px;
    width: 9px;
    height: 9px;
    border: 2px solid #fff;
    border-radius: 50%;
    background: var(--vv-primary);
    box-shadow: 0 0 0 1px #c4b5fd;
}

.vv-activity-title {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    line-height: 1.45;
}

.vv-activity-meta {
    margin-top: 3px;
    color: #9ca3af;
    font-size: 8px;
}

.vv-invoice-box {
    padding: 12px;
    border: 1px solid #ddd6fe;
    border-radius: 10px;
    background: #f5f3ff;
}

.vv-invoice-title {
    color: #5b21b6;
    font-size: 10px;
    font-weight: 700;
}

.vv-invoice-text {
    margin-top: 4px;
    color: #7c3aed;
    font-size: 8px;
    line-height: 1.55;
}

@media print {
    .sidebar,
    .topbar,
    .vv-header-actions,
    .vv-workflow,
    .no-print {
        display: none !important;
    }

    .main-content,
    .content-wrapper {
        margin: 0 !important;
        padding: 0 !important;
    }

    .vv-card,
    .vv-stat {
        box-shadow: none !important;
        break-inside: avoid;
    }

    .vv-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 1100px) {
    .vv-overview {
        grid-template-columns:
            repeat(3,minmax(0,1fr));
    }

    .vv-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .vv-header {
        flex-direction: column;
    }

    .vv-header-actions {
        justify-content: flex-start;
    }

    .vv-overview,
    .vv-detail-grid {
        grid-template-columns: 1fr;
    }

    .vv-detail.full {
        grid-column: auto;
    }
}
</style>

<div class="visit-view-page">
    <div class="vv-header">
        <div>
            <h1><?= e($visitNumber); ?></h1>
            <p>
                <?= e($visit['job_no']); ?>
                · <?= e($visit['job_title']); ?>
                · <?= e($visit['client_name']); ?>
            </p>
        </div>

        <div class="vv-header-actions">
            <a href="visits.php" class="vv-btn">
                <i class="bi bi-arrow-left"></i>
                Back
            </a>

            <button
                type="button"
                class="vv-btn"
                onclick="window.print();"
            >
                <i class="bi bi-printer"></i>
                Print
            </button>

            <a
                href="job-view.php?id=<?= (int) $visit['job_id']; ?>"
                class="vv-btn"
            >
                <i class="bi bi-briefcase"></i>
                View Job
            </a>

            <?php if ($canManage): ?>
                <a
                    href="visit-edit.php?id=<?= (int) $visitId; ?>"
                    class="vv-btn primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit Visit
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="vv-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="vv-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="vv-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="vv-overview">
        <article class="vv-stat">
            <div class="vv-stat-label">Visit Status</div>
            <div class="vv-stat-value">
                <span class="vv-status <?= e(
                    visitViewStatusClass(
                        $visit['status']
                    )
                ); ?>">
                    <?= e(
                        visitViewLabel(
                            $visit['status']
                        )
                    ); ?>
                </span>

                <?php if ($isOverdue): ?>
                    <span class="vv-overdue">
                        Overdue
                    </span>
                <?php endif; ?>
            </div>
        </article>

        <article class="vv-stat">
            <div class="vv-stat-label">Scheduled Start</div>
            <div class="vv-stat-value">
                <?= e(
                    visitViewDateTime(
                        $visit['scheduled_start']
                    )
                ); ?>
            </div>
        </article>

        <article class="vv-stat">
            <div class="vv-stat-label">Scheduled End</div>
            <div class="vv-stat-value">
                <?= e(
                    visitViewDateTime(
                        $visit['scheduled_end']
                    )
                ); ?>
            </div>
        </article>

        <article class="vv-stat">
            <div class="vv-stat-label">Planned Duration</div>
            <div class="vv-stat-value">
                <?= e($plannedDuration); ?>
            </div>
        </article>

        <article class="vv-stat">
            <div class="vv-stat-label">Actual Duration</div>
            <div class="vv-stat-value">
                <?= e($actualDuration); ?>
            </div>
        </article>

        <article class="vv-stat">
            <div class="vv-stat-label">Invoice</div>
            <div class="vv-stat-value">
                <?= !empty($visit['requires_invoice'])
                    ? 'Required'
                    : 'Not Required'; ?>
            </div>
        </article>
    </section>

    <div class="vv-grid">
        <main>
            <section class="vv-card">
                <div class="vv-card-head">
                    <div>
                        <h2>Visit Information</h2>
                        <p>
                            Schedule, actual timings, assignment, and job details.
                        </p>
                    </div>

                    <div>
                        <span class="vv-status <?= e(
                            visitViewStatusClass(
                                $visit['status']
                            )
                        ); ?>">
                            <?= e(
                                visitViewLabel(
                                    $visit['status']
                                )
                            ); ?>
                        </span>
                    </div>
                </div>

                <div class="vv-card-body">
                    <div class="vv-detail-grid">
                        <div class="vv-detail">
                            <span class="vv-detail-label">
                                Visit Number
                            </span>

                            <span class="vv-detail-value">
                                <?= e($visitNumber); ?>
                            </span>
                        </div>

                        <div class="vv-detail">
                            <span class="vv-detail-label">
                                Job
                            </span>

                            <span class="vv-detail-value">
                                <a
                                    href="job-view.php?id=<?= (int) $visit['job_id']; ?>"
                                >
                                    <?= e($visit['job_no']); ?>
                                    · <?= e($visit['job_title']); ?>
                                </a>
                            </span>
                        </div>

                        <div class="vv-detail">
                            <span class="vv-detail-label">
                                Scheduled Start
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    visitViewDateTime(
                                        $visit['scheduled_start']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="vv-detail">
                            <span class="vv-detail-label">
                                Scheduled End
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    visitViewDateTime(
                                        $visit['scheduled_end']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="vv-detail">
                            <span class="vv-detail-label">
                                Actual Start
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    visitViewDateTime(
                                        $visit['actual_start']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="vv-detail">
                            <span class="vv-detail-label">
                                Actual End
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    visitViewDateTime(
                                        $visit['actual_end']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="vv-detail">
                            <span class="vv-detail-label">
                                Created
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    visitViewDateTime(
                                        $visit['created_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="vv-detail">
                            <span class="vv-detail-label">
                                Last Updated
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    visitViewDateTime(
                                        $visit['updated_at']
                                    )
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="vv-card">
                <div class="vv-card-head">
                    <div>
                        <h2>Visit Instructions</h2>
                        <p>
                            Work instructions, access details, tools, or customer requests.
                        </p>
                    </div>
                </div>

                <div class="vv-card-body">
                    <?php if (
                        trim((string) $visit['instructions']) !== ''
                    ): ?>
                        <p class="vv-copy"><?= e(
                            $visit['instructions']
                        ); ?></p>
                    <?php else: ?>
                        <div class="vv-empty-copy">
                            No visit instructions were entered.
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="vv-card">
                <div class="vv-card-head">
                    <div>
                        <h2>Completion Notes</h2>
                        <p>
                            Work outcome, follow-up, review, or customer feedback.
                        </p>
                    </div>
                </div>

                <div class="vv-card-body">
                    <?php if (
                        trim(
                            (string) $visit['completion_notes']
                        ) !== ''
                    ): ?>
                        <p class="vv-copy"><?= e(
                            $visit['completion_notes']
                        ); ?></p>
                    <?php else: ?>
                        <div class="vv-empty-copy">
                            No completion notes were entered.
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="vv-card">
                <div class="vv-card-head">
                    <div>
                        <h2>Related Work Orders</h2>
                        <p>
                            Work orders created for the same job.
                        </p>
                    </div>
                </div>

                <div class="vv-card-body">
                    <?php if (!empty($workOrders)): ?>
                        <ul class="vv-list">
                            <?php foreach ($workOrders as $workOrder): ?>
                                <li class="vv-list-item">
                                    <div class="vv-list-main">
                                        <a
                                            href="work-order-view.php?id=<?= (int) $workOrder['id']; ?>"
                                            class="vv-list-title"
                                        >
                                            <?= e(
                                                $workOrder['work_order_no']
                                            ); ?>
                                            · <?= e($workOrder['title']); ?>
                                        </a>

                                        <div class="vv-list-sub">
                                            <?= e(
                                                visitViewDateTime(
                                                    $workOrder['scheduled_start']
                                                )
                                            ); ?>

                                            <?php if (
                                                !empty(
                                                    $workOrder['scheduled_end']
                                                )
                                            ): ?>
                                                to
                                                <?= e(
                                                    visitViewDateTime(
                                                        $workOrder['scheduled_end']
                                                    )
                                                ); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <span class="vv-list-tag">
                                        <?= e(
                                            visitViewLabel(
                                                $workOrder['status']
                                            )
                                        ); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="vv-empty-copy">
                            No work orders are linked to this job.
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="vv-card">
                <div class="vv-card-head">
                    <div>
                        <h2>Other Job Visits</h2>
                        <p>
                            Recent visits created for the same job.
                        </p>
                    </div>
                </div>

                <div class="vv-card-body">
                    <?php if (!empty($otherVisits)): ?>
                        <ul class="vv-list">
                            <?php foreach ($otherVisits as $otherVisit): ?>
                                <li class="vv-list-item">
                                    <div class="vv-list-main">
                                        <a
                                            href="visit-view.php?id=<?= (int) $otherVisit['id']; ?>"
                                            class="vv-list-title"
                                        >
                                            <?= e(
                                                trim(
                                                    (string) $otherVisit['visit_no']
                                                ) !== ''
                                                    ? $otherVisit['visit_no']
                                                    : 'Visit #' .
                                                        $otherVisit['id']
                                            ); ?>
                                        </a>

                                        <div class="vv-list-sub">
                                            <?= e(
                                                visitViewDateTime(
                                                    $otherVisit['scheduled_start']
                                                )
                                            ); ?>

                                            <?php if (
                                                !empty(
                                                    $otherVisit['scheduled_end']
                                                )
                                            ): ?>
                                                to
                                                <?= e(
                                                    visitViewDateTime(
                                                        $otherVisit['scheduled_end']
                                                    )
                                                ); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <span class="vv-list-tag">
                                        <?= e(
                                            visitViewLabel(
                                                $otherVisit['status']
                                            )
                                        ); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="vv-empty-copy">
                            No other visits are available for this job.
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <aside>
            <section class="vv-card">
                <div class="vv-card-head">
                    <div>
                        <h2>Client</h2>
                        <p>Customer contact information.</p>
                    </div>
                </div>

                <div class="vv-card-body">
                    <div class="vv-detail-grid">
                        <div class="vv-detail full">
                            <span class="vv-detail-label">
                                Client Name
                            </span>

                            <span class="vv-detail-value">
                                <a
                                    href="client-view.php?id=<?= (int) $visit['client_id']; ?>"
                                >
                                    <?= e($visit['client_name']); ?>
                                </a>
                            </span>
                        </div>

                        <div class="vv-detail full">
                            <span class="vv-detail-label">
                                Phone
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    trim(
                                        (string) $visit['client_phone']
                                    ) !== ''
                                        ? $visit['client_phone']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="vv-detail full">
                            <span class="vv-detail-label">
                                Email
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    trim(
                                        (string) $visit['client_email']
                                    ) !== ''
                                        ? $visit['client_email']
                                        : '—'
                                ); ?>
                            </span>
                        </div>
                    </div>

                    <div class="vv-contact-actions no-print">
                        <?php if (
                            trim(
                                (string) $visit['client_phone']
                            ) !== ''
                        ): ?>
                            <a
                                href="tel:<?= e(
                                    preg_replace(
                                        '/[^0-9+]/',
                                        '',
                                        $visit['client_phone']
                                    )
                                ); ?>"
                                class="vv-contact-link"
                            >
                                <i class="bi bi-telephone"></i>
                                Call
                            </a>
                        <?php endif; ?>

                        <?php if (
                            trim(
                                (string) $visit['client_email']
                            ) !== ''
                        ): ?>
                            <a
                                href="mailto:<?= e(
                                    $visit['client_email']
                                ); ?>"
                                class="vv-contact-link"
                            >
                                <i class="bi bi-envelope"></i>
                                Email
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="vv-card">
                <div class="vv-card-head">
                    <div>
                        <h2>Property</h2>
                        <p>Visit location and address.</p>
                    </div>
                </div>

                <div class="vv-card-body">
                    <div class="vv-detail-grid">
                        <div class="vv-detail full">
                            <span class="vv-detail-label">
                                Property
                            </span>

                            <span class="vv-detail-value">
                                <?php if (
                                    !empty($visit['property_id'])
                                ): ?>
                                    <a
                                        href="property-view.php?id=<?= (int) $visit['property_id']; ?>"
                                    >
                                        <?= e($propertyName); ?>
                                    </a>
                                <?php else: ?>
                                    <?= e($propertyName); ?>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="vv-detail full">
                            <span class="vv-detail-label">
                                Address
                            </span>

                            <span class="vv-detail-value">
                                <?= e($propertyAddress); ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($mapQuery !== ''): ?>
                        <div class="vv-contact-actions no-print">
                            <a
                                href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($mapQuery); ?>"
                                target="_blank"
                                rel="noopener"
                                class="vv-contact-link"
                            >
                                <i class="bi bi-geo-alt"></i>
                                Open Map
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="vv-card">
                <div class="vv-card-head">
                    <div>
                        <h2>Assigned Worker</h2>
                        <p>Worker responsible for this visit.</p>
                    </div>
                </div>

                <div class="vv-card-body">
                    <div class="vv-detail-grid">
                        <div class="vv-detail full">
                            <span class="vv-detail-label">
                                Worker
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    $workerName !== ''
                                        ? $workerName
                                        : 'Unassigned'
                                ); ?>
                            </span>
                        </div>

                        <div class="vv-detail full">
                            <span class="vv-detail-label">
                                Job Title
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    trim(
                                        (string) $visit['assigned_user_job_title']
                                    ) !== ''
                                        ? $visit['assigned_user_job_title']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="vv-detail full">
                            <span class="vv-detail-label">
                                Phone
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    trim(
                                        (string) $visit['assigned_user_phone']
                                    ) !== ''
                                        ? $visit['assigned_user_phone']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="vv-detail full">
                            <span class="vv-detail-label">
                                Email
                            </span>

                            <span class="vv-detail-value">
                                <?= e(
                                    trim(
                                        (string) $visit['assigned_user_email']
                                    ) !== ''
                                        ? $visit['assigned_user_email']
                                        : '—'
                                ); ?>
                            </span>
                        </div>
                    </div>

                    <div class="vv-contact-actions no-print">
                        <?php if (
                            trim(
                                (string) $visit['assigned_user_phone']
                            ) !== ''
                        ): ?>
                            <a
                                href="tel:<?= e(
                                    preg_replace(
                                        '/[^0-9+]/',
                                        '',
                                        $visit['assigned_user_phone']
                                    )
                                ); ?>"
                                class="vv-contact-link"
                            >
                                <i class="bi bi-telephone"></i>
                                Call Worker
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php if (!empty($visit['requires_invoice'])): ?>
                <section class="vv-card">
                    <div class="vv-card-body">
                        <div class="vv-invoice-box">
                            <div class="vv-invoice-title">
                                <i class="bi bi-receipt"></i>
                                Invoice Required
                            </div>

                            <div class="vv-invoice-text">
                                This visit is marked for invoicing follow-up.
                                The job invoicing preference is
                                <?= e(
                                    visitViewLabel(
                                        $visit['invoicing_preference']
                                    )
                                ); ?>.
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <section class="vv-card no-print">
                    <div class="vv-card-head">
                        <div>
                            <h2>Visit Workflow</h2>
                            <p>
                                Update the current visit status.
                            </p>
                        </div>
                    </div>

                    <div class="vv-card-body">
                        <div class="vv-workflow">
                            <?php
                            $workflowActions = array();

                            switch ($visit['status']) {
                                case 'draft':
                                    $workflowActions = array(
                                        'scheduled' => 'Schedule Visit',
                                        'cancelled' => 'Cancel Visit'
                                    );
                                    break;

                                case 'scheduled':
                                    $workflowActions = array(
                                        'dispatched' => 'Dispatch Worker',
                                        'missed' => 'Mark Missed',
                                        'cancelled' => 'Cancel Visit'
                                    );
                                    break;

                                case 'dispatched':
                                    $workflowActions = array(
                                        'on_my_way' => 'Mark On My Way',
                                        'in_progress' => 'Start Visit',
                                        'missed' => 'Mark Missed',
                                        'cancelled' => 'Cancel Visit'
                                    );
                                    break;

                                case 'on_my_way':
                                    $workflowActions = array(
                                        'in_progress' => 'Start Visit',
                                        'missed' => 'Mark Missed',
                                        'cancelled' => 'Cancel Visit'
                                    );
                                    break;

                                case 'in_progress':
                                    $workflowActions = array(
                                        'completed' => 'Complete Visit',
                                        'needs_review' => 'Send for Review',
                                        'cancelled' => 'Cancel Visit'
                                    );
                                    break;

                                case 'needs_review':
                                    $workflowActions = array(
                                        'completed' => 'Approve and Complete',
                                        'in_progress' => 'Return to In Progress'
                                    );
                                    break;

                                case 'missed':
                                    $workflowActions = array(
                                        'scheduled' => 'Reschedule Visit',
                                        'cancelled' => 'Cancel Visit'
                                    );
                                    break;

                                case 'cancelled':
                                    $workflowActions = array(
                                        'scheduled' => 'Reopen and Schedule'
                                    );
                                    break;

                                case 'completed':
                                    $workflowActions = array(
                                        'needs_review' => 'Reopen for Review'
                                    );
                                    break;
                            }

                            foreach (
                                $workflowActions as
                                $statusValue => $actionLabel
                            ):
                                $buttonClass = '';

                                if (
                                    in_array(
                                        $statusValue,
                                        array(
                                            'completed',
                                            'in_progress',
                                            'scheduled'
                                        ),
                                        true
                                    )
                                ) {
                                    $buttonClass = 'primary';
                                } elseif (
                                    in_array(
                                        $statusValue,
                                        array(
                                            'cancelled',
                                            'missed'
                                        ),
                                        true
                                    )
                                ) {
                                    $buttonClass = 'danger';
                                }
                            ?>
                                <form
                                    method="post"
                                    action=""
                                    class="vv-status-form"
                                    onsubmit="return confirm('Change visit status to <?= e(
                                        visitViewLabel(
                                            $statusValue
                                        )
                                    ); ?>?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($csrfToken); ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $visitId; ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="update_status"
                                    >

                                    <input
                                        type="hidden"
                                        name="new_status"
                                        value="<?= e($statusValue); ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="vv-status-action <?= e(
                                            $buttonClass
                                        ); ?>"
                                    >
                                        <?= e($actionLabel); ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>

                            <?php if (empty($workflowActions)): ?>
                                <div class="vv-empty-copy">
                                    No workflow actions are available.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="vv-card">
                <div class="vv-card-head">
                    <div>
                        <h2>Activity History</h2>
                        <p>
                            Recent actions recorded for this visit.
                        </p>
                    </div>
                </div>

                <div class="vv-card-body">
                    <?php if (!empty($activities)): ?>
                        <div class="vv-activity">
                            <?php foreach ($activities as $activity): ?>
                                <div class="vv-activity-item">
                                    <span class="vv-activity-dot"></span>

                                    <div class="vv-activity-title">
                                        <?= e($activity['title']); ?>
                                    </div>

                                    <div class="vv-activity-meta">
                                        <?= e(
                                            trim(
                                                (string) $activity['actor_name']
                                            ) !== ''
                                                ? $activity['actor_name']
                                                : 'System'
                                        ); ?>
                                        ·
                                        <?= e(
                                            visitViewDateTime(
                                                $activity['created_at']
                                            )
                                        ); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="vv-empty-copy">
                            No visit activity is available.
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
