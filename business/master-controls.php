<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Master Controls';
$activePage = 'master-controls';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['master_controls_csrf_token'])) {
    $_SESSION['master_controls_csrf_token'] = bin2hex(random_bytes(32));
}

$masterControlsCsrfToken =
    (string)$_SESSION['master_controls_csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>Master Controls - FieldPlx</title>
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
   Master Controls - canonical tenant UI
   ========================================================== */
.mc-page{
  display:grid;
  gap:16px;
}

.mc-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
}

.mc-title{
  margin:0 0 7px;
  color:var(--fd-text);
  font-size:21px;
  font-weight:700;
}

.mc-subtitle{
  margin:0;
  max-width:820px;
  color:var(--fd-muted);
  font-size:11px;
  line-height:1.55;
}

.mc-tabs{
  display:flex;
  align-items:center;
  gap:7px;
  overflow-x:auto;
  padding:5px;
  border:1px solid var(--fd-border);
  border-radius:10px;
  background:#fff;
  scrollbar-width:none;
}

.mc-tabs::-webkit-scrollbar{display:none}

.mc-tab{
  min-height:38px;
  padding:0 13px;
  display:inline-flex;
  align-items:center;
  gap:7px;
  flex:0 0 auto;
  border:0;
  border-radius:8px;
  color:#596981;
  background:transparent;
  font-size:10px;
  font-weight:700;
  cursor:pointer;
}

.mc-tab:hover{
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}

.mc-tab.active{
  color:#fff;
  background:linear-gradient(90deg,#7fc92d,#68aa1d);
}

.mc-panel{
  display:none;
}

.mc-panel.active{
  display:block;
}

.mc-card{
  overflow:hidden;
  border:1px solid var(--fd-border);
  border-radius:9px;
  background:#fff;
  box-shadow:0 4px 14px rgba(31,43,88,.05);
}

.mc-toolbar{
  padding:13px 14px;
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}

.mc-toolbar-title{
  min-width:0;
  margin-right:auto;
}

.mc-toolbar-title strong{
  display:block;
  color:var(--fd-text);
  font-size:12px;
}

.mc-toolbar-title small{
  display:block;
  margin-top:3px;
  color:var(--fd-muted);
  font-size:8.5px;
}

.mc-btn{
  min-height:38px;
  padding:0 12px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:7px;
  border:1px solid var(--fd-border);
  border-radius:8px;
  color:#43546c;
  background:#fff;
  font-size:9.5px;
  font-weight:700;
  cursor:pointer;
}

.mc-btn:hover{
  border-color:#cfe3ae;
  color:var(--fd-green-dark);
  background:#f9fcf4;
}

.mc-btn.primary{
  border-color:var(--fd-green);
  color:#fff;
  background:linear-gradient(90deg,#7fc92d,#68aa1d);
}

.mc-btn.primary:hover{
  color:#fff;
  background:linear-gradient(90deg,#74b824,#5d971b);
}

.mc-btn.danger{
  border-color:#ffd5d9;
  color:#b9444d;
  background:#fff;
}

.mc-btn:disabled{
  opacity:.55;
  cursor:not-allowed;
}

.mc-loader{
  width:13px;
  height:13px;
  display:none;
  border:2px dotted currentColor;
  border-radius:50%;
  animation:mcSpin .75s linear infinite;
}

.mc-btn.loading .mc-loader{display:inline-block}
@keyframes mcSpin{to{transform:rotate(360deg)}}

.mc-table-wrap{
  width:100%;
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:thin;
  scrollbar-color:#9aa0a6 transparent;
}

.mc-table-wrap::-webkit-scrollbar{
  height:3px !important;
}

.mc-table-wrap::-webkit-scrollbar-track{
  background:transparent !important;
}

.mc-table-wrap::-webkit-scrollbar-thumb{
  min-width:20px;
  border-radius:999px !important;
  background:#9aa0a6 !important;
}

.mc-table-wrap::-webkit-scrollbar-button{
  width:0 !important;
  height:0 !important;
  display:none !important;
}

.mc-table{
  width:100%;
  min-width:980px;
  border-collapse:collapse;
  white-space:nowrap;
}

.mc-table th{
  padding:11px 12px;
  border-bottom:1px solid var(--fd-border);
  color:#65738a;
  background:#f8fafc;
  font-size:8.5px;
  font-weight:600;
  text-transform:uppercase;
}

.mc-table td{
  padding:12px;
  border-bottom:1px solid #f1f3f7;
  color:#33445f;
  font-size:9.5px;
  vertical-align:middle;
}

.mc-table tbody tr:hover{
  background:#fbfcfa;
}

.mc-badge{
  display:inline-flex;
  align-items:center;
  padding:5px 7px;
  border-radius:5px;
  font-size:8px;
  font-weight:700;
}

.mc-badge.active{
  color:#5d971b;
  background:#f0f8e5;
}

.mc-badge.inactive{
  color:#6f7b90;
  background:#eef2f6;
}

.mc-badge.default{
  color:#123d70;
  background:#edf2f7;
}

.mc-actions{
  display:flex;
  gap:5px;
}

.mc-icon{
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
}

.mc-icon:hover{
  color:var(--fd-green-dark);
  background:var(--fd-green-soft);
}

.mc-icon.danger:hover{
  color:#b9444d;
  background:#fff0f1;
}

.mc-empty{
  padding:28px 18px !important;
  text-align:center;
  color:#9aa4b3 !important;
  font-size:10px !important;
}

.mc-number-grid{
  padding:14px;
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:12px;
}

.mc-number-card{
  padding:14px;
  border:1px solid var(--fd-border);
  border-radius:10px;
  background:#fff;
}

.mc-number-title{
  display:flex;
  align-items:center;
  gap:9px;
  margin-bottom:13px;
}

.mc-number-icon{
  width:36px;
  height:36px;
  display:grid;
  place-items:center;
  border-radius:9px;
  color:#fff;
  background:#123f73;
}

.mc-number-title strong{
  display:block;
  color:var(--fd-text);
  font-size:11px;
}

.mc-number-title small{
  display:block;
  margin-top:2px;
  color:var(--fd-muted);
  font-size:8px;
}

.mc-preview{
  margin-top:11px;
  padding:9px 10px;
  border:1px dashed #cfd9e4;
  border-radius:8px;
  color:#123d70;
  background:#f8fafc;
  font-size:10px;
  font-weight:700;
  word-break:break-all;
}

.mc-form-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:12px;
}

.mc-field.full{
  grid-column:1/-1;
}

.mc-field label{
  display:block;
  margin-bottom:6px;
  color:#42536c;
  font-size:9px;
  font-weight:700;
}

.mc-field input,
.mc-field select,
.mc-field textarea{
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

.mc-field textarea{
  min-height:74px;
  resize:vertical;
}

.mc-field input:focus,
.mc-field select:focus,
.mc-field textarea:focus{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}

.mc-field-help{
  display:block;
  margin-top:5px;
  color:#8793a5;
  font-size:8px;
  line-height:1.45;
}


.mc-check-field{
  min-height:40px;
  display:flex;
  align-items:center;
}

.mc-check-label{
  margin:0 !important;
  display:inline-flex !important;
  align-items:center;
  gap:7px;
  color:#42536c;
  font-size:9px;
  font-weight:700;
  line-height:1.2;
  cursor:pointer;
}

.mc-check-label input[type="checkbox"]{
  width:14px !important;
  height:14px !important;
  min-width:14px !important;
  min-height:14px !important;
  margin:0 !important;
  padding:0 !important;
  flex:0 0 14px;
  border-radius:3px;
  accent-color:var(--fd-green);
  box-shadow:none !important;
}

.mc-form-actions{
  grid-column:1/-1;
  padding-top:4px;
  display:flex;
  justify-content:flex-end;
  gap:8px;
}

.mc-modal-backdrop{
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

.mc-modal-backdrop.show{
  display:flex;
}

.mc-modal{
  width:min(760px,100%);
  max-height:calc(100vh - 36px);
  overflow:auto;
  border:1px solid #dfe5ec;
  border-radius:12px;
  background:#fff;
  box-shadow:0 24px 65px rgba(0,17,49,.24);
}

.mc-modal-header{
  padding:11px 14px;
  display:flex;
  align-items:center;
  gap:10px;
  border-bottom:1px solid var(--fd-border);
  background:#fbfcfd;
}

.mc-modal-header strong{
  flex:1;
  color:var(--fd-text);
  font-size:12px;
}

.mc-modal-close{
  width:30px;
  height:30px;
  border:0;
  border-radius:7px;
  color:#8490a0;
  background:transparent;
  cursor:pointer;
}

.mc-modal-body{
  padding:15px;
}

.mc-modal-footer{
  padding:12px 15px;
  display:flex;
  justify-content:flex-end;
  gap:8px;
  border-top:1px solid var(--fd-border);
  background:#fbfcfd;
}

.mc-toast{
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
  opacity:0;
  transform:translateY(-8px);
  pointer-events:none;
  transition:.18s ease;
  box-shadow:0 10px 26px rgba(0,17,49,.18);
}

.mc-toast.show{
  opacity:1;
  transform:translateY(0);
}

.mc-toast.success{background:#5d971b}
.mc-toast.error{background:#e45b66}
.mc-toast.warning{background:#96a52f}
.mc-toast.info{background:#123d70}

.mc-toast span{
  min-width:0;
  flex:1;
  font-size:8.5px;
  font-weight:600;
}

.mc-toast button{
  border:0;
  color:#fff;
  background:transparent;
}

@media(max-width:1100px){
  .mc-number-grid{
    grid-template-columns:1fr;
  }
}

@media(max-width:767.98px){
  .mc-header{
    flex-direction:column;
  }

  .mc-form-grid{
    grid-template-columns:1fr;
  }

  .mc-field.full,
  .mc-form-actions{
    grid-column:auto;
  }

  .mc-form-actions{
    flex-direction:column-reverse;
  }

  .mc-form-actions .mc-btn{
    width:100%;
  }
}

@media(max-width:575.98px){
  .mc-toast{
    top:72px;
    left:12px;
    right:12px;
    width:auto;
  }
}

/* Tenant currency symbol for service pricing */
.mc-money-input{
  width:100%;
  min-height:40px;
  display:flex;
  align-items:stretch;
  overflow:hidden;
  border:1px solid #dfe5ec;
  border-radius:8px;
  background:#fff;
  transition:border-color .16s ease,box-shadow .16s ease;
}
.mc-money-input:focus-within{
  border-color:#a9cf75;
  box-shadow:0 0 0 3px rgba(116,184,36,.11);
}
.mc-money-symbol{
  min-width:38px;
  padding:0 9px;
  display:flex;
  align-items:center;
  justify-content:center;
  border-right:1px solid #e4e9ef;
  color:#42536c;
  background:#f7f9fb;
  font-size:10px;
  font-weight:700;
  white-space:nowrap;
}
.mc-money-input input{
  min-width:0!important;
  min-height:38px!important;
  flex:1;
  border:0!important;
  border-radius:0!important;
  box-shadow:none!important;
}
.mc-money-input input:focus{
  border:0!important;
  box-shadow:none!important;
}
.mc-currency-code{
  color:#738096;
  font-size:8.5px;
  font-weight:600;
}

/* SMTP test email */
.mc-smtp-test-status{display:inline-flex;align-items:center;gap:5px;padding:4px 7px;border-radius:5px;font-size:8px;font-weight:700;text-transform:capitalize;white-space:nowrap}.mc-smtp-test-status.success{color:#5d971b;background:#f0f8e5}.mc-smtp-test-status.failed{color:#b9444d;background:#fff0f1}.mc-smtp-test-status.not_tested{color:#6f7b90;background:#eef2f6}.mc-smtp-test-note{display:block;margin-top:3px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#8a96a7;font-size:7.5px}.mc-icon.smtp-test:hover{color:#123d70;background:#edf2f7}
</style>
</head>
<body>

<?php require_once __DIR__ . '/includes/nav.php'; ?>

<div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="fieldplx-main-content">
        <div class="fieldplx-content-wrapper">

            <div class="fd-dashboard">
                <div class="mc-page">

                    <header class="mc-header">
                        <div>
                            <h1 class="mc-title">Master Controls</h1>
                            <p class="mc-subtitle">
                                Manage branches, departments, services, tenant SMTP settings and document number formats from one tenant-scoped control center.
                            </p>
                        </div>
                    </header>

                    <div class="mc-tabs" role="tablist">
                        <button class="mc-tab active" data-tab="branches" type="button">
                            <i class="bi bi-building"></i> Branches
                        </button>
                        <button class="mc-tab" data-tab="departments" type="button">
                            <i class="bi bi-diagram-3"></i> Departments
                        </button>
                        <button class="mc-tab" data-tab="services" type="button">
                            <i class="bi bi-tools"></i> Services
                        </button>
                        <button class="mc-tab" data-tab="smtp" type="button">
                            <i class="bi bi-envelope-at"></i> Tenant SMTP
                        </button>
                        <button class="mc-tab" data-tab="numbering" type="button">
                            <i class="bi bi-123"></i> Number Formatting
                        </button>
                    </div>

                    <section class="mc-panel active" id="panel-branches">
                        <div class="mc-card">
                            <div class="mc-toolbar">
                                <div class="mc-toolbar-title">
                                    <strong>Branches</strong>
                                    <small>Manage tenant locations, currency, timezone and head office.</small>
                                </div>
                                <button class="mc-btn primary" id="addBranchButton" type="button">
                                    <i class="bi bi-plus-lg"></i> Add Branch
                                </button>
                            </div>
                            <div class="mc-table-wrap">
                                <table class="mc-table">
                                    <thead>
                                        <tr>
                                            <th>S/No</th>
                                            <th>Branch</th>
                                            <th>Code</th>
                                            <th>Contact</th>
                                            <th>Location</th>
                                            <th>Timezone</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="branchesBody">
                                        <tr><td colspan="9" class="mc-empty">Loading branches...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="mc-panel" id="panel-departments">
                        <div class="mc-card">
                            <div class="mc-toolbar">
                                <div class="mc-toolbar-title">
                                    <strong>Departments</strong>
                                    <small>Create departments and optionally assign them to a branch.</small>
                                </div>
                                <button class="mc-btn primary" id="addDepartmentButton" type="button">
                                    <i class="bi bi-plus-lg"></i> Add Department
                                </button>
                            </div>
                            <div class="mc-table-wrap">
                                <table class="mc-table">
                                    <thead>
                                        <tr>
                                            <th>S/No</th>
                                            <th>Department</th>
                                            <th>Code</th>
                                            <th>Branch</th>
                                            <th>Description</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="departmentsBody">
                                        <tr><td colspan="7" class="mc-empty">Loading departments...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="mc-panel" id="panel-services">
                        <div class="mc-card">
                            <div class="mc-toolbar">
                                <div class="mc-toolbar-title">
                                    <strong>Services</strong>
                                    <small>Manage tenant service catalog, internal service cost, customer pricing, tax, duration and booking availability.</small>
                                </div>
                                <button class="mc-btn primary" id="addServiceButton" type="button">
                                    <i class="bi bi-plus-lg"></i> Add Service
                                </button>
                            </div>

                            <div class="mc-table-wrap">
                                <table class="mc-table">
                                    <thead>
                                        <tr>
                                            <th>S/No</th>
                                            <th>Service</th>
                                            <th>SKU</th>
                                            <th>Unit</th>
                                            <th>Internal Cost</th>
                                            <th>Customer Price</th>
                                            <th>Tax</th>
                                            <th>Duration</th>
                                            <th>Bookable</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="servicesBody">
                                        <tr><td colspan="11" class="mc-empty">Loading services...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="mc-panel" id="panel-smtp">
                        <div class="mc-card">
                            <div class="mc-toolbar">
                                <div class="mc-toolbar-title">
                                    <strong>Tenant SMTP Configuration</strong>
                                    <small>Configure tenant or branch email sending accounts.</small>
                                </div>
                                <button class="mc-btn primary" id="addSmtpButton" type="button">
                                    <i class="bi bi-plus-lg"></i> Add SMTP
                                </button>
                            </div>
                            <div class="mc-table-wrap">
                                <table class="mc-table">
                                    <thead>
                                        <tr>
                                            <th>S/No</th>
                                            <th>Configuration</th>
                                            <th>Scope</th>
                                            <th>Host</th>
                                            <th>From</th>
                                            <th>Encryption</th>
                                            <th>Default</th>
                                            <th>Status</th>
                                            <th>Test</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="smtpBody">
                                        <tr><td colspan="10" class="mc-empty">Loading SMTP settings...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="mc-panel" id="panel-numbering">
                        <div class="mc-card">
                            <div class="mc-toolbar">
                                <div class="mc-toolbar-title">
                                    <strong>Invoice, Quote & Enquiry Number Formatting</strong>
                                    <small>Enquiry uses the existing service request/request_no sequence.</small>
                                </div>
                            </div>

                            <div class="mc-number-grid">

                                <form class="mc-number-card mc-sequence-form" data-doc-type="invoice">
                                    <input type="hidden" name="document_type" value="invoice">
                                    <div class="mc-number-title">
                                        <span class="mc-number-icon"><i class="bi bi-receipt"></i></span>
                                        <span>
                                            <strong>Invoice Number</strong>
                                            <small>Invoice sequence format</small>
                                        </span>
                                    </div>
                                    <div class="mc-form-grid mc-sequence-fields"></div>
                                    <div class="mc-preview" data-preview>INV-000001</div>
                                </form>

                                <form class="mc-number-card mc-sequence-form" data-doc-type="quote">
                                    <input type="hidden" name="document_type" value="quote">
                                    <div class="mc-number-title">
                                        <span class="mc-number-icon"><i class="bi bi-file-earmark-text"></i></span>
                                        <span>
                                            <strong>Quote Number</strong>
                                            <small>Quotation sequence format</small>
                                        </span>
                                    </div>
                                    <div class="mc-form-grid mc-sequence-fields"></div>
                                    <div class="mc-preview" data-preview>QUO-000001</div>
                                </form>

                                <form class="mc-number-card mc-sequence-form" data-doc-type="request">
                                    <input type="hidden" name="document_type" value="request">
                                    <div class="mc-number-title">
                                        <span class="mc-number-icon"><i class="bi bi-chat-left-text"></i></span>
                                        <span>
                                            <strong>Enquiry Number</strong>
                                            <small>Service request / enquiry sequence</small>
                                        </span>
                                    </div>
                                    <div class="mc-form-grid mc-sequence-fields"></div>
                                    <div class="mc-preview" data-preview>ENQ-000001</div>
                                </form>

                            </div>
                        </div>
                    </section>

                </div>
            </div>

            <!-- Generic modal -->
            <div class="mc-modal-backdrop" id="masterModal">
                <section class="mc-modal">
                    <div class="mc-modal-header">
                        <strong id="masterModalTitle">Master Control</strong>
                        <button class="mc-modal-close" id="masterModalClose" type="button">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <form id="masterForm">
                        <div class="mc-modal-body" id="masterModalBody"></div>
                        <div class="mc-modal-footer">
                            <button class="mc-btn" id="masterCancelButton" type="button">Cancel</button>
                            <button class="mc-btn primary" id="masterSaveButton" type="submit">
                                <span class="mc-loader"></span>
                                <i class="bi bi-check-lg"></i>
                                <span>Save</span>
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <div class="mc-toast info" id="mcToast">
                <span id="mcToastMessage">Notification</span>
                <button type="button" id="mcToastClose"><i class="bi bi-x"></i></button>
            </div>

        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
(function(){
'use strict';

var csrfToken = <?= json_encode($masterControlsCsrfToken) ?>;
var state = {
    branches:[],
    departments:[],
    services:[],
    smtp:[],
    meta:{countries:[],currencies:[],branches:[],tenant_currency:{symbol:'',currency_code:'',symbol_position:'before',decimal_places:2}},
    sequences:{}
};

var modal = document.getElementById('masterModal');
var form = document.getElementById('masterForm');
var modalBody = document.getElementById('masterModalBody');
var modalTitle = document.getElementById('masterModalTitle');
var saveButton = document.getElementById('masterSaveButton');
var toast = document.getElementById('mcToast');
var toastMessage = document.getElementById('mcToastMessage');
var toastTimer = null;

function esc(v){
    return String(v == null ? '' : v)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function showToast(type,message){
    if(toastTimer) clearTimeout(toastTimer);
    toast.className = 'mc-toast ' + (type || 'info') + ' show';
    toastMessage.textContent = message || 'Notification';
    toastTimer = setTimeout(function(){
        toast.classList.remove('show');
    },3000);
}

function loading(button,on){
    if(!button) return;
    button.disabled = !!on;
    button.classList.toggle('loading',!!on);
}

function parseResponse(response){
    return response.text().then(function(raw){
        var text = (raw || '').trim();
        var data;
        try{
            data = text ? JSON.parse(text) : {};
        }catch(e){
            var clean = text
                .replace(/<br\s*\/?>/gi,' ')
                .replace(/<[^>]*>/g,' ')
                .replace(/\s+/g,' ')
                .trim();
            throw new Error(clean || 'Server returned an invalid response.');
        }
        if(!response.ok || !data.success){
            var fallback = 'Request failed';
            if(response && response.status){
                fallback += ' (HTTP '+response.status+')';
            }
            throw new Error(data.message || fallback+'.');
        }
        return data;
    });
}

function request(fd){
    fd.append('csrf_token',csrfToken);
    return fetch('api/master-controls.php',{
        method:'POST',
        body:fd,
        credentials:'same-origin',
        headers:{
            'X-Requested-With':'XMLHttpRequest',
            'Accept':'application/json'
        }
    }).then(parseResponse);
}

function options(items,selected,labelKey){
    var html = '<option value="">Select</option>';
    (items || []).forEach(function(item){
        var selectedAttr =
            String(item.id) === String(selected || '')
                ? ' selected'
                : '';
        html += '<option value="'+Number(item.id)+'"'+selectedAttr+'>'+
            esc(item[labelKey || 'name'])+
            '</option>';
    });
    return html;
}

function renderBranches(){
    var body = document.getElementById('branchesBody');

    if(!state.branches.length){
        body.innerHTML = '<tr><td colspan="9" class="mc-empty">No branches found.</td></tr>';
        return;
    }

    var html = '';
    state.branches.forEach(function(row,index){
        html += '<tr>'+
            '<td>'+(index+1)+'</td>'+
            '<td><strong>'+esc(row.name)+'</strong></td>'+
            '<td>'+esc(row.branch_code)+'</td>'+
            '<td>'+esc(row.email || '-')+'<br><small>'+esc(row.phone || '-')+'</small></td>'+
            '<td>'+esc([row.city,row.state].filter(Boolean).join(', ') || '-')+'</td>'+
            '<td>'+esc(row.timezone || '-')+'</td>'+
            '<td>'+(Number(row.is_head_office)===1?'<span class="mc-badge default">Head Office</span>':'Branch')+'</td>'+
            '<td><span class="mc-badge '+esc(row.status)+'">'+esc(row.status)+'</span></td>'+
            '<td><div class="mc-actions">'+
                '<button class="mc-icon" data-kind="branch" data-action="edit" data-id="'+Number(row.id)+'"><i class="bi bi-pencil"></i></button>'+
                '<button class="mc-icon danger" data-kind="branch" data-action="delete" data-id="'+Number(row.id)+'"><i class="bi bi-trash"></i></button>'+
            '</div></td>'+
        '</tr>';
    });
    body.innerHTML = html;
}

function renderDepartments(){
    var body = document.getElementById('departmentsBody');

    if(!state.departments.length){
        body.innerHTML = '<tr><td colspan="7" class="mc-empty">No departments found.</td></tr>';
        return;
    }

    var html = '';
    state.departments.forEach(function(row,index){
        html += '<tr>'+
            '<td>'+(index+1)+'</td>'+
            '<td><strong>'+esc(row.name)+'</strong></td>'+
            '<td>'+esc(row.code || '-')+'</td>'+
            '<td>'+esc(row.branch_name || 'All / Unassigned')+'</td>'+
            '<td>'+esc(row.description || '-')+'</td>'+
            '<td><span class="mc-badge '+esc(row.status)+'">'+esc(row.status)+'</span></td>'+
            '<td><div class="mc-actions">'+
                '<button class="mc-icon" data-kind="department" data-action="edit" data-id="'+Number(row.id)+'"><i class="bi bi-pencil"></i></button>'+
                '<button class="mc-icon danger" data-kind="department" data-action="delete" data-id="'+Number(row.id)+'"><i class="bi bi-trash"></i></button>'+
            '</div></td>'+
        '</tr>';
    });
    body.innerHTML = html;
}

function renderServices(){
    var body = document.getElementById('servicesBody');

    if(!state.services.length){
        body.innerHTML = '<tr><td colspan="11" class="mc-empty">No services found.</td></tr>';
        return;
    }

    var html = '';

    state.services.forEach(function(row,index){
        var duration = row.estimated_duration_minutes
            ? Number(row.estimated_duration_minutes) + ' min'
            : '-';

        html += '<tr>'+
            '<td>'+(index+1)+'</td>'+
            '<td><strong>'+esc(row.name)+'</strong><br><small>'+esc(row.description || '-')+'</small></td>'+
            '<td>'+esc(row.sku || '-')+'</td>'+
            '<td>'+esc(row.unit_name || '-')+'</td>'+
            '<td>'+esc(tenantMoney(row.unit_cost || 0))+'</td>'+
            '<td>'+esc(tenantMoney(row.unit_price || 0))+'</td>'+
            '<td>'+Number(row.tax_percent || 0).toFixed(2)+'%</td>'+
            '<td>'+esc(duration)+'</td>'+
            '<td>'+(Number(row.is_bookable)===1 ? '<span class="mc-badge active">Yes</span>' : '<span class="mc-badge inactive">No</span>')+'</td>'+
            '<td><span class="mc-badge '+esc(row.status)+'">'+esc(row.status)+'</span></td>'+
            '<td><div class="mc-actions">'+
                '<button class="mc-icon" data-kind="service" data-action="edit" data-id="'+Number(row.id)+'" title="Edit Service"><i class="bi bi-pencil"></i></button>'+
                '<button class="mc-icon danger" data-kind="service" data-action="delete" data-id="'+Number(row.id)+'" title="Archive Service"><i class="bi bi-archive"></i></button>'+
            '</div></td>'+
        '</tr>';
    });

    body.innerHTML = html;
}

function renderSmtp(){
    var body = document.getElementById('smtpBody');

    if(!state.smtp.length){
        body.innerHTML = '<tr><td colspan="10" class="mc-empty">No SMTP configurations found.</td></tr>';
        return;
    }

    var html = '';
    state.smtp.forEach(function(row,index){
        html += '<tr>'+
            '<td>'+(index+1)+'</td>'+
            '<td><strong>'+esc(row.config_name)+'</strong></td>'+
            '<td>'+esc(row.scope_type)+(row.branch_name?' · '+esc(row.branch_name):'')+'</td>'+
            '<td>'+esc(row.host)+':'+esc(row.port)+'</td>'+
            '<td>'+esc(row.from_name || '-')+'<br><small>'+esc(row.from_email || '-')+'</small></td>'+
            '<td>'+esc(row.encryption)+'</td>'+
            '<td>'+(Number(row.is_default)===1?'<span class="mc-badge default">Default</span>':'-')+'</td>'+
            '<td><span class="mc-badge '+(Number(row.is_active)===1?'active':'inactive')+'">'+(Number(row.is_active)===1?'active':'inactive')+'</span></td>'+
            '<td><span class="mc-smtp-test-status '+esc(row.last_test_status || 'not_tested')+'"><i class="bi '+((row.last_test_status||'not_tested')==='success'?'bi-check-circle':((row.last_test_status||'not_tested')==='failed'?'bi-x-circle':'bi-dash-circle'))+'"></i>'+esc((row.last_test_status || 'not_tested').replace(/_/g,' '))+'</span>'+(row.last_tested_at?'<small class="mc-smtp-test-note" title="'+esc(row.last_test_message||'')+'">'+esc(row.last_test_message||'')+'</small>':'')+'</td>'+
            '<td><div class="mc-actions">'+
                '<button class="mc-icon smtp-test" data-kind="smtp" data-action="test" data-id="'+Number(row.id)+'" title="Send Test Email"><i class="bi bi-send"></i></button>'+
                '<button class="mc-icon" data-kind="smtp" data-action="edit" data-id="'+Number(row.id)+'" title="Edit SMTP"><i class="bi bi-pencil"></i></button>'+
                '<button class="mc-icon danger" data-kind="smtp" data-action="delete" data-id="'+Number(row.id)+'" title="Delete SMTP"><i class="bi bi-trash"></i></button>'+
            '</div></td>'+
        '</tr>';
    });
    body.innerHTML = html;
}

function sequenceFields(row){
    row = row || {};
    var branchOptions = '<option value="">Tenant Default</option>';
    (state.meta.branches || []).forEach(function(b){
        branchOptions += '<option value="'+Number(b.id)+'"'+
            (String(row.branch_id||'')===String(b.id)?' selected':'')+'>'+
            esc(b.name)+'</option>';
    });

    return ''+
    '<div class="mc-field full"><label>Branch Scope</label><select name="branch_id">'+branchOptions+'</select></div>'+
    '<div class="mc-field"><label>Prefix</label><input name="prefix" maxlength="50" value="'+esc(row.prefix || '')+'"></div>'+
    '<div class="mc-field"><label>Separator</label><input name="number_separator" maxlength="10" value="'+esc(row.number_separator || '-')+'"></div>'+
    '<div class="mc-field"><label>Middle Format</label><select name="middle_format">'+
        ['none','year','year_month','financial_year','branch_year'].map(function(v){
            return '<option value="'+v+'"'+((row.middle_format||'none')===v?' selected':'')+'>'+v.replace(/_/g,' ')+'</option>';
        }).join('')+
    '</select></div>'+
    '<div class="mc-field"><label>Suffix</label><input name="suffix" maxlength="50" value="'+esc(row.suffix || '')+'"></div>'+
    '<div class="mc-field"><label>Number Length</label><input type="number" min="1" max="12" name="number_length" value="'+esc(row.number_length || 6)+'"></div>'+
    '<div class="mc-field"><label>Current Number</label><input type="number" min="0" name="current_number" value="'+esc(row.current_number || 0)+'"></div>'+
    '<div class="mc-field"><label>Reset Period</label><select name="reset_period">'+
        ['never','monthly','yearly','financial_year'].map(function(v){
            return '<option value="'+v+'"'+((row.reset_period||'never')===v?' selected':'')+'>'+v.replace(/_/g,' ')+'</option>';
        }).join('')+
    '</select></div>'+
    '<div class="mc-field"><label>Financial Year Start Month</label><input type="number" min="1" max="12" name="financial_year_start_month" value="'+esc(row.financial_year_start_month || 4)+'"></div>'+
    '<div class="mc-field full mc-check-field"><label class="mc-check-label"><input type="checkbox" name="is_active" value="1" '+(row.is_active === undefined || Number(row.is_active)===1?'checked':'')+'> <span>Active</span></label></div>'+
    '<div class="mc-form-actions"><button class="mc-btn primary" type="submit"><span class="mc-loader"></span><i class="bi bi-check-lg"></i> Save Format</button></div>';
}

function sequencePreview(form){
    var fd = new FormData(form);
    var prefix = fd.get('prefix') || '';
    var sep = fd.get('number_separator') || '';
    var suffix = fd.get('suffix') || '';
    var middle = fd.get('middle_format') || 'none';
    var length = Math.max(1,Number(fd.get('number_length') || 6));
    var current = Number(fd.get('current_number') || 0) + 1;
    var now = new Date();
    var y = String(now.getFullYear());
    var m = String(now.getMonth()+1).padStart(2,'0');
    var mid = '';

    if(middle === 'year') mid = y;
    if(middle === 'year_month') mid = y+m;
    if(middle === 'financial_year'){
        var fyStart = now.getMonth()+1 >= Number(fd.get('financial_year_start_month')||4)
            ? now.getFullYear()
            : now.getFullYear()-1;
        mid = String(fyStart).slice(-2)+String(fyStart+1).slice(-2);
    }
    if(middle === 'branch_year') mid = 'BR'+y;

    var parts = [];
    if(prefix) parts.push(prefix);
    if(mid) parts.push(mid);
    parts.push(String(current).padStart(length,'0'));

    var value = parts.join(sep);
    if(suffix) value += sep + suffix;

    form.querySelector('[data-preview]').textContent = value;
}

function renderSequences(){
    document.querySelectorAll('.mc-sequence-form').forEach(function(form){
        var type = form.getAttribute('data-doc-type');
        var row = state.sequences[type] || {};
        form.querySelector('.mc-sequence-fields').innerHTML = sequenceFields(row);
        sequencePreview(form);

        form.querySelectorAll('input,select').forEach(function(el){
            el.addEventListener('input',function(){sequencePreview(form)});
            el.addEventListener('change',function(){sequencePreview(form)});
        });

        form.onsubmit = function(e){
            e.preventDefault();
            var fd = new FormData(form);
            fd.append('action','save_sequence');
            var button = form.querySelector('button[type="submit"]');
            loading(button,true);

            request(fd)
                .then(function(data){
                    showToast('success',data.message);
                    return loadAll();
                })
                .catch(function(error){
                    showToast('error',error.message);
                })
                .finally(function(){
                    loading(button,false);
                });
        };
    });
}

function loadAll(){
    var fd = new FormData();
    fd.append('action','load_all');

    return request(fd).then(function(data){
        state.branches = data.branches || [];
        state.departments = data.departments || [];
        state.services = data.services || [];
        state.smtp = data.smtp || [];
        state.meta = data.meta || {countries:[],currencies:[],branches:[],tenant_currency:{symbol:'',currency_code:'',symbol_position:'before',decimal_places:2}};
        state.sequences = data.sequences || {};
        renderBranches();
        renderDepartments();
        renderServices();
        renderSmtp();
        renderSequences();
    }).catch(function(error){
        showToast('error',error.message);
    });
}

function openModal(title,html,kind,id){
    modalTitle.textContent = title;
    modalBody.innerHTML = html;
    form.dataset.kind = kind;
    form.dataset.id = id || 0;
    var saveLabel = saveButton.querySelector('span:last-child');
    if(saveLabel) saveLabel.textContent = 'Save';
    modal.classList.add('show');

    /* SMTP edit safety: never allow browser autofill to change a saved
     * password unless the user explicitly checks Change SMTP Password. */
    if(kind === 'smtp' && Number(id || 0) > 0){
        var changePassword = document.getElementById('smtpChangePassword');
        var passwordInput = document.getElementById('smtpPasswordInput');

        if(changePassword && passwordInput){
            changePassword.checked = false;
            passwordInput.value = '';
            passwordInput.disabled = true;
            passwordInput.required = false;

            changePassword.addEventListener('change',function(){
                passwordInput.value = '';
                passwordInput.disabled = !changePassword.checked;
                passwordInput.required = changePassword.checked;
                if(changePassword.checked){
                    setTimeout(function(){ passwordInput.focus(); },0);
                }
            });
        }
    }
}

function closeModal(){
    modal.classList.remove('show');
    form.reset();
    modalBody.innerHTML = '';
}

function branchForm(row){
    row = row || {};
    return '<div class="mc-form-grid">'+
        '<div class="mc-field"><label>Branch Name</label><input name="name" required maxlength="190" value="'+esc(row.name||'')+'"></div>'+
        '<div class="mc-field"><label>Branch Code</label><input name="branch_code" required maxlength="80" value="'+esc(row.branch_code||'')+'"></div>'+
        '<div class="mc-field"><label>Email</label><input type="email" name="email" maxlength="190" value="'+esc(row.email||'')+'"></div>'+
        '<div class="mc-field"><label>Phone</label><input name="phone" maxlength="50" value="'+esc(row.phone||'')+'"></div>'+
        '<div class="mc-field full"><label>Address Line 1</label><input name="address_line1" maxlength="255" value="'+esc(row.address_line1||'')+'"></div>'+
        '<div class="mc-field full"><label>Address Line 2</label><input name="address_line2" maxlength="255" value="'+esc(row.address_line2||'')+'"></div>'+
        '<div class="mc-field"><label>City</label><input name="city" maxlength="120" value="'+esc(row.city||'')+'"></div>'+
        '<div class="mc-field"><label>State</label><input name="state" maxlength="120" value="'+esc(row.state||'')+'"></div>'+
        '<div class="mc-field"><label>Postal Code</label><input name="postal_code" maxlength="40" value="'+esc(row.postal_code||'')+'"></div>'+
        '<div class="mc-field"><label>Timezone</label><input name="timezone" maxlength="100" value="'+esc(row.timezone||'')+'"></div>'+
        '<div class="mc-field"><label>Country</label><select name="country_id">'+options(state.meta.countries,row.country_id,'name')+'</select></div>'+
        '<div class="mc-field"><label>Currency</label><select name="currency_id">'+options(state.meta.currencies,row.currency_id,'currency_name')+'</select></div>'+
        '<div class="mc-field"><label>Status</label><select name="status"><option value="active"'+((row.status||'active')==='active'?' selected':'')+'>Active</option><option value="inactive"'+(row.status==='inactive'?' selected':'')+'>Inactive</option><option value="archived"'+(row.status==='archived'?' selected':'')+'>Archived</option></select></div>'+
        '<div class="mc-field mc-check-field"><label class="mc-check-label"><input type="checkbox" name="is_head_office" value="1" '+(Number(row.is_head_office)===1?'checked':'')+'> <span>Head Office</span></label></div>'+
    '</div>';
}

function departmentForm(row){
    row = row || {};
    return '<div class="mc-form-grid">'+
        '<div class="mc-field"><label>Department Name</label><input name="name" required maxlength="190" value="'+esc(row.name||'')+'"></div>'+
        '<div class="mc-field"><label>Code</label><input name="code" maxlength="80" value="'+esc(row.code||'')+'"></div>'+
        '<div class="mc-field"><label>Branch</label><select name="branch_id">'+options(state.meta.branches,row.branch_id,'name')+'</select></div>'+
        '<div class="mc-field"><label>Status</label><select name="status"><option value="active"'+((row.status||'active')==='active'?' selected':'')+'>Active</option><option value="inactive"'+(row.status==='inactive'?' selected':'')+'>Inactive</option></select></div>'+
        '<div class="mc-field full"><label>Description</label><textarea name="description">'+esc(row.description||'')+'</textarea></div>'+
    '</div>';
}

function tenantCurrency(){
    var currency = state.meta && state.meta.tenant_currency
        ? state.meta.tenant_currency
        : {};

    return {
        symbol: currency.symbol || '',
        code: currency.currency_code || '',
        position: currency.symbol_position || 'before',
        decimals: Number(currency.decimal_places === undefined ? 2 : currency.decimal_places)
    };
}

function tenantMoney(value){
    var currency = tenantCurrency();
    var amount = Number(value || 0);
    var decimals = Math.max(0,Math.min(6,currency.decimals));
    var formatted = amount.toFixed(decimals);

    if(!currency.symbol){
        return currency.code ? formatted + ' ' + currency.code : formatted;
    }

    return currency.position === 'after'
        ? formatted + ' ' + currency.symbol
        : currency.symbol + ' ' + formatted;
}

function moneyInputHtml(name,value){
    var currency = tenantCurrency();
    var displaySymbol = currency.symbol || currency.code || '';

    return '<div class="mc-money-input">'+
        '<span class="mc-money-symbol">'+esc(displaySymbol)+'</span>'+
        '<input type="number" name="'+name+'" min="0" step="0.01" value="'+esc(value)+'">'+
    '</div>';
}

function serviceForm(row){
    row = row || {};

    return '<div class="mc-form-grid">'+
        '<div class="mc-field"><label>Service Name</label><input name="name" required maxlength="190" value="'+esc(row.name||'')+'"></div>'+
        '<div class="mc-field"><label>SKU / Service Code</label><input name="sku" maxlength="100" value="'+esc(row.sku||'')+'" placeholder="SER-001"></div>'+
        '<div class="mc-field"><label>Unit Name</label><input name="unit_name" maxlength="50" value="'+esc(row.unit_name||'Service')+'" placeholder="Service / Hour / Visit"></div>'+
        '<div class="mc-field"><label>Tax Percent</label><input type="number" name="tax_percent" min="0" max="100" step="0.0001" value="'+esc(row.tax_percent===undefined?'0.0000':row.tax_percent)+'"></div>'+
        '<div class="mc-field"><label>Internal Service Cost <span class="mc-currency-code">('+esc(tenantCurrency().symbol || tenantCurrency().code)+')</span></label>'+moneyInputHtml('unit_cost',row.unit_cost===undefined?'0.00':row.unit_cost)+'<small class="mc-field-help">Your internal cost per selected service unit, for example a technician visit or technician hour.</small></div>'+
        '<div class="mc-field"><label>Customer Service Price <span class="mc-currency-code">('+esc(tenantCurrency().symbol || tenantCurrency().code)+')</span></label>'+moneyInputHtml('unit_price',row.unit_price===undefined?'0.00':row.unit_price)+'<small class="mc-field-help">Amount charged to the customer per the same service unit.</small></div>'+
        '<div class="mc-field"><label>Estimated Duration (Minutes)</label><input type="number" name="estimated_duration_minutes" min="0" step="1" value="'+esc(row.estimated_duration_minutes||'')+'" placeholder="60"></div>'+
        '<div class="mc-field"><label>Status</label><select name="status">'+
            '<option value="active"'+((row.status||'active')==='active'?' selected':'')+'>Active</option>'+
            '<option value="inactive"'+(row.status==='inactive'?' selected':'')+'>Inactive</option>'+
            '<option value="archived"'+(row.status==='archived'?' selected':'')+'>Archived</option>'+
        '</select></div>'+
        '<div class="mc-field full"><label>Description</label><textarea name="description" maxlength="2000">'+esc(row.description||'')+'</textarea></div>'+
        '<div class="mc-field mc-check-field full"><label class="mc-check-label"><input type="checkbox" name="is_bookable" value="1" '+(Number(row.is_bookable)===1?'checked':'')+'> <span>Bookable Service</span></label></div>'+
    '</div>';
}

function smtpForm(row){
    row = row || {};
    var branchOptions = '<option value="">Tenant Level</option>';
    (state.meta.branches || []).forEach(function(b){
        branchOptions += '<option value="'+Number(b.id)+'"'+
            (String(row.branch_id||'')===String(b.id)?' selected':'')+'>'+esc(b.name)+'</option>';
    });

    return '<div class="mc-form-grid">'+
        '<div class="mc-field"><label>Configuration Name</label><input name="config_name" required maxlength="190" value="'+esc(row.config_name||'')+'"></div>'+
        '<div class="mc-field"><label>Scope</label><select name="scope_type"><option value="tenant"'+((row.scope_type||'tenant')==='tenant'?' selected':'')+'>Tenant</option><option value="branch"'+(row.scope_type==='branch'?' selected':'')+'>Branch</option></select></div>'+
        '<div class="mc-field"><label>Branch</label><select name="branch_id">'+branchOptions+'</select></div>'+
        '<div class="mc-field"><label>SMTP Host</label><input name="host" required maxlength="190" value="'+esc(row.host||'')+'"></div>'+
        '<div class="mc-field"><label>Port</label><input type="number" name="port" min="1" max="65535" value="'+esc(row.port||587)+'"></div>'+
        '<div class="mc-field"><label>Encryption</label><select name="encryption">'+['none','ssl','tls','starttls'].map(function(v){return '<option value="'+v+'"'+((row.encryption||'tls')===v?' selected':'')+'>'+v+'</option>';}).join('')+'</select></div>'+
        '<div class="mc-field"><label>Username</label><input name="username" maxlength="190" autocomplete="off" value="'+esc(row.username||'')+'"></div>'+
        (row.id
            ? '<div class="mc-field"><label>Password</label><div class="mc-check-field" style="min-height:40px;display:flex;align-items:center"><label class="mc-check-label"><input type="checkbox" id="smtpChangePassword" name="change_password" value="1" autocomplete="off"> <span>Change SMTP Password</span></label></div><input type="password" id="smtpPasswordInput" name="password" value="" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" disabled placeholder="Enter new SMTP password" style="margin-top:6px"></div>'
            : '<div class="mc-field"><label>Password</label><input type="password" id="smtpPasswordInput" name="password" value="" required autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" placeholder="SMTP password / app password"></div>')+
        '<div class="mc-field"><label>From Name</label><input name="from_name" maxlength="190" value="'+esc(row.from_name||'')+'"></div>'+
        '<div class="mc-field"><label>From Email</label><input type="email" name="from_email" maxlength="190" value="'+esc(row.from_email||'')+'"></div>'+
        '<div class="mc-field"><label>Reply-To Email</label><input type="email" name="reply_to_email" maxlength="190" value="'+esc(row.reply_to_email||'')+'"></div>'+
        '<div class="mc-field mc-check-field"><label class="mc-check-label"><input type="checkbox" name="is_default" value="1" '+(Number(row.is_default)===1?'checked':'')+'> <span>Default SMTP</span></label></div>'+
        '<div class="mc-field mc-check-field"><label class="mc-check-label"><input type="checkbox" name="is_active" value="1" '+(row.is_active===undefined||Number(row.is_active)===1?'checked':'')+'> <span>Active</span></label></div>'+
    '</div>';
}

function smtpTestForm(row){
    row = row || {};
    return '<div class="mc-form-grid">'+
        '<div class="mc-field full"><label>SMTP Configuration</label><input value="'+esc(row.config_name||'SMTP')+'" disabled></div>'+
        '<div class="mc-field full"><label>Send Test Email To</label><input type="email" name="test_email" required maxlength="190" placeholder="name@example.com"></div>'+
        '<div class="mc-field full"><small style="color:#7f8b9d;font-size:8px;line-height:1.55">A real email will be sent using <strong>'+esc(row.host||'-')+':'+esc(row.port||'')+'</strong>. The result will be stored in the SMTP Test column.</small></div>'+
    '</div>';
}

document.querySelectorAll('.mc-tab').forEach(function(tab){
    tab.addEventListener('click',function(){
        document.querySelectorAll('.mc-tab').forEach(function(x){x.classList.remove('active')});
        document.querySelectorAll('.mc-panel').forEach(function(x){x.classList.remove('active')});
        tab.classList.add('active');
        document.getElementById('panel-'+tab.dataset.tab).classList.add('active');
    });
});

document.getElementById('addBranchButton').onclick = function(){
    openModal('Add Branch',branchForm({}),'branch',0);
};

document.getElementById('addDepartmentButton').onclick = function(){
    openModal('Add Department',departmentForm({}),'department',0);
};

document.getElementById('addServiceButton').onclick = function(){
    openModal('Add Service',serviceForm({}),'service',0);
};

document.getElementById('addSmtpButton').onclick = function(){
    openModal('Add SMTP Configuration',smtpForm({}),'smtp',0);
};

document.addEventListener('click',function(event){
    var btn = event.target.closest('[data-kind][data-action]');
    if(!btn) return;

    var kind = btn.dataset.kind;
    var action = btn.dataset.action;
    var id = Number(btn.dataset.id);
    var list =
        kind === 'branch' ? state.branches :
        kind === 'department' ? state.departments :
        kind === 'service' ? state.services :
        state.smtp;
    var row = list.find(function(x){return Number(x.id)===id});

    if(action === 'test' && kind === 'smtp'){
        if(!row){ showToast('error','SMTP configuration not found.'); return; }
        openModal('Test SMTP Email',smtpTestForm(row),'smtp_test',id);
        saveButton.querySelector('span:last-child').textContent = 'Send Test';
        return;
    }

    if(action === 'edit'){
        if(kind === 'branch') openModal('Edit Branch',branchForm(row),'branch',id);
        if(kind === 'department') openModal('Edit Department',departmentForm(row),'department',id);
        if(kind === 'service') openModal('Edit Service',serviceForm(row),'service',id);
        if(kind === 'smtp') openModal('Edit SMTP Configuration',smtpForm(row),'smtp',id);
        return;
    }

    if(action === 'delete'){
        if(!window.confirm(kind === 'service' ? 'Archive this service? Existing job, quote and invoice history will remain linked.' : 'Delete this '+kind+'?')) return;
        var fd = new FormData();
        fd.append('action','delete_'+kind);
        fd.append('id',id);
        request(fd).then(function(data){
            showToast('success',data.message);
            loadAll();
        }).catch(function(error){
            showToast('error',error.message);
        });
    }
});

modalBody.addEventListener('change',function(event){
    if(event.target && event.target.id === 'smtpChangePassword'){
        var passwordInput = document.getElementById('smtpPasswordInput');
        if(!passwordInput) return;
        passwordInput.disabled = !event.target.checked;
        passwordInput.required = !!event.target.checked;
        passwordInput.value = '';
        if(event.target.checked) passwordInput.focus();
    }
});

form.onsubmit = function(event){
    event.preventDefault();

    if(!form.reportValidity()){
        showToast('warning','Complete the required fields.');
        return;
    }

    var kind = form.dataset.kind;
    var id = Number(form.dataset.id || 0);

    if(kind === 'smtp' && id > 0){
        var changePassword = document.getElementById('smtpChangePassword');
        var passwordInput = document.getElementById('smtpPasswordInput');
        if(!changePassword || !changePassword.checked){
            if(passwordInput){
                passwordInput.value = '';
                passwordInput.disabled = true;
            }
        }
    }

    var fd = new FormData(form);
    fd.append('action',kind === 'smtp_test' ? 'test_smtp' : 'save_'+kind);
    fd.append('id',id);

    loading(saveButton,true);

    request(fd).then(function(data){
        closeModal();
        showToast('success',data.message);
        loadAll();
    }).catch(function(error){
        showToast('error',error.message);
    }).finally(function(){
        loading(saveButton,false);
    });
};

document.getElementById('masterModalClose').onclick = closeModal;
document.getElementById('masterCancelButton').onclick = closeModal;
document.getElementById('mcToastClose').onclick = function(){toast.classList.remove('show')};

modal.addEventListener('click',function(event){
    if(event.target === modal) closeModal();
});

loadAll();

})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>