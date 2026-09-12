<?php
/*
 * FieldPlx Request View - Version 2.0.2 - 2026-09-08
 * Jobber-style Request View using the Add Invoice FieldPlx visual system.
 * All mutations use the dedicated api/request-view.php endpoint.
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Request View';
$activePage = 'requests';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['request_view_csrf_token'])) {
    $_SESSION['request_view_csrf_token'] = bin2hex(random_bytes(32));
}
$requestViewCsrf = (string)$_SESSION['request_view_csrf_token'];
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Request View - FieldPlx</title>
<?php require_once __DIR__ . '/includes/links.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
/* Canonical FieldPlx tenant shell copied from Add Request/Add Invoice reference */

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



/* Request View v2 page styles */
:root{
  --rv-green:#2f8d25;--rv-green-dark:#27781f;--rv-green-soft:#edf6e8;
  --rv-navy:#001131;--rv-text:#0b3142;--rv-body:#314f5d;--rv-muted:#6c818d;
  --rv-line:#dbe3e7;--rv-line-soft:#e9eef0;--rv-surface:#fff;--rv-soft:#f8fafb;
  --rv-danger:#dc4c55;--rv-blue:#2f83c6;--rv-orange:#c66e06;
}
*{box-sizing:border-box}
body{margin:0;background:#fff;color:var(--rv-body);font-family:Arial,Helvetica,sans-serif!important;font-size:14px!important}
a{text-decoration:none}
button,input,select,textarea{font-family:Arial,Helvetica,sans-serif}
.fieldplx-content-wrapper{padding:0!important}
.rv-page{width:100%;min-height:calc(100vh - 70px);background:#fff}
.rv-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;min-height:calc(100vh - 70px)}
.rv-main{min-width:0;border-right:1px solid var(--rv-line-soft)}
.rv-main-inner{max-width:1160px;margin:0 auto;padding:27px 31px 55px}
.rv-side{min-width:0;padding:28px 25px;background:#fff}
.rv-side-inner{position:sticky;top:86px}

/* Header */
.rv-top{display:flex;align-items:center;gap:12px;margin-bottom:18px}
.rv-request-icon{width:28px;height:28px;display:grid;place-items:center;color:#c96d00;font-size:22px}
.rv-status{display:inline-flex;align-items:center;gap:7px;min-height:27px;padding:4px 11px;border-radius:999px;background:#e8f4ff;color:#32668b;font-size:13px}
.rv-status:before{width:8px;height:8px;border-radius:50%;background:#36a2eb;content:""}
.rv-top-spacer{flex:1}
.rv-icon-btn,.rv-action-btn{height:41px;border:1px solid var(--rv-line);border-radius:8px;background:#fff;color:#2e4b59;font-size:14px;font-weight:700;cursor:pointer}
.rv-icon-btn{width:42px;display:grid;place-items:center;font-size:19px;border-color:transparent}
.rv-icon-btn:hover{background:#f4f8f2;color:var(--rv-green)}
.rv-action-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:0 15px}
.rv-action-btn:hover{border-color:#b8d9ac;color:var(--rv-green-dark);background:#fbfef9}
.rv-action-btn.primary{border-color:var(--rv-green);background:var(--rv-green);color:#fff}
.rv-action-btn.primary:hover{background:var(--rv-green-dark);color:#fff}
.rv-more-wrap{position:relative}
.rv-more-menu{width:205px;position:absolute;right:0;top:48px;z-index:2000;display:none;padding:7px;border:1px solid var(--rv-line);border-radius:9px;background:#fff;box-shadow:0 10px 26px rgba(0,17,49,.15)}
.rv-more-menu.show{display:block}
.rv-more-item{width:100%;min-height:40px;padding:8px 10px;display:flex;align-items:center;gap:10px;border:0;border-radius:7px;background:#fff;color:#334f5d;font-size:14px;font-weight:600;text-align:left;cursor:pointer}
.rv-more-item:hover{background:#f5f8f5;color:var(--rv-green-dark)}
.rv-more-item i{width:20px;font-size:17px}.rv-more-item.danger{color:#c9444e}.rv-more-sep{height:1px;margin:6px 5px;background:var(--rv-line-soft)}

.rv-title-row{display:flex;align-items:center;gap:10px;margin-bottom:15px}
.rv-title{margin:0;color:#052f40;font-size:30px;line-height:1.16;font-weight:700;letter-spacing:-.3px}
.rv-title-edit{width:36px;height:36px;margin-left:auto;border:0;border-radius:8px;background:transparent;color:#2b4b59;font-size:19px;cursor:pointer}
.rv-title-edit:hover{background:#f4f8f2;color:var(--rv-green)}
.rv-request-no{display:none;color:#758894;font-size:12px}

.rv-header-info{display:grid;grid-template-columns:minmax(310px,450px) minmax(300px,1fr);gap:17px;align-items:start;margin-bottom:30px}
.rv-customer-card{min-height:177px;padding:24px;border:1px solid var(--rv-line);border-radius:8px;background:#fff;position:relative}
.rv-customer-name{display:flex;align-items:center;gap:7px;margin:0 34px 12px 0;color:#0b3142;font-size:17px;font-weight:700}
.rv-customer-dot{width:8px;height:8px;border-radius:50%;background:#37a3e6}
.rv-customer-more{position:absolute;right:18px;top:18px;width:34px;height:34px;border:0;background:transparent;color:#36525f;font-size:20px;cursor:pointer;border-radius:7px}
.rv-customer-more:hover{background:#f5f8f5}
.rv-address{margin-bottom:12px;color:#2f4e5d;line-height:1.35;white-space:pre-line}
.rv-customer-links{display:grid;gap:5px}.rv-customer-links a{width:max-content;max-width:100%;color:#3b8d26;text-decoration:underline!important;overflow-wrap:anywhere}
.rv-requested{display:grid;grid-template-columns:130px 1fr;gap:24px;padding:17px 0;border-bottom:1px solid var(--rv-line);color:#526d79}
.rv-requested strong{color:#173d4d;font-weight:400}

/* Sections */
.rv-section{padding:29px 0;border-top:1px solid var(--rv-line-soft)}
.rv-section:first-of-type{border-top:0}
.rv-card{border:1px solid var(--rv-line);border-radius:8px;background:#fff;overflow:hidden}
.rv-card-inner{padding:27px 24px}
.rv-section-head{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.rv-section-head h2,.rv-side-title{margin:0;color:#073247;font-size:22px;line-height:1.2;font-weight:700}
.rv-section-head .rv-edit{margin-left:auto}
.rv-edit{width:36px;height:36px;border:0;border-radius:8px;background:transparent;color:#2d4b58;font-size:18px;cursor:pointer}
.rv-edit:hover{background:#f4f8f2;color:var(--rv-green)}
.rv-subtitle{margin:0 0 7px;color:#0e3446;font-size:16px;font-weight:700}.rv-helper{margin:0 0 5px;color:#6d838f}
.rv-copy{color:#2f4e5c;line-height:1.55;white-space:pre-wrap}
.rv-info-block{margin-bottom:18px}.rv-info-block:last-child{margin-bottom:0}
.rv-placeholder{color:#6f828e}.rv-work-images{display:flex;gap:9px;flex-wrap:wrap;margin-top:10px}.rv-work-image{width:74px;height:74px;overflow:hidden;border:1px solid var(--rv-line);border-radius:7px;background:#f7f9fa}.rv-work-image img{width:100%;height:100%;object-fit:cover}
.rv-form-field{margin-bottom:13px}.rv-form-field label{display:block;margin-bottom:6px;color:#274553;font-size:13px;font-weight:600}
.rv-input,.rv-select,.rv-textarea{width:100%;height:43px;padding:9px 12px;border:1px solid var(--rv-line);border-radius:7px;background:#fff;color:#183845;font-size:14px;outline:0}
.rv-textarea{height:auto;min-height:92px;resize:vertical;line-height:1.5}.rv-input:focus,.rv-select:focus,.rv-textarea:focus{border-color:#8fbe79;box-shadow:0 0 0 2px rgba(47,141,37,.08)}
.rv-editor-actions{display:flex;justify-content:flex-end;gap:8px;padding-top:7px}
.rv-small-btn{height:38px;padding:0 14px;border:1px solid var(--rv-line);border-radius:7px;background:#fff;color:#31505d;font-size:14px;font-weight:700;cursor:pointer}.rv-small-btn.primary{border-color:var(--rv-green);background:var(--rv-green);color:#fff}.rv-small-btn:hover{border-color:#b8d9ac}.rv-small-btn.primary:hover{background:var(--rv-green-dark)}

/* Assessment */
.rv-assessment-title{margin:0 0 17px;color:#073247;font-size:22px;font-weight:700}
.rv-assessment-empty{min-height:225px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px;border:1px dashed #cad5da;border-radius:8px;background:#f4f2ee;color:#244655;cursor:pointer;text-align:center}
.rv-assessment-empty:hover{border-color:#9cc18a;background:#f6f9f2}
.rv-plus{width:58px;height:58px;display:grid;place-items:center;border-radius:50%;background:var(--rv-green);color:#fff;font-size:28px;font-weight:300}
.rv-assessment-card{border:1px solid var(--rv-line);border-radius:8px;background:#fff;overflow:hidden}
.rv-assessment-display{padding:24px}.rv-assessment-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin-top:18px}.rv-meta-box{padding:12px;border:1px solid var(--rv-line-soft);border-radius:7px;background:#fbfcfc}.rv-meta-box small{display:block;color:#7b8e98;font-size:12px}.rv-meta-box strong{display:block;margin-top:5px;color:#173d4c;font-size:14px;font-weight:600}
.rv-assessment-editor{padding:24px}.rv-assessment-editor .rv-section-head{margin-bottom:18px}.rv-assessment-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 26px;margin-top:20px;align-items:start}.rv-assessment-col{min-width:0}.rv-assessment-col h3{margin:0 0 12px;color:#0b3142;font-size:16px}.rv-date-pair,.rv-time-pair{display:grid;grid-template-columns:1fr 1fr}.rv-date-pair input:first-child,.rv-time-pair input:first-child{border-radius:7px 0 0 7px}.rv-date-pair input:last-child,.rv-time-pair input:last-child{border-left:0;border-radius:0 7px 7px 0}.rv-check-row{display:flex;align-items:center;gap:8px;margin:12px 0;color:#395965;line-height:1.35}.rv-check-row input{width:19px;height:19px;flex:0 0 19px;accent-color:var(--rv-green)}.rv-assessment-checklists{grid-column:1/-1;padding-top:18px;border-top:1px solid var(--rv-line-soft)}.rv-checklist-box{display:flex;align-items:flex-start;gap:14px;padding:14px 16px;border:1px dashed #d2dce0;border-radius:8px;background:#fbfcfb}.rv-checklist-icon{width:46px;height:46px;flex:0 0 46px;display:grid;place-items:center;border-radius:50%;background:#eef2f0;color:#214b5b;font-size:22px}.rv-checklist-copy{min-width:0;flex:1;display:grid;grid-template-columns:minmax(230px,1fr) auto;gap:8px 18px;align-items:center}.rv-checklist-text strong{display:block;margin-bottom:4px;color:#0d3344}.rv-checklist-text p{margin:0;color:#54707d;line-height:1.35}.rv-checklist-items{grid-column:1/-1;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.rv-link-btn{padding:0;border:0;background:transparent;color:#3b8d26;font-size:14px;font-weight:700;text-decoration:underline;cursor:pointer;white-space:nowrap}.rv-checklist-chip{display:inline-flex;align-items:center;gap:7px;margin:0;padding:6px 9px;border-radius:6px;background:#edf6e8;color:#347525;font-size:12px}.rv-assessment-team .select2-container{width:100%!important}.rv-assessment-team .select2-container--default .select2-selection--multiple{width:100%!important;min-height:43px!important}.rv-assessment-team .select2-container--default .select2-selection--multiple .select2-search--inline{float:none!important;display:inline-block!important;vertical-align:middle!important}.rv-assessment-team .select2-container--default .select2-selection--multiple .select2-search__field{width:auto!important;min-width:135px!important;height:29px!important;margin:3px 0 0 3px!important;padding:0 4px!important;border:0!important;border-radius:0!important;box-shadow:none!important}.rv-assessment-team .rv-form-field{max-width:100%}

/* Select2 */
.select2-container{width:100%!important}.select2-container .select2-selection--single{height:43px!important;border:1px solid var(--rv-line)!important;border-radius:7px!important;background:#fff!important}.select2-container .select2-selection--single .select2-selection__rendered{height:41px!important;line-height:41px!important;padding-left:12px!important;padding-right:34px!important;color:#183845!important;font-size:14px!important}.select2-container .select2-selection--single .select2-selection__arrow{height:41px!important;right:5px!important}.select2-container--focus .select2-selection--single,.select2-container--open .select2-selection--single{border-color:#8fbe79!important;box-shadow:0 0 0 2px rgba(47,141,37,.08)!important}.select2-dropdown{border:1px solid #d5dee2!important;border-radius:7px!important;overflow:hidden;box-shadow:0 10px 24px rgba(0,17,49,.12)}.select2-search--dropdown{padding:8px!important}.select2-search__field{height:38px!important;padding:8px 10px!important;border:1px solid #d7e0e4!important;border-radius:6px!important;font-size:14px!important;outline:0}.select2-results__option{padding:10px 12px!important;font-size:13.5px!important}.select2-results__option--highlighted[aria-selected]{background:#f1f0ed!important;color:#173d4c!important}.select2-container--default .select2-selection--multiple{min-height:43px!important;padding:3px 32px 3px 5px!important;border:1px solid var(--rv-line)!important;border-radius:7px!important}.select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#8fbe79!important;box-shadow:0 0 0 2px rgba(47,141,37,.08)!important}.select2-container--default .select2-selection--multiple .select2-selection__choice{margin-top:4px!important;padding:4px 8px 4px 22px!important;border:0!important;border-radius:999px!important;background:#eef3ef!important;color:#244857!important;font-size:12px!important}.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{height:100%!important;left:5px!important;border:0!important}.rv-catalog-result{display:flex;align-items:flex-start;gap:8px}.rv-catalog-main{min-width:0;flex:1}.rv-catalog-name{display:flex;align-items:center;gap:7px;color:#294957}.rv-catalog-desc{margin-top:3px;color:#6a808b;font-size:12px;line-height:1.35}.rv-catalog-price{margin-left:auto;color:#294957;white-space:nowrap}.rv-badge{display:inline-flex;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:700}.rv-badge.service{color:#28701f;background:#edf7e9;border:1px solid #cae6c2}.rv-badge.product{color:#245d83;background:#eef6fb;border:1px solid #c9deeb}.rv-create-option{display:flex;align-items:center;gap:7px;color:var(--rv-green-dark);font-weight:700}

/* Product Service */
.rv-lines-card{border:1px solid var(--rv-line);border-radius:8px;background:#fff;overflow:hidden}.rv-lines-head{padding:27px 24px 18px}.rv-lines-head h2{margin:0 0 18px;color:#073247;font-size:22px}.rv-lines-head p{margin:0 0 15px}.rv-line-list{padding:0 24px}.rv-line{display:grid;grid-template-columns:minmax(250px,1fr) 125px 160px 160px 40px;gap:9px;padding:16px 0;border-top:1px solid var(--rv-line-soft);align-items:start}.rv-line:first-child{border-top:0}.rv-money{height:48px;padding:6px 10px;border:1px solid var(--rv-line);border-radius:7px;background:#fff}.rv-money small{display:block;color:#718692;font-size:11px}.rv-money input{width:100%;padding:0;border:0;outline:0;background:transparent;color:#183845;font-size:14px}.rv-money strong{display:block;margin-top:2px;color:#183845;font-size:14px}.rv-line .select2-container .select2-selection--single{height:48px!important}.rv-line .select2-container .select2-selection--single .select2-selection__rendered{height:46px!important;line-height:46px!important}.rv-line-desc{grid-column:1/5;min-height:86px}.rv-line-image{height:86px;display:grid;place-items:center;border:1px dashed #ccd7dc;border-radius:7px;color:var(--rv-green);font-size:19px;background:#fff}.rv-remove-line{width:40px;height:48px;border:0;border-radius:7px;background:transparent;color:#647d89;font-size:19px;cursor:pointer}.rv-remove-line:hover{background:#fff0f1;color:#c9444e}.rv-totals{display:grid;grid-template-columns:1fr minmax(360px,50%);padding:19px 24px 24px;border-top:4px solid #e4e9eb}.rv-total-box{grid-column:2}.rv-total-row{min-height:44px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--rv-line-soft);color:#405e6b}.rv-total-row.grand{font-size:16px;font-weight:700;color:#0d3445;border-bottom:4px solid #e4e9eb}.rv-total-row.grand span:last-child{font-size:18px}.rv-line-editor-actions{padding:14px 24px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--rv-line-soft)}
.rv-lines-display{padding:0 24px 18px}.rv-display-line{display:grid;grid-template-columns:minmax(0,1fr) 90px 130px 130px;gap:10px;padding:13px 0;border-top:1px solid var(--rv-line-soft);align-items:center}.rv-display-line:first-child{border-top:0}.rv-display-line-name strong{display:block;color:#183845}.rv-display-line-name small{display:block;margin-top:4px;color:#71828b}.rv-display-right{text-align:right}.rv-empty-lines{padding:0 24px 24px;color:#55707d}

/* Notes */
.rv-side-title{font-size:20px;margin-bottom:18px}.rv-notes-card{padding:17px;border:1px solid var(--rv-line);border-radius:8px;background:#fff}.rv-note-empty{min-height:245px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:15px;border:1px dashed #cbd6dc;border-radius:8px;text-align:center;color:#244655;cursor:pointer;padding:20px}.rv-note-empty:hover{border-color:#9cc18a;background:#fbfdf9}.rv-note-empty-icon{width:58px;height:58px;display:grid;place-items:center;border-radius:50%;background:#f5f5f3;color:#244a58;font-size:24px}.rv-note-list{display:grid;gap:10px;margin-bottom:12px}.rv-note-item{padding:11px;border:1px solid var(--rv-line-soft);border-radius:7px;background:#fbfcfc}.rv-note-item-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}.rv-note-item strong{font-size:12px;color:#173d4c}.rv-note-item time{font-size:10px;color:#8a9aa2}.rv-note-item p{margin:0;color:#4e6975;font-size:12px;line-height:1.45;white-space:pre-wrap}.rv-note-editor{position:relative}.rv-note-editor textarea{min-height:95px;border-color:#79ad64}.rv-mention-menu{display:none;position:absolute;left:8px;right:8px;top:102px;z-index:2400;max-height:210px;overflow:auto;padding:4px 0;border:1px solid var(--rv-line);border-radius:7px;background:#fff;box-shadow:0 10px 24px rgba(0,17,49,.14)}.rv-mention-menu.show{display:block}.rv-mention-option{width:100%;padding:8px 10px;display:flex;align-items:center;gap:9px;border:0;background:#fff;color:#294957;text-align:left;cursor:pointer}.rv-mention-option:hover{background:#f1f0ed}.rv-avatar{width:27px;height:27px;display:grid;place-items:center;border-radius:50%;background:#edf6e8;color:#2f8d25;font-size:10px;font-weight:700}.rv-mention-option small{display:block;color:#81919a;font-size:10px}.rv-note-drop{margin-top:10px;min-height:72px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:5px;border:1px dashed #d0dbe0;border-radius:7px;color:#6b818d;font-size:11px;cursor:pointer}.rv-note-drop button{height:32px;padding:0 11px;border:1px solid var(--rv-line);border-radius:6px;background:#fff;color:var(--rv-green-dark);font-weight:700}.rv-note-file-list{display:grid;gap:5px;margin-top:7px}.rv-note-file{padding:6px 8px;border:1px solid var(--rv-line-soft);border-radius:6px;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rv-add-note-btn{width:100%;height:37px;border:1px solid var(--rv-line);border-radius:7px;background:#fff;color:var(--rv-green-dark);font-weight:700;cursor:pointer}

/* History drawer */
.rv-drawer-backdrop{position:fixed;inset:0;z-index:11990;display:none;background:rgba(0,17,49,.18)}.rv-drawer-backdrop.show{display:block}.rv-history{width:min(420px,92vw);position:fixed;top:0;right:0;bottom:0;z-index:12000;transform:translateX(100%);background:#fff;box-shadow:-12px 0 36px rgba(0,17,49,.15);transition:transform .22s ease;display:flex;flex-direction:column}.rv-history.show{transform:translateX(0)}.rv-history-head{padding:22px 21px 12px;display:flex;align-items:center;gap:12px}.rv-history-head h2{margin:0;color:#073247;font-size:24px}.rv-history-close{margin-left:auto;width:36px;height:36px;border:0;background:transparent;color:#284957;font-size:21px;cursor:pointer}.rv-history-filters{padding:8px 18px 13px;display:flex;gap:7px;flex-wrap:wrap;border-bottom:1px solid var(--rv-line-soft)}.rv-history-filter{height:34px;padding:0 11px;border:0;border-radius:999px;background:#ecebe8;color:#294957;font-size:12px}.rv-history-list{flex:1;overflow:auto;padding:15px 18px}.rv-history-item{display:grid;grid-template-columns:28px 1fr;gap:10px;padding:12px 0;border-bottom:1px solid var(--rv-line-soft)}.rv-history-avatar{width:26px;height:26px;display:grid;place-items:center;border-radius:50%;background:#173d4c;color:#fff;font-size:10px}.rv-history-item strong{display:block;color:#294957;font-size:13px}.rv-history-item small{display:block;margin-top:3px;color:#83949d;font-size:11px}.rv-history-detail{margin-top:7px;color:#526d79;font-size:12px;line-height:1.45}.rv-history-detail em{color:#85949b}.rv-history-empty{padding:28px 8px;text-align:center;color:#80919b}

/* Modals */
.rv-modal-bg{position:fixed;inset:0;z-index:20000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.4)}.rv-modal-bg.show{display:flex}.rv-modal{width:min(720px,100%);max-height:calc(100vh - 36px);overflow:auto;border-radius:10px;background:#fff;box-shadow:0 22px 60px rgba(0,17,49,.22)}.rv-modal.wide{width:min(1450px,100%)}.rv-modal.small{width:min(460px,100%)}.rv-modal-head{padding:20px 23px 12px;display:flex;align-items:center;gap:12px}.rv-modal-head h2{margin:0;color:#073247;font-size:23px}.rv-modal-close{margin-left:auto;width:34px;height:34px;border:0;border-radius:7px;background:transparent;color:#607783;font-size:19px;cursor:pointer}.rv-modal-body{padding:8px 23px 13px}.rv-modal-foot{padding:12px 23px 20px;display:flex;justify-content:flex-end;gap:8px}.rv-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.rv-modal-grid .full{grid-column:1/-1}.rv-cost-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0}.rv-cost-grid .rv-input{border-radius:0}.rv-cost-grid .rv-form-field:first-child .rv-input{border-radius:7px 0 0 7px}.rv-cost-grid .rv-form-field:last-child .rv-input{border-radius:0 7px 7px 0}

/* Checklist builder */
.rv-check-builder{display:grid;grid-template-columns:minmax(0,1fr) 310px;min-height:650px;border-top:1px solid var(--rv-line-soft)}.rv-check-canvas{padding:20px;overflow:auto;background:#f8fafb}.rv-check-manage{padding:18px;border-left:1px solid var(--rv-line);background:#fff}.rv-check-manage h3{margin:0 0 14px;color:#0b3142;font-size:17px}.rv-check-palette{display:grid;gap:7px}.rv-check-palette button{min-height:39px;padding:8px 10px;display:flex;align-items:center;gap:9px;border:1px solid var(--rv-line);border-radius:7px;background:#fff;color:#31505d;text-align:left;cursor:pointer}.rv-check-palette button:hover{border-color:#a9cf75;background:#f8fcf6;color:var(--rv-green-dark)}.rv-check-section{margin-bottom:14px;border:1px solid var(--rv-line);border-radius:8px;background:#fff;overflow:hidden}.rv-check-section-head{padding:10px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--rv-line-soft)}.rv-check-section-head input{flex:1;height:39px;border:0;font-size:16px;font-weight:700;outline:0;color:#173d4c}.rv-check-question{padding:13px;border-top:1px solid var(--rv-line-soft)}.rv-check-question:first-child{border-top:0}.rv-check-q-grid{display:grid;grid-template-columns:minmax(0,1fr) 210px 36px;gap:8px}.rv-check-options{display:grid;gap:6px;margin-top:8px;padding-left:8px}.rv-check-option-row{display:flex;gap:6px}.rv-check-option-row .rv-input{height:37px}.rv-check-required{margin-top:8px;display:flex;align-items:center;gap:7px;color:#536e7a}.rv-check-required input{width:17px;height:17px;accent-color:var(--rv-green)}

.rv-loading{min-height:420px;display:grid;place-items:center;color:#7c8e98}.rv-spinner{width:26px;height:26px;border:3px solid #dce6d8;border-top-color:var(--rv-green);border-radius:50%;animation:rvSpin .8s linear infinite}@keyframes rvSpin{to{transform:rotate(360deg)}}
.rv-error{max-width:620px;margin:70px auto;padding:25px;border:1px solid #f2d0d3;border-radius:9px;background:#fff7f7;text-align:center}.rv-error h2{color:#a73942}.rv-error p{color:#7c5a5f}

@media(max-width:1199.98px){.rv-layout{grid-template-columns:minmax(0,1fr) 290px}.rv-assessment-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.rv-assessment-checklists{grid-column:1/-1}.rv-line{grid-template-columns:minmax(210px,1fr) 110px 140px 140px 38px}}
@media(max-width:991.98px){.rv-layout{grid-template-columns:1fr}.rv-main{border-right:0}.rv-side{border-top:1px solid var(--rv-line-soft);padding:24px 22px}.rv-side-inner{position:static}.rv-header-info{grid-template-columns:1fr}.rv-assessment-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.rv-assessment-checklists{grid-column:1/-1}.rv-check-builder{grid-template-columns:1fr}.rv-check-manage{border-left:0;border-top:1px solid var(--rv-line)}}
@media(max-width:767.98px){.rv-main-inner{padding:20px 16px 42px}.rv-side{padding:20px 16px}.rv-top{flex-wrap:wrap}.rv-top-spacer{display:none}.rv-title{font-size:25px}.rv-header-info{grid-template-columns:1fr}.rv-customer-card{min-height:auto}.rv-requested{grid-template-columns:105px 1fr}.rv-card-inner,.rv-assessment-editor,.rv-assessment-display,.rv-lines-head{padding-left:17px;padding-right:17px}.rv-line-list{padding:0 17px}.rv-line{grid-template-columns:1fr 1fr}.rv-line-item,.rv-line-desc{grid-column:1/-1}.rv-line-image{grid-column:1/-1}.rv-remove-line{position:absolute;right:17px}.rv-line{position:relative;padding-right:44px}.rv-totals{grid-template-columns:1fr;padding-left:17px;padding-right:17px}.rv-total-box{grid-column:1}.rv-display-line{grid-template-columns:1fr 80px}.rv-display-line .rv-display-right:nth-child(n+4){display:none}.rv-modal-grid,.rv-cost-grid{grid-template-columns:1fr}.rv-modal-grid .full{grid-column:auto}.rv-cost-grid{gap:12px}.rv-cost-grid .rv-input,.rv-cost-grid .rv-form-field:first-child .rv-input,.rv-cost-grid .rv-form-field:last-child .rv-input{border-radius:7px}.rv-check-q-grid{grid-template-columns:1fr}.rv-check-q-grid button{justify-self:end}}
@media(max-width:767.98px){.rv-assessment-grid{grid-template-columns:1fr;gap:18px}.rv-assessment-checklists{grid-column:auto}.rv-checklist-copy{grid-template-columns:1fr}.rv-checklist-items{grid-column:auto}.rv-checklist-box{padding:13px}.rv-check-row{white-space:normal}}
@media(max-width:520px){.rv-action-btn span{display:none}.rv-action-btn{width:42px;padding:0}.rv-history-filters{gap:5px}.rv-history-filter{padding:0 8px}.rv-requested{grid-template-columns:1fr;gap:5px}.rv-section-head h2,.rv-side-title,.rv-assessment-title,.rv-lines-head h2{font-size:20px}}
@media print{.fieldplx-topbar,.fieldplx-sidebar,.fieldplx-footer,.rv-side,.rv-top,.rv-edit,.rv-title-edit,.rv-more-wrap{display:none!important}.fieldplx-main-content{margin-left:0!important}.rv-layout{display:block}.rv-main-inner{max-width:none;padding:15px}.rv-card{break-inside:avoid}.rv-section{break-inside:avoid}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/includes/nav.php'; ?>
<div class="fieldplx-main-layout">
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>
<main class="fieldplx-main-content">
<div class="fieldplx-content-wrapper">
<div class="rv-page" id="requestViewPage">
  <div class="rv-layout">
    <div class="rv-main">
      <div class="rv-main-inner" id="rvMainContent">
        <div class="rv-loading"><div><div class="rv-spinner" style="margin:auto"></div><div style="margin-top:12px">Loading request...</div></div></div>
      </div>
    </div>
    <aside class="rv-side">
      <div class="rv-side-inner">
        <h2 class="rv-side-title">Notes</h2>
        <div class="rv-notes-card" id="rvNotesCard"><div class="rv-loading" style="min-height:260px"><div class="rv-spinner"></div></div></div>
      </div>
    </aside>
  </div>
</div>
</div>
</main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php require_once __DIR__ . '/includes/toast.php'; ?>

<!-- Request History -->
<div class="rv-drawer-backdrop" id="historyBackdrop"></div>
<aside class="rv-history" id="historyDrawer" aria-hidden="true">
  <div class="rv-history-head"><h2>Request History</h2><button class="rv-history-close" type="button" id="historyClose"><i class="bi bi-x-lg"></i></button></div>
  <div class="rv-history-filters">
    <select class="rv-history-filter" id="historyTeam"><option value="">Team | All</option></select>
    <select class="rv-history-filter" id="historyType"><option value="">Type | All</option></select>
    <select class="rv-history-filter" id="historyDate"><option value="">Date | All</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option></select>
  </div>
  <div class="rv-history-list" id="historyList"></div>
</aside>

<!-- Product / Service quick create -->
<div class="rv-modal-bg" id="catalogModal">
  <div class="rv-modal" role="dialog" aria-modal="true">
    <div class="rv-modal-head"><h2>Add Product / Service</h2><button class="rv-modal-close" type="button" data-close-modal="catalogModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="rv-modal-body">
      <div class="rv-modal-grid">
        <div class="rv-form-field full"><label>Item type</label><select class="rv-select" id="newItemType"><option value="service">Service</option><option value="product">Product</option></select></div>
        <div class="rv-form-field full"><label>Name</label><input class="rv-input" id="newItemName" maxlength="190"></div>
        <div class="rv-form-field full"><label>Description</label><textarea class="rv-textarea" id="newItemDescription"></textarea></div>
      </div>
      <div class="rv-cost-grid">
        <div class="rv-form-field"><label>Unit cost</label><input class="rv-input" type="number" min="0" step="0.01" id="newItemCost" value="0.00"></div>
        <div class="rv-form-field"><label>Markup %</label><input class="rv-input" type="number" min="0" step="0.01" id="newItemMarkup" value="0.00"></div>
        <div class="rv-form-field"><label>Unit price</label><input class="rv-input" type="number" min="0" step="0.01" id="newItemPrice" value="0.00"></div>
      </div>
      <div class="rv-form-field" style="max-width:190px"><label>Tax %</label><input class="rv-input" type="number" min="0" max="100" step="0.01" id="newItemTax" value="0.00"></div>
    </div>
    <div class="rv-modal-foot"><button class="rv-small-btn" type="button" data-close-modal="catalogModal">Cancel</button><button class="rv-small-btn primary" type="button" id="createCatalogBtn">Create</button></div>
  </div>
</div>

<!-- Checklist builder -->
<div class="rv-modal-bg" id="checklistModal">
  <div class="rv-modal wide" role="dialog" aria-modal="true">
    <div class="rv-modal-head"><h2>Edit New Checklist</h2><button class="rv-modal-close" type="button" data-close-modal="checklistModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="rv-modal-body" style="padding:0">
      <div style="padding:15px 20px;border-top:1px solid var(--rv-line-soft)"><div class="rv-form-field" style="margin:0"><label>Form title</label><input class="rv-input" id="checklistName" value="New checklist"></div></div>
      <div class="rv-check-builder">
        <div class="rv-check-canvas" id="checklistCanvas"></div>
        <aside class="rv-check-manage"><h3>Manage checklist</h3><div class="rv-check-palette">
          <button type="button" data-check-add="section"><i class="bi bi-plus-square"></i> Add section</button>
          <button type="button" data-check-add="short_answer"><i class="bi bi-text-left"></i> Short answer</button>
          <button type="button" data-check-add="long_answer"><i class="bi bi-text-paragraph"></i> Long answer</button>
          <button type="button" data-check-add="dropdown"><i class="bi bi-menu-button-wide"></i> Dropdown - single choice</button>
          <button type="button" data-check-add="checkbox"><i class="bi bi-check2-square"></i> Checkbox</button>
          <button type="button" data-check-add="number"><i class="bi bi-123"></i> Numerical answer</button>
          <button type="button" data-check-add="image"><i class="bi bi-image"></i> Upload images</button>
          <button type="button" data-check-add="date"><i class="bi bi-calendar3"></i> Date picker</button>
          <button type="button" data-check-add="signature"><i class="bi bi-pen"></i> Signature</button>
        </div></aside>
      </div>
    </div>
    <div class="rv-modal-foot"><button class="rv-small-btn" type="button" data-close-modal="checklistModal">Cancel</button><button class="rv-small-btn primary" type="button" id="saveChecklistBtn">Save Checklist</button></div>
  </div>
</div>

<!-- Confirm modal -->
<div class="rv-modal-bg" id="confirmModal">
  <div class="rv-modal small" role="dialog" aria-modal="true">
    <div class="rv-modal-head"><h2 id="confirmTitle">Confirm</h2><button class="rv-modal-close" type="button" data-close-modal="confirmModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="rv-modal-body"><p id="confirmText" style="margin:0;color:#536e7a;line-height:1.55"></p></div>
    <div class="rv-modal-foot"><button class="rv-small-btn" type="button" data-close-modal="confirmModal">Cancel</button><button class="rv-small-btn primary" type="button" id="confirmActionBtn">Continue</button></div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
'use strict';
var requestId = <?= (int)$requestId ?>;
var csrfToken = <?= json_encode($requestViewCsrf, JSON_UNESCAPED_SLASHES) ?>;
var apiUrl = 'api/request-view.php';
var state = {data:null,catalog:[],users:[],currency:{},lines:[],assessment:null,checklistDraft:null,lineEdit:false,assessmentEdit:false,noteEdit:false,catalogTarget:null,history:[]};

function esc(v){return String(v==null?'':v).replace(/[&<>'"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]})}
function readable(v){return String(v||'').replace(/_/g,' ').replace(/\b\w/g,function(m){return m.toUpperCase()})}
function toast(type,msg,duration){if(typeof window.fieldplxToast==='function'){window.fieldplxToast(type,msg,duration||4200)}else{console.log(type,msg)}}
function api(action,fields,files){var fd=new FormData();fd.append('action',action);fd.append('csrf_token',csrfToken);if(action!=='create_catalog_item')fd.append('request_id',requestId);Object.keys(fields||{}).forEach(function(k){fd.append(k,fields[k])});if(files){Object.keys(files).forEach(function(k){(files[k]||[]).forEach(function(file){fd.append(k+'[]',file)})})}return fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json().catch(function(){throw new Error('Invalid server response.')}).then(function(j){if(!r.ok||!j.success)throw new Error(j.message||'Request failed.');return j})})}
function money(n){n=Number(n||0);var c=state.currency||{},d=Number(c.decimal_places==null?2:c.decimal_places);var formatted=n.toLocaleString(undefined,{minimumFractionDigits:d,maximumFractionDigits:d});var symbol=c.symbol||'';return c.symbol_position==='after'?formatted+(symbol?' '+symbol:''):(symbol||'')+formatted}
function formatDate(v,withTime){if(!v)return '-';var d=new Date(String(v).replace(' ','T'));if(isNaN(d.getTime()))return v;var opts={day:'2-digit',month:'short',year:'numeric'};if(withTime){opts.hour='2-digit';opts.minute='2-digit'}return d.toLocaleString('en-IN',opts)}
function initials(name){var p=String(name||'').trim().split(/\s+/).filter(Boolean);return ((p[0]?p[0][0]:'')+(p.length>1?p[p.length-1][0]:'')).toUpperCase()||'U'}
function address(r){return [r.location_address1,r.location_address2,[r.location_city,r.location_state,r.location_postal_code].filter(Boolean).join(', ')].filter(Boolean).join('\n')}
function openModal(id){document.getElementById(id).classList.add('show');document.body.style.overflow='hidden'}
function closeModal(id){document.getElementById(id).classList.remove('show');if(!document.querySelector('.rv-modal-bg.show')&&!document.getElementById('historyDrawer').classList.contains('show'))document.body.style.overflow=''}
document.addEventListener('click',function(e){var b=e.target.closest('[data-close-modal]');if(b)closeModal(b.getAttribute('data-close-modal'))});

function load(){if(requestId<=0){renderError('Invalid request.');return}api('load',{}).then(function(j){state.data=j.data;state.catalog=j.data.catalog||[];state.users=j.data.users||[];state.currency=j.data.currency||{};state.lines=j.data.line_items||[];state.assessment=j.data.assessment||null;state.history=j.data.history||[];renderAll()}).catch(function(e){renderError(e.message)})}
function renderError(msg){document.getElementById('rvMainContent').innerHTML='<div class="rv-error"><h2>Request unavailable</h2><p>'+esc(msg)+'</p><a class="rv-small-btn" style="display:inline-flex;align-items:center" href="requests.php">Back to Requests</a></div>';document.getElementById('rvNotesCard').innerHTML='<div class="rv-history-empty">Request unavailable.</div>'}

function renderAll(){renderMain();renderNotes();renderHistoryFilters();renderHistory()}
function renderMain(){var d=state.data,r=d.request||{},hasAssessment=!!state.assessment;var html='';
html+='<div class="rv-top"><span class="rv-request-icon"><i class="bi bi-inbox"></i></span><span class="rv-status">'+esc(readable(r.status||'new'))+'</span><span class="rv-request-no">'+esc(r.request_no||'')+'</span><span class="rv-top-spacer"></span><button class="rv-icon-btn" id="historyOpen" type="button" title="Request history"><i class="bi bi-clock-history"></i></button><div class="rv-more-wrap"><button class="rv-action-btn" id="moreBtn" type="button"><i class="bi bi-three-dots"></i> <span>More</span></button><div class="rv-more-menu" id="moreMenu"><a class="rv-more-item" id="convertQuoteLink" href="#"><i class="bi bi-cash-coin"></i> Convert to Quote</a><a class="rv-more-item" id="convertJobLink" href="#"><i class="bi bi-hammer"></i> Convert to Job</a><div class="rv-more-sep"></div><button class="rv-more-item" type="button" id="archiveBtn"><i class="bi bi-archive"></i> Archive</button><button class="rv-more-item" type="button" id="printBtn"><i class="bi bi-printer"></i> Print</button><button class="rv-more-item danger" type="button" id="deleteBtn"><i class="bi bi-trash"></i> Delete</button></div></div>'+(hasAssessment?'':'<button class="rv-action-btn primary" type="button" id="assessmentTopBtn"><i class="bi bi-calendar3"></i> <span>Schedule Assessment</span></button>')+'</div>';
html+='<div class="rv-title-row"><h1 class="rv-title">'+esc(r.title||'Service Request')+'</h1><button class="rv-title-edit" type="button" id="titleEditBtn"><i class="bi bi-pencil"></i></button></div>';
html+='<div class="rv-header-info"><div class="rv-customer-card"><button class="rv-customer-more" type="button" id="customerMore"><i class="bi bi-three-dots"></i></button><div class="rv-customer-name">'+esc(r.client_name||'Customer')+' <span class="rv-customer-dot"></span></div><div class="rv-address">'+esc(address(r)||r.location_name||'')+'</div><div class="rv-customer-links">'+(r.client_phone?'<a href="tel:'+esc(r.client_phone)+'">'+esc(r.client_phone)+'</a>':'')+(r.client_email?'<a href="mailto:'+esc(r.client_email)+'">'+esc(r.client_email)+'</a>':'')+'</div></div><div class="rv-requested"><span>Requested</span><strong>'+esc(formatDate(r.created_at,false))+'</strong></div></div>';
html+=renderOverview();
html+=renderAssessment();
html+=renderLines();
document.getElementById('rvMainContent').innerHTML=html;bindMain();}

function renderOverview(){var r=state.data.request||{},imgs=(state.data.attachments||[]).filter(function(a){return String(a.attachment_type||'').indexOf('before_photo')>=0||String(a.file_mime||'').indexOf('image/')===0});if(state.overviewEdit){return '<section class="rv-section"><div class="rv-card"><div class="rv-card-inner"><div class="rv-section-head"><h2>Overview</h2></div><div class="rv-form-field"><label>Title</label><input class="rv-input" id="overviewTitle" value="'+esc(r.title||'')+'"></div><div class="rv-form-field"><label>Service details</label><textarea class="rv-textarea" id="overviewDescription">'+esc(r.description||'')+'</textarea></div><div class="rv-form-field"><label>How did you hear about us?</label><select class="rv-select" id="overviewSource">'+['office','website','portal','phone','sms','email','ai','other'].map(function(v){return '<option value="'+v+'" '+(r.source===v?'selected':'')+'>'+readable(v)+'</option>'}).join('')+'</select></div><div class="rv-editor-actions"><button class="rv-small-btn" id="overviewCancel" type="button">Cancel</button><button class="rv-small-btn primary" id="overviewSave" type="button">Save</button></div></div></div></section>'}
var imageHtml=imgs.length?'<div class="rv-work-images">'+imgs.slice(0,10).map(function(a){return '<a class="rv-work-image" href="'+esc(a.file_path||'#')+'" target="_blank"><img src="'+esc(a.file_path||'')+'" alt=""></a>'}).join('')+'</div>':'<div class="rv-placeholder">—</div>';
return '<section class="rv-section"><div class="rv-card"><div class="rv-card-inner"><div class="rv-section-head"><h2>Overview</h2><button class="rv-edit" id="overviewEdit" type="button"><i class="bi bi-pencil"></i></button></div><div class="rv-info-block"><div class="rv-subtitle">Service details</div><div class="rv-helper">Please provide as much information as you can</div><div class="rv-copy">'+esc(r.description||'—')+'</div></div><div class="rv-info-block"><div class="rv-helper">Share images of the work to be done</div>'+imageHtml+'</div><div class="rv-info-block"><div class="rv-helper">How did you hear about us?</div><div class="rv-copy">'+esc(r.source?readable(r.source):'—')+'</div></div></div></div></section>'}

function assignedNames(){var ids=(state.assessment&&state.assessment.assigned_user_ids)||[];return ids.map(function(id){var u=state.users.find(function(x){return Number(x.id)===Number(id)});return u?u.name:null}).filter(Boolean)}
function assessmentSchedule(a){if(!a)return '';if(!a.scheduled_start)return 'Schedule later';return formatDate(a.scheduled_start,true)+(a.scheduled_end?' - '+formatDate(a.scheduled_end,true):'')}
function renderAssessment(){
var a=state.assessment,checklists=state.data.checklists||[];
if(!a&&!state.assessmentEdit){return '<section class="rv-section"><h2 class="rv-assessment-title">On-site assessment</h2><div class="rv-assessment-empty" id="assessmentEmpty"><div class="rv-plus">+</div><div>Visit the property to assess the job before you do the work</div></div></section>'}
var startDate='',endDate='',startTime='',endTime='',scheduleLater=true,anytime=false;
if(a&&a.scheduled_start){var sd=String(a.scheduled_start).replace(' ','T');var ed=String(a.scheduled_end||a.scheduled_start).replace(' ','T');startDate=sd.slice(0,10);startTime=sd.slice(11,16);endDate=ed.slice(0,10);endTime=ed.slice(11,16);scheduleLater=(a.schedule_later!=null?Number(a.schedule_later)===1:false);anytime=(a.anytime!=null?Number(a.anytime)===1:(startTime==='00:00'&&endTime==='23:59'))}
else if(a){scheduleLater=(a.schedule_later!=null?Number(a.schedule_later)===1:true);anytime=(a.anytime!=null?Number(a.anytime)===1:false)}
var closeBtn=!a?'<button class="rv-edit" id="assessmentClose" type="button" aria-label="Close assessment"><i class="bi bi-x-lg"></i></button>':'';
var draftChip=state.checklistDraft?'<span class="rv-checklist-chip"><i class="bi bi-check-circle"></i> '+esc(state.checklistDraft.name)+'</span>':'';
var savedChips=checklists.map(function(c){return '<span class="rv-checklist-chip"><i class="bi bi-clipboard-check"></i> '+esc(c.name)+' ('+Number(c.item_count||0)+')</span>'}).join('');
return '<section class="rv-section"><div class="rv-assessment-card"><div class="rv-assessment-editor"><div class="rv-section-head"><h2>On-site assessment</h2>'+closeBtn+'</div><textarea class="rv-textarea" id="assessmentInstructions" placeholder="Instructions">'+esc(a&&a.notes?a.notes:'')+'</textarea><div class="rv-assessment-grid"><div class="rv-assessment-col"><h3>Schedule</h3><div class="rv-date-pair"><input class="rv-input" type="date" id="assessmentStartDate" value="'+esc(startDate)+'"><input class="rv-input" type="date" id="assessmentEndDate" value="'+esc(endDate)+'"></div><label class="rv-check-row"><input type="checkbox" id="scheduleLater" '+(scheduleLater?'checked':'')+'> Schedule later</label><div class="rv-time-pair"><input class="rv-input" type="time" id="assessmentStartTime" value="'+esc(startTime)+'"><input class="rv-input" type="time" id="assessmentEndTime" value="'+esc(endTime)+'"></div><label class="rv-check-row"><input type="checkbox" id="assessmentAnytime" '+(anytime?'checked':'')+'> Anytime</label></div><div class="rv-assessment-col rv-assessment-team"><h3>Team</h3><select id="assessmentTeam" multiple></select><label class="rv-check-row"><input type="checkbox" id="emailTeam"> Email team when assigned</label><div class="rv-form-field"><label>Team reminder</label><select class="rv-select" id="teamReminder"><option value="none" '+((a&&a.team_reminder==='none')||!a||!a.team_reminder?'selected':'')+'>No reminder set</option><option value="15_minutes" '+(a&&a.team_reminder==='15_minutes'?'selected':'')+'>15 minutes before</option><option value="1_hour" '+(a&&a.team_reminder==='1_hour'?'selected':'')+'>1 hour before</option><option value="1_day" '+(a&&a.team_reminder==='1_day'?'selected':'')+'>1 day before</option></select></div></div><div class="rv-assessment-col rv-assessment-checklists"><h3>Checklists</h3><div class="rv-checklist-box"><div class="rv-checklist-icon"><i class="bi bi-clipboard2-check"></i></div><div class="rv-checklist-copy"><div class="rv-checklist-text"><strong>CAPTURE ON-SITE DETAILS</strong><p>Attach custom-built checklists so that nothing gets missed</p></div><button class="rv-link-btn" id="createChecklistBtn" type="button">Create a Checklist</button><div class="rv-checklist-items" id="checklistDraftSummary">'+draftChip+savedChips+'</div></div></div></div></div><div class="rv-editor-actions" style="margin-top:18px"><button class="rv-small-btn" id="assessmentCancel" type="button">Cancel</button><button class="rv-small-btn primary" id="assessmentSave" type="button">Save</button></div></div></div></section>'}

function renderLines(){var lines=state.lines||[];if(state.lineEdit){return '<section class="rv-section"><div class="rv-lines-card"><div class="rv-lines-head"><h2>Product / Service</h2><p>Keep everything on track by adding products and services.</p><button class="rv-small-btn primary" id="addLineBtn" type="button">Add Line Item</button></div><div class="rv-line-list" id="lineEditorList">'+lines.map(function(l,i){return lineEditorRow(l,i)}).join('')+'</div>'+totalsHtml(lines)+'<div class="rv-line-editor-actions"><button class="rv-small-btn" id="lineCancel" type="button">Cancel</button><button class="rv-small-btn primary" id="lineSave" type="button">Save</button></div></div></section>'}
if(!lines.length){return '<section class="rv-section"><div class="rv-lines-card"><div class="rv-lines-head"><h2>Product / Service</h2><p>Keep everything on track by adding products and services.</p><button class="rv-small-btn primary" id="addLineFromEmpty" type="button">Add Line Item</button></div>'+totalsHtml([])+'</div></section>'}
return '<section class="rv-section"><div class="rv-lines-card"><div class="rv-lines-head" style="display:flex;align-items:flex-start;gap:12px"><div><h2>Product / Service</h2><p style="margin-bottom:0">Keep everything on track by adding products and services.</p></div><button class="rv-edit" id="lineEdit" style="margin-left:auto" type="button"><i class="bi bi-pencil"></i></button></div><div class="rv-lines-display">'+lines.map(function(l){return '<div class="rv-display-line"><div class="rv-display-line-name"><strong>'+esc(l.item_name)+'</strong><small>'+esc(l.description||readable(l.item_type))+'</small></div><div class="rv-display-right">'+Number(l.quantity||0).toFixed(2)+'</div><div class="rv-display-right">'+money(l.unit_price)+'</div><div class="rv-display-right"><strong>'+money(l.line_total)+'</strong></div></div>'}).join('')+'</div>'+totalsHtml(lines)+'</div></section>'}
function lineEditorRow(l,i){return '<div class="rv-line" data-line-index="'+i+'"><div class="rv-line-item"><select class="rv-catalog-select" data-i="'+i+'"></select></div><div class="rv-money"><small>Quantity</small><input class="rv-line-qty" data-i="'+i+'" type="number" min="0.001" step="0.001" value="'+esc(Number(l.quantity||1))+'"></div><div class="rv-money"><small>Unit price</small><input class="rv-line-price" data-i="'+i+'" type="number" min="0" step="0.01" value="'+esc(Number(l.unit_price||0).toFixed(2))+'"></div><div class="rv-money"><small>Total</small><strong class="rv-line-total" data-i="'+i+'">'+money(l.line_total||0)+'</strong></div><button class="rv-remove-line" type="button" data-remove-line="'+i+'"><i class="bi bi-three-dots"></i></button><textarea class="rv-textarea rv-line-desc" data-i="'+i+'" placeholder="Description">'+esc(l.description||'')+'</textarea><label class="rv-line-image" title="Attach image"><i class="bi bi-image"></i><input class="rv-line-image-input" data-i="'+i+'" type="file" accept="image/*" hidden></label></div>'}
function totalsHtml(lines){var sub=0,total=0;(lines||[]).forEach(function(l){sub+=Number(l.quantity||0)*Number(l.unit_price||0);total+=Number(l.line_total!=null?l.line_total:(Number(l.quantity||0)*Number(l.unit_price||0)))});return '<div class="rv-totals"><div class="rv-total-box"><div class="rv-total-row"><span>Subtotal</span><span>'+money(sub)+'</span></div><div class="rv-total-row grand"><span>Total</span><span>'+money(total)+'</span></div></div></div>'}

function bindMain(){var r=state.data.request||{};var q={request_id:requestId,client_id:r.client_id};if(r.location_id)q.location_id=r.location_id;if(r.product_service_id)q.product_service_id=r.product_service_id;var qs=new URLSearchParams(q).toString();document.getElementById('convertQuoteLink').href='add-quotation.php?'+qs;document.getElementById('convertJobLink').href='job-form.php?'+qs;
document.getElementById('historyOpen').onclick=openHistory;document.getElementById('moreBtn').onclick=function(e){e.stopPropagation();document.getElementById('moreMenu').classList.toggle('show')};document.getElementById('printBtn').onclick=function(){window.print()};document.getElementById('archiveBtn').onclick=function(){confirmAction('Archive Request','Archive this service request? It will remain in your records.',function(){api('archive',{}).then(function(j){toast('success',j.message);load()}).catch(function(e){toast('error',e.message)})})};document.getElementById('deleteBtn').onclick=function(){confirmAction('Delete Request','Delete this service request? This action uses the available soft-delete/cancel behavior.',function(){api('delete',{}).then(function(j){toast('success',j.message);setTimeout(function(){location.href='requests.php'},500)}).catch(function(e){toast('error',e.message)})})};document.getElementById('customerMore').onclick=function(){location.href='client-view.php?client_id='+encodeURIComponent(r.client_id)};document.getElementById('titleEditBtn').onclick=function(){state.overviewEdit=true;renderMain()};var assessmentTopBtn=document.getElementById('assessmentTopBtn');if(assessmentTopBtn)assessmentTopBtn.onclick=function(){state.assessmentEdit=true;renderMain()};
if(document.getElementById('overviewEdit'))document.getElementById('overviewEdit').onclick=function(){state.overviewEdit=true;renderMain()};if(document.getElementById('overviewCancel'))document.getElementById('overviewCancel').onclick=function(){state.overviewEdit=false;renderMain()};if(document.getElementById('overviewSave'))document.getElementById('overviewSave').onclick=saveOverview;
if(document.getElementById('assessmentEmpty'))document.getElementById('assessmentEmpty').onclick=function(){state.assessmentEdit=true;renderMain()};if(document.getElementById('assessmentClose'))document.getElementById('assessmentClose').onclick=function(){state.assessmentEdit=false;state.checklistDraft=null;renderMain()};if(document.getElementById('assessmentCancel'))document.getElementById('assessmentCancel').onclick=function(){state.assessmentEdit=false;state.checklistDraft=null;renderMain()};if(document.getElementById('assessmentSave')){initAssessmentSelect();bindScheduleSwitches();document.getElementById('assessmentSave').onclick=saveAssessment;document.getElementById('createChecklistBtn').onclick=openChecklist}
if(document.getElementById('addLineFromEmpty'))document.getElementById('addLineFromEmpty').onclick=function(){state.lineEdit=true;state.lines=[blankLine()];renderMain()};if(document.getElementById('lineEdit'))document.getElementById('lineEdit').onclick=function(){state.lineEdit=true;renderMain()};if(document.getElementById('addLineBtn')){initLineEditors();document.getElementById('addLineBtn').onclick=function(){state.lines.push(blankLine());renderMain();setTimeout(function(){var selects=document.querySelectorAll('.rv-catalog-select');if(selects.length)$(selects[selects.length-1]).select2('open')},20)};document.getElementById('lineCancel').onclick=function(){state.lineEdit=false;load()};document.getElementById('lineSave').onclick=saveLines;document.querySelectorAll('[data-remove-line]').forEach(function(b){b.onclick=function(){state.lines.splice(Number(b.getAttribute('data-remove-line')),1);renderMain()}})} }
document.addEventListener('click',function(){var m=document.getElementById('moreMenu');if(m)m.classList.remove('show')});

function saveOverview(){var btn=document.getElementById('overviewSave');btn.disabled=true;api('save_overview',{title:document.getElementById('overviewTitle').value,description:document.getElementById('overviewDescription').value,source:document.getElementById('overviewSource').value}).then(function(j){state.data.request=j.request;state.overviewEdit=false;toast('success',j.message);renderMain();state.history.unshift({event_type:'service_request_overview_updated',title:'Request overview updated',created_at:new Date().toISOString(),actor_name:'You',details:{}});renderHistory()}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})}
function initAssessmentSelect(){var el=$('#assessmentTeam');el.empty();state.users.forEach(function(u){el.append(new Option(u.name,u.id,false,(state.assessment&&state.assessment.assigned_user_ids||[]).map(Number).indexOf(Number(u.id))>=0))});el.select2({placeholder:'Assign team members',closeOnSelect:false,width:'100%'}).trigger('change')}
function bindScheduleSwitches(){function sync(){var later=document.getElementById('scheduleLater').checked,any=document.getElementById('assessmentAnytime').checked;['assessmentStartDate','assessmentEndDate'].forEach(function(id){document.getElementById(id).disabled=later});['assessmentStartTime','assessmentEndTime'].forEach(function(id){document.getElementById(id).disabled=later||any})}document.getElementById('scheduleLater').onchange=sync;document.getElementById('assessmentAnytime').onchange=sync;sync()}
function saveAssessment(){var btn=document.getElementById('assessmentSave');btn.disabled=true;var ids=$('#assessmentTeam').val()||[];api('save_assessment',{assessment_id:state.assessment?state.assessment.id:0,instructions:document.getElementById('assessmentInstructions').value,schedule_later:document.getElementById('scheduleLater').checked?1:0,start_date:document.getElementById('assessmentStartDate').value,end_date:document.getElementById('assessmentEndDate').value,anytime:document.getElementById('assessmentAnytime').checked?1:0,start_time:document.getElementById('assessmentStartTime').value,end_time:document.getElementById('assessmentEndTime').value,assigned_user_ids:JSON.stringify(ids),email_team_when_assigned:document.getElementById('emailTeam').checked?1:0,team_reminder:document.getElementById('teamReminder').value,new_checklist_json:state.checklistDraft?JSON.stringify(state.checklistDraft):''}).then(function(j){state.assessment=j.assessment;state.data.checklists=j.checklists||state.data.checklists;state.assessmentEdit=false;state.checklistDraft=null;toast('success',j.message);renderMain();if(j.team_email&&j.team_email.email_failed)toast('warning',j.team_email.email_failed+' team email(s) failed.')}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})}

function blankLine(){return {catalog_key:'',item_name:'',item_type:'service',description:'',quantity:1,unit_cost:0,unit_price:0,tax_percent:0,tax_amount:0,line_total:0}}
function catalogById(id){return state.catalog.find(function(x){return String(x.id)===String(id)})}
function initLineEditors(){document.querySelectorAll('.rv-catalog-select').forEach(function(sel){var i=Number(sel.getAttribute('data-i')),line=state.lines[i]||blankLine();var $s=$(sel);$s.empty();$s.append(new Option('', '', false, false));state.catalog.forEach(function(c){$s.append(new Option(c.name,c.id,false,String(line.catalog_key||'')===String(c.id)))});$s.select2({placeholder:'Name',width:'100%',tags:true,createTag:function(params){var term=$.trim(params.term);if(!term)return null;return {id:'__create__:'+term,text:'+ Create new item',term:term,isNew:true}},templateResult:catalogResult,templateSelection:function(item){var c=catalogById(item.id);return c?c.name:(item.text||'')}}).on('select2:select',function(e){var d=e.params.data;if(String(d.id).indexOf('__create__:')===0){state.catalogTarget=i;document.getElementById('newItemName').value=d.term||String(d.id).replace('__create__:','');$s.val(null).trigger('change');openModal('catalogModal');return}var c=catalogById(d.id);if(c){state.lines[i].catalog_key=c.id;state.lines[i].item_name=c.name;state.lines[i].item_type=c.item_type;state.lines[i].description=c.description||'';state.lines[i].unit_cost=Number(c.unit_cost||0);state.lines[i].unit_price=Number(c.unit_price||0);state.lines[i].tax_percent=Number(c.tax_percent||0);recalcLine(i);renderMain()}})});document.querySelectorAll('.rv-line-qty').forEach(function(el){el.oninput=function(){var i=Number(el.dataset.i);state.lines[i].quantity=Number(el.value||0);recalcLine(i);updateLineTotal(i)}});document.querySelectorAll('.rv-line-price').forEach(function(el){el.oninput=function(){var i=Number(el.dataset.i);state.lines[i].unit_price=Number(el.value||0);recalcLine(i);updateLineTotal(i)}});document.querySelectorAll('.rv-line-desc').forEach(function(el){el.oninput=function(){state.lines[Number(el.dataset.i)].description=el.value}})}
function catalogResult(item){if(!item.id)return item.text;if(item.isNew||String(item.id).indexOf('__create__:')===0)return $('<div class="rv-create-option"><i class="bi bi-plus-circle"></i><span>'+esc(item.text)+'</span></div>');var c=catalogById(item.id);if(!c)return item.text;return $('<div class="rv-catalog-result"><div class="rv-catalog-main"><div class="rv-catalog-name"><span>'+esc(c.name)+'</span><span class="rv-badge '+esc(c.item_type)+'">'+esc(readable(c.item_type))+'</span></div><div class="rv-catalog-desc">'+esc(c.description||'')+'</div></div><div class="rv-catalog-price">'+esc(money(c.unit_price))+'</div></div>')}
function recalcLine(i){var l=state.lines[i];var base=Number(l.quantity||0)*Number(l.unit_price||0);l.tax_amount=base*Number(l.tax_percent||0)/100;l.line_total=base+l.tax_amount}
function updateLineTotal(i){var el=document.querySelector('.rv-line-total[data-i="'+i+'"]');if(el)el.textContent=money(state.lines[i].line_total);var card=el&&el.closest('.rv-lines-card');if(card){var t=card.querySelector('.rv-totals');if(t)t.outerHTML=totalsHtml(state.lines)}}
function saveLines(){document.querySelectorAll('.rv-line-desc').forEach(function(el){state.lines[Number(el.dataset.i)].description=el.value});var images=[];document.querySelectorAll('.rv-line-image-input').forEach(function(el){Array.from(el.files||[]).forEach(function(f){images.push(f)})});var btn=document.getElementById('lineSave');btn.disabled=true;api('save_lines',{line_items_json:JSON.stringify(state.lines)},{line_item_images:images}).then(function(j){state.lines=j.line_items||[];state.lineEdit=false;toast('success',j.message);if(j.line_images&&j.line_images.failed)toast('warning',j.line_images.failed+' line image(s) could not be saved.');renderMain()}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})}

/* Quick create catalog */
var priceManuallyEdited=false;function recalcNewPrice(){if(priceManuallyEdited)return;var c=Number(document.getElementById('newItemCost').value||0),m=Number(document.getElementById('newItemMarkup').value||0);document.getElementById('newItemPrice').value=(c*(1+m/100)).toFixed(2)}
document.getElementById('newItemCost').addEventListener('input',recalcNewPrice);document.getElementById('newItemMarkup').addEventListener('input',recalcNewPrice);document.getElementById('newItemPrice').addEventListener('input',function(){priceManuallyEdited=true});
document.getElementById('createCatalogBtn').onclick=function(){var btn=this;btn.disabled=true;api('create_catalog_item',{item_type:document.getElementById('newItemType').value,name:document.getElementById('newItemName').value,description:document.getElementById('newItemDescription').value,unit_cost:document.getElementById('newItemCost').value,markup_percent:document.getElementById('newItemMarkup').value,unit_price:document.getElementById('newItemPrice').value,tax_percent:document.getElementById('newItemTax').value}).then(function(j){var item=j.item;state.catalog.push(item);state.catalog.sort(function(a,b){return String(a.name).localeCompare(String(b.name))});if(state.catalogTarget!=null&&state.lines[state.catalogTarget]){state.lines[state.catalogTarget].catalog_key=item.id;state.lines[state.catalogTarget].item_name=item.name;state.lines[state.catalogTarget].item_type=item.item_type;state.lines[state.catalogTarget].description=item.description||'';state.lines[state.catalogTarget].unit_cost=Number(item.unit_cost||0);state.lines[state.catalogTarget].unit_price=Number(item.unit_price||0);state.lines[state.catalogTarget].tax_percent=Number(item.tax_percent||0);recalcLine(state.catalogTarget)}closeModal('catalogModal');toast('success',j.message);state.catalogTarget=null;priceManuallyEdited=false;renderMain()}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})};

/* Notes */
function renderNotes(){var notes=state.data.notes||[];var html='';if(!state.noteEdit){if(notes.length){html+='<div class="rv-note-list">'+notes.slice(0,4).map(function(n){return '<div class="rv-note-item"><div class="rv-note-item-top"><strong>'+esc(n.actor_name||'Team member')+'</strong><time>'+esc(formatDate(n.created_at,true))+'</time></div><p>'+esc(n.note||'')+'</p></div>'}).join('')+'</div><button class="rv-add-note-btn" id="openNoteEditor" type="button">Add internal note</button>'}else{html='<div class="rv-note-empty" id="openNoteEditor"><div class="rv-note-empty-icon"><i class="bi bi-journal-plus"></i></div><div>Leave an internal note for<br>yourself or a team member</div></div>'}}else{html='<div class="rv-note-editor"><textarea class="rv-textarea" id="noteText" placeholder="Use @ in notes to mention your team"></textarea><div class="rv-mention-menu" id="mentionMenu"></div></div><label class="rv-note-drop"><button type="button" onclick="document.getElementById(\'noteFiles\').click();return false">Attach files & photos</button><span>Select or drag files here to upload</span><input type="file" multiple hidden id="noteFiles"></label><div class="rv-note-file-list" id="noteFileList"></div><div class="rv-editor-actions"><button class="rv-small-btn" id="noteCancel" type="button">Cancel</button><button class="rv-small-btn primary" id="noteSave" type="button">Save Note</button></div>'}document.getElementById('rvNotesCard').innerHTML=html;var open=document.getElementById('openNoteEditor');if(open)open.onclick=function(){state.noteEdit=true;state.noteMentions=[];renderNotes();setTimeout(bindNoteEditor,0)};if(state.noteEdit)bindNoteEditor()}
function bindNoteEditor(){var tx=document.getElementById('noteText');if(!tx)return;state.noteMentions=state.noteMentions||[];tx.oninput=function(){showMentionMenu(tx)};document.getElementById('noteCancel').onclick=function(){state.noteEdit=false;renderNotes()};var input=document.getElementById('noteFiles');input.onchange=renderNoteFiles;var drop=document.querySelector('.rv-note-drop');if(drop){drop.ondragover=function(e){e.preventDefault();drop.style.background='#f8fcf6'};drop.ondragleave=function(){drop.style.background=''};drop.ondrop=function(e){e.preventDefault();drop.style.background='';if(e.dataTransfer&&e.dataTransfer.files){try{input.files=e.dataTransfer.files}catch(err){state.noteDroppedFiles=Array.from(e.dataTransfer.files)}renderNoteFiles()}}}document.getElementById('noteSave').onclick=saveNote}
function showMentionMenu(tx){var val=tx.value,pos=tx.selectionStart||0,before=val.slice(0,pos),m=before.match(/@([A-Za-z0-9._ -]*)$/),menu=document.getElementById('mentionMenu');if(!m){menu.classList.remove('show');return}var q=String(m[1]||'').toLowerCase();var users=state.users.filter(function(u){return String(u.name||'').toLowerCase().indexOf(q)>=0}).slice(0,8);menu.innerHTML=users.map(function(u){return '<button class="rv-mention-option" type="button" data-mention="'+u.id+'"><span class="rv-avatar">'+esc(initials(u.name))+'</span><span>'+esc(u.name)+'<small>'+esc(u.job_title||'Team member')+'</small></span></button>'}).join('');menu.classList.toggle('show',users.length>0);menu.querySelectorAll('[data-mention]').forEach(function(b){b.onclick=function(){var uid=Number(b.dataset.mention),u=state.users.find(function(x){return Number(x.id)===uid});var start=before.lastIndexOf('@');tx.value=val.slice(0,start)+'@'+String(u.name).replace(/\s+/g,'_')+' '+val.slice(pos);tx.focus();tx.selectionStart=tx.selectionEnd=start+String(u.name).replace(/\s+/g,'_').length+2;if(state.noteMentions.indexOf(uid)<0)state.noteMentions.push(uid);menu.classList.remove('show')}})}
function renderNoteFiles(){var files=(state.noteDroppedFiles&&state.noteDroppedFiles.length)?state.noteDroppedFiles:Array.from(document.getElementById('noteFiles').files||[]);document.getElementById('noteFileList').innerHTML=files.map(function(f){return '<div class="rv-note-file"><i class="bi bi-paperclip"></i> '+esc(f.name)+'</div>'}).join('')}
function saveNote(){var btn=document.getElementById('noteSave'),files=(state.noteDroppedFiles&&state.noteDroppedFiles.length)?state.noteDroppedFiles:Array.from(document.getElementById('noteFiles').files||[]);btn.disabled=true;api('add_note',{note:document.getElementById('noteText').value,mention_user_ids:JSON.stringify(state.noteMentions||[])},{note_files:files}).then(function(j){state.data.notes=j.notes||[];state.noteEdit=false;state.noteDroppedFiles=[];toast('success',j.message);renderNotes();loadHistoryOnly()}).catch(function(e){toast('error',e.message)}).finally(function(){btn.disabled=false})}

/* History */
function openHistory(){document.getElementById('historyDrawer').classList.add('show');document.getElementById('historyBackdrop').classList.add('show');document.body.style.overflow='hidden';renderHistory()}
function closeHistory(){document.getElementById('historyDrawer').classList.remove('show');document.getElementById('historyBackdrop').classList.remove('show');document.body.style.overflow=''}
document.getElementById('historyClose').onclick=closeHistory;document.getElementById('historyBackdrop').onclick=closeHistory;
function renderHistoryFilters(){var team=document.getElementById('historyTeam'),type=document.getElementById('historyType');var actors={},types={};state.history.forEach(function(h){if(h.actor_user_id)actors[h.actor_user_id]=h.actor_name||'Team member';if(h.event_type)types[h.event_type]=readable(h.event_type)});team.innerHTML='<option value="">Team | All</option>'+Object.keys(actors).map(function(k){return '<option value="'+esc(k)+'">'+esc(actors[k])+'</option>'}).join('');type.innerHTML='<option value="">Type | All</option>'+Object.keys(types).map(function(k){return '<option value="'+esc(k)+'">'+esc(types[k])+'</option>'}).join('');team.onchange=renderHistory;type.onchange=renderHistory;document.getElementById('historyDate').onchange=renderHistory}
function historyDetail(h){var d=h.details||{};if(h.event_type==='request_status_changed')return '<em>'+esc(readable(d.old_status||'empty'))+'</em> → <strong>'+esc(readable(d.new_status||''))+'</strong>'+(d.notes?'<div>'+esc(d.notes)+'</div>':'');if(d.note)return esc(d.note);if(d.old&&d.new&&d.old.description!==d.new.description)return '<em>Service details updated</em>';return ''}
function renderHistory(){var team=document.getElementById('historyTeam').value,type=document.getElementById('historyType').value,days=Number(document.getElementById('historyDate').value||0),cut=days?Date.now()-days*86400000:0;var rows=state.history.filter(function(h){if(team&&String(h.actor_user_id)!==String(team))return false;if(type&&h.event_type!==type)return false;if(cut&&new Date(String(h.created_at).replace(' ','T')).getTime()<cut)return false;return true});document.getElementById('historyList').innerHTML=rows.length?rows.map(function(h){return '<div class="rv-history-item"><div class="rv-history-avatar">'+esc(initials(h.actor_name||'System'))+'</div><div><strong>'+esc(h.title||readable(h.event_type))+'</strong><small>'+esc((h.actor_name||'System')+' · '+formatDate(h.created_at,true))+'</small><div class="rv-history-detail">'+historyDetail(h)+'</div></div></div>'}).join(''):'<div class="rv-history-empty">No matching request activity.</div>'}
function loadHistoryOnly(){api('load',{}).then(function(j){state.history=j.data.history||[];state.data.notes=j.data.notes||state.data.notes;renderHistoryFilters();renderHistory()}).catch(function(){})}

/* Checklist */
function newChecklistModel(){return {name:'New checklist',description:'',sections:[{title:'Section 1',questions:[{title:'Question',question_type:'short_answer',options:[],is_required:0}]}]}}
function openChecklist(){if(!state.checklistBuilder)state.checklistBuilder=newChecklistModel();document.getElementById('checklistName').value=state.checklistBuilder.name||'New checklist';renderChecklist();openModal('checklistModal')}
function renderChecklist(){var m=state.checklistBuilder||newChecklistModel();document.getElementById('checklistCanvas').innerHTML=m.sections.map(function(s,si){return '<div class="rv-check-section" data-section="'+si+'"><div class="rv-check-section-head"><input value="'+esc(s.title)+'" data-section-title="'+si+'"><button class="rv-remove-line" type="button" data-section-delete="'+si+'"><i class="bi bi-trash"></i></button></div><div>'+s.questions.map(function(q,qi){return checklistQuestion(q,si,qi)}).join('')+'</div><div style="padding:10px"><button class="rv-link-btn" type="button" data-add-question="'+si+'">+ Add Question</button></div></div>'}).join('');bindChecklist()}
function checklistQuestion(q,si,qi){var opts='';if(q.question_type==='dropdown'||q.question_type==='checkbox'){opts='<div class="rv-check-options">'+(q.options||[]).map(function(o,oi){return '<div class="rv-check-option-row"><input class="rv-input" value="'+esc(o)+'" data-option="'+si+','+qi+','+oi+'"><button class="rv-remove-line" style="height:37px" type="button" data-remove-option="'+si+','+qi+','+oi+'"><i class="bi bi-x"></i></button></div>'}).join('')+'<button class="rv-link-btn" type="button" data-add-option="'+si+','+qi+'">+ Add option</button></div>'}return '<div class="rv-check-question"><div class="rv-check-q-grid"><input class="rv-input" value="'+esc(q.title)+'" data-q-title="'+si+','+qi+'"><select class="rv-select" data-q-type="'+si+','+qi+'">'+[['short_answer','Short answer'],['long_answer','Long answer'],['dropdown','Dropdown'],['checkbox','Checkbox'],['number','Numerical answer'],['image','Upload images'],['date','Date picker'],['signature','Signature']].map(function(x){return '<option value="'+x[0]+'" '+(q.question_type===x[0]?'selected':'')+'>'+x[1]+'</option>'}).join('')+'</select><button class="rv-remove-line" style="height:43px" type="button" data-q-delete="'+si+','+qi+'"><i class="bi bi-trash"></i></button></div>'+opts+'<label class="rv-check-required"><input type="checkbox" data-q-required="'+si+','+qi+'" '+(q.is_required?'checked':'')+'> Required</label></div>'}
function parsePair(v){return String(v).split(',').map(Number)}
function bindChecklist(){document.querySelectorAll('[data-section-title]').forEach(function(e){e.oninput=function(){state.checklistBuilder.sections[Number(e.dataset.sectionTitle)].title=e.value}});document.querySelectorAll('[data-section-delete]').forEach(function(e){e.onclick=function(){state.checklistBuilder.sections.splice(Number(e.dataset.sectionDelete),1);if(!state.checklistBuilder.sections.length)state.checklistBuilder.sections.push({title:'Section 1',questions:[]});renderChecklist()}});document.querySelectorAll('[data-add-question]').forEach(function(e){e.onclick=function(){state.checklistBuilder.sections[Number(e.dataset.addQuestion)].questions.push({title:'Question',question_type:'short_answer',options:[],is_required:0});renderChecklist()}});document.querySelectorAll('[data-q-title]').forEach(function(e){e.oninput=function(){var p=parsePair(e.dataset.qTitle);state.checklistBuilder.sections[p[0]].questions[p[1]].title=e.value}});document.querySelectorAll('[data-q-type]').forEach(function(e){e.onchange=function(){var p=parsePair(e.dataset.qType),q=state.checklistBuilder.sections[p[0]].questions[p[1]];q.question_type=e.value;if((e.value==='dropdown'||e.value==='checkbox')&&!q.options.length)q.options=['Option 1'];renderChecklist()}});document.querySelectorAll('[data-q-delete]').forEach(function(e){e.onclick=function(){var p=parsePair(e.dataset.qDelete);state.checklistBuilder.sections[p[0]].questions.splice(p[1],1);renderChecklist()}});document.querySelectorAll('[data-q-required]').forEach(function(e){e.onchange=function(){var p=parsePair(e.dataset.qRequired);state.checklistBuilder.sections[p[0]].questions[p[1]].is_required=e.checked?1:0}});document.querySelectorAll('[data-option]').forEach(function(e){e.oninput=function(){var p=String(e.dataset.option).split(',').map(Number);state.checklistBuilder.sections[p[0]].questions[p[1]].options[p[2]]=e.value}});document.querySelectorAll('[data-add-option]').forEach(function(e){e.onclick=function(){var p=parsePair(e.dataset.addOption);state.checklistBuilder.sections[p[0]].questions[p[1]].options.push('Option '+(state.checklistBuilder.sections[p[0]].questions[p[1]].options.length+1));renderChecklist()}});document.querySelectorAll('[data-remove-option]').forEach(function(e){e.onclick=function(){var p=String(e.dataset.removeOption).split(',').map(Number);state.checklistBuilder.sections[p[0]].questions[p[1]].options.splice(p[2],1);renderChecklist()}})}
document.querySelectorAll('[data-check-add]').forEach(function(b){b.onclick=function(){var type=b.dataset.checkAdd;if(!state.checklistBuilder)state.checklistBuilder=newChecklistModel();if(type==='section'){state.checklistBuilder.sections.push({title:'Section '+(state.checklistBuilder.sections.length+1),questions:[]})}else{var s=state.checklistBuilder.sections[state.checklistBuilder.sections.length-1];s.questions.push({title:'Question',question_type:type,options:(type==='dropdown'||type==='checkbox')?['Option 1']:[],is_required:0})}renderChecklist()}});
document.getElementById('saveChecklistBtn').onclick=function(){var name=document.getElementById('checklistName').value.trim();if(!name){toast('warning','Enter a checklist title.');return}state.checklistBuilder.name=name;var items=[];state.checklistBuilder.sections.forEach(function(s){s.questions.forEach(function(q){if(String(q.title||'').trim())items.push({section_title:s.title,title:q.title,question_type:q.question_type,options:q.options||[],is_required:q.is_required?1:0})})});if(!items.length){toast('warning','Add at least one checklist question.');return}state.checklistDraft={name:name,description:'',items:items};closeModal('checklistModal');var sum=document.getElementById('checklistDraftSummary');if(sum)sum.innerHTML='<span class="rv-checklist-chip"><i class="bi bi-check-circle"></i> '+esc(name)+'</span>';toast('success','Checklist added.')};

/* Confirm */
var pendingConfirm=null;function confirmAction(title,text,fn){pendingConfirm=fn;document.getElementById('confirmTitle').textContent=title;document.getElementById('confirmText').textContent=text;openModal('confirmModal')}document.getElementById('confirmActionBtn').onclick=function(){var fn=pendingConfirm;pendingConfirm=null;closeModal('confirmModal');if(fn)fn()};

load();
})();
</script>
</body>
</html>
