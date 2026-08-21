<?php
/**
 * FieldPlx - Edit Job
 *
 * Upload as:
 * /public_html/job-edit.php
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
        rawurlencode(
            'job-edit.php?id=' .
            (isset($_GET['id']) ? (int) $_GET['id'] : 0)
        )
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'jobs.manage',
        'You do not have permission to edit jobs.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Edit Job - FieldPlx';
$activePage = 'jobs';
$searchPlaceholder = 'Search jobs...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$jobId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (
        isset($_POST['job_id'])
            ? (int) $_POST['job_id']
            : 0
    );

if ($jobId <= 0) {
    http_response_code(400);
    exit('A valid job ID is required.');
}

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('jobEditFetchAssoc')) {
    function jobEditFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('jobEditFetchAll')) {
    function jobEditFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('jobEditValue')) {
    function jobEditValue(
        $key,
        $record,
        $default = ''
    ) {
        if (
            isset($_POST[$key]) &&
            !is_array($_POST[$key])
        ) {
            return trim((string) $_POST[$key]);
        }

        if (array_key_exists($key, $record)) {
            return $record[$key] === null
                ? $default
                : (string) $record[$key];
        }

        return $default;
    }
}

if (!function_exists('jobEditNullable')) {
    function jobEditNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('jobEditCsrfToken')) {
    function jobEditCsrfToken()
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

if (!function_exists('jobEditVerifyCsrf')) {
    function jobEditVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('jobEditLogActivity')) {
    function jobEditLogActivity(
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
                'job_updated',
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
            'Job updated: ' .
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
| Load existing job
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
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
        total
    FROM jobs
    WHERE id = ?
      AND tenant_id = ?
      AND deleted_at IS NULL
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load the selected job.');
}

$stmt->bind_param(
    'ii',
    $jobId,
    $tenantId
);

$stmt->execute();
$job = jobEditFetchAssoc($stmt);
$stmt->close();

if (!$job) {
    http_response_code(404);
    exit('Job not found.');
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
$taxRates = array();
$existingLineItems = array();

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
    $clients = jobEditFetchAll($stmt);
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
    $properties = jobEditFetchAll($stmt);
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
    ORDER BY created_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $requests = jobEditFetchAll($stmt);
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
    ORDER BY created_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $quotes = jobEditFetchAll($stmt);
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
    $users = jobEditFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        ps.id,
        ps.item_type,
        ps.name,
        ps.unit_name,
        ps.unit_cost,
        ps.unit_price,
        ps.tax_rate_id,
        COALESCE(tr.rate, 0) AS tax_rate,
        COALESCE(tr.name, 'No Tax') AS tax_name
    FROM product_services ps
    LEFT JOIN tax_rates tr
        ON tr.id = ps.tax_rate_id
       AND tr.tenant_id = ps.tenant_id
       AND tr.is_active = 1
    WHERE ps.tenant_id = ?
      AND ps.deleted_at IS NULL
      AND ps.status = 'active'
    ORDER BY ps.name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $products = jobEditFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        rate
    FROM tax_rates
    WHERE tenant_id = ?
      AND is_active = 1
    ORDER BY rate ASC, name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $taxRates = jobEditFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        product_service_id,
        item_name,
        description,
        quantity,
        unit_cost,
        unit_price,
        tax_rate_id,
        tax_amount,
        line_total,
        sort_order
    FROM job_line_items
    WHERE tenant_id = ?
      AND job_id = ?
    ORDER BY sort_order ASC, id ASC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $jobId
    );

    $stmt->execute();
    $existingLineItems =
        jobEditFetchAll($stmt);
    $stmt->close();
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

    if (!jobEditVerifyCsrf($csrfToken)) {
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

    $title = jobEditValue(
        'title',
        $job
    );

    $description = jobEditValue(
        'description',
        $job
    );

    $jobType = jobEditValue(
        'job_type',
        $job,
        'one_off'
    );

    $status = jobEditValue(
        'status',
        $job,
        'draft'
    );

    $assignedUserId =
        isset($_POST['assigned_user_id']) &&
        (int) $_POST['assigned_user_id'] > 0
            ? (int) $_POST['assigned_user_id']
            : null;

    $startDate = jobEditValue(
        'start_date',
        $job
    );

    $endDate = jobEditValue(
        'end_date',
        $job
    );

    $recurrenceRule =
        jobEditValue(
            'recurrence_rule',
            $job
        );

    $invoicingPreference =
        jobEditValue(
            'invoicing_preference',
            $job,
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
        $errors[] =
            'Please select a valid job type.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid status.';
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
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $startDate
        )
    ) {
        $errors[] =
            'Please enter a valid start date.';
    }

    if (
        $endDate !== '' &&
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $endDate
        )
    ) {
        $errors[] =
            'Please enter a valid end date.';
    }

    if (
        $startDate !== '' &&
        $endDate !== '' &&
        strtotime($endDate) <
        strtotime($startDate)
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

    $productIds =
        isset($_POST['product_service_id']) &&
        is_array($_POST['product_service_id'])
            ? $_POST['product_service_id']
            : array();

    $descriptions =
        isset($_POST['item_description']) &&
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

    $taxRateIds = isset($_POST['tax_rate_id']) &&
        is_array($_POST['tax_rate_id'])
            ? $_POST['tax_rate_id']
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

        $itemDescription =
            isset($descriptions[$i])
                ? trim(
                    (string) $descriptions[$i]
                )
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

        $taxRateId = isset($taxRateIds[$i]) &&
            (int) $taxRateIds[$i] > 0
                ? (int) $taxRateIds[$i]
                : null;

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

        if (
            $unitPrice < 0 ||
            $unitCost < 0
        ) {
            $errors[] =
                'Line item amounts cannot be negative.';
            break;
        }

        $taxPercent = 0.00;

        if ($taxRateId !== null) {
            $stmt = $conn->prepare("
                SELECT rate
                FROM tax_rates
                WHERE id = ?
                  AND tenant_id = ?
                  AND is_active = 1
                LIMIT 1
            ");

            if (!$stmt) {
                $errors[] =
                    'Unable to validate a line-item tax rate.';
                break;
            }

            $stmt->bind_param(
                'ii',
                $taxRateId,
                $tenantId
            );

            $stmt->execute();
            $stmt->bind_result($taxPercent);

            if (!$stmt->fetch()) {
                $stmt->close();

                $errors[] =
                    'A selected line-item tax rate is invalid or inactive.';
                break;
            }

            $stmt->close();
        }

        $lineSubtotal =
            round(
                $quantity * $unitPrice,
                2
            );

        $taxAmount =
            round(
                $lineSubtotal *
                ((float) $taxPercent / 100),
                2
            );

        $lineTotal =
            round(
                $lineSubtotal + $taxAmount,
                2
            );

        $subtotal += $lineSubtotal;
        $taxTotal += $taxAmount;
        $grandTotal += $lineTotal;

        $lineItems[] = array(
            'product_service_id' => $productId,
            'item_name' => $itemName,
            'description' =>
                jobEditNullable(
                    $itemDescription
                ),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'unit_price' => $unitPrice,
            'tax_rate_id' => $taxRateId,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
            'sort_order' => $i
        );
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            $descriptionValue =
                jobEditNullable($description);

            $startDateValue =
                jobEditNullable($startDate);

            $endDateValue =
                jobEditNullable($endDate);

            $recurrenceRuleValue =
                $jobType === 'recurring'
                    ? jobEditNullable(
                        $recurrenceRule
                    )
                    : null;

            $stmt = $conn->prepare("
                UPDATE jobs
                SET
                    client_id = ?,
                    property_id = ?,
                    quote_id = ?,
                    request_id = ?,
                    title = ?,
                    description = ?,
                    job_type = ?,
                    status = ?,
                    assigned_user_id = ?,
                    start_date = ?,
                    end_date = ?,
                    recurrence_rule = ?,
                    invoicing_preference = ?,
                    subtotal = ?,
                    tax_total = ?,
                    total = ?,
                    completed_at = CASE
                        WHEN ? = 'completed'
                         AND completed_at IS NULL
                        THEN NOW()
                        WHEN ? <> 'completed'
                        THEN NULL
                        ELSE completed_at
                    END,
                    closed_at = CASE
                        WHEN ? = 'closed'
                         AND closed_at IS NULL
                        THEN NOW()
                        WHEN ? <> 'closed'
                        THEN NULL
                        ELSE closed_at
                    END,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                  AND deleted_at IS NULL
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the job update operation.'
                );
            }

            /*
             * 22 variables / 22 type characters
             */
            $stmt->bind_param(
                'iiiissssissssdddssssii',
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
                $status,
                $status,
                $status,
                $status,
                $jobId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Job could not be updated: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            /*
             * Replace current active assignments.
             */
            $stmt = $conn->prepare("
                UPDATE job_assignments
                SET removed_at = NOW()
                WHERE tenant_id = ?
                  AND job_id = ?
                  AND removed_at IS NULL
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to update existing job assignments.'
                );
            }

            $stmt->bind_param(
                'ii',
                $tenantId,
                $jobId
            );

            $stmt->execute();
            $stmt->close();

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
                        'Unable to create the new job assignment.'
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
             * Replace line items.
             */
            $stmt = $conn->prepare("
                DELETE FROM job_line_items
                WHERE tenant_id = ?
                  AND job_id = ?
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to clear existing job items.'
                );
            }

            $stmt->bind_param(
                'ii',
                $tenantId,
                $jobId
            );

            $stmt->execute();
            $stmt->close();

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
                        ?,
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

                    $taxRateId =
                        $item['tax_rate_id'];

                    $taxAmount =
                        $item['tax_amount'];

                    $lineTotal =
                        $item['line_total'];

                    $sortOrder =
                        $item['sort_order'];

                    /*
                     * 12 variables / 12 type characters
                     */
                    $stmt->bind_param(
                        'iiissdddiddd',
                        $tenantId,
                        $jobId,
                        $productServiceId,
                        $itemName,
                        $itemDescription,
                        $quantity,
                        $unitCost,
                        $unitPrice,
                        $taxRateId,
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

            $conn->commit();

            jobEditLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $jobId,
                $clientId,
                $job['job_no'],
                $title
            );

            $_SESSION['flash_success'] =
                'Job updated successfully.';

            header(
                'Location: job-view.php?id=' .
                $jobId
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
| Values for rendering
|--------------------------------------------------------------------------
*/

$selectedClientId = (int) jobEditValue(
    'client_id',
    $job,
    '0'
);

$selectedPropertyId = (int) jobEditValue(
    'property_id',
    $job,
    '0'
);

$selectedRequestId = (int) jobEditValue(
    'request_id',
    $job,
    '0'
);

$selectedQuoteId = (int) jobEditValue(
    'quote_id',
    $job,
    '0'
);

$selectedAssignedUserId =
    (int) jobEditValue(
        'assigned_user_id',
        $job,
        '0'
    );

$selectedStatus = jobEditValue(
    'status',
    $job,
    'draft'
);

$selectedJobType = jobEditValue(
    'job_type',
    $job,
    'one_off'
);

$selectedInvoicingPreference =
    jobEditValue(
        'invoicing_preference',
        $job,
        'when_job_complete'
    );

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $renderLineItems = array();

    $postedNames = isset($_POST['item_name']) &&
        is_array($_POST['item_name'])
            ? $_POST['item_name']
            : array();

    $postedProductIds =
        isset($_POST['product_service_id']) &&
        is_array($_POST['product_service_id'])
            ? $_POST['product_service_id']
            : array();

    $postedDescriptions =
        isset($_POST['item_description']) &&
        is_array($_POST['item_description'])
            ? $_POST['item_description']
            : array();

    $postedQuantities =
        isset($_POST['quantity']) &&
        is_array($_POST['quantity'])
            ? $_POST['quantity']
            : array();

    $postedUnitCosts =
        isset($_POST['unit_cost']) &&
        is_array($_POST['unit_cost'])
            ? $_POST['unit_cost']
            : array();

    $postedUnitPrices =
        isset($_POST['unit_price']) &&
        is_array($_POST['unit_price'])
            ? $_POST['unit_price']
            : array();

    $postedTaxRateIds =
        isset($_POST['tax_rate_id']) &&
        is_array($_POST['tax_rate_id'])
            ? $_POST['tax_rate_id']
            : array();

    $postedCount = max(
        count($postedNames),
        count($postedQuantities),
        count($postedUnitPrices)
    );

    for ($i = 0; $i < $postedCount; $i++) {
        $renderLineItems[] = array(
            'product_service_id' =>
                isset($postedProductIds[$i])
                    ? (int) $postedProductIds[$i]
                    : 0,
            'item_name' =>
                isset($postedNames[$i])
                    ? (string) $postedNames[$i]
                    : '',
            'description' =>
                isset($postedDescriptions[$i])
                    ? (string) $postedDescriptions[$i]
                    : '',
            'quantity' =>
                isset($postedQuantities[$i])
                    ? (string) $postedQuantities[$i]
                    : '1',
            'unit_cost' =>
                isset($postedUnitCosts[$i])
                    ? (string) $postedUnitCosts[$i]
                    : '0',
            'unit_price' =>
                isset($postedUnitPrices[$i])
                    ? (string) $postedUnitPrices[$i]
                    : '0',
            'tax_rate_id' =>
                isset($postedTaxRateIds[$i])
                    ? (int) $postedTaxRateIds[$i]
                    : 0
        );
    }
} else {
    $renderLineItems = $existingLineItems;
}

$csrfToken = jobEditCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.job-edit-page {
    --je-primary: #6d28d9;
    --je-text: #111827;
    --je-muted: #6b7280;
    --je-border: #e5e7eb;
}

.je-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.je-header h1 {
    margin: 0;
    color: var(--je-text);
    font-size: 21px;
    font-weight: 700;
}

.je-header p {
    margin: 5px 0 0;
    color: var(--je-muted);
    font-size: 11px;
}

.je-back {
    min-height: 34px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid var(--je-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.je-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.je-alert ul {
    margin: 0;
    padding-left: 18px;
}

.je-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.55fr)
        minmax(290px,.7fr);
    gap: 13px;
}

.je-card {
    overflow: hidden;
    border: 1px solid var(--je-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.je-card + .je-card {
    margin-top: 13px;
}

.je-card-head {
    min-height: 46px;
    padding: 11px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.je-card-head h2 {
    margin: 0;
    color: var(--je-text);
    font-size: 11px;
    font-weight: 700;
}

.je-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.je-card-body {
    padding: 14px;
}

.je-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.je-field {
    min-width: 0;
}

.je-field.full {
    grid-column: 1 / -1;
}

.je-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.je-required {
    color: #dc2626;
}

.je-input,
.je-select,
.je-textarea {
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

.je-textarea {
    min-height: 105px;
    resize: vertical;
}

.je-input:focus,
.je-select:focus,
.je-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.je-line-table-wrap {
    width: 100%;
    overflow: visible;
}

.je-line-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.je-line-table th,
.je-line-table td {
    padding: 7px 5px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    vertical-align: top;
    white-space: normal;
    overflow-wrap: anywhere;
}

.je-line-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.je-line-input,
.je-line-select {
    width: 100%;
    min-width: 0;
    min-height: 34px;
    padding: 7px 6px;
    border: 1px solid #dfe3e8;
    border-radius: 7px;
    background: #fff;
    font-size: 9px;
    box-sizing: border-box;
}

.je-line-table th:nth-child(1),
.je-line-table td:nth-child(1) {
    width: 17%;
}

.je-line-table th:nth-child(2),
.je-line-table td:nth-child(2) {
    width: 18%;
}

.je-line-table th:nth-child(3),
.je-line-table td:nth-child(3) {
    width: 7%;
}

.je-line-table th:nth-child(4),
.je-line-table td:nth-child(4),
.je-line-table th:nth-child(5),
.je-line-table td:nth-child(5) {
    width: 11%;
}

.je-line-table th:nth-child(6),
.je-line-table td:nth-child(6) {
    width: 14%;
}

.je-line-table th:nth-child(7),
.je-line-table td:nth-child(7) {
    width: 9%;
}

.je-line-table th:nth-child(8),
.je-line-table td:nth-child(8) {
    width: 5%;
    text-align: center;
}

.je-remove-line {
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

.je-add-line {
    min-height: 32px;
    padding: 7px 10px;
    border: 1px solid #c4b5fd;
    border-radius: 8px;
    background: #faf8ff;
    color: var(--je-primary);
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.je-summary {
    display: grid;
    gap: 9px;
}

.je-summary-row {
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

.je-summary-row.total {
    border-color: #ddd6fe;
    background: #f5f3ff;
    color: #5b21b6;
    font-size: 11px;
    font-weight: 700;
}

.je-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

.je-btn {
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

.je-btn.secondary {
    border: 1px solid var(--je-border);
    background: #fff;
    color: #374151;
}

.je-btn.primary {
    background: var(--je-primary);
    color: #fff;
}

@media (max-width: 1080px) {
    .je-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 820px) {
    .je-line-table,
    .je-line-table tbody,
    .je-line-table tr,
    .je-line-table td {
        display: block;
        width: 100%;
    }

    .je-line-table thead {
        display: none;
    }

    .je-line-table tr {
        padding: 10px;
        border-bottom: 1px solid #e5e7eb;
    }

    .je-line-table td {
        padding: 5px 0;
        border: 0;
    }

    .je-line-table td::before {
        margin-bottom: 4px;
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .je-line-table td:nth-child(1)::before {
        content: 'Product / Service';
    }

    .je-line-table td:nth-child(2)::before {
        content: 'Item Name';
    }

    .je-line-table td:nth-child(3)::before {
        content: 'Qty';
    }

    .je-line-table td:nth-child(4)::before {
        content: 'Unit Cost';
    }

    .je-line-table td:nth-child(5)::before {
        content: 'Unit Price';
    }

    .je-line-table td:nth-child(6)::before {
        content: 'Tax';
    }

    .je-line-table td:nth-child(7)::before {
        content: 'Line Total';
    }

    .je-line-table td:nth-child(8) {
        text-align: right;
    }
}

@media (max-width: 680px) {
    .je-header {
        flex-direction: column;
    }

    .je-grid {
        grid-template-columns: 1fr;
    }

    .je-field.full {
        grid-column: auto;
    }

    .je-actions {
        flex-direction: column-reverse;
    }

    .je-btn {
        width: 100%;
    }
}
</style>

<div class="job-edit-page">
    <div class="je-header">
        <div>
            <h1>Edit Job</h1>
            <p>
                Update <?= e($job['job_no']); ?> and its job items.
            </p>
        </div>

        <a
            href="job-view.php?id=<?= (int) $jobId; ?>"
            class="je-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Job
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="je-alert">
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
            name="job_id"
            value="<?= (int) $jobId; ?>"
        >

        <div class="je-layout">
            <main>
                <section class="je-card">
                    <div class="je-card-head">
                        <div>
                            <h2>Job Information</h2>
                            <p>
                                Update the customer and job details.
                            </p>
                        </div>
                    </div>

                    <div class="je-card-body">
                        <div class="je-grid">
                            <div class="je-field full">
                                <label class="je-label">
                                    Client
                                    <span class="je-required">*</span>
                                </label>

                                <select
                                    name="client_id"
                                    id="jobClient"
                                    class="je-select"
                                    required
                                >
                                    <option value="">
                                        Select Client
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
                                                !empty(
                                                    $client['phone']
                                                )
                                            ): ?>
                                                ·
                                                <?= e(
                                                    $client['phone']
                                                ); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="je-field">
                                <label class="je-label">
                                    Property
                                </label>

                                <select
                                    name="property_id"
                                    id="jobProperty"
                                    class="je-select"
                                >
                                    <option value="">
                                        No Property
                                    </option>

                                    <?php foreach ($properties as $property): ?>
                                        <?php
                                        $propertyLabel =
                                            trim(
                                                (string) $property['name']
                                            ) !== ''
                                                ? $property['name']
                                                : $property['address_line1'];
                                        ?>
                                        <option
                                            value="<?= (int) $property['id']; ?>"
                                            data-client-id="<?= (int) $property['client_id']; ?>"
                                            <?= $selectedPropertyId ===
                                                (int) $property['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($propertyLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="je-field">
                                <label class="je-label">
                                    Request
                                </label>

                                <select
                                    name="request_id"
                                    id="jobRequest"
                                    class="je-select"
                                >
                                    <option value="">
                                        No Request
                                    </option>

                                    <?php foreach ($requests as $request): ?>
                                        <option
                                            value="<?= (int) $request['id']; ?>"
                                            data-client-id="<?= (int) $request['client_id']; ?>"
                                            <?= $selectedRequestId ===
                                                (int) $request['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e(
                                                $request['request_no']
                                            ); ?>
                                            ·
                                            <?= e(
                                                $request['title']
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="je-field">
                                <label class="je-label">
                                    Quote
                                </label>

                                <select
                                    name="quote_id"
                                    id="jobQuote"
                                    class="je-select"
                                >
                                    <option value="">
                                        No Quote
                                    </option>

                                    <?php foreach ($quotes as $quote): ?>
                                        <option
                                            value="<?= (int) $quote['id']; ?>"
                                            data-client-id="<?= (int) $quote['client_id']; ?>"
                                            <?= $selectedQuoteId ===
                                                (int) $quote['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e(
                                                $quote['quote_no']
                                            ); ?>
                                            ·
                                            <?= e(
                                                !empty(
                                                    $quote['title']
                                                )
                                                    ? $quote['title']
                                                    : 'Quote'
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="je-field">
                                <label class="je-label">
                                    Assigned Worker
                                </label>

                                <select
                                    name="assigned_user_id"
                                    class="je-select"
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
                                            <?= $selectedAssignedUserId ===
                                                (int) $user['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($userName); ?>
                                            <?= !empty(
                                                $user['is_field_worker']
                                            )
                                                ? ' · Field Worker'
                                                : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="je-field full">
                                <label class="je-label">
                                    Job Title
                                    <span class="je-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    class="je-input"
                                    maxlength="190"
                                    value="<?= e(
                                        jobEditValue(
                                            'title',
                                            $job
                                        )
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="je-field full">
                                <label class="je-label">
                                    Description
                                </label>

                                <textarea
                                    name="description"
                                    class="je-textarea"
                                ><?= e(
                                    jobEditValue(
                                        'description',
                                        $job
                                    )
                                ); ?></textarea>
                            </div>

                            <div class="je-field">
                                <label class="je-label">
                                    Job Type
                                </label>

                                <select
                                    name="job_type"
                                    id="jobType"
                                    class="je-select"
                                >
                                    <option
                                        value="one_off"
                                        <?= $selectedJobType ===
                                            'one_off'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        One-off
                                    </option>

                                    <option
                                        value="recurring"
                                        <?= $selectedJobType ===
                                            'recurring'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        Recurring
                                    </option>
                                </select>
                            </div>

                            <div class="je-field">
                                <label class="je-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    class="je-select"
                                >
                                    <?php
                                    $statuses = array(
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

                                    foreach ($statuses as $value => $label):
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

                            <div class="je-field">
                                <label class="je-label">
                                    Start Date
                                </label>

                                <input
                                    type="date"
                                    name="start_date"
                                    class="je-input"
                                    value="<?= e(
                                        jobEditValue(
                                            'start_date',
                                            $job
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="je-field">
                                <label class="je-label">
                                    End Date
                                </label>

                                <input
                                    type="date"
                                    name="end_date"
                                    class="je-input"
                                    value="<?= e(
                                        jobEditValue(
                                            'end_date',
                                            $job
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div
                                class="je-field full"
                                id="recurrenceField"
                            >
                                <label class="je-label">
                                    Recurrence Rule
                                </label>

                                <input
                                    type="text"
                                    name="recurrence_rule"
                                    class="je-input"
                                    value="<?= e(
                                        jobEditValue(
                                            'recurrence_rule',
                                            $job
                                        )
                                    ); ?>"
                                    placeholder="Example: FREQ=WEEKLY;INTERVAL=1"
                                >
                            </div>

                            <div class="je-field full">
                                <label class="je-label">
                                    Invoicing Preference
                                </label>

                                <select
                                    name="invoicing_preference"
                                    class="je-select"
                                >
                                    <option
                                        value="when_job_complete"
                                        <?= $selectedInvoicingPreference ===
                                            'when_job_complete'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        When Job Is Complete
                                    </option>

                                    <option
                                        value="after_each_visit"
                                        <?= $selectedInvoicingPreference ===
                                            'after_each_visit'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        After Each Visit
                                    </option>

                                    <option
                                        value="recurring_schedule"
                                        <?= $selectedInvoicingPreference ===
                                            'recurring_schedule'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        Recurring Schedule
                                    </option>

                                    <option
                                        value="manual"
                                        <?= $selectedInvoicingPreference ===
                                            'manual'
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

                <section class="je-card">
                    <div class="je-card-head">
                        <div>
                            <h2>Job Items</h2>
                            <p>
                                Edit products, services, materials, or fees.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="je-add-line"
                            id="addJobLine"
                        >
                            <i class="bi bi-plus-lg"></i>
                            Add Item
                        </button>
                    </div>

                    <div class="je-line-table-wrap">
                        <table class="je-line-table">
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
                <section class="je-card">
                    <div class="je-card-head">
                        <div>
                            <h2>Job Summary</h2>
                            <p>
                                Totals update from job items.
                            </p>
                        </div>
                    </div>

                    <div class="je-card-body">
                        <div class="je-summary">
                            <div class="je-summary-row">
                                <span>Subtotal</span>
                                <strong id="summarySubtotal">
                                    0.00
                                </strong>
                            </div>

                            <div class="je-summary-row">
                                <span>Tax</span>
                                <strong id="summaryTax">
                                    0.00
                                </strong>
                            </div>

                            <div class="je-summary-row total">
                                <span>Total</span>
                                <strong id="summaryTotal">
                                    0.00
                                </strong>
                            </div>
                        </div>
                    </div>

                    <div class="je-actions">
                        <a
                            href="job-view.php?id=<?= (int) $jobId; ?>"
                            class="je-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="je-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Update Job
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
                class="je-line-select product-select"
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
                        data-tax-rate-id="<?= (int) $product['tax_rate_id']; ?>"
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
                class="je-line-input item-name"
                placeholder="Item name"
            >

            <input
                type="text"
                name="item_description[]"
                class="je-line-input item-description"
                placeholder="Description"
                style="margin-top:5px;"
            >
        </td>

        <td>
            <input
                type="number"
                name="quantity[]"
                class="je-line-input quantity"
                step="0.001"
                min="0.001"
                value="1"
            >
        </td>

        <td>
            <input
                type="number"
                name="unit_cost[]"
                class="je-line-input unit-cost"
                step="0.01"
                min="0"
                value="0"
            >
        </td>

        <td>
            <input
                type="number"
                name="unit_price[]"
                class="je-line-input unit-price"
                step="0.01"
                min="0"
                value="0"
            >
        </td>

        <td>
            <select
                name="tax_rate_id[]"
                class="je-line-select tax-rate-select"
            >
                <option
                    value=""
                    data-rate="0"
                >
                    No Tax
                </option>

                <?php foreach ($taxRates as $taxRate): ?>
                    <option
                        value="<?= (int) $taxRate['id']; ?>"
                        data-rate="<?= e($taxRate['rate']); ?>"
                    >
                        <?= e($taxRate['name']); ?>
                        ·
                        <?= e(
                            rtrim(
                                rtrim(
                                    number_format(
                                        (float) $taxRate['rate'],
                                        3,
                                        '.',
                                        ''
                                    ),
                                    '0'
                                ),
                                '.'
                            )
                        ); ?>%
                    </option>
                <?php endforeach; ?>
            </select>

            <input
                type="text"
                class="je-line-input tax-amount-display"
                value="0.00"
                readonly
                tabindex="-1"
                style="margin-top:5px;background:#f9fafb;"
            >
        </td>

        <td>
            <strong class="line-total">
                0.00
            </strong>
        </td>

        <td>
            <button
                type="button"
                class="je-remove-line remove-line"
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

    var existingLineItems = <?= json_encode(
        $renderLineItems,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    function filterRelatedSelect(
        select,
        clientId
    ) {
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
                    option.getAttribute(
                        'data-client-id'
                    );

                var visible =
                    clientId !== '' &&
                    optionClientId === clientId;

                option.hidden = !visible;

                if (
                    visible &&
                    option.selected
                ) {
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
            clientSelect
                ? clientSelect.value
                : '';

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
            .querySelectorAll(
                '.job-line-row'
            )
            .forEach(function (row) {
                var quantity =
                    parseFloat(
                        row.querySelector(
                            '.quantity'
                        ).value
                    ) || 0;

                var unitPrice =
                    parseFloat(
                        row.querySelector(
                            '.unit-price'
                        ).value
                    ) || 0;

                var taxSelect =
                    row.querySelector(
                        '.tax-rate-select'
                    );

                var selectedTaxOption =
                    taxSelect.options[
                        taxSelect.selectedIndex
                    ];

                var taxPercent =
                    selectedTaxOption
                        ? parseFloat(
                            selectedTaxOption
                                .getAttribute(
                                    'data-rate'
                                )
                        ) || 0
                        : 0;

                var lineSubtotal =
                    quantity * unitPrice;

                var taxAmount =
                    lineSubtotal *
                    (taxPercent / 100);

                var lineTotal =
                    lineSubtotal + taxAmount;

                subtotal += lineSubtotal;
                tax += taxAmount;
                total += lineTotal;

                row.querySelector(
                    '.tax-amount-display'
                ).value =
                    taxAmount.toFixed(2);

                row.querySelector(
                    '.line-total'
                ).textContent =
                    lineTotal.toFixed(2);
            });

        document.getElementById(
            'summarySubtotal'
        ).textContent =
            subtotal.toFixed(2);

        document.getElementById(
            'summaryTax'
        ).textContent =
            tax.toFixed(2);

        document.getElementById(
            'summaryTotal'
        ).textContent =
            total.toFixed(2);
    }

    function bindLine(row) {
        var productSelect =
            row.querySelector(
                '.product-select'
            );

        var itemName =
            row.querySelector(
                '.item-name'
            );

        var unitCost =
            row.querySelector(
                '.unit-cost'
            );

        var unitPrice =
            row.querySelector(
                '.unit-price'
            );

        var taxRateSelect =
            row.querySelector(
                '.tax-rate-select'
            );

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

                    taxRateSelect.value =
                        option.getAttribute(
                            'data-tax-rate-id'
                        ) || '';
                } else {
                    taxRateSelect.value = '';
                }

                recalculate();
            }
        );

        row.querySelectorAll('input')
            .forEach(function (input) {
                input.addEventListener(
                    'input',
                    recalculate
                );
            });

        taxRateSelect.addEventListener(
            'change',
            recalculate
        );

        row.querySelector(
            '.remove-line'
        ).addEventListener(
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

    function addLine(item) {
        var fragment =
            lineTemplate.content
                .cloneNode(true);

        var row =
            fragment.querySelector(
                '.job-line-row'
            );

        bindLine(row);
        lineBody.appendChild(fragment);

        if (item) {
            row.querySelector(
                '.product-select'
            ).value =
                item.product_service_id || '';

            row.querySelector(
                '.item-name'
            ).value =
                item.item_name || '';

            row.querySelector(
                '.item-description'
            ).value =
                item.description || '';

            row.querySelector(
                '.quantity'
            ).value =
                item.quantity || '1';

            row.querySelector(
                '.unit-cost'
            ).value =
                item.unit_cost || '0';

            row.querySelector(
                '.unit-price'
            ).value =
                item.unit_price || '0';

            row.querySelector(
                '.tax-rate-select'
            ).value =
                item.tax_rate_id || '';
        }

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
            function () {
                addLine(null);
            }
        );
    }

    filterRelations();
    toggleRecurrence();

    if (
        Array.isArray(existingLineItems) &&
        existingLineItems.length > 0
    ) {
        existingLineItems.forEach(
            function (item) {
                addLine(item);
            }
        );
    } else {
        addLine(null);
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
