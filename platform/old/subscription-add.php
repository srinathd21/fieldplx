<?php
/**
 * FieldPlx Platform - Add Subscription
 *
 * File:
 * platform/subscription-add.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - plans
 * - plan_prices
 * - tenants
 * - subscriptions
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin'
));

$pageTitle = 'Add Subscription - FieldPlx';
$activePage = 'subscriptions';
$basePath = '';

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('subscriptionAddEscape')) {
    function subscriptionAddEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('subscriptionAddPost')) {
    function subscriptionAddPost(
        $key,
        $default = ''
    ) {
        if (
            !isset($_POST[$key]) ||
            is_array($_POST[$key])
        ) {
            return $default;
        }

        return trim((string) $_POST[$key]);
    }
}

if (!function_exists('subscriptionAddTableExists')) {
    function subscriptionAddTableExists(
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

if (!function_exists('subscriptionAddColumns')) {
    function subscriptionAddColumns(
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

if (!function_exists('subscriptionAddFirstColumn')) {
    function subscriptionAddFirstColumn(
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

if (!function_exists('subscriptionAddBind')) {
    function subscriptionAddBind(
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

if (!function_exists('subscriptionAddMoney')) {
    function subscriptionAddMoney($value)
    {
        $value = str_replace(
            ',',
            '',
            trim((string) $value)
        );

        if ($value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return false;
        }

        return round((float) $value, 2);
    }
}

if (!function_exists('subscriptionAddDate')) {
    function subscriptionAddDate($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return false;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('subscriptionAddReference')) {
    function subscriptionAddReference()
    {
        return
            'SUB-' .
            date('Ymd') .
            '-' .
            strtoupper(
                bin2hex(random_bytes(3))
            );
    }
}

if (!function_exists('subscriptionAddLabel')) {
    function subscriptionAddLabel($value)
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

/*
|--------------------------------------------------------------------------
| Verify required tables
|--------------------------------------------------------------------------
*/

$requiredTables = array(
    'tenants',
    'plans',
    'subscriptions'
);

foreach ($requiredTables as $requiredTable) {
    if (
        !subscriptionAddTableExists(
            $conn,
            $requiredTable
        )
    ) {
        http_response_code(500);

        exit(
            'The ' .
            subscriptionAddEscape($requiredTable) .
            ' table does not exist.'
        );
    }
}

$hasPlanPrices =
    subscriptionAddTableExists(
        $conn,
        'plan_prices'
    );

/*
|--------------------------------------------------------------------------
| Detect tenant structure
|--------------------------------------------------------------------------
*/

$tenantColumns =
    subscriptionAddColumns(
        $conn,
        'tenants'
    );

$tenantIdColumn =
    subscriptionAddFirstColumn(
        $tenantColumns,
        array('id', 'tenant_id')
    );

$tenantNameColumn =
    subscriptionAddFirstColumn(
        $tenantColumns,
        array(
            'business_name',
            'name',
            'tenant_name',
            'company_name'
        )
    );

$tenantCodeColumn =
    subscriptionAddFirstColumn(
        $tenantColumns,
        array(
            'tenant_code',
            'business_code',
            'code',
            'slug'
        )
    );

$tenantStatusColumn =
    subscriptionAddFirstColumn(
        $tenantColumns,
        array('status')
    );

$tenantDeletedColumn =
    subscriptionAddFirstColumn(
        $tenantColumns,
        array('deleted_at')
    );

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);

    exit(
        'The tenants table requires an ID and name column.'
    );
}

/*
|--------------------------------------------------------------------------
| Detect plan structure
|--------------------------------------------------------------------------
*/

$planColumns =
    subscriptionAddColumns(
        $conn,
        'plans'
    );

$planIdColumn =
    subscriptionAddFirstColumn(
        $planColumns,
        array('id', 'plan_id')
    );

$planNameColumn =
    subscriptionAddFirstColumn(
        $planColumns,
        array('name', 'plan_name')
    );

$planCodeColumn =
    subscriptionAddFirstColumn(
        $planColumns,
        array('code', 'plan_code')
    );

$planPriceColumn =
    subscriptionAddFirstColumn(
        $planColumns,
        array('price', 'amount')
    );

$planCurrencyColumn =
    subscriptionAddFirstColumn(
        $planColumns,
        array('currency', 'currency_code')
    );

$planCycleColumn =
    subscriptionAddFirstColumn(
        $planColumns,
        array(
            'billing_cycle',
            'billing_period',
            'cycle'
        )
    );

$planTrialDaysColumn =
    subscriptionAddFirstColumn(
        $planColumns,
        array('trial_days', 'free_trial_days')
    );

$planStatusColumn =
    subscriptionAddFirstColumn(
        $planColumns,
        array('status')
    );

$planDeletedColumn =
    subscriptionAddFirstColumn(
        $planColumns,
        array('deleted_at')
    );

if (
    $planIdColumn === '' ||
    $planNameColumn === ''
) {
    http_response_code(500);

    exit(
        'The plans table requires an ID and name column.'
    );
}

/*
|--------------------------------------------------------------------------
| Load tenants
|--------------------------------------------------------------------------
*/

$tenantSelect = array(
    "`{$tenantIdColumn}` AS tenant_id",
    "`{$tenantNameColumn}` AS tenant_name"
);

$tenantSelect[] =
    $tenantCodeColumn !== ''
        ? "`{$tenantCodeColumn}` AS tenant_code"
        : "'' AS tenant_code";

$tenantSql = "
    SELECT
        " .
        implode(', ', $tenantSelect) .
    "
    FROM tenants
    WHERE 1 = 1
";

if ($tenantDeletedColumn !== '') {
    $tenantSql .= "
        AND `{$tenantDeletedColumn}` IS NULL
    ";
}

if ($tenantStatusColumn !== '') {
    $tenantSql .= "
        AND `{$tenantStatusColumn}` IN (
            'active',
            'trial',
            'pending'
        )
    ";
}

$tenantSql .= "
    ORDER BY `{$tenantNameColumn}` ASC
";

$tenantResult = $conn->query($tenantSql);

$tenants = array();

while (
    $tenantRow =
    $tenantResult->fetch_assoc()
) {
    $tenants[] = $tenantRow;
}

$tenantResult->free();

/*
|--------------------------------------------------------------------------
| Load plans
|--------------------------------------------------------------------------
*/

$planSelect = array(
    "`{$planIdColumn}` AS plan_id",
    "`{$planNameColumn}` AS plan_name"
);

$planSelect[] =
    $planCodeColumn !== ''
        ? "`{$planCodeColumn}` AS plan_code"
        : "'' AS plan_code";

$planSelect[] =
    $planPriceColumn !== ''
        ? "`{$planPriceColumn}` AS default_price"
        : "0 AS default_price";

$planSelect[] =
    $planCurrencyColumn !== ''
        ? "`{$planCurrencyColumn}` AS default_currency"
        : "'USD' AS default_currency";

$planSelect[] =
    $planCycleColumn !== ''
        ? "`{$planCycleColumn}` AS billing_cycle"
        : "'monthly' AS billing_cycle";

$planSelect[] =
    $planTrialDaysColumn !== ''
        ? "`{$planTrialDaysColumn}` AS trial_days"
        : "0 AS trial_days";

$planSql = "
    SELECT
        " .
        implode(', ', $planSelect) .
    "
    FROM plans
    WHERE 1 = 1
";

if ($planDeletedColumn !== '') {
    $planSql .= "
        AND `{$planDeletedColumn}` IS NULL
    ";
}

if ($planStatusColumn !== '') {
    $planSql .= "
        AND `{$planStatusColumn}` = 'active'
    ";
}

$planSql .= "
    ORDER BY `{$planNameColumn}` ASC
";

$planResult = $conn->query($planSql);

$plans = array();
$planMap = array();

while ($planRow = $planResult->fetch_assoc()) {
    $planRow['prices'] = array();

    $plans[] = $planRow;
    $planMap[(int) $planRow['plan_id']] =
        $planRow;
}

$planResult->free();

/*
|--------------------------------------------------------------------------
| Load plan prices
|--------------------------------------------------------------------------
*/

if ($hasPlanPrices && !empty($planMap)) {
    $priceColumns =
        subscriptionAddColumns(
            $conn,
            'plan_prices'
        );

    $pricePlanColumn =
        subscriptionAddFirstColumn(
            $priceColumns,
            array('plan_id')
        );

    $priceCurrencyColumn =
        subscriptionAddFirstColumn(
            $priceColumns,
            array('currency_code', 'currency')
        );

    $priceAmountColumn =
        subscriptionAddFirstColumn(
            $priceColumns,
            array('amount', 'price')
        );

    $priceDefaultColumn =
        subscriptionAddFirstColumn(
            $priceColumns,
            array('is_default')
        );

    $priceActiveColumn =
        subscriptionAddFirstColumn(
            $priceColumns,
            array('is_active', 'active')
        );

    if (
        $pricePlanColumn !== '' &&
        $priceCurrencyColumn !== '' &&
        $priceAmountColumn !== ''
    ) {
        $priceSelect = array(
            "`{$pricePlanColumn}` AS plan_id",
            "`{$priceCurrencyColumn}` AS currency_code",
            "`{$priceAmountColumn}` AS amount"
        );

        $priceSelect[] =
            $priceDefaultColumn !== ''
                ? "`{$priceDefaultColumn}` AS is_default"
                : "0 AS is_default";

        $priceSql = "
            SELECT
                " .
                implode(', ', $priceSelect) .
            "
            FROM plan_prices
            WHERE 1 = 1
        ";

        if ($priceActiveColumn !== '') {
            $priceSql .= "
                AND `{$priceActiveColumn}` = 1
            ";
        }

        $priceResult =
            $conn->query($priceSql);

        while (
            $priceRow =
            $priceResult->fetch_assoc()
        ) {
            $planIdForPrice =
                (int) $priceRow['plan_id'];

            if (
                isset($planMap[$planIdForPrice])
            ) {
                $planMap[
                    $planIdForPrice
                ]['prices'][] = array(
                    'currency' => strtoupper(
                        (string)
                        $priceRow['currency_code']
                    ),
                    'amount' => (float)
                        $priceRow['amount'],
                    'is_default' => (int)
                        $priceRow['is_default']
                );
            }
        }

        $priceResult->free();
    }
}

/*
|--------------------------------------------------------------------------
| Rebuild plans array with prices
|--------------------------------------------------------------------------
*/

$plans = array_values($planMap);

/*
|--------------------------------------------------------------------------
| Form defaults
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$tenantId = isset($_POST['tenant_id'])
    ? (int) $_POST['tenant_id']
    : (
        isset($_GET['tenant_id'])
            ? (int) $_GET['tenant_id']
            : 0
    );

$planId = isset($_POST['plan_id'])
    ? (int) $_POST['plan_id']
    : (
        isset($_GET['plan_id'])
            ? (int) $_GET['plan_id']
            : 0
    );

$referenceNo =
    subscriptionAddPost(
        'reference_no',
        subscriptionAddReference()
    );

$status =
    subscriptionAddPost(
        'status',
        'active'
    );

$billingCycle =
    subscriptionAddPost(
        'billing_cycle',
        'monthly'
    );

$currency =
    strtoupper(
        subscriptionAddPost(
            'currency',
            'USD'
        )
    );

$amount =
    subscriptionAddPost(
        'amount',
        ''
    );

$startsAt =
    subscriptionAddPost(
        'starts_at',
        date('Y-m-d')
    );

$endsAt =
    subscriptionAddPost(
        'ends_at',
        ''
    );

$trialEndsAt =
    subscriptionAddPost(
        'trial_ends_at',
        ''
    );

$autoRenew =
    !empty($_POST['auto_renew'])
        ? 1
        : 0;

$notes =
    subscriptionAddPost('notes');

$allowedStatuses = array(
    'trial',
    'active',
    'past_due',
    'suspended',
    'cancelled',
    'expired'
);

$allowedCycles = array(
    'monthly',
    'quarterly',
    'half_yearly',
    'yearly',
    'lifetime',
    'custom'
);

$allowedCurrencies = array(
    'USD',
    'GBP',
    'EUR',
    'CAD',
    'AUD',
    'INR'
);

if (
    !in_array(
        $status,
        $allowedStatuses,
        true
    )
) {
    $status = 'active';
}

if (
    !in_array(
        $billingCycle,
        $allowedCycles,
        true
    )
) {
    $billingCycle = 'monthly';
}

if (
    !in_array(
        $currency,
        $allowedCurrencies,
        true
    )
) {
    $currency = 'USD';
}

/*
|--------------------------------------------------------------------------
| Process form
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper(
        $_SERVER['REQUEST_METHOD']
    ) === 'POST'
) {
    verifyCsrfToken();

    if ($tenantId <= 0) {
        $errorMessage =
            'Select a tenant.';
    } elseif ($planId <= 0) {
        $errorMessage =
            'Select a subscription plan.';
    } elseif (
        !isset($planMap[$planId])
    ) {
        $errorMessage =
            'The selected plan is not available.';
    } elseif ($referenceNo === '') {
        $errorMessage =
            'Enter the subscription reference number.';
    } elseif (
        strlen($referenceNo) > 120
    ) {
        $errorMessage =
            'Reference number must not exceed 120 characters.';
    }

    $amountValue = null;

    if ($errorMessage === '') {
        $amountValue =
            subscriptionAddMoney($amount);

        if ($amountValue === false) {
            $errorMessage =
                'Enter a valid subscription amount.';
        } elseif (
            $amountValue === null ||
            $amountValue < 0
        ) {
            $errorMessage =
                'Subscription amount is required.';
        }
    }

    $startsAtValue = null;
    $endsAtValue = null;
    $trialEndsAtValue = null;

    if ($errorMessage === '') {
        $startsAtValue =
            subscriptionAddDate(
                $startsAt . ' 00:00:00'
            );

        if ($startsAtValue === false) {
            $errorMessage =
                'Enter a valid subscription start date.';
        }
    }

    if (
        $errorMessage === '' &&
        $endsAt !== ''
    ) {
        $endsAtValue =
            subscriptionAddDate(
                $endsAt . ' 23:59:59'
            );

        if ($endsAtValue === false) {
            $errorMessage =
                'Enter a valid subscription end date.';
        }
    }

    if (
        $errorMessage === '' &&
        $trialEndsAt !== ''
    ) {
        $trialEndsAtValue =
            subscriptionAddDate(
                $trialEndsAt . ' 23:59:59'
            );

        if ($trialEndsAtValue === false) {
            $errorMessage =
                'Enter a valid trial end date.';
        }
    }

    if (
        $errorMessage === '' &&
        $endsAtValue !== null &&
        strtotime($endsAtValue) <
        strtotime($startsAtValue)
    ) {
        $errorMessage =
            'End date cannot be before the start date.';
    }

    if (
        $errorMessage === '' &&
        $trialEndsAtValue !== null &&
        strtotime($trialEndsAtValue) <
        strtotime($startsAtValue)
    ) {
        $errorMessage =
            'Trial end date cannot be before the start date.';
    }

    if ($errorMessage === '') {
        $tenantCheckSql = "
            SELECT COUNT(*) AS total
            FROM tenants
            WHERE `{$tenantIdColumn}` = ?
        ";

        if ($tenantDeletedColumn !== '') {
            $tenantCheckSql .= "
                AND `{$tenantDeletedColumn}` IS NULL
            ";
        }

        $tenantCheckStmt =
            $conn->prepare($tenantCheckSql);

        $tenantCheckStmt->bind_param(
            'i',
            $tenantId
        );

        $tenantCheckStmt->execute();

        $tenantCheckRow =
            $tenantCheckStmt
            ->get_result()
            ->fetch_assoc();

        $tenantCheckStmt->close();

        if (empty($tenantCheckRow['total'])) {
            $errorMessage =
                'The selected tenant is not available.';
        }
    }

    if ($errorMessage === '') {
        $duplicateStmt =
            $conn->prepare("
                SELECT COUNT(*) AS total
                FROM subscriptions
                WHERE `tenant_id` = ?
                  AND `status` IN (
                    'trial',
                    'active',
                    'past_due',
                    'suspended'
                  )
                  AND `deleted_at` IS NULL
            ");

        $duplicateStmt->bind_param(
            'i',
            $tenantId
        );

        $duplicateStmt->execute();

        $duplicateRow =
            $duplicateStmt
            ->get_result()
            ->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'This tenant already has an active or pending subscription.';
        }
    }

    if ($errorMessage === '') {
        $referenceStmt =
            $conn->prepare("
                SELECT COUNT(*) AS total
                FROM subscriptions
                WHERE LOWER(`reference_no`) =
                    LOWER(?)
                  AND `deleted_at` IS NULL
            ");

        $referenceStmt->bind_param(
            's',
            $referenceNo
        );

        $referenceStmt->execute();

        $referenceRow =
            $referenceStmt
            ->get_result()
            ->fetch_assoc();

        $referenceStmt->close();

        if (!empty($referenceRow['total'])) {
            $errorMessage =
                'This subscription reference number already exists.';
        }
    }

    if ($errorMessage === '') {
        try {
            $insertStmt =
                $conn->prepare("
                    INSERT INTO subscriptions (
                        `tenant_id`,
                        `plan_id`,
                        `reference_no`,
                        `status`,
                        `starts_at`,
                        `ends_at`,
                        `trial_ends_at`,
                        `amount`,
                        `currency`,
                        `billing_cycle`,
                        `auto_renew`,
                        `notes`,
                        `created_at`
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
                        NOW()
                    )
                ");

            $insertStmt->bind_param(
                'iisssssdssis',
                $tenantId,
                $planId,
                $referenceNo,
                $status,
                $startsAtValue,
                $endsAtValue,
                $trialEndsAtValue,
                $amountValue,
                $currency,
                $billingCycle,
                $autoRenew,
                $notes
            );

            $insertStmt->execute();

            $subscriptionId =
                (int) $insertStmt->insert_id;

            $insertStmt->close();

            /*
             * Keep the tenant's legacy subscription_plan
             * value in sync when that column exists.
             */
            if (
                isset(
                    $tenantColumns[
                        'subscription_plan'
                    ]
                )
            ) {
                $selectedPlan =
                    $planMap[$planId];

                $subscriptionPlanValue =
                    !empty(
                        $selectedPlan['plan_code']
                    )
                        ? (string)
                            $selectedPlan['plan_code']
                        : (string)
                            $selectedPlan['plan_name'];

                $syncStmt =
                    $conn->prepare("
                        UPDATE tenants
                        SET `subscription_plan` = ?
                        WHERE `{$tenantIdColumn}` = ?
                        LIMIT 1
                    ");

                $syncStmt->bind_param(
                    'si',
                    $subscriptionPlanValue,
                    $tenantId
                );

                $syncStmt->execute();
                $syncStmt->close();
            }

            regenerateCsrfToken();

            $_SESSION[
                'platform_success_message'
            ] =
                'Subscription created successfully.';

            header(
                'Location: subscription-view.php?id=' .
                $subscriptionId,
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            error_log(
                'Subscription creation failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to create the subscription: ' .
                $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Plan data for JavaScript
|--------------------------------------------------------------------------
*/

$planData = array();

foreach ($plans as $plan) {
    $prices = array();

    foreach ($plan['prices'] as $price) {
        $prices[
            strtoupper(
                (string) $price['currency']
            )
        ] = array(
            'amount' => (float)
                $price['amount'],
            'is_default' => (int)
                $price['is_default']
        );
    }

    if (empty($prices)) {
        $prices[
            strtoupper(
                (string)
                $plan['default_currency']
            )
        ] = array(
            'amount' => (float)
                $plan['default_price'],
            'is_default' => 1
        );
    }

    $planData[
        (string) $plan['plan_id']
    ] = array(
        'name' =>
            (string) $plan['plan_name'],
        'code' =>
            (string) $plan['plan_code'],
        'billing_cycle' =>
            (string) $plan['billing_cycle'],
        'trial_days' =>
            (int) $plan['trial_days'],
        'default_currency' =>
            strtoupper(
                (string)
                $plan['default_currency']
            ),
        'prices' => $prices
    );
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .subscription-add-page {
        max-width: 1120px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .subscription-add-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .subscription-add-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .subscription-add-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .subscription-add-back {
        min-height: 36px;
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

    .subscription-add-back:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .subscription-add-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid #fecaca;
        border-radius: 10px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 10px;
        line-height: 1.55;
    }

    .subscription-add-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(290px, 340px);
        gap: 15px;
        align-items: start;
    }

    .subscription-add-main,
    .subscription-add-side {
        display: grid;
        gap: 15px;
    }

    .subscription-add-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .subscription-add-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .subscription-add-card-icon {
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

    .subscription-add-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .subscription-add-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .subscription-add-card-body {
        padding: 15px;
    }

    .subscription-add-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .subscription-add-required {
        color: #dc2626;
    }

    .subscription-add-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    textarea.subscription-add-control {
        min-height: 100px;
        resize: vertical;
    }

    .subscription-add-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .subscription-add-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .subscription-add-plan-preview {
        padding: 12px;
        border: 1px solid #ddd6fe;
        border-radius: 10px;
        background: #faf8ff;
    }

    .subscription-add-preview-name {
        color: #111827;
        font-size: 11px;
        font-weight: 800;
    }

    .subscription-add-preview-meta {
        margin-top: 5px;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .subscription-add-preview-badge {
        padding: 4px 7px;
        border-radius: 999px;
        background: #ffffff;
        color: #6d28d9;
        font-size: 7px;
        font-weight: 700;
    }

    .subscription-add-toggle {
        padding: 10px;
        display: flex;
        align-items: flex-start;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #fafafa;
        color: #4b5563;
        font-size: 9px;
        line-height: 1.45;
    }

    .subscription-add-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .subscription-add-submit {
        min-height: 41px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 0;
        border-radius: 9px;
        background:
            linear-gradient(
                135deg,
                #7c3aed,
                #6d28d9
            );
        color: #ffffff;
        font-size: 10px;
        font-weight: 700;
    }

    .subscription-add-submit:disabled {
        opacity: 0.65;
    }

    .subscription-add-cancel {
        min-height: 37px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #6b7280;
        font-size: 9px;
        font-weight: 600;
        text-decoration: none;
    }

    @media (max-width: 900px) {
        .subscription-add-layout {
            grid-template-columns: 1fr;
        }

        .subscription-add-side {
            order: -1;
        }
    }

    @media (max-width: 650px) {
        .subscription-add-header {
            align-items: flex-start;
            flex-direction: column;
        }
    }
</style>

<div class="subscription-add-page">

    <div class="subscription-add-header">
        <div>
            <h2 class="subscription-add-title">
                Add Subscription
            </h2>

            <div class="subscription-add-description">
                Assign a plan to a tenant with currency, dates, billing, and renewal settings.
            </div>
        </div>

        <a
            href="subscriptions.php"
            class="subscription-add-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Subscriptions
        </a>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="subscription-add-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= subscriptionAddEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="subscription-add.php"
        id="subscriptionAddForm"
        autocomplete="off"
    >
        <?php csrfField(); ?>

        <div class="subscription-add-layout">

            <div class="subscription-add-main">

                <section class="subscription-add-card">
                    <div class="subscription-add-card-header">
                        <span class="subscription-add-card-icon">
                            <i class="bi bi-buildings"></i>
                        </span>

                        <div>
                            <h3 class="subscription-add-card-title">
                                Tenant & Plan
                            </h3>

                            <div class="subscription-add-card-subtitle">
                                Choose the customer account and subscription plan
                            </div>
                        </div>
                    </div>

                    <div class="subscription-add-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label
                                    class="subscription-add-label"
                                    for="tenantId"
                                >
                                    Tenant
                                    <span class="subscription-add-required">*</span>
                                </label>

                                <select
                                    name="tenant_id"
                                    id="tenantId"
                                    class="form-select subscription-add-control"
                                    required
                                >
                                    <option value="">
                                        Select tenant
                                    </option>

                                    <?php foreach ($tenants as $tenant): ?>
                                        <option
                                            value="<?= (int)
                                                $tenant['tenant_id']; ?>"
                                            <?= $tenantId ===
                                                (int)
                                                $tenant['tenant_id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionAddEscape(
                                                $tenant['tenant_name'] .
                                                (
                                                    !empty(
                                                        $tenant['tenant_code']
                                                    )
                                                        ? ' (' .
                                                            $tenant['tenant_code'] .
                                                            ')'
                                                        : ''
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="subscription-add-label"
                                    for="planId"
                                >
                                    Subscription Plan
                                    <span class="subscription-add-required">*</span>
                                </label>

                                <select
                                    name="plan_id"
                                    id="planId"
                                    class="form-select subscription-add-control"
                                    required
                                >
                                    <option value="">
                                        Select plan
                                    </option>

                                    <?php foreach ($plans as $plan): ?>
                                        <option
                                            value="<?= (int)
                                                $plan['plan_id']; ?>"
                                            <?= $planId ===
                                                (int)
                                                $plan['plan_id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionAddEscape(
                                                $plan['plan_name'] .
                                                (
                                                    !empty(
                                                        $plan['plan_code']
                                                    )
                                                        ? ' (' .
                                                            $plan['plan_code'] .
                                                            ')'
                                                        : ''
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <div
                                    class="subscription-add-plan-preview"
                                    id="planPreview"
                                    style="display:none;"
                                >
                                    <div
                                        class="subscription-add-preview-name"
                                        id="planPreviewName"
                                    ></div>

                                    <div
                                        class="subscription-add-preview-meta"
                                        id="planPreviewMeta"
                                    ></div>
                                </div>
                            </div>

                        </div>
                    </div>
                </section>

                <section class="subscription-add-card">
                    <div class="subscription-add-card-header">
                        <span class="subscription-add-card-icon">
                            <i class="bi bi-currency-dollar"></i>
                        </span>

                        <div>
                            <h3 class="subscription-add-card-title">
                                Billing Details
                            </h3>

                            <div class="subscription-add-card-subtitle">
                                Currency, amount, billing cycle, and reference
                            </div>
                        </div>
                    </div>

                    <div class="subscription-add-card-body">
                        <div class="row g-3">

                            <div class="col-md-4">
                                <label
                                    class="subscription-add-label"
                                    for="currency"
                                >
                                    Currency
                                </label>

                                <select
                                    name="currency"
                                    id="currency"
                                    class="form-select subscription-add-control"
                                >
                                    <?php foreach (
                                        $allowedCurrencies as
                                        $currencyOption
                                    ): ?>
                                        <option
                                            value="<?= subscriptionAddEscape(
                                                $currencyOption
                                            ); ?>"
                                            <?= $currency ===
                                                $currencyOption
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionAddEscape(
                                                $currencyOption
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="subscription-add-label"
                                    for="amount"
                                >
                                    Amount
                                    <span class="subscription-add-required">*</span>
                                </label>

                                <input
                                    type="number"
                                    name="amount"
                                    id="amount"
                                    class="form-control subscription-add-control"
                                    value="<?= subscriptionAddEscape(
                                        $amount
                                    ); ?>"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="subscription-add-label"
                                    for="billingCycle"
                                >
                                    Billing Cycle
                                </label>

                                <select
                                    name="billing_cycle"
                                    id="billingCycle"
                                    class="form-select subscription-add-control"
                                >
                                    <?php foreach (
                                        $allowedCycles as
                                        $cycleOption
                                    ): ?>
                                        <option
                                            value="<?= subscriptionAddEscape(
                                                $cycleOption
                                            ); ?>"
                                            <?= $billingCycle ===
                                                $cycleOption
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionAddEscape(
                                                subscriptionAddLabel(
                                                    $cycleOption
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label
                                    class="subscription-add-label"
                                    for="referenceNo"
                                >
                                    Reference Number
                                </label>

                                <input
                                    type="text"
                                    name="reference_no"
                                    id="referenceNo"
                                    class="form-control subscription-add-control"
                                    value="<?= subscriptionAddEscape(
                                        $referenceNo
                                    ); ?>"
                                    maxlength="120"
                                    required
                                >
                            </div>

                        </div>
                    </div>
                </section>

                <section class="subscription-add-card">
                    <div class="subscription-add-card-header">
                        <span class="subscription-add-card-icon">
                            <i class="bi bi-calendar3"></i>
                        </span>

                        <div>
                            <h3 class="subscription-add-card-title">
                                Subscription Period
                            </h3>

                            <div class="subscription-add-card-subtitle">
                                Start, trial, and expiry dates
                            </div>
                        </div>
                    </div>

                    <div class="subscription-add-card-body">
                        <div class="row g-3">

                            <div class="col-md-4">
                                <label
                                    class="subscription-add-label"
                                    for="startsAt"
                                >
                                    Start Date
                                    <span class="subscription-add-required">*</span>
                                </label>

                                <input
                                    type="date"
                                    name="starts_at"
                                    id="startsAt"
                                    class="form-control subscription-add-control"
                                    value="<?= subscriptionAddEscape(
                                        $startsAt
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="subscription-add-label"
                                    for="trialEndsAt"
                                >
                                    Trial Ends
                                </label>

                                <input
                                    type="date"
                                    name="trial_ends_at"
                                    id="trialEndsAt"
                                    class="form-control subscription-add-control"
                                    value="<?= subscriptionAddEscape(
                                        $trialEndsAt
                                    ); ?>"
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="subscription-add-label"
                                    for="endsAt"
                                >
                                    End Date
                                </label>

                                <input
                                    type="date"
                                    name="ends_at"
                                    id="endsAt"
                                    class="form-control subscription-add-control"
                                    value="<?= subscriptionAddEscape(
                                        $endsAt
                                    ); ?>"
                                >

                                <div class="subscription-add-help">
                                    Leave blank for lifetime or open-ended subscriptions.
                                </div>
                            </div>

                        </div>
                    </div>
                </section>

                <section class="subscription-add-card">
                    <div class="subscription-add-card-header">
                        <span class="subscription-add-card-icon">
                            <i class="bi bi-journal-text"></i>
                        </span>

                        <div>
                            <h3 class="subscription-add-card-title">
                                Internal Notes
                            </h3>

                            <div class="subscription-add-card-subtitle">
                                Optional billing or account notes
                            </div>
                        </div>
                    </div>

                    <div class="subscription-add-card-body">
                        <textarea
                            name="notes"
                            class="form-control subscription-add-control"
                            placeholder="Add internal notes about this subscription"
                        ><?= subscriptionAddEscape(
                            $notes
                        ); ?></textarea>
                    </div>
                </section>

            </div>

            <aside class="subscription-add-side">

                <section class="subscription-add-card">
                    <div class="subscription-add-card-header">
                        <span class="subscription-add-card-icon">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <div>
                            <h3 class="subscription-add-card-title">
                                Subscription Settings
                            </h3>

                            <div class="subscription-add-card-subtitle">
                                Status and renewal controls
                            </div>
                        </div>
                    </div>

                    <div class="subscription-add-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label
                                    class="subscription-add-label"
                                    for="status"
                                >
                                    Status
                                </label>

                                <select
                                    name="status"
                                    id="status"
                                    class="form-select subscription-add-control"
                                >
                                    <?php foreach (
                                        $allowedStatuses as
                                        $statusOption
                                    ): ?>
                                        <option
                                            value="<?= subscriptionAddEscape(
                                                $statusOption
                                            ); ?>"
                                            <?= $status ===
                                                $statusOption
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionAddEscape(
                                                subscriptionAddLabel(
                                                    $statusOption
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="subscription-add-toggle">
                                    <input
                                        type="checkbox"
                                        name="auto_renew"
                                        value="1"
                                        <?= $autoRenew === 1
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span>
                                        <strong>
                                            Auto Renew
                                        </strong>
                                        <br>
                                        Automatically renew this subscription at the end of its billing period.
                                    </span>
                                </label>
                            </div>

                        </div>
                    </div>
                </section>

                <div class="subscription-add-submit-card">
                    <button
                        type="submit"
                        class="subscription-add-submit"
                        id="subscriptionSubmit"
                    >
                        <i class="bi bi-check2-circle"></i>
                        Create Subscription
                    </button>

                    <a
                        href="subscriptions.php"
                        class="subscription-add-cancel"
                    >
                        Cancel
                    </a>
                </div>

            </aside>

        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    const planData =
        <?= json_encode(
            $planData,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ); ?>;

    const planSelect =
        document.getElementById('planId');

    const currencySelect =
        document.getElementById('currency');

    const amountInput =
        document.getElementById('amount');

    const cycleSelect =
        document.getElementById('billingCycle');

    const startsAt =
        document.getElementById('startsAt');

    const trialEndsAt =
        document.getElementById('trialEndsAt');

    const statusSelect =
        document.getElementById('status');

    const planPreview =
        document.getElementById('planPreview');

    const planPreviewName =
        document.getElementById(
            'planPreviewName'
        );

    const planPreviewMeta =
        document.getElementById(
            'planPreviewMeta'
        );

    function formatMoney(amount, currency) {
        try {
            return new Intl.NumberFormat(
                'en-US',
                {
                    style: 'currency',
                    currency: currency
                }
            ).format(amount);
        } catch (error) {
            return currency + ' ' +
                Number(amount).toFixed(2);
        }
    }

    function getSelectedPlan() {
        if (!planSelect) {
            return null;
        }

        return planData[
            String(planSelect.value)
        ] || null;
    }

    function updateCurrencyOptions(plan) {
        if (
            !currencySelect ||
            !plan
        ) {
            return;
        }

        const existingValue =
            currencySelect.value;

        const currencies =
            Object.keys(plan.prices || {});

        Array.from(
            currencySelect.options
        ).forEach(function (option) {
            option.disabled =
                currencies.indexOf(
                    option.value
                ) === -1;
        });

        if (
            currencies.indexOf(
                existingValue
            ) === -1
        ) {
            currencySelect.value =
                plan.default_currency ||
                currencies[0] ||
                'USD';
        }
    }

    function updateAmount(plan) {
        if (
            !plan ||
            !currencySelect ||
            !amountInput
        ) {
            return;
        }

        const selectedCurrency =
            currencySelect.value;

        if (
            plan.prices &&
            plan.prices[selectedCurrency]
        ) {
            amountInput.value =
                Number(
                    plan.prices[
                        selectedCurrency
                    ].amount
                ).toFixed(2);
        }
    }

    function updateTrialDate(plan) {
        if (
            !plan ||
            !startsAt ||
            !trialEndsAt
        ) {
            return;
        }

        const trialDays =
            Number(plan.trial_days || 0);

        if (
            trialDays <= 0 ||
            !startsAt.value
        ) {
            trialEndsAt.value = '';
            return;
        }

        const date =
            new Date(
                startsAt.value +
                'T00:00:00'
            );

        date.setDate(
            date.getDate() +
            trialDays
        );

        const year =
            date.getFullYear();

        const month =
            String(
                date.getMonth() + 1
            ).padStart(2, '0');

        const day =
            String(
                date.getDate()
            ).padStart(2, '0');

        trialEndsAt.value =
            year + '-' + month + '-' + day;
    }

    function updatePlanPreview(plan) {
        if (
            !planPreview ||
            !planPreviewName ||
            !planPreviewMeta
        ) {
            return;
        }

        if (!plan) {
            planPreview.style.display =
                'none';
            return;
        }

        planPreview.style.display =
            'block';

        planPreviewName.textContent =
            plan.name;

        planPreviewMeta.innerHTML = '';

        const cycleBadge =
            document.createElement('span');

        cycleBadge.className =
            'subscription-add-preview-badge';

        cycleBadge.textContent =
            String(
                plan.billing_cycle
            )
            .replace(/_/g, ' ')
            .replace(/\b\w/g, function (letter) {
                return letter.toUpperCase();
            });

        planPreviewMeta.appendChild(
            cycleBadge
        );

        const trialBadge =
            document.createElement('span');

        trialBadge.className =
            'subscription-add-preview-badge';

        trialBadge.textContent =
            Number(plan.trial_days || 0) > 0
                ? plan.trial_days +
                    ' trial days'
                : 'No trial';

        planPreviewMeta.appendChild(
            trialBadge
        );

        const currency =
            currencySelect
                ? currencySelect.value
                : plan.default_currency;

        if (
            plan.prices &&
            plan.prices[currency]
        ) {
            const priceBadge =
                document.createElement('span');

            priceBadge.className =
                'subscription-add-preview-badge';

            priceBadge.textContent =
                formatMoney(
                    plan.prices[currency].amount,
                    currency
                );

            planPreviewMeta.appendChild(
                priceBadge
            );
        }
    }

    function applyPlanDefaults() {
        const plan =
            getSelectedPlan();

        if (!plan) {
            updatePlanPreview(null);
            return;
        }

        updateCurrencyOptions(plan);

        if (cycleSelect) {
            cycleSelect.value =
                plan.billing_cycle ||
                'monthly';
        }

        updateAmount(plan);
        updateTrialDate(plan);
        updatePlanPreview(plan);

        if (
            statusSelect &&
            Number(plan.trial_days || 0) > 0
        ) {
            statusSelect.value = 'trial';
        }
    }

    if (planSelect) {
        planSelect.addEventListener(
            'change',
            applyPlanDefaults
        );
    }

    if (currencySelect) {
        currencySelect.addEventListener(
            'change',
            function () {
                const plan =
                    getSelectedPlan();

                updateAmount(plan);
                updatePlanPreview(plan);
            }
        );
    }

    if (startsAt) {
        startsAt.addEventListener(
            'change',
            function () {
                updateTrialDate(
                    getSelectedPlan()
                );
            }
        );
    }

    if (
        planSelect &&
        planSelect.value
    ) {
        applyPlanDefaults();
    }

    const form =
        document.getElementById(
            'subscriptionAddForm'
        );

    const submitButton =
        document.getElementById(
            'subscriptionSubmit'
        );

    if (
        form &&
        submitButton
    ) {
        form.addEventListener(
            'submit',
            function () {
                submitButton.disabled = true;
                submitButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm"></span> Creating...';
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
