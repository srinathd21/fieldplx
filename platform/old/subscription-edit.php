<?php
/**
 * FieldPlx Platform - Edit Subscription
 *
 * File:
 * platform/subscription-edit.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - tenants
 * - plans
 * - plan_prices
 * - subscriptions
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin'
));

$pageTitle = 'Edit Subscription - FieldPlx';
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

if (!function_exists('subscriptionEditEscape')) {
    function subscriptionEditEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('subscriptionEditPost')) {
    function subscriptionEditPost(
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

if (!function_exists('subscriptionEditTableExists')) {
    function subscriptionEditTableExists(
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

if (!function_exists('subscriptionEditColumns')) {
    function subscriptionEditColumns(
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

if (!function_exists('subscriptionEditFirstColumn')) {
    function subscriptionEditFirstColumn(
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

if (!function_exists('subscriptionEditMoney')) {
    function subscriptionEditMoney($value)
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

if (!function_exists('subscriptionEditDate')) {
    function subscriptionEditDate(
        $value,
        $endOfDay = false
    ) {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return false;
        }

        return date(
            $endOfDay
                ? 'Y-m-d 23:59:59'
                : 'Y-m-d 00:00:00',
            $timestamp
        );
    }
}

if (!function_exists('subscriptionEditInputDate')) {
    function subscriptionEditInputDate($value)
    {
        if (empty($value)) {
            return '';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }
}

if (!function_exists('subscriptionEditLabel')) {
    function subscriptionEditLabel($value)
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
        !subscriptionEditTableExists(
            $conn,
            $requiredTable
        )
    ) {
        http_response_code(500);

        exit(
            'The ' .
            subscriptionEditEscape($requiredTable) .
            ' table does not exist.'
        );
    }
}

$hasPlanPrices =
    subscriptionEditTableExists(
        $conn,
        'plan_prices'
    );

/*
|--------------------------------------------------------------------------
| Detect structures
|--------------------------------------------------------------------------
*/

$tenantColumns =
    subscriptionEditColumns(
        $conn,
        'tenants'
    );

$planColumns =
    subscriptionEditColumns(
        $conn,
        'plans'
    );

$subscriptionColumns =
    subscriptionEditColumns(
        $conn,
        'subscriptions'
    );

$tenantIdColumn =
    subscriptionEditFirstColumn(
        $tenantColumns,
        array('id', 'tenant_id')
    );

$tenantNameColumn =
    subscriptionEditFirstColumn(
        $tenantColumns,
        array(
            'business_name',
            'name',
            'tenant_name',
            'company_name'
        )
    );

$tenantCodeColumn =
    subscriptionEditFirstColumn(
        $tenantColumns,
        array(
            'tenant_code',
            'business_code',
            'code',
            'slug'
        )
    );

$tenantStatusColumn =
    subscriptionEditFirstColumn(
        $tenantColumns,
        array('status')
    );

$tenantDeletedColumn =
    subscriptionEditFirstColumn(
        $tenantColumns,
        array('deleted_at')
    );

$planIdColumn =
    subscriptionEditFirstColumn(
        $planColumns,
        array('id', 'plan_id')
    );

$planNameColumn =
    subscriptionEditFirstColumn(
        $planColumns,
        array('name', 'plan_name')
    );

$planCodeColumn =
    subscriptionEditFirstColumn(
        $planColumns,
        array('code', 'plan_code')
    );

$planPriceColumn =
    subscriptionEditFirstColumn(
        $planColumns,
        array('price', 'amount')
    );

$planCurrencyColumn =
    subscriptionEditFirstColumn(
        $planColumns,
        array('currency', 'currency_code')
    );

$planCycleColumn =
    subscriptionEditFirstColumn(
        $planColumns,
        array(
            'billing_cycle',
            'billing_period',
            'cycle'
        )
    );

$planTrialDaysColumn =
    subscriptionEditFirstColumn(
        $planColumns,
        array('trial_days', 'free_trial_days')
    );

$planStatusColumn =
    subscriptionEditFirstColumn(
        $planColumns,
        array('status')
    );

$planDeletedColumn =
    subscriptionEditFirstColumn(
        $planColumns,
        array('deleted_at')
    );

$subscriptionIdColumn =
    subscriptionEditFirstColumn(
        $subscriptionColumns,
        array('id', 'subscription_id')
    );

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === '' ||
    $planIdColumn === '' ||
    $planNameColumn === '' ||
    $subscriptionIdColumn === ''
) {
    http_response_code(500);

    exit(
        'Required tenant, plan, or subscription columns are missing.'
    );
}

/*
|--------------------------------------------------------------------------
| Resolve subscription
|--------------------------------------------------------------------------
*/

$subscriptionId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (
        isset($_POST['subscription_id'])
            ? (int) $_POST['subscription_id']
            : 0
    );

if ($subscriptionId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid subscription.';

    header('Location: subscriptions.php');
    exit;
}

$subscriptionSql = "
    SELECT *
    FROM subscriptions
    WHERE `{$subscriptionIdColumn}` = ?
";

if (isset($subscriptionColumns['deleted_at'])) {
    $subscriptionSql .= "
        AND `deleted_at` IS NULL
    ";
}

$subscriptionSql .= " LIMIT 1";

$subscriptionStmt =
    $conn->prepare($subscriptionSql);

$subscriptionStmt->bind_param(
    'i',
    $subscriptionId
);

$subscriptionStmt->execute();

$currentSubscription =
    $subscriptionStmt
    ->get_result()
    ->fetch_assoc();

$subscriptionStmt->close();

if (!$currentSubscription) {
    $_SESSION['platform_error_message'] =
        'Subscription not found.';

    header('Location: subscriptions.php');
    exit;
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
    SELECT " .
        implode(', ', $tenantSelect) . "
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

while ($tenantRow = $tenantResult->fetch_assoc()) {
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
    SELECT " .
        implode(', ', $planSelect) . "
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
        AND (
            `{$planStatusColumn}` = 'active'
            OR `{$planIdColumn}` = " .
            (int) $currentSubscription['plan_id'] .
        ")
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
        subscriptionEditColumns(
            $conn,
            'plan_prices'
        );

    $pricePlanColumn =
        subscriptionEditFirstColumn(
            $priceColumns,
            array('plan_id')
        );

    $priceCurrencyColumn =
        subscriptionEditFirstColumn(
            $priceColumns,
            array('currency_code', 'currency')
        );

    $priceAmountColumn =
        subscriptionEditFirstColumn(
            $priceColumns,
            array('amount', 'price')
        );

    $priceDefaultColumn =
        subscriptionEditFirstColumn(
            $priceColumns,
            array('is_default')
        );

    $priceActiveColumn =
        subscriptionEditFirstColumn(
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
            SELECT " .
                implode(', ', $priceSelect) . "
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

        while ($priceRow = $priceResult->fetch_assoc()) {
            $planIdForPrice =
                (int) $priceRow['plan_id'];

            if (isset($planMap[$planIdForPrice])) {
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

$plans = array_values($planMap);

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$tenantId = isset($_POST['tenant_id'])
    ? (int) $_POST['tenant_id']
    : (int) $currentSubscription['tenant_id'];

$planId = isset($_POST['plan_id'])
    ? (int) $_POST['plan_id']
    : (int) $currentSubscription['plan_id'];

$referenceNo = isset($_POST['reference_no'])
    ? subscriptionEditPost('reference_no')
    : (string) $currentSubscription['reference_no'];

$status = isset($_POST['status'])
    ? subscriptionEditPost('status')
    : (string) $currentSubscription['status'];

$billingCycle = isset($_POST['billing_cycle'])
    ? subscriptionEditPost('billing_cycle')
    : (string) $currentSubscription['billing_cycle'];

$currency = isset($_POST['currency'])
    ? strtoupper(
        subscriptionEditPost('currency')
    )
    : strtoupper(
        (string) $currentSubscription['currency']
    );

$amount = isset($_POST['amount'])
    ? subscriptionEditPost('amount')
    : number_format(
        (float) $currentSubscription['amount'],
        2,
        '.',
        ''
    );

$startsAt = isset($_POST['starts_at'])
    ? subscriptionEditPost('starts_at')
    : subscriptionEditInputDate(
        $currentSubscription['starts_at']
    );

$endsAt = isset($_POST['ends_at'])
    ? subscriptionEditPost('ends_at')
    : subscriptionEditInputDate(
        $currentSubscription['ends_at']
    );

$trialEndsAt = isset($_POST['trial_ends_at'])
    ? subscriptionEditPost('trial_ends_at')
    : subscriptionEditInputDate(
        $currentSubscription['trial_ends_at']
    );

$autoRenew =
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
        ? (!empty($_POST['auto_renew']) ? 1 : 0)
        : (int) $currentSubscription['auto_renew'];

$notes = isset($_POST['notes'])
    ? subscriptionEditPost('notes')
    : (string) $currentSubscription['notes'];

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
| Process update
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    if ($tenantId <= 0) {
        $errorMessage =
            'Select a tenant.';
    } elseif ($planId <= 0) {
        $errorMessage =
            'Select a subscription plan.';
    } elseif (!isset($planMap[$planId])) {
        $errorMessage =
            'The selected plan is not available.';
    } elseif ($referenceNo === '') {
        $errorMessage =
            'Enter the subscription reference number.';
    } elseif (strlen($referenceNo) > 120) {
        $errorMessage =
            'Reference number must not exceed 120 characters.';
    }

    $amountValue = null;

    if ($errorMessage === '') {
        $amountValue =
            subscriptionEditMoney($amount);

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
            subscriptionEditDate($startsAt);

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
            subscriptionEditDate(
                $endsAt,
                true
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
            subscriptionEditDate(
                $trialEndsAt,
                true
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
                  AND `id` <> ?
                  AND `status` IN (
                    'trial',
                    'active',
                    'past_due',
                    'suspended'
                  )
                  AND `deleted_at` IS NULL
            ");

        $duplicateStmt->bind_param(
            'ii',
            $tenantId,
            $subscriptionId
        );

        $duplicateStmt->execute();

        $duplicateRow =
            $duplicateStmt
            ->get_result()
            ->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'This tenant already has another active or pending subscription.';
        }
    }

    if ($errorMessage === '') {
        $referenceStmt =
            $conn->prepare("
                SELECT COUNT(*) AS total
                FROM subscriptions
                WHERE LOWER(`reference_no`) =
                    LOWER(?)
                  AND `id` <> ?
                  AND `deleted_at` IS NULL
            ");

        $referenceStmt->bind_param(
            'si',
            $referenceNo,
            $subscriptionId
        );

        $referenceStmt->execute();

        $referenceRow =
            $referenceStmt
            ->get_result()
            ->fetch_assoc();

        $referenceStmt->close();

        if (!empty($referenceRow['total'])) {
            $errorMessage =
                'Another subscription already uses this reference number.';
        }
    }

    if ($errorMessage === '') {
        try {
            $conn->begin_transaction();

            $updateStmt =
                $conn->prepare("
                    UPDATE subscriptions
                    SET
                        `tenant_id` = ?,
                        `plan_id` = ?,
                        `reference_no` = ?,
                        `status` = ?,
                        `starts_at` = ?,
                        `ends_at` = ?,
                        `trial_ends_at` = ?,
                        `amount` = ?,
                        `currency` = ?,
                        `billing_cycle` = ?,
                        `auto_renew` = ?,
                        `notes` = ?,
                        `updated_at` = NOW()
                    WHERE `id` = ?
                    LIMIT 1
                ");

            $updateStmt->bind_param(
                'iisssssdssisi',
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
                $notes,
                $subscriptionId
            );

            $updateStmt->execute();
            $updateStmt->close();

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

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Subscription updated successfully.';

            header(
                'Location: subscription-view.php?id=' .
                $subscriptionId,
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            $conn->rollback();

            error_log(
                'Subscription update failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to update the subscription: ' .
                $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| JavaScript plan data
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
    .subscription-edit-page {
        max-width: 1120px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .subscription-edit-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .subscription-edit-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .subscription-edit-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .subscription-edit-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .subscription-edit-button {
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

    .subscription-edit-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .subscription-edit-alert {
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

    .subscription-edit-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(290px, 340px);
        gap: 15px;
        align-items: start;
    }

    .subscription-edit-main,
    .subscription-edit-side {
        display: grid;
        gap: 15px;
    }

    .subscription-edit-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .subscription-edit-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .subscription-edit-card-icon {
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

    .subscription-edit-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .subscription-edit-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .subscription-edit-card-body {
        padding: 15px;
    }

    .subscription-edit-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .subscription-edit-required {
        color: #dc2626;
    }

    .subscription-edit-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    textarea.subscription-edit-control {
        min-height: 100px;
        resize: vertical;
    }

    .subscription-edit-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .subscription-edit-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .subscription-edit-plan-preview {
        padding: 12px;
        border: 1px solid #ddd6fe;
        border-radius: 10px;
        background: #faf8ff;
    }

    .subscription-edit-preview-name {
        color: #111827;
        font-size: 11px;
        font-weight: 800;
    }

    .subscription-edit-preview-meta {
        margin-top: 5px;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .subscription-edit-preview-badge {
        padding: 4px 7px;
        border-radius: 999px;
        background: #ffffff;
        color: #6d28d9;
        font-size: 7px;
        font-weight: 700;
    }

    .subscription-edit-toggle {
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

    .subscription-edit-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .subscription-edit-submit {
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

    .subscription-edit-submit:disabled {
        opacity: 0.65;
    }

    .subscription-edit-cancel {
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
        .subscription-edit-layout {
            grid-template-columns: 1fr;
        }

        .subscription-edit-side {
            order: -1;
        }
    }

    @media (max-width: 650px) {
        .subscription-edit-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .subscription-edit-actions {
            width: 100%;
        }

        .subscription-edit-button {
            flex: 1;
        }
    }
</style>

<div class="subscription-edit-page">

    <div class="subscription-edit-header">
        <div>
            <h2 class="subscription-edit-title">
                Edit Subscription
            </h2>

            <div class="subscription-edit-description">
                Update tenant, plan, pricing, dates, status, and renewal settings.
            </div>
        </div>

        <div class="subscription-edit-actions">
            <a
                href="subscription-view.php?id=<?= (int) $subscriptionId; ?>"
                class="subscription-edit-button"
            >
                <i class="bi bi-eye"></i>
                View Subscription
            </a>

            <a
                href="subscriptions.php"
                class="subscription-edit-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Subscriptions
            </a>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="subscription-edit-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= subscriptionEditEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="subscription-edit.php?id=<?= (int) $subscriptionId; ?>"
        id="subscriptionEditForm"
        autocomplete="off"
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="subscription_id"
            value="<?= (int) $subscriptionId; ?>"
        >

        <div class="subscription-edit-layout">

            <div class="subscription-edit-main">

                <section class="subscription-edit-card">
                    <div class="subscription-edit-card-header">
                        <span class="subscription-edit-card-icon">
                            <i class="bi bi-buildings"></i>
                        </span>

                        <div>
                            <h3 class="subscription-edit-card-title">
                                Tenant & Plan
                            </h3>

                            <div class="subscription-edit-card-subtitle">
                                Change the linked tenant or subscription plan
                            </div>
                        </div>
                    </div>

                    <div class="subscription-edit-card-body">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label
                                    class="subscription-edit-label"
                                    for="tenantId"
                                >
                                    Tenant
                                    <span class="subscription-edit-required">*</span>
                                </label>

                                <select
                                    name="tenant_id"
                                    id="tenantId"
                                    class="form-select subscription-edit-control"
                                    required
                                >
                                    <option value="">
                                        Select tenant
                                    </option>

                                    <?php foreach ($tenants as $tenant): ?>
                                        <option
                                            value="<?= (int) $tenant['tenant_id']; ?>"
                                            <?= $tenantId ===
                                                (int) $tenant['tenant_id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionEditEscape(
                                                $tenant['tenant_name'] .
                                                (
                                                    !empty($tenant['tenant_code'])
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
                                    class="subscription-edit-label"
                                    for="planId"
                                >
                                    Subscription Plan
                                    <span class="subscription-edit-required">*</span>
                                </label>

                                <select
                                    name="plan_id"
                                    id="planId"
                                    class="form-select subscription-edit-control"
                                    required
                                >
                                    <option value="">
                                        Select plan
                                    </option>

                                    <?php foreach ($plans as $plan): ?>
                                        <option
                                            value="<?= (int) $plan['plan_id']; ?>"
                                            <?= $planId ===
                                                (int) $plan['plan_id']
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionEditEscape(
                                                $plan['plan_name'] .
                                                (
                                                    !empty($plan['plan_code'])
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
                                    class="subscription-edit-plan-preview"
                                    id="planPreview"
                                    style="display:none;"
                                >
                                    <div
                                        class="subscription-edit-preview-name"
                                        id="planPreviewName"
                                    ></div>

                                    <div
                                        class="subscription-edit-preview-meta"
                                        id="planPreviewMeta"
                                    ></div>
                                </div>
                            </div>

                        </div>
                    </div>
                </section>

                <section class="subscription-edit-card">
                    <div class="subscription-edit-card-header">
                        <span class="subscription-edit-card-icon">
                            <i class="bi bi-currency-dollar"></i>
                        </span>

                        <div>
                            <h3 class="subscription-edit-card-title">
                                Billing Details
                            </h3>

                            <div class="subscription-edit-card-subtitle">
                                Currency, amount, billing cycle, and reference
                            </div>
                        </div>
                    </div>

                    <div class="subscription-edit-card-body">
                        <div class="row g-3">

                            <div class="col-md-4">
                                <label
                                    class="subscription-edit-label"
                                    for="currency"
                                >
                                    Currency
                                </label>

                                <select
                                    name="currency"
                                    id="currency"
                                    class="form-select subscription-edit-control"
                                >
                                    <?php foreach (
                                        $allowedCurrencies as
                                        $currencyOption
                                    ): ?>
                                        <option
                                            value="<?= subscriptionEditEscape(
                                                $currencyOption
                                            ); ?>"
                                            <?= $currency ===
                                                $currencyOption
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionEditEscape(
                                                $currencyOption
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="subscription-edit-label"
                                    for="amount"
                                >
                                    Amount
                                    <span class="subscription-edit-required">*</span>
                                </label>

                                <input
                                    type="number"
                                    name="amount"
                                    id="amount"
                                    class="form-control subscription-edit-control"
                                    value="<?= subscriptionEditEscape(
                                        $amount
                                    ); ?>"
                                    min="0"
                                    step="0.01"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="subscription-edit-label"
                                    for="billingCycle"
                                >
                                    Billing Cycle
                                </label>

                                <select
                                    name="billing_cycle"
                                    id="billingCycle"
                                    class="form-select subscription-edit-control"
                                >
                                    <?php foreach (
                                        $allowedCycles as
                                        $cycleOption
                                    ): ?>
                                        <option
                                            value="<?= subscriptionEditEscape(
                                                $cycleOption
                                            ); ?>"
                                            <?= $billingCycle ===
                                                $cycleOption
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionEditEscape(
                                                subscriptionEditLabel(
                                                    $cycleOption
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label
                                    class="subscription-edit-label"
                                    for="referenceNo"
                                >
                                    Reference Number
                                </label>

                                <input
                                    type="text"
                                    name="reference_no"
                                    id="referenceNo"
                                    class="form-control subscription-edit-control"
                                    value="<?= subscriptionEditEscape(
                                        $referenceNo
                                    ); ?>"
                                    maxlength="120"
                                    required
                                >
                            </div>

                        </div>
                    </div>
                </section>

                <section class="subscription-edit-card">
                    <div class="subscription-edit-card-header">
                        <span class="subscription-edit-card-icon">
                            <i class="bi bi-calendar3"></i>
                        </span>

                        <div>
                            <h3 class="subscription-edit-card-title">
                                Subscription Period
                            </h3>

                            <div class="subscription-edit-card-subtitle">
                                Start, trial, and expiry dates
                            </div>
                        </div>
                    </div>

                    <div class="subscription-edit-card-body">
                        <div class="row g-3">

                            <div class="col-md-4">
                                <label
                                    class="subscription-edit-label"
                                    for="startsAt"
                                >
                                    Start Date
                                    <span class="subscription-edit-required">*</span>
                                </label>

                                <input
                                    type="date"
                                    name="starts_at"
                                    id="startsAt"
                                    class="form-control subscription-edit-control"
                                    value="<?= subscriptionEditEscape(
                                        $startsAt
                                    ); ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="subscription-edit-label"
                                    for="trialEndsAt"
                                >
                                    Trial Ends
                                </label>

                                <input
                                    type="date"
                                    name="trial_ends_at"
                                    id="trialEndsAt"
                                    class="form-control subscription-edit-control"
                                    value="<?= subscriptionEditEscape(
                                        $trialEndsAt
                                    ); ?>"
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="subscription-edit-label"
                                    for="endsAt"
                                >
                                    End Date
                                </label>

                                <input
                                    type="date"
                                    name="ends_at"
                                    id="endsAt"
                                    class="form-control subscription-edit-control"
                                    value="<?= subscriptionEditEscape(
                                        $endsAt
                                    ); ?>"
                                >

                                <div class="subscription-edit-help">
                                    Leave blank for lifetime or open-ended subscriptions.
                                </div>
                            </div>

                        </div>
                    </div>
                </section>

                <section class="subscription-edit-card">
                    <div class="subscription-edit-card-header">
                        <span class="subscription-edit-card-icon">
                            <i class="bi bi-journal-text"></i>
                        </span>

                        <div>
                            <h3 class="subscription-edit-card-title">
                                Internal Notes
                            </h3>

                            <div class="subscription-edit-card-subtitle">
                                Optional billing or account notes
                            </div>
                        </div>
                    </div>

                    <div class="subscription-edit-card-body">
                        <textarea
                            name="notes"
                            class="form-control subscription-edit-control"
                            placeholder="Add internal notes about this subscription"
                        ><?= subscriptionEditEscape(
                            $notes
                        ); ?></textarea>
                    </div>
                </section>

            </div>

            <aside class="subscription-edit-side">

                <section class="subscription-edit-card">
                    <div class="subscription-edit-card-header">
                        <span class="subscription-edit-card-icon">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <div>
                            <h3 class="subscription-edit-card-title">
                                Subscription Settings
                            </h3>

                            <div class="subscription-edit-card-subtitle">
                                Status and renewal controls
                            </div>
                        </div>
                    </div>

                    <div class="subscription-edit-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label
                                    class="subscription-edit-label"
                                    for="status"
                                >
                                    Status
                                </label>

                                <select
                                    name="status"
                                    id="status"
                                    class="form-select subscription-edit-control"
                                >
                                    <?php foreach (
                                        $allowedStatuses as
                                        $statusOption
                                    ): ?>
                                        <option
                                            value="<?= subscriptionEditEscape(
                                                $statusOption
                                            ); ?>"
                                            <?= $status ===
                                                $statusOption
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= subscriptionEditEscape(
                                                subscriptionEditLabel(
                                                    $statusOption
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="subscription-edit-toggle">
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

                <div class="subscription-edit-submit-card">
                    <button
                        type="submit"
                        class="subscription-edit-submit"
                        id="subscriptionSubmit"
                    >
                        <i class="bi bi-check2-circle"></i>
                        Update Subscription
                    </button>

                    <a
                        href="subscription-view.php?id=<?= (int) $subscriptionId; ?>"
                        class="subscription-edit-cancel"
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

    const originalPlanId =
        '<?= (int) $currentSubscription['plan_id']; ?>';

    const originalCurrency =
        '<?= subscriptionEditEscape(
            strtoupper(
                (string)
                $currentSubscription['currency']
            )
        ); ?>';

    const originalAmount =
        '<?= subscriptionEditEscape(
            number_format(
                (float)
                $currentSubscription['amount'],
                2,
                '.',
                ''
            )
        ); ?>';

    const originalCycle =
        '<?= subscriptionEditEscape(
            (string)
            $currentSubscription['billing_cycle']
        ); ?>';

    const originalTrialDate =
        '<?= subscriptionEditEscape(
            subscriptionEditInputDate(
                $currentSubscription['trial_ends_at']
            )
        ); ?>';

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
                'en-GB',
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

        const currentValue =
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
                currentValue
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
            !trialEndsAt ||
            !startsAt.value
        ) {
            return;
        }

        const trialDays =
            Number(plan.trial_days || 0);

        if (trialDays <= 0) {
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

    function updatePreview(plan) {
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
            'subscription-edit-preview-badge';

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
            'subscription-edit-preview-badge';

        trialBadge.textContent =
            Number(plan.trial_days || 0) > 0
                ? plan.trial_days +
                    ' trial days'
                : 'No trial';

        planPreviewMeta.appendChild(
            trialBadge
        );

        const selectedCurrency =
            currencySelect
                ? currencySelect.value
                : plan.default_currency;

        if (
            plan.prices &&
            plan.prices[selectedCurrency]
        ) {
            const priceBadge =
                document.createElement('span');

            priceBadge.className =
                'subscription-edit-preview-badge';

            priceBadge.textContent =
                formatMoney(
                    plan.prices[
                        selectedCurrency
                    ].amount,
                    selectedCurrency
                );

            planPreviewMeta.appendChild(
                priceBadge
            );
        }
    }

    function applyPlanDefaults(forceDefaults) {
        const plan = getSelectedPlan();

        if (!plan) {
            updatePreview(null);
            return;
        }

        updateCurrencyOptions(plan);

        if (forceDefaults) {
            if (cycleSelect) {
                cycleSelect.value =
                    plan.billing_cycle ||
                    'monthly';
            }

            updateAmount(plan);
            updateTrialDate(plan);
        }

        updatePreview(plan);
    }

    if (planSelect) {
        planSelect.addEventListener(
            'change',
            function () {
                applyPlanDefaults(true);
            }
        );
    }

    if (currencySelect) {
        currencySelect.addEventListener(
            'change',
            function () {
                const plan = getSelectedPlan();

                updateAmount(plan);
                updatePreview(plan);
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
        applyPlanDefaults(false);

        if (
            String(planSelect.value) ===
            String(originalPlanId)
        ) {
            if (currencySelect) {
                currencySelect.value =
                    originalCurrency;
            }

            if (amountInput) {
                amountInput.value =
                    originalAmount;
            }

            if (cycleSelect) {
                cycleSelect.value =
                    originalCycle;
            }

            if (
                trialEndsAt &&
                originalTrialDate
            ) {
                trialEndsAt.value =
                    originalTrialDate;
            }

            updatePreview(
                getSelectedPlan()
            );
        }
    }

    const form =
        document.getElementById(
            'subscriptionEditForm'
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
                    '<span class="spinner-border spinner-border-sm"></span> Updating...';
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
