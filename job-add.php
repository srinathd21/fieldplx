<?php
/**
 * FieldPlx - Add Job
 *
 * Upload as:
 * /public_html/job-add.php
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
        rawurlencode('job-add.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'jobs.manage',
        'You do not have permission to create jobs.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Add Job - FieldPlx';
$activePage = 'job-add';
$searchPlaceholder = 'Search jobs...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('jobAddFetchAssoc')) {
    function jobAddFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('jobAddFetchAll')) {
    function jobAddFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('jobAddOld')) {
    function jobAddOld($key, $default = '')
    {
        return isset($_POST[$key])
            ? trim((string) $_POST[$key])
            : $default;
    }
}

if (!function_exists('jobAddNullable')) {
    function jobAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('jobAddCsrfToken')) {
    function jobAddCsrfToken()
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

if (!function_exists('jobAddVerifyCsrf')) {
    function jobAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('jobAddGenerateNumber')) {
    function jobAddGenerateNumber(
        mysqli $conn,
        $tenantId
    ) {
        $prefix = 'JOB';
        $nextNumber = 1;
        $paddingLength = 6;

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT
                    id,
                    prefix,
                    next_number,
                    padding_length
                FROM tenant_number_sequences
                WHERE tenant_id = ?
                  AND document_type = 'job'
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to load job number sequence.'
                );
            }

            $stmt->bind_param('i', $tenantId);
            $stmt->execute();

            $row = jobAddFetchAssoc($stmt);
            $stmt->close();

            if ($row) {
                $sequenceId = (int) $row['id'];
                $prefix = trim((string) $row['prefix']) !== ''
                    ? (string) $row['prefix']
                    : 'JOB';

                $nextNumber = max(
                    1,
                    (int) $row['next_number']
                );

                $paddingLength = max(
                    1,
                    (int) $row['padding_length']
                );

                $newNextNumber = $nextNumber + 1;

                $stmt = $conn->prepare("
                    UPDATE tenant_number_sequences
                    SET
                        next_number = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to update job number sequence.'
                    );
                }

                $stmt->bind_param(
                    'iii',
                    $newNextNumber,
                    $sequenceId,
                    $tenantId
                );

                $stmt->execute();
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
                        'job',
                        'JOB',
                        2,
                        6,
                        'never',
                        NULL,
                        NOW()
                    )
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to create job number sequence.'
                    );
                }

                $stmt->bind_param('i', $tenantId);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();

            return $prefix .
                '-' .
                str_pad(
                    (string) $nextNumber,
                    $paddingLength,
                    '0',
                    STR_PAD_LEFT
                );
        } catch (Throwable $error) {
            $conn->rollback();

            return 'JOB-' .
                date('Ymd') .
                '-' .
                substr(
                    (string) ((int) (microtime(true) * 1000)),
                    -6
                );
        }
    }
}

if (!function_exists('jobAddLogActivity')) {
    function jobAddLogActivity(
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
                'job_created',
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
            'Job created: ' .
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
| Selectable data
|--------------------------------------------------------------------------
*/

$clients = array();
$properties = array();
$requests = array();
$quotes = array();
$users = array();
$products = array();

$stmt = $conn->prepare("
    SELECT
        id,
        display_name,
        phone
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $clients = jobAddFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        client_id,
        name,
        address_line1,
        city,
        state
    FROM properties
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY is_primary DESC, name ASC, address_line1 ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $properties = jobAddFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        client_id,
        property_id,
        request_no,
        title,
        status
    FROM requests
    WHERE tenant_id = ?
      AND archived_at IS NULL
      AND status NOT IN (
          'closed',
          'rejected',
          'archived',
          'converted'
      )
    ORDER BY created_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $requests = jobAddFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        client_id,
        property_id,
        request_id,
        quote_no,
        title,
        status,
        total
    FROM quotes
    WHERE tenant_id = ?
      AND archived_at IS NULL
      AND status NOT IN (
          'rejected',
          'expired',
          'archived',
          'converted'
      )
    ORDER BY created_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $quotes = jobAddFetchAll($stmt);
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
    $users = jobAddFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        item_type,
        name,
        unit_name,
        unit_cost,
        unit_price
    FROM product_services
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status = 'active'
    ORDER BY name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $products = jobAddFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Preselected relations
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($_GET['client_id'])) {
        $_POST['client_id'] =
            (string) (int) $_GET['client_id'];
    }

    if (!empty($_GET['property_id'])) {
        $_POST['property_id'] =
            (string) (int) $_GET['property_id'];
    }

    if (!empty($_GET['request_id'])) {
        $_POST['request_id'] =
            (string) (int) $_GET['request_id'];
    }

    if (!empty($_GET['quote_id'])) {
        $_POST['quote_id'] =
            (string) (int) $_GET['quote_id'];
    }
}

/*
|--------------------------------------------------------------------------
| Save job
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!jobAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $clientId = isset($_POST['client_id'])
        ? (int) $_POST['client_id']
        : 0;

    $propertyId = isset($_POST['property_id']) &&
        (int) $_POST['property_id'] > 0
            ? (int) $_POST['property_id']
            : null;

    $requestId = isset($_POST['request_id']) &&
        (int) $_POST['request_id'] > 0
            ? (int) $_POST['request_id']
            : null;

    $quoteId = isset($_POST['quote_id']) &&
        (int) $_POST['quote_id'] > 0
            ? (int) $_POST['quote_id']
            : null;

    $title = jobAddOld('title');
    $description = jobAddOld('description');

    $jobType = jobAddOld(
        'job_type',
        'one_off'
    );

    $status = jobAddOld(
        'status',
        'draft'
    );

    $assignedUserId =
        isset($_POST['assigned_user_id']) &&
        (int) $_POST['assigned_user_id'] > 0
            ? (int) $_POST['assigned_user_id']
            : null;

    $startDate = jobAddOld('start_date');
    $endDate = jobAddOld('end_date');

    $recurrenceRule =
        jobAddOld('recurrence_rule');

    $invoicingPreference =
        jobAddOld(
            'invoicing_preference',
            'when_job_complete'
        );

    $allowedJobTypes = array(
        'one_off',
        'recurring'
    );

    $allowedStatuses = array(
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

    $allowedInvoicing = array(
        'after_each_visit',
        'when_job_complete',
        'recurring_schedule',
        'manual'
    );

    if ($clientId <= 0) {
        $errors[] = 'Please select a client.';
    }

    if ($title === '') {
        $errors[] = 'Job title is required.';
    }

    if (strlen($title) > 190) {
        $errors[] =
            'Job title cannot exceed 190 characters.';
    }

    if (
        !in_array(
            $jobType,
            $allowedJobTypes,
            true
        )
    ) {
        $errors[] = 'Please select a valid job type.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] = 'Please select a valid status.';
    }

    if (
        !in_array(
            $invoicingPreference,
            $allowedInvoicing,
            true
        )
    ) {
        $errors[] =
            'Please select a valid invoicing preference.';
    }

    if (
        $startDate !== '' &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)
    ) {
        $errors[] = 'Please enter a valid start date.';
    }

    if (
        $endDate !== '' &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
    ) {
        $errors[] = 'Please enter a valid end date.';
    }

    if (
        $startDate !== '' &&
        $endDate !== '' &&
        strtotime($endDate) < strtotime($startDate)
    ) {
        $errors[] =
            'End date cannot be earlier than start date.';
    }

    /*
     * Validate client.
     */
    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT id
            FROM clients
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
              AND status <> 'archived'
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected client.';
        } else {
            $stmt->bind_param(
                'ii',
                $clientId,
                $tenantId
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $errors[] =
                    'The selected client is not valid.';
            }

            $stmt->close();
        }
    }

    /*
     * Validate property.
     */
    if (
        empty($errors) &&
        $propertyId !== null
    ) {
        $stmt = $conn->prepare("
            SELECT id
            FROM properties
            WHERE id = ?
              AND client_id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
              AND status <> 'archived'
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected property.';
        } else {
            $stmt->bind_param(
                'iii',
                $propertyId,
                $clientId,
                $tenantId
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $errors[] =
                    'The selected property does not belong to this client.';
            }

            $stmt->close();
        }
    }

    /*
     * Validate request.
     */
    if (
        empty($errors) &&
        $requestId !== null
    ) {
        $stmt = $conn->prepare("
            SELECT id
            FROM requests
            WHERE id = ?
              AND client_id = ?
              AND tenant_id = ?
              AND archived_at IS NULL
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected request.';
        } else {
            $stmt->bind_param(
                'iii',
                $requestId,
                $clientId,
                $tenantId
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $errors[] =
                    'The selected request does not belong to this client.';
            }

            $stmt->close();
        }
    }

    /*
     * Validate quote.
     */
    if (
        empty($errors) &&
        $quoteId !== null
    ) {
        $stmt = $conn->prepare("
            SELECT id
            FROM quotes
            WHERE id = ?
              AND client_id = ?
              AND tenant_id = ?
              AND archived_at IS NULL
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected quote.';
        } else {
            $stmt->bind_param(
                'iii',
                $quoteId,
                $clientId,
                $tenantId
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $errors[] =
                    'The selected quote does not belong to this client.';
            }

            $stmt->close();
        }
    }

    /*
     * Validate assigned user.
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
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the assigned user.';
        } else {
            $stmt->bind_param(
                'ii',
                $assignedUserId,
                $tenantId
            );

            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 0) {
                $errors[] =
                    'The selected assigned user is not valid.';
            }

            $stmt->close();
        }
    }

    /*
     * Parse line items.
     */
    $lineItems = array();
    $subtotal = 0.00;
    $taxTotal = 0.00;
    $grandTotal = 0.00;

    $itemNames = isset($_POST['item_name']) &&
        is_array($_POST['item_name'])
            ? $_POST['item_name']
            : array();

    $productIds = isset($_POST['product_service_id']) &&
        is_array($_POST['product_service_id'])
            ? $_POST['product_service_id']
            : array();

    $descriptions = isset($_POST['item_description']) &&
        is_array($_POST['item_description'])
            ? $_POST['item_description']
            : array();

    $quantities = isset($_POST['quantity']) &&
        is_array($_POST['quantity'])
            ? $_POST['quantity']
            : array();

    $unitCosts = isset($_POST['unit_cost']) &&
        is_array($_POST['unit_cost'])
            ? $_POST['unit_cost']
            : array();

    $unitPrices = isset($_POST['unit_price']) &&
        is_array($_POST['unit_price'])
            ? $_POST['unit_price']
            : array();

    $taxAmounts = isset($_POST['tax_amount']) &&
        is_array($_POST['tax_amount'])
            ? $_POST['tax_amount']
            : array();

    $rowCount = max(
        count($itemNames),
        count($quantities),
        count($unitPrices)
    );

    for ($i = 0; $i < $rowCount; $i++) {
        $itemName = isset($itemNames[$i])
            ? trim((string) $itemNames[$i])
            : '';

        $productId = isset($productIds[$i]) &&
            (int) $productIds[$i] > 0
                ? (int) $productIds[$i]
                : null;

        $itemDescription = isset($descriptions[$i])
            ? trim((string) $descriptions[$i])
            : '';

        $quantity = isset($quantities[$i])
            ? (float) $quantities[$i]
            : 0;

        $unitCost = isset($unitCosts[$i])
            ? (float) $unitCosts[$i]
            : 0;

        $unitPrice = isset($unitPrices[$i])
            ? (float) $unitPrices[$i]
            : 0;

        $taxAmount = isset($taxAmounts[$i])
            ? (float) $taxAmounts[$i]
            : 0;

        if (
            $itemName === '' &&
            $quantity <= 0 &&
            $unitPrice <= 0
        ) {
            continue;
        }

        if ($itemName === '') {
            $errors[] =
                'Every line item must have an item name.';
            break;
        }

        if ($quantity <= 0) {
            $errors[] =
                'Every line item quantity must be greater than zero.';
            break;
        }

        if ($unitPrice < 0 || $unitCost < 0 || $taxAmount < 0) {
            $errors[] =
                'Line item amounts cannot be negative.';
            break;
        }

        $lineSubtotal =
            round($quantity * $unitPrice, 2);

        $lineTotal =
            round($lineSubtotal + $taxAmount, 2);

        $subtotal += $lineSubtotal;
        $taxTotal += $taxAmount;
        $grandTotal += $lineTotal;

        $lineItems[] = array(
            'product_service_id' => $productId,
            'item_name' => $itemName,
            'description' =>
                jobAddNullable($itemDescription),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'unit_price' => $unitPrice,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
            'sort_order' => $i
        );
    }

    if (empty($errors)) {
        try {
            $jobNo =
                jobAddGenerateNumber(
                    $conn,
                    $tenantId
                );

            /*
             * jobAddGenerateNumber commits its own small sequence
             * transaction. Start the main job transaction now.
             */
            $conn->begin_transaction();

            $descriptionValue =
                jobAddNullable($description);

            $startDateValue =
                jobAddNullable($startDate);

            $endDateValue =
                jobAddNullable($endDate);

            $recurrenceRuleValue =
                $jobType === 'recurring'
                    ? jobAddNullable($recurrenceRule)
                    : null;

            $stmt = $conn->prepare("
                INSERT INTO jobs (
                    tenant_id,
                    job_no,
                    client_id,
                    property_id,
                    quote_id,
                    request_id,
                    title,
                    description,
                    job_type,
                    status,
                    assigned_user_id,
                    start_date,
                    end_date,
                    recurrence_rule,
                    invoicing_preference,
                    subtotal,
                    tax_total,
                    total,
                    created_by,
                    completed_at,
                    closed_at,
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
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NULL,
                    NULL,
                    NOW(),
                    NOW(),
                    NULL
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the job save operation.'
                );
            }

            /*
             * 19 variables / 19 type characters:
             * i s i i i i s s s s i s s s s d d d i
             */
            $stmt->bind_param(
                'isiiiissssissssdddi',
                $tenantId,
                $jobNo,
                $clientId,
                $propertyId,
                $quoteId,
                $requestId,
                $title,
                $descriptionValue,
                $jobType,
                $status,
                $assignedUserId,
                $startDateValue,
                $endDateValue,
                $recurrenceRuleValue,
                $invoicingPreference,
                $subtotal,
                $taxTotal,
                $grandTotal,
                $currentUserId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Job could not be saved: ' .
                    $stmt->error
                );
            }

            $jobId = (int) $stmt->insert_id;
            $stmt->close();

            /*
             * Primary assignment.
             */
            if ($assignedUserId !== null) {
                $stmt = $conn->prepare("
                    INSERT INTO job_assignments (
                        tenant_id,
                        job_id,
                        user_id,
                        team_id,
                        assignment_role,
                        assigned_by,
                        assigned_at,
                        accepted_at,
                        status,
                        removed_at
                    ) VALUES (
                        ?,
                        ?,
                        ?,
                        NULL,
                        'primary',
                        ?,
                        NOW(),
                        NULL,
                        'assigned',
                        NULL
                    )
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to create job assignment.'
                    );
                }

                $stmt->bind_param(
                    'iiii',
                    $tenantId,
                    $jobId,
                    $assignedUserId,
                    $currentUserId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Job assignment could not be saved: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            /*
             * Line items.
             */
            if (!empty($lineItems)) {
                $stmt = $conn->prepare("
                    INSERT INTO job_line_items (
                        tenant_id,
                        job_id,
                        product_service_id,
                        item_name,
                        description,
                        quantity,
                        unit_cost,
                        unit_price,
                        tax_rate_id,
                        tax_amount,
                        line_total,
                        sort_order,
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
                        NULL,
                        ?,
                        ?,
                        ?,
                        NOW()
                    )
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare job line items.'
                    );
                }

                foreach ($lineItems as $item) {
                    $productServiceId =
                        $item['product_service_id'];

                    $itemName =
                        $item['item_name'];

                    $itemDescription =
                        $item['description'];

                    $quantity =
                        $item['quantity'];

                    $unitCost =
                        $item['unit_cost'];

                    $unitPrice =
                        $item['unit_price'];

                    $taxAmount =
                        $item['tax_amount'];

                    $lineTotal =
                        $item['line_total'];

                    $sortOrder =
                        $item['sort_order'];

                    $stmt->bind_param(
                        'iiissdddddi',
                        $tenantId,
                        $jobId,
                        $productServiceId,
                        $itemName,
                        $itemDescription,
                        $quantity,
                        $unitCost,
                        $unitPrice,
                        $taxAmount,
                        $lineTotal,
                        $sortOrder
                    );

                    if (!$stmt->execute()) {
                        throw new Exception(
                            'A job line item could not be saved: ' .
                            $stmt->error
                        );
                    }
                }

                $stmt->close();
            }

            /*
             * Mark linked request as converted.
             */
            if ($requestId !== null) {
                $stmt = $conn->prepare("
                    UPDATE requests
                    SET
                        status = 'converted',
                        converted_job_id = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        'iii',
                        $jobId,
                        $requestId,
                        $tenantId
                    );

                    $stmt->execute();
                    $stmt->close();
                }
            }

            /*
             * Mark linked quote as converted.
             */
            if ($quoteId !== null) {
                $stmt = $conn->prepare("
                    UPDATE quotes
                    SET
                        status = 'converted',
                        converted_job_id = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        'iii',
                        $jobId,
                        $quoteId,
                        $tenantId
                    );

                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();

            jobAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $jobId,
                $clientId,
                $jobNo,
                $title
            );

            $_SESSION['flash_success'] =
                'Job created successfully.';

            header(
                'Location: job-view.php?id=' .
                $jobId
            );
            exit;
        } catch (Throwable $error) {
            if ($conn->errno === 0) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }
            } else {
                $conn->rollback();
            }

            $errors[] = $error->getMessage();
        }
    }
}

$csrfToken = jobAddCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.job-add-page {
    --ja-primary: #6d28d9;
    --ja-text: #111827;
    --ja-muted: #6b7280;
    --ja-border: #e5e7eb;
}

.ja-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.ja-header h1 {
    margin: 0;
    color: var(--ja-text);
    font-size: 21px;
    font-weight: 700;
}

.ja-header p {
    margin: 5px 0 0;
    color: var(--ja-muted);
    font-size: 11px;
}

.ja-back {
    min-height: 34px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid var(--ja-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.ja-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.ja-alert ul {
    margin: 0;
    padding-left: 18px;
}

.ja-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.55fr)
        minmax(290px,.7fr);
    gap: 13px;
}

.ja-card {
    overflow: hidden;
    border: 1px solid var(--ja-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.ja-card + .ja-card {
    margin-top: 13px;
}

.ja-card-head {
    min-height: 46px;
    padding: 11px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.ja-card-head h2 {
    margin: 0;
    color: var(--ja-text);
    font-size: 11px;
    font-weight: 700;
}

.ja-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.ja-card-body {
    padding: 14px;
}

.ja-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.ja-field {
    min-width: 0;
}

.ja-field.full {
    grid-column: 1 / -1;
}

.ja-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.ja-required {
    color: #dc2626;
}

.ja-input,
.ja-select,
.ja-textarea {
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

.ja-textarea {
    min-height: 105px;
    resize: vertical;
}

.ja-input:focus,
.ja-select:focus,
.ja-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.ja-line-table-wrap {
    overflow-x: auto;
}

.ja-line-table {
    width: 100%;
    border-collapse: collapse;
}

.ja-line-table th,
.ja-line-table td {
    padding: 8px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    vertical-align: top;
}

.ja-line-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.ja-line-input,
.ja-line-select {
    width: 100%;
    min-width: 95px;
    min-height: 34px;
    padding: 7px 8px;
    border: 1px solid #dfe3e8;
    border-radius: 7px;
    background: #fff;
    font-size: 9px;
}

.ja-line-name {
    min-width: 170px;
}

.ja-remove-line {
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #fecaca;
    border-radius: 7px;
    background: #fff;
    color: #dc2626;
    cursor: pointer;
}

.ja-add-line {
    min-height: 32px;
    padding: 7px 10px;
    border: 1px solid #c4b5fd;
    border-radius: 8px;
    background: #faf8ff;
    color: var(--ja-primary);
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.ja-summary {
    display: grid;
    gap: 9px;
}

.ja-summary-row {
    padding: 10px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
    color: #374151;
    font-size: 9px;
}

.ja-summary-row.total {
    border-color: #ddd6fe;
    background: #f5f3ff;
    color: #5b21b6;
    font-size: 11px;
    font-weight: 700;
}

.ja-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

.ja-btn {
    min-height: 36px;
    padding: 8px 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 0;
    border-radius: 9px;
    font-family: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}

.ja-btn.secondary {
    border: 1px solid var(--ja-border);
    background: #fff;
    color: #374151;
}

.ja-btn.primary {
    background: var(--ja-primary);
    color: #fff;
}

@media (max-width: 1080px) {
    .ja-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .ja-header {
        flex-direction: column;
    }

    .ja-grid {
        grid-template-columns: 1fr;
    }

    .ja-field.full {
        grid-column: auto;
    }

    .ja-actions {
        flex-direction: column-reverse;
    }

    .ja-btn {
        width: 100%;
    }
}
</style>

<div class="job-add-page">
    <div class="ja-header">
        <div>
            <h1>Add Job</h1>
            <p>
                Create a service job, assign a worker, and add job items.
            </p>
        </div>

        <a
            href="jobs.php"
            class="ja-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Jobs
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="ja-alert">
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

        <div class="ja-layout">
            <main>
                <section class="ja-card">
                    <div class="ja-card-head">
                        <div>
                            <h2>Job Information</h2>
                            <p>
                                Select the customer and enter the job details.
                            </p>
                        </div>
                    </div>

                    <div class="ja-card-body">
                        <div class="ja-grid">
                            <div class="ja-field full">
                                <label class="ja-label">
                                    Client
                                    <span class="ja-required">*</span>
                                </label>

                                <select
                                    name="client_id"
                                    id="jobClient"
                                    class="ja-select"
                                    required
                                >
                                    <option value="">
                                        Select Client
                                    </option>

                                    <?php foreach ($clients as $client): ?>
                                        <option
                                            value="<?= (int) $client['id']; ?>"
                                            <?= (int) jobAddOld('client_id') === (int) $client['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($client['display_name']); ?>
                                            <?php if (!empty($client['phone'])): ?>
                                                · <?= e($client['phone']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ja-field">
                                <label class="ja-label">
                                    Property
                                </label>

                                <select
                                    name="property_id"
                                    id="jobProperty"
                                    class="ja-select"
                                >
                                    <option value="">
                                        No Property
                                    </option>

                                    <?php foreach ($properties as $property): ?>
                                        <?php
                                        $propertyLabel =
                                            trim((string) $property['name']) !== ''
                                                ? $property['name']
                                                : $property['address_line1'];
                                        ?>
                                        <option
                                            value="<?= (int) $property['id']; ?>"
                                            data-client-id="<?= (int) $property['client_id']; ?>"
                                            <?= (int) jobAddOld('property_id') === (int) $property['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($propertyLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ja-field">
                                <label class="ja-label">
                                    Request
                                </label>

                                <select
                                    name="request_id"
                                    id="jobRequest"
                                    class="ja-select"
                                >
                                    <option value="">
                                        No Request
                                    </option>

                                    <?php foreach ($requests as $request): ?>
                                        <option
                                            value="<?= (int) $request['id']; ?>"
                                            data-client-id="<?= (int) $request['client_id']; ?>"
                                            data-property-id="<?= (int) $request['property_id']; ?>"
                                            <?= (int) jobAddOld('request_id') === (int) $request['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($request['request_no']); ?>
                                            · <?= e($request['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ja-field">
                                <label class="ja-label">
                                    Quote
                                </label>

                                <select
                                    name="quote_id"
                                    id="jobQuote"
                                    class="ja-select"
                                >
                                    <option value="">
                                        No Quote
                                    </option>

                                    <?php foreach ($quotes as $quote): ?>
                                        <option
                                            value="<?= (int) $quote['id']; ?>"
                                            data-client-id="<?= (int) $quote['client_id']; ?>"
                                            data-property-id="<?= (int) $quote['property_id']; ?>"
                                            data-request-id="<?= (int) $quote['request_id']; ?>"
                                            <?= (int) jobAddOld('quote_id') === (int) $quote['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($quote['quote_no']); ?>
                                            ·
                                            <?= e(
                                                !empty($quote['title'])
                                                    ? $quote['title']
                                                    : 'Quote'
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ja-field">
                                <label class="ja-label">
                                    Assigned Worker
                                </label>

                                <select
                                    name="assigned_user_id"
                                    class="ja-select"
                                >
                                    <option value="">
                                        Not Assigned
                                    </option>

                                    <?php foreach ($users as $user): ?>
                                        <?php
                                        $userName = trim(
                                            (string) $user['first_name'] .
                                            ' ' .
                                            (string) $user['last_name']
                                        );
                                        ?>
                                        <option
                                            value="<?= (int) $user['id']; ?>"
                                            <?= (int) jobAddOld('assigned_user_id') === (int) $user['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($userName); ?>
                                            <?= !empty($user['is_field_worker'])
                                                ? ' · Field Worker'
                                                : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ja-field full">
                                <label class="ja-label">
                                    Job Title
                                    <span class="ja-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    class="ja-input"
                                    maxlength="190"
                                    value="<?= e(jobAddOld('title')); ?>"
                                    required
                                >
                            </div>

                            <div class="ja-field full">
                                <label class="ja-label">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    class="ja-textarea"
                                ><?= e(jobAddOld('description')); ?></textarea>
                            </div>

                            <div class="ja-field">
                                <label class="ja-label">
                                    Job Type
                                </label>

                                <select
                                    name="job_type"
                                    id="jobType"
                                    class="ja-select"
                                >
                                    <option
                                        value="one_off"
                                        <?= jobAddOld('job_type', 'one_off') === 'one_off'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        One-off
                                    </option>

                                    <option
                                        value="recurring"
                                        <?= jobAddOld('job_type') === 'recurring'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Recurring
                                    </option>
                                </select>
                            </div>

                            <div class="ja-field">
                                <label class="ja-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    class="ja-select"
                                >
                                    <?php
                                    $statuses = array(
                                        'draft' => 'Draft',
                                        'active' => 'Active',
                                        'scheduled' => 'Scheduled',
                                        'upcoming' => 'Upcoming',
                                        'today' => 'Today',
                                        'in_progress' => 'In Progress',
                                        'unscheduled' => 'Unscheduled'
                                    );

                                    foreach ($statuses as $value => $label):
                                    ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= jobAddOld('status', 'draft') === $value
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ja-field">
                                <label class="ja-label">
                                    Start Date
                                </label>

                                <input
                                    type="date"
                                    name="start_date"
                                    class="ja-input"
                                    value="<?= e(jobAddOld('start_date')); ?>"
                                >
                            </div>

                            <div class="ja-field">
                                <label class="ja-label">
                                    End Date
                                </label>

                                <input
                                    type="date"
                                    name="end_date"
                                    class="ja-input"
                                    value="<?= e(jobAddOld('end_date')); ?>"
                                >
                            </div>

                            <div class="ja-field full" id="recurrenceField">
                                <label class="ja-label">
                                    Recurrence Rule
                                </label>

                                <input
                                    type="text"
                                    name="recurrence_rule"
                                    class="ja-input"
                                    value="<?= e(jobAddOld('recurrence_rule')); ?>"
                                    placeholder="Example: FREQ=WEEKLY;INTERVAL=1"
                                >
                            </div>

                            <div class="ja-field full">
                                <label class="ja-label">
                                    Invoicing Preference
                                </label>

                                <select
                                    name="invoicing_preference"
                                    class="ja-select"
                                >
                                    <option
                                        value="when_job_complete"
                                        <?= jobAddOld(
                                            'invoicing_preference',
                                            'when_job_complete'
                                        ) === 'when_job_complete'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        When Job Is Complete
                                    </option>

                                    <option
                                        value="after_each_visit"
                                        <?= jobAddOld('invoicing_preference') === 'after_each_visit'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        After Each Visit
                                    </option>

                                    <option
                                        value="recurring_schedule"
                                        <?= jobAddOld('invoicing_preference') === 'recurring_schedule'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Recurring Schedule
                                    </option>

                                    <option
                                        value="manual"
                                        <?= jobAddOld('invoicing_preference') === 'manual'
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        Manual
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ja-card">
                    <div class="ja-card-head">
                        <div>
                            <h2>Job Items</h2>
                            <p>
                                Add products, services, materials, or fees.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="ja-add-line"
                            id="addJobLine"
                        >
                            <i class="bi bi-plus-lg"></i>
                            Add Item
                        </button>
                    </div>

                    <div class="ja-line-table-wrap">
                        <table class="ja-line-table">
                            <thead>
                                <tr>
                                    <th>Product / Service</th>
                                    <th>Item Name</th>
                                    <th>Qty</th>
                                    <th>Unit Cost</th>
                                    <th>Unit Price</th>
                                    <th>Tax</th>
                                    <th>Line Total</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody id="jobLineBody"></tbody>
                        </table>
                    </div>
                </section>
            </main>

            <aside>
                <section class="ja-card">
                    <div class="ja-card-head">
                        <div>
                            <h2>Job Summary</h2>
                            <p>
                                Totals update from job items.
                            </p>
                        </div>
                    </div>

                    <div class="ja-card-body">
                        <div class="ja-summary">
                            <div class="ja-summary-row">
                                <span>Subtotal</span>
                                <strong id="summarySubtotal">0.00</strong>
                            </div>

                            <div class="ja-summary-row">
                                <span>Tax</span>
                                <strong id="summaryTax">0.00</strong>
                            </div>

                            <div class="ja-summary-row total">
                                <span>Total</span>
                                <strong id="summaryTotal">0.00</strong>
                            </div>
                        </div>
                    </div>

                    <div class="ja-actions">
                        <a
                            href="jobs.php"
                            class="ja-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="ja-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Save Job
                        </button>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>

<template id="jobLineTemplate">
    <tr class="job-line-row">
        <td>
            <select
                name="product_service_id[]"
                class="ja-line-select product-select"
            >
                <option value="">
                    Custom Item
                </option>

                <?php foreach ($products as $product): ?>
                    <option
                        value="<?= (int) $product['id']; ?>"
                        data-name="<?= e($product['name']); ?>"
                        data-cost="<?= e($product['unit_cost']); ?>"
                        data-price="<?= e($product['unit_price']); ?>"
                    >
                        <?= e($product['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>

        <td>
            <input
                type="text"
                name="item_name[]"
                class="ja-line-input ja-line-name item-name"
                placeholder="Item name"
            >

            <input
                type="text"
                name="item_description[]"
                class="ja-line-input"
                placeholder="Description"
                style="margin-top:5px;"
            >
        </td>

        <td>
            <input
                type="number"
                name="quantity[]"
                class="ja-line-input quantity"
                step="0.001"
                min="0.001"
                value="1"
            >
        </td>

        <td>
            <input
                type="number"
                name="unit_cost[]"
                class="ja-line-input unit-cost"
                step="0.01"
                min="0"
                value="0"
            >
        </td>

        <td>
            <input
                type="number"
                name="unit_price[]"
                class="ja-line-input unit-price"
                step="0.01"
                min="0"
                value="0"
            >
        </td>

        <td>
            <input
                type="number"
                name="tax_amount[]"
                class="ja-line-input tax-amount"
                step="0.01"
                min="0"
                value="0"
            >
        </td>

        <td>
            <strong class="line-total">0.00</strong>
        </td>

        <td>
            <button
                type="button"
                class="ja-remove-line remove-line"
                title="Remove item"
            >
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var clientSelect =
        document.getElementById('jobClient');

    var propertySelect =
        document.getElementById('jobProperty');

    var requestSelect =
        document.getElementById('jobRequest');

    var quoteSelect =
        document.getElementById('jobQuote');

    var jobType =
        document.getElementById('jobType');

    var recurrenceField =
        document.getElementById('recurrenceField');

    var lineBody =
        document.getElementById('jobLineBody');

    var lineTemplate =
        document.getElementById('jobLineTemplate');

    var addLineButton =
        document.getElementById('addJobLine');

    function filterRelatedSelect(select, clientId) {
        if (!select) {
            return;
        }

        var selectedStillVisible = false;

        Array.prototype.forEach.call(
            select.options,
            function (option, index) {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }

                var optionClientId =
                    option.getAttribute('data-client-id');

                var visible =
                    clientId !== '' &&
                    optionClientId === clientId;

                option.hidden = !visible;

                if (visible && option.selected) {
                    selectedStillVisible = true;
                }
            }
        );

        if (!selectedStillVisible) {
            select.value = '';
        }
    }

    function filterRelations() {
        var clientId =
            clientSelect ? clientSelect.value : '';

        filterRelatedSelect(
            propertySelect,
            clientId
        );

        filterRelatedSelect(
            requestSelect,
            clientId
        );

        filterRelatedSelect(
            quoteSelect,
            clientId
        );
    }

    function toggleRecurrence() {
        if (!jobType || !recurrenceField) {
            return;
        }

        recurrenceField.style.display =
            jobType.value === 'recurring'
                ? ''
                : 'none';
    }

    function recalculate() {
        var subtotal = 0;
        var tax = 0;
        var total = 0;

        lineBody
            .querySelectorAll('.job-line-row')
            .forEach(function (row) {
                var quantity =
                    parseFloat(
                        row.querySelector('.quantity').value
                    ) || 0;

                var unitPrice =
                    parseFloat(
                        row.querySelector('.unit-price').value
                    ) || 0;

                var taxAmount =
                    parseFloat(
                        row.querySelector('.tax-amount').value
                    ) || 0;

                var lineSubtotal =
                    quantity * unitPrice;

                var lineTotal =
                    lineSubtotal + taxAmount;

                subtotal += lineSubtotal;
                tax += taxAmount;
                total += lineTotal;

                row.querySelector('.line-total').textContent =
                    lineTotal.toFixed(2);
            });

        document.getElementById(
            'summarySubtotal'
        ).textContent = subtotal.toFixed(2);

        document.getElementById(
            'summaryTax'
        ).textContent = tax.toFixed(2);

        document.getElementById(
            'summaryTotal'
        ).textContent = total.toFixed(2);
    }

    function bindLine(row) {
        var productSelect =
            row.querySelector('.product-select');

        var itemName =
            row.querySelector('.item-name');

        var unitCost =
            row.querySelector('.unit-cost');

        var unitPrice =
            row.querySelector('.unit-price');

        productSelect.addEventListener(
            'change',
            function () {
                var option =
                    productSelect.options[
                        productSelect.selectedIndex
                    ];

                if (
                    option &&
                    option.value !== ''
                ) {
                    itemName.value =
                        option.getAttribute(
                            'data-name'
                        ) || '';

                    unitCost.value =
                        option.getAttribute(
                            'data-cost'
                        ) || '0';

                    unitPrice.value =
                        option.getAttribute(
                            'data-price'
                        ) || '0';
                }

                recalculate();
            }
        );

        row.querySelectorAll('input').forEach(
            function (input) {
                input.addEventListener(
                    'input',
                    recalculate
                );
            }
        );

        row.querySelector('.remove-line')
            .addEventListener(
                'click',
                function () {
                    row.remove();

                    if (
                        lineBody.querySelectorAll(
                            '.job-line-row'
                        ).length === 0
                    ) {
                        addLine();
                    }

                    recalculate();
                }
            );
    }

    function addLine() {
        var fragment =
            lineTemplate.content.cloneNode(true);

        var row =
            fragment.querySelector('.job-line-row');

        bindLine(row);
        lineBody.appendChild(fragment);
        recalculate();
    }

    if (clientSelect) {
        clientSelect.addEventListener(
            'change',
            filterRelations
        );
    }

    if (jobType) {
        jobType.addEventListener(
            'change',
            toggleRecurrence
        );
    }

    if (addLineButton) {
        addLineButton.addEventListener(
            'click',
            addLine
        );
    }

    filterRelations();
    toggleRecurrence();
    addLine();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
