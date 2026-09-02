<?php
/* FieldPlx Product Form - Version 2.0.1 - 2026-08-29 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Add Product';
$activePage = 'products';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['product_form_csrf_token'])) {
    $_SESSION['product_form_csrf_token'] = bin2hex(random_bytes(32));
}
$productFormCsrfToken = (string)$_SESSION['product_form_csrf_token'];
$productId = isset($_GET['id']) ? max(0, (int)$_GET['id']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <title><?= $productId > 0 ? 'Edit Product' : 'Add Product' ?> - FieldPlx</title>
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


    /* ==========================================================
       Product form additions - built on canonical tenant template
       ========================================================== */
    .fd-product-layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 340px;
      gap: 16px;
      align-items: start;
    }

    .fd-product-main,
    .fd-product-side { min-width: 0; }

    .fd-product-card {
      margin-bottom: 16px;
      overflow: visible;
    }

    .fd-product-card-head {
      min-height: 58px;
      padding: 11px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border-bottom: 1px solid var(--fd-border);
      background: #fbfcfd;
    }

    .fd-product-card-icon {
      width: 34px;
      height: 34px;
      flex: 0 0 34px;
      display: grid;
      place-items: center;
      border-radius: 9px;
      color: var(--fd-green-dark);
      background: var(--fd-green-soft);
      font-size: 15px;
    }

    .fd-product-card-head > div { min-width: 0; }
    .fd-product-card-head h2 {
      margin: 0;
      color: var(--fd-text);
      font-size: 12px;
      font-weight: 700;
    }
    .fd-product-card-head p {
      margin: 3px 0 0;
      color: var(--fd-muted);
      font-size: 8.5px;
      line-height: 1.45;
    }
    .fd-product-card-body { padding: 15px; }

    .fd-product-label {
      display: block;
      margin-bottom: 6px;
      color: #42536c;
      font-size: 9px;
      font-weight: 700;
    }

    .fd-product-control,
    .fd-team-field > select {
      width: 100%;
      min-height: 40px;
      padding: 8px 10px;
      border: 1px solid #dfe5ec;
      border-radius: 8px;
      outline: 0;
      color: #263750;
      background: #fff;
      font-family: inherit;
      font-size: 10px;
    }

    .fd-product-control:focus,
    .fd-team-field > select:focus {
      border-color: #a9cf75;
      box-shadow: 0 0 0 3px rgba(116, 184, 36, .11);
    }

    textarea.fd-product-control {
      min-height: 92px;
      resize: vertical;
    }

    input[type="file"].fd-product-control {
      padding: 7px 9px;
      line-height: 22px;
    }

    input[type="file"].fd-product-control::file-selector-button {
      height: 38px;
      margin: -7px 10px -7px -9px;
      padding: 0 12px;
      border: 0;
      border-right: 1px solid #dfe5ec;
      color: #43546c;
      background: #f8fafc;
      font-size: 9px;
      font-weight: 700;
      cursor: pointer;
    }

    .fd-product-help {
      margin-top: 5px;
      color: #8a96a7;
      font-size: 8px;
      line-height: 1.45;
    }

    .fd-product-image-box {
      min-height: 190px;
      padding: 12px;
      border: 1px dashed #d7e0e9;
      border-radius: 10px;
      background: #fbfcfd;
    }

    .fd-product-preview {
      height: 130px;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      border: 1px solid #e5eaf1;
      border-radius: 9px;
      color: #95a0af;
      background: #fff;
      font-size: 9px;
    }

    .fd-product-preview img {
      width: 100%;
      height: 100%;
      display: block;
      object-fit: contain;
    }

    .fd-product-switch {
      min-height: 52px;
      padding: 10px 12px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      border: 1px solid var(--fd-border);
      border-radius: 9px;
      background: #fbfcfd;
    }

    .fd-product-switch strong,
    .fd-product-switch small { display: block; }
    .fd-product-switch strong { color: #31425b; font-size: 9.5px; }
    .fd-product-switch small { margin-top: 2px; color: #8a96a7; font-size: 8px; }

    /* Inventory toggle: explicitly override generic .fd-team-field input rules. */
    .fd-product-switch input#trackInventory {
      width: 44px !important;
      min-width: 44px !important;
      max-width: 44px !important;
      height: 24px !important;
      min-height: 24px !important;
      flex: 0 0 44px !important;
      position: relative !important;
      display: inline-block !important;
      margin: 0 !important;
      padding: 0 !important;
      appearance: none !important;
      -webkit-appearance: none !important;
      border: 1px solid #cfd8e3 !important;
      border-radius: 999px !important;
      outline: 0 !important;
      background: #dce3ea !important;
      box-shadow: none !important;
      cursor: pointer;
      vertical-align: middle;
      transition: background .18s ease, border-color .18s ease, box-shadow .18s ease;
    }

    .fd-product-switch input#trackInventory::before {
      width: 18px;
      height: 18px;
      position: absolute;
      top: 2px;
      left: 2px;
      content: "";
      border-radius: 50%;
      background: #fff;
      box-shadow: 0 1px 4px rgba(0, 17, 49, .20);
      transition: transform .18s ease;
    }

    .fd-product-switch input#trackInventory:checked {
      border-color: var(--fd-green) !important;
      background: var(--fd-green) !important;
    }

    .fd-product-switch input#trackInventory:checked::before {
      transform: translateX(20px);
    }

    .fd-product-switch input#trackInventory:focus-visible {
      box-shadow: 0 0 0 3px rgba(116,184,36,.16) !important;
    }

    .fd-product-money-wrap {
      position: relative;
    }

    .fd-product-money-symbol {
      min-width: 36px;
      position: absolute;
      top: 50%;
      left: 1px;
      z-index: 2;
      transform: translateY(-50%);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 8px;
      border-right: 1px solid #e2e7ed;
      color: #52627a;
      font-size: 10px;
      font-weight: 700;
      pointer-events: none;
    }

    .fd-product-money-wrap .fd-product-control {
      padding-left: 48px !important;
    }

    .fd-product-money-wrap.currency-after .fd-product-money-symbol {
      right: 1px;
      left: auto;
      border-right: 0;
      border-left: 1px solid #e2e7ed;
    }

    .fd-product-money-wrap.currency-after .fd-product-control {
      padding-right: 48px !important;
      padding-left: 10px !important;
    }

    .fd-product-price {
      margin-top: 14px;
      padding: 14px;
      border: 1px solid #dfe7ef;
      border-radius: 9px;
      background: #f8fafc;
    }
    .fd-product-price small,
    .fd-product-price strong { display: block; }
    .fd-product-price small { color: #7e8b9d; font-size: 8.5px; }
    .fd-product-price strong { margin-top: 5px; color: var(--fd-navy); font-size: 21px; line-height: 1.1; }

    .fd-product-tax-note {
      margin-top: 8px;
      padding: 9px 10px;
      border: 1px solid #e7ecf2;
      border-radius: 8px;
      color: #5e6c7e;
      background: #f5f8fb;
      font-size: 8px;
      line-height: 1.5;
    }

    .pf-hidden { display: none !important; }

    /* Select2 follows canonical FieldPlx input styling. */
    .select2-container { width: 100% !important; max-width: 100%; }
    .select2-container .select2-selection--single {
      height: 40px !important;
      border: 1px solid #dfe5ec !important;
      border-radius: 8px !important;
      background: #fff !important;
      box-shadow: none !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
      padding-left: 10px !important;
      padding-right: 30px !important;
      line-height: 38px !important;
      color: #263750 !important;
      font-size: 10px !important;
    }
    .select2-container--default .select2-selection--single .select2-selection__placeholder { color: #8a96a7 !important; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px !important; right: 5px !important; }
    .select2-dropdown {
      overflow: hidden;
      border: 1px solid #dfe5ec !important;
      border-radius: 8px !important;
      box-shadow: 0 10px 28px rgba(0, 17, 49, .12) !important;
      z-index: 17000 !important;
    }
    .select2-search--dropdown { padding: 8px !important; }
    .select2-search__field {
      height: 34px;
      padding: 6px 8px !important;
      border: 1px solid #dfe5ec !important;
      border-radius: 7px;
      outline: 0;
      font-size: 10px !important;
    }
    .select2-results__option { padding: 8px 10px !important; font-size: 10px !important; }
    .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
      color: #fff !important;
      background: var(--fd-green) !important;
    }
    .select2-container--default.select2-container--focus .select2-selection--single,
    .select2-container--default.select2-container--open .select2-selection--single {
      border-color: #a9cf75 !important;
      box-shadow: 0 0 0 3px rgba(116, 184, 36, .11) !important;
    }

    .fd-product-tax-modal { width: min(620px, 100%); }

    @media (max-width: 1199.98px) {
      .fd-product-layout { grid-template-columns: minmax(0, 1fr) 310px; }
    }

    @media (max-width: 1100px) {
      .fd-product-layout { grid-template-columns: 1fr; }
      .fd-product-side {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
      }
      .fd-product-side .fd-product-card { margin-bottom: 0; }
    }

    @media (max-width: 767.98px) {
      .fd-product-side { grid-template-columns: 1fr; }
      .fd-product-side .fd-product-card { margin-bottom: 0; }
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
        <section class="fd-teams-header">
          <div><h1 class="fd-teams-title" id="pageHeading"><?= $productId > 0 ? 'Edit Product' : 'Add Product' ?></h1><p class="fd-teams-subtitle">Maintain product identity, category, unit of measure, pricing, US state tax, image and optional branch inventory in one place.</p></div>
          <div class="fd-teams-actions"><a class="fd-team-button" href="products.php"><i class="bi bi-arrow-left"></i> Products</a></div>
        </section>

        <form id="productForm" enctype="multipart/form-data">
          <input type="hidden" name="id" id="productId" value="<?= (int)$productId ?>">
          <input type="hidden" name="new_category_id" id="newCategoryId" value="0">
          <input type="hidden" name="new_uom_id" id="newUomId" value="0">
          <div class="fd-product-layout">
            <div class="fd-product-main">
              <section class="fd-card fd-product-card">
                <div class="fd-product-card-head"><span class="fd-product-card-icon"><i class="bi bi-box-seam"></i></span><div><h2>Product Identity</h2><p>Main product information and searchable master values.</p></div></div>
                <div class="fd-product-card-body">
                  <div class="fd-team-form-grid">
                    <div class="fd-team-field"><label class="fd-product-label">Product Name *</label><input class="fd-product-control" type="text" name="name" id="productName" maxlength="190" required></div>
                    <div class="fd-team-field"><label class="fd-product-label">SKU / Product Code</label><input class="fd-product-control" type="text" name="sku" id="productSku" maxlength="100"></div>
                    <div class="fd-team-field"><label class="fd-product-label">Category</label><select name="category_id" id="categoryId"></select><div class="fd-product-help">Type a new category and press Enter to create it instantly.</div></div>
                    <div class="fd-team-field"><label class="fd-product-label">Unit of Measure *</label><select name="unit_of_measure_id" id="uomId" required></select><div class="fd-product-help">Type a new unit and press Enter to save it immediately.</div></div>
                    <div class="fd-team-field full"><label class="fd-product-label">Description</label><textarea class="fd-product-control" name="description" id="productDescription" maxlength="5000" placeholder="Product description"></textarea></div>
                    <div class="fd-team-field"><label class="fd-product-label">Category Image</label><input class="fd-product-control" type="file" name="category_image" id="categoryImage" accept="image/jpeg,image/png,image/webp"><div class="fd-product-help">Optional. Updates the selected category image.</div></div>
                    <div class="fd-team-field"><label class="fd-product-label">Unit Image</label><input class="fd-product-control" type="file" name="uom_image" id="uomImage" accept="image/jpeg,image/png,image/webp"><div class="fd-product-help">Optional. Updates the selected unit image.</div></div>
                  </div>
                </div>
              </section>

              <section class="fd-card fd-product-card">
                <div class="fd-product-card-head"><span class="fd-product-card-icon"><i class="bi bi-cash-coin"></i></span><div><h2>Pricing & Tax</h2><p>Base price, markup, selling price and reusable tax master.</p></div></div>
                <div class="fd-product-card-body">
                  <div class="fd-team-form-grid">
                    <div class="fd-team-field"><label class="fd-product-label">Base Unit Price *</label><div class="fd-product-money-wrap" id="basePriceWrap"><span class="fd-product-money-symbol tenant-currency-symbol"></span><input class="fd-product-control" type="number" name="base_unit_price" id="basePrice" min="0" step="0.01" value="0.00" required></div></div>
                    <div class="fd-team-field"><label class="fd-product-label">Markup Type</label><select name="markup_type" id="markupType"><option value="percentage">Percentage</option><option value="fixed">Fixed Amount</option></select></div>
                    <div class="fd-team-field"><label class="fd-product-label">Markup Value</label><div class="fd-product-money-wrap" id="markupValueWrap"><span class="fd-product-money-symbol" id="markupValuePrefix">%</span><input class="fd-product-control" type="number" name="markup_value" id="markupValue" min="0" step="0.01" value="0.00"></div></div>
                    <div class="fd-team-field"><label class="fd-product-label">Selling Price</label><div class="fd-product-money-wrap" id="sellingPriceWrap"><span class="fd-product-money-symbol tenant-currency-symbol"></span><input class="fd-product-control" type="number" id="sellingPrice" value="0.00" readonly></div></div>
                    <div class="fd-team-field full"><label class="fd-product-label">Tax</label><select name="tax_id" id="taxId"></select><div class="fd-product-tax-note">Tax is tenant-specific. For US taxes, state and jurisdiction are stored in the Tax Master. Typing a new tax opens the tax setup modal.</div></div>
                  </div>
                </div>
              </section>

              <section class="fd-card fd-product-card">
                <div class="fd-product-card-head"><span class="fd-product-card-icon"><i class="bi bi-boxes"></i></span><div><h2>Inventory</h2><p>Choose whether stock is tracked and maintain branch quantity separately from the product master.</p></div></div>
                <div class="fd-product-card-body">
                  <div class="fd-team-form-grid">
                    <div class="fd-team-field full"><div class="fd-product-switch"><div><strong>Track Inventory</strong><small>Enable stock-on-hand tracking for this product.</small></div><input type="checkbox" name="track_inventory" id="trackInventory" value="1"></div></div>
                    <div class="fd-team-section-title inventory-field pf-hidden">Stock Settings</div>
                    <div class="fd-team-field inventory-field pf-hidden"><label class="fd-product-label">Inventory Branch *</label><select name="inventory_branch_id" id="inventoryBranchId"></select></div>
                    <div class="fd-team-field inventory-field pf-hidden"><label class="fd-product-label" id="stockLabel">Opening Stock</label><input class="fd-product-control" type="number" name="stock_quantity" id="stockQuantity" step="0.001" value="0.000"></div>
                    <div class="fd-team-field inventory-field pf-hidden"><label class="fd-product-label">Reorder Level</label><input class="fd-product-control" type="number" name="reorder_level" id="reorderLevel" min="0" step="0.001" value="0.000"></div>
                    <div class="fd-team-field inventory-field pf-hidden"><label class="fd-product-label">Minimum Stock</label><input class="fd-product-control" type="number" name="minimum_stock" id="minimumStock" min="0" step="0.001" value="0.000"></div>
                  </div>
                </div>
              </section>
            </div>

            <aside class="fd-product-side">
              <section class="fd-card fd-product-card">
                <div class="fd-product-card-head"><span class="fd-product-card-icon"><i class="bi bi-image"></i></span><div><h2>Product Image</h2><p>JPG, PNG or WEBP up to 4 MB.</p></div></div>
                <div class="fd-product-card-body"><div class="fd-product-image-box"><div class="fd-product-preview" id="productPreview">No product image</div><input class="fd-product-control" type="file" name="product_image" id="productImage" accept="image/jpeg,image/png,image/webp"></div></div>
              </section>
              <section class="fd-card fd-product-card">
                <div class="fd-product-card-head"><span class="fd-product-card-icon"><i class="bi bi-sliders"></i></span><div><h2>Product Status</h2><p>Control product availability.</p></div></div>
                <div class="fd-product-card-body"><label class="fd-product-label">Status</label><select name="status" id="status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select><div class="fd-product-price"><small>Calculated Selling Price</small><strong id="sellingDisplay"><span class="tenant-currency-symbol"></span><span id="sellingDisplayValue">0.00</span></strong></div></div>
              </section>
              
              <div class="mt-3">
                  <button class="fd-team-button primary" type="submit" form="productForm" id="saveTopBtn"><i class="bi bi-check2-circle"></i> Save Product</button>
              </div>
            </aside>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>

<div class="fd-team-modal-backdrop" id="taxModal" aria-hidden="true">
  <section class="fd-team-modal fd-product-tax-modal" role="dialog" aria-modal="true">
    <div class="fd-team-modal-header"><span class="fd-team-modal-icon"><i class="bi bi-percent"></i></span><div><h3>Create Tax</h3><p>Only new tax entries require this additional US tax setup.</p></div><button type="button" class="fd-team-modal-close" id="taxModalClose"><i class="bi bi-x-lg"></i></button></div>
    <form id="taxForm">
      <div class="fd-team-modal-body"><div class="fd-team-form-grid">
        <div class="fd-team-field"><label class="fd-product-label">Tax Name *</label><input class="fd-product-control" type="text" name="tax_name" id="taxName" maxlength="120" required></div>
        <div class="fd-team-field"><label class="fd-product-label">Tax Rate % *</label><input class="fd-product-control" type="number" name="rate_percent" id="taxRate" min="0" max="100" step="0.0001" required></div>
        <div class="fd-team-field"><label class="fd-product-label">US State</label><select name="state_code" id="taxState"></select></div>
        <div class="fd-team-field"><label class="fd-product-label">Tax Type</label><select name="tax_type" id="taxType"><option value="state">State</option><option value="county">County</option><option value="city">City</option><option value="special">Special District</option><option value="combined">Combined</option><option value="other">Other</option></select></div>
        <div class="fd-team-field full"><label class="fd-product-label">Jurisdiction / Notes</label><input class="fd-product-control" type="text" name="jurisdiction_name" id="taxJurisdiction" maxlength="190" placeholder="Optional county, city or district"></div>
      </div></div>
      <div class="fd-team-modal-footer"><button type="button" class="fd-team-button" id="cancelTax">Cancel</button><button type="submit" class="fd-team-button primary" id="saveTaxBtn"><i class="bi bi-check2"></i> Save Tax</button></div>
    </form>
  </section>
</div>
<div class="fd-team-toast info" id="toast">Notification</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function(){
'use strict';
var csrfToken=<?= json_encode($productFormCsrfToken) ?>, productId=<?= (int)$productId ?>, apiUrl='api/product-form.php', toastTimer=null, meta={categories:[],uoms:[],taxes:[],branches:[],inventories:[],currency:{symbol:'',position:'before',decimals:2}}, pendingTaxText='';
var states=[['','All US States / General'],['AL','Alabama'],['AK','Alaska'],['AZ','Arizona'],['AR','Arkansas'],['CA','California'],['CO','Colorado'],['CT','Connecticut'],['DE','Delaware'],['FL','Florida'],['GA','Georgia'],['HI','Hawaii'],['ID','Idaho'],['IL','Illinois'],['IN','Indiana'],['IA','Iowa'],['KS','Kansas'],['KY','Kentucky'],['LA','Louisiana'],['ME','Maine'],['MD','Maryland'],['MA','Massachusetts'],['MI','Michigan'],['MN','Minnesota'],['MS','Mississippi'],['MO','Missouri'],['MT','Montana'],['NE','Nebraska'],['NV','Nevada'],['NH','New Hampshire'],['NJ','New Jersey'],['NM','New Mexico'],['NY','New York'],['NC','North Carolina'],['ND','North Dakota'],['OH','Ohio'],['OK','Oklahoma'],['OR','Oregon'],['PA','Pennsylvania'],['RI','Rhode Island'],['SC','South Carolina'],['SD','South Dakota'],['TN','Tennessee'],['TX','Texas'],['UT','Utah'],['VT','Vermont'],['VA','Virginia'],['WA','Washington'],['WV','West Virginia'],['WI','Wisconsin'],['WY','Wyoming'],['DC','District of Columbia']];
function el(id){return document.getElementById(id)}
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function notify(type,msg){if(toastTimer)clearTimeout(toastTimer);var t=el('toast');t.className='fd-team-toast '+(type||'info')+' show';t.textContent=msg||'Notification';toastTimer=setTimeout(function(){t.classList.remove('show')},3200)}
function parseResponse(r){return r.text().then(function(raw){var d,text=String(raw||'').trim();try{d=text?JSON.parse(text):{}}catch(e){throw new Error(text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim()||'Invalid server response.')}if(!r.ok||!d.success)throw new Error(d.message||('Request failed. HTTP '+r.status));return d})}
function request(fd){fd.append('csrf_token',csrfToken);return fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(parseResponse)}
function titleCase(v){return String(v||'').trim().toLowerCase().replace(/\b\w/g,function(c){return c.toUpperCase()})}
function fillSelect(id,rows,placeholder,formatter){var s=$(id),old=s.val();s.empty().append(new Option(placeholder,'',true,false));(rows||[]).forEach(function(r){s.append(new Option(formatter?formatter(r):r.name,String(r.id),false,false))});if(old)s.val(old).trigger('change.select2')}
function initSelect2(){
  $('#categoryId').select2({placeholder:'Select or type category',allowClear:true,tags:true,createTag:function(p){var t=p.term.trim();return t?{id:'new:'+t,text:titleCase(t),newTag:true}:null}});
  $('#uomId').select2({placeholder:'Select or type unit of measure',allowClear:false,tags:true,createTag:function(p){var t=p.term.trim();return t?{id:'new:'+t,text:titleCase(t),newTag:true}:null}});
  $('#taxId').select2({placeholder:'No Tax',allowClear:true,tags:true,createTag:function(p){var t=p.term.trim();return t?{id:'new:'+t,text:titleCase(t),newTag:true}:null}});
  $('#markupType,#status,#inventoryBranchId').select2({minimumResultsForSearch:Infinity});
  $('#taxState').select2({dropdownParent:$('#taxModal'),placeholder:'Select US State',allowClear:true});
  $('#taxType').select2({dropdownParent:$('#taxModal'),minimumResultsForSearch:Infinity});
}
function createSimple(type,text){var fd=new FormData();fd.append('action',type);fd.append('name',text);return request(fd)}
function replaceNewOption(selectId,newId,text){var s=$(selectId);s.find('option').filter(function(){return String(this.value).indexOf('new:')===0}).remove();s.append(new Option(text,String(newId),true,true)).trigger('change')}
function bindTags(){
  $('#categoryId').on('select2:select',function(e){var d=e.params.data;if(String(d.id).indexOf('new:')===0){createSimple('create_category',d.text).then(function(r){replaceNewOption('#categoryId',r.master.id,r.master.name);el('newCategoryId').value=r.master.id;notify('success','Category saved. You can optionally upload its image below.')}).catch(function(er){notify('error',er.message);$('#categoryId').val(null).trigger('change')})}});
  $('#uomId').on('select2:select',function(e){var d=e.params.data;if(String(d.id).indexOf('new:')===0){createSimple('create_uom',d.text).then(function(r){replaceNewOption('#uomId',r.master.id,r.master.name);el('newUomId').value=r.master.id;notify('success','Unit of measure saved. You can optionally upload its image below.')}).catch(function(er){notify('error',er.message);$('#uomId').val(null).trigger('change')})}});
  $('#taxId').on('select2:select',function(e){var d=e.params.data;if(String(d.id).indexOf('new:')===0){pendingTaxText=titleCase(d.text);$('#taxId').val(null).trigger('change');el('taxName').value=pendingTaxText;openTaxModal()}});
}
function openTaxModal(){el('taxModal').classList.add('show');el('taxModal').setAttribute('aria-hidden','false');setTimeout(function(){el('taxRate').focus()},80)}
function closeTaxModal(){el('taxModal').classList.remove('show');el('taxModal').setAttribute('aria-hidden','true');pendingTaxText='';el('taxForm').reset();$('#taxState').val('').trigger('change');$('#taxType').val('state').trigger('change')}
function applyCurrency(){var c=meta.currency||{},symbol=String(c.symbol||''),position=String(c.position||'before');document.querySelectorAll('.tenant-currency-symbol').forEach(function(x){x.textContent=symbol});['basePriceWrap','sellingPriceWrap'].forEach(function(id){var w=el(id);if(w)w.classList.toggle('currency-after',position==='after')});updateMarkupPrefix();}
function updateMarkupPrefix(){var fixed=el('markupType').value==='fixed',c=meta.currency||{},prefix=el('markupValuePrefix'),wrap=el('markupValueWrap');prefix.textContent=fixed?String(c.symbol||''):'%';wrap.classList.toggle('currency-after',fixed&&String(c.position||'before')==='after');}
function calc(){var b=Number(el('basePrice').value||0),m=Number(el('markupValue').value||0),t=el('markupType').value,p=t==='fixed'?b+m:b+(b*m/100),decimals=Number((meta.currency||{}).decimals);if(!isFinite(decimals)||decimals<0||decimals>6)decimals=2;if(!isFinite(p)||p<0)p=0;el('sellingPrice').value=p.toFixed(decimals);el('sellingDisplayValue').textContent=p.toFixed(decimals);updateMarkupPrefix()}
function inventoryRows(){return document.querySelectorAll('.inventory-field')}
function toggleInventory(){inventoryRows().forEach(function(x){x.classList.toggle('pf-hidden',!el('trackInventory').checked)});if(el('trackInventory').checked)loadBranchInventory()}
function loadBranchInventory(){var b=Number($('#inventoryBranchId').val()||0),inv=null;(meta.inventories||[]).some(function(x){if(Number(x.branch_id||0)===b){inv=x;return true}return false});el('stockQuantity').value=inv?Number(inv.quantity_on_hand||0).toFixed(3):'0.000';el('reorderLevel').value=inv?Number(inv.reorder_level||0).toFixed(3):'0.000';el('minimumStock').value=inv?Number(inv.minimum_stock||0).toFixed(3):'0.000'}
function previewFile(input,box){var f=input.files&&input.files[0];if(!f)return;var url=URL.createObjectURL(f);box.innerHTML='<img src="'+url+'" alt="Product preview">'}
function loadMeta(){var fd=new FormData();fd.append('action','meta');fd.append('id',productId);return request(fd).then(function(d){meta=d.meta||meta;applyCurrency();fillSelect('#categoryId',meta.categories,'No Category',function(r){return r.name});fillSelect('#uomId',meta.uoms,'Select Unit',function(r){return r.symbol?r.name+' ('+r.symbol+')':r.name});fillSelect('#taxId',meta.taxes,'No Tax',function(r){return r.tax_name+' · '+Number(r.rate_percent||0).toFixed(4)+'%'+(r.state_code?' · '+r.state_code:'')});fillSelect('#inventoryBranchId',meta.branches,'Select Branch',function(r){return r.name});if(meta.default_branch_id){$('#inventoryBranchId').val(String(meta.default_branch_id)).trigger('change.select2')}
    if(d.product){var p=d.product;el('pageHeading').textContent='Edit Product';el('productName').value=p.name||'';el('productSku').value=p.sku||'';el('productDescription').value=p.description||'';el('basePrice').value=p.base_unit_price||0;el('markupValue').value=p.markup_value||0;$('#markupType').val(p.markup_type||'percentage').trigger('change');$('#status').val(p.status||'active').trigger('change');if(p.category_id)$('#categoryId').val(String(p.category_id)).trigger('change');if(p.unit_of_measure_id)$('#uomId').val(String(p.unit_of_measure_id)).trigger('change');if(p.tax_id)$('#taxId').val(String(p.tax_id)).trigger('change');el('trackInventory').checked=Number(p.track_inventory||0)===1;if(p.image_path)el('productPreview').innerHTML='<img src="'+esc(p.image_path)+'" alt="Product image">';el('stockLabel').textContent='Stock On Hand';calc();toggleInventory();}
  })}
function saveProduct(e){e.preventDefault();if(!el('productForm').reportValidity()){notify('warning','Complete required product fields.');return}if(el('trackInventory').checked&&!$('#inventoryBranchId').val()){notify('warning','Select an inventory branch.');return}var fd=new FormData(el('productForm'));fd.append('action','save_product');var b=el('saveTopBtn'),old=b.innerHTML;b.disabled=true;b.innerHTML='<span class="spinner-border spinner-border-sm"></span> Saving...';request(fd).then(function(d){notify('success',d.message);setTimeout(function(){window.location.href='products.php'},650)}).catch(function(er){notify('error',er.message)}).finally(function(){b.disabled=false;b.innerHTML=old})}
function saveTax(e){e.preventDefault();if(!el('taxForm').reportValidity())return;var fd=new FormData(el('taxForm'));fd.append('action','create_tax');var b=el('saveTaxBtn'),old=b.innerHTML;b.disabled=true;b.innerHTML='<span class="spinner-border spinner-border-sm"></span> Saving...';request(fd).then(function(d){meta.taxes.push(d.tax);$('#taxId').append(new Option(d.tax.tax_name+' · '+Number(d.tax.rate_percent).toFixed(4)+'%'+(d.tax.state_code?' · '+d.tax.state_code:''),String(d.tax.id),true,true)).trigger('change');notify('success','Tax saved and selected.');closeTaxModal()}).catch(function(er){notify('error',er.message)}).finally(function(){b.disabled=false;b.innerHTML=old})}
$(function(){states.forEach(function(s){$('#taxState').append(new Option(s[1],s[0],false,false))});initSelect2();bindTags();loadMeta().catch(function(e){notify('error',e.message)});el('basePrice').addEventListener('input',calc);el('markupValue').addEventListener('input',calc);$('#markupType').on('change',calc);el('trackInventory').addEventListener('change',toggleInventory);$('#inventoryBranchId').on('change',loadBranchInventory);el('productImage').addEventListener('change',function(){previewFile(this,el('productPreview'))});el('productForm').addEventListener('submit',saveProduct);el('taxForm').addEventListener('submit',saveTax);el('taxModalClose').onclick=closeTaxModal;el('cancelTax').onclick=closeTaxModal;el('taxModal').onclick=function(e){if(e.target===this)closeTaxModal()}})
})();
</script>
</body>
</html>
