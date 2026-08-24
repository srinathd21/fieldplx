<?php
/**
 * FieldPlx - Edit Quote
 *
 * Upload as:
 * /public_html/quote-edit.php
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
            'quote-edit.php?id=' .
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
        'quotes.manage',
        'You do not have permission to edit quotes.'
    );
}

/*
|--------------------------------------------------------------------------
| Page settings
|--------------------------------------------------------------------------
*/

$pageTitle = 'Edit Quote - FieldPlx';
$activePage = 'quotes';
$searchPlaceholder = 'Search quotes...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$errors = array();

$quoteId = 0;

if (
    isset($_GET['id']) &&
    (int) $_GET['id'] > 0
) {
    $quoteId = (int) $_GET['id'];
} elseif (
    isset($_GET['quote_id']) &&
    (int) $_GET['quote_id'] > 0
) {
    $quoteId = (int) $_GET['quote_id'];
} elseif (
    isset($_POST['quote_id']) &&
    (int) $_POST['quote_id'] > 0
) {
    $quoteId = (int) $_POST['quote_id'];
}

if ($quoteId <= 0) {
    $_SESSION['flash_error'] =
        'Please select a quote to edit.';

    header('Location: quotes.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('quoteEditFetchAssoc')) {
    function quoteEditFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('quoteEditFetchAll')) {
    function quoteEditFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('quoteEditValue')) {
    function quoteEditValue(
        $key,
        array $record,
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

if (!function_exists('quoteEditNullable')) {
    function quoteEditNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('quoteEditCsrfToken')) {
    function quoteEditCsrfToken()
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

if (!function_exists('quoteEditVerifyCsrf')) {
    function quoteEditVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('quoteEditLogActivity')) {
    function quoteEditLogActivity(
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
                'quote_updated',
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
            'Quote updated: ' .
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
| Load quote
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
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
        converted_job_id,
        financing_required,
        financing_status,
        created_by,
        created_at,
        updated_at,
        archived_at
    FROM quotes
    WHERE id = ?
      AND tenant_id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit(
        'Unable to prepare quote details: ' .
        e($conn->error)
    );
}

$stmt->bind_param(
    'ii',
    $quoteId,
    $tenantId
);

$stmt->execute();
$quote = quoteEditFetchAssoc($stmt);
$stmt->close();

if (!$quote) {
    http_response_code(404);
    exit('Quote not found.');
}

/*
|--------------------------------------------------------------------------
| Selectable data
|--------------------------------------------------------------------------
*/

$clients = array();
$properties = array();
$propertiesByClient = array();
$requests = array();
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
    $clients = quoteEditFetchAll($stmt);
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
    $properties = quoteEditFetchAll($stmt);
    $stmt->close();
}

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
    ORDER BY created_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $requests = quoteEditFetchAll($stmt);
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
    $products = quoteEditFetchAll($stmt);
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
    $taxRates = quoteEditFetchAll($stmt);
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
    FROM quote_line_items
    WHERE quote_id = ?
      AND tenant_id = ?
    ORDER BY sort_order ASC, id ASC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $quoteId,
        $tenantId
    );

    $stmt->execute();
    $existingLineItems =
        quoteEditFetchAll($stmt);

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

    if (!quoteEditVerifyCsrf($csrfToken)) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $clientId = isset($_POST['client_id'])
        ? (int) $_POST['client_id']
        : 0;

    $propertyId =
        isset($_POST['property_id']) &&
        (int) $_POST['property_id'] > 0
            ? (int) $_POST['property_id']
            : null;

    $requestId =
        isset($_POST['request_id']) &&
        (int) $_POST['request_id'] > 0
            ? (int) $_POST['request_id']
            : null;

    $title =
        quoteEditValue(
            'title',
            $quote
        );

    $introduction =
        quoteEditValue(
            'introduction',
            $quote
        );

    $status =
        quoteEditValue(
            'status',
            $quote,
            'draft'
        );

    $validUntil =
        quoteEditValue(
            'valid_until',
            $quote
        );

    $depositRequired =
        !empty($_POST['deposit_required'])
            ? 1
            : 0;

    $depositType =
        $depositRequired
            ? quoteEditValue(
                'deposit_type',
                $quote,
                'fixed'
            )
            : null;

    $depositValue =
        $depositRequired
            ? max(
                0,
                (float) quoteEditValue(
                    'deposit_value',
                    $quote,
                    '0'
                )
            )
            : null;

    $allowedStatuses = array(
        'draft',
        'sent',
        'viewed',
        'awaiting_response',
        'changes_requested',
        'approved',
        'deposit_paid',
        'converted',
        'rejected',
        'expired',
        'archived'
    );

    if ($clientId <= 0) {
        $errors[] =
            'Please select a client.';
    }

    if ($title === '') {
        $errors[] =
            'Quote title is required.';
    }

    if (strlen($title) > 190) {
        $errors[] =
            'Quote title cannot exceed 190 characters.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid quote status.';
    }

    if (
        $validUntil !== '' &&
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $validUntil
        )
    ) {
        $errors[] =
            'Please enter a valid validity date.';
    }

    if (
        $depositRequired &&
        !in_array(
            $depositType,
            array('fixed', 'percent'),
            true
        )
    ) {
        $errors[] =
            'Please select a valid deposit type.';
    }

    if (
        $depositRequired &&
        $depositType === 'percent' &&
        $depositValue > 100
    ) {
        $errors[] =
            'Deposit percentage cannot exceed 100%.';
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
     * Parse line items and recalculate tax on server.
     */
    $lineItems = array();
    $subtotal = 0.00;
    $taxTotal = 0.00;
    $grandTotal = 0.00;

    $productIds =
        isset($_POST['product_service_id']) &&
        is_array($_POST['product_service_id'])
            ? $_POST['product_service_id']
            : array();

    $itemNames =
        isset($_POST['item_name']) &&
        is_array($_POST['item_name'])
            ? $_POST['item_name']
            : array();

    $descriptions =
        isset($_POST['item_description']) &&
        is_array($_POST['item_description'])
            ? $_POST['item_description']
            : array();

    $quantities =
        isset($_POST['quantity']) &&
        is_array($_POST['quantity'])
            ? $_POST['quantity']
            : array();

    $unitCosts =
        isset($_POST['unit_cost']) &&
        is_array($_POST['unit_cost'])
            ? $_POST['unit_cost']
            : array();

    $unitPrices =
        isset($_POST['unit_price']) &&
        is_array($_POST['unit_price'])
            ? $_POST['unit_price']
            : array();

    $taxRateIds =
        isset($_POST['tax_rate_id']) &&
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

        $itemName =
            isset($itemNames[$i])
                ? trim((string) $itemNames[$i])
                : '';

        $itemDescription =
            isset($descriptions[$i])
                ? trim((string) $descriptions[$i])
                : '';

        $quantity =
            isset($quantities[$i])
                ? (float) $quantities[$i]
                : 0;

        $unitCost =
            isset($unitCosts[$i])
                ? (float) $unitCosts[$i]
                : 0;

        $unitPrice =
            isset($unitPrices[$i])
                ? (float) $unitPrices[$i]
                : 0;

        $taxRateId =
            isset($taxRateIds[$i]) &&
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
                    'Unable to validate a selected tax rate.';
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
                    'A selected tax rate is invalid or inactive.';
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
            'product_service_id' =>
                $productServiceId,
            'item_name' =>
                $itemName,
            'description' =>
                quoteEditNullable($itemDescription),
            'quantity' =>
                $quantity,
            'unit_cost' =>
                $unitCost,
            'unit_price' =>
                $unitPrice,
            'tax_rate_id' =>
                $taxRateId,
            'tax_amount' =>
                $taxAmount,
            'line_total' =>
                $lineTotal,
            'sort_order' =>
                $i
        );
    }

    if (empty($lineItems)) {
        $errors[] =
            'Please add at least one quote item.';
    }

    $depositAmount = 0.00;

    if ($depositRequired) {
        if ($depositType === 'percent') {
            $depositAmount =
                round(
                    $grandTotal *
                    ($depositValue / 100),
                    2
                );
        } else {
            $depositAmount =
                min(
                    $grandTotal,
                    round($depositValue, 2)
                );
        }
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            $introductionValue =
                quoteEditNullable($introduction);

            $validUntilValue =
                quoteEditNullable($validUntil);

            $sentAt =
                $status === 'sent'
                    ? (
                        !empty($quote['sent_at'])
                            ? $quote['sent_at']
                            : date('Y-m-d H:i:s')
                    )
                    : null;

            $viewedAt =
                $status === 'viewed'
                    ? (
                        !empty($quote['viewed_at'])
                            ? $quote['viewed_at']
                            : date('Y-m-d H:i:s')
                    )
                    : $quote['viewed_at'];

            $approvedAt =
                in_array(
                    $status,
                    array(
                        'approved',
                        'deposit_paid',
                        'converted'
                    ),
                    true
                )
                    ? (
                        !empty($quote['approved_at'])
                            ? $quote['approved_at']
                            : date('Y-m-d H:i:s')
                    )
                    : null;

            $archivedAt =
                $status === 'archived'
                    ? (
                        !empty($quote['archived_at'])
                            ? $quote['archived_at']
                            : date('Y-m-d H:i:s')
                    )
                    : null;

            $stmt = $conn->prepare("
                UPDATE quotes
                SET
                    client_id = ?,
                    property_id = ?,
                    request_id = ?,
                    title = ?,
                    introduction = ?,
                    status = ?,
                    subtotal = ?,
                    discount_total = 0,
                    tax_total = ?,
                    total = ?,
                    deposit_required = ?,
                    deposit_type = ?,
                    deposit_value = ?,
                    deposit_amount = ?,
                    valid_until = ?,
                    sent_at = ?,
                    viewed_at = ?,
                    approved_at = ?,
                    archived_at = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the quote update operation: ' .
                    $conn->error
                );
            }

            /*
             * 20 variables / 20 type characters
             */
            $stmt->bind_param(
                'iiisssdddissddssssii',
                $clientId,
                $propertyId,
                $requestId,
                $title,
                $introductionValue,
                $status,
                $subtotal,
                $taxTotal,
                $grandTotal,
                $depositRequired,
                $depositType,
                $depositValue,
                $depositAmount,
                $validUntilValue,
                $sentAt,
                $viewedAt,
                $approvedAt,
                $archivedAt,
                $quoteId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Quote could not be updated: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            $stmt = $conn->prepare("
                DELETE FROM quote_line_items
                WHERE quote_id = ?
                  AND tenant_id = ?
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to clear existing quote items: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'ii',
                $quoteId,
                $tenantId
            );

            $stmt->execute();
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
                    markup_percent,
                    margin_percent,
                    discount_amount,
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
                    NULL,
                    0,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare quote items: ' .
                    $conn->error
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
                    'iiissdddidddi',
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

            $conn->commit();

            quoteEditLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $quoteId,
                $clientId,
                $quote['quote_no'],
                $title
            );

            $_SESSION['flash_success'] =
                'Quote updated successfully.';

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
    (int) quoteEditValue(
        'client_id',
        $quote,
        '0'
    );

$selectedPropertyId =
    (int) quoteEditValue(
        'property_id',
        $quote,
        '0'
    );

$selectedRequestId =
    (int) quoteEditValue(
        'request_id',
        $quote,
        '0'
    );

$selectedStatus =
    quoteEditValue(
        'status',
        $quote,
        'draft'
    );

$depositRequiredChecked =
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? !empty($_POST['deposit_required'])
        : !empty($quote['deposit_required']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $renderLineItems = array();

    $postedProductIds =
        isset($_POST['product_service_id']) &&
        is_array($_POST['product_service_id'])
            ? $_POST['product_service_id']
            : array();

    $postedNames =
        isset($_POST['item_name']) &&
        is_array($_POST['item_name'])
            ? $_POST['item_name']
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
    $renderLineItems =
        $existingLineItems;
}

$csrfToken =
    quoteEditCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
:root {
    --fieldplx-primary: #74b824;
    --fieldplx-primary-dark: #5d971b;
    --fieldplx-text: #0b1933;
    --fieldplx-muted: #6f7b90;
    --fieldplx-border: #e5eaf1;
    --fieldplx-surface: #ffffff;
    --fieldplx-background: #f6f8fb;
    --fieldplx-topbar-height: 70px;
    --fieldplx-sidebar-width: 250px;
    --fieldplx-sidebar-collapsed-width: 78px;
    --qe-navy: #001131;
    --qe-navy-light: #071f49;
    --qe-blue: #123d70;
    --qe-primary: #74b824;
    --qe-primary-dark: #5d971b;
    --qe-primary-soft: #f0f8e5;
    --qe-red: #e45b66;
    --qe-bg: #f6f8fb;
    --qe-text: #0b1933;
    --qe-muted: #6f7b90;
    --qe-border: #e5eaf1;
}

body {
    background: var(--qe-bg) !important;
    color: var(--qe-text);
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 14px;
}

/* Exact new FieldPlx dashboard shell */
.fieldplx-topbar {
    min-height: 70px !important;
    margin-left: var(--fieldplx-sidebar-width);
    width: calc(100% - var(--fieldplx-sidebar-width));
    background: #fff !important;
    border-bottom: 1px solid var(--qe-border) !important;
    box-shadow: 0 3px 14px rgba(0,17,49,.035);
    backdrop-filter: none !important;
    transition: margin-left .25s ease, width .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: var(--fieldplx-sidebar-collapsed-width);
    width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
}
.fieldplx-topbar-inner {
    min-height: 70px !important;
    padding: 0 27px !important;
    gap: 13px !important;
}
.fieldplx-page-heading { display: none !important; }
.fieldplx-menu-toggle,
.fieldplx-topbar-action {
    width: 41px !important;
    height: 41px !important;
    border: 0 !important;
    border-radius: 9px !important;
    color: var(--qe-navy) !important;
    background: transparent !important;
}
.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
    color: var(--qe-navy) !important;
    background: var(--qe-primary-soft) !important;
}
.fieldplx-search-wrap { width: 280px !important; margin-left: auto; }
.fieldplx-search-input {
    height: 41px !important;
    padding-left: 38px !important;
    border: 0 !important;
    border-radius: 8px !important;
    background: #f5f8fb !important;
    color: var(--qe-text) !important;
    font-size: 14px !important;
}
.fieldplx-search-input:focus {
    background: #f5f8fb !important;
    box-shadow: 0 0 0 3px rgba(116,184,36,.14) !important;
}
.fieldplx-profile-button {
    padding: 2px !important;
    border: 0 !important;
    border-radius: 9px !important;
    background: transparent !important;
}
.fieldplx-profile-button:hover { background: var(--qe-primary-soft) !important; }
.fieldplx-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    border: 0 !important;
    color: var(--qe-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
    font-size: 14px !important;
    font-weight: 800 !important;
}
.fieldplx-profile-name { font-size: 14px !important; }
.fieldplx-profile-role { color: var(--qe-muted) !important; font-size: 12px !important; }
.fieldplx-notification-count { background: var(--qe-red) !important; }
.fieldplx-dropdown,
.fieldplx-profile-menu {
    border-color: var(--qe-border) !important;
    box-shadow: 0 18px 45px rgba(29,38,74,.14) !important;
}
.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover { color: var(--qe-primary-dark) !important; }

.fieldplx-sidebar {
    width: var(--fieldplx-sidebar-width) !important;
    min-width: var(--fieldplx-sidebar-width) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 1045 !important;
    color: #fff !important;
    background: linear-gradient(180deg,var(--qe-navy-light),var(--qe-navy)) !important;
    border-top: 4px solid var(--qe-primary) !important;
    border-right: 0 !important;
    transition: width .25s ease, min-width .25s ease, transform .25s ease !important;
}
body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: var(--fieldplx-sidebar-collapsed-width) !important;
    min-width: var(--fieldplx-sidebar-collapsed-width) !important;
}
.fieldplx-sidebar-header {
    min-height: 68px !important;
    padding: 9px 14px 10px !important;
    border-bottom: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-brand { color: #fff !important; }
.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
    width: 40px !important;
    height: 40px !important;
    flex: 0 0 40px !important;
    border-radius: 10px !important;
}
.fieldplx-sidebar-logo-placeholder {
    color: #fff !important;
    background: linear-gradient(135deg,#8fd236,#68aa1d) !important;
    font-size: 18px !important;
}
.fieldplx-sidebar-company-name {
    max-width: 155px !important;
    color: #fff !important;
    font-size: 16px !important;
    font-weight: 700 !important;
}
.fieldplx-sidebar-product-name { color: #9fda55 !important; font-size: 11px !important; }
.fieldplx-sidebar-body { padding: 12px 14px !important; scrollbar-width: none !important; }
.fieldplx-sidebar-body::-webkit-scrollbar { display: none; }
.fieldplx-sidebar-section-label {
    margin: 7px 12px 7px !important;
    color: rgba(255,255,255,.50) !important;
    font-size: 11px !important;
}
.fieldplx-sidebar-nav { gap: 3px !important; }
.fieldplx-sidebar-link {
    min-height: 46px !important;
    margin-bottom: 3px !important;
    padding: 0 14px !important;
    gap: 15px !important;
    border-radius: 9px !important;
    color: rgba(255,255,255,.94) !important;
    font-size: 15px !important;
    font-weight: 600 !important;
}
.fieldplx-sidebar-link:hover { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link {
    color: #fff !important;
    background: linear-gradient(90deg,#7fc92d,#68aa1d) !important;
    box-shadow: 0 6px 18px rgba(0,17,49,.28) !important;
}
.fieldplx-sidebar-link-icon {
    width: 21px !important;
    height: 21px !important;
    flex: 0 0 21px !important;
    font-size: 19px !important;
}
.fieldplx-sidebar-arrow { color: rgba(255,255,255,.65) !important; }
.fieldplx-sidebar-submenu { padding-left: 36px !important; }
.fieldplx-sidebar-sublink {
    min-height: 34px !important;
    color: rgba(255,255,255,.72) !important;
    font-size: 13px !important;
}
.fieldplx-sidebar-sublink::before { background: rgba(255,255,255,.35) !important; }
.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active { color: #fff !important; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-sublink.active::before { background: #9fda55 !important; }
.fieldplx-sidebar-footer {
    padding: 10px 14px 14px !important;
    border-top: 1px solid rgba(255,255,255,.08) !important;
}
.fieldplx-sidebar-user { min-height: 62px; background: rgba(255,255,255,.08) !important; }
.fieldplx-sidebar-user-name { color: #fff !important; font-size: 14px !important; }
.fieldplx-sidebar-user-role { color: rgba(255,255,255,.60) !important; font-size: 11px !important; }
.fieldplx-sidebar-user-avatar {
    width: 38px !important;
    height: 38px !important;
    flex: 0 0 38px !important;
    border-radius: 50% !important;
    color: var(--qe-navy) !important;
    background: linear-gradient(135deg,#fff,#e8f3d9) !important;
}
.fieldplx-sidebar-logout { color: rgba(255,255,255,.70) !important; }
.fieldplx-sidebar-logout:hover { color: #fff !important; background: rgba(228,91,102,.30) !important; }
.fieldplx-main-layout { display: block !important; min-height: calc(100vh - 70px) !important; }
.fieldplx-main-content {
    margin-left: var(--fieldplx-sidebar-width);
    min-width: 0;
    transition: margin-left .25s ease;
}
body.fieldplx-sidebar-collapsed .fieldplx-main-content { margin-left: var(--fieldplx-sidebar-collapsed-width); }
.fieldplx-content-wrapper { padding: 0 !important; }
.fieldplx-footer { display: none !important; }

/* Quote Edit - approved new FieldPlx component language */
.quote-edit-page {
    width: 100%;
    max-width: 1600px;
    margin: auto;
    padding: 25px 27px 35px;
}

.qe-header {
    min-height: 108px;
    margin-bottom: 18px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border: 1px solid var(--qe-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.qe-header-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 16px;
}
.qe-header-icon {
    width: 58px;
    height: 58px;
    flex: 0 0 58px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(135deg,var(--qe-blue),var(--qe-navy));
    box-shadow: 0 8px 22px rgba(0,17,49,.16);
    font-size: 23px;
}
.qe-header h1 {
    margin: 0 0 7px;
    color: var(--qe-text);
    font-size: 28px;
    line-height: 1.1;
    font-weight: 700;
}
.qe-header p {
    margin: 0;
    color: var(--qe-muted);
    font-size: 14px;
    line-height: 1.5;
}
.qe-header-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.qe-back,
.qe-btn {
    min-height: 46px;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--qe-border);
    border-radius: 9px;
    background: #fff;
    color: #53627a;
    box-shadow: 0 4px 14px rgba(31,43,88,.04);
    font-family: Arial, Helvetica, sans-serif;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: .18s ease;
}
.qe-back i,
.qe-btn i { font-size: 14px; }
.qe-back:hover,
.qe-btn.secondary:hover {
    border-color: #cfe3ae;
    color: var(--qe-primary-dark);
    background: #f9fcf4;
}
.qe-btn.primary {
    border-color: var(--qe-primary);
    background: var(--qe-primary);
    color: #fff;
    box-shadow: 0 6px 16px rgba(116,184,36,.20);
}
.qe-btn.primary:hover {
    border-color: var(--qe-primary-dark);
    background: var(--qe-primary-dark);
    color: #fff;
}

.qe-alert {
    margin-bottom: 18px;
    padding: 14px 16px;
    border: 1px solid #f1c7cb;
    border-radius: 9px;
    background: #fff5f6;
    color: #b5434d;
    box-shadow: 0 4px 14px rgba(31,43,88,.035);
    font-size: 13px;
    line-height: 1.65;
}
.qe-alert ul { margin: 0; padding-left: 20px; }

.qe-layout {
    display: grid;
    grid-template-columns: minmax(0,1.62fr) minmax(335px,.68fr);
    gap: 18px;
    align-items: start;
}
.qe-layout > main,
.qe-layout > aside { min-width: 0; }
.qe-layout > aside {
    position: sticky;
    top: 88px;
}

.qe-card {
    overflow: hidden;
    border: 1px solid var(--qe-border);
    border-radius: 9px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(31,43,88,.05);
}
.qe-card + .qe-card { margin-top: 18px; }
.qe-card-head {
    min-height: 68px;
    padding: 16px 18px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    border-bottom: 1px solid var(--qe-border);
    background: #fff;
}
.qe-card-head > div { min-width: 0; }
.qe-card-head h2 {
    margin: 0;
    color: var(--qe-text);
    font-size: 18px;
    line-height: 1.25;
    font-weight: 700;
}
.qe-card-head p {
    margin: 5px 0 0;
    color: #8290a4;
    font-size: 12px;
    line-height: 1.5;
}
.qe-card-head > div::before {
    content: '';
    width: 34px;
    height: 4px;
    display: block;
    margin-bottom: 10px;
    border-radius: 999px;
    background: var(--qe-primary);
}
.qe-card-body { padding: 20px 18px; }

.qe-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 17px 16px;
}
.qe-field { min-width: 0; }
.qe-field.full { grid-column: 1 / -1; }
.qe-label {
    margin-bottom: 8px;
    display: block;
    color: #34455f;
    font-size: 13px;
    line-height: 1.35;
    font-weight: 700;
}
.qe-required { color: var(--qe-red); }

.qe-input,
.qe-select,
.qe-textarea {
    width: 100%;
    min-height: 46px;
    padding: 0 14px;
    border: 1px solid #dfe5ed;
    border-radius: 9px;
    background: #fff;
    color: var(--qe-text);
    font-family: Arial, Helvetica, sans-serif;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.qe-select { cursor: pointer; padding-right: 38px; }
.qe-textarea {
    min-height: 128px;
    padding-top: 12px;
    padding-bottom: 12px;
    resize: vertical;
}
.qe-input::placeholder,
.qe-textarea::placeholder { color: #a1abba; }
.qe-input:hover,
.qe-select:hover,
.qe-textarea:hover { border-color: #cad4df; }
.qe-input:focus,
.qe-select:focus,
.qe-textarea:focus,
.qe-line-input:focus,
.qe-line-select:focus {
    border-color: var(--qe-primary);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(116,184,36,.14);
}
.qe-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin: 0 8px 0 0;
    vertical-align: -4px;
    accent-color: var(--qe-primary);
}

.qe-deposit-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 10px;
    margin-top: 10px;
    padding: 14px;
    border: 1px solid #e7edf3;
    border-radius: 9px;
    background: #fbfcfe;
}

/* Quote line editor */
.qe-line-wrap {
    width: 100%;
    overflow-x: auto;
    padding-bottom: 2px;
}
.qe-line-table {
    width: 100%;
    min-width: 1160px;
    border-collapse: collapse;
    table-layout: fixed;
}
.qe-line-table th,
.qe-line-table td {
    padding: 11px 8px;
    border-bottom: 1px solid #f0f2f6;
    text-align: left;
    vertical-align: top;
}
.qe-line-table th {
    background: #fff;
    color: #65738a;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .02em;
    text-transform: uppercase;
}
.qe-line-table tbody tr:hover { background: #fbfdf8; }
.qe-line-input,
.qe-line-select {
    width: 100%;
    min-width: 0;
    min-height: 42px;
    padding: 0 10px;
    border: 1px solid #dfe5ed;
    border-radius: 8px;
    background: #fff;
    color: #263956;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 13px;
    font-weight: 500;
    box-sizing: border-box;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease;
}
.qe-line-select { cursor: pointer; }
.qe-line-input.item-description {
    color: #66748b;
    font-size: 12px;
}
.qe-line-table th:nth-child(1),
.qe-line-table td:nth-child(1) { width: 19%; }
.qe-line-table th:nth-child(2),
.qe-line-table td:nth-child(2) { width: 21%; }
.qe-line-table th:nth-child(3),
.qe-line-table td:nth-child(3) { width: 8%; }
.qe-line-table th:nth-child(4),
.qe-line-table td:nth-child(4),
.qe-line-table th:nth-child(5),
.qe-line-table td:nth-child(5) { width: 11%; }
.qe-line-table th:nth-child(6),
.qe-line-table td:nth-child(6) { width: 15%; }
.qe-line-table th:nth-child(7),
.qe-line-table td:nth-child(7) { width: 9%; }
.qe-line-table th:nth-child(8),
.qe-line-table td:nth-child(8) { width: 6%; }
.qe-line-table .line-total {
    min-height: 42px;
    display: flex;
    align-items: center;
    color: var(--qe-text);
    font-size: 14px;
    font-weight: 700;
}
.qe-add-line {
    min-height: 42px;
    padding: 0 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border: 1px solid #cfe3ae;
    border-radius: 8px;
    background: #f7fbf1;
    color: var(--qe-primary-dark);
    font-family: inherit;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: .18s ease;
}
.qe-add-line:hover {
    border-color: var(--qe-primary);
    background: var(--qe-primary-soft);
}
.qe-remove-line {
    width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 8px;
    background: #fff0f1;
    color: #c54e58;
    font-size: 14px;
    cursor: pointer;
    transition: .18s ease;
}
.qe-remove-line:hover { background: #ffe3e6; color: #a83943; }

.qe-summary { display: grid; gap: 10px; }
.qe-summary-row {
    min-height: 50px;
    padding: 12px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #edf0f4;
    border-radius: 8px;
    background: #fbfcfe;
    color: #53627a;
    font-size: 13px;
}
.qe-summary-row strong { color: var(--qe-text); font-size: 14px; }
.qe-summary-row.total {
    min-height: 64px;
    border-color: #cfe3ae;
    background: var(--qe-primary-soft);
    color: var(--qe-primary-dark);
    font-size: 15px;
    font-weight: 700;
}
.qe-summary-row.total strong { color: var(--qe-primary-dark); font-size: 22px; }

.qe-actions {
    margin-top: 2px;
    padding: 16px 18px;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    border-top: 1px solid var(--qe-border);
    background: #fbfcfe;
}

@media (max-width: 1180px) {
    .qe-layout { grid-template-columns: 1fr; }
    .qe-layout > aside { position: static; }
}
@media (max-width: 991.98px) {
    .fieldplx-topbar { margin-left: 0 !important; width: 100% !important; }
    .fieldplx-main-content { margin-left: 0 !important; }
    .quote-edit-page { padding: 20px 18px 30px; }
}
@media (max-width: 820px) {
    .qe-line-wrap { overflow: visible; }
    .qe-line-table,
    .qe-line-table tbody,
    .qe-line-table tr,
    .qe-line-table td {
        display: block;
        width: 100% !important;
        min-width: 0;
    }
    .qe-line-table thead { display: none; }
    .qe-line-table tr {
        margin: 0 14px 14px;
        padding: 13px;
        border: 1px solid var(--qe-border);
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 3px 10px rgba(31,43,88,.035);
    }
    .qe-line-table td {
        position: relative;
        padding: 7px 0;
        border: 0;
    }
    .qe-line-table td::before {
        display: block;
        margin-bottom: 6px;
        color: #7b889d;
        font-size: 10px;
        line-height: 1.3;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
    }
    .qe-line-table td:nth-child(1)::before { content: 'Product / Service'; }
    .qe-line-table td:nth-child(2)::before { content: 'Item'; }
    .qe-line-table td:nth-child(3)::before { content: 'Quantity'; }
    .qe-line-table td:nth-child(4)::before { content: 'Unit Cost'; }
    .qe-line-table td:nth-child(5)::before { content: 'Unit Price'; }
    .qe-line-table td:nth-child(6)::before { content: 'Tax'; }
    .qe-line-table td:nth-child(7)::before { content: 'Total'; }
    .qe-line-table td:nth-child(8)::before { content: 'Action'; }
}
@media (max-width: 680px) {
    .fieldplx-topbar-inner { padding: 0 14px !important; }
    .fieldplx-search-wrap { display: none !important; }
    .quote-edit-page { padding: 18px 13px 28px; }
    .qe-header {
        align-items: flex-start;
        flex-direction: column;
        padding: 17px 15px;
    }
    .qe-header-main { align-items: flex-start; }
    .qe-header h1 { font-size: 24px; }
    .qe-header-actions { width: 100%; }
    .qe-back { flex: 1; }
    .qe-grid,
    .qe-deposit-grid { grid-template-columns: 1fr; }
    .qe-field.full { grid-column: auto; }
    .qe-card-head { min-height: 0; padding: 15px; align-items: flex-start; }
    .qe-card-body { padding: 15px; }
    .qe-input,
    .qe-select,
    .qe-textarea,
    .qe-line-input,
    .qe-line-select { font-size: 16px; }
    .qe-actions { padding: 15px; flex-direction: column-reverse; }
    .qe-btn { width: 100%; }
    .qe-add-line { min-height: 40px; }
}
</style>

<div class="quote-edit-page">
    <div class="qe-header">
        <div class="qe-header-main">
            <div class="qe-header-icon">
                <i class="bi bi-file-earmark-text-fill"></i>
            </div>
            <div>
                <h1>Edit Quote</h1>
                <p>
                    Update <?= e($quote['quote_no']); ?> details, pricing, line items, tax, and deposit settings.
                </p>
            </div>
        </div>

        <div class="qe-header-actions">
            <a
                href="quote-view.php?id=<?= (int) $quoteId; ?>"
                class="qe-back"
            >
                <i class="bi bi-eye"></i>
                View Quote
            </a>
            <a
                href="quotes.php"
                class="qe-back"
            >
                <i class="bi bi-arrow-left"></i>
                Quotes
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="qe-alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="quote-edit.php?id=<?= (int) $quoteId; ?>"
        autocomplete="off"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken); ?>"
        >

        <input
            type="hidden"
            name="quote_id"
            value="<?= (int) $quoteId; ?>"
        >

        <div class="qe-layout">
            <main>
                <section class="qe-card">
                    <div class="qe-card-head">
                        <div>
                            <h2>Quote Information</h2>
                            <p>
                                Update client assignment, quote details, status, validity, and deposit settings.
                            </p>
                        </div>
                    </div>

                    <div class="qe-card-body">
                        <div class="qe-grid">
                            <div class="qe-field full">
                                <label class="qe-label">
                                    Client
                                    <span class="qe-required">*</span>
                                </label>

                                <select
                                    name="client_id"
                                    id="quoteClient"
                                    class="qe-select"
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
                                            <?= e($client['display_name']); ?>
                                            <?php if (!empty($client['phone'])): ?>
                                                · <?= e($client['phone']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="qe-field">
                                <label class="qe-label">
                                    Property
                                </label>

                                <select
                                    name="property_id"
                                    id="quoteProperty"
                                    class="qe-select"
                                    data-selected-id="<?= (int) $selectedPropertyId; ?>"
                                >
                                    <option value="">
                                        Select Client First
                                    </option>
                                </select>
                            </div>

                            <div class="qe-field">
                                <label class="qe-label">
                                    Request
                                </label>

                                <select
                                    name="request_id"
                                    id="quoteRequest"
                                    class="qe-select"
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
                                            <?= e($request['request_no']); ?>
                                            · <?= e($request['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="qe-field full">
                                <label class="qe-label">
                                    Quote Title
                                    <span class="qe-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="title"
                                    class="qe-input"
                                    maxlength="190"
                                    value="<?= e(
                                        quoteEditValue(
                                            'title',
                                            $quote
                                        )
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="qe-field full">
                                <label class="qe-label">
                                    Introduction
                                </label>

                                <textarea
                                    name="introduction"
                                    class="qe-textarea"
                                ><?= e(
                                    quoteEditValue(
                                        'introduction',
                                        $quote
                                    )
                                ); ?></textarea>
                            </div>

                            <div class="qe-field">
                                <label class="qe-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    class="qe-select"
                                >
                                    <?php
                                    $statusOptions = array(
                                        'draft' => 'Draft',
                                        'sent' => 'Sent',
                                        'viewed' => 'Viewed',
                                        'awaiting_response' => 'Awaiting Response',
                                        'changes_requested' => 'Changes Requested',
                                        'approved' => 'Approved',
                                        'deposit_paid' => 'Deposit Paid',
                                        'converted' => 'Converted',
                                        'rejected' => 'Rejected',
                                        'expired' => 'Expired',
                                        'archived' => 'Archived'
                                    );

                                    foreach (
                                        $statusOptions as
                                        $statusValue => $statusLabel
                                    ):
                                    ?>
                                        <option
                                            value="<?= e($statusValue); ?>"
                                            <?= $selectedStatus ===
                                                $statusValue
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($statusLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="qe-field">
                                <label class="qe-label">
                                    Valid Until
                                </label>

                                <input
                                    type="date"
                                    name="valid_until"
                                    class="qe-input"
                                    value="<?= e(
                                        quoteEditValue(
                                            'valid_until',
                                            $quote
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="qe-field full">
                                <label class="qe-label">
                                    <input
                                        type="checkbox"
                                        name="deposit_required"
                                        id="depositRequired"
                                        value="1"
                                        <?= $depositRequiredChecked
                                            ? 'checked'
                                            : ''; ?>
                                    >
                                    Deposit Required
                                </label>

                                <div
                                    class="qe-deposit-grid"
                                    id="depositFields"
                                >
                                    <select
                                        name="deposit_type"
                                        class="qe-select"
                                    >
                                        <option
                                            value="fixed"
                                            <?= quoteEditValue(
                                                'deposit_type',
                                                $quote,
                                                'fixed'
                                            ) === 'fixed'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Fixed Amount
                                        </option>

                                        <option
                                            value="percent"
                                            <?= quoteEditValue(
                                                'deposit_type',
                                                $quote
                                            ) === 'percent'
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            Percentage
                                        </option>
                                    </select>

                                    <input
                                        type="number"
                                        name="deposit_value"
                                        class="qe-input"
                                        min="0"
                                        step="0.01"
                                        value="<?= e(
                                            quoteEditValue(
                                                'deposit_value',
                                                $quote,
                                                '0'
                                            )
                                        ); ?>"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="qe-card">
                    <div class="qe-card-head">
                        <div>
                            <h2>Quote Items</h2>
                            <p>
                                Edit products and services with quantities, cost, selling price, tax, and live totals.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="qe-add-line"
                            id="addQuoteLine"
                        >
                            <i class="bi bi-plus-lg"></i>
                            Add Item
                        </button>
                    </div>

                    <div class="qe-line-wrap">
                        <table class="qe-line-table">
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
                <section class="qe-card">
                    <div class="qe-card-head">
                        <div>
                            <h2>Quote Summary</h2>
                            <p>
                                Live pricing summary calculated from the quote items.
                            </p>
                        </div>
                    </div>

                    <div class="qe-card-body">
                        <div class="qe-summary">
                            <div class="qe-summary-row">
                                <span>Subtotal</span>
                                <strong id="quoteSubtotal">
                                    0.00
                                </strong>
                            </div>

                            <div class="qe-summary-row">
                                <span>Tax</span>
                                <strong id="quoteTax">
                                    0.00
                                </strong>
                            </div>

                            <div class="qe-summary-row total">
                                <span>Total</span>
                                <strong id="quoteTotal">
                                    0.00
                                </strong>
                            </div>
                        </div>
                    </div>

                    <div class="qe-actions">
                        <a
                            href="quote-view.php?id=<?= (int) $quoteId; ?>"
                            class="qe-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="qe-btn primary"
                        >
                            <i class="bi bi-check2"></i>
                            Update Quote
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
                class="qe-line-select product-select"
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
                class="qe-line-input item-name"
                placeholder="Item name"
            >

            <input
                type="text"
                name="item_description[]"
                class="qe-line-input item-description"
                placeholder="Description"
                style="margin-top:5px;"
            >
        </td>

        <td>
            <input
                type="number"
                name="quantity[]"
                class="qe-line-input quantity"
                step="0.001"
                min="0.001"
                value="1"
            >
        </td>

        <td>
            <input
                type="number"
                name="unit_cost[]"
                class="qe-line-input unit-cost"
                step="0.01"
                min="0"
                value="0"
            >
        </td>

        <td>
            <input
                type="number"
                name="unit_price[]"
                class="qe-line-input unit-price"
                step="0.01"
                min="0"
                value="0"
            >
        </td>

        <td>
            <select
                name="tax_rate_id[]"
                class="qe-line-select tax-select"
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
                class="qe-line-input tax-display"
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
                class="qe-remove-line remove-line"
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

    var depositRequired =
        document.getElementById('depositRequired');

    var depositFields =
        document.getElementById('depositFields');

    var propertiesByClient = <?= json_encode(
        $propertiesByClient,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var existingLineItems = <?= json_encode(
        $renderLineItems,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ); ?>;

    var initialPropertyId =
        propertySelect
            ? String(
                propertySelect.getAttribute(
                    'data-selected-id'
                ) || ''
            )
            : '';

    function populateProperties(
        clientId,
        selectedId
    ) {
        if (!propertySelect) {
            return;
        }

        propertySelect.innerHTML = '';

        var firstOption =
            document.createElement('option');

        firstOption.value = '';

        if (clientId === '') {
            firstOption.textContent =
                'Select Client First';

            propertySelect.appendChild(
                firstOption
            );

            propertySelect.disabled = true;
            return;
        }

        firstOption.textContent =
            'No Property';

        propertySelect.appendChild(
            firstOption
        );

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

            propertySelect.appendChild(
                option
            );
        });

        propertySelect.disabled = false;
    }

    function filterRequests(
        clientId,
        preserveCurrent
    ) {
        if (!requestSelect) {
            return;
        }

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

                var visible =
                    clientId !== '' &&
                    String(
                        option.getAttribute(
                            'data-client-id'
                        ) || ''
                    ) === String(clientId);

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

    function refreshRelations(
        preserveCurrent
    ) {
        var clientId =
            clientSelect
                ? String(clientSelect.value || '')
                : '';

        populateProperties(
            clientId,
            preserveCurrent
                ? initialPropertyId
                : ''
        );

        filterRequests(
            clientId,
            preserveCurrent
        );
    }

    function toggleDeposit() {
        if (
            !depositRequired ||
            !depositFields
        ) {
            return;
        }

        depositFields.style.display =
            depositRequired.checked
                ? 'grid'
                : 'none';
    }

    function recalculate() {
        var subtotal = 0;
        var tax = 0;
        var total = 0;

        lineBody
            .querySelectorAll(
                '.quote-line-row'
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
                        '.tax-select'
                    );

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

                row.querySelector(
                    '.tax-display'
                ).value =
                    taxAmount.toFixed(2);

                row.querySelector(
                    '.line-total'
                ).textContent =
                    lineTotal.toFixed(2);
            });

        document.getElementById(
            'quoteSubtotal'
        ).textContent =
            subtotal.toFixed(2);

        document.getElementById(
            'quoteTax'
        ).textContent =
            tax.toFixed(2);

        document.getElementById(
            'quoteTotal'
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

        var taxSelect =
            row.querySelector(
                '.tax-select'
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

                    taxSelect.value =
                        option.getAttribute(
                            'data-tax-rate-id'
                        ) || '';
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

        taxSelect.addEventListener(
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
                        '.quote-line-row'
                    ).length === 0
                ) {
                    addLine(null);
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
                '.quote-line-row'
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
                '.tax-select'
            ).value =
                item.tax_rate_id || '';
        }

        recalculate();
    }

    if (clientSelect) {
        clientSelect.addEventListener(
            'change',
            function () {
                initialPropertyId = '';
                refreshRelations(false);
            }
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

    if (depositRequired) {
        depositRequired.addEventListener(
            'change',
            toggleDeposit
        );
    }

    refreshRelations(true);
    toggleDeposit();

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
