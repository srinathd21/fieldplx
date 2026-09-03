<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Roles';
$activePage = 'roles';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['roles_csrf_token'])) {
    $_SESSION['roles_csrf_token'] = bin2hex(random_bytes(32));
}

$rolesCsrfToken = (string)$_SESSION['roles_csrf_token'];

/*
 * Role summary cards are loaded directly from the tenant database.
 * The list API remains responsible only for the dynamic role table/actions.
 */
$rolesTenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0;
$rolesSummary = array(
    'total' => 0,
    'active' => 0,
    'admin' => 0,
    'assigned_users' => 0
);

if ($rolesTenantId > 0 && isset($pdo) && $pdo instanceof PDO) {
    try {
        $rolesSummaryStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN is_admin = 1 THEN 1 ELSE 0 END) AS admin
            FROM roles
            WHERE tenant_id = :tenant_id
        ");
        $rolesSummaryStmt->execute(array(':tenant_id' => $rolesTenantId));
        $rolesSummaryRow = $rolesSummaryStmt->fetch(PDO::FETCH_ASSOC);

        if ($rolesSummaryRow) {
            $rolesSummary['total'] = (int)($rolesSummaryRow['total'] ?? 0);
            $rolesSummary['active'] = (int)($rolesSummaryRow['active'] ?? 0);
            $rolesSummary['admin'] = (int)($rolesSummaryRow['admin'] ?? 0);
        }

        $rolesAssignedStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM users
            WHERE tenant_id = :tenant_id
              AND role_id IS NOT NULL
              AND deleted_at IS NULL
        ");
        $rolesAssignedStmt->execute(array(':tenant_id' => $rolesTenantId));
        $rolesSummary['assigned_users'] = (int)$rolesAssignedStmt->fetchColumn();
    } catch (Throwable $rolesSummaryError) {
        error_log('FieldPlx Roles direct summary: ' . $rolesSummaryError->getMessage());
    }
}

function roles_h($value)
{
    return htmlspecialchars(
        (string)($value === null ? '' : $value),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>Roles - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
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
  width: 340px;
  max-width: calc(100vw - 24px);
  padding: 0;
  margin-top: 10px !important;
  overflow: hidden;
  border: 1px solid var(--fieldplx-border);
  border-radius: 14px;
  background: #ffffff;
  box-shadow: 0 14px 34px rgba(31, 41, 55, 0.12);
}

.fieldplx-dropdown-header {
  min-height: 48px;
  padding: 11px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--fieldplx-border);
  background: #ffffff;
}

.fieldplx-dropdown-title {
  margin: 0;
  color: #111827;
  font-size: 14px;
  line-height: 1.2;
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
  min-height: 155px;
  padding: 28px 18px 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  color: #718096;
  background: #ffffff;
  font-size: 13px;
  line-height: 1.45;
}

.fieldplx-empty-notifications i {
  display: block;
  margin-bottom: 10px;
  color: #b9a8ff;
  font-size: 30px;
  line-height: 1;
}

.fieldplx-dropdown-footer {
  min-height: 44px;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-top: 1px solid var(--fieldplx-border);
  text-align: center;
  background: #ffffff;
}

.fieldplx-dropdown-footer a {
  color: var(--fieldplx-primary);
  font-size: 11px;
  font-weight: 700;
  text-decoration: none;
}

.fieldplx-dropdown-footer a:hover {
  text-decoration: underline;
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

:root {
  --fieldplx-sidebar-width: 246px;
  --fieldplx-sidebar-collapsed-width: 72px;
}

.fieldplx-sidebar {
  width: var(--fieldplx-sidebar-width);
  min-width: var(--fieldplx-sidebar-width);
  height: calc(100vh - var(--fieldplx-topbar-height));
  position: sticky;
  top: var(--fieldplx-topbar-height);
  z-index: 1020;
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border-right: 1px solid var(--fieldplx-border);
  transition:
    width 0.22s ease,
    min-width 0.22s ease,
    transform 0.22s ease;
}

.fieldplx-sidebar-header {
  min-height: 64px;
  padding: 10px 13px;
  display: flex;
  align-items: center;
  border-bottom: 1px solid #f0f1f3;
}

.fieldplx-sidebar-brand {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  color: #111827;
  text-decoration: none;
}

.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
  width: 38px;
  height: 38px;
  flex: 0 0 38px;
  border-radius: 10px;
}

.fieldplx-sidebar-logo {
  object-fit: contain;
  background: #f7f4ff;
}

.fieldplx-sidebar-logo-placeholder {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
  color: #ffffff;
  font-size: 16px;
  font-weight: 700;
}

.fieldplx-sidebar-brand-text {
  min-width: 0;
  display: block;
}

.fieldplx-sidebar-company-name {
  max-width: 160px;
  display: block;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: 12px;
  font-weight: 700;
}

.fieldplx-sidebar-product-name {
  margin-top: 1px;
  display: block;
  color: #8b5cf6;
  font-size: 9px;
  font-weight: 600;
  letter-spacing: 0.4px;
  text-transform: uppercase;
}

.fieldplx-sidebar-close {
  width: 32px;
  height: 32px;
  margin-left: auto;
  padding: 0;
  display: none;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #6b7280;
  font-size: 16px;
}

.fieldplx-sidebar-body {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 12px 9px;
  scrollbar-width: thin;
  scrollbar-color: #d8d4e5 transparent;
}

.fieldplx-sidebar-section-label {
  margin: 4px 10px 7px;
  color: #9ca3af;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.65px;
  text-transform: uppercase;
}

.fieldplx-sidebar-nav {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.fieldplx-sidebar-link {
  width: 100%;
  min-height: 39px;
  padding: 8px 10px;
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  border-radius: 9px;
  background: transparent;
  color: #4b5563;
  text-align: left;
  text-decoration: none;
  font-family: inherit;
  font-size: 11px;
  font-weight: 500;
  transition:
    color 0.16s ease,
    background 0.16s ease;
}

.fieldplx-sidebar-link:hover {
  background: #f8f6ff;
  color: #6d28d9;
}

.fieldplx-sidebar-link.active {
  background: #f0ebff;
  color: #6d28d9;
  font-weight: 700;
}

.fieldplx-sidebar-link-icon {
  width: 20px;
  height: 20px;
  flex: 0 0 20px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
}

.fieldplx-sidebar-link-text {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.fieldplx-sidebar-arrow {
  margin-left: auto;
  color: #9ca3af;
  font-size: 10px;
  transition: transform 0.2s ease;
}

.fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow {
  transform: rotate(180deg);
}

.fieldplx-sidebar-submenu {
  max-height: 0;
  overflow: hidden;
  padding-left: 39px;
  transition: max-height 0.25s ease;
}

.fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-submenu {
  max-height: 520px;
  padding-top: 3px;
  padding-bottom: 3px;
}

.fieldplx-sidebar-sublink {
  min-height: 31px;
  padding: 7px 9px;
  position: relative;
  display: flex;
  align-items: center;
  border-radius: 7px;
  color: #6b7280;
  text-decoration: none;
  font-size: 10px;
  font-weight: 500;
}

.fieldplx-sidebar-sublink::before {
  width: 5px;
  height: 5px;
  margin-right: 9px;
  flex: 0 0 5px;
  content: "";
  border-radius: 50%;
  background: #d1d5db;
}

.fieldplx-sidebar-sublink:hover {
  background: #faf8ff;
  color: #6d28d9;
}

.fieldplx-sidebar-sublink.active {
  background: #f7f3ff;
  color: #6d28d9;
  font-weight: 700;
}

.fieldplx-sidebar-sublink.active::before {
  background: #7c3aed;
}

.fieldplx-sidebar-empty {
  margin: 8px 10px 14px;
  padding: 14px 12px;
  display: grid;
  justify-items: center;
  gap: 7px;
  border: 1px dashed #ddd6fe;
  border-radius: 10px;
  background: #faf8ff;
  color: #7c3aed;
  font-size: 9px;
  line-height: 1.5;
  text-align: center;
}

.fieldplx-sidebar-empty i {
  font-size: 17px;
}

.fieldplx-sidebar-footer {
  padding: 10px;
  border-top: 1px solid #f0f1f3;
}

.fieldplx-sidebar-user {
  padding: 8px;
  display: flex;
  align-items: center;
  gap: 9px;
  border-radius: 10px;
  background: #fafafa;
}

.fieldplx-sidebar-user-avatar {
  width: 31px;
  height: 31px;
  flex: 0 0 31px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
}

.fieldplx-sidebar-user-details {
  min-width: 0;
  flex: 1;
}

.fieldplx-sidebar-user-name,
.fieldplx-sidebar-user-role {
  display: block;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.fieldplx-sidebar-user-name {
  color: #111827;
  font-size: 10px;
  font-weight: 700;
}

.fieldplx-sidebar-user-role {
  margin-top: 1px;
  color: #9ca3af;
  font-size: 8px;
}

.fieldplx-sidebar-logout {
  width: 29px;
  height: 29px;
  flex: 0 0 29px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  color: #9ca3af;
  text-decoration: none;
  font-size: 14px;
}

.fieldplx-sidebar-logout:hover {
  background: #fee2e2;
  color: #dc2626;
}

.fieldplx-sidebar-overlay {
  display: none;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
  width: var(--fieldplx-sidebar-collapsed-width);
  min-width: var(--fieldplx-sidebar-collapsed-width);
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
  display: none;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
  justify-content: center;
  padding-left: 8px;
  padding-right: 8px;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link {
  justify-content: center;
  padding-left: 8px;
  padding-right: 8px;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
  justify-content: center;
  padding-left: 5px;
  padding-right: 5px;
}

@media (max-width: 991.98px) {
  .fieldplx-sidebar {
    width: 260px;
    min-width: 260px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    z-index: 1050;
    transform: translateX(-100%);
    box-shadow: none;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar {
    transform: translateX(0);
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: 260px;
    min-width: 260px;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
    display: block;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
    display: block;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
    justify-content: initial;
  }

  .fieldplx-sidebar-close {
    display: inline-flex;
  }

  .fieldplx-sidebar-overlay {
    position: fixed;
    inset: 0;
    z-index: 1040;
    display: block;
    visibility: hidden;
    background: rgba(17, 24, 39, 0.42);
    opacity: 0;
    transition:
      opacity 0.2s ease,
      visibility 0.2s ease;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
    visibility: visible;
    opacity: 1;
  }
}

.fieldplx-fallback-sidebar {
  width: 236px;
  min-width: 236px;
  height: calc(100vh - var(--fieldplx-topbar-height));
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
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
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
  letter-spacing: 0.4px;
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

:root {
  --fieldplx-primary: #74b824;
  --fieldplx-primary-dark: #5d971b;
  --fieldplx-text: #0b1933;
  --fieldplx-muted: #6f7b90;
  --fieldplx-border: #e5eaf1;
  --fieldplx-surface: #ffffff;
  --fieldplx-background: #f6f8fb;
  --fieldplx-topbar-height: 70px;
  --fieldplx-sidebar-width: 250px;
  --fieldplx-sidebar-collapsed-width: 78px;
  --fd-navy: #001131;
  --fd-navy-light: #071f49;
  --fd-blue: #123d70;
  --fd-green: #74b824;
  --fd-green-dark: #5d971b;
  --fd-green-soft: #f0f8e5;
  --fd-orange: #96c945;
  --fd-red: #e45b66;
  --fd-bg: #f6f8fb;
  --fd-text: #0b1933;
  --fd-muted: #6f7b90;
  --fd-border: #e5eaf1;
}

body {
  background: var(--fd-bg) !important;
  color: var(--fd-text);
  font-family: Arial, Helvetica, sans-serif !important;
  font-size: 14px;
}

.fieldplx-topbar {
  min-height: 70px !important;
  margin-left: var(--fieldplx-sidebar-width);
  width: calc(100% - var(--fieldplx-sidebar-width));
  background: #fff !important;
  border-bottom: 1px solid var(--fd-border) !important;
  box-shadow: 0 3px 14px rgba(0, 17, 49, 0.035);
  backdrop-filter: none !important;
  transition:
    margin-left 0.25s ease,
    width 0.25s ease;
}

body.fieldplx-sidebar-collapsed .fieldplx-topbar {
  margin-left: var(--fieldplx-sidebar-collapsed-width);
  width: calc(100% - var(--fieldplx-sidebar-collapsed-width));
}

.fieldplx-topbar-inner {
  min-height: 70px !important;
  padding: 0 27px !important;
  gap: 13px !important;
}

.fieldplx-page-heading {
  display: none !important;
}

.fieldplx-menu-toggle,
.fieldplx-topbar-action {
  width: 41px !important;
  height: 41px !important;
  border: 0 !important;
  border-radius: 9px !important;
  color: var(--fd-navy) !important;
  background: transparent !important;
}

.fieldplx-menu-toggle:hover,
.fieldplx-topbar-action:hover {
  color: var(--fd-navy) !important;
  background: var(--fd-green-soft) !important;
}

.fieldplx-search-wrap {
  width: 280px !important;
  margin-left: auto;
}

.fieldplx-search-input {
  height: 41px !important;
  padding-left: 38px !important;
  border: 0 !important;
  border-radius: 8px !important;
  background: #f5f8fb !important;
  color: var(--fd-text) !important;
  font-size: 12px !important;
}

.fieldplx-search-input:focus {
  background: #f5f8fb !important;
  box-shadow: 0 0 0 3px rgba(116, 184, 36, 0.14) !important;
}

.fieldplx-profile-button {
  padding: 2px !important;
  border: 0 !important;
  border-radius: 9px !important;
  background: transparent !important;
}

.fieldplx-profile-button:hover {
  background: var(--fd-green-soft) !important;
}

.fieldplx-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  border-radius: 50% !important;
  border: 0 !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg, #fff, #e8f3d9) !important;
  font-size: 12px !important;
  font-weight: 800 !important;
}

.fieldplx-profile-name {
  font-size: 12px !important;
}

.fieldplx-profile-role {
  color: var(--fd-muted) !important;
  font-size: 10px !important;
}

.fieldplx-notification-count {
  background: var(--fd-red) !important;
}

.fieldplx-dropdown,
.fieldplx-profile-menu {
  border-color: var(--fd-border) !important;
  box-shadow: 0 18px 45px rgba(29, 38, 74, 0.14) !important;
}

.fieldplx-dropdown-footer a,
.fieldplx-profile-menu .dropdown-item:hover {
  color: var(--fd-green-dark) !important;
}

.fieldplx-sidebar {
  width: var(--fieldplx-sidebar-width) !important;
  min-width: var(--fieldplx-sidebar-width) !important;
  height: 100vh !important;
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  z-index: 1045 !important;
  color: #fff !important;
  background: linear-gradient(
    180deg,
    var(--fd-navy-light),
    var(--fd-navy)
  ) !important;

  border-right: 0 !important;
  transition:
    width 0.25s ease,
    min-width 0.25s ease,
    transform 0.25s ease !important;
}

body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
  width: var(--fieldplx-sidebar-collapsed-width) !important;
  min-width: var(--fieldplx-sidebar-collapsed-width) !important;
}

.fieldplx-sidebar-header {
  min-height: 68px !important;
  padding: 9px 14px 10px !important;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-brand {
  color: #fff !important;
}

.fieldplx-sidebar-logo,
.fieldplx-sidebar-logo-placeholder {
  width: 40px !important;
  height: 40px !important;
  flex: 0 0 40px !important;
  border-radius: 10px !important;
}

.fieldplx-sidebar-logo-placeholder {
  color: #fff !important;
  background: linear-gradient(135deg, #8fd236, #68aa1d) !important;
  font-size: 18px !important;
}

.fieldplx-sidebar-company-name {
  max-width: 155px !important;
  color: #fff !important;
  font-size: 16px !important;
  font-weight: 700 !important;
}

.fieldplx-sidebar-product-name {
  color: #9fda55 !important;
  font-size: 9px !important;
}

.fieldplx-sidebar-body {
  padding: 12px 14px !important;
  scrollbar-width: none !important;
}

.fieldplx-sidebar-body::-webkit-scrollbar {
  display: none;
}

.fieldplx-sidebar-section-label {
  margin: 7px 12px 7px !important;
  color: rgba(255, 255, 255, 0.5) !important;
  font-size: 9px !important;
}

.fieldplx-sidebar-nav {
  gap: 3px !important;
}

.fieldplx-sidebar-link {
  min-height: 46px !important;
  margin-bottom: 3px !important;
  padding: 0 14px !important;
  gap: 15px !important;
  border-radius: 9px !important;
  color: rgba(255, 255, 255, 0.94) !important;
  font-size: 14px !important;
  font-weight: 600 !important;
}

.fieldplx-sidebar-link:hover {
  color: #fff !important;
  background: rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-link.active,
.fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link {
  color: #fff !important;
  background: linear-gradient(90deg, #7fc92d, #68aa1d) !important;
  box-shadow: 0 6px 18px rgba(0, 17, 49, 0.28) !important;
}

.fieldplx-sidebar-link-icon {
  width: 21px !important;
  height: 21px !important;
  flex: 0 0 21px !important;
  font-size: 19px !important;
}

.fieldplx-sidebar-arrow {
  color: rgba(255, 255, 255, 0.65) !important;
}

.fieldplx-sidebar-submenu {
  padding-left: 36px !important;
}

.fieldplx-sidebar-sublink {
  min-height: 34px !important;
  color: rgba(255, 255, 255, 0.72) !important;
  font-size: 11px !important;
}

.fieldplx-sidebar-sublink::before {
  background: rgba(255, 255, 255, 0.35) !important;
}

.fieldplx-sidebar-sublink:hover,
.fieldplx-sidebar-sublink.active {
  color: #fff !important;
  background: rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-sublink.active::before {
  background: #9fda55 !important;
}

.fieldplx-sidebar-footer {
  padding: 10px 14px 14px !important;
  border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-user {
  min-height: 62px;
  background: rgba(255, 255, 255, 0.08) !important;
}

.fieldplx-sidebar-user-name {
  color: #fff !important;
  font-size: 12px !important;
}

.fieldplx-sidebar-user-role {
  color: rgba(255, 255, 255, 0.6) !important;
  font-size: 9px !important;
}

.fieldplx-sidebar-user-avatar {
  width: 38px !important;
  height: 38px !important;
  flex: 0 0 38px !important;
  border-radius: 50% !important;
  color: var(--fd-navy) !important;
  background: linear-gradient(135deg, #fff, #e8f3d9) !important;
}

.fieldplx-sidebar-logout {
  color: rgba(255, 255, 255, 0.7) !important;
}

.fieldplx-sidebar-logout:hover {
  color: #fff !important;
  background: rgba(228, 91, 102, 0.3) !important;
}

.fieldplx-main-layout {
  display: block !important;
  min-height: calc(100vh - 70px) !important;
}

.fieldplx-main-content {
  margin-left: var(--fieldplx-sidebar-width);
  min-width: 0;
  transition: margin-left 0.25s ease;
}

body.fieldplx-sidebar-collapsed .fieldplx-main-content {
  margin-left: var(--fieldplx-sidebar-collapsed-width);
}

.fieldplx-content-wrapper {
  padding: 0 !important;
}

.fieldplx-footer {
  display: block !important;
}

.fd-dashboard {
  width: 100%;
  max-width: 1600px;
  margin: auto;
  padding: 25px 27px 35px;
}

.fd-dashboard .row > * {
  min-width: 0;
}

.fd-welcome {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 23px;
}

.fd-welcome h1 {
  margin: 0 0 8px;
  color: var(--fd-text);
  font-size: 21px;
  font-weight: 700;
}

.fd-welcome p {
  margin: 0;
  color: var(--fd-muted);
  font-size: 12px;
}

.fd-date-actions {
  display: flex;
  gap: 9px;
}

.fd-date-button,
.fd-filter-button {
  height: 46px;
  border: 1px solid var(--fd-border);
  border-radius: 9px;
  color: var(--fd-navy);
  background: #fff;
  box-shadow: 0 5px 15px rgba(31, 43, 88, 0.05);
  text-decoration: none;
}

.fd-date-button {
  min-width: 213px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 11px;
  padding: 0 14px;
  font-size: 11px;
  font-weight: 700;
}

.fd-filter-button {
  width: 46px;
  display: grid;
  place-items: center;
}

.fd-date-button:hover,
.fd-filter-button:hover {
  border-color: #cfe3ae;
  color: var(--fd-green-dark);
  background: #f9fcf4;
}

.fd-card {
  height: 100%;
  border: 1px solid var(--fd-border);
  border-radius: 9px;
  background: #fff;
  box-shadow: 0 4px 14px rgba(31, 43, 88, 0.05);
}

/* Summary cards - clean reference style */
.fd-stat-card {
  position: relative;
  min-height: 112px;
  padding: 18px 20px;
  overflow: hidden;
  border: 1px solid #dfe6ef;
  border-radius: 12px;
  background: #ffffff;
  box-shadow: 0 3px 12px rgba(24, 45, 76, 0.035);
}

.fd-stat-more {
  position: absolute;
  top: 14px;
  right: 15px;
  color: #8b9bb0;
  font-size: 18px;
  line-height: 1;
}

.fd-stat-row {
  display: flex;
  align-items: center;
  gap: 18px;
  min-height: 72px;
}

.fd-stat-row > div {
  min-width: 0;
}

.fd-stat-icon {
  width: 58px;
  height: 58px;
  flex: 0 0 58px;
  display: grid;
  place-items: center;
  border-radius: 16px;
  color: #ffffff;
  background: #123f73 !important;
  font-size: 26px;
}

.fd-stat-icon i {
  line-height: 1;
}

.fd-stat-icon.blue,
.fd-stat-icon.green,
.fd-stat-icon.lime,
.fd-stat-icon.orange {
  background: #123f73 !important;
}

.fd-stat-label {
  display: block;
  margin-bottom: 8px;
  color: #506784;
  font-size: 13px;
  line-height: 1.2;
  font-weight: 400;
}

.fd-stat-value {
  display: block;
  color: #020b16;
  font-size: 31px;
  line-height: 1;
  font-weight: 700;
  letter-spacing: -0.5px;
}

.fd-stat-card .fd-growth,
.fd-stat-card .fd-sparkline {
  display: none !important;
}

.fd-growth {
  display: block;
  margin-top: 14px;
  color: #8a95a8;
  font-size: 9px;
}

.fd-growth strong {
  font-size: 10px;
}

.fd-growth.up strong {
  color: var(--fd-green-dark);
}

.fd-growth.down strong {
  color: var(--fd-red);
}

.fd-growth.flat strong {
  color: #7d899d;
}

.fd-sparkline {
  position: absolute;
  right: 18px;
  bottom: 7px;
  left: 18px;
  height: 45px;
}

.fd-panel {
  padding: 18px;
}

.fd-panel-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 13px;
}

.fd-panel-title h2 {
  margin: 0;
  color: var(--fd-text);
  font-size: 14px;
  font-weight: 700;
}

.fd-chart-card {
  min-height: 313px;
}

.fd-chart-area {
  position: relative;
  height: 245px;
}

.fd-chart-area canvas {
  width: 100% !important;
  height: 100% !important;
}

.fd-chart-legend {
  color: var(--fd-muted);
  font-size: 10px;
  white-space: nowrap;
}

.fd-status-wrapper {
  min-height: 245px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 22px;
}

.fd-donut {
  position: relative;
  width: 165px;
  height: 165px;
  flex: 0 0 165px;
  display: grid;
  place-items: center;
  border-radius: 50%;
}

.fd-donut::before {
  position: absolute;
  width: 104px;
  height: 104px;
  border-radius: 50%;
  background: #fff;
  content: "";
}

.fd-donut-center {
  position: relative;
  z-index: 1;
  text-align: center;
}

.fd-donut-center strong {
  display: block;
  color: var(--fd-text);
  font-size: 21px;
}

.fd-donut-center small {
  color: var(--fd-muted);
  font-size: 10px;
}

.fd-status-legend {
  display: flex;
  flex-direction: column;
  gap: 11px;
}

.fd-legend-row {
  display: flex;
  gap: 8px;
  color: var(--fd-muted);
  font-size: 10px;
  line-height: 1.45;
}

.fd-legend-dot {
  width: 8px;
  height: 8px;
  flex: 0 0 8px;
  margin-top: 3px;
  border-radius: 50%;
}

.fd-legend-row strong {
  color: var(--fd-text);
}

.fd-tasks-count {
  padding: 4px 8px;
  border-radius: 999px;
  color: var(--fd-green-dark);
  background: var(--fd-green-soft);
  font-size: 9px;
  font-weight: 700;
}

.fd-task-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.fd-task-item {
  min-height: 41px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid var(--fd-border);
  border-radius: 8px;
  color: inherit;
  background: #fbfcfa;
  text-decoration: none;
  transition:
    border-color 0.2s ease,
    background 0.2s ease;
}

.fd-task-item:hover {
  border-color: #cfe3ae;
  color: inherit;
  background: #f7fbed;
}

.fd-task-check {
  width: 17px;
  height: 17px;
  flex: 0 0 17px;
  display: grid;
  place-items: center;
  border: 1px solid #cdd3df;
  border-radius: 4px;
  color: #fff;
  font-size: 10px;
}

.fd-task-item.complete {
  background: #f5faee;
}

.fd-task-item.complete .fd-task-check {
  border-color: var(--fd-green);
  background: var(--fd-green);
}

.fd-task-content {
  min-width: 0;
  flex: 1;
}

.fd-task-content strong,
.fd-task-content small {
  display: block;
}

.fd-task-content strong {
  overflow: hidden;
  color: var(--fd-navy);
  font-size: 10px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fd-task-content small {
  margin-top: 2px;
  color: var(--fd-muted);
  font-size: 9px;
}

.fd-task-item.complete .fd-task-content strong {
  color: #8792a4;
  text-decoration: line-through;
}

.fd-task-time {
  flex: 0 0 auto;
  padding: 4px 7px;
  border-radius: 999px;
  color: #5c6b81;
  background: #eef2f6;
  font-size: 8.5px;
  font-weight: 700;
  white-space: nowrap;
}

.fd-task-footer {
  display: flex;
  justify-content: flex-end;
  padding-top: 7px;
}

.fd-link {
  color: var(--fd-green-dark);
  font-size: 10px;
  font-weight: 600;
  text-decoration: none;
}

.fd-link:hover {
  color: var(--fd-green);
}

.fd-recent-jobs-card {
  min-height: 360px;
  overflow: hidden;
}

.fd-view-button {
  padding: 6px 11px;
  border: 1px solid var(--fd-border);
  border-radius: 5px;
  color: #53627a;
  background: #fff;
  font-size: 10px;
  text-decoration: none;
}

.fd-view-button:hover {
  border-color: #cfe3ae;
  color: var(--fd-green-dark);
  background: #f9fcf4;
}

.fd-jobs-table {
  min-width: 820px;
  margin: 4px 0 0;
  white-space: nowrap;
}

.fd-jobs-table th {
  padding: 11px 6px;
  border-bottom-color: var(--fd-border);
  color: #65738a;
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
}

.fd-jobs-table td {
  padding: 12px 6px;
  border-bottom-color: #f1f3f7;
  color: #33445f;
  font-size: 9.5px;
  vertical-align: middle;
}

.fd-job-name {
  color: var(--fd-text);
  font-weight: 700;
}

.fd-status {
  display: inline-flex;
  padding: 5px 7px;
  border-radius: 5px;
  font-size: 9px;
  font-weight: 600;
}

.fd-status.progress {
  color: #123d70;
  background: #edf2f7;
}

.fd-status.completed {
  color: #5d971b;
  background: #f0f8e5;
}

.fd-status.pending {
  color: #678a23;
  background: #f5f9ea;
}

.fd-status.cancelled {
  color: #b9444d;
  background: #fff0f1;
}

.fd-action-link {
  width: 28px;
  height: 28px;
  display: grid;
  place-items: center;
  border-radius: 6px;
  color: #66748b;
  text-decoration: none;
}

.fd-action-link:hover {
  color: var(--fd-green-dark);
  background: var(--fd-green-soft);
}

.fd-schedule-event {
  min-height: 45px;
  display: grid;
  grid-template-columns: 10px 58px 1fr;
  align-items: start;
  color: inherit;
  text-decoration: none;
}

.fd-schedule-event:hover .fd-schedule-info strong {
  color: var(--fd-green-dark);
}

.fd-schedule-dot {
  width: 8px;
  height: 8px;
  margin-top: 3px;
  border-radius: 50%;
  background: var(--fd-green);
}

.fd-schedule-time {
  padding-top: 1px;
  color: var(--fd-muted);
  font-size: 9px;
}

.fd-schedule-info strong,
.fd-schedule-info small {
  display: block;
}

.fd-schedule-info strong {
  color: var(--fd-text);
  font-size: 10px;
}

.fd-schedule-info small {
  margin-top: 2px;
  color: var(--fd-muted);
  font-size: 9px;
}

.fd-activity-item {
  display: flex;
  gap: 10px;
  padding: 8px 0;
}

.fd-activity-icon {
  width: 30px;
  height: 30px;
  flex: 0 0 30px;
  display: grid;
  place-items: center;
  border-radius: 9px;
}

.fd-activity-icon.green,
.fd-activity-icon.lime {
  color: var(--fd-green-dark);
  background: #f0f8e5;
}

.fd-activity-icon.orange {
  color: #789d2c;
  background: #f4f9ea;
}

.fd-activity-icon.blue {
  color: #123d70;
  background: #edf2f7;
}

.fd-activity-content strong,
.fd-activity-content small {
  display: block;
}

.fd-activity-content strong {
  color: var(--fd-text);
  font-size: 10px;
}

.fd-activity-content small {
  margin-top: 2px;
  color: var(--fd-muted);
  font-size: 9px;
  line-height: 1.4;
}

.fd-bottom-card {
  position: relative;
  min-height: 132px;
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 22px;
  overflow: hidden;
}

.fd-bottom-icon {
  width: 52px;
  height: 52px;
  flex: 0 0 52px;
  display: grid;
  place-items: center;
  border-radius: 14px;
  font-size: 22px;
}

.fd-bottom-content small,
.fd-bottom-content strong,
.fd-bottom-content span {
  display: block;
}

.fd-bottom-content small {
  color: var(--fd-muted);
  font-size: 10px;
  font-weight: 600;
}

.fd-bottom-content strong {
  margin-top: 5px;
  color: var(--fd-text);
  font-size: 23px;
  line-height: 1.1;
}

.fd-bottom-content span {
  margin-top: 7px;
  color: #33445f;
  font-size: 9px;
  font-weight: 700;
}

.fd-bottom-content .growth {
  color: var(--fd-green-dark);
}

.fd-empty {
  min-height: 120px;
  display: grid;
  place-items: center;
  padding: 20px;
  color: #9aa4b3;
  font-size: 10px;
  text-align: center;
}

@media (max-width: 1199.98px) {
  .fd-status-wrapper {
    gap: 14px;
  }
}

@media (max-width: 991.98px) {
  .fieldplx-topbar,
  body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: 0 !important;
    width: 100% !important;
  }

  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: 250px !important;
    min-width: 250px !important;
    transform: translateX(-100%);
    box-shadow: none !important;
    filter: none !important;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar {
    transform: translateX(0) !important;
  }

  .fieldplx-main-content,
  body.fieldplx-sidebar-collapsed .fieldplx-main-content {
    margin-left: 0 !important;
  }

  .fieldplx-sidebar-brand-text,
  .fieldplx-sidebar-section-label,
  .fieldplx-sidebar-link-text,
  .fieldplx-sidebar-arrow,
  .fieldplx-sidebar-user-details,
  .fieldplx-sidebar-logout,
  .fieldplx-sidebar-submenu {
    display: initial;
  }
}

@media (max-width: 767.98px) {
  :root {
    --fieldplx-topbar-height: 64px;
  }

  .fieldplx-topbar,
  .fieldplx-topbar-inner {
    min-height: 64px !important;
  }

  .fieldplx-topbar-inner {
    padding: 0 13px !important;
  }

  .fieldplx-search-wrap {
    display: none !important;
  }

  .fd-dashboard {
    padding: 17px 13px 28px;
  }

  .fd-welcome {
    align-items: flex-start;
  }

  .fd-welcome h1 {
    font-size: 19px;
  }

  .fd-welcome p {
    max-width: 260px;
    font-size: 11px;
    line-height: 1.5;
  }

  .fd-date-button {
    min-width: 46px;
    width: 46px;
    padding: 0;
    justify-content: center;
  }

  .fd-date-button span,
  .fd-date-button .bi-chevron-down {
    display: none;
  }

  .fd-stat-card {
    min-height: 108px;
    padding: 17px 18px;
  }

  .fd-donut {
    width: 145px;
    height: 145px;
    flex-basis: 145px;
  }

  .fd-donut::before {
    width: 91px;
    height: 91px;
  }
}

@media (max-width: 420px) {
  .fd-welcome {
    min-height: 65px;
    gap: 10px;
  }

  .fd-date-actions {
    gap: 5px;
  }

  .fd-filter-button {
    width: 42px;
  }

  .fd-status-wrapper {
    transform: scale(0.92);
    margin-inline: -14px;
  }
}

@media (max-width: 575.98px) {
  .fd-stat-card {
    min-height: 102px;
    padding: 15px 17px;
  }

  .fd-stat-row {
    gap: 15px;
    min-height: 66px;
  }

  .fd-stat-icon {
    width: 54px;
    height: 54px;
    flex-basis: 54px;
    border-radius: 15px;
    font-size: 24px;
  }

  .fd-stat-label {
    margin-bottom: 7px;
  }

  .fd-stat-value {
    font-size: 28px;
  }

  .fd-stat-row {
    gap: 18px;
    min-height: 72px;
  }

  .fd-stat-value {
    font-size: 29px;
  }
}

.fieldplx-footer {
  min-height: 52px;
  margin-left: var(--fieldplx-sidebar-width);
  border-top: 1px solid var(--fieldplx-border);
  background: #ffffff;
  transition:
    margin-left 0.22s ease,
    background-color 0.22s ease;
}

.fieldplx-footer-inner {
  min-height: 52px;
  padding: 10px 18px;
  display: flex;
  align-items: center;
  gap: 18px;
  color: #6b7280;
  font-size: 10px;
}

.fieldplx-footer-copyright {
  min-width: 0;
}

.fieldplx-footer-links {
  display: flex;
  align-items: center;
  gap: 8px;
}

.fieldplx-footer-links a {
  color: #6b7280;
  text-decoration: none;
  transition: color 0.18s ease;
}

.fieldplx-footer-links a:hover {
  color: var(--fieldplx-primary);
}

.fieldplx-footer-separator {
  color: #d1d5db;
  font-size: 8px;
}

.fieldplx-footer-product {
  margin-left: auto;
  white-space: nowrap;
  color: #9ca3af;
}

.fieldplx-footer-product strong {
  color: var(--fieldplx-primary);
  font-weight: 700;
}

body.fieldplx-sidebar-collapsed .fieldplx-footer {
  margin-left: var(--fieldplx-sidebar-collapsed-width);
}

@media (max-width: 991.98px) {
  .fieldplx-footer {
    margin-left: 0;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-footer {
    margin-left: 0;
  }
}

@media (max-width: 767.98px) {
  .fieldplx-footer-inner {
    padding: 12px;
    flex-wrap: wrap;
    justify-content: center;
    gap: 7px 14px;
    text-align: center;
  }

  .fieldplx-footer-product {
    width: 100%;
    margin-left: 0;
  }
}

/* Notification dropdown correction */
.dropdown:has(.fieldplx-topbar-action) .fieldplx-dropdown {
  right: 0 !important;
  left: auto !important;
  width: 340px !important;
  max-width: calc(100vw - 24px) !important;
  margin-top: 10px !important;
  border: 1px solid var(--fd-border) !important;
  border-radius: 14px !important;
  background: #ffffff !important;
  box-shadow: 0 14px 34px rgba(29, 38, 74, 0.12) !important;
}

#topbarNotificationList {
  max-height: 300px;
  overflow-y: auto;
  background: #ffffff;
}

.fieldplx-empty-notifications {
  min-height: 155px !important;
  padding: 28px 18px 24px !important;
}

.fieldplx-dropdown-footer {
  border-top: 1px solid var(--fd-border) !important;
}

@media (max-width: 575.98px) {
  .dropdown:has(.fieldplx-topbar-action) .fieldplx-dropdown {
    width: min(320px, calc(100vw - 20px)) !important;
  }

  .fieldplx-empty-notifications {
    min-height: 135px !important;
    padding: 22px 15px !important;
  }
}

/* ==========================================================
   FieldPlx mobile sidebar final correction
   Desktop sidebar appearance is intentionally unchanged.
   ========================================================== */
@media (max-width: 991.98px) {
  html,
  body {
    overflow-x: hidden !important;
  }

  body.fieldplx-sidebar-mobile-open {
    overflow: hidden !important;
  }

  .fieldplx-topbar,
  body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    margin-left: 0 !important;
    width: 100% !important;
  }

  .fieldplx-main-content,
  body.fieldplx-sidebar-collapsed .fieldplx-main-content {
    margin-left: 0 !important;
    width: 100% !important;
  }

  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: min(300px, calc(100vw - 52px)) !important;
    min-width: 0 !important;
    max-width: 300px !important;
    height: 100vh !important;
    height: 100dvh !important;
    position: fixed !important;
    top: 0 !important;
    bottom: 0 !important;
    left: 0 !important;
    z-index: 1060 !important;

    display: flex !important;
    flex-direction: column !important;

    overflow: hidden !important;
    visibility: hidden !important;
    transform: translate3d(-100%, 0, 0) !important;

    border-right: 0 !important;
    box-shadow: none !important;
    filter: none !important;

    transition:
      transform 0.25s ease,
      visibility 0.25s ease !important;

    will-change: transform;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
  body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed
    .fieldplx-sidebar {
    visibility: visible !important;
    transform: translate3d(0, 0, 0) !important;
  }

  .fieldplx-sidebar-header,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header {
    flex: 0 0 auto !important;
    justify-content: flex-start !important;
    padding-left: 14px !important;
    padding-right: 10px !important;
  }

  .fieldplx-sidebar-close {
    width: 34px !important;
    height: 34px !important;
    margin-left: auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;

    color: rgba(255, 255, 255, 0.88) !important;
    background: rgba(255, 255, 255, 0.08) !important;
  }

  .fieldplx-sidebar-close:hover {
    color: #ffffff !important;
    background: rgba(255, 255, 255, 0.14) !important;
  }

  .fieldplx-sidebar-body {
    min-height: 0 !important;
    flex: 1 1 auto !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
  }

  .fieldplx-sidebar-footer {
    flex: 0 0 auto !important;
  }

  /* Never allow the desktop collapsed state to hide mobile labels. */
  .fieldplx-sidebar-brand-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text {
    display: block !important;
  }

  .fieldplx-sidebar-section-label,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label {
    display: block !important;
  }

  .fieldplx-sidebar-link-text,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text {
    display: block !important;
  }

  .fieldplx-sidebar-arrow,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow {
    display: inline-flex !important;
  }

  .fieldplx-sidebar-user-details,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details {
    display: block !important;
  }

  .fieldplx-sidebar-logout,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout {
    display: inline-flex !important;
  }

  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user {
    justify-content: flex-start !important;
  }

  /* Restore proper accordion behavior on mobile.
       Do not use display:initial here: it turns the submenu into inline
       content and breaks max-height animation/spacing. */
  .fieldplx-sidebar-submenu,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu {
    display: block !important;
    max-height: 0 !important;
    overflow: hidden !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;

    transition:
      max-height 0.25s ease,
      padding-top 0.25s ease,
      padding-bottom 0.25s ease !important;
  }

  .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu,
  body.fieldplx-sidebar-collapsed
    .fieldplx-sidebar-menu.menu-open
    > .fieldplx-sidebar-submenu {
    display: block !important;
    max-height: 680px !important;
    padding-top: 4px !important;
    padding-bottom: 5px !important;
  }

  .fieldplx-sidebar-overlay {
    position: fixed !important;
    inset: 0 !important;
    z-index: 1055 !important;

    display: block !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;

    background: rgba(0, 17, 49, 0.48) !important;
    transition:
      opacity 0.25s ease,
      visibility 0.25s ease !important;
  }

  body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay {
    visibility: visible !important;
    opacity: 1 !important;
    pointer-events: auto !important;
  }
}

@media (max-width: 575.98px) {
  .fieldplx-sidebar,
  body.fieldplx-sidebar-collapsed .fieldplx-sidebar {
    width: min(288px, calc(100vw - 44px)) !important;
  }

  .fieldplx-sidebar-body {
    padding-left: 10px !important;
    padding-right: 10px !important;
  }

  .fieldplx-sidebar-link {
    min-height: 43px !important;
    padding-left: 12px !important;
    padding-right: 12px !important;
    gap: 12px !important;
    font-size: 13px !important;
  }

  .fieldplx-sidebar-submenu {
    padding-left: 31px !important;
  }

  .fieldplx-sidebar-sublink {
    min-height: 33px !important;
    font-size: 11px !important;
  }
}

    
/* ==========================================================
   Roles page - uses canonical tenant template
   ========================================================== */
.fd-roles-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}

.fd-roles-title{
  margin:0 0 7px;
  color:var(--fd-text);
  font-size:21px;
  line-height:1.2;
  font-weight:700;
}

.fd-roles-subtitle{
  margin:0;
  max-width:760px;
  color:var(--fd-muted);
  font-size:11px;
  line-height:1.55;
}

.fd-roles-actions{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.fd-role-button{
  min-height:39px;
  padding:0 13px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:7px;
  border:1px solid var(--fd-border);
  border-radius:8px;
  color:#43546c;
  background:#fff;
  box-shadow:0 4px 12px rgba(31,43,88,.04);
  font-size:10px;
  font-weight:700;
  text-decoration:none;
  cursor:pointer;
}

.fd-role-button:hover{
  border-color:#cfe3ae;
  color:var(--fd-green-dark);
  background:#f9fcf4;
}

.fd-role-button.primary{
  border-color:var(--fd-green);
  color:#fff;
  background:linear-gradient(90deg,#7fc92d,#68aa1d);
  box-shadow:0 7px 16px rgba(104,170,29,.18);
}

.fd-role-button.primary:hover{
  color:#fff;
  background:linear-gradient(90deg,#74b824,#5d971b);
}

.fd-role-button.danger{
  border-color:#ffd5d9;
  color:#b9444d;
  background:#fff;
}

.fd-role-button.danger:hover{
  color:#b9444d;
  background:#fff4f5;
}

.fd-role-button:disabled{
  opacity:.58;
  cursor:not-allowed;
}

.fd-role-loader{
  width:13px;
  height:13px;
  display:none;
  border:2px dotted currentColor;
  border-radius:50%;
  animation:fdRoleSpin .75s linear infinite;
}

.fd-role-button.loading .fd-role-loader{
  display:inline-block;
}

@keyframes fdRoleSpin{
  to{transform:rotate(360deg);}
}

.fd-roles-summary{
  margin-bottom:16px;
}

.fd-role-stat-card{
  position:relative;
  min-height:112px;
  padding:18px 20px;
  overflow:hidden;
  border:1px solid #dfe6ef;
  border-radius:12px;
  background:#fff;
  box-shadow:0 3px 12px rgba(24,45,76,.035);
}

.fd-role-stat-row{
  min-height:72px;
  display:flex;
  align-items:center;
  gap:18px;
}

.fd-role-stat-icon{
  width:58px;
  height:58px;
  flex:0 0 58px;
  display:grid;
  place-items:center;
  border-radius:16px;
  color:#fff;
  background:#123f73;
  font-size:25px;
}

.fd-role-stat-label{
  display:block;
  margin-bottom:8px;
  color:#506784;
  font-size:13px;
  line-height:1.2;
  font-weight:400;
}

.fd-role-stat-value{
  display:block;
  color:#020b16;
  font-size:31px;
  line-height:1;
  font-weight:700;
  letter-spacing:-.5px;
}

.fd-roles-card{
  overflow:hidden;
}

.fd-roles-toolbar{
  padding:13px 14px;
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}

.fd-role-search{
  width:260px;
  position:relative;
}

.fd-role-search i{
  position:absolute;
  left:12px;
  top:50%;
  transform:translateY(-50%);
  color:#8a96a7;
  font-size:13px;
}

.fd-role-search input,
.fd-role-filter{
  height:39px;
  border:1px solid #dde4ec;
  border-radius:8px;
  outline:0;
  color:#33445f;
  background:#fff;
  font-size:10px;
}

.fd-role-search input{
  width:100%;
  padding:8px 11px 8px 34px;
}

.fd-role-filter{
  min-width:135px;
  padding:8px 10px;
}

.fd-role-search input:focus,
.fd-role-filter:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}

.fd-role-toolbar-spacer{
  margin-left:auto;
}

.fd-role-table-wrap{
  width:100%;
  overflow-x:auto;
  scrollbar-width:thin;
}

.fd-role-table-wrap::-webkit-scrollbar{
  height:4px;
}

.fd-role-table-wrap::-webkit-scrollbar-thumb{
  border-radius:999px;
  background:#ccd5df;
}

.fd-role-table{
  width:100%;
  min-width:950px;
  margin:0;
  border-collapse:collapse;
  white-space:nowrap;
}

.fd-role-table th{
  padding:11px 12px;
  border-bottom:1px solid var(--fd-border);
  color:#65738a;
  background:#f8fafc;
  font-size:9px;
  font-weight:600;
  text-transform:uppercase;
}

.fd-role-table td{
  padding:12px;
  border-bottom:1px solid #f1f3f7;
  color:#33445f;
  font-size:9.5px;
  vertical-align:middle;
}

.fd-role-table tbody tr:hover{
  background:#fbfcfa;
}

.fd-role-name{
  display:flex;
  align-items:center;
  gap:10px;
}

.fd-role-name-icon{
  width:34px;
  height:34px;
  flex:0 0 34px;
  display:grid;
  place-items:center;
  border-radius:9px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
  font-size:14px;
}

.fd-role-name strong,
.fd-role-name small{
  display:block;
}

.fd-role-name strong{
  color:var(--fd-text);
  font-size:10.5px;
}

.fd-role-name small{
  margin-top:2px;
  color:#8d98a8;
  font-size:8.5px;
}

.fd-role-badge{
  display:inline-flex;
  align-items:center;
  gap:4px;
  padding:5px 7px;
  border-radius:5px;
  font-size:8.5px;
  font-weight:600;
}

.fd-role-badge.active{
  color:#5d971b;
  background:#f0f8e5;
}

.fd-role-badge.inactive{
  color:#6f7b90;
  background:#eef2f6;
}

.fd-role-badge.admin{
  color:#123d70;
  background:#edf2f7;
}

.fd-role-badge.system{
  color:#789d2c;
  background:#f4f9ea;
}

.fd-role-action-wrap{
  display:flex;
  align-items:center;
  gap:5px;
}

.fd-role-icon-button{
  width:29px;
  height:29px;
  padding:0;
  display:grid;
  place-items:center;
  border:0;
  border-radius:6px;
  color:#66748b;
  background:transparent;
  cursor:pointer;
  font-size:12px;
}

.fd-role-icon-button:hover{
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}

.fd-role-icon-button.danger:hover{
  color:#b9444d;
  background:#fff0f1;
}

.fd-role-empty{
  min-height:120px;
  padding:28px 18px !important;
  text-align:center;
  color:#9aa4b3 !important;
  font-size:10px !important;
}

.fd-role-pagination{
  min-height:49px;
  padding:10px 14px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  border-top:1px solid var(--fd-border);
  color:#768397;
  background:#fff;
  font-size:9px;
}

.fd-role-pagination-actions{
  display:flex;
  gap:5px;
}

.fd-role-modal-backdrop{
  position:fixed;
  inset:0;
  z-index:15000;
  display:none;
  align-items:center;
  justify-content:center;
  padding:18px;
  background:rgba(0,17,49,.46);
  backdrop-filter:blur(3px);
}

.fd-role-modal-backdrop.show{
  display:flex;
}

.fd-role-modal{
  width:min(760px,100%);
  max-height:calc(100vh - 36px);
  overflow:auto;
  border:1px solid #dfe5ec;
  border-radius:12px;
  background:#fff;
  box-shadow:0 24px 65px rgba(0,17,49,.24);
}

.fd-role-modal-header{
  min-height:58px;
  padding:11px 14px;
  display:flex;
  align-items:center;
  gap:10px;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}

.fd-role-modal-icon{
  width:34px;
  height:34px;
  flex:0 0 34px;
  display:grid;
  place-items:center;
  border-radius:9px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
  font-size:15px;
}

.fd-role-modal-heading{
  min-width:0;
  flex:1;
}

.fd-role-modal-heading h3{
  margin:0;
  color:var(--fd-text);
  font-size:12px;
  font-weight:700;
}

.fd-role-modal-heading p{
  margin:3px 0 0;
  color:var(--fd-muted);
  font-size:8.5px;
}

.fd-role-modal-close{
  width:30px;
  height:30px;
  display:grid;
  place-items:center;
  border:0;
  border-radius:7px;
  color:#8490a0;
  background:transparent;
  cursor:pointer;
}

.fd-role-modal-close:hover{
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}

.fd-role-modal-body{
  padding:15px;
}

.fd-role-form-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:13px;
}

.fd-role-field.full{
  grid-column:1/-1;
}

.fd-role-field label{
  margin-bottom:6px;
  display:block;
  color:#42536c;
  font-size:9px;
  font-weight:700;
}

.fd-role-field input,
.fd-role-field select,
.fd-role-field textarea{
  width:100%;
  min-height:40px;
  padding:8px 10px;
  border:1px solid #dfe5ec;
  border-radius:8px;
  outline:0;
  color:#263750;
  background:#fff;
  font-size:10px;
}

.fd-role-field textarea{
  min-height:76px;
  resize:vertical;
}

.fd-role-field input:focus,
.fd-role-field select:focus,
.fd-role-field textarea:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}

.fd-role-switch-row{
  min-height:48px;
  padding:9px 10px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  border:1px solid var(--fd-border);
  border-radius:9px;
  background:#fbfcfd;
}

.fd-role-switch-copy strong,
.fd-role-switch-copy small{
  display:block;
}

.fd-role-switch-copy strong{
  color:#34465f;
  font-size:9.5px;
}

.fd-role-switch-copy small{
  margin-top:2px;
  color:#8a96a7;
  font-size:8px;
}

.fd-role-switch{
  width:38px;
  height:21px;
  position:relative;
  flex:0 0 38px;
}

.fd-role-switch input{
  position:absolute;
  opacity:0;
  pointer-events:none;
}

.fd-role-switch span{
  position:absolute;
  inset:0;
  border-radius:999px;
  background:#d6dce4;
  cursor:pointer;
  transition:.18s ease;
}

.fd-role-switch span::before{
  width:15px;
  height:15px;
  position:absolute;
  top:3px;
  left:3px;
  border-radius:50%;
  background:#fff;
  box-shadow:0 1px 4px rgba(0,0,0,.15);
  content:"";
  transition:.18s ease;
}

.fd-role-switch input:checked + span{
  background:var(--fd-green);
}

.fd-role-switch input:checked + span::before{
  transform:translateX(17px);
}

.fd-role-permissions{
  margin-top:15px;
  overflow:hidden;
  border:1px solid var(--fd-border);
  border-radius:9px;
}

.fd-role-permissions-head{
  padding:10px 12px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  border-bottom:1px solid var(--fd-border);
  background:#f9fbfc;
}

.fd-role-permissions-head strong{
  display:block;
  color:#31425b;
  font-size:10px;
}

.fd-role-permissions-head small{
  display:block;
  margin-top:2px;
  color:#8b96a6;
  font-size:8px;
}

.fd-role-permission-list{
  max-height:290px;
  overflow:auto;
}

.fd-role-permission-module{
  border-bottom:1px solid #f0f3f6;
}

.fd-role-permission-module:last-child{
  border-bottom:0;
}

.fd-role-permission-title{
  padding:9px 11px;
  display:flex;
  align-items:center;
  gap:7px;
  color:#34465e;
  background:#fbfcfd;
  font-size:9px;
  font-weight:700;
}

.fd-role-permission-items{
  padding:8px 11px 10px;
  display:flex;
  flex-wrap:wrap;
  gap:7px;
}

.fd-role-permission-check{
  padding:6px 8px;
  display:inline-flex;
  align-items:center;
  gap:6px;
  border:1px solid #e3e8ed;
  border-radius:7px;
  color:#5c6d82;
  background:#fff;
  font-size:8.5px;
  cursor:pointer;
}

.fd-role-permission-check input{
  width:13px;
  height:13px;
  accent-color:var(--fd-green);
}

.fd-role-modal-footer{
  padding:12px 15px;
  display:flex;
  justify-content:flex-end;
  gap:8px;
  border-top:1px solid var(--fd-border);
  background:#fbfcfd;
}

.fd-role-confirm{
  width:min(430px,100%);
}

.fd-role-confirm .fd-role-modal-body{
  padding:18px 16px;
  color:#56667c;
  font-size:10px;
  line-height:1.6;
}

.fd-role-toast{
  width:min(290px,calc(100vw - 24px));
  position:fixed;
  top:82px;
  right:16px;
  z-index:25000;
  padding:8px 9px;
  display:flex;
  align-items:center;
  gap:7px;
  border-radius:7px;
  color:#fff;
  box-shadow:0 10px 26px rgba(0,17,49,.18);
  opacity:0;
  transform:translateY(-8px);
  pointer-events:none;
  transition:.18s ease;
}

.fd-role-toast.show{
  opacity:1;
  transform:translateY(0);
  pointer-events:auto;
}

.fd-role-toast.success{background:#5d971b;}
.fd-role-toast.error{background:#e45b66;}
.fd-role-toast.warning{background:#96a52f;}
.fd-role-toast.info{background:#123d70;}

.fd-role-toast-icon{
  width:19px;
  height:19px;
  flex:0 0 19px;
  display:grid;
  place-items:center;
  border-radius:50%;
  background:rgba(255,255,255,.16);
  font-size:9px;
}

.fd-role-toast-message{
  min-width:0;
  flex:1;
  font-size:8.5px;
  font-weight:600;
  line-height:1.35;
}

.fd-role-toast-close{
  width:19px;
  height:19px;
  padding:0;
  border:0;
  color:#fff;
  background:transparent;
  cursor:pointer;
  opacity:.8;
}

@media(max-width:767.98px){
  .fd-roles-header{
    align-items:stretch;
    flex-direction:column;
  }

  .fd-roles-actions{
    justify-content:flex-end;
  }

  .fd-role-form-grid{
    grid-template-columns:1fr;
  }

  .fd-role-field.full{
    grid-column:auto;
  }

  .fd-role-search{
    width:100%;
  }

  .fd-role-toolbar-spacer{
    display:none;
  }
}

@media(max-width:575.98px){
  .fd-role-stat-card{
    min-height:102px;
    padding:15px 17px;
  }

  .fd-role-stat-row{
    gap:15px;
    min-height:66px;
  }

  .fd-role-stat-icon{
    width:54px;
    height:54px;
    flex-basis:54px;
    border-radius:15px;
    font-size:24px;
  }

  .fd-role-stat-value{
    font-size:29px;
  }

  .fd-role-filter{
    flex:1;
  }

  .fd-role-modal-footer{
    flex-direction:column-reverse;
  }

  .fd-role-modal-footer .fd-role-button{
    width:100%;
  }

  .fd-role-toast{
    top:72px;
    right:12px;
    left:12px;
    width:auto;
  }
}


/* ==========================================================
   Roles v2.0 - canonical FieldPlx cards + permission matrix
   ========================================================== */
.fd-roles-summary{margin-bottom:18px;}
.fd-role-summary-card{
  width:100%;
  min-height:134px;
  padding:15px 18px;
  position:relative;
  overflow:visible;
  border:1px solid #dfe6ef;
  border-radius:12px;
  background:#fff;
  box-shadow:0 3px 12px rgba(24,45,76,.035);
}
.fd-role-card-arrow{
  position:absolute;
  top:15px;
  right:16px;
  color:#8b9bb0;
  font-size:15px;
  line-height:1;
}
.fd-role-summary-title{
  margin:0;
  color:#31425b;
  font-size:15px;
  line-height:1.2;
  font-weight:600;
}
.fd-role-summary-period{
  margin-top:5px;
  color:#8c98a9;
  font-size:10px;
}
.fd-role-summary-value-row{
  min-height:67px;
  padding-top:10px;
  display:flex;
  align-items:flex-end;
}
.fd-role-summary-value{
  color:#020b16;
  font-size:31px;
  line-height:1;
  font-weight:700;
  letter-spacing:-.5px;
}
.fd-role-overview-list{
  margin-top:12px;
  display:grid;
  gap:7px;
}
.fd-role-overview-item{
  min-width:0;
  display:grid;
  grid-template-columns:7px minmax(0,1fr) auto;
  align-items:center;
  gap:8px;
  color:#637289;
  font-size:9.5px;
}
.fd-role-overview-item strong{
  color:#172942;
  font-size:10px;
  font-weight:700;
}
.fd-role-overview-dot{
  width:7px;
  height:7px;
  border-radius:50%;
  background:#123d70;
}
.fd-role-overview-dot.active{background:#74b824;}
.fd-role-overview-dot.admin{background:#547493;}
.fd-role-overview-dot.users{background:#96c945;}

.fd-roles-card{
  border-radius:10px;
  box-shadow:0 4px 14px rgba(31,43,88,.04);
}
.fd-roles-toolbar{background:#fff;}
.fd-role-table tbody tr:hover{background:#fbfdf8;}

.fd-role-modal{
  width:min(1060px,100%);
  max-height:calc(100vh - 30px);
  overflow:hidden;
  display:flex;
  flex-direction:column;
}
.fd-role-modal form{
  min-height:0;
  display:flex;
  flex-direction:column;
}
.fd-role-modal-body{
  min-height:0;
  overflow:auto;
  padding:17px;
}
.fd-role-modal-header{
  padding:13px 16px;
  background:#fff;
}
.fd-role-modal-heading h3{font-size:14px;}
.fd-role-modal-heading p{font-size:9px;}
.fd-role-modal-footer{
  flex:0 0 auto;
  padding:12px 16px;
}

.fd-role-permissions{
  margin-top:17px;
  border-radius:11px;
  border-color:#dfe6ef;
  background:#fff;
}
.fd-role-permissions-head{
  min-height:60px;
  padding:11px 13px;
  align-items:center;
  background:#fff;
}
.fd-role-permissions-copy{min-width:0;}
.fd-role-permissions-head strong{
  color:#1e304a;
  font-size:11px;
}
.fd-role-permissions-head small{
  max-width:620px;
  margin-top:4px;
  font-size:8.5px;
  line-height:1.45;
}
.fd-role-permissions-head-meta{
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:7px;
  flex-wrap:wrap;
}
.fd-role-permission-count{
  padding:5px 7px;
  border:1px solid #e0e6ed;
  border-radius:999px;
  color:#69788e;
  background:#f8fafc;
  font-size:8px;
  white-space:nowrap;
}
.fd-role-select-all-control,
.fd-role-action-select,
.fd-role-module-select-all{
  margin:0;
  display:inline-flex;
  align-items:center;
  gap:6px;
  color:#4c5f77;
  font-size:8.5px;
  font-weight:600;
  cursor:pointer;
  white-space:nowrap;
}
.fd-role-select-all-control{
  min-height:30px;
  padding:0 9px;
  border:1px solid #cfe3ae;
  border-radius:7px;
  color:var(--fd-green-dark);
  background:#f8fced;
}
.fd-role-select-all-control input,
.fd-role-action-select input,
.fd-role-module-select-all input{
  width:13px;
  height:13px;
  margin:0;
  accent-color:var(--fd-green);
}
.fd-role-permission-bulk{
  min-height:42px;
  padding:7px 12px;
  display:flex;
  align-items:center;
  gap:7px;
  flex-wrap:wrap;
  border-bottom:1px solid #edf1f5;
  background:#fbfcfd;
}
.fd-role-permission-bulk:empty{display:none;}
.fd-role-permission-bulk-label{
  margin-right:2px;
  color:#738197;
  font-size:8px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.35px;
}
.fd-role-action-select{
  min-height:27px;
  padding:0 8px;
  border:1px solid #e0e6ec;
  border-radius:7px;
  background:#fff;
}
.fd-role-action-select:hover{
  border-color:#cfe3ae;
  color:var(--fd-green-dark);
  background:#f9fcf4;
}
.fd-role-permission-list{
  max-height:430px;
  padding:11px;
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;
  overflow:auto;
  background:#f8fafc;
}
.fd-role-permission-module{
  min-width:0;
  overflow:hidden;
  border:1px solid #dfe6ed !important;
  border-radius:10px;
  background:#fff;
  box-shadow:0 2px 8px rgba(24,45,76,.025);
}
.fd-role-permission-title{
  min-height:48px;
  padding:8px 10px;
  display:flex;
  align-items:center;
  gap:8px;
  border-bottom:1px solid #edf1f4;
  color:#34465e;
  background:#fff;
}
.fd-role-permission-module-icon{
  width:30px;
  height:30px;
  flex:0 0 30px;
  display:grid;
  place-items:center;
  border-radius:8px;
  color:#123d70;
  background:#edf2f7;
  font-size:13px;
}
.fd-role-permission-module-copy{
  min-width:0;
  flex:1;
}
.fd-role-permission-module-copy strong,
.fd-role-permission-module-copy small{display:block;}
.fd-role-permission-module-copy strong{
  overflow:hidden;
  color:#172942;
  font-size:9.5px;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.fd-role-permission-module-copy small{
  margin-top:2px;
  overflow:hidden;
  color:#8a96a7;
  font-size:7.5px;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.fd-role-module-select-all{
  min-height:27px;
  padding:0 7px;
  border:1px solid #e0e6ec;
  border-radius:7px;
  background:#fbfcfd;
}
.fd-role-permission-items{
  padding:9px 10px 10px;
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:7px;
}
.fd-role-permission-check{
  min-width:0;
  min-height:43px;
  padding:7px 8px;
  display:grid;
  grid-template-columns:14px minmax(0,1fr);
  grid-template-rows:auto auto;
  align-items:center;
  column-gap:6px;
  row-gap:1px;
  border:1px solid #e2e7ed;
  border-radius:8px;
  color:#52647b;
  background:#fff;
  font-size:8.5px;
}
.fd-role-permission-check:hover{
  border-color:#cfe3ae;
  background:#fbfdf8;
}
.fd-role-permission-check input{
  grid-row:1/3;
  width:13px;
  height:13px;
  margin:0;
}
.fd-role-permission-action{
  overflow:hidden;
  color:#34465e;
  font-size:8.5px;
  font-weight:700;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.fd-role-permission-check small{
  overflow:hidden;
  color:#98a3b1;
  font-size:6.8px;
  font-weight:400;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.fd-role-permission-check:has(input:checked){
  border-color:#bddc91;
  background:#f8fced;
}
.fd-role-permission-check:has(input:checked) .fd-role-permission-action{
  color:var(--fd-green-dark);
}

@media(max-width:991.98px){
  .fd-role-permission-list{grid-template-columns:1fr;}
}
@media(max-width:767.98px){
  .fd-role-permissions-head{align-items:flex-start;flex-direction:column;}
  .fd-role-permissions-head-meta{width:100%;justify-content:flex-start;}
  .fd-role-permission-items{grid-template-columns:repeat(2,minmax(0,1fr));}
}
@media(max-width:480px){
  .fd-role-permission-items{grid-template-columns:1fr;}
  .fd-role-permission-bulk{align-items:stretch;}
  .fd-role-action-select{flex:1 1 calc(50% - 8px);}
}

</style>
</head>

<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>
    <div class="fieldplx-main-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="fieldplx-main-content">
            <div class="fieldplx-content-wrapper">
                <div class="fd-dashboard">

                    <section class="fd-roles-header">
                        <div>
                            <h1 class="fd-roles-title">Roles & Permissions</h1>
                            <p class="fd-roles-subtitle">
                                Create employee roles and control access to every module available in this tenant's sidebar.
                            </p>
                        </div>

                        <div class="fd-roles-actions">
                            <button
                                type="button"
                                class="fd-role-button primary"
                                id="addRoleButton"
                            >
                                <i class="bi bi-plus-lg"></i>
                                Add Role
                            </button>
                        </div>
                    </section>

                    <section class="row g-3 fd-roles-summary">
                        <div class="col-xl-3 col-md-6">
                            <article class="fd-role-summary-card fd-role-overview-card">
                                <span class="fd-role-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                                <h2 class="fd-role-summary-title">Overview</h2>
                                <div class="fd-role-overview-list">
                                    <div class="fd-role-overview-item">
                                        <span class="fd-role-overview-dot total"></span>
                                        <span>Total roles</span>
                                        <strong><?= (int)$rolesSummary['total'] ?></strong>
                                    </div>
                                    <div class="fd-role-overview-item">
                                        <span class="fd-role-overview-dot active"></span>
                                        <span>Active roles</span>
                                        <strong><?= (int)$rolesSummary['active'] ?></strong>
                                    </div>
                                    <div class="fd-role-overview-item">
                                        <span class="fd-role-overview-dot admin"></span>
                                        <span>Admin roles</span>
                                        <strong><?= (int)$rolesSummary['admin'] ?></strong>
                                    </div>
                                    <div class="fd-role-overview-item">
                                        <span class="fd-role-overview-dot users"></span>
                                        <span>Assigned users</span>
                                        <strong><?= (int)$rolesSummary['assigned_users'] ?></strong>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div class="col-xl-3 col-md-6">
                            <article class="fd-role-summary-card fd-role-metric-card">
                                <span class="fd-role-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                                <h2 class="fd-role-summary-title">Total roles</h2>
                                <div class="fd-role-summary-period">Tenant role master</div>
                                <div class="fd-role-summary-value-row">
                                    <strong class="fd-role-summary-value"><?= (int)$rolesSummary['total'] ?></strong>
                                </div>
                            </article>
                        </div>

                        <div class="col-xl-3 col-md-6">
                            <article class="fd-role-summary-card fd-role-metric-card">
                                <span class="fd-role-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                                <h2 class="fd-role-summary-title">Active roles</h2>
                                <div class="fd-role-summary-period">Available for assignment</div>
                                <div class="fd-role-summary-value-row">
                                    <strong class="fd-role-summary-value"><?= (int)$rolesSummary['active'] ?></strong>
                                </div>
                            </article>
                        </div>

                        <div class="col-xl-3 col-md-6">
                            <article class="fd-role-summary-card fd-role-metric-card">
                                <span class="fd-role-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                                <h2 class="fd-role-summary-title">Assigned users</h2>
                                <div class="fd-role-summary-period">Employees with a role</div>
                                <div class="fd-role-summary-value-row">
                                    <strong class="fd-role-summary-value"><?= (int)$rolesSummary['assigned_users'] ?></strong>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="fd-card fd-roles-card">

                        <div class="fd-roles-toolbar">

                            <div class="fd-role-search">
                                <i class="bi bi-search"></i>
                                <input
                                    type="search"
                                    id="rolesSearch"
                                    placeholder="Search role name or code"
                                    autocomplete="off"
                                >
                            </div>

                            <select
                                class="fd-role-filter"
                                id="statusFilter"
                            >
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>

                            <select
                                class="fd-role-filter"
                                id="typeFilter"
                            >
                                <option value="">All Types</option>
                                <option value="admin">Admin</option>
                                <option value="standard">Standard</option>
                                <option value="system">System</option>
                            </select>

                            <div class="fd-role-toolbar-spacer"></div>

                            <button
                                type="button"
                                class="fd-role-button"
                                id="clearFiltersButton"
                            >
                                <i class="bi bi-x-circle"></i>
                                Clear
                            </button>

                        </div>

                        <div class="fd-role-table-wrap">

                            <table class="fd-role-table">

                                <thead>
                                    <tr>
                                        <th>S/No</th>
                                        <th>Role</th>
                                        <th>Type</th>
                                        <th>Permissions</th>
                                        <th>Users</th>
                                        <th>Status</th>
                                        <th>Updated</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody id="rolesTableBody">
                                    <tr>
                                        <td colspan="8" class="fd-role-empty">
                                            Loading roles...
                                        </td>
                                    </tr>
                                </tbody>

                            </table>

                        </div>

                        <div class="fd-role-pagination">

                            <span id="rolesCountText">
                                Showing 0 roles
                            </span>

                            <div class="fd-role-pagination-actions">

                                <button
                                    type="button"
                                    class="fd-role-button"
                                    id="prevPageButton"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </button>

                                <button
                                    type="button"
                                    class="fd-role-button"
                                    id="nextPageButton"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </button>

                            </div>

                        </div>

                    </section>

                </div>

                <div
                    class="fd-role-modal-backdrop"
                    id="roleModalBackdrop"
                    aria-hidden="true"
                >

                    <section
                        class="fd-role-modal"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="roleModalTitle"
                    >

                        <div class="fd-role-modal-header">

                            <span class="fd-role-modal-icon">
                                <i class="bi bi-shield-check"></i>
                            </span>

                            <div class="fd-role-modal-heading">
                                <h3 id="roleModalTitle">Add Role</h3>
                                <p id="roleModalSubtitle">
                                    Create a tenant role and assign allowed permissions.
                                </p>
                            </div>

                            <button
                                type="button"
                                class="fd-role-modal-close"
                                id="roleModalClose"
                                aria-label="Close"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>

                        </div>

                        <form id="roleForm">

                            <div class="fd-role-modal-body">

                                <input
                                    type="hidden"
                                    id="roleId"
                                    name="role_id"
                                    value="0"
                                >

                                <div class="fd-role-form-grid">

                                    <div class="fd-role-field">

                                        <label for="roleName">
                                            Role Name
                                        </label>

                                        <input
                                            type="text"
                                            id="roleName"
                                            name="name"
                                            maxlength="150"
                                            placeholder="Example: Operations Manager"
                                            required
                                        >

                                    </div>

                                    <div class="fd-role-field">

                                        <label for="roleCode">
                                            Role Code
                                        </label>

                                        <input
                                            type="text"
                                            id="roleCode"
                                            name="code"
                                            maxlength="100"
                                            placeholder="operations_manager"
                                            required
                                        >

                                    </div>

                                    <div class="fd-role-field full">

                                        <label for="roleDescription">
                                            Description
                                        </label>

                                        <textarea
                                            id="roleDescription"
                                            name="description"
                                            maxlength="500"
                                            placeholder="Describe this role"
                                        ></textarea>

                                    </div>

                                    <div class="fd-role-field">

                                        <div class="fd-role-switch-row">

                                            <span class="fd-role-switch-copy">
                                                <strong>Administrator Role</strong>
                                                <small>Use admin behavior where allowed.</small>
                                            </span>

                                            <label class="fd-role-switch">
                                                <input
                                                    type="checkbox"
                                                    id="roleIsAdmin"
                                                    name="is_admin"
                                                    value="1"
                                                >
                                                <span></span>
                                            </label>

                                        </div>

                                    </div>

                                    <div class="fd-role-field">

                                        <label for="roleStatus">
                                            Status
                                        </label>

                                        <select
                                            id="roleStatus"
                                            name="status"
                                        >
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                        </select>

                                    </div>

                                </div>

                                <div class="fd-role-permissions">

                                    <div class="fd-role-permissions-head">
                                        <span class="fd-role-permissions-copy">
                                            <strong>Role Permissions</strong>
                                            <small>Matches the modules currently available in this tenant's sidebar and plan.</small>
                                        </span>

                                        <div class="fd-role-permissions-head-meta">
                                            <span class="fd-role-permission-count" id="permissionModuleCount">0 modules</span>
                                            <span class="fd-role-permission-count" id="permissionSelectedCount">0 selected</span>
                                            <label class="fd-role-select-all-control">
                                                <input type="checkbox" id="selectAllPermissions">
                                                <span>Select all permissions</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="fd-role-permission-bulk" id="permissionActionBulk" aria-label="Select permissions by action"></div>

                                    <div
                                        class="fd-role-permission-list"
                                        id="permissionList"
                                    >
                                        <div class="fd-role-empty">
                                            Loading permissions...
                                        </div>
                                    </div>

                                </div>

                            </div>

                            <div class="fd-role-modal-footer">

                                <button
                                    type="button"
                                    class="fd-role-button"
                                    id="cancelRoleButton"
                                >
                                    Cancel
                                </button>

                                <button
                                    type="submit"
                                    class="fd-role-button primary"
                                    id="saveRoleButton"
                                >
                                    <span class="fd-role-loader"></span>
                                    <i class="bi bi-check-lg"></i>
                                    <span id="saveRoleButtonText">Save Role</span>
                                </button>

                            </div>

                        </form>

                    </section>

                </div>

                <div
                    class="fd-role-modal-backdrop"
                    id="deleteModalBackdrop"
                    aria-hidden="true"
                >

                    <section
                        class="fd-role-modal fd-role-confirm"
                        role="dialog"
                        aria-modal="true"
                    >

                        <div class="fd-role-modal-header">

                            <span class="fd-role-modal-icon">
                                <i class="bi bi-trash"></i>
                            </span>

                            <div class="fd-role-modal-heading">
                                <h3>Delete Role</h3>
                                <p>This action cannot be undone.</p>
                            </div>

                            <button
                                type="button"
                                class="fd-role-modal-close"
                                id="deleteModalClose"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>

                        </div>

                        <div class="fd-role-modal-body" id="deleteRoleMessage">
                            Delete this role?
                        </div>

                        <div class="fd-role-modal-footer">

                            <button
                                type="button"
                                class="fd-role-button"
                                id="cancelDeleteButton"
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                class="fd-role-button danger"
                                id="confirmDeleteButton"
                            >
                                <span class="fd-role-loader"></span>
                                <i class="bi bi-trash"></i>
                                Delete
                            </button>

                        </div>

                    </section>

                </div>

                <div
                    class="fd-role-toast info"
                    id="rolesToast"
                    role="status"
                    aria-live="polite"
                >

                    <span class="fd-role-toast-icon">
                        <i class="bi bi-info-lg" id="rolesToastIcon"></i>
                    </span>

                    <span
                        class="fd-role-toast-message"
                        id="rolesToastMessage"
                    >
                        Notification
                    </span>

                    <button
                        type="button"
                        class="fd-role-toast-close"
                        id="rolesToastClose"
                    >
                        <i class="bi bi-x"></i>
                    </button>

                </div>

                <script>
                (function(){
                'use strict';

                var csrfToken =
                    <?= json_encode($rolesCsrfToken) ?>;

                var state = {
                    page:1,
                    perPage:10,
                    search:'',
                    status:'',
                    type:'',
                    deletingId:0,
                    editingRole:null,
                    permissions:[]
                };

                var tableBody =
                    document.getElementById('rolesTableBody');

                var searchInput =
                    document.getElementById('rolesSearch');

                var statusFilter =
                    document.getElementById('statusFilter');

                var typeFilter =
                    document.getElementById('typeFilter');

                var roleModal =
                    document.getElementById('roleModalBackdrop');

                var deleteModal =
                    document.getElementById('deleteModalBackdrop');

                var roleForm =
                    document.getElementById('roleForm');

                var saveRoleButton =
                    document.getElementById('saveRoleButton');

                var permissionList =
                    document.getElementById('permissionList');

                var selectAllPermissions =
                    document.getElementById('selectAllPermissions');

                var permissionActionBulk =
                    document.getElementById('permissionActionBulk');

                var permissionModuleCount =
                    document.getElementById('permissionModuleCount');

                var permissionSelectedCount =
                    document.getElementById('permissionSelectedCount');

                var toast =
                    document.getElementById('rolesToast');

                var toastMessage =
                    document.getElementById('rolesToastMessage');

                var toastIcon =
                    document.getElementById('rolesToastIcon');

                var toastTimer = null;
                var searchTimer = null;

                function escapeHtml(value){
                    return String(value == null ? '' : value)
                        .replace(/&/g,'&amp;')
                        .replace(/</g,'&lt;')
                        .replace(/>/g,'&gt;')
                        .replace(/"/g,'&quot;')
                        .replace(/'/g,'&#039;');
                }

                function showToast(type,message,duration){
                    if(toastTimer){
                        clearTimeout(toastTimer);
                    }

                    var icons = {
                        success:'bi-check-lg',
                        error:'bi-x-lg',
                        warning:'bi-exclamation-lg',
                        info:'bi-info-lg'
                    };

                    var t = type || 'info';

                    toast.className =
                        'fd-role-toast ' + t;

                    toastMessage.textContent =
                        message || 'Notification';

                    toastIcon.className =
                        'bi ' + (icons[t] || icons.info);

                    toast.classList.add('show');

                    toastTimer = setTimeout(
                        function(){
                            toast.classList.remove('show');
                            toastTimer = null;
                        },
                        typeof duration === 'number'
                            ? duration
                            : 3000
                    );
                }

                function setLoading(button,loading){
                    if(!button){
                        return;
                    }

                    button.disabled = !!loading;
                    button.classList.toggle(
                        'loading',
                        !!loading
                    );
                }

                function parseResponse(response){
                    return response.text().then(
                        function(rawText){
                            var text =
                                (rawText || '').trim();

                            var data = null;

                            try{
                                data =
                                    text !== ''
                                        ? JSON.parse(text)
                                        : {};
                            }catch(e){
                                var clean =
                                    text
                                        .replace(/<br\s*\/?>/gi,' ')
                                        .replace(/<[^>]*>/g,' ')
                                        .replace(/\s+/g,' ')
                                        .trim();

                                throw new Error(
                                    clean !== ''
                                        ? 'Server error: ' + clean
                                        : 'Server returned an invalid response.'
                                );
                            }

                            return {
                                ok:response.ok,
                                status:response.status,
                                data:data
                            };
                        }
                    );
                }

                function apiRequest(formData){
                    formData.append(
                        'csrf_token',
                        csrfToken
                    );

                    return fetch(
                        'api/roles.php',
                        {
                            method:'POST',
                            body:formData,
                            credentials:'same-origin',
                            headers:{
                                'X-Requested-With':'XMLHttpRequest',
                                'Accept':'application/json'
                            }
                        }
                    )
                    .then(parseResponse)
                    .then(function(result){
                        if(
                            !result.ok ||
                            !result.data ||
                            result.data.success !== true
                        ){
                            throw new Error(
                                result.data &&
                                result.data.message
                                    ? result.data.message
                                    : 'Request failed.'
                            );
                        }

                        return result.data;
                    });
                }

                function formatDate(value){
                    if(!value){
                        return '-';
                    }

                    var date = new Date(
                        String(value).replace(' ','T')
                    );

                    if(isNaN(date.getTime())){
                        return escapeHtml(value);
                    }

                    return date.toLocaleDateString(
                        undefined,
                        {
                            day:'2-digit',
                            month:'short',
                            year:'numeric'
                        }
                    );
                }

                function renderRoles(rows){
                    if(!rows || !rows.length){
                        tableBody.innerHTML =
                            '<tr><td colspan="8" class="fd-role-empty">' +
                            'No roles found.' +
                            '</td></tr>';
                        return;
                    }

                    var html = '';

                    rows.forEach(
                        function(row,index){
                            var typeBadges = '';

                            if(Number(row.is_admin) === 1){
                                typeBadges +=
                                    '<span class="fd-role-badge admin">Admin</span> ';
                            }else{
                                typeBadges +=
                                    '<span class="fd-role-badge inactive">Standard</span> ';
                            }

                            if(Number(row.is_system_role) === 1){
                                typeBadges +=
                                    '<span class="fd-role-badge system">System</span>';
                            }

                            var statusClass =
                                row.status === 'active'
                                    ? 'active'
                                    : 'inactive';

                            var deleteButton =
                                Number(row.is_system_role) === 1
                                    ? ''
                                    : '<button type="button" class="fd-role-icon-button danger" ' +
                                      'data-action="delete" data-id="' +
                                      Number(row.id) +
                                      '" title="Delete"><i class="bi bi-trash"></i></button>';

                            html +=
                                '<tr>' +
                                    '<td>' +
                                        ((state.page - 1) * state.perPage + index + 1) +
                                    '</td>' +
                                    '<td>' +
                                        '<div class="fd-role-name">' +
                                            '<span class="fd-role-name-icon">' +
                                                '<i class="bi bi-shield-check"></i>' +
                                            '</span>' +
                                            '<span>' +
                                                '<strong>' + escapeHtml(row.name) + '</strong>' +
                                                '<small>' + escapeHtml(row.code) + '</small>' +
                                            '</span>' +
                                        '</div>' +
                                    '</td>' +
                                    '<td>' + typeBadges + '</td>' +
                                    '<td>' + Number(row.permission_count || 0) + '</td>' +
                                    '<td>' + Number(row.user_count || 0) + '</td>' +
                                    '<td>' +
                                        '<span class="fd-role-badge ' + statusClass + '">' +
                                            escapeHtml(row.status) +
                                        '</span>' +
                                    '</td>' +
                                    '<td>' + formatDate(row.updated_at || row.created_at) + '</td>' +
                                    '<td>' +
                                        '<div class="fd-role-action-wrap">' +
                                            '<button type="button" class="fd-role-icon-button" ' +
                                            'data-action="edit" data-id="' +
                                            Number(row.id) +
                                            '" title="Edit">' +
                                            '<i class="bi bi-pencil"></i></button>' +
                                            '<button type="button" class="fd-role-icon-button" ' +
                                            'data-action="toggle" data-id="' +
                                            Number(row.id) +
                                            '" data-status="' +
                                            escapeHtml(row.status) +
                                            '" title="Change Status">' +
                                            '<i class="bi bi-power"></i></button>' +
                                            deleteButton +
                                        '</div>' +
                                    '</td>' +
                                '</tr>';
                        }
                    );

                    tableBody.innerHTML = html;
                }

                function loadRoles(){
                    var formData = new FormData();

                    formData.append('action','list');
                    formData.append('page',state.page);
                    formData.append('per_page',state.perPage);
                    formData.append('search',state.search);
                    formData.append('status',state.status);
                    formData.append('type',state.type);

                    tableBody.innerHTML =
                        '<tr><td colspan="8" class="fd-role-empty">' +
                        'Loading roles...' +
                        '</td></tr>';

                    apiRequest(formData)
                        .then(function(data){
                            renderRoles(data.roles || []);

                            var pagination =
                                data.pagination || {};

                            var total =
                                Number(pagination.total || 0);

                            var pages =
                                Number(pagination.pages || 1);

                            document.getElementById('rolesCountText').textContent =
                                'Showing ' +
                                Number(pagination.from || 0) +
                                '-' +
                                Number(pagination.to || 0) +
                                ' of ' +
                                total +
                                ' roles';

                            document.getElementById('prevPageButton').disabled =
                                state.page <= 1;

                            document.getElementById('nextPageButton').disabled =
                                state.page >= pages;
                        })
                        .catch(function(error){
                            tableBody.innerHTML =
                                '<tr><td colspan="8" class="fd-role-empty">' +
                                escapeHtml(error.message) +
                                '</td></tr>';

                            showToast(
                                'error',
                                error.message,
                                3000
                            );
                        });
                }

                function permissionActionLabel(actionCode){
                    var labels = {
                        view:'View',
                        create:'Create',
                        update:'Update',
                        delete:'Delete',
                        approve:'Approve',
                        export:'Export'
                    };

                    var key = String(actionCode || '').toLowerCase();
                    return labels[key] || (key ? key.charAt(0).toUpperCase() + key.slice(1) : 'Permission');
                }

                function permissionIcon(module){
                    var icon = String(module.icon_name || '').trim();
                    if(icon === ''){
                        return 'bi bi-grid';
                    }
                    if(icon.indexOf('bi ') === 0 || icon.indexOf('bi-') === 0){
                        return icon.indexOf('bi ') === 0 ? icon : 'bi ' + icon;
                    }
                    return 'bi bi-grid';
                }

                function buildPermissionList(
                    permissions,
                    selectedIds
                ){
                    state.permissions = permissions || [];
                    selectedIds = (selectedIds || []).map(Number);

                    if(!state.permissions.length){
                        permissionActionBulk.innerHTML = '';
                        permissionModuleCount.textContent = '0 modules';
                        permissionSelectedCount.textContent = '0 selected';
                        permissionList.innerHTML =
                            '<div class="fd-role-empty">' +
                            'No tenant sidebar permissions are available. Check this tenant\'s active plan modules.' +
                            '</div>';
                        selectAllPermissions.checked = false;
                        selectAllPermissions.indeterminate = false;
                        return;
                    }

                    var modules = [];
                    var moduleMap = {};
                    var actionCodes = [];
                    var seenActions = {};

                    state.permissions.forEach(function(item){
                        var moduleKey = String(item.module_id || item.module_code || item.module_name || 'other');

                        if(!moduleMap[moduleKey]){
                            moduleMap[moduleKey] = {
                                id:Number(item.module_id || 0),
                                code:item.module_code || '',
                                name:item.module_name || 'Other',
                                parent:item.parent_module_name || '',
                                icon_name:item.icon_name || '',
                                permissions:[]
                            };
                            modules.push(moduleMap[moduleKey]);
                        }

                        moduleMap[moduleKey].permissions.push(item);

                        var actionKey = String(item.action_code || '').toLowerCase();
                        if(actionKey !== '' && !seenActions[actionKey]){
                            seenActions[actionKey] = true;
                            actionCodes.push(actionKey);
                        }
                    });

                    var preferredOrder = ['view','create','update','delete','approve','export'];
                    actionCodes.sort(function(a,b){
                        var ai = preferredOrder.indexOf(a);
                        var bi = preferredOrder.indexOf(b);
                        ai = ai === -1 ? 999 : ai;
                        bi = bi === -1 ? 999 : bi;
                        return ai === bi ? a.localeCompare(b) : ai - bi;
                    });

                    permissionModuleCount.textContent =
                        modules.length + (modules.length === 1 ? ' module' : ' modules');

                    var bulkHtml =
                        '<span class="fd-role-permission-bulk-label">Select by action</span>';

                    actionCodes.forEach(function(actionCode){
                        bulkHtml +=
                            '<label class="fd-role-action-select">' +
                                '<input type="checkbox" class="fd-role-action-select-all" data-action="' +
                                escapeHtml(actionCode) + '">' +
                                '<span>' + escapeHtml(permissionActionLabel(actionCode)) + ' all</span>' +
                            '</label>';
                    });

                    permissionActionBulk.innerHTML = bulkHtml;

                    var html = '';

                    modules.forEach(function(module){
                        var moduleId = Number(module.id || 0);
                        var moduleKey = moduleId > 0 ? String(moduleId) : module.code;
                        var subtitle = module.parent ? module.parent + ' / ' + module.code : module.code;

                        html +=
                            '<section class="fd-role-permission-module" data-module-id="' + escapeHtml(moduleKey) + '">' +
                                '<div class="fd-role-permission-title">' +
                                    '<span class="fd-role-permission-module-icon"><i class="' + escapeHtml(permissionIcon(module)) + '"></i></span>' +
                                    '<span class="fd-role-permission-module-copy">' +
                                        '<strong>' + escapeHtml(module.name) + '</strong>' +
                                        '<small>' + escapeHtml(subtitle || 'Sidebar module') + '</small>' +
                                    '</span>' +
                                    '<label class="fd-role-module-select-all">' +
                                        '<input type="checkbox" class="fd-role-module-select" data-module-id="' + escapeHtml(moduleKey) + '">' +
                                        '<span>Select all</span>' +
                                    '</label>' +
                                '</div>' +
                                '<div class="fd-role-permission-items">';

                        module.permissions.forEach(function(permission){
                            var checked =
                                selectedIds.indexOf(Number(permission.id)) !== -1;
                            var actionCode = String(permission.action_code || '').toLowerCase();

                            html +=
                                '<label class="fd-role-permission-check" title="' + escapeHtml(permission.description || permission.permission_code || '') + '">' +
                                    '<input type="checkbox" ' +
                                    'class="fd-role-permission-box" ' +
                                    'name="permission_ids[]" ' +
                                    'data-module-id="' + escapeHtml(moduleKey) + '" ' +
                                    'data-action="' + escapeHtml(actionCode) + '" ' +
                                    'value="' + Number(permission.id) + '"' +
                                    (checked ? ' checked' : '') +
                                    '>' +
                                    '<span class="fd-role-permission-action">' + escapeHtml(permissionActionLabel(actionCode)) + '</span>' +
                                    '<small>' + escapeHtml(permission.permission_code || '') + '</small>' +
                                '</label>';
                        });

                        html +=
                                '</div>' +
                            '</section>';
                    });

                    permissionList.innerHTML = html;
                    updateSelectAllState();
                }

                function updateSelectAllState(){
                    var boxes = permissionList.querySelectorAll('input[name="permission_ids[]"]');
                    var checked = permissionList.querySelectorAll('input[name="permission_ids[]"]:checked');

                    permissionSelectedCount.textContent =
                        checked.length + ' of ' + boxes.length + ' selected';

                    if(!boxes.length){
                        selectAllPermissions.checked = false;
                        selectAllPermissions.indeterminate = false;
                        return;
                    }

                    selectAllPermissions.checked = checked.length === boxes.length;
                    selectAllPermissions.indeterminate = checked.length > 0 && checked.length < boxes.length;

                    permissionList.querySelectorAll('.fd-role-module-select').forEach(function(moduleBox){
                        var moduleId = moduleBox.getAttribute('data-module-id');
                        var moduleBoxes = permissionList.querySelectorAll(
                            'input[name="permission_ids[]"][data-module-id="' + CSS.escape(moduleId) + '"]'
                        );
                        var moduleChecked = permissionList.querySelectorAll(
                            'input[name="permission_ids[]"][data-module-id="' + CSS.escape(moduleId) + '"]:checked'
                        );

                        moduleBox.checked = moduleBoxes.length > 0 && moduleChecked.length === moduleBoxes.length;
                        moduleBox.indeterminate = moduleChecked.length > 0 && moduleChecked.length < moduleBoxes.length;
                    });

                    permissionActionBulk.querySelectorAll('.fd-role-action-select-all').forEach(function(actionBox){
                        var actionCode = actionBox.getAttribute('data-action');
                        var actionBoxes = permissionList.querySelectorAll(
                            'input[name="permission_ids[]"][data-action="' + CSS.escape(actionCode) + '"]'
                        );
                        var actionChecked = permissionList.querySelectorAll(
                            'input[name="permission_ids[]"][data-action="' + CSS.escape(actionCode) + '"]:checked'
                        );

                        actionBox.checked = actionBoxes.length > 0 && actionChecked.length === actionBoxes.length;
                        actionBox.indeterminate = actionChecked.length > 0 && actionChecked.length < actionBoxes.length;
                    });
                }

                function openRoleModal(roleId){
                    var formData = new FormData();

                    formData.append(
                        'action',
                        roleId > 0
                            ? 'get'
                            : 'permissions'
                    );

                    if(roleId > 0){
                        formData.append(
                            'role_id',
                            roleId
                        );
                    }

                    roleForm.reset();

                    document.getElementById('roleId').value =
                        roleId > 0
                            ? roleId
                            : 0;

                    document.getElementById('roleStatus').value =
                        'active';

                    permissionList.innerHTML =
                        '<div class="fd-role-empty">' +
                        'Loading permissions...' +
                        '</div>';

                    roleModal.classList.add('show');
                    roleModal.setAttribute('aria-hidden','false');

                    apiRequest(formData)
                        .then(function(data){
                            var role =
                                data.role || null;

                            var selected =
                                data.selected_permission_ids || [];

                            buildPermissionList(
                                data.permissions || [],
                                selected
                            );

                            if(role){
                                state.editingRole = role;

                                document.getElementById('roleModalTitle').textContent =
                                    'Edit Role';

                                document.getElementById('roleModalSubtitle').textContent =
                                    Number(role.is_system_role) === 1
                                        ? 'System role: core identity fields are protected.'
                                        : 'Update role details and permissions.';

                                document.getElementById('roleName').value =
                                    role.name || '';

                                document.getElementById('roleCode').value =
                                    role.code || '';

                                document.getElementById('roleDescription').value =
                                    role.description || '';

                                document.getElementById('roleIsAdmin').checked =
                                    Number(role.is_admin) === 1;

                                document.getElementById('roleStatus').value =
                                    role.status || 'active';

                                document.getElementById('roleName').readOnly =
                                    Number(role.is_system_role) === 1;

                                document.getElementById('roleCode').readOnly =
                                    Number(role.is_system_role) === 1;

                                document.getElementById('roleIsAdmin').disabled =
                                    Number(role.is_system_role) === 1;

                                document.getElementById('saveRoleButtonText').textContent =
                                    'Update Role';
                            }else{
                                state.editingRole = null;

                                document.getElementById('roleModalTitle').textContent =
                                    'Add Role';

                                document.getElementById('roleModalSubtitle').textContent =
                                    'Create a tenant role and assign allowed permissions.';

                                document.getElementById('roleName').readOnly = false;
                                document.getElementById('roleCode').readOnly = false;
                                document.getElementById('roleIsAdmin').disabled = false;

                                document.getElementById('saveRoleButtonText').textContent =
                                    'Save Role';
                            }
                        })
                        .catch(function(error){
                            closeRoleModal();

                            showToast(
                                'error',
                                error.message,
                                3000
                            );
                        });
                }

                function closeRoleModal(){
                    roleModal.classList.remove('show');
                    roleModal.setAttribute('aria-hidden','true');
                    state.editingRole = null;
                }

                function saveRole(){
                    if(!roleForm.reportValidity()){
                        showToast(
                            'warning',
                            'Complete the required role fields.',
                            3000
                        );
                        return;
                    }

                    var formData =
                        new FormData(roleForm);

                    formData.append('action','save');

                    setLoading(
                        saveRoleButton,
                        true
                    );

                    apiRequest(formData)
                        .then(function(data){
                            closeRoleModal();

                            showToast(
                                'success',
                                data.message || 'Role saved successfully.',
                                3000
                            );

                            loadRoles();
                        })
                        .catch(function(error){
                            showToast(
                                'error',
                                error.message,
                                3000
                            );
                        })
                        .finally(function(){
                            setLoading(
                                saveRoleButton,
                                false
                            );
                        });
                }

                function toggleStatus(id,currentStatus){
                    var next =
                        currentStatus === 'active'
                            ? 'inactive'
                            : 'active';

                    var formData = new FormData();

                    formData.append('action','change_status');
                    formData.append('role_id',id);
                    formData.append('status',next);

                    apiRequest(formData)
                        .then(function(data){
                            showToast(
                                'success',
                                data.message || 'Role status updated.',
                                3000
                            );

                            loadRoles();
                        })
                        .catch(function(error){
                            showToast(
                                'error',
                                error.message,
                                3000
                            );
                        });
                }

                function openDeleteModal(id,name){
                    state.deletingId = Number(id);

                    document.getElementById('deleteRoleMessage').textContent =
                        'Delete role "' +
                        (name || 'this role') +
                        '"? Users assigned to this role will prevent deletion.';

                    deleteModal.classList.add('show');
                    deleteModal.setAttribute('aria-hidden','false');
                }

                function closeDeleteModal(){
                    state.deletingId = 0;
                    deleteModal.classList.remove('show');
                    deleteModal.setAttribute('aria-hidden','true');
                }

                function deleteRole(){
                    if(state.deletingId <= 0){
                        return;
                    }

                    var button =
                        document.getElementById('confirmDeleteButton');

                    var formData = new FormData();

                    formData.append('action','delete');
                    formData.append(
                        'role_id',
                        state.deletingId
                    );

                    setLoading(
                        button,
                        true
                    );

                    apiRequest(formData)
                        .then(function(data){
                            closeDeleteModal();

                            showToast(
                                'success',
                                data.message || 'Role deleted successfully.',
                                3000
                            );

                            loadRoles();
                        })
                        .catch(function(error){
                            showToast(
                                'error',
                                error.message,
                                3000
                            );
                        })
                        .finally(function(){
                            setLoading(
                                button,
                                false
                            );
                        });
                }

                tableBody.addEventListener(
                    'click',
                    function(event){
                        var button =
                            event.target.closest(
                                '[data-action]'
                            );

                        if(!button){
                            return;
                        }

                        var action =
                            button.getAttribute(
                                'data-action'
                            );

                        var id =
                            Number(
                                button.getAttribute(
                                    'data-id'
                                )
                            );

                        if(action === 'edit'){
                            openRoleModal(id);
                            return;
                        }

                        if(action === 'toggle'){
                            toggleStatus(
                                id,
                                button.getAttribute(
                                    'data-status'
                                )
                            );
                            return;
                        }

                        if(action === 'delete'){
                            var row =
                                button.closest('tr');

                            var name =
                                row
                                    ? row.querySelector(
                                        '.fd-role-name strong'
                                      ).textContent
                                    : '';

                            openDeleteModal(
                                id,
                                name
                            );
                        }
                    }
                );

                roleForm.addEventListener(
                    'submit',
                    function(event){
                        event.preventDefault();
                        saveRole();
                    }
                );

                permissionList.addEventListener(
                    'change',
                    function(event){
                        var target = event.target;

                        if(target && target.classList.contains('fd-role-module-select')){
                            var moduleId = target.getAttribute('data-module-id');
                            permissionList.querySelectorAll(
                                'input[name="permission_ids[]"][data-module-id="' + CSS.escape(moduleId) + '"]'
                            ).forEach(function(box){
                                box.checked = target.checked;
                            });
                        }

                        updateSelectAllState();
                    }
                );

                permissionActionBulk.addEventListener(
                    'change',
                    function(event){
                        var target = event.target;
                        if(!target || !target.classList.contains('fd-role-action-select-all')){
                            return;
                        }

                        var actionCode = target.getAttribute('data-action');
                        permissionList.querySelectorAll(
                            'input[name="permission_ids[]"][data-action="' + CSS.escape(actionCode) + '"]'
                        ).forEach(function(box){
                            box.checked = target.checked;
                        });

                        updateSelectAllState();
                    }
                );

                selectAllPermissions.addEventListener(
                    'change',
                    function(){
                        permissionList.querySelectorAll(
                            'input[name="permission_ids[]"]'
                        ).forEach(function(box){
                            box.checked = selectAllPermissions.checked;
                        });

                        updateSelectAllState();
                    }
                );

                document.getElementById('addRoleButton')
                    .addEventListener(
                        'click',
                        function(){
                            openRoleModal(0);
                        }
                    );

                var refreshRolesButton = document.getElementById('refreshRolesButton');
                if(refreshRolesButton){
                    refreshRolesButton.addEventListener('click',loadRoles);
                }

                document.getElementById('roleModalClose')
                    .addEventListener(
                        'click',
                        closeRoleModal
                    );

                document.getElementById('cancelRoleButton')
                    .addEventListener(
                        'click',
                        closeRoleModal
                    );

                document.getElementById('deleteModalClose')
                    .addEventListener(
                        'click',
                        closeDeleteModal
                    );

                document.getElementById('cancelDeleteButton')
                    .addEventListener(
                        'click',
                        closeDeleteModal
                    );

                document.getElementById('confirmDeleteButton')
                    .addEventListener(
                        'click',
                        deleteRole
                    );

                document.getElementById('rolesToastClose')
                    .addEventListener(
                        'click',
                        function(){
                            toast.classList.remove('show');

                            if(toastTimer){
                                clearTimeout(toastTimer);
                                toastTimer = null;
                            }
                        }
                    );

                document.getElementById('clearFiltersButton')
                    .addEventListener(
                        'click',
                        function(){
                            searchInput.value = '';
                            statusFilter.value = '';
                            typeFilter.value = '';

                            state.search = '';
                            state.status = '';
                            state.type = '';
                            state.page = 1;

                            loadRoles();
                        }
                    );

                searchInput.addEventListener(
                    'input',
                    function(){
                        if(searchTimer){
                            clearTimeout(searchTimer);
                        }

                        searchTimer = setTimeout(
                            function(){
                                state.search =
                                    searchInput.value.trim();

                                state.page = 1;
                                loadRoles();
                            },
                            250
                        );
                    }
                );

                statusFilter.addEventListener(
                    'change',
                    function(){
                        state.status =
                            statusFilter.value;

                        state.page = 1;
                        loadRoles();
                    }
                );

                typeFilter.addEventListener(
                    'change',
                    function(){
                        state.type =
                            typeFilter.value;

                        state.page = 1;
                        loadRoles();
                    }
                );

                document.getElementById('prevPageButton')
                    .addEventListener(
                        'click',
                        function(){
                            if(state.page > 1){
                                state.page--;
                                loadRoles();
                            }
                        }
                    );

                document.getElementById('nextPageButton')
                    .addEventListener(
                        'click',
                        function(){
                            state.page++;
                            loadRoles();
                        }
                    );

                roleModal.addEventListener(
                    'click',
                    function(event){
                        if(event.target === roleModal){
                            closeRoleModal();
                        }
                    }
                );

                deleteModal.addEventListener(
                    'click',
                    function(event){
                        if(event.target === deleteModal){
                            closeDeleteModal();
                        }
                    }
                );

                document.addEventListener(
                    'keydown',
                    function(event){
                        if(event.key === 'Escape'){
                            closeRoleModal();
                            closeDeleteModal();
                        }
                    }
                );

                loadRoles();

                })();
                </script>

            </div>
        </main>
    </div>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


</body>

</html>