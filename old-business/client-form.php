<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Add / Edit Client';
$activePage = 'clients';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION['clients_csrf_token'])) {
  $_SESSION['clients_csrf_token'] = bin2hex(random_bytes(32));
}

$clientsCsrfToken = (string) $_SESSION['clients_csrf_token'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta content="width=device-width, initial-scale=1" name="viewport" />
  <title>Add / Edit Client - FieldPlx</title>
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
      background: linear-gradient(180deg,
          var(--fd-navy-light),
          var(--fd-navy)) !important;

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
    .fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-link {
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

    .fd-dashboard .row>* {
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

    .fd-stat-row>div {
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
      body.fieldplx-sidebar-mobile-open.fieldplx-sidebar-collapsed .fieldplx-sidebar {
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

      .fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-submenu,
      body.fieldplx-sidebar-collapsed .fieldplx-sidebar-menu.menu-open>.fieldplx-sidebar-submenu {
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
    .fd-employees-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px
    }

    .fd-employees-title {
      margin: 0 0 7px;
      color: var(--fd-text);
      font-size: 21px;
      font-weight: 700
    }

    .fd-employees-subtitle {
      margin: 0;
      color: var(--fd-muted);
      font-size: 11px
    }

    .fd-employees-actions {
      display: flex;
      gap: 8px
    }

    .fd-employee-btn {
      min-height: 39px;
      padding: 0 13px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      border: 1px solid var(--fd-border);
      border-radius: 8px;
      background: #fff;
      color: #43546c;
      font-size: 10px;
      font-weight: 700;
      cursor: pointer
    }

    .fd-employee-btn.primary {
      border-color: var(--fd-green);
      background: linear-gradient(90deg, #7fc92d, #68aa1d);
      color: #fff
    }

    .fd-employee-btn:hover {
      border-color: #cfe3ae;
      background: #f9fcf4;
      color: var(--fd-green-dark)
    }

    .fd-employee-btn.primary:hover {
      background: linear-gradient(90deg, #74b824, #5d971b);
      color: #fff
    }

    .fd-employee-btn.danger {
      border-color: #ffd5d9;
      color: #b9444d
    }

    .fd-employee-loader {
      width: 13px;
      height: 13px;
      display: none;
      border: 2px dotted currentColor;
      border-radius: 50%;
      animation: eSpin .75s linear infinite
    }

    .fd-employee-btn.loading .fd-employee-loader {
      display: inline-block
    }

    @keyframes eSpin {
      to {
        transform: rotate(360deg)
      }
    }

    .fd-employee-stat {
      min-height: 112px;
      padding: 18px 20px;
      border: 1px solid #dfe6ef;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 3px 12px rgba(24, 45, 76, .035)
    }

    .fd-employee-stat-row {
      min-height: 72px;
      display: flex;
      align-items: center;
      gap: 18px
    }

    .fd-employee-stat-icon {
      width: 58px;
      height: 58px;
      flex: 0 0 58px;
      display: grid;
      place-items: center;
      border-radius: 16px;
      background: #123f73;
      color: #fff;
      font-size: 25px
    }

    .fd-employee-stat-label {
      display: block;
      margin-bottom: 8px;
      color: #506784;
      font-size: 13px
    }

    .fd-employee-stat-value {
      display: block;
      color: #020b16;
      font-size: 31px;
      line-height: 1;
      font-weight: 700
    }

    .fd-employees-card {
      overflow: hidden
    }

    .fd-employees-toolbar {
      padding: 13px 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd
    }

    .fd-employee-search {
      width: 270px;
      position: relative
    }

    .fd-employee-search i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #8a96a7
    }

    .fd-employee-search input,
    .fd-employee-filter {
      height: 39px;
      border: 1px solid #dde4ec;
      border-radius: 8px;
      background: #fff;
      color: #33445f;
      font-size: 10px;
      outline: 0
    }

    .fd-employee-search input {
      width: 100%;
      padding: 8px 11px 8px 34px
    }

    .fd-employee-filter {
      min-width: 140px;
      padding: 8px 10px
    }

    .fd-employee-toolbar-spacer {
      margin-left: auto
    }

    .fd-employee-table-wrap {
      overflow-x: auto
    }

    .fd-employee-table {
      width: 100%;
      min-width: 1180px;
      border-collapse: collapse;
      white-space: nowrap
    }

    .fd-employee-table th {
      padding: 11px 12px;
      border-bottom: 1px solid var(--fd-border);
      background: #f8fafc;
      color: #65738a;
      font-size: 9px;
      font-weight: 600;
      text-transform: uppercase
    }

    .fd-employee-table td {
      padding: 12px;
      border-bottom: 1px solid #f1f3f7;
      color: #33445f;
      font-size: 9.5px
    }

    .fd-employee-person {
      display: flex;
      align-items: center;
      gap: 10px
    }

    .fd-employee-avatar {
      width: 36px;
      height: 36px;
      flex: 0 0 36px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: linear-gradient(135deg, #fff, #e8f3d9);
      border: 1px solid #dce8cf;
      color: var(--fd-navy);
      font-size: 10px;
      font-weight: 700;
      overflow: hidden
    }

    .fd-employee-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover
    }

    .fd-employee-person strong,
    .fd-employee-person small {
      display: block
    }

    .fd-employee-person small {
      margin-top: 2px;
      color: #8d98a8;
      font-size: 8.5px
    }

    .fd-employee-badge {
      display: inline-flex;
      padding: 5px 7px;
      border-radius: 5px;
      font-size: 8.5px;
      font-weight: 600
    }

    .fd-employee-badge.active,
    .fd-employee-badge.field {
      color: #5d971b;
      background: #f0f8e5
    }

    .fd-employee-badge.inactive {
      color: #6f7b90;
      background: #eef2f6
    }

    .fd-employee-badge.invited,
    .fd-employee-badge.admin {
      color: #123d70;
      background: #edf2f7
    }

    .fd-employee-badge.suspended {
      color: #b9444d;
      background: #fff0f1
    }

    .fd-employee-actions-cell {
      display: flex;
      gap: 5px
    }

    .fd-employee-icon {
      width: 29px;
      height: 29px;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 6px;
      background: transparent;
      color: #66748b;
      cursor: pointer
    }

    .fd-employee-icon:hover {
      background: var(--fd-green-soft);
      color: var(--fd-green-dark)
    }

    .fd-employee-icon.danger:hover {
      background: #fff0f1;
      color: #b9444d
    }

    .fd-employee-empty {
      padding: 28px 18px !important;
      text-align: center;
      color: #9aa4b3 !important;
      font-size: 10px !important
    }

    .fd-employee-pagination {
      padding: 10px 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-top: 1px solid var(--fd-border);
      font-size: 9px;
      color: #768397
    }

    .fd-employee-modal-backdrop {
      position: fixed;
      inset: 0;
      z-index: 15000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      background: rgba(0, 17, 49, .46);
      backdrop-filter: blur(3px)
    }

    .fd-employee-modal-backdrop.show {
      display: flex
    }

    .fd-employee-modal {
      width: min(860px, 100%);
      max-height: calc(100vh - 36px);
      overflow: auto;
      border: 1px solid #dfe5ec;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 24px 65px rgba(0, 17, 49, .24)
    }

    .fd-employee-modal-header {
      padding: 11px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd
    }

    .fd-employee-modal-icon {
      width: 34px;
      height: 34px;
      display: grid;
      place-items: center;
      border-radius: 9px;
      background: var(--fd-green-soft);
      color: var(--fd-green-dark)
    }

    .fd-employee-modal-heading {
      flex: 1
    }

    .fd-employee-modal-heading h3 {
      margin: 0;
      font-size: 12px
    }

    .fd-employee-modal-heading p {
      margin: 3px 0 0;
      color: var(--fd-muted);
      font-size: 8.5px
    }

    .fd-employee-modal-close {
      width: 30px;
      height: 30px;
      border: 0;
      border-radius: 7px;
      background: transparent;
      color: #8490a0
    }

    .fd-employee-modal-body {
      padding: 15px
    }

    .fd-employee-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 13px
    }

    .fd-employee-field.full {
      grid-column: 1/-1
    }

    .fd-employee-field label {
      display: block;
      margin-bottom: 6px;
      color: #42536c;
      font-size: 9px;
      font-weight: 700
    }

    .fd-employee-field input,
    .fd-employee-field select {
      width: 100%;
      min-height: 40px;
      padding: 8px 10px;
      border: 1px solid #dfe5ec;
      border-radius: 8px;
      background: #fff;
      color: #263750;
      font-size: 10px;
      outline: 0
    }

    .fd-employee-section {
      grid-column: 1/-1;
      padding: 7px 0 2px;
      border-bottom: 1px solid #eef2f5;
      color: #31425b;
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase
    }

    .fd-employee-switches {
      grid-column: 1/-1;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 9px
    }

    .fd-employee-switch-row {
      padding: 10px;
      border: 1px solid var(--fd-border);
      border-radius: 9px;
      background: #fbfcfd;
      display: flex;
      align-items: center;
      justify-content: space-between
    }

    .fd-employee-switch-row strong,
    .fd-employee-switch-row small {
      display: block
    }

    .fd-employee-switch-row strong {
      font-size: 9.5px
    }

    .fd-employee-switch-row small {
      margin-top: 2px;
      color: #8a96a7;
      font-size: 8px
    }

    .fd-employee-switch input {
      width: 15px;
      height: 15px;
      accent-color: var(--fd-green)
    }

    .fd-employee-modal-footer {
      padding: 12px 15px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      border-top: 1px solid var(--fd-border);
      background: #fbfcfd
    }

    .fd-employee-confirm {
      width: min(440px, 100%)
    }

    .fd-employee-toast {
      width: min(290px, calc(100vw - 24px));
      position: fixed;
      top: 82px;
      right: 16px;
      z-index: 25000;
      padding: 8px 9px;
      display: flex;
      align-items: center;
      gap: 7px;
      border-radius: 7px;
      color: #fff;
      opacity: 0;
      transform: translateY(-8px);
      pointer-events: none;
      transition: .18s ease;
      box-shadow: 0 10px 26px rgba(0, 17, 49, .18)
    }

    .fd-employee-toast.show {
      opacity: 1;
      transform: translateY(0)
    }

    .fd-employee-toast.success {
      background: #5d971b
    }

    .fd-employee-toast.error {
      background: #e45b66
    }

    .fd-employee-toast.warning {
      background: #96a52f
    }

    .fd-employee-toast.info {
      background: #123d70
    }

    .fd-employee-toast span {
      font-size: 8.5px
    }

    .fd-employee-toast button {
      margin-left: auto;
      border: 0;
      background: transparent;
      color: #fff
    }

    @media(max-width:767.98px) {
      .fd-employees-header {
        flex-direction: column
      }

      .fd-employee-grid {
        grid-template-columns: 1fr
      }

      .fd-employee-field.full,
      .fd-employee-section,
      .fd-employee-switches {
        grid-column: auto
      }

      .fd-employee-switches {
        grid-template-columns: 1fr
      }

      .fd-employee-search {
        width: 100%
      }

      .fd-employee-toolbar-spacer {
        display: none
      }
    }

    @media(max-width:575.98px) {
      .fd-employee-toast {
        top: 72px;
        left: 12px;
        right: 12px;
        width: auto
      }

      .fd-employee-modal-footer {
        flex-direction: column-reverse
      }

      .fd-employee-modal-footer .fd-employee-btn {
        width: 100%
      }
    }

    /* ==========================================================
   Teams page - canonical tenant template
   ========================================================== */
    .fd-teams-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px;
    }

    .fd-teams-title {
      margin: 0 0 7px;
      color: var(--fd-text);
      font-size: 21px;
      line-height: 1.2;
      font-weight: 700;
    }

    .fd-teams-subtitle {
      margin: 0;
      max-width: 780px;
      color: var(--fd-muted);
      font-size: 11px;
      line-height: 1.55;
    }

    .fd-teams-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .fd-team-button {
      min-height: 39px;
      padding: 0 13px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      border: 1px solid var(--fd-border);
      border-radius: 8px;
      color: #43546c;
      background: #fff;
      box-shadow: 0 4px 12px rgba(31, 43, 88, .04);
      font-size: 10px;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
    }

    .fd-team-button:hover {
      border-color: #cfe3ae;
      color: var(--fd-green-dark);
      background: #f9fcf4;
    }

    .fd-team-button.primary {
      border-color: var(--fd-green);
      color: #fff;
      background: linear-gradient(90deg, #7fc92d, #68aa1d);
      box-shadow: 0 7px 16px rgba(104, 170, 29, .18);
    }

    .fd-team-button.primary:hover {
      color: #fff;
      background: linear-gradient(90deg, #74b824, #5d971b);
    }

    .fd-team-button.danger {
      border-color: #ffd5d9;
      color: #b9444d;
      background: #fff;
    }

    .fd-team-button:disabled {
      opacity: .58;
      cursor: not-allowed;
    }

    .fd-team-loader {
      width: 13px;
      height: 13px;
      display: none;
      border: 2px dotted currentColor;
      border-radius: 50%;
      animation: fdTeamSpin .75s linear infinite;
    }

    .fd-team-button.loading .fd-team-loader {
      display: inline-block
    }

    @keyframes fdTeamSpin {
      to {
        transform: rotate(360deg)
      }
    }

    .fd-teams-summary {
      margin-bottom: 16px
    }

    .fd-team-stat-card {
      min-height: 112px;
      padding: 18px 20px;
      border: 1px solid #dfe6ef;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 3px 12px rgba(24, 45, 76, .035);
    }

    .fd-team-stat-row {
      min-height: 72px;
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .fd-team-stat-icon {
      width: 58px;
      height: 58px;
      flex: 0 0 58px;
      display: grid;
      place-items: center;
      border-radius: 16px;
      color: #fff;
      background: #123f73;
      font-size: 25px;
    }

    .fd-team-stat-label {
      display: block;
      margin-bottom: 8px;
      color: #506784;
      font-size: 13px;
    }

    .fd-team-stat-value {
      display: block;
      color: #020b16;
      font-size: 31px;
      line-height: 1;
      font-weight: 700;
    }

    .fd-teams-card {
      overflow: hidden
    }

    .fd-teams-toolbar {
      padding: 13px 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    .fd-team-search {
      width: 270px;
      position: relative
    }

    .fd-team-search i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #8a96a7;
      font-size: 13px;
    }

    .fd-team-search input,
    .fd-team-filter {
      height: 39px;
      border: 1px solid #dde4ec;
      border-radius: 8px;
      outline: 0;
      color: #33445f;
      background: #fff;
      font-size: 10px;
    }

    .fd-team-search input {
      width: 100%;
      padding: 8px 11px 8px 34px;
    }

    .fd-team-filter {
      min-width: 140px;
      padding: 8px 10px;
    }

    .fd-team-search input:focus,
    .fd-team-filter:focus {
      border-color: #a9cf75;
      box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-team-toolbar-spacer {
      margin-left: auto
    }

    .fd-team-table-wrap {
      width: 100%;
      overflow-x: auto;
      overflow-y: hidden;
      scrollbar-width: thin;
      scrollbar-color: #9aa0a6 transparent;
    }

    .fd-team-table-wrap::-webkit-scrollbar {
      height: 3px !important
    }

    .fd-team-table-wrap::-webkit-scrollbar-track {
      background: transparent !important
    }

    .fd-team-table-wrap::-webkit-scrollbar-thumb {
      min-width: 20px;
      border-radius: 999px !important;
      background: #9aa0a6 !important;
    }

    .fd-team-table-wrap::-webkit-scrollbar-button {
      width: 0 !important;
      height: 0 !important;
      display: none !important;
    }

    .fd-team-table {
      width: 100%;
      min-width: 1080px;
      margin: 0;
      border-collapse: collapse;
      white-space: nowrap;
    }

    .fd-team-table th {
      padding: 11px 12px;
      border-bottom: 1px solid var(--fd-border);
      color: #65738a;
      background: #f8fafc;
      font-size: 9px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .fd-team-table td {
      padding: 12px;
      border-bottom: 1px solid #f1f3f7;
      color: #33445f;
      font-size: 9.5px;
      vertical-align: middle;
    }

    .fd-team-table tbody tr:hover {
      background: #fbfcfa
    }

    .fd-team-name {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .fd-team-name-icon {
      width: 36px;
      height: 36px;
      flex: 0 0 36px;
      display: grid;
      place-items: center;
      border-radius: 10px;
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
      font-size: 15px;
    }

    .fd-team-name strong,
    .fd-team-name small {
      display: block
    }

    .fd-team-name strong {
      color: var(--fd-text);
      font-size: 10.5px;
    }

    .fd-team-name small {
      margin-top: 2px;
      color: #8d98a8;
      font-size: 8.5px;
    }

    .fd-team-badge {
      display: inline-flex;
      align-items: center;
      padding: 5px 7px;
      border-radius: 5px;
      font-size: 8.5px;
      font-weight: 600;
    }

    .fd-team-badge.active {
      color: #5d971b;
      background: #f0f8e5;
    }

    .fd-team-badge.inactive {
      color: #6f7b90;
      background: #eef2f6;
    }

    .fd-team-badge.primary {
      color: #123d70;
      background: #edf2f7;
    }

    .fd-team-actions-cell {
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .fd-team-icon-button {
      width: 29px;
      height: 29px;
      padding: 0;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 6px;
      color: #66748b;
      background: transparent;
      cursor: pointer;
      font-size: 12px;
    }

    .fd-team-icon-button:hover {
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
    }

    .fd-team-icon-button.danger:hover {
      color: #b9444d;
      background: #fff0f1;
    }

    .fd-team-empty {
      padding: 28px 18px !important;
      text-align: center;
      color: #9aa4b3 !important;
      font-size: 10px !important;
    }

    .fd-team-pagination {
      min-height: 49px;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      border-top: 1px solid var(--fd-border);
      color: #768397;
      background: #fff;
      font-size: 9px;
    }

    .fd-team-pagination-actions {
      display: flex;
      gap: 5px
    }

    .fd-team-modal-backdrop {
      position: fixed;
      inset: 0;
      z-index: 15000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      background: rgba(0, 17, 49, .46);
      backdrop-filter: blur(3px);
    }

    .fd-team-modal-backdrop.show {
      display: flex
    }

    .fd-team-modal {
      width: min(860px, 100%);
      max-height: calc(100vh - 36px);
      overflow: auto;
      border: 1px solid #dfe5ec;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 24px 65px rgba(0, 17, 49, .24);
    }

    .fd-team-modal-header {
      min-height: 58px;
      padding: 11px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    .fd-team-modal-icon {
      width: 34px;
      height: 34px;
      display: grid;
      place-items: center;
      border-radius: 9px;
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
      font-size: 15px;
    }

    .fd-team-modal-heading {
      min-width: 0;
      flex: 1
    }

    .fd-team-modal-heading h3 {
      margin: 0;
      color: var(--fd-text);
      font-size: 12px;
      font-weight: 700;
    }

    .fd-team-modal-heading p {
      margin: 3px 0 0;
      color: var(--fd-muted);
      font-size: 8.5px;
    }

    .fd-team-modal-close {
      width: 30px;
      height: 30px;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 7px;
      color: #8490a0;
      background: transparent;
      cursor: pointer;
    }

    .fd-team-modal-body {
      padding: 15px
    }

    .fd-team-form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 13px;
    }

    .fd-team-field.full {
      grid-column: 1/-1
    }

    .fd-team-field label {
      margin-bottom: 6px;
      display: block;
      color: #42536c;
      font-size: 9px;
      font-weight: 700;
    }

    .fd-team-field input,
    .fd-team-field select,
    .fd-team-field textarea {
      width: 100%;
      min-height: 40px;
      padding: 8px 10px;
      border: 1px solid #dfe5ec;
      border-radius: 8px;
      outline: 0;
      color: #263750;
      background: #fff;
      font-size: 10px;
    }

    .fd-team-field textarea {
      min-height: 76px;
      resize: vertical;
    }

    .fd-team-field input:focus,
    .fd-team-field select:focus,
    .fd-team-field textarea:focus {
      border-color: #a9cf75;
      box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    .fd-team-section-title {
      grid-column: 1/-1;
      margin-top: 3px;
      padding: 8px 0 2px;
      border-bottom: 1px solid #eef2f5;
      color: #31425b;
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .fd-team-members-box {
      grid-column: 1/-1;
      border: 1px solid var(--fd-border);
      border-radius: 9px;
      overflow: hidden;
    }

    .fd-team-members-head {
      padding: 10px 11px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      background: #f9fbfc;
      border-bottom: 1px solid var(--fd-border);
    }

    .fd-team-members-head strong {
      color: #31425b;
      font-size: 10px;
    }

    .fd-team-members-list {
      max-height: 260px;
      overflow: auto;
    }

    .fd-team-member-row {
      padding: 9px 11px;
      display: grid;
      grid-template-columns: 24px minmax(0, 1fr) 170px 110px;
      align-items: center;
      gap: 8px;
      border-bottom: 1px solid #f0f3f6;
    }

    .fd-team-member-row:last-child {
      border-bottom: 0
    }

    .fd-team-member-row input[type="checkbox"] {
      width: 14px;
      height: 14px;
      accent-color: var(--fd-green);
    }

    .fd-team-member-copy strong,
    .fd-team-member-copy small {
      display: block
    }

    .fd-team-member-copy strong {
      color: #34465f;
      font-size: 9.5px;
    }

    .fd-team-member-copy small {
      margin-top: 2px;
      color: #8a96a7;
      font-size: 8px;
    }

    .fd-team-member-row input[type="text"] {
      width: 100%;
      height: 34px;
      padding: 6px 8px;
      border: 1px solid #dfe5ec;
      border-radius: 7px;
      font-size: 9px;
    }

    .fd-team-primary-label {
      display: flex;
      align-items: center;
      gap: 6px;
      color: #607086;
      font-size: 8.5px;
    }

    .fd-team-modal-footer {
      padding: 12px 15px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      border-top: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    .fd-team-confirm {
      width: min(440px, 100%)
    }

    .fd-team-confirm .fd-team-modal-body {
      padding: 18px 16px;
      color: #56667c;
      font-size: 10px;
      line-height: 1.6;
    }

    .fd-team-toast {
      width: min(290px, calc(100vw - 24px));
      position: fixed;
      top: 82px;
      right: 16px;
      z-index: 25000;
      padding: 8px 9px;
      display: flex;
      align-items: center;
      gap: 7px;
      border-radius: 7px;
      color: #fff;
      box-shadow: 0 10px 26px rgba(0, 17, 49, .18);
      opacity: 0;
      transform: translateY(-8px);
      pointer-events: none;
      transition: .18s ease;
    }

    .fd-team-toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    .fd-team-toast.success {
      background: #5d971b
    }

    .fd-team-toast.error {
      background: #e45b66
    }

    .fd-team-toast.warning {
      background: #96a52f
    }

    .fd-team-toast.info {
      background: #123d70
    }

    .fd-team-toast-message {
      min-width: 0;
      flex: 1;
      font-size: 8.5px;
      font-weight: 600;
    }

    .fd-team-toast-close {
      width: 19px;
      height: 19px;
      padding: 0;
      border: 0;
      color: #fff;
      background: transparent;
      cursor: pointer;
    }

    @media(max-width:767.98px) {
      .fd-teams-header {
        flex-direction: column
      }

      .fd-teams-actions {
        justify-content: flex-end
      }

      .fd-team-form-grid {
        grid-template-columns: 1fr
      }

      .fd-team-field.full,
      .fd-team-section-title,
      .fd-team-members-box {
        grid-column: auto
      }

      .fd-team-search {
        width: 100%
      }

      .fd-team-toolbar-spacer {
        display: none
      }

      .fd-team-member-row {
        grid-template-columns: 24px minmax(0, 1fr);
      }

      .fd-team-member-row input[type="text"],
      .fd-team-primary-label {
        grid-column: 2;
      }
    }

    @media(max-width:575.98px) {
      .fd-team-stat-card {
        min-height: 102px;
        padding: 15px 17px;
      }

      .fd-team-stat-icon {
        width: 54px;
        height: 54px;
        flex-basis: 54px;
      }

      .fd-team-stat-value {
        font-size: 29px
      }

      .fd-team-filter {
        flex: 1
      }

      .fd-team-modal-footer {
        flex-direction: column-reverse
      }

      .fd-team-modal-footer .fd-team-button {
        width: 100%
      }

      .fd-team-toast {
        top: 72px;
        left: 12px;
        right: 12px;
        width: auto;
      }
    }

    \n

    /* Clients page additions */
    \na,
    a:link,
    a:visited,
    a:hover,
    a:focus,
    a:active {
      text-decoration: none !important
    }

    \n.fd-client-type {
      display: inline-flex;
      align-items: center;
      padding: 5px 7px;
      border-radius: 5px;
      font-size: 8.5px;
      font-weight: 600
    }

    \n.fd-client-type.client,
    .fd-client-type.active {
      color: #5d971b;
      background: #f0f8e5
    }

    .fd-client-type.lead,
    .fd-client-type.new {
      color: #123d70;
      background: #edf2f7
    }

    .fd-client-type.inactive {
      color: #6f7b90;
      background: #eef2f6
    }

    .fd-client-type.archived {
      color: #8a5e10;
      background: #fff7df
    }

    \n.fd-client-checks {
      grid-column: 1/-1;
      display: flex;
      flex-wrap: wrap;
      gap: 8px
    }

    .fd-client-check {
      min-height: 38px;
      padding: 7px 9px;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      border: 1px solid #e3e8ed;
      border-radius: 7px;
      color: #5c6d82;
      background: #fff;
      font-size: 8.5px
    }

    .fd-client-check input {
      width: 14px;
      height: 14px;
      accent-color: var(--fd-green)
    }

    \n

    /* Clients table font + alignment correction */
    .fd-client-table {
      table-layout: auto;
    }

    .fd-client-table th {
      padding: 12px 14px !important;
      color: #5f6f86 !important;
      font-size: 9px !important;
      line-height: 1.2 !important;
      font-weight: 700 !important;
      letter-spacing: .01em !important;
      text-align: left !important;
      vertical-align: middle !important;
      white-space: nowrap !important;
    }

    .fd-client-table td {
      padding: 12px 14px !important;
      color: #33445f !important;
      font-size: 9.5px !important;
      line-height: 1.45 !important;
      font-weight: 400 !important;
      text-align: left !important;
      vertical-align: middle !important;
    }

    .fd-client-table th:first-child,
    .fd-client-table td:first-child {
      width: 58px;
      text-align: center !important;
    }

    .fd-client-person {
      min-width: 185px;
      align-items: center !important;
    }

    .fd-client-person strong {
      color: #17233b !important;
      font-size: 10.5px !important;
      line-height: 1.35 !important;
      font-weight: 700 !important;
    }

    .fd-client-person small {
      margin-top: 3px !important;
      color: #8793a5 !important;
      font-size: 8.5px !important;
      line-height: 1.3 !important;
      font-weight: 400 !important;
    }

    .fd-client-table td small {
      color: #66758a !important;
      font-size: 8.5px !important;
      line-height: 1.35 !important;
    }

    .fd-client-badge {
      min-height: 22px;
      padding: 4px 7px !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      border-radius: 5px !important;
      font-size: 8.5px !important;
      line-height: 1 !important;
      font-weight: 700 !important;
      text-transform: capitalize !important;
      white-space: nowrap !important;
    }

    .fd-client-actions-cell {
      min-width: 100px;
      justify-content: flex-start !important;
      align-items: center !important;
      gap: 4px !important;
    }

    .fd-client-icon-btn {
      width: 29px !important;
      height: 29px !important;
      min-width: 29px !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      line-height: 1 !important;
    }

    .fd-client-icon-btn i {
      line-height: 1 !important;
      font-size: 12px !important;
    }

    .fd-client-table td:nth-child(3),
    .fd-client-table td:nth-child(9) {
      vertical-align: middle !important;
    }

    .fd-client-table td:nth-child(10) {
      color: #52627a !important;
      font-size: 9px !important;
    }

    .fd-client-table th:last-child,
    .fd-client-table td:last-child {
      text-align: left !important;
    }

    .fd-client-table a,
    .fd-client-table a:visited,
    .fd-client-table a:hover,
    .fd-client-table a:focus,
    .fd-client-table a:active {
      color: inherit;
      text-decoration: none !important;
    }

    .fd-client-table a.fd-client-badge,
    .fd-client-table a.fd-client-badge:visited {
      color: #123d70 !important;
    }

    @media(max-width:767.98px) {

      .fd-client-table th,
      .fd-client-table td {
        padding: 10px 11px !important;
      }
    }

    /* Client location/view/delete controls */
    .fd-client-location-link {
      min-width: 42px;
      height: 28px;
      padding: 0 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 5px;
      border: 1px solid #dfe7ef;
      border-radius: 7px;
      color: #123d70 !important;
      background: #f8fafc;
      font-size: 9px;
      font-weight: 700;
      text-decoration: none !important
    }

    .fd-client-location-link:hover {
      border-color: #cfe3ae;
      color: var(--fd-green-dark) !important;
      background: var(--fd-green-soft)
    }

    .fd-team-actions-cell a.fd-team-icon-button {
      text-decoration: none !important
    }

    /* ==============================================================
       Jobber-style Client Form - aligned to Add Invoice UI
       ============================================================== */
    :root {
      --cf-navy: #001131;
      --cf-green: #2f8d25;
      --cf-green-dark: #24751d;
      --cf-green-soft: #f2f8ee;
      --cf-text: #0b2b37;
      --cf-muted: #5f7380;
      --cf-border: #dce4e8;
      --cf-soft: #f8fafb;
      --cf-card-soft: #faf9f7;
      --cf-danger: #c74646;
    }

    body {
      background: #ffffff !important;
      color: var(--cf-text) !important;
      font-family: Arial, Helvetica, sans-serif !important;
      font-size: 14px !important;
    }

    .fdj-page {
      width: 100%;
      max-width: 1180px;
      margin: 0 auto;
      padding: 26px 28px 104px;
      background: #fff;
    }

    .fdj-title {
      margin: 0 0 30px;
      color: var(--cf-text);
      font-size: 34px;
      line-height: 1.1;
      font-weight: 700;
      letter-spacing: -0.5px;
    }

    .fdj-section {
      display: grid;
      grid-template-columns: minmax(250px, 330px) minmax(0, 1fr);
      gap: 54px;
      padding: 10px 0 34px;
      border-bottom: 1px solid #edf1f3;
    }

    .fdj-section + .fdj-section {
      padding-top: 34px;
    }

    .fdj-section-side h2 {
      margin: 0 0 12px;
      color: var(--cf-text);
      font-size: 20px;
      line-height: 1.2;
      font-weight: 700;
    }

    .fdj-section-side p {
      max-width: 275px;
      margin: 0;
      color: var(--cf-muted);
      font-size: 13px;
      line-height: 1.3;
    }

    .fdj-side-action {
      margin-top: 18px;
    }

    .fdj-section-main {
      min-width: 0;
    }

    .fdj-group {
      overflow: hidden;
      border: 1px solid var(--cf-border);
      border-radius: 8px;
      background: #fff;
    }

    .fdj-name-grid {
      display: grid;
      grid-template-columns: 128px minmax(0, 1fr) minmax(0, 1fr);
    }

    .fdj-field,
    .fdj-group-field {
      position: relative;
      min-width: 0;
    }

    .fdj-name-grid .fdj-group-field {
      border-right: 1px solid var(--cf-border);
    }

    .fdj-name-grid .fdj-group-field:last-child {
      border-right: 0;
    }

    .fdj-company-row {
      border-top: 1px solid var(--cf-border);
    }

    .fdj-input,
    .fdj-select,
    .fdj-textarea {
      width: 100%;
      min-width: 0;
      max-width: 100%;
      border: 1px solid var(--cf-border);
      border-radius: 8px;
      outline: 0;
      background: #fff;
      color: #163644;
      font-family: inherit;
      font-size: 14px;
      transition: border-color .15s ease, box-shadow .15s ease;
    }

    .fdj-input,
    .fdj-select {
      height: 48px;
      padding: 11px 14px;
    }

    .fdj-textarea {
      min-height: 86px;
      padding: 12px 14px;
      line-height: 1.45;
      resize: vertical;
    }

    .fdj-input:focus,
    .fdj-select:focus,
    .fdj-textarea:focus,
    .fdj-group-field:focus-within {
      border-color: #91bd7e;
      box-shadow: 0 0 0 2px rgba(47, 141, 37, .08);
    }

    .fdj-group .fdj-input,
    .fdj-group .fdj-select {
      height: 54px;
      border: 0;
      border-radius: 0;
      box-shadow: none !important;
    }

    .fdj-floating-label {
      position: absolute;
      top: 8px;
      left: 14px;
      z-index: 2;
      color: #647a87;
      font-size: 11px;
      line-height: 1;
      pointer-events: none;
    }

    .fdj-floating-label + .fdj-select,
    .fdj-floating-label + .fdj-input {
      padding-top: 23px;
      padding-bottom: 7px;
    }

    .fdj-subheading {
      margin: 18px 0 10px;
      color: var(--cf-text);
      font-size: 14px;
      font-weight: 700;
    }

    .fdj-stack {
      display: grid;
      gap: 8px;
    }

    /* Jobber-style multi-phone communication input */
    .fdj-phone-list {
      display: grid;
      gap: 8px;
    }

    .fdj-phone-row {
      display: grid;
      gap: 6px;
    }

    .fdj-phone-line {
      display: grid;
      grid-template-columns: 28px minmax(0, 1fr) 140px 48px;
      gap: 8px;
      align-items: center;
    }

    .fdj-phone-row.is-blank .fdj-phone-line {
      grid-template-columns: minmax(0, 1fr);
    }

    .fdj-phone-row.is-blank .fdj-phone-star,
    .fdj-phone-row.is-blank .fdj-phone-type,
    .fdj-phone-row.is-blank .fdj-phone-action,
    .fdj-phone-row.is-blank .fdj-phone-sms,
    .fdj-phone-row.is-blank .fdj-phone-label {
      display: none;
    }

    .fdj-phone-star,
    .fdj-phone-action {
      width: 48px;
      height: 48px;
      padding: 0;
      display: inline-grid;
      place-items: center;
      border: 1px solid var(--cf-border);
      border-radius: 8px;
      background: #fff;
      font-family: inherit;
      cursor: pointer;
    }

    .fdj-phone-star {
      width: 28px;
      height: 48px;
      border: 0;
      color: #cfb51e;
      background: transparent;
      font-size: 20px;
    }

    .fdj-phone-star:hover {
      color: #a78d00;
      background: transparent;
    }

    .fdj-phone-action {
      color: var(--cf-green-dark);
      font-size: 22px;
    }

    .fdj-phone-action.delete {
      color: #df4038;
      font-size: 18px;
    }

    .fdj-phone-action:hover {
      border-color: #b8d89e;
      background: #f8fcf6;
    }

    .fdj-phone-action.delete:hover {
      border-color: #f0c3c0;
      background: #fff7f6;
    }

    .fdj-phone-input-wrap {
      position: relative;
      min-width: 0;
    }

    .fdj-phone-input {
      padding-top: 22px !important;
      padding-bottom: 7px !important;
    }

    .fdj-phone-row.is-blank .fdj-phone-input {
      padding: 11px 14px !important;
    }

    .fdj-phone-label {
      position: absolute;
      top: 7px;
      left: 14px;
      z-index: 2;
      color: #647a87;
      font-size: 11px;
      line-height: 1;
      pointer-events: none;
    }

    .fdj-phone-type {
      height: 48px;
      padding: 0 14px;
      border: 1px solid var(--cf-border);
      border-radius: 8px;
      outline: 0;
      color: #163644;
      background: #fff;
      font-family: inherit;
      font-size: 14px;
    }

    .fdj-phone-type:focus {
      border-color: #91bd7e;
      box-shadow: 0 0 0 2px rgba(47, 141, 37, .08);
    }

    .fdj-phone-sms {
      margin-left: 36px;
      display: flex;
      align-items: center;
      gap: 8px;
      color: #3e5965;
      font-size: 14px;
      line-height: 1.2;
    }

    .fdj-phone-sms-toggle {
      width: 48px;
      height: 24px;
      padding: 0;
      position: relative;
      border: 0;
      border-radius: 999px;
      background: #dbe2e5;
      cursor: pointer;
      transition: background .16s ease;
    }

    .fdj-phone-sms-toggle::after {
      width: 18px;
      height: 18px;
      position: absolute;
      top: 3px;
      left: 4px;
      border-radius: 50%;
      background: #fff;
      box-shadow: 0 1px 3px rgba(0,0,0,.2);
      content: "";
      transition: left .16s ease;
    }

    .fdj-phone-sms-toggle.on {
      background: var(--cf-green);
    }

    .fdj-phone-sms-toggle.on::before {
      position: absolute;
      top: 3px;
      left: 8px;
      z-index: 2;
      color: #fff;
      font-size: 12px;
      line-height: 18px;
      content: "\2713";
    }

    .fdj-phone-sms-toggle.on::after {
      left: 26px;
    }

    @media (max-width: 767.98px) {
      .fdj-phone-line {
        grid-template-columns: 24px minmax(0, 1fr) 112px 44px;
        gap: 6px;
      }
      .fdj-phone-star { width: 24px; }
      .fdj-phone-action { width: 44px; }
      .fdj-phone-type { padding: 0 10px; }
      .fdj-phone-sms { margin-left: 30px; font-size: 13px; }
    }

    @media (max-width: 575.98px) {
      .fdj-phone-line {
        grid-template-columns: 24px minmax(0, 1fr) 44px;
      }
      .fdj-phone-type {
        grid-column: 2 / 3;
        width: 100%;
      }
      .fdj-phone-action { grid-column: 3; grid-row: 1; }
      .fdj-phone-row.is-blank .fdj-phone-line { grid-template-columns: 1fr; }
      .fdj-phone-sms { margin-left: 30px; }
    }

    .fdj-link-button {
      padding: 0;
      border: 0;
      background: transparent;
      color: var(--cf-green-dark);
      font-family: inherit;
      font-size: 14px;
      font-weight: 700;
      text-decoration: underline;
      cursor: pointer;
    }

    .fdj-link-button:hover {
      color: var(--cf-green);
    }

    .fdj-accordion {
      margin-top: 16px;
      overflow: hidden;
      border-radius: 9px;
      background: var(--cf-card-soft);
    }

    .fdj-accordion-head {
      min-height: 58px;
      padding: 0 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      border: 0;
      background: transparent;
      color: var(--cf-text);
      font-family: inherit;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
    }

    .fdj-accordion-head i {
      color: var(--cf-green-dark);
      transition: transform .16s ease;
    }

    .fdj-accordion.collapsed .fdj-accordion-head i {
      transform: rotate(180deg);
    }

    .fdj-accordion-body {
      padding: 4px 16px 16px;
    }

    .fdj-accordion.collapsed .fdj-accordion-body {
      display: none;
    }

    .fdj-detail-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .fdj-detail-grid .full {
      grid-column: 1 / -1;
    }

    .fdj-detail-label {
      display: block;
      margin: 0 0 5px;
      color: #526b78;
      font-size: 11px;
      font-weight: 700;
    }

    .fdj-inline-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }

    .fdj-muted {
      color: var(--cf-muted);
      font-size: 12px;
      line-height: 1.4;
    }

    .fdj-btn {
      min-height: 40px;
      padding: 0 15px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      border: 1px solid var(--cf-border);
      border-radius: 8px;
      background: #fff;
      color: #31505d;
      font-family: inherit;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none !important;
      cursor: pointer;
    }

    .fdj-btn:hover {
      border-color: #b8d89e;
      color: var(--cf-green-dark);
      background: #f8fcf6;
    }

    .fdj-btn.primary {
      border-color: var(--cf-green);
      background: var(--cf-green);
      color: #fff;
    }

    .fdj-btn.primary:hover {
      background: var(--cf-green-dark);
      color: #fff;
    }

    .fdj-btn.danger {
      border-color: #efc2c2;
      color: #b43e3e;
      background: #fff;
    }

    .fdj-btn:disabled {
      opacity: .55;
      cursor: not-allowed;
    }

    .fdj-savebar {
      position: fixed;
      left: var(--fieldplx-sidebar-width);
      right: 0;
      bottom: 0;
      z-index: 1035;
      min-height: 70px;
      padding: 10px 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      border-top: 1px solid var(--cf-border);
      background: rgba(255, 255, 255, .98);
      box-shadow: 0 -4px 12px rgba(0, 17, 49, .04);
    }

    body.fieldplx-sidebar-collapsed .fdj-savebar {
      left: var(--fieldplx-sidebar-collapsed-width);
    }

    .fdj-savebar-right {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .fdj-property-block + .fdj-property-block {
      margin-top: 34px;
      padding-top: 34px;
      border-top: 1px solid #edf1f3;
    }

    .fdj-address-group {
      overflow: hidden;
      border: 1px solid var(--cf-border);
      border-radius: 8px;
      background: #fff;
    }

    .fdj-address-row {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      border-top: 1px solid var(--cf-border);
    }

    .fdj-address-row:first-child {
      border-top: 0;
    }

    .fdj-address-row.one {
      grid-template-columns: 1fr;
    }

    .fdj-address-row .fdj-input,
    .fdj-address-row .fdj-select {
      height: 48px;
      border: 0;
      border-radius: 0;
      box-shadow: none !important;
    }

    .fdj-address-row > * + * {
      border-left: 1px solid var(--cf-border) !important;
    }

    .fdj-country-wrap {
      position: relative;
    }

    .fdj-country-wrap .fdj-select {
      padding-top: 22px;
    }

    .fdj-country-wrap .fdj-floating-label {
      top: 6px;
    }

    .fdj-check {
      min-height: 34px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: #405d6a;
      font-size: 13px;
      cursor: pointer;
    }

    .fdj-check input {
      width: 18px;
      height: 18px;
      margin: 0;
      accent-color: var(--cf-green);
    }

    .fdj-tax-wrap,
    .fdj-source-wrap {
      position: relative;
      margin-top: 12px;
    }

    .fdj-source-wrap {
      margin-top: 0;
    }

    .fdj-pop-menu {
      display: none;
      position: absolute;
      top: calc(100% + 4px);
      left: 0;
      right: 0;
      z-index: 1250;
      max-height: 290px;
      overflow: auto;
      border: 1px solid var(--cf-border);
      border-radius: 8px;
      background: #fff;
      box-shadow: 0 10px 28px rgba(0, 17, 49, .14);
    }

    .fdj-pop-menu.show {
      display: block;
    }

    .fdj-pop-option,
    .fdj-pop-create {
      width: 100%;
      min-height: 40px;
      padding: 9px 12px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      border: 0;
      border-bottom: 1px solid #edf1f3;
      background: #fff;
      color: #35515f;
      font-family: inherit;
      font-size: 13px;
      text-align: left;
      cursor: pointer;
    }

    .fdj-pop-option:hover {
      background: #f6f9f5;
    }

    .fdj-pop-create {
      position: sticky;
      bottom: 0;
      border-bottom: 0;
      color: var(--cf-green-dark);
      font-weight: 700;
      text-decoration: underline;
    }

    .fdj-tax-caption {
      color: #6a7f8b;
      font-size: 12px;
    }

    .fdj-billing-box {
      display: none;
      margin-top: 8px;
      padding: 12px;
      border: 1px solid #e6ebee;
      border-radius: 8px;
      background: #fbfcfc;
    }

    .fdj-billing-box.show {
      display: block;
    }

    .fdj-contact-list {
      display: grid;
      gap: 8px;
      margin-top: 10px;
    }

    .fdj-contact-card {
      min-height: 54px;
      padding: 10px 12px;
      display: flex;
      align-items: center;
      gap: 10px;
      border: 1px solid #e2e8eb;
      border-radius: 8px;
      background: #fff;
    }

    .fdj-contact-avatar {
      width: 34px;
      height: 34px;
      flex: 0 0 34px;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: var(--cf-green-soft);
      color: var(--cf-green-dark);
      font-size: 12px;
      font-weight: 700;
    }

    .fdj-contact-copy {
      min-width: 0;
      flex: 1;
    }

    .fdj-contact-copy strong,
    .fdj-contact-copy small {
      display: block;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .fdj-contact-copy strong {
      color: #24424f;
      font-size: 13px;
    }

    .fdj-contact-copy small {
      margin-top: 3px;
      color: var(--cf-muted);
      font-size: 11px;
    }

    .fdj-icon-btn {
      width: 32px;
      height: 32px;
      padding: 0;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 7px;
      background: transparent;
      color: #718692;
      cursor: pointer;
    }

    .fdj-icon-btn:hover {
      color: var(--cf-green-dark);
      background: var(--cf-green-soft);
    }

    .fdj-icon-btn.danger:hover {
      color: var(--cf-danger);
      background: #fff1f1;
    }

    .fdj-custom-grid {
      display: grid;
      gap: 9px;
      margin-top: 10px;
    }

    .fdj-empty-note {
      padding: 12px 0;
      color: #7c8d96;
      font-size: 12px;
    }

    .fdj-modal-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 13000;
      padding: 18px;
      align-items: center;
      justify-content: center;
      background: rgba(0, 17, 49, .34);
    }

    .fdj-modal-backdrop.show {
      display: flex;
    }

    .fdj-modal {
      width: min(620px, 100%);
      max-height: calc(100vh - 36px);
      overflow: auto;
      border: 1px solid var(--cf-border);
      border-radius: 10px;
      background: #fff;
      box-shadow: 0 18px 55px rgba(0, 17, 49, .22);
    }

    .fdj-modal.small {
      width: min(540px, 100%);
    }

    .fdj-modal-head {
      padding: 20px 24px 10px;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
    }

    .fdj-modal-head h3 {
      margin: 0;
      color: var(--cf-text);
      font-size: 22px;
      font-weight: 700;
    }

    .fdj-modal-close {
      width: 34px;
      height: 34px;
      padding: 0;
      border: 0;
      border-radius: 7px;
      background: transparent;
      color: #3e5966;
      font-size: 22px;
      cursor: pointer;
    }

    .fdj-modal-body {
      padding: 12px 24px 18px;
    }

    .fdj-modal-footer {
      padding: 0 24px 22px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
    }

    .fdj-modal-copy {
      margin: 0 0 16px;
      color: #405d6a;
      font-size: 14px;
      line-height: 1.4;
    }

    .fdj-setting-box {
      padding: 14px 16px;
      border: 1px solid var(--cf-border);
      border-radius: 8px;
      background: #fff;
    }

    .fdj-setting-title {
      margin: 0 0 9px;
      color: #24424f;
      font-size: 14px;
      font-weight: 700;
    }

    .fdj-setting-title button {
      padding: 0;
      border: 0;
      background: transparent;
      color: var(--cf-green-dark);
      font: inherit;
      text-decoration: underline;
      cursor: pointer;
    }

    .fdj-setting-row {
      min-height: 44px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      color: #405d6a;
      font-size: 14px;
    }

    .fdj-switch {
      position: relative;
      width: 44px;
      height: 24px;
      flex: 0 0 44px;
      border: 0;
      border-radius: 999px;
      background: #cbd5db;
      cursor: pointer;
    }

    .fdj-switch.on {
      background: var(--cf-green);
    }

    .fdj-switch::after {
      position: absolute;
      top: 3px;
      left: 3px;
      width: 18px;
      height: 18px;
      content: '';
      border-radius: 50%;
      background: #fff;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .18);
      transition: left .16s ease;
    }

    .fdj-switch.on::after {
      left: 23px;
    }

    .fdj-switch .check {
      position: absolute;
      top: 3px;
      left: 8px;
      z-index: 2;
      color: #fff;
      font-size: 12px;
      opacity: 0;
    }

    .fdj-switch.on .check {
      opacity: 1;
    }

    .fdj-info {
      margin-bottom: 12px;
      padding: 12px;
      border-radius: 7px;
      color: #32678d;
      background: #e7f3ff;
      font-size: 13px;
    }

    .fdj-toast {
      position: fixed;
      top: 82px;
      right: 18px;
      z-index: 16000;
      width: min(390px, calc(100vw - 36px));
      padding: 12px 14px;
      border-radius: 8px;
      color: #fff;
      background: #1f5f7a;
      box-shadow: 0 12px 30px rgba(0, 17, 49, .18);
      opacity: 0;
      transform: translateY(-8px);
      pointer-events: none;
      transition: .18s;
      font-size: 14px;
      font-weight: 700;
    }

    .fdj-toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    .fdj-toast.success { background: #2f8d25; }
    .fdj-toast.error { background: #c94f55; }
    .fdj-toast.warning { background: #9a741a; }

    .fdj-loader {
      width: 14px;
      height: 14px;
      display: none;
      border: 2px dotted currentColor;
      border-radius: 50%;
      animation: fdjSpin .75s linear infinite;
    }

    .loading .fdj-loader { display: inline-block; }

    @keyframes fdjSpin { to { transform: rotate(360deg); } }

    @media (max-width: 991.98px) {
      .fdj-savebar,
      body.fieldplx-sidebar-collapsed .fdj-savebar {
        left: 0;
      }
      .fdj-section {
        grid-template-columns: 1fr;
        gap: 18px;
      }
      .fdj-section-side p { max-width: none; }
    }

    @media (max-width: 767.98px) {
      .fdj-page { padding: 20px 14px 108px; }
      .fdj-title { margin-bottom: 22px; font-size: 28px; }
      .fdj-name-grid { grid-template-columns: 110px 1fr; }
      .fdj-name-grid .fdj-group-field:nth-child(3) {
        grid-column: 1 / -1;
        border-top: 1px solid var(--cf-border);
        border-right: 0;
      }
      .fdj-detail-grid { grid-template-columns: 1fr; }
      .fdj-detail-grid .full { grid-column: auto; }
      .fdj-savebar { padding: 10px 14px; }
      .fdj-savebar-right { gap: 6px; }
    }

    @media (max-width: 575.98px) {
      .fdj-address-row { grid-template-columns: 1fr; }
      .fdj-address-row > * + * {
        border-left: 0 !important;
        border-top: 1px solid var(--cf-border) !important;
      }
      .fdj-savebar {
        align-items: stretch;
        flex-direction: column;
      }
      .fdj-savebar-right {
        display: grid;
        grid-template-columns: 1fr 1fr;
      }
      .fdj-savebar .fdj-btn { width: 100%; }
      .fdj-toast { top: 72px; left: 12px; right: 12px; width: auto; }
    }
  </style>
</head>

<body>
  <?php require_once __DIR__ . '/includes/nav.php'; ?>
  <div class="fieldplx-main-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="fieldplx-main-content">
      <div class="fieldplx-content-wrapper">
        <form id="customerForm" class="fdj-page" autocomplete="off">
          <input type="hidden" id="clientId" name="client_id" value="0">
          <input type="hidden" id="displayName" name="display_name" value="">
          <input type="hidden" id="locationsJson" name="locations_json" value="[]">
          <input type="hidden" id="communicationJson" name="communication_json" value="{}">
          <input type="hidden" id="customerCustomValuesJson" name="customer_custom_values_json" value="{}">
          <input type="hidden" id="clientContactsJson" name="client_contacts_json" value="[]">
          <input type="hidden" id="phoneNumbersJson" name="phone_numbers_json" value="[]">

          <h1 class="fdj-title" id="pageHeading">New Client</h1>

          <section class="fdj-section">
            <div class="fdj-section-side">
              <h2>Primary contact details</h2>
              <p>Provide the main point of contact to ensure smooth communication and reliable Client records.</p>
            </div>
            <div class="fdj-section-main">
              <div class="fdj-group">
                <div class="fdj-name-grid">
                  <div class="fdj-group-field">
                    <label class="fdj-floating-label" for="titlePrefix">Title</label>
                    <select class="fdj-select" id="titlePrefix" name="title_prefix">
                      <option value="">No title</option>
                      <option value="Mr.">Mr.</option>
                      <option value="Ms.">Ms.</option>
                      <option value="Mrs.">Mrs.</option>
                      <option value="Miss.">Miss.</option>
                      <option value="Dr.">Dr.</option>
                    </select>
                  </div>
                  <div class="fdj-group-field"><input class="fdj-input" id="firstName" name="first_name" maxlength="120" placeholder="First name"></div>
                  <div class="fdj-group-field"><input class="fdj-input" id="lastName" name="last_name" maxlength="120" placeholder="Last name"></div>
                </div>
                <div class="fdj-company-row"><input class="fdj-input" id="companyName" name="company_name" maxlength="190" placeholder="Company name"></div>
              </div>

              <div class="fdj-subheading">Communication</div>
              <div class="fdj-stack">
                <input type="hidden" id="phone" name="phone" value="">
                <div class="fdj-phone-list" id="phoneNumbersList"></div>
                <input class="fdj-input" type="email" id="email" name="email" maxlength="190" placeholder="Email">
              </div>
              <div style="margin-top:13px"><button type="button" class="fdj-link-button" id="communicationSettingsButton">Communication settings</button></div>

              <div class="fdj-subheading">Lead information</div>
              <div class="fdj-source-wrap" id="sourceWrap">
                <input class="fdj-input" type="text" id="source" name="source" maxlength="120" placeholder="Lead source" autocomplete="off">
                <div class="fdj-pop-menu" id="sourceMenu"></div>
              </div>

              <div class="fdj-accordion" id="additionalCustomerAccordion">
                <button class="fdj-accordion-head" type="button" data-toggle-accordion="additionalCustomerAccordion">
                  <span>Additional client details</span><i class="bi bi-chevron-up"></i>
                </button>
                <div class="fdj-accordion-body">
                  <div class="fdj-inline-row">
                    <span class="fdj-muted">Create custom fields to track client-specific details.</span>
                    <button type="button" class="fdj-btn" data-add-custom="client"><i class="bi bi-plus-lg"></i> Add Custom Field</button>
                  </div>
                  <div class="fdj-custom-grid" id="customerCustomFields"></div>

                  <div class="fdj-detail-grid" style="margin-top:14px">
                    <div class="full"><label class="fdj-detail-label">Internal Notes</label><textarea class="fdj-textarea" id="notes" name="notes" maxlength="5000" placeholder="Add internal notes about this client"></textarea></div>
                  </div>

                  <!-- Compatibility-only values. These legacy fields are intentionally not shown in the Client UI. -->
                  <div hidden aria-hidden="true">
                    <input type="hidden" id="clientType" name="client_type" value="client">
                    <input type="hidden" id="clientStatus" name="status" value="active">
                    <input type="hidden" id="branchId" name="branch_id" value="">
                    <input type="hidden" id="accountManagerId" name="account_manager_id" value="">
                    <input type="hidden" id="alternatePhone" name="alternate_phone" value="">
                    <input type="hidden" id="preferredContactMethod" name="preferred_contact_method" value="email">
                    <input type="hidden" id="taxNumber" name="tax_number" value="">
                    <input type="checkbox" id="allowEmail" name="allow_email" value="1" checked>
                    <input type="checkbox" id="allowSms" name="allow_sms" value="1" checked>
                    <input type="checkbox" id="portalEnabled" name="portal_enabled" value="1">
                    <div class="portal-password-field"><label id="passwordLabel">Client Password</label><input type="password" id="customerPassword" name="customer_password" autocomplete="new-password"></div>
                    <div class="portal-password-field"><input type="password" id="confirmPassword" name="confirm_password" autocomplete="new-password"></div>
                  </div>
                </div>
              </div>

              <div class="fdj-accordion" id="additionalContactsAccordion">
                <button class="fdj-accordion-head" type="button" data-toggle-accordion="additionalContactsAccordion">
                  <span>Additional contacts</span><i class="bi bi-chevron-up"></i>
                </button>
                <div class="fdj-accordion-body">
                  <div class="fdj-inline-row">
                    <span class="fdj-muted">For contacts with access to all properties, e.g., spouse or family for residential, or property or regional managers for commercial.</span>
                    <button type="button" class="fdj-btn" id="addClientContactButton">Add Contact</button>
                  </div>
                  <div id="clientContactsList"></div>
                </div>
              </div>
            </div>
          </section>

          <div id="propertySections"></div>
        </form>
      </div>
    </main>
  </div>

  <div class="fdj-savebar">
    <a class="fdj-btn" href="clients.php">Cancel</a>
    <div class="fdj-savebar-right">
      <button type="button" class="fdj-btn" id="saveAnotherButton"><span class="fdj-loader"></span>Save and Create Another</button>
      <button type="submit" form="customerForm" class="fdj-btn primary" id="saveCustomerButton"><span class="fdj-loader"></span><span id="saveCustomerText">Save Client</span></button>
    </div>
  </div>

  <div class="fdj-modal-backdrop" id="communicationModal">
    <div class="fdj-modal small" role="dialog" aria-modal="true">
      <div class="fdj-modal-head"><h3>Communication Settings</h3><button type="button" class="fdj-modal-close" data-close-modal="communicationModal">&times;</button></div>
      <div class="fdj-modal-body">
        <p class="fdj-modal-copy">Automated communications send emails and SMS to the client for key updates. They can be toggled on or off per client.</p>
        <div class="fdj-setting-box">
          <div class="fdj-setting-title">Quotes &amp; Invoices <button type="button" data-configure-info>Configure</button></div>
          <div class="fdj-setting-row"><span>Outstanding quote follow-ups</span><button type="button" class="fdj-switch on" data-comm="quote_followups"><i class="bi bi-check check"></i></button></div>
          <div class="fdj-setting-row"><span>Overdue invoice follow-ups</span><button type="button" class="fdj-switch on" data-comm="invoice_followups"><i class="bi bi-check check"></i></button></div>
          <div class="fdj-setting-title" style="margin-top:10px">Jobs &amp; Visits <button type="button" data-configure-info>Configure</button></div>
          <div class="fdj-setting-row"><span>Upcoming assessment or visit reminders</span><button type="button" class="fdj-switch on" data-comm="visit_reminders"><i class="bi bi-check check"></i></button></div>
          <div class="fdj-setting-row"><span>Job closure follow-ups</span><button type="button" class="fdj-switch on" data-comm="job_close_followups"><i class="bi bi-check check"></i></button></div>
        </div>
      </div>
      <div class="fdj-modal-footer"><button type="button" class="fdj-btn" data-close-modal="communicationModal">Cancel</button><button type="button" class="fdj-btn primary" id="saveCommunicationButton">Save</button></div>
    </div>
  </div>

  <div class="fdj-modal-backdrop" id="leadSourceModal">
    <div class="fdj-modal small" role="dialog" aria-modal="true">
      <div class="fdj-modal-head"><h3>Create Lead Source</h3><button type="button" class="fdj-modal-close" data-close-modal="leadSourceModal">&times;</button></div>
      <div class="fdj-modal-body"><input class="fdj-input" type="text" id="newLeadSourceName" maxlength="120" placeholder="Lead source name"></div>
      <div class="fdj-modal-footer"><button type="button" class="fdj-btn" data-close-modal="leadSourceModal">Cancel</button><button type="button" class="fdj-btn primary" id="createLeadSourceButton">Create Lead Source</button></div>
    </div>
  </div>

  <div class="fdj-modal-backdrop" id="taxRateModal">
    <div class="fdj-modal small" role="dialog" aria-modal="true">
      <div class="fdj-modal-head"><h3>Create Tax Rate</h3><button type="button" class="fdj-modal-close" data-close-modal="taxRateModal">&times;</button></div>
      <div class="fdj-modal-body">
        <div class="fdj-group">
          <div class="fdj-address-row">
            <input class="fdj-input" type="text" id="taxRateName" maxlength="120" placeholder="Name">
            <div class="fdj-group-field"><label class="fdj-floating-label" for="taxRatePercent">Tax rate (%)</label><input class="fdj-input" type="number" id="taxRatePercent" min="0" max="100" step="0.0001" value="0"></div>
          </div>
        </div>
        <input class="fdj-input" style="margin-top:12px" type="text" id="taxRateDescription" maxlength="190" placeholder="Internal tax description">
        <label class="fdj-check" style="margin-top:10px"><input type="checkbox" id="taxRateDefault"> Make default for new quotes and invoices</label>
        <p class="fdj-muted" style="margin:12px 0 0">Tax rates are shared with the same tax-rate master used by FieldPlx quotes and invoices.</p>
      </div>
      <div class="fdj-modal-footer"><button type="button" class="fdj-btn" data-close-modal="taxRateModal">Cancel</button><button type="button" class="fdj-btn primary" id="createTaxRateButton">Create Tax Rate</button></div>
    </div>
  </div>

  <div class="fdj-modal-backdrop" id="customFieldModal">
    <div class="fdj-modal small" role="dialog" aria-modal="true">
      <div class="fdj-modal-head"><h3>New custom field</h3><button type="button" class="fdj-modal-close" data-close-modal="customFieldModal">&times;</button></div>
      <div class="fdj-modal-body">
        <div class="fdj-muted" style="text-transform:uppercase;font-size:11px">Applies to</div>
        <div style="font-size:20px;font-weight:700;margin:3px 0 12px" id="customFieldScopeLabel">All clients</div>
        <label class="fdj-check"><input type="checkbox" id="customFieldTransferable"> Transferable field</label>
        <div class="fdj-muted" style="margin:-2px 0 14px 26px">Transferable fields appear in multiple places and follow your workflow.</div>
        <input class="fdj-input" type="text" id="customFieldName" maxlength="190" placeholder="Custom field name">
        <div class="fdj-group-field" style="margin-top:10px"><label class="fdj-floating-label" for="customFieldType">Field type</label><select class="fdj-select" id="customFieldType"><option value="text">Text</option><option value="numeric">Numeric</option><option value="boolean">True/False</option><option value="area">Area (length x width)</option><option value="dropdown">Dropdown</option></select></div>
        <div id="customFieldDropdownOptionsWrap" style="display:none;margin-top:10px"><input class="fdj-input" type="text" id="customFieldDropdownOptions" placeholder="Dropdown options, separated by commas"></div>
        <div class="fdj-muted" style="margin:14px 0 6px">Example</div>
        <div style="color:#31505d;line-height:1.45">Serial Number<br>54A17-HEX</div>
        <input class="fdj-input" style="margin-top:14px" type="text" id="customFieldDefault" placeholder="Default value">
        <div class="fdj-muted" style="margin-top:14px">All custom fields can be managed from your FieldPlx master controls.</div>
      </div>
      <div class="fdj-modal-footer"><button type="button" class="fdj-btn" data-close-modal="customFieldModal">Cancel</button><button type="button" class="fdj-btn primary" id="createCustomFieldButton">Add Custom Field</button></div>
    </div>
  </div>

  <div class="fdj-modal-backdrop" id="contactModal">
    <div class="fdj-modal" role="dialog" aria-modal="true">
      <div class="fdj-modal-head"><h3 id="contactModalTitle">Add contact</h3><button type="button" class="fdj-modal-close" data-close-modal="contactModal">&times;</button></div>
      <div class="fdj-modal-body">
        <div class="fdj-subheading" style="margin-top:0">Details</div>
        <div class="fdj-group">
          <div class="fdj-name-grid">
            <div class="fdj-group-field"><label class="fdj-floating-label" for="contactTitlePrefix">Title</label><select class="fdj-select" id="contactTitlePrefix"><option value="">No title</option><option value="Mr.">Mr.</option><option value="Ms.">Ms.</option><option value="Mrs.">Mrs.</option><option value="Miss.">Miss.</option><option value="Dr.">Dr.</option></select></div>
            <div class="fdj-group-field"><input class="fdj-input" id="contactFirstName" maxlength="120" placeholder="First name"></div>
            <div class="fdj-group-field"><input class="fdj-input" id="contactLastName" maxlength="120" placeholder="Last name"></div>
          </div>
          <div class="fdj-company-row"><input class="fdj-input" id="contactRole" maxlength="120" placeholder="Role"></div>
        </div>
        <label class="fdj-check" style="margin-top:10px"><input type="checkbox" id="contactBilling"> Set as billing contact</label>

        <div class="fdj-subheading">Communication</div>
        <div class="fdj-stack"><input class="fdj-input" id="contactPhone" maxlength="50" placeholder="Phone number"><input class="fdj-input" type="email" id="contactEmail" maxlength="190" placeholder="Email"></div>

        <div class="fdj-subheading">Communication settings</div>
        <div class="fdj-info"><i class="bi bi-info-circle" style="margin-right:7px"></i>Contacts can access the client portal when portal access is enabled.</div>
        <label class="fdj-check" style="margin-bottom:10px"><input type="checkbox" id="contactPortalAccess"> Allow client portal access</label>
        <div class="fdj-setting-box">
          <div class="fdj-setting-title">Quotes &amp; Invoices <button type="button" data-configure-info>Configure</button></div>
          <div class="fdj-setting-row"><span>Outstanding quote follow-ups</span><button type="button" class="fdj-switch" data-contact-comm="quote_followups"><i class="bi bi-check check"></i></button></div>
          <div class="fdj-setting-row"><span>Overdue invoice follow-ups</span><button type="button" class="fdj-switch" data-contact-comm="invoice_followups"><i class="bi bi-check check"></i></button></div>
          <div class="fdj-setting-title" style="margin-top:10px">Jobs &amp; Visits <button type="button" data-configure-info>Configure</button></div>
          <div class="fdj-setting-row"><span>Upcoming assessment or visit reminders</span><button type="button" class="fdj-switch on" data-contact-comm="visit_reminders"><i class="bi bi-check check"></i></button></div>
          <div class="fdj-setting-row"><span>Job closure follow-ups</span><button type="button" class="fdj-switch" data-contact-comm="job_close_followups"><i class="bi bi-check check"></i></button></div>
        </div>
      </div>
      <div class="fdj-modal-footer"><button type="button" class="fdj-btn" data-close-modal="contactModal">Cancel</button><button type="button" class="fdj-btn primary" id="saveContactButton">Add Contact</button></div>
    </div>
  </div>

  <?php require_once __DIR__ . '/includes/toast.php'; ?>
  <script>
    (function () {
      'use strict';

      var csrfToken = <?= json_encode($clientsCsrfToken) ?>;
      var params = new URLSearchParams(window.location.search);
      var clientId = Number(params.get('client_id') || 0);
      var editMode = clientId > 0;
      var form = document.getElementById('customerForm');
      var saveButton = document.getElementById('saveCustomerButton');
      var saveAnotherButton = document.getElementById('saveAnotherButton');
      var dirty = false;
      var submitting = false;
      var initializing = true;
      var saveMode = 'normal';
      var metaData = { countries: [], lead_sources: [], tax_rates: [], client_custom_fields: [], location_custom_fields: [], branches: [], users: [] };
      var communication = { quote_followups: 1, invoice_followups: 1, visit_reminders: 1, job_close_followups: 1 };
      var customerCustomValues = {};
      var clientContacts = [];
      var phoneNumbers = [];
      var locations = [];
      var locationSeq = 0;
      var customFieldContext = { appliesTo: 'client', locationIndex: -1 };
      var taxLocationIndex = -1;
      var contactContext = { scope: 'location', locationIndex: -1, contactIndex: -1 };
      var contactComm = { quote_followups: 0, invoice_followups: 0, visit_reminders: 1, job_close_followups: 0 };

      function esc(value) {
        return String(value == null ? '' : value)
          .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
      }

      function toast(type, message) {
        if (typeof window.fieldplxToast === 'function') {
          window.fieldplxToast(type, message || 'Notification', 4200);
          return;
        }
        if (window.console && console.log) console.log((type || 'info') + ': ' + (message || 'Notification'));
      }

      function parseResponse(response) {
        return response.text().then(function (raw) {
          var data;
          try { data = raw ? JSON.parse(raw) : {}; }
          catch (e) { throw new Error((raw || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || 'Invalid server response.'); }
          if (!response.ok || !data.success) throw new Error(data.message || 'Request failed.');
          return data;
        });
      }

      function request(formData) {
        formData.append('csrf_token', csrfToken);
        return fetch('api/client-form.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(parseResponse);
      }

      function openModal(id) { document.getElementById(id).classList.add('show'); }
      function closeModal(id) { document.getElementById(id).classList.remove('show'); }

      function setLoading(on) {
        saveButton.disabled = on;
        saveAnotherButton.disabled = on;
        saveButton.classList.toggle('loading', on);
        saveAnotherButton.classList.toggle('loading', on);
      }

      function syncDisplayName() {
        var parts = [document.getElementById('titlePrefix').value, document.getElementById('firstName').value.trim(), document.getElementById('lastName').value.trim()].filter(Boolean);
        var display = parts.join(' ').trim();
        if (!display) display = document.getElementById('companyName').value.trim();
        document.getElementById('displayName').value = display;
      }

      function countryOptions(selected) {
        var html = '<option value="">Select a country</option>';
        (metaData.countries || []).forEach(function (country) {
          html += '<option value="' + Number(country.id) + '"' + (String(country.id) === String(selected || '') ? ' selected' : '') + '>' + esc(country.name) + '</option>';
        });
        return html;
      }

      function taxName(id) {
        id = Number(id || 0);
        var row = (metaData.tax_rates || []).find(function (x) { return Number(x.id) === id; });
        return row ? row.tax_name + ' (' + Number(row.rate_percent || 0).toFixed(2).replace(/\.00$/, '') + '%)' : '';
      }

      function blankPhoneNumber() {
        return {
          id: 0,
          phone_number: '',
          phone_type: 'main',
          receives_messages: 1,
          is_primary: phoneNumbers.length === 0 ? 1 : 0,
          sort_order: phoneNumbers.length + 1,
          _delete: 0
        };
      }

      function normalizePhoneNumber(row) {
        row = row || {};
        var type = String(row.phone_type || 'main').toLowerCase();
        if (['main','work','mobile','home','fax','other'].indexOf(type) === -1) type = 'other';
        return {
          id: Number(row.id || 0),
          phone_number: row.phone_number || row.phone || '',
          phone_type: type,
          receives_messages: Number(row.receives_messages == null ? 1 : row.receives_messages) === 1 ? 1 : 0,
          is_primary: Number(row.is_primary || 0) === 1 ? 1 : 0,
          sort_order: Number(row.sort_order || 0),
          _delete: 0
        };
      }

      function phoneTypeOptions(selected) {
        var types = [
          ['main','Main'], ['work','Work'], ['mobile','Mobile'],
          ['home','Home'], ['fax','Fax'], ['other','Other']
        ];
        return types.map(function (row) {
          return '<option value="' + row[0] + '" ' + (row[0] === selected ? 'selected' : '') + '>' + row[1] + '</option>';
        }).join('');
      }

      function activePhoneNumbers() {
        return (phoneNumbers || []).filter(function (row) {
          return !row._delete && String(row.phone_number || '').trim() !== '';
        });
      }

      function syncPhoneHiddenFields() {
        var active = activePhoneNumbers();
        var primary = active.find(function (row) { return Number(row.is_primary || 0) === 1; }) || active[0] || null;
        if (primary) {
          phoneNumbers.forEach(function (row) { row.is_primary = row === primary ? 1 : 0; });
        }
        var secondary = active.find(function (row) { return row !== primary; }) || null;
        document.getElementById('phone').value = primary ? String(primary.phone_number || '').trim() : '';
        var alt = document.getElementById('alternatePhone');
        if (alt) alt.value = secondary ? String(secondary.phone_number || '').trim() : '';
        document.getElementById('phoneNumbersJson').value = JSON.stringify(phoneNumbers);
      }

      function renderPhoneNumbers() {
        var host = document.getElementById('phoneNumbersList');
        if (!host) return;
        var visible = (phoneNumbers || []).filter(function (row) { return !row._delete; });
        if (!visible.length) {
          phoneNumbers.push(blankPhoneNumber());
          visible = phoneNumbers.filter(function (row) { return !row._delete; });
        }
        var hasPrimary = visible.some(function (row) { return Number(row.is_primary || 0) === 1 && String(row.phone_number || '').trim() !== ''; });
        if (!hasPrimary) {
          var firstFilled = visible.find(function (row) { return String(row.phone_number || '').trim() !== ''; });
          if (firstFilled) firstFilled.is_primary = 1;
        }
        var html = '';
        visible.forEach(function (row, visualIndex) {
          var index = phoneNumbers.indexOf(row);
          var blank = String(row.phone_number || '').trim() === '';
          var primary = Number(row.is_primary || 0) === 1;
          var action = visualIndex === 0
            ? '<button type="button" class="fdj-phone-action" data-add-phone title="Add another phone" aria-label="Add another phone"><i class="bi bi-plus-lg"></i></button>'
            : '<button type="button" class="fdj-phone-action delete" data-delete-phone="' + index + '" title="Remove phone" aria-label="Remove phone"><i class="bi bi-trash"></i></button>';
          html += '<div class="fdj-phone-row ' + (blank ? 'is-blank' : '') + '" data-phone-row="' + index + '">'
            + '<div class="fdj-phone-line">'
            + '<button type="button" class="fdj-phone-star" data-primary-phone="' + index + '" title="Set as primary phone" aria-label="Set as primary phone"><i class="bi ' + (primary ? 'bi-star-fill' : 'bi-star') + '"></i></button>'
            + '<div class="fdj-phone-input-wrap"><label class="fdj-phone-label">Phone number</label><input class="fdj-input fdj-phone-input" type="text" maxlength="50" data-phone-input="' + index + '" value="' + esc(row.phone_number || '') + '" placeholder="Phone number"></div>'
            + '<select class="fdj-phone-type" data-phone-type="' + index + '" aria-label="Phone type">' + phoneTypeOptions(row.phone_type || 'main') + '</select>'
            + action
            + '</div>'
            + '<div class="fdj-phone-sms"><button type="button" class="fdj-phone-sms-toggle ' + (Number(row.receives_messages || 0) === 1 ? 'on' : '') + '" data-phone-sms="' + index + '" aria-label="Toggle receives messages"></button><span>Receives messages</span></div>'
            + '</div>';
        });
        host.innerHTML = html;
        syncPhoneHiddenFields();
      }

      function blankLocation() {
        return {
          _key: ++locationSeq,
          id: 0,
          location_type: 'other',
          name: '',
          address_line1: '',
          address_line2: '',
          city: '',
          state: '',
          postal_code: '',
          country_id: '',
          tax_rate_id: '',
          billing_same_as_property: 1,
          billing_address_line1: '',
          billing_address_line2: '',
          billing_city: '',
          billing_state: '',
          billing_postal_code: '',
          billing_country_id: '',
          latitude: '',
          longitude: '',
          contact_name: '',
          contact_phone: '',
          gate_code: '',
          access_notes: '',
          service_instructions: '',
          is_primary: locations.length === 0 ? 1 : 0,
          status: 'active',
          custom_values: {},
          contacts: [],
          _delete: 0
        };
      }

      function normalizeContact(row) {
        row = row || {};
        return {
          id: Number(row.id || 0),
          title_prefix: row.title_prefix || '',
          first_name: row.first_name || '',
          last_name: row.last_name || '',
          role_name: row.role_name || row.title || '',
          email: row.email || '',
          phone: row.phone || '',
          is_primary: Number(row.is_primary || 0) === 1 ? 1 : 0,
          is_billing_contact: Number(row.is_billing_contact || 0) === 1 ? 1 : 0,
          portal_access: Number(row.portal_access || 0) === 1 ? 1 : 0,
          quote_followups: Number(row.quote_followups || 0) === 1 ? 1 : 0,
          invoice_followups: Number(row.invoice_followups || 0) === 1 ? 1 : 0,
          visit_reminders: Number(row.visit_reminders == null ? 1 : row.visit_reminders) === 1 ? 1 : 0,
          job_close_followups: Number(row.job_close_followups || 0) === 1 ? 1 : 0,
          _delete: 0
        };
      }

      function normalizeLocation(row) {
        row = row || {};
        return {
          _key: ++locationSeq,
          id: Number(row.id || 0),
          location_type: row.location_type || 'other',
          name: row.name || '',
          address_line1: row.address_line1 || '',
          address_line2: row.address_line2 || '',
          city: row.city || '',
          state: row.state || '',
          postal_code: row.postal_code || '',
          country_id: row.country_id || '',
          tax_rate_id: row.tax_rate_id || '',
          billing_same_as_property: Number(row.billing_same_as_property == null ? 1 : row.billing_same_as_property) === 1 ? 1 : 0,
          billing_address_line1: row.billing_address_line1 || '',
          billing_address_line2: row.billing_address_line2 || '',
          billing_city: row.billing_city || '',
          billing_state: row.billing_state || '',
          billing_postal_code: row.billing_postal_code || '',
          billing_country_id: row.billing_country_id || '',
          latitude: row.latitude || '',
          longitude: row.longitude || '',
          contact_name: row.contact_name || '',
          contact_phone: row.contact_phone || '',
          gate_code: row.gate_code || '',
          access_notes: row.access_notes || '',
          service_instructions: row.service_instructions || '',
          is_primary: Number(row.is_primary || 0) === 1 ? 1 : 0,
          status: row.status || 'active',
          custom_values: row.custom_values || {},
          contacts: (row.contacts || []).map(normalizeContact),
          _delete: 0
        };
      }

      function fieldControl(def, value, attr) {
        value = value == null ? (def.default_value || '') : value;
        var common = ' class="fdj-input" ' + attr;
        if (def.field_type === 'dropdown') {
          var html = '<select class="fdj-select" ' + attr + '><option value="">Select</option>';
          (def.options || []).forEach(function (option) { html += '<option value="' + esc(option) + '"' + (String(option) === String(value) ? ' selected' : '') + '>' + esc(option) + '</option>'; });
          return html + '</select>';
        }
        if (def.field_type === 'boolean') {
          return '<select class="fdj-select" ' + attr + '><option value="">Select</option><option value="1"' + (String(value) === '1' ? ' selected' : '') + '>True</option><option value="0"' + (String(value) === '0' ? ' selected' : '') + '>False</option></select>';
        }
        var type = def.field_type === 'numeric' ? 'number' : 'text';
        var placeholder = def.field_type === 'area' ? 'Length x width' : '';
        return '<input type="' + type + '"' + common + ' value="' + esc(value) + '" placeholder="' + placeholder + '">';
      }

      function renderCustomerCustomFields() {
        var host = document.getElementById('customerCustomFields');
        var fields = metaData.client_custom_fields || [];
        if (!fields.length) {
          host.innerHTML = '<div class="fdj-empty-note">No Client custom fields yet.</div>';
          return;
        }
        var html = '';
        fields.forEach(function (def) {
          html += '<div><label class="fdj-detail-label">' + esc(def.field_name) + '</label>' + fieldControl(def, customerCustomValues[String(def.id)], 'data-customer-custom="' + Number(def.id) + '"') + '</div>';
        });
        host.innerHTML = html;
      }

      function renderLocationCustomFields(location, index) {
        var fields = metaData.location_custom_fields || [];
        if (!fields.length) return '<div class="fdj-empty-note">No property custom fields yet.</div>';
        var html = '<div class="fdj-custom-grid">';
        fields.forEach(function (def) {
          html += '<div><label class="fdj-detail-label">' + esc(def.field_name) + '</label>' + fieldControl(def, location.custom_values[String(def.id)], 'data-location-custom="' + Number(def.id) + '" data-li="' + index + '"') + '</div>';
        });
        return html + '</div>';
      }

      function renderClientContacts() {
        var host = document.getElementById('clientContactsList');
        if (!host) return;
        var active = (clientContacts || []).filter(function (c) { return !c._delete; });
        if (!active.length) {
          host.innerHTML = '<div class="fdj-empty-note">No additional contacts added.</div>';
          document.getElementById('clientContactsJson').value = JSON.stringify(clientContacts);
          return;
        }
        var html = '<div class="fdj-contact-list">';
        active.forEach(function (contact) {
          var ci = clientContacts.indexOf(contact);
          var name = [contact.title_prefix, contact.first_name, contact.last_name].filter(Boolean).join(' ');
          var meta = [contact.role_name, contact.phone, contact.email].filter(Boolean).join(' · ');
          html += '<div class="fdj-contact-card"><div class="fdj-contact-avatar">' + esc((contact.first_name || 'C').charAt(0).toUpperCase()) + '</div><div class="fdj-contact-copy"><strong>' + esc(name || 'Contact') + (contact.is_billing_contact ? ' · Billing' : '') + '</strong><small>' + esc(meta || 'Additional contact') + '</small></div><button type="button" class="fdj-icon-btn" data-edit-client-contact="' + ci + '"><i class="bi bi-pencil"></i></button><button type="button" class="fdj-icon-btn danger" data-remove-client-contact="' + ci + '"><i class="bi bi-trash"></i></button></div>';
        });
        host.innerHTML = html + '</div>';
        document.getElementById('clientContactsJson').value = JSON.stringify(clientContacts);
      }

      function renderContacts(location, index) {
        var active = (location.contacts || []).filter(function (c) { return !c._delete; });
        if (!active.length) return '<div class="fdj-empty-note">No property contacts added.</div>';
        var html = '<div class="fdj-contact-list">';
        active.forEach(function (contact) {
          var ci = location.contacts.indexOf(contact);
          var name = [contact.title_prefix, contact.first_name, contact.last_name].filter(Boolean).join(' ');
          var meta = [contact.role_name, contact.phone, contact.email].filter(Boolean).join(' · ');
          html += '<div class="fdj-contact-card"><div class="fdj-contact-avatar">' + esc((contact.first_name || 'C').charAt(0).toUpperCase()) + '</div><div class="fdj-contact-copy"><strong>' + esc(name || 'Contact') + (contact.is_billing_contact ? ' · Billing' : '') + '</strong><small>' + esc(meta || 'Property contact') + '</small></div><button type="button" class="fdj-icon-btn" data-edit-contact="' + ci + '" data-li="' + index + '"><i class="bi bi-pencil"></i></button><button type="button" class="fdj-icon-btn danger" data-remove-contact="' + ci + '" data-li="' + index + '"><i class="bi bi-trash"></i></button></div>';
        });
        return html + '</div>';
      }

      function renderLocations() {
        var host = document.getElementById('propertySections');
        var visible = locations.filter(function (x) { return !x._delete; });
        if (!visible.length) {
          locations.push(blankLocation());
          visible = locations.filter(function (x) { return !x._delete; });
        }
        var html = '';
        visible.forEach(function (location, visualIndex) {
          var index = locations.indexOf(location);
          var title = visualIndex === 0 ? 'Property address' : 'Additional property address';
          var description = visualIndex === 0 ? 'Enter the primary service address, billing address, or any additional locations where services may take place.' : 'Add another service location for this client.';
          var taxLabel = taxName(location.tax_rate_id);
          html += '<section class="fdj-section fdj-property-block" data-location-section="' + index + '">'
            + '<div class="fdj-section-side"><h2>' + title + '</h2><p>' + description + '</p>'
            + (visualIndex === 0 ? '<div class="fdj-side-action"><button type="button" class="fdj-btn" id="addAnotherAddressButton"><i class="bi bi-plus-lg"></i> Add Another Address</button></div>' : '<div class="fdj-side-action"><button type="button" class="fdj-btn danger" data-remove-location="' + index + '"><i class="bi bi-trash"></i> Remove Address</button></div>')
            + '</div><div class="fdj-section-main">'
            + '<div class="fdj-address-group">'
            + '<div class="fdj-address-row one"><input class="fdj-input" data-li="' + index + '" data-lf="address_line1" maxlength="255" value="' + esc(location.address_line1) + '" placeholder="Street 1"></div>'
            + '<div class="fdj-address-row one"><input class="fdj-input" data-li="' + index + '" data-lf="address_line2" maxlength="255" value="' + esc(location.address_line2) + '" placeholder="Street 2"></div>'
            + '<div class="fdj-address-row"><input class="fdj-input" data-li="' + index + '" data-lf="city" maxlength="120" value="' + esc(location.city) + '" placeholder="City"><input class="fdj-input" data-li="' + index + '" data-lf="state" maxlength="120" value="' + esc(location.state) + '" placeholder="State"></div>'
            + '<div class="fdj-address-row"><input class="fdj-input" data-li="' + index + '" data-lf="postal_code" maxlength="40" value="' + esc(location.postal_code) + '" placeholder="ZIP / Postal code"><div class="fdj-country-wrap"><label class="fdj-floating-label">Country</label><select class="fdj-select" data-li="' + index + '" data-lf="country_id">' + countryOptions(location.country_id) + '</select></div></div>'
            + '</div>'
            + '<div class="fdj-tax-wrap"><input class="fdj-input" type="text" data-tax-search="' + index + '" value="' + esc(taxLabel) + '" placeholder="Search tax rate" autocomplete="off"><div class="fdj-pop-menu" data-tax-menu="' + index + '"></div></div>'
            + '<label class="fdj-check" style="margin-top:8px"><input type="checkbox" data-li="' + index + '" data-billing-same ' + (location.billing_same_as_property ? 'checked' : '') + '> Billing address is the same as property address</label>'
            + '<div class="fdj-billing-box ' + (!location.billing_same_as_property ? 'show' : '') + '" data-billing-box="' + index + '"><div class="fdj-detail-grid"><div class="full"><label class="fdj-detail-label">Billing Street 1</label><input class="fdj-input" data-li="' + index + '" data-lf="billing_address_line1" value="' + esc(location.billing_address_line1) + '"></div><div class="full"><label class="fdj-detail-label">Billing Street 2</label><input class="fdj-input" data-li="' + index + '" data-lf="billing_address_line2" value="' + esc(location.billing_address_line2) + '"></div><div><label class="fdj-detail-label">Billing City</label><input class="fdj-input" data-li="' + index + '" data-lf="billing_city" value="' + esc(location.billing_city) + '"></div><div><label class="fdj-detail-label">Billing State</label><input class="fdj-input" data-li="' + index + '" data-lf="billing_state" value="' + esc(location.billing_state) + '"></div><div><label class="fdj-detail-label">Billing ZIP / Postal</label><input class="fdj-input" data-li="' + index + '" data-lf="billing_postal_code" value="' + esc(location.billing_postal_code) + '"></div><div><label class="fdj-detail-label">Billing Country</label><select class="fdj-select" data-li="' + index + '" data-lf="billing_country_id">' + countryOptions(location.billing_country_id) + '</select></div></div></div>'
            + '<div class="fdj-accordion" data-property-accordion="details-' + index + '"><button class="fdj-accordion-head" type="button" data-property-toggle="details-' + index + '"><span>Property details</span><i class="bi bi-chevron-up"></i></button><div class="fdj-accordion-body">'
            + '<div class="fdj-inline-row"><span class="fdj-muted">Create custom fields to track additional property details.</span><button type="button" class="fdj-btn" data-add-location-custom="' + index + '">Add Custom Field</button></div>'
            + renderLocationCustomFields(location, index)
            + '</div></div>'
            + '<div class="fdj-accordion" data-property-accordion="contacts-' + index + '"><button class="fdj-accordion-head" type="button" data-property-toggle="contacts-' + index + '"><span>Property contacts</span><i class="bi bi-chevron-up"></i></button><div class="fdj-accordion-body"><div class="fdj-inline-row"><span class="fdj-muted">For contacts with access limited to this property.</span><button type="button" class="fdj-btn" data-add-contact="' + index + '">Add Contact</button></div>' + renderContacts(location, index) + '</div></div>'
            + '</div></section>';
        });
        host.innerHTML = html;
        document.getElementById('locationsJson').value = JSON.stringify(locations);
      }

      function renderSourceMenu(filter) {
        filter = String(filter || '').toLowerCase();
        var html = '';
        (metaData.lead_sources || []).forEach(function (row) {
          if (filter && String(row.source_name || '').toLowerCase().indexOf(filter) === -1) return;
          html += '<button type="button" class="fdj-pop-option" data-source-option="' + esc(row.source_name) + '"><span>' + esc(row.source_name) + '</span></button>';
        });
        html += '<button type="button" class="fdj-pop-create" id="createLeadSourceLink">Create new lead source</button>';
        var menu = document.getElementById('sourceMenu');
        menu.innerHTML = html;
        menu.classList.add('show');
      }

      function renderTaxMenu(index, filter) {
        var menu = document.querySelector('[data-tax-menu="' + index + '"]');
        if (!menu) return;
        filter = String(filter || '').toLowerCase();
        var html = '';
        (metaData.tax_rates || []).forEach(function (row) {
          var label = row.tax_name + ' (' + Number(row.rate_percent || 0).toFixed(2).replace(/\.00$/, '') + '%)';
          if (filter && label.toLowerCase().indexOf(filter) === -1) return;
          html += '<button type="button" class="fdj-pop-option" data-tax-option="' + Number(row.id) + '" data-li="' + index + '"><span>' + esc(label) + '</span></button>';
        });
        if (!html) html = '<div class="fdj-pop-option" style="cursor:default">No matching tax rates</div>';
        html += '<button type="button" class="fdj-pop-create" data-create-tax="' + index + '">Create new tax rate</button>';
        menu.innerHTML = html;
        menu.classList.add('show');
      }

      function setCommunicationUI() {
        document.querySelectorAll('[data-comm]').forEach(function (button) {
          button.classList.toggle('on', Number(communication[button.dataset.comm] || 0) === 1);
        });
        document.getElementById('communicationJson').value = JSON.stringify(communication);
      }

      function setContactCommunicationUI() {
        document.querySelectorAll('[data-contact-comm]').forEach(function (button) {
          button.classList.toggle('on', Number(contactComm[button.dataset.contactComm] || 0) === 1);
        });
      }

      function updatePortalFields() {
        document.querySelectorAll('.portal-password-field').forEach(function (el) { el.style.display = 'none'; });
        var label = document.getElementById('passwordLabel');
        if (label) label.textContent = 'Client Password';
      }

      function applyMeta(meta) {
        metaData = meta || metaData;
        renderCustomerCustomFields();
        if (metaData.schema && !metaData.schema.ready) {
          saveButton.disabled = true;
          saveAnotherButton.disabled = true;
          toast('error', 'Run ' + (metaData.schema.migration || 'the Client form migration') + ' before saving this form.');
        }
      }

      function updateMode() {
        document.getElementById('pageHeading').textContent = editMode ? 'Edit Client' : 'New Client';
        document.getElementById('saveCustomerText').textContent = editMode ? 'Update Client' : 'Save Client';
        updatePortalFields();
      }

      function load() {
        var fd = new FormData();
        fd.append('action', editMode ? 'get' : 'meta');
        if (editMode) fd.append('client_id', clientId);
        request(fd).then(function (data) {
          applyMeta(data.meta || {});
          if (editMode) {
            var row = data.client || {};
            var portal = data.portal || {};
            document.getElementById('clientId').value = row.id || clientId;
            document.getElementById('titlePrefix').value = row.title_prefix || '';
            document.getElementById('firstName').value = row.first_name || '';
            document.getElementById('lastName').value = row.last_name || '';
            document.getElementById('companyName').value = row.company_name || '';
            document.getElementById('email').value = row.email || '';
            phoneNumbers = (data.phone_numbers || []).map(normalizePhoneNumber);
            if (!phoneNumbers.length) {
              if (row.phone) phoneNumbers.push(normalizePhoneNumber({ phone_number: row.phone, phone_type: 'main', receives_messages: Number(row.allow_sms == null ? 1 : row.allow_sms), is_primary: 1, sort_order: 1 }));
              if (row.alternate_phone) phoneNumbers.push(normalizePhoneNumber({ phone_number: row.alternate_phone, phone_type: 'other', receives_messages: Number(row.allow_sms == null ? 1 : row.allow_sms), is_primary: 0, sort_order: 2 }));
            }
            if (!phoneNumbers.length) phoneNumbers = [blankPhoneNumber()];
            document.getElementById('source').value = row.source || '';
            document.getElementById('clientType').value = row.client_type || 'client';
            document.getElementById('clientStatus').value = row.status || 'active';
            document.getElementById('branchId').value = row.branch_id || '';
            document.getElementById('accountManagerId').value = row.account_manager_id || '';
            document.getElementById('preferredContactMethod').value = row.preferred_contact_method || 'email';
            document.getElementById('taxNumber').value = row.tax_number || '';
            document.getElementById('allowEmail').checked = Number(row.allow_email) === 1;
            document.getElementById('allowSms').checked = Number(row.allow_sms) === 1;
            document.getElementById('notes').value = row.notes || '';
            document.getElementById('portalEnabled').checked = portal.exists ? String(portal.status) !== 'inactive' : false;
            communication = Object.assign(communication, data.communication || {});
            customerCustomValues = data.customer_custom_values || {};
            clientContacts = (data.client_contacts || []).map(normalizeContact);
            locations = (data.locations || []).map(normalizeLocation);
          } else {
            clientContacts = [];
            phoneNumbers = [blankPhoneNumber()];
            locations = [blankLocation()];
            communication = { quote_followups: 1, invoice_followups: 1, visit_reminders: 1, job_close_followups: 1 };
          }
          renderCustomerCustomFields();
          renderClientContacts();
          renderPhoneNumbers();
          renderLocations();
          setCommunicationUI();
          syncDisplayName();
          updateMode();
          initializing = false;
          dirty = false;
        }).catch(function (error) {
          initializing = false;
          toast('error', error.message);
          saveButton.disabled = true;
          saveAnotherButton.disabled = true;
        });
      }

      document.getElementById('phoneNumbersList').addEventListener('input', function (event) {
        var input = event.target.closest('[data-phone-input]');
        if (!input) return;
        var index = Number(input.dataset.phoneInput);
        if (!phoneNumbers[index]) return;
        phoneNumbers[index].phone_number = input.value;
        var rowEl = input.closest('.fdj-phone-row');
        var isBlank = input.value.trim() === '';
        if (rowEl) rowEl.classList.toggle('is-blank', isBlank);
        if (isBlank && Number(phoneNumbers[index].is_primary || 0) === 1) {
          phoneNumbers[index].is_primary = 0;
          var nextPrimary = phoneNumbers.find(function (row) { return !row._delete && String(row.phone_number || '').trim() !== ''; });
          if (nextPrimary) nextPrimary.is_primary = 1;
          renderPhoneNumbers();
        } else if (!isBlank && !activePhoneNumbers().some(function (row) { return row !== phoneNumbers[index] && Number(row.is_primary || 0) === 1; })) {
          phoneNumbers[index].is_primary = 1;
          renderPhoneNumbers();
          var refocus = document.querySelector('[data-phone-input="' + index + '"]');
          if (refocus) { refocus.focus(); try { refocus.setSelectionRange(refocus.value.length, refocus.value.length); } catch (e) {} }
        } else {
          syncPhoneHiddenFields();
        }
        if (!initializing) dirty = true;
      });

      document.getElementById('phoneNumbersList').addEventListener('change', function (event) {
        var type = event.target.closest('[data-phone-type]');
        if (!type) return;
        var index = Number(type.dataset.phoneType);
        if (!phoneNumbers[index]) return;
        phoneNumbers[index].phone_type = type.value;
        syncPhoneHiddenFields();
        dirty = true;
      });

      document.getElementById('phoneNumbersList').addEventListener('click', function (event) {
        var add = event.target.closest('[data-add-phone]');
        if (add) {
          phoneNumbers.push(blankPhoneNumber());
          renderPhoneNumbers();
          var lastIndex = phoneNumbers.length - 1;
          var input = document.querySelector('[data-phone-input="' + lastIndex + '"]');
          if (input) input.focus();
          dirty = true;
          return;
        }
        var remove = event.target.closest('[data-delete-phone]');
        if (remove) {
          var removeIndex = Number(remove.dataset.deletePhone);
          if (!phoneNumbers[removeIndex]) return;
          var wasPrimary = Number(phoneNumbers[removeIndex].is_primary || 0) === 1;
          if (Number(phoneNumbers[removeIndex].id || 0) > 0) phoneNumbers[removeIndex]._delete = 1;
          else phoneNumbers.splice(removeIndex, 1);
          if (wasPrimary) {
            var next = phoneNumbers.find(function (row) { return !row._delete && String(row.phone_number || '').trim() !== ''; });
            if (next) next.is_primary = 1;
          }
          renderPhoneNumbers();
          dirty = true;
          return;
        }
        var primary = event.target.closest('[data-primary-phone]');
        if (primary) {
          var primaryIndex = Number(primary.dataset.primaryPhone);
          if (!phoneNumbers[primaryIndex] || !String(phoneNumbers[primaryIndex].phone_number || '').trim()) return;
          phoneNumbers.forEach(function (row, idx) { row.is_primary = idx === primaryIndex ? 1 : 0; });
          renderPhoneNumbers();
          dirty = true;
          return;
        }
        var sms = event.target.closest('[data-phone-sms]');
        if (sms) {
          var smsIndex = Number(sms.dataset.phoneSms);
          if (!phoneNumbers[smsIndex]) return;
          phoneNumbers[smsIndex].receives_messages = Number(phoneNumbers[smsIndex].receives_messages || 0) === 1 ? 0 : 1;
          sms.classList.toggle('on', Number(phoneNumbers[smsIndex].receives_messages) === 1);
          syncPhoneHiddenFields();
          dirty = true;
        }
      });

      document.getElementById('communicationSettingsButton').addEventListener('click', function () {
        setCommunicationUI();
        openModal('communicationModal');
      });

      document.getElementById('saveCommunicationButton').addEventListener('click', function () {
        document.getElementById('communicationJson').value = JSON.stringify(communication);
        closeModal('communicationModal');
        dirty = true;
        toast('success', 'Communication settings updated for this Client form.');
      });

      document.addEventListener('click', function (event) {
        var close = event.target.closest('[data-close-modal]');
        if (close) closeModal(close.dataset.closeModal);
        var toggle = event.target.closest('[data-toggle-accordion]');
        if (toggle) document.getElementById(toggle.dataset.toggleAccordion).classList.toggle('collapsed');
        var configure = event.target.closest('[data-configure-info]');
        if (configure) toast('success', 'These switches control the client-level automation preferences saved with this client.');
      });

      document.querySelectorAll('.fdj-modal-backdrop').forEach(function (backdrop) {
        backdrop.addEventListener('click', function (event) { if (event.target === backdrop) closeModal(backdrop.id); });
      });

      document.querySelectorAll('[data-comm]').forEach(function (button) {
        button.addEventListener('click', function () {
          var key = this.dataset.comm;
          communication[key] = Number(communication[key] || 0) === 1 ? 0 : 1;
          setCommunicationUI();
        });
      });

      document.getElementById('source').addEventListener('focus', function () { renderSourceMenu(this.value); });
      document.getElementById('source').addEventListener('input', function () { renderSourceMenu(this.value); dirty = true; });
      document.getElementById('sourceMenu').addEventListener('click', function (event) {
        var option = event.target.closest('[data-source-option]');
        if (option) {
          document.getElementById('source').value = option.dataset.sourceOption;
          document.getElementById('sourceMenu').classList.remove('show');
          dirty = true;
          return;
        }
        if (event.target.closest('#createLeadSourceLink')) {
          document.getElementById('sourceMenu').classList.remove('show');
          document.getElementById('newLeadSourceName').value = document.getElementById('source').value.trim();
          openModal('leadSourceModal');
          setTimeout(function () { document.getElementById('newLeadSourceName').focus(); }, 30);
        }
      });

      document.getElementById('createLeadSourceButton').addEventListener('click', function () {
        var name = document.getElementById('newLeadSourceName').value.trim();
        if (!name) { toast('warning', 'Enter a lead source name.'); return; }
        var fd = new FormData(); fd.append('action', 'create_lead_source'); fd.append('source_name', name);
        request(fd).then(function (data) {
          var row = data.lead_source || { source_name: name };
          if (!(metaData.lead_sources || []).some(function (x) { return String(x.source_name).toLowerCase() === String(row.source_name).toLowerCase(); })) metaData.lead_sources.push(row);
          document.getElementById('source').value = row.source_name || name;
          closeModal('leadSourceModal');
          dirty = true;
          toast('success', data.message || 'Lead source created.');
        }).catch(function (error) { toast('error', error.message); });
      });

      var addClientContactButton = document.getElementById('addClientContactButton');
      if (addClientContactButton) {
        addClientContactButton.addEventListener('click', function () { openContact('client', -1, -1); });
      }
      var clientContactsList = document.getElementById('clientContactsList');
      if (clientContactsList) {
        clientContactsList.addEventListener('click', function (event) {
          var editContact = event.target.closest('[data-edit-client-contact]');
          if (editContact) { openContact('client', -1, Number(editContact.dataset.editClientContact)); return; }
          var removeContact = event.target.closest('[data-remove-client-contact]');
          if (removeContact) {
            var ci = Number(removeContact.dataset.removeClientContact);
            if (!clientContacts[ci]) return;
            if (clientContacts[ci].id > 0) clientContacts[ci]._delete = 1; else clientContacts.splice(ci, 1);
            renderClientContacts();
            dirty = true;
          }
        });
      }

      document.getElementById('propertySections').addEventListener('focusin', function (event) {
        var tax = event.target.closest('[data-tax-search]');
        if (tax) renderTaxMenu(Number(tax.dataset.taxSearch), tax.value);
      });

      document.getElementById('propertySections').addEventListener('input', function (event) {
        var target = event.target;
        var index = Number(target.dataset.li);
        if (target.dataset.lf && locations[index]) {
          locations[index][target.dataset.lf] = target.value;
          dirty = true;
        }
        if (target.hasAttribute('data-tax-search')) {
          index = Number(target.dataset.taxSearch);
          if (locations[index]) locations[index].tax_rate_id = '';
          renderTaxMenu(index, target.value);
          dirty = true;
        }
        if (target.hasAttribute('data-location-custom')) {
          index = Number(target.dataset.li);
          if (locations[index]) locations[index].custom_values[String(target.dataset.locationCustom)] = target.value;
          dirty = true;
        }
        document.getElementById('locationsJson').value = JSON.stringify(locations);
      });

      document.getElementById('propertySections').addEventListener('change', function (event) {
        var target = event.target;
        var index = Number(target.dataset.li);
        if (target.dataset.lf && locations[index]) locations[index][target.dataset.lf] = target.value;
        if (target.hasAttribute('data-billing-same') && locations[index]) {
          locations[index].billing_same_as_property = target.checked ? 1 : 0;
          var box = document.querySelector('[data-billing-box="' + index + '"]');
          if (box) box.classList.toggle('show', !target.checked);
        }
        if (target.hasAttribute('data-location-custom') && locations[index]) locations[index].custom_values[String(target.dataset.locationCustom)] = target.value;
        document.getElementById('locationsJson').value = JSON.stringify(locations);
        dirty = true;
      });

      document.getElementById('propertySections').addEventListener('click', function (event) {
        var addAnother = event.target.closest('#addAnotherAddressButton');
        if (addAnother) { locations.push(blankLocation()); renderLocations(); dirty = true; return; }
        var remove = event.target.closest('[data-remove-location]');
        if (remove) {
          var ri = Number(remove.dataset.removeLocation);
          if (!locations[ri]) return;
          if (locations[ri].id > 0) locations[ri]._delete = 1; else locations.splice(ri, 1);
          var active = locations.filter(function (x) { return !x._delete; });
          if (active.length && !active.some(function (x) { return Number(x.is_primary) === 1; })) active[0].is_primary = 1;
          renderLocations(); dirty = true; return;
        }
        var taxOption = event.target.closest('[data-tax-option]');
        if (taxOption) {
          var ti = Number(taxOption.dataset.li), taxId = Number(taxOption.dataset.taxOption);
          if (locations[ti]) locations[ti].tax_rate_id = taxId;
          var input = document.querySelector('[data-tax-search="' + ti + '"]'); if (input) input.value = taxName(taxId);
          var menu = document.querySelector('[data-tax-menu="' + ti + '"]'); if (menu) menu.classList.remove('show');
          document.getElementById('locationsJson').value = JSON.stringify(locations); dirty = true; return;
        }
        var createTax = event.target.closest('[data-create-tax]');
        if (createTax) {
          taxLocationIndex = Number(createTax.dataset.createTax);
          document.querySelectorAll('[data-tax-menu]').forEach(function (menu) { menu.classList.remove('show'); });
          document.getElementById('taxRateName').value = '';
          document.getElementById('taxRatePercent').value = '0';
          document.getElementById('taxRateDescription').value = '';
          document.getElementById('taxRateDefault').checked = false;
          openModal('taxRateModal'); return;
        }
        var toggle = event.target.closest('[data-property-toggle]');
        if (toggle) {
          var panel = document.querySelector('[data-property-accordion="' + toggle.dataset.propertyToggle + '"]');
          if (panel) panel.classList.toggle('collapsed'); return;
        }
        var addCustom = event.target.closest('[data-add-location-custom]');
        if (addCustom) { openCustomField('location', Number(addCustom.dataset.addLocationCustom)); return; }
        var addContact = event.target.closest('[data-add-contact]');
        if (addContact) { openContact('location', Number(addContact.dataset.addContact), -1); return; }
        var editContact = event.target.closest('[data-edit-contact]');
        if (editContact) { openContact('location', Number(editContact.dataset.li), Number(editContact.dataset.editContact)); return; }
        var removeContact = event.target.closest('[data-remove-contact]');
        if (removeContact) {
          var li = Number(removeContact.dataset.li), ci = Number(removeContact.dataset.removeContact);
          if (!locations[li] || !locations[li].contacts[ci]) return;
          if (locations[li].contacts[ci].id > 0) locations[li].contacts[ci]._delete = 1; else locations[li].contacts.splice(ci, 1);
          renderLocations(); dirty = true; return;
        }
      });

      document.getElementById('createTaxRateButton').addEventListener('click', function () {
        var name = document.getElementById('taxRateName').value.trim();
        var rate = document.getElementById('taxRatePercent').value;
        if (!name) { toast('warning', 'Tax rate name is required.'); return; }
        var fd = new FormData();
        fd.append('action', 'create_tax_rate'); fd.append('name', name); fd.append('rate_percent', rate);
        fd.append('description', document.getElementById('taxRateDescription').value.trim());
        fd.append('is_default', document.getElementById('taxRateDefault').checked ? '1' : '0');
        request(fd).then(function (data) {
          var row = data.tax_rate;
          if (row) {
            metaData.tax_rates = (metaData.tax_rates || []).filter(function (x) { return Number(x.id) !== Number(row.id); });
            metaData.tax_rates.push(row);
            if (locations[taxLocationIndex]) locations[taxLocationIndex].tax_rate_id = Number(row.id);
          }
          closeModal('taxRateModal'); renderLocations(); dirty = true; toast('success', data.message || 'Tax rate created.');
        }).catch(function (error) { toast('error', error.message); });
      });

      function openCustomField(appliesTo, locationIndex) {
        customFieldContext = { appliesTo: appliesTo, locationIndex: Number(locationIndex == null ? -1 : locationIndex) };
        document.getElementById('customFieldScopeLabel').textContent = appliesTo === 'location' ? 'All properties' : 'All clients';
        document.getElementById('customFieldName').value = '';
        document.getElementById('customFieldType').value = 'text';
        document.getElementById('customFieldTransferable').checked = false;
        document.getElementById('customFieldDefault').value = '';
        document.getElementById('customFieldDropdownOptions').value = '';
        document.getElementById('customFieldDropdownOptionsWrap').style.display = 'none';
        openModal('customFieldModal');
      }

      document.querySelectorAll('[data-add-custom="client"]').forEach(function (button) { button.addEventListener('click', function () { openCustomField('client', -1); }); });
      document.getElementById('customFieldType').addEventListener('change', function () { document.getElementById('customFieldDropdownOptionsWrap').style.display = this.value === 'dropdown' ? 'block' : 'none'; });
      document.getElementById('createCustomFieldButton').addEventListener('click', function () {
        var name = document.getElementById('customFieldName').value.trim();
        if (!name) { toast('warning', 'Custom field name is required.'); return; }
        var options = document.getElementById('customFieldDropdownOptions').value.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
        var fd = new FormData();
        fd.append('action', 'create_custom_field');
        fd.append('applies_to', customFieldContext.appliesTo);
        fd.append('field_name', name);
        fd.append('field_type', document.getElementById('customFieldType').value);
        fd.append('is_transferable', document.getElementById('customFieldTransferable').checked ? '1' : '0');
        fd.append('default_value', document.getElementById('customFieldDefault').value);
        fd.append('options_json', JSON.stringify(options));
        request(fd).then(function (data) {
          var field = data.custom_field;
          if (field) {
            if (customFieldContext.appliesTo === 'client') {
              metaData.client_custom_fields.push(field); customerCustomValues[String(field.id)] = field.default_value || ''; renderCustomerCustomFields();
            } else {
              metaData.location_custom_fields.push(field);
              if (locations[customFieldContext.locationIndex]) locations[customFieldContext.locationIndex].custom_values[String(field.id)] = field.default_value || '';
              renderLocations();
            }
          }
          closeModal('customFieldModal'); dirty = true; toast('success', data.message || 'Custom field created.');
        }).catch(function (error) { toast('error', error.message); });
      });

      function openContact(scope, locationIndex, contactIndex) {
        scope = scope === 'client' ? 'client' : 'location';
        locationIndex = Number(locationIndex == null ? -1 : locationIndex);
        contactIndex = Number(contactIndex == null ? -1 : contactIndex);
        contactContext = { scope: scope, locationIndex: locationIndex, contactIndex: contactIndex };
        var source = scope === 'client'
          ? clientContacts
          : (locations[locationIndex] ? locations[locationIndex].contacts : []);
        var contact = contactIndex >= 0 && source ? source[contactIndex] : null;
        contact = contact || normalizeContact({});
        document.getElementById('contactModalTitle').textContent = contactIndex >= 0 ? 'Edit contact' : 'Add contact';
        document.getElementById('saveContactButton').textContent = contactIndex >= 0 ? 'Save Contact' : 'Add Contact';
        document.getElementById('contactTitlePrefix').value = contact.title_prefix || '';
        document.getElementById('contactFirstName').value = contact.first_name || '';
        document.getElementById('contactLastName').value = contact.last_name || '';
        document.getElementById('contactRole').value = contact.role_name || '';
        document.getElementById('contactPhone').value = contact.phone || '';
        document.getElementById('contactEmail').value = contact.email || '';
        document.getElementById('contactBilling').checked = !!contact.is_billing_contact;
        document.getElementById('contactPortalAccess').checked = !!contact.portal_access;
        contactComm = { quote_followups: contact.quote_followups ? 1 : 0, invoice_followups: contact.invoice_followups ? 1 : 0, visit_reminders: contact.visit_reminders ? 1 : 0, job_close_followups: contact.job_close_followups ? 1 : 0 };
        setContactCommunicationUI(); openModal('contactModal');
      }

      document.querySelectorAll('[data-contact-comm]').forEach(function (button) {
        button.addEventListener('click', function () { var key = this.dataset.contactComm; contactComm[key] = Number(contactComm[key] || 0) === 1 ? 0 : 1; setContactCommunicationUI(); });
      });

      document.getElementById('saveContactButton').addEventListener('click', function () {
        var isClientContact = contactContext.scope === 'client';
        var li = contactContext.locationIndex;
        if (!isClientContact && !locations[li]) return;
        var targetContacts = isClientContact ? clientContacts : locations[li].contacts;
        var first = document.getElementById('contactFirstName').value.trim();
        var email = document.getElementById('contactEmail').value.trim();
        if (!first) { toast('warning', 'Contact first name is required.'); return; }
        if (email && !document.getElementById('contactEmail').checkValidity()) { toast('warning', 'Enter a valid contact email.'); return; }
        var existing = contactContext.contactIndex >= 0 ? targetContacts[contactContext.contactIndex] : normalizeContact({});
        var row = Object.assign(existing, {
          title_prefix: document.getElementById('contactTitlePrefix').value,
          first_name: first,
          last_name: document.getElementById('contactLastName').value.trim(),
          role_name: document.getElementById('contactRole').value.trim(),
          phone: document.getElementById('contactPhone').value.trim(),
          email: email,
          is_billing_contact: document.getElementById('contactBilling').checked ? 1 : 0,
          portal_access: document.getElementById('contactPortalAccess').checked ? 1 : 0,
          quote_followups: contactComm.quote_followups,
          invoice_followups: contactComm.invoice_followups,
          visit_reminders: contactComm.visit_reminders,
          job_close_followups: contactComm.job_close_followups,
          _delete: 0
        });
        if (contactContext.contactIndex >= 0) targetContacts[contactContext.contactIndex] = row; else targetContacts.push(row);
        closeModal('contactModal');
        if (isClientContact) renderClientContacts(); else renderLocations();
        dirty = true;
      });

      document.getElementById('customerCustomFields').addEventListener('input', function (event) {
        if (!event.target.hasAttribute('data-customer-custom')) return;
        customerCustomValues[String(event.target.dataset.customerCustom)] = event.target.value;
        document.getElementById('customerCustomValuesJson').value = JSON.stringify(customerCustomValues);
        dirty = true;
      });
      document.getElementById('customerCustomFields').addEventListener('change', function (event) {
        if (!event.target.hasAttribute('data-customer-custom')) return;
        customerCustomValues[String(event.target.dataset.customerCustom)] = event.target.value;
        document.getElementById('customerCustomValuesJson').value = JSON.stringify(customerCustomValues);
        dirty = true;
      });

      ['titlePrefix', 'firstName', 'lastName', 'companyName'].forEach(function (id) {
        document.getElementById(id).addEventListener(id === 'titlePrefix' ? 'change' : 'input', function () { syncDisplayName(); if (!initializing) dirty = true; });
      });

      form.addEventListener('input', function () { if (!initializing) dirty = true; });
      form.addEventListener('change', function () { if (!initializing) dirty = true; });
      document.getElementById('portalEnabled').addEventListener('change', updatePortalFields);

      document.addEventListener('click', function (event) {
        if (!event.target.closest('#sourceWrap')) document.getElementById('sourceMenu').classList.remove('show');
        if (!event.target.closest('.fdj-tax-wrap')) document.querySelectorAll('[data-tax-menu]').forEach(function (menu) { menu.classList.remove('show'); });
      });

      function preparePayload() {
        syncDisplayName();
        syncPhoneHiddenFields();
        document.getElementById('communicationJson').value = JSON.stringify(communication);
        document.getElementById('customerCustomValuesJson').value = JSON.stringify(customerCustomValues);
        document.getElementById('clientContactsJson').value = JSON.stringify(clientContacts);
        document.getElementById('locationsJson').value = JSON.stringify(locations);
      }

      function validateForm() {
        syncDisplayName();
        if (!document.getElementById('displayName').value.trim()) { toast('warning', 'Enter a first name, last name, or company name.'); return false; }
        var email = document.getElementById('email');
        if (email.value && !email.checkValidity()) { toast('warning', 'Enter a valid email address.'); return false; }
        var seenPhones = {};
        var phones = activePhoneNumbers();
        for (var p = 0; p < phones.length; p++) {
          var key = String(phones[p].phone_number || '').replace(/\s+/g, '').toLowerCase();
          if (seenPhones[key]) { toast('warning', 'The same phone number cannot be added more than once.'); return false; }
          seenPhones[key] = true;
        }
        for (var i = 0; i < locations.length; i++) {
          var location = locations[i];
          if (location._delete) continue;
          var hasData = [location.address_line1, location.address_line2, location.city, location.state, location.postal_code].some(function (x) { return String(x || '').trim() !== ''; }) || Number(location.tax_rate_id || 0) > 0 || (location.contacts || []).some(function (c) { return !c._delete; });
          if (hasData && !String(location.address_line1 || '').trim()) { toast('warning', 'Street 1 is required for each property address you add.'); return false; }
        }
        return true;
      }

      function submitForm(mode) {
        saveMode = mode || 'normal';
        if (!validateForm()) return;
        preparePayload();
        var fd = new FormData(form);
        fd.append('action', 'save');
        fd.set('client_id', editMode ? clientId : 0);
        submitting = true;
        setLoading(true);
        request(fd).then(function (data) {
          dirty = false;
          var message = data.message || 'Client saved successfully.';
          if (data.email_notice) message += ' ' + data.email_notice;
          toast(data.email_status === 'failed' ? 'warning' : 'success', message);
          if (saveMode === 'another') {
            setTimeout(function () { window.location.href = 'client-form.php'; }, 500);
            return;
          }
          if (!editMode && data.client_id) {
            var createdClientId = Number(data.client_id);
            if (createdClientId > 0) {
              setTimeout(function () {
                window.location.href = 'client-view.php?client_id=' + encodeURIComponent(createdClientId);
              }, 650);
              return;
            }
          }
        }).catch(function (error) {
          toast('error', error.message);
        }).finally(function () {
          submitting = false;
          setLoading(false);
        });
      }

      form.addEventListener('submit', function (event) { event.preventDefault(); submitForm('normal'); });
      saveAnotherButton.addEventListener('click', function () { submitForm('another'); });
      window.addEventListener('beforeunload', function (event) { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });

      updateMode();
      setCommunicationUI();
      load();
    })();
  </script>
</body>
</html>
