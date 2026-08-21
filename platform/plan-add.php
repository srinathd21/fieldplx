<?php
/**
 * FieldPlx Platform - Add Subscription Plan
 *
 * File:
 * platform/plan-add.php
 *
 * Supports:
 * - Multiple currency prices
 * - USD-first pricing for the US market
 * - PHP 7.2
 * - MariaDB / MySQLi
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin'
));

$pageTitle = 'Add Subscription Plan - FieldPlx';
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

if (!function_exists('planAddEscape')) {
    function planAddEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('planAddPost')) {
    function planAddPost($key, $default = '')
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

if (!function_exists('planAddTableExists')) {
    function planAddTableExists(
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

if (!function_exists('planAddCode')) {
    function planAddCode($value)
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

if (!function_exists('planAddMoneyValue')) {
    function planAddMoneyValue($value)
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

if (!function_exists('planAddBind')) {
    function planAddBind(
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

if (!planAddTableExists($conn, 'plans')) {
    http_response_code(500);
    exit('The plans table does not exist.');
}

if (!planAddTableExists($conn, 'plan_prices')) {
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

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$errorMessage = '';

$planName = planAddPost('name');
$planCode = planAddPost('code');
$description = planAddPost('description');

$billingCycle = planAddPost(
    'billing_cycle',
    'monthly'
);

$trialDays = isset($_POST['trial_days'])
    ? max(0, (int) $_POST['trial_days'])
    : 14;

$maxUsers = planAddPost('max_users');
$maxBranches = planAddPost('max_branches');
$storageLimitMb = planAddPost('storage_limit_mb');

$isFeatured = !empty($_POST['is_featured'])
    ? 1
    : 0;

$status = planAddPost('status', 'active');

$defaultCurrency = planAddPost(
    'default_currency',
    'USD'
);

$priceValues = array();

foreach ($currencies as $currencyCode => $currencyData) {
    $priceValues[$currencyCode] = isset(
        $_POST['prices'][$currencyCode]
    ) && !is_array($_POST['prices'][$currencyCode])
        ? trim(
            (string) $_POST['prices'][$currencyCode]
        )
        : '';
}

if (!isset($currencies[$defaultCurrency])) {
    $defaultCurrency = 'USD';
}

if (!isset($billingCycles[$billingCycle])) {
    $billingCycle = 'monthly';
}

$allowedStatuses = array(
    'active',
    'inactive',
    'draft',
    'archived'
);

if (!in_array($status, $allowedStatuses, true)) {
    $status = 'active';
}

/*
|--------------------------------------------------------------------------
| Process form
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
        $planCode = planAddCode($planName);
    } else {
        $planCode = planAddCode($planCode);
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
            $normalised = planAddMoneyValue(
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
              AND `deleted_at` IS NULL
        ");

        $duplicateStmt->bind_param(
            's',
            $planCode
        );

        $duplicateStmt->execute();

        $duplicateRow = $duplicateStmt
            ->get_result()
            ->fetch_assoc();

        $duplicateStmt->close();

        if (!empty($duplicateRow['total'])) {
            $errorMessage =
                'A plan with this code already exists.';
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

            $insertPlanSql = "
                INSERT INTO plans (
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
                    `status`,
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
            ";

            $insertPlanStmt = $conn->prepare(
                $insertPlanSql
            );

            $planValues = array(
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
                $status
            );

            $planTypes = 'sssdssiiiiis';

            planAddBind(
                $insertPlanStmt,
                $planTypes,
                $planValues
            );

            $insertPlanStmt->execute();

            $planId = (int)
                $insertPlanStmt->insert_id;

            $insertPlanStmt->close();

            $priceInsertStmt = $conn->prepare("
                INSERT INTO plan_prices (
                    `plan_id`,
                    `currency_code`,
                    `amount`,
                    `is_default`,
                    `is_active`,
                    `created_at`
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    1,
                    NOW()
                )
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

                $priceValuesInsert = array(
                    $planId,
                    $currencyCode,
                    $amount,
                    $isDefault
                );

                planAddBind(
                    $priceInsertStmt,
                    'isdi',
                    $priceValuesInsert
                );

                $priceInsertStmt->execute();
            }

            $priceInsertStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'Subscription plan created successfully.';

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
                'Plan creation failed: ' .
                $exception->getMessage()
            );

            $errorMessage =
                'Unable to create the plan: ' .
                $exception->getMessage();
        }
    }
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .plan-add-page {
        max-width: 1120px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .plan-add-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .plan-add-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .plan-add-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .plan-add-back {
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

    .plan-add-back:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .plan-add-alert {
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

    .plan-add-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1fr)
            minmax(290px, 340px);
        gap: 15px;
        align-items: start;
    }

    .plan-add-main,
    .plan-add-side {
        display: grid;
        gap: 15px;
    }

    .plan-add-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .plan-add-card-header {
        min-height: 53px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .plan-add-card-icon {
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

    .plan-add-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .plan-add-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .plan-add-card-body {
        padding: 15px;
    }

    .plan-add-label {
        margin-bottom: 6px;
        color: #374151;
        font-size: 9px;
        font-weight: 700;
    }

    .plan-add-required {
        color: #dc2626;
    }

    .plan-add-control {
        min-height: 39px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        box-shadow: none;
        color: #374151;
        font-size: 10px;
    }

    textarea.plan-add-control {
        min-height: 110px;
        resize: vertical;
    }

    .plan-add-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124, 58, 237, 0.08);
    }

    .plan-add-help {
        margin-top: 5px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .plan-add-currency-grid {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .plan-add-currency-box {
        padding: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fafafa;
    }

    .plan-add-currency-box.primary-market {
        border-color: #c4b5fd;
        background: #faf8ff;
    }

    .plan-add-currency-header {
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .plan-add-currency-name {
        color: #111827;
        font-size: 9px;
        font-weight: 800;
    }

    .plan-add-currency-market {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 7px;
    }

    .plan-add-currency-code {
        padding: 3px 6px;
        border-radius: 999px;
        background: #ffffff;
        color: #7c3aed;
        font-size: 7px;
        font-weight: 800;
    }

    .plan-add-price-wrap {
        position: relative;
    }

    .plan-add-price-symbol {
        position: absolute;
        top: 50%;
        left: 11px;
        transform: translateY(-50%);
        color: #6b7280;
        font-size: 10px;
        font-weight: 700;
    }

    .plan-add-price-input {
        padding-left: 34px;
    }

    .plan-add-default-currency {
        margin-top: 10px;
        padding: 10px;
        border: 1px solid #ddd6fe;
        border-radius: 9px;
        background: #faf8ff;
    }

    .plan-add-check {
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

    .plan-add-submit-card {
        padding: 13px;
        display: grid;
        gap: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .plan-add-submit {
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

    .plan-add-cancel {
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
        .plan-add-layout {
            grid-template-columns: 1fr;
        }

        .plan-add-side {
            order: -1;
        }
    }

    @media (max-width: 650px) {
        .plan-add-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .plan-add-currency-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="plan-add-page">

    <div class="plan-add-header">
        <div>
            <h2 class="plan-add-title">
                Add Subscription Plan
            </h2>

            <div class="plan-add-description">
                Create a plan with USD-first multi-currency pricing.
            </div>
        </div>

        <a
            href="plans.php"
            class="plan-add-back"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Plans
        </a>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="plan-add-alert">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= planAddEscape($errorMessage); ?>
            </span>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="plan-add.php"
        id="planAddForm"
        autocomplete="off"
    >
        <?php csrfField(); ?>

        <div class="plan-add-layout">

            <div class="plan-add-main">

                <section class="plan-add-card">
                    <div class="plan-add-card-header">
                        <span class="plan-add-card-icon">
                            <i class="bi bi-card-list"></i>
                        </span>

                        <div>
                            <h3 class="plan-add-card-title">
                                Plan Information
                            </h3>

                            <div class="plan-add-card-subtitle">
                                Name, code, description, and billing cycle
                            </div>
                        </div>
                    </div>

                    <div class="plan-add-card-body">
                        <div class="row g-3">

                            <div class="col-md-8">
                                <label
                                    class="plan-add-label"
                                    for="planName"
                                >
                                    Plan Name
                                    <span class="plan-add-required">*</span>
                                </label>

                                <input
                                    type="text"
                                    class="form-control plan-add-control"
                                    id="planName"
                                    name="name"
                                    value="<?= planAddEscape(
                                        $planName
                                    ); ?>"
                                    maxlength="190"
                                    placeholder="Example: Professional"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="plan-add-label"
                                    for="planCode"
                                >
                                    Plan Code
                                </label>

                                <input
                                    type="text"
                                    class="form-control plan-add-control"
                                    id="planCode"
                                    name="code"
                                    value="<?= planAddEscape(
                                        $planCode
                                    ); ?>"
                                    maxlength="120"
                                    placeholder="Auto generated"
                                >
                            </div>

                            <div class="col-12">
                                <label
                                    class="plan-add-label"
                                    for="description"
                                >
                                    Description
                                </label>

                                <textarea
                                    class="form-control plan-add-control"
                                    id="description"
                                    name="description"
                                    placeholder="Describe the plan and its target customers"
                                ><?= planAddEscape(
                                    $description
                                ); ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="plan-add-label"
                                    for="billingCycle"
                                >
                                    Billing Cycle
                                </label>

                                <select
                                    class="form-select plan-add-control"
                                    id="billingCycle"
                                    name="billing_cycle"
                                >
                                    <?php foreach (
                                        $billingCycles as
                                        $cycleCode => $cycleLabel
                                    ): ?>
                                        <option
                                            value="<?= planAddEscape(
                                                $cycleCode
                                            ); ?>"
                                            <?= $billingCycle ===
                                                $cycleCode
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= planAddEscape(
                                                $cycleLabel
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label
                                    class="plan-add-label"
                                    for="trialDays"
                                >
                                    Free Trial Days
                                </label>

                                <input
                                    type="number"
                                    class="form-control plan-add-control"
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

                <section class="plan-add-card">
                    <div class="plan-add-card-header">
                        <span class="plan-add-card-icon">
                            <i class="bi bi-currency-dollar"></i>
                        </span>

                        <div>
                            <h3 class="plan-add-card-title">
                                Multi-Currency Pricing
                            </h3>

                            <div class="plan-add-card-subtitle">
                                USD is highlighted for the main US market
                            </div>
                        </div>
                    </div>

                    <div class="plan-add-card-body">

                        <div class="plan-add-currency-grid">
                            <?php foreach (
                                $currencies as
                                $currencyCode => $currencyData
                            ): ?>
                                <div
                                    class="plan-add-currency-box <?= $currencyCode === 'USD'
                                        ? 'primary-market'
                                        : ''; ?>"
                                >
                                    <div class="plan-add-currency-header">
                                        <span>
                                            <span class="plan-add-currency-name">
                                                <?= planAddEscape(
                                                    $currencyData['name']
                                                ); ?>
                                            </span>

                                            <span class="plan-add-currency-market">
                                                <?= planAddEscape(
                                                    $currencyData['market']
                                                ); ?>
                                            </span>
                                        </span>

                                        <span class="plan-add-currency-code">
                                            <?= planAddEscape(
                                                $currencyCode
                                            ); ?>
                                        </span>
                                    </div>

                                    <div class="plan-add-price-wrap">
                                        <span class="plan-add-price-symbol">
                                            <?= planAddEscape(
                                                $currencyData['symbol']
                                            ); ?>
                                        </span>

                                        <input
                                            type="number"
                                            class="form-control plan-add-control plan-add-price-input"
                                            name="prices[<?= planAddEscape(
                                                $currencyCode
                                            ); ?>]"
                                            value="<?= planAddEscape(
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

                        <div class="plan-add-default-currency">
                            <label
                                class="plan-add-label"
                                for="defaultCurrency"
                            >
                                Default Currency
                            </label>

                            <select
                                class="form-select plan-add-control"
                                id="defaultCurrency"
                                name="default_currency"
                            >
                                <?php foreach (
                                    $currencies as
                                    $currencyCode => $currencyData
                                ): ?>
                                    <option
                                        value="<?= planAddEscape(
                                            $currencyCode
                                        ); ?>"
                                        <?= $defaultCurrency ===
                                            $currencyCode
                                                ? 'selected'
                                                : ''; ?>
                                    >
                                        <?= planAddEscape(
                                            $currencyCode .
                                            ' - ' .
                                            $currencyData['name']
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="plan-add-help">
                                The default price is also stored in the main plans table for reports and compatibility.
                            </div>
                        </div>

                    </div>
                </section>

                <section class="plan-add-card">
                    <div class="plan-add-card-header">
                        <span class="plan-add-card-icon">
                            <i class="bi bi-speedometer2"></i>
                        </span>

                        <div>
                            <h3 class="plan-add-card-title">
                                Plan Limits
                            </h3>

                            <div class="plan-add-card-subtitle">
                                Leave blank to make a limit unlimited
                            </div>
                        </div>
                    </div>

                    <div class="plan-add-card-body">
                        <div class="row g-3">

                            <div class="col-md-4">
                                <label
                                    class="plan-add-label"
                                    for="maxUsers"
                                >
                                    Maximum Users
                                </label>

                                <input
                                    type="number"
                                    class="form-control plan-add-control"
                                    id="maxUsers"
                                    name="max_users"
                                    value="<?= planAddEscape(
                                        $maxUsers
                                    ); ?>"
                                    min="0"
                                    placeholder="Unlimited"
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="plan-add-label"
                                    for="maxBranches"
                                >
                                    Maximum Branches
                                </label>

                                <input
                                    type="number"
                                    class="form-control plan-add-control"
                                    id="maxBranches"
                                    name="max_branches"
                                    value="<?= planAddEscape(
                                        $maxBranches
                                    ); ?>"
                                    min="0"
                                    placeholder="Unlimited"
                                >
                            </div>

                            <div class="col-md-4">
                                <label
                                    class="plan-add-label"
                                    for="storageLimit"
                                >
                                    Storage Limit (MB)
                                </label>

                                <input
                                    type="number"
                                    class="form-control plan-add-control"
                                    id="storageLimit"
                                    name="storage_limit_mb"
                                    value="<?= planAddEscape(
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

            <aside class="plan-add-side">

                <section class="plan-add-card">
                    <div class="plan-add-card-header">
                        <span class="plan-add-card-icon">
                            <i class="bi bi-sliders"></i>
                        </span>

                        <div>
                            <h3 class="plan-add-card-title">
                                Plan Settings
                            </h3>

                            <div class="plan-add-card-subtitle">
                                Availability and visibility
                            </div>
                        </div>
                    </div>

                    <div class="plan-add-card-body">
                        <div class="row g-3">

                            <div class="col-12">
                                <label
                                    class="plan-add-label"
                                    for="status"
                                >
                                    Status
                                </label>

                                <select
                                    class="form-select plan-add-control"
                                    id="status"
                                    name="status"
                                >
                                    <?php foreach (
                                        $allowedStatuses as
                                        $statusOption
                                    ): ?>
                                        <option
                                            value="<?= planAddEscape(
                                                $statusOption
                                            ); ?>"
                                            <?= $status ===
                                                $statusOption
                                                    ? 'selected'
                                                    : ''; ?>
                                        >
                                            <?= planAddEscape(
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
                                <label class="plan-add-check">
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

                <div class="plan-add-submit-card">
                    <button
                        type="submit"
                        class="plan-add-submit"
                        id="planAddSubmit"
                    >
                        <i class="bi bi-check2-circle"></i>
                        Create Plan
                    </button>

                    <a
                        href="plans.php"
                        class="plan-add-cancel"
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
