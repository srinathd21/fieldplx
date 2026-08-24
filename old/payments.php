<?php
/**
 * FieldPlx - Payments
 *
 * Upload as:
 * /public_html/payments.php
 *
 * PHP 7.2+ / MariaDB / MySQLi
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

/*
|--------------------------------------------------------------------------
| Authentication and permissions
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header(
        'Location: login.php?redirect=' .
        rawurlencode('payments.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'payments.view',
        'You do not have permission to view payments.'
    );
}

$tenantId = (int) $_SESSION['tenant_id'];
$currentUserId = (int) $_SESSION['user_id'];

$canManage = function_exists('hasPermission')
    ? hasPermission('payments.manage')
    : true;

$pageTitle = 'Payments - FieldPlx';
$activePage = 'payments';
$searchPlaceholder = 'Search payments...';
$basePath = '';

$errors = array();

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('paymentsFetchAssoc')) {
    function paymentsFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('paymentsFetchAll')) {
    function paymentsFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('paymentsBindParams')) {
    function paymentsBindParams(
        mysqli_stmt $stmt,
        $types,
        array &$params
    ) {
        if ($types === '' || empty($params)) {
            return true;
        }

        $arguments = array($types);

        foreach ($params as $key => $value) {
            $arguments[] = &$params[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('paymentsMoney')) {
    function paymentsMoney(
        $amount,
        $currencyCode
    ) {
        $currencyCode = strtoupper(
            trim((string) $currencyCode)
        );

        $prefixes = array(
            'INR' => '₹',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
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

if (!function_exists('paymentsDate')) {
    function paymentsDate($value)
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

if (!function_exists('paymentsDateTime')) {
    function paymentsDateTime($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp =
            strtotime((string) $value);

        return $timestamp
            ? date('d M Y, h:i A', $timestamp)
            : '—';
    }
}

if (!function_exists('paymentsLabel')) {
    function paymentsLabel($value)
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

if (!function_exists('paymentsQueryString')) {
    function paymentsQueryString(
        array $overrides = array()
    ) {
        $query = $_GET;

        foreach ($overrides as $key => $value) {
            if (
                $value === null ||
                $value === ''
            ) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return http_build_query($query);
    }
}

/*
|--------------------------------------------------------------------------
| Tenant currency
|--------------------------------------------------------------------------
*/

$tenantCurrency = 'INR';

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
            paymentsFetchAssoc($stmt);

        if (
            $tenantRow &&
            trim(
                (string) $tenantRow[
                    'currency_code'
                ]
            ) !== ''
        ) {
            $tenantCurrency =
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
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

$statusFilter = isset($_GET['status'])
    ? trim((string) $_GET['status'])
    : '';

$methodFilter = isset($_GET['method'])
    ? trim((string) $_GET['method'])
    : '';

$channelFilter = isset($_GET['channel'])
    ? trim((string) $_GET['channel'])
    : '';

$clientFilter = isset($_GET['client_id'])
    ? max(0, (int) $_GET['client_id'])
    : 0;

$invoiceFilter = isset($_GET['invoice_id'])
    ? max(0, (int) $_GET['invoice_id'])
    : 0;

$dateFrom = isset($_GET['date_from'])
    ? trim((string) $_GET['date_from'])
    : '';

$dateTo = isset($_GET['date_to'])
    ? trim((string) $_GET['date_to'])
    : '';

$sort = isset($_GET['sort'])
    ? trim((string) $_GET['sort'])
    : 'latest';

$allowedStatuses = array(
    '',
    'pending',
    'authorized',
    'succeeded',
    'failed',
    'refunded',
    'partially_refunded',
    'cancelled'
);

$allowedMethods = array(
    '',
    'cash',
    'card',
    'bank',
    'upi',
    'cheque',
    'wallet',
    'other'
);

$allowedChannels = array(
    '',
    'online',
    'client_portal',
    'mobile',
    'office',
    'tap_to_pay',
    'manual'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'amount_desc',
    'amount_asc',
    'client_asc',
    'status_asc',
    'payment_no_asc'
);

if (
    !in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $statusFilter = '';
}

if (
    !in_array(
        $methodFilter,
        $allowedMethods,
        true
    )
) {
    $methodFilter = '';
}

if (
    !in_array(
        $channelFilter,
        $allowedChannels,
        true
    )
) {
    $channelFilter = '';
}

if (
    !in_array(
        $sort,
        $allowedSorts,
        true
    )
) {
    $sort = 'latest';
}

if (
    $dateFrom !== '' &&
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $dateFrom
    )
) {
    $dateFrom = '';
}

if (
    $dateTo !== '' &&
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $dateTo
    )
) {
    $dateTo = '';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 20;
$offset =
    ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Filter options
|--------------------------------------------------------------------------
*/

$clients = array();

$stmt = $conn->prepare("
    SELECT DISTINCT
        c.id,
        c.display_name,
        c.company_name
    FROM payments p

    INNER JOIN clients c
        ON c.id = p.client_id
       AND c.tenant_id = p.tenant_id
       AND c.deleted_at IS NULL

    WHERE p.tenant_id = ?

    ORDER BY c.display_name ASC
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $clients =
            paymentsFetchAll($stmt);
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Summary statistics
|--------------------------------------------------------------------------
*/

$stats = array(
    'successful_total' => 0.00,
    'today_total' => 0.00,
    'month_total' => 0.00,
    'successful_count' => 0,
    'pending_count' => 0,
    'failed_count' => 0,
    'fee_total' => 0.00
);

$stmt = $conn->prepare("
    SELECT
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

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'succeeded'
                     AND DATE(
                            COALESCE(
                                received_at,
                                created_at
                            )
                         ) = CURDATE()
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS today_total,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'succeeded'
                     AND YEAR(
                            COALESCE(
                                received_at,
                                created_at
                            )
                         ) = YEAR(CURDATE())
                     AND MONTH(
                            COALESCE(
                                received_at,
                                created_at
                            )
                         ) = MONTH(CURDATE())
                    THEN amount
                    ELSE 0
                END
            ),
            0
        ) AS month_total,

        SUM(status = 'succeeded')
            AS successful_count,

        SUM(
            status IN (
                'pending',
                'authorized'
            )
        ) AS pending_count,

        SUM(status = 'failed')
            AS failed_count,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'succeeded'
                    THEN transaction_fee
                    ELSE 0
                END
            ),
            0
        ) AS fee_total

    FROM payments
    WHERE tenant_id = ?
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $tenantId
    );

    if ($stmt->execute()) {
        $row =
            paymentsFetchAssoc($stmt);

        if ($row) {
            $stats['successful_total'] =
                (float) $row[
                    'successful_total'
                ];

            $stats['today_total'] =
                (float) $row[
                    'today_total'
                ];

            $stats['month_total'] =
                (float) $row[
                    'month_total'
                ];

            $stats['successful_count'] =
                (int) $row[
                    'successful_count'
                ];

            $stats['pending_count'] =
                (int) $row[
                    'pending_count'
                ];

            $stats['failed_count'] =
                (int) $row[
                    'failed_count'
                ];

            $stats['fee_total'] =
                (float) $row[
                    'fee_total'
                ];
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Outstanding invoices for Record Payment modal
|--------------------------------------------------------------------------
*/

$outstandingInvoices = array();

if ($canManage) {
    $stmt = $conn->prepare("
        SELECT
            i.id,
            i.invoice_no,
            i.status,
            i.issue_date,
            i.due_date,
            i.total,
            i.amount_paid,
            i.balance_due,
            c.display_name AS client_name,
            c.company_name AS client_company,
            c.phone AS client_phone,
            t.currency_code
        FROM invoices i

        INNER JOIN clients c
            ON c.id = i.client_id
           AND c.tenant_id = i.tenant_id
           AND c.deleted_at IS NULL

        INNER JOIN tenants t
            ON t.id = i.tenant_id
           AND t.deleted_at IS NULL

        WHERE i.tenant_id = ?
          AND i.archived_at IS NULL
          AND i.balance_due > 0
          AND i.status IN (
              'sent',
              'viewed',
              'partially_paid',
              'overdue'
          )

        ORDER BY
            CASE
                WHEN i.status = 'overdue'
                THEN 0
                ELSE 1
            END,
            i.due_date ASC,
            i.id DESC

        LIMIT 500
    ");

    if ($stmt) {
        $stmt->bind_param(
            'i',
            $tenantId
        );

        if ($stmt->execute()) {
            $outstandingInvoices =
                paymentsFetchAll($stmt);
        }

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Build filtered payment query
|--------------------------------------------------------------------------
*/

$where = array(
    'p.tenant_id = ?'
);

$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        p.payment_no LIKE ?
        OR c.display_name LIKE ?
        OR c.company_name LIKE ?
        OR c.phone LIKE ?
        OR c.email LIKE ?
        OR i.invoice_no LIKE ?
        OR p.provider LIKE ?
        OR p.provider_payment_id LIKE ?
        OR p.notes LIKE ?
    )";

    $searchLike =
        '%' . $search . '%';

    for ($index = 0; $index < 9; $index++) {
        $params[] =
            $searchLike;

        $types .= 's';
    }
}

if ($statusFilter !== '') {
    $where[] =
        'p.status = ?';

    $params[] =
        $statusFilter;

    $types .= 's';
}

if ($methodFilter !== '') {
    $where[] =
        'p.payment_method = ?';

    $params[] =
        $methodFilter;

    $types .= 's';
}

if ($channelFilter !== '') {
    $where[] =
        'p.payment_channel = ?';

    $params[] =
        $channelFilter;

    $types .= 's';
}

if ($clientFilter > 0) {
    $where[] =
        'p.client_id = ?';

    $params[] =
        $clientFilter;

    $types .= 'i';
}

if ($invoiceFilter > 0) {
    $where[] =
        'p.invoice_id = ?';

    $params[] =
        $invoiceFilter;

    $types .= 'i';
}

if ($dateFrom !== '') {
    $where[] = "
        DATE(
            COALESCE(
                p.received_at,
                p.created_at
            )
        ) >= ?
    ";

    $params[] =
        $dateFrom;

    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = "
        DATE(
            COALESCE(
                p.received_at,
                p.created_at
            )
        ) <= ?
    ";

    $params[] =
        $dateTo;

    $types .= 's';
}

$whereSql =
    implode(' AND ', $where);

$orderSql = "
    COALESCE(
        p.received_at,
        p.created_at
    ) DESC,
    p.id DESC
";

if ($sort === 'oldest') {
    $orderSql = "
        COALESCE(
            p.received_at,
            p.created_at
        ) ASC,
        p.id ASC
    ";
} elseif ($sort === 'amount_desc') {
    $orderSql =
        'p.amount DESC, p.id DESC';
} elseif ($sort === 'amount_asc') {
    $orderSql =
        'p.amount ASC, p.id ASC';
} elseif ($sort === 'client_asc') {
    $orderSql =
        'c.display_name ASC, p.id DESC';
} elseif ($sort === 'status_asc') {
    $orderSql =
        'p.status ASC, p.id DESC';
} elseif ($sort === 'payment_no_asc') {
    $orderSql =
        'p.payment_no ASC, p.id ASC';
}

/*
|--------------------------------------------------------------------------
| Count filtered rows
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM payments p

    INNER JOIN clients c
        ON c.id = p.client_id
       AND c.tenant_id = p.tenant_id
       AND c.deleted_at IS NULL

    LEFT JOIN invoices i
        ON i.id = p.invoice_id
       AND i.tenant_id = p.tenant_id
       AND i.archived_at IS NULL

    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare the payment count query: ' .
        $conn->error;
} else {
    if (
        !paymentsBindParams(
            $stmt,
            $types,
            $params
        )
    ) {
        $errors[] =
            'Unable to bind payment filters.';
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to count payments: ' .
            $stmt->error;
    } else {
        $row =
            paymentsFetchAssoc($stmt);

        if ($row) {
            $totalFiltered =
                (int) $row['total'];
        }
    }

    $stmt->close();
}

$totalPages = max(
    1,
    (int) ceil(
        $totalFiltered / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset =
        ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Load filtered payment rows
|--------------------------------------------------------------------------
*/

$payments = array();

$listSql = "
    SELECT
        p.id,
        p.payment_no,
        p.client_id,
        p.invoice_id,
        p.quote_id,
        p.payment_method_id,
        p.provider,
        p.provider_payment_id,
        p.payment_method,
        p.payment_channel,
        p.status,
        p.amount,
        p.currency_code,
        p.transaction_fee,
        p.received_at,
        p.failure_reason,
        p.notes,
        p.created_by,
        p.created_at,
        p.updated_at,

        c.display_name AS client_name,
        c.company_name AS client_company,
        c.email AS client_email,
        c.phone AS client_phone,

        i.invoice_no,
        i.status AS invoice_status,
        i.total AS invoice_total,
        i.amount_paid AS invoice_amount_paid,
        i.balance_due AS invoice_balance_due,
        i.due_date AS invoice_due_date,

        q.quote_no,

        pm.provider AS saved_method_provider,
        pm.method_type AS saved_method_type,
        pm.brand AS saved_method_brand,
        pm.last4 AS saved_method_last4,

        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN COALESCE(u.first_name, '') <> ''
                 AND COALESCE(u.last_name, '') <> ''
                THEN ' '
                ELSE ''
            END,
            COALESCE(u.last_name, '')
        ) AS created_by_name,

        CASE
            WHEN p.status = 'succeeded'
            THEN p.amount - p.transaction_fee
            ELSE 0
        END AS net_amount

    FROM payments p

    INNER JOIN clients c
        ON c.id = p.client_id
       AND c.tenant_id = p.tenant_id
       AND c.deleted_at IS NULL

    LEFT JOIN invoices i
        ON i.id = p.invoice_id
       AND i.tenant_id = p.tenant_id
       AND i.archived_at IS NULL

    LEFT JOIN quotes q
        ON q.id = p.quote_id
       AND q.tenant_id = p.tenant_id
       AND q.archived_at IS NULL

    LEFT JOIN payment_methods pm
        ON pm.id = p.payment_method_id
       AND pm.tenant_id = p.tenant_id

    LEFT JOIN users u
        ON u.id = p.created_by
       AND u.tenant_id = p.tenant_id
       AND u.deleted_at IS NULL

    WHERE {$whereSql}

    ORDER BY {$orderSql}

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($listSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare the payment list query: ' .
        $conn->error;
} else {
    $listParams = $params;
    $listTypes =
        $types . 'ii';

    $listParams[] =
        $perPage;

    $listParams[] =
        $offset;

    if (
        !paymentsBindParams(
            $stmt,
            $listTypes,
            $listParams
        )
    ) {
        $errors[] =
            'Unable to bind the payment list filters.';
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to load payments: ' .
            $stmt->error;
    } else {
        $payments =
            paymentsFetchAll($stmt);
    }

    $stmt->close();
}

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.payments-page {
    --py-primary: #6d28d9;
    --py-text: #111827;
    --py-muted: #6b7280;
    --py-border: #e5e7eb;
}

.py-header {
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.py-header h1 {
    margin: 0;
    color: var(--py-text);
    font-size: 21px;
    font-weight: 700;
}

.py-header p {
    margin: 5px 0 0;
    color: var(--py-muted);
    font-size: 11px;
}

.py-add {
    min-height: 35px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 0;
    border-radius: 9px;
    background: var(--py-primary);
    color: #fff;
    font-family: inherit;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
}

.py-alert {
    margin-bottom: 13px;
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 10px;
    line-height: 1.55;
}

.py-alert.success {
    border: 1px solid #bbf7d0;
    background: #f0fdf4;
    color: #047857;
}

.py-alert.error {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}

.py-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns:
        repeat(6,minmax(0,1fr));
    gap: 10px;
}

.py-stat {
    min-width: 0;
    padding: 13px;
    border: 1px solid var(--py-border);
    border-radius: 11px;
    background: #fff;
}

.py-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.py-stat-value {
    margin-top: 4px;
    overflow: hidden;
    color: var(--py-text);
    font-size: 18px;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.py-stat-sub {
    margin-top: 4px;
    color: #9ca3af;
    font-size: 8px;
}

.py-panel {
    overflow: hidden;
    border: 1px solid var(--py-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.py-filters {
    padding: 12px;
    display: grid;
    grid-template-columns:
        minmax(220px,1.2fr)
        minmax(130px,.62fr)
        minmax(130px,.62fr)
        minmax(145px,.68fr)
        minmax(165px,.75fr);
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.py-input,
.py-select {
    width: 100%;
    height: 36px;
    min-height: 36px;
    padding: 8px 10px;
    border: 1px solid #dfe3e8;
    border-radius: 8px;
    background: #fff;
    color: #111827;
    font-family: inherit;
    font-size: 9px;
    outline: none;
}

.py-input:focus,
.py-select:focus {
    border-color: #8b5cf6;
    box-shadow:
        0 0 0 3px
        rgba(139,92,246,.08);
}

.py-filter-actions {
    display: flex;
    gap: 6px;
}

.py-filter-btn,
.py-reset {
    height: 36px;
    min-height: 36px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 700;
}

.py-filter-btn {
    border: 0;
    background: var(--py-primary);
    color: #fff;
    cursor: pointer;
}

.py-reset {
    border: 1px solid var(--py-border);
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.py-table-wrap {
    overflow-x: auto;
}

.py-table {
    width: 100%;
    min-width: 1250px;
    border-collapse: collapse;
}

.py-table th,
.py-table td {
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    white-space: nowrap;
    vertical-align: middle;
}

.py-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.py-table td {
    color: #374151;
    font-size: 9px;
}

.py-main {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.py-main:hover {
    color: var(--py-primary);
}

.py-sub {
    margin-top: 2px;
    display: block;
    max-width: 250px;
    overflow: hidden;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.4;
    text-overflow: ellipsis;
}

.py-money {
    color: #111827;
    font-size: 10px;
    font-weight: 700;
}

.py-money.success {
    color: #047857;
}

.py-money.muted {
    color: #6b7280;
}

.py-badge {
    padding: 4px 7px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
}

.py-badge.succeeded {
    background: #ecfdf5;
    color: #047857;
}

.py-badge.pending,
.py-badge.authorized {
    background: #fff7ed;
    color: #c2410c;
}

.py-badge.failed,
.py-badge.cancelled {
    background: #fef2f2;
    color: #b91c1c;
}

.py-badge.refunded,
.py-badge.partially_refunded {
    background: #eff6ff;
    color: #1d4ed8;
}

.py-method {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #374151;
    font-size: 9px;
    font-weight: 700;
}

.py-method i {
    color: #7c3aed;
}

.py-actions {
    display: flex;
    justify-content: flex-end;
    gap: 5px;
}

.py-action {
    width: 29px;
    height: 29px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--py-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.py-action:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--py-primary);
}

.py-action.success {
    border-color: #bbf7d0;
    color: #047857;
}

.py-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #f1f5f9;
}

.py-result {
    color: #6b7280;
    font-size: 9px;
}

.py-pages {
    display: flex;
    gap: 5px;
}

.py-page {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--py-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.py-page.active {
    border-color: var(--py-primary);
    background: var(--py-primary);
    color: #fff;
}

.py-empty {
    padding: 44px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

.py-empty i {
    margin-bottom: 9px;
    display: block;
    color: #c4b5fd;
    font-size: 30px;
}

/*
|--------------------------------------------------------------------------
| Record payment modal
|--------------------------------------------------------------------------
*/

.py-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(15,23,42,.48);
}

.py-modal.open {
    display: flex;
}

.py-modal-card {
    width: min(760px,100%);
    max-height: calc(100vh - 36px);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    background: #fff;
    box-shadow:
        0 25px 60px
        rgba(15,23,42,.25);
}

.py-modal-head {
    padding: 13px 15px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid #eef0f4;
}

.py-modal-head h2 {
    margin: 0;
    color: #111827;
    font-size: 13px;
    font-weight: 800;
}

.py-modal-head p {
    margin: 4px 0 0;
    color: #9ca3af;
    font-size: 8px;
}

.py-modal-close {
    width: 31px;
    height: 31px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    cursor: pointer;
}

.py-modal-body {
    padding: 14px;
    overflow-y: auto;
}

.py-modal-search {
    margin-bottom: 10px;
}

.py-invoice-list {
    display: grid;
    gap: 7px;
}

.py-invoice-option {
    width: 100%;
    padding: 10px 11px;
    display: grid;
    grid-template-columns:
        minmax(0,1fr)
        auto;
    gap: 10px;
    align-items: center;
    border: 1px solid #e5e7eb;
    border-radius: 9px;
    background: #fff;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
}

.py-invoice-option:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
}

.py-invoice-name {
    color: #111827;
    font-size: 9px;
    font-weight: 800;
}

.py-invoice-meta {
    margin-top: 3px;
    color: #9ca3af;
    font-size: 8px;
}

.py-invoice-balance {
    color: #c2410c;
    font-size: 10px;
    font-weight: 800;
    text-align: right;
}

.py-invoice-due {
    margin-top: 3px;
    color: #9ca3af;
    font-size: 8px;
    text-align: right;
}

.py-modal-empty {
    padding: 30px 12px;
    color: #9ca3af;
    font-size: 9px;
    text-align: center;
}

@media (max-width: 1280px) {
    .py-filters {
        grid-template-columns:
            repeat(4,minmax(0,1fr));
    }
}

@media (max-width: 1050px) {
    .py-stats {
        grid-template-columns:
            repeat(3,minmax(0,1fr));
    }
}

@media (max-width: 760px) {
    .py-header {
        flex-direction: column;
    }

    .py-filters {
        grid-template-columns:
            repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 560px) {
    .py-stats,
    .py-filters {
        grid-template-columns: 1fr;
    }

    .py-filter-actions {
        width: 100%;
    }

    .py-filter-btn,
    .py-reset {
        flex: 1;
    }

    .py-footer {
        flex-direction: column;
        align-items: flex-start;
    }

    .py-invoice-option {
        grid-template-columns: 1fr;
    }

    .py-invoice-balance,
    .py-invoice-due {
        text-align: left;
    }
}
</style>

<div class="payments-page">
    <div class="py-header">
        <div>
            <h1>Payments</h1>
            <p>
                Review customer payments, collection status, methods, channels, fees, and invoice links.
            </p>
        </div>

        <?php if ($canManage): ?>
            <button
                type="button"
                class="py-add"
                id="openPaymentModal"
            >
                <i class="bi bi-plus-lg"></i>
                Record Payment
            </button>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="py-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="py-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="py-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="py-stats">
        <article class="py-stat">
            <div class="py-stat-label">
                Total Collected
            </div>
            <div class="py-stat-value">
                <?= e(
                    paymentsMoney(
                        $stats[
                            'successful_total'
                        ],
                        $tenantCurrency
                    )
                ); ?>
            </div>
            <div class="py-stat-sub">
                <?= e(
                    $stats[
                        'successful_count'
                    ]
                ); ?>
                successful payment<?= $stats[
                    'successful_count'
                ] === 1 ? '' : 's'; ?>
            </div>
        </article>

        <article class="py-stat">
            <div class="py-stat-label">
                Collected Today
            </div>
            <div class="py-stat-value">
                <?= e(
                    paymentsMoney(
                        $stats['today_total'],
                        $tenantCurrency
                    )
                ); ?>
            </div>
            <div class="py-stat-sub">
                Successful payments today
            </div>
        </article>

        <article class="py-stat">
            <div class="py-stat-label">
                This Month
            </div>
            <div class="py-stat-value">
                <?= e(
                    paymentsMoney(
                        $stats['month_total'],
                        $tenantCurrency
                    )
                ); ?>
            </div>
            <div class="py-stat-sub">
                Current calendar month
            </div>
        </article>

        <article class="py-stat">
            <div class="py-stat-label">
                Pending / Authorized
            </div>
            <div class="py-stat-value">
                <?= e(
                    $stats['pending_count']
                ); ?>
            </div>
            <div class="py-stat-sub">
                Not applied to invoices
            </div>
        </article>

        <article class="py-stat">
            <div class="py-stat-label">
                Failed Payments
            </div>
            <div class="py-stat-value">
                <?= e(
                    $stats['failed_count']
                ); ?>
            </div>
            <div class="py-stat-sub">
                Failed payment attempts
            </div>
        </article>

        <article class="py-stat">
            <div class="py-stat-label">
                Transaction Fees
            </div>
            <div class="py-stat-value">
                <?= e(
                    paymentsMoney(
                        $stats['fee_total'],
                        $tenantCurrency
                    )
                ); ?>
            </div>
            <div class="py-stat-sub">
                Fees on successful payments
            </div>
        </article>
    </section>

    <section class="py-panel">
        <form
            method="get"
            action=""
            class="py-filters"
            id="paymentFilters"
        >
            <input
                type="search"
                name="search"
                id="paymentSearch"
                class="py-input"
                value="<?= e($search); ?>"
                placeholder="Search payment, client, invoice or reference"
            >

            <select
                name="status"
                class="py-select"
            >
                <option value="">
                    All Statuses
                </option>

                <?php foreach (
                    array(
                        'pending' =>
                            'Pending',
                        'authorized' =>
                            'Authorized',
                        'succeeded' =>
                            'Succeeded',
                        'failed' =>
                            'Failed',
                        'refunded' =>
                            'Refunded',
                        'partially_refunded' =>
                            'Partially Refunded',
                        'cancelled' =>
                            'Cancelled'
                    ) as $value => $label
                ): ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $statusFilter === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="method"
                class="py-select"
            >
                <option value="">
                    All Methods
                </option>

                <?php foreach (
                    array(
                        'cash' => 'Cash',
                        'card' => 'Card',
                        'bank' =>
                            'Bank Transfer',
                        'upi' => 'UPI',
                        'cheque' => 'Cheque',
                        'wallet' => 'Wallet',
                        'other' => 'Other'
                    ) as $value => $label
                ): ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $methodFilter === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="channel"
                class="py-select"
            >
                <option value="">
                    All Channels
                </option>

                <?php foreach (
                    array(
                        'office' => 'Office',
                        'manual' => 'Manual',
                        'mobile' => 'Mobile',
                        'online' => 'Online',
                        'client_portal' =>
                            'Client Portal',
                        'tap_to_pay' =>
                            'Tap to Pay'
                    ) as $value => $label
                ): ?>
                    <option
                        value="<?= e($value); ?>"
                        <?= $channelFilter === $value
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="client_id"
                class="py-select"
            >
                <option value="">
                    All Clients
                </option>

                <?php foreach ($clients as $client): ?>
                    <option
                        value="<?= (int) $client[
                            'id'
                        ]; ?>"
                        <?= $clientFilter ===
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
                                    'company_name'
                                ]
                            ) !== ''
                        ): ?>
                            · <?= e(
                                $client[
                                    'company_name'
                                ]
                            ); ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input
                type="date"
                name="date_from"
                class="py-input"
                value="<?= e($dateFrom); ?>"
                title="Payment date from"
            >

            <input
                type="date"
                name="date_to"
                class="py-input"
                value="<?= e($dateTo); ?>"
                title="Payment date to"
            >

            <select
                name="sort"
                class="py-select"
            >
                <option
                    value="latest"
                    <?= $sort === 'latest'
                        ? 'selected'
                        : ''; ?>
                >
                    Latest First
                </option>

                <option
                    value="oldest"
                    <?= $sort === 'oldest'
                        ? 'selected'
                        : ''; ?>
                >
                    Oldest First
                </option>

                <option
                    value="amount_desc"
                    <?= $sort === 'amount_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Highest Amount
                </option>

                <option
                    value="amount_asc"
                    <?= $sort === 'amount_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Lowest Amount
                </option>

                <option
                    value="client_asc"
                    <?= $sort === 'client_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Client A-Z
                </option>

                <option
                    value="status_asc"
                    <?= $sort === 'status_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Status
                </option>

                <option
                    value="payment_no_asc"
                    <?= $sort === 'payment_no_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Payment Number
                </option>
            </select>

            <?php if ($invoiceFilter > 0): ?>
                <input
                    type="hidden"
                    name="invoice_id"
                    value="<?= $invoiceFilter; ?>"
                >
            <?php endif; ?>

            <div class="py-filter-actions">
                <button
                    type="submit"
                    class="py-filter-btn"
                >
                    Apply
                </button>

                <a
                    href="payments.php"
                    class="py-reset"
                >
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($payments)): ?>
            <div class="py-table-wrap">
                <table class="py-table">
                    <thead>
                        <tr>
                            <th>Payment</th>
                            <th>Customer</th>
                            <th>Invoice / Quote</th>
                            <th>Method</th>
                            <th>Channel</th>
                            <th>Status</th>
                            <th style="text-align:right;">
                                Amount
                            </th>
                            <th style="text-align:right;">
                                Fee / Net
                            </th>
                            <th>Received</th>
                            <th>Recorded By</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $paymentId =
                            (int) $payment['id'];

                        $paymentStatus =
                            (string) $payment[
                                'status'
                            ];

                        $statusClass =
                            preg_replace(
                                '/[^a-z0-9_-]/',
                                '',
                                strtolower(
                                    $paymentStatus
                                )
                            );

                        $method =
                            (string) $payment[
                                'payment_method'
                            ];

                        $methodIcons = array(
                            'cash' =>
                                'bi-cash',
                            'card' =>
                                'bi-credit-card',
                            'bank' =>
                                'bi-bank',
                            'upi' =>
                                'bi-phone',
                            'cheque' =>
                                'bi-file-earmark-check',
                            'wallet' =>
                                'bi-wallet2',
                            'other' =>
                                'bi-three-dots'
                        );

                        $methodIcon =
                            isset(
                                $methodIcons[$method]
                            )
                                ? $methodIcons[$method]
                                : 'bi-cash-stack';

                        $paymentCurrency =
                            trim(
                                (string) $payment[
                                    'currency_code'
                                ]
                            ) !== ''
                                ? $payment[
                                    'currency_code'
                                ]
                                : $tenantCurrency;

                        $paymentDate =
                            !empty(
                                $payment[
                                    'received_at'
                                ]
                            )
                                ? $payment[
                                    'received_at'
                                ]
                                : $payment[
                                    'created_at'
                                ];

                        $createdByName =
                            trim(
                                (string) $payment[
                                    'created_by_name'
                                ]
                            );

                        $methodDetails =
                            array();

                        if (
                            trim(
                                (string) $payment[
                                    'saved_method_brand'
                                ]
                            ) !== ''
                        ) {
                            $methodDetails[] =
                                $payment[
                                    'saved_method_brand'
                                ];
                        }

                        if (
                            trim(
                                (string) $payment[
                                    'saved_method_last4'
                                ]
                            ) !== ''
                        ) {
                            $methodDetails[] =
                                'ending ' .
                                $payment[
                                    'saved_method_last4'
                                ];
                        }

                        if (
                            trim(
                                (string) $payment[
                                    'provider'
                                ]
                            ) !== ''
                        ) {
                            $methodDetails[] =
                                $payment[
                                    'provider'
                                ];
                        }

                        if (
                            trim(
                                (string) $payment[
                                    'provider_payment_id'
                                ]
                            ) !== ''
                        ) {
                            $methodDetails[] =
                                $payment[
                                    'provider_payment_id'
                                ];
                        }
                        ?>

                        <tr>
                            <td>
                                <span class="py-main">
                                    <?= e(
                                        $payment[
                                            'payment_no'
                                        ]
                                    ); ?>
                                </span>

                                <span class="py-sub">
                                    Created:
                                    <?= e(
                                        paymentsDateTime(
                                            $payment[
                                                'created_at'
                                            ]
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <a
                                    href="client-view.php?id=<?= (int) $payment[
                                        'client_id'
                                    ]; ?>"
                                    class="py-main"
                                >
                                    <?= e(
                                        $payment[
                                            'client_name'
                                        ]
                                    ); ?>
                                </a>

                                <span class="py-sub">
                                    <?php
                                    $clientDetails =
                                        array_filter(
                                            array(
                                                $payment[
                                                    'client_company'
                                                ],
                                                $payment[
                                                    'client_phone'
                                                ],
                                                $payment[
                                                    'client_email'
                                                ]
                                            ),
                                            function ($value) {
                                                return trim(
                                                    (string) $value
                                                ) !== '';
                                            }
                                        );
                                    ?>

                                    <?= e(
                                        !empty(
                                            $clientDetails
                                        )
                                            ? implode(
                                                ' · ',
                                                $clientDetails
                                            )
                                            : 'No contact details'
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <?php if (
                                    !empty(
                                        $payment[
                                            'invoice_id'
                                        ]
                                    )
                                ): ?>
                                    <a
                                        href="invoice-view.php?id=<?= (int) $payment[
                                            'invoice_id'
                                        ]; ?>"
                                        class="py-main"
                                    >
                                        <?= e(
                                            $payment[
                                                'invoice_no'
                                            ]
                                        ); ?>
                                    </a>

                                    <span class="py-sub">
                                        Invoice:
                                        <?= e(
                                            paymentsLabel(
                                                $payment[
                                                    'invoice_status'
                                                ]
                                            )
                                        ); ?>
                                        · Balance
                                        <?= e(
                                            paymentsMoney(
                                                $payment[
                                                    'invoice_balance_due'
                                                ],
                                                $paymentCurrency
                                            )
                                        ); ?>
                                    </span>
                                <?php elseif (
                                    !empty(
                                        $payment[
                                            'quote_id'
                                        ]
                                    )
                                ): ?>
                                    <a
                                        href="quote-view.php?id=<?= (int) $payment[
                                            'quote_id'
                                        ]; ?>"
                                        class="py-main"
                                    >
                                        <?= e(
                                            $payment[
                                                'quote_no'
                                            ]
                                        ); ?>
                                    </a>

                                    <span class="py-sub">
                                        Quote payment
                                    </span>
                                <?php else: ?>
                                    <span class="py-main">
                                        Unallocated
                                    </span>

                                    <span class="py-sub">
                                        No invoice or quote
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="py-method">
                                    <i class="bi <?= e(
                                        $methodIcon
                                    ); ?>"></i>

                                    <?= e(
                                        paymentsLabel(
                                            $method
                                        )
                                    ); ?>
                                </span>

                                <?php if (
                                    !empty($methodDetails)
                                ): ?>
                                    <span class="py-sub">
                                        <?= e(
                                            implode(
                                                ' · ',
                                                $methodDetails
                                            )
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="py-main">
                                    <?= e(
                                        paymentsLabel(
                                            $payment[
                                                'payment_channel'
                                            ]
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="py-badge <?= e(
                                    $statusClass
                                ); ?>">
                                    <?= e(
                                        paymentsLabel(
                                            $paymentStatus
                                        )
                                    ); ?>
                                </span>

                                <?php if (
                                    $paymentStatus ===
                                        'failed' &&
                                    trim(
                                        (string) $payment[
                                            'failure_reason'
                                        ]
                                    ) !== ''
                                ): ?>
                                    <span class="py-sub">
                                        <?= e(
                                            $payment[
                                                'failure_reason'
                                            ]
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td style="text-align:right;">
                                <span class="py-money <?= $paymentStatus ===
                                    'succeeded'
                                        ? 'success'
                                        : ''; ?>">
                                    <?= e(
                                        paymentsMoney(
                                            $payment['amount'],
                                            $paymentCurrency
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td style="text-align:right;">
                                <span class="py-money muted">
                                    Fee:
                                    <?= e(
                                        paymentsMoney(
                                            $payment[
                                                'transaction_fee'
                                            ],
                                            $paymentCurrency
                                        )
                                    ); ?>
                                </span>

                                <?php if (
                                    $paymentStatus ===
                                        'succeeded'
                                ): ?>
                                    <span class="py-sub">
                                        Net:
                                        <?= e(
                                            paymentsMoney(
                                                $payment[
                                                    'net_amount'
                                                ],
                                                $paymentCurrency
                                            )
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="py-main">
                                    <?= e(
                                        paymentsDate(
                                            $paymentDate
                                        )
                                    ); ?>
                                </span>

                                <span class="py-sub">
                                    <?= e(
                                        paymentsDateTime(
                                            $paymentDate
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="py-main">
                                    <?= e(
                                        $createdByName !== ''
                                            ? $createdByName
                                            : 'System'
                                    ); ?>
                                </span>

                                <?php if (
                                    trim(
                                        (string) $payment[
                                            'notes'
                                        ]
                                    ) !== ''
                                ): ?>
                                    <span class="py-sub">
                                        <?= e(
                                            $payment['notes']
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="py-actions">
                                    <?php if (
                                        !empty(
                                            $payment[
                                                'invoice_id'
                                            ]
                                        )
                                    ): ?>
                                        <a
                                            href="invoice-view.php?id=<?= (int) $payment[
                                                'invoice_id'
                                            ]; ?>"
                                            class="py-action"
                                            title="View Invoice"
                                        >
                                            <i class="bi bi-receipt"></i>
                                        </a>

                                        <?php if (
                                            $canManage &&
                                            (float) $payment[
                                                'invoice_balance_due'
                                            ] > 0 &&
                                            in_array(
                                                $payment[
                                                    'invoice_status'
                                                ],
                                                array(
                                                    'sent',
                                                    'viewed',
                                                    'partially_paid',
                                                    'overdue'
                                                ),
                                                true
                                            )
                                        ): ?>
                                            <a
                                                href="payment-add.php?invoice_id=<?= (int) $payment[
                                                    'invoice_id'
                                                ]; ?>"
                                                class="py-action success"
                                                title="Record Another Payment"
                                            >
                                                <i class="bi bi-cash-coin"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <a
                                        href="client-view.php?id=<?= (int) $payment[
                                            'client_id'
                                        ]; ?>"
                                        class="py-action"
                                        title="View Customer"
                                    >
                                        <i class="bi bi-person"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="py-footer">
                <div class="py-result">
                    Showing
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset + 1
                        )
                    ); ?>
                    -
                    <?= e(
                        min(
                            $totalFiltered,
                            $offset +
                            count($payments)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    payments
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="py-pages">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    paymentsQueryString(
                                        array(
                                            'page' =>
                                                $page - 1
                                        )
                                    )
                                ); ?>"
                                class="py-page"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage =
                            max(1, $page - 2);

                        $endPage =
                            min(
                                $totalPages,
                                $page + 2
                            );

                        for (
                            $pageNumber =
                                $startPage;
                            $pageNumber <=
                                $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(
                                    paymentsQueryString(
                                        array(
                                            'page' =>
                                                $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="py-page <?= $pageNumber === $page
                                    ? 'active'
                                    : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    paymentsQueryString(
                                        array(
                                            'page' =>
                                                $page + 1
                                        )
                                    )
                                ); ?>"
                                class="py-page"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="py-empty">
                <i class="bi bi-cash-stack"></i>

                <?php if (
                    $search !== '' ||
                    $statusFilter !== '' ||
                    $methodFilter !== '' ||
                    $channelFilter !== '' ||
                    $clientFilter > 0 ||
                    $invoiceFilter > 0 ||
                    $dateFrom !== '' ||
                    $dateTo !== ''
                ): ?>
                    No payments found for the selected filters.
                <?php else: ?>
                    No payments have been recorded.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php if ($canManage): ?>
    <div
        class="py-modal"
        id="paymentModal"
        aria-hidden="true"
    >
        <div
            class="py-modal-card"
            role="dialog"
            aria-modal="true"
            aria-labelledby="paymentModalTitle"
        >
            <div class="py-modal-head">
                <div>
                    <h2 id="paymentModalTitle">
                        Select Invoice
                    </h2>

                    <p>
                        Choose an outstanding invoice before recording the payment.
                    </p>
                </div>

                <button
                    type="button"
                    class="py-modal-close"
                    id="closePaymentModal"
                    aria-label="Close"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="py-modal-body">
                <div class="py-modal-search">
                    <input
                        type="search"
                        id="outstandingInvoiceSearch"
                        class="py-input"
                        placeholder="Search invoice number, customer, phone, or balance"
                    >
                </div>

                <?php if (
                    !empty($outstandingInvoices)
                ): ?>
                    <div
                        class="py-invoice-list"
                        id="outstandingInvoiceList"
                    >
                        <?php foreach (
                            $outstandingInvoices as
                            $outstandingInvoice
                        ): ?>
                            <?php
                            $outstandingCurrency =
                                trim(
                                    (string) $outstandingInvoice[
                                        'currency_code'
                                    ]
                                ) !== ''
                                    ? $outstandingInvoice[
                                        'currency_code'
                                    ]
                                    : $tenantCurrency;

                            $invoiceSearchText =
                                strtolower(
                                    implode(
                                        ' ',
                                        array(
                                            $outstandingInvoice[
                                                'invoice_no'
                                            ],
                                            $outstandingInvoice[
                                                'client_name'
                                            ],
                                            $outstandingInvoice[
                                                'client_company'
                                            ],
                                            $outstandingInvoice[
                                                'client_phone'
                                            ],
                                            $outstandingInvoice[
                                                'balance_due'
                                            ],
                                            $outstandingInvoice[
                                                'status'
                                            ]
                                        )
                                    )
                                );
                            ?>

                            <button
                                type="button"
                                class="py-invoice-option outstanding-invoice-option"
                                data-search="<?= e(
                                    $invoiceSearchText
                                ); ?>"
                                data-url="payment-add.php?invoice_id=<?= (int) $outstandingInvoice[
                                    'id'
                                ]; ?>"
                            >
                                <span>
                                    <span class="py-invoice-name">
                                        <?= e(
                                            $outstandingInvoice[
                                                'invoice_no'
                                            ]
                                        ); ?>
                                        ·
                                        <?= e(
                                            $outstandingInvoice[
                                                'client_name'
                                            ]
                                        ); ?>
                                    </span>

                                    <span class="py-invoice-meta">
                                        <?= e(
                                            paymentsLabel(
                                                $outstandingInvoice[
                                                    'status'
                                                ]
                                            )
                                        ); ?>
                                        · Invoice total
                                        <?= e(
                                            paymentsMoney(
                                                $outstandingInvoice[
                                                    'total'
                                                ],
                                                $outstandingCurrency
                                            )
                                        ); ?>
                                        · Paid
                                        <?= e(
                                            paymentsMoney(
                                                $outstandingInvoice[
                                                    'amount_paid'
                                                ],
                                                $outstandingCurrency
                                            )
                                        ); ?>
                                    </span>
                                </span>

                                <span>
                                    <span class="py-invoice-balance">
                                        <?= e(
                                            paymentsMoney(
                                                $outstandingInvoice[
                                                    'balance_due'
                                                ],
                                                $outstandingCurrency
                                            )
                                        ); ?>
                                    </span>

                                    <span class="py-invoice-due">
                                        Due:
                                        <?= e(
                                            paymentsDate(
                                                $outstandingInvoice[
                                                    'due_date'
                                                ]
                                            )
                                        ); ?>
                                    </span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div
                        class="py-modal-empty"
                        id="invoiceSearchEmpty"
                        style="display:none;"
                    >
                        No outstanding invoices match the search.
                    </div>
                <?php else: ?>
                    <div class="py-modal-empty">
                        There are no outstanding invoices available for payment.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        'use strict';

        var filterForm =
            document.getElementById(
                'paymentFilters'
            );

        var searchInput =
            document.getElementById(
                'paymentSearch'
            );

        var submitTimer = null;

        document.addEventListener(
            'fieldplx:search',
            function (event) {
                if (
                    !filterForm ||
                    !searchInput ||
                    !event.detail
                ) {
                    return;
                }

                searchInput.value =
                    event.detail.value || '';

                window.clearTimeout(
                    submitTimer
                );

                submitTimer =
                    window.setTimeout(
                        function () {
                            filterForm.submit();
                        },
                        250
                    );
            }
        );

        var modal =
            document.getElementById(
                'paymentModal'
            );

        var openButton =
            document.getElementById(
                'openPaymentModal'
            );

        var closeButton =
            document.getElementById(
                'closePaymentModal'
            );

        function openModal() {
            if (!modal) {
                return;
            }

            modal.classList.add('open');
            modal.setAttribute(
                'aria-hidden',
                'false'
            );

            document.body.style.overflow =
                'hidden';

            var invoiceSearch =
                document.getElementById(
                    'outstandingInvoiceSearch'
                );

            if (invoiceSearch) {
                window.setTimeout(
                    function () {
                        invoiceSearch.focus();
                    },
                    50
                );
            }
        }

        function closeModal() {
            if (!modal) {
                return;
            }

            modal.classList.remove('open');
            modal.setAttribute(
                'aria-hidden',
                'true'
            );

            document.body.style.overflow =
                '';
        }

        if (openButton) {
            openButton.addEventListener(
                'click',
                openModal
            );
        }

        if (closeButton) {
            closeButton.addEventListener(
                'click',
                closeModal
            );
        }

        if (modal) {
            modal.addEventListener(
                'click',
                function (event) {
                    if (event.target === modal) {
                        closeModal();
                    }
                }
            );
        }

        document.addEventListener(
            'keydown',
            function (event) {
                if (
                    event.key === 'Escape' &&
                    modal &&
                    modal.classList.contains(
                        'open'
                    )
                ) {
                    closeModal();
                }
            }
        );

        document.querySelectorAll(
            '.outstanding-invoice-option'
        ).forEach(
            function (option) {
                option.addEventListener(
                    'click',
                    function () {
                        var url =
                            option.dataset.url;

                        if (url) {
                            window.location.href =
                                url;
                        }
                    }
                );
            }
        );

        var outstandingSearch =
            document.getElementById(
                'outstandingInvoiceSearch'
            );

        var outstandingEmpty =
            document.getElementById(
                'invoiceSearchEmpty'
            );

        if (outstandingSearch) {
            outstandingSearch.addEventListener(
                'input',
                function () {
                    var query =
                        outstandingSearch.value
                            .trim()
                            .toLowerCase();

                    var visibleCount = 0;

                    document.querySelectorAll(
                        '.outstanding-invoice-option'
                    ).forEach(
                        function (option) {
                            var haystack =
                                String(
                                    option.dataset
                                        .search || ''
                                ).toLowerCase();

                            var visible =
                                query === '' ||
                                haystack.indexOf(
                                    query
                                ) !== -1;

                            option.style.display =
                                visible
                                    ? ''
                                    : 'none';

                            if (visible) {
                                visibleCount++;
                            }
                        }
                    );

                    if (outstandingEmpty) {
                        outstandingEmpty.style
                            .display =
                            visibleCount === 0
                                ? 'block'
                                : 'none';
                    }
                }
            );
        }
    }
);
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
