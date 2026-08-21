<?php
/**
 * FieldPlx Platform - View Subscription Plan
 *
 * File:
 * platform/plan-view.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - Multi-currency plan_prices table
 */

require_once __DIR__ . '/includes/auth.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin',
    'platform_read_only'
));

$pageTitle = 'Plan Details - FieldPlx';
$activePage = 'plans';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('planViewEscape')) {
    function planViewEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('planViewTableExists')) {
    function planViewTableExists(
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

if (!function_exists('planViewColumns')) {
    function planViewColumns(
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

        while ($row = $result->fetch_assoc()) {
            $cache[$tableName][
                (string) $row['Field']
            ] = $row;
        }

        $result->free();

        return $cache[$tableName];
    }
}

if (!function_exists('planViewFirstColumn')) {
    function planViewFirstColumn(
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

if (!function_exists('planViewLabel')) {
    function planViewLabel($value)
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

if (!function_exists('planViewDate')) {
    function planViewDate(
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

if (!function_exists('planViewStatusClass')) {
    function planViewStatusClass($status)
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

if (!function_exists('planViewCurrencySymbol')) {
    function planViewCurrencySymbol($currency)
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

if (!function_exists('planViewMoney')) {
    function planViewMoney($amount, $currency)
    {
        $amount = is_numeric($amount)
            ? (float) $amount
            : 0;

        return planViewCurrencySymbol($currency) .
            number_format($amount, 2);
    }
}

if (!function_exists('planViewLimit')) {
    function planViewLimit($value)
    {
        if (
            $value === null ||
            $value === ''
        ) {
            return 'Unlimited';
        }

        return number_format((int) $value);
    }
}

/*
|--------------------------------------------------------------------------
| Verify tables
|--------------------------------------------------------------------------
*/

if (!planViewTableExists($conn, 'plans')) {
    http_response_code(500);
    exit('The plans table does not exist.');
}

$planColumns = planViewColumns($conn, 'plans');

$hasPlanPricesTable =
    planViewTableExists($conn, 'plan_prices');

$hasSubscriptionsTable =
    planViewTableExists($conn, 'subscriptions');


$hasPlanModulesTable =
    planViewTableExists($conn, 'plan_modules');

$hasPlanFeaturesTable =
    planViewTableExists($conn, 'plan_features');

$hasModulesTable =
    planViewTableExists($conn, 'modules');

$hasModuleFeaturesTable =
    planViewTableExists($conn, 'module_features');

/*
|--------------------------------------------------------------------------
| Detect plan columns
|--------------------------------------------------------------------------
*/

$planIdColumn = planViewFirstColumn(
    $planColumns,
    array('id', 'plan_id')
);

$planNameColumn = planViewFirstColumn(
    $planColumns,
    array('name', 'plan_name')
);

$planCodeColumn = planViewFirstColumn(
    $planColumns,
    array('code', 'plan_code')
);

$planDescriptionColumn = planViewFirstColumn(
    $planColumns,
    array('description', 'notes', 'remarks')
);

$planPriceColumn = planViewFirstColumn(
    $planColumns,
    array('price', 'amount', 'plan_amount')
);

$planCurrencyColumn = planViewFirstColumn(
    $planColumns,
    array('currency', 'currency_code')
);

$planBillingCycleColumn = planViewFirstColumn(
    $planColumns,
    array(
        'billing_cycle',
        'billing_period',
        'interval',
        'cycle'
    )
);

$planTrialDaysColumn = planViewFirstColumn(
    $planColumns,
    array('trial_days', 'free_trial_days')
);

$planMaxUsersColumn = planViewFirstColumn(
    $planColumns,
    array(
        'max_users',
        'user_limit',
        'maximum_users'
    )
);

$planMaxBranchesColumn = planViewFirstColumn(
    $planColumns,
    array(
        'max_branches',
        'branch_limit',
        'maximum_branches'
    )
);

$planStorageColumn = planViewFirstColumn(
    $planColumns,
    array(
        'storage_limit_mb',
        'storage_limit',
        'storage_mb'
    )
);

$planFeaturedColumn = planViewFirstColumn(
    $planColumns,
    array(
        'is_featured',
        'featured',
        'is_popular'
    )
);

$planStatusColumn = planViewFirstColumn(
    $planColumns,
    array('status')
);

$planCreatedAtColumn = planViewFirstColumn(
    $planColumns,
    array('created_at', 'created_on')
);

$planUpdatedAtColumn = planViewFirstColumn(
    $planColumns,
    array('updated_at', 'updated_on')
);

$planDeletedColumn = planViewFirstColumn(
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
| Load plan
|--------------------------------------------------------------------------
*/

$planId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($planId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid subscription plan.';

    header('Location: plans.php');
    exit;
}

$select = array(
    "`{$planIdColumn}` AS plan_id",
    "`{$planNameColumn}` AS plan_name"
);

$select[] = $planCodeColumn !== ''
    ? "`{$planCodeColumn}` AS plan_code"
    : "'' AS plan_code";

$select[] = $planDescriptionColumn !== ''
    ? "`{$planDescriptionColumn}` AS plan_description"
    : "'' AS plan_description";

$select[] = $planPriceColumn !== ''
    ? "`{$planPriceColumn}` AS default_price"
    : "0 AS default_price";

$select[] = $planCurrencyColumn !== ''
    ? "`{$planCurrencyColumn}` AS default_currency"
    : "'USD' AS default_currency";

$select[] = $planBillingCycleColumn !== ''
    ? "`{$planBillingCycleColumn}` AS billing_cycle"
    : "'' AS billing_cycle";

$select[] = $planTrialDaysColumn !== ''
    ? "`{$planTrialDaysColumn}` AS trial_days"
    : "0 AS trial_days";

$select[] = $planMaxUsersColumn !== ''
    ? "`{$planMaxUsersColumn}` AS max_users"
    : "NULL AS max_users";

$select[] = $planMaxBranchesColumn !== ''
    ? "`{$planMaxBranchesColumn}` AS max_branches"
    : "NULL AS max_branches";

$select[] = $planStorageColumn !== ''
    ? "`{$planStorageColumn}` AS storage_limit_mb"
    : "NULL AS storage_limit_mb";

$select[] = $planFeaturedColumn !== ''
    ? "`{$planFeaturedColumn}` AS is_featured"
    : "0 AS is_featured";

$select[] = $planStatusColumn !== ''
    ? "`{$planStatusColumn}` AS plan_status"
    : "'active' AS plan_status";

$select[] = $planCreatedAtColumn !== ''
    ? "`{$planCreatedAtColumn}` AS created_at"
    : "NULL AS created_at";

$select[] = $planUpdatedAtColumn !== ''
    ? "`{$planUpdatedAtColumn}` AS updated_at"
    : "NULL AS updated_at";

$planSql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM plans
    WHERE `{$planIdColumn}` = ?
";

if ($planDeletedColumn !== '') {
    $planSql .= "
        AND `{$planDeletedColumn}` IS NULL
    ";
}

$planSql .= " LIMIT 1";

$planStmt = $conn->prepare($planSql);
$planStmt->bind_param('i', $planId);
$planStmt->execute();

$plan = $planStmt
    ->get_result()
    ->fetch_assoc();

$planStmt->close();

if (!$plan) {
    $_SESSION['platform_error_message'] =
        'Subscription plan not found.';

    header('Location: plans.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load currency prices
|--------------------------------------------------------------------------
*/

$prices = array();

if ($hasPlanPricesTable) {
    $priceColumns = planViewColumns(
        $conn,
        'plan_prices'
    );

    $priceIdColumn = planViewFirstColumn(
        $priceColumns,
        array('id', 'price_id')
    );

    $pricePlanColumn = planViewFirstColumn(
        $priceColumns,
        array('plan_id')
    );

    $priceCurrencyColumn = planViewFirstColumn(
        $priceColumns,
        array('currency_code', 'currency')
    );

    $priceAmountColumn = planViewFirstColumn(
        $priceColumns,
        array('amount', 'price')
    );

    $priceDefaultColumn = planViewFirstColumn(
        $priceColumns,
        array('is_default')
    );

    $priceActiveColumn = planViewFirstColumn(
        $priceColumns,
        array('is_active', 'active')
    );

    if (
        $pricePlanColumn !== '' &&
        $priceCurrencyColumn !== '' &&
        $priceAmountColumn !== ''
    ) {
        $priceSelect = array(
            "`{$priceCurrencyColumn}` AS currency_code",
            "`{$priceAmountColumn}` AS amount"
        );

        $priceSelect[] = $priceDefaultColumn !== ''
            ? "`{$priceDefaultColumn}` AS is_default"
            : "0 AS is_default";

        $priceSql = "
            SELECT
                " . implode(', ', $priceSelect) . "
            FROM plan_prices
            WHERE `{$pricePlanColumn}` = ?
        ";

        if ($priceActiveColumn !== '') {
            $priceSql .= "
                AND `{$priceActiveColumn}` = 1
            ";
        }

        $priceSql .= "
            ORDER BY
                is_default DESC,
                currency_code ASC
        ";

        $priceStmt = $conn->prepare($priceSql);
        $priceStmt->bind_param('i', $planId);
        $priceStmt->execute();

        $priceResult = $priceStmt->get_result();

        while ($priceRow = $priceResult->fetch_assoc()) {
            $prices[] = $priceRow;
        }

        $priceStmt->close();
    }
}

if (empty($prices)) {
    $prices[] = array(
        'currency_code' =>
            $plan['default_currency'],
        'amount' =>
            $plan['default_price'],
        'is_default' => 1
    );
}

/*
|--------------------------------------------------------------------------
| Subscription statistics
|--------------------------------------------------------------------------
*/

$subscriptionStats = array(
    'total' => 0,
    'active' => 0,
    'trial' => 0,
    'inactive' => 0,
    'monthly_revenue' => 0
);

if ($hasSubscriptionsTable) {
    $subscriptionColumns = planViewColumns(
        $conn,
        'subscriptions'
    );

    $subscriptionPlanColumn =
        planViewFirstColumn(
            $subscriptionColumns,
            array('plan_id')
        );

    $subscriptionStatusColumn =
        planViewFirstColumn(
            $subscriptionColumns,
            array('status')
        );

    $subscriptionAmountColumn =
        planViewFirstColumn(
            $subscriptionColumns,
            array('amount', 'price')
        );

    $subscriptionCycleColumn =
        planViewFirstColumn(
            $subscriptionColumns,
            array('billing_cycle', 'cycle')
        );

    $subscriptionDeletedColumn =
        planViewFirstColumn(
            $subscriptionColumns,
            array('deleted_at')
        );

    if ($subscriptionPlanColumn !== '') {
        $statsSql = "
            SELECT
                COUNT(*) AS total_count
        ";

        if ($subscriptionStatusColumn !== '') {
            $statsSql .= ",
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
                            'inactive',
                            'expired',
                            'cancelled',
                            'suspended'
                        )
                        THEN 1 ELSE 0
                    END
                ) AS inactive_count
            ";
        }

        if (
            $subscriptionAmountColumn !== '' &&
            $subscriptionCycleColumn !== ''
        ) {
            $statsSql .= ",
                SUM(
                    CASE
                        WHEN `{$subscriptionStatusColumn}` = 'active'
                         AND `{$subscriptionCycleColumn}` = 'monthly'
                        THEN `{$subscriptionAmountColumn}`
                        ELSE 0
                    END
                ) AS monthly_revenue
            ";
        }

        $statsSql .= "
            FROM subscriptions
            WHERE `{$subscriptionPlanColumn}` = ?
        ";

        if ($subscriptionDeletedColumn !== '') {
            $statsSql .= "
                AND `{$subscriptionDeletedColumn}` IS NULL
            ";
        }

        $statsStmt = $conn->prepare($statsSql);
        $statsStmt->bind_param('i', $planId);
        $statsStmt->execute();

        $statsRow = $statsStmt
            ->get_result()
            ->fetch_assoc();

        $statsStmt->close();

        $subscriptionStats['total'] =
            isset($statsRow['total_count'])
                ? (int) $statsRow['total_count']
                : 0;

        $subscriptionStats['active'] =
            isset($statsRow['active_count'])
                ? (int) $statsRow['active_count']
                : 0;

        $subscriptionStats['trial'] =
            isset($statsRow['trial_count'])
                ? (int) $statsRow['trial_count']
                : 0;

        $subscriptionStats['inactive'] =
            isset($statsRow['inactive_count'])
                ? (int) $statsRow['inactive_count']
                : 0;

        $subscriptionStats['monthly_revenue'] =
            isset($statsRow['monthly_revenue'])
                ? (float) $statsRow['monthly_revenue']
                : 0;
    }
}


/*
|--------------------------------------------------------------------------
| Plan modules and features
|--------------------------------------------------------------------------
*/

$planModules = array();
$includedModulesCount = 0;
$includedFeaturesCount = 0;

if (
    $hasPlanModulesTable &&
    $hasModulesTable
) {
    $moduleSql = "
        SELECT
            m.`id` AS module_id,
            m.`module_code`,
            m.`module_name`,
            m.`description`,
            m.`icon_class`,
            m.`menu_url`,
            m.`menu_order`,
            m.`is_core`,
            m.`is_active`,
            pm.`is_enabled`
        FROM plan_modules pm
        INNER JOIN modules m
            ON m.`id` = pm.`module_id`
        WHERE pm.`plan_id` = ?
          AND pm.`is_enabled` = 1
        ORDER BY
            m.`menu_order` ASC,
            m.`module_name` ASC
    ";

    $moduleStmt = $conn->prepare($moduleSql);
    $moduleStmt->bind_param('i', $planId);
    $moduleStmt->execute();

    $moduleResult =
        $moduleStmt->get_result();

    while ($moduleRow = $moduleResult->fetch_assoc()) {
        $moduleRow['features'] = array();

        $planModules[
            (int) $moduleRow['module_id']
        ] = $moduleRow;
    }

    $moduleStmt->close();

    $includedModulesCount =
        count($planModules);
}

if (
    $hasPlanFeaturesTable &&
    $hasModuleFeaturesTable &&
    !empty($planModules)
) {
    $featureSql = "
        SELECT
            mf.`id` AS feature_id,
            mf.`module_id`,
            mf.`feature_code`,
            mf.`feature_name`,
            mf.`description`,
            mf.`is_active`,
            pf.`is_enabled`
        FROM plan_features pf
        INNER JOIN module_features mf
            ON mf.`id` = pf.`feature_id`
        WHERE pf.`plan_id` = ?
          AND pf.`is_enabled` = 1
        ORDER BY
            mf.`module_id` ASC,
            mf.`feature_name` ASC
    ";

    $featureStmt = $conn->prepare($featureSql);
    $featureStmt->bind_param('i', $planId);
    $featureStmt->execute();

    $featureResult =
        $featureStmt->get_result();

    while ($featureRow = $featureResult->fetch_assoc()) {
        $moduleId =
            (int) $featureRow['module_id'];

        if (!isset($planModules[$moduleId])) {
            continue;
        }

        $planModules[$moduleId]['features'][] =
            $featureRow;

        $includedFeaturesCount++;
    }

    $featureStmt->close();
}

$planModules = array_values($planModules);

$planStatus = strtolower(
    trim((string) $plan['plan_status'])
);

if ($planStatus === '') {
    $planStatus = 'active';
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .plan-view-page {
        max-width: 1120px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .plan-view-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .plan-view-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .plan-view-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .plan-view-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .plan-view-button {
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

    .plan-view-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .plan-view-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .plan-view-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .plan-view-hero {
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background:
            linear-gradient(
                135deg,
                #ffffff,
                #faf8ff
            );
        box-shadow:
            0 6px 24px rgba(31, 41, 55, 0.04);
    }

    .plan-view-icon {
        width: 74px;
        height: 74px;
        flex: 0 0 74px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 28px;
        box-shadow:
            0 8px 22px rgba(91, 33, 182, 0.18);
    }

    .plan-view-hero-content {
        min-width: 0;
        flex: 1;
    }

    .plan-view-name {
        margin: 0;
        color: #111827;
        font-size: 21px;
        font-weight: 800;
    }

    .plan-view-code {
        margin-top: 4px;
        color: #7c3aed;
        font-size: 9px;
        font-weight: 700;
    }

    .plan-view-subline {
        margin-top: 7px;
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
    }

    .plan-view-status,
    .plan-view-featured {
        padding: 5px 8px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .plan-view-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .plan-view-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .plan-view-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .plan-view-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .plan-view-featured {
        background: #fff7ed;
        color: #b45309;
    }

    .plan-view-summary {
        display: grid;
        grid-template-columns:
            repeat(6, minmax(0, 1fr));
        gap: 10px;
    }

    .plan-view-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
        box-shadow:
            0 4px 15px rgba(31, 41, 55, 0.03);
    }

    .plan-view-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 14px;
    }

    .plan-view-summary-icon.total {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .plan-view-summary-icon.active {
        background: #ecfdf5;
        color: #059669;
    }

    .plan-view-summary-icon.trial {
        background: #eff6ff;
        color: #2563eb;
    }

    .plan-view-summary-icon.inactive {
        background: #fef2f2;
        color: #dc2626;
    }

    .plan-view-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .plan-view-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .plan-view-grid {
        display: grid;
        grid-template-columns:
            minmax(0, 1.35fr)
            minmax(290px, 0.8fr);
        gap: 15px;
        align-items: start;
    }

    .plan-view-main,
    .plan-view-side {
        display: grid;
        gap: 15px;
    }

    .plan-view-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .plan-view-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .plan-view-card-icon {
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

    .plan-view-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .plan-view-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .plan-view-card-body {
        padding: 15px;
    }

    .plan-view-description-box {
        padding: 13px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
        color: #4b5563;
        font-size: 9px;
        line-height: 1.65;
    }

    .plan-view-details {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 11px;
    }

    .plan-view-detail {
        padding: 11px 12px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .plan-view-detail-label {
        display: block;
        color: #9ca3af;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.35px;
        text-transform: uppercase;
    }

    .plan-view-detail-value {
        margin-top: 4px;
        display: block;
        color: #374151;
        font-size: 10px;
        font-weight: 700;
    }

    .plan-view-prices {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .plan-view-price {
        padding: 12px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fafafa;
    }

    .plan-view-price.default {
        border-color: #c4b5fd;
        background: #faf8ff;
    }

    .plan-view-price-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .plan-view-price-code {
        color: #6b7280;
        font-size: 8px;
        font-weight: 800;
    }

    .plan-view-price-default {
        padding: 3px 6px;
        border-radius: 999px;
        background: #ede9fe;
        color: #6d28d9;
        font-size: 7px;
        font-weight: 800;
    }

    .plan-view-price-amount {
        margin-top: 8px;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .plan-view-price-cycle {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 8px;
    }

    .plan-view-card-action {
        color: #7c3aed;
        font-size: 8px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }

    .plan-view-card-action:hover {
        color: #6d28d9;
    }

    .plan-view-modules-list {
        display: grid;
        gap: 10px;
    }

    .plan-view-module-item {
        padding: 12px;
        border: 1px solid #eef0f3;
        border-radius: 10px;
        background: #fafafa;
    }

    .plan-view-module-top {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .plan-view-module-icon {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: #ede9fe;
        color: #6d28d9;
        font-size: 13px;
    }

    .plan-view-module-content {
        min-width: 0;
        flex: 1;
    }

    .plan-view-module-name {
        display: block;
        color: #111827;
        font-size: 9px;
        font-weight: 800;
    }

    .plan-view-module-code {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 7px;
    }

    .plan-view-module-count {
        padding: 4px 7px;
        border-radius: 999px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 7px;
        font-weight: 700;
        white-space: nowrap;
    }

    .plan-view-feature-tags {
        margin-top: 10px;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .plan-view-feature-tag {
        padding: 5px 7px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border-radius: 7px;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        color: #4b5563;
        font-size: 7px;
        font-weight: 600;
    }

    .plan-view-feature-tag i {
        color: #059669;
    }

    .plan-view-module-no-features,
    .plan-view-modules-empty {
        padding: 11px;
        border: 1px dashed #e5e7eb;
        border-radius: 8px;
        color: #9ca3af;
        font-size: 8px;
        text-align: center;
    }

    .plan-view-quick-links {
        display: grid;
        gap: 8px;
    }

    .plan-view-quick-link {
        min-height: 40px;
        padding: 9px 11px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #ffffff;
        color: #4b5563;
        font-size: 9px;
        font-weight: 700;
        text-decoration: none;
    }

    .plan-view-quick-link:hover {
        border-color: #c4b5fd;
        background: #faf8ff;
        color: #7c3aed;
    }

    @media (max-width: 1150px) {
        .plan-view-summary {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .plan-view-grid {
            grid-template-columns: 1fr;
        }

        .plan-view-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 650px) {
        .plan-view-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .plan-view-actions {
            width: 100%;
        }

        .plan-view-button {
            flex: 1;
        }

        .plan-view-hero {
            align-items: flex-start;
            flex-direction: column;
        }

        .plan-view-details,
        .plan-view-prices {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 430px) {
        .plan-view-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="plan-view-page">

    <div class="plan-view-header">
        <div>
            <h2 class="plan-view-title">
                Subscription Plan Details
            </h2>

            <div class="plan-view-description">
                Review pricing, limits, availability, and usage.
            </div>
        </div>

        <div class="plan-view-actions">
            <a
                href="plans.php"
                class="plan-view-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Plans
            </a>

            <?php if (
                hasPlatformRole(array(
                    'super_admin',
                    'platform_admin',
                    'billing_admin'
                ))
            ): ?>
                <a
                    href="plan-modules.php?plan_id=<?= (int) $planId; ?>"
                    class="plan-view-button"
                >
                    <i class="bi bi-grid-3x3-gap"></i>
                    Modules & Features
                </a>

                <a
                    href="plan-edit.php?id=<?= (int) $planId; ?>"
                    class="plan-view-button primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit Plan
                </a>
            <?php endif; ?>
        </div>
    </div>

    <section class="plan-view-hero">
        <div class="plan-view-icon">
            <i class="bi bi-card-list"></i>
        </div>

        <div class="plan-view-hero-content">
            <h1 class="plan-view-name">
                <?= planViewEscape($plan['plan_name']); ?>
            </h1>

            <div class="plan-view-code">
                <?= planViewEscape(
                    !empty($plan['plan_code'])
                        ? $plan['plan_code']
                        : 'PLAN-' . (int) $planId
                ); ?>
            </div>

            <div class="plan-view-subline">
                <span
                    class="plan-view-status <?= planViewEscape(
                        planViewStatusClass($planStatus)
                    ); ?>"
                >
                    <?= planViewEscape(
                        planViewLabel($planStatus)
                    ); ?>
                </span>

                <?php if (!empty($plan['is_featured'])): ?>
                    <span class="plan-view-featured">
                        <i class="bi bi-star-fill"></i>
                        Featured Plan
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="plan-view-summary">
        <div class="plan-view-summary-card">
            <span class="plan-view-summary-icon total">
                <i class="bi bi-people"></i>
            </span>

            <span>
                <span class="plan-view-summary-label">
                    Total Subscriptions
                </span>

                <span class="plan-view-summary-value">
                    <?= number_format(
                        $subscriptionStats['total']
                    ); ?>
                </span>
            </span>
        </div>

        <div class="plan-view-summary-card">
            <span class="plan-view-summary-icon active">
                <i class="bi bi-check-circle"></i>
            </span>

            <span>
                <span class="plan-view-summary-label">
                    Active
                </span>

                <span class="plan-view-summary-value">
                    <?= number_format(
                        $subscriptionStats['active']
                    ); ?>
                </span>
            </span>
        </div>

        <div class="plan-view-summary-card">
            <span class="plan-view-summary-icon trial">
                <i class="bi bi-hourglass-split"></i>
            </span>

            <span>
                <span class="plan-view-summary-label">
                    Trial
                </span>

                <span class="plan-view-summary-value">
                    <?= number_format(
                        $subscriptionStats['trial']
                    ); ?>
                </span>
            </span>
        </div>

        <div class="plan-view-summary-card">
            <span class="plan-view-summary-icon inactive">
                <i class="bi bi-slash-circle"></i>
            </span>

            <span>
                <span class="plan-view-summary-label">
                    Inactive
                </span>

                <span class="plan-view-summary-value">
                    <?= number_format(
                        $subscriptionStats['inactive']
                    ); ?>
                </span>
            </span>
        </div>


        <div class="plan-view-summary-card">
            <span class="plan-view-summary-icon total">
                <i class="bi bi-grid-3x3-gap"></i>
            </span>

            <span>
                <span class="plan-view-summary-label">
                    Modules
                </span>

                <span class="plan-view-summary-value">
                    <?= number_format(
                        $includedModulesCount
                    ); ?>
                </span>
            </span>
        </div>

        <div class="plan-view-summary-card">
            <span class="plan-view-summary-icon trial">
                <i class="bi bi-ui-checks"></i>
            </span>

            <span>
                <span class="plan-view-summary-label">
                    Features
                </span>

                <span class="plan-view-summary-value">
                    <?= number_format(
                        $includedFeaturesCount
                    ); ?>
                </span>
            </span>
        </div>
    </div>

    <div class="plan-view-grid">

        <div class="plan-view-main">

            <section class="plan-view-card">
                <div class="plan-view-card-header">
                    <span class="plan-view-card-icon">
                        <i class="bi bi-currency-dollar"></i>
                    </span>

                    <div>
                        <h3 class="plan-view-card-title">
                            Multi-Currency Pricing
                        </h3>

                        <div class="plan-view-card-subtitle">
                            Available prices for supported markets
                        </div>
                    </div>
                </div>

                <div class="plan-view-card-body">
                    <div class="plan-view-prices">
                        <?php foreach ($prices as $price): ?>
                            <div
                                class="plan-view-price <?= !empty(
                                    $price['is_default']
                                )
                                    ? 'default'
                                    : ''; ?>"
                            >
                                <div class="plan-view-price-top">
                                    <span class="plan-view-price-code">
                                        <?= planViewEscape(
                                            strtoupper(
                                                $price['currency_code']
                                            )
                                        ); ?>
                                    </span>

                                    <?php if (
                                        !empty($price['is_default'])
                                    ): ?>
                                        <span class="plan-view-price-default">
                                            Default
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="plan-view-price-amount">
                                    <?= planViewEscape(
                                        planViewMoney(
                                            $price['amount'],
                                            $price['currency_code']
                                        )
                                    ); ?>
                                </div>

                                <div class="plan-view-price-cycle">
                                    <?= planViewEscape(
                                        planViewLabel(
                                            $plan['billing_cycle']
                                        )
                                    ); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="plan-view-card">
                <div class="plan-view-card-header">
                    <span class="plan-view-card-icon">
                        <i class="bi bi-grid-3x3-gap"></i>
                    </span>

                    <div style="min-width:0;flex:1;">
                        <h3 class="plan-view-card-title">
                            Included Modules & Features
                        </h3>

                        <div class="plan-view-card-subtitle">
                            Default access provided by this subscription plan
                        </div>
                    </div>

                    <?php if (
                        hasPlatformRole(array(
                            'super_admin',
                            'platform_admin',
                            'billing_admin'
                        ))
                    ): ?>
                        <a
                            href="plan-modules.php?plan_id=<?= (int) $planId; ?>"
                            class="plan-view-card-action"
                        >
                            Configure Access
                        </a>
                    <?php endif; ?>
                </div>

                <div class="plan-view-card-body">
                    <?php if (empty($planModules)): ?>
                        <div class="plan-view-modules-empty">
                            No modules are currently included in this plan.
                        </div>
                    <?php else: ?>
                        <div class="plan-view-modules-list">
                            <?php foreach ($planModules as $module): ?>
                                <article class="plan-view-module-item">
                                    <div class="plan-view-module-top">
                                        <span class="plan-view-module-icon">
                                            <i class="<?= planViewEscape(
                                                !empty($module['icon_class'])
                                                    ? $module['icon_class']
                                                    : 'bi bi-grid'
                                            ); ?>"></i>
                                        </span>

                                        <span class="plan-view-module-content">
                                            <span class="plan-view-module-name">
                                                <?= planViewEscape(
                                                    $module['module_name']
                                                ); ?>
                                            </span>

                                            <span class="plan-view-module-code">
                                                <?= planViewEscape(
                                                    $module['module_code']
                                                ); ?>
                                            </span>
                                        </span>

                                        <span class="plan-view-module-count">
                                            <?= number_format(
                                                count($module['features'])
                                            ); ?>
                                            feature<?= count($module['features']) !== 1
                                                ? 's'
                                                : ''; ?>
                                        </span>
                                    </div>

                                    <?php if (!empty($module['features'])): ?>
                                        <div class="plan-view-feature-tags">
                                            <?php foreach ($module['features'] as $feature): ?>
                                                <span class="plan-view-feature-tag">
                                                    <i class="bi bi-check2"></i>
                                                    <?= planViewEscape(
                                                        $feature['feature_name']
                                                    ); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="plan-view-module-no-features">
                                            Module-level access only
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="plan-view-card">
                <div class="plan-view-card-header">
                    <span class="plan-view-card-icon">
                        <i class="bi bi-info-circle"></i>
                    </span>

                    <div>
                        <h3 class="plan-view-card-title">
                            Plan Description
                        </h3>

                        <div class="plan-view-card-subtitle">
                            Customer-facing plan summary
                        </div>
                    </div>
                </div>

                <div class="plan-view-card-body">
                    <div class="plan-view-description-box">
                        <?= nl2br(
                            planViewEscape(
                                !empty(
                                    $plan['plan_description']
                                )
                                    ? $plan[
                                        'plan_description'
                                    ]
                                    : 'No plan description has been added.'
                            )
                        ); ?>
                    </div>
                </div>
            </section>

            <section class="plan-view-card">
                <div class="plan-view-card-header">
                    <span class="plan-view-card-icon">
                        <i class="bi bi-speedometer2"></i>
                    </span>

                    <div>
                        <h3 class="plan-view-card-title">
                            Plan Limits
                        </h3>

                        <div class="plan-view-card-subtitle">
                            Usage and account limits
                        </div>
                    </div>
                </div>

                <div class="plan-view-card-body">
                    <div class="plan-view-details">
                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Maximum Users
                            </span>

                            <span class="plan-view-detail-value">
                                <?= planViewEscape(
                                    planViewLimit(
                                        $plan['max_users']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Maximum Branches
                            </span>

                            <span class="plan-view-detail-value">
                                <?= planViewEscape(
                                    planViewLimit(
                                        $plan['max_branches']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Storage Limit
                            </span>

                            <span class="plan-view-detail-value">
                                <?= $plan['storage_limit_mb'] === null ||
                                    $plan['storage_limit_mb'] === ''
                                        ? 'Unlimited'
                                        : number_format(
                                            (int)
                                            $plan[
                                                'storage_limit_mb'
                                            ]
                                        ) . ' MB'; ?>
                            </span>
                        </div>

                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Trial Period
                            </span>

                            <span class="plan-view-detail-value">
                                <?= (int) $plan['trial_days'] > 0
                                    ? number_format(
                                        (int)
                                        $plan['trial_days']
                                    ) . ' days'
                                    : 'No trial'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

        </div>

        <aside class="plan-view-side">

            <section class="plan-view-card">
                <div class="plan-view-card-header">
                    <span class="plan-view-card-icon">
                        <i class="bi bi-sliders"></i>
                    </span>

                    <div>
                        <h3 class="plan-view-card-title">
                            Plan Settings
                        </h3>

                        <div class="plan-view-card-subtitle">
                            Billing and availability details
                        </div>
                    </div>
                </div>

                <div class="plan-view-card-body">
                    <div class="plan-view-details">
                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Billing Cycle
                            </span>

                            <span class="plan-view-detail-value">
                                <?= planViewEscape(
                                    planViewLabel(
                                        $plan['billing_cycle']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Default Currency
                            </span>

                            <span class="plan-view-detail-value">
                                <?= planViewEscape(
                                    strtoupper(
                                        $plan[
                                            'default_currency'
                                        ]
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Status
                            </span>

                            <span class="plan-view-detail-value">
                                <?= planViewEscape(
                                    planViewLabel(
                                        $planStatus
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Featured
                            </span>

                            <span class="plan-view-detail-value">
                                <?= !empty(
                                    $plan['is_featured']
                                )
                                    ? 'Yes'
                                    : 'No'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="plan-view-card">
                <div class="plan-view-card-header">
                    <span class="plan-view-card-icon">
                        <i class="bi bi-clock-history"></i>
                    </span>

                    <div>
                        <h3 class="plan-view-card-title">
                            Record Information
                        </h3>

                        <div class="plan-view-card-subtitle">
                            Plan creation and update details
                        </div>
                    </div>
                </div>

                <div class="plan-view-card-body">
                    <div class="plan-view-details">
                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Plan ID
                            </span>

                            <span class="plan-view-detail-value">
                                #<?= (int) $planId; ?>
                            </span>
                        </div>

                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Created
                            </span>

                            <span class="plan-view-detail-value">
                                <?= planViewEscape(
                                    planViewDate(
                                        $plan['created_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="plan-view-detail">
                            <span class="plan-view-detail-label">
                                Last Updated
                            </span>

                            <span class="plan-view-detail-value">
                                <?= planViewEscape(
                                    planViewDate(
                                        $plan['updated_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="plan-view-card">
                <div class="plan-view-card-header">
                    <span class="plan-view-card-icon">
                        <i class="bi bi-lightning-charge"></i>
                    </span>

                    <div>
                        <h3 class="plan-view-card-title">
                            Quick Actions
                        </h3>

                        <div class="plan-view-card-subtitle">
                            Related plan actions
                        </div>
                    </div>
                </div>

                <div class="plan-view-card-body">
                    <div class="plan-view-quick-links">

                        <?php if (
                            hasPlatformRole(array(
                                'super_admin',
                                'platform_admin',
                                'billing_admin'
                            ))
                        ): ?>
                            <a
                                href="plan-edit.php?id=<?= (int) $planId; ?>"
                                class="plan-view-quick-link"
                            >
                                <i class="bi bi-pencil-square"></i>
                                Edit Subscription Plan
                            </a>


                            <a
                                href="plan-modules.php?plan_id=<?= (int) $planId; ?>"
                                class="plan-view-quick-link"
                            >
                                <i class="bi bi-grid-3x3-gap"></i>
                                Configure Modules & Features
                            </a>
                        <?php endif; ?>

                        <a
                            href="subscriptions.php?plan_id=<?= (int) $planId; ?>"
                            class="plan-view-quick-link"
                        >
                            <i class="bi bi-credit-card"></i>
                            View Plan Subscriptions
                        </a>

                        <a
                            href="subscription-add.php?plan_id=<?= (int) $planId; ?>"
                            class="plan-view-quick-link"
                        >
                            <i class="bi bi-plus-circle"></i>
                            Assign to Tenant
                        </a>

                        <a
                            href="plans.php"
                            class="plan-view-quick-link"
                        >
                            <i class="bi bi-card-list"></i>
                            View All Plans
                        </a>

                    </div>
                </div>
            </section>

        </aside>

    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
