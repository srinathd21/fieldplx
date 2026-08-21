<?php
/**
 * FieldPlx - Add Task
 *
 * Upload as:
 * /public_html/task-add.php
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
| Dedicated task permissions are preferred. The current database may not yet
| contain tasks.manage, so jobs.manage is retained as the Operations fallback.
|
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('task-add.php')
    );
    exit;
}

$canManageTasks = true;

if (function_exists('hasPermission')) {
    $canManageTasks =
        hasPermission('tasks.manage') ||
        hasPermission('jobs.manage');
}

if (!$canManageTasks) {
    http_response_code(403);

    exit(
        '403 - Access Denied. ' .
        'You do not have permission to create tasks.'
    );
}

$pageTitle = 'Add Task - FieldPlx';
$activePage = 'task-add';
$searchPlaceholder = 'Search tasks...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('taskAddFetchAssoc')) {
    function taskAddFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('taskAddFetchAll')) {
    function taskAddFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('taskAddOld')) {
    function taskAddOld($key, $default = '')
    {
        if (
            isset($_POST[$key]) &&
            !is_array($_POST[$key])
        ) {
            return trim((string) $_POST[$key]);
        }

        return $default;
    }
}

if (!function_exists('taskAddNullable')) {
    function taskAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('taskAddNormalizeDateTime')) {
    function taskAddNormalizeDateTime($value)
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

if (!function_exists('taskAddCsrfToken')) {
    function taskAddCsrfToken()
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

if (!function_exists('taskAddVerifyCsrf')) {
    function taskAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('taskAddRelatedRecord')) {
    function taskAddRelatedRecord(
        mysqli $conn,
        $tenantId,
        $relatedType,
        $relatedId
    ) {
        $relatedId = (int) $relatedId;

        if (
            $relatedType === '' ||
            $relatedId <= 0
        ) {
            return null;
        }

        $queries = array(
            'job' => "
                SELECT
                    j.id,
                    j.client_id,
                    j.property_id,
                    CONCAT(j.job_no, ' · ', j.title)
                        AS label
                FROM jobs j
                WHERE j.id = ?
                  AND j.tenant_id = ?
                  AND j.deleted_at IS NULL
                LIMIT 1
            ",

            'visit' => "
                SELECT
                    v.id,
                    j.client_id,
                    j.property_id,
                    CONCAT(
                        COALESCE(
                            NULLIF(v.visit_no, ''),
                            CONCAT('Visit #', v.id)
                        ),
                        ' · ',
                        j.job_no,
                        ' · ',
                        j.title
                    ) AS label
                FROM visits v
                INNER JOIN jobs j
                    ON j.id = v.job_id
                   AND j.tenant_id = v.tenant_id
                   AND j.deleted_at IS NULL
                WHERE v.id = ?
                  AND v.tenant_id = ?
                LIMIT 1
            ",

            'work_order' => "
                SELECT
                    wo.id,
                    wo.client_id,
                    wo.property_id,
                    CONCAT(
                        wo.work_order_no,
                        ' · ',
                        wo.title
                    ) AS label
                FROM work_orders wo
                WHERE wo.id = ?
                  AND wo.tenant_id = ?
                  AND wo.deleted_at IS NULL
                LIMIT 1
            ",

            'request' => "
                SELECT
                    r.id,
                    r.client_id,
                    r.property_id,
                    CONCAT(
                        r.request_no,
                        ' · ',
                        r.title
                    ) AS label
                FROM requests r
                WHERE r.id = ?
                  AND r.tenant_id = ?
                  AND r.archived_at IS NULL
                LIMIT 1
            ",

            'quote' => "
                SELECT
                    q.id,
                    q.client_id,
                    q.property_id,
                    CONCAT(
                        q.quote_no,
                        CASE
                            WHEN q.title IS NOT NULL
                             AND q.title <> ''
                            THEN CONCAT(' · ', q.title)
                            ELSE ''
                        END
                    ) AS label
                FROM quotes q
                WHERE q.id = ?
                  AND q.tenant_id = ?
                  AND q.archived_at IS NULL
                LIMIT 1
            ",

            'invoice' => "
                SELECT
                    i.id,
                    i.client_id,
                    i.property_id,
                    CONCAT(
                        i.invoice_no,
                        ' · ',
                        i.status
                    ) AS label
                FROM invoices i
                WHERE i.id = ?
                  AND i.tenant_id = ?
                  AND i.archived_at IS NULL
                LIMIT 1
            ",

            'property' => "
                SELECT
                    p.id,
                    p.client_id,
                    p.id AS property_id,
                    CONCAT(
                        COALESCE(
                            NULLIF(p.name, ''),
                            p.address_line1
                        ),
                        ' · ',
                        p.address_line1
                    ) AS label
                FROM properties p
                WHERE p.id = ?
                  AND p.tenant_id = ?
                  AND p.deleted_at IS NULL
                LIMIT 1
            ",

            'route_plan' => "
                SELECT
                    rp.id,
                    NULL AS client_id,
                    NULL AS property_id,
                    CONCAT(
                        rp.name,
                        ' · ',
                        rp.route_date
                    ) AS label
                FROM route_plans rp
                WHERE rp.id = ?
                  AND rp.tenant_id = ?
                LIMIT 1
            ",

            'booking' => "
                SELECT
                    b.id,
                    b.client_id,
                    b.property_id,
                    CONCAT(
                        b.booking_no,
                        ' · ',
                        b.customer_name
                    ) AS label
                FROM bookings b
                WHERE b.id = ?
                  AND b.tenant_id = ?
                LIMIT 1
            "
        );

        if (!isset($queries[$relatedType])) {
            return null;
        }

        $stmt = $conn->prepare(
            $queries[$relatedType]
        );

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param(
            'ii',
            $relatedId,
            $tenantId
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $record =
            taskAddFetchAssoc($stmt);

        $stmt->close();

        return $record;
    }
}

if (!function_exists('taskAddLogActivity')) {
    function taskAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $taskId,
        $clientId,
        $title,
        $status,
        $priority,
        $assignedUserId,
        $relatedType,
        $relatedId,
        $dueAt
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
                'task_created',
                'task',
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
            'Task created: ' .
            $title;

        $details = json_encode(
            array(
                'task_id' =>
                    (int) $taskId,
                'title' =>
                    (string) $title,
                'status' =>
                    (string) $status,
                'priority' =>
                    (string) $priority,
                'assigned_user_id' =>
                    $assignedUserId !== null
                        ? (int) $assignedUserId
                        : null,
                'client_id' =>
                    $clientId !== null
                        ? (int) $clientId
                        : null,
                'related_type' =>
                    $relatedType !== null
                        ? (string) $relatedType
                        : null,
                'related_id' =>
                    $relatedId !== null
                        ? (int) $relatedId
                        : null,
                'due_at' =>
                    $dueAt
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $taskId,
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
| Load active users
|--------------------------------------------------------------------------
*/

$workers = array();
$workerMap = array();

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
        is_field_worker,
        is_bookable
    FROM users
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status = 'active'
    ORDER BY
        is_field_worker DESC,
        first_name ASC,
        last_name ASC
");

if (!$stmt) {
    $errors[] =
        'Unable to prepare the user query: ' .
        $conn->error;
} else {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if (!$stmt->execute()) {
        $errors[] =
            'Unable to load users: ' .
            $stmt->error;
    } else {
        $workers =
            taskAddFetchAll($stmt);
    }

    $stmt->close();
}

foreach ($workers as $worker) {
    $workerMap[(int) $worker['id']] =
        $worker;
}

/*
|--------------------------------------------------------------------------
| Load clients
|--------------------------------------------------------------------------
*/

$clients = array();
$clientMap = array();

$stmt = $conn->prepare("
    SELECT
        id,
        display_name,
        phone,
        email,
        status
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if (!$stmt) {
    $errors[] =
        'Unable to prepare the client query: ' .
        $conn->error;
} else {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if (!$stmt->execute()) {
        $errors[] =
            'Unable to load clients: ' .
            $stmt->error;
    } else {
        $clients =
            taskAddFetchAll($stmt);
    }

    $stmt->close();
}

foreach ($clients as $client) {
    $clientMap[(int) $client['id']] =
        $client;
}

/*
|--------------------------------------------------------------------------
| Load related records
|--------------------------------------------------------------------------
*/

$relatedOptions = array(
    'job' => array(),
    'visit' => array(),
    'work_order' => array(),
    'request' => array(),
    'quote' => array(),
    'invoice' => array(),
    'property' => array(),
    'route_plan' => array(),
    'booking' => array()
);

$relatedQueries = array(
    'job' => "
        SELECT
            id,
            client_id,
            property_id,
            CONCAT(job_no, ' · ', title)
                AS label
        FROM jobs
        WHERE tenant_id = ?
          AND deleted_at IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ",

    'visit' => "
        SELECT
            v.id,
            j.client_id,
            j.property_id,
            CONCAT(
                COALESCE(
                    NULLIF(v.visit_no, ''),
                    CONCAT('Visit #', v.id)
                ),
                ' · ',
                j.job_no,
                ' · ',
                j.title
            ) AS label
        FROM visits v
        INNER JOIN jobs j
            ON j.id = v.job_id
           AND j.tenant_id = v.tenant_id
           AND j.deleted_at IS NULL
        WHERE v.tenant_id = ?
        ORDER BY v.created_at DESC, v.id DESC
        LIMIT 500
    ",

    'work_order' => "
        SELECT
            id,
            client_id,
            property_id,
            CONCAT(
                work_order_no,
                ' · ',
                title
            ) AS label
        FROM work_orders
        WHERE tenant_id = ?
          AND deleted_at IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ",

    'request' => "
        SELECT
            id,
            client_id,
            property_id,
            CONCAT(
                request_no,
                ' · ',
                title
            ) AS label
        FROM requests
        WHERE tenant_id = ?
          AND archived_at IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ",

    'quote' => "
        SELECT
            id,
            client_id,
            property_id,
            CONCAT(
                quote_no,
                CASE
                    WHEN title IS NOT NULL
                     AND title <> ''
                    THEN CONCAT(' · ', title)
                    ELSE ''
                END
            ) AS label
        FROM quotes
        WHERE tenant_id = ?
          AND archived_at IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ",

    'invoice' => "
        SELECT
            id,
            client_id,
            property_id,
            CONCAT(
                invoice_no,
                ' · ',
                status
            ) AS label
        FROM invoices
        WHERE tenant_id = ?
          AND archived_at IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ",

    'property' => "
        SELECT
            id,
            client_id,
            id AS property_id,
            CONCAT(
                COALESCE(
                    NULLIF(name, ''),
                    address_line1
                ),
                ' · ',
                address_line1,
                CASE
                    WHEN city IS NOT NULL
                     AND city <> ''
                    THEN CONCAT(', ', city)
                    ELSE ''
                END
            ) AS label
        FROM properties
        WHERE tenant_id = ?
          AND deleted_at IS NULL
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ",

    'route_plan' => "
        SELECT
            id,
            NULL AS client_id,
            NULL AS property_id,
            CONCAT(
                name,
                ' · ',
                route_date
            ) AS label
        FROM route_plans
        WHERE tenant_id = ?
        ORDER BY route_date DESC, id DESC
        LIMIT 500
    ",

    'booking' => "
        SELECT
            id,
            client_id,
            property_id,
            CONCAT(
                booking_no,
                ' · ',
                customer_name
            ) AS label
        FROM bookings
        WHERE tenant_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    "
);

foreach (
    $relatedQueries as
    $relatedTypeKey => $relatedSql
) {
    $stmt = $conn->prepare(
        $relatedSql
    );

    if (!$stmt) {
        continue;
    }

    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $rows =
            taskAddFetchAll($stmt);

        foreach ($rows as $row) {
            $relatedOptions[$relatedTypeKey][] =
                array(
                    'id' =>
                        (int) $row['id'],
                    'client_id' =>
                        !empty($row['client_id'])
                            ? (int) $row['client_id']
                            : null,
                    'property_id' =>
                        !empty($row['property_id'])
                            ? (int) $row['property_id']
                            : null,
                    'label' =>
                        (string) $row['label']
                );
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| GET preselection
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($_GET['assigned_user_id'])) {
        $_POST['assigned_user_id'] =
            (string) (int) $_GET['assigned_user_id'];
    }

    if (!empty($_GET['client_id'])) {
        $_POST['client_id'] =
            (string) (int) $_GET['client_id'];
    }

    if (!empty($_GET['related_type'])) {
        $_POST['related_type'] =
            trim((string) $_GET['related_type']);
    }

    if (!empty($_GET['related_id'])) {
        $_POST['related_id'] =
            (string) (int) $_GET['related_id'];
    }

    if (!empty($_GET['due_at'])) {
        $_POST['due_at'] =
            trim((string) $_GET['due_at']);
    }
}

/*
|--------------------------------------------------------------------------
| Save task
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!taskAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $title =
        taskAddOld('title');

    $description =
        taskAddOld('description');

    $assignedUserId =
        isset($_POST['assigned_user_id']) &&
        (int) $_POST['assigned_user_id'] > 0
            ? (int) $_POST['assigned_user_id']
            : null;

    $clientId =
        isset($_POST['client_id']) &&
        (int) $_POST['client_id'] > 0
            ? (int) $_POST['client_id']
            : null;

    $relatedType =
        taskAddOld('related_type');

    $relatedId =
        isset($_POST['related_id']) &&
        (int) $_POST['related_id'] > 0
            ? (int) $_POST['related_id']
            : null;

    $dueAtInput =
        taskAddOld('due_at');

    $status =
        taskAddOld(
            'status',
            'open'
        );

    $priority =
        taskAddOld(
            'priority',
            'normal'
        );

    $allowedStatuses = array(
        'open',
        'in_progress',
        'completed',
        'cancelled',
        'overdue'
    );

    $allowedPriorities = array(
        'low',
        'normal',
        'high',
        'urgent'
    );

    $allowedRelatedTypes = array(
        '',
        'job',
        'visit',
        'work_order',
        'request',
        'quote',
        'invoice',
        'property',
        'route_plan',
        'booking'
    );

    if ($title === '') {
        $errors[] =
            'Task title is required.';
    } elseif (strlen($title) > 190) {
        $errors[] =
            'Task title cannot exceed 190 characters.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid task status.';
    }

    if (
        !in_array(
            $priority,
            $allowedPriorities,
            true
        )
    ) {
        $errors[] =
            'Please select a valid task priority.';
    }

    if (
        !in_array(
            $relatedType,
            $allowedRelatedTypes,
            true
        )
    ) {
        $errors[] =
            'Please select a valid related record type.';
    }

    if (
        $relatedType === '' &&
        $relatedId !== null
    ) {
        $errors[] =
            'Please select a related record type.';
    }

    if (
        $relatedType !== '' &&
        $relatedId === null
    ) {
        $errors[] =
            'Please select the related record.';
    }

    $dueAt =
        taskAddNormalizeDateTime(
            $dueAtInput
        );

    if (
        $dueAtInput !== '' &&
        $dueAt === null
    ) {
        $errors[] =
            'Please enter a valid due date and time.';
    }

    if (
        $assignedUserId !== null &&
        !isset($workerMap[$assignedUserId])
    ) {
        $errors[] =
            'The selected assigned user is not available.';
    }

    if (
        $clientId !== null &&
        !isset($clientMap[$clientId])
    ) {
        $errors[] =
            'The selected client is not available.';
    }

    $relatedRecord = null;

    if (
        empty($errors) &&
        $relatedType !== '' &&
        $relatedId !== null
    ) {
        $relatedRecord =
            taskAddRelatedRecord(
                $conn,
                $tenantId,
                $relatedType,
                $relatedId
            );

        if (!$relatedRecord) {
            $errors[] =
                'The selected related record is not available.';
        }
    }

    $propertyId = null;

    if (
        empty($errors) &&
        $relatedRecord
    ) {
        $relatedClientId =
            !empty($relatedRecord['client_id'])
                ? (int) $relatedRecord['client_id']
                : null;

        $propertyId =
            !empty($relatedRecord['property_id'])
                ? (int) $relatedRecord['property_id']
                : null;

        if (
            $clientId === null &&
            $relatedClientId !== null
        ) {
            $clientId =
                $relatedClientId;
        } elseif (
            $clientId !== null &&
            $relatedClientId !== null &&
            $clientId !== $relatedClientId
        ) {
            $errors[] =
                'The selected client does not match the related record.';
        }
    }

    if (
        empty($errors) &&
        $dueAt !== null &&
        strtotime($dueAt) < time() &&
        in_array(
            $status,
            array(
                'open',
                'in_progress'
            ),
            true
        )
    ) {
        $status =
            'overdue';
    }

    $completedAt =
        $status === 'completed'
            ? date('Y-m-d H:i:s')
            : null;

    if (empty($errors)) {
        $descriptionValue =
            taskAddNullable(
                $description
            );

        $relatedTypeValue =
            taskAddNullable(
                $relatedType
            );

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                INSERT INTO tasks (
                    tenant_id,
                    title,
                    description,
                    assigned_user_id,
                    client_id,
                    related_type,
                    related_id,
                    due_at,
                    status,
                    priority,
                    created_by,
                    completed_at,
                    created_at,
                    updated_at
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
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the task save operation: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'issiisisssis',
                $tenantId,
                $title,
                $descriptionValue,
                $assignedUserId,
                $clientId,
                $relatedTypeValue,
                $relatedId,
                $dueAt,
                $status,
                $priority,
                $currentUserId,
                $completedAt
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Task could not be saved: ' .
                    $stmt->error
                );
            }

            $taskId =
                (int) $stmt->insert_id;

            $stmt->close();

            /*
             * Add the task to Scheduling when a due date is supplied.
             */
            if ($dueAt !== null) {
                $scheduleStart = date(
                    'Y-m-d H:i:s',
                    strtotime(
                        $dueAt .
                        ' -30 minutes'
                    )
                );

                $scheduleEnd =
                    $dueAt;

                $scheduleStatus =
                    'scheduled';

                if ($status === 'completed') {
                    $scheduleStatus =
                        'completed';
                } elseif ($status === 'cancelled') {
                    $scheduleStatus =
                        'cancelled';
                } elseif ($status === 'overdue') {
                    $scheduleStatus =
                        'missed';
                }

                $scheduleColor =
                    '#2563eb';

                if ($priority === 'low') {
                    $scheduleColor =
                        '#64748b';
                } elseif ($priority === 'high') {
                    $scheduleColor =
                        '#ea580c';
                } elseif ($priority === 'urgent') {
                    $scheduleColor =
                        '#dc2626';
                }

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
                        'task',
                        'task',
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
                        ?,
                        ?,
                        NOW(),
                        NOW()
                    )
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare the task schedule event: ' .
                        $conn->error
                    );
                }

                $stmt->bind_param(
                    'iissiiissssi',
                    $tenantId,
                    $taskId,
                    $title,
                    $descriptionValue,
                    $assignedUserId,
                    $clientId,
                    $propertyId,
                    $scheduleStart,
                    $scheduleEnd,
                    $scheduleStatus,
                    $scheduleColor,
                    $currentUserId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to create the task schedule event: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            taskAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $taskId,
                $clientId,
                $title,
                $status,
                $priority,
                $assignedUserId,
                $relatedTypeValue,
                $relatedId,
                $dueAt
            );

            $_SESSION['flash_success'] =
                'Task created successfully.';

            header(
                'Location: tasks.php'
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
| Render values
|--------------------------------------------------------------------------
*/

$selectedWorkerId =
    (int) taskAddOld(
        'assigned_user_id'
    );

$selectedClientId =
    (int) taskAddOld(
        'client_id'
    );

$selectedRelatedType =
    taskAddOld(
        'related_type'
    );

$selectedRelatedId =
    (int) taskAddOld(
        'related_id'
    );

$selectedStatus =
    taskAddOld(
        'status',
        'open'
    );

$selectedPriority =
    taskAddOld(
        'priority',
        'normal'
    );

$csrfToken =
    taskAddCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.task-add-page {
    --ta-primary: #6d28d9;
    --ta-text: #111827;
    --ta-muted: #6b7280;
    --ta-border: #e5e7eb;
}

.ta-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.ta-header h1 {
    margin: 0;
    color: var(--ta-text);
    font-size: 21px;
    font-weight: 700;
}

.ta-header p {
    margin: 5px 0 0;
    color: var(--ta-muted);
    font-size: 11px;
}

.ta-back,
.ta-btn {
    min-height: 36px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 9px;
    font-family: inherit;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.ta-back,
.ta-btn.secondary {
    border: 1px solid var(--ta-border);
    background: #fff;
    color: #374151;
}

.ta-btn.primary {
    border: 0;
    background: var(--ta-primary);
    color: #fff;
    cursor: pointer;
}

.ta-btn.primary:disabled {
    cursor: not-allowed;
    opacity: .55;
}

.ta-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.ta-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(300px,.68fr);
    gap: 13px;
    align-items: start;
}

.ta-card {
    overflow: hidden;
    border: 1px solid var(--ta-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.ta-card + .ta-card {
    margin-top: 13px;
}

.ta-card-head {
    min-height: 46px;
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
}

.ta-card-head h2 {
    margin: 0;
    color: var(--ta-text);
    font-size: 11px;
    font-weight: 700;
}

.ta-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.ta-card-body {
    padding: 14px;
}

.ta-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.ta-field {
    min-width: 0;
}

.ta-field.full {
    grid-column: 1 / -1;
}

.ta-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.ta-required {
    color: #dc2626;
}

.ta-input,
.ta-select,
.ta-textarea {
    width: 100%;
    min-height: 38px;
    padding: 9px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 9px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 10px;
    outline: none;
}

.ta-textarea {
    min-height: 105px;
    resize: vertical;
}

.ta-input:focus,
.ta-select:focus,
.ta-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.ta-help {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.ta-summary {
    display: grid;
    gap: 9px;
}

.ta-summary-item {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.ta-summary-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.ta-summary-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.ta-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

@media (max-width: 1050px) {
    .ta-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .ta-header {
        flex-direction: column;
    }

    .ta-grid {
        grid-template-columns: 1fr;
    }

    .ta-field.full {
        grid-column: auto;
    }

    .ta-actions {
        flex-direction: column-reverse;
    }

    .ta-btn {
        width: 100%;
    }
}
</style>

<div class="task-add-page">
    <div class="ta-header">
        <div>
            <h1>Add Task</h1>
            <p>
                Create, assign, prioritize, schedule, and link an operational task.
            </p>
        </div>

        <a href="tasks.php" class="ta-back">
            <i class="bi bi-arrow-left"></i>
            Back to Tasks
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="ta-alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action=""
        autocomplete="off"
        id="taskAddForm"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken); ?>"
        >

        <div class="ta-layout">
            <main>
                <section class="ta-card">
                    <div class="ta-card-head">
                        <h2>Task Information</h2>
                        <p>
                            Enter the task title, instructions, assignment, and priority.
                        </p>
                    </div>

                    <div class="ta-card-body">
                        <div class="ta-grid">
                            <div class="ta-field full">
                                <label class="ta-label">
                                    Task Title
                                    <span class="ta-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    id="taskTitle"
                                    class="ta-input"
                                    maxlength="190"
                                    value="<?= e(
                                        taskAddOld('title')
                                    ); ?>"
                                    placeholder="Enter the task title"
                                    required
                                >
                            </div>

                            <div class="ta-field full">
                                <label class="ta-label">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    id="taskDescription"
                                    class="ta-textarea"
                                    placeholder="Enter instructions, follow-up information, or task notes."
                                ><?= e(
                                    taskAddOld(
                                        'description'
                                    )
                                ); ?></textarea>
                            </div>

                            <div class="ta-field">
                                <label class="ta-label">
                                    Assigned User
                                </label>

                                <select
                                    name="assigned_user_id"
                                    id="taskWorker"
                                    class="ta-select"
                                >
                                    <option value="">
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
                                            <?= $selectedWorkerId ===
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
                                                · <?= e(
                                                    $worker['job_title']
                                                ); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ta-field">
                                <label class="ta-label">
                                    Client
                                </label>

                                <select
                                    name="client_id"
                                    id="taskClient"
                                    class="ta-select"
                                >
                                    <option value="">
                                        No Client
                                    </option>

                                    <?php foreach ($clients as $client): ?>
                                        <option
                                            value="<?= (int) $client['id']; ?>"
                                            <?= $selectedClientId ===
                                                (int) $client['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e(
                                                $client['display_name']
                                            ); ?>

                                            <?php if (
                                                trim(
                                                    (string) $client['phone']
                                                ) !== ''
                                            ): ?>
                                                · <?= e(
                                                    $client['phone']
                                                ); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ta-field">
                                <label class="ta-label">
                                    Priority
                                </label>

                                <select
                                    name="priority"
                                    id="taskPriority"
                                    class="ta-select"
                                >
                                    <option
                                        value="low"
                                        <?= $selectedPriority === 'low'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Low
                                    </option>

                                    <option
                                        value="normal"
                                        <?= $selectedPriority === 'normal'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Normal
                                    </option>

                                    <option
                                        value="high"
                                        <?= $selectedPriority === 'high'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        High
                                    </option>

                                    <option
                                        value="urgent"
                                        <?= $selectedPriority === 'urgent'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Urgent
                                    </option>
                                </select>
                            </div>

                            <div class="ta-field">
                                <label class="ta-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    id="taskStatus"
                                    class="ta-select"
                                >
                                    <option
                                        value="open"
                                        <?= $selectedStatus === 'open'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Open
                                    </option>

                                    <option
                                        value="in_progress"
                                        <?= $selectedStatus === 'in_progress'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        In Progress
                                    </option>

                                    <option
                                        value="completed"
                                        <?= $selectedStatus === 'completed'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Completed
                                    </option>

                                    <option
                                        value="overdue"
                                        <?= $selectedStatus === 'overdue'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Overdue
                                    </option>

                                    <option
                                        value="cancelled"
                                        <?= $selectedStatus === 'cancelled'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Cancelled
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ta-card">
                    <div class="ta-card-head">
                        <h2>Schedule and Related Record</h2>
                        <p>
                            Set a due date and optionally link this task to another record.
                        </p>
                    </div>

                    <div class="ta-card-body">
                        <div class="ta-grid">
                            <div class="ta-field full">
                                <label class="ta-label">
                                    Due Date and Time
                                </label>

                                <input
                                    type="datetime-local"
                                    name="due_at"
                                    id="taskDueAt"
                                    class="ta-input"
                                    value="<?= e(
                                        taskAddOld('due_at')
                                    ); ?>"
                                >

                                <div class="ta-help">
                                    A scheduled task event is created automatically when a due date is entered.
                                </div>
                            </div>

                            <div class="ta-field">
                                <label class="ta-label">
                                    Related Record Type
                                </label>

                                <select
                                    name="related_type"
                                    id="taskRelatedType"
                                    class="ta-select"
                                >
                                    <option value="">
                                        No Related Record
                                    </option>

                                    <?php foreach (
                                        array(
                                            'job' => 'Job',
                                            'visit' => 'Visit',
                                            'work_order' => 'Work Order',
                                            'request' => 'Request',
                                            'quote' => 'Quote',
                                            'invoice' => 'Invoice',
                                            'property' => 'Property',
                                            'route_plan' => 'Route',
                                            'booking' => 'Booking'
                                        ) as $value => $label
                                    ): ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= $selectedRelatedType === $value
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ta-field">
                                <label class="ta-label">
                                    Related Record
                                </label>

                                <select
                                    name="related_id"
                                    id="taskRelatedId"
                                    class="ta-select"
                                    <?= $selectedRelatedType === ''
                                        ? 'disabled'
                                        : ''; ?>
                                >
                                    <option value="">
                                        <?= $selectedRelatedType === ''
                                            ? 'Select a record type first'
                                            : 'Select Related Record'; ?>
                                    </option>
                                </select>

                                <div class="ta-help">
                                    Selecting a related record can automatically fill the matching client.
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="ta-card">
                    <div class="ta-card-head">
                        <h2>Task Summary</h2>
                        <p>
                            Review the main task information before saving.
                        </p>
                    </div>

                    <div class="ta-card-body">
                        <div class="ta-summary">
                            <div class="ta-summary-item">
                                <span class="ta-summary-label">
                                    Task
                                </span>

                                <span
                                    class="ta-summary-value"
                                    id="summaryTaskTitle"
                                >
                                    Not entered
                                </span>
                            </div>

                            <div class="ta-summary-item">
                                <span class="ta-summary-label">
                                    Assigned User
                                </span>

                                <span
                                    class="ta-summary-value"
                                    id="summaryTaskWorker"
                                >
                                    Unassigned
                                </span>
                            </div>

                            <div class="ta-summary-item">
                                <span class="ta-summary-label">
                                    Client
                                </span>

                                <span
                                    class="ta-summary-value"
                                    id="summaryTaskClient"
                                >
                                    No client
                                </span>
                            </div>

                            <div class="ta-summary-item">
                                <span class="ta-summary-label">
                                    Priority
                                </span>

                                <span
                                    class="ta-summary-value"
                                    id="summaryTaskPriority"
                                >
                                    Normal
                                </span>
                            </div>

                            <div class="ta-summary-item">
                                <span class="ta-summary-label">
                                    Status
                                </span>

                                <span
                                    class="ta-summary-value"
                                    id="summaryTaskStatus"
                                >
                                    Open
                                </span>
                            </div>

                            <div class="ta-summary-item">
                                <span class="ta-summary-label">
                                    Due
                                </span>

                                <span
                                    class="ta-summary-value"
                                    id="summaryTaskDue"
                                >
                                    No due date
                                </span>
                            </div>

                            <div class="ta-summary-item">
                                <span class="ta-summary-label">
                                    Related Record
                                </span>

                                <span
                                    class="ta-summary-value"
                                    id="summaryTaskRelated"
                                >
                                    No related record
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ta-actions">
                        <a
                            href="tasks.php"
                            class="ta-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="ta-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Save Task
                        </button>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var relatedOptions = <?= json_encode(
        $relatedOptions,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var initialRelatedId =
        <?= (int) $selectedRelatedId; ?>;

    var titleInput =
        document.getElementById(
            'taskTitle'
        );

    var workerInput =
        document.getElementById(
            'taskWorker'
        );

    var clientInput =
        document.getElementById(
            'taskClient'
        );

    var priorityInput =
        document.getElementById(
            'taskPriority'
        );

    var statusInput =
        document.getElementById(
            'taskStatus'
        );

    var dueInput =
        document.getElementById(
            'taskDueAt'
        );

    var relatedTypeInput =
        document.getElementById(
            'taskRelatedType'
        );

    var relatedIdInput =
        document.getElementById(
            'taskRelatedId'
        );

    function selectedText(
        select,
        fallback
    ) {
        var option =
            select.options[
                select.selectedIndex
            ];

        if (
            !option ||
            option.value === ''
        ) {
            return fallback;
        }

        return option.textContent
            .replace(/\s+/g, ' ')
            .trim();
    }

    function formatDueDate(value) {
        if (!value) {
            return 'No due date';
        }

        var date =
            new Date(value);

        if (isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString(
            'en-IN',
            {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }
        );
    }

    function populateRelatedRecords(
        selectedId
    ) {
        var relatedType =
            relatedTypeInput.value;

        relatedIdInput.innerHTML = '';

        if (
            relatedType === '' ||
            !relatedOptions[relatedType]
        ) {
            var blankOption =
                document.createElement(
                    'option'
                );

            blankOption.value = '';
            blankOption.textContent =
                'Select a record type first';

            relatedIdInput.appendChild(
                blankOption
            );

            relatedIdInput.disabled = true;

            updateSummary();
            return;
        }

        var firstOption =
            document.createElement(
                'option'
            );

        firstOption.value = '';
        firstOption.textContent =
            'Select Related Record';

        relatedIdInput.appendChild(
            firstOption
        );

        relatedOptions[relatedType].forEach(
            function (record) {
                var option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    String(record.id);

                option.textContent =
                    record.label;

                option.dataset.clientId =
                    record.client_id !== null
                        ? String(
                            record.client_id
                        )
                        : '';

                option.dataset.propertyId =
                    record.property_id !== null
                        ? String(
                            record.property_id
                        )
                        : '';

                if (
                    String(selectedId || '') ===
                    String(record.id)
                ) {
                    option.selected = true;
                }

                relatedIdInput.appendChild(
                    option
                );
            }
        );

        relatedIdInput.disabled = false;

        updateSummary();
    }

    function updateSummary() {
        document.getElementById(
            'summaryTaskTitle'
        ).textContent =
            titleInput.value.trim() !== ''
                ? titleInput.value.trim()
                : 'Not entered';

        document.getElementById(
            'summaryTaskWorker'
        ).textContent =
            selectedText(
                workerInput,
                'Unassigned'
            );

        document.getElementById(
            'summaryTaskClient'
        ).textContent =
            selectedText(
                clientInput,
                'No client'
            );

        document.getElementById(
            'summaryTaskPriority'
        ).textContent =
            selectedText(
                priorityInput,
                'Normal'
            );

        document.getElementById(
            'summaryTaskStatus'
        ).textContent =
            selectedText(
                statusInput,
                'Open'
            );

        document.getElementById(
            'summaryTaskDue'
        ).textContent =
            formatDueDate(
                dueInput.value
            );

        document.getElementById(
            'summaryTaskRelated'
        ).textContent =
            selectedText(
                relatedIdInput,
                'No related record'
            );
    }

    relatedTypeInput.addEventListener(
        'change',
        function () {
            populateRelatedRecords('');
        }
    );

    relatedIdInput.addEventListener(
        'change',
        function () {
            var selected =
                relatedIdInput.options[
                    relatedIdInput.selectedIndex
                ];

            if (
                selected &&
                selected.dataset.clientId &&
                clientInput.value === ''
            ) {
                clientInput.value =
                    selected.dataset.clientId;
            }

            updateSummary();
        }
    );

    [
        titleInput,
        workerInput,
        clientInput,
        priorityInput,
        statusInput,
        dueInput
    ].forEach(function (element) {
        element.addEventListener(
            'input',
            updateSummary
        );

        element.addEventListener(
            'change',
            updateSummary
        );
    });

    document.getElementById(
        'taskAddForm'
    ).addEventListener(
        'submit',
        function (event) {
            if (
                relatedTypeInput.value !== '' &&
                relatedIdInput.value === ''
            ) {
                event.preventDefault();

                window.alert(
                    'Please select the related record.'
                );
            }
        }
    );

    populateRelatedRecords(
        initialRelatedId
    );

    if (
        initialRelatedId > 0 &&
        relatedIdInput.value !== ''
    ) {
        var initialOption =
            relatedIdInput.options[
                relatedIdInput.selectedIndex
            ];

        if (
            initialOption &&
            initialOption.dataset.clientId &&
            clientInput.value === ''
        ) {
            clientInput.value =
                initialOption.dataset.clientId;
        }
    }

    updateSummary();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
