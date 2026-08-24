<?php
/**
 * FieldPlx Platform - Billing Dashboard
 *
 * File:
 * platform/billing.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - tenants
 * - plans
 * - subscriptions
 *
 * Optional support:
 * - billing_payments
 * - invoices
 */

require_once __DIR__ . '/includes/auth.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin',
    'platform_read_only'
));

$pageTitle = 'Billing - FieldPlx';
$activePage = 'billing';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('billingEscape')) {
    function billingEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('billingTableExists')) {
    function billingTableExists(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        $stmt->bind_param('s', $tableName);
        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('billingColumns')) {
    function billingColumns(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTable = str_replace(
            '`',
            '``',
            $tableName
        );

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTable}`"
        );

        while ($row = $result->fetch_assoc()) {
            $cache[$tableName][
                (string) $row['Field']
            ] = $row;
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('billingFirstColumn')) {
    function billingFirstColumn(
        array $columns,
        array $candidates
    ) {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('billingLabel')) {
    function billingLabel($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '—';
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

if (!function_exists('billingStatusClass')) {
    function billingStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
            case 'paid':
                return 'success';

            case 'trial':
                return 'info';

            case 'past_due':
            case 'pending':
            case 'partially_paid':
                return 'warning';

            case 'suspended':
            case 'cancelled':
            case 'expired':
            case 'failed':
            case 'overdue':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('billingCurrencySymbol')) {
    function billingCurrencySymbol($currency)
    {
        switch (strtoupper(trim((string) $currency))) {
            case 'USD':
                return '$';

            case 'GBP':
                return '£';

            case 'EUR':
                return '€';

            case 'CAD':
                return 'C$';

            case 'AUD':
                return 'A$';

            case 'INR':
                return '₹';

            default:
                return strtoupper(
                    trim((string) $currency)
                ) . ' ';
        }
    }
}

if (!function_exists('billingMoney')) {
    function billingMoney(
        $amount,
        $currency
    ) {
        $amount = is_numeric($amount)
            ? (float) $amount
            : 0;

        return billingCurrencySymbol($currency) .
            number_format($amount, 2);
    }
}

if (!function_exists('billingDate')) {
    function billingDate(
        $value,
        $withTime = false
    ) {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return '—';
        }

        return $withTime
            ? date('d M Y, h:i A', $timestamp)
            : date('d M Y', $timestamp);
    }
}

if (!function_exists('billingBuildQuery')) {
    function billingBuildQuery(array $changes = array())
    {
        $query = $_GET;

        foreach ($changes as $key => $value) {
            if ($value === '' || $value === null) {
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
| Verify required tables
|--------------------------------------------------------------------------
*/

$requiredTables = array(
    'subscriptions',
    'tenants',
    'plans'
);

foreach ($requiredTables as $requiredTable) {
    if (
        !billingTableExists(
            $conn,
            $requiredTable
        )
    ) {
        http_response_code(500);

        exit(
            'The ' .
            billingEscape($requiredTable) .
            ' table does not exist.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Detect structures
|--------------------------------------------------------------------------
*/

$subscriptionColumns =
    billingColumns(
        $conn,
        'subscriptions'
    );

$tenantColumns =
    billingColumns(
        $conn,
        'tenants'
    );

$planColumns =
    billingColumns(
        $conn,
        'plans'
    );

$subscriptionIdColumn =
    billingFirstColumn(
        $subscriptionColumns,
        array('id', 'subscription_id')
    );

$tenantIdColumn =
    billingFirstColumn(
        $tenantColumns,
        array('id', 'tenant_id')
    );

$tenantNameColumn =
    billingFirstColumn(
        $tenantColumns,
        array(
            'business_name',
            'name',
            'tenant_name',
            'company_name'
        )
    );

$tenantCodeColumn =
    billingFirstColumn(
        $tenantColumns,
        array(
            'tenant_code',
            'business_code',
            'code',
            'slug'
        )
    );

$planIdColumn =
    billingFirstColumn(
        $planColumns,
        array('id', 'plan_id')
    );

$planNameColumn =
    billingFirstColumn(
        $planColumns,
        array('name', 'plan_name')
    );

if (
    $subscriptionIdColumn === '' ||
    $tenantIdColumn === '' ||
    $tenantNameColumn === '' ||
    $planIdColumn === '' ||
    $planNameColumn === ''
) {
    http_response_code(500);

    exit(
        'Required billing columns are missing.'
    );
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search']) &&
    !is_array($_GET['search'])
        ? trim((string) $_GET['search'])
        : '';

$status = isset($_GET['status']) &&
    !is_array($_GET['status'])
        ? trim((string) $_GET['status'])
        : '';

$currency = isset($_GET['currency']) &&
    !is_array($_GET['currency'])
        ? strtoupper(
            trim((string) $_GET['currency'])
        )
        : '';

$cycle = isset($_GET['cycle']) &&
    !is_array($_GET['cycle'])
        ? trim((string) $_GET['cycle'])
        : '';

$sort = isset($_GET['sort']) &&
    !is_array($_GET['sort'])
        ? trim((string) $_GET['sort'])
        : 'latest';

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = isset($_GET['per_page'])
    ? (int) $_GET['per_page']
    : 15;

$allowedStatuses = array(
    'trial',
    'active',
    'past_due',
    'suspended',
    'cancelled',
    'expired'
);

$allowedCurrencies = array(
    'USD',
    'GBP',
    'EUR',
    'CAD',
    'AUD',
    'INR'
);

$allowedCycles = array(
    'monthly',
    'quarterly',
    'half_yearly',
    'yearly',
    'lifetime',
    'custom'
);

$allowedSorts = array(
    'latest',
    'oldest',
    'amount_desc',
    'amount_asc',
    'expiry_asc',
    'expiry_desc',
    'tenant_asc',
    'tenant_desc'
);

$allowedPerPage = array(
    10,
    15,
    25,
    50
);

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

if (!in_array($currency, $allowedCurrencies, true)) {
    $currency = '';
}

if (!in_array($cycle, $allowedCycles, true)) {
    $cycle = '';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 15;
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summaryResult = $conn->query("
    SELECT
        COUNT(*) AS total_count,
        SUM(
            CASE
                WHEN `status` = 'active'
                THEN 1 ELSE 0
            END
        ) AS active_count,
        SUM(
            CASE
                WHEN `status` = 'trial'
                THEN 1 ELSE 0
            END
        ) AS trial_count,
        SUM(
            CASE
                WHEN `status` = 'past_due'
                THEN 1 ELSE 0
            END
        ) AS past_due_count,
        SUM(
            CASE
                WHEN `ends_at` IS NOT NULL
                 AND `ends_at` >= NOW()
                 AND `ends_at` <
                    DATE_ADD(
                        NOW(),
                        INTERVAL 30 DAY
                    )
                 AND `status` IN (
                    'active',
                    'trial',
                    'past_due'
                 )
                THEN 1 ELSE 0
            END
        ) AS expiring_count
    FROM subscriptions
    WHERE `deleted_at` IS NULL
");

$summaryRow = $summaryResult
    ? $summaryResult->fetch_assoc()
    : array();

if ($summaryResult) {
    $summaryResult->free();
}

$summary = array(
    'total' => isset($summaryRow['total_count'])
        ? (int) $summaryRow['total_count']
        : 0,
    'active' => isset($summaryRow['active_count'])
        ? (int) $summaryRow['active_count']
        : 0,
    'trial' => isset($summaryRow['trial_count'])
        ? (int) $summaryRow['trial_count']
        : 0,
    'past_due' => isset($summaryRow['past_due_count'])
        ? (int) $summaryRow['past_due_count']
        : 0,
    'expiring' => isset($summaryRow['expiring_count'])
        ? (int) $summaryRow['expiring_count']
        : 0
);

/*
|--------------------------------------------------------------------------
| Revenue by currency
|--------------------------------------------------------------------------
*/

$currencyTotals = array();

$currencyResult = $conn->query("
    SELECT
        UPPER(`currency`) AS currency_code,
        SUM(
            CASE
                WHEN `status` IN (
                    'active',
                    'trial',
                    'past_due'
                )
                THEN `amount`
                ELSE 0
            END
        ) AS recurring_value,
        COUNT(
            CASE
                WHEN `status` IN (
                    'active',
                    'trial',
                    'past_due'
                )
                THEN 1
            END
        ) AS active_records
    FROM subscriptions
    WHERE `deleted_at` IS NULL
    GROUP BY UPPER(`currency`)
    ORDER BY recurring_value DESC
");

while (
    $currencyRow =
    $currencyResult->fetch_assoc()
) {
    $currencyTotals[] = $currencyRow;
}

$currencyResult->free();

/*
|--------------------------------------------------------------------------
| Build listing filters
|--------------------------------------------------------------------------
*/

$where = array(
    "s.`deleted_at` IS NULL"
);

$params = array();
$types = '';

if ($status !== '') {
    $where[] = "s.`status` = ?";
    $types .= 's';
    $params[] = $status;
}

if ($currency !== '') {
    $where[] = "UPPER(s.`currency`) = ?";
    $types .= 's';
    $params[] = $currency;
}

if ($cycle !== '') {
    $where[] = "s.`billing_cycle` = ?";
    $types .= 's';
    $params[] = $cycle;
}

if ($search !== '') {
    $searchParts = array(
        "t.`{$tenantNameColumn}` LIKE ?",
        "p.`{$planNameColumn}` LIKE ?",
        "s.`reference_no` LIKE ?"
    );

    if ($tenantCodeColumn !== '') {
        $searchParts[] =
            "t.`{$tenantCodeColumn}` LIKE ?";
    }

    $where[] =
        '(' .
        implode(' OR ', $searchParts) .
        ')';

    $searchValue = '%' . $search . '%';

    foreach ($searchParts as $unused) {
        $types .= 's';
        $params[] = $searchValue;
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM subscriptions s
    INNER JOIN tenants t
        ON t.`{$tenantIdColumn}` =
           s.`tenant_id`
    INNER JOIN plans p
        ON p.`{$planIdColumn}` =
           s.`plan_id`
    {$whereSql}
";

$countStmt = $conn->prepare($countSql);

if ($types !== '') {
    $bindValues = array($types);

    foreach ($params as $key => $value) {
        $bindValues[] = &$params[$key];
    }

    call_user_func_array(
        array($countStmt, 'bind_param'),
        $bindValues
    );
}

$countStmt->execute();

$countRow = $countStmt
    ->get_result()
    ->fetch_assoc();

$totalRecords = isset($countRow['total'])
    ? (int) $countRow['total']
    : 0;

$countStmt->close();

$totalPages = max(
    1,
    (int) ceil($totalRecords / $perPage)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Sorting
|--------------------------------------------------------------------------
*/

switch ($sort) {
    case 'oldest':
        $orderSql = "ORDER BY s.`created_at` ASC";
        break;

    case 'amount_desc':
        $orderSql = "ORDER BY s.`amount` DESC";
        break;

    case 'amount_asc':
        $orderSql = "ORDER BY s.`amount` ASC";
        break;

    case 'expiry_asc':
        $orderSql = "
            ORDER BY
                s.`ends_at` IS NULL ASC,
                s.`ends_at` ASC
        ";
        break;

    case 'expiry_desc':
        $orderSql = "
            ORDER BY
                s.`ends_at` IS NULL ASC,
                s.`ends_at` DESC
        ";
        break;

    case 'tenant_asc':
        $orderSql =
            "ORDER BY t.`{$tenantNameColumn}` ASC";
        break;

    case 'tenant_desc':
        $orderSql =
            "ORDER BY t.`{$tenantNameColumn}` DESC";
        break;

    case 'latest':
    default:
        $orderSql = "ORDER BY s.`created_at` DESC";
        break;
}

/*
|--------------------------------------------------------------------------
| Load subscriptions
|--------------------------------------------------------------------------
*/

$tenantCodeSelect =
    $tenantCodeColumn !== ''
        ? "t.`{$tenantCodeColumn}` AS tenant_code"
        : "'' AS tenant_code";

$listSql = "
    SELECT
        s.`{$subscriptionIdColumn}` AS subscription_id,
        s.`tenant_id`,
        s.`plan_id`,
        s.`reference_no`,
        s.`status`,
        s.`starts_at`,
        s.`ends_at`,
        s.`trial_ends_at`,
        s.`amount`,
        s.`currency`,
        s.`billing_cycle`,
        s.`auto_renew`,
        s.`created_at`,
        t.`{$tenantNameColumn}` AS tenant_name,
        {$tenantCodeSelect},
        p.`{$planNameColumn}` AS plan_name
    FROM subscriptions s
    INNER JOIN tenants t
        ON t.`{$tenantIdColumn}` =
           s.`tenant_id`
    INNER JOIN plans p
        ON p.`{$planIdColumn}` =
           s.`plan_id`
    {$whereSql}
    {$orderSql}
    LIMIT ? OFFSET ?
";

$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$listTypes = $types . 'ii';

$listStmt = $conn->prepare($listSql);

$bindValues = array($listTypes);

foreach ($listParams as $key => $value) {
    $bindValues[] = &$listParams[$key];
}

call_user_func_array(
    array($listStmt, 'bind_param'),
    $bindValues
);

$listStmt->execute();

$listResult = $listStmt->get_result();

$subscriptions = array();

while ($row = $listResult->fetch_assoc()) {
    $subscriptions[] = $row;
}

$listStmt->close();

/*
|--------------------------------------------------------------------------
| Expiring and overdue highlights
|--------------------------------------------------------------------------
*/

$alertResult = $conn->query("
    SELECT
        s.`{$subscriptionIdColumn}` AS subscription_id,
        s.`status`,
        s.`ends_at`,
        s.`amount`,
        s.`currency`,
        t.`{$tenantNameColumn}` AS tenant_name,
        p.`{$planNameColumn}` AS plan_name
    FROM subscriptions s
    INNER JOIN tenants t
        ON t.`{$tenantIdColumn}` =
           s.`tenant_id`
    INNER JOIN plans p
        ON p.`{$planIdColumn}` =
           s.`plan_id`
    WHERE s.`deleted_at` IS NULL
      AND (
          s.`status` = 'past_due'
          OR (
              s.`ends_at` IS NOT NULL
              AND s.`ends_at` >= NOW()
              AND s.`ends_at` <
                  DATE_ADD(
                      NOW(),
                      INTERVAL 14 DAY
                  )
              AND s.`status` IN (
                  'active',
                  'trial'
              )
          )
      )
    ORDER BY
        CASE
            WHEN s.`status` = 'past_due'
            THEN 0 ELSE 1
        END,
        s.`ends_at` ASC
    LIMIT 8
");

$billingAlerts = array();

while ($alertRow = $alertResult->fetch_assoc()) {
    $billingAlerts[] = $alertRow;
}

$alertResult->free();

/*
|--------------------------------------------------------------------------
| Messages and pagination
|--------------------------------------------------------------------------
*/

$successMessage = '';

if (!empty($_SESSION['platform_success_message'])) {
    $successMessage =
        (string) $_SESSION['platform_success_message'];

    unset($_SESSION['platform_success_message']);
}

$errorMessage = '';

if (!empty($_SESSION['platform_error_message'])) {
    $errorMessage =
        (string) $_SESSION['platform_error_message'];

    unset($_SESSION['platform_error_message']);
}

$startRecord = $totalRecords > 0
    ? $offset + 1
    : 0;

$endRecord = min(
    $offset + $perPage,
    $totalRecords
);

$paginationStart = max(1, $page - 2);
$paginationEnd = min(
    $totalPages,
    $page + 2
);

$canManageBilling = hasPlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin'
));

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .billing-page {
        display: grid;
        gap: 15px;
    }

    .billing-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .billing-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .billing-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .billing-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .billing-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .billing-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .billing-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .billing-button {
        min-height: 37px;
        padding: 7px 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #4b5563;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none;
    }

    .billing-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .billing-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .billing-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .billing-summary {
        display: grid;
        grid-template-columns:
            repeat(5, minmax(0, 1fr));
        gap: 10px;
    }

    .billing-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
        text-decoration: none;
        box-shadow:
            0 4px 15px rgba(31, 41, 55, 0.03);
    }

    .billing-summary-card:hover,
    .billing-summary-card.selected {
        border-color: #ddd6fe;
        background: #faf8ff;
    }

    .billing-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 14px;
    }

    .billing-summary-icon.total {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .billing-summary-icon.active {
        background: #ecfdf5;
        color: #059669;
    }

    .billing-summary-icon.trial {
        background: #eff6ff;
        color: #2563eb;
    }

    .billing-summary-icon.due {
        background: #fef2f2;
        color: #dc2626;
    }

    .billing-summary-icon.expiring {
        background: #fff7ed;
        color: #d97706;
    }

    .billing-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .billing-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .billing-top-grid {
        display: grid;
        grid-template-columns:
            minmax(0, 1.1fr)
            minmax(320px, 0.9fr);
        gap: 15px;
        align-items: start;
    }

    .billing-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .billing-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .billing-card-icon {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 13px;
    }

    .billing-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .billing-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .billing-card-body {
        padding: 14px;
    }

    .billing-currency-grid {
        display: grid;
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
        gap: 9px;
    }

    .billing-currency-item {
        padding: 11px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .billing-currency-code {
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .billing-currency-value {
        margin-top: 4px;
        color: #111827;
        font-size: 13px;
        font-weight: 800;
    }

    .billing-currency-meta {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 7px;
    }

    .billing-alert-list {
        display: grid;
        gap: 8px;
    }

    .billing-alert-item {
        padding: 10px 11px;
        display: flex;
        justify-content: space-between;
        gap: 10px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
        text-decoration: none;
    }

    .billing-alert-item:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
    }

    .billing-alert-name {
        color: #111827;
        font-size: 9px;
        font-weight: 700;
    }

    .billing-alert-meta {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 7px;
    }

    .billing-alert-amount {
        color: #6d28d9;
        font-size: 9px;
        font-weight: 800;
        white-space: nowrap;
    }

    .billing-empty-mini {
        padding: 24px 10px;
        color: #9ca3af;
        text-align: center;
        font-size: 9px;
    }

    .billing-toolbar {
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-bottom: 1px solid #eef0f3;
    }

    .billing-search {
        min-width: 220px;
        position: relative;
        flex: 1;
    }

    .billing-search i {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 12px;
    }

    .billing-control {
        height: 36px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .billing-search .billing-control {
        padding-left: 33px;
    }

    .billing-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .billing-filter-button {
        height: 36px;
        padding: 7px 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 0;
        border-radius: 8px;
        background: #111827;
        color: #ffffff;
        font-size: 9px;
        font-weight: 700;
    }

    .billing-clear-button {
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #6b7280;
        text-decoration: none;
    }

    .billing-table-wrap {
        overflow-x: auto;
    }

    .billing-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .billing-table th {
        padding: 10px 13px;
        border-bottom: 1px solid #e9ebef;
        background: #fafafa;
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .billing-table td {
        padding: 11px 13px;
        border-bottom: 1px solid #f0f1f3;
        color: #374151;
        font-size: 9px;
        vertical-align: middle;
    }

    .billing-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .billing-table tbody tr:hover {
        background: #fcfbff;
    }

    .billing-tenant {
        min-width: 180px;
    }

    .billing-tenant-name {
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .billing-tenant-code {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .billing-status {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .billing-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .billing-status.info {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .billing-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .billing-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .billing-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .billing-amount {
        color: #111827;
        font-size: 10px;
        font-weight: 800;
    }

    .billing-actions-cell {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .billing-action {
        width: 29px;
        height: 29px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        background: #ffffff;
        color: #6b7280;
        font-size: 11px;
        text-decoration: none;
    }

    .billing-action:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
        color: #7c3aed;
    }

    .billing-empty {
        padding: 48px 20px;
        color: #9ca3af;
        text-align: center;
        font-size: 10px;
    }

    .billing-empty i {
        margin-bottom: 10px;
        display: block;
        color: #c4b5fd;
        font-size: 30px;
    }

    .billing-pagination-bar {
        min-height: 54px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #eef0f3;
    }

    .billing-pagination-info {
        color: #6b7280;
        font-size: 9px;
    }

    .billing-pagination {
        margin: 0;
        display: flex;
        gap: 4px;
        list-style: none;
    }

    .billing-pagination a,
    .billing-pagination span {
        min-width: 29px;
        height: 29px;
        padding: 0 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        background: #ffffff;
        color: #6b7280;
        font-size: 8px;
        text-decoration: none;
    }

    .billing-pagination .active {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .billing-pagination .disabled {
        opacity: 0.45;
        pointer-events: none;
    }

    @media (max-width: 1150px) {
        .billing-summary {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .billing-top-grid {
            grid-template-columns: 1fr;
        }

        .billing-currency-grid {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

        .billing-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .billing-search {
            min-width: 0;
        }

        .billing-toolbar .billing-control,
        .billing-filter-button,
        .billing-clear-button {
            width: 100% !important;
        }
    }

    @media (max-width: 650px) {
        .billing-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .billing-actions {
            width: 100%;
        }

        .billing-button {
            flex: 1;
        }

        .billing-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

        .billing-pagination-bar {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    @media (max-width: 430px) {
        .billing-summary,
        .billing-currency-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="billing-page">

    <?php if ($successMessage !== ''): ?>
        <div class="billing-alert success">
            <i class="bi bi-check-circle"></i>
            <span>
                <?= billingEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="billing-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span>
                <?= billingEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="billing-header">
        <div>
            <h2 class="billing-title">
                Billing
            </h2>

            <div class="billing-description">
                Review subscription value, billing status, renewals, and expiring accounts.
            </div>
        </div>

        <div class="billing-actions">
            <a
                href="subscriptions.php"
                class="billing-button"
            >
                <i class="bi bi-credit-card"></i>
                Subscriptions
            </a>

            <?php if ($canManageBilling): ?>
                <a
                    href="subscription-add.php"
                    class="billing-button primary"
                >
                    <i class="bi bi-plus-circle"></i>
                    Add Subscription
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="billing-summary">

        <a
            href="billing.php"
            class="billing-summary-card <?= $status === ''
                ? 'selected'
                : ''; ?>"
        >
            <span class="billing-summary-icon total">
                <i class="bi bi-receipt"></i>
            </span>

            <span>
                <span class="billing-summary-label">
                    Total Subscriptions
                </span>

                <span class="billing-summary-value">
                    <?= number_format(
                        $summary['total']
                    ); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= billingEscape(
                billingBuildQuery(
                    array(
                        'status' => 'active',
                        'page' => 1
                    )
                )
            ); ?>"
            class="billing-summary-card <?= $status === 'active'
                ? 'selected'
                : ''; ?>"
        >
            <span class="billing-summary-icon active">
                <i class="bi bi-check-circle"></i>
            </span>

            <span>
                <span class="billing-summary-label">
                    Active
                </span>

                <span class="billing-summary-value">
                    <?= number_format(
                        $summary['active']
                    ); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= billingEscape(
                billingBuildQuery(
                    array(
                        'status' => 'trial',
                        'page' => 1
                    )
                )
            ); ?>"
            class="billing-summary-card <?= $status === 'trial'
                ? 'selected'
                : ''; ?>"
        >
            <span class="billing-summary-icon trial">
                <i class="bi bi-hourglass-split"></i>
            </span>

            <span>
                <span class="billing-summary-label">
                    Trial
                </span>

                <span class="billing-summary-value">
                    <?= number_format(
                        $summary['trial']
                    ); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= billingEscape(
                billingBuildQuery(
                    array(
                        'status' => 'past_due',
                        'page' => 1
                    )
                )
            ); ?>"
            class="billing-summary-card <?= $status === 'past_due'
                ? 'selected'
                : ''; ?>"
        >
            <span class="billing-summary-icon due">
                <i class="bi bi-exclamation-circle"></i>
            </span>

            <span>
                <span class="billing-summary-label">
                    Past Due
                </span>

                <span class="billing-summary-value">
                    <?= number_format(
                        $summary['past_due']
                    ); ?>
                </span>
            </span>
        </a>

        <div class="billing-summary-card">
            <span class="billing-summary-icon expiring">
                <i class="bi bi-calendar2-week"></i>
            </span>

            <span>
                <span class="billing-summary-label">
                    Expiring in 30 Days
                </span>

                <span class="billing-summary-value">
                    <?= number_format(
                        $summary['expiring']
                    ); ?>
                </span>
            </span>
        </div>

    </div>

    <div class="billing-top-grid">

        <section class="billing-card">
            <div class="billing-card-header">
                <span class="billing-card-icon">
                    <i class="bi bi-cash-stack"></i>
                </span>

                <div>
                    <h3 class="billing-card-title">
                        Subscription Value by Currency
                    </h3>

                    <div class="billing-card-subtitle">
                        Active, trial, and past-due subscription values
                    </div>
                </div>
            </div>

            <div class="billing-card-body">
                <?php if (empty($currencyTotals)): ?>
                    <div class="billing-empty-mini">
                        No billing value is available.
                    </div>
                <?php else: ?>
                    <div class="billing-currency-grid">
                        <?php foreach ($currencyTotals as $currencyRow): ?>
                            <a
                                href="?<?= billingEscape(
                                    billingBuildQuery(
                                        array(
                                            'currency' =>
                                                strtoupper(
                                                    $currencyRow[
                                                        'currency_code'
                                                    ]
                                                ),
                                            'page' => 1
                                        )
                                    )
                                ); ?>"
                                class="billing-currency-item"
                                style="text-decoration:none;"
                            >
                                <div class="billing-currency-code">
                                    <?= billingEscape(
                                        strtoupper(
                                            $currencyRow[
                                                'currency_code'
                                            ]
                                        )
                                    ); ?>
                                </div>

                                <div class="billing-currency-value">
                                    <?= billingEscape(
                                        billingMoney(
                                            $currencyRow[
                                                'recurring_value'
                                            ],
                                            $currencyRow[
                                                'currency_code'
                                            ]
                                        )
                                    ); ?>
                                </div>

                                <div class="billing-currency-meta">
                                    <?= number_format(
                                        (int)
                                        $currencyRow[
                                            'active_records'
                                        ]
                                    ); ?>
                                    subscription<?= (int)
                                        $currencyRow[
                                            'active_records'
                                        ] === 1
                                            ? ''
                                            : 's'; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="billing-card">
            <div class="billing-card-header">
                <span class="billing-card-icon">
                    <i class="bi bi-bell"></i>
                </span>

                <div>
                    <h3 class="billing-card-title">
                        Billing Attention
                    </h3>

                    <div class="billing-card-subtitle">
                        Past-due and soon-expiring subscriptions
                    </div>
                </div>
            </div>

            <div class="billing-card-body">
                <?php if (empty($billingAlerts)): ?>
                    <div class="billing-empty-mini">
                        No subscriptions currently require attention.
                    </div>
                <?php else: ?>
                    <div class="billing-alert-list">
                        <?php foreach ($billingAlerts as $billingAlert): ?>
                            <a
                                href="subscription-view.php?id=<?= (int)
                                    $billingAlert[
                                        'subscription_id'
                                    ]; ?>"
                                class="billing-alert-item"
                            >
                                <span>
                                    <span class="billing-alert-name">
                                        <?= billingEscape(
                                            $billingAlert[
                                                'tenant_name'
                                            ]
                                        ); ?>
                                    </span>

                                    <span class="billing-alert-meta">
                                        <?= billingEscape(
                                            $billingAlert[
                                                'plan_name'
                                            ]
                                        ); ?>
                                        ·
                                        <?= billingEscape(
                                            billingLabel(
                                                $billingAlert[
                                                    'status'
                                                ]
                                            )
                                        ); ?>

                                        <?php if (
                                            !empty(
                                                $billingAlert[
                                                    'ends_at'
                                                ]
                                            )
                                        ): ?>
                                            ·
                                            <?= billingEscape(
                                                billingDate(
                                                    $billingAlert[
                                                        'ends_at'
                                                    ]
                                                )
                                            ); ?>
                                        <?php endif; ?>
                                    </span>
                                </span>

                                <span class="billing-alert-amount">
                                    <?= billingEscape(
                                        billingMoney(
                                            $billingAlert[
                                                'amount'
                                            ],
                                            $billingAlert[
                                                'currency'
                                            ]
                                        )
                                    ); ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

    </div>

    <section class="billing-card">

        <form
            method="get"
            class="billing-toolbar"
            id="billingFilterForm"
        >
            <div class="billing-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="search"
                    class="form-control billing-control"
                    value="<?= billingEscape(
                        $search
                    ); ?>"
                    placeholder="Search tenant, plan, code, or reference..."
                    autocomplete="off"
                >
            </div>

            <select
                name="status"
                class="form-select billing-control"
                style="width:135px;"
            >
                <option value="">
                    All statuses
                </option>

                <?php foreach ($allowedStatuses as $statusOption): ?>
                    <option
                        value="<?= billingEscape(
                            $statusOption
                        ); ?>"
                        <?= $status === $statusOption
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= billingEscape(
                            billingLabel(
                                $statusOption
                            )
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="currency"
                class="form-select billing-control"
                style="width:105px;"
            >
                <option value="">
                    Currency
                </option>

                <?php foreach ($allowedCurrencies as $currencyOption): ?>
                    <option
                        value="<?= billingEscape(
                            $currencyOption
                        ); ?>"
                        <?= $currency === $currencyOption
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= billingEscape(
                            $currencyOption
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="cycle"
                class="form-select billing-control"
                style="width:140px;"
            >
                <option value="">
                    Billing cycle
                </option>

                <?php foreach ($allowedCycles as $cycleOption): ?>
                    <option
                        value="<?= billingEscape(
                            $cycleOption
                        ); ?>"
                        <?= $cycle === $cycleOption
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= billingEscape(
                            billingLabel(
                                $cycleOption
                            )
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select
                name="sort"
                class="form-select billing-control"
                style="width:145px;"
            >
                <option
                    value="latest"
                    <?= $sort === 'latest'
                        ? 'selected'
                        : ''; ?>
                >
                    Latest first
                </option>

                <option
                    value="oldest"
                    <?= $sort === 'oldest'
                        ? 'selected'
                        : ''; ?>
                >
                    Oldest first
                </option>

                <option
                    value="amount_desc"
                    <?= $sort === 'amount_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Highest amount
                </option>

                <option
                    value="amount_asc"
                    <?= $sort === 'amount_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Lowest amount
                </option>

                <option
                    value="expiry_asc"
                    <?= $sort === 'expiry_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Expiring first
                </option>

                <option
                    value="tenant_asc"
                    <?= $sort === 'tenant_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Tenant A-Z
                </option>
            </select>

            <select
                name="per_page"
                class="form-select billing-control"
                style="width:85px;"
            >
                <?php foreach ($allowedPerPage as $pageSize): ?>
                    <option
                        value="<?= (int) $pageSize; ?>"
                        <?= $perPage === $pageSize
                            ? 'selected'
                            : ''; ?>
                    >
                        <?= (int) $pageSize; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button
                type="submit"
                class="billing-filter-button"
            >
                <i class="bi bi-funnel"></i>
                Apply
            </button>

            <?php if (
                $search !== '' ||
                $status !== '' ||
                $currency !== '' ||
                $cycle !== '' ||
                $sort !== 'latest' ||
                $perPage !== 15
            ): ?>
                <a
                    href="billing.php"
                    class="billing-clear-button"
                    title="Clear filters"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($subscriptions)): ?>
            <div class="billing-empty">
                <i class="bi bi-receipt"></i>
                No billing records matched your filters.
            </div>
        <?php else: ?>

            <div class="billing-table-wrap">
                <table class="billing-table">
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Plan</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Amount</th>
                            <th>Cycle</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Renew</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($subscriptions as $subscription): ?>
                            <tr>
                                <td>
                                    <div class="billing-tenant">
                                        <span class="billing-tenant-name">
                                            <?= billingEscape(
                                                $subscription[
                                                    'tenant_name'
                                                ]
                                            ); ?>
                                        </span>

                                        <span class="billing-tenant-code">
                                            <?= billingEscape(
                                                !empty(
                                                    $subscription[
                                                        'tenant_code'
                                                    ]
                                                )
                                                    ? $subscription[
                                                        'tenant_code'
                                                    ]
                                                    : 'Tenant #' .
                                                        $subscription[
                                                            'tenant_id'
                                                        ]
                                            ); ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <?= billingEscape(
                                        $subscription[
                                            'plan_name'
                                        ]
                                    ); ?>
                                </td>

                                <td>
                                    <?= billingEscape(
                                        !empty(
                                            $subscription[
                                                'reference_no'
                                            ]
                                        )
                                            ? $subscription[
                                                'reference_no'
                                            ]
                                            : '—'
                                    ); ?>
                                </td>

                                <td>
                                    <span
                                        class="billing-status <?= billingEscape(
                                            billingStatusClass(
                                                $subscription[
                                                    'status'
                                                ]
                                            )
                                        ); ?>"
                                    >
                                        <?= billingEscape(
                                            billingLabel(
                                                $subscription[
                                                    'status'
                                                ]
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="billing-amount">
                                        <?= billingEscape(
                                            billingMoney(
                                                $subscription[
                                                    'amount'
                                                ],
                                                $subscription[
                                                    'currency'
                                                ]
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <?= billingEscape(
                                        billingLabel(
                                            $subscription[
                                                'billing_cycle'
                                            ]
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <?= billingEscape(
                                        billingDate(
                                            $subscription[
                                                'starts_at'
                                            ]
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <?= billingEscape(
                                        billingDate(
                                            $subscription[
                                                'ends_at'
                                            ]
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <?= !empty(
                                        $subscription[
                                            'auto_renew'
                                        ]
                                    )
                                        ? 'Yes'
                                        : 'No'; ?>
                                </td>

                                <td>
                                    <div class="billing-actions-cell">
                                        <a
                                            href="subscription-view.php?id=<?= (int)
                                                $subscription[
                                                    'subscription_id'
                                                ]; ?>"
                                            class="billing-action"
                                            title="View subscription"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <?php if ($canManageBilling): ?>
                                            <a
                                                href="subscription-edit.php?id=<?= (int)
                                                    $subscription[
                                                        'subscription_id'
                                                    ]; ?>"
                                                class="billing-action"
                                                title="Edit subscription"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <div class="billing-pagination-bar">
            <div class="billing-pagination-info">
                Showing
                <?= number_format($startRecord); ?>
                to
                <?= number_format($endRecord); ?>
                of
                <?= number_format($totalRecords); ?>
                billing records
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Billing pagination">
                    <ul class="billing-pagination">
                        <li>
                            <?php if ($page > 1): ?>
                                <a
                                    href="?<?= billingEscape(
                                        billingBuildQuery(
                                            array(
                                                'page' => $page - 1
                                            )
                                        )
                                    ); ?>"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    <i class="bi bi-chevron-left"></i>
                                </span>
                            <?php endif; ?>
                        </li>

                        <?php if ($paginationStart > 1): ?>
                            <li>
                                <a
                                    href="?<?= billingEscape(
                                        billingBuildQuery(
                                            array('page' => 1)
                                        )
                                    ); ?>"
                                >
                                    1
                                </a>
                            </li>

                            <?php if ($paginationStart > 2): ?>
                                <li>
                                    <span>…</span>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for (
                            $pageNumber = $paginationStart;
                            $pageNumber <= $paginationEnd;
                            $pageNumber++
                        ): ?>
                            <li>
                                <?php if ($pageNumber === $page): ?>
                                    <span class="active">
                                        <?= (int) $pageNumber; ?>
                                    </span>
                                <?php else: ?>
                                    <a
                                        href="?<?= billingEscape(
                                            billingBuildQuery(
                                                array(
                                                    'page' =>
                                                        $pageNumber
                                                )
                                            )
                                        ); ?>"
                                    >
                                        <?= (int) $pageNumber; ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endfor; ?>

                        <?php if (
                            $paginationEnd < $totalPages
                        ): ?>
                            <?php if (
                                $paginationEnd <
                                $totalPages - 1
                            ): ?>
                                <li>
                                    <span>…</span>
                                </li>
                            <?php endif; ?>

                            <li>
                                <a
                                    href="?<?= billingEscape(
                                        billingBuildQuery(
                                            array(
                                                'page' =>
                                                    $totalPages
                                            )
                                        )
                                    ); ?>"
                                >
                                    <?= (int) $totalPages; ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <li>
                            <?php if ($page < $totalPages): ?>
                                <a
                                    href="?<?= billingEscape(
                                        billingBuildQuery(
                                            array(
                                                'page' => $page + 1
                                            )
                                        )
                                    ); ?>"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    <i class="bi bi-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>

    </section>
</div>

<script>
(function () {
    'use strict';

    const form =
        document.getElementById(
            'billingFilterForm'
        );

    if (!form) {
        return;
    }

    const selects =
        form.querySelectorAll('select');

    selects.forEach(function (select) {
        select.addEventListener(
            'change',
            function () {
                form.submit();
            }
        );
    });

    const searchInput =
        form.querySelector(
            'input[name="search"]'
        );

    let timer = null;

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            function () {
                window.clearTimeout(timer);

                timer = window.setTimeout(
                    function () {
                        form.submit();
                    },
                    650
                );
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
