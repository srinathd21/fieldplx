<?php
/**
 * FieldPlx Shared Topbar
 *
 * Required page variables:
 *
 * $pageTitle         = 'Dashboard - FieldPlx';
 * $activePage        = 'dashboard';
 * $searchPlaceholder = 'Search...';
 *
 * For files inside a subfolder, define:
 *
 * $basePath = '../';
 *
 * before requiring this file.
 *
 * Compatible with PHP 7.2.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';

/*
|--------------------------------------------------------------------------
| Page defaults
|--------------------------------------------------------------------------
*/

$pageTitle = isset($pageTitle) && trim($pageTitle) !== ''
    ? trim($pageTitle)
    : 'FieldPlx';

$activePage = isset($activePage)
    ? trim($activePage)
    : '';

$searchPlaceholder = isset($searchPlaceholder)
    && trim($searchPlaceholder) !== ''
        ? trim($searchPlaceholder)
        : 'Search...';

$basePath = isset($basePath)
    ? (string) $basePath
    : '';

$hideGlobalSearch = !empty($hideGlobalSearch);


$canViewNotifications = (
    (
        function_exists('tenantHasModule')
            ? tenantHasModule('communication')
            : true
    ) &&
    (
        (function_exists('hasPermission') && hasPermission('notifications.view')) ||
        (function_exists('isTenantOwner') && isTenantOwner())
    )
);

$canViewSettings = (
    (
        function_exists('tenantHasModule')
            ? tenantHasModule('administration')
            : true
    ) &&
    function_exists('hasPermission') &&
    hasPermission('settings.view')
);

/*
|--------------------------------------------------------------------------
| Logged-in user details
|--------------------------------------------------------------------------
*/

$currentUser = function_exists('authUser')
    ? authUser()
    : array();

$userName = !empty($currentUser['name'])
    ? $currentUser['name']
    : 'User';

$userEmail = !empty($currentUser['email'])
    ? $currentUser['email']
    : '';

$userRole = !empty($currentUser['role_name'])
    ? $currentUser['role_name']
    : 'User';

$userAvatar = !empty($currentUser['avatar_path'])
    ? $basePath . ltrim($currentUser['avatar_path'], '/')
    : '';

$companyName = !empty($_SESSION['company_name'])
    ? $_SESSION['company_name']
    : 'FieldPlx';

$companyLogo = !empty($_SESSION['company_logo'])
    ? $basePath . ltrim($_SESSION['company_logo'], '/')
    : '';

$userInitials = '';

$nameParts = preg_split('/\s+/', trim($userName));

if (!empty($nameParts[0])) {
    $userInitials .= strtoupper(substr($nameParts[0], 0, 1));
}

if (count($nameParts) > 1) {
    $lastNamePart = end($nameParts);

    if ($lastNamePart !== '') {
        $userInitials .= strtoupper(substr($lastNamePart, 0, 1));
    }
}

if ($userInitials === '') {
    $userInitials = 'U';
}

/*
|--------------------------------------------------------------------------
| Unread notifications count
|--------------------------------------------------------------------------
*/

$tenantId = function_exists('currentTenantId')
    ? currentTenantId()
    : (int) ($_SESSION['tenant_id'] ?? 0);

$userId = function_exists('currentUserId')
    ? currentUserId()
    : (int) ($_SESSION['user_id'] ?? 0);
$unreadNotificationCount = 0;
$notificationTableAvailable = false;

if (
    $canViewNotifications &&
    function_exists('dbTableExists') &&
    dbTableExists($conn, 'notification_logs')
) {
    $notificationTableAvailable = true;

    $requiredNotificationColumns = array(
        'tenant_id',
        'recipient_type',
        'recipient_id',
        'channel',
        'status'
    );

    foreach ($requiredNotificationColumns as $columnName) {
        if (
            function_exists('dbColumnExists') &&
            !dbColumnExists(
                $conn,
                'notification_logs',
                $columnName
            )
        ) {
            $notificationTableAvailable = false;
            break;
        }
    }
}

if ($notificationTableAvailable) {
    $notificationStmt = $conn->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notification_logs
        WHERE tenant_id = ?
          AND recipient_type = 'user'
          AND recipient_id = ?
          AND channel = 'in_app'
          AND (
              status IS NULL
              OR status <> 'read'
          )
    ");

    if ($notificationStmt) {
        $notificationStmt->bind_param(
            'ii',
            $tenantId,
            $userId
        );

        $notificationStmt->execute();

        $notificationRow = $notificationStmt
            ->get_result()
            ->fetch_assoc();

        $unreadNotificationCount =
            isset($notificationRow['unread_count'])
                ? (int) $notificationRow['unread_count']
                : 0;

        $notificationStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Recent notifications
|--------------------------------------------------------------------------
*/

$recentNotifications = array();

if ($notificationTableAvailable) {
    $notificationColumns = array(
        'id',
        'event_key',
        'related_type',
        'related_id',
        'status',
        'payload_json',
        'created_at'
    );

    $allColumnsAvailable = true;

    foreach ($notificationColumns as $columnName) {
        if (
            function_exists('dbColumnExists') &&
            !dbColumnExists(
                $conn,
                'notification_logs',
                $columnName
            )
        ) {
            $allColumnsAvailable = false;
            break;
        }
    }

    if ($allColumnsAvailable) {
        $notificationStmt = $conn->prepare("
            SELECT
                id,
                event_key,
                related_type,
                related_id,
                status,
                payload_json,
                created_at
            FROM notification_logs
            WHERE tenant_id = ?
              AND recipient_type = 'user'
              AND recipient_id = ?
              AND channel = 'in_app'
            ORDER BY created_at DESC
            LIMIT 5
        ");

        if ($notificationStmt) {
            $notificationStmt->bind_param(
                'ii',
                $tenantId,
                $userId
            );

            $notificationStmt->execute();

            $notificationResult =
                $notificationStmt->get_result();

            while (
                $notificationRow =
                $notificationResult->fetch_assoc()
            ) {
                $payload = array();

                if (
                    !empty(
                        $notificationRow['payload_json']
                    )
                ) {
                    $decodedPayload = json_decode(
                        $notificationRow['payload_json'],
                        true
                    );

                    if (is_array($decodedPayload)) {
                        $payload = $decodedPayload;
                    }
                }

                $notificationRow['title'] =
                    !empty($payload['title'])
                        ? (string) $payload['title']
                        : statusLabel(
                            $notificationRow['event_key']
                        );

                $notificationRow['message'] =
                    !empty($payload['message'])
                        ? (string) $payload['message']
                        : '';

                $notificationRow['url'] =
                    !empty($payload['url'])
                        ? (string) $payload['url']
                        : 'notifications.php';

                $recentNotifications[] =
                    $notificationRow;
            }

            $notificationStmt->close();
        }
    }
}

$currentPage = basename(
    isset($_SERVER['PHP_SELF'])
        ? $_SERVER['PHP_SELF']
        : ''
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

    <meta
        name="csrf-token"
        content="<?= e(csrfToken()); ?>"
    >

    <title><?= e($pageTitle); ?></title>

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
        href="<?= e($basePath); ?>assets/css/styles.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --fieldplx-primary: #6d28d9;
            --fieldplx-primary-dark: #5b21b6;
            --fieldplx-text: #1f2937;
            --fieldplx-muted: #6b7280;
            --fieldplx-border: #e5e7eb;
            --fieldplx-surface: #ffffff;
            --fieldplx-background: #f7f7fb;
            --fieldplx-topbar-height: 64px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            background: var(--fieldplx-background);
            color: var(--fieldplx-text);
            font-family: "Inter", sans-serif;
            font-size: 13px;
        }

        .fieldplx-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            min-height: var(--fieldplx-topbar-height);
            background: rgba(255, 255, 255, 0.96);
            border-bottom: 1px solid var(--fieldplx-border);
            backdrop-filter: blur(12px);
        }

        .fieldplx-topbar-inner {
            min-height: var(--fieldplx-topbar-height);
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 8px 18px;
        }

        .fieldplx-brand-mobile {
            display: none;
            align-items: center;
            gap: 9px;
            min-width: 0;
            text-decoration: none;
            color: var(--fieldplx-text);
        }

        .fieldplx-brand-logo {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 9px;
            object-fit: contain;
            background: #f3f0ff;
        }

        .fieldplx-brand-placeholder {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            color: #ffffff;
            background: linear-gradient(135deg, #7c3aed, #5b21b6);
            font-size: 15px;
            font-weight: 700;
        }

        .fieldplx-brand-name {
            max-width: 170px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            font-size: 14px;
            font-weight: 700;
        }

        .fieldplx-menu-toggle {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--fieldplx-border);
            border-radius: 9px;
            background: #ffffff;
            color: #4b5563;
            font-size: 19px;
        }

        .fieldplx-menu-toggle:hover {
            color: var(--fieldplx-primary);
            border-color: #d8ccfb;
            background: #faf8ff;
        }

        .fieldplx-page-heading {
            min-width: 0;
            margin-right: auto;
        }

        .fieldplx-page-title {
            margin: 0;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            color: #111827;
            font-size: 15px;
            font-weight: 700;
        }

        .fieldplx-page-subtitle {
            margin-top: 2px;
            color: var(--fieldplx-muted);
            font-size: 11px;
        }

        .fieldplx-search-wrap {
            width: min(340px, 31vw);
            position: relative;
        }

        .fieldplx-search-icon {
            position: absolute;
            top: 50%;
            left: 12px;
            z-index: 2;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 14px;
            pointer-events: none;
        }

        .fieldplx-search-input {
            height: 38px;
            padding: 8px 13px 8px 35px;
            border: 1px solid var(--fieldplx-border);
            border-radius: 10px;
            background: #f9fafb;
            box-shadow: none;
            font-size: 12px;
        }

        .fieldplx-search-input:focus {
            border-color: #c4b5fd;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.09);
        }

        .fieldplx-topbar-action {
            width: 38px;
            height: 38px;
            padding: 0;
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--fieldplx-border);
            border-radius: 10px;
            background: #ffffff;
            color: #4b5563;
            font-size: 17px;
        }

        .fieldplx-topbar-action:hover {
            color: var(--fieldplx-primary);
            border-color: #d8ccfb;
            background: #faf8ff;
        }

        .fieldplx-notification-count {
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
            background: #dc2626;
            color: #ffffff;
            font-size: 9px;
            font-weight: 700;
        }

        .fieldplx-profile-button {
            min-width: 0;
            padding: 4px 8px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--fieldplx-border);
            border-radius: 11px;
            background: #ffffff;
            text-align: left;
        }

        .fieldplx-profile-button:hover {
            border-color: #d8ccfb;
            background: #faf8ff;
        }

        .fieldplx-avatar {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            overflow: hidden;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: linear-gradient(135deg, #7c3aed, #5b21b6);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
        }

        .fieldplx-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .fieldplx-profile-details {
            max-width: 145px;
            min-width: 0;
        }

        .fieldplx-profile-name,
        .fieldplx-profile-role {
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .fieldplx-profile-name {
            color: #111827;
            font-size: 11px;
            font-weight: 700;
        }

        .fieldplx-profile-role {
            margin-top: 1px;
            color: var(--fieldplx-muted);
            font-size: 9px;
        }

        .fieldplx-dropdown {
            width: 330px;
            padding: 0;
            overflow: hidden;
            border: 1px solid var(--fieldplx-border);
            border-radius: 13px;
            box-shadow: 0 18px 50px rgba(31, 41, 55, 0.13);
        }

        .fieldplx-dropdown-header {
            padding: 13px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--fieldplx-border);
            background: #ffffff;
        }

        .fieldplx-dropdown-title {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
        }

        .fieldplx-notification-item {
            padding: 11px 14px;
            display: flex;
            gap: 10px;
            border-bottom: 1px solid #f1f2f4;
            color: inherit;
            text-decoration: none;
        }

        .fieldplx-notification-item:hover {
            background: #faf8ff;
        }

        .fieldplx-notification-item.is-unread {
            background: #fbf9ff;
        }

        .fieldplx-notification-icon {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #f3e8ff;
            color: #7c3aed;
            font-size: 14px;
        }

        .fieldplx-notification-content {
            min-width: 0;
        }

        .fieldplx-notification-title {
            margin: 0;
            color: #111827;
            font-size: 11px;
            font-weight: 700;
        }

        .fieldplx-notification-message {
            margin-top: 3px;
            overflow: hidden;
            display: -webkit-box;
            color: var(--fieldplx-muted);
            font-size: 10px;
            line-height: 1.45;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .fieldplx-notification-time {
            margin-top: 4px;
            color: #9ca3af;
            font-size: 9px;
        }

        .fieldplx-empty-notifications {
            padding: 30px 15px;
            text-align: center;
            color: var(--fieldplx-muted);
        }

        .fieldplx-empty-notifications i {
            display: block;
            margin-bottom: 8px;
            color: #c4b5fd;
            font-size: 27px;
        }

        .fieldplx-dropdown-footer {
            padding: 10px 14px;
            text-align: center;
            background: #ffffff;
        }

        .fieldplx-dropdown-footer a {
            color: var(--fieldplx-primary);
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
        }

        .fieldplx-profile-menu {
            width: 230px;
            padding: 7px;
            border: 1px solid var(--fieldplx-border);
            border-radius: 12px;
            box-shadow: 0 18px 50px rgba(31, 41, 55, 0.13);
        }

        .fieldplx-profile-menu-header {
            padding: 9px 10px 11px;
            border-bottom: 1px solid #f0f1f3;
        }

        .fieldplx-profile-menu-name {
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            color: #111827;
            font-size: 12px;
            font-weight: 700;
        }

        .fieldplx-profile-menu-email {
            margin-top: 2px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            color: var(--fieldplx-muted);
            font-size: 10px;
        }

        .fieldplx-profile-menu .dropdown-item {
            padding: 9px 10px;
            display: flex;
            align-items: center;
            gap: 9px;
            border-radius: 8px;
            color: #374151;
            font-size: 11px;
        }

        .fieldplx-profile-menu .dropdown-item:hover {
            color: var(--fieldplx-primary);
            background: #faf8ff;
        }

        .fieldplx-profile-menu .dropdown-item.text-danger:hover {
            color: #b91c1c !important;
            background: #fff5f5;
        }

        .fieldplx-main-layout {
            display: flex;
            min-height: calc(100vh - var(--fieldplx-topbar-height));
        }

        .fieldplx-main-content {
            min-width: 0;
            flex: 1;
        }

        .fieldplx-content-wrapper {
            padding: 18px;
        }

        @media (max-width: 991.98px) {
            .fieldplx-brand-mobile {
                display: flex;
            }

            .fieldplx-page-heading {
                display: none;
            }

            .fieldplx-search-wrap {
                margin-left: auto;
                width: min(280px, 40vw);
            }

            .fieldplx-profile-details {
                display: none;
            }

            .fieldplx-profile-button {
                padding-right: 5px;
            }
        }

        @media (max-width: 767.98px) {
            .fieldplx-topbar-inner {
                gap: 8px;
                padding: 8px 11px;
            }

            .fieldplx-brand-name {
                display: none;
            }

            .fieldplx-search-wrap {
                display: none;
            }

            .fieldplx-topbar-spacer {
                margin-left: auto;
            }

            .fieldplx-dropdown {
                width: min(330px, calc(100vw - 22px));
            }

            .fieldplx-content-wrapper {
                padding: 12px;
            }
        }
    </style>
</head>

<body>

<header class="fieldplx-topbar">
    <div class="fieldplx-topbar-inner">

        <button
            type="button"
            class="fieldplx-menu-toggle"
            id="sidebarToggle"
            aria-label="Toggle sidebar"
        >
            <i class="bi bi-list"></i>
        </button>

        <a
            href="<?= e($basePath); ?>dashboard.php"
            class="fieldplx-brand-mobile"
        >
            <?php if ($companyLogo !== ''): ?>
                <img
                    src="<?= e($companyLogo); ?>"
                    alt="<?= e($companyName); ?>"
                    class="fieldplx-brand-logo"
                >
            <?php else: ?>
                <span class="fieldplx-brand-placeholder">
                    <?= e(strtoupper(substr($companyName, 0, 1))); ?>
                </span>
            <?php endif; ?>

            <span class="fieldplx-brand-name">
                <?= e($companyName); ?>
            </span>
        </a>

        <div class="fieldplx-page-heading">
            <h1 class="fieldplx-page-title">
                <?php
                $cleanPageTitle = str_replace(
                    ' - FieldPlx',
                    '',
                    $pageTitle
                );

                echo e($cleanPageTitle);
                ?>
            </h1>

            <div class="fieldplx-page-subtitle">
                <?= e($companyName); ?>
            </div>
        </div>

        <?php if (!$hideGlobalSearch): ?>
            <div class="fieldplx-search-wrap">
                <i class="bi bi-search fieldplx-search-icon"></i>

                <input
                    type="search"
                    class="form-control fieldplx-search-input"
                    id="globalSearchInput"
                    placeholder="<?= e($searchPlaceholder); ?>"
                    autocomplete="off"
                    aria-label="<?= e($searchPlaceholder); ?>"
                >
            </div>
        <?php else: ?>
            <div class="fieldplx-topbar-spacer"></div>
        <?php endif; ?>

        <?php if ($canViewNotifications): ?>
        <div class="dropdown">
            <button
                type="button"
                class="fieldplx-topbar-action"
                data-bs-toggle="dropdown"
                data-bs-auto-close="outside"
                aria-expanded="false"
                aria-label="Notifications"
                title="Notifications"
            >
                <i class="bi bi-bell"></i>

                <?php if ($unreadNotificationCount > 0): ?>
                    <span class="fieldplx-notification-count">
                        <?= $unreadNotificationCount > 99
                            ? '99+'
                            : e($unreadNotificationCount); ?>
                    </span>
                <?php endif; ?>
            </button>

            <div class="dropdown-menu dropdown-menu-end fieldplx-dropdown">
                <div class="fieldplx-dropdown-header">
                    <h2 class="fieldplx-dropdown-title">
                        Notifications
                    </h2>

                    <?php if ($unreadNotificationCount > 0): ?>
                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 text-decoration-none"
                            id="markAllNotificationsRead"
                            style="font-size:10px;color:#6d28d9;"
                        >
                            Mark all read
                        </button>
                    <?php endif; ?>
                </div>

                <div id="topbarNotificationList">
                    <?php if (empty($recentNotifications)): ?>
                        <div class="fieldplx-empty-notifications">
                            <i class="bi bi-bell-slash"></i>
                            No notifications available.
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentNotifications as $notification): ?>
                            <?php
                            $notificationUrl = $notification['url'];

                            $notificationUrl = trim(
                                (string) $notificationUrl
                            );

                            if (
                                preg_match(
                                    '/^(javascript|data):/i',
                                    $notificationUrl
                                )
                            ) {
                                $notificationUrl =
                                    $basePath .
                                    'notifications.php';
                            } elseif (
                                strpos($notificationUrl, 'http://') !== 0 &&
                                strpos($notificationUrl, 'https://') !== 0 &&
                                strpos($notificationUrl, '/') !== 0
                            ) {
                                $notificationUrl =
                                    $basePath .
                                    ltrim(
                                        $notificationUrl,
                                        '/'
                                    );
                            }
                            ?>

                            <a
                                href="<?= e($notificationUrl); ?>"
                                class="fieldplx-notification-item <?= $notification['status'] !== 'read' ? 'is-unread' : ''; ?>"
                                data-notification-id="<?= (int) $notification['id']; ?>"
                            >
                                <span class="fieldplx-notification-icon">
                                    <i class="bi bi-bell"></i>
                                </span>

                                <span class="fieldplx-notification-content">
                                    <span class="fieldplx-notification-title">
                                        <?= e($notification['title']); ?>
                                    </span>

                                    <?php if ($notification['message'] !== ''): ?>
                                        <span class="fieldplx-notification-message">
                                            <?= e($notification['message']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <span class="fieldplx-notification-time">
                                        <?= e(timeAgo(
                                            $notification['created_at']
                                        )); ?>
                                    </span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="fieldplx-dropdown-footer">
                    <a href="<?= e($basePath); ?>notifications.php">
                        View all notifications
                    </a>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <div class="dropdown">
            <button
                type="button"
                class="fieldplx-profile-button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <span class="fieldplx-avatar">
                    <?php if ($userAvatar !== ''): ?>
                        <img
                            src="<?= e($userAvatar); ?>"
                            alt="<?= e($userName); ?>"
                        >
                    <?php else: ?>
                        <?= e($userInitials); ?>
                    <?php endif; ?>
                </span>

                <span class="fieldplx-profile-details">
                    <span class="fieldplx-profile-name d-block">
                        <?= e($userName); ?>
                    </span>

                    <span class="fieldplx-profile-role d-block">
                        <?= e($userRole); ?>
                    </span>
                </span>

                <i
                    class="bi bi-chevron-down"
                    style="font-size:10px;color:#9ca3af;"
                ></i>
            </button>

            <div class="dropdown-menu dropdown-menu-end fieldplx-profile-menu">
                <div class="fieldplx-profile-menu-header">
                    <div class="fieldplx-profile-menu-name">
                        <?= e($userName); ?>
                    </div>

                    <div class="fieldplx-profile-menu-email">
                        <?= e($userEmail); ?>
                    </div>
                </div>

                <a
                    href="<?= e($basePath); ?>profile.php"
                    class="dropdown-item mt-1"
                >
                    <i class="bi bi-person"></i>
                    My Profile
                </a>

                <?php if ($canViewSettings): ?>
                    <a
                        href="<?= e($basePath); ?>settings.php"
                        class="dropdown-item"
                    >
                        <i class="bi bi-gear"></i>
                        Settings
                    </a>
                <?php endif; ?>

                <div class="dropdown-divider my-1"></div>

                <a
                    href="<?= e($basePath); ?>logout.php"
                    class="dropdown-item text-danger"
                >
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </div>
        </div>

    </div>
</header>

<div class="fieldplx-main-layout">

    <?php
    /*
    |--------------------------------------------------------------------------
    | Safe sidebar loader
    |--------------------------------------------------------------------------
    */

    $sidebarLoaded = false;

    try {
        require __DIR__ . '/sidebar.php';
        $sidebarLoaded = true;
    } catch (Throwable $sidebarError) {
        error_log(
            'FieldPlx sidebar error: ' .
            $sidebarError->getMessage() .
            ' in ' .
            $sidebarError->getFile() .
            ' on line ' .
            $sidebarError->getLine()
        );
    }

    if (!$sidebarLoaded):
    ?>
        <aside class="fieldplx-fallback-sidebar">
            <div class="fieldplx-fallback-brand">
                <span class="fieldplx-fallback-logo">
                    <?= e(strtoupper(substr($companyName, 0, 1))); ?>
                </span>

                <span class="fieldplx-fallback-brand-text">
                    <strong><?= e($companyName); ?></strong>
                    <small>FieldPlx</small>
                </span>
            </div>

            <nav class="fieldplx-fallback-nav">
                <a
                    href="<?= e($basePath); ?>dashboard.php"
                    class="<?= $activePage === 'dashboard' ? 'active' : ''; ?>"
                >
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>

                <a href="<?= e($basePath); ?>clients.php">
                    <i class="bi bi-people"></i>
                    <span>Clients</span>
                </a>

                <a href="<?= e($basePath); ?>requests.php">
                    <i class="bi bi-inbox"></i>
                    <span>Requests</span>
                </a>

                <a href="<?= e($basePath); ?>jobs.php">
                    <i class="bi bi-briefcase"></i>
                    <span>Jobs</span>
                </a>

                <a href="<?= e($basePath); ?>work-orders.php">
                    <i class="bi bi-clipboard-check"></i>
                    <span>Work Orders</span>
                </a>

                <a href="<?= e($basePath); ?>visits.php">
                    <i class="bi bi-geo-alt"></i>
                    <span>Visits</span>
                </a>

                <a href="<?= e($basePath); ?>scheduling.php">
                    <i class="bi bi-calendar3"></i>
                    <span>Scheduling</span>
                </a>

                <a href="<?= e($basePath); ?>quotes.php">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>Quotes</span>
                </a>

                <a href="<?= e($basePath); ?>invoices.php">
                    <i class="bi bi-receipt"></i>
                    <span>Invoices</span>
                </a>

                <a href="<?= e($basePath); ?>payments.php">
                    <i class="bi bi-cash-stack"></i>
                    <span>Payments</span>
                </a>

                <a href="<?= e($basePath); ?>workers.php">
                    <i class="bi bi-person-badge"></i>
                    <span>Workers</span>
                </a>

                <a href="<?= e($basePath); ?>reports.php">
                    <i class="bi bi-bar-chart"></i>
                    <span>Reports</span>
                </a>

                <a href="<?= e($basePath); ?>profile.php">
                    <i class="bi bi-person"></i>
                    <span>Profile</span>
                </a>
            </nav>

            <div class="fieldplx-fallback-footer">
                <a href="<?= e($basePath); ?>logout.php">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <style>
            .fieldplx-fallback-sidebar {
                width: 236px;
                min-width: 236px;
                height: calc(
                    100vh -
                    var(--fieldplx-topbar-height)
                );
                position: sticky;
                top: var(--fieldplx-topbar-height);
                z-index: 1020;
                display: flex;
                flex-direction: column;
                border-right: 1px solid var(--fieldplx-border);
                background: #ffffff;
            }

            .fieldplx-fallback-brand {
                min-height: 62px;
                padding: 11px 13px;
                display: flex;
                align-items: center;
                gap: 10px;
                border-bottom: 1px solid #f1f5f9;
            }

            .fieldplx-fallback-logo {
                width: 37px;
                height: 37px;
                flex: 0 0 37px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 10px;
                background: linear-gradient(
                    135deg,
                    #7c3aed,
                    #5b21b6
                );
                color: #ffffff;
                font-size: 14px;
                font-weight: 700;
            }

            .fieldplx-fallback-brand-text {
                min-width: 0;
            }

            .fieldplx-fallback-brand-text strong,
            .fieldplx-fallback-brand-text small {
                display: block;
            }

            .fieldplx-fallback-brand-text strong {
                max-width: 155px;
                overflow: hidden;
                white-space: nowrap;
                text-overflow: ellipsis;
                color: #111827;
                font-size: 11px;
            }

            .fieldplx-fallback-brand-text small {
                margin-top: 2px;
                color: #8b5cf6;
                font-size: 8px;
                font-weight: 700;
                letter-spacing: .4px;
                text-transform: uppercase;
            }

            .fieldplx-fallback-nav {
                flex: 1;
                overflow-y: auto;
                padding: 10px 8px;
            }

            .fieldplx-fallback-nav a,
            .fieldplx-fallback-footer a {
                min-height: 38px;
                padding: 8px 10px;
                display: flex;
                align-items: center;
                gap: 10px;
                border-radius: 9px;
                color: #4b5563;
                font-size: 10px;
                font-weight: 600;
                text-decoration: none;
            }

            .fieldplx-fallback-nav a:hover,
            .fieldplx-fallback-nav a.active {
                background: #f0ebff;
                color: #6d28d9;
            }

            .fieldplx-fallback-nav i,
            .fieldplx-fallback-footer i {
                width: 19px;
                flex: 0 0 19px;
                font-size: 14px;
                text-align: center;
            }

            .fieldplx-fallback-footer {
                padding: 10px;
                border-top: 1px solid #f1f5f9;
            }

            .fieldplx-fallback-footer a:hover {
                background: #fef2f2;
                color: #dc2626;
            }

            @media (max-width: 991.98px) {
                .fieldplx-fallback-sidebar {
                    display: none;
                }
            }
        </style>
    <?php endif; ?>

    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">

            <?php
            if (function_exists('displayFlashMessage')) {
                displayFlashMessage();
            }
            ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const searchInput = document.getElementById(
        'globalSearchInput'
    );

    if (searchInput) {
        let searchTimer = null;

        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);

            searchTimer = window.setTimeout(function () {
                document.dispatchEvent(
                    new CustomEvent(
                        'fieldplx:search',
                        {
                            detail: {
                                value:
                                    searchInput.value.trim()
                            }
                        }
                    )
                );
            }, 180);
        });
    }

    const csrfMeta = document.querySelector(
        'meta[name="csrf-token"]'
    );

    const csrfToken = csrfMeta
        ? csrfMeta.getAttribute('content')
        : '';

    const notificationEndpoint =
        <?= json_encode(
            $basePath . 'ajax/notification-read.php',
            JSON_UNESCAPED_SLASHES
        ); ?>;

    function sendNotificationRequest(formData, keepalive) {
        if (
            window.FieldPlx &&
            typeof window.FieldPlx.request === 'function'
        ) {
            return window.FieldPlx.request(
                notificationEndpoint,
                {
                    method: 'POST',
                    body: formData,
                    keepalive: !!keepalive
                }
            );
        }

        if (
            csrfToken &&
            !formData.has('csrf_token')
        ) {
            formData.append(
                'csrf_token',
                csrfToken
            );
        }

        return fetch(
            notificationEndpoint,
            {
                method: 'POST',
                headers: {
                    'X-Requested-With':
                        'XMLHttpRequest',
                    'Accept':
                        'application/json'
                },
                credentials: 'same-origin',
                body: formData,
                keepalive: !!keepalive
            }
        ).then(function (response) {
            return response.json();
        });
    }

    document
        .querySelectorAll(
            '[data-notification-id]'
        )
        .forEach(function (item) {
            item.addEventListener(
                'click',
                function () {
                    const notificationId =
                        parseInt(
                            item.getAttribute(
                                'data-notification-id'
                            ),
                            10
                        );

                    if (
                        !notificationId ||
                        !item.classList.contains(
                            'is-unread'
                        )
                    ) {
                        return;
                    }

                    const formData =
                        new FormData();

                    formData.append(
                        'notification_id',
                        String(notificationId)
                    );

                    if (csrfToken) {
                        formData.append(
                            'csrf_token',
                            csrfToken
                        );
                    }

                    sendNotificationRequest(
                        formData,
                        true
                    ).catch(function () {
                        // Navigation must continue.
                    });
                }
            );
        });

    const markAllButton =
        document.getElementById(
            'markAllNotificationsRead'
        );

    if (markAllButton) {
        markAllButton.addEventListener(
            'click',
            function () {
                if (
                    markAllButton.dataset.loading === '1'
                ) {
                    return;
                }

                markAllButton.dataset.loading = '1';
                markAllButton.disabled = true;

                const originalText =
                    markAllButton.textContent;

                markAllButton.textContent =
                    'Updating...';

                const formData =
                    new FormData();

                formData.append(
                    'mark_all',
                    '1'
                );

                if (csrfToken) {
                    formData.append(
                        'csrf_token',
                        csrfToken
                    );
                }

                sendNotificationRequest(
                    formData,
                    false
                )
                .then(function (response) {
                    if (
                        !response ||
                        response.success !== true
                    ) {
                        throw new Error(
                            response &&
                            response.message
                                ? response.message
                                : 'Unable to update notifications.'
                        );
                    }

                    document
                        .querySelectorAll(
                            '.fieldplx-notification-item.is-unread'
                        )
                        .forEach(function (item) {
                            item.classList.remove(
                                'is-unread'
                            );
                        });

                    const notificationCount =
                        document.querySelector(
                            '.fieldplx-notification-count'
                        );

                    if (notificationCount) {
                        notificationCount.remove();
                    }

                    markAllButton.remove();
                })
                .catch(function () {
                    markAllButton.dataset.loading = '0';
                    markAllButton.disabled = false;
                    markAllButton.textContent =
                        originalText;
                });
            }
        );
    }
});
</script>
