<?php
/**
 * FieldPlx - Edit Invoice
 *
 * Upload as:
 * /public_html/invoice-edit.php
 *
 * PHP 7.2+ / MariaDB / MySQLi
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

$requestedInvoiceId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (
        isset($_POST['invoice_id'])
            ? (int) $_POST['invoice_id']
            : 0
    );

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    $redirect = 'invoice-edit.php';

    if ($requestedInvoiceId > 0) {
        $redirect .= '?id=' . $requestedInvoiceId;
    }

    header(
        'Location: login.php?redirect=' .
        rawurlencode($redirect)
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'invoices.manage',
        'You do not have permission to edit invoices.'
    );
}

$pageTitle = 'Edit Invoice - FieldPlx';
$activePage = 'invoices';
$searchPlaceholder = 'Search invoices...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('invoiceEditFetchAssoc')) {
    function invoiceEditFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('invoiceEditFetchAll')) {
    function invoiceEditFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('invoiceEditOld')) {
    function invoiceEditOld($key, $default = '')
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

if (!function_exists('invoiceEditNullable')) {
    function invoiceEditNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('invoiceEditCsrfToken')) {
    function invoiceEditCsrfToken()
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

if (!function_exists('invoiceEditVerifyCsrf')) {
    function invoiceEditVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('invoiceEditMoney')) {
    function invoiceEditMoney($amount, $currencyCode)
    {
        $currencyCode = strtoupper(
            trim((string) $currencyCode)
        );

        $prefixes = array(
            'INR' => 'Rs. ',
            'USD' => '$',
            'GBP' => 'GBP ',
            'EUR' => 'EUR ',
            'CAD' => 'CAD ',
            'AUD' => 'AUD '
        );

        $prefix = isset($prefixes[$currencyCode])
            ? $prefixes[$currencyCode]
            : $currencyCode . ' ';

        return $prefix .
            number_format(
                (float) $amount,
                2
            );
    }
}

if (!function_exists('invoiceEditLabel')) {
    function invoiceEditLabel($value)
    {
        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                (string) $value
            )
        );
    }
}

if (!function_exists('invoiceEditSequencePeriod')) {
    function invoiceEditSequencePeriod($frequency)
    {
        if ($frequency === 'yearly') {
            return date('Y');
        }

        if ($frequency === 'monthly') {
            return date('Y-m');
        }

        return null;
    }
}

if (!function_exists('invoiceEditGenerateNumber')) {
    function invoiceEditGenerateNumber(
        mysqli $conn,
        $tenantId
    ) {
        /*
         * This function must be called inside the invoice transaction.
         * FOR UPDATE protects the tenant sequence from duplicate numbers.
         */

        $prefix = 'INV';
        $number = 1;
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
              AND document_type = 'invoice'
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to load the invoice number sequence.'
            );
        }

        $stmt->bind_param(
            'i',
            $tenantId
        );

        if (!$stmt->execute()) {
            throw new Exception(
                'Unable to read the invoice number sequence.'
            );
        }

        $sequence =
            invoiceEditFetchAssoc($stmt);

        $stmt->close();

        if ($sequence) {
            $sequenceId =
                (int) $sequence['id'];

            $prefix =
                trim(
                    (string) $sequence['prefix']
                ) !== ''
                    ? trim(
                        (string) $sequence['prefix']
                    )
                    : 'INV';

            $number = max(
                1,
                (int) $sequence['next_number']
            );

            $paddingLength = max(
                1,
                min(
                    12,
                    (int) $sequence[
                        'padding_length'
                    ]
                )
            );

            $frequency =
                trim(
                    (string) $sequence[
                        'reset_frequency'
                    ]
                );

            $currentPeriod =
                invoiceEditSequencePeriod(
                    $frequency
                );

            $lastResetPeriod =
                trim(
                    (string) $sequence[
                        'last_reset_period'
                    ]
                );

            if (
                $currentPeriod !== null &&
                $currentPeriod !==
                    $lastResetPeriod
            ) {
                $number = 1;
            }

            $nextNumber =
                $number + 1;

            $stmt = $conn->prepare("
                UPDATE tenant_number_sequences
                SET
                    next_number = ?,
                    last_reset_period = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the invoice number update.'
                );
            }

            $stmt->bind_param(
                'isii',
                $nextNumber,
                $currentPeriod,
                $sequenceId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to update the invoice number sequence.'
                );
            }

            $stmt->close();
        } else {
            $number = 1;
            $paddingLength = 6;
            $nextNumber = 2;

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
                    'invoice',
                    'INV',
                    ?,
                    ?,
                    'never',
                    NULL,
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the invoice number sequence creation.'
                );
            }

            $stmt->bind_param(
                'iii',
                $tenantId,
                $nextNumber,
                $paddingLength
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to create the invoice number sequence.'
                );
            }

            $stmt->close();
        }

        return $prefix .
            '-' .
            str_pad(
                (string) $number,
                $paddingLength,
                '0',
                STR_PAD_LEFT
            );
    }
}

if (!function_exists('invoiceEditLoadInvoice')) {
    function invoiceEditLoadInvoice(
        mysqli $conn,
        $tenantId,
        $invoiceId,
        $forUpdate = false
    ) {
        $sql = "
            SELECT
                i.id,
                i.invoice_no,
                i.client_id,
                i.property_id,
                i.job_id,
                i.visit_id,
                i.quote_id,
                i.status,
                i.issue_date,
                i.due_date,
                i.subtotal,
                i.discount_total,
                i.tax_total,
                i.total,
                i.amount_paid,
                i.balance_due,
                i.payment_terms,
                i.notes,
                i.sent_at,
                i.viewed_at,
                i.paid_at,
                i.created_by,
                i.created_at,
                i.updated_at,
                c.display_name AS client_name,
                t.currency_code
            FROM invoices i
            INNER JOIN clients c
                ON c.id = i.client_id
               AND c.tenant_id = i.tenant_id
               AND c.deleted_at IS NULL
            INNER JOIN tenants t
                ON t.id = i.tenant_id
               AND t.deleted_at IS NULL
            WHERE i.id = ?
              AND i.tenant_id = ?
              AND i.archived_at IS NULL
            LIMIT 1
        ";

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param(
            'ii',
            $invoiceId,
            $tenantId
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $invoice =
            invoiceEditFetchAssoc($stmt);

        $stmt->close();

        return $invoice;
    }
}

if (!function_exists('invoiceEditLogActivity')) {
    function invoiceEditLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $invoiceId,
        $clientId,
        $invoiceNo,
        array $oldValues,
        array $newValues
    ) {
        try {
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
                    'invoice_updated',
                    'invoice',
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

            $title =
                'Invoice updated: ' .
                $invoiceNo;

            $details = json_encode(
                array(
                    'invoice_id' =>
                        (int) $invoiceId,
                    'invoice_no' =>
                        (string) $invoiceNo,
                    'old' => $oldValues,
                    'new' => $newValues
                ),
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            $stmt->bind_param(
                'iiiiss',
                $tenantId,
                $userId,
                $invoiceId,
                $clientId,
                $title,
                $details
            );

            $stmt->execute();
            $stmt->close();
        } catch (Throwable $error) {
            error_log(
                'Invoice update activity log failed: ' .
                $error->getMessage()
            );
        }
    }
}

if (!function_exists('invoiceEditLineCalculation')) {
    function invoiceEditLineCalculation(
        $quantity,
        $unitPrice,
        $discountAmount,
        $taxRate,
        $taxType
    ) {
        $quantity =
            round((float) $quantity, 3);

        $unitPrice =
            round((float) $unitPrice, 2);

        $discountAmount =
            round((float) $discountAmount, 2);

        $taxRate =
            round((float) $taxRate, 4);

        $gross =
            round(
                $quantity * $unitPrice,
                2
            );

        $discountAmount =
            min(
                max(0, $discountAmount),
                $gross
            );

        $afterDiscount =
            round(
                $gross - $discountAmount,
                2
            );

        $taxType =
            strtolower(
                trim((string) $taxType)
            );

        if ($taxType !== 'inclusive') {
            $taxType = 'exclusive';
        }

        if (
            $taxRate <= 0 ||
            $afterDiscount <= 0
        ) {
            $taxAmount = 0.00;
            $lineTotal =
                $afterDiscount;
        } elseif ($taxType === 'inclusive') {
            $taxAmount =
                round(
                    $afterDiscount -
                    (
                        $afterDiscount /
                        (1 + ($taxRate / 100))
                    ),
                    2
                );

            $lineTotal =
                $afterDiscount;
        } else {
            $taxAmount =
                round(
                    $afterDiscount *
                    ($taxRate / 100),
                    2
                );

            $lineTotal =
                round(
                    $afterDiscount +
                    $taxAmount,
                    2
                );
        }

        return array(
            'gross' =>
                $gross,
            'discount_amount' =>
                $discountAmount,
            'tax_amount' =>
                $taxAmount,
            'line_total' =>
                $lineTotal
        );
    }
}

/*
|--------------------------------------------------------------------------
| Load invoice being edited
|--------------------------------------------------------------------------
*/

$invoiceId = $requestedInvoiceId;

if ($invoiceId <= 0) {
    header('Location: invoices.php');
    exit;
}

$invoice = invoiceEditLoadInvoice(
    $conn,
    $tenantId,
    $invoiceId,
    false
);

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found or access denied.');
}

$editableInvoiceStatuses = array(
    'draft',
    'sent',
    'viewed',
    'partially_paid',
    'overdue'
);

$invoiceLocked = !in_array(
    (string) $invoice['status'],
    $editableInvoiceStatuses,
    true
);

/*
|--------------------------------------------------------------------------
| Tenant currency
|--------------------------------------------------------------------------
*/

$currencyCode = 'INR';

$stmt = $conn->prepare("
    SELECT currency_code
    FROM tenants
    WHERE id = ?
      AND deleted_at IS NULL
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $tenantRow =
            invoiceEditFetchAssoc($stmt);

        if (
            $tenantRow &&
            trim(
                (string) $tenantRow[
                    'currency_code'
                ]
            ) !== ''
        ) {
            $currencyCode =
                strtoupper(
                    trim(
                        (string) $tenantRow[
                            'currency_code'
                        ]
                    )
                );
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Selectable data
|--------------------------------------------------------------------------
*/

$clients = array();
$properties = array();
$jobs = array();
$visits = array();
$quotes = array();
$products = array();
$taxRates = array();

$stmt = $conn->prepare("
    SELECT
        id,
        display_name,
        company_name,
        phone,
        email,
        tax_number
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $clients =
            invoiceEditFetchAll($stmt);
    }

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
        country,
        is_primary,
        status
    FROM properties
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY
        is_primary DESC,
        name ASC,
        address_line1 ASC
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $properties =
            invoiceEditFetchAll($stmt);
    }

    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        job_no,
        client_id,
        property_id,
        quote_id,
        title,
        status,
        subtotal,
        tax_total,
        total
    FROM jobs
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status NOT IN (
          'cancelled',
          'archived'
      )
    ORDER BY created_at DESC, id DESC
    LIMIT 1000
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $jobs =
            invoiceEditFetchAll($stmt);
    }

    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        v.id,
        v.visit_no,
        v.job_id,
        v.status,
        v.scheduled_start,
        j.client_id,
        j.property_id,
        j.job_no,
        j.title AS job_title
    FROM visits v

    INNER JOIN jobs j
        ON j.id = v.job_id
       AND j.tenant_id = v.tenant_id
       AND j.deleted_at IS NULL

    WHERE v.tenant_id = ?

    ORDER BY
        v.created_at DESC,
        v.id DESC

    LIMIT 1000
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $visits =
            invoiceEditFetchAll($stmt);
    }

    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        quote_no,
        client_id,
        property_id,
        title,
        status,
        issue_date,
        valid_until,
        subtotal,
        tax_total,
        total,
        terms,
        customer_note
    FROM quotes
    WHERE tenant_id = ?
      AND archived_at IS NULL
      AND status <> 'archived'
    ORDER BY created_at DESC, id DESC
    LIMIT 1000
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $quotes =
            invoiceEditFetchAll($stmt);
    }

    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        ps.id,
        ps.item_type,
        ps.name,
        ps.sku,
        ps.description,
        ps.unit_name,
        ps.unit_cost,
        ps.unit_price,
        ps.tax_rate_id,
        tr.name AS tax_name,
        tr.rate AS tax_rate,
        tr.tax_type
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
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $products =
            invoiceEditFetchAll($stmt);
    }

    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        rate,
        tax_type,
        is_default
    FROM tax_rates
    WHERE tenant_id = ?
      AND is_active = 1
    ORDER BY
        is_default DESC,
        rate ASC,
        name ASC
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $taxRates =
            invoiceEditFetchAll($stmt);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Source line-item data
|--------------------------------------------------------------------------
*/

$jobSourceItems = array();
$quoteSourceItems = array();

$stmt = $conn->prepare("
    SELECT
        jli.job_id,
        jli.product_service_id,
        jli.item_name,
        jli.description,
        jli.quantity,
        jli.unit_cost,
        jli.unit_price,
        0.00 AS discount_amount,
        jli.tax_rate_id,
        jli.tax_amount,
        jli.line_total,
        jli.sort_order
    FROM job_line_items jli

    INNER JOIN jobs j
        ON j.id = jli.job_id
       AND j.tenant_id = jli.tenant_id
       AND j.deleted_at IS NULL

    WHERE jli.tenant_id = ?

    ORDER BY
        jli.job_id ASC,
        jli.sort_order ASC,
        jli.id ASC
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $rows =
            invoiceEditFetchAll($stmt);

        foreach ($rows as $row) {
            $sourceId =
                (int) $row['job_id'];

            if (
                !isset(
                    $jobSourceItems[$sourceId]
                )
            ) {
                $jobSourceItems[$sourceId] =
                    array();
            }

            $jobSourceItems[$sourceId][] =
                $row;
        }
    }

    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        qli.quote_id,
        qli.product_service_id,
        qli.item_name,
        qli.description,
        qli.quantity,
        qli.unit_cost,
        qli.unit_price,
        qli.discount_amount,
        qli.tax_rate_id,
        qli.tax_amount,
        qli.line_total,
        qli.sort_order
    FROM quote_line_items qli

    INNER JOIN quotes q
        ON q.id = qli.quote_id
       AND q.tenant_id = qli.tenant_id
       AND q.archived_at IS NULL

    WHERE qli.tenant_id = ?
      AND qli.is_selected_by_client = 1

    ORDER BY
        qli.quote_id ASC,
        qli.sort_order ASC,
        qli.id ASC
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $rows =
            invoiceEditFetchAll($stmt);

        foreach ($rows as $row) {
            $sourceId =
                (int) $row['quote_id'];

            if (
                !isset(
                    $quoteSourceItems[$sourceId]
                )
            ) {
                $quoteSourceItems[$sourceId] =
                    array();
            }

            $quoteSourceItems[$sourceId][] =
                $row;
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Server-side maps
|--------------------------------------------------------------------------
*/

$clientMap = array();
$propertyMap = array();
$jobMap = array();
$visitMap = array();
$quoteMap = array();
$productMap = array();
$taxRateMap = array();

foreach ($clients as $client) {
    $clientMap[
        (int) $client['id']
    ] = $client;
}

foreach ($properties as $property) {
    $propertyMap[
        (int) $property['id']
    ] = $property;
}

foreach ($jobs as $job) {
    $jobMap[
        (int) $job['id']
    ] = $job;
}

foreach ($visits as $visit) {
    $visitMap[
        (int) $visit['id']
    ] = $visit;
}

foreach ($quotes as $quote) {
    $quoteMap[
        (int) $quote['id']
    ] = $quote;
}

foreach ($products as $product) {
    $productMap[
        (int) $product['id']
    ] = $product;
}

foreach ($taxRates as $taxRate) {
    $taxRateMap[
        (int) $taxRate['id']
    ] = $taxRate;
}

/*
|--------------------------------------------------------------------------
| Existing invoice values and line items
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_POST['client_id'] =
        (string) (int) $invoice['client_id'];

    $_POST['property_id'] =
        !empty($invoice['property_id'])
            ? (string) (int) $invoice['property_id']
            : '';

    $_POST['job_id'] =
        !empty($invoice['job_id'])
            ? (string) (int) $invoice['job_id']
            : '';

    $_POST['visit_id'] =
        !empty($invoice['visit_id'])
            ? (string) (int) $invoice['visit_id']
            : '';

    $_POST['quote_id'] =
        !empty($invoice['quote_id'])
            ? (string) (int) $invoice['quote_id']
            : '';

    $_POST['status'] =
        (string) $invoice['status'] === 'draft'
            ? 'draft'
            : 'sent';

    $_POST['issue_date'] =
        (string) $invoice['issue_date'];

    $_POST['due_date'] =
        !empty($invoice['due_date'])
            ? (string) $invoice['due_date']
            : '';

    $_POST['payment_terms'] =
        (string) $invoice['payment_terms'];

    $_POST['notes'] =
        (string) $invoice['notes'];
}

$initialLineItems = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $postedProducts =
        isset($_POST['product_service_id']) &&
        is_array($_POST['product_service_id'])
            ? $_POST['product_service_id']
            : array();

    $postedQuantities =
        isset($_POST['quantity']) &&
        is_array($_POST['quantity'])
            ? $_POST['quantity']
            : array();

    $postedCosts =
        isset($_POST['unit_cost']) &&
        is_array($_POST['unit_cost'])
            ? $_POST['unit_cost']
            : array();

    $postedPrices =
        isset($_POST['unit_price']) &&
        is_array($_POST['unit_price'])
            ? $_POST['unit_price']
            : array();

    $postedDiscounts =
        isset($_POST['discount_amount']) &&
        is_array($_POST['discount_amount'])
            ? $_POST['discount_amount']
            : array();

    $postedTaxes =
        isset($_POST['tax_rate_id']) &&
        is_array($_POST['tax_rate_id'])
            ? $_POST['tax_rate_id']
            : array();

    $rowCount = max(
        count($postedNames),
        count($postedQuantities),
        count($postedPrices)
    );

    for ($index = 0; $index < $rowCount; $index++) {
        $initialLineItems[] = array(
            'product_service_id' =>
                isset($postedProducts[$index])
                    ? (int) $postedProducts[$index]
                    : null,
            'item_name' =>
                isset($postedNames[$index])
                    ? (string) $postedNames[$index]
                    : '',
            'description' =>
                isset($postedDescriptions[$index])
                    ? (string) $postedDescriptions[$index]
                    : '',
            'quantity' =>
                isset($postedQuantities[$index])
                    ? (string) $postedQuantities[$index]
                    : '1',
            'unit_cost' =>
                isset($postedCosts[$index])
                    ? (string) $postedCosts[$index]
                    : '0',
            'unit_price' =>
                isset($postedPrices[$index])
                    ? (string) $postedPrices[$index]
                    : '0',
            'discount_amount' =>
                isset($postedDiscounts[$index])
                    ? (string) $postedDiscounts[$index]
                    : '0',
            'tax_rate_id' =>
                isset($postedTaxes[$index])
                    ? (int) $postedTaxes[$index]
                    : null
        );
    }
} else {
    $stmt = $conn->prepare("
        SELECT
            product_service_id,
            item_name,
            description,
            quantity,
            unit_cost,
            unit_price,
            discount_amount,
            tax_rate_id,
            tax_amount,
            line_total,
            sort_order
        FROM invoice_line_items
        WHERE tenant_id = ?
          AND invoice_id = ?
        ORDER BY sort_order ASC, id ASC
    ");

    if (!$stmt) {
        $errors[] =
            'Unable to prepare the invoice item query: ' .
            $conn->error;
    } else {
        $stmt->bind_param(
            'ii',
            $tenantId,
            $invoiceId
        );

        if (!$stmt->execute()) {
            $errors[] =
                'Unable to load invoice items: ' .
                $stmt->error;
        } else {
            $initialLineItems =
                invoiceEditFetchAll($stmt);
        }

        $stmt->close();
    }
}

if (empty($initialLineItems)) {
    $initialLineItems[] = array(
        'product_service_id' => null,
        'item_name' => '',
        'description' => '',
        'quantity' => '1',
        'unit_cost' => '0',
        'unit_price' => '0',
        'discount_amount' => '0',
        'tax_rate_id' => null
    );
}

/*
|--------------------------------------------------------------------------
| Save invoice
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($invoiceLocked) {
        $errors[] =
            'Paid, cancelled, written-off, or otherwise locked invoices cannot be edited.';
    }

    $originalUpdatedAt =
        invoiceEditOld('original_updated_at');

    $csrfToken =
        isset($_POST['csrf_token'])
            ? (string) $_POST[
                'csrf_token'
            ]
            : '';

    if (
        !invoiceEditVerifyCsrf(
            $csrfToken
        )
    ) {
        $errors[] =
            'Your session token is invalid. Please refresh and try again.';
    }

    $clientId =
        isset($_POST['client_id'])
            ? (int) $_POST[
                'client_id'
            ]
            : 0;

    $propertyId =
        isset($_POST['property_id']) &&
        (int) $_POST['property_id'] > 0
            ? (int) $_POST[
                'property_id'
            ]
            : null;

    $jobId =
        isset($_POST['job_id']) &&
        (int) $_POST['job_id'] > 0
            ? (int) $_POST['job_id']
            : null;

    $visitId =
        isset($_POST['visit_id']) &&
        (int) $_POST['visit_id'] > 0
            ? (int) $_POST[
                'visit_id'
            ]
            : null;

    $quoteId =
        isset($_POST['quote_id']) &&
        (int) $_POST['quote_id'] > 0
            ? (int) $_POST[
                'quote_id'
            ]
            : null;

    $status =
        invoiceEditOld(
            'status',
            'draft'
        );

    $issueDate =
        invoiceEditOld(
            'issue_date',
            date('Y-m-d')
        );

    $dueDate =
        invoiceEditOld('due_date');

    $paymentTerms =
        invoiceEditOld(
            'payment_terms'
        );

    $notes =
        invoiceEditOld('notes', (string) $invoice['notes']);

    $allowedStatuses = array(
        'draft',
        'sent'
    );

    if ($clientId <= 0) {
        $errors[] =
            'Please select a client.';
    }

    if (
        !in_array(
            $status,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid invoice status.';
    }

    if (
        $issueDate === '' ||
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $issueDate
        )
    ) {
        $errors[] =
            'Please enter a valid invoice date.';
    }

    if (
        $dueDate !== '' &&
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $dueDate
        )
    ) {
        $errors[] =
            'Please enter a valid due date.';
    }

    if (
        $issueDate !== '' &&
        $dueDate !== '' &&
        strtotime($dueDate) <
            strtotime($issueDate)
    ) {
        $errors[] =
            'Due date cannot be earlier than the invoice date.';
    }

    if (
        strlen($paymentTerms) > 120
    ) {
        $errors[] =
            'Payment terms cannot exceed 120 characters.';
    }

    /*
     * Validate client and relations with strict tenant isolation.
     */
    if (
        empty($errors) &&
        !isset($clientMap[$clientId])
    ) {
        $errors[] =
            'The selected client is not valid or is archived.';
    }

    if (
        empty($errors) &&
        $propertyId !== null
    ) {
        if (
            !isset(
                $propertyMap[$propertyId]
            ) ||
            (int) $propertyMap[
                $propertyId
            ]['client_id'] !== $clientId
        ) {
            $errors[] =
                'The selected property does not belong to this client.';
        }
    }

    if (
        empty($errors) &&
        $jobId !== null
    ) {
        if (
            !isset($jobMap[$jobId]) ||
            (int) $jobMap[
                $jobId
            ]['client_id'] !== $clientId
        ) {
            $errors[] =
                'The selected job does not belong to this client.';
        } elseif (
            $propertyId !== null &&
            !empty(
                $jobMap[$jobId][
                    'property_id'
                ]
            ) &&
            (int) $jobMap[$jobId][
                'property_id'
            ] !== $propertyId
        ) {
            $errors[] =
                'The selected property does not match the selected job.';
        }
    }

    if (
        empty($errors) &&
        $visitId !== null
    ) {
        if (
            !isset(
                $visitMap[$visitId]
            ) ||
            (int) $visitMap[
                $visitId
            ]['client_id'] !== $clientId
        ) {
            $errors[] =
                'The selected visit does not belong to this client.';
        } elseif (
            $jobId !== null &&
            (int) $visitMap[
                $visitId
            ]['job_id'] !== $jobId
        ) {
            $errors[] =
                'The selected visit does not belong to the selected job.';
        }
    }

    if (
        empty($errors) &&
        $quoteId !== null
    ) {
        if (
            !isset(
                $quoteMap[$quoteId]
            ) ||
            (int) $quoteMap[
                $quoteId
            ]['client_id'] !== $clientId
        ) {
            $errors[] =
                'The selected quote does not belong to this client.';
        } elseif (
            $propertyId !== null &&
            !empty(
                $quoteMap[$quoteId][
                    'property_id'
                ]
            ) &&
            (int) $quoteMap[$quoteId][
                'property_id'
            ] !== $propertyId
        ) {
            $errors[] =
                'The selected property does not match the selected quote.';
        }
    }

    /*
     * Parse and recalculate line items.
     * Posted tax and totals are never trusted.
     */
    $lineItems = array();

    $subtotal = 0.00;
    $discountTotal = 0.00;
    $taxTotal = 0.00;
    $invoiceTotal = 0.00;

    $productIds =
        isset(
            $_POST['product_service_id']
        ) &&
        is_array(
            $_POST['product_service_id']
        )
            ? $_POST[
                'product_service_id'
            ]
            : array();

    $itemNames =
        isset($_POST['item_name']) &&
        is_array($_POST['item_name'])
            ? $_POST['item_name']
            : array();

    $descriptions =
        isset(
            $_POST['item_description']
        ) &&
        is_array(
            $_POST['item_description']
        )
            ? $_POST[
                'item_description'
            ]
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

    $discounts =
        isset(
            $_POST['discount_amount']
        ) &&
        is_array(
            $_POST['discount_amount']
        )
            ? $_POST[
                'discount_amount'
            ]
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

    for (
        $index = 0;
        $index < $rowCount;
        $index++
    ) {
        $productServiceId =
            isset($productIds[$index]) &&
            (int) $productIds[$index] > 0
                ? (int) $productIds[
                    $index
                ]
                : null;

        $itemName =
            isset($itemNames[$index])
                ? trim(
                    (string) $itemNames[
                        $index
                    ]
                )
                : '';

        $description =
            isset(
                $descriptions[$index]
            )
                ? trim(
                    (string)
                        $descriptions[
                            $index
                        ]
                )
                : '';

        $quantityRaw =
            isset($quantities[$index])
                ? trim(
                    (string) $quantities[
                        $index
                    ]
                )
                : '';

        $unitCostRaw =
            isset($unitCosts[$index])
                ? trim(
                    (string) $unitCosts[
                        $index
                    ]
                )
                : '0';

        $unitPriceRaw =
            isset($unitPrices[$index])
                ? trim(
                    (string) $unitPrices[
                        $index
                    ]
                )
                : '0';

        $discountRaw =
            isset($discounts[$index])
                ? trim(
                    (string) $discounts[
                        $index
                    ]
                )
                : '0';

        $taxRateId =
            isset($taxRateIds[$index]) &&
            (int) $taxRateIds[$index] > 0
                ? (int) $taxRateIds[
                    $index
                ]
                : null;

        if (
            $itemName === '' &&
            (
                $quantityRaw === '' ||
                (float) $quantityRaw <= 0
            ) &&
            (
                $unitPriceRaw === '' ||
                (float) $unitPriceRaw <= 0
            )
        ) {
            continue;
        }

        if ($itemName === '') {
            $errors[] =
                'Every invoice item must have an item name.';
            break;
        }

        if (
            strlen($itemName) > 190
        ) {
            $errors[] =
                'An invoice item name exceeds 190 characters.';
            break;
        }

        if (
            !is_numeric($quantityRaw) ||
            (float) $quantityRaw <= 0
        ) {
            $errors[] =
                'Every invoice item quantity must be greater than zero.';
            break;
        }

        if (
            !is_numeric($unitCostRaw) ||
            !is_numeric($unitPriceRaw) ||
            !is_numeric($discountRaw)
        ) {
            $errors[] =
                'Invoice item amounts must be valid numbers.';
            break;
        }

        $quantity =
            round(
                (float) $quantityRaw,
                3
            );

        $unitCost =
            round(
                (float) $unitCostRaw,
                2
            );

        $unitPrice =
            round(
                (float) $unitPriceRaw,
                2
            );

        $discountAmount =
            round(
                (float) $discountRaw,
                2
            );

        if (
            $unitCost < 0 ||
            $unitPrice < 0 ||
            $discountAmount < 0
        ) {
            $errors[] =
                'Invoice item amounts cannot be negative.';
            break;
        }

        if (
            $productServiceId !== null &&
            !isset(
                $productMap[
                    $productServiceId
                ]
            )
        ) {
            $errors[] =
                'A selected product or service is no longer available.';
            break;
        }

        $taxRate = 0.00;
        $taxType = 'exclusive';

        if ($taxRateId !== null) {
            if (
                !isset(
                    $taxRateMap[
                        $taxRateId
                    ]
                )
            ) {
                $errors[] =
                    'A selected tax rate is no longer available.';
                break;
            }

            $taxRate =
                (float) $taxRateMap[
                    $taxRateId
                ]['rate'];

            $taxType =
                (string) $taxRateMap[
                    $taxRateId
                ]['tax_type'];
        }

        $calculation =
            invoiceEditLineCalculation(
                $quantity,
                $unitPrice,
                $discountAmount,
                $taxRate,
                $taxType
            );

        if (
            $discountAmount >
            $calculation['gross']
        ) {
            $errors[] =
                'An invoice item discount cannot exceed its gross amount.';
            break;
        }

        $subtotal +=
            $calculation['gross'];

        $discountTotal +=
            $calculation[
                'discount_amount'
            ];

        $taxTotal +=
            $calculation[
                'tax_amount'
            ];

        $invoiceTotal +=
            $calculation[
                'line_total'
            ];

        $lineItems[] = array(
            'product_service_id' =>
                $productServiceId,
            'item_name' =>
                $itemName,
            'description' =>
                invoiceEditNullable(
                    $description
                ),
            'quantity' =>
                $quantity,
            'unit_cost' =>
                $unitCost,
            'unit_price' =>
                $unitPrice,
            'discount_amount' =>
                $calculation[
                    'discount_amount'
                ],
            'tax_rate_id' =>
                $taxRateId,
            'tax_amount' =>
                $calculation[
                    'tax_amount'
                ],
            'line_total' =>
                $calculation[
                    'line_total'
                ],
            'sort_order' =>
                $index
        );
    }

    if (
        empty($lineItems) &&
        empty($errors)
    ) {
        $errors[] =
            'Please add at least one invoice item.';
    }

    $subtotal =
        round($subtotal, 2);

    $discountTotal =
        round($discountTotal, 2);

    $taxTotal =
        round($taxTotal, 2);

    $invoiceTotal =
        round($invoiceTotal, 2);

    if (
        empty($errors) &&
        $invoiceTotal < 0
    ) {
        $errors[] =
            'Invoice total cannot be negative.';
    }

    /*
     * Update the invoice and replace its line items atomically.
     */
    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            $lockedInvoice =
                invoiceEditLoadInvoice(
                    $conn,
                    $tenantId,
                    $invoiceId,
                    true
                );

            if (!$lockedInvoice) {
                throw new Exception(
                    'Invoice not found or access denied.'
                );
            }

            if (
                !in_array(
                    (string) $lockedInvoice['status'],
                    $editableInvoiceStatuses,
                    true
                )
            ) {
                throw new Exception(
                    'This invoice is no longer editable because its status changed to ' .
                    invoiceEditLabel(
                        $lockedInvoice['status']
                    ) .
                    '.'
                );
            }

            if (
                $originalUpdatedAt !== '' &&
                (string) $lockedInvoice['updated_at'] !==
                    $originalUpdatedAt
            ) {
                throw new Exception(
                    'This invoice was changed by another user or payment process. Reload the page before saving.'
                );
            }

            $amountPaid = round(
                (float) $lockedInvoice['amount_paid'],
                2
            );

            if (
                $invoiceTotal + 0.005 <
                $amountPaid
            ) {
                throw new Exception(
                    'The edited invoice total cannot be lower than the amount already paid (' .
                    invoiceEditMoney(
                        $amountPaid,
                        $currencyCode
                    ) .
                    ').'
                );
            }

            $newBalance = round(
                max(
                    0,
                    $invoiceTotal - $amountPaid
                ),
                2
            );

            $issueDateValue =
                invoiceEditNullable(
                    $issueDate
                );

            $dueDateValue =
                invoiceEditNullable(
                    $dueDate
                );

            $paymentTermsValue =
                invoiceEditNullable(
                    $paymentTerms
                );

            $notesValue =
                invoiceEditNullable(
                    $notes
                );

            $requestedStatus = $status;
            $newStatus = $requestedStatus;
            $sentAt = $lockedInvoice['sent_at'];
            $viewedAt = $lockedInvoice['viewed_at'];
            $paidAt = $lockedInvoice['paid_at'];

            if ($amountPaid > 0) {
                if ($newBalance <= 0.005) {
                    $newBalance = 0.00;
                    $newStatus = 'paid';

                    if (empty($paidAt)) {
                        $paidAt = date('Y-m-d H:i:s');
                    }
                } else {
                    $newStatus = 'partially_paid';
                    $paidAt = null;
                }
            } else {
                $paidAt = null;

                if ($requestedStatus === 'sent') {
                    if (empty($sentAt)) {
                        $sentAt = date('Y-m-d H:i:s');
                    }

                    if (
                        $dueDateValue !== null &&
                        strtotime($dueDateValue) <
                            strtotime(date('Y-m-d')) &&
                        $newBalance > 0
                    ) {
                        $newStatus = 'overdue';
                    }
                } else {
                    $newStatus = 'draft';
                    $sentAt = null;
                    $viewedAt = null;
                }
            }

            $stmt = $conn->prepare("
                UPDATE invoices
                SET
                    client_id = ?,
                    property_id = ?,
                    job_id = ?,
                    visit_id = ?,
                    quote_id = ?,
                    status = ?,
                    issue_date = ?,
                    due_date = ?,
                    subtotal = ?,
                    discount_total = ?,
                    tax_total = ?,
                    total = ?,
                    amount_paid = ?,
                    balance_due = ?,
                    payment_terms = ?,
                    notes = ?,
                    sent_at = ?,
                    viewed_at = ?,
                    paid_at = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND tenant_id = ?
                  AND archived_at IS NULL
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the invoice update: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'iiiiisssddddddsssssii',
                $clientId,
                $propertyId,
                $jobId,
                $visitId,
                $quoteId,
                $newStatus,
                $issueDateValue,
                $dueDateValue,
                $subtotal,
                $discountTotal,
                $taxTotal,
                $invoiceTotal,
                $amountPaid,
                $newBalance,
                $paymentTermsValue,
                $notesValue,
                $sentAt,
                $viewedAt,
                $paidAt,
                $invoiceId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Invoice could not be updated: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            $stmt = $conn->prepare("
                DELETE FROM invoice_line_items
                WHERE tenant_id = ?
                  AND invoice_id = ?
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the old invoice-item removal.'
                );
            }

            $stmt->bind_param(
                'ii',
                $tenantId,
                $invoiceId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to remove the previous invoice items: ' .
                    $stmt->error
                );
            }

            $stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO invoice_line_items (
                    tenant_id,
                    invoice_id,
                    product_service_id,
                    item_name,
                    description,
                    quantity,
                    unit_cost,
                    unit_price,
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
                    'Unable to prepare updated invoice items: ' .
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

                $discountAmount =
                    $item['discount_amount'];

                $taxRateId =
                    $item['tax_rate_id'];

                $taxAmount =
                    $item['tax_amount'];

                $lineTotal =
                    $item['line_total'];

                $sortOrder =
                    $item['sort_order'];

                $stmt->bind_param(
                    'iiissddddiddi',
                    $tenantId,
                    $invoiceId,
                    $productServiceId,
                    $itemName,
                    $itemDescription,
                    $quantity,
                    $unitCost,
                    $unitPrice,
                    $discountAmount,
                    $taxRateId,
                    $taxAmount,
                    $lineTotal,
                    $sortOrder
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'An updated invoice item could not be saved: ' .
                        $stmt->error
                    );
                }
            }

            $stmt->close();

            if (
                $jobId !== null &&
                in_array(
                    $newStatus,
                    array(
                        'sent',
                        'overdue',
                        'partially_paid',
                        'paid'
                    ),
                    true
                )
            ) {
                $stmt = $conn->prepare("
                    UPDATE jobs
                    SET
                        status = 'invoiced',
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                      AND deleted_at IS NULL
                      AND status IN (
                          'completed',
                          'requires_invoicing',
                          'ready_to_invoice'
                      )
                    LIMIT 1
                ");

                if ($stmt) {
                    $stmt->bind_param(
                        'ii',
                        $jobId,
                        $tenantId
                    );

                    $stmt->execute();
                    $stmt->close();
                }
            }

            $oldValues = array(
                'client_id' =>
                    (int) $lockedInvoice['client_id'],
                'property_id' =>
                    !empty($lockedInvoice['property_id'])
                        ? (int) $lockedInvoice['property_id']
                        : null,
                'job_id' =>
                    !empty($lockedInvoice['job_id'])
                        ? (int) $lockedInvoice['job_id']
                        : null,
                'visit_id' =>
                    !empty($lockedInvoice['visit_id'])
                        ? (int) $lockedInvoice['visit_id']
                        : null,
                'quote_id' =>
                    !empty($lockedInvoice['quote_id'])
                        ? (int) $lockedInvoice['quote_id']
                        : null,
                'status' =>
                    (string) $lockedInvoice['status'],
                'total' =>
                    (float) $lockedInvoice['total'],
                'amount_paid' =>
                    (float) $lockedInvoice['amount_paid'],
                'balance_due' =>
                    (float) $lockedInvoice['balance_due']
            );

            $newValues = array(
                'client_id' => $clientId,
                'property_id' => $propertyId,
                'job_id' => $jobId,
                'visit_id' => $visitId,
                'quote_id' => $quoteId,
                'status' => $newStatus,
                'total' => $invoiceTotal,
                'amount_paid' => $amountPaid,
                'balance_due' => $newBalance,
                'line_item_count' => count($lineItems)
            );

            $conn->commit();

            invoiceEditLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $invoiceId,
                $clientId,
                $lockedInvoice['invoice_no'],
                $oldValues,
                $newValues
            );

            $_SESSION['flash_success'] =
                'Invoice updated successfully.';

            header(
                'Location: invoice-view.php?id=' .
                $invoiceId
            );
            exit;
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            $errors[] =
                $error->getMessage();

            error_log(
                'Invoice update failed: ' .
                $error->getMessage()
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Render values
|--------------------------------------------------------------------------
*/

$selectedClientId =
    (int) invoiceEditOld(
        'client_id',
        (string) $invoice['client_id']
    );

$selectedPropertyId =
    (int) invoiceEditOld(
        'property_id',
        !empty($invoice['property_id'])
            ? (string) $invoice['property_id']
            : ''
    );

$selectedJobId =
    (int) invoiceEditOld(
        'job_id',
        !empty($invoice['job_id'])
            ? (string) $invoice['job_id']
            : ''
    );

$selectedVisitId =
    (int) invoiceEditOld(
        'visit_id',
        !empty($invoice['visit_id'])
            ? (string) $invoice['visit_id']
            : ''
    );

$selectedQuoteId =
    (int) invoiceEditOld(
        'quote_id',
        !empty($invoice['quote_id'])
            ? (string) $invoice['quote_id']
            : ''
    );

$selectedStatus =
    invoiceEditOld(
        'status',
        (string) $invoice['status'] === 'draft'
            ? 'draft'
            : 'sent'
    );

$csrfToken =
    invoiceEditCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.invoice-edit-page {
    --ia-primary: #6d28d9;
    --ia-primary-dark: #4c1d95;
    --ia-soft: #f5f3ff;
    --ia-text: #111827;
    --ia-muted: #6b7280;
    --ia-border: #e5e7eb;
}

.ia-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.ia-header-main {
    display: flex;
    align-items: center;
    gap: 11px;
}

.ia-header-icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background:
        linear-gradient(
            135deg,
            var(--ia-primary),
            var(--ia-primary-dark)
        );
    color: #fff;
    font-size: 18px;
    box-shadow:
        0 10px 22px
        rgba(109,40,217,.2);
}

.ia-header h1 {
    margin: 0;
    color: var(--ia-text);
    font-size: 21px;
    font-weight: 800;
}

.ia-header p {
    margin: 5px 0 0;
    color: var(--ia-muted);
    font-size: 10px;
}

.ia-back {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid var(--ia-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
}

.ia-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 10px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 10px;
    line-height: 1.6;
}

.ia-alert ul {
    margin: 0;
    padding-left: 18px;
}

.ia-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.58fr)
        minmax(300px,.68fr);
    gap: 13px;
    align-items: start;
}

.ia-card {
    overflow: hidden;
    border: 1px solid var(--ia-border);
    border-radius: 13px;
    background: #fff;
    box-shadow:
        0 6px 20px
        rgba(15,23,42,.04);
}

.ia-card + .ia-card {
    margin-top: 13px;
}

.ia-card-head {
    min-height: 48px;
    padding: 11px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #eef0f4;
}

.ia-card-head h2 {
    margin: 0;
    color: var(--ia-text);
    font-size: 11px;
    font-weight: 800;
}

.ia-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 8px;
}

.ia-card-body {
    padding: 14px;
}

.ia-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.ia-grid.three {
    grid-template-columns:
        repeat(3,minmax(0,1fr));
}

.ia-field {
    min-width: 0;
}

.ia-field.full {
    grid-column: 1 / -1;
}

.ia-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 800;
}

.ia-required {
    color: #dc2626;
}

.ia-input,
.ia-select,
.ia-textarea {
    width: 100%;
    min-height: 38px;
    padding: 9px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 9px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 9px;
    outline: none;
}

.ia-textarea {
    min-height: 92px;
    resize: vertical;
}

.ia-input:focus,
.ia-select:focus,
.ia-textarea:focus {
    border-color: #8b5cf6;
    box-shadow:
        0 0 0 3px
        rgba(139,92,246,.1);
}

.ia-help {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.ia-source-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.ia-source-btn,
.ia-add-line {
    min-height: 32px;
    padding: 7px 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 1px solid #c4b5fd;
    border-radius: 8px;
    background: #faf8ff;
    color: var(--ia-primary);
    font-family: inherit;
    font-size: 8px;
    font-weight: 800;
    cursor: pointer;
}

.ia-source-btn:disabled {
    cursor: not-allowed;
    opacity: .45;
}

.ia-line-wrap {
    overflow-x: auto;
}

.ia-line-table {
    width: 100%;
    min-width: 1120px;
    border-collapse: collapse;
}

.ia-line-table th,
.ia-line-table td {
    padding: 8px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    vertical-align: top;
}

.ia-line-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.ia-line-input,
.ia-line-select {
    width: 100%;
    min-width: 88px;
    min-height: 34px;
    padding: 7px 8px;
    border: 1px solid #dfe3e8;
    border-radius: 7px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 8px;
    outline: none;
}

.ia-line-input:focus,
.ia-line-select:focus {
    border-color: #8b5cf6;
    box-shadow:
        0 0 0 2px
        rgba(139,92,246,.08);
}

.ia-line-product {
    min-width: 165px;
}

.ia-line-name {
    min-width: 155px;
}

.ia-line-description {
    min-width: 170px;
}

.ia-line-quantity {
    min-width: 75px;
}

.ia-line-money {
    min-width: 90px;
}

.ia-line-tax {
    min-width: 115px;
}

.ia-line-total {
    min-width: 95px;
    padding-top: 9px;
    display: block;
    color: #111827;
    font-size: 9px;
    font-weight: 800;
    text-align: right;
}

.ia-remove-line {
    width: 30px;
    height: 30px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #fecaca;
    border-radius: 7px;
    background: #fff;
    color: #dc2626;
    cursor: pointer;
}

.ia-empty-lines {
    padding: 26px 12px;
    color: #9ca3af;
    font-size: 9px;
    text-align: center;
}

.ia-summary {
    display: grid;
    gap: 9px;
}

.ia-summary-row {
    padding: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
    color: #4b5563;
    font-size: 9px;
}

.ia-summary-row strong {
    color: #111827;
}

.ia-summary-row.discount strong {
    color: #b91c1c;
}

.ia-summary-row.total {
    border-color: #ddd6fe;
    background: var(--ia-soft);
    color: var(--ia-primary-dark);
    font-size: 11px;
    font-weight: 800;
}

.ia-summary-row.balance {
    border-color: #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 10px;
    font-weight: 800;
}

.ia-client-summary {
    margin-bottom: 11px;
    padding: 11px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.ia-client-name {
    color: #111827;
    font-size: 10px;
    font-weight: 800;
}

.ia-client-meta {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.ia-actions {
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #eef0f4;
    background: #fafafa;
}

.ia-btn {
    min-height: 38px;
    padding: 8px 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 9px;
    font-family: inherit;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}

.ia-btn.secondary {
    border: 1px solid var(--ia-border);
    background: #fff;
    color: #374151;
}

.ia-btn.primary {
    border: 0;
    background:
        linear-gradient(
            135deg,
            var(--ia-primary),
            var(--ia-primary-dark)
        );
    color: #fff;
}

.ia-btn:disabled {
    cursor: not-allowed;
    opacity: .55;
}

@media (max-width: 1100px) {
    .ia-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .ia-header {
        flex-direction: column;
    }

    .ia-grid,
    .ia-grid.three {
        grid-template-columns: 1fr;
    }

    .ia-field.full {
        grid-column: auto;
    }

    .ia-actions {
        flex-direction: column-reverse;
    }

    .ia-btn {
        width: 100%;
    }
}
</style>

<div class="invoice-edit-page">
    <div class="ia-header">
        <div class="ia-header-main">
            <div class="ia-header-icon">
                <i class="bi bi-receipt"></i>
            </div>

            <div>
                <h1>Edit Invoice</h1>
                <p>
                    Update invoice <?= e($invoice['invoice_no']); ?> while preserving payments and recalculating the balance safely.
                </p>
            </div>
        </div>

        <a
            href="invoice-view.php?id=<?= $invoiceId; ?>"
            class="ia-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Invoice
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="ia-alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($invoiceLocked): ?>
        <div class="ia-alert">
            This invoice is locked because its current status is
            <strong><?= e(invoiceEditLabel($invoice['status'])); ?></strong>.
            Paid, cancelled, and written-off invoices cannot be changed.
        </div>
    <?php elseif ((float) $invoice['amount_paid'] > 0): ?>
        <div class="ia-alert" style="border-color:#fed7aa;background:#fff7ed;color:#c2410c;">
            This invoice already has
            <strong><?= e(invoiceEditMoney($invoice['amount_paid'], $currencyCode)); ?></strong>
            recorded as paid. The edited total cannot be lower than that amount,
            and the balance/status will be recalculated automatically.
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="invoice-edit.php?id=<?= $invoiceId; ?>"
        autocomplete="off"
        id="invoiceEditForm"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken); ?>"
        >

        <input
            type="hidden"
            name="invoice_id"
            value="<?= $invoiceId; ?>"
        >

        <input
            type="hidden"
            name="original_updated_at"
            value="<?= e($invoice['updated_at']); ?>"
        >

        <fieldset
            <?= $invoiceLocked ? 'disabled' : ''; ?>
            style="border:0;padding:0;margin:0;min-width:0;"
        >

        <div class="ia-layout">
            <main>
                <section class="ia-card">
                    <div class="ia-card-head">
                        <div>
                            <h2>Invoice Information</h2>
                            <p>
                                Update the customer, related records, dates, terms, and issued state.
                            </p>
                        </div>
                    </div>

                    <div class="ia-card-body">
                        <div class="ia-grid">
                            <div class="ia-field full">
                                <label class="ia-label">
                                    Client
                                    <span class="ia-required">*</span>
                                </label>

                                <select
                                    name="client_id"
                                    id="invoiceClient"
                                    class="ia-select"
                                    required
                                >
                                    <option value="">
                                        Select Client
                                    </option>

                                    <?php foreach ($clients as $client): ?>
                                        <option
                                            value="<?= (int) $client['id']; ?>"
                                            data-phone="<?= e(
                                                $client['phone']
                                            ); ?>"
                                            data-email="<?= e(
                                                $client['email']
                                            ); ?>"
                                            data-company="<?= e(
                                                $client['company_name']
                                            ); ?>"
                                            data-tax-number="<?= e(
                                                $client['tax_number']
                                            ); ?>"
                                            <?= $selectedClientId ===
                                                (int) $client['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e(
                                                $client[
                                                    'display_name'
                                                ]
                                            ); ?>

                                            <?php if (
                                                trim(
                                                    (string) $client[
                                                        'phone'
                                                    ]
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

                            <div class="ia-field">
                                <label class="ia-label">
                                    Property
                                </label>

                                <select
                                    name="property_id"
                                    id="invoiceProperty"
                                    class="ia-select"
                                >
                                    <option value="">
                                        No Property
                                    </option>

                                    <?php foreach (
                                        $properties as
                                        $property
                                    ): ?>
                                        <?php
                                        $propertyLabel =
                                            trim(
                                                (string) $property[
                                                    'name'
                                                ]
                                            ) !== ''
                                                ? $property[
                                                    'name'
                                                ]
                                                : $property[
                                                    'address_line1'
                                                ];
                                        ?>

                                        <option
                                            value="<?= (int) $property[
                                                'id'
                                            ]; ?>"
                                            data-client-id="<?= (int) $property[
                                                'client_id'
                                            ]; ?>"
                                            <?= $selectedPropertyId ===
                                                (int) $property[
                                                    'id'
                                                ]
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($propertyLabel); ?>

                                            <?php if (
                                                trim(
                                                    (string) $property[
                                                        'city'
                                                    ]
                                                ) !== ''
                                            ): ?>
                                                · <?= e(
                                                    $property['city']
                                                ); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ia-field">
                                <label class="ia-label">
                                    Job
                                </label>

                                <select
                                    name="job_id"
                                    id="invoiceJob"
                                    class="ia-select"
                                >
                                    <option value="">
                                        No Job
                                    </option>

                                    <?php foreach ($jobs as $job): ?>
                                        <option
                                            value="<?= (int) $job['id']; ?>"
                                            data-client-id="<?= (int) $job[
                                                'client_id'
                                            ]; ?>"
                                            data-property-id="<?= (int) $job[
                                                'property_id'
                                            ]; ?>"
                                            data-quote-id="<?= (int) $job[
                                                'quote_id'
                                            ]; ?>"
                                            <?= $selectedJobId ===
                                                (int) $job['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($job['job_no']); ?>
                                            · <?= e($job['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ia-field">
                                <label class="ia-label">
                                    Visit
                                </label>

                                <select
                                    name="visit_id"
                                    id="invoiceVisit"
                                    class="ia-select"
                                >
                                    <option value="">
                                        No Visit
                                    </option>

                                    <?php foreach ($visits as $visit): ?>
                                        <option
                                            value="<?= (int) $visit['id']; ?>"
                                            data-client-id="<?= (int) $visit[
                                                'client_id'
                                            ]; ?>"
                                            data-property-id="<?= (int) $visit[
                                                'property_id'
                                            ]; ?>"
                                            data-job-id="<?= (int) $visit[
                                                'job_id'
                                            ]; ?>"
                                            <?= $selectedVisitId ===
                                                (int) $visit['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e(
                                                !empty(
                                                    $visit[
                                                        'visit_no'
                                                    ]
                                                )
                                                    ? $visit[
                                                        'visit_no'
                                                    ]
                                                    : 'Visit #' .
                                                        $visit[
                                                            'id'
                                                        ]
                                            ); ?>
                                            · <?= e(
                                                $visit['job_no']
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ia-field">
                                <label class="ia-label">
                                    Quote
                                </label>

                                <select
                                    name="quote_id"
                                    id="invoiceQuote"
                                    class="ia-select"
                                >
                                    <option value="">
                                        No Quote
                                    </option>

                                    <?php foreach ($quotes as $quote): ?>
                                        <option
                                            value="<?= (int) $quote['id']; ?>"
                                            data-client-id="<?= (int) $quote[
                                                'client_id'
                                            ]; ?>"
                                            data-property-id="<?= (int) $quote[
                                                'property_id'
                                            ]; ?>"
                                            data-terms="<?= e(
                                                $quote['terms']
                                            ); ?>"
                                            data-note="<?= e(
                                                $quote[
                                                    'customer_note'
                                                ]
                                            ); ?>"
                                            <?= $selectedQuoteId ===
                                                (int) $quote['id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e(
                                                $quote['quote_no']
                                            ); ?>
                                            · <?= e(
                                                $quote['title']
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ia-field">
                                <label class="ia-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    id="invoiceStatus"
                                    class="ia-select"
                                >
                                    <option
                                        value="draft"
                                        <?= $selectedStatus ===
                                            'draft'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        Draft
                                    </option>

                                    <option
                                        value="sent"
                                        <?= $selectedStatus ===
                                            'sent'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        Sent / Issued
                                    </option>
                                </select>
                            </div>

                            <div class="ia-field">
                                <label class="ia-label">
                                    Invoice Date
                                    <span class="ia-required">*</span>
                                </label>

                                <input
                                    type="date"
                                    name="issue_date"
                                    id="invoiceIssueDate"
                                    class="ia-input"
                                    value="<?= e(
                                        invoiceEditOld(
                                            'issue_date',
                                            (string) $invoice['issue_date']
                                        )
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="ia-field">
                                <label class="ia-label">
                                    Due Date
                                </label>

                                <input
                                    type="date"
                                    name="due_date"
                                    id="invoiceDueDate"
                                    class="ia-input"
                                    value="<?= e(
                                        invoiceEditOld(
                                            'due_date',
                                            !empty($invoice['due_date'])
                                                ? (string) $invoice['due_date']
                                                : ''
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="ia-field full">
                                <label class="ia-label">
                                    Payment Terms
                                </label>

                                <input
                                    type="text"
                                    name="payment_terms"
                                    id="invoicePaymentTerms"
                                    class="ia-input"
                                    maxlength="120"
                                    value="<?= e(
                                        invoiceEditOld(
                                            'payment_terms',
                                            (string) $invoice['payment_terms']
                                        )
                                    ); ?>"
                                    placeholder="Example: Payment due within 7 days"
                                >
                            </div>

                            <div class="ia-field full">
                                <label class="ia-label">
                                    Notes
                                </label>

                                <textarea
                                    name="notes"
                                    id="invoiceNotes"
                                    class="ia-textarea"
                                    placeholder="Customer notes, payment instructions, or invoice remarks"
                                ><?= e(
                                    invoiceEditOld('notes')
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ia-card">
                    <div class="ia-card-head">
                        <div>
                            <h2>Invoice Items</h2>
                            <p>
                                Edit existing items or replace them with items from the selected job or quote.
                            </p>
                        </div>

                        <div class="ia-source-actions">
                            <button
                                type="button"
                                class="ia-source-btn"
                                id="loadJobItems"
                            >
                                <i class="bi bi-briefcase"></i>
                                Load Job Items
                            </button>

                            <button
                                type="button"
                                class="ia-source-btn"
                                id="loadQuoteItems"
                            >
                                <i class="bi bi-file-earmark-text"></i>
                                Load Quote Items
                            </button>

                            <button
                                type="button"
                                class="ia-add-line"
                                id="addInvoiceLine"
                            >
                                <i class="bi bi-plus-lg"></i>
                                Add Item
                            </button>
                        </div>
                    </div>

                    <div class="ia-line-wrap">
                        <table class="ia-line-table">
                            <thead>
                                <tr>
                                    <th>Product / Service</th>
                                    <th>Item Name</th>
                                    <th>Description</th>
                                    <th>Qty</th>
                                    <th>Unit Cost</th>
                                    <th>Unit Price</th>
                                    <th>Discount</th>
                                    <th>Tax</th>
                                    <th style="text-align:right;">
                                        Amount
                                    </th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody id="invoiceLineBody"></tbody>
                        </table>
                    </div>
                </section>
            </main>

            <aside>
                <section class="ia-card">
                    <div class="ia-card-head">
                        <div>
                            <h2>Invoice Summary</h2>
                            <p>
                                Totals, paid amount, balance, and status are recalculated by the server before saving.
                            </p>
                        </div>
                    </div>

                    <div class="ia-card-body">
                        <div
                            class="ia-client-summary"
                            id="invoiceClientSummary"
                        >
                            <div class="ia-client-name">
                                No client selected
                            </div>

                            <div class="ia-client-meta">
                                Select a client to review billing details.
                            </div>
                        </div>

                        <div class="ia-summary">
                            <div class="ia-summary-row">
                                <span>Invoice Number</span>
                                <strong>
                                    <?= e($invoice['invoice_no']); ?>
                                </strong>
                            </div>

                            <div class="ia-summary-row">
                                <span>Currency</span>
                                <strong>
                                    <?= e($currencyCode); ?>
                                </strong>
                            </div>

                            <div class="ia-summary-row">
                                <span>Amount Paid</span>
                                <strong style="color:#047857;">
                                    <?= e(invoiceEditMoney($invoice['amount_paid'], $currencyCode)); ?>
                                </strong>
                            </div>

                            <div class="ia-summary-row">
                                <span>Subtotal</span>
                                <strong id="summarySubtotal">
                                    <?= e(
                                        invoiceEditMoney(
                                            0,
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>

                            <div class="ia-summary-row discount">
                                <span>Discount</span>
                                <strong id="summaryDiscount">
                                    <?= e(
                                        invoiceEditMoney(
                                            0,
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>

                            <div class="ia-summary-row">
                                <span>Tax</span>
                                <strong id="summaryTax">
                                    <?= e(
                                        invoiceEditMoney(
                                            0,
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>

                            <div class="ia-summary-row total">
                                <span>Total</span>
                                <strong id="summaryTotal">
                                    <?= e(
                                        invoiceEditMoney(
                                            0,
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>

                            <div class="ia-summary-row balance">
                                <span>Balance Due</span>
                                <strong id="summaryBalance">
                                    <?= e(
                                        invoiceEditMoney(
                                            0,
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>
                        </div>
                    </div>

                    <div class="ia-actions">
                        <a
                            href="invoice-view.php?id=<?= $invoiceId; ?>"
                            class="ia-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="ia-btn primary"
                            id="saveInvoiceButton"
                            <?= $invoiceLocked ? 'disabled' : ''; ?>
                        >
                            <i class="bi bi-check2"></i>
                            Save Changes
                        </button>
                    </div>
                </section>
            </aside>
        </div>
        </fieldset>
    </form>
</div>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        'use strict';

        var currencyCode =
            <?= json_encode(
                $currencyCode,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;

        var existingAmountPaid =
            <?= json_encode(
                round(
                    (float) $invoice['amount_paid'],
                    2
                )
            ); ?>;

        var products =
            <?= json_encode(
                $products,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;

        var taxRates =
            <?= json_encode(
                $taxRates,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;

        var jobSourceItems =
            <?= json_encode(
                $jobSourceItems,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;

        var quoteSourceItems =
            <?= json_encode(
                $quoteSourceItems,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;

        var initialRows =
            <?= json_encode(
                $initialLineItems,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;

        var clientSelect =
            document.getElementById(
                'invoiceClient'
            );

        var propertySelect =
            document.getElementById(
                'invoiceProperty'
            );

        var jobSelect =
            document.getElementById(
                'invoiceJob'
            );

        var visitSelect =
            document.getElementById(
                'invoiceVisit'
            );

        var quoteSelect =
            document.getElementById(
                'invoiceQuote'
            );

        var paymentTermsInput =
            document.getElementById(
                'invoicePaymentTerms'
            );

        var notesInput =
            document.getElementById(
                'invoiceNotes'
            );

        var lineBody =
            document.getElementById(
                'invoiceLineBody'
            );

        var productMap = {};
        var taxMap = {};

        products.forEach(function (product) {
            productMap[String(product.id)] =
                product;
        });

        taxRates.forEach(function (tax) {
            taxMap[String(tax.id)] =
                tax;
        });

        function escapeHtml(value) {
            return String(
                value === null ||
                typeof value === 'undefined'
                    ? ''
                    : value
            )
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function numberValue(value) {
            var number =
                parseFloat(value);

            return isFinite(number)
                ? number
                : 0;
        }

        function money(value) {
            var amount =
                numberValue(value);

            try {
                return new Intl.NumberFormat(
                    'en-IN',
                    {
                        style: 'currency',
                        currency: currencyCode,
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }
                ).format(amount);
            } catch (error) {
                return currencyCode +
                    ' ' +
                    amount.toFixed(2);
            }
        }

        function productOptions(
            selectedId
        ) {
            var html =
                '<option value="">Custom Item</option>';

            products.forEach(
                function (product) {
                    var selected =
                        String(
                            selectedId || ''
                        ) ===
                        String(product.id)
                            ? ' selected'
                            : '';

                    var label =
                        product.name;

                    if (product.sku) {
                        label +=
                            ' · ' +
                            product.sku;
                    }

                    html +=
                        '<option value="' +
                        escapeHtml(product.id) +
                        '"' +
                        selected +
                        '>' +
                        escapeHtml(label) +
                        '</option>';
                }
            );

            return html;
        }

        function taxOptions(selectedId) {
            var html =
                '<option value="">No Tax</option>';

            taxRates.forEach(
                function (tax) {
                    var selected =
                        String(
                            selectedId || ''
                        ) ===
                        String(tax.id)
                            ? ' selected'
                            : '';

                    var type =
                        String(
                            tax.tax_type || ''
                        ).toLowerCase() ===
                        'inclusive'
                            ? 'Inclusive'
                            : 'Exclusive';

                    html +=
                        '<option value="' +
                        escapeHtml(tax.id) +
                        '"' +
                        selected +
                        '>' +
                        escapeHtml(tax.name) +
                        ' · ' +
                        escapeHtml(tax.rate) +
                        '% · ' +
                        type +
                        '</option>';
                }
            );

            return html;
        }

        function createRow(item) {
            item = item || {};

            var row =
                document.createElement('tr');

            row.className =
                'invoice-line-row';

            row.innerHTML =
                '<td>' +
                    '<select name="product_service_id[]" class="ia-line-select ia-line-product line-product">' +
                        productOptions(
                            item.product_service_id
                        ) +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<input type="text" name="item_name[]" class="ia-line-input ia-line-name line-name" maxlength="190" value="' +
                        escapeHtml(
                            item.item_name || ''
                        ) +
                    '" required>' +
                '</td>' +
                '<td>' +
                    '<input type="text" name="item_description[]" class="ia-line-input ia-line-description line-description" value="' +
                        escapeHtml(
                            item.description || ''
                        ) +
                    '">' +
                '</td>' +
                '<td>' +
                    '<input type="number" name="quantity[]" class="ia-line-input ia-line-quantity line-quantity" min="0.001" step="0.001" value="' +
                        escapeHtml(
                            typeof item.quantity !==
                            'undefined'
                                ? item.quantity
                                : '1'
                        ) +
                    '" required>' +
                '</td>' +
                '<td>' +
                    '<input type="number" name="unit_cost[]" class="ia-line-input ia-line-money line-cost" min="0" step="0.01" value="' +
                        escapeHtml(
                            typeof item.unit_cost !==
                            'undefined'
                                ? item.unit_cost
                                : '0'
                        ) +
                    '">' +
                '</td>' +
                '<td>' +
                    '<input type="number" name="unit_price[]" class="ia-line-input ia-line-money line-price" min="0" step="0.01" value="' +
                        escapeHtml(
                            typeof item.unit_price !==
                            'undefined'
                                ? item.unit_price
                                : '0'
                        ) +
                    '" required>' +
                '</td>' +
                '<td>' +
                    '<input type="number" name="discount_amount[]" class="ia-line-input ia-line-money line-discount" min="0" step="0.01" value="' +
                        escapeHtml(
                            typeof item.discount_amount !==
                            'undefined'
                                ? item.discount_amount
                                : '0'
                        ) +
                    '">' +
                '</td>' +
                '<td>' +
                    '<select name="tax_rate_id[]" class="ia-line-select ia-line-tax line-tax">' +
                        taxOptions(
                            item.tax_rate_id
                        ) +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<span class="ia-line-total line-total">' +
                        money(0) +
                    '</span>' +
                '</td>' +
                '<td>' +
                    '<button type="button" class="ia-remove-line remove-line" title="Remove Item">' +
                        '<i class="bi bi-trash"></i>' +
                    '</button>' +
                '</td>';

            bindRow(row);
            lineBody.appendChild(row);
            calculateRow(row);

            return row;
        }

        function bindRow(row) {
            var productSelect =
                row.querySelector(
                    '.line-product'
                );

            var removeButton =
                row.querySelector(
                    '.remove-line'
                );

            productSelect.addEventListener(
                'change',
                function () {
                    var product =
                        productMap[
                            String(
                                productSelect.value
                            )
                        ];

                    if (!product) {
                        calculateRow(row);
                        return;
                    }

                    row.querySelector(
                        '.line-name'
                    ).value =
                        product.name || '';

                    row.querySelector(
                        '.line-description'
                    ).value =
                        product.description || '';

                    row.querySelector(
                        '.line-cost'
                    ).value =
                        numberValue(
                            product.unit_cost
                        ).toFixed(2);

                    row.querySelector(
                        '.line-price'
                    ).value =
                        numberValue(
                            product.unit_price
                        ).toFixed(2);

                    row.querySelector(
                        '.line-tax'
                    ).value =
                        product.tax_rate_id
                            ? String(
                                product.tax_rate_id
                            )
                            : '';

                    calculateRow(row);
                }
            );

            row.querySelectorAll(
                '.line-quantity,' +
                '.line-price,' +
                '.line-discount,' +
                '.line-tax'
            ).forEach(
                function (input) {
                    input.addEventListener(
                        'input',
                        function () {
                            calculateRow(row);
                        }
                    );

                    input.addEventListener(
                        'change',
                        function () {
                            calculateRow(row);
                        }
                    );
                }
            );

            removeButton.addEventListener(
                'click',
                function () {
                    row.remove();

                    if (
                        lineBody.querySelectorAll(
                            '.invoice-line-row'
                        ).length === 0
                    ) {
                        createRow({});
                    }

                    calculateInvoice();
                }
            );
        }

        function calculateRow(row) {
            var quantity =
                Math.max(
                    0,
                    numberValue(
                        row.querySelector(
                            '.line-quantity'
                        ).value
                    )
                );

            var price =
                Math.max(
                    0,
                    numberValue(
                        row.querySelector(
                            '.line-price'
                        ).value
                    )
                );

            var discount =
                Math.max(
                    0,
                    numberValue(
                        row.querySelector(
                            '.line-discount'
                        ).value
                    )
                );

            var gross =
                quantity * price;

            discount =
                Math.min(
                    discount,
                    gross
                );

            var afterDiscount =
                gross - discount;

            var taxSelect =
                row.querySelector(
                    '.line-tax'
                );

            var tax =
                taxMap[
                    String(taxSelect.value)
                ];

            var rate =
                tax
                    ? Math.max(
                        0,
                        numberValue(
                            tax.rate
                        )
                    )
                    : 0;

            var taxType =
                tax &&
                String(
                    tax.tax_type || ''
                ).toLowerCase() ===
                    'inclusive'
                    ? 'inclusive'
                    : 'exclusive';

            var taxAmount = 0;
            var total =
                afterDiscount;

            if (
                rate > 0 &&
                afterDiscount > 0
            ) {
                if (
                    taxType ===
                    'inclusive'
                ) {
                    taxAmount =
                        afterDiscount -
                        (
                            afterDiscount /
                            (
                                1 +
                                (rate / 100)
                            )
                        );

                    total =
                        afterDiscount;
                } else {
                    taxAmount =
                        afterDiscount *
                        (rate / 100);

                    total =
                        afterDiscount +
                        taxAmount;
                }
            }

            row.dataset.gross =
                gross.toFixed(2);

            row.dataset.discount =
                discount.toFixed(2);

            row.dataset.tax =
                taxAmount.toFixed(2);

            row.dataset.total =
                total.toFixed(2);

            row.querySelector(
                '.line-total'
            ).textContent =
                money(total);

            calculateInvoice();
        }

        function calculateInvoice() {
            var subtotal = 0;
            var discount = 0;
            var tax = 0;
            var total = 0;

            lineBody.querySelectorAll(
                '.invoice-line-row'
            ).forEach(
                function (row) {
                    subtotal +=
                        numberValue(
                            row.dataset.gross
                        );

                    discount +=
                        numberValue(
                            row.dataset.discount
                        );

                    tax +=
                        numberValue(
                            row.dataset.tax
                        );

                    total +=
                        numberValue(
                            row.dataset.total
                        );
                }
            );

            document.getElementById(
                'summarySubtotal'
            ).textContent =
                money(subtotal);

            document.getElementById(
                'summaryDiscount'
            ).textContent =
                money(discount);

            document.getElementById(
                'summaryTax'
            ).textContent =
                money(tax);

            document.getElementById(
                'summaryTotal'
            ).textContent =
                money(total);

            document.getElementById(
                'summaryBalance'
            ).textContent =
                money(
                    Math.max(
                        0,
                        total - existingAmountPaid
                    )
                );
        }

        function replaceRows(items) {
            lineBody.innerHTML = '';

            if (
                !Array.isArray(items) ||
                items.length === 0
            ) {
                createRow({});
                return;
            }

            items.forEach(function (item) {
                createRow(item);
            });

            calculateInvoice();
        }

        function selectedOption(select) {
            return select.options[
                select.selectedIndex
            ] || null;
        }

        function filterSelect(
            select,
            callback
        ) {
            Array.prototype.forEach.call(
                select.options,
                function (option) {
                    if (option.value === '') {
                        option.hidden = false;
                        option.disabled = false;
                        return;
                    }

                    var visible =
                        callback(option);

                    option.hidden =
                        !visible;

                    option.disabled =
                        !visible;
                }
            );

            var selected =
                selectedOption(select);

            if (
                selected &&
                selected.value !== '' &&
                (
                    selected.hidden ||
                    selected.disabled
                )
            ) {
                select.value = '';
            }
        }

        function updateRelatedFilters() {
            var clientId =
                clientSelect.value;

            var jobId =
                jobSelect.value;

            filterSelect(
                propertySelect,
                function (option) {
                    return clientId === '' ||
                        option.dataset.clientId ===
                            clientId;
                }
            );

            filterSelect(
                jobSelect,
                function (option) {
                    return clientId === '' ||
                        option.dataset.clientId ===
                            clientId;
                }
            );

            filterSelect(
                quoteSelect,
                function (option) {
                    return clientId === '' ||
                        option.dataset.clientId ===
                            clientId;
                }
            );

            filterSelect(
                visitSelect,
                function (option) {
                    var clientMatches =
                        clientId === '' ||
                        option.dataset.clientId ===
                            clientId;

                    var jobMatches =
                        jobId === '' ||
                        option.dataset.jobId ===
                            jobId;

                    return clientMatches &&
                        jobMatches;
                }
            );

            document.getElementById(
                'loadJobItems'
            ).disabled =
                jobSelect.value === '';

            document.getElementById(
                'loadQuoteItems'
            ).disabled =
                quoteSelect.value === '';
        }

        function updateClientSummary() {
            var option =
                selectedOption(
                    clientSelect
                );

            var box =
                document.getElementById(
                    'invoiceClientSummary'
                );

            if (
                !option ||
                option.value === ''
            ) {
                box.innerHTML =
                    '<div class="ia-client-name">No client selected</div>' +
                    '<div class="ia-client-meta">Select a client to review billing details.</div>';

                return;
            }

            var meta = [];

            if (
                option.dataset.company
            ) {
                meta.push(
                    option.dataset.company
                );
            }

            if (option.dataset.phone) {
                meta.push(
                    option.dataset.phone
                );
            }

            if (option.dataset.email) {
                meta.push(
                    option.dataset.email
                );
            }

            if (
                option.dataset.taxNumber
            ) {
                meta.push(
                    'Tax: ' +
                    option.dataset.taxNumber
                );
            }

            box.innerHTML =
                '<div class="ia-client-name">' +
                escapeHtml(
                    option.textContent
                        .replace(/\s+/g, ' ')
                        .trim()
                        .split(' · ')[0]
                ) +
                '</div>' +
                '<div class="ia-client-meta">' +
                escapeHtml(
                    meta.length
                        ? meta.join(' · ')
                        : 'No additional contact details'
                ) +
                '</div>';
        }

        clientSelect.addEventListener(
            'change',
            function () {
                propertySelect.value = '';
                jobSelect.value = '';
                visitSelect.value = '';
                quoteSelect.value = '';

                updateRelatedFilters();
                updateClientSummary();
            }
        );

        propertySelect.addEventListener(
            'change',
            function () {
                var option =
                    selectedOption(
                        propertySelect
                    );

                if (
                    option &&
                    option.value !== '' &&
                    clientSelect.value === ''
                ) {
                    clientSelect.value =
                        option.dataset.clientId ||
                        '';
                }

                updateRelatedFilters();
                updateClientSummary();
            }
        );

        jobSelect.addEventListener(
            'change',
            function () {
                var option =
                    selectedOption(
                        jobSelect
                    );

                if (
                    option &&
                    option.value !== ''
                ) {
                    if (
                        clientSelect.value === ''
                    ) {
                        clientSelect.value =
                            option.dataset.clientId ||
                            '';
                    }

                    if (
                        propertySelect.value ===
                            '' &&
                        option.dataset.propertyId
                    ) {
                        propertySelect.value =
                            option.dataset.propertyId;
                    }

                    if (
                        quoteSelect.value ===
                            '' &&
                        option.dataset.quoteId
                    ) {
                        quoteSelect.value =
                            option.dataset.quoteId;
                    }
                }

                updateRelatedFilters();
                updateClientSummary();
            }
        );

        visitSelect.addEventListener(
            'change',
            function () {
                var option =
                    selectedOption(
                        visitSelect
                    );

                if (
                    option &&
                    option.value !== ''
                ) {
                    clientSelect.value =
                        option.dataset.clientId ||
                        clientSelect.value;

                    jobSelect.value =
                        option.dataset.jobId ||
                        jobSelect.value;

                    if (
                        propertySelect.value ===
                            '' &&
                        option.dataset.propertyId
                    ) {
                        propertySelect.value =
                            option.dataset.propertyId;
                    }
                }

                updateRelatedFilters();
                updateClientSummary();
            }
        );

        quoteSelect.addEventListener(
            'change',
            function () {
                var option =
                    selectedOption(
                        quoteSelect
                    );

                if (
                    option &&
                    option.value !== ''
                ) {
                    clientSelect.value =
                        option.dataset.clientId ||
                        clientSelect.value;

                    if (
                        propertySelect.value ===
                            '' &&
                        option.dataset.propertyId
                    ) {
                        propertySelect.value =
                            option.dataset.propertyId;
                    }

                    if (
                        paymentTermsInput.value
                            .trim() === '' &&
                        option.dataset.terms
                    ) {
                        paymentTermsInput.value =
                            option.dataset.terms;
                    }

                    if (
                        notesInput.value
                            .trim() === '' &&
                        option.dataset.note
                    ) {
                        notesInput.value =
                            option.dataset.note;
                    }
                }

                updateRelatedFilters();
                updateClientSummary();
            }
        );

        document.getElementById(
            'addInvoiceLine'
        ).addEventListener(
            'click',
            function () {
                createRow({});
            }
        );

        document.getElementById(
            'loadJobItems'
        ).addEventListener(
            'click',
            function () {
                var id =
                    jobSelect.value;

                if (
                    id === '' ||
                    !jobSourceItems[id] ||
                    !jobSourceItems[id].length
                ) {
                    window.alert(
                        'No line items are available for the selected job.'
                    );

                    return;
                }

                if (
                    window.confirm(
                        'Replace the current invoice items with the selected job items?'
                    )
                ) {
                    replaceRows(
                        jobSourceItems[id]
                    );
                }
            }
        );

        document.getElementById(
            'loadQuoteItems'
        ).addEventListener(
            'click',
            function () {
                var id =
                    quoteSelect.value;

                if (
                    id === '' ||
                    !quoteSourceItems[id] ||
                    !quoteSourceItems[id].length
                ) {
                    window.alert(
                        'No selected line items are available for the selected quote.'
                    );

                    return;
                }

                if (
                    window.confirm(
                        'Replace the current invoice items with the selected quote items?'
                    )
                ) {
                    replaceRows(
                        quoteSourceItems[id]
                    );
                }
            }
        );

        document.getElementById(
            'invoiceEditForm'
        ).addEventListener(
            'submit',
            function (event) {
                var rows =
                    lineBody.querySelectorAll(
                        '.invoice-line-row'
                    );

                if (rows.length === 0) {
                    event.preventDefault();

                    window.alert(
                        'Please add at least one invoice item.'
                    );

                    return;
                }

                var invalid = false;

                rows.forEach(function (row) {
                    var name =
                        row.querySelector(
                            '.line-name'
                        ).value.trim();

                    var quantity =
                        numberValue(
                            row.querySelector(
                                '.line-quantity'
                            ).value
                        );

                    var price =
                        numberValue(
                            row.querySelector(
                                '.line-price'
                            ).value
                        );

                    var discount =
                        numberValue(
                            row.querySelector(
                                '.line-discount'
                            ).value
                        );

                    if (
                        name === '' ||
                        quantity <= 0 ||
                        price < 0 ||
                        discount < 0 ||
                        discount >
                            quantity * price
                    ) {
                        invalid = true;
                    }
                });

                var editedTotal = 0;

                rows.forEach(function (row) {
                    editedTotal +=
                        numberValue(
                            row.dataset.total
                        );
                });

                if (
                    !invalid &&
                    editedTotal + 0.005 <
                        existingAmountPaid
                ) {
                    invalid = true;

                    window.alert(
                        'The edited invoice total cannot be lower than the amount already paid.'
                    );
                }

                if (invalid) {
                    event.preventDefault();

                    if (
                        editedTotal + 0.005 >=
                        existingAmountPaid
                    ) {
                        window.alert(
                            'Please check the invoice items. Every item needs a name, positive quantity, valid price, and a discount not exceeding its gross amount.'
                        );
                    }
                } else {
                    document.getElementById(
                        'saveInvoiceButton'
                    ).disabled = true;

                    document.getElementById(
                        'saveInvoiceButton'
                    ).innerHTML =
                        '<i class="bi bi-hourglass-split"></i> Saving Changes...';
                }
            }
        );

        replaceRows(initialRows);
        updateRelatedFilters();
        updateClientSummary();
    }
);
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
