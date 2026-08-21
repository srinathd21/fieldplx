<?php
/**
 * FieldPlx - Add Quote
 *
 * Upload as:
 * /public_html/quote-add.php
 *
 * PHP 7.2+ / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('quote-add.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'quotes.manage',
        'You do not have permission to create quotes.'
    );
}

$pageTitle = 'Add Quote - FieldPlx';
$activePage = 'quote-add';
$searchPlaceholder = 'Search quotes...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$errors = array();

if (!function_exists('quoteAddFetchAssoc')) {
    function quoteAddFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('quoteAddFetchAll')) {
    function quoteAddFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('quoteAddOld')) {
    function quoteAddOld($key, $default = '')
    {
        return isset($_POST[$key])
            ? trim((string) $_POST[$key])
            : $default;
    }
}

if (!function_exists('quoteAddNullable')) {
    function quoteAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('quoteAddCsrfToken')) {
    function quoteAddCsrfToken()
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

if (!function_exists('quoteAddVerifyCsrf')) {
    function quoteAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('quoteAddGenerateNumber')) {
    function quoteAddGenerateNumber(
        mysqli $conn,
        $tenantId
    ) {
        $prefix = 'QUO';
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
                  AND document_type = 'quote'
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to load quote number sequence.'
                );
            }

            $stmt->bind_param('i', $tenantId);
            $stmt->execute();

            $row = quoteAddFetchAssoc($stmt);
            $stmt->close();

            if ($row) {
                $sequenceId = (int) $row['id'];

                $prefix =
                    trim((string) $row['prefix']) !== ''
                        ? (string) $row['prefix']
                        : 'QUO';

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
                        'Unable to update quote number sequence.'
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
                        'quote',
                        'QUO',
                        2,
                        6,
                        'never',
                        NULL,
                        NOW()
                    )
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to create quote number sequence.'
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

            return 'QUO-' .
                date('Ymd') .
                '-' .
                substr(
                    (string) ((int) (microtime(true) * 1000)),
                    -6
                );
        }
    }
}

if (!function_exists('quoteAddLogActivity')) {
    function quoteAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $quoteId,
        $clientId,
        $quoteNo,
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
                'quote_created',
                'quote',
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
            'Quote created: ' .
            $quoteNo .
            ' - ' .
            $title;

        $details = json_encode(
            array(
                'quote_id' => (int) $quoteId,
                'quote_no' => (string) $quoteNo,
                'title' => (string) $title
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $stmt->bind_param(
            'iiiiss',
            $tenantId,
            $userId,
            $quoteId,
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
$products = array();
$taxRates = array();

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
    $clients = quoteAddFetchAll($stmt);
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
    $properties = quoteAddFetchAll($stmt);
    $stmt->close();
}

/*
 * Build a client-wise property map.
 * The dropdown is rebuilt in JavaScript whenever the client changes.
 */
$propertiesByClient = array();

foreach ($properties as $property) {
    $propertyClientId =
        (int) $property['client_id'];

    $propertyName =
        trim((string) $property['name']) !== ''
            ? (string) $property['name']
            : (string) $property['address_line1'];

    $locationParts = array_filter(
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

    $location =
        implode(', ', $locationParts);

    $label = $propertyName;

    if (
        $location !== '' &&
        strcasecmp(
            trim($propertyName),
            trim($location)
        ) !== 0
    ) {
        $label .= ' · ' . $location;
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
    $requests = quoteAddFetchAll($stmt);
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
        COALESCE(tr.name, 'No Tax') AS tax_name,
        COALESCE(tr.rate, 0) AS tax_rate
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
    $products = quoteAddFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT id, name, rate
    FROM tax_rates
    WHERE tenant_id = ?
      AND is_active = 1
    ORDER BY rate ASC, name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $taxRates = quoteAddFetchAll($stmt);
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
}

/*
|--------------------------------------------------------------------------
| Save quote
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (!quoteAddVerifyCsrf($csrfToken)) {
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

    $title = quoteAddOld('title');
    $description = quoteAddOld('description');
    $status = quoteAddOld('status', 'draft');
    $issueDate = quoteAddOld(
        'issue_date',
        date('Y-m-d')
    );
    $validUntil = quoteAddOld('valid_until');
    $customerNote = quoteAddOld('customer_note');
    $internalNote = quoteAddOld('internal_note');
    $terms = quoteAddOld('terms');

    $allowedStatuses = array(
        'draft',
        'sent',
        'viewed',
        'approved',
        'rejected',
        'expired',
        'converted',
        'archived'
    );

    if ($clientId <= 0) {
        $errors[] = 'Please select a client.';
    }

    if ($title === '') {
        $errors[] = 'Quote title is required.';
    }

    if (strlen($title) > 190) {
        $errors[] =
            'Quote title cannot exceed 190 characters.';
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Please select a valid quote status.';
    }

    if (
        $issueDate !== '' &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)
    ) {
        $errors[] = 'Please enter a valid issue date.';
    }

    if (
        $validUntil !== '' &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)
    ) {
        $errors[] = 'Please enter a valid validity date.';
    }

    if (
        $issueDate !== '' &&
        $validUntil !== '' &&
        strtotime($validUntil) < strtotime($issueDate)
    ) {
        $errors[] =
            'Valid until date cannot be earlier than the issue date.';
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
     * Parse line items.
     */
    $lineItems = array();
    $subtotal = 0.00;
    $taxTotal = 0.00;
    $grandTotal = 0.00;

    $productIds = isset($_POST['product_service_id']) &&
        is_array($_POST['product_service_id'])
            ? $_POST['product_service_id']
            : array();

    $itemNames = isset($_POST['item_name']) &&
        is_array($_POST['item_name'])
            ? $_POST['item_name']
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
        $productServiceId =
            isset($productIds[$i]) &&
            (int) $productIds[$i] > 0
                ? (int) $productIds[$i]
                : null;

        $itemName = isset($itemNames[$i])
            ? trim((string) $itemNames[$i])
            : '';

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
                'Every quote item must have an item name.';
            break;
        }

        if ($quantity <= 0) {
            $errors[] =
                'Every quote item quantity must be greater than zero.';
            break;
        }

        if (
            $unitCost < 0 ||
            $unitPrice < 0
        ) {
            $errors[] =
                'Quote item amounts cannot be negative.';
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
                    'Unable to validate the selected tax rate.';
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
                    'The selected tax rate is invalid or inactive.';
                break;
            }

            $stmt->close();
        }

        $lineSubtotal =
            round($quantity * $unitPrice, 2);

        $taxAmount =
            round(
                $lineSubtotal *
                ((float) $taxPercent / 100),
                2
            );

        $lineTotal =
            round($lineSubtotal + $taxAmount, 2);

        $subtotal += $lineSubtotal;
        $taxTotal += $taxAmount;
        $grandTotal += $lineTotal;

        $lineItems[] = array(
            'product_service_id' =>
                $productServiceId,
            'item_name' => $itemName,
            'description' =>
                quoteAddNullable($itemDescription),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'unit_price' => $unitPrice,
            'tax_rate_id' => $taxRateId,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
            'sort_order' => $i
        );
    }

    if (empty($lineItems)) {
        $errors[] =
            'Please add at least one quote item.';
    }

    if (empty($errors)) {
        try {
            $quoteNo =
                quoteAddGenerateNumber(
                    $conn,
                    $tenantId
                );

            $conn->begin_transaction();

            $descriptionValue =
                quoteAddNullable($description);

            $issueDateValue =
                quoteAddNullable($issueDate);

            $validUntilValue =
                quoteAddNullable($validUntil);

            $customerNoteValue =
                quoteAddNullable($customerNote);

            $internalNoteValue =
                quoteAddNullable($internalNote);

            $termsValue =
                quoteAddNullable($terms);

            $sentAt = $status === 'sent'
                ? date('Y-m-d H:i:s')
                : null;

            $viewedAt = $status === 'viewed'
                ? date('Y-m-d H:i:s')
                : null;

            $approvedAt = in_array(
                $status,
                array('approved', 'deposit_paid', 'converted'),
                true
            )
                ? date('Y-m-d H:i:s')
                : null;

            $archivedAt = $status === 'archived'
                ? date('Y-m-d H:i:s')
                : null;

            $stmt = $conn->prepare("
                INSERT INTO quotes (
                    tenant_id,
                    quote_no,
                    client_id,
                    property_id,
                    request_id,
                    title,
                    introduction,
                    status,
                    subtotal,
                    discount_total,
                    tax_total,
                    total,
                    deposit_required,
                    deposit_type,
                    deposit_value,
                    deposit_amount,
                    valid_until,
                    sent_at,
                    viewed_at,
                    approved_at,
                    financing_required,
                    financing_status,
                    created_by,
                    created_at,
                    updated_at,
                    archived_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, 0, ?, ?,
                    0, NULL, NULL, 0,
                    ?, ?, ?, ?,
                    0, 'not_required', ?,
                    NOW(), NOW(), ?
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the quote save operation: ' .
                    $conn->error
                );
            }

            /*
             * 17 variables / 17 type characters:
             * i s i i i s s s d d d s s s s i s
             */
            $stmt->bind_param(
                'isiiisssdddssssis',
                $tenantId,
                $quoteNo,
                $clientId,
                $propertyId,
                $requestId,
                $title,
                $descriptionValue,
                $status,
                $subtotal,
                $taxTotal,
                $grandTotal,
                $validUntilValue,
                $sentAt,
                $viewedAt,
                $approvedAt,
                $currentUserId,
                $archivedAt
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Quote could not be saved: ' .
                    $stmt->error
                );
            }

            $quoteId = (int) $stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO quote_line_items (
                    tenant_id,
                    quote_id,
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
                    'Unable to prepare quote line items.'
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
                 * 12 variables / 12 type characters:
                 * i i i s s d d d i d d i
                 */
                $stmt->bind_param(
                    'iiissdddiddi',
                    $tenantId,
                    $quoteId,
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
                        'A quote item could not be saved: ' .
                        $stmt->error
                    );
                }
            }

            $stmt->close();

            if ($requestId !== null) {
                $stmt = $conn->prepare("
                    UPDATE requests
                    SET
                        status = 'quote_required',
                        converted_quote_id = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        'iii',
                        $quoteId,
                        $requestId,
                        $tenantId
                    );

                    $stmt->execute();
                    $stmt->close();
                }
            }

            $conn->commit();

            quoteAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $quoteId,
                $clientId,
                $quoteNo,
                $title
            );

            $_SESSION['flash_success'] =
                'Quote created successfully.';

            header(
                'Location: quote-view.php?id=' .
                $quoteId
            );
            exit;
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            $errors[] = $error->getMessage();
        }
    }
}

$csrfToken = quoteAddCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.quote-add-page {
    --qa-primary: #6d28d9;
    --qa-text: #111827;
    --qa-muted: #6b7280;
    --qa-border: #e5e7eb;
}

.qa-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.qa-header h1 {
    margin: 0;
    color: var(--qa-text);
    font-size: 21px;
    font-weight: 700;
}

.qa-header p {
    margin: 5px 0 0;
    color: var(--qa-muted);
    font-size: 11px;
}

.qa-back {
    min-height: 34px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid var(--qa-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
}

.qa-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.qa-alert ul {
    margin: 0;
    padding-left: 18px;
}

.qa-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.55fr)
        minmax(290px,.7fr);
    gap: 13px;
}

.qa-card {
    overflow: hidden;
    border: 1px solid var(--qa-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.qa-card + .qa-card {
    margin-top: 13px;
}

.qa-card-head {
    min-height: 46px;
    padding: 11px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.qa-card-head h2 {
    margin: 0;
    color: var(--qa-text);
    font-size: 11px;
    font-weight: 700;
}

.qa-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 9px;
}

.qa-card-body {
    padding: 14px;
}

.qa-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 11px;
}

.qa-field {
    min-width: 0;
}

.qa-field.full {
    grid-column: 1 / -1;
}

.qa-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.qa-required {
    color: #dc2626;
}

.qa-input,
.qa-select,
.qa-textarea {
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

.qa-textarea {
    min-height: 90px;
    resize: vertical;
}

.qa-input:focus,
.qa-select:focus,
.qa-textarea:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.1);
}

.qa-line-wrap {
    overflow-x: auto;
}

.qa-line-table {
    width: 100%;
    border-collapse: collapse;
}

.qa-line-table th,
.qa-line-table td {
    padding: 8px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    vertical-align: top;
}

.qa-line-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.qa-line-input,
.qa-line-select {
    width: 100%;
    min-width: 95px;
    min-height: 34px;
    padding: 7px 8px;
    border: 1px solid #dfe3e8;
    border-radius: 7px;
    background: #fff;
    font-size: 9px;
}

.qa-line-name {
    min-width: 170px;
}

.qa-add-line {
    min-height: 32px;
    padding: 7px 10px;
    border: 1px solid #c4b5fd;
    border-radius: 8px;
    background: #faf8ff;
    color: var(--qa-primary);
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.qa-remove-line {
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

.qa-summary {
    display: grid;
    gap: 9px;
}

.qa-summary-row {
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

.qa-summary-row.total {
    border-color: #ddd6fe;
    background: #f5f3ff;
    color: #5b21b6;
    font-size: 11px;
    font-weight: 700;
}

.qa-actions {
    margin-top: 13px;
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}

.qa-btn {
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

.qa-btn.secondary {
    border: 1px solid var(--qa-border);
    background: #fff;
    color: #374151;
}

.qa-btn.primary {
    background: var(--qa-primary);
    color: #fff;
}

@media (max-width: 1080px) {
    .qa-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .qa-header {
        flex-direction: column;
    }

    .qa-grid {
        grid-template-columns: 1fr;
    }

    .qa-field.full {
        grid-column: auto;
    }

    .qa-actions {
        flex-direction: column-reverse;
    }

    .qa-btn {
        width: 100%;
    }
}
</style>

<div class="quote-add-page">
    <div class="qa-header">
        <div>
            <h1>Add Quote</h1>
            <p>
                Create a quote with products, services, pricing, and validity.
            </p>
        </div>

        <a
            href="quotes.php"
            class="qa-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Quotes
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="qa-alert">
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

        <div class="qa-layout">
            <main>
                <section class="qa-card">
                    <div class="qa-card-head">
                        <div>
                            <h2>Quote Information</h2>
                            <p>
                                Select the client and enter the quote details.
                            </p>
                        </div>
                    </div>

                    <div class="qa-card-body">
                        <div class="qa-grid">
                            <div class="qa-field full">
                                <label class="qa-label">
                                    Client
                                    <span class="qa-required">*</span>
                                </label>

                                <select
                                    name="client_id"
                                    id="quoteClient"
                                    class="qa-select"
                                    required
                                >
                                    <option value="">
                                        Select Client
                                    </option>

                                    <?php foreach ($clients as $client): ?>
                                        <option
                                            value="<?= (int) $client['id']; ?>"
                                            <?= (int) quoteAddOld('client_id') === (int) $client['id']
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

                            <div class="qa-field">
                                <label class="qa-label">Property</label>

                                <select
                                    name="property_id"
                                    id="quoteProperty"
                                    class="qa-select"
                                    data-selected-property-id="<?= (int) quoteAddOld('property_id'); ?>"
                                >
                                    <option value="">
                                        Select Client First
                                    </option>
                                </select>
                            </div>

                            <div class="qa-field">
                                <label class="qa-label">Request</label>

                                <select
                                    name="request_id"
                                    id="quoteRequest"
                                    class="qa-select"
                                >
                                    <option value="">
                                        No Request
                                    </option>

                                    <?php foreach ($requests as $request): ?>
                                        <option
                                            value="<?= (int) $request['id']; ?>"
                                            data-client-id="<?= (int) $request['client_id']; ?>"
                                            data-property-id="<?= (int) $request['property_id']; ?>"
                                            <?= (int) quoteAddOld('request_id') === (int) $request['id']
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?= e($request['request_no']); ?>
                                            · <?= e($request['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="qa-field full">
                                <label class="qa-label">
                                    Quote Title
                                    <span class="qa-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    class="qa-input"
                                    maxlength="190"
                                    value="<?= e(quoteAddOld('title')); ?>"
                                    required
                                >
                            </div>

                            <div class="qa-field full">
                                <label class="qa-label">Description</label>

                                <textarea
                                    name="description"
                                    class="qa-textarea"
                                ><?= e(quoteAddOld('description')); ?></textarea>
                            </div>

                            <div class="qa-field">
                                <label class="qa-label">Status</label>

                                <select
                                    name="status"
                                    class="qa-select"
                                >
                                    <option value="draft" <?= quoteAddOld('status', 'draft') === 'draft' ? 'selected' : ''; ?>>
                                        Draft
                                    </option>
                                    <option value="sent" <?= quoteAddOld('status') === 'sent' ? 'selected' : ''; ?>>
                                        Sent
                                    </option>
                                    <option value="approved" <?= quoteAddOld('status') === 'approved' ? 'selected' : ''; ?>>
                                        Accepted
                                    </option>
                                </select>
                            </div>

                            <div class="qa-field">
                                <label class="qa-label">Issue Date</label>

                                <input
                                    type="date"
                                    name="issue_date"
                                    class="qa-input"
                                    value="<?= e(
                                        quoteAddOld(
                                            'issue_date',
                                            date('Y-m-d')
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="qa-field">
                                <label class="qa-label">Valid Until</label>

                                <input
                                    type="date"
                                    name="valid_until"
                                    class="qa-input"
                                    value="<?= e(quoteAddOld('valid_until')); ?>"
                                >
                            </div>

                            <div class="qa-field full">
                                <label class="qa-label">Customer Note</label>

                                <textarea
                                    name="customer_note"
                                    class="qa-textarea"
                                ><?= e(quoteAddOld('customer_note')); ?></textarea>
                            </div>

                            <div class="qa-field full">
                                <label class="qa-label">Terms</label>

                                <textarea
                                    name="terms"
                                    class="qa-textarea"
                                ><?= e(quoteAddOld('terms')); ?></textarea>
                            </div>

                            <div class="qa-field full">
                                <label class="qa-label">Internal Note</label>

                                <textarea
                                    name="internal_note"
                                    class="qa-textarea"
                                ><?= e(quoteAddOld('internal_note')); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="qa-card">
                    <div class="qa-card-head">
                        <div>
                            <h2>Quote Items</h2>
                            <p>
                                Add products, services, materials, or fees.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="qa-add-line"
                            id="addQuoteLine"
                        >
                            <i class="bi bi-plus-lg"></i>
                            Add Item
                        </button>
                    </div>

                    <div class="qa-line-wrap">
                        <table class="qa-line-table">
                            <thead>
                                <tr>
                                    <th>Product / Service</th>
                                    <th>Item Name</th>
                                    <th>Qty</th>
                                    <th>Unit Cost</th>
                                    <th>Unit Price</th>
                                    <th>Tax</th>
                                    <th>Total</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody id="quoteLineBody"></tbody>
                        </table>
                    </div>
                </section>
            </main>

            <aside>
                <section class="qa-card">
                    <div class="qa-card-head">
                        <div>
                            <h2>Quote Summary</h2>
                            <p>
                                Totals update from quote items.
                            </p>
                        </div>
                    </div>

                    <div class="qa-card-body">
                        <div class="qa-summary">
                            <div class="qa-summary-row">
                                <span>Subtotal</span>
                                <strong id="quoteSubtotal">0.00</strong>
                            </div>

                            <div class="qa-summary-row">
                                <span>Tax</span>
                                <strong id="quoteTax">0.00</strong>
                            </div>

                            <div class="qa-summary-row total">
                                <span>Total</span>
                                <strong id="quoteTotal">0.00</strong>
                            </div>
                        </div>
                    </div>

                    <div class="qa-actions">
                        <a
                            href="quotes.php"
                            class="qa-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="qa-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Save Quote
                        </button>
                    </div>
                </section>
            </aside>
        </div>
    </form>
</div>

<template id="quoteLineTemplate">
    <tr class="quote-line-row">
        <td>
            <select
                name="product_service_id[]"
                class="qa-line-select product-select"
            >
                <option value="">Custom Item</option>

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
                class="qa-line-input qa-line-name item-name"
                placeholder="Item name"
            >

            <input
                type="text"
                name="item_description[]"
                class="qa-line-input"
                placeholder="Description"
                style="margin-top:5px;"
            >
        </td>

        <td>
            <input
                type="number"
                name="quantity[]"
                class="qa-line-input quantity"
                step="0.001"
                min="0.001"
                value="1"
            >
        </td>

        <td>
            <input
                type="number"
                name="unit_cost[]"
                class="qa-line-input unit-cost"
                step="0.01"
                min="0"
                value="0"
            >
        </td>

        <td>
            <input
                type="number"
                name="unit_price[]"
                class="qa-line-input unit-price"
                step="0.01"
                min="0"
                value="0"
            >
        </td>

        <td>
            <select
                name="tax_rate_id[]"
                class="qa-line-select tax-select"
            >
                <option value="" data-rate="0">
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
                class="qa-line-input tax-display"
                value="0.00"
                readonly
                tabindex="-1"
                style="margin-top:5px;background:#f9fafb;"
            >
        </td>

        <td>
            <strong class="line-total">0.00</strong>
        </td>

        <td>
            <button
                type="button"
                class="qa-remove-line remove-line"
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
        document.getElementById('quoteClient');

    var propertySelect =
        document.getElementById('quoteProperty');

    var requestSelect =
        document.getElementById('quoteRequest');

    var lineBody =
        document.getElementById('quoteLineBody');

    var lineTemplate =
        document.getElementById('quoteLineTemplate');

    var addLineButton =
        document.getElementById('addQuoteLine');

    var propertiesByClient = <?= json_encode(
        $propertiesByClient,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var initialPropertyId =
        propertySelect
            ? String(
                propertySelect.getAttribute(
                    'data-selected-property-id'
                ) || ''
            )
            : '';

    function populateProperties(
        clientId,
        selectedPropertyId
    ) {
        if (!propertySelect) {
            return;
        }

        propertySelect.innerHTML = '';

        var defaultOption =
            document.createElement('option');

        defaultOption.value = '';

        if (clientId === '') {
            defaultOption.textContent =
                'Select Client First';

            propertySelect.appendChild(
                defaultOption
            );

            propertySelect.disabled = true;
            return;
        }

        defaultOption.textContent =
            'No Property';

        propertySelect.appendChild(
            defaultOption
        );

        var availableProperties =
            propertiesByClient[
                String(clientId)
            ] || [];

        availableProperties.forEach(
            function (property) {
                var option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    String(property.id);

                option.textContent =
                    property.label;

                if (
                    String(property.id) ===
                    String(selectedPropertyId)
                ) {
                    option.selected = true;
                }

                propertySelect.appendChild(
                    option
                );
            }
        );

        propertySelect.disabled = false;
    }

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

    function filterRelations(
        preserveCurrentProperty
    ) {
        var clientId =
            clientSelect
                ? String(clientSelect.value || '')
                : '';

        populateProperties(
            clientId,
            preserveCurrentProperty
                ? initialPropertyId
                : ''
        );

        filterRelatedSelect(
            requestSelect,
            clientId
        );
    }

    function recalculate() {
        var subtotal = 0;
        var tax = 0;
        var total = 0;

        lineBody
            .querySelectorAll('.quote-line-row')
            .forEach(function (row) {
                var quantity =
                    parseFloat(
                        row.querySelector('.quantity').value
                    ) || 0;

                var unitPrice =
                    parseFloat(
                        row.querySelector('.unit-price').value
                    ) || 0;

                var taxSelect =
                    row.querySelector('.tax-select');

                var taxOption =
                    taxSelect.options[
                        taxSelect.selectedIndex
                    ];

                var taxPercent =
                    taxOption
                        ? parseFloat(
                            taxOption.getAttribute(
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

                row.querySelector('.tax-display').value =
                    taxAmount.toFixed(2);

                row.querySelector('.line-total').textContent =
                    lineTotal.toFixed(2);
            });

        document.getElementById(
            'quoteSubtotal'
        ).textContent = subtotal.toFixed(2);

        document.getElementById(
            'quoteTax'
        ).textContent = tax.toFixed(2);

        document.getElementById(
            'quoteTotal'
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

        var taxSelect =
            row.querySelector('.tax-select');

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
                        option.getAttribute('data-name') || '';

                    unitCost.value =
                        option.getAttribute('data-cost') || '0';

                    unitPrice.value =
                        option.getAttribute('data-price') || '0';

                    taxSelect.value =
                        option.getAttribute(
                            'data-tax-rate-id'
                        ) || '';
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

        taxSelect.addEventListener(
            'change',
            recalculate
        );

        row.querySelector('.remove-line')
            .addEventListener(
                'click',
                function () {
                    row.remove();

                    if (
                        lineBody.querySelectorAll(
                            '.quote-line-row'
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
            fragment.querySelector('.quote-line-row');

        bindLine(row);
        lineBody.appendChild(fragment);
        recalculate();
    }

    if (clientSelect) {
        clientSelect.addEventListener(
            'change',
            function () {
                initialPropertyId = '';
                filterRelations(false);
            }
        );
    }

    if (addLineButton) {
        addLineButton.addEventListener(
            'click',
            addLine
        );
    }

    filterRelations(true);
    addLine();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
