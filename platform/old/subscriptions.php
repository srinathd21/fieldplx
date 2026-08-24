<?php
/**
 * FieldPlx Platform - Subscriptions
 *
 * File:
 * platform/subscriptions.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - platform_users authentication
 */

require_once __DIR__ . '/includes/auth.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin',
    'platform_read_only'
));

$pageTitle = 'Subscriptions - FieldPlx';
$activePage = 'subscriptions';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('subscriptionsEscape')) {
    function subscriptionsEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('subscriptionsTableExists')) {
    function subscriptionsTableExists(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        $tableName = trim((string) $tableName);

        if ($tableName === '') {
            return false;
        }

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        if (!$stmt) {
            $cache[$tableName] = false;
            return false;
        }

        $stmt->bind_param('s', $tableName);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('subscriptionsColumns')) {
    function subscriptionsColumns(
        mysqli $conn,
        $tableName
    ) {
        static $cache = array();

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTable = str_replace('`', '``', $tableName);

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTable}`"
        );

        if (!$result) {
            return $cache[$tableName];
        }

        while ($row = $result->fetch_assoc()) {
            if (!empty($row['Field'])) {
                $cache[$tableName][
                    (string) $row['Field']
                ] = $row;
            }
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('subscriptionsFirstColumn')) {
    function subscriptionsFirstColumn(
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

if (!function_exists('subscriptionsBind')) {
    function subscriptionsBind(
        mysqli_stmt $stmt,
        $types,
        array &$values
    ) {
        if ($types === '') {
            return true;
        }

        $arguments = array($types);

        foreach ($values as $key => $value) {
            $arguments[] = &$values[$key];
        }

        return call_user_func_array(
            array($stmt, 'bind_param'),
            $arguments
        );
    }
}

if (!function_exists('subscriptionsLabel')) {
    function subscriptionsLabel($value)
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

if (!function_exists('subscriptionsStatusClass')) {
    function subscriptionsStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
                return 'success';

            case 'trial':
                return 'info';

            case 'pending':
            case 'past_due':
                return 'warning';

            case 'expired':
            case 'cancelled':
            case 'inactive':
            case 'suspended':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('subscriptionsDate')) {
    function subscriptionsDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return '—';
        }

        return date('d M Y', $timestamp);
    }
}

if (!function_exists('subscriptionsMoney')) {
    function subscriptionsMoney($amount, $currency)
    {
        $amount = is_numeric($amount)
            ? (float) $amount
            : 0;

        $currency = strtoupper(trim((string) $currency));

        if ($currency === '') {
            $currency = 'INR';
        }

        $symbol = $currency;

        if ($currency === 'INR') {
            $symbol = '₹';
        } elseif ($currency === 'GBP') {
            $symbol = '£';
        } elseif ($currency === 'USD') {
            $symbol = '$';
        } elseif ($currency === 'EUR') {
            $symbol = '€';
        }

        return $symbol . number_format($amount, 2);
    }
}

if (!function_exists('subscriptionsBuildQuery')) {
    function subscriptionsBuildQuery(array $changes = array())
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
| Verify tables
|--------------------------------------------------------------------------
*/

if (!subscriptionsTableExists($conn, 'subscriptions')) {
    http_response_code(500);
    exit('The subscriptions table does not exist.');
}

if (!subscriptionsTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$subscriptionColumns = subscriptionsColumns(
    $conn,
    'subscriptions'
);

$tenantColumns = subscriptionsColumns(
    $conn,
    'tenants'
);

$hasPlansTable = subscriptionsTableExists(
    $conn,
    'plans'
);

$planColumns = $hasPlansTable
    ? subscriptionsColumns($conn, 'plans')
    : array();

/*
|--------------------------------------------------------------------------
| Detect subscription columns
|--------------------------------------------------------------------------
*/

$subscriptionIdColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array('id', 'subscription_id')
);

$subscriptionTenantColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array('tenant_id')
);

$subscriptionPlanColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array('plan_id')
);

$subscriptionStatusColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array('status')
);

$subscriptionStartColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array(
        'starts_at',
        'start_date',
        'started_at',
        'subscription_start'
    )
);

$subscriptionEndColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array(
        'ends_at',
        'end_date',
        'expires_at',
        'expiry_date',
        'subscription_end'
    )
);

$subscriptionTrialEndColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array(
        'trial_ends_at',
        'trial_end_date',
        'trial_until'
    )
);

$subscriptionAmountColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array(
        'amount',
        'price',
        'subscription_amount',
        'billing_amount'
    )
);

$subscriptionCurrencyColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array('currency', 'currency_code')
);

$subscriptionBillingCycleColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array(
        'billing_cycle',
        'cycle',
        'billing_period',
        'interval'
    )
);

$subscriptionAutoRenewColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array('auto_renew', 'is_auto_renew')
);

$subscriptionReferenceColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array(
        'reference_no',
        'subscription_code',
        'reference',
        'code'
    )
);

$subscriptionCreatedAtColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array('created_at', 'created_on')
);

$subscriptionUpdatedAtColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array('updated_at', 'updated_on')
);

$subscriptionDeletedColumn = subscriptionsFirstColumn(
    $subscriptionColumns,
    array('deleted_at')
);

if (
    $subscriptionIdColumn === '' ||
    $subscriptionTenantColumn === ''
) {
    http_response_code(500);
    exit('The subscriptions table requires id and tenant_id columns.');
}

/*
|--------------------------------------------------------------------------
| Detect tenant columns
|--------------------------------------------------------------------------
*/

$tenantIdColumn = subscriptionsFirstColumn(
    $tenantColumns,
    array('id', 'tenant_id')
);

$tenantNameColumn = subscriptionsFirstColumn(
    $tenantColumns,
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$tenantCodeColumn = subscriptionsFirstColumn(
    $tenantColumns,
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$tenantStatusColumn = subscriptionsFirstColumn(
    $tenantColumns,
    array('status')
);

$tenantDeletedColumn = subscriptionsFirstColumn(
    $tenantColumns,
    array('deleted_at')
);

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);
    exit('The tenants table requires id and name columns.');
}

/*
|--------------------------------------------------------------------------
| Detect plan columns
|--------------------------------------------------------------------------
*/

$planIdColumn = '';
$planNameColumn = '';
$planCodeColumn = '';
$planPriceColumn = '';
$planCurrencyColumn = '';
$planBillingCycleColumn = '';
$planDeletedColumn = '';

if ($hasPlansTable) {
    $planIdColumn = subscriptionsFirstColumn(
        $planColumns,
        array('id', 'plan_id')
    );

    $planNameColumn = subscriptionsFirstColumn(
        $planColumns,
        array('name', 'plan_name')
    );

    $planCodeColumn = subscriptionsFirstColumn(
        $planColumns,
        array('code', 'plan_code')
    );

    $planPriceColumn = subscriptionsFirstColumn(
        $planColumns,
        array('price', 'amount')
    );

    $planCurrencyColumn = subscriptionsFirstColumn(
        $planColumns,
        array('currency', 'currency_code')
    );

    $planBillingCycleColumn = subscriptionsFirstColumn(
        $planColumns,
        array(
            'billing_cycle',
            'billing_period',
            'interval'
        )
    );

    $planDeletedColumn = subscriptionsFirstColumn(
        $planColumns,
        array('deleted_at')
    );
}

/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search']) &&
    !is_array($_GET['search'])
        ? trim((string) $_GET['search'])
        : '';

$status = isset($_GET['status']) &&
    !is_array($_GET['status'])
        ? strtolower(trim((string) $_GET['status']))
        : '';

$tenantId = isset($_GET['tenant_id'])
    ? max(0, (int) $_GET['tenant_id'])
    : 0;

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

$allowedPerPage = array(10, 15, 25, 50);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 15;
}

$allowedSorts = array(
    'latest',
    'oldest',
    'expiry_asc',
    'expiry_desc',
    'tenant_asc'
);

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

/*
|--------------------------------------------------------------------------
| Load tenants
|--------------------------------------------------------------------------
*/

$tenantList = array();

$tenantListSql = "
    SELECT
        `{$tenantIdColumn}` AS tenant_id,
        `{$tenantNameColumn}` AS tenant_name
";

if ($tenantCodeColumn !== '') {
    $tenantListSql .= ",
        `{$tenantCodeColumn}` AS tenant_code
    ";
} else {
    $tenantListSql .= ",
        '' AS tenant_code
    ";
}

$tenantListSql .= "
    FROM tenants
";

if ($tenantDeletedColumn !== '') {
    $tenantListSql .= "
        WHERE `{$tenantDeletedColumn}` IS NULL
    ";
}

$tenantListSql .= "
    ORDER BY `{$tenantNameColumn}` ASC
";

$tenantListResult = $conn->query(
    $tenantListSql
);

while ($tenantRow =
    $tenantListResult->fetch_assoc()
) {
    $tenantList[] = $tenantRow;
}

$tenantListResult->free();

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summaryWhere = array();

if ($subscriptionDeletedColumn !== '') {
    $summaryWhere[] =
        "`{$subscriptionDeletedColumn}` IS NULL";
}

if ($tenantId > 0) {
    $summaryWhere[] =
        "`{$subscriptionTenantColumn}` = " .
        (int) $tenantId;
}

$summarySql = "
    SELECT
        COUNT(*) AS total_count
";

if ($subscriptionStatusColumn !== '') {
    $summarySql .= ",
        SUM(
            CASE
                WHEN `{$subscriptionStatusColumn}` = 'active'
                THEN 1 ELSE 0
            END
        ) AS active_count,
        SUM(
            CASE
                WHEN `{$subscriptionStatusColumn}` = 'trial'
                THEN 1 ELSE 0
            END
        ) AS trial_count,
        SUM(
            CASE
                WHEN `{$subscriptionStatusColumn}` IN (
                    'expired',
                    'cancelled',
                    'inactive',
                    'suspended'
                )
                THEN 1 ELSE 0
            END
        ) AS inactive_count
    ";
}

if ($subscriptionEndColumn !== '') {
    $summarySql .= ",
        SUM(
            CASE
                WHEN `{$subscriptionEndColumn}` IS NOT NULL
                 AND DATE(`{$subscriptionEndColumn}`)
                     BETWEEN CURDATE()
                     AND DATE_ADD(
                         CURDATE(),
                         INTERVAL 30 DAY
                     )
                THEN 1 ELSE 0
            END
        ) AS expiring_count
    ";
}

$summarySql .= "
    FROM subscriptions
";

if (!empty($summaryWhere)) {
    $summarySql .= "
        WHERE " .
        implode(' AND ', $summaryWhere);
}

$summary = array(
    'total' => 0,
    'active' => 0,
    'trial' => 0,
    'inactive' => 0,
    'expiring' => 0
);

$summaryResult = $conn->query($summarySql);

if ($summaryResult) {
    $summaryRow = $summaryResult->fetch_assoc();

    $summary['total'] = isset(
        $summaryRow['total_count']
    )
        ? (int) $summaryRow['total_count']
        : 0;

    $summary['active'] = isset(
        $summaryRow['active_count']
    )
        ? (int) $summaryRow['active_count']
        : 0;

    $summary['trial'] = isset(
        $summaryRow['trial_count']
    )
        ? (int) $summaryRow['trial_count']
        : 0;

    $summary['inactive'] = isset(
        $summaryRow['inactive_count']
    )
        ? (int) $summaryRow['inactive_count']
        : 0;

    $summary['expiring'] = isset(
        $summaryRow['expiring_count']
    )
        ? (int) $summaryRow['expiring_count']
        : 0;

    $summaryResult->free();
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$where = array();
$params = array();
$types = '';

if ($subscriptionDeletedColumn !== '') {
    $where[] =
        "s.`{$subscriptionDeletedColumn}` IS NULL";
}

if ($tenantDeletedColumn !== '') {
    $where[] =
        "t.`{$tenantDeletedColumn}` IS NULL";
}

if ($tenantId > 0) {
    $where[] =
        "s.`{$subscriptionTenantColumn}` = ?";

    $types .= 'i';
    $params[] = $tenantId;
}

if (
    $status !== '' &&
    $subscriptionStatusColumn !== ''
) {
    if ($status === 'inactive') {
        $where[] =
            "s.`{$subscriptionStatusColumn}` IN (
                'expired',
                'cancelled',
                'inactive',
                'suspended'
            )";
    } elseif (
        $status === 'expiring' &&
        $subscriptionEndColumn !== ''
    ) {
        $where[] = "
            s.`{$subscriptionEndColumn}` IS NOT NULL
            AND DATE(
                s.`{$subscriptionEndColumn}`
            ) BETWEEN CURDATE()
            AND DATE_ADD(
                CURDATE(),
                INTERVAL 30 DAY
            )
        ";
    } else {
        $where[] =
            "s.`{$subscriptionStatusColumn}` = ?";

        $types .= 's';
        $params[] = $status;
    }
}

if ($search !== '') {
    $searchConditions = array();

    $searchConditions[] =
        "t.`{$tenantNameColumn}` LIKE ?";

    $types .= 's';
    $params[] = '%' . $search . '%';

    if ($tenantCodeColumn !== '') {
        $searchConditions[] =
            "t.`{$tenantCodeColumn}` LIKE ?";

        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    if ($subscriptionReferenceColumn !== '') {
        $searchConditions[] =
            "s.`{$subscriptionReferenceColumn}` LIKE ?";

        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    if (
        $hasPlansTable &&
        $subscriptionPlanColumn !== '' &&
        $planNameColumn !== ''
    ) {
        $searchConditions[] =
            "p.`{$planNameColumn}` LIKE ?";

        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    $where[] =
        '(' . implode(' OR ', $searchConditions) . ')';
}

$whereSql = !empty($where)
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| Join plans
|--------------------------------------------------------------------------
*/

$planJoinSql = '';

if (
    $hasPlansTable &&
    $subscriptionPlanColumn !== '' &&
    $planIdColumn !== ''
) {
    $planJoinSql = "
        LEFT JOIN plans p
            ON p.`{$planIdColumn}` =
               s.`{$subscriptionPlanColumn}`
    ";

    if ($planDeletedColumn !== '') {
        $planJoinSql .= "
            AND p.`{$planDeletedColumn}` IS NULL
        ";
    }
}

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
           s.`{$subscriptionTenantColumn}`
    {$planJoinSql}
    {$whereSql}
";

$countStmt = $conn->prepare($countSql);

subscriptionsBind(
    $countStmt,
    $types,
    $params
);

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
        $sortColumn = $subscriptionCreatedAtColumn !== ''
            ? "s.`{$subscriptionCreatedAtColumn}`"
            : "s.`{$subscriptionIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} ASC";
        break;

    case 'expiry_asc':
        $sortColumn = $subscriptionEndColumn !== ''
            ? "s.`{$subscriptionEndColumn}`"
            : "s.`{$subscriptionIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} ASC";
        break;

    case 'expiry_desc':
        $sortColumn = $subscriptionEndColumn !== ''
            ? "s.`{$subscriptionEndColumn}`"
            : "s.`{$subscriptionIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} DESC";
        break;

    case 'tenant_asc':
        $orderSql =
            "ORDER BY t.`{$tenantNameColumn}` ASC";
        break;

    case 'latest':
    default:
        $sortColumn = $subscriptionCreatedAtColumn !== ''
            ? "s.`{$subscriptionCreatedAtColumn}`"
            : "s.`{$subscriptionIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} DESC";
        break;
}

/*
|--------------------------------------------------------------------------
| Select subscriptions
|--------------------------------------------------------------------------
*/

$select = array(
    "s.`{$subscriptionIdColumn}` AS subscription_id",
    "s.`{$subscriptionTenantColumn}` AS tenant_id",
    "t.`{$tenantNameColumn}` AS tenant_name"
);

$select[] = $tenantCodeColumn !== ''
    ? "t.`{$tenantCodeColumn}` AS tenant_code"
    : "'' AS tenant_code";

$select[] = $tenantStatusColumn !== ''
    ? "t.`{$tenantStatusColumn}` AS tenant_status"
    : "'' AS tenant_status";

$select[] = $subscriptionStatusColumn !== ''
    ? "s.`{$subscriptionStatusColumn}` AS subscription_status"
    : "'active' AS subscription_status";

$select[] = $subscriptionStartColumn !== ''
    ? "s.`{$subscriptionStartColumn}` AS starts_at"
    : "NULL AS starts_at";

$select[] = $subscriptionEndColumn !== ''
    ? "s.`{$subscriptionEndColumn}` AS ends_at"
    : "NULL AS ends_at";

$select[] = $subscriptionTrialEndColumn !== ''
    ? "s.`{$subscriptionTrialEndColumn}` AS trial_ends_at"
    : "NULL AS trial_ends_at";

$select[] = $subscriptionAmountColumn !== ''
    ? "s.`{$subscriptionAmountColumn}` AS subscription_amount"
    : "NULL AS subscription_amount";

$select[] = $subscriptionCurrencyColumn !== ''
    ? "s.`{$subscriptionCurrencyColumn}` AS subscription_currency"
    : "'' AS subscription_currency";

$select[] = $subscriptionBillingCycleColumn !== ''
    ? "s.`{$subscriptionBillingCycleColumn}` AS billing_cycle"
    : "'' AS billing_cycle";

$select[] = $subscriptionAutoRenewColumn !== ''
    ? "s.`{$subscriptionAutoRenewColumn}` AS auto_renew"
    : "0 AS auto_renew";

$select[] = $subscriptionReferenceColumn !== ''
    ? "s.`{$subscriptionReferenceColumn}` AS reference_no"
    : "'' AS reference_no";

$select[] = $subscriptionCreatedAtColumn !== ''
    ? "s.`{$subscriptionCreatedAtColumn}` AS created_at"
    : "NULL AS created_at";

$select[] = $subscriptionUpdatedAtColumn !== ''
    ? "s.`{$subscriptionUpdatedAtColumn}` AS updated_at"
    : "NULL AS updated_at";

if (
    $hasPlansTable &&
    $subscriptionPlanColumn !== '' &&
    $planIdColumn !== ''
) {
    $select[] =
        "s.`{$subscriptionPlanColumn}` AS plan_id";

    $select[] = $planNameColumn !== ''
        ? "p.`{$planNameColumn}` AS plan_name"
        : "'' AS plan_name";

    $select[] = $planCodeColumn !== ''
        ? "p.`{$planCodeColumn}` AS plan_code"
        : "'' AS plan_code";

    $select[] = $planPriceColumn !== ''
        ? "p.`{$planPriceColumn}` AS plan_price"
        : "NULL AS plan_price";

    $select[] = $planCurrencyColumn !== ''
        ? "p.`{$planCurrencyColumn}` AS plan_currency"
        : "'' AS plan_currency";

    $select[] = $planBillingCycleColumn !== ''
        ? "p.`{$planBillingCycleColumn}` AS plan_billing_cycle"
        : "'' AS plan_billing_cycle";
} else {
    $select[] = "0 AS plan_id";
    $select[] = "'Custom Plan' AS plan_name";
    $select[] = "'' AS plan_code";
    $select[] = "NULL AS plan_price";
    $select[] = "'' AS plan_currency";
    $select[] = "'' AS plan_billing_cycle";
}

$listSql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM subscriptions s
    INNER JOIN tenants t
        ON t.`{$tenantIdColumn}` =
           s.`{$subscriptionTenantColumn}`
    {$planJoinSql}
    {$whereSql}
    {$orderSql}
    LIMIT ? OFFSET ?
";

$listStmt = $conn->prepare($listSql);

$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$listTypes = $types . 'ii';

subscriptionsBind(
    $listStmt,
    $listTypes,
    $listParams
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
| Messages
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

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .subscriptions-page {
        display: grid;
        gap: 15px;
    }

    .subscriptions-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .subscriptions-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .subscriptions-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .subscriptions-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .subscriptions-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .subscriptions-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .subscriptions-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .subscriptions-button {
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

    .subscriptions-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .subscriptions-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .subscriptions-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .subscriptions-summary {
        display: grid;
        grid-template-columns:
            repeat(5, minmax(0, 1fr));
        gap: 10px;
    }

    .subscriptions-summary-card {
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

    .subscriptions-summary-card:hover,
    .subscriptions-summary-card.selected {
        border-color: #ddd6fe;
        background: #faf8ff;
    }

    .subscriptions-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 14px;
    }

    .subscriptions-summary-icon.total {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .subscriptions-summary-icon.active {
        background: #ecfdf5;
        color: #059669;
    }

    .subscriptions-summary-icon.trial {
        background: #eff6ff;
        color: #2563eb;
    }

    .subscriptions-summary-icon.inactive {
        background: #fef2f2;
        color: #dc2626;
    }

    .subscriptions-summary-icon.expiring {
        background: #fff7ed;
        color: #d97706;
    }

    .subscriptions-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .subscriptions-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .subscriptions-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .subscriptions-toolbar {
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-bottom: 1px solid #eef0f3;
    }

    .subscriptions-search {
        min-width: 220px;
        position: relative;
        flex: 1;
    }

    .subscriptions-search i {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 12px;
    }

    .subscriptions-control {
        height: 36px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .subscriptions-search .subscriptions-control {
        padding-left: 33px;
    }

    .subscriptions-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .subscriptions-filter-button {
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

    .subscriptions-clear-button {
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

    .subscriptions-table-wrap {
        overflow-x: auto;
    }

    .subscriptions-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .subscriptions-table th {
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

    .subscriptions-table td {
        padding: 11px 13px;
        border-bottom: 1px solid #f0f1f3;
        color: #374151;
        font-size: 9px;
        vertical-align: middle;
    }

    .subscriptions-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .subscriptions-table tbody tr:hover {
        background: #fcfbff;
    }

    .subscription-tenant-name,
    .subscription-plan-name {
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .subscription-meta {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .subscription-status {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .subscription-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .subscription-status.info {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .subscription-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .subscription-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .subscription-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .subscription-amount {
        color: #111827;
        font-size: 10px;
        font-weight: 800;
    }

    .subscription-renew {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #f3f4f6;
        color: #4b5563;
        font-size: 8px;
        font-weight: 700;
    }

    .subscription-actions {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .subscription-action {
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

    .subscription-action:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
        color: #7c3aed;
    }

    .subscriptions-empty {
        padding: 48px 20px;
        color: #9ca3af;
        text-align: center;
        font-size: 10px;
    }

    .subscriptions-empty i {
        margin-bottom: 10px;
        display: block;
        color: #c4b5fd;
        font-size: 30px;
    }

    .subscriptions-pagination-bar {
        min-height: 54px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #eef0f3;
    }

    .subscriptions-pagination-info {
        color: #6b7280;
        font-size: 9px;
    }

    .subscriptions-pagination {
        margin: 0;
        display: flex;
        gap: 4px;
        list-style: none;
    }

    .subscriptions-pagination a,
    .subscriptions-pagination span {
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

    .subscriptions-pagination a:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .subscriptions-pagination .active {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .subscriptions-pagination .disabled {
        opacity: 0.45;
        pointer-events: none;
    }

    @media (max-width: 1100px) {
        .subscriptions-summary {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 800px) {
        .subscriptions-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .subscriptions-actions {
            width: 100%;
        }

        .subscriptions-button {
            flex: 1;
        }

        .subscriptions-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .subscriptions-search {
            min-width: 0;
        }

        .subscriptions-toolbar .subscriptions-control,
        .subscriptions-filter-button,
        .subscriptions-clear-button {
            width: 100% !important;
        }

        .subscriptions-pagination-bar {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    @media (max-width: 600px) {
        .subscriptions-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 420px) {
        .subscriptions-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="subscriptions-page">

    <?php if ($successMessage !== ''): ?>
        <div class="subscriptions-alert success">
            <i class="bi bi-check-circle"></i>
            <span><?= subscriptionsEscape($successMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="subscriptions-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= subscriptionsEscape($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <div class="subscriptions-header">
        <div>
            <h2 class="subscriptions-title">
                Subscriptions
            </h2>

            <div class="subscriptions-description">
                Track tenant plans, billing cycles, renewals, and expiry dates.
            </div>
        </div>

        <div class="subscriptions-actions">
            <a
                href="plans.php"
                class="subscriptions-button"
            >
                <i class="bi bi-card-list"></i>
                View Plans
            </a>

            <?php if (
                hasPlatformRole(array(
                    'super_admin',
                    'platform_admin',
                    'billing_admin'
                ))
            ): ?>
                <a
                    href="subscription-add.php"
                    class="subscriptions-button primary"
                >
                    <i class="bi bi-plus-circle"></i>
                    Add Subscription
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="subscriptions-summary">

        <a
            href="?<?= subscriptionsEscape(
                subscriptionsBuildQuery(
                    array(
                        'status' => '',
                        'page' => 1
                    )
                )
            ); ?>"
            class="subscriptions-summary-card <?= $status === ''
                ? 'selected'
                : ''; ?>"
        >
            <span class="subscriptions-summary-icon total">
                <i class="bi bi-credit-card"></i>
            </span>

            <span>
                <span class="subscriptions-summary-label">
                    Total
                </span>

                <span class="subscriptions-summary-value">
                    <?= number_format($summary['total']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= subscriptionsEscape(
                subscriptionsBuildQuery(
                    array(
                        'status' => 'active',
                        'page' => 1
                    )
                )
            ); ?>"
            class="subscriptions-summary-card <?= $status === 'active'
                ? 'selected'
                : ''; ?>"
        >
            <span class="subscriptions-summary-icon active">
                <i class="bi bi-check-circle"></i>
            </span>

            <span>
                <span class="subscriptions-summary-label">
                    Active
                </span>

                <span class="subscriptions-summary-value">
                    <?= number_format($summary['active']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= subscriptionsEscape(
                subscriptionsBuildQuery(
                    array(
                        'status' => 'trial',
                        'page' => 1
                    )
                )
            ); ?>"
            class="subscriptions-summary-card <?= $status === 'trial'
                ? 'selected'
                : ''; ?>"
        >
            <span class="subscriptions-summary-icon trial">
                <i class="bi bi-hourglass-split"></i>
            </span>

            <span>
                <span class="subscriptions-summary-label">
                    Trial
                </span>

                <span class="subscriptions-summary-value">
                    <?= number_format($summary['trial']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= subscriptionsEscape(
                subscriptionsBuildQuery(
                    array(
                        'status' => 'inactive',
                        'page' => 1
                    )
                )
            ); ?>"
            class="subscriptions-summary-card <?= $status === 'inactive'
                ? 'selected'
                : ''; ?>"
        >
            <span class="subscriptions-summary-icon inactive">
                <i class="bi bi-slash-circle"></i>
            </span>

            <span>
                <span class="subscriptions-summary-label">
                    Inactive
                </span>

                <span class="subscriptions-summary-value">
                    <?= number_format($summary['inactive']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= subscriptionsEscape(
                subscriptionsBuildQuery(
                    array(
                        'status' => 'expiring',
                        'page' => 1
                    )
                )
            ); ?>"
            class="subscriptions-summary-card <?= $status === 'expiring'
                ? 'selected'
                : ''; ?>"
        >
            <span class="subscriptions-summary-icon expiring">
                <i class="bi bi-calendar-event"></i>
            </span>

            <span>
                <span class="subscriptions-summary-label">
                    Expiring Soon
                </span>

                <span class="subscriptions-summary-value">
                    <?= number_format($summary['expiring']); ?>
                </span>
            </span>
        </a>

    </div>

    <div class="subscriptions-card">

        <form
            method="get"
            class="subscriptions-toolbar"
            id="subscriptionsFilterForm"
        >
            <div class="subscriptions-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="search"
                    class="form-control subscriptions-control"
                    value="<?= subscriptionsEscape($search); ?>"
                    placeholder="Search tenant, plan, or reference..."
                    autocomplete="off"
                >
            </div>

            <select
                name="tenant_id"
                class="form-select subscriptions-control"
                style="width:190px;"
            >
                <option value="0">
                    All tenants
                </option>

                <?php foreach ($tenantList as $tenantRow): ?>
                    <option
                        value="<?= (int) $tenantRow['tenant_id']; ?>"
                        <?= $tenantId ===
                            (int) $tenantRow['tenant_id']
                                ? 'selected'
                                : ''; ?>
                    >
                        <?= subscriptionsEscape(
                            $tenantRow['tenant_name']
                        ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if (
                $subscriptionStatusColumn !== ''
            ): ?>
                <select
                    name="status"
                    class="form-select subscriptions-control"
                    style="width:145px;"
                >
                    <option value="">
                        All statuses
                    </option>

                    <option
                        value="active"
                        <?= $status === 'active'
                            ? 'selected'
                            : ''; ?>
                    >
                        Active
                    </option>

                    <option
                        value="trial"
                        <?= $status === 'trial'
                            ? 'selected'
                            : ''; ?>
                    >
                        Trial
                    </option>

                    <option
                        value="inactive"
                        <?= $status === 'inactive'
                            ? 'selected'
                            : ''; ?>
                    >
                        Inactive
                    </option>

                    <option
                        value="expiring"
                        <?= $status === 'expiring'
                            ? 'selected'
                            : ''; ?>
                    >
                        Expiring Soon
                    </option>
                </select>
            <?php endif; ?>

            <select
                name="sort"
                class="form-select subscriptions-control"
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
                    value="expiry_asc"
                    <?= $sort === 'expiry_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Expiry ascending
                </option>

                <option
                    value="expiry_desc"
                    <?= $sort === 'expiry_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Expiry descending
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
                class="form-select subscriptions-control"
                style="width:90px;"
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
                class="subscriptions-filter-button"
            >
                <i class="bi bi-funnel"></i>
                Apply
            </button>

            <?php if (
                $search !== '' ||
                $tenantId > 0 ||
                $status !== '' ||
                $sort !== 'latest' ||
                $perPage !== 15
            ): ?>
                <a
                    href="subscriptions.php"
                    class="subscriptions-clear-button"
                    title="Clear filters"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($subscriptions)): ?>
            <div class="subscriptions-empty">
                <i class="bi bi-credit-card"></i>
                No subscriptions matched your filters.
            </div>
        <?php else: ?>

            <div class="subscriptions-table-wrap">
                <table class="subscriptions-table">
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Plan</th>
                            <th>Amount</th>
                            <th>Cycle</th>
                            <th>Start</th>
                            <th>Expiry</th>
                            <th>Status</th>
                            <th>Renewal</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach (
                            $subscriptions as $subscription
                        ): ?>
                            <?php
                            $subscriptionStatus = strtolower(
                                trim(
                                    (string)
                                    $subscription[
                                        'subscription_status'
                                    ]
                                )
                            );

                            if ($subscriptionStatus === '') {
                                $subscriptionStatus = 'active';
                            }

                            $amount = $subscription[
                                'subscription_amount'
                            ];

                            if (
                                $amount === null ||
                                $amount === ''
                            ) {
                                $amount =
                                    $subscription['plan_price'];
                            }

                            $currency = !empty(
                                $subscription[
                                    'subscription_currency'
                                ]
                            )
                                ? $subscription[
                                    'subscription_currency'
                                ]
                                : $subscription[
                                    'plan_currency'
                                ];

                            $billingCycle = !empty(
                                $subscription['billing_cycle']
                            )
                                ? $subscription['billing_cycle']
                                : $subscription[
                                    'plan_billing_cycle'
                                ];
                            ?>

                            <tr>
                                <td>
                                    <span class="subscription-tenant-name">
                                        <?= subscriptionsEscape(
                                            $subscription['tenant_name']
                                        ); ?>
                                    </span>

                                    <span class="subscription-meta">
                                        <?= subscriptionsEscape(
                                            !empty(
                                                $subscription[
                                                    'tenant_code'
                                                ]
                                            )
                                                ? $subscription[
                                                    'tenant_code'
                                                ]
                                                : 'Tenant ID ' .
                                                    (int)
                                                    $subscription[
                                                        'tenant_id'
                                                    ]
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="subscription-plan-name">
                                        <?= subscriptionsEscape(
                                            $subscription['plan_name']
                                        ); ?>
                                    </span>

                                    <span class="subscription-meta">
                                        <?= subscriptionsEscape(
                                            !empty(
                                                $subscription['plan_code']
                                            )
                                                ? $subscription['plan_code']
                                                : (
                                                    !empty(
                                                        $subscription[
                                                            'reference_no'
                                                        ]
                                                    )
                                                        ? $subscription[
                                                            'reference_no'
                                                        ]
                                                        : 'Subscription #' .
                                                            (int)
                                                            $subscription[
                                                                'subscription_id'
                                                            ]
                                                )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="subscription-amount">
                                        <?= subscriptionsEscape(
                                            subscriptionsMoney(
                                                $amount,
                                                $currency
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <?= subscriptionsEscape(
                                        subscriptionsLabel(
                                            $billingCycle
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <?= subscriptionsEscape(
                                        subscriptionsDate(
                                            $subscription['starts_at']
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <?= subscriptionsEscape(
                                        subscriptionsDate(
                                            !empty(
                                                $subscription['ends_at']
                                            )
                                                ? $subscription['ends_at']
                                                : $subscription[
                                                    'trial_ends_at'
                                                ]
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <span
                                        class="subscription-status <?= subscriptionsEscape(
                                            subscriptionsStatusClass(
                                                $subscriptionStatus
                                            )
                                        ); ?>"
                                    >
                                        <?= subscriptionsEscape(
                                            subscriptionsLabel(
                                                $subscriptionStatus
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="subscription-renew">
                                        <?= !empty(
                                            $subscription['auto_renew']
                                        )
                                            ? 'Auto Renew'
                                            : 'Manual'; ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="subscription-actions">
                                        <a
                                            href="subscription-view.php?id=<?= (int)
                                                $subscription[
                                                    'subscription_id'
                                                ]; ?>"
                                            class="subscription-action"
                                            title="View subscription"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <?php if (
                                            hasPlatformRole(array(
                                                'super_admin',
                                                'platform_admin',
                                                'billing_admin'
                                            ))
                                        ): ?>
                                            <a
                                                href="subscription-edit.php?id=<?= (int)
                                                    $subscription[
                                                        'subscription_id'
                                                    ]; ?>"
                                                class="subscription-action"
                                                title="Edit subscription"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>

                                        <a
                                            href="tenant-view.php?id=<?= (int)
                                                $subscription[
                                                    'tenant_id'
                                                ]; ?>"
                                            class="subscription-action"
                                            title="View tenant"
                                        >
                                            <i class="bi bi-building"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <div class="subscriptions-pagination-bar">
            <div class="subscriptions-pagination-info">
                Showing
                <?= number_format($startRecord); ?>
                to
                <?= number_format($endRecord); ?>
                of
                <?= number_format($totalRecords); ?>
                subscriptions
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Subscription pagination">
                    <ul class="subscriptions-pagination">
                        <li>
                            <?php if ($page > 1): ?>
                                <a
                                    href="?<?= subscriptionsEscape(
                                        subscriptionsBuildQuery(
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
                                    href="?<?= subscriptionsEscape(
                                        subscriptionsBuildQuery(
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
                                <?php if (
                                    $pageNumber === $page
                                ): ?>
                                    <span class="active">
                                        <?= (int) $pageNumber; ?>
                                    </span>
                                <?php else: ?>
                                    <a
                                        href="?<?= subscriptionsEscape(
                                            subscriptionsBuildQuery(
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
                                    href="?<?= subscriptionsEscape(
                                        subscriptionsBuildQuery(
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
                                    href="?<?= subscriptionsEscape(
                                        subscriptionsBuildQuery(
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

    </div>
</div>

<script>
(function () {
    'use strict';

    const form = document.getElementById(
        'subscriptionsFilterForm'
    );

    if (!form) {
        return;
    }

    const selects = form.querySelectorAll('select');

    selects.forEach(function (select) {
        select.addEventListener(
            'change',
            function () {
                form.submit();
            }
        );
    });

    const searchInput = form.querySelector(
        'input[name="search"]'
    );

    let searchTimer = null;

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            function () {
                window.clearTimeout(searchTimer);

                searchTimer = window.setTimeout(
                    function () {
                        form.submit();
                    },
                    600
                );
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
