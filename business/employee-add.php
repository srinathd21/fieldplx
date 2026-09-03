<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Add Employee';
$activePage = 'employees';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['employees_csrf_token'])) {
    $_SESSION['employees_csrf_token'] = bin2hex(random_bytes(32));
}
$employeesCsrfToken = (string) $_SESSION['employees_csrf_token'];

$tenantId = !empty($_SESSION['tenant_id'])
    ? (int) $_SESSION['tenant_id']
    : (!empty($_SESSION['business_id']) ? (int) $_SESSION['business_id'] : 0);
$defaultBranchId = !empty($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : 0;

$tenantCurrencySymbol = '';
$tenantCurrencyCode = '';
$tenantCurrencyPosition = 'before';
$tenantName = 'FieldPlx';
$branches = array();
$departments = array();
$roles = array();

if ($tenantId > 0 && isset($pdo) && $pdo instanceof PDO) {
    try {
        $tenantStmt = $pdo->prepare(
            "SELECT
                t.display_name,
                t.legal_name,
                c.currency_code,
                c.symbol,
                c.symbol_position
             FROM tenants t
             LEFT JOIN currencies c ON c.id = t.currency_id
             WHERE t.id = :tenant_id
               AND t.deleted_at IS NULL
             LIMIT 1"
        );
        $tenantStmt->execute(array(':tenant_id' => $tenantId));
        $tenantRow = $tenantStmt->fetch(PDO::FETCH_ASSOC);
        if ($tenantRow) {
            $tenantName = trim((string) $tenantRow['display_name']);
            if ($tenantName === '') {
                $tenantName = trim((string) $tenantRow['legal_name']);
            }
            if ($tenantName === '') {
                $tenantName = 'FieldPlx';
            }
            $tenantCurrencyCode = trim((string) $tenantRow['currency_code']);
            $tenantCurrencySymbol = trim((string) $tenantRow['symbol']);
            $tenantCurrencyPosition = isset($tenantRow['symbol_position']) && (string) $tenantRow['symbol_position'] === 'after'
                ? 'after'
                : 'before';
        }

        $branchStmt = $pdo->prepare(
            "SELECT id,name,branch_code
             FROM branches
             WHERE tenant_id = :tenant_id
               AND status = 'active'
             ORDER BY is_head_office DESC,name ASC"
        );
        $branchStmt->execute(array(':tenant_id' => $tenantId));
        $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);

        $departmentStmt = $pdo->prepare(
            "SELECT id,branch_id,name,code
             FROM departments
             WHERE tenant_id = :tenant_id
               AND status = 'active'
             ORDER BY name ASC"
        );
        $departmentStmt->execute(array(':tenant_id' => $tenantId));
        $departments = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);

        $roleStmt = $pdo->prepare(
            "SELECT id,name,code,is_admin
             FROM roles
             WHERE tenant_id = :tenant_id
               AND status = 'active'
             ORDER BY is_admin DESC,name ASC"
        );
        $roleStmt->execute(array(':tenant_id' => $tenantId));
        $roles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $employeeAddLoadError) {
        error_log('FieldPlx Add Employee page data: ' . $employeeAddLoadError->getMessage());
    }
}

if ($tenantCurrencySymbol === '') {
    $tenantCurrencySymbol = $tenantCurrencyCode;
}

function employeeAddEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>Add Employee - FieldPlx</title>
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

    
/* Employees page */
.fd-employees-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.fd-employees-title{margin:0 0 7px;color:var(--fd-text);font-size:21px;font-weight:700}.fd-employees-subtitle{margin:0;color:var(--fd-muted);font-size:11px}.fd-employees-actions{display:flex;gap:8px}.fd-employee-btn{min-height:39px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;background:#fff;color:#43546c;font-size:10px;font-weight:700;cursor:pointer}.fd-employee-btn.primary{border-color:var(--fd-green);background:linear-gradient(90deg,#7fc92d,#68aa1d);color:#fff}.fd-employee-btn:hover{border-color:#cfe3ae;background:#f9fcf4;color:var(--fd-green-dark)}.fd-employee-btn.primary:hover{background:linear-gradient(90deg,#74b824,#5d971b);color:#fff}.fd-employee-btn.danger{border-color:#ffd5d9;color:#b9444d}.fd-employee-loader{width:13px;height:13px;display:none;border:2px dotted currentColor;border-radius:50%;animation:eSpin .75s linear infinite}.fd-employee-btn.loading .fd-employee-loader{display:inline-block}@keyframes eSpin{to{transform:rotate(360deg)}}
.fd-employee-stat{min-height:112px;padding:18px 20px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}.fd-employee-stat-row{min-height:72px;display:flex;align-items:center;gap:18px}.fd-employee-stat-icon{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;border-radius:16px;background:#123f73;color:#fff;font-size:25px}.fd-employee-stat-label{display:block;margin-bottom:8px;color:#506784;font-size:13px}.fd-employee-stat-value{display:block;color:#020b16;font-size:31px;line-height:1;font-weight:700}.fd-employees-card{overflow:hidden}.fd-employees-toolbar{padding:13px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--fd-border);background:#fbfcfd}.fd-employee-search{width:270px;position:relative}.fd-employee-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a96a7}.fd-employee-search input,.fd-employee-filter{height:39px;border:1px solid #dde4ec;border-radius:8px;background:#fff;color:#33445f;font-size:10px;outline:0}.fd-employee-search input{width:100%;padding:8px 11px 8px 34px}.fd-employee-filter{min-width:140px;padding:8px 10px}.fd-employee-toolbar-spacer{margin-left:auto}.fd-employee-table-wrap{
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:thin;
  scrollbar-color:#9aa0a6 transparent;
}

.fd-employee-table-wrap::-webkit-scrollbar{
  height:3px !important;
  max-height:3px !important;
}

.fd-employee-table-wrap::-webkit-scrollbar-track{
  height:3px !important;
  background:transparent !important;
}

.fd-employee-table-wrap::-webkit-scrollbar-thumb{
  min-width:20px;
  height:3px !important;
  border:0 !important;
  border-radius:999px !important;
  background:#9aa0a6 !important;
}

.fd-employee-table-wrap::-webkit-scrollbar-button{
  width:0 !important;
  height:0 !important;
  display:none !important;
}

.fd-employee-table-wrap::-webkit-scrollbar-corner{
  background:transparent !important;
}.fd-employee-table{width:100%;min-width:1180px;border-collapse:collapse;white-space:nowrap}.fd-employee-table th{padding:11px 12px;border-bottom:1px solid var(--fd-border);background:#f8fafc;color:#65738a;font-size:9px;font-weight:600;text-transform:uppercase}.fd-employee-table td{padding:12px;border-bottom:1px solid #f1f3f7;color:#33445f;font-size:9.5px}.fd-employee-person{display:flex;align-items:center;gap:10px}.fd-employee-avatar{width:36px;height:36px;flex:0 0 36px;display:grid;place-items:center;border-radius:50%;background:linear-gradient(135deg,#fff,#e8f3d9);border:1px solid #dce8cf;color:var(--fd-navy);font-size:10px;font-weight:700;overflow:hidden}.fd-employee-avatar img{width:100%;height:100%;object-fit:cover}.fd-employee-person strong,.fd-employee-person small{display:block}.fd-employee-person small{margin-top:2px;color:#8d98a8;font-size:8.5px}.fd-employee-badge{display:inline-flex;padding:5px 7px;border-radius:5px;font-size:8.5px;font-weight:600}.fd-employee-badge.active,.fd-employee-badge.field{color:#5d971b;background:#f0f8e5}.fd-employee-badge.inactive{color:#6f7b90;background:#eef2f6}.fd-employee-badge.invited,.fd-employee-badge.admin{color:#123d70;background:#edf2f7}.fd-employee-badge.suspended{color:#b9444d;background:#fff0f1}.fd-employee-actions-cell{display:flex;gap:5px}.fd-employee-icon{width:29px;height:29px;display:grid;place-items:center;border:0;border-radius:6px;background:transparent;color:#66748b;cursor:pointer}.fd-employee-icon:hover{background:var(--fd-green-soft);color:var(--fd-green-dark)}.fd-employee-icon.danger:hover{background:#fff0f1;color:#b9444d}.fd-employee-empty{padding:28px 18px!important;text-align:center;color:#9aa4b3!important;font-size:10px!important}.fd-employee-pagination{padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--fd-border);font-size:9px;color:#768397}
.fd-employee-modal-backdrop{position:fixed;inset:0;z-index:15000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.46);backdrop-filter:blur(3px)}.fd-employee-modal-backdrop.show{display:flex}.fd-employee-modal{width:min(860px,100%);max-height:calc(100vh - 36px);overflow:auto;border:1px solid #dfe5ec;border-radius:12px;background:#fff;box-shadow:0 24px 65px rgba(0,17,49,.24)}.fd-employee-modal-header{padding:11px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--fd-border);background:#fbfcfd}.fd-employee-modal-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:9px;background:var(--fd-green-soft);color:var(--fd-green-dark)}.fd-employee-modal-heading{flex:1}.fd-employee-modal-heading h3{margin:0;font-size:12px}.fd-employee-modal-heading p{margin:3px 0 0;color:var(--fd-muted);font-size:8.5px}.fd-employee-modal-close{width:30px;height:30px;border:0;border-radius:7px;background:transparent;color:#8490a0}.fd-employee-modal-body{padding:15px}.fd-employee-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.fd-employee-field.full{grid-column:1/-1}.fd-employee-field label{display:block;margin-bottom:6px;color:#42536c;font-size:9px;font-weight:700}.fd-employee-field input,.fd-employee-field select{width:100%;min-height:40px;padding:8px 10px;border:1px solid #dfe5ec;border-radius:8px;background:#fff;color:#263750;font-size:10px;outline:0}.fd-employee-section{grid-column:1/-1;padding:7px 0 2px;border-bottom:1px solid #eef2f5;color:#31425b;font-size:9px;font-weight:700;text-transform:uppercase}.fd-employee-switches{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.fd-employee-switch-row{padding:10px;border:1px solid var(--fd-border);border-radius:9px;background:#fbfcfd;display:flex;align-items:center;justify-content:space-between}.fd-employee-switch-row strong,.fd-employee-switch-row small{display:block}.fd-employee-switch-row strong{font-size:9.5px}.fd-employee-switch-row small{margin-top:2px;color:#8a96a7;font-size:8px}.fd-employee-switch input{width:15px;height:15px;accent-color:var(--fd-green)}.fd-employee-money-input{position:relative}.fd-employee-money-input .fd-employee-currency{position:absolute;top:50%;transform:translateY(-50%);z-index:2;color:#607086;font-size:10px;font-weight:700;pointer-events:none;white-space:nowrap}.fd-employee-money-input.currency-before .fd-employee-currency{left:11px}.fd-employee-money-input.currency-after .fd-employee-currency{right:11px}.fd-employee-money-input.currency-before input{padding-left:42px!important}.fd-employee-money-input.currency-after input{padding-right:42px!important}.fd-employee-field-help{display:block;margin-top:5px;color:#8793a5;font-size:8px;line-height:1.45}.fd-employee-modal-footer{padding:12px 15px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--fd-border);background:#fbfcfd}.fd-employee-confirm{width:min(440px,100%)}
.fd-employee-toast{width:min(290px,calc(100vw - 24px));position:fixed;top:82px;right:16px;z-index:25000;padding:8px 9px;display:flex;align-items:center;gap:7px;border-radius:7px;color:#fff;opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s ease;box-shadow:0 10px 26px rgba(0,17,49,.18)}.fd-employee-toast.show{opacity:1;transform:translateY(0)}.fd-employee-toast.success{background:#5d971b}.fd-employee-toast.error{background:#e45b66}.fd-employee-toast.warning{background:#96a52f}.fd-employee-toast.info{background:#123d70}.fd-employee-toast span{font-size:8.5px}.fd-employee-toast button{margin-left:auto;border:0;background:transparent;color:#fff}@media(max-width:767.98px){.fd-employees-header{flex-direction:column}.fd-employee-grid{grid-template-columns:1fr}.fd-employee-field.full,.fd-employee-section,.fd-employee-switches{grid-column:auto}.fd-employee-switches{grid-template-columns:1fr}.fd-employee-search{width:100%}.fd-employee-toolbar-spacer{display:none}}@media(max-width:575.98px){.fd-employee-toast{top:72px;left:12px;right:12px;width:auto}.fd-employee-modal-footer{flex-direction:column-reverse}.fd-employee-modal-footer .fd-employee-btn{width:100%}}


/* Separate Add Employee page */
.fd-employee-add-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
.fd-employee-add-header h1{margin:0 0 7px;color:var(--fd-text);font-size:21px;font-weight:700}
.fd-employee-add-header p{margin:0;color:var(--fd-muted);font-size:11px;line-height:1.55}
.fd-employee-add-card{overflow:hidden;border:1px solid var(--fd-border);border-radius:10px;background:#fff;box-shadow:0 4px 14px rgba(31,43,88,.05)}
.fd-employee-add-card-head{padding:15px 17px;display:flex;align-items:center;gap:11px;border-bottom:1px solid var(--fd-border);background:#fbfcfd}
.fd-employee-add-card-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:10px;background:var(--fd-green-soft);color:var(--fd-green-dark);font-size:17px}
.fd-employee-add-card-head strong{display:block;color:var(--fd-navy);font-size:12px}
.fd-employee-add-card-head small{display:block;margin-top:3px;color:var(--fd-muted);font-size:8.5px}
.fd-employee-add-body{padding:18px}
.fd-employee-add-mail-note{grid-column:1/-1;padding:11px 13px;display:flex;align-items:flex-start;gap:9px;border:1px solid #d9e9c1;border-radius:9px;background:#f7fbf0;color:#526b30;font-size:9px;line-height:1.55}
.fd-employee-add-mail-note i{margin-top:1px;color:var(--fd-green-dark);font-size:14px}
.fd-employee-add-footer{padding:13px 17px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid var(--fd-border);background:#fbfcfd}
.fd-employee-add-footer-note{color:#8a96a7;font-size:8.5px;line-height:1.45}
.fd-employee-add-footer-actions{display:flex;gap:8px;margin-left:auto}
.fd-employee-btn[disabled]{cursor:not-allowed;opacity:.68}
.fd-employee-field input:focus,.fd-employee-field select:focus{border-color:#a9cf75;box-shadow:0 0 0 3px rgba(116,184,36,.11)}
.fd-employee-field small{display:block;margin-top:5px;color:#8793a5;font-size:8px;line-height:1.45}
.fd-employee-created-box{display:none;margin-top:14px;padding:13px 14px;border:1px solid #d9e9c1;border-radius:9px;background:#f7fbf0;color:#385d12;font-size:10px;line-height:1.55}
.fd-employee-created-box.show{display:block}
@media(max-width:767.98px){.fd-employee-add-header{flex-direction:column}.fd-employee-add-footer{align-items:stretch;flex-direction:column}.fd-employee-add-footer-actions{width:100%;margin-left:0}.fd-employee-add-footer-actions .fd-employee-btn{flex:1}}

</style>
<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>
    <div class="fieldplx-main-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="fieldplx-main-content">
            <div class="fieldplx-content-wrapper">
                <div class="fd-dashboard">
                    <section class="fd-employee-add-header">
                        <div>
                            <h1>Add Employee</h1>
                            <p>Create a new employee account for <?= employeeAddEscape($tenantName) ?>. A welcome email is sent automatically after the account is created.</p>
                        </div>
                        <a class="fd-employee-btn" href="employees.php"><i class="bi bi-arrow-left"></i>Back to Employees</a>
                    </section>

                    <section class="fd-employee-add-card">
                        <div class="fd-employee-add-card-head">
                            <span class="fd-employee-add-card-icon"><i class="bi bi-person-plus"></i></span>
                            <div>
                                <strong>Employee Details</strong>
                                <small>Enter account, organization and work details.</small>
                            </div>
                        </div>

                        <form id="addEmployeeForm" novalidate>
                            <div class="fd-employee-add-body">
                                <input type="hidden" name="employee_id" value="0">
                                <div class="fd-employee-grid">
                                    <div class="fd-employee-section">Basic Details</div>

                                    <div class="fd-employee-field">
                                        <label for="employeeCode">Employee Code</label>
                                        <input name="employee_code" id="employeeCode" maxlength="80" placeholder="Optional employee code">
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="employeeStatus">Status</label>
                                        <select name="status" id="employeeStatus">
                                            <option value="active" selected>Active</option>
                                            <option value="inactive">Inactive</option>
                                            <option value="invited">Invited</option>
                                            <option value="suspended">Suspended</option>
                                        </select>
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="firstName">First Name *</label>
                                        <input name="first_name" id="firstName" maxlength="120" required autocomplete="given-name">
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="lastName">Last Name</label>
                                        <input name="last_name" id="lastName" maxlength="120" autocomplete="family-name">
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="email">Email Address *</label>
                                        <input type="email" name="email" id="email" maxlength="190" required autocomplete="email">
                                        <small>The welcome email will be sent automatically to this registered address.</small>
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="phone">Phone</label>
                                        <input name="phone" id="phone" maxlength="50" autocomplete="tel">
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="alternatePhone">Alternate Phone</label>
                                        <input name="alternate_phone" id="alternatePhone" maxlength="50">
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="jobTitle">Job Title</label>
                                        <input name="job_title" id="jobTitle" maxlength="120">
                                    </div>

                                    <div class="fd-employee-section">Organization</div>

                                    <div class="fd-employee-field">
                                        <label for="branchId">Branch</label>
                                        <select name="branch_id" id="branchId">
                                            <option value="">No Branch</option>
                                            <?php foreach ($branches as $branch): ?>
                                                <option value="<?= (int) $branch['id'] ?>" <?= $defaultBranchId === (int) $branch['id'] ? 'selected' : '' ?>><?= employeeAddEscape($branch['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="departmentId">Department</label>
                                        <select name="department_id" id="departmentId">
                                            <option value="">No Department</option>
                                        </select>
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="roleId">Role</label>
                                        <select name="role_id" id="roleId">
                                            <option value="">No Role</option>
                                            <?php foreach ($roles as $role): ?>
                                                <option value="<?= (int) $role['id'] ?>"><?= employeeAddEscape($role['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="laborRate">Labour Rate (Per Hour)</label>
                                        <div class="fd-employee-money-input currency-<?= $tenantCurrencyPosition === 'after' ? 'after' : 'before' ?>">
                                            <span class="fd-employee-currency" aria-hidden="true"><?= employeeAddEscape($tenantCurrencySymbol) ?></span>
                                            <input type="number" min="0" step="0.01" name="labor_rate" id="laborRate" inputmode="decimal" placeholder="0.00" aria-describedby="laborRateHelp">
                                        </div>
                                        <small id="laborRateHelp">Hourly labour rate in <?= employeeAddEscape($tenantCurrencyCode !== '' ? $tenantCurrencyCode : 'the tenant currency') ?>, used for job costing and labour calculations.</small>
                                    </div>

                                    <div class="fd-employee-section">Account Access</div>

                                    <div class="fd-employee-field">
                                        <label for="employeePassword">Password *</label>
                                        <input type="password" name="password" id="employeePassword" minlength="8" required autocomplete="new-password">
                                        <small>Minimum 8 characters. The password is not included in the welcome email.</small>
                                    </div>

                                    <div class="fd-employee-field">
                                        <label for="confirmPassword">Confirm Password *</label>
                                        <input type="password" id="confirmPassword" minlength="8" required autocomplete="new-password">
                                    </div>

                                    <div class="fd-employee-switches">
                                        <label class="fd-employee-switch-row">
                                            <span><strong>Bookable</strong><small>Can be scheduled.</small></span>
                                            <span class="fd-employee-switch"><input type="checkbox" name="is_bookable" value="1" checked></span>
                                        </label>
                                        <label class="fd-employee-switch-row">
                                            <span><strong>Field Worker</strong><small>Works on field jobs.</small></span>
                                            <span class="fd-employee-switch"><input type="checkbox" name="is_field_worker" value="1"></span>
                                        </label>
                                        <label class="fd-employee-switch-row">
                                            <span><strong>Tenant Admin</strong><small>Tenant administrator.</small></span>
                                            <span class="fd-employee-switch"><input type="checkbox" name="is_tenant_admin" value="1"></span>
                                        </label>
                                    </div>

                                    <div class="fd-employee-add-mail-note">
                                        <i class="bi bi-envelope-check"></i>
                                        <div>
                                            After the employee record is created, FieldPlx first uses an active SMTP configuration for the selected branch when available, then falls back to the active tenant SMTP configuration. The email contains the employee account details but never includes the password.
                                        </div>
                                    </div>
                                </div>

                                <div class="fd-employee-created-box" id="employeeCreatedBox"></div>
                            </div>

                            <div class="fd-employee-add-footer">
                                <span class="fd-employee-add-footer-note">Fields marked * are required.</span>
                                <div class="fd-employee-add-footer-actions">
                                    <a class="fd-employee-btn" href="employees.php">Cancel</a>
                                    <button class="fd-employee-btn primary" id="createEmployeeButton" type="submit">
                                        <span class="fd-employee-loader"></span>
                                        <i class="bi bi-person-check"></i>
                                        <span>Create Employee</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </main>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <div class="fd-employee-toast info" id="employeesToast">
        <span id="employeesToastMessage">Notification</span>
        <button id="employeesToastClose" type="button"><i class="bi bi-x"></i></button>
    </div>

    <script>
    (function(){
        'use strict';

        var csrf = <?= json_encode($employeesCsrfToken) ?>;
        var departments = <?= json_encode($departments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var form = document.getElementById('addEmployeeForm');
        var branch = document.getElementById('branchId');
        var department = document.getElementById('departmentId');
        var saveButton = document.getElementById('createEmployeeButton');
        var toast = document.getElementById('employeesToast');
        var toastMessage = document.getElementById('employeesToastMessage');
        var createdBox = document.getElementById('employeeCreatedBox');
        var toastTimer = null;
        var created = false;

        function esc(value){
            return String(value == null ? '' : value)
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        }

        function showToast(type,message,duration){
            if(toastTimer) clearTimeout(toastTimer);
            toast.className = 'fd-employee-toast ' + (type || 'info') + ' show';
            toastMessage.textContent = message || 'Notification';
            toastTimer = setTimeout(function(){toast.classList.remove('show');}, duration || 3500);
        }

        function loading(active){
            saveButton.disabled = !!active;
            saveButton.classList.toggle('loading',!!active);
        }

        function parseResponse(response){
            return response.text().then(function(text){
                var data;
                try {
                    data = text.trim() ? JSON.parse(text) : {};
                } catch(error) {
                    throw new Error(text.replace(/<[^>]*>/g,' ').trim() || 'Invalid server response.');
                }
                if(!response.ok || !data.success){
                    throw new Error(data.message || 'Unable to create employee.');
                }
                return data;
            });
        }

        function renderDepartments(){
            var branchId = Number(branch.value || 0);
            var html = '<option value="">No Department</option>';
            departments.forEach(function(item){
                var departmentBranch = Number(item.branch_id || 0);
                if(departmentBranch === 0 || branchId === 0 || departmentBranch === branchId){
                    html += '<option value="' + Number(item.id) + '">' + esc(item.name || '') + '</option>';
                }
            });
            department.innerHTML = html;
        }

        branch.addEventListener('change',renderDepartments);
        renderDepartments();

        form.addEventListener('submit',function(event){
            event.preventDefault();
            if(created) return;

            if(!form.reportValidity()){
                showToast('warning','Complete the required employee fields.');
                return;
            }

            var password = document.getElementById('employeePassword').value;
            var confirmPassword = document.getElementById('confirmPassword').value;
            if(password !== confirmPassword){
                showToast('warning','Password and confirm password do not match.');
                document.getElementById('confirmPassword').focus();
                return;
            }

            var fd = new FormData(form);
            fd.delete('confirm_password');
            fd.append('action','save');
            fd.append('csrf_token',csrf);

            loading(true);

            fetch('api/employees.php',{
                method:'POST',
                body:fd,
                credentials:'same-origin',
                headers:{
                    'X-Requested-With':'XMLHttpRequest',
                    'Accept':'application/json'
                }
            })
            .then(parseResponse)
            .then(function(data){
                created = true;
                form.querySelectorAll('input,select').forEach(function(control){control.disabled = true;});
                saveButton.disabled = true;

                if(data.email_sent === true){
                    createdBox.className = 'fd-employee-created-box show';
                    createdBox.innerHTML = '<strong>Employee created successfully.</strong><br>Welcome email sent to <strong>' + esc(data.email_to || document.getElementById('email').value) + '</strong>.';
                    showToast('success',data.message || 'Employee created and welcome email sent.',5000);
                    setTimeout(function(){window.location.href='employees.php';},1500);
                } else {
                    createdBox.className = 'fd-employee-created-box show';
                    createdBox.style.borderColor = '#f0d7a0';
                    createdBox.style.background = '#fffaf0';
                    createdBox.style.color = '#74551d';
                    createdBox.innerHTML = '<strong>Employee created successfully, but the welcome email was not sent.</strong><br>' + esc(data.email_message || 'Check the tenant SMTP configuration in Master Controls.') + '<br><br><a href="employees.php" class="fd-employee-btn">Back to Employees</a>';
                    showToast('warning',data.message || 'Employee created, but welcome email failed.',7000);
                }
            })
            .catch(function(error){
                showToast('error',error.message,6000);
            })
            .finally(function(){
                if(!created) loading(false);
                else saveButton.classList.remove('loading');
            });
        });

        document.getElementById('employeesToastClose').onclick = function(){toast.classList.remove('show');};
    })();
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
