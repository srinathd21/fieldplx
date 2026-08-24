<?php
/**
 * FieldPlx Platform - Tenant View
 *
 * File:
 * platform/tenant-view.php
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
    'support_admin',
    'billing_admin',
    'platform_read_only'
));

$pageTitle = 'Tenant Details - FieldPlx';
$activePage = 'tenants';
$basePath = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('tenantViewEscape')) {
    function tenantViewEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantViewTableExists')) {
    function tenantViewTableExists(mysqli $conn, $tableName)
    {
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

        if (!$stmt->execute()) {
            $stmt->close();
            $cache[$tableName] = false;
            return false;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        $cache[$tableName] = !empty($row['total']);

        return $cache[$tableName];
    }
}

if (!function_exists('tenantViewColumns')) {
    function tenantViewColumns(mysqli $conn, $tableName)
    {
        static $cache = array();

        $tableName = trim((string) $tableName);

        if ($tableName === '') {
            return array();
        }

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $cache[$tableName] = array();

        $safeTableName = str_replace('`', '``', $tableName);

        $result = $conn->query(
            "SHOW COLUMNS FROM `{$safeTableName}`"
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

if (!function_exists('tenantViewFirstColumn')) {
    function tenantViewFirstColumn(
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

if (!function_exists('tenantViewStatusLabel')) {
    function tenantViewStatusLabel($status)
    {
        $status = trim((string) $status);

        if ($status === '') {
            return 'Unknown';
        }

        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                $status
            )
        );
    }
}

if (!function_exists('tenantViewStatusClass')) {
    function tenantViewStatusClass($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'active':
            case 'paid':
            case 'completed':
                return 'success';

            case 'trial':
                return 'info';

            case 'pending':
            case 'pending_approval':
                return 'warning';

            case 'inactive':
            case 'suspended':
            case 'expired':
            case 'cancelled':
            case 'overdue':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('tenantViewFormatDate')) {
    function tenantViewFormatDate($value, $withTime = false)
    {
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

if (!function_exists('tenantViewInitials')) {
    function tenantViewInitials($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'TN';
        }

        $parts = preg_split('/\s+/', $name);
        $initials = '';

        if (!empty($parts[0])) {
            $initials .= strtoupper(
                substr($parts[0], 0, 1)
            );
        }

        if (count($parts) > 1) {
            $last = end($parts);

            if ($last !== '') {
                $initials .= strtoupper(
                    substr($last, 0, 1)
                );
            }
        }

        return $initials !== ''
            ? $initials
            : 'TN';
    }
}

if (!function_exists('tenantViewDisplay')) {
    function tenantViewDisplay($value, $fallback = '—')
    {
        $value = trim((string) $value);

        return $value !== ''
            ? $value
            : $fallback;
    }
}

if (!function_exists('tenantViewCountByTenant')) {
    function tenantViewCountByTenant(
        mysqli $conn,
        $tableName,
        $tenantColumn,
        $tenantId,
        $deletedColumn = ''
    ) {
        if (
            !tenantViewTableExists($conn, $tableName) ||
            $tenantColumn === ''
        ) {
            return 0;
        }

        $safeTable = str_replace('`', '``', $tableName);
        $safeTenantColumn = str_replace('`', '``', $tenantColumn);

        $sql = "
            SELECT COUNT(*) AS total
            FROM `{$safeTable}`
            WHERE `{$safeTenantColumn}` = ?
        ";

        if ($deletedColumn !== '') {
            $safeDeletedColumn = str_replace(
                '`',
                '``',
                $deletedColumn
            );

            $sql .= "
                AND `{$safeDeletedColumn}` IS NULL
            ";
        }

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $tenantId);

        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return isset($row['total'])
            ? (int) $row['total']
            : 0;
    }
}

/*
|--------------------------------------------------------------------------
| Input
|--------------------------------------------------------------------------
*/

$tenantId = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($tenantId <= 0) {
    $_SESSION['platform_error_message'] =
        'Invalid tenant record.';

    header('Location: tenants.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Tenant table structure
|--------------------------------------------------------------------------
*/

if (!tenantViewTableExists($conn, 'tenants')) {
    http_response_code(500);
    exit('The tenants table does not exist.');
}

$tenantColumns = tenantViewColumns(
    $conn,
    'tenants'
);

$idColumn = tenantViewFirstColumn(
    $tenantColumns,
    array('id', 'tenant_id')
);

$nameColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'company_name',
        'business_name',
        'tenant_name',
        'name'
    )
);

$codeColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'tenant_code',
        'code',
        'business_code'
    )
);

$slugColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'slug',
        'tenant_slug',
        'subdomain'
    )
);

$emailColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'email',
        'contact_email',
        'billing_email'
    )
);

$phoneColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'phone',
        'mobile',
        'contact_phone',
        'contact_mobile'
    )
);

$alternatePhoneColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'alternate_phone',
        'alternate_mobile',
        'phone2'
    )
);

$contactNameColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'contact_name',
        'owner_name',
        'primary_contact_name'
    )
);

$addressColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'address',
        'address_line1',
        'street_address'
    )
);

$addressLine2Column = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'address_line2',
        'address2'
    )
);

$cityColumn = tenantViewFirstColumn(
    $tenantColumns,
    array('city')
);

$stateColumn = tenantViewFirstColumn(
    $tenantColumns,
    array('state', 'state_name')
);

$countryColumn = tenantViewFirstColumn(
    $tenantColumns,
    array('country', 'country_name')
);

$postalCodeColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'postal_code',
        'pincode',
        'zip_code'
    )
);

$taxNumberColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'tax_number',
        'gst_number',
        'gstin',
        'vat_number'
    )
);

$statusColumn = tenantViewFirstColumn(
    $tenantColumns,
    array('status')
);

$trialStartColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'trial_starts_at',
        'trial_start_date',
        'trial_start'
    )
);

$trialEndColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'trial_ends_at',
        'trial_end_date',
        'trial_end'
    )
);

$logoColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'logo_path',
        'logo',
        'company_logo'
    )
);

$timezoneColumn = tenantViewFirstColumn(
    $tenantColumns,
    array('timezone')
);

$currencyColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'currency',
        'currency_code'
    )
);

$notesColumn = tenantViewFirstColumn(
    $tenantColumns,
    array(
        'notes',
        'description',
        'remarks'
    )
);

$createdAtColumn = tenantViewFirstColumn(
    $tenantColumns,
    array('created_at', 'created_on')
);

$updatedAtColumn = tenantViewFirstColumn(
    $tenantColumns,
    array('updated_at', 'updated_on')
);

$deletedColumn = tenantViewFirstColumn(
    $tenantColumns,
    array('deleted_at')
);

if ($idColumn === '' || $nameColumn === '') {
    http_response_code(500);
    exit('Required tenant columns are missing.');
}

/*
|--------------------------------------------------------------------------
| Load tenant
|--------------------------------------------------------------------------
*/

$select = array(
    "`{$idColumn}` AS tenant_id",
    "`{$nameColumn}` AS tenant_name"
);

$optionalSelects = array(
    'tenant_code' => $codeColumn,
    'tenant_slug' => $slugColumn,
    'tenant_email' => $emailColumn,
    'tenant_phone' => $phoneColumn,
    'tenant_alternate_phone' => $alternatePhoneColumn,
    'tenant_contact_name' => $contactNameColumn,
    'tenant_address' => $addressColumn,
    'tenant_address_line2' => $addressLine2Column,
    'tenant_city' => $cityColumn,
    'tenant_state' => $stateColumn,
    'tenant_country' => $countryColumn,
    'tenant_postal_code' => $postalCodeColumn,
    'tenant_tax_number' => $taxNumberColumn,
    'tenant_status' => $statusColumn,
    'trial_starts_at' => $trialStartColumn,
    'trial_ends_at' => $trialEndColumn,
    'tenant_logo' => $logoColumn,
    'tenant_timezone' => $timezoneColumn,
    'tenant_currency' => $currencyColumn,
    'tenant_notes' => $notesColumn,
    'created_at' => $createdAtColumn,
    'updated_at' => $updatedAtColumn
);

foreach ($optionalSelects as $alias => $column) {
    $select[] = $column !== ''
        ? "`{$column}` AS `{$alias}`"
        : "NULL AS `{$alias}`";
}

$sql = "
    SELECT
        " . implode(",\n        ", $select) . "
    FROM tenants
    WHERE `{$idColumn}` = ?
";

if ($deletedColumn !== '') {
    $sql .= "
        AND `{$deletedColumn}` IS NULL
    ";
}

$sql .= ' LIMIT 1';

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    exit(
        'Unable to prepare tenant details: ' .
        tenantViewEscape($conn->error)
    );
}

$stmt->bind_param('i', $tenantId);
$stmt->execute();

$result = $stmt->get_result();
$tenant = $result->fetch_assoc();

$stmt->close();

if (!$tenant) {
    $_SESSION['platform_error_message'] =
        'Tenant record not found.';

    header('Location: tenants.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Related totals
|--------------------------------------------------------------------------
*/

$tenantUsersCount = 0;
$branchesCount = 0;
$subscriptionsCount = 0;
$enabledModulesCount = 0;
$moduleOverridesCount = 0;
$featureOverridesCount = 0;
$tenantLimitsCount = 0;

if (tenantViewTableExists($conn, 'users')) {
    $userColumns = tenantViewColumns(
        $conn,
        'users'
    );

    $userTenantColumn = tenantViewFirstColumn(
        $userColumns,
        array('tenant_id')
    );

    $userDeletedColumn = tenantViewFirstColumn(
        $userColumns,
        array('deleted_at')
    );

    $tenantUsersCount = tenantViewCountByTenant(
        $conn,
        'users',
        $userTenantColumn,
        $tenantId,
        $userDeletedColumn
    );
}

if (tenantViewTableExists($conn, 'branches')) {
    $branchColumns = tenantViewColumns(
        $conn,
        'branches'
    );

    $branchTenantColumn = tenantViewFirstColumn(
        $branchColumns,
        array('tenant_id')
    );

    $branchDeletedColumn = tenantViewFirstColumn(
        $branchColumns,
        array('deleted_at')
    );

    $branchesCount = tenantViewCountByTenant(
        $conn,
        'branches',
        $branchTenantColumn,
        $tenantId,
        $branchDeletedColumn
    );
}

if (tenantViewTableExists($conn, 'subscriptions')) {
    $subscriptionColumns = tenantViewColumns(
        $conn,
        'subscriptions'
    );

    $subscriptionTenantColumn = tenantViewFirstColumn(
        $subscriptionColumns,
        array('tenant_id')
    );

    $subscriptionDeletedColumn = tenantViewFirstColumn(
        $subscriptionColumns,
        array('deleted_at')
    );

    $subscriptionsCount = tenantViewCountByTenant(
        $conn,
        'subscriptions',
        $subscriptionTenantColumn,
        $tenantId,
        $subscriptionDeletedColumn
    );
}


/*
|--------------------------------------------------------------------------
| Tenant module, feature, and limit totals
|--------------------------------------------------------------------------
*/

if (
    tenantViewTableExists($conn, 'tenant_modules') &&
    tenantViewTableExists($conn, 'modules')
) {
    $moduleCountStmt = $conn->prepare("
        SELECT
            SUM(
                CASE
                    WHEN tm.`access_type` = 'enabled'
                    THEN 1 ELSE 0
                END
            ) AS enabled_count,
            SUM(
                CASE
                    WHEN tm.`access_type` IN (
                        'enabled',
                        'disabled'
                    )
                    THEN 1 ELSE 0
                END
            ) AS override_count
        FROM tenant_modules tm
        INNER JOIN modules m
            ON m.`id` = tm.`module_id`
        WHERE tm.`tenant_id` = ?
          AND m.`is_active` = 1
    ");

    if ($moduleCountStmt) {
        $moduleCountStmt->bind_param(
            'i',
            $tenantId
        );

        $moduleCountStmt->execute();

        $moduleCountRow = $moduleCountStmt
            ->get_result()
            ->fetch_assoc();

        $moduleCountStmt->close();

        $enabledModulesCount = isset(
            $moduleCountRow['enabled_count']
        )
            ? (int) $moduleCountRow['enabled_count']
            : 0;

        $moduleOverridesCount = isset(
            $moduleCountRow['override_count']
        )
            ? (int) $moduleCountRow['override_count']
            : 0;
    }
}

if (
    tenantViewTableExists($conn, 'tenant_features')
) {
    $featureColumns = tenantViewColumns(
        $conn,
        'tenant_features'
    );

    $featureTenantColumn =
        tenantViewFirstColumn(
            $featureColumns,
            array('tenant_id')
        );

    if ($featureTenantColumn !== '') {
        $featureOverrideStmt =
            $conn->prepare("
                SELECT COUNT(*) AS total
                FROM tenant_features
                WHERE `{$featureTenantColumn}` = ?
                  AND `access_type` IN (
                      'enabled',
                      'disabled'
                  )
            ");

        if ($featureOverrideStmt) {
            $featureOverrideStmt->bind_param(
                'i',
                $tenantId
            );

            $featureOverrideStmt->execute();

            $featureOverrideRow =
                $featureOverrideStmt
                ->get_result()
                ->fetch_assoc();

            $featureOverrideStmt->close();

            $featureOverridesCount = isset(
                $featureOverrideRow['total']
            )
                ? (int)
                    $featureOverrideRow['total']
                : 0;
        }
    }
}

if (
    tenantViewTableExists(
        $conn,
        'tenant_feature_limits'
    )
) {
    $limitColumns = tenantViewColumns(
        $conn,
        'tenant_feature_limits'
    );

    $limitTenantColumn =
        tenantViewFirstColumn(
            $limitColumns,
            array('tenant_id')
        );

    $tenantLimitsCount =
        tenantViewCountByTenant(
            $conn,
            'tenant_feature_limits',
            $limitTenantColumn,
            $tenantId
        );
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

/*
|--------------------------------------------------------------------------
| Presentation values
|--------------------------------------------------------------------------
*/

$tenantName = tenantViewDisplay(
    $tenant['tenant_name'],
    'Unnamed Tenant'
);

$tenantStatus = strtolower(
    trim((string) $tenant['tenant_status'])
);

if ($tenantStatus === '') {
    $tenantStatus = 'active';
}

$tenantLogo = trim(
    (string) $tenant['tenant_logo']
);

$fullAddressParts = array();

foreach (array(
    $tenant['tenant_address'],
    $tenant['tenant_address_line2'],
    $tenant['tenant_city'],
    $tenant['tenant_state'],
    $tenant['tenant_postal_code'],
    $tenant['tenant_country']
) as $addressPart) {
    $addressPart = trim((string) $addressPart);

    if ($addressPart !== '') {
        $fullAddressParts[] = $addressPart;
    }
}

$fullAddress = !empty($fullAddressParts)
    ? implode(', ', $fullAddressParts)
    : '—';

$trialDaysRemaining = null;

if (!empty($tenant['trial_ends_at'])) {
    $trialEndTimestamp = strtotime(
        (string) $tenant['trial_ends_at']
    );

    if ($trialEndTimestamp !== false) {
        $trialDaysRemaining = (int) ceil(
            ($trialEndTimestamp - time()) / 86400
        );
    }
}

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .tenant-view-page {
        display: grid;
        gap: 15px;
    }

    .tenant-view-alert {
        padding: 11px 13px;
        display: flex;
        align-items: flex-start;
        gap: 9px;
        border: 1px solid;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.55;
    }

    .tenant-view-alert.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
        color: #15803d;
    }

    .tenant-view-alert.danger {
        border-color: #fecaca;
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-view-hero {
        padding: 18px;
        display: flex;
        align-items: center;
        gap: 15px;
        border: 1px solid #e5e7eb;
        border-radius: 13px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.035);
    }

    .tenant-view-logo {
        width: 66px;
        height: 66px;
        flex: 0 0 66px;
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 15px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 18px;
        font-weight: 800;
    }

    .tenant-view-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .tenant-view-hero-content {
        min-width: 0;
        flex: 1;
    }

    .tenant-view-title-row {
        display: flex;
        align-items: center;
        gap: 9px;
        flex-wrap: wrap;
    }

    .tenant-view-title {
        margin: 0;
        color: #111827;
        font-size: 19px;
        font-weight: 800;
    }

    .tenant-view-status {
        padding: 4px 8px;
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .tenant-view-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .tenant-view-status.info {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .tenant-view-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .tenant-view-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .tenant-view-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .tenant-view-meta {
        margin-top: 7px;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        color: #6b7280;
        font-size: 9px;
    }

    .tenant-view-meta span {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .tenant-view-actions {
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
    }

    .tenant-view-button {
        min-height: 36px;
        padding: 7px 11px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #4b5563;
        font-size: 9px;
        font-weight: 600;
        text-decoration: none;
    }

    .tenant-view-button:hover {
        border-color: #c4b5fd;
        color: #7c3aed;
    }

    .tenant-view-button.primary {
        border-color: #7c3aed;
        background: #7c3aed;
        color: #ffffff;
    }

    .tenant-view-button.primary:hover {
        border-color: #6d28d9;
        background: #6d28d9;
        color: #ffffff;
    }

    .tenant-view-summary {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px;
    }

    .tenant-view-summary-card {
        padding: 14px;
        display: flex;
        align-items: center;
        gap: 11px;
        border: 1px solid #e5e7eb;
        border-radius: 11px;
        background: #ffffff;
    }

    .tenant-view-summary-icon {
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        font-size: 15px;
    }

    .tenant-view-summary-icon.purple {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .tenant-view-summary-icon.blue {
        background: #eff6ff;
        color: #2563eb;
    }

    .tenant-view-summary-icon.green {
        background: #ecfdf5;
        color: #059669;
    }

    .tenant-view-summary-icon.orange {
        background: #fff7ed;
        color: #d97706;
    }

    .tenant-view-summary-label {
        display: block;
        color: #6b7280;
        font-size: 8px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .tenant-view-summary-value {
        margin-top: 3px;
        display: block;
        color: #111827;
        font-size: 17px;
        font-weight: 800;
    }

    .tenant-view-layout {
        display: grid;
        grid-template-columns:
            minmax(0, 1.4fr)
            minmax(280px, 0.6fr);
        gap: 15px;
        align-items: start;
    }

    .tenant-view-column {
        display: grid;
        gap: 15px;
    }

    .tenant-view-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #ffffff;
        box-shadow:
            0 5px 20px rgba(31, 41, 55, 0.03);
    }

    .tenant-view-card-header {
        min-height: 52px;
        padding: 12px 15px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-bottom: 1px solid #eef0f3;
    }

    .tenant-view-card-title-wrap {
        display: flex;
        align-items: center;
        gap: 9px;
    }

    .tenant-view-card-icon {
        width: 31px;
        height: 31px;
        flex: 0 0 31px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #f3e8ff;
        color: #7c3aed;
        font-size: 12px;
    }

    .tenant-view-card-title {
        margin: 0;
        color: #111827;
        font-size: 11px;
        font-weight: 700;
    }

    .tenant-view-card-subtitle {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .tenant-view-card-body {
        padding: 15px;
    }

    .tenant-view-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px 18px;
    }

    .tenant-view-field.full {
        grid-column: 1 / -1;
    }

    .tenant-view-label {
        display: block;
        color: #9ca3af;
        font-size: 8px;
        font-weight: 600;
        letter-spacing: 0.35px;
        text-transform: uppercase;
    }

    .tenant-view-value {
        margin-top: 5px;
        display: block;
        color: #374151;
        font-size: 10px;
        font-weight: 600;
        line-height: 1.55;
        word-break: break-word;
    }

    .tenant-view-note {
        margin: 0;
        color: #4b5563;
        font-size: 10px;
        line-height: 1.7;
        white-space: pre-wrap;
    }

    .tenant-view-trial {
        padding: 13px;
        border: 1px solid #dbeafe;
        border-radius: 10px;
        background: #eff6ff;
    }

    .tenant-view-trial.expired {
        border-color: #fecaca;
        background: #fef2f2;
    }

    .tenant-view-trial-title {
        color: #1d4ed8;
        font-size: 10px;
        font-weight: 700;
    }

    .tenant-view-trial.expired .tenant-view-trial-title {
        color: #b91c1c;
    }

    .tenant-view-trial-text {
        margin-top: 5px;
        color: #4b5563;
        font-size: 9px;
        line-height: 1.55;
    }

    .tenant-view-quick-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .tenant-view-quick-action {
        min-height: 76px;
        padding: 11px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: center;
        gap: 7px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        background: #ffffff;
        color: #374151;
        text-decoration: none;
    }

    .tenant-view-quick-action:hover {
        border-color: #c4b5fd;
        background: #faf8ff;
        color: #7c3aed;
    }

    .tenant-view-quick-action i {
        font-size: 16px;
    }

    .tenant-view-quick-action span {
        font-size: 9px;
        font-weight: 600;
    }


    .tenant-view-config-list {
        display: grid;
        gap: 9px;
    }

    .tenant-view-config-row {
        padding: 11px 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border: 1px solid #eef0f3;
        border-radius: 9px;
        background: #fafafa;
    }

    .tenant-view-config-name {
        display: block;
        color: #111827;
        font-size: 9px;
        font-weight: 700;
    }

    .tenant-view-config-description {
        margin-top: 3px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
        line-height: 1.45;
    }

    .tenant-view-config-value {
        min-width: 34px;
        height: 25px;
        padding: 0 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #ede9fe;
        color: #6d28d9;
        font-size: 8px;
        font-weight: 800;
    }

    .tenant-view-card-link {
        color: #7c3aed;
        font-size: 8px;
        font-weight: 700;
        text-decoration: none;
    }

    .tenant-view-card-link:hover {
        color: #6d28d9;
    }

    @media (max-width: 1250px) {
        .tenant-view-summary {
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 800px) {
        .tenant-view-summary {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .tenant-view-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 700px) {
        .tenant-view-hero {
            align-items: flex-start;
            flex-direction: column;
        }

        .tenant-view-actions {
            width: 100%;
        }

        .tenant-view-button {
            flex: 1;
        }

        .tenant-view-grid {
            grid-template-columns: 1fr;
        }

        .tenant-view-field.full {
            grid-column: auto;
        }
    }

    @media (max-width: 480px) {
        .tenant-view-summary {
            grid-template-columns: 1fr;
        }

        .tenant-view-quick-actions {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="tenant-view-page">

    <?php if ($successMessage !== ''): ?>
        <div class="tenant-view-alert success">
            <i class="bi bi-check-circle"></i>

            <span>
                <?= tenantViewEscape($successMessage); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="tenant-view-alert danger">
            <i class="bi bi-exclamation-circle"></i>

            <span>
                <?= tenantViewEscape($errorMessage); ?>
            </span>
        </div>
    <?php endif; ?>

    <section class="tenant-view-hero">

        <div class="tenant-view-logo">
            <?php if ($tenantLogo !== ''): ?>
                <img
                    src="../<?= tenantViewEscape(
                        ltrim($tenantLogo, '/')
                    ); ?>"
                    alt="<?= tenantViewEscape(
                        $tenantName
                    ); ?>"
                >
            <?php else: ?>
                <?= tenantViewEscape(
                    tenantViewInitials($tenantName)
                ); ?>
            <?php endif; ?>
        </div>

        <div class="tenant-view-hero-content">
            <div class="tenant-view-title-row">
                <h2 class="tenant-view-title">
                    <?= tenantViewEscape($tenantName); ?>
                </h2>

                <span
                    class="tenant-view-status <?= tenantViewEscape(
                        tenantViewStatusClass(
                            $tenantStatus
                        )
                    ); ?>"
                >
                    <?= tenantViewEscape(
                        tenantViewStatusLabel(
                            $tenantStatus
                        )
                    ); ?>
                </span>
            </div>

            <div class="tenant-view-meta">
                <span>
                    <i class="bi bi-hash"></i>

                    <?= tenantViewEscape(
                        !empty($tenant['tenant_code'])
                            ? $tenant['tenant_code']
                            : 'Tenant ' . $tenantId
                    ); ?>
                </span>

                <?php if (
                    !empty($tenant['tenant_email'])
                ): ?>
                    <span>
                        <i class="bi bi-envelope"></i>

                        <?= tenantViewEscape(
                            $tenant['tenant_email']
                        ); ?>
                    </span>
                <?php endif; ?>

                <span>
                    <i class="bi bi-calendar3"></i>

                    Created
                    <?= tenantViewEscape(
                        tenantViewFormatDate(
                            $tenant['created_at']
                        )
                    ); ?>
                </span>
            </div>
        </div>

        <div class="tenant-view-actions">
            <a
                href="tenants.php"
                class="tenant-view-button"
            >
                <i class="bi bi-arrow-left"></i>
                Back
            </a>

            <?php if (canManagePlatformTenants()): ?>
                <a
                    href="tenant-modules.php?tenant_id=<?= (int) $tenantId; ?>"
                    class="tenant-view-button"
                >
                    <i class="bi bi-grid-3x3-gap"></i>
                    Modules & Features
                </a>

                <a
                    href="tenant-limits.php?tenant_id=<?= (int) $tenantId; ?>"
                    class="tenant-view-button"
                >
                    <i class="bi bi-sliders"></i>
                    Usage Limits
                </a>

                <a
                    href="tenant-edit.php?id=<?= (int) $tenantId; ?>"
                    class="tenant-view-button primary"
                >
                    <i class="bi bi-pencil"></i>
                    Edit Tenant
                </a>
            <?php endif; ?>
        </div>

    </section>

    <section class="tenant-view-summary">

        <article class="tenant-view-summary-card">
            <span class="tenant-view-summary-icon purple">
                <i class="bi bi-people"></i>
            </span>

            <span>
                <span class="tenant-view-summary-label">
                    Users
                </span>

                <span class="tenant-view-summary-value">
                    <?= number_format(
                        $tenantUsersCount
                    ); ?>
                </span>
            </span>
        </article>

        <article class="tenant-view-summary-card">
            <span class="tenant-view-summary-icon blue">
                <i class="bi bi-diagram-3"></i>
            </span>

            <span>
                <span class="tenant-view-summary-label">
                    Branches
                </span>

                <span class="tenant-view-summary-value">
                    <?= number_format(
                        $branchesCount
                    ); ?>
                </span>
            </span>
        </article>

        <article class="tenant-view-summary-card">
            <span class="tenant-view-summary-icon green">
                <i class="bi bi-credit-card"></i>
            </span>

            <span>
                <span class="tenant-view-summary-label">
                    Subscriptions
                </span>

                <span class="tenant-view-summary-value">
                    <?= number_format(
                        $subscriptionsCount
                    ); ?>
                </span>
            </span>
        </article>


        <article class="tenant-view-summary-card">
            <span class="tenant-view-summary-icon purple">
                <i class="bi bi-grid-3x3-gap"></i>
            </span>

            <span>
                <span class="tenant-view-summary-label">
                    Enabled Modules
                </span>

                <span class="tenant-view-summary-value">
                    <?= number_format(
                        $enabledModulesCount
                    ); ?>
                </span>
            </span>
        </article>

        <article class="tenant-view-summary-card">
            <span class="tenant-view-summary-icon blue">
                <i class="bi bi-toggles"></i>
            </span>

            <span>
                <span class="tenant-view-summary-label">
                    Custom Overrides
                </span>

                <span class="tenant-view-summary-value">
                    <?= number_format(
                        $moduleOverridesCount +
                        $featureOverridesCount
                    ); ?>
                </span>
            </span>
        </article>

        <article class="tenant-view-summary-card">
            <span class="tenant-view-summary-icon orange">
                <i class="bi bi-globe2"></i>
            </span>

            <span>
                <span class="tenant-view-summary-label">
                    Currency
                </span>

                <span class="tenant-view-summary-value">
                    <?= tenantViewEscape(
                        tenantViewDisplay(
                            $tenant['tenant_currency'],
                            'INR'
                        )
                    ); ?>
                </span>
            </span>
        </article>

    </section>

    <section class="tenant-view-layout">

        <div class="tenant-view-column">

            <article class="tenant-view-card">
                <div class="tenant-view-card-header">
                    <div class="tenant-view-card-title-wrap">
                        <span class="tenant-view-card-icon">
                            <i class="bi bi-building"></i>
                        </span>

                        <div>
                            <h3 class="tenant-view-card-title">
                                Business Information
                            </h3>

                            <div class="tenant-view-card-subtitle">
                                Tenant workspace and company details
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tenant-view-card-body">
                    <div class="tenant-view-grid">

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Company Name
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    $tenantName
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Tenant Code
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewDisplay(
                                        $tenant['tenant_code']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Workspace Slug
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewDisplay(
                                        $tenant['tenant_slug']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Tax / GST Number
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewDisplay(
                                        $tenant['tenant_tax_number']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Timezone
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewDisplay(
                                        $tenant['tenant_timezone'],
                                        'Asia/Kolkata'
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Last Updated
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewFormatDate(
                                        $tenant['updated_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>

                    </div>
                </div>
            </article>

            <article class="tenant-view-card">
                <div class="tenant-view-card-header">
                    <div class="tenant-view-card-title-wrap">
                        <span class="tenant-view-card-icon">
                            <i class="bi bi-person-lines-fill"></i>
                        </span>

                        <div>
                            <h3 class="tenant-view-card-title">
                                Contact Information
                            </h3>

                            <div class="tenant-view-card-subtitle">
                                Primary tenant contact details
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tenant-view-card-body">
                    <div class="tenant-view-grid">

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Contact Person
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewDisplay(
                                        $tenant[
                                            'tenant_contact_name'
                                        ]
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Email Address
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewDisplay(
                                        $tenant['tenant_email']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Phone Number
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewDisplay(
                                        $tenant['tenant_phone']
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field">
                            <span class="tenant-view-label">
                                Alternate Phone
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewDisplay(
                                        $tenant[
                                            'tenant_alternate_phone'
                                        ]
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field full">
                            <span class="tenant-view-label">
                                Registered Address
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    $fullAddress
                                ); ?>
                            </span>
                        </div>

                    </div>
                </div>
            </article>

            <article class="tenant-view-card">
                <div class="tenant-view-card-header">
                    <div class="tenant-view-card-title-wrap">
                        <span class="tenant-view-card-icon">
                            <i class="bi bi-grid-3x3-gap"></i>
                        </span>

                        <div>
                            <h3 class="tenant-view-card-title">
                                Modules & Feature Configuration
                            </h3>

                            <div class="tenant-view-card-subtitle">
                                Tenant-specific access and overrides
                            </div>
                        </div>
                    </div>

                    <?php if (canManagePlatformTenants()): ?>
                        <a
                            href="tenant-modules.php?tenant_id=<?= (int) $tenantId; ?>"
                            class="tenant-view-card-link"
                        >
                            Configure Access
                        </a>
                    <?php endif; ?>
                </div>

                <div class="tenant-view-card-body">
                    <div class="tenant-view-config-list">

                        <div class="tenant-view-config-row">
                            <span>
                                <span class="tenant-view-config-name">
                                    Enabled Module Overrides
                                </span>

                                <span class="tenant-view-config-description">
                                    Modules force-enabled specifically for this tenant
                                </span>
                            </span>

                            <span class="tenant-view-config-value">
                                <?= number_format(
                                    $enabledModulesCount
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-config-row">
                            <span>
                                <span class="tenant-view-config-name">
                                    Module Overrides
                                </span>

                                <span class="tenant-view-config-description">
                                    Modules using enabled or disabled tenant overrides
                                </span>
                            </span>

                            <span class="tenant-view-config-value">
                                <?= number_format(
                                    $moduleOverridesCount
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-config-row">
                            <span>
                                <span class="tenant-view-config-name">
                                    Feature Overrides
                                </span>

                                <span class="tenant-view-config-description">
                                    Individual features enabled or disabled for this tenant
                                </span>
                            </span>

                            <span class="tenant-view-config-value">
                                <?= number_format(
                                    $featureOverridesCount
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-config-row">
                            <span>
                                <span class="tenant-view-config-name">
                                    Custom Usage Limits
                                </span>

                                <span class="tenant-view-config-description">
                                    Tenant-specific user, worker, job, storage, or branch limits
                                </span>
                            </span>

                            <span class="tenant-view-config-value">
                                <?= number_format(
                                    $tenantLimitsCount
                                ); ?>
                            </span>
                        </div>

                    </div>
                </div>
            </article>

            <?php if (
                trim((string) $tenant['tenant_notes']) !== ''
            ): ?>
                <article class="tenant-view-card">
                    <div class="tenant-view-card-header">
                        <div class="tenant-view-card-title-wrap">
                            <span class="tenant-view-card-icon">
                                <i class="bi bi-journal-text"></i>
                            </span>

                            <div>
                                <h3 class="tenant-view-card-title">
                                    Internal Notes
                                </h3>

                                <div class="tenant-view-card-subtitle">
                                    Platform administrator notes
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tenant-view-card-body">
                        <p class="tenant-view-note"><?= tenantViewEscape(
                            $tenant['tenant_notes']
                        ); ?></p>
                    </div>
                </article>
            <?php endif; ?>

        </div>

        <aside class="tenant-view-column">

            <?php if (
                $tenantStatus === 'trial' ||
                !empty($tenant['trial_starts_at']) ||
                !empty($tenant['trial_ends_at'])
            ): ?>
                <article class="tenant-view-card">
                    <div class="tenant-view-card-header">
                        <div class="tenant-view-card-title-wrap">
                            <span class="tenant-view-card-icon">
                                <i class="bi bi-clock-history"></i>
                            </span>

                            <div>
                                <h3 class="tenant-view-card-title">
                                    Trial Information
                                </h3>

                                <div class="tenant-view-card-subtitle">
                                    Current trial period
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tenant-view-card-body">
                        <div
                            class="tenant-view-trial <?= (
                                $trialDaysRemaining !== null &&
                                $trialDaysRemaining < 0
                            )
                                ? 'expired'
                                : ''; ?>"
                        >
                            <div class="tenant-view-trial-title">
                                <?php if (
                                    $trialDaysRemaining === null
                                ): ?>
                                    Trial dates not configured
                                <?php elseif (
                                    $trialDaysRemaining < 0
                                ): ?>
                                    Trial expired
                                <?php elseif (
                                    $trialDaysRemaining === 0
                                ): ?>
                                    Trial ends today
                                <?php else: ?>
                                    <?= (int) $trialDaysRemaining; ?>
                                    day<?= $trialDaysRemaining !== 1
                                        ? 's'
                                        : ''; ?>
                                    remaining
                                <?php endif; ?>
                            </div>

                            <div class="tenant-view-trial-text">
                                Start:
                                <?= tenantViewEscape(
                                    tenantViewFormatDate(
                                        $tenant[
                                            'trial_starts_at'
                                        ]
                                    )
                                ); ?>
                                <br>

                                End:
                                <?= tenantViewEscape(
                                    tenantViewFormatDate(
                                        $tenant[
                                            'trial_ends_at'
                                        ]
                                    )
                                ); ?>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endif; ?>

            <article class="tenant-view-card">
                <div class="tenant-view-card-header">
                    <div class="tenant-view-card-title-wrap">
                        <span class="tenant-view-card-icon">
                            <i class="bi bi-lightning-charge"></i>
                        </span>

                        <div>
                            <h3 class="tenant-view-card-title">
                                Quick Actions
                            </h3>

                            <div class="tenant-view-card-subtitle">
                                Common tenant operations
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tenant-view-card-body">
                    <div class="tenant-view-quick-actions">

                        <?php if (canManagePlatformTenants()): ?>
                            <a
                                href="tenant-edit.php?id=<?= (int) $tenantId; ?>"
                                class="tenant-view-quick-action"
                            >
                                <i class="bi bi-pencil-square"></i>
                                <span>Edit Tenant</span>
                            </a>
                        <?php endif; ?>

                        <a
                            href="tenant-users.php?tenant_id=<?= (int) $tenantId; ?>"
                            class="tenant-view-quick-action"
                        >
                            <i class="bi bi-people"></i>
                            <span>Tenant Users</span>
                        </a>

                        <a
                            href="subscriptions.php?tenant_id=<?= (int) $tenantId; ?>"
                            class="tenant-view-quick-action"
                        >
                            <i class="bi bi-credit-card"></i>
                            <span>Subscriptions</span>
                        </a>


                        <?php if (canManagePlatformTenants()): ?>
                            <a
                                href="tenant-modules.php?tenant_id=<?= (int) $tenantId; ?>"
                                class="tenant-view-quick-action"
                            >
                                <i class="bi bi-grid-3x3-gap"></i>
                                <span>Modules & Features</span>
                            </a>

                            <a
                                href="tenant-limits.php?tenant_id=<?= (int) $tenantId; ?>"
                                class="tenant-view-quick-action"
                            >
                                <i class="bi bi-sliders"></i>
                                <span>Usage Limits</span>
                            </a>
                        <?php endif; ?>

                        <?php if (canProvidePlatformSupport()): ?>
                            <a
                                href="support-access.php?tenant_id=<?= (int) $tenantId; ?>"
                                class="tenant-view-quick-action"
                            >
                                <i class="bi bi-headset"></i>
                                <span>Support Access</span>
                            </a>
                        <?php endif; ?>

                    </div>
                </div>
            </article>

            <article class="tenant-view-card">
                <div class="tenant-view-card-header">
                    <div class="tenant-view-card-title-wrap">
                        <span class="tenant-view-card-icon">
                            <i class="bi bi-info-circle"></i>
                        </span>

                        <div>
                            <h3 class="tenant-view-card-title">
                                Record Information
                            </h3>

                            <div class="tenant-view-card-subtitle">
                                Tenant record timestamps
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tenant-view-card-body">
                    <div class="tenant-view-grid">

                        <div class="tenant-view-field full">
                            <span class="tenant-view-label">
                                Tenant ID
                            </span>

                            <span class="tenant-view-value">
                                <?= (int) $tenantId; ?>
                            </span>
                        </div>

                        <div class="tenant-view-field full">
                            <span class="tenant-view-label">
                                Created At
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewFormatDate(
                                        $tenant['created_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>

                        <div class="tenant-view-field full">
                            <span class="tenant-view-label">
                                Updated At
                            </span>

                            <span class="tenant-view-value">
                                <?= tenantViewEscape(
                                    tenantViewFormatDate(
                                        $tenant['updated_at'],
                                        true
                                    )
                                ); ?>
                            </span>
                        </div>

                    </div>
                </div>
            </article>

        </aside>

    </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
