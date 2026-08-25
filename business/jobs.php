<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Jobs';
$activePage = 'jobs';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['jobs_csrf_token'])) {
    $_SESSION['jobs_csrf_token'] = bin2hex(random_bytes(32));
}

$jobsCsrfToken = (string)$_SESSION['jobs_csrf_token'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>Jobs - FieldPlx</title>
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

/* Jobs page */
a,a:link,a:visited,a:hover,a:focus,a:active{text-decoration:none!important}
.fd-job-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
.fd-job-title{margin:0 0 7px;color:var(--fd-text);font-size:21px;font-weight:700}
.fd-job-sub{margin:0;max-width:880px;color:var(--fd-muted);font-size:10.5px;line-height:1.55}
.fd-job-actions{display:flex;gap:8px;flex-wrap:wrap}
.fd-job-btn{min-height:39px;padding:0 13px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--fd-border);border-radius:8px;color:#43546c;background:#fff;font-size:10px;font-weight:700;cursor:pointer}
.fd-job-btn.primary{border-color:var(--fd-green);color:#fff;background:linear-gradient(90deg,#7fc92d,#68aa1d);box-shadow:0 7px 16px rgba(104,170,29,.16)}
.fd-job-btn.danger{border-color:#ffd5d9;color:#b9444d}
.fd-job-btn:disabled{opacity:.55;cursor:not-allowed}
.fd-job-loader{width:13px;height:13px;display:none;border:2px dotted currentColor;border-radius:50%;animation:jobSpin .75s linear infinite}
.fd-job-btn.loading .fd-job-loader{display:inline-block}@keyframes jobSpin{to{transform:rotate(360deg)}}
.fd-job-summary{margin-bottom:16px}
.fd-job-stat{min-height:112px;padding:18px 20px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(24,45,76,.035)}
.fd-job-stat-row{min-height:72px;display:flex;align-items:center;gap:18px}.fd-job-stat-icon{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;border-radius:16px;color:#fff;background:#123f73;font-size:25px}
.fd-job-stat-label{display:block;margin-bottom:8px;color:#506784;font-size:13px}.fd-job-stat-value{display:block;color:#020b16;font-size:31px;line-height:1;font-weight:700}
.fd-job-toolbar{padding:13px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--fd-border);background:#fbfcfd}
.fd-job-search{width:270px;position:relative}.fd-job-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a96a7}
.fd-job-search input,.fd-job-filter{height:39px;border:1px solid #dde4ec;border-radius:8px;outline:0;color:#33445f;background:#fff;font-size:10px}
.fd-job-search input{width:100%;padding:8px 11px 8px 34px}.fd-job-filter{min-width:135px;padding:8px 10px}.fd-job-spacer{margin-left:auto}
.fd-job-table-wrap{overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;scrollbar-color:#9aa0a6 transparent}
.fd-job-table-wrap::-webkit-scrollbar{height:3px!important}.fd-job-table-wrap::-webkit-scrollbar-track{background:transparent!important}.fd-job-table-wrap::-webkit-scrollbar-thumb{min-width:20px;border-radius:999px!important;background:#9aa0a6!important}.fd-job-table-wrap::-webkit-scrollbar-button{display:none!important;width:0!important;height:0!important}
.fd-job-table{width:100%;min-width:1420px;border-collapse:collapse;white-space:nowrap}
.fd-job-table th{padding:11px 12px;border-bottom:1px solid var(--fd-border);color:#65738a;background:#f8fafc;font-size:9px;font-weight:700;text-align:left;text-transform:uppercase}
.fd-job-table td{padding:12px;border-bottom:1px solid #f1f3f7;color:#33445f;font-size:9.5px;vertical-align:middle}
.fd-job-table th:first-child,.fd-job-table td:first-child{width:55px;text-align:center}.fd-job-table tbody tr:hover{background:#fbfcfa}
.fd-job-main strong,.fd-job-main small,.fd-job-client strong,.fd-job-client small{display:block}.fd-job-main strong{color:#123d70;font-size:10.5px}.fd-job-main small,.fd-job-client small{margin-top:3px;color:#8995a6;font-size:8.3px}.fd-job-client strong{color:#17233b;font-size:10px}
.fd-job-badge{min-height:22px;padding:4px 7px;display:inline-flex;align-items:center;justify-content:center;border-radius:5px;font-size:8.3px;font-weight:700;text-transform:capitalize}
.fd-job-badge.draft,.fd-job-badge.normal{color:#123d70;background:#edf2f7}.fd-job-badge.active,.fd-job-badge.completed,.fd-job-badge.closed,.fd-job-badge.ready_to_invoice,.fd-job-badge.low{color:#5d971b;background:#f0f8e5}
.fd-job-badge.scheduled,.fd-job-badge.upcoming,.fd-job-badge.today,.fd-job-badge.in_progress,.fd-job-badge.high{color:#a85a08;background:#fff4df}.fd-job-badge.waiting_customer,.fd-job-badge.waiting_material,.fd-job-badge.needs_review,.fd-job-badge.rescheduled{color:#8a5e10;background:#fff7df}.fd-job-badge.cancelled,.fd-job-badge.archived,.fd-job-badge.urgent{color:#bd2f3a;background:#fff0f1}.fd-job-badge.invoiced{color:#5b4dad;background:#f1efff}
.fd-job-row-actions{display:flex;align-items:center;gap:4px;min-width:110px}.fd-job-icon{width:29px;height:29px;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:6px;color:#66748b;background:transparent;cursor:pointer}.fd-job-icon:hover{color:var(--fd-green-dark);background:var(--fd-green-soft)}.fd-job-icon.danger:hover{color:#b9444d;background:#fff0f1}
.fd-job-empty{padding:28px 18px!important;text-align:center;color:#9aa4b3!important;font-size:10px!important}
.fd-job-pagination{min-height:49px;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid var(--fd-border);color:#768397;background:#fff;font-size:9px}
.fd-job-modal-bg{position:fixed;inset:0;z-index:15000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,17,49,.46);backdrop-filter:blur(3px)}.fd-job-modal-bg.show{display:flex}
.fd-job-modal{width:min(980px,100%);max-height:calc(100vh - 34px);overflow:auto;border:1px solid #dfe5ec;border-radius:12px;background:#fff;box-shadow:0 24px 65px rgba(0,17,49,.24)}
.fd-job-modal.small{width:min(610px,100%)}.fd-job-modal-head{min-height:58px;padding:11px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--fd-border);background:#fbfcfd}.fd-job-modal-head h3{margin:0;color:var(--fd-text);font-size:12px}.fd-job-modal-head p{margin:3px 0 0;color:var(--fd-muted);font-size:8.5px}.fd-job-modal-copy{min-width:0;flex:1}.fd-job-modal-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:9px;color:var(--fd-green-dark);background:var(--fd-green-soft)}.fd-job-close{width:30px;height:30px;border:0;border-radius:7px;background:transparent;color:#8490a0}
.fd-job-modal-body{padding:15px}.fd-job-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.fd-job-field.full{grid-column:1/-1}.fd-job-section{grid-column:1/-1;margin-top:4px;padding:8px 0 4px;border-bottom:1px solid #eef2f5;color:#31425b;font-size:9px;font-weight:700;text-transform:uppercase}
.fd-job-field label{display:block;margin-bottom:6px;color:#42536c;font-size:9px;font-weight:700}.fd-job-field input,.fd-job-field select,.fd-job-field textarea{width:100%;min-height:40px;padding:8px 10px;border:1px solid #dfe5ec;border-radius:8px;outline:0;color:#263750;background:#fff;font-size:10px}.fd-job-field textarea{min-height:86px;resize:vertical}
.fd-job-assignment{grid-column:1/-1;padding:12px;border:1px solid #e4e9ef;border-radius:9px;background:#fbfcfd}.fd-job-hint{margin-top:5px;color:#8793a5;font-size:8px}
.fd-job-modal-footer{padding:12px 15px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid var(--fd-border);background:#fbfcfd}
.fd-job-toast{width:min(300px,calc(100vw - 24px));position:fixed;top:82px;right:16px;z-index:25000;padding:8px 9px;display:flex;align-items:center;gap:7px;border-radius:7px;color:#fff;box-shadow:0 10px 26px rgba(0,17,49,.18);opacity:0;transform:translateY(-8px);pointer-events:none;transition:.18s}.fd-job-toast.show{opacity:1;transform:translateY(0)}.fd-job-toast.success{background:#5d971b}.fd-job-toast.error{background:#e45b66}.fd-job-toast.warning{background:#96a52f}.fd-job-toast.info{background:#123d70}.fd-job-toast-msg{flex:1;font-size:8.5px;font-weight:600}.fd-job-toast-close{border:0;color:#fff;background:transparent}
.select2-container{width:100%!important}.select2-container .select2-selection--single{height:40px!important;border:1px solid #dfe5ec!important;border-radius:8px!important}.select2-container .select2-selection--single .select2-selection__rendered{height:38px!important;padding:0 31px 0 10px!important;display:flex!important;align-items:center!important;color:#263750!important;font-size:10px!important}.select2-container .select2-selection--single .select2-selection__arrow{height:38px!important}.select2-container .select2-selection--multiple{min-height:40px!important;border:1px solid #dfe5ec!important;border-radius:8px!important}.select2-dropdown{z-index:20000!important;border:1px solid #dfe5ec!important}.select2-results__option{font-size:9px!important}
@media(max-width:767.98px){.fd-job-head{flex-direction:column}.fd-job-grid{grid-template-columns:1fr}.fd-job-field.full,.fd-job-section,.fd-job-assignment{grid-column:auto}.fd-job-search{width:100%}.fd-job-spacer{display:none}}

.fd-job-quote-info{background:#f8fbf4!important;border-color:#dbe9c9!important}.fd-job-quote-info input[readonly]{background:#fff!important;color:#31445e!important}.fd-job-filter[type="date"]{min-width:135px}.fd-job-assignment>.fd-job-hint:last-child{margin-top:10px;color:#5d971b}
</style>
</head>

<body>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>
    <div class="fieldplx-main-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="fieldplx-main-content">
            <div class="fieldplx-content-wrapper">
                <div class="fd-dashboard">

<section class="fd-job-head">
  <div>
    <h1 class="fd-job-title">Jobs</h1>
    <p class="fd-job-sub">Manage executable field work created from requests, quotes or direct entry. Control service workflow, assignment, scheduling, completion rules and invoicing readiness.</p>
  </div>
  <div class="fd-job-actions">
    <button type="button" class="fd-job-btn" id="refreshButton"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
    <button type="button" class="fd-job-btn primary" id="addJobButton"><i class="bi bi-plus-lg"></i> Add Job</button>
  </div>
</section>

<section class="row g-3 fd-job-summary">
  <div class="col-xl-3 col-md-6"><article class="fd-job-stat"><div class="fd-job-stat-row"><span class="fd-job-stat-icon"><i class="bi bi-briefcase"></i></span><div><span class="fd-job-stat-label">Total Jobs</span><strong class="fd-job-stat-value" id="statTotal">0</strong></div></div></article></div>
  <div class="col-xl-3 col-md-6"><article class="fd-job-stat"><div class="fd-job-stat-row"><span class="fd-job-stat-icon"><i class="bi bi-play-circle"></i></span><div><span class="fd-job-stat-label">Assigned / Scheduled</span><strong class="fd-job-stat-value" id="statAssigned">0</strong></div></div></article></div>
  <div class="col-xl-3 col-md-6"><article class="fd-job-stat"><div class="fd-job-stat-row"><span class="fd-job-stat-icon"><i class="bi bi-calendar-check"></i></span><div><span class="fd-job-stat-label">In Progress</span><strong class="fd-job-stat-value" id="statProgress">0</strong></div></div></article></div>
  <div class="col-xl-3 col-md-6"><article class="fd-job-stat"><div class="fd-job-stat-row"><span class="fd-job-stat-icon"><i class="bi bi-receipt"></i></span><div><span class="fd-job-stat-label">Completed</span><strong class="fd-job-stat-value" id="statCompleted">0</strong></div></div></article></div>
</section>

<section class="fd-card">
  <div class="fd-job-toolbar">
    <div class="fd-job-search"><i class="bi bi-search"></i><input type="search" id="search" placeholder="Search job no, title, client or service"></div>
    <select class="fd-job-filter" id="statusFilter"><option value="">All Status</option><option value="draft">Draft</option><option value="active">Active</option><option value="scheduled">Scheduled</option><option value="upcoming">Upcoming</option><option value="today">Today</option><option value="in_progress">In Progress</option><option value="waiting_customer">Waiting Customer</option><option value="waiting_material">Waiting Material</option><option value="rescheduled">Rescheduled</option><option value="completed">Completed</option><option value="needs_review">Needs Review</option><option value="ready_to_invoice">Completed</option><option value="invoiced">Invoiced</option><option value="closed">Closed</option><option value="cancelled">Cancelled</option></select>
    <input class="fd-job-filter" type="date" id="fromDate" title="From Date">
    <input class="fd-job-filter" type="date" id="toDate" title="To Date">
    <select class="fd-job-filter" id="perPage"><option value="10">10 rows</option><option value="25">25 rows</option><option value="50">50 rows</option></select>
    <div class="fd-job-spacer"></div>
    <button type="button" class="fd-job-btn" id="clearFilters"><i class="bi bi-x-circle"></i> Clear</button>
  </div>

  <div class="fd-job-table-wrap">
    <table class="fd-job-table">
      <thead><tr><th>S/No</th><th>Job</th><th>Approved Quote</th><th>Customer</th><th>Service</th><th>Assignee</th><th>Schedule</th><th>Priority</th><th>Total</th><th>Status</th><th>Action</th></tr></thead>
      <tbody id="jobsBody"><tr><td colspan="11" class="fd-job-empty">Loading jobs...</td></tr></tbody>
    </table>
  </div>
  <div class="fd-job-pagination"><span id="countText">Showing 0 jobs</span><div class="fd-job-actions"><button class="fd-job-btn" type="button" id="prevPage"><i class="bi bi-chevron-left"></i></button><button class="fd-job-btn" type="button" id="nextPage"><i class="bi bi-chevron-right"></i></button></div></div>
</section>
</div>

<div class="fd-job-modal-bg" id="jobModal">
<section class="fd-job-modal">
  <div class="fd-job-modal-head"><span class="fd-job-modal-icon"><i class="bi bi-briefcase"></i></span><div class="fd-job-modal-copy"><h3 id="modalTitle">Add Job</h3><p>Create executable field work and assign the responsible workforce.</p></div><button class="fd-job-close" type="button" id="modalClose"><i class="bi bi-x-lg"></i></button></div>
  <form id="jobForm">
    <div class="fd-job-modal-body">
      <input type="hidden" name="job_id" id="jobId" value="0">
      <div class="fd-job-grid">
        <div class="fd-job-section">Approved Quotation</div>
        <div class="fd-job-field full"><label>Approved Quotation *</label><select name="quote_id" id="quoteId" class="job-select2" required><option value="">Select Approved Quotation</option></select><div class="fd-job-hint">Only approved quotations that are not already converted to a job are shown.</div></div>
        <div class="fd-job-assignment fd-job-quote-info" id="quoteInfo" style="display:none">
          <div class="fd-job-grid">
            <div class="fd-job-field"><label>Customer</label><input id="quoteCustomer" readonly></div>
            <div class="fd-job-field"><label>Service</label><input id="quoteService" readonly></div>
            <div class="fd-job-field"><label>Quotation Total</label><input id="quoteTotal" readonly></div>
            <div class="fd-job-field"><label>Workflow</label><input id="quoteWorkflow" readonly></div>
          </div>
        </div>
        <div class="fd-job-section">Job Details</div>
        <div class="fd-job-field full"><label>Job Title *</label><input name="title" id="title" maxlength="190" required></div>
        <div class="fd-job-field full"><label>Description / Work Instructions</label><textarea name="description" id="description"></textarea></div>
        <div class="fd-job-field"><label>Priority</label><select name="priority" id="priority"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
        <div class="fd-job-field"><label>Status</label><select name="status" id="status"><option value="active">Active</option><option value="scheduled">Scheduled</option><option value="upcoming">Upcoming</option><option value="today">Today</option><option value="in_progress">In Progress</option><option value="waiting_customer">Waiting Customer</option><option value="waiting_material">Waiting Material</option><option value="rescheduled">Rescheduled</option><option value="completed">Completed</option><option value="needs_review">Needs Review</option><option value="ready_to_invoice">Ready to Invoice</option><option value="invoiced">Invoiced</option><option value="closed">Closed</option><option value="cancelled">Cancelled</option></select></div>
        <div class="fd-job-section">Schedule</div>
        <div class="fd-job-field"><label>Start Date</label><input type="date" name="start_date" id="startDate"></div>
        <div class="fd-job-field"><label>End Date</label><input type="date" name="end_date" id="endDate"></div>

        <div class="fd-job-section">Assignment</div>
        <div class="fd-job-field"><label>Assignment Mode</label><select name="assignment_mode" id="assignmentMode"><option value="single_user">Single Service Man</option><option value="multiple_users">Multiple Service Men</option><option value="department">Department</option></select></div>
        <div class="fd-job-field"><label>Completion Rule</label><select name="assignment_completion_mode" id="completionMode"><option value="primary_only">Primary Only</option><option value="task_owner">Task Owner</option><option value="all_assignees">All Assignees</option></select></div>
        <div class="fd-job-assignment">
          <div class="fd-job-field" id="singleUserWrap"><label>Primary Employee *</label><select name="single_user_id" id="singleUserId" class="job-select2"><option value="">Select Employee</option></select></div>
          <div class="fd-job-field" id="multiUsersWrap" style="display:none"><label>Employees *</label><select name="user_ids[]" id="userIds" class="job-multi" multiple></select><div class="fd-job-hint">First selected employee is stored as primary responsible.</div></div>
          <div class="fd-job-field" id="departmentWrap" style="display:none"><label>Department *</label><select name="department_id" id="departmentId" class="job-select2"><option value="">Select Department</option></select><div class="fd-job-hint">All active service users in this department will be assigned and notified.</div></div>
        </div>
      </div>
    </div>
    <div class="fd-job-modal-footer"><button type="button" class="fd-job-btn" id="cancelButton">Cancel</button><button type="submit" class="fd-job-btn primary" id="saveButton"><span class="fd-job-loader"></span><i class="bi bi-check-lg"></i><span id="saveText">Save Job</span></button></div>
  </form>
</section>
</div>

<div class="fd-job-modal-bg" id="cancelModal"><section class="fd-job-modal small"><div class="fd-job-modal-head"><span class="fd-job-modal-icon"><i class="bi bi-x-circle"></i></span><div class="fd-job-modal-copy"><h3>Cancel Job</h3><p>The job remains in history and linked records are preserved.</p></div><button type="button" class="fd-job-close" id="cancelModalClose"><i class="bi bi-x-lg"></i></button></div><div class="fd-job-modal-body"><div class="fd-job-field"><label>Cancellation Reason *</label><textarea id="cancelReason"></textarea></div></div><div class="fd-job-modal-footer"><button class="fd-job-btn" type="button" id="cancelBack">Back</button><button class="fd-job-btn danger" type="button" id="confirmCancel"><span class="fd-job-loader"></span><i class="bi bi-x-circle"></i> Cancel Job</button></div></section></div>

<div class="fd-job-toast info" id="toast"><span class="fd-job-toast-msg" id="toastMsg">Notification</span><button class="fd-job-toast-close" type="button" id="toastClose"><i class="bi bi-x"></i></button></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
'use strict';
var csrfToken=<?= json_encode($jobsCsrfToken) ?>;
var state={page:1,perPage:10,search:'',status:'',from:'',to:'',cancelId:0,meta:{quotes:[],users:[],departments:[],currency:{}}},timer=null,searchTimer=null;
var body=document.getElementById('jobsBody'),modal=document.getElementById('jobModal'),cancelModal=document.getElementById('cancelModal'),form=document.getElementById('jobForm'),toast=document.getElementById('toast'),toastMsg=document.getElementById('toastMsg');
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function read(v){return String(v||'-').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()})}
function fmtDate(v){if(!v)return '-';var d=new Date(String(v)+'T00:00:00');return isNaN(d.getTime())?esc(v):d.toLocaleDateString(undefined,{day:'2-digit',month:'short',year:'numeric'})}
function notify(t,m){if(timer)clearTimeout(timer);toast.className='fd-job-toast '+(t||'info')+' show';toastMsg.textContent=m||'Notification';timer=setTimeout(function(){toast.classList.remove('show')},3000)}
function loading(b,on){if(!b)return;b.disabled=!!on;b.classList.toggle('loading',!!on)}
function parse(r){return r.text().then(function(raw){var d,t=(raw||'').trim();try{d=t?JSON.parse(t):{}}catch(e){throw new Error(t.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||'Request failed.');return d})}
function req(fd){fd.append('csrf_token',csrfToken);return fetch('api/jobs.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parse)}
function money(n){var c=state.meta.currency||{},s=Number(n||0).toFixed(Number(c.decimal_places||2)),sym=c.symbol||'';return c.symbol_position==='after'?s+(sym?' '+sym:''):(sym||'')+s}
function option(rows,label){var h='<option value="">'+esc(label)+'</option>';(rows||[]).forEach(function(x){h+='<option value="'+Number(x.id)+'">'+esc(x.name||x.quote_no||'')+'</option>'});return h}
function initSelect2(){$('.job-select2').select2({width:'100%',dropdownParent:$('#jobModal')});$('.job-multi').select2({width:'100%',dropdownParent:$('#jobModal'),placeholder:'Select service men'})}
function applyMeta(m){state.meta=m||state.meta;var q='<option value="">Select Approved Quotation</option>';(state.meta.quotes||[]).forEach(function(x){q+='<option value="'+Number(x.id)+'">'+esc(x.quote_no)+' · '+esc(x.client_name)+' · '+money(x.total)+'</option>'});$('#quoteId').html(q);$('#singleUserId').html(option(state.meta.users,'Select Service Man'));var uh='';(state.meta.users||[]).forEach(function(x){uh+='<option value="'+Number(x.id)+'">'+esc(x.name)+(x.job_title?' · '+esc(x.job_title):'')+'</option>'});$('#userIds').html(uh);$('#departmentId').html(option(state.meta.departments,'Select Department'));}
function loadMeta(jobId,cb){var fd=new FormData();fd.append('action','meta');fd.append('job_id',jobId||0);req(fd).then(function(d){applyMeta(d.meta||{});if(cb)cb()}).catch(function(e){notify('error',e.message)})}
function quoteDetails(id){if(!id){document.getElementById('quoteInfo').style.display='none';return}var fd=new FormData();fd.append('action','quote_details');fd.append('quote_id',id);fd.append('job_id',document.getElementById('jobId').value||0);req(fd).then(function(d){var q=d.quotation||{};state.meta.currency=d.currency||state.meta.currency;document.getElementById('quoteCustomer').value=q.client_name||'';document.getElementById('quoteService').value=q.service_name||'No service';document.getElementById('quoteTotal').value=money(q.total);document.getElementById('quoteWorkflow').value=q.workflow_id?'Default workflow assigned':'No active workflow mapped';document.getElementById('quoteInfo').style.display='block';if(!document.getElementById('title').value)document.getElementById('title').value=q.title||q.request_title||'';}).catch(function(e){notify('error',e.message)})}
function render(rows,p){if(!rows.length){body.innerHTML='<tr><td colspan="11" class="fd-job-empty">No jobs found.</td></tr>';return}var h='';rows.forEach(function(r,i){h+='<tr><td>'+Number((p.from||1)+i)+'</td><td><div class="fd-job-main"><strong>'+esc(r.job_no)+'</strong><small>'+esc(r.title)+'</small></div></td><td>'+esc(r.quote_no||'-')+'</td><td>'+esc(r.client_name||'-')+'</td><td>'+esc(r.service_name||'-')+'</td><td>'+esc(r.assignees||'Unassigned')+'</td><td>'+fmtDate(r.start_date)+(r.end_date?'<br><small>to '+fmtDate(r.end_date)+'</small>':'')+'</td><td><span class="fd-job-badge '+esc(r.priority)+'">'+esc(read(r.priority))+'</span></td><td>'+money(r.total)+'</td><td><span class="fd-job-badge '+esc(r.status)+'">'+esc(read(r.status))+'</span></td><td><div class="fd-job-row-actions"><button class="fd-job-icon" data-action="edit" data-id="'+Number(r.id)+'" title="Edit"><i class="bi bi-pencil"></i></button><button class="fd-job-icon danger" data-action="cancel" data-id="'+Number(r.id)+'" data-status="'+esc(r.status)+'" title="Cancel"><i class="bi bi-x-circle"></i></button></div></td></tr>'});body.innerHTML=h}
function load(){var fd=new FormData();fd.append('action','list');fd.append('page',state.page);fd.append('per_page',state.perPage);fd.append('search',state.search);fd.append('status',state.status);fd.append('from_date',state.from);fd.append('to_date',state.to);body.innerHTML='<tr><td colspan="11" class="fd-job-empty">Loading jobs...</td></tr>';req(fd).then(function(d){var p=d.pagination||{},s=d.summary||{};state.meta.currency=d.currency||state.meta.currency;render(d.jobs||[],p);document.getElementById('statTotal').textContent=Number(s.total||0);document.getElementById('statAssigned').textContent=Number(s.assigned||0);document.getElementById('statProgress').textContent=Number(s.in_progress||0);document.getElementById('statCompleted').textContent=Number(s.completed||0);document.getElementById('countText').textContent='Showing '+Number(p.from||0)+'-'+Number(p.to||0)+' of '+Number(p.total||0)+' jobs';document.getElementById('prevPage').disabled=state.page<=1;document.getElementById('nextPage').disabled=state.page>=Number(p.pages||1)}).catch(function(e){body.innerHTML='<tr><td colspan="11" class="fd-job-empty">'+esc(e.message)+'</td></tr>';notify('error',e.message)})}
function updateAssignment(){var m=document.getElementById('assignmentMode').value;document.getElementById('singleUserWrap').style.display=m==='single_user'?'block':'none';document.getElementById('multiUsersWrap').style.display=m==='multiple_users'?'block':'none';document.getElementById('departmentWrap').style.display=m==='department'?'block':'none'}
function resetForm(){form.reset();document.getElementById('jobId').value=0;document.getElementById('priority').value='normal';document.getElementById('status').value='active';document.getElementById('assignmentMode').value='single_user';document.getElementById('completionMode').value='primary_only';document.getElementById('quoteInfo').style.display='none';$('#quoteId,#singleUserId,#departmentId').val('').trigger('change.select2');$('#userIds').val(null).trigger('change');updateAssignment()}
function openJob(id){resetForm();modal.classList.add('show');loadMeta(id,function(){if(id<=0){document.getElementById('modalTitle').textContent='Create Job from Approved Quotation';document.getElementById('saveText').textContent='Create & Assign Job';return}var fd=new FormData();fd.append('action','get');fd.append('job_id',id);req(fd).then(function(d){var r=d.job||{},a=d.assignments||[];applyMeta(d.meta||{});document.getElementById('modalTitle').textContent='Edit '+(r.job_no||'Job');document.getElementById('saveText').textContent='Update Job';document.getElementById('jobId').value=r.id||0;$('#quoteId').val(String(r.quote_id||'')).trigger('change.select2');document.getElementById('title').value=r.title||'';document.getElementById('description').value=r.description||'';document.getElementById('priority').value=r.priority||'normal';document.getElementById('status').value=r.status||'active';document.getElementById('startDate').value=r.start_date||'';document.getElementById('endDate').value=r.end_date||'';document.getElementById('completionMode').value=r.assignment_completion_mode||'primary_only';if(r.assignment_mode==='single_user'){document.getElementById('assignmentMode').value='single_user';var x=a.find(function(z){return z.user_id});$('#singleUserId').val(x?String(x.user_id):'').trigger('change.select2')}else{document.getElementById('assignmentMode').value='multiple_users';$('#userIds').val(a.filter(function(z){return z.user_id}).map(function(z){return String(z.user_id)})).trigger('change')}updateAssignment();quoteDetails(r.quote_id)}).catch(function(e){modal.classList.remove('show');notify('error',e.message)})})}
form.addEventListener('submit',function(e){e.preventDefault();if(!form.reportValidity()){notify('warning','Complete the required job fields.');return}var m=document.getElementById('assignmentMode').value;if(m==='single_user'&&!document.getElementById('singleUserId').value){notify('warning','Select a service man.');return}if(m==='multiple_users'&&!($('#userIds').val()||[]).length){notify('warning','Select at least one service man.');return}if(m==='department'&&!document.getElementById('departmentId').value){notify('warning','Select a department.');return}if(document.getElementById('startDate').value&&document.getElementById('endDate').value&&document.getElementById('endDate').value<document.getElementById('startDate').value){notify('warning','End date cannot be before start date.');return}var fd=new FormData(form);fd.append('action','save');var b=document.getElementById('saveButton');loading(b,true);req(fd).then(function(d){modal.classList.remove('show');var n=d.notifications||{},msg=d.message;if(n.email_failed)msg+=' '+n.email_failed+' email notification(s) failed.';else if(n.email_sent)msg+=' '+n.email_sent+' email notification(s) sent.';notify(n.email_failed?'warning':'success',msg);load()}).catch(function(er){notify('error',er.message)}).finally(function(){loading(b,false)})})
document.getElementById('quoteId').addEventListener('change',function(){quoteDetails(this.value)});document.getElementById('assignmentMode').addEventListener('change',updateAssignment);
body.addEventListener('click',function(e){var b=e.target.closest('[data-action]');if(!b)return;if(b.dataset.action==='edit'){openJob(Number(b.dataset.id));return}if(b.dataset.action==='cancel'){if(['cancelled','closed','archived'].indexOf(b.dataset.status)!==-1){notify('warning','This job cannot be cancelled.');return}state.cancelId=Number(b.dataset.id);document.getElementById('cancelReason').value='';cancelModal.classList.add('show')}});
document.getElementById('confirmCancel').onclick=function(){var reason=document.getElementById('cancelReason').value.trim();if(!reason){notify('warning','Enter cancellation reason.');return}var fd=new FormData();fd.append('action','cancel');fd.append('job_id',state.cancelId);fd.append('reason',reason);var b=this;loading(b,true);req(fd).then(function(d){cancelModal.classList.remove('show');notify('success',d.message);load()}).catch(function(e){notify('error',e.message)}).finally(function(){loading(b,false)})};
document.getElementById('addJobButton').onclick=function(){openJob(0)};document.getElementById('refreshButton').onclick=load;document.getElementById('modalClose').onclick=function(){modal.classList.remove('show')};document.getElementById('cancelButton').onclick=function(){modal.classList.remove('show')};document.getElementById('cancelModalClose').onclick=function(){cancelModal.classList.remove('show')};document.getElementById('cancelBack').onclick=function(){cancelModal.classList.remove('show')};document.getElementById('toastClose').onclick=function(){toast.classList.remove('show')};
document.getElementById('search').addEventListener('input',function(e){if(searchTimer)clearTimeout(searchTimer);searchTimer=setTimeout(function(){state.search=e.target.value.trim();state.page=1;load()},250)});document.getElementById('statusFilter').onchange=function(e){state.status=e.target.value;state.page=1;load()};document.getElementById('fromDate').onchange=function(e){state.from=e.target.value;state.page=1;load()};document.getElementById('toDate').onchange=function(e){state.to=e.target.value;state.page=1;load()};document.getElementById('perPage').onchange=function(e){state.perPage=Number(e.target.value||10);state.page=1;load()};document.getElementById('clearFilters').onclick=function(){document.getElementById('search').value='';document.getElementById('statusFilter').value='';document.getElementById('fromDate').value='';document.getElementById('toDate').value='';state.search=state.status=state.from=state.to='';state.page=1;load()};document.getElementById('prevPage').onclick=function(){if(state.page>1){state.page--;load()}};document.getElementById('nextPage').onclick=function(){state.page++;load()};
[modal,cancelModal].forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('show')})});initSelect2();updateAssignment();load();
})();
</script>

            </div>
        </main>
    </div>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


</body>

</html>