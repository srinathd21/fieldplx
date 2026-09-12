<?php
/* FieldPlx Add Service Request - Version 3.2.0 - 2026-09-08
 * Jobber request UI aligned to Add Invoice controls.
 * Fixes form metadata loading, Select2 customer/team/catalog controls, customer confirmation email,
 * and Email team when assigned behavior.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Add Service Request';
$activePage = 'requests';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['add_request_csrf_token'])) {
    $_SESSION['add_request_csrf_token'] = bin2hex(random_bytes(32));
}

$addRequestCsrfToken = (string)$_SESSION['add_request_csrf_token'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>Add Service Request - FieldPlx</title>
    <?php require_once __DIR__ . '/includes/links.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
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
\n/* Clients page additions */\na,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}\n.fd-client-type{display:inline-flex;align-items:center;padding:5px 7px;border-radius:5px;font-size:8.5px;font-weight:600}\n.fd-client-type.client,.fd-client-type.active{color:#5d971b;background:#f0f8e5}.fd-client-type.lead,.fd-client-type.new{color:#123d70;background:#edf2f7}.fd-client-type.inactive{color:#6f7b90;background:#eef2f6}.fd-client-type.archived{color:#8a5e10;background:#fff7df}\n.fd-client-checks{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:8px}.fd-client-check{min-height:38px;padding:7px 9px;display:inline-flex;align-items:center;gap:7px;border:1px solid #e3e8ed;border-radius:7px;color:#5c6d82;background:#fff;font-size:8.5px}.fd-client-check input{width:14px;height:14px;accent-color:var(--fd-green)}\n
/* Clients table font + alignment correction */
.fd-client-table{
  table-layout:auto;
}

.fd-client-table th{
  padding:12px 14px !important;
  color:#5f6f86 !important;
  font-size:9px !important;
  line-height:1.2 !important;
  font-weight:700 !important;
  letter-spacing:.01em !important;
  text-align:left !important;
  vertical-align:middle !important;
  white-space:nowrap !important;
}

.fd-client-table td{
  padding:12px 14px !important;
  color:#33445f !important;
  font-size:9.5px !important;
  line-height:1.45 !important;
  font-weight:400 !important;
  text-align:left !important;
  vertical-align:middle !important;
}

.fd-client-table th:first-child,
.fd-client-table td:first-child{
  width:58px;
  text-align:center !important;
}

.fd-client-person{
  min-width:185px;
  align-items:center !important;
}

.fd-client-person strong{
  color:#17233b !important;
  font-size:10.5px !important;
  line-height:1.35 !important;
  font-weight:700 !important;
}

.fd-client-person small{
  margin-top:3px !important;
  color:#8793a5 !important;
  font-size:8.5px !important;
  line-height:1.3 !important;
  font-weight:400 !important;
}

.fd-client-table td small{
  color:#66758a !important;
  font-size:8.5px !important;
  line-height:1.35 !important;
}

.fd-client-badge{
  min-height:22px;
  padding:4px 7px !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  border-radius:5px !important;
  font-size:8.5px !important;
  line-height:1 !important;
  font-weight:700 !important;
  text-transform:capitalize !important;
  white-space:nowrap !important;
}

.fd-client-actions-cell{
  min-width:100px;
  justify-content:flex-start !important;
  align-items:center !important;
  gap:4px !important;
}

.fd-client-icon-btn{
  width:29px !important;
  height:29px !important;
  min-width:29px !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  line-height:1 !important;
}

.fd-client-icon-btn i{
  line-height:1 !important;
  font-size:12px !important;
}

.fd-client-table td:nth-child(3),
.fd-client-table td:nth-child(9){
  vertical-align:middle !important;
}

.fd-client-table td:nth-child(10){
  color:#52627a !important;
  font-size:9px !important;
}

.fd-client-table th:last-child,
.fd-client-table td:last-child{
  text-align:left !important;
}

.fd-client-table a,
.fd-client-table a:visited,
.fd-client-table a:hover,
.fd-client-table a:focus,
.fd-client-table a:active{
  color:inherit;
  text-decoration:none !important;
}

.fd-client-table a.fd-client-badge,
.fd-client-table a.fd-client-badge:visited{
  color:#123d70 !important;
}

@media(max-width:767.98px){
  .fd-client-table th,
  .fd-client-table td{
    padding:10px 11px !important;
  }
}

/* Client Locations page */
.fd-loc-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}
.fd-loc-title{
  margin:0 0 7px;
  color:var(--fd-text);
  font-size:21px;
  font-weight:700;
}
.fd-loc-sub{
  margin:0;
  max-width:800px;
  color:var(--fd-muted);
  font-size:11px;
  line-height:1.55;
}
.fd-loc-actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.fd-loc-client{
  margin-bottom:14px;
  padding:12px 14px;
  display:flex;
  align-items:center;
  gap:10px;
  border:1px solid var(--fd-border);
  border-radius:10px;
  background:#fff;
}
.fd-loc-client-icon{
  width:38px;
  height:38px;
  display:grid;
  place-items:center;
  flex:0 0 38px;
  border-radius:10px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}
.fd-loc-client strong,
.fd-loc-client small{
  display:block;
}
.fd-loc-client strong{
  color:var(--fd-text);
  font-size:11px;
}
.fd-loc-client small{
  margin-top:3px;
  color:var(--fd-muted);
  font-size:8.5px;
}
.fd-loc-address{
  min-width:260px;
  white-space:normal !important;
  line-height:1.4;
}
.fd-loc-modal{
  width:min(900px,100%);
}
.fd-loc-map-link{
  color:#123d70 !important;
  font-weight:700;
  text-decoration:none !important;
}
.fd-loc-map-link:hover{
  color:var(--fd-green-dark) !important;
}
.fd-loc-primary{
  color:#5d971b;
  background:#f0f8e5;
}
.fd-loc-type{
  color:#123d70;
  background:#edf2f7;
}
.fd-loc-table .fd-team-actions-cell{
  justify-content:flex-start;
}
.fd-loc-table-wrap{
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:thin;
  scrollbar-color:#9aa0a6 transparent;
}
.fd-loc-table-wrap::-webkit-scrollbar{height:3px!important}
.fd-loc-table-wrap::-webkit-scrollbar-track{background:transparent!important}
.fd-loc-table-wrap::-webkit-scrollbar-thumb{
  min-width:20px;
  border-radius:999px!important;
  background:#9aa0a6!important;
}
.fd-loc-table-wrap::-webkit-scrollbar-button{
  width:0!important;
  height:0!important;
  display:none!important;
}
@media(max-width:767.98px){
  .fd-loc-head{
    flex-direction:column;
  }
  .fd-loc-actions{
    width:100%;
  }
}

/* ==========================================================
   Service Requests - tenant CRM intake
   ========================================================== */
a,
a:link,
a:visited,
a:hover,
a:focus,
a:active{
  text-decoration:none!important;
}

.fd-rq-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}

.fd-rq-title{
  margin:0 0 7px;
  color:var(--fd-text);
  font-size:21px;
  line-height:1.2;
  font-weight:700;
}

.fd-rq-sub{
  margin:0;
  max-width:860px;
  color:var(--fd-muted);
  font-size:11px;
  line-height:1.55;
}

.fd-rq-actions{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}

.fd-rq-btn{
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
  cursor:pointer;
}

.fd-rq-btn:hover{
  border-color:#cfe3ae;
  color:var(--fd-green-dark);
  background:#f9fcf4;
}

.fd-rq-btn.primary{
  border-color:var(--fd-green);
  color:#fff;
  background:linear-gradient(90deg,#7fc92d,#68aa1d);
  box-shadow:0 7px 16px rgba(104,170,29,.18);
}

.fd-rq-btn.primary:hover{
  color:#fff;
  background:linear-gradient(90deg,#74b824,#5d971b);
}

.fd-rq-btn.danger{
  border-color:#ffd5d9;
  color:#b9444d;
  background:#fff;
}

.fd-rq-btn:disabled{
  opacity:.58;
  cursor:not-allowed;
}

.fd-rq-loader{
  width:13px;
  height:13px;
  display:none;
  border:2px dotted currentColor;
  border-radius:50%;
  animation:fdRqSpin .75s linear infinite;
}

.fd-rq-btn.loading .fd-rq-loader{display:inline-block}

@keyframes fdRqSpin{
  to{transform:rotate(360deg)}
}

.fd-rq-summary{margin-bottom:16px}

.fd-rq-stat{
  min-height:112px;
  padding:18px 20px;
  border:1px solid #dfe6ef;
  border-radius:12px;
  background:#fff;
  box-shadow:0 3px 12px rgba(24,45,76,.035);
}

.fd-rq-stat-row{
  min-height:72px;
  display:flex;
  align-items:center;
  gap:18px;
}

.fd-rq-stat-icon{
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

.fd-rq-stat-label{
  display:block;
  margin-bottom:8px;
  color:#506784;
  font-size:13px;
}

.fd-rq-stat-value{
  display:block;
  color:#020b16;
  font-size:31px;
  line-height:1;
  font-weight:700;
}

.fd-rq-card{overflow:hidden}

.fd-rq-toolbar{
  padding:13px 14px;
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}

.fd-rq-search{
  width:280px;
  position:relative;
}

.fd-rq-search i{
  position:absolute;
  left:12px;
  top:50%;
  transform:translateY(-50%);
  color:#8a96a7;
  font-size:13px;
}

.fd-rq-search input,
.fd-rq-filter{
  height:39px;
  border:1px solid #dde4ec;
  border-radius:8px;
  outline:0;
  color:#33445f;
  background:#fff;
  font-size:10px;
}

.fd-rq-search input{
  width:100%;
  padding:8px 11px 8px 34px;
}

.fd-rq-filter{
  min-width:140px;
  padding:8px 10px;
}

.fd-rq-search input:focus,
.fd-rq-filter:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}

.fd-rq-spacer{margin-left:auto}

.fd-rq-table-wrap{
  width:100%;
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:thin;
  scrollbar-color:#9aa0a6 transparent;
}

.fd-rq-table-wrap::-webkit-scrollbar{height:3px!important}
.fd-rq-table-wrap::-webkit-scrollbar-track{background:transparent!important}
.fd-rq-table-wrap::-webkit-scrollbar-thumb{
  min-width:20px;
  border-radius:999px!important;
  background:#9aa0a6!important;
}
.fd-rq-table-wrap::-webkit-scrollbar-button{
  width:0!important;
  height:0!important;
  display:none!important;
}

.fd-rq-table{
  width:100%;
  min-width:1320px;
  margin:0;
  border-collapse:collapse;
  white-space:nowrap;
}

.fd-rq-table th{
  padding:11px 12px;
  border-bottom:1px solid var(--fd-border);
  color:#65738a;
  background:#f8fafc;
  font-size:9px;
  line-height:1.2;
  font-weight:700;
  text-align:left;
  text-transform:uppercase;
}

.fd-rq-table td{
  padding:12px;
  border-bottom:1px solid #f1f3f7;
  color:#33445f;
  font-size:9.5px;
  line-height:1.45;
  vertical-align:middle;
}

.fd-rq-table tbody tr:hover{background:#fbfcfa}

.fd-rq-table th:first-child,
.fd-rq-table td:first-child{
  width:55px;
  text-align:center;
}

.fd-rq-request strong,
.fd-rq-request small,
.fd-rq-client strong,
.fd-rq-client small{
  display:block;
}

.fd-rq-request strong{
  color:#123d70;
  font-size:10.5px;
  font-weight:700;
}

.fd-rq-request small,
.fd-rq-client small{
  margin-top:3px;
  color:#8995a6;
  font-size:8.3px;
}

.fd-rq-client strong{
  color:#17233b;
  font-size:10px;
  font-weight:700;
}

.fd-rq-badge{
  min-height:22px;
  padding:4px 7px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:5px;
  font-size:8.5px;
  line-height:1;
  font-weight:700;
  text-transform:capitalize;
}

.fd-rq-badge.new,
.fd-rq-badge.normal{
  color:#123d70;
  background:#edf2f7;
}

.fd-rq-badge.contacting,
.fd-rq-badge.information_required{
  color:#8a5e10;
  background:#fff7df;
}

.fd-rq-badge.assessment_required,
.fd-rq-badge.quote_required,
.fd-rq-badge.job_required,
.fd-rq-badge.high{
  color:#b55b00;
  background:#fff1e4;
}

.fd-rq-badge.converted,
.fd-rq-badge.closed,
.fd-rq-badge.low{
  color:#5d971b;
  background:#f0f8e5;
}

.fd-rq-badge.cancelled{
  color:#8b4450;
  background:#fff0f1;
}

.fd-rq-badge.urgent{
  color:#bd2f3a;
  background:#fff0f1;
}

.fd-rq-actions-cell{
  min-width:120px;
  display:flex;
  align-items:center;
  gap:4px;
}

.fd-rq-icon{
  width:29px;
  height:29px;
  min-width:29px;
  padding:0;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border:0;
  border-radius:6px;
  color:#66748b;
  background:transparent;
  cursor:pointer;
  font-size:12px;
  line-height:1;
}

.fd-rq-icon:hover{
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}

.fd-rq-icon.danger:hover{
  color:#b9444d;
  background:#fff0f1;
}

.fd-rq-empty{
  padding:28px 18px!important;
  text-align:center;
  color:#9aa4b3!important;
  font-size:10px!important;
}

.fd-rq-pagination{
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

.fd-rq-pagination-actions{
  display:flex;
  gap:5px;
}

/* Modal */
.fd-rq-modal-bg{
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

.fd-rq-modal-bg.show{display:flex}

.fd-rq-modal{
  width:min(940px,100%);
  max-height:calc(100vh - 34px);
  overflow:auto;
  border:1px solid #dfe5ec;
  border-radius:12px;
  background:#fff;
  box-shadow:0 24px 65px rgba(0,17,49,.24);
}

.fd-rq-modal.small{
  width:min(610px,100%);
}

.fd-rq-modal-header{
  min-height:58px;
  padding:11px 14px;
  display:flex;
  align-items:center;
  gap:10px;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}

.fd-rq-modal-icon{
  width:34px;
  height:34px;
  display:grid;
  place-items:center;
  border-radius:9px;
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
  font-size:15px;
}

.fd-rq-modal-heading{
  min-width:0;
  flex:1;
}

.fd-rq-modal-heading h3{
  margin:0;
  color:var(--fd-text);
  font-size:12px;
  font-weight:700;
}

.fd-rq-modal-heading p{
  margin:3px 0 0;
  color:var(--fd-muted);
  font-size:8.5px;
}

.fd-rq-modal-close{
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

.fd-rq-modal-body{padding:15px}

.fd-rq-form-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:13px;
}

.fd-rq-field.full{grid-column:1/-1}

.fd-rq-field label{
  margin-bottom:6px;
  display:block;
  color:#42536c;
  font-size:9px;
  font-weight:700;
}

.fd-rq-field input,
.fd-rq-field select,
.fd-rq-field textarea{
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

.fd-rq-field textarea{
  min-height:88px;
  resize:vertical;
}

.fd-rq-field input:focus,
.fd-rq-field select:focus,
.fd-rq-field textarea:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}

.fd-rq-section{
  grid-column:1/-1;
  margin-top:4px;
  padding:8px 0 3px;
  border-bottom:1px solid #eef2f5;
  color:#31425b;
  font-size:9px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.04em;
}

.fd-rq-modal-footer{
  padding:12px 15px;
  display:flex;
  justify-content:flex-end;
  gap:8px;
  border-top:1px solid var(--fd-border);
  background:#fbfcfd;
}

/* History */
.fd-rq-history{
  display:grid;
  gap:8px;
}

.fd-rq-history-item{
  padding:10px 11px;
  border:1px solid #e4e9ef;
  border-radius:8px;
  background:#fbfcfd;
}

.fd-rq-history-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}

.fd-rq-history-top strong{
  color:#263750;
  font-size:9.5px;
}

.fd-rq-history-item small{
  display:block;
  margin-top:4px;
  color:#8793a5;
  font-size:8px;
}

.fd-rq-history-item p{
  margin:7px 0 0;
  color:#56667c;
  font-size:8.5px;
  line-height:1.5;
}

/* Toast */
.fd-rq-toast{
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

.fd-rq-toast.show{
  opacity:1;
  transform:translateY(0);
}

.fd-rq-toast.success{background:#5d971b}
.fd-rq-toast.error{background:#e45b66}
.fd-rq-toast.warning{background:#96a52f}
.fd-rq-toast.info{background:#123d70}

.fd-rq-toast-msg{
  min-width:0;
  flex:1;
  font-size:8.5px;
  font-weight:600;
}

.fd-rq-toast-close{
  width:19px;
  height:19px;
  padding:0;
  border:0;
  color:#fff;
  background:transparent;
  cursor:pointer;
}

@media(max-width:767.98px){
  .fd-rq-head{flex-direction:column}
  .fd-rq-actions{width:100%}
  .fd-rq-form-grid{grid-template-columns:1fr}
  .fd-rq-field.full,
  .fd-rq-section{grid-column:auto}
  .fd-rq-search{width:100%}
  .fd-rq-spacer{display:none}
}

@media(max-width:575.98px){
  .fd-rq-stat{
    min-height:102px;
    padding:15px 17px;
  }
  .fd-rq-stat-icon{
    width:54px;
    height:54px;
    flex-basis:54px;
  }
  .fd-rq-stat-value{font-size:29px}
  .fd-rq-filter{flex:1}
  .fd-rq-modal-footer{flex-direction:column-reverse}
  .fd-rq-modal-footer .fd-rq-btn{width:100%}
  .fd-rq-toast{
    top:72px;
    left:12px;
    right:12px;
    width:auto;
  }
}

/* ==========================================================
   Add Invoice reference: shared FieldPlx shell / typography
   ========================================================== */
        :root{
            --fieldplx-primary:#74b824;
            --fieldplx-primary-dark:#5d971b;
            --fieldplx-text:#0b1933;
            --fieldplx-muted:#6f7b90;
            --fieldplx-border:#e5eaf1;
            --fieldplx-surface:#ffffff;
            --fieldplx-background:#f6f8fb;
            --fieldplx-topbar-height:70px;
            --fieldplx-sidebar-width:250px;
            --fieldplx-sidebar-collapsed-width:78px;

            --fd-navy:#001131;
            --fd-navy-light:#071f49;
            --fd-blue:#123d70;
            --fd-green:#74b824;
            --fd-green-dark:#5d971b;
            --fd-green-soft:#f0f8e5;
            --fd-red:#e45b66;
            --fd-bg:#f6f8fb;
            --fd-text:#0b1933;
            --fd-muted:#6f7b90;
            --fd-border:#e5eaf1;
        }

        *{
            box-sizing:border-box;
        }

        html,
        body{
            margin:0;
            min-height:100%;
            overflow-x:hidden;
        }

        body{
            min-height:100vh;
            background:var(--fd-bg)!important;
            color:var(--fd-text);
            font-family:Arial,Helvetica,sans-serif!important;
            font-size:14px;
        }

        a,
        a:link,
        a:visited,
        a:hover,
        a:focus,
        a:active{
            text-decoration:none!important;
        }

        /* ---------- Topbar ---------- */
        .fieldplx-topbar{
            min-height:70px!important;
            position:sticky!important;
            top:0!important;
            z-index:1030!important;
            margin-left:var(--fieldplx-sidebar-width);
            width:calc(100% - var(--fieldplx-sidebar-width));
            background:#fff!important;
            border-bottom:1px solid var(--fd-border)!important;
            box-shadow:0 3px 14px rgba(0,17,49,.035)!important;
            backdrop-filter:none!important;
            transition:margin-left .25s ease,width .25s ease;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-topbar{
            margin-left:var(--fieldplx-sidebar-collapsed-width);
            width:calc(100% - var(--fieldplx-sidebar-collapsed-width));
        }

        .fieldplx-topbar-inner{
            min-height:70px!important;
            padding:0 27px!important;
            display:flex!important;
            align-items:center!important;
            gap:13px!important;
        }

        .fieldplx-brand-mobile{
            display:none!important;
            align-items:center!important;
            gap:9px!important;
            min-width:0!important;
            color:var(--fd-text)!important;
        }

        .fieldplx-brand-logo{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            border-radius:10px!important;
            object-fit:contain!important;
        }

        .fieldplx-brand-placeholder{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:10px!important;
            color:#fff!important;
            background:linear-gradient(135deg,#8fd236,#68aa1d)!important;
            font-weight:700!important;
        }

        .fieldplx-brand-name{
            max-width:170px!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:var(--fd-text)!important;
            font-size:14px!important;
            font-weight:700!important;
        }

        .fieldplx-page-heading{
            display:none!important;
        }

        .fieldplx-menu-toggle,
        .fieldplx-topbar-action{
            width:41px!important;
            height:41px!important;
            min-width:41px!important;
            padding:0!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            position:relative!important;
            border:0!important;
            border-radius:9px!important;
            color:var(--fd-navy)!important;
            background:transparent!important;
            font-size:18px!important;
            box-shadow:none!important;
        }

        .fieldplx-menu-toggle:hover,
        .fieldplx-topbar-action:hover{
            color:var(--fd-navy)!important;
            background:var(--fd-green-soft)!important;
        }

        .fieldplx-search-wrap{
            width:280px!important;
            margin-left:auto!important;
            position:relative!important;
        }

        .fieldplx-search-icon{
            position:absolute!important;
            top:50%!important;
            left:13px!important;
            z-index:2!important;
            transform:translateY(-50%)!important;
            color:#98a3b2!important;
            font-size:14px!important;
            pointer-events:none!important;
        }

        .fieldplx-search-input{
            width:100%!important;
            height:41px!important;
            padding:8px 13px 8px 38px!important;
            border:0!important;
            border-radius:8px!important;
            outline:0!important;
            background:#f5f8fb!important;
            color:var(--fd-text)!important;
            font-size:12px!important;
            box-shadow:none!important;
        }

        .fieldplx-search-input:focus{
            background:#f5f8fb!important;
            box-shadow:0 0 0 3px rgba(116,184,36,.14)!important;
        }

        .fieldplx-notification-count{
            position:absolute!important;
            top:-5px!important;
            right:-5px!important;
            min-width:18px!important;
            height:18px!important;
            padding:0 5px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border:2px solid #fff!important;
            border-radius:999px!important;
            color:#fff!important;
            background:var(--fd-red)!important;
            font-size:9px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-button{
            min-width:0!important;
            padding:2px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border:0!important;
            border-radius:9px!important;
            background:transparent!important;
            color:var(--fd-text)!important;
            text-align:left!important;
            box-shadow:none!important;
        }

        .fieldplx-profile-button:hover{
            background:var(--fd-green-soft)!important;
        }

        .fieldplx-avatar{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            overflow:hidden!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border:0!important;
            border-radius:50%!important;
            color:var(--fd-navy)!important;
            background:linear-gradient(135deg,#fff,#e8f3d9)!important;
            font-size:12px!important;
            font-weight:800!important;
        }

        .fieldplx-avatar img{
            width:100%!important;
            height:100%!important;
            object-fit:cover!important;
        }

        .fieldplx-profile-details{
            max-width:145px!important;
            min-width:0!important;
        }

        .fieldplx-profile-name,
        .fieldplx-profile-role{
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-profile-name{
            color:#111827!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-role{
            margin-top:1px!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
        }

        /* ---------- Dropdowns ---------- */
        .fieldplx-dropdown{
            width:340px!important;
            max-width:calc(100vw - 24px)!important;
            padding:0!important;
            margin-top:10px!important;
            overflow:hidden!important;
            border:1px solid var(--fd-border)!important;
            border-radius:14px!important;
            background:#fff!important;
            box-shadow:0 18px 45px rgba(29,38,74,.14)!important;
        }

        .fieldplx-dropdown-header{
            min-height:48px!important;
            padding:11px 16px!important;
            display:flex!important;
            align-items:center!important;
            justify-content:space-between!important;
            border-bottom:1px solid var(--fd-border)!important;
            background:#fff!important;
        }

        .fieldplx-dropdown-title{
            margin:0!important;
            color:#111827!important;
            font-size:14px!important;
            font-weight:700!important;
        }

        #topbarNotificationList{
            max-height:300px!important;
            overflow-y:auto!important;
            background:#fff!important;
        }

        .fieldplx-notification-item{
            padding:11px 14px!important;
            display:flex!important;
            gap:10px!important;
            border-bottom:1px solid #f1f2f4!important;
            color:inherit!important;
            text-decoration:none!important;
        }

        .fieldplx-notification-item:hover,
        .fieldplx-notification-item.is-unread{
            background:#f8fbf3!important;
        }

        .fieldplx-notification-icon{
            width:32px!important;
            height:32px!important;
            flex:0 0 32px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:9px!important;
            color:var(--fd-green-dark)!important;
            background:var(--fd-green-soft)!important;
            font-size:14px!important;
        }

        .fieldplx-notification-content{
            min-width:0!important;
        }

        .fieldplx-notification-title{
            margin:0!important;
            color:#111827!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-notification-message{
            margin-top:3px!important;
            overflow:hidden!important;
            display:-webkit-box!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
            line-height:1.45!important;
            -webkit-line-clamp:2!important;
            -webkit-box-orient:vertical!important;
        }

        .fieldplx-notification-time{
            margin-top:4px!important;
            color:#9ca3af!important;
            font-size:9px!important;
        }

        .fieldplx-empty-notifications{
            min-height:155px!important;
            padding:28px 18px 24px!important;
            display:flex!important;
            flex-direction:column!important;
            align-items:center!important;
            justify-content:center!important;
            color:#718096!important;
            background:#fff!important;
            text-align:center!important;
            font-size:13px!important;
        }

        .fieldplx-empty-notifications i{
            margin-bottom:10px!important;
            color:#a9cf75!important;
            font-size:30px!important;
        }

        .fieldplx-dropdown-footer{
            min-height:44px!important;
            padding:10px 14px!important;
            display:flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-top:1px solid var(--fd-border)!important;
            background:#fff!important;
        }

        .fieldplx-dropdown-footer a{
            color:var(--fd-green-dark)!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-menu{
            width:230px!important;
            padding:7px!important;
            border:1px solid var(--fd-border)!important;
            border-radius:12px!important;
            background:#fff!important;
            box-shadow:0 18px 45px rgba(29,38,74,.14)!important;
        }

        .fieldplx-profile-menu-header{
            padding:9px 10px 11px!important;
            border-bottom:1px solid #f0f1f3!important;
        }

        .fieldplx-profile-menu-name{
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:#111827!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-profile-menu-email{
            margin-top:2px!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:var(--fd-muted)!important;
            font-size:10px!important;
        }

        .fieldplx-profile-menu .dropdown-item{
            padding:9px 10px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border-radius:8px!important;
            color:#374151!important;
            background:transparent!important;
            font-size:11px!important;
        }

        .fieldplx-profile-menu .dropdown-item:hover{
            color:var(--fd-green-dark)!important;
            background:var(--fd-green-soft)!important;
        }

        /* ---------- Sidebar ---------- */
        .fieldplx-sidebar{
            width:var(--fieldplx-sidebar-width)!important;
            min-width:var(--fieldplx-sidebar-width)!important;
            height:100vh!important;
            position:fixed!important;
            top:0!important;
            left:0!important;
            z-index:1045!important;
            display:flex!important;
            flex-direction:column!important;
            color:#fff!important;
            background:linear-gradient(180deg,var(--fd-navy-light),var(--fd-navy))!important;
            border-right:0!important;
            transition:width .25s ease,min-width .25s ease,transform .25s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
            width:var(--fieldplx-sidebar-collapsed-width)!important;
            min-width:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-sidebar-header{
            min-height:68px!important;
            padding:9px 14px 10px!important;
            display:flex!important;
            align-items:center!important;
            border-bottom:1px solid rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-brand{
            min-width:0!important;
            display:flex!important;
            align-items:center!important;
            gap:10px!important;
            color:#fff!important;
        }

        .fieldplx-sidebar-logo,
        .fieldplx-sidebar-logo-placeholder{
            width:40px!important;
            height:40px!important;
            flex:0 0 40px!important;
            border-radius:10px!important;
        }

        .fieldplx-sidebar-logo{
            object-fit:contain!important;
        }

        .fieldplx-sidebar-logo-placeholder{
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            color:#fff!important;
            background:linear-gradient(135deg,#8fd236,#68aa1d)!important;
            font-size:18px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-brand-text{
            min-width:0!important;
            display:block!important;
        }

        .fieldplx-sidebar-company-name{
            max-width:155px!important;
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
            color:#fff!important;
            font-size:16px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-product-name{
            margin-top:1px!important;
            display:block!important;
            color:#9fda55!important;
            font-size:9px!important;
            font-weight:600!important;
            letter-spacing:.4px!important;
            text-transform:uppercase!important;
        }

        .fieldplx-sidebar-close{
            width:32px!important;
            height:32px!important;
            margin-left:auto!important;
            padding:0!important;
            display:none!important;
            align-items:center!important;
            justify-content:center!important;
            border:0!important;
            border-radius:8px!important;
            color:rgba(255,255,255,.82)!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-body{
            min-height:0!important;
            flex:1 1 auto!important;
            overflow-y:auto!important;
            overflow-x:hidden!important;
            padding:12px 14px!important;
            scrollbar-width:none!important;
        }

        .fieldplx-sidebar-body::-webkit-scrollbar{
            display:none!important;
        }

        .fieldplx-sidebar-section-label{
            margin:7px 12px!important;
            color:rgba(255,255,255,.5)!important;
            font-size:9px!important;
            font-weight:700!important;
            letter-spacing:.65px!important;
            text-transform:uppercase!important;
        }

        .fieldplx-sidebar-nav{
            display:flex!important;
            flex-direction:column!important;
            gap:3px!important;
        }

        .fieldplx-sidebar-link{
            width:100%!important;
            min-height:46px!important;
            margin-bottom:3px!important;
            padding:0 14px!important;
            display:flex!important;
            align-items:center!important;
            gap:15px!important;
            border:0!important;
            border-radius:9px!important;
            color:rgba(255,255,255,.94)!important;
            background:transparent!important;
            text-align:left!important;
            font-family:inherit!important;
            font-size:14px!important;
            font-weight:600!important;
        }

        .fieldplx-sidebar-link:hover{
            color:#fff!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-link.active,
        .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-link{
            color:#fff!important;
            background:linear-gradient(90deg,#7fc92d,#68aa1d)!important;
            box-shadow:0 6px 18px rgba(0,17,49,.28)!important;
        }

        .fieldplx-sidebar-link-icon{
            width:21px!important;
            height:21px!important;
            flex:0 0 21px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            font-size:19px!important;
        }

        .fieldplx-sidebar-link-text{
            min-width:0!important;
            flex:1!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-sidebar-arrow{
            margin-left:auto!important;
            color:rgba(255,255,255,.65)!important;
            font-size:10px!important;
            transition:transform .2s ease!important;
        }

        .fieldplx-sidebar-menu.menu-open .fieldplx-sidebar-arrow{
            transform:rotate(180deg)!important;
        }

        .fieldplx-sidebar-submenu{
            max-height:0!important;
            overflow:hidden!important;
            padding-left:36px!important;
            transition:max-height .25s ease,padding-top .25s ease,padding-bottom .25s ease!important;
        }

        .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu{
            max-height:680px!important;
            padding-top:4px!important;
            padding-bottom:5px!important;
        }

        .fieldplx-sidebar-sublink{
            min-height:34px!important;
            padding:7px 9px!important;
            display:flex!important;
            align-items:center!important;
            border-radius:7px!important;
            color:rgba(255,255,255,.72)!important;
            background:transparent!important;
            font-size:11px!important;
            font-weight:500!important;
        }

        .fieldplx-sidebar-sublink::before{
            width:5px!important;
            height:5px!important;
            margin-right:9px!important;
            flex:0 0 5px!important;
            content:""!important;
            border-radius:50%!important;
            background:rgba(255,255,255,.35)!important;
        }

        .fieldplx-sidebar-sublink:hover,
        .fieldplx-sidebar-sublink.active{
            color:#fff!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-sublink.active::before{
            background:#9fda55!important;
        }

        .fieldplx-sidebar-footer{
            flex:0 0 auto!important;
            padding:10px 14px 14px!important;
            border-top:1px solid rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-user{
            min-height:62px!important;
            padding:8px!important;
            display:flex!important;
            align-items:center!important;
            gap:9px!important;
            border-radius:10px!important;
            background:rgba(255,255,255,.08)!important;
        }

        .fieldplx-sidebar-user-avatar{
            width:38px!important;
            height:38px!important;
            flex:0 0 38px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:50%!important;
            color:var(--fd-navy)!important;
            background:linear-gradient(135deg,#fff,#e8f3d9)!important;
            font-size:11px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-user-details{
            min-width:0!important;
            flex:1!important;
        }

        .fieldplx-sidebar-user-name,
        .fieldplx-sidebar-user-role{
            display:block!important;
            overflow:hidden!important;
            white-space:nowrap!important;
            text-overflow:ellipsis!important;
        }

        .fieldplx-sidebar-user-name{
            color:#fff!important;
            font-size:12px!important;
            font-weight:700!important;
        }

        .fieldplx-sidebar-user-role{
            margin-top:1px!important;
            color:rgba(255,255,255,.6)!important;
            font-size:9px!important;
        }

        .fieldplx-sidebar-logout{
            width:29px!important;
            height:29px!important;
            flex:0 0 29px!important;
            display:inline-flex!important;
            align-items:center!important;
            justify-content:center!important;
            border-radius:8px!important;
            color:rgba(255,255,255,.7)!important;
            font-size:14px!important;
        }

        .fieldplx-sidebar-logout:hover{
            color:#fff!important;
            background:rgba(228,91,102,.3)!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout{
            display:none!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
        body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user{
            justify-content:center!important;
        }

        /* ---------- Main content ---------- */
        .fieldplx-main-layout{
            display:block!important;
            min-height:calc(100vh - 70px)!important;
        }

        .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-width)!important;
            min-width:0!important;
            transition:margin-left .25s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-main-content{
            margin-left:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-content-wrapper{
            padding:0!important;
        }

        /* ---------- Footer ---------- */
        .fieldplx-footer{
            min-height:52px!important;
            margin-left:var(--fieldplx-sidebar-width)!important;
            display:block!important;
            border-top:1px solid var(--fd-border)!important;
            background:#fff!important;
            transition:margin-left .22s ease!important;
        }

        body.fieldplx-sidebar-collapsed .fieldplx-footer{
            margin-left:var(--fieldplx-sidebar-collapsed-width)!important;
        }

        .fieldplx-footer-inner{
            min-height:52px!important;
            padding:10px 18px!important;
            display:flex!important;
            align-items:center!important;
            gap:18px!important;
            color:#6b7280!important;
            font-size:10px!important;
        }

        .fieldplx-footer-links{
            display:flex!important;
            align-items:center!important;
            gap:8px!important;
        }

        .fieldplx-footer-links a{
            color:#6b7280!important;
        }

        .fieldplx-footer-links a:hover,
        .fieldplx-footer-product strong{
            color:var(--fd-green-dark)!important;
        }

        .fieldplx-footer-separator{
            color:#d1d5db!important;
            font-size:8px!important;
        }

        .fieldplx-footer-product{
            margin-left:auto!important;
            white-space:nowrap!important;
            color:#9ca3af!important;
        }

        /* ---------- Mobile sidebar ---------- */
        .fieldplx-sidebar-overlay{
            display:none;
        }

        @media(max-width:991.98px){
            html,
            body{
                overflow-x:hidden!important;
            }

            body.fieldplx-sidebar-mobile-open{
                overflow:hidden!important;
            }

            .fieldplx-topbar,
            body.fieldplx-sidebar-collapsed .fieldplx-topbar{
                margin-left:0!important;
                width:100%!important;
            }

            .fieldplx-brand-mobile{
                display:flex!important;
            }

            .fieldplx-main-content,
            body.fieldplx-sidebar-collapsed .fieldplx-main-content{
                width:100%!important;
                margin-left:0!important;
            }

            .fieldplx-footer,
            body.fieldplx-sidebar-collapsed .fieldplx-footer{
                margin-left:0!important;
            }

            .fieldplx-sidebar,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                width:min(300px,calc(100vw - 52px))!important;
                min-width:0!important;
                max-width:300px!important;
                height:100vh!important;
                height:100dvh!important;
                position:fixed!important;
                top:0!important;
                bottom:0!important;
                left:0!important;
                z-index:1060!important;
                display:flex!important;
                flex-direction:column!important;
                overflow:hidden!important;
                visibility:hidden!important;
                transform:translate3d(-100%,0,0)!important;
                box-shadow:none!important;
                transition:transform .25s ease,visibility .25s ease!important;
            }

            body.fieldplx-sidebar-mobile-open .fieldplx-sidebar,
            body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                visibility:visible!important;
                transform:translate3d(0,0,0)!important;
            }

            .fieldplx-sidebar-close{
                display:inline-flex!important;
            }

            .fieldplx-sidebar-brand-text,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-brand-text,
            .fieldplx-sidebar-section-label,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-section-label,
            .fieldplx-sidebar-link-text,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link-text,
            .fieldplx-sidebar-user-details,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user-details{
                display:block!important;
            }

            .fieldplx-sidebar-arrow,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-arrow,
            .fieldplx-sidebar-logout,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-logout{
                display:inline-flex!important;
            }

            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-header,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-link,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-user{
                justify-content:flex-start!important;
            }

            .fieldplx-sidebar-submenu,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-submenu{
                display:block!important;
                max-height:0!important;
                overflow:hidden!important;
                padding-top:0!important;
                padding-bottom:0!important;
            }

            .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open > .fieldplx-sidebar-submenu{
                max-height:680px!important;
                padding-top:4px!important;
                padding-bottom:5px!important;
            }

            .fieldplx-sidebar-overlay{
                position:fixed!important;
                inset:0!important;
                z-index:1055!important;
                display:block!important;
                visibility:hidden!important;
                opacity:0!important;
                pointer-events:none!important;
                background:rgba(0,17,49,.48)!important;
                transition:opacity .25s ease,visibility .25s ease!important;
            }

            body.fieldplx-sidebar-mobile-open .fieldplx-sidebar-overlay{
                visibility:visible!important;
                opacity:1!important;
                pointer-events:auto!important;
            }
        }

        @media(max-width:767.98px){
            :root{
                --fieldplx-topbar-height:64px;
            }

            .fieldplx-topbar,
            .fieldplx-topbar-inner{
                min-height:64px!important;
            }

            .fieldplx-topbar-inner{
                padding:0 13px!important;
            }

            .fieldplx-search-wrap{
                display:none!important;
            }

            .fieldplx-profile-details{
                display:none!important;
            }

            .fieldplx-footer-inner{
                padding:12px!important;
                flex-wrap:wrap!important;
                justify-content:center!important;
                gap:7px 14px!important;
                text-align:center!important;
            }

            .fieldplx-footer-product{
                width:100%!important;
                margin-left:0!important;
            }
        }

        @media(max-width:575.98px){
            .fieldplx-sidebar,
            body.fieldplx-sidebar-collapsed .fieldplx-sidebar{
                width:min(288px,calc(100vw - 44px))!important;
            }

            .fieldplx-sidebar-body{
                padding-left:10px!important;
                padding-right:10px!important;
            }

            .fieldplx-sidebar-link{
                min-height:43px!important;
                padding-left:12px!important;
                padding-right:12px!important;
                gap:12px!important;
                font-size:13px!important;
            }

            .fieldplx-sidebar-submenu{
                padding-left:31px!important;
            }
        }


/* ==========================================================
   FieldPlx New Request - Jobber reference layout v2.0
   Uses the same compact invoice/job form sizing.
   ========================================================== */
:root{--jr-green:#2f8b27;--jr-green-dark:#24751f;--jr-navy:#092d3d;--jr-text:#183548;--jr-muted:#667b8d;--jr-line:#dbe2e7;--jr-soft:#f7f8f8;--jr-warm:#f0eee9}
.jr-page{width:100%;max-width:none;margin:0;background:#fff;min-height:calc(100vh - 70px);padding:24px 28px 92px;color:var(--jr-text);font-family:Arial,Helvetica,sans-serif;font-size:14px}
.jr-head{display:flex;align-items:center;gap:13px;margin:0 0 20px;font-size:22px;font-weight:700;color:#082b3d}.jr-head i{font-size:20px;color:#d17400}
.jr-top{padding-bottom:26px;border-bottom:1px solid var(--jr-line)}
.jr-control{width:100%;height:48px;padding:10px 16px;border:1px solid var(--jr-line);border-radius:8px;background:#fff;color:var(--jr-text);outline:0;font:inherit;font-size:13px;box-shadow:none}.jr-control:focus{border-color:#8da8b5;box-shadow:0 0 0 2px rgba(23,65,84,.08)}
.jr-title{height:49px;margin-bottom:16px;font-size:14px}
.jr-top-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}.jr-client-picker{min-width:0}.jr-requested{display:grid;grid-template-columns:180px 1fr;min-height:50px;align-items:center}.jr-requested-label{color:#557083;font-size:12px}.jr-requested-value{font-size:13px;color:#28485b}
.jr-client-card{display:none;min-height:174px;padding:20px 22px;border:1px solid var(--jr-line);border-radius:8px;background:#fff;position:relative}.jr-client-card.show{display:block}.jr-client-name{font-size:14px;font-weight:700;color:#0b3142;margin-bottom:12px}.jr-client-dot{display:inline-block;width:7px;height:7px;margin-left:5px;border-radius:50%;background:#299bd7}.jr-client-more{position:absolute;right:18px;top:17px;border:0;background:transparent;color:#315166;font-size:18px;cursor:pointer}.jr-client-meta{font-size:12px;line-height:1.5;color:#294b5d}.jr-client-meta a{color:var(--jr-green-dark);text-decoration:underline}.jr-client-actions{display:flex;gap:10px;align-items:center;margin-top:11px}.jr-link{border:0;background:transparent;padding:0;color:var(--jr-green-dark);text-decoration:underline;font-size:12px;font-weight:700;cursor:pointer}.jr-location-select{margin-top:11px}
.jr-section{padding:30px 0;border-bottom:1px solid var(--jr-line)}.jr-section-title{margin:0 0 14px;color:#0b3142;font-size:22px;line-height:1.2;font-weight:700}.jr-subtitle{margin:0 0 8px;font-size:14px;font-weight:700;color:#12384b}.jr-help{margin:0 0 10px;color:#61798a;font-size:12px}.jr-textarea{height:92px;resize:vertical;padding-top:14px}
.jr-upload-label{display:flex;align-items:center;justify-content:space-between;margin:14px 0 7px;color:#567083;font-size:12px}.jr-counter{padding:2px 7px;border-radius:999px;background:#f1f3f4;color:#718493;font-size:10px}.jr-drop{min-height:58px;border:1px dashed #ccd7e1;border-radius:8px;display:flex;align-items:center;justify-content:center;gap:10px;background:#fff;cursor:pointer;color:#398b25}.jr-drop i{font-size:19px}.jr-drop small{color:#61798a}.jr-preview{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.jr-preview-item{width:58px;height:58px;position:relative;border:1px solid var(--jr-line);border-radius:7px;overflow:hidden;background:#f7f8f8}.jr-preview-item img{width:100%;height:100%;object-fit:cover}.jr-preview-item button{position:absolute;right:2px;top:2px;width:18px;height:18px;border:0;border-radius:50%;background:rgba(0,0,0,.65);color:#fff;font-size:10px;cursor:pointer}
.jr-assessment-empty{height:225px;border:1px dashed #cfd9df;border-radius:8px;background:#f0eee9;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;cursor:pointer}.jr-assessment-plus{width:58px;height:58px;border:0;border-radius:50%;background:var(--jr-green);color:#fff;font-size:26px;display:grid;place-items:center;cursor:pointer}.jr-assessment-empty span{font-size:13px;color:#26485b}
.jr-assessment-editor{display:none;border-top:1px solid transparent}.jr-assessment-editor.show{display:block}.jr-assessment-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}.jr-assessment-close{width:34px;height:34px;border:0;background:transparent;color:#294d5e;font-size:21px;cursor:pointer}.jr-assessment-instructions{height:89px;margin-bottom:22px;resize:vertical}.jr-assessment-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px}.jr-assessment-col h3{margin:0 0 16px;color:#0c3043;font-size:15px}.jr-pair{display:grid;grid-template-columns:1fr 1fr}.jr-pair .jr-control:first-child{border-radius:8px 0 0 8px}.jr-pair .jr-control:last-child{margin-left:-1px;border-radius:0 8px 8px 0}.jr-checkline{display:flex;align-items:center;gap:8px;margin:12px 0;color:#35556a;font-size:12px}.jr-checkline input{width:17px;height:17px;accent-color:var(--jr-green)}.jr-field-label{display:block;margin:12px 0 6px;color:#557083;font-size:11px}.jr-capture{display:flex;align-items:flex-start;gap:14px}.jr-capture-icon{width:48px;height:48px;flex:0 0 48px;border-radius:50%;background:#eef1f1;color:#264c5f;display:grid;place-items:center;font-size:21px}.jr-capture-copy strong{display:block;margin-bottom:5px;color:#0c3043;font-size:13px;text-transform:uppercase}.jr-capture-copy span{display:block;color:#4f687a;font-size:12px;line-height:1.45}.jr-checklist-summary{margin-top:11px;padding:10px 12px;border:1px solid #dbe3e7;border-radius:7px;background:#fff;display:none}.jr-checklist-summary.show{display:flex;align-items:center;gap:10px}.jr-checklist-summary i{color:var(--jr-green);font-size:18px}.jr-checklist-summary div{min-width:0;flex:1}.jr-checklist-summary strong,.jr-checklist-summary small{display:block}.jr-checklist-summary strong{font-size:12px;color:#153b4d}.jr-checklist-summary small{margin-top:2px;color:#718493;font-size:10px}.jr-checklist-summary button{border:0;background:transparent;color:#a84545;cursor:pointer}
.jr-card{border:1px solid var(--jr-line);border-radius:8px;background:#fff;overflow:hidden}.jr-card-head{padding:23px 24px 18px}.jr-card-head h2{margin:0 0 14px;font-size:22px;color:#0b3142}.jr-card-head p{margin:0 0 17px;font-size:12px;color:#38566a}.jr-add-line{border:0;background:var(--jr-green);color:#fff;border-radius:6px;padding:9px 14px;font-weight:700;font-size:12px;cursor:pointer}.jr-line-list{padding:0 24px}.jr-line{display:grid;grid-template-columns:minmax(240px,2.5fr) .7fr .9fr .9fr 34px;gap:10px;padding:14px 0;border-top:1px solid #eef1f3;align-items:start}.jr-line:first-child{border-top:0}.jr-line-item{min-width:0}.jr-line .jr-control{height:48px}.jr-money{height:48px;border:1px solid var(--jr-line);border-radius:8px;padding:6px 12px}.jr-money small{display:block;color:#61798a;font-size:9px}.jr-money input{width:100%;border:0;outline:0;background:transparent;color:#17364a;font-size:12px;padding:0}.jr-money strong{font-size:12px}.jr-line-desc{grid-column:1/5;height:88px;resize:vertical}.jr-line-remove{width:34px;height:34px;border:0;background:transparent;color:#78909d;font-size:15px;cursor:pointer}.jr-line-remove:hover{color:#d84b4b}.jr-line-image{grid-column:5;width:100%;height:88px;border:1px dashed #d3dce2;border-radius:8px;display:grid;place-items:center;color:#3a9229;cursor:pointer}.jr-line-image input{display:none}.jr-empty-lines{padding:0 24px 22px;color:#7f8f9a;font-size:11px}.jr-totals{border-top:3px solid #e0e5e8;padding:24px;display:grid;grid-template-columns:1fr minmax(320px,48%)}.jr-total-box{grid-column:2}.jr-total-row{min-height:43px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--jr-line);font-size:12px}.jr-total-row.grand{font-size:14px;font-weight:700;border-bottom:3px solid #e0e5e8}
.jr-notes-title{margin:24px 0 13px;color:#0b3142;font-size:22px}.jr-notes{height:66px;resize:vertical}.jr-file-drop{min-height:88px;margin-top:12px;border:1px dashed #ccd7e1;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:7px;cursor:pointer}.jr-file-drop button{border:1px solid var(--jr-line);background:#fff;color:var(--jr-green-dark);border-radius:7px;padding:7px 12px;font-size:11px;font-weight:700}.jr-file-drop small{color:#61798a;font-size:10px}.jr-file-list{display:grid;gap:6px;margin-top:8px}.jr-file-chip{padding:8px 10px;border:1px solid var(--jr-line);border-radius:7px;display:flex;align-items:center;gap:8px;font-size:11px}.jr-file-chip button{margin-left:auto;border:0;background:transparent;color:#b34c4c;cursor:pointer}
.jr-link-related{padding:24px 0 5px}.jr-link-related-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}.jr-link-related h3{margin:0;font-size:14px;color:#12384b}.jr-link-related-options{display:flex;gap:14px;flex-wrap:wrap}.jr-link-related label{display:flex;align-items:center;gap:7px;font-size:12px}.jr-link-related input{width:17px;height:17px;accent-color:var(--jr-green)}
.jr-sticky{position:fixed;left:var(--fieldplx-sidebar-width);right:0;bottom:0;z-index:1038;height:64px;border-top:1px solid var(--jr-line);background:rgba(255,255,255,.98);display:flex;align-items:center;justify-content:flex-end;padding:9px 28px;box-shadow:0 -3px 10px rgba(0,0,0,.03)}body.fieldplx-sidebar-collapsed .jr-sticky{left:var(--fieldplx-sidebar-collapsed-width)}.jr-btn{height:42px;padding:0 16px;border:1px solid var(--jr-line);border-radius:7px;background:#fff;color:#3a7a2e;font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer}.jr-btn.primary{margin-left:8px;border-color:var(--jr-green);background:var(--jr-green);color:#fff}.jr-btn.primary:disabled{opacity:.6;cursor:not-allowed}
.select2-container{width:100%!important}.select2-container .select2-selection--single{height:48px!important;border:1px solid var(--jr-line)!important;border-radius:8px!important;background:#fff!important}.select2-container .select2-selection--single .select2-selection__rendered{height:46px!important;padding-left:14px!important;padding-right:34px!important;display:flex!important;align-items:center!important;color:#294b5d!important;font-size:12px!important}.select2-container .select2-selection--single .select2-selection__arrow{height:46px!important}.select2-container .select2-selection--multiple{min-height:48px!important;border:1px solid var(--jr-line)!important;border-radius:8px!important;padding:4px!important}.select2-dropdown{z-index:32000!important;border-color:var(--jr-line)!important}.select2-results__option{font-size:12px!important;padding:9px 12px!important}
/* checklist builder copied from the working Job Form reference */
.jb-modal{position:fixed;inset:0;z-index:30000;background:rgba(7,31,49,.35);display:none;align-items:center;justify-content:center;padding:16px}.jb-modal.show{display:flex}.jb-dialog{width:min(1450px,98vw);height:min(860px,96vh);background:#fff;display:flex;flex-direction:column;overflow:hidden;border-radius:8px}.jb-modal-head{height:66px;border-bottom:1px solid var(--jr-line);display:flex;align-items:center;padding:0 20px;gap:12px}.jb-modal-head h2{font-size:20px;margin:0}.jb-modal-actions{margin-left:auto;display:flex;gap:8px}.jb-modal-body{min-height:0;flex:1;display:grid;grid-template-columns:1fr 330px;background:#efede8}.jb-builder-canvas{overflow:auto;padding:14px}.jb-builder-paper{max-width:900px;margin:auto;background:#fff;border-radius:8px;padding:13px}.jb-builder-section{border:1px solid var(--jr-line);border-radius:7px;overflow:hidden;margin-bottom:12px}.jb-builder-sec-head{display:flex;align-items:center;padding:10px;border-bottom:1px solid var(--jr-line)}.jb-builder-sec-head input{border:0;outline:0;font-weight:700;color:#183548;flex:1}.jb-builder-question{padding:10px 12px;background:#fff}.jb-builder-question+.jb-builder-question{border-top:1px solid #eef0f1}.jb-q-top{display:grid;grid-template-columns:1fr 190px 32px;gap:8px}.jb-q-top input,.jb-q-top select,.jb-option-row input,.jb-builder-side input{height:39px;border:1px solid var(--jr-line);border-radius:7px;padding:8px 10px}.jb-options{padding:8px 0 0 30px;display:grid;gap:7px}.jb-option-row{display:grid;grid-template-columns:22px 1fr 32px;gap:7px;align-items:center}.jb-q-foot{display:flex;align-items:center;gap:10px;margin-top:8px;font-size:11px}.jb-builder-side{background:#fff;border-left:1px solid var(--jr-line);padding:18px;overflow:auto}.jb-builder-side h3{margin:0 0 16px;font-size:16px}.jb-palette{display:grid;gap:7px}.jb-palette button{border:0;background:#fff;border-radius:6px;display:flex;align-items:center;gap:10px;padding:6px;cursor:pointer;color:#294b5d}.jb-palette button:hover{background:#f0f1ef}.jb-palette i{width:34px;height:34px;border-radius:7px;background:#efeee9;display:grid;place-items:center;font-size:16px}.jb-icon-btn{width:32px;height:32px;border:0;background:#fff;border-radius:6px;cursor:pointer}.jb-icon-btn:hover{background:#f4f5f5}.jb-modal-foot{height:60px;border-top:1px solid var(--jr-line);display:flex;align-items:center;justify-content:flex-end;padding:0 18px;gap:8px;background:#fff}.jb-cancel{border:1px solid var(--jr-line);background:#fff;color:#3a7a2e;border-radius:7px;padding:9px 15px;font-weight:700;font-size:11px}.jb-save{border:0;background:var(--jr-green);color:#fff;padding:10px 15px;font-weight:700;font-size:11px;border-radius:7px}.jb-builder-link{border:0;background:transparent;padding:0;color:var(--jr-green-dark);text-decoration:underline;font-weight:700;font-size:12px;cursor:pointer}
@media(max-width:991.98px){.jr-page{padding:18px 15px 88px}.jr-top-grid,.jr-assessment-grid{grid-template-columns:1fr}.jr-requested{grid-template-columns:130px 1fr}.jr-sticky,body.fieldplx-sidebar-collapsed .jr-sticky{left:0}.jb-modal-body{grid-template-columns:1fr}.jb-builder-side{display:none}.jr-line{grid-template-columns:1.7fr .65fr .85fr .85fr 32px}}
@media(max-width:700px){.jr-top-grid{gap:12px}.jr-line{grid-template-columns:1fr 1fr}.jr-line-item{grid-column:1/-1}.jr-line-desc{grid-column:1/-1}.jr-line-image{grid-column:1/-1;height:70px}.jr-line-remove{grid-column:2}.jr-totals{grid-template-columns:1fr}.jr-total-box{grid-column:1}.jr-pair{grid-template-columns:1fr}.jr-pair .jr-control:first-child,.jr-pair .jr-control:last-child{border-radius:8px;margin-left:0;margin-bottom:8px}.jr-sticky{padding:9px 14px}}


/* ==========================================================
   Add Request v3 - Add Invoice UI alignment
   Customer / Product-Service / Notes + matching typography
   ========================================================== */
:root{
  --jr-green:#2f8d25;
  --jr-green-dark:#24751d;
  --jr-green-soft:#f2f8ee;
  --jr-text:#0b2b37;
  --jr-muted:#5f7380;
  --jr-line:#dce4e8;
}
body{background:#fff!important;color:var(--jr-text)!important;font-family:Arial,Helvetica,sans-serif!important;font-size:14px!important}
.jr-page{background:#fff;padding:24px 28px 88px;color:var(--jr-text);font-family:Arial,Helvetica,sans-serif;font-size:14px}
.jr-head{gap:12px;margin-bottom:18px;color:var(--jr-text);font-size:24px;line-height:1.2;font-weight:700}
.jr-head i{font-size:22px;color:#2b75ac}
.jr-top{padding-bottom:30px}
.jr-control{height:43px;padding:8px 12px;border:1px solid var(--jr-line);border-radius:7px;color:#163644;background:#fff;font-family:inherit;font-size:14px}
.jr-control:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.09)}
.jr-title{height:43px;margin-bottom:14px;padding:8px 12px;font-size:16px}
.jr-top-grid{grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr);gap:22px}
.jr-requested{grid-template-columns:180px minmax(0,1fr);min-height:44px;border-bottom:1px solid var(--jr-line)}
.jr-requested-label{color:#607582;font-size:14px}.jr-requested-value{color:#153442;font-size:15px}
.jr-section{padding:26px 0}.jr-section-title,.jr-card-head h2,.jr-notes-title{font-size:20px;color:var(--jr-text)}
.jr-subtitle{font-size:14px;color:#12384b}.jr-help,.jr-card-head p{font-size:13px;color:var(--jr-muted)}
.jr-textarea{min-height:96px;height:auto;padding:13px 12px;font-size:14px;line-height:1.5}

/* Customer picker/card matches Add Invoice */
.jr-customer-picker{max-width:640px}
.jr-customer-picker .select2-container .select2-selection--single,
.jr-location-picker .select2-container .select2-selection--single{height:46px!important;border-radius:8px!important}
.jr-customer-picker .select2-container .select2-selection__rendered,
.jr-location-picker .select2-container .select2-selection__rendered{height:44px!important;line-height:44px!important;padding-left:13px!important;font-size:14px!important}
.jr-customer-picker .select2-selection__arrow,.jr-location-picker .select2-selection__arrow{height:44px!important}
.jr-client-card{display:none;position:relative;min-height:226px;padding:20px 22px;border:1px solid #d9e1e5;border-radius:8px;background:#fff;color:#173846}
.jr-client-card.show{display:block}
.jr-customer-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:13px}
.jr-client-name{display:flex;align-items:center;gap:6px;margin:0;color:#113448;font-size:16px;font-weight:700;line-height:1.25}
.jr-client-dot{width:7px;height:7px;margin:0;border-radius:50%;background:#2f8d25;display:inline-block;flex:0 0 7px}
.jr-client-menu-wrap{position:relative}.jr-client-more{position:static;width:34px;height:30px;border:0;border-radius:6px;background:transparent;color:#274b5b;display:grid;place-items:center;cursor:pointer;font-size:19px;font-weight:700;line-height:1}
.jr-client-more:hover{background:#f3f7f8}.jr-client-menu{display:none;position:absolute;right:0;top:34px;z-index:1210;width:190px;padding:6px;border:1px solid var(--jr-line);border-radius:8px;background:#fff;box-shadow:0 12px 28px rgba(0,17,49,.15)}
.jr-client-menu.show{display:block}.jr-client-menu button{width:100%;padding:9px 10px;border:0;border-radius:6px;background:#fff;color:#36535f;text-align:left;font:inherit;font-size:13px;cursor:pointer}.jr-client-menu button:hover{background:#f5faf2;color:var(--jr-green-dark)}
.jr-customer-block{margin-top:12px}.jr-customer-block-label{display:block;margin-bottom:3px;color:#5e7581;font-size:13px}.jr-customer-block-value{display:block;color:#173846;font-size:14px;line-height:1.35;white-space:pre-line}
.jr-customer-contact{margin-top:12px;display:grid;gap:3px}.jr-customer-contact a{width:max-content;max-width:100%;overflow:hidden;text-overflow:ellipsis;color:var(--jr-green-dark)!important;font-size:13px;text-decoration:underline!important}
.jr-location-picker{display:none;max-width:640px;margin-top:10px}.jr-location-picker.show{display:block}

/* Select2 matches Add Invoice */
.select2-container{width:100%!important}.select2-container .select2-selection--single{height:43px!important;border:1px solid var(--jr-line)!important;border-radius:7px!important;background:#fff!important}
.select2-container .select2-selection--single .select2-selection__rendered{height:41px!important;line-height:41px!important;padding-left:12px!important;padding-right:28px!important;display:block!important;overflow:hidden!important;white-space:nowrap!important;text-overflow:ellipsis!important;color:#183845!important;font-size:14px!important}
.select2-container .select2-selection--single .select2-selection__arrow{height:41px!important}.select2-container--focus .select2-selection--single,.select2-container--open .select2-selection--single{border-color:#91bd7e!important;box-shadow:0 0 0 2px rgba(47,141,37,.08)!important}
.select2-dropdown{border:1px solid var(--jr-line)!important;border-radius:7px!important;overflow:hidden!important;box-shadow:0 12px 24px rgba(0,17,49,.12)!important}.select2-search__field{height:34px!important;border:1px solid var(--jr-line)!important;border-radius:6px!important;font-size:14px!important}.select2-results__option{padding:8px 10px!important;font-size:13.5px!important}.select2-results__option--highlighted[aria-selected]{background:#eaf5e5!important;color:#244f1c!important}
.select2-container--default .select2-selection--multiple{min-height:43px!important;border:1px solid var(--jr-line)!important;border-radius:7px!important;font-size:14px!important}.select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#91bd7e!important;box-shadow:0 0 0 2px rgba(47,141,37,.08)!important}
.select2-container--default .select2-selection--multiple{padding:4px 8px!important;background:#fff!important}.select2-container--default .select2-selection--multiple .select2-selection__rendered{display:flex!important;align-items:center!important;flex-wrap:wrap!important;gap:4px!important;margin:0!important;padding:0!important}.select2-container--default .select2-selection--multiple .select2-selection__choice{margin:0!important;padding:3px 8px 3px 24px!important;border:1px solid #dbe5d5!important;border-radius:999px!important;background:#f1f7ed!important;color:#315445!important;font-size:12px!important;line-height:20px!important;position:relative!important}.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{position:absolute!important;left:7px!important;top:50%!important;transform:translateY(-50%)!important;border:0!important;color:#718692!important;background:transparent!important}.select2-container--default .select2-selection--multiple .select2-search--inline .select2-search__field{height:30px!important;margin:0!important;padding:4px 2px!important;border:0!important;box-shadow:none!important;font-size:14px!important}.jr-assessment-col .select2-container{max-width:100%!important}.jr-assessment-col .select2-selection--multiple{min-height:48px!important}

/* Product / Service dropdown and rows match Add Invoice */
.jr-card{border:1px solid var(--jr-line);border-radius:8px}.jr-card-head{padding:22px 20px 18px}.jr-card-head h2{margin-bottom:17px}.jr-add-line{height:34px;padding:0 13px;border:1px solid var(--jr-green);border-radius:7px;background:var(--jr-green);font-size:13px}.jr-add-line:hover{background:var(--jr-green-dark)}
.jr-line-list{padding:0 20px}.jr-line{grid-template-columns:minmax(280px,1fr) 130px 170px 170px 42px;gap:12px;padding:16px 0 18px}.jr-line-item{min-width:0}.jr-line .select2-container .select2-selection--single{height:48px!important}.jr-line .select2-container .select2-selection__rendered{height:46px!important;line-height:46px!important;padding-left:12px!important;font-size:14px!important}.jr-line .select2-selection__arrow{height:46px!important}
.jr-money{height:48px;padding:7px 10px;border:1px solid var(--jr-line);border-radius:7px}.jr-money small{color:#718692;font-size:11px}.jr-money input,.jr-money strong{font-size:14px;color:#183845}.jr-line-desc{grid-column:1/5;min-height:84px;height:auto;padding:13px 12px;font-size:14px;line-height:1.45}.jr-line-image{height:84px;border-color:#cbd7dd}.jr-line-remove{width:42px;height:48px;border-radius:7px}.jr-empty-lines{padding:0 20px 22px;font-size:14px}.jr-totals{padding:18px 20px 22px;grid-template-columns:1fr minmax(420px,52%)}.jr-total-row{min-height:43px;color:#445f6d;font-size:14px}.jr-total-row.grand{min-height:48px;font-size:14px}.jr-total-row.grand span:last-child{font-size:20px}
.jr-catalog-result{display:flex;align-items:flex-start;gap:10px;padding:2px 0}.jr-catalog-copy{min-width:0;flex:1}.jr-catalog-name{display:flex;align-items:center;gap:8px;color:#284755}.jr-catalog-desc{margin-top:3px;color:#6b808b;line-height:1.35}.jr-catalog-price{margin-left:auto;white-space:nowrap;color:#284755}.jr-type-badge{display:inline-flex;align-items:center;min-height:20px;padding:2px 7px;border-radius:999px;font-weight:700;line-height:1}.jr-type-badge.service{color:#28701f;background:#edf7e9;border:1px solid #cae6c2}.jr-type-badge.product{color:#245d83;background:#eef6fb;border:1px solid #c9deeb}.jr-type-badge.other{color:#526b78;background:#f4f7f8;border:1px solid #dce4e8}

/* Notes matches Add Invoice including @mention dropdown */
.jr-notes-wrap{padding-top:22px}.jr-notes-title{margin:0 0 12px}.jr-note-editor{position:relative}.jr-notes{width:100%;min-height:78px;height:auto;padding:13px;border:1px solid #79ad64;border-radius:8px;background:#fff;color:#183845;font-family:inherit;font-size:14px;line-height:1.5;resize:vertical;outline:0}.jr-notes:focus{border-color:#5f9d45;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
.jr-mention-menu{display:none;position:absolute;left:10px;top:calc(100% - 3px);z-index:1300;width:min(320px,calc(100% - 20px));max-height:220px;overflow:auto;padding:4px 0;border:1px solid #d8e0e4;border-radius:7px;background:#fff;box-shadow:0 8px 22px rgba(0,17,49,.16)}.jr-mention-menu.show{display:block}.jr-mention-option{width:100%;min-height:42px;padding:8px 12px;display:flex;align-items:center;gap:9px;border:0;background:#fff;color:#314f5d;text-align:left;cursor:pointer}.jr-mention-option:hover,.jr-mention-option.active{background:#f1f0ed}.jr-mention-avatar{width:28px;height:28px;flex:0 0 28px;display:grid;place-items:center;border-radius:50%;background:#edf6e8;color:#2f8d25;font-size:11px;font-weight:700}.jr-mention-copy{min-width:0;flex:1}.jr-mention-name{display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-size:13px}.jr-mention-role{display:block;margin-top:2px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#7b8d96;font-size:11px}.jr-note-help{margin:7px 0 0;color:#6d818c;font-size:12px}
.jr-file-drop{margin-top:13px;min-height:80px;padding:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;border:1px dashed #d5dde1;border-radius:8px;background:#fff;text-align:center}.jr-file-drop.dragover{border-color:#79ad64;background:#f8fcf6}.jr-file-drop button{height:32px;padding:0 13px;border:1px solid #d7dfe3;border-radius:7px;background:#fff;color:var(--jr-green-dark);font-family:inherit;font-size:13px;font-weight:700}.jr-file-drop button:hover{border-color:#a9cc98;background:#f8fcf6}.jr-file-drop small{color:#617783;font-size:11px}.jr-file-list{margin-top:9px;display:grid;gap:7px}.jr-file-chip{min-height:39px;padding:7px 10px;border:1px solid #e3e9ec;border-radius:7px;background:#fbfcfc;color:#405c69;font-size:13px}.jr-file-chip i{color:#61808d}.jr-file-chip small{color:#8899a2}
.jr-sticky{height:64px;padding:10px 28px}.jr-btn{height:38px;padding:0 14px;border-radius:7px;font-size:14px}.jr-btn.primary{border-color:var(--jr-green);background:var(--jr-green)}.jr-btn.primary:hover{background:var(--jr-green-dark)}
@media(max-width:991.98px){.jr-top-grid{grid-template-columns:1fr}.jr-totals{grid-template-columns:1fr}.jr-total-box{grid-column:1}.jr-line{grid-template-columns:minmax(220px,1fr) 110px 140px 140px 42px}}
@media(max-width:767.98px){.jr-page{padding:18px 14px 88px}.jr-line{grid-template-columns:1fr 1fr}.jr-line-item,.jr-line-desc{grid-column:1/-1}.jr-line-image{grid-column:1/-1}.jr-top-grid{gap:14px}.jr-requested{grid-template-columns:120px 1fr}.jr-sticky{padding-left:14px;padding-right:14px}}
@media(max-width:575.98px){.jr-line{grid-template-columns:1fr}.jr-line-item,.jr-line-desc,.jr-line-image{grid-column:auto}.jr-requested{grid-template-columns:1fr;gap:4px;padding:8px 0}.jr-client-card{padding:17px 16px}}

/* Quick-create controls: same visual language as Add Invoice */
.jr-create-option{display:flex;align-items:center;gap:8px;color:var(--jr-green-dark);font-size:13.5px;font-weight:700}.jr-create-option i{font-size:14px}.jr-create-option span{line-height:1.25}
.jr-quick-modal{position:fixed;inset:0;z-index:35000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,17,49,.34);font-family:Arial,Helvetica,sans-serif}.jr-quick-modal.show{display:flex}.jr-quick-dialog{width:100%;max-width:620px;max-height:calc(100vh - 40px);overflow:auto;border-radius:10px;background:#fff;box-shadow:0 22px 60px rgba(0,17,49,.22)}.jr-catalog-create .jr-quick-dialog{max-width:720px}
.jr-quick-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:22px 24px 12px}.jr-quick-title{margin:0;color:var(--jr-text);font-size:23px;line-height:1.2;font-weight:700}.jr-catalog-create .jr-quick-title{font-size:24px}.jr-quick-close{width:34px;height:34px;padding:0;display:grid;place-items:center;border:0;border-radius:7px;background:transparent;color:#607783;font-size:19px;cursor:pointer}.jr-quick-close:hover{background:#f2f5f6;color:#173846}
.jr-quick-body{padding:8px 24px 10px}.jr-quick-footer{padding:12px 24px 22px;display:flex;justify-content:flex-end;gap:8px}.jr-quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.jr-quick-grid .full{grid-column:1/-1}.jr-modal-field{position:relative}.jr-modal-field label{display:block;margin:0 0 6px;color:#5d7380;font-size:12px;font-weight:500}.jr-modal-field input,.jr-modal-field select,.jr-modal-field textarea{width:100%;height:43px;padding:8px 12px;border:1px solid var(--jr-line);border-radius:7px;background:#fff;color:#183845;font:14px Arial,Helvetica,sans-serif;outline:0}.jr-modal-field textarea{height:88px;padding-top:11px;resize:vertical;line-height:1.45}.jr-modal-field input:focus,.jr-modal-field select:focus,.jr-modal-field textarea:focus{border-color:#91bd7e;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
.jr-new-item-costs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;margin-top:14px}.jr-new-item-costs .jr-modal-field input{border-radius:0}.jr-new-item-costs .jr-modal-field:first-child input{border-radius:8px 0 0 8px}.jr-new-item-costs .jr-modal-field:last-child input{border-radius:0 8px 8px 0}.jr-new-item-tax{display:grid;grid-template-columns:180px 1fr;align-items:end;gap:14px;margin-top:14px}.jr-tax-exempt{min-height:43px;display:flex;align-items:center;gap:8px;color:#334f5c;font-size:14px;cursor:pointer}.jr-tax-exempt input{width:19px;height:19px;accent-color:var(--jr-green)}
.jr-modal-btn{height:38px;padding:0 14px;border:1px solid var(--jr-line);border-radius:7px;background:#fff;color:#31505d;font:700 14px Arial,Helvetica,sans-serif;cursor:pointer}.jr-modal-btn.primary{border-color:var(--jr-green);background:var(--jr-green);color:#fff}.jr-modal-btn.primary:hover{background:var(--jr-green-dark)}.jr-modal-btn:disabled{opacity:.55;cursor:not-allowed}
body.jr-modal-open{overflow:hidden}
@media(max-width:767.98px){.jr-quick-modal{padding:12px}.jr-quick-head{padding:18px 16px 10px}.jr-quick-body{padding:8px 16px 10px}.jr-quick-footer{padding:12px 16px 18px}.jr-quick-grid{grid-template-columns:1fr}.jr-quick-grid .full{grid-column:auto}.jr-new-item-costs{grid-template-columns:1fr;gap:12px}.jr-new-item-costs .jr-modal-field input,.jr-new-item-costs .jr-modal-field:first-child input,.jr-new-item-costs .jr-modal-field:last-child input{border-radius:7px}.jr-new-item-tax{grid-template-columns:1fr}}



/* ==========================================================
   FINAL FIX: Fixed FieldPlx navbar for Add/Edit pages
   Keep this block at the END of the page stylesheet so it
   overrides every earlier/sticky topbar declaration.
   ========================================================== */
.fieldplx-topbar {
    position: fixed !important;
    top: 0 !important;
    right: 0 !important;
    left: var(--fieldplx-sidebar-width) !important;
    width: auto !important;
    margin-left: 0 !important;
    z-index: 1030 !important;
}

body.fieldplx-sidebar-collapsed .fieldplx-topbar {
    left: var(--fieldplx-sidebar-collapsed-width) !important;
    width: auto !important;
    margin-left: 0 !important;
}

/* The fixed navbar is removed from normal flow, so reserve its height. */
.fieldplx-main-layout {
    padding-top: var(--fieldplx-topbar-height) !important;
    min-height: 100vh !important;
}

@media (max-width: 991.98px) {
    .fieldplx-topbar,
    body.fieldplx-sidebar-collapsed .fieldplx-topbar {
        left: 0 !important;
        right: 0 !important;
        width: auto !important;
        margin-left: 0 !important;
    }
}

</style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fieldplx-main-content"><div class="fieldplx-content-wrapper"><div class="jr-page">
<form id="requestForm" enctype="multipart/form-data">
<input type="hidden" name="source" value="office">
<input type="hidden" name="priority" value="normal">
<input type="hidden" name="status" id="status" value="new">
<input type="hidden" name="product_service_id" id="primaryServiceId" value="">
<input type="hidden" name="branch_id" id="branchId" value="">
<input type="hidden" name="assessment_enabled" id="assessmentEnabled" value="0">
<input type="hidden" name="new_checklist_json" id="newChecklistJson" value="">
<input type="hidden" name="line_items_json" id="lineItemsJson" value="[]">
<input type="hidden" name="notify_in_app" value="1">
<input type="hidden" name="assignment_mode" value="multiple">
<input type="hidden" name="notify_email" id="notifyEmail" value="0">

<div class="jr-head"><i class="bi bi-inbox-arrow-down"></i><span>New Request</span></div>
<div class="jr-top">
  <input class="jr-control jr-title" type="text" name="title" id="title" maxlength="190" placeholder="Title" required>
  <div class="jr-top-grid">
    <div class="jr-client-picker">
      <div class="jr-customer-picker" id="clientSelectWrap"><select name="client_id" id="clientId" class="jr-select2" required><option value=""></option></select></div>
      <div class="jr-client-card" id="clientCard">
        <div class="jr-customer-card-head">
          <div class="jr-client-name"><span id="clientName">Customer</span><span class="jr-client-dot" aria-hidden="true"></span></div>
          <div class="jr-client-menu-wrap">
            <button type="button" class="jr-client-more" id="clientMenuButton" aria-label="Customer options">...</button>
            <div class="jr-client-menu" id="clientMenu">
              <button type="button" id="changeClient">Change customer</button>
              <button type="button" id="changeLocation">Change location</button>
            </div>
          </div>
        </div>
        <div class="jr-customer-block"><span class="jr-customer-block-label">Billing Address</span><span class="jr-customer-block-value" id="clientBillingAddress">-</span></div>
        <div class="jr-customer-block"><span class="jr-customer-block-label">Property Address</span><span class="jr-customer-block-value" id="clientPropertyAddress">-</span></div>
        <div class="jr-customer-contact"><a href="#" id="clientPhone" style="display:none"></a><a href="#" id="clientEmail" style="display:none"></a></div>
      </div>
      <div class="jr-location-picker" id="locationPickerWrap"><select name="location_id" id="locationId" class="jr-select2"><option value=""></option></select></div>
    </div>
    <div class="jr-requested"><div class="jr-requested-label">Requested on</div><div class="jr-requested-value" id="requestedOn"></div></div>
  </div>
</div>

<section class="jr-section">
  <h2 class="jr-section-title">Overview</h2>
  <div class="jr-subtitle">Service details</div>
  <p class="jr-help">Please provide as much information as you can</p>
  <textarea class="jr-control jr-textarea" name="description" id="description" maxlength="5000"></textarea>
  <div class="jr-upload-label"><span>Share images of the work to be done</span><span class="jr-counter" id="imageCounter">0/10</span></div>
  <label class="jr-drop" id="imageDrop"><i class="bi bi-image"></i><span>Select or drag images here</span><input type="file" id="requestImages" name="request_images[]" accept="image/*" multiple hidden></label>
  <div class="jr-preview" id="imagePreview"></div>
</section>

<section class="jr-section">
  <div class="jr-assessment-head"><h2 class="jr-section-title" style="margin:0">On-site assessment</h2><button type="button" class="jr-assessment-close" id="removeAssessment" style="display:none" aria-label="Remove assessment"><i class="bi bi-x-lg"></i></button></div>
  <div class="jr-assessment-empty" id="assessmentEmpty"><button type="button" class="jr-assessment-plus" id="addAssessment"><i class="bi bi-plus-lg"></i></button><span>Visit the property to assess the job before you do the work</span></div>
  <div class="jr-assessment-editor" id="assessmentEditor">
    <textarea class="jr-control jr-assessment-instructions" name="assessment_instructions" id="assessmentInstructions" placeholder="Instructions"></textarea>
    <div class="jr-assessment-grid">
      <div class="jr-assessment-col">
        <h3>Schedule</h3>
        <div class="jr-pair"><input class="jr-control" type="date" name="assessment_start_date" id="assessmentStartDate" disabled><input class="jr-control" type="date" name="assessment_end_date" id="assessmentEndDate" disabled></div>
        <label class="jr-checkline"><input type="checkbox" name="assessment_schedule_later" id="scheduleLater" value="1" checked> Schedule later</label>
        <div class="jr-pair"><input class="jr-control" type="time" name="assessment_start_time" id="assessmentStartTime" disabled><input class="jr-control" type="time" name="assessment_end_time" id="assessmentEndTime" disabled></div>
        <label class="jr-checkline"><input type="checkbox" name="assessment_anytime" id="assessmentAnytime" value="1"> Anytime</label>
      </div>
      <div class="jr-assessment-col">
        <h3>Team</h3>
        <select name="employee_ids[]" id="assessmentUsers" class="jr-select2-multiple" multiple></select>
        <label class="jr-checkline"><input type="checkbox" name="email_team_when_assigned" id="emailTeam" value="1"> Email team when assigned</label>
        <span class="jr-field-label">Team reminder</span>
        <select class="jr-control" name="team_reminder" id="teamReminder"><option value="none">No reminder set</option><option value="30_minutes">30 minutes before</option><option value="1_hour">1 hour before</option><option value="1_day">1 day before</option></select>
      </div>
      <div class="jr-assessment-col">
        <h3>Checklists</h3>
        <div class="jr-capture"><div class="jr-capture-icon"><i class="bi bi-clipboard2-check"></i></div><div class="jr-capture-copy"><strong>Capture on-site details</strong><span>Attach custom-built checklists so that nothing gets missed</span><button type="button" class="jr-link js-open-checklist" style="margin-top:11px">Create a Checklist</button></div></div>
        <div class="jr-checklist-summary" id="checklistSummary"><i class="bi bi-list-check"></i><div><strong id="checklistSummaryName">New checklist</strong><small id="checklistSummaryCount">0 questions</small></div><button type="button" id="removeChecklist" title="Remove checklist"><i class="bi bi-trash"></i></button></div>
      </div>
    </div>
  </div>
</section>

<section class="jr-section">
  <div class="jr-card">
    <div class="jr-card-head"><h2>Product / Service</h2><p>Keep everything on track by adding products and services.</p><button type="button" class="jr-add-line" id="addLineItem">Add Line Item</button></div>
    <div class="jr-line-list" id="lineItems"></div>
    <div class="jr-empty-lines" id="emptyLines">No line items added yet.</div>
    <div class="jr-totals"><div></div><div class="jr-total-box"><div class="jr-total-row"><span>Subtotal</span><span id="subtotal">0.00</span></div><div class="jr-total-row grand"><span>Total</span><span id="total">0.00</span></div></div></div>
  </div>

  <div class="jr-notes-wrap">
    <h2 class="jr-notes-title">Notes</h2>
    <div class="jr-note-editor">
      <textarea class="jr-notes" name="internal_note" id="internalNote" maxlength="10000" autocomplete="off" placeholder="Use @ in notes to mention your team"></textarea>
      <div class="jr-mention-menu" id="noteMentionMenu" role="listbox"></div>
    </div>
    <input type="hidden" name="note_mentions_json" id="noteMentionsJson" value="[]">
    <p class="jr-note-help">Type @ and select an employee. The mention is saved inside this request note.</p>
    <div class="jr-file-drop" id="fileDrop" role="button" tabindex="0">
      <button type="button" id="noteAttachButton">Attach files &amp; photos</button>
      <small>Select or drag files here to upload</small>
      <input type="file" id="requestAttachments" name="request_attachments[]" accept="image/avif,image/jpeg,image/png,image/webp,image/heic,.heic,.pdf,.doc,.docx" multiple hidden>
    </div>
    <div class="jr-file-list" id="fileList"></div>
  </div>

  <div class="jr-link-related"><div class="jr-link-related-head"><h3>Link to related</h3><i class="bi bi-chevron-up"></i></div><div class="jr-link-related-options"><label><input type="checkbox" name="link_quotes" value="1" checked> Quotes</label><label><input type="checkbox" name="link_jobs" value="1" checked> Jobs</label><label><input type="checkbox" name="link_invoices" value="1" checked> Invoices</label></div></div>
</section>
</form>
</div></div></main></div>

<div class="jr-sticky"><a href="requests.php" class="jr-btn">Cancel</a><button type="submit" form="requestForm" class="jr-btn primary" id="saveButton">Save Request</button></div>

<div class="jr-quick-modal" id="createCustomerModal" aria-hidden="true">
  <div class="jr-quick-dialog" role="dialog" aria-modal="true" aria-labelledby="createCustomerTitle">
    <div class="jr-quick-head"><h2 class="jr-quick-title" id="createCustomerTitle">Create Customer</h2><button type="button" class="jr-quick-close" data-close-quick="createCustomerModal" aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="jr-quick-body">
      <div class="jr-quick-grid">
        <div class="jr-modal-field full"><label for="newCustomerName">Customer name</label><input id="newCustomerName" type="text" maxlength="190"></div>
        <div class="jr-modal-field full"><label for="newCustomerCompany">Company name</label><input id="newCustomerCompany" type="text" maxlength="190"></div>
        <div class="jr-modal-field"><label for="newCustomerPhone">Phone</label><input id="newCustomerPhone" type="text" maxlength="50"></div>
        <div class="jr-modal-field"><label for="newCustomerEmail">Email</label><input id="newCustomerEmail" type="email" maxlength="190"></div>
      </div>
    </div>
    <div class="jr-quick-footer"><button type="button" class="jr-modal-btn" data-close-quick="createCustomerModal">Cancel</button><button type="button" class="jr-modal-btn primary" id="createCustomerButton">Create Customer</button></div>
  </div>
</div>

<div class="jr-quick-modal jr-catalog-create" id="catalogItemModal" aria-hidden="true">
  <div class="jr-quick-dialog" role="dialog" aria-modal="true" aria-labelledby="catalogItemTitle">
    <div class="jr-quick-head"><h2 class="jr-quick-title" id="catalogItemTitle">Add Product / Service</h2><button type="button" class="jr-quick-close" data-close-quick="catalogItemModal" aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="jr-quick-body">
      <div class="jr-modal-field" style="margin-bottom:14px"><label for="newItemType">Item type</label><select id="newItemType"><option value="service">Service</option><option value="product">Product</option></select></div>
      <div class="jr-modal-field" style="margin-bottom:14px"><label for="newItemName">Name</label><input id="newItemName" type="text" maxlength="190"></div>
      <div class="jr-modal-field"><label for="newItemDescription">Description</label><textarea id="newItemDescription"></textarea></div>
      <div class="jr-new-item-costs">
        <div class="jr-modal-field"><label for="newItemUnitCost">Unit cost</label><input id="newItemUnitCost" type="number" min="0" step="0.01" value="0.00"></div>
        <div class="jr-modal-field"><label for="newItemMarkup">Markup (%)</label><input id="newItemMarkup" type="number" min="0" step="0.01" value="0"></div>
        <div class="jr-modal-field"><label for="newItemUnitPrice">Unit price</label><input id="newItemUnitPrice" type="number" min="0" step="0.01" value="0.00"></div>
      </div>
      <div class="jr-new-item-tax">
        <div class="jr-modal-field"><label for="newItemTaxPercent">Tax (%)</label><input id="newItemTaxPercent" type="number" min="0" max="100" step="0.0001" value="0"></div>
        <label class="jr-tax-exempt"><input id="newItemTaxExempt" type="checkbox"><span>Exempt from Tax</span></label>
      </div>
    </div>
    <div class="jr-quick-footer"><button type="button" class="jr-modal-btn" data-close-quick="catalogItemModal">Cancel</button><button type="button" class="jr-modal-btn primary" id="createCatalogItemButton">Create</button></div>
  </div>
</div>

<div class="jb-modal" id="checklistModal"><div class="jb-dialog"><div class="jb-modal-head"><h2 id="checklistModalTitle">Edit New Checklist</h2><div class="jb-modal-actions"><button class="jb-cancel" type="button" id="cancelChecklist">Cancel</button><button class="jb-save" type="button" id="saveChecklist">Save</button></div></div><div class="jb-modal-body"><div class="jb-builder-canvas"><div class="jb-builder-paper" id="builderCanvas"></div></div><aside class="jb-builder-side"><h3>Manage checklist</h3><div style="margin-bottom:18px"><label style="font-size:10px;color:#607688">Form title</label><input id="checklistName" value="New checklist" style="width:100%;margin-top:6px"></div><h3 style="font-size:13px">Checklist contents</h3><div class="jb-palette"><button type="button" data-add-type="section"><i class="bi bi-file-earmark-plus"></i> Add section</button><button type="button" data-add-type="short_answer"><i class="bi bi-list"></i> Short answer</button><button type="button" data-add-type="long_answer"><i class="bi bi-text-paragraph"></i> Long answer</button><button type="button" data-add-type="dropdown"><i class="bi bi-chevron-circle-down"></i> Dropdown (single choice)</button><button type="button" data-add-type="checkbox"><i class="bi bi-check-square"></i> Checkbox</button><button type="button" data-add-type="number"><i class="bi bi-hash"></i> Numerical answer</button><button type="button" data-add-type="image"><i class="bi bi-image"></i> Upload images</button><button type="button" data-add-type="date"><i class="bi bi-calendar"></i> Date picker</button><button type="button" data-add-type="signature"><i class="bi bi-pen"></i> Signature</button></div></aside></div><div class="jb-modal-foot"><button class="jb-cancel" type="button" id="modalClose">Cancel</button><button class="jb-save" type="button" id="modalApply">Save Checklist</button></div></div></div>

<?php require_once __DIR__ . '/includes/toast.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){'use strict';
var csrf=<?= json_encode($addRequestCsrfToken) ?>;
var api='api/request-form.php';
var form=document.getElementById('requestForm');
var saveButton=document.getElementById('saveButton');
var meta={clients:[],locations:[],branches:[],catalog:[],users:[],currency:{symbol:'',symbol_position:'before',decimal_places:2}};
var lines=[],lineSeq=0,selectedImages=[],selectedFiles=[],noteMentionIds=[],mentionMatches=[],mentionActiveIndex=0;
var catalogTargetRow=null,catalogPriceTouched=false;
var builder=[{title:'Section 1',questions:[{title:'Question',type:'short_answer',required:0,options:[]}]}];
var dirty=false,submitting=false;
function E(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function toast(type,msg,duration){if(typeof window.fieldplxToast==='function'){window.fieldplxToast(type,msg,duration||4200);return}console.log('[FieldPlx toast]',type,msg)}
function req(fd){fd.append('csrf_token',csrf);return fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(function(r){return r.text().then(function(raw){var d;try{d=JSON.parse(raw)}catch(e){throw new Error((raw||'').replace(/<[^>]+>/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d})})}
function money(v){var p=parseInt((meta.currency||{}).decimal_places,10);if(isNaN(p))p=2;var n=Number(v||0).toFixed(p),s=(meta.currency||{}).symbol||'';return (meta.currency||{}).symbol_position==='after'?n+(s?' '+s:''):(s||'')+n}
function dateLabel(d){return d.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'})}
E('requestedOn').textContent=dateLabel(new Date());
function clientById(id){id=Number(id||0);return (meta.clients||[]).find(function(x){return Number(x.id)===id})||null}
function locationById(id){id=Number(id||0);return (meta.locations||[]).find(function(x){return Number(x.id)===id})||null}
function catalogById(id){id=Number(id||0);return (meta.catalog||[]).find(function(x){return Number(x.id)===id})||null}
function optionRows(rows,first){var h='<option value="">'+esc(first)+'</option>';(rows||[]).forEach(function(x){h+='<option value="'+Number(x.id)+'">'+esc(x.name||x.display_name||('Item #'+x.id))+'</option>'});return h}
function initSelect(el,opts){var $e=$(el);if($e.hasClass('select2-hidden-accessible'))$e.select2('destroy');$e.select2(Object.assign({width:'100%'},opts||{}))}
function createOptionNode(label){var n=document.createElement('div');n.className='jr-create-option';var ic=document.createElement('i');ic.className='bi bi-plus-lg';var tx=document.createElement('span');tx.textContent=label;n.appendChild(ic);n.appendChild(tx);return $(n)}
function customerOptionsHtml(){var h='<option value=""></option>';(meta.clients||[]).forEach(function(c){h+='<option value="'+Number(c.id)+'">'+esc((c.name||c.display_name||'Customer')+(c.company_name?' - '+c.company_name:'')+(c.phone?' - '+c.phone:''))+'</option>'});return h}
function customerResult(item){if(!item.id)return item.text;var id=String(item.id||'');if(id.indexOf('newclient:')===0)return createOptionNode('Create new customer');return item.text}
function initCustomerSelect(selected){E('clientId').innerHTML=customerOptionsHtml();initSelect(E('clientId'),{placeholder:'Select a customer',allowClear:true,tags:true,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term)return null;var exact=(meta.clients||[]).some(function(c){return String(c.name||c.display_name||'').toLowerCase()===term.toLowerCase()});return exact?null:{id:'newclient:'+term,text:term,newTag:true}},templateResult:customerResult});if(Number(selected)>0)$('#clientId').val(String(selected)).trigger('change.select2')}
function formatAddress(x){if(!x)return '';return [x.address_line1,x.address_line2,x.city,x.state,x.postal_code].filter(function(v){return String(v||'').trim()!==''}).join(', ')}
function primaryLocation(){var id=Number((clientById(E('clientId').value)||{}).primary_location_id||0);return locationById(id)||(meta.locations||[]).find(function(x){return Number(x.is_primary||0)===1})||null}
function renderClientCard(){var c=clientById(E('clientId').value),card=E('clientCard'),picker=E('clientSelectWrap'),locWrap=E('locationPickerWrap');if(!c){card.classList.remove('show');picker.style.display='block';locWrap.classList.remove('show');return}picker.style.display='none';card.classList.add('show');E('clientName').textContent=c.name||c.display_name||'Customer';var billing=primaryLocation(),property=locationById(E('locationId').value),billingText=formatAddress(billing),propertyText=formatAddress(property);E('clientBillingAddress').textContent=billingText||'No billing address saved';E('clientPropertyAddress').textContent=property?(billing&&Number(property.id)===Number(billing.id)?'(Same as billing address)':(propertyText||property.name||'Selected location')):'No service location selected';var ph=E('clientPhone'),em=E('clientEmail');if(c.phone){ph.style.display='inline';ph.textContent=c.phone;ph.href='tel:'+String(c.phone).replace(/[^+0-9]/g,'')}else ph.style.display='none';if(c.email){em.style.display='inline';em.textContent=c.email;em.href='mailto:'+c.email}else em.style.display='none'}
function setMeta(m){meta=m||{};meta.clients=Array.isArray(meta.clients)?meta.clients:[];meta.locations=Array.isArray(meta.locations)?meta.locations:[];meta.catalog=Array.isArray(meta.catalog)?meta.catalog:[];meta.users=Array.isArray(meta.users)?meta.users:[];meta.currency=(meta.currency&&typeof meta.currency==='object')?meta.currency:{symbol:'',symbol_position:'before',decimal_places:2};initCustomerSelect(0);var uh='';meta.users.forEach(function(u){uh+='<option value="'+Number(u.id)+'">'+esc(u.name||'Team member')+(u.job_title?' - '+esc(u.job_title):'')+'</option>'});E('assessmentUsers').innerHTML=uh;initSelect(E('assessmentUsers'),{placeholder:'Assign team members',closeOnSelect:false,minimumResultsForSearch:0});var pre=Math.max(0,parseInt(new URLSearchParams(location.search).get('client_id')||'0',10)||0);if(pre&&clientById(pre)){initCustomerSelect(pre);return showClient(pre)}return Promise.resolve()}
function loadMeta(){var fd=new FormData();fd.append('action','meta');saveButton.disabled=true;saveButton.textContent='Loading...';return req(fd).then(function(d){return setMeta(d.meta||{}).then(function(){if(!meta.clients.length)toast('warning','No active customers are available.');if(!meta.catalog.length)console.warn('No active Product / Service items are available.');return d})}).catch(function(err){toast('error','Unable to load request form data: '+err.message,6000);throw err}).finally(function(){saveButton.disabled=false;saveButton.textContent='Save Request'})}
function loadLocations(clientId,selected){var fd=new FormData();fd.append('action','locations');fd.append('client_id',clientId);return req(fd).then(function(d){meta.locations=Array.isArray(d.locations)?d.locations:[];var h='<option value=""></option>';meta.locations.forEach(function(x){var a=[x.address_line1,x.city,x.state].filter(Boolean).join(', ');h+='<option value="'+Number(x.id)+'">'+esc((x.name||('Location #'+x.id))+(a?' - '+a:''))+'</option>'});E('locationId').innerHTML=h;initSelect(E('locationId'),{placeholder:'Select location',allowClear:true,minimumResultsForSearch:0});var use=Number(selected||0);if(use<=0){var p=meta.locations.find(function(x){return Number(x.is_primary||0)===1});if(p)use=Number(p.id)}if(use>0)$('#locationId').val(String(use)).trigger('change.select2');renderClientCard();return d})}
function showClient(id){var c=clientById(id);if(!c)return Promise.resolve();if(c.branch_id)E('branchId').value=String(c.branch_id);return loadLocations(id,c.primary_location_id||0).then(function(){renderClientCard()})}
function hideClient(){E('clientMenu').classList.remove('show');E('clientCard').classList.remove('show');E('clientSelectWrap').style.display='block';E('locationPickerWrap').classList.remove('show');$('#clientId').val(null).trigger('change.select2');E('locationId').innerHTML='<option value=""></option>';meta.locations=[];setTimeout(function(){try{$('#clientId').select2('open')}catch(e){}},0)}
$('#clientId').on('select2:select',function(e){var raw=String(e.params&&e.params.data?e.params.data.id:this.value||'');if(raw.indexOf('newclient:')===0){openCreateCustomerModal(raw.slice(10));$('#clientId').val(null).trigger('change.select2');return}if(raw)showClient(raw)});$('#clientId').on('select2:clear',hideClient);$('#locationId').on('select2:select',function(){renderClientCard();E('locationPickerWrap').classList.remove('show')});$('#locationId').on('select2:clear',function(){renderClientCard();E('locationPickerWrap').classList.add('show')});
E('clientMenuButton').onclick=function(e){e.stopPropagation();E('clientMenu').classList.toggle('show')};E('changeClient').onclick=hideClient;E('changeLocation').onclick=function(){E('clientMenu').classList.remove('show');E('locationPickerWrap').classList.add('show');setTimeout(function(){try{$('#locationId').select2('open')}catch(e){}},0)};document.addEventListener('click',function(e){if(!e.target.closest('.jr-client-menu-wrap'))E('clientMenu').classList.remove('show')});
function quickModal(id,show){var m=E(id);if(!m)return;m.classList.toggle('show',!!show);m.setAttribute('aria-hidden',show?'false':'true');document.body.classList.toggle('jr-modal-open',document.querySelector('.jr-quick-modal.show')!==null)}
function openCreateCustomerModal(name){E('newCustomerName').value=String(name||'').trim();E('newCustomerCompany').value='';E('newCustomerPhone').value='';E('newCustomerEmail').value='';quickModal('createCustomerModal',true);setTimeout(function(){E('newCustomerName').focus();E('newCustomerName').select()},80)}
function createCustomer(){var name=String(E('newCustomerName').value||'').trim();if(!name){toast('warning','Enter a customer name.');E('newCustomerName').focus();return}var email=String(E('newCustomerEmail').value||'').trim();if(email&&E('newCustomerEmail').validity&&!E('newCustomerEmail').validity.valid){toast('warning','Enter a valid customer email address.');E('newCustomerEmail').focus();return}var fd=new FormData();fd.append('action','create_customer');fd.append('display_name',name);fd.append('company_name',E('newCustomerCompany').value||'');fd.append('phone',E('newCustomerPhone').value||'');fd.append('email',email);fd.append('branch_id',String(Number(E('branchId').value||0)));var btn=E('createCustomerButton');btn.disabled=true;btn.textContent='Creating...';req(fd).then(function(d){var c=d.client||{};if(Number(c.id||0)<=0)throw new Error('Customer was not returned by the server.');c.name=c.name||c.display_name||name;var pos=(meta.clients||[]).findIndex(function(x){return Number(x.id)===Number(c.id)});if(pos>=0)meta.clients[pos]=c;else meta.clients.push(c);initCustomerSelect(c.id);quickModal('createCustomerModal',false);return showClient(c.id).then(function(){dirty=true;toast('success',d.message||'Customer created successfully.')})}).catch(function(err){toast('error',err.message,6000)}).finally(function(){btn.disabled=false;btn.textContent='Create Customer'})}

function setAssessment(on){E('assessmentEnabled').value=on?'1':'0';E('status').value=on?'assessment_required':'new';E('assessmentEmpty').style.display=on?'none':'flex';E('assessmentEditor').classList.toggle('show',on);E('removeAssessment').style.display=on?'block':'none';if(!on){$('#assessmentUsers').val(null).trigger('change');E('emailTeam').checked=false;E('notifyEmail').value='0'}else{E('notifyEmail').value=E('emailTeam').checked?'1':'0'}}
E('addAssessment').onclick=function(){setAssessment(true)};E('assessmentEmpty').onclick=function(e){if(e.target===this)setAssessment(true)};E('removeAssessment').onclick=function(){setAssessment(false)};E('emailTeam').onchange=function(){E('notifyEmail').value=this.checked?'1':'0'};
function syncAssessmentSchedule(){var later=E('scheduleLater').checked,any=E('assessmentAnytime').checked;if(later&&any){E('assessmentAnytime').checked=false;any=false}E('assessmentStartDate').disabled=later;E('assessmentEndDate').disabled=later;E('assessmentStartTime').disabled=later||any;E('assessmentEndTime').disabled=later||any}
E('scheduleLater').onchange=function(){if(this.checked)E('assessmentAnytime').checked=false;syncAssessmentSchedule()};
E('assessmentAnytime').onchange=function(){if(this.checked)E('scheduleLater').checked=false;syncAssessmentSchedule()};
function imageRender(){E('imageCounter').textContent=selectedImages.length+'/10';var box=E('imagePreview');box.innerHTML='';selectedImages.forEach(function(f,i){var d=document.createElement('div');d.className='jr-preview-item';var img=document.createElement('img');img.src=URL.createObjectURL(f);var b=document.createElement('button');b.type='button';b.innerHTML='&times;';b.onclick=function(){selectedImages.splice(i,1);imageRender()};d.appendChild(img);d.appendChild(b);box.appendChild(d)})}
E('requestImages').addEventListener('change',function(){Array.prototype.forEach.call(this.files||[],function(f){if(selectedImages.length<10&&/^image\//.test(f.type))selectedImages.push(f)});this.value='';imageRender()});E('imageDrop').addEventListener('dragover',function(e){e.preventDefault()});E('imageDrop').addEventListener('drop',function(e){e.preventDefault();Array.prototype.forEach.call(e.dataTransfer.files||[],function(f){if(selectedImages.length<10&&/^image\//.test(f.type))selectedImages.push(f)});imageRender()});
function fileSize(n){n=Number(n||0);if(n>=1024*1024)return (n/(1024*1024)).toFixed(1)+' MB';if(n>=1024)return Math.round(n/1024)+' KB';return n+' B'}
function fileRender(){var box=E('fileList');box.innerHTML='';selectedFiles.forEach(function(f,i){var ext=(String(f.name||'').split('.').pop()||'').toLowerCase(),icon=['jpg','jpeg','png','webp','avif','heic'].indexOf(ext)>=0?'bi-image':'bi-paperclip';var r=document.createElement('div');r.className='jr-file-chip';r.innerHTML='<i class="bi '+icon+'"></i><span></span><small></small><button type="button"><i class="bi bi-x-lg"></i></button>';r.querySelector('span').textContent=f.name;r.querySelector('small').textContent=fileSize(f.size);r.querySelector('button').onclick=function(){selectedFiles.splice(i,1);fileRender()};box.appendChild(r)})}
function queueFiles(list){Array.prototype.forEach.call(list||[],function(f){if(selectedFiles.length>=10){toast('warning','Maximum 10 note files/photos allowed.');return}if(Number(f.size||0)>25*1024*1024){toast('warning',f.name+' exceeds the 25MB note attachment limit.');return}selectedFiles.push(f)});fileRender()}
E('noteAttachButton').onclick=function(e){e.stopPropagation();E('requestAttachments').click()};E('fileDrop').onclick=function(e){if(e.target===this||!e.target.closest('button'))E('requestAttachments').click()};E('fileDrop').onkeydown=function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();E('requestAttachments').click()}};E('requestAttachments').onchange=function(){queueFiles(this.files);this.value=''};['dragenter','dragover'].forEach(function(evt){E('fileDrop').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.add('dragover')})});['dragleave','drop'].forEach(function(evt){E('fileDrop').addEventListener(evt,function(e){e.preventDefault();e.stopPropagation();this.classList.remove('dragover')})});E('fileDrop').addEventListener('drop',function(e){queueFiles(e.dataTransfer&&e.dataTransfer.files?e.dataTransfer.files:[])});
function noteMentionHandle(m){var raw=String((m&&m.name)||'User').trim().replace(/\s+/g,'_');raw=raw.replace(/[^A-Za-z0-9_]/g,'_').replace(/_+/g,'_').replace(/^_+|_+$/g,'');return raw||('user'+Number(m&&m.id||0))}
function noteMentionContext(){var ta=E('internalNote'),pos=ta.selectionStart==null?ta.value.length:ta.selectionStart,before=ta.value.slice(0,pos),m=before.match(/(^|[\s\n])@([A-Za-z0-9_.-]*)$/);if(!m)return null;return{start:pos-m[2].length-1,end:pos,query:String(m[2]||'').toLowerCase()}}
function hideMentionMenu(){E('noteMentionMenu').classList.remove('show');E('noteMentionMenu').innerHTML='';mentionMatches=[];mentionActiveIndex=0}
function renderMentionMenu(){var ctx=noteMentionContext();if(!ctx){hideMentionMenu();return}var q=ctx.query;mentionMatches=(meta.users||[]).filter(function(m){var hay=[m.name,m.email,m.job_title,noteMentionHandle(m)].join(' ').toLowerCase();return !q||hay.indexOf(q)>=0}).slice(0,8);if(!mentionMatches.length){hideMentionMenu();return}mentionActiveIndex=Math.min(mentionActiveIndex,mentionMatches.length-1);E('noteMentionMenu').innerHTML=mentionMatches.map(function(m,i){var full=String(m.name||'Team member'),role=String(m.job_title||'Team member'),initial=full.trim().charAt(0).toUpperCase()||'U';return '<button type="button" class="jr-mention-option '+(i===mentionActiveIndex?'active':'')+'" data-note-mention-id="'+Number(m.id)+'"><span class="jr-mention-avatar">'+esc(initial)+'</span><span class="jr-mention-copy"><span class="jr-mention-name">'+esc(full)+'</span><span class="jr-mention-role">'+esc(role)+'</span></span></button>'}).join('');E('noteMentionMenu').classList.add('show')}
function syncNoteMentions(){var t=String(E('internalNote').value||'').toLowerCase();noteMentionIds=noteMentionIds.filter(function(id){var m=(meta.users||[]).find(function(x){return Number(x.id)===Number(id)});return m&&t.indexOf(('@'+noteMentionHandle(m)).toLowerCase())>=0});E('noteMentionsJson').value=JSON.stringify(noteMentionIds)}
function insertNoteMention(id){var m=(meta.users||[]).find(function(x){return Number(x.id)===Number(id)}),ctx=noteMentionContext(),ta=E('internalNote');if(!m||!ctx)return;var token='@'+noteMentionHandle(m),before=ta.value.slice(0,ctx.start),after=ta.value.slice(ctx.end);ta.value=before+token+' '+after;var caret=before.length+token.length+1;ta.focus();ta.setSelectionRange(caret,caret);if(noteMentionIds.indexOf(Number(m.id))<0)noteMentionIds.push(Number(m.id));syncNoteMentions();hideMentionMenu()}
E('internalNote').addEventListener('input',function(){syncNoteMentions();mentionActiveIndex=0;renderMentionMenu()});E('internalNote').addEventListener('click',renderMentionMenu);E('internalNote').addEventListener('keydown',function(e){if(!E('noteMentionMenu').classList.contains('show'))return;if(e.key==='ArrowDown'){e.preventDefault();mentionActiveIndex=(mentionActiveIndex+1)%mentionMatches.length;renderMentionMenu()}else if(e.key==='ArrowUp'){e.preventDefault();mentionActiveIndex=(mentionActiveIndex-1+mentionMatches.length)%mentionMatches.length;renderMentionMenu()}else if(e.key==='Enter'&&mentionMatches.length){e.preventDefault();insertNoteMention(mentionMatches[mentionActiveIndex].id)}else if(e.key==='Escape'){e.preventDefault();hideMentionMenu()}});E('noteMentionMenu').addEventListener('mousedown',function(e){var b=e.target.closest('[data-note-mention-id]');if(!b)return;e.preventDefault();insertNoteMention(b.getAttribute('data-note-mention-id'))});document.addEventListener('click',function(e){if(!e.target.closest('.jr-note-editor'))hideMentionMenu()});
function catalogOptions(selected){var h='<option value=""></option>';meta.catalog.forEach(function(x){h+='<option value="'+Number(x.id)+'" data-kind="'+esc(x.item_type||'other')+'" data-price="'+esc(x.unit_price||0)+'" data-description="'+esc(x.description||'')+'"'+(Number(selected||0)===Number(x.id)?' selected':'')+'>'+esc(x.name||'')+'</option>'});return h}
function catalogResult(item){if(!item.id)return item.text;var id=String(item.id||'');if(id.indexOf('newitem:')===0)return createOptionNode('Create new item');var el=item.element,kind=el?String(el.getAttribute('data-kind')||'other'):'other',price=el?el.getAttribute('data-price'):'',desc=el?el.getAttribute('data-description'):'';var row=document.createElement('div');row.className='jr-catalog-result';var copy=document.createElement('div');copy.className='jr-catalog-copy';var name=document.createElement('div');name.className='jr-catalog-name';var tx=document.createElement('span');tx.textContent=item.text||'';name.appendChild(tx);var badge=document.createElement('span');badge.className='jr-type-badge '+(kind==='service'?'service':(kind==='product'?'product':'other'));badge.textContent=kind==='service'?'Service':(kind==='product'?'Product':String(kind||'Item').replace(/_/g,' '));name.appendChild(badge);copy.appendChild(name);if(desc){var d=document.createElement('div');d.className='jr-catalog-desc';d.textContent=desc;copy.appendChild(d)}row.appendChild(copy);if(price!==null&&price!==''){var p=document.createElement('div');p.className='jr-catalog-price';p.textContent=money(price);row.appendChild(p)}return $(row)}
function catalogSelection(item){if(!item.id)return 'Name';var id=String(item.id||'');return id.indexOf('newitem:')===0?'Name':(item.text||'Name')}
function lineHtml(x){x=x||{};lineSeq++;var id='rli'+lineSeq;return '<div class="jr-line" data-line="'+id+'"><div class="jr-line-item"><select class="jr-line-select" data-f="catalog">'+catalogOptions(x.product_service_id||0)+'</select></div><div class="jr-money"><small>Quantity</small><input data-f="qty" type="number" min="0.001" step="0.001" value="'+Number(x.quantity||1)+'"></div><div class="jr-money"><small>Unit price</small><input data-f="price" type="number" min="0" step="0.01" value="'+Number(x.unit_price||0).toFixed(2)+'"></div><div class="jr-money"><small>Total</small><strong data-f="total">'+money(Number(x.quantity||1)*Number(x.unit_price||0))+'</strong></div><button type="button" class="jr-line-remove" title="Remove"><i class="bi bi-trash"></i></button><textarea class="jr-control jr-line-desc" data-f="desc" placeholder="Description">'+esc(x.description||'')+'</textarea><label class="jr-line-image"><i class="bi bi-image"></i><input type="file" name="line_item_images[]" accept="image/*"></label></div>'}
function addLine(x){E('lineItems').insertAdjacentHTML('beforeend',lineHtml(x));var r=E('lineItems').lastElementChild,$s=$(r).find('.jr-line-select');$s.select2({width:'100%',placeholder:'Name',allowClear:true,tags:true,minimumResultsForSearch:0,createTag:function(params){var term=$.trim(params.term||'');if(!term)return null;var exact=(meta.catalog||[]).some(function(v){return String(v.name||'').toLowerCase()===term.toLowerCase()});return exact?null:{id:'newitem:'+term,text:term,newTag:true}},templateResult:catalogResult,templateSelection:catalogSelection});$s.on('select2:select',function(e){var raw=String(e.params&&e.params.data?e.params.data.id:this.value||'');if(raw.indexOf('newitem:')===0){catalogTargetRow=r;openCreateCatalogModal(raw.slice(8));$s.val(null).trigger('change.select2');return}var c=catalogById(raw);if(c){r.querySelector('[data-f="price"]').value=Number(c.unit_price||0).toFixed(2);r.querySelector('[data-f="desc"]').value=c.description||'';updateLine(r)}});$s.on('select2:clear',function(){r.querySelector('[data-f="price"]').value='0.00';r.querySelector('[data-f="desc"]').value='';updateLine(r)});r.querySelector('[data-f="qty"]').oninput=function(){updateLine(r)};r.querySelector('[data-f="price"]').oninput=function(){updateLine(r)};r.querySelector('.jr-line-remove').onclick=function(){if($s.hasClass('select2-hidden-accessible'))$s.select2('destroy');if(catalogTargetRow===r)catalogTargetRow=null;r.remove();updateTotals()};E('emptyLines').style.display='none';updateLine(r)}
function updateLine(r){var q=Math.max(0,Number(r.querySelector('[data-f="qty"]').value||0)),p=Math.max(0,Number(r.querySelector('[data-f="price"]').value||0));r.querySelector('[data-f="total"]').textContent=money(q*p);updateTotals()}
function collectLines(){var a=[];document.querySelectorAll('[data-line]').forEach(function(r,n){var id=Number($(r).find('[data-f="catalog"]').val()||0),c=catalogById(id),q=Math.max(0,Number(r.querySelector('[data-f="qty"]').value||0)),p=Math.max(0,Number(r.querySelector('[data-f="price"]').value||0));if(!c||q<=0)return;a.push({product_service_id:id,item_type:c.item_type||'service',item_name:c.name||'',description:r.querySelector('[data-f="desc"]').value,quantity:q,unit_cost:Number(c.unit_cost||0),unit_price:p,tax_percent:Number(c.tax_percent||0),sort_order:n+1})});return a}
function updateTotals(){var a=collectLines(),sub=0,total=0,primary='';a.forEach(function(x){var base=x.quantity*x.unit_price,tax=base*x.tax_percent/100;sub+=base;total+=base+tax;if(!primary&&String(x.item_type||'')==='service')primary=x.product_service_id});E('subtotal').textContent=money(sub);E('total').textContent=money(total);E('lineItemsJson').value=JSON.stringify(a);E('primaryServiceId').value=primary||'';E('emptyLines').style.display=document.querySelectorAll('[data-line]').length?'none':'block'}
E('addLineItem').onclick=function(){addLine({quantity:1});setTimeout(function(){var rows=E('lineItems').querySelectorAll('.jr-line');var r=rows.length?rows[rows.length-1]:null;if(r){var s=$(r).find('.jr-line-select');try{s.select2('open')}catch(e){}}},30)};
function openCreateCatalogModal(name){E('newItemType').value='service';E('newItemName').value=String(name||'').trim();E('newItemDescription').value='';E('newItemUnitCost').value='0.00';E('newItemMarkup').value='0';E('newItemUnitPrice').value='0.00';E('newItemTaxPercent').value='0';E('newItemTaxExempt').checked=false;E('newItemTaxPercent').disabled=false;catalogPriceTouched=false;quickModal('catalogItemModal',true);setTimeout(function(){E('newItemName').focus();E('newItemName').select()},80)}
function recalcNewCatalogPrice(){if(catalogPriceTouched)return;var cost=Math.max(0,Number(E('newItemUnitCost').value||0)),markup=Math.max(0,Number(E('newItemMarkup').value||0));E('newItemUnitPrice').value=(Math.round(cost*(1+markup/100)*100)/100).toFixed(2)}
function appendCatalogItem(item){var exists=(meta.catalog||[]).findIndex(function(x){return Number(x.id)===Number(item.id)});if(exists>=0)meta.catalog[exists]=item;else meta.catalog.push(item);document.querySelectorAll('.jr-line-select').forEach(function(sel){if(!sel.querySelector('option[value="'+Number(item.id)+'"]')){var o=document.createElement('option');o.value=String(Number(item.id));o.textContent=item.name||'Item';o.setAttribute('data-kind',item.item_type||'other');o.setAttribute('data-price',String(item.unit_price||0));o.setAttribute('data-description',item.description||'');sel.appendChild(o)}})}
function createCatalogItem(){var type=String(E('newItemType').value||'service'),name=String(E('newItemName').value||'').trim();if(!name){toast('warning','Enter a product / service name.');E('newItemName').focus();return}var fd=new FormData();fd.append('action','create_catalog_item');fd.append('item_type',type);fd.append('name',name);fd.append('description',E('newItemDescription').value||'');fd.append('unit_cost',E('newItemUnitCost').value||'0');fd.append('markup_percent',E('newItemMarkup').value||'0');fd.append('unit_price',E('newItemUnitPrice').value||'0');fd.append('tax_percent',E('newItemTaxExempt').checked?'0':(E('newItemTaxPercent').value||'0'));var btn=E('createCatalogItemButton');btn.disabled=true;btn.textContent='Creating...';req(fd).then(function(d){var item=d.item||{};if(Number(item.id||0)<=0)throw new Error('Product / Service was not returned by the server.');appendCatalogItem(item);if(catalogTargetRow&&document.body.contains(catalogTargetRow)){var $sel=$(catalogTargetRow).find('.jr-line-select');$sel.val(String(item.id)).trigger({type:'select2:select',params:{data:{id:String(item.id),text:item.name}}});$sel.trigger('change.select2')}catalogTargetRow=null;quickModal('catalogItemModal',false);dirty=true;toast('success',d.message||'Item created successfully.')}).catch(function(err){toast('error',err.message,6000)}).finally(function(){btn.disabled=false;btn.textContent='Create'})}
document.querySelectorAll('[data-close-quick]').forEach(function(btn){btn.addEventListener('click',function(){quickModal(this.getAttribute('data-close-quick'),false);if(this.getAttribute('data-close-quick')==='catalogItemModal')catalogTargetRow=null})});document.querySelectorAll('.jr-quick-modal').forEach(function(modal){modal.addEventListener('mousedown',function(e){if(e.target===modal){quickModal(modal.id,false);if(modal.id==='catalogItemModal')catalogTargetRow=null}})});document.addEventListener('keydown',function(e){if(e.key!=='Escape')return;var open=document.querySelector('.jr-quick-modal.show');if(open){quickModal(open.id,false);if(open.id==='catalogItemModal')catalogTargetRow=null}});E('createCustomerButton').addEventListener('click',createCustomer);E('newCustomerName').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();createCustomer()}});E('newCustomerEmail').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();createCustomer()}});E('newItemUnitCost').addEventListener('input',recalcNewCatalogPrice);E('newItemMarkup').addEventListener('input',recalcNewCatalogPrice);E('newItemUnitPrice').addEventListener('input',function(){catalogPriceTouched=true});E('newItemTaxExempt').addEventListener('change',function(){E('newItemTaxPercent').disabled=this.checked;if(this.checked)E('newItemTaxPercent').value='0'});E('createCatalogItemButton').addEventListener('click',createCatalogItem);E('newItemName').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();createCatalogItem()}});
function openChecklist(){E('checklistModal').classList.add('show');renderBuilder()}function closeChecklist(){E('checklistModal').classList.remove('show')}
document.addEventListener('click',function(e){if(e.target.closest('.js-open-checklist'))openChecklist()});E('cancelChecklist').onclick=closeChecklist;E('modalClose').onclick=closeChecklist;E('checklistModal').onclick=function(e){if(e.target===this)closeChecklist()};
function newQuestion(type){return {title:'Question',type:type||'short_answer',required:0,options:(type==='dropdown'||type==='checkbox')?['Option 1','Option 2','Option 3']:[]}}
function renderBuilder(){E('builderCanvas').innerHTML=builder.map(function(s,si){return '<div class="jb-builder-section" data-s="'+si+'"><div class="jb-builder-sec-head"><input data-sec-title value="'+esc(s.title)+'"><button type="button" class="jb-icon-btn" data-del-sec><i class="bi bi-three-dots"></i></button></div>'+s.questions.map(function(q,qi){var ops=(q.type==='dropdown'||q.type==='checkbox')?'<div class="jb-options">'+q.options.map(function(o,oi){return '<div class="jb-option-row"><span>'+(oi+1)+'.</span><input data-opt="'+oi+'" value="'+esc(o)+'"><button type="button" class="jb-icon-btn" data-del-opt="'+oi+'"><i class="bi bi-x-lg"></i></button></div>'}).join('')+'<button type="button" class="jb-cancel" data-add-opt style="width:max-content;padding:5px 10px">Add option</button></div>':'';return '<div class="jb-builder-question" data-q="'+qi+'"><div class="jb-q-top"><input data-q-title value="'+esc(q.title)+'"><select data-q-type><option value="short_answer"'+(q.type==='short_answer'?' selected':'')+'>Short answer</option><option value="long_answer"'+(q.type==='long_answer'?' selected':'')+'>Long answer</option><option value="dropdown"'+(q.type==='dropdown'?' selected':'')+'>Dropdown</option><option value="checkbox"'+(q.type==='checkbox'?' selected':'')+'>Checkbox</option><option value="number"'+(q.type==='number'?' selected':'')+'>Numerical answer</option><option value="image"'+(q.type==='image'?' selected':'')+'>Upload images</option><option value="date"'+(q.type==='date'?' selected':'')+'>Date picker</option><option value="signature"'+(q.type==='signature'?' selected':'')+'>Signature</option></select><button type="button" class="jb-icon-btn" data-del-q><i class="bi bi-trash"></i></button></div>'+ops+'<div class="jb-q-foot"><label><input type="checkbox" data-required '+(q.required?'checked':'')+'> Required</label></div></div>'}).join('')+'<button type="button" class="jb-builder-link" data-add-q style="margin:10px 14px">+ Add Question</button></div>'}).join('')}
function syncBuilder(){document.querySelectorAll('[data-s]').forEach(function(sec){var si=Number(sec.dataset.s);builder[si].title=sec.querySelector('[data-sec-title]').value;sec.querySelectorAll('[data-q]').forEach(function(qel){var qi=Number(qel.dataset.q),q=builder[si].questions[qi];q.title=qel.querySelector('[data-q-title]').value;q.type=qel.querySelector('[data-q-type]').value;q.required=qel.querySelector('[data-required]').checked?1:0;q.options=[];qel.querySelectorAll('[data-opt]').forEach(function(o){q.options.push(o.value)})})})}
E('builderCanvas').addEventListener('click',function(e){var sec=e.target.closest('[data-s]'),qel=e.target.closest('[data-q]');if(e.target.closest('[data-add-q]')&&sec){syncBuilder();builder[Number(sec.dataset.s)].questions.push(newQuestion('short_answer'));renderBuilder();return}if(e.target.closest('[data-del-q]')&&sec&&qel){syncBuilder();builder[Number(sec.dataset.s)].questions.splice(Number(qel.dataset.q),1);renderBuilder();return}if(e.target.closest('[data-del-sec]')&&sec){syncBuilder();if(builder.length>1)builder.splice(Number(sec.dataset.s),1);renderBuilder();return}if(e.target.closest('[data-add-opt]')&&sec&&qel){syncBuilder();builder[Number(sec.dataset.s)].questions[Number(qel.dataset.q)].options.push('Option '+(builder[Number(sec.dataset.s)].questions[Number(qel.dataset.q)].options.length+1));renderBuilder();return}var ro=e.target.closest('[data-del-opt]');if(ro&&sec&&qel){syncBuilder();builder[Number(sec.dataset.s)].questions[Number(qel.dataset.q)].options.splice(Number(ro.dataset.delOpt),1);renderBuilder()}});
E('builderCanvas').addEventListener('change',function(e){if(e.target.matches('[data-q-type]')){syncBuilder();var sec=e.target.closest('[data-s]'),q=e.target.closest('[data-q]'),obj=builder[Number(sec.dataset.s)].questions[Number(q.dataset.q)];if((obj.type==='dropdown'||obj.type==='checkbox')&&!obj.options.length)obj.options=['Option 1'];renderBuilder()}});
document.querySelectorAll('[data-add-type]').forEach(function(b){b.onclick=function(){syncBuilder();var t=this.dataset.addType;if(t==='section')builder.push({title:'Section '+(builder.length+1),questions:[]});else builder[builder.length-1].questions.push(newQuestion(t));renderBuilder()}});
function saveChecklist(){syncBuilder();var name=E('checklistName').value.trim()||'New checklist',items=[];builder.forEach(function(s){s.questions.forEach(function(q){if(q.title.trim())items.push({section_title:s.title.trim(),title:q.title.trim(),question_type:q.type,options:q.options,is_required:q.required})})});if(!items.length){toast('warning','Add at least one checklist question.');return}E('newChecklistJson').value=JSON.stringify({name:name,description:'',items:items});E('checklistSummaryName').textContent=name;E('checklistSummaryCount').textContent=items.length+' question'+(items.length===1?'':'s');E('checklistSummary').classList.add('show');closeChecklist();toast('success','Checklist added.')}
E('saveChecklist').onclick=saveChecklist;E('modalApply').onclick=saveChecklist;E('removeChecklist').onclick=function(){E('newChecklistJson').value='';E('checklistSummary').classList.remove('show');builder=[{title:'Section 1',questions:[{title:'Question',type:'short_answer',required:0,options:[]}]}];E('checklistName').value='New checklist'};
function serialize(){syncNoteMentions();var a=collectLines();E('lineItemsJson').value=JSON.stringify(a);var primary=a.find(function(x){return String(x.item_type||'')==='service'});E('primaryServiceId').value=primary?primary.product_service_id:'';if(E('assessmentEnabled').value==='1'){var users=$('#assessmentUsers').val()||[];if(!users.length){toast('warning','Assign at least one team member for the on-site assessment.');return false}var later=E('scheduleLater').checked,any=E('assessmentAnytime').checked;if(!later&&!E('assessmentStartDate').value){toast('warning','Select an assessment start date or choose Schedule later.');return false}if(!later&&!any&&(!E('assessmentStartTime').value||!E('assessmentEndTime').value)){toast('warning','Enter assessment start and end time, or select Anytime.');return false}E('status').value='assessment_required';E('notifyEmail').value=E('emailTeam').checked?'1':'0'}else{E('status').value='new'}return true}
form.addEventListener('input',function(){dirty=true});form.addEventListener('change',function(){dirty=true});window.addEventListener('beforeunload',function(e){if(dirty&&!submitting){e.preventDefault();e.returnValue=''}});
form.addEventListener('submit',function(e){e.preventDefault();if(!form.reportValidity()){toast('warning','Complete the required request fields.');return}if(!serialize())return;var fd=new FormData(form);fd.delete('request_images[]');fd.delete('request_attachments[]');selectedImages.forEach(function(f){fd.append('request_images[]',f,f.name)});selectedFiles.forEach(function(f){fd.append('request_attachments[]',f,f.name)});fd.append('action','create');saveButton.disabled=true;saveButton.textContent='Saving...';submitting=true;req(fd).then(function(d){dirty=false;var msg=d.message||'Service request created successfully.',warnings=[];if(d.assessment_no)msg+=' Assessment '+d.assessment_no+' created.';var cm=d.customer_email||{};if(cm.status==='sent')msg+=' Customer email sent.';else if(cm.status==='failed'||cm.status==='skipped')warnings.push(cm.message||'Customer email was not sent.');var ns=d.notification_summary||{};if(Number(ns.email_sent||0)>0)msg+=' Team email sent to '+Number(ns.email_sent)+' member'+(Number(ns.email_sent)===1?'':'s')+'.';if(E('emailTeam').checked&&(Number(ns.email_failed||0)>0||Number(ns.email_skipped||0)>0)){var failed=Number(ns.email_failed||0),skipped=Number(ns.email_skipped||0);warnings.push('Team email: '+failed+' failed, '+skipped+' skipped.')}if(warnings.length){toast('warning',msg+' '+warnings.join(' '),6500);setTimeout(function(){location.href='requests.php'},2600)}else{toast('success',msg,4800);setTimeout(function(){location.href='requests.php'},1400)}}).catch(function(err){submitting=false;toast('error',err.message,6000)}).finally(function(){saveButton.disabled=false;saveButton.textContent='Save Request'})});
syncAssessmentSchedule();loadMeta().catch(function(){/* error already shown by loadMeta */});
})();
</script>
</body></html>
