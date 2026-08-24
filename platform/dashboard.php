<?php
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Platform Dashboard';
$activePage = 'dashboard';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function dash_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}

function dash_table_exists(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");

    $stmt->execute(array(
        ':table_name' => $table
    ));

    $cache[$table] = ((int)$stmt->fetchColumn() > 0);

    return $cache[$table];
}

function dash_column_exists(PDO $pdo, $table, $column)
{
    static $cache = array();
    $key = $table . '.' . $column;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");

    $stmt->execute(array(
        ':table_name' => $table,
        ':column_name' => $column
    ));

    $cache[$key] = ((int)$stmt->fetchColumn() > 0);

    return $cache[$key];
}

function dash_scalar(PDO $pdo, $sql, array $params = array())
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function dash_time_ago($datetime)
{
    if (!$datetime) {
        return '';
    }

    $time = strtotime($datetime);

    if (!$time) {
        return '';
    }

    $diff = time() - $time;

    if ($diff < 0) {
        return date('d M Y H:i', $time);
    }

    if ($diff < 60) {
        return 'Just now';
    }

    if ($diff < 3600) {
        $minutes = (int)floor($diff / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
    }

    if ($diff < 86400) {
        $hours = (int)floor($diff / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }

    if ($diff < 172800) {
        return 'Yesterday';
    }

    if ($diff < 604800) {
        $days = (int)floor($diff / 86400);
        return $days . ' days ago';
    }

    return date('d M Y', $time);
}

function dash_activity_icon($eventType)
{
    $eventType = strtolower((string)$eventType);

    if (strpos($eventType, 'tenant') !== false) {
        return 'bi bi-building-add';
    }

    if (strpos($eventType, 'payment') !== false) {
        return 'bi bi-credit-card';
    }

    if (
        strpos($eventType, 'subscription') !== false ||
        strpos($eventType, 'renew') !== false ||
        strpos($eventType, 'plan') !== false
    ) {
        return 'bi bi-arrow-repeat';
    }

    if (strpos($eventType, 'user') !== false) {
        return 'bi bi-person-plus';
    }

    if (strpos($eventType, 'job') !== false) {
        return 'bi bi-briefcase';
    }

    if (strpos($eventType, 'quote') !== false) {
        return 'bi bi-file-earmark-text';
    }

    if (strpos($eventType, 'request') !== false) {
        return 'bi bi-inbox';
    }

    return 'bi bi-activity';
}

$tenantHasDeletedAt = dash_column_exists($pdo, 'tenants', 'deleted_at');
$subscriptionHasDeletedAt = dash_column_exists($pdo, 'subscriptions', 'deleted_at');
$userHasDeletedAt = dash_column_exists($pdo, 'users', 'deleted_at');
$platformUserHasDeletedAt = dash_column_exists($pdo, 'platform_users', 'deleted_at');
$hasSubscriptionPayments = dash_table_exists($pdo, 'subscription_payments');
$hasActivityEvents = dash_table_exists($pdo, 'activity_events');

$tenantWhere = $tenantHasDeletedAt ? ' WHERE deleted_at IS NULL ' : '';
$subscriptionWhere = $subscriptionHasDeletedAt ? ' WHERE deleted_at IS NULL ' : '';
$userWhere = $userHasDeletedAt ? ' WHERE deleted_at IS NULL ' : '';
$platformUserWhere = $platformUserHasDeletedAt ? ' WHERE deleted_at IS NULL ' : '';

$totalTenants = (int)dash_scalar(
    $pdo,
    'SELECT COUNT(*) FROM tenants' . $tenantWhere
);

$tenantsThisMonthSql = "
    SELECT COUNT(*)
    FROM tenants
    WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
";
if ($tenantHasDeletedAt) {
    $tenantsThisMonthSql .= ' AND deleted_at IS NULL ';
}
$tenantsThisMonth = (int)dash_scalar($pdo, $tenantsThisMonthSql);

$activeTenantsSql = "SELECT COUNT(*) FROM tenants WHERE status = 'active'";
if ($tenantHasDeletedAt) {
    $activeTenantsSql .= ' AND deleted_at IS NULL ';
}
$activeTenants = (int)dash_scalar($pdo, $activeTenantsSql);

$trialTenantsSql = "SELECT COUNT(*) FROM tenants WHERE status = 'trial'";
if ($tenantHasDeletedAt) {
    $trialTenantsSql .= ' AND deleted_at IS NULL ';
}
$trialTenants = (int)dash_scalar($pdo, $trialTenantsSql);

$trialExpiringWeekSql = "
    SELECT COUNT(*)
    FROM subscriptions
    WHERE status = 'trial'
      AND trial_end_date IS NOT NULL
      AND trial_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
";
if ($subscriptionHasDeletedAt) {
    $trialExpiringWeekSql .= ' AND deleted_at IS NULL ';
}
$trialExpiringWeek = (int)dash_scalar($pdo, $trialExpiringWeekSql);

$totalSubscriptions = (int)dash_scalar(
    $pdo,
    'SELECT COUNT(*) FROM subscriptions' . $subscriptionWhere
);

$activeSubscriptionsSql = "SELECT COUNT(*) FROM subscriptions WHERE status = 'active'";
if ($subscriptionHasDeletedAt) {
    $activeSubscriptionsSql .= ' AND deleted_at IS NULL ';
}
$activeSubscriptions = (int)dash_scalar($pdo, $activeSubscriptionsSql);

$platformUsers = (int)dash_scalar(
    $pdo,
    'SELECT COUNT(*) FROM platform_users' . $platformUserWhere
);

$activePlatformUsersSql = "SELECT COUNT(*) FROM platform_users WHERE status = 'active'";
if ($platformUserHasDeletedAt) {
    $activePlatformUsersSql .= ' AND deleted_at IS NULL ';
}
$activePlatformUsers = (int)dash_scalar($pdo, $activePlatformUsersSql);

$tenantUsers = (int)dash_scalar(
    $pdo,
    'SELECT COUNT(*) FROM users' . $userWhere
);

$attentionExpirySql = "
    SELECT COUNT(*)
    FROM subscriptions
    WHERE status IN ('active','trial','expired')
      AND expiry_date IS NOT NULL
      AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
";
if ($subscriptionHasDeletedAt) {
    $attentionExpirySql .= ' AND deleted_at IS NULL ';
}
$attentionExpiry = (int)dash_scalar($pdo, $attentionExpirySql);

$attentionPayments = 0;
$monthlyRevenueDisplay = '0.00';
$monthlyRevenueMeta = 'No successful subscription payments this month';

if ($hasSubscriptionPayments) {
    $paymentHasDeletedAt = dash_column_exists(
        $pdo,
        'subscription_payments',
        'deleted_at'
    );

    $attentionPaymentSql = "
        SELECT COUNT(*)
        FROM subscription_payments
        WHERE status IN ('pending','failed')
    ";

    if ($paymentHasDeletedAt) {
        $attentionPaymentSql .= ' AND deleted_at IS NULL ';
    }

    $attentionPayments = (int)dash_scalar(
        $pdo,
        $attentionPaymentSql
    );

    $revenueSql = "
        SELECT
            COALESCE(cur.currency_code, '') AS currency_code,
            SUM(sp.amount) AS total_amount
        FROM subscription_payments sp
        LEFT JOIN currencies cur
            ON cur.id = sp.currency_id
        WHERE sp.status = 'succeeded'
          AND sp.payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND sp.payment_date < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
    ";

    if ($paymentHasDeletedAt) {
        $revenueSql .= ' AND sp.deleted_at IS NULL ';
    }

    $revenueSql .= "
        GROUP BY sp.currency_id, cur.currency_code
        ORDER BY total_amount DESC
    ";

    $revenueRows = $pdo->query($revenueSql)->fetchAll();

    if (count($revenueRows) === 1) {
        $monthlyRevenueDisplay =
            ($revenueRows[0]['currency_code'] ?: '') .
            ' ' .
            number_format((float)$revenueRows[0]['total_amount'], 2);

        $monthlyRevenueMeta = 'Successful subscription payments this month';
    } elseif (count($revenueRows) > 1) {
        $monthlyRevenueDisplay = count($revenueRows) . ' currencies';
        $parts = array();

        foreach (array_slice($revenueRows, 0, 3) as $row) {
            $parts[] =
                ($row['currency_code'] ?: '-') .
                ' ' .
                number_format((float)$row['total_amount'], 2);
        }

        $monthlyRevenueMeta = implode(' · ', $parts);
    }
}

$needsAttention = $attentionExpiry + $attentionPayments;
$activePercent = $totalTenants > 0
    ? round(($activeTenants / $totalTenants) * 100)
    : 0;

/* Recent tenants */
$recentTenantSql = "
    SELECT
        t.id,
        t.tenant_code,
        t.display_name,
        t.legal_name,
        t.status,
        t.created_at,
        c.name AS country_name,
        (
            SELECT p2.name
            FROM subscriptions s2
            LEFT JOIN plans p2 ON p2.id = s2.plan_id
            WHERE s2.tenant_id = t.id
";

if ($subscriptionHasDeletedAt) {
    $recentTenantSql .= ' AND s2.deleted_at IS NULL ';
}

$recentTenantSql .= "
            ORDER BY s2.id DESC
            LIMIT 1
        ) AS plan_name,
        (
            SELECT COUNT(*)
            FROM users u2
            WHERE u2.tenant_id = t.id
";

if ($userHasDeletedAt) {
    $recentTenantSql .= ' AND u2.deleted_at IS NULL ';
}

$recentTenantSql .= "
        ) AS user_count
    FROM tenants t
    LEFT JOIN countries c ON c.id = t.country_id
    WHERE 1=1
";

if ($tenantHasDeletedAt) {
    $recentTenantSql .= ' AND t.deleted_at IS NULL ';
}

$recentTenantSql .= "
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 5
";

$recentTenants = $pdo->query($recentTenantSql)->fetchAll();

/* Recent activity */
$recentActivity = array();

if ($hasActivityEvents) {
    $activitySql = "
        SELECT
            ae.id,
            ae.event_type,
            ae.title,
            ae.created_at,
            ae.actor_type,
            ae.tenant_id,
            t.display_name AS tenant_name
        FROM activity_events ae
        LEFT JOIN tenants t ON t.id = ae.tenant_id
        ORDER BY ae.created_at DESC, ae.id DESC
        LIMIT 5
    ";

    $recentActivity = $pdo->query($activitySql)->fetchAll();
}

/* Subscription distribution */
$distributionSql = "
    SELECT
        COALESCE(p.name, 'No Plan') AS plan_name,
        COUNT(*) AS tenant_count
    FROM subscriptions s
    LEFT JOIN plans p ON p.id = s.plan_id
    INNER JOIN (
        SELECT tenant_id, MAX(id) AS latest_subscription_id
        FROM subscriptions
        WHERE status <> 'cancelled'
";

if ($subscriptionHasDeletedAt) {
    $distributionSql .= ' AND deleted_at IS NULL ';
}

$distributionSql .= "
        GROUP BY tenant_id
    ) latest
        ON latest.latest_subscription_id = s.id
    WHERE s.status <> 'cancelled'
";

if ($subscriptionHasDeletedAt) {
    $distributionSql .= ' AND s.deleted_at IS NULL ';
}

$distributionSql .= "
    GROUP BY s.plan_id, p.name
    ORDER BY tenant_count DESC, plan_name ASC
    LIMIT 8
";

$subscriptionDistribution = $pdo->query($distributionSql)->fetchAll();
$distributionTotal = 0;

foreach ($subscriptionDistribution as $row) {
    $distributionTotal += (int)$row['tenant_count'];
}

/* Welcome user */
$welcomeName = '';

if (!empty($_SESSION['platform_user_id'])) {
    $welcomeStmt = $pdo->prepare("
        SELECT first_name, last_name
        FROM platform_users
        WHERE id = :id
        LIMIT 1
    ");

    $welcomeStmt->execute(array(
        ':id' => (int)$_SESSION['platform_user_id']
    ));

    $welcomeUser = $welcomeStmt->fetch();

    if ($welcomeUser) {
        $welcomeName = trim(
            $welcomeUser['first_name'] . ' ' .
            ($welcomeUser['last_name'] ?: '')
        );
    }
}

if ($welcomeName === '') {
    $welcomeName = !empty($_SESSION['platform_user_name'])
        ? (string)$_SESSION['platform_user_name']
        : 'Platform Admin';
}

$todayDay = date('l');
$todayDate = date('d M Y');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle); ?> - FieldPlx</title>

    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <style>
        :root {
            --fp-primary: #12182d;
            --fp-primary-2: #1c2250;
            --fp-primary-3: #201f6b;
            --fp-accent: #8b5cf6;
            --fp-accent-light: #a78bfa;
            --fp-accent-dark: #6d28d9;
            --fp-text: #20213f;
            --fp-muted: #6f6b8f;
            --fp-border: #ded9ef;
            --fp-bg: #f1edff;
            --fp-surface: #ffffff;
            --fp-surface-soft: #f8f6ff;
            --fp-success: #059669;
            --fp-warning: #d97706;
            --fp-danger: #dc2626;
            --fp-info: #6366f1;
            --fp-sidebar-width: 260px;
            --fp-sidebar-collapsed-width: 76px;
            --fp-topbar-height: 66px;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background: #ffffff;
            color: var(--fp-text);
            font-family: "Inter", sans-serif;
            font-size: 13px;
        }

        a {
            text-decoration: none;
        }

        .fp-layout {
            min-height: 100vh;
        }

        .fp-main {
            min-height: calc(100vh - 52px);
            margin-left: var(--fp-sidebar-width);
            transition: margin-left .22s ease;
        }

        body.fp-sidebar-collapsed .fp-main {
            margin-left: var(--fp-sidebar-collapsed-width);
        }

        .fp-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            min-height: var(--fp-topbar-height);
            border-bottom: 1px solid #ded8f3;
            background: rgba(248, 246, 255, .96);
            backdrop-filter: blur(14px);
        }

        .fp-topbar-inner {
            min-height: var(--fp-topbar-height);
            padding: 8px 18px;
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .fp-menu-toggle,
        .fp-icon-button {
            width: 39px;
            height: 39px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d9d2ef;
            border-radius: 10px;
            background: #ffffff;
            color: #39345f;
            font-size: 18px;
            transition: .16s ease;
        }

        .fp-menu-toggle:hover,
        .fp-icon-button:hover {
            border-color: #bda9ff;
            background: #f4f0ff;
            color: var(--fp-accent-dark);
        }

        .fp-page-heading {
            min-width: 0;
            margin-right: auto;
        }

        .fp-page-title {
            margin: 0;
            overflow: hidden;
            color: #17172e;
            font-size: 15px;
            font-weight: 700;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fp-page-subtitle {
            margin-top: 2px;
            color: var(--fp-muted);
            font-size: 10px;
        }

        .fp-search {
            width: min(340px, 31vw);
            position: relative;
        }

        .fp-search i {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(-50%);
            color: #8f88aa;
            font-size: 14px;
        }

        .fp-search input {
            height: 39px;
            padding: 8px 13px 8px 36px;
            border: 1px solid #dcd5ef;
            border-radius: 10px;
            background: #f8f6ff;
            box-shadow: none;
            font-size: 12px;
        }

        .fp-search input:focus {
            border-color: #a78bfa;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, .12);
        }

        .fp-notification-wrap {
            position: relative;
        }

        .fp-notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            border-radius: 999px;
            background: var(--fp-danger);
            color: #fff;
            font-size: 9px;
            font-weight: 700;
        }

        .fp-profile {
            min-width: 0;
            padding: 4px 9px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--fp-border);
            border-radius: 11px;
            background: #fff;
        }

        .fp-avatar {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: linear-gradient(135deg, #6d4df4, #9a5cff);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
        }

        .fp-profile-text {
            max-width: 145px;
            min-width: 0;
        }

        .fp-profile-name,
        .fp-profile-role {
            overflow: hidden;
            display: block;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fp-profile-name {
            color: #111827;
            font-size: 11px;
            font-weight: 700;
        }

        .fp-profile-role {
            margin-top: 1px;
            color: var(--fp-muted);
            font-size: 9px;
        }

        .fp-content {
            padding: 18px;
            background: #ffffff;
        }

        .fp-mobile-brand {
            display: none;
        }

        @media (max-width: 991.98px) {

            .fp-main,
            body.fp-sidebar-collapsed .fp-main {
                margin-left: 0;
            }

            .fp-search {
                display: none;
            }

            .fp-profile-text {
                display: none;
            }

            .fp-mobile-brand {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: #ffffff;
                font-weight: 700;
            }

            .fp-mobile-brand-logo {
                width: 34px;
                height: 34px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 9px;
                background: linear-gradient(135deg, #6d4df4, #9a5cff);
                color: #fff;
                font-size: 13px;
            }
        }

        @media (max-width: 575.98px) {
            .fp-topbar-inner {
                padding: 8px 11px;
            }

            .fp-page-subtitle {
                display: none;
            }

            .fp-page-title {
                font-size: 13px;
            }

            .fp-content {
                padding: 12px;
            }
        }
    </style>

    <style>
        .dashboard-page {
            display: grid;
            gap: 18px;
        }

        .dashboard-welcome {
            padding: 24px 26px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            border: 1px solid rgba(139, 92, 246, .24);
            border-radius: 16px;
            background:
                radial-gradient(circle at 96% 0%, rgba(255, 255, 255, .08), transparent 28%),
                linear-gradient(135deg, #12182d 0%, #20255a 58%, #35317b 100%);
            box-shadow: 0 12px 34px rgba(36, 29, 82, .10);
        }

        .dashboard-welcome h2 {
            margin: 0 0 7px;
            color: #ffffff;
            font-size: 20px;
            font-weight: 800;
        }

        .dashboard-welcome p {
            max-width: 650px;
            margin: 0;
            color: #d9d7ef;
            font-size: 11px;
            line-height: 1.65;
        }

        .dashboard-date {
            min-width: 170px;
            padding: 12px 14px;
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 12px;
            background: rgba(255, 255, 255, .08);
            color: #d8d5f0;
            text-align: center;
            font-size: 10px;
        }

        .dashboard-date strong {
            margin-top: 3px;
            display: block;
            color: #ffffff;
            font-size: 13px;
        }

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .dashboard-stat {
            min-width: 0;
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 13px;
            border: 1px solid #ddd5f1;
            border-radius: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #fbf9ff 100%);
            box-shadow: 0 8px 24px rgba(39, 31, 84, .055);
        }

        .dashboard-stat-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 18px;
        }

        .dashboard-stat-icon.purple {
            background: #ede7ff;
            color: #7c3aed;
        }

        .dashboard-stat-icon.green {
            background: #eee9ff;
            color: #6d4df4;
        }

        .dashboard-stat-icon.orange {
            background: #f2eaff;
            color: #8b5cf6;
        }

        .dashboard-stat-icon.blue {
            background: #e8e7ff;
            color: #5757d9;
        }

        .dashboard-stat-icon.red {
            background: #f0e8ff;
            color: #7c3aed;
        }

        .dashboard-stat-icon.cyan {
            background: #e9e8ff;
            color: #5956c8;
        }

        .dashboard-stat-icon.indigo {
            background: #e7e3ff;
            color: #5b50c8;
        }

        .dashboard-stat-icon.gray {
            background: #eeeaf8;
            color: #615a7d;
        }

        .dashboard-stat-label {
            color: #9ca3af;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .dashboard-stat-value {
            margin-top: 3px;
            color: #111827;
            font-size: 22px;
            font-weight: 800;
        }

        .dashboard-stat-meta {
            margin-top: 2px;
            color: #6b7280;
            font-size: 9px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(300px, .85fr);
            gap: 16px;
        }

        .dashboard-card {
            overflow: hidden;
            border: 1px solid #ded7ef;
            border-radius: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfaff 100%);
            box-shadow: 0 10px 28px rgba(37, 29, 80, .06);
        }

        .dashboard-card-header {
            min-height: 52px;
            padding: 12px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid #ece7f7;
            background: #fbf9ff;
        }

        .dashboard-card-title {
            margin: 0;
            color: #111827;
            font-size: 12px;
            font-weight: 700;
        }

        .dashboard-card-subtitle {
            margin-top: 2px;
            color: #9ca3af;
            font-size: 9px;
        }

        .dashboard-card-link {
            color: #7c3aed;
            font-size: 9px;
            font-weight: 600;
        }

        .dashboard-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .dashboard-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
            white-space: nowrap;
        }

        .dashboard-table th {
            padding: 10px 13px;
            border-bottom: 1px solid #eceef2;
            background: #f6f2ff;
            color: #847d9e;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .dashboard-table td {
            padding: 11px 13px;
            border-bottom: 1px solid #f2f3f5;
            color: #4b5563;
            font-size: 10px;
            vertical-align: middle;
        }

        .dashboard-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .tenant-name {
            color: #111827;
            font-weight: 700;
        }

        .tenant-code {
            margin-top: 2px;
            color: #9ca3af;
            font-size: 8px;
        }

        .status-badge {
            min-height: 22px;
            padding: 4px 8px;
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 700;
        }

        .status-active {
            background: #d1fae5;
            color: #047857;
        }

        .status-trial {
            background: #fef3c7;
            color: #b45309;
        }

        .status-suspended {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-pending {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .activity-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .activity-item {
            padding: 13px 15px;
            display: flex;
            gap: 11px;
            border-bottom: 1px solid #f1f2f4;
        }

        .activity-item:last-child {
            border-bottom: 0;
        }

        .activity-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #eee8ff;
            color: #7c3aed;
            font-size: 14px;
        }

        .activity-title {
            color: #111827;
            font-size: 10px;
            font-weight: 700;
        }

        .activity-text {
            margin-top: 2px;
            color: #6b7280;
            font-size: 9px;
            line-height: 1.45;
        }

        .activity-time {
            margin-top: 3px;
            color: #9ca3af;
            font-size: 8px;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px;
            padding: 14px;
        }

        .quick-action {
            min-height: 82px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: 1px solid #e0d8f2;
            border-radius: 11px;
            background: #fbf9ff;
            color: #4e486a;
            transition: .15s ease;
        }

        .quick-action:hover {
            border-color: #bca7ff;
            background: #f1ebff;
            color: #6d28d9;
            transform: translateY(-1px);
        }

        .quick-action i {
            font-size: 18px;
        }

        .quick-action span {
            font-size: 9px;
            font-weight: 700;
        }

        .subscription-progress {
            padding: 15px;
        }

        .subscription-row {
            margin-bottom: 14px;
        }

        .subscription-row:last-child {
            margin-bottom: 0;
        }

        .subscription-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 9px;
        }

        .subscription-line strong {
            color: #111827;
            font-size: 10px;
        }

        .progress {
            height: 7px;
            margin-top: 7px;
            background: #f0f1f3;
            border-radius: 999px;
        }

        .progress-bar {
            border-radius: 999px;
        }

        @media (max-width: 1199px) {
            .dashboard-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 991px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .dashboard-welcome {
                align-items: flex-start;
                flex-direction: column;
            }

            .dashboard-date {
                width: 100%;
                text-align: left;
            }

            .dashboard-stats {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <div class="fp-layout">

        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>


        <main class="fp-main">

            <?php require_once __DIR__ . '/includes/topbar.php'; ?>

            <div class="fp-content">


                <div class="dashboard-page">

                    <section class="dashboard-welcome">
                        <div>
                            <h2>Welcome back, <?= dash_h($welcomeName) ?></h2>
                            <p>
                                Here is the live FieldPlx platform overview with current tenant, subscription,
                                user, billing and activity information.
                            </p>
                        </div>

                        <div class="dashboard-date">
                            <?= dash_h($todayDay) ?>
                            <strong><?= dash_h($todayDate) ?></strong>
                        </div>
                    </section>

                    <section class="dashboard-stats">

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon purple"><i class="bi bi-buildings"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Total Tenants</span>
                                <span class="dashboard-stat-value"><?= number_format($totalTenants) ?></span>
                                <span class="dashboard-stat-meta">+<?= number_format($tenantsThisMonth) ?> this month</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon green"><i class="bi bi-check-circle"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Active Tenants</span>
                                <span class="dashboard-stat-value"><?= number_format($activeTenants) ?></span>
                                <span class="dashboard-stat-meta"><?= number_format($activePercent) ?>% of all tenants</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon orange"><i class="bi bi-hourglass-split"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Trial Tenants</span>
                                <span class="dashboard-stat-value"><?= number_format($trialTenants) ?></span>
                                <span class="dashboard-stat-meta"><?= number_format($trialExpiringWeek) ?> expire this week</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon blue"><i class="bi bi-credit-card"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Subscriptions</span>
                                <span class="dashboard-stat-value"><?= number_format($totalSubscriptions) ?></span>
                                <span class="dashboard-stat-meta"><?= number_format($activeSubscriptions) ?> currently active</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon cyan"><i class="bi bi-people"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Platform Users</span>
                                <span class="dashboard-stat-value"><?= number_format($platformUsers) ?></span>
                                <span class="dashboard-stat-meta"><?= number_format($activePlatformUsers) ?> active administrators</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon indigo"><i class="bi bi-person-workspace"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Tenant Users</span>
                                <span class="dashboard-stat-value"><?= number_format($tenantUsers) ?></span>
                                <span class="dashboard-stat-meta">Across all workspaces</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon red"><i class="bi bi-exclamation-circle"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Needs Attention</span>
                                <span class="dashboard-stat-value"><?= number_format($needsAttention) ?></span>
                                <span class="dashboard-stat-meta"><?= number_format($attentionPayments) ?> payments · <?= number_format($attentionExpiry) ?> expiries</span>
                            </span>
                        </article>

                        <article class="dashboard-stat">
                            <span class="dashboard-stat-icon gray"><i class="bi bi-currency-dollar"></i></span>
                            <span>
                                <span class="dashboard-stat-label">Monthly Revenue</span>
                                <span class="dashboard-stat-value" style="font-size:16px"><?= dash_h($monthlyRevenueDisplay) ?></span>
                                <span class="dashboard-stat-meta"><?= dash_h($monthlyRevenueMeta) ?></span>
                            </span>
                        </article>

                    </section>

                    <section class="dashboard-grid">

                        <div class="dashboard-card">
                            <div class="dashboard-card-header">
                                <div>
                                    <h3 class="dashboard-card-title">Recent Tenants</h3>
                                    <div class="dashboard-card-subtitle">Latest registered businesses</div>
                                </div>
                                <a href="tenants.php" class="dashboard-card-link">View all</a>
                            </div>

                            <div class="dashboard-table-wrap">
                                <table class="dashboard-table">
                                    <thead>
                                        <tr>
                                            <th>Business</th>
                                            <th>Plan</th>
                                            <th>Country</th>
                                            <th>Users</th>
                                            <th>Status</th>
                                            <th>Joined</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (!$recentTenants): ?>
                                        <tr>
                                            <td colspan="6" style="text-align:center;color:#9ca3af;">No tenants found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentTenants as $tenant): ?>
                                        <?php
                                        $tenantStatusClass = 'status-pending';
                                        if ($tenant['status'] === 'active') {
                                            $tenantStatusClass = 'status-active';
                                        } elseif ($tenant['status'] === 'trial') {
                                            $tenantStatusClass = 'status-trial';
                                        } elseif ($tenant['status'] === 'suspended') {
                                            $tenantStatusClass = 'status-suspended';
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="tenant-name"><?= dash_h($tenant['display_name'] ?: $tenant['legal_name']) ?></div>
                                                <div class="tenant-code"><?= dash_h($tenant['tenant_code']) ?></div>
                                            </td>
                                            <td><?= dash_h($tenant['plan_name'] ?: 'No Plan') ?></td>
                                            <td><?= dash_h($tenant['country_name'] ?: '-') ?></td>
                                            <td><?= number_format((int)$tenant['user_count']) ?></td>
                                            <td>
                                                <span class="status-badge <?= dash_h($tenantStatusClass) ?>">
                                                    <?= dash_h(ucfirst($tenant['status'])) ?>
                                                </span>
                                            </td>
                                            <td><?= dash_h(date('d M Y', strtotime($tenant['created_at']))) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="dashboard-card">
                            <div class="dashboard-card-header">
                                <div>
                                    <h3 class="dashboard-card-title">Recent Activity</h3>
                                    <div class="dashboard-card-subtitle">Latest recorded platform and tenant events</div>
                                </div>
                                <a href="activity-logs.php" class="dashboard-card-link">View log</a>
                            </div>

                            <ul class="activity-list">
                            <?php if (!$recentActivity): ?>
                                <li class="activity-item">
                                    <span class="activity-icon"><i class="bi bi-info-circle"></i></span>
                                    <span>
                                        <span class="activity-title">No recent activity</span>
                                        <span class="activity-text">Activity will appear here when events are recorded.</span>
                                    </span>
                                </li>
                            <?php else: ?>
                                <?php foreach ($recentActivity as $activity): ?>
                                <li class="activity-item">
                                    <span class="activity-icon"><i class="<?= dash_h(dash_activity_icon($activity['event_type'])) ?>"></i></span>
                                    <span>
                                        <span class="activity-title"><?= dash_h($activity['title']) ?></span>
                                        <span class="activity-text">
                                            <?= dash_h(
                                                $activity['tenant_name']
                                                    ? $activity['tenant_name'] . ' · ' . ucwords(str_replace('_', ' ', $activity['event_type']))
                                                    : ucwords(str_replace('_', ' ', $activity['event_type']))
                                            ) ?>
                                        </span>
                                        <span class="activity-time"><?= dash_h(dash_time_ago($activity['created_at'])) ?></span>
                                    </span>
                                </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </ul>
                        </div>

                    </section>

                    <section class="dashboard-grid">

                        <div class="dashboard-card">
                            <div class="dashboard-card-header">
                                <div>
                                    <h3 class="dashboard-card-title">Subscription Distribution</h3>
                                    <div class="dashboard-card-subtitle">Current plan usage across tenants</div>
                                </div>
                            </div>

                            <div class="subscription-progress">
                            <?php if (!$subscriptionDistribution): ?>
                                <div class="subscription-row">
                                    <div class="subscription-line">
                                        <strong>No subscription data</strong>
                                        <span>0 tenants</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($subscriptionDistribution as $distribution): ?>
                                <?php
                                $distributionCount = (int)$distribution['tenant_count'];
                                $distributionPercent = $distributionTotal > 0
                                    ? round(($distributionCount / $distributionTotal) * 100)
                                    : 0;
                                ?>
                                <div class="subscription-row">
                                    <div class="subscription-line">
                                        <strong><?= dash_h($distribution['plan_name']) ?></strong>
                                        <span>
                                            <?= number_format($distributionCount) ?> tenants ·
                                            <?= number_format($distributionPercent) ?>%
                                        </span>
                                    </div>
                                    <div class="progress">
                                        <div
                                            class="progress-bar"
                                            style="width:<?= (int)$distributionPercent ?>%;background:#8b5cf6;"
                                        ></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </div>
                        </div>

                        <div class="dashboard-card">
                            <div class="dashboard-card-header">
                                <div>
                                    <h3 class="dashboard-card-title">Quick Actions</h3>
                                    <div class="dashboard-card-subtitle">Common platform administration shortcuts</div>
                                </div>
                            </div>

                            <div class="quick-actions">
                                <a href="tenant-add.php" class="quick-action">
                                    <i class="bi bi-building-add"></i>
                                    <span>Add Tenant</span>
                                </a>

                                <a href="plans.php" class="quick-action">
                                    <i class="bi bi-plus-square"></i>
                                    <span>Create Plan</span>
                                </a>

                                <a href="platform-users.php" class="quick-action">
                                    <i class="bi bi-person-plus"></i>
                                    <span>Add Platform User</span>
                                </a>

                                <a href="platform-settings.php" class="quick-action">
                                    <i class="bi bi-gear"></i>
                                    <span>Platform Settings</span>
                                </a>
                            </div>
                        </div>

                    </section>

                </div>

            </div>
        </main>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        (function () {
            'use strict';

            const body = document.body;
            const toggle = document.getElementById('fpSidebarToggle');
            const close = document.getElementById('fpSidebarClose');
            const overlay = document.getElementById('fpSidebarOverlay');

            const SIDEBAR_STORAGE_KEY = 'fieldplx_sidebar_collapsed';

            /*
            |--------------------------------------------------------------------------
            | Restore saved desktop sidebar state
            |--------------------------------------------------------------------------
            |
            | collapsed = "1"
            | expanded  = "0"
            |
            | The sidebar will keep the same state even after page refresh.
            |
            */

            function restoreSidebarState() {
                if (window.innerWidth < 992) {
                    body.classList.remove('fp-sidebar-collapsed');
                    return;
                }

                const savedState = localStorage.getItem(
                    SIDEBAR_STORAGE_KEY
                );

                if (savedState === '1') {
                    body.classList.add('fp-sidebar-collapsed');
                } else {
                    body.classList.remove('fp-sidebar-collapsed');
                }
            }

            function saveSidebarState() {
                const isCollapsed = body.classList.contains(
                    'fp-sidebar-collapsed'
                );

                localStorage.setItem(
                    SIDEBAR_STORAGE_KEY,
                    isCollapsed ? '1' : '0'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Initial sidebar state
            |--------------------------------------------------------------------------
            */

            restoreSidebarState();

            /*
            |--------------------------------------------------------------------------
            | Sidebar toggle
            |--------------------------------------------------------------------------
            */

            if (toggle) {
                toggle.addEventListener('click', function () {
                    if (window.innerWidth < 992) {
                        body.classList.toggle(
                            'fp-sidebar-mobile-open'
                        );
                        return;
                    }

                    body.classList.toggle(
                        'fp-sidebar-collapsed'
                    );

                    saveSidebarState();
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Mobile sidebar controls
            |--------------------------------------------------------------------------
            */

            if (close) {
                close.addEventListener('click', function () {
                    body.classList.remove(
                        'fp-sidebar-mobile-open'
                    );
                });
            }

            if (overlay) {
                overlay.addEventListener('click', function () {
                    body.classList.remove(
                        'fp-sidebar-mobile-open'
                    );
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Sidebar submenu toggle
            |--------------------------------------------------------------------------
            */

            document
                .querySelectorAll('.fp-sidebar-menu-toggle')
                .forEach(function (button) {
                    button.addEventListener(
                        'click',
                        function () {
                            const menu = button.closest(
                                '.fp-sidebar-menu'
                            );

                            if (menu) {
                                menu.classList.toggle('open');
                            }
                        }
                    );
                });

            /*
            |--------------------------------------------------------------------------
            | Close mobile sidebar after navigation
            |--------------------------------------------------------------------------
            */

            document
                .querySelectorAll('.fp-sidebar a')
                .forEach(function (link) {
                    link.addEventListener(
                        'click',
                        function () {
                            if (window.innerWidth < 992) {
                                body.classList.remove(
                                    'fp-sidebar-mobile-open'
                                );
                            }
                        }
                    );
                });

            /*
            |--------------------------------------------------------------------------
            | Responsive handling
            |--------------------------------------------------------------------------
            |
            | Desktop:
            |   restore saved collapsed/expanded state.
            |
            | Mobile:
            |   use overlay sidebar only.
            |
            */

            let lastDesktopState =
                window.innerWidth >= 992;

            window.addEventListener(
                'resize',
                function () {
                    const isDesktop =
                        window.innerWidth >= 992;

                    if (isDesktop !== lastDesktopState) {
                        body.classList.remove(
                            'fp-sidebar-mobile-open'
                        );

                        restoreSidebarState();

                        lastDesktopState = isDesktop;
                    }
                }
            );
        })();
    </script>

</body>

</html>