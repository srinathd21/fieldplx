<?php
/* FieldPlx Dynamic Tenant Topbar - Version 1.0.0 - 2026-08-28 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('fpNavH')) {
    function fpNavH($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$fpNavUserName = !empty($_SESSION['tenant_user_name']) ? (string)$_SESSION['tenant_user_name'] : (!empty($_SESSION['user_name']) ? (string)$_SESSION['user_name'] : 'User');
$fpNavUserEmail = !empty($_SESSION['tenant_user_email']) ? (string)$_SESSION['tenant_user_email'] : (!empty($_SESSION['user_email']) ? (string)$_SESSION['user_email'] : '');
$fpNavUserRole = !empty($_SESSION['role_name']) ? (string)$_SESSION['role_name'] : (!empty($_SESSION['tenant_user_job_title']) ? (string)$_SESSION['tenant_user_job_title'] : (!empty($_SESSION['tenant_user_is_admin']) ? 'Administrator' : 'User'));
$fpNavAvatar = !empty($_SESSION['tenant_user_avatar']) ? (string)$_SESSION['tenant_user_avatar'] : '';
$fpNavInitials = '';
$fpNavParts = preg_split('/\s+/', trim($fpNavUserName));
if (!empty($fpNavParts[0])) $fpNavInitials .= strtoupper(substr($fpNavParts[0], 0, 1));
if (count($fpNavParts) > 1 && !empty($fpNavParts[count($fpNavParts)-1])) $fpNavInitials .= strtoupper(substr($fpNavParts[count($fpNavParts)-1], 0, 1));
if ($fpNavInitials === '') $fpNavInitials = 'U';
?>
<header class="fieldplx-topbar">
    <div class="fieldplx-topbar-inner">
        <button aria-label="Toggle sidebar" class="fieldplx-menu-toggle" id="sidebarToggle" type="button">
            <i class="bi bi-list"></i>
        </button>

      
        <div class="fieldplx-page-heading">
            <h1 class="fieldplx-page-title"><?= fpNavH(isset($pageTitle) ? $pageTitle : 'Dashboard') ?></h1>
            <div class="fieldplx-page-subtitle">FieldPlx</div>
        </div>

        <div class="fieldplx-search-wrap">
            <i class="bi bi-search fieldplx-search-icon"></i>
            <input aria-label="Global search" autocomplete="off" class="form-control fieldplx-search-input"
                   id="globalSearchInput" placeholder="Search clients, jobs, invoices..." type="search">
        </div>

        <div class="fieldplx-topbar-spacer"></div>

        <div class="dropdown">
            <button aria-expanded="false" aria-label="Notifications" class="fieldplx-topbar-action"
                    data-bs-auto-close="outside" data-bs-toggle="dropdown" title="Notifications" type="button"
                    id="topbarNotificationButton">
                <i class="bi bi-bell"></i>
                <span class="fieldplx-notification-count d-none" id="topbarNotificationCount">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end fieldplx-dropdown">
                <div class="fieldplx-dropdown-header">
                    <h2 class="fieldplx-dropdown-title">Notifications</h2>
                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"
                            id="topbarMarkAllRead" style="font-size:10px;color:#6d28d9;">Mark all read</button>
                </div>
                <div id="topbarNotificationList">
                    <div class="px-3 py-4 text-center text-muted" style="font-size:12px;">Loading notifications...</div>
                </div>
                <div class="fieldplx-dropdown-footer">
                    <a href="notifications.php">View all notifications</a>
                </div>
            </div>
        </div>

        <div class="dropdown">
            <button aria-expanded="false" class="fieldplx-profile-button" data-bs-toggle="dropdown" type="button">
                <span class="fieldplx-avatar" id="topbarProfileAvatar">
                    <?php if ($fpNavAvatar !== ''): ?>
                        <img src="<?= fpNavH($fpNavAvatar) ?>" alt="<?= fpNavH($fpNavUserName) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    <?php else: ?>
                        <?= fpNavH($fpNavInitials) ?>
                    <?php endif; ?>
                </span>
                <span class="fieldplx-profile-details">
                    <span class="fieldplx-profile-name d-block" id="topbarProfileName"><?= fpNavH($fpNavUserName) ?></span>
                    <span class="fieldplx-profile-role d-block" id="topbarProfileRole"><?= fpNavH($fpNavUserRole) ?></span>
                </span>
                <i class="bi bi-chevron-down" style="font-size:10px;color:#9ca3af;"></i>
            </button>

            <div class="dropdown-menu dropdown-menu-end fieldplx-profile-menu">
                <div class="fieldplx-profile-menu-header">
                    <div class="fieldplx-profile-menu-name" id="topbarMenuProfileName"><?= fpNavH($fpNavUserName) ?></div>
                    <div class="fieldplx-profile-menu-email" id="topbarMenuProfileEmail"><?= fpNavH($fpNavUserEmail) ?></div>
                    <div class="text-muted mt-1" id="topbarMenuProfileMeta" style="font-size:10px;"></div>
                </div>
                <a class="dropdown-item mt-1" href="my-profile.php">
                    <i class="bi bi-person"></i> My Profile
                </a>
               
                <div class="dropdown-divider my-1"></div>
                <a class="dropdown-item text-danger" href="logout.php">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </div>
</header>

<script>
(function () {
    'use strict';

    var topbarCsrf = '';
    var notificationList = document.getElementById('topbarNotificationList');
    var notificationCount = document.getElementById('topbarNotificationCount');
    var markAllButton = document.getElementById('topbarMarkAllRead');

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function parseResponse(response) {
        return response.text().then(function (text) {
            var data;
            try { data = JSON.parse(text); }
            catch (e) { throw new Error('Invalid server response (HTTP ' + response.status + ').'); }
            if (!response.ok || !data.success) {
                throw new Error(data && data.message ? data.message : 'Request failed (HTTP ' + response.status + ').');
            }
            return data;
        });
    }

    function api(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        if (action !== 'load') fd.append('csrf_token', topbarCsrf);
        if (extra) Object.keys(extra).forEach(function (key) { fd.append(key, extra[key]); });
        return fetch('api/topbar.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(parseResponse);
    }

    function updateCount(count) {
        count = parseInt(count || 0, 10);
        notificationCount.textContent = count > 99 ? '99+' : String(count);
        notificationCount.classList.toggle('d-none', count <= 0);
        markAllButton.disabled = count <= 0;
    }

    function renderNotifications(items) {
        if (!items || !items.length) {
            notificationList.innerHTML = '<div class="px-3 py-4 text-center text-muted" style="font-size:12px;">No notifications</div>';
            return;
        }
        notificationList.innerHTML = items.map(function (item) {
            return '<a class="fieldplx-notification-item ' + (item.is_unread ? 'is-unread' : '') + '" href="' + esc(item.url || '#') + '" data-notification-id="' + esc(item.id) + '">' +
                '<span class="fieldplx-notification-icon"><i class="' + esc(item.icon || 'bi bi-bell') + '"></i></span>' +
                '<span class="fieldplx-notification-content">' +
                    '<span class="fieldplx-notification-title">' + esc(item.title) + '</span>' +
                    '<span class="fieldplx-notification-message">' + esc(item.message) + '</span>' +
                    '<span class="fieldplx-notification-time">' + esc(item.time) + '</span>' +
                '</span>' +
            '</a>';
        }).join('');
    }

    function renderProfile(profile) {
        if (!profile) return;
        document.getElementById('topbarProfileName').textContent = profile.name || 'User';
        document.getElementById('topbarProfileRole').textContent = profile.role || 'User';
        document.getElementById('topbarMenuProfileName').textContent = profile.name || 'User';
        document.getElementById('topbarMenuProfileEmail').textContent = profile.email || '';

        var meta = [];
        if (profile.employee_code) meta.push(profile.employee_code);
        if (profile.department_name) meta.push(profile.department_name);
        if (profile.branch_name) meta.push(profile.branch_name);
        document.getElementById('topbarMenuProfileMeta').textContent = meta.join(' · ');

        var avatar = document.getElementById('topbarProfileAvatar');
        if (profile.avatar_path) {
            avatar.innerHTML = '<img src="' + esc(profile.avatar_path) + '" alt="' + esc(profile.name) + '" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
        } else {
            avatar.textContent = profile.initials || 'U';
        }
    }

    function loadTopbar() {
        api('load').then(function (data) {
            topbarCsrf = data.csrf_token || '';
            updateCount(data.unread_count || 0);
            renderNotifications(data.notifications || []);
            renderProfile(data.profile || null);
        }).catch(function (error) {
            notificationList.innerHTML = '<div class="px-3 py-4 text-center text-danger" style="font-size:12px;">' + esc(error.message) + '</div>';
        });
    }

    notificationList.addEventListener('click', function (event) {
        var item = event.target.closest('[data-notification-id]');
        if (!item) return;
        var id = item.getAttribute('data-notification-id');
        if (!id || !item.classList.contains('is-unread')) return;
        api('mark_read', { notification_id: id }).then(function () {
            item.classList.remove('is-unread');
            var current = parseInt(notificationCount.textContent || '0', 10);
            updateCount(Math.max(0, current - 1));
        }).catch(function () {});
    });

    markAllButton.addEventListener('click', function () {
        if (markAllButton.disabled) return;
        var oldText = markAllButton.textContent;
        markAllButton.disabled = true;
        markAllButton.textContent = 'Updating...';
        api('mark_all_read').then(function () {
            Array.prototype.forEach.call(notificationList.querySelectorAll('.is-unread'), function (el) {
                el.classList.remove('is-unread');
            });
            updateCount(0);
        }).catch(function (error) {
            if (window.showToast) window.showToast(error.message, 'error');
        }).then(function () {
            markAllButton.textContent = oldText;
        });
    });

    loadTopbar();
    window.setInterval(loadTopbar, 60000);
})();
</script>
