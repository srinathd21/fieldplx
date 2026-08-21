<?php
/**
 * FieldPlx - Add Work Order
 *
 * Upload as:
 * /public_html/work-order-add.php
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
| The current permission table does not contain work_orders.manage.
| Work Orders are part of the Jobs module, so this page uses jobs.manage.
|
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('work-order-add.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'jobs.manage',
        'You do not have permission to create work orders.'
    );
}

$pageTitle = 'Add Work Order - FieldPlx';
$activePage = 'work-order-add';
$searchPlaceholder = 'Search work orders...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('workOrderAddFetchAssoc')) {
    function workOrderAddFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('workOrderAddFetchAll')) {
    function workOrderAddFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('workOrderAddOld')) {
    function workOrderAddOld($key, $default = '')
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

if (!function_exists('workOrderAddNullable')) {
    function workOrderAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('workOrderAddNormalizeDateTime')) {
    function workOrderAddNormalizeDateTime($value)
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

if (!function_exists('workOrderAddCsrfToken')) {
    function workOrderAddCsrfToken()
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

if (!function_exists('workOrderAddVerifyCsrf')) {
    function workOrderAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('workOrderAddGenerateNumber')) {
    function workOrderAddGenerateNumber(
        mysqli $conn,
        $tenantId
    ) {
        $prefix = 'WO';
        $nextNumber = 1;
        $paddingLength = 6;

        $stmt = $conn->prepare("
            SELECT
                id,
                prefix,
                next_number,
                padding_length,
                reset_frequency,
                last_reset_period
            FROM tenant_number_sequences
            WHERE tenant_id = ?
              AND document_type = 'work_order'
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to load the work order number sequence: ' .
                $conn->error
            );
        }

        $stmt->bind_param('i', $tenantId);

        if (!$stmt->execute()) {
            throw new Exception(
                'Unable to execute the work order number query: ' .
                $stmt->error
            );
        }

        $sequence =
            workOrderAddFetchAssoc($stmt);

        $stmt->close();

        $currentPeriod = null;

        if ($sequence) {
            $sequenceId =
                (int) $sequence['id'];

            $prefix =
                trim((string) $sequence['prefix']) !== ''
                    ? (string) $sequence['prefix']
                    : 'WO';

            $nextNumber =
                max(
                    1,
                    (int) $sequence['next_number']
                );

            $paddingLength =
                max(
                    1,
                    (int) $sequence['padding_length']
                );

            if ($sequence['reset_frequency'] === 'yearly') {
                $currentPeriod = date('Y');
            } elseif (
                $sequence['reset_frequency'] === 'monthly'
            ) {
                $currentPeriod = date('Y-m');
            }

            if (
                $currentPeriod !== null &&
                (string) $sequence['last_reset_period'] !==
                    $currentPeriod
            ) {
                $nextNumber = 1;
            }

            $newNextNumber =
                $nextNumber + 1;

            $stmt = $conn->prepare("
                UPDATE tenant_number_sequences
                SET
                    next_number = ?,
                    last_reset_period = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the work order sequence update: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'isii',
                $newNextNumber,
                $currentPeriod,
                $sequenceId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to update the work order sequence: ' .
                    $stmt->error
                );
            }

            $stmt->close();
        } else {
            $stmt = $conn->prepare("
                INSERT INTO tenant_number_sequences (
                    tenant_id,
                    document_type,
                    prefix,
                    next_number,
                    padding_length,
                    reset_frequency,
                    last_reset_period,
                    updated_at
                ) VALUES (
                    ?,
                    'work_order',
                    'WO',
                    2,
                    6,
                    'never',
                    NULL,
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to create the work order number sequence: ' .
                    $conn->error
                );
            }

            $stmt->bind_param('i', $tenantId);

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to save the work order number sequence: ' .
                    $stmt->error
                );
            }

            $stmt->close();
        }

        return $prefix .
            '-' .
            str_pad(
                (string) $nextNumber,
                $paddingLength,
                '0',
                STR_PAD_LEFT
            );
    }
}

if (!function_exists('workOrderAddLogActivity')) {
    function workOrderAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $workOrderId,
        $clientId,
        $workOrderNo,
        $title,
        $jobId
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
                'work_order_created',
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
            'Work order created: ' .
            $workOrderNo .
            ' - ' .
            $title;

        $details = json_encode(
            array(
                'work_order_id' =>
                    (int) $workOrderId,
                'work_order_no' =>
                    (string) $workOrderNo,
                'job_id' =>
                    (int) $jobId,
                'title' =>
                    (string) $title
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
| Load available jobs
|--------------------------------------------------------------------------
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
      AND j.status NOT IN (
          'cancelled',
          'archived',
          'closed'
      )

    ORDER BY j.created_at DESC, j.id DESC
");

if (!$stmt) {
    $errors[] =
        'Unable to prepare the jobs query: ' .
        $conn->error;
} else {
    $stmt->bind_param('i', $tenantId);

    if (!$stmt->execute()) {
        $errors[] =
            'Unable to load jobs: ' .
            $stmt->error;
    } else {
        $jobs =
            workOrderAddFetchAll($stmt);
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
| GET preselection
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' &&
    !empty($_GET['job_id'])
) {
    $_POST['job_id'] =
        (string) (int) $_GET['job_id'];
}

/*
|--------------------------------------------------------------------------
| Save work order
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!workOrderAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $jobId =
        isset($_POST['job_id'])
            ? (int) $_POST['job_id']
            : 0;

    $title =
        workOrderAddOld('title');

    $workDescription =
        workOrderAddOld('work_description');

    $safetyInstructions =
        workOrderAddOld('safety_instructions');

    $scheduledStartInput =
        workOrderAddOld('scheduled_start');

    $scheduledEndInput =
        workOrderAddOld('scheduled_end');

    $actualStartInput =
        workOrderAddOld('actual_start');

    $actualEndInput =
        workOrderAddOld('actual_end');

    $status =
        workOrderAddOld('status', 'draft');

    $completionNotes =
        workOrderAddOld('completion_notes');

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
        workOrderAddNormalizeDateTime(
            $scheduledStartInput
        );

    $scheduledEnd =
        workOrderAddNormalizeDateTime(
            $scheduledEndInput
        );

    $actualStart =
        workOrderAddNormalizeDateTime(
            $actualStartInput
        );

    $actualEnd =
        workOrderAddNormalizeDateTime(
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

    if (
        empty($errors) &&
        $status === 'completed' &&
        $actualStart === null
    ) {
        $actualStart =
            $scheduledStart !== null
                ? $scheduledStart
                : date('Y-m-d H:i:s');
    }

    if (
        empty($errors) &&
        $status === 'completed' &&
        $actualEnd === null
    ) {
        $actualEnd =
            date('Y-m-d H:i:s');
    }

    /*
     * Validate the selected job and get tenant-safe client/property data.
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
              AND j.status NOT IN (
                  'cancelled',
                  'archived',
                  'closed'
              )

            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected job: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'ii',
                $jobId,
                $tenantId
            );

            if (!$stmt->execute()) {
                $errors[] =
                    'Unable to validate the selected job: ' .
                    $stmt->error;
            } else {
                $selectedJob =
                    workOrderAddFetchAssoc($stmt);

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

        $issuedBy = null;
        $issuedAt = null;
        $acceptedAt = null;
        $completedAt = null;

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
            )
        ) {
            $issuedBy =
                $currentUserId;

            $issuedAt =
                date('Y-m-d H:i:s');
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
            )
        ) {
            $acceptedAt =
                date('Y-m-d H:i:s');
        }

        if ($status === 'completed') {
            $completedAt =
                date('Y-m-d H:i:s');
        }

        try {
            $conn->begin_transaction();

            $workOrderNo =
                workOrderAddGenerateNumber(
                    $conn,
                    $tenantId
                );

            $workDescriptionValue =
                workOrderAddNullable(
                    $workDescription
                );

            $safetyInstructionsValue =
                workOrderAddNullable(
                    $safetyInstructions
                );

            $completionNotesValue =
                workOrderAddNullable(
                    $completionNotes
                );

            $stmt = $conn->prepare("
                INSERT INTO work_orders (
                    tenant_id,
                    work_order_no,
                    job_id,
                    client_id,
                    property_id,
                    title,
                    work_description,
                    safety_instructions,
                    scheduled_start,
                    scheduled_end,
                    actual_start,
                    actual_end,
                    status,
                    completion_notes,
                    signature_attachment_id,
                    issued_by,
                    issued_at,
                    accepted_at,
                    completed_at,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at,
                    deleted_at
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
                    ?,
                    ?,
                    NULL,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW(),
                    NULL
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the work order save operation: ' .
                    $conn->error
                );
            }

            /*
             * 20 variables / 20 type characters.
             */
            $stmt->bind_param(
                'isiiisssssssssisssii',
                $tenantId,
                $workOrderNo,
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
                $currentUserId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Work order could not be saved: ' .
                    $stmt->error
                );
            }

            $workOrderId =
                (int) $stmt->insert_id;

            $stmt->close();

            /*
             * Add a schedule event when this work order has a schedule.
             * schedule_events does not contain a work_order event type,
             * so event_type is stored as "event" and related_type as
             * "work_order".
             */
            if (
                $scheduledStart !== null &&
                $scheduledEnd !== null
            ) {
                $scheduleStatus = 'scheduled';

                if ($status === 'completed') {
                    $scheduleStatus = 'completed';
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
                    $scheduleStatus = 'cancelled';
                }

                $scheduleTitle =
                    $workOrderNo .
                    ' - ' .
                    $title;

                $scheduleDescription =
                    $workDescriptionValue;

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
                        'Unable to prepare the work order schedule event: ' .
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
                        'Unable to create the work order schedule event: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            workOrderAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $workOrderId,
                $clientId,
                $workOrderNo,
                $title,
                $jobId
            );

            $_SESSION['flash_success'] =
                'Work order created successfully.';

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
    (int) workOrderAddOld('job_id');

$csrfToken =
    workOrderAddCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.work-order-add-page {
    --wa-primary: #6d28d9;
    --wa-text: #111827;
    --wa-muted: #6b7280;
    --wa-border: #e5e7eb;
}

.wa-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.wa-header h1 {
    margin: 0;
    color: var(--wa-text);
    font-size: 21px;
    font-weight: 700;
}

.wa-header p {
    margin: 5px 0 0;
    color: var(--wa-muted);
    font-size: 11px;
}

.wa-back,
.wa-btn {
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

.wa-back,
.wa-btn.secondary {
    border: 1px solid var(--wa-border);
    background: #fff;
    color: #374151;
}

.wa-btn.primary {
    border: 0;
    background: var(--wa-primary);
    color: #fff;
    cursor: pointer;
}

.wa-btn.primary:disabled {
    cursor: not-allowed;
    opacity: .55;
}

.wa-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.wa-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(300px,.68fr);
    gap: 13px;
    align-items: start;
}

.wa-card {
    overflow: hidden;
    border: 1px solid var(--wa-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.wa-card + .wa-card {
    margin-top: 13px;
}

.wa-card-head {
    min-height: 46px;
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
}

.wa-card-head h2 {
    margin: 0;
    color: var(--wa-text);
    font-size: 11px;
    font-weight: 700;
}

.wa-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.wa-card-body {
    padding: 14px;
}

.wa-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.wa-field {
    min-width: 0;
}

.wa-field.full {
    grid-column: 1 / -1;
}

.wa-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.wa-required {
    color: #dc2626;
}

.wa-input,
.wa-select,
.wa-textarea {
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

.wa-textarea {
    min-height: 105px;
    resize: vertical;
}

.wa-input:focus,
.wa-select:focus,
.wa-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.wa-help {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.wa-summary {
    display: grid;
    gap: 9px;
}

.wa-summary-item {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.wa-summary-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.wa-summary-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.wa-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

@media (max-width: 1050px) {
    .wa-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .wa-header {
        flex-direction: column;
    }

    .wa-grid {
        grid-template-columns: 1fr;
    }

    .wa-field.full {
        grid-column: auto;
    }

    .wa-actions {
        flex-direction: column-reverse;
    }

    .wa-btn {
        width: 100%;
    }
}
</style>

<div class="work-order-add-page">
    <div class="wa-header">
        <div>
            <h1>Add Work Order</h1>
            <p>
                Create job instructions, work schedule, safety notes, and progress details.
            </p>
        </div>

        <a href="work-orders.php" class="wa-back">
            <i class="bi bi-arrow-left"></i>
            Back to Work Orders
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="wa-alert">
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

        <div class="wa-layout">
            <main>
                <section class="wa-card">
                    <div class="wa-card-head">
                        <h2>Job Information</h2>
                        <p>
                            Select the job. Client, property, and worker are loaded automatically.
                        </p>
                    </div>

                    <div class="wa-card-body">
                        <div class="wa-grid">
                            <div class="wa-field full">
                                <label class="wa-label">
                                    Job
                                    <span class="wa-required">*</span>
                                </label>

                                <select
                                    name="job_id"
                                    id="workOrderJob"
                                    class="wa-select"
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
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if (empty($jobs)): ?>
                                    <div class="wa-help">
                                        No active jobs are available. Create a job before adding a work order.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="wa-field full">
                                <label class="wa-label">
                                    Work Order Title
                                    <span class="wa-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    id="workOrderTitle"
                                    class="wa-input"
                                    maxlength="190"
                                    value="<?= e(
                                        workOrderAddOld('title')
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="wa-field full">
                                <label class="wa-label">
                                    Work Description
                                </label>

                                <textarea
                                    name="work_description"
                                    id="workDescription"
                                    class="wa-textarea"
                                    placeholder="Describe the work to be completed."
                                ><?= e(
                                    workOrderAddOld(
                                        'work_description'
                                    )
                                ); ?></textarea>
                            </div>

                            <div class="wa-field full">
                                <label class="wa-label">
                                    Safety Instructions
                                </label>

                                <textarea
                                    name="safety_instructions"
                                    class="wa-textarea"
                                    placeholder="Enter safety procedures, PPE requirements, risks, or access precautions."
                                ><?= e(
                                    workOrderAddOld(
                                        'safety_instructions'
                                    )
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="wa-card">
                    <div class="wa-card-head">
                        <h2>Schedule and Status</h2>
                        <p>
                            Configure planned time, progress status, and completion details.
                        </p>
                    </div>

                    <div class="wa-card-body">
                        <div class="wa-grid">
                            <div class="wa-field">
                                <label class="wa-label">
                                    Scheduled Start
                                </label>

                                <input
                                    type="datetime-local"
                                    name="scheduled_start"
                                    id="scheduledStart"
                                    class="wa-input"
                                    value="<?= e(
                                        workOrderAddOld(
                                            'scheduled_start'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="wa-field">
                                <label class="wa-label">
                                    Scheduled End
                                </label>

                                <input
                                    type="datetime-local"
                                    name="scheduled_end"
                                    id="scheduledEnd"
                                    class="wa-input"
                                    value="<?= e(
                                        workOrderAddOld(
                                            'scheduled_end'
                                        )
                                    ); ?>"
                                >

                                <div class="wa-help">
                                    Defaults to one hour after scheduled start.
                                </div>
                            </div>

                            <div class="wa-field">
                                <label class="wa-label">
                                    Actual Start
                                </label>

                                <input
                                    type="datetime-local"
                                    name="actual_start"
                                    id="actualStart"
                                    class="wa-input"
                                    value="<?= e(
                                        workOrderAddOld(
                                            'actual_start'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="wa-field">
                                <label class="wa-label">
                                    Actual End
                                </label>

                                <input
                                    type="datetime-local"
                                    name="actual_end"
                                    id="actualEnd"
                                    class="wa-input"
                                    value="<?= e(
                                        workOrderAddOld(
                                            'actual_end'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="wa-field full">
                                <label class="wa-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    id="workOrderStatus"
                                    class="wa-select"
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

                                    $selectedStatus =
                                        workOrderAddOld(
                                            'status',
                                            'draft'
                                        );

                                    foreach (
                                        $statusOptions as
                                        $value => $label
                                    ):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= $selectedStatus === $value
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div
                                class="wa-field full"
                                id="completionNotesField"
                            >
                                <label class="wa-label">
                                    Completion Notes
                                </label>

                                <textarea
                                    name="completion_notes"
                                    class="wa-textarea"
                                    placeholder="Enter work completed, result, customer confirmation, or closure notes."
                                ><?= e(
                                    workOrderAddOld(
                                        'completion_notes'
                                    )
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="wa-card">
                    <div class="wa-card-head">
                        <h2>Work Order Summary</h2>
                        <p>
                            Review the selected job and schedule.
                        </p>
                    </div>

                    <div class="wa-card-body">
                        <div class="wa-summary">
                            <div class="wa-summary-item">
                                <span class="wa-summary-label">
                                    Job
                                </span>

                                <span
                                    class="wa-summary-value"
                                    id="summaryJob"
                                >
                                    Not selected
                                </span>
                            </div>

                            <div class="wa-summary-item">
                                <span class="wa-summary-label">
                                    Client
                                </span>

                                <span
                                    class="wa-summary-value"
                                    id="summaryClient"
                                >
                                    —
                                </span>
                            </div>

                            <div class="wa-summary-item">
                                <span class="wa-summary-label">
                                    Property
                                </span>

                                <span
                                    class="wa-summary-value"
                                    id="summaryProperty"
                                >
                                    —
                                </span>
                            </div>

                            <div class="wa-summary-item">
                                <span class="wa-summary-label">
                                    Assigned Worker
                                </span>

                                <span
                                    class="wa-summary-value"
                                    id="summaryWorker"
                                >
                                    Unassigned
                                </span>
                            </div>

                            <div class="wa-summary-item">
                                <span class="wa-summary-label">
                                    Schedule
                                </span>

                                <span
                                    class="wa-summary-value"
                                    id="summarySchedule"
                                >
                                    Not scheduled
                                </span>
                            </div>

                            <div class="wa-summary-item">
                                <span class="wa-summary-label">
                                    Status
                                </span>

                                <span
                                    class="wa-summary-value"
                                    id="summaryStatus"
                                >
                                    Draft
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="wa-actions">
                        <a
                            href="work-orders.php"
                            class="wa-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="wa-btn primary"
                            <?= empty($jobs)
                                ? 'disabled'
                                : ''; ?>
                        >
                            <i class="bi bi-check2"></i>
                            Save Work Order
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

    var statusSelect =
        document.getElementById('workOrderStatus');

    var jobMap = <?= json_encode(
        $jobMap,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var titleWasEntered =
        titleInput.value.trim() !== '';

    var descriptionWasEntered =
        descriptionInput.value.trim() !== '';

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

    function applyJobDetails(force) {
        var job =
            getSelectedJob();

        if (!job) {
            updateSummary();
            return;
        }

        if (
            force ||
            !titleWasEntered ||
            titleInput.value.trim() === ''
        ) {
            titleInput.value =
                job.title;

            titleWasEntered = false;
        }

        if (
            job.description !== '' &&
            (
                force ||
                !descriptionWasEntered ||
                descriptionInput.value.trim() === ''
            )
        ) {
            descriptionInput.value =
                job.description;

            descriptionWasEntered = false;
        }

        updateSummary();
    }

    jobSelect.addEventListener(
        'change',
        function () {
            titleWasEntered = false;
            descriptionWasEntered = false;
            applyJobDetails(true);
        }
    );

    titleInput.addEventListener(
        'input',
        function () {
            titleWasEntered =
                titleInput.value.trim() !== '';
        }
    );

    descriptionInput.addEventListener(
        'input',
        function () {
            descriptionWasEntered =
                descriptionInput.value.trim() !== '';
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
        updateSummary
    );

    if (
        jobSelect.value !== '' &&
        (
            titleInput.value.trim() === '' ||
            descriptionInput.value.trim() === ''
        )
    ) {
        applyJobDetails(false);
    }

    if (
        scheduledStart.value !== '' &&
        scheduledEnd.value === ''
    ) {
        endWasEntered = false;
        calculateEndTime(true);
    }

    updateSummary();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
