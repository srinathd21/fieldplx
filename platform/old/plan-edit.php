<?php
/**
 * FieldPlx Platform - Edit Subscription Plan
 *
 * File:
 * platform/plan-edit.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - Multi-currency plan_prices table
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin'
));

$pageTitle = 'Edit Subscription Plan - FieldPlx';
$activePage = 'plans';
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

if (!function_exists('planEditEscape')) {
    function planEditEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('planEditPost')) {
    function planEditPost($key, $default = '')
    {
        if (
            !isset($_POST[$key]) ||
            is_array($_POST[$key])
        ) {
            return $default;
        }

        return trim((string) $_POST[$key]);
    }
}

if (!function_exists('planEditTableExists')) {
    function planEditTableExists(
        mysqli $conn,
        $tableName
    ) {
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

        return !empty($row['total']);
    }
}

if (!function_exists('planEditCode')) {
    function planEditCode($value)
    {
        $value = strtolower(trim((string) $value));

        $value = preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        );

        return trim($value, '-');
    }
}

if (!function_exists('planEditMoneyValue')) {
    function planEditMoneyValue($value)
    {
        $value = trim((string) $value);
        $value = str_replace(',', '', $value);

        if ($value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return false;
        }

        return round((float) $value, 2);
    }
}

if (!function_exists('planEditBind')) {
    function planEditBind(
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

/*
|--------------------------------------------------------------------------
| Verify tables
|--------------------------------------------------------------------------
*/

if (!planEditTableExists($conn, 'plans')) {
    http_response_code(500);
    exit('The plans table does not exist.');
}

if (!planEditTableExists($conn, 'plan_prices')) {
    http_response_code(500);
    exit(
        'The plan_prices table does not exist. ' .
        'Run create-plan-prices-table.sql first.'
    );
}

/*
|--------------------------------------------------------------------------
| Currency configuration
|--------------------------------------------------------------------------
*/

$currencies = array(
    'USD' => array(
        'name' => 'US Dollar',
        'symbol' => '$',
        'market' => 'United States'
    ),
    'GBP' => array(
        'name' => 'British Pound',
        'symbol' => '£',
        'market' => 'United Kingdom'
    ),
    'EUR' => array(
        'name' => 'Euro',
        'symbol' => '€',
        'market' => 'European Union'
    ),
    'CAD' => array(
        'name' => 'Canadian Dollar',
        'symbol' => 'C$',
        'market' => 'Canada'
    ),
    'AUD' => array(
        'name' => 'Australian Dollar',
        'symbol' => 'A$',
        'market' => 'Australia'
    ),
    'INR' => array(
        'name' => 'Indian Rupee',
        'symbol' => '₹',
        'market' => 'India'
    )
);

$billingCycles = array(
    'monthly' => 'Monthly',
    'quarterly' => 'Quarterly',
    'half_yearly' => 'Half Yearly',
    'yearly' => 'Yearly',
    'lifetime' => 'Lifetime',
    'custom' => 'Custom'
);

$allowedStatuses = array(
    'active',
    'inactive',
    'draft',
    'archived'
);

/*
|--------------------------------------------------------------------------
| Load plan
|--------------------------------------------------------------------------
*/

$planId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (
        isset($_POST['plan_id'])
            ? (int) $_POST['plan_id']
            : 0
    );

if ($planId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid subscription plan.';

    header('Location: plans.php');
    exit;
}

$planStmt = $conn->prepare("
    SELECT
        `id`,
        `name`,
        `code`,
        `description`,
        `price`,
        `currency`,
        `billing_cycle`,
        `trial_days`,
        `max_users`,
        `max_branches`,
        `storage_limit_mb`,
        `is_featured`,
        `status`
    FROM plans
    WHERE `id` = ?
      AND `deleted_at` IS NULL
    LIMIT 1
");

$planStmt->bind_param('i', $planId);
$planStmt->execute();

$currentPlan = $planStmt
    ->get_result()
    ->fetch_assoc();

$planStmt->close();

if (!$currentPlan) {
    $_SESSION['platform_error_message'] =
        'Subscription plan not found.';

    header('Location: plans.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load current prices
|--------------------------------------------------------------------------
*/

$currentPrices = array();

$priceStmt = $conn->prepare("
    SELECT
        `currency_code`,
        `amount`,
        `is_default`
    FROM plan_prices
    WHERE `plan_id` = ?
      AND `is_active` = 1
");

$priceStmt->bind_param('i', $planId);
$priceStmt->execute();

$priceResult = $priceStmt->get_result();

while ($priceRow = $priceResult->fetch_assoc()) {
    $currentPrices[
        strtoupper(
            (string) $priceRow['currency_code']
        )
    ] = $priceRow;
}

$priceStmt->close();

if (empty($currentPrices)) {
    $currentPrices[
        strtoupper(
            (string) $currentPlan['currency']
        )
    ] = array(
        'currency_code' =>
            strtoupper(
                (string) $currentPlan['currency']
            ),
        'amount' =>
            $currentPlan['price'],
        'is_default' => 1
    );
}

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$planName = isset($_POST['name'])
    ? planEditPost('name')
    : (string) $currentPlan['name'];

$planCode = isset($_POST['code'])
    ? planEditPost('code')
    : (string) $currentPlan['code'];

$description = isset($_POST['description'])
    ? planEditPost('description')
    : (string) $currentPlan['description'];

$billingCycle = isset($_POST['billing_cycle'])
    ? planEditPost('billing_cycle')
    : (string) $currentPlan['billing_cycle'];

$trialDays = isset($_POST['trial_days'])
    ? max(0, (int) $_POST['trial_days'])
    : (int) $currentPlan['trial_days'];

$maxUsers = isset($_POST['max_users'])
    ? planEditPost('max_users')
    : (
        $currentPlan['max_users'] === null
            ? ''
            : (string) $currentPlan['max_users']
    );

$maxBranches = isset($_POST['max_branches'])
    ? planEditPost('max_branches')
    : (
        $currentPlan['max_branches'] === null
            ? ''
            : (string) $currentPlan['max_branches']
    );

$storageLimitMb = isset($_POST['storage_limit_mb'])
    ? planEditPost('storage_limit_mb')
    : (
        $currentPlan['storage_limit_mb'] === null
            ? ''
            : (string) $currentPlan['storage_limit_mb']
    );

$isFeatured = isset($_POST['is_featured'])
    ? 1
    : (
        isset($_SERVER['REQUEST_METHOD']) &&
        strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
            ? 0
            : (int) $currentPlan['is_featured']
    );

$status = isset($_POST['status'])
    ? planEditPost('status')
    : (string) $currentPlan['status'];

$defaultCurrency = isset($_POST['default_currency'])
    ? planEditPost('default_currency')
    : strtoupper(
        (string) $currentPlan['currency']
    );

$priceValues = array();

foreach ($currencies as $currencyCode => $currencyData) {
    if (
        isset($_POST['prices'][$currencyCode]) &&
        !is_array($_POST['prices'][$currencyCode])
    ) {
        $priceValues[$currencyCode] = trim(
            (string) $_POST['prices'][$currencyCode]
        );
    } else {
        $priceValues[$currencyCode] = isset(
            $currentPrices[$currencyCode]
        )
            ? (string) $currentPrices[
                $currencyCode
            ]['amount']
            : '';
    }
}

if (!isset($currencies[$defaultCurrency])) {
    $defaultCurrency = 'USD';
}

if (!isset($billingCycles[$billingCycle])) {
    $billingCycle = 'monthly';
}

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'active';
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

    if ($planName === '') {
        $errorMessage = 'Enter the plan name.';
    } elseif (strlen($planName) > 190) {
        $errorMessage =
            'Plan name must not exceed 190 characters.';
    }

    if ($planCode === '') {
        $planCode = planEditCode($planName);
    } else {
        $planCode = planEditCode($planCode);
    }

    if (
        $errorMessage === '' &&
        $planCode === ''
    ) {
        $errorMessage = 'Enter a valid plan code.';
    }

    if (
        $errorMessage === '' &&
        strlen($planCode) > 120
    ) {
        $errorMessage =
            'Plan code must not exceed 120 characters.';
    }

    $normalisedPrices = array();

    if ($errorMessage === '') {
        foreach (
            $priceValues as
            $currencyCode => $priceValue
        ) {
            $normalised = planEditMoneyValue(
                $priceValue
            );

            if ($normalised === false) {
                $errorMessage =
                    'Enter a valid amount for ' .
                    $currencyCode . '.';
                break;
            }

            if (
                $normalised !== null &&
                $normalised < 0
            ) {
                $errorMessage =
                    'Plan amounts cannot be negative.';
                break;
            }

            $normalisedPrices[$currencyCode] =
                $normalised;
        }
    }

    if (
        $errorMessage === '' &&
        (
            !isset(
                $normalisedPrices[
                    $defaultCurrency
                ]
            ) ||
            $normalisedPrices[
                $defaultCurrency
            ] === null
        )
    ) {
        $errorMessage =
            'Enter the amount for the default currency.';
    }

    if ($errorMessage === '') {
        $duplicateStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM plans
            WHERE LOWER(`code`) = LOWER(?)
              AND `id` <> ?
              AND `deleted_at` IS NULL
        ");

        $duplicateStmt->bind_param(
            'si',
            $planCode,
            $planId
        );

        $duplicateStmt->execute();

        $duplicateRow = $duplicateStmt
            ->get_result()
            ->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'Another plan already uses this code.';
        }
    }

    if ($errorMessage === '') {
        try {
            $conn->begin_transaction();

            $defaultPrice =
                $normalisedPrices[
                    $defaultCurrency
                ];

            $maxUsersValue =
                $maxUsers === ''
                    ? null
                    : max(0, (int) $maxUsers);

            $maxBranchesValue =
                $maxBranches === ''
                    ? null
                    : max(0, (int) $maxBranches);

            $storageLimitValue =
                $storageLimitMb === ''
                    ? null
                    : max(
                        0,
                        (int) $storageLimitMb
                    );

            $updatePlanStmt = $conn->prepare("
                UPDATE plans
                SET
                    `name` = ?,
                    `code` = ?,
                    `description` = ?,
                    `price` = ?,
                    `currency` = ?,
                    `billing_cycle` = ?,
                    `trial_days` = ?,
                    `max_users` = ?,
                    `max_branches` = ?,
                    `storage_limit_mb` = ?,
                    `is_featured` = ?,
                    `status` = ?,
                    `updated_at` = NOW()
                WHERE `id` = ?
                LIMIT 1
            ");

            $updateValues = array(
                $planName,
                $planCode,
                $description,
                $defaultPrice,
                $defaultCurrency,
                $billingCycle,
                $trialDays,
                $maxUsersValue,
                $maxBranchesValue,
                $storageLimitValue,
                $isFeatured,
                $status,
                $planId
            );

            planEditBind(
                $updatePlanStmt,
                'sssdssiiiiisi',
                $updateValues
            );

            $updatePlanStmt->execute();
            $updatePlanStmt->close();

            $deactivateStmt = $conn->prepare("
                UPDATE plan_prices
                SET
                    `is_active` = 0,
                    `is_default` = 0,
                    `updated_at` = NOW()
                WHERE `plan_id` = ?
            ");

            $deactivateStmt->bind_param(
                'i',
                $planId
            );

            $deactivateStmt->execute();
            $deactivateStmt->close();

            $priceUpsertStmt = $conn->prepare("
                INSERT INTO plan_prices (
                    `plan_id`,
                    `currency_code`,
                    `amount`,
                    `is_default`,
                    `is_active`,
                    `created_at`,
                    `updated_at`
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    1,
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    `amount` = VALUES(`amount`),
                    `is_default` = VALUES(`is_default`),
                    `is_active` = 1,
                    `updated_at` = NOW()
            ");

            foreach (
                $normalisedPrices as
                $currencyCode => $amount
            ) {
                if ($amount === null) {
                    continue;
                }

                $isDefault =
                    $currencyCode ===
                    $defaultCurrency
                        ? 1
                        : 0;

                $priceValuesUpdate = array(
                    $planId,
                    $currencyCode,
                    $amount,
                    $isDefault
                );

                planEditBind(
                    $priceUpsertStmt,
                    'isdi',
                    $priceValuesUpdate
                );

                $priceUpsertStmt->execute();
            }

            $priceUpsertStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Subscription plan updated successfully.';

            header(
                'Location: plan-view.php?id=' .
                $planId,
                true,
                303
            );

            exit;
        } catch (Exception $exception) {
            $conn->rollback();

            error_log(
                'Plan update failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to update the plan: ' .
                $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .plan-edit-page {
        max-width: 1120px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .plan-edit-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .plan-edit-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .plan-edit-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .plan-edit-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .plan-edit-button {
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

    .plan-edit-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .plan-edit-alert {
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

    .plan-edit-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(290px, 340px);
        gap: 15px;
        align-items: start;
    }

    .plan-edit-main,
    .plan-edit-side {
        display: grid;
        gap: 15px;
    }

    .plan-edit-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .plan-edit-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .plan-edit-card-icon {
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

    .plan-edit-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .plan-edit-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .plan-edit-card-body {
        padding: 15px;
    }

    .plan-edit-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .plan-edit-required {
        color: #dc2626;
    }

    .plan-edit-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    textarea.plan-edit-control {
        min-height: 110px;
        resize: vertical;
    }

    .plan-edit-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .plan-edit-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .plan-edit-currency-grid {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .plan-edit-currency-box {
        padding: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fafafa;
    }

    .plan-edit-currency-box.primary-market {
        border-color: #c4b5fd;
        background: #faf8ff;
    }

    .plan-edit-currency-header {
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .plan-edit-currency-name {
        color: #111827;
        font-size: 9px;
        font-weight: 800;
    }

    .plan-edit-currency-market {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 7px;
    }

    .plan-edit-currency-code {
        padding: 3px 6px;
        border-radius: 999px;
        background: #ffffff;
        color: #7c3aed;
        font-size: 7px;
        font-weight: 800;
    }

    .plan-edit-price-wrap {
        position: relative;
    }

    .plan-edit-price-symbol {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #6b7280;
        font-size: 10px;
        font-weight: 700;
    }

    .plan-edit-price-input {
        padding-left: 34px;
    }

    .plan-edit-default-currency {
        margin-top: 10px;
        padding: 10px;
        border: 1px solid #ddd6fe;
        border-radius: 9px;
        background: #faf8ff;
    }

    .plan-edit-check {
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

    .plan-edit-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .plan-edit-submit {
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

    .plan-edit-cancel {
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
        .plan-edit-layout {
            grid-template-columns: 1fr;
        }

        .plan-edit-side {
            order: -1;
        }
    }

    @media (max-width: 650px) {
        .plan-edit-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .plan-edit-actions {
            width: 100%;
        }

        .plan-edit-button {
            flex: 1;
        }

        .plan-edit-currency-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="plan-edit-page">

    <div class="plan-edit-header">
        <div>
            <h2 class="plan-edit-title">
                Edit Subscription Plan
            </h2>

            <div class="plan-edit-description">
                Update plan pricing, limits, billing, and availability.
            </div>
        </div>

        <div class="plan-edit-actions">
            <a
                href="plan-view.php?id=<?= (int) $planId; ?>"
                class="plan-edit-button"
            >
                <i class="bi bi-eye"></i>
                View Plan
            </a>

            <a
                href="plans.php"
                class="plan-edit-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Plans
            </a>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="plan-edit-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= planEditEscape($errorMessage); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="plan-edit.php?id=<?= (int) $planId; ?>"
        id="planEditForm"
        autocomplete="off"
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="plan_id"
            value="<?= (int) $planId; ?>"
        >

        <div class="plan-edit-layout">

            <div class="plan-edit-main">

                <section class="plan-edit-card">
                    <div class="plan-edit-card-header">
                        <span class="plan-edit-card-icon">
                            <i class="bi bi-card-list"></i>
                        </span>

                        <div>
                            <h3 class="plan-edit-card-title">
                                Plan Information
                            </h3>

                            <div class="plan-edit-card-subtitle">
                                Name, code, description, and billing cycle
                            </div>
                        </div>
                    </div>

                    <div class="plan-edit-card-body">
                        <div class="row g-3">

                            <div class="col-md-8">
                                <label
                                    class="plan-edit-label"
                                    for="planName"
                                >
                                    Plan Name
                                    <span class="plan-edit-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control plan-edit-control"
                                    id="planName"
                                    name="name"
                                    value="<?= planEditEscape(
                                        $planName
                                    ); ?>"
                                    maxlength="190"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="plan-edit-label"
                                    for="planCode"
                                >
                                    Plan Code
                                </label>

                                <input
                                    type="text"
                                    class="form-control plan-edit-control"
                                    id="planCode"
                                    name="code"
                                    value="<?= planEditEscape(
                                        $planCode
                                    ); ?>"
                                    maxlength="120"
                                >
                            </div>

                            <div class="col-12">
                                <label
                                    class="plan-edit-label"
                                    for="description"
                                >
                                    Description
                                </label>

                                <textarea
                                    class="form-control plan-edit-control"
                                    id="description"
                                    name="description"
                                ><?= planEditEscape(
                                    $description
                                ); ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="plan-edit-label"
                                    for="billingCycle"
                                >
                                    Billing Cycle
                                </label>

                                <select
                                    class="form-select plan-edit-control"
                                    id="billingCycle"
                                    name="billing_cycle"
                                >
                                    <?php foreach (
                                        $billingCycles as
                                        $cycleCode => $cycleLabel
                                    ): ?>
                                        <option
                                            value="<?= planEditEscape(
                                                $cycleCode
                                            ); ?>"
                                            <?= $billingCycle ===
                                                $cycleCode
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= planEditEscape(
                                                $cycleLabel
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="plan-edit-label"
                                    for="trialDays"
                                >
                                    Free Trial Days
                                </label>

                                <input
                                    type="number"
                                    class="form-control plan-edit-control"
                                    id="trialDays"
                                    name="trial_days"
                                    value="<?= (int) $trialDays; ?>"
                                    min="0"
                                    max="365"
                                >
                            </div>

                        </div>
                    </div>
                </section>

                <section class="plan-edit-card">
                    <div class="plan-edit-card-header">
                        <span class="plan-edit-card-icon">
                            <i class="bi bi-currency-dollar"></i>
                        </span>

                        <div>
                            <h3 class="plan-edit-card-title">
                                Multi-Currency Pricing
                            </h3>

                            <div class="plan-edit-card-subtitle">
                                Edit prices for each supported market
                            </div>
                        </div>
                    </div>

                    <div class="plan-edit-card-body">

                        <div class="plan-edit-currency-grid">
                            <?php foreach (
                                $currencies as
                                $currencyCode => $currencyData
                            ): ?>
                                <div
                                    class="plan-edit-currency-box <?= $currencyCode === 'USD'
                                        ? 'primary-market'
                                        : ''; ?>"
                                >
                                    <div class="plan-edit-currency-header">
                                        <span>
                                            <span class="plan-edit-currency-name">
                                                <?= planEditEscape(
                                                    $currencyData['name']
                                                ); ?>
                                            </span>

                                            <span class="plan-edit-currency-market">
                                                <?= planEditEscape(
                                                    $currencyData['market']
                                                ); ?>
                                            </span>
                                        </span>

                                        <span class="plan-edit-currency-code">
                                            <?= planEditEscape(
                                                $currencyCode
                                            ); ?>
                                        </span>
                                    </div>

                                    <div class="plan-edit-price-wrap">
                                        <span class="plan-edit-price-symbol">
                                            <?= planEditEscape(
                                                $currencyData['symbol']
                                            ); ?>
                                        </span>

                                        <input
                                            type="number"
                                            class="form-control plan-edit-control plan-edit-price-input"
                                            name="prices[<?= planEditEscape(
                                                $currencyCode
                                            ); ?>]"
                                            value="<?= planEditEscape(
                                                $priceValues[
                                                    $currencyCode
                                                ]
                                            ); ?>"
                                            min="0"
                                            step="0.01"
                                            placeholder="0.00"
                                            <?= $currencyCode ===
                                                $defaultCurrency
                                                    ? 'required'
                                                    : ''; ?>
                                        >
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="plan-edit-default-currency">
                            <label
                                class="plan-edit-label"
                                for="defaultCurrency"
                            >
                                Default Currency
                            </label>

                            <select
                                class="form-select plan-edit-control"
                                id="defaultCurrency"
                                name="default_currency"
                            >
                                <?php foreach (
                                    $currencies as
                                    $currencyCode => $currencyData
                                ): ?>
                                    <option
                                        value="<?= planEditEscape(
                                            $currencyCode
                                        ); ?>"
                                        <?= $defaultCurrency ===
                                            $currencyCode
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        <?= planEditEscape(
                                            $currencyCode .
                                            ' - ' .
                                            $currencyData['name']
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="plan-edit-help">
                                The selected default amount is stored in the main plans table for reports and compatibility.
                            </div>
                        </div>

                    </div>
                </section>

                <section class="plan-edit-card">
                    <div class="plan-edit-card-header">
                        <span class="plan-edit-card-icon">
                            <i class="bi bi-speedometer2"></i>
                        </span>

                        <div>
                            <h3 class="plan-edit-card-title">
                                Plan Limits
                            </h3>

                            <div class="plan-edit-card-subtitle">
                                Leave blank to make a limit unlimited
                            </div>
                        </div>
                    </div>

                    <div class="plan-edit-card-body">
                        <div class="row g-3">

                            <div class="col-md-4">
                                <label
                                    class="plan-edit-label"
                                    for="maxUsers"
                                >
                                    Maximum Users
                                </label>

                                <input
                                    type="number"
                                    class="form-control plan-edit-control"
                                    id="maxUsers"
                                    name="max_users"
                                    value="<?= planEditEscape(
                                        $maxUsers
                                    ); ?>"
                                    min="0"
                                    placeholder="Unlimited"
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="plan-edit-label"
                                    for="maxBranches"
                                >
                                    Maximum Branches
                                </label>

                                <input
                                    type="number"
                                    class="form-control plan-edit-control"
                                    id="maxBranches"
                                    name="max_branches"
                                    value="<?= planEditEscape(
                                        $maxBranches
                                    ); ?>"
                                    min="0"
                                    placeholder="Unlimited"
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="plan-edit-label"
                                    for="storageLimit"
                                >
                                    Storage Limit (MB)
                                </label>

                                <input
                                    type="number"
                                    class="form-control plan-edit-control"
                                    id="storageLimit"
                                    name="storage_limit_mb"
                                    value="<?= planEditEscape(
                                        $storageLimitMb
                                    ); ?>"
                                    min="0"
                                    placeholder="Unlimited"
                                >
                            </div>

                        </div>
                    </div>
                </section>

            </div>

            <aside class="plan-edit-side">

                <section class="plan-edit-card">
                    <div class="plan-edit-card-header">
                        <span class="plan-edit-card-icon">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <div>
                            <h3 class="plan-edit-card-title">
                                Plan Settings
                            </h3>

                            <div class="plan-edit-card-subtitle">
                                Availability and visibility
                            </div>
                        </div>
                    </div>

                    <div class="plan-edit-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label
                                    class="plan-edit-label"
                                    for="status"
                                >
                                    Status
                                </label>

                                <select
                                    class="form-select plan-edit-control"
                                    id="status"
                                    name="status"
                                >
                                    <?php foreach (
                                        $allowedStatuses as
                                        $statusOption
                                    ): ?>
                                        <option
                                            value="<?= planEditEscape(
                                                $statusOption
                                            ); ?>"
                                            <?= $status ===
                                                $statusOption
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= planEditEscape(
                                                ucwords(
                                                    str_replace(
                                                        '_',
                                                        ' ',
                                                        $statusOption
                                                    )
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="plan-edit-check">
                                    <input
                                        type="checkbox"
                                        name="is_featured"
                                        value="1"
                                        <?= $isFeatured === 1
                                            ? 'checked'
                                            : ''; ?>
                                    >

                                    <span>
                                        <strong>
                                            Featured Plan
                                        </strong>
                                        <br>
                                        Highlight this plan as recommended.
                                    </span>
                                </label>
                            </div>

                        </div>
                    </div>
                </section>

                <div class="plan-edit-submit-card">
                    <button
                        type="submit"
                        class="plan-edit-submit"
                        id="planEditSubmit"
                    >
                        <i class="bi bi-check2-circle"></i>
                        Update Plan
                    </button>

                    <a
                        href="plan-view.php?id=<?= (int) $planId; ?>"
                        class="plan-edit-cancel"
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

    const planName =
        document.getElementById('planName');

    const planCode =
        document.getElementById('planCode');

    let codeEdited =
        planCode &&
        planCode.value.trim() !== '';

    function makeCode(value) {
        return value
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    if (planCode) {
        planCode.addEventListener(
            'input',
            function () {
                codeEdited =
                    planCode.value.trim() !== '';
            }
        );
    }

    if (planName) {
        planName.addEventListener(
            'input',
            function () {
                if (
                    planCode &&
                    !codeEdited
                ) {
                    planCode.value =
                        makeCode(planName.value);
                }
            }
        );
    }

    const defaultCurrency =
        document.getElementById(
            'defaultCurrency'
        );

    function updateRequiredCurrency() {
        const currencyInputs =
            document.querySelectorAll(
                'input[name^="prices["]'
            );

        currencyInputs.forEach(
            function (input) {
                input.required = false;
            }
        );

        if (!defaultCurrency) {
            return;
        }

        const selected =
            defaultCurrency.value;

        const selectedInput =
            document.querySelector(
                'input[name="prices[' +
                selected +
                ']"]'
            );

        if (selectedInput) {
            selectedInput.required = true;
        }
    }

    if (defaultCurrency) {
        defaultCurrency.addEventListener(
            'change',
            updateRequiredCurrency
        );
    }

    updateRequiredCurrency();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
