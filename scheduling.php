<?php
/**
 * FieldPlx - Scheduling Calendar
 *
 * Upload as:
 * /public_html/scheduling.php
 *
 * Features:
 * - Month / week / day / list calendar views
 * - Worker, event type and status filters
 * - Create custom schedule events
 * - Edit and delete custom events
 * - Drag/drop and resize rescheduling
 * - Worker conflict validation
 * - Reschedule history
 * - Linked visit, booking, assessment, work-order and task date sync
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
        rawurlencode('scheduling.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'schedule.view',
        'You do not have permission to view the schedule.'
    );
}

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$canManage = function_exists('hasPermission')
    ? hasPermission('schedule.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('scheduleFetchAssoc')) {
    function scheduleFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('scheduleFetchAll')) {
    function scheduleFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('scheduleBindParams')) {
    function scheduleBindParams(
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

if (!function_exists('scheduleJson')) {
    function scheduleJson(
        $success,
        $message = '',
        array $data = array(),
        $statusCode = 200
    ) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            array_merge(
                array(
                    'success' => (bool) $success,
                    'message' => (string) $message
                ),
                $data
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}

if (!function_exists('scheduleNullable')) {
    function scheduleNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('scheduleNormalizeDateTime')) {
    function scheduleNormalizeDateTime($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('scheduleCsrfToken')) {
    function scheduleCsrfToken()
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

if (!function_exists('scheduleVerifyCsrf')) {
    function scheduleVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('scheduleColor')) {
    function scheduleColor($eventType, $status, $savedColor)
    {
        $savedColor = trim((string) $savedColor);

        if (
            $savedColor !== '' &&
            preg_match(
                '/^#[0-9A-Fa-f]{6}$/',
                $savedColor
            )
        ) {
            return $savedColor;
        }

        if ($status === 'completed') {
            return '#059669';
        }

        if ($status === 'cancelled') {
            return '#dc2626';
        }

        if ($status === 'missed') {
            return '#b91c1c';
        }

        if ($status === 'rescheduled') {
            return '#d97706';
        }

        $colors = array(
            'visit' => '#2563eb',
            'assessment' => '#7c3aed',
            'task' => '#ea580c',
            'event' => '#475569',
            'booking' => '#0891b2'
        );

        return isset($colors[$eventType])
            ? $colors[$eventType]
            : '#6d28d9';
    }
}

if (!function_exists('scheduleLinkedUrl')) {
    function scheduleLinkedUrl($eventType, $relatedType, $relatedId)
    {
        $relatedId = (int) $relatedId;

        if ($relatedId <= 0) {
            return '';
        }

        if (
            $relatedType === 'work_order' ||
            $relatedType === 'work-order'
        ) {
            return 'work-order-view.php?id=' . $relatedId;
        }

        if (
            $relatedType === 'visit' ||
            $eventType === 'visit'
        ) {
            return 'visit-view.php?id=' . $relatedId;
        }

        if (
            $relatedType === 'booking' ||
            $eventType === 'booking'
        ) {
            return 'booking-view.php?id=' . $relatedId;
        }

        if (
            $relatedType === 'assessment' ||
            $eventType === 'assessment'
        ) {
            return 'assessment-view.php?id=' . $relatedId;
        }

        if (
            $relatedType === 'task' ||
            $eventType === 'task'
        ) {
            return 'task-view.php?id=' . $relatedId;
        }

        return '';
    }
}

if (!function_exists('scheduleLoadEvent')) {
    function scheduleLoadEvent(
        mysqli $conn,
        $eventId,
        $tenantId,
        $lock = false
    ) {
        $sql = "
            SELECT
                se.id,
                se.tenant_id,
                se.event_type,
                se.related_type,
                se.related_id,
                se.title,
                se.description,
                se.assigned_user_id,
                se.client_id,
                se.property_id,
                se.start_at,
                se.end_at,
                se.all_day,
                se.status,
                se.color,
                se.created_by,
                se.created_at,
                se.updated_at
            FROM schedule_events se
            WHERE se.id = ?
              AND se.tenant_id = ?
            LIMIT 1
        ";

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param(
            'ii',
            $eventId,
            $tenantId
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $event = scheduleFetchAssoc($stmt);
        $stmt->close();

        return $event;
    }
}

if (!function_exists('scheduleValidateWorker')) {
    function scheduleValidateWorker(
        mysqli $conn,
        $tenantId,
        $workerId
    ) {
        if ($workerId === null) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND tenant_id = ?
              AND status = 'active'
              AND deleted_at IS NULL
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ii',
            $workerId,
            $tenantId
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('scheduleValidateClient')) {
    function scheduleValidateClient(
        mysqli $conn,
        $tenantId,
        $clientId
    ) {
        if ($clientId === null) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM clients
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ii',
            $clientId,
            $tenantId
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('scheduleValidateProperty')) {
    function scheduleValidateProperty(
        mysqli $conn,
        $tenantId,
        $propertyId,
        $clientId
    ) {
        if ($propertyId === null) {
            return true;
        }

        $sql = "
            SELECT id
            FROM properties
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
        ";

        $params = array(
            $propertyId,
            $tenantId
        );

        $types = 'ii';

        if ($clientId !== null) {
            $sql .= ' AND client_id = ?';
            $params[] = $clientId;
            $types .= 'i';
        }

        $sql .= ' LIMIT 1';

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        if (
            !scheduleBindParams(
                $stmt,
                $types,
                $params
            )
        ) {
            $stmt->close();
            return false;
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('scheduleFindConflict')) {
    function scheduleFindConflict(
        mysqli $conn,
        $tenantId,
        $workerId,
        $startAt,
        $endAt,
        $excludeEventId = 0
    ) {
        if (
            $workerId === null ||
            $startAt === null ||
            $endAt === null
        ) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT
                id,
                title,
                start_at,
                end_at
            FROM schedule_events
            WHERE tenant_id = ?
              AND assigned_user_id = ?
              AND id <> ?
              AND status NOT IN (
                  'completed',
                  'cancelled',
                  'missed'
              )
              AND start_at < ?
              AND end_at > ?
            ORDER BY start_at ASC
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param(
            'iiiss',
            $tenantId,
            $workerId,
            $excludeEventId,
            $endAt,
            $startAt
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $conflict = scheduleFetchAssoc($stmt);
        $stmt->close();

        return $conflict;
    }
}

if (!function_exists('scheduleInsertHistory')) {
    function scheduleInsertHistory(
        mysqli $conn,
        $tenantId,
        $eventId,
        $oldWorkerId,
        $newWorkerId,
        $oldStartAt,
        $oldEndAt,
        $newStartAt,
        $newEndAt,
        $reason,
        $changedBy
    ) {
        $stmt = $conn->prepare("
            INSERT INTO schedule_reschedule_history (
                tenant_id,
                schedule_event_id,
                old_assigned_user_id,
                new_assigned_user_id,
                old_start_at,
                old_end_at,
                new_start_at,
                new_end_at,
                reason,
                notification_sent,
                changed_by,
                created_at
            ) VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                0,
                ?,
                NOW()
            )
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to prepare reschedule history: ' .
                $conn->error
            );
        }

        $stmt->bind_param(
            'iiiisssssi',
            $tenantId,
            $eventId,
            $oldWorkerId,
            $newWorkerId,
            $oldStartAt,
            $oldEndAt,
            $newStartAt,
            $newEndAt,
            $reason,
            $changedBy
        );

        if (!$stmt->execute()) {
            throw new Exception(
                'Unable to save reschedule history: ' .
                $stmt->error
            );
        }

        $stmt->close();
    }
}

if (!function_exists('scheduleSyncLinkedRecord')) {
    function scheduleSyncLinkedRecord(
        mysqli $conn,
        array $event,
        $workerId,
        $startAt,
        $endAt
    ) {
        $tenantId = (int) $event['tenant_id'];
        $relatedId = (int) $event['related_id'];
        $relatedType = trim(
            (string) $event['related_type']
        );

        if ($relatedId <= 0) {
            return;
        }

        if (
            $relatedType === 'visit' ||
            $event['event_type'] === 'visit'
        ) {
            $stmt = $conn->prepare("
                UPDATE visits
                SET
                    assigned_user_id = ?,
                    scheduled_start = ?,
                    scheduled_end = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'issii',
                    $workerId,
                    $startAt,
                    $endAt,
                    $relatedId,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to sync the linked visit: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            return;
        }

        if (
            $relatedType === 'booking' ||
            $event['event_type'] === 'booking'
        ) {
            $stmt = $conn->prepare("
                UPDATE bookings
                SET
                    assigned_user_id = ?,
                    scheduled_start = ?,
                    scheduled_end = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'issii',
                    $workerId,
                    $startAt,
                    $endAt,
                    $relatedId,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to sync the linked booking: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            return;
        }

        if (
            $relatedType === 'assessment' ||
            $event['event_type'] === 'assessment'
        ) {
            $stmt = $conn->prepare("
                UPDATE assessments
                SET
                    assigned_user_id = ?,
                    scheduled_start = ?,
                    scheduled_end = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'issii',
                    $workerId,
                    $startAt,
                    $endAt,
                    $relatedId,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to sync the linked assessment: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            return;
        }

        if (
            $relatedType === 'work_order' ||
            $relatedType === 'work-order'
        ) {
            $stmt = $conn->prepare("
                UPDATE work_orders
                SET
                    scheduled_start = ?,
                    scheduled_end = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                  AND deleted_at IS NULL
                LIMIT 1
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'ssii',
                    $startAt,
                    $endAt,
                    $relatedId,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to sync the linked work order: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            return;
        }

        if (
            $relatedType === 'task' ||
            $event['event_type'] === 'task'
        ) {
            $stmt = $conn->prepare("
                UPDATE tasks
                SET
                    assigned_user_id = ?,
                    due_at = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if ($stmt) {
                $stmt->bind_param(
                    'isii',
                    $workerId,
                    $endAt,
                    $relatedId,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to sync the linked task: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Correct activity logger
|--------------------------------------------------------------------------
|
| The helper above is intentionally not called because PHP bind type strings
| cannot contain spaces. This separate helper is used by the actions below.
|
*/

if (!function_exists('scheduleWriteActivity')) {
    function scheduleWriteActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $eventId,
        $clientId,
        $eventType,
        $title,
        array $details
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
                ?,
                'schedule_event',
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

        $detailsJson = json_encode(
            $details,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iisiiss',
            $tenantId,
            $userId,
            $eventType,
            $eventId,
            $clientId,
            $title,
            $detailsJson
        );

        $stmt->execute();
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| AJAX event feed
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['ajax']) &&
    $_GET['ajax'] === 'events'
) {
    $start = scheduleNormalizeDateTime(
        isset($_GET['start'])
            ? $_GET['start']
            : ''
    );

    $end = scheduleNormalizeDateTime(
        isset($_GET['end'])
            ? $_GET['end']
            : ''
    );

    if ($start === null || $end === null) {
        scheduleJson(
            false,
            'Invalid calendar date range.',
            array(),
            422
        );
    }

    $workerId = isset($_GET['worker_id'])
        ? (int) $_GET['worker_id']
        : 0;

    $eventType = isset($_GET['event_type'])
        ? trim((string) $_GET['event_type'])
        : '';

    $status = isset($_GET['status'])
        ? trim((string) $_GET['status'])
        : '';

    $allowedTypes = array(
        '',
        'visit',
        'assessment',
        'task',
        'event',
        'booking'
    );

    $allowedStatuses = array(
        '',
        'scheduled',
        'completed',
        'cancelled',
        'rescheduled',
        'missed'
    );

    if (!in_array($eventType, $allowedTypes, true)) {
        $eventType = '';
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $status = '';
    }

    $where = array(
        'se.tenant_id = ?',
        'se.start_at < ?',
        'se.end_at > ?'
    );

    $params = array(
        $tenantId,
        $end,
        $start
    );

    $types = 'iss';

    if ($workerId > 0) {
        $where[] = 'se.assigned_user_id = ?';
        $params[] = $workerId;
        $types .= 'i';
    }

    if ($eventType !== '') {
        $where[] = 'se.event_type = ?';
        $params[] = $eventType;
        $types .= 's';
    }

    if ($status !== '') {
        $where[] = 'se.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    $sql = "
        SELECT
            se.id,
            se.event_type,
            se.related_type,
            se.related_id,
            se.title,
            se.description,
            se.assigned_user_id,
            se.client_id,
            se.property_id,
            se.start_at,
            se.end_at,
            se.all_day,
            se.status,
            se.color,

            CONCAT(
                COALESCE(u.first_name, ''),
                CASE
                    WHEN u.last_name IS NOT NULL
                     AND u.last_name <> ''
                    THEN CONCAT(' ', u.last_name)
                    ELSE ''
                END
            ) AS worker_name,

            u.color_code AS worker_color,

            c.display_name AS client_name,

            p.name AS property_name,
            p.address_line1 AS property_address,
            p.city AS property_city

        FROM schedule_events se

        LEFT JOIN users u
            ON u.id = se.assigned_user_id
           AND u.tenant_id = se.tenant_id
           AND u.deleted_at IS NULL

        LEFT JOIN clients c
            ON c.id = se.client_id
           AND c.tenant_id = se.tenant_id
           AND c.deleted_at IS NULL

        LEFT JOIN properties p
            ON p.id = se.property_id
           AND p.tenant_id = se.tenant_id
           AND p.deleted_at IS NULL

        WHERE " . implode(' AND ', $where) . "

        ORDER BY se.start_at ASC, se.id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        scheduleJson(
            false,
            'Unable to prepare the schedule query: ' .
            $conn->error,
            array(),
            500
        );
    }

    if (
        !scheduleBindParams(
            $stmt,
            $types,
            $params
        )
    ) {
        scheduleJson(
            false,
            'Unable to bind schedule filters.',
            array(),
            500
        );
    }

    if (!$stmt->execute()) {
        scheduleJson(
            false,
            'Unable to load schedule events: ' .
            $stmt->error,
            array(),
            500
        );
    }

    $rows = scheduleFetchAll($stmt);
    $stmt->close();

    $events = array();

    foreach ($rows as $row) {
        $savedColor = trim(
            (string) $row['color']
        );

        $workerColor = trim(
            (string) $row['worker_color']
        );

        if (
            $savedColor === '' &&
            preg_match(
                '/^#[0-9A-Fa-f]{6}$/',
                $workerColor
            )
        ) {
            $savedColor = $workerColor;
        }

        $eventColor = scheduleColor(
            $row['event_type'],
            $row['status'],
            $savedColor
        );

        $linkedUrl = scheduleLinkedUrl(
            $row['event_type'],
            $row['related_type'],
            $row['related_id']
        );

        $isCustomEvent =
            $row['event_type'] === 'event' &&
            (
                empty($row['related_type']) ||
                $row['related_type'] === 'event'
            );

        $events[] = array(
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'start' => date(
                DATE_ATOM,
                strtotime($row['start_at'])
            ),
            'end' => date(
                DATE_ATOM,
                strtotime($row['end_at'])
            ),
            'allDay' => !empty($row['all_day']),
            'backgroundColor' => $eventColor,
            'borderColor' => $eventColor,
            'textColor' => '#ffffff',
            'editable' =>
                $canManage &&
                !in_array(
                    $row['status'],
                    array(
                        'completed',
                        'cancelled',
                        'missed'
                    ),
                    true
                ),
            'extendedProps' => array(
                'eventType' =>
                    (string) $row['event_type'],
                'relatedType' =>
                    (string) $row['related_type'],
                'relatedId' =>
                    !empty($row['related_id'])
                        ? (int) $row['related_id']
                        : null,
                'description' =>
                    (string) $row['description'],
                'assignedUserId' =>
                    !empty($row['assigned_user_id'])
                        ? (int) $row['assigned_user_id']
                        : null,
                'workerName' =>
                    trim((string) $row['worker_name']),
                'clientId' =>
                    !empty($row['client_id'])
                        ? (int) $row['client_id']
                        : null,
                'clientName' =>
                    (string) $row['client_name'],
                'propertyId' =>
                    !empty($row['property_id'])
                        ? (int) $row['property_id']
                        : null,
                'propertyName' =>
                    (string) $row['property_name'],
                'propertyAddress' =>
                    trim(
                        implode(
                            ', ',
                            array_filter(
                                array(
                                    $row['property_address'],
                                    $row['property_city']
                                ),
                                function ($value) {
                                    return trim(
                                        (string) $value
                                    ) !== '';
                                }
                            )
                        )
                    ),
                'status' =>
                    (string) $row['status'],
                'color' =>
                    $eventColor,
                'linkedUrl' =>
                    $linkedUrl,
                'isCustomEvent' =>
                    $isCustomEvent
            )
        );
    }

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        $events,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| AJAX action handlers
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['ajax_action'])
) {
    if (!$canManage) {
        scheduleJson(
            false,
            'You do not have permission to manage the schedule.',
            array(),
            403
        );
    }

    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!scheduleVerifyCsrf($csrfToken)) {
        scheduleJson(
            false,
            'Your session token is invalid. Refresh the page and try again.',
            array(),
            419
        );
    }

    $action = trim(
        (string) $_POST['ajax_action']
    );

    /*
    |--------------------------------------------------------------------------
    | Create custom event
    |--------------------------------------------------------------------------
    */

    if ($action === 'create_event') {
        $title = isset($_POST['title'])
            ? trim((string) $_POST['title'])
            : '';

        $description = isset($_POST['description'])
            ? trim((string) $_POST['description'])
            : '';

        $workerId =
            !empty($_POST['assigned_user_id'])
                ? (int) $_POST['assigned_user_id']
                : null;

        $clientId =
            !empty($_POST['client_id'])
                ? (int) $_POST['client_id']
                : null;

        $propertyId =
            !empty($_POST['property_id'])
                ? (int) $_POST['property_id']
                : null;

        $startAt = scheduleNormalizeDateTime(
            isset($_POST['start_at'])
                ? $_POST['start_at']
                : ''
        );

        $endAt = scheduleNormalizeDateTime(
            isset($_POST['end_at'])
                ? $_POST['end_at']
                : ''
        );

        $allDay =
            !empty($_POST['all_day'])
                ? 1
                : 0;

        $status = isset($_POST['status'])
            ? trim((string) $_POST['status'])
            : 'scheduled';

        $color = isset($_POST['color'])
            ? trim((string) $_POST['color'])
            : '#475569';

        $allowedStatuses = array(
            'scheduled',
            'completed',
            'cancelled',
            'rescheduled',
            'missed'
        );

        if ($title === '') {
            scheduleJson(
                false,
                'Event title is required.',
                array(),
                422
            );
        }

        if (strlen($title) > 190) {
            scheduleJson(
                false,
                'Event title cannot exceed 190 characters.',
                array(),
                422
            );
        }

        if ($startAt === null || $endAt === null) {
            scheduleJson(
                false,
                'Start and end date/time are required.',
                array(),
                422
            );
        }

        if (
            strtotime($endAt) <=
            strtotime($startAt)
        ) {
            scheduleJson(
                false,
                'End date/time must be after start date/time.',
                array(),
                422
            );
        }

        if (
            !in_array(
                $status,
                $allowedStatuses,
                true
            )
        ) {
            scheduleJson(
                false,
                'Invalid event status.',
                array(),
                422
            );
        }

        if (
            !preg_match(
                '/^#[0-9A-Fa-f]{6}$/',
                $color
            )
        ) {
            $color = '#475569';
        }

        if (
            !scheduleValidateWorker(
                $conn,
                $tenantId,
                $workerId
            )
        ) {
            scheduleJson(
                false,
                'The selected worker is not available.',
                array(),
                422
            );
        }

        if (
            !scheduleValidateClient(
                $conn,
                $tenantId,
                $clientId
            )
        ) {
            scheduleJson(
                false,
                'The selected client is not available.',
                array(),
                422
            );
        }

        if (
            !scheduleValidateProperty(
                $conn,
                $tenantId,
                $propertyId,
                $clientId
            )
        ) {
            scheduleJson(
                false,
                'The selected property is not available for this client.',
                array(),
                422
            );
        }

        $conflict = scheduleFindConflict(
            $conn,
            $tenantId,
            $workerId,
            $startAt,
            $endAt,
            0
        );

        if ($conflict) {
            scheduleJson(
                false,
                'This worker already has "' .
                $conflict['title'] .
                '" during the selected time.',
                array(
                    'conflict' => $conflict
                ),
                409
            );
        }

        $descriptionValue =
            scheduleNullable($description);

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                INSERT INTO schedule_events (
                    tenant_id,
                    event_type,
                    related_type,
                    related_id,
                    title,
                    description,
                    assigned_user_id,
                    client_id,
                    property_id,
                    start_at,
                    end_at,
                    all_day,
                    status,
                    color,
                    created_by,
                    created_at,
                    updated_at
                ) VALUES (
                    ?,
                    'event',
                    'event',
                    NULL,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare event creation: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'issiiississsi',
                $tenantId,
                $title,
                $descriptionValue,
                $workerId,
                $clientId,
                $propertyId,
                $startAt,
                $endAt,
                $allDay,
                $status,
                $color,
                $currentUserId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Event could not be created: ' .
                    $stmt->error
                );
            }

            $eventId = (int) $stmt->insert_id;
            $stmt->close();

            $conn->commit();

            scheduleWriteActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $eventId,
                $clientId,
                'schedule_event_created',
                'Schedule event created: ' . $title,
                array(
                    'event_id' => $eventId,
                    'title' => $title,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'assigned_user_id' => $workerId
                )
            );

            scheduleJson(
                true,
                'Schedule event created successfully.',
                array(
                    'event_id' => $eventId
                )
            );
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            scheduleJson(
                false,
                $error->getMessage(),
                array(),
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update event details
    |--------------------------------------------------------------------------
    */

    if ($action === 'update_event') {
        $eventId = isset($_POST['event_id'])
            ? (int) $_POST['event_id']
            : 0;

        if ($eventId <= 0) {
            scheduleJson(
                false,
                'Invalid schedule event.',
                array(),
                422
            );
        }

        $title = isset($_POST['title'])
            ? trim((string) $_POST['title'])
            : '';

        $description = isset($_POST['description'])
            ? trim((string) $_POST['description'])
            : '';

        $workerId =
            !empty($_POST['assigned_user_id'])
                ? (int) $_POST['assigned_user_id']
                : null;

        $clientId =
            !empty($_POST['client_id'])
                ? (int) $_POST['client_id']
                : null;

        $propertyId =
            !empty($_POST['property_id'])
                ? (int) $_POST['property_id']
                : null;

        $startAt = scheduleNormalizeDateTime(
            isset($_POST['start_at'])
                ? $_POST['start_at']
                : ''
        );

        $endAt = scheduleNormalizeDateTime(
            isset($_POST['end_at'])
                ? $_POST['end_at']
                : ''
        );

        $allDay =
            !empty($_POST['all_day'])
                ? 1
                : 0;

        $status = isset($_POST['status'])
            ? trim((string) $_POST['status'])
            : 'scheduled';

        $color = isset($_POST['color'])
            ? trim((string) $_POST['color'])
            : '#475569';

        $rescheduleReason = isset($_POST['reason'])
            ? trim((string) $_POST['reason'])
            : 'Schedule event updated';

        $allowedStatuses = array(
            'scheduled',
            'completed',
            'cancelled',
            'rescheduled',
            'missed'
        );

        if ($title === '') {
            scheduleJson(
                false,
                'Event title is required.',
                array(),
                422
            );
        }

        if (strlen($title) > 190) {
            scheduleJson(
                false,
                'Event title cannot exceed 190 characters.',
                array(),
                422
            );
        }

        if ($startAt === null || $endAt === null) {
            scheduleJson(
                false,
                'Start and end date/time are required.',
                array(),
                422
            );
        }

        if (
            strtotime($endAt) <=
            strtotime($startAt)
        ) {
            scheduleJson(
                false,
                'End date/time must be after start date/time.',
                array(),
                422
            );
        }

        if (
            !in_array(
                $status,
                $allowedStatuses,
                true
            )
        ) {
            scheduleJson(
                false,
                'Invalid event status.',
                array(),
                422
            );
        }

        if (
            !preg_match(
                '/^#[0-9A-Fa-f]{6}$/',
                $color
            )
        ) {
            $color = '#475569';
        }

        if (
            !scheduleValidateWorker(
                $conn,
                $tenantId,
                $workerId
            )
        ) {
            scheduleJson(
                false,
                'The selected worker is not available.',
                array(),
                422
            );
        }

        if (
            !scheduleValidateClient(
                $conn,
                $tenantId,
                $clientId
            )
        ) {
            scheduleJson(
                false,
                'The selected client is not available.',
                array(),
                422
            );
        }

        if (
            !scheduleValidateProperty(
                $conn,
                $tenantId,
                $propertyId,
                $clientId
            )
        ) {
            scheduleJson(
                false,
                'The selected property is not available for this client.',
                array(),
                422
            );
        }

        $conflict = scheduleFindConflict(
            $conn,
            $tenantId,
            $workerId,
            $startAt,
            $endAt,
            $eventId
        );

        if ($conflict) {
            scheduleJson(
                false,
                'This worker already has "' .
                $conflict['title'] .
                '" during the selected time.',
                array(
                    'conflict' => $conflict
                ),
                409
            );
        }

        try {
            $conn->begin_transaction();

            $event = scheduleLoadEvent(
                $conn,
                $eventId,
                $tenantId,
                true
            );

            if (!$event) {
                throw new Exception(
                    'Schedule event was not found.'
                );
            }

            $scheduleChanged =
                (string) $event['start_at'] !==
                    (string) $startAt ||
                (string) $event['end_at'] !==
                    (string) $endAt ||
                (int) $event['assigned_user_id'] !==
                    (int) $workerId;

            if ($scheduleChanged) {
                scheduleInsertHistory(
                    $conn,
                    $tenantId,
                    $eventId,
                    !empty($event['assigned_user_id'])
                        ? (int) $event['assigned_user_id']
                        : null,
                    $workerId,
                    $event['start_at'],
                    $event['end_at'],
                    $startAt,
                    $endAt,
                    $rescheduleReason !== ''
                        ? $rescheduleReason
                        : 'Schedule event updated',
                    $currentUserId
                );
            }

            $descriptionValue =
                scheduleNullable($description);

            $stmt = $conn->prepare("
                UPDATE schedule_events
                SET
                    title = ?,
                    description = ?,
                    assigned_user_id = ?,
                    client_id = ?,
                    property_id = ?,
                    start_at = ?,
                    end_at = ?,
                    all_day = ?,
                    status = ?,
                    color = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the event update: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'ssiiississii',
                $title,
                $descriptionValue,
                $workerId,
                $clientId,
                $propertyId,
                $startAt,
                $endAt,
                $allDay,
                $status,
                $color,
                $eventId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Schedule event could not be updated: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            scheduleSyncLinkedRecord(
                $conn,
                $event,
                $workerId,
                $startAt,
                $endAt
            );

            $conn->commit();

            scheduleWriteActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $eventId,
                $clientId,
                'schedule_event_updated',
                'Schedule event updated: ' . $title,
                array(
                    'event_id' => $eventId,
                    'old_start_at' => $event['start_at'],
                    'old_end_at' => $event['end_at'],
                    'new_start_at' => $startAt,
                    'new_end_at' => $endAt,
                    'old_assigned_user_id' =>
                        $event['assigned_user_id'],
                    'new_assigned_user_id' =>
                        $workerId,
                    'status' => $status
                )
            );

            scheduleJson(
                true,
                'Schedule event updated successfully.'
            );
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            scheduleJson(
                false,
                $error->getMessage(),
                array(),
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Drag/drop or resize event
    |--------------------------------------------------------------------------
    */

    if ($action === 'move_event') {
        $eventId = isset($_POST['event_id'])
            ? (int) $_POST['event_id']
            : 0;

        $startAt = scheduleNormalizeDateTime(
            isset($_POST['start_at'])
                ? $_POST['start_at']
                : ''
        );

        $endAt = scheduleNormalizeDateTime(
            isset($_POST['end_at'])
                ? $_POST['end_at']
                : ''
        );

        $allDay =
            !empty($_POST['all_day'])
                ? 1
                : 0;

        if (
            $eventId <= 0 ||
            $startAt === null ||
            $endAt === null
        ) {
            scheduleJson(
                false,
                'Invalid schedule movement.',
                array(),
                422
            );
        }

        if (
            strtotime($endAt) <=
            strtotime($startAt)
        ) {
            scheduleJson(
                false,
                'End date/time must be after start date/time.',
                array(),
                422
            );
        }

        try {
            $conn->begin_transaction();

            $event = scheduleLoadEvent(
                $conn,
                $eventId,
                $tenantId,
                true
            );

            if (!$event) {
                throw new Exception(
                    'Schedule event was not found.'
                );
            }

            if (
                in_array(
                    $event['status'],
                    array(
                        'completed',
                        'cancelled',
                        'missed'
                    ),
                    true
                )
            ) {
                throw new Exception(
                    'Completed, cancelled, or missed events cannot be moved.'
                );
            }

            $workerId =
                !empty($event['assigned_user_id'])
                    ? (int) $event['assigned_user_id']
                    : null;

            $conflict = scheduleFindConflict(
                $conn,
                $tenantId,
                $workerId,
                $startAt,
                $endAt,
                $eventId
            );

            if ($conflict) {
                throw new Exception(
                    'This worker already has "' .
                    $conflict['title'] .
                    '" during the selected time.'
                );
            }

            scheduleInsertHistory(
                $conn,
                $tenantId,
                $eventId,
                $workerId,
                $workerId,
                $event['start_at'],
                $event['end_at'],
                $startAt,
                $endAt,
                'Calendar drag or resize',
                $currentUserId
            );

            $stmt = $conn->prepare("
                UPDATE schedule_events
                SET
                    start_at = ?,
                    end_at = ?,
                    all_day = ?,
                    status = 'rescheduled',
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the schedule movement: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'ssiii',
                $startAt,
                $endAt,
                $allDay,
                $eventId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Schedule event could not be moved: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            scheduleSyncLinkedRecord(
                $conn,
                $event,
                $workerId,
                $startAt,
                $endAt
            );

            $conn->commit();

            scheduleWriteActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $eventId,
                !empty($event['client_id'])
                    ? (int) $event['client_id']
                    : null,
                'schedule_event_rescheduled',
                'Schedule event rescheduled: ' .
                    $event['title'],
                array(
                    'event_id' => $eventId,
                    'old_start_at' => $event['start_at'],
                    'old_end_at' => $event['end_at'],
                    'new_start_at' => $startAt,
                    'new_end_at' => $endAt
                )
            );

            scheduleJson(
                true,
                'Schedule event rescheduled successfully.'
            );
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            scheduleJson(
                false,
                $error->getMessage(),
                array(),
                409
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Delete custom event
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete_event') {
        $eventId = isset($_POST['event_id'])
            ? (int) $_POST['event_id']
            : 0;

        if ($eventId <= 0) {
            scheduleJson(
                false,
                'Invalid schedule event.',
                array(),
                422
            );
        }

        try {
            $conn->begin_transaction();

            $event = scheduleLoadEvent(
                $conn,
                $eventId,
                $tenantId,
                true
            );

            if (!$event) {
                throw new Exception(
                    'Schedule event was not found.'
                );
            }

            $isCustomEvent =
                $event['event_type'] === 'event' &&
                (
                    empty($event['related_type']) ||
                    $event['related_type'] === 'event'
                ) &&
                empty($event['related_id']);

            if (!$isCustomEvent) {
                throw new Exception(
                    'Linked events cannot be deleted from the calendar. Open the linked record instead.'
                );
            }

            $stmt = $conn->prepare("
                DELETE FROM schedule_events
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare event deletion: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'ii',
                $eventId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Schedule event could not be deleted: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            $conn->commit();

            scheduleWriteActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $eventId,
                !empty($event['client_id'])
                    ? (int) $event['client_id']
                    : null,
                'schedule_event_deleted',
                'Schedule event deleted: ' .
                    $event['title'],
                array(
                    'event_id' => $eventId,
                    'title' => $event['title'],
                    'start_at' => $event['start_at'],
                    'end_at' => $event['end_at']
                )
            );

            scheduleJson(
                true,
                'Schedule event deleted successfully.'
            );
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            scheduleJson(
                false,
                $error->getMessage(),
                array(),
                422
            );
        }
    }

    scheduleJson(
        false,
        'Unknown scheduling action.',
        array(),
        400
    );
}

/*
|--------------------------------------------------------------------------
| Page data
|--------------------------------------------------------------------------
*/

$pageTitle = 'Scheduling - FieldPlx';
$activePage = 'schedule';
$searchPlaceholder = 'Search schedule...';
$basePath = '';

$workers = array();
$clients = array();
$properties = array();

$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name,
        email,
        phone,
        job_title,
        employee_code,
        color_code,
        is_bookable,
        is_field_worker
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
    $stmt->execute();
    $workers = scheduleFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        display_name,
        phone,
        email
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $clients = scheduleFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        client_id,
        name,
        address_line1,
        city,
        state,
        postal_code
    FROM properties
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status = 'active'
    ORDER BY
        client_id ASC,
        name ASC,
        address_line1 ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $properties = scheduleFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Dashboard statistics
|--------------------------------------------------------------------------
*/

$stats = array(
    'today' => 0,
    'upcoming' => 0,
    'in_progress' => 0,
    'completed_month' => 0,
    'unassigned' => 0,
    'conflicts' => 0
);

$stmt = $conn->prepare("
    SELECT
        SUM(
            DATE(start_at) = CURDATE()
            AND status NOT IN (
                'cancelled',
                'missed'
            )
        ) AS today_count,

        SUM(
            start_at > NOW()
            AND status NOT IN (
                'completed',
                'cancelled',
                'missed'
            )
        ) AS upcoming_count,

        SUM(
            start_at <= NOW()
            AND end_at >= NOW()
            AND status NOT IN (
                'completed',
                'cancelled',
                'missed'
            )
        ) AS in_progress_count,

        SUM(
            status = 'completed'
            AND YEAR(start_at) = YEAR(CURDATE())
            AND MONTH(start_at) = MONTH(CURDATE())
        ) AS completed_month_count,

        SUM(
            assigned_user_id IS NULL
            AND status NOT IN (
                'completed',
                'cancelled',
                'missed'
            )
        ) AS unassigned_count

    FROM schedule_events
    WHERE tenant_id = ?
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = scheduleFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['today'] =
            (int) $row['today_count'];

        $stats['upcoming'] =
            (int) $row['upcoming_count'];

        $stats['in_progress'] =
            (int) $row['in_progress_count'];

        $stats['completed_month'] =
            (int) $row['completed_month_count'];

        $stats['unassigned'] =
            (int) $row['unassigned_count'];
    }
}

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM schedule_events a

    INNER JOIN schedule_events b
        ON b.tenant_id = a.tenant_id
       AND b.assigned_user_id =
            a.assigned_user_id
       AND b.id > a.id
       AND b.start_at < a.end_at
       AND b.end_at > a.start_at
       AND b.status NOT IN (
            'completed',
            'cancelled',
            'missed'
       )

    WHERE a.tenant_id = ?
      AND a.assigned_user_id IS NOT NULL
      AND a.status NOT IN (
          'completed',
          'cancelled',
          'missed'
      )
      AND a.end_at >= NOW()
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $row = scheduleFetchAssoc($stmt);
    $stmt->close();

    if ($row) {
        $stats['conflicts'] =
            (int) $row['total'];
    }
}

$csrfToken = scheduleCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css"
>

<style>
.scheduling-page {
    --sc-primary: #6d28d9;
    --sc-text: #111827;
    --sc-muted: #6b7280;
    --sc-border: #e5e7eb;
}

.sc-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.sc-header h1 {
    margin: 0;
    color: var(--sc-text);
    font-size: 21px;
    font-weight: 700;
}

.sc-header p {
    margin: 5px 0 0;
    color: var(--sc-muted);
    font-size: 11px;
}

.sc-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.sc-btn {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 1px solid var(--sc-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-family: inherit;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.sc-btn.primary {
    border-color: var(--sc-primary);
    background: var(--sc-primary);
    color: #fff;
}

.sc-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(6,minmax(0,1fr));
    gap: 10px;
}

.sc-stat {
    padding: 13px;
    border: 1px solid var(--sc-border);
    border-radius: 11px;
    background: #fff;
}

.sc-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.sc-stat-value {
    margin-top: 4px;
    color: var(--sc-text);
    font-size: 19px;
    font-weight: 700;
}

.sc-layout {
    display: grid;
    grid-template-columns:
        minmax(230px,.28fr)
        minmax(0,1.72fr);
    gap: 13px;
    align-items: start;
}

.sc-card {
    overflow: hidden;
    border: 1px solid var(--sc-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.sc-card + .sc-card {
    margin-top: 13px;
}

.sc-card-head {
    min-height: 46px;
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
}

.sc-card-head h2 {
    margin: 0;
    color: var(--sc-text);
    font-size: 11px;
    font-weight: 700;
}

.sc-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.sc-card-body {
    padding: 13px;
}

.sc-filter {
    margin-bottom: 10px;
}

.sc-filter:last-child {
    margin-bottom: 0;
}

.sc-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.sc-select,
.sc-input,
.sc-textarea {
    width: 100%;
    min-height: 37px;
    padding: 8px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 8px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 9px;
    outline: none;
}

.sc-textarea {
    min-height: 92px;
    resize: vertical;
}

.sc-select:focus,
.sc-input:focus,
.sc-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.08);
}

.sc-worker-list {
    max-height: 340px;
    overflow-y: auto;
}

.sc-worker {
    padding: 8px 5px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid #f3f4f6;
}

.sc-worker:last-child {
    border-bottom: 0;
}

.sc-worker-dot {
    width: 9px;
    height: 9px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: #9ca3af;
}

.sc-worker-details {
    min-width: 0;
}

.sc-worker-name {
    display: block;
    overflow: hidden;
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sc-worker-role {
    margin-top: 2px;
    display: block;
    overflow: hidden;
    color: #9ca3af;
    font-size: 8px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sc-legend {
    display: grid;
    gap: 7px;
}

.sc-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #4b5563;
    font-size: 8px;
}

.sc-legend-dot {
    width: 10px;
    height: 10px;
    flex: 0 0 auto;
    border-radius: 3px;
}

.sc-calendar-wrap {
    min-height: 730px;
    padding: 13px;
}

#scheduleCalendar {
    min-height: 700px;
}

.sc-loading {
    padding: 16px;
    color: #6b7280;
    font-size: 9px;
    text-align: center;
}

.sc-toast-container {
    position: fixed;
    top: 18px;
    right: 18px;
    z-index: 10050;
    display: grid;
    gap: 8px;
}

.sc-toast {
    min-width: 260px;
    max-width: 390px;
    padding: 11px 13px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fff;
    color: #374151;
    box-shadow: 0 16px 40px rgba(15,23,42,.18);
    font-size: 9px;
    line-height: 1.55;
}

.sc-toast.success {
    border-color: #bbf7d0;
    color: #047857;
}

.sc-toast.error {
    border-color: #fecaca;
    color: #b91c1c;
}

.sc-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(15,23,42,.48);
}

.sc-modal-backdrop.open {
    display: flex;
}

.sc-modal {
    width: min(760px,100%);
    max-height: calc(100vh - 32px);
    overflow-y: auto;
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 22px 60px rgba(15,23,42,.28);
}

.sc-modal-head {
    padding: 13px 15px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.sc-modal-head h3 {
    margin: 0;
    color: #111827;
    font-size: 13px;
    font-weight: 700;
}

.sc-modal-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.sc-modal-close {
    width: 31px;
    height: 31px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    color: #6b7280;
    cursor: pointer;
}

.sc-modal-body {
    padding: 15px;
}

.sc-form-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 11px;
}

.sc-field.full {
    grid-column: 1 / -1;
}

.sc-field label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.sc-check {
    min-height: 38px;
    padding: 8px 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fafafa;
}

.sc-check input {
    width: 16px;
    height: 16px;
    accent-color: var(--sc-primary);
}

.sc-check span {
    color: #374151;
    font-size: 9px;
    font-weight: 600;
}

.sc-modal-actions {
    padding: 12px 15px;
    display: flex;
    justify-content: space-between;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

.sc-modal-action-group {
    display: flex;
    gap: 7px;
}

.sc-event-meta {
    margin-bottom: 13px;
    padding: 10px;
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 8px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.sc-event-meta-item {
    min-width: 0;
}

.sc-event-meta-label {
    color: #9ca3af;
    font-size: 7px;
    font-weight: 700;
    text-transform: uppercase;
}

.sc-event-meta-value {
    margin-top: 3px;
    display: block;
    overflow-wrap: anywhere;
    color: #111827;
    font-size: 9px;
    font-weight: 700;
}

.sc-linked-link {
    color: var(--sc-primary);
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.fc {
    font-family: inherit;
}

.fc .fc-toolbar-title {
    color: #111827;
    font-size: 16px;
    font-weight: 700;
}

.fc .fc-button {
    border: 0 !important;
    border-radius: 7px !important;
    background: #f3f4f6 !important;
    color: #374151 !important;
    box-shadow: none !important;
    font-size: 9px !important;
    font-weight: 700 !important;
    text-transform: capitalize !important;
}

.fc .fc-button-primary:not(:disabled).fc-button-active,
.fc .fc-button-primary:not(:disabled):active {
    background: var(--sc-primary) !important;
    color: #fff !important;
}

.fc .fc-today-button {
    background: var(--sc-primary) !important;
    color: #fff !important;
}

.fc .fc-col-header-cell-cushion,
.fc .fc-daygrid-day-number,
.fc .fc-timegrid-axis-cushion,
.fc .fc-timegrid-slot-label-cushion {
    color: #4b5563;
    font-size: 8px;
    text-decoration: none;
}

.fc .fc-daygrid-event,
.fc .fc-timegrid-event {
    border-radius: 6px;
    font-size: 8px;
    font-weight: 600;
}

.fc .fc-day-today {
    background: #faf5ff !important;
}

@media (max-width: 1100px) {
    .sc-stats {
        grid-template-columns: repeat(3,minmax(0,1fr));
    }

    .sc-layout {
        grid-template-columns: 1fr;
    }

    .sc-worker-list {
        max-height: 220px;
    }
}

@media (max-width: 680px) {
    .sc-header {
        flex-direction: column;
    }

    .sc-stats,
    .sc-form-grid,
    .sc-event-meta {
        grid-template-columns: 1fr;
    }

    .sc-field.full {
        grid-column: auto;
    }

    .sc-modal-actions {
        flex-direction: column-reverse;
    }

    .sc-modal-action-group {
        width: 100%;
    }

    .sc-modal-action-group .sc-btn {
        flex: 1;
    }

    .fc .fc-toolbar {
        align-items: flex-start;
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<div class="scheduling-page">
    <div class="sc-header">
        <div>
            <h1>Scheduling</h1>
            <p>
                View, assign, and reschedule visits, bookings, assessments, tasks, work orders, and custom events.
            </p>
        </div>

        <div class="sc-header-actions">
            <a href="visit-add.php" class="sc-btn">
                <i class="bi bi-geo-alt"></i>
                Add Visit
            </a>

            <?php if ($canManage): ?>
                <button
                    type="button"
                    class="sc-btn primary"
                    id="addScheduleEventButton"
                >
                    <i class="bi bi-plus-lg"></i>
                    Add Event
                </button>
            <?php endif; ?>
        </div>
    </div>

    <section class="sc-stats">
        <article class="sc-stat">
            <div class="sc-stat-label">Today</div>
            <div class="sc-stat-value">
                <?= e($stats['today']); ?>
            </div>
        </article>

        <article class="sc-stat">
            <div class="sc-stat-label">Upcoming</div>
            <div class="sc-stat-value">
                <?= e($stats['upcoming']); ?>
            </div>
        </article>

        <article class="sc-stat">
            <div class="sc-stat-label">Happening Now</div>
            <div class="sc-stat-value">
                <?= e($stats['in_progress']); ?>
            </div>
        </article>

        <article class="sc-stat">
            <div class="sc-stat-label">Completed This Month</div>
            <div class="sc-stat-value">
                <?= e($stats['completed_month']); ?>
            </div>
        </article>

        <article class="sc-stat">
            <div class="sc-stat-label">Unassigned</div>
            <div class="sc-stat-value">
                <?= e($stats['unassigned']); ?>
            </div>
        </article>

        <article class="sc-stat">
            <div class="sc-stat-label">Schedule Conflicts</div>
            <div class="sc-stat-value">
                <?= e($stats['conflicts']); ?>
            </div>
        </article>
    </section>

    <div class="sc-layout">
        <aside>
            <section class="sc-card">
                <div class="sc-card-head">
                    <h2>Calendar Filters</h2>
                    <p>Filter the visible events.</p>
                </div>

                <div class="sc-card-body">
                    <div class="sc-filter">
                        <label class="sc-label" for="calendarWorkerFilter">
                            Worker
                        </label>

                        <select
                            id="calendarWorkerFilter"
                            class="sc-select"
                        >
                            <option value="">All Workers</option>
                            <option value="0">Unassigned</option>

                            <?php foreach ($workers as $worker): ?>
                                <?php
                                $workerName = trim(
                                    (string) $worker['first_name'] .
                                    ' ' .
                                    (string) $worker['last_name']
                                );
                                ?>
                                <option value="<?= (int) $worker['id']; ?>">
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
                    </div>

                    <div class="sc-filter">
                        <label class="sc-label" for="calendarTypeFilter">
                            Event Type
                        </label>

                        <select
                            id="calendarTypeFilter"
                            class="sc-select"
                        >
                            <option value="">All Types</option>
                            <option value="visit">Visits</option>
                            <option value="booking">Bookings</option>
                            <option value="assessment">Assessments</option>
                            <option value="task">Tasks</option>
                            <option value="event">Events / Work Orders</option>
                        </select>
                    </div>

                    <div class="sc-filter">
                        <label class="sc-label" for="calendarStatusFilter">
                            Status
                        </label>

                        <select
                            id="calendarStatusFilter"
                            class="sc-select"
                        >
                            <option value="">All Statuses</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="rescheduled">Rescheduled</option>
                            <option value="completed">Completed</option>
                            <option value="missed">Missed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>

                    <button
                        type="button"
                        class="sc-btn"
                        id="resetCalendarFilters"
                        style="width:100%;"
                    >
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Reset Filters
                    </button>
                </div>
            </section>

            <section class="sc-card">
                <div class="sc-card-head">
                    <h2>Team</h2>
                    <p>Active users available for assignment.</p>
                </div>

                <div class="sc-card-body">
                    <div class="sc-worker-list">
                        <?php if (!empty($workers)): ?>
                            <?php foreach ($workers as $worker): ?>
                                <?php
                                $workerName = trim(
                                    (string) $worker['first_name'] .
                                    ' ' .
                                    (string) $worker['last_name']
                                );

                                $workerColor = trim(
                                    (string) $worker['color_code']
                                );

                                if (
                                    !preg_match(
                                        '/^#[0-9A-Fa-f]{6}$/',
                                        $workerColor
                                    )
                                ) {
                                    $workerColor = '#9ca3af';
                                }
                                ?>
                                <div class="sc-worker">
                                    <span
                                        class="sc-worker-dot"
                                        style="background:<?= e($workerColor); ?>;"
                                    ></span>

                                    <div class="sc-worker-details">
                                        <span class="sc-worker-name">
                                            <?= e($workerName); ?>
                                        </span>

                                        <span class="sc-worker-role">
                                            <?= e(
                                                trim(
                                                    (string) $worker['job_title']
                                                ) !== ''
                                                    ? $worker['job_title']
                                                    : (
                                                        !empty(
                                                            $worker['is_field_worker']
                                                        )
                                                            ? 'Field Worker'
                                                            : 'Team Member'
                                                    )
                                            ); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="sc-loading">
                                No active team members are available.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="sc-card">
                <div class="sc-card-head">
                    <h2>Event Legend</h2>
                </div>

                <div class="sc-card-body">
                    <div class="sc-legend">
                        <div class="sc-legend-item">
                            <span
                                class="sc-legend-dot"
                                style="background:#2563eb;"
                            ></span>
                            Visit
                        </div>

                        <div class="sc-legend-item">
                            <span
                                class="sc-legend-dot"
                                style="background:#0891b2;"
                            ></span>
                            Booking
                        </div>

                        <div class="sc-legend-item">
                            <span
                                class="sc-legend-dot"
                                style="background:#7c3aed;"
                            ></span>
                            Assessment
                        </div>

                        <div class="sc-legend-item">
                            <span
                                class="sc-legend-dot"
                                style="background:#ea580c;"
                            ></span>
                            Task
                        </div>

                        <div class="sc-legend-item">
                            <span
                                class="sc-legend-dot"
                                style="background:#475569;"
                            ></span>
                            Event / Work Order
                        </div>

                        <div class="sc-legend-item">
                            <span
                                class="sc-legend-dot"
                                style="background:#059669;"
                            ></span>
                            Completed
                        </div>

                        <div class="sc-legend-item">
                            <span
                                class="sc-legend-dot"
                                style="background:#d97706;"
                            ></span>
                            Rescheduled
                        </div>
                    </div>
                </div>
            </section>
        </aside>

        <main>
            <section class="sc-card">
                <div class="sc-calendar-wrap">
                    <div id="scheduleCalendar"></div>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="sc-toast-container" id="scheduleToastContainer"></div>

<div class="sc-modal-backdrop" id="scheduleModalBackdrop">
    <div
        class="sc-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="scheduleModalTitle"
    >
        <div class="sc-modal-head">
            <div>
                <h3 id="scheduleModalTitle">Add Schedule Event</h3>
                <p id="scheduleModalSubtitle">
                    Create a custom calendar event.
                </p>
            </div>

            <button
                type="button"
                class="sc-modal-close"
                data-close-schedule-modal
                aria-label="Close"
            >
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form id="scheduleEventForm">
            <div class="sc-modal-body">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken); ?>"
                >

                <input
                    type="hidden"
                    name="ajax_action"
                    id="scheduleAjaxAction"
                    value="create_event"
                >

                <input
                    type="hidden"
                    name="event_id"
                    id="scheduleEventId"
                    value=""
                >

                <div
                    class="sc-event-meta"
                    id="scheduleEventMeta"
                    style="display:none;"
                >
                    <div class="sc-event-meta-item">
                        <span class="sc-event-meta-label">
                            Event Type
                        </span>
                        <span
                            class="sc-event-meta-value"
                            id="metaEventType"
                        >
                            —
                        </span>
                    </div>

                    <div class="sc-event-meta-item">
                        <span class="sc-event-meta-label">
                            Related Record
                        </span>
                        <span
                            class="sc-event-meta-value"
                            id="metaRelatedRecord"
                        >
                            —
                        </span>
                    </div>

                    <div class="sc-event-meta-item">
                        <span class="sc-event-meta-label">
                            Worker
                        </span>
                        <span
                            class="sc-event-meta-value"
                            id="metaWorker"
                        >
                            Unassigned
                        </span>
                    </div>

                    <div class="sc-event-meta-item">
                        <span class="sc-event-meta-label">
                            Client
                        </span>
                        <span
                            class="sc-event-meta-value"
                            id="metaClient"
                        >
                            —
                        </span>
                    </div>
                </div>

                <div class="sc-form-grid">
                    <div class="sc-field full">
                        <label for="scheduleTitle">
                            Event Title
                            <span style="color:#dc2626;">*</span>
                        </label>

                        <input
                            type="text"
                            name="title"
                            id="scheduleTitle"
                            class="sc-input"
                            maxlength="190"
                            required
                        >
                    </div>

                    <div class="sc-field full">
                        <label for="scheduleDescription">
                            Description
                        </label>

                        <textarea
                            name="description"
                            id="scheduleDescription"
                            class="sc-textarea"
                        ></textarea>
                    </div>

                    <div class="sc-field">
                        <label for="scheduleWorker">
                            Assigned Worker
                        </label>

                        <select
                            name="assigned_user_id"
                            id="scheduleWorker"
                            class="sc-select"
                        >
                            <option value="">Unassigned</option>

                            <?php foreach ($workers as $worker): ?>
                                <?php
                                $workerName = trim(
                                    (string) $worker['first_name'] .
                                    ' ' .
                                    (string) $worker['last_name']
                                );
                                ?>
                                <option value="<?= (int) $worker['id']; ?>">
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
                    </div>

                    <div class="sc-field">
                        <label for="scheduleClient">
                            Client
                        </label>

                        <select
                            name="client_id"
                            id="scheduleClient"
                            class="sc-select"
                        >
                            <option value="">No Client</option>

                            <?php foreach ($clients as $client): ?>
                                <option value="<?= (int) $client['id']; ?>">
                                    <?= e($client['display_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sc-field full">
                        <label for="scheduleProperty">
                            Property
                        </label>

                        <select
                            name="property_id"
                            id="scheduleProperty"
                            class="sc-select"
                        >
                            <option value="">No Property</option>

                            <?php foreach ($properties as $property): ?>
                                <?php
                                $propertyLabel =
                                    trim(
                                        (string) $property['name']
                                    ) !== ''
                                        ? (string) $property['name']
                                        : (string) $property['address_line1'];

                                $propertyLocation = trim(
                                    implode(
                                        ', ',
                                        array_filter(
                                            array(
                                                $property['city'],
                                                $property['state'],
                                                $property['postal_code']
                                            ),
                                            function ($value) {
                                                return trim(
                                                    (string) $value
                                                ) !== '';
                                            }
                                        )
                                    )
                                );
                                ?>
                                <option
                                    value="<?= (int) $property['id']; ?>"
                                    data-client-id="<?= (int) $property['client_id']; ?>"
                                >
                                    <?= e($propertyLabel); ?>

                                    <?php if ($propertyLocation !== ''): ?>
                                        · <?= e($propertyLocation); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sc-field">
                        <label for="scheduleStart">
                            Start
                            <span style="color:#dc2626;">*</span>
                        </label>

                        <input
                            type="datetime-local"
                            name="start_at"
                            id="scheduleStart"
                            class="sc-input"
                            required
                        >
                    </div>

                    <div class="sc-field">
                        <label for="scheduleEnd">
                            End
                            <span style="color:#dc2626;">*</span>
                        </label>

                        <input
                            type="datetime-local"
                            name="end_at"
                            id="scheduleEnd"
                            class="sc-input"
                            required
                        >
                    </div>

                    <div class="sc-field">
                        <label for="scheduleStatus">
                            Status
                        </label>

                        <select
                            name="status"
                            id="scheduleStatus"
                            class="sc-select"
                        >
                            <option value="scheduled">Scheduled</option>
                            <option value="rescheduled">Rescheduled</option>
                            <option value="completed">Completed</option>
                            <option value="missed">Missed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>

                    <div class="sc-field">
                        <label for="scheduleColor">
                            Event Colour
                        </label>

                        <input
                            type="color"
                            name="color"
                            id="scheduleColor"
                            class="sc-input"
                            value="#475569"
                        >
                    </div>

                    <div class="sc-field full">
                        <label class="sc-check">
                            <input
                                type="checkbox"
                                name="all_day"
                                id="scheduleAllDay"
                                value="1"
                            >
                            <span>All-day event</span>
                        </label>
                    </div>

                    <div
                        class="sc-field full"
                        id="scheduleReasonField"
                        style="display:none;"
                    >
                        <label for="scheduleReason">
                            Reschedule / Update Reason
                        </label>

                        <input
                            type="text"
                            name="reason"
                            id="scheduleReason"
                            class="sc-input"
                            maxlength="255"
                            placeholder="Example: Customer requested a new time"
                        >
                    </div>

                    <div
                        class="sc-field full"
                        id="scheduleLinkedRecord"
                        style="display:none;"
                    >
                        <a
                            href="#"
                            id="scheduleLinkedRecordLink"
                            class="sc-linked-link"
                        >
                            Open linked record
                        </a>
                    </div>
                </div>
            </div>

            <div class="sc-modal-actions">
                <div class="sc-modal-action-group">
                    <button
                        type="button"
                        class="sc-btn"
                        data-close-schedule-modal
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        class="sc-btn"
                        id="deleteScheduleEventButton"
                        style="display:none;color:#b91c1c;border-color:#fecaca;"
                    >
                        <i class="bi bi-trash"></i>
                        Delete
                    </button>
                </div>

                <div class="sc-modal-action-group">
                    <button
                        type="submit"
                        class="sc-btn primary"
                        id="saveScheduleEventButton"
                    >
                        <i class="bi bi-check2"></i>
                        Save Event
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var canManage =
        <?= $canManage ? 'true' : 'false'; ?>;

    var calendarElement =
        document.getElementById('scheduleCalendar');

    var workerFilter =
        document.getElementById('calendarWorkerFilter');

    var typeFilter =
        document.getElementById('calendarTypeFilter');

    var statusFilter =
        document.getElementById('calendarStatusFilter');

    var resetFiltersButton =
        document.getElementById('resetCalendarFilters');

    var addButton =
        document.getElementById('addScheduleEventButton');

    var modalBackdrop =
        document.getElementById('scheduleModalBackdrop');

    var modalTitle =
        document.getElementById('scheduleModalTitle');

    var modalSubtitle =
        document.getElementById('scheduleModalSubtitle');

    var eventForm =
        document.getElementById('scheduleEventForm');

    var ajaxAction =
        document.getElementById('scheduleAjaxAction');

    var eventIdInput =
        document.getElementById('scheduleEventId');

    var titleInput =
        document.getElementById('scheduleTitle');

    var descriptionInput =
        document.getElementById('scheduleDescription');

    var workerInput =
        document.getElementById('scheduleWorker');

    var clientInput =
        document.getElementById('scheduleClient');

    var propertyInput =
        document.getElementById('scheduleProperty');

    var startInput =
        document.getElementById('scheduleStart');

    var endInput =
        document.getElementById('scheduleEnd');

    var statusInput =
        document.getElementById('scheduleStatus');

    var colorInput =
        document.getElementById('scheduleColor');

    var allDayInput =
        document.getElementById('scheduleAllDay');

    var reasonField =
        document.getElementById('scheduleReasonField');

    var reasonInput =
        document.getElementById('scheduleReason');

    var deleteButton =
        document.getElementById('deleteScheduleEventButton');

    var saveButton =
        document.getElementById('saveScheduleEventButton');

    var eventMeta =
        document.getElementById('scheduleEventMeta');

    var linkedRecord =
        document.getElementById('scheduleLinkedRecord');

    var linkedRecordLink =
        document.getElementById('scheduleLinkedRecordLink');

    var propertyOptions = [];

    Array.prototype.forEach.call(
        propertyInput.options,
        function (option) {
            propertyOptions.push({
                value: option.value,
                text: option.textContent,
                clientId:
                    option.getAttribute('data-client-id') || ''
            });
        }
    );

    function pad(value) {
        value = String(value);

        return value.length < 2
            ? '0' + value
            : value;
    }

    function toLocalInputValue(date) {
        if (!date) {
            return '';
        }

        return date.getFullYear() +
            '-' +
            pad(date.getMonth() + 1) +
            '-' +
            pad(date.getDate()) +
            'T' +
            pad(date.getHours()) +
            ':' +
            pad(date.getMinutes());
    }

    function fromIsoString(value) {
        if (!value) {
            return null;
        }

        var date = new Date(value);

        return isNaN(date.getTime())
            ? null
            : date;
    }

    function showToast(message, type) {
        var container =
            document.getElementById(
                'scheduleToastContainer'
            );

        var toast =
            document.createElement('div');

        toast.className =
            'sc-toast ' + (type || '');

        toast.textContent =
            message;

        container.appendChild(toast);

        window.setTimeout(
            function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(
                        toast
                    );
                }
            },
            4500
        );
    }

    function setSaving(isSaving) {
        saveButton.disabled = isSaving;
        saveButton.textContent =
            isSaving
                ? 'Saving...'
                : 'Save Event';
    }

    function refreshPropertyOptions(
        selectedPropertyId
    ) {
        var clientId =
            clientInput.value;

        propertyInput.innerHTML = '';

        propertyOptions.forEach(
            function (item) {
                if (
                    item.value !== '' &&
                    clientId !== '' &&
                    item.clientId !== clientId
                ) {
                    return;
                }

                var option =
                    document.createElement('option');

                option.value =
                    item.value;

                option.textContent =
                    item.text;

                if (
                    item.value ===
                    String(selectedPropertyId || '')
                ) {
                    option.selected = true;
                }

                propertyInput.appendChild(option);
            }
        );

        if (
            propertyInput.options.length === 0 ||
            propertyInput.options[0].value !== ''
        ) {
            var emptyOption =
                document.createElement('option');

            emptyOption.value = '';
            emptyOption.textContent =
                'No Property';

            propertyInput.insertBefore(
                emptyOption,
                propertyInput.firstChild
            );
        }
    }

    function closeModal() {
        modalBackdrop.classList.remove('open');
        document.body.style.overflow = '';
    }

    function openModal() {
        modalBackdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function resetEventForm() {
        eventForm.reset();

        ajaxAction.value =
            'create_event';

        eventIdInput.value = '';

        colorInput.value =
            '#475569';

        statusInput.value =
            'scheduled';

        reasonField.style.display =
            'none';

        reasonInput.value = '';

        deleteButton.style.display =
            'none';

        eventMeta.style.display =
            'none';

        linkedRecord.style.display =
            'none';

        linkedRecordLink.href = '#';

        titleInput.readOnly = false;
        descriptionInput.readOnly = false;
        clientInput.disabled = false;
        propertyInput.disabled = false;

        refreshPropertyOptions('');
    }

    function openCreateModal(
        startDate,
        endDate,
        allDay
    ) {
        resetEventForm();

        modalTitle.textContent =
            'Add Schedule Event';

        modalSubtitle.textContent =
            'Create a custom calendar event.';

        if (!startDate) {
            startDate = new Date();
            startDate.setMinutes(
                Math.ceil(
                    startDate.getMinutes() / 15
                ) * 15
            );
            startDate.setSeconds(0, 0);
        }

        if (!endDate) {
            endDate =
                new Date(
                    startDate.getTime() +
                    60 * 60 * 1000
                );
        }

        startInput.value =
            toLocalInputValue(startDate);

        endInput.value =
            toLocalInputValue(endDate);

        allDayInput.checked =
            !!allDay;

        openModal();

        window.setTimeout(
            function () {
                titleInput.focus();
            },
            50
        );
    }

    function setMetaValue(id, value) {
        document.getElementById(id).textContent =
            value && String(value).trim() !== ''
                ? value
                : '—';
    }

    function openEditModal(event) {
        resetEventForm();

        var props =
            event.extendedProps || {};

        modalTitle.textContent =
            'Schedule Event';

        modalSubtitle.textContent =
            props.isCustomEvent
                ? 'Edit the custom calendar event.'
                : 'Update the linked schedule event.';

        ajaxAction.value =
            'update_event';

        eventIdInput.value =
            event.id;

        titleInput.value =
            event.title || '';

        descriptionInput.value =
            props.description || '';

        workerInput.value =
            props.assignedUserId !== null &&
            typeof props.assignedUserId !== 'undefined'
                ? String(props.assignedUserId)
                : '';

        clientInput.value =
            props.clientId !== null &&
            typeof props.clientId !== 'undefined'
                ? String(props.clientId)
                : '';

        refreshPropertyOptions(
            props.propertyId
        );

        startInput.value =
            toLocalInputValue(event.start);

        endInput.value =
            toLocalInputValue(
                event.end ||
                new Date(
                    event.start.getTime() +
                    60 * 60 * 1000
                )
            );

        allDayInput.checked =
            !!event.allDay;

        statusInput.value =
            props.status || 'scheduled';

        colorInput.value =
            props.color || '#475569';

        reasonField.style.display =
            'block';

        deleteButton.style.display =
            props.isCustomEvent
                ? 'inline-flex'
                : 'none';

        eventMeta.style.display =
            'grid';

        setMetaValue(
            'metaEventType',
            props.eventType
                ? props.eventType
                    .replace(/_/g, ' ')
                    .replace(/\b\w/g, function (letter) {
                        return letter.toUpperCase();
                    })
                : 'Event'
        );

        setMetaValue(
            'metaRelatedRecord',
            props.relatedType && props.relatedId
                ? props.relatedType +
                  ' #' +
                  props.relatedId
                : 'Custom event'
        );

        setMetaValue(
            'metaWorker',
            props.workerName || 'Unassigned'
        );

        setMetaValue(
            'metaClient',
            props.clientName || 'No client'
        );

        if (props.linkedUrl) {
            linkedRecord.style.display =
                'block';

            linkedRecordLink.href =
                props.linkedUrl;
        }

        if (!canManage) {
            titleInput.readOnly = true;
            descriptionInput.readOnly = true;
            workerInput.disabled = true;
            clientInput.disabled = true;
            propertyInput.disabled = true;
            startInput.disabled = true;
            endInput.disabled = true;
            statusInput.disabled = true;
            colorInput.disabled = true;
            allDayInput.disabled = true;
            reasonInput.disabled = true;
            saveButton.style.display = 'none';
        } else {
            startInput.disabled = false;
            endInput.disabled = false;
            statusInput.disabled = false;
            colorInput.disabled = false;
            allDayInput.disabled = false;
            reasonInput.disabled = false;
            saveButton.style.display = 'inline-flex';
        }

        openModal();
    }

    function submitAction(formData) {
        return fetch('scheduling.php', {
            method: 'POST',
            headers: {
                'X-Requested-With':
                    'XMLHttpRequest'
            },
            body: formData
        }).then(function (response) {
            return response.json().then(
                function (payload) {
                    if (
                        !response.ok ||
                        !payload.success
                    ) {
                        throw new Error(
                            payload.message ||
                            'The schedule action failed.'
                        );
                    }

                    return payload;
                }
            );
        });
    }

    var calendar =
        new FullCalendar.Calendar(
            calendarElement,
            {
                initialView: 'dayGridMonth',
                height: 'auto',
                nowIndicator: true,
                selectable: canManage,
                editable: canManage,
                eventStartEditable: canManage,
                eventDurationEditable: canManage,
                dayMaxEvents: true,
                navLinks: true,
                slotMinTime: '06:00:00',
                slotMaxTime: '22:00:00',
                slotDuration: '00:30:00',
                snapDuration: '00:15:00',
                firstDay: 1,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right:
                        'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                },
                buttonText: {
                    today: 'Today',
                    month: 'Month',
                    week: 'Week',
                    day: 'Day',
                    list: 'List'
                },
                events: function (
                    fetchInfo,
                    successCallback,
                    failureCallback
                ) {
                    var params =
                        new URLSearchParams();

                    params.set('ajax', 'events');
                    params.set(
                        'start',
                        fetchInfo.startStr
                    );
                    params.set(
                        'end',
                        fetchInfo.endStr
                    );

                    if (workerFilter.value !== '') {
                        params.set(
                            'worker_id',
                            workerFilter.value
                        );
                    }

                    if (typeFilter.value !== '') {
                        params.set(
                            'event_type',
                            typeFilter.value
                        );
                    }

                    if (statusFilter.value !== '') {
                        params.set(
                            'status',
                            statusFilter.value
                        );
                    }

                    fetch(
                        'scheduling.php?' +
                        params.toString(),
                        {
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
                        }
                    )
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error(
                                    'Unable to load calendar events.'
                                );
                            }

                            return response.json();
                        })
                        .then(successCallback)
                        .catch(function (error) {
                            showToast(
                                error.message,
                                'error'
                            );

                            failureCallback(error);
                        });
                },
                select: function (selectionInfo) {
                    if (!canManage) {
                        return;
                    }

                    var start =
                        selectionInfo.start;

                    var end =
                        selectionInfo.end;

                    if (selectionInfo.allDay) {
                        end =
                            new Date(
                                start.getTime() +
                                24 * 60 * 60 * 1000
                            );
                    }

                    openCreateModal(
                        start,
                        end,
                        selectionInfo.allDay
                    );
                },
                dateClick: function (info) {
                    if (
                        !canManage ||
                        info.view.type !==
                            'dayGridMonth'
                    ) {
                        return;
                    }

                    var start =
                        new Date(info.date);

                    start.setHours(9, 0, 0, 0);

                    openCreateModal(
                        start,
                        new Date(
                            start.getTime() +
                            60 * 60 * 1000
                        ),
                        false
                    );
                },
                eventClick: function (info) {
                    info.jsEvent.preventDefault();
                    openEditModal(info.event);
                },
                eventDrop: function (info) {
                    moveCalendarEvent(info);
                },
                eventResize: function (info) {
                    moveCalendarEvent(info);
                },
                eventDidMount: function (info) {
                    var props =
                        info.event.extendedProps || {};

                    var tooltip = [
                        info.event.title
                    ];

                    if (props.workerName) {
                        tooltip.push(
                            'Worker: ' +
                            props.workerName
                        );
                    }

                    if (props.clientName) {
                        tooltip.push(
                            'Client: ' +
                            props.clientName
                        );
                    }

                    if (props.propertyName) {
                        tooltip.push(
                            'Property: ' +
                            props.propertyName
                        );
                    }

                    tooltip.push(
                        'Status: ' +
                        (
                            props.status ||
                            'scheduled'
                        )
                    );

                    info.el.title =
                        tooltip.join('\n');
                }
            }
        );

    function moveCalendarEvent(info) {
        var event =
            info.event;

        var end =
            event.end ||
            new Date(
                event.start.getTime() +
                60 * 60 * 1000
            );

        var formData =
            new FormData();

        formData.append(
            'csrf_token',
            '<?= e($csrfToken); ?>'
        );

        formData.append(
            'ajax_action',
            'move_event'
        );

        formData.append(
            'event_id',
            event.id
        );

        formData.append(
            'start_at',
            toLocalInputValue(event.start)
        );

        formData.append(
            'end_at',
            toLocalInputValue(end)
        );

        formData.append(
            'all_day',
            event.allDay ? '1' : '0'
        );

        submitAction(formData)
            .then(function (payload) {
                showToast(
                    payload.message,
                    'success'
                );

                calendar.refetchEvents();
            })
            .catch(function (error) {
                info.revert();

                showToast(
                    error.message,
                    'error'
                );
            });
    }

    calendar.render();

    workerFilter.addEventListener(
        'change',
        function () {
            calendar.refetchEvents();
        }
    );

    typeFilter.addEventListener(
        'change',
        function () {
            calendar.refetchEvents();
        }
    );

    statusFilter.addEventListener(
        'change',
        function () {
            calendar.refetchEvents();
        }
    );

    resetFiltersButton.addEventListener(
        'click',
        function () {
            workerFilter.value = '';
            typeFilter.value = '';
            statusFilter.value = '';
            calendar.refetchEvents();
        }
    );

    if (addButton) {
        addButton.addEventListener(
            'click',
            function () {
                openCreateModal();
            }
        );
    }

    clientInput.addEventListener(
        'change',
        function () {
            refreshPropertyOptions('');
        }
    );

    startInput.addEventListener(
        'change',
        function () {
            if (
                startInput.value !== '' &&
                endInput.value === ''
            ) {
                var start =
                    fromIsoString(
                        startInput.value
                    );

                if (start) {
                    endInput.value =
                        toLocalInputValue(
                            new Date(
                                start.getTime() +
                                60 * 60 * 1000
                            )
                        );
                }
            }
        }
    );

    eventForm.addEventListener(
        'submit',
        function (event) {
            event.preventDefault();

            if (!canManage) {
                return;
            }

            setSaving(true);

            var formData =
                new FormData(eventForm);

            submitAction(formData)
                .then(function (payload) {
                    showToast(
                        payload.message,
                        'success'
                    );

                    closeModal();
                    calendar.refetchEvents();
                })
                .catch(function (error) {
                    showToast(
                        error.message,
                        'error'
                    );
                })
                .finally(function () {
                    setSaving(false);
                });
        }
    );

    deleteButton.addEventListener(
        'click',
        function () {
            if (
                !eventIdInput.value ||
                !window.confirm(
                    'Delete this custom schedule event?'
                )
            ) {
                return;
            }

            var formData =
                new FormData();

            formData.append(
                'csrf_token',
                '<?= e($csrfToken); ?>'
            );

            formData.append(
                'ajax_action',
                'delete_event'
            );

            formData.append(
                'event_id',
                eventIdInput.value
            );

            submitAction(formData)
                .then(function (payload) {
                    showToast(
                        payload.message,
                        'success'
                    );

                    closeModal();
                    calendar.refetchEvents();
                })
                .catch(function (error) {
                    showToast(
                        error.message,
                        'error'
                    );
                });
        }
    );

    Array.prototype.forEach.call(
        document.querySelectorAll(
            '[data-close-schedule-modal]'
        ),
        function (button) {
            button.addEventListener(
                'click',
                closeModal
            );
        }
    );

    modalBackdrop.addEventListener(
        'click',
        function (event) {
            if (event.target === modalBackdrop) {
                closeModal();
            }
        }
    );

    document.addEventListener(
        'keydown',
        function (event) {
            if (
                event.key === 'Escape' &&
                modalBackdrop.classList.contains(
                    'open'
                )
            ) {
                closeModal();
            }
        }
    );
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
