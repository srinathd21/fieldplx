<?php
/**
 * FieldPlx - Add Visit
 *
 * Upload as:
 * /public_html/visit-add.php
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
        rawurlencode('visit-add.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'jobs.manage',
        'You do not have permission to create visits.'
    );
}

$pageTitle = 'Add Visit - FieldPlx';
$activePage = 'visit-add';
$searchPlaceholder = 'Search visits...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('visitAddFetchAssoc')) {
    function visitAddFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('visitAddFetchAll')) {
    function visitAddFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('visitAddOld')) {
    function visitAddOld($key, $default = '')
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

if (!function_exists('visitAddNullable')) {
    function visitAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('visitAddNormalizeDateTime')) {
    function visitAddNormalizeDateTime($value)
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

if (!function_exists('visitAddCsrfToken')) {
    function visitAddCsrfToken()
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

if (!function_exists('visitAddVerifyCsrf')) {
    function visitAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('visitAddGenerateNumber')) {
    function visitAddGenerateNumber(
        mysqli $conn,
        $tenantId
    ) {
        $prefix = 'VIS';
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
              AND document_type = 'visit'
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to load the visit number sequence: ' .
                $conn->error
            );
        }

        $stmt->bind_param('i', $tenantId);

        if (!$stmt->execute()) {
            throw new Exception(
                'Unable to execute the visit number query: ' .
                $stmt->error
            );
        }

        $sequence = visitAddFetchAssoc($stmt);
        $stmt->close();

        $currentPeriod = null;

        if ($sequence) {
            $sequenceId = (int) $sequence['id'];

            $prefix =
                trim((string) $sequence['prefix']) !== ''
                    ? (string) $sequence['prefix']
                    : 'VIS';

            $nextNumber = max(
                1,
                (int) $sequence['next_number']
            );

            $paddingLength = max(
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

            $newNextNumber = $nextNumber + 1;

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
                    'Unable to prepare the visit sequence update: ' .
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
                    'Unable to update the visit number sequence: ' .
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
                    'visit',
                    'VIS',
                    2,
                    6,
                    'never',
                    NULL,
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to create the visit number sequence: ' .
                    $conn->error
                );
            }

            $stmt->bind_param('i', $tenantId);

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to save the visit number sequence: ' .
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

if (!function_exists('visitAddLogActivity')) {
    function visitAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $visitId,
        $clientId,
        $visitNo,
        $jobId,
        $jobNo,
        $status
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
                'visit_created',
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
            'Visit created: ' .
            $visitNo .
            ' for ' .
            $jobNo;

        $details = json_encode(
            array(
                'visit_id' => (int) $visitId,
                'visit_no' => (string) $visitNo,
                'job_id' => (int) $jobId,
                'job_no' => (string) $jobNo,
                'status' => (string) $status
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
        j.invoicing_preference,

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
        $jobs = visitAddFetchAll($stmt);
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

    $propertyName =
        trim((string) $job['property_name']) !== ''
            ? (string) $job['property_name']
            : (
                !empty($propertyParts)
                    ? (string) $job['property_address_line1']
                    : 'No property'
            );

    $propertyAddress =
        !empty($propertyParts)
            ? implode(', ', $propertyParts)
            : '—';

    $jobMap[(int) $job['id']] = array(
        'id' => (int) $job['id'],
        'job_no' => (string) $job['job_no'],
        'title' => (string) $job['title'],
        'description' => (string) $job['description'],
        'status' => (string) $job['status'],
        'client_id' => (int) $job['client_id'],
        'client_name' => (string) $job['client_name'],
        'client_phone' => (string) $job['client_phone'],
        'client_email' => (string) $job['client_email'],
        'property_id' =>
            !empty($job['property_id'])
                ? (int) $job['property_id']
                : null,
        'property_name' => $propertyName,
        'property_address' => $propertyAddress,
        'assigned_user_id' =>
            !empty($job['assigned_user_id'])
                ? (int) $job['assigned_user_id']
                : null,
        'assigned_user_name' =>
            trim((string) $job['assigned_user_name']),
        'start_date' => (string) $job['start_date'],
        'end_date' => (string) $job['end_date'],
        'invoicing_preference' =>
            (string) $job['invoicing_preference']
    );
}

/*
|--------------------------------------------------------------------------
| Load available workers
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
        employee_code,
        color_code,
        is_bookable,
        is_field_worker
    FROM users
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status = 'active'
      AND (
          is_field_worker = 1
          OR is_bookable = 1
      )
    ORDER BY
        is_field_worker DESC,
        first_name ASC,
        last_name ASC
");

if (!$stmt) {
    $errors[] =
        'Unable to prepare the worker query: ' .
        $conn->error;
} else {
    $stmt->bind_param('i', $tenantId);

    if (!$stmt->execute()) {
        $errors[] =
            'Unable to load workers: ' .
            $stmt->error;
    } else {
        $workers = visitAddFetchAll($stmt);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| GET preselection
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($_GET['job_id'])) {
        $_POST['job_id'] =
            (string) (int) $_GET['job_id'];
    }

    if (!empty($_GET['assigned_user_id'])) {
        $_POST['assigned_user_id'] =
            (string) (int) $_GET['assigned_user_id'];
    }

    if (!empty($_GET['scheduled_start'])) {
        $_POST['scheduled_start'] =
            trim((string) $_GET['scheduled_start']);
    }
}

/*
|--------------------------------------------------------------------------
| Save visit
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!visitAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $jobId =
        isset($_POST['job_id'])
            ? (int) $_POST['job_id']
            : 0;

    $assignedUserId =
        isset($_POST['assigned_user_id']) &&
        (int) $_POST['assigned_user_id'] > 0
            ? (int) $_POST['assigned_user_id']
            : null;

    $scheduledStartInput =
        visitAddOld('scheduled_start');

    $scheduledEndInput =
        visitAddOld('scheduled_end');

    $actualStartInput =
        visitAddOld('actual_start');

    $actualEndInput =
        visitAddOld('actual_end');

    $status =
        visitAddOld('status', 'scheduled');

    $instructions =
        visitAddOld('instructions');

    $completionNotes =
        visitAddOld('completion_notes');

    $requiresInvoice =
        !empty($_POST['requires_invoice'])
            ? 1
            : 0;

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

    if ($jobId <= 0) {
        $errors[] =
            'Please select a job.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid visit status.';
    }

    $scheduledStart =
        visitAddNormalizeDateTime(
            $scheduledStartInput
        );

    $scheduledEnd =
        visitAddNormalizeDateTime(
            $scheduledEndInput
        );

    $actualStart =
        visitAddNormalizeDateTime(
            $actualStartInput
        );

    $actualEnd =
        visitAddNormalizeDateTime(
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
        $status !== 'draft' &&
        $scheduledStart === null &&
        !in_array(
            $status,
            array(
                'completed',
                'missed',
                'cancelled',
                'needs_review'
            ),
            true
        )
    ) {
        $errors[] =
            'A scheduled start is required for this visit status.';
    }

    /*
     * Automatically set actual times for progress and completion statuses.
     */
    $now = date('Y-m-d H:i:s');

    if (
        empty($errors) &&
        in_array(
            $status,
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
        empty($errors) &&
        in_array(
            $status,
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

    /*
     * Validate selected job.
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
                j.invoicing_preference,

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
                    visitAddFetchAssoc($stmt);

                if (!$selectedJob) {
                    $errors[] =
                        'The selected job is not available.';
                }
            }

            $stmt->close();
        }
    }

    /*
     * Validate selected worker.
     */
    if (
        empty($errors) &&
        $assignedUserId !== null
    ) {
        $stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
              AND status = 'active'
              AND (
                  is_field_worker = 1
                  OR is_bookable = 1
              )
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the assigned worker: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'ii',
                $assignedUserId,
                $tenantId
            );

            if (!$stmt->execute()) {
                $errors[] =
                    'Unable to validate the assigned worker: ' .
                    $stmt->error;
            } else {
                $stmt->store_result();

                if ($stmt->num_rows === 0) {
                    $errors[] =
                        'The selected assigned worker is not available.';
                }
            }

            $stmt->close();
        }
    }

    /*
     * Prevent overlapping active visits for the same worker.
     */
    if (
        empty($errors) &&
        $assignedUserId !== null &&
        $scheduledStart !== null &&
        $scheduledEnd !== null &&
        !in_array(
            $status,
            array(
                'completed',
                'missed',
                'cancelled'
            ),
            true
        )
    ) {
        $stmt = $conn->prepare("
            SELECT
                id,
                visit_no
            FROM visits
            WHERE tenant_id = ?
              AND assigned_user_id = ?
              AND status NOT IN (
                  'completed',
                  'missed',
                  'cancelled'
              )
              AND scheduled_start IS NOT NULL
              AND scheduled_end IS NOT NULL
              AND scheduled_start < ?
              AND scheduled_end > ?
            ORDER BY scheduled_start ASC
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to check worker availability: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'iiss',
                $tenantId,
                $assignedUserId,
                $scheduledEnd,
                $scheduledStart
            );

            if (!$stmt->execute()) {
                $errors[] =
                    'Unable to check worker availability: ' .
                    $stmt->error;
            } else {
                $conflict =
                    visitAddFetchAssoc($stmt);

                if ($conflict) {
                    $errors[] =
                        'The assigned worker already has visit ' .
                        (
                            trim((string) $conflict['visit_no']) !== ''
                                ? $conflict['visit_no']
                                : '#' . $conflict['id']
                        ) .
                        ' during the selected time.';
                }
            }

            $stmt->close();
        }
    }

    if (
        empty($errors) &&
        $selectedJob
    ) {
        $clientId =
            (int) $selectedJob['client_id'];

        $propertyId =
            !empty($selectedJob['property_id'])
                ? (int) $selectedJob['property_id']
                : null;

        if (
            $assignedUserId === null &&
            !empty($selectedJob['assigned_user_id'])
        ) {
            $assignedUserId =
                (int) $selectedJob['assigned_user_id'];
        }

        $instructionsValue =
            visitAddNullable($instructions);

        $completionNotesValue =
            visitAddNullable($completionNotes);

        try {
            $conn->begin_transaction();

            $visitNo =
                visitAddGenerateNumber(
                    $conn,
                    $tenantId
                );

            $stmt = $conn->prepare("
                INSERT INTO visits (
                    tenant_id,
                    job_id,
                    visit_no,
                    assigned_user_id,
                    scheduled_start,
                    scheduled_end,
                    actual_start,
                    actual_end,
                    status,
                    instructions,
                    completion_notes,
                    requires_invoice,
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
                    'Unable to prepare the visit save operation: ' .
                    $conn->error
                );
            }

            /*
             * 12 variables / 12 type characters.
             */
            $stmt->bind_param(
                'iisisssssssi',
                $tenantId,
                $jobId,
                $visitNo,
                $assignedUserId,
                $scheduledStart,
                $scheduledEnd,
                $actualStart,
                $actualEnd,
                $status,
                $instructionsValue,
                $completionNotesValue,
                $requiresInvoice
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Visit could not be saved: ' .
                    $stmt->error
                );
            }

            $visitId =
                (int) $stmt->insert_id;

            $stmt->close();

            /*
             * Create the linked calendar event when scheduled.
             */
            if (
                $scheduledStart !== null &&
                $scheduledEnd !== null
            ) {
                $scheduleStatus = 'scheduled';

                if (
                    in_array(
                        $status,
                        array(
                            'completed',
                            'needs_review'
                        ),
                        true
                    )
                ) {
                    $scheduleStatus = 'completed';
                } elseif ($status === 'missed') {
                    $scheduleStatus = 'missed';
                } elseif ($status === 'cancelled') {
                    $scheduleStatus = 'cancelled';
                }

                $scheduleTitle =
                    $visitNo .
                    ' - ' .
                    $selectedJob['title'];

                $scheduleDescription =
                    $instructionsValue;

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
                        'visit',
                        'visit',
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
                        'Unable to prepare the visit schedule event: ' .
                        $conn->error
                    );
                }

                $stmt->bind_param(
                    'iissiiisssi',
                    $tenantId,
                    $visitId,
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
                        'Unable to create the visit schedule event: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            visitAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $visitId,
                $clientId,
                $visitNo,
                $jobId,
                (string) $selectedJob['job_no'],
                $status
            );

            $_SESSION['flash_success'] =
                'Visit created successfully.';

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
| Render values
|--------------------------------------------------------------------------
*/

$selectedJobId =
    (int) visitAddOld('job_id');

$selectedWorkerId =
    (int) visitAddOld('assigned_user_id');

$selectedStatus =
    visitAddOld('status', 'scheduled');

$csrfToken =
    visitAddCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.visit-add-page {
    --va-primary: #6d28d9;
    --va-text: #111827;
    --va-muted: #6b7280;
    --va-border: #e5e7eb;
}

.va-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.va-header h1 {
    margin: 0;
    color: var(--va-text);
    font-size: 21px;
    font-weight: 700;
}

.va-header p {
    margin: 5px 0 0;
    color: var(--va-muted);
    font-size: 11px;
}

.va-back,
.va-btn {
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

.va-back,
.va-btn.secondary {
    border: 1px solid var(--va-border);
    background: #fff;
    color: #374151;
}

.va-btn.primary {
    border: 0;
    background: var(--va-primary);
    color: #fff;
    cursor: pointer;
}

.va-btn.primary:disabled {
    cursor: not-allowed;
    opacity: .55;
}

.va-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.va-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(300px,.68fr);
    gap: 13px;
    align-items: start;
}

.va-card {
    overflow: hidden;
    border: 1px solid var(--va-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.va-card + .va-card {
    margin-top: 13px;
}

.va-card-head {
    min-height: 46px;
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
}

.va-card-head h2 {
    margin: 0;
    color: var(--va-text);
    font-size: 11px;
    font-weight: 700;
}

.va-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.va-card-body {
    padding: 14px;
}

.va-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.va-field {
    min-width: 0;
}

.va-field.full {
    grid-column: 1 / -1;
}

.va-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.va-required {
    color: #dc2626;
}

.va-input,
.va-select,
.va-textarea {
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

.va-textarea {
    min-height: 105px;
    resize: vertical;
}

.va-input:focus,
.va-select:focus,
.va-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.va-help {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.va-check {
    min-height: 42px;
    padding: 10px 11px;
    display: flex;
    align-items: center;
    gap: 9px;
    border: 1px solid #e5e7eb;
    border-radius: 9px;
    background: #fafafa;
}

.va-check input {
    width: 16px;
    height: 16px;
    accent-color: var(--va-primary);
}

.va-check-text strong {
    display: block;
    color: #374151;
    font-size: 9px;
}

.va-check-text span {
    margin-top: 2px;
    display: block;
    color: #9ca3af;
    font-size: 8px;
}

.va-summary {
    display: grid;
    gap: 9px;
}

.va-summary-item {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.va-summary-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.va-summary-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.va-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

@media (max-width: 1050px) {
    .va-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .va-header {
        flex-direction: column;
    }

    .va-grid {
        grid-template-columns: 1fr;
    }

    .va-field.full {
        grid-column: auto;
    }

    .va-actions {
        flex-direction: column-reverse;
    }

    .va-btn {
        width: 100%;
    }
}
</style>

<div class="visit-add-page">
    <div class="va-header">
        <div>
            <h1>Add Visit</h1>
            <p>
                Schedule a job visit, assign a worker, and define visit instructions.
            </p>
        </div>

        <a href="visits.php" class="va-back">
            <i class="bi bi-arrow-left"></i>
            Back to Visits
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="va-alert">
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

        <div class="va-layout">
            <main>
                <section class="va-card">
                    <div class="va-card-head">
                        <h2>Job and Assignment</h2>
                        <p>
                            Client and property are loaded from the selected job.
                        </p>
                    </div>

                    <div class="va-card-body">
                        <div class="va-grid">
                            <div class="va-field full">
                                <label class="va-label">
                                    Job
                                    <span class="va-required">*</span>
                                </label>

                                <select
                                    name="job_id"
                                    id="visitJob"
                                    class="va-select"
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
                                    <div class="va-help">
                                        No active jobs are available. Create a job before adding a visit.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="va-field full">
                                <label class="va-label">
                                    Assigned Worker
                                </label>

                                <select
                                    name="assigned_user_id"
                                    id="visitWorker"
                                    class="va-select"
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
                                                · <?= e($worker['job_title']); ?>
                                            <?php endif; ?>

                                            <?php if (
                                                !empty($worker['is_field_worker'])
                                            ): ?>
                                                · Field Worker
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <div class="va-help">
                                    Defaults to the worker assigned to the selected job.
                                </div>
                            </div>

                            <div class="va-field full">
                                <label class="va-label">
                                    Visit Instructions
                                </label>

                                <textarea
                                    name="instructions"
                                    id="visitInstructions"
                                    class="va-textarea"
                                    placeholder="Enter access notes, work instructions, customer requests, tools, or materials required."
                                ><?= e(
                                    visitAddOld('instructions')
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="va-card">
                    <div class="va-card-head">
                        <h2>Schedule and Status</h2>
                        <p>
                            Configure planned time, progress, completion, and invoice requirements.
                        </p>
                    </div>

                    <div class="va-card-body">
                        <div class="va-grid">
                            <div class="va-field">
                                <label class="va-label">
                                    Scheduled Start
                                </label>

                                <input
                                    type="datetime-local"
                                    name="scheduled_start"
                                    id="scheduledStart"
                                    class="va-input"
                                    value="<?= e(
                                        visitAddOld(
                                            'scheduled_start'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="va-field">
                                <label class="va-label">
                                    Scheduled End
                                </label>

                                <input
                                    type="datetime-local"
                                    name="scheduled_end"
                                    id="scheduledEnd"
                                    class="va-input"
                                    value="<?= e(
                                        visitAddOld(
                                            'scheduled_end'
                                        )
                                    ); ?>"
                                >

                                <div class="va-help">
                                    Defaults to one hour after scheduled start.
                                </div>
                            </div>

                            <div class="va-field">
                                <label class="va-label">
                                    Actual Start
                                </label>

                                <input
                                    type="datetime-local"
                                    name="actual_start"
                                    id="actualStart"
                                    class="va-input"
                                    value="<?= e(
                                        visitAddOld(
                                            'actual_start'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="va-field">
                                <label class="va-label">
                                    Actual End
                                </label>

                                <input
                                    type="datetime-local"
                                    name="actual_end"
                                    id="actualEnd"
                                    class="va-input"
                                    value="<?= e(
                                        visitAddOld(
                                            'actual_end'
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="va-field full">
                                <label class="va-label">
                                    Visit Status
                                </label>

                                <select
                                    name="status"
                                    id="visitStatus"
                                    class="va-select"
                                >
                                    <?php
                                    $statusOptions = array(
                                        'draft' => 'Draft',
                                        'scheduled' => 'Scheduled',
                                        'dispatched' => 'Dispatched',
                                        'on_my_way' => 'On My Way',
                                        'in_progress' => 'In Progress',
                                        'completed' => 'Completed',
                                        'missed' => 'Missed',
                                        'cancelled' => 'Cancelled',
                                        'needs_review' => 'Needs Review'
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

                            <div class="va-field full">
                                <label class="va-label">
                                    Completion Notes
                                </label>

                                <textarea
                                    name="completion_notes"
                                    class="va-textarea"
                                    placeholder="Enter work completed, outcome, customer feedback, follow-up, or review notes."
                                ><?= e(
                                    visitAddOld(
                                        'completion_notes'
                                    )
                                ); ?></textarea>
                            </div>

                            <div class="va-field full">
                                <label class="va-check">
                                    <input
                                        type="checkbox"
                                        name="requires_invoice"
                                        id="requiresInvoice"
                                        value="1"
                                        <?= !empty(
                                            $_POST['requires_invoice']
                                        )
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span class="va-check-text">
                                        <strong>
                                            Invoice required after this visit
                                        </strong>
                                        <span>
                                            Marks this visit for invoicing follow-up.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="va-card">
                    <div class="va-card-head">
                        <h2>Visit Summary</h2>
                        <p>
                            Review the selected job, worker, schedule, and status.
                        </p>
                    </div>

                    <div class="va-card-body">
                        <div class="va-summary">
                            <div class="va-summary-item">
                                <span class="va-summary-label">
                                    Job
                                </span>

                                <span
                                    class="va-summary-value"
                                    id="summaryJob"
                                >
                                    Not selected
                                </span>
                            </div>

                            <div class="va-summary-item">
                                <span class="va-summary-label">
                                    Client
                                </span>

                                <span
                                    class="va-summary-value"
                                    id="summaryClient"
                                >
                                    —
                                </span>
                            </div>

                            <div class="va-summary-item">
                                <span class="va-summary-label">
                                    Property
                                </span>

                                <span
                                    class="va-summary-value"
                                    id="summaryProperty"
                                >
                                    —
                                </span>
                            </div>

                            <div class="va-summary-item">
                                <span class="va-summary-label">
                                    Assigned Worker
                                </span>

                                <span
                                    class="va-summary-value"
                                    id="summaryWorker"
                                >
                                    Unassigned
                                </span>
                            </div>

                            <div class="va-summary-item">
                                <span class="va-summary-label">
                                    Schedule
                                </span>

                                <span
                                    class="va-summary-value"
                                    id="summarySchedule"
                                >
                                    Not scheduled
                                </span>
                            </div>

                            <div class="va-summary-item">
                                <span class="va-summary-label">
                                    Status
                                </span>

                                <span
                                    class="va-summary-value"
                                    id="summaryStatus"
                                >
                                    Scheduled
                                </span>
                            </div>

                            <div class="va-summary-item">
                                <span class="va-summary-label">
                                    Invoice
                                </span>

                                <span
                                    class="va-summary-value"
                                    id="summaryInvoice"
                                >
                                    Not required
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="va-actions">
                        <a
                            href="visits.php"
                            class="va-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="va-btn primary"
                            <?= empty($jobs)
                                ? 'disabled'
                                : ''; ?>
                        >
                            <i class="bi bi-check2"></i>
                            Save Visit
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
        document.getElementById('visitJob');

    var workerSelect =
        document.getElementById('visitWorker');

    var instructions =
        document.getElementById('visitInstructions');

    var scheduledStart =
        document.getElementById('scheduledStart');

    var scheduledEnd =
        document.getElementById('scheduledEnd');

    var actualStart =
        document.getElementById('actualStart');

    var actualEnd =
        document.getElementById('actualEnd');

    var statusSelect =
        document.getElementById('visitStatus');

    var requiresInvoice =
        document.getElementById('requiresInvoice');

    var jobMap = <?= json_encode(
        $jobMap,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var workerWasSelected =
        workerSelect.value !== '';

    var instructionsWereEntered =
        instructions.value.trim() !== '';

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

    function selectedText(select, fallback) {
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

    function applyJobDetails(force) {
        var job =
            getSelectedJob();

        if (!job) {
            updateSummary();
            return;
        }

        if (
            job.assigned_user_id !== null &&
            (
                force ||
                !workerWasSelected ||
                workerSelect.value === ''
            )
        ) {
            workerSelect.value =
                String(job.assigned_user_id);

            workerWasSelected =
                workerSelect.value !== '';
        }

        if (
            job.description !== '' &&
            (
                force ||
                !instructionsWereEntered ||
                instructions.value.trim() === ''
            )
        ) {
            instructions.value =
                job.description;

            instructionsWereEntered = false;
        }

        if (
            job.invoicing_preference ===
                'after_each_visit' &&
            !requiresInvoice.checked
        ) {
            requiresInvoice.checked = true;
        }

        updateSummary();
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
            selectedText(
                workerSelect,
                'Unassigned'
            );

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

        document.getElementById(
            'summaryStatus'
        ).textContent =
            selectedText(
                statusSelect,
                'Scheduled'
            );

        document.getElementById(
            'summaryInvoice'
        ).textContent =
            requiresInvoice.checked
                ? 'Required'
                : 'Not required';
    }

    jobSelect.addEventListener(
        'change',
        function () {
            workerWasSelected = false;
            instructionsWereEntered = false;
            applyJobDetails(true);
        }
    );

    workerSelect.addEventListener(
        'change',
        function () {
            workerWasSelected =
                workerSelect.value !== '';

            updateSummary();
        }
    );

    instructions.addEventListener(
        'input',
        function () {
            instructionsWereEntered =
                instructions.value.trim() !== '';
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
            var status =
                statusSelect.value;

            var now =
                new Date();

            if (
                (
                    status === 'in_progress' ||
                    status === 'completed' ||
                    status === 'needs_review'
                ) &&
                actualStart.value === ''
            ) {
                actualStart.value =
                    formatDateTimeLocal(now);
            }

            if (
                (
                    status === 'completed' ||
                    status === 'needs_review'
                ) &&
                actualEnd.value === ''
            ) {
                actualEnd.value =
                    formatDateTimeLocal(now);
            }

            updateSummary();
        }
    );

    requiresInvoice.addEventListener(
        'change',
        updateSummary
    );

    if (jobSelect.value !== '') {
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
