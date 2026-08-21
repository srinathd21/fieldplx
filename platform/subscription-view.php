<?php
/**
 * FieldPlx Platform - View Subscription
 *
 * File:
 * platform/subscription-view.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 * - tenants
 * - plans
 * - subscriptions
 */

require_once __DIR__ . '/includes/auth.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin',
    'support_admin',
    'platform_read_only'
));

$pageTitle = 'Subscription Details - FieldPlx';
$activePage = 'subscriptions';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('subscriptionViewEscape')) {
    function subscriptionViewEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('subscriptionViewTableExists')) {
    function subscriptionViewTableExists(
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

if (!function_exists('subscriptionViewColumns')) {
    function subscriptionViewColumns(
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

if (!function_exists('subscriptionViewFirstColumn')) {
    function subscriptionViewFirstColumn(
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

if (!function_exists('subscriptionViewLabel')) {
    function subscriptionViewLabel($value)
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

if (!function_exists('subscriptionViewDate')) {
    function subscriptionViewDate(
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

if (!function_exists('subscriptionViewStatusClass')) {
    function subscriptionViewStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
                return 'success';

            case 'trial':
                return 'info';

            case 'past_due':
                return 'warning';

            case 'suspended':
            case 'cancelled':
            case 'expired':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('subscriptionViewCurrencySymbol')) {
    function subscriptionViewCurrencySymbol($currency)
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

if (!function_exists('subscriptionViewMoney')) {
    function subscriptionViewMoney(
        $amount,
        $currency
    ) {
        $amount = is_numeric($amount)
            ? (float) $amount
            : 0;

        return subscriptionViewCurrencySymbol(
            $currency
        ) . number_format($amount, 2);
    }
}

if (!function_exists('subscriptionViewDaysRemaining')) {
    function subscriptionViewDaysRemaining($value)
    {
        if (empty($value)) {
            return null;
        }

        $end = strtotime((string) $value);

        if ($end === false) {
            return null;
        }

        $today = strtotime(date('Y-m-d'));
        $endDay = strtotime(date('Y-m-d', $end));

        return (int) floor(
            ($endDay - $today) / 86400
        );
    }
}

/*
|--------------------------------------------------------------------------
| Verify tables
|--------------------------------------------------------------------------
*/

$requiredTables = array(
    'subscriptions',
    'tenants',
    'plans'
);

foreach ($requiredTables as $tableName) {
    if (
        !subscriptionViewTableExists(
            $conn,
            $tableName
        )
    ) {
        http_response_code(500);

        exit(
            'The ' .
            subscriptionViewEscape($tableName) .
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
    subscriptionViewColumns(
        $conn,
        'subscriptions'
    );

$tenantColumns =
    subscriptionViewColumns(
        $conn,
        'tenants'
    );

$planColumns =
    subscriptionViewColumns(
        $conn,
        'plans'
    );

$subscriptionIdColumn =
    subscriptionViewFirstColumn(
        $subscriptionColumns,
        array('id', 'subscription_id')
    );

$subscriptionTenantColumn =
    subscriptionViewFirstColumn(
        $subscriptionColumns,
        array('tenant_id')
    );

$subscriptionPlanColumn =
    subscriptionViewFirstColumn(
        $subscriptionColumns,
        array('plan_id')
    );

$tenantIdColumn =
    subscriptionViewFirstColumn(
        $tenantColumns,
        array('id', 'tenant_id')
    );

$tenantNameColumn =
    subscriptionViewFirstColumn(
        $tenantColumns,
        array(
            'business_name',
            'name',
            'tenant_name',
            'company_name'
        )
    );

$tenantCodeColumn =
    subscriptionViewFirstColumn(
        $tenantColumns,
        array(
            'tenant_code',
            'business_code',
            'code',
            'slug'
        )
    );

$tenantEmailColumn =
    subscriptionViewFirstColumn(
        $tenantColumns,
        array(
            'email',
            'business_email',
            'contact_email'
        )
    );

$tenantPhoneColumn =
    subscriptionViewFirstColumn(
        $tenantColumns,
        array(
            'phone',
            'mobile',
            'contact_phone'
        )
    );

$tenantStatusColumn =
    subscriptionViewFirstColumn(
        $tenantColumns,
        array('status')
    );

$planIdColumn =
    subscriptionViewFirstColumn(
        $planColumns,
        array('id', 'plan_id')
    );

$planNameColumn =
    subscriptionViewFirstColumn(
        $planColumns,
        array('name', 'plan_name')
    );

$planCodeColumn =
    subscriptionViewFirstColumn(
        $planColumns,
        array('code', 'plan_code')
    );

$planDescriptionColumn =
    subscriptionViewFirstColumn(
        $planColumns,
        array('description', 'notes')
    );

if (
    $subscriptionIdColumn === '' ||
    $subscriptionTenantColumn === '' ||
    $subscriptionPlanColumn === '' ||
    $tenantIdColumn === '' ||
    $tenantNameColumn === '' ||
    $planIdColumn === '' ||
    $planNameColumn === ''
) {
    http_response_code(500);

    exit(
        'Required subscription, tenant, or plan columns are missing.'
    );
}

/*
|--------------------------------------------------------------------------
| Load subscription
|--------------------------------------------------------------------------
*/

$subscriptionId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($subscriptionId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid subscription.';

    header('Location: subscriptions.php');
    exit;
}

$select = array(
    "s.`{$subscriptionIdColumn}` AS subscription_id",
    "s.`{$subscriptionTenantColumn}` AS tenant_id",
    "s.`{$subscriptionPlanColumn}` AS plan_id",
    "t.`{$tenantNameColumn}` AS tenant_name",
    "p.`{$planNameColumn}` AS plan_name"
);

$select[] =
    isset($subscriptionColumns['reference_no'])
        ? "s.`reference_no` AS reference_no"
        : "'' AS reference_no";

$select[] =
    isset($subscriptionColumns['status'])
        ? "s.`status` AS subscription_status"
        : "'active' AS subscription_status";

$select[] =
    isset($subscriptionColumns['starts_at'])
        ? "s.`starts_at` AS starts_at"
        : "NULL AS starts_at";

$select[] =
    isset($subscriptionColumns['ends_at'])
        ? "s.`ends_at` AS ends_at"
        : "NULL AS ends_at";

$select[] =
    isset($subscriptionColumns['trial_ends_at'])
        ? "s.`trial_ends_at` AS trial_ends_at"
        : "NULL AS trial_ends_at";

$select[] =
    isset($subscriptionColumns['amount'])
        ? "s.`amount` AS amount"
        : "0 AS amount";

$select[] =
    isset($subscriptionColumns['currency'])
        ? "s.`currency` AS currency"
        : "'USD' AS currency";

$select[] =
    isset($subscriptionColumns['billing_cycle'])
        ? "s.`billing_cycle` AS billing_cycle"
        : "'monthly' AS billing_cycle";

$select[] =
    isset($subscriptionColumns['auto_renew'])
        ? "s.`auto_renew` AS auto_renew"
        : "0 AS auto_renew";

$select[] =
    isset($subscriptionColumns['notes'])
        ? "s.`notes` AS notes"
        : "'' AS notes";

$select[] =
    isset($subscriptionColumns['created_at'])
        ? "s.`created_at` AS created_at"
        : "NULL AS created_at";

$select[] =
    isset($subscriptionColumns['updated_at'])
        ? "s.`updated_at` AS updated_at"
        : "NULL AS updated_at";

$select[] =
    $tenantCodeColumn !== ''
        ? "t.`{$tenantCodeColumn}` AS tenant_code"
        : "'' AS tenant_code";

$select[] =
    $tenantEmailColumn !== ''
        ? "t.`{$tenantEmailColumn}` AS tenant_email"
        : "'' AS tenant_email";

$select[] =
    $tenantPhoneColumn !== ''
        ? "t.`{$tenantPhoneColumn}` AS tenant_phone"
        : "'' AS tenant_phone";

$select[] =
    $tenantStatusColumn !== ''
        ? "t.`{$tenantStatusColumn}` AS tenant_status"
        : "'' AS tenant_status";

$select[] =
    $planCodeColumn !== ''
        ? "p.`{$planCodeColumn}` AS plan_code"
        : "'' AS plan_code";

$select[] =
    $planDescriptionColumn !== ''
        ? "p.`{$planDescriptionColumn}` AS plan_description"
        : "'' AS plan_description";

$sql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM subscriptions s
    INNER JOIN tenants t
        ON t.`{$tenantIdColumn}` =
           s.`{$subscriptionTenantColumn}`
    INNER JOIN plans p
        ON p.`{$planIdColumn}` =
           s.`{$subscriptionPlanColumn}`
    WHERE s.`{$subscriptionIdColumn}` = ?
";

if (isset($subscriptionColumns['deleted_at'])) {
    $sql .= "
        AND s.`deleted_at` IS NULL
    ";
}

$sql .= " LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $subscriptionId);
$stmt->execute();

$subscription = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$subscription) {
    $_SESSION['platform_error_message'] =
        'Subscription not found.';

    header('Location: subscriptions.php');
    exit;
}

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

$status = strtolower(
    trim(
        (string)
        $subscription['subscription_status']
    )
);

$daysRemaining =
    subscriptionViewDaysRemaining(
        $subscription['ends_at']
    );

$trialDaysRemaining =
    subscriptionViewDaysRemaining(
        $subscription['trial_ends_at']
    );

$canEdit = hasPlatformRole(array(
    'super_admin',
    'platform_admin',
    'billing_admin'
));

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .subscription-view-page {
        max-width: 1100px;
        margin: 0 auto;
        display: grid;
        gap: 15px;
    }

    .subscription-view-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .subscription-view-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .subscription-view-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .subscription-view-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .subscription-view-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .subscription-view-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .subscription-view-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .subscription-view-button {
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

    .subscription-view-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .subscription-view-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .subscription-view-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .subscription-view-hero {
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 17px;
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

    .subscription-view-hero-icon {
        width: 78px;
        height: 78px;
        flex: 0 0 78px;
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

    .subscription-view-hero-content {
        min-width: 0;
        flex: 1;
    }

    .subscription-view-name {
        margin: 0;
        color: #111827;
        font-size: 21px;
        font-weight: 800;
    }

    .subscription-view-plan {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .subscription-view-badges {
        margin-top: 9px;
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .subscription-view-status,
    .subscription-view-reference {
        padding: 5px 8px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .subscription-view-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .subscription-view-status.info {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .subscription-view-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .subscription-view-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .subscription-view-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .subscription-view-reference {
        background: #ede9fe;
        color: #6d28d9;
    }

    .subscription-view-summary {
        display: grid;
        grid-template-columns:
            repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .subscription-view-summary-card {
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

    .subscription-view-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        font-size: 14px;
    }

    .subscription-view-summary-icon.amount {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .subscription-view-summary-icon.start {
        background: #ecfdf5;
        color: #059669;
    }

    .subscription-view-summary-icon.end {
        background: #fff7ed;
        color: #d97706;
    }

    .subscription-view-summary-icon.renew {
        background: #eff6ff;
        color: #2563eb;
    }

    .subscription-view-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .subscription-view-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 14px;
        font-weight: 800;
    }

    .subscription-view-grid {
        display: grid;
        grid-template-columns:
            minmax(0, 1.35fr)
            minmax(290px, 0.8fr);
        gap: 15px;
        align-items: start;
    }

    .subscription-view-main,
    .subscription-view-side {
        display: grid;
        gap: 15px;
    }

    .subscription-view-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .subscription-view-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #eef0f3;
    }

    .subscription-view-card-icon {
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

    .subscription-view-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .subscription-view-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .subscription-view-card-body {
        padding: 15px;
    }

    .subscription-view-details {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 11px;
    }

    .subscription-view-detail {
        padding: 11px 12px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .subscription-view-detail-label {
        display: block;
        color: #9ca3af;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.35px;
        text-transform: uppercase;
    }

    .subscription-view-detail-value {
        margin-top: 4px;
        display: block;
        color: #374151;
        font-size: 10px;
        font-weight: 700;
        word-break: break-word;
    }

    .subscription-view-description-box {
        padding: 13px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
        color: #4b5563;
        font-size: 9px;
        line-height: 1.65;
    }

    .subscription-view-period {
        display: grid;
        gap: 10px;
    }

    .subscription-view-period-row {
        padding: 11px 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .subscription-view-period-label {
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .subscription-view-period-value {
        color: #111827;
        font-size: 9px;
        font-weight: 800;
        text-align: right;
    }

    .subscription-view-period-note {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 7px;
        font-weight: 600;
    }

    .subscription-view-quick-links {
        display: grid;
        gap: 8px;
    }

    .subscription-view-quick-link {
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

    .subscription-view-quick-link:hover {
        border-color: #c4b5fd;
        background: #faf8ff;
        color: #7c3aed;
    }

    @media (max-width: 900px) {
        .subscription-view-grid {
            grid-template-columns: 1fr;
        }

        .subscription-view-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 650px) {
        .subscription-view-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .subscription-view-actions {
            width: 100%;
        }

        .subscription-view-button {
            flex: 1;
        }

        .subscription-view-hero {
            align-items: flex-start;
            flex-direction: column;
        }

        .subscription-view-details {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 430px) {
        .subscription-view-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="subscription-view-page">

    <?php if ($successMessage !== ''): ?>
        <div class="subscription-view-alert success">
            <i class="bi bi-check-circle"></i>
            <span>
                <?= subscriptionViewEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="subscription-view-alert danger">
            <i class="bi bi-exclamation-circle"></i>
            <span>
                <?= subscriptionViewEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="subscription-view-header">
        <div>
            <h2 class="subscription-view-title">
                Subscription Details
            </h2>

            <div class="subscription-view-description">
                Review tenant, plan, billing, renewal, trial, and expiry information.
            </div>
        </div>

        <div class="subscription-view-actions">
            <a
                href="subscriptions.php"
                class="subscription-view-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Subscriptions
            </a>

            <?php if ($canEdit): ?>
                <a
                    href="subscription-edit.php?id=<?= (int) $subscriptionId; ?>"
                    class="subscription-view-button primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit Subscription
                </a>
            <?php endif; ?>
        </div>
    </div>

    <section class="subscription-view-hero">
        <div class="subscription-view-hero-icon">
            <i class="bi bi-credit-card"></i>
        </div>

        <div class="subscription-view-hero-content">
            <h1 class="subscription-view-name">
                <?= subscriptionViewEscape(
                    $subscription['tenant_name']
                ); ?>
            </h1>

            <div class="subscription-view-plan">
                <?= subscriptionViewEscape(
                    $subscription['plan_name']
                ); ?>

                <?php if (
                    !empty($subscription['plan_code'])
                ): ?>
                    · <?= subscriptionViewEscape(
                        $subscription['plan_code']
                    ); ?>
                <?php endif; ?>
            </div>

            <div class="subscription-view-badges">
                <span
                    class="subscription-view-status <?= subscriptionViewEscape(
                        subscriptionViewStatusClass(
                            $status
                        )
                    ); ?>"
                >
                    <?= subscriptionViewEscape(
                        subscriptionViewLabel($status)
                    ); ?>
                </span>

                <?php if (
                    !empty(
                        $subscription['reference_no']
                    )
                ): ?>
                    <span class="subscription-view-reference">
                        <i class="bi bi-hash"></i>

                        <?= subscriptionViewEscape(
                            $subscription['reference_no']
                        ); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="subscription-view-summary">

        <div class="subscription-view-summary-card">
            <span class="subscription-view-summary-icon amount">
                <i class="bi bi-cash-stack"></i>
            </span>

            <span>
                <span class="subscription-view-summary-label">
                    Subscription Amount
                </span>

                <span class="subscription-view-summary-value">
                    <?= subscriptionViewEscape(
                        subscriptionViewMoney(
                            $subscription['amount'],
                            $subscription['currency']
                        )
                    ); ?>
                </span>
            </span>
        </div>

        <div class="subscription-view-summary-card">
            <span class="subscription-view-summary-icon start">
                <i class="bi bi-calendar-check"></i>
            </span>

            <span>
                <span class="subscription-view-summary-label">
                    Start Date
                </span>

                <span class="subscription-view-summary-value">
                    <?= subscriptionViewEscape(
                        subscriptionViewDate(
                            $subscription['starts_at']
                        )
                    ); ?>
                </span>
            </span>
        </div>

        <div class="subscription-view-summary-card">
            <span class="subscription-view-summary-icon end">
                <i class="bi bi-calendar-x"></i>
            </span>

            <span>
                <span class="subscription-view-summary-label">
                    End Date
                </span>

                <span class="subscription-view-summary-value">
                    <?= subscriptionViewEscape(
                        subscriptionViewDate(
                            $subscription['ends_at']
                        )
                    ); ?>
                </span>
            </span>
        </div>

        <div class="subscription-view-summary-card">
            <span class="subscription-view-summary-icon renew">
                <i class="bi bi-arrow-repeat"></i>
            </span>

            <span>
                <span class="subscription-view-summary-label">
                    Auto Renew
                </span>

                <span class="subscription-view-summary-value">
                    <?= !empty(
                        $subscription['auto_renew']
                    )
                        ? 'Enabled'
                        : 'Disabled'; ?>
                </span>
            </span>
        </div>

    </div>

    <div class="subscription-view-grid">

        <div class="subscription-view-main">

            <section class="subscription-view-card">
                <div class="subscription-view-card-header">
                    <span class="subscription-view-card-icon">
                        <i class="bi bi-buildings"></i>
                    </span>

                    <div>
                        <h3 class="subscription-view-card-title">
                            Tenant Information
                        </h3>

                        <div class="subscription-view-card-subtitle">
                            Customer account linked to this subscription
                        </div>
                    </div>
                </div>

                <div class="subscription-view-card-body">
                    <div class="subscription-view-details">
                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Tenant Name
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    $subscription['tenant_name']
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Tenant Code
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    !empty(
                                        $subscription['tenant_code']
                                    )
                                        ? $subscription['tenant_code']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Email
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    !empty(
                                        $subscription['tenant_email']
                                    )
                                        ? $subscription['tenant_email']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Phone
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    !empty(
                                        $subscription['tenant_phone']
                                    )
                                        ? $subscription['tenant_phone']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Tenant Status
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    !empty(
                                        $subscription['tenant_status']
                                    )
                                        ? subscriptionViewLabel(
                                            $subscription['tenant_status']
                                        )
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Tenant ID
                            </span>

                            <span class="subscription-view-detail-value">
                                #<?= (int) $subscription['tenant_id']; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="subscription-view-card">
                <div class="subscription-view-card-header">
                    <span class="subscription-view-card-icon">
                        <i class="bi bi-card-list"></i>
                    </span>

                    <div>
                        <h3 class="subscription-view-card-title">
                            Plan Information
                        </h3>

                        <div class="subscription-view-card-subtitle">
                            Subscription plan and billing details
                        </div>
                    </div>
                </div>

                <div class="subscription-view-card-body">
                    <div class="subscription-view-details">
                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Plan Name
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    $subscription['plan_name']
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Plan Code
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    !empty(
                                        $subscription['plan_code']
                                    )
                                        ? $subscription['plan_code']
                                        : '—'
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Billing Cycle
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    subscriptionViewLabel(
                                        $subscription['billing_cycle']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Currency
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    strtoupper(
                                        $subscription['currency']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Amount
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    subscriptionViewMoney(
                                        $subscription['amount'],
                                        $subscription['currency']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Plan ID
                            </span>

                            <span class="subscription-view-detail-value">
                                #<?= (int) $subscription['plan_id']; ?>
                            </span>
                        </div>
                    </div>

                    <?php if (
                        !empty(
                            $subscription['plan_description']
                        )
                    ): ?>
                        <div
                            class="subscription-view-description-box"
                            style="margin-top:11px;"
                        >
                            <?= nl2br(
                                subscriptionViewEscape(
                                    $subscription['plan_description']
                                )
                            ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="subscription-view-card">
                <div class="subscription-view-card-header">
                    <span class="subscription-view-card-icon">
                        <i class="bi bi-journal-text"></i>
                    </span>

                    <div>
                        <h3 class="subscription-view-card-title">
                            Internal Notes
                        </h3>

                        <div class="subscription-view-card-subtitle">
                            Billing or account notes
                        </div>
                    </div>
                </div>

                <div class="subscription-view-card-body">
                    <div class="subscription-view-description-box">
                        <?= nl2br(
                            subscriptionViewEscape(
                                !empty(
                                    $subscription['notes']
                                )
                                    ? $subscription['notes']
                                    : 'No internal notes have been added.'
                            )
                        ); ?>
                    </div>
                </div>
            </section>

        </div>

        <aside class="subscription-view-side">

            <section class="subscription-view-card">
                <div class="subscription-view-card-header">
                    <span class="subscription-view-card-icon">
                        <i class="bi bi-calendar3"></i>
                    </span>

                    <div>
                        <h3 class="subscription-view-card-title">
                            Subscription Period
                        </h3>

                        <div class="subscription-view-card-subtitle">
                            Start, trial, and expiry dates
                        </div>
                    </div>
                </div>

                <div class="subscription-view-card-body">
                    <div class="subscription-view-period">
                        <div class="subscription-view-period-row">
                            <span class="subscription-view-period-label">
                                Start Date
                            </span>

                            <span class="subscription-view-period-value">
                                <?= subscriptionViewEscape(
                                    subscriptionViewDate(
                                        $subscription['starts_at']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-period-row">
                            <span class="subscription-view-period-label">
                                Trial Ends
                            </span>

                            <span class="subscription-view-period-value">
                                <?= subscriptionViewEscape(
                                    subscriptionViewDate(
                                        $subscription['trial_ends_at']
                                    )
                                ); ?>

                                <?php if (
                                    $trialDaysRemaining !== null &&
                                    $trialDaysRemaining >= 0
                                ): ?>
                                    <span class="subscription-view-period-note">
                                        <?= (int) $trialDaysRemaining; ?>
                                        day<?= $trialDaysRemaining === 1
                                            ? ''
                                            : 's'; ?> remaining
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="subscription-view-period-row">
                            <span class="subscription-view-period-label">
                                End Date
                            </span>

                            <span class="subscription-view-period-value">
                                <?= subscriptionViewEscape(
                                    subscriptionViewDate(
                                        $subscription['ends_at']
                                    )
                                ); ?>

                                <?php if (
                                    $daysRemaining !== null
                                ): ?>
                                    <span class="subscription-view-period-note">
                                        <?php if (
                                            $daysRemaining > 0
                                        ): ?>
                                            <?= (int) $daysRemaining; ?>
                                            day<?= $daysRemaining === 1
                                                ? ''
                                                : 's'; ?> remaining
                                        <?php elseif (
                                            $daysRemaining === 0
                                        ): ?>
                                            Ends today
                                        <?php else: ?>
                                            Expired
                                            <?= abs(
                                                (int)
                                                $daysRemaining
                                            ); ?>
                                            day<?= abs(
                                                (int)
                                                $daysRemaining
                                            ) === 1
                                                ? ''
                                                : 's'; ?> ago
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="subscription-view-card">
                <div class="subscription-view-card-header">
                    <span class="subscription-view-card-icon">
                        <i class="bi bi-sliders"></i>
                    </span>

                    <div>
                        <h3 class="subscription-view-card-title">
                            Subscription Settings
                        </h3>

                        <div class="subscription-view-card-subtitle">
                            Status, renewal, and record details
                        </div>
                    </div>
                </div>

                <div class="subscription-view-card-body">
                    <div class="subscription-view-details">
                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Status
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    subscriptionViewLabel(
                                        $status
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Auto Renew
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= !empty(
                                    $subscription['auto_renew']
                                )
                                    ? 'Enabled'
                                    : 'Disabled'; ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Created
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    subscriptionViewDate(
                                        $subscription['created_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Last Updated
                            </span>

                            <span class="subscription-view-detail-value">
                                <?= subscriptionViewEscape(
                                    subscriptionViewDate(
                                        $subscription['updated_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="subscription-view-detail">
                            <span class="subscription-view-detail-label">
                                Subscription ID
                            </span>

                            <span class="subscription-view-detail-value">
                                #<?= (int) $subscriptionId; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="subscription-view-card">
                <div class="subscription-view-card-header">
                    <span class="subscription-view-card-icon">
                        <i class="bi bi-lightning-charge"></i>
                    </span>

                    <div>
                        <h3 class="subscription-view-card-title">
                            Quick Actions
                        </h3>

                        <div class="subscription-view-card-subtitle">
                            Related subscription actions
                        </div>
                    </div>
                </div>

                <div class="subscription-view-card-body">
                    <div class="subscription-view-quick-links">

                        <?php if ($canEdit): ?>
                            <a
                                href="subscription-edit.php?id=<?= (int) $subscriptionId; ?>"
                                class="subscription-view-quick-link"
                            >
                                <i class="bi bi-pencil-square"></i>
                                Edit Subscription
                            </a>
                        <?php endif; ?>

                        <a
                            href="tenant-view.php?id=<?= (int)
                                $subscription['tenant_id']; ?>"
                            class="subscription-view-quick-link"
                        >
                            <i class="bi bi-building"></i>
                            View Tenant
                        </a>

                        <a
                            href="plan-view.php?id=<?= (int)
                                $subscription['plan_id']; ?>"
                            class="subscription-view-quick-link"
                        >
                            <i class="bi bi-card-list"></i>
                            View Plan
                        </a>

                        <a
                            href="subscriptions.php"
                            class="subscription-view-quick-link"
                        >
                            <i class="bi bi-credit-card"></i>
                            View All Subscriptions
                        </a>

                    </div>
                </div>
            </section>

        </aside>

    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
