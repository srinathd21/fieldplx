<?php
/**
 * FieldPlx - Record Invoice Payment
 *
 * Upload as:
 * /public_html/payment-add.php
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
    $redirect = 'payment-add.php';

    if (!empty($_GET['invoice_id'])) {
        $redirect .=
            '?invoice_id=' .
            (int) $_GET['invoice_id'];
    }

    header(
        'Location: login.php?redirect=' .
        rawurlencode($redirect)
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'payments.manage',
        'You do not have permission to record payments.'
    );
}

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$invoiceId = isset($_GET['invoice_id'])
    ? (int) $_GET['invoice_id']
    : (
        isset($_POST['invoice_id'])
            ? (int) $_POST['invoice_id']
            : 0
    );

if ($invoiceId <= 0) {
    header('Location: invoices.php');
    exit;
}

$pageTitle = 'Record Payment - FieldPlx';
$activePage = 'invoices';
$searchPlaceholder = 'Search invoices...';
$basePath = '';

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('paymentAddFetchAssoc')) {
    function paymentAddFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('paymentAddFetchAll')) {
    function paymentAddFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('paymentAddOld')) {
    function paymentAddOld($key, $default = '')
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

if (!function_exists('paymentAddNullable')) {
    function paymentAddNullable($value)
    {
        $value = trim((string) $value);

        return $value === ''
            ? null
            : $value;
    }
}

if (!function_exists('paymentAddCsrfToken')) {
    function paymentAddCsrfToken()
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

if (!function_exists('paymentAddVerifyCsrf')) {
    function paymentAddVerifyCsrf($token)
    {
        return !empty($_SESSION['csrf_token']) &&
            is_string($token) &&
            hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            );
    }
}

if (!function_exists('paymentAddDateTimeInput')) {
    function paymentAddDateTimeInput($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return date(
            'Y-m-d H:i:s',
            $timestamp
        );
    }
}

if (!function_exists('paymentAddMoney')) {
    function paymentAddMoney(
        $amount,
        $currencyCode
    ) {
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

        $prefix = isset(
            $prefixes[$currencyCode]
        )
            ? $prefixes[$currencyCode]
            : $currencyCode . ' ';

        return $prefix .
            number_format(
                (float) $amount,
                2
            );
    }
}

if (!function_exists('paymentAddDate')) {
    function paymentAddDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp =
            strtotime((string) $value);

        return $timestamp
            ? date('d M Y', $timestamp)
            : '—';
    }
}

if (!function_exists('paymentAddLabel')) {
    function paymentAddLabel($value)
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

if (!function_exists('paymentAddSequencePeriod')) {
    function paymentAddSequencePeriod(
        $frequency
    ) {
        if ($frequency === 'yearly') {
            return date('Y');
        }

        if ($frequency === 'monthly') {
            return date('Y-m');
        }

        return null;
    }
}

if (!function_exists('paymentAddGenerateNumber')) {
    function paymentAddGenerateNumber(
        mysqli $conn,
        $tenantId
    ) {
        /*
         * Must be called inside the payment transaction.
         * FOR UPDATE prevents duplicate payment numbers.
         */

        $prefix = 'PAY';
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
              AND document_type = 'payment'
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            throw new Exception(
                'Unable to load the payment number sequence.'
            );
        }

        $stmt->bind_param(
            'i',
            $tenantId
        );

        if (!$stmt->execute()) {
            throw new Exception(
                'Unable to read the payment number sequence.'
            );
        }

        $sequence =
            paymentAddFetchAssoc($stmt);

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
                    : 'PAY';

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
                paymentAddSequencePeriod(
                    $frequency
                );

            $lastPeriod =
                trim(
                    (string) $sequence[
                        'last_reset_period'
                    ]
                );

            if (
                $currentPeriod !== null &&
                $currentPeriod !== $lastPeriod
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
                    'Unable to prepare the payment sequence update.'
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
                    'Unable to update the payment number sequence.'
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
                    'payment',
                    'PAY',
                    ?,
                    ?,
                    'never',
                    NULL,
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare the payment sequence creation.'
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
                    'Unable to create the payment number sequence.'
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

if (!function_exists('paymentAddLoadInvoice')) {
    function paymentAddLoadInvoice(
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
                i.created_at,

                c.display_name AS client_name,
                c.company_name AS client_company,
                c.email AS client_email,
                c.phone AS client_phone,
                c.tax_number AS client_tax_number,

                p.name AS property_name,
                p.address_line1 AS property_address1,
                p.address_line2 AS property_address2,
                p.city AS property_city,
                p.state AS property_state,
                p.postal_code AS property_postal_code,
                p.country AS property_country,

                j.job_no,
                j.title AS job_title,

                q.quote_no,

                t.company_name AS tenant_name,
                t.currency_code

            FROM invoices i

            INNER JOIN clients c
                ON c.id = i.client_id
               AND c.tenant_id = i.tenant_id
               AND c.deleted_at IS NULL

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

            LEFT JOIN quotes q
                ON q.id = i.quote_id
               AND q.tenant_id = i.tenant_id
               AND q.archived_at IS NULL

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
            paymentAddFetchAssoc($stmt);

        $stmt->close();

        return $invoice;
    }
}

if (!function_exists('paymentAddLogActivity')) {
    function paymentAddLogActivity(
        mysqli $conn,
        $tenantId,
        $userId,
        $paymentId,
        $invoiceId,
        $clientId,
        $paymentNo,
        $invoiceNo,
        $amount,
        $currencyCode,
        $paymentStatus,
        $invoiceStatus
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
                    'payment',
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

            $eventType =
                $paymentStatus === 'succeeded'
                    ? 'payment_received'
                    : 'payment_recorded';

            $title =
                (
                    $paymentStatus ===
                    'succeeded'
                        ? 'Payment received: '
                        : 'Payment recorded: '
                ) .
                $paymentNo;

            $details = json_encode(
                array(
                    'payment_id' =>
                        (int) $paymentId,
                    'payment_no' =>
                        (string) $paymentNo,
                    'invoice_id' =>
                        (int) $invoiceId,
                    'invoice_no' =>
                        (string) $invoiceNo,
                    'amount' =>
                        (float) $amount,
                    'currency_code' =>
                        (string) $currencyCode,
                    'payment_status' =>
                        (string) $paymentStatus,
                    'invoice_status' =>
                        (string) $invoiceStatus
                ),
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            $stmt->bind_param(
                'iisiiss',
                $tenantId,
                $userId,
                $eventType,
                $paymentId,
                $clientId,
                $title,
                $details
            );

            $stmt->execute();
            $stmt->close();
        } catch (Throwable $error) {
            error_log(
                'Payment activity log failed: ' .
                $error->getMessage()
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load invoice
|--------------------------------------------------------------------------
*/

$invoice =
    paymentAddLoadInvoice(
        $conn,
        $tenantId,
        $invoiceId,
        false
    );

if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found or access denied.');
}

$currencyCode =
    trim(
        (string) $invoice['currency_code']
    ) !== ''
        ? strtoupper(
            trim(
                (string) $invoice[
                    'currency_code'
                ]
            )
        )
        : 'INR';

$eligibleInvoiceStatuses = array(
    'sent',
    'viewed',
    'partially_paid',
    'overdue'
);

$invoiceCanReceivePayment =
    (float) $invoice['balance_due'] > 0.005 &&
    in_array(
        $invoice['status'],
        $eligibleInvoiceStatuses,
        true
    );

/*
|--------------------------------------------------------------------------
| Saved client payment methods
|--------------------------------------------------------------------------
*/

$savedPaymentMethods = array();

$stmt = $conn->prepare("
    SELECT
        id,
        provider,
        provider_payment_method_id,
        method_type,
        brand,
        last4,
        expiry_month,
        expiry_year,
        is_default,
        authorization_status
    FROM payment_methods
    WHERE tenant_id = ?
      AND client_id = ?
      AND status = 'active'
      AND authorization_status = 'authorized'
    ORDER BY
        is_default DESC,
        id DESC
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $invoice['client_id']
    );

    if ($stmt->execute()) {
        $savedPaymentMethods =
            paymentAddFetchAll($stmt);
    }

    $stmt->close();
}

$savedPaymentMethodMap = array();

foreach (
    $savedPaymentMethods as
    $savedMethod
) {
    $savedPaymentMethodMap[
        (int) $savedMethod['id']
    ] = $savedMethod;
}

/*
|--------------------------------------------------------------------------
| Recent invoice payment information
|--------------------------------------------------------------------------
*/

$paymentStats = array(
    'payment_count' => 0,
    'successful_total' => 0.00,
    'last_payment_at' => null
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS payment_count,
        COALESCE(
            SUM(
                CASE
                    WHEN status = 'succeeded'
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS successful_total,
        MAX(
            CASE
                WHEN status = 'succeeded'
                THEN COALESCE(
                    received_at,
                    created_at
                )
                ELSE NULL
            END
        ) AS last_payment_at
    FROM payments
    WHERE tenant_id = ?
      AND invoice_id = ?
");

if ($stmt) {
    $stmt->bind_param(
        'ii',
        $tenantId,
        $invoiceId
    );

    if ($stmt->execute()) {
        $row =
            paymentAddFetchAssoc($stmt);

        if ($row) {
            $paymentStats = $row;
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Save payment
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !paymentAddVerifyCsrf(
            paymentAddOld('csrf_token')
        )
    ) {
        $errors[] =
            'Your session token is invalid. Refresh the page and try again.';
    }

    $amountRaw =
        paymentAddOld('amount');

    $transactionFeeRaw =
        paymentAddOld(
            'transaction_fee',
            '0'
        );

    $paymentMethod =
        paymentAddOld(
            'payment_method',
            'cash'
        );

    $paymentChannel =
        paymentAddOld(
            'payment_channel',
            'office'
        );

    $paymentStatus =
        paymentAddOld(
            'status',
            'succeeded'
        );

    $savedPaymentMethodId =
        (int) paymentAddOld(
            'payment_method_id',
            '0'
        );

    if ($savedPaymentMethodId <= 0) {
        $savedPaymentMethodId = null;
    }

    $provider =
        paymentAddOld('provider');

    $providerPaymentId =
        paymentAddOld(
            'provider_payment_id'
        );

    $receivedAtInput =
        paymentAddOld(
            'received_at'
        );

    $failureReason =
        paymentAddOld(
            'failure_reason'
        );

    $notes =
        paymentAddOld('notes');

    $allowedMethods = array(
        'cash',
        'card',
        'bank',
        'upi',
        'cheque',
        'wallet',
        'other'
    );

    $allowedChannels = array(
        'online',
        'client_portal',
        'mobile',
        'office',
        'tap_to_pay',
        'manual'
    );

    $allowedStatuses = array(
        'pending',
        'authorized',
        'succeeded',
        'failed'
    );

    if (
        !is_numeric($amountRaw) ||
        (float) $amountRaw <= 0
    ) {
        $errors[] =
            'Payment amount must be greater than zero.';
    }

    if (
        !is_numeric(
            $transactionFeeRaw
        ) ||
        (float) $transactionFeeRaw < 0
    ) {
        $errors[] =
            'Transaction fee must be zero or a positive amount.';
    }

    if (
        !in_array(
            $paymentMethod,
            $allowedMethods,
            true
        )
    ) {
        $errors[] =
            'Please select a valid payment method.';
    }

    if (
        !in_array(
            $paymentChannel,
            $allowedChannels,
            true
        )
    ) {
        $errors[] =
            'Please select a valid payment channel.';
    }

    if (
        !in_array(
            $paymentStatus,
            $allowedStatuses,
            true
        )
    ) {
        $errors[] =
            'Please select a valid payment status.';
    }

    $receivedAt =
        paymentAddDateTimeInput(
            $receivedAtInput
        );

    if (
        $receivedAtInput !== '' &&
        $receivedAt === null
    ) {
        $errors[] =
            'Please enter a valid received date and time.';
    }

    if (
        $paymentStatus === 'succeeded' &&
        $receivedAt === null
    ) {
        $receivedAt =
            date('Y-m-d H:i:s');
    }

    if (
        $paymentStatus === 'failed' &&
        $failureReason === ''
    ) {
        $errors[] =
            'Enter the reason why the payment failed.';
    }

    if (
        strlen($provider) > 120
    ) {
        $errors[] =
            'Provider cannot exceed 120 characters.';
    }

    if (
        strlen($providerPaymentId) > 190
    ) {
        $errors[] =
            'Transaction or reference ID cannot exceed 190 characters.';
    }

    if (
        $savedPaymentMethodId !== null &&
        !isset(
            $savedPaymentMethodMap[
                $savedPaymentMethodId
            ]
        )
    ) {
        $errors[] =
            'The selected saved payment method is not available for this client.';
    }

    $amount =
        round(
            (float) $amountRaw,
            2
        );

    $transactionFee =
        round(
            (float) $transactionFeeRaw,
            2
        );

    if (
        $paymentStatus === 'succeeded' &&
        !$invoiceCanReceivePayment
    ) {
        $errors[] =
            'This invoice cannot receive a successful payment in its current status.';
    }

    if (
        $paymentStatus === 'succeeded' &&
        $amount >
            (
                (float) $invoice[
                    'balance_due'
                ] + 0.005
            )
    ) {
        $errors[] =
            'Payment amount cannot exceed the current invoice balance.';
    }

    if (empty($errors)) {
        $providerValue =
            paymentAddNullable($provider);

        $providerPaymentIdValue =
            paymentAddNullable(
                $providerPaymentId
            );

        $failureReasonValue =
            $paymentStatus === 'failed'
                ? paymentAddNullable(
                    $failureReason
                )
                : null;

        $notesValue =
            paymentAddNullable($notes);

        try {
            $conn->begin_transaction();

            /*
             * Lock the invoice and validate it again to protect against
             * concurrent payments from another browser or user.
             */
            $lockedInvoice =
                paymentAddLoadInvoice(
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

            $lockedBalance =
                round(
                    (float) $lockedInvoice[
                        'balance_due'
                    ],
                    2
                );

            $lockedAmountPaid =
                round(
                    (float) $lockedInvoice[
                        'amount_paid'
                    ],
                    2
                );

            $lockedTotal =
                round(
                    (float) $lockedInvoice[
                        'total'
                    ],
                    2
                );

            if (
                $paymentStatus ===
                    'succeeded'
            ) {
                if (
                    !in_array(
                        $lockedInvoice['status'],
                        $eligibleInvoiceStatuses,
                        true
                    )
                ) {
                    throw new Exception(
                        'This invoice is no longer eligible to receive a payment.'
                    );
                }

                if ($lockedBalance <= 0.005) {
                    throw new Exception(
                        'This invoice no longer has an outstanding balance.'
                    );
                }

                if (
                    $amount >
                    ($lockedBalance + 0.005)
                ) {
                    throw new Exception(
                        'Payment amount exceeds the latest invoice balance of ' .
                        paymentAddMoney(
                            $lockedBalance,
                            $currencyCode
                        ) .
                        '.'
                    );
                }
            }

            if (
                $savedPaymentMethodId !==
                    null
            ) {
                $stmt = $conn->prepare("
                    SELECT
                        id,
                        provider,
                        method_type
                    FROM payment_methods
                    WHERE id = ?
                      AND tenant_id = ?
                      AND client_id = ?
                      AND status = 'active'
                      AND authorization_status = 'authorized'
                    LIMIT 1
                    FOR UPDATE
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to validate the saved payment method.'
                    );
                }

                $stmt->bind_param(
                    'iii',
                    $savedPaymentMethodId,
                    $tenantId,
                    $lockedInvoice[
                        'client_id'
                    ]
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to validate the saved payment method.'
                    );
                }

                $lockedMethod =
                    paymentAddFetchAssoc(
                        $stmt
                    );

                $stmt->close();

                if (!$lockedMethod) {
                    throw new Exception(
                        'The saved payment method is no longer available.'
                    );
                }

                if (
                    $providerValue === null &&
                    !empty(
                        $lockedMethod['provider']
                    )
                ) {
                    $providerValue =
                        (string) $lockedMethod[
                            'provider'
                        ];
                }

                $savedMethodType =
                    (string) $lockedMethod[
                        'method_type'
                    ];

                if (
                    in_array(
                        $savedMethodType,
                        array(
                            'card',
                            'bank',
                            'wallet'
                        ),
                        true
                    )
                ) {
                    $paymentMethod =
                        $savedMethodType;
                }
            }

            $paymentNo =
                paymentAddGenerateNumber(
                    $conn,
                    $tenantId
                );

            $clientId =
                (int) $lockedInvoice[
                    'client_id'
                ];

            $quoteId =
                !empty(
                    $lockedInvoice['quote_id']
                )
                    ? (int) $lockedInvoice[
                        'quote_id'
                    ]
                    : null;

            $stmt = $conn->prepare("
                INSERT INTO payments (
                    tenant_id,
                    payment_no,
                    client_id,
                    invoice_id,
                    quote_id,
                    payment_method_id,
                    provider,
                    provider_payment_id,
                    payment_method,
                    payment_channel,
                    status,
                    amount,
                    currency_code,
                    transaction_fee,
                    received_at,
                    failure_reason,
                    notes,
                    created_by,
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
                    'Unable to prepare the payment save operation: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'isiiiisssssdsdsssi',
                $tenantId,
                $paymentNo,
                $clientId,
                $invoiceId,
                $quoteId,
                $savedPaymentMethodId,
                $providerValue,
                $providerPaymentIdValue,
                $paymentMethod,
                $paymentChannel,
                $paymentStatus,
                $amount,
                $currencyCode,
                $transactionFee,
                $receivedAt,
                $failureReasonValue,
                $notesValue,
                $currentUserId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Payment could not be saved: ' .
                    $stmt->error
                );
            }

            $paymentId =
                (int) $stmt->insert_id;

            $stmt->close();

            $newInvoiceStatus =
                (string) $lockedInvoice[
                    'status'
                ];

            $newAmountPaid =
                $lockedAmountPaid;

            $newBalance =
                $lockedBalance;

            $paidAt = null;

            if (
                $paymentStatus ===
                    'succeeded'
            ) {
                $newAmountPaid =
                    round(
                        $lockedAmountPaid +
                        $amount,
                        2
                    );

                if (
                    $newAmountPaid >
                    $lockedTotal
                ) {
                    $newAmountPaid =
                        $lockedTotal;
                }

                $newBalance =
                    round(
                        max(
                            0,
                            $lockedTotal -
                            $newAmountPaid
                        ),
                        2
                    );

                if ($newBalance <= 0.005) {
                    $newBalance = 0.00;
                    $newInvoiceStatus =
                        'paid';

                    $paidAt =
                        $receivedAt !== null
                            ? $receivedAt
                            : date(
                                'Y-m-d H:i:s'
                            );
                } else {
                    $newInvoiceStatus =
                        'partially_paid';
                }

                $stmt = $conn->prepare("
                    UPDATE invoices
                    SET
                        amount_paid = ?,
                        balance_due = ?,
                        status = ?,
                        paid_at = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND tenant_id = ?
                      AND archived_at IS NULL
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare the invoice balance update.'
                    );
                }

                $stmt->bind_param(
                    'ddssii',
                    $newAmountPaid,
                    $newBalance,
                    $newInvoiceStatus,
                    $paidAt,
                    $invoiceId,
                    $tenantId
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        'Unable to update the invoice balance: ' .
                        $stmt->error
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            paymentAddLogActivity(
                $conn,
                $tenantId,
                $currentUserId,
                $paymentId,
                $invoiceId,
                $clientId,
                $paymentNo,
                $lockedInvoice[
                    'invoice_no'
                ],
                $amount,
                $currencyCode,
                $paymentStatus,
                $newInvoiceStatus
            );

            if (
                $paymentStatus ===
                    'succeeded'
            ) {
                $_SESSION['flash_success'] =
                    $newInvoiceStatus ===
                        'paid'
                        ? 'Payment recorded successfully. The invoice is now fully paid.'
                        : 'Partial payment recorded successfully.';
            } else {
                $_SESSION['flash_success'] =
                    'Payment attempt recorded successfully.';
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

            $errors[] =
                $error->getMessage();

            error_log(
                'Payment creation failed: ' .
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

$defaultAmount =
    number_format(
        (float) $invoice[
            'balance_due'
        ],
        2,
        '.',
        ''
    );

$selectedStatus =
    paymentAddOld(
        'status',
        'succeeded'
    );

$selectedMethod =
    paymentAddOld(
        'payment_method',
        'cash'
    );

$selectedChannel =
    paymentAddOld(
        'payment_channel',
        'office'
    );

$selectedSavedMethodId =
    (int) paymentAddOld(
        'payment_method_id',
        '0'
    );

$csrfToken =
    paymentAddCsrfToken();

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.payment-add-page {
    --pa-primary: #6d28d9;
    --pa-primary-dark: #4c1d95;
    --pa-text: #111827;
    --pa-muted: #6b7280;
    --pa-border: #e5e7eb;
    --pa-green: #047857;
}

.pa-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.pa-header-main {
    display: flex;
    align-items: center;
    gap: 11px;
}

.pa-header-icon {
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
            #059669,
            #047857
        );
    color: #fff;
    font-size: 18px;
    box-shadow:
        0 10px 22px
        rgba(5,150,105,.2);
}

.pa-header h1 {
    margin: 0;
    color: var(--pa-text);
    font-size: 21px;
    font-weight: 800;
}

.pa-header p {
    margin: 5px 0 0;
    color: var(--pa-muted);
    font-size: 10px;
}

.pa-back {
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid var(--pa-border);
    border-radius: 9px;
    background: #fff;
    color: #374151;
    font-size: 9px;
    font-weight: 800;
    text-decoration: none;
}

.pa-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
    line-height: 1.55;
}

.pa-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.pa-alert.warning {
    border: 1px solid #fed7aa;
    background: #fff7ed;
    color: #c2410c;
}

.pa-alert ul {
    margin: 0;
    padding-left: 18px;
}

.pa-layout {
    display: grid;
    grid-template-columns:
        minmax(0,1.35fr)
        minmax(310px,.66fr);
    gap: 13px;
    align-items: start;
}

.pa-card {
    overflow: hidden;
    border: 1px solid var(--pa-border);
    border-radius: 13px;
    background: #fff;
    box-shadow:
        0 6px 20px
        rgba(15,23,42,.04);
}

.pa-card + .pa-card {
    margin-top: 13px;
}

.pa-card-head {
    min-height: 49px;
    padding: 11px 14px;
    border-bottom: 1px solid #eef0f4;
}

.pa-card-head h2 {
    margin: 0;
    color: var(--pa-text);
    font-size: 11px;
    font-weight: 800;
}

.pa-card-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.pa-card-body {
    padding: 14px;
}

.pa-grid {
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 11px;
}

.pa-field {
    min-width: 0;
}

.pa-field.full {
    grid-column: 1 / -1;
}

.pa-label {
    margin-bottom: 5px;
    display: block;
    color: #374151;
    font-size: 9px;
    font-weight: 800;
}

.pa-required {
    color: #dc2626;
}

.pa-input,
.pa-select,
.pa-textarea {
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

.pa-textarea {
    min-height: 92px;
    resize: vertical;
}

.pa-input:focus,
.pa-select:focus,
.pa-textarea:focus {
    border-color: #8b5cf6;
    box-shadow:
        0 0 0 3px
        rgba(139,92,246,.1);
}

.pa-input:disabled,
.pa-select:disabled,
.pa-textarea:disabled {
    cursor: not-allowed;
    background: #f9fafb;
    color: #9ca3af;
}

.pa-help {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.45;
}

.pa-amount-wrap {
    position: relative;
}

.pa-currency {
    position: absolute;
    top: 50%;
    left: 11px;
    transform: translateY(-50%);
    color: #6b7280;
    font-size: 9px;
    font-weight: 800;
    pointer-events: none;
}

.pa-amount {
    padding-left: 46px;
    color: #047857;
    font-size: 16px;
    font-weight: 800;
}

.pa-status-note {
    margin-top: 6px;
    padding: 8px 10px;
    border: 1px solid #dbeafe;
    border-radius: 8px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 8px;
    line-height: 1.5;
}

.pa-provider-fields,
.pa-failure-field {
    display: none;
}

.pa-provider-fields.visible,
.pa-failure-field.visible {
    display: contents;
}

.pa-invoice-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.pa-invoice-no {
    color: #111827;
    font-size: 13px;
    font-weight: 800;
}

.pa-status {
    padding: 4px 7px;
    display: inline-flex;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 800;
}

.pa-status.sent,
.pa-status.viewed {
    background: #eff6ff;
    color: #1d4ed8;
}

.pa-status.partially_paid {
    background: #fff7ed;
    color: #c2410c;
}

.pa-status.paid {
    background: #ecfdf5;
    color: #047857;
}

.pa-status.overdue {
    background: #fef2f2;
    color: #b91c1c;
}

.pa-client {
    margin-top: 12px;
    padding: 11px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
}

.pa-client-name {
    color: #111827;
    font-size: 10px;
    font-weight: 800;
}

.pa-client-meta {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.55;
    overflow-wrap: anywhere;
}

.pa-summary {
    margin-top: 11px;
    display: grid;
    gap: 8px;
}

.pa-summary-row {
    padding: 9px 10px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid #edf0f5;
    border-radius: 9px;
    background: #fafafa;
    color: #6b7280;
    font-size: 9px;
}

.pa-summary-row strong {
    color: #111827;
    text-align: right;
}

.pa-summary-row.paid strong {
    color: #047857;
}

.pa-summary-row.balance {
    border-color: #fed7aa;
    background: #fff7ed;
    color: #c2410c;
    font-size: 10px;
    font-weight: 800;
}

.pa-summary-row.balance strong {
    color: #c2410c;
}

.pa-summary-row.after {
    border-color: #bbf7d0;
    background: #f0fdf4;
    color: #047857;
    font-size: 10px;
    font-weight: 800;
}

.pa-summary-row.after strong {
    color: #047857;
}

.pa-related {
    margin-top: 11px;
    display: grid;
    grid-template-columns:
        repeat(2,minmax(0,1fr));
    gap: 8px;
}

.pa-related-item {
    padding: 9px;
    border: 1px solid #edf0f5;
    border-radius: 8px;
    background: #fff;
}

.pa-related-label {
    color: #9ca3af;
    font-size: 7px;
    font-weight: 800;
    text-transform: uppercase;
}

.pa-related-value {
    margin-top: 4px;
    display: block;
    color: #111827;
    font-size: 8px;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.pa-actions {
    padding: 12px 14px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #eef0f4;
    background: #fafafa;
}

.pa-btn {
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

.pa-btn.secondary {
    border: 1px solid var(--pa-border);
    background: #fff;
    color: #374151;
}

.pa-btn.primary {
    border: 0;
    background:
        linear-gradient(
            135deg,
            #059669,
            #047857
        );
    color: #fff;
}

.pa-btn:disabled {
    cursor: not-allowed;
    opacity: .5;
}

@media (max-width: 1050px) {
    .pa-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .pa-header {
        flex-direction: column;
    }

    .pa-grid,
    .pa-related {
        grid-template-columns: 1fr;
    }

    .pa-field.full {
        grid-column: auto;
    }

    .pa-actions {
        flex-direction: column-reverse;
    }

    .pa-btn {
        width: 100%;
    }
}
</style>

<div class="payment-add-page">
    <div class="pa-header">
        <div class="pa-header-main">
            <div class="pa-header-icon">
                <i class="bi bi-cash-coin"></i>
            </div>

            <div>
                <h1>Record Payment</h1>
                <p>
                    Record a payment against invoice <?= e(
                        $invoice['invoice_no']
                    ); ?> and update its outstanding balance.
                </p>
            </div>
        </div>

        <a
            href="invoice-view.php?id=<?= $invoiceId; ?>"
            class="pa-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Invoice
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="pa-alert error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$invoiceCanReceivePayment): ?>
        <div class="pa-alert warning">
            This invoice cannot receive a successful payment because its
            current status is
            <strong><?= e(
                paymentAddLabel(
                    $invoice['status']
                )
            ); ?></strong>
            or it has no remaining balance. Pending or failed attempts may
            still be recorded where appropriate.
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="payment-add.php?invoice_id=<?= $invoiceId; ?>"
        autocomplete="off"
        id="paymentAddForm"
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

        <div class="pa-layout">
            <main>
                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Payment Information</h2>
                        <p>
                            Enter the amount, method, channel, payment state, and received time.
                        </p>
                    </div>

                    <div class="pa-card-body">
                        <div class="pa-grid">
                            <div class="pa-field full">
                                <label class="pa-label">
                                    Payment Amount
                                    <span class="pa-required">*</span>
                                </label>

                                <div class="pa-amount-wrap">
                                    <span class="pa-currency">
                                        <?= e($currencyCode); ?>
                                    </span>

                                    <input
                                        type="number"
                                        name="amount"
                                        id="paymentAmount"
                                        class="pa-input pa-amount"
                                        min="0.01"
                                        max="<?= e(
                                            number_format(
                                                (float) $invoice[
                                                    'balance_due'
                                                ],
                                                2,
                                                '.',
                                                ''
                                            )
                                        ); ?>"
                                        step="0.01"
                                        value="<?= e(
                                            paymentAddOld(
                                                'amount',
                                                $defaultAmount
                                            )
                                        ); ?>"
                                        required
                                    >
                                </div>

                                <div class="pa-help">
                                    Maximum successful payment:
                                    <?= e(
                                        paymentAddMoney(
                                            $invoice[
                                                'balance_due'
                                            ],
                                            $currencyCode
                                        )
                                    ); ?>.
                                    Partial payments are supported.
                                </div>
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Payment Method
                                    <span class="pa-required">*</span>
                                </label>

                                <select
                                    name="payment_method"
                                    id="paymentMethod"
                                    class="pa-select"
                                    required
                                >
                                    <?php foreach (
                                        array(
                                            'cash' => 'Cash',
                                            'card' => 'Card',
                                            'bank' => 'Bank Transfer',
                                            'upi' => 'UPI',
                                            'cheque' => 'Cheque',
                                            'wallet' => 'Wallet',
                                            'other' => 'Other'
                                        ) as $value => $label
                                    ): ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= $selectedMethod ===
                                                $value
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Payment Channel
                                    <span class="pa-required">*</span>
                                </label>

                                <select
                                    name="payment_channel"
                                    id="paymentChannel"
                                    class="pa-select"
                                    required
                                >
                                    <?php foreach (
                                        array(
                                            'office' => 'Office',
                                            'manual' => 'Manual Entry',
                                            'mobile' => 'Mobile',
                                            'tap_to_pay' => 'Tap to Pay',
                                            'online' => 'Online',
                                            'client_portal' => 'Client Portal'
                                        ) as $value => $label
                                    ): ?>
                                        <option
                                            value="<?= e($value); ?>"
                                            <?= $selectedChannel ===
                                                $value
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Payment Status
                                    <span class="pa-required">*</span>
                                </label>

                                <select
                                    name="status"
                                    id="paymentStatus"
                                    class="pa-select"
                                    required
                                >
                                    <option
                                        value="succeeded"
                                        <?= $selectedStatus ===
                                            'succeeded'
                                                ? 'selected'
                                                : ''; ?>
                                        <?= !$invoiceCanReceivePayment
                                            ? 'disabled'
                                            : ''; ?>
                                    >
                                        Succeeded
                                    </option>

                                    <option
                                        value="pending"
                                        <?= $selectedStatus ===
                                            'pending'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        Pending
                                    </option>

                                    <option
                                        value="authorized"
                                        <?= $selectedStatus ===
                                            'authorized'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        Authorized
                                    </option>

                                    <option
                                        value="failed"
                                        <?= $selectedStatus ===
                                            'failed'
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        Failed
                                    </option>
                                </select>

                                <div
                                    class="pa-status-note"
                                    id="paymentStatusNote"
                                ></div>
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Received At
                                </label>

                                <input
                                    type="datetime-local"
                                    name="received_at"
                                    id="receivedAt"
                                    class="pa-input"
                                    value="<?= e(
                                        paymentAddOld(
                                            'received_at',
                                            date(
                                                'Y-m-d\TH:i'
                                            )
                                        )
                                    ); ?>"
                                >
                            </div>

                            <div class="pa-field">
                                <label class="pa-label">
                                    Transaction Fee
                                </label>

                                <div class="pa-amount-wrap">
                                    <span class="pa-currency">
                                        <?= e($currencyCode); ?>
                                    </span>

                                    <input
                                        type="number"
                                        name="transaction_fee"
                                        id="transactionFee"
                                        class="pa-input"
                                        min="0"
                                        step="0.01"
                                        value="<?= e(
                                            paymentAddOld(
                                                'transaction_fee',
                                                '0.00'
                                            )
                                        ); ?>"
                                        style="padding-left:46px;"
                                    >
                                </div>

                                <div class="pa-help">
                                    The fee is stored separately and does not reduce the invoice payment amount.
                                </div>
                            </div>

                            <?php if (
                                !empty(
                                    $savedPaymentMethods
                                )
                            ): ?>
                                <div class="pa-field full">
                                    <label class="pa-label">
                                        Saved Payment Method
                                    </label>

                                    <select
                                        name="payment_method_id"
                                        id="savedPaymentMethod"
                                        class="pa-select"
                                    >
                                        <option value="">
                                            Do not use a saved method
                                        </option>

                                        <?php foreach (
                                            $savedPaymentMethods as
                                            $savedMethod
                                        ): ?>
                                            <?php
                                            $savedLabel =
                                                paymentAddLabel(
                                                    $savedMethod[
                                                        'method_type'
                                                    ]
                                                );

                                            if (
                                                !empty(
                                                    $savedMethod[
                                                        'brand'
                                                    ]
                                                )
                                            ) {
                                                $savedLabel .=
                                                    ' · ' .
                                                    $savedMethod[
                                                        'brand'
                                                    ];
                                            }

                                            if (
                                                !empty(
                                                    $savedMethod[
                                                        'last4'
                                                    ]
                                                )
                                            ) {
                                                $savedLabel .=
                                                    ' ending ' .
                                                    $savedMethod[
                                                        'last4'
                                                    ];
                                            }

                                            if (
                                                !empty(
                                                    $savedMethod[
                                                        'provider'
                                                    ]
                                                )
                                            ) {
                                                $savedLabel .=
                                                    ' · ' .
                                                    $savedMethod[
                                                        'provider'
                                                    ];
                                            }

                                            if (
                                                !empty(
                                                    $savedMethod[
                                                        'is_default'
                                                    ]
                                                )
                                            ) {
                                                $savedLabel .=
                                                    ' · Default';
                                            }
                                            ?>

                                            <option
                                                value="<?= (int) $savedMethod[
                                                    'id'
                                                ]; ?>"
                                                data-provider="<?= e(
                                                    $savedMethod[
                                                        'provider'
                                                    ]
                                                ); ?>"
                                                data-method-type="<?= e(
                                                    $savedMethod[
                                                        'method_type'
                                                    ]
                                                ); ?>"
                                                <?= $selectedSavedMethodId ===
                                                    (int) $savedMethod[
                                                        'id'
                                                    ]
                                                        ? 'selected'
                                                        : ''; ?>
                                            >
                                                <?= e($savedLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="pa-provider-fields" id="providerFields">
                                <div class="pa-field">
                                    <label class="pa-label">
                                        Provider
                                    </label>

                                    <input
                                        type="text"
                                        name="provider"
                                        id="paymentProvider"
                                        class="pa-input"
                                        maxlength="120"
                                        value="<?= e(
                                            paymentAddOld(
                                                'provider'
                                            )
                                        ); ?>"
                                        placeholder="Bank, Stripe, Razorpay, terminal..."
                                    >
                                </div>

                                <div class="pa-field">
                                    <label class="pa-label">
                                        Transaction / Reference ID
                                    </label>

                                    <input
                                        type="text"
                                        name="provider_payment_id"
                                        id="providerPaymentId"
                                        class="pa-input"
                                        maxlength="190"
                                        value="<?= e(
                                            paymentAddOld(
                                                'provider_payment_id'
                                            )
                                        ); ?>"
                                        placeholder="Transaction, UTR, cheque, or provider reference"
                                    >
                                </div>
                            </div>

                            <div class="pa-failure-field" id="failureField">
                                <div class="pa-field full">
                                    <label class="pa-label">
                                        Failure Reason
                                        <span class="pa-required">*</span>
                                    </label>

                                    <textarea
                                        name="failure_reason"
                                        id="failureReason"
                                        class="pa-textarea"
                                        placeholder="Explain why the payment failed"
                                    ><?= e(
                                        paymentAddOld(
                                            'failure_reason'
                                        )
                                    ); ?></textarea>
                                </div>
                            </div>

                            <div class="pa-field full">
                                <label class="pa-label">
                                    Notes
                                </label>

                                <textarea
                                    name="notes"
                                    class="pa-textarea"
                                    placeholder="Collection remarks, cheque details, bank notes, or internal payment information"
                                ><?= e(
                                    paymentAddOld('notes')
                                ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="pa-card">
                    <div class="pa-card-head">
                        <h2>Invoice Summary</h2>
                        <p>
                            Verify the customer, invoice, and balance before recording the payment.
                        </p>
                    </div>

                    <div class="pa-card-body">
                        <div class="pa-invoice-title">
                            <span class="pa-invoice-no">
                                <?= e(
                                    $invoice['invoice_no']
                                ); ?>
                            </span>

                            <span class="pa-status <?= e(
                                preg_replace(
                                    '/[^a-z0-9_-]/',
                                    '',
                                    strtolower(
                                        $invoice[
                                            'status'
                                        ]
                                    )
                                )
                            ); ?>">
                                <?= e(
                                    paymentAddLabel(
                                        $invoice[
                                            'status'
                                        ]
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="pa-client">
                            <div class="pa-client-name">
                                <?= e(
                                    $invoice[
                                        'client_name'
                                    ]
                                ); ?>
                            </div>

                            <div class="pa-client-meta">
                                <?php
                                $clientMeta = array_filter(
                                    array(
                                        $invoice[
                                            'client_company'
                                        ],
                                        $invoice[
                                            'client_phone'
                                        ],
                                        $invoice[
                                            'client_email'
                                        ],
                                        !empty(
                                            $invoice[
                                                'client_tax_number'
                                            ]
                                        )
                                            ? 'Tax: ' .
                                                $invoice[
                                                    'client_tax_number'
                                                ]
                                            : null
                                    ),
                                    function ($value) {
                                        return trim(
                                            (string) $value
                                        ) !== '';
                                    }
                                );
                                ?>

                                <?= e(
                                    !empty($clientMeta)
                                        ? implode(
                                            ' · ',
                                            $clientMeta
                                        )
                                        : 'No additional customer details'
                                ); ?>
                            </div>
                        </div>

                        <div class="pa-summary">
                            <div class="pa-summary-row">
                                <span>Invoice Total</span>
                                <strong>
                                    <?= e(
                                        paymentAddMoney(
                                            $invoice['total'],
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>

                            <div class="pa-summary-row paid">
                                <span>Already Paid</span>
                                <strong>
                                    <?= e(
                                        paymentAddMoney(
                                            $invoice[
                                                'amount_paid'
                                            ],
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>

                            <div class="pa-summary-row balance">
                                <span>Current Balance</span>
                                <strong>
                                    <?= e(
                                        paymentAddMoney(
                                            $invoice[
                                                'balance_due'
                                            ],
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>

                            <div class="pa-summary-row">
                                <span>This Payment</span>
                                <strong id="summaryPayment">
                                    <?= e(
                                        paymentAddMoney(
                                            $defaultAmount,
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>

                            <div class="pa-summary-row after">
                                <span>Balance After Payment</span>
                                <strong id="summaryRemaining">
                                    <?= e(
                                        paymentAddMoney(
                                            0,
                                            $currencyCode
                                        )
                                    ); ?>
                                </strong>
                            </div>
                        </div>

                        <div class="pa-related">
                            <div class="pa-related-item">
                                <span class="pa-related-label">
                                    Invoice Date
                                </span>
                                <span class="pa-related-value">
                                    <?= e(
                                        paymentAddDate(
                                            $invoice[
                                                'issue_date'
                                            ]
                                        )
                                    ); ?>
                                </span>
                            </div>

                            <div class="pa-related-item">
                                <span class="pa-related-label">
                                    Due Date
                                </span>
                                <span class="pa-related-value">
                                    <?= e(
                                        paymentAddDate(
                                            $invoice[
                                                'due_date'
                                            ]
                                        )
                                    ); ?>
                                </span>
                            </div>

                            <div class="pa-related-item">
                                <span class="pa-related-label">
                                    Previous Attempts
                                </span>
                                <span class="pa-related-value">
                                    <?= (int) $paymentStats[
                                        'payment_count'
                                    ]; ?>
                                </span>
                            </div>

                            <div class="pa-related-item">
                                <span class="pa-related-label">
                                    Last Successful Payment
                                </span>
                                <span class="pa-related-value">
                                    <?= e(
                                        !empty(
                                            $paymentStats[
                                                'last_payment_at'
                                            ]
                                        )
                                            ? paymentAddDate(
                                                $paymentStats[
                                                    'last_payment_at'
                                                ]
                                            )
                                            : 'None'
                                    ); ?>
                                </span>
                            </div>

                            <?php if (
                                !empty(
                                    $invoice['job_no']
                                )
                            ): ?>
                                <div class="pa-related-item">
                                    <span class="pa-related-label">
                                        Job
                                    </span>
                                    <span class="pa-related-value">
                                        <?= e(
                                            $invoice['job_no']
                                        ); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (
                                !empty(
                                    $invoice['quote_no']
                                )
                            ): ?>
                                <div class="pa-related-item">
                                    <span class="pa-related-label">
                                        Quote
                                    </span>
                                    <span class="pa-related-value">
                                        <?= e(
                                            $invoice[
                                                'quote_no'
                                            ]
                                        ); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pa-actions">
                        <a
                            href="invoice-view.php?id=<?= $invoiceId; ?>"
                            class="pa-btn secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="pa-btn primary"
                            id="savePaymentButton"
                        >
                            <i class="bi bi-check2"></i>
                            Record Payment
                        </button>
                    </div>
                </section>
            </aside>
        </div>
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

        var currentBalance =
            <?= json_encode(
                round(
                    (float) $invoice[
                        'balance_due'
                    ],
                    2
                )
            ); ?>;

        var invoiceEligible =
            <?= $invoiceCanReceivePayment
                ? 'true'
                : 'false'; ?>;

        var amountInput =
            document.getElementById(
                'paymentAmount'
            );

        var statusSelect =
            document.getElementById(
                'paymentStatus'
            );

        var methodSelect =
            document.getElementById(
                'paymentMethod'
            );

        var channelSelect =
            document.getElementById(
                'paymentChannel'
            );

        var providerFields =
            document.getElementById(
                'providerFields'
            );

        var providerInput =
            document.getElementById(
                'paymentProvider'
            );

        var providerPaymentId =
            document.getElementById(
                'providerPaymentId'
            );

        var failureField =
            document.getElementById(
                'failureField'
            );

        var failureReason =
            document.getElementById(
                'failureReason'
            );

        var savedMethodSelect =
            document.getElementById(
                'savedPaymentMethod'
            );

        var saveButton =
            document.getElementById(
                'savePaymentButton'
            );

        var statusNote =
            document.getElementById(
                'paymentStatusNote'
            );

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

        function updateSummary() {
            var amount =
                Math.max(
                    0,
                    numberValue(
                        amountInput.value
                    )
                );

            var affectsBalance =
                statusSelect.value ===
                'succeeded';

            var remaining =
                affectsBalance
                    ? Math.max(
                        0,
                        currentBalance -
                        amount
                    )
                    : currentBalance;

            document.getElementById(
                'summaryPayment'
            ).textContent =
                money(amount);

            document.getElementById(
                'summaryRemaining'
            ).textContent =
                money(remaining);
        }

        function updateStatusFields() {
            var status =
                statusSelect.value;

            failureField.classList.toggle(
                'visible',
                status === 'failed'
            );

            failureReason.required =
                status === 'failed';

            if (status === 'succeeded') {
                statusNote.textContent =
                    'A successful payment immediately updates the invoice paid amount, balance, and status.';

                amountInput.max =
                    currentBalance.toFixed(2);
            } else if (
                status === 'pending'
            ) {
                statusNote.textContent =
                    'A pending payment is recorded but does not change the invoice balance.';
                amountInput.removeAttribute(
                    'max'
                );
            } else if (
                status === 'authorized'
            ) {
                statusNote.textContent =
                    'An authorized payment is recorded but does not change the invoice balance until it succeeds.';
                amountInput.removeAttribute(
                    'max'
                );
            } else {
                statusNote.textContent =
                    'A failed payment is kept for history and does not change the invoice balance.';
                amountInput.removeAttribute(
                    'max'
                );
            }

            if (
                !invoiceEligible &&
                status === 'succeeded'
            ) {
                statusSelect.value =
                    'pending';

                updateStatusFields();
                return;
            }

            updateSummary();
        }

        function updateProviderFields() {
            var method =
                methodSelect.value;

            var channel =
                channelSelect.value;

            var visible =
                method !== 'cash' ||
                channel === 'online' ||
                channel === 'client_portal' ||
                channel === 'tap_to_pay';

            providerFields.classList.toggle(
                'visible',
                visible
            );

            if (!visible) {
                providerInput.required = false;
                providerPaymentId.required = false;
            }
        }

        amountInput.addEventListener(
            'input',
            updateSummary
        );

        statusSelect.addEventListener(
            'change',
            updateStatusFields
        );

        methodSelect.addEventListener(
            'change',
            updateProviderFields
        );

        channelSelect.addEventListener(
            'change',
            updateProviderFields
        );

        if (savedMethodSelect) {
            savedMethodSelect.addEventListener(
                'change',
                function () {
                    var option =
                        savedMethodSelect.options[
                            savedMethodSelect
                                .selectedIndex
                        ];

                    if (
                        !option ||
                        option.value === ''
                    ) {
                        return;
                    }

                    if (
                        option.dataset.methodType
                    ) {
                        methodSelect.value =
                            option.dataset
                                .methodType;
                    }

                    if (
                        option.dataset.provider &&
                        providerInput.value
                            .trim() === ''
                    ) {
                        providerInput.value =
                            option.dataset.provider;
                    }

                    updateProviderFields();
                }
            );
        }

        document.getElementById(
            'paymentAddForm'
        ).addEventListener(
            'submit',
            function (event) {
                var amount =
                    numberValue(
                        amountInput.value
                    );

                if (amount <= 0) {
                    event.preventDefault();

                    window.alert(
                        'Payment amount must be greater than zero.'
                    );

                    return;
                }

                if (
                    statusSelect.value ===
                        'succeeded' &&
                    amount >
                        currentBalance + 0.005
                ) {
                    event.preventDefault();

                    window.alert(
                        'Payment amount cannot exceed the current invoice balance.'
                    );

                    return;
                }

                if (
                    statusSelect.value ===
                        'failed' &&
                    failureReason.value
                        .trim() === ''
                ) {
                    event.preventDefault();

                    window.alert(
                        'Enter the payment failure reason.'
                    );

                    return;
                }

                saveButton.disabled = true;

                saveButton.innerHTML =
                    '<i class="bi bi-hourglass-split"></i> Saving Payment...';
            }
        );

        updateStatusFields();
        updateProviderFields();
        updateSummary();
    }
);
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
