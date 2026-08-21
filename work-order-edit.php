<?php
/**
 * FieldPlx - Edit Work Order
 *
 * Upload as:
 * /public_html/work-order-edit.php
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
| Work Orders belong to the Jobs module in the current permission setup.
|
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode(
            'work-order-edit.php?id=' .
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
        'jobs.manage',
        'You do not have permission to edit work orders.'
    );
}

$pageTitle = 'Edit Work Order - FieldPlx';
$activePage = 'work-orders';
$searchPlaceholder = 'Search work orders...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

$workOrderId = isset($_POST['id'])
    ? (int) $_POST['id']
    : (
        isset($_GET['id'])
            ? (int) $_GET['id']
            : 0
    );

if ($workOrderId <= 0) {
    $_SESSION['flash_error'] =
        'Invalid work order selected.';

    header('Location: work-orders.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('workOrderEditFetchAssoc')) {
    function workOrderEditFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('workOrderEditFetchAll')) {
    function workOrderEditFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('workOrderEditOld')) {
    function workOrderEditOld(
        $key,
        $default = ''
    ) {
        if (
            isset($_POST[$key]) &&
            !is_array($_POST[$key])
        ) {
            return trim((string) $_POST[$key]);
        }

        return $default;
    }
}

if (!function_exists('workOrderEditNullable')) {
    function workOrderEditNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('workOrderEditNormalizeDateTime')) {
    function workOrderEditNormalizeDateTime($value)
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

if (!function_exists('workOrderEditLocalDateTime')) {
    function workOrderEditLocalDateTime($value)
    {
        if (empty($value)) {
            return '';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('Y-m-d\TH:i', $timestamp)
            : '';
    }
}

if (!function_exists('workOrderEditCsrfToken')) {
    function workOrderEditCsrfToken()
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

if (!function_exists('workOrderEditVerifyCsrf')) {
    function workOrderEditVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('workOrderEditLogActivity')) {
    function workOrderEditLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $workOrderId,
        $clientId,
        $workOrderNo,
        $title,
        array $oldValues,
        array $newValues
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
                'work_order_updated',
                'work_order',
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
            'Work order updated: ' .
            $workOrderNo .
            ' - ' .
            $title;

        $details = json_encode(
            array(
                'work_order_id' =>
                    (int) $workOrderId,
                'work_order_no' =>
                    (string) $workOrderNo,
                'old_values' =>
                    $oldValues,
                'new_values' =>
                    $newValues
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $workOrderId,
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
| Load existing work order
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        wo.id,
        wo.tenant_id,
        wo.work_order_no,
        wo.job_id,
        wo.client_id,
        wo.property_id,
        wo.title,
        wo.work_description,
        wo.safety_instructions,
        wo.scheduled_start,
        wo.scheduled_end,
        wo.actual_start,
        wo.actual_end,
        wo.status,
        wo.completion_notes,
        wo.signature_attachment_id,
        wo.issued_by,
        wo.issued_at,
        wo.accepted_at,
        wo.completed_at,
        wo.created_by,
        wo.updated_by,
        wo.created_at,
        wo.updated_at,

        j.job_no,
        j.title AS job_title,
        j.status AS job_status,
        j.assigned_user_id AS job_assigned_user_id,

        c.display_name AS client_name,

        p.name AS property_name,
        p.address_line1 AS property_address_line1,
        p.address_line2 AS property_address_line2,
        p.city AS property_city,
        p.state AS property_state,
        p.postal_code AS property_postal_code,

        sa.file_name AS signature_file_name,
        sa.file_path AS signature_file_path

    FROM work_orders wo

    INNER JOIN jobs j
        ON j.id = wo.job_id
       AND j.tenant_id = wo.tenant_id

    INNER JOIN clients c
        ON c.id = wo.client_id
       AND c.tenant_id = wo.tenant_id

    LEFT JOIN properties p
        ON p.id = wo.property_id
       AND p.tenant_id = wo.tenant_id

    LEFT JOIN attachments sa
        ON sa.id = wo.signature_attachment_id
       AND sa.tenant_id = wo.tenant_id

    WHERE wo.id = ?
      AND wo.tenant_id = ?
      AND wo.deleted_at IS NULL

    LIMIT 1
");

if (!$stmt) {
    $_SESSION['flash_error'] =
        'Unable to load the work order: ' .
        $conn->error;

    header('Location: work-orders.php');
    exit;
}

$stmt->bind_param(
    'ii',
    $workOrderId,
    $tenantId
);

if (!$stmt->execute()) {
    $stmt->close();

    $_SESSION['flash_error'] =
        'Unable to load the selected work order.';

    header('Location: work-orders.php');
    exit;
}

$workOrder =
    workOrderEditFetchAssoc($stmt);

$stmt->close();

if (!$workOrder) {
    $_SESSION['flash_error'] =
        'Work order not found or access denied.';

    header('Location: work-orders.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load selectable jobs
|--------------------------------------------------------------------------
|
| The work order's current job remains selectable even if that job has since
| been closed or cancelled.
|
*/

$jobs = array();
$jobMap = array();

$stmt = $conn->prepare("
    SELECT
        j.id,
        j.job_no,
        j.client_id,
        j.property_id,
        j.title,
        j.description,
        j.status,
        j.assigned_user_id,
        j.start_date,
        j.end_date,
        j.total,

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
        ) AS assigned_user_name

    FROM jobs j

    INNER JOIN clients c
        ON c.id = j.client_id
       AND c.tenant_id = j.tenant_id
       AND c.deleted_at IS NULL

    LEFT JOIN properties p
        ON p.id = j.property_id
       AND p.tenant_id = j.tenant_id
       AND p.deleted_at IS NULL

    LEFT JOIN users u
        ON u.id = j.assigned_user_id
       AND u.tenant_id = j.tenant_id
       AND u.deleted_at IS NULL

    WHERE j.tenant_id = ?
      AND j.deleted_at IS NULL
      AND (
          j.status NOT IN (
              'cancelled',
              'archived',
              'closed'
          )
          OR j.id = ?
      )

    ORDER BY
        CASE
            WHEN j.id = ? THEN 0
            ELSE 1
        END,
        j.created_at DESC,
        j.id DESC
");

if (!$stmt) {
    $errors[] =
        'Unable to prepare the jobs query: ' .
        $conn->error;
} else {
    $currentJobId =
        (int) $workOrder['job_id'];

    $stmt->bind_param(
        'iii',
        $tenantId,
        $currentJobId,
        $currentJobId
    );

    if (!$stmt->execute()) {
        $errors[] =
            'Unable to load jobs: ' .
            $stmt->error;
    } else {
        $jobs =
            workOrderEditFetchAll($stmt);
    }

    $stmt->close();
}

foreach ($jobs as $job) {
    $propertyParts = array_filter(
        array(
            $job['property_address_line1'],
            $job['property_address_line2'],
            $job['property_city'],
            $job['property_state'],
            $job['property_postal_code']
        ),
        function ($value) {
            return trim((string) $value) !== '';
        }
    );

    $propertyLabel =
        trim((string) $job['property_name']) !== ''
            ? (string) $job['property_name']
            : (
                !empty($propertyParts)
                    ? implode(', ', $propertyParts)
                    : 'No property'
            );

    $propertyAddress =
        !empty($propertyParts)
            ? implode(', ', $propertyParts)
            : '—';

    $jobMap[(int) $job['id']] = array(
        'id' =>
            (int) $job['id'],
        'job_no' =>
            (string) $job['job_no'],
        'title' =>
            (string) $job['title'],
        'description' =>
            (string) $job['description'],
        'status' =>
            (string) $job['status'],
        'client_id' =>
            (int) $job['client_id'],
        'client_name' =>
            (string) $job['client_name'],
        'client_phone' =>
            (string) $job['client_phone'],
        'client_email' =>
            (string) $job['client_email'],
        'property_id' =>
            !empty($job['property_id'])
                ? (int) $job['property_id']
                : null,
        'property_name' =>
            $propertyLabel,
        'property_address' =>
            $propertyAddress,
        'assigned_user_id' =>
            !empty($job['assigned_user_id'])
                ? (int) $job['assigned_user_id']
                : null,
        'assigned_user_name' =>
            trim(
                (string) $job['assigned_user_name']
            ),
        'start_date' =>
            (string) $job['start_date'],
        'end_date' =>
            (string) $job['end_date'],
        'total' =>
            (float) $job['total']
    );
}

/*
|--------------------------------------------------------------------------
| Save changes
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!workOrderEditVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $jobId =
        isset($_POST['job_id'])
            ? (int) $_POST['job_id']
            : 0;

    $title =
        workOrderEditOld('title');

    $workDescription =
        workOrderEditOld('work_description');

    $safetyInstructions =
        workOrderEditOld('safety_instructions');

    $scheduledStartInput =
        workOrderEditOld('scheduled_start');

    $scheduledEndInput =
        workOrderEditOld('scheduled_end');

    $actualStartInput =
        workOrderEditOld('actual_start');

    $actualEndInput =
        workOrderEditOld('actual_end');

    $status =
        workOrderEditOld(
            'status',
            (string) $workOrder['status']
        );

    $completionNotes =
        workOrderEditOld('completion_notes');

    $allowedStatuses = array(
        'draft',
        'issued',
        'accepted',
        'in_progress',
        'completed',
        'rejected',
        'cancelled'
    );

    if ($jobId <= 0) {
        $errors[] =
            'Please select a job.';
    }

    if ($title === '') {
        $errors[] =
            'Work order title is required.';
    }

    if (strlen($title) > 190) {
        $errors[] =
            'Work order title cannot exceed 190 characters.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid work order status.';
    }

    $scheduledStart =
        workOrderEditNormalizeDateTime(
            $scheduledStartInput
        );

    $scheduledEnd =
        workOrderEditNormalizeDateTime(
            $scheduledEndInput
        );

    $actualStart =
        workOrderEditNormalizeDateTime(
            $actualStartInput
        );

    $actualEnd =
        workOrderEditNormalizeDateTime(
            $actualEndInput
        );

    if (
        $scheduledStartInput !== '' &&
        $scheduledStart === null
    ) {
        $errors[] =
            'Please enter a valid scheduled start date and time.';
    }

    if (
        $scheduledEndInput !== '' &&
        $scheduledEnd === null
    ) {
        $errors[] =
            'Please enter a valid scheduled end date and time.';
    }

    if (
        $actualStartInput !== '' &&
        $actualStart === null
    ) {
        $errors[] =
            'Please enter a valid actual start date and time.';
    }

    if (
        $actualEndInput !== '' &&
        $actualEnd === null
    ) {
        $errors[] =
            'Please enter a valid actual end date and time.';
    }

    if (
        empty($errors) &&
        $scheduledStart === null &&
        $scheduledEnd !== null
    ) {
        $errors[] =
            'Scheduled start is required when scheduled end is entered.';
    }

    if (
        empty($errors) &&
        $scheduledStart !== null &&
        $scheduledEnd === null
    ) {
        $scheduledEnd = date(
            'Y-m-d H:i:s',
            strtotime(
                $scheduledStart .
                ' +1 hour'
            )
        );
    }

    if (
        empty($errors) &&
        $scheduledStart !== null &&
        $scheduledEnd !== null &&
        strtotime($scheduledEnd) <=
            strtotime($scheduledStart)
    ) {
        $errors[] =
            'Scheduled end must be after scheduled start.';
    }

    if (
        empty($errors) &&
        $actualStart === null &&
        $actualEnd !== null
    ) {
        $errors[] =
            'Actual start is required when actual end is entered.';
    }

    if (
        empty($errors) &&
        $actualStart !== null &&
        $actualEnd !== null &&
        strtotime($actualEnd) <=
            strtotime($actualStart)
    ) {
        $errors[] =
            'Actual end must be after actual start.';
    }

    /*
     * Validate selected job and get client/property/worker details.
     */
    $selectedJob = null;

    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT
                j.id,
                j.job_no,
                j.client_id,
                j.property_id,
                j.title,
                j.description,
                j.status,
                j.assigned_user_id,

                c.display_name AS client_name,

                p.name AS property_name,
                p.address_line1 AS property_address

            FROM jobs j

            INNER JOIN clients c
                ON c.id = j.client_id
               AND c.tenant_id = j.tenant_id
               AND c.deleted_at IS NULL

            LEFT JOIN properties p
                ON p.id = j.property_id
               AND p.tenant_id = j.tenant_id
               AND p.deleted_at IS NULL

            WHERE j.id = ?
              AND j.tenant_id = ?
              AND j.deleted_at IS NULL
              AND (
                  j.status NOT IN (
                      'cancelled',
                      'archived',
                      'closed'
                  )
                  OR j.id = ?
              )

            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected job: ' .
                $conn->error;
        } else {
            $originalJobId =
                (int) $workOrder['job_id'];

            $stmt->bind_param(
                'iii',
                $jobId,
                $tenantId,
                $originalJobId
            );

            if (!$stmt->execute()) {
                $errors[] =
                    'Unable to validate the selected job: ' .
                    $stmt->error;
            } else {
                $selectedJob =
                    workOrderEditFetchAssoc($stmt);

                if (!$selectedJob) {
                    $errors[] =
                        'The selected job is not available.';
                }
            }

            $stmt->close();
        }
    }

    if (empty($errors) && $selectedJob) {
        $clientId =
            (int) $selectedJob['client_id'];

        $propertyId =
            !empty($selectedJob['property_id'])
                ? (int) $selectedJob['property_id']
                : null;

        $assignedUserId =
            !empty($selectedJob['assigned_user_id'])
                ? (int) $selectedJob['assigned_user_id']
                : null;

        /*
         * Preserve existing milestone dates and fill only missing dates when
         * the edited status reaches each stage.
         */
        $issuedBy =
            !empty($workOrder['issued_by'])
                ? (int) $workOrder['issued_by']
                : null;

        $issuedAt =
            workOrderEditNullable(
                $workOrder['issued_at']
            );

        $acceptedAt =
            workOrderEditNullable(
                $workOrder['accepted_at']
            );

        $completedAt =
            workOrderEditNullable(
                $workOrder['completed_at']
            );

        $now =
            date('Y-m-d H:i:s');

        if (
            in_array(
                $status,
                array(
                    'issued',
                    'accepted',
                    'in_progress',
                    'completed'
                ),
                true
            ) &&
            $issuedAt === null
        ) {
            $issuedBy =
                $currentUserId;

            $issuedAt =
                $now;
        }

        if (
            in_array(
                $status,
                array(
                    'accepted',
                    'in_progress',
                    'completed'
                ),
                true
            ) &&
            $acceptedAt === null
        ) {
            $acceptedAt =
                $now;
        }

        if (
            in_array(
                $status,
                array(
                    'in_progress',
                    'completed'
                ),
                true
            ) &&
            $actualStart === null
        ) {
            $actualStart =
                !empty($workOrder['actual_start'])
                    ? (string) $workOrder['actual_start']
                    : $now;
        }

        if ($status === 'completed') {
            if ($actualEnd === null) {
                $actualEnd =
                    !empty($workOrder['actual_end'])
                        ? (string) $workOrder['actual_end']
                        : $now;
            }

            if ($completedAt === null) {
                $completedAt =
                    $now;
            }
        }

        $workDescriptionValue =
            workOrderEditNullable(
                $workDescription
            );

        $safetyInstructionsValue =
            workOrderEditNullable(
                $safetyInstructions
            );

        $completionNotesValue =
            workOrderEditNullable(
                $completionNotes
            );

        $oldValues = array(
            'job_id' =>
                (int) $workOrder['job_id'],
            'client_id' =>
                (int) $workOrder['client_id'],
            'property_id' =>
                !empty($workOrder['property_id'])
                    ? (int) $workOrder['property_id']
                    : null,
            'title' =>
                (string) $workOrder['title'],
            'work_description' =>
                $workOrder['work_description'],
            'safety_instructions' =>
                $workOrder['safety_instructions'],
            'scheduled_start' =>
                $workOrder['scheduled_start'],
            'scheduled_end' =>
                $workOrder['scheduled_end'],
            'actual_start' =>
                $workOrder['actual_start'],
            'actual_end' =>
                $workOrder['actual_end'],
            'status' =>
                (string) $workOrder['status'],
            'completion_notes' =>
                $workOrder['completion_notes']
        );

        $newValues = array(
            'job_id' =>
                $jobId,
            'client_id' =>
                $clientId,
            'property_id' =>
                $propertyId,
            'title' =>
                $title,
            'work_description' =>
                $workDescriptionValue,
            'safety_instructions' =>
                $safetyInstructionsValue,
            'scheduled_start' =>
                $scheduledStart,
            'scheduled_end' =>
                $scheduledEnd,
            'actual_start' =>
                $actualStart,
            'actual_end' =>
                $actualEnd,
            'status' =>
                $status,
            'completion_notes' =>
                $completionNotesValue
        );

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                UPDATE work_orders
                SET
                    job_id = ?,
                    client_id = ?,
                    property_id = ?,
                    title = ?,
                    work_description = ?,
                    safety_instructions = ?,
                    scheduled_start = ?,
                    scheduled_end = ?,
                    actual_start = ?,
                    actual_end = ?,
                    status = ?,
                    completion_notes = ?,
                    issued_by = ?,
                    issued_at = ?,
                    accepted_at = ?,
                    completed_at = ?,
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                  AND deleted_at IS NULL
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the work order update: ' .
                    $conn->error
                );
            }

            /*
             * 19 variables / 19 type characters.
             */
            $stmt->bind_param(
                'iiisssssssssisssiii',
                $jobId,
                $clientId,
                $propertyId,
                $title,
                $workDescriptionValue,
                $safetyInstructionsValue,
                $scheduledStart,
                $scheduledEnd,
                $actualStart,
                $actualEnd,
                $status,
                $completionNotesValue,
                $issuedBy,
                $issuedAt,
                $acceptedAt,
                $completedAt,
                $currentUserId,
                $workOrderId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Work order could not be updated: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            /*
             * Find the schedule event created for this work order.
             */
            $existingEvent = null;

            $stmt = $conn->prepare("
                SELECT
                    id,
                    assigned_user_id,
                    start_at,
                    end_at,
                    status
                FROM schedule_events
                WHERE tenant_id = ?
                  AND related_type = 'work_order'
                  AND related_id = ?
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to load the work order schedule event: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'ii',
                $tenantId,
                $workOrderId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to load the work order schedule event: ' .
                    $stmt->error
                );
            }

            $existingEvent =
                workOrderEditFetchAssoc($stmt);

            $stmt->close();

            if (
                $scheduledStart !== null &&
                $scheduledEnd !== null
            ) {
                $scheduleStatus = 'scheduled';

                if ($status === 'completed') {
                    $scheduleStatus =
                        'completed';
                } elseif (
                    in_array(
                        $status,
                        array(
                            'rejected',
                            'cancelled'
                        ),
                        true
                    )
                ) {
                    $scheduleStatus =
                        'cancelled';
                }

                $scheduleTitle =
                    $workOrder['work_order_no'] .
                    ' - ' .
                    $title;

                $scheduleDescription =
                    $workDescriptionValue;

                if ($existingEvent) {
                    $scheduleChanged =
                        (string) $existingEvent['start_at'] !==
                            (string) $scheduledStart ||
                        (string) $existingEvent['end_at'] !==
                            (string) $scheduledEnd ||
                        (int) $existingEvent['assigned_user_id'] !==
                            (int) $assignedUserId;

                    if ($scheduleChanged) {
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
                                'Unable to prepare schedule history: ' .
                                $conn->error
                            );
                        }

                        $scheduleEventId =
                            (int) $existingEvent['id'];

                        $oldAssignedUserId =
                            !empty($existingEvent['assigned_user_id'])
                                ? (int) $existingEvent['assigned_user_id']
                                : null;

                        $oldStartAt =
                            workOrderEditNullable(
                                $existingEvent['start_at']
                            );

                        $oldEndAt =
                            workOrderEditNullable(
                                $existingEvent['end_at']
                            );

                        $reason =
                            'Work order schedule updated';

                        $stmt->bind_param(
                            'iiiisssssi',
                            $tenantId,
                            $scheduleEventId,
                            $oldAssignedUserId,
                            $assignedUserId,
                            $oldStartAt,
                            $oldEndAt,
                            $scheduledStart,
                            $scheduledEnd,
                            $reason,
                            $currentUserId
                        );

                        if (!$stmt->execute()) {
                            throw new Exception(
                                'Unable to record schedule history: ' .
                                $stmt->error
                            );
                        }

                        $stmt->close();
                    }

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
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?
                          AND tenant_id = ?
                        LIMIT 1
                    ");

                    if (!$stmt) {
                        throw new Exception(
                            'Unable to prepare the schedule event update: ' .
                            $conn->error
                        );
                    }

                    $scheduleEventId =
                        (int) $existingEvent['id'];

                    $stmt->bind_param(
                        'ssiiisssii',
                        $scheduleTitle,
                        $scheduleDescription,
                        $assignedUserId,
                        $clientId,
                        $propertyId,
                        $scheduledStart,
                        $scheduledEnd,
                        $scheduleStatus,
                        $scheduleEventId,
                        $tenantId
                    );

                    if (!$stmt->execute()) {
                        throw new Exception(
                            'Unable to update the schedule event: ' .
                            $stmt->error
                        );
                    }

                    $stmt->close();
                } else {
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
                            created_by,
                            created_at,
                            updated_at
                        ) VALUES (
                            ?,
                            'event',
                            'work_order',
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
                            NOW(),
                            NOW()
                        )
                    ");

                    if (!$stmt) {
                        throw new Exception(
                            'Unable to prepare the schedule event: ' .
                            $conn->error
                        );
                    }

                    $stmt->bind_param(
                        'iissiiisssi',
                        $tenantId,
                        $workOrderId,
                        $scheduleTitle,
                        $scheduleDescription,
                        $assignedUserId,
                        $clientId,
                        $propertyId,
                        $scheduledStart,
                        $scheduledEnd,
                        $scheduleStatus,
                        $currentUserId
                    );

                    if (!$stmt->execute()) {
                        throw new Exception(
                            'Unable to create the schedule event: ' .
                            $stmt->error
                        );
                    }

                    $stmt->close();
                }
            } elseif ($existingEvent) {
                /*
                 * The schedule was removed from the work order. Delete only
                 * the generated event linked directly to this work order.
                 */
                $stmt = $conn->prepare("
                    DELETE FROM schedule_events
                    WHERE id = ?
                      AND tenant_id = ?
                      AND related_type = 'work_order'
                      AND related_id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare schedule removal: ' .
                        $conn->error
                    );
                }

                $scheduleEventId =
                    (int) $existingEvent['id'];

                $stmt->bind_param(
                    'iii',
                    $scheduleEventId,
                    $tenantId,
                    $workOrderId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to remove the schedule event: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            workOrderEditLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $workOrderId,
                $clientId,
                (string) $workOrder['work_order_no'],
                $title,
                $oldValues,
                $newValues
            );

            $_SESSION['flash_success'] =
                'Work order updated successfully.';

            header(
                'Location: work-order-view.php?id=' .
                $workOrderId
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

$selectedJobId =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (int) workOrderEditOld('job_id')
        : (int) $workOrder['job_id'];

$titleValue =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? workOrderEditOld('title')
        : (string) $workOrder['title'];

$workDescriptionValue =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? workOrderEditOld('work_description')
        : (string) $workOrder['work_description'];

$safetyInstructionsValue =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? workOrderEditOld('safety_instructions')
        : (string) $workOrder['safety_instructions'];

$scheduledStartValue =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? workOrderEditOld('scheduled_start')
        : workOrderEditLocalDateTime(
            $workOrder['scheduled_start']
        );

$scheduledEndValue =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? workOrderEditOld('scheduled_end')
        : workOrderEditLocalDateTime(
            $workOrder['scheduled_end']
        );

$actualStartValue =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? workOrderEditOld('actual_start')
        : workOrderEditLocalDateTime(
            $workOrder['actual_start']
        );

$actualEndValue =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? workOrderEditOld('actual_end')
        : workOrderEditLocalDateTime(
            $workOrder['actual_end']
        );

$statusValue =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? workOrderEditOld(
            'status',
            (string) $workOrder['status']
        )
        : (string) $workOrder['status'];

$completionNotesValue =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? workOrderEditOld('completion_notes')
        : (string) $workOrder['completion_notes'];

$csrfToken =
    workOrderEditCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.work-order-edit-page {
    --we-primary: #6d28d9;
    --we-text: #111827;
    --we-muted: #6b7280;
    --we-border: #e5e7eb;
}

.we-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.we-header h1 {
    margin: 0;
    color: var(--we-text);
    font-size: 21px;
    font-weight: 700;
}

.we-header p {
    margin: 5px 0 0;
    color: var(--we-muted);
    font-size: 11px;
}

.we-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.we-back,
.we-btn {
    min-height: 36px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.we-back,
.we-btn.secondary {
    border: 1px solid var(--we-border);
    background: #fff;
    color: #374151;
}

.we-btn.primary {
    border: 0;
    background: var(--we-primary);
    color: #fff;
    cursor: pointer;
}

.we-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.we-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(300px,.68fr);
    gap: 13px;
    align-items: start;
}

.we-card {
    overflow: hidden;
    border: 1px solid var(--we-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.we-card + .we-card {
    margin-top: 13px;
}

.we-card-head {
    min-height: 46px;
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
}

.we-card-head h2 {
    margin: 0;
    color: var(--we-text);
    font-size: 11px;
    font-weight: 700;
}

.we-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.we-card-body {
    padding: 14px;
}

.we-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.we-field {
    min-width: 0;
}

.we-field.full {
    grid-column: 1 / -1;
}

.we-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.we-required {
    color: #dc2626;
}

.we-input,
.we-select,
.we-textarea {
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

.we-input[readonly] {
    background: #f8fafc;
    color: #64748b;
}

.we-textarea {
    min-height: 105px;
    resize: vertical;
}

.we-input:focus,
.we-select:focus,
.we-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.we-help {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.we-summary {
    display: grid;
    gap: 9px;
}

.we-summary-item {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.we-summary-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.we-summary-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.we-signature {
    margin-top: 9px;
    padding: 10px;
    border: 1px solid #d1fae5;
    border-radius: 9px;
    background: #ecfdf5;
    color: #047857;
    font-size: 9px;
}

.we-signature a {
    color: inherit;
    font-weight: 700;
    text-decoration: none;
}

.we-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

@media (max-width: 1050px) {
    .we-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .we-header {
        flex-direction: column;
    }

    .we-grid {
        grid-template-columns: 1fr;
    }

    .we-field.full {
        grid-column: auto;
    }

    .we-actions {
        flex-direction: column-reverse;
    }

    .we-btn {
        width: 100%;
    }
}
</style>

<div class="work-order-edit-page">
    <div class="we-header">
        <div>
            <h1>Edit Work Order</h1>
            <p>
                <?= e($workOrder['work_order_no']); ?>
                · Update instructions, schedule, progress, and completion details.
            </p>
        </div>

        <div class="we-header-actions">
            <a
                href="work-order-view.php?id=<?= (int) $workOrderId; ?>"
                class="we-back"
            >
                <i class="bi bi-eye"></i>
                View Work Order
            </a>

            <a
                href="work-orders.php"
                class="we-back"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Work Orders
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="we-alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="" autocomplete="off">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken); ?>"
        >

        <input
            type="hidden"
            name="id"
            value="<?= (int) $workOrderId; ?>"
        >

        <div class="we-layout">
            <main>
                <section class="we-card">
                    <div class="we-card-head">
                        <h2>Work Order Information</h2>
                        <p>
                            The work-order number is permanent. Changing the job updates its client, property, and assigned worker.
                        </p>
                    </div>

                    <div class="we-card-body">
                        <div class="we-grid">
                            <div class="we-field">
                                <label class="we-label">
                                    Work Order Number
                                </label>

                                <input
                                    type="text"
                                    class="we-input"
                                    value="<?= e(
                                        $workOrder['work_order_no']
                                    ); ?>"
                                    readonly
                                >
                            </div>

                            <div class="we-field">
                                <label class="we-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    id="workOrderStatus"
                                    class="we-select"
                                >
                                    <?php
                                    $statusOptions = array(
                                        'draft' => 'Draft',
                                        'issued' => 'Issued',
                                        'accepted' => 'Accepted',
                                        'in_progress' => 'In Progress',
                                        'completed' => 'Completed',
                                        'rejected' => 'Rejected',
                                        'cancelled' => 'Cancelled'
                                    );

                                    foreach (
                                        $statusOptions as
                                        $value => $label
                                    ):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= $statusValue === $value
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="we-field full">
                                <label class="we-label">
                                    Job
                                    <span class="we-required">*</span>
                                </label>

                                <select
                                    name="job_id"
                                    id="workOrderJob"
                                    class="we-select"
                                    required
                                >
                                    <option value="">
                                        Select Job
                                    </option>

                                    <?php foreach ($jobs as $job): ?>
                                        <option
                                            value="<?= (int) $job['id']; ?>"
                                            <?= $selectedJobId ===
                                                (int) $job['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($job['job_no']); ?>
                                            · <?= e($job['title']); ?>
                                            · <?= e($job['client_name']); ?>

                                            <?php if (
                                                in_array(
                                                    $job['status'],
                                                    array(
                                                        'cancelled',
                                                        'archived',
                                                        'closed'
                                                    ),
                                                    true
                                                )
                                            ): ?>
                                                · <?= e(
                                                    ucwords(
                                                        str_replace(
                                                            '_',
                                                            ' ',
                                                            $job['status']
                                                        )
                                                    )
                                                ); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="we-field full">
                                <label class="we-label">
                                    Work Order Title
                                    <span class="we-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    id="workOrderTitle"
                                    class="we-input"
                                    maxlength="190"
                                    value="<?= e($titleValue); ?>"
                                    required
                                >
                            </div>

                            <div class="we-field full">
                                <label class="we-label">
                                    Work Description
                                </label>

                                <textarea
                                    name="work_description"
                                    id="workDescription"
                                    class="we-textarea"
                                    placeholder="Describe the work to be completed."
                                ><?= e(
                                    $workDescriptionValue
                                ); ?></textarea>
                            </div>

                            <div class="we-field full">
                                <label class="we-label">
                                    Safety Instructions
                                </label>

                                <textarea
                                    name="safety_instructions"
                                    class="we-textarea"
                                    placeholder="Enter safety procedures, PPE requirements, risks, or access precautions."
                                ><?= e(
                                    $safetyInstructionsValue
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="we-card">
                    <div class="we-card-head">
                        <h2>Schedule and Completion</h2>
                        <p>
                            Updating the schedule also updates the connected calendar event and records reschedule history.
                        </p>
                    </div>

                    <div class="we-card-body">
                        <div class="we-grid">
                            <div class="we-field">
                                <label class="we-label">
                                    Scheduled Start
                                </label>

                                <input
                                    type="datetime-local"
                                    name="scheduled_start"
                                    id="scheduledStart"
                                    class="we-input"
                                    value="<?= e(
                                        $scheduledStartValue
                                    ); ?>"
                                >
                            </div>

                            <div class="we-field">
                                <label class="we-label">
                                    Scheduled End
                                </label>

                                <input
                                    type="datetime-local"
                                    name="scheduled_end"
                                    id="scheduledEnd"
                                    class="we-input"
                                    value="<?= e(
                                        $scheduledEndValue
                                    ); ?>"
                                >

                                <div class="we-help">
                                    When left blank with a start time, it defaults to one hour later.
                                </div>
                            </div>

                            <div class="we-field">
                                <label class="we-label">
                                    Actual Start
                                </label>

                                <input
                                    type="datetime-local"
                                    name="actual_start"
                                    id="actualStart"
                                    class="we-input"
                                    value="<?= e(
                                        $actualStartValue
                                    ); ?>"
                                >
                            </div>

                            <div class="we-field">
                                <label class="we-label">
                                    Actual End
                                </label>

                                <input
                                    type="datetime-local"
                                    name="actual_end"
                                    id="actualEnd"
                                    class="we-input"
                                    value="<?= e(
                                        $actualEndValue
                                    ); ?>"
                                >
                            </div>

                            <div class="we-field full">
                                <label class="we-label">
                                    Completion Notes
                                </label>

                                <textarea
                                    name="completion_notes"
                                    class="we-textarea"
                                    placeholder="Enter work completed, result, customer confirmation, or closure notes."
                                ><?= e(
                                    $completionNotesValue
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="we-card">
                    <div class="we-card-head">
                        <h2>Work Order Summary</h2>
                        <p>
                            Review the selected job, client, worker, and schedule.
                        </p>
                    </div>

                    <div class="we-card-body">
                        <div class="we-summary">
                            <div class="we-summary-item">
                                <span class="we-summary-label">
                                    Work Order
                                </span>

                                <span class="we-summary-value">
                                    <?= e(
                                        $workOrder['work_order_no']
                                    ); ?>
                                </span>
                            </div>

                            <div class="we-summary-item">
                                <span class="we-summary-label">
                                    Job
                                </span>

                                <span
                                    class="we-summary-value"
                                    id="summaryJob"
                                >
                                    Not selected
                                </span>
                            </div>

                            <div class="we-summary-item">
                                <span class="we-summary-label">
                                    Client
                                </span>

                                <span
                                    class="we-summary-value"
                                    id="summaryClient"
                                >
                                    —
                                </span>
                            </div>

                            <div class="we-summary-item">
                                <span class="we-summary-label">
                                    Property
                                </span>

                                <span
                                    class="we-summary-value"
                                    id="summaryProperty"
                                >
                                    —
                                </span>
                            </div>

                            <div class="we-summary-item">
                                <span class="we-summary-label">
                                    Assigned Worker
                                </span>

                                <span
                                    class="we-summary-value"
                                    id="summaryWorker"
                                >
                                    Unassigned
                                </span>
                            </div>

                            <div class="we-summary-item">
                                <span class="we-summary-label">
                                    Schedule
                                </span>

                                <span
                                    class="we-summary-value"
                                    id="summarySchedule"
                                >
                                    Not scheduled
                                </span>
                            </div>

                            <div class="we-summary-item">
                                <span class="we-summary-label">
                                    Status
                                </span>

                                <span
                                    class="we-summary-value"
                                    id="summaryStatus"
                                >
                                    <?= e(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $statusValue
                                            )
                                        )
                                    ); ?>
                                </span>
                            </div>
                        </div>

                        <?php if (
                            !empty(
                                $workOrder['signature_attachment_id']
                            )
                        ): ?>
                            <div class="we-signature">
                                <i class="bi bi-pen"></i>
                                Customer signature is attached and will be preserved.

                                <?php if (
                                    trim(
                                        (string) $workOrder['signature_file_path']
                                    ) !== ''
                                ): ?>
                                    <a
                                        href="<?= e(
                                            $workOrder['signature_file_path']
                                        ); ?>"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        View signature
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="we-actions">
                        <a
                            href="work-order-view.php?id=<?= (int) $workOrderId; ?>"
                            class="we-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="we-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Update Work Order
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

    var jobSelect =
        document.getElementById('workOrderJob');

    var titleInput =
        document.getElementById('workOrderTitle');

    var descriptionInput =
        document.getElementById('workDescription');

    var scheduledStart =
        document.getElementById('scheduledStart');

    var scheduledEnd =
        document.getElementById('scheduledEnd');

    var actualStart =
        document.getElementById('actualStart');

    var actualEnd =
        document.getElementById('actualEnd');

    var statusSelect =
        document.getElementById('workOrderStatus');

    var originalJobId =
        <?= (int) $workOrder['job_id']; ?>;

    var jobMap = <?= json_encode(
        $jobMap,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var endWasEntered =
        scheduledEnd.value !== '';

    function pad(value) {
        value = String(value);

        return value.length < 2
            ? '0' + value
            : value;
    }

    function formatDateTimeLocal(date) {
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

    function getSelectedJob() {
        var jobId =
            String(jobSelect.value || '');

        return jobMap[jobId] || null;
    }

    function calculateEndTime(force) {
        if (scheduledStart.value === '') {
            return;
        }

        if (endWasEntered && !force) {
            return;
        }

        var start =
            new Date(scheduledStart.value);

        if (isNaN(start.getTime())) {
            return;
        }

        start.setMinutes(
            start.getMinutes() + 60
        );

        scheduledEnd.value =
            formatDateTimeLocal(start);
    }

    function updateSummary() {
        var job =
            getSelectedJob();

        document.getElementById(
            'summaryJob'
        ).textContent =
            job
                ? job.job_no + ' · ' + job.title
                : 'Not selected';

        document.getElementById(
            'summaryClient'
        ).textContent =
            job
                ? job.client_name
                : '—';

        document.getElementById(
            'summaryProperty'
        ).textContent =
            job
                ? job.property_name +
                  (
                      job.property_address !== '—' &&
                      job.property_address !==
                          job.property_name
                          ? ' · ' +
                            job.property_address
                          : ''
                  )
                : '—';

        document.getElementById(
            'summaryWorker'
        ).textContent =
            job &&
            job.assigned_user_name !== ''
                ? job.assigned_user_name
                : 'Unassigned';

        var schedule =
            scheduledStart.value !== ''
                ? scheduledStart.value
                    .replace('T', ' ')
                : 'Not scheduled';

        if (scheduledEnd.value !== '') {
            schedule +=
                ' to ' +
                scheduledEnd.value
                    .replace('T', ' ');
        }

        document.getElementById(
            'summarySchedule'
        ).textContent =
            schedule;

        var statusOption =
            statusSelect.options[
                statusSelect.selectedIndex
            ];

        document.getElementById(
            'summaryStatus'
        ).textContent =
            statusOption
                ? statusOption.textContent.trim()
                : 'Draft';
    }

    jobSelect.addEventListener(
        'change',
        function () {
            var job =
                getSelectedJob();

            if (
                job &&
                parseInt(jobSelect.value, 10) !==
                    originalJobId
            ) {
                titleInput.value =
                    job.title;

                if (job.description !== '') {
                    descriptionInput.value =
                        job.description;
                }
            }

            updateSummary();
        }
    );

    scheduledStart.addEventListener(
        'change',
        function () {
            endWasEntered = false;
            calculateEndTime(true);
            updateSummary();
        }
    );

    scheduledEnd.addEventListener(
        'change',
        function () {
            endWasEntered =
                scheduledEnd.value !== '';

            updateSummary();
        }
    );

    statusSelect.addEventListener(
        'change',
        function () {
            var now =
                new Date();

            var status =
                statusSelect.value;

            if (
                (
                    status === 'in_progress' ||
                    status === 'completed'
                ) &&
                actualStart.value === ''
            ) {
                actualStart.value =
                    formatDateTimeLocal(now);
            }

            if (
                status === 'completed' &&
                actualEnd.value === ''
            ) {
                actualEnd.value =
                    formatDateTimeLocal(now);
            }

            updateSummary();
        }
    );

    updateSummary();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
