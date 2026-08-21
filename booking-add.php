<?php
/**
 * FieldPlx - Add Booking
 *
 * Upload as:
 * /public_html/booking-add.php
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
        rawurlencode('booking-add.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'bookings.manage',
        'You do not have permission to create bookings.'
    );
}

$pageTitle = 'Add Booking - FieldPlx';
$activePage = 'booking-add';
$searchPlaceholder = 'Search bookings...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('bookingAddFetchAssoc')) {
    function bookingAddFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('bookingAddFetchAll')) {
    function bookingAddFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('bookingAddOld')) {
    function bookingAddOld($key, $default = '')
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

if (!function_exists('bookingAddNullable')) {
    function bookingAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('bookingAddCsrfToken')) {
    function bookingAddCsrfToken()
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

if (!function_exists('bookingAddVerifyCsrf')) {
    function bookingAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('bookingAddNormalizeDateTime')) {
    function bookingAddNormalizeDateTime($value)
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

if (!function_exists('bookingAddGenerateNumber')) {
    function bookingAddGenerateNumber(
        mysqli $conn,
        $tenantId
    ) {
        $prefix = 'BOOK';
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
              AND document_type = 'booking'
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to load the booking number sequence: ' .
                $conn->error
            );
        }

        $stmt->bind_param('i', $tenantId);

        if (!$stmt->execute()) {
            throw new Exception(
                'Unable to execute the booking number query: ' .
                $stmt->error
            );
        }

        $sequence = bookingAddFetchAssoc($stmt);
        $stmt->close();

        $currentPeriod = null;

        if ($sequence) {
            $sequenceId = (int) $sequence['id'];

            $prefix =
                trim((string) $sequence['prefix']) !== ''
                    ? (string) $sequence['prefix']
                    : 'BOOK';

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
                    'Unable to prepare the booking sequence update: ' .
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
                    'Unable to update the booking number sequence: ' .
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
                    'booking',
                    'BOOK',
                    2,
                    6,
                    'never',
                    NULL,
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to create the booking number sequence: ' .
                    $conn->error
                );
            }

            $stmt->bind_param('i', $tenantId);

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to save the booking number sequence: ' .
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

if (!function_exists('bookingAddLogActivity')) {
    function bookingAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $bookingId,
        $clientId,
        $bookingNo,
        $customerName
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
                'booking_created',
                'booking',
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
            'Booking created: ' .
            $bookingNo .
            ' - ' .
            $customerName;

        $details = json_encode(
            array(
                'booking_id' => (int) $bookingId,
                'booking_no' => (string) $bookingNo,
                'customer_name' => (string) $customerName
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $bookingId,
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
$propertiesByClient = array();
$services = array();
$serviceMap = array();
$requests = array();
$users = array();
$serviceTeamMembers = array();

$stmt = $conn->prepare("
    SELECT
        id,
        display_name,
        email,
        phone,
        alternate_phone
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $clients = bookingAddFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        client_id,
        name,
        address_line1,
        address_line2,
        city,
        state,
        postal_code,
        is_primary,
        status
    FROM properties
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status IN ('active', 'inactive')
    ORDER BY
        client_id ASC,
        is_primary DESC,
        COALESCE(NULLIF(name, ''), address_line1) ASC,
        id ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $properties = bookingAddFetchAll($stmt);
    $stmt->close();
}

foreach ($properties as $property) {
    $propertyClientId =
        (int) $property['client_id'];

    $propertyName =
        trim((string) $property['name']) !== ''
            ? (string) $property['name']
            : (string) $property['address_line1'];

    $addressParts = array_filter(
        array(
            $property['address_line1'],
            $property['address_line2'],
            $property['city'],
            $property['state'],
            $property['postal_code']
        ),
        function ($value) {
            return trim((string) $value) !== '';
        }
    );

    $address = implode(', ', $addressParts);
    $label = $propertyName;

    if (
        $address !== '' &&
        strcasecmp(
            trim($propertyName),
            trim($address)
        ) !== 0
    ) {
        $label .= ' · ' . $address;
    }

    if (!empty($property['is_primary'])) {
        $label .= ' · Primary';
    }

    if ($property['status'] === 'inactive') {
        $label .= ' · Inactive';
    }

    if (!isset($propertiesByClient[$propertyClientId])) {
        $propertiesByClient[$propertyClientId] = array();
    }

    $propertiesByClient[$propertyClientId][] = array(
        'id' => (int) $property['id'],
        'label' => $label
    );
}

$stmt = $conn->prepare("
    SELECT
        id,
        product_service_id,
        name,
        description,
        estimated_price,
        duration_minutes,
        buffer_before_minutes,
        buffer_after_minutes
    FROM bookable_services
    WHERE tenant_id = ?
      AND is_active = 1
    ORDER BY name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $services = bookingAddFetchAll($stmt);
    $stmt->close();
}

foreach ($services as $service) {
    $serviceMap[(int) $service['id']] = array(
        'id' => (int) $service['id'],
        'name' => (string) $service['name'],
        'description' => (string) $service['description'],
        'estimated_price' => $service['estimated_price'],
        'duration_minutes' => (int) $service['duration_minutes'],
        'buffer_before_minutes' =>
            (int) $service['buffer_before_minutes'],
        'buffer_after_minutes' =>
            (int) $service['buffer_after_minutes']
    );
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
          'converted',
          'closed',
          'rejected',
          'archived'
      )
    ORDER BY created_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $requests = bookingAddFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name,
        email,
        phone,
        is_bookable,
        is_field_worker
    FROM users
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status = 'active'
      AND is_bookable = 1
    ORDER BY first_name ASC, last_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $users = bookingAddFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        bookable_service_id,
        user_id
    FROM bookable_service_team_members
    WHERE tenant_id = ?
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $teamRows = bookingAddFetchAll($stmt);
    $stmt->close();

    foreach ($teamRows as $teamRow) {
        $serviceId =
            (int) $teamRow['bookable_service_id'];

        if (!isset($serviceTeamMembers[$serviceId])) {
            $serviceTeamMembers[$serviceId] = array();
        }

        $serviceTeamMembers[$serviceId][] =
            (int) $teamRow['user_id'];
    }
}

/*
|--------------------------------------------------------------------------
| GET preselection
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $preselectKeys = array(
        'client_id',
        'property_id',
        'request_id',
        'bookable_service_id',
        'assigned_user_id'
    );

    foreach ($preselectKeys as $key) {
        if (!empty($_GET[$key])) {
            $_POST[$key] =
                (string) (int) $_GET[$key];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Save booking
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!bookingAddVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $clientId =
        isset($_POST['client_id']) &&
        (int) $_POST['client_id'] > 0
            ? (int) $_POST['client_id']
            : null;

    $propertyId =
        isset($_POST['property_id']) &&
        (int) $_POST['property_id'] > 0
            ? (int) $_POST['property_id']
            : null;

    $bookableServiceId =
        isset($_POST['bookable_service_id']) &&
        (int) $_POST['bookable_service_id'] > 0
            ? (int) $_POST['bookable_service_id']
            : null;

    $requestId =
        isset($_POST['request_id']) &&
        (int) $_POST['request_id'] > 0
            ? (int) $_POST['request_id']
            : null;

    $assignedUserId =
        isset($_POST['assigned_user_id']) &&
        (int) $_POST['assigned_user_id'] > 0
            ? (int) $_POST['assigned_user_id']
            : null;

    $customerName =
        bookingAddOld('customer_name');

    $customerEmail =
        bookingAddOld('customer_email');

    $customerPhone =
        bookingAddOld('customer_phone');

    $scheduledStartInput =
        bookingAddOld('scheduled_start');

    $scheduledEndInput =
        bookingAddOld('scheduled_end');

    $status =
        bookingAddOld('status', 'submitted');

    $notes =
        bookingAddOld('notes');

    $allowedStatuses = array(
        'submitted',
        'confirmed',
        'declined',
        'cancelled',
        'converted'
    );

    if ($customerName === '') {
        $errors[] =
            'Customer name is required.';
    }

    if (strlen($customerName) > 190) {
        $errors[] =
            'Customer name cannot exceed 190 characters.';
    }

    if (
        $customerEmail !== '' &&
        !filter_var(
            $customerEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] =
            'Please enter a valid customer email address.';
    }

    if ($bookableServiceId === null) {
        $errors[] =
            'Please select a bookable service.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid booking status.';
    }

    $scheduledStart =
        bookingAddNormalizeDateTime(
            $scheduledStartInput
        );

    $scheduledEnd =
        bookingAddNormalizeDateTime(
            $scheduledEndInput
        );

    if (
        $scheduledStartInput !== '' &&
        $scheduledStart === null
    ) {
        $errors[] =
            'Please enter a valid schedule start date and time.';
    }

    if (
        $scheduledEndInput !== '' &&
        $scheduledEnd === null
    ) {
        $errors[] =
            'Please enter a valid schedule end date and time.';
    }

    /*
     * Validate client.
     */
    $selectedClient = null;

    if (
        empty($errors) &&
        $clientId !== null
    ) {
        $stmt = $conn->prepare("
            SELECT
                id,
                display_name,
                email,
                phone
            FROM clients
            WHERE id = ?
              AND tenant_id = ?
              AND deleted_at IS NULL
              AND status <> 'archived'
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected client: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'ii',
                $clientId,
                $tenantId
            );

            $stmt->execute();
            $selectedClient =
                bookingAddFetchAssoc($stmt);

            $stmt->close();

            if (!$selectedClient) {
                $errors[] =
                    'The selected client is not valid.';
            }
        }
    }

    /*
     * Validate property.
     */
    if (
        empty($errors) &&
        $propertyId !== null
    ) {
        if ($clientId === null) {
            $errors[] =
                'Please select a client before selecting a property.';
        } else {
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
                    'Unable to validate the selected property: ' .
                    $conn->error;
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
    }

    /*
     * Validate service and read duration.
     */
    $selectedService = null;

    if (
        empty($errors) &&
        $bookableServiceId !== null
    ) {
        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                description,
                estimated_price,
                duration_minutes,
                buffer_before_minutes,
                buffer_after_minutes
            FROM bookable_services
            WHERE id = ?
              AND tenant_id = ?
              AND is_active = 1
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected service: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'ii',
                $bookableServiceId,
                $tenantId
            );

            $stmt->execute();
            $selectedService =
                bookingAddFetchAssoc($stmt);

            $stmt->close();

            if (!$selectedService) {
                $errors[] =
                    'The selected bookable service is not valid.';
            }
        }
    }

    /*
     * Automatically calculate end time when only start is supplied.
     */
    if (
        empty($errors) &&
        $scheduledStart !== null &&
        $scheduledEnd === null &&
        $selectedService
    ) {
        $durationMinutes = max(
            1,
            (int) $selectedService['duration_minutes']
        );

        $scheduledEnd = date(
            'Y-m-d H:i:s',
            strtotime(
                $scheduledStart .
                ' +' .
                $durationMinutes .
                ' minutes'
            )
        );
    }

    if (
        empty($errors) &&
        $scheduledStart === null &&
        $scheduledEnd !== null
    ) {
        $errors[] =
            'Schedule start is required when schedule end is entered.';
    }

    if (
        empty($errors) &&
        $scheduledStart !== null &&
        $scheduledEnd !== null &&
        strtotime($scheduledEnd) <=
            strtotime($scheduledStart)
    ) {
        $errors[] =
            'Schedule end must be after schedule start.';
    }

    /*
     * Validate request.
     */
    if (
        empty($errors) &&
        $requestId !== null
    ) {
        $stmt = $conn->prepare("
            SELECT
                id,
                client_id,
                property_id
            FROM requests
            WHERE id = ?
              AND tenant_id = ?
              AND archived_at IS NULL
              AND status NOT IN (
                  'converted',
                  'closed',
                  'rejected',
                  'archived'
              )
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the selected request: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'ii',
                $requestId,
                $tenantId
            );

            $stmt->execute();
            $selectedRequest =
                bookingAddFetchAssoc($stmt);

            $stmt->close();

            if (!$selectedRequest) {
                $errors[] =
                    'The selected request is not available.';
            } else {
                if (
                    !empty($selectedRequest['client_id']) &&
                    (
                        $clientId === null ||
                        (int) $selectedRequest['client_id'] !==
                            $clientId
                    )
                ) {
                    $errors[] =
                        'The selected request belongs to a different client.';
                }

                if (
                    !empty($selectedRequest['property_id']) &&
                    $propertyId !== null &&
                    (int) $selectedRequest['property_id'] !==
                        $propertyId
                ) {
                    $errors[] =
                        'The selected request belongs to a different property.';
                }
            }
        }
    }

    /*
     * Validate assigned user and service team membership.
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
              AND is_bookable = 1
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to validate the assigned user: ' .
                $conn->error;
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
                    'The selected assigned user is not bookable.';
            }

            $stmt->close();
        }

        if (
            empty($errors) &&
            isset(
                $serviceTeamMembers[
                    $bookableServiceId
                ]
            ) &&
            !empty(
                $serviceTeamMembers[
                    $bookableServiceId
                ]
            ) &&
            !in_array(
                $assignedUserId,
                $serviceTeamMembers[
                    $bookableServiceId
                ],
                true
            )
        ) {
            $errors[] =
                'The selected user is not assigned to this bookable service.';
        }
    }

    /*
     * Prevent overlapping bookings for the same worker.
     */
    if (
        empty($errors) &&
        $assignedUserId !== null &&
        $scheduledStart !== null &&
        $scheduledEnd !== null &&
        in_array(
            $status,
            array('submitted', 'confirmed'),
            true
        )
    ) {
        $stmt = $conn->prepare("
            SELECT
                id,
                booking_no
            FROM bookings
            WHERE tenant_id = ?
              AND assigned_user_id = ?
              AND status IN (
                  'submitted',
                  'confirmed'
              )
              AND scheduled_start < ?
              AND COALESCE(
                    scheduled_end,
                    DATE_ADD(
                        scheduled_start,
                        INTERVAL 60 MINUTE
                    )
                  ) > ?
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Unable to check booking availability: ' .
                $conn->error;
        } else {
            $stmt->bind_param(
                'iiss',
                $tenantId,
                $assignedUserId,
                $scheduledEnd,
                $scheduledStart
            );

            $stmt->execute();
            $conflict =
                bookingAddFetchAssoc($stmt);

            $stmt->close();

            if ($conflict) {
                $errors[] =
                    'The assigned user already has booking ' .
                    $conflict['booking_no'] .
                    ' during the selected time.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            $bookingNo =
                bookingAddGenerateNumber(
                    $conn,
                    $tenantId
                );

            $customerEmailValue =
                bookingAddNullable($customerEmail);

            $customerPhoneValue =
                bookingAddNullable($customerPhone);

            $notesValue =
                bookingAddNullable($notes);

            $payload = json_encode(
                array(
                    'notes' => $notesValue,
                    'created_via' => 'office',
                    'service_snapshot' => array(
                        'name' =>
                            $selectedService
                                ? $selectedService['name']
                                : null,
                        'estimated_price' =>
                            $selectedService
                                ? $selectedService['estimated_price']
                                : null,
                        'duration_minutes' =>
                            $selectedService
                                ? (int) $selectedService['duration_minutes']
                                : null
                    )
                ),
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            if ($payload === false) {
                throw new Exception(
                    'Unable to prepare the booking notes.'
                );
            }

            $stmt = $conn->prepare("
                INSERT INTO bookings (
                    tenant_id,
                    booking_no,
                    client_id,
                    property_id,
                    bookable_service_id,
                    request_id,
                    assigned_user_id,
                    customer_name,
                    customer_email,
                    customer_phone,
                    scheduled_start,
                    scheduled_end,
                    status,
                    payload,
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
                    ?,
                    ?,
                    NOW(),
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the booking save operation: ' .
                    $conn->error
                );
            }

            /*
             * 14 variables / 14 type characters.
             */
            $stmt->bind_param(
                'isiiiiisssssss',
                $tenantId,
                $bookingNo,
                $clientId,
                $propertyId,
                $bookableServiceId,
                $requestId,
                $assignedUserId,
                $customerName,
                $customerEmailValue,
                $customerPhoneValue,
                $scheduledStart,
                $scheduledEnd,
                $status,
                $payload
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Booking could not be saved: ' .
                    $stmt->error
                );
            }

            $bookingId =
                (int) $stmt->insert_id;

            $stmt->close();

            /*
             * Create a scheduling event for scheduled bookings.
             */
            if (
                $scheduledStart !== null &&
                $scheduledEnd !== null &&
                !in_array(
                    $status,
                    array('declined', 'cancelled'),
                    true
                )
            ) {
                $scheduleStatus =
                    $status === 'converted'
                        ? 'completed'
                        : 'scheduled';

                $scheduleTitle =
                    (
                        $selectedService
                            ? $selectedService['name']
                            : 'Booking'
                    ) .
                    ' - ' .
                    $customerName;

                $scheduleDescription =
                    $notesValue;

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
                        'booking',
                        'booking',
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
                        'Unable to prepare the booking schedule event: ' .
                        $conn->error
                    );
                }

                $stmt->bind_param(
                    'iissiiisssi',
                    $tenantId,
                    $bookingId,
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
                        'Unable to create the booking schedule event: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            bookingAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $bookingId,
                $clientId,
                $bookingNo,
                $customerName
            );

            $_SESSION['flash_success'] =
                'Booking created successfully.';

            header(
                'Location: booking-view.php?id=' .
                $bookingId
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

$selectedClientId =
    (int) bookingAddOld('client_id');

$selectedPropertyId =
    (int) bookingAddOld('property_id');

$selectedRequestId =
    (int) bookingAddOld('request_id');

$selectedServiceId =
    (int) bookingAddOld('bookable_service_id');

$selectedAssignedUserId =
    (int) bookingAddOld('assigned_user_id');

$csrfToken =
    bookingAddCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.booking-add-page {
    --ba-primary: #6d28d9;
    --ba-text: #111827;
    --ba-muted: #6b7280;
    --ba-border: #e5e7eb;
}

.ba-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.ba-header h1 {
    margin: 0;
    color: var(--ba-text);
    font-size: 21px;
    font-weight: 700;
}

.ba-header p {
    margin: 5px 0 0;
    color: var(--ba-muted);
    font-size: 11px;
}

.ba-back,
.ba-btn {
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

.ba-back,
.ba-btn.secondary {
    border: 1px solid var(--ba-border);
    background: #fff;
    color: #374151;
}

.ba-btn.primary {
    border: 0;
    background: var(--ba-primary);
    color: #fff;
    cursor: pointer;
}

.ba-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.ba-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.45fr)
        minmax(300px,.68fr);
    gap: 13px;
    align-items: start;
}

.ba-card {
    overflow: hidden;
    border: 1px solid var(--ba-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.ba-card + .ba-card {
    margin-top: 13px;
}

.ba-card-head {
    min-height: 46px;
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
}

.ba-card-head h2 {
    margin: 0;
    color: var(--ba-text);
    font-size: 11px;
    font-weight: 700;
}

.ba-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.ba-card-body {
    padding: 14px;
}

.ba-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.ba-field {
    min-width: 0;
}

.ba-field.full {
    grid-column: 1 / -1;
}

.ba-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.ba-required {
    color: #dc2626;
}

.ba-input,
.ba-select,
.ba-textarea {
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

.ba-textarea {
    min-height: 100px;
    resize: vertical;
}

.ba-input:focus,
.ba-select:focus,
.ba-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.ba-help {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.ba-summary {
    display: grid;
    gap: 9px;
}

.ba-summary-item {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.ba-summary-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.ba-summary-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.ba-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

@media (max-width: 1050px) {
    .ba-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .ba-header {
        flex-direction: column;
    }

    .ba-grid {
        grid-template-columns: 1fr;
    }

    .ba-field.full {
        grid-column: auto;
    }

    .ba-actions {
        flex-direction: column-reverse;
    }

    .ba-btn {
        width: 100%;
    }
}
</style>

<div class="booking-add-page">
    <div class="ba-header">
        <div>
            <h1>Add Booking</h1>
            <p>
                Schedule a bookable service for a customer.
            </p>
        </div>

        <a href="bookings.php" class="ba-back">
            <i class="bi bi-arrow-left"></i>
            Back to Bookings
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="ba-alert">
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

        <div class="ba-layout">
            <main>
                <section class="ba-card">
                    <div class="ba-card-head">
                        <h2>Customer and Location</h2>
                        <p>
                            Select an existing client or enter guest customer details.
                        </p>
                    </div>

                    <div class="ba-card-body">
                        <div class="ba-grid">
                            <div class="ba-field full">
                                <label class="ba-label">
                                    Existing Client
                                </label>

                                <select
                                    name="client_id"
                                    id="bookingClient"
                                    class="ba-select"
                                >
                                    <option value="">
                                        Guest / No Existing Client
                                    </option>

                                    <?php foreach ($clients as $client): ?>
                                        <option
                                            value="<?= (int) $client['id']; ?>"
                                            data-name="<?= e($client['display_name']); ?>"
                                            data-email="<?= e($client['email']); ?>"
                                            data-phone="<?= e(
                                                trim((string) $client['phone']) !== ''
                                                    ? $client['phone']
                                                    : $client['alternate_phone']
                                            ); ?>"
                                            <?= $selectedClientId ===
                                                (int) $client['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($client['display_name']); ?>

                                            <?php if (
                                                trim((string) $client['phone']) !== ''
                                            ): ?>
                                                · <?= e($client['phone']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ba-field full">
                                <label class="ba-label">
                                    Property
                                </label>

                                <select
                                    name="property_id"
                                    id="bookingProperty"
                                    class="ba-select"
                                    data-selected-id="<?= (int) $selectedPropertyId; ?>"
                                >
                                    <option value="">
                                        Select Client First
                                    </option>
                                </select>
                            </div>

                            <div class="ba-field">
                                <label class="ba-label">
                                    Customer Name
                                    <span class="ba-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="customer_name"
                                    id="customerName"
                                    class="ba-input"
                                    maxlength="190"
                                    value="<?= e(
                                        bookingAddOld('customer_name')
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="ba-field">
                                <label class="ba-label">
                                    Customer Phone
                                </label>

                                <input
                                    type="text"
                                    name="customer_phone"
                                    id="customerPhone"
                                    class="ba-input"
                                    maxlength="50"
                                    value="<?= e(
                                        bookingAddOld('customer_phone')
                                    ); ?>"
                                >
                            </div>

                            <div class="ba-field full">
                                <label class="ba-label">
                                    Customer Email
                                </label>

                                <input
                                    type="email"
                                    name="customer_email"
                                    id="customerEmail"
                                    class="ba-input"
                                    maxlength="190"
                                    value="<?= e(
                                        bookingAddOld('customer_email')
                                    ); ?>"
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ba-card">
                    <div class="ba-card-head">
                        <h2>Service and Schedule</h2>
                        <p>
                            Select a service, employee, date, time, and booking status.
                        </p>
                    </div>

                    <div class="ba-card-body">
                        <div class="ba-grid">
                            <div class="ba-field full">
                                <label class="ba-label">
                                    Bookable Service
                                    <span class="ba-required">*</span>
                                </label>

                                <select
                                    name="bookable_service_id"
                                    id="bookingService"
                                    class="ba-select"
                                    required
                                >
                                    <option value="">
                                        Select Service
                                    </option>

                                    <?php foreach ($services as $service): ?>
                                        <option
                                            value="<?= (int) $service['id']; ?>"
                                            <?= $selectedServiceId ===
                                                (int) $service['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($service['name']); ?>
                                            ·
                                            <?= e(
                                                (int) $service['duration_minutes']
                                            ); ?> minutes

                                            <?php if (
                                                $service['estimated_price'] !== null
                                            ): ?>
                                                · ₹<?= e(
                                                    number_format(
                                                        (float) $service['estimated_price'],
                                                        2
                                                    )
                                                ); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if (empty($services)): ?>
                                    <div class="ba-help">
                                        No active bookable services are available.
                                        Create one before saving a booking.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="ba-field">
                                <label class="ba-label">
                                    Schedule Start
                                </label>

                                <input
                                    type="datetime-local"
                                    name="scheduled_start"
                                    id="scheduledStart"
                                    class="ba-input"
                                    value="<?= e(
                                        bookingAddOld('scheduled_start')
                                    ); ?>"
                                >
                            </div>

                            <div class="ba-field">
                                <label class="ba-label">
                                    Schedule End
                                </label>

                                <input
                                    type="datetime-local"
                                    name="scheduled_end"
                                    id="scheduledEnd"
                                    class="ba-input"
                                    value="<?= e(
                                        bookingAddOld('scheduled_end')
                                    ); ?>"
                                >

                                <div class="ba-help">
                                    Automatically calculated from service duration.
                                </div>
                            </div>

                            <div class="ba-field">
                                <label class="ba-label">
                                    Assign To
                                </label>

                                <select
                                    name="assigned_user_id"
                                    id="bookingAssignedUser"
                                    class="ba-select"
                                >
                                    <option value="">
                                        Unassigned
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
                                            data-user-id="<?= (int) $user['id']; ?>"
                                            <?= $selectedAssignedUserId ===
                                                (int) $user['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($userName); ?>

                                            <?php if (
                                                !empty($user['is_field_worker'])
                                            ): ?>
                                                · Field Worker
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ba-field">
                                <label class="ba-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    id="bookingStatus"
                                    class="ba-select"
                                >
                                    <?php
                                    $statusOptions = array(
                                        'submitted' => 'Submitted',
                                        'confirmed' => 'Confirmed',
                                        'declined' => 'Declined',
                                        'cancelled' => 'Cancelled',
                                        'converted' => 'Converted'
                                    );

                                    $selectedStatus =
                                        bookingAddOld(
                                            'status',
                                            'submitted'
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

                            <div class="ba-field full">
                                <label class="ba-label">
                                    Related Request
                                </label>

                                <select
                                    name="request_id"
                                    id="bookingRequest"
                                    class="ba-select"
                                >
                                    <option value="">
                                        No Related Request
                                    </option>

                                    <?php foreach ($requests as $request): ?>
                                        <option
                                            value="<?= (int) $request['id']; ?>"
                                            data-client-id="<?= (int) $request['client_id']; ?>"
                                            data-property-id="<?= (int) $request['property_id']; ?>"
                                            <?= $selectedRequestId ===
                                                (int) $request['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($request['request_no']); ?>
                                            · <?= e($request['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ba-field full">
                                <label class="ba-label">
                                    Notes
                                </label>

                                <textarea
                                    name="notes"
                                    id="bookingNotes"
                                    class="ba-textarea"
                                    placeholder="Customer request, access instructions, or booking notes."
                                ><?= e(
                                    bookingAddOld('notes')
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="ba-card">
                    <div class="ba-card-head">
                        <h2>Booking Summary</h2>
                        <p>
                            Review the selected booking details.
                        </p>
                    </div>

                    <div class="ba-card-body">
                        <div class="ba-summary">
                            <div class="ba-summary-item">
                                <span class="ba-summary-label">
                                    Customer
                                </span>

                                <span
                                    class="ba-summary-value"
                                    id="summaryCustomer"
                                >
                                    Not entered
                                </span>
                            </div>

                            <div class="ba-summary-item">
                                <span class="ba-summary-label">
                                    Property
                                </span>

                                <span
                                    class="ba-summary-value"
                                    id="summaryProperty"
                                >
                                    No property
                                </span>
                            </div>

                            <div class="ba-summary-item">
                                <span class="ba-summary-label">
                                    Service
                                </span>

                                <span
                                    class="ba-summary-value"
                                    id="summaryService"
                                >
                                    Not selected
                                </span>
                            </div>

                            <div class="ba-summary-item">
                                <span class="ba-summary-label">
                                    Estimated Price
                                </span>

                                <span
                                    class="ba-summary-value"
                                    id="summaryPrice"
                                >
                                    —
                                </span>
                            </div>

                            <div class="ba-summary-item">
                                <span class="ba-summary-label">
                                    Schedule
                                </span>

                                <span
                                    class="ba-summary-value"
                                    id="summarySchedule"
                                >
                                    Not scheduled
                                </span>
                            </div>

                            <div class="ba-summary-item">
                                <span class="ba-summary-label">
                                    Assigned To
                                </span>

                                <span
                                    class="ba-summary-value"
                                    id="summaryAssigned"
                                >
                                    Unassigned
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ba-actions">
                        <a
                            href="bookings.php"
                            class="ba-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="ba-btn primary"
                            <?= empty($services)
                                ? 'disabled'
                                : ''; ?>
                        >
                            <i class="bi bi-check2"></i>
                            Save Booking
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

    var clientSelect =
        document.getElementById('bookingClient');

    var propertySelect =
        document.getElementById('bookingProperty');

    var requestSelect =
        document.getElementById('bookingRequest');

    var serviceSelect =
        document.getElementById('bookingService');

    var assignedSelect =
        document.getElementById('bookingAssignedUser');

    var customerName =
        document.getElementById('customerName');

    var customerEmail =
        document.getElementById('customerEmail');

    var customerPhone =
        document.getElementById('customerPhone');

    var scheduledStart =
        document.getElementById('scheduledStart');

    var scheduledEnd =
        document.getElementById('scheduledEnd');

    var propertiesByClient = <?= json_encode(
        $propertiesByClient,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var serviceMap = <?= json_encode(
        $serviceMap,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var serviceTeamMembers = <?= json_encode(
        $serviceTeamMembers,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var initialPropertyId =
        String(
            propertySelect.getAttribute(
                'data-selected-id'
            ) || ''
        );

    var endManuallyChanged =
        scheduledEnd.value !== '';

    function pad(value) {
        return String(value).padStart(2, '0');
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

    function populateProperties(
        clientId,
        selectedId
    ) {
        propertySelect.innerHTML = '';

        var first =
            document.createElement('option');

        first.value = '';

        if (clientId === '') {
            first.textContent =
                'Select Client First';

            propertySelect.appendChild(first);
            propertySelect.disabled = true;
            return;
        }

        first.textContent =
            'No Property';

        propertySelect.appendChild(first);

        var rows =
            propertiesByClient[
                String(clientId)
            ] || [];

        rows.forEach(function (property) {
            var option =
                document.createElement('option');

            option.value =
                String(property.id);

            option.textContent =
                property.label;

            if (
                String(property.id) ===
                String(selectedId)
            ) {
                option.selected = true;
            }

            propertySelect.appendChild(option);
        });

        propertySelect.disabled = false;
    }

    function filterRequests(
        clientId,
        preserveCurrent
    ) {
        var selectedValue =
            preserveCurrent
                ? String(requestSelect.value || '')
                : '';

        var selectedVisible = false;

        Array.prototype.forEach.call(
            requestSelect.options,
            function (option, index) {
                if (index === 0) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }

                var requestClientId =
                    String(
                        option.getAttribute(
                            'data-client-id'
                        ) || ''
                    );

                var visible =
                    clientId === ''
                        ? requestClientId === '' ||
                          requestClientId === '0'
                        : requestClientId ===
                          String(clientId);

                option.hidden = !visible;
                option.disabled = !visible;

                if (
                    visible &&
                    String(option.value) ===
                    selectedValue
                ) {
                    selectedVisible = true;
                }
            }
        );

        requestSelect.value =
            selectedVisible
                ? selectedValue
                : '';
    }

    function filterAssignedUsers() {
        var serviceId =
            String(serviceSelect.value || '');

        var allowed =
            serviceTeamMembers[serviceId] || [];

        var hasRestriction =
            Array.isArray(allowed) &&
            allowed.length > 0;

        var currentValue =
            String(assignedSelect.value || '');

        var currentVisible =
            currentValue === '';

        Array.prototype.forEach.call(
            assignedSelect.options,
            function (option, index) {
                if (index === 0) {
                    option.hidden = false;
                    option.disabled = false;
                    return;
                }

                var userId =
                    parseInt(
                        option.getAttribute(
                            'data-user-id'
                        ) || '0',
                        10
                    );

                var visible =
                    !hasRestriction ||
                    allowed.indexOf(userId) !== -1;

                option.hidden = !visible;
                option.disabled = !visible;

                if (
                    visible &&
                    String(option.value) ===
                    currentValue
                ) {
                    currentVisible = true;
                }
            }
        );

        if (!currentVisible) {
            assignedSelect.value = '';
        }
    }

    function fillClientDetails() {
        var option =
            clientSelect.options[
                clientSelect.selectedIndex
            ];

        if (
            !option ||
            option.value === ''
        ) {
            return;
        }

        customerName.value =
            option.getAttribute(
                'data-name'
            ) || '';

        customerEmail.value =
            option.getAttribute(
                'data-email'
            ) || '';

        customerPhone.value =
            option.getAttribute(
                'data-phone'
            ) || '';
    }

    function calculateEndTime(force) {
        var serviceId =
            String(serviceSelect.value || '');

        var service =
            serviceMap[serviceId];

        if (
            !service ||
            scheduledStart.value === ''
        ) {
            return;
        }

        if (
            endManuallyChanged &&
            !force
        ) {
            return;
        }

        var start =
            new Date(scheduledStart.value);

        if (isNaN(start.getTime())) {
            return;
        }

        var duration =
            parseInt(
                service.duration_minutes || 60,
                10
            );

        start.setMinutes(
            start.getMinutes() + duration
        );

        scheduledEnd.value =
            formatDateTimeLocal(start);
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

    function updateSummary() {
        var customer =
            customerName.value.trim();

        document.getElementById(
            'summaryCustomer'
        ).textContent =
            customer !== ''
                ? customer
                : 'Not entered';

        document.getElementById(
            'summaryProperty'
        ).textContent =
            selectedText(
                propertySelect,
                'No property'
            );

        document.getElementById(
            'summaryService'
        ).textContent =
            selectedText(
                serviceSelect,
                'Not selected'
            );

        var service =
            serviceMap[
                String(serviceSelect.value || '')
            ];

        document.getElementById(
            'summaryPrice'
        ).textContent =
            service &&
            service.estimated_price !== null &&
            service.estimated_price !== ''
                ? '₹' +
                  Number(
                      service.estimated_price
                  ).toFixed(2)
                : '—';

        var scheduleText =
            scheduledStart.value !== ''
                ? scheduledStart.value
                    .replace('T', ' ')
                : 'Not scheduled';

        if (scheduledEnd.value !== '') {
            scheduleText +=
                ' to ' +
                scheduledEnd.value
                    .replace('T', ' ');
        }

        document.getElementById(
            'summarySchedule'
        ).textContent =
            scheduleText;

        document.getElementById(
            'summaryAssigned'
        ).textContent =
            selectedText(
                assignedSelect,
                'Unassigned'
            );
    }

    clientSelect.addEventListener(
        'change',
        function () {
            initialPropertyId = '';

            fillClientDetails();

            populateProperties(
                String(clientSelect.value || ''),
                ''
            );

            filterRequests(
                String(clientSelect.value || ''),
                false
            );

            updateSummary();
        }
    );

    propertySelect.addEventListener(
        'change',
        updateSummary
    );

    requestSelect.addEventListener(
        'change',
        function () {
            var option =
                requestSelect.options[
                    requestSelect.selectedIndex
                ];

            if (
                option &&
                option.value !== ''
            ) {
                var requestPropertyId =
                    String(
                        option.getAttribute(
                            'data-property-id'
                        ) || ''
                    );

                if (requestPropertyId !== '') {
                    propertySelect.value =
                        requestPropertyId;
                }
            }

            updateSummary();
        }
    );

    serviceSelect.addEventListener(
        'change',
        function () {
            filterAssignedUsers();
            endManuallyChanged = false;
            calculateEndTime(true);
            updateSummary();
        }
    );

    assignedSelect.addEventListener(
        'change',
        updateSummary
    );

    scheduledStart.addEventListener(
        'change',
        function () {
            endManuallyChanged = false;
            calculateEndTime(true);
            updateSummary();
        }
    );

    scheduledEnd.addEventListener(
        'change',
        function () {
            endManuallyChanged = true;
            updateSummary();
        }
    );

    customerName.addEventListener(
        'input',
        updateSummary
    );

    populateProperties(
        String(clientSelect.value || ''),
        initialPropertyId
    );

    filterRequests(
        String(clientSelect.value || ''),
        true
    );

    filterAssignedUsers();

    if (
        scheduledStart.value !== '' &&
        scheduledEnd.value === ''
    ) {
        endManuallyChanged = false;
        calculateEndTime(true);
    }

    updateSummary();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
