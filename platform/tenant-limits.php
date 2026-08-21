<?php
/**
 * FieldPlx Platform - Tenant Usage Limits
 *
 * File:
 * platform/tenant-limits.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MariaDB / MySQLi
 *
 * Required tables:
 * - tenants
 * - tenant_feature_limits
 *
 * Optional:
 * - subscriptions
 * - plans
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

requirePlatformRole(array(
    'super_admin',
    'platform_admin'
));

$pageTitle = 'Tenant Usage Limits - FieldPlx';
$activePage = 'tenant-limits';
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

if (!function_exists('tenantLimitsEscape')) {
    function tenantLimitsEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantLimitsTableExists')) {
    function tenantLimitsTableExists(
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

if (!function_exists('tenantLimitsColumns')) {
    function tenantLimitsColumns(
        mysqli $conn,
        $tableName,
        $refresh = false
    ) {
        static $cache = array();

        if (
            !$refresh &&
            isset($cache[$tableName])
        ) {
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

if (!function_exists('tenantLimitsFirstColumn')) {
    function tenantLimitsFirstColumn(
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

if (!function_exists('tenantLimitsPost')) {
    function tenantLimitsPost(
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

if (!function_exists('tenantLimitsCurrentUserId')) {
    function tenantLimitsCurrentUserId()
    {
        if (!empty($_SESSION['platform_user_id'])) {
            return (int) $_SESSION['platform_user_id'];
        }

        if (!empty($_SESSION['platform_admin_id'])) {
            return (int) $_SESSION['platform_admin_id'];
        }

        return 0;
    }
}

if (!function_exists('tenantLimitsLabel')) {
    function tenantLimitsLabel($value)
    {
        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                trim((string) $value)
            )
        );
    }
}

/*
|--------------------------------------------------------------------------
| Create limits table when missing
|--------------------------------------------------------------------------
*/

$conn->query("
    CREATE TABLE IF NOT EXISTS `tenant_feature_limits` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `tenant_id` BIGINT(20) UNSIGNED NOT NULL,
        `limit_code` VARCHAR(120) NOT NULL,
        `limit_value` VARCHAR(255) DEFAULT NULL,
        `updated_by` BIGINT(20) UNSIGNED DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_tenant_limit`
            (`tenant_id`, `limit_code`),
        KEY `idx_tenant_feature_limits_tenant`
            (`tenant_id`)
    ) ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
");


/*
|--------------------------------------------------------------------------
| Upgrade existing tenant_feature_limits table safely
|--------------------------------------------------------------------------
*/

$tenantLimitColumns = tenantLimitsColumns(
    $conn,
    'tenant_feature_limits'
);

if (!isset($tenantLimitColumns['updated_by'])) {
    $conn->query("
        ALTER TABLE `tenant_feature_limits`
        ADD COLUMN `updated_by`
            BIGINT(20) UNSIGNED DEFAULT NULL
        AFTER `limit_value`
    ");
}

$tenantLimitColumns = tenantLimitsColumns(
    $conn,
    'tenant_feature_limits',
    true
);

if (!isset($tenantLimitColumns['created_at'])) {
    $conn->query("
        ALTER TABLE `tenant_feature_limits`
        ADD COLUMN `created_at`
            DATETIME NOT NULL
            DEFAULT CURRENT_TIMESTAMP
        AFTER `updated_by`
    ");
}

$tenantLimitColumns = tenantLimitsColumns(
    $conn,
    'tenant_feature_limits',
    true
);

if (!isset($tenantLimitColumns['updated_at'])) {
    $conn->query("
        ALTER TABLE `tenant_feature_limits`
        ADD COLUMN `updated_at`
            DATETIME DEFAULT NULL
            ON UPDATE CURRENT_TIMESTAMP
        AFTER `created_at`
    ");
}

/*
|--------------------------------------------------------------------------
| Tenant
|--------------------------------------------------------------------------
*/

if (!tenantLimitsTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$tenantColumns =
    tenantLimitsColumns(
        $conn,
        'tenants'
    );

$tenantIdColumn =
    tenantLimitsFirstColumn(
        $tenantColumns,
        array('id', 'tenant_id')
    );

$tenantNameColumn =
    tenantLimitsFirstColumn(
        $tenantColumns,
        array(
            'business_name',
            'company_name',
            'tenant_name',
            'name'
        )
    );

$tenantCodeColumn =
    tenantLimitsFirstColumn(
        $tenantColumns,
        array(
            'tenant_code',
            'business_code',
            'code',
            'slug'
        )
    );

$tenantDeletedColumn =
    tenantLimitsFirstColumn(
        $tenantColumns,
        array('deleted_at')
    );

if (
    $tenantIdColumn === '' ||
    $tenantNameColumn === ''
) {
    http_response_code(500);
    exit('Required tenant columns are missing.');
}

$tenantId = isset($_GET['tenant_id'])
    ? (int) $_GET['tenant_id']
    : (
        isset($_POST['tenant_id'])
            ? (int) $_POST['tenant_id']
            : 0
    );

if ($tenantId <= 0) {
    $_SESSION['platform_error_message'] =
        'Select a tenant to configure.';

    header('Location: tenants.php');
    exit;
}

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
    WHERE `{$tenantIdColumn}` = ?
";

if ($tenantDeletedColumn !== '') {
    $tenantSql .= "
        AND `{$tenantDeletedColumn}` IS NULL
    ";
}

$tenantSql .= " LIMIT 1";

$tenantStmt = $conn->prepare($tenantSql);
$tenantStmt->bind_param('i', $tenantId);
$tenantStmt->execute();

$tenant = $tenantStmt
    ->get_result()
    ->fetch_assoc();

$tenantStmt->close();

if (!$tenant) {
    $_SESSION['platform_error_message'] =
        'Tenant not found.';

    header('Location: tenants.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Current subscription and plan
|--------------------------------------------------------------------------
*/

$currentSubscription = null;

if (
    tenantLimitsTableExists($conn, 'subscriptions') &&
    tenantLimitsTableExists($conn, 'plans')
) {
    $subscriptionColumns =
        tenantLimitsColumns(
            $conn,
            'subscriptions'
        );

    $planColumns =
        tenantLimitsColumns(
            $conn,
            'plans'
        );

    $subscriptionIdColumn =
        tenantLimitsFirstColumn(
            $subscriptionColumns,
            array('id', 'subscription_id')
        );

    $subscriptionPlanColumn =
        tenantLimitsFirstColumn(
            $subscriptionColumns,
            array('plan_id')
        );

    $subscriptionTenantColumn =
        tenantLimitsFirstColumn(
            $subscriptionColumns,
            array('tenant_id')
        );

    $subscriptionStatusColumn =
        tenantLimitsFirstColumn(
            $subscriptionColumns,
            array('status')
        );

    $subscriptionDeletedColumn =
        tenantLimitsFirstColumn(
            $subscriptionColumns,
            array('deleted_at')
        );

    $subscriptionCreatedColumn =
        tenantLimitsFirstColumn(
            $subscriptionColumns,
            array('created_at')
        );

    $planIdColumn =
        tenantLimitsFirstColumn(
            $planColumns,
            array('id', 'plan_id')
        );

    $planNameColumn =
        tenantLimitsFirstColumn(
            $planColumns,
            array('name', 'plan_name')
        );

    if (
        $subscriptionIdColumn !== '' &&
        $subscriptionPlanColumn !== '' &&
        $subscriptionTenantColumn !== '' &&
        $planIdColumn !== '' &&
        $planNameColumn !== ''
    ) {
        $subscriptionSql = "
            SELECT
                s.`{$subscriptionIdColumn}` AS subscription_id,
                s.`{$subscriptionPlanColumn}` AS plan_id,
                p.`{$planNameColumn}` AS plan_name
        ";

        $subscriptionSql .=
            $subscriptionStatusColumn !== ''
                ? ", s.`{$subscriptionStatusColumn}` AS subscription_status"
                : ", 'active' AS subscription_status";

        $subscriptionSql .= "
            FROM subscriptions s
            INNER JOIN plans p
                ON p.`{$planIdColumn}` =
                   s.`{$subscriptionPlanColumn}`
            WHERE s.`{$subscriptionTenantColumn}` = ?
        ";

        if ($subscriptionDeletedColumn !== '') {
            $subscriptionSql .= "
                AND s.`{$subscriptionDeletedColumn}` IS NULL
            ";
        }

        if ($subscriptionStatusColumn !== '') {
            $subscriptionSql .= "
                AND s.`{$subscriptionStatusColumn}` IN (
                    'trial',
                    'active',
                    'past_due',
                    'suspended'
                )
            ";
        }

        $subscriptionSql .= "
            ORDER BY
                " .
                (
                    $subscriptionCreatedColumn !== ''
                        ? "s.`{$subscriptionCreatedColumn}` DESC"
                        : "s.`{$subscriptionIdColumn}` DESC"
                ) . "
            LIMIT 1
        ";

        $subscriptionStmt =
            $conn->prepare($subscriptionSql);

        $subscriptionStmt->bind_param(
            'i',
            $tenantId
        );

        $subscriptionStmt->execute();

        $currentSubscription =
            $subscriptionStmt
            ->get_result()
            ->fetch_assoc();

        $subscriptionStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Limit definitions
|--------------------------------------------------------------------------
*/

$limitDefinitions = array(
    'max_users' => array(
        'label' => 'Maximum Users',
        'description' =>
            'Maximum active users allowed in this tenant workspace.',
        'type' => 'number',
        'unit' => 'users',
        'min' => 1,
        'max' => 100000,
        'default' => ''
    ),
    'max_workers' => array(
        'label' => 'Maximum Workers',
        'description' =>
            'Maximum field workers or technicians allowed.',
        'type' => 'number',
        'unit' => 'workers',
        'min' => 1,
        'max' => 100000,
        'default' => ''
    ),
    'max_branches' => array(
        'label' => 'Maximum Branches',
        'description' =>
            'Maximum number of business branches or locations.',
        'type' => 'number',
        'unit' => 'branches',
        'min' => 1,
        'max' => 10000,
        'default' => ''
    ),
    'max_monthly_jobs' => array(
        'label' => 'Monthly Job Limit',
        'description' =>
            'Maximum jobs that can be created during a calendar month.',
        'type' => 'number',
        'unit' => 'jobs/month',
        'min' => 1,
        'max' => 10000000,
        'default' => ''
    ),
    'max_monthly_invoices' => array(
        'label' => 'Monthly Invoice Limit',
        'description' =>
            'Maximum invoices that can be generated each month.',
        'type' => 'number',
        'unit' => 'invoices/month',
        'min' => 1,
        'max' => 10000000,
        'default' => ''
    ),
    'storage_gb' => array(
        'label' => 'Storage Limit',
        'description' =>
            'Maximum uploaded file storage available to this tenant.',
        'type' => 'decimal',
        'unit' => 'GB',
        'min' => 0.1,
        'max' => 100000,
        'default' => ''
    ),
    'location_retention_days' => array(
        'label' => 'Location Retention',
        'description' =>
            'Number of days worker location history should be retained.',
        'type' => 'number',
        'unit' => 'days',
        'min' => 1,
        'max' => 3650,
        'default' => ''
    ),
    'max_api_requests_per_day' => array(
        'label' => 'Daily API Request Limit',
        'description' =>
            'Maximum API requests permitted within one day.',
        'type' => 'number',
        'unit' => 'requests/day',
        'min' => 1,
        'max' => 100000000,
        'default' => ''
    ),
    'max_automation_runs_per_month' => array(
        'label' => 'Monthly Automation Runs',
        'description' =>
            'Maximum automation executions allowed each month.',
        'type' => 'number',
        'unit' => 'runs/month',
        'min' => 1,
        'max' => 10000000,
        'default' => ''
    ),
    'max_message_credits_per_month' => array(
        'label' => 'Monthly Message Credits',
        'description' =>
            'Maximum notification, SMS, or message credits available each month.',
        'type' => 'number',
        'unit' => 'credits/month',
        'min' => 1,
        'max' => 100000000,
        'default' => ''
    )
);

/*
|--------------------------------------------------------------------------
| Load saved values
|--------------------------------------------------------------------------
*/

$savedLimits = array();

$limitsStmt = $conn->prepare("
    SELECT
        `limit_code`,
        `limit_value`
    FROM tenant_feature_limits
    WHERE `tenant_id` = ?
");

$limitsStmt->bind_param(
    'i',
    $tenantId
);

$limitsStmt->execute();

$limitsResult = $limitsStmt->get_result();

while ($limitRow = $limitsResult->fetch_assoc()) {
    $savedLimits[
        (string) $limitRow['limit_code']
    ] = (string) $limitRow['limit_value'];
}

$limitsStmt->close();

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$formValues = array();

foreach ($limitDefinitions as $code => $definition) {
    if (
        isset($_POST['limits']) &&
        is_array($_POST['limits']) &&
        isset($_POST['limits'][$code]) &&
        !is_array($_POST['limits'][$code])
    ) {
        $formValues[$code] =
            trim(
                (string)
                $_POST['limits'][$code]
            );
    } elseif (array_key_exists($code, $savedLimits)) {
        $formValues[$code] =
            $savedLimits[$code];
    } else {
        $formValues[$code] =
            (string) $definition['default'];
    }
}

$errorMessage = '';
$successMessage = '';

/*
|--------------------------------------------------------------------------
| Save limits
|--------------------------------------------------------------------------
*/

if (
    isset($_SERVER['REQUEST_METHOD']) &&
    strtoupper($_SERVER['REQUEST_METHOD']) === 'POST'
) {
    verifyCsrfToken();

    $action = tenantLimitsPost(
        'action',
        'save'
    );

    try {
        $conn->begin_transaction();

        if ($action === 'reset_all') {
            $deleteAllStmt =
                $conn->prepare("
                    DELETE FROM tenant_feature_limits
                    WHERE `tenant_id` = ?
                ");

            $deleteAllStmt->bind_param(
                'i',
                $tenantId
            );

            $deleteAllStmt->execute();
            $deleteAllStmt->close();

            $conn->commit();

            regenerateCsrfToken();

            $_SESSION['platform_success_message'] =
                'All tenant usage limits were reset to plan or system defaults.';

            header(
                'Location: tenant-limits.php?tenant_id=' .
                $tenantId,
                true,
                303
            );

            exit;
        }

        $validatedValues = array();

        foreach ($limitDefinitions as $code => $definition) {
            $rawValue = isset($formValues[$code])
                ? trim((string) $formValues[$code])
                : '';

            if ($rawValue === '') {
                $validatedValues[$code] = null;
                continue;
            }

            if (!is_numeric($rawValue)) {
                throw new RuntimeException(
                    $definition['label'] .
                    ' must contain a valid number.'
                );
            }

            $numericValue =
                $definition['type'] === 'decimal'
                    ? round((float) $rawValue, 2)
                    : (int) $rawValue;

            if ($numericValue < $definition['min']) {
                throw new RuntimeException(
                    $definition['label'] .
                    ' must be at least ' .
                    $definition['min'] . '.'
                );
            }

            if ($numericValue > $definition['max']) {
                throw new RuntimeException(
                    $definition['label'] .
                    ' must not exceed ' .
                    $definition['max'] . '.'
                );
            }

            $validatedValues[$code] =
                $definition['type'] === 'decimal'
                    ? number_format(
                        $numericValue,
                        2,
                        '.',
                        ''
                    )
                    : (string) $numericValue;
        }

        $deleteStmt =
            $conn->prepare("
                DELETE FROM tenant_feature_limits
                WHERE `tenant_id` = ?
                  AND `limit_code` = ?
            ");

        $upsertStmt =
            $conn->prepare("
                INSERT INTO tenant_feature_limits (
                    `tenant_id`,
                    `limit_code`,
                    `limit_value`,
                    `updated_by`,
                    `created_at`,
                    `updated_at`
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    `limit_value` =
                        VALUES(`limit_value`),
                    `updated_by` =
                        VALUES(`updated_by`),
                    `updated_at` = NOW()
            ");

        $updatedBy =
            tenantLimitsCurrentUserId();

        foreach ($validatedValues as $code => $value) {
            if ($value === null) {
                $deleteStmt->bind_param(
                    'is',
                    $tenantId,
                    $code
                );

                $deleteStmt->execute();
            } else {
                $upsertStmt->bind_param(
                    'issi',
                    $tenantId,
                    $code,
                    $value,
                    $updatedBy
                );

                $upsertStmt->execute();
            }
        }

        $deleteStmt->close();
        $upsertStmt->close();

        $conn->commit();

        regenerateCsrfToken();

        $_SESSION['platform_success_message'] =
            'Tenant usage limits updated successfully.';

        header(
            'Location: tenant-limits.php?tenant_id=' .
            $tenantId,
            true,
            303
        );

        exit;
    } catch (Exception $exception) {
        $conn->rollback();

        error_log(
            'Tenant limits update failed: ' .
            $exception->getMessage()
        );

        $errorMessage =
            'Unable to save tenant limits: ' .
            $exception->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Messages and summary
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['platform_success_message'])) {
    $successMessage =
        (string) $_SESSION['platform_success_message'];

    unset($_SESSION['platform_success_message']);
}

if (!empty($_SESSION['platform_error_message'])) {
    $errorMessage =
        (string) $_SESSION['platform_error_message'];

    unset($_SESSION['platform_error_message']);
}

$customLimitsCount = 0;

foreach ($formValues as $value) {
    if (trim((string) $value) !== '') {
        $customLimitsCount++;
    }
}

$totalLimitOptions = count($limitDefinitions);

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .tenant-limits-page {
        display: grid;
        gap: 15px;
    }

    .tenant-limits-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .tenant-limits-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .tenant-limits-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-limits-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .tenant-limits-title {
        margin: 0;
        color: #111827;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-limits-description {
        margin-top: 4px;
        color: #6b7280;
        font-size: 10px;
    }

    .tenant-limits-actions {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
    }

    .tenant-limits-button {
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

    .tenant-limits-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-limits-button.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-limits-tenant {
        padding: 16px;
        display: flex;
        align-items: center;
        gap: 13px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-limits-tenant-icon {
        width: 48px;
        height: 48px;
        flex: 0 0 48px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 17px;
    }

    .tenant-limits-tenant-name {
        display: block;
        color: #111827;
        font-size: 14px;
        font-weight: 800;
    }

    .tenant-limits-tenant-meta {
        margin-top: 4px;
        display: block;
        color: #6b7280;
        font-size: 9px;
    }

    .tenant-limits-summary {
        display: grid;
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
        gap: 10px;
    }

    .tenant-limits-summary-card {
        padding: 13px 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
    }

    .tenant-limits-summary-icon {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 14px;
    }

    .tenant-limits-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .tenant-limits-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .tenant-limits-help {
        padding: 12px 14px;
        border: 1px solid #ddd6fe;
        border-radius: 10px;
        background: #faf8ff;
        color: #5b21b6;
        font-size: 9px;
        line-height: 1.6;
    }

    .tenant-limits-grid {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .tenant-limit-card {
        padding: 14px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .tenant-limit-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .tenant-limit-title {
        color: #111827;
        font-size: 10px;
        font-weight: 800;
    }

    .tenant-limit-description {
        margin-top: 4px;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.5;
    }

    .tenant-limit-status {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        background: #f3f4f6;
        color: #6b7280;
        font-size: 7px;
        font-weight: 700;
        white-space: nowrap;
    }

    .tenant-limit-status.custom {
        background: #ede9fe;
        color: #6d28d9;
    }

    .tenant-limit-control-wrap {
        margin-top: 12px;
        position: relative;
    }

    .tenant-limit-control {
        min-height: 39px;
        padding-right: 108px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fafafa;
        color: #374151;
        font-size: 10px;
    }

    .tenant-limit-control:focus {
        border-color: #c4b5fd;
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(124,58,237,.08);
    }

    .tenant-limit-unit {
        position: absolute;
        top: 50%;
        right: 11px;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 8px;
        pointer-events: none;
    }

    .tenant-limit-help {
        margin-top: 6px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-limits-savebar {
        position: sticky;
        bottom: 0;
        z-index: 20;
        padding: 12px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border: 1px solid #ddd6fe;
        border-radius: 12px;
        background: rgba(255,255,255,.96);
        box-shadow:
            0 -8px 28px rgba(91,33,182,.08);
        backdrop-filter: blur(8px);
    }

    .tenant-limits-savebar-text {
        color: #6b7280;
        font-size: 9px;
    }

    .tenant-limits-save {
        min-height: 39px;
        padding: 8px 14px;
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
        font-size: 9px;
        font-weight: 700;
    }

    @media (max-width: 900px) {
        .tenant-limits-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 700px) {
        .tenant-limits-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .tenant-limits-actions {
            width: 100%;
        }

        .tenant-limits-button {
            flex: 1;
        }

        .tenant-limits-summary {
            grid-template-columns: 1fr;
        }

        .tenant-limits-savebar {
            align-items: stretch;
            flex-direction: column;
        }

        .tenant-limits-save {
            width: 100%;
        }
    }
</style>

<div class="tenant-limits-page">

    <?php if ($successMessage !== ''): ?>
        <div class="tenant-limits-alert success">
            <i class="bi bi-check-circle"></i>

            <span>
                <?= tenantLimitsEscape(
                    $successMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="tenant-limits-alert danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= tenantLimitsEscape(
                    $errorMessage
                ); ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="tenant-limits-header">
        <div>
            <h2 class="tenant-limits-title">
                Tenant Usage Limits
            </h2>

            <div class="tenant-limits-description">
                Set custom workspace limits for this individual business.
            </div>
        </div>

        <div class="tenant-limits-actions">
            <a
                href="tenant-view.php?id=<?= (int) $tenantId; ?>"
                class="tenant-limits-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Tenant
            </a>

            <a
                href="tenant-modules.php?tenant_id=<?= (int) $tenantId; ?>"
                class="tenant-limits-button"
            >
                <i class="bi bi-grid-3x3-gap"></i>
                Modules & Features
            </a>
        </div>
    </div>

    <section class="tenant-limits-tenant">
        <span class="tenant-limits-tenant-icon">
            <i class="bi bi-building"></i>
        </span>

        <span>
            <span class="tenant-limits-tenant-name">
                <?= tenantLimitsEscape(
                    $tenant['tenant_name']
                ); ?>
            </span>

            <span class="tenant-limits-tenant-meta">
                <?= tenantLimitsEscape(
                    !empty($tenant['tenant_code'])
                        ? $tenant['tenant_code']
                        : 'Tenant #' . $tenantId
                ); ?>

                <?php if ($currentSubscription): ?>
                    · Plan:
                    <?= tenantLimitsEscape(
                        $currentSubscription['plan_name']
                    ); ?>
                    ·
                    <?= tenantLimitsEscape(
                        tenantLimitsLabel(
                            $currentSubscription[
                                'subscription_status'
                            ]
                        )
                    ); ?>
                <?php else: ?>
                    · No active subscription plan
                <?php endif; ?>
            </span>
        </span>
    </section>

    <section class="tenant-limits-summary">
        <article class="tenant-limits-summary-card">
            <span class="tenant-limits-summary-icon">
                <i class="bi bi-sliders"></i>
            </span>

            <span>
                <span class="tenant-limits-summary-label">
                    Custom Limits
                </span>

                <span class="tenant-limits-summary-value">
                    <?= number_format(
                        $customLimitsCount
                    ); ?>
                </span>
            </span>
        </article>

        <article class="tenant-limits-summary-card">
            <span class="tenant-limits-summary-icon">
                <i class="bi bi-list-check"></i>
            </span>

            <span>
                <span class="tenant-limits-summary-label">
                    Available Limits
                </span>

                <span class="tenant-limits-summary-value">
                    <?= number_format(
                        $totalLimitOptions
                    ); ?>
                </span>
            </span>
        </article>

        <article class="tenant-limits-summary-card">
            <span class="tenant-limits-summary-icon">
                <i class="bi bi-arrow-repeat"></i>
            </span>

            <span>
                <span class="tenant-limits-summary-label">
                    Default Behaviour
                </span>

                <span class="tenant-limits-summary-value">
                    Plan
                </span>
            </span>
        </article>
    </section>

    <div class="tenant-limits-help">
        Leave a field blank to use the subscription plan or system default.
        Enter a value only when this tenant needs a custom limit.
    </div>

    <form
        method="post"
        action="tenant-limits.php?tenant_id=<?= (int) $tenantId; ?>"
        id="tenantLimitsForm"
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="tenant_id"
            value="<?= (int) $tenantId; ?>"
        >

        <input
            type="hidden"
            name="action"
            value="save"
        >

        <div class="tenant-limits-grid">
            <?php foreach (
                $limitDefinitions as
                $limitCode =>
                $definition
            ): ?>
                <?php
                $currentValue = isset(
                    $formValues[$limitCode]
                )
                    ? trim(
                        (string)
                        $formValues[$limitCode]
                    )
                    : '';

                $hasCustomValue =
                    $currentValue !== '';
                ?>

                <section class="tenant-limit-card">
                    <div class="tenant-limit-header">
                        <span>
                            <span class="tenant-limit-title">
                                <?= tenantLimitsEscape(
                                    $definition['label']
                                ); ?>
                            </span>

                            <span class="tenant-limit-description">
                                <?= tenantLimitsEscape(
                                    $definition[
                                        'description'
                                    ]
                                ); ?>
                            </span>
                        </span>

                        <span
                            class="tenant-limit-status <?= $hasCustomValue
                                ? 'custom'
                                : ''; ?>"
                            data-limit-status="<?= tenantLimitsEscape(
                                $limitCode
                            ); ?>"
                        >
                            <?= $hasCustomValue
                                ? 'Custom'
                                : 'Plan Default'; ?>
                        </span>
                    </div>

                    <div class="tenant-limit-control-wrap">
                        <input
                            type="number"
                            name="limits[<?= tenantLimitsEscape(
                                $limitCode
                            ); ?>]"
                            class="form-control tenant-limit-control"
                            value="<?= tenantLimitsEscape(
                                $currentValue
                            ); ?>"
                            min="<?= tenantLimitsEscape(
                                $definition['min']
                            ); ?>"
                            max="<?= tenantLimitsEscape(
                                $definition['max']
                            ); ?>"
                            step="<?= $definition['type'] === 'decimal'
                                ? '0.01'
                                : '1'; ?>"
                            placeholder="Use plan default"
                            data-limit-input="<?= tenantLimitsEscape(
                                $limitCode
                            ); ?>"
                        >

                        <span class="tenant-limit-unit">
                            <?= tenantLimitsEscape(
                                $definition['unit']
                            ); ?>
                        </span>
                    </div>

                    <div class="tenant-limit-help">
                        Allowed range:
                        <?= tenantLimitsEscape(
                            $definition['min']
                        ); ?>
                        to
                        <?= tenantLimitsEscape(
                            $definition['max']
                        ); ?>
                        <?= tenantLimitsEscape(
                            $definition['unit']
                        ); ?>.
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="tenant-limits-savebar">
            <div class="tenant-limits-savebar-text">
                Blank values continue using the plan or system default.
            </div>

            <button
                type="submit"
                class="tenant-limits-save"
                id="tenantLimitsSave"
            >
                <i class="bi bi-check2-circle"></i>
                Save Usage Limits
            </button>
        </div>
    </form>

    <form
        method="post"
        action="tenant-limits.php?tenant_id=<?= (int) $tenantId; ?>"
        onsubmit="return confirm('Reset all custom tenant limits to plan or system defaults?');"
    >
        <?php csrfField(); ?>

        <input
            type="hidden"
            name="tenant_id"
            value="<?= (int) $tenantId; ?>"
        >

        <input
            type="hidden"
            name="action"
            value="reset_all"
        >

        <button
            type="submit"
            class="tenant-limits-button danger"
        >
            <i class="bi bi-arrow-counterclockwise"></i>
            Reset All Limits to Default
        </button>
    </form>

</div>

<script>
(function () {
    'use strict';

    const inputs =
        document.querySelectorAll(
            '[data-limit-input]'
        );

    inputs.forEach(function (input) {
        const code =
            input.getAttribute(
                'data-limit-input'
            );

        const status =
            document.querySelector(
                '[data-limit-status="' +
                code +
                '"]'
            );

        function refreshStatus() {
            if (!status) {
                return;
            }

            if (
                String(input.value).trim() !== ''
            ) {
                status.textContent = 'Custom';
                status.classList.add('custom');
            } else {
                status.textContent =
                    'Plan Default';

                status.classList.remove(
                    'custom'
                );
            }
        }

        input.addEventListener(
            'input',
            refreshStatus
        );

        refreshStatus();
    });

    const form =
        document.getElementById(
            'tenantLimitsForm'
        );

    const saveButton =
        document.getElementById(
            'tenantLimitsSave'
        );

    if (
        form &&
        saveButton
    ) {
        form.addEventListener(
            'submit',
            function () {
                saveButton.disabled = true;
                saveButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm"></span> Saving...';
            }
        );
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
