<?php
/**
 * FieldPlx Platform - All Tenants
 *
 * Dynamic PDO version.
 * PHP 7.2+
 */

require_once __DIR__ . '/includes/db.php';

$pageTitle = 'All Tenants';
$activePage = 'tenants';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('tenantEscape')) {
    function tenantEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('tenantGet')) {
    function tenantGet($key, $default = '')
    {
        if (
            !isset($_GET[$key]) ||
            is_array($_GET[$key])
        ) {
            return $default;
        }

        return trim((string) $_GET[$key]);
    }
}

if (!function_exists('tenantUrl')) {
    function tenantUrl($changes = array())
    {
        $query = $_GET;

        foreach ($changes as $key => $value) {
            if ($value === '' || $value === null) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return '?' . http_build_query($query);
    }
}

if (!function_exists('tenantLabel')) {
    function tenantLabel($value)
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

if (!function_exists('tenantDate')) {
    function tenantDate($value)
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return '—';
        }

        return date('d M Y', $timestamp);
    }
}

if (!function_exists('tenantInitials')) {
    function tenantInitials($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'T';
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
            : 'T';
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = tenantGet('search');
$status = strtolower(tenantGet('status'));
$plan = tenantGet('plan');

$page = max(
    1,
    (int) tenantGet('page', '1')
);

$perPage = (int) tenantGet(
    'per_page',
    '10'
);

$allowedPerPage = array(
    10,
    15,
    25,
    50,
    100
);

if (
    !in_array(
        $perPage,
        $allowedPerPage,
        true
    )
) {
    $perPage = 10;
}

$allowedStatuses = array(
    '',
    'trial',
    'active',
    'expired',
    'suspended',
    'cancelled',
    'archived'
);

if (
    !in_array(
        $status,
        $allowedStatuses,
        true
    )
) {
    $status = '';
}

/*
|--------------------------------------------------------------------------
| Summary Counts
|--------------------------------------------------------------------------
*/

$summarySql = "
    SELECT
        COUNT(*) AS total,
        SUM(
            CASE
                WHEN status = 'active' THEN 1
                ELSE 0
            END
        ) AS active_count,
        SUM(
            CASE
                WHEN status = 'trial' THEN 1
                ELSE 0
            END
        ) AS trial_count,
        SUM(
            CASE
                WHEN status = 'suspended' THEN 1
                ELSE 0
            END
        ) AS suspended_count
    FROM tenants
    WHERE deleted_at IS NULL
";

$summaryStmt = $pdo->query($summarySql);
$summary = $summaryStmt->fetch();

if (!$summary) {
    $summary = array();
}

$totalTenants = isset($summary['total'])
    ? (int) $summary['total']
    : 0;

$activeTenants = isset($summary['active_count'])
    ? (int) $summary['active_count']
    : 0;

$trialTenants = isset($summary['trial_count'])
    ? (int) $summary['trial_count']
    : 0;

$suspendedTenants = isset($summary['suspended_count'])
    ? (int) $summary['suspended_count']
    : 0;

/*
|--------------------------------------------------------------------------
| Plan Filter Options
|--------------------------------------------------------------------------
*/

$planOptions = array();

$planStmt = $pdo->query("
    SELECT
        name
    FROM plans
    WHERE deleted_at IS NULL
      AND status <> 'archived'
    ORDER BY name ASC
");

while ($planRow = $planStmt->fetch()) {
    $planOptions[] = (string) $planRow['name'];
}

/*
|--------------------------------------------------------------------------
| Build WHERE Conditions
|--------------------------------------------------------------------------
*/

$where = array(
    't.deleted_at IS NULL'
);

$params = array();

if ($search !== '') {
    $where[] = "(
        t.tenant_code LIKE :search
        OR t.legal_name LIKE :search
        OR t.display_name LIKE :search
        OR t.email LIKE :search
        OR t.phone LIKE :search
        OR t.alternate_phone LIKE :search
        OR t.business_type LIKE :search
        OR t.city LIKE :search
        OR t.state LIKE :search
        OR COALESCE(c.name, '') LIKE :search
        OR COALESCE(cur.currency_code, '') LIKE :search
        OR COALESCE(p.name, '') LIKE :search
    )";

    $params[':search'] = '%' . $search . '%';
}

if ($status !== '') {
    $where[] = 't.status = :status';
    $params[':status'] = $status;
}

if ($plan !== '') {
    $where[] = 'LOWER(COALESCE(p.name, \'\')) = LOWER(:plan)';
    $params[':plan'] = $plan;
}

$whereSql = implode(
    ' AND ',
    $where
);

/*
|--------------------------------------------------------------------------
| Shared JOIN SQL
|--------------------------------------------------------------------------
|
| Current FieldPlx schema:
|   tenants.country_id   -> countries.id
|   tenants.currency_id  -> currencies.id
|   subscriptions.plan_id -> plans.id
|   users.tenant_id      -> tenants.id
|   branches.tenant_id   -> tenants.id
|
*/

$joinSql = "
    LEFT JOIN (
        SELECT s1.*
        FROM subscriptions s1
        INNER JOIN (
            SELECT
                tenant_id,
                MAX(id) AS max_id
            FROM subscriptions
            GROUP BY tenant_id
        ) latest_subscription
            ON latest_subscription.max_id = s1.id
    ) s
        ON s.tenant_id = t.id

    LEFT JOIN plans p
        ON p.id = s.plan_id
       AND p.deleted_at IS NULL

    LEFT JOIN countries c
        ON c.id = t.country_id

    LEFT JOIN currencies cur
        ON cur.id = t.currency_id

    LEFT JOIN (
        SELECT
            tenant_id,
            COUNT(*) AS user_count
        FROM users
        WHERE deleted_at IS NULL
        GROUP BY tenant_id
    ) uc
        ON uc.tenant_id = t.id

    LEFT JOIN (
        SELECT
            tenant_id,
            COUNT(*) AS branch_count
        FROM branches
        WHERE status <> 'archived'
        GROUP BY tenant_id
    ) bc
        ON bc.tenant_id = t.id
";

/*
|--------------------------------------------------------------------------
| Count Filtered Records
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*)
    FROM tenants t
    {$joinSql}
    WHERE {$whereSql}
";

$countStmt = $pdo->prepare($countSql);

foreach ($params as $key => $value) {
    $countStmt->bindValue(
        $key,
        $value,
        PDO::PARAM_STR
    );
}

$countStmt->execute();

$totalRecords = (int) $countStmt->fetchColumn();

$totalPages = max(
    1,
    (int) ceil(
        $totalRecords / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Tenant List
|--------------------------------------------------------------------------
*/

$listSql = "
    SELECT
        t.id,
        t.tenant_code,
        t.legal_name,
        t.display_name,
        t.business_type,
        t.registration_number,
        t.tax_number,
        t.email,
        t.phone,
        t.alternate_phone,
        t.website_url,
        t.timezone,
        t.date_format,
        t.logo_path,
        t.invoice_logo_path,
        t.address_line1,
        t.address_line2,
        t.city,
        t.state,
        t.postal_code,
        t.status,
        t.created_at,

        c.name AS country,
        c.iso2 AS country_iso2,

        cur.currency_code,
        cur.currency_name,
        cur.symbol AS currency_symbol,

        COALESCE(
            p.name,
            'No Plan'
        ) AS plan_name,

        s.id AS subscription_id,
        s.status AS subscription_status,
        s.start_date AS subscription_start_date,
        s.expiry_date AS subscription_expiry_date,
        s.trial_end_date AS subscription_trial_end_date,
        s.amount AS subscription_amount,

        COALESCE(
            uc.user_count,
            0
        ) AS user_count,

        COALESCE(
            bc.branch_count,
            0
        ) AS branch_count

    FROM tenants t

    {$joinSql}

    WHERE {$whereSql}

    ORDER BY
        t.created_at DESC,
        t.id DESC

    LIMIT :limit
    OFFSET :offset
";

$listStmt = $pdo->prepare($listSql);

foreach ($params as $key => $value) {
    $listStmt->bindValue(
        $key,
        $value,
        PDO::PARAM_STR
    );
}

$listStmt->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);

$listStmt->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);

$listStmt->execute();

$tenants = $listStmt->fetchAll();

$startRecord = $totalRecords > 0
    ? $offset + 1
    : 0;

$endRecord = min(
    $offset + $perPage,
    $totalRecords
);

$paginationStart = max(
    1,
    $page - 2
);

$paginationEnd = min(
    $totalPages,
    $page + 2
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        <?= tenantEscape($pageTitle); ?> - FieldPlx
    </title>

    <?php
    require_once __DIR__ . '/includes/links.php';
    ?>

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
            --fp-bg: #ffffff;
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

        /* =========================================================
           SHARED FIELDPLX TOPBAR UI
           Matches Platform Dashboard
        ========================================================= */

        .fp-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            min-height: var(--fp-topbar-height);
            border-bottom: 1px solid #ded8f3;
            background: rgba(248, 246, 255, .96);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
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
            min-width: 39px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d9d2ef;
            border-radius: 10px;
            background: #ffffff;
            color: #39345f;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
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
            line-height: 1.25;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fp-page-subtitle {
            margin-top: 2px;
            color: var(--fp-muted);
            font-size: 10px;
            line-height: 1.3;
        }

        .fp-search {
            width: min(340px, 31vw);
            position: relative;
            flex: 0 1 340px;
        }

        .fp-search i {
            position: absolute;
            top: 50%;
            left: 12px;
            z-index: 2;
            transform: translateY(-50%);
            color: #8f88aa;
            font-size: 14px;
            pointer-events: none;
        }

        .fp-search input {
            width: 100%;
            height: 39px;
            padding: 8px 13px 8px 36px;
            border: 1px solid #dcd5ef;
            border-radius: 10px;
            outline: none;
            background: #f8f6ff;
            color: #292640;
            box-shadow: none;
            font-family: inherit;
            font-size: 12px;
        }

        .fp-search input::placeholder {
            color: #77718e;
        }

        .fp-search input:focus {
            border-color: #a78bfa;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, .12);
        }

        .fp-notification-wrap {
            position: relative;
            flex: 0 0 auto;
        }

        .fp-notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            z-index: 3;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
            border-radius: 999px;
            background: var(--fp-danger);
            color: #ffffff;
            font-size: 9px;
            font-weight: 700;
            line-height: 1;
        }

        .fp-profile {
            min-width: 0;
            padding: 4px 9px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--fp-border);
            border-radius: 11px;
            background: #ffffff;
            color: inherit;
            cursor: pointer;
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
            color: #ffffff;
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
            line-height: 1.25;
        }

        .fp-profile-role {
            margin-top: 1px;
            color: var(--fp-muted);
            font-size: 9px;
            line-height: 1.25;
        }

        .fp-mobile-brand {
            display: none;
        }

        .fp-mobile-brand-logo {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: linear-gradient(135deg, #6d4df4, #9a5cff);
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
        }

        .fp-content {
            padding: 18px;
            background: #ffffff;
        }

        .tenant-page {
            display: grid;
            gap: 16px;
        }

        .tenant-page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
        }

        .tenant-page-title {
            margin: 0;
            color: #111827;
            font-size: 20px;
            font-weight: 800;
        }

        .tenant-page-description {
            margin-top: 4px;
            color: #77718e;
            font-size: 10px;
        }

        .tenant-add-button {
            min-height: 39px;
            padding: 9px 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 0;
            border-radius: 10px;
            background:
                linear-gradient(
                    135deg,
                    #7c3aed,
                    #6d28d9
                );
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
            box-shadow:
                0 8px 20px
                rgba(109, 40, 217, .18);
        }

        .tenant-add-button:hover {
            color: #ffffff;
            transform: translateY(-1px);
        }

        .tenant-summary {
            display: grid;
            grid-template-columns:
                repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .tenant-summary-card {
            padding: 14px 15px;
            display: flex;
            align-items: center;
            gap: 11px;
            border: 1px solid #ddd5f1;
            border-radius: 13px;
            background:
                linear-gradient(
                    180deg,
                    #ffffff 0%,
                    #fbf9ff 100%
                );
        }

        .tenant-summary-icon {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #eee8ff;
            color: #7c3aed;
            font-size: 16px;
        }

        .tenant-summary-label {
            color: #9a94ae;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .tenant-summary-value {
            margin-top: 2px;
            display: block;
            color: #111827;
            font-size: 20px;
            font-weight: 800;
        }

        .tenant-card {
            overflow: hidden;
            border: 1px solid #ded7ef;
            border-radius: 14px;
            background: #ffffff;
            box-shadow:
                0 8px 24px
                rgba(37, 29, 80, .05);
        }

        .tenant-toolbar {
            padding: 13px;
            display: grid;
            grid-template-columns:
                minmax(260px, 1.6fr)
                minmax(150px, .55fr)
                minmax(150px, .55fr)
                110px;
            gap: 10px;
            align-items: start;
            border-bottom: 1px solid #ece7f7;
            background: #fbf9ff;
        }

        .tenant-search {
            position: relative;
        }

        .tenant-search-icon {
            position: absolute;
            top: 19px;
            left: 12px;
            z-index: 2;
            transform: translateY(-50%);
            color: #8f88aa;
            font-size: 13px;
            pointer-events: none;
        }

        .tenant-control {
            width: 100%;
            height: 38px;
            border: 1px solid #dcd5ef;
            border-radius: 9px;
            background: #ffffff;
            color: #39345f;
            font-size: 10px;
            box-shadow: none;
        }

        .tenant-search .tenant-control {
            padding-left: 35px;
        }

        .tenant-control:focus {
            border-color: #a78bfa;
            box-shadow:
                0 0 0 3px
                rgba(139, 92, 246, .10);
        }

        .search-countdown {
            min-height: 12px;
            margin-top: 4px;
            color: #9992aa;
            font-size: 8px;
        }

        .tenant-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .tenant-table {
            width: 100%;
            min-width: 1080px;
            border-collapse: collapse;
            white-space: nowrap;
        }

        .tenant-table th {
            padding: 11px 13px;
            border-bottom: 1px solid #ece7f7;
            background: #f6f2ff;
            color: #847d9e;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .tenant-table td {
            padding: 12px 13px;
            border-bottom: 1px solid #f1eff6;
            color: #4f4a64;
            font-size: 10px;
            vertical-align: middle;
        }

        .tenant-table tbody tr:hover {
            background: #fcfbff;
        }

        .tenant-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .tenant-main {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tenant-logo {
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background:
                linear-gradient(
                    135deg,
                    #ece7ff,
                    #f7f4ff
                );
            color: #7c3aed;
            font-size: 10px;
            font-weight: 800;
        }

        .tenant-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .tenant-company {
            color: #111827;
            font-size: 10px;
            font-weight: 700;
        }

        .tenant-code {
            margin-top: 2px;
            color: #9d96ac;
            font-size: 8px;
        }

        .tenant-contact {
            line-height: 1.5;
        }

        .tenant-contact strong {
            display: block;
            max-width: 220px;
            overflow: hidden;
            color: #373248;
            font-size: 9px;
            font-weight: 600;
            text-overflow: ellipsis;
        }

        .tenant-contact span {
            color: #918b9d;
            font-size: 8px;
        }

        .tenant-plan-badge {
            padding: 5px 8px;
            display: inline-flex;
            align-items: center;
            border-radius: 7px;
            background: #f0ebff;
            color: #6d28d9;
            font-size: 8px;
            font-weight: 700;
        }

        .tenant-status {
            min-height: 22px;
            padding: 4px 8px;
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 700;
        }

        .tenant-status.active {
            background: #d1fae5;
            color: #047857;
        }

        .tenant-status.trial {
            background: #fef3c7;
            color: #b45309;
        }

        .tenant-status.expired {
            background: #fff7ed;
            color: #c2410c;
        }

        .tenant-status.cancelled {
            background: #f3f4f6;
            color: #4b5563;
        }

        .tenant-status.archived {
            background: #f3f4f6;
            color: #6b7280;
        }

        .tenant-status.suspended {
            background: #fee2e2;
            color: #b91c1c;
        }

        .tenant-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }

        .tenant-action {
            width: 29px;
            height: 29px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e1dcef;
            border-radius: 8px;
            background: #ffffff;
            color: #615a75;
            font-size: 12px;
        }

        .tenant-action:hover {
            border-color: #bca7ff;
            background: #f4f0ff;
            color: #6d28d9;
        }

        .tenant-empty {
            padding: 50px 20px;
            text-align: center;
        }

        .tenant-empty i {
            color: #b6adc9;
            font-size: 32px;
        }

        .tenant-empty h3 {
            margin: 10px 0 4px;
            color: #312c40;
            font-size: 13px;
            font-weight: 700;
        }

        .tenant-empty p {
            margin: 0;
            color: #9992a8;
            font-size: 9px;
        }

        .tenant-pagination-bar {
            min-height: 56px;
            padding: 10px 13px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-top: 1px solid #ece7f7;
            background: #ffffff;
        }

        .tenant-pagination-info {
            color: #817a91;
            font-size: 9px;
        }

        .tenant-pagination {
            margin: 0 0 0 auto;
            padding: 0;
            display: flex;
            align-items: center;
            gap: 5px;
            list-style: none;
        }

        .tenant-pagination a,
        .tenant-pagination span {
            min-width: 30px;
            height: 30px;
            padding: 0 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e0daee;
            border-radius: 8px;
            background: #ffffff;
            color: #5e586d;
            font-size: 9px;
            font-weight: 600;
        }

        .tenant-pagination a:hover {
            border-color: #bca7ff;
            background: #f4f0ff;
            color: #6d28d9;
        }

        .tenant-pagination .active {
            border-color: #7c3aed;
            background: #7c3aed;
            color: #ffffff;
        }

        .tenant-pagination .disabled {
            color: #c1bbc9;
            cursor: not-allowed;
        }

        @media (max-width: 1100px) {
            .tenant-summary {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .tenant-toolbar {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .tenant-search {
                grid-column: span 2;
            }
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
        }

        @media (max-width: 700px) {
            .tenant-page-header {
                flex-direction: column;
            }

            .tenant-add-button {
                width: 100%;
            }

            .tenant-summary,
            .tenant-toolbar {
                grid-template-columns: 1fr;
            }

            .tenant-search {
                grid-column: span 1;
            }

            .tenant-pagination-bar {
                align-items: flex-start;
                flex-direction: column;
            }

            .tenant-pagination {
                margin-left: 0;
                flex-wrap: wrap;
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
</head>

<body>

<div class="fp-layout">

    <?php
    require_once __DIR__ . '/includes/sidebar.php';
    ?>

    <main class="fp-main">

        <?php
        require_once __DIR__ . '/includes/topbar.php';
        ?>

        <div class="fp-content">

            <div class="tenant-page">

                <div class="tenant-page-header">
                    <div>
                        <h2 class="tenant-page-title">
                            All Tenants
                        </h2>

                        <div class="tenant-page-description">
                            Manage FieldPlx business workspaces,
                            subscriptions, users and business locations.
                        </div>
                    </div>

                    <a
                        href="tenant-add.php"
                        class="tenant-add-button"
                    >
                        <i class="bi bi-plus-lg"></i>
                        Add Tenant
                    </a>
                </div>

                <section class="tenant-summary">

                    <article class="tenant-summary-card">
                        <span class="tenant-summary-icon">
                            <i class="bi bi-buildings"></i>
                        </span>

                        <span>
                            <span class="tenant-summary-label">
                                Total Tenants
                            </span>

                            <span class="tenant-summary-value">
                                <?= number_format(
                                    $totalTenants
                                ); ?>
                            </span>
                        </span>
                    </article>

                    <article class="tenant-summary-card">
                        <span class="tenant-summary-icon">
                            <i class="bi bi-check-circle"></i>
                        </span>

                        <span>
                            <span class="tenant-summary-label">
                                Active
                            </span>

                            <span class="tenant-summary-value">
                                <?= number_format(
                                    $activeTenants
                                ); ?>
                            </span>
                        </span>
                    </article>

                    <article class="tenant-summary-card">
                        <span class="tenant-summary-icon">
                            <i class="bi bi-hourglass-split"></i>
                        </span>

                        <span>
                            <span class="tenant-summary-label">
                                Trial
                            </span>

                            <span class="tenant-summary-value">
                                <?= number_format(
                                    $trialTenants
                                ); ?>
                            </span>
                        </span>
                    </article>

                    <article class="tenant-summary-card">
                        <span class="tenant-summary-icon">
                            <i class="bi bi-slash-circle"></i>
                        </span>

                        <span>
                            <span class="tenant-summary-label">
                                Suspended
                            </span>

                            <span class="tenant-summary-value">
                                <?= number_format(
                                    $suspendedTenants
                                ); ?>
                            </span>
                        </span>
                    </article>

                </section>

                <section class="tenant-card">

                    <form
                        method="get"
                        action="tenants.php"
                        class="tenant-toolbar"
                        id="tenantFilterForm"
                    >

                        <div class="tenant-search">
                            <i
                                class="bi bi-search tenant-search-icon"
                            ></i>

                            <input
                                type="search"
                                name="search"
                                id="tenantSearchInput"
                                class="form-control tenant-control"
                                value="<?= tenantEscape(
                                    $search
                                ); ?>"
                                placeholder="Search tenant code, company, email, phone, location, country or plan..."
                                autocomplete="off"
                            >

                            <div
                                class="search-countdown"
                                id="tenantSearchCountdown"
                            ></div>
                        </div>

                        <select
                            name="status"
                            class="form-select tenant-control"
                            id="tenantStatusFilter"
                        >
                            <option value="">
                                All Status
                            </option>

                            <option
                                value="active"
                                <?= $status === 'active'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                Active
                            </option>

                            <option
                                value="trial"
                                <?= $status === 'trial'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                Trial
                            </option>

                            <option
                                value="expired"
                                <?= $status === 'expired'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                Expired
                            </option>

                            <option
                                value="suspended"
                                <?= $status === 'suspended'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                Suspended
                            </option>

                            <option
                                value="cancelled"
                                <?= $status === 'cancelled'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                Cancelled
                            </option>

                            <option
                                value="archived"
                                <?= $status === 'archived'
                                    ? 'selected'
                                    : ''; ?>
                            >
                                Archived
                            </option>
                        </select>

                        <select
                            name="plan"
                            class="form-select tenant-control"
                            id="tenantPlanFilter"
                        >
                            <option value="">
                                All Plans
                            </option>

                            <?php
                            foreach ($planOptions as $planName):
                            ?>
                                <option
                                    value="<?= tenantEscape(
                                        $planName
                                    ); ?>"
                                    <?= strtolower($plan) ===
                                        strtolower($planName)
                                            ? 'selected'
                                            : ''; ?>
                                >
                                    <?= tenantEscape(
                                        tenantLabel(
                                            $planName
                                        )
                                    ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select
                            name="per_page"
                            class="form-select tenant-control"
                            id="tenantPerPage"
                        >
                            <?php
                            foreach (
                                $allowedPerPage as $size
                            ):
                            ?>
                                <option
                                    value="<?= (int) $size; ?>"
                                    <?= $perPage === $size
                                        ? 'selected'
                                        : ''; ?>
                                >
                                    <?= (int) $size; ?> / page
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <input
                            type="hidden"
                            name="page"
                            id="tenantPageInput"
                            value="1"
                        >

                    </form>

                    <?php if (empty($tenants)): ?>

                        <div class="tenant-empty">
                            <i class="bi bi-search"></i>

                            <h3>
                                No tenants found
                            </h3>

                            <p>
                                Change the search or filter
                                values and try again.
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="tenant-table-wrap">

                            <table class="tenant-table">
                                <thead>
                                <tr>
                                    <th style="width:56px;">
                                        S.No
                                    </th>
                                    <th>Tenant</th>
                                    <th>Contact</th>
                                    <th>Country</th>
                                    <th>Plan</th>
                                    <th style="text-align:center;">
                                        Users
                                    </th>
                                    <th style="text-align:center;">
                                        Locations
                                    </th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th style="text-align:right;">
                                        Action
                                    </th>
                                </tr>
                                </thead>

                                <tbody>

                                <?php
                                foreach (
                                    $tenants as $index => $tenant
                                ):
                                ?>
                                    <tr>

                                        <td>
                                            <?= (int) (
                                                $offset +
                                                $index +
                                                1
                                            ); ?>
                                        </td>

                                        <td>
                                            <div class="tenant-main">

                                                <span class="tenant-logo">

                                                    <?php
                                                    if (
                                                        !empty(
                                                            $tenant[
                                                                'logo_path'
                                                            ]
                                                        )
                                                    ):
                                                    ?>
                                                        <img
                                                            src="<?= tenantEscape(
                                                                $tenant[
                                                                    'logo_path'
                                                                ]
                                                            ); ?>"
                                                            alt=""
                                                            onerror="this.style.display='none';this.nextElementSibling.style.display='inline';"
                                                        >
                                                        <span style="display:none;">
                                                            <?= tenantEscape(
                                                                tenantInitials(
                                                                    $tenant[
                                                                        'display_name'
                                                                    ]
                                                                )
                                                            ); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <?= tenantEscape(
                                                            tenantInitials(
                                                                $tenant[
                                                                    'display_name'
                                                                ]
                                                            )
                                                        ); ?>
                                                    <?php endif; ?>

                                                </span>

                                                <span>
                                                    <span class="tenant-company">
                                                        <?= tenantEscape(
                                                            $tenant[
                                                                'display_name'
                                                            ]
                                                        ); ?>
                                                    </span>

                                                    <span class="tenant-code">
                                                        <?= tenantEscape(
                                                            $tenant['tenant_code']
                                                        ); ?>
                                                    </span>
                                                </span>

                                            </div>
                                        </td>

                                        <td>
                                            <div class="tenant-contact">
                                                <strong>
                                                    <?= tenantEscape(
                                                        !empty(
                                                            $tenant['email']
                                                        )
                                                            ? $tenant['email']
                                                            : '—'
                                                    ); ?>
                                                </strong>

                                                <span>
                                                    <?= tenantEscape(
                                                        !empty(
                                                            $tenant['phone']
                                                        )
                                                            ? $tenant['phone']
                                                            : '—'
                                                    ); ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td>
                                            <?= tenantEscape(
                                                !empty(
                                                    $tenant['country']
                                                )
                                                    ? $tenant['country']
                                                    : '—'
                                            ); ?>
                                        </td>

                                        <td>
                                            <span class="tenant-plan-badge">
                                                <?= tenantEscape(
                                                    tenantLabel(
                                                        $tenant[
                                                            'plan_name'
                                                        ]
                                                    )
                                                ); ?>
                                            </span>
                                        </td>

                                        <td style="text-align:center;">
                                            <?= number_format(
                                                (int)
                                                $tenant['user_count']
                                            ); ?>
                                        </td>

                                        <td style="text-align:center;">
                                            <?= number_format(
                                                (int)
                                                $tenant['branch_count']
                                            ); ?>
                                        </td>

                                        <td>
                                            <span
                                                class="tenant-status <?= tenantEscape(
                                                    $tenant['status']
                                                ); ?>"
                                            >
                                                <?= tenantEscape(
                                                    tenantLabel(
                                                        $tenant['status']
                                                    )
                                                ); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= tenantEscape(
                                                tenantDate(
                                                    $tenant['created_at']
                                                )
                                            ); ?>
                                        </td>

                                        <td>
                                            <div class="tenant-actions">

                                                <a
                                                    href="tenant-view.php?id=<?= (int)
                                                        $tenant['id']; ?>"
                                                    class="tenant-action"
                                                    title="View Tenant"
                                                >
                                                    <i class="bi bi-eye"></i>
                                                </a>

                                                <a
                                                    href="tenant-edit.php?id=<?= (int)
                                                        $tenant['id']; ?>"
                                                    class="tenant-action"
                                                    title="Edit Tenant"
                                                >
                                                    <i class="bi bi-pencil"></i>
                                                </a>

                                                <a
                                                    href="tenant-users.php?tenant_id=<?= (int)
                                                        $tenant['id']; ?>"
                                                    class="tenant-action"
                                                    title="Tenant Users"
                                                >
                                                    <i class="bi bi-people"></i>
                                                </a>

                                            </div>
                                        </td>

                                    </tr>
                                <?php endforeach; ?>

                                </tbody>
                            </table>

                        </div>

                    <?php endif; ?>

                    <div class="tenant-pagination-bar">

                        <div class="tenant-pagination-info">
                            Showing
                            <?= number_format($startRecord); ?>
                            to
                            <?= number_format($endRecord); ?>
                            of
                            <?= number_format($totalRecords); ?>
                            tenants
                        </div>

                        <?php if ($totalPages > 1): ?>

                            <ul class="tenant-pagination">

                                <li>
                                    <?php if ($page > 1): ?>
                                        <a
                                            href="<?= tenantEscape(
                                                tenantUrl(
                                                    array(
                                                        'page' => 1
                                                    )
                                                )
                                            ); ?>"
                                            title="First Page"
                                        >
                                            <i class="bi bi-chevron-double-left"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="disabled">
                                            <i class="bi bi-chevron-double-left"></i>
                                        </span>
                                    <?php endif; ?>
                                </li>

                                <li>
                                    <?php if ($page > 1): ?>
                                        <a
                                            href="<?= tenantEscape(
                                                tenantUrl(
                                                    array(
                                                        'page' => $page - 1
                                                    )
                                                )
                                            ); ?>"
                                            title="Previous Page"
                                        >
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="disabled">
                                            <i class="bi bi-chevron-left"></i>
                                        </span>
                                    <?php endif; ?>
                                </li>

                                <?php
                                if ($paginationStart > 1):
                                ?>
                                    <li>
                                        <a
                                            href="<?= tenantEscape(
                                                tenantUrl(
                                                    array(
                                                        'page' => 1
                                                    )
                                                )
                                            ); ?>"
                                        >
                                            1
                                        </a>
                                    </li>

                                    <?php
                                    if ($paginationStart > 2):
                                    ?>
                                        <li>
                                            <span>…</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php
                                for (
                                    $pageNumber =
                                        $paginationStart;
                                    $pageNumber <=
                                        $paginationEnd;
                                    $pageNumber++
                                ):
                                ?>
                                    <li>
                                        <?php
                                        if (
                                            $pageNumber === $page
                                        ):
                                        ?>
                                            <span class="active">
                                                <?= (int)
                                                    $pageNumber; ?>
                                            </span>
                                        <?php else: ?>
                                            <a
                                                href="<?= tenantEscape(
                                                    tenantUrl(
                                                        array(
                                                            'page' =>
                                                                $pageNumber
                                                        )
                                                    )
                                                ); ?>"
                                            >
                                                <?= (int)
                                                    $pageNumber; ?>
                                            </a>
                                        <?php endif; ?>
                                    </li>
                                <?php endfor; ?>

                                <?php
                                if (
                                    $paginationEnd <
                                    $totalPages
                                ):
                                ?>

                                    <?php
                                    if (
                                        $paginationEnd <
                                        $totalPages - 1
                                    ):
                                    ?>
                                        <li>
                                            <span>…</span>
                                        </li>
                                    <?php endif; ?>

                                    <li>
                                        <a
                                            href="<?= tenantEscape(
                                                tenantUrl(
                                                    array(
                                                        'page' =>
                                                            $totalPages
                                                    )
                                                )
                                            ); ?>"
                                        >
                                            <?= (int)
                                                $totalPages; ?>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <li>
                                    <?php
                                    if ($page < $totalPages):
                                    ?>
                                        <a
                                            href="<?= tenantEscape(
                                                tenantUrl(
                                                    array(
                                                        'page' =>
                                                            $page + 1
                                                    )
                                                )
                                            ); ?>"
                                            title="Next Page"
                                        >
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="disabled">
                                            <i class="bi bi-chevron-right"></i>
                                        </span>
                                    <?php endif; ?>
                                </li>

                                <li>
                                    <?php
                                    if ($page < $totalPages):
                                    ?>
                                        <a
                                            href="<?= tenantEscape(
                                                tenantUrl(
                                                    array(
                                                        'page' =>
                                                            $totalPages
                                                    )
                                                )
                                            ); ?>"
                                            title="Last Page"
                                        >
                                            <i class="bi bi-chevron-double-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="disabled">
                                            <i class="bi bi-chevron-double-right"></i>
                                        </span>
                                    <?php endif; ?>
                                </li>

                            </ul>

                        <?php endif; ?>

                    </div>

                </section>

            </div>

        </div>

    </main>

</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>
(function () {
    'use strict';

    /*
    |--------------------------------------------------------------------------
    | Persistent Sidebar
    |--------------------------------------------------------------------------
    */

    var body = document.body;
    var toggle =
        document.getElementById('fpSidebarToggle');
    var close =
        document.getElementById('fpSidebarClose');
    var overlay =
        document.getElementById('fpSidebarOverlay');

    var SIDEBAR_STORAGE_KEY =
        'fieldplx_sidebar_collapsed';

    function restoreSidebarState() {
        if (window.innerWidth < 992) {
            body.classList.remove(
                'fp-sidebar-collapsed'
            );
            return;
        }

        var savedState =
            localStorage.getItem(
                SIDEBAR_STORAGE_KEY
            );

        if (savedState === '1') {
            body.classList.add(
                'fp-sidebar-collapsed'
            );
        } else {
            body.classList.remove(
                'fp-sidebar-collapsed'
            );
        }
    }

    function saveSidebarState() {
        var isCollapsed =
            body.classList.contains(
                'fp-sidebar-collapsed'
            );

        localStorage.setItem(
            SIDEBAR_STORAGE_KEY,
            isCollapsed ? '1' : '0'
        );
    }

    restoreSidebarState();

    if (toggle) {
        toggle.addEventListener(
            'click',
            function () {
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
            }
        );
    }

    if (close) {
        close.addEventListener(
            'click',
            function () {
                body.classList.remove(
                    'fp-sidebar-mobile-open'
                );
            }
        );
    }

    if (overlay) {
        overlay.addEventListener(
            'click',
            function () {
                body.classList.remove(
                    'fp-sidebar-mobile-open'
                );
            }
        );
    }

    document
        .querySelectorAll(
            '.fp-sidebar-menu-toggle'
        )
        .forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    var menu =
                        button.closest(
                            '.fp-sidebar-menu'
                        );

                    if (menu) {
                        menu.classList.toggle(
                            'open'
                        );
                    }
                }
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Search & Filters
    |--------------------------------------------------------------------------
    */

    var form =
        document.getElementById(
            'tenantFilterForm'
        );

    var searchInput =
        document.getElementById(
            'tenantSearchInput'
        );

    var statusFilter =
        document.getElementById(
            'tenantStatusFilter'
        );

    var planFilter =
        document.getElementById(
            'tenantPlanFilter'
        );

    var perPageFilter =
        document.getElementById(
            'tenantPerPage'
        );

    var pageInput =
        document.getElementById(
            'tenantPageInput'
        );

    var countdown =
        document.getElementById(
            'tenantSearchCountdown'
        );

    var searchTimer = null;
    var countdownTimer = null;

    function submitFilters() {
        if (!form) {
            return;
        }

        if (pageInput) {
            pageInput.value = '1';
        }

        form.submit();
    }

    /*
     * Search runs 3 seconds after
     * the user stops typing.
     */

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            function () {
                window.clearTimeout(
                    searchTimer
                );

                window.clearInterval(
                    countdownTimer
                );

                var secondsLeft = 3;

                if (countdown) {
                    countdown.textContent =
                        'Searching in 3 seconds...';
                }

                countdownTimer =
                    window.setInterval(
                        function () {
                            secondsLeft--;

                            if (
                                secondsLeft > 0 &&
                                countdown
                            ) {
                                countdown.textContent =
                                    'Searching in ' +
                                    secondsLeft +
                                    ' second' +
                                    (
                                        secondsLeft === 1
                                            ? ''
                                            : 's'
                                    ) +
                                    '...';
                            }
                        },
                        1000
                    );

                searchTimer =
                    window.setTimeout(
                        function () {
                            window.clearInterval(
                                countdownTimer
                            );

                            if (countdown) {
                                countdown.textContent =
                                    'Loading...';
                            }

                            submitFilters();
                        },
                        3000
                    );
            }
        );

        searchInput.addEventListener(
            'keydown',
            function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();

                    window.clearTimeout(
                        searchTimer
                    );

                    window.clearInterval(
                        countdownTimer
                    );

                    submitFilters();
                }
            }
        );
    }

    [
        statusFilter,
        planFilter,
        perPageFilter
    ].forEach(function (control) {
        if (!control) {
            return;
        }

        control.addEventListener(
            'change',
            submitFilters
        );
    });

})();
</script>

</body>
</html>