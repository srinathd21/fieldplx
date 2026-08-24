<?php
/**
 * FieldPlx - Invoices List
 *
 * Upload as:
 * /public_html/invoices.php
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
    header(
        'Location: login.php?redirect=' .
        rawurlencode('invoices.php')
    );
    exit;
}

if (function_exists('requirePermission')) {
    requirePermission(
        'invoices.view',
        'You do not have permission to view invoices.'
    );
}

$pageTitle = 'Invoices - FieldPlx';
$activePage = 'invoices';
$searchPlaceholder = 'Search invoices...';
$basePath = '';

$tenantId = (int) $_SESSION['tenant_id'];
$errors = array();

$canManageInvoices = function_exists('hasPermission')
    ? hasPermission('invoices.manage')
    : true;

$canRecordPayments = function_exists('hasPermission')
    ? hasPermission('payments.manage')
    : true;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('invoicesFetchAssoc')) {
    function invoicesFetchAssoc(mysqli_stmt $stmt)
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

if (!function_exists('invoicesFetchAll')) {
    function invoicesFetchAll(mysqli_stmt $stmt)
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

if (!function_exists('invoicesBindParams')) {
    function invoicesBindParams(
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

if (!function_exists('invoicesDate')) {
    function invoicesDate($value)
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

if (!function_exists('invoicesDateTime')) {
    function invoicesDateTime($value)
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

if (!function_exists('invoicesLabel')) {
    function invoicesLabel($value)
    {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string) $value
            )
        );
    }
}

if (!function_exists('invoicesClass')) {
    function invoicesClass($value)
    {
        return preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(
                trim((string) $value)
            )
        );
    }
}

if (!function_exists('invoicesMoney')) {
    function invoicesMoney($amount, $currencyCode)
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

        return $prefix . number_format(
            (float) $amount,
            2
        );
    }
}

if (!function_exists('invoicesQueryString')) {
    function invoicesQueryString(array $overrides = array())
    {
        $query = $_GET;

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
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
| Currency
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
    $stmt->bind_param('i', $tenantId);

    if ($stmt->execute()) {
        $row = invoicesFetchAssoc($stmt);

        if (
            $row &&
            trim((string) $row['currency_code']) !== ''
        ) {
            $currencyCode = strtoupper(
                trim((string) $row['currency_code'])
            );
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Refresh derived overdue statuses
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    UPDATE invoices
    SET
        status = 'overdue',
        updated_at = NOW()
    WHERE tenant_id = ?
      AND archived_at IS NULL
      AND due_date IS NOT NULL
      AND due_date < CURDATE()
      AND balance_due > 0
      AND status IN (
          'sent',
          'viewed',
          'partially_paid'
      )
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
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

$clientFilter = isset($_GET['client_id'])
    ? (int) $_GET['client_id']
    : 0;

$jobFilter = isset($_GET['job_id'])
    ? (int) $_GET['job_id']
    : 0;

$dueFilter = isset($_GET['due'])
    ? trim((string) $_GET['due'])
    : '';

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
    'draft',
    'sent',
    'viewed',
    'partially_paid',
    'paid',
    'overdue',
    'written_off',
    'cancelled'
);

$allowedDueFilters = array(
    '',
    'outstanding',
    'overdue',
    'today',
    'upcoming',
    'no_due',
    'paid'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'issue_desc',
    'issue_asc',
    'due_asc',
    'due_desc',
    'invoice_asc',
    'client_asc',
    'total_desc',
    'balance_desc',
    'status_asc'
);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if (!in_array($dueFilter, $allowedDueFilters, true)) {
    $dueFilter = '';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

if (
    $dateFrom !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
) {
    $dateFrom = '';
}

if (
    $dateTo !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
) {
    $dateTo = '';
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 20;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Filter options
|--------------------------------------------------------------------------
*/

$clientOptions = array();
$jobOptions = array();

$stmt = $conn->prepare("
    SELECT
        id,
        display_name
    FROM clients
    WHERE tenant_id = ?
      AND deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY display_name ASC
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $clientOptions = invoicesFetchAll($stmt);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT
        id,
        job_no,
        title
    FROM jobs
    WHERE tenant_id = ?
      AND deleted_at IS NULL
    ORDER BY created_at DESC, id DESC
    LIMIT 1000
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $jobOptions = invoicesFetchAll($stmt);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Summary statistics
|--------------------------------------------------------------------------
*/

$stats = array(
    'total' => 0,
    'draft' => 0,
    'outstanding' => 0,
    'overdue' => 0,
    'invoice_value' => 0.00,
    'balance_due' => 0.00
);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(status = 'draft') AS draft_count,
        SUM(
            balance_due > 0
            AND status NOT IN (
                'draft',
                'paid',
                'written_off',
                'cancelled',
                'archived'
            )
        ) AS outstanding_count,
        SUM(
            balance_due > 0
            AND due_date IS NOT NULL
            AND due_date < CURDATE()
            AND status NOT IN (
                'paid',
                'written_off',
                'cancelled',
                'archived'
            )
        ) AS overdue_count,
        SUM(
            CASE
                WHEN status NOT IN (
                    'cancelled',
                    'archived'
                )
                THEN total
                ELSE 0
            END
        ) AS invoice_value,
        SUM(
            CASE
                WHEN status NOT IN (
                    'written_off',
                    'cancelled',
                    'archived'
                )
                THEN balance_due
                ELSE 0
            END
        ) AS balance_due_value
    FROM invoices
    WHERE tenant_id = ?
      AND archived_at IS NULL
      AND status <> 'archived'
");

if ($stmt) {
    $stmt->bind_param('i', $tenantId);

    if ($stmt->execute()) {
        $row = invoicesFetchAssoc($stmt);

        if ($row) {
            $stats['total'] =
                (int) $row['total_count'];

            $stats['draft'] =
                (int) $row['draft_count'];

            $stats['outstanding'] =
                (int) $row['outstanding_count'];

            $stats['overdue'] =
                (int) $row['overdue_count'];

            $stats['invoice_value'] =
                (float) $row['invoice_value'];

            $stats['balance_due'] =
                (float) $row['balance_due_value'];
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Build filtered query
|--------------------------------------------------------------------------
*/

$where = array(
    'i.tenant_id = ?',
    'i.archived_at IS NULL',
    "i.status <> 'archived'"
);

$params = array($tenantId);
$types = 'i';

if ($search !== '') {
    $where[] = "(
        i.invoice_no LIKE ?
        OR i.notes LIKE ?
        OR i.payment_terms LIKE ?
        OR c.display_name LIKE ?
        OR c.phone LIKE ?
        OR c.email LIKE ?
        OR j.job_no LIKE ?
        OR j.title LIKE ?
        OR p.name LIKE ?
        OR p.address_line1 LIKE ?
        OR p.city LIKE ?
        OR q.quote_no LIKE ?
        OR q.title LIKE ?
    )";

    $searchLike = '%' . $search . '%';

    for ($index = 0; $index < 13; $index++) {
        $params[] = $searchLike;
        $types .= 's';
    }
}

if ($statusFilter !== '') {
    $where[] = 'i.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($clientFilter > 0) {
    $where[] = 'i.client_id = ?';
    $params[] = $clientFilter;
    $types .= 'i';
}

if ($jobFilter > 0) {
    $where[] = 'i.job_id = ?';
    $params[] = $jobFilter;
    $types .= 'i';
}

if ($dueFilter === 'outstanding') {
    $where[] = "
        i.balance_due > 0
        AND i.status NOT IN (
            'draft',
            'paid',
            'written_off',
            'cancelled',
            'archived'
        )
    ";
} elseif ($dueFilter === 'overdue') {
    $where[] = "
        i.balance_due > 0
        AND i.due_date IS NOT NULL
        AND i.due_date < CURDATE()
        AND i.status NOT IN (
            'paid',
            'written_off',
            'cancelled',
            'archived'
        )
    ";
} elseif ($dueFilter === 'today') {
    $where[] = "
        i.balance_due > 0
        AND i.due_date = CURDATE()
        AND i.status NOT IN (
            'paid',
            'written_off',
            'cancelled',
            'archived'
        )
    ";
} elseif ($dueFilter === 'upcoming') {
    $where[] = "
        i.balance_due > 0
        AND i.due_date > CURDATE()
        AND i.status NOT IN (
            'paid',
            'written_off',
            'cancelled',
            'archived'
        )
    ";
} elseif ($dueFilter === 'no_due') {
    $where[] = 'i.due_date IS NULL';
} elseif ($dueFilter === 'paid') {
    $where[] = "(
        i.status = 'paid'
        OR i.balance_due <= 0
    )";
}

if ($dateFrom !== '') {
    $where[] = 'i.issue_date >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = 'i.issue_date <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$orderSql = 'i.created_at DESC, i.id DESC';

if ($sort === 'oldest') {
    $orderSql = 'i.created_at ASC, i.id ASC';
} elseif ($sort === 'issue_desc') {
    $orderSql = "
        CASE
            WHEN i.issue_date IS NULL THEN 1
            ELSE 0
        END ASC,
        i.issue_date DESC,
        i.id DESC
    ";
} elseif ($sort === 'issue_asc') {
    $orderSql = "
        CASE
            WHEN i.issue_date IS NULL THEN 1
            ELSE 0
        END ASC,
        i.issue_date ASC,
        i.id ASC
    ";
} elseif ($sort === 'due_asc') {
    $orderSql = "
        CASE
            WHEN i.due_date IS NULL THEN 1
            ELSE 0
        END ASC,
        i.due_date ASC,
        i.id DESC
    ";
} elseif ($sort === 'due_desc') {
    $orderSql = "
        CASE
            WHEN i.due_date IS NULL THEN 1
            ELSE 0
        END ASC,
        i.due_date DESC,
        i.id DESC
    ";
} elseif ($sort === 'invoice_asc') {
    $orderSql = 'i.invoice_no ASC, i.id ASC';
} elseif ($sort === 'client_asc') {
    $orderSql = 'c.display_name ASC, i.created_at DESC';
} elseif ($sort === 'total_desc') {
    $orderSql = 'i.total DESC, i.created_at DESC';
} elseif ($sort === 'balance_desc') {
    $orderSql = 'i.balance_due DESC, i.created_at DESC';
} elseif ($sort === 'status_asc') {
    $orderSql = 'i.status ASC, i.created_at DESC';
}

/*
|--------------------------------------------------------------------------
| Count filtered rows
|--------------------------------------------------------------------------
*/

$totalFiltered = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM invoices i

    INNER JOIN clients c
        ON c.id = i.client_id
       AND c.tenant_id = i.tenant_id

    LEFT JOIN properties p
        ON p.id = i.property_id
       AND p.tenant_id = i.tenant_id

    LEFT JOIN jobs j
        ON j.id = i.job_id
       AND j.tenant_id = i.tenant_id

    LEFT JOIN quotes q
        ON q.id = i.quote_id
       AND q.tenant_id = i.tenant_id

    WHERE {$whereSql}
";

$stmt = $conn->prepare($countSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare the invoice count query: ' .
        $conn->error;
} else {
    if (
        !invoicesBindParams(
            $stmt,
            $types,
            $params
        )
    ) {
        $errors[] =
            'Unable to bind invoice filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to count invoices: ' .
            $stmt->error;
    } else {
        $row = invoicesFetchAssoc($stmt);

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
    $offset = ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Load invoices
|--------------------------------------------------------------------------
*/

$invoices = array();

$listSql = "
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
        c.phone AS client_phone,
        c.email AS client_email,

        p.name AS property_name,
        p.address_line1 AS property_address,
        p.city AS property_city,
        p.state AS property_state,
        p.postal_code AS property_postal_code,

        j.job_no,
        j.title AS job_title,
        j.status AS job_status,

        q.quote_no,
        q.title AS quote_title,

        CONCAT(
            COALESCE(cu.first_name, ''),
            CASE
                WHEN cu.last_name IS NOT NULL
                 AND cu.last_name <> ''
                THEN CONCAT(' ', cu.last_name)
                ELSE ''
            END
        ) AS created_by_name,

        (
            SELECT COUNT(*)
            FROM invoice_line_items ili
            WHERE ili.invoice_id = i.id
              AND ili.tenant_id = i.tenant_id
        ) AS line_item_count

    FROM invoices i

    INNER JOIN clients c
        ON c.id = i.client_id
       AND c.tenant_id = i.tenant_id

    LEFT JOIN properties p
        ON p.id = i.property_id
       AND p.tenant_id = i.tenant_id

    LEFT JOIN jobs j
        ON j.id = i.job_id
       AND j.tenant_id = i.tenant_id

    LEFT JOIN quotes q
        ON q.id = i.quote_id
       AND q.tenant_id = i.tenant_id

    LEFT JOIN users cu
        ON cu.id = i.created_by
       AND cu.tenant_id = i.tenant_id
       AND cu.deleted_at IS NULL

    WHERE {$whereSql}

    ORDER BY {$orderSql}

    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($listSql);

if (!$stmt) {
    $errors[] =
        'Unable to prepare the invoice list query: ' .
        $conn->error;
} else {
    $listParams = $params;
    $listTypes = $types . 'ii';

    $listParams[] = $perPage;
    $listParams[] = $offset;

    if (
        !invoicesBindParams(
            $stmt,
            $listTypes,
            $listParams
        )
    ) {
        $errors[] =
            'Unable to bind the invoice list filters: ' .
            $stmt->error;
    } elseif (!$stmt->execute()) {
        $errors[] =
            'Unable to load invoices: ' .
            $stmt->error;
    } else {
        $invoices =
            invoicesFetchAll($stmt);
    }

    $stmt->close();
}

require_once __DIR__ . '/includes/topbar.php';
?>

<style>
.invoices-page {
    --iv-primary: #6d28d9;
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

.iv-header h1 {
    margin: 0;
    color: var(--iv-text);
    font-size: 21px;
    font-weight: 700;
}

.iv-header p {
    margin: 5px 0 0;
    color: var(--iv-muted);
    font-size: 11px;
}

.iv-add {
    min-height: 35px;
    padding: 8px 13px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 9px;
    background: var(--iv-primary);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
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

.iv-stats {
    margin-bottom: 13px;
    display: grid;
    grid-template-columns: repeat(6,minmax(0,1fr));
    gap: 10px;
}

.iv-stat {
    padding: 13px;
    border: 1px solid var(--iv-border);
    border-radius: 11px;
    background: #fff;
}

.iv-stat-label {
    color: #9ca3af;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.iv-stat-value {
    margin-top: 4px;
    color: var(--iv-text);
    font-size: 19px;
    font-weight: 700;
}

.iv-stat-value.money {
    font-size: 16px;
}

.iv-panel {
    overflow: hidden;
    border: 1px solid var(--iv-border);
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(15,23,42,.035);
}

.iv-filters {
    padding: 12px;
    display: grid;
    grid-template-columns: repeat(5,minmax(0,1fr));
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
}

.iv-search {
    grid-column: span 2;
}

.iv-input,
.iv-select {
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

.iv-input:focus,
.iv-select:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.08);
}

.iv-filter-actions {
    display: flex;
    gap: 6px;
}

.iv-filter-btn,
.iv-reset {
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

.iv-filter-btn {
    border: 0;
    background: var(--iv-primary);
    color: #fff;
    cursor: pointer;
}

.iv-reset {
    border: 1px solid var(--iv-border);
    background: #fff;
    color: #4b5563;
    text-decoration: none;
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
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    white-space: nowrap;
    vertical-align: middle;
}

.iv-table th {
    background: #fafafa;
    color: #6b7280;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
}

.iv-table td {
    color: #374151;
    font-size: 9px;
}

.iv-main {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.iv-sub {
    margin-top: 2px;
    display: block;
    max-width: 250px;
    overflow: hidden;
    color: #9ca3af;
    font-size: 8px;
    line-height: 1.4;
    text-overflow: ellipsis;
}

.iv-money {
    color: #111827;
    font-size: 9px;
    font-weight: 700;
}

.iv-money.balance {
    color: #b45309;
}

.iv-money.paid {
    color: #047857;
}

.iv-badge {
    padding: 4px 7px;
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
    font-size: 8px;
    font-weight: 700;
}

.iv-badge.draft {
    background: #f3f4f6;
    color: #4b5563;
}

.iv-badge.sent,
.iv-badge.viewed {
    background: #eff6ff;
    color: #1d4ed8;
}

.iv-badge.partially_paid {
    background: #fff7ed;
    color: #c2410c;
}

.iv-badge.paid {
    background: #ecfdf5;
    color: #047857;
}

.iv-badge.overdue {
    background: #fef2f2;
    color: #b91c1c;
}

.iv-badge.written_off,
.iv-badge.cancelled,
.iv-badge.archived {
    background: #f3f4f6;
    color: #6b7280;
}

.iv-overdue {
    margin-left: 5px;
    padding: 3px 6px;
    display: inline-flex;
    border-radius: 999px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 7px;
    font-weight: 700;
}

.iv-actions {
    display: flex;
    justify-content: flex-end;
    gap: 5px;
}

.iv-action {
    width: 29px;
    height: 29px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--iv-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    text-decoration: none;
}

.iv-action:hover {
    border-color: #c4b5fd;
    background: #faf8ff;
    color: var(--iv-primary);
}

.iv-action.payment {
    border-color: #bbf7d0;
    color: #047857;
}

.iv-footer {
    padding: 11px 13px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #f1f5f9;
}

.iv-result {
    color: #6b7280;
    font-size: 9px;
}

.iv-pages {
    display: flex;
    gap: 5px;
}

.iv-page {
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--iv-border);
    border-radius: 8px;
    background: #fff;
    color: #4b5563;
    font-size: 9px;
    font-weight: 700;
    text-decoration: none;
}

.iv-page.active {
    border-color: var(--iv-primary);
    background: var(--iv-primary);
    color: #fff;
}

.iv-empty {
    padding: 42px 15px;
    color: #9ca3af;
    font-size: 10px;
    text-align: center;
}

@media (max-width: 1180px) {
    .iv-filters {
        grid-template-columns: repeat(4,minmax(0,1fr));
    }

    .iv-search {
        grid-column: span 2;
    }
}

@media (max-width: 1050px) {
    .iv-stats {
        grid-template-columns: repeat(3,minmax(0,1fr));
    }
}

@media (max-width: 760px) {
    .iv-header {
        flex-direction: column;
    }

    .iv-filters {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }

    .iv-search {
        grid-column: span 2;
    }
}

@media (max-width: 560px) {
    .iv-stats,
    .iv-filters {
        grid-template-columns: 1fr;
    }

    .iv-search {
        grid-column: auto;
    }

    .iv-filter-actions {
        width: 100%;
    }

    .iv-filter-btn,
    .iv-reset {
        flex: 1;
    }

    .iv-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="invoices-page">
    <div class="iv-header">
        <div>
            <h1>Invoices</h1>
            <p>
                Manage issued invoices, payments, outstanding balances, and due dates.
            </p>
        </div>

        <?php if ($canManageInvoices): ?>
            <a href="invoice-add.php" class="iv-add">
                <i class="bi bi-plus-lg"></i>
                Add Invoice
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="iv-alert success">
            <?= e($_SESSION['flash_success']); ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="iv-alert error">
            <?= e($_SESSION['flash_error']); ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="iv-alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="iv-stats">
        <article class="iv-stat">
            <div class="iv-stat-label">Total Invoices</div>
            <div class="iv-stat-value">
                <?= e($stats['total']); ?>
            </div>
        </article>

        <article class="iv-stat">
            <div class="iv-stat-label">Draft</div>
            <div class="iv-stat-value">
                <?= e($stats['draft']); ?>
            </div>
        </article>

        <article class="iv-stat">
            <div class="iv-stat-label">Outstanding</div>
            <div class="iv-stat-value">
                <?= e($stats['outstanding']); ?>
            </div>
        </article>

        <article class="iv-stat">
            <div class="iv-stat-label">Overdue</div>
            <div class="iv-stat-value">
                <?= e($stats['overdue']); ?>
            </div>
        </article>

        <article class="iv-stat">
            <div class="iv-stat-label">Invoice Value</div>
            <div class="iv-stat-value money">
                <?= e(
                    invoicesMoney(
                        $stats['invoice_value'],
                        $currencyCode
                    )
                ); ?>
            </div>
        </article>

        <article class="iv-stat">
            <div class="iv-stat-label">Balance Due</div>
            <div class="iv-stat-value money">
                <?= e(
                    invoicesMoney(
                        $stats['balance_due'],
                        $currencyCode
                    )
                ); ?>
            </div>
        </article>
    </section>

    <section class="iv-panel">
        <form
            method="get"
            action=""
            class="iv-filters"
            id="invoiceFilters"
        >
            <input
                type="search"
                name="search"
                id="invoiceSearch"
                class="iv-input iv-search"
                value="<?= e($search); ?>"
                placeholder="Search invoice, client, job, quote, property or notes"
            >

            <select name="status" class="iv-select">
                <option value="">All Statuses</option>

                <?php foreach (
                    array(
                        'draft' => 'Draft',
                        'sent' => 'Sent',
                        'viewed' => 'Viewed',
                        'partially_paid' => 'Partially Paid',
                        'paid' => 'Paid',
                        'overdue' => 'Overdue',
                        'written_off' => 'Written Off',
                        'cancelled' => 'Cancelled'
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

            <select name="client_id" class="iv-select">
                <option value="">All Clients</option>

                <?php foreach ($clientOptions as $client): ?>
                    <option
                        value="<?= (int) $client['id']; ?>"
                        <?= $clientFilter === (int) $client['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($client['display_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="job_id" class="iv-select">
                <option value="">All Jobs</option>

                <?php foreach ($jobOptions as $job): ?>
                    <option
                        value="<?= (int) $job['id']; ?>"
                        <?= $jobFilter === (int) $job['id']
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= e($job['job_no']); ?>
                        - <?= e($job['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="due" class="iv-select">
                <option value="">All Due States</option>
                <option value="outstanding" <?= $dueFilter === 'outstanding' ? 'selected' : ''; ?>>
                    Outstanding
                </option>
                <option value="overdue" <?= $dueFilter === 'overdue' ? 'selected' : ''; ?>>
                    Overdue
                </option>
                <option value="today" <?= $dueFilter === 'today' ? 'selected' : ''; ?>>
                    Due Today
                </option>
                <option value="upcoming" <?= $dueFilter === 'upcoming' ? 'selected' : ''; ?>>
                    Upcoming
                </option>
                <option value="no_due" <?= $dueFilter === 'no_due' ? 'selected' : ''; ?>>
                    No Due Date
                </option>
                <option value="paid" <?= $dueFilter === 'paid' ? 'selected' : ''; ?>>
                    Paid
                </option>
            </select>

            <input
                type="date"
                name="date_from"
                class="iv-input"
                value="<?= e($dateFrom); ?>"
                title="Issue date from"
            >

            <input
                type="date"
                name="date_to"
                class="iv-input"
                value="<?= e($dateTo); ?>"
                title="Issue date to"
            >

            <select name="sort" class="iv-select">
                <option value="latest" <?= $sort === 'latest' ? 'selected' : ''; ?>>
                    Latest Created
                </option>
                <option value="oldest" <?= $sort === 'oldest' ? 'selected' : ''; ?>>
                    Oldest Created
                </option>
                <option value="issue_desc" <?= $sort === 'issue_desc' ? 'selected' : ''; ?>>
                    Latest Issue Date
                </option>
                <option value="issue_asc" <?= $sort === 'issue_asc' ? 'selected' : ''; ?>>
                    Oldest Issue Date
                </option>
                <option value="due_asc" <?= $sort === 'due_asc' ? 'selected' : ''; ?>>
                    Due Date Ascending
                </option>
                <option value="due_desc" <?= $sort === 'due_desc' ? 'selected' : ''; ?>>
                    Due Date Descending
                </option>
                <option value="invoice_asc" <?= $sort === 'invoice_asc' ? 'selected' : ''; ?>>
                    Invoice Number
                </option>
                <option value="client_asc" <?= $sort === 'client_asc' ? 'selected' : ''; ?>>
                    Client A-Z
                </option>
                <option value="total_desc" <?= $sort === 'total_desc' ? 'selected' : ''; ?>>
                    Highest Total
                </option>
                <option value="balance_desc" <?= $sort === 'balance_desc' ? 'selected' : ''; ?>>
                    Highest Balance
                </option>
                <option value="status_asc" <?= $sort === 'status_asc' ? 'selected' : ''; ?>>
                    Status
                </option>
            </select>

            <div class="iv-filter-actions">
                <button type="submit" class="iv-filter-btn">
                    Apply
                </button>

                <a href="invoices.php" class="iv-reset">
                    Reset
                </a>
            </div>
        </form>

        <?php if (!empty($invoices)): ?>
            <div class="iv-table-wrap">
                <table class="iv-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Client</th>
                            <th>Job / Property</th>
                            <th>Issue / Due</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                        $invoiceId =
                            (int) $invoice['id'];

                        $isOverdue =
                            (float) $invoice['balance_due'] > 0 &&
                            !empty($invoice['due_date']) &&
                            strtotime((string) $invoice['due_date']) <
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

                        $displayStatus = $isOverdue
                            ? 'overdue'
                            : $invoice['status'];

                        $propertyTitle =
                            trim((string) $invoice['property_name']) !== ''
                                ? (string) $invoice['property_name']
                                : (
                                    trim((string) $invoice['property_address']) !== ''
                                        ? (string) $invoice['property_address']
                                        : ''
                                );

                        $propertyLocation = trim(
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
                            )
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
                        ?>

                        <tr>
                            <td>
                                <a
                                    href="invoice-view.php?id=<?= $invoiceId; ?>"
                                    class="iv-main"
                                >
                                    <?= e($invoice['invoice_no']); ?>
                                </a>

                                <span class="iv-sub">
                                    <?= e(
                                        (int) $invoice['line_item_count']
                                    ); ?>
                                    item<?= (int) $invoice['line_item_count'] === 1 ? '' : 's'; ?>

                                    <?php if (
                                        trim((string) $invoice['payment_terms']) !== ''
                                    ): ?>
                                        - <?= e($invoice['payment_terms']); ?>
                                    <?php endif; ?>
                                </span>
                            </td>

                            <td>
                                <a
                                    href="client-view.php?id=<?= (int) $invoice['client_id']; ?>"
                                    class="iv-main"
                                >
                                    <?= e($invoice['client_name']); ?>
                                </a>

                                <?php if (
                                    trim((string) $invoice['client_phone']) !== ''
                                ): ?>
                                    <span class="iv-sub">
                                        <?= e($invoice['client_phone']); ?>
                                    </span>
                                <?php elseif (
                                    trim((string) $invoice['client_email']) !== ''
                                ): ?>
                                    <span class="iv-sub">
                                        <?= e($invoice['client_email']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($invoice['job_id'])): ?>
                                    <a
                                        href="job-view.php?id=<?= (int) $invoice['job_id']; ?>"
                                        class="iv-main"
                                    >
                                        <?= e($invoice['job_no']); ?>
                                    </a>

                                    <span class="iv-sub">
                                        <?= e($invoice['job_title']); ?>
                                    </span>
                                <?php elseif (!empty($invoice['property_id'])): ?>
                                    <a
                                        href="property-view.php?id=<?= (int) $invoice['property_id']; ?>"
                                        class="iv-main"
                                    >
                                        <?= e($propertyTitle); ?>
                                    </a>

                                    <?php if ($propertyLocation !== ''): ?>
                                        <span class="iv-sub">
                                            <?= e($propertyLocation); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php elseif (!empty($invoice['quote_id'])): ?>
                                    <a
                                        href="quote-view.php?id=<?= (int) $invoice['quote_id']; ?>"
                                        class="iv-main"
                                    >
                                        <?= e($invoice['quote_no']); ?>
                                    </a>

                                    <span class="iv-sub">
                                        <?= e(
                                            trim((string) $invoice['quote_title']) !== ''
                                                ? $invoice['quote_title']
                                                : 'Related quote'
                                        ); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="iv-main">Not linked</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="iv-main">
                                    <?= e(
                                        invoicesDate(
                                            $invoice['issue_date']
                                        )
                                    ); ?>
                                </span>

                                <span class="iv-sub">
                                    Due:
                                    <?= e(
                                        invoicesDate(
                                            $invoice['due_date']
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="iv-money">
                                    <?= e(
                                        invoicesMoney(
                                            $invoice['total'],
                                            $currencyCode
                                        )
                                    ); ?>
                                </span>

                                <?php if ((float) $invoice['tax_total'] > 0): ?>
                                    <span class="iv-sub">
                                        Tax:
                                        <?= e(
                                            invoicesMoney(
                                                $invoice['tax_total'],
                                                $currencyCode
                                            )
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="iv-money paid">
                                    <?= e(
                                        invoicesMoney(
                                            $invoice['amount_paid'],
                                            $currencyCode
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="iv-money <?= (float) $invoice['balance_due'] > 0
                                    ? 'balance'
                                    : 'paid'; ?>">
                                    <?= e(
                                        invoicesMoney(
                                            $invoice['balance_due'],
                                            $currencyCode
                                        )
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="iv-badge <?= e(
                                    invoicesClass(
                                        $displayStatus
                                    )
                                ); ?>">
                                    <?= e(
                                        invoicesLabel(
                                            $displayStatus
                                        )
                                    ); ?>
                                </span>

                                <?php if (
                                    $isOverdue &&
                                    $invoice['status'] !== 'overdue'
                                ): ?>
                                    <span class="iv-overdue">
                                        Overdue
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="iv-main">
                                    <?= e(
                                        invoicesDate(
                                            $invoice['created_at']
                                        )
                                    ); ?>
                                </span>

                                <?php if (
                                    trim((string) $invoice['created_by_name']) !== ''
                                ): ?>
                                    <span class="iv-sub">
                                        <?= e($invoice['created_by_name']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="iv-actions">
                                    <a
                                        href="invoice-view.php?id=<?= $invoiceId; ?>"
                                        class="iv-action"
                                        title="View Invoice"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($canManageInvoices): ?>
                                        <a
                                            href="invoice-edit.php?id=<?= $invoiceId; ?>"
                                            class="iv-action"
                                            title="Edit Invoice"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canPayInvoice): ?>
                                        <a
                                            href="payment-add.php?invoice_id=<?= $invoiceId; ?>"
                                            class="iv-action payment"
                                            title="Record Payment"
                                        >
                                            <i class="bi bi-cash-coin"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($invoice['job_id'])): ?>
                                        <a
                                            href="job-view.php?id=<?= (int) $invoice['job_id']; ?>"
                                            class="iv-action"
                                            title="View Job"
                                        >
                                            <i class="bi bi-briefcase"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="iv-footer">
                <div class="iv-result">
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
                            $offset + count($invoices)
                        )
                    ); ?>
                    of
                    <?= e($totalFiltered); ?>
                    invoices
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="iv-pages">
                        <?php if ($page > 1): ?>
                            <a
                                href="?<?= e(
                                    invoicesQueryString(
                                        array(
                                            'page' => $page - 1
                                        )
                                    )
                                ); ?>"
                                class="iv-page"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min(
                            $totalPages,
                            $page + 2
                        );

                        for (
                            $pageNumber = $startPage;
                            $pageNumber <= $endPage;
                            $pageNumber++
                        ):
                        ?>
                            <a
                                href="?<?= e(
                                    invoicesQueryString(
                                        array(
                                            'page' => $pageNumber
                                        )
                                    )
                                ); ?>"
                                class="iv-page <?= $pageNumber === $page
                                    ? 'active'
                                    : ''; ?>"
                            >
                                <?= e($pageNumber); ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a
                                href="?<?= e(
                                    invoicesQueryString(
                                        array(
                                            'page' => $page + 1
                                        )
                                    )
                                ); ?>"
                                class="iv-page"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="iv-empty">
                <?php if (
                    $search !== '' ||
                    $statusFilter !== '' ||
                    $clientFilter > 0 ||
                    $jobFilter > 0 ||
                    $dueFilter !== '' ||
                    $dateFrom !== '' ||
                    $dateTo !== ''
                ): ?>
                    No invoices found for the selected filters.
                <?php else: ?>
                    No invoices are available.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        'use strict';

        var form =
            document.getElementById(
                'invoiceFilters'
            );

        var searchInput =
            document.getElementById(
                'invoiceSearch'
            );

        var submitTimer = null;

        document.addEventListener(
            'fieldplx:search',
            function (event) {
                if (
                    !form ||
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
                            form.submit();
                        },
                        250
                    );
            }
        );
    }
);
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
