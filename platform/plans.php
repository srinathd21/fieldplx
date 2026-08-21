<?php
/**
 * FieldPlx Platform - Subscription Plans
 *
 * File:
 * platform/plans.php
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

$pageTitle = 'Subscription Plans - FieldPlx';
$activePage = 'plans';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('plansEscape')) {
    function plansEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('plansTableExists')) {
    function plansTableExists(
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

if (!function_exists('plansColumns')) {
    function plansColumns(
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

if (!function_exists('plansFirstColumn')) {
    function plansFirstColumn(
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

if (!function_exists('plansBind')) {
    function plansBind(
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

if (!function_exists('plansLabel')) {
    function plansLabel($value)
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

if (!function_exists('plansMoney')) {
    function plansMoney($amount, $currency)
    {
        $amount = is_numeric($amount)
            ? (float) $amount
            : 0;

        $currency = strtoupper(trim((string) $currency));

        if ($currency === '') {
            $currency = 'INR';
        }

        $symbol = $currency . ' ';

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

if (!function_exists('plansStatusClass')) {
    function plansStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
                return 'success';

            case 'draft':
            case 'pending':
                return 'warning';

            case 'inactive':
            case 'disabled':
            case 'archived':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('plansDate')) {
    function plansDate($value)
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

if (!function_exists('plansBuildQuery')) {
    function plansBuildQuery(array $changes = array())
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
| Verify plans table
|--------------------------------------------------------------------------
*/

if (!plansTableExists($conn, 'plans')) {
    http_response_code(500);
    exit('The plans table does not exist.');
}

$planColumns = plansColumns($conn, 'plans');

$hasSubscriptionsTable = plansTableExists(
    $conn,
    'subscriptions'
);

$subscriptionColumns = $hasSubscriptionsTable
    ? plansColumns($conn, 'subscriptions')
    : array();


$hasPlanModulesTable = plansTableExists(
    $conn,
    'plan_modules'
);

$hasPlanFeaturesTable = plansTableExists(
    $conn,
    'plan_features'
);

$hasModulesTable = plansTableExists(
    $conn,
    'modules'
);

$hasModuleFeaturesTable = plansTableExists(
    $conn,
    'module_features'
);

/*
|--------------------------------------------------------------------------
| Detect plan columns
|--------------------------------------------------------------------------
*/

$planIdColumn = plansFirstColumn(
    $planColumns,
    array('id', 'plan_id')
);

$planNameColumn = plansFirstColumn(
    $planColumns,
    array('name', 'plan_name')
);

$planCodeColumn = plansFirstColumn(
    $planColumns,
    array('code', 'plan_code')
);

$planDescriptionColumn = plansFirstColumn(
    $planColumns,
    array('description', 'notes', 'remarks')
);

$planPriceColumn = plansFirstColumn(
    $planColumns,
    array('price', 'amount', 'plan_amount')
);

$planCurrencyColumn = plansFirstColumn(
    $planColumns,
    array('currency', 'currency_code')
);

$planBillingCycleColumn = plansFirstColumn(
    $planColumns,
    array(
        'billing_cycle',
        'billing_period',
        'interval',
        'cycle'
    )
);

$planTrialDaysColumn = plansFirstColumn(
    $planColumns,
    array('trial_days', 'free_trial_days')
);

$planMaxUsersColumn = plansFirstColumn(
    $planColumns,
    array(
        'max_users',
        'user_limit',
        'maximum_users'
    )
);

$planMaxBranchesColumn = plansFirstColumn(
    $planColumns,
    array(
        'max_branches',
        'branch_limit',
        'maximum_branches'
    )
);

$planStorageColumn = plansFirstColumn(
    $planColumns,
    array(
        'storage_limit',
        'storage_limit_mb',
        'storage_mb'
    )
);

$planStatusColumn = plansFirstColumn(
    $planColumns,
    array('status')
);

$planFeaturedColumn = plansFirstColumn(
    $planColumns,
    array(
        'is_featured',
        'featured',
        'is_popular'
    )
);

$planCreatedAtColumn = plansFirstColumn(
    $planColumns,
    array('created_at', 'created_on')
);

$planUpdatedAtColumn = plansFirstColumn(
    $planColumns,
    array('updated_at', 'updated_on')
);

$planDeletedColumn = plansFirstColumn(
    $planColumns,
    array('deleted_at')
);

if (
    $planIdColumn === '' ||
    $planNameColumn === ''
) {
    http_response_code(500);
    exit('The plans table requires id and name columns.');
}

/*
|--------------------------------------------------------------------------
| Detect subscription columns
|--------------------------------------------------------------------------
*/

$subscriptionPlanColumn = '';
$subscriptionStatusColumn = '';
$subscriptionDeletedColumn = '';

if ($hasSubscriptionsTable) {
    $subscriptionPlanColumn = plansFirstColumn(
        $subscriptionColumns,
        array('plan_id')
    );

    $subscriptionStatusColumn = plansFirstColumn(
        $subscriptionColumns,
        array('status')
    );

    $subscriptionDeletedColumn = plansFirstColumn(
        $subscriptionColumns,
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

$billingCycle = isset($_GET['billing_cycle']) &&
    !is_array($_GET['billing_cycle'])
        ? strtolower(
            trim((string) $_GET['billing_cycle'])
        )
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

$allowedPerPage = array(10, 15, 25, 50);

if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 15;
}

$allowedSorts = array(
    'latest',
    'oldest',
    'name_asc',
    'name_desc',
    'price_asc',
    'price_desc'
);

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'latest';
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summaryWhere = array();

if ($planDeletedColumn !== '') {
    $summaryWhere[] =
        "`{$planDeletedColumn}` IS NULL";
}

$summarySql = "
    SELECT COUNT(*) AS total_count
";

if ($planStatusColumn !== '') {
    $summarySql .= ",
        SUM(
            CASE
                WHEN `{$planStatusColumn}` = 'active'
                THEN 1 ELSE 0
            END
        ) AS active_count,
        SUM(
            CASE
                WHEN `{$planStatusColumn}` IN (
                    'inactive',
                    'disabled',
                    'archived'
                )
                THEN 1 ELSE 0
            END
        ) AS inactive_count
    ";
}

if ($planFeaturedColumn !== '') {
    $summarySql .= ",
        SUM(
            CASE
                WHEN `{$planFeaturedColumn}` = 1
                THEN 1 ELSE 0
            END
        ) AS featured_count
    ";
}

$summarySql .= "
    FROM plans
";

if (!empty($summaryWhere)) {
    $summarySql .= "
        WHERE " . implode(' AND ', $summaryWhere);
}

$summary = array(
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'featured' => 0
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

    $summary['inactive'] = isset(
        $summaryRow['inactive_count']
    )
        ? (int) $summaryRow['inactive_count']
        : 0;

    $summary['featured'] = isset(
        $summaryRow['featured_count']
    )
        ? (int) $summaryRow['featured_count']
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

if ($planDeletedColumn !== '') {
    $where[] =
        "p.`{$planDeletedColumn}` IS NULL";
}

if (
    $status !== '' &&
    $planStatusColumn !== ''
) {
    if ($status === 'inactive') {
        $where[] =
            "p.`{$planStatusColumn}` IN (
                'inactive',
                'disabled',
                'archived'
            )";
    } else {
        $where[] =
            "p.`{$planStatusColumn}` = ?";

        $types .= 's';
        $params[] = $status;
    }
}

if (
    $billingCycle !== '' &&
    $planBillingCycleColumn !== ''
) {
    $where[] =
        "p.`{$planBillingCycleColumn}` = ?";

    $types .= 's';
    $params[] = $billingCycle;
}

if ($search !== '') {
    $searchConditions = array();

    $searchConditions[] =
        "p.`{$planNameColumn}` LIKE ?";

    $types .= 's';
    $params[] = '%' . $search . '%';

    if ($planCodeColumn !== '') {
        $searchConditions[] =
            "p.`{$planCodeColumn}` LIKE ?";

        $types .= 's';
        $params[] = '%' . $search . '%';
    }

    if ($planDescriptionColumn !== '') {
        $searchConditions[] =
            "p.`{$planDescriptionColumn}` LIKE ?";

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
| Subscription counts join
|--------------------------------------------------------------------------
*/

$subscriptionJoinSql = '';

if (
    $hasSubscriptionsTable &&
    $subscriptionPlanColumn !== ''
) {
    $subscriptionJoinSql = "
        LEFT JOIN subscriptions s
            ON s.`{$subscriptionPlanColumn}` =
               p.`{$planIdColumn}`
    ";

    if ($subscriptionDeletedColumn !== '') {
        $subscriptionJoinSql .= "
            AND s.`{$subscriptionDeletedColumn}` IS NULL
        ";
    }
}


/*
|--------------------------------------------------------------------------
| Module and feature count joins
|--------------------------------------------------------------------------
*/

$planModuleJoinSql = '';
$planFeatureJoinSql = '';

if (
    $hasPlanModulesTable &&
    $hasModulesTable
) {
    $planModuleJoinSql = "
        LEFT JOIN (
            SELECT
                pm.`plan_id`,
                COUNT(DISTINCT pm.`module_id`) AS module_count
            FROM plan_modules pm
            INNER JOIN modules m
                ON m.`id` = pm.`module_id`
            WHERE pm.`is_enabled` = 1
            GROUP BY pm.`plan_id`
        ) module_stats
            ON module_stats.`plan_id` =
               p.`{$planIdColumn}`
    ";
}

if (
    $hasPlanFeaturesTable &&
    $hasModuleFeaturesTable
) {
    $planFeatureJoinSql = "
        LEFT JOIN (
            SELECT
                pf.`plan_id`,
                COUNT(DISTINCT pf.`feature_id`) AS feature_count
            FROM plan_features pf
            INNER JOIN module_features mf
                ON mf.`id` = pf.`feature_id`
            WHERE pf.`is_enabled` = 1
            GROUP BY pf.`plan_id`
        ) feature_stats
            ON feature_stats.`plan_id` =
               p.`{$planIdColumn}`
    ";
}

/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM plans p
    {$whereSql}
";

$countStmt = $conn->prepare($countSql);

plansBind(
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
        $sortColumn = $planCreatedAtColumn !== ''
            ? "p.`{$planCreatedAtColumn}`"
            : "p.`{$planIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} ASC";
        break;

    case 'name_asc':
        $orderSql =
            "ORDER BY p.`{$planNameColumn}` ASC";
        break;

    case 'name_desc':
        $orderSql =
            "ORDER BY p.`{$planNameColumn}` DESC";
        break;

    case 'price_asc':
        $sortColumn = $planPriceColumn !== ''
            ? "p.`{$planPriceColumn}`"
            : "p.`{$planIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} ASC";
        break;

    case 'price_desc':
        $sortColumn = $planPriceColumn !== ''
            ? "p.`{$planPriceColumn}`"
            : "p.`{$planIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} DESC";
        break;

    case 'latest':
    default:
        $sortColumn = $planCreatedAtColumn !== ''
            ? "p.`{$planCreatedAtColumn}`"
            : "p.`{$planIdColumn}`";

        $orderSql =
            "ORDER BY {$sortColumn} DESC";
        break;
}

/*
|--------------------------------------------------------------------------
| Select plans
|--------------------------------------------------------------------------
*/

$select = array(
    "p.`{$planIdColumn}` AS plan_id",
    "p.`{$planNameColumn}` AS plan_name"
);

$select[] = $planCodeColumn !== ''
    ? "p.`{$planCodeColumn}` AS plan_code"
    : "'' AS plan_code";

$select[] = $planDescriptionColumn !== ''
    ? "p.`{$planDescriptionColumn}` AS plan_description"
    : "'' AS plan_description";

$select[] = $planPriceColumn !== ''
    ? "p.`{$planPriceColumn}` AS plan_price"
    : "0 AS plan_price";

$select[] = $planCurrencyColumn !== ''
    ? "p.`{$planCurrencyColumn}` AS plan_currency"
    : "'INR' AS plan_currency";

$select[] = $planBillingCycleColumn !== ''
    ? "p.`{$planBillingCycleColumn}` AS billing_cycle"
    : "'' AS billing_cycle";

$select[] = $planTrialDaysColumn !== ''
    ? "p.`{$planTrialDaysColumn}` AS trial_days"
    : "0 AS trial_days";

$select[] = $planMaxUsersColumn !== ''
    ? "p.`{$planMaxUsersColumn}` AS max_users"
    : "NULL AS max_users";

$select[] = $planMaxBranchesColumn !== ''
    ? "p.`{$planMaxBranchesColumn}` AS max_branches"
    : "NULL AS max_branches";

$select[] = $planStorageColumn !== ''
    ? "p.`{$planStorageColumn}` AS storage_limit"
    : "NULL AS storage_limit";

$select[] = $planStatusColumn !== ''
    ? "p.`{$planStatusColumn}` AS plan_status"
    : "'active' AS plan_status";

$select[] = $planFeaturedColumn !== ''
    ? "p.`{$planFeaturedColumn}` AS is_featured"
    : "0 AS is_featured";

$select[] = $planCreatedAtColumn !== ''
    ? "p.`{$planCreatedAtColumn}` AS created_at"
    : "NULL AS created_at";

$select[] = $planUpdatedAtColumn !== ''
    ? "p.`{$planUpdatedAtColumn}` AS updated_at"
    : "NULL AS updated_at";


$select[] = $hasPlanModulesTable &&
    $hasModulesTable
        ? "COALESCE(module_stats.`module_count`, 0) AS module_count"
        : "0 AS module_count";

$select[] = $hasPlanFeaturesTable &&
    $hasModuleFeaturesTable
        ? "COALESCE(feature_stats.`feature_count`, 0) AS feature_count"
        : "0 AS feature_count";

if (
    $hasSubscriptionsTable &&
    $subscriptionPlanColumn !== ''
) {
    $select[] =
        "COUNT(s.`{$subscriptionPlanColumn}`) AS subscription_count";

    if ($subscriptionStatusColumn !== '') {
        $select[] = "
            SUM(
                CASE
                    WHEN s.`{$subscriptionStatusColumn}` = 'active'
                    THEN 1 ELSE 0
                END
            ) AS active_subscription_count
        ";
    } else {
        $select[] =
            "COUNT(s.`{$subscriptionPlanColumn}`) AS active_subscription_count";
    }
} else {
    $select[] = "0 AS subscription_count";
    $select[] = "0 AS active_subscription_count";
}

$listSql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM plans p
    {$subscriptionJoinSql}
    {$planModuleJoinSql}
    {$planFeatureJoinSql}
    {$whereSql}
";

if (
    $hasSubscriptionsTable &&
    $subscriptionPlanColumn !== ''
) {
    $listSql .= "
        GROUP BY p.`{$planIdColumn}`
    ";
}

$listSql .= "
    {$orderSql}
    LIMIT ? OFFSET ?
";

$listStmt = $conn->prepare($listSql);

$listParams = $params;
$listParams[] = $perPage;
$listParams[] = $offset;

$listTypes = $types . 'ii';

plansBind(
    $listStmt,
    $listTypes,
    $listParams
);

$listStmt->execute();

$listResult = $listStmt->get_result();
$plans = array();

while ($row = $listResult->fetch_assoc()) {
    $plans[] = $row;
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
    .plans-page {
        display: grid;
        gap: 15px;
    }

    .plans-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .plans-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .plans-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .plans-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .plans-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .plans-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .plans-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .plans-button {
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

    .plans-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .plans-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .plans-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .plans-summary {
        display: grid;
        grid-template-columns:
            repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .plans-summary-card {
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

    .plans-summary-card:hover,
    .plans-summary-card.selected {
        border-color: #ddd6fe;
        background: #faf8ff;
    }

    .plans-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 14px;
    }

    .plans-summary-icon.total {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .plans-summary-icon.active {
        background: #ecfdf5;
        color: #059669;
    }

    .plans-summary-icon.inactive {
        background: #fef2f2;
        color: #dc2626;
    }

    .plans-summary-icon.featured {
        background: #fff7ed;
        color: #d97706;
    }

    .plans-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .plans-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .plans-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .plans-toolbar {
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 9px;
        border-bottom: 1px solid #eef0f3;
    }

    .plans-search {
        min-width: 220px;
        position: relative;
        flex: 1;
    }

    .plans-search i {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 12px;
    }

    .plans-control {
        height: 36px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    .plans-search .plans-control {
        padding-left: 33px;
    }

    .plans-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .plans-filter-button {
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

    .plans-clear-button {
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

    .plans-table-wrap {
        overflow-x: auto;
    }

    .plans-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .plans-table th {
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

    .plans-table td {
        padding: 11px 13px;
        border-bottom: 1px solid #f0f1f3;
        color: #374151;
        font-size: 9px;
        vertical-align: middle;
    }

    .plans-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .plans-table tbody tr:hover {
        background: #fcfbff;
    }

    .plan-name {
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .plan-code {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .plan-description {
        max-width: 270px;
        color: #6b7280;
        font-size: 8px;
        line-height: 1.45;
    }

    .plan-price {
        color: #111827;
        font-size: 11px;
        font-weight: 800;
    }

    .plan-cycle {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .plan-limit {
        display: block;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .plan-limit + .plan-limit {
        margin-top: 3px;
    }

    .plan-status {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .plan-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .plan-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .plan-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .plan-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .plan-featured {
        margin-top: 4px;
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #fff7ed;
        color: #b45309;
        font-size: 8px;
        font-weight: 700;
    }

    .plan-subscription-count {
        color: #111827;
        font-size: 10px;
        font-weight: 800;
    }


    .plan-access-link {
        min-width: 120px;
        display: grid;
        gap: 4px;
        color: #4b5563;
        text-decoration: none;
    }

    .plan-access-link:hover {
        color: #7c3aed;
    }

    .plan-access-count {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 8px;
        font-weight: 700;
        white-space: nowrap;
    }

    .plan-access-count i {
        color: #7c3aed;
        font-size: 9px;
    }

    .plan-actions {
        display: flex;
        justify-content: flex-end;
        gap: 5px;
    }

    .plan-action {
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

    .plan-action:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
        color: #7c3aed;
    }

    .plans-empty {
        padding: 48px 20px;
        color: #9ca3af;
        text-align: center;
        font-size: 10px;
    }

    .plans-empty i {
        margin-bottom: 10px;
        display: block;
        color: #c4b5fd;
        font-size: 30px;
    }

    .plans-pagination-bar {
        min-height: 54px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-top: 1px solid #eef0f3;
    }

    .plans-pagination-info {
        color: #6b7280;
        font-size: 9px;
    }

    .plans-pagination {
        margin: 0;
        display: flex;
        gap: 4px;
        list-style: none;
    }

    .plans-pagination a,
    .plans-pagination span {
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

    .plans-pagination a:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .plans-pagination .active {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .plans-pagination .disabled {
        opacity: 0.45;
        pointer-events: none;
    }

    @media (max-width: 1000px) {
        .plans-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 800px) {
        .plans-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .plans-actions {
            width: 100%;
        }

        .plans-button {
            flex: 1;
        }

        .plans-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .plans-search {
            min-width: 0;
        }

        .plans-toolbar .plans-control,
        .plans-filter-button,
        .plans-clear-button {
            width: 100% !important;
        }

        .plans-pagination-bar {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    @media (max-width: 480px) {
        .plans-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="plans-page">

    <?php if ($successMessage !== ''): ?>
        <div class="plans-alert success">
            <i class="bi bi-check-circle"></i>
            <span><?= plansEscape($successMessage); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="plans-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span><?= plansEscape($errorMessage); ?></span>
        </div>
    <?php endif; ?>

    <div class="plans-header">
        <div>
            <h2 class="plans-title">
                Subscription Plans
            </h2>

            <div class="plans-description">
                Manage plan pricing, limits, billing cycles, and availability.
            </div>
        </div>

        <div class="plans-actions">
            <a
                href="subscriptions.php"
                class="plans-button"
            >
                <i class="bi bi-credit-card"></i>
                View Subscriptions
            </a>


            <?php if (
                hasPlatformRole(array(
                    'super_admin',
                    'platform_admin',
                    'billing_admin'
                ))
            ): ?>
                <a
                    href="plan-modules.php"
                    class="plans-button"
                >
                    <i class="bi bi-grid-3x3-gap"></i>
                    Plan Modules
                </a>
            <?php endif; ?>

            <?php if (
                hasPlatformRole(array(
                    'super_admin',
                    'platform_admin',
                    'billing_admin'
                ))
            ): ?>
                <a
                    href="plan-add.php"
                    class="plans-button primary"
                >
                    <i class="bi bi-plus-circle"></i>
                    Add Plan
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="plans-summary">

        <a
            href="?<?= plansEscape(
                plansBuildQuery(
                    array(
                        'status' => '',
                        'page' => 1
                    )
                )
            ); ?>"
            class="plans-summary-card <?= $status === ''
                ? 'selected'
                : ''; ?>"
        >
            <span class="plans-summary-icon total">
                <i class="bi bi-card-list"></i>
            </span>

            <span>
                <span class="plans-summary-label">
                    Total Plans
                </span>

                <span class="plans-summary-value">
                    <?= number_format($summary['total']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= plansEscape(
                plansBuildQuery(
                    array(
                        'status' => 'active',
                        'page' => 1
                    )
                )
            ); ?>"
            class="plans-summary-card <?= $status === 'active'
                ? 'selected'
                : ''; ?>"
        >
            <span class="plans-summary-icon active">
                <i class="bi bi-check-circle"></i>
            </span>

            <span>
                <span class="plans-summary-label">
                    Active
                </span>

                <span class="plans-summary-value">
                    <?= number_format($summary['active']); ?>
                </span>
            </span>
        </a>

        <a
            href="?<?= plansEscape(
                plansBuildQuery(
                    array(
                        'status' => 'inactive',
                        'page' => 1
                    )
                )
            ); ?>"
            class="plans-summary-card <?= $status === 'inactive'
                ? 'selected'
                : ''; ?>"
        >
            <span class="plans-summary-icon inactive">
                <i class="bi bi-slash-circle"></i>
            </span>

            <span>
                <span class="plans-summary-label">
                    Inactive
                </span>

                <span class="plans-summary-value">
                    <?= number_format($summary['inactive']); ?>
                </span>
            </span>
        </a>

        <div class="plans-summary-card">
            <span class="plans-summary-icon featured">
                <i class="bi bi-star"></i>
            </span>

            <span>
                <span class="plans-summary-label">
                    Featured
                </span>

                <span class="plans-summary-value">
                    <?= number_format($summary['featured']); ?>
                </span>
            </span>
        </div>

    </div>

    <div class="plans-card">

        <form
            method="get"
            class="plans-toolbar"
            id="plansFilterForm"
        >
            <div class="plans-search">
                <i class="bi bi-search"></i>

                <input
                    type="search"
                    name="search"
                    class="form-control plans-control"
                    value="<?= plansEscape($search); ?>"
                    placeholder="Search plan name, code, or description..."
                    autocomplete="off"
                >
            </div>

            <?php if ($planStatusColumn !== ''): ?>
                <select
                    name="status"
                    class="form-select plans-control"
                    style="width:140px;"
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
                        value="inactive"
                        <?= $status === 'inactive'
                            ? 'selected'
                            : ''; ?>
                    >
                        Inactive
                    </option>
                </select>
            <?php endif; ?>

            <?php if (
                $planBillingCycleColumn !== ''
            ): ?>
                <select
                    name="billing_cycle"
                    class="form-select plans-control"
                    style="width:150px;"
                >
                    <option value="">
                        All billing cycles
                    </option>

                    <?php
                    $cycles = array(
                        'monthly',
                        'quarterly',
                        'half_yearly',
                        'yearly',
                        'lifetime',
                        'custom'
                    );
                    ?>

                    <?php foreach ($cycles as $cycle): ?>
                        <option
                            value="<?= plansEscape($cycle); ?>"
                            <?= $billingCycle === $cycle
                                ? 'selected'
                                : ''; ?>
                        >
                            <?= plansEscape(
                                plansLabel($cycle)
                            ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <select
                name="sort"
                class="form-select plans-control"
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
                    value="name_asc"
                    <?= $sort === 'name_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Name A-Z
                </option>

                <option
                    value="name_desc"
                    <?= $sort === 'name_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Name Z-A
                </option>

                <option
                    value="price_asc"
                    <?= $sort === 'price_asc'
                        ? 'selected'
                        : ''; ?>
                >
                    Price low-high
                </option>

                <option
                    value="price_desc"
                    <?= $sort === 'price_desc'
                        ? 'selected'
                        : ''; ?>
                >
                    Price high-low
                </option>
            </select>

            <select
                name="per_page"
                class="form-select plans-control"
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
                class="plans-filter-button"
            >
                <i class="bi bi-funnel"></i>
                Apply
            </button>

            <?php if (
                $search !== '' ||
                $status !== '' ||
                $billingCycle !== '' ||
                $sort !== 'latest' ||
                $perPage !== 15
            ): ?>
                <a
                    href="plans.php"
                    class="plans-clear-button"
                    title="Clear filters"
                >
                    <i class="bi bi-x-lg"></i>
                </a>
            <?php endif; ?>
        </form>

        <?php if (empty($plans)): ?>
            <div class="plans-empty">
                <i class="bi bi-card-list"></i>
                No plans matched your filters.
            </div>
        <?php else: ?>

            <div class="plans-table-wrap">
                <table class="plans-table">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Price</th>
                            <th>Limits</th>
                            <th>Trial</th>
                            <th>Modules & Features</th>
                            <th>Subscriptions</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th style="text-align:right;">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($plans as $plan): ?>
                            <?php
                            $planStatus = strtolower(
                                trim(
                                    (string)
                                    $plan['plan_status']
                                )
                            );

                            if ($planStatus === '') {
                                $planStatus = 'active';
                            }
                            ?>

                            <tr>
                                <td>
                                    <span class="plan-name">
                                        <?= plansEscape(
                                            $plan['plan_name']
                                        ); ?>
                                    </span>

                                    <span class="plan-code">
                                        <?= plansEscape(
                                            !empty($plan['plan_code'])
                                                ? $plan['plan_code']
                                                : 'Plan ID ' .
                                                    (int) $plan['plan_id']
                                        ); ?>
                                    </span>

                                    <?php if (
                                        !empty($plan['is_featured'])
                                    ): ?>
                                        <span class="plan-featured">
                                            <i class="bi bi-star-fill me-1"></i>
                                            Featured
                                        </span>
                                    <?php endif; ?>

                                    <div class="plan-description">
                                        <?= plansEscape(
                                            !empty(
                                                $plan['plan_description']
                                            )
                                                ? $plan['plan_description']
                                                : 'No description'
                                        ); ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="plan-price">
                                        <?= plansEscape(
                                            plansMoney(
                                                $plan['plan_price'],
                                                $plan['plan_currency']
                                            )
                                        ); ?>
                                    </span>

                                    <span class="plan-cycle">
                                        <?= plansEscape(
                                            plansLabel(
                                                $plan['billing_cycle']
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="plan-limit">
                                        Users:
                                        <?= $plan['max_users'] === null ||
                                            $plan['max_users'] === ''
                                                ? 'Unlimited'
                                                : number_format(
                                                    (int)
                                                    $plan['max_users']
                                                ); ?>
                                    </span>

                                    <span class="plan-limit">
                                        Branches:
                                        <?= $plan['max_branches'] === null ||
                                            $plan['max_branches'] === ''
                                                ? 'Unlimited'
                                                : number_format(
                                                    (int)
                                                    $plan['max_branches']
                                                ); ?>
                                    </span>

                                    <?php if (
                                        $plan['storage_limit'] !== null &&
                                        $plan['storage_limit'] !== ''
                                    ): ?>
                                        <span class="plan-limit">
                                            Storage:
                                            <?= number_format(
                                                (float)
                                                $plan['storage_limit']
                                            ); ?>
                                            MB
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= (int) $plan['trial_days'] > 0
                                        ? number_format(
                                            (int) $plan['trial_days']
                                        ) . ' days'
                                        : 'No trial'; ?>
                                </td>


                                <td>
                                    <?php if (
                                        hasPlatformRole(array(
                                            'super_admin',
                                            'platform_admin',
                                            'billing_admin'
                                        ))
                                    ): ?>
                                        <a
                                            href="plan-modules.php?plan_id=<?= (int)
                                                $plan['plan_id']; ?>"
                                            class="plan-access-link"
                                            title="Configure plan modules and features"
                                        >
                                            <span class="plan-access-count">
                                                <i class="bi bi-grid-3x3-gap"></i>
                                                <?= number_format(
                                                    (int)
                                                    $plan['module_count']
                                                ); ?>
                                                modules
                                            </span>

                                            <span class="plan-access-count">
                                                <i class="bi bi-ui-checks"></i>
                                                <?= number_format(
                                                    (int)
                                                    $plan['feature_count']
                                                ); ?>
                                                features
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <span class="plan-access-link">
                                            <span class="plan-access-count">
                                                <i class="bi bi-grid-3x3-gap"></i>
                                                <?= number_format(
                                                    (int)
                                                    $plan['module_count']
                                                ); ?>
                                                modules
                                            </span>

                                            <span class="plan-access-count">
                                                <i class="bi bi-ui-checks"></i>
                                                <?= number_format(
                                                    (int)
                                                    $plan['feature_count']
                                                ); ?>
                                                features
                                            </span>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="plan-subscription-count">
                                        <?= number_format(
                                            (int)
                                            $plan['subscription_count']
                                        ); ?>
                                    </span>

                                    <span class="plan-cycle">
                                        <?= number_format(
                                            (int)
                                            $plan[
                                                'active_subscription_count'
                                            ]
                                        ); ?>
                                        active
                                    </span>
                                </td>

                                <td>
                                    <span
                                        class="plan-status <?= plansEscape(
                                            plansStatusClass(
                                                $planStatus
                                            )
                                        ); ?>"
                                    >
                                        <?= plansEscape(
                                            plansLabel(
                                                $planStatus
                                            )
                                        ); ?>
                                    </span>
                                </td>

                                <td>
                                    <?= plansEscape(
                                        plansDate(
                                            $plan['created_at']
                                        )
                                    ); ?>
                                </td>

                                <td>
                                    <div class="plan-actions">
                                        <a
                                            href="plan-view.php?id=<?= (int)
                                                $plan['plan_id']; ?>"
                                            class="plan-action"
                                            title="View plan"
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
                                                href="plan-edit.php?id=<?= (int)
                                                    $plan['plan_id']; ?>"
                                                class="plan-action"
                                                title="Edit plan"
                                            >
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if (
                                            hasPlatformRole(array(
                                                'super_admin',
                                                'platform_admin',
                                                'billing_admin'
                                            ))
                                        ): ?>
                                            <a
                                                href="plan-modules.php?plan_id=<?= (int)
                                                    $plan['plan_id']; ?>"
                                                class="plan-action"
                                                title="Configure modules and features"
                                            >
                                                <i class="bi bi-grid-3x3-gap"></i>
                                            </a>
                                        <?php endif; ?>

                                        <a
                                            href="subscriptions.php?plan_id=<?= (int)
                                                $plan['plan_id']; ?>"
                                            class="plan-action"
                                            title="View subscriptions"
                                        >
                                            <i class="bi bi-credit-card"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <div class="plans-pagination-bar">
            <div class="plans-pagination-info">
                Showing
                <?= number_format($startRecord); ?>
                to
                <?= number_format($endRecord); ?>
                of
                <?= number_format($totalRecords); ?>
                plans
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Plan pagination">
                    <ul class="plans-pagination">
                        <li>
                            <?php if ($page > 1): ?>
                                <a
                                    href="?<?= plansEscape(
                                        plansBuildQuery(
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
                                    href="?<?= plansEscape(
                                        plansBuildQuery(
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
                                        href="?<?= plansEscape(
                                            plansBuildQuery(
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
                                    href="?<?= plansEscape(
                                        plansBuildQuery(
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
                                    href="?<?= plansEscape(
                                        plansBuildQuery(
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
        'plansFilterForm'
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
