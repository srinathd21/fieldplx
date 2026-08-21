<?php
/**
 * FieldPlx - Invoice View
 *
 * Upload as:
 * /public_html/invoice-view.php
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

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    $redirect = 'invoice-view.php';

    if (!empty($_GET['id'])) {
        $redirect .= '?id=' . (int) $_GET['id'];
    }

    header(
        'Location: login.php?redirect=' .
        rawurlencode($redirect)
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'invoices.view',
        'You do not have permission to view invoices.'
    );
}

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];
$invoiceId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($invoiceId <= 0) {
    header('Location: invoices.php');
    exit;
}

$canManageInvoices = function_exists('hasPermission')
    ? hasPermission('invoices.manage')
    : true;

$canRecordPayments = function_exists('hasPermission')
    ? hasPermission('payments.manage')
    : true;

$pageTitle = 'Invoice Details - FieldPlx';
$activePage = 'invoices';
$searchPlaceholder = 'Search invoices...';
$basePath = '';
$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('invoiceViewFetchAssoc')) {
    function invoiceViewFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('invoiceViewFetchAll')) {
    function invoiceViewFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('invoiceViewPost')) {
    function invoiceViewPost($key, $default = '')
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

if (!function_exists('invoiceViewCsrfToken')) {
    function invoiceViewCsrfToken()
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

if (!function_exists('invoiceViewVerifyCsrf')) {
    function invoiceViewVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('invoiceViewDate')) {
    function invoiceViewDate($value)
    {
        if (empty($value)) {
            return 'Not set';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y', $timestamp)
            : 'Not set';
    }
}

if (!function_exists('invoiceViewDateTime')) {
    function invoiceViewDateTime($value)
    {
        if (empty($value)) {
            return 'Not set';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : 'Not set';
    }
}

if (!function_exists('invoiceViewLabel')) {
    function invoiceViewLabel($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 'Not set';
        }

        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                $value
            )
        );
    }
}

if (!function_exists('invoiceViewMoney')) {
    function invoiceViewMoney($amount, $currencyCode)
    {
        $currencyCode = strtoupper(
            trim((string) $currencyCode)
        );

        $symbols = array(
            'INR' => '₹',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'CAD' => 'CAD ',
            'AUD' => 'AUD '
        );

        $prefix = isset($symbols[$currencyCode])
            ? $symbols[$currencyCode]
            : $currencyCode . ' ';

        return $prefix .
            number_format(
                (float) $amount,
                2
            );
    }
}

if (!function_exists('invoiceViewQuantity')) {
    function invoiceViewQuantity($quantity)
    {
        $formatted = number_format(
            (float) $quantity,
            3,
            '.',
            ''
        );

        return rtrim(
            rtrim($formatted, '0'),
            '.'
        );
    }
}

if (!function_exists('invoiceViewStatusClass')) {
    function invoiceViewStatusClass($status)
    {
        $allowed = array(
            'draft',
            'sent',
            'viewed',
            'partially_paid',
            'paid',
            'overdue',
            'written_off',
            'cancelled',
            'archived',
            'pending',
            'authorized',
            'succeeded',
            'failed',
            'refunded',
            'partially_refunded'
        );

        $status = strtolower(
            trim((string) $status)
        );

        return in_array(
            $status,
            $allowed,
            true
        )
            ? $status
            : 'default';
    }
}

if (!function_exists('invoiceViewAddressLines')) {
    function invoiceViewAddressLines(array $values)
    {
        $lines = array();

        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return $lines;
    }
}

if (!function_exists('invoiceViewLogActivity')) {
    function invoiceViewLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $invoiceId,
        $clientId,
        $eventType,
        $title,
        array $details
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
                    ?,
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

            $detailsJson = json_encode(
                $details,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            $stmt->bind_param(
                'iisisss',
                $tenantId,
                $userId,
                $eventType,
                $invoiceId,
                $clientId,
                $title,
                $detailsJson
            );

            $stmt->execute();
            $stmt->close();
        } catch (Throwable $error) {
            error_log(
                'Invoice activity log failed: ' .
                $error->getMessage()
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Invoice actions
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    if (!$canManageInvoices) {
        $errors[] =
            'You do not have permission to update invoices.';
    }

    if (
        !invoiceViewVerifyCsrf(
            invoiceViewPost('csrf_token')
        )
    ) {
        $errors[] =
            'Your session token is invalid. Refresh the page and try again.';
    }

    $action = invoiceViewPost('action');

    $allowedActions = array(
        'mark_sent',
        'cancel_invoice',
        'write_off_invoice'
    );

    if (
        empty($errors) &&
        !in_array(
            $action,
            $allowedActions,
            true
        )
    ) {
        $errors[] = 'Invalid invoice action.';
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                SELECT
                    id,
                    invoice_no,
                    client_id,
                    status,
                    issue_date,
                    due_date,
                    total,
                    amount_paid,
                    balance_due
                FROM invoices
                WHERE id = ?
                  AND tenant_id = ?
                  AND archived_at IS NULL
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the invoice action.'
                );
            }

            $stmt->bind_param(
                'ii',
                $invoiceId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to load the invoice for update.'
                );
            }

            $lockedInvoice =
                invoiceViewFetchAssoc($stmt);

            $stmt->close();

            if (!$lockedInvoice) {
                throw new Exception(
                    'Invoice not found or access denied.'
                );
            }

            $oldStatus =
                (string) $lockedInvoice['status'];

            $newStatus = $oldStatus;
            $newBalance =
                (float) $lockedInvoice['balance_due'];
            $eventType = '';
            $eventTitle = '';

            if ($action === 'mark_sent') {
                if ($oldStatus !== 'draft') {
                    throw new Exception(
                        'Only draft invoices can be marked as sent.'
                    );
                }

                $newStatus = 'sent';

                if (
                    !empty($lockedInvoice['due_date']) &&
                    strtotime(
                        $lockedInvoice['due_date']
                    ) < strtotime(date('Y-m-d')) &&
                    $newBalance > 0
                ) {
                    $newStatus = 'overdue';
                }

                $stmt = $conn->prepare("
                    UPDATE invoices
                    SET
                        status = ?,
                        sent_at = COALESCE(sent_at, NOW()),
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare the invoice status update.'
                    );
                }

                $stmt->bind_param(
                    'sii',
                    $newStatus,
                    $invoiceId,
                    $tenantId
                );

                $eventType = 'invoice_sent';
                $eventTitle =
                    'Invoice marked as sent: ' .
                    $lockedInvoice['invoice_no'];
            } elseif (
                $action === 'cancel_invoice'
            ) {
                if (
                    in_array(
                        $oldStatus,
                        array(
                            'paid',
                            'written_off',
                            'cancelled',
                            'archived'
                        ),
                        true
                    )
                ) {
                    throw new Exception(
                        'This invoice cannot be cancelled in its current status.'
                    );
                }

                if (
                    (float) $lockedInvoice['amount_paid'] > 0
                ) {
                    throw new Exception(
                        'An invoice with recorded payments cannot be cancelled.'
                    );
                }

                $newStatus = 'cancelled';

                $stmt = $conn->prepare("
                    UPDATE invoices
                    SET
                        status = 'cancelled',
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare invoice cancellation.'
                    );
                }

                $stmt->bind_param(
                    'ii',
                    $invoiceId,
                    $tenantId
                );

                $eventType = 'invoice_cancelled';
                $eventTitle =
                    'Invoice cancelled: ' .
                    $lockedInvoice['invoice_no'];
            } else {
                if (
                    $newBalance <= 0 ||
                    in_array(
                        $oldStatus,
                        array(
                            'draft',
                            'paid',
                            'written_off',
                            'cancelled',
                            'archived'
                        ),
                        true
                    )
                ) {
                    throw new Exception(
                        'This invoice does not have an eligible balance to write off.'
                    );
                }

                $newStatus = 'written_off';
                $newBalance = 0.00;

                $stmt = $conn->prepare("
                    UPDATE invoices
                    SET
                        status = 'written_off',
                        balance_due = 0.00,
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare the write-off action.'
                    );
                }

                $stmt->bind_param(
                    'ii',
                    $invoiceId,
                    $tenantId
                );

                $eventType = 'invoice_written_off';
                $eventTitle =
                    'Invoice balance written off: ' .
                    $lockedInvoice['invoice_no'];
            }

            if (!$stmt->execute()) {
                throw new Exception(
                    'The invoice action could not be completed: ' .
                    $stmt->error
                );
            }

            $stmt->close();
            $conn->commit();

            invoiceViewLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $invoiceId,
                (int) $lockedInvoice['client_id'],
                $eventType,
                $eventTitle,
                array(
                    'invoice_id' => $invoiceId,
                    'invoice_no' =>
                        $lockedInvoice['invoice_no'],
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'old_balance_due' =>
                        (float) $lockedInvoice['balance_due'],
                    'new_balance_due' => $newBalance
                )
            );

            if ($action === 'mark_sent') {
                $_SESSION['flash_success'] =
                    'Invoice marked as sent successfully.';
            } elseif ($action === 'cancel_invoice') {
                $_SESSION['flash_success'] =
                    'Invoice cancelled successfully.';
            } else {
                $_SESSION['flash_success'] =
                    'Invoice balance written off successfully.';
            }

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

            $errors[] = $error->getMessage();

            error_log(
                'Invoice view action failed: ' .
                $error->getMessage()
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Automatically refresh overdue status
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    UPDATE invoices
    SET
        status = 'overdue',
        updated_at = NOW()
    WHERE id = ?
      AND tenant_id = ?
      AND archived_at IS NULL
      AND balance_due > 0
      AND due_date IS NOT NULL
      AND due_date < CURDATE()
      AND status IN (
          'sent',
          'viewed',
          'partially_paid'
      )
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $invoiceId,
        $tenantId
    );

    $stmt->execute();
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Load invoice
|--------------------------------------------------------------------------
*/

$invoice = null;

$stmt = $conn->prepare("
    SELECT
        i.id,
        i.tenant_id,
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
        c.company_name AS client_company,
        c.first_name AS client_first_name,
        c.last_name AS client_last_name,
        c.email AS client_email,
        c.phone AS client_phone,
        c.alternate_phone AS client_alternate_phone,
        c.tax_number AS client_tax_number,
        c.billing_address_line1,
        c.billing_address_line2,
        c.billing_city,
        c.billing_state,
        c.billing_postal_code,
        c.billing_country,

        p.name AS property_name,
        p.address_line1 AS property_address_line1,
        p.address_line2 AS property_address_line2,
        p.city AS property_city,
        p.state AS property_state,
        p.postal_code AS property_postal_code,
        p.country AS property_country,

        j.job_no,
        j.title AS job_title,
        j.status AS job_status,

        v.visit_no,
        v.status AS visit_status,
        v.scheduled_start AS visit_scheduled_start,

        q.quote_no,
        q.title AS quote_title,
        q.status AS quote_status,

        CONCAT(
            COALESCE(cu.first_name, ''),
            CASE
                WHEN cu.last_name IS NOT NULL
                 AND cu.last_name <> ''
                THEN CONCAT(' ', cu.last_name)
                ELSE ''
            END
        ) AS created_by_name,

        t.company_name AS tenant_company_name,
        t.business_type AS tenant_business_type,
        t.email AS tenant_email,
        t.phone AS tenant_phone,
        t.website AS tenant_website,
        t.logo_path AS tenant_logo_path,
        t.currency_code,
        t.date_format,
        t.timezone

    FROM invoices i

    INNER JOIN clients c
        ON c.id = i.client_id
       AND c.tenant_id = i.tenant_id

    INNER JOIN tenants t
        ON t.id = i.tenant_id
       AND t.deleted_at IS NULL

    LEFT JOIN properties p
        ON p.id = i.property_id
       AND p.tenant_id = i.tenant_id
       AND p.deleted_at IS NULL

    LEFT JOIN jobs j
        ON j.id = i.job_id
       AND j.tenant_id = i.tenant_id
       AND j.deleted_at IS NULL

    LEFT JOIN visits v
        ON v.id = i.visit_id
       AND v.tenant_id = i.tenant_id

    LEFT JOIN quotes q
        ON q.id = i.quote_id
       AND q.tenant_id = i.tenant_id
       AND q.archived_at IS NULL

    LEFT JOIN users cu
        ON cu.id = i.created_by
       AND cu.tenant_id = i.tenant_id
       AND cu.deleted_at IS NULL

    WHERE i.id = ?
      AND i.tenant_id = ?
      AND i.archived_at IS NULL

    LIMIT 1
");

if (!$stmt) {
    $errors[] =
        'Unable to prepare the invoice query: ' .
        $conn->error;
} else {
    $stmt->bind_param(
        'ii',
        $invoiceId,
        $tenantId
    );

    if (!$stmt->execute()) {
        $errors[] =
            'Unable to load the invoice: ' .
            $stmt->error;
    } else {
        $invoice =
            invoiceViewFetchAssoc($stmt);
    }

    $stmt->close();
}

if (!$invoice) {
    http_response_code(404);

    require_once __DIR__ . '/includes/topbar.php';
    ?>
    <div style="padding:40px;text-align:center;">
        <h2 style="margin:0 0 8px;font-size:20px;">
            Invoice not found
        </h2>
        <p style="margin:0 0 16px;color:#6b7280;">
            This invoice does not exist or does not belong to your workspace.
        </p>
        <a href="invoices.php" style="color:#6d28d9;font-weight:700;">
            Back to Invoices
        </a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle =
    $invoice['invoice_no'] .
    ' - Invoice - FieldPlx';

/*
|--------------------------------------------------------------------------
| Tenant location
|--------------------------------------------------------------------------
*/

$tenantLocation = array();

$stmt = $conn->prepare("
    SELECT
        name,
        address_line1,
        address_line2,
        city,
        state,
        postal_code,
        country
    FROM tenant_locations
    WHERE tenant_id = ?
    ORDER BY
        is_primary DESC,
        id ASC
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $tenantLocation =
            invoiceViewFetchAssoc($stmt);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Line items
|--------------------------------------------------------------------------
*/

$lineItems = array();

$stmt = $conn->prepare("
    SELECT
        ili.id,
        ili.product_service_id,
        ili.item_name,
        ili.description,
        ili.quantity,
        ili.unit_cost,
        ili.unit_price,
        ili.discount_amount,
        ili.tax_rate_id,
        ili.tax_amount,
        ili.line_total,
        ili.sort_order,
        ps.sku,
        ps.item_type,
        ps.unit_name,
        tr.name AS tax_name,
        tr.rate AS tax_rate,
        tr.tax_type
    FROM invoice_line_items ili

    LEFT JOIN product_services ps
        ON ps.id = ili.product_service_id
       AND ps.tenant_id = ili.tenant_id

    LEFT JOIN tax_rates tr
        ON tr.id = ili.tax_rate_id
       AND tr.tenant_id = ili.tenant_id

    WHERE ili.invoice_id = ?
      AND ili.tenant_id = ?

    ORDER BY
        ili.sort_order ASC,
        ili.id ASC
");

if (!$stmt) {
    $errors[] =
        'Unable to prepare invoice items: ' .
        $conn->error;
} else {
    $stmt->bind_param(
        'ii',
        $invoiceId,
        $tenantId
    );

    if ($stmt->execute()) {
        $lineItems =
            invoiceViewFetchAll($stmt);
    } else {
        $errors[] =
            'Unable to load invoice items.';
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Payment history
|--------------------------------------------------------------------------
*/

$payments = array();

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.payment_no,
        p.payment_method,
        p.payment_channel,
        p.provider,
        p.provider_payment_id,
        p.status,
        p.amount,
        p.currency_code,
        p.transaction_fee,
        p.received_at,
        p.failure_reason,
        p.notes,
        p.created_at,
        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.last_name IS NOT NULL
                 AND u.last_name <> ''
                THEN CONCAT(' ', u.last_name)
                ELSE ''
            END
        ) AS received_by_name
    FROM payments p

    LEFT JOIN users u
        ON u.id = p.created_by
       AND u.tenant_id = p.tenant_id
       AND u.deleted_at IS NULL

    WHERE p.invoice_id = ?
      AND p.tenant_id = ?

    ORDER BY
        COALESCE(p.received_at, p.created_at) DESC,
        p.id DESC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $invoiceId,
        $tenantId
    );

    if ($stmt->execute()) {
        $payments =
            invoiceViewFetchAll($stmt);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Display state
|--------------------------------------------------------------------------
*/

$currencyCode = trim(
    (string) $invoice['currency_code']
) !== ''
    ? strtoupper(
        trim(
            (string) $invoice['currency_code']
        )
    )
    : 'INR';

$displayStatus =
    (string) $invoice['status'];

$isOverdue =
    (float) $invoice['balance_due'] > 0 &&
    !empty($invoice['due_date']) &&
    strtotime($invoice['due_date']) <
        strtotime(date('Y-m-d')) &&
    !in_array(
        $invoice['status'],
        array(
            'paid',
            'written_off',
            'cancelled',
            'archived'
        ),
        true
    );

if ($isOverdue) {
    $displayStatus = 'overdue';
}

$overdueDays = 0;

if ($isOverdue) {
    $dueTimestamp = strtotime(
        $invoice['due_date']
    );

    $todayTimestamp = strtotime(
        date('Y-m-d')
    );

    $overdueDays = max(
        1,
        (int) floor(
            ($todayTimestamp - $dueTimestamp) /
            86400
        )
    );
}

$tenantAddress =
    invoiceViewAddressLines(
        array(
            isset($tenantLocation['address_line1'])
                ? $tenantLocation['address_line1']
                : '',
            isset($tenantLocation['address_line2'])
                ? $tenantLocation['address_line2']
                : '',
            implode(
                ', ',
                array_filter(
                    array(
                        isset($tenantLocation['city'])
                            ? $tenantLocation['city']
                            : '',
                        isset($tenantLocation['state'])
                            ? $tenantLocation['state']
                            : '',
                        isset($tenantLocation['postal_code'])
                            ? $tenantLocation['postal_code']
                            : ''
                    ),
                    function ($value) {
                        return trim((string) $value) !== '';
                    }
                )
            ),
            isset($tenantLocation['country'])
                ? $tenantLocation['country']
                : ''
        )
    );

$clientAddress =
    invoiceViewAddressLines(
        array(
            $invoice['billing_address_line1'],
            $invoice['billing_address_line2'],
            implode(
                ', ',
                array_filter(
                    array(
                        $invoice['billing_city'],
                        $invoice['billing_state'],
                        $invoice['billing_postal_code']
                    ),
                    function ($value) {
                        return trim((string) $value) !== '';
                    }
                )
            ),
            $invoice['billing_country']
        )
    );

if (empty($clientAddress)) {
    $clientAddress =
        invoiceViewAddressLines(
            array(
                $invoice['property_address_line1'],
                $invoice['property_address_line2'],
                implode(
                    ', ',
                    array_filter(
                        array(
                            $invoice['property_city'],
                            $invoice['property_state'],
                            $invoice['property_postal_code']
                        ),
                        function ($value) {
                            return trim((string) $value) !== '';
                        }
                    )
                ),
                $invoice['property_country']
            )
        );
}

$canEditInvoice =
    $canManageInvoices &&
    !in_array(
        $invoice['status'],
        array(
            'paid',
            'written_off',
            'cancelled',
            'archived'
        ),
        true
    );

$canPayInvoice =
    $canRecordPayments &&
    (float) $invoice['balance_due'] > 0 &&
    !in_array(
        $invoice['status'],
        array(
            'draft',
            'paid',
            'written_off',
            'cancelled',
            'archived'
        ),
        true
    );

$canMarkSent =
    $canManageInvoices &&
    $invoice['status'] === 'draft';

$canCancelInvoice =
    $canManageInvoices &&
    (float) $invoice['amount_paid'] <= 0 &&
    !in_array(
        $invoice['status'],
        array(
            'paid',
            'written_off',
            'cancelled',
            'archived'
        ),
        true
    );

$canWriteOff =
    $canManageInvoices &&
    (float) $invoice['balance_due'] > 0 &&
    !in_array(
        $invoice['status'],
        array(
            'draft',
            'paid',
            'written_off',
            'cancelled',
            'archived'
        ),
        true
    );

$csrfToken =
    invoiceViewCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.invoice-view-page {
    --iv-primary: #6d28d9;
    --iv-primary-dark: #4c1d95;
    --iv-soft: #f5f3ff;
    --iv-text: #111827;
    --iv-muted: #6b7280;
    --iv-border: #e5e7eb;
}

.iv-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.iv-header-main {
    display: flex;
    align-items: center;
    gap: 11px;
}

.iv-header-icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: linear-gradient(
        135deg,
        var(--iv-primary),
        var(--iv-primary-dark)
    );
    color: #fff;
    font-size: 18px;
    box-shadow: 0 10px 22px rgba(109,40,217,.2);
}

.iv-header h1 {
    margin: 0;
    color: var(--iv-text);
    font-size: 21px;
    font-weight: 800;
}

.iv-header p {
    margin: 5px 0 0;
    color: var(--iv-muted);
    font-size: 10px;
}

.iv-header-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 7px;
}

.iv-btn,
.iv-btn-form button {
    min-height: 36px;
    padding: 8px 12px;
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

.iv-btn.secondary,
.iv-btn-form button.secondary {
    border: 1px solid var(--iv-border);
    background: #fff;
    color: #374151;
}

.iv-btn.primary,
.iv-btn-form button.primary {
    border: 0;
    background: linear-gradient(
        135deg,
        var(--iv-primary),
        var(--iv-primary-dark)
    );
    color: #fff;
}

.iv-btn.payment {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.iv-btn-form {
    margin: 0;
}

.iv-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
    line-height: 1.55;
}

.iv-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.iv-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.iv-layout {
    display: grid;
    grid-template-columns: minmax(0,1.58fr) minmax(310px,.68fr);
    gap: 13px;
    align-items: start;
}

.iv-card {
    overflow: hidden;
    border: 1px solid var(--iv-border);
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 6px 20px rgba(15,23,42,.04);
}

.iv-card + .iv-card {
    margin-top: 13px;
}

.iv-card-head {
    min-height: 48px;
    padding: 11px 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    border-bottom: 1px solid #eef0f4;
}

.iv-card-head h2 {
    margin: 0;
    color: var(--iv-text);
    font-size: 11px;
    font-weight: 800;
}

.iv-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 8px;
}

.iv-card-body {
    padding: 14px;
}

.iv-status {
    padding: 5px 8px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 800;
}

.iv-status.draft {
    background: #f3f4f6;
    color: #4b5563;
}

.iv-status.sent,
.iv-status.viewed,
.iv-status.authorized,
.iv-status.pending {
    background: #eff6ff;
    color: #1d4ed8;
}

.iv-status.partially_paid,
.iv-status.partially_refunded {
    background: #fff7ed;
    color: #c2410c;
}

.iv-status.paid,
.iv-status.succeeded {
    background: #ecfdf5;
    color: #047857;
}

.iv-status.overdue,
.iv-status.failed {
    background: #fef2f2;
    color: #b91c1c;
}

.iv-status.written_off,
.iv-status.cancelled,
.iv-status.refunded,
.iv-status.archived {
    background: #f3f4f6;
    color: #6b7280;
}

.iv-invoice-sheet {
    padding: 20px;
}

.iv-document-top {
    display: grid;
    grid-template-columns: minmax(0,1fr) auto;
    gap: 20px;
    align-items: start;
}

.iv-company {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.iv-logo {
    width: 64px;
    height: 54px;
    flex: 0 0 64px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fafafa;
    color: #9ca3af;
}

.iv-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.iv-logo i {
    font-size: 22px;
}

.iv-company-name {
    color: #111827;
    font-size: 15px;
    font-weight: 800;
}

.iv-company-meta {
    margin-top: 4px;
    color: #6b7280;
    font-size: 8px;
    line-height: 1.6;
}

.iv-document-label {
    color: var(--iv-primary);
    font-size: 25px;
    font-weight: 900;
    letter-spacing: .04em;
    text-align: right;
}

.iv-document-number {
    margin-top: 4px;
    color: #111827;
    font-size: 11px;
    font-weight: 800;
    text-align: right;
}

.iv-document-status {
    margin-top: 7px;
    text-align: right;
}

.iv-parties {
    margin-top: 22px;
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 13px;
}

.iv-party {
    padding: 12px;
    border: 1px solid #edf0f5;
    border-radius: 10px;
    background: #fafafa;
}

.iv-party-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.iv-party-name {
    margin-top: 5px;
    color: #111827;
    font-size: 11px;
    font-weight: 800;
}

.iv-party-meta {
    margin-top: 4px;
    color: #6b7280;
    font-size: 8px;
    line-height: 1.65;
    overflow-wrap: anywhere;
}

.iv-dates {
    margin-top: 13px;
    display: grid;
    grid-template-columns: repeat(4,minmax(0,1fr));
    gap: 8px;
}

.iv-date-box {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fff;
}

.iv-date-label {
    color: #9ca3af;
    font-size: 7px;
    font-weight: 800;
    text-transform: uppercase;
}

.iv-date-value {
    margin-top: 4px;
    color: #111827;
    font-size: 9px;
    font-weight: 800;
}

.iv-overdue-note {
    margin-top: 10px;
    padding: 9px 11px;
    border: 1px solid #fecaca;
    border-radius: 9px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 9px;
    font-weight: 700;
}

.iv-table-wrap {
    overflow-x: auto;
}

.iv-table {
    width: 100%;
    border-collapse: collapse;
}

.iv-table th,
.iv-table td {
    padding: 10px 11px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    vertical-align: top;
}

.iv-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.iv-table td {
    color: #374151;
    font-size: 9px;
}

.iv-item-name {
    color: #111827;
    font-size: 9px;
    font-weight: 800;
}

.iv-item-meta,
.iv-item-description {
    margin-top: 3px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.5;
}

.iv-money {
    color: #111827;
    font-size: 9px;
    font-weight: 800;
    white-space: nowrap;
}

.iv-totals {
    margin-left: auto;
    width: min(100%, 355px);
    display: grid;
    gap: 7px;
}

.iv-total-row {
    padding: 9px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #6b7280;
    font-size: 9px;
}

.iv-total-row strong {
    color: #111827;
}

.iv-total-row.discount strong {
    color: #b91c1c;
}

.iv-total-row.grand {
    border: 1px solid #ddd6fe;
    border-radius: 9px;
    background: var(--iv-soft);
    color: var(--iv-primary-dark);
    font-size: 11px;
    font-weight: 800;
}

.iv-total-row.grand strong {
    color: var(--iv-primary-dark);
}

.iv-total-row.paid {
    border: 1px solid #bbf7d0;
    border-radius: 9px;
    background: #f0fdf4;
    color: #047857;
    font-weight: 800;
}

.iv-total-row.paid strong {
    color: #047857;
}

.iv-total-row.balance {
    border: 1px solid #bfdbfe;
    border-radius: 9px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 10px;
    font-weight: 800;
}

.iv-total-row.balance.overdue {
    border-color: #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.iv-total-row.balance strong {
    color: inherit;
}

.iv-notes-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 11px;
}

.iv-note-box {
    padding: 11px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.iv-note-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.iv-note-text {
    margin-top: 5px;
    color: #374151;
    font-size: 9px;
    line-height: 1.65;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.iv-side-summary {
    display: grid;
    gap: 8px;
}

.iv-summary-item {
    padding: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.iv-summary-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.iv-summary-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 9px;
    font-weight: 800;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.iv-related-list {
    display: grid;
    gap: 7px;
}

.iv-related-link {
    min-height: 39px;
    padding: 9px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
    color: #374151;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
}

.iv-related-link:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--iv-primary);
}

.iv-action-list {
    display: grid;
    gap: 7px;
}

.iv-side-action,
.iv-side-form button {
    width: 100%;
    min-height: 38px;
    padding: 8px 10px;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 8px;
    border: 1px solid var(--iv-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-family: inherit;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}

.iv-side-action:hover,
.iv-side-form button:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--iv-primary);
}

.iv-side-action.payment {
    border-color: #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.iv-side-form {
    margin: 0;
}

.iv-side-form button.warning {
    border-color: #fed7aa;
    color: #c2410c;
}

.iv-side-form button.danger {
    border-color: #fecaca;
    color: #b91c1c;
}

.iv-payment-table td,
.iv-payment-table th {
    white-space: nowrap;
}

.iv-payment-table .wrap {
    min-width: 170px;
    white-space: normal;
}

.iv-empty {
    padding: 30px 14px;
    color: #9ca3af;
    font-size: 9px;
    text-align: center;
}

@media (max-width: 1100px) {
    .iv-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .iv-header {
        flex-direction: column;
    }

    .iv-header-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .iv-document-top,
    .iv-parties,
    .iv-notes-grid {
        grid-template-columns: 1fr;
    }

    .iv-document-label,
    .iv-document-number,
    .iv-document-status {
        text-align: left;
    }

    .iv-dates {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 520px) {
    .iv-header-actions,
    .iv-header-actions .iv-btn,
    .iv-header-actions .iv-btn-form,
    .iv-header-actions .iv-btn-form button {
        width: 100%;
    }

    .iv-dates {
        grid-template-columns: 1fr;
    }

    .iv-invoice-sheet {
        padding: 14px;
    }
}

@media print {
    body {
        background: #fff !important;
    }

    .sidebar,
    .topbar,
    .mobile-backdrop,
    .iv-header,
    .iv-alert,
    .iv-print-hide,
    footer {
        display: none !important;
    }

    .main-content,
    .content-wrapper,
    .page-content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }

    .invoice-view-page,
    .iv-layout {
        display: block !important;
    }

    .iv-layout > aside,
    .iv-card-head.iv-print-hide,
    .iv-payment-card {
        display: none !important;
    }

    .iv-card,
    .iv-invoice-sheet {
        border: 0 !important;
        box-shadow: none !important;
        border-radius: 0 !important;
    }

    .iv-card + .iv-card {
        margin-top: 12px !important;
    }

    .iv-table-wrap {
        overflow: visible !important;
    }

    .iv-table {
        font-size: 10px !important;
    }

    @page {
        size: A4;
        margin: 12mm;
    }
}
</style>

<div class="invoice-view-page">
    <div class="iv-header">
        <div class="iv-header-main">
            <div class="iv-header-icon">
                <i class="bi bi-receipt"></i>
            </div>

            <div>
                <h1><?= e($invoice['invoice_no']); ?></h1>
                <p>
                    Invoice for <?= e($invoice['client_name']); ?>
                    · Created <?= e(invoiceViewDate($invoice['created_at'])); ?>
                </p>
            </div>
        </div>

        <div class="iv-header-actions">
            <a href="invoices.php" class="iv-btn secondary">
                <i class="bi bi-arrow-left"></i>
                Back
            </a>

            <button
                type="button"
                class="iv-btn secondary"
                onclick="window.print();"
            >
                <i class="bi bi-printer"></i>
                Print
            </button>

            <?php if ($canEditInvoice): ?>
                <a
                    href="invoice-edit.php?id=<?= $invoiceId; ?>"
                    class="iv-btn secondary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit
                </a>
            <?php endif; ?>

            <?php if ($canPayInvoice): ?>
                <a
                    href="payment-add.php?invoice_id=<?= $invoiceId; ?>"
                    class="iv-btn payment"
                >
                    <i class="bi bi-cash-coin"></i>
                    Record Payment
                </a>
            <?php endif; ?>

            <?php if ($canMarkSent): ?>
                <form method="post" class="iv-btn-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken); ?>"
                    >
                    <input
                        type="hidden"
                        name="action"
                        value="mark_sent"
                    >
                    <button
                        type="submit"
                        class="primary"
                        onclick="return confirm('Mark this invoice as sent or issued?');"
                    >
                        <i class="bi bi-send"></i>
                        Mark Sent
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="iv-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="iv-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="iv-layout">
        <main>
            <section class="iv-card">
                <div class="iv-invoice-sheet">
                    <div class="iv-document-top">
                        <div class="iv-company">
                            <div class="iv-logo">
                                <?php if (!empty($invoice['tenant_logo_path'])): ?>
                                    <img
                                        src="<?= e($invoice['tenant_logo_path']); ?>"
                                        alt="Company logo"
                                    >
                                <?php else: ?>
                                    <i class="bi bi-buildings"></i>
                                <?php endif; ?>
                            </div>

                            <div>
                                <div class="iv-company-name">
                                    <?= e($invoice['tenant_company_name']); ?>
                                </div>

                                <div class="iv-company-meta">
                                    <?php foreach ($tenantAddress as $line): ?>
                                        <div><?= e($line); ?></div>
                                    <?php endforeach; ?>

                                    <?php if (!empty($invoice['tenant_phone'])): ?>
                                        <div><?= e($invoice['tenant_phone']); ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($invoice['tenant_email'])): ?>
                                        <div><?= e($invoice['tenant_email']); ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($invoice['tenant_website'])): ?>
                                        <div><?= e($invoice['tenant_website']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="iv-document-label">INVOICE</div>
                            <div class="iv-document-number">
                                <?= e($invoice['invoice_no']); ?>
                            </div>
                            <div class="iv-document-status">
                                <span class="iv-status <?= e(invoiceViewStatusClass($displayStatus)); ?>">
                                    <?= e(invoiceViewLabel($displayStatus)); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="iv-parties">
                        <div class="iv-party">
                            <div class="iv-party-label">Bill To</div>
                            <div class="iv-party-name">
                                <?= e($invoice['client_name']); ?>
                            </div>
                            <div class="iv-party-meta">
                                <?php if (!empty($invoice['client_company'])): ?>
                                    <div><?= e($invoice['client_company']); ?></div>
                                <?php endif; ?>

                                <?php foreach ($clientAddress as $line): ?>
                                    <div><?= e($line); ?></div>
                                <?php endforeach; ?>

                                <?php if (!empty($invoice['client_phone'])): ?>
                                    <div><?= e($invoice['client_phone']); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($invoice['client_email'])): ?>
                                    <div><?= e($invoice['client_email']); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($invoice['client_tax_number'])): ?>
                                    <div>Tax No: <?= e($invoice['client_tax_number']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="iv-party">
                            <div class="iv-party-label">Service Location</div>
                            <div class="iv-party-name">
                                <?= e(
                                    !empty($invoice['property_name'])
                                        ? $invoice['property_name']
                                        : 'No property selected'
                                ); ?>
                            </div>
                            <div class="iv-party-meta">
                                <?php
                                $propertyAddress = invoiceViewAddressLines(
                                    array(
                                        $invoice['property_address_line1'],
                                        $invoice['property_address_line2'],
                                        implode(
                                            ', ',
                                            array_filter(
                                                array(
                                                    $invoice['property_city'],
                                                    $invoice['property_state'],
                                                    $invoice['property_postal_code']
                                                ),
                                                function ($value) {
                                                    return trim((string) $value) !== '';
                                                }
                                            )
                                        ),
                                        $invoice['property_country']
                                    )
                                );
                                ?>

                                <?php if (!empty($propertyAddress)): ?>
                                    <?php foreach ($propertyAddress as $line): ?>
                                        <div><?= e($line); ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div>No separate service address.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="iv-dates">
                        <div class="iv-date-box">
                            <div class="iv-date-label">Invoice Date</div>
                            <div class="iv-date-value">
                                <?= e(invoiceViewDate($invoice['issue_date'])); ?>
                            </div>
                        </div>

                        <div class="iv-date-box">
                            <div class="iv-date-label">Due Date</div>
                            <div class="iv-date-value">
                                <?= e(invoiceViewDate($invoice['due_date'])); ?>
                            </div>
                        </div>

                        <div class="iv-date-box">
                            <div class="iv-date-label">Currency</div>
                            <div class="iv-date-value">
                                <?= e($currencyCode); ?>
                            </div>
                        </div>

                        <div class="iv-date-box">
                            <div class="iv-date-label">Payment Terms</div>
                            <div class="iv-date-value">
                                <?= e(
                                    !empty($invoice['payment_terms'])
                                        ? $invoice['payment_terms']
                                        : 'Not specified'
                                ); ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($isOverdue): ?>
                        <div class="iv-overdue-note">
                            <i class="bi bi-exclamation-triangle"></i>
                            This invoice is overdue by <?= e($overdueDays); ?> day<?= $overdueDays === 1 ? '' : 's'; ?>.
                            Outstanding balance:
                            <?= e(invoiceViewMoney($invoice['balance_due'], $currencyCode)); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="iv-card">
                <div class="iv-card-head">
                    <div>
                        <h2>Invoice Items</h2>
                        <p><?= e(count($lineItems)); ?> item<?= count($lineItems) === 1 ? '' : 's'; ?> in this invoice.</p>
                    </div>
                </div>

                <?php if (!empty($lineItems)): ?>
                    <div class="iv-table-wrap">
                        <table class="iv-table">
                            <thead>
                                <tr>
                                    <th style="width:38%;">Item</th>
                                    <th style="text-align:right;">Qty</th>
                                    <th style="text-align:right;">Unit Price</th>
                                    <th style="text-align:right;">Discount</th>
                                    <th style="text-align:right;">Tax</th>
                                    <th style="text-align:right;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lineItems as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="iv-item-name">
                                                <?= e($item['item_name']); ?>
                                            </div>

                                            <?php if (!empty($item['description'])): ?>
                                                <div class="iv-item-description">
                                                    <?= e($item['description']); ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="iv-item-meta">
                                                <?php if (!empty($item['sku'])): ?>
                                                    SKU: <?= e($item['sku']); ?>
                                                <?php endif; ?>

                                                <?php if (!empty($item['unit_name'])): ?>
                                                    <?= !empty($item['sku']) ? ' · ' : ''; ?>
                                                    Unit: <?= e($item['unit_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="text-align:right;">
                                            <?= e(invoiceViewQuantity($item['quantity'])); ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <span class="iv-money">
                                                <?= e(invoiceViewMoney($item['unit_price'], $currencyCode)); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right;">
                                            <?= e(invoiceViewMoney($item['discount_amount'], $currencyCode)); ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <span class="iv-money">
                                                <?= e(invoiceViewMoney($item['tax_amount'], $currencyCode)); ?>
                                            </span>
                                            <?php if (!empty($item['tax_name'])): ?>
                                                <div class="iv-item-meta">
                                                    <?= e($item['tax_name']); ?>
                                                    <?= e(number_format((float) $item['tax_rate'], 2)); ?>%
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <span class="iv-money">
                                                <?= e(invoiceViewMoney($item['line_total'], $currencyCode)); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="iv-empty">
                        No line items are stored for this invoice.
                    </div>
                <?php endif; ?>

                <div class="iv-card-body">
                    <div class="iv-totals">
                        <div class="iv-total-row">
                            <span>Subtotal</span>
                            <strong><?= e(invoiceViewMoney($invoice['subtotal'], $currencyCode)); ?></strong>
                        </div>

                        <div class="iv-total-row discount">
                            <span>Discount</span>
                            <strong>-<?= e(invoiceViewMoney($invoice['discount_total'], $currencyCode)); ?></strong>
                        </div>

                        <div class="iv-total-row">
                            <span>Tax</span>
                            <strong><?= e(invoiceViewMoney($invoice['tax_total'], $currencyCode)); ?></strong>
                        </div>

                        <div class="iv-total-row grand">
                            <span>Invoice Total</span>
                            <strong><?= e(invoiceViewMoney($invoice['total'], $currencyCode)); ?></strong>
                        </div>

                        <div class="iv-total-row paid">
                            <span>Amount Paid</span>
                            <strong><?= e(invoiceViewMoney($invoice['amount_paid'], $currencyCode)); ?></strong>
                        </div>

                        <div class="iv-total-row balance <?= $isOverdue ? 'overdue' : ''; ?>">
                            <span>Balance Due</span>
                            <strong><?= e(invoiceViewMoney($invoice['balance_due'], $currencyCode)); ?></strong>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (!empty($invoice['payment_terms']) || !empty($invoice['notes'])): ?>
                <section class="iv-card">
                    <div class="iv-card-head">
                        <div>
                            <h2>Terms and Notes</h2>
                            <p>Payment instructions and additional information.</p>
                        </div>
                    </div>

                    <div class="iv-card-body">
                        <div class="iv-notes-grid">
                            <div class="iv-note-box">
                                <div class="iv-note-label">Payment Terms</div>
                                <div class="iv-note-text"><?= e(
                                    !empty($invoice['payment_terms'])
                                        ? $invoice['payment_terms']
                                        : 'No payment terms were provided.'
                                ); ?></div>
                            </div>

                            <div class="iv-note-box">
                                <div class="iv-note-label">Notes</div>
                                <div class="iv-note-text"><?= e(
                                    !empty($invoice['notes'])
                                        ? $invoice['notes']
                                        : 'No additional notes were provided.'
                                ); ?></div>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="iv-card iv-payment-card">
                <div class="iv-card-head">
                    <div>
                        <h2>Payment History</h2>
                        <p>All payment attempts linked directly to this invoice.</p>
                    </div>

                    <?php if ($canPayInvoice): ?>
                        <a
                            href="payment-add.php?invoice_id=<?= $invoiceId; ?>"
                            class="iv-btn payment"
                        >
                            <i class="bi bi-plus-lg"></i>
                            Record Payment
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($payments)): ?>
                    <div class="iv-table-wrap">
                        <table class="iv-table iv-payment-table">
                            <thead>
                                <tr>
                                    <th>Payment</th>
                                    <th>Date</th>
                                    <th>Method</th>
                                    <th>Channel</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th class="wrap">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td>
                                            <span class="iv-item-name">
                                                <?= e($payment['payment_no']); ?>
                                            </span>
                                            <?php if (!empty($payment['received_by_name'])): ?>
                                                <div class="iv-item-meta">
                                                    By <?= e($payment['received_by_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= e(invoiceViewDateTime(
                                                !empty($payment['received_at'])
                                                    ? $payment['received_at']
                                                    : $payment['created_at']
                                            )); ?>
                                        </td>
                                        <td><?= e(invoiceViewLabel($payment['payment_method'])); ?></td>
                                        <td><?= e(invoiceViewLabel($payment['payment_channel'])); ?></td>
                                        <td>
                                            <span class="iv-status <?= e(invoiceViewStatusClass($payment['status'])); ?>">
                                                <?= e(invoiceViewLabel($payment['status'])); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right;">
                                            <span class="iv-money">
                                                <?= e(invoiceViewMoney(
                                                    $payment['amount'],
                                                    !empty($payment['currency_code'])
                                                        ? $payment['currency_code']
                                                        : $currencyCode
                                                )); ?>
                                            </span>
                                        </td>
                                        <td class="wrap">
                                            <?php if (!empty($payment['provider'])): ?>
                                                <div><?= e($payment['provider']); ?></div>
                                            <?php endif; ?>

                                            <?php if (!empty($payment['provider_payment_id'])): ?>
                                                <div class="iv-item-meta">
                                                    Ref: <?= e($payment['provider_payment_id']); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($payment['notes'])): ?>
                                                <div class="iv-item-meta">
                                                    <?= e($payment['notes']); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($payment['failure_reason'])): ?>
                                                <div class="iv-item-meta" style="color:#b91c1c;">
                                                    <?= e($payment['failure_reason']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="iv-empty">
                        No payments are linked to this invoice.
                    </div>
                <?php endif; ?>
            </section>
        </main>

        <aside class="iv-print-hide">
            <section class="iv-card">
                <div class="iv-card-head">
                    <div>
                        <h2>Invoice Summary</h2>
                        <p>Current status and balance information.</p>
                    </div>
                </div>

                <div class="iv-card-body">
                    <div class="iv-side-summary">
                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Status</div>
                            <span class="iv-summary-value">
                                <span class="iv-status <?= e(invoiceViewStatusClass($displayStatus)); ?>">
                                    <?= e(invoiceViewLabel($displayStatus)); ?>
                                </span>
                            </span>
                        </div>

                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Invoice Total</div>
                            <span class="iv-summary-value">
                                <?= e(invoiceViewMoney($invoice['total'], $currencyCode)); ?>
                            </span>
                        </div>

                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Amount Paid</div>
                            <span class="iv-summary-value" style="color:#047857;">
                                <?= e(invoiceViewMoney($invoice['amount_paid'], $currencyCode)); ?>
                            </span>
                        </div>

                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Balance Due</div>
                            <span class="iv-summary-value" style="color:<?= $isOverdue ? '#b91c1c' : '#1d4ed8'; ?>;">
                                <?= e(invoiceViewMoney($invoice['balance_due'], $currencyCode)); ?>
                            </span>
                        </div>

                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Created By</div>
                            <span class="iv-summary-value">
                                <?= e(
                                    trim((string) $invoice['created_by_name']) !== ''
                                        ? $invoice['created_by_name']
                                        : 'System'
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (
                !empty($invoice['job_id']) ||
                !empty($invoice['visit_id']) ||
                !empty($invoice['quote_id']) ||
                !empty($invoice['property_id'])
            ): ?>
                <section class="iv-card">
                    <div class="iv-card-head">
                        <div>
                            <h2>Related Records</h2>
                            <p>Open records connected to this invoice.</p>
                        </div>
                    </div>

                    <div class="iv-card-body">
                        <div class="iv-related-list">
                            <?php if (!empty($invoice['job_id'])): ?>
                                <a
                                    href="job-view.php?id=<?= (int) $invoice['job_id']; ?>"
                                    class="iv-related-link"
                                >
                                    <span>
                                        <i class="bi bi-briefcase"></i>
                                        <?= e($invoice['job_no']); ?>
                                        · <?= e($invoice['job_title']); ?>
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($invoice['visit_id'])): ?>
                                <a
                                    href="visit-view.php?id=<?= (int) $invoice['visit_id']; ?>"
                                    class="iv-related-link"
                                >
                                    <span>
                                        <i class="bi bi-geo-alt"></i>
                                        <?= e(
                                            !empty($invoice['visit_no'])
                                                ? $invoice['visit_no']
                                                : 'Visit #' . $invoice['visit_id']
                                        ); ?>
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($invoice['quote_id'])): ?>
                                <a
                                    href="quote-view.php?id=<?= (int) $invoice['quote_id']; ?>"
                                    class="iv-related-link"
                                >
                                    <span>
                                        <i class="bi bi-file-earmark-text"></i>
                                        <?= e($invoice['quote_no']); ?>
                                        <?= !empty($invoice['quote_title']) ? ' · ' . e($invoice['quote_title']) : ''; ?>
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php endif; ?>

                            <?php if (!empty($invoice['property_id'])): ?>
                                <a
                                    href="property-view.php?id=<?= (int) $invoice['property_id']; ?>"
                                    class="iv-related-link"
                                >
                                    <span>
                                        <i class="bi bi-house-door"></i>
                                        <?= e(
                                            !empty($invoice['property_name'])
                                                ? $invoice['property_name']
                                                : 'Property #' . $invoice['property_id']
                                        ); ?>
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="iv-card">
                <div class="iv-card-head">
                    <div>
                        <h2>Quick Actions</h2>
                        <p>Available actions depend on status and permissions.</p>
                    </div>
                </div>

                <div class="iv-card-body">
                    <div class="iv-action-list">
                        <button
                            type="button"
                            class="iv-side-action"
                            onclick="window.print();"
                        >
                            <i class="bi bi-printer"></i>
                            Print Invoice
                        </button>

                        <?php if ($canEditInvoice): ?>
                            <a
                                href="invoice-edit.php?id=<?= $invoiceId; ?>"
                                class="iv-side-action"
                            >
                                <i class="bi bi-pencil"></i>
                                Edit Invoice
                            </a>
                        <?php endif; ?>

                        <?php if ($canPayInvoice): ?>
                            <a
                                href="payment-add.php?invoice_id=<?= $invoiceId; ?>"
                                class="iv-side-action payment"
                            >
                                <i class="bi bi-cash-coin"></i>
                                Record Payment
                            </a>
                        <?php endif; ?>

                        <?php if ($canMarkSent): ?>
                            <form method="post" class="iv-side-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                <input type="hidden" name="action" value="mark_sent">
                                <button
                                    type="submit"
                                    onclick="return confirm('Mark this invoice as sent or issued?');"
                                >
                                    <i class="bi bi-send"></i>
                                    Mark as Sent
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canWriteOff): ?>
                            <form method="post" class="iv-side-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                <input type="hidden" name="action" value="write_off_invoice">
                                <button
                                    type="submit"
                                    class="warning"
                                    onclick="return confirm('Write off the complete remaining balance? This action cannot be reversed from this page.');"
                                >
                                    <i class="bi bi-slash-circle"></i>
                                    Write Off Balance
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canCancelInvoice): ?>
                            <form method="post" class="iv-side-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                <input type="hidden" name="action" value="cancel_invoice">
                                <button
                                    type="submit"
                                    class="danger"
                                    onclick="return confirm('Cancel this invoice? An invoice with a recorded payment cannot be cancelled.');"
                                >
                                    <i class="bi bi-x-circle"></i>
                                    Cancel Invoice
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="iv-card">
                <div class="iv-card-head">
                    <div>
                        <h2>Timeline</h2>
                        <p>Important invoice timestamps.</p>
                    </div>
                </div>

                <div class="iv-card-body">
                    <div class="iv-side-summary">
                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Created</div>
                            <span class="iv-summary-value">
                                <?= e(invoiceViewDateTime($invoice['created_at'])); ?>
                            </span>
                        </div>

                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Sent</div>
                            <span class="iv-summary-value">
                                <?= e(invoiceViewDateTime($invoice['sent_at'])); ?>
                            </span>
                        </div>

                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Viewed</div>
                            <span class="iv-summary-value">
                                <?= e(invoiceViewDateTime($invoice['viewed_at'])); ?>
                            </span>
                        </div>

                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Paid</div>
                            <span class="iv-summary-value">
                                <?= e(invoiceViewDateTime($invoice['paid_at'])); ?>
                            </span>
                        </div>

                        <div class="iv-summary-item">
                            <div class="iv-summary-label">Last Updated</div>
                            <span class="iv-summary-value">
                                <?= e(invoiceViewDateTime($invoice['updated_at'])); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
