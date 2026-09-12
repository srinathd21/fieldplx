<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Edit Workflow';
$activePage = 'workflows';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['workflows_csrf_token'])) {
    $_SESSION['workflows_csrf_token'] = bin2hex(random_bytes(32));
}
$workflowsCsrfToken = (string)$_SESSION['workflows_csrf_token'];
$workflowId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($workflowId <= 0) { header('Location: workflows.php'); exit; }

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>Edit Workflow - FieldPlx</title>
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
.fd-employee-stat{min-height:112px;padding:18px 20px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}.fd-employee-stat-row{min-height:72px;display:flex;align-items:center;gap:18px}.fd-employee-stat-icon{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;border-radius:16px;background:#123f73;color:#fff;font-size:25px}.fd-employee-stat-label{display:block;margin-bottom:8px;color:#506784;font-size:13px}.fd-employee-stat-value{display:block;color:#020b16;font-size:31px;line-height:1;font-weight:700}.fd-employees-card{overflow:hidden}.fd-employees-toolbar{padding:13px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--fd-border);background:#fbfcfd}.fd-employee-search{width:270px;position:relative}.fd-employee-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a96a7}.fd-employee-search input,.fd-employee-filter{height:39px;border:1px solid #dde4ec;border-radius:8px;background:#fff;color:#33445f;font-size:10px;outline:0}.fd-employee-search input{width:100%;padding:8px 11px 8px 34px}.fd-employee-filter{min-width:140px;padding:8px 10px}.fd-employee-toolbar-spacer{margin-left:auto}.fd-employee-table-wrap{overflow-x:auto}.fd-employee-table{width:100%;min-width:1180px;border-collapse:collapse;white-space:nowrap}.fd-employee-table th{padding:11px 12px;border-bottom:1px solid var(--fd-border);background:#f8fafc;color:#65738a;font-size:9px;font-weight:600;text-transform:uppercase}.fd-employee-table td{padding:12px;border-bottom:1px solid #f1f3f7;color:#33445f;font-size:9.5px}.fd-employee-person{display:flex;align-items:center;gap:10px}.fd-employee-avatar{width:36px;height:36px;flex:0 0 36px;display:grid;place-items:center;border-radius:50%;background:linear-gradient(135deg,#fff,#e8f3d9);border:1px solid #dce8cf;color:var(--fd-navy);font-size:10px;font-weight:700;overflow:hidden}.fd-employee-avatar img{width:100%;height:100%;object-fit:cover}.fd-employee-person strong,.fd-employee-person small{display:block}.fd-employee-person small{margin-top:2px;color:#8d98a8;font-size:8.5px}.fd-employee-badge{display:inline-flex;padding:5px 7px;border-radius:5px;font-size:8.5px;font-weight:600}.fd-employee-badge.active,.fd-employee-badge.field{color:#5d971b;background:#f0f8e5}.fd-employee-badge.inactive{color:#6f7b90;background:#eef2f6}.fd-employee-badge.invited,.fd-employee-badge.admin{color:#123d70;background:#edf2f7}.fd-employee-badge.suspended{color:#b9444d;background:#fff0f1}.fd-employee-actions-cell{display:flex;gap:5px}.fd-employee-icon{width:29px;height:29px;display:grid;place-items:center;border:0;border-radius:6px;background:transparent;color:#66748b;cursor:pointer}.fd-employee-icon:hover{background:var(--fd-green-soft);color:var(--fd-green-dark)}.fd-employee-icon.danger:hover{background:#fff0f1;color:#b9444d}.fd-employee-empty{padding:28px 18px!important;text-align:center;color:#9aa4b3!important;font-size:10px!important}.fd-employee-pagination{padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--fd-border);font-size:9px;color:#768397}
.fd-employee-modal-backdrop{position:fixed;inset:0;z-index:15000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.46);backdrop-filter:blur(3px)}.fd-employee-modal-backdrop.show{display:flex}.fd-employee-modal{width:min(860px,100%);max-height:calc(100vh - 36px);overflow:auto;border:1px solid #dfe5ec;border-radius:12px;background:#fff;box-shadow:0 24px 65px rgba(0,17,49,.24)}.fd-employee-modal-header{padding:11px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--fd-border);background:#fbfcfd}.fd-employee-modal-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:9px;background:var(--fd-green-soft);color:var(--fd-green-dark)}.fd-employee-modal-heading{flex:1}.fd-employee-modal-heading h3{margin:0;font-size:12px}.fd-employee-modal-heading p{margin:3px 0 0;color:var(--fd-muted);font-size:8.5px}.fd-employee-modal-close{width:30px;height:30px;border:0;border-radius:7px;background:transparent;color:#8490a0}.fd-employee-modal-body{padding:15px}.fd-employee-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.fd-employee-field.full{grid-column:1/-1}.fd-employee-field label{display:block;margin-bottom:6px;color:#42536c;font-size:9px;font-weight:700}.fd-employee-field input,.fd-employee-field select{width:100%;min-height:40px;padding:8px 10px;border:1px solid #dfe5ec;border-radius:8px;background:#fff;color:#263750;font-size:10px;outline:0}.fd-employee-section{grid-column:1/-1;padding:7px 0 2px;border-bottom:1px solid #eef2f5;color:#31425b;font-size:9px;font-weight:700;text-transform:uppercase}.fd-employee-switches{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.fd-employee-switch-row{padding:10px;border:1px solid var(--fd-border);border-radius:9px;background:#fbfcfd;display:flex;align-items:center;justify-content:space-between}.fd-employee-switch-row strong,.fd-employee-switch-row small{display:block}.fd-employee-switch-row strong{font-size:9.5px}.fd-employee-switch-row small{margin-top:2px;color:#8a96a7;font-size:8px}.fd-employee-switch input{width:15px;height:15px;accent-color:var(--fd-green)}.fd-employee-modal-footer{padding:12px 15px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--fd-border);background:#fbfcfd}.fd-employee-confirm{width:min(440px,100%)}
.fd-employee-toast{width:min(290px,calc(100vw - 24px));position:fixed;top:82px;right:16px;z-index:25000;padding:8px 9px;display:flex;align-items:center;gap:7px;border-radius:7px;color:#fff;opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s ease;box-shadow:0 10px 26px rgba(0,17,49,.18)}.fd-employee-toast.show{opacity:1;transform:translateY(0)}.fd-employee-toast.success{background:#5d971b}.fd-employee-toast.error{background:#e45b66}.fd-employee-toast.warning{background:#96a52f}.fd-employee-toast.info{background:#123d70}.fd-employee-toast span{font-size:8.5px}.fd-employee-toast button{margin-left:auto;border:0;background:transparent;color:#fff}@media(max-width:767.98px){.fd-employees-header{flex-direction:column}.fd-employee-grid{grid-template-columns:1fr}.fd-employee-field.full,.fd-employee-section,.fd-employee-switches{grid-column:auto}.fd-employee-switches{grid-template-columns:1fr}.fd-employee-search{width:100%}.fd-employee-toolbar-spacer{display:none}}@media(max-width:575.98px){.fd-employee-toast{top:72px;left:12px;right:12px;width:auto}.fd-employee-modal-footer{flex-direction:column-reverse}.fd-employee-modal-footer .fd-employee-btn{width:100%}}

/* ==========================================================
   Teams page - canonical tenant template
   ========================================================== */
.fd-teams-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}
.fd-teams-title{
  margin:0 0 7px;
  color:var(--fd-text);
  font-size:21px;
  line-height:1.2;
  font-weight:700;
}
.fd-teams-subtitle{
  margin:0;
  max-width:780px;
  color:var(--fd-muted);
  font-size:11px;
  line-height:1.55;
}
.fd-teams-actions{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}
.fd-team-button{
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
.fd-team-button:hover{
  border-color:#cfe3ae;
  color:var(--fd-green-dark);
  background:#f9fcf4;
}
.fd-team-button.primary{
  border-color:var(--fd-green);
  color:#fff;
  background:linear-gradient(90deg,#7fc92d,#68aa1d);
  box-shadow:0 7px 16px rgba(104,170,29,.18);
}
.fd-team-button.primary:hover{
  color:#fff;
  background:linear-gradient(90deg,#74b824,#5d971b);
}
.fd-team-button.danger{
  border-color:#ffd5d9;
  color:#b9444d;
  background:#fff;
}
.fd-team-button:disabled{
  opacity:.58;
  cursor:not-allowed;
}
.fd-team-loader{
  width:13px;
  height:13px;
  display:none;
  border:2px dotted currentColor;
  border-radius:50%;
  animation:fdTeamSpin .75s linear infinite;
}
.fd-team-button.loading .fd-team-loader{display:inline-block}
@keyframes fdTeamSpin{to{transform:rotate(360deg)}}

.fd-teams-summary{margin-bottom:16px}
.fd-team-stat-card{
  min-height:112px;
  padding:18px 20px;
  border:1px solid #dfe6ef;
  border-radius:12px;
  background:#fff;
  box-shadow:0 3px 12px rgba(24,45,76,.035);
}
.fd-team-stat-row{
  min-height:72px;
  display:flex;
  align-items:center;
  gap:18px;
}
.fd-team-stat-icon{
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
.fd-team-stat-label{
  display:block;
  margin-bottom:8px;
  color:#506784;
  font-size:13px;
}
.fd-team-stat-value{
  display:block;
  color:#020b16;
  font-size:31px;
  line-height:1;
  font-weight:700;
}

.fd-teams-card{overflow:hidden}
.fd-teams-toolbar{
  padding:13px 14px;
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}
.fd-team-search{width:270px;position:relative}
.fd-team-search i{
  position:absolute;
  left:12px;
  top:50%;
  transform:translateY(-50%);
  color:#8a96a7;
  font-size:13px;
}
.fd-team-search input,
.fd-team-filter{
  height:39px;
  border:1px solid #dde4ec;
  border-radius:8px;
  outline:0;
  color:#33445f;
  background:#fff;
  font-size:10px;
}
.fd-team-search input{
  width:100%;
  padding:8px 11px 8px 34px;
}
.fd-team-filter{
  min-width:140px;
  padding:8px 10px;
}
.fd-team-search input:focus,
.fd-team-filter:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}
.fd-team-toolbar-spacer{margin-left:auto}

.fd-team-table-wrap{
  width:100%;
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:thin;
  scrollbar-color:#9aa0a6 transparent;
}
.fd-team-table-wrap::-webkit-scrollbar{height:3px!important}
.fd-team-table-wrap::-webkit-scrollbar-track{background:transparent!important}
.fd-team-table-wrap::-webkit-scrollbar-thumb{
  min-width:20px;
  border-radius:999px!important;
  background:#9aa0a6!important;
}
.fd-team-table-wrap::-webkit-scrollbar-button{
  width:0!important;
  height:0!important;
  display:none!important;
}
.fd-team-table{
  width:100%;
  min-width:1080px;
  margin:0;
  border-collapse:collapse;
  white-space:nowrap;
}
.fd-team-table th{
  padding:11px 12px;
  border-bottom:1px solid var(--fd-border);
  color:#65738a;
  background:#f8fafc;
  font-size:9px;
  font-weight:600;
  text-transform:uppercase;
}
.fd-team-table td{
  padding:12px;
  border-bottom:1px solid #f1f3f7;
  color:#33445f;
  font-size:9.5px;
  vertical-align:middle;
}
.fd-team-table tbody tr:hover{background:#fbfcfa}

.fd-team-name{
  display:flex;
  align-items:center;
  gap:10px;
}
.fd-team-name-icon{
  width:36px;
  height:36px;
  flex:0 0 36px;
  display:grid;
  place-items:center;
  border-radius:10px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
  font-size:15px;
}
.fd-team-name strong,
.fd-team-name small{display:block}
.fd-team-name strong{
  color:var(--fd-text);
  font-size:10.5px;
}
.fd-team-name small{
  margin-top:2px;
  color:#8d98a8;
  font-size:8.5px;
}
.fd-team-badge{
  display:inline-flex;
  align-items:center;
  padding:5px 7px;
  border-radius:5px;
  font-size:8.5px;
  font-weight:600;
}
.fd-team-badge.active{
  color:#5d971b;
  background:#f0f8e5;
}
.fd-team-badge.inactive{
  color:#6f7b90;
  background:#eef2f6;
}
.fd-team-badge.primary{
  color:#123d70;
  background:#edf2f7;
}
.fd-team-actions-cell{
  display:flex;
  align-items:center;
  gap:5px;
}
.fd-team-icon-button{
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
.fd-team-icon-button:hover{
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}
.fd-team-icon-button.danger:hover{
  color:#b9444d;
  background:#fff0f1;
}
.fd-team-empty{
  padding:28px 18px!important;
  text-align:center;
  color:#9aa4b3!important;
  font-size:10px!important;
}
.fd-team-pagination{
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
.fd-team-pagination-actions{display:flex;gap:5px}

.fd-team-modal-backdrop{
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
.fd-team-modal-backdrop.show{display:flex}
.fd-team-modal{
  width:min(860px,100%);
  max-height:calc(100vh - 36px);
  overflow:auto;
  border:1px solid #dfe5ec;
  border-radius:12px;
  background:#fff;
  box-shadow:0 24px 65px rgba(0,17,49,.24);
}
.fd-team-modal-header{
  min-height:58px;
  padding:11px 14px;
  display:flex;
  align-items:center;
  gap:10px;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}
.fd-team-modal-icon{
  width:34px;
  height:34px;
  display:grid;
  place-items:center;
  border-radius:9px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
  font-size:15px;
}
.fd-team-modal-heading{min-width:0;flex:1}
.fd-team-modal-heading h3{
  margin:0;
  color:var(--fd-text);
  font-size:12px;
  font-weight:700;
}
.fd-team-modal-heading p{
  margin:3px 0 0;
  color:var(--fd-muted);
  font-size:8.5px;
}
.fd-team-modal-close{
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
.fd-team-modal-body{padding:15px}
.fd-team-form-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:13px;
}
.fd-team-field.full{grid-column:1/-1}
.fd-team-field label{
  margin-bottom:6px;
  display:block;
  color:#42536c;
  font-size:9px;
  font-weight:700;
}
.fd-team-field input,
.fd-team-field select,
.fd-team-field textarea{
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
.fd-team-field textarea{
  min-height:76px;
  resize:vertical;
}
.fd-team-field input:focus,
.fd-team-field select:focus,
.fd-team-field textarea:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}
.fd-team-section-title{
  grid-column:1/-1;
  margin-top:3px;
  padding:8px 0 2px;
  border-bottom:1px solid #eef2f5;
  color:#31425b;
  font-size:9px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.04em;
}
.fd-team-members-box{
  grid-column:1/-1;
  border:1px solid var(--fd-border);
  border-radius:9px;
  overflow:hidden;
}
.fd-team-members-head{
  padding:10px 11px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  background:#f9fbfc;
  border-bottom:1px solid var(--fd-border);
}
.fd-team-members-head strong{
  color:#31425b;
  font-size:10px;
}
.fd-team-members-list{
  max-height:260px;
  overflow:auto;
}
.fd-team-member-row{
  padding:9px 11px;
  display:grid;
  grid-template-columns:24px minmax(0,1fr) 170px 110px;
  align-items:center;
  gap:8px;
  border-bottom:1px solid #f0f3f6;
}
.fd-team-member-row:last-child{border-bottom:0}
.fd-team-member-row input[type="checkbox"]{
  width:14px;
  height:14px;
  accent-color:var(--fd-green);
}
.fd-team-member-copy strong,
.fd-team-member-copy small{display:block}
.fd-team-member-copy strong{
  color:#34465f;
  font-size:9.5px;
}
.fd-team-member-copy small{
  margin-top:2px;
  color:#8a96a7;
  font-size:8px;
}
.fd-team-member-row input[type="text"]{
  width:100%;
  height:34px;
  padding:6px 8px;
  border:1px solid #dfe5ec;
  border-radius:7px;
  font-size:9px;
}
.fd-team-primary-label{
  display:flex;
  align-items:center;
  gap:6px;
  color:#607086;
  font-size:8.5px;
}
.fd-team-modal-footer{
  padding:12px 15px;
  display:flex;
  justify-content:flex-end;
  gap:8px;
  border-top:1px solid var(--fd-border);
  background:#fbfcfd;
}
.fd-team-confirm{width:min(440px,100%)}
.fd-team-confirm .fd-team-modal-body{
  padding:18px 16px;
  color:#56667c;
  font-size:10px;
  line-height:1.6;
}

.fd-team-toast{
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
.fd-team-toast.show{
  opacity:1;
  transform:translateY(0);
}
.fd-team-toast.success{background:#5d971b}
.fd-team-toast.error{background:#e45b66}
.fd-team-toast.warning{background:#96a52f}
.fd-team-toast.info{background:#123d70}
.fd-team-toast-message{
  min-width:0;
  flex:1;
  font-size:8.5px;
  font-weight:600;
}
.fd-team-toast-close{
  width:19px;
  height:19px;
  padding:0;
  border:0;
  color:#fff;
  background:transparent;
  cursor:pointer;
}

@media(max-width:767.98px){
  .fd-teams-header{flex-direction:column}
  .fd-teams-actions{justify-content:flex-end}
  .fd-team-form-grid{grid-template-columns:1fr}
  .fd-team-field.full,
  .fd-team-section-title,
  .fd-team-members-box{grid-column:auto}
  .fd-team-search{width:100%}
  .fd-team-toolbar-spacer{display:none}
  .fd-team-member-row{
    grid-template-columns:24px minmax(0,1fr);
  }
  .fd-team-member-row input[type="text"],
  .fd-team-primary-label{
    grid-column:2;
  }
}
@media(max-width:575.98px){
  .fd-team-stat-card{
    min-height:102px;
    padding:15px 17px;
  }
  .fd-team-stat-icon{
    width:54px;
    height:54px;
    flex-basis:54px;
  }
  .fd-team-stat-value{font-size:29px}
  .fd-team-filter{flex:1}
  .fd-team-modal-footer{flex-direction:column-reverse}
  .fd-team-modal-footer .fd-team-button{width:100%}
  .fd-team-toast{
    top:72px;
    left:12px;
    right:12px;
    width:auto;
  }
}

/* ==========================================================
   Workflows page - canonical tenant template
   ========================================================== */
.fd-workflows-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
.fd-workflows-title{margin:0 0 7px;color:var(--fd-text);font-size:21px;line-height:1.2;font-weight:700}
.fd-workflows-subtitle{margin:0;max-width:850px;color:var(--fd-muted);font-size:11px;line-height:1.55}
.fd-workflows-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.fd-wf-btn{min-height:39px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;color:#43546c;background:#fff;box-shadow:0 4px 12px rgba(31,43,88,.04);font-size:10px;font-weight:700;cursor:pointer}
.fd-wf-btn:hover{border-color:#cfe3ae;color:var(--fd-green-dark);background:#f9fcf4}
.fd-wf-btn.primary{border-color:var(--fd-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);box-shadow:0 7px 16px rgba(104,170,29,.18)}
.fd-wf-btn.primary:hover{color:#fff;background:linear-gradient(90deg,#74b824,#5d971b)}
.fd-wf-btn.danger{border-color:#ffd5d9;color:#b9444d;background:#fff}
.fd-wf-btn:disabled{opacity:.58;cursor:not-allowed}
.fd-wf-loader{width:13px;height:13px;display:none;border:2px dotted currentColor;border-radius:50%;animation:fdWfSpin .75s linear infinite}
.fd-wf-btn.loading .fd-wf-loader{display:inline-block}
@keyframes fdWfSpin{to{transform:rotate(360deg)}}

.fd-wf-summary{margin-bottom:16px}
.fd-wf-stat{min-height:112px;padding:18px 20px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}
.fd-wf-stat-row{min-height:72px;display:flex;align-items:center;gap:18px}
.fd-wf-stat-icon{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;border-radius:16px;color:#fff;background:#123f73;font-size:25px}
.fd-wf-stat-label{display:block;margin-bottom:8px;color:#506784;font-size:13px}
.fd-wf-stat-value{display:block;color:#020b16;font-size:31px;line-height:1;font-weight:700}

.fd-wf-tabs{margin-bottom:13px;padding:5px;display:flex;gap:6px;overflow-x:auto;border:1px solid var(--fd-border);border-radius:10px;background:#fff;scrollbar-width:none}
.fd-wf-tabs::-webkit-scrollbar{display:none}
.fd-wf-tab{min-height:38px;padding:0 13px;display:inline-flex;align-items:center;gap:7px;flex:0 0 auto;border:0;border-radius:8px;color:#596981;background:transparent;font-size:10px;font-weight:700;cursor:pointer}
.fd-wf-tab.active{color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d)}
.fd-wf-panel{display:none}.fd-wf-panel.active{display:block}

.fd-wf-card{overflow:hidden}
.fd-wf-toolbar{padding:13px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--fd-border);background:#fbfcfd}
.fd-wf-search{width:270px;position:relative}
.fd-wf-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a96a7;font-size:13px}
.fd-wf-search input,.fd-wf-filter{height:39px;border:1px solid #dde4ec;border-radius:8px;outline:0;color:#33445f;background:#fff;font-size:10px}
.fd-wf-search input{width:100%;padding:8px 11px 8px 34px}
.fd-wf-filter{min-width:145px;padding:8px 10px}
.fd-wf-toolbar-spacer{margin-left:auto}

.fd-wf-table-wrap{width:100%;overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;scrollbar-color:#9aa0a6 transparent}
.fd-wf-table-wrap::-webkit-scrollbar{height:3px!important}
.fd-wf-table-wrap::-webkit-scrollbar-track{background:transparent!important}
.fd-wf-table-wrap::-webkit-scrollbar-thumb{min-width:20px;border-radius:999px!important;background:#9aa0a6!important}
.fd-wf-table-wrap::-webkit-scrollbar-button{width:0!important;height:0!important;display:none!important}
.fd-wf-table{width:100%;min-width:1120px;margin:0;border-collapse:collapse;white-space:nowrap}
.fd-wf-table th{padding:11px 12px;border-bottom:1px solid var(--fd-border);color:#65738a;background:#f8fafc;font-size:9px;font-weight:600;text-transform:uppercase}
.fd-wf-table td{padding:12px;border-bottom:1px solid #f1f3f7;color:#33445f;font-size:9.5px;vertical-align:middle}
.fd-wf-table tbody tr:hover{background:#fbfcfa}
.fd-wf-name{display:flex;align-items:center;gap:10px}
.fd-wf-name-icon{width:36px;height:36px;flex:0 0 36px;display:grid;place-items:center;border-radius:10px;color:var(--fd-green-dark);background:var(--fd-green-soft);font-size:15px}
.fd-wf-name strong,.fd-wf-name small{display:block}
.fd-wf-name strong{color:var(--fd-text);font-size:10.5px}
.fd-wf-name small{margin-top:2px;color:#8d98a8;font-size:8.5px}
.fd-wf-badge{display:inline-flex;align-items:center;padding:5px 7px;border-radius:5px;font-size:8.5px;font-weight:600}
.fd-wf-badge.active{color:#5d971b;background:#f0f8e5}
.fd-wf-badge.inactive,.fd-wf-badge.draft{color:#6f7b90;background:#eef2f6}
.fd-wf-badge.archived{color:#8a5e10;background:#fff7df}
.fd-wf-badge.default{color:#123d70;background:#edf2f7}
.fd-wf-actions-cell{display:flex;align-items:center;gap:5px}
.fd-wf-icon-btn{width:29px;height:29px;padding:0;display:grid;place-items:center;border:0;border-radius:6px;color:#66748b;background:transparent;cursor:pointer;font-size:12px}
.fd-wf-icon-btn:hover{color:var(--fd-green-dark);background:var(--fd-green-soft)}
.fd-wf-icon-btn.danger:hover{color:#b9444d;background:#fff0f1}
.fd-wf-empty{padding:28px 18px!important;text-align:center;color:#9aa4b3!important;font-size:10px!important}
.fd-wf-pagination{min-height:49px;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid var(--fd-border);color:#768397;background:#fff;font-size:9px}
.fd-wf-pagination-actions{display:flex;gap:5px}

.fd-wf-modal-backdrop{position:fixed;inset:0;z-index:15000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.46);backdrop-filter:blur(3px)}
.fd-wf-modal-backdrop.show{display:flex}
.fd-wf-modal{width:min(980px,100%);max-height:calc(100vh - 30px);overflow:auto;border:1px solid #dfe5ec;border-radius:12px;background:#fff;box-shadow:0 24px 65px rgba(0,17,49,.24)}
.fd-wf-modal-header{min-height:58px;padding:11px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--fd-border);background:#fbfcfd}
.fd-wf-modal-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:9px;color:var(--fd-green-dark);background:var(--fd-green-soft);font-size:15px}
.fd-wf-modal-heading{min-width:0;flex:1}
.fd-wf-modal-heading h3{margin:0;color:var(--fd-text);font-size:12px;font-weight:700}
.fd-wf-modal-heading p{margin:3px 0 0;color:var(--fd-muted);font-size:8.5px}
.fd-wf-modal-close{width:30px;height:30px;display:grid;place-items:center;border:0;border-radius:7px;color:#8490a0;background:transparent;cursor:pointer}
.fd-wf-modal-body{padding:15px}
.fd-wf-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
.fd-wf-field.full{grid-column:1/-1}
.fd-wf-field label{margin-bottom:6px;display:block;color:#42536c;font-size:9px;font-weight:700}
.fd-wf-field input,.fd-wf-field select,.fd-wf-field textarea{width:100%;min-height:40px;padding:8px 10px;border:1px solid #dfe5ec;border-radius:8px;outline:0;color:#263750;background:#fff;font-size:10px}
.fd-wf-field textarea{min-height:72px;resize:vertical}
.fd-wf-field input:focus,.fd-wf-field select:focus,.fd-wf-field textarea:focus{border-color:#a9cf75;box-shadow:0 0 0 3px rgba(116,184,36,.11)}
.fd-wf-section-title{grid-column:1/-1;margin-top:4px;padding:8px 0 3px;border-bottom:1px solid #eef2f5;color:#31425b;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}

.fd-wf-steps{grid-column:1/-1;display:grid;gap:9px}
.fd-wf-step{padding:11px;border:1px solid var(--fd-border);border-radius:9px;background:#fbfcfd}
.fd-wf-step-top{display:grid;grid-template-columns:52px minmax(0,1.1fr) minmax(0,.8fr) 34px;gap:8px;align-items:end}
.fd-wf-step-number{height:40px;display:grid;place-items:center;border-radius:8px;color:#fff;background:#123f73;font-size:11px;font-weight:700}
.fd-wf-step-grid{margin-top:9px;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.fd-wf-check-grid{margin-top:9px;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px}
.fd-wf-check{min-height:38px;padding:7px 8px;display:flex;align-items:center;gap:6px;border:1px solid #e3e8ed;border-radius:7px;color:#5c6d82;background:#fff;font-size:8.5px}
.fd-wf-check input{width:14px;height:14px;accent-color:var(--fd-green)}
.fd-wf-role-box{margin-top:9px;padding:8px;border:1px solid #e3e8ed;border-radius:8px;background:#fff}
.fd-wf-role-title{margin-bottom:7px;color:#526278;font-size:8.5px;font-weight:700}
.fd-wf-role-list{display:flex;flex-wrap:wrap;gap:6px}
.fd-wf-role-item{padding:5px 7px;display:inline-flex;align-items:center;gap:5px;border:1px solid #e3e8ed;border-radius:7px;color:#607086;font-size:8px}
.fd-wf-role-item input{width:13px;height:13px;accent-color:var(--fd-green)}
.fd-wf-step-remove{width:34px;height:40px;border:0;border-radius:8px;color:#b9444d;background:#fff0f1;cursor:pointer}
.fd-wf-modal-footer{padding:12px 15px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--fd-border);background:#fbfcfd}
.fd-wf-confirm{width:min(440px,100%)}
.fd-wf-confirm .fd-wf-modal-body{padding:18px 16px;color:#56667c;font-size:10px;line-height:1.6}

.fd-wf-mapping-grid{padding:14px;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:10px;align-items:end}
.fd-wf-map-list{padding:0 14px 14px}

.fd-wf-toast{width:min(290px,calc(100vw - 24px));position:fixed;top:82px;right:16px;z-index:25000;padding:8px 9px;display:flex;align-items:center;gap:7px;border-radius:7px;color:#fff;box-shadow:0 10px 26px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s ease}
.fd-wf-toast.show{opacity:1;transform:translateY(0)}
.fd-wf-toast.success{background:#5d971b}.fd-wf-toast.error{background:#e45b66}.fd-wf-toast.warning{background:#96a52f}.fd-wf-toast.info{background:#123d70}
.fd-wf-toast-message{min-width:0;flex:1;font-size:8.5px;font-weight:600}
.fd-wf-toast-close{width:19px;height:19px;padding:0;border:0;color:#fff;background:transparent;cursor:pointer}

@media(max-width:900px){
  .fd-wf-step-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .fd-wf-check-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .fd-wf-mapping-grid{grid-template-columns:1fr}
}
@media(max-width:767.98px){
  .fd-workflows-header{flex-direction:column}
  .fd-workflows-actions{justify-content:flex-end}
  .fd-wf-form-grid{grid-template-columns:1fr}
  .fd-wf-field.full,.fd-wf-section-title,.fd-wf-steps{grid-column:auto}
  .fd-wf-search{width:100%}
  .fd-wf-toolbar-spacer{display:none}
  .fd-wf-step-top{grid-template-columns:44px 1fr 34px}
  .fd-wf-step-top .step-code-wrap{grid-column:2}
  .fd-wf-step-grid{grid-template-columns:1fr}
  .fd-wf-check-grid{grid-template-columns:1fr}
}
@media(max-width:575.98px){
  .fd-wf-stat{min-height:102px;padding:15px 17px}
  .fd-wf-stat-icon{width:54px;height:54px;flex-basis:54px}
  .fd-wf-stat-value{font-size:29px}
  .fd-wf-filter{flex:1}
  .fd-wf-modal-footer{flex-direction:column-reverse}
  .fd-wf-modal-footer .fd-wf-btn{width:100%}
  .fd-wf-toast{top:72px;left:12px;right:12px;width:auto}
}

/* Workflow Builder v2 */
a,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}
.wf-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.wf-head h1{margin:0 0 6px;color:var(--fd-text);font-size:21px;font-weight:700}.wf-head p{margin:0;max-width:880px;color:var(--fd-muted);font-size:11px;line-height:1.55}.wf-actions{display:flex;gap:8px;flex-wrap:wrap}.wf-btn{min-height:39px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;background:#fff;color:#43546c;font-size:10px;font-weight:700;cursor:pointer}.wf-btn:hover{border-color:#cfe3ae;color:var(--fd-green-dark);background:#f9fcf4}.wf-btn.primary{border-color:var(--fd-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d)}.wf-btn.danger{border-color:#ffd5d9;color:#b9444d;background:#fff}.wf-btn:disabled{opacity:.55;cursor:not-allowed}.wf-loader{width:13px;height:13px;display:none;border:2px dotted currentColor;border-radius:50%;animation:wfSpin .75s linear infinite}.wf-btn.loading .wf-loader{display:inline-block}@keyframes wfSpin{to{transform:rotate(360deg)}}
.wf-stat{min-height:108px;padding:17px 19px;border:1px solid #dfe6ef;border-radius:12px;background:#fff}.wf-stat-row{height:100%;display:flex;align-items:center;gap:16px}.wf-stat-icon{width:54px;height:54px;display:grid;place-items:center;flex:0 0 54px;border-radius:15px;color:#fff;background:#123f73;font-size:23px}.wf-stat-label{display:block;color:#506784;font-size:12px}.wf-stat-value{display:block;margin-top:7px;color:#020b16;font-size:29px;line-height:1;font-weight:700}
.wf-card{overflow:hidden;border:1px solid var(--fd-border);border-radius:10px;background:#fff;box-shadow:0 4px 14px rgba(31,43,88,.05)}.wf-toolbar{padding:13px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--fd-border);background:#fbfcfd}.wf-search{position:relative;width:280px}.wf-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a96a7}.wf-search input,.wf-filter{height:39px;border:1px solid #dde4ec;border-radius:8px;background:#fff;color:#33445f;font-size:10px;outline:0}.wf-search input{width:100%;padding:8px 11px 8px 34px}.wf-filter{min-width:150px;padding:8px 10px}.wf-spacer{margin-left:auto}.wf-table-wrap{width:100%;overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;scrollbar-color:#9aa0a6 transparent}.wf-table-wrap::-webkit-scrollbar{height:3px!important}.wf-table-wrap::-webkit-scrollbar-thumb{border-radius:999px;background:#9aa0a6}.wf-table-wrap::-webkit-scrollbar-button{display:none!important;width:0!important;height:0!important}.wf-table{width:100%;min-width:1120px;border-collapse:collapse;white-space:nowrap}.wf-table th{padding:11px 12px;border-bottom:1px solid var(--fd-border);background:#f8fafc;color:#65738a;font-size:9px;font-weight:600;text-transform:uppercase}.wf-table td{padding:12px;border-bottom:1px solid #f1f3f7;color:#33445f;font-size:9.5px;vertical-align:middle}.wf-table tbody tr:hover{background:#fbfcfa}.wf-badge{display:inline-flex;align-items:center;padding:5px 7px;border-radius:5px;font-size:8.5px;font-weight:600}.wf-badge.active{color:#5d971b;background:#f0f8e5}.wf-badge.draft,.wf-badge.inactive{color:#6f7b90;background:#eef2f6}.wf-badge.archived{color:#9a6713;background:#fff6db}.wf-badge.default{color:#123d70;background:#edf2f7}.wf-icon-btn{width:29px;height:29px;padding:0;display:inline-grid;place-items:center;border:0;border-radius:6px;background:transparent;color:#66748b;cursor:pointer}.wf-icon-btn:hover{color:var(--fd-green-dark);background:var(--fd-green-soft)}.wf-icon-btn.danger:hover{color:#b9444d;background:#fff0f1}.wf-empty{padding:30px 18px!important;text-align:center;color:#9aa4b3!important}.wf-pagination{padding:10px 14px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--fd-border);color:#768397;font-size:9px}
.wf-toast{width:min(300px,calc(100vw - 24px));position:fixed;top:82px;right:16px;z-index:25000;padding:9px 10px;display:flex;align-items:center;gap:8px;border-radius:7px;color:#fff;box-shadow:0 10px 26px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s}.wf-toast.show{opacity:1;transform:translateY(0)}.wf-toast.success{background:#5d971b}.wf-toast.error{background:#e45b66}.wf-toast.warning{background:#a07a1e}.wf-toast.info{background:#123d70}.wf-toast span{min-width:0;flex:1;font-size:8.5px;font-weight:600}.wf-toast button{border:0;color:#fff;background:transparent}
@media(max-width:767.98px){.wf-head{flex-direction:column}.wf-search{width:100%}.wf-spacer{display:none}}@media(max-width:575.98px){.wf-toast{top:72px;left:12px;right:12px;width:auto}}

.wfb-layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:14px;align-items:start}.wfb-main{display:grid;gap:14px}.wfb-side{position:sticky;top:84px;display:grid;gap:12px}.wfb-section{padding:15px}.wfb-section-title{margin:0 0 4px;color:var(--fd-text);font-size:12px;font-weight:700}.wfb-section-sub{margin:0 0 13px;color:var(--fd-muted);font-size:8.5px}.wfb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.wfb-field.full{grid-column:1/-1}.wfb-field label{display:block;margin-bottom:6px;color:#42536c;font-size:9px;font-weight:700}.wfb-field input,.wfb-field select,.wfb-field textarea{width:100%;min-height:40px;padding:8px 10px;border:1px solid #dfe5ec;border-radius:8px;background:#fff;color:#263750;font-size:10px;outline:0}.wfb-field textarea{min-height:75px;resize:vertical}.wfb-field input:focus,.wfb-field select:focus,.wfb-field textarea:focus{border-color:#a9cf75;box-shadow:0 0 0 3px rgba(116,184,36,.11)}
.wfb-service-note{margin-top:8px;padding:9px 10px;border:1px solid #dce8cf;border-radius:8px;background:#f7fced;color:#5c6d42;font-size:8.5px;line-height:1.5}
.wfb-steps{display:grid;gap:11px}.wfb-step{border:1px solid #dfe5ec;border-radius:10px;background:#fff;overflow:hidden}.wfb-step-head{padding:10px 11px;display:grid;grid-template-columns:38px minmax(0,1fr) auto;gap:9px;align-items:center;background:#f8fafc;border-bottom:1px solid #e9edf2}.wfb-step-num{width:38px;height:38px;display:grid;place-items:center;border-radius:9px;background:#123f73;color:#fff;font-size:11px;font-weight:700}.wfb-step-title input{width:100%;height:36px;padding:7px 9px;border:1px solid #dfe5ec;border-radius:7px;color:#233650;font-size:10px;font-weight:700}.wfb-step-actions{display:flex;gap:4px}.wfb-step-body{padding:11px}.wfb-step-meta{display:grid;grid-template-columns:minmax(0,1fr) 125px;gap:8px;margin-bottom:10px}.wfb-step-meta textarea{width:100%;min-height:54px;padding:7px 9px;border:1px solid #dfe5ec;border-radius:7px;font-size:9px}.wfb-required{min-height:38px;padding:7px 9px;display:flex;align-items:center;gap:6px;border:1px solid #e3e8ed;border-radius:7px;color:#5c6d82;font-size:8.5px}.wfb-required input,.wfb-check input{width:14px;height:14px;accent-color:var(--fd-green)}
.wfb-fields{display:grid;gap:8px}.wfb-fields-title{display:flex;align-items:center;justify-content:space-between;gap:8px}.wfb-fields-title strong{font-size:9.5px;color:#34465f}.wfb-field-card{padding:9px;border:1px solid #e3e8ed;border-radius:8px;background:#fbfcfd}.wfb-field-top{display:grid;grid-template-columns:28px minmax(0,1fr) 155px auto;gap:7px;align-items:center}.wfb-drag{width:28px;height:34px;display:grid;place-items:center;color:#9aa4b3}.wfb-field-label,.wfb-field-type{height:35px;padding:6px 8px;border:1px solid #dfe5ec;border-radius:7px;background:#fff;color:#34465f;font-size:9px}.wfb-field-options{margin-top:8px;padding-top:8px;border-top:1px dashed #dde3ea;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}.wfb-mini label{display:block;margin-bottom:4px;color:#6a778a;font-size:7.8px;font-weight:600}.wfb-mini input,.wfb-mini select,.wfb-mini textarea{width:100%;min-height:32px;padding:5px 7px;border:1px solid #dfe5ec;border-radius:6px;background:#fff;font-size:8.5px}.wfb-mini.full{grid-column:1/-1}.wfb-check{min-height:32px;padding:5px 7px;display:flex;align-items:center;gap:6px;border:1px solid #e3e8ed;border-radius:6px;background:#fff;color:#66748b;font-size:8px}.wfb-option-list{display:grid;gap:5px}.wfb-option-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) 28px;gap:5px}.wfb-option-row input{height:31px;padding:5px 7px;border:1px solid #dfe5ec;border-radius:6px;font-size:8.5px}.wfb-side-list{display:grid;gap:7px}.wfb-side-row{padding:8px 9px;display:flex;align-items:flex-start;gap:8px;border:1px solid #e6eaf0;border-radius:8px;background:#fbfcfd}.wfb-side-row span:first-child{width:24px;height:24px;display:grid;place-items:center;flex:0 0 24px;border-radius:6px;background:#edf4e4;color:var(--fd-green-dark);font-size:9px}.wfb-side-row strong{display:block;color:#34465f;font-size:8.5px}.wfb-side-row small{display:block;margin-top:2px;color:#8a96a7;font-size:7.5px;line-height:1.35}.wfb-footer{padding:12px 15px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--fd-border);background:#fbfcfd}
@media(max-width:1100px){.wfb-layout{grid-template-columns:1fr}.wfb-side{position:static;grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:767.98px){.wfb-grid,.wfb-step-meta,.wfb-field-options{grid-template-columns:1fr}.wfb-field.full,.wfb-mini.full{grid-column:auto}.wfb-field-top{grid-template-columns:28px 1fr auto}.wfb-field-type{grid-column:2}.wfb-side{grid-template-columns:1fr}}
</style></head><body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fieldplx-main-content"><div class="fieldplx-content-wrapper"><div class="fd-dashboard"><section class="wf-head"><div><h1>Edit Workflow</h1><p>Select the service first, then build the exact prebuilt technician process for that service.</p></div><div class="wf-actions"><a class="wf-btn" href="workflows.php"><i class="bi bi-arrow-left"></i> Back to Workflows</a></div></section>
<form id="builderForm"><input type="hidden" name="workflow_id" id="workflowId" value="<?= (int)$workflowId ?>"><div class="wfb-layout"><div class="wfb-main"><section class="wf-card wfb-section"><h2 class="wfb-section-title">Service & Workflow</h2><p class="wfb-section-sub">The selected service determines which prebuilt work process technicians receive.</p><div class="wfb-grid"><div class="wfb-field"><label>Service *</label><select name="service_id" id="serviceId" required><option value="">Select Service</option></select><div class="wfb-service-note">Example: AC Service → AC Standard Service workflow → technician follows the configured process below.</div></div><div class="wfb-field"><label>Workflow Name *</label><input name="name" id="workflowName" maxlength="190" required placeholder="AC Standard Service"></div><div class="wfb-field"><label>Workflow Code</label><input name="code" id="workflowCode" maxlength="100" placeholder="AC_STANDARD_SERVICE"></div><div class="wfb-field"><label>Status</label><select name="status" id="workflowStatus"><option value="draft">Draft</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select></div><div class="wfb-field"><label>Completion Mode</label><select name="assignment_completion_mode" id="completionMode"><option value="primary_only">Primary Assignee Only</option><option value="task_owner">Task Owner</option><option value="all_assignees">All Assignees</option></select></div><div class="wfb-field"><label>Version</label><input type="number" name="version_no" id="versionNo" min="1" value="1"></div><div class="wfb-field full"><label>Description</label><textarea name="description" id="workflowDescription" placeholder="Describe when and how this workflow should be used"></textarea></div></div></section>
<section class="wf-card"><div class="wf-toolbar"><div><strong style="font-size:11px;color:var(--fd-text)">Prebuilt Work Process</strong><small style="display:block;margin-top:3px;color:var(--fd-muted);font-size:8.5px">Build ordered steps, then add the required checklist/input/photo/signature fields inside each step.</small></div><div class="wf-spacer"></div><button class="wf-btn primary" type="button" id="addStepBtn"><i class="bi bi-plus-lg"></i> Add Step</button></div><div class="wfb-section"><div class="wfb-steps" id="stepsBox"></div></div><div class="wfb-footer"><a class="wf-btn" href="workflows.php">Cancel</a><button class="wf-btn primary" type="submit" id="saveBtn"><span class="wf-loader"></span><i class="bi bi-check-lg"></i> Save Workflow</button></div></section></div>
<aside class="wfb-side"><section class="wf-card wfb-section"><h3 class="wfb-section-title">Available Field Types</h3><p class="wfb-section-sub">Add these inside any work-process step.</p><div class="wfb-side-list" id="typeGuide"></div></section><section class="wf-card wfb-section"><h3 class="wfb-section-title">Validation</h3><div class="wfb-side-list"><div class="wfb-side-row"><span><i class="bi bi-lock"></i></span><div><strong>Required fields</strong><small>Technician cannot complete a mandatory step until required fields are completed.</small></div></div><div class="wfb-side-row"><span><i class="bi bi-camera"></i></span><div><strong>Photo limits</strong><small>Single/multiple photo fields can enforce minimum and maximum uploads.</small></div></div><div class="wfb-side-row"><span><i class="bi bi-list-check"></i></span><div><strong>Checklist items</strong><small>Checklist/select/radio fields support configurable options.</small></div></div></div></section></aside></div></form>
<div class="wf-toast info" id="toast"><span id="toastMessage">Notification</span><button type="button" id="toastClose"><i class="bi bi-x"></i></button></div>
<script>(function(){'use strict';var csrf=<?= json_encode($workflowsCsrfToken) ?>,workflowId=<?= (int)$workflowId ?>,stepSeq=0,toast=document.getElementById('toast'),toastMsg=document.getElementById('toastMessage'),toastTimer=null,stepsBox=document.getElementById('stepsBox'),saveBtn=document.getElementById('saveBtn');var TYPES=[['checklist','Checklist','bi-list-check'],['text','Text Input','bi-fonts'],['textarea','Long Text / Notes','bi-text-paragraph'],['number','Number','bi-123'],['decimal','Decimal','bi-calculator'],['yes_no','Yes / No','bi-toggle-on'],['select','Dropdown','bi-menu-button-wide'],['radio','Radio Options','bi-ui-radios'],['checkbox','Single Checkbox','bi-check-square'],['photo_single','Single Photo','bi-camera'],['photo_multiple','Multiple Photos','bi-images'],['signature','Signature','bi-pen'],['date','Date','bi-calendar3'],['time','Time','bi-clock'],['datetime','Date & Time','bi-calendar2-week'],['location','GPS / Location','bi-geo-alt'],['file','File Upload','bi-paperclip'],['customer_confirmation','Customer Confirmation','bi-person-check'],['heading','Heading / Instruction','bi-type-h1']];function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}function note(t,m){if(toastTimer)clearTimeout(toastTimer);toast.className='wf-toast '+t+' show';toastMsg.textContent=m;toastTimer=setTimeout(function(){toast.classList.remove('show')},3000)}function loading(b,on){b.disabled=!!on;b.classList.toggle('loading',!!on)}function req(fd){fd.append('csrf_token',csrf);return fetch('api/workflows.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.text().then(function(x){var d;try{d=x.trim()?JSON.parse(x):{}}catch(e){throw new Error(x.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d})})}function typeOptions(selected){return TYPES.map(function(t){return '<option value="'+t[0]+'"'+(selected===t[0]?' selected':'')+'>'+t[1]+'</option>'}).join('')}function guide(){document.getElementById('typeGuide').innerHTML=TYPES.map(function(t){return '<div class="wfb-side-row"><span><i class="bi '+t[2]+'"></i></span><div><strong>'+t[1]+'</strong><small>'+fieldHint(t[0])+'</small></div></div>'}).join('')}function fieldHint(t){var m={checklist:'One or more technician checklist items.',text:'Short text such as model or serial number.',textarea:'Detailed inspection/service notes.',number:'Whole-number measurement or quantity.',decimal:'Pressure, temperature, reading or amount.',yes_no:'Simple Yes / No confirmation.',select:'Choose one value from configured options.',radio:'Visible single-choice option list.',checkbox:'Single required/optional confirmation.',photo_single:'Capture/upload one photo.',photo_multiple:'Capture multiple photos with min/max limits.',signature:'Technician or customer signature.',date:'Date value.',time:'Time value.',datetime:'Date and time together.',location:'Capture GPS coordinates/location.',file:'Upload supporting document/file.',customer_confirmation:'Customer confirms work/result.',heading:'Instruction text with no answer.'};return m[t]||''}function optionTypes(t){return ['checklist','select','radio'].indexOf(t)!==-1}function photoType(t){return t==='photo_single'||t==='photo_multiple'}function numberType(t){return t==='number'||t==='decimal'}function addOption(card,opt){opt=opt||{};var list=card.querySelector('.wfb-option-list'),row=document.createElement('div');row.className='wfb-option-row';row.innerHTML='<input class="opt-label" placeholder="Option / checklist item" value="'+esc(opt.option_label||'')+'"><input class="opt-value" placeholder="Value" value="'+esc(opt.option_value||'')+'"><button type="button" class="wf-icon-btn danger"><i class="bi bi-x"></i></button>';row.querySelector('button').onclick=function(){row.remove()};list.appendChild(row)}function renderFieldSettings(card,data){data=data||{};var t=card.querySelector('.wfb-field-type').value,box=card.querySelector('.wfb-field-options'),html='<div class="wfb-mini"><label>Field Key</label><input class="f-key" maxlength="100" value="'+esc(data.field_key||'')+'" placeholder="auto if blank"></div><div class="wfb-mini"><label>Placeholder</label><input class="f-placeholder" maxlength="255" value="'+esc(data.placeholder||'')+'"></div><div class="wfb-mini"><label>Help Text</label><input class="f-help" maxlength="500" value="'+esc(data.help_text||'')+'"></div><label class="wfb-check"><input type="checkbox" class="f-required" '+(Number(data.is_required||0)===1?'checked':'')+'> Required</label>';if(numberType(t))html+='<div class="wfb-mini"><label>Minimum</label><input type="number" step="any" class="f-min" value="'+esc(data.min_value==null?'':data.min_value)+'"></div><div class="wfb-mini"><label>Maximum</label><input type="number" step="any" class="f-max" value="'+esc(data.max_value==null?'':data.max_value)+'"></div>';if(t==='text'||t==='textarea')html+='<div class="wfb-mini"><label>Min Length</label><input type="number" min="0" class="f-minlen" value="'+esc(data.min_length==null?'':data.min_length)+'"></div><div class="wfb-mini"><label>Max Length</label><input type="number" min="0" class="f-maxlen" value="'+esc(data.max_length==null?'':data.max_length)+'"></div>';if(photoType(t)||t==='file')html+='<div class="wfb-mini"><label>Minimum Files</label><input type="number" min="0" class="f-minfiles" value="'+esc(data.min_files==null?(t==='photo_single'?1:''):data.min_files)+'"></div><div class="wfb-mini"><label>Maximum Files</label><input type="number" min="1" class="f-maxfiles" value="'+esc(data.max_files==null?(t==='photo_single'?1:''):data.max_files)+'"></div><div class="wfb-mini"><label>Allowed Types</label><input class="f-accept" value="'+esc(data.accept_types||'')+'" placeholder="image/* or application/pdf"></div>';if(t==='signature')html+='<div class="wfb-mini"><label>Signature By</label><select class="f-signature-by"><option value="customer">Customer</option><option value="technician">Technician</option><option value="either">Either</option></select></div>';if(t==='location')html+='<label class="wfb-check"><input type="checkbox" class="f-gps-required" '+(data.config&&data.config.gps_required===false?'':'checked')+'> GPS coordinates required</label>';if(t==='customer_confirmation')html+='<div class="wfb-mini full"><label>Confirmation Text</label><input class="f-confirm-text" value="'+esc(data.config&&data.config.confirmation_text?data.config.confirmation_text:'I confirm the work has been completed satisfactorily.')+'"></div>';if(t==='heading')html+='<div class="wfb-mini full"><label>Instruction / Heading Text</label><textarea class="f-instruction">'+esc(data.config&&data.config.instruction?data.config.instruction:'')+'</textarea></div>';if(optionTypes(t))html+='<div class="wfb-mini full"><label>Options / Checklist Items</label><div class="wfb-option-list"></div><button type="button" class="wf-btn f-add-option" style="margin-top:6px;min-height:31px"><i class="bi bi-plus"></i> Add Item</button></div>';box.innerHTML=html;if(t==='signature'&&box.querySelector('.f-signature-by'))box.querySelector('.f-signature-by').value=(data.config&&data.config.signature_by)||'customer';if(optionTypes(t)){(data.options||[]).forEach(function(o){addOption(card,o)});if(!(data.options||[]).length)addOption(card,{});box.querySelector('.f-add-option').onclick=function(){addOption(card,{})}}}function addField(step,data){data=data||{};var fields=step.querySelector('.wfb-fields'),card=document.createElement('div');card.className='wfb-field-card';card.innerHTML='<input type="hidden" class="f-id" value="'+Number(data.id||0)+'"><div class="wfb-field-top"><span class="wfb-drag"><i class="bi bi-grip-vertical"></i></span><input class="wfb-field-label" placeholder="Field label / checklist item title" value="'+esc(data.label||'')+'"><select class="wfb-field-type">'+typeOptions(data.field_type||'checklist')+'</select><button type="button" class="wf-icon-btn danger f-remove"><i class="bi bi-trash"></i></button></div><div class="wfb-field-options"></div>';card.querySelector('.f-remove').onclick=function(){card.remove()};card.querySelector('.wfb-field-type').onchange=function(){renderFieldSettings(card,{})};fields.appendChild(card);renderFieldSettings(card,data)}function addStep(data){data=data||{};var step=document.createElement('section');step.className='wfb-step';step.dataset.seq=stepSeq++;step.innerHTML='<input type="hidden" class="s-id" value="'+Number(data.id||0)+'"><div class="wfb-step-head"><span class="wfb-step-num">'+(stepsBox.children.length+1)+'</span><div class="wfb-step-title"><input class="s-name" maxlength="190" required placeholder="Example: Initial Inspection" value="'+esc(data.step_name||'')+'"></div><div class="wfb-step-actions"><button type="button" class="wf-icon-btn s-up" title="Move up"><i class="bi bi-arrow-up"></i></button><button type="button" class="wf-icon-btn s-down" title="Move down"><i class="bi bi-arrow-down"></i></button><button type="button" class="wf-icon-btn danger s-remove" title="Remove"><i class="bi bi-trash"></i></button></div></div><div class="wfb-step-body"><div class="wfb-step-meta"><textarea class="s-description" placeholder="Technician instructions for this step">'+esc(data.description||'')+'</textarea><label class="wfb-required"><input type="checkbox" class="s-required" '+(data.required===undefined||Number(data.required)===1?'checked':'')+'> Step required</label></div><div class="wfb-fields-title"><strong>Fields / Required Inputs</strong><button type="button" class="wf-btn s-add-field" style="min-height:32px"><i class="bi bi-plus"></i> Add Field</button></div><div class="wfb-fields"></div></div>';step.querySelector('.s-add-field').onclick=function(){addField(step,{field_type:'checklist',is_required:1})};step.querySelector('.s-remove').onclick=function(){if(stepsBox.children.length<=1){note('warning','A workflow needs at least one process step.');return}step.remove();renumber()};step.querySelector('.s-up').onclick=function(){var p=step.previousElementSibling;if(p){stepsBox.insertBefore(step,p);renumber()}};step.querySelector('.s-down').onclick=function(){var n=step.nextElementSibling;if(n){stepsBox.insertBefore(n,step);renumber()}};stepsBox.appendChild(step);(data.fields||[]).forEach(function(f){addField(step,f)});if(!(data.fields||[]).length)addField(step,{field_type:'checklist',is_required:1});renumber()}function renumber(){Array.prototype.forEach.call(stepsBox.children,function(s,i){s.querySelector('.wfb-step-num').textContent=i+1})}function collect(fd){Array.prototype.forEach.call(stepsBox.querySelectorAll('.wfb-step'),function(step,si){var sp='steps['+si+']';fd.append(sp+'[id]',step.querySelector('.s-id').value||0);fd.append(sp+'[step_name]',step.querySelector('.s-name').value.trim());fd.append(sp+'[description]',step.querySelector('.s-description').value.trim());fd.append(sp+'[sort_order]',si+1);fd.append(sp+'[required]',step.querySelector('.s-required').checked?'1':'0');Array.prototype.forEach.call(step.querySelectorAll('.wfb-field-card'),function(card,fi){var fp=sp+'[fields]['+fi+']',t=card.querySelector('.wfb-field-type').value;fd.append(fp+'[id]',card.querySelector('.f-id').value||0);fd.append(fp+'[label]',card.querySelector('.wfb-field-label').value.trim());fd.append(fp+'[field_type]',t);fd.append(fp+'[sort_order]',fi+1);['key','placeholder','help'].forEach(function(k){var el=card.querySelector('.f-'+k);fd.append(fp+'['+(k==='key'?'field_key':k==='help'?'help_text':'placeholder')+']',el?el.value.trim():'')});var reqd=card.querySelector('.f-required');fd.append(fp+'[is_required]',reqd&&reqd.checked?'1':'0');[['min','min_value'],['max','max_value'],['minlen','min_length'],['maxlen','max_length'],['minfiles','min_files'],['maxfiles','max_files'],['accept','accept_types']].forEach(function(x){var el=card.querySelector('.f-'+x[0]);fd.append(fp+'['+x[1]+']',el?el.value:'')});var cfg={};var sb=card.querySelector('.f-signature-by');if(sb)cfg.signature_by=sb.value;var gps=card.querySelector('.f-gps-required');if(gps)cfg.gps_required=gps.checked;var ct=card.querySelector('.f-confirm-text');if(ct)cfg.confirmation_text=ct.value.trim();var ins=card.querySelector('.f-instruction');if(ins)cfg.instruction=ins.value.trim();fd.append(fp+'[config_json]',JSON.stringify(cfg));Array.prototype.forEach.call(card.querySelectorAll('.wfb-option-row'),function(or,oi){fd.append(fp+'[options]['+oi+'][option_label]',or.querySelector('.opt-label').value.trim());fd.append(fp+'[options]['+oi+'][option_value]',or.querySelector('.opt-value').value.trim());fd.append(fp+'[options]['+oi+'][sort_order]',oi+1)})})})}function applyData(d){var w=d.workflow||{};document.getElementById('workflowName').value=w.name||'';document.getElementById('workflowCode').value=w.code||'';document.getElementById('workflowStatus').value=w.status||'draft';document.getElementById('completionMode').value=w.assignment_completion_mode||'primary_only';document.getElementById('versionNo').value=Number(w.version_no||1);document.getElementById('workflowDescription').value=w.description||'';if(w.service_id)document.getElementById('serviceId').value=w.service_id;stepsBox.innerHTML='';(d.steps||[]).forEach(addStep);if(!(d.steps||[]).length)addStep({step_name:'Technician Check-in',required:1,fields:[{label:'Technician checked in',field_type:'checklist',is_required:1}]})}function load(){var fd=new FormData();fd.append('action',workflowId>0?'builder_get':'builder_meta');if(workflowId>0)fd.append('workflow_id',workflowId);req(fd).then(function(d){var h='<option value="">Select Service</option>';(d.services||[]).forEach(function(s){h+='<option value="'+Number(s.id)+'">'+esc(s.name)+(s.sku?' ('+esc(s.sku)+')':'')+'</option>'});document.getElementById('serviceId').innerHTML=h;if(workflowId>0)applyData(d);else{stepsBox.innerHTML='';addStep({step_name:'Technician Check-in',required:1,fields:[{label:'Technician checked in',field_type:'checklist',is_required:1}]})}}).catch(function(e){note('error',e.message)})}document.getElementById('addStepBtn').onclick=function(){addStep({})};document.getElementById('builderForm').onsubmit=function(e){e.preventDefault();if(!this.reportValidity()){note('warning','Complete all required workflow fields.');return}if(!document.getElementById('serviceId').value){note('warning','Select the service first.');return}var invalid=false;stepsBox.querySelectorAll('.wfb-step').forEach(function(s){if(!s.querySelector('.s-name').value.trim())invalid=true;s.querySelectorAll('.wfb-field-card').forEach(function(f){var type=f.querySelector('.wfb-field-type').value;if(type!=='heading'&&!f.querySelector('.wfb-field-label').value.trim())invalid=true})});if(invalid){note('warning','Every step and field needs a name/label.');return}var fd=new FormData(this);fd.append('action','builder_save');collect(fd);loading(saveBtn,true);req(fd).then(function(d){note('success',d.message);setTimeout(function(){window.location.href='view-workflow.php?id='+Number(d.workflow_id)},700)}).catch(function(er){note('error',er.message)}).finally(function(){loading(saveBtn,false)})};document.getElementById('toastClose').onclick=function(){toast.classList.remove('show')};guide();load()})();</script></div></div></main></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>