<?php
/**
 * FieldPlx Platform Panel Topbar
 *
 * Required variables before including this file:
 *
 * $pageTitle  = 'Platform Dashboard - FieldPlx';
 * $activePage = 'dashboard';
 *
 * Optional:
 *
 * $basePath = '';
 * $hidePlatformSearch = false;
 *
 * Compatible with PHP 7.2.
 */

require_once __DIR__ . '/auth.php';

/*
|--------------------------------------------------------------------------
| Page defaults
|--------------------------------------------------------------------------
*/

$pageTitle = isset($pageTitle) && trim((string) $pageTitle) !== ''
    ? trim((string) $pageTitle)
    : 'FieldPlx Platform';

$activePage = isset($activePage)
    ? trim((string) $activePage)
    : '';

$basePath = isset($basePath)
    ? (string) $basePath
    : '';

$hidePlatformSearch = !empty($hidePlatformSearch);

/*
|--------------------------------------------------------------------------
| Platform user
|--------------------------------------------------------------------------
*/

$platformUser = platformAuthUser();

$platformUserName = !empty($platformUser['name'])
    ? $platformUser['name']
    : 'Platform Administrator';

$platformUserEmail = !empty($platformUser['email'])
    ? $platformUser['email']
    : '';

$platformRoleName = !empty($platformUser['role_name'])
    ? $platformUser['role_name']
    : 'Administrator';

$platformAvatar = !empty($platformUser['avatar_path'])
    ? $basePath . '../' . ltrim($platformUser['avatar_path'], '/')
    : '';

/*
|--------------------------------------------------------------------------
| User initials
|--------------------------------------------------------------------------
*/

$platformInitials = '';
$nameParts = preg_split('/\s+/', trim($platformUserName));

if (!empty($nameParts[0])) {
    $platformInitials .= strtoupper(
        substr($nameParts[0], 0, 1)
    );
}

if (count($nameParts) > 1) {
    $lastName = end($nameParts);

    if ($lastName !== '') {
        $platformInitials .= strtoupper(
            substr($lastName, 0, 1)
        );
    }
}

if ($platformInitials === '') {
    $platformInitials = 'PA';
}

/*
|--------------------------------------------------------------------------
| Platform dashboard counts
|--------------------------------------------------------------------------
*/

$pendingTenantCount = 0;
$trialTenantCount = 0;
$suspendedTenantCount = 0;

$countResult = $conn->query("
    SELECT
        SUM(
            CASE
                WHEN status = 'pending' THEN 1
                ELSE 0
            END
        ) AS pending_count,

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
");

if ($countResult) {
    $countRow = $countResult->fetch_assoc();

    $pendingTenantCount = isset($countRow['pending_count'])
        ? (int) $countRow['pending_count']
        : 0;

    $trialTenantCount = isset($countRow['trial_count'])
        ? (int) $countRow['trial_count']
        : 0;

    $suspendedTenantCount = isset($countRow['suspended_count'])
        ? (int) $countRow['suspended_count']
        : 0;

    $countResult->free();
}

$platformAlertCount =
    $pendingTenantCount +
    $suspendedTenantCount;

/*
|--------------------------------------------------------------------------
| Recent tenant alerts
|--------------------------------------------------------------------------
*/

$recentTenantAlerts = array();

$alertStmt = $conn->prepare("
    SELECT
        id,
        company_name,
        status,
        trial_ends_at,
        created_at
    FROM tenants
    WHERE deleted_at IS NULL
      AND status IN ('pending', 'trial', 'suspended')
    ORDER BY
        CASE
            WHEN status = 'suspended' THEN 1
            WHEN status = 'pending' THEN 2
            ELSE 3
        END,
        created_at DESC
    LIMIT 6
");

if ($alertStmt) {
    $alertStmt->execute();
    $alertResult = $alertStmt->get_result();

    while ($alertRow = $alertResult->fetch_assoc()) {
        $recentTenantAlerts[] = $alertRow;
    }

    $alertStmt->close();
}

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

if (!function_exists('platformEscape')) {
    function platformEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('platformStatusLabel')) {
    function platformStatusLabel($status)
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

if (!function_exists('platformTimeAgo')) {
    function platformTimeAgo($dateTime)
    {
        if (empty($dateTime)) {
            return '';
        }

        $timestamp = strtotime($dateTime);

        if ($timestamp === false) {
            return '';
        }

        $difference = time() - $timestamp;

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

        return date('d M Y, h:i A', $timestamp);
    }
}

/*
|--------------------------------------------------------------------------
| Clean page heading
|--------------------------------------------------------------------------
*/

$cleanPageTitle = str_replace(
    array(
        ' - FieldPlx',
        'FieldPlx - ',
        'Platform - '
    ),
    '',
    $pageTitle
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

    <title><?= platformEscape($pageTitle); ?></title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        href="<?= platformEscape($basePath); ?>assets/css/platform.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --platform-primary: #111827;
            --platform-accent: #7c3aed;
            --platform-accent-dark: #6d28d9;
            --platform-text: #1f2937;
            --platform-muted: #6b7280;
            --platform-border: #e5e7eb;
            --platform-background: #f4f6f9;
            --platform-surface: #ffffff;
            --platform-danger: #dc2626;
            --platform-warning: #d97706;
            --platform-success: #059669;
            --platform-topbar-height: 66px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background: var(--platform-background);
            color: var(--platform-text);
            font-family: "Inter", sans-serif;
            font-size: 13px;
        }

        button,
        input,
        select,
        textarea {
            font-family: inherit;
        }

        .platform-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            min-height: var(--platform-topbar-height);
            background: rgba(255, 255, 255, 0.97);
            border-bottom: 1px solid var(--platform-border);
            backdrop-filter: blur(14px);
        }

        .platform-topbar-inner {
            min-height: var(--platform-topbar-height);
            padding: 8px 18px;
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .platform-menu-toggle {
            width: 38px;
            height: 38px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--platform-border);
            border-radius: 10px;
            background: #ffffff;
            color: #4b5563;
            font-size: 19px;
        }

        .platform-menu-toggle:hover {
            border-color: #d8ccfb;
            background: #faf8ff;
            color: var(--platform-accent);
        }

        .platform-mobile-brand {
            display: none;
            align-items: center;
            gap: 9px;
            color: #111827;
            text-decoration: none;
        }

        .platform-mobile-logo {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
        }

        .platform-mobile-brand-text {
            font-size: 13px;
            font-weight: 700;
        }

        .platform-heading {
            min-width: 0;
            margin-right: auto;
        }

        .platform-page-title {
            margin: 0;
            overflow: hidden;
            color: #111827;
            font-size: 15px;
            font-weight: 700;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .platform-page-subtitle {
            margin-top: 2px;
            color: var(--platform-muted);
            font-size: 10px;
        }

        .platform-search {
            width: min(340px, 31vw);
            position: relative;
        }

        .platform-search-icon {
            position: absolute;
            top: 50%;
            left: 12px;
            z-index: 2;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 14px;
            pointer-events: none;
        }

        .platform-search-input {
            height: 39px;
            padding: 8px 13px 8px 36px;
            border: 1px solid var(--platform-border);
            border-radius: 10px;
            background: #f9fafb;
            box-shadow: none;
            font-size: 12px;
        }

        .platform-search-input:focus {
            border-color: #c4b5fd;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.09);
        }

        .platform-topbar-button {
            width: 39px;
            height: 39px;
            padding: 0;
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--platform-border);
            border-radius: 10px;
            background: #ffffff;
            color: #4b5563;
            font-size: 17px;
        }

        .platform-topbar-button:hover {
            border-color: #d8ccfb;
            background: #faf8ff;
            color: var(--platform-accent);
        }

        .platform-alert-count {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
            border-radius: 999px;
            background: var(--platform-danger);
            color: #ffffff;
            font-size: 9px;
            font-weight: 700;
        }

        .platform-profile-button {
            min-width: 0;
            padding: 4px 8px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--platform-border);
            border-radius: 11px;
            background: #ffffff;
            text-align: left;
        }

        .platform-profile-button:hover {
            border-color: #d8ccfb;
            background: #faf8ff;
        }

        .platform-avatar {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: linear-gradient(
                135deg,
                #111827,
                #7c3aed
            );
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
        }

        .platform-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .platform-profile-details {
            max-width: 155px;
            min-width: 0;
        }

        .platform-profile-name,
        .platform-profile-role {
            overflow: hidden;
            display: block;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .platform-profile-name {
            color: #111827;
            font-size: 11px;
            font-weight: 700;
        }

        .platform-profile-role {
            margin-top: 1px;
            color: var(--platform-muted);
            font-size: 9px;
        }

        .platform-alert-dropdown {
            width: 350px;
            padding: 0;
            overflow: hidden;
            border: 1px solid var(--platform-border);
            border-radius: 13px;
            box-shadow: 0 18px 50px rgba(31, 41, 55, 0.14);
        }

        .platform-dropdown-header {
            padding: 13px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--platform-border);
            background: #ffffff;
        }

        .platform-dropdown-title {
            margin: 0;
            color: #111827;
            font-size: 13px;
            font-weight: 700;
        }

        .platform-dropdown-counts {
            padding: 10px 13px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 7px;
            border-bottom: 1px solid #f0f1f3;
            background: #fafafa;
        }

        .platform-count-box {
            padding: 8px 5px;
            border: 1px solid #eceef1;
            border-radius: 9px;
            background: #ffffff;
            text-align: center;
        }

        .platform-count-number {
            display: block;
            color: #111827;
            font-size: 13px;
            font-weight: 700;
        }

        .platform-count-label {
            margin-top: 2px;
            display: block;
            color: #9ca3af;
            font-size: 8px;
            text-transform: uppercase;
        }

        .platform-alert-item {
            padding: 11px 14px;
            display: flex;
            gap: 10px;
            border-bottom: 1px solid #f1f2f4;
            color: inherit;
            text-decoration: none;
        }

        .platform-alert-item:hover {
            background: #faf8ff;
        }

        .platform-alert-icon {
            width: 33px;
            height: 33px;
            flex: 0 0 33px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            font-size: 14px;
        }

        .platform-alert-icon.pending {
            background: #fff7ed;
            color: #d97706;
        }

        .platform-alert-icon.trial {
            background: #eff6ff;
            color: #2563eb;
        }

        .platform-alert-icon.suspended {
            background: #fef2f2;
            color: #dc2626;
        }

        .platform-alert-content {
            min-width: 0;
            flex: 1;
        }

        .platform-alert-company {
            overflow: hidden;
            display: block;
            color: #111827;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .platform-alert-message {
            margin-top: 2px;
            display: block;
            color: var(--platform-muted);
            font-size: 9px;
        }

        .platform-alert-time {
            margin-top: 4px;
            display: block;
            color: #9ca3af;
            font-size: 8px;
        }

        .platform-empty-alerts {
            padding: 30px 15px;
            color: var(--platform-muted);
            text-align: center;
            font-size: 11px;
        }

        .platform-empty-alerts i {
            margin-bottom: 8px;
            display: block;
            color: #86efac;
            font-size: 27px;
        }

        .platform-dropdown-footer {
            padding: 10px 14px;
            background: #ffffff;
            text-align: center;
        }

        .platform-dropdown-footer a {
            color: var(--platform-accent);
            font-size: 10px;
            font-weight: 600;
            text-decoration: none;
        }

        .platform-profile-menu {
            width: 240px;
            padding: 7px;
            border: 1px solid var(--platform-border);
            border-radius: 12px;
            box-shadow: 0 18px 50px rgba(31, 41, 55, 0.14);
        }

        .platform-profile-menu-header {
            padding: 9px 10px 11px;
            border-bottom: 1px solid #f0f1f3;
        }

        .platform-profile-menu-name {
            overflow: hidden;
            color: #111827;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .platform-profile-menu-email {
            margin-top: 2px;
            overflow: hidden;
            color: var(--platform-muted);
            font-size: 9px;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .platform-profile-menu .dropdown-item {
            padding: 9px 10px;
            display: flex;
            align-items: center;
            gap: 9px;
            border-radius: 8px;
            color: #374151;
            font-size: 11px;
        }

        .platform-profile-menu .dropdown-item:hover {
            background: #faf8ff;
            color: var(--platform-accent);
        }

        .platform-profile-menu .dropdown-item.text-danger:hover {
            background: #fff5f5;
            color: #b91c1c !important;
        }

        .platform-main-layout {
            display: flex;
            min-height: calc(
                100vh - var(--platform-topbar-height)
            );
        }

        .platform-main-content {
            min-width: 0;
            flex: 1;
        }

        .platform-content-wrapper {
            padding: 18px;
        }

        @media (max-width: 991.98px) {
            .platform-mobile-brand {
                display: flex;
            }

            .platform-heading {
                display: none;
            }

            .platform-search {
                width: min(280px, 39vw);
                margin-left: auto;
            }

            .platform-profile-details {
                display: none;
            }

            .platform-profile-button {
                padding-right: 5px;
            }
        }

        @media (max-width: 767.98px) {
            .platform-topbar-inner {
                gap: 8px;
                padding: 8px 11px;
            }

            .platform-mobile-brand-text {
                display: none;
            }

            .platform-search {
                display: none;
            }

            .platform-topbar-spacer {
                margin-left: auto;
            }

            .platform-alert-dropdown {
                width: min(
                    350px,
                    calc(100vw - 22px)
                );
            }

            .platform-content-wrapper {
                padding: 12px;
            }
        }
    </style>
</head>

<body>

<header class="platform-topbar">
    <div class="platform-topbar-inner">

        <button
            type="button"
            class="platform-menu-toggle"
            id="platformSidebarToggle"
            aria-label="Toggle platform sidebar"
        >
            <i class="bi bi-list"></i>
        </button>

        <a
            href="<?= platformEscape($basePath); ?>dashboard.php"
            class="platform-mobile-brand"
        >
            <span class="platform-mobile-logo">
                FP
            </span>

            <span class="platform-mobile-brand-text">
                FieldPlx Platform
            </span>
        </a>

        <div class="platform-heading">
            <h1 class="platform-page-title">
                <?= platformEscape($cleanPageTitle); ?>
            </h1>

            <div class="platform-page-subtitle">
                FieldPlx Platform Administration
            </div>
        </div>

        <?php if (!$hidePlatformSearch): ?>
            <div class="platform-search">
                <i class="bi bi-search platform-search-icon"></i>

                <input
                    type="search"
                    class="form-control platform-search-input"
                    id="platformGlobalSearch"
                    placeholder="Search tenants, users or subscriptions..."
                    autocomplete="off"
                    aria-label="Search platform"
                >
            </div>
        <?php else: ?>
            <div class="platform-topbar-spacer"></div>
        <?php endif; ?>

        <a
            href="../dashboard.php"
            class="platform-topbar-button"
            title="Open tenant application"
            aria-label="Open tenant application"
        >
            <i class="bi bi-box-arrow-up-right"></i>
        </a>

        <div class="dropdown">
            <button
                type="button"
                class="platform-topbar-button"
                data-bs-toggle="dropdown"
                data-bs-auto-close="outside"
                aria-expanded="false"
                aria-label="Platform alerts"
            >
                <i class="bi bi-bell"></i>

                <?php if ($platformAlertCount > 0): ?>
                    <span class="platform-alert-count">
                        <?= $platformAlertCount > 99
                            ? '99+'
                            : platformEscape(
                                $platformAlertCount
                            ); ?>
                    </span>
                <?php endif; ?>
            </button>

            <div
                class="dropdown-menu dropdown-menu-end platform-alert-dropdown"
            >
                <div class="platform-dropdown-header">
                    <h2 class="platform-dropdown-title">
                        Platform Alerts
                    </h2>

                    <a
                        href="<?= platformEscape($basePath); ?>tenants.php"
                        class="text-decoration-none"
                        style="font-size:9px;color:#7c3aed;"
                    >
                        Manage tenants
                    </a>
                </div>

                <div class="platform-dropdown-counts">
                    <div class="platform-count-box">
                        <span class="platform-count-number">
                            <?= platformEscape(
                                $pendingTenantCount
                            ); ?>
                        </span>

                        <span class="platform-count-label">
                            Pending
                        </span>
                    </div>

                    <div class="platform-count-box">
                        <span class="platform-count-number">
                            <?= platformEscape(
                                $trialTenantCount
                            ); ?>
                        </span>

                        <span class="platform-count-label">
                            Trial
                        </span>
                    </div>

                    <div class="platform-count-box">
                        <span class="platform-count-number">
                            <?= platformEscape(
                                $suspendedTenantCount
                            ); ?>
                        </span>

                        <span class="platform-count-label">
                            Suspended
                        </span>
                    </div>
                </div>

                <div>
                    <?php if (empty($recentTenantAlerts)): ?>
                        <div class="platform-empty-alerts">
                            <i class="bi bi-check-circle"></i>
                            No platform alerts available.
                        </div>
                    <?php else: ?>
                        <?php foreach (
                            $recentTenantAlerts as $tenantAlert
                        ): ?>
                            <?php
                            $tenantStatus = strtolower(
                                (string) $tenantAlert['status']
                            );

                            $alertMessage = platformStatusLabel(
                                $tenantStatus
                            );

                            if (
                                $tenantStatus === 'trial' &&
                                !empty(
                                    $tenantAlert['trial_ends_at']
                                )
                            ) {
                                $alertMessage =
                                    'Trial ends ' .
                                    date(
                                        'd M Y',
                                        strtotime(
                                            $tenantAlert[
                                                'trial_ends_at'
                                            ]
                                        )
                                    );
                            }
                            ?>

                            <a
                                href="<?= platformEscape(
                                    $basePath
                                ); ?>tenant-view.php?id=<?= (int) $tenantAlert['id']; ?>"
                                class="platform-alert-item"
                            >
                                <span
                                    class="platform-alert-icon <?= platformEscape(
                                        $tenantStatus
                                    ); ?>"
                                >
                                    <?php if (
                                        $tenantStatus === 'suspended'
                                    ): ?>
                                        <i class="bi bi-slash-circle"></i>
                                    <?php elseif (
                                        $tenantStatus === 'pending'
                                    ): ?>
                                        <i class="bi bi-hourglass-split"></i>
                                    <?php else: ?>
                                        <i class="bi bi-clock-history"></i>
                                    <?php endif; ?>
                                </span>

                                <span class="platform-alert-content">
                                    <span class="platform-alert-company">
                                        <?= platformEscape(
                                            $tenantAlert[
                                                'company_name'
                                            ]
                                        ); ?>
                                    </span>

                                    <span class="platform-alert-message">
                                        <?= platformEscape(
                                            $alertMessage
                                        ); ?>
                                    </span>

                                    <span class="platform-alert-time">
                                        <?= platformEscape(
                                            platformTimeAgo(
                                                $tenantAlert[
                                                    'created_at'
                                                ]
                                            )
                                        ); ?>
                                    </span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="platform-dropdown-footer">
                    <a
                        href="<?= platformEscape($basePath); ?>tenants.php"
                    >
                        View all tenants
                    </a>
                </div>
            </div>
        </div>

        <div class="dropdown">
            <button
                type="button"
                class="platform-profile-button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <span class="platform-avatar">
                    <?php if ($platformAvatar !== ''): ?>
                        <img
                            src="<?= platformEscape(
                                $platformAvatar
                            ); ?>"
                            alt="<?= platformEscape(
                                $platformUserName
                            ); ?>"
                        >
                    <?php else: ?>
                        <?= platformEscape(
                            $platformInitials
                        ); ?>
                    <?php endif; ?>
                </span>

                <span class="platform-profile-details">
                    <span class="platform-profile-name">
                        <?= platformEscape(
                            $platformUserName
                        ); ?>
                    </span>

                    <span class="platform-profile-role">
                        <?= platformEscape(
                            $platformRoleName
                        ); ?>
                    </span>
                </span>

                <i
                    class="bi bi-chevron-down"
                    style="font-size:10px;color:#9ca3af;"
                ></i>
            </button>

            <div
                class="dropdown-menu dropdown-menu-end platform-profile-menu"
            >
                <div class="platform-profile-menu-header">
                    <div class="platform-profile-menu-name">
                        <?= platformEscape(
                            $platformUserName
                        ); ?>
                    </div>

                    <div class="platform-profile-menu-email">
                        <?= platformEscape(
                            $platformUserEmail
                        ); ?>
                    </div>
                </div>

                <a
                    href="<?= platformEscape($basePath); ?>profile.php"
                    class="dropdown-item mt-1"
                >
                    <i class="bi bi-person"></i>
                    My Profile
                </a>

                <?php if (
                    hasPlatformRole(
                        array(
                            'super_admin',
                            'platform_admin'
                        )
                    )
                ): ?>
                    <a
                        href="<?= platformEscape($basePath); ?>settings.php"
                        class="dropdown-item"
                    >
                        <i class="bi bi-gear"></i>
                        Platform Settings
                    </a>
                <?php endif; ?>

                <a
                    href="<?= platformEscape($basePath); ?>activity-logs.php"
                    class="dropdown-item"
                >
                    <i class="bi bi-clock-history"></i>
                    Activity Logs
                </a>

                <div class="dropdown-divider my-1"></div>

                <a
                    href="<?= platformEscape($basePath); ?>logout.php"
                    class="dropdown-item text-danger"
                >
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </div>
        </div>

    </div>
</header>

<div class="platform-main-layout">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="platform-main-content">
        <div class="platform-content-wrapper">

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById(
        'platformGlobalSearch'
    );

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            document.dispatchEvent(
                new CustomEvent('fieldplx:platform-search', {
                    detail: {
                        value: searchInput.value.trim()
                    }
                })
            );
        });

        searchInput.addEventListener('keydown', function (event) {
            if (
                event.key === 'Enter' &&
                searchInput.value.trim() !== ''
            ) {
                event.preventDefault();

                window.location.href =
                    '<?= platformEscape($basePath); ?>search.php?q=' +
                    encodeURIComponent(
                        searchInput.value.trim()
                    );
            }
        });
    }
});
</script>