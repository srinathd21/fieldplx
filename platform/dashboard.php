
<?php
/**
 * FieldPlx Platform Dashboard
 *
 * File:
 * platform/dashboard.php
 *
 * Compatible with:
 * - PHP 7.2
 * - MySQLi
 * - platform_users authentication
 */

require_once __DIR__ . '/includes/auth.php';

/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

$pageTitle = 'Platform Dashboard - FieldPlx';
$activePage = 'dashboard';
$basePath = '';

$currentPlatformUser = platformAuthUser();

/*
|--------------------------------------------------------------------------
| Dashboard helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('platformDashboardEscape')) {
    function platformDashboardEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('platformDashboardTableExists')) {
    function platformDashboardTableExists(mysqli $conn, $tableName)
    {
        $tableName = trim((string) $tableName);

        if ($tableName === '') {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $tableName);

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return !empty($row['total']);
    }
}

if (!function_exists('platformDashboardColumnExists')) {
    function platformDashboardColumnExists(
        mysqli $conn,
        $tableName,
        $columnName
    ) {
        $tableName = trim((string) $tableName);
        $columnName = trim((string) $columnName);

        if ($tableName === '' || $columnName === '') {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ss',
            $tableName,
            $columnName
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return !empty($row['total']);
    }
}

if (!function_exists('platformDashboardScalar')) {
    function platformDashboardScalar(mysqli $conn, $sql)
    {
        $result = $conn->query($sql);

        if (!$result) {
            error_log(
                'Platform dashboard scalar query failed: ' .
                $conn->error
            );

            return 0;
        }

        $row = $result->fetch_row();
        $result->free();

        return isset($row[0])
            ? (float) $row[0]
            : 0;
    }
}

if (!function_exists('platformDashboardFormatNumber')) {
    function platformDashboardFormatNumber($number)
    {
        return number_format((float) $number, 0);
    }
}

if (!function_exists('platformDashboardFormatDate')) {
    function platformDashboardFormatDate($dateTime)
    {
        if (empty($dateTime)) {
            return '—';
        }

        $timestamp = strtotime((string) $dateTime);

        if ($timestamp === false) {
            return '—';
        }

        return date('d M Y, h:i A', $timestamp);
    }
}

if (!function_exists('platformDashboardTimeAgo')) {
    function platformDashboardTimeAgo($dateTime)
    {
        if (empty($dateTime)) {
            return '—';
        }

        $timestamp = strtotime((string) $dateTime);

        if ($timestamp === false) {
            return '—';
        }

        $difference = time() - $timestamp;

        if ($difference < 0) {
            return platformDashboardFormatDate($dateTime);
        }

        if ($difference < 60) {
            return 'Just now';
        }

        if ($difference < 3600) {
            $minutes = (int) floor($difference / 60);

            return $minutes . ' minute' .
                ($minutes !== 1 ? 's' : '') .
                ' ago';
        }

        if ($difference < 86400) {
            $hours = (int) floor($difference / 3600);

            return $hours . ' hour' .
                ($hours !== 1 ? 's' : '') .
                ' ago';
        }

        if ($difference < 604800) {
            $days = (int) floor($difference / 86400);

            return $days . ' day' .
                ($days !== 1 ? 's' : '') .
                ' ago';
        }

        return date('d M Y', $timestamp);
    }
}

if (!function_exists('platformDashboardStatusClass')) {
    function platformDashboardStatusClass($status)
    {
        $status = strtolower(trim((string) $status));

        switch ($status) {
            case 'active':
            case 'paid':
            case 'completed':
                return 'success';

            case 'trial':
            case 'pending':
            case 'pending_approval':
                return 'warning';

            case 'suspended':
            case 'cancelled':
            case 'expired':
            case 'inactive':
                return 'danger';

            default:
                return 'secondary';
        }
    }
}

if (!function_exists('platformDashboardStatusLabel')) {
    function platformDashboardStatusLabel($status)
    {
        return ucwords(
            str_replace(
                array('_', '-'),
                ' ',
                trim((string) $status)
            )
        );
    }
}

/*
|--------------------------------------------------------------------------
| Detect available platform tables and columns
|--------------------------------------------------------------------------
*/

$hasTenantsTable = platformDashboardTableExists(
    $conn,
    'tenants'
);

$hasPlatformUsersTable = platformDashboardTableExists(
    $conn,
    'platform_users'
);

$hasSubscriptionsTable = platformDashboardTableExists(
    $conn,
    'subscriptions'
);

$hasPlansTable = platformDashboardTableExists(
    $conn,
    'plans'
);

$hasActivityLogsTable = platformDashboardTableExists(
    $conn,
    'platform_activity_logs'
);

if (!$hasActivityLogsTable) {
    $hasActivityLogsTable = platformDashboardTableExists(
        $conn,
        'activity_logs'
    );
}

$activityLogsTable = platformDashboardTableExists(
    $conn,
    'platform_activity_logs'
)
    ? 'platform_activity_logs'
    : 'activity_logs';

/*
|--------------------------------------------------------------------------
| Dashboard totals
|--------------------------------------------------------------------------
*/

$totalTenants = 0;
$activeTenants = 0;
$trialTenants = 0;
$pendingTenants = 0;
$suspendedTenants = 0;

$totalPlatformUsers = 0;
$activePlatformUsers = 0;

$totalSubscriptions = 0;
$activeSubscriptions = 0;
$expiringSubscriptions = 0;

if ($hasTenantsTable) {
    $tenantWhere = '';

    if (
        platformDashboardColumnExists(
            $conn,
            'tenants',
            'deleted_at'
        )
    ) {
        $tenantWhere = ' WHERE deleted_at IS NULL';
    }

    $totalTenants = (int) platformDashboardScalar(
        $conn,
        "SELECT COUNT(*) FROM tenants" . $tenantWhere
    );

    if (
        platformDashboardColumnExists(
            $conn,
            'tenants',
            'status'
        )
    ) {
        $conditionPrefix = $tenantWhere === ''
            ? ' WHERE '
            : ' AND ';

        $activeTenants = (int) platformDashboardScalar(
            $conn,
            "SELECT COUNT(*)
             FROM tenants
             {$tenantWhere}
             {$conditionPrefix} status = 'active'"
        );

        $trialTenants = (int) platformDashboardScalar(
            $conn,
            "SELECT COUNT(*)
             FROM tenants
             {$tenantWhere}
             {$conditionPrefix} status = 'trial'"
        );

        $pendingTenants = (int) platformDashboardScalar(
            $conn,
            "SELECT COUNT(*)
             FROM tenants
             {$tenantWhere}
             {$conditionPrefix} status IN (
                 'pending',
                 'pending_approval'
             )"
        );

        $suspendedTenants = (int) platformDashboardScalar(
            $conn,
            "SELECT COUNT(*)
             FROM tenants
             {$tenantWhere}
             {$conditionPrefix} status = 'suspended'"
        );
    }
}

if ($hasPlatformUsersTable) {
    $platformUserWhere = '';

    if (
        platformDashboardColumnExists(
            $conn,
            'platform_users',
            'deleted_at'
        )
    ) {
        $platformUserWhere =
            ' WHERE deleted_at IS NULL';
    }

    $totalPlatformUsers = (int) platformDashboardScalar(
        $conn,
        "SELECT COUNT(*)
         FROM platform_users
         {$platformUserWhere}"
    );

    if (
        platformDashboardColumnExists(
            $conn,
            'platform_users',
            'status'
        )
    ) {
        $conditionPrefix = $platformUserWhere === ''
            ? ' WHERE '
            : ' AND ';

        $activePlatformUsers =
            (int) platformDashboardScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM platform_users
                 {$platformUserWhere}
                 {$conditionPrefix} status = 'active'"
            );
    }
}

if ($hasSubscriptionsTable) {
    $subscriptionWhere = '';

    if (
        platformDashboardColumnExists(
            $conn,
            'subscriptions',
            'deleted_at'
        )
    ) {
        $subscriptionWhere =
            ' WHERE deleted_at IS NULL';
    }

    $totalSubscriptions =
        (int) platformDashboardScalar(
            $conn,
            "SELECT COUNT(*)
             FROM subscriptions
             {$subscriptionWhere}"
        );

    if (
        platformDashboardColumnExists(
            $conn,
            'subscriptions',
            'status'
        )
    ) {
        $conditionPrefix = $subscriptionWhere === ''
            ? ' WHERE '
            : ' AND ';

        $activeSubscriptions =
            (int) platformDashboardScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM subscriptions
                 {$subscriptionWhere}
                 {$conditionPrefix} status = 'active'"
            );
    }

    $subscriptionEndColumn = '';

    if (
        platformDashboardColumnExists(
            $conn,
            'subscriptions',
            'ends_at'
        )
    ) {
        $subscriptionEndColumn = 'ends_at';
    } elseif (
        platformDashboardColumnExists(
            $conn,
            'subscriptions',
            'end_date'
        )
    ) {
        $subscriptionEndColumn = 'end_date';
    } elseif (
        platformDashboardColumnExists(
            $conn,
            'subscriptions',
            'expires_at'
        )
    ) {
        $subscriptionEndColumn = 'expires_at';
    }

    if ($subscriptionEndColumn !== '') {
        $conditionPrefix = $subscriptionWhere === ''
            ? ' WHERE '
            : ' AND ';

        $expiringSubscriptions =
            (int) platformDashboardScalar(
                $conn,
                "SELECT COUNT(*)
                 FROM subscriptions
                 {$subscriptionWhere}
                 {$conditionPrefix}
                 {$subscriptionEndColumn} >= CURDATE()
                 AND {$subscriptionEndColumn}
                     < DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
            );
    }
}

/*
|--------------------------------------------------------------------------
| Recent tenants
|--------------------------------------------------------------------------
*/

$recentTenants = array();

if ($hasTenantsTable) {
    $tenantIdColumn = platformDashboardColumnExists(
        $conn,
        'tenants',
        'id'
    )
        ? 'id'
        : null;

    $tenantNameColumn = null;

    $tenantNameCandidates = array(
        'company_name',
        'business_name',
        'name',
        'tenant_name'
    );

    foreach ($tenantNameCandidates as $candidate) {
        if (
            platformDashboardColumnExists(
                $conn,
                'tenants',
                $candidate
            )
        ) {
            $tenantNameColumn = $candidate;
            break;
        }
    }

    $tenantEmailColumn = null;

    $tenantEmailCandidates = array(
        'email',
        'contact_email',
        'billing_email'
    );

    foreach ($tenantEmailCandidates as $candidate) {
        if (
            platformDashboardColumnExists(
                $conn,
                'tenants',
                $candidate
            )
        ) {
            $tenantEmailColumn = $candidate;
            break;
        }
    }

    $tenantStatusColumn = platformDashboardColumnExists(
        $conn,
        'tenants',
        'status'
    )
        ? 'status'
        : null;

    $tenantCreatedColumn = platformDashboardColumnExists(
        $conn,
        'tenants',
        'created_at'
    )
        ? 'created_at'
        : null;

    if ($tenantIdColumn && $tenantNameColumn) {
        $selectColumns = array(
            "`{$tenantIdColumn}` AS tenant_id",
            "`{$tenantNameColumn}` AS tenant_name"
        );

        $selectColumns[] = $tenantEmailColumn
            ? "`{$tenantEmailColumn}` AS tenant_email"
            : "'' AS tenant_email";

        $selectColumns[] = $tenantStatusColumn
            ? "`{$tenantStatusColumn}` AS tenant_status"
            : "'active' AS tenant_status";

        $selectColumns[] = $tenantCreatedColumn
            ? "`{$tenantCreatedColumn}` AS tenant_created_at"
            : "NULL AS tenant_created_at";

        $whereSql = '';

        if (
            platformDashboardColumnExists(
                $conn,
                'tenants',
                'deleted_at'
            )
        ) {
            $whereSql =
                ' WHERE deleted_at IS NULL';
        }

        $orderSql = $tenantCreatedColumn
            ? " ORDER BY `{$tenantCreatedColumn}` DESC"
            : " ORDER BY `{$tenantIdColumn}` DESC";

        $recentTenantSql = "
            SELECT
                " . implode(', ', $selectColumns) . "
            FROM tenants
            {$whereSql}
            {$orderSql}
            LIMIT 6
        ";

        $recentTenantResult = $conn->query(
            $recentTenantSql
        );

        if ($recentTenantResult) {
            while (
                $tenantRow =
                    $recentTenantResult->fetch_assoc()
            ) {
                $recentTenants[] = $tenantRow;
            }

            $recentTenantResult->free();
        } else {
            error_log(
                'Recent tenant query failed: ' .
                $conn->error
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Recent platform users
|--------------------------------------------------------------------------
*/

$recentPlatformUsers = array();

if ($hasPlatformUsersTable) {
    $recentUserResult = $conn->query("
        SELECT
            id,
            first_name,
            last_name,
            email,
            role_code,
            status,
            last_login_at,
            created_at
        FROM platform_users
        WHERE deleted_at IS NULL
        ORDER BY created_at DESC
        LIMIT 5
    ");

    if ($recentUserResult) {
        while (
            $platformUserRow =
                $recentUserResult->fetch_assoc()
        ) {
            $recentPlatformUsers[] =
                $platformUserRow;
        }

        $recentUserResult->free();
    } else {
        error_log(
            'Recent platform users query failed: ' .
            $conn->error
        );
    }
}

/*
|--------------------------------------------------------------------------
| Dashboard completion percentage
|--------------------------------------------------------------------------
*/

$activePercentage = $totalTenants > 0
    ? round(
        ($activeTenants / $totalTenants) * 100
    )
    : 0;

$trialPercentage = $totalTenants > 0
    ? round(
        ($trialTenants / $totalTenants) * 100
    )
    : 0;

$pendingPercentage = $totalTenants > 0
    ? round(
        ($pendingTenants / $totalTenants) * 100
    )
    : 0;

$suspendedPercentage = $totalTenants > 0
    ? round(
        ($suspendedTenants / $totalTenants) * 100
    )
    : 0;

/*
|--------------------------------------------------------------------------
| Load platform layout
|--------------------------------------------------------------------------
*/

require __DIR__ . '/includes/topbar.php';
?>

<style>
    .platform-dashboard {
        display: grid;
        gap: 18px;
    }

    .dashboard-welcome {
        padding: 21px 23px;
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        gap: 20px;
        border: 1px solid #ddd6fe;
        border-radius: 15px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #312e81
            );
        color: #ffffff;
    }

    .dashboard-welcome::after {
        width: 260px;
        height: 260px;
        position: absolute;
        top: -130px;
        right: -70px;
        content: "";
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.06);
    }

    .dashboard-welcome-content {
        min-width: 0;
        position: relative;
        z-index: 2;
        flex: 1;
    }

    .dashboard-welcome-title {
        margin: 0;
        font-size: 20px;
        font-weight: 800;
    }

    .dashboard-welcome-text {
        max-width: 700px;
        margin: 7px 0 0;
        color: #d8d8ec;
        font-size: 11px;
        line-height: 1.65;
    }

    .dashboard-welcome-actions {
        position: relative;
        z-index: 2;
        display: flex;
        gap: 8px;
    }

    .dashboard-welcome-button {
        min-height: 37px;
        padding: 8px 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 9px;
        background: rgba(255, 255, 255, 0.10);
        color: #ffffff;
        font-size: 10px;
        font-weight: 600;
        text-decoration: none;
    }

    .dashboard-welcome-button:hover {
        background: rgba(255, 255, 255, 0.18);
        color: #ffffff;
    }

    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 13px;
    }

    .dashboard-stat-card {
        padding: 16px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        border: 1px solid #e5e7eb;
        border-radius: 13px;
        background: #ffffff;
        box-shadow:
            0 6px 20px rgba(31, 41, 55, 0.04);
    }

    .dashboard-stat-icon {
        width: 41px;
        height: 41px;
        flex: 0 0 41px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 11px;
        font-size: 17px;
    }

    .dashboard-stat-icon.purple {
        background: #f3e8ff;
        color: #7c3aed;
    }

    .dashboard-stat-icon.green {
        background: #ecfdf5;
        color: #059669;
    }

    .dashboard-stat-icon.orange {
        background: #fff7ed;
        color: #d97706;
    }

    .dashboard-stat-icon.blue {
        background: #eff6ff;
        color: #2563eb;
    }

    .dashboard-stat-content {
        min-width: 0;
        flex: 1;
    }

    .dashboard-stat-label {
        color: #6b7280;
        font-size: 9px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .dashboard-stat-value {
        margin-top: 5px;
        color: #111827;
        font-size: 22px;
        font-weight: 800;
        line-height: 1;
    }

    .dashboard-stat-note {
        margin-top: 6px;
        color: #9ca3af;
        font-size: 9px;
    }

    .dashboard-grid {
        display: grid;
        grid-template-columns:
            minmax(0, 1.55fr)
            minmax(300px, 0.75fr);
        gap: 15px;
    }

    .dashboard-card {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 13px;
        background: #ffffff;
        box-shadow:
            0 6px 20px rgba(31, 41, 55, 0.035);
    }

    .dashboard-card-header {
        min-height: 57px;
        padding: 13px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 13px;
        border-bottom: 1px solid #f0f1f3;
    }

    .dashboard-card-title {
        margin: 0;
        color: #111827;
        font-size: 12px;
        font-weight: 700;
    }

    .dashboard-card-subtitle {
        margin-top: 3px;
        color: #9ca3af;
        font-size: 9px;
    }

    .dashboard-card-link {
        color: #7c3aed;
        font-size: 9px;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
    }

    .dashboard-card-link:hover {
        color: #5b21b6;
    }

    .dashboard-table-wrap {
        overflow-x: auto;
    }

    .dashboard-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .dashboard-table th {
        padding: 10px 14px;
        border-bottom: 1px solid #eef0f3;
        background: #fafafa;
        color: #6b7280;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.45px;
        white-space: nowrap;
    }

    .dashboard-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #f2f3f5;
        color: #374151;
        font-size: 10px;
        vertical-align: middle;
    }

    .dashboard-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .dashboard-table tbody tr:hover {
        background: #fcfbff;
    }

    .dashboard-tenant-name {
        color: #111827;
        font-size: 10px;
        font-weight: 700;
    }

    .dashboard-tenant-email {
        margin-top: 2px;
        color: #9ca3af;
        font-size: 8px;
    }

    .dashboard-status {
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 700;
    }

    .dashboard-status.success {
        background: #ecfdf5;
        color: #047857;
    }

    .dashboard-status.warning {
        background: #fff7ed;
        color: #b45309;
    }

    .dashboard-status.danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .dashboard-status.secondary {
        background: #f3f4f6;
        color: #4b5563;
    }

    .dashboard-view-button {
        width: 29px;
        height: 29px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #ffffff;
        color: #6b7280;
        text-decoration: none;
        font-size: 12px;
    }

    .dashboard-view-button:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
        color: #7c3aed;
    }

    .dashboard-breakdown {
        padding: 15px 16px;
        display: grid;
        gap: 15px;
    }

    .dashboard-breakdown-row {
        display: grid;
        gap: 6px;
    }

    .dashboard-breakdown-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .dashboard-breakdown-label {
        color: #4b5563;
        font-size: 9px;
        font-weight: 600;
    }

    .dashboard-breakdown-value {
        color: #111827;
        font-size: 9px;
        font-weight: 700;
    }

    .dashboard-progress {
        height: 7px;
        overflow: hidden;
        border-radius: 999px;
        background: #f1f2f4;
    }

    .dashboard-progress-bar {
        height: 100%;
        border-radius: inherit;
    }

    .dashboard-progress-bar.green {
        background: #10b981;
    }

    .dashboard-progress-bar.blue {
        background: #3b82f6;
    }

    .dashboard-progress-bar.orange {
        background: #f59e0b;
    }

    .dashboard-progress-bar.red {
        background: #ef4444;
    }

    .dashboard-quick-actions {
        padding: 15px;
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 9px;
    }

    .dashboard-quick-action {
        min-height: 78px;
        padding: 12px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: center;
        gap: 7px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #ffffff;
        color: #374151;
        text-decoration: none;
    }

    .dashboard-quick-action:hover {
        border-color: #ddd6fe;
        background: #faf8ff;
        color: #6d28d9;
    }

    .dashboard-quick-action i {
        font-size: 17px;
    }

    .dashboard-quick-action span {
        font-size: 9px;
        font-weight: 600;
    }

    .dashboard-user-list {
        display: grid;
    }

    .dashboard-user-item {
        padding: 12px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #f1f2f4;
    }

    .dashboard-user-item:last-child {
        border-bottom: 0;
    }

    .dashboard-user-avatar {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background:
            linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
        color: #ffffff;
        font-size: 9px;
        font-weight: 700;
    }

    .dashboard-user-content {
        min-width: 0;
        flex: 1;
    }

    .dashboard-user-name {
        overflow: hidden;
        display: block;
        color: #111827;
        font-size: 10px;
        font-weight: 700;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .dashboard-user-role {
        margin-top: 2px;
        display: block;
        color: #9ca3af;
        font-size: 8px;
    }

    .dashboard-user-login {
        color: #9ca3af;
        font-size: 8px;
        white-space: nowrap;
    }

    .dashboard-empty {
        padding: 34px 18px;
        color: #9ca3af;
        text-align: center;
        font-size: 10px;
    }

    .dashboard-empty i {
        margin-bottom: 8px;
        display: block;
        color: #c4b5fd;
        font-size: 27px;
    }

    @media (max-width: 1199.98px) {
        .dashboard-stats {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 991.98px) {
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 767.98px) {
        .dashboard-welcome {
            padding: 18px;
            align-items: flex-start;
            flex-direction: column;
        }

        .dashboard-welcome-actions {
            width: 100%;
        }

        .dashboard-welcome-button {
            flex: 1;
        }

        .dashboard-stats {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .dashboard-quick-actions {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="platform-dashboard">

    <section class="dashboard-welcome">
        <div class="dashboard-welcome-content">
            <h2 class="dashboard-welcome-title">
                Welcome back,
                <?= platformDashboardEscape(
                    !empty($currentPlatformUser['first_name'])
                        ? $currentPlatformUser['first_name']
                        : $currentPlatformUser['name']
                ); ?>
            </h2>

            <p class="dashboard-welcome-text">
                Review tenant activity, subscription status,
                platform users and operational alerts from the
                FieldPlx platform control centre.
            </p>
        </div>

        <div class="dashboard-welcome-actions">
            <?php if (canManagePlatformTenants()): ?>
                <a
                    href="tenant-add.php"
                    class="dashboard-welcome-button"
                >
                    <i class="bi bi-plus-circle"></i>
                    Add Tenant
                </a>
            <?php endif; ?>

            <a
                href="tenants.php"
                class="dashboard-welcome-button"
            >
                <i class="bi bi-buildings"></i>
                View Tenants
            </a>
        </div>
    </section>

    <section class="dashboard-stats">

        <article class="dashboard-stat-card">
            <span class="dashboard-stat-icon purple">
                <i class="bi bi-buildings"></i>
            </span>

            <div class="dashboard-stat-content">
                <div class="dashboard-stat-label">
                    Total Tenants
                </div>

                <div class="dashboard-stat-value">
                    <?= platformDashboardFormatNumber(
                        $totalTenants
                    ); ?>
                </div>

                <div class="dashboard-stat-note">
                    <?= platformDashboardFormatNumber(
                        $activeTenants
                    ); ?>
                    active workspaces
                </div>
            </div>
        </article>

        <article class="dashboard-stat-card">
            <span class="dashboard-stat-icon green">
                <i class="bi bi-check2-circle"></i>
            </span>

            <div class="dashboard-stat-content">
                <div class="dashboard-stat-label">
                    Active Tenants
                </div>

                <div class="dashboard-stat-value">
                    <?= platformDashboardFormatNumber(
                        $activeTenants
                    ); ?>
                </div>

                <div class="dashboard-stat-note">
                    <?= (int) $activePercentage; ?>%
                    of all tenants
                </div>
            </div>
        </article>

        <article class="dashboard-stat-card">
            <span class="dashboard-stat-icon orange">
                <i class="bi bi-clock-history"></i>
            </span>

            <div class="dashboard-stat-content">
                <div class="dashboard-stat-label">
                    Trial / Pending
                </div>

                <div class="dashboard-stat-value">
                    <?= platformDashboardFormatNumber(
                        $trialTenants +
                        $pendingTenants
                    ); ?>
                </div>

                <div class="dashboard-stat-note">
                    <?= platformDashboardFormatNumber(
                        $trialTenants
                    ); ?>
                    trial,
                    <?= platformDashboardFormatNumber(
                        $pendingTenants
                    ); ?>
                    pending
                </div>
            </div>
        </article>

        <article class="dashboard-stat-card">
            <span class="dashboard-stat-icon blue">
                <i class="bi bi-people"></i>
            </span>

            <div class="dashboard-stat-content">
                <div class="dashboard-stat-label">
                    Platform Users
                </div>

                <div class="dashboard-stat-value">
                    <?= platformDashboardFormatNumber(
                        $totalPlatformUsers
                    ); ?>
                </div>

                <div class="dashboard-stat-note">
                    <?= platformDashboardFormatNumber(
                        $activePlatformUsers
                    ); ?>
                    active administrators
                </div>
            </div>
        </article>

    </section>

    <section class="dashboard-grid">

        <article class="dashboard-card">
            <div class="dashboard-card-header">
                <div>
                    <h3 class="dashboard-card-title">
                        Recent Tenants
                    </h3>

                    <div class="dashboard-card-subtitle">
                        Newly created tenant workspaces
                    </div>
                </div>

                <a
                    href="tenants.php"
                    class="dashboard-card-link"
                >
                    View all tenants
                    <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <?php if (empty($recentTenants)): ?>
                <div class="dashboard-empty">
                    <i class="bi bi-buildings"></i>
                    No tenant records are available.
                </div>
            <?php else: ?>
                <div class="dashboard-table-wrap">
                    <table class="dashboard-table">
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th style="width:55px;"></th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach (
                                $recentTenants as $tenant
                            ): ?>
                                <tr>
                                    <td>
                                        <div class="dashboard-tenant-name">
                                            <?= platformDashboardEscape(
                                                $tenant['tenant_name']
                                            ); ?>
                                        </div>

                                        <?php if (
                                            !empty(
                                                $tenant['tenant_email']
                                            )
                                        ): ?>
                                            <div class="dashboard-tenant-email">
                                                <?= platformDashboardEscape(
                                                    $tenant['tenant_email']
                                                ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span
                                            class="dashboard-status <?= platformDashboardEscape(
                                                platformDashboardStatusClass(
                                                    $tenant['tenant_status']
                                                )
                                            ); ?>"
                                        >
                                            <?= platformDashboardEscape(
                                                platformDashboardStatusLabel(
                                                    $tenant['tenant_status']
                                                )
                                            ); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= platformDashboardEscape(
                                            platformDashboardTimeAgo(
                                                $tenant[
                                                    'tenant_created_at'
                                                ]
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <a
                                            href="tenant-view.php?id=<?= (int) $tenant['tenant_id']; ?>"
                                            class="dashboard-view-button"
                                            title="View tenant"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <div class="d-grid gap-3">

            <article class="dashboard-card">
                <div class="dashboard-card-header">
                    <div>
                        <h3 class="dashboard-card-title">
                            Tenant Distribution
                        </h3>

                        <div class="dashboard-card-subtitle">
                            Current workspace status
                        </div>
                    </div>
                </div>

                <div class="dashboard-breakdown">

                    <div class="dashboard-breakdown-row">
                        <div class="dashboard-breakdown-header">
                            <span class="dashboard-breakdown-label">
                                Active
                            </span>

                            <span class="dashboard-breakdown-value">
                                <?= (int) $activeTenants; ?>
                            </span>
                        </div>

                        <div class="dashboard-progress">
                            <div
                                class="dashboard-progress-bar green"
                                style="width:<?= (int) $activePercentage; ?>%;"
                            ></div>
                        </div>
                    </div>

                    <div class="dashboard-breakdown-row">
                        <div class="dashboard-breakdown-header">
                            <span class="dashboard-breakdown-label">
                                Trial
                            </span>

                            <span class="dashboard-breakdown-value">
                                <?= (int) $trialTenants; ?>
                            </span>
                        </div>

                        <div class="dashboard-progress">
                            <div
                                class="dashboard-progress-bar blue"
                                style="width:<?= (int) $trialPercentage; ?>%;"
                            ></div>
                        </div>
                    </div>

                    <div class="dashboard-breakdown-row">
                        <div class="dashboard-breakdown-header">
                            <span class="dashboard-breakdown-label">
                                Pending
                            </span>

                            <span class="dashboard-breakdown-value">
                                <?= (int) $pendingTenants; ?>
                            </span>
                        </div>

                        <div class="dashboard-progress">
                            <div
                                class="dashboard-progress-bar orange"
                                style="width:<?= (int) $pendingPercentage; ?>%;"
                            ></div>
                        </div>
                    </div>

                    <div class="dashboard-breakdown-row">
                        <div class="dashboard-breakdown-header">
                            <span class="dashboard-breakdown-label">
                                Suspended
                            </span>

                            <span class="dashboard-breakdown-value">
                                <?= (int) $suspendedTenants; ?>
                            </span>
                        </div>

                        <div class="dashboard-progress">
                            <div
                                class="dashboard-progress-bar red"
                                style="width:<?= (int) $suspendedPercentage; ?>%;"
                            ></div>
                        </div>
                    </div>

                </div>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card-header">
                    <div>
                        <h3 class="dashboard-card-title">
                            Quick Actions
                        </h3>

                        <div class="dashboard-card-subtitle">
                            Common platform tasks
                        </div>
                    </div>
                </div>

                <div class="dashboard-quick-actions">

                    <?php if (canManagePlatformTenants()): ?>
                        <a
                            href="tenant-add.php"
                            class="dashboard-quick-action"
                        >
                            <i class="bi bi-building-add"></i>
                            <span>Create Tenant</span>
                        </a>
                    <?php endif; ?>

                    <a
                        href="tenants.php"
                        class="dashboard-quick-action"
                    >
                        <i class="bi bi-buildings"></i>
                        <span>Manage Tenants</span>
                    </a>

                    <?php if (
                        hasPlatformRole(
                            array(
                                'super_admin',
                                'platform_admin',
                                'billing_admin'
                            )
                        )
                    ): ?>
                        <a
                            href="subscriptions.php"
                            class="dashboard-quick-action"
                        >
                            <i class="bi bi-credit-card"></i>
                            <span>Subscriptions</span>
                        </a>
                    <?php endif; ?>

                    <?php if (
                        hasPlatformRole(
                            array(
                                'super_admin',
                                'platform_admin'
                            )
                        )
                    ): ?>
                        <a
                            href="platform-users.php"
                            class="dashboard-quick-action"
                        >
                            <i class="bi bi-people"></i>
                            <span>Platform Users</span>
                        </a>
                    <?php endif; ?>

                </div>
            </article>

        </div>

    </section>

    <section class="dashboard-grid">

        <article class="dashboard-card">
            <div class="dashboard-card-header">
                <div>
                    <h3 class="dashboard-card-title">
                        Platform Users
                    </h3>

                    <div class="dashboard-card-subtitle">
                        Recently created administrator accounts
                    </div>
                </div>

                <a
                    href="platform-users.php"
                    class="dashboard-card-link"
                >
                    Manage users
                    <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <?php if (empty($recentPlatformUsers)): ?>
                <div class="dashboard-empty">
                    <i class="bi bi-people"></i>
                    No platform users are available.
                </div>
            <?php else: ?>
                <div class="dashboard-user-list">
                    <?php foreach (
                        $recentPlatformUsers as $dashboardUser
                    ): ?>
                        <?php
                        $dashboardUserName = trim(
                            (string) $dashboardUser['first_name'] .
                            ' ' .
                            (string) $dashboardUser['last_name']
                        );

                        if ($dashboardUserName === '') {
                            $dashboardUserName =
                                $dashboardUser['email'];
                        }

                        $dashboardUserInitials = strtoupper(
                            substr(
                                trim(
                                    (string) $dashboardUser[
                                        'first_name'
                                    ]
                                ),
                                0,
                                1
                            ) .
                            substr(
                                trim(
                                    (string) $dashboardUser[
                                        'last_name'
                                    ]
                                ),
                                0,
                                1
                            )
                        );

                        if ($dashboardUserInitials === '') {
                            $dashboardUserInitials = 'PA';
                        }
                        ?>

                        <div class="dashboard-user-item">
                            <span class="dashboard-user-avatar">
                                <?= platformDashboardEscape(
                                    $dashboardUserInitials
                                ); ?>
                            </span>

                            <span class="dashboard-user-content">
                                <span class="dashboard-user-name">
                                    <?= platformDashboardEscape(
                                        $dashboardUserName
                                    ); ?>
                                </span>

                                <span class="dashboard-user-role">
                                    <?= platformDashboardEscape(
                                        platformDashboardStatusLabel(
                                            $dashboardUser[
                                                'role_code'
                                            ]
                                        )
                                    ); ?>
                                    ·
                                    <?= platformDashboardEscape(
                                        $dashboardUser['email']
                                    ); ?>
                                </span>
                            </span>

                            <span class="dashboard-user-login">
                                <?= platformDashboardEscape(
                                    !empty(
                                        $dashboardUser[
                                            'last_login_at'
                                        ]
                                    )
                                        ? platformDashboardTimeAgo(
                                            $dashboardUser[
                                                'last_login_at'
                                            ]
                                        )
                                        : 'Never logged in'
                                ); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="dashboard-card">
            <div class="dashboard-card-header">
                <div>
                    <h3 class="dashboard-card-title">
                        Subscription Summary
                    </h3>

                    <div class="dashboard-card-subtitle">
                        Current subscription overview
                    </div>
                </div>

                <a
                    href="subscriptions.php"
                    class="dashboard-card-link"
                >
                    View subscriptions
                </a>
            </div>

            <div class="dashboard-breakdown">

                <div class="dashboard-breakdown-row">
                    <div class="dashboard-breakdown-header">
                        <span class="dashboard-breakdown-label">
                            Total subscriptions
                        </span>

                        <span class="dashboard-breakdown-value">
                            <?= platformDashboardFormatNumber(
                                $totalSubscriptions
                            ); ?>
                        </span>
                    </div>
                </div>

                <div class="dashboard-breakdown-row">
                    <div class="dashboard-breakdown-header">
                        <span class="dashboard-breakdown-label">
                            Active subscriptions
                        </span>

                        <span class="dashboard-breakdown-value">
                            <?= platformDashboardFormatNumber(
                                $activeSubscriptions
                            ); ?>
                        </span>
                    </div>
                </div>

                <div class="dashboard-breakdown-row">
                    <div class="dashboard-breakdown-header">
                        <span class="dashboard-breakdown-label">
                            Expiring within 30 days
                        </span>

                        <span class="dashboard-breakdown-value">
                            <?= platformDashboardFormatNumber(
                                $expiringSubscriptions
                            ); ?>
                        </span>
                    </div>
                </div>

                <?php if (!$hasSubscriptionsTable): ?>
                    <div
                        class="alert alert-light border mb-0"
                        style="font-size:9px;"
                    >
                        The subscriptions table is not available yet.
                        Subscription values will appear after the
                        billing module is created.
                    </div>
                <?php endif; ?>

            </div>
        </article>

    </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
